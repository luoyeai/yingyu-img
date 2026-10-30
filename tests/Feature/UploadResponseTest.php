<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ImageStorage;
use App\Services\Updates\UpdateState;
use App\Services\UploadFailure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UploadResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_and_expired_csrf_have_queue_scoped_codes(): void
    {
        $this->postJson('/upload')->assertStatus(401)->assertJsonPath('code', 'authentication_required')->assertJsonPath('scope', 'queue');
        $this->actingAs(User::factory()->create());
        $environment = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->postJson('/upload')->assertStatus(419)->assertJsonPath('code', 'session_expired')->assertJsonPath('retryable', false);
        } finally {
            $this->app['env'] = $environment;
        }
    }

    public function test_invalid_parameters_are_file_scoped_and_maintenance_pauses_without_retry(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/upload', ['preset' => 'standard'])->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('scope', 'file')->assertJsonStructure(['errors' => ['file']]);
        $this->app->make(UpdateState::class)->write('journal.json', ['active' => true]);
        $this->postJson('/upload')->assertStatus(503)->assertHeader('Retry-After', '30')->assertJsonPath('code', 'maintenance')->assertJsonPath('retryable', false);
    }

    public function test_storage_busy_pending_and_unknown_failures_remain_distinct_and_sanitized(): void
    {
        $this->actingAs(User::factory()->create());
        Log::spy();
        foreach ([
            [new UploadFailure('存储忙。', 'storage_busy', true, 'file'), 'storage_busy', true, 'file'],
            [new UploadFailure('清理待完成。', 'storage_pending'), 'storage_pending', false, 'queue'],
            [new UploadFailure('配置不可用。', 'storage_configuration'), 'storage_configuration', false, 'queue'],
            [new \RuntimeException('fixture-private-error'), 'storage_error', false, 'queue'],
        ] as [$exception, $code, $retryable, $scope]) {
            $storage = Mockery::mock(ImageStorage::class);
            $storage->shouldReceive('upload')->once()->andThrow($exception);
            $this->app->instance(ImageStorage::class, $storage);
            $this->postJson('/upload', ['file' => UploadedFile::fake()->create('x.jpg', 1), 'preset' => 'standard'])
                ->assertStatus(503)->assertJsonPath('code', $code)->assertJsonPath('retryable', $retryable)->assertJsonPath('scope', $scope)->assertDontSee('fixture-private-error');
        }
    }

    public function test_exception_response_preserves_rate_limit_headers_and_server_size_status(): void
    {
        $request = Request::create('/upload', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $handler = $this->app->make(ExceptionHandler::class);
        $limited = $handler->render($request, new HttpException(429, 'private-internal-message', headers: ['Retry-After' => '7']));
        $this->assertSame(429, $limited->getStatusCode());
        $this->assertSame('7', $limited->headers->get('Retry-After'));
        $this->assertSame('rate_limited', $limited->getData(true)['code']);
        $this->assertStringNotContainsString('private-internal-message', $limited->getContent());
        $large = $handler->render($request, new PostTooLargeException);
        $this->assertSame(413, $large->getStatusCode());
        $this->assertSame('request_too_large', $large->getData(true)['code']);
    }
}
