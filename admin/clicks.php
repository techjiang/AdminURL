<?php
/**
 * AdminURL 管理后台 - 访问明细
 *
 * 展示最近一次具体访问的短链、来源、设备与脱敏 IP；明细按时间倒序保留固定条数。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '访问明细';
$activeNav = 'clicks';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'clear_events') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $_SESSION['flash_error'] = '请求已过期，请刷新页面后重试';
    } else {
        $count = click_events_clear();
        $_SESSION['flash_ok'] = '已清空 ' . $count . ' 条访问明细';
        log_event('clicks_clear', '清空访问明细，共 ' . $count . ' 条');
    }
    redirect(base_path('/admin/clicks.php'));
}

$q       = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$listing = click_events_list($q, $page, $perPage);
$summary = click_events_summary();
$page    = min($page, max(1, $listing['pages']));
$query   = $q !== '' ? '&q=' . urlencode($q) : '';

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>访问明细</h2>
        <p>查看最近每一次跳转的来源、设备与时间；IP 已脱敏，仅用于排查异常访问。</p>
    </div>
    <a href="<?= e(base_path('/admin/audience.php')) ?>" class="btn btn-ghost btn-sm"><?= icon('chart', 'icon-sm') ?> 访问来源汇总</a>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-label">明细总数</span>
        <strong class="stat-value"><?= number_format($summary['total']) ?></strong>
        <span class="stat-foot">最多保留 <?= number_format(CLICK_EVENTS_KEEP) ?> 条</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">今日访问</span>
        <strong class="stat-value"><?= number_format($summary['today']) ?></strong>
        <span class="stat-foot">按站点时区统计</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">来源域名</span>
        <strong class="stat-value"><?= number_format($summary['hosts']) ?></strong>
        <span class="stat-foot">含直接访问 / 站内来源</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">设备类型</span>
        <strong class="stat-value"><?= number_format($summary['devices']) ?></strong>
        <span class="stat-foot">由 User-Agent 归类</span>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= icon('clock') ?> 最近访问 <span class="count-badge"><?= number_format($listing['total']) ?></span></h2>
        <form method="get" class="search-form">
            <div class="search-box">
                <?= icon('search') ?>
                <input type="search" name="q" placeholder="搜索短码 / 来源 / 设备" value="<?= e($q) ?>">
            </div>
            <button type="submit" class="btn btn-ghost btn-sm">搜索</button>
            <?php if ($q !== ''): ?>
                <a href="<?= e(base_path('/admin/clicks.php')) ?>" class="btn btn-ghost btn-sm">清除</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (count($listing['rows']) === 0): ?>
        <div class="empty-state">
            <?= icon('clock', 'icon-xl') ?>
            <p><?= $q !== '' ? '没有匹配的访问记录，换个关键词试试' : '还没有访问记录，短链被打开后会显示在这里' ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>访问时间</th>
                    <th>短链</th>
                    <th>来源</th>
                    <th>设备</th>
                    <th>IP（脱敏）</th>
                    <th>User-Agent</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($listing['rows'] as $row): ?>
                    <tr>
                        <td class="td-time"><?= e($row['created_at']) ?></td>
                        <td><a href="<?= e(base_path('/admin/links.php?q=' . urlencode((string)$row['code']))) ?>"
                               class="code-chip"><?= e($row['code']) ?></a></td>
                        <td><?= e($row['host']) ?></td>
                        <td><span class="badge badge-ok"><?= e($row['device']) ?></span></td>
                        <td class="mono"><?= e(mask_ip((string)$row['ip'])) ?></td>
                        <td class="td-url" title="<?= e($row['ua']) ?>"><?= e($row['ua'] !== '' ? mb_substr((string)$row['ua'], 0, 42) : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($listing['pages'] > 1): ?>
            <div class="pagination">
                <span class="page-info">共 <?= number_format($listing['total']) ?> 条 · 每页 <?= $perPage ?> 条</span>
                <a class="btn btn-ghost btn-sm <?= $page <= 1 ? 'disabled' : '' ?>"
                   href="<?= e(base_path('/admin/clicks.php?p=' . max(1, $page - 1) . $query)) ?>"><?= icon('arrow-left', 'icon-sm') ?> 上一页</a>
                <span class="page-info"><?= $page ?> / <?= $listing['pages'] ?></span>
                <a class="btn btn-ghost btn-sm <?= $page >= $listing['pages'] ? 'disabled' : '' ?>"
                   href="<?= e(base_path('/admin/clicks.php?p=' . min($listing['pages'], $page + 1) . $query)) ?>">下一页 <?= icon('arrow-right', 'icon-sm') ?></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="import-grid" style="margin-top:20px">
    <div class="card">
        <div class="card-head"><h2><?= icon('info') ?> 数据说明</h2></div>
        <ul class="import-list">
            <li>明细来自短链成功跳转时的实时写入，受密码保护但未授权的访客不会计入。</li>
            <li>只保留最近 <?= number_format(CLICK_EVENTS_KEEP) ?> 条，超出后自动裁剪最旧记录，不会无限占用空间。</li>
            <li>「访问来源」页是长期按天聚合的统计，即使明细被裁剪或清空也不受影响。</li>
            <li>IP 在页面上脱敏展示，完整值仅存于本地数据库且不会被导出。</li>
        </ul>
    </div>

    <div class="card">
        <div class="card-head"><h2><?= icon('trash') ?> 清空明细</h2></div>
        <p class="card-desc">清空后仅删除明细记录，不会改动短链、累计点击数与按天聚合的来源统计。</p>
        <form method="post" onsubmit="return confirm('确定清空全部访问明细吗？此操作不可恢复。')">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="clear_events">
            <button type="submit" class="btn btn-danger btn-sm">清空访问明细</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
