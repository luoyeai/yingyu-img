@php(require_once base_path('bootstrap/preflight.php'))
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', '映屿Img') · 映屿Img</title>
<link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ vault_asset_version(base_path(), 'assets/app.css') }}">@if(request()->routeIs('upload'))<script src="{{ asset('assets/upload.js') }}?v={{ vault_asset_version(base_path(), 'assets/upload.js') }}" defer></script>@endif<script src="{{ asset('assets/app.js') }}?v={{ vault_asset_version(base_path(), 'assets/app.js') }}" defer></script>
</head>
<body>
<svg class="icon-defs" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
<symbol id="i-upload" viewBox="0 0 24 24"><path d="M8 17H6a4 4 0 0 1-.8-7.9A7 7 0 0 1 19 9a4 4 0 0 1-1 8h-2M12 21V11m-4 4 4-4 4 4"/></symbol>
<symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-5 5 6"/></symbol>
<symbol id="i-trash" viewBox="0 0 24 24"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></symbol>
<symbol id="i-settings" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="18" r="2"/></symbol>
<symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></symbol>
<symbol id="i-mark" viewBox="0 0 24 24"><path d="m3 18 6-9 4 5 3-4 5 8H3Z"/><path d="m9 9 2-3 3 5"/></symbol>
</defs></svg>
@auth
<div class="app-shell">
<aside class="sidebar" id="navigation"><a class="brand" href="{{ route('upload') }}"><span class="brand-mark"><svg class="icon"><use href="#i-mark"/></svg></span>映屿Img</a>
<nav aria-label="主导航">
@foreach([['upload','upload','上传图片'],['library','image','图片库'],['trash','trash','回收站'],['settings','settings','设置与状态'],['updates','settings','系统更新']] as [$route,$icon,$label])
<a href="{{ route($route) }}" class="nav-item {{ request()->routeIs($route) ? 'active' : '' }}" @if(request()->routeIs($route)) aria-current="page" @endif><svg class="icon"><use href="#i-{{ $icon }}"/></svg>{{ $label }}</a>
@endforeach
</nav><div class="sidebar-footer"><span class="muted">单人图片工作室</span><form method="post" action="{{ route('logout') }}">@csrf<button class="text-button" type="submit">退出登录</button></form><small>映屿Img · 本地存储</small></div></aside>
<div class="mobile-header"><a class="brand" href="{{ route('upload') }}"><svg class="icon"><use href="#i-mark"/></svg>映屿Img</a><button class="icon-button" type="button" aria-controls="navigation" aria-expanded="false" data-nav-toggle aria-label="展开导航"><svg class="icon"><use href="#i-menu"/></svg></button></div>
<main class="workspace">
@if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert"><strong>操作未完成</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</main></div>
@else
@yield('content')
@endauth
<div class="toast" role="status" aria-live="polite" id="toast" hidden></div>
<dialog id="compare-dialog" class="wide-dialog"><div class="dialog-heading"><h2>处理前后对比</h2><button type="button" class="icon-button" data-close-dialog aria-label="关闭对比">×</button></div><p class="muted">原始比例显示，可分别滚动检查局部；拖动滑块同步放大。</p><label class="zoom-control">缩放 <input type="range" id="compare-zoom" min="25" max="200" value="100" step="25"><output id="compare-scale">100%</output></label><div class="comparison"><section><h3>原图 <span id="compare-before-meta"></span></h3><div class="comparison-viewport"><img id="compare-before" alt="上传的本地原图"></div></section><section><h3>WebP <span id="compare-after-meta"></span></h3><div class="comparison-viewport"><img id="compare-after" alt="处理后的 WebP"></div></section></div></dialog>
</body></html>
