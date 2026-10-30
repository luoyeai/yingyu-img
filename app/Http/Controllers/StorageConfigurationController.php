<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\StorageConfiguration;
use App\Services\RemoteImageStorage;
use App\Services\StorageEndpoint;
use App\Services\StorageManager;
use App\Services\StorageMutex;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class StorageConfigurationController extends Controller
{
    public function store(Request $request, StorageManager $manager, StorageMutex $mutex): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100', 'driver' => 'required|in:cos,oss,s3',
            'region' => ['required', 'string', 'max:80', 'regex:/\A[a-z][a-z0-9-]+\z/D'],
            'endpoint' => 'nullable|string|max:255', 'bucket' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9][a-z0-9-]{1,98}[a-z0-9]\z/D'],
            'prefix' => ['nullable', 'string', 'max:180', 'regex:~\A(?:[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*)?\z~D'],
            'public_url' => 'required|string|max:255', 'path_style' => 'nullable|boolean',
            'access_key' => ['required', 'string', 'max:255', 'regex:/\A[^\s\x00-\x1f\x7f]+\z/D'],
            'secret_key' => ['required', 'string', 'max:255', 'regex:/\A[^\s\x00-\x1f\x7f]+\z/D'],
            'bucket_confirmed' => 'accepted',
        ]);
        try {
            $normalized = $manager->normalize($data);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['storage' => $exception->getMessage()]);
        }
        $mutex->run(fn () => StorageConfiguration::create($normalized));

        return back()->with('success', '存储配置已创建，请测试读写与公开访问后再设为默认。');
    }

    public function update(Request $request, StorageConfiguration $configuration, StorageMutex $mutex): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'enabled' => 'required|boolean',
            'access_key' => 'nullable|string|max:255|regex:/\A[^\s\x00-\x1f\x7f]+\z/D',
            'secret_key' => 'nullable|string|max:255|regex:/\A[^\s\x00-\x1f\x7f]+\z/D']);
        if ($request->filled('access_key') !== $request->filled('secret_key')) {
            throw ValidationException::withMessages(['storage' => '轮换密钥时请同时填写 Access Key 与 Secret Key。']);
        }
        $mutex->run(function () use ($configuration, $data, $request): void {
            DB::transaction(function () use ($configuration, $data, $request): void {
                $configuration->refresh();
                $default = Setting::value('default_storage_id') ?? StorageConfiguration::where('driver', 'local')->value('id');
                if (! $data['enabled'] && (int) $default === $configuration->id) {
                    throw ValidationException::withMessages(['storage' => '请先选择其他默认存储，再停用此配置。']);
                }
                $configuration->fill(['name' => $data['name'], 'enabled' => $data['enabled']]);
                if ($configuration->driver !== 'local' && $request->filled('access_key')) {
                    $configuration->credentials = ['key' => $data['access_key'], 'secret' => $data['secret_key']];
                }
                $configuration->save();
            });
        });

        return back()->with('success', '存储配置已保存。停用只阻止新上传，现有图片仍按原配置管理。');
    }

    public function destroy(StorageConfiguration $configuration, StorageMutex $mutex): RedirectResponse
    {
        $mutex->run(function () use ($configuration): void {
            DB::transaction(function () use ($configuration): void {
                $configuration->refresh();
                if ($configuration->driver === 'local' || (int) Setting::value('default_storage_id') === $configuration->id
                    || $configuration->images()->exists() || DB::table('storage_operations')->where('storage_configuration_id', $configuration->id)->exists()) {
                    throw ValidationException::withMessages(['storage' => '本地、默认、被图片或未完成操作引用的存储配置不能删除。']);
                }
                $configuration->delete();
            });
        });

        return back()->with('success', '未被引用的存储配置已删除。');
    }

    public function setDefault(Request $request, StorageManager $manager, StorageMutex $mutex): RedirectResponse
    {
        $data = $request->validate(['storage_configuration_id' => 'required|integer']);
        try {
            $mutex->run(function () use ($data, $manager): void {
                DB::transaction(function () use ($data, $manager): void {
                    Setting::put('default_storage_id', $manager->target((int) $data['storage_configuration_id'])->id);
                });
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['storage' => $exception->getMessage()]);
        }

        return back()->with('success', '默认存储已更新，仅影响之后选择默认目标的新上传。');
    }

    public function test(StorageConfiguration $configuration, StorageManager $manager, RemoteImageStorage $remote, StorageMutex $mutex, StorageEndpoint $endpoint): RedirectResponse
    {
        if ($configuration->driver === 'local') {
            return back()->with('success', '本地存储请使用运行状态中的目录读写检测。');
        }
        try {
            $public = $mutex->run(function () use ($configuration, $manager, $remote, $endpoint): bool {
                $directory = storage_path('app/tmp');
                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                $source = $directory.'/storage-test-'.bin2hex(random_bytes(16));
                $download = $source.'-download';
                $path = '_checks/'.bin2hex(random_bytes(20)).'.webp';
                try {
                    $image = new \Imagick;
                    $image->newImage(1, 1, 'transparent', 'webp');
                    $image->writeImage($source);
                    $image->clear();
                    chmod($source, 0600);
                    $store = $manager->store($configuration);
                    $store->assertSafeBucket();
                    $id = $remote->begin($configuration, 'test_cleanup', $path, null, []);
                    $store->put($configuration->objectKey($path), $source);
                    $store->download($configuration->objectKey($path), $download, filesize($source));
                    $remote->verifyFile($download, filesize($source), hash_file('sha256', $source));
                    $public = false;
                    try {
                        $response = $endpoint->client()->get($configuration->publicUrl($path), ['response_byte_limit' => filesize($source)]);
                        $public = $response->getStatusCode() === 200 && hash_equals(hash_file('sha256', $source), hash('sha256', (string) $response->getBody()));
                    } catch (Throwable) {
                        $public = false;
                    }
                    $remote->execute($id);

                    return $public;
                } finally {
                    foreach ([$source, $download] as $file) {
                        if (is_file($file)) {
                            unlink($file);
                        }
                    }
                }
            });
        } catch (Throwable) {
            return back()->withErrors(['storage' => '测试未完成，请检查连接与权限。可能残留的测试对象已登记，可重试清理。']);
        }

        return back()->with('success', $public ? '随机路径写入、读取、删除与匿名公开访问均通过。' : '随机路径写入、读取和删除通过；匿名公开访问未通过，请检查公开域名、桶策略或 CDN。');
    }

    public function retry(RemoteImageStorage $remote, StorageMutex $mutex): RedirectResponse
    {
        $result = $mutex->run(function () use ($remote): array {
            DB::table('storage_operations')->update(['next_attempt_at' => null]);

            return $remote->retryPending();
        });

        return back()->with('success', '存储操作完成 '.$result['completed'].' 项，仍待重试 '.$result['failed'].' 项。');
    }
}
