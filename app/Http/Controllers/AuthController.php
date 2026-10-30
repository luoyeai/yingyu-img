<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:128']);
        $accountKey = 'login:'.hash('sha256', mb_strtolower($data['email']).'|'.$request->ip());
        $addressKey = 'login-ip:'.hash('sha256', (string) $request->ip());
        foreach ([$accountKey => 5, $addressKey => 20] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw ValidationException::withMessages(['email' => '登录尝试过多，请 '.RateLimiter::availableIn($key).' 秒后重试。']);
            }
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
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'string', 'confirmed', 'min:12', 'max:128']]);
        $request->user()->update(['password' => Hash::make($data['password'])]);
        $request->session()->regenerate();

        return back()->with('success', '密码已更新。');
    }
}
