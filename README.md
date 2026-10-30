# 映屿 Img · Yingyu Img

面向个人的中文 WebP 图床，提供图片上传、整理、外链和多存储管理。

当前功能基线为 **1.1.4**。本仓库提供源码，不包含生产依赖、运行配置、图片、数据库、签名私钥或历史发行包。源码整理不代表生产站已升级。

## 功能

- 单管理员、禁止公开注册，支持登录限流、CSRF 防护与密码修改。
- 拖拽、粘贴、多图和文件夹上传，支持递归子目录、来源路径记录、CSV / JSON 导出。
- 超过每批上限时自动分批排队，单并发逐张处理，失败可重试；默认每批 50 张、单图 20 MB、4000 万像素。
- JPEG、PNG、静态 WebP 转公开 WebP，支持质量、无损、最长边、透明与 EXIF 方向修正；拒绝 GIF、SVG、APNG 和动态 WebP。
- 本地、S3 兼容 / 腾讯云 COS、阿里云 OSS 存储配置；不同对象存储的权限、区域和自定义域名须按服务商要求配置。
- 图片分组、检索、名称 / alt 编辑、查重，支持直链、Markdown、HTML 复制与处理前后局部对比。
- 私有原图可选保留，回收站默认保留 30 天，支持停用、恢复与永久删除。
- 网页安装向导、环境诊断、只读文件审计、签名更新与受保护恢复入口。

前端使用 Blade、原生 JavaScript 和 CSS，无 Node 构建和外部 CDN；同步处理，不需要 Redis、Docker 或常驻队列。

## 运行环境

- PHP **8.4.1+**，Laravel **13.38.0**（版本由 `composer.lock` 锁定）。
- MySQL **8.0+**、Nginx，PHP CLI 与 FPM 使用一致的版本和扩展。
- Imagick / ImageMagick 具备 WebP 编解码能力。
- PHP 扩展：`ctype`、`curl`、`dom` / `xml`、`exif`、`fileinfo`、`filter`、`hash`、`iconv`、`imagick`、`json`、`mbstring`、`openssl`、`PDO`、`pdo_mysql`、`session`、`tokenizer`；依赖安装和更新解压还需 `zip` / `unzip`。
- 从源码安装需要 Composer 2。

## 从源码安装

以下步骤只适用于新站和专用空数据库。已有站点应先备份并使用兼容的更新流程。

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
```

将 Nginx 网站根目录指向项目的 **`public/`**，参考 [Nginx 配置](deploy/nginx.conf.example) 或 [宝塔伪静态](deploy/baota-rewrite.conf)，启用 HTTPS。确认 PHP 运行用户具备本站安装所需写权限后，访问 `https://你的域名/install`，由向导检查环境并配置专用 MySQL 空库、站点和管理员。

使用网页安装时，**不要预先复制 `.env`、生成 APP_KEY 或迁移数据库**；已有配置、非空数据库或安装中断状态会阻止重装。不要运行 `composer run setup` 后再使用网页安装。

CLI 手动安装可参考 `.env.example` 和 [部署说明](docs/deployment.md)。不要执行 `storage:link`，私有原图、预览、安装状态与更新备份不能公开访问。

```sh
# 建议每小时通过 cron 执行
php artisan vault:cleanup
# 只读检查记录、文件体积与孤立文件
php artisan vault:audit
```

## 目录

| 路径 | 用途 |
| --- | --- |
| `app/` | 图片、存储、安装和更新服务 |
| `resources/views/` | Blade 与独立安装 / 恢复页面 |
| `public/assets/` | CSS 与原生 JavaScript |
| `database/` | 数据库迁移、工厂和 Seeder |
| `tests/` | PHPUnit 与前端逻辑测试 |
| `deploy/` | Nginx 模板和安装包说明 |
| `bin/build-release.py` | 构建包含锁定生产依赖的完整安装包 |
| `bin/build-update.py` | 使用维护者私钥构建签名更新包 |
| `bin/build-source.py` | 按明确文件清单构建无运行数据的源码包 |

`bootstrap/update-public.pem` 是签名校验公钥，可公开；私钥不随源码提供。自行维护的分支应建立自己的签名信任链，不应绕过签名验证。不要给现有站点直接替换信任公钥。

## 源码导出

```sh
python3 bin/build-source.py --output release/yingyu-img-source.zip
```

导出采用源码白名单，拒绝软链接与已识别的密钥内容，输出已存在时拒绝覆盖。历史发行 ZIP、`.tools/`、真实 `.env*`、日志、运行缓存、图片、安装恢复数据和内部验收记录不会进入源码包。Git 忽略规则同时保留空目录所需的 `.gitignore`。

正式安装包和签名更新包须单独构建验证；已经交付的发行包不覆盖，后续发行使用新版本号。GitHub 自动生成的源码 ZIP 不包含 `vendor/`，不能替代包含依赖的完整安装包。

## 运维参考

- [部署与升级](docs/deployment.md)
- [备份与恢复](docs/backup-restore.md)
- [安装和环境检查](docs/installer-environment.md)

数据库与文件系统不构成整体事务。变更或恢复前应停写并制作数据库、配置和媒体的一致性备份。宝塔防篡改拦截 `chmod` / `rename` 时，应按实际错误和目录状态排查，不能据此认定目录已删除。

本项目代码采用 MIT 许可证，版权归 Luoye 所有。Laravel、AWS SDK、OSS SDK 等第三方依赖保留各自作者与许可证声明。
