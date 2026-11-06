<?php
/**
 * AdminURL 启动引导
 *
 * 所有页面入口统一引入本文件，完成配置加载、时区与会话初始化。
 */

declare(strict_types=1);

// 项目根目录
defined('ROOT') or define('ROOT', dirname(__DIR__));

// 读取配置
$GLOBALS['config'] = is_file(ROOT . '/config/config.php')
    ? require ROOT . '/config/config.php'
    : require ROOT . '/config/config.example.php';

// 时区
date_default_timezone_set($GLOBALS['config']['timezone'] ?? 'Asia/Shanghai');

// 错误处理：生产环境不显示错误详情
if (($GLOBALS['config']['debug'] ?? false) === true) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

// 会话
$sessName = $GLOBALS['config']['session_name'] ?? 'ADMINURL_SESSID';
session_name($sessName);
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 基础函数库
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/icons.php';
require_once ROOT . '/includes/version.php';
require_once ROOT . '/includes/stats.php';
require_once ROOT . '/includes/chart.php';
require_once ROOT . '/includes/lifecycle.php';
require_once ROOT . '/includes/tagging.php';
require_once ROOT . '/includes/health.php';
require_once ROOT . '/includes/audience.php';

// 安全响应头
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-XSS-Protection: 1; mode=block');