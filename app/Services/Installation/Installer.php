<?php

namespace App\Services\Installation;

use App\Providers\AppServiceProvider;
use App\Services\Updates\UpdateRunner;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use Monolog\Handler\NullHandler;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

class Installer
{
    private InstallationState $state;

    private EnvFile $environment;

    public function __construct(private string $root, private ?Closure $runtime = null)
    {
        $this->state = new InstallationState($root);
        $this->environment = new EnvFile($root);
    }

    /** @return array<string,mixed> */
    public function validate(array $input): array
    {
        $site = $this->validateSite($input);
        $password = (string) ($input['admin_password'] ?? '');
        if (mb_strlen($password) < 12 || strlen($password) > 72 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
            throw new InvalidArgumentException('管理员密码至少 12 个字符、最多 72 字节，且不含控制字符。');
        }
        if (! hash_equals($password, (string) ($input['admin_password_confirmation'] ?? ''))) {
            throw new InvalidArgumentException('两次输入的管理员密码不一致。');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        if (! is_string($hash)) {
            throw new RuntimeException('无法安全处理管理员密码。');
        }

        return [...$site, 'admin_hash' => $hash];
    }

    private function validateSite(array $input): array
    {
        $database = self::normalizeDatabase($input);
        $name = trim((string) ($input['app_name'] ?? '映屿Img'));
        $url = rtrim(trim((string) ($input['app_url'] ?? '')), '/');
        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new InvalidArgumentException('网站名称必须为 1 至 100 个字符，且不含控制字符。');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '') || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('网站地址必须是完整 HTTP/HTTPS 根域名地址，不含路径或账号信息。');
        }
        $host = trim(strtolower($parts['host']), '[]');
        $local = $host === 'localhost' || $host === '::1' || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && str_starts_with($host, '127.'));
        if ($parts['scheme'] !== 'https' && ! $local) {
            throw new InvalidArgumentException('正式网站地址必须使用 HTTPS；本地地址可使用 HTTP。');
        }
        $email = trim((string) ($input['admin_email'] ?? ''));
        if (strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('请输入有效的管理员邮箱。');
        }

        return [...$database, 'app_name' => $name, 'app_url' => $url, 'secure_cookie' => $parts['scheme'] === 'https', 'admin_email' => $email];
    }

    /** @return array{version:string,empty:bool,message:string} */
    public function checkDatabase(array $input, string $token): array
    {
        return $this->state->underLock(function () use ($input, $token): array {
            $this->authorizeFresh($token);
            $database = self::normalizeDatabase($input);
            $pdo = self::connect($database);
            $this->acquireDatabaseLock($pdo, $database['db_database']);
            try {
                if (! self::isSupportedVersion($pdo)) {
                    throw new InvalidArgumentException('仅支持 MySQL 8.0 及以上版本，不支持 MariaDB。');
                }
                if (self::databaseObjects($pdo, $database['db_database']) !== []) {
                    throw new InvalidArgumentException('目标数据库不是空库。为保护已有数据，安装已停止。');
                }

                return ['version' => 'MySQL 8.0+', 'empty' => true, 'message' => '数据库连接成功且为空库。'];
            } finally {
                $this->releaseDatabaseLock($pdo, $database['db_database']);
            }
        });
    }

    /** @return array{installed:bool,url:string,message:string} */
    public function install(array $input, string $token): array
    {
        return $this->installPrepared($this->validate($input), $token);
    }

    /** Accept only data previously prepared by validate() and stored in a private server session. */
    public function installPrepared(array $input, string $token): array
    {
        return $this->state->underLock(function () use ($input, $token): array {
            $this->authorizeFresh($token);
            $data = $this->validateSite($input);
            $hash = $input['admin_hash'] ?? '';
            $hashInfo = is_string($hash) ? password_get_info($hash) : [];
            if (($hashInfo['algoName'] ?? '') !== 'bcrypt' || ($hashInfo['options']['cost'] ?? 0) < 12 || ! preg_match('/\A\$2y\$[0-9]{2}\$[.\/A-Za-z0-9]{53}\z/D', $hash)) {
                throw new InvalidArgumentException('管理员准备数据无效，请重新设置管理员密码。');
            }
            $data['admin_hash'] = $hash;
            $pdo = self::connect($data);
            $this->acquireDatabaseLock($pdo, $data['db_database']);
            $journal = null;
            $snapshot = null;
            $recoveryStarted = false;
            try {
                if (! self::isSupportedVersion($pdo)) {
                    throw new InvalidArgumentException('仅支持 MySQL 8.0 及以上版本，不支持 MariaDB。');
                }
                if (self::databaseObjects($pdo, $data['db_database']) !== []) {
                    throw new InvalidArgumentException('目标数据库不是空库。安装器不会接管或删除已有表。');
                }
                $snapshot = $this->environment->snapshot();
                $journal = [
                    'format' => 1,
                    'installation_id' => bin2hex(random_bytes(16)),
                    'owner_token_sha256' => hash('sha256', $token),
                    'target_sha256' => hash('sha256', strtolower($data['db_host']).':'.$data['db_port'].'/'.$data['db_database']),
                    'stage' => 'claimed_empty_database',
                    'started_at' => gmdate(DATE_ATOM),
                    'database' => ['host' => $data['db_host'], 'port' => $data['db_port'], 'name' => $data['db_database']],
                    'original_environment_exists' => $snapshot !== null,
                    'original_environment_sha256' => $snapshot === null ? null : hash('sha256', $snapshot),
                    'environment_restored' => false,
                ];
                $values = $this->environmentValues($data);
                $pending = $this->environment->render($values);
                $recoveryStarted = true;
                $this->writeState('pending.env', $pending);
                if ($snapshot !== null) {
                    $this->writeState('environment.backup', $snapshot);
                }
                $this->writeJournal($journal);
                $journal['stage'] = 'migrating';
                $this->writeJournal($journal);
                if ($this->runtime) {
                    ($this->runtime)($data, $values, function (string $stage) use (&$journal): void {
                        $journal['stage'] = $stage;
                        $this->writeJournal($journal);
                    });
                } else {
                    $this->runLaravel($data, $values, function (string $stage) use (&$journal): void {
                        $journal['stage'] = $stage;
                        $this->writeJournal($journal);
                    });
                }
                $journal['stage'] = 'database_ready';
                $this->writeJournal($journal);
                $this->environment->writeAtomic($pending);
                $journal['stage'] = 'environment_published';
                $this->writeJournal($journal);
                $journal['stage'] = 'installed';
                $journal['completed_at'] = gmdate(DATE_ATOM);
                $this->writeJournal($journal);
                $releaseMetadata = [];
                $releaseFile = $this->root.'/release.json';
                if (is_file($releaseFile) && ! is_link($releaseFile)) {
                    $release = json_decode(file_get_contents($releaseFile), true, 64, JSON_THROW_ON_ERROR);
                    $releaseMetadata = array_intersect_key($release, array_flip(['program', 'schema', 'managed_files']));
                }
                $this->writeState('installed.json', json_encode([
                    'format' => 1, 'installation_id' => $journal['installation_id'], 'installed_at' => gmdate(DATE_ATOM),
                    ...$releaseMetadata,
                    'schema_fingerprint' => UpdateRunner::schemaFingerprint($pdo),
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
                foreach (['pending.env', 'environment.backup', 'owner-token'] as $name) {
                    if (is_file($this->state->path($name))) {
                        @unlink($this->state->path($name));
                    }
                }

                return ['installed' => true, 'url' => $data['app_url'].'/login', 'message' => '安装完成，唯一管理员已创建，安装入口已关闭。'];
            } catch (Throwable $exception) {
                if ($recoveryStarted && $journal !== null && ! is_file($this->state->path('installed.json'))) {
                    $journal['failed_stage'] = $journal['stage'];
                    $journal['stage'] = 'failed';
                    $journal['failed_at'] = gmdate(DATE_ATOM);
                    $journal['owned_administrator_removed'] = $this->compensateAdministrator($pdo, $data);
                    try {
                        $this->environment->restore($snapshot);
                        $journal['environment_restored'] = true;
                    } catch (Throwable) {
                        $journal['environment_restored'] = false;
                    }
                    try {
                        $this->writeJournal($journal);
                    } catch (Throwable) {
                    }
                    throw new RuntimeException('安装未完成，已保留私有恢复日志；网页重试已关闭。MySQL DDL 不会自动回滚，请服务器管理员核对备份和日志后人工恢复。');
                }
                if ($exception instanceof InvalidArgumentException || $exception instanceof RuntimeException) {
                    throw $exception;
                }
                throw new RuntimeException('安装未完成，请检查服务器环境与目录权限。');
            } finally {
                $this->releaseDatabaseLock($pdo, $data['db_database']);
            }
        });
    }

    /** @return array{db_host:string,db_port:int,db_database:string,db_username:string,db_password:string} */
    public static function normalizeDatabase(array $input): array
    {
        $host = trim((string) ($input['db_host'] ?? '127.0.0.1'));
        if (! filter_var($host, FILTER_VALIDATE_IP) && (! preg_match('/\A(?=.{1,253}\z)[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?\z/D', $host) || str_contains($host, '..'))) {
            throw new InvalidArgumentException('数据库主机须为有效主机名或 IP，不能包含连接串。');
        }
        $port = filter_var($input['db_port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $name = (string) ($input['db_database'] ?? '');
        $username = (string) ($input['db_username'] ?? '');
        $password = (string) ($input['db_password'] ?? '');
        if ($port === false || ! preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D', $name) || $username === '' || strlen($username) > 128) {
            throw new InvalidArgumentException('数据库名称、用户名或端口无效；库名仅允许字母、数字、下划线。');
        }
        if (in_array(strtolower($name), ['mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
            throw new InvalidArgumentException('不得使用 MySQL 系统数据库。');
        }
        foreach ([$username, $password] as $value) {
            if (strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value) || ! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('数据库凭据不得包含控制字符或无效编码。');
            }
        }
        if (! empty($input['db_socket']) || ! empty($input['db_url'])) {
            throw new InvalidArgumentException('安装器仅接受 TCP 数据库连接。');
        }

        return ['db_host' => $host, 'db_port' => $port, 'db_database' => $name, 'db_username' => $username, 'db_password' => $password];
    }

    public static function connect(array $database): PDO
    {
        try {
            $host = str_contains($database['db_host'], ':') ? '['.$database['db_host'].']' : ($database['db_host'] === 'localhost' ? '127.0.0.1' : $database['db_host']);

            return new PDO('mysql:host='.$host.';port='.$database['db_port'].';dbname='.$database['db_database'].';charset=utf8mb4', $database['db_username'], $database['db_password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3,
            ]);
        } catch (Throwable) {
            throw new RuntimeException('无法连接数据库，请核对主机、端口、库名及账号权限。');
        }
    }

    public static function isSupportedVersion(PDO $pdo): bool
    {
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        return stripos($version, 'mariadb') === false && preg_match('/\A([0-9]+\.[0-9]+\.[0-9]+)/', $version, $matches) === 1 && version_compare($matches[1], '8.0.0', '>=');
    }

    /** @return list<string> */
    public static function databaseObjects(PDO $pdo, string $database): array
    {
        try {
            $objects = [];
            foreach (['SELECT TABLE_NAME AS object_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', 'SELECT ROUTINE_NAME AS object_name FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?', 'SELECT EVENT_NAME AS object_name FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?'] as $query) {
                $statement = $pdo->prepare($query);
                $statement->execute([$database]);
                foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
                    $objects[] = (string) $name;
                }
            }

            return $objects;
        } catch (Throwable) {
            throw new RuntimeException('无法安全核对数据库对象，请检查数据库权限。');
        }
    }

    private function authorizeFresh(string $token): void
    {
        if (! $this->state->verifyToken($token)) {
            throw new InvalidArgumentException('服务器所有者令牌无效。');
        }
        $state = $this->state->inspect();
        if (! $state['can_install']) {
            throw new RuntimeException($state['message']);
        }
    }

    private function acquireDatabaseLock(PDO $pdo, string $database): void
    {
        try {
            $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            foreach ([$this->databaseLockName($database), 'image_vault_admin_init'] as $name) {
                $statement->execute([$name]);
                if ((int) $statement->fetchColumn() !== 1) {
                    throw new RuntimeException('数据库安装锁已被占用，请稍后重试。');
                }
            }
        } catch (Throwable) {
            $this->releaseDatabaseLock($pdo, $database);
            throw new RuntimeException('无法取得数据库安装锁，请稍后重试并检查数据库权限。');
        }
    }

    private function releaseDatabaseLock(PDO $pdo, string $database): void
    {
        try {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            foreach (['image_vault_admin_init', $this->databaseLockName($database)] as $name) {
                $statement->execute([$name]);
            }
        } catch (Throwable) {
        }
    }

    private function databaseLockName(string $database): string
    {
        return 'image_vault_install_'.substr(hash('sha256', $database), 0, 40);
    }

    private function environmentValues(array $data): array
    {
        return [
            'APP_NAME' => $data['app_name'], 'APP_ENV' => 'production', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => false, 'APP_URL' => $data['app_url'], 'APP_LOCALE' => 'zh_CN', 'APP_FALLBACK_LOCALE' => 'en', 'APP_MAINTENANCE_DRIVER' => 'file',
            'BCRYPT_ROUNDS' => 12, 'LOG_CHANNEL' => 'stack', 'LOG_STACK' => 'single', 'LOG_LEVEL' => 'warning',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $data['db_host'] === 'localhost' ? '127.0.0.1' : $data['db_host'], 'DB_PORT' => $data['db_port'],
            'DB_DATABASE' => $data['db_database'], 'DB_USERNAME' => $data['db_username'], 'DB_PASSWORD' => $data['db_password'], 'DB_SOCKET' => '', 'DB_URL' => '',
            'SESSION_DRIVER' => 'file', 'SESSION_LIFETIME' => 120, 'SESSION_ENCRYPT' => true, 'SESSION_SECURE_COOKIE' => $data['secure_cookie'], 'SESSION_SAME_SITE' => 'lax',
            'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'FILESYSTEM_DISK' => 'local', 'IMAGE_PUBLIC_URL' => '', 'MAIL_MAILER' => 'log',
        ];
    }

    private function compensateAdministrator(PDO $pdo, array $data): bool
    {
        try {
            if (! in_array('users', self::databaseObjects($pdo, $data['db_database']), true)) {
                return true;
            }
            $statement = $pdo->prepare('DELETE FROM users WHERE email = ? AND password = ?');
            $statement->execute([$data['admin_email'], $data['admin_hash']]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function writeJournal(array $journal): void
    {
        $this->writeState('journal.json', json_encode($journal, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
    }

    private function writeState(string $name, string $content): void
    {
        $this->environment->atomicFile($this->state->path($name), $content);
    }

    private function runLaravel(array $data, array $values, callable $stage): void
    {
        $previousApplication = Application::getInstance();
        $previousFacadeApplication = Facade::getFacadeApplication();
        $previousResolver = Model::getConnectionResolver();
        $previousDispatcher = Model::getEventDispatcher();
        $previousTimezone = date_default_timezone_get();
        $application = null;
        try {
            $application = Application::configure(basePath: $this->root)
                ->withExceptions()
                ->withProviders([AppServiceProvider::class])
                ->create();
            $application->useEnvironmentPath(dirname($this->state->path('pending.env')));
            $application->loadEnvironmentFrom('pending.env');
            $application->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($data, $values): void {
                $config = $app['config'];
                $config->set('app.env', 'production');
                $config->set('app.debug', false);
                $config->set('app.name', $data['app_name']);
                $config->set('app.url', $data['app_url']);
                $config->set('app.key', $values['APP_KEY']);
                $config->set('database.default', 'mysql');
                $config->set('database.connections.mysql', [
                    'driver' => 'mysql', 'host' => $values['DB_HOST'], 'port' => $data['db_port'], 'database' => $data['db_database'],
                    'username' => $data['db_username'], 'password' => $data['db_password'], 'unix_socket' => '', 'url' => null,
                    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'prefix_indexes' => true, 'strict' => true, 'engine' => null, 'options' => [],
                ]);
                $config->set('cache.default', 'file');
                $config->set('queue.default', 'sync');
                $config->set('session.driver', 'file');
                $config->set('logging.default', 'null');
                $config->set('logging.channels.null', ['driver' => 'monolog', 'handler' => NullHandler::class]);
                $app->instance('env', 'production');
            });
            $kernel = $application->make(Kernel::class);
            $application->bootstrapWith([
                LoadConfiguration::class,
                RegisterFacades::class,
                SetRequestForConsole::class,
                RegisterProviders::class,
                BootProviders::class,
            ]);
            $kernel->bootstrap();
            $stage('migrating');
            if ($kernel->call('migrate', ['--force' => true, '--no-interaction' => true, '--path' => $this->root.'/database/migrations', '--realpath' => true], new NullOutput) !== 0) {
                throw new RuntimeException('数据库迁移失败。');
            }
            $stage('creating_administrator');
            $connection = $application['db']->connection();
            $connection->transaction(function () use ($connection, $data): void {
                if ($connection->table('users')->exists()) {
                    throw new RuntimeException('数据库已存在管理员，停止初始化。');
                }
                $connection->table('users')->insert([
                    'name' => '管理员', 'email' => $data['admin_email'], 'password' => $data['admin_hash'],
                    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            });
        } finally {
            if ($application && $application->bound('db')) {
                $application['db']->disconnect();
            }
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacadeApplication);
            Application::setInstance($previousApplication);
            if ($previousResolver) {
                Model::setConnectionResolver($previousResolver);
            } else {
                Model::unsetConnectionResolver();
            }
            if ($previousDispatcher) {
                Model::setEventDispatcher($previousDispatcher);
            } else {
                Model::unsetEventDispatcher();
            }
            date_default_timezone_set($previousTimezone);
        }
    }
}
