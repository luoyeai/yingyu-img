@extends('layout')
@section('title', '系统更新')
@section('content')
@php
require_once base_path('bootstrap/preflight.php');
$versionLabel = static fn ($value): string => (string) $value === '0.0.0' ? '旧版' : (string) $value;
$hasTask = !empty($update['id']);
$finished = in_array($update['status'] ?? '', ['complete', 'restored', 'cancelled'], true);
$prepared = ($update['stage'] ?? '') === 'prepared' && ($update['status'] ?? '') !== 'failed';
@endphp
<script src="{{ asset('assets/update.js') }}?v={{ vault_asset_version(base_path(), 'assets/update.js') }}" defer></script>
<header class="page-heading"><h1>系统更新</h1><p>当前版本 <span data-update-version>{{ isset($version['program']) ? $versionLabel($version['program']) : '待识别' }}</span></p></header>
@if($problem)<div class="notice" role="status">{{ $problem }}</div>@endif
<div class="update-layout">
@if(!$hasTask || $finished)
<section class="panel settings-section"><h2>安装更新</h2><form method="post" enctype="multipart/form-data" action="{{ route('updates.upload') }}" class="stack-form">@csrf<label>选择官方更新包<input type="file" name="package" required accept=".zip,application/zip"></label><button type="submit" class="button primary">检查更新包</button></form></section>
@endif
@if($hasTask)
<section class="panel settings-section" data-update-screen data-finished="{{ $finished ? 'true' : 'false' }}">
<h2>{{ $versionLabel($update['from'] ?? '') }} → {{ $versionLabel($update['to'] ?? '') }}</h2>
@if($prepared)
<div data-update-confirm><p class="update-notes">{{ $update['package']['release_notes'] ?? '修复问题与功能改进。' }}</p>
<form method="post" action="{{ route('updates.confirm') }}" class="stack-form">@csrf<input type="hidden" name="task" value="{{ $update['id'] }}"><input type="hidden" name="confirmation" value="1"><p class="caption">更新期间后台暂时不可用，请保留此页。</p><button class="button primary" type="submit">确认更新</button></form></div>
@endif
<p role="status" aria-live="polite" data-update-status>{{ $finished ? (($update['status'] ?? '') === 'complete' ? '更新完成。' : '已恢复更新前版本。') : ($prepared ? '等待确认' : '正在读取进度…') }}</p>
<div class="update-actions"><button class="button" type="button" data-update-retry hidden>重新检查</button><a class="button primary" href="/login" data-update-done @if(!$finished) hidden @endif>返回后台</a><a class="button" href="/update-recovery" data-update-fallback hidden>继续处理</a></div>
<form method="post" action="/update-recovery" class="stack-form" data-update-restore hidden><input type="hidden" name="csrf"><input type="hidden" name="action" value="restore"><label class="checkbox-label"><input type="checkbox" name="confirmation" value="restore" required> 恢复更新前版本</label><button class="button destructive" type="submit">确认恢复</button></form>
<details class="update-details"><summary>查看详情</summary><pre data-update-detail>{{ $update['error'] ?? '' }}@foreach($update['log'] ?? [] as $entry)
{{ $entry['time'] ?? '' }} {{ $entry['message'] ?? '' }}@endforeach</pre></details>
</section>
@endif
</div>
@endsection
