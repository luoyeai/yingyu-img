<?php

namespace Tests\Unit;

use App\Services\Installation\EnvFile;
use App\Services\Installation\InstallationState;
use App\Services\Installation\Installer;
use App\Services\Updates\UpdateRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\SupportInstallerSandbox;

class InstallerIntegrationTest extends TestCase
{
    use SupportInstallerSandbox;

    protected function setUp(): void
    {
        $this->createInstallerSandbox();
    }

    protected function tearDown(): void
    {
        $this->removeInstallerSandbox();
    }

    public function test_final_multi_storage_zip_installs_and_uploads_with_its_own_production_vendor(): void
    {
        $project = dirname(__DIR__, 2);
        $archive = $project.'/release/image-vault-20261030-update-v1-1-4-production.zip';
        if (! is_file($archive)) {
            $this->markTestSkipped('Build final 1.1.4 full ZIP before acceptance.');
        }
        $this->removeSandboxTree($this->sandboxRoot);
        mkdir($this->sandboxRoot, 0700);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($archive));
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $relative = substr($name, strlen('image-vault/'));
            if ($relative === '') {
                continue;
            }
            $path = $this->sandboxRoot.'/'.$relative;
            if (str_ends_with($name, '/')) {
                if (! is_dir($path)) {
                    mkdir($path, str_starts_with($relative, 'storage/') ? 0700 : 0755, true);
                }
            } else {
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0755, true);
                }
                file_put_contents($path, $zip->getFromIndex($index));
                chmod($path, 0644);
            }
        }
        $zip->close();
        $this->assertFalse(is_link($this->sandboxRoot.'/vendor'));
        $code = <<<'PHP'
