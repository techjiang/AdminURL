<?php
/**
 * AdminURL 首页
 *
 * - 已登录：直接进入管理后台
 * - 未登录且开放使用开启：展示公开缩短表单
 * - 未登录且开放使用关闭：跳转登录页
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once ROOT . '/includes/schema.php';

if (!is_installed()) {
    redirect(base_path('/install/'));
}

// 已登录用户直接进后台
if (is_logged_in()) {
    redirect(base_path('/admin/'));
}

$publicShorten = (string)setting('public_shorten', '0') === '1';

// 未开放且未登录 → 登录页
if (!$publicShorten) {
    redirect(base_path('/admin/login.php'));
}

$siteName = (string)setting('site_name', 'AdminURL');
$siteDesc = (string)setting('site_desc', '同一个世界，同一个互联网！');
$error    = '';
$shortUrl = '';
$longUrl  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $error = '请求已过期，请刷新页面后重试';
    } else {
        $longUrl = trim((string)($_POST['url'] ?? ''));
        if (!is_valid_url($longUrl)) {
            $error = '请输入有效的 http/https 链接';
        } else {
            $code = generate_unique_code((int)config('code_length', 5));
            $title = '';
            if ((string)setting('auto_title', '1') === '1') {
                $title = fetch_page_title($longUrl, (int)config('fetch_timeout', 6));
            }

            try {
                $stmt = db()->prepare(
                    'INSERT INTO links (code, url, title, user_id, status, created_at, updated_at)
                     VALUES (?, ?, ?, 0, 1, ?, ?)'
                );
                $stmt->execute([$code, $longUrl, $title, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                $shortUrl = short_url($code);
                log_event('public_shorten', '公开创建短链接 ' . $code);
            } catch (Throwable $e) {
                $error = '创建失败，请稍后重试';
            }
        }
    }
}

$pageTitle = $siteName;
require ROOT . '/includes/templates/header.php';
?>

<div class="hero">
    <div class="hero-inner">
        <div class="logo-badge" aria-hidden="true">🔗</div>
        <h1><?= e($siteName) ?></h1>
        <p class="hero-desc"><?= e($siteDesc) ?></p>

        <?php if ($shortUrl !== ''): ?>
            <div class="result-card success">
                <p class="result-label">短链接已生成，复制分享：</p>
                <div class="result-url">
                    <input type="text" id="result-url" value="<?= e($shortUrl) ?>" readonly>
                    <button type="button" class="btn btn-copy" data-copy="#result-url">复制</button>
                </div>
                <p class="result-tip">访问该短链接会自动跳转到目标地址</p>
            </div>
            <a href="/" class="btn btn-ghost">再生成一个</a>
        <?php else: ?>
            <form method="post" action="/" class="shorten-form" autocomplete="off">
                <?= csrf_field() ?>
                <div class="input-wrap">
                    <input type="url" name="url" id="url" placeholder="粘贴长链接，例如 https://example.com/very/long/path" value="<?= e($longUrl) ?>" required maxlength="2048">
                    <button type="submit" class="btn btn-primary">生成短链</button>
                </div>
                <?php if ($error !== ''): ?>
                    <p class="form-error"><?= e($error) ?></p>
                <?php endif; ?>
            </form>
            <p class="hero-note">免费 · 无需注册 · 点击即可访问</p>
        <?php endif; ?>

        <div class="hero-footer">
            <a href="<?= e(base_path('/admin/login.php')) ?>" class="btn btn-ghost btn-sm">管理后台</a>
        </div>
    </div>
</div>

<?php require ROOT . '/includes/templates/footer.php'; ?>