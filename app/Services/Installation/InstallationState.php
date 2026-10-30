<?php

namespace App\Services\Installation;

use RuntimeException;
use Throwable;

class InstallationState
{
    public function __construct(private string $root) {}

    /** @return array{status:string,can_install:bool,reason:string,message:string} */
    public function inspect(): array
    {
        try {
            if (is_file($this->path('installed.json'))) {
                return $this->result('installed', 'installed_lock', '安装已完成，安装入口已关闭。');
            }
            if (file_exists($this->path('journal.json'))) {
                return $this->result('partial', 'interrupted_installation', '检测到已有安装日志。为保护数据，网页不会自动重试或删除数据；请使用服务器备份与日志人工恢复。');
            }
            if (file_exists($this->root.'/bootstrap/cache/config.php')) {
                return $this->result('existing', 'configuration_cache', '检测到配置缓存，不能确认全新安装；请由服务器管理员核查。');
            }
            $values = (new EnvFile($this->root))->read();
            if (trim($values['APP_KEY'] ?? '') !== '') {
                return $this->result('existing', 'application_key_exists', '检测到已有应用密钥，安装入口已关闭。');
            }
            if (($values['DB_URL'] ?? '') !== '' || ($values['DB_SOCKET'] ?? '') !== '') {
                return $this->result('blocked', 'unsupported_database_configuration', '现有数据库配置无法安全确认，请使用空数据库和 TCP 连接。');
            }
            $hasDatabase = ($values['DB_DATABASE'] ?? '') !== '';
            $hasUsername = ($values['DB_USERNAME'] ?? '') !== '';
            if ($hasDatabase !== $hasUsername) {
                return $this->result('blocked', 'incomplete_database_configuration', '现有数据库配置不完整，无法确认是否全新安装。');
            }
            $configured = $hasDatabase && $hasUsername;
            if ($configured) {
                if (($values['DB_CONNECTION'] ?? 'mysql') !== 'mysql') {
                    return $this->result('blocked', 'unsupported_database', '安装器仅支持 MySQL 8.0。');
                }
                $database = Installer::normalizeDatabase([
                    'db_host' => $values['DB_HOST'] ?? '127.0.0.1',
                    'db_port' => $values['DB_PORT'] ?? '3306',
                    'db_database' => $values['DB_DATABASE'],
                    'db_username' => $values['DB_USERNAME'],
                    'db_password' => $values['DB_PASSWORD'] ?? '',
                ]);
                $pdo = Installer::connect($database);
                if (! Installer::isSupportedVersion($pdo)) {
                    return $this->result('blocked', 'unsupported_database_version', '现有数据库不是受支持的 MySQL 8.0。');
                }
                if (Installer::databaseObjects($pdo, $database['db_database']) !== []) {
                    return $this->result('existing', 'database_not_empty', '现有数据库包含对象，安装入口已关闭。请保留数据并使用新的空数据库。');
                }
            }

            return $this->result('fresh', 'empty_installation', '可初始化全新安装。');
        } catch (Throwable) {
            return $this->result('blocked', 'unconfirmed_existing_configuration', '无法安全确认现有配置和数据库状态，安装入口保持关闭。请由服务器管理员检查连接和权限。');
        }
    }

    public function path(string $name): string
    {
        if (! in_array($name, ['owner-token', 'installed.json', 'journal.json', 'install.lock', 'environment.backup', 'pending.env'], true)) {
            throw new RuntimeException('安装状态文件名无效。');
        }

        $directory = $this->root;
        foreach (['storage', 'app', 'installation'] as $part) {
            $directory .= '/'.$part;
            clearstatcache(true, $directory);
            if (is_link($directory) || (file_exists($directory) && ! is_dir($directory))) {
                throw new RuntimeException('安装私有目录路径无效，禁止符号链接或非目录路径。');
            }
        }

        return $directory.'/'.$name;
    }

    public function ensureDirectory(): void
    {
        $directory = dirname($this->path('install.lock'));
        if (is_link($directory) || (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory))) {
            throw new RuntimeException('无法创建 storage/app/installation。请在宝塔文件中将此目录及其父目录的所有者设置为本站 PHP 用户，并给予创建、写入权限；不要使用 chmod 777。');
        }
        if (! chmod($directory, 0700)) {
            throw new RuntimeException('无法设置 storage/app/installation 的私有权限。请在宝塔文件中修复此目录的本站 PHP 用户所有权与读写权限。');
        }
    }

    public function verifyToken(string $token): bool
    {
        $path = $this->path('owner-token');
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $token) || is_link($path) || ! is_file($path)) {
            return false;
        }
        $stored = file_get_contents($path);

        return is_string($stored) && hash_equals(trim($stored), $token);
    }

    public function ensureOwnerToken(): string
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('所有者令牌只能在服务器终端生成。');
        }

        return $this->ensureInternalToken();
    }

    /** Generated internally for a fresh installation; never displayed or submitted by the visitor. */
    public function ensureInternalToken(): string
    {
        return $this->underLock(function (): string {
            if (! $this->inspect()['can_install']) {
                throw new RuntimeException('当前状态不允许生成安装令牌。');
            }
            $path = $this->path('owner-token');
            if (is_file($path) && ! is_link($path)) {
                $token = trim(file_get_contents($path));
                if (preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
                    return $token;
                }
                throw new RuntimeException('已有所有者令牌格式无效，请由管理员核查。');
            }
            $token = bin2hex(random_bytes(32));
            (new EnvFile($this->root))->atomicFile($path, $token."\n");

            return $token;
        });
    }

    /** @return array<string,mixed>|null */
    public function readJournal(): ?array
    {
        $path = $this->path('journal.json');
        if (! is_file($path) || is_link($path)) {
            return null;
        }
        try {
            $journal = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);

            return is_array($journal) ? $journal : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function underLock(callable $callback): mixed
    {
        $this->ensureDirectory();
        $path = $this->path('install.lock');
        if (is_link($path)) {
            throw new RuntimeException('安装锁路径无效。');
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('无法打开安装锁。');
        }
        try {
            chmod($path, 0600);
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('另一个安装请求正在执行，请稍后重试。');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function result(string $status, string $reason, string $message): array
    {
        return ['status' => $status, 'can_install' => $status === 'fresh', 'reason' => $reason, 'message' => $message];
    }
}
