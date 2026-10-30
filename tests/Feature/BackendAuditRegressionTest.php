<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\Setting;
use App\Models\User;
use App\Services\ImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BackendAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    private string $originalPublic;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/vault-backend-audit-'.bin2hex(random_bytes(8));
        $this->originalPublic = public_path();
        $this->originalStorage = storage_path();
        File::ensureDirectoryExists($this->sandbox.'/public/media');
        File::ensureDirectoryExists($this->sandbox.'/storage/app');
        $this->app->usePublicPath($this->sandbox.'/public');
        $this->app->useStoragePath($this->sandbox.'/storage');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Image::flushEventListeners();
        if (isset($this->sandbox)) {
            $this->app->usePublicPath($this->originalPublic);
            $this->app->useStoragePath($this->originalStorage);
            File::deleteDirectory($this->sandbox);
        }
        parent::tearDown();
    }

    public function test_dedupe_prefers_available_copy_over_earlier_disabled_image(): void
    {
        $storage = app(ImageStorage::class);
        $image = $storage->upload($this->png(), Setting::uploadOptions())['image'];
        $copy = $storage->duplicate($image);
        $storage->disable($image);

        $this->postJson('/upload', ['file' => $this->png(), 'preset' => 'standard'])
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('trashed', false)
            ->assertJsonPath('image.id', $copy->id)->assertJsonPath('image.disabled_at', null);
        $this->assertDatabaseCount('images', 2);
        $this->assertNotNull($image->fresh()->disabled_at);
        $this->assertFileExists(public_path('media/'.$copy->path));
    }

    public static function localCleanupActions(): array
    {
        return [['original'], ['purge']];
    }

    #[DataProvider('localCleanupActions')]
    public function test_local_cleanup_permission_failure_keeps_committed_intent_until_retry(string $action): void
    {
        $storage = app(ImageStorage::class);
        $image = $storage->upload($this->png(), [...Setting::uploadOptions(), 'keep_original' => true])['image'];
        $original = storage_path('app/originals/'.$image->original_path);
        $public = public_path('media/'.$image->path);
        $preview = storage_path('app/previews/'.$image->preview_path);
        $directory = dirname($action === 'original' ? $original : $public);
        if ($action === 'purge') {
            $image->delete();
            Image::forceDeleted(static function () use ($directory): void {
                chmod($directory, 0500);
            });
        } else {
            Image::updated(static function () use ($directory): void {
                chmod($directory, 0500);
            });
        }

        try {
            try {
                $action === 'purge' ? $storage->purge($image) : $storage->deleteOriginal($image);
                $this->fail('File cleanup should fail while the containing directory is read-only.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('本地文件清理尚未完成', $exception->getMessage());
                $this->assertDatabaseHas('storage_operations', ['image_id' => $image->id, 'state' => 'committed']);
                $this->assertFileExists($original);
                $this->assertFileExists($public);
                if ($action === 'purge') {
                    $this->assertDatabaseMissing('images', ['id' => $image->id]);
                } else {
                    $this->assertNull($image->fresh()->original_path);
                }
            }
        } finally {
            chmod($directory, 0700);
            Image::flushEventListeners();
        }

        $this->post('/settings/storage/retry')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('storage_operations', 0);
        $this->assertFileDoesNotExist($original);
        if ($action === 'purge') {
            $this->assertFileDoesNotExist($public);
            $this->assertFileDoesNotExist($preview);
        } else {
            $this->assertFileExists($public);
            $this->assertFileExists($preview);
        }
    }

    private function png(): UploadedFile
    {
        $image = new \Imagick;
        $image->newImage(32, 24, 'red', 'png');
        $blob = $image->getImageBlob();
        $image->clear();

        return UploadedFile::fake()->createWithContent('fixture.png', $blob);
    }
}
