<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Geetest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GeetestController extends Controller
{
    public function save(Request $request, Geetest $geetest): RedirectResponse
    {
        $key = $request->input('geetest_key');
        $request->request->remove('geetest_key');
        $request->query->remove('geetest_key');
        $request->json()->remove('geetest_key');
        $data = Validator::make(['captcha_id' => $request->input('captcha_id'), 'geetest_key' => $key], [
            'captcha_id' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/iD'],
            'geetest_key' => ['nullable', 'string', 'regex:/\A[a-f0-9]{32}\z/iD'],
        ])->validate();
        DB::transaction(function () use ($data, $geetest): void {
            Setting::where('key', 'geetest')->lockForUpdate()->first();
            $configuration = $geetest->configuration();
            if (empty($data['geetest_key']) && $configuration['encrypted_key'] === '') {
                throw ValidationException::withMessages(['geetest' => '首次配置必须填写极验密钥。']);
            }
            Setting::put('geetest', [
                'enabled' => false, 'captcha_id' => $data['captcha_id'],
                'encrypted_key' => ! empty($data['geetest_key']) ? Crypt::encryptString($data['geetest_key']) : $configuration['encrypted_key'],
                'revision' => bin2hex(random_bytes(16)),
            ]);
        });
        $request->session()->forget(Geetest::PROOF_KEY);

        return redirect()->route('settings')->with('success', '极验配置已保存，登录验证已关闭。请完成后台实测后再开启。');
    }

    public function test(Request $request, Geetest $geetest): JsonResponse
    {
        $request->session()->forget(Geetest::PROOF_KEY);
        $configuration = $geetest->configuration();
        if (! $geetest->verify($configuration, $request->only(['lot_number', 'captcha_output', 'pass_token', 'gen_time']))) {
            return response()->json(['message' => '服务端验证未通过，请检查配置、网络后重新验证。'], 422);
        }
        if (! hash_equals($geetest->fingerprint($configuration), $geetest->fingerprint($geetest->configuration()))) {
            return response()->json(['message' => '配置已更改，请刷新后重新验证。'], 409);
        }
        $geetest->rememberProof($request, $configuration);

        return response()->json(['message' => '服务端验证成功，请在五分钟内开启。']);
    }

    public function activation(Request $request, Geetest $geetest): RedirectResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        DB::transaction(function () use ($request, $geetest, $data): void {
            Setting::where('key', 'geetest')->lockForUpdate()->first();
            $configuration = $geetest->configuration();
            if ($data['enabled'] && ! $geetest->hasProof($request, $configuration)) {
                throw ValidationException::withMessages(['geetest' => '请先使用当前配置完成后台服务端实测，验证证明有效期为五分钟。']);
            }
            $configuration['enabled'] = (bool) $data['enabled'];
            Setting::put('geetest', $configuration);
        });
        $request->session()->forget(Geetest::PROOF_KEY);

        return redirect()->route('settings')->with('success', $data['enabled'] ? '登录极验验证已开启。' : '登录极验验证已关闭。');
    }
}
