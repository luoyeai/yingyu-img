<?php

namespace App\Services\Updates;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

class ReleaseChecker
{
    public const RELEASES_URL = 'https://github.com/luoyeai/yingyu-img/releases';

    public const API_URL = 'https://api.github.com/repos/luoyeai/yingyu-img/releases/latest';

    private const CACHE_KEY = 'yingyu-official-stable-release-v1';

    public function __construct(private HttpFactory $http, private CacheFactory $cache) {}

    /** @return array{status:string,message:string,version:?string,url:string,checked_at:?int} */
    public function check(bool $manual = false): array
    {
        try {
            $cache = $this->cache->store('file');
            $cached = $cache->get(self::CACHE_KEY);
            if (! $manual && is_array($cached)) {
                return $cached;
            }
            if (! $cache->add(self::CACHE_KEY.'-request', true, 300)) {
                return is_array($cached)
                    ? [...$cached, 'message' => '检测请求过于频繁，请稍后重试。'.$cached['message']]
                    : $this->result('limited', '检测正在进行或请求过于频繁，请稍后重试。');
            }
            $result = $this->fetch();
            $cache->put(self::CACHE_KEY, $result, 86400);

            return $result;
        } catch (Throwable) {
            return $this->result('unavailable', '版本检测暂不可用，请查看官方发行页。');
        }
    }

    /** @return array{status:string,message:string,version:?string,url:string,checked_at:?int} */
    private function fetch(): array
    {
        try {
            $response = $this->http->acceptJson()
                ->withHeaders(['User-Agent' => 'Yingyu-Img-Release-Check', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->withOptions(['verify' => true, 'allow_redirects' => false, 'proxy' => ''])
                ->connectTimeout(3)->timeout(5)->get(self::API_URL);
            if (in_array($response->status(), [403, 429], true)) {
                return $this->result('limited', '官方接口暂时限流，请稍后重试或查看发行页。');
            }
            if ($response->status() === 404) {
                return $this->result('empty', '暂未找到公开的稳定版本，请查看官方发行页。');
            }
            if (! $response->successful() || strlen($response->body()) > 1048576) {
                return $this->result('unavailable', '版本检测未完成，请查看官方发行页。');
            }
            $release = $response->json();
            $tag = is_array($release) ? ($release['tag_name'] ?? null) : null;
            if (! is_array($release) || ($release['draft'] ?? null) !== false || ($release['prerelease'] ?? null) !== false
                || ! is_string($tag) || ! preg_match('/\Av?((?:0|[1-9][0-9]{0,5})\.(?:0|[1-9][0-9]{0,5})\.(?:0|[1-9][0-9]{0,5}))\z/D', $tag, $matches)
                || ($release['html_url'] ?? null) !== self::RELEASES_URL.'/tag/'.$tag) {
                return $this->result('invalid', '官方版本信息格式异常，请查看发行页。');
            }

            return [...$this->result('available', '已获取最新稳定发行版本；无法确认当前版本/基线，请查看发行页。'),
                'version' => $matches[1], 'url' => self::RELEASES_URL.'/tag/'.$tag];
        } catch (Throwable) {
            return $this->result('unavailable', '网络连接失败或超时，请稍后重试或查看官方发行页。');
        }
    }

    /** @return array{status:string,message:string,version:?string,url:string,checked_at:?int} */
    private function result(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message, 'version' => null,
            'url' => self::RELEASES_URL, 'checked_at' => time()];
    }
}
