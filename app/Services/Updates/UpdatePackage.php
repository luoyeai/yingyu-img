<?php

namespace App\Services\Updates;

use RuntimeException;
use ZipArchive;

class UpdatePackage
{
    private const MAX_ARCHIVE_BYTES = 134217728;

    private const MAX_EXPANDED_BYTES = 536870912;

    private const MAX_ENTRIES = 20000;

    public const REQUIRED_FILES = [
        'artisan', 'composer.json', 'composer.lock', 'release.json', 'bootstrap/app.php',
        'bootstrap/providers.php', 'bootstrap/preflight.php', 'bootstrap/installation.php',
        'bootstrap/update-gateway.php', 'bootstrap/update-public.pem', 'public/index.php',
        'vendor/autoload.php', 'vendor/composer/installed.json',
    ];

    public function __construct(private string $root) {}

    public function inspect(string $archive, array $current): array
    {
        return $this->withArchive($archive, function (ZipArchive $zip) use ($current): array {
            return $this->validate($zip, $current);
        });
    }

    /** @return array{manifest:array,payload:string} */
    public function extract(string $archive, string $destination, array $current): array
    {
        return $this->withArchive($archive, function (ZipArchive $zip) use ($destination, $current): array {
            $manifest = $this->validate($zip, $current);
            if (file_exists($destination) || is_link($destination)) {
                throw new RuntimeException('更新暂存目录必须尚不存在。');
            }
            $relativeDestination = substr($destination, strlen(rtrim($this->root, '/')) + 1);
            if (! str_starts_with($destination, rtrim($this->root, '/').'/') || ! self::canonicalPath($relativeDestination)) {
                throw new RuntimeException('更新暂存目录必须位于本站受控路径。');
            }
            $parent = dirname($destination);
            while ($parent !== rtrim($this->root, '/') && $parent !== dirname($parent)) {
                if (is_link($parent)) {
                    throw new RuntimeException('更新暂存路径包含软链接。');
                }
                $parent = dirname($parent);
            }
            if (! mkdir($destination, 0700, true)) {
                throw new RuntimeException('无法创建更新暂存目录。');
            }
            foreach ($manifest['files'] as $path => $metadata) {
                $target = $destination.'/payload/'.$path;
                if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0700, true)) {
                    throw new RuntimeException('无法创建更新暂存子目录。');
                }
                $input = $zip->getStream('payload/'.$path);
                $output = fopen($target, 'x');
                if ($input === false || $output === false) {
                    throw new RuntimeException('无法读取更新包文件。');
                }
                try {
                    chmod($target, 0600);
                    $copied = stream_copy_to_stream($input, $output, $metadata['size'] + 1);
                    if ($copied !== $metadata['size'] || ! fflush($output)) {
                        throw new RuntimeException('更新包文件解压不完整。');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if (! hash_equals($metadata['sha256'], hash_file('sha256', $target))) {
                    throw new RuntimeException('更新包文件完整性验证失败。');
                }
            }

            return ['manifest' => $manifest, 'payload' => $destination.'/payload'];
        });
    }

