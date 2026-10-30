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

class ImageStorage
{
    public function __construct(private ImageProcessor $processor, private StorageManager $manager, private RemoteImageStorage $remote, private StorageMutex $mutex) {}

    public function synchronized(callable $operation): mixed
    {
        return $this->mutex->run($operation);
    }

    public function withImage(Image $image, callable $operation): mixed
    {
        return $this->mutex->run(function () use ($image, $operation): mixed {
            $record = Image::withTrashed()->findOrFail($image->id);
            $this->remote->assertIdle($record);

            return $operation($record);
        });
    }

    /** @return array{image:Image,duplicate:bool,trashed:bool} */
    public function upload(UploadedFile $file, array $options, ?int $groupId = null, ?int $storageId = null): array
    {
        return $this->mutex->run(fn (): array => $this->uploadToConfiguration($file, $options, $groupId, $this->manager->target($storageId)));
    }

    /** @return array{image:Image,duplicate:bool,trashed:bool} */
    private function uploadToConfiguration(UploadedFile $file, array $options, ?int $groupId, StorageConfiguration $configuration): array
    {
        $result = $this->processor->process($file, $options);
        $published = [];
        $lock = null;
        try {
            $lock = $this->lock(substr($result['fingerprint'], 0, 2));
            if (DB::table('storage_operations')->where('storage_configuration_id', $configuration->id)->where('identity', $result['fingerprint'])->exists()) {
                throw new UploadFailure('同一目标的上传尚有未完成清理，请在设置中重试存储操作。', 'storage_pending');
            }
            $existing = $this->findDuplicate($result, $configuration->id);
            if ($existing) {
                return ['image' => $existing, 'duplicate' => true, 'trashed' => $existing->trashed()];
            }
            if ($configuration->driver !== 'local') {
                return ['image' => $this->remote->upload($file, $result, $groupId, $configuration), 'duplicate' => false, 'trashed' => false];
            }
            $contentHash = hash_file('sha256', $result['temp_path']);
            $path = $this->newPath();
            $image = DB::transaction(function () use ($file, $groupId, $path, $result, $configuration, $contentHash, &$published): Image {
                $this->publish($result['temp_path'], $this->publicPath($path), $published);
                $this->publish($result['preview_temp_path'], $this->privatePath('previews', $path), $published);
                $originalPath = null;
                if ($result['processing']['keep_original']) {
                    $originalPath = substr($path, 0, -4).$result['source_extension'];
                    $this->copyFile($file->getPathname(), $this->privatePath('originals', $originalPath), $published);
                }
                $originalName = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);

                return Image::create([
                    'storage_configuration_id' => $configuration->id, 'content_sha256' => $contentHash,
                    'original_name' => $originalName,
                    'display_name' => $originalName,
                    'alt' => '',
                    'group_id' => $groupId,
                    'path' => $path,
                    'preview_path' => $path,
                    'original_path' => $originalPath,
                    'mime' => 'image/webp',
                    'source_width' => $result['source_width'],
                    'source_height' => $result['source_height'],
                    'width' => $result['width'],
                    'height' => $result['height'],
                    'source_bytes' => $result['source_bytes'],
                    'bytes' => $result['bytes'],
                    'preview_bytes' => filesize($this->privatePath('previews', $path)),
                    'original_bytes' => $originalPath ? $result['source_bytes'] : 0,
                    'source_hash' => $result['source_hash'],
                    'fingerprint' => $result['fingerprint'],
                    'processing' => $result['processing'],
                ]);
            });

            return ['image' => $image, 'duplicate' => false, 'trashed' => false];
        } catch (Throwable $exception) {
            $this->removePublished($published);
            throw $exception;
        } finally {
            $this->processor->cleanup($result);
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function findDuplicate(array $result, int $storageId): ?Image
    {
        $parameters = ['quality', 'lossless', 'max_edge', 'keep_original'];
        $candidates = Image::withTrashed()->where('storage_configuration_id', $storageId)->where('source_hash', $result['source_hash'])
            ->orderByRaw('deleted_at IS NOT NULL')->orderByRaw('disabled_at IS NOT NULL')->orderBy('id')->get();
        foreach ($candidates as $candidate) {
            if ($result['processing']['keep_original'] && ! $candidate->original_path) {
                continue;
            }
            $matches = true;
            foreach ($parameters as $parameter) {
                if (($candidate->processing[$parameter] ?? null) !== $result['processing'][$parameter]) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $this->remote->assertIdle($candidate);

                return $candidate;
            }
        }

        return null;
    }

    public function deleteOriginal(Image $image): int
    {
        return $this->withImage($image, function (Image $record) use ($image): int {
            $bytes = $this->deleteOriginalLocal($record);
            $image->refresh();

            return $bytes;
        });
    }

    private function deleteOriginalLocal(Image $image): int
    {
        if (! $image->original_path) {
            return 0;
        }
        $source = $this->privatePath('originals', $image->original_path);
        $bytes = is_file($source) ? (int) filesize($source) : 0;
        $this->commitLocalCleanup($image, 'local_original_cleanup', ['original_path' => $image->original_path], function () use ($image): void {
            $record = Image::withTrashed()->lockForUpdate()->findOrFail($image->id);
            $record->forceFill(['original_path' => null, 'original_bytes' => 0, 'fingerprint' => null])->save();
        });
        $image->refresh();

        return $bytes;
    }

    public function disable(Image $image): void
    {
        $this->withImage($image, fn (Image $record) => $this->changeAvailability($record, true));
        $image->refresh();
    }

    public function enable(Image $image): void
    {
        $this->withImage($image, fn (Image $record) => $this->changeAvailability($record, false));
        $image->refresh();
    }

    public function purge(Image $image, ?CarbonInterface $expiredBefore = null): bool
    {
        return $this->mutex->run(function () use ($image, $expiredBefore): bool {
            $record = Image::withTrashed()->find($image->id);
            if (! $record) {
                return false;
            }
            $this->remote->assertIdle($record);

            return $record->storageConfiguration->driver === 'local' ? $this->purgeLocal($record, $expiredBefore) : $this->remote->purge($record, $expiredBefore);
        });
    }

    private function purgeLocal(Image $image, ?CarbonInterface $expiredBefore = null): bool
    {
        if (! $image->trashed() || ($expiredBefore && $image->deleted_at->greaterThan($expiredBefore))) {
            return false;
        }
        $payload = ['preview_path' => $image->preview_path, 'original_path' => $image->original_path,
            'public_path' => $image->disabled_at ? null : $image->path, 'disabled_path' => $image->disabled_at ? $image->path : null];
        $this->commitLocalCleanup($image, 'local_purge_cleanup', $payload, function () use ($image): void {
            $record = Image::withTrashed()->lockForUpdate()->findOrFail($image->id);
            if (! $record->trashed()) {
                throw new RuntimeException('图片已恢复，拒绝删除记录。');
            }
            $record->forceDelete();
        });

        return true;
    }

    /** Files stay at their original paths until the database change and cleanup intent commit together. */
    private function commitLocalCleanup(Image $image, string $operation, array $payload, callable $mutation): void
    {
        $id = $this->remote->begin($image->storageConfiguration, $operation, $image->path, $image->id, $payload);
        try {
            DB::transaction(function () use ($id, $mutation): void {
                $mutation();
                DB::table('storage_operations')->where('id', $id)->update(['state' => 'committed', 'updated_at' => now()]);
            });
        } catch (Throwable $exception) {
            DB::table('storage_operations')->where('id', $id)->where('state', 'pending')->delete();
            throw $exception;
        }
        $this->remote->execute($id);
    }

    public function duplicate(Image $image, ?int $groupId = null): Image
    {
        return $this->withImage($image, function (Image $record) use ($groupId): Image {
            if ($record->trashed()) {
                throw new InvalidArgumentException('回收站图片不能复制。');
            }

            return $record->storageConfiguration->driver === 'local' ? $this->duplicateLocal($record, $groupId) : $this->remote->duplicate($record, $groupId);
        });
    }

    private function duplicateLocal(Image $image, ?int $groupId = null): Image
    {
        $published = [];
        try {
            return DB::transaction(function () use ($image, $groupId, &$published): Image {
                $record = Image::query()->lockForUpdate()->findOrFail($image->id);
                $path = $this->newPath();
                $source = $record->disabled_at ? $this->privatePath('disabled', $record->path) : $this->publicPath($record->path);
                $destination = $record->disabled_at ? $this->privatePath('disabled', $path) : $this->publicPath($path);
                $this->copyFile($source, $destination, $published);
                $this->copyFile($this->privatePath('previews', $record->preview_path), $this->privatePath('previews', $path), $published);
                $originalPath = null;
                if ($record->original_path) {
                    $originalPath = substr($path, 0, -4).pathinfo($record->original_path, PATHINFO_EXTENSION);
                    $this->copyFile($this->privatePath('originals', $record->original_path), $this->privatePath('originals', $originalPath), $published);
                }
                $copy = $record->replicate();
                $copy->fill([
                    'group_id' => $groupId,
                    'path' => $path,
                    'preview_path' => $path,
                    'original_path' => $originalPath,
                    'display_name' => mb_substr($record->display_name, 0, 248).' 副本',
                    'fingerprint' => null,
                ]);
                $copy->save();

                return $copy;
            });
        } catch (Throwable $exception) {
            $this->removePublished($published);
            throw $exception;
        }
    }

    public function publicPath(string $path): string
    {
        $this->validatePath($path, false);

        return public_path('media/'.$path);
    }

    public function privatePath(string $area, string $path): string
    {
        if (! in_array($area, ['originals', 'previews', 'disabled'], true)) {
            throw new InvalidArgumentException('无效的图片存储区域。');
        }
        $this->validatePath($path, $area === 'originals');

        return storage_path('app/'.$area.'/'.$path);
    }

    private function changeAvailability(Image $image, bool $disabled): void
    {
        if ($image->storageConfiguration->driver !== 'local') {
            $this->remote->changeAvailability($image, $disabled);

            return;
        }
        $moved = null;
        try {
            DB::transaction(function () use ($image, $disabled, &$moved): void {
                $record = Image::withTrashed()->lockForUpdate()->findOrFail($image->id);
                if ((bool) $record->disabled_at === $disabled) {
                    return;
                }
                $public = $this->publicPath($record->path);
                $private = $this->privatePath('disabled', $record->path);
                [$source, $destination] = $disabled ? [$public, $private] : [$private, $public];
                if (! is_file($source)) {
                    throw new InvalidArgumentException('图片文件不存在，无法'.($disabled ? '停用' : '恢复').'链接。');
                }
                $this->ensureDirectory(dirname($destination), $disabled ? 0700 : 0755);
                if (file_exists($destination) || ! rename($source, $destination)) {
                    throw new RuntimeException('无法切换图片链接状态，请检查目录权限。');
                }
                $moved = [$source, $destination];
                chmod($destination, $disabled ? 0600 : 0644);
                $record->disabled_at = $disabled ? now() : null;
                $record->save();
            });
        } catch (Throwable $exception) {
            if ($moved && is_file($moved[1])) {
                rename($moved[1], $moved[0]);
                chmod($moved[0], $disabled ? 0644 : 0600);
            }
            throw $exception;
        }
        $image->refresh();
    }

    private function validatePath(string $path, bool $original): void
    {
        $extensions = $original ? '(?:webp|jpg|png)' : 'webp';
        if (! preg_match('~\A[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{32}\.'.$extensions.'\z~D', $path)) {
            throw new InvalidArgumentException('图片路径无效。');
        }
    }

    private function newPath(): string
    {
        do {
            $path = now()->format('Y/m').'/'.bin2hex(random_bytes(16)).'.webp';
        } while (file_exists($this->publicPath($path)) || file_exists($this->privatePath('disabled', $path)));

        return $path;
    }

    /** @return resource */
    private function lock(string $bucket)
    {
        $directory = storage_path('app/locks');
        $this->ensureDirectory($directory, 0700);
        $handle = fopen($directory.'/upload-'.$bucket.'.lock', 'c');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('无法获得上传锁。');
        }

        return $handle;
    }

