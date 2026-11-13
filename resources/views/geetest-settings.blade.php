@php
$geetestService = app(\App\Services\Geetest::class);
$geetestConfiguration = $geetestService->configuration();
$captchaId = old('captcha_id', $geetestConfiguration['captcha_id']);
$captchaId = is_string($captchaId) ? $captchaId : $geetestConfiguration['captcha_id'];
@endphp
<section class="panel settings-section">
    <div class="settings-section-heading"><h2>登录极验 V4</h2><span class="settings-state">{{ $geetestConfiguration['enabled'] ? '已开启' : '已关闭' }}</span></div>
    <p class="caption">保存配置会关闭验证；请在当前会话测试通过后，五分钟内开启。</p>
    <h3 class="settings-step">1 · 保存配置</h3>
    <form action="{{ route('geetest.save') }}" method="post" class="stack-form">@csrf<input type="hidden" name="_settings_section" value="security"> @method('PUT')
        <label>验证码 ID<input name="captcha_id" required pattern="[a-fA-F0-9]{32}" maxlength="32" value="{{ $captchaId }}" autocomplete="off"></label>
        <label>验证码密钥<input name="geetest_key" type="password" pattern="[a-fA-F0-9]{32}" maxlength="32" autocomplete="new-password" @required($geetestConfiguration['encrypted_key'] === '')><span class="caption">{{ $geetestConfiguration['encrypted_key'] !== '' ? '已保存密钥；留空保留，填写新值替换。' : '首次配置请填写密钥。' }}密钥不会回显。</span></label>
        <button class="button" type="submit">保存配置并关闭验证</button>
    </form>
    <div class="settings-step-block"><h3 class="settings-step">2 · 测试验证码</h3>
    @if($geetestConfiguration['captcha_id'] !== '' && $geetestConfiguration['encrypted_key'] !== '')
    <div data-geetest-settings data-captcha-id="{{ $geetestConfiguration['captcha_id'] }}" data-test-url="{{ route('geetest.test') }}">
        <p class="caption">请先保存修改，再测试。</p>
        <button class="button" type="button" data-geetest-test>测试验证码</button>
        <p class="caption" role="status" data-geetest-status>{{ $geetestService->hasProof(request(), $geetestConfiguration) ? '测试通过，请在五分钟内开启。' : '尚未通过测试。' }}</p>
    </div>
    @else
    <p class="caption">请先保存验证码 ID 和密钥。</p>
    @endif
    <noscript><p class="caption">请启用 JavaScript 后测试验证码。</p></noscript></div>
    <div class="settings-step-block"><h3 class="settings-step">3 · 开启登录验证</h3>
    <form action="{{ route('geetest.activation') }}" method="post" class="stack-form">@csrf<input type="hidden" name="_settings_section" value="security"> @method('PUT')
        <input type="hidden" name="enabled" value="{{ $geetestConfiguration['enabled'] ? '0' : '1' }}">
        <button class="button" type="submit">{{ $geetestConfiguration['enabled'] ? '关闭登录验证' : '开启登录验证（需先通过测试）' }}</button>
    </form>
    </div>
    <script src="{{ asset('assets/geetest.js') }}?v={{ vault_asset_version(base_path(), 'assets/geetest.js') }}" defer></script>
</section>
