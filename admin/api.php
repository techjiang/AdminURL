<?php
/**
 * AdminURL 管理后台 - API 接口
 *
 * 管理访问令牌，展示接口文档。
 */

require_once __DIR__ . '/_init.php';

$pageTitle = 'API 接口';
$activeNav = 'api';

// 创建令牌
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'create_token') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $_SESSION['flash_error'] = '请求已过期，请刷新页面后重试';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) {
            $_SESSION['flash_error'] = '令牌名称不能为空且不超过 50 字';
        } else {
            // 生成 48 位随机会话令牌
            $raw = bin2hex(random_bytes(32)); // 64 hex chars
            $hash = hash('sha256', $raw);
            db()->prepare('INSERT INTO api_tokens (user_id, name, token_hash, status, created_at) VALUES (?, ?, ?, 1, ?)')
                ->execute([(int)$adminUser['id'], $name, $hash, date('Y-m-d H:i:s')]);
            $_SESSION['new_token'] = $raw; // 仅本次展示一次
            $_SESSION['new_token_name'] = $name;
            log_event('api_token', '创建 API 令牌: ' . $name);
            redirect(base_path('/admin/api.php?created=1'));
        }
    }
    redirect(base_path('/admin/api.php'));
}

// 吊销令牌
if (($_GET['action'] ?? '') === 'revoke' && (int)($_GET['id'] ?? 0) > 0) {
    if (verify_csrf($_GET['_csrf'] ?? '')) {
        db()->prepare('UPDATE api_tokens SET status = 0 WHERE id = ?')->execute([(int)$_GET['id']]);
        $_SESSION['flash_ok'] = '令牌已吊销';
        log_event('api_token', '吊销 API 令牌 #' . (int)$_GET['id']);
    }
    redirect(base_path('/admin/api.php'));
}

$tokens = db()->query('SELECT * FROM api_tokens ORDER BY id DESC LIMIT 50')->fetchAll();
$newToken = $_SESSION['new_token'] ?? null;
$newTokenName = $_SESSION['new_token_name'] ?? '';
unset($_SESSION['new_token'], $_SESSION['new_token_name']);

require __DIR__ . '/_header.php';
?>

<?php if ($newToken): ?>
<div class="card highlight-card">
    <div class="card-head"><h2>🎉 令牌已创建</h2></div>
    <p class="card-desc">请立即复制并妥善保存，出于安全考虑，该令牌仅显示这一次：</p>
    <div class="result-url">
        <input type="text" id="new-token-value" value="<?= e($newToken) ?>" readonly>
        <button type="button" class="btn btn-copy" data-copy="#new-token-value">复制</button>
    </div>
    <p class="form-help">令牌以 <code>Authorization: Bearer &lt;token&gt;</code> 方式调用 API。</p>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>访问令牌</h2></div>
    <form method="post" class="inline-form" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="create_token">
        <div class="input-wrap">
            <input type="text" name="name" placeholder="令牌名称，如「我的博客」" required maxlength="50">
            <button type="submit" class="btn btn-primary">创建令牌</button>
        </div>
    </form>

    <?php if (count($tokens) === 0): ?>
        <div class="empty-state"><p>还没有令牌，创建一个即可调用 API</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>名称</th>
                    <th>状态</th>
                    <th>最后使用</th>
                    <th>创建时间</th>
                    <th class="th-actions">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tokens as $t): ?>
                    <tr>
                        <td><?= e($t['name']) ?></td>
                        <td>
                            <?php if ((int)$t['status'] === 1): ?>
                                <span class="badge badge-ok">有效</span>
                            <?php else: ?>
                                <span class="badge badge-warn">已吊销</span>
                            <?php endif; ?>
                        </td>
                        <td class="td-time"><?= e($t['last_used_at'] ?: '—') ?></td>
                        <td class="td-time"><?= e($t['created_at']) ?></td>
                        <td class="td-actions">
                            <?php if ((int)$t['status'] === 1): ?>
                            <a href="<?= e(base_path('/admin/api.php?action=revoke&id=' . (int)$t['id'] . '&_csrf=' . urlencode(csrf_token()))) ?>"
                               class="btn btn-danger btn-sm" onclick="return confirm('吊销后该令牌立即失效，确定吗？')">吊销</a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head"><h2>接口文档</h2></div>
    <div class="api-doc">
        <p class="card-desc">所有接口返回 JSON，鉴权方式：<code>Authorization: Bearer &lt;token&gt;</code> 或 URL 参数 <code>?access_token=&lt;token&gt;</code>。</p>

        <h3>创建短链</h3>
        <pre><code>POST <?= e(base_path('/api/v1/links')) ?>  Content-Type: application/json
{ "url": "https://example.com/very/long/path", "code": "optional", "title": "optional" }</code></pre>

        <h3>查询短链列表</h3>
        <pre><code>GET <?= e(base_path('/api/v1/links')) ?>?page=1&size=20&q=keyword</code></pre>

        <h3>查询单个短链</h3>
        <pre><code>GET <?= e(base_path('/api/v1/links/{code}')) ?></code></pre>

        <h3>更新短链</h3>
        <pre><code>PUT <?= e(base_path('/api/v1/links/{code}')) ?>  Content-Type: application/json
{ "url": "https://new.example.com", "title": "新标题", "code": "newcode" }</code></pre>

        <h3>删除短链</h3>
        <pre><code>DELETE <?= e(base_path('/api/v1/links/{code}')) ?></code></pre>

        <h3>统计概览</h3>
        <pre><code>GET <?= e(base_path('/api/v1/stats')) ?></code></pre>

        <h3>检测 UA 是否被屏蔽</h3>
        <pre><code>POST <?= e(base_path('/api/v1/ua/check')) ?>  Content-Type: application/json
{ "ua": "Mozilla/5.0 ..." }</code></pre>

        <h3>示例（curl）</h3>
        <pre><code>curl -X POST <?= e(base_path('/api/v1/links')) ?> \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://example.com"}'</code></pre>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>