<?php
/**
 * AdminURL 安装向导
 *
 * 首次访问引导安装：写入配置文件、初始化数据库、创建管理员账号。
 * 已安装后访问本页将重定向到首页。
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT . '/includes/schema.php';

// 已安装则禁止重复安装（防重放攻击）
if (is_installed()) {
    redirect(base_path('/'));
}

$error = '';
$done  = false;

// 推断默认站点地址
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$guessBase = $scheme . '://' . $host;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 防护：安装请求同样需要带会话令牌，防止外部站点诱导触发安装
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $error = '请求已过期，请刷新页面后重试';
    } else {
        $siteName   = trim((string)($_POST['site_name'] ?? 'AdminURL'));
        $baseUrl    = rtrim(trim((string)($_POST['base_url'] ?? $guessBase)), '/');
        $username   = trim((string)($_POST['username'] ?? ''));
        $password   = (string)($_POST['password'] ?? '');
        $password2  = (string)($_POST['password2'] ?? '');
    
        if ($siteName === '' || mb_strlen($siteName) > 30) {
            $error = '站点名称不能为空且不超过 30 字';
        } elseif (!preg_match('#^https?://[^/\s]+$#i', $baseUrl)) {
            $error = '站点地址格式不正确（需要 http(s):// 开头）';
        } elseif (mb_strlen($username) < 2 || mb_strlen($username) > 32) {
            $error = '管理员用户名长度须为 2-32 字符';
        } elseif (mb_strlen($password) < 8) {
            $error = '管理员密码至少 8 位';
        } elseif ($password !== $password2) {
            $error = '两次输入的密码不一致';
        } else {
            try {
                // 1. 初始化数据库
                migrate_schema();
    
                // 2. 创建管理员
                $res = create_user($username, $password, 'admin');
                if (!$res['ok']) {
                    throw new RuntimeException($res['message']);
                }
    
                // 3. 写入站点设置
                set_setting('site_name', $siteName);
                set_setting('base_url', $baseUrl);
    
                // 4. 生成正式配置文件
                $example = require ROOT . '/config/config.example.php';
                $example['base_url']   = $baseUrl;
                $example['secret_key'] = bin2hex(random_bytes(32));
                // 动态确认当前 PHP 可用的哈希算法
                $example['db_file'] = $example['db_file'] ?? (ROOT . '/data/adminurl.db');
    
                $php = "<?php\n\n/**\n * AdminURL 配置文件（安装时自动生成）\n * 请妥善保管本文件，泄露 secret_key 可能导致安全隐患\n */\n\nreturn " . var_export($example, true) . ";\n";
    
                $configFile = ROOT . '/config/config.php';
                if (is_file($configFile) && !is_writable($configFile)) {
                    throw new RuntimeException('config/config.php 不可写，请检查文件权限');
                }
                if (file_put_contents($configFile, $php) === false) {
                    throw new RuntimeException('无法写入 config/config.php，请检查目录权限');
                }
    
                log_event('install', '系统安装完成');
                $done = true;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
    }
    }
}

// 环境检查
$checks = [
    'PHP >= 8.0'            => version_compare(PHP_VERSION, '8.0.0', '>='),
    'PDO 扩展'               => extension_loaded('pdo'),
    'PDO SQLite 驱动'        => extension_loaded('pdo_sqlite'),
    'SQLite3 扩展'           => extension_loaded('sqlite3'),
    'cURL 扩展（标题抓取）'      => extension_loaded('curl'),
    'OpenSSL 扩展（安全）'     => extension_loaded('openssl'),
    'mbstring 扩展（中文）'    => extension_loaded('mbstring'),
    'data 目录可写'           => is_dir(ROOT . '/data') && is_writable(ROOT . '/data'),
    'config 目录可写'         => is_writable(ROOT . '/config'),
];
$envOk = !in_array(false, $checks, true);

$pageTitle = '安装 AdminURL';
require ROOT . '/includes/templates/header.php';
?>

<div class="install-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <div class="logo-badge">🔗</div>
            <h1>安装 AdminURL</h1>
            <p>开源短链接系统 · 一步到位</p>
        </div>

        <?php if ($done): ?>
            <div class="result-card success">
                <p class="result-label">✅ 安装成功！</p>
                <p><strong>站点地址：</strong><?= e(htmlspecialchars((string)setting('base_url', ''), ENT_QUOTES)) ?></p>
                <p class="result-tip">管理员账号已创建，可以使用了。</p>
            </div>
            <div class="auth-actions">
                <a href="<?= e(base_path('/admin/login.php')) ?>" class="btn btn-primary btn-block">进入管理后台</a>
            </div>
            <p class="auth-tip">安全提示：安装完成后请删除 install 目录，防止未授权重装。</p>

        <?php else: ?>

            <?php if (!$envOk): ?>
                <div class="alert alert-error">
                    <strong>环境检查未通过：</strong>
                    <ul>
                        <?php foreach ($checks as $label => $ok): ?>
                            <li><?= $ok ? '✅' : '❌' ?> <?= e($label) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p>请修复上述问题后刷新本页继续。</p>
                </div>
            <?php else: ?>
                <div class="env-ok">✅ 环境检查全部通过（PHP <?= e(PHP_VERSION) ?>）</div>
            <?php endif; ?>

            <?php if ($envOk): ?>
                <form method="post" class="auth-form" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="site_name">站点名称</label>
                        <input type="text" id="site_name" name="site_name" value="AdminURL" maxlength="30" required>
                    </div>
                    <div class="form-group">
                        <label for="base_url">站点地址</label>
                        <input type="text" id="base_url" name="base_url" value="<?= e($guessBase) ?>" maxlength="255" required>
                        <p class="form-help">不带结尾斜杠，例如 https://s.example.com</p>
                    </div>
                    <hr class="form-divider">
                    <div class="form-group">
                        <label for="username">管理员用户名</label>
                        <input type="text" id="username" name="username" placeholder="admin" required>
                    </div>
                    <div class="form-group">
                        <label for="password">管理员密码</label>
                        <input type="password" id="password" name="password" placeholder="至少 8 位" minlength="8" required>
                    </div>
                    <div class="form-group">
                        <label for="password2">确认密码</label>
                        <input type="password" id="password2" name="password2" minlength="8" required>
                    </div>

                    <?php if ($error !== ''): ?>
                        <p class="form-error"><?= e($error) ?></p>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary btn-block">开始安装</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require ROOT . '/includes/templates/footer.php'; ?>