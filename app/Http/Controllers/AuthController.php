<?php

namespace App\Http\Controllers;

use App\Services\Geetest;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin(Request $request, Geetest $geetest): View
    {
        $configuration = $geetest->configuration();
        $request->attributes->set('geetest_page', (bool) $configuration['enabled']);

        return view('login', ['geetestEnabled' => $configuration['enabled'], 'geetestId' => $configuration['captcha_id']]);
    }

    public function login(Request $request, Geetest $geetest): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:128']);
        $accountKey = 'login:'.hash('sha256', mb_strtolower($data['email']).'|'.$request->ip());
        $addressKey = 'login-ip:'.hash('sha256', (string) $request->ip());
        foreach ([$accountKey => 5, $addressKey => 20] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw ValidationException::withMessages(['email' => '登录尝试过多，请 '.RateLimiter::availableIn($key).' 秒后重试。']);
            }
        }
        $configuration = $geetest->configuration();
        if ($configuration['enabled'] && ! $geetest->verify($configuration, $request->only(['lot_number', 'captcha_output', 'pass_token', 'gen_time']))) {
            throw ValidationException::withMessages(['geetest' => '验证码未通过或服务暂不可用，请重新验证后重试。']);
        }
        if (! Auth::attempt($data)) {
            RateLimiter::hit($accountKey, 300);
            RateLimiter::hit($addressKey, 300);
            throw ValidationException::withMessages(['email' => '邮箱或密码不正确。']);
        }
        RateLimiter::clear($accountKey);
        RateLimiter::clear($addressKey);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'min:12', 'max:128', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || preg_match('/[\x00-\x1F\x7F]/', $value))) {
                    $fail('新密码至少 12 个字符、最多 72 字节，且不得包含控制字符。');
                }
            }],
        ]);
        $request->user()->update(['password' => Hash::make($data['password'])]);
        $request->session()->regenerate();

        return back()->with('success', '密码已更新。');
    }
}
