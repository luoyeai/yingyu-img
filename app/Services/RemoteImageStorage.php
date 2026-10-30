<?php

namespace App\Services;

use App\Models\Image;
use App\Models\StorageConfiguration;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Durable intents track remote effects and committed local deletions. All callers hold StorageMutex. */
class RemoteImageStorage
{
    public function __construct(private StorageManager $manager) {}

    public function assertIdle(Image $image): void
    {
        if (DB::table('storage_operations')->where('image_id', $image->id)->exists()) {
            throw new UploadFailure('该图片有未完成的存储操作，请在设置中重试后继续。', 'storage_pending');
        }
    }

    public function upload(UploadedFile $file, array $result, ?int $groupId, StorageConfiguration $configuration): Image
    {
        $path = now()->format('Y/m').'/'.bin2hex(random_bytes(16)).'.webp';
        $originalPath = $result['processing']['keep_original'] ? substr($path, 0, -4).$result['source_extension'] : null;
        $payload = ['preview_path' => $path, 'original_path' => $originalPath, 'disabled_path' => null];
        $store = $this->manager->store($configuration);
        $store->assertSafeBucket();
        $operation = $this->begin($configuration, 'upload_cleanup', $path, null, $payload, $result['fingerprint']);
        try {
            $hash = hash_file('sha256', $result['temp_path']);
            $this->markUnknown($operation);
            $store->put($configuration->objectKey($path), $result['temp_path']);
            $this->verifyRemote($store, $configuration->objectKey($path), $result['bytes'], $hash);
            $this->copyLocal($result['preview_temp_path'], $this->local('previews', $path));
            if ($originalPath) {
                $this->copyLocal($file->getPathname(), $this->local('originals', $originalPath));
            }

            return DB::transaction(function () use ($operation, $file, $result, $groupId, $configuration, $path, $originalPath, $hash): Image {
                $name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
                $image = Image::create([
                    'storage_configuration_id' => $configuration->id, 'content_sha256' => $hash,
                    'original_name' => $name, 'display_name' => $name, 'alt' => '', 'group_id' => $groupId,
                    'path' => $path, 'preview_path' => $path, 'original_path' => $originalPath, 'mime' => 'image/webp',
                    'source_width' => $result['source_width'], 'source_height' => $result['source_height'],
                    'width' => $result['width'], 'height' => $result['height'], 'source_bytes' => $result['source_bytes'],
                    'bytes' => $result['bytes'], 'preview_bytes' => filesize($this->local('previews', $path)),
                    'original_bytes' => $originalPath ? $result['source_bytes'] : 0,
                    'source_hash' => $result['source_hash'], 'fingerprint' => $result['fingerprint'], 'processing' => $result['processing'],
                ]);
                DB::table('storage_operations')->where('id', $operation)->delete();

                return $image;
            });
        } catch (Throwable) {
            $this->compensate($operation);
            throw new UploadFailure('云端上传未完成；已登记清理任务，请检查存储操作状态后重试。', 'storage_pending');
        }
    }

    public function duplicate(Image $image, ?int $groupId): Image
    {
        $this->assertIdle($image);
        $configuration = $image->storageConfiguration;
        $path = now()->format('Y/m').'/'.bin2hex(random_bytes(16)).'.webp';
        $originalPath = $image->original_path ? substr($path, 0, -4).pathinfo($image->original_path, PATHINFO_EXTENSION) : null;
        $store = $image->disabled_at ? null : $this->manager->store($configuration);
        $store?->assertSafeBucket();
        $operation = $this->begin($configuration, 'upload_cleanup', $path, null,
            ['preview_path' => $path, 'original_path' => $originalPath, 'disabled_path' => $image->disabled_at ? $path : null]);
        try {
            $this->markUnknown($operation);
            if ($image->disabled_at) {
                $this->copyLocal($this->local('disabled', $image->path), $this->local('disabled', $path));
            } else {
                $store->copy($configuration->objectKey($image->path), $configuration->objectKey($path));
                $this->verifyRemote($store, $configuration->objectKey($path), $image->bytes, $image->content_sha256);
            }
            $this->copyLocal($this->local('previews', $image->preview_path), $this->local('previews', $path));
            if ($originalPath) {
                $this->copyLocal($this->local('originals', $image->original_path), $this->local('originals', $originalPath));
            }

            return DB::transaction(function () use ($image, $groupId, $path, $originalPath, $operation): Image {
                $copy = $image->replicate();
                $copy->fill(['path' => $path, 'preview_path' => $path, 'original_path' => $originalPath,
                    'group_id' => $groupId, 'display_name' => mb_substr($image->display_name, 0, 248).' 副本', 'fingerprint' => null]);
                $copy->save();
                DB::table('storage_operations')->where('id', $operation)->delete();

                return $copy;
            });
        } catch (Throwable) {
            $this->compensate($operation);
            throw new RuntimeException('复制未完成，已保留可重试清理记录。');
        }
    }

