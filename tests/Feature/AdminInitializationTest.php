<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Installation\InstallationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminInitializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_no_default_login_and_initialization_accepts_only_one_admin(): void
    {
        $this->seed();
        $this->assertDatabaseCount('users', 0);
        $this->artisan('vault:admin', ['email' => 'owner@example.invalid'])
            ->expectsQuestion('管理员密码（至少12位）', 'Non-default-fixture-password!')
            ->expectsQuestion('再次输入密码', 'Non-default-fixture-password!')->assertSuccessful();
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue(Hash::check('Non-default-fixture-password!', User::first()->password));
        $this->artisan('vault:admin', ['email' => 'second@example.invalid'])
            ->expectsQuestion('管理员密码（至少12位）', 'Another-fixture-password!')
            ->expectsQuestion('再次输入密码', 'Another-fixture-password!')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_initialization_rejects_short_or_unconfirmed_password(): void
    {
        $this->artisan('vault:admin', ['email' => 'owner@example.invalid'])->expectsQuestion('管理员密码（至少12位）', 'short')->expectsQuestion('再次输入密码', 'different')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_seal_requires_complete_migrations_and_one_administrator(): void
    {
        $state = app(InstallationState::class);
        $this->artisan('vault:seal-installation')->assertFailed();
        User::factory()->create();
        $before = User::first()->getRawOriginal();
        $this->artisan('vault:seal-installation')->assertSuccessful();
        $this->assertFileExists($state->path('installed.json'));
        $this->assertSame($before, User::first()->getRawOriginal());
        unlink($state->path('installed.json'));
        DB::table('migrations')->where('migration', DB::table('migrations')->max('migration'))->delete();
        $this->artisan('vault:seal-installation')->assertFailed();
        $this->assertFileDoesNotExist($state->path('installed.json'));
    }

    public function test_seal_rejects_malformed_application_key(): void
    {
        User::factory()->create();
        config(['app.key' => 'base64:malformed']);
        $this->artisan('vault:seal-installation')->assertFailed();
        $this->assertFileDoesNotExist(app(InstallationState::class)->path('installed.json'));
    }

    public function test_ip_limit_cannot_be_bypassed_by_changing_email(): void
    {
        for ($index = 0; $index < 20; $index++) {
            $this->postJson('/login', ['email' => 'other'.$index.'@example.invalid', 'password' => 'incorrect'])->assertUnprocessable();
        }
        $response = $this->postJson('/login', ['email' => 'changed@example.invalid', 'password' => 'incorrect'])->assertUnprocessable();
        $this->assertStringContainsString('登录尝试过多', $response->json('errors.email.0'));
    }
}
