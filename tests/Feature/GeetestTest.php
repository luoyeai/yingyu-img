<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Geetest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class GeetestTest extends TestCase
{
    use RefreshDatabase;

    private function configuration(bool $enabled = false): array
    {
        $configuration = ['enabled' => $enabled, 'captcha_id' => str_repeat('a', 32), 'encrypted_key' => Crypt::encryptString(str_repeat('b', 32)), 'revision' => 'fixture'];
        Setting::put('geetest', $configuration);

        return $configuration;
    }

    private function challenge(): array
    {
        return ['lot_number' => 'fixture-lot', 'captcha_output' => 'fixture-output', 'pass_token' => 'fixture-token', 'gen_time' => '1793318400'];
    }

    private function fakeSuccess(): void
    {
        Http::preventStrayRequests();
        Http::fake(['gcaptcha4.geetest.com/*' => Http::response(['result' => 'success', 'captcha_args' => ['lot_number' => 'fixture-lot']])]);
    }

    public function test_legacy_updater_can_render_login_without_controller_variables_and_respects_captcha_configuration(): void
    {
        Http::preventStrayRequests();
        $this->app['view']->share('errors', new ViewErrorBag);
        foreach ([false, true] as $enabled) {
            $this->configuration($enabled);
            $html = $this->app['view']->make('login')->render();
            $this->assertStringContainsString('管理员登录', $html);
            if ($enabled) {
                $this->assertStringContainsString('data-geetest-login', $html);
                $this->assertStringContainsString('assets/geetest.js', $html);
            } else {
                $this->assertStringNotContainsString('data-geetest-login', $html);
                $this->assertStringNotContainsString('assets/geetest.js', $html);
            }
        }
        Http::assertNothingSent();
    }

    private function verifyCurrentConfiguration(): void
    {
        $this->withCredentials()->postJson('/settings/geetest/test', $this->challenge())
            ->assertOk()->assertSessionHas(Geetest::PROOF_KEY);
        $session = $this->app['session.store'];
        $this->assertSame(hash('sha256', $session->getId()), $session->get(Geetest::PROOF_KEY)['session']);
        $this->withCookie($session->getName(), $session->getId());
    }

    public function test_disabled_by_default_has_no_sdk_or_external_csp_and_login_needs_no_captcha(): void
    {
        Http::fake();
        $user = User::factory()->create(['password' => 'Fixture-password-81!']);
        $response = $this->get('/login')->assertOk()->assertDontSee('assets/geetest.js');
        $this->assertStringNotContainsString('geetest.com', $response->headers->get('Content-Security-Policy'));
        $this->post('/login', ['email' => $user->email, 'password' => 'Fixture-password-81!'])->assertRedirect('/');
        Http::assertNothingSent();
    }

    public function test_guest_cannot_change_or_test_configuration(): void
    {
        $this->put('/settings/geetest')->assertRedirect('/login');
        $this->post('/settings/geetest/test')->assertRedirect('/login');
        $this->put('/settings/geetest/activation', ['enabled' => true])->assertRedirect('/login');
    }

    public function test_configuration_is_encrypted_and_blank_key_retains_it_but_saving_disables(): void
    {
        $this->actingAs(User::factory()->create());
        $this->put('/settings/geetest', ['captcha_id' => str_repeat('a', 32), 'geetest_key' => str_repeat('b', 32)])->assertRedirect('/settings');
        $first = Setting::value('geetest');
        $this->assertFalse($first['enabled']);
        $this->assertSame(str_repeat('b', 32), Crypt::decryptString($first['encrypted_key']));
        $this->assertStringNotContainsString(str_repeat('b', 32), json_encode($first));
        $this->put('/settings/geetest', ['captcha_id' => str_repeat('c', 32), 'geetest_key' => ''])->assertRedirect('/settings');
        $second = Setting::value('geetest');
        $this->assertSame($first['encrypted_key'], $second['encrypted_key']);
        $this->assertNotSame($first['revision'], $second['revision']);
        $this->get('/settings')->assertOk()->assertDontSee(str_repeat('b', 32))->assertDontSee($first['encrypted_key']);
    }

    public function test_invalid_secret_is_not_flashed_in_html_or_json_requests(): void
    {
        $this->actingAs(User::factory()->create());
        $this->from('/settings')->put('/settings/geetest', ['captcha_id' => 'invalid', 'geetest_key' => 'DO-NOT-FLASH'])->assertSessionHasErrors();
        $this->assertArrayNotHasKey('geetest_key', session()->getOldInput());
        $this->putJson('/settings/geetest', ['captcha_id' => 'invalid', 'geetest_key' => 'DO-NOT-FLASH'])->assertUnprocessable();
    }

    public function test_client_boolean_cannot_enable_and_failed_test_produces_no_proof(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configuration();
        $this->putJson('/settings/geetest/activation', ['enabled' => true, 'verified' => true])->assertUnprocessable();
        Http::fake(['*' => Http::response(['result' => 'fail'])]);
        $this->postJson('/settings/geetest/test', $this->challenge())->assertUnprocessable()->assertSessionMissing(Geetest::PROOF_KEY);
        $this->assertFalse(Setting::value('geetest')['enabled']);
    }

    public function test_successful_test_enables_once_and_consumes_proof(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configuration();
        $this->fakeSuccess();
        $this->verifyCurrentConfiguration();
        $this->put('/settings/geetest/activation', ['enabled' => true])->assertRedirect('/settings')->assertSessionMissing(Geetest::PROOF_KEY);
        $this->assertTrue(Setting::value('geetest')['enabled']);
    }

    public function test_expired_proof_cannot_enable(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configuration();
        $this->fakeSuccess();
        $this->verifyCurrentConfiguration();
        $this->travel(301)->seconds();
        $this->putJson('/settings/geetest/activation', ['enabled' => true])->assertUnprocessable();
        $this->assertFalse(Setting::value('geetest')['enabled']);
    }

    public function test_changing_id_or_key_invalidates_existing_proof(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configuration();
        $this->fakeSuccess();
        $this->verifyCurrentConfiguration();
        $this->put('/settings/geetest', ['captcha_id' => str_repeat('c', 32), 'geetest_key' => str_repeat('d', 32)])->assertRedirect('/settings')->assertSessionMissing(Geetest::PROOF_KEY);
        $this->putJson('/settings/geetest/activation', ['enabled' => true])->assertUnprocessable();
    }

    public function test_another_session_cannot_use_copied_proof(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configuration();
        $this->fakeSuccess();
        $this->verifyCurrentConfiguration();
        $proof = session(Geetest::PROOF_KEY);
        $session = $this->app['session.store'];
        $session->migrate();
        $session->put(Geetest::PROOF_KEY, $proof);
        $session->save();
        $this->withCookie($session->getName(), $session->getId());
        $this->putJson('/settings/geetest/activation', ['enabled' => true])->assertUnprocessable();
        $this->assertFalse(Setting::value('geetest')['enabled']);
    }

    public function test_enabled_login_requires_server_verification_even_with_correct_password(): void
    {
        $user = User::factory()->create(['password' => 'Fixture-password-81!']);
        $this->configuration(true);
        $this->fakeSuccess();
        $this->get('/login')->assertOk()->assertSee('assets/geetest.js');
        $credentials = ['email' => $user->email, 'password' => 'Fixture-password-81!'];
        $this->postJson('/login', $credentials)->assertUnprocessable()->assertJsonValidationErrors('geetest');
        $this->assertGuest();
        $this->post('/login', array_merge($credentials, $this->challenge()))->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_network_failure_blocks_login_and_does_not_expose_exception(): void
    {
        $user = User::factory()->create(['password' => 'Fixture-password-81!']);
        $this->configuration(true);
        Http::fake(fn () => throw new ConnectionException('PRIVATE-TRANSPORT-DETAIL'));
        $this->postJson('/login', array_merge(['email' => $user->email, 'password' => 'Fixture-password-81!'], $this->challenge()))
            ->assertUnprocessable()->assertDontSee('PRIVATE-TRANSPORT-DETAIL');
        $this->assertGuest();
    }
}
