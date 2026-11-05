<?php
/**
 * AdminURL 前台页头
 *
 * $pageTitle  页面标题
 * $layout     site（默认，带导航的首页布局）| auth（登录 / 注册 / 安装分屏布局）
 */
$pageTitle = $pageTitle ?? 'AdminURL';
$auLayout  = $layout ?? 'site';
$siteName  = (string)setting('site_name', 'AdminURL');
$siteDesc  = (string)setting('site_desc', '同一个世界，同一个互联网！');
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#5b5bf6">
    <title><?= e($pageTitle) ?> · <?= e($siteName) ?></title>
    <meta name="description" content="<?= e($siteDesc) ?>">
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
<?php if ($auLayout === 'auth'): ?>
<body class="auth-body">
<?php else: ?>
<body class="site-body">
<div class="site-grid-overlay" aria-hidden="true"></div>

<header class="site-nav">
    <a href="<?= e(base_path('/')) ?>" class="site-brand">
        <span class="brand-mark"><?= icon('link') ?></span>
        <span>
            <span class="brand-name"><?= e($siteName) ?></span>
            <span class="brand-tag">Short links, simply.</span>
        </span>
    </a>
    <nav class="site-nav-links">
        <a href="#features">功能特性</a>
        <a href="https://github.com/techjiang/AdminURL" target="_blank" rel="noopener nofollow">开源仓库</a>
        <a href="#footer">关于作者</a>
    </nav>
    <div class="site-nav-actions">
        <button type="button" class="icon-btn" data-theme-toggle aria-label="切换深浅主题" title="切换深浅主题">
            <?= icon('moon', 'theme-icon-moon') ?><?= icon('sun', 'theme-icon-sun') ?>
        </button>
        <a href="<?= e(base_path(is_logged_in() ? '/admin/' : '/admin/login.php')) ?>" class="btn btn-ghost btn-sm">
            <?= is_logged_in() ? '进入后台' : '管理后台' ?> <?= icon('arrow-right', 'icon-sm') ?>
        </a>
    </div>
</header>

<div class="page-wrap">
<?php endif; ?>
