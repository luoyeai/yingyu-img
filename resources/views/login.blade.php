@extends('layout')
@section('title', '管理员登录')
@section('content')
<main class="login-page">
    <section class="login-visual" aria-label="映屿Img 图片工作室">
        <a class="brand" href="{{ route('login') }}"><span class="brand-mark"><svg class="icon" aria-hidden="true"><use href="#i-mark"/></svg></span>映屿Img</a>
        <div class="login-art" aria-hidden="true">
            <div class="art-sheet art-sheet-back"></div>
            <div class="art-sheet art-sheet-middle"></div>
            <div class="art-sheet art-sheet-front"><div class="art-landscape"></div><div class="art-caption"><span>YINGYU IMG</span><span>WEBP</span></div></div>
        </div>
        <div class="login-statement"><h2>收好每一张图片，<br>用好每一个链接。</h2><p>上传、整理、分享。<br>一个安静有序的图片工作室。</p></div>
    </section>
    <section class="login-form-side" aria-labelledby="login-title">
        <div class="login-panel">
            <a class="brand" href="{{ route('login') }}"><span class="brand-mark"><svg class="icon" aria-hidden="true"><use href="#i-mark"/></svg></span>映屿Img</a>
            <h1 id="login-title">管理员登录</h1><p class="muted">登录工作室，继续整理你的图片。</p>
            @if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ route('login') }}" class="stack-form">@csrf
                <label>邮箱<input name="email" type="email" autocomplete="username" required value="{{ old('email') }}" placeholder="请输入管理员邮箱" autofocus></label>
                <label>密码<input name="password" type="password" autocomplete="current-password" required placeholder="请输入密码"></label>
                <button class="button primary" type="submit">登录映屿Img</button>
            </form>
            <p class="caption">仅管理员可登录，不开放注册。</p>
        </div>
        <p class="login-footer">映屿Img · 单人图片工作室</p>
    </section>
</main>
@endsection
