<?php

namespace Tests;

use App\Services\Installation\InstallationState;
use PDO;
use RuntimeException;

/** Strictly scoped to this project's independent MySQL instance and newly created databases. */
trait SupportInstallerSandbox
{
    private string $sandboxRoot;

    private string $sandboxDatabase;

    private string $sandboxToken;

    private array $sandboxCredentials;

    private PDO $sandboxRootPdo;

    private function createInstallerSandbox(): void
    {
        $project = dirname(__DIR__);
        $credentials = json_decode(file_get_contents($project.'/.tools/runtime/mysql/credentials.json'), true, 32, JSON_THROW_ON_ERROR);
        if ($credentials['host'] !== '127.0.0.1' || (int) $credentials['port'] !== 13368 || $credentials['database'] !== 'image_vault') {
            throw new RuntimeException('Refused installer test before PDO: wrong isolated runtime.');
        }
        $this->sandboxDatabase = 'image_vault_installtest_'.bin2hex(random_bytes(6));
        $this->sandboxRoot = sys_get_temp_dir().'/vault-installer-test-'.bin2hex(random_bytes(10));
        mkdir($this->sandboxRoot, 0700);
        $this->sandboxRootPdo = new PDO('mysql:host=127.0.0.1;port=13368;charset=utf8mb4', 'root', $credentials['root_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (! str_starts_with((string) $this->sandboxRootPdo->query('SELECT VERSION()')->fetchColumn(), '8.0.')) {
            throw new RuntimeException('Installer test requires project MySQL8.0.');
        }
        if ($this->sandboxRootPdo->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='.$this->sandboxRootPdo->quote($this->sandboxDatabase))->fetchColumn()) {
            throw new RuntimeException('Test database exists; refusing.');
        }
        $this->sandboxRootPdo->exec('CREATE DATABASE `'.$this->sandboxDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->sandboxCredentials = ['db_host' => '127.0.0.1', 'db_port' => 13368, 'db_database' => $this->sandboxDatabase, 'db_username' => $credentials['username'], 'db_password' => $credentials['password']];
        $account = $this->sandboxRootPdo->quote($credentials['username'])."@'127.0.0.1'";
        $this->sandboxRootPdo->exec('GRANT ALL PRIVILEGES ON `'.$this->sandboxDatabase.'`.* TO '.$account);
        foreach (['app', 'resources', 'config', 'database/migrations', 'bootstrap/providers.php', 'composer.json', 'routes', 'public/assets', 'public/index.php', 'bin/router.php', 'bootstrap/app.php', 'bootstrap/preflight.php', 'bootstrap/installation.php'] as $relative) {
            $this->copySandbox($project.'/'.$relative, $this->sandboxRoot.'/'.$relative);
        }
        symlink($project.'/vendor', $this->sandboxRoot.'/vendor');
        foreach (['bootstrap/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache/data', 'storage/logs', 'public/media'] as $relative) {
            if (! is_dir($this->sandboxRoot.'/'.$relative)) {
                mkdir($this->sandboxRoot.'/'.$relative, 0700, true);
            }
        }
        $this->sandboxToken = (new InstallationState($this->sandboxRoot))->ensureOwnerToken();
    }

    private function sandboxInput(): array
    {
        return [...$this->sandboxCredentials, 'app_name' => '测试图仓 $ " # \\', 'app_url' => 'http://127.0.0.1:8791', 'admin_email' => 'owner@example.invalid', 'admin_password' => 'Fixture-install-secret-81!', 'admin_password_confirmation' => 'Fixture-install-secret-81!'];
    }

    private function removeInstallerSandbox(): void
    {
        if (isset($this->sandboxDatabase,$this->sandboxRootPdo) && preg_match('/\Aimage_vault_installtest_[a-f0-9]{12}\z/D', $this->sandboxDatabase)) {
            $this->sandboxRootPdo->exec('DROP DATABASE `'.$this->sandboxDatabase.'`');
        }
        if (isset($this->sandboxRoot) && str_starts_with($this->sandboxRoot, sys_get_temp_dir().'/vault-installer-test-')) {
            $this->removeSandboxTree($this->sandboxRoot);
        }
    }

    private function copySandbox(string $from, string $to): void
    {
        if (is_dir($from)) {
            if (! is_dir($to)) {
                mkdir($to, 0700, true);
            }foreach (scandir($from) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->copySandbox($from.'/'.$entry, $to.'/'.$entry);
                }
            }
        } elseif (is_file($from)) {
            if (! is_dir(dirname($to))) {
                mkdir(dirname($to), 0700, true);
            }copy($from, $to);
        }
    }

    private function removeSandboxTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeSandboxTree($path.'/'.$name);
            }
        }rmdir($path);
    }
}
