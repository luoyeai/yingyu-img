<?php

namespace App\Services\Updates;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__.'/UpdateRequirements.php';

/** Runs one durable phase per request and remains usable without Composer or Laravel. */
class UpdateRunner
{
    private UpdateState $state;

    /** @var resource|null */
    private mixed $phaseLock = null;

    private const LEGACY_SCHEMA = [
        '0001_01_01_000000_create_users_table', '0001_01_01_000001_create_cache_table',
        '0001_01_01_000002_create_jobs_table', '2026_10_27_000001_create_vault_tables',
        '2026_10_27_053804_enforce_single_administrator', '2026_10_27_054753_add_preview_bytes_to_images',
    ];

    public function __construct(private string $root, private ?array $connection = null, private ?string $phpCli = null)
    {
        $canonical = realpath($root);
        if ($canonical === false || ! is_dir($canonical)) {
            throw new RuntimeException('更新根目录无效。');
        }
        $this->root = $canonical;
        $this->state = new UpdateState($canonical);
    }

    public function current(): array
    {
        if ($this->state->read('version.json') !== null) {
            return $this->state->version();
        }
        $pdo = $this->database();
        $migrations = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        $installedVersion = $this->installedVersion($pdo, $migrations);
        if ($installedVersion !== null) {
            $this->state->write('version.json', $installedVersion);

            return $installedVersion;
        }
        $expected = self::LEGACY_SCHEMA;
        sort($expected);
        if ($migrations !== $expected || (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 1) {
            throw new RuntimeException('无法识别已有数据库基线，禁止猜测版本或重新安装。');
        }
        $expectedColumns = [
            'users' => ['id', 'name', 'email', 'email_verified_at', 'password', 'remember_token', 'created_at', 'updated_at', 'singleton'],
            'images' => ['id', 'group_id', 'original_name', 'display_name', 'alt', 'path', 'preview_path', 'original_path', 'mime', 'source_width', 'source_height', 'width', 'height', 'source_bytes', 'bytes', 'original_bytes', 'source_hash', 'fingerprint', 'processing', 'disabled_at', 'deleted_at', 'created_at', 'updated_at', 'preview_bytes'],
            'settings' => ['key', 'value', 'created_at', 'updated_at'], 'groups' => ['id', 'name', 'created_at', 'updated_at'],
            'migrations' => ['id', 'migration', 'batch'], 'cache' => ['key', 'value', 'expiration'], 'cache_locks' => ['key', 'owner', 'expiration'],
            'jobs' => ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'],
            'job_batches' => ['id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'failed_job_ids', 'options', 'cancelled_at', 'created_at', 'finished_at'],
            'failed_jobs' => ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'],
            'password_reset_tokens' => ['email', 'token', 'created_at'], 'sessions' => ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'],
        ];
        $actualTables = (new DatabaseSnapshot($pdo))->inspect();
        $expectedTables = array_keys($expectedColumns);
        sort($expectedTables);
        if ($actualTables !== $expectedTables) {
            throw new RuntimeException('已有数据库表清单与已知基线不符。');
        }
        foreach ($expectedColumns as $table => $columns) {
            $actual = $pdo->query('SHOW COLUMNS FROM '.DatabaseSnapshot::identifier($table))->fetchAll(PDO::FETCH_COLUMN);
            if ($columns !== $actual) {
                throw new RuntimeException('已有数据库字段与已知基线不符。');
            }
        }
        $singletonIndex = $pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='singleton' AND NON_UNIQUE=0")->fetchAll(PDO::FETCH_COLUMN);
        $previewType = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='images' AND COLUMN_NAME='preview_bytes'")->fetchColumn();
        if ($singletonIndex === [] || $previewType !== 'bigint unsigned' || (int) $pdo->query('SELECT singleton FROM users LIMIT 1')->fetchColumn() !== 1) {
            throw new RuntimeException('已有管理员约束与已知基线不符。');
        }
        $legacyPath = $this->root.'/bootstrap/update-legacy.json';
        if (is_link($legacyPath) || ! is_file($legacyPath)) {
            throw new RuntimeException('缺少受支持的首次接入基线清单。');
        }
        $legacy = json_decode(file_get_contents($legacyPath), true, 64, JSON_THROW_ON_ERROR);
        if (($legacy['baseline'] ?? null) !== 'legacy-20261028' || ! is_array($legacy['files'] ?? null)) {
            throw new RuntimeException('首次接入基线清单无效。');
        }
        if (! is_string($legacy['schema_sha256'] ?? null) || ! hash_equals($legacy['schema_sha256'], self::schemaFingerprint($pdo))) {
            throw new RuntimeException('已有数据库类型、索引或外键与受支持基线不一致。');
        }
        $managed = array_keys($legacy['files']);
        $program = '0.0.0';
        $releasePath = $this->root.'/release.json';
        if (is_file($releasePath) && ! is_link($releasePath)) {
            $release = json_decode(file_get_contents($releasePath), true, 64, JSON_THROW_ON_ERROR);
            $installedPath = $this->root.'/storage/app/installation/installed.json';
            $installed = is_file($installedPath) && ! is_link($installedPath) ? json_decode(file_get_contents($installedPath), true, 64, JSON_THROW_ON_ERROR) : [];
            $releaseSchema = $release['schema'] ?? [];
            sort($releaseSchema);
            if (is_string($release['program'] ?? null) && preg_match('/\A1\.\d+\.\d+\z/D', $release['program']) && $releaseSchema === $expected && is_array($release['managed_files'] ?? null)) {
                $managed = array_unique([...$managed, ...$release['managed_files']]);
                if (($installed['program'] ?? null) === $release['program'] && ($installed['schema'] ?? null) === ($release['schema'] ?? null)) {
                    $program = $release['program'];
                }
            }
        }
        $managed = array_values(array_filter($managed, UpdatePackage::allowedPath(...)));
        sort($managed);
        $version = ['program' => $program, 'schema' => $expected, 'baseline' => $program === '0.0.0' ? 'legacy-20261028' : $program, 'managed_files' => $managed, 'schema_fingerprint' => self::schemaFingerprint($pdo)];
        $this->state->write('version.json', $version);

        return $version;
    }

    /** @param list<string> $migrations */
    private function installedVersion(PDO $pdo, array $migrations): ?array
    {
        $releasePath = $this->root.'/release.json';
        $installedPath = $this->root.'/storage/app/installation/installed.json';
        if (! is_file($releasePath) || is_link($releasePath) || ! is_file($installedPath) || is_link($installedPath)) {
            return null;
        }
        if (filesize($releasePath) > 8388608 || filesize($installedPath) > 8388608) {
            throw new RuntimeException('安装版本记录超出允许大小。');
        }
        $installed = json_decode(file_get_contents($installedPath), true, 64, JSON_THROW_ON_ERROR);
        if (! isset($installed['schema_fingerprint'])) {
            return null;
        }
        $release = json_decode(file_get_contents($releasePath), true, 64, JSON_THROW_ON_ERROR);
        $schema = $release['schema'] ?? null;
        $managed = $release['managed_files'] ?? null;
        if (! is_string($release['program'] ?? null) || ! preg_match('/\A1\.\d+\.\d+\z/D', $release['program'])
            || ! is_array($schema) || ! array_is_list($schema) || ! is_array($managed) || ! array_is_list($managed)
            || ($installed['program'] ?? null) !== $release['program'] || ($installed['schema'] ?? null) !== $schema
            || ($installed['managed_files'] ?? null) !== $managed
            || array_any($managed, static fn (mixed $path): bool => ! is_string($path) || ! UpdatePackage::allowedPath($path))
            || ! is_string($installed['schema_fingerprint']) || ! hash_equals($installed['schema_fingerprint'], self::schemaFingerprint($pdo))
            || (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 1) {
            throw new RuntimeException('安装版本记录与发行清单或实际数据库结构不一致。');
        }
        sort($schema);
        if ($schema !== $migrations) {
            throw new RuntimeException('安装版本迁移记录与实际数据库不一致。');
        }

        return ['program' => $release['program'], 'schema' => $schema, 'baseline' => $release['program'],
            'managed_files' => $managed, 'schema_fingerprint' => $installed['schema_fingerprint']];
    }

    public static function schemaFingerprint(PDO $pdo): string
    {
        $queries = [
            'columns' => 'SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION',
            'indexes' => 'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',
            'keys' => 'SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION',
            'references' => 'SELECT TABLE_NAME,CONSTRAINT_NAME,UNIQUE_CONSTRAINT_NAME,MATCH_OPTION,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME',
        ];
        $shape = [];
        foreach ($queries as $name => $query) {
            $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                foreach ($row as &$value) {
                    $value = $value === null ? null : (string) $value;
                }
                unset($value);
            }
            unset($row);
            $shape[$name] = $rows;
        }

        return hash('sha256', json_encode($shape, JSON_THROW_ON_ERROR));
    }

    public function prepare(string $archive): array
    {
        return $this->state->locked(function () use ($archive): array {
            $old = $this->state->read('journal.json');
            if ($old && ! in_array($old['status'], ['complete', 'restored', 'cancelled'], true)) {
                throw new RuntimeException('已有未完成更新，请继续或恢复当前任务。');
            }
            if ($old !== null) {
                $old = $this->verifyTransitionCompletion($old);
            }
            if (($old['status'] ?? null) === 'complete' && isset($old['completion'])) {
                $this->cleanupSuccessfulTask($old);
                if ($this->state->read('task-'.$old['id'].'.json') === null) {
                    throw new RuntimeException('无法保存上一成功任务的清理记录，请稍后重试。');
                }
            }
            $current = $this->current();
            $id = bin2hex(random_bytes(16));
            $task = $this->state->directory().'/tasks/'.$id;
            try {
                $package = (new UpdatePackage($this->root))->extract($archive, $task, $current);
                $manifest = $package['manifest'];
                $this->preflight($manifest, $current);
                $this->freezeRuntime();
                $this->state->write('connection.json', $this->databaseConfiguration());
                $admin = $this->database()->query('SELECT email,password FROM users LIMIT 1')->fetch(PDO::FETCH_ASSOC);
                $this->state->write('recovery-account.json', ['task' => $id, 'email' => strtolower($admin['email']), 'password_hash' => $admin['password']]);
                $journal = ['id' => $id, 'status' => 'prepared', 'stage' => 'prepared', 'active' => false, 'from' => $current['program'], 'to' => $manifest['program'], 'before' => $current, 'manifest' => $manifest, 'environment_sha256' => $this->environmentHash(), 'log' => []];
                $this->log($journal, '更新包签名、版本、环境、权限和磁盘检查通过；等待管理员确认。');
                $this->state->write('journal.json', $journal);
            } catch (Throwable $failure) {
                if (is_dir($task)) {
                    $this->removeTree($task);
                }
                throw $failure;
            }

            return $this->publicStatus($journal);
        });
    }

    public function status(): array
    {
        $journal = $this->state->read('journal.json');

        return $journal ? $this->publicStatus($journal) : ['status' => 'idle', 'active' => false, 'stage' => 'idle', 'log' => []];
    }

    /** @return array{completed:int,pending:int,skipped:bool} */
    public function cleanupCompleted(): array
    {
        try {
            return $this->state->locked(function (): array {
                $journal = $this->state->read('journal.json');
                if (($journal['active'] ?? false) || in_array($journal['status'] ?? '', ['running', 'failed', 'restoring'], true)
                    || ($journal['stage'] ?? '') === 'migration-running') {
                    return ['completed' => 0, 'pending' => 0, 'skipped' => true];
                }
                $results = [];
                if ($journal !== null) {
                    try {
                        $journal = $this->verifyTransitionCompletion($journal);
                    } catch (Throwable) {
                        $journal['cleanup'] = ['status' => 'pending', 'error' => '过渡更新重新核验未通过，保留暂存与备份；维护时可重试。'];
                        $this->log($journal, $journal['cleanup']['error']);
                        $this->state->write('journal.json', $journal);

                        return ['completed' => 0, 'pending' => 1, 'skipped' => false];
                    }
                }
                if (($journal['status'] ?? '') === 'complete' && isset($journal['completion'])) {
                    $journal = $this->cleanupSuccessfulTask($journal);
                    $results[$journal['id']] = ($journal['cleanup']['status'] ?? '') === 'complete';
                }
                foreach (glob($this->state->directory().'/task-*.json') ?: [] as $path) {
                    $name = basename($path);
                    if (! preg_match('/\Atask-([a-f0-9]{32})\.json\z/D', $name, $matches) || isset($results[$matches[1]])) {
                        continue;
                    }
                    try {
                        $receipt = $this->state->read($name);
                        $this->assertCompletedReceipt($receipt, $matches[1]);
                        if (($receipt['cleanup']['status'] ?? '') === 'complete') {
                            continue;
                        }
                        $receipt = $this->cleanupReceipt($receipt);
                        $results[$matches[1]] = $receipt['cleanup']['status'] === 'complete';
                    } catch (Throwable) {
                        $results[$matches[1]] = false;
                    }
                }

                return ['completed' => count(array_filter($results)), 'pending' => count(array_filter($results, static fn (bool $complete): bool => ! $complete)), 'skipped' => false];
            });
        } catch (Throwable) {
            return ['completed' => 0, 'pending' => 1, 'skipped' => true];
        }
    }

    /** Only the current 1.1.0 engine's successful transition may acquire a new receipt. */
    private function verifyTransitionCompletion(array $journal): array
    {
        if (isset($journal['completion']) || ($journal['from'] ?? '') !== '1.1.0' || ($journal['to'] ?? '') !== '1.1.1'
            || ($journal['status'] ?? '') !== 'complete' || ($journal['stage'] ?? '') !== 'complete'
            || ($journal['active'] ?? null) !== false) {
            return $journal;
        }
        $version = $this->state->version();
        $manifest = $journal['manifest'];
        if ($version['program'] !== '1.1.1' || ($manifest['program'] ?? '') !== '1.1.1'
            || $version['schema'] !== $manifest['schema'] || $version['managed_files'] !== array_keys($manifest['files'])
            || ! is_string($version['schema_fingerprint'] ?? null)
            || ! hash_equals($version['schema_fingerprint'], self::schemaFingerprint($this->database()))) {
            throw new RuntimeException('过渡更新的成功版本与实际程序或数据库不一致，保留任务备份。');
        }
        $this->assertEnvironment($journal);
        $this->health($manifest, $journal['before']['managed_files']);
        $this->assertEnvironment($journal);
        $journal['completion'] = ['checked_at' => gmdate('c'), 'version_sha256' => $this->versionHash($version), 'verified_by' => '1.1.1-maintenance'];
        $this->log($journal, '旧执行器完成的 1.1.1 过渡更新已重新通过文件、迁移和启动健康检查。');
        $this->state->write('journal.json', $journal);

        return $journal;
    }

    /** Called only with the update lock held, after version and completion are durable. */
    private function cleanupSuccessfulTask(array $journal): array
    {
        if (! isset($journal['completion']) || ($journal['cleanup']['status'] ?? '') === 'complete') {
            return $journal;
        }
        try {
            $id = $journal['id'];
            $this->assertCompletedReceipt($journal, $id);
            $saved = $this->journal($id);
            $this->assertCompletedReceipt($saved, $id);
            $receipt = $this->state->read('task-'.$id.'.json');
            if ($receipt === null) {
                $version = $this->state->version();
                if ($saved['completion'] !== $journal['completion']
                    || ($saved['to'] ?? null) !== $version['program']
                    || ! hash_equals($saved['completion']['version_sha256'], $this->versionHash($version))) {
                    throw new RuntimeException('成功版本状态尚未可靠保存，暂不清理。');
                }
                $receipt = array_intersect_key($saved, array_flip(['id', 'status', 'stage', 'active', 'from', 'to', 'completion', 'log']));
            }
            $this->assertCompletedReceipt($receipt, $id);
            $receipt = $this->cleanupReceipt($receipt);
            $journal['cleanup'] = $receipt['cleanup'];
        } catch (Throwable) {
            $journal['cleanup'] = ['status' => 'pending', 'error' => '成功任务暂存或备份清理未完成，将在下次维护时重试。'];
        }
        try {
            $saved = $this->journal($journal['id']);
            if (($saved['cleanup']['status'] ?? null) !== $journal['cleanup']['status']) {
                $this->log($journal, $journal['cleanup']['status'] === 'complete'
                    ? '成功任务的更新暂存、程序备份和数据库备份已清理。'
                    : '更新已成功；暂存或备份清理待重试，不影响后台使用。');
            }
            $this->state->write('journal.json', $journal);
        } catch (Throwable) {
            // The durable success receipt remains available to the maintenance retry.
        }

        return $journal;
    }

    private function assertCompletedReceipt(?array $receipt, string $id): void
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/D', $id) || ($receipt['id'] ?? null) !== $id
            || ($receipt['status'] ?? null) !== 'complete' || ($receipt['stage'] ?? null) !== 'complete'
            || ($receipt['active'] ?? null) !== false || ! is_string($receipt['completion']['checked_at'] ?? null)
            || ! is_string($receipt['completion']['version_sha256'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $receipt['completion']['version_sha256'])) {
            throw new RuntimeException('缺少独立且完整的成功任务凭据，禁止清理。');
        }
    }

    private function versionHash(array $version): string
    {
        return hash('sha256', json_encode($version, JSON_THROW_ON_ERROR));
    }

    private function cleanupReceipt(array $receipt): array
    {
        if (($receipt['cleanup']['status'] ?? '') === 'complete') {
            return $receipt;
        }
        $receipt['cleanup'] = ['status' => 'pending', 'attempts' => (int) ($receipt['cleanup']['attempts'] ?? 0) + 1, 'attempted_at' => gmdate('c')];
        $name = 'task-'.$receipt['id'].'.json';
        $this->state->write($name, $receipt);
        try {
            $task = $this->task($receipt['id']);
            foreach (['payload', 'backup'] as $part) {
                $this->inspectCleanupTree($task.'/'.$part, $task);
            }
            foreach (['payload', 'backup'] as $part) {
                $this->deleteCleanupTree($task.'/'.$part, $task);
            }
            $receipt['cleanup']['status'] = 'complete';
            $receipt['cleanup']['completed_at'] = gmdate('c');
        } catch (Throwable) {
            $receipt['cleanup']['error'] = '暂存或备份清理未完成；请检查路径与权限后重试维护。';
        }
        $this->state->write($name, $receipt);

        return $receipt;
    }

    private function assertCleanupPath(string $path, string $task): void
    {
        if ($path !== $task.'/payload' && ! str_starts_with($path, $task.'/payload/')
            && $path !== $task.'/backup' && ! str_starts_with($path, $task.'/backup/')) {
            throw new RuntimeException('清理路径不在当前任务范围。');
        }
        for ($part = $path; $part !== $this->root; $part = dirname($part)) {
            clearstatcache(true, $part);
            if (is_link($part) || (file_exists($part) && $part !== $path && ! is_dir($part))) {
                throw new RuntimeException('清理路径包含符号链接或无效父目录。');
            }
        }
    }

    private function inspectCleanupTree(string $path, string $task): void
    {
        $this->assertCleanupPath($path, $task);
        if (! file_exists($path)) {
            return;
        }
        if (is_dir($path)) {
            $entries = @scandir($path);
            if ($entries === false) {
                throw new RuntimeException('无法检查任务清理目录。');
            }
            foreach (array_diff($entries, ['.', '..']) as $entry) {
                $this->inspectCleanupTree($path.'/'.$entry, $task);
            }
        } elseif (! is_file($path)) {
            throw new RuntimeException('任务清理目录含有特殊文件。');
        }
    }

    private function deleteCleanupTree(string $path, string $task): void
    {
        $this->assertCleanupPath($path, $task);
        if (! file_exists($path)) {
            return;
        }
        if (is_dir($path)) {
            $entries = @scandir($path);
            if ($entries === false) {
                throw new RuntimeException('无法读取任务清理目录。');
            }
            foreach (array_diff($entries, ['.', '..']) as $entry) {
                $this->deleteCleanupTree($path.'/'.$entry, $task);
            }
            $this->assertCleanupPath($path, $task);
            if (! @rmdir($path)) {
                throw new RuntimeException('任务清理目录删除失败。');
            }
        } elseif (! is_file($path) || ! @unlink($path)) {
            throw new RuntimeException('任务清理文件删除失败。');
        }
    }

    public function advance(string $id, ?string $expectedStage = null): array
    {
        return $this->state->locked(function () use ($id, $expectedStage): array {
            $journal = $this->journal($id);
            if ($journal['status'] === 'complete') {
                return $this->publicStatus($this->cleanupSuccessfulTask($journal));
            }
            if ($expectedStage !== null && $journal['stage'] !== $expectedStage) {
                return $this->publicStatus($journal);
            }
            if (in_array($journal['status'], ['restored', 'cancelled'], true)) {
                return $this->publicStatus($journal);
            }
            if ($journal['status'] === 'failed') {
                throw new RuntimeException('更新失败，当前任务只能恢复，不能重复执行迁移。');
            }
            $journal['active'] = true;
            $this->state->write('journal.json', $journal);
            $lock = $this->writeLock();
            $this->phaseLock = $lock;
            try {
                $task = $this->task($id);
                if ($journal['stage'] === 'prepared') {
                    $journal['stage'] = 'backup';
                    $this->log($journal, '已暂停后台写入并取得排他写锁，开始完整备份。');
                    $this->state->write('journal.json', $journal);
                }
                if ($journal['stage'] === 'backup') {
                    if (is_dir($task.'/backup')) {
                        $this->removeTree($task.'/backup');
                    }
                    $this->assertEnvironment($journal);
                    $admin = $this->database()->query('SELECT email,password FROM users LIMIT 1')->fetch(PDO::FETCH_ASSOC);
                    $this->state->write('recovery-account.json', ['task' => $id, 'email' => strtolower($admin['email']), 'password_hash' => $admin['password']]);
                    (new DatabaseSnapshot($this->database()))->checkPermissions();
                    $journal['backup'] = $this->backup($journal, $task);
                    $journal['stage'] = 'files';
                    $this->log($journal, '数据库及将替换的程序备份完整性校验通过。');
                } elseif ($journal['stage'] === 'files') {
                    $this->verifyBackup($journal, $task);
                    $this->replaceFiles($journal, $task);
                    $journal['stage'] = 'migrate';
                    $this->log($journal, '程序替换完成，等待执行全部缺失迁移。');
                } elseif ($journal['stage'] === 'migrate') {
                    $journal['stage'] = 'migration-running';
                    $this->log($journal, '开始迁移；若请求中断，不自动重跑，使用完整备份恢复。');
                    $this->state->write('journal.json', $journal);
                    $this->assertEnvironment($journal);
                    $this->validateChildDatabase();
                    $this->runPhp(['artisan', 'migrate', '--force', '--no-interaction'], 180);
                    $journal['stage'] = 'health';
                    $this->log($journal, '全部缺失迁移执行完成。');
                } elseif ($journal['stage'] === 'health') {
                    $this->assertEnvironment($journal);
                    $this->clearCaches();
                    $this->health($journal['manifest'], $journal['before']['managed_files']);
                    $this->assertEnvironment($journal);
                    $this->invalidateSessions();
                    $version = ['program' => $journal['manifest']['program'], 'schema' => $journal['manifest']['schema'], 'baseline' => $journal['manifest']['program'], 'managed_files' => array_keys($journal['manifest']['files']), 'schema_fingerprint' => self::schemaFingerprint($this->database())];
                    $this->state->write('version.json', $version);
                    $journal['completion'] = ['checked_at' => gmdate('c'), 'version_sha256' => $this->versionHash($version)];
                    $journal['status'] = 'complete';
                    $journal['stage'] = 'complete';
                    $journal['active'] = false;
                    $this->log($journal, '健康检查通过，更新完成，后台恢复写入。');
                } elseif ($journal['stage'] === 'migration-running') {
                    throw new RuntimeException('检测到迁移中断，必须从完整备份恢复。');
                } else {
                    throw new RuntimeException('未知更新阶段，维护状态保留。');
                }
                if ($journal['status'] !== 'complete') {
                    $journal['status'] = 'running';
                }
            } catch (Throwable $failure) {
                $journal['status'] = 'failed';
                $journal['error'] = $failure instanceof RuntimeException && ! $failure instanceof \PDOException ? $failure->getMessage() : '当前阶段执行失败；内部错误未公开，请恢复到更新前版本。';
                $this->log($journal, $journal['error']);
            } finally {
                $this->phaseLock = null;
                flock($lock, LOCK_UN);
                fclose($lock);
                $this->state->write('journal.json', $journal);
            }

            if ($journal['status'] === 'complete') {
                $journal = $this->cleanupSuccessfulTask($journal);
            }

            return $this->publicStatus($journal);
        });
    }

    public function restore(string $id): array
    {
        return $this->state->locked(function () use ($id): array {
            $journal = $this->journal($id);
            if (in_array($journal['status'], ['restored', 'cancelled'], true)) {
                return $this->publicStatus($journal);
            }
            if ($journal['status'] === 'complete') {
                throw new RuntimeException('已完成更新不能在线撤销，以免丢失更新后的新数据。');
            }
            $lock = $this->writeLock();
            $this->phaseLock = $lock;
            try {
                if (in_array($journal['stage'], ['prepared', 'backup'], true) && ! isset($journal['backup'])) {
                    $journal['status'] = 'cancelled';
                    $journal['stage'] = 'cancelled';
                    $journal['active'] = false;
                    $this->log($journal, '程序和业务表尚未变更，已安全取消更新。');
                } else {
                    $task = $this->task($id);
                    $this->assertEnvironment($journal);
                    $backup = $this->verifyBackup($journal, $task);
                    $journal['status'] = 'restoring';
                    $journal['active'] = true;
                    $this->log($journal, '全部备份校验通过，开始恢复程序和数据库。');
                    $this->state->write('journal.json', $journal);
                    $this->restoreFiles($journal, $task, $backup);
                    (new DatabaseSnapshot($this->database()))->restore($task.'/backup/database', $journal['backup']['database']);
                    $this->clearCaches();
                    $this->invalidateSessions();
                    $this->state->write('version.json', $journal['before']);
                    $this->health(['schema' => $journal['before']['schema']]);
                    $journal['status'] = 'restored';
                    $journal['stage'] = 'restored';
                    $journal['active'] = false;
                    unset($journal['error']);
                    $this->log($journal, '已恢复更新前程序及数据库，健康检查通过。');
                }
            } catch (Throwable $failure) {
                $journal['status'] = 'failed';
                $journal['active'] = true;
                $journal['error'] = $failure instanceof RuntimeException && ! $failure instanceof \PDOException ? $failure->getMessage() : '恢复未完成，维护状态保留；请保留全部备份。';
                $this->log($journal, $journal['error']);
            } finally {
                $this->phaseLock = null;
                flock($lock, LOCK_UN);
                fclose($lock);
                $this->state->write('journal.json', $journal);
            }

            return $this->publicStatus($journal);
        });
    }

    private function preflight(array $manifest, array $current): void
    {
        foreach ((new UpdateRequirements($this->phpBinary()))->checks() as $check) {
            if (! $check['ok']) {
                throw new RuntimeException($check['label'].'：'.$check['detail'].' '.$check['fix']);
            }
        }
        if (version_compare(PHP_VERSION, $manifest['php_min'], '<')) {
            throw new RuntimeException('PHP 版本不满足更新包要求。');
        }
        $snapshot = new DatabaseSnapshot($this->database());
        $snapshot->inspect();
        $this->checkDeclaredPermissions();
        $pdo = $this->database();
        if (! is_string($current['schema_fingerprint'] ?? null) || ! hash_equals($current['schema_fingerprint'], self::schemaFingerprint($pdo)) || (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 1) {
            throw new RuntimeException('当前数据库结构或管理员与已记录版本基线不一致。');
        }
        $actual = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        $schema = $current['schema'];
        sort($schema);
        if ($actual !== $schema || array_diff($schema, $manifest['schema']) !== []) {
            throw new RuntimeException('数据库迁移记录与程序版本不一致，更新已阻止。');
        }
        $required = 67108864;
        foreach ($manifest['files'] as $path => $metadata) {
            $target = $this->target($path);
            if (file_exists($target) && ! in_array($path, $current['managed_files'], true)) {
                throw new RuntimeException('更新包会覆盖未托管文件，已阻止更新。');
            }
            if (file_exists($target) && ! is_file($target)) {
                throw new RuntimeException('程序目标不是普通文件。');
            }
            if (file_exists($target) && ! is_writable($target)) {
                throw new RuntimeException('程序文件不可写，请修复本站 PHP 用户所有权。');
            }
            $parent = dirname($target);
            while (! is_dir($parent)) {
                $parent = dirname($parent);
            }
            if (! is_writable($parent)) {
                throw new RuntimeException('程序目录不可写，请修复本站 PHP 用户所有权。');
            }
            $required += $metadata['size'] * 3 + (is_file($target) ? filesize($target) : 0);
        }
        $required += (int) $this->database()->query('SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() * 6;
        if (disk_free_space($this->root) < $required || disk_free_space($this->state->directory()) < $required) {
            throw new RuntimeException('磁盘空间不足以暂存更新和完整恢复备份。');
        }
    }

    private function checkDeclaredPermissions(): void
    {
        $pdo = $this->database();
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $privileges = [];
        foreach ($pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (! preg_match('/\AGRANT (.+) ON (.+) TO /', $grant, $matches)) {
                continue;
            }
            $scope = str_replace(['`', '\\_'], ['', '_'], $matches[2]);
            if (! in_array($scope, [$database.'.*', '*.*'], true)) {
                continue;
            }
            foreach (explode(', ', $matches[1]) as $privilege) {
                $privileges[$privilege] = true;
            }
        }
        if (! isset($privileges['ALL PRIVILEGES']) && array_diff(['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'REFERENCES'], array_keys($privileges)) !== []) {
            throw new RuntimeException('数据库账号缺少可确认的更新及恢复权限；请在宝塔为本站独立库授予完整权限。');
        }
    }

    private function backup(array $journal, string $task): array
    {
        $directory = $task.'/backup';
        $this->makeDirectory($directory.'/files');
        $files = [];
        $paths = array_unique([...array_keys($journal['manifest']['files']), ...($journal['before']['managed_files'] ?? [])]);
        foreach ($paths as $path) {
            $target = $this->target($path);
            if (is_file($target)) {
                $backup = $directory.'/files/'.$path;
                $this->makeDirectory(dirname($backup));
                if (! copy($target, $backup) || ! chmod($backup, 0600)) {
                    throw new RuntimeException('程序备份写入失败。');
                }
                $handle = fopen($backup, 'rb');
                if ($handle === false || ! fsync($handle)) {
                    throw new RuntimeException('程序备份同步失败。');
                }
                fclose($handle);
                $files[$path] = ['sha256' => hash_file('sha256', $backup), 'size' => filesize($backup), 'mode' => fileperms($target) & 0777];
            } else {
                $files[$path] = null;
            }
        }
        $body = json_encode($files, JSON_THROW_ON_ERROR);
        $path = $directory.'/files.json';
        if (file_put_contents($path, $body, LOCK_EX) !== strlen($body) || ! chmod($path, 0600)) {
            throw new RuntimeException('程序备份清单写入失败。');
        }
        $database = (new DatabaseSnapshot($this->database()))->create($directory.'/database');

        return ['files_sha256' => hash('sha256', $body), 'database' => $database];
    }

    private function verifyBackup(array $journal, string $task): array
    {
        if (! isset($journal['backup']['files_sha256'], $journal['backup']['database'])) {
            throw new RuntimeException('备份未完成，禁止破坏性恢复。');
        }
        $path = $task.'/backup/files.json';
        if (is_link($path) || ! is_file($path) || ! hash_equals($journal['backup']['files_sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('程序备份清单损坏，禁止恢复。');
        }
        $files = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $paths = array_unique([...array_keys($journal['manifest']['files']), ...($journal['before']['managed_files'] ?? [])]);
        if (array_diff($paths, array_keys($files)) !== [] || array_diff(array_keys($files), $paths) !== []) {
            throw new RuntimeException('程序备份清单不完整，禁止恢复。');
        }
        foreach ($files as $relative => $metadata) {
            $this->target($relative);
            if ($metadata !== null) {
                $source = $task.'/backup/files/'.$relative;
                if (is_link($source) || ! is_file($source) || filesize($source) !== $metadata['size'] || ! hash_equals($metadata['sha256'], hash_file('sha256', $source))) {
                    throw new RuntimeException('程序备份损坏，禁止恢复。');
                }
            }
        }
        (new DatabaseSnapshot($this->database()))->verify($task.'/backup/database', $journal['backup']['database']);

        return $files;
    }

    private function replaceFiles(array $journal, string $task): void
    {
        foreach ($journal['manifest']['files'] as $path => $metadata) {
            $source = $task.'/payload/'.$path;
            if (is_link($source) || ! is_file($source) || ! hash_equals($metadata['sha256'], hash_file('sha256', $source))) {
                throw new RuntimeException('暂存程序文件损坏，停止更新。');
            }
            $this->copyAtomic($source, $this->target($path), 0644, $metadata['sha256']);
        }
        $backupFiles = json_decode(file_get_contents($task.'/backup/files.json'), true, 64, JSON_THROW_ON_ERROR);
        foreach ($journal['before']['managed_files'] ?? [] as $path) {
            if (! isset($journal['manifest']['files'][$path]) && in_array($path, ['public/index.php', 'bootstrap/update-gateway.php', 'bootstrap/update-public.pem'], true)) {
                throw new RuntimeException('更新包不得移除固定恢复入口。');
            }
            if (! isset($journal['manifest']['files'][$path]) && is_file($this->target($path))) {
                if (! isset($backupFiles[$path]['sha256']) || ! hash_equals($backupFiles[$path]['sha256'], hash_file('sha256', $this->target($path)))) {
                    throw new RuntimeException('旧版本托管文件在备份后被修改，停止清理。');
                }
                if (! unlink($this->target($path))) {
                    throw new RuntimeException('旧版本托管文件清理失败。');
                }
            }
        }
        $this->clearCaches();
    }

    private function restoreFiles(array $journal, string $task, array $files): void
    {
        foreach ($files as $path => $metadata) {
            $target = $this->target($path);
            if ($metadata !== null) {
                $this->copyAtomic($task.'/backup/files/'.$path, $target, $metadata['mode'], $metadata['sha256']);
            } elseif (is_file($target)) {
                $expected = $journal['manifest']['files'][$path]['sha256'] ?? null;
                if ($expected === null || ! hash_equals($expected, hash_file('sha256', $target))) {
                    throw new RuntimeException('新增程序文件已被外部修改，恢复停止。');
                }
                if (! unlink($target)) {
                    throw new RuntimeException('新增程序文件清理失败。');
                }
            }
        }
    }

    /** @param list<string> $previousFiles */
    private function health(array $manifest, array $previousFiles = []): void
    {
        foreach ($manifest['files'] ?? [] as $path => $metadata) {
            $target = $this->target($path);
            clearstatcache(true, $target);
            if (! is_file($target) || filesize($target) !== $metadata['size'] || ! hash_equals($metadata['sha256'], hash_file('sha256', $target))) {
                throw new RuntimeException('健康检查失败：已安装程序文件与签名清单不一致。');
            }
        }
        foreach (array_diff($previousFiles, array_keys($manifest['files'] ?? [])) as $path) {
            if (file_exists($this->target($path))) {
                throw new RuntimeException('健康检查失败：旧版本托管文件未移除。');
            }
        }
        $this->validateChildDatabase();
        $probe = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (Illuminate\Support\Facades\DB::table("users")->count() !== 1) {
    exit(2);
}
$app["view"]->share("errors", new Illuminate\Support\ViewErrorBag);
$app["view"]->make("login")->render();
foreach (["login", "library", "upload", "updates"] as $name) {
    if (!$app["router"]->getRoutes()->getByName($name)) {
        exit(3);
    }
}
App\Models\Group::query()->limit(1)->get();
App\Models\Image::query()->limit(1)->get();
App\Models\Setting::uploadOptions();
if (in_array("2026_10_28_091720_create_storage_configurations_table", EXPECTED_SCHEMA, true)) {
    foreach (App\Models\StorageConfiguration::all() as $configuration) {
        $configuration->credentials;
    }
    $app->make(App\Http\Controllers\VaultController::class)->uploadPage()->render();
}
if (in_array("2026_10_28_091721_create_storage_operations_table", EXPECTED_SCHEMA, true)) {
    Illuminate\Support\Facades\DB::table("storage_operations")->limit(1)->get();
}
PHP;
        $this->runPhp(['-r', str_replace('EXPECTED_SCHEMA', var_export($manifest['schema'], true), $probe)], 30);
        $actual = $this->database()->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        $expected = $manifest['schema'];
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('健康检查失败：数据库迁移记录不完整。');
        }
    }

    private function clearCaches(): void
    {
        foreach (glob($this->root.'/bootstrap/cache/*.php') ?: [] as $path) {
            if (is_link($path) || ! unlink($path)) {
                throw new RuntimeException('无法清理启动缓存。');
            }
        }
        foreach (glob($this->root.'/storage/framework/views/*.php') ?: [] as $path) {
            if (is_link($path) || ! unlink($path)) {
                throw new RuntimeException('无法清理视图缓存。');
            }
        }
        if (function_exists('opcache_reset') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN) && PHP_SAPI !== 'cli' && ! opcache_reset()) {
            throw new RuntimeException('OPcache 清理失败，维护状态保留。');
        }
    }

    private function runPhp(array $arguments, int $timeout): void
    {
        $binary = $this->phpBinary();
        $command = [$binary];
        array_push($command, ...$arguments);
        $environment = getenv();
        foreach (array_keys($environment) as $name) {
            if (preg_match('/\A(APP_|DB_|CACHE_|SESSION_|QUEUE_|MAIL_|IMAGE_)/', $name)) {
                unset($environment[$name]);
            }
        }
        $environment['APP_ENV'] = 'production';
        $config = $this->databaseConfiguration();
        foreach (['DB_CONNECTION' => 'driver', 'DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_DATABASE' => 'database', 'DB_USERNAME' => 'username', 'DB_PASSWORD' => 'password'] as $name => $key) {
            $environment[$name] = (string) ($config[$key] ?? ($key === 'driver' ? 'mysql' : ''));
        }
        $environment['DB_URL'] = '';
        $environment['DB_SOCKET'] = '';
        $pipes = [];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if (is_resource($this->phaseLock)) {
            $descriptors[3] = $this->phaseLock;
        }
        $process = proc_open($command, $descriptors, $pipes, $this->root, $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('无法启动同版本 PHP CLI；请在宝塔启用本站 PHP CLI 和 proc_open。');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = microtime(true);
        $exit = -1;
        try {
            do {
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (! $status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                if (microtime(true) - $start > $timeout) {
                    proc_terminate($process, 9);
                    throw new RuntimeException('PHP 子进程超时；维护状态保留，请从备份恢复。');
                }
                usleep(50000);
            } while (true);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        if ($exit !== 0) {
            throw new RuntimeException('PHP CLI、迁移或启动健康检查失败；内部输出已隐藏，请使用受保护恢复入口。');
        }
    }

    private function validateChildDatabase(): void
    {
        $config = $this->databaseConfiguration();
        $identity = [$config['driver'] ?? 'mysql', (string) $config['host'], (string) $config['port'], (string) $config['database'], (string) $config['username']];
        $hash = hash('sha256', json_encode($identity));
        $this->runPhp(['-r', 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $c=$app["config"]->get("database.connections.mysql"); $v=[$c["driver"],(string)$c["host"],(string)$c["port"],(string)$c["database"],(string)$c["username"]]; if($app["config"]->get("database.default")!=="mysql" || !empty($c["url"]) || !empty($c["unix_socket"]) || !hash_equals("'.$hash.'",hash("sha256",json_encode($v)))){exit(3);}'], 20);
    }

    private function environmentHash(): string
    {
        $path = $this->root.'/.env';
        if (is_link($path) || ! is_file($path) || filesize($path) > 1048576) {
            throw new RuntimeException('已有环境配置不可安全读取。');
        }

        return hash_file('sha256', $path);
    }

    private function assertEnvironment(array $journal): void
    {
        if (! hash_equals($journal['environment_sha256'] ?? '', $this->environmentHash())) {
            throw new RuntimeException('环境配置在检查后发生变化，更新或恢复已阻止；请保留原 APP_KEY 和数据库配置。');
        }
    }

    private function invalidateSessions(): void
    {
        $this->database()->exec('DELETE FROM sessions');
        $this->database()->exec('UPDATE users SET remember_token=NULL');
        foreach (glob($this->root.'/storage/framework/sessions/*') ?: [] as $path) {
            if (is_file($path) && ! is_link($path) && ! unlink($path)) {
                throw new RuntimeException('恢复后会话清理失败。');
            }
        }
    }

    private function phpBinary(): string
    {
        $binary = UpdateRequirements::phpBinary($this->phpCli ?? ($this->connection['php_cli'] ?? null) ?? ($this->state->read('connection.json')['php_cli'] ?? null));
        if (! is_file($binary) || ! is_executable($binary)) {
            throw new RuntimeException('本站 PHP CLI 不可执行，更新已阻止。');
        }

        return $binary;
    }

    private function database(): PDO
    {
        $config = $this->databaseConfiguration();
        if (($config['driver'] ?? 'mysql') !== 'mysql' || ! empty($config['url']) || ! empty($config['unix_socket']) || ! preg_match('/\A[a-zA-Z0-9_]+\z/D', $config['database'] ?? '')) {
            throw new RuntimeException('更新仅支持独立 MySQL TCP 数据库。');
        }

        return new PDO('mysql:host='.$config['host'].';port='.(int) $config['port'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    }

    private function databaseConfiguration(): array
    {
        $config = $this->connection ?? $this->state->read('connection.json');
        if (! is_array($config)) {
            throw new RuntimeException('更新数据库配置尚未通过管理员认证建立。');
        }

        $config['php_cli'] = $this->phpCli ?? ($config['php_cli'] ?? null) ?? (getenv('IMAGE_VAULT_UPDATE_PHP_CLI') ?: null);

        return array_intersect_key($config, array_flip(['driver', 'host', 'port', 'database', 'username', 'password', 'url', 'unix_socket', 'php_cli']));
    }

    private function freezeRuntime(): void
    {
        $base = $this->state->directory();
        $directory = $base.'/runtime-new-'.bin2hex(random_bytes(8));
        $this->makeDirectory($directory);
        foreach (glob($this->root.'/app/Services/Updates/*.php') ?: [] as $source) {
            $this->copyAtomic($source, $directory.'/'.basename($source), 0600);
        }
        foreach (['bootstrap/update-public.pem' => 'update-public.pem', 'resources/views/update-recovery.php' => 'update-recovery.php'] as $source => $target) {
            if (is_file($this->root.'/'.$source)) {
                $this->copyAtomic($this->root.'/'.$source, $directory.'/'.$target, 0600);
            }
        }
        if (is_link($base.'/runtime') || is_link($base.'/runtime-previous')) {
            throw new RuntimeException('恢复运行时路径无效。');
        }
        if (is_dir($base.'/runtime-previous')) {
            $this->removeTree($base.'/runtime-previous');
        }
        if (is_dir($base.'/runtime') && ! rename($base.'/runtime', $base.'/runtime-previous')) {
            throw new RuntimeException('无法保留旧恢复运行时。');
        }
        if (! rename($directory, $base.'/runtime')) {
            if (is_dir($base.'/runtime-previous')) {
                rename($base.'/runtime-previous', $base.'/runtime');
            }
            throw new RuntimeException('无法发布独立恢复运行时。');
        }
    }

    private function target(string $path): string
    {
        if (! UpdatePackage::allowedPath($path)) {
            throw new RuntimeException('程序路径不在允许更新范围。');
        }
        $target = $this->root.'/'.$path;
        for ($part = $target; $part !== $this->root; $part = dirname($part)) {
            if (is_link($part)) {
                throw new RuntimeException('程序路径包含符号链接。');
            }
        }

        return $target;
    }

    private function copyAtomic(string $source, string $target, int $mode, ?string $expected = null): void
    {
        $this->makeDirectory(dirname($target), str_starts_with($target, $this->state->directory()) ? 0700 : 0755);
        $expected ??= hash_file('sha256', $source);
        if (is_link($source) || ! hash_equals($expected, hash_file('sha256', $source))) {
            throw new RuntimeException('程序来源校验失败。');
        }
        $temporary = dirname($target).'/.update-'.bin2hex(random_bytes(8));
        try {
            if (! copy($source, $temporary) || ! chmod($temporary, $mode)) {
                throw new RuntimeException('程序暂存写入失败。');
            }
            $handle = fopen($temporary, 'rb');
            if ($handle === false) {
                throw new RuntimeException('程序暂存同步失败。');
            }
            try {
                if (! fsync($handle)) {
                    throw new RuntimeException('程序暂存同步失败。');
                }
            } finally {
                fclose($handle);
            }
            if (! hash_equals($expected, hash_file('sha256', $temporary)) || ! rename($temporary, $target)) {
                throw new RuntimeException('程序原子替换失败。');
            }
            if (function_exists('opcache_invalidate') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN) && PHP_SAPI !== 'cli' && ! opcache_invalidate($target, true)) {
                throw new RuntimeException('程序 OPcache 失效失败，维护状态保留。');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function makeDirectory(string $directory, int $mode = 0700): void
    {
        if (is_link($directory) || (! is_dir($directory) && ! mkdir($directory, $mode, true))) {
            throw new RuntimeException('无法创建更新目录。');
        }
    }

    private function journal(string $id): array
    {
        $journal = $this->state->read('journal.json');
        if (! preg_match('/\A[a-f0-9]{32}\z/D', $id) || ! $journal || ! hash_equals($journal['id'], $id)) {
            throw new RuntimeException('更新任务不匹配。');
        }

        return $journal;
    }

    private function task(string $id): string
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            throw new RuntimeException('更新任务目录无效。');
        }
        $path = $this->state->directory().'/tasks/'.$id;
        foreach ([dirname($path), $path] as $part) {
            if (is_link($part) || (file_exists($part) && ! is_dir($part))) {
                throw new RuntimeException('更新任务目录路径无效。');
            }
        }

        return $path;
    }

    /** @return resource */
    private function writeLock(): mixed
    {
        $path = $this->state->directory().'/write.lock';
        if (is_link($path)) {
            throw new RuntimeException('后台写锁路径无效。');
        }
        $handle = fopen($path, 'c');
        if ($handle === false || ! chmod($path, 0600) || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('仍有后台写入正在完成，请稍后继续当前任务。');
        }

        return $handle;
    }

    private function publicStatus(array $journal): array
    {
        $status = array_intersect_key($journal, array_flip(['id', 'status', 'stage', 'active', 'from', 'to', 'log', 'error', 'cleanup']));
        $manifest = $journal['manifest'];
        $status['backup_ready'] = isset($journal['backup']) && $journal['status'] !== 'complete';
        $status['package'] = ['program' => $manifest['program'], 'schema_count' => count($manifest['schema']), 'pending_count' => count(array_diff($manifest['schema'], $journal['before']['schema'])), 'compatible' => $manifest['compatible'], 'php_min' => $manifest['php_min'], 'release_notes' => $manifest['release_notes'] ?? '发行方未提供说明'];

        return $status;
    }

    private function log(array &$journal, string $message): void
    {
        $journal['log'][] = ['time' => gmdate('c'), 'stage' => $journal['stage'], 'message' => $message];
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path.'/'.$name);
            }
        }
        rmdir($path);
    }
}
