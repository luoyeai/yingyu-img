<div class="storage-layout">
<section class="panel settings-section storage-settings">
<div class="storage-default">
<div class="storage-default-copy"><h2>图片存储</h2><p class="caption">默认目标仅用于新上传，历史图片与外链不变。</p></div>
<form method="post" action="{{ route('storage.default') }}" class="storage-default-form">@csrf<input type="hidden" name="_settings_section" value="storage"> @method('PUT')<label>默认上传目标<select name="storage_configuration_id">@foreach($storages->where('enabled',true) as $storage)<option value="{{ $storage->id }}" @selected($defaultStorageId==$storage->id)>{{ $storage->name }} · {{ strtoupper($storage->driver) }}</option>@endforeach</select></label><button class="button" type="submit">设为默认</button></form>
</div>

@if($pendingOperations)<div class="settings-pending" id="storage-pending"><h3>待处理操作</h3><form method="post" action="{{ route('storage.retry') }}" class="inline-form">@csrf<input type="hidden" name="_settings_section" value="storage"><p>{{ $pendingOperations }} 项操作未完成，相关图片暂时锁定。</p><button class="button" type="submit">立即重试存储操作</button></form></div>@endif
<h3 class="settings-list-heading">存储配置 <span class="caption">{{ $storages->count() }} 项</span></h3>
<div class="settings-storage-list">
@foreach($storages as $storage)
<details class="settings-details storage-configuration" id="storage-{{ $storage->id }}" @if($errors->any() && $errorForm === 'storage-'.$storage->id) open @endif>
<summary><span class="storage-configuration-name">{{ $storage->name }}<span class="storage-driver">{{ strtoupper($storage->driver) }}</span></span><span class="storage-configuration-state">@if($defaultStorageId==$storage->id)<span class="storage-badge">默认</span>@endif @if(!$storage->enabled)<span class="storage-badge">已停用新上传</span>@endif<span class="caption">{{ $storage->images_count }} 张</span></span></summary>
<div class="storage-configuration-body">
@php($usage=$storageStats->get($storage->id))
<dl class="storage-usage"><div><dt>WebP</dt><dd>{{ $formatBytes($usage?->webp_bytes ?? 0) }}</dd></div><div><dt>预览与原图</dt><dd>{{ $formatBytes($usage?->private_bytes ?? 0) }}</dd></div></dl>
@if($storage->driver!=='local')<p class="caption">{{ $storage->bucket }} · {{ $storage->region }} · {{ $storage->prefix ?: '无前缀' }}<br>{{ $storage->public_url }}<br>以上信息不可修改；变更请创建新配置，保留历史链接。</p>@endif
<form method="post" action="{{ route('storage.update',$storage) }}" class="stack-form">@csrf<input type="hidden" name="_settings_section" value="storage"> @method('PUT')<input type="hidden" name="_settings_form" value="storage-{{ $storage->id }}"><div class="form-columns"><label>名称<input name="name" required maxlength="100" value="{{ $errorForm === 'storage-'.$storage->id ? $settingsOldValue('name', $storage->name) : $storage->name }}"></label><label>新上传<select name="enabled"><option value="1" @selected($errorForm === 'storage-'.$storage->id ? old('enabled', $storage->enabled) : $storage->enabled)>允许</option><option value="0" @selected(!($errorForm === 'storage-'.$storage->id ? old('enabled', $storage->enabled) : $storage->enabled))>停用</option></select></label></div>
@if($storage->driver!=='local')<div class="form-columns"><label>轮换 Access Key<input type="password" name="access_key" autocomplete="new-password" maxlength="255" placeholder="留空保留现有密钥"></label><label>轮换 Secret Key<input type="password" name="secret_key" autocomplete="new-password" maxlength="255" placeholder="留空保留现有密钥"></label></div>@endif<div class="storage-form-actions"><button type="submit" class="button small">保存配置</button></div></form>
@if($storage->driver!=='local')<div class="inline-form"><form method="post" action="{{ route('storage.test',$storage) }}">@csrf<input type="hidden" name="_settings_section" value="storage"><button class="button small" type="submit">测试读写与公开访问</button></form><form method="post" action="{{ route('storage.destroy',$storage) }}" data-confirm="删除未被引用的存储配置？不会删除桶。">@csrf<input type="hidden" name="_settings_section" value="storage"> @method('DELETE')<button class="text-button danger" type="submit">删除配置</button></form></div>@endif
</div>
</details>
@endforeach
</div>
<details class="settings-details storage-notes"><summary>云端文件与备份</summary><p class="caption">云端保存 WebP，预览与原图保存在本站。停用云图与待处理操作可能额外占用本站空间。</p><p class="caption">停用云图会在本站保留完整副本，恢复时重新上传。云端回收站保留外链，30 天后由计划任务删除；缓存须另行清理。</p><p class="caption">备份须包含数据库、原 APP_KEY 和私有文件；更换密钥前须保留必要旧密钥。新配置不搬迁历史图片。</p></details>
</section>
<section class="panel settings-section storage-create-panel" id="storage-create"><h2>添加云存储</h2><form method="post" action="{{ route('storage.store') }}" class="stack-form">@csrf<input type="hidden" name="_settings_section" value="storage"><input type="hidden" name="_settings_form" value="storage-create">
<div class="form-columns"><label>名称<input name="name" value="{{ $errorForm === 'storage-create' ? $settingsOldValue('name') : '' }}" required maxlength="100" placeholder="例如：网站图片 COS"></label><label>类型<select name="driver"><option value="cos" @selected($errorForm !== 'storage-create' || old('driver', 'cos') === 'cos')>腾讯云 COS</option><option value="oss" @selected($errorForm === 'storage-create' && old('driver') === 'oss')>阿里云 OSS</option><option value="s3" @selected($errorForm === 'storage-create' && old('driver') === 's3')>通用 S3</option></select></label></div>
<div class="form-columns"><label>存储桶<input name="bucket" value="{{ $errorForm === 'storage-create' ? $settingsOldValue('bucket') : '' }}" required maxlength="100" placeholder="COS 需包含 -APPID"></label><label>地域<input name="region" value="{{ $errorForm === 'storage-create' ? $settingsOldValue('region') : '' }}" required maxlength="80" placeholder="COS: ap-guangzhou / OSS: cn-hangzhou"></label></div>
<label>公开域名<input name="public_url" value="{{ $errorForm === 'storage-create' ? $settingsOldValue('public_url') : '' }}" type="url" required maxlength="255" placeholder="https://cdn.example.com"><span class="caption">填写桶公开域名或 CDN 根域名，不含对象前缀。</span></label>
<div class="form-columns"><label>Access Key / SecretId<input type="password" name="access_key" required autocomplete="new-password" maxlength="255"></label><label>Secret Key<input type="password" name="secret_key" required autocomplete="new-password" maxlength="255"></label></div>
<details class="settings-details storage-advanced" data-storage-options><summary>路径与 S3 选项</summary>
<div class="form-columns"><label>对象前缀<input name="prefix" value="{{ $errorForm === 'storage-create' ? $settingsOldValue('prefix') : '' }}" maxlength="180" placeholder="例如 images（可留空）"></label><label>S3 Endpoint<input name="endpoint" value="{{ $errorForm === 'storage-create' ? $settingsOldValue('endpoint') : '' }}" type="url" maxlength="255" placeholder="https://s3.example.com"><span class="caption">仅 S3 需要，填写公网 HTTPS 地址。</span></label></div>
<label class="checkbox-label"><input name="path_style" type="checkbox" value="1" @checked($errorForm === 'storage-create' && old('path_style'))>S3 使用路径寻址</label></details>
<label class="checkbox-label"><input name="bucket_confirmed" type="checkbox" value="1" @checked($errorForm === 'storage-create' && old('bucket_confirmed')) required>确认该桶未启用过版本控制、对象锁或保留策略</label><p class="caption">需要文件读写、删除及存储桶安全检查权限。添加后请测试连接。</p>
<div class="storage-form-actions"><button class="button primary" type="submit">添加配置</button></div></form></section>
</div>
