<?php
/**
 * AdminURL 登录页
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT . '/includes/schema.php';

if (!is_installed()) {
    redirect(base_path('/install/'));
}

// 已登录直接进后台
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
require ROOT . '/includes/templates/header.php';
?>

<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <div class="logo-badge">🔗</div>
            <h1>欢迎回来</h1>
            <p>登录 AdminURL 管理后台</p>
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

            <button type="submit" class="btn btn-primary btn-block">登 录</button>
        </form>

        <div class="auth-actions">
            <?php if ($publicRegister): ?>
                <a href="<?= e(base_path('/admin/register.php')) ?>">没有账号？注册一个</a>
            <?php endif; ?>
            <a href="<?= e(base_path('/')) ?>">返回首页</a>
        </div>
    </div>
</div>

<?php require ROOT . '/includes/templates/footer.php'; ?>