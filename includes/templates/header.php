<?php
/**
 * AdminURL 页头
 *
 * 前台页面（首页 / 登录 / 注册 / 公网表单）统一引入。
 * 使用前需设置 $pageTitle。
 */
if (!isset($pageTitle)) {
    $pageTitle = 'AdminURL';
}
$siteName = (string)setting('site_name', 'AdminURL');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - <?= e($siteName) ?></title>
    <meta name="description" content="<?= e((string)setting('site_desc', '同一个世界，同一个互联网！')) ?>">
    <link rel="icon" href="<?= e(base_path('/assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(base_path('/assets/css/style.css?v=1.0')) ?>">
</head>
<body>
<div class="page-wrap">