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

- 短链路由：`/s/{code}` → `go.php?s={code}`
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

    # 短链路由：/s/{code} -> go.php
    location ~ ^/s/([A-Za-z0-9]{1,32})/?$ {
        try_files $uri /go.php?s=$1;
    }

    # 敏感目录：直接拒绝
    location ~ ^/(\.|config/|data/|includes/) {
        deny all;
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

- 短链地址形如：`http://127.0.0.1:8000/s/aB3x9`
- 安装向导：`http://127.0.0.1:8000/install/`
- 管理后台：`http://127.0.0.1:8000/admin/`

> 内置服务器仅为开发调试设计，**生产环境请使用 Apache / Nginx**。

---

## 伪静态路由说明

短链采用 `/s/{code}` 路径，而不是 `?code=` 参数，便于分享与收录。两种服务器等价配置：

| 服务器 | 规则 |
| --- | --- |
| Apache | `RewriteRule ^s/([A-Za-z0-9]{1,32})/?$ go.php?s=$1 [L,QSA]`（已内置） |
| Nginx | `location ~ ^/s/([A-Za-z0-9]{1,32})/?$ { try_files $uri /go.php?s=$1; }` |
| IIS | URL Rewrite：`^s/([A-Za-z0-9]{1,32})/?$` → `go.php?s={R:1}` |

---

## 安全加固清单

上线前逐项确认：

1. ✅ **删除 `install/` 目录**：防止任何人重新安装并覆盖你的管理员账号。
2. ✅ **`data/`、`config/` 不可 Web 访问**：项目默认规则已拦截，Nginx 请按上文配置 `deny all`。
3. ✅ **`config/config.php` 的 `secret_key` 唯一且保密**：泄露后请修改并重新登录所有会话。
4. ✅ **数据目录权限收紧**：目录属主为 PHP 运行用户，其他用户不可读写（`chmod 750`）。
5. ✅ **强制 HTTPS**：建议在服务器层开启 301 跳转与 HSTS。
6. ✅ **后台开启「开放使用」后注意**：短链将对所有人可见，UA 屏蔽与禁用链接仍生效。
7. ✅ **定期备份数据库**：`data/adminurl.db` 单文件即全部数据，停机复制即可备份。
8. ✅ **关注 PHP 与 Web 服务器安全更新**。

---

## 常见问题

**Q：安装时报「config 目录不可写」？**  
将 `config/` 目录属主改为 PHP 运行用户（如 `chown www-data:www-data config/`）。

**Q：短链访问 404？**  
先确认伪静态规则已生效（Apache 开启 `AllowOverride All`；Nginx 已配置 `location ~ ^/s/`）。再用 `curl -I https://your.domain/s/{code}` 验证。

**Q：自动获取标题不生效？**  
确认 `curl` 扩展已启用；个别站点会拒绝非浏览器 UA 或返回动态页面，属正常现象，可手动填写标题。

**Q：如何备份与迁移？**  
停止写入后复制 `data/adminurl.db` 即可。新环境走一遍安装流程，再用新库替换（或直接放回 `data/` 并确保 `config.php` 的 `db_file` 指向一致）。

**Q：忘记管理员密码？**  
在数据库中为 `users` 表对应账号重新写入密码哈希（需使用与系统一致的 Argon2id/bcrypt 算法，建议通过临时 PHP 脚本调用 `password_hash()` 生成后 UPDATE）。