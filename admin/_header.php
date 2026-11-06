<?php
/**
 * AdminURL 后台布局 · 页头
 *
 * 使用前由 _init.php 提供 $adminUser / $adminSiteName，
 * 页面需设置 $pageTitle、$activeNav，可选 $pageSubtitle。
 */
$pageTitle    = $pageTitle ?? '管理后台';
$activeNav    = $activeNav ?? '';
$pageSubtitle = $pageSubtitle ?? '';
$adminUser    = $adminUser ?? [];
$adminSiteName = $adminSiteName ?? (string)setting('site_name', 'AdminURL');

$navGroups = [
    '概览' => [
        'dashboard' => ['仪表盘', base_path('/admin/'), 'grid'],
        'analytics' => ['数据统计', base_path('/admin/analytics.php'), 'chart'],
    ],
    '链接管理' => [
        'links'   => ['全部链接', base_path('/admin/links.php'), 'link'],
        'tags'    => ['分组标签', base_path('/admin/tags.php'), 'layers'],
        'health'  => ['健康检查', base_path('/admin/health.php'), 'shield'],
        'import'  => ['批量导入', base_path('/admin/import.php'), 'upload'],
        'migrate' => ['数据迁移', base_path('/admin/migrate.php'), 'database'],
    ],
    '系统' => [
        'api'      => ['API 接口', base_path('/admin/api.php'), 'code'],
        'ua'       => ['UA 屏蔽', base_path('/admin/ua.php'), 'shield'],
        'logs'     => ['操作日志', base_path('/admin/logs.php'), 'clock'],
        'settings' => ['系统设置', base_path('/admin/settings.php'), 'settings'],
    ],
];

$userName  = (string)($adminUser['username'] ?? 'Admin');
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1, 'UTF-8'), 'UTF-8');
$totalLinks = 0;
try {
    $totalLinks = (int)db()->query('SELECT COUNT(*) AS c FROM links')->fetch()['c'];
} catch (Throwable $e) {
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#5b5bf6">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> · <?= e($adminSiteName) ?></title>
    <link rel="icon" href="<?= e(base_path('/assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(base_path('/assets/css/app.css?v=2.0')) ?>">
    <link rel="stylesheet" href="<?= e(base_path('/assets/css/site.css?v=2.0')) ?>">
    <script>
        (function () {
            var mode = 'auto';
            try { mode = localStorage.getItem('au-theme') || 'auto'; } catch (e) { }
            var dark = mode === 'dark' || (mode === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme-mode', mode);
        })();
    </script>
</head>
<body class="admin-body">
<div class="admin-shell">

    <aside class="admin-sidebar">
        <a href="<?= e(base_path('/admin/')) ?>" class="sidebar-brand">
            <span class="brand-mark"><?= icon('link') ?></span>
            <span>
                <span class="brand-name"><?= e($adminSiteName) ?></span>
                <span class="brand-tag">Short links, simply.</span>
            </span>
        </a>

        <nav class="sidebar-nav">
            <?php foreach ($navGroups as $group => $items): ?>
                <div class="nav-label"><?= e($group) ?></div>
                <?php foreach ($items as $key => [$label, $url, $ico]): ?>
                    <a href="<?= e($url) ?>" class="nav-item <?= $activeNav === $key ? 'active' : '' ?>">
                        <?= icon($ico) ?>
                        <span><?= e($label) ?></span>
                        <?php if ($key === 'links' && $totalLinks > 0): ?>
                            <span class="nav-badge"><?= number_format($totalLinks) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <a href="<?= e(base_path('/')) ?>" class="nav-item" target="_blank" rel="noopener"><?= icon('globe') ?><span>访问站点</span></a>
            <a href="<?= e(base_path('/admin/logout.php')) ?>" class="nav-item"><?= icon('logout') ?><span>退出登录</span></a>
            <div class="sidebar-user">
                <span class="avatar"><?= e($userInitial) ?></span>
                <span class="sidebar-user-meta">
                    <span class="sidebar-user-name"><?= e($userName) ?></span>
                    <span class="sidebar-user-role">管理员</span>
                </span>
            </div>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-topbar">
            <div class="topbar-left">
                <button type="button" class="icon-btn sidebar-toggle" aria-label="打开菜单"><?= icon('menu') ?></button>
                <div>
                    <div class="topbar-crumb"><?= e($adminSiteName) ?> / <?= e($pageTitle) ?></div>
                    <h1><?= e($pageTitle) ?></h1>
                </div>
            </div>
            <div class="topbar-actions">
                <span class="topbar-time" data-clock></span>
                <button type="button" class="icon-btn" data-theme-toggle aria-label="切换深浅主题" title="切换深浅主题">
                    <?= icon('moon', 'theme-icon-moon') ?><?= icon('sun', 'theme-icon-sun') ?>
                </button>
                <a href="<?= e(base_path('/admin/links.php')) ?>" class="icon-btn" title="搜索链接（Ctrl + K）"><?= icon('search') ?></a>
                <span class="user-chip"><span class="avatar" style="width:24px;height:24px;border-radius:8px;font-size:11px"><?= e($userInitial) ?></span><span><?= e($userName) ?></span></span>
            </div>
        </header>

        <?php if (!empty($_SESSION['flash_ok'])): ?>
            <div class="alert alert-ok" data-flash style="margin:18px 30px 0"><?= icon('check') ?><span><?= e($_SESSION['flash_ok']) ?></span></div>
            <?php unset($_SESSION['flash_ok']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-error" data-flash style="margin:18px 30px 0"><?= icon('alert') ?><span><?= e($_SESSION['flash_error']) ?></span></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <div class="admin-content">
        <?php if ($pageSubtitle !== ''): ?>
            <div class="page-head">
                <div>
                    <h2><?= e($pageSubtitle) ?></h2>
                </div>
            </div>
        <?php endif; ?>
