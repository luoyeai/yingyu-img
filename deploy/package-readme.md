# 映屿Img宝塔安装包

当前完整安装包为 **1.1.4**，已包含锁定生产 vendor 和前端资源；新站安装无需 Composer 或 Node。已有正式 1.1.1、1.1.2 或 1.1.3 请在后台上传 `image-vault-1.1.4-signed-update.zip`，不要将完整包当作更新包。

1. 上传并解压 `image-vault` 到宝塔站点目录。站点 PHP 选择 **8.4.1 或更高的8.4补丁版本**，运行目录选择 **/public**。需要 MySQL8、Imagick/WebP、pdo_mysql 等扩展。
2. 在“网站 → 设置 → 伪静态”粘贴 `deploy/baota-rewrite.conf` 的全部内容并保存。保留宝塔默认 PHP8.4 配置，不要将 `nginx.conf.example` 整份 server 配置贴到伪静态。
3. 直接打开 `https://你的域名/install`。页面自动检测扩展、目录权限和 WebP，按页面填写独立 MySQL8 空库、站点及管理员后确认安装。**不需要生成、读取或输入安装密钥，不需要先检查目录权限或复制 .env。**

所有必要检测通过即可继续。若提示某项失败，仅按该项说明在宝塔修复，例如启用扩展、将失败目录所有者设为本站 PHP 用户；不要 chmod 777。内部随机状态由程序自动生成，安装完成后自动锁定入口；已有站点和非空数据库会阻止重装。

## 系统更新（1.0.0）

全新安装使用本完整包。既有版本首次接入须先备份，再覆盖专用 legacy-transition 包；保留 .env、storage 和 public/media，不重新安装。随后登录后台“系统更新”上传 legacy-signed-update 包，核对后确认。该签名包只从明确 legacy 基线登记至1.0.0，1.0.0不能重复安装。更新中保持当前浏览器的恢复授权 Cookie；失败时从 /update-recovery 恢复。服务器须支持 ZIP、OpenSSL、proc_open、同版本PHP CLI和程序原子替换权限。详细边界及包用途见 docs/deployment.md 第8节。

## 已上传旧版时的样式修复

assetfix 版的安装、预检和后台 CSS/JS 地址均带实际文件内容的 SHA256 版本参数，资源变更后会使用新地址。覆盖更新需同时上传模板和完整 `public/assets` 资源，不要只更新 HTML/PHP。最小补丁按项目根目录的 `bootstrap/`、`resources/`、`public/` 同名路径覆盖，不要解压到 `public` 内，也不要删除原项目或重新安装。补丁不包含 `.env`、storage、数据库或依赖。

如果使用 CDN，且其缓存键忽略查询参数，需清理相应 CSS/JS 的 CDN 缓存，或让缓存键包含 `v` 参数。PHP 模板若受 OPcache 不检查时间戳的配置影响，覆盖后在宝塔重载本站 PHP。服务器资源未被完整覆盖时，版本参数无法替代缺失的新文件。

## 伪静态内容（可直接复制）

