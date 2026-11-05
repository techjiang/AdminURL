<?php
/**
 * AdminURL 管理后台 - 导出短链接 CSV
 *
 * 支持按链接列表当前的搜索条件导出；使用 GET + CSRF 令牌鉴权。
 * 输出 UTF-8 BOM 便于 Excel 识别中文，并对单元格做公式注入防护。
 */

require_once __DIR__ . '/_init.php';

$search = trim((string)($_GET['q'] ?? ''));
$backUrl = base_path('/admin/links.php' . ($search !== '' ? '?q=' . urlencode($search) : ''));

if (!verify_csrf($_GET['_csrf'] ?? '')) {
    $_SESSION['flash_error'] = '导出链接已过期，请刷新页面后重试';
    redirect($backUrl);
}

$where  = '';
$params = [];
if ($search !== '') {
    $where  = 'WHERE (code LIKE ? OR url LIKE ? OR title LIKE ?)';
    $params = ["%$search%", "%$search%", "%$search%"];
}

/**
 * 阻止 Excel / WPS 把以 = + - @ 开头的内容当作公式执行
 */
function csv_safe_cell(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
}

$countStmt = db()->prepare('SELECT COUNT(*) AS c FROM links ' . $where);
$countStmt->execute($params);
$exportCount = (int)$countStmt->fetch()['c'];

log_event('export', '导出短链接 CSV，共 ' . $exportCount . ' 条'
    . ($search !== '' ? '（筛选：' . mb_substr($search, 0, 50) . '）' : ''));

$stmt = db()->prepare('SELECT * FROM links ' . $where . ' ORDER BY id DESC');
$stmt->execute($params);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="adminurl-links-' . date('Ymd-His') . '.csv"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['短码', '短链接', '目标地址', '标题', '点击', '状态', '创建时间', '更新时间']);

while ($row = $stmt->fetch()) {
    fputcsv($out, [
        csv_safe_cell((string)$row['code']),
        csv_safe_cell(short_url((string)$row['code'])),
        csv_safe_cell((string)$row['url']),
        csv_safe_cell((string)$row['title']),
        (int)$row['clicks'],
        (int)$row['status'] === 1 ? '正常' : '停用',
        (string)$row['created_at'],
        (string)$row['updated_at'],
    ]);
}

fclose($out);
exit;
