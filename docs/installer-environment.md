# 安装与后台环境诊断

服务：`App\Services\EnvironmentDiagnostics`，文件 `app/Services/EnvironmentDiagnostics.php`。

```php
$diagnostics = new App\Services\EnvironmentDiagnostics($projectRoot);
$result = $diagnostics->run(true, true);
```

该类不使用 Laravel、helpers 或 vendor，不读取现有 `.env`，不读取或写入数据库，不执行 Composer 生成代码。可在 Laravel 启动失败前报告环境问题。它只操作随机生成的探测文件；后台调用同样不会持久修改配置或真实图片。

**全新状态的安装页自动运行诊断；已有站点仅向已登录管理员展示详细诊断。** 返回结果不含凭据，但全新安装需要在配置前展示目录、版本和限制；已有站点不向匿名请求开放详细检查。诊断自身不负责安装状态判断、安装锁或CSRF，调用方只在确认fresh或管理员登录后使用。

## 返回合同

```text
{
  checks: [
    { id: string, label: string, required: bool, ok: bool,
      detail: string, fix: string }
  ],
  ready: bool,
  checked_at: ISO8601 string
}
```

- `required=true` 的任何失败会令 `ready=false`，表示尚不满足安装必要条件。
- `required=false` 是建议或无法在该上下文判定的项，不阻断安装。
- `run(true, false)` 用于已安装后台：配置根目录写权限和 DOCUMENT_ROOT 仅作建议，真实存储与 WebP 必须通过。
- `run(false)` 只读取版本、扩展、依赖文件和 PHP 配置，不创建目录/文件。真实探测项返回未执行并失败，故**不能将此模式当成安装通过**。
- `checked_at` 使用当前 PHP 时区并带明确偏移；调用方按产品时区显示。

## 必要条件

| ID / 范围 | 实际检查 |
| --- | --- |
| `project-root` | 项目根目录存在 |
| `php-version` | 当前 PHP 至少 8.4.1；同时显示实际版本和 SAPI |
| `extension.*` | 只检查当前项目/已锁定依赖实际要求的精简扩展清单 |
| `filesystem-functions` | 文件探测、随机数、原子写入所需函数未被禁用 |
| `public-entry` | `public/index.php` 存在 |
| `composer-vendor` | `vendor/autoload.php` 存在，**不会加载** |
| `composer-platform` | 只读解析真实 `vendor/composer/platform_check.php` 中的 `PHP_VERSION_ID` 条件，与当前 PHP 比较 |
| `file-uploads` | 当前 SAPI 的 PHP `file_uploads` 已启用 |
| `directory.*` | 每个运行目录真实创建随机文件、写入随机字节、读取比对、同目录改名、再次读取、删除 |
| `env-atomic-write` | 在项目根目录执行相同随机探测，确认安装时可同目录原子生成配置；从不读取/修改 `.env` |
| `atomic.*` | `storage/app/tmp` 与每个目标目录 `stat.dev` 相同，并真实 rename、读取验证、删除 |
| `imagick-webp-roundtrip` | Imagick 生成 8×6 透明 WebP，真实写入临时文件、重新解码，验证单帧、格式、宽高及透明通道 |

扩展清单：`ctype`、`curl`、`xml`、`dom`、`exif`、`fileinfo`、`filter`、`hash`、`iconv`、`imagick`、`json`、`libxml`、`mbstring`、`openssl`、`pcre`、`PDO`、`pdo_mysql`、`session`、`tokenizer`。它不要求 GD、Redis、SQLite 或常驻队列。`exif` 是项目 Composer 明确要求；图片实际方向修正由 Imagick 执行。

Composer 平台条件支持生成文件常用的版本比较、括号、`&&` 和 `||`，不使用 `require`、`include` 或 `eval`。文件缺失、过大、不可读、无可识别条件或出现不支持语法时，返回失败和 `composer check-platform-reqs --no-dev` 修复建议。该检查只确认该生成文件中的 PHP 要求，并不替代 Composer 对所有系统库/第三方扩展版本的完整验收。

### 真实读写目录

```text
storage/framework/sessions
storage/framework/views
storage/framework/cache/data
storage/logs
bootstrap/cache
public/media
storage/app/tmp
storage/app/previews
storage/app/originals
storage/app/disabled
storage/app/installation
```

不存在的目录会按本站运行用户实际创建；公开目录 0755、私有新目录 0700，现有目录权限不改动。拒绝指向项目边界外的软链接，避免诊断误写其他项目或外部位置。

跨目录移动检查目标为 `public/media`、`storage/app/previews`、`storage/app/originals`、`storage/app/disabled`、`storage/app/installation`。先比较设备号，禁止把跨文件系统可能退化为复制的 `rename` 当成原子成功。

探测文件名为随机 `.vault-diagnostic-<32hex>.probe`（同目录改名后可能带 `.moved`），内容仅随机二进制，权限 0600。公开目录中的探测既不符合外链年月 WebP 路径，也不是可执行脚本后缀，不包含原图、配置或原文。每项均在 `finally` 清理；若无法清理，标为失败并提示修复删除权限。

