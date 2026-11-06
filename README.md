# 🔗 AdminURL

> 同一个世界，同一个互联网！

**AdminURL** 是一个自托管的开源短链接系统：轻量、私密、好看。默认只有登录的管理员可以使用，需要对外开放时一键开启。基于 PHP + SQLite 构建，零外部依赖、无前端构建链，部署即是上线。

- **作者**：[科技酱](https://www.asoe.cn)
- **仓库**：https://github.com/techjiang/AdminURL
- **文档**：https://docs.asoe.cn · [部署指南](docs/DEPLOYMENT.md)
- **协议**：[MIT](LICENSE)
- **当前版本**：v2.6.0（[更新日志](CHANGELOG.md)）

---

## ✨ 功能特性

| 功能 | 说明 |
| --- | --- |
| 短链接生成 | 长链接一键转为短链接，支持自定义短码（3-32 位字母数字），自动避开系统保留路径 |
| 根路径短链 | 默认生成 `域名/{code}` 标准短链；`/s/{code}` 作为兼容拓展始终可用，可在设置中切换 |
| 有效期控制 | 为每条短链设置到期时间，到期自动停止跳转；列表可筛选「已过期」、按「即将到期」排序 |
| 分组标签 | 用 `tag` 按活动 / 渠道 / 项目归类，独立汇总页可重命名、合并与清除标签，列表与 API 均支持按分组筛选 |
| 批量导入 | 上传或粘贴 CSV 一次创建多条短链，含模板下载、逐行校验、错误明细与事务回滚 |
| 一键克隆 | 复制目标地址、标题与未到期有效期并生成新短码，点击数从 0 开始，后台与 API 均可调用 |
| 点击统计 | 全站 / 单链近 14 天点击趋势、今日与周指标、热门短链 Top 10，按日聚合长期保留 |
| 筛选与排序 | 按状态筛选、按点击量 / 短码排序，分页与 CSV 导出保持一致 |
| 运维保障 | 操作日志留痕（筛选 / 清空）、一键下载一致性数据库备份 |
| 友好错误页 | 短链不存在、已停用或被屏蔽时展示品牌化 404 / 403 页面 |
| 链接管理 | 列表、搜索、分页、编辑、启用/停用、删除，统计一目了然 |
| 数据导出 | 一键导出 CSV，可沿用当前搜索条件，UTF-8 BOM 兼容 Excel |
| 批量操作 | 全选 / 批量启用、停用、删除，带二次确认与 CSRF 校验 |
| 数据看板 | 近 14 天创建趋势图、核心统计卡、运行状态与最近链接 |
| 深浅主题 | 浅色 / 深色一键切换，可跟随系统并记忆偏好 |
| 响应式界面 | 桌面侧栏、移动抽屉、全站内联 SVG 图标，无任何 CDN 依赖 |
| 延迟计数 | 点击量先写入缓冲表，按阈值 / 间隔批量合并，高并发下不卡库 |
| 自动获取标题 | 添加链接时自动抓取页面标题，也可留空保存时再抓 |
| UA 屏蔽 | 自定义屏蔽 User-Agent，命中即返回 403 且不计数，支持通配符 |
| 数据迁移 | 粘贴 YOURLS 的 CSV 或 SQL 导出内容，防重复并回填点击量 |
| 完整 API | REST 接口 + 令牌鉴权 + 限流，方便二次开发与集成 |
| 私密可控 | 默认仅管理员可用；「开放使用」「开放注册」均为独立开关，默认关闭 |
| 安全加固 | Argon2id 密码哈希、CSRF 防护、登录限速锁定、会话硬化、令牌吊销 |

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

### 获取代码

```bash
git clone https://github.com/techjiang/AdminURL.git
cd AdminURL
```

也可以下载 Releases 源码包，解压到站点根目录。

### 安装步骤

1. **下载代码**，将 `AdminURL` 目录放到 Web 根目录（如 Apache 的 `htdocs`、Nginx 站点根目录）。
2. **准备目录**：确认 `data/`、`config/` 目录存在且 PHP 进程可写。
3. **访问安装向导**：浏览器打开 `https://你的域名/install/`，按提示填写站点名称、站点地址（不带结尾斜杠）、管理员账号密码。
4. **安装完成**：系统自动生成 `config/config.php` 并写入随机密钥，初始化数据库。点击「进入管理后台」即可登录使用。
5. **安全提示**：正式上线前**删除 `install` 目录**，防止未授权重装。

### 管理员账号与忘记密码

**本项目没有默认账号，也没有默认密码。** 用户名和密码都在安装向导第 3 步由安装者自行填写，密码使用 Argon2id 单向哈希存储，任何人（包括开发者）都无法从数据库中反查出原密码，只能重置。

- 后台入口：`/admin/login.php`
- 忘记密码后改密码（需先登录）：`系统设置 → 账号安全`
- 完全忘记密码时，可在项目根目录临时新建 `reset-admin.php`，执行后立刻删除：

```php
<?php
require_once __DIR__ . '/includes/bootstrap.php';
var_dump(update_user(1, 'admin', 'YourNewPass123')); // 依次：用户 ID、用户名、新密码（至少 8 位）
db()->exec('DELETE FROM login_failures');            // 顺带清除登录失败锁定
```

运行 `php reset-admin.php`，看到 `bool(true)` 即重置成功。若连用户名也忘了，可先用 `SELECT id, username FROM users;` 查询。只有在不需要保留任何数据时，才考虑删除 `config/config.php` 与 `data/adminurl.db` 后重新运行安装向导（**会清空所有短链**）。

### 演示（本地开发）

没有 Web 服务器时，可用 PHP 内置服务器：

```bash
php -S 0.0.0.0:8000 router.php
```

然后访问 `http://127.0.0.1:8000/install/` 完成安装（短链路由由 `router.php` 模拟）。

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

站点级开关（`后台 → 系统设置 → 访问与开放`，默认关闭）：

- **开放公众缩短链接**：关闭时，未登录用户访问首页会跳转登录页；开启后任何人可在首页生成并访问短链。
- **开放用户注册**：关闭时注册功能提示未开放；开启后访客可自助注册。

短链路径形态（`后台 → 系统设置 → 站点信息`）：

- **根路径 `/{code}`**（默认）：生成 `https://your.domain/aB3x9`，标准短链形式。
- **兼容拓展 `/s/{code}`**：生成 `https://your.domain/s/aB3x9`，用于沿用旧版链接。
- 无论选择哪种，**两种路径始终都能访问**，已发布的链接不会因切换而失效。根路径短链依赖伪静态规则，配置见 [部署指南](docs/DEPLOYMENT.md)。

短链有效期（`创建 / 编辑 / 批量导入 / API`）：

- 在快捷创建、首页高级选项、编辑弹窗或 CSV 的 `expires_at` 列设置到期时间，留空即永久有效。
- 到期后短链展示品牌化「短链接已过期」页面，不再跳转、不再计数，数据也不会被删除。
- 修改或清除有效期后即刻恢复；列表状态列会显示到期时间，仪表盘汇总已过期与 7 天内到期数量。
- `links.expires_at` 由程序自动建列，从旧版本升级无需手工执行 SQL。

## 🔌 API 文档

BASE：`https://your.domain/api/v1`

鉴权二选一：

```
Authorization: Bearer <token>
# 或
?access_token=<token>
```

令牌在 `后台 → API 接口` 创建，仅显示一次，请妥善保存；支持随时吊销。

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| `GET` | `/health` | 健康检查（免鉴权） |
| `POST` | `/links` | 创建短链（`{"url": "...", "code": "可选", "title": "可选", "expires_at": "可选", "tag": "可选"}`） |
| `GET` | `/links` | 短链列表（支持 `page` / `size` / `q` / `tag`，`tag=__none` 查未分组） |
| `GET` | `/links/{code}` | 查询单个短链 |
| `PUT` | `/links/{code}` | 更新短链（含有效期，传空字符串可清除） |
| `POST` | `/links/{code}/clone` | 一键克隆，生成新短码且点击数从 0 开始 |
| `DELETE` | `/links/{code}` | 删除短链 |
| `GET` | `/stats` | 统计（链接总数 / 总点击 / 今日新增等） |
| `POST` | `/ua/check` | 检测 UA 是否命中屏蔽规则 |

示例：

```bash
curl -X POST https://your.domain/api/v1/links -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" -d '{"url":"https://example.com/very/long/path"}'
```

> 完整参数与错误码见 `后台 → API 接口 → 接口文档`。

## 🔐 安全设计

- 密码使用 **Argon2id** 哈希存储，绝不明文。
- 所有写操作均有 **CSRF Token** 校验，安装流程同样受保护。
- 登录失败连续 5 次锁定 10 分钟，防爆破。
- 会话 Cookie 启用 `HttpOnly`、`SameSite`，敏感操作校验会话指纹。
- `data/`（含数据库）、`config/`、`includes/` 均被 Web 层禁止访问。
- API 错误统一为 JSON（401 / 404 / 405 / 409 / 429），不泄露实现细节。

## 📦 从 YOURLS 迁移

后台 → 数据迁移，支持两种粘贴导入方式：

1. **CSV**：每行 `短码,目标地址,标题,点击数`，首行可带表头 `keyword,url,title,clicks`。
2. **SQL 导出**：粘贴 phpMyAdmin 导出的 YOURLS `urls` 表 INSERT 语句。

短码已存在的条目会自动跳过（不覆盖），导入时可选自动抓取缺失标题，并回填原有点击量。迁移历史在页面下方留存。

## 📥 批量导入

后台 → 批量导入，可上传 `.csv` 文件或直接粘贴 CSV 内容，建议先下载页面提供的模板：

| 列 | 必填 | 说明 |
| --- | --- | --- |
| `url` | 是 | 目标地址，必须是 http/https 链接 |
| `code` | 否 | 3-32 位字母数字，留空自动生成短码 |
| `title` | 否 | 标题，最长 200 字 |
| `expires_at` | 否 | 如 `2026-12-31 23:59`，留空永久有效 |
| `tag` | 否 | 分组标签，最多 32 字（中文表头「分组」亦可） |

表头按名称识别（列顺序不限，也支持中文表头），兼容 UTF-8 / GBK 编码，单次最多 500 条、1 MB。重复短码、系统保留短码与非法行会跳过并给出逐行原因；所有行在同一事务中写入，出现数据库错误时整体回滚。

## 🗂 目录结构

```
AdminURL/
├── admin/            # 管理后台（登录、仪表盘、统计、链接、详情、批量导入、导出、日志、备份、设置、迁移、UA、API）
├── api/              # REST API
├── assets/           # CSS / JS / SVG 图标（无外部依赖）
├── config/           # config.example.php（config.php 由安装生成，不入库）
├── data/             # SQLite 数据库（运行时生成，不入库）
├── docs/             # 部署指南
├── includes/         # 核心库（数据库、认证、函数、Schema、统计、图表、生命周期、版本、图标、模板）
├── CHANGELOG.md      # 更新日志
├── install/          # 安装向导（上线后删除）
├── go.php            # 短链跳转入口（/{code} 与 /s/{code}）
├── index.php         # 首页
├── router.php        # PHP 内置服务器路由（开发用）
└── .htaccess         # Apache 伪静态 + 安全头
```

## ❤️ 支持与反馈

- 官网：https://www.asoe.cn
- GitHub：https://github.com/techjiang/AdminURL
- 技术文档：https://docs.asoe.cn
- 哔哩哔哩：https://space.bilibili.com/1768832152
- 玲珑社区：https://forums.asoe.cn/
- 邮箱：official@tech.asoe.cn
- QQ 群①：291974598
- QQ 群②：474819022
- QQ 群③：631167288

## 📄 开源协议

本项目基于 [MIT License](LICENSE) 开源，可自由使用、修改与商用。
