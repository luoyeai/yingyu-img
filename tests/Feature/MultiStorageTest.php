<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\Setting;
use App\Models\StorageConfiguration;
use App\Models\User;
use App\Services\ImageStorage;
use App\Services\ObjectStore;
use App\Services\RemoteImageStorage;
use App\Services\StorageEndpoint;
use App\Services\StorageManager;
use App\Services\StorageMutex;
use App\Services\Updates\UpdateState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MultiStorageTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    private string $originalStorage;

    private string $originalPublic;

    private array $stores = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/vault-multistore-'.bin2hex(random_bytes(8));
        $this->originalStorage = storage_path();
        $this->originalPublic = public_path();
        File::ensureDirectoryExists($this->sandbox.'/public/media');
        File::ensureDirectoryExists($this->sandbox.'/storage/app');
        $this->app->useStoragePath($this->sandbox.'/storage');
        $this->app->usePublicPath($this->sandbox.'/public');
        $manager = Mockery::mock(StorageManager::class, [new StorageEndpoint])->makePartial();
        $manager->shouldReceive('store')->andReturnUsing(fn (StorageConfiguration $configuration): ObjectStore => $this->stores[$configuration->id] ??= new MemoryObjectStore);
        $this->app->instance(StorageManager::class, $manager);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Image::flushEventListeners();
        if (isset($this->sandbox)) {
            $this->app->useStoragePath($this->originalStorage);
            $this->app->usePublicPath($this->originalPublic);
            File::deleteDirectory($this->sandbox);
        }
        parent::tearDown();
    }

    private function png(): UploadedFile
    {
        $image = new \Imagick;
        $image->newImage(32, 24, 'red', 'png');
        $blob = $image->getImageBlob();
        $image->clear();

        return UploadedFile::fake()->createWithContent('fixture.png', $blob);
    }

    private function cloud(array $attributes = []): StorageConfiguration
    {
        $configuration = StorageConfiguration::factory()->create($attributes);
        $this->stores[$configuration->id] = new MemoryObjectStore;

        return $configuration;
    }

    private function upload(StorageConfiguration $configuration, bool $original = false): Image
    {
        return app(ImageStorage::class)->upload($this->png(), [...Setting::uploadOptions(), 'keep_original' => $original], null, $configuration->id)['image'];
    }

    public function test_default_and_dedupe_are_scoped_to_target_and_old_links_never_change(): void
    {
        $local = StorageConfiguration::where('driver', 'local')->firstOrFail();
        $cloud = $this->cloud();
        $other = $this->cloud();
        $localImage = $this->upload($local);
        $localUrl = $localImage->url;
        $this->put('/settings/storage/default', ['storage_configuration_id' => $cloud->id])->assertRedirect();
        $id = $this->postJson('/upload', ['file' => $this->png(), 'preset' => 'standard'])->assertOk()->json('image.id');
        $image = Image::findOrFail($id);
        $this->assertSame($cloud->id, $image->storage_configuration_id);
        $this->assertSame($cloud->publicUrl($image->path), $image->url);
        $this->assertSame($localUrl, $localImage->fresh()->url);
        $this->assertFileExists(public_path('media/'.$localImage->path));
        $this->assertFileDoesNotExist(public_path('media/'.$image->path));
        $this->assertFileExists(storage_path('app/previews/'.$image->path));
        $this->postJson('/upload', ['file' => $this->png(), 'preset' => 'standard'])->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('image.id', $id);
        $this->assertNotSame($id, $this->upload($other)->id);
        $this->get('/')->assertOk()->assertSee('upload-storage');
        $this->get('/settings')->assertOk()->assertDontSee('fixture-secret')->assertDontSee('fixture-key');
    }

    public function test_cloud_copy_disable_enable_soft_delete_original_cleanup_and_purge(): void
    {
        $cloud = $this->cloud();
        $image = $this->upload($cloud, true);
        $store = $this->stores[$cloud->id];
        $key = $cloud->objectKey($image->path);
        $this->assertSame(hash('sha256', $store->objects[$key]), $image->content_sha256);
        $this->assertNotSame($image->source_hash, $image->content_sha256);
        $storage = app(ImageStorage::class);
        $copy = $storage->duplicate($image);
        $storage->disable($image);
        $this->assertArrayNotHasKey($key, $store->objects);
        $this->assertFileExists(storage_path('app/disabled/'.$image->path));
        $this->assertNotNull($image->disabled_at);
        $disabledCopy = $storage->duplicate($image);
        $this->assertFileExists(storage_path('app/disabled/'.$disabledCopy->path));
        $storage->enable($image);
        $this->assertArrayHasKey($key, $store->objects);
        $this->assertFileDoesNotExist(storage_path('app/disabled/'.$image->path));
        $this->post('/images/batch', ['action' => 'trash', 'ids' => [$image->id]])->assertRedirect();
        $this->assertArrayHasKey($key, $store->objects);
        $this->assertTrue($storage->upload($this->png(), [...Setting::uploadOptions(), 'keep_original' => true], null, $cloud->id)['duplicate']);
        $this->post('/settings/clean-originals', ['confirmation' => '清理所有历史原图'])->assertRedirect();
        $this->assertNull($image->fresh()->original_path);
        $storage->purge($image->fresh());
        $this->assertDatabaseMissing('images', ['id' => $image->id]);
        $this->assertArrayNotHasKey($key, $store->objects);
        $this->assertArrayHasKey($cloud->objectKey($copy->path), $store->objects);
        $this->assertDatabaseCount('storage_operations', 0);
        $this->artisan('vault:audit')->assertSuccessful();
    }

    public function test_upload_timeout_and_failed_compensation_remain_retryable_and_block_dedupe(): void
    {
        $cloud = $this->cloud();
        $store = $this->stores[$cloud->id];
        $store->failPutAfterWrite = true;
        $store->failDelete = true;
        $response = $this->postJson('/upload', ['file' => $this->png(), 'preset' => 'standard', 'storage_configuration_id' => $cloud->id])->assertStatus(503);
        $this->assertStringNotContainsString('injected-secret', $response->getContent());
        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('storage_operations', 1);
        $this->assertCount(1, $store->objects);
        $store->failPutAfterWrite = false;
        $this->postJson('/upload', ['file' => $this->png(), 'preset' => 'standard', 'storage_configuration_id' => $cloud->id])->assertStatus(503);
        $this->assertCount(1, $store->objects);
        $store->failDelete = false;
        $this->post('/settings/storage/retry')->assertRedirect();
        $this->assertDatabaseCount('storage_operations', 0);
        $this->assertCount(0, $store->objects);
        $this->assertNotNull($this->upload($cloud));
    }

    public function test_unknown_disable_blocks_restore_copy_and_original_cleanup_then_converges(): void
    {
        $cloud = $this->cloud();
        $image = $this->upload($cloud, true);
        $store = $this->stores[$cloud->id];
        $store->failDelete = true;
        $this->post('/images/batch', ['action' => 'disable', 'ids' => [$image->id]])->assertSessionHasErrors('batch');
        $this->assertNull($image->fresh()->disabled_at);
        $backup = storage_path('app/disabled/'.$image->path);
        $this->assertFileExists($backup);
        $this->assertSame($image->content_sha256, hash_file('sha256', $backup));
        $this->post('/images/batch', ['action' => 'trash', 'ids' => [$image->id]])->assertSessionHasErrors('batch');
        $this->post('/images/batch', ['action' => 'copy', 'ids' => [$image->id]])->assertSessionHasErrors('batch');
        $this->post('/settings/clean-originals', ['confirmation' => '清理所有历史原图'])->assertSessionHasErrors('originals');
        touch($backup, time() - 172800);
        $this->artisan('vault:cleanup')->assertSuccessful();
        $this->assertFileExists($backup);
        $store->failDelete = false;
        $this->post('/settings/storage/retry')->assertRedirect();
        $this->assertNotNull($image->fresh()->disabled_at);
        $this->assertDatabaseCount('storage_operations', 0);
        app(ImageStorage::class)->enable($image);
        $this->assertNull($image->fresh()->disabled_at);
    }

    public function test_database_failure_after_remote_disable_retains_verified_backup_and_intent(): void
    {
        $cloud = $this->cloud();
        $image = $this->upload($cloud);
        Image::updating(static function (): void {
            throw new RuntimeException('injected DB failure');
        });
        try {
            app(ImageStorage::class)->disable($image);
            $this->fail('must fail');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('images', ['id' => $image->id, 'disabled_at' => null]);
        }
        Image::flushEventListeners();
        $this->assertCount(0, $this->stores[$cloud->id]->objects);
        $this->assertFileExists(storage_path('app/disabled/'.$image->path));
        $this->assertDatabaseCount('storage_operations', 1);
        $this->post('/settings/storage/retry')->assertRedirect();
        $this->assertNotNull($image->fresh()->disabled_at);
    }

    public function test_unknown_enable_retains_disabled_copy_until_remote_and_database_are_confirmed(): void
    {
        $cloud = $this->cloud();
        $image = $this->upload($cloud);
        app(ImageStorage::class)->disable($image);
        $this->stores[$cloud->id]->failPutAfterWrite = true;
        $this->post('/images/batch', ['action' => 'enable', 'ids' => [$image->id]])->assertSessionHasErrors('batch');
        $this->assertNotNull($image->fresh()->disabled_at);
        $this->assertFileExists(storage_path('app/disabled/'.$image->path));
        $this->stores[$cloud->id]->failPutAfterWrite = false;
        $this->post('/settings/storage/retry')->assertRedirect();
        $this->assertNull($image->fresh()->disabled_at);
        $this->assertFileDoesNotExist(storage_path('app/disabled/'.$image->path));
    }

    public function test_purge_failure_never_loses_record_and_restore_cannot_race_pending_purge(): void
    {
        $cloud = $this->cloud();
        $image = $this->upload($cloud, true);
        $this->post('/images/batch', ['action' => 'trash', 'ids' => [$image->id]])->assertRedirect();
        $this->stores[$cloud->id]->failDelete = true;
        $this->post('/images/batch', ['action' => 'purge', 'ids' => [$image->id], 'confirmation' => '永久删除'])->assertSessionHasErrors('batch');
        $this->assertDatabaseHas('images', ['id' => $image->id]);
        $this->assertFileExists(storage_path('app/disabled/'.$image->path));
        $this->post('/images/batch', ['action' => 'restore', 'ids' => [$image->id]])->assertSessionHasErrors('batch');
        $this->stores[$cloud->id]->failDelete = false;
        $this->post('/settings/storage/retry')->assertRedirect();
        $this->assertDatabaseMissing('images', ['id' => $image->id]);
        $this->assertFileDoesNotExist(storage_path('app/disabled/'.$image->path));
        $this->assertFileDoesNotExist(storage_path('app/previews/'.$image->path));
    }

    public function test_corrupt_full_object_prevents_disable_and_safe_record_remains(): void
    {
        $cloud = $this->cloud();
        $image = $this->upload($cloud);
        $key = $cloud->objectKey($image->path);
        $this->stores[$cloud->id]->objects[$key] = str_repeat('x', $image->bytes);
        $this->post('/images/batch', ['action' => 'disable', 'ids' => [$image->id]])->assertSessionHasErrors('batch');
        $this->assertArrayHasKey($key, $this->stores[$cloud->id]->objects);
        $this->assertNull($image->fresh()->disabled_at);
        $this->assertDatabaseCount('storage_operations', 1);
    }

    public function test_configuration_secrets_are_encrypted_hidden_rotatable_and_references_protected(): void
    {
        $cloud = $this->cloud();
        $raw = DB::table('storage_configurations')->where('id', $cloud->id)->value('credentials');
        $this->assertStringNotContainsString('fixture-secret', $raw);
        $this->assertArrayNotHasKey('credentials', $cloud->toArray());
        $image = $this->upload($cloud);
        $url = $image->url;
        $this->put('/settings/storage/'.$cloud->id, ['name' => 'Changed', 'enabled' => 0,
            'bucket' => 'different-bucket', 'public_url' => 'https://changed.invalid', 'access_key' => 'rotated-key', 'secret_key' => 'rotated-secret'])->assertRedirect();
        $this->assertSame($url, $image->fresh()->url);
        $this->assertSame('rotated-secret', $cloud->fresh()->credentials['secret']);
        $this->postJson('/upload', ['file' => $this->png(), 'preset' => 'standard', 'storage_configuration_id' => $cloud->id])->assertUnprocessable();
        $image->delete();
        $this->delete('/settings/storage/'.$cloud->id)->assertSessionHasErrors('storage');
        $this->put('/settings/storage/default', ['storage_configuration_id' => $cloud->id])->assertSessionHasErrors('storage');
        app(ImageStorage::class)->disable($image);
        $this->assertNotNull($image->fresh()->disabled_at);
        $unused = $this->cloud();
        $this->delete('/settings/storage/'.$unused->id)->assertRedirect();
        $this->assertDatabaseMissing('storage_configurations', ['id' => $unused->id]);
    }

    public function test_unsafe_endpoints_are_rejected_and_validation_never_flashes_keys(): void
    {
        $data = ['name' => 'unsafe', 'driver' => 's3', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1', 'bucket' => 'bucket',
            'public_url' => 'https://cdn.example.com', 'access_key' => 'sensitive-key', 'secret_key' => 'sensitive-secret', 'bucket_confirmed' => 1];
        $this->from('/settings')->post('/settings/storage', $data)->assertSessionHasErrors('storage');
        $this->assertNull(session()->getOldInput('access_key'));
        $this->assertNull(session()->getOldInput('secret_key'));
        $this->assertDatabaseCount('storage_configurations', 1);
        $data['driver'] = 'cos';
        $data['bucket'] = 'photos-1250000000';
        $data['region'] = 'ap-guangzhou';
        $this->post('/settings/storage', $data)->assertRedirect();
        $configuration = StorageConfiguration::where('driver', 'cos')->firstOrFail();
        $this->assertSame('https://cos.ap-guangzhou.myqcloud.com', $configuration->endpoint);
        $this->assertFalse($configuration->path_style);
    }

    public function test_storage_routes_require_authentication_and_update_lock_blocks_retry(): void
    {
        $cloud = $this->cloud();
        $state = app(UpdateState::class);
        $state->write('journal.json', ['active' => true]);
        $this->post('/settings/storage/retry')->assertStatus(503);
        $state->write('journal.json', ['active' => false]);
        $this->app['auth']->forgetGuards();
        foreach (['/settings/storage', '/settings/storage/retry', '/settings/storage/'.$cloud->id.'/test'] as $route) {
            $this->post($route)->assertRedirect('/login');
        }
        $this->put('/settings/storage/default')->assertRedirect('/login');
        $this->put('/settings/storage/'.$cloud->id)->assertRedirect('/login');
        $this->delete('/settings/storage/'.$cloud->id)->assertRedirect('/login');
    }

    public function test_committed_purge_cleanup_failure_cannot_regress_to_unknown_or_lose_retryability(): void
    {
        $cloud = $this->cloud();
        $image = $this->upload($cloud);
        $image->delete();
        $directory = dirname(storage_path('app/previews/'.$image->path));
        Image::forceDeleted(static function () use ($directory): void {
            chmod($directory, 0500);
        });
        try {
            app(ImageStorage::class)->purge($image);
            $this->fail('cleanup must fail');
        } catch (RuntimeException) {
            $this->assertDatabaseMissing('images', ['id' => $image->id]);
            $this->assertDatabaseHas('storage_operations', ['image_id' => $image->id, 'state' => 'committed']);
        } finally {
            chmod($directory, 0700);
            Image::flushEventListeners();
        }
        $this->post('/settings/storage/retry')->assertRedirect();
        $this->assertDatabaseCount('storage_operations', 0);
        $this->assertFileDoesNotExist(storage_path('app/previews/'.$image->path));
        $this->assertFileDoesNotExist(storage_path('app/disabled/'.$image->path));
    }

    public function test_storage_mutex_excludes_other_database_sessions_and_releases_after_failure(): void
    {
        $configuration = DB::connection()->getConfig();
        $pdo = new \PDO('mysql:host=127.0.0.1;port=13368;dbname=image_vault_test;charset=utf8mb4', $configuration['username'], $configuration['password']);
        $name = substr('vault-storage-'.hash('sha256', 'image_vault_test'), 0, 64);
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        try {
            app(StorageMutex::class)->run(function () use ($statement, $name): void {
                $statement->execute([$name]);
                $this->assertSame(0, (int) $statement->fetchColumn());
                throw new RuntimeException('fixture failure');
            });
        } catch (RuntimeException) {
            $statement->execute([$name]);
            $this->assertSame(1, (int) $statement->fetchColumn());
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    public function test_upload_preflight_failure_creates_no_objects_or_intents_and_unused_configuration_can_be_deleted(): void
    {
        $cloud = $this->cloud();
        $this->stores[$cloud->id]->failPreflight = true;
        try {
            $this->upload($cloud);
            $this->fail('unavailable bucket accepted');
        } catch (RuntimeException) {
            $this->assertSame([], $this->stores[$cloud->id]->objects);
            $this->assertDatabaseCount('storage_operations', 0);
            $this->assertDatabaseCount('images', 0);
        }
        $this->delete('/settings/storage/'.$cloud->id)->assertSessionHasNoErrors();
    }

    public function test_unreadable_upload_credentials_create_no_intent_and_unused_configuration_can_be_deleted(): void
    {
        $cloud = $this->cloud();
        DB::table('storage_configurations')->where('id', $cloud->id)->update(['credentials' => 'broken-ciphertext']);
        $this->app->instance(StorageManager::class, new StorageManager(new StorageEndpoint));
        try {
            $this->upload($cloud);
            $this->fail('broken credentials accepted');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('APP_KEY', $exception->getMessage());
            $this->assertDatabaseCount('storage_operations', 0);
            $this->assertDatabaseCount('images', 0);
        }
        $this->delete('/settings/storage/'.$cloud->id)->assertSessionHasNoErrors();
    }

    public function test_bucket_preflight_failure_leaves_no_cleanup_intent_and_configuration_remains_deletable(): void
    {
        $cloud = $this->cloud();
        $this->stores[$cloud->id]->failPreflight = true;
        $this->post('/settings/storage/'.$cloud->id.'/test')->assertSessionHasErrors('storage');
        $this->assertSame([], $this->stores[$cloud->id]->objects);
        $this->assertDatabaseCount('storage_operations', 0);
        $this->delete('/settings/storage/'.$cloud->id)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('storage_configurations', ['id' => $cloud->id]);
    }

    public function test_unknown_test_write_retains_cleanup_intent_until_confirmed_deleted(): void
    {
        $cloud = $this->cloud();
        $store = $this->stores[$cloud->id];
        $store->failPutAfterWrite = true;
        $store->failDelete = true;
        $this->post('/settings/storage/'.$cloud->id.'/test')->assertSessionHasErrors('storage');
        $this->assertCount(1, $store->objects);
        $this->assertDatabaseHas('storage_operations', ['operation' => 'test_cleanup']);
        $this->delete('/settings/storage/'.$cloud->id)->assertSessionHasErrors('storage');
        $store->failDelete = false;
        $this->post('/settings/storage/retry')->assertSessionHasNoErrors();
        $this->assertSame([], $store->objects);
        $this->assertDatabaseCount('storage_operations', 0);
    }

    public function test_repeated_manual_retries_do_not_starve_operations_after_first_fifty_failures(): void
    {
        $cloud = $this->cloud();
        $this->stores[$cloud->id]->failDelete = true;
        $remote = app(RemoteImageStorage::class);
        for ($index = 0; $index < 50; $index++) {
            $remote->begin($cloud, 'test_cleanup', '_checks/'.$index.'.webp', null, []);
        }
        $healthy = $this->cloud();
        $last = $remote->begin($healthy, 'test_cleanup', '_checks/last.webp', null, []);
        $this->post('/settings/storage/retry')->assertRedirect();
        $this->assertDatabaseHas('storage_operations', ['id' => $last, 'attempts' => 0]);
        $this->post('/settings/storage/retry')->assertRedirect();
        $this->assertDatabaseMissing('storage_operations', ['id' => $last]);
        $this->assertDatabaseCount('storage_operations', 50);
    }

    public function test_wrong_app_key_fails_closed_without_using_environment_credentials(): void
    {
        $cloud = $this->cloud();
        DB::table('storage_configurations')->where('id', $cloud->id)->update(['credentials' => 'broken-ciphertext']);
        try {
            (new StorageManager(new StorageEndpoint))->store($cloud->fresh());
            $this->fail('must reject broken credentials');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('APP_KEY', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }
}

class MemoryObjectStore implements ObjectStore
{
    public array $objects = [];

    public bool $failPutAfterWrite = false;

    public bool $failDelete = false;

    public bool $failPreflight = false;

    public function assertSafeBucket(): void
    {
        if ($this->failPreflight) {
            throw new RuntimeException('fixture bucket unavailable');
        }
    }

    public function put(string $key, string $source): void
    {
        $this->objects[$key] = file_get_contents($source);
        if ($this->failPutAfterWrite) {
            throw new RuntimeException('timeout injected-secret');
        }
    }

    public function download(string $key, string $destination, int $maxBytes = 134217728): void
    {
        if (! isset($this->objects[$key])) {
            throw new RuntimeException('missing');
        }
        file_put_contents($destination, $this->objects[$key]);
    }

    public function copy(string $source, string $destination): void
    {
        $this->objects[$destination] = $this->objects[$source];
    }

    public function head(string $key): ?array
    {
        return isset($this->objects[$key]) ? ['bytes' => strlen($this->objects[$key]), 'sha256' => hash('sha256', $this->objects[$key])] : null;
    }

    public function delete(string $key): void
    {
        if ($this->failDelete) {
            throw new RuntimeException('delete injected-secret');
        }
        unset($this->objects[$key]);
    }
}