    public static function allowedPath(string $path): bool
    {
        if (! self::canonicalPath($path)) {
            return false;
        }
        if (in_array($path, ['.env.example', 'artisan', 'composer.json', 'composer.lock', 'release.json', 'BUILD.json', 'FILES.sha256', 'README.md', 'public/index.php', 'public/robots.txt'], true)) {
            return true;
        }
        foreach (['app/', 'config/', 'lang/', 'database/migrations/', 'resources/views/', 'routes/', 'bootstrap/', 'vendor/', 'public/assets/', 'deploy/', 'docs/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $vendor = str_starts_with($path, 'vendor/');
                foreach (explode('/', $path) as $component) {
                    $permittedMetadata = $vendor && $component === basename($path) && in_array($component, ['.gitignore', '.editorconfig', '.gitattributes', '.phpstorm.meta.php', '.deepsource.toml'], true);
                    if ((str_starts_with($component, '.') && ! $permittedMetadata) || (! $vendor && in_array(strtolower($component), ['tests', 'test', 'backups', 'node_modules'], true))) {
                        return false;
                    }
                }
                if (str_starts_with($path, 'bootstrap/cache/')) {
                    return false;
                }
                if (str_starts_with($path, 'public/assets/') && ! preg_match('/\.(?:css|js|webp)\z/D', $path)) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }

    private static function canonicalPath(string $path): bool
    {
        return strlen($path) <= 240 && preg_match('/\A[A-Za-z0-9_.@+-]+(?:\/[A-Za-z0-9_.@+-]+)*\z/D', $path)
            && ! in_array('.', explode('/', $path), true) && ! in_array('..', explode('/', $path), true);
    }

    private function withArchive(string $archive, callable $callback): mixed
    {
        if (! class_exists(ZipArchive::class) || is_link($archive) || ! is_file($archive)
            || filesize($archive) < 1 || filesize($archive) > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('更新包不可读、过大或缺少 PHP Zip 扩展。');
        }
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('更新 ZIP 已损坏。');
        }
        try {
            return $callback($zip);
        } finally {
            $zip->close();
        }
    }

    private function validate(ZipArchive $zip, array $current): array
    {
        if ($zip->numFiles < 3 || $zip->numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException('更新包文件数量无效。');
        }
        $entries = [];
        $expanded = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            $name = $entry['name'];
            $canonical = rtrim($name, '/');
            if (! self::canonicalPath($canonical) || isset($entries[$canonical]) || ($entry['encryption_method'] ?? 0) !== 0) {
                throw new RuntimeException('更新包包含重复、加密或不安全路径。');
            }
            $opsys = $attributes = 0;
            $zip->getExternalAttributesIndex($index, $opsys, $attributes);
            $type = ($attributes >> 16) & 0170000;
            $directory = str_ends_with($name, '/');
            if (($type !== 0 && $type !== ($directory ? 0040000 : 0100000)) || ($directory && $entry['size'] !== 0)) {
                throw new RuntimeException('更新包包含软链接或特殊文件。');
            }
            if (! $directory && str_starts_with($name, 'payload/') && $opsys === ZipArchive::OPSYS_UNIX
                && (($attributes >> 16) & 07777) !== 0644) {
                throw new RuntimeException('更新包程序文件 ZIP 权限必须为 0644。');
            }
            if ($entry['size'] > 67108864 || ($entry['size'] > 1048576 && $entry['size'] / max(1, $entry['comp_size']) > 2000)) {
                throw new RuntimeException('更新包解压比例或文件大小超出限制。');
            }
            $expanded += $entry['size'];
            if ($expanded > self::MAX_EXPANDED_BYTES) {
                throw new RuntimeException('更新包解压总量超出限制。');
            }
            if ($directory) {
                if ($name !== 'payload/' && ! str_starts_with($name, 'payload/')) {
                    throw new RuntimeException('更新包目录无效。');
                }
            } elseif (! in_array($name, ['manifest.json', 'signature.txt'], true) && ! str_starts_with($name, 'payload/')) {
                throw new RuntimeException('更新包包含未声明文件。');
            }
            $entries[$canonical] = $entry;
        }
        if (! isset($entries['manifest.json'], $entries['signature.txt']) || $entries['manifest.json']['size'] > 8388608 || $entries['signature.txt']['size'] > 4096) {
            throw new RuntimeException('更新包缺少有效签名清单。');
        }
        $body = $zip->getFromName('manifest.json');
        $signature = base64_decode(trim($zip->getFromName('signature.txt')), true);
        $keyPath = $this->root.'/bootstrap/update-public.pem';
        $publicKey = null;
        if (! is_link($this->root.'/bootstrap') && ! is_link($keyPath) && is_file($keyPath) && filesize($keyPath) <= 16384) {
            $publicKey = openssl_pkey_get_public(file_get_contents($keyPath));
        }
        $details = $publicKey === false || $publicKey === null ? null : openssl_pkey_get_details($publicKey);
        if (! is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048 || $signature === false
            || openssl_verify($body, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('更新包签名无效，必须使用本站固定发行公钥签名的更新包。');
        }
        $manifest = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $this->validateManifest($manifest, $current);
        if (array_diff(self::REQUIRED_FILES, array_keys($manifest['files'])) !== []) {
            throw new RuntimeException('更新包不是包含生产依赖与恢复入口的完整程序包。');
        }
        $expected = ['manifest.json' => true, 'signature.txt' => true];
        foreach ($manifest['files'] as $path => $metadata) {
            if (! self::allowedPath($path) || ! is_array($metadata) || ! is_int($metadata['size'] ?? null)
                || $metadata['size'] < 0 || ($metadata['mode'] ?? null) !== 0644
                || ! is_string($metadata['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $metadata['sha256'])) {
                throw new RuntimeException('更新清单包含私有路径或无效文件记录。');
            }
            if (in_array($path, ['bootstrap/update-public.pem', 'bootstrap/update-gateway.php', 'public/index.php'], true)) {
                $protected = $this->root.'/'.$path;
                if (! is_file($protected) || is_link($protected) || ! hash_equals(hash_file('sha256', $protected), $metadata['sha256'])) {
                    throw new RuntimeException('更新包不得替换固定恢复入口或发行公钥。');
                }
            }
            $name = 'payload/'.$path;
            if (! isset($entries[$name]) || $entries[$name]['size'] !== $metadata['size']) {
                throw new RuntimeException('更新包缺少清单文件或大小不符。');
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                throw new RuntimeException('更新包文件不可读。');
            }
            try {
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                if (! hash_equals($metadata['sha256'], hash_final($hash))) {
                    throw new RuntimeException('更新包文件已被篡改。');
                }
            } finally {
                fclose($stream);
            }
            $expected[$name] = true;
            $directory = dirname($name);
            while ($directory !== '.') {
                $expected[$directory] = true;
                $directory = dirname($directory);
            }
        }
        foreach ($entries as $name => $entry) {
            if (! isset($expected[$name])) {
                throw new RuntimeException('更新包包含清单以外的文件。');
            }
        }

        $release = json_decode($zip->getFromName('payload/release.json'), true, 64, JSON_THROW_ON_ERROR);
        $managed = array_keys($manifest['files']);
        $releaseManaged = $release['managed_files'] ?? [];
        if (! is_array($releaseManaged) || ! array_is_list($releaseManaged)) {
            throw new RuntimeException('更新程序清单格式无效。');
        }
        sort($managed);
        sort($releaseManaged);
        if (($release['program'] ?? null) !== $manifest['program'] || ($release['schema'] ?? null) !== $manifest['schema']
            || $releaseManaged !== $managed || ($manifest['media_migrations'] ?? null) !== false) {
            throw new RuntimeException('程序版本记录、受管理文件或图片兼容声明不一致。');
        }
        foreach ($manifest['schema'] as $migration) {
            if (! isset($manifest['files']['database/migrations/'.$migration.'.php'])) {
                throw new RuntimeException('更新包缺少数据库迁移文件。');
            }
        }

        return $manifest;
    }

    private function validateManifest(mixed $manifest, array $current): void
    {
        if (isset($manifest['release_notes']) && (! is_string($manifest['release_notes']) || strlen($manifest['release_notes']) > 8000)) {
            throw new RuntimeException('发行更新说明格式无效。');
        }
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== 1 || ! is_array($manifest['files'] ?? null) || $manifest['files'] === []
            || ! is_array($manifest['schema'] ?? null) || ! array_is_list($manifest['schema']) || $manifest['schema'] === []
            || ! is_array($current['schema'] ?? null) || ! array_is_list($current['schema']) || $current['schema'] === []) {
            throw new RuntimeException('更新清单或当前数据库基线无效。');
        }
        foreach ([$manifest['program'] ?? null, $manifest['compatible']['min'] ?? null, $manifest['compatible']['max'] ?? null, $current['program'] ?? null, $manifest['php_min'] ?? null] as $version) {
            if (! is_string($version) || ! preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $version)) {
                throw new RuntimeException('更新版本范围无效。');
            }
        }
        $legacy = ($current['baseline'] ?? null) === 'legacy-20261028' && $current['program'] === '0.0.0' && $manifest['program'] === '1.0.0'
            && $manifest['compatible']['min'] === '0.0.0' && $manifest['compatible']['max'] === '0.0.0';
        if (version_compare($manifest['program'], $current['program'], '<=')
            || version_compare($current['program'], $manifest['compatible']['min'], '<')
            || version_compare($current['program'], $manifest['compatible']['max'], '>')
            || (! $legacy && explode('.', $manifest['program'])[0] !== explode('.', $current['program'])[0])
            || version_compare(PHP_VERSION, $manifest['php_min'], '<')) {
            throw new RuntimeException('更新包不支持当前版本、降级、同版本重装或当前 PHP 环境。');
        }
        $schema = [];
        foreach ($manifest['schema'] as $migration) {
            if (! is_string($migration) || ! preg_match('/\A\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\z/D', $migration) || isset($schema[$migration])) {
                throw new RuntimeException('更新数据库版本清单无效。');
            }
            $schema[$migration] = true;
        }
        if (array_diff($current['schema'], $manifest['schema']) !== []) {
            throw new RuntimeException('更新包不包含当前数据库迁移基线。');
        }
    }
}