```nginx
# 粘贴到宝塔：网站 -> 设置 -> 伪静态（替换原伪静态，不要重复追加）。
# 前提：运行目录 /public；宝塔 PHP 版本选择 8.4，保留默认 enable-php-84.conf。
# server 级 return 防护先于任何 PHP/静态正则 location 生效，无需调整 include 顺序。
if ($uri ~* "^/(?:storage|originals|previews|disabled|vendor|app|bootstrap|config|database|resources|routes|tests|deploy|docs|bin)(?:/|$)") {
    return 404;
}
if ($uri ~ "(?:^|/)\.(?!well-known(?:/|$))") {
    return 404;
}
# 只允许 /index.php 进入宝塔现有 PHP handler，阻止其他脚本及 PATH_INFO。
if ($uri ~ "^/(?!index\.php$).*(?i:\.(?:php[0-9]*|phtml|phar))(?:[./]|$)") {
    return 404;
}
# 拒绝所有非规范 media 路径（包括大小写变形），避免被默认静态缓存正则接管。
if ($uri ~ "^(?!/media/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{32}\.webp$)(?i:/media)(?:/|$)") {
    return 404;
}
location ^~ /media/ {
    try_files $uri =404;
    open_file_cache off;
    types { image/webp webp; }
    default_type image/webp;
    expires off;
    add_header Cache-Control "public, max-age=0, must-revalidate" always;
    add_header X-Content-Type-Options nosniff always;
    etag on;
}
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

该片段适用于宝塔默认 PHP8.4 include 已启用、运行目录 /public 的现有站点。它不包含证书、域名、日志、PHP socket 或 server 块，无需替换这些配置。服务器级 return 防护可覆盖先出现的 PHP/静态正则；media 使用 ^~ 前缀，默认图片缓存正则不会接管，停用时物理移走文件后源站返回404。

如果当前自定义配置已经定义 `location /` 或 `location ^~ /media/`，请在宝塔“配置文件”中移除对应重复 location，再保存该片段。不要删除宝塔默认 PHP include。运行目录 /public 和正确 PHP handler 是安全边界，单独 try_files 不能替代。片段不更改服务器上传体积限制；需要上传较大文件时按检测页提醒调整宝塔 PHP/Nginx 限制。生产建议使用 HTTPS，第三方已缓存/保存的副本无法由源站撤回。

后续使用见 `docs/deployment.md`，备份见 `docs/backup-restore.md`。遇安装中断保留私有恢复日志，不要清库或删除锁重装。ZIP 内 `BUILD.json` 记录生产依赖，`FILES.sha256` 可核对解压完整性；ZIP 外 `SHA256SUMS` 校验下载文件。

## 1.0.1 安装检测

安装环境步骤现会逐项核查 ZIP、更新需要的进程/文件函数、同版本 PHP CLI 及其 pdo_mysql/Imagick。缺失时按页面提示在宝塔安装扩展或仅解除对应禁用函数，重载本站 PHP 后重新检测；不会自动修改服务器配置。已有1.0.0请在后台上传1.0.1签名更新包；旧无版本站先用原1.0.0接入流程。


## 1.0.2 同页更新

本完整包用于新站安装。已有 1.0.0 或 1.0.1 请上传 `image-vault-1.0.2-signed-update.zip`；旧无版本站须先完成原 1.0.0 接入。数据库结构不变。

1.0.2 安装后的更新在系统更新页完成确认、进度与结果，失败时可同页恢复，详细记录默认折叠。独立恢复入口保留用于故障兜底。升级到 1.0.2 的这一次仍按原版本界面完成，新体验在安装后生效。


## 1.1.0 多存储

本完整包用于新站；已有正式 1.0.0–1.0.2 请在后台上传 `image-vault-1.1.0-signed-update.zip`。旧无版本站仍先完成原 1.0.0 接入，不重新安装、不重建 APP_KEY。升级前独立备份数据库、原 `.env` 与全部媒体。

支持本地、腾讯 COS、阿里 OSS、通用 S3；默认目标切换仅影响新上传，每批可另选。历史图片仅登记本地归属，不搬迁文件，原 `/media/` 链接保持。云端只存公开 WebP，私有预览、可选原图与停用完整副本仍在本站。仅支持未启用过版本控制、无对象锁/保留策略的普通桶；需对象读写复制删除及查询桶版本/对象锁或 OSS WORM 状态的最小权限。无自动容灾、历史迁移或跨桶同步。

Web 与同版本 PHP CLI 均需 cURL、SimpleXML、`dns_get_record` 等安装页能力。先用配置中的随机路径测试，再验证匿名公开访问。云图停用会删除源站对象，但 CDN/浏览器副本需另行清缓存；回收站到期再由计划任务清除。未知结果保留待办与安全副本，通过设置或计划任务重试，不要手工删除。

密钥使用 APP_KEY 加密且不回显；数据库、原 APP_KEY（包括轮换需要的旧密钥）和私有媒体必须一起灾备。详见 `docs/deployment.md` 第8节。发布包与隔离安装/升级已验收；真实云桶、CDN 和目标宝塔环境尚未实测，首次启用须在测试桶验证。

升级前须在宝塔分别核对 Web 与实际同版本 PHP CLI 的 cURL、SimpleXML 和 `dns_get_record`。**1.0.0–1.0.2 的旧冻结更新器不会完整自动检测这些新增云存储能力**；签名清单只声明 PHP 最低版本，不能代替上述人工能力核对。缺少能力时先修复再上传更新包；不得通过放宽旧协议或跳过检测来安装。

## 1.1.1 成功更新任务清理

仅兼容正式 1.1.0；八项数据库迁移及依赖保持不变。新版更新器在文件、迁移和健康检查通过、版本与完成状态可靠保存后，自动删除该成功任务的 payload、程序备份及数据库备份，仅保留轻量记录。失败、运行中、迁移中断或恢复中任务不清理，历史无成功凭据目录也不自动删除。

本次 1.1.0 → 1.1.1 仍由原冻结更新器执行，成功响应时暂存及备份仍会保留；安装后使用本站同版本 PHP 在项目根目录执行 `php artisan vault:cleanup --updates-only --no-interaction`，仅重新核验当前这一个成功过渡任务，核验通过后清理，失败则保留并记录重试。此选项不清回收站、不重试云操作，也可由管理员自行配置为维护计划；发布包不自动配置计划任务。由 1.1.1 发起的后续升级在成功落盘后立即清理。正常页面不会触发删除。未加 `--updates-only` 的原维护命令还会永久清理到期回收站并重试云操作，应按原计划使用。

升级前独立灾备，并确认宝塔防篡改允许本站 PHP 修改程序文件和执行 chmod、rename、删除。任务备份不再长期保留，不能代替站点灾备。详见 `docs/deployment.md`。
