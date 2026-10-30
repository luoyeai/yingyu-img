<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use Tests\TestCase;

class VaultAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'image_vault_test') {
            throw new LogicException('Access tests must use the isolated MySQL image_vault_test database.');
        }
    }

    private function administrator(): User
    {
        return User::factory()->create(['email' => 'access-test@example.invalid', 'password' => Hash::make('Fixture-password-81!')]);
    }

    private function image(array $attributes = []): Image
    {
        $key = bin2hex(random_bytes(16));

        return Image::create(array_merge([
            'original_name' => 'private-original.png', 'display_name' => '图片', 'mime' => 'image/png',
            'path' => '2099/01/'.$key.'.webp', 'preview_path' => '2099/01/'.$key.'.webp',
            'original_path' => '2099/01/'.$key.'.png', 'source_width' => 32, 'source_height' => 24,
            'width' => 32, 'height' => 24, 'source_bytes' => 100, 'bytes' => 80, 'original_bytes' => 100,
            'source_hash' => hash('sha256', $key), 'fingerprint' => hash('sha256', 'options'.$key),
            'processing' => ['quality' => 80, 'lossless' => false, 'max_edge' => 0],
        ], $attributes));
    }

    public function test_all_administration_and_private_file_routes_require_login(): void
    {
        $image = $this->image();
        foreach (['/', '/library', '/trash', '/settings', '/images/'.$image->id.'/preview', '/images/'.$image->id.'/original'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/upload')->assertRedirect('/login');
        $this->post('/groups', ['name' => '不得创建'])->assertRedirect('/login');
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_registration_and_password_reset_are_not_publicly_available(): void
    {
        foreach (['/register', '/forgot-password', '/reset-password/example'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->post('/register', ['email' => 'stranger@example.invalid', 'password' => 'Forbidden-password-92!'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_five_bad_logins_lock_even_the_correct_password_temporarily(): void
    {
        $this->administrator();
        $key = 'login:'.hash('sha256', 'access-test@example.invalid|127.0.0.1');
        RateLimiter::clear($key);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', ['email' => 'access-test@example.invalid', 'password' => 'wrong-password'])->assertUnprocessable();
        }
        $response = $this->postJson('/login', ['email' => 'access-test@example.invalid', 'password' => 'Fixture-password-81!']);
        $response->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertStringContainsString('登录尝试过多', $response->json('errors.email.0'));
        $this->assertGuest();
        $this->travel(301)->seconds();
        $this->post('/login', ['email' => 'access-test@example.invalid', 'password' => 'Fixture-password-81!'])->assertRedirect('/');
        $this->assertAuthenticated();
        RateLimiter::clear($key);
    }

    public function test_successful_login_resets_previous_failure_counter(): void
    {
        $user = $this->administrator();
        $key = 'login:'.hash('sha256', 'access-test@example.invalid|127.0.0.1');
        RateLimiter::clear($key);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnprocessable();
        $this->post('/login', ['email' => $user->email, 'password' => 'Fixture-password-81!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, RateLimiter::attempts($key));
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_password_change_requires_current_password_and_a_confirmed_long_password(): void
    {
        $user = $this->administrator();
        $this->actingAs($user);
        $this->putJson('/password', ['current_password' => 'incorrect', 'password' => 'Replacement-password-93!', 'password_confirmation' => 'Replacement-password-93!'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/password', ['current_password' => 'Fixture-password-81!', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/password', ['current_password' => 'Fixture-password-81!', 'password' => 'Replacement-password-93!', 'password_confirmation' => 'Mismatch-password-93!'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('Fixture-password-81!', $user->fresh()->password));
        $this->put('/password', ['current_password' => 'Fixture-password-81!', 'password' => 'Replacement-password-93!', 'password_confirmation' => 'Replacement-password-93!'])->assertRedirect();
        $this->assertTrue(Hash::check('Replacement-password-93!', $user->fresh()->password));
        $this->assertFalse(Hash::check('Fixture-password-81!', $user->fresh()->password));
        $this->post('/logout');
        $this->postJson('/login', ['email' => $user->email, 'password' => 'Fixture-password-81!'])->assertUnprocessable();
        $this->post('/login', ['email' => $user->email, 'password' => 'Replacement-password-93!'])->assertRedirect('/');
    }

    public function test_deleting_group_moves_active_and_trashed_images_to_ungrouped_without_changing_paths(): void
    {
        $this->actingAs($this->administrator());
        $group = Group::create(['name' => '删除分组测试']);
        $active = $this->image(['group_id' => $group->id]);
        $trashed = $this->image(['group_id' => $group->id]);
        $trashed->delete();
        $activePath = $active->path;
        $trashPath = $trashed->path;
        $this->delete('/groups/'.$group->id)->assertRedirect();
        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
        $this->assertNull($active->fresh()->group_id);
        $this->assertNull(Image::withTrashed()->findOrFail($trashed->id)->group_id);
        $this->assertSame($activePath, $active->fresh()->path);
        $this->assertSame($trashPath, Image::withTrashed()->findOrFail($trashed->id)->path);
    }

    public function test_csrf_protection_rejects_missing_token_and_accepts_valid_session_token(): void
    {
        $this->actingAs($this->administrator());
        // Laravel bypasses CSRF in the testing environment; temporarily use production
        // environment detection to exercise the real middleware without altering DB config.
        $previousEnvironment = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->post('/groups', ['name' => '无令牌'])->assertStatus(419);
            $this->assertDatabaseMissing('groups', ['name' => '无令牌']);
            $token = bin2hex(random_bytes(20));
            $this->withSession(['_token' => $token])->post('/groups', ['name' => '有效令牌', '_token' => $token])->assertRedirect();
            $this->assertDatabaseHas('groups', ['name' => '有效令牌']);
        } finally {
            $this->app['env'] = $previousEnvironment;
        }
    }
}
