<?php

require_once dirname(__DIR__, 2).'/bootstrap/preflight.php';

/** @var array<string, mixed> $view */
$installerView = is_array($view ?? null) ? $view : [];
$escape = static fn (mixed $value): string => htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$step = (string) ($installerView['step'] ?? 'blocked');
$allowedSteps = ['environment', 'database', 'site', 'confirm', 'complete', 'blocked', 'failed'];
if (! in_array($step, $allowedSteps, true)) {
    $step = 'blocked';
}
$values = is_array($installerView['values'] ?? null) ? $installerView['values'] : [];
$value = static fn (string $name, string $default = ''): string => $escape($values[$name] ?? $default);
$csrf = $escape($installerView['csrf'] ?? '');
$checks = is_array($installerView['checks'] ?? null) ? $installerView['checks'] : [];
$ready = ($installerView['ready'] ?? false) === true;
$canRetry = ($installerView['can_retry'] ?? false) === true;
$errors = [];
foreach ((array) ($installerView['errors'] ?? []) as $fieldErrors) {
    foreach (is_array($fieldErrors) ? $fieldErrors : [$fieldErrors] as $error) {
        if (is_scalar($error)) {
            $errors[] = (string) $error;
        }
    }
}
$steps = ['environment' => '环境检查', 'database' => '数据库', 'site' => '站点与管理员', 'confirm' => '确认', 'complete' => '完成'];
$shortSteps = ['environment' => '环境', 'database' => '数据库', 'site' => '管理员', 'confirm' => '确认', 'complete' => '完成'];
$currentIndex = array_search($step, array_keys($steps), true);
$titles = ['environment' => '检查运行环境', 'database' => '连接独立数据库', 'site' => '设置站点与管理员', 'confirm' => '确认安装信息', 'complete' => '映屿Img安装完成', 'blocked' => '安装入口已受保护', 'failed' => '安装未完成'];
$descriptions = ['environment' => '检查 PHP、图片处理能力与目录权限，确保安装后能够正常上传。', 'database' => '使用专用 MySQL 8.0 空数据库，避免与其他应用共用数据。', 'site' => '设置图片链接使用的站点地址，并创建唯一管理员。', 'confirm' => '核对以下信息，确认后创建应用数据表与管理员。', 'complete' => '安装状态已由服务器确认，可以登录并开始上传图片。', 'blocked' => '当前状态不允许继续安装，请按下方说明检查服务器。', 'failed' => '服务器未确认安装成功。请修复提示的问题后再继续。'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $escape($titles[$step]) ?> · 映屿Img</title>
    <link rel="stylesheet" href="/assets/installer.css?v=<?= $escape(vault_asset_version(dirname(__DIR__, 2), 'assets/installer.css')) ?>">
    <script src="/assets/installer.js?v=<?= $escape(vault_asset_version(dirname(__DIR__, 2), 'assets/installer.js')) ?>" defer></script>
</head>
<body>
<div class="installer-shell">
    <main class="installer-panel step-<?= $escape($step) ?>" data-server-state="<?= $escape($installerView['state'] ?? '') ?>">
    <header class="installer-header">
        <a class="brand" href="/install" aria-label="映屿Img安装向导">
            <span class="brand-mark"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 18 6-9 4 5 3-4 5 8H3Z"/><path d="m9 9 2-3 3 5"/></svg></span>
            <span class="brand-copy"><strong>映屿Img</strong><span>安装向导</span></span>
        </a>
        <?php if (! in_array($step, ['blocked', 'failed'], true)) { ?>
            <nav aria-label="安装步骤" class="installation-progress">
                <ol>
                    <?php foreach ($steps as $stepKey => $stepLabel) { ?>
                        <?php $stepIndex = array_search($stepKey, array_keys($steps), true); ?>
                        <li class="<?= $stepKey === $step ? 'is-current' : ($currentIndex !== false && $stepIndex < $currentIndex ? 'is-complete' : '') ?>"<?= $stepKey === $step ? ' aria-current="step"' : '' ?>>
                            <span class="step-number" aria-hidden="true"><?php if ($currentIndex !== false && $stepIndex < $currentIndex) { ?><svg viewBox="0 0 16 16"><path d="m4 8 3 3 5-6"/></svg><?php } else { ?><?= $stepIndex + 1 ?><?php } ?></span><span class="step-label"><?= $escape($stepLabel) ?></span><span class="step-label-short" aria-hidden="true"><?= $escape($shortSteps[$stepKey]) ?></span>
                        </li>
                    <?php } ?>
                </ol>
            </nav>
        <?php } else { ?><span class="header-label">安装状态</span><?php } ?>
    </header>
        <div class="step-content">
            <header class="page-heading">
                <?php if ($step === 'complete') { ?><span class="result-symbol result-success" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m6 12 4 4 8-8"/></svg></span><?php } ?>
                <?php if (in_array($step, ['blocked', 'failed'], true)) { ?><span class="result-symbol result-warning" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 8v5m0 4h.01"/><circle cx="12" cy="12" r="9"/></svg></span><?php } ?>
                <h1><?= $escape($titles[$step]) ?></h1>
                <p><?= $escape($descriptions[$step]) ?></p>
            </header>
            <?php if (! empty($installerView['message'])) { ?>
                <div class="notice <?= in_array($step, ['failed', 'blocked'], true) ? 'notice-error' : 'notice-info' ?>" role="<?= in_array($step, ['failed', 'blocked'], true) ? 'alert' : 'status' ?>"><?= $escape($installerView['message']) ?></div>
            <?php } ?>
            <?php if ($errors !== []) { ?>
                <div class="notice notice-error" role="alert"><strong>请检查以下问题</strong><ul><?php foreach ($errors as $error) { ?><li><?= $escape($error) ?></li><?php } ?></ul></div>
            <?php } ?>
            <?php if ($step === 'environment') { ?>
                <?php
                $requiredChecks = array_filter($checks, static fn (array $check): bool => (bool) ($check['required'] ?? true));
                $requiredPassed = count(array_filter($requiredChecks, static fn (array $check): bool => (bool) ($check['ok'] ?? false)));
                $adviceCount = count(array_filter($checks, static fn (array $check): bool => ! ($check['required'] ?? true) && ! ($check['ok'] ?? false)));
                $checksById = array_column($checks, null, 'id');
                $phpDetail = (string) ($checksById['php-version']['detail'] ?? '待检测');
                $phpVersion = explode(' / ', $phpDetail, 2)[0];
                $webpCheck = $checksById['imagick-webp-roundtrip'] ?? null;
                $checkGroups = [
                    'problems' => array_merge(
                        array_filter($checks, static fn (array $check): bool => ! ($check['ok'] ?? false) && ($check['required'] ?? true)),
                        array_filter($checks, static fn (array $check): bool => ! ($check['ok'] ?? false) && ! ($check['required'] ?? true)),
                    ),
                    'passed' => array_filter($checks, static fn (array $check): bool => (bool) ($check['ok'] ?? false)),
                ];
                ?>
                <div class="environment-overview" aria-label="环境检测概览">
                    <div class="overview-card overview-primary"><span class="overview-label">必要条件通过</span><div class="overview-value"><strong><?= $requiredPassed ?></strong><span>/ <?= count($requiredChecks) ?></span></div></div>
                    <div class="overview-card"><span class="overview-label">PHP 版本</span><strong class="overview-stat"><?= $escape($phpVersion) ?></strong><span class="overview-caption">要求 8.4.1 或更高</span></div>
                    <div class="overview-card"><span class="overview-label">WebP 实际编解码</span><strong class="overview-stat"><?= $webpCheck === null ? '待检测' : (! empty($webpCheck['ok']) ? '检测通过' : '需要修复') ?></strong><span class="overview-caption">透明图片读写验证</span></div>
                    <div class="overview-card"><span class="overview-label">建议待处理</span><strong class="overview-stat"><?= $adviceCount ?> <span>项</span></strong><span class="overview-caption">不影响继续安装</span></div>
                </div>
                <div class="environment-decision">
                <div class="check-summary <?= $ready ? 'summary-ready' : 'summary-waiting' ?>"><strong><?= $ready ? '必要条件已满足' : '请先解决必要条件中的问题' ?></strong><span><?= $ready ? '可继续配置数据库，检测详情可在下方查看。' : '修复下方必要问题后重新检查。' ?></span></div>
                <?php if ($csrf !== '') { ?>
                <form method="post" action="/install" class="environment-actions" data-loading-message="正在检查运行环境…"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="<?= $ready ? 'database' : 'environment' ?>"><div class="form-actions"><button class="button <?= $ready ? 'primary' : '' ?>" type="submit"><?= $ready ? '继续配置数据库' : '重新检查环境' ?></button><?php if (! $ready) { ?><button type="button" class="button primary" disabled>继续配置数据库</button><?php } ?></div></form>
                <?php } else { ?><div class="form-actions environment-actions"><a class="button" href="/install">重新检查环境</a><button class="button primary" disabled>继续配置数据库</button></div><?php } ?>
                </div>
                <?php foreach ($checkGroups as $group => $groupChecks) { ?>
                    <?php if ($groupChecks === []) {
                        continue;
                    } ?>
                    <?php if ($group === 'passed') { ?><details class="passed-checks"><summary>已通过 <?= count($groupChecks) ?> 项 · 展开查看详情</summary><?php } else { ?><section class="problem-checks" aria-labelledby="problem-checks-heading"><h2 id="problem-checks-heading">需要处理的项目</h2><?php } ?>
                <ul class="environment-checks" aria-label="环境检查结果">
                    <?php foreach ($groupChecks as $check) { ?>
                        <?php
                        if (! is_array($check)) {
                            continue;
                        }
                        $required = ($check['required'] ?? true) === true;
                        $checkPassed = array_key_exists('ok', $check) ? (bool) $check['ok'] : (($check['status'] ?? '') === 'pass');
                        $checkStatus = $checkPassed ? 'pass' : ($required ? 'fail' : 'warning');
                        $checkStatusLabel = $checkPassed ? '通过' : ($required ? '需修复' : '提醒');
                        ?>
                        <li class="environment-check check-<?= $checkStatus ?>">
                            <div class="check-heading"><h2><?= $escape($check['label'] ?? '环境检查项') ?></h2><span class="check-status"><?= $checkStatusLabel ?></span></div>
                            <?php if (! empty($check['detail'])) { ?><p><?= $escape($check['detail']) ?></p><?php } ?>
                            <?php if (! $checkPassed && ! empty($check['fix'])) { ?><p class="check-fix"><strong>处理方法：</strong><?= $escape($check['fix']) ?></p><?php } ?>
                            <span class="requirement-label"><?= $required ? '必要条件' : '建议检查' ?></span>
                        </li>
                    <?php } ?>
                </ul>
                    <?php if ($group === 'passed') { ?></details><?php } else { ?></section><?php } ?>
                <?php } ?>
                <?php if ($checks === []) { ?><div class="notice notice-info">尚未收到环境检查结果，请重新检查。</div><?php } ?>
            <?php } elseif ($step === 'database') { ?>
                <div class="notice notice-info">请先创建一个独立的空数据库，并为它配置专用账号。安装程序会检查库内是否已有数据；已有表的数据库不能用于此次安装。</div>
                <form method="post" action="/install" class="installation-form" data-loading-message="正在连接并检查数据库…">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="database">
                    <div class="field-grid"><div class="field"><label for="db-host">数据库主机</label><input id="db-host" name="db_host" required value="<?= $value('db_host', '127.0.0.1') ?>" autocomplete="off" maxlength="255"><p class="field-hint">本机数据库通常使用 127.0.0.1。</p></div><div class="field"><label for="db-port">端口</label><input id="db-port" name="db_port" type="number" required min="1" max="65535" value="<?= $value('db_port', '3306') ?>" inputmode="numeric"></div></div>
                    <div class="field"><label for="db-database">独立空数据库名称</label><input id="db-database" name="db_database" required value="<?= $value('db_database') ?>" autocomplete="off" spellcheck="false" maxlength="64"></div>
                    <div class="field"><label for="db-username">数据库账号</label><input id="db-username" name="db_username" required value="<?= $value('db_username') ?>" autocomplete="off" spellcheck="false" maxlength="128"></div>
                    <div class="field"><label for="db-password">数据库密码</label><input id="db-password" name="db_password" type="password" autocomplete="new-password"><p class="field-hint">连接信息仅提交给当前服务器。再次进入此步骤时，请重新填写密码。</p></div>
                    <div class="form-actions"><button class="button primary" type="submit">连接并检查数据库</button></div>
                </form>
            <?php } elseif ($step === 'site') { ?>
                <form method="post" action="/install" class="installation-form" data-loading-message="正在验证站点与管理员信息…">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="site">
                    <div class="field"><label for="site-name">站点名称</label><input id="site-name" name="site_name" required value="<?= $value('site_name', '映屿Img') ?>" maxlength="100" autocomplete="organization"></div>
                    <div class="field"><label for="site-url">站点地址</label><input id="site-url" name="site_url" type="url" required value="<?= $value('site_url') ?>" placeholder="https://images.example.com" spellcheck="false" autocomplete="url" maxlength="255"><p class="field-hint">填写网站根地址，公开图片链接会使用此地址。生产环境使用 HTTPS，本地可使用 HTTP。</p></div>
                    <div class="form-divider"><h2>创建唯一管理员</h2><p>不开放注册，安装时不会创建公开默认密码。</p></div>
                    <div class="field"><label for="admin-email">管理员邮箱</label><input id="admin-email" name="email" type="email" required value="<?= $value('email') ?>" autocomplete="username" maxlength="255"></div>
                    <div class="field-grid"><div class="field"><label for="admin-password">管理员密码</label><input id="admin-password" name="admin_password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" aria-describedby="password-hint"></div><div class="field"><label for="admin-password-confirmation">确认密码</label><input id="admin-password-confirmation" name="admin_password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password"></div></div>
                    <p class="field-hint" id="password-hint">至少 12 个字符，最多 72 字节。密码不会出现在下一步的确认页面中。</p>
                    <div class="form-actions"><button class="button primary" type="submit">继续确认安装</button></div>
                </form>
            <?php } elseif ($step === 'confirm') { ?>
                <section aria-labelledby="confirmation-heading"><h2 id="confirmation-heading" class="section-title">即将使用的配置</h2><dl class="configuration-summary">
                    <?php foreach (['site_name' => '站点名称', 'site_url' => '站点地址', 'email' => '管理员邮箱', 'db_host' => '数据库主机', 'db_port' => '数据库端口', 'db_database' => '独立数据库', 'db_username' => '数据库账号'] as $name => $label) { ?><div><dt><?= $escape($label) ?></dt><dd><?= $value($name, '未提供') ?></dd></div><?php } ?>
                    <div><dt>密码</dt><dd class="muted">已在服务器验证，不在页面中显示</dd></div>
                </dl></section>
                <div class="notice notice-info"><strong>安装范围</strong><p>在已验证的独立空数据库中创建映屿Img数据表和唯一管理员，并写入本站运行配置。不会覆盖其他应用或已有数据库表。</p></div>
                <form method="post" action="/install" class="installation-form" data-loading-message="正在安装，请保持页面打开…">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="install">
                    <label class="confirmation-checkbox"><input type="checkbox" name="confirm_empty_database" value="1" required><span>我确认以上数据库专供本站使用且为空，允许创建映屿Img数据表与管理员。</span></label>
                    <div class="form-actions"><button class="button primary" type="submit">确认并安装</button><span class="action-hint">提交后请等待服务器确认结果。</span></div>
                </form>
            <?php } elseif ($step === 'complete') { ?>
                <dl class="configuration-summary compact-summary"><div><dt>站点名称</dt><dd><?= $value('site_name', '映屿Img') ?></dd></div><div><dt>管理员邮箱</dt><dd><?= $value('email', '请使用安装时填写的邮箱') ?></dd></div></dl>
                <p class="completion-note">请使用刚设置的管理员账号登录。安装入口已由服务器关闭；后续配置可在“设置与状态”中检查。</p>
                <div class="form-actions"><a class="button primary" href="/login">前往管理员登录</a></div>
            <?php } elseif ($step === 'failed') { ?>
                <div class="recovery-guide"><h2>处理后再继续</h2><p>检查上方错误和服务器日志。请勿手动重复创建管理员、覆盖配置或清空数据库。</p><p class="field-hint">本页不会把失败结果显示为安装成功。是否允许重试由服务器当前状态决定。</p></div>
                <?php if ($canRetry) { ?><form method="post" action="/install" data-loading-message="正在重新检查安装条件…"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="environment"><div class="form-actions"><button class="button" type="submit">重新检查环境</button><span class="action-hint">检查通过后按向导重新核对配置。</span></div></form><?php } else { ?><p class="blocked-note">当前不允许自动重试。请由服务器管理员检查安装锁与失败原因。</p><?php } ?>
            <?php } else { ?>
                <div class="recovery-guide"><h2>请检查服务器安装状态</h2><p>已完成安装、已有应用数据或不满足安装权限时，安装入口会保持关闭。本页不会自动解除保护。</p><p class="field-hint">若这是已有站点，请直接使用管理员登录；若首次安装被中断，请先核查配置、数据库和安装日志。</p></div>
                <div class="form-actions"><a class="button" href="/login">前往管理员登录</a></div>
            <?php } ?>
            <p id="submission-status" class="submission-status" role="status" aria-live="polite" hidden></p>
            <noscript><p class="no-script-note">JavaScript 未启用。所有安装步骤仍可通过表单提交完成，请勿重复点击提交。</p></noscript>
        </div>
    <footer class="installer-footer"><span>映屿Img · 单人图片工作室</span><span>本地存储 · WebP</span></footer>
    </main>
</div>
</body>
</html>
