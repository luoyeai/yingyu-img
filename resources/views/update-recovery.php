<?php
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$assetHash = fn (string $path): string => is_file($this->root.'/public/'.$path) ? substr(hash_file('sha256', $this->root.'/public/'.$path), 0, 16) : 'missing';
$finished = in_array($update['status'] ?? '', ['complete', 'restored', 'cancelled'], true);
$failed = ($update['status'] ?? '') === 'failed';
$versionLabel = static fn (mixed $version): string => (string) $version === '0.0.0' ? '旧版' : (string) $version;
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>系统更新 · 映屿Img</title><link rel="stylesheet" href="/assets/app.css?v=<?= $escape($assetHash('assets/app.css')) ?>"><script src="/assets/update.js?v=<?= $escape($assetHash('assets/update.js')) ?>" defer></script><script src="/assets/app.js?v=<?= $escape($assetHash('assets/app.js')) ?>" defer></script></head><body><svg class="icon-defs" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
<symbol id="i-upload" viewBox="0 0 24 24"><path d="M8 17H6a4 4 0 0 1-.8-7.9A7 7 0 0 1 19 9a4 4 0 0 1-1 8h-2M12 21V11m-4 4 4-4 4 4"/></symbol>
<symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-5 5 6"/></symbol>
<symbol id="i-trash" viewBox="0 0 24 24"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></symbol>
<symbol id="i-settings" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="18" r="2"/></symbol>
<symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></symbol>
<symbol id="i-mark" viewBox="0 0 24 24"><path d="m3 18 6-9 4 5 3-4 5 8H3Z"/><path d="m9 9 2-3 3 5"/></symbol>
</defs></svg><div class="app-shell">
<aside class="sidebar" id="navigation"><a class="brand" href="/"><span class="brand-mark"><svg class="icon"><use href="#i-mark"/></svg></span>映屿Img</a><nav aria-label="主导航"><a class="nav-item" href="/"><svg class="icon"><use href="#i-upload"/></svg>上传图片</a><a class="nav-item" href="/library"><svg class="icon"><use href="#i-image"/></svg>图片库</a><a class="nav-item" href="/trash"><svg class="icon"><use href="#i-trash"/></svg>回收站</a><a class="nav-item" href="/settings"><svg class="icon"><use href="#i-settings"/></svg>设置与状态</a><a class="nav-item active" href="/updates" aria-current="page"><svg class="icon"><use href="#i-settings"/></svg>系统更新</a></nav><div class="sidebar-footer"><span class="muted">单人图片工作室</span><small>映屿Img · 本地存储</small></div></aside>
<div class="mobile-header"><a class="brand" href="/"><svg class="icon"><use href="#i-mark"/></svg>映屿Img</a><button class="icon-button" type="button" aria-controls="navigation" aria-expanded="false" data-nav-toggle aria-label="展开导航">☰</button></div>
<main class="workspace"><header class="page-heading"><h1>系统更新</h1><p>更新期间后台暂时不可用，请保留此页。</p></header><div class="update-layout"><section class="panel settings-section" data-update-screen data-finished="<?= $finished ? 'true' : 'false' ?>">
<h2><?= $escape($versionLabel($update['from'] ?? '')) ?> → <?= $escape($versionLabel($update['to'] ?? '')) ?></h2>
<p role="status" aria-live="polite" data-update-status><?= $finished ? (($update['status'] ?? '') === 'complete' ? '更新完成。' : '已恢复更新前版本。') : ($failed ? '更新未完成，请恢复后重试。' : '正在读取进度…') ?></p>
<div class="update-actions"><button class="button" type="button" data-update-retry hidden>重新检查</button><a class="button primary" href="/login" data-update-done <?= $finished ? '' : 'hidden' ?>>返回后台</a><a class="button" href="/update-recovery" data-update-fallback hidden>继续处理</a><?php if (! $confirmed && ! $failed && ! $finished) { ?><a class="button" href="/updates">返回确认更新</a><?php } ?></div>
<form method="post" action="/update-recovery" class="stack-form" data-update-restore <?= $failed ? '' : 'hidden' ?>><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="restore"><label class="checkbox-label"><input type="checkbox" name="confirmation" value="restore" required> 恢复更新前版本</label><button class="button destructive" type="submit">确认恢复</button></form>
<noscript><?php if ($confirmed && ! $failed && ! $finished) { ?><form method="post" action="/update-recovery"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="stage" value="<?= $escape($update['stage'] ?? '') ?>"><input type="hidden" name="action" value="advance"><button class="button" type="submit">继续更新</button></form><?php } ?></noscript>
<details class="update-details"><summary>查看详情</summary><pre data-update-detail><?= $escape($error ?? $update['error'] ?? '') ?><?php foreach ($update['log'] ?? [] as $entry) {
    echo "\n".$escape(($entry['time'] ?? '').' '.($entry['message'] ?? ''));
} ?></pre></details>
</section></div></main></div></body></html>
