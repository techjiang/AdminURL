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
if (!$publicRegister) {
    // 未开放注册：渲染友好提示页（而非简单 302），用户可自行选择返回登录
    $pageTitle = '注册未开放';
    require ROOT . '/includes/templates/header.php';
    ?>
    <div class="auth-wrap">
        <div class="auth-card">
            <div class="auth-head">
                <div class="logo-badge">🔗</div>
                <h1>暂未开放注册</h1>
                <p>管理员未开启「开放注册」，当前不可自助创建账号</p>
            </div>
            <?php if ($error !== ''): ?>
                <p class="form-error"><?= e($error) ?></p>
            <?php endif; ?>
            <div class="auth-actions">
                <a href="<?= e(base_path('/admin/login.php')) ?>" class="btn btn-primary btn-block">去登录</a>
            </div>
        </div>
    </div>
    <?php
    require ROOT . '/includes/templates/footer.php';
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $error = '请求已过期，请刷新页面后重试';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');

        if ($password !== $password2) {
            $error = '两次输入的密码不一致';
        } else {
            $res = create_user($username, $password, 'admin');
            if ($res['ok']) {
                // 注册成功自动登录
                $login = do_login($username, $password);
                redirect(base_path('/admin/'));
            }
            $error = $res['message'];
        }
    }
}

$pageTitle = '注册';
require ROOT . '/includes/templates/header.php';
?>

<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <div class="logo-badge">🔗</div>
            <h1>创建账号</h1>
            <p>注册 AdminURL 账号</p>
        </div>

        <form method="post" class="auth-form" autocomplete="off">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" placeholder="2-32 位，中英文/数字/下划线" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" placeholder="至少 8 位" minlength="8" required>
            </div>
            <div class="form-group">
                <label for="password2">确认密码</label>
                <input type="password" id="password2" name="password2" minlength="8" required>
            </div>

            <?php if ($error !== ''): ?>
                <p class="form-error"><?= e($error) ?></p>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-block">注 册</button>
        </form>

        <div class="auth-actions">
            <a href="<?= e(base_path('/admin/login.php')) ?>">已有账号？去登录</a>
        </div>
    </div>
</div>

<?php require ROOT . '/includes/templates/footer.php'; ?>