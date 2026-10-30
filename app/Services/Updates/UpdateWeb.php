<?php

namespace App\Services\Updates;

use PDOException;
use RuntimeException;
use Throwable;

/** Standalone recovery web handler: this class must never depend on Laravel/vendor. */
class UpdateWeb
{
    public function __construct(private string $root) {}

    public function handle(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        $state = new UpdateState($this->root);
        $grant = $state->read('recovery-grant.json');
        $secret = $_COOKIE['vault_update_recovery'] ?? '';
        if (! is_string($secret) || ! preg_match('/\A[a-f0-9]{64}\z/D', $secret)
            || ! is_array($grant) || ! is_string($grant['hash'] ?? null)
            || ! hash_equals($grant['hash'], hash('sha256', $secret))
            || ! is_int($grant['expires'] ?? null) || $grant['expires'] <= time()) {
            $this->login($state);

            return;
        }
        $runner = new UpdateRunner($this->root);
        $update = $runner->status();
        if (($update['id'] ?? null) !== ($grant['task'] ?? null)) {
            http_response_code(409);
            echo '恢复授权与当前任务不一致。请从已登录后台重新打开恢复入口。';

            return;
        }
        $csrf = hash_hmac('sha256', 'vault-update:'.$grant['task'], $secret);
        $error = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $submitted = $_POST['csrf'] ?? '';
            if (! is_string($submitted) || ! hash_equals($csrf, $submitted)) {
                http_response_code(419);
                echo '恢复请求校验失败。请返回恢复页重试。';

                return;
            }
            try {
                $action = $_POST['action'] ?? '';
                if ($action === 'advance' && ($grant['confirmed'] ?? false) === true) {
                    $stage = $_POST['stage'] ?? '';
                    if (! is_string($stage) || $stage !== ($update['stage'] ?? null)) {
                        throw new RuntimeException('任务阶段已变化，请刷新页面。');
                    }
                    $update = $runner->advance($grant['task'], $stage);
                } elseif ($action === 'restore' && ($_POST['confirmation'] ?? '') === 'restore') {
                    $update = $runner->restore($grant['task']);
                } else {
                    throw new RuntimeException('恢复操作无效或更新尚未确认。');
                }
                if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'VaultUpdate') {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($this->payload($update, $grant, $csrf), JSON_THROW_ON_ERROR);

                    return;
                }
                header('Location: /update-recovery', true, 303);

                return;
            } catch (Throwable $failure) {
                http_response_code(409);
                $error = $failure instanceof RuntimeException && ! $failure instanceof PDOException ? $failure->getMessage() : '操作未完成。已保留阶段日志与备份，请刷新后检查；内部错误和配置未显示。';
                $update = $runner->status();
            }
        } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            header('Allow: GET, POST');

            return;
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'VaultUpdate') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->payload($update, $grant, $csrf, $error), JSON_THROW_ON_ERROR);

            return;
        }
        $confirmed = ($grant['confirmed'] ?? false) === true;
        $template = $state->directory().'/runtime/update-recovery.php';
        if (! is_file($template) || is_link($template)) {
            http_response_code(503);
            echo '受保护恢复界面不完整，请保留更新私有目录并由服务器管理员恢复受保护备份。';

            return;
        }
        require $template;
    }

    /** @param array<string,mixed> $update
     * @param  array<string,mixed>  $grant
     * @return array<string,mixed>
     */
    private function payload(array $update, array $grant, string $csrf, ?string $error = null): array
    {
        return [
            'ok' => $error === null,
            'stage' => $update['stage'] ?? '',
            'status' => $update['status'] ?? '',
            'confirmed' => ($grant['confirmed'] ?? false) === true,
            'version' => ($update['status'] ?? '') === 'complete' ? ($update['to'] ?? '') : ($update['from'] ?? ''),
            'csrf' => $csrf,
            'error' => $error ?? $update['error'] ?? '',
            'log' => array_map(static fn (array $entry): string => ($entry['time'] ?? '').' '.($entry['message'] ?? ''), $update['log'] ?? []),
        ];
    }

    private function login(UpdateState $state): void
    {
        $journal = $state->read('journal.json');
        $account = $state->read('recovery-account.json');
        if (! is_array($journal) || ! in_array($journal['status'] ?? '', ['prepared', 'running', 'failed', 'restoring'], true)
            || ! is_array($account) || ($account['task'] ?? null) !== ($journal['id'] ?? null)
            || ! is_string($account['email'] ?? null) || ! is_string($account['password_hash'] ?? null)) {
            http_response_code(403);
            echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>恢复入口未授权</title><h1>恢复入口未授权</h1><p>没有可重新认证的更新任务。请从已登录后台的系统更新页打开恢复入口。</p></html>';

            return;
        }
        $challenge = $_COOKIE['vault_recovery_challenge'] ?? '';
        if (! is_string($challenge) || ! preg_match('/\A[a-f0-9]{64}\z/D', $challenge)) {
            $challenge = bin2hex(random_bytes(32));
            $this->cookie('vault_recovery_challenge', $challenge, time() + 1800);
        }
        $csrf = hash_hmac('sha256', 'vault-recovery-login:'.$journal['id'], $challenge);
        $error = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (($_POST['action'] ?? '') !== 'login' || ! is_string($_POST['csrf'] ?? null) || ! hash_equals($csrf, $_POST['csrf'])) {
                http_response_code(419);
                echo '恢复登录请求校验失败，请刷新恢复页。';

                return;
            }
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            if (! is_string($email) || ! is_string($password) || strlen($email) > 255 || strlen($password) > 128) {
                http_response_code(422);
                $error = '邮箱或密码格式不正确。';
            } else {
                $result = $state->locked(function () use ($state, $account, $email, $password, $journal): string {
                    $currentJournal = $state->read('journal.json');
                    $currentAccount = $state->read('recovery-account.json');
                    if (($currentJournal['id'] ?? null) !== $journal['id']
                        || ! in_array($currentJournal['status'] ?? '', ['prepared', 'running', 'failed', 'restoring'], true)
                        || ($currentAccount['task'] ?? null) !== $journal['id']
                        || ($currentAccount['email'] ?? null) !== $account['email']
                        || ($currentAccount['password_hash'] ?? null) !== $account['password_hash']) {
                        return 'invalid';
                    }
                    $journal = $currentJournal;
                    $rate = $state->read('recovery-rate.json') ?? ['window' => time(), 'global' => 0, 'addresses' => []];
                    if (! is_int($rate['window'] ?? null) || $rate['window'] <= time() - 900) {
                        $rate = ['window' => time(), 'global' => 0, 'addresses' => []];
                    }
                    $address = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                    $attempts = (int) ($rate['addresses'][$address] ?? 0);
                    if ((int) ($rate['global'] ?? 0) >= 30 || $attempts >= 5) {
                        return 'limited';
                    }
                    $rate['global'] = (int) ($rate['global'] ?? 0) + 1;
                    $rate['addresses'][$address] = $attempts + 1;
                    $state->write('recovery-rate.json', $rate);
                    if (! hash_equals(strtolower($account['email']), strtolower(trim($email))) || ! password_verify($password, $account['password_hash'])) {
                        return 'invalid';
                    }
                    $secret = bin2hex(random_bytes(32));
                    $expires = time() + 7 * 86400;
                    $state->write('recovery-grant.json', ['hash' => hash('sha256', $secret), 'expires' => $expires, 'task' => $journal['id'], 'confirmed' => ($journal['active'] ?? false) === true || in_array($journal['status'], ['running', 'failed', 'restoring'], true)]);
                    $this->cookie('vault_update_recovery', $secret, $expires);
                    $rate['addresses'][$address] = 0;
                    $state->write('recovery-rate.json', $rate);

                    return 'accepted';
                });
                if ($result === 'accepted') {
                    header('Location: /updates', true, 303);

                    return;
                }
                http_response_code($result === 'limited' ? 429 : 403);
                $error = $result === 'limited' ? '恢复登录尝试过多，请在 15 分钟后重试。' : '邮箱或密码不正确。请使用本次更新前的管理员密码。';
            }
        } else {
            http_response_code(403);
        }
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $assetHash = is_file($this->root.'/public/assets/app.css') ? substr(hash_file('sha256', $this->root.'/public/assets/app.css'), 0, 16) : 'missing';
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>恢复登录 · 映屿Img</title><link rel="stylesheet" href="/assets/app.css?v='.$escape($assetHash).'"></head><body><main class="workspace"><header class="page-heading"><h1>恢复登录</h1><p>浏览器授权丢失或过期时，使用本次更新前的管理员账号重新验证。此入口不依赖 Laravel 或 vendor。</p></header><section class="panel settings-section">';
        if ($error !== null) {
            echo '<p class="notice error" role="alert">'.$escape($error).'</p>';
        }
        echo '<form method="post" action="/update-recovery" class="stack-form"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="'.$escape($csrf).'"><label>管理员邮箱<input type="email" name="email" maxlength="255" autocomplete="username" required></label><label>更新前管理员密码<input type="password" name="password" maxlength="128" autocomplete="current-password" required></label><button class="button primary" type="submit">验证并打开恢复入口</button></form></section></main></body></html>';
    }

    private function cookie(string $name, string $value, int $expires): void
    {
        if ($name === 'vault_update_recovery') {
            setcookie($name, '', ['expires' => time() - 3600, 'path' => '/update-recovery', 'httponly' => true, 'samesite' => 'Strict']);
        }
        setcookie($name, $value, ['expires' => $expires, 'path' => $name === 'vault_update_recovery' ? '/' : '/update-recovery', 'secure' => ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
    }
}
