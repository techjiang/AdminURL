<?php
/**
 * AdminURL 后台页头（侧边栏布局）
 *
 * 使用前需在 _init.php 已加载；设置 $pageTitle、$activeNav（nav 标识）。
 */
if (!isset($pageTitle)) {
    $pageTitle = '管理后台';
}
$activeNav = $activeNav ?? '';
$navs = [
    'dashboard' => ['仪表盘', base_path('/admin/'), '🏠'],
    'links'     => ['链接管理', base_path('/admin/links.php'), '🔗'],
    'migrate'   => ['数据迁移', base_path('/admin/migrate.php'), '🚚'],
    'api'       => ['API 接口', base_path('/admin/api.php'), '💻'],
    'ua'        => ['UA 屏蔽', base_path('/admin/ua.php'), '🛡️'],
    'settings'  => ['系统设置', base_path('/admin/settings.php'), '⚙️'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - <?= e($adminSiteName) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="<?= e(base_path('/assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(base_path('/assets/css/style.css?v=1.0')) ?>">
</head>
<body class="admin-body">
<div class="admin-shell">

    <aside class="admin-sidebar">
        <div class="sidebar-brand">
            <span class="logo-badge">🔗</span>
            <span class="brand-name"><?= e($adminSiteName) ?></span>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($navs as $key => [$label, $url, $icon]): ?>
                <a href="<?= e($url) ?>" class="nav-item <?= $activeNav === $key ? 'active' : '' ?>">
                    <span class="nav-icon"><?= $icon ?></span>
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <a href="<?= e(base_path('/')) ?>" class="nav-item"><span class="nav-icon">🌐</span> 站点首页</a>
            <a href="<?= e(base_path('/admin/logout.php')) ?>" class="nav-item"><span class="nav-icon">🚪</span> 退出登录</a>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-topbar">
            <div class="topbar-left">
                <button type="button" class="sidebar-toggle" aria-label="打开菜单">☰</button>
                <h1><?= e($pageTitle) ?></h1>
            </div>
            <div class="topbar-right">
                <span class="user-chip">👤 <?= e($adminUser['username'] ?? '') ?></span>
            </div>
        </header>

        <?php if (!empty($_SESSION['flash_ok'])): ?>
            <div class="alert alert-ok" id="flash-ok"><?= e($_SESSION['flash_ok']) ?></div>
            <?php unset($_SESSION['flash_ok']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-error" id="flash-error"><?= e($_SESSION['flash_error']) ?></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <div class="admin-content">