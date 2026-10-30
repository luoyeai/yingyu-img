<?php

namespace App\Http\Controllers;

use App\Services\Updates\UpdateRunner;
use App\Services\Updates\UpdateState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class UpdateController extends Controller
{
    public function index(UpdateRunner $runner): View
    {
        $version = null;
        $problem = null;
        try {
            $version = $runner->current();
        } catch (Throwable) {
            $problem = '尚未建立更新版本记录；上传后将核对既有迁移基线。未知基线不会执行更新。';
        }

        try {
            $update = $runner->status();
        } catch (Throwable) {
            $update = ['status' => 'unavailable'];
            $problem = '更新状态无法安全读取。请保留私有更新目录并检查文件完整性与权限。';
        }

        return view('update', ['version' => $version, 'update' => $update, 'problem' => $problem]);
    }

    public function upload(Request $request, UpdateRunner $runner, UpdateState $state): RedirectResponse
    {
        $request->validate(['package' => ['required', 'file', 'max:102400']]);
        $temporary = $state->directory().'/upload-'.bin2hex(random_bytes(12)).'.zip';
        try {
            $request->file('package')->move(dirname($temporary), basename($temporary));
            chmod($temporary, 0600);
            $update = $runner->prepare($temporary);
            $response = redirect()->route('updates')->with('success', '更新包检查通过。');

            return $this->grant($response, $state, (string) $update['id'], $request->isSecure());
        } catch (Throwable $failure) {
            return back()->withErrors(['package' => $this->safeMessage($failure)]);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function confirm(Request $request, UpdateRunner $runner, UpdateState $state): RedirectResponse
    {
        $data = $request->validate(['task' => ['required', 'regex:/\A[a-f0-9]{32}\z/D'], 'confirmation' => ['accepted']]);
        try {
            $update = $runner->status();
            if (($update['id'] ?? null) !== $data['task']) {
                throw new RuntimeException('更新任务已变化，请重新检查。');
            }

            return $this->grant(redirect()->route('updates'), $state, $data['task'], $request->isSecure(), true);
        } catch (Throwable $failure) {
            return back()->withErrors(['update' => $this->safeMessage($failure)]);
        }
    }

    public function recovery(Request $request, UpdateRunner $runner, UpdateState $state): RedirectResponse
    {
        try {
            $update = $runner->status();
            if (! preg_match('/\A[a-f0-9]{32}\z/D', (string) ($update['id'] ?? ''))) {
                throw new RuntimeException('没有可继续或恢复的更新任务。');
            }

            return $this->grant(redirect('/update-recovery'), $state, $update['id'], $request->isSecure());
        } catch (Throwable $failure) {
            return back()->withErrors(['update' => $this->safeMessage($failure)]);
        }
    }

    private function grant(RedirectResponse $response, UpdateState $state, string $task, bool $secure, bool $confirmed = false): RedirectResponse
    {
        $secret = bin2hex(random_bytes(32));
        $expires = time() + 7 * 86400;
        $state->locked(function () use ($state, $secret, $expires, $task, $confirmed): void {
            $journal = $state->read('journal.json');
            if (($journal['id'] ?? null) !== $task) {
                throw new RuntimeException('更新任务已变化，请重新检查。');
            }
            $state->write('recovery-grant.json', ['hash' => hash('sha256', $secret), 'expires' => $expires, 'task' => $task, 'confirmed' => $confirmed]);
        });
        $response->headers->clearCookie('vault_update_recovery', '/update-recovery', null, $secure, true, Cookie::SAMESITE_STRICT);
        $response->headers->setCookie(new Cookie('vault_update_recovery', $secret, $expires, '/', null, $secure, true, false, Cookie::SAMESITE_STRICT));

        return $response;
    }

    private function safeMessage(Throwable $failure): string
    {
        return $failure instanceof RuntimeException && ! $failure instanceof PDOException ? $failure->getMessage() : '更新检查未完成，请检查更新包、上传限制和本站 PHP 用户权限。内部配置未显示。';
    }
}
