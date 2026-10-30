<?php

namespace App\Services;

use App\Models\StorageConfiguration;
use GuzzleHttp\Client;
use OSS\Credentials\Credentials;
use OSS\Http\RequestCore;
use OSS\Signer\SignerV4;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/** Native OSS V4 signer with the shared DNS-pinned transport; SDK's internal redirects are never used. */
class OssObjectStore implements ObjectStore
{
    private Client $client;

    private bool $bucketChecked = false;

    public function __construct(private StorageConfiguration $configuration, private array $credentials, StorageEndpoint $endpoint)
    {
        $this->client = $endpoint->client();
    }

    public function assertSafeBucket(): void
    {
        if ($this->bucketChecked) {
            return;
        }
        $response = $this->request('GET', '', [], ['query' => 'versioning']);
        $xml = $this->xml((string) $response->getBody());
        if ((string) $xml->Status !== '') {
            throw new RuntimeException('第一阶段不支持启用过版本控制的 OSS 存储桶。');
        }
        $worm = $this->request('GET', '', [], ['query' => 'worm'], true);
        if ($worm->getStatusCode() !== 404) {
            throw new RuntimeException('第一阶段不支持 OSS 保留策略，或无法确认策略状态。');
        }
        $this->bucketChecked = true;
    }

    public function put(string $key, string $source): void
    {
        $this->assertSafeBucket();
        $stream = fopen($source, 'rb');
        try {
            $this->request('PUT', $key, ['Content-Type' => 'image/webp', 'Content-Length' => (string) filesize($source),
                'Cache-Control' => 'public, max-age=0, must-revalidate', 'x-oss-meta-sha256' => hash_file('sha256', $source)], ['body' => $stream]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function download(string $key, string $destination, int $maxBytes = 134217728): void
    {
        $this->request('GET', $key, [], ['sink' => $destination, 'response_byte_limit' => $maxBytes]);
    }

    public function copy(string $source, string $destination): void
    {
        $this->assertSafeBucket();
        $response = $this->request('PUT', $destination, ['x-oss-copy-source' => '/'.$this->configuration->bucket.'/'.str_replace('%2F', '/', rawurlencode($source))]);
        if ($this->xml((string) $response->getBody())->getName() !== 'CopyObjectResult') {
            throw new RuntimeException('OSS 复制结果未确认。');
        }
    }

    public function head(string $key): ?array
    {
        $response = $this->request('HEAD', $key, [], [], true);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->hasHeader('x-oss-version-id') && $response->getHeaderLine('x-oss-version-id') !== 'null') {
            throw new RuntimeException('检测到 OSS 对象版本，已阻止处理。');
        }

        return ['bytes' => (int) $response->getHeaderLine('Content-Length'), 'sha256' => $response->getHeaderLine('x-oss-meta-sha256') ?: null];
    }

    public function delete(string $key): void
    {
        $this->assertSafeBucket();
        $response = $this->request('DELETE', $key, [], [], true);
        if ($response->hasHeader('x-oss-delete-marker') || $response->hasHeader('x-oss-version-id') || $this->head($key) !== null) {
            throw new RuntimeException('OSS 删除未确认，保留记录等待重试。');
        }
    }

    private function request(string $method, string $key, array $headers = [], array $options = [], bool $allowMissing = false): ResponseInterface
    {
        try {
            $host = $this->configuration->bucket.'.'.parse_url($this->configuration->endpoint, PHP_URL_HOST);
            $url = 'https://'.$host.'/'.str_replace('%2F', '/', rawurlencode($key));
            if (isset($options['query'])) {
                $url .= '?'.$options['query'];
                unset($options['query']);
            }
            $request = new RequestCore($url);
            $request->set_method($method);
            foreach ($headers as $name => $value) {
                $request->add_header($name, $value);
            }
            $signing = ['bucket' => $this->configuration->bucket, 'key' => $key, 'region' => $this->configuration->region, 'product' => 'oss'];
            (new SignerV4)->sign($request, new Credentials($this->credentials['key'], $this->credentials['secret']), $signing);
            $response = $this->client->request($method, $url, ['headers' => $request->request_headers, ...$options]);
            if (($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) && ! ($allowMissing && $response->getStatusCode() === 404)) {
                throw new RuntimeException;
            }

            return $response;
        } catch (Throwable) {
            throw new RuntimeException('OSS 操作失败，请检查连接、存储桶与访问权限。');
        }
    }

    private function xml(string $body): \SimpleXMLElement
    {
        if (str_contains(strtoupper($body), '<!DOCTYPE') || str_contains(strtoupper($body), '<!ENTITY')) {
            throw new RuntimeException('OSS 返回了无效的响应。');
        }
        $xml = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException('OSS 返回了无效的响应。');
        }

        return $xml;
    }
}
