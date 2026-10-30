<?php

namespace App\Services\Updates;

use RuntimeException;

class UpdateState
{
    public function __construct(private string $root) {}

    public function root(): string
    {
        return $this->root;
    }

    public function directory(): string
    {
        $path = $this->root;
        foreach (['storage', 'app', 'updates'] as $part) {
            $path .= '/'.$part;
            if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
                throw new RuntimeException('更新私有目录路径无效。');
            }
            if (! is_dir($path) && ! mkdir($path, 0700)) {
                throw new RuntimeException('无法创建更新私有目录。');
            }
        }
        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== 0700 && ! chmod($path, 0700)) {
            throw new RuntimeException('无法保护更新私有目录。');
        }

        return $path;
    }

    public function read(string $name): ?array
    {
        $path = $this->path($name);
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }
        if (is_link($path) || ! is_file($path) || filesize($path) > 16777216) {
            throw new RuntimeException('更新状态文件无效。');
        }
        $data = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('更新状态格式无效。');
        }

        return $data;
    }

    public function write(string $name, array $data): void
    {
        $path = $this->path($name);
        if (is_link($path)) {
            throw new RuntimeException('更新状态路径无效。');
        }
        $temporary = $this->directory().'/.state-'.bin2hex(random_bytes(12));
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('无法创建更新状态。');
        }
        try {
            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('无法保护更新状态。');
            }
            $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
            if (fwrite($handle, $body) !== strlen($body) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('无法完整保存更新状态。');
            }
            fclose($handle);
            $handle = null;
            if (! rename($temporary, $path)) {
                throw new RuntimeException('无法原子保存更新状态。');
            }
            $directory = fopen($this->directory(), 'r');
            if ($directory === false) {
                throw new RuntimeException('无法同步更新状态目录。');
            }
            try {
                if (! fsync($directory)) {
                    throw new RuntimeException('无法同步更新状态目录。');
                }
            } finally {
                fclose($directory);
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function locked(callable $callback): mixed
    {
        $path = $this->directory().'/update.lock';
        if (is_link($path)) {
            throw new RuntimeException('更新锁路径无效。');
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('无法打开更新锁。');
        }
        try {
            clearstatcache(true, $path);
            if ((fileperms($path) & 0777) !== 0600 && ! chmod($path, 0600)) {
                throw new RuntimeException('无法保护更新锁。');
            }
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('另一个更新或恢复请求正在执行，请稍后重试。');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function version(): array
    {
        $version = $this->read('version.json');
        if ($version === null || ! is_string($version['program'] ?? null)
            || ! preg_match('/\A\d+\.\d+\.\d+\z/D', $version['program'])
            || ! is_array($version['schema'] ?? null) || ! array_is_list($version['schema'])
            || ! is_array($version['managed_files'] ?? null)) {
            throw new RuntimeException('未知版本基线，必须先完成受支持的首次接入。');
        }

        return $version;
    }

    private function path(string $name): string
    {
        if (! preg_match('/\A[a-z][a-z0-9-]*\.json\z/D', $name)) {
            throw new RuntimeException('更新状态名称无效。');
        }

        return $this->directory().'/'.$name;
    }
}
