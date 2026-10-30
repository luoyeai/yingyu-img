@extends('layout')
@section('title','环境检测')
@section('content')
<header class="page-heading heading-with-action"><div><h1>环境检测</h1><p>按当前网站 PHP 进程实际检测。仅使用临时探测文件，不修改配置、图片或数据库。</p></div><a class="button" href="{{ route('settings') }}">返回设置</a></header>
<section class="panel settings-section"><h2>{{ $diagnostics['ready'] ? '必需运行检查通过' : '有必需运行检查未通过' }}</h2><p class="caption">检测时间：{{ $diagnostics['checked_at'] }}。配置目录只在安装时要求可写，运行阶段可收紧；建议告警不会阻断站点。</p><form method="post" action="{{ route('settings.diagnostics') }}">@csrf<button class="button primary" type="submit">重新检测</button></form><dl class="status-list">
@foreach($diagnostics['checks'] as $check)
<div><dt>{{ $check['label'] }} <span class="caption">{{ $check['required'] ? '必需' : '建议' }}</span><p class="caption">{{ $check['detail'] }}</p>@if(!$check['ok'])<p class="caption">{{ $check['fix'] }}</p>@endif</dt><dd class="{{ $check['ok'] ? 'text-success' : ($check['required'] ? 'text-danger' : '') }}">{{ $check['ok'] ? '通过' : ($check['required'] ? '未通过' : '提示') }}</dd></div>
@endforeach
</dl></section>
@endsection
