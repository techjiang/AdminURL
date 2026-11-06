<?php
/**
 * AdminURL 管理后台 - 目标地址健康检查
 *
 * 手动批量探测短链目标是否仍可访问；结果写入 links.check_code / checked_at。
 * 探测复用防 SSRF 规则，仅允许公网 HTTP(S) 目标，使用 HEAD 请求且逐条超时 4 秒。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '健康检查';
$activeNav = 'health';

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'run_check') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $_SESSION['flash_error'] = '请求已过期，请刷新页面后重试';
    } else {
        $limit = (int)($_POST['limit'] ?? 20);
        $limit = max(1, min(50, $limit));
        $result = run_health_checks($limit);
        log_event('health_check', '健康检查 ' . $result['checked'] . ' 条，正常 '
            . $result['ok'] . ' 条，异常 ' . $result['bad'] . ' 条');
        $_SESSION['flash_ok'] = '本次检查 ' . $result['checked'] . ' 条：正常 '
            . $result['ok'] . ' 条，异常 ' . $result['bad'] . ' 条';
    }
    redirect(base_path('/admin/health.php'));
}

$summary = health_summary();
try {
    $recent = db()->query(
        'SELECT code, url, check_code, checked_at FROM links
         WHERE status = 1 AND checked_at IS NOT NULL
         ORDER BY checked_at DESC, id DESC LIMIT 30'
    )->fetchAll();
} catch (Throwable $e) {
    $recent = [];
}

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>健康检查</h2>
        <p>批量探测短链目标地址是否仍可访问，及时发现失效链接。</p>
    </div>
    <a href="<?= e(base_path('/admin/links.php')) ?>" class="btn btn-ghost btn-sm"><?= icon('link', 'icon-sm') ?> 链接管理</a>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-label">启用链接</span>
        <strong class="stat-value"><?= number_format($summary['total']) ?></strong>
        <span class="stat-foot">参与检查的范围</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">目标正常</span>
        <strong class="stat-value"><?= number_format($summary['ok']) ?></strong>
        <span class="stat-foot">HTTP 2xx / 3xx</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">目标异常</span>
        <strong class="stat-value"><?= number_format($summary['bad']) ?></strong>
        <span class="stat-foot">4xx / 5xx / 连接失败</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">尚未检测</span>
        <strong class="stat-value"><?= number_format($summary['unchecked']) ?></strong>
        <span class="stat-foot">按最久未检测优先</span>
    </div>
</div>

<div class="import-grid">
    <div class="card">
        <div class="card-head"><h2><?= icon('shield') ?> 运行检查</h2></div>
        <p class="card-desc">每次优先检查从未检测或最久未检测的启用链接，结果会显示在链接列表与详情页中。</p>
        <form method="post" class="inline-form" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="run_check">
            <select name="limit" aria-label="本次检查条数">
                <option value="10">本次检查 10 条</option>
                <option value="20" selected>本次检查 20 条</option>
                <option value="50">本次检查 50 条</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><?= icon('bolt', 'icon-sm') ?> 开始检查</button>
        </form>
        <ul class="import-list" style="margin-top:18px">
            <li>使用 <code>HEAD</code> 请求，不下载页面内容；单条超时 4 秒，最多跟随 2 次跳转。</li>
            <li>仅探测公网 HTTP(S) 目标，内网 / 保留地址会被 <code>resolve_public_ip()</code> 拒绝。</li>
            <li>检查只记录状态码，不会停用短链；是否需要下线由管理员自行判断。</li>
        </ul>
    </div>

    <div class="card">
        <div class="card-head"><h2><?= icon('info') ?> 状态说明</h2></div>
        <ul class="import-list">
            <li><span class="badge badge-ok">正常</span> 目标返回 2xx / 3xx。</li>
            <li><span class="badge badge-danger">异常</span> 目标返回 4xx / 5xx 或连接失败。</li>
            <li><span class="badge badge-warn">未检测</span> 尚未运行过检查，不代表链接异常。</li>
        </ul>
        <p class="card-desc" style="margin-top:16px">部分站点会拒绝无浏览器特征的 HEAD 请求，出现误报时可手动打开目标地址复核。</p>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-head">
        <h2><?= icon('clock') ?> 最近检测结果</h2>
        <span class="chart-total">最多显示 30 条</span>
    </div>
    <?php if (count($recent) === 0): ?>
        <div class="empty-state">
            <?= icon('shield', 'icon-xl') ?>
            <p>还没有检测记录，点击上方「开始检查」运行第一轮</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>短码</th><th>目标地址</th><th>状态</th><th>检测时间</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $row): ?>
                    <?php $meta = health_meta($row['check_code'] !== null ? (int)$row['check_code'] : null); ?>
                    <tr>
                        <td><span class="code-chip"><?= e($row['code']) ?></span></td>
                        <td class="td-url" title="<?= e($row['url']) ?>"><?= e(mb_substr((string)$row['url'], 0, 60)) ?></td>
                        <td><span class="badge <?= e($meta['class']) ?>"><?= e($meta['label']) ?></span></td>
                        <td class="td-time"><?= e($row['checked_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
