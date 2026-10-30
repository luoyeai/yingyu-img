<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class StorageEndpoint
{
    public function validate(string $url, bool $base = true): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($base && (isset($parts['query']) || ! in_array($parts['path'] ?? '', ['', '/'], true)))) {
            throw new InvalidArgumentException('存储地址必须是无账号、参数和路径的 HTTPS 地址。');
        }
        $host = strtolower($parts['host']);
        if (! preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D', $host)
            || ! str_contains($host, '.') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')
            || str_contains($host, '..') || (filter_var($host, FILTER_VALIDATE_IP) && ! $this->publicIp($host))) {
            throw new InvalidArgumentException('存储地址必须使用有效的公网主机。');
        }

        return $parts;
    }

    public function publicIp(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false
            && ! str_starts_with(strtolower($address), '::ffff:')
            && ! str_starts_with(strtolower($address), 'ff')
            && ! preg_match('/\A(?:22[4-9]|23[0-9])\./', $address)
            && ! preg_match('/\A(?:0\.|100\.(?:6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.|192\.0\.0\.|198\.(?:18|19)\.)/', $address);
    }

    /** Resolve every address and pin the checked result for the actual connection. */
    public function curlOptions(string $url): array
    {
        $parts = $this->validate($url, false);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? 443;
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolveAddresses($host);
        if ($addresses === [] || array_any($addresses, fn (string $address): bool => ! $this->publicIp($address))) {
            throw new InvalidArgumentException('存储地址无法解析为允许的公网地址。');
        }
        $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];

        return [CURLOPT_RESOLVE => [$host.':'.$port.':'.$address]];
    }

    /** @return list<string> */
    protected function resolveAddresses(string $host): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            dns_get_record($host, DNS_A | DNS_AAAA) ?: [],
        ))));
    }

    /** Bound actual streamed bytes, including chunked and decoded responses, before writing the sink. */
    public function boundedOptions(array $options, string $method = 'GET'): array
    {
        $limit = $options['response_byte_limit'] ?? 1048576;
        unset($options['response_byte_limit']);
        if (! is_int($limit) || $limit < 1 || $limit > 134217728) {
            throw new InvalidArgumentException('远程响应大小上限无效。');
        }
        $sink = $options['sink'] ?? fopen('php://temp/maxmemory:1048576', 'w+b');
        $stream = Utils::streamFor(is_string($sink) ? Utils::tryFopen($sink, 'w+b') : $sink);
        $written = 0;
        $options['sink'] = FnStream::decorate($stream, [
            'write' => static function (string $data) use ($stream, $limit, &$written): int {
                if (strlen($data) > $limit - $written) {
                    throw new RuntimeException('远程响应超出允许大小。');
                }
                $bytes = $stream->write($data);
                $written += $bytes;

                return $bytes;
            },
        ]);
        $headers = $options['on_headers'] ?? null;
        $options['on_headers'] = static function (ResponseInterface $response) use ($limit, $headers, $method): void {
            if ($method !== 'HEAD' && (float) $response->getHeaderLine('Content-Length') > $limit) {
                throw new RuntimeException('远程响应超出允许大小。');
            }
            if ($headers) {
                $headers($response);
            }
        };

        return $options;
    }

    public function client(): Client
    {
        $curl = new CurlHandler;
        $handler = function (RequestInterface $request, array $options) use ($curl) {
            $options = $this->boundedOptions($options, $request->getMethod());
            $options['curl'] = $this->curlOptions((string) $request->getUri());
            $options['allow_redirects'] = false;
            $options['proxy'] = '';
            $options['verify'] = true;
            $options['protocols'] = ['https'];
            $options['timeout'] = 30;
            $options['connect_timeout'] = 5;
            $options['debug'] = false;

            return $curl($request, $options);
        };

        return new Client(['handler' => HandlerStack::create($handler), 'allow_redirects' => false, 'http_errors' => false,
            'timeout' => 30, 'connect_timeout' => 5, 'proxy' => '', 'verify' => true, 'protocols' => ['https']]);
    }
}
