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

ensure_lifecycle_schema();

if (is_logged_in()) {
    redirect(base_path('/admin/'));
}

$publicShorten = (string)setting('public_shorten', '0') === '1';

if (!$publicShorten) {
    redirect(base_path('/admin/login.php'));
}

$siteName = (string)setting('site_name', 'AdminURL');
$siteDesc = (string)setting('site_desc', '同一个世界，同一个互联网！');
$error    = '';
$shortUrl = '';
$longUrl  = '';
$customCode = '';
$expiryRaw = '';
$expiryValue = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $error = '请求已过期，请刷新页面后重试';
    } else {
        $longUrl    = trim((string)($_POST['url'] ?? ''));
        $customCode = trim((string)($_POST['code'] ?? ''));
        $expiryRaw  = trim((string)($_POST['expires_at'] ?? ''));
        $expiry     = validate_expiry_input($expiryRaw);

        if (!is_valid_url($longUrl)) {
            $error = '请输入有效的 http/https 链接';
        } elseif (!$expiry['ok']) {
            $error = $expiry['error'];
        } elseif ($customCode !== '' && !preg_match('/^[A-Za-z0-9]{3,32}$/', $customCode)) {
            $error = '自定义短码仅支持 3-32 位字母或数字';
        } elseif ($customCode !== '' && is_reserved_code($customCode)) {
            $error = '该短码是系统保留路径，请换一个';
        } elseif ($customCode !== '' && link_exists($customCode)) {
            $error = '这个短码已被占用，换一个试试';
        } else {
            $code  = $customCode !== '' ? $customCode : generate_unique_code((int)config('code_length', 5));
            $title = '';
            if ((string)setting('auto_title', '1') === '1') {
                $title = fetch_page_title($longUrl, (int)config('fetch_timeout', 6));
            }

            try {
                $stmt = db()->prepare(
                    'INSERT INTO links (code, url, title, user_id, status, expires_at, created_at, updated_at)
                     VALUES (?, ?, ?, 0, 1, ?, ?, ?)'
                );
                $stmt->execute([$code, $longUrl, $title, $expiry['value'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                $shortUrl = short_url($code);
                $expiryValue = $expiry['value'];
                log_event('public_shorten', '公开创建短链接 ' . $code);
            } catch (Throwable $e) {
                $error = '创建失败，请稍后重试';
            }
        }
    }
}

// 首页公开统计（不影响隐私，仅展示聚合数字）
$publicStats = ['links' => 0, 'clicks' => 0, 'days' => 0];
try {
    $publicStats['links']  = (int)db()->query('SELECT COUNT(*) AS c FROM links')->fetch()['c'];
    $publicStats['clicks'] = (int)db()->query('SELECT COALESCE(SUM(clicks),0) AS c FROM links')->fetch()['c'];
    $first = db()->query('SELECT MIN(created_at) AS d FROM links')->fetch()['d'] ?? null;
    if ($first) {
        $publicStats['days'] = max(1, (int)floor((time() - strtotime($first)) / 86400) + 1);
    }
} catch (Throwable $e) {
    // 数据表尚未初始化时忽略
}

$pageTitle = $siteName;
require ROOT . '/includes/templates/header.php';
?>

<section class="hero">
    <div class="hero-eyebrow"><span class="pulse-dot"></span> 开源 · 自托管 · 零依赖</div>
    <h1 class="hero-title">让每一条链接<br><span class="hero-grad">都更短一点</span></h1>
    <p class="hero-sub"><?= e($siteDesc) ?><br>粘贴长链接，一键生成清爽短址，跳转统计与安全策略尽在掌握。</p>

    <?php if ($shortUrl !== ''): ?>
        <div class="result-panel">
            <div class="result-panel-inner">
                <span class="result-check"><?= icon('check') ?></span>
                <h2>短链接已生成</h2>
                <p class="result-label">复制下面的链接，分享给任何人</p>
                <div class="result-url">
                    <input type="text" id="result-url" value="<?= e($shortUrl) ?>" readonly>
                    <button type="button" class="btn btn-copy" data-copy="#result-url"><?= icon('copy', 'icon-sm') ?> 复制</button>
                    <a href="<?= e($shortUrl) ?>" target="_blank" rel="noopener" class="btn btn-ghost" title="在新窗口打开"><?= icon('external', 'icon-sm') ?></a>
                </div>
                <p class="result-target">目标地址：<?= e($longUrl) ?></p>
                <?php if ($expiryValue !== null): ?>
                    <p class="result-target">有效期至 <?= e($expiryValue) ?>，到期后自动停止跳转</p>
                <?php endif; ?>
                <div class="btn-row">
                    <a href="<?= e(base_path('/')) ?>" class="btn btn-primary"><?= icon('plus', 'icon-sm') ?> 再生成一个</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="hero-card">
            <form method="post" action="<?= e(base_path('/')) ?>" class="shorten-form" autocomplete="off">
                <?= csrf_field() ?>
                <div class="input-wrap">
                    <input type="url" name="url" id="url" placeholder="粘贴长链接，例如 https://example.com/very/long/path" value="<?= e($longUrl) ?>" required maxlength="2048" autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-lg"><?= icon('bolt', 'icon-sm') ?> 生成短链</button>
                <details class="hero-advanced" <?= $customCode !== '' || $expiryRaw !== '' ? 'open' : '' ?>>
                    <summary><?= icon('settings', 'icon-sm') ?> 自定义短码（可选）</summary>
                    <div class="hero-advanced-body">
                        <span class="mono"><?= e(rtrim((string)config('base_url', ''), '/') . short_path_prefix()) ?></span>
                        <input type="text" name="code" value="<?= e($customCode) ?>" placeholder="例如 mylink" maxlength="32" pattern="[A-Za-z0-9]{3,32}">
                        <span class="form-help">3-32 位字母或数字</span>
                        <span class="form-help" style="flex:1 1 100%;margin:4px 0 0">有效期（可选）：留空永久有效，到期后短链自动停止跳转</span>
                        <input type="datetime-local" name="expires_at" value="<?= e(expiry_for_input($expiryRaw)) ?>" aria-label="有效期（可选）">
                    </div>
                </details>
                <?php if ($error !== ''): ?>
                    <p class="form-error-inside"><?= e($error) ?></p>
                <?php endif; ?>
            </form>
        </div>
        <p class="hero-note"><?= icon('lock', 'icon-sm') ?> 免费使用 · 无需注册 · 链接仅在本站跳转</p>
    <?php endif; ?>

    <div class="hero-stats">
        <div class="hero-stat"><strong><?= number_format($publicStats['links']) ?></strong><span>已生成短链</span></div>
        <div class="hero-stat"><strong><?= number_format($publicStats['clicks']) ?></strong><span>累计跳转</span></div>
        <div class="hero-stat"><strong><?= $publicStats['days'] > 0 ? $publicStats['days'] . ' 天' : '今天' ?></strong><span>稳定运行</span></div>
    </div>
</section>

<section class="features" id="features">
    <article class="feature-card">
        <span class="feature-icon"><?= icon('bolt') ?></span>
        <h3>秒级跳转，数据可见</h3>
        <p>基于 PHP + SQLite 的轻量架构，301 / 302 自由切换，点击量、来源与趋势一目了然。</p>
    </article>
    <article class="feature-card">
        <span class="feature-icon green"><?= icon('shield') ?></span>
        <h3>私密可控，默认安全</h3>
        <p>Argon2id 密码哈希、CSRF 防护、登录锁定与 UA 屏蔽，开放使用与注册均可独立开关。</p>
    </article>
    <article class="feature-card">
        <span class="feature-icon orange"><?= icon('code') ?></span>
        <h3>完整 API，随时集成</h3>
        <p>REST 接口配合令牌鉴权与限流，支持创建、查询、更新、删除与统计，方便二次开发。</p>
    </article>
</section>

<?php require ROOT . '/includes/templates/footer.php'; ?>
