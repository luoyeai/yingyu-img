<?php

namespace App\Services\Updates;

use Throwable;

/** Shared, vendor-independent installation and update capability checks. */
final class UpdateRequirements
{
    private const FUNCTIONS = [
        'proc_open', 'proc_get_status', 'proc_terminate', 'proc_close',
        'stream_set_blocking', 'stream_get_contents', 'stream_copy_to_stream',
        'getenv', 'microtime', 'usleep', 'flock', 'fsync', 'tmpfile',
        'fstat', 'fgets', 'feof', 'rewind', 'hash_file', 'disk_free_space', 'sys_get_temp_dir', 'is_executable',
    ];

    public function __construct(private ?string $phpCli = null) {}

    public static function phpBinary(?string $configured = null): string
    {
        return $configured ?? (function_exists('getenv') ? (getenv('IMAGE_VAULT_UPDATE_PHP_CLI') ?: null) : null)
            ?? (PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR.'/php');
    }

    /** @return list<array{id:string,label:string,required:bool,ok:bool,detail:string,fix:string}> */
    public function checks(bool $probe = true): array
    {
        $checks = [];
        $add = static function (string $id, string $label, bool $ok, string $detail, string $fix) use (&$checks): void {
            $checks[] = ['id' => $id, 'label' => $label, 'required' => true, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
        };
        foreach (['zip', 'openssl', 'pdo_mysql', 'imagick', 'curl', 'simplexml'] as $extension) {
            $add('extension.'.$extension, 'PHP 扩展 '.$extension, extension_loaded($extension), extension_loaded($extension) ? '当前 PHP 已加载，供签名更新使用。' : '当前 PHP 缺少更新必需扩展。', '宝塔 → 软件商店 → 当前站点 PHP 8.4 → 安装扩展，启用 '.$extension.' 并重载本站 PHP-FPM；命令行 PHP 也须启用。');
        }
        $add('storage.function.dns_get_record', '云存储 DNS 查询', function_exists('dns_get_record'), '云存储传输需要校验并固定公网 DNS 地址。', '为本站 PHP 启用 dns_get_record，Web 与 CLI 均须可用。');
        $functionsReady = true;
        foreach (self::FUNCTIONS as $function) {
            $available = function_exists($function);
            $functionsReady = $functionsReady && $available;
            $add('update.function.'.$function, '更新函数 '.$function, $available, $available ? '当前 PHP 可用。' : '函数不存在或被 disable_functions 禁用。', '宝塔 → 当前站点 PHP 8.4 → 禁用函数，只解除 '.$function.' 的禁用并重载本站 PHP-FPM；勿批量关闭全部限制。');
        }
        $opcache = filter_var(ini_get(in_array(PHP_SAPI, ['cli', 'cli-server', 'phpdbg'], true) ? 'opcache.enable_cli' : 'opcache.enable'), FILTER_VALIDATE_BOOL);
        foreach (['opcache_invalidate', 'opcache_reset'] as $function) {
            $available = ! $opcache || function_exists($function);
            $detail = ! $opcache ? '当前执行环境未启用 OPcache，无需此函数。' : ($available ? 'OPcache 已启用，函数可用。' : 'OPcache 已启用，但必要函数不可用。');
            if ($opcache && $probe && $available && $function === 'opcache_invalidate') {
                $available = @opcache_invalidate(__FILE__, true);
                $detail = $available ? 'OPcache 失效 API 实际探测通过。' : 'OPcache 失效 API 不允许本站调用。';
            }
            $add('update.function.'.$function, '更新缓存函数 '.$function, $available, $detail, '宝塔 → 本站 PHP 配置：启用 '.$function.'，并核对 opcache.restrict_api 允许本站目录；重载本站 PHP-FPM。');
        }
        $binary = $functionsReady ? self::phpBinary($this->phpCli) : '';
        $executable = $binary !== '' && is_file($binary) && is_executable($binary);
        $add('update.php-cli', '同版本 PHP CLI 程序', $executable, $executable ? '已找到可执行的本站 PHP CLI；下方独立探测其实际能力。' : '未找到可执行的本站 PHP CLI，或启动所需函数被禁用。', '在宝塔安装与本站相同的 PHP 8.4，确认其 bin/php 可由本站 PHP 用户执行；站点 FPM 与命令行版本须一致。');
        $report = $probe && $functionsReady && $executable ? $this->probeCli($binary) : null;
        $detail = ! $probe ? '仅查看配置，未启动 CLI，不能确认安装就绪。' : 'PHP CLI 无法安全启动、输出无效或超过 5 秒；未加载 Laravel、配置或数据库。';
        $add('update.cli-version', 'Web / CLI PHP 版本一致', is_array($report) && ($report['sapi'] ?? null) === 'cli' && ($report['version'] ?? null) === PHP_VERSION, is_array($report) ? '当前 '.PHP_SAPI.'：'.PHP_VERSION.'；CLI：'.($report['version'] ?? '未知').' / '.($report['sapi'] ?? '未知').'。' : $detail, '在宝塔切换本站与 CLI 为同一个 PHP 安装及补丁版本，检查 bin/php 的执行权限和进程禁用函数；重载本站 PHP-FPM 后重检。');
        foreach (['pdo_mysql', 'imagick', 'curl', 'simplexml'] as $extension) {
            $ok = is_array($report) && ($report['extensions'][$extension] ?? false) === true;
            $add('update.cli-extension.'.$extension, 'PHP CLI 扩展 '.$extension, $ok, is_array($report) ? ($ok ? '独立 CLI 子进程实际已加载。' : 'Web 与 CLI 配置不同，CLI 未加载 '.$extension.'。') : $detail, '在宝塔为命令行实际使用的同版本 PHP 安装 '.$extension.'，确认 CLI 的 php.ini/conf.d 也加载；只为 FPM 启用不够。');
        }

        $add('update.cli-function.dns_get_record', 'PHP CLI DNS 查询', is_array($report) && ($report['dns'] ?? false) === true, is_array($report) ? '云存储传输需要 CLI DNS 查询能力。' : $detail, '为本站 PHP CLI 启用 dns_get_record。');

        return $checks;
    }

    /** @return array<string,mixed>|null */
    private function probeCli(string $binary): ?array
    {
        $code = 'echo json_encode(["version"=>PHP_VERSION,"sapi"=>PHP_SAPI,"dns"=>function_exists("dns_get_record"),"extensions"=>["pdo_mysql"=>extension_loaded("pdo_mysql"),"imagick"=>extension_loaded("imagick"),"curl"=>extension_loaded("curl"),"simplexml"=>extension_loaded("simplexml")]]);';
        $pipes = [];
        $process = null;
        try {
            $environment = getenv();
            foreach (array_keys($environment) as $name) {
                if (preg_match('/\A(?:APP_|DB_|CACHE_|SESSION_|QUEUE_|MAIL_|IMAGE_)/', $name)) {
                    unset($environment[$name]);
                }
            }
            $process = @proc_open([$binary, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir(), $environment);
            if (! is_resource($process)) {
                return null;
            }
            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $started = microtime(true);
            $output = '';
            do {
                $output .= stream_get_contents($pipes[1], 4096);
                stream_get_contents($pipes[2], 4096);
                $status = proc_get_status($process);
                if (strlen($output) > 4096 || microtime(true) - $started >= 5) {
                    proc_terminate($process, 9);

                    return null;
                }
                if (! $status['running']) {
                    $output .= stream_get_contents($pipes[1], 4096);
                    if ($status['exitcode'] !== 0 || strlen($output) > 4096) {
                        return null;
                    }
                    $report = json_decode($output, true, 8, JSON_THROW_ON_ERROR);

                    return is_array($report) && is_string($report['version'] ?? null)
                        && preg_match('/\A\d+\.\d+\.\d+[a-zA-Z0-9.-]*\z/D', $report['version'])
                        && is_string($report['sapi'] ?? null) && is_array($report['extensions'] ?? null)
                        && is_bool($report['extensions']['pdo_mysql'] ?? null) && is_bool($report['extensions']['imagick'] ?? null)
                        ? $report : null;
                }
                usleep(20000);
            } while (true);
        } catch (Throwable) {
            return null;
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
        }
    }
}
