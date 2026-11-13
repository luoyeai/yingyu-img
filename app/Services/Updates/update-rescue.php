<?php

use App\Services\Updates\UpdateRunner;

/** Standalone owner-operated rescue utility. Never loaded by HTTP or Composer. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');
umask(0077);
set_error_handler(static function (): never {
    throw new RuntimeException('文件操作未完成，请检查权限或防篡改策略；任务与备份保留。', 42);
});

function vault_rescue_fail(string $message): never
{
    throw new RuntimeException($message, 42);
}

function vault_rescue_path(string $base, string $relative, bool $directory = false): string
{
    if (! preg_match('/\A[a-zA-Z0-9_@.\/-]+\z/D', $relative) || str_contains($relative, '..') || str_starts_with($relative, '/')) {
        vault_rescue_fail('检查路径不安全，已停止。');
    }
    $path = $base;
    foreach (explode('/', $relative) as $part) {
        $path .= '/'.$part;
        if (is_link($path)) {
            vault_rescue_fail('检查路径包含符号链接，已停止。');
        }
    }
    if ($directory ? ! is_dir($path) : ! is_file($path)) {
        vault_rescue_fail('所需任务文件或目录不完整，已停止。');
    }

    return $path;
}

/** @return array<string|int,mixed> */
function vault_rescue_json(string $path): array
{
    if (filesize($path) > 16777216) {
        vault_rescue_fail('任务清单超过检查上限，已停止。');
    }
    $data = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (! is_array($data)) {
        vault_rescue_fail('任务清单格式无效，已停止。');
    }

    return $data;
}

function vault_rescue_digest(string $path, mixed $expected, ?int $size = null): void
{
    if (! is_string($expected) || ! preg_match('/\A[a-f0-9]{64}\z/D', $expected)
        || ($size !== null && filesize($path) !== $size) || ! hash_equals($expected, hash_file('sha256', $path))) {
        vault_rescue_fail('备份或恢复引擎哈希不匹配，已停止；请保留全部备份。');
    }
}

/** @param array<string,mixed> $journal */
function vault_rescue_backups(string $directory, array $journal): string
{
    if (! isset($journal['backup'])) {
        if (! in_array($journal['stage'], ['prepared', 'backup'], true)) {
            vault_rescue_fail('此阶段缺少完整备份，已停止。');
        }

        return 'not-created';
    }
    $backup = vault_rescue_path($directory, 'tasks/'.$journal['id'].'/backup', true);
    $index = vault_rescue_path($backup, 'files.json');
    vault_rescue_digest($index, $journal['backup']['files_sha256'] ?? null);
    $files = vault_rescue_json($index);
    $expected = array_unique([...array_keys($journal['manifest']['files'] ?? []), ...($journal['before']['managed_files'] ?? [])]);
    if (array_diff($expected, array_keys($files)) !== [] || array_diff(array_keys($files), $expected) !== []) {
        vault_rescue_fail('程序备份清单不完整，已停止。');
    }
    foreach ($files as $relative => $metadata) {
        if ($metadata !== null) {
            if (! is_array($metadata) || ! is_int($metadata['size'] ?? null)) {
                vault_rescue_fail('程序备份元数据无效，已停止。');
            }
            vault_rescue_digest(vault_rescue_path($backup, 'files/'.$relative), $metadata['sha256'] ?? null, $metadata['size']);
        }
    }
    $manifest = vault_rescue_path($backup, 'database/manifest.json');
    $descriptor = $journal['backup']['database'] ?? [];
    vault_rescue_digest($manifest, $descriptor['sha256'] ?? null);
    $database = vault_rescue_json($manifest);
    if (($database['format'] ?? null) !== 1 || ! is_array($database['tables'] ?? null) || $database['tables'] === []
        || count($database['tables']) !== ($descriptor['tables'] ?? null) || ($database['database'] ?? null) !== ($descriptor['database'] ?? null)) {
        vault_rescue_fail('数据库备份清单无效，已停止。');
    }
    $bytes = 0;
    foreach ($database['tables'] as $table => $metadata) {
        if (! is_string($table) || ! preg_match('/\A[a-zA-Z0-9_]+\z/D', $table) || ! is_int($metadata['bytes'] ?? null)) {
            vault_rescue_fail('数据库备份条目无效，已停止。');
        }
        vault_rescue_digest(vault_rescue_path($backup, 'database/'.$table.'.rows'), $metadata['sha256'] ?? null, $metadata['bytes']);
        $bytes += $metadata['bytes'];
    }
    if ($bytes !== ($descriptor['bytes'] ?? null)) {
        vault_rescue_fail('数据库备份总大小不匹配，已停止。');
    }

    return 'file-hashes-verified';
}

