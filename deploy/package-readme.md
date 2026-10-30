# 映屿 Img 1.0.0

作者：[Luoye](https://luoyeai.cn) · MIT 许可证。

完整安装包已包含生产依赖和前端资源，无需 Composer 或 Node。

## 安装

1. 准备 PHP **8.4.1+**、MySQL **8.0+**、Nginx 和支持 WebP 的 Imagick，PHP CLI 与网站使用一致的版本和扩展。
2. 将 `image-vault/` 中的文件解压到站点目录，网站运行目录设置为 **`public/`**，启用 HTTPS。
3. 宝塔用户将 `deploy/baota-rewrite.conf` 粘贴到网站伪静态设置，保留宝塔默认 PHP 配置。其他 Nginx 环境参考 `deploy/nginx.conf.example`。
4. 打开 `https://你的域名/install`，按向导检查环境，填写专用 MySQL 空库、站点信息和管理员账号。

使用网页安装时，不要预先复制 `.env`、生成 APP_KEY 或执行数据库迁移。权限和扩展问题按安装页提示修复，不要设置 `777` 权限。

本包用于全新安装，不用于覆盖已有站点或降级历史版本。安装完成后入口自动锁定。

后续使用见 `docs/deployment.md`，备份见 `docs/backup-restore.md`。`FILES.sha256` 用于核对解压文件，包外 `SHA256SUMS` 用于校验下载文件。
