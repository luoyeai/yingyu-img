<?php

/* This entry remains parseable before Composer or Laravel can run. */
ini_set('display_errors', '0');

/** Keep an active update screen available before Composer and database sessions. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') === '/updates') {
    $updateScreenRoot = dirname(__DIR__);
    $updateScreenJournal = $updateScreenRoot.'/storage/app/updates/journal.json';
    if (is_file($updateScreenJournal) && ! is_link($updateScreenJournal) && filesize($updateScreenJournal) <= 4 * 1024 * 1024) {
        $updateScreenState = json_decode((string) file_get_contents($updateScreenJournal), true);
        if (is_array($updateScreenState) && (($updateScreenState['active'] ?? false) === true
            || in_array($updateScreenState['status'] ?? '', ['running', 'failed', 'restoring'], true))) {
            require __DIR__.'/update-gateway.php';
            exit;
        }
    }
}

function vault_asset_version(string $root, string $relativePath): string
{
    $path = $root.'/public/'.$relativePath;
    $hash = is_file($path) && is_readable($path) ? hash_file('sha256', $path) : false;

    return is_string($hash) ? substr($hash, 0, 16) : 'unavailable';
}

function vault_preflight_checks($root)
{
    $checks = [];
    $checks[] = ['label' => 'PHP 8.4.1 或更高', 'ok' => version_compare(PHP_VERSION, '8.4.1', '>='), 'fix' => '宝塔选择 PHP 8.4；网站与命令行使用相同版本。'];
    $checks[] = ['label' => 'Composer 依赖', 'ok' => is_file($root.'/vendor/autoload.php'), 'fix' => '重新上传并完整解压已包含 vendor 的生产安装包，无需在服务器安装 Composer。'];
    foreach (['ctype', 'curl', 'dom', 'fileinfo', 'mbstring', 'openssl', 'PDO', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'filter'] as $extension) {
        $checks[] = ['label' => 'PHP 扩展 '.$extension, 'ok' => extension_loaded($extension), 'fix' => '宝塔 PHP 8.4 → 安装扩展/配置，启用对应扩展并重载该版本 PHP-FPM；CLI 也需启用。'];
    }

    return $checks;
}
function vault_render_preflight($root, $message = '')
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; base-uri 'none'; frame-ancestors 'none'");
    $escape = function ($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $checks = vault_preflight_checks($root);
    $passed = array_filter($checks, function ($check) {
        return $check['ok'];
    });
    $problems = array_filter($checks, function ($check) {
        return ! $check['ok'];
    });
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>映屿Img · 启动预检</title>
    <link rel="stylesheet" href="/assets/installer.css?v=<?= $escape(vault_asset_version($root, 'assets/installer.css')) ?>">
</head>
<body>
<div class="installer-shell">
    <main class="installer-panel preflight-panel">
        <header class="installer-header">
            <a class="brand" href="/install" aria-label="映屿Img安装向导"><span class="brand-mark"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 18 6-9 4 5 3-4 5 8H3Z"/><path d="m9 9 2-3 3 5"/></svg></span><span class="brand-copy"><strong>映屿Img</strong><span>启动预检</span></span></a>
            <span class="header-label">基础运行检查</span>
        </header>
        <div class="step-content">
            <header class="page-heading"><h1>启动预检</h1><p>先确认 PHP 与基础扩展可用。完整 WebP 和目录权限检测将在安装向导中完成。</p></header>
            <?php if ($message !== '') { ?><div class="notice notice-error" role="alert"><?= $escape($message) ?></div><?php } ?>
            <div class="environment-overview preflight-overview">
                <div class="overview-card overview-primary"><span class="overview-label">基础检查通过</span><div class="overview-value"><strong><?= count($passed) ?></strong><span>/ <?= count($checks) ?></span></div></div>
                <div class="overview-card"><span class="overview-label">PHP 版本</span><strong class="overview-stat"><?= $escape(PHP_VERSION) ?></strong><span class="overview-caption">要求 8.4.1 或更高</span></div>
                <div class="overview-card"><span class="overview-label">待修复项目</span><strong class="overview-stat"><?= count($problems) ?> <span>项</span></strong><span class="overview-caption">按下方提示处理</span></div>
            </div>
            <div class="check-summary preflight-summary <?= $problems === [] ? 'summary-ready' : 'summary-waiting' ?>"><strong><?= $problems === [] ? '基础运行条件已满足' : '请先修复基础运行条件' ?></strong><span>完整 WebP 与目录操作检测需使用安装向导或管理员环境检测。</span></div>
            <?php foreach (['problems' => $problems, 'passed' => $passed] as $group => $groupChecks) { ?>
                <?php if ($groupChecks === []) {
                    continue;
                } ?>
                <?php if ($group === 'passed') { ?><details class="passed-checks"><summary>已通过 <?= count($groupChecks) ?> 项 · 展开查看详情</summary><?php } else { ?><section class="problem-checks" aria-labelledby="preflight-problems"><h2 id="preflight-problems">需要处理的项目</h2><?php } ?>
                <ul class="environment-checks">
                    <?php foreach ($groupChecks as $check) { ?>
                        <li class="environment-check <?= $check['ok'] ? 'check-pass' : 'check-fail' ?>"><div class="check-heading"><h2><?= $escape($check['label']) ?></h2><span class="check-status"><?= $check['ok'] ? '通过' : '需修复' ?></span></div><?php if (! $check['ok']) { ?><p class="check-fix"><?= $escape($check['fix']) ?></p><?php } ?></li>
                    <?php } ?>
                </ul>
                <?php if ($group === 'passed') { ?></details><?php } else { ?></section><?php } ?>
            <?php } ?>
            <p class="field-hint completion-note">目录权限应交给站点运行用户，按目录用途配置最小权限；不要使用 chmod 777。请检查站点运行目录为 public。</p>
            <div class="form-actions"><a class="button primary" href="/install"><?= $problems === [] ? '进入安装向导' : '重新检查环境' ?></a><a class="button" href="/login">管理员登录</a></div>
        </div>
        <footer class="installer-footer"><span>映屿Img · 单人图片工作室</span><span>本地存储 · WebP</span></footer>
    </main>
</div>
</body>
</html>
<?php
}