## 建议项与上下文限制

| ID | 建议 / 限制 |
| --- | --- |
| `document-root` | Web 请求应指向项目 `public`；CLI 无法判断，明确显示需从真实站点复核 |
| `https` | 生产请求应 HTTPS；localhost/127.0.0.1/[::1] HTTP 可用于本地测试；CLI 不作生产结论 |
| `upload-max` | 当前 SAPI `upload_max_filesize`，默认单图 20MB，建议 21M |
| `post-max` | 当前 SAPI `post_max_size`，逐图上传建议 24M |
| `memory-limit` | 当前 SAPI `memory_limit`，建议 512M，保留像素数限制 |
| `execution-time` | 当前 SAPI `max_execution_time`，建议至少 120 秒或按运维策略设置 |

PHP CLI 与 PHP-FPM 可以加载不同 ini/扩展。CLI 成功不证明 Web 成功；应在实际安装页面再次检测。安装模式中可解析且明确错误的 `DOCUMENT_ROOT` 是必要失败；CLI 或无法确定时提示人工复核。运行模式只作建议。规范反代架构需保证其真实 public 边界正确。HTTPS 检查不盲信任来路不明的 `X-Forwarded-*` 请求头。

Nginx 请求体上限、PHP-FPM 超时、ImageMagick policy、磁盘容量、cron 最近运行、MySQL 连接和数据库权限不由此类全部判断。它不连接数据库，因此不伪造 cron/DB 通过；这些应由后续安装数据库步骤和后台运行状态单独验证。

## 宝塔修复提示

- PHP/扩展：为**本站 PHP 8.4** 安装或启用所列扩展，重启对应 PHP-FPM；确认 CLI 使用同版本。
- Composer：使用实际目标 PHP 执行 `composer install --no-dev --prefer-dist --optimize-autoloader` 和 `composer check-platform-reqs --no-dev`。不能通过 `--ignore-platform-reqs` 使诊断假通过。
- 运行目录：宝塔站点根目录指向完整项目，运行目录选择 `/public`；仅允许 `index.php` 执行。
- 权限：让本站 PHP 运行用户拥有必要目录的创建、写入、改名、删除权限；按照部署文档修复所有者，**不要 chmod 777**。
- 配置生成：安装阶段项目根目录需允许该用户原子写入；安装完成后可按运维策略收紧。`ready` 面向安装必要条件，收紧后后台须区分“配置重写权限”与“现有图片服务健康”。
- 原子移动：tmp 与公开/私有目标放在同一文件系统，避免跨盘挂载或外部软链接。
- Imagick：确认 ImageMagick 真正支持 WebP 读写，policy 允许必需 coder，目录权限正常，重启对应 FPM 后复测。
- 上传限制：PHP 按建议配置，Nginx `client_max_body_size` 至少与 POST 上限匹配；修改后从 Web 重新检测。

## 已有测试证据

`tests/Unit/EnvironmentDiagnosticsTest.php` 使用隔离临时项目目录，不连接数据库、不读取主 `.env`。覆盖真实 WebP 与目录/移动探测、不遗留文件、原配置哈希不变、只读模式、被文件阻塞目录、外部软链接拒绝、Composer 平台条件安全解析、不执行恶意 fixture、缺少依赖和入口、CLI 建议上下文，安装/运行模式切换与实际收紧根目录权限，以及 `php -n` 无 Laravel/vendor/Imagick 的独立进程运行。

运行：

```sh
bin/php-local vendor/bin/phpunit tests/Unit/EnvironmentDiagnosticsTest.php
```

2026-10-27 本地执行结果：**11 项测试，210 个断言通过**；主项目真实 CLI 诊断为 **50 项检查，ready=true**。Web PHP-FPM、宝塔运行目录和生产 HTTPS 尚需目标环境验证。

## 1.0.1 更新能力安装前检查

安装环境步骤新增逐项必需检查：PHP ZIP、更新器实际调用的进程/流/文件同步函数，以及同版本 PHP CLI。OpenSSL、pdo_mysql、Imagick 复用现有条目，避免重复。CLI 在五秒上限内只输出版本、SAPI、pdo_mysql/Imagick 是否加载，不启动 Laravel、不读站点配置、不连接数据库、不写业务数据。Web 和 CLI 须为相同补丁版本，扩展分别验证；仅启用 FPM 扩展不足以通过。

缺少 ZIP 时在宝塔当前 PHP 的安装扩展页启用；缺少函数时按失败条目在“禁用函数”只解除该函数，重载本站 PHP-FPM 后重检。OPcache 开启时检查 opcache_invalidate/opcache_reset，失效 API 实际验证受限目录是否允许调用。必要项失败会阻止新站继续安装；既有站点正常启动不增加这些硬门槛，后台环境诊断显示为建议，执行更新前仍以同一清单强制检查。

1.0.1 完整包用于未来新站；1.0.0 已安装站在系统更新上传 1.0.1 签名包。legacy 站保留原有 1.0.0 过渡包/签名包接入路径，再升 1.0.1，不另发 1.0.1 过渡包。数据库迁移清单未变化。