    private function publish(string $temporary, string $destination, array &$published): void
    {
        $this->ensureDirectory(dirname($destination), str_starts_with($destination, public_path('media/')) ? 0755 : 0700);
        $lock = $this->lock('publish');
        try {
            if (stat($temporary)['dev'] !== stat(dirname($destination))['dev']) {
                throw new RuntimeException('暂存目录与图片目录必须位于同一文件系统，以保证原子保存。');
            }
            if (file_exists($destination) || ! rename($temporary, $destination)) {
                throw new RuntimeException('无法原子保存图片文件。');
            }
            $published[] = $destination;
            chmod($destination, str_starts_with($destination, public_path('media/')) ? 0644 : 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function copyFile(string $source, string $destination, array &$published): void
    {
        if (! is_file($source)) {
            throw new InvalidArgumentException('图片源文件不存在，无法复制。');
        }
        $temporary = storage_path('app/tmp/copy-'.bin2hex(random_bytes(16)));
        $this->ensureDirectory(dirname($temporary), 0700);
        try {
            if (! copy($source, $temporary) || filesize($source) !== filesize($temporary) || hash_file('sha256', $source) !== hash_file('sha256', $temporary)) {
                throw new RuntimeException('图片复制校验失败。');
            }
            $this->publish($temporary, $destination, $published);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function ensureDirectory(string $directory, int $mode): void
    {
        if (! is_dir($directory) && ! mkdir($directory, $mode, true) && ! is_dir($directory)) {
            throw new RuntimeException('无法创建图片存储目录。');
        }
    }

    private function removePublished(array $paths): void
    {
        foreach (array_reverse($paths) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
