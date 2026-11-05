<?php
/**
 * AdminURL 注册页
 *
 * 仅在「开放注册」开启时可用，默认关闭。
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT . '/includes/schema.php';

if (!is_installed()) {
    redirect(base_path('/install/'));
}

if (is_logged_in()) {
    redirect(base_path('/admin/'));
}

$publicRegister = (string)setting('public_register', '0') === '1';
$closed = !$publicRegister;
$error = '';

if (!$closed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $error = '请求已过期，请刷新页面后重试';
    } else {
        $username  = trim((string)($_POST['username'] ?? ''));
        $password  = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');

        if ($password !== $password2) {
            $error = '两次输入的密码不一致';
        } else {
            $res = create_user($username, $password, 'admin');
            if ($res['ok']) {
                do_login($username, $password);
                redirect(base_path('/admin/'));
            }
            $error = $res['message'];
        }
    }
}

$pageTitle = $closed ? '注册未开放' : '注册';
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
            <?php if ($closed): ?>
                <div class="auth-head">
                    <span class="brand-mark"><?= icon('lock') ?></span>
                    <h1>暂未开放注册</h1>
                    <p>管理员未开启「开放注册」，当前不可自助创建账号。</p>
                </div>
                <div class="auth-actions" style="justify-content:flex-start">
                    <a href="<?= e(base_path('/admin/login.php')) ?>" class="btn btn-primary btn-block btn-lg"><?= icon('arrow-left', 'icon-sm') ?> 返回登录</a>
                </div>
            <?php else: ?>
                <div class="auth-head">
                    <span class="brand-mark"><?= icon('user') ?></span>
                    <h1>创建账号</h1>
                    <p>填写下面的信息，几秒钟即可开始使用。</p>
                </div>

                <form method="post" class="auth-form">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="username">用户名</label>
                        <input type="text" id="username" name="username" placeholder="2-32 位，中英文 / 数字 / 下划线" required autofocus autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label for="password">密码</label>
                        <input type="password" id="password" name="password" placeholder="至少 8 位" minlength="8" required autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label for="password2">确认密码</label>
                        <input type="password" id="password2" name="password2" placeholder="再次输入密码" minlength="8" required autocomplete="new-password">
                    </div>

                    <?php if ($error !== ''): ?>
                        <p class="form-error"><?= e($error) ?></p>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary btn-block btn-lg"><?= icon('check', 'icon-sm') ?> 注册并登录</button>
                </form>

                <div class="auth-actions">
                    <a href="<?= e(base_path('/admin/login.php')) ?>">已有账号？去登录</a>
                    <a href="<?= e(base_path('/')) ?>">返回首页</a>
                </div>
            <?php endif; ?>
        </div>
        <div class="auth-foot">&copy; <?= date('Y') ?> AdminURL · by <a href="https://www.asoe.cn" target="_blank" rel="noopener nofollow">科技酱</a></div>
    </main>
</div>

<?php require ROOT . '/includes/templates/footer.php'; ?>
