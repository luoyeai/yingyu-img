<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\StorageConfiguration;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class StorageManager
{
    public function __construct(private StorageEndpoint $endpoint) {}

    public function target(?int $id = null): StorageConfiguration
    {
        $id ??= Setting::value('default_storage_id');
        $configuration = $id ? StorageConfiguration::find($id) : StorageConfiguration::where('driver', 'local')->first();
        if (! $configuration || ! $configuration->enabled) {
            throw new InvalidArgumentException('所选存储不可用，请重新选择存储目标。');
        }

        return $configuration;
    }

    public function store(StorageConfiguration $configuration): ObjectStore
    {
        try {
            $credentials = $configuration->credentials;
            if (! is_array($credentials) || empty($credentials['key']) || empty($credentials['secret'])) {
                throw new RuntimeException;
            }

            return match ($configuration->driver) {
                's3', 'cos' => new S3ObjectStore($configuration, $credentials, $this->endpoint),
                'oss' => new OssObjectStore($configuration, $credentials, $this->endpoint),
                default => throw new RuntimeException,
            };
        } catch (Throwable) {
            throw new UploadFailure('无法读取存储密钥或初始化存储，请检查本站 APP_KEY 与配置。', 'storage_configuration');
        }
    }

    /** @return array<string,mixed> */
    public function normalize(array $data): array
    {
        $driver = $data['driver'];
        $region = $data['region'];
        $endpoint = match ($driver) {
            'cos' => 'https://cos.'.$region.'.myqcloud.com',
            'oss' => 'https://oss-'.$region.'.aliyuncs.com',
            default => rtrim($data['endpoint'] ?? '', '/'),
        };
        $this->endpoint->validate($endpoint);
        $publicUrl = rtrim($data['public_url'], '/');
        $this->endpoint->validate($publicUrl);
        if ($driver === 'cos' && ! preg_match('/\A[a-z0-9][a-z0-9-]*-[0-9]{5,}\z/D', $data['bucket'])) {
            throw new InvalidArgumentException('COS 存储桶必须包含 APPID，例如 images-1250000000。');
        }

        return ['name' => $data['name'], 'driver' => $driver, 'region' => $region, 'endpoint' => $endpoint,
            'bucket' => $data['bucket'], 'prefix' => trim($data['prefix'] ?? '', '/'), 'public_url' => $publicUrl,
            'path_style' => $driver === 's3' && ! empty($data['path_style']), 'enabled' => true,
            'credentials' => ['key' => $data['access_key'], 'secret' => $data['secret_key']]];
    }
}
