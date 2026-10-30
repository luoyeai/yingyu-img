<?php

namespace App\Services\Installation;

use Dotenv\Dotenv;
use Dotenv\Parser\EntryParser;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class EnvFile
{
    public function __construct(private string $root) {}

    /** @return array<string, string|null> */
    public function read(): array
    {
        $content = $this->snapshot();
        if ($content === null) {
            return [];
        }
        try {
            foreach (preg_split('/\r\n|\n|\r/', $content) as $line) {
                if (trim($line) !== '' && ! str_starts_with(ltrim($line), '#') && EntryParser::parse($line)->error()->isDefined()) {
                    throw new RuntimeException('Invalid environment entry.');
                }
            }

            return Dotenv::parse($content);
        } catch (Throwable) {
            throw new RuntimeException('环境配置文件无法安全解析，请由服务器管理员检查。');
        }
    }

    public function snapshot(): ?string
    {
        $path = $this->root.'/.env';
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('环境配置路径必须是普通文件。');
        }
        if (! is_file($path)) {
            return null;
        }
        if (filesize($path) > 1048576 || ($content = file_get_contents($path)) === false) {
            throw new RuntimeException('无法读取环境配置文件。');
        }

        return $content;
    }

    /** @param array<string, scalar|null> $values */
    public function render(array $values): string
    {
        $lines = [];
        foreach ($values as $key => $value) {
            if (! preg_match('/\A[A-Z][A-Z0-9_]*\z/D', $key)) {
                throw new InvalidArgumentException('环境变量名称无效。');
            }
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '');
            if (preg_match('/[\x00-\x1F\x7F]/', $value) || ! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('配置值不得包含换行、控制字符或无效编码。');
            }
            $encoded = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
            $lines[] = $key.'="'.$encoded.'"';
        }
        $content = implode("\n", $lines)."\n";
        $parsed = Dotenv::parse($content);
        foreach ($values as $key => $value) {
            $expected = is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '');
            if (($parsed[$key] ?? null) !== $expected) {
                throw new RuntimeException('配置编码校验失败。');
            }
        }

        return $content;
    }

    public function writeAtomic(array|string $values): void
    {
        $this->atomicFile($this->root.'/.env', is_array($values) ? $this->render($values) : $values);
    }

    public function restore(?string $content): void
    {
        if ($content === null) {
            $path = $this->root.'/.env';
            if (is_link($path) || (file_exists($path) && ! is_file($path))) {
                throw new RuntimeException('无法恢复环境配置文件。');
            }
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('无法恢复环境配置文件。');
            }

            return;
        }
        $this->writeAtomic($content);
    }

    public function atomicFile(string $path, string $content): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) || is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('配置写入路径无效。');
        }
        $temporary = tempnam($directory, '.installation-');
        if ($temporary === false) {
            throw new RuntimeException('无法创建配置暂存文件。');
        }
        try {
            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('无法限制配置文件权限。');
            }
            $handle = fopen($temporary, 'wb');
            if ($handle === false) {
                throw new RuntimeException('无法打开配置暂存文件。');
            }
            try {
                $offset = 0;
                while ($offset < strlen($content)) {
                    $written = fwrite($handle, substr($content, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('无法完整写入配置文件。');
                    }
                    $offset += $written;
                }
                if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                    throw new RuntimeException('无法同步配置文件。');
                }
            } finally {
                fclose($handle);
            }
            if (! rename($temporary, $path)) {
                throw new RuntimeException('无法原子替换配置文件。');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
