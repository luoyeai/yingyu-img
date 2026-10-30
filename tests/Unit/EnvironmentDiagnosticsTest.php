<?php

namespace Tests\Unit;

use App\Services\EnvironmentDiagnostics;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class EnvironmentDiagnosticsTest extends TestCase
{
    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/vault-diagnostics-test-'.bin2hex(random_bytes(10));
        $this->outside = $this->root.'-outside';
        mkdir($this->root, 0700);
        mkdir($this->root.'/public', 0700);
        mkdir($this->root.'/vendor/composer', 0700, true);
        file_put_contents($this->root.'/public/index.php', '<?php // fixture');
        file_put_contents($this->root.'/vendor/autoload.php', '<?php throw new RuntimeException("must not load vendor");');
        $this->platform('PHP_VERSION_ID >= 80400');
        file_put_contents($this->root.'/.env', "DO_NOT_READ_OR_CHANGE=fixture-secret\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        $this->removeTree($this->outside);
        parent::tearDown();
    }

    private function platform(string $expression, string $prefix = ''): void
    {
        file_put_contents($this->root.'/vendor/composer/platform_check.php', '<?php '.$prefix.' if (!('.$expression.')) { throw new RuntimeException("platform mismatch"); }');
    }

    private function checks(array $result): array
    {
        return array_column($result['checks'], null, 'id');
    }

    private function files(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isLink() || $file->isFile()) {
                unlink($file->getPathname());
            } else {
                rmdir($file->getPathname());
            }
        }
        rmdir($root);
    }

    public function test_update_capabilities_block_installation_but_are_advice_for_running_sites_without_duplicate_extensions(): void
    {
        $installation = $this->checks((new EnvironmentDiagnostics($this->root))->run(false, true));
        $runtime = $this->checks((new EnvironmentDiagnostics($this->root))->run(false, false));
        foreach (['extension.zip', 'update.function.proc_open', 'update.function.proc_get_status', 'update.function.fsync', 'update.cli-version', 'update.cli-extension.imagick'] as $id) {
            $this->assertTrue($installation[$id]['required']);
            $this->assertFalse($runtime[$id]['required']);
            $this->assertNotSame('', $installation[$id]['fix']);
        }
        $this->assertFalse($installation['update.cli-version']['ok']);
        foreach (['extension.openssl', 'extension.pdo_mysql', 'extension.imagick'] as $id) {
            $checks = (new EnvironmentDiagnostics($this->root))->run(false)['checks'];
            $this->assertCount(1, array_filter($checks, static fn (array $check): bool => $check['id'] === $id));
        }
    }

    public function test_real_directory_and_webp_probes_leave_no_files_and_preserve_existing_environment(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Run with project bin/php-local for actual Imagick round-trip.');
        }
        $before = $this->files($this->root);
        $result = (new EnvironmentDiagnostics($this->root))->run();
        $checks = $this->checks($result);
        $this->assertTrue($checks['env-atomic-write']['ok']);
        $this->assertTrue($checks['imagick-webp-roundtrip']['ok'], $checks['imagick-webp-roundtrip']['detail']);
        $this->assertTrue($checks['atomic.public.media']['ok']);
        $this->assertTrue($checks['atomic.storage.app.originals']['ok']);
        $this->assertTrue($checks['atomic.storage.app.disabled']['ok']);
        $this->assertTrue($checks['directory.storage.framework.sessions']['ok']);
        $this->assertTrue($checks['directory.storage.app.installation']['ok']);
        $this->assertSame($before, $this->files($this->root));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $result['checked_at']);
        foreach ($result['checks'] as $check) {
            $this->assertSame(['id', 'label', 'required', 'ok', 'detail', 'fix'], array_keys($check));
            $this->assertIsBool($check['required']);
            $this->assertIsBool($check['ok']);
        }
    }

    public function test_inspection_only_never_creates_directories_and_cannot_claim_installation_ready(): void
    {
        $before = $this->files($this->root);
        $result = (new EnvironmentDiagnostics($this->root))->run(false);
        $checks = $this->checks($result);
        $this->assertFalse($result['ready']);
        $this->assertFalse($checks['env-atomic-write']['ok']);
        $this->assertFalse($checks['imagick-webp-roundtrip']['ok']);
        $this->assertFalse(is_dir($this->root.'/storage'));
        $this->assertSame($before, $this->files($this->root));
    }

    public function test_blocked_required_directory_fails_without_residual_probe_files(): void
    {
        mkdir($this->root.'/storage', 0700);
        file_put_contents($this->root.'/storage/logs', 'blocking fixture');
        $before = $this->files($this->root);
        $result = (new EnvironmentDiagnostics($this->root))->run();
        $check = $this->checks($result)['directory.storage.logs'];
        $this->assertFalse($result['ready']);
        $this->assertFalse($check['ok']);
        $this->assertTrue($check['required']);
        $this->assertStringContainsString('勿 chmod 777', $check['fix']);
        $this->assertSame($before, $this->files($this->root));
    }

    public function test_external_symlink_is_rejected_without_writing_outside_project(): void
    {
        mkdir($this->outside, 0700);
        mkdir($this->root.'/storage/app', 0700, true);
        symlink($this->outside, $this->root.'/storage/app/originals');
        $result = (new EnvironmentDiagnostics($this->root))->run();
        $checks = $this->checks($result);
        $this->assertFalse($checks['directory.storage.app.originals']['ok']);
        $this->assertFalse($checks['atomic.storage.app.originals']['ok']);
        $this->assertSame([], $this->files($this->outside));
        $this->assertFalse($result['ready']);
    }

    public function test_composer_real_patch_requirement_is_read_safely_without_executing_vendor(): void
    {
        $marker = $this->root.'/must-not-execute';
        $prefix = 'file_put_contents('.var_export($marker, true).', "bad");';
        $this->platform('PHP_VERSION_ID >= 99999', $prefix);
        $result = (new EnvironmentDiagnostics($this->root))->run(false);
        $check = $this->checks($result)['composer-platform'];
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('9.99.99', $check['detail']);
        $this->assertFileDoesNotExist($marker);
        $this->assertFalse($result['ready']);
    }

    public function test_composer_boolean_platform_conditions_are_evaluated_without_eval(): void
    {
        $this->platform('(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 90000) || PHP_VERSION_ID == 99999');
        $result = (new EnvironmentDiagnostics($this->root))->run(false);
        $this->assertSame(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 90000, $this->checks($result)['composer-platform']['ok']);
        $this->platform('PHP_VERSION_ID >= version_that_requires_execution()');
        $result = (new EnvironmentDiagnostics($this->root))->run(false);
        $this->assertFalse($this->checks($result)['composer-platform']['ok']);
    }

    public function test_missing_vendor_and_public_entry_have_actionable_required_failures(): void
    {
        unlink($this->root.'/vendor/autoload.php');
        unlink($this->root.'/vendor/composer/platform_check.php');
        unlink($this->root.'/public/index.php');
        $result = (new EnvironmentDiagnostics($this->root))->run(false);
        $checks = $this->checks($result);
        foreach (['composer-vendor', 'composer-platform', 'public-entry'] as $id) {
            $this->assertFalse($checks[$id]['ok']);
            $this->assertTrue($checks[$id]['required']);
            $this->assertNotSame('', $checks[$id]['fix']);
        }
    }

    public function test_cli_limits_and_document_root_are_explicit_nonblocking_advice(): void
    {
        $result = (new EnvironmentDiagnostics($this->root))->run(false);
        $checks = $this->checks($result);
        foreach (['document-root', 'https', 'upload-max', 'post-max', 'memory-limit', 'execution-time'] as $id) {
            $this->assertFalse($checks[$id]['required']);
        }
        $this->assertStringContainsString('CLI', $checks['document-root']['detail']);
        $this->assertStringContainsString(PHP_SAPI, $checks['upload-max']['detail']);
    }

    public function test_running_site_does_not_require_configuration_directory_rewrite_permission(): void
    {
        $install = $this->checks((new EnvironmentDiagnostics($this->root))->run(false, true));
        $runtime = $this->checks((new EnvironmentDiagnostics($this->root))->run(false, false));
        $this->assertTrue($install['env-atomic-write']['required']);
        $this->assertFalse($runtime['env-atomic-write']['required']);
        $this->assertFalse($runtime['env-atomic-write']['ok']);
        $this->assertTrue($runtime['directory.storage.app.tmp']['required']);
        $this->assertTrue($runtime['imagick-webp-roundtrip']['required']);
    }

    public function test_protected_root_blocks_installation_but_allows_healthy_runtime(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Requires actual Imagick probe.');
        }
        (new EnvironmentDiagnostics($this->root))->run();
        chmod($this->root, 0500);
        try {
            if (is_writable($this->root)) {
                $this->markTestSkipped('Privileged process bypasses permission fixture.');
            }
            $install = (new EnvironmentDiagnostics($this->root))->run(true, true);
            $runtime = (new EnvironmentDiagnostics($this->root))->run(true, false);
            $this->assertFalse($install['ready']);
            $this->assertFalse($this->checks($install)['env-atomic-write']['ok']);
            $this->assertTrue($runtime['ready']);
            $this->assertFalse($this->checks($runtime)['env-atomic-write']['ok']);
        } finally {
            chmod($this->root, 0700);
        }
    }

    public function test_service_runs_without_laravel_composer_autoload_or_optional_extensions(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open unavailable.');
        }
        $source = dirname(__DIR__, 2).'/app/Services/EnvironmentDiagnostics.php';
        $program = 'require '.var_export($source, true).'; $result=(new App\\Services\\EnvironmentDiagnostics('.var_export($this->root, true).'))->run(false); echo json_encode($result);';
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-n', '-r', $program], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($result['ready']);
        $this->assertFalse($this->checks($result)['extension.imagick']['ok']);
        $this->assertFileDoesNotExist($this->root.'/must-not-execute');
    }
}
