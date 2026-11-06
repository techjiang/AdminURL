<?php
/**
 * AdminURL 管理后台 - 操作日志
 *
 * 展示登录、创建、编辑、导出、备份等站内操作记录；支持筛选、分页与清空。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '操作日志';
$activeNav = 'logs';

$actionLabels = [
    'login'           => '用户登录',
    'install'         => '系统安装',
    'settings'        => '系统设置',
    'account'         => '账号变更',
    'shorten'         => '创建短链',
    'public_shorten'  => '公开创建',
    'link_edit'       => '编辑链接',
    'link_delete'     => '删除链接',
    'link_toggle'     => '启停链接',
    'bulk_delete'     => '批量删除',
    'bulk_toggle'     => '批量启停',
    'bulk_tag'        => '批量分组',
    'bulk_expiry'     => '批量有效期',
    'link_clone'      => '克隆短链',
    'api_clone'       => 'API 克隆',
    'tag_rename'      => '重命名分组',
    'tag_clear'       => '清除分组',
    'health_check'    => '健康检查',
    'trash_restore'   => '回收站恢复',
    'trash_purge'     => '彻底删除',
    'trash_clear'     => '清空回收站',
    'clicks_clear'    => '清空访问明细',
    'import'          => '批量导入',
    'export'          => '导出数据',
    'backup'          => '数据备份',
    'migrate'         => '数据迁移',
    'api_shorten'     => 'API 创建',
    'api_edit'        => 'API 编辑',
    'api_delete'      => 'API 删除',
    'api_token'       => 'API 令牌',
    'ua_block'        => 'UA 规则',
    'click_flush'     => '点击合并',
    'logs_clear'      => '清空日志',
];

// ---------- 清空日志 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'clear_logs') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $_SESSION['flash_error'] = '请求已过期，请刷新后重试';
    } else {
        try {
            db()->exec('DELETE FROM logs');
            log_event('logs_clear', '清空全部操作日志');
            $_SESSION['flash_ok'] = '操作日志已清空';
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = '清空失败：' . $e->getMessage();
        }
    }
    redirect(base_path('/admin/logs.php'));
}

// ---------- 查询 ----------
$filter  = trim((string)($_GET['action'] ?? ''));
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;

$where  = '';
$params = [];
if ($filter !== '') {
    $where  = 'WHERE action = ?';
    $params = [$filter];
}

$total = 0;
$totalPages = 1;
$rows = [];
$actions = [];
try {
    $stmt = db()->prepare('SELECT COUNT(*) AS c FROM logs ' . $where);
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['c'];
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare('SELECT * FROM logs ' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->execute([...$params, $perPage, $offset]);
    $rows = $stmt->fetchAll();
    $actions = db()->query('SELECT DISTINCT action FROM logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    // 表未初始化
}

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>操作日志</h2>
        <p>共记录 <?= number_format($total) ?> 条站内操作，系统仅保留最近 500 条。</p>
    </div>
    <form method="post" onsubmit="return confirm('确定清空全部操作日志吗？此操作不可恢复。')">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="clear_logs">
        <button type="submit" class="btn btn-danger btn-sm"><?= icon('trash', 'icon-sm') ?> 清空日志</button>
    </form>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= icon('clock') ?> 最近操作 <span class="count-badge"><?= number_format($total) ?></span></h2>
        <form method="get" class="search-form">
            <select name="action" aria-label="按动作筛选">
                <option value="">全部动作</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= e($a) ?>" <?= $filter === $a ? 'selected' : '' ?>><?= e($actionLabels[$a] ?? $a) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-ghost btn-sm">筛选</button>
            <?php if ($filter !== ''): ?>
                <a href="<?= e(base_path('/admin/logs.php')) ?>" class="btn btn-ghost btn-sm"><?= icon('close', 'icon-sm') ?> 清除</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (count($rows) === 0): ?>
        <div class="empty-state">
            <?= icon('clock', 'icon-xl') ?>
            <p><?= $filter !== '' ? '该动作下暂无日志记录' : '暂无操作日志，使用系统后会自动记录' ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th>时间</th><th>动作</th><th>详情</th><th>来源 IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $isDanger = str_contains((string)$row['action'], 'delete') || $row['action'] === 'logs_clear';
                    $isOk = $row['action'] === 'login' || $row['action'] === 'install';
                    ?>
                    <tr>
                        <td class="td-time"><?= e($row['created_at']) ?></td>
                        <td><span class="badge <?= $isDanger ? 'badge-danger' : ($isOk ? 'badge-ok' : '') ?>"><?= e($actionLabels[$row['action']] ?? $row['action']) ?></span></td>
                        <td class="td-title" title="<?= e($row['detail']) ?>"><?= e($row['detail'] !== '' ? $row['detail'] : '—') ?></td>
                        <td class="mono muted"><?= e($row['ip']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <?php $qs = $filter !== '' ? '&action=' . urlencode($filter) : ''; ?>
            <div class="pagination">
                <span class="page-info">共 <?= number_format($total) ?> 条</span>
                <a class="btn btn-ghost btn-sm <?= $page <= 1 ? 'disabled' : '' ?>"
                   href="<?= e(base_path('/admin/logs.php?p=' . max(1, $page - 1) . $qs)) ?>"><?= icon('arrow-left', 'icon-sm') ?> 上一页</a>
                <span class="page-info"><?= $page ?> / <?= $totalPages ?></span>
                <a class="btn btn-ghost btn-sm <?= $page >= $totalPages ? 'disabled' : '' ?>"
                   href="<?= e(base_path('/admin/logs.php?p=' . min($totalPages, $page + 1) . $qs)) ?>">下一页 <?= icon('arrow-right', 'icon-sm') ?></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
