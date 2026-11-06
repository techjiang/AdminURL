<?php
/**
 * AdminURL 管理后台 - 访问来源分析
 *
 * 数据来自 click_audience（按天 + 来源域名 + 设备聚合），不受点击缓冲队列清空影响。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '访问来源';
$activeNav = 'audience';

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90], true)) {
    $days = 30;
}

$data    = audience_breakdown($days, 10);
$summary = audience_summary($days);
$maxHost = 1;
foreach ($data['hosts'] as $row) {
    $maxHost = max($maxHost, (int)$row['c']);
}
$maxDevice = 1;
foreach ($data['devices'] as $row) {
    $maxDevice = max($maxDevice, (int)$row['c']);
}

$trend = [];
for ($i = 13; $i >= 0; $i--) {
    $trend[date('Y-m-d', strtotime('-' . $i . ' days'))] = 0;
}
foreach ($data['daily'] as $row) {
    if (isset($trend[$row['day']])) {
        $trend[$row['day']] = (int)$row['c'];
    }
}

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>访问来源</h2>
        <p>按来源域名与设备类型聚合点击，数据按天保留，不会随点击缓冲合并而丢失。</p>
    </div>
    <form method="get" class="inline-form">
        <select name="days" aria-label="统计周期">
            <option value="7" <?= $days === 7 ? 'selected' : '' ?>>近 7 天</option>
            <option value="30" <?= $days === 30 ? 'selected' : '' ?>>近 30 天</option>
            <option value="90" <?= $days === 90 ? 'selected' : '' ?>>近 90 天</option>
        </select>
        <button type="submit" class="btn btn-ghost btn-sm">应用</button>
    </form>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-label">点击总量</span>
        <strong class="stat-value"><?= number_format($summary['total']) ?></strong>
        <span class="stat-foot">近 <?= $days ?> 天</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">来源域名</span>
        <strong class="stat-value"><?= number_format($summary['hosts']) ?></strong>
        <span class="stat-foot">含直接访问与站内来源</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">主要设备</span>
        <strong class="stat-value" style="font-size:22px"><?= e($summary['top_device']) ?></strong>
        <span class="stat-foot">按点击量排名第一</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">统计周期</span>
        <strong class="stat-value"><?= $days ?> 天</strong>
        <span class="stat-foot"><a href="<?= e(base_path('/admin/analytics.php')) ?>">前往数据统计</a></span>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= icon('chart') ?> 近 14 天来源点击趋势</h2>
        <span class="chart-total">共 <?= number_format(array_sum($trend)) ?> 次</span>
    </div>
    <div class="chart-wrap"><?= trend_svg($trend, '近 14 天来源点击趋势') ?></div>
</div>

<div class="import-grid" style="margin-top:20px">
    <div class="card">
        <div class="card-head"><h2><?= icon('link') ?> 热门来源</h2></div>
        <?php if (count($data['hosts']) === 0): ?>
            <div class="empty-state"><?= icon('link', 'icon-xl') ?><p>暂无点击数据，短链被访问后会在这里汇总</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>来源</th><th>点击</th><th style="width:38%">占比</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['hosts'] as $row): ?>
                        <tr>
                            <td><?= e($row['host']) ?></td>
                            <td class="td-num"><?= number_format((int)$row['c']) ?></td>
                            <td><div class="bar-track"><div class="bar-fill" style="width:<?= max(2, (int)round((int)$row['c'] * 100 / $maxHost)) ?>%"></div></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2><?= icon('mouse') ?> 设备分布</h2></div>
        <?php if (count($data['devices']) === 0): ?>
            <div class="empty-state"><?= icon('mouse', 'icon-xl') ?><p>暂无设备数据</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>类型</th><th>点击</th><th style="width:38%">占比</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['devices'] as $row): ?>
                        <tr>
                            <td><?= e($row['device']) ?></td>
                            <td class="td-num"><?= number_format((int)$row['c']) ?></td>
                            <td><div class="bar-track"><div class="bar-fill" style="width:<?= max(2, (int)round((int)$row['c'] * 100 / $maxDevice)) ?>%"></div></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="card-desc" style="margin:16px 0 0">设备类型由 User-Agent 归类；无法识别的请求记为「其他」，无 UA 记为「未知」。</p>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
