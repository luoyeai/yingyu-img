<?php

use App\Services\Installation\EnvFile;
use App\Services\Installation\InstallationState;
use App\Services\Installation\InstallationWeb;

require_once __DIR__.'/../app/Services/EnvironmentDiagnostics.php';
foreach (['EnvFile', 'InstallationState', 'Installer', 'InstallationWeb'] as $installationClass) {
    $installationFile = __DIR__.'/../app/Services/Installation/'.$installationClass.'.php';
    if (! is_file($installationFile)) {
        http_response_code(503);
        vault_render_preflight(dirname(__DIR__), '安装模块正在准备，请稍后重试。');
        exit;
    }
    require_once $installationFile;
}
$installationState = new InstallationState(dirname(__DIR__));
$installationInspection = $installationState->inspect();
$installationPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($installationPath === '/install' || $installationPath === '/install/') {
    (new InstallationWeb(dirname(__DIR__)))->handle($installationInspection);
    exit;
}
if (in_array($installationInspection['status'], ['fresh', 'partial', 'blocked'], true)) {
    header('Location: /install', true, 302);
    exit;
}
try {
    $existingEnvironment = (new EnvFile(dirname(__DIR__)))->read();
} catch (Throwable) {
    http_response_code(503);
    vault_render_preflight(dirname(__DIR__), '已有安装的配置文件无法安全读取。请从受保护备份恢复配置；安装器不会覆盖现有站点。');
    exit;
}
if (empty($existingEnvironment['APP_KEY']) || empty($existingEnvironment['DB_DATABASE'])) {
    http_response_code(503);
    vault_render_preflight(dirname(__DIR__), '检测到已有配置或数据库，但应用密钥/数据库配置不完整。为保护现有数据，安装器不会覆盖；请从受保护备份恢复配置，或查看安装失败恢复说明。');
    exit;
}
