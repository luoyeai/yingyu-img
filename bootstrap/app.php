<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UpdateWriteGuard;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(UpdateWriteGuard::class);
        $middleware->encryptCookies(except: ['vault_update_recovery']);
        $middleware->web(append: [SecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
            if (! $request->is('upload') || ! $request->expectsJson()) {
                return null;
            }
            [$status, $code, $message, $retryable, $scope] = match (true) {
                $exception instanceof ValidationException => [422, 'validation_failed', '文件或上传参数不符合要求。', false, 'file'],
                $exception instanceof AuthenticationException => [401, 'authentication_required', '登录已失效，请重新登录后继续。', false, 'queue'],
                $exception instanceof TokenMismatchException || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 419) => [419, 'session_expired', '会话已过期，请导出记录后刷新并重新登录。', false, 'queue'],
                $exception instanceof PostTooLargeException => [413, 'request_too_large', '文件超出服务器请求上限。', false, 'file'],
                $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 429 => [429, 'rate_limited', '请求过于频繁，稍后重试。', true, 'queue'],
                default => [500, 'upload_error', '服务器处理结果未确认，请检查运行状态后重试。', false, 'queue'],
            };
            $body = compact('code', 'message', 'retryable', 'scope');
            if ($exception instanceof ValidationException) {
                $body['errors'] = $exception->errors();
            }
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

            return response()->json($body, $status, $headers);
        });
        $exceptions->dontFlash(['access_key', 'secret_key']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