/** @return array<string,string> */
function vault_rescue_web_replacements(): array
{
    $dispatch = <<<'PHP'
        $state = new UpdateState($this->root);
        if ((($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'login')
            || (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['reauth'] ?? '') === '1')) {
            $this->login($state);

            return;
        }
PHP;

    return [
        '        $state = new UpdateState($this->root);' => $dispatch,
        "echo '恢复请求校验失败。请返回恢复页重试。';" => "echo '<!doctype html><html lang=\"zh-CN\"><meta charset=\"utf-8\"><title>恢复请求未执行</title><h1>恢复请求未执行</h1><p>恢复请求校验失败，本次操作未执行。请勿重复提交旧表单。</p><p><a href=\"/update-recovery?inspect=1\">重新打开恢复页</a></p><p><a href=\"/update-recovery?reauth=1\">重新验证管理员身份</a></p></html>';",
        "echo '恢复登录请求校验失败，请刷新恢复页。';" => "echo '<!doctype html><html lang=\"zh-CN\"><meta charset=\"utf-8\"><title>恢复登录未执行</title><h1>恢复登录未执行</h1><p><a href=\"/update-recovery?reauth=1\">重新打开恢复登录页</a></p></html>';",
        "header('Location: /updates', true, 303);" => "header('Location: /update-recovery?inspect=1', true, 303);",
    ];
}

function vault_rescue_transform(string $body, string $originalHash, array $replacements): string
{
    if (! hash_equals($originalHash, hash('sha256', $body))) {
        $reverted = strtr($body, array_flip($replacements));
        if (! hash_equals($originalHash, hash('sha256', $reverted))) {
            vault_rescue_fail('恢复引擎不属于已审计版本，拒绝修复或执行；请使用匹配版本的应急工具。');
        }

        return $body;
    }
    foreach ($replacements as $needle => $replacement) {
        if (substr_count($body, $needle) !== 1) {
            vault_rescue_fail('恢复代码定位不唯一，拒绝修复。');
        }
    }

    return strtr($body, $replacements);
}

/** @return array<string,array{original:string,repaired:string}> */
function vault_rescue_runtime(string $directory): array
{
    $fixed = [
        'UpdateState' => '0e0d8c41ee8de9944b985fcffeacf33e1fcfda2e7047723e50dd5d535015ccaf',
        'UpdatePackage' => '27bec2ebb61be15985d6d0e56f51247205865cf957084ffe4cc88fe803544fee',
        'DatabaseSnapshot' => 'bfa286b2406e6383d47f85068ef4afa26754699961310e9d3cf1f281fd0fa9f6',
        'UpdateRequirements' => '3e474d5793459e6386964137fd1c90f10c4324eb9ef800886df072c2497bdf18',
    ];
    foreach ($fixed as $class => $hash) {
        vault_rescue_digest(vault_rescue_path($directory, 'runtime/'.$class.'.php'), $hash);
    }
    $plan = [];
    $runner = file_get_contents(vault_rescue_path($directory, 'runtime/UpdateRunner.php'));
    $needle = "\$probe = <<<'PHP'\nrequire \"vendor/autoload.php\";";
    $patched = "\$probe = <<<'PHP'\n\$_SERVER[\"REQUEST_METHOD\"] = \"CLI\";\nrequire_once \"bootstrap/preflight.php\";\nrequire \"vendor/autoload.php\";";
    $plan['UpdateRunner.php'] = ['original' => $runner, 'repaired' => hash('sha256', $runner) === '1d6d347c50da5d7dd1b3f740f54435624c6d215964b6231f5f5fea0466e00807'
        ? $runner : vault_rescue_transform($runner, 'ea3d963d04587b6f544b274981fee2ba39603f9fa41b8feeeb588a68cd187956', [$needle => $patched])];
    $web = file_get_contents(vault_rescue_path($directory, 'runtime/UpdateWeb.php'));
    $plan['UpdateWeb.php'] = ['original' => $web, 'repaired' => in_array(hash('sha256', $web), ['58a190fab54b581e81e619bda10a259757bfecaadfbc93a35661010e1006a6a1', 'f4f4b5ef2534b09a9dbfda056bef298aaadd2bb7d47aec185968cc40782df33d'], true)
        ? $web : vault_rescue_transform($web, '5cbce0bbeb7398c7b6175910687a10dc1700c7d51943e27cf6875ffc96760ac2', vault_rescue_web_replacements())];

    return $plan;
}

