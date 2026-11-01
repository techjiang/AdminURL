<?php
/**
 * AdminURL 管理后台 - 系统设置
 *
 * 站点信息 / 开放开关 / 跳转与标题策略 / 修改账号
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '系统设置';
$activeNav = 'settings';

$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $_SESSION['flash_error'] = '请求已过期，请刷新页面后重试';
    } else {
        $form = $_POST['form'] ?? '';

        if ($form === 'site_settings') {
            $siteName = trim((string)($_POST['site_name'] ?? 'AdminURL'));
            $siteDesc = trim((string)($_POST['site_desc'] ?? ''));
            $redirectType = (string)($_POST['redirect_type'] ?? '302');
            $autoTitle = isset($_POST['auto_title']) ? '1' : '0';
            $publicShorten = isset($_POST['public_shorten']) && (string)($_POST['public_shorten']) !== '' ? '1' : '0';
            $publicRegister = isset($_POST['public_register']) && (string)($_POST['public_register']) !== '' ? '1' : '0';

            if ($siteName === '' || mb_strlen($siteName) > 30) {
                $_SESSION['flash_error'] = '站点名称不能为空且不超过 30 字';
            } elseif ($redirectType !== '301' && $redirectType !== '302') {
                $_SESSION['flash_error'] = '跳转方式参数不正确';
            } else {
                set_setting('site_name', $siteName);
                set_setting('site_desc', mb_substr($siteDesc, 0, 120));
                set_setting('redirect_type', $redirectType);
                set_setting('auto_title', $autoTitle);
                set_setting('public_shorten', $publicShorten);
                set_setting('public_register', $publicRegister);
                $saved = true;
                $_SESSION['flash_ok'] = '设置已保存';
                log_event('settings', '更新站点设置');
            }
        }

        if ($form === 'account') {
            $username = trim((string)($_POST['username'] ?? ''));
            $curPass  = (string)($_POST['current_password'] ?? '');
            $newPass  = (string)($_POST['new_password'] ?? '');
            $newPass2 = (string)($_POST['new_password2'] ?? '');

            $user = current_user();
            if (!password_verify($curPass, $user['password_hash'])) {
                $_SESSION['flash_error'] = '当前密码不正确';
            } elseif ($newPass !== $newPass2) {
                $_SESSION['flash_error'] = '两次输入的新密码不一致';
            } else {
                $res = update_user((int)$user['id'], $username, $newPass !== '' ? $newPass : null);
                if ($res['ok']) {
                    $_SESSION['username'] = $username;
                    $_SESSION['flash_ok'] = '账号信息已更新';
                    log_event('account', '修改账号信息');
                } else {
                    $_SESSION['flash_error'] = $res['message'];
                }
            }
        }
    }
    redirect(base_path('/admin/settings.php'));
}

$siteSettings = [
    'site_name'       => (string)setting('site_name', 'AdminURL'),
    'site_desc'       => (string)setting('site_desc', ''),
    'redirect_type'   => (string)setting('redirect_type', '302'),
    'auto_title'      => (string)setting('auto_title', '1'),
    'public_shorten'  => (string)setting('public_shorten', '0'),
    'public_register' => (string)setting('public_register', '0'),
];

require __DIR__ . '/_header.php';
?>

<div class="settings-grid">

    <!-- 站点设置 -->
    <div class="card">
        <div class="card-head"><h2>站点设置</h2></div>
        <form method="post" class="settings-form" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="site_settings">

            <div class="form-group">
                <label for="site_name">站点名称</label>
                <input type="text" id="site_name" name="site_name" value="<?= e($siteSettings['site_name']) ?>" maxlength="30" required>
            </div>
            <div class="form-group">
                <label for="site_desc">站点描述</label>
                <input type="text" id="site_desc" name="site_desc" value="<?= e($siteSettings['site_desc']) ?>" maxlength="120" placeholder="一句话介绍站点">
            </div>
            <div class="form-group">
                <label for="redirect_type">跳转方式</label>
                <select name="redirect_type" id="redirect_type">
                    <option value="302" <?= $siteSettings['redirect_type'] === '302' ? 'selected' : '' ?>>302 临时跳转（推荐）</option>
                    <option value="301" <?= $siteSettings['redirect_type'] === '301' ? 'selected' : '' ?>>301 永久跳转（利于 SEO）</option>
                </select>
            </div>
            <div class="form-group">
                <label class="switch-row">
                    <input type="checkbox" name="auto_title" <?= $siteSettings['auto_title'] === '1' ? 'checked' : '' ?>>
                    <span class="switch"></span>
                    <span>创建链接时自动抓取标题</span>
                </label>
            </div>
            <button type="submit" class="btn btn-primary">保存设置</button>
        </form>
    </div>

    <!-- 开放使用 -->
    <div class="card">
        <div class="card-head"><h2>开放使用</h2></div>
        <p class="card-desc">默认仅登录管理员可使用本系统。开启后将向公众开放对应能力。</p>
        <form method="post" class="settings-form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="site_settings">

            <div class="form-group">
                <label class="switch-row">
                    <input type="checkbox" name="public_shorten" <?= $siteSettings['public_shorten'] === '1' ? 'checked' : '' ?>>
                    <span class="switch"></span>
                    <span>开放公众缩短链接</span>
                </label>
                <p class="form-help">开启后未登录访客可在首页直接生成短链</p>
            </div>
            <div class="form-group">
                <label class="switch-row">
                    <input type="checkbox" name="public_register" <?= $siteSettings['public_register'] === '1' ? 'checked' : '' ?>>
                    <span class="switch"></span>
                    <span>开放用户注册</span>
                </label>
                <p class="form-help">开启后访客可自行注册账号（不推荐公网开启）</p>
            </div>
            <button type="submit" class="btn btn-primary">保存开关</button>
        </form>

        <div class="notice-box">
            <p><strong>安全提示：</strong>公网部署时建议保持两个开关均关闭，仅管理员使用。</p>
        </div>
    </div>

    <!-- 账号与密码 -->
    <div class="card">
        <div class="card-head"><h2>账号与密码</h2></div>
        <form method="post" class="settings-form" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="account">

            <div class="form-group">
                <label for="acc-username">登录用户名</label>
                <input type="text" id="acc-username" name="username" value="<?= e($adminUser['username'] ?? '') ?>" maxlength="32" required>
            </div>
            <div class="form-group">
                <label for="acc-current">当前密码</label>
                <input type="password" id="acc-current" name="current_password" autocomplete="current-password" required>
            </div>
            <div class="form-group">
                <label for="acc-new">新密码（留空则不变）</label>
                <input type="password" id="acc-new" name="new_password" autocomplete="new-password" minlength="8">
            </div>
            <div class="form-group">
                <label for="acc-new2">确认新密码</label>
                <input type="password" id="acc-new2" name="new_password2" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary">更新账号</button>
        </form>
    </div>

</div>

<?php require __DIR__ . '/_footer.php'; ?>