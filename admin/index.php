<?php
/**
 * AdminURL 管理后台 - 仪表盘
 *
 * 展示核心统计、近 14 天趋势、快捷创建与最近链接。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '仪表盘';
$activeNav = 'dashboard';

// 统计
$stats = ['total' => 0, 'clicks' => 0, 'today' => 0, 'pending' => 0];
$expiryStats = ['expired' => 0, 'expiring' => 0];
$healthStats = ['total' => 0, 'ok' => 0, 'bad' => 0, 'unchecked' => 0];
try {
    $stats['total']   = (int)db()->query('SELECT COUNT(*) AS c FROM links')->fetch()['c'];
    $stats['clicks']  = live_total_clicks();
    $todayStmt = db()->prepare('SELECT COUNT(*) AS c FROM links WHERE created_at >= ?');
    $todayStmt->execute([date('Y-m-d 00:00:00')]);
    $stats['today']   = (int)$todayStmt->fetch()['c'];
    $stats['pending'] = (int)db()->query('SELECT COUNT(*) AS c FROM click_logs')->fetch()['c'];
    $expiryStats      = link_expiry_stats();
    $healthStats      = health_summary();
} catch (Throwable $e) {
    // 表未初始化
}

// 近 14 天创建趋势
$trend = [];
for ($i = 13; $i >= 0; $i--) {
    $trend[date('Y-m-d', strtotime('-' . $i . ' days'))] = 0;
}
$trendStart = array_key_first($trend);
try {
    $stmt = db()->prepare('SELECT substr(created_at,1,10) AS d, COUNT(*) AS c FROM links WHERE created_at >= ? GROUP BY d');
    $stmt->execute([$trendStart . ' 00:00:00']);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($trend[$r['d']])) {
            $trend[$r['d']] = (int)$r['c'];
        }
    }
} catch (Throwable $e) {
}
$trendMax = max(1, max($trend));
$trendSum = array_sum($trend);

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
        $longUrl    = trim((string)($_POST['url'] ?? ''));
        $customCode = trim((string)($_POST['code'] ?? ''));
        $expiry     = validate_expiry_input((string)($_POST['expires_at'] ?? ''));
        $newTag     = normalize_tag((string)($_POST['tag'] ?? ''));
        $newPwdRaw  = trim((string)($_POST['access_password'] ?? ''));
        $newPwdChk  = validate_link_password($newPwdRaw);

        if (!is_valid_url($longUrl)) {
            $_SESSION['flash_error'] = '请输入有效的 http/https 链接';
        } elseif (!$expiry['ok']) {
            $_SESSION['flash_error'] = $expiry['error'];
        } elseif (!$newPwdChk['ok']) {
            $_SESSION['flash_error'] = $newPwdChk['error'];
        } elseif ($customCode !== '' && !preg_match('/^[A-Za-z0-9]{3,32}$/', $customCode)) {
            $_SESSION['flash_error'] = '自定义短码仅支持 3-32 位字母或数字';
        } elseif ($customCode !== '' && is_reserved_code($customCode)) {
            $_SESSION['flash_error'] = '该短码是系统保留路径，请换一个';
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
                    'INSERT INTO links (code, url, title, user_id, status, expires_at, tag, access_password, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $code, $longUrl, $title, (int)$adminUser['id'], $expiry['value'], $newTag,
                    $newPwdRaw !== '' ? hash_link_password($newPwdRaw) : null,
                    date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
                ]);
                $createUrl = short_url($code);
                $createOk  = true;
                log_event('shorten', '创建短链接 ' . $code . ' -> ' . mb_substr($longUrl, 0, 120));
            } catch (Throwable $e) {
                $_SESSION['flash_error'] = '创建失败，请稍后重试';
            }
        }
    }
    if (!$createOk) {
        redirect(base_path('/admin/'));
    }
}

// 生成趋势图 SVG
require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>概览</h2>
        <p>欢迎回来，<?= e($adminUser['username'] ?? '') ?>。这里是站点的最新动态。</p>
    </div>
    <a href="#quick-create" class="btn btn-primary"><?= icon('plus', 'icon-sm') ?> 新建短链</a>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-icon"><?= icon('link') ?></span>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($stats['total']) ?></div>
            <div class="stat-label">短链接总数</div>
        </div>
        <span class="stat-trend">全部记录</span>
    </div>
    <div class="stat-card">
        <span class="stat-icon green"><?= icon('mouse') ?></span>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($stats['clicks']) ?></div>
            <div class="stat-label">累计点击量</div>
        </div>
        <span class="stat-trend">已合并统计</span>
    </div>
    <div class="stat-card">
        <span class="stat-icon orange"><?= icon('sparkles') ?></span>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($stats['today']) ?></div>
            <div class="stat-label">今日新增</div>
        </div>
        <span class="stat-trend"><?= date('m 月 d 日') ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-icon violet"><?= icon('clock') ?></span>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($stats['pending']) ?></div>
            <div class="stat-label">待合并点击</div>
        </div>
        <span class="stat-trend">缓冲队列</span>
    </div>
</div>

<?php $attentionTotal = (int)$expiryStats['expired'] + (int)$expiryStats['expiring']
    + (int)$healthStats['bad'] + (int)$healthStats['unchecked']; ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-head">
        <h2><?= icon('shield') ?> 需要关注</h2>
        <span class="chart-total"><?= $attentionTotal > 0 ? '共 ' . $attentionTotal . ' 项需要跟进' : '当前一切正常' ?></span>
    </div>
    <div class="stat-grid" style="margin-bottom:0">
        <a class="stat-card" style="text-decoration:none;color:inherit" href="<?= e(base_path('/admin/links.php?status=expired')) ?>">
            <span class="stat-label">已过期短链</span>
            <strong class="stat-value"><?= number_format((int)$expiryStats['expired']) ?></strong>
            <span class="stat-foot">查看已过期链接 →</span>
        </a>
        <a class="stat-card" style="text-decoration:none;color:inherit" href="<?= e(base_path('/admin/links.php?status=expiring')) ?>">
            <span class="stat-label">7 天内到期</span>
            <strong class="stat-value"><?= number_format((int)$expiryStats['expiring']) ?></strong>
            <span class="stat-foot">提前续期或替换 →</span>
        </a>
        <a class="stat-card" style="text-decoration:none;color:inherit" href="<?= e(base_path('/admin/links.php?status=bad')) ?>">
            <span class="stat-label">目标异常</span>
            <strong class="stat-value"><?= number_format((int)$healthStats['bad']) ?></strong>
            <span class="stat-foot">来自健康检查 →</span>
        </a>
        <a class="stat-card" style="text-decoration:none;color:inherit" href="<?= e(base_path('/admin/health.php')) ?>">
            <span class="stat-label">尚未检测</span>
            <strong class="stat-value"><?= number_format((int)$healthStats['unchecked']) ?></strong>
            <span class="stat-foot">前往健康检查 →</span>
        </a>
    </div>
</div>

<div class="dash-grid">
    <div class="card">
        <div class="card-head">
            <h2><?= icon('chart') ?> 近 14 天创建趋势</h2>
            <span class="chart-total">共新增 <?= number_format($trendSum) ?> 条</span>
        </div>
        <div class="chart-wrap">
            <?= trend_svg($trend, '近 14 天短链创建趋势') ?>
        </div>
    </div>

    <div>
        <div class="card" id="quick-create">
            <div class="card-head">
                <h2><?= icon('bolt') ?> 快捷创建</h2>
                <span class="badge">一键生成</span>
            </div>
            <p class="card-desc">粘贴长链接，立即得到一条可分享的短址。短码留空将自动生成。</p>
            <form method="post" class="quick-form" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="quick_create">
                <input type="url" name="url" placeholder="https://example.com/very/long/path" required maxlength="2048">
                <input type="text" name="code" placeholder="自定义短码（可选）" maxlength="32" pattern="[A-Za-z0-9]{3,32}">
                <input type="datetime-local" name="expires_at" title="有效期（留空永久有效）" aria-label="有效期（可选）">
                <input type="text" name="tag" placeholder="分组标签（可选）" maxlength="32" aria-label="分组标签（可选）">
                <input type="password" name="access_password" placeholder="访问密码（可选）" maxlength="128" autocomplete="new-password" aria-label="访问密码（可选）">
                <button type="submit" class="btn btn-primary"><?= icon('plus', 'icon-sm') ?> 生成短链</button>
                <p class="form-help" style="flex:1 1 100%;margin-top:-2px">短码、有效期、分组与访问密码均可留空：留空时自动生成短码、链接永久有效、未分组且无需密码。</p>
            </form>

            <?php if ($createOk && $createUrl !== ''): ?>
                <div class="quick-create-result">
                    <p class="result-label"><?= icon('check', 'icon-sm') ?> 创建成功，复制分享吧</p>
                    <div class="result-url">
                        <input type="text" id="new-short-url" value="<?= e($createUrl) ?>" readonly>
                        <button type="button" class="btn btn-copy" data-copy="#new-short-url"><?= icon('copy', 'icon-sm') ?> 复制</button>
                    </div>
                    <?php if ($expiry['value'] !== null): ?>
                        <p class="result-tip">有效期至 <?= e($expiry['value']) ?>，到期后自动停止跳转。</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-top:20px">
            <div class="card-head"><h2><?= icon('info') ?> 运行状态</h2></div>
            <div class="kv-list">
                <div class="kv-row"><span>系统版本</span><strong>v<?= e(ADMINURL_VERSION) ?></strong></div>
                <div class="kv-row"><span>运行环境</span><strong>PHP <?= e(PHP_VERSION) ?></strong></div>
                <div class="kv-row"><span>数据库</span><strong>SQLite</strong></div>
                <div class="kv-row"><span>短链形式</span><strong class="mono"><?= e(rtrim((string)config('base_url', ''), '/') . short_path_prefix() . '{code}') ?></strong></div>
                <div class="kv-row"><span>跳转方式</span><strong><?= e((string)setting('redirect_type', '302')) ?></strong></div>
                <div class="kv-row"><span>已过期 / 7 天内到期</span><strong><?= (int)$expiryStats['expired'] ?> / <?= (int)$expiryStats['expiring'] ?></strong></div>
                <div class="kv-row"><span>开放使用</span>
                    <?= (string)setting('public_shorten', '0') === '1' ? '<span class="badge badge-ok">已开启</span>' : '<span class="badge">仅管理员</span>' ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-head">
        <h2><?= icon('link') ?> 最近创建的链接</h2>
        <a href="<?= e(base_path('/admin/links.php')) ?>" class="btn btn-ghost btn-sm">查看全部 <?= icon('arrow-right', 'icon-sm') ?></a>
    </div>

    <?php if (count($recent) === 0): ?>
        <div class="empty-state">
            <?= icon('link', 'icon-xl') ?>
            <p>还没有短链接，用上方的「快捷创建」生成第一条吧</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>短码</th>
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
                            <a href="<?= e(short_url($row['code'])) ?>" target="_blank" rel="noopener" class="code-chip"><?= e($row['code']) ?></a>
                            <button type="button" class="link-copy" data-copy-url="<?= e(short_url($row['code'])) ?>" title="复制短链"><?= icon('copy', 'icon-sm') ?></button>
                        </td>
                        <td class="td-title" title="<?= e($row['url']) ?>"><?= e($row['title'] !== '' ? $row['title'] : '—') ?></td>
                        <td class="td-url" title="<?= e($row['url']) ?>"><a href="<?= e($row['url']) ?>" target="_blank" rel="noopener nofollow"><?= e(mb_substr($row['url'], 0, 60)) ?></a></td>
                        <td class="td-num"><?= number_format((int)$row['clicks']) ?></td>
                        <td class="td-time"><?= e($row['created_at']) ?></td>
                        <td class="td-actions">
                            <a href="<?= e(base_path('/admin/links.php?action=edit&id=' . (int)$row['id'])) ?>" class="btn btn-ghost btn-sm"><?= icon('edit', 'icon-sm') ?> 编辑</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
