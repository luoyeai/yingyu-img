<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UpdateCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_cleanup_preserves_legacy_deletion_recovery_files_and_expires_upload_temporaries(): void
    {
        $originalStorage = storage_path();
        $sandbox = sys_get_temp_dir().'/vault-cleanup-audit-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($sandbox.'/app/tmp');
        $this->app->useStoragePath($sandbox);
        $temporary = storage_path('app/tmp');
        $recoveryNames = ['original-'.str_repeat('a', 32), 'purge-'.str_repeat('b', 32)];
        try {
            foreach ([...$recoveryNames, 'expired-upload.webp'] as $name) {
                file_put_contents($temporary.'/'.$name, 'keep recovery bytes: '.$name);
                touch($temporary.'/'.$name, time() - 172800);
            }
            file_put_contents($temporary.'/active-upload.webp', 'active upload');
            $this->artisan('vault:cleanup')->assertSuccessful();
            foreach ($recoveryNames as $name) {
                $this->assertFileExists($temporary.'/'.$name);
                $this->assertSame('keep recovery bytes: '.$name, file_get_contents($temporary.'/'.$name));
            }
            $this->assertFileDoesNotExist($temporary.'/expired-upload.webp');
            $this->assertFileExists($temporary.'/active-upload.webp');
        } finally {
            $this->app->useStoragePath($originalStorage);
            File::deleteDirectory($sandbox);
        }
    }
}
