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
            $raw  = bin2hex(random_bytes(32));
            $hash = hash('sha256', $raw);
            db()->prepare('INSERT INTO api_tokens (user_id, name, token_hash, status, created_at) VALUES (?, ?, ?, 1, ?)')
                ->execute([(int)$adminUser['id'], $name, $hash, date('Y-m-d H:i:s')]);
            $_SESSION['new_token'] = $raw;
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

$endpoints = [
    ['POST',   '/api/v1/links',        '创建短链',        'url 必填，code / title / expires_at / tag / access_password 可选'],
    ['GET',    '/api/v1/links',        '查询短链列表',    '支持 page / size / q / tag 参数'],
    ['GET',    '/api/v1/links/{code}', '查询单个短链',    '返回链接详情、有效期与点击数'],
    ['PUT',    '/api/v1/links/{code}', '更新短链',        '可修改 url / title / code / expires_at / access_password'],
    ['POST',   '/api/v1/links/{code}/clone', '一键克隆', '生成新短码，点击数从 0 开始'],
    ['DELETE', '/api/v1/links/{code}', '删除短链',        '删除后不可恢复'],
    ['GET',    '/api/v1/stats',        '统计概览',        '总量、点击量与今日新增'],
    ['POST',   '/api/v1/ua/check',     '检测 UA 屏蔽',    '提交 ua 判断是否命中规则'],
    ['GET',    '/api/v1/health',       '健康检查',        '无需鉴权'],
];

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>API 接口</h2>
        <p>用令牌调用 REST 接口，把短链能力接入你的脚本、博客或工作流。</p>
    </div>
</div>

<?php if ($newToken): ?>
<div class="card highlight-card">
    <div class="card-head"><h2><?= icon('check') ?> 令牌已创建</h2></div>
    <p class="card-desc">名称「<?= e($newTokenName) ?>」。出于安全考虑，令牌仅显示这一次，请立即复制并妥善保存。</p>
    <div class="result-url">
        <input type="text" id="new-token-value" value="<?= e($newToken) ?>" readonly>
        <button type="button" class="btn btn-copy" data-copy="#new-token-value"><?= icon('copy', 'icon-sm') ?> 复制</button>
    </div>
    <p class="form-help">调用时通过 <code>Authorization: Bearer &lt;token&gt;</code> 传递。</p>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2><?= icon('key') ?> 访问令牌</h2>
        <span class="count-badge"><?= count($tokens) ?></span>
    </div>
    <form method="post" class="inline-form" autocomplete="off" style="margin-bottom:18px">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="create_token">
        <div class="input-wrap">
            <input type="text" name="name" placeholder="令牌名称，如「我的博客」" required maxlength="50">
            <button type="submit" class="btn btn-primary"><?= icon('plus', 'icon-sm') ?> 创建令牌</button>
        </div>
    </form>

    <?php if (count($tokens) === 0): ?>
        <div class="empty-state">
            <?= icon('key', 'icon-xl') ?>
            <p>还没有令牌，创建一个即可调用 API</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th>名称</th><th>状态</th><th>最后使用</th><th>创建时间</th><th class="th-actions">操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tokens as $t): ?>
                    <tr>
                        <td><strong style="font-weight:650"><?= e($t['name']) ?></strong></td>
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
                               class="btn btn-danger btn-sm" onclick="return confirm('吊销后该令牌立即失效，确定吗？')"><?= icon('trash', 'icon-sm') ?> 吊销</a>
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
    <div class="card-head"><h2><?= icon('code') ?> 接口文档</h2></div>
    <p class="card-desc">所有接口返回 JSON。鉴权方式：<code>Authorization: Bearer &lt;token&gt;</code> 或 URL 参数 <code>?access_token=&lt;token&gt;</code>。基地址为 <code><?= e(base_path('')) ?></code>。</p>

    <div class="endpoint-list">
        <?php foreach ($endpoints as [$method, $path, $label, $desc]): ?>
            <div class="endpoint">
                <span class="method method-<?= strtolower($method) ?>"><?= e($method) ?></span>
                <code class="endpoint-path"><?= e($path) ?></code>
                <span class="endpoint-name"><?= e($label) ?></span>
                <span class="endpoint-desc"><?= e($desc) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <h3 style="margin:24px 0 10px;font-size:14px">创建短链请求体</h3>
    <pre><code>POST <?= e(base_path('/api/v1/links')) ?>  Content-Type: application/json
{ "url": "https://example.com/very/long/path", "code": "optional", "title": "optional",
  "expires_at": "2026-12-31 23:59", "tag": "活动推广", "access_password": "visit123" }</code></pre>
    <p class="form-help"><code>expires_at</code> 可选，支持 <code>YYYY-MM-DD HH:MM:SS</code> 与 <code>YYYY-MM-DD HH:MM</code>；传空字符串表示永久有效。<code>tag</code> 可选，最多 32 字。列表 / 详情响应返回 <code>expires_at</code>、<code>expired</code>、<code>tag</code> 与 <code>password_protected</code>；列表可用 <code>?tag=标签</code> 或 <code>?tag=__none</code> 过滤。<code>access_password</code> 为 4-128 字符的明文，仅用于写入，不会出现在任何响应或 CSV 导出中；PUT 时传 <code>clear_access_password: true</code> 可清除密码，留空则保持不变。</p>

    <h3 style="margin:24px 0 10px;font-size:14px">一键克隆</h3>
    <pre><code>POST <?= e(base_path('/api/v1/links/{code}/clone')) ?>  Authorization: Bearer YOUR_TOKEN</code></pre>
    <p class="form-help">复制目标地址、标题与尚未到期的有效期，自动生成新短码；新链接默认启用且点击数从 0 开始。</p>

    <h3 style="margin:24px 0 10px;font-size:14px">curl 示例</h3>
    <pre><code>curl -X POST <?= e(base_path('/api/v1/links')) ?> \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://example.com"}'</code></pre>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
