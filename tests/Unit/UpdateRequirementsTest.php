<?php

namespace Tests\Unit;

use App\Services\Updates\UpdateRequirements;
use PHPUnit\Framework\TestCase;

class UpdateRequirementsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/vault-requirements-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    private function indexed(array $checks): array
    {
        return array_column($checks, null, 'id');
    }

    private function cli(array $extensions, string $version = PHP_VERSION): string
    {
        $path = $this->root.'/php-fixture';
        $report = json_encode(['version' => $version, 'sapi' => 'cli', 'extensions' => $extensions], JSON_THROW_ON_ERROR);
        file_put_contents($path, "#!/bin/sh\nprintf '%s' '".$report."'\n");
        chmod($path, 0700);

        return $path;
    }

    public function test_real_php_cli_is_probed_without_application_or_database_bootstrap(): void
    {
        $checks = (new UpdateRequirements(dirname(__DIR__, 2).'/bin/php-local'))->checks();
        foreach ($checks as $check) {
            $this->assertTrue($check['ok'], $check['label'].': '.$check['detail']);
            $this->assertNotSame('', $check['fix']);
        }
        $indexed = $this->indexed($checks);
        foreach (['extension.simplexml', 'storage.function.dns_get_record', 'update.cli-extension.curl', 'update.cli-extension.simplexml', 'update.cli-function.dns_get_record'] as $id) {
            $this->assertTrue($indexed[$id]['ok']);
        }
        $this->assertSame([], glob($this->root.'/*'));
    }

    public function test_missing_web_extensions_and_disabled_process_functions_are_individually_actionable(): void
    {
        $class = dirname(__DIR__, 2).'/app/Services/Updates/UpdateRequirements.php';
        $program = 'namespace App\\Services\\Updates; function extension_loaded(string $name): bool { return $name !== "zip" && \\extension_loaded($name); } require '.var_export($class, true).'; echo json_encode((new UpdateRequirements)->checks());';
        $checks = $this->indexed($this->subprocess([PHP_BINARY, '-n', '-d', 'disable_functions=proc_open,proc_get_status,fsync', '-r', $program]));
        foreach (['extension.zip', 'extension.imagick', 'update.function.proc_open', 'update.function.proc_get_status', 'update.function.fsync', 'update.cli-version'] as $id) {
            $this->assertFalse($checks[$id]['ok']);
            $this->assertTrue($checks[$id]['required']);
            $this->assertNotSame('', $checks[$id]['fix']);
        }
    }

    public function test_web_and_cli_extensions_and_patch_versions_are_checked_separately(): void
    {
        $checks = $this->indexed((new UpdateRequirements($this->cli(['pdo_mysql' => false, 'imagick' => false], '8.4.0')))->checks());
        $this->assertTrue($checks['extension.pdo_mysql']['ok']);
        $this->assertTrue($checks['extension.imagick']['ok']);
        $this->assertFalse($checks['update.cli-extension.pdo_mysql']['ok']);
        $this->assertFalse($checks['update.cli-extension.imagick']['ok']);
        $this->assertFalse($checks['update.cli-version']['ok']);
        $this->assertStringContainsString('8.4.0', $checks['update.cli-version']['detail']);
    }

    public function test_cli_timeout_is_bounded_and_does_not_leak_process_output(): void
    {
        $path = $this->root.'/slow-php';
        file_put_contents($path, "#!/bin/sh\nexec '".PHP_BINARY."' -r 'usleep(8000000);'\n");
        chmod($path, 0700);
        $start = microtime(true);
        $checks = $this->indexed((new UpdateRequirements($path))->checks());
        $this->assertFalse($checks['update.cli-version']['ok']);
        $this->assertLessThan(7, microtime(true) - $start);
    }

    public function test_disabled_update_function_does_not_become_a_site_startup_gate(): void
    {
        $project = dirname(__DIR__, 2);
        $program = 'require '.var_export($project.'/bootstrap/preflight.php', true).'; echo json_encode(vault_preflight_checks('.var_export($project, true).'));';
        $checks = $this->subprocess([$project.'/bin/php-local', '-d', 'disable_functions=proc_open,proc_get_status,proc_terminate,proc_close,fsync', '-r', $program]);
        foreach ($checks as $check) {
            $this->assertTrue($check['ok'], $check['label']);
        }
    }

    private function subprocess(array $command): array
    {
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);

        return json_decode($output, true, 64, JSON_THROW_ON_ERROR);
    }
}
