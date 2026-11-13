<?php

namespace Tests\Unit;

use App\Services\Geetest;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

class GeetestVerificationTest extends TestCase
{
    private mixed $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('encrypter', new Encrypter(str_repeat('t', 32), 'AES-256-CBC'));
        $container->instance('cache', new Repository(new ArrayStore));
        $container->instance(Factory::class, new Factory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);
        parent::tearDown();
    }

    private function configuration(): array
    {
        return ['enabled' => true, 'captcha_id' => str_repeat('a', 32), 'encrypted_key' => Crypt::encryptString(str_repeat('b', 32)), 'revision' => 'fixture'];
    }

    private function challenge(): array
    {
        return ['lot_number' => 'fixture-lot', 'captcha_output' => 'fixture-output', 'pass_token' => 'fixture-token', 'gen_time' => '1793318400'];
    }

    public function test_signed_form_request_accepts_success_once_and_never_sends_raw_key(): void
    {
        Http::fake(['gcaptcha4.geetest.com/*' => Http::response(['result' => 'success', 'captcha_args' => ['lot_number' => 'fixture-lot']])]);
        $geetest = new Geetest;
        $configuration = $this->configuration();
        $this->assertTrue($geetest->verify($configuration, $this->challenge()));
        $this->assertFalse($geetest->verify($configuration, $this->challenge()));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gcaptcha4.geetest.com/validate?captcha_id='.str_repeat('a', 32)
            && $request['sign_token'] === hash_hmac('sha256', 'fixture-lot', str_repeat('b', 32))
            && ! str_contains($request->body(), str_repeat('b', 32)));
    }

    public function test_failures_malformed_responses_and_http_errors_fail_closed(): void
    {
        Http::fakeSequence()->push(['result' => 'fail'])->push(['result' => 'success'])
            ->push(['result' => 'success', 'captcha_args' => ['lot_number' => 'different']])
            ->push('invalid-json')->push(['result' => 'success', 'captcha_args' => ['lot_number' => 'fixture-lot']], 503);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertFalse((new Geetest)->verify($this->configuration(), $this->challenge()));
        }
    }

    public function test_network_failure_fails_closed(): void
    {
        Http::fake(fn () => throw new ConnectionException('Fixture timeout'));
        $this->assertFalse((new Geetest)->verify($this->configuration(), $this->challenge()));
    }

    public function test_proof_is_bound_to_configuration_administrator_session_and_expiry(): void
    {
        $request = Request::create('/settings/geetest/test', 'POST');
        $session = new Store('geetest-unit', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => new GenericUser(['id' => 7]));
        $geetest = new Geetest;
        $configuration = $this->configuration();
        $geetest->rememberProof($request, $configuration);
        $this->assertTrue($geetest->hasProof($request, $configuration));
        foreach (['captcha_id' => str_repeat('c', 32), 'encrypted_key' => Crypt::encryptString(str_repeat('d', 32)), 'revision' => 'new-revision'] as $field => $value) {
            $this->assertFalse($geetest->hasProof($request, array_replace($configuration, [$field => $value])));
        }
        $request->setUserResolver(fn () => new GenericUser(['id' => 8]));
        $this->assertFalse($geetest->hasProof($request, $configuration));
        $request->setUserResolver(fn () => new GenericUser(['id' => 7]));
        $session->migrate();
        $this->assertFalse($geetest->hasProof($request, $configuration));
        $geetest->rememberProof($request, $configuration);
        $proof = $session->get(Geetest::PROOF_KEY);
        $proof['expires'] = now()->subSecond()->timestamp;
        $session->put(Geetest::PROOF_KEY, $proof);
        $this->assertFalse($geetest->hasProof($request, $configuration));
    }

    public function test_missing_inputs_and_unreadable_secret_make_no_http_request(): void
    {
        Http::fake();
        $geetest = new Geetest;
        $this->assertFalse($geetest->verify($this->configuration(), []));
        $this->assertFalse($geetest->verify($this->configuration(), array_replace($this->challenge(), ['pass_token' => []])));
        $configuration = $this->configuration();
        $configuration['encrypted_key'] = 'unreadable';
        $this->assertFalse($geetest->verify($configuration, $this->challenge()));
        Http::assertNothingSent();
    }
}