    public function changeAvailability(Image $image, bool $disabled): void
    {
        $this->assertIdle($image);
        if ((bool) $image->disabled_at === $disabled) {
            return;
        }
        $id = $this->begin($image->storageConfiguration, $disabled ? 'disable' : 'enable', $image->path, $image->id,
            ['sha256' => $image->content_sha256, 'bytes' => $image->bytes]);
        $this->execute($id);
        $image->refresh();
    }

    public function purge(Image $image, ?CarbonInterface $expiredBefore): bool
    {
        $this->assertIdle($image);
        if (! $image->trashed() || ($expiredBefore && $image->deleted_at->greaterThan($expiredBefore))) {
            return false;
        }
        $id = $this->begin($image->storageConfiguration, 'purge', $image->path, $image->id,
            ['preview_path' => $image->preview_path, 'original_path' => $image->original_path, 'disabled_path' => $image->path,
                'sha256' => $image->content_sha256, 'bytes' => $image->bytes]);
        $this->execute($id);

        return true;
    }

    public function begin(StorageConfiguration $configuration, string $operation, string $path, ?int $imageId, array $payload, ?string $identity = null): int
    {
        if (DB::transactionLevel() !== 0 && ! app()->environment('testing')) {
            throw new RuntimeException('云端存储操作不能嵌入尚未提交的数据库事务。');
        }

        return DB::table('storage_operations')->insertGetId(['storage_configuration_id' => $configuration->id,
            'image_id' => $imageId, 'operation' => $operation, 'path' => $path, 'identity' => $identity,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'state' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function retryPending(): array
    {
        $completed = 0;
        $failed = 0;
        foreach (DB::table('storage_operations')->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('attempts')->orderBy('updated_at')->orderBy('id')->limit(50)->pluck('id') as $id) {
            try {
                $this->execute($id);
                $completed++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return compact('completed', 'failed');
    }

    public function execute(int $id): void
    {
        $operation = DB::table('storage_operations')->find($id);
        if (! $operation) {
            return;
        }
        $localCleanup = in_array($operation->operation, ['local_original_cleanup', 'local_purge_cleanup'], true);
        try {
            $configuration = StorageConfiguration::findOrFail($operation->storage_configuration_id);
            $payload = json_decode($operation->payload, true, 32, JSON_THROW_ON_ERROR);
            if ($localCleanup) {
                if ($operation->state === 'committed') {
                    $this->assertLocalCleanupUnreferenced($payload);
                    $this->cleanLocal($payload);
                } elseif ($operation->state !== 'pending') {
                    throw new RuntimeException('本地清理提交状态无效。');
                }
                DB::table('storage_operations')->where('id', $id)->delete();

                return;
            }
            $image = $operation->image_id ? Image::withTrashed()->find($operation->image_id) : null;
            if ($operation->state === 'committed') {
                $this->cleanLocal($payload);
                DB::table('storage_operations')->where('id', $id)->delete();

                return;
            }
            $store = $this->manager->store($configuration);
            $key = $configuration->objectKey($operation->path);
            $this->markUnknown($id);
            if (in_array($operation->operation, ['upload_cleanup', 'test_cleanup'], true)) {
                if (Image::withTrashed()->where('storage_configuration_id', $configuration->id)->where('path', $operation->path)->exists()) {
                    throw new RuntimeException('清理目标已有图片引用，拒绝删除。');
                }
                $store->delete($key);
                $this->cleanLocal($payload);
                DB::table('storage_operations')->where('id', $id)->delete();

                return;
            }
            if (! $image || $image->path !== $operation->path || $image->storage_configuration_id !== $configuration->id) {
                throw new RuntimeException('图片存储归属已变化，拒绝继续。');
            }
            $backup = $this->local('disabled', $image->path);
            if ($operation->operation === 'disable' || $operation->operation === 'purge') {
                if (! is_file($backup)) {
                    $temporary = $this->temporary();
                    try {
                        $store->download($key, $temporary, $image->bytes);
                        $this->verifyFile($temporary, $image->bytes, $image->content_sha256);
                        $this->copyLocal($temporary, $backup);
                    } finally {
                        if (is_file($temporary)) {
                            unlink($temporary);
                        }
                    }
                }
                $this->verifyFile($backup, $image->bytes, $image->content_sha256);
                $store->delete($key);
            } elseif ($operation->operation === 'enable') {
                $this->verifyFile($backup, $image->bytes, $image->content_sha256);
                $store->put($key, $backup);
                $this->verifyRemote($store, $key, $image->bytes, $image->content_sha256);
            } else {
                throw new RuntimeException('未知存储操作。');
            }
            DB::transaction(function () use ($operation, $image, $id, $backup): void {
                if ($operation->operation === 'purge') {
                    if (! $image->trashed()) {
                        throw new RuntimeException('图片已恢复，拒绝删除记录。');
                    }
                    $image->forceDelete();
                    DB::table('storage_operations')->where('id', $id)->update(['state' => 'committed', 'updated_at' => now()]);
                } else {
                    $image->forceFill(['disabled_at' => $operation->operation === 'disable' ? now() : null,
                        'content_sha256' => hash_file('sha256', $backup)])->save();
                    if ($operation->operation === 'enable') {
                        DB::table('storage_operations')->where('id', $id)->update(['state' => 'committed', 'payload' => json_encode(['disabled_path' => $image->path]), 'updated_at' => now()]);
                    } else {
                        DB::table('storage_operations')->where('id', $id)->delete();
                    }
                }
            });
            if ($operation->operation !== 'disable') {
                $this->execute($id);
            }
        } catch (Throwable) {
            DB::table('storage_operations')->where('id', $id)->update(['state' => DB::table('storage_operations')->where('id', $id)->value('state') === 'committed' ? 'committed' : ($localCleanup ? $operation->state : (in_array($operation->operation, ['upload_cleanup', 'test_cleanup'], true) ? 'compensating' : 'unknown')),
                'attempts' => DB::raw('attempts + 1'), 'error_code' => 'storage_operation_failed', 'next_attempt_at' => now()->addMinute(), 'updated_at' => now()]);
            if ($localCleanup) {
                throw new RuntimeException('本地文件清理尚未完成，已保留操作记录，请在设置中重试。');
            }
            throw new RuntimeException('存储操作结果尚未确认，图片记录与安全副本已保留，请在设置中重试。');
        }
    }

    public function verifyFile(string $path, int $bytes, ?string $hash): void
    {
        if (! is_file($path) || filesize($path) !== $bytes || ($hash && ! hash_equals($hash, hash_file('sha256', $path)))) {
            throw new RuntimeException('完整 WebP 校验失败，已阻止远程删除。');
        }
    }

    private function verifyRemote(ObjectStore $store, string $key, int $bytes, ?string $hash): void
    {
        $head = $store->head($key);
        if (! $head || $head['bytes'] !== $bytes || ($hash && ! hash_equals($hash, $head['sha256'] ?? ''))) {
            throw new RuntimeException('远程 WebP 保存校验失败。');
        }
    }

    private function markUnknown(int $id): void
    {
        DB::table('storage_operations')->where('id', $id)->update(['state' => 'unknown', 'updated_at' => now()]);
    }

    private function compensate(int $id): void
    {
        DB::table('storage_operations')->where('id', $id)->update(['state' => 'compensating', 'updated_at' => now()]);
        try {
            $this->execute($id);
        } catch (Throwable) {
            // A durable intent remains; neither credentials nor the SDK exception are logged.
        }
    }

    private function cleanLocal(array $payload): void
    {
        foreach (['public_path' => 'public', 'preview_path' => 'previews', 'original_path' => 'originals', 'disabled_path' => 'disabled'] as $field => $area) {
            if (! empty($payload[$field])) {
                $path = $this->local($area, $payload[$field]);
                if (is_file($path) && ! unlink($path)) {
                    throw new RuntimeException('私有存储清理待重试。');
                }
            }
        }
    }

    private function assertLocalCleanupUnreferenced(array $payload): void
    {
        foreach (['public_path' => 'path', 'disabled_path' => 'path', 'preview_path' => 'preview_path', 'original_path' => 'original_path'] as $field => $column) {
            if (! empty($payload[$field]) && Image::withTrashed()->where($column, $payload[$field])->exists()) {
                throw new RuntimeException('本地清理目标仍被图片引用，拒绝删除。');
            }
        }
    }

    private function local(string $area, string $path): string
    {
        $extension = $area === 'originals' ? '(?:webp|jpg|png)' : 'webp';
        if (! preg_match('~\A[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{32}\.'.$extension.'\z~D', $path)) {
            throw new InvalidArgumentException('私有存储路径无效。');
        }

        return $area === 'public' ? public_path('media/'.$path) : storage_path('app/'.$area.'/'.$path);
    }

    private function temporary(): string
    {
        $directory = storage_path('app/tmp');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('暂存目录不可写。');
        }

        return $directory.'/remote-'.bin2hex(random_bytes(16));
    }

    private function copyLocal(string $source, string $destination): void
    {
        if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true) && ! is_dir(dirname($destination))) {
            throw new RuntimeException('私有图片目录不可写。');
        }
        $temporary = $this->temporary();
        try {
            if (! is_file($source) || ! copy($source, $temporary) || hash_file('sha256', $source) !== hash_file('sha256', $temporary)
                || file_exists($destination) || ! rename($temporary, $destination)) {
                throw new RuntimeException('私有图片保存校验失败。');
            }
            chmod($destination, 0600);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
