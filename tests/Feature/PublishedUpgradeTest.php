<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Services\Installation\EnvFile;
use App\Services\Installation\InstallationState;
use App\Services\Updates\UpdateRunner;
use App\Services\Updates\UpdateState;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class PublishedUpgradeTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $sandbox = null;

    private ?string $fixtureKey = null;

    public function test_enabled_captcha_login_renders_in_a_fresh_cli_process(): void
    {
        $root = $this->baseline();
        copy(base_path('resources/views/login.blade.php'), $root.'/resources/views/login.blade.php');
        $probe = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app["view"]->share("errors", new Illuminate\Support\ViewErrorBag);
if (function_exists("vault_asset_version")) { exit(4); }
try {
    $html = $app["view"]->make("login", ["geetestEnabled" => true, "geetestId" => str_repeat("a", 32)])->render();
    echo json_encode(["rendered" => str_contains($html, "data-geetest-login") && str_contains($html, "assets/geetest.js?v=")]);
} catch (Throwable $error) {
    echo json_encode(["rendered" => false, "error" => $error->getMessage()]);
}
PHP;
        $result = $this->phpProcess($root, $probe);
        $this->assertTrue($result['rendered'], $result['error'] ?? '');
    }

    public function test_published_100_engine_reproduces_101_health_failure_and_restores(): void
    {
        $root = $this->baseline();
        $result = $this->exercise($root, '1.0.1', true);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('health', $result['stage']);
        $this->assertStringContainsString('启动健康检查失败', $result['failure']);
        $this->assertTrue($result['legacy_login_error']);
        $this->assertSame('restored', $result['restored']);
        $this->assertSame('1.0.0', $result['version']);
        $this->assertSame(['prepared', 'files', 'migrate', 'health'], $result['stages']);
        $this->assertSame(file_get_contents($root.'/resources/views/login.blade.php'), $this->publishedLogin());
        $this->assertPreserved($root);
        $retry = $this->exercise($root, '1.0.2', false);
        $this->assertSame('complete', $retry['status'], $retry['failure'] ?? '');
        $this->assertSame('1.0.2', $retry['version']);
        $this->assertPreserved($root);
    }

    public function test_published_100_engine_upgrades_to_102_through_health_and_preserves_data(): void
    {
        $root = $this->baseline();
        $result = $this->exercise($root, '1.0.2', false);
        $this->assertSame('complete', $result['status'], $result['failure'] ?? '');
        $this->assertSame('complete', $result['stage']);
        $this->assertSame('1.0.2', $result['version']);
        $this->assertSame(['prepared', 'files', 'migrate', 'health'], $result['stages']);
        $this->assertPreserved($root);
    }

    public function test_published_100_engine_upgrades_to_103_through_health_and_preserves_data(): void
    {
        $root = $this->baseline();
        $result = $this->exercise($root, '1.0.3', false);
        $this->assertSame('complete', $result['status'], $result['failure'] ?? '');
        $this->assertSame('complete', $result['stage']);
        $this->assertSame('1.0.3', $result['version']);
        $this->assertSame(['prepared', 'files', 'migrate', 'health'], $result['stages']);
        $this->assertPreserved($root);
    }

    public function test_delivered_102_engine_upgrades_to_103_then_enabled_104_and_preserves_data(): void
    {
        $root = $this->baseline();
        $previous = $this->exercise($root, '1.0.2', false);
        $this->assertSame('complete', $previous['status'], $previous['failure'] ?? '');
        $this->assertSame('1.0.2', $previous['version']);
        $result = $this->exercise($root, '1.0.3', false);
        $this->assertSame('complete', $result['status'], $result['failure'] ?? '');
        $this->assertSame('complete', $result['stage']);
        $this->assertSame('1.0.3', $result['version']);
        $this->assertSame(['prepared', 'files', 'migrate', 'health'], $result['stages']);
        $this->assertPreserved($root);
        $configuration = $this->enableGeetest();
        $fixed = $this->exercise($root, '1.0.4', false);
        $this->assertSame('complete', $fixed['status'], $fixed['failure'] ?? '');
        $this->assertSame('1.0.4', $fixed['version']);
        $this->assertSame($configuration, Setting::value('geetest'));
        $this->assertPreserved($root);
        $upgrade = $this->exercise($root, '1.0.5', false);
        $this->assertSame('complete', $upgrade['status'], $upgrade['failure'] ?? '');
        $this->assertSame('1.0.5', $upgrade['version']);
        $this->assertSame($configuration, Setting::value('geetest'));
        $this->assertPreserved($root);
    }

    public function test_enabled_captcha_103_failure_and_restore_are_repaired_without_disabling_captcha(): void
    {
        $root = $this->baseline();
        $this->assertSame('complete', $this->exercise($root, '1.0.2', false)['status']);
        $configuration = $this->enableGeetest();
        $result = $this->exercise($root, '1.0.3', true);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('health', $result['stage']);
        $this->assertTrue($result['asset_helper_error']);
        $this->assertSame('failed', $result['restored'], 'old 1.0.2 restore reproduces the same cold-process failure');
        $this->assertSame($configuration, Setting::value('geetest'));
        $runtime = $root.'/storage/app/updates/runtime/UpdateRunner.php';
        $original = hash_file('sha256', $runtime);
        $journal = file_get_contents($root.'/storage/app/updates/journal.json');
        $this->assertSame(0, $this->repair($root, false)['exit']);
        $this->assertSame($original, hash_file('sha256', $runtime), 'default check does not write the runtime');
        $this->assertSame([], glob($root.'/storage/app/updates/repair-login-health-*'));
        $originalBody = file_get_contents($runtime);
        file_put_contents($runtime, $originalBody."\n// unknown runtime\n");
        $unknown = hash_file('sha256', $runtime);
        $this->assertSame(1, $this->repair($root, true)['exit'], 'unknown runtime code is refused');
        $this->assertSame($unknown, hash_file('sha256', $runtime));
        file_put_contents($runtime, $originalBody);
        $lock = fopen($root.'/storage/app/updates/update.lock', 'r+');
        flock($lock, LOCK_EX);
        try {
            $this->assertSame(1, $this->repair($root, true)['exit'], 'concurrent recovery is refused');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $metadata = stat($runtime);
        $this->assertSame(0, $this->repair($root, true)['exit']);
        $this->assertNotSame($original, hash_file('sha256', $runtime));
        clearstatcache(true, $runtime);
        $this->assertSame([$metadata['uid'], $metadata['gid'], $metadata['mode'] & 0777], [fileowner($runtime), filegroup($runtime), fileperms($runtime) & 0777]);
        $this->assertSame($journal, file_get_contents($root.'/storage/app/updates/journal.json'));
        $backups = glob($root.'/storage/app/updates/repair-login-health-*/UpdateRunner.php');
        $this->assertCount(1, $backups);
        $this->assertSame($original, hash_file('sha256', $backups[0]));
        $this->assertSame(0, $this->repair($root, true)['exit'], 'reapplying the exact repair is idempotent');
        $restored = $this->phpProcess($root, <<<'PHP'
require "storage/app/updates/runtime/UpdateRunner.php";
require "vendor/autoload.php";
$state = new App\Services\Updates\UpdateState(getcwd());
$runner = new App\Services\Updates\UpdateRunner(getcwd(), null, $argv[1]);
echo json_encode($runner->restore($state->read("journal.json")["id"]));
PHP, [base_path('bin/php-local')]);
        $this->assertSame('restored', $restored['status'], $restored['error'] ?? '');
        $this->assertSame($configuration, Setting::value('geetest'));
        $this->assertPreserved($root);
        $this->assertSame(1, $this->repair($root, true)['exit'], 'a restored task cannot be patched');
        $upgraded = $this->exercise($root, '1.0.4', false);
        $this->assertSame('complete', $upgraded['status'], $upgraded['failure'] ?? '');
        $this->assertSame('1.0.4', $upgraded['version']);
        $this->assertSame($configuration, Setting::value('geetest'));
        $this->assertPreserved($root);
    }

    public function test_published_100_engine_upgrades_with_enabled_captcha_to_104(): void
    {
        $root = $this->baseline();
        $configuration = $this->enableGeetest();
        $result = $this->exercise($root, '1.0.4', false);
        $this->assertSame('complete', $result['status'], $result['failure'] ?? '');
        $this->assertSame('1.0.4', $result['version']);
        $this->assertSame($configuration, Setting::value('geetest'));
        $this->assertPreserved($root);
    }

    public function test_published_100_engine_upgrades_with_enabled_captcha_to_105(): void
    {
        $root = $this->baseline();
        $configuration = $this->enableGeetest();
        $result = $this->exercise($root, '1.0.5', false);
        $this->assertSame('complete', $result['status'], $result['failure'] ?? '');
        $this->assertSame('1.0.5', $result['version']);
        $this->assertSame($configuration, Setting::value('geetest'));
        $this->assertPreserved($root);
    }

    public function test_owner_rescue_checks_repairs_and_restores_a_real_failed_upgrade_without_disabling_captcha(): void
    {
        $root = $this->baseline();
        $this->assertSame('complete', $this->exercise($root, '1.0.2', false)['status']);
        $configuration = $this->enableGeetest();
        $failure = $this->exercise($root, '1.0.3', false);
        $this->assertSame('failed', $failure['status']);
        $this->assertSame('health', $failure['stage']);
        $state = new UpdateState($root);
        $task = $state->read('journal.json')['id'];
        $journal = file_get_contents($state->directory().'/journal.json');
        $check = $this->rescue($root);
        $this->assertSame(0, $check['exit'], $check['output']);
        $this->assertStringContainsString('file-hashes-verified', $check['output']);
        $this->assertSame($journal, file_get_contents($state->directory().'/journal.json'));
        $repair = $this->rescue($root, ['--repair']);
        $this->assertSame(0, $repair['exit'], $repair['output']);
        $this->assertSame($journal, file_get_contents($state->directory().'/journal.json'));
        $this->assertSame($configuration, Setting::value('geetest'));
        $restored = $this->rescue($root, ['--restore='.$task, '--confirm-database-rollback']);
        $this->assertSame(0, $restored['exit'], $restored['output']);
        $this->assertSame('restored', $state->read('journal.json')['status']);
        $this->assertSame('1.0.2', $state->version()['program']);
        $this->assertSame($configuration, Setting::value('geetest'));
        $this->assertPreserved($root);
        $upgrade = $this->exercise($root, '1.0.5', false);
        $this->assertSame('complete', $upgrade['status'], $upgrade['failure'] ?? '');
        $this->assertSame('1.0.5', $upgrade['version']);
        $this->assertSame($configuration, Setting::value('geetest'));
        $this->assertPreserved($root);
    }

    /** @param list<string> $arguments
     * @return array{exit:int,output:string}
     */
    private function rescue(string $root, array $arguments = []): array
    {
        $process = proc_open([base_path('bin/php-local'), base_path('app/Services/Updates/update-rescue.php'), $root, ...$arguments], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $output];
    }

    public function test_current_engine_retains_integrity_checks_and_restores_an_enabled_captcha_baseline(): void
    {
        $root = $this->baseline();
        $this->assertSame('complete', $this->exercise($root, '1.0.2', false)['status']);
        $configuration = $this->enableGeetest();
        foreach (['UpdateRunner', 'UpdateWeb'] as $class) {
            copy(base_path('app/Services/Updates/'.$class.'.php'), $root.'/app/Services/Updates/'.$class.'.php');
        }
        $result = $this->phpProcess($root, <<<'PHP'
require "vendor/autoload.php";
$runner = new App\Services\Updates\UpdateRunner(getcwd(), null, $argv[2]);
$status = $runner->prepare($argv[1]);
for ($step = 0; $step < 8 && $status['stage'] !== 'health' && $status['status'] !== 'failed'; $step++) {
    $status = $runner->advance($status['id'], $status['stage']);
}
file_put_contents("public/assets/app.css", "\n/* invalid signed payload fixture */\n", FILE_APPEND);
$failed = $runner->advance($status['id'], 'health');
$restored = $runner->restore($status['id']);
echo json_encode(["failed" => $failed['status'], "integrity_rejected" => str_contains($failed['error'] ?? '', '签名清单不一致'), "restored" => $restored['status']], JSON_THROW_ON_ERROR);
PHP, [base_path('release/local-v1.0.3/image-vault-1.0.3-signed-update.zip'), base_path('bin/php-local')]);
        $this->assertSame('failed', $result['failed']);
        $this->assertTrue($result['integrity_rejected']);
        $this->assertSame('restored', $result['restored']);
        $this->assertSame($configuration, Setting::value('geetest'));
        $this->assertPreserved($root);
    }

    private function enableGeetest(): array
    {
        $encrypter = new Encrypter(base64_decode(substr($this->fixtureKey, 7)), config('app.cipher'));
        $configuration = ['enabled' => true, 'captcha_id' => str_repeat('a', 32),
            'encrypted_key' => $encrypter->encryptString(str_repeat('b', 32)), 'revision' => 'cold-process-fixture'];
        Setting::put('geetest', $configuration);

        return Setting::value('geetest');
    }

    /** @return array{exit:int,output:string} */
    private function repair(string $root, bool $apply): array
    {
        $command = [base_path('bin/php-local'), base_path('bin/repair-login-health.php'), $root];
        if ($apply) {
            $command[] = '--apply';
        }
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $output];
    }

    private function baseline(): string
    {
        $this->sandbox = sys_get_temp_dir().'/yingyu-published-upgrade-'.bin2hex(random_bytes(8));
        mkdir($this->sandbox, 0700, true);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(base_path('release/github-v1.0.0/yingyu-img-1.0.0.zip')));
        $this->assertTrue($zip->extractTo($this->sandbox));
        $zip->close();
        $root = $this->sandbox.'/image-vault';
        $release = json_decode(file_get_contents($root.'/release.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('1.0.0', $release['program']);
        $this->assertSame('ea3d963d04587b6f544b274981fee2ba39603f9fa41b8feeeb588a68cd187956', hash_file('sha256', $root.'/app/Services/Updates/UpdateRunner.php'));

        $database = config('database.connections.mysql');
        $this->assertSame(['127.0.0.1', 13368, 'image_vault_test'], [$database['host'], (int) $database['port'], $database['database']]);
        $this->fixtureKey = 'base64:'.base64_encode(random_bytes(32));
        (new EnvFile($root))->writeAtomic([
            'APP_NAME' => 'Upgrade fixture', 'APP_ENV' => 'production', 'APP_DEBUG' => false,
            'APP_KEY' => $this->fixtureKey, 'APP_URL' => 'http://localhost',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $database['host'], 'DB_PORT' => $database['port'],
            'DB_DATABASE' => $database['database'], 'DB_USERNAME' => $database['username'],
            'DB_PASSWORD' => $database['password'], 'DB_URL' => '', 'DB_SOCKET' => '',
            'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ]);
        $version = ['program' => '1.0.0', 'baseline' => '1.0.0', 'schema' => $release['schema'],
            'managed_files' => $release['managed_files'], 'schema_fingerprint' => UpdateRunner::schemaFingerprint(DB::connection()->getPdo())];
        (new UpdateState($root))->write('version.json', $version);
        (new UpdateState($root))->write('connection.json', $database);
        $installation = new InstallationState($root);
        $installation->ensureDirectory();
        file_put_contents($installation->path('installed.json'), json_encode($version + ['format' => 1, 'installation_id' => 'fixture', 'installed_at' => gmdate('c')], JSON_THROW_ON_ERROR));
        User::factory()->create(['email' => 'upgrade-fixture@example.invalid']);
        Group::create(['name' => 'Preserved fixture group']);
        Setting::put('upgrade_fixture', ['preserved' => true]);
        $image = new \Imagick;
        $image->newImage(2, 2, new \ImagickPixel('white'));
        $image->setImageFormat('webp');
        if (! is_dir($root.'/public/media')) {
            mkdir($root.'/public/media', 0755, true);
        }
        $image->writeImage($root.'/public/media/fixture.webp');
        $image->clear();

        return $root;
    }

    /** @return array{status:string,stage:string,failure:?string,restored:?string,version:string,stages:list<string>,legacy_login_error:bool,asset_helper_error:bool} */
    private function exercise(string $root, string $version, bool $restore): array
    {
        $probe = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$runner = new App\Services\Updates\UpdateRunner($argv[1], null, $argv[3]);
$status = $runner->prepare($argv[2]);
$stages = [];
for ($step = 0; $step < 8 && !in_array($status['status'], ['complete', 'failed'], true); $step++) {
    $stages[] = $status['stage'];
    $status = $runner->advance($status['id'], $status['stage']);
}
$legacyLoginError = false;
$assetHelperError = false;
foreach (glob($argv[1].'/storage/logs/*.log') ?: [] as $log) {
    $legacyLoginError = $legacyLoginError || str_contains(file_get_contents($log), 'Undefined variable $geetestEnabled');
    $assetHelperError = $assetHelperError || str_contains(file_get_contents($log), 'Call to undefined function vault_asset_version()');
}
$restored = null;
if ($argv[4] === 'restore' && $status['status'] === 'failed') {
    $restored = $runner->restore($status['id'])['status'];
}
$version = (new App\Services\Updates\UpdateState($argv[1]))->version();
echo json_encode(['status' => $status['status'], 'stage' => $status['stage'], 'failure' => $status['error'] ?? null, 'restored' => $restored, 'version' => $version['program'], 'stages' => $stages, 'legacy_login_error' => $legacyLoginError, 'asset_helper_error' => $assetHelperError], JSON_THROW_ON_ERROR);
PHP;
        $directory = in_array($version, ['1.0.3', '1.0.4', '1.0.5'], true) ? 'local-v'.$version : 'github-v'.$version;
        $package = base_path('release/'.$directory.'/image-vault-'.$version.'-signed-update.zip');

        return $this->phpProcess($root, $probe, [$root, $package, base_path('bin/php-local'), $restore ? 'restore' : 'upgrade']);
    }

    /** @param list<string> $arguments
     * @return array<string,mixed>
     */
    private function phpProcess(string $root, string $probe, array $arguments = []): array
    {
        $environment = getenv();
        foreach (array_keys($environment) as $name) {
            if (preg_match('/\A(APP_|DB_|CACHE_|SESSION_|QUEUE_|MAIL_|IMAGE_)/', $name)) {
                unset($environment[$name]);
            }
        }
        $pipes = [];
        $process = proc_open([base_path('bin/php-local'), '-r', $probe, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);

        return json_decode($output, true, 64, JSON_THROW_ON_ERROR);
    }

    private function publishedLogin(): string
    {
        $zip = new ZipArchive;
        $zip->open(base_path('release/github-v1.0.0/yingyu-img-1.0.0.zip'));
        $body = $zip->getFromName('image-vault/resources/views/login.blade.php');
        $zip->close();

        return $body;
    }

    private function assertPreserved(string $root): void
    {
        DB::purge('mysql');
        $this->assertDatabaseHas('users', ['email' => 'upgrade-fixture@example.invalid']);
        $this->assertDatabaseHas('groups', ['name' => 'Preserved fixture group']);
        $this->assertSame(['preserved' => true], Setting::value('upgrade_fixture'));
        $image = new \Imagick($root.'/public/media/fixture.webp');
        $this->assertSame('WEBP', $image->getImageFormat());
        $this->assertSame(2, $image->getImageWidth());
        $image->clear();
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== null && is_dir($this->sandbox)) {
            (new Filesystem)->deleteDirectory($this->sandbox);
        }
        parent::tearDown();
    }
}
