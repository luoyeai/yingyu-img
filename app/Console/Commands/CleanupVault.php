<?php

namespace App\Console\Commands;

use App\Models\Image;
use App\Models\Setting;
use App\Services\ImageStorage;
use App\Services\RemoteImageStorage;
use App\Services\Updates\UpdateRunner;
use App\Services\Updates\UpdateState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanupVault extends Command
{
    protected $signature = 'vault:cleanup {--updates-only : 仅核验并清理已完成的更新任务}';

    protected $description = '永久清除回收站满30天图片、过期暂存文件和已验证成功的更新备份';

    public function handle(ImageStorage $storage, UpdateState $state, UpdateRunner $runner): int
    {
        $updates = $runner->cleanupCompleted();
        if ($updates['pending'] > 0) {
            $this->warn('成功更新的暂存或备份清理待重试，不影响已完成更新。');
        }
        if ($this->option('updates-only')) {
            $this->info($updates['skipped'] ? '更新或恢复中，或状态暂不可用，已跳过更新任务清理。' : '成功更新任务核验及清理完成；未处理回收站或云待办。');

            return $updates['pending'] > 0 ? self::FAILURE : self::SUCCESS;
        }
        $path = $state->directory().'/write.lock';
        if (is_link($path)) {
            $this->error('更新写入锁路径无效。');

            return self::FAILURE;
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            $this->error('无法取得更新写入锁。');

            return self::FAILURE;
        }
        try {
            clearstatcache(true, $path);
            if ((fileperms($path) & 0777) !== 0600 && ! chmod($path, 0600)) {
                $this->error('无法保护更新写入锁。');

                return self::FAILURE;
            }
            if (! flock($handle, LOCK_SH | LOCK_NB)) {
                $this->warn('系统更新或恢复中，已跳过本次清理。');

                return self::SUCCESS;
            }
            $journal = $state->read('journal.json');
            if (($journal['active'] ?? false) || in_array($journal['status'] ?? '', ['running', 'failed', 'restoring'], true)
                || ($journal['stage'] ?? '') === 'migration-running') {
                $this->warn('系统更新或恢复中，已跳过本次清理。');

                return self::SUCCESS;
            }

            return $storage->synchronized(fn (): int => $this->cleanup($storage));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function cleanup(ImageStorage $storage): int
    {
        $retries = app(RemoteImageStorage::class)->retryPending();
        $count = 0;
        $failed = $retries['failed'];
        Image::onlyTrashed()->where('deleted_at', '<=', now()->subDays(30))->orderBy('id')->chunkById(50, function ($images) use ($storage, &$count, &$failed) {
            foreach ($images as $image) {
                try {
                    if ($storage->purge($image, now()->subDays(30))) {
                        $count++;
                    }
                } catch (\Throwable) {
                    $failed++;
                }
            }
        });
        $root = storage_path('app/tmp');
        if (is_dir($root)) {
            foreach (File::files($root) as $file) {
                /** Legacy deletion staging may contain the only surviving copy after an interrupted transaction. */
                if (preg_match('/\A(?:original|purge)-[a-f0-9]{32}\z/D', $file->getFilename())) {
                    continue;
                }
                if ($file->getMTime() < time() - 86400) {
                    File::delete($file->getPathname());
                }
            }
        }
        Setting::put('cron_last_run', now()->toIso8601String());
        $this->info('回收站清除 '.$count.' 张；待重试 '.$failed.' 项。');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
