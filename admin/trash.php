<?php
/**
 * AdminURL 管理后台 - 回收站
 *
 * 删除的短链整行移入 trash 表，可原地恢复；短码被占用时自动分配新短码。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '回收站';
$activeNav = 'trash';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $_SESSION['flash_error'] = '请求已过期，请刷新页面后重试';
    } else {
        $form = (string)($_POST['form'] ?? '');
        $tid  = (int)($_POST['id'] ?? 0);

        if ($form === 'restore' && $tid > 0) {
            $res = trash_restore($tid);
            if ($res['ok']) {
                $_SESSION['flash_ok'] = $res['message'];
                log_event('trash_restore', '从回收站恢复 ' . $res['code']);
            } else {
                $_SESSION['flash_error'] = $res['message'];
            }
        } elseif ($form === 'purge' && $tid > 0) {
            $ok = trash_purge($tid);
            $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $ok ? '记录已彻底删除' : '删除失败，请重试';
            log_event('trash_purge', '彻底删除回收站记录 #' . $tid);
        } elseif ($form === 'clear') {
            $count = trash_clear();
            $_SESSION['flash_ok'] = '已清空回收站，共 ' . $count . ' 条记录';
            log_event('trash_clear', '清空回收站，共 ' . $count . ' 条');
        } else {
            $_SESSION['flash_error'] = '未知操作';
        }
    }
    redirect(base_path('/admin/trash.php'));
}

$items   = trash_list(100);
$summary = trash_summary();

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>回收站</h2>
        <p>删除的短链会先移到这里，可恢复或彻底清除，避免误删造成的数据丢失。</p>
    </div>
    <a href="<?= e(base_path('/admin/links.php')) ?>" class="btn btn-ghost btn-sm"><?= icon('link', 'icon-sm') ?> 返回链接管理</a>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-label">回收站记录</span>
        <strong class="stat-value"><?= number_format($summary['count']) ?></strong>
        <span class="stat-foot">最多展示最近 100 条</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">保留的点击数</span>
        <strong class="stat-value"><?= number_format($summary['clicks']) ?></strong>
        <span class="stat-foot">恢复后一并带回</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">最近删除</span>
        <strong class="stat-value" style="font-size:18px"><?= e($summary['latest'] !== '' ? mb_substr($summary['latest'], 0, 16) : '—') ?></strong>
        <span class="stat-foot">按删除时间倒序</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">清空</span>
        <strong class="stat-value" style="font-size:18px">谨慎操作</strong>
        <span class="stat-foot">清空后不可恢复</span>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= icon('trash') ?> 已删除的短链 <span class="count-badge"><?= count($items) ?></span></h2>
        <?php if (count($items) > 0): ?>
            <form method="post" onsubmit="return confirm('确定清空回收站吗？所有记录将永久删除且无法恢复。')">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="clear">
                <button type="submit" class="btn btn-danger btn-sm">清空回收站</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (count($items) === 0): ?>
        <div class="empty-state">
            <?= icon('trash', 'icon-xl') ?>
            <p>回收站是空的，删除短链后可以在这里找回</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>短码</th>
                    <th>目标地址</th>
                    <th>点击</th>
                    <th>删除时间</th>
                    <th class="th-actions">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><span class="code-chip"><?= e($item['code']) ?></span></td>
                        <td class="td-url" title="<?= e($item['url']) ?>"><?= e(mb_substr((string)$item['url'], 0, 55)) ?></td>
                        <td class="td-num"><?= number_format((int)$item['clicks']) ?></td>
                        <td class="td-time"><?= e($item['deleted_at']) ?></td>
                        <td class="td-actions">
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form" value="restore">
                                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm">恢复</button>
                            </form>
                            <form method="post" class="inline-form" onsubmit="return confirm('彻底删除后无法恢复，确定继续吗？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form" value="purge">
                                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">彻底删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-head"><h2><?= icon('info') ?> 恢复说明</h2></div>
    <ul class="import-list">
        <li>删除时会把整行（含分组、有效期、访问密码、点击数）序列化保存，恢复后原样带回。</li>
        <li>若原短码已被新链接占用或属于系统保留路径，恢复时自动分配新短码并在提示中说明。</li>
        <li>后台单条 / 批量删除、API <code>DELETE /api/v1/links/{code}</code> 都会进入回收站。</li>
        <li>回收站记录不会自动过期，请定期清理不再需要的记录。</li>
    </ul>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
