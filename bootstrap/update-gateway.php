<?php

use App\Services\Updates\UpdateWeb;

/** Immutable 1.x recovery gateway, reached only through public/index.php. */
$recoveryRoot = dirname(__DIR__);
$recoveryBufferLevel = ob_get_level();
ob_start();
$recoveryDirectory = $recoveryRoot.'/storage/app/updates/runtime';
try {
    foreach (['storage', 'storage/app', 'storage/app/updates', 'storage/app/updates/runtime'] as $recoveryPart) {
        if (is_link($recoveryRoot.'/'.$recoveryPart) || ! is_dir($recoveryRoot.'/'.$recoveryPart)) {
            throw new RuntimeException('Recovery runtime unavailable.');
        }
    }
    foreach (['UpdateState', 'UpdatePackage', 'DatabaseSnapshot', 'UpdateRunner', 'UpdateWeb'] as $recoveryClass) {
        $recoveryFile = $recoveryDirectory.'/'.$recoveryClass.'.php';
        if (! is_file($recoveryFile) || is_link($recoveryFile)) {
            throw new RuntimeException('Recovery runtime incomplete.');
        }
        require_once $recoveryFile;
    }
    (new UpdateWeb($recoveryRoot))->handle();
    ob_end_flush();
} catch (Throwable $recoveryFailure) {
    while (ob_get_level() > $recoveryBufferLevel) {
        ob_end_clean();
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>恢复入口暂不可用</title><h1>恢复入口暂不可用</h1><p>受保护恢复模块未就绪或当前请求未能完成。请保留 storage/app/updates 的日志与备份；若后台可用，请登录后打开系统更新页。内部配置未显示。</p></html>';
}
