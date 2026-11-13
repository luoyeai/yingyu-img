@extends('layout')
@section('title', '设置与状态')
@section('content')
@php
$formatBytes = static function ($bytes): string { return number_format($bytes / 1048576, 2).' MB'; };
$imagickReady = extension_loaded('imagick');
$webpReady = $imagickReady && count(\Imagick::queryFormats('WEBP')) > 0;
$originalCount = \App\Models\Image::withTrashed()->whereNotNull('original_path')->count();
$submittedForm = old('_settings_form', '');
$errorForm = is_string($submittedForm) && preg_match('/\Astorage-(?:create|[1-9][0-9]*)\z/D', $submittedForm) === 1 ? $submittedForm : '';
$settingsOldValue = static function (string $key, mixed $fallback = ''): string {
    $value = old($key, $fallback);
    return is_string($value) || is_int($value) || is_float($value) ? (string) $value : (string) $fallback;
};
$settingsSections = ['upload' => '上传设置', 'storage' => '图片存储', 'groups' => '分组管理', 'security' => '账号与安全', 'runtime' => '运行状态', 'cleanup' => '数据清理'];
$returnSection = 'upload';
if (session('success')) {
    $previousUrl = url()->previous();
    $settingsUrl = route('settings');
    if (parse_url($previousUrl, PHP_URL_HOST) === parse_url($settingsUrl, PHP_URL_HOST) && parse_url($previousUrl, PHP_URL_PATH) === parse_url($settingsUrl, PHP_URL_PATH)) {
        parse_str(parse_url($previousUrl, PHP_URL_QUERY) ?? '', $previousQuery);
        $returnSection = $previousQuery['section'] ?? 'upload';
    }
}
$requestedSection = $errors->any() ? old('_settings_section', request()->query('section', 'upload')) : request()->query('section', $returnSection);
$activeSection = is_string($requestedSection) && isset($settingsSections[$requestedSection]) ? $requestedSection : 'upload';
@endphp
<div class="settings-page">
<header class="page-heading"><h1>设置与状态</h1></header>
<section class="storage-overview panel" aria-label="存储概览"><div><span>图片总数</span><strong>{{ number_format($stats['count']) }}<small> 张</small></strong></div><div><span>WebP 与预览</span><strong>{{ $formatBytes($stats['bytes']) }}</strong></div><div><span>私有原图</span><strong>{{ $formatBytes($stats['original_bytes']) }}</strong></div><div><span>回收站</span><strong>{{ $formatBytes($stats['trash_bytes']) }}</strong></div></section>
@if($pendingOperations)
<div class="notice settings-alert" role="status">有 <strong>{{ $pendingOperations }}</strong> 项未完成存储操作，相关图片暂时锁定。<a href="{{ route('settings', ['section' => 'storage']) }}#storage-pending" data-settings-link="storage">前往处理 →</a></div>
@endif
<div class="settings-layout" data-settings data-active-section="{{ $activeSection }}" data-error-fields="{{ json_encode($errors->keys()) }}" data-error-form="{{ $errorForm }}">
<nav class="settings-nav" aria-label="设置分类">
@foreach($settingsSections as $key => $label)
<a href="{{ route('settings', ['section' => $key]) }}" data-settings-link="{{ $key }}" @if($activeSection === $key) aria-current="page" @endif>{{ $label }}@if($key === 'storage' && $pendingOperations)<span class="settings-nav-count" aria-label="{{ $pendingOperations }} 项待处理">{{ $pendingOperations }}</span>@endif</a>
@endforeach
</nav>
<div class="settings-content">
<div class="settings-category" id="settings-upload" data-settings-section="upload" @if($activeSection !== 'upload') hidden @endif>
<section class="panel settings-section"><h2>上传设置</h2><p class="caption">仅影响新上传的图片。</p><form action="{{ route('settings.save') }}" method="post" class="stack-form">@csrf<input type="hidden" name="_settings_section" value="upload"> @method('PUT')<fieldset class="settings-fieldset"><legend>图片处理</legend><div class="form-columns"><label>默认质量（1–100）<input type="number" min="1" max="100" required name="quality" value="{{ $settingsOldValue('quality', $options['quality']) }}"></label><label>最长边（像素）<input type="number" min="0" max="20000" required name="max_edge" value="{{ $settingsOldValue('max_edge', $options['max_edge']) }}"><span class="caption">0 为原尺寸，不放大图片。</span></label></div><div class="form-columns"><label>默认无损压缩<select name="lossless"><option value="0" @selected(!old('lossless', $options['lossless']))>关闭 · 有损 WebP</option><option value="1" @selected(old('lossless', $options['lossless']))>开启 · 无损 WebP</option></select></label><label>保留私有原图<select name="keep_original"><option value="0" @selected(!old('keep_original', $options['keep_original']))>关闭</option><option value="1" @selected(old('keep_original', $options['keep_original']))>开启</option></select></label></div><p class="caption">原图仅管理员可下载；关闭后不删除历史原图。</p></fieldset><fieldset class="settings-fieldset"><legend>上传限制</legend><div class="form-columns"><label>每批图片数量<input name="batch_limit" type="number" min="1" max="100" required value="{{ $settingsOldValue('batch_limit', $options['batch_limit']) }}"></label><label>单图上限（MB）<input name="max_file_mb" type="number" min="1" max="100" required value="{{ $settingsOldValue('max_file_mb', $options['max_file_mb']) }}"></label></div><label>单图像素上限<input name="max_pixels" type="number" min="1000000" max="100000000" step="1000000" required value="{{ $settingsOldValue('max_pixels', $options['max_pixels']) }}"><span class="caption">像素总量 = 宽 × 高。</span></label></fieldset><div class="settings-form-actions"><button class="button primary" type="submit">保存上传设置</button></div></form></section>

