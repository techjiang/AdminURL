# 🔗 AdminURL

> 同一个世界，同一个互联网！

**AdminURL** 是一个面向管理员的开源短链接系统：私密、安全、好看。默认只有登录的管理员可以使用，需要对外开放时一键开启。基于 PHP + SQLite 构建，零外部依赖，部署即是上线。

- **作者**：[科技酱](https://www.asoe.cn)
- **文档**：https://docs.asoe.cn
- **GitHub**：https://github.com/techjiang/

---

## ✨ 功能特性

| 功能 | 说明 |
| --- | --- |
| 短链接生成 | 长链接一键转为短链接，支持自定义别名（3-32 位字母数字） |
| 链接管理 | 列表、搜索、分页、编辑、启用/停用、删除，统计一目了然 |
| 延迟计数 | 点击量先写入缓冲表，按阈值 / 间隔批量合并，高并发下不卡库 |
| 自动获取标题 | 添加链接时自动抓取页面标题，也可留空保存时再抓（内置防 SSRF） |
| UA 屏蔽 | 自定义屏蔽 User-Agent，命中即返回 403 且不计数，支持通配符 |
| 数据迁移 | 一键导入 YOURLS 数据（CSV / 直接连 MySQL），防重复、回填点击量 |
| 完整 API | REST 接口 + 令牌鉴权 + 限流，方便二次开发与集成 |
| 私密可控 | 默认仅管理员可用；「开放使用」「开放注册」均为独立开关，默认关闭 |
| 安全加固 | Argon2id 密码哈希、CSRF 防护、登录限速锁定、会话硬化、定时令牌吊销 |

## 🧱 技术栈

- PHP >= 8.0（无需 Composer、无需任何第三方包）
- SQLite 3（PDO）
- 原生 HTML + CSS + JavaScript（无前端构建链）

## 🚀 快速开始

> 生产环境（Apache / Nginx 伪静态、安全加固）请见 **[部署指南](docs/DEPLOYMENT.md)**。

### 环境要求

安装前请确认 PHP 已启用以下扩展：

- `pdo` / `pdo_sqlite` / `sqlite3`（数据库）
- `curl`（自动获取标题）
- `openssl`（安全 / Argon2id 可用时优先使用）
- `mbstring`（中文处理）

### 安装步骤

1. **下载代码**，将 `AdminURL` 目录放到 Web 根目录（如 Apache 的 `htdocs`、Nginx 站点根目录）。
2. **准备目录**：确认 `data/`、`config/` 目录存在且 PHP 进程可写。
3. **访问安装向导**：浏览器打开 `https://你的域名/install/`，按提示填写站点名称、站点地址（不带结尾斜杠）、管理员账号密码。
4. **安装完成**：系统自动生成 `config/config.php` 并写入随机密钥，初始化数据库。点击「进入管理后台」即可登录使用。
5. **安全提示**：正式上线前**删除 `install` 目录**，防止未授权重装。

### 演示（本地开发）

没有 Web 服务器时，可用 PHP 内置服务器：

```bash
php -S 0.0.0.0:8000 router.php
```

然后访问 `http://127.0.0.1:8000/install/` 完成安装。

## ⚙️ 配置说明

安装完成后，所有配置都在 `config/config.php`（由安装向导生成，也可手动编辑）：

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `base_url` | - | 站点地址，**不得带结尾斜杠** |
| `db_file` | `data/adminurl.db` | SQLite 数据库路径 |
| `secret_key` | 安装时随机生成 | 会话 / CSRF 签名密钥，请勿泄露 |
| `timezone` | `Asia/Shanghai` | 时区 |
| `code_length` | `5` | 随机短码长度（3-12） |
| `click_flush_threshold` | `100` | 点击缓冲累计条数，达到即合并入主计数 |
| `click_flush_interval` | `60` | 距上次合并超过该秒数也触发合并 |
| `fetch_timeout` | `6` | 抓取标题超时（秒） |
| `login_lock_minutes` | `10` | 连续登录失败后的锁定分钟数 |
| `api_rate_limit` | `300` | 单 IP 每分钟 API 请求上限 |
| `api_rate_token_limit` | `120` | 单令牌每分钟 API 请求上限 |

站点级开关（`后台 → 设置`，默认关闭）：

- **开放使用**：关闭时，未登录用户访问短链会跳转登录页；开启后任何人可访问短链。
- **开放注册**：关闭时注册功能隐藏；开启后访客可自助注册普通账号。

## 🔌 API 文档

BASE：`https://你的域名/api/v1`

鉴权二选一：

```
Authorization: Bearer <token>
# 或
?access_token=<token>
```

令牌在 `后台 → API 令牌` 创建，仅显示一次，请妥善保存；支持随时吊销。

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| `GET` | `/health` | 健康检查（免鉴权） |
| `POST` | `/links` | 创建短链（`{"url": "...", "code": "可选", "title": "可选"}`） |
| `GET` | `/links` | 短链列表 |
| `GET` | `/links/{code}` | 查询单个短链 |
| `PUT` | `/links/{code}` | 更新短链 |
| `DELETE` | `/links/{code}` | 删除短链 |
| `GET` | `/stats` | 统计（链接总数 / 总点击 / 今日新增等） |
| `POST` | `/ua/check` | 检测 UA 是否命中屏蔽规则 |

示例：

```bash
curl -X POST https://your.domain/api/v1/links \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://example.com/very/long/path"}'

# {"ok":true,"data":{"code":"aB3x9","short_url":"https://your.domain/s/aB3x9","url":"https://example.com/very/long/path",...}}
```

> 完整参数与错误码见 `后台 → API 令牌 → 接口文档`。

## 🔐 安全设计

- 密码使用 **Argon2id**（不可用则回退 bcrypt）哈希存储，绝不明文。
- 所有写操作均有 **CSRF Token** 校验，安装流程同样受保护。
- 登录失败连续 5 次锁定 10 分钟，防爆破。
- 会话 Cookie 启用 `HttpOnly`、`SameSite`，敏感操作校验会话指纹。
- 自动抓取标题做了 **SSRF 防护**（仅允许 http/https、拒绝内网 / 保留地址）。
- `data/`（含数据库）、`config/`、`includes/` 均被 Web 层禁止访问。
- API 错误统一为 JSON（401 / 404 / 405 / 409 / 429），不泄露实现细节。

## 📦 从 YOURLS 迁移

后台 → 数据迁移，支持两种方式：

1. **CSV 导入**：导出 YOURLS 数据为 CSV（列序 `keyword,url,title,clicks`）后上传。
2. **MySQL 直连**：填写 YOURLS 的数据库连接信息，自动读取 `yourls_url` 表并映射导入。

迁移自动跳过重复短码，导入后可保留原有点击量。

## 🗂 目录结构

```
AdminURL/
├── admin/            # 管理后台（登录、链接管理、设置、迁移、UA、API）
├── api/              # REST API
├── assets/           # CSS / JS / 图标
├── config/           # 配置（config.php 由安装生成，勿上传到公开仓库）
├── data/             # SQLite 数据库（运行时生成）
├── includes/         # 核心库（数据库、认证、函数、Schema、模板）
├── install/          # 安装向导（上线后删除）
├── go.php            # 短链跳转入口
├── index.php         # 首页
├── router.php        # PHP 内置服务器路由（开发用）
└── .htaccess         # Apache 伪静态 + 安全头
```

## ❤️ 支持与反馈

- 技术文档（博客）：https://docs.asoe.cn
- GitHub：https://github.com/techjiang/
- 哔哩哔哩：https://space.bilibili.com/1768832152
- 玲珑社区：https://forums.asoe.cn/
- 邮箱：official@tech.asoe.cn
- QQ 群①：291974598
- QQ 群②：474819022
- QQ 群③：631167288

## 📄 License

[MIT](LICENSE) © 科技酱