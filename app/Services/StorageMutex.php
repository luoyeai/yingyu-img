<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StorageMutex
{
    private bool $held = false;

    public function run(callable $operation): mixed
    {
        if ($this->held) {
            return $operation();
        }
        $connection = DB::connection();
        $name = substr('vault-storage-'.hash('sha256', $connection->getDatabaseName()), 0, 64);
        if ((int) $connection->selectOne('SELECT GET_LOCK(?, 3) AS acquired', [$name])->acquired !== 1) {
            throw new UploadFailure('存储正在处理其他操作，请稍后重试。', 'storage_busy', true, 'file');
        }
        $this->held = true;
        try {
            return $operation();
        } finally {
            $this->held = false;
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
        }
    }
}
