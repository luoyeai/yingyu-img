<?php

namespace Tests\Unit;

use App\Services\Installation\EnvFile;
use App\Services\Installation\InstallationState;
use App\Services\Installation\InstallationWeb;
use App\Services\Installation\Installer;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class InstallerCoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/vault-installer-core-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isLink() || ! $file->isDir()) {
                unlink($file->getPathname());
            } else {
                rmdir($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function test_environment_values_round_trip_without_expanding_or_corrupting_special_characters(): void
    {
        $environment = new EnvFile($this->root);
        $values = [
            'DB_PASSWORD' => 'space # double" single\' slash\\ dollars${APP_KEY}=$HOME',
            'APP_KEY' => 'base64:test-key', 'APP_NAME' => '图仓', 'EMPTY_VALUE' => '',
            'RESERVED_FALSE' => 'false', 'RESERVED_NULL' => 'null', 'QUOTED_VALUE' => '"literal quotes"',
        ];
        $environment->writeAtomic($values);
        $this->assertSame($values, $environment->read());
        $this->assertSame(0600, fileperms($this->root.'/.env') & 0777);
        $this->assertStringNotContainsString("\nAPP_DEBUG=true", $environment->snapshot());
    }

    public function test_environment_injection_is_rejected_without_echoing_the_value_or_replacing_existing_file(): void
    {
        $environment = new EnvFile($this->root);
        $environment->writeAtomic(['APP_NAME' => 'original']);
        foreach (["password\nAPP_DEBUG=true", "password\rDB_USERNAME=root", "password\0secret"] as $malicious) {
            try {
                $environment->writeAtomic(['DB_PASSWORD' => $malicious]);
                $this->fail('A control character was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringNotContainsString('password', $exception->getMessage());
                $this->assertSame(['APP_NAME' => 'original'], $environment->read());
            }
        }
    }

    public function test_environment_snapshot_is_restored_and_new_file_can_be_removed(): void
    {
        $environment = new EnvFile($this->root);
        $this->assertNull($environment->snapshot());
        $environment->writeAtomic(['APP_NAME' => 'before']);
        $snapshot = $environment->snapshot();
        $environment->writeAtomic(['APP_NAME' => 'after']);
        $environment->restore($snapshot);
        $this->assertSame(['APP_NAME' => 'before'], $environment->read());
        $environment->restore(null);
        $this->assertFileDoesNotExist($this->root.'/.env');
    }

    public function test_environment_symbolic_link_is_rejected_without_modifying_target(): void
    {
        $outside = $this->root.'/outside';
        file_put_contents($outside, 'must remain');
        symlink($outside, $this->root.'/.env');
        try {
            (new EnvFile($this->root))->writeAtomic(['APP_NAME' => 'bad']);
            $this->fail('A symlink was followed.');
        } catch (RuntimeException) {
            $this->assertSame('must remain', file_get_contents($outside));
        }
    }

    public function test_fresh_state_issues_an_idempotent_private_owner_token_and_verifies_it(): void
    {
        $state = new InstallationState($this->root);
        $this->assertSame('fresh', $state->inspect()['status']);
        $token = $state->ensureOwnerToken();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame($token, $state->ensureOwnerToken());
        $this->assertTrue($state->verifyToken($token));
        $this->assertFalse($state->verifyToken(str_repeat('0', 64)));
        $this->assertSame(0600, fileperms($state->path('owner-token')) & 0777);
        $this->assertStringNotContainsString($token, json_encode($state->inspect()));
    }

    public function test_existing_application_key_prevents_reinstallation_even_without_the_new_installed_lock(): void
    {
        (new EnvFile($this->root))->writeAtomic(['APP_KEY' => 'base64:existing-secret', 'DB_PASSWORD' => 'private-password']);
        $state = new InstallationState($this->root);
        $result = $state->inspect();
        $this->assertSame('existing', $result['status']);
        $this->assertFalse($result['can_install']);
        $this->assertStringNotContainsString('existing-secret', json_encode($result));
        $this->assertStringNotContainsString('private-password', json_encode($result));
        $this->expectException(RuntimeException::class);
        $state->ensureOwnerToken();
    }

    public function test_private_installation_state_rejects_each_symlinked_ancestor_before_writing(): void
    {
        mkdir($this->root.'/public', 0700);
        foreach (['storage', 'storage/app', 'storage/app/installation'] as $relative) {
            $path = $this->root.'/'.$relative;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            symlink($this->root.'/public', $path);
            try {
                $state = new InstallationState($this->root);
                try {
                    $state->ensureInternalToken();
                    $this->fail('Installation token followed a symlinked ancestor: '.$relative);
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('符号链接', $exception->getMessage());
                }
                $this->assertSame('blocked', $state->inspect()['status']);
                $this->assertSame(['.', '..'], scandir($this->root.'/public'));
            } finally {
                unlink($path);
            }
        }
    }

    public function test_installation_session_rejects_public_ancestor_before_creating_private_files(): void
    {
        mkdir($this->root.'/storage', 0700);
        mkdir($this->root.'/public', 0700);
        symlink($this->root.'/public', $this->root.'/storage/app');
        $rejected = false;
        try {
            (new \ReflectionMethod(InstallationWeb::class, 'startSession'))->invoke(new InstallationWeb($this->root));
        } catch (RuntimeException $exception) {
            $rejected = str_contains($exception->getMessage(), '符号链接');
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
        $this->assertSame(['.', '..'], scandir($this->root.'/public'));
        $this->assertTrue($rejected, 'Installation sessions must reject a symlinked ancestor before session_start.');
    }

    public function test_configuration_cache_or_interrupted_journal_is_fail_closed(): void
    {
        mkdir($this->root.'/bootstrap/cache', 0700, true);
        file_put_contents($this->root.'/bootstrap/cache/config.php', '<?php return ["private"=>"secret"];');
        $state = new InstallationState($this->root);
        $this->assertSame('existing', $state->inspect()['status']);
        $state->ensureDirectory();
        file_put_contents($state->path('journal.json'), '{invalid journal');
        $this->assertSame('partial', $state->inspect()['status']);
        $this->assertFalse($state->inspect()['can_install']);
        $this->assertNull($state->readJournal());
        file_put_contents($state->path('installed.json'), '{}');
        $this->assertSame('installed', $state->inspect()['status']);
    }

    public function test_malformed_environment_is_blocked_without_secret_in_error(): void
    {
        file_put_contents($this->root.'/.env', 'DB_PASSWORD="secret-without-closing-quote');
        $result = (new InstallationState($this->root))->inspect();
        $this->assertSame('blocked', $result['status']);
        $this->assertStringNotContainsString('secret-without', json_encode($result));
    }

    public function test_incomplete_existing_database_configuration_is_not_assumed_fresh(): void
    {
        (new EnvFile($this->root))->writeAtomic(['APP_KEY' => '', 'DB_DATABASE' => 'existing-database']);
        $result = (new InstallationState($this->root))->inspect();
        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_install']);
    }

    public function test_installation_file_lock_prevents_overlapping_requests(): void
    {
        $state = new InstallationState($this->root);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('另一个安装请求');
        $state->underLock(fn () => $state->underLock(fn () => true));
    }

    public function test_site_validation_prepares_only_a_hash_and_accepts_https_or_loopback_http(): void
    {
        $installer = new Installer($this->root);
        $input = $this->input();
        $prepared = $installer->validate($input);
        $this->assertTrue(password_verify($input['admin_password'], $prepared['admin_hash']));
        $this->assertArrayNotHasKey('admin_password', $prepared);
        $this->assertArrayNotHasKey('admin_password_confirmation', $prepared);
        $this->assertSame('https://images.example.com', $prepared['app_url']);
        $this->assertTrue($prepared['secure_cookie']);
        foreach (['http://localhost:8000', 'http://127.0.0.1:8000', 'http://127.1.2.3:8000', 'http://[::1]:8000'] as $url) {
            $result = $installer->validate([...$input, 'app_url' => $url]);
            $this->assertFalse($result['secure_cookie']);
        }
    }

    public function test_non_loopback_http_urls_credentials_and_subpaths_are_rejected(): void
    {
        $installer = new Installer($this->root);
        foreach (['http://images.example.com', 'http://not-localhost.test', 'http://localhost.example.com', 'https://user:password@example.com', 'https://example.com/path', 'https://example.com?query=1', 'https://example.com#fragment'] as $url) {
            try {
                $installer->validate([...$this->input(), 'app_url' => $url]);
                $this->fail('An unsafe site URL was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringNotContainsString('user:password', $exception->getMessage());
            }
        }
    }

    public function test_invalid_database_fields_and_system_schemas_are_rejected_without_echoing_passwords(): void
    {
        foreach ([['db_host' => '127.0.0.1;dbname=other'], ['db_database' => 'db;other'], ['db_database' => 'mysql'], ['db_port' => 70000], ['db_socket' => '/tmp/mysql.sock'], ['db_url' => 'mysql://private'], ['db_password' => "private\nINJECT=1"]] as $bad) {
            try {
                Installer::normalizeDatabase([...$this->input(), ...$bad]);
                $this->fail('Unsafe database data was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringNotContainsString('private', $exception->getMessage());
            }
        }
    }

    public function test_mysql_version_gate_rejects_mariadb_and_mysql_seven_and_accepts_newer_mysql(): void
    {
        foreach (['5.7.44' => false, '10.11.6-MariaDB' => false, '8.0.35-MariaDB' => false, '8.0.35' => true, '8.4.1-commercial' => true, '9.0.0' => true] as $version => $expected) {
            $pdo = new class($version) extends PDO
            {
                public function __construct(private string $version) {}

                public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
                {
                    return new class($this->version) extends PDOStatement
                    {
                        public function __construct(private string $version) {}

                        public function fetchColumn(int $column = 0): mixed
                        {
                            return $this->version;
                        }
                    };
                }
            };
            $this->assertSame($expected, Installer::isSupportedVersion($pdo));
        }
    }

    public function test_password_length_and_confirmation_fail_without_recording_plaintext(): void
    {
        $installer = new Installer($this->root);
        foreach ([['admin_password' => 'short'], ['admin_password' => str_repeat('long-secret', 8)], ['admin_password_confirmation' => 'mismatch-private-secret']] as $bad) {
            try {
                $installer->validate([...$this->input(), ...$bad]);
                $this->fail('An invalid password was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringNotContainsString('private-secret', $exception->getMessage());
            }
        }
    }

    public function test_server_token_command_works_without_vendor_and_refuses_overwrite_busy_or_existing_site(): void
    {
        foreach (['bin/install-token.php', 'app/Services/Installation/EnvFile.php', 'app/Services/Installation/InstallationState.php'] as $relative) {
            $target = $this->root.'/'.$relative;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            copy(dirname(__DIR__, 2).'/'.$relative, $target);
        }
        $run = function (): array {
            $pipes = [];
            $process = proc_open([PHP_BINARY, $this->root.'/bin/install-token.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $output];
        };
        [$code, $output] = $run();
        $this->assertSame(0, $code);
        $state = new InstallationState($this->root);
        $token = trim(file_get_contents($state->path('owner-token')));
        $this->assertTrue($state->verifyToken($token));
        $this->assertStringNotContainsString($token, $output);
        $this->assertSame(0600, fileperms($state->path('owner-token')) & 0777);
        [$code] = $run();
        $this->assertSame(1, $code);
        $this->assertSame($token, trim(file_get_contents($state->path('owner-token'))));
        $handle = fopen($state->path('install.lock'), 'c');
        flock($handle, LOCK_EX);
        try {
            [$code] = $run();
            $this->assertSame(1, $code);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        unlink($state->path('owner-token'));
        file_put_contents($state->path('installed.json'), '{}');
        [$code] = $run();
        $this->assertSame(1, $code);
        $this->assertFileDoesNotExist($state->path('owner-token'));
    }

    public function test_fresh_web_initialization_generates_internal_token_without_command_or_environment(): void
    {
        $state = new InstallationState($this->root);
        $this->assertFileDoesNotExist($state->path('owner-token'));
        $token = $state->ensureInternalToken();
        $this->assertTrue($state->verifyToken($token));
        $this->assertSame($token, $state->ensureInternalToken());
        $this->assertFileDoesNotExist($this->root.'/.env');
        $this->assertSame('fresh', $state->inspect()['status']);
        file_put_contents($state->path('installed.json'), '{}');
        $this->expectException(RuntimeException::class);
        $state->ensureInternalToken();
    }

    private function input(): array
    {
        return [
            'app_name' => '图仓', 'app_url' => 'https://images.example.com',
            'db_host' => '127.0.0.1', 'db_port' => 3306, 'db_database' => 'image_vault_new', 'db_username' => 'image_vault', 'db_password' => 'private-password',
            'admin_email' => 'owner@example.invalid', 'admin_password' => 'Private-password-123!', 'admin_password_confirmation' => 'Private-password-123!',
        ];
    }
}
