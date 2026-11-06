<?php
/**
 * AdminURL 后台初始化（所有 admin 页面统一引入）
 *
 * 要求：必须登录，否则跳转登录页。
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT . '/includes/schema.php';

if (!is_installed()) {
    redirect(base_path('/install/'));
}

ensure_lifecycle_schema();

require_login();

// 后台公共变量
$adminUser = current_user();
$adminSiteName = (string)setting('site_name', 'AdminURL');