<?php
/**
 * AdminURL 管理后台 - 链接管理
 *
 * 支持搜索、分组 / 状态筛选、排序、分页、编辑、有效期、删除、启用/停用、一键克隆、
 * 批量分组与有效期维护、CSV 导出。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '链接管理';
$activeNav = 'links';

// 筛选条件提前解析，供操作后的重定向与导出沿用
$filter    = link_filter_from_request();
$search    = $filter['search'];
$status    = $filter['status'];
$sort      = $filter['sort'];
$tag       = $filter['tag'];
$baseQuery = $filter['query'];
$extra     = $baseQuery !== '' ? '&' . $baseQuery : '';
$listUrl   = base_path('/admin/links.php' . ($baseQuery !== '' ? '?' . $baseQuery : ''));

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

// ---------- 一键克隆 ----------
if ($action === 'clone' && $id > 0) {
    if (!verify_csrf($_GET['_csrf'] ?? '')) {
        $_SESSION['flash_error'] = '请求已过期';
    } else {
        $stmt = db()->prepare('SELECT * FROM links WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            $_SESSION['flash_error'] = '短链接不存在或已被删除';
        } else {
            try {
                $clone = clone_link_row($row, (int)($adminUser['id'] ?? 0));
                log_event('link_clone', '克隆短链接 ' . $row['code'] . ' -> ' . $clone['code']);
                $_SESSION['flash_ok'] = '已克隆为「' . $clone['code'] . '」：' . short_url($clone['code']);
            } catch (Throwable $e) {
                $_SESSION['flash_error'] = '克隆失败，请稍后重试';
            }
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
        } elseif (!in_array($bulkAction, [
            'enable', 'disable', 'delete', 'set_tag', 'clear_tag', 'set_expiry', 'clear_expiry',
        ], true)) {
            $_SESSION['flash_error'] = '未知的批量操作';
        } else {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $now   = date('Y-m-d H:i:s');

            if ($bulkAction === 'delete') {
                $sel = db()->prepare('SELECT code FROM links WHERE id IN (' . $marks . ')');
                $sel->execute($ids);
                $codes = $sel->fetchAll(PDO::FETCH_COLUMN);
                db()->prepare('DELETE FROM links WHERE id IN (' . $marks . ')')->execute($ids);
                db()->prepare('DELETE FROM click_logs WHERE link_id IN (' . $marks . ')')->execute($ids);
                log_event('bulk_delete', '批量删除 ' . count($codes) . ' 条短链接: ' . implode(', ', array_slice($codes, 0, 10)));
                $_SESSION['flash_ok'] = '已删除 ' . count($codes) . ' 条短链接';
            } elseif ($bulkAction === 'set_tag') {
                $bulkTag = normalize_tag((string)($_POST['bulk_tag'] ?? ''));
                if ($bulkTag === '') {
                    $_SESSION['flash_error'] = '请填写要设置的分组标签';
                } else {
                    $upd = db()->prepare('UPDATE links SET tag = ?, updated_at = ? WHERE id IN (' . $marks . ')');
                    $upd->execute([$bulkTag, $now, ...$ids]);
                    log_event('bulk_tag', '批量设置分组「' . $bulkTag . '」，共 ' . $upd->rowCount() . ' 条');
                    $_SESSION['flash_ok'] = '已将 ' . $upd->rowCount() . ' 条链接归入「' . $bulkTag . '」';
                }
            } elseif ($bulkAction === 'clear_tag') {
                $upd = db()->prepare("UPDATE links SET tag = '', updated_at = ? WHERE id IN (" . $marks . ')');
                $upd->execute([$now, ...$ids]);
                log_event('bulk_tag', '批量清除分组，共 ' . $upd->rowCount() . ' 条');
                $_SESSION['flash_ok'] = '已清除 ' . $upd->rowCount() . ' 条链接的分组';
            } elseif ($bulkAction === 'set_expiry') {
                $bulkExpiry = validate_expiry_input((string)($_POST['bulk_expires_at'] ?? ''));
                if (!$bulkExpiry['ok']) {
                    $_SESSION['flash_error'] = $bulkExpiry['error'];
                } else {
                    $upd = db()->prepare('UPDATE links SET expires_at = ?, updated_at = ? WHERE id IN (' . $marks . ')');
                    $upd->execute([$bulkExpiry['value'], $now, ...$ids]);
                    log_event('bulk_expiry', '批量设置有效期 ' . $bulkExpiry['value'] . '，共 ' . $upd->rowCount() . ' 条');
                    $_SESSION['flash_ok'] = '已为 ' . $upd->rowCount() . ' 条链接设置有效期';
                }
            } elseif ($bulkAction === 'clear_expiry') {
                $upd = db()->prepare('UPDATE links SET expires_at = NULL, updated_at = ? WHERE id IN (' . $marks . ')');
                $upd->execute([$now, ...$ids]);
                log_event('bulk_expiry', '批量清除有效期，共 ' . $upd->rowCount() . ' 条');
                $_SESSION['flash_ok'] = '已清除 ' . $upd->rowCount() . ' 条链接的有效期';
            } else {
                $newStatus = $bulkAction === 'enable' ? 1 : 0;
                $upd = db()->prepare('UPDATE links SET status = ?, updated_at = ? WHERE id IN (' . $marks . ')');
                $upd->execute([$newStatus, $now, ...$ids]);
                $affected = $upd->rowCount();
                log_event('bulk_toggle', ($newStatus === 1 ? '批量启用 ' : '批量停用 ') . $affected . ' 条短链接');
                $_SESSION['flash_ok'] = ($newStatus === 1 ? '已启用 ' : '已停用 ') . $affected . ' 条短链接';
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
        // 编辑允许保留已过去的有效期，否则已过期链接无法保存其他字段
        $eexpiry = validate_expiry_input((string)($_POST['expires_at'] ?? ''), false);
        $etag    = normalize_tag((string)($_POST['tag'] ?? ''));
        $currentPwdStmt = db()->prepare('SELECT access_password FROM links WHERE id = ?');
        $currentPwdStmt->execute([$eid]);
        $currentPwd = $currentPwdStmt->fetchColumn();
        $currentPwd = ($currentPwd === false || $currentPwd === null || (string)$currentPwd === '')
            ? null : (string)$currentPwd;
        $epassword = resolve_link_password($_POST, $currentPwd);

        if (!is_valid_url($eurl)) {
            $_SESSION['edit_error'] = '目标地址必须是有效的 http/https 链接';
        } elseif (!$eexpiry['ok']) {
            $_SESSION['edit_error'] = $eexpiry['error'];
        } elseif (!$epassword['ok']) {
            $_SESSION['edit_error'] = $epassword['error'];
        } elseif ($ecode === '' || !preg_match('/^[A-Za-z0-9]{3,32}$/', $ecode)) {
            $_SESSION['edit_error'] = '短码仅支持 3-32 位字母或数字';
        } elseif (is_reserved_code($ecode)) {
            $_SESSION['edit_error'] = '该短码是系统保留路径，请换一个';
        } else {
            $stmt = db()->prepare('SELECT id FROM links WHERE code = ? AND id != ? LIMIT 1');
            $stmt->execute([$ecode, $eid]);
            if ($stmt->fetch()) {
                $_SESSION['edit_error'] = '该短码已被占用，请换一个';
            } else {
                if ($etitle === '' && (string)setting('auto_title', '1') === '1') {
                    $etitle = fetch_page_title($eurl, (int)config('fetch_timeout', 6));
                }
                db()->prepare('UPDATE links SET code = ?, url = ?, title = ?, expires_at = ?, tag = ?, access_password = ?, updated_at = ? WHERE id = ?')
                    ->execute([
                        $ecode, $eurl, mb_substr($etitle, 0, 200), $eexpiry['value'], $etag,
                        $epassword['value'], date('Y-m-d H:i:s'), $eid,
                    ]);
                log_event('link_edit', '编辑短链接 ' . $ecode);
                $_SESSION['flash_ok'] = '链接已更新';
                redirect($listUrl);
            }
        }
        redirect(base_path('/admin/links.php?action=edit&id=' . $eid . $extra));
    }
}

// ---------- 列表查询 ----------
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where  = $filter['where'];
$params = $filter['params'];
$order  = $filter['order'];

$stmt = db()->prepare('SELECT COUNT(*) AS c FROM links ' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetch()['c'];
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = db()->prepare('SELECT * FROM links ' . $where . ' ' . $order . ' LIMIT ? OFFSET ?');
$stmt->execute([...$params, $perPage, $offset]);
$links = $stmt->fetchAll();

$exportUrl  = base_path('/admin/export.php?_csrf=' . urlencode(csrf_token()) . $extra);
$tagOptions = tag_list();

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>全部链接</h2>
        <p>管理站点中的 <?= number_format($total) ?> 条短链接，支持搜索、批量操作与导出。</p>
    </div>
    <div class="btn-row">
        <a href="<?= e(base_path('/admin/import.php')) ?>" class="btn btn-ghost btn-sm"><?= icon('upload', 'icon-sm') ?> 批量导入</a>
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

    <form method="get" class="filter-bar">
        <input type="hidden" name="q" value="<?= e($search) ?>">
        <label for="filter-status">状态</label>
        <select name="status" id="filter-status">
            <option value="" <?= $status === '' ? 'selected' : '' ?>>全部状态</option>
            <option value="1" <?= $status === '1' ? 'selected' : '' ?>>正常</option>
            <option value="0" <?= $status === '0' ? 'selected' : '' ?>>已停用</option>
            <option value="expired" <?= $status === 'expired' ? 'selected' : '' ?>>已过期</option>
            <option value="expiring" <?= $status === 'expiring' ? 'selected' : '' ?>>7 天内到期</option>
            <option value="bad" <?= $status === 'bad' ? 'selected' : '' ?>>目标异常</option>
        </select>
        <label for="filter-tag">分组</label>
        <select name="tag" id="filter-tag">
            <option value="" <?= $tag === '' ? 'selected' : '' ?>>全部分组</option>
            <option value="__none" <?= $tag === '__none' ? 'selected' : '' ?>>未分组</option>
            <?php foreach ($tagOptions as $t): ?>
                <?php if ((string)$t['tag'] === '') { continue; } ?>
                <option value="<?= e($t['tag']) ?>" <?= $tag === (string)$t['tag'] ? 'selected' : '' ?>><?= e($t['tag']) ?> (<?= (int)$t['links'] ?>)</option>
            <?php endforeach; ?>
        </select>
        <label for="filter-sort">排序</label>
        <select name="sort" id="filter-sort">
            <option value="" <?= $sort === '' ? 'selected' : '' ?>>最新创建</option>
            <option value="clicks" <?= $sort === 'clicks' ? 'selected' : '' ?>>点击最多</option>
            <option value="code" <?= $sort === 'code' ? 'selected' : '' ?>>短码升序</option>
            <option value="expiry" <?= $sort === 'expiry' ? 'selected' : '' ?>>即将到期</option>
        </select>
        <button type="submit" class="btn btn-ghost btn-sm">应用</button>
        <?php if ($baseQuery !== ''): ?>
            <a href="<?= e(base_path('/admin/links.php')) ?>" class="btn btn-ghost btn-sm"><?= icon('close', 'icon-sm') ?> 重置筛选</a>
        <?php endif; ?>
    </form>

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
                                <?php if (trim((string)($row['tag'] ?? '')) !== ''): ?>
                                    <a class="tag-chip" style="--tag-hue:<?= tag_hue((string)$row['tag']) ?>"
                                       href="<?= e(base_path('/admin/links.php?tag=' . urlencode((string)$row['tag']))) ?>"><?= e($row['tag']) ?></a>
                                <?php endif; ?>
                                <?php if (link_is_protected($row)): ?>
                                    <span class="tag-chip" style="--tag-hue:265">密码保护</span>
                                <?php endif; ?>
                            </td>
                            <td class="td-title" title="<?= e($row['url']) ?>"><?= e($row['title'] !== '' ? $row['title'] : '—') ?></td>
                            <td class="td-url" title="<?= e($row['url']) ?>">
                                <a href="<?= e($row['url']) ?>" target="_blank" rel="noopener nofollow"><?= e(mb_substr($row['url'], 0, 55)) ?></a>
                            </td>
                            <td class="td-num"><?= number_format((int)$row['clicks']) ?></td>
                            <td>
                                <?php $meta = link_status_meta($row); ?>
                                <span class="badge <?= e($meta['class']) ?>"><?= e($meta['label']) ?></span>
                                <?php if (trim((string)($row['expires_at'] ?? '')) !== ''): ?>
                                    <div class="expiry-hint"><?= e(mb_substr((string)$row['expires_at'], 0, 16)) ?></div>
                                <?php endif; ?>
                                <?php $hm = health_meta(isset($row['check_code']) && $row['check_code'] !== null ? (int)$row['check_code'] : null); ?>
                                <div class="health-hint td-health">目标 <?= e($hm['label']) ?></div>
                            </td>
                            <td class="td-time"><?= e($row['created_at']) ?></td>
                            <td class="td-actions">
                                <a href="<?= e(base_path('/admin/link.php?id=' . (int)$row['id'])) ?>"
                                   class="btn btn-ghost btn-sm" title="详情与趋势"><?= icon('chart', 'icon-sm') ?></a>
                                <a href="<?= e(base_path('/admin/links.php?action=clone&id=' . (int)$row['id'] . '&_csrf=' . urlencode(csrf_token()) . $extra)) ?>"
                                   class="btn btn-ghost btn-sm" title="一键克隆"><?= icon('copy', 'icon-sm') ?></a>
                                <a href="<?= e(base_path('/admin/links.php?action=edit&id=' . (int)$row['id'] . $extra)) ?>"
                                   class="btn btn-ghost btn-sm" title="编辑"><?= icon('edit', 'icon-sm') ?></a>
                                <a href="<?= e(base_path('/admin/links.php?action=toggle&id=' . (int)$row['id'] . '&_csrf=' . urlencode(csrf_token()) . $extra)) ?>"
                                   class="btn btn-ghost btn-sm" title="<?= (int)$row['status'] === 1 ? '停用' : '启用' ?>"
                                   onclick="return confirm('确定<?= (int)$row['status'] === 1 ? '停用' : '启用' ?>该链接吗？')"><?= icon('power', 'icon-sm') ?></a>
                                <a href="<?= e(base_path('/admin/links.php?action=delete&id=' . (int)$row['id'] . '&_csrf=' . urlencode(csrf_token()) . $extra)) ?>"
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
                    <option value="set_tag">批量设置分组</option>
                    <option value="clear_tag">批量清除分组</option>
                    <option value="set_expiry">批量设置有效期</option>
                    <option value="clear_expiry">批量清除有效期</option>
                    <option value="delete">批量删除</option>
                </select>
                <input type="text" name="bulk_tag" list="bulk-tag-suggestions" maxlength="32" placeholder="分组标签" aria-label="批量分组标签" style="max-width:150px">
                <datalist id="bulk-tag-suggestions">
                    <?php foreach ($tagOptions as $t): ?>
                        <?php if ((string)$t['tag'] === '') { continue; } ?>
                        <option value="<?= e($t['tag']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <input type="datetime-local" name="bulk_expires_at" aria-label="批量有效期" style="max-width:190px">
                <button type="submit" class="btn btn-primary btn-sm" id="bulk-submit" disabled>应用操作</button>
                <span class="muted" style="font-size:12px">仅对当前页勾选的链接生效</span>
            </div>
        </form>

        <?php if ($totalPages > 1): ?>
            <?php $qs = $extra; ?>
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
            <div class="form-group">
                <label for="edit-expires">有效期</label>
                <input type="datetime-local" id="edit-expires" name="expires_at" value="<?= e(expiry_for_input($editRow['expires_at'] ?? '')) ?>">
                <p class="form-help">留空表示永久有效；到期后短链自动停止跳转。</p>
            </div>
            <div class="form-group">
                <label for="edit-tag">分组标签</label>
                <input type="text" id="edit-tag" name="tag" list="tag-suggestions" value="<?= e($editRow['tag'] ?? '') ?>" maxlength="32" placeholder="例如：活动推广">
                <datalist id="tag-suggestions">
                    <?php foreach ($tagOptions as $t): ?>
                        <?php if ((string)$t['tag'] === '') { continue; } ?>
                        <option value="<?= e($t['tag']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <p class="form-help">留空表示未分组；最多 32 字，可用于筛选与统计。</p>
            </div>
            <div class="form-group">
                <label for="edit-access-password">访问密码</label>
                <input type="password" id="edit-access-password" name="access_password" maxlength="128" autocomplete="new-password"
                       placeholder="<?= link_is_protected($editRow) ? '已设置，留空保持不变' : '留空表示无需密码' ?>">
                <p class="form-help">设置后访客需输入密码才能跳转；密码以 bcrypt 哈希存储，不会明文保存。</p>
                <?php if (link_is_protected($editRow)): ?>
                    <label class="switch-row" style="margin-top:10px;font-size:13px;color:var(--text-2)">
                        <input type="checkbox" name="clear_access_password" value="1" style="width:auto;min-height:auto;margin-right:8px"> 清除已有访问密码
                    </label>
                <?php endif; ?>
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

<script>
    (function () {
        var action = document.getElementById('bulk-action');
        var tagInput = document.querySelector('input[name="bulk_tag"]');
        var expInput = document.querySelector('input[name="bulk_expires_at"]');
        if (!action) { return; }
        function sync() {
            if (tagInput) {
                tagInput.disabled = action.value !== 'set_tag';
                tagInput.style.opacity = tagInput.disabled ? '.55' : '1';
            }
            if (expInput) {
                expInput.disabled = action.value !== 'set_expiry';
                expInput.style.opacity = expInput.disabled ? '.55' : '1';
            }
        }
        action.addEventListener('change', sync);
        sync();
    })();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
