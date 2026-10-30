<?php

namespace Tests\Unit;

use App\Services\Installation\EnvFile;
use App\Services\Updates\DatabaseSnapshot;
use App\Services\Updates\UpdatePackage;
use App\Services\Updates\UpdateRunner;
use App\Services\Updates\UpdateState;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class UpdateRunnerTest extends TestCase
{
    private string $root;

    private string $database;

    private PDO $admin;

    private PDO $pdo;

    private array $connection;

    private mixed $key;

    private string $project;

    protected function setUp(): void
    {
        $this->project = dirname(__DIR__, 2);
        $credentials = json_decode(file_get_contents($this->project.'/.tools/runtime/mysql/credentials.json'), true, 32, JSON_THROW_ON_ERROR);
        if ($credentials['host'] !== '127.0.0.1' || (int) $credentials['port'] !== 13368 || $credentials['database'] !== 'image_vault') {
            throw new RuntimeException('Refused updater fixture database.');
        }
        $this->database = 'image_vault_updatetest_'.bin2hex(random_bytes(6));
        $this->admin = new PDO('mysql:host=127.0.0.1;port=13368;charset=utf8mb4', 'root', $credentials['root_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->admin->exec('CREATE DATABASE `'.$this->database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->admin->exec('GRANT ALL PRIVILEGES ON `'.$this->database.'`.* TO '.$this->admin->quote($credentials['username'])."@'127.0.0.1'");
        $this->connection = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 13368, 'database' => $this->database, 'username' => $credentials['username'], 'password' => $credentials['password']];
        $this->pdo = new PDO('mysql:host=127.0.0.1;port=13368;dbname='.$this->database.';charset=utf8mb4', $credentials['username'], $credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->root = realpath(sys_get_temp_dir()).'/vault-updater-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        foreach (['app', 'bootstrap', 'config', 'database', 'lang', 'resources', 'routes', 'public/assets', 'public/index.php', 'public/robots.txt', 'artisan', 'composer.json', 'composer.lock'] as $path) {
            $this->copyTree($this->project.'/'.$path, $this->root.'/'.$path);
        }
        $this->copyTree($this->project.'/vendor', $this->root.'/vendor');
        foreach (glob($this->root.'/database/migrations/*_create_storage_*_table.php') as $migration) {
            unlink($migration);
        }
        foreach (['storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache/data', 'storage/logs', 'public/media'] as $path) {
            mkdir($this->root.'/'.$path, 0700, true);
        }
        $env = ['APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_ENV' => 'testing', 'APP_URL' => 'http://127.0.0.1', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => 13368, 'DB_DATABASE' => $this->database, 'DB_USERNAME' => $credentials['username'], 'DB_PASSWORD' => $credentials['password'], 'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file'];
        (new EnvFile($this->root))->writeAtomic($env);
        $this->php(['artisan', 'migrate', '--force', '--no-interaction']);
        $this->pdo->prepare('INSERT INTO users (name,email,password,singleton) VALUES (?,?,?,1)')->execute(['Fixture', 'fixture@example.invalid', password_hash('FixturePassword-12345', PASSWORD_BCRYPT)]);
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        file_put_contents($this->root.'/bootstrap/update-public.pem', openssl_pkey_get_details($this->key)['key']);
        $legacy = json_decode(file_get_contents($this->root.'/bootstrap/update-legacy.json'), true, 64, JSON_THROW_ON_ERROR);
        $legacy['schema_sha256'] = UpdateRunner::schemaFingerprint($this->pdo);
        file_put_contents($this->root.'/bootstrap/update-legacy.json', json_encode($legacy));
        $schema = $this->pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        $managed = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($this->root) + 1);
            if ($file->isFile() && UpdatePackage::allowedPath($relative)) {
                $managed[] = $relative;
            }
        }
        $managed[] = 'release.json';
        file_put_contents($this->root.'/release.json', json_encode(['program' => '1.0.0', 'schema' => $schema, 'managed_files' => $managed]));
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && preg_match('/\Aimage_vault_updatetest_[a-f0-9]{12}\z/D', $this->database)) {
            $this->admin->exec('DROP DATABASE `'.$this->database.'`');
        }
        if (isset($this->root)) {
            $this->removeTree($this->root);
        }
    }

    public function test_new_installation_identity_requires_matching_metadata_and_schema_without_weakening_legacy(): void
    {
        $releasePath = $this->root.'/release.json';
        $installedPath = $this->root.'/storage/app/installation/installed.json';
        mkdir(dirname($installedPath), 0700, true);
        $release = json_decode(file_get_contents($releasePath), true, 64, JSON_THROW_ON_ERROR);
        $release['program'] = '1.1.0';
        $installed = [...$release, 'schema_fingerprint' => UpdateRunner::schemaFingerprint($this->pdo)];
        file_put_contents($releasePath, json_encode($release, JSON_THROW_ON_ERROR));
        file_put_contents($installedPath, json_encode($installed, JSON_THROW_ON_ERROR));
        $this->assertSame('1.1.0', $this->runner()->current()['program']);
        unlink($this->root.'/storage/app/updates/version.json');
        $reject = function (): void {
            try {
                $this->runner()->current();
                $this->fail('Untrusted installation identity accepted.');
            } catch (RuntimeException) {
                $this->assertFileDoesNotExist($this->root.'/storage/app/updates/version.json');
            }
        };
        foreach (['program', 'schema', 'managed_files'] as $field) {
            $changed = $installed;
            $changed[$field] = $field === 'program' ? '1.1.1' : [];
            file_put_contents($installedPath, json_encode($changed, JSON_THROW_ON_ERROR));
            $reject();
            file_put_contents($installedPath, json_encode($installed, JSON_THROW_ON_ERROR));
            $changed = $release;
            $changed[$field] = $field === 'program' ? '1.1.1' : [];
            file_put_contents($releasePath, json_encode($changed, JSON_THROW_ON_ERROR));
            $reject();
            file_put_contents($releasePath, json_encode($release, JSON_THROW_ON_ERROR));
        }
        $this->pdo->exec('ALTER TABLE images ADD COLUMN unexpected_fixture INT NULL');
        $reject();
        unset($installed['schema_fingerprint']);
        file_put_contents($installedPath, json_encode($installed, JSON_THROW_ON_ERROR));
        $reject();
    }

    /** @return array<string,array{string,string}> */
    public static function multiStorageUpdateSources(): array
    {
        return [
            '1.0.0' => ['image-vault-20261028-update-v1-final-production.zip', '1.0.0'],
            '1.0.1' => ['image-vault-20261028-update-v1-0-1-production.zip', '1.0.1'],
            '1.0.2' => ['image-vault-20261028-update-v1-0-2-production.zip', '1.0.2'],
        ];
    }

    #[DataProvider('multiStorageUpdateSources')]
    public function test_official_multi_storage_update_runs_each_released_frozen_engine_with_production_vendor(string $source, string $from): void
    {
        $this->installOfficialRelease($source, $from);
        $path = $this->seedReleaseMedia();
        $beforeImage = $this->pdo->query('SELECT * FROM images')->fetch(PDO::FETCH_ASSOC);
        $beforeUsers = $this->pdo->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC);
        $beforeSettings = $this->pdo->query('SELECT * FROM settings ORDER BY `key`')->fetchAll(PDO::FETCH_ASSOC);
        $beforeEnv = hash_file('sha256', $this->root.'/.env');
        $beforeMedia = hash_file('sha256', $this->root.'/public/media/'.$path);
        $engine = hash_file('sha256', $this->root.'/app/Services/Updates/UpdateRunner.php');
        $input = json_encode(['root' => $this->root, 'connection' => $this->connection, 'php' => $this->project.'/bin/php-local', 'package' => $this->project.'/release/image-vault-1.1.0-signed-update.zip'], JSON_THROW_ON_ERROR);
        $code = <<<'PHP'
$d=json_decode(stream_get_contents(STDIN),true);chdir($d['root']);require 'vendor/autoload.php';
$r=new App\Services\Updates\UpdateRunner($d['root'],$d['connection'],$d['php']);
$s=$r->prepare($d['package']);$frozen=hash_file('sha256',$d['root'].'/storage/app/updates/runtime/UpdateRunner.php');
for($step=0;$step<4;$step++){$s=$r->advance($s['id'],$s['stage']);}
$metadata=json_decode(file_get_contents('vendor/composer/installed.json'),true);
echo json_encode(['status'=>$s['status'],'version'=>$r->current()['program'],'frozen'=>$frozen,'packages'=>count($metadata['packages']),'dev'=>$metadata['dev']]);
PHP;
        $this->assertSame(['status' => 'complete', 'version' => '1.1.0', 'frozen' => $engine, 'packages' => 82, 'dev' => false], $this->compatibilityProcess($code, $input));
        $image = $this->pdo->query('SELECT * FROM images')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($beforeImage, array_intersect_key($image, $beforeImage));
        $this->assertNull($image['content_sha256']);
        $this->assertSame('local', $this->pdo->query('SELECT driver FROM storage_configurations WHERE id='.(int) $image['storage_configuration_id'])->fetchColumn());
        $this->assertSame($beforeUsers, $this->pdo->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame($beforeSettings, $this->pdo->query('SELECT * FROM settings ORDER BY `key`')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame($beforeEnv, hash_file('sha256', $this->root.'/.env'));
        $this->assertSame($beforeMedia, hash_file('sha256', $this->root.'/public/media/'.$path));
        $this->assertFileExists($this->root.'/storage/app/previews/'.$path);
        $this->assertFileExists($this->root.'/storage/app/originals/'.$path);
        $expected = json_decode(file_get_contents($this->root.'/release.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame($expected['schema'], $this->pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM storage_operations')->fetchColumn());
        $code = '$d=json_decode(stream_get_contents(STDIN),true);chdir($d["root"]);require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$i=App\\Models\\Image::firstOrFail();echo json_encode(["url"=>$i->url,"factory"=>class_exists("Database\\Factories\\StorageConfigurationFactory")]);';
        $this->assertSame(['url' => 'http://127.0.0.1/media/'.$path, 'factory' => false], $this->compatibilityProcess($code, $input));
    }

    public function test_official_1_1_0_frozen_engine_installs_1_1_1_and_only_verified_current_transition_is_cleaned(): void
    {
        $archive = $this->project.'/release/image-vault-1.1.1-signed-update.zip';
        if (! is_file($archive)) {
            $this->markTestSkipped('Build official 1.1.1 artifacts before transition acceptance.');
        }
        $this->installOfficialRelease('image-vault-20261029-update-v1-1-0-production.zip', '1.1.0');
        $path = $this->seedReleaseMedia();
        $before = [];
        foreach (['users', 'settings', 'images', 'storage_configurations', 'storage_operations'] as $table) {
            $before[$table] = $this->pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
        }
        $environment = hash_file('sha256', $this->root.'/.env');
        $media = hash_file('sha256', $this->root.'/public/media/'.$path);
        $engine = hash_file('sha256', $this->root.'/app/Services/Updates/UpdateRunner.php');
        $key = hash_file('sha256', $this->root.'/bootstrap/update-public.pem');
        $history = [];
        for ($index = 1; $index <= 5; $index++) {
            $history[] = $this->root.'/storage/app/updates/tasks/'.str_repeat((string) $index, 32);
            foreach (['payload', 'backup'] as $part) {
                mkdir(end($history).'/'.$part, 0700, true);
                file_put_contents(end($history).'/'.$part.'/evidence', 'keep');
            }
        }
        $input = json_encode(['root' => $this->root, 'connection' => $this->connection, 'php' => $this->project.'/bin/php-local', 'package' => $archive], JSON_THROW_ON_ERROR);
        $code = <<<'PHP'
$d=json_decode(stream_get_contents(STDIN),true);chdir($d['root']);require 'vendor/autoload.php';
$r=new App\Services\Updates\UpdateRunner($d['root'],$d['connection'],$d['php']);
$s=$r->prepare($d['package']);$frozen=hash_file('sha256','storage/app/updates/runtime/UpdateRunner.php');
for($step=0;$step<4;$step++){$s=$r->advance($s['id'],$s['stage']);}
$j=(new App\Services\Updates\UpdateState($d['root']))->read('journal.json');
echo json_encode(['status'=>$s['status'],'version'=>$r->current()['program'],'frozen'=>$frozen,'proof'=>isset($j['completion']),'payload'=>is_dir('storage/app/updates/tasks/'.$s['id'].'/payload'),'backup'=>is_dir('storage/app/updates/tasks/'.$s['id'].'/backup')]);
PHP;
        $this->assertSame(['status' => 'complete', 'version' => '1.1.1', 'frozen' => $engine, 'proof' => false, 'payload' => true, 'backup' => true], $this->compatibilityProcess($code, $input));
        $this->assertSame($key, hash_file('sha256', $this->root.'/bootstrap/update-public.pem'));
        $this->assertSame(hash_file('sha256', $this->project.'/app/Services/Updates/UpdateRunner.php'), hash_file('sha256', $this->root.'/app/Services/Updates/UpdateRunner.php'));
        $state = new UpdateState($this->root);
        $journal = $state->read('journal.json');
        $task = $state->directory().'/tasks/'.$journal['id'];
        $asset = $this->root.'/public/assets/app.js';
        $valid = file_get_contents($asset);
        file_put_contents($asset, 'corrupt transition fixture');
        $cleanup = <<<'PHP'
$d=json_decode(stream_get_contents(STDIN),true);chdir($d['root']);require 'vendor/autoload.php';
$r=new App\Services\Updates\UpdateRunner($d['root'],$d['connection'],$d['php']);$result=$r->cleanupCompleted();
$j=(new App\Services\Updates\UpdateState($d['root']))->read('journal.json');
echo json_encode(['result'=>$result,'status'=>$j['status'],'active'=>$j['active'],'cleanup'=>$j['cleanup']['status']??null,'proof'=>$j['completion']['verified_by']??null]);
PHP;
        $this->assertSame(['result' => ['completed' => 0, 'pending' => 1, 'skipped' => false], 'status' => 'complete', 'active' => false, 'cleanup' => 'pending', 'proof' => null], $this->compatibilityProcess($cleanup, $input));
        $this->assertDirectoryExists($task.'/payload');
        $this->assertFileExists($task.'/backup/database/users.rows');
        file_put_contents($asset, $valid);
        $this->assertSame(['result' => ['completed' => 1, 'pending' => 0, 'skipped' => false], 'status' => 'complete', 'active' => false, 'cleanup' => 'complete', 'proof' => '1.1.1-maintenance'], $this->compatibilityProcess($cleanup, $input));
        $this->assertDirectoryDoesNotExist($task.'/payload');
        $this->assertDirectoryDoesNotExist($task.'/backup');
        $this->assertSame('complete', $state->read('task-'.$journal['id'].'.json')['cleanup']['status']);
        foreach ($history as $directory) {
            $this->assertSame('keep', file_get_contents($directory.'/payload/evidence'));
            $this->assertSame('keep', file_get_contents($directory.'/backup/evidence'));
        }
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $this->pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC), $table);
        }
        $this->assertSame($environment, hash_file('sha256', $this->root.'/.env'));
        $this->assertSame($media, hash_file('sha256', $this->root.'/public/media/'.$path));
        $this->assertFileExists($this->root.'/storage/app/previews/'.$path);
        $this->assertFileExists($this->root.'/storage/app/originals/'.$path);

        file_put_contents($this->root.'/bootstrap/update-public.pem', openssl_pkey_get_details($this->key)['key']);
        $current = $this->runner()->current();
        $next = $this->package('1.1.2', ['min' => '1.1.1', 'max' => '1.1.1'], [], $current['schema']);
        $input = json_encode(['root' => $this->root, 'connection' => $this->connection, 'php' => $this->project.'/bin/php-local', 'package' => $next], JSON_THROW_ON_ERROR);
        $code = <<<'PHP'
$d=json_decode(stream_get_contents(STDIN),true);chdir($d['root']);require 'vendor/autoload.php';
$r=new App\Services\Updates\UpdateRunner($d['root'],$d['connection'],$d['php']);$s=$r->prepare($d['package']);
$frozen=hash_file('sha256','storage/app/updates/runtime/UpdateRunner.php');
for($step=0;$step<4;$step++){$s=$r->advance($s['id'],$s['stage']);}
echo json_encode(['status'=>$s['status'],'version'=>$r->current()['program'],'cleanup'=>$s['cleanup']['status']??null,'frozen'=>$frozen,'payload'=>is_dir('storage/app/updates/tasks/'.$s['id'].'/payload'),'backup'=>is_dir('storage/app/updates/tasks/'.$s['id'].'/backup')]);
PHP;
        $this->assertSame(['status' => 'complete', 'version' => '1.1.2', 'cleanup' => 'complete', 'frozen' => hash_file('sha256', $this->project.'/app/Services/Updates/UpdateRunner.php'), 'payload' => false, 'backup' => false], $this->compatibilityProcess($code, $input));
        $this->assertSame('keep', file_get_contents($history[0].'/backup/evidence'));
    }

    public function test_final_multi_storage_production_failure_restores_encrypted_configurations_operations_and_media(): void
    {
        $this->installOfficialRelease('image-vault-20261029-update-v1-1-0-production.zip', '1.1.0');
        $path = $this->seedReleaseMedia();
        $beforeEnv = hash_file('sha256', $this->root.'/.env');
        $beforeMedia = hash_file('sha256', $this->root.'/public/media/'.$path);
        $this->php(['-r', 'require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$c=App\\Models\\StorageConfiguration::create(["name"=>"Isolated fixture","driver"=>"s3","region"=>"us-east-1","endpoint"=>"https://storage.example.invalid","bucket"=>"fixture-bucket","prefix"=>"fixture","public_url"=>"https://cdn.example.invalid","enabled"=>true,"credentials"=>["key"=>"fixture-key","secret"=>"fixture-secret"]]);Illuminate\\Support\\Facades\\DB::table("storage_operations")->insert(["storage_configuration_id"=>$c->id,"operation"=>"test_cleanup","path"=>"_checks/fixture.webp","payload"=>"{}","state"=>"unknown","attempts"=>2,"created_at"=>now(),"updated_at"=>now()]);']);
        $before = [];
        foreach (['users', 'settings', 'images', 'storage_configurations', 'storage_operations'] as $table) {
            $before[$table] = $this->pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
        }
        file_put_contents($this->root.'/bootstrap/update-public.pem', openssl_pkey_get_details($this->key)['key']);
        $runner = $this->runner();
        $baseline = $runner->current();
        $name = '2026_10_29_100001_release_failure_fixture';
        $migration = '<?php return new class extends Illuminate\\Database\\Migrations\\Migration{public function up():void{Illuminate\\Support\\Facades\\Schema::create("release_failed_fixture",function(Illuminate\\Database\\Schema\\Blueprint $t){$t->id();});Illuminate\\Support\\Facades\\DB::table("storage_operations")->delete();throw new RuntimeException("fixture failure");}public function down():void{}};';
        $package = $this->package('1.1.1', ['min' => '1.1.0', 'max' => '1.1.0'], ['database/migrations/'.$name.'.php' => $migration], [...$baseline['schema'], $name]);
        $input = json_encode(['root' => $this->root, 'connection' => $this->connection, 'php' => $this->project.'/bin/php-local', 'package' => $package], JSON_THROW_ON_ERROR);
        $code = '$d=json_decode(stream_get_contents(STDIN),true);chdir($d["root"]);require "vendor/autoload.php";$r=new App\\Services\\Updates\\UpdateRunner($d["root"],$d["connection"],$d["php"]);$s=$r->prepare($d["package"]);for($i=0;$i<3;$i++){$s=$r->advance($s["id"],$s["stage"]);}$failed=$s["status"];$s=$r->restore($s["id"]);echo json_encode(["failed"=>$failed,"restored"=>$s["status"],"version"=>$r->current()["program"]]);';
        $this->assertSame(['failed' => 'failed', 'restored' => 'restored', 'version' => '1.1.0'], $this->compatibilityProcess($code, $input));
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $this->pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC), $table);
        }
        $this->assertSame($beforeEnv, hash_file('sha256', $this->root.'/.env'));
        $this->assertSame($beforeMedia, hash_file('sha256', $this->root.'/public/media/'.$path));
        $code = <<<'PHP'
$d=json_decode(stream_get_contents(STDIN),true);chdir($d['root']);require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=App\Models\StorageConfiguration::where('driver','s3')->firstOrFail();$decrypted=$c->credentials['secret']==='fixture-secret';
$manager=new class(new App\Services\StorageEndpoint) extends App\Services\StorageManager {
public function store(App\Models\StorageConfiguration $configuration):App\Services\ObjectStore{return new class implements App\Services\ObjectStore{public function put(string $k,string $s):void{}public function download(string $k,string $p,int $maxBytes=134217728):void{}public function copy(string $s,string $d):void{}public function head(string $k):?array{return null;}public function delete(string $k):void{}public function assertSafeBucket():void{}};}};
$remote=new App\Services\RemoteImageStorage($manager);$result=$app->make(App\Services\StorageMutex::class)->run(fn()=>$remote->retryPending());echo json_encode(['decrypted'=>$decrypted,'retried'=>$result,'pending'=>Illuminate\Support\Facades\DB::table('storage_operations')->count(),'dev'=>json_decode(file_get_contents('vendor/composer/installed.json'),true)['dev']]);
PHP;
        $this->assertSame(['decrypted' => true, 'retried' => ['completed' => 1, 'failed' => 0], 'pending' => 0, 'dev' => false], $this->compatibilityProcess($code, $input));

        $name = '2026_10_29_100002_release_success_fixture';
        $migration = '<?php return new class extends Illuminate\\Database\\Migrations\\Migration{public function up():void{Illuminate\\Support\\Facades\\Schema::create("release_success_fixture",function(Illuminate\\Database\\Schema\\Blueprint $t){$t->id();$t->string("value");});Illuminate\\Support\\Facades\\DB::table("release_success_fixture")->insert(["value"=>"completed"]);}public function down():void{}};';
        $package = $this->package('1.1.1', ['min' => '1.1.0', 'max' => '1.1.0'], ['database/migrations/'.$name.'.php' => $migration], [...$baseline['schema'], $name]);
        $input = json_encode(['root' => $this->root, 'connection' => $this->connection, 'php' => $this->project.'/bin/php-local', 'package' => $package], JSON_THROW_ON_ERROR);
        $code = <<<'PHP'
$d=json_decode(stream_get_contents(STDIN),true);chdir($d['root']);require 'vendor/autoload.php';
$r=new App\Services\Updates\UpdateRunner($d['root'],$d['connection'],$d['php']);$s=$r->prepare($d['package']);$stages=[];
for($step=0;$step<4;$step++){$s=$r->advance($s['id'],$s['stage']);$stages[]=$s['stage'];}
$metadata=json_decode(file_get_contents('vendor/composer/installed.json'),true);
echo json_encode(['status'=>$s['status'],'version'=>$r->current()['program'],'stages'=>$stages,'packages'=>count($metadata['packages']),'dev'=>$metadata['dev']]);
PHP;
        $this->assertSame(['status' => 'complete', 'version' => '1.1.1', 'stages' => ['files', 'migrate', 'health', 'complete'], 'packages' => 82, 'dev' => false], $this->compatibilityProcess($code, $input));
        $this->assertSame('completed', $this->pdo->query('SELECT value FROM release_success_fixture')->fetchColumn());
        $this->assertSame([...$baseline['schema'], $name], $this->pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame($beforeEnv, hash_file('sha256', $this->root.'/.env'));
        $this->assertSame($beforeMedia, hash_file('sha256', $this->root.'/public/media/'.$path));
    }

    /** @return array<string,array{string,string}> */
    public static function auditReleaseSources(): array
    {
        return [
            '1.1.1' => ['image-vault-20261029-update-v1-1-1-production.zip', '1.1.1'],
            '1.1.2' => ['image-vault-20261029-update-v1-1-2-production.zip', '1.1.2'],
            '1.1.3' => ['image-vault-20261029-update-v1-1-3-production.zip', '1.1.3'],
        ];
    }

    #[DataProvider('auditReleaseSources')]
    public function test_official_released_engine_installs_1_1_4_and_cleans_only_successful_task(string $source, string $from): void
    {
        $this->installOfficialRelease($source, $from);
        if ($from === '1.1.1') {
            $this->assertFileDoesNotExist($this->root.'/public/assets/upload.js');
        }
        $path = $this->seedReleaseMedia();
        $before = [];
        foreach (['users', 'settings', 'images', 'storage_configurations', 'storage_operations'] as $table) {
            $before[$table] = $this->pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
        }
        $hashes = [];
        foreach (['.env', 'public/media/'.$path, 'storage/app/previews/'.$path, 'storage/app/originals/'.$path] as $relative) {
            $hashes[$relative] = hash_file('sha256', $this->root.'/'.$relative);
        }
        $history = $this->root.'/storage/app/updates/tasks/'.str_repeat('a', 32);
        mkdir($history.'/backup', 0700, true);
        file_put_contents($history.'/backup/evidence', 'preserve unknown task');
        $engine = hash_file('sha256', $this->root.'/app/Services/Updates/UpdateRunner.php');
        $input = json_encode(['root' => $this->root, 'connection' => $this->connection, 'php' => $this->project.'/bin/php-local', 'package' => $this->project.'/release/image-vault-1.1.4-signed-update.zip'], JSON_THROW_ON_ERROR);
        $code = <<<'PHP'
$d=json_decode(stream_get_contents(STDIN),true);chdir($d['root']);require 'vendor/autoload.php';
$r=new App\Services\Updates\UpdateRunner($d['root'],$d['connection'],$d['php']);
$s=$r->prepare($d['package']);$frozen=hash_file('sha256','storage/app/updates/runtime/UpdateRunner.php');
for($step=0;$step<4;$step++){$s=$r->advance($s['id'],$s['stage']);}
$state=new App\Services\Updates\UpdateState($d['root']);$j=$state->read('journal.json');$receipt=$state->read('task-'.$s['id'].'.json');
echo json_encode(['status'=>$s['status'],'version'=>$r->current()['program'],'frozen'=>$frozen,'active'=>$j['active'],'proof'=>isset($j['completion']),'cleanup'=>$receipt['cleanup']['status'],'payload'=>is_dir('storage/app/updates/tasks/'.$s['id'].'/payload'),'backup'=>is_dir('storage/app/updates/tasks/'.$s['id'].'/backup')]);
PHP;
        $this->assertSame(['status' => 'complete', 'version' => '1.1.4', 'frozen' => $engine, 'active' => false, 'proof' => true, 'cleanup' => 'complete', 'payload' => false, 'backup' => false], $this->compatibilityProcess($code, $input));
        $this->assertSame('preserve unknown task', file_get_contents($history.'/backup/evidence'));
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $this->pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC), $table);
        }
        foreach ($hashes as $relative => $hash) {
            $this->assertSame($hash, hash_file('sha256', $this->root.'/'.$relative));
        }
        $this->assertSame(hash_file('sha256', $this->project.'/public/assets/upload.js'), hash_file('sha256', $this->root.'/public/assets/upload.js'));
        $code = <<<'PHP'
$d=json_decode(stream_get_contents(STDIN),true);chdir($d['root']);require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$request=Illuminate\Http\Request::create('/','GET');$route=$app['router']->getRoutes()->getByName('upload');$request->setRouteResolver(fn()=>$route);$app->instance('request',$request);Illuminate\Support\Facades\Auth::login(App\Models\User::firstOrFail());
$html=$app->make(App\Http\Controllers\VaultController::class)->uploadPage()->with('errors',new Illuminate\Support\ViewErrorBag)->render();
$upload=strpos($html,'assets/upload.js?v=');$appScript=strpos($html,'assets/app.js?v=');
echo json_encode(['order'=>$upload!==false&&$appScript!==false&&$upload<$appScript,'versioned'=>str_contains($html,'assets/upload.js?v='.vault_asset_version(getcwd(),'assets/upload.js')),'export'=>str_contains($html,'id="export-csv"')&&str_contains($html,'id="export-json"')]);
PHP;
        $this->assertSame(['order' => true, 'versioned' => true, 'export' => true], $this->compatibilityProcess($code, $input));
    }

    private function installOfficialRelease(string $source, string $version): void
    {
        $archive = $this->project.'/release/'.$source;
        if (! is_file($archive) || ! is_file($this->project.'/release/image-vault-1.1.0-signed-update.zip')) {
            $this->markTestSkipped('Build official 1.1.0 artifacts before compatibility acceptance.');
        }
        $this->removeTree($this->root);
        mkdir($this->root, 0700);
        $this->admin->exec('DROP DATABASE `'.$this->database.'`');
        $this->admin->exec('CREATE DATABASE `'.$this->database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->pdo->exec('USE `'.$this->database.'`');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive));
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $relative = substr($name, strlen('image-vault/'));
            if ($relative === '') {
                continue;
            }
            $path = $this->root.'/'.$relative;
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
        $input = ['db_host' => '127.0.0.1', 'db_port' => 13368, 'db_database' => $this->database, 'db_username' => $this->connection['username'], 'db_password' => $this->connection['password'], 'app_name' => 'Release fixture', 'app_url' => 'http://127.0.0.1', 'admin_email' => 'fixture@example.invalid', 'admin_password' => 'FixturePassword-12345', 'admin_password_confirmation' => 'FixturePassword-12345'];
        $code = '$d=json_decode(stream_get_contents(STDIN),true);require "vendor/autoload.php";$s=new App\\Services\\Installation\\InstallationState(getcwd());$r=(new App\\Services\\Installation\\Installer(getcwd()))->install($d,$s->ensureOwnerToken());$m=json_decode(file_get_contents("vendor/composer/installed.json"),true);echo json_encode(["installed"=>$r["installed"],"version"=>json_decode(file_get_contents("release.json"),true)["program"],"dev"=>$m["dev"]]);';
        $this->assertSame(['installed' => true, 'version' => $version, 'dev' => false], $this->compatibilityProcess($code, json_encode($input, JSON_THROW_ON_ERROR)));
        $this->assertFalse(is_link($this->root.'/vendor'));
        $this->assertFileDoesNotExist($this->root.'/vendor/phpunit/phpunit/composer.json');
    }

    private function seedReleaseMedia(): string
    {
        $path = '2099/01/'.str_repeat('c', 32).'.webp';
        $image = new \Imagick;
        $image->newImage(2, 2, 'transparent', 'webp');
        foreach (['public/media', 'storage/app/previews', 'storage/app/originals'] as $directory) {
            $destination = $this->root.'/'.$directory.'/'.$path;
            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0700, true);
            }
            $image->writeImage($destination);
        }
        $image->clear();
        $this->pdo->prepare('INSERT INTO images (original_name,display_name,path,preview_path,original_path,mime,source_width,source_height,width,height,source_hash,processing) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(['legacy.webp', 'legacy', $path, $path, $path, 'image/webp', 2, 2, 2, 2, str_repeat('1', 64), '{}']);
        $this->pdo->prepare('INSERT INTO settings (`key`,value) VALUES (?,?)')->execute(['release_fixture', json_encode('preserve this setting')]);

        return $path;
    }

    public function test_official_old_engine_installs_patch_and_next_frozen_runtime_is_self_contained(): void
    {
        $this->assertOfficialPatch('image-vault-20261028-update-v1-final-production.zip', '1.0.0', '1.0.1');
    }

    /** @return array<string,array{string,string}> */
    public static function simplifiedUpdateSources(): array
    {
        return [
            '1.0.0' => ['image-vault-20261028-update-v1-final-production.zip', '1.0.0'],
            '1.0.1' => ['image-vault-20261028-update-v1-0-1-production.zip', '1.0.1'],
        ];
    }

    #[DataProvider('simplifiedUpdateSources')]
    public function test_official_simplified_update_supports_both_released_engines(string $source, string $from): void
    {
        $this->assertOfficialPatch($source, $from, '1.0.2');
    }

    private function assertOfficialPatch(string $source, string $from, string $target): void
    {
        $archive = $this->project.'/release/image-vault-'.$target.'-signed-update.zip';
        if (! is_file($archive)) {
            $this->markTestSkipped('Build the official target artifact before its compatibility test.');
        }
        $old = new ZipArchive;
        $this->assertTrue($old->open($this->project.'/release/'.$source));
        for ($index = 0; $index < $old->numFiles; $index++) {
            $name = $old->getNameIndex($index);
            if (str_ends_with($name, '/')) {
                continue;
            }
            $relative = substr($name, strlen('image-vault/'));
            $path = $this->root.'/'.$relative;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $old->getFromIndex($index));
            chmod($path, 0644);
        }
        $old->close();
        if ($from === '1.0.0') {
            unlink($this->root.'/app/Services/Updates/UpdateRequirements.php');
        }
        $release = json_decode(file_get_contents($this->root.'/release.json'), true, 64, JSON_THROW_ON_ERROR);
        $state = new UpdateState($this->root);
        $state->write('version.json', [...$release, 'baseline' => $from, 'schema_fingerprint' => UpdateRunner::schemaFingerprint($this->pdo)]);
        $input = json_encode(['root' => $this->root, 'connection' => $this->connection, 'php' => $this->project.'/bin/php-local', 'package' => $archive], JSON_THROW_ON_ERROR);
        $code = '$d=json_decode(stream_get_contents(STDIN),true); chdir($d["root"]); require "vendor/autoload.php"; $r=new App\\Services\\Updates\\UpdateRunner($d["root"],$d["connection"],$d["php"]); $s=$r->prepare($d["package"]); for($i=0;$i<4;$i++){$s=$r->advance($s["id"],$s["stage"]);} echo json_encode(["status"=>$s["status"],"version"=>$r->current()["program"]]);';
        $this->assertSame(['status' => 'complete', 'version' => $target], $this->compatibilityProcess($code, $input));
        $code = '$d=json_decode(stream_get_contents(STDIN),true); chdir($d["root"]); require "vendor/autoload.php"; $r=new App\\Services\\Updates\\UpdateRunner($d["root"],$d["connection"],$d["php"]); (new ReflectionMethod($r,"freezeRuntime"))->invoke($r); echo json_encode(["frozen"=>is_file($d["root"]."/storage/app/updates/runtime/UpdateRequirements.php")]);';
        $this->assertSame(['frozen' => true], $this->compatibilityProcess($code, $input));
        file_put_contents($this->root.'/vendor/autoload.php', '<?php throw new RuntimeException("broken vendor");');
        $code = '$d=json_decode(stream_get_contents(STDIN),true); $base=$d["root"]."/storage/app/updates/runtime/"; foreach(["UpdateState","UpdatePackage","DatabaseSnapshot","UpdateRunner"] as $c){require $base.$c.".php";} $c=(new App\\Services\\Updates\\UpdateRequirements($d["php"]))->checks(); echo json_encode(["standalone"=>!in_array(false,array_column($c,"ok"),true)]);';
        $this->assertSame(['standalone' => true], $this->compatibilityProcess($code, $input));
        $this->assertSame($release['schema'], $this->pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN));
    }

    private function compatibilityProcess(string $code, string $input): array
    {
        $environment = getenv();
        foreach (array_keys($environment) as $name) {
            if (preg_match('/\A(?:APP_|DB_|IMAGE_|CACHE_|SESSION_)/', $name)) {
                unset($environment[$name]);
            }
        }
        $process = proc_open([$this->project.'/bin/php-local', '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->root, $environment);
        $this->assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);

        return json_decode($output, true, 64, JSON_THROW_ON_ERROR);
    }

    public function test_storage_migrations_preserve_legacy_media_and_snapshot_recovers_pending_operations(): void
    {
        $path = '2099/01/'.str_repeat('a', 32).'.webp';
        mkdir($this->root.'/public/media/2099/01', 0755, true);
        $webp = new \Imagick;
        $webp->newImage(2, 2, 'red', 'webp');
        $webp->writeImage($this->root.'/public/media/'.$path);
        $webp->clear();
        $beforeHash = hash_file('sha256', $this->root.'/public/media/'.$path);
        $envHash = hash_file('sha256', $this->root.'/.env');
        $this->pdo->prepare('INSERT INTO images (original_name,display_name,path,preview_path,mime,source_width,source_height,width,height,source_hash,processing) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(['legacy.webp', 'legacy', $path, $path, 'image/webp', 2, 2, 2, 2, str_repeat('1', 64), '{}']);
        $before = $this->pdo->query('SELECT * FROM images')->fetch(PDO::FETCH_ASSOC);
        $runner = $this->runner();
        $baseline = $runner->current();
        $extra = [];
        $schema = $baseline['schema'];
        foreach (glob($this->project.'/database/migrations/*_create_storage_*_table.php') as $migration) {
            $extra['database/migrations/'.basename($migration)] = file_get_contents($migration);
            $schema[] = basename($migration, '.php');
        }
        $status = $runner->prepare($this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], $extra, $schema));
        for ($step = 0; $step < 4; $step++) {
            $status = $runner->advance($status['id'], $status['stage']);
        }
        $this->assertSame('complete', $status['status'], json_encode($status));
        $after = $this->pdo->query('SELECT * FROM images')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertNull($after['content_sha256']);
        $this->assertSame('local', $this->pdo->query('SELECT driver FROM storage_configurations WHERE id='.(int) $after['storage_configuration_id'])->fetchColumn());
        $this->assertSame($beforeHash, hash_file('sha256', $this->root.'/public/media/'.$path));
        $this->assertSame($envHash, hash_file('sha256', $this->root.'/.env'));
        $this->php(['-r', 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $c=App\\Models\\StorageConfiguration::factory()->create(); Illuminate\\Support\\Facades\\DB::table("storage_operations")->insert(["storage_configuration_id"=>$c->id,"operation"=>"upload_cleanup","path"=>"2099/01/'.str_repeat('b', 32).'.webp","payload"=>"{}","state"=>"unknown","attempts"=>2,"created_at"=>now(),"updated_at"=>now()]);']);
        $operations = $this->pdo->query('SELECT * FROM storage_operations')->fetchAll(PDO::FETCH_ASSOC);
        $configs = $this->pdo->query('SELECT * FROM storage_configurations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $name = '2026_10_29_000001_storage_failure_fixture';
        $migration = '<?php return new class extends Illuminate\\Database\\Migrations\\Migration {public function up():void {Illuminate\\Support\\Facades\\Schema::create("storage_failed_fixture",function(Illuminate\\Database\\Schema\\Blueprint $t){$t->id();});throw new RuntimeException("fixture failure");}public function down():void{}};';
        $status = $runner->prepare($this->package('1.0.1', ['min' => '1.0.0', 'max' => '1.0.0'], ['database/migrations/'.$name.'.php' => $migration], [...$schema, $name]));
        for ($step = 0; $step < 3; $step++) {
            $status = $runner->advance($status['id'], $status['stage']);
        }
        $this->assertSame('failed', $status['status']);
        $this->assertSame('restored', $runner->restore($status['id'])['status']);
        $this->assertSame($operations, $this->pdo->query('SELECT * FROM storage_operations')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame($configs, $this->pdo->query('SELECT * FROM storage_configurations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame($beforeHash, hash_file('sha256', $this->root.'/public/media/'.$path));
        $this->assertSame($envHash, hash_file('sha256', $this->root.'/.env'));
        $this->php(['-r', 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $c=App\\Models\\StorageConfiguration::where("driver","s3")->firstOrFail(); if($c->credentials["secret"]!=="fixture-secret"){exit(2);} $fake=new class implements App\\Services\\ObjectStore {public function put(string $k,string $s):void{}public function download(string $k,string $p,int $maxBytes=134217728):void{}public function copy(string $s,string $d):void{}public function head(string $k):?array{return null;}public function delete(string $k):void{}public function assertSafeBucket():void{}}; $manager=Mockery::mock(App\\Services\\StorageManager::class);$manager->shouldReceive("store")->andReturn($fake);$remote=new App\\Services\\RemoteImageStorage($manager);$app->make(App\\Services\\StorageMutex::class)->run(fn()=>$remote->retryPending());if(Illuminate\\Support\\Facades\\DB::table("storage_operations")->count()!==0){exit(3);}']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM storage_operations')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM images')->fetchColumn());
    }

    public function test_legacy_baseline_and_cumulative_migrations_and_duplicate_requests(): void
    {
        $state = new UpdateState($this->root);
        $runner = $this->runner();
        $legacy = $runner->current();
        $this->assertSame('0.0.0', $legacy['program']);
        $this->assertSame('legacy-20261028', $legacy['baseline']);
        $state->write('version.json', [...$legacy, 'program' => '1.0.0', 'baseline' => '1.0.0']);
        $migration1 = '2026_10_28_010001_add_update_fixture';
        $migration2 = '2026_10_28_010002_convert_update_fixture';
        $create = '<?php return new class extends Illuminate\Database\Migrations\Migration { public function up(): void { Illuminate\Support\Facades\Schema::create("update_fixture",function(Illuminate\Database\Schema\Blueprint $t){$t->id();$t->string("value");}); Illuminate\Support\Facades\DB::table("update_fixture")->insert(["value"=>"before"]); } public function down():void{} };';
        $convert = '<?php return new class extends Illuminate\Database\Migrations\Migration { public function up(): void { Illuminate\Support\Facades\DB::table("update_fixture")->update(["value"=>"converted"]); } public function down():void{} };';
        $zip = $this->package('1.2.0', ['min' => '1.0.0', 'max' => '1.1.9'], ["database/migrations/$migration1.php" => $create, "database/migrations/$migration2.php" => $convert], [...$legacy['schema'], $migration1, $migration2]);
        $status = $runner->prepare($zip);
        $this->assertFalse($status['active']);
        $id = $status['id'];
        $status = $runner->advance($id, 'prepared');
        $this->assertSame('files', $status['stage']);
        $this->assertSame($status, $runner->advance($id, 'prepared'));
        $status = $runner->advance($id, 'files');
        $this->assertSame('migrate', $status['stage']);
        $status = $runner->advance($id, 'migrate');
        $this->assertSame('health', $status['stage'], json_encode($status));
        $status = $runner->advance($id, 'health');
        $this->assertSame('complete', $status['status'], json_encode($status));
        $this->assertSame('converted', $this->pdo->query('SELECT value FROM update_fixture')->fetchColumn());
        $this->assertSame('1.2.0', $state->version()['program']);
        $task = $state->directory().'/tasks/'.$id;
        $this->assertDirectoryDoesNotExist($task.'/payload');
        $this->assertDirectoryDoesNotExist($task.'/backup');
        $this->assertFalse($status['backup_ready']);
        $receipt = $state->read('task-'.$id.'.json');
        $this->assertSame('complete', $receipt['cleanup']['status']);
        $this->assertSame($status['cleanup'], $receipt['cleanup']);
        $this->assertArrayNotHasKey('manifest', $receipt);
        $this->assertNotEmpty($receipt['log']);
        $this->assertFileExists($state->directory().'/runtime/UpdateRunner.php');
        $this->assertSame($status, $runner->advance($id));
    }

    public function test_installed_file_drift_fails_health_and_preserves_recovery_material(): void
    {
        $runner = $this->runner();
        $baseline = $runner->current();
        $status = $runner->prepare($this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], [], $baseline['schema']));
        for ($step = 0; $step < 3; $step++) {
            $status = $runner->advance($status['id']);
        }
        $this->assertSame('health', $status['stage']);
        file_put_contents($this->root.'/public/assets/update.js', 'damaged installed file');
        $status = $runner->advance($status['id']);
        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('签名清单', $status['error']);
        $this->assertSame('0.0.0', $runner->current()['program']);
        $task = $this->root.'/storage/app/updates/tasks/'.$status['id'];
        $this->assertDirectoryExists($task.'/payload');
        $this->assertFileExists($task.'/backup/database/users.rows');
        $this->assertSame(['completed' => 0, 'pending' => 0, 'skipped' => true], $runner->cleanupCompleted());
        $this->assertSame('restored', $runner->restore($status['id'])['status']);
    }

    public function test_cleanup_failure_stays_successful_and_receipt_retries_after_journal_is_replaced(): void
    {
        $runner = $this->runner();
        $baseline = $runner->current();
        $status = $runner->prepare($this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], [], $baseline['schema']));
        for ($step = 0; $step < 3; $step++) {
            $status = $runner->advance($status['id']);
        }
        $state = new UpdateState($this->root);
        $task = $state->directory().'/tasks/'.$status['id'];
        $outside = $this->root.'/public/media/keep.webp';
        file_put_contents($outside, 'keep');
        symlink($outside, $task.'/backup/escape');
        $status = $runner->advance($status['id']);
        $this->assertSame('complete', $status['status']);
        $this->assertFalse($status['active']);
        $this->assertSame('pending', $status['cleanup']['status']);
        $this->assertSame('1.0.0', $state->version()['program']);
        $this->assertFileExists($task.'/backup/database/users.rows');
        $this->assertDirectoryExists($task.'/payload');
        unlink($task.'/backup/escape');
        $this->assertSame($status, $runner->status());
        $this->assertDirectoryExists($task.'/payload');
        $next = $state->read('journal.json');
        $next['id'] = str_repeat('b', 32);
        $next['status'] = 'prepared';
        $next['stage'] = 'prepared';
        unset($next['cleanup'], $next['completion']);
        $state->write('journal.json', $next);
        $state->write('version.json', [...$state->version(), 'program' => '1.1.0']);
        $unknown = $state->directory().'/tasks/'.str_repeat('c', 32).'/backup';
        mkdir($unknown, 0700, true);
        file_put_contents($unknown.'/evidence', 'keep');
        $this->assertSame(['completed' => 1, 'pending' => 0, 'skipped' => false], $runner->cleanupCompleted());
        $this->assertSame($next, $state->read('journal.json'));
        $this->assertDirectoryDoesNotExist($task.'/payload');
        $this->assertDirectoryDoesNotExist($task.'/backup');
        $this->assertSame('complete', $state->read('task-'.$status['id'].'.json')['cleanup']['status']);
        $this->assertSame(2, $state->read('task-'.$status['id'].'.json')['cleanup']['attempts']);
        $this->assertSame('keep', file_get_contents($outside));
        $this->assertSame('keep', file_get_contents($unknown.'/evidence'));
        $this->assertSame(['completed' => 0, 'pending' => 0, 'skipped' => false], $runner->cleanupCompleted());
    }

    public function test_cleanup_requires_durable_version_success_and_mutex_and_rejects_unsafe_paths(): void
    {
        $runner = $this->runner();
        $state = new UpdateState($this->root);
        $baseline = $runner->current();
        $status = $runner->prepare($this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], [], $baseline['schema']));
        $id = $status['id'];
        $task = $state->directory().'/tasks/'.$id;
        $prepared = $state->read('journal.json');
        $version = [...$baseline, 'program' => '1.0.0', 'baseline' => '1.0.0'];
        $state->write('version.json', $version);
        $complete = [...$prepared, 'status' => 'complete', 'stage' => 'complete', 'active' => false,
            'completion' => ['checked_at' => gmdate('c'), 'version_sha256' => hash('sha256', json_encode($version, JSON_THROW_ON_ERROR))]];
        $legacy = $complete;
        unset($legacy['completion']);
        $state->write('journal.json', $legacy);
        $this->assertSame(['completed' => 0, 'pending' => 0, 'skipped' => false], $runner->cleanupCompleted());
        $this->assertDirectoryExists($task.'/payload');
        foreach ([['running', 'files', true], ['failed', 'health', true], ['restoring', 'restoring', true], ['complete', 'migration-running', false], ['complete', 'complete', true]] as [$result, $stage, $active]) {
            $state->write('journal.json', [...$complete, 'status' => $result, 'stage' => $stage, 'active' => $active]);
            $this->assertTrue($runner->cleanupCompleted()['skipped']);
            $this->assertDirectoryExists($task.'/payload');
        }
        $state->write('journal.json', [...$complete, 'completion' => [...$complete['completion'], 'version_sha256' => str_repeat('0', 64)]]);
        $this->assertSame(1, $runner->cleanupCompleted()['pending']);
        $this->assertDirectoryExists($task.'/payload');
        $this->assertNull($state->read('task-'.$id.'.json'));
        $state->write('journal.json', $complete);
        $handle = fopen($state->directory().'/update.lock', 'c');
        flock($handle, LOCK_EX);
        try {
            $this->assertTrue($runner->cleanupCompleted()['skipped']);
            $this->assertDirectoryExists($task.'/payload');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        foreach ([['id' => '../media'], ['status' => 'running'], ['status' => 'failed'], ['status' => 'restoring'], ['stage' => 'migration-running'], ['active' => true]] as $invalid) {
            $state->write('task-'.$id.'.json', [...$complete, ...$invalid]);
            $this->assertSame(1, $runner->cleanupCompleted()['pending']);
            $this->assertDirectoryExists($task.'/payload');
        }
        $state->write('task-'.$id.'.json', $complete);
        foreach ([$task.'/payload', $task, dirname($task)] as $path) {
            rename($path, $path.'-held');
            symlink($path.'-held', $path);
            try {
                $this->assertSame(1, $runner->cleanupCompleted()['pending']);
                $this->assertSame('complete', $runner->status()['status']);
                $this->assertDirectoryExists($task.'/payload');
            } finally {
                unlink($path);
                rename($path.'-held', $path);
            }
        }
        $this->assertSame('complete', $runner->advance($id)['cleanup']['status']);
        $this->assertDirectoryDoesNotExist($task.'/payload');
    }

    public function test_cleanup_waits_for_durable_completion_and_receipt_write_failures_do_not_fail_upgrade(): void
    {
        $runner = $this->runner();
        $baseline = $runner->current();
        $status = $runner->prepare($this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], [], $baseline['schema']));
        for ($step = 0; $step < 3; $step++) {
            $status = $runner->advance($status['id']);
        }
        $state = new class($this->root) extends UpdateState
        {
            public bool $refuseCompletion = true;

            public bool $refuseReceipt = true;

            public bool $refuseCleanupResult = true;

            public function write(string $name, array $data): void
            {
                if (($name === 'journal.json' && ($data['status'] ?? '') === 'complete' && $this->refuseCompletion)
                    || (str_starts_with($name, 'task-') && ($this->refuseReceipt
                        || (($data['cleanup']['status'] ?? '') === 'complete' && $this->refuseCleanupResult)))) {
                    throw new RuntimeException('Fixture state write failure.');
                }
                parent::write($name, $data);
            }
        };
        (new \ReflectionProperty(UpdateRunner::class, 'state'))->setValue($runner, $state);
        $task = $state->directory().'/tasks/'.$status['id'];
        try {
            $runner->advance($status['id']);
            $this->fail('Completion write must be durable before cleanup.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture state write failure.', $exception->getMessage());
        }
        $this->assertSame('health', $state->read('journal.json')['stage']);
        $this->assertFileExists($task.'/backup/database/users.rows');
        $this->assertDirectoryExists($task.'/payload');
        $this->assertNull($state->read('task-'.$status['id'].'.json'));
        $state->refuseCompletion = false;
        $status = $runner->advance($status['id']);
        $this->assertSame('complete', $status['status']);
        $this->assertSame('pending', $status['cleanup']['status']);
        $this->assertFalse($status['active']);
        $this->assertDirectoryExists($task.'/payload');
        $this->assertFileExists($task.'/backup/database/users.rows');
        $state->refuseReceipt = false;
        $status = $runner->advance($status['id']);
        $this->assertSame('complete', $status['status']);
        $this->assertSame('pending', $status['cleanup']['status']);
        $this->assertSame('pending', $state->read('task-'.$status['id'].'.json')['cleanup']['status']);
        $this->assertDirectoryDoesNotExist($task.'/payload');
        $this->assertDirectoryDoesNotExist($task.'/backup');
        $state->refuseCleanupResult = false;
        $status = $runner->advance($status['id']);
        $this->assertSame('complete', $status['cleanup']['status']);
        $this->assertDirectoryDoesNotExist($task.'/payload');
        $this->assertDirectoryDoesNotExist($task.'/backup');
    }

    public function test_legacy_to_baseline_without_reinstalling_or_changing_admin_or_images(): void
    {
        $before = $this->pdo->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($this->root.'/public/media/kept.webp', 'unchanged');
        file_put_contents($this->root.'/app/customer-added.php', '<?php custom file kept');
        $runner = $this->runner();
        $legacy = $runner->current();
        $zip = $this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], [], $legacy['schema']);
        $status = $runner->prepare($zip);
        for ($count = 0; $count < 4; $count++) {
            $status = $runner->advance($status['id'], $status['stage']);
        }
        $this->assertSame('complete', $status['status'], json_encode($status));
        $this->assertSame($before, $this->pdo->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame('unchanged', file_get_contents($this->root.'/public/media/kept.webp'));
        $this->assertSame('<?php custom file kept', file_get_contents($this->root.'/app/customer-added.php'));
    }

    public function test_migration_ddl_failure_and_corrupt_backup_refuses_restore_before_drop(): void
    {
        $runner = $this->runner();
        $legacy = $runner->current();
        $name = '2026_10_28_020001_failure';
        $migration = '<?php return new class extends Illuminate\Database\Migrations\Migration { public function up():void{Illuminate\Support\Facades\Schema::create("interrupted_ddl",function(Illuminate\Database\Schema\Blueprint $t){$t->id();});throw new RuntimeException("fixture stop");}public function down():void{}};';
        $zip = $this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], ["database/migrations/$name.php" => $migration], [...$legacy['schema'], $name]);
        $status = $runner->prepare($zip);
        $status = $runner->advance($status['id']);
        $status = $runner->advance($status['id']);
        $status = $runner->advance($status['id']);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('migration-running', $status['stage']);
        $this->assertSame('interrupted_ddl', $this->pdo->query("SHOW TABLES LIKE 'interrupted_ddl'")->fetchColumn());
        $backup = $this->root.'/storage/app/updates/tasks/'.$status['id'].'/backup/database/users.rows';
        $valid = file_get_contents($backup);
        file_put_contents($backup, 'corrupt');
        $refused = $runner->restore($status['id']);
        $this->assertSame('failed', $refused['status']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame('interrupted_ddl', $this->pdo->query("SHOW TABLES LIKE 'interrupted_ddl'")->fetchColumn());
        file_put_contents($backup, $valid);
        $status = $runner->restore($status['id']);
        $this->assertSame('restored', $status['status'], json_encode($status));
        $this->assertFalse($this->pdo->query("SHOW TABLES LIKE 'interrupted_ddl'")->fetchColumn());
        $this->assertFileDoesNotExist($this->root."/database/migrations/$name.php");
    }

    public function test_half_replacement_and_broken_startup_recover_with_frozen_classes(): void
    {
        $runner = $this->runner();
        $legacy = $runner->current();
        $original = file_get_contents($this->root.'/bootstrap/app.php');
        $zip = $this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], ['bootstrap/app.php' => '<?php throw new RuntimeException("broken startup fixture");'], $legacy['schema']);
        $status = $runner->prepare($zip);
        $status = $runner->advance($status['id']);
        $this->assertSame('files', $status['stage']);
        file_put_contents($this->root.'/bootstrap/app.php', '<?php broken fixture');
        file_put_contents($this->root.'/vendor/autoload.php', '<?php broken vendor fixture');
        $program = 'foreach(["UpdateState","UpdatePackage","DatabaseSnapshot","UpdateRunner"] as $class){require '.var_export($this->root.'/storage/app/updates/runtime/', true).'.$class.".php";} echo json_encode((new App\Services\Updates\UpdateRunner('.var_export($this->root, true).'))->restore('.var_export($status['id'], true).'));';
        $pipes = [];
        $process = proc_open([$this->project.'/bin/php-local', '-r', $program], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process));
        $status = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('restored', $status['status'], json_encode($status));
        $this->assertSame($original, file_get_contents($this->root.'/bootstrap/app.php'));
        $status = $runner->prepare($zip);
        $status = $runner->advance($status['id']);
        $status = $runner->advance($status['id']);
        $status = $runner->advance($status['id']);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('restored', $runner->restore($status['id'])['status']);
        $this->assertFileExists($this->root.'/storage/app/updates/runtime/UpdateRunner.php');
    }

    public function test_concurrent_requests_and_in_flight_write_lock_do_not_change_program(): void
    {
        $runner = $this->runner();
        $legacy = $runner->current();
        $status = $runner->prepare($this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], [], $legacy['schema']));
        $handle = fopen($this->root.'/storage/app/updates/update.lock', 'c');
        flock($handle, LOCK_EX);
        try {
            $runner->advance($status['id']);
            $this->fail('Concurrent update should refuse');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('另一个', $exception->getMessage());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $handle = fopen($this->root.'/storage/app/updates/write.lock', 'c');
        flock($handle, LOCK_SH);
        try {
            $runner->advance($status['id']);
            $this->fail('In-flight write should drain first');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('写入', $exception->getMessage());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->assertSame('cancelled', $runner->restore($status['id'])['status']);
    }

    public function test_orphaned_migration_holds_write_lock_after_request_process_is_killed(): void
    {
        $runner = $this->runner();
        $legacy = $runner->current();
        $name = '2026_10_28_030001_slow_migration';
        $migration = '<?php return new class extends Illuminate\\Database\\Migrations\\Migration { public function up():void{file_put_contents(base_path("migration-ready"),"ready");sleep(3);Illuminate\\Support\\Facades\\Schema::create("orphan_ddl",function(Illuminate\\Database\\Schema\\Blueprint $t){$t->id();});}public function down():void{}};';
        $status = $runner->prepare($this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], ["database/migrations/$name.php" => $migration], [...$legacy['schema'], $name]));
        $status = $runner->advance($status['id']);
        $status = $runner->advance($status['id']);
        $program = 'require '.var_export($this->project.'/vendor/autoload.php', true).'; (new App\\Services\\Updates\\UpdateRunner('.var_export($this->root, true).'))->advance('.var_export($status['id'], true).',"migrate");';
        $pipes = [];
        $process = proc_open([$this->project.'/bin/php-local', '-r', $program], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        fclose($pipes[0]);
        $start = microtime(true);
        while (! is_file($this->root.'/migration-ready') && microtime(true) - $start < 10) {
            usleep(50000);
        }
        $this->assertFileExists($this->root.'/migration-ready');
        proc_terminate($process, 9);
        usleep(200000);
        try {
            $runner->restore($status['id']);
            $this->fail('Orphan migration must hold write lock until it exits');
        } catch (RuntimeException $exception) {
            $this->assertTrue(str_contains($exception->getMessage(), '写入') || str_contains($exception->getMessage(), '另一个'));
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $start = microtime(true);
        while (! $this->pdo->query("SHOW TABLES LIKE 'orphan_ddl'")->fetchColumn() && microtime(true) - $start < 6) {
            usleep(50000);
        }
        $this->assertSame('orphan_ddl', $this->pdo->query("SHOW TABLES LIKE 'orphan_ddl'")->fetchColumn());
        usleep(100000);
        $this->assertSame('restored', $runner->restore($status['id'])['status']);
        $this->assertFalse($this->pdo->query("SHOW TABLES LIKE 'orphan_ddl'")->fetchColumn());
    }

    public function test_environment_drift_is_blocked_before_any_program_or_schema_change(): void
    {
        $runner = $this->runner();
        $legacy = $runner->current();
        $status = $runner->prepare($this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], [], $legacy['schema']));
        $before = file_get_contents($this->root.'/.env');
        file_put_contents($this->root.'/.env', $before."\nAPP_NAME=ChangedAfterConfirmation\n");
        $status = $runner->advance($status['id']);
        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('环境配置', $status['error']);
        $this->assertSame('backup', $status['stage']);
        $this->assertSame('cancelled', $runner->restore($status['id'])['status']);
    }

    public function test_database_permissions_unknown_baseline_and_unmanaged_conflict_are_refused(): void
    {
        $runner = $this->runner();
        $this->pdo->exec('ALTER TABLE images ADD unexpected INT NULL');
        try {
            $runner->current();
            $this->fail('Unknown baseline must refuse');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('字段', $exception->getMessage());
        }
        $this->pdo->exec('ALTER TABLE images DROP unexpected');
        $legacy = $runner->current();
        $this->pdo->exec('ALTER TABLE images ADD unexpected INT NULL');
        try {
            $runner->prepare($this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], [], $legacy['schema']));
            $this->fail('Stored version must detect structural drift');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('基线', $exception->getMessage());
        }
        $this->pdo->exec('ALTER TABLE images DROP unexpected');
        file_put_contents($this->root.'/app/private-custom.php', '<?php custom');
        try {
            $runner->prepare($this->package('1.0.0', ['min' => '0.0.0', 'max' => '0.0.0'], ['app/private-custom.php' => '<?php replaced'], $legacy['schema']));
            $this->fail('Unmanaged conflict must refuse');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('未托管', $exception->getMessage());
        }
        $this->admin->exec('REVOKE CREATE,ALTER,DROP ON `'.$this->database.'`.* FROM '.$this->admin->quote($this->connection['username'])."@'127.0.0.1'");
        try {
            (new DatabaseSnapshot($this->pdo))->checkPermissions();
            $this->fail('Read-only privileges must refuse');
        } catch (\Throwable $exception) {
            $this->assertTrue(true);
        }
    }

    public function test_snapshot_restores_binary_null_json_and_rejects_truncation(): void
    {
        $this->pdo->exec('CREATE TABLE snapshot_fixture (id INT PRIMARY KEY, bytes LONGBLOB, nullable VARCHAR(50), metadata JSON) ENGINE=InnoDB');
        $bytes = "\x00\xff\x10'\\\n";
        $this->pdo->prepare('INSERT INTO snapshot_fixture VALUES(1,?,NULL,?)')->execute([$bytes, '{"a":1}']);
        $snapshot = new DatabaseSnapshot($this->pdo);
        $descriptor = $snapshot->create($this->root.'/fixture-backup');
        $this->pdo->exec('UPDATE snapshot_fixture SET bytes="changed"');
        $snapshot->restore($this->root.'/fixture-backup', $descriptor);
        $row = $this->pdo->query('SELECT * FROM snapshot_fixture')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($bytes, $row['bytes']);
        $this->assertNull($row['nullable']);
        $this->assertSame('{"a": 1}', $row['metadata']);
        file_put_contents($this->root.'/fixture-backup/snapshot_fixture.rows', '');
        $this->expectException(RuntimeException::class);
        $snapshot->restore($this->root.'/fixture-backup', $descriptor);
    }

    private function runner(): UpdateRunner
    {
        return new UpdateRunner($this->root, $this->connection, $this->project.'/bin/php-local');
    }

    private function package(string $version, array $compatible, array $extra, array $schema): string
    {
        $paths = ['artisan', 'composer.json', 'composer.lock', 'public/index.php', 'bootstrap/app.php', 'bootstrap/providers.php', 'bootstrap/preflight.php', 'bootstrap/installation.php', 'bootstrap/update-gateway.php', 'bootstrap/update-public.pem', 'app/Services/Updates/UpdateRunner.php', 'app/Services/Updates/UpdatePackage.php', 'app/Services/Updates/UpdateState.php', 'app/Services/Updates/DatabaseSnapshot.php', 'app/Services/Updates/UpdateWeb.php', 'resources/views/update-recovery.php'];
        $current = (new UpdateState($this->root))->version();
        $paths = array_values(array_unique([...$paths, ...$current['managed_files']]));
        $files = [];
        foreach ($paths as $path) {
            if (is_file($this->root.'/'.$path)) {
                $files[$path] = file_get_contents($this->root.'/'.$path);
            }
        }
        foreach (glob($this->root.'/database/migrations/*.php') as $path) {
            $files['database/migrations/'.basename($path)] = file_get_contents($path);
        }
        $files['vendor/autoload.php'] ??= file_get_contents($this->project.'/vendor/autoload.php');
        $files['vendor/composer/installed.json'] ??= file_get_contents($this->project.'/vendor/composer/installed.json');
        $files = [...$files, ...$extra];
        $paths = array_values(array_unique([...array_keys($files), 'release.json']));
        $files['release.json'] = json_encode(['program' => $version, 'schema' => $schema, 'managed_files' => $paths]);
        $manifest = ['format' => 1, 'program' => $version, 'schema' => $schema, 'compatible' => $compatible, 'php_min' => '8.4.1', 'media_migrations' => false, 'files' => []];
        foreach ($files as $path => $body) {
            $manifest['files'][$path] = ['sha256' => hash('sha256', $body), 'size' => strlen($body), 'mode' => 0644];
        }
        $body = json_encode($manifest, JSON_THROW_ON_ERROR);
        openssl_sign($body, $signature, $this->key, OPENSSL_ALGO_SHA256);
        $archive = $this->root.'/fixture-'.bin2hex(random_bytes(6)).'.zip';
        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL);
        $zip->addFromString('manifest.json', $body);
        $zip->addFromString('signature.txt', base64_encode($signature));
        foreach ($files as $path => $content) {
            $zip->addFromString('payload/'.$path, $content);
            $zip->setExternalAttributesName('payload/'.$path, ZipArchive::OPSYS_UNIX, (0100644) << 16);
        }
        $zip->close();

        return $archive;
    }

    private function php(array $arguments): void
    {
        $environment = getenv();
        foreach (array_keys($environment) as $name) {
            if (preg_match('/\A(APP_|DB_)/', $name)) {
                unset($environment[$name]);
            }
        }
        $pipes = [];
        $process = proc_open([$this->project.'/bin/php-local', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $environment);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Fixture migration failed; output suppressed.');
        }
    }

    private function copyTree(string $source, string $target): void
    {
        if (is_dir($source)) {
            if (! is_dir($target)) {
                mkdir($target, 0755, true);
            }
            foreach (scandir($source) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->copyTree($source.'/'.$entry, $target.'/'.$entry);
                }
            }
        } else {
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }copy($source, $target);
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.'/'.$entry);
            }
        }
        rmdir($path);
    }
}
