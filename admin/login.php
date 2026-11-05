<?php
/**
 * AdminURL 登录页
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT . '/includes/schema.php';

if (!is_installed()) {
    redirect(base_path('/install/'));
}

if (is_logged_in()) {
    redirect(base_path('/admin/'));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $error = '请求已过期，请刷新页面后重试';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $result = do_login($username, $password);
        if ($result['ok']) {
            redirect(base_path('/admin/'));
        }
        $error = $result['message'];
    }
}

$publicRegister = (string)setting('public_register', '0') === '1';
$pageTitle = '登录';
$layout = 'auth';
require ROOT . '/includes/templates/header.php';
?>

<div class="auth-page">
    <aside class="auth-aside">
        <div class="auth-orb second" aria-hidden="true"></div>
        <div class="auth-orb" aria-hidden="true"></div>
        <div class="auth-aside-inner">
            <a href="<?= e(base_path('/')) ?>" class="site-brand">
                <span class="brand-mark"><?= icon('link') ?></span>
                <span>
                    <span class="brand-name">AdminURL</span>
                    <span class="brand-tag">Short links, simply.</span>
                </span>
            </a>

            <h2>轻量的链接，<br><span>承载更多可能。</span></h2>
            <p class="auth-aside-desc">一个部署在你自己服务器上的短链接系统。干净、快速、完全可控。</p>

            <div class="auth-points">
                <div class="auth-point"><?= icon('bolt') ?> 秒级跳转，点击数据实时可查</div>
                <div class="auth-point"><?= icon('shield') ?> 私密优先，安全策略层层防护</div>
                <div class="auth-point"><?= icon('code') ?> 完整 REST API，随时集成</div>
            </div>

            <div class="auth-quote">「同一个世界，同一个互联网！」<strong>— 科技酱</strong></div>
        </div>
    </aside>

    <main class="auth-panel">
        <button type="button" class="icon-btn auth-theme" data-theme-toggle aria-label="切换深浅主题" title="切换深浅主题">
            <?= icon('moon', 'theme-icon-moon') ?><?= icon('sun', 'theme-icon-sun') ?>
        </button>
        <div class="auth-box">
            <div class="auth-head">
                <span class="brand-mark"><?= icon('lock') ?></span>
                <h1>欢迎回来</h1>
                <p>登录控制台，继续管理你的短链接。</p>
            </div>

            <form method="post" class="auth-form" autocomplete="off">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" placeholder="请输入用户名" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" placeholder="请输入密码" required>
                </div>

                <?php if ($error !== ''): ?>
                    <p class="form-error"><?= e($error) ?></p>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary btn-block btn-lg"><?= icon('arrow-right', 'icon-sm') ?> 登录控制台</button>
            </form>

            <div class="auth-actions">
                <?php if ($publicRegister): ?>
                    <a href="<?= e(base_path('/admin/register.php')) ?>">没有账号？注册一个</a>
                <?php else: ?>
                    <span class="muted">仅管理员可登录</span>
                <?php endif; ?>
                <a href="<?= e(base_path('/')) ?>">返回首页 <?= icon('arrow-right', 'icon-sm') ?></a>
            </div>
        </div>
        <div class="auth-foot">&copy; <?= date('Y') ?> AdminURL · by <a href="https://www.asoe.cn" target="_blank" rel="noopener nofollow">科技酱</a></div>
    </main>
</div>

<?php require ROOT . '/includes/templates/footer.php'; ?>
