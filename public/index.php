<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') === '/update-recovery') {
    require __DIR__.'/../bootstrap/update-gateway.php';
    exit;
}

require_once __DIR__.'/../bootstrap/preflight.php';
$startupRoot = dirname(__DIR__);
$startupPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$startupReady = true;
foreach (vault_preflight_checks($startupRoot) as $startupCheck) {
    $startupReady = $startupReady && $startupCheck['ok'];
}
if ($startupPath === '/preflight' || ! $startupReady) {
    if (! $startupReady) {
        http_response_code(503);
    }
    vault_render_preflight($startupRoot);
    exit;
}
try {
    set_error_handler(static function ($severity, $message) {
        throw new ErrorException('Composer bootstrap failed', 0, $severity);
    });
    require_once $startupRoot.'/vendor/autoload.php';
} catch (Throwable $startupFailure) {
    restore_error_handler();
    http_response_code(503);
    vault_render_preflight($startupRoot, '生产依赖无法启动，请确认宝塔使用 PHP 8.4.1+ 并重新解压包含 vendor 的完整安装包。未显示内部错误或配置。');
    exit;
}
restore_error_handler();
require $startupRoot.'/bootstrap/installation.php';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require_once __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
