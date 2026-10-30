<?php

namespace Tests\Feature;

use App\Http\Middleware\UpdateWriteGuard;
use App\Models\User;
use App\Services\ImageStorage;
use App\Services\Updates\UpdateRunner;
use App\Services\Updates\UpdateState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class UpdateAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $updateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->updateRoot = sys_get_temp_dir().'/vault-update-ui-'.bin2hex(random_bytes(10));
        mkdir($this->updateRoot, 0700);
        $this->app->instance(UpdateState::class, new UpdateState($this->updateRoot));
    }

    protected function tearDown(): void
    {
        if (isset($this->updateRoot)) {
            File::deleteDirectory($this->updateRoot);
        }
        parent::tearDown();
    }

    public function test_update_routes_require_existing_administrator_authentication(): void
    {
        $this->get('/updates')->assertRedirect('/login');
        foreach (['/updates/upload', '/updates/confirm', '/updates/recovery'] as $path) {
            $this->post($path)->assertRedirect('/login');
        }
        $this->assertFileDoesNotExist($this->updateRoot.'/storage/app/updates/recovery-grant.json');
    }

    public function test_update_confirmation_requires_csrf_before_grant_or_runner_access(): void
    {
        $this->actingAs(User::factory()->create());
        $environment = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->post('/updates/confirm', ['task' => str_repeat('a', 32), 'confirmation' => '1'])->assertStatus(419);
            $this->assertFileDoesNotExist($this->updateRoot.'/storage/app/updates/recovery-grant.json');
        } finally {
            $this->app['env'] = $environment;
        }
    }

    public function test_successful_inspection_grants_only_hashed_recovery_authorization(): void
    {
        $this->actingAs(User::factory()->create());
        $runner = Mockery::mock(UpdateRunner::class);
        $runner->shouldReceive('prepare')->once()->withArgs(fn (string $path): bool => is_file($path))->andReturn(['id' => str_repeat('a', 32), 'stage' => 'prepared']);
        $this->app->instance(UpdateRunner::class, $runner);
        (new UpdateState($this->updateRoot))->write('journal.json', ['id' => str_repeat('a', 32), 'status' => 'prepared']);
        $response = $this->post('/updates/upload', ['package' => UploadedFile::fake()->create('signed.zip', 2, 'application/zip')]);
        $response->assertRedirect('/updates');
        $grant = (new UpdateState($this->updateRoot))->read('recovery-grant.json');
        $this->assertSame(str_repeat('a', 32), $grant['task']);
        $this->assertFalse($grant['confirmed']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $grant['hash']);
        $cookies = $response->headers->getCookies();
        $cookie = array_values(array_filter($cookies, fn ($value): bool => $value->getName() === 'vault_update_recovery' && $value->getPath() === '/'))[0];
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
        $this->assertCount(2, array_filter($cookies, fn ($value): bool => $value->getName() === 'vault_update_recovery'));
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame($grant['hash'], hash('sha256', $cookie->getValue()));
        $this->assertStringNotContainsString($cookie->getValue(), file_get_contents($this->updateRoot.'/storage/app/updates/recovery-grant.json'));
    }

    public function test_confirmation_is_bound_to_task_and_does_not_start_work_before_cookie_arrives(): void
    {
        $this->actingAs(User::factory()->create());
        $task = str_repeat('b', 32);
        $runner = Mockery::mock(UpdateRunner::class);
        $runner->shouldReceive('status')->once()->andReturn(['id' => $task, 'stage' => 'prepared']);
        $runner->shouldNotReceive('advance');
        $this->app->instance(UpdateRunner::class, $runner);
        (new UpdateState($this->updateRoot))->write('journal.json', ['id' => $task, 'status' => 'prepared']);
        $this->post('/updates/confirm', ['task' => $task, 'confirmation' => '1'])->assertRedirect('/updates');
        $this->assertTrue((new UpdateState($this->updateRoot))->read('recovery-grant.json')['confirmed']);
    }

    public function test_forged_confirmation_cannot_grant_a_different_task(): void
    {
        $this->actingAs(User::factory()->create());
        $runner = Mockery::mock(UpdateRunner::class);
        $runner->shouldReceive('status')->once()->andReturn(['id' => str_repeat('b', 32)]);
        $this->app->instance(UpdateRunner::class, $runner);
        $this->post('/updates/confirm', ['task' => str_repeat('a', 32), 'confirmation' => '1'])->assertSessionHasErrors('update');
        $this->assertFileDoesNotExist($this->updateRoot.'/storage/app/updates/recovery-grant.json');
    }

    public function test_write_guard_drains_shared_requests_and_blocks_active_update(): void
    {
        $state = new UpdateState($this->updateRoot);
        $guard = new UpdateWriteGuard($state);
        $state->write('journal.json', ['active' => true, 'status' => 'running']);
        $response = $guard->handle(Request::create('/upload', 'POST'), fn () => response('unsafe'));
        $this->assertSame(503, $response->getStatusCode());
        $state->write('journal.json', ['active' => false, 'status' => 'prepared']);
        $response = $guard->handle(Request::create('/library'), function () use ($state) {
            $exclusive = fopen($state->directory().'/write.lock', 'c');
            $this->assertFalse(flock($exclusive, LOCK_EX | LOCK_NB));
            fclose($exclusive);

            return response('protected');
        });
        $this->assertSame('protected', $response->getContent());
    }

    public function test_maintenance_retries_update_cleanup_without_failing_successful_image_cleanup(): void
    {
        $runner = Mockery::mock(UpdateRunner::class);
        $runner->shouldReceive('cleanupCompleted')->once()->andReturn(['completed' => 0, 'pending' => 1, 'skipped' => false]);
        $this->app->instance(UpdateRunner::class, $runner);
        $this->artisan('vault:cleanup')->expectsOutputToContain('不影响已完成更新')->assertSuccessful();
    }

    public function test_secured_standalone_status_does_not_reapply_directory_permissions(): void
    {
        [$secret] = $this->recoveryFixture();
        $response = $this->gatewayRequest(['vault_update_recovery' => $secret], [], 'GET', '/update-recovery', true, ['-d', 'disable_functions=chmod']);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('"status":"prepared"', $response['body']);
    }

    public function test_failed_task_cannot_run_maintenance_even_if_active_flag_is_false(): void
    {
        (new UpdateState($this->updateRoot))->write('journal.json', ['status' => 'failed', 'stage' => 'migration-running', 'active' => false]);
        $runner = Mockery::mock(UpdateRunner::class);
        $runner->shouldReceive('cleanupCompleted')->once()->andReturn(['completed' => 0, 'pending' => 0, 'skipped' => true]);
        $this->app->instance(UpdateRunner::class, $runner);
        $storage = Mockery::mock(ImageStorage::class);
        $storage->shouldNotReceive('synchronized');
        $this->app->instance(ImageStorage::class, $storage);
        $this->artisan('vault:cleanup')->expectsOutputToContain('已跳过本次清理')->assertSuccessful();
    }

    public function test_update_only_maintenance_does_not_touch_images_or_remote_pending_operations(): void
    {
        $runner = Mockery::mock(UpdateRunner::class);
        $runner->shouldReceive('cleanupCompleted')->twice()->andReturn(
            ['completed' => 1, 'pending' => 0, 'skipped' => false],
            ['completed' => 0, 'pending' => 1, 'skipped' => false],
        );
        $this->app->instance(UpdateRunner::class, $runner);
        $storage = Mockery::mock(ImageStorage::class);
        $storage->shouldNotReceive('synchronized');
        $this->app->instance(ImageStorage::class, $storage);
        $this->artisan('vault:cleanup', ['--updates-only' => true])->expectsOutputToContain('未处理回收站或云待办')->assertSuccessful();
        $this->artisan('vault:cleanup', ['--updates-only' => true])->assertFailed();
    }

    public function test_standalone_gateway_requires_private_cookie_and_survives_broken_vendor(): void
    {
        [$secret, $task] = $this->recoveryFixture();
        $denied = $this->gatewayRequest();
        $this->assertSame(403, $denied['status'], $denied['body']);
        $this->assertStringContainsString('恢复入口未授权', $denied['body']);
        $accepted = $this->gatewayRequest(['vault_update_recovery' => $secret]);
        $this->assertSame(200, $accepted['status']);
        $this->assertStringContainsString('系统更新', $accepted['body']);
        $this->assertStringNotContainsString(substr($task, 0, 8), $accepted['body']);
        $this->assertStringContainsString('<details class="update-details">', $accepted['body']);
        $this->assertStringNotContainsString($secret, $accepted['body']);
        $this->assertStringNotContainsString('BROKEN_VENDOR_EXECUTED', $accepted['body']);
    }

    public function test_standalone_recovery_rejects_expired_grant_and_missing_csrf(): void
    {
        [$secret, $task] = $this->recoveryFixture();
        $response = $this->gatewayRequest(['vault_update_recovery' => $secret], ['action' => 'restore', 'confirmation' => 'restore'], 'POST');
        $this->assertSame(419, $response['status']);
        $state = new UpdateState($this->updateRoot);
        $this->assertSame('prepared', $state->read('journal.json')['stage']);
        $state->write('recovery-grant.json', ['hash' => hash('sha256', $secret), 'expires' => time() - 1, 'task' => $task]);
        $this->assertSame(403, $this->gatewayRequest(['vault_update_recovery' => $secret])['status']);
    }

    public function test_standalone_recovery_cannot_start_without_administrator_confirmation(): void
    {
        [$secret, $task] = $this->recoveryFixture();
        $response = $this->gatewayRequest(['vault_update_recovery' => $secret], ['action' => 'advance', 'stage' => 'prepared', 'csrf' => hash_hmac('sha256', 'vault-update:'.$task, $secret)], 'POST');
        $this->assertSame(409, $response['status']);
        $this->assertSame('prepared', (new UpdateState($this->updateRoot))->read('journal.json')['stage']);
    }

    public function test_standalone_login_recovers_expired_cookie_only_after_password_and_csrf(): void
    {
        [$secret, $task] = $this->recoveryFixture();
        $state = new UpdateState($this->updateRoot);
        $state->write('recovery-account.json', ['task' => $task, 'email' => 'recovery@example.invalid', 'password_hash' => password_hash('Fixture-recovery-82!', PASSWORD_BCRYPT)]);
        $state->write('recovery-grant.json', ['hash' => hash('sha256', $secret), 'expires' => time() - 1, 'task' => $task]);
        $journal = $state->read('journal.json');
        $journal['status'] = 'failed';
        $journal['active'] = true;
        $state->write('journal.json', $journal);
        $this->assertStringContainsString('恢复登录', $this->gatewayRequest()['body']);
        $challenge = bin2hex(random_bytes(32));
        $csrf = hash_hmac('sha256', 'vault-recovery-login:'.$task, $challenge);
        $cookies = ['vault_recovery_challenge' => $challenge, 'vault_update_recovery' => $secret];
        $input = ['action' => 'login', 'email' => 'recovery@example.invalid', 'password' => 'Fixture-recovery-82!'];
        $this->assertSame(419, $this->gatewayRequest($cookies, $input, 'POST')['status']);
        $this->assertSame(403, $this->gatewayRequest($cookies, [...$input, 'csrf' => $csrf, 'password' => 'wrong'], 'POST')['status']);
        $this->assertLessThan(time(), $state->read('recovery-grant.json')['expires']);
        $this->assertSame(303, $this->gatewayRequest($cookies, [...$input, 'csrf' => $csrf], 'POST')['status']);
        $grant = $state->read('recovery-grant.json');
        $this->assertGreaterThan(time(), $grant['expires']);
        $this->assertTrue($grant['confirmed']);
        $this->assertNotSame(hash('sha256', $secret), $grant['hash']);
        $this->assertStringNotContainsString('Fixture-recovery-82!', file_get_contents($state->directory().'/recovery-account.json'));
    }

    public function test_standalone_login_limits_failed_passwords_and_rejects_finished_tasks(): void
    {
        [, $task] = $this->recoveryFixture();
        $state = new UpdateState($this->updateRoot);
        $state->write('recovery-account.json', ['task' => $task, 'email' => 'recovery@example.invalid', 'password_hash' => password_hash('Fixture-recovery-82!', PASSWORD_BCRYPT)]);
        $challenge = bin2hex(random_bytes(32));
        $cookies = ['vault_recovery_challenge' => $challenge];
        $input = ['action' => 'login', 'csrf' => hash_hmac('sha256', 'vault-recovery-login:'.$task, $challenge), 'email' => 'recovery@example.invalid', 'password' => 'wrong'];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertSame(403, $this->gatewayRequest($cookies, $input, 'POST')['status']);
        }
        $this->assertSame(429, $this->gatewayRequest($cookies, [...$input, 'password' => 'Fixture-recovery-82!'], 'POST')['status']);
        $journal = $state->read('journal.json');
        $journal['status'] = 'complete';
        $state->write('journal.json', $journal);
        $this->assertSame(403, $this->gatewayRequest($cookies, [...$input, 'password' => 'Fixture-recovery-82!'], 'POST')['status']);
    }

    public function test_update_page_shows_only_version_notes_and_confirmation_by_default(): void
    {
        $this->actingAs(User::factory()->create());
        $runner = Mockery::mock(UpdateRunner::class);
        $runner->shouldReceive('current')->once()->andReturn(['program' => '1.0.1', 'schema' => []]);
        $runner->shouldReceive('status')->once()->andReturn(['id' => str_repeat('a', 32), 'from' => '1.0.1', 'to' => '1.0.2', 'stage' => 'prepared', 'status' => 'prepared', 'package' => ['release_notes' => '简化更新操作。'], 'log' => []]);
        $this->app->instance(UpdateRunner::class, $runner);
        $this->get('/updates')->assertOk()->assertSee('1.0.1 → 1.0.2')->assertSee('简化更新操作。')->assertSee('确认更新')
            ->assertDontSee('任务编号')->assertDontSee('所需 PHP')->assertDontSee('兼容程序范围')->assertDontSee('受保护恢复入口')->assertDontSee('阶段记录');
    }

    public function test_refresh_active_same_page_uses_authorized_frozen_runtime_before_broken_vendor(): void
    {
        [$secret] = $this->recoveryFixture();
        $state = new UpdateState($this->updateRoot);
        $journal = $state->read('journal.json');
        $state->write('journal.json', [...$journal, 'active' => true, 'status' => 'running', 'stage' => 'files']);
        $response = $this->gatewayRequest(['vault_update_recovery' => $secret], [], 'GET', '/updates');
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('data-update-screen', $response['body']);
        $this->assertStringNotContainsString('BROKEN_VENDOR_EXECUTED', $response['body']);
        $this->assertSame(403, $this->gatewayRequest([], [], 'GET', '/updates')['status']);
    }

    public function test_json_status_and_failure_restore_use_bound_cookie_and_csrf(): void
    {
        [$secret, $task] = $this->recoveryFixture();
        $state = new UpdateState($this->updateRoot);
        $journal = $state->read('journal.json');
        $state->write('journal.json', [...$journal, 'active' => true, 'status' => 'failed', 'error' => 'Fixture failure']);
        $response = $this->gatewayRequest(['vault_update_recovery' => $secret], [], 'GET', '/update-recovery', true);
        $this->assertSame(200, $response['status']);
        $data = json_decode(explode("\nGATEWAY_STATUS=", $response['body'])[0], true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $data['status']);
        $this->assertSame(hash_hmac('sha256', 'vault-update:'.$task, $secret), $data['csrf']);
        $this->assertStringNotContainsString($secret, $response['body']);
        $response = $this->gatewayRequest(['vault_update_recovery' => $secret], ['action' => 'restore', 'confirmation' => 'restore', 'csrf' => $data['csrf']], 'POST', '/update-recovery', true);
        $this->assertSame(200, $response['status']);
        $this->assertSame('cancelled', $state->read('journal.json')['status']);
        $this->assertStringContainsString('"status":"cancelled"', $response['body']);
    }

    /** @return array{string,string} */
    private function recoveryFixture(): array
    {
        $runtime = $this->updateRoot.'/storage/app/updates/runtime';
        mkdir($runtime, 0700, true);
        foreach (['UpdateState', 'UpdatePackage', 'DatabaseSnapshot', 'UpdateRequirements', 'UpdateRunner', 'UpdateWeb'] as $class) {
            copy(base_path('app/Services/Updates/'.$class.'.php'), $runtime.'/'.$class.'.php');
        }
        copy(base_path('resources/views/update-recovery.php'), $runtime.'/update-recovery.php');
        mkdir($this->updateRoot.'/public', 0755);
        mkdir($this->updateRoot.'/bootstrap', 0755);
        mkdir($this->updateRoot.'/vendor', 0755);
        file_put_contents($this->updateRoot.'/vendor/autoload.php', '<?php throw new RuntimeException("BROKEN_VENDOR_EXECUTED");');
        copy(base_path('public/index.php'), $this->updateRoot.'/public/index.php');
        copy(base_path('bootstrap/preflight.php'), $this->updateRoot.'/bootstrap/preflight.php');
        copy(base_path('bootstrap/update-gateway.php'), $this->updateRoot.'/bootstrap/update-gateway.php');
        $secret = bin2hex(random_bytes(32));
        $task = bin2hex(random_bytes(16));
        $state = new UpdateState($this->updateRoot);
        $state->write('recovery-grant.json', ['hash' => hash('sha256', $secret), 'expires' => time() + 3600, 'task' => $task, 'confirmed' => false]);
        $state->write('journal.json', ['id' => $task, 'stage' => 'prepared', 'status' => 'prepared', 'active' => false, 'from' => '0.0.0', 'to' => '1.0.0', 'log' => [], 'manifest' => ['program' => '1.0.0', 'schema' => [], 'compatible' => ['min' => '0.0.0', 'max' => '0.0.0'], 'php_min' => '8.4.1'], 'before' => ['schema' => []]]);

        return [$secret, $task];
    }

    /** @return array{status:int,body:string} */
    private function gatewayRequest(array $cookies = [], array $post = [], string $method = 'GET', string $path = '/update-recovery', bool $json = false, array $phpOptions = []): array
    {
        $code = '<?php $_COOKIE='.var_export($cookies, true).'; $_POST='.var_export($post, true).'; $_SERVER["REQUEST_URI"]='.var_export($path, true).'; $_SERVER["HTTP_X_REQUESTED_WITH"]='.var_export($json ? 'VaultUpdate' : '', true).'; $_SERVER["REQUEST_METHOD"]='.var_export($method, true).'; http_response_code(200); register_shutdown_function(function(){echo "\nGATEWAY_STATUS=".http_response_code();}); require '.var_export($this->updateRoot.'/public/index.php', true).';';
        $process = proc_open([PHP_BINARY, ...$phpOptions], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process));
        $this->assertSame('', $errors);
        $this->assertMatchesRegularExpression('/GATEWAY_STATUS=([0-9]{3})$/', $output);
        preg_match('/GATEWAY_STATUS=([0-9]{3})$/', $output, $matches);

        return ['status' => (int) $matches[1], 'body' => $output];
    }
}
