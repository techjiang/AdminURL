<?php
/**
 * AdminURL 管理后台 - 仪表盘
 *
 * 展示核心统计与快捷创建短链。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '仪表盘';
$activeNav = 'dashboard';

// 统计
$stats = [
    'total'   => 0,
    'clicks'  => 0,
    'today'   => 0,
    'pending' => 0,
];
try {
    $stats['total'] = (int)db()->query('SELECT COUNT(*) AS c FROM links')->fetch()['c'];
    $stats['clicks'] = (int)db()->query('SELECT COALESCE(SUM(clicks),0) AS c FROM links')->fetch()['c'];
    $stats['today'] = (int)db()->query(
        "SELECT COUNT(*) AS c FROM links WHERE date(created_at) = date('now')"
    )->fetch()['c'];
    $stats['pending'] = (int)db()->query('SELECT COUNT(*) AS c FROM click_logs')->fetch()['c'];
} catch (Throwable $e) {
    // 表未初始化
}

// 最近 8 条链接
$recent = [];
try {
    $recent = db()->query('SELECT * FROM links ORDER BY id DESC LIMIT 8')->fetchAll();
} catch (Throwable $e) {
}

// 创建短链
$createOk = false;
$createUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'quick_create') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $_SESSION['flash_error'] = '请求已过期，请刷新页面后重试';
    } else {
        $longUrl = trim((string)($_POST['url'] ?? ''));
        $customCode = trim((string)($_POST['code'] ?? ''));

        if (!is_valid_url($longUrl)) {
            $_SESSION['flash_error'] = '请输入有效的 http/https 链接';
        } elseif ($customCode !== '' && !preg_match('/^[A-Za-z0-9]{3,32}$/', $customCode)) {
            $_SESSION['flash_error'] = '自定义短码仅支持 3-32 位字母或数字';
        } elseif ($customCode !== '' && link_exists($customCode)) {
            $_SESSION['flash_error'] = '该短码已被占用，请换一个';
        } else {
            $code = $customCode !== '' ? $customCode : generate_unique_code((int)config('code_length', 5));
            $title = '';
            if ((string)setting('auto_title', '1') === '1') {
                $title = fetch_page_title($longUrl, (int)config('fetch_timeout', 6));
            }

            try {
                $stmt = db()->prepare(
                    'INSERT INTO links (code, url, title, user_id, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 1, ?, ?)'
                );
                $stmt->execute([$code, $longUrl, $title, (int)$adminUser['id'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                $createUrl = short_url($code);
                $createOk = true;
                log_event('shorten', '创建短链接 ' . $code . ' -> ' . mb_substr($longUrl, 0, 120));
            } catch (Throwable $e) {
                $_SESSION['flash_error'] = '创建失败，请稍后重试';
            }
        }
    }
    // 非 AJAX 场景下刷新展示
    if (!$createOk) {
        redirect(base_path('/admin/'));
    }
}

require __DIR__ . '/_header.php';
?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon">🔗</div>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($stats['total']) ?></div>
            <div class="stat-label">短链接总数</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">👆</div>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($stats['clicks']) ?></div>
            <div class="stat-label">总点击量</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📅</div>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($stats['today']) ?></div>
            <div class="stat-label">今日新增</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">⏳</div>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($stats['pending']) ?></div>
            <div class="stat-label">待合并计数</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>快捷创建短链</h2>
    </div>
    <form method="post" class="inline-form" id="quick-create" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="quick_create">
        <div class="input-wrap">
            <input type="url" name="url" placeholder="输入长链接，例如 https://example.com/very/long/path" required maxlength="2048">
            <input type="text" name="code" class="code-input" placeholder="自定义短码（可选）" maxlength="32" pattern="[A-Za-z0-9]{3,32}">
            <button type="submit" class="btn btn-primary">生 成</button>
        </div>
    </form>

    <?php if ($createOk && $createUrl !== ''): ?>
        <div class="result-card success">
            <p class="result-label">✅ 短链接已生成：</p>
            <div class="result-url">
                <input type="text" id="new-short-url" value="<?= e($createUrl) ?>" readonly>
                <button type="button" class="btn btn-copy" data-copy="#new-short-url">复制</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2>最近创建的链接</h2>
        <a href="<?= e(base_path('/admin/links.php')) ?>" class="btn btn-ghost btn-sm">查看全部</a>
    </div>

    <?php if (count($recent) === 0): ?>
        <div class="empty-state">
            <p>还没有短链接，先在上面创建第一个吧 🎉</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>短链</th>
                    <th>标题</th>
                    <th>目标地址</th>
                    <th>点击</th>
                    <th>创建时间</th>
                    <th class="th-actions">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td>
                            <a href="<?= e(short_url($row['code'])) ?>" target="_blank" rel="noopener" class="mono"><?= e($row['code']) ?></a>
                            <button type="button" class="link-copy" data-copy-url="<?= e(short_url($row['code'])) ?>">复制</button>
                        </td>
                        <td class="td-title" title="<?= e($row['url']) ?>"><?= e($row['title'] !== '' ? $row['title'] : '—') ?></td>
                        <td class="td-url" title="<?= e($row['url']) ?>"><a href="<?= e($row['url']) ?>" target="_blank" rel="noopener nofollow"><?= e(mb_substr($row['url'], 0, 60)) ?></a></td>
                        <td class="td-num"><?= number_format((int)$row['clicks']) ?></td>
                        <td class="td-time"><?= e($row['created_at']) ?></td>
                        <td class="td-actions">
                            <a href="<?= e(base_path('/admin/links.php?action=edit&id=' . (int)$row['id'])) ?>" class="btn btn-ghost btn-sm">编辑</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>