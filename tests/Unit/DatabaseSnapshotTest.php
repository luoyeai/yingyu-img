<?php

namespace Tests\Unit;

use App\Services\Updates\DatabaseSnapshot;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseSnapshotTest extends TestCase
{
    private string $root;

    private string $database;

    private PDO $admin;

    private PDO $pdo;

    private array $credentials;

    private ?string $restrictedUser = null;

    protected function setUp(): void
    {
        $project = dirname(__DIR__, 2);
        $credentials = json_decode(file_get_contents($project.'/.tools/runtime/mysql/credentials.json'), true, 32, JSON_THROW_ON_ERROR);
        if ($credentials['host'] !== '127.0.0.1' || (int) $credentials['port'] !== 13368 || $credentials['database'] !== 'image_vault') {
            throw new RuntimeException('Refused snapshot fixture before database connection.');
        }
        $this->credentials = $credentials;
        $this->database = 'image_vault_snapshottest_'.bin2hex(random_bytes(6));
        $this->admin = new PDO('mysql:host=127.0.0.1;port=13368;charset=utf8mb4', 'root', $credentials['root_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->admin->exec('CREATE DATABASE `'.$this->database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->admin->exec('GRANT ALL PRIVILEGES ON `'.$this->database.'`.* TO '.$this->admin->quote($credentials['username'])."@'127.0.0.1'");
        $this->pdo = $this->connect();
        $this->root = realpath(sys_get_temp_dir()).'/vault-snapshot-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->pdo->exec('CREATE TABLE sample (id INT PRIMARY KEY, value LONGBLOB NULL, metadata JSON NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $this->pdo->prepare('INSERT INTO sample (id,value,metadata) VALUES (?,?,?)')->execute([1, "binary\0\xff\x01", '{"message":"测试"}']);
        $this->pdo->prepare('INSERT INTO sample (id,value,metadata) VALUES (?,?,?)')->execute([2, null, null]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && preg_match('/\Aimage_vault_snapshottest_[a-f0-9]{12}\z/D', $this->database)) {
            $this->admin->exec('DROP DATABASE `'.$this->database.'`');
        }
        if ($this->restrictedUser !== null) {
            $this->admin->exec('DROP USER '.$this->admin->quote($this->restrictedUser)."@'127.0.0.1'");
        }
        if (isset($this->root)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($this->root);
        }
    }

    public function test_binary_null_and_default_timestamp_snapshot_recovers_empty_and_partial_databases(): void
    {
        $snapshot = new DatabaseSnapshot($this->pdo);
        $before = $this->pdo->query('SELECT * FROM sample ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $descriptor = $snapshot->create($this->root.'/backup');
        $this->assertSame(1, $descriptor['tables']);
        $this->pdo->exec('DROP TABLE sample');
        $snapshot->restore($this->root.'/backup', $descriptor);
        $this->assertSame($before, $this->pdo->query('SELECT * FROM sample ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $this->pdo->exec('DELETE FROM sample WHERE id=1');
        $this->pdo->exec('CREATE TABLE partial_migration (id INT, doubled INT GENERATED ALWAYS AS (id*2) STORED) ENGINE=InnoDB');
        $this->pdo->exec('CREATE VIEW migration_view AS SELECT id FROM sample');
        $this->pdo->exec('CREATE PROCEDURE migration_routine() SELECT 1');
        $this->admin->exec('CREATE TRIGGER `'.$this->database.'`.migration_trigger BEFORE INSERT ON `'.$this->database.'`.sample FOR EACH ROW SET NEW.id=NEW.id');
        $this->pdo->exec('CREATE EVENT migration_event ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY DISABLE DO SELECT 1');
        $snapshot->restore($this->root.'/backup', $descriptor);
        $this->assertSame($before, $this->pdo->query('SELECT * FROM sample ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame(['sample'], $snapshot->inspect());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn());
    }

    public function test_corrupt_or_missing_backup_cannot_drop_existing_data(): void
    {
        $snapshot = new DatabaseSnapshot($this->pdo);
        $descriptor = $snapshot->create($this->root.'/backup');
        $rows = file_get_contents($this->root.'/backup/sample.rows');
        foreach (['corrupt', substr($rows, 0, -2)] as $body) {
            file_put_contents($this->root.'/backup/sample.rows', $body);
            try {
                $snapshot->restore($this->root.'/backup', $descriptor);
                $this->fail('Corrupt input triggered restore.');
            } catch (RuntimeException) {
                $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM sample')->fetchColumn());
            }
        }
        unlink($this->root.'/backup/sample.rows');
        $this->expectException(RuntimeException::class);
        $snapshot->restore($this->root.'/backup', $descriptor);
    }

    public function test_pinned_verified_streams_survive_original_backup_replacement_before_drop(): void
    {
        $dsn = 'mysql:host=127.0.0.1;port=13368;dbname='.$this->database.';charset=utf8mb4';
        $hook = new class($dsn, $this->credentials['username'], $this->credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends PDO
        {
            public ?\Closure $beforeDrop = null;

            public function exec(string $statement): int|false
            {
                if (str_starts_with($statement, 'DROP ') && $this->beforeDrop !== null) {
                    $callback = $this->beforeDrop;
                    $this->beforeDrop = null;
                    $callback();
                }

                return parent::exec($statement);
            }
        };
        $snapshot = new DatabaseSnapshot($hook);
        $descriptor = $snapshot->create($this->root.'/backup');
        $hook->beforeDrop = function (): void {
            file_put_contents($this->root.'/backup/sample.rows', 'changed after verification');
            file_put_contents($this->root.'/backup/manifest.json', '{}');
        };
        $snapshot->restore($this->root.'/backup', $descriptor);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM sample')->fetchColumn());
        $this->assertSame("binary\0\xff\x01", $this->pdo->query('SELECT value FROM sample WHERE id=1')->fetchColumn());
    }

    public function test_partial_permissions_are_rejected_even_when_hidden_objects_appear_absent(): void
    {
        $this->restrictedUser = 'vault_snapshot_'.bin2hex(random_bytes(5));
        $password = bin2hex(random_bytes(24));
        $account = $this->admin->quote($this->restrictedUser)."@'127.0.0.1'";
        $this->admin->exec('CREATE USER '.$account.' IDENTIFIED BY '.$this->admin->quote($password));
        $this->admin->exec('GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX ON `'.$this->database.'`.* TO '.$account);
        $this->admin->exec('CREATE TRIGGER `'.$this->database.'`.hidden_trigger BEFORE INSERT ON `'.$this->database.'`.sample FOR EACH ROW SET NEW.id=NEW.id');
        $pdo = new PDO('mysql:host=127.0.0.1;port=13368;dbname='.$this->database.';charset=utf8mb4', $this->restrictedUser, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ALL PRIVILEGES');
        (new DatabaseSnapshot($pdo))->inspect();
    }

    public function test_restore_retries_after_interruption_between_drop_and_create(): void
    {
        $dsn = 'mysql:host=127.0.0.1;port=13368;dbname='.$this->database.';charset=utf8mb4';
        $hook = new class($dsn, $this->credentials['username'], $this->credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends PDO
        {
            public bool $failCreate = false;

            public function exec(string $statement): int|false
            {
                if ($this->failCreate && str_starts_with($statement, 'CREATE TABLE ')) {
                    $this->failCreate = false;
                    throw new RuntimeException('Injected restore interruption.');
                }

                return parent::exec($statement);
            }
        };
        $snapshot = new DatabaseSnapshot($hook);
        $descriptor = $snapshot->create($this->root.'/backup');
        $hook->failCreate = true;
        try {
            $snapshot->restore($this->root.'/backup', $descriptor);
            $this->fail('Injected interruption was skipped.');
        } catch (RuntimeException) {
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn());
        }
        $snapshot->restore($this->root.'/backup', $descriptor);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM sample')->fetchColumn());
    }

    public function test_preexisting_generated_columns_and_routines_are_refused_before_backup(): void
    {
        $snapshot = new DatabaseSnapshot($this->pdo);
        $this->pdo->exec('ALTER TABLE sample ADD doubled INT GENERATED ALWAYS AS (id*2) VIRTUAL');
        try {
            $snapshot->create($this->root.'/backup');
            $this->fail('Generated columns accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('生成列', $exception->getMessage());
            $this->assertDirectoryDoesNotExist($this->root.'/backup');
        }
        $this->pdo->exec('ALTER TABLE sample DROP doubled');
        $this->pdo->exec('CREATE PROCEDURE existing_routine() SELECT 1');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('例程');
        $snapshot->create($this->root.'/backup');
    }

    public function test_oversized_single_row_is_rejected_before_transferring_it_to_php(): void
    {
        $this->pdo->prepare('UPDATE sample SET value=? WHERE id=1')->execute([str_repeat('x', 13 * 1024 * 1024)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('单行过大');
        (new DatabaseSnapshot($this->pdo))->create($this->root.'/backup');
    }

    public function test_snapshot_streams_large_tables_with_bounded_php_memory(): void
    {
        $statement = $this->pdo->prepare('INSERT INTO sample (id,value) VALUES (?,?)');
        $body = str_repeat('x', 16384);
        $this->pdo->beginTransaction();
        for ($index = 3; $index <= 2050; $index++) {
            $statement->execute([$index, $body]);
        }
        $this->pdo->commit();
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $descriptor = (new DatabaseSnapshot($this->pdo))->create($this->root.'/backup');
        $increase = memory_get_peak_usage(true) - $before;
        $this->assertGreaterThan(40000000, $descriptor['bytes']);
        $this->assertLessThan(16777216, $increase);
        $this->assertSame(0600, fileperms($this->root.'/backup/sample.rows') & 0777);
        $this->assertTrue((bool) $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
    }

    private function connect(): PDO
    {
        return new PDO('mysql:host=127.0.0.1;port=13368;dbname='.$this->database.';charset=utf8mb4', $this->credentials['username'], $this->credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
