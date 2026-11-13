<?php

namespace Tests\Unit;

use App\Services\Updates\ReleaseChecker;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReleaseCheckerTest extends TestCase
{
    private HttpFactory $http;

    private Repository $cache;

    private ReleaseChecker $checker;

    protected function setUp(): void
    {
        $this->http = new HttpFactory;
        $this->http->preventStrayRequests();
        $this->cache = new Repository(new ArrayStore);
        $factory = $this->createMock(CacheFactory::class);
        $factory->method('store')->with('file')->willReturn($this->cache);
        $this->checker = new ReleaseChecker($this->http, $factory);
    }

    private function release(string $tag = 'v1.0.0'): array
    {
        return ['tag_name' => $tag, 'draft' => false, 'prerelease' => false,
            'html_url' => ReleaseChecker::RELEASES_URL.'/tag/'.$tag];
    }

    public function test_stable_release_is_cached_without_claiming_internal_version_compatibility(): void
    {
        $this->http->fake([ReleaseChecker::API_URL => $this->http->response($this->release())]);
        $result = $this->checker->check();
        $this->assertSame('available', $result['status']);
        $this->assertSame('1.0.0', $result['version']);
        $this->assertStringContainsString('无法确认当前版本/基线', $result['message']);
        $this->assertSame($result, $this->checker->check());
        $this->checker->check(manual: true);
        $this->http->assertSentCount(1);
        $this->http->assertSent(fn ($request): bool => $request->url() === ReleaseChecker::API_URL
            && ! $request->hasHeader('Authorization'));
    }

    public function test_manual_check_can_refresh_after_cooldown_but_auto_check_keeps_daily_cache(): void
    {
        $this->http->fakeSequence()->push($this->release('1.0.0'))->push($this->release('v1.1.0'));
        $this->checker->check();
        $this->cache->forget('yingyu-official-stable-release-v1-request');
        $this->assertSame('1.0.0', $this->checker->check()['version']);
        $this->assertSame('1.1.0', $this->checker->check(manual: true)['version']);
        $this->http->assertSentCount(2);
    }

    #[DataProvider('invalidReleases')]
    public function test_untrusted_release_metadata_is_rejected(mixed $release): void
    {
        $this->http->fake([ReleaseChecker::API_URL => $this->http->response($release)]);
        $result = $this->checker->check();
        $this->assertSame('invalid', $result['status']);
        $this->assertNull($result['version']);
        $this->assertSame(ReleaseChecker::RELEASES_URL, $result['url']);
    }

    public static function invalidReleases(): array
    {
        $valid = ['tag_name' => 'v1.0.0', 'draft' => false, 'prerelease' => false,
            'html_url' => ReleaseChecker::RELEASES_URL.'/tag/v1.0.0'];

        return [
            'draft' => [[...$valid, 'draft' => true]],
            'prerelease' => [[...$valid, 'prerelease' => true]],
            'missing flags' => [['tag_name' => 'v1.0.0']],
            'preview tag' => [[...$valid, 'tag_name' => 'v1.0.0-rc1']],
            'overflow' => [[...$valid, 'tag_name' => '99999999999999999999.0.0']],
            'leading zeros' => [[...$valid, 'tag_name' => 'v01.0.0']],
            'html' => [[...$valid, 'tag_name' => '<script>alert(1)</script>']],
            'different repository' => [[...$valid, 'html_url' => 'https://github.com/other/repo/releases/tag/v1.0.0']],
            'query injection' => [[...$valid, 'html_url' => $valid['html_url'].'?redirect=evil']],
            'not an object' => ['invalid'],
        ];
    }

    #[DataProvider('failureStatuses')]
    public function test_http_failures_are_cached_and_keep_official_link(int $status, string $expected): void
    {
        $this->http->fake([ReleaseChecker::API_URL => $this->http->response([], $status)]);
        $result = $this->checker->check();
        $this->assertSame($expected, $result['status']);
        $this->assertSame(ReleaseChecker::RELEASES_URL, $result['url']);
        $this->assertSame($result, $this->checker->check());
        $this->http->assertSentCount(1);
    }

    public static function failureStatuses(): array
    {
        return [[403, 'limited'], [429, 'limited'], [404, 'empty'], [500, 'unavailable'], [302, 'unavailable']];
    }

    public function test_network_errors_and_bad_json_never_escape(): void
    {
        $this->http->fake(fn () => throw new RuntimeException('private connection details'));
        $result = $this->checker->check();
        $this->assertSame('unavailable', $result['status']);
        $this->assertStringNotContainsString('private', $result['message']);
        $this->assertSame($result, $this->checker->check());
    }

    public function test_invalid_json_is_rejected(): void
    {
        $this->http->fake([ReleaseChecker::API_URL => $this->http->response('{invalid')]);
        $this->assertSame('invalid', $this->checker->check()['status']);
    }

    public function test_request_keeps_tls_validation_and_disables_redirects_and_proxies(): void
    {
        $this->http->fake(function ($request, array $options) {
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertSame(5, $options['timeout']);

            return $this->http->response($this->release());
        });
        $this->assertSame('available', $this->checker->check()['status']);
    }

    public function test_oversized_response_is_rejected(): void
    {
        $this->http->fake([ReleaseChecker::API_URL => $this->http->response(str_repeat('x', 1048577))]);
        $this->assertSame('unavailable', $this->checker->check()['status']);
    }

    public function test_cache_failure_prevents_outbound_requests(): void
    {
        $cache = $this->createMock(CacheFactory::class);
        $cache->method('store')->willThrowException(new RuntimeException('private path'));
        $this->assertSame('unavailable', (new ReleaseChecker($this->http, $cache))->check()['status']);
        $this->http->assertNothingSent();
    }
}
