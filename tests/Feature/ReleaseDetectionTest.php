<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Updates\ReleaseChecker;
use App\Services\Updates\UpdateState;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReleaseDetectionTest extends TestCase
{
    use RefreshDatabase;

    private ?string $releaseCacheDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->releaseCacheDirectory = sys_get_temp_dir().'/yingyu-release-test-'.bin2hex(random_bytes(8));
        config(['cache.stores.file.path' => $this->releaseCacheDirectory]);
        Cache::purge('file');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->releaseCacheDirectory !== null) {
            Cache::purge('file');
            (new Filesystem)->deleteDirectory($this->releaseCacheDirectory);
        }
        parent::tearDown();
    }

    public function test_guest_cannot_check_releases_or_trigger_an_external_request(): void
    {
        Http::fake();
        $this->get('/updates')->assertRedirect('/login');
        $this->post('/updates/check-release')->assertRedirect('/login');
        Http::assertNothingSent();
    }

    public function test_stable_release_is_shown_without_creating_an_installation_version_and_cached(): void
    {
        Http::fake([ReleaseChecker::API_URL => Http::response([
            'tag_name' => 'v1.2.0', 'draft' => false, 'prerelease' => false,
            'html_url' => ReleaseChecker::RELEASES_URL.'/tag/v1.2.0',
        ])]);
        $this->actingAs(User::factory()->create());
        $this->get('/updates')->assertOk()->assertSee('1.2.0')
            ->assertSee('无法确认当前版本/基线')->assertSee(ReleaseChecker::RELEASES_URL.'/tag/v1.2.0');
        $this->get('/updates')->assertOk();
        Http::assertSentCount(1);
        $this->assertNull(app(UpdateState::class)->read('version.json'));
        $this->assertNull(app(UpdateState::class)->read('journal.json'));
    }

    public function test_manual_check_handles_upstream_failure_and_retains_the_manual_release_link(): void
    {
        Http::fake([ReleaseChecker::API_URL => Http::response([], 503)]);
        $this->actingAs(User::factory()->create());
        $this->post('/updates/check-release')->assertRedirect('/updates')->assertSessionHas('release_check');
        $this->get('/updates')->assertOk()->assertSee('版本检测未完成')
            ->assertSee(ReleaseChecker::RELEASES_URL)->assertDontSee('已是最新版');
        Http::assertSentCount(1);
    }
}
