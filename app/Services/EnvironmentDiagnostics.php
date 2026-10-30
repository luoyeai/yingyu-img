<?php

namespace App\Services;

use App\Services\Updates\UpdateRequirements;
use DateTimeImmutable;
use Imagick;
use ImagickPixel;
use RuntimeException;
use Throwable;

/** Standalone installer diagnostics: no Laravel boot, .env reads, database, or vendor execution. */
final class EnvironmentDiagnostics
{
    private const DIRECTORIES = [
        'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache/data',
        'storage/logs', 'bootstrap/cache', 'public/media', 'storage/app/tmp',
        'storage/app/previews', 'storage/app/originals', 'storage/app/disabled', 'storage/app/installation',
    ];

    private const EXTENSIONS = [
        'ctype', 'curl', 'xml', 'dom', 'exif', 'fileinfo', 'filter', 'hash', 'iconv', 'imagick', 'json', 'libxml',
        'mbstring', 'openssl', 'pcre', 'PDO', 'pdo_mysql', 'session', 'simplexml', 'tokenizer',
    ];

    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
    }

    /** @return array{checks:list<array{id:string,label:string,required:bool,ok:bool,detail:string,fix:string}>,ready:bool,checked_at:string} */
    public function run(bool $probe = true, bool $installation = true): array
    {
        $checks = [];
        $add = static function (string $id, string $label, bool $required, bool $ok, string $detail, string $fix = '') use (&$checks): void {
            $checks[] = compact('id', 'label', 'required', 'ok', 'detail', 'fix');
        };
        $validRoot = $this->root !== '' && is_dir($this->root) && realpath($this->root) !== false;
        $add('project-root', '项目目录', true, $validRoot, $validRoot ? '项目目录存在。' : '项目目录不存在，未进行文件探测。', '确认安装入口位于完整项目目录内。');
        $add('php-version', 'PHP 版本', true, PHP_VERSION_ID >= 80401, PHP_VERSION.' / '.PHP_SAPI, '在宝塔为站点和 CLI 选择 PHP 8.4.1 或更高补丁版本，并重启对应 PHP-FPM。');
        foreach (self::EXTENSIONS as $extension) {
            $add('extension.'.strtolower($extension), 'PHP 扩展 '.$extension, true, extension_loaded($extension), extension_loaded($extension) ? '当前 PHP 已加载。' : '当前 PHP 未加载。', '在宝塔 PHP 8.4 安装/启用 '.$extension.'，重启对应 PHP-FPM；CLI 也需启用相同扩展。');
        }
        require_once __DIR__.'/Updates/UpdateRequirements.php';
        foreach ((new UpdateRequirements)->checks($probe) as $check) {
            if (in_array($check['id'], array_column($checks, 'id'), true)) {
                continue;
            }
            $check['required'] = $installation;
            $checks[] = $check;
        }
        $functions = ['dns_get_record', 'random_bytes', 'file_put_contents', 'file_get_contents', 'fopen', 'fwrite', 'fflush', 'fclose', 'chmod', 'rename', 'unlink', 'mkdir', 'stat', 'hash_equals'];
        $missingFunctions = array_values(array_filter($functions, static fn (string $name): bool => ! function_exists($name)));
        $add('filesystem-functions', '文件与随机数函数', true, $missingFunctions === [], $missingFunctions === [] ? '文件原子写入所需函数可用。' : '不可用函数：'.implode('、', $missingFunctions), '检查 PHP disable_functions，只为本项目解除所列必要函数限制。');
        $canProbe = $probe && $validRoot && $missingFunctions === [];

        $entry = $validRoot && is_file($this->root.'/public/index.php');
        $add('public-entry', 'Laravel 公开入口', true, $entry, $entry ? 'public/index.php 存在。' : '缺少 public/index.php。', '上传完整项目，宝塔网站运行目录选择 /public。');
        $this->checkVendor($add);
        $this->checkWebContext($add, $installation);
        $this->checkLimits($add);

        $directories = [];
        foreach (self::DIRECTORIES as $relative) {
            [$ok, $detail] = $canProbe ? $this->probeDirectory($this->root.'/'.$relative) : [false, '未执行真实创建、写入、读取、改名和删除探测。'];
            $directories[$relative] = $ok;
            $add('directory.'.str_replace('/', '.', $relative), $relative, true, $ok, $detail, $this->directoryFix($relative));
        }
        [$envOk, $envDetail] = $canProbe ? $this->probeDirectory($this->root) : [false, '未执行项目根目录原子写入探测。'];
        $add('env-atomic-write', $installation ? '安装配置原子写入' : '配置目录保护', $installation, $envOk, $envOk ? '项目根目录随机探测文件写入、改名和删除成功；未读取或改动现有 .env。' : $envDetail, '安装时让站点运行用户可写项目根目录，以便同目录原子生成 .env；不要使用 chmod 777。完成安装后可按部署策略收紧权限。');

        foreach (['public/media', 'storage/app/previews', 'storage/app/originals', 'storage/app/disabled', 'storage/app/installation'] as $destination) {
            [$ok, $detail] = $canProbe && ($directories['storage/app/tmp'] ?? false) && ($directories[$destination] ?? false)
                ? $this->probeAtomicMove($this->root.'/storage/app/tmp', $this->root.'/'.$destination)
                : [false, '未执行跨目录原子移动；先通过临时目录与目标目录读写探测。'];
            $add('atomic.'.str_replace('/', '.', $destination), '原子移动 tmp → '.$destination, true, $ok, $detail, '将 storage/app/tmp 与 '.$destination.' 放在同一文件系统；检查站点运行用户的目录所有权和读写权限，避免跨盘挂载或外部软链接，不使用 chmod 777。');
        }
        [$webpOk, $webpDetail] = $canProbe && ($directories['storage/app/tmp'] ?? false)
            ? $this->probeWebp($this->root.'/storage/app/tmp')
            : [false, '未执行透明 WebP 落盘与重新解码。'];
        $add('imagick-webp-roundtrip', '透明 WebP 实际编解码', true, $webpOk, $webpDetail, '为当前 PHP 8.4 安装 Imagick，并确认 ImageMagick 的 WebP coder 可读写；检查 policy.xml 资源限制和 storage/app/tmp 权限，重启对应 PHP-FPM。');

        return [
            'checks' => $checks,
            'ready' => count(array_filter($checks, static fn (array $check): bool => $check['required'] && ! $check['ok'])) === 0,
            'checked_at' => (new DateTimeImmutable)->format(DATE_ATOM),
        ];
    }

    private function checkVendor(callable $add): void
    {
        $autoload = is_file($this->root.'/vendor/autoload.php');
        $add('composer-vendor', 'Composer 依赖', true, $autoload, $autoload ? 'vendor/autoload.php 存在；诊断未加载该文件。' : '缺少 vendor/autoload.php。', '重新上传并完整解压包含生产 vendor 的安装包；目标服务器无需 Composer。');
        $path = $this->root.'/vendor/composer/platform_check.php';
        $contents = is_file($path) && is_readable($path) && filesize($path) <= 131072 ? @file_get_contents($path) : false;
        if (! is_string($contents)) {
            $add('composer-platform', '已安装依赖的 PHP 要求', true, false, '无法读取 Composer 生成的 platform_check.php；未执行 vendor 代码。', '使用 PHP 8.4.1+，重新解压完整生产安装包；不要只上传业务源码或复用旧 vendor。');

            return;
        }
        preg_match_all('/if\s*\(\s*!\s*\(([^{};]+)\)\s*\)\s*\{/s', $contents, $matches);
        $expressions = array_values(array_filter($matches[1] ?? [], static fn (string $expression): bool => str_contains($expression, 'PHP_VERSION_ID')));
        if ($expressions === []) {
            $add('composer-platform', '已安装依赖的 PHP 要求', true, false, '未识别到 Composer 生成的 PHP_VERSION_ID 平台条件，无法安全确认兼容性。', '重新解压已验证的完整生产安装包；诊断不会 require 或 eval 平台检查文件。');

            return;
        }
        $descriptions = [];
        $ok = true;
        foreach ($expressions as $expression) {
            $evaluated = $this->evaluatePlatformExpression($expression);
            if ($evaluated === null) {
                $add('composer-platform', '已安装依赖的 PHP 要求', true, false, 'Composer PHP 条件包含诊断不支持的语法；为避免执行 vendor 代码，未作通过判断。', '在宝塔选择匹配的 PHP 8.4.1+，重新解压已验证的完整生产安装包。');

                return;
            }
            $ok = $ok && $evaluated;
            $descriptions[] = preg_replace_callback('/\b[0-9]{5,6}\b/', static fn (array $m): string => intdiv((int) $m[0], 10000).'.'.intdiv((int) $m[0] % 10000, 100).'.'.((int) $m[0] % 100), preg_replace('/\s+/', ' ', trim($expression)));
        }
        $add('composer-platform', '已安装依赖的 PHP 要求', true, $ok, '只读解析平台文件：'.implode('；', $descriptions).'；当前 '.PHP_VERSION.'。', '使用满足实际 Composer 要求的 PHP 8.4 补丁版本安装并运行站点，不要使用 --ignore-platform-reqs 绕过。');
    }

    /** A deliberately small parser for Composer-generated version comparisons; never eval/require. */
    private function evaluatePlatformExpression(string $expression): ?bool
    {
        preg_match_all('/PHP_VERSION_ID|&&|\|\||>=|<=|===|!==|==|!=|>|<|\(|\)|[0-9]+/', $expression, $matches);
        $tokens = $matches[0];
        if (implode('', $tokens) !== preg_replace('/\s+/', '', $expression)) {
            return null;
        }
        $position = 0;
        $parseOr = null;
        $parseAtom = function () use (&$tokens, &$position, &$parseOr): ?bool {
            if (($tokens[$position] ?? '') === '(') {
                $position++;
                $value = $parseOr();
                if (($tokens[$position++] ?? '') !== ')') {
                    return null;
                }

                return $value;
            }
            $left = $tokens[$position++] ?? '';
            $operator = $tokens[$position++] ?? '';
            $right = $tokens[$position++] ?? '';
            if ($left !== 'PHP_VERSION_ID' || ! preg_match('/\A[0-9]+\z/', $right)) {
                return null;
            }
            $version = (int) $right;

            return match ($operator) {
                '>=' => $version <= PHP_VERSION_ID, '>' => $version < PHP_VERSION_ID,
                '<=' => $version >= PHP_VERSION_ID, '<' => $version > PHP_VERSION_ID,
                '==', '===' => $version === PHP_VERSION_ID, '!=', '!==' => $version !== PHP_VERSION_ID,
                default => null,
            };
        };
        $parseAnd = function () use (&$tokens, &$position, &$parseAtom): ?bool {
            $value = $parseAtom();
            while (($tokens[$position] ?? '') === '&&') {
                $position++;
                $right = $parseAtom();
                if ($value === null || $right === null) {
                    return null;
                }
                $value = $value && $right;
            }

            return $value;
        };
        $parseOr = function () use (&$tokens, &$position, &$parseAnd): ?bool {
            $value = $parseAnd();
            while (($tokens[$position] ?? '') === '||') {
                $position++;
                $right = $parseAnd();
                if ($value === null || $right === null) {
                    return null;
                }
                $value = $value || $right;
            }

            return $value;
        };
        $result = $parseOr();

        return $position === count($tokens) ? $result : null;
    }

    private function checkWebContext(callable $add, bool $installation): void
    {
        $cli = in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $expected = realpath($this->root.'/public');
        $add('document-root', '网站运行目录', $installation && ! $cli && $documentRoot !== false, $cli || ($expected && $documentRoot === $expected), $cli ? '当前为 CLI，不能判断宝塔/Nginx 的 DOCUMENT_ROOT；必须在实际站点请求复核。' : ($expected && $documentRoot === $expected ? '当前 Web DOCUMENT_ROOT 指向项目 public。' : '当前 Web DOCUMENT_ROOT 未确认指向项目 public。'), '在宝塔网站设置中将运行目录改为 /public，根目录保持完整项目目录；Nginx 仅允许 index.php 执行。');
        $https = in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true) || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $local = (bool) preg_match('/\A(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]+)?\z/', $host);
        $add('https', 'HTTPS', false, $cli || $https || $local, $cli ? 'CLI 无法判断站点 HTTPS。' : ($https ? '当前请求使用 HTTPS。' : ($local ? '本地 HTTP 可用于测试；生产请启用 HTTPS。' : '当前 Web 请求未确认使用 HTTPS。')), '在宝塔配置有效证书和 HTTPS；生产启用安全 Session Cookie，反代只信任已配置代理。');
    }

    private function checkLimits(callable $add): void
    {
        $uploads = filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOL);
        $add('file-uploads', 'PHP 文件上传', true, $uploads, '当前 '.PHP_SAPI.' file_uploads='.($uploads ? 'On' : 'Off').'。', '在本站使用的 PHP 8.4 配置启用 file_uploads，重启对应 PHP-FPM。');
        foreach ([['upload-max', 'upload_max_filesize', 20 * 1048576, '21M'], ['post-max', 'post_max_size', 24 * 1048576, '24M'], ['memory-limit', 'memory_limit', 512 * 1048576, '512M']] as [$id, $name, $recommended, $example]) {
            $raw = (string) ini_get($name);
            $bytes = $this->iniBytes($raw);
            // PHP upload_max_filesize=0 is a zero-byte file limit; only post/memory 0/-1 may be unlimited.
            $unlimited = $name === 'memory_limit' ? $bytes === -1 : ($name === 'post_max_size' && $bytes === 0);
            $ok = $unlimited || ($bytes !== null && $bytes >= $recommended);
            $add($id, 'PHP '.$name, false, $ok, '当前 '.PHP_SAPI.'：'.$raw.'；按默认单图 20MB、逐张上传给出建议。', '在本站 PHP 8.4 中建议设置 '.$name.'='.$example.'；同时核对 Nginx client_max_body_size 24m。修改后重启对应 PHP-FPM，再从 Web 重新检测。');
        }
        $time = (int) ini_get('max_execution_time');
        $add('execution-time', 'PHP 执行时间', false, $time === 0 || $time >= 120, '当前 '.PHP_SAPI.' max_execution_time='.$time.' 秒（0 为不限制）。', '按服务器资源建议 max_execution_time=120，并匹配 Nginx/FPM 超时；保留像素限制，避免过大图片耗尽内存。');
    }

    private function iniBytes(string $value): ?int
    {
        if (! preg_match('/\A\s*(-?[0-9]+)\s*([KMG]?)\s*\z/i', $value, $matches)) {
            return null;
        }

        return (int) $matches[1] * match (strtoupper($matches[2] ?? '')) {
            'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1
        };
    }

    private function directoryFix(string $relative): string
    {
        return '在宝塔检查 '.$relative.' 的目录所有权和权限，使本站 PHP 运行用户可创建、读取、改名和删除文件；可按部署文档设置所有者，勿 chmod 777。';
    }

    private function insideRoot(string $directory): bool
    {
        $candidate = $directory;
        while (! file_exists($candidate) && ! is_link($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return false;
            }
            $candidate = $parent;
        }
        $resolved = realpath($candidate);

        return $resolved !== false && ($resolved === $this->root || str_starts_with($resolved, $this->root.DIRECTORY_SEPARATOR));
    }

    /** @return array{bool,string} */
    private function probeDirectory(string $directory): array
    {
        $paths = [];
        $handle = null;
        $ok = false;
        $detail = '目录读写探测失败。';
        try {
            if (! $this->insideRoot($directory)) {
                throw new RuntimeException('目录或软链接位于项目边界之外。');
            }
            if (! is_dir($directory) && ! @mkdir($directory, str_contains($directory, '/public/') ? 0755 : 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('无法创建目录。');
            }
            $path = $directory.'/.vault-diagnostic-'.bin2hex(random_bytes(16)).'.probe';
            $moved = $path.'.moved';
            $paths = [$path, $moved];
            $handle = @fopen($path, 'x+b');
            if ($handle === false) {
                throw new RuntimeException('无法创建探测文件。');
            }
            @chmod($path, 0600);
            $payload = random_bytes(48);
            if (fwrite($handle, $payload) !== strlen($payload) || ! fflush($handle)) {
                throw new RuntimeException('探测文件写入失败。');
            }
            fclose($handle);
            $handle = null;
            $read = @file_get_contents($path);
            if (! is_string($read) || ! hash_equals($payload, $read)) {
                throw new RuntimeException('探测文件读取校验失败。');
            }
            if (file_exists($moved) || ! @rename($path, $moved)) {
                throw new RuntimeException('同目录原子改名失败。');
            }
            $read = @file_get_contents($moved);
            if (! is_string($read) || ! hash_equals($payload, $read)) {
                throw new RuntimeException('改名后读取校验失败。');
            }
            if (! @unlink($moved)) {
                throw new RuntimeException('探测文件删除失败。');
            }
            $ok = true;
            $detail = '实际创建、随机字节写入、读取校验、改名和删除成功。';
        } catch (Throwable $exception) {
            $detail = $exception instanceof RuntimeException ? $exception->getMessage() : '目录探测失败，请检查 PHP 文件函数和运行用户权限。';
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            if (count(array_filter($paths, static fn (string $path): bool => file_exists($path))) > 0) {
                $ok = false;
                $detail = '探测文件未能完全清理，请修复目录删除权限并移除 .vault-diagnostic-*.probe*。';
            }
        }

        return [$ok, $detail];
    }

    /** @return array{bool,string} */
    private function probeAtomicMove(string $source, string $destination): array
    {
        $paths = [];
        $ok = false;
        $detail = '跨目录原子移动失败。';
        try {
            if (! $this->insideRoot($source) || ! $this->insideRoot($destination)) {
                throw new RuntimeException('目录位于项目边界之外。');
            }
            $sourceStat = @stat($source);
            $destinationStat = @stat($destination);
            if (! $sourceStat || ! $destinationStat || $sourceStat['dev'] !== $destinationStat['dev']) {
                throw new RuntimeException('目录不在同一文件系统，不能保证原子 rename。');
            }
            $name = '/.vault-diagnostic-'.bin2hex(random_bytes(16)).'.probe';
            $paths = [$source.$name, $destination.$name];
            $payload = random_bytes(48);
            $handle = @fopen($paths[0], 'xb');
            if ($handle === false) {
                throw new RuntimeException('无法创建跨目录移动探测文件。');
            }
            try {
                if (fwrite($handle, $payload) !== strlen($payload) || ! fflush($handle)) {
                    throw new RuntimeException('移动前写入失败。');
                }
            } finally {
                fclose($handle);
            }
            @chmod($paths[0], 0600);
            if (file_exists($paths[1]) || ! @rename($paths[0], $paths[1])) {
                throw new RuntimeException('同文件系统跨目录 rename 失败。');
            }
            $read = @file_get_contents($paths[1]);
            if (file_exists($paths[0]) || ! is_string($read) || ! hash_equals($payload, $read)) {
                throw new RuntimeException('跨目录移动后内容验证失败。');
            }
            if (! @unlink($paths[1])) {
                throw new RuntimeException('移动探测文件删除失败。');
            }
            $ok = true;
            $detail = 'stat 设备号一致，真实 rename 后读取一致，探测文件已删除。';
        } catch (Throwable $exception) {
            $detail = $exception instanceof RuntimeException ? $exception->getMessage() : '原子移动探测异常，请检查目录权限和文件系统。';
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            if (count(array_filter($paths, static fn (string $path): bool => file_exists($path))) > 0) {
                $ok = false;
                $detail = '移动探测文件未完全清理，请修复删除权限。';
            }
        }

        return [$ok, $detail];
    }

    /** @return array{bool,string} */
    private function probeWebp(string $directory): array
    {
        if (! extension_loaded('imagick') || ! class_exists(Imagick::class, false)) {
            return [false, '当前 PHP 未加载 Imagick，无法进行 WebP 实测。'];
        }
        $path = null;
        $image = null;
        $decoded = null;
        $ok = false;
        $detail = '透明 WebP 编解码失败。';
        try {
            if (! in_array('WEBP', Imagick::queryFormats('WEBP'), true)) {
                throw new RuntimeException('Imagick 未声明 WebP coder 支持。');
            }
            $path = $directory.'/.vault-diagnostic-'.bin2hex(random_bytes(16)).'.probe';
            $handle = @fopen($path, 'xb');
            if ($handle === false) {
                throw new RuntimeException('无法创建 WebP 探测临时文件。');
            }
            fclose($handle);
            @chmod($path, 0600);
            $image = new Imagick;
            $image->newImage(8, 6, new ImagickPixel('transparent'));
            $image->setImageFormat('WEBP');
            $image->setOption('webp:lossless', 'true');
            if (! $image->writeImage($path) || filesize($path) < 12) {
                throw new RuntimeException('WebP 写入失败。');
            }
            $decoded = new Imagick;
            $decoded->readImage($path);
            if ($decoded->getNumberImages() !== 1 || strtoupper($decoded->getImageFormat()) !== 'WEBP' || $decoded->getImageWidth() !== 8 || $decoded->getImageHeight() !== 6 || ! $decoded->getImageAlphaChannel() || $decoded->getImagePixelColor(0, 0)->getColorValue(Imagick::COLOR_ALPHA) > 0.001) {
                throw new RuntimeException('WebP 解码后的格式、尺寸或透明通道不正确。');
            }
            $ok = true;
            $detail = 'Imagick '.phpversion('imagick').' 实际生成透明 WebP，落盘后重新解码为 8×6，透明通道正确。';
        } catch (Throwable $exception) {
            $detail = $exception instanceof RuntimeException ? $exception->getMessage() : 'Imagick WebP 实测失败，可能是 coder、policy 或目录权限问题。';
        } finally {
            $image?->clear();
            $decoded?->clear();
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
            if ($path !== null && file_exists($path)) {
                $ok = false;
                $detail = 'WebP 探测文件未完全清理，请检查临时目录删除权限。';
            }
        }

        return [$ok, $detail];
    }
}
