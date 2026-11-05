<?php
/**
 * AdminURL 安装向导
 *
 * 首次访问引导安装：写入配置文件、初始化数据库、创建管理员账号。
 * 已安装后访问本页将重定向到首页。
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT . '/includes/schema.php';

if (is_installed()) {
    redirect(base_path('/'));
}

$error = '';
$done  = false;

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$guessBase = $scheme . '://' . $host;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $error = '请求已过期，请刷新页面后重试';
    } else {
        $siteName  = trim((string)($_POST['site_name'] ?? 'AdminURL'));
        $baseUrl   = rtrim(trim((string)($_POST['base_url'] ?? $guessBase)), '/');
        $username  = trim((string)($_POST['username'] ?? ''));
        $password  = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');

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
                migrate_schema();

                $res = create_user($username, $password, 'admin');
                if (!$res['ok']) {
                    throw new RuntimeException($res['message']);
                }

                set_setting('site_name', $siteName);
                set_setting('base_url', $baseUrl);

                $example = require ROOT . '/config/config.example.php';
                $example['base_url']   = $baseUrl;
                $example['secret_key'] = bin2hex(random_bytes(32));
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

$checks = [
    'PHP >= 8.0'            => version_compare(PHP_VERSION, '8.0.0', '>='),
    'PDO 扩展'               => extension_loaded('pdo'),
    'PDO SQLite 驱动'        => extension_loaded('pdo_sqlite'),
    'SQLite3 扩展'           => extension_loaded('sqlite3'),
    'cURL 扩展（标题抓取）'    => extension_loaded('curl'),
    'OpenSSL 扩展（安全）'     => extension_loaded('openssl'),
    'mbstring 扩展（中文）'    => extension_loaded('mbstring'),
    'data 目录可写'           => is_dir(ROOT . '/data') && is_writable(ROOT . '/data'),
    'config 目录可写'         => is_writable(ROOT . '/config'),
];
$envOk = !in_array(false, $checks, true);

$pageTitle = '安装 AdminURL';
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
            <h2>三步之外，<br><span>即刻拥有短链站。</span></h2>
            <p class="auth-aside-desc">不需要 MySQL、不需要 Composer，甚至不需要联网。上传代码，填好信息，就这么简单。</p>
            <div class="auth-points">
                <div class="auth-point"><?= icon('check') ?> 环境自动检测，依赖一目了然</div>
                <div class="auth-point"><?= icon('database') ?> SQLite 单文件，备份就是复制</div>
                <div class="auth-point"><?= icon('shield') ?> 随机密钥与 Argon2id 密码哈希</div>
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
                <span class="brand-mark"><?= icon('sparkles') ?></span>
                <h1><?= $done ? '安装完成' : '安装 AdminURL' ?></h1>
                <p><?= $done ? '一切就绪，开始使用你的短链接系统。' : '开源短链接系统 · 一分钟完成部署' ?></p>
            </div>

            <?php if ($done): ?>
                <div class="result-card success" style="margin-top:0">
                    <p class="result-label"><?= icon('check', 'icon-sm') ?> 安装成功！</p>
                    <p><strong>站点地址：</strong><?= e((string)setting('base_url', '')) ?></p>
                    <p class="result-tip">管理员账号已创建，可以使用了。</p>
                </div>
                <div class="auth-actions" style="justify-content:flex-start">
                    <a href="<?= e(base_path('/admin/login.php')) ?>" class="btn btn-primary btn-block btn-lg">进入管理后台 <?= icon('arrow-right', 'icon-sm') ?></a>
                </div>
                <p class="auth-tip">安全提示：安装完成后请删除 install 目录，防止未授权重装。</p>
            <?php else: ?>

                <?php if (!$envOk): ?>
                    <div class="alert alert-error">
                        <div>
                            <strong>环境检查未通过</strong>
                            <ul class="env-list" style="margin-top:10px">
                                <?php foreach ($checks as $label => $ok): ?>
                                    <li class="<?= $ok ? '' : 'bad' ?>"><?= icon($ok ? 'check' : 'close', 'icon-sm') ?> <?= e($label) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin:0">请修复上述问题后刷新本页继续。</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="env-ok"><?= icon('check', 'icon-sm') ?> 环境检查全部通过 · PHP <?= e(PHP_VERSION) ?></div>
                <?php endif; ?>

                <?php if ($envOk): ?>
                    <form method="post" class="auth-form">
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
                            <input type="text" id="username" name="username" placeholder="admin" required autocomplete="username">
                        </div>
                        <div class="form-group">
                            <label for="password">管理员密码</label>
                            <input type="password" id="password" name="password" placeholder="至少 8 位" minlength="8" required autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label for="password2">确认密码</label>
                            <input type="password" id="password2" name="password2" placeholder="再次输入密码" minlength="8" required autocomplete="new-password">
                        </div>

                        <?php if ($error !== ''): ?>
                            <p class="form-error"><?= e($error) ?></p>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary btn-block btn-lg"><?= icon('bolt', 'icon-sm') ?> 开始安装</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="auth-foot">&copy; <?= date('Y') ?> AdminURL · by <a href="https://www.asoe.cn" target="_blank" rel="noopener nofollow">科技酱</a></div>
    </main>
</div>

<?php require ROOT . '/includes/templates/footer.php'; ?>
