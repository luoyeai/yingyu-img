<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\Setting;
use App\Models\User;
use App\Services\ImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VaultWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    private string $originalPublic;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('image_vault_test', config('database.connections.mysql.database'));
        $this->sandbox = sys_get_temp_dir().'/vault-test-'.bin2hex(random_bytes(8));
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
        if (isset($this->originalPublic)) {
            $this->app->usePublicPath($this->originalPublic);
            $this->app->useStoragePath($this->originalStorage);
            File::deleteDirectory($this->sandbox);
        }
        parent::tearDown();
    }

    private function png(string $name = 'alpha.png'): UploadedFile
    {
        $im = new \Imagick;
        $im->newImage(320, 200, new \ImagickPixel('transparent'));
        $im->setImageFormat('png');
        $im->setImagePixelColor(50, 50, new \ImagickPixel('red'));
        $blob = $im->getImageBlob();
        $im->clear();

        return UploadedFile::fake()->createWithContent($name, $blob);
    }

    public function test_upload_dedupe_recycle_restore_disable_enable_and_permanent_deletion(): void
    {
        $response = $this->postJson('/upload', ['file' => $this->png(), 'preset' => 'standard'])->assertOk();
        $id = $response->json('image.id');
        $image = Image::findOrFail($id);
        $path = public_path('media/'.$image->path);
        $this->assertFileExists($path);
        $this->assertMatchesRegularExpression('~^\d{4}/\d{2}/[a-f0-9]{32}\.webp$~', $image->path);
        $this->assertNull($image->original_path);
        $this->postJson('/upload', ['file' => $this->png(), 'preset' => 'standard'])->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('image.id', $id);
        $this->assertSame(1, Image::count());
        $this->post('/images/batch', ['ids' => [$id], 'action' => 'trash'])->assertRedirect();
        $this->assertFileExists($path);
        $this->postJson('/upload', ['file' => $this->png(), 'preset' => 'standard'])->assertOk()->assertJsonPath('trashed', true);
        $this->post('/images/batch', ['ids' => [$id], 'action' => 'restore'])->assertRedirect();
        $this->post('/images/batch', ['ids' => [$id], 'action' => 'disable'])->assertRedirect();
        $this->assertFileDoesNotExist($path);
        $this->assertFileExists(storage_path('app/disabled/'.$image->path));
        $this->post('/images/batch', ['ids' => [$id], 'action' => 'enable'])->assertRedirect();
        $this->assertFileExists($path);
        $this->patch('/images/'.$id, ['display_name' => '新的名字', 'alt' => '"<test>'])->assertRedirect();
        $this->assertSame($image->url, $image->fresh()->url);
        $this->post('/images/batch', ['ids' => [$id], 'action' => 'trash'])->assertRedirect();
        $this->post('/images/batch', ['ids' => [$id], 'action' => 'purge'])->assertSessionHasErrors('confirmation');
        $this->assertFileExists($path);
        $this->post('/images/batch', ['ids' => [$id], 'action' => 'purge', 'confirmation' => '永久删除'])->assertRedirect();
        $this->assertDatabaseMissing('images', ['id' => $id]);
        $this->assertFileDoesNotExist($path);
    }

    public function test_private_original_future_setting_and_independent_copy(): void
    {
        Setting::put('upload', ['keep_original' => true]);
        $id = $this->postJson('/upload', ['file' => $this->png('keep.png'), 'preset' => 'custom', 'quality' => 85, 'lossless' => 1, 'max_edge' => 100])->assertOk()->json('image.id');
        $i = Image::findOrFail($id);
        $this->assertSame(100, $i->width);
        $this->assertFileExists(storage_path('app/originals/'.$i->original_path));
        $this->get('/images/'.$id.'/original')->assertOk();
        $copy = app(ImageStorage::class)->duplicate($i, null);
        $this->assertNotSame($i->path, $copy->path);
        $i->delete();
        app(ImageStorage::class)->purge($i);
        $this->assertFileExists(public_path('media/'.$copy->path));
        $this->post('/settings/clean-originals', ['confirmation' => 'bad'])->assertSessionHasErrors('confirmation');
        $this->post('/settings/clean-originals', ['confirmation' => '清理所有历史原图'])->assertRedirect();
        $this->assertNull($copy->fresh()->original_path);
        $this->assertFileExists(public_path('media/'.$copy->path));
    }

    public function test_failure_compensates_new_upload_and_disable_and_original_cleanup(): void
    {
        $storage = app(ImageStorage::class);
        Image::creating(function () {
            throw new \RuntimeException('injected database failure');
        });
        try {
            $storage->upload($this->png(), Setting::uploadOptions());
            $this->fail('must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected database failure', $e->getMessage());
        }Image::flushEventListeners();
        $this->assertCount(0, File::allFiles(public_path('media')));
        $i = $storage->upload($this->png(), [...Setting::uploadOptions(), 'keep_original' => true])['image'];
        $public = public_path('media/'.$i->path);
        $original = storage_path('app/originals/'.$i->original_path);
        Image::updating(function () {
            throw new \RuntimeException('injected database failure');
        });
        foreach (['disable', 'deleteOriginal'] as $method) {
            try {
                $storage->$method($i);
                $this->fail('must fail');
            } catch (\RuntimeException $e) {
                $this->assertSame('injected database failure', $e->getMessage());
            }$this->assertFileExists($public);
            $this->assertFileExists($original);
        }Image::flushEventListeners();
    }

    public function test_rejects_svg_gif_pixel_limit_and_cleans_thirty_day_trash(): void
    {
        $this->postJson('/upload', ['file' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'), 'preset' => 'standard'])->assertUnprocessable();
        $this->postJson('/upload', ['file' => UploadedFile::fake()->createWithContent('x.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==')), 'preset' => 'standard'])->assertUnprocessable();
        $i = app(ImageStorage::class)->upload($this->png(), Setting::uploadOptions())['image'];
        $i->delete();
        $i->forceFill(['deleted_at' => now()->subDays(31)])->save();
        $this->artisan('vault:cleanup')->assertSuccessful();
        $this->assertDatabaseMissing('images', ['id' => $i->id]);
        $this->assertNotNull(Setting::value('cron_last_run'));
    }

    public function test_batch_validates_all_item_states_before_mutating_any(): void
    {
        $storage = app(ImageStorage::class);
        $first = $storage->upload($this->png(), Setting::uploadOptions())['image'];
        $second = $storage->duplicate($first, null);
        $first->delete();
        $this->post('/images/batch', ['ids' => [$first->id, $second->id], 'action' => 'purge', 'confirmation' => '永久删除'])->assertSessionHasErrors('ids');
        $this->assertDatabaseHas('images', ['id' => $first->id]);
        $this->assertDatabaseHas('images', ['id' => $second->id]);
    }

    public function test_purge_database_failure_restores_all_files(): void
    {
        $storage = app(ImageStorage::class);
        $image = $storage->upload($this->png(), [...Setting::uploadOptions(), 'keep_original' => true])['image'];
        $image->delete();
        $public = public_path('media/'.$image->path);
        $original = storage_path('app/originals/'.$image->original_path);
        Image::forceDeleting(function () {
            throw new \RuntimeException('injected purge failure');
        });
        try {
            $storage->purge($image);
            $this->fail('must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected purge failure', $e->getMessage());
        }Image::flushEventListeners();
        $this->assertFileExists($public);
        $this->assertFileExists($original);
        $this->assertDatabaseHas('images', ['id' => $image->id]);
    }

    public function test_stale_cleanup_selection_cannot_purge_restored_or_recently_retrashed_image(): void
    {
        $storage = app(ImageStorage::class);
        $image = $storage->upload($this->png(), Setting::uploadOptions())['image'];
        $image->delete();
        $image->forceFill(['deleted_at' => now()->subDays(31)])->save();
        $stale = $image->fresh();
        $image->restore();
        $this->assertFalse($storage->purge($stale, now()->subDays(30)));
        $this->assertFileExists(public_path('media/'.$image->path));
        $image->delete();
        $this->assertFalse($storage->purge($stale, now()->subDays(30)));
        $this->assertDatabaseHas('images', ['id' => $image->id]);
    }

    public function test_read_only_audit_reports_orphans_and_missing_files(): void
    {
        $image = app(ImageStorage::class)->upload($this->png(), Setting::uploadOptions())['image'];
        $this->artisan('vault:audit')->assertSuccessful();
        $orphan = public_path('media/orphan.webp');
        file_put_contents($orphan, 'orphan');
        $this->artisan('vault:audit')->assertFailed();
        $this->assertFileExists($orphan);
        unlink(public_path('media/'.$image->path));
        $this->artisan('vault:audit')->assertFailed();
        $this->assertDatabaseHas('images', ['id' => $image->id]);
    }

    public function test_dedupe_prefers_active_copy_and_survives_canonical_purge(): void
    {
        $storage = app(ImageStorage::class);
        $image = $storage->upload($this->png(), Setting::uploadOptions())['image'];
        $copy = $storage->duplicate($image, null);
        $image->delete();
        $match = $storage->upload($this->png(), Setting::uploadOptions());
        $this->assertSame($copy->id, $match['image']->id);
        $this->assertFalse($match['trashed']);
        $storage->purge($image);
        $match = $storage->upload($this->png(), Setting::uploadOptions());
        $this->assertTrue($match['duplicate']);
        $this->assertSame($copy->id, $match['image']->id);
    }
}
