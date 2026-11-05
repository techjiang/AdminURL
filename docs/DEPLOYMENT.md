# AdminURL 部署指南

> 面向生产环境的部署、伪静态配置与安全加固说明。  
> 环境要求：PHP >= 8.0，启用 `pdo_sqlite` / `sqlite3` / `curl` / `openssl` / `mbstring` 扩展。

---

## 目录

1. [通用安装流程](#通用安装流程)
2. [Apache 部署](#apache-部署)
3. [Nginx 部署](#nginx-部署)
4. [PHP 内置服务器（开发用）](#php-内置服务器开发用)
5. [伪静态路由说明](#伪静态路由说明)
6. [安全加固清单](#安全加固清单)
7. [常见问题](#常见问题)

---

## 通用安装流程

```bash
# 1. 上传代码到站点根目录（本例以 /var/www/AdminURL 为例）
#    请确认 data/ 与 config/ 目录存在且 PHP 进程可写

# 2. 设置目录权限（以 www-data 用户运行 PHP 为例）
chown -R www-data:www-data /var/www/AdminURL
chmod 750 /var/www/AdminURL/data /var/www/AdminURL/config

# 3. 浏览器访问
#    https://your.domain/install/
#    填写站点名称、站点地址（不带结尾斜杠）、管理员账号密码，点击安装

# 4. 安装完成后删除安装向导，防止未授权重装
rm -rf /var/www/AdminURL/install
```

> Windows / IIS 环境：`install/` 目录同样需要在安装完成后移除；IIS 请参考「伪静态路由说明」自行配置 URL Rewrite。

---

## Apache 部署

项目已内置 `.htaccess`，`AllowOverride All` 开启即可直接工作：

```apache
<VirtualHost *:80>
    ServerName your.domain
    DocumentRoot /var/www/AdminURL

    <Directory /var/www/AdminURL>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`.htaccess` 已包含：

- **主路由**：`/{code}` → `go.php?s={code}`，仅在请求不是真实文件 / 目录时生效
- **兼容拓展**：`/s/{code}` → `go.php?s={code}`，旧版链接继续可用
- 敏感目录拦截：`.开头目录`、`config/`、`data/`、`includes/` 一律 403
- `index.php` 301 至根目录
- 安全头（`X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy`）
- 禁止目录浏览、静态资源长缓存

---

## Nginx 部署

Nginx 不支持 `.htaccess`，需在 server 块内配置如下：

```nginx
server {
    listen 80;
    server_name your.domain;
    root /var/www/AdminURL;

    index index.php;

    charset utf-8;

    # 敏感目录：直接拒绝
    location ~ ^/(\.|config/|data/|includes/) {
        deny all;
    }

    # 兼容拓展：/s/{code}（旧版链接）
    location ~ ^/s/([A-Za-z0-9]{1,32})/?$ {
        try_files $uri /go.php?s=$1;
    }

    # 主路由：/{code}
    # 负向前瞻排除系统保留目录，避免 /admin/、/api/ 等被误判为短码
    location ~ ^/(?!(?:admin|api|assets|config|data|docs|includes|install|go|index|router|robots|favicon|s)(?:/|$))([A-Za-z0-9]{3,32})/?$ {
        try_files $uri $uri/ /go.php?s=$1;
    }

    # 静态资源
    location ~* \.(css|js|svg|png|jpg|jpeg|gif|ico|webp|woff2?)$ {
        expires 7d;
        access_log off;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;  # 按实际 PHP-FPM 版本调整
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";  # 防 HTTP_PROXY 注入
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

配置完成后重载：

```bash
nginx -t && nginx -s reload
```

---

## PHP 内置服务器（开发用）

无需 Web 服务器时，项目内置了路由脚本：

```bash
php -S 0.0.0.0:8000 router.php
```

- 短链地址形如：`http://127.0.0.1:8000/aB3x9`
- 兼容拓展地址：`http://127.0.0.1:8000/s/aB3x9`
- 安装向导：`http://127.0.0.1:8000/install/`
- 管理后台：`http://127.0.0.1:8000/admin/`

> `router.php` 会先放行真实文件与目录，再把 `/{code}` 与 `/s/{code}` 交给 `go.php`。开发时请务必带上该参数，否则根路径短链会 404。内置服务器仅为开发调试设计，**生产环境请使用 Apache / Nginx**。

---

## 伪静态路由说明

短链默认采用 **`/{code}`**（标准短链形态），**`/s/{code}` 作为兼容拓展保留**。两种路径最终都由 `go.php` 统一解析：

- 服务端**始终同时接受两种路径**，后台「短链路径形态」设置只决定新生成链接长什么样，切换不会让已发布链接失效。
- 规则必须保证**真实文件与目录优先**，只有不存在的路径才进入短链重写。
- `go.php` 内置保留路径名拦截（`admin` / `api` / `assets` / `config` / `data` / `docs` / `includes` / `install` / `go` / `index` / `router` / `robots` / `favicon` / `s` 等），即使伪静态配置遗漏也不会把系统路径当成短码。
- 直连兜底：`go.php?s={code}` 同样可用。

| 服务器 | 主路由 `/{code}` | 兼容拓展 `/s/{code}` |
| --- | --- | --- |
| Apache | `RewriteCond %{REQUEST_FILENAME} !-f` + `!-d`，再 `RewriteRule ^([A-Za-z0-9]{3,32})/?$ go.php?s=$1 [L,QSA]`（已内置） | `RewriteRule ^s/([A-Za-z0-9]{1,32})/?$ go.php?s=$1 [L,QSA]`（已内置） |
| Nginx | `location ~ ^/(?!保留目录)([A-Za-z0-9]{3,32})/?$ { try_files $uri $uri/ /go.php?s=$1; }`（完整规则见上文） | `location ~ ^/s/([A-Za-z0-9]{1,32})/?$ { try_files $uri /go.php?s=$1; }` |
| IIS | URL Rewrite：`^([A-Za-z0-9]{3,32})/?$` → `go.php?s={R:1}` | `^s/([A-Za-z0-9]{1,32})/?$` → `go.php?s={R:1}` |

---

## 安全加固清单

上线前逐项确认：

1. ✅ **删除 `install/` 目录**：防止任何人重新安装并覆盖你的管理员账号。
2. ✅ **`data/`、`config/` 不可 Web 访问**：项目默认规则已拦截，Nginx 请按上文配置 `deny all`。
3. ✅ **`config/config.php` 的 `secret_key` 唯一且保密**：泄露后请修改并重新登录所有会话。
4. ✅ **数据目录权限收紧**：目录属主为 PHP 运行用户，其他用户不可读写（`chmod 750`）。
5. ✅ **根路径短链的重写规则排除真实目录**：否则 `/admin/`、`/api/` 可能被误判为短码。
6. ✅ **强制 HTTPS**：建议在服务器层开启 301 跳转与 HSTS。
7. ✅ **后台开启「开放使用」后注意**：短链将对所有人可见，UA 屏蔽与禁用链接仍生效。
8. ✅ **定期备份数据库**：`data/adminurl.db` 单文件即全部数据，停机复制即可备份。
9. ✅ **关注 PHP 与 Web 服务器安全更新**。

---

## 常见问题

**Q：安装时报「config 目录不可写」？**  
将 `config/` 目录属主改为 PHP 运行用户（如 `chown www-data:www-data config/`）。

**Q：短链访问 404？**  
先确认伪静态规则已生效（Apache 开启 `AllowOverride All`；Nginx 已配置根路径 `location`；内置服务器启动时带了 `router.php`），再用 `curl -I https://your.domain/aB3x9` 验证。旧的 `/s/{code}` 链接无需改动也仍然可用。

**Q：能不能改回 `/s/{code}` 形式？**  
可以。`后台 → 系统设置 → 站点信息 → 短链路径形态` 选择 `/s/{code}` 即可；切换只影响新生成的链接，两种路径始终都能访问，不需要迁移数据。

**Q：为什么访问 `/admin` 变成了找短码？**  
说明伪静态没有排除真实目录。Apache 请确认 `.htaccess` 生效且 `!-f` / `!-d` 条件存在；Nginx 请保留上文的保留目录负向前瞻。`go.php` 也会拦截这些保留名，不会跳转。

**Q：自动获取标题不生效？**  
确认 `curl` 扩展已启用；个别站点会拒绝非浏览器 UA 或返回动态页面，属正常现象，可手动填写标题。

**Q：如何备份与迁移？**  
停止写入后复制 `data/adminurl.db` 即可。新环境走一遍安装流程，再用新库替换（或直接放回 `data/` 并确保 `config.php` 的 `db_file` 指向一致）。

**Q：忘记管理员密码？**  
密码使用 Argon2id 单向哈希，无法找回原密码，只能重置。参见 [README「管理员账号与忘记密码」](../README.md)，用临时 PHP 脚本调用 `update_user()` 生成新密码（用完立即删除脚本）。
