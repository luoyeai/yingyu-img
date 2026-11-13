<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class UpdateRescueTest extends TestCase
{
    private string $root;

    private string $directory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/vault-rescue-'.bin2hex(random_bytes(8));
        $this->directory = $this->root.'/storage/app/updates';
        mkdir($this->directory.'/runtime', 0700, true);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(dirname(__DIR__, 2).'/release/github-v1.0.2/image-vault-20261030-opensource-v1-0-2-production.zip'));
        foreach (['UpdateState', 'UpdatePackage', 'DatabaseSnapshot', 'UpdateRequirements', 'UpdateRunner', 'UpdateWeb'] as $class) {
            file_put_contents($this->directory.'/runtime/'.$class.'.php', $zip->getFromName('image-vault/app/Services/Updates/'.$class.'.php'));
        }
        $zip->close();
        touch($this->directory.'/update.lock');
        file_put_contents($this->directory.'/connection.json', 'DO NOT READ CONNECTION CONFIGURATION');
        $backup = $this->directory.'/tasks/'.str_repeat('a', 32).'/backup';
        mkdir($backup.'/files/app', 0700, true);
        mkdir($backup.'/database', 0700);
        file_put_contents($backup.'/files/app/example.php', 'fixture');
        $files = ['app/example.php' => ['size' => 7, 'sha256' => hash('sha256', 'fixture'), 'mode' => 0644]];
        file_put_contents($backup.'/files.json', json_encode($files));
        file_put_contents($backup.'/database/fixture.rows', "[]\n");
        $database = ['format' => 1, 'database' => 'image_vault_test', 'tables' => ['fixture' => ['bytes' => 3, 'sha256' => hash('sha256', "[]\n")]]];
        file_put_contents($backup.'/database/manifest.json', json_encode($database));
        $journal = ['id' => str_repeat('a', 32), 'from' => '1.0.2', 'to' => '1.0.3', 'status' => 'failed', 'stage' => 'health', 'active' => true,
            'manifest' => ['files' => $files], 'before' => ['managed_files' => array_keys($files)],
            'backup' => ['files_sha256' => hash_file('sha256', $backup.'/files.json'), 'database' => ['sha256' => hash_file('sha256', $backup.'/database/manifest.json'), 'database' => 'image_vault_test', 'tables' => 1, 'bytes' => 3]]];
        file_put_contents($this->directory.'/journal.json', json_encode($journal));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_check_is_read_only_and_repair_is_backed_up_idempotent_and_preserves_task_and_permissions(): void
    {
        $before = $this->snapshot();
        $check = $this->runTool();
        $this->assertSame(0, $check['exit'], $check['output']);
        $this->assertStringContainsString('"database_accessed": false', $check['output']);
        $this->assertSame($before, $this->snapshot());
        $runner = $this->directory.'/runtime/UpdateRunner.php';
        chmod($runner, 0640);
        $metadata = stat($runner);
        $repair = $this->runTool(['--repair']);
        $this->assertSame(0, $repair['exit'], $repair['output']);
        clearstatcache();
        $this->assertSame($metadata['mode'], stat($runner)['mode']);
        $this->assertSame($metadata['uid'], stat($runner)['uid']);
        $this->assertSame($metadata['gid'], stat($runner)['gid']);
        $this->assertSame($before['journal.json'], hash_file('sha256', $this->directory.'/journal.json'));
        $backups = glob($this->directory.'/rescue-*');
        $this->assertCount(1, $backups);
        $this->assertSame($before['runtime/UpdateRunner.php'], hash_file('sha256', $backups[0].'/UpdateRunner.php'));
        $this->assertSame($before['runtime/UpdateWeb.php'], hash_file('sha256', $backups[0].'/UpdateWeb.php'));
        $this->assertSame(0700, fileperms($backups[0]) & 0777);
        $this->assertSame(0600, fileperms($backups[0].'/repair.json') & 0777);
        $this->assertSame(0, $this->runTool(['--repair'])['exit']);
        $this->assertCount(1, glob($this->directory.'/rescue-*'));
        $this->assertStringContainsString('"repair_needed": false', $this->runTool()['output']);
        $this->assertStringContainsString('reauth', file_get_contents($this->directory.'/runtime/UpdateWeb.php'));
    }

    public function test_unknown_code_corrupt_backup_and_symbolic_links_are_rejected_before_repair(): void
    {
        $web = $this->directory.'/runtime/UpdateWeb.php';
        $original = file_get_contents($web);
        file_put_contents($web, $original."\n// unknown code\n");
        $this->assertSame(1, $this->runTool(['--repair'])['exit']);
        $this->assertSame([], glob($this->directory.'/rescue-*'));
        file_put_contents($web, $original);
        $rows = $this->directory.'/tasks/'.str_repeat('a', 32).'/backup/database/fixture.rows';
        file_put_contents($rows, 'corrupt');
        $this->assertSame(1, $this->runTool(['--repair'])['exit']);
        unlink($rows);
        symlink($this->directory.'/journal.json', $rows);
        $this->assertSame(1, $this->runTool(['--repair'])['exit']);
        unlink($rows);
        $this->assertSame([], glob($this->directory.'/rescue-*'));
    }

    public function test_busy_lock_incomplete_confirmation_wrong_task_and_finished_tasks_never_restore(): void
    {
        $lock = fopen($this->directory.'/update.lock', 'r+');
        flock($lock, LOCK_EX);
        try {
            $this->assertSame(1, $this->runTool()['exit']);
            $this->assertSame(1, $this->runTool(['--repair'])['exit']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $before = $this->snapshot();
        $this->assertSame(1, $this->runTool(['--restore='.str_repeat('a', 32)])['exit']);
        $this->assertSame(1, $this->runTool(['--restore='.str_repeat('b', 32), '--confirm-database-rollback'])['exit']);
        $this->assertSame(1, $this->runTool(['--restore='.str_repeat('a', 32), '--confirm-database-rollback'])['exit']);
        $this->assertSame($before, $this->snapshot());
        $journal = json_decode(file_get_contents($this->directory.'/journal.json'), true);
        $journal['status'] = 'complete';
        file_put_contents($this->directory.'/journal.json', json_encode($journal));
        $this->assertSame(1, $this->runTool(['--repair'])['exit']);
    }

    public function test_current_release_runtime_is_supported_without_a_patch(): void
    {
        foreach (['UpdateRunner', 'UpdateWeb'] as $class) {
            copy(dirname(__DIR__, 2).'/app/Services/Updates/'.$class.'.php', $this->directory.'/runtime/'.$class.'.php');
        }
        $result = $this->runTool();
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('"repair_needed": false', $result['output']);
    }

    public function test_previous_login_health_repair_is_preserved_while_only_the_web_entry_is_repaired(): void
    {
        $runner = $this->directory.'/runtime/UpdateRunner.php';
        copy(dirname(__DIR__, 2).'/app/Services/Updates/UpdateRunner.php', $runner);
        $hash = hash_file('sha256', $runner);
        $result = $this->runTool(['--repair']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame($hash, hash_file('sha256', $runner));
        $backup = glob($this->directory.'/rescue-*');
        $this->assertCount(1, $backup);
        $this->assertFileExists($backup[0].'/UpdateWeb.php');
        $this->assertFileDoesNotExist($backup[0].'/UpdateRunner.php');
    }

    public function test_freezing_runtime_invalidates_final_paths_even_when_opcache_timestamp_checks_are_disabled(): void
    {
        mkdir($this->root.'/app/Services/Updates', 0700, true);
        file_put_contents($this->directory.'/runtime/CacheProbe.php', '<?php return "old-runtime";');
        file_put_contents($this->root.'/app/Services/Updates/CacheProbe.php', '<?php return "new-runtime";');
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$path = $argv[2].'/storage/app/updates/runtime/CacheProbe.php';
$before = include $path;
if (! opcache_is_script_cached($path)) {
    exit(2);
}
$runner = new App\Services\Updates\UpdateRunner($argv[2]);
(new ReflectionMethod($runner, 'freezeRuntime'))->invoke($runner);
clearstatcache(true);
$after = include $path;
echo json_encode(['before' => $before, 'after' => $after]);
PHP;
        $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.file_update_protection=0',
            '-r', $code, dirname(__DIR__, 2), $this->root], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output.$error);
        $this->assertSame('', $error);
        $this->assertSame(['before' => 'old-runtime', 'after' => 'new-runtime'], json_decode($output, true, 8, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,string> */
    private function snapshot(): array
    {
        $files = [];
        foreach ((new Filesystem)->allFiles($this->directory) as $file) {
            $files[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return $files;
    }

    /** @param list<string> $arguments
     * @return array{exit:int,output:string}
     */
    private function runTool(array $arguments = []): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/app/Services/Updates/update-rescue.php', $this->root, ...$arguments], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $output];
    }
}
