<?php
/**
 * AdminURL 管理后台 - 链接管理
 *
 * 支持搜索、分页、编辑、删除、启用/停用、批量操作与 CSV 导出。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '链接管理';
$activeNav = 'links';

// 搜索条件提前解析，供操作后的重定向沿用
$search  = trim((string)($_GET['q'] ?? ''));
$listUrl = base_path('/admin/links.php' . ($search !== '' ? '?q=' . urlencode($search) : ''));

$action = $_GET['action'] ?? '';
$id = (int)($_GET['id'] ?? 0);

// ---------- 单条删除 ----------
if ($action === 'delete' && $id > 0) {
    if (!verify_csrf($_GET['_csrf'] ?? '')) {
        $_SESSION['flash_error'] = '请求已过期';
    } else {
        $stmt = db()->prepare('SELECT code FROM links WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            db()->prepare('DELETE FROM links WHERE id = ?')->execute([$id]);
            db()->prepare('DELETE FROM click_logs WHERE link_id = ?')->execute([$id]);
            log_event('link_delete', '删除短链接 ' . $row['code']);
            $_SESSION['flash_ok'] = '链接「' . $row['code'] . '」已删除';
        }
    }
    redirect($listUrl);
}

// ---------- 单条启用 / 停用 ----------
if ($action === 'toggle' && $id > 0) {
    if (verify_csrf($_GET['_csrf'] ?? '')) {
        $stmt = db()->prepare('SELECT code, status FROM links WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $newStatus = (int)$row['status'] === 1 ? 0 : 1;
            db()->prepare('UPDATE links SET status = ?, updated_at = ? WHERE id = ?')
                ->execute([$newStatus, date('Y-m-d H:i:s'), $id]);
            log_event('link_toggle', ($newStatus === 1 ? '启用' : '停用') . '短链接 ' . $row['code']);
            $_SESSION['flash_ok'] = ($newStatus === 1 ? '已启用' : '已停用') . '「' . $row['code'] . '」';
        }
    }
    redirect($listUrl);
}

// ---------- 批量操作 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'bulk') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $_SESSION['flash_error'] = '请求已过期，请刷新页面后重试';
    } else {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $ids = array_values(array_unique(array_filter($ids, static fn(int $v): bool => $v > 0)));
        $bulkAction = (string)($_POST['bulk_action'] ?? '');

        if (count($ids) === 0) {
            $_SESSION['flash_error'] = '请先勾选要操作的短链接';
        } elseif (!in_array($bulkAction, ['enable', 'disable', 'delete'], true)) {
            $_SESSION['flash_error'] = '未知的批量操作';
        } else {
            $marks = implode(',', array_fill(0, count($ids), '?'));

            if ($bulkAction === 'delete') {
                $sel = db()->prepare('SELECT code FROM links WHERE id IN (' . $marks . ')');
                $sel->execute($ids);
                $codes = $sel->fetchAll(PDO::FETCH_COLUMN);
                db()->prepare('DELETE FROM links WHERE id IN (' . $marks . ')')->execute($ids);
                db()->prepare('DELETE FROM click_logs WHERE link_id IN (' . $marks . ')')->execute($ids);
                log_event('bulk_delete', '批量删除 ' . count($codes) . ' 条短链接: ' . implode(', ', array_slice($codes, 0, 10)));
                $_SESSION['flash_ok'] = '已删除 ' . count($codes) . ' 条短链接';
            } else {
                $status = $bulkAction === 'enable' ? 1 : 0;
                $upd = db()->prepare('UPDATE links SET status = ?, updated_at = ? WHERE id IN (' . $marks . ')');
                $upd->execute([$status, date('Y-m-d H:i:s'), ...$ids]);
                $affected = $upd->rowCount();
                log_event('bulk_toggle', ($status === 1 ? '批量启用 ' : '批量停用 ') . $affected . ' 条短链接');
                $_SESSION['flash_ok'] = ($status === 1 ? '已启用 ' : '已停用 ') . $affected . ' 条短链接';
            }
        }
    }
    redirect($listUrl);
}

// ---------- 编辑表单 ----------
$editRow = null;
if ($action === 'edit' && $id > 0) {
    $stmt = db()->prepare('SELECT * FROM links WHERE id = ?');
    $stmt->execute([$id]);
    $editRow = $stmt->fetch();
    if ($editRow === false) {
        redirect($listUrl);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_link') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $_SESSION['edit_error'] = '请求已过期，请刷新后重试';
    } else {
        $eid    = (int)($_POST['id'] ?? 0);
        $eurl   = trim((string)($_POST['url'] ?? ''));
        $etitle = trim((string)($_POST['title'] ?? ''));
        $ecode  = trim((string)($_POST['code'] ?? ''));

        if (!is_valid_url($eurl)) {
            $_SESSION['edit_error'] = '目标地址必须是有效的 http/https 链接';
        } elseif ($ecode === '' || !preg_match('/^[A-Za-z0-9]{3,32}$/', $ecode)) {
            $_SESSION['edit_error'] = '短码仅支持 3-32 位字母或数字';
        } else {
            $stmt = db()->prepare('SELECT id FROM links WHERE code = ? AND id != ? LIMIT 1');
            $stmt->execute([$ecode, $eid]);
            if ($stmt->fetch()) {
                $_SESSION['edit_error'] = '该短码已被占用，请换一个';
            } else {
                if ($etitle === '' && (string)setting('auto_title', '1') === '1') {
                    $etitle = fetch_page_title($eurl, (int)config('fetch_timeout', 6));
                }
                db()->prepare('UPDATE links SET code = ?, url = ?, title = ?, updated_at = ? WHERE id = ?')
                    ->execute([$ecode, $eurl, mb_substr($etitle, 0, 200), date('Y-m-d H:i:s'), $eid]);
                log_event('link_edit', '编辑短链接 ' . $ecode);
                $_SESSION['flash_ok'] = '链接已更新';
                redirect($listUrl);
            }
        }
        redirect(base_path('/admin/links.php?action=edit&id=' . $eid));
    }
}

// ---------- 列表查询 ----------
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where  = '';
$params = [];
if ($search !== '') {
    $where  = 'WHERE (code LIKE ? OR url LIKE ? OR title LIKE ?)';
    $params = ["%$search%", "%$search%", "%$search%"];
}

$stmt = db()->prepare('SELECT COUNT(*) AS c FROM links ' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetch()['c'];
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = db()->prepare('SELECT * FROM links ' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?');
$stmt->execute([...$params, $perPage, $offset]);
$links = $stmt->fetchAll();

$exportUrl = base_path('/admin/export.php?_csrf=' . urlencode(csrf_token())
    . ($search !== '' ? '&q=' . urlencode($search) : ''));

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>全部链接</h2>
        <p>管理站点中的 <?= number_format($total) ?> 条短链接，支持搜索、批量操作与导出。</p>
    </div>
    <div class="btn-row">
        <a href="<?= e($exportUrl) ?>" class="btn btn-ghost btn-sm"><?= icon('download', 'icon-sm') ?> 导出 CSV</a>
        <a href="<?= e(base_path('/admin/#quick-create')) ?>" class="btn btn-primary"><?= icon('plus', 'icon-sm') ?> 新建短链</a>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= icon('layers') ?> 链接列表 <span class="count-badge"><?= number_format($total) ?></span></h2>
        <form method="get" class="search-form">
            <div class="search-box">
                <?= icon('search') ?>
                <input type="search" name="q" placeholder="搜索短码 / 链接 / 标题（Ctrl + K）" value="<?= e($search) ?>">
            </div>
            <button type="submit" class="btn btn-ghost btn-sm">搜索</button>
            <?php if ($search !== ''): ?>
                <a href="<?= e(base_path('/admin/links.php')) ?>" class="btn btn-ghost btn-sm"><?= icon('close', 'icon-sm') ?> 清除</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (count($links) === 0): ?>
        <div class="empty-state">
            <?= icon($search !== '' ? 'search' : 'link', 'icon-xl') ?>
            <p><?= $search !== '' ? '没有找到匹配的短链接，换个关键词试试' : '还没有短链接，去仪表盘创建第一条吧' ?></p>
        </div>
    <?php else: ?>
        <form method="post" action="<?= e($listUrl) ?>" id="bulk-form" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="bulk">

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th class="th-check"><input type="checkbox" id="check-all" aria-label="全选本页"></th>
                        <th>短码</th>
                        <th>标题</th>
                        <th>目标地址</th>
                        <th>点击</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th class="th-actions">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($links as $row): ?>
                        <tr>
                            <td class="td-check">
                                <input type="checkbox" class="row-check" name="ids[]" value="<?= (int)$row['id'] ?>" aria-label="选择 <?= e($row['code']) ?>">
                            </td>
                            <td>
                                <a href="<?= e(short_url($row['code'])) ?>" target="_blank" rel="noopener" class="code-chip"><?= e($row['code']) ?></a>
                                <button type="button" class="link-copy" data-copy-url="<?= e(short_url($row['code'])) ?>" title="复制短链"><?= icon('copy', 'icon-sm') ?></button>
                            </td>
                            <td class="td-title" title="<?= e($row['url']) ?>"><?= e($row['title'] !== '' ? $row['title'] : '—') ?></td>
                            <td class="td-url" title="<?= e($row['url']) ?>">
                                <a href="<?= e($row['url']) ?>" target="_blank" rel="noopener nofollow"><?= e(mb_substr($row['url'], 0, 55)) ?></a>
                            </td>
                            <td class="td-num"><?= number_format((int)$row['clicks']) ?></td>
                            <td>
                                <?php if ((int)$row['status'] === 1): ?>
                                    <span class="badge badge-ok">正常</span>
                                <?php else: ?>
                                    <span class="badge badge-warn">已停用</span>
                                <?php endif; ?>
                            </td>
                            <td class="td-time"><?= e($row['created_at']) ?></td>
                            <td class="td-actions">
                                <a href="<?= e(base_path('/admin/link.php?id=' . (int)$row['id'])) ?>"
                                   class="btn btn-ghost btn-sm" title="详情与趋势"><?= icon('chart', 'icon-sm') ?></a>
                                <a href="<?= e(base_path('/admin/links.php?action=edit&id=' . (int)$row['id'])) ?>"
                                   class="btn btn-ghost btn-sm" title="编辑"><?= icon('edit', 'icon-sm') ?></a>
                                <a href="<?= e(base_path('/admin/links.php?action=toggle&id=' . (int)$row['id'] . '&_csrf=' . urlencode(csrf_token()))) ?>"
                                   class="btn btn-ghost btn-sm" title="<?= (int)$row['status'] === 1 ? '停用' : '启用' ?>"
                                   onclick="return confirm('确定<?= (int)$row['status'] === 1 ? '停用' : '启用' ?>该链接吗？')"><?= icon('power', 'icon-sm') ?></a>
                                <a href="<?= e(base_path('/admin/links.php?action=delete&id=' . (int)$row['id'] . '&_csrf=' . urlencode(csrf_token()))) ?>"
                                   class="btn btn-danger btn-sm" title="删除"
                                   onclick="return confirm('删除后不可恢复，确定删除吗？')"><?= icon('trash', 'icon-sm') ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="bulk-bar">
                <span><?= icon('check-box', 'icon-sm') ?> 已选 <strong id="selected-count">0</strong> 项</span>
                <select name="bulk_action" id="bulk-action" aria-label="批量操作">
                    <option value="enable">批量启用</option>
                    <option value="disable">批量停用</option>
                    <option value="delete">批量删除</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm" id="bulk-submit" disabled>应用操作</button>
                <span class="muted" style="font-size:12px">仅对当前页勾选的链接生效</span>
            </div>
        </form>

        <?php if ($totalPages > 1): ?>
            <?php $qs = $search !== '' ? '&q=' . urlencode($search) : ''; ?>
            <div class="pagination">
                <span class="page-info">共 <?= number_format($total) ?> 条 · 每页 <?= $perPage ?> 条</span>
                <a class="btn btn-ghost btn-sm <?= $page <= 1 ? 'disabled' : '' ?>"
                   href="<?= e(base_path('/admin/links.php?p=' . max(1, $page - 1) . $qs)) ?>"><?= icon('arrow-left', 'icon-sm') ?> 上一页</a>
                <span class="page-info"><?= $page ?> / <?= $totalPages ?></span>
                <a class="btn btn-ghost btn-sm <?= $page >= $totalPages ? 'disabled' : '' ?>"
                   href="<?= e(base_path('/admin/links.php?p=' . min($totalPages, $page + 1) . $qs)) ?>">下一页 <?= icon('arrow-right', 'icon-sm') ?></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($editRow): ?>
<div class="modal-backdrop" id="edit-modal">
    <div class="modal" role="dialog" aria-modal="true" aria-label="编辑短链接">
        <div class="modal-head">
            <h2><?= icon('edit') ?> 编辑短链接</h2>
            <button type="button" class="modal-close" data-close=".modal-backdrop" aria-label="关闭"><?= icon('close', 'icon-sm') ?></button>
        </div>
        <form method="post" action="<?= e($listUrl) ?>" class="modal-body" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="edit_link">
            <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">

            <div class="form-group">
                <label for="edit-code">短码</label>
                <input type="text" id="edit-code" name="code" value="<?= e($editRow['code']) ?>" required pattern="[A-Za-z0-9]{3,32}">
                <p class="form-help">访问地址：<?= e(short_url((string)$editRow['code'])) ?></p>
            </div>
            <div class="form-group">
                <label for="edit-url">目标地址</label>
                <input type="url" id="edit-url" name="url" value="<?= e($editRow['url']) ?>" required maxlength="2048">
            </div>
            <div class="form-group">
                <label for="edit-title">标题</label>
                <input type="text" id="edit-title" name="title" value="<?= e($editRow['title']) ?>" maxlength="200">
                <p class="form-help">留空保存时将自动重新抓取标题</p>
            </div>

            <?php if (!empty($_SESSION['edit_error'])): ?>
                <p class="form-error"><?= e($_SESSION['edit_error']) ?></p>
                <?php unset($_SESSION['edit_error']); ?>
            <?php endif; ?>

            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" data-close=".modal-backdrop">取消</button>
                <button type="submit" class="btn btn-primary"><?= icon('check', 'icon-sm') ?> 保存修改</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