function vault_rescue_write(string $path, string $body, int $mode): void
{
    $handle = fopen($path, 'xb');
    if ($handle === false) {
        vault_rescue_fail('无法创建应急文件，已停止。');
    }
    try {
        if (! chmod($path, $mode) || fwrite($handle, $body) !== strlen($body) || ! fflush($handle) || ! fsync($handle)) {
            vault_rescue_fail('无法完整同步应急文件，已停止。');
        }
    } finally {
        fclose($handle);
    }
}

/** @param array<string,array{original:string,repaired:string}> $plan */
function vault_rescue_repair(string $directory, string $task, array $plan): void
{
    $changes = array_filter($plan, static fn (array $entry): bool => $entry['original'] !== $entry['repaired']);
    if ($changes === []) {
        echo "已知问题已修复，无需重复操作。\n";

        return;
    }
    $backup = $directory.'/rescue-'.$task.'-'.bin2hex(random_bytes(6));
    if (! mkdir($backup, 0700)) {
        vault_rescue_fail('无法创建私有修复备份，原引擎保留。');
    }
    $record = ['task' => $task, 'created_at' => gmdate('c'), 'files' => []];
    foreach ($changes as $name => $entry) {
        vault_rescue_write($backup.'/'.$name, $entry['original'], 0600);
        vault_rescue_digest($backup.'/'.$name, hash('sha256', $entry['original']));
        $record['files'][$name] = ['before' => hash('sha256', $entry['original']), 'after' => hash('sha256', $entry['repaired'])];
    }
    vault_rescue_write($backup.'/repair.json', json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n", 0600);
    foreach ($changes as $name => $entry) {
        $target = vault_rescue_path($directory, 'runtime/'.$name);
        $metadata = stat($target);
        $temporary = $directory.'/runtime/.rescue-'.bin2hex(random_bytes(8));
        try {
            vault_rescue_write($temporary, $entry['repaired'], $metadata['mode'] & 0777);
            if ((fileowner($temporary) !== $metadata['uid'] && ! chown($temporary, $metadata['uid']))
                || (filegroup($temporary) !== $metadata['gid'] && ! chgrp($temporary, $metadata['gid']))) {
                vault_rescue_fail('无法保留引擎所有权，原文件保留。');
            }
            vault_rescue_digest($temporary, hash('sha256', $entry['repaired']));
            vault_rescue_digest($target, hash('sha256', $entry['original']));
            if (! rename($temporary, $target)) {
                vault_rescue_fail('引擎替换失败，请检查权限或防篡改策略，备份保留。');
            }
            $handle = fopen(dirname($target), 'r');
            try {
                if (! fsync($handle)) {
                    vault_rescue_fail('引擎目录同步失败，备份保留。');
                }
            } finally {
                fclose($handle);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
    echo "仅修复私有恢复引擎；未修改数据库、业务文件、任务状态或恢复授权。\n";
    echo "CLI 无法清理 PHP-FPM 的 OPcache；若服务器关闭时间戳校验，请由管理员重载本站 PHP-FPM 后再验证网页。\n";
    echo "请重载本站 PHP 服务，再打开 /update-recovery?reauth=1，使用更新前管理员账号验证。\n";
    echo "恢复更新前版本会同时恢复程序和数据库备份；本工具不会自动执行。\n";
}

$lock = null;
$exitCode = 0;
try {
    if (count($argv) < 2 || count($argv) > 4) {
        vault_rescue_fail('用法：php update-rescue.php 项目根目录 [--repair | --restore=任务编号 --confirm-database-rollback]；默认仅检查。');
    }
    $options = array_slice($argv, 2);
    $mode = $options === [] ? 'check' : ($options === ['--repair'] ? 'repair' : 'restore');
    if ($mode === 'restore' && (count($options) !== 2 || ! preg_match('/\A--restore=([a-f0-9]{32})\z/D', $options[0], $match)
        || $options[1] !== '--confirm-database-rollback')) {
        vault_rescue_fail('恢复必须显式指定 --restore=任务编号 和 --confirm-database-rollback；未执行恢复。');
    }
    $root = realpath($argv[1]);
    if ($root === false || ! is_dir($root)) {
        vault_rescue_fail('项目根目录无效，已停止。');
    }
    $directory = vault_rescue_path($root, 'storage/app/updates', true);
    $lockPath = vault_rescue_path($directory, 'update.lock');
    $lock = fopen($lockPath, $mode === 'check' ? 'r' : 'r+');
    if ($lock === false || ! flock($lock, ($mode === 'check' ? LOCK_SH : LOCK_EX) | LOCK_NB)) {
        vault_rescue_fail('更新或恢复仍在运行，请等待请求结束后再检查。');
    }
    $journal = vault_rescue_json(vault_rescue_path($directory, 'journal.json'));
    if (! is_string($journal['id'] ?? null) || ! preg_match('/\A[a-f0-9]{32}\z/D', $journal['id'])
        || ! in_array($journal['status'] ?? '', ['prepared', 'running', 'failed', 'restoring'], true)
        || ! in_array($journal['stage'] ?? '', ['prepared', 'backup', 'files', 'migrate', 'migration-running', 'health', 'restoring'], true)
        || ! is_string($journal['from'] ?? null) || ! preg_match('/\A\d+\.\d+\.\d+\z/D', $journal['from'])
        || ! is_string($journal['to'] ?? null) || ! preg_match('/\A\d+\.\d+\.\d+\z/D', $journal['to'])) {
        vault_rescue_fail('没有可处理的未完成更新任务；已完成或已恢复任务不会修改。');
    }
    $plan = vault_rescue_runtime($directory);
    $backupState = vault_rescue_backups($directory, $journal);
    $report = ['task' => $journal['id'], 'from' => $journal['from'], 'to' => $journal['to'], 'status' => $journal['status'], 'stage' => $journal['stage'],
        'runtime' => 'known', 'backup' => $backupState, 'database_accessed' => false,
        'repair_needed' => count(array_filter($plan, static fn (array $entry): bool => $entry['original'] !== $entry['repaired'])) > 0];
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
    if ($mode === 'check') {
        echo "仅检查，未读取数据库连接配置、未连接数据库、未修改任何文件。\n";
    } elseif ($mode === 'repair') {
        vault_rescue_repair($directory, $journal['id'], $plan);
    } else {
        if ($match[1] !== $journal['id'] || ! in_array($journal['status'], ['failed', 'restoring'], true) || $backupState !== 'file-hashes-verified') {
            vault_rescue_fail('恢复任务编号、状态或备份不符合要求，未执行恢复。');
        }
        if ($plan['UpdateRunner.php']['original'] !== $plan['UpdateRunner.php']['repaired']) {
            vault_rescue_fail('恢复引擎仍有已知健康检查问题，请先运行 --repair；未执行恢复。');
        }
        foreach (['UpdateState', 'UpdatePackage', 'DatabaseSnapshot', 'UpdateRequirements', 'UpdateRunner'] as $class) {
            require_once vault_rescue_path($directory, 'runtime/'.$class.'.php');
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        $lock = null;
        $result = (new UpdateRunner($root))->restore($journal['id']);
        echo json_encode(['status' => $result['status'], 'stage' => $result['stage'], 'database_rollback_requested' => true], JSON_THROW_ON_ERROR)."\n";
        if (($result['status'] ?? '') !== 'restored') {
            vault_rescue_fail('恢复未完成，维护状态与全部备份保留；请检查服务器日志，不要重复更新。');
        }
        echo "原恢复引擎已恢复更新前程序及数据库，健康检查通过。请重新登录后台。\n";
    }
} catch (Throwable $failure) {
    fwrite(STDERR, ($failure->getCode() === 42 ? $failure->getMessage() : '应急操作未完成，任务与备份保留；未显示内部配置或错误。')."\n");
    $exitCode = 1;
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    restore_error_handler();
}
exit($exitCode);
