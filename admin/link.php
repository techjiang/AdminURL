<?php
/**
 * AdminURL 管理后台 - 单条链接详情
 *
 * 展示链接信息、点击指标与近 14 天趋势。
 */

require_once __DIR__ . '/_init.php';

$id = (int)($_GET['id'] ?? 0);
$link = false;

if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM links WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $link = $stmt->fetch();
}

if ($link === false) {
    $_SESSION['flash_error'] = '短链接不存在或已被删除';
    redirect(base_path('/admin/links.php'));
}

$pageTitle = '链接详情';
$activeNav = 'links';

$shortUrl = short_url((string)$link['code']);
$daily    = daily_clicks((int)$link['id']);
$summary  = click_summary((int)$link['id']);

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>链接详情 <span class="code-chip" style="margin-left:8px"><?= e($link['code']) ?></span></h2>
        <p>查看这条短链的访问数据与配置。</p>
    </div>
    <a href="<?= e(base_path('/admin/links.php')) ?>" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 'icon-sm') ?> 返回列表</a>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= icon('info') ?> 链接信息</h2>
        <?php if ((int)$link['status'] === 1): ?>
            <span class="badge badge-ok">正常</span>
        <?php else: ?>
            <span class="badge badge-warn">已停用</span>
        <?php endif; ?>
    </div>

    <label class="field-label">短链接</label>
    <div class="result-url" style="max-width:520px;margin-top:0">
        <input type="text" id="detail-short-url" value="<?= e($shortUrl) ?>" readonly>
        <button type="button" class="btn btn-copy" data-copy="#detail-short-url"><?= icon('copy', 'icon-sm') ?> 复制</button>
        <a href="<?= e($shortUrl) ?>" target="_blank" rel="noopener" class="btn btn-ghost" title="在新窗口打开"><?= icon('external', 'icon-sm') ?></a>
    </div>

    <div class="kv-list" style="margin-top:18px">
        <div class="kv-row"><span>目标地址</span><strong style="word-break:break-all"><?= e($link['url']) ?></strong></div>
        <div class="kv-row"><span>标题</span><strong><?= e($link['title'] !== '' ? $link['title'] : '—') ?></strong></div>
        <div class="kv-row"><span>创建时间</span><strong class="mono"><?= e($link['created_at']) ?></strong></div>
        <div class="kv-row"><span>最后更新</span><strong class="mono"><?= e($link['updated_at']) ?></strong></div>
    </div>

    <div class="btn-row" style="margin-top:20px">
        <a href="<?= e(base_path('/admin/links.php?action=edit&id=' . (int)$link['id'])) ?>" class="btn btn-ghost btn-sm"><?= icon('edit', 'icon-sm') ?> 编辑</a>
        <a href="<?= e(base_path('/admin/links.php?action=toggle&id=' . (int)$link['id'] . '&_csrf=' . urlencode(csrf_token()))) ?>"
           class="btn btn-ghost btn-sm" onclick="return confirm('确定<?= (int)$link['status'] === 1 ? '停用' : '启用' ?>该链接吗？')"><?= icon('power', 'icon-sm') ?> <?= (int)$link['status'] === 1 ? '停用' : '启用' ?></a>
        <a href="<?= e(base_path('/admin/links.php?action=delete&id=' . (int)$link['id'] . '&_csrf=' . urlencode(csrf_token()))) ?>"
           class="btn btn-danger btn-sm" onclick="return confirm('删除后不可恢复，确定删除吗？')"><?= icon('trash', 'icon-sm') ?> 删除</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-icon"><?= icon('mouse') ?></span>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format(live_total_clicks((int)$link['id'])) ?></div>
            <div class="stat-label">累计点击</div>
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
        <span class="stat-trend">区间合计 <?= number_format($summary['total']) ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-icon violet"><?= icon('trend') ?></span>
        <div class="stat-meta">
            <div class="stat-value"><?= number_format($summary['peak']) ?></div>
            <div class="stat-label">单日最高</div>
        </div>
        <span class="stat-trend">近 14 天</span>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= icon('trend') ?> 近 14 天点击趋势</h2>
        <span class="chart-total">区间合计 <?= number_format($summary['total']) ?> 次</span>
    </div>
    <div class="chart-wrap"><?= trend_svg($daily, '短链接近 14 天点击趋势') ?></div>
    <p class="form-help" style="margin-top:12px">按日聚合的点击数据自 v2.3 起记录；累计点击包含升级前的历史次数。</p>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
