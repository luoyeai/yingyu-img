<?php

namespace App\Console\Commands;

use App\Models\Image;
use App\Services\ImageStorage;
use App\Services\StorageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class AuditVault extends Command
{
    protected $signature = 'vault:audit';

    protected $description = '只读核对数据库与图片文件，报告缺失、尺寸异常及孤立文件';

    public function handle(ImageStorage $storage): int
    {
        $expected = [];
        $problems = 0;
        $count = 0;
        Image::withTrashed()->orderBy('id')->chunkById(100, function ($images) use ($storage, &$expected, &$problems, &$count): void {
            foreach ($images as $image) {
                $count++;
                $files = [['previews', $image->preview_path, $image->preview_bytes]];
                if ($image->storageConfiguration->driver === 'local' || $image->disabled_at) {
                    $files[] = [$image->disabled_at ? 'disabled' : 'public', $image->path, $image->bytes];
                } else {
                    try {
                        $head = app(StorageManager::class)->store($image->storageConfiguration)->head($image->storageConfiguration->objectKey($image->path));
                        if (! $head || $head['bytes'] !== (int) $image->bytes || ($image->content_sha256 && $head['sha256'] !== $image->content_sha256)) {
                            $this->error('云端对象缺失或校验不符 #'.$image->id);
                            $problems++;
                        }
                    } catch (\Throwable) {
                        $this->error('云端对象无法核对 #'.$image->id.'，请检查存储连接与权限。');
                        $problems++;
                    }
                }
                if ($image->original_path) {
                    $files[] = ['originals', $image->original_path, $image->original_bytes];
                }
                foreach ($files as [$area,$relative,$bytes]) {
                    $path = $area === 'public' ? $storage->publicPath($relative) : $storage->privatePath($area, $relative);
                    $expected[$path] = true;
                    if (! is_file($path)) {
                        $this->error('缺失 #'.$image->id.' '.$area.'/'.$relative);
                        $problems++;
                    } elseif ((int) $bytes > 0 && filesize($path) !== (int) $bytes) {
                        $this->error('体积不匹配 #'.$image->id.' '.$area.'/'.$relative);
                        $problems++;
                    }
                }
            }
        });
        foreach (DB::table('storage_operations')->get() as $operation) {
            $this->warn('未完成存储操作 #'.$operation->id.' '.$operation->state);
            $problems++;
            $payload = json_decode($operation->payload, true);
            foreach (['preview_path' => 'previews', 'original_path' => 'originals', 'disabled_path' => 'disabled'] as $field => $area) {
                if (! empty($payload[$field])) {
                    $expected[$storage->privatePath($area, $payload[$field])] = true;
                }
            }
            if (in_array($operation->operation, ['disable', 'enable', 'purge'], true)) {
                $expected[$storage->privatePath('disabled', $operation->path)] = true;
            }
        }
        foreach ([public_path('media'), storage_path('app/previews'), storage_path('app/originals'), storage_path('app/disabled')] as $root) {
            if (! is_dir($root)) {
                continue;
            }foreach (File::allFiles($root) as $file) {
                if (! isset($expected[$file->getPathname()])) {
                    $this->warn('孤立文件 '.$file->getPathname());
                    $problems++;
                }
            }
        }
        $this->info('已核对 '.$count.' 张图片；异常 '.$problems.' 项。仅检查，未修改文件。');

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
