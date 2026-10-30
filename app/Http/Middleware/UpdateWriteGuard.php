<?php

namespace App\Http\Middleware;

use App\Services\Updates\UpdateState;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class UpdateWriteGuard
{
    public function __construct(private UpdateState $state) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $directory = $this->state->directory();
        $path = $directory.'/write.lock';
        if (is_link($path)) {
            throw new RuntimeException('更新写入锁路径无效。');
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            return $this->blocked($request, '无法取得本站写入锁，请检查私有目录权限。', 'write_lock_error');
        }
        try {
            clearstatcache(true, $path);
            if ((fileperms($path) & 0777) !== 0600 && ! chmod($path, 0600)) {
                return $this->blocked($request, '无法保护本站写入锁，请检查私有目录权限。', 'write_lock_error');
            }
            if (! flock($handle, LOCK_SH | LOCK_NB)) {
                return $this->blocked($request, '系统更新中，后台暂时暂停访问。已有图片直链继续由 Web 服务器提供。', 'maintenance', ['Retry-After' => '30']);
            }
            $journal = $this->state->read('journal.json');
            if (($journal['active'] ?? false) || in_array($journal['status'] ?? '', ['running', 'failed', 'restoring'], true)) {
                return $this->blocked($request, '系统更新中，请使用当前浏览器的受保护恢复入口继续。', 'maintenance', ['Retry-After' => '30']);
            }

            return $next($request);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string,string> $headers */
    private function blocked(Request $request, string $message, string $code, array $headers = []): Response
    {
        if ($request->is('upload') && $request->expectsJson()) {
            return response()->json(['message' => $message, 'code' => $code, 'retryable' => false, 'scope' => 'queue'], 503, $headers);
        }

        return response($message, 503, $headers);
    }
}
