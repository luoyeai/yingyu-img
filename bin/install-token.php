<?php

use App\Services\Installation\InstallationState;

/** Run on the server; never make this file a public endpoint. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (version_compare(PHP_VERSION, '8.4.1', '<')) {
    fwrite(STDERR, "请使用 PHP 8.4.1 或更高版本。\n");
    exit(1);
}
$root = dirname(__DIR__);
if (is_file($root.'/vendor/autoload.php')) {
    require_once $root.'/vendor/autoload.php';
}
require $root.'/app/Services/Installation/EnvFile.php';
require $root.'/app/Services/Installation/InstallationState.php';
$state = new InstallationState($root);
$inspection = $state->inspect();
if (($inspection['status'] ?? '') !== 'fresh') {
    fwrite(STDERR, "当前目录不是可初始化的新站点，拒绝生成安装密钥。请检查既有安装或未完成状态。\n");
    exit(1);
}
$directory = $root.'/storage/app/installation';
if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
    fwrite(STDERR, "无法创建私有安装目录；请修复站点用户权限。\n");
    exit(1);
}
$handle = fopen($directory.'/install.lock', 'c');
if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "安装正在进行，拒绝修改安装密钥。\n");
    exit(1);
}
try {
    if (! $state->inspect()['can_install']) {
        throw new RuntimeException('安装状态已变化，拒绝生成令牌。');
    }
    if (is_file($directory.'/owner-token')) {
        fwrite(STDERR, "安装密钥文件已存在，请从服务器私有文件读取；不自动覆盖或公开输出。\n");
        exit(1);
    }
    $token = bin2hex(random_bytes(32));
    $temporary = $directory.'/.token-'.bin2hex(random_bytes(8));
    if (file_put_contents($temporary, $token."\n", LOCK_EX) === false || ! chmod($temporary, 0600) || ! rename($temporary, $directory.'/owner-token')) {
        if (is_file($temporary)) {
            unlink($temporary);
        }
        throw new RuntimeException('写入安装密钥失败。');
    }
    echo "安装密钥已生成：storage/app/installation/owner-token（0600）。\n在服务器读取此私有文件，粘贴到 /install 授权页面。不要将密钥放入URL或公开分享。\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "安装密钥生成失败，请检查站点目录权限。\n");
    exit(1);
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}
