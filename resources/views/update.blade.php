@extends('layout')
@section('title', '系统更新')
@section('content')
@php
require_once base_path('bootstrap/preflight.php');
$versionLabel = static fn ($value): string => (string) $value === '0.0.0' ? '旧版' : (string) $value;
$hasTask = !empty($update['id']);
$taskStatus = $update['status'] ?? '';
$finished = in_array($taskStatus, ['complete', 'restored', 'cancelled'], true);
$prepared = !$finished && ($update['stage'] ?? '') === 'prepared' && $taskStatus !== 'failed';
$stateLabels = ['prepared' => '待确认', 'running' => '更新中', 'failed' => '需要处理', 'restoring' => '恢复中', 'complete' => '已完成', 'restored' => '已恢复', 'cancelled' => '已取消'];
$finishedMessage = match ($taskStatus) {
    'complete' => '更新完成。',
    'cancelled' => '更新已取消，当前版本未变更。',
    default => '已恢复更新前版本。',
};
$releaseMessage = static fn (string $message): string => str_replace('已获取最新稳定发行版本；无法确认当前版本/基线，请查看发行页。', '版本信息已更新。', $message);
$problem = $problem ? strtr($problem, [
    '尚未建立更新版本记录；上传后将核对既有迁移基线。未知基线不会执行更新。' => '无法识别当前版本，请上传更新包检查。检查通过后才能更新。',
    '更新状态无法安全读取。请保留私有更新目录并检查文件完整性与权限。' => '无法读取更新状态，请保留更新备份并联系服务器管理员。',
]) : $problem;
$stepIndex = ['prepared' => 0, 'backup' => 1, 'files' => 2, 'migrate' => 3, 'migration-running' => 3, 'health' => 4, 'complete' => 5][$update['stage'] ?? ''] ?? 0;
@endphp
<script src="{{ asset('assets/update.js') }}?v={{ vault_asset_version(base_path(), 'assets/update.js') }}" defer></script>
<header class="page-heading"><h1>系统更新</h1></header>
@if($problem)<div class="notice" role="status">{{ $problem }}</div>@endif
<div class="update-layout">
@if($hasTask)
<section class="panel settings-section update-task" data-update-screen data-state="{{ $taskStatus }}" data-finished="{{ $finished ? 'true' : 'false' }}">
<div class="update-task-heading"><div><p class="caption">本次更新</p><h2>{{ $versionLabel($update['from'] ?? '') }} <span aria-label="更新至">→</span> {{ $versionLabel($update['to'] ?? '') }}</h2></div><span class="update-state" data-update-state>{{ $stateLabels[$taskStatus] ?? '处理中' }}</span></div>
<ol class="update-steps" aria-label="更新步骤" data-update-steps>
@foreach(['备份', '安装', '更新数据', '检查'] as $stepLabel)
<li data-update-step="{{ $loop->iteration }}" data-step-state="{{ $taskStatus === 'complete' || $stepIndex > $loop->iteration ? 'complete' : ($stepIndex === $loop->iteration ? 'current' : 'pending') }}" @if($stepIndex === $loop->iteration) aria-current="step" @endif><span>{{ $loop->iteration }}</span>{{ $stepLabel }}</li>
@endforeach
</ol>
<div class="update-progress"><p role="status" aria-live="polite" data-update-status>{{ $finished ? $finishedMessage : ($prepared ? '等待确认' : '正在读取进度…') }}</p>
<div class="update-actions"><button class="button" type="button" data-update-retry hidden>重新检查</button><button class="button primary" type="button" data-update-continue hidden>确认继续更新</button><a class="button primary" href="/login" data-update-done @if(!$finished) hidden @endif>返回后台</a><a class="button" href="/update-recovery?inspect=1" data-update-fallback hidden>继续处理</a></div></div>
@if($prepared)
<div class="update-confirm" data-update-confirm><h3>更新内容</h3><p class="update-notes">{{ $update['package']['release_notes'] ?? '修复问题与功能改进。' }}</p>
<form method="post" action="{{ route('updates.confirm') }}" class="stack-form">@csrf<input type="hidden" name="task" value="{{ $update['id'] }}"><input type="hidden" name="confirmation" value="1"><p class="caption">更新期间后台暂时不可用，请保留此页。</p><button class="button primary" type="submit">确认更新</button></form></div>
@endif
<form method="post" action="/update-recovery" class="stack-form update-restore" data-update-restore hidden><input type="hidden" name="csrf"><input type="hidden" name="action" value="restore"><label class="checkbox-label"><input type="checkbox" name="confirmation" value="restore" required> 恢复更新前版本（同时恢复程序和数据库备份）</label><button class="button destructive" type="submit">确认恢复</button></form>
<noscript><p class="caption">请前往恢复页查看进度并继续操作。</p><a class="button" href="/update-recovery?inspect=1">打开恢复页</a></noscript>
<div class="update-task-details"><details class="update-details"><summary>查看详情</summary><pre data-update-detail>{{ $update['error'] ?? '' }}@foreach($update['log'] ?? [] as $entry)
{{ $entry['time'] ?? '' }} {{ $entry['message'] ?? '' }}@endforeach</pre></details>
<details class="update-details"><summary>恢复帮助</summary><p>校验失败请重新打开恢复页；登录失效请使用更新前的管理员账号验证。不要重复提交旧表单。</p><p><a href="/update-recovery?inspect=1">打开恢复页</a> · <a href="/update-recovery?reauth=1">验证管理员身份</a></p><p>恢复会覆盖程序和数据库。若恢复仍失败，请保留全部备份并联系服务器管理员。</p></details></div>
</section>
@endif
<section class="panel update-setup">
<div class="update-release">
<h2>版本信息</h2>
<dl class="update-versions"><div><dt>当前版本</dt><dd data-update-version>{{ isset($version['program']) ? $versionLabel($version['program']) : '待识别' }}</dd></div><div><dt>最新稳定版</dt><dd>{{ $release['version'] ?: '暂未获取' }}</dd></div></dl>
@if(session('release_check'))<p class="caption" role="status">{{ $releaseMessage(session('release_check')) }}</p>@endif
@if(!$release['version'])<p class="caption" role="status">{{ $releaseMessage($release['message']) }}</p>@endif
<p class="caption">是否适用，以更新包检查结果为准。</p>
<div class="update-actions"><form method="post" action="{{ route('updates.check-release') }}">@csrf<button type="submit" class="button">检查新版本</button></form><a class="text-button" href="{{ $release['url'] }}" target="_blank" rel="noopener noreferrer">官方发行页 ↗</a></div>
</div>
@if(!$hasTask || $finished)
<div class="update-package"><h2>安装更新包</h2><form method="post" enctype="multipart/form-data" action="{{ route('updates.upload') }}" class="stack-form">@csrf<label>官方更新包（ZIP）<input type="file" name="package" required accept=".zip,application/zip"></label><p class="caption">检查通过后，确认开始更新。</p><button type="submit" class="button primary">检查更新包</button></form></div>
@endif
</section>
</div>
@endsection
