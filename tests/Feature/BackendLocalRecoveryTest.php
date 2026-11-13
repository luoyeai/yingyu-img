<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\Setting;
use App\Services\ImageStorage;
use App\Services\RemoteImageStorage;
use App\Services\StorageMutex;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackendLocalRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    private string $sandbox;

    private string $originalPublic;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/vault-local-recovery-'.bin2hex(random_bytes(8));
        $this->originalPublic = public_path();
        $this->originalStorage = storage_path();
        File::ensureDirectoryExists($this->sandbox.'/public/media');
        File::ensureDirectoryExists($this->sandbox.'/storage/app');
        $this->app->usePublicPath($this->sandbox.'/public');
        $this->app->useStoragePath($this->sandbox.'/storage');
    }

    protected function tearDown(): void
    {
        if (isset($this->sandbox)) {
            $this->app->usePublicPath($this->originalPublic);
            $this->app->useStoragePath($this->originalStorage);
            File::deleteDirectory($this->sandbox);
        }
        parent::tearDown();
    }

    public static function interruptionCases(): array
    {
        return [
            'original before commit' => ['original', 'before'],
            'original after commit' => ['original', 'after'],
            'purge before commit' => ['purge', 'before'],
            'purge after commit' => ['purge', 'after'],
        ];
    }

    #[DataProvider('interruptionCases')]
    public function test_killed_local_deletion_preserves_files_until_committed_cleanup(string $action, string $phase): void
    {
        $image = $this->image();
        $paths = [storage_path('app/originals/'.$image->original_path)];
        if ($action === 'purge') {
            $image->delete();
            $paths[] = public_path('media/'.$image->path);
            $paths[] = storage_path('app/previews/'.$image->preview_path);
        }
        $hashes = [];
        foreach ($paths as $path) {
            $hashes[$path] = hash_file('sha256', $path);
            touch($path, time() - 172800);
        }

        $process = $this->interruptDeletion($image, $action, $phase);
        $this->assertStringContainsString('checkpoint:'.$phase, $process->getOutput(), $process->getErrorOutput());
        $this->assertTrue($process->hasBeenSignaled());
        $this->assertSame(9, $process->getTermSignal());
        foreach ($hashes as $path => $hash) {
            $this->assertFileExists($path);
            $this->assertSame($hash, hash_file('sha256', $path));
        }

        $operation = $action === 'purge' ? 'local_purge_cleanup' : 'local_original_cleanup';
        $this->assertDatabaseHas('storage_operations', ['image_id' => $image->id, 'operation' => $operation,
            'state' => $phase === 'before' ? 'pending' : 'committed']);
        $record = Image::withTrashed()->find($image->id);
        if ($phase === 'before') {
            $this->assertNotNull($record);
            $this->assertSame($image->original_path, $record->original_path);
        } elseif ($action === 'purge') {
            $this->assertNull($record);
        } else {
            $this->assertNull($record->original_path);
            $this->assertSame(0, $record->original_bytes);
        }

        $result = app(StorageMutex::class)->run(fn (): array => app(RemoteImageStorage::class)->retryPending());
        $this->assertSame(['completed' => 1, 'failed' => 0], $result);
        $this->assertDatabaseCount('storage_operations', 0);
        foreach ($paths as $path) {
            if ($phase === 'before') {
                $this->assertFileExists($path);
            } else {
                $this->assertFileDoesNotExist($path);
            }
        }
    }

    public static function creationActions(): array
    {
        return [['upload'], ['copy']];
    }

    #[DataProvider('creationActions')]
    public function test_lost_local_creation_commit_acknowledgement_preserves_committed_records_and_files(string $action): void
    {
        $source = $this->image();
        $this->failNextCommittedTransaction();
        try {
            $action === 'copy'
                ? app(ImageStorage::class)->duplicate($source)
                : $this->uploadAnother($source);
            $this->fail('The simulated commit acknowledgement must be lost.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('simulated commit acknowledgement lost', $exception->getMessage());
        }
        $this->assertDatabaseCount('images', 2);
        $this->assertDatabaseCount('storage_operations', 0);
        $record = Image::latest('id')->firstOrFail();
        $this->assertNotSame($source->id, $record->id);
        $this->assertFileExists(public_path('media/'.$record->path));
        $this->assertFileExists(storage_path('app/previews/'.$record->preview_path));
        $this->assertFileExists(storage_path('app/originals/'.$record->original_path));
        $this->assertSame($record->content_sha256, hash_file('sha256', public_path('media/'.$record->path)));
    }

    public static function availabilityActions(): array
    {
        return [['disable'], ['enable']];
    }

    #[DataProvider('availabilityActions')]
    public function test_lost_local_availability_commit_acknowledgement_never_reverses_committed_file_state(string $action): void
    {
        $image = $this->image();
        $storage = app(ImageStorage::class);
        if ($action === 'enable') {
            $storage->disable($image);
        }
        $this->failNextCommittedTransaction();
        try {
            $storage->{$action}($image);
            $this->fail('The simulated commit acknowledgement must be lost.');
        } catch (\RuntimeException) {
            $this->assertSame($action === 'disable', (bool) $image->fresh()->disabled_at);
        }
        $public = public_path('media/'.$image->path);
        $private = storage_path('app/disabled/'.$image->path);
        $this->assertSame($action === 'enable', is_file($public));
        $this->assertSame($action === 'disable', is_file($private));
        $this->assertDatabaseCount('storage_operations', 0);
    }

    public static function creationInterruptions(): array
    {
        return [['upload', 'before'], ['upload', 'after'], ['copy', 'before'], ['copy', 'after']];
    }

    #[DataProvider('creationInterruptions')]
    public function test_interrupted_local_creation_is_reconciled_from_committed_references(string $action, string $phase): void
    {
        $source = $this->image();
        $process = $this->interruptDeletion($source, $action, $phase);
        $this->assertStringContainsString('checkpoint:'.$phase, $process->getOutput(), $process->getErrorOutput());
        $this->assertTrue($process->hasBeenSignaled());
        $this->assertSame(9, $process->getTermSignal());
        if ($phase === 'before') {
            $operation = DB::table('storage_operations')->sole();
            $this->assertSame('local_upload_cleanup', $operation->operation);
            $payload = json_decode($operation->payload, true, 32, JSON_THROW_ON_ERROR);
        } else {
            $record = Image::latest('id')->firstOrFail();
            $payload = ['public_path' => $record->path, 'preview_path' => $record->preview_path, 'original_path' => $record->original_path];
        }
        $paths = [public_path('media/'.$payload['public_path']), storage_path('app/previews/'.$payload['preview_path']), storage_path('app/originals/'.$payload['original_path'])];
        foreach ($paths as $path) {
            $this->assertFileExists($path);
        }
        $result = app(StorageMutex::class)->run(fn (): array => app(RemoteImageStorage::class)->retryPending());
        $this->assertSame(['completed' => $phase === 'before' ? 1 : 0, 'failed' => 0], $result);
        $this->assertDatabaseCount('images', $phase === 'before' ? 1 : 2);
        $this->assertDatabaseCount('storage_operations', 0);
        foreach ($paths as $path) {
            $this->assertSame($phase === 'after', is_file($path));
        }
        $this->assertFileExists(public_path('media/'.$source->path));
        $this->assertFileExists(storage_path('app/originals/'.$source->original_path));
    }

    public static function availabilityInterruptions(): array
    {
        return [['disable', 'before'], ['disable', 'after'], ['enable', 'before'], ['enable', 'after']];
    }

    #[DataProvider('availabilityInterruptions')]
    public function test_interrupted_local_availability_can_resume_without_a_second_file_move(string $action, string $phase): void
    {
        $image = $this->image();
        if ($action === 'enable') {
            app(ImageStorage::class)->disable($image);
        }
        $process = $this->interruptDeletion($image, $action, $phase);
        $this->assertStringContainsString('checkpoint:'.$phase, $process->getOutput(), $process->getErrorOutput());
        $this->assertTrue($process->hasBeenSignaled());
        $this->assertSame(9, $process->getTermSignal());
        $this->assertSame($phase === 'after' ? $action === 'disable' : $action === 'enable', (bool) $image->fresh()->disabled_at);
        $this->assertSame($action === 'enable', is_file(public_path('media/'.$image->path)));
        $this->assertSame($action === 'disable', is_file(storage_path('app/disabled/'.$image->path)));
        $result = app(StorageMutex::class)->run(fn (): array => app(RemoteImageStorage::class)->retryPending());
        $this->assertSame(['completed' => $phase === 'before' ? 1 : 0, 'failed' => 0], $result);
        $this->assertSame($action === 'disable', (bool) $image->fresh()->disabled_at);
        $this->assertDatabaseCount('storage_operations', 0);
    }

    private function failNextCommittedTransaction(): void
    {
        $injected = false;
        $this->app['events']->listen(TransactionCommitted::class, static function () use (&$injected): void {
            if (! $injected) {
                $injected = true;
                throw new \RuntimeException('simulated commit acknowledgement lost');
            }
        });
    }

    private function uploadAnother(Image $source): array
    {
        $file = new UploadedFile(storage_path('app/originals/'.$source->original_path), 'fixture.png', 'image/png', null, true);

        return app(ImageStorage::class)->upload($file, [...Setting::uploadOptions(), 'quality' => 37, 'keep_original' => true]);
    }

    private function image(): Image
    {
        $source = new \Imagick;
        $source->newImage(32, 24, 'red', 'png');
        $blob = $source->getImageBlob();
        $source->clear();

        return app(ImageStorage::class)->upload(UploadedFile::fake()->createWithContent('fixture.png', $blob),
            [...Setting::uploadOptions(), 'keep_original' => true])['image'];
    }

    private function interruptDeletion(Image $image, string $action, string $phase): Process
    {
        $code = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = config('database.connections.mysql');
if (! app()->environment('testing') || config('database.default') !== 'mysql'
    || ($database['database'] ?? '') !== 'image_vault_test' || ($database['host'] ?? '') !== '127.0.0.1'
    || (int) ($database['port'] ?? 0) !== 13368 || ! empty($database['url']) || ! empty($database['unix_socket'])) {
    throw new LogicException('Refusing subprocess access outside the isolated test database.');
}
$app->usePublicPath(getenv('VAULT_AUDIT_SANDBOX').'/public');
$app->useStoragePath(getenv('VAULT_AUDIT_SANDBOX').'/storage');
$phase = getenv('VAULT_AUDIT_PHASE');
$event = $phase === 'before' ? Illuminate\Database\Events\TransactionCommitting::class : Illuminate\Database\Events\TransactionCommitted::class;
$app['events']->listen($event, static function ($event) use ($phase): void {
    if ($event->connection->transactionLevel() === ($phase === 'before' ? 1 : 0)) {
        fwrite(STDOUT, 'checkpoint:'.$phase."\n");
        fflush(STDOUT);
        posix_kill(getmypid(), 9);
    }
});
$image = App\Models\Image::withTrashed()->findOrFail((int) getenv('VAULT_AUDIT_IMAGE_ID'));
$storage = app(App\Services\ImageStorage::class);
match (getenv('VAULT_AUDIT_ACTION')) {
    'purge' => $storage->purge($image),
    'original' => $storage->deleteOriginal($image),
    'disable' => $storage->disable($image),
    'enable' => $storage->enable($image),
    'copy' => $storage->duplicate($image),
    'upload' => $storage->upload(new Illuminate\Http\UploadedFile($storage->privatePath('originals', $image->original_path), 'fixture.png', 'image/png', null, true),
        [...App\Models\Setting::uploadOptions(), 'quality' => 37, 'keep_original' => true]),
};
PHP;
        $process = new Process([base_path('bin/php-local'), '-r', $code], base_path(), [
            'APP_ENV' => 'testing', 'VAULT_AUDIT_SANDBOX' => $this->sandbox,
            'VAULT_AUDIT_PHASE' => $phase, 'VAULT_AUDIT_ACTION' => $action, 'VAULT_AUDIT_IMAGE_ID' => (string) $image->id,
        ]);
        $process->setTimeout(15);
        try {
            $process->run();
        } catch (ProcessSignaledException) {
            $this->assertTrue($process->hasBeenSignaled());
        }

        return $process;
    }
}
