<?php
/**
 * AdminURL 开发服务器路由
 *
 * 用法：php -S 0.0.0.0:8000 router.php
 * 用于没有 Apache 重写环境时的本地调试，模拟根路径短链与 /s/ 兼容路由。
 */

$uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

// 真实文件与目录交给内置服务器默认处理（admin / api / assets / install 等）
$file = __DIR__ . $uri;
if ($uri !== '/' && (is_file($file) || is_dir($file))) {
    return false;
}

// 拓展路由：/s/{code} -> go.php（兼容旧链接，始终可用）
if (preg_match('#^/s/([A-Za-z0-9]{1,32})/?$#', $uri, $m)) {
    $_GET['s'] = $m[1];
    require __DIR__ . '/go.php';
    return true;
}

// 主路由：/{code} -> go.php（标准根路径短链）
if (preg_match('#^/([A-Za-z0-9]{3,32})/?$#', $uri, $m)) {
    $_GET['s'] = $m[1];
    require __DIR__ . '/go.php';
    return true;
}

// 其余交给内置服务器默认处理
return false;