$d=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
require 'vendor/autoload.php';
$s=new App\Services\Installation\InstallationState(getcwd());
$i=new App\Services\Installation\Installer(getcwd());
$r=$i->install($d,$s->ensureOwnerToken());
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=App\Models\StorageConfiguration::where('driver','local')->firstOrFail();
$webp=new Imagick;$webp->newImage(20,12,'transparent','webp');
$source=storage_path('app/tmp/acceptance.webp');
if(!is_dir(dirname($source))){mkdir(dirname($source),0700,true);}
$webp->writeImage($source);$webp->clear();
$upload=new Illuminate\Http\UploadedFile($source,'acceptance.webp',test:true);
$image=$app->make(App\Services\ImageStorage::class)->upload($upload,App\Models\Setting::uploadOptions(),null,$c->id)['image'];
$metadata=json_decode(file_get_contents('vendor/composer/installed.json'),true);
echo json_encode(['installed'=>$r['installed'],'state'=>$s->inspect()['status'],'program'=>json_decode(file_get_contents('release.json'),true)['program'],'recognized'=>(new App\Services\Updates\UpdateRunner(getcwd(),config('database.connections.mysql')))->current()['program'],'packages'=>count($metadata['packages']),'dev'=>$metadata['dev'],'factory'=>class_exists('Database\Factories\StorageConfigurationFactory'),'sdk'=>class_exists('Aws\S3\S3Client')&&class_exists('OSS\Signer\SignerV4'),'configurations'=>App\Models\StorageConfiguration::count(),'operations'=>Illuminate\Support\Facades\DB::table('storage_operations')->count(),'image_local'=>$image->storage_configuration_id===$c->id,'url_local'=>str_starts_with($image->url,$d['app_url'].'/media/'),'webp'=>getimagesize(public_path('media/'.$image->path))['mime']==='image/webp','hash'=>hash_file('sha256',public_path('media/'.$image->path))===$image->content_sha256]);
PHP;
        $environment = getenv();
        foreach (array_keys($environment) as $name) {
            if (preg_match('/\A(?:APP_|DB_|IMAGE_|CACHE_|SESSION_)/', $name)) {
                unset($environment[$name]);
            }
        }
        $process = proc_open([$project.'/bin/php-local', '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->sandboxRoot, $environment);
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode($this->sandboxInput(), JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), 'Independent production installation/upload failed; private output suppressed.');
        $this->assertSame(['installed' => true, 'state' => 'installed', 'program' => '1.1.4', 'recognized' => '1.1.4', 'packages' => 82, 'dev' => false, 'factory' => false, 'sdk' => true, 'configurations' => 1, 'operations' => 0, 'image_local' => true, 'url_local' => true, 'webp' => true, 'hash' => true], json_decode($output, true, 64, JSON_THROW_ON_ERROR));
        $this->assertFileDoesNotExist($this->sandboxRoot.'/storage/app/installation/owner-token');
        $this->assertSame(0600, fileperms($this->sandboxRoot.'/.env') & 0777);
    }

    public function test_real_new_installation_without_env_key_creates_unique_admin_and_persistent_lock(): void
    {
        $installer = new Installer($this->sandboxRoot);
        $this->assertFileDoesNotExist($this->sandboxRoot.'/.env');
        $this->assertTrue($installer->checkDatabase($this->sandboxCredentials, $this->sandboxToken)['empty']);
        $result = $installer->install($this->sandboxInput(), $this->sandboxToken);
        $this->assertTrue($result['installed']);
        $env = (new EnvFile($this->sandboxRoot))->read();
        $this->assertSame($this->sandboxInput()['app_name'], $env['APP_NAME']);
        $this->assertStringStartsWith('base64:', $env['APP_KEY']);
        $this->assertSame($this->sandboxDatabase, $env['DB_DATABASE']);
        $users = $this->sandboxRootPdo->query('SELECT email,password FROM `'.$this->sandboxDatabase.'`.users')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $users);
        $this->assertTrue(password_verify($this->sandboxInput()['admin_password'], $users[0]['password']));
        $this->assertSame('installed', (new InstallationState($this->sandboxRoot))->inspect()['status']);
        $this->assertFileDoesNotExist($this->sandboxRoot.'/storage/app/installation/owner-token');
        $this->assertSame(0600, fileperms($this->sandboxRoot.'/.env') & 0777);
        try {
            $installer->install($this->sandboxInput(), $this->sandboxToken);
            $this->fail('reinstall accepted');
        } catch (\Throwable $e) {
            $this->assertNotSame('reinstall accepted', $e->getMessage());
        }
    }

    public function test_new_release_installation_records_program_and_schema_without_reclassifying_legacy(): void
    {
        $release = ['program' => '1.0.0', 'schema' => ['known_migration'], 'managed_files' => ['artisan', 'release.json']];
        file_put_contents($this->sandboxRoot.'/release.json', json_encode($release, JSON_THROW_ON_ERROR));
        $result = (new Installer($this->sandboxRoot))->install($this->sandboxInput(), $this->sandboxToken);
        $this->assertTrue($result['installed']);
        $installed = json_decode(file_get_contents($this->sandboxRoot.'/storage/app/installation/installed.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('1.0.0', $installed['program']);
        $this->assertSame($release['schema'], $installed['schema']);
        $this->assertSame($release['managed_files'], $installed['managed_files']);
        $connection = Installer::connect(Installer::normalizeDatabase($this->sandboxCredentials));
        $this->assertSame(UpdateRunner::schemaFingerprint($connection), $installed['schema_fingerprint']);
        $this->assertFileDoesNotExist($this->sandboxRoot.'/storage/app/updates/version.json');
    }

    public function test_sealing_an_installed_release_preserves_its_identity_before_first_update(): void
    {
        $schema = array_map(static fn (string $path): string => basename($path, '.php'), glob($this->sandboxRoot.'/database/migrations/*.php'));
        sort($schema);
        $release = ['program' => '1.1.2', 'schema' => $schema, 'managed_files' => ['artisan', 'release.json']];
        file_put_contents($this->sandboxRoot.'/release.json', json_encode($release, JSON_THROW_ON_ERROR));
        $this->assertTrue((new Installer($this->sandboxRoot))->install($this->sandboxInput(), $this->sandboxToken)['installed']);
        $installedPath = $this->sandboxRoot.'/storage/app/installation/installed.json';
        $before = file_get_contents($installedPath);
        $this->assertFileDoesNotExist($this->sandboxRoot.'/storage/app/updates/version.json');
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
exit($kernel->call('vault:seal-installation', ['--no-interaction' => true], new Symfony\Component\Console\Output\NullOutput));
PHP;
        $environment = getenv();
        foreach (array_keys($environment) as $name) {
            if (preg_match('/\A(?:APP_|DB_|IMAGE_|CACHE_|SESSION_)/', $name)) {
                unset($environment[$name]);
            }
        }
        $process = proc_open([dirname(__DIR__, 2).'/bin/php-local', '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->sandboxRoot, $environment);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), 'Sealing the isolated installation failed; private output suppressed.');
        $this->assertSame($before, file_get_contents($installedPath));
        $connection = Installer::normalizeDatabase($this->sandboxCredentials);
        $runner = new UpdateRunner($this->sandboxRoot, [
            'driver' => 'mysql', 'host' => $connection['db_host'], 'port' => $connection['db_port'],
            'database' => $connection['db_database'], 'username' => $connection['db_username'], 'password' => $connection['db_password'],
        ]);
        $this->assertSame('1.1.2', $runner->current()['program']);
    }

    public function test_nonempty_database_wrong_connection_and_shared_admin_lock_refuse_without_mutations(): void
    {
        $installer = new Installer($this->sandboxRoot);
        $bad = $this->sandboxCredentials;
        $bad['db_password'] = 'wrong';
        try {
            $installer->checkDatabase($bad, $this->sandboxToken);
            $this->fail('wrong credentials');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('wrong', $e->getMessage());
        }
        $this->sandboxRootPdo->query("SELECT GET_LOCK('image_vault_admin_init',0)");
        try {
            $installer->checkDatabase($this->sandboxCredentials, $this->sandboxToken);
            $this->fail('lock bypass');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('锁', $e->getMessage());
        } finally {
            $this->sandboxRootPdo->query("SELECT RELEASE_LOCK('image_vault_admin_init')");
        }
        $this->sandboxRootPdo->exec('CREATE TABLE `'.$this->sandboxDatabase.'`.unrelated (id int primary key)');
        $this->sandboxRootPdo->exec('INSERT INTO `'.$this->sandboxDatabase.'`.unrelated VALUES (7)');
        try {
            $installer->install($this->sandboxInput(), $this->sandboxToken);
            $this->fail('nonempty accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('空库', $e->getMessage());
        }
        $this->assertSame(7, $this->sandboxRootPdo->query('SELECT id FROM `'.$this->sandboxDatabase.'`.unrelated')->fetchColumn());
        $this->assertFileDoesNotExist($this->sandboxRoot.'/.env');
    }

    public function test_partial_ddl_failure_restores_original_environment_and_preserves_recovery_journal(): void
    {
        $before = "APP_NAME=before\nAPP_KEY=\n";
        file_put_contents($this->sandboxRoot.'/.env', $before);
        $pdo = $this->sandboxRootPdo;
        $db = $this->sandboxDatabase;
        $installer = new Installer($this->sandboxRoot, function () use ($pdo, $db) {
            $pdo->exec('CREATE TABLE `'.$db.'`.partial_marker (id int)');
            throw new RuntimeException('sensitive query password');
        });
        try {
            $installer->install($this->sandboxInput(), $this->sandboxToken);
            $this->fail('must fail');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('sensitive', $e->getMessage());
        }
        $state = new InstallationState($this->sandboxRoot);
        $this->assertSame('partial', $state->inspect()['status']);
        $this->assertSame($before, file_get_contents($this->sandboxRoot.'/.env'));
        $this->assertFileExists($state->path('pending.env'));
        $this->assertFileDoesNotExist($state->path('installed.json'));
        $this->assertFileDoesNotExist($this->sandboxRoot.'/storage/logs/laravel.log');
        $journal = file_get_contents($state->path('journal.json'));
        $this->assertStringNotContainsString($this->sandboxInput()['admin_password'], $journal);
        $this->assertStringNotContainsString($this->sandboxCredentials['db_password'], $journal);
        $this->assertNotFalse($pdo->query('SHOW TABLES FROM `'.$db.'`')->fetchColumn());
    }

    public function test_environment_publication_failure_removes_only_owned_admin_and_restores_original_config(): void
    {
        $before = "APP_NAME=before\nAPP_KEY=\n";
        file_put_contents($this->sandboxRoot.'/.env', $before);
        $installer = new Installer($this->sandboxRoot);
        $environment = new class($this->sandboxRoot) extends EnvFile
        {
            private bool $failNext = true;

            public function writeAtomic(array|string $values): void
            {
                if ($this->failNext) {
                    $this->failNext = false;
                    throw new RuntimeException('injected private publication failure');
                }
                parent::writeAtomic($values);
            }
        };
        (new \ReflectionProperty(Installer::class, 'environment'))->setValue($installer, $environment);
        try {
            $installer->install($this->sandboxInput(), $this->sandboxToken);
            $this->fail('publication must fail');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('injected private', $e->getMessage());
        }
        $state = new InstallationState($this->sandboxRoot);
        $journal = $state->readJournal();
        $this->assertSame('partial', $state->inspect()['status']);
        $this->assertSame($before, file_get_contents($this->sandboxRoot.'/.env'));
        $this->assertSame(0, (int) $this->sandboxRootPdo->query('SELECT COUNT(*) FROM `'.$this->sandboxDatabase.'`.users')->fetchColumn());
        $this->assertTrue($journal['owned_administrator_removed']);
        $this->assertTrue($journal['environment_restored']);
        $this->assertSame('database_ready', $journal['failed_stage']);
        $this->assertFileExists($state->path('pending.env'));
        $this->assertFileDoesNotExist($state->path('installed.json'));
        $this->assertFileDoesNotExist($this->sandboxRoot.'/storage/logs/laravel.log');
    }

    public function test_real_special_character_database_credentials_install_and_round_trip_without_logging(): void
    {
        $username = 'iv_inst_'.bin2hex(random_bytes(6));
        $password = <<<'PASSWORD'
Literal # " ' \ ${DB_HOST} ${APP_KEY} $dollar = 83!
PASSWORD;
        $account = $this->sandboxRootPdo->quote($username)."@'127.0.0.1'";
        $this->sandboxRootPdo->exec('CREATE USER '.$account.' IDENTIFIED BY '.$this->sandboxRootPdo->quote($password));
        try {
            $this->sandboxRootPdo->exec('GRANT ALL PRIVILEGES ON `'.$this->sandboxDatabase.'`.* TO '.$account);
            $input = [...$this->sandboxInput(), 'db_username' => $username, 'db_password' => $password];
            $installer = new Installer($this->sandboxRoot);
            $result = $installer->installPrepared($installer->validate($input), $this->sandboxToken);
            $this->assertTrue($result['installed']);
            $env = (new EnvFile($this->sandboxRoot))->read();
            $this->assertSame($password, $env['DB_PASSWORD']);
            $this->assertSame($username, $env['DB_USERNAME']);
            $connection = Installer::connect(Installer::normalizeDatabase(['db_host' => $env['DB_HOST'], 'db_port' => $env['DB_PORT'], 'db_database' => $env['DB_DATABASE'], 'db_username' => $env['DB_USERNAME'], 'db_password' => $env['DB_PASSWORD']]));
            $this->assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn());
            $connection = null;
            $journal = file_get_contents((new InstallationState($this->sandboxRoot))->path('journal.json'));
            $this->assertStringNotContainsString($password, $journal);
            $this->assertStringNotContainsString($input['admin_password'], $journal);
            $this->assertFileDoesNotExist($this->sandboxRoot.'/storage/logs/laravel.log');
        } finally {
            $this->sandboxRootPdo->exec('DROP USER '.$account);
        }
    }
}
