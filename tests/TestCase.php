<?php

namespace Tests;

use App\Services\Installation\InstallationState;
use App\Services\Updates\UpdateRunner;
use App\Services\Updates\UpdateState;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    private ?string $installationTestRoot = null;

    /** Validate before RefreshDatabase traits can run migrations or open a connection. */
    public function createApplication(): Application
    {
        $application = parent::createApplication();
        $database = $application['config']->get('database.connections.mysql');
        if ($application->environment() !== 'testing'
            || $application['config']->get('database.default') !== 'mysql'
            || ($database['database'] ?? null) !== 'image_vault_test'
            || ($database['host'] ?? null) !== '127.0.0.1'
            || (int) ($database['port'] ?? 0) !== 13368
            || ! empty($database['url'])
            || ! empty($database['unix_socket'])) {
            throw new LogicException('Tests refused before database access: only testing / mysql / 127.0.0.1:13368 / image_vault_test is authorized.');
        }

        $this->installationTestRoot = sys_get_temp_dir().'/vault-cli-state-test-'.bin2hex(random_bytes(8));
        mkdir($this->installationTestRoot, 0700, true);
        $root = $this->installationTestRoot;
        $application->bind(InstallationState::class, fn () => new InstallationState($root));
        $application->bind(UpdateState::class, fn () => new UpdateState($root));
        $application->bind(UpdateRunner::class, fn () => new UpdateRunner($root, $database));

        return $application;
    }

    protected function tearDown(): void
    {
        if ($this->installationTestRoot && is_dir($this->installationTestRoot)) {
            (new Filesystem)->deleteDirectory($this->installationTestRoot);
        }
        parent::tearDown();
    }
}
