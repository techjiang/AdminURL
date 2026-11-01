<?php
/**
 * AdminURL 开发服务器路由
 *
 * 用法：php -S 0.0.0.0:8000 router.php
 * 用于没有 Apache 重写环境时的本地调试，模拟 /s/{code} 路由。
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 静态资源直接放行
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

// 短链路由：/s/{code} -> go.php
if (preg_match('#^/s/([A-Za-z0-9]{1,32})/?$#', $uri, $m)) {
    $_GET['s'] = $m[1];
    require __DIR__ . '/go.php';
    return true;
}

// 其余交给内置服务器默认处理
return false;