</div>
<div class="settings-category" id="settings-storage" data-settings-section="storage" @if($activeSection !== 'storage') hidden @endif>
@include('storage')
</div>
<div class="settings-category" id="settings-groups" data-settings-section="groups" @if($activeSection !== 'groups') hidden @endif>
<section class="panel settings-section"><h2>分组管理</h2><p class="caption">删除分组后，图片移至未分组，文件与外链保持有效。</p><form method="post" action="{{ route('groups.store') }}" class="inline-form">@csrf<input type="hidden" name="_settings_section" value="groups"><input name="name" aria-label="新分组名称" placeholder="新分组名称" required maxlength="100"><button class="button" type="submit">创建分组</button></form><ul class="group-list">@forelse($groups as $group)<li><span>{{ $group->name }}</span><form method="post" action="{{ route('groups.destroy',$group) }}" data-confirm="删除分组“{{ $group->name }}”？其中图片将移至未分组。">@csrf<input type="hidden" name="_settings_section" value="groups"> @method('DELETE')<button class="text-button danger" type="submit">删除分组</button></form></li>@empty<li class="muted">尚无自定义分组</li>@endforelse</ul></section>

</div>
<div class="settings-category" id="settings-security" data-settings-section="security" @if($activeSection !== 'security') hidden @endif>
<section class="panel settings-section"><h2>修改密码</h2><p class="caption">至少 12 个字符，最多 72 字节（汉字通常占 3 字节），不含控制字符。</p><form method="post" action="{{ route('password') }}" class="stack-form">@csrf<input type="hidden" name="_settings_section" value="security"> @method('PUT')<label>当前密码<input name="current_password" type="password" autocomplete="current-password" required></label><label>新密码<input name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="72"></label><label>确认新密码<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="72"></label><button class="button" type="submit">更新密码</button></form></section>

@include('geetest-settings')
</div>
<div class="settings-category" id="settings-runtime" data-settings-section="runtime" @if($activeSection !== 'runtime') hidden @endif>
<section class="panel settings-section">
<div class="settings-section-heading"><div><h2>运行状态</h2></div><form method="post" action="{{ route('settings.diagnostics') }}">@csrf<input type="hidden" name="_settings_section" value="runtime"><button class="button small" type="submit">重新检测</button></form></div>
<div class="settings-status-group"><h3>图像处理</h3><dl class="status-list"><div><dt>PHP</dt><dd>{{ PHP_VERSION }}</dd></div><div><dt>Imagick</dt><dd class="{{ $imagickReady ? '' : 'text-danger' }}">{{ $imagickReady ? phpversion('imagick') : '未加载' }}</dd></div><div><dt>WebP 编解码</dt><dd class="{{ $webpReady ? '' : 'text-danger' }}">{{ $webpReady ? '可用' : '不可用' }}</dd></div></dl></div>
<div class="settings-status-group"><h3>资源限制</h3><dl class="status-list"><div><dt>PHP 单文件上限</dt><dd>{{ ini_get('upload_max_filesize') }}</dd></div><div><dt>PHP 请求上限</dt><dd>{{ ini_get('post_max_size') }}</dd></div><div><dt>PHP 内存上限</dt><dd>{{ ini_get('memory_limit') }}</dd></div><div><dt>磁盘剩余空间</dt><dd>{{ number_format($stats['free']/1073741824,2) }} GB</dd></div></dl><p class="caption">实际上传上限还受服务器配置限制。</p></div>
<div class="settings-status-group"><h3>目录权限</h3><dl class="status-list">@foreach(['WebP 图片目录'=>public_path('media'),'私有存储目录'=>storage_path('app'),'框架缓存目录'=>storage_path('framework'),'应用缓存目录'=>base_path('bootstrap/cache')] as $label=>$path)<div><dt>{{ $label }}</dt><dd class="{{ is_writable(is_dir($path)?$path:dirname($path)) ? '':'text-danger' }}">{{ is_writable(is_dir($path)?$path:dirname($path)) ? (is_dir($path)?'可写':'可创建'):'不可写' }}</dd></div>@endforeach</dl></div>
<div class="settings-status-group"><h3>计划任务</h3><dl class="status-list"><div><dt>cron 最近执行</dt><dd>{{ $stats['cron'] ?: '尚未执行' }}</dd></div></dl><p class="caption">回收站自动清理需要计划任务正常运行。</p></div>
</section></div>
<div class="settings-category" id="settings-cleanup" data-settings-section="cleanup" @if($activeSection !== 'cleanup') hidden @endif>
<section class="panel settings-section danger-section"><h2>清理历史原图</h2><p>范围：全部图片与回收站中的 <strong>{{ $originalCount }} 份私有原图</strong>，预计释放 <strong>{{ $formatBytes($stats['original_bytes']) }}</strong>。WebP 图片、缩略图和公开链接继续保留。</p><p class="caption">清理不可恢复，请先备份所需原图；不改变新上传的保留设置。</p><form action="{{ route('originals.clean') }}" method="post" class="inline-form" data-confirm="确认清理所有 {{ $originalCount }} 份历史私有原图？此操作不可恢复，预计释放 {{ $formatBytes($stats['original_bytes']) }}。">@csrf<input type="hidden" name="_settings_section" value="cleanup"><label class="clean-confirm-label">输入“清理所有历史原图”确认<input name="confirmation" required pattern="清理所有历史原图" placeholder="清理所有历史原图" autocomplete="off"></label><button class="button destructive" type="submit" @disabled($originalCount===0)>清理历史原图</button></form></section>
</div>
</div></div>
</div>
<script src="{{ asset('assets/settings.js') }}?v={{ vault_asset_version(base_path(), 'assets/settings.js') }}" defer></script>
@endsection
