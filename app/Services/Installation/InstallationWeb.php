<?php

namespace App\Services\Installation;

use App\Services\EnvironmentDiagnostics;
use RuntimeException;
use Throwable;

class InstallationWeb
{
    public function __construct(private string $root) {}

    public function handle(array $inspection): void
    {
        $this->headers();
        $view = ['step' => 'environment', 'csrf' => '', 'message' => null, 'errors' => [], 'checks' => [], 'ready' => false, 'values' => [], 'state' => $inspection['status'], 'can_retry' => false];
        if (! $inspection['can_install']) {
            http_response_code(409);
            $view['step'] = $inspection['status'] === 'partial' ? 'failed' : 'blocked';
            $view['message'] = $inspection['message'];
            $this->render($view);

            return;
        }
        $sessionReady = false;
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if (! in_array($method, ['GET', 'POST'], true)) {
                http_response_code(405);
                throw new RuntimeException('仅允许 GET 或 POST 请求。');
            }
            $this->startSession();
            $state = new InstallationState($this->root);
            $_SESSION['step'] ??= 'environment';
            $_SESSION['owner_token'] = $state->ensureInternalToken();
            $sessionReady = true;
            if ($method === 'POST') {
                $csrf = $_POST['csrf'] ?? null;
                if (! is_string($csrf) || ! hash_equals($_SESSION['csrf'], $csrf)) {
                    http_response_code(419);
                    throw new RuntimeException('页面校验已失效，请刷新后重新提交。');
                }
                if (! $this->rateLimit('requests', 30, 60)) {
                    http_response_code(429);
                    throw new RuntimeException('操作过于频繁，请一分钟后重试。');
                }
                $action = $_POST['action'] ?? '';
                if (! is_string($action)) {
                    throw new RuntimeException('无效安装步骤。');
                }
                $this->processAction($action, $state);
            }
            $view['step'] = $_SESSION['step'] ?? 'environment';
            $view['values'] = $_SESSION['values'] ?? [];
            if ($view['step'] === 'environment') {
                $this->environmentView($view);
            }
            if (! empty($_SESSION['completed'])) {
                $view['step'] = 'complete';
                $view['message'] = '安装已完成，安装入口已自动关闭。请使用刚创建的管理员账号登录。';
                $_SESSION = [];
                session_destroy();
            }
        } catch (Throwable $exception) {
            $view['errors'] = [$exception instanceof RuntimeException || $exception instanceof \InvalidArgumentException ? $exception->getMessage() : '安装操作未完成，请按环境检查提示修复后重新打开此页。'];
            $view['step'] = $_SESSION['step'] ?? 'environment';
            $view['values'] = $_SESSION['values'] ?? [];
            if ($view['step'] === 'environment') {
                $this->environmentView($view);
                if (! $sessionReady) {
                    $view['checks'][] = ['id' => 'installation-session', 'label' => '安装私有会话与内部状态', 'required' => true, 'ok' => false, 'detail' => $view['errors'][0], 'fix' => '在宝塔文件中检查 storage/app/installation 及 sessions 子目录，所有者设为本站 PHP 运行用户并授予创建、写入、改名和删除权限，然后重新检查。无需执行命令或读取密钥，不要 chmod 777。'];
                    $view['ready'] = false;
                }
            }
            $latest = (new InstallationState($this->root))->inspect();
            if (! $latest['can_install']) {
                $view['step'] = $latest['status'] === 'partial' ? 'failed' : 'blocked';
                $view['message'] = $latest['message'];
                unset($_SESSION['data'], $_SESSION['owner_token']);
                http_response_code(409);
            } elseif (http_response_code() < 400) {
                http_response_code($sessionReady ? 422 : 503);
            }
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $view['csrf'] = $sessionReady ? ($_SESSION['csrf'] ?? '') : '';
            session_write_close();
        }
        $this->render($view);
    }

    private function environmentView(array &$view): void
    {
        $diagnostics = (new EnvironmentDiagnostics($this->root))->run();
        $view['checks'] = $diagnostics['checks'];
        $view['ready'] = $diagnostics['ready'];
    }

    private function requireStep(string $expected): void
    {
        if (($_SESSION['step'] ?? 'environment') !== $expected) {
            throw new RuntimeException('请按页面顺序完成环境、数据库和管理员设置，不能跳过安装步骤。');
        }
    }

    private function processAction(string $action, InstallationState $state): void
    {
        $installer = new Installer($this->root);
        switch ($action) {
            case 'environment':
                $_SESSION['step'] = 'environment';
                unset($_SESSION['data']);

                return;
            case 'database':
                if (! isset($_POST['db_host'])) {
                    $this->requireStep('environment');
                    if (! (new EnvironmentDiagnostics($this->root))->run()['ready']) {
                        throw new RuntimeException('必需环境检测未通过，请按提示修复后重新检测。');
                    }
                    $_SESSION['step'] = 'database';

                    return;
                }
                $this->requireStep('database');
                unset($_SESSION['data']);
                $database = $this->strings(['db_host', 'db_port', 'db_database', 'db_username', 'db_password']);
                $_SESSION['values'] = array_replace($_SESSION['values'] ?? [], array_diff_key($database, ['db_password' => true]));
                $installer->checkDatabase($database, $_SESSION['owner_token']);
                $_SESSION['data'] = $database;
                $_SESSION['step'] = 'site';

                return;
            case 'site':
                $this->requireStep('site');
                if (empty($_SESSION['data']['db_database'])) {
                    throw new RuntimeException('请先测试独立空数据库。');
                }
                $_SESSION['step'] = 'site';
                $site = $this->strings(['site_name', 'site_url', 'email', 'admin_password', 'admin_password_confirmation']);
                $data = array_replace($_SESSION['data'], ['app_name' => $site['site_name'], 'app_url' => $site['site_url'], 'admin_email' => $site['email'], 'admin_password' => $site['admin_password'], 'admin_password_confirmation' => $site['admin_password_confirmation']]);
                $data = $installer->validate($data);
                $_SESSION['data'] = $data;
                $_SESSION['values'] = array_replace($_SESSION['values'], ['site_name' => $site['site_name'], 'site_url' => $site['site_url'], 'email' => $site['email']]);
                $_SESSION['step'] = 'confirm';

                return;
            case 'install':
                if (($_SESSION['step'] ?? '') !== 'confirm' || ($_POST['confirm_empty_database'] ?? '') !== '1') {
                    throw new RuntimeException('请完成配置并明确确认独立空数据库范围。');
                }
                if (! (new EnvironmentDiagnostics($this->root))->run()['ready']) {
                    $_SESSION['step'] = 'environment';
                    throw new RuntimeException('当前环境检测不通过，尚未执行安装。');
                }
                $installer->installPrepared($_SESSION['data'], $_SESSION['owner_token']);
                $_SESSION = ['completed' => true, 'values' => $_SESSION['values'] ?? [], 'csrf' => bin2hex(random_bytes(32))];

                return;
            default:
                throw new RuntimeException('无效安装步骤。');
        }
    }

    private function strings(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (! isset($_POST[$key]) || ! is_string($_POST[$key])) {
                throw new RuntimeException('安装表单不完整，请重新填写。');
            }
            $result[$key] = $_POST[$key];
        }

        return $result;
    }

    private function startSession(): void
    {
        (new InstallationState($this->root))->ensureDirectory();
        $directory = $this->root.'/storage/app/installation/sessions';
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('无法创建 storage/app/installation/sessions。请在宝塔文件中修复该目录及父目录的本站 PHP 用户所有权和创建、写入权限，禁止 chmod 777。');
        }
        $resolved = realpath($directory);
        $root = realpath($this->root);
        if (! $resolved || ! $root || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || is_link($directory)) {
            throw new RuntimeException('安装会话目录必须位于项目私有存储内，禁止外部软链接。');
        }
        session_name('VAULT_INSTALL');
        session_save_path($directory);
        session_set_cookie_params(['lifetime' => 0, 'path' => '/install', 'secure' => ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        if (! @session_start()) {
            throw new RuntimeException('无法写入 storage/app/installation/sessions。请在宝塔文件中修复该目录的本站 PHP 用户所有权和读写权限，再重新检查。');
        }
        if (isset($_SESSION['last_activity']) && $_SESSION['last_activity'] < time() - 900) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    private function rateLimit(string $name, int $limit, int $seconds): bool
    {
        $directory = $this->root.'/storage/app/installation';
        $path = $directory.'/rate-'.hash('sha256', $name.'|'.($_SERVER['REMOTE_ADDR'] ?? 'unknown')).'.json';
        $handle = @fopen($path, 'c+');
        if (! $handle || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('无法写入安装限流状态，请检查私有目录权限。');
        }
        chmod($path, 0600);
        try {
            $data = json_decode(stream_get_contents($handle), true) ?: ['start' => time(), 'count' => 0];
            if ($data['start'] < time() - $seconds) {
                $data = ['start' => time(), 'count' => 0];
            }
            if ($data['count'] >= $limit) {
                return false;
            }
            $data['count']++;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($data, JSON_THROW_ON_ERROR));
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function headers(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    }

    private function render(array $view): void
    {
        require $this->root.'/resources/views/installer.php';
    }
}
