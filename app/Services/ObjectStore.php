<?php

namespace App\Services;

interface ObjectStore
{
    public function put(string $key, string $source): void;

    public function download(string $key, string $destination, int $maxBytes = 134217728): void;

    public function copy(string $source, string $destination): void;

    /** @return array{bytes:int,sha256:?string}|null */
    public function head(string $key): ?array;

    public function delete(string $key): void;

    public function assertSafeBucket(): void;
}
