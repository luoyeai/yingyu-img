<?php

namespace App\Services;

use App\Models\StorageConfiguration;
use Aws\Exception\AwsException;
use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\Result;
use Aws\S3\S3Client;
use RuntimeException;
use Throwable;

/** AWS S3 and Tencent's documented S3 interface; COS always uses its regional virtual host. */
class S3ObjectStore implements ObjectStore
{
    private S3Client $client;

    private bool $bucketChecked = false;

    public function __construct(private StorageConfiguration $configuration, array $credentials, StorageEndpoint $endpoint)
    {
        $this->client = new S3Client([
            'version' => '2006-03-01', 'region' => $configuration->region,
            'endpoint' => $configuration->endpoint, 'signature_version' => 'v4',
            'use_path_style_endpoint' => $configuration->driver === 'cos' ? false : $configuration->path_style,
            'credentials' => ['key' => $credentials['key'], 'secret' => $credentials['secret']],
            'http_handler' => new GuzzleHandler($endpoint->client()), 'http' => ['http_errors' => true, 'allow_redirects' => false], 'retries' => 0,
            'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
        ]);
    }

    public function assertSafeBucket(): void
    {
        if ($this->bucketChecked) {
            return;
        }
        $versioning = $this->call('GetBucketVersioning');
        if (! empty($versioning['Status'])) {
            throw new RuntimeException('第一阶段不支持启用过版本控制的存储桶。');
        }
        try {
            $lock = $this->client->getObjectLockConfiguration(['Bucket' => $this->configuration->bucket]);
            if (($lock['@metadata']['statusCode'] ?? 0) !== 200) {
                throw new RuntimeException('无法确认存储桶对象锁状态。');
            }
            if (! empty($lock['ObjectLockConfiguration']['ObjectLockEnabled'])) {
                throw new RuntimeException('第一阶段不支持对象锁或保留策略。');
            }
        } catch (AwsException $exception) {
            if (! in_array($exception->getAwsErrorCode(), ['ObjectLockConfigurationNotFoundError', 'NoSuchObjectLockConfiguration', 'NotImplemented'], true)) {
                throw new RuntimeException('无法确认存储桶未启用对象锁，请检查查询权限。');
            }
        }
        $this->bucketChecked = true;
    }

    public function put(string $key, string $source): void
    {
        $this->assertSafeBucket();
        $this->call('PutObject', ['Key' => $key, 'SourceFile' => $source, 'ContentType' => 'image/webp',
            'CacheControl' => 'public, max-age=0, must-revalidate', 'Metadata' => ['sha256' => hash_file('sha256', $source)]]);
    }

    public function download(string $key, string $destination, int $maxBytes = 134217728): void
    {
        $this->call('GetObject', ['Key' => $key, 'SaveAs' => $destination, '@http' => ['response_byte_limit' => $maxBytes]]);
    }

    public function copy(string $source, string $destination): void
    {
        $this->assertSafeBucket();
        $this->call('CopyObject', ['Key' => $destination, 'CopySource' => $this->configuration->bucket.'/'.str_replace('%2F', '/', rawurlencode($source)), 'MetadataDirective' => 'COPY']);
    }

    public function head(string $key): ?array
    {
        try {
            $result = $this->client->headObject(['Bucket' => $this->configuration->bucket, 'Key' => $key]);
            if (($result['@metadata']['statusCode'] ?? 0) !== 200) {
                throw new RuntimeException('对象读取状态无效。');
            }
            if (! empty($result['VersionId']) && $result['VersionId'] !== 'null') {
                throw new RuntimeException('检测到对象版本，已阻止处理。');
            }

            return ['bytes' => (int) $result['ContentLength'], 'sha256' => $result['Metadata']['sha256'] ?? null];
        } catch (AwsException $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }
            throw new RuntimeException('对象存储读取失败，请检查连接和权限。');
        } catch (Throwable) {
            throw new RuntimeException('对象存储读取失败或对象版本不受支持。');
        }
    }

    public function delete(string $key): void
    {
        $this->assertSafeBucket();
        $result = $this->call('DeleteObject', ['Key' => $key]);
        if (! empty($result['DeleteMarker']) || ! empty($result['VersionId']) || $this->head($key) !== null) {
            throw new RuntimeException('远程删除未确认，保留记录等待重试。');
        }
    }

    private function call(string $operation, array $arguments = []): Result
    {
        try {
            $result = $this->client->execute($this->client->getCommand($operation, ['Bucket' => $this->configuration->bucket, ...$arguments]));
            $status = $result['@metadata']['statusCode'] ?? 0;
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException;
            }

            return $result;
        } catch (Throwable) {
            throw new RuntimeException('对象存储操作失败，请检查连接、存储桶与访问权限。');
        }
    }
}
