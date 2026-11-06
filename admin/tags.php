<?php
/**
 * AdminURL 管理后台 - 分组标签
 *
 * 汇总各标签的链接数、点击量与异常数量，支持重命名 / 合并与清除标签。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '分组标签';
$activeNav = 'tags';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $_SESSION['flash_error'] = '请求已过期，请刷新页面后重试';
    } else {
        $form = (string)($_POST['form'] ?? '');
        $old  = normalize_tag((string)($_POST['old_tag'] ?? ''));

        if ($form === 'rename_tag') {
            $new = normalize_tag((string)($_POST['new_tag'] ?? ''));
            if ($old === '') {
                $error = '请选择要重命名的标签';
            } elseif ($new === '') {
                $error = '新标签名不能为空，如需移除标签请使用「清除」';
            } else {
                $stmt = db()->prepare('UPDATE links SET tag = ?, updated_at = ? WHERE tag = ?');
                $stmt->execute([$new, date('Y-m-d H:i:s'), $old]);
                log_event('tag_rename', '分组重命名 ' . $old . ' -> ' . $new . '（' . $stmt->rowCount() . ' 条）');
                $_SESSION['flash_ok'] = '已将「' . $old . '」合并到「' . $new . '」，共 ' . $stmt->rowCount() . ' 条链接';
            }
        } elseif ($form === 'clear_tag') {
            if ($old === '') {
                $error = '请选择要清除的标签';
            } else {
                $stmt = db()->prepare("UPDATE links SET tag = '', updated_at = ? WHERE tag = ?");
                $stmt->execute([date('Y-m-d H:i:s'), $old]);
                log_event('tag_clear', '清除分组 ' . $old . '（' . $stmt->rowCount() . ' 条）');
                $_SESSION['flash_ok'] = '已清除「' . $old . '」分组，共 ' . $stmt->rowCount() . ' 条链接';
            }
        }
    }
    if ($error !== '') {
        $_SESSION['flash_error'] = $error;
    }
    redirect(base_path('/admin/tags.php'));
}

$groups  = tag_list();
$summary = tag_summary();
$totalLinks = 0;
$untagged = 0;
foreach ($groups as $g) {
    $totalLinks += (int)$g['links'];
    if ((string)$g['tag'] === '') {
        $untagged = (int)$g['links'];
    }
}
$groupCount = 0;
foreach ($groups as $g) {
    if ((string)$g['tag'] !== '') {
        $groupCount++;
    }
}

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>分组标签</h2>
        <p>用标签把短链按活动、渠道或项目归类，快速筛选与汇总。</p>
    </div>
    <a href="<?= e(base_path('/admin/links.php')) ?>" class="btn btn-ghost btn-sm"><?= icon('link', 'icon-sm') ?> 前往链接管理</a>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-label">分组数</span>
        <strong class="stat-value"><?= $groupCount ?></strong>
        <span class="stat-foot">已命名的标签</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">已打标链接</span>
        <strong class="stat-value"><?= number_format($summary['tagged']) ?></strong>
        <span class="stat-foot">共 <?= number_format($totalLinks) ?> 条链接</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">未分组</span>
        <strong class="stat-value"><?= number_format($untagged) ?></strong>
        <span class="stat-foot"><a href="<?= e(base_path('/admin/links.php?tag=__none')) ?>">查看未分组链接</a></span>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= icon('layers') ?> 全部分组</h2>
        <span class="chart-total">点击「查看链接」可按分组筛选</span>
    </div>

    <?php if (count($groups) === 0): ?>
        <div class="empty-state">
            <?= icon('layers', 'icon-xl') ?>
            <p>还没有短链接，先去创建并在编辑弹窗中填写分组标签吧</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>标签</th>
                    <th>链接数</th>
                    <th>累计点击</th>
                    <th>已过期</th>
                    <th>已停用</th>
                    <th class="th-actions">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $g): ?>
                    <?php $name = (string)$g['tag']; ?>
                    <tr>
                        <td>
                            <?php if ($name === ''): ?>
                                <span class="muted">未分组</span>
                            <?php else: ?>
                                <span class="tag-chip" style="--tag-hue:<?= tag_hue($name) ?>"><?= e($name) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="td-num"><?= number_format((int)$g['links']) ?></td>
                        <td class="td-num"><?= number_format((int)$g['clicks']) ?></td>
                        <td class="td-num"><?= (int)$g['expired'] > 0 ? '<span class="badge badge-warn">' . (int)$g['expired'] . '</span>' : '0' ?></td>
                        <td class="td-num"><?= (int)$g['disabled'] > 0 ? '<span class="badge badge-warn">' . (int)$g['disabled'] . '</span>' : '0' ?></td>
                        <td class="td-actions">
                            <a href="<?= e(base_path('/admin/links.php?tag=' . ($name === '' ? '__none' : urlencode($name)))) ?>"
                               class="btn btn-ghost btn-sm">查看链接</a>
                            <?php if ($name !== ''): ?>
                                <button type="button" class="btn btn-ghost btn-sm tag-rename"
                                        data-tag="<?= e($name) ?>"><?= icon('edit', 'icon-sm') ?> 重命名</button>
                                <form method="post" class="inline-form" onsubmit="return confirm('确定清除该分组标签吗？链接本身不会被删除。')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form" value="clear_tag">
                                    <input type="hidden" name="old_tag" value="<?= e($name) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">清除</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-head"><h2><?= icon('edit') ?> 重命名 / 合并分组</h2></div>
    <p class="card-desc">把「原标签」改为「新标签」；若新标签已存在，两组会合并，链接本身不会变动。</p>
    <form method="post" class="quick-form" style="max-width:620px" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="rename_tag">
        <input type="text" name="old_tag" id="rename-old" placeholder="原标签" maxlength="32" required>
        <input type="text" name="new_tag" placeholder="新标签" maxlength="32" required>
        <button type="submit" class="btn btn-primary btn-sm">保存</button>
    </form>
</div>

<script>
    (function () {
        document.querySelectorAll('.tag-rename').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById('rename-old');
                if (input) {
                    input.value = this.getAttribute('data-tag') || '';
                    input.focus();
                    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        });
    })();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
