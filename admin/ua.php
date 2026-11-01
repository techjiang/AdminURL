<?php
/**
 * AdminURL 管理后台 - UA 屏蔽
 *
 * 管理员自定义屏蔽 User-Agent（防止恶意爬虫/采集）。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = 'UA 屏蔽';
$activeNav = 'ua';

// 删除规则
if (($_GET['action'] ?? '') === 'delete' && (int)($_GET['id'] ?? 0) > 0) {
    if (verify_csrf($_GET['_csrf'] ?? '')) {
        db()->prepare('DELETE FROM ua_blacklist WHERE id = ?')->execute([(int)$_GET['id']]);
        $_SESSION['flash_ok'] = '屏蔽规则已删除';
        log_event('ua_block', '删除 UA 屏蔽规则 #' . (int)$_GET['id']);
    }
    redirect(base_path('/admin/ua.php'));
}

// 新增规则
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add_ua') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $_SESSION['flash_error'] = '请求已过期，请刷新页面后重试';
    } else {
        $pattern = trim((string)($_POST['pattern'] ?? ''));
        $remark  = trim((string)($_POST['remark'] ?? ''));
        if ($pattern === '' || mb_strlen($pattern) > 200) {
            $_SESSION['flash_error'] = '请输入 1-200 字以内的 UA 匹配串';
        } else {
            try {
                db()->prepare('INSERT INTO ua_blacklist (pattern, remark, created_at) VALUES (?, ?, ?)')
                    ->execute([$pattern, mb_substr($remark, 0, 200), date('Y-m-d H:i:s')]);
                $_SESSION['flash_ok'] = '屏蔽规则已添加';
                log_event('ua_block', '添加 UA 屏蔽规则: ' . $pattern);
            } catch (Throwable $e) {
                $_SESSION['flash_error'] = '规则已存在或添加失败';
            }
        }
    }
    redirect(base_path('/admin/ua.php'));
}

// 测试 UA
$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'test_ua') {
    if (verify_csrf($_POST['_csrf'] ?? null)) {
        $testUa = (string)($_POST['test_ua'] ?? '');
        if ($testUa !== '') {
            $testResult = ua_match($testUa);
        }
    }
}

// 规则列表
$rules = db()->query('SELECT * FROM ua_blacklist ORDER BY id DESC')->fetchAll();

require __DIR__ . '/_header.php';
?>

<div class="card">
    <div class="card-head"><h2>屏蔽规则</h2></div>
    <p class="card-desc">
        命中规则的 User-Agent 访问短链时将返回 403，且不计入点击统计。
        支持模糊匹配：填 <code>python-requests</code> 匹配包含该串的 UA；填 <code>*bot*</code> 使用通配符。
    </p>

    <form method="post" class="inline-form" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="add_ua">
        <div class="input-wrap">
            <input type="text" name="pattern" placeholder="如 python-requests 或 *bot*" required maxlength="200">
            <input type="text" name="remark" class="code-input" placeholder="备注（可选）" maxlength="200">
            <button type="submit" class="btn btn-primary">添加规则</button>
        </div>
    </form>

    <?php if (count($rules) === 0): ?>
        <div class="empty-state"><p>暂无屏蔽规则，全部 UA 均可正常访问</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>匹配串</th>
                    <th>备注</th>
                    <th>添加时间</th>
                    <th class="th-actions">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rules as $r): ?>
                    <tr>
                        <td>#<?= (int)$r['id'] ?></td>
                        <td class="mono"><?= e($r['pattern']) ?></td>
                        <td><?= e($r['remark'] !== '' ? $r['remark'] : '—') ?></td>
                        <td class="td-time"><?= e($r['created_at']) ?></td>
                        <td class="td-actions">
                            <a href="<?= e(base_path('/admin/ua.php?action=delete&id=' . (int)$r['id'] . '&_csrf=' . urlencode(csrf_token()))) ?>"
                               class="btn btn-danger btn-sm" onclick="return confirm('确定删除该规则吗？')">删除</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head"><h2>UA 调试工具</h2></div>
    <form method="post" class="inline-form" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="test_ua">
        <div class="input-wrap">
            <input type="text" name="test_ua" placeholder="粘贴一个 User-Agent 测试是否命中屏蔽" required maxlength="500">
            <button type="submit" class="btn btn-ghost">测试</button>
        </div>
    </form>

    <?php if ($testResult !== null): ?>
        <div class="result-card <?= $testResult ? 'warn' : 'success' ?>">
            <p>
                <?= $testResult
                    ? '⛔ 该 UA 命中了屏蔽规则，将被拒绝访问且不计数'
                    : '✅ 该 UA 未命中任何屏蔽规则，可正常访问' ?>
            </p>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>