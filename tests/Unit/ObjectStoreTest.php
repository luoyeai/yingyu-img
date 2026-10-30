<?php

namespace Tests\Unit;

use App\Models\StorageConfiguration;
use App\Services\OssObjectStore;
use App\Services\S3ObjectStore;
use App\Services\StorageEndpoint;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ObjectStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public static function unsafeAddresses(): array
    {
        return array_map(static fn (string $url): array => [$url], ['http://storage.example.com', 'https://127.0.0.1', 'https://10.0.0.1',
            'https://169.254.169.254', 'https://[::1]', 'https://user:pass@storage.example.com', 'https://storage.example.com/path',
            'https://storage.example.com?secret=1', 'https://storage.local', 'https://localhost', 'https://2130706433']);
    }

    #[DataProvider('unsafeAddresses')]
    public function test_configuration_address_rejects_non_public_or_ambiguous_urls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StorageEndpoint)->validate($url);
    }

    public function test_dns_results_are_all_checked_and_connection_is_pinned_with_no_proxy_or_redirect(): void
    {
        $endpoint = new class extends StorageEndpoint
        {
            public array $addresses = ['8.8.8.8', '1.1.1.1'];

            protected function resolveAddresses(string $host): array
            {
                return $this->addresses;
            }
        };
        foreach (['224.0.0.1', '240.0.0.1', '192.0.2.1', '100.64.0.1', '::ffff:127.0.0.1', 'ff02::1', '2001:db8::1'] as $address) {
            $this->assertFalse($endpoint->publicIp($address));
        }
        $options = $endpoint->curlOptions('https://storage.example.com:9443/object?signature=fixture');
        $this->assertSame(['storage.example.com:9443:8.8.8.8'], $options[CURLOPT_RESOLVE]);
        $client = $endpoint->client();
        $this->assertFalse($client->getConfig('allow_redirects'));
        $this->assertSame('', $client->getConfig('proxy'));
        $this->assertTrue($client->getConfig('verify'));
        $this->assertSame(['https'], $client->getConfig('protocols'));
        $endpoint->addresses = ['8.8.8.8', '127.0.0.1'];
        $this->expectException(InvalidArgumentException::class);
        $endpoint->curlOptions('https://storage.example.com/object');
    }

    public function test_s3_and_cos_operations_use_v4_virtual_host_and_disable_optional_checksum_trailers(): void
    {
        $history = [];
        $head = ['Content-Length' => '4', 'x-amz-meta-sha256' => hash('sha256', 'data')];
        $mock = new MockHandler([
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
            new Response(404, [], '<Error><Code>NoSuchObjectLockConfiguration</Code></Error>'),
            new Response(200, ['ETag' => '"8d777f385d3dfec8815d20f7496026dc"']),
            new Response(200, $head),
            new Response(200, [], '<CopyObjectResult><ETag>"8d777f385d3dfec8815d20f7496026dc"</ETag></CopyObjectResult>'),
            new Response(200, [], 'data'), new Response(204), new Response(404),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $endpoint = Mockery::mock(StorageEndpoint::class);
        $endpoint->shouldReceive('client')->andReturn(new Client(['handler' => $stack, 'http_errors' => false, 'allow_redirects' => false]));
        $configuration = new StorageConfiguration(['driver' => 'cos', 'region' => 'ap-guangzhou', 'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com', 'bucket' => 'photos-1250000000', 'path_style' => true]);
        $store = new S3ObjectStore($configuration, ['key' => 'fixture-key', 'secret' => 'fixture-secret'], $endpoint);
        $source = tempnam(sys_get_temp_dir(), 'vault-s3-');
        $download = $source.'-download';
        file_put_contents($source, 'data');
        try {
            $store->put('prefix/2099/01/a.webp', $source);
            $this->assertSame(['bytes' => 4, 'sha256' => hash('sha256', 'data')], $store->head('prefix/2099/01/a.webp'));
            $store->copy('prefix/2099/01/a.webp', 'prefix/2099/01/b.webp');
            $store->download('prefix/2099/01/a.webp', $download);
            $this->assertSame('data', file_get_contents($download));
            $store->delete('prefix/2099/01/a.webp');
            $this->assertCount(8, $history);
            foreach ($history as $item) {
                $request = $item['request'];
                $this->assertSame('photos-1250000000.cos.ap-guangzhou.myqcloud.com', $request->getUri()->getHost());
                $this->assertStringContainsString('AWS4-HMAC-SHA256', $request->getHeaderLine('Authorization'));
                $this->assertFalse($request->hasHeader('x-amz-trailer'));
            }
            $put = $history[2]['request'];
            $this->assertSame('image/webp', $put->getHeaderLine('Content-Type'));
            $this->assertSame('public, max-age=0, must-revalidate', $put->getHeaderLine('Cache-Control'));
        } finally {
            unlink($source);
            if (is_file($download)) {
                unlink($download);
            }
        }
    }

    public function test_endpoint_options_are_accepted_by_real_curl_transport_without_network_io(): void
    {
        $endpoint = new StorageEndpoint;
        $url = 'https://8.8.8.8/object';
        $options = $endpoint->boundedOptions([
            ...$endpoint->client()->getConfig(),
            'curl' => $endpoint->curlOptions($url),
        ]);
        $factory = new CurlFactory(0);
        try {
            $handle = $factory->create(new Request('GET', $url), $options);
            try {
                $this->assertInstanceOf(\CurlHandle::class, $handle->handle);
            } finally {
                $factory->release($handle);
            }
        } finally {
            $options['sink']->close();
        }
    }

    public function test_oss_uses_native_v4_signing_for_five_object_operations_and_safe_bucket_checks(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '<VersioningConfiguration/>'), new Response(404), new Response(200),
            new Response(200, ['Content-Length' => '4', 'x-oss-meta-sha256' => hash('sha256', 'data')]),
            new Response(200, [], '<CopyObjectResult><ETag>fixture</ETag></CopyObjectResult>'),
            new Response(200, [], 'data'), new Response(204), new Response(404),
        ]));
        $stack->push(Middleware::history($history));
        $endpoint = Mockery::mock(StorageEndpoint::class);
        $endpoint->shouldReceive('client')->andReturn(new Client(['handler' => $stack, 'http_errors' => false, 'allow_redirects' => false]));
        $configuration = new StorageConfiguration(['driver' => 'oss', 'region' => 'cn-hangzhou', 'endpoint' => 'https://oss-cn-hangzhou.aliyuncs.com', 'bucket' => 'photos']);
        $store = new OssObjectStore($configuration, ['key' => 'fixture-key', 'secret' => 'fixture-secret'], $endpoint);
        $source = tempnam(sys_get_temp_dir(), 'vault-oss-');
        $download = $source.'-download';
        file_put_contents($source, 'data');
        try {
            $store->put('prefix/a.webp', $source);
            $this->assertSame(['bytes' => 4, 'sha256' => hash('sha256', 'data')], $store->head('prefix/a.webp'));
            $store->copy('prefix/a.webp', 'prefix/b.webp');
            $store->download('prefix/a.webp', $download);
            $this->assertSame('data', file_get_contents($download));
            $store->delete('prefix/a.webp');
            foreach ($history as $item) {
                $this->assertSame('photos.oss-cn-hangzhou.aliyuncs.com', $item['request']->getUri()->getHost());
                $this->assertStringContainsString('OSS4-HMAC-SHA256 Credential=fixture-key/', $item['request']->getHeaderLine('Authorization'));
                $this->assertStringNotContainsString('fixture-secret', (string) $item['request']->getUri());
            }
            $this->assertSame('/photos/prefix/a.webp', $history[4]['request']->getHeaderLine('x-oss-copy-source'));
        } finally {
            unlink($source);
            if (is_file($download)) {
                unlink($download);
            }
        }
    }

    public function test_response_limits_reject_declared_and_streamed_excess_before_sink_growth(): void
    {
        $endpoint = new StorageEndpoint;
        $options = $endpoint->boundedOptions(['response_byte_limit' => 4]);
        $this->assertArrayNotHasKey('response_byte_limit', $options);
        $this->assertSame(4, $options['sink']->write('data'));
        try {
            $options['sink']->write('excess');
            $this->fail('chunked response exceeded limit');
        } catch (RuntimeException) {
            $this->assertSame(4, $options['sink']->getSize());
        }
        try {
            $options['on_headers'](new Response(200, ['Content-Length' => '5']));
            $this->fail('declared response exceeded limit');
        } catch (RuntimeException) {
            $this->assertSame(4, $options['sink']->getSize());
        }
        $options['sink']->close();
    }

    public function test_head_reports_large_object_length_without_treating_it_as_a_response_body(): void
    {
        $endpoint = new StorageEndpoint;
        $options = $endpoint->boundedOptions([], 'HEAD');
        $options['on_headers'](new Response(200, ['Content-Length' => '5242880']));
        $this->assertSame(0, $options['sink']->getSize());
        $options['sink']->close();
        $get = $endpoint->boundedOptions([]);
        try {
            $get['on_headers'](new Response(200, ['Content-Length' => '5242880']));
            $this->fail('GET must reject oversized body');
        } catch (RuntimeException) {
            $this->assertSame(0, $get['sink']->getSize());
        } finally {
            $get['sink']->close();
        }
    }

    public function test_versioned_bucket_is_rejected_before_any_object_write(): void
    {
        $endpoint = Mockery::mock(StorageEndpoint::class);
        $endpoint->shouldReceive('client')->andReturn(new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Status>Suspended</Status></VersioningConfiguration>'),
        ]))]));
        $configuration = new StorageConfiguration(['driver' => 's3', 'region' => 'us-east-1', 'endpoint' => 'https://s3.example.com', 'bucket' => 'photos']);
        $store = new S3ObjectStore($configuration, ['key' => 'fixture-key', 'secret' => 'fixture-secret'], $endpoint);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('版本控制');
        $store->assertSafeBucket();
    }
}
