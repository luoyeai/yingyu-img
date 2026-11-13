<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

class Geetest
{
    public const PROOF_KEY = 'geetest_activation_proof';

    /** @return array{enabled: bool, captcha_id: string, encrypted_key: string, revision: string} */
    public function configuration(): array
    {
        return array_replace(['enabled' => false, 'captcha_id' => '', 'encrypted_key' => '', 'revision' => ''], Setting::value('geetest', []));
    }

    /** @param array{enabled: bool, captcha_id: string, encrypted_key: string, revision: string} $configuration */
    public function fingerprint(array $configuration): string
    {
        return hash('sha256', $configuration['captcha_id'].'|'.$configuration['encrypted_key'].'|'.$configuration['revision']);
    }

    /**
     * @param  array{enabled: bool, captcha_id: string, encrypted_key: string, revision: string}  $configuration
     * @param  array<string, mixed>  $challenge
     */
    public function verify(array $configuration, array $challenge): bool
    {
        foreach (['lot_number' => 128, 'captcha_output' => 8192, 'pass_token' => 2048, 'gen_time' => 20] as $field => $limit) {
            if (! is_string($challenge[$field] ?? null) || $challenge[$field] === '' || strlen($challenge[$field]) > $limit) {
                return false;
            }
        }
        if (! preg_match('/\A[a-f0-9]{32}\z/iD', $configuration['captcha_id']) || $configuration['encrypted_key'] === '') {
            return false;
        }
        try {
            $key = Crypt::decryptString($configuration['encrypted_key']);
            $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->post('https://gcaptcha4.geetest.com/validate?captcha_id='.$configuration['captcha_id'], [
                    'lot_number' => $challenge['lot_number'],
                    'captcha_output' => $challenge['captcha_output'],
                    'pass_token' => $challenge['pass_token'],
                    'gen_time' => $challenge['gen_time'],
                    'sign_token' => hash_hmac('sha256', $challenge['lot_number'], $key),
                ]);
            if (! $response->successful() || $response->json('result') !== 'success'
                || $response->json('captcha_args.lot_number') !== $challenge['lot_number']) {
                return false;
            }

            return Cache::add('geetest-used:'.hash('sha256', $configuration['captcha_id'].'|'.$challenge['lot_number']), true, 600);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array{enabled: bool, captcha_id: string, encrypted_key: string, revision: string} $configuration */
    public function rememberProof(Request $request, array $configuration): void
    {
        $request->session()->put(self::PROOF_KEY, [
            'fingerprint' => $this->fingerprint($configuration), 'user' => (string) $request->user()->getAuthIdentifier(),
            'session' => hash('sha256', $request->session()->getId()), 'expires' => now()->addMinutes(5)->timestamp,
        ]);
    }

    /** @param array{enabled: bool, captcha_id: string, encrypted_key: string, revision: string} $configuration */
    public function hasProof(Request $request, array $configuration): bool
    {
        $proof = $request->session()->get(self::PROOF_KEY);

        return is_array($proof) && ($proof['expires'] ?? 0) > now()->timestamp
            && ($proof['expires'] ?? 0) <= now()->addMinutes(5)->timestamp
            && ($proof['user'] ?? '') === (string) $request->user()->getAuthIdentifier()
            && hash_equals($this->fingerprint($configuration), $proof['fingerprint'] ?? '')
            && hash_equals(hash('sha256', $request->session()->getId()), $proof['session'] ?? '');
    }
}
