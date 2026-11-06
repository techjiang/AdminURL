<?php
/**
 * AdminURL 管理后台 - 数据统计
 *
 * 全站点击趋势、核心指标与热门短链排行。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '数据统计';
$activeNav = 'analytics';

$daily   = daily_clicks();
$summary = click_summary();

$totalClicks = 0;
$activeLinks = 0;
$topLinks    = [];
try {
    $totalClicks = live_total_clicks();
    $activeLinks = (int)db()->query('SELECT COUNT(*) AS c FROM links WHERE status = 1')->fetch()['c'];
    $topLinks    = db()->query('SELECT * FROM links ORDER BY clicks DESC, id DESC LIMIT 10')->fetchAll();
} catch (Throwable $e) {
    // 表未初始化
}

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>数据统计</h2>
        <p>全站点击趋势与热门短链，数据按日聚合并长期保留。</p>
    </div>
    <a href="<?= e(base_path('/admin/links.php')) ?>" class="btn btn-ghost btn-sm"><?= icon('layers', 'icon-sm') ?> 全部链接</a>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-icon"><?= icon('mouse') ?></span>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($totalClicks) ?></div>
            <div class="stat-label">累计点击量</div>
        </div>
        <span class="stat-trend">含历史数据</span>
    </div>
    <div class="stat-card">
        <span class="stat-icon green"><?= icon('bolt') ?></span>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($summary['today']) ?></div>
            <div class="stat-label">今日点击</div>
        </div>
        <span class="stat-trend"><?= date('m 月 d 日') ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-icon orange"><?= icon('chart') ?></span>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($summary['week']) ?></div>
            <div class="stat-label">近 7 天点击</div>
        </div>
        <span class="stat-trend">日均 <?= number_format((int)round($summary['week'] / 7)) ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-icon violet"><?= icon('link') ?></span>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($activeLinks) ?></div>
            <div class="stat-label">正常链接</div>
        </div>
        <span class="stat-trend">状态启用中</span>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= icon('trend') ?> 近 14 天点击趋势</h2>
        <span class="chart-total">区间合计 <?= number_format($summary['total']) ?> 次</span>
    </div>
    <div class="chart-wrap"><?= trend_svg($daily, '近 14 天点击趋势') ?></div>
    <p class="form-help" style="margin-top:12px">按日聚合的点击数据自 v2.3 起记录；「累计点击量」包含升级前的历史次数。</p>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= icon('sparkles') ?> 热门短链 Top 10</h2>
        <span class="count-badge"><?= count($topLinks) ?></span>
    </div>

    <?php if (count($topLinks) === 0): ?>
        <div class="empty-state">
            <?= icon('chart', 'icon-xl') ?>
            <p>还没有短链接，创建后这里会显示点击排行</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th>#</th><th>短码</th><th>标题</th><th>点击</th><th>状态</th><th class="th-actions">操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($topLinks as $i => $row): ?>
                    <tr>
                        <td class="td-num muted"><?= $i + 1 ?></td>
                        <td>
                            <a href="<?= e(short_url($row['code'])) ?>" target="_blank" rel="noopener" class="code-chip"><?= e($row['code']) ?></a>
                        </td>
                        <td class="td-title"><?= e($row['title'] !== '' ? $row['title'] : '—') ?></td>
                        <td class="td-num"><?= number_format((int)$row['clicks']) ?></td>
                        <td>
                            <?php $meta = link_status_meta($row); ?>
                            <span class="badge <?= e($meta['class']) ?>"><?= e($meta['label']) ?></span>
                        </td>
                        <td class="td-actions">
                            <a href="<?= e(base_path('/admin/link.php?id=' . (int)$row['id'])) ?>" class="btn btn-ghost btn-sm">查看趋势 <?= icon('arrow-right', 'icon-sm') ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
