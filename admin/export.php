<?php
/**
 * AdminURL 管理后台 - 导出短链接 CSV
 *
 * 与链接列表共用筛选条件（搜索 / 状态 / 分组 / 排序），保证导出内容与所见一致。
 * 使用 GET + CSRF 令牌鉴权；UTF-8 BOM 便于 Excel 识别中文，单元格做公式注入防护。
 */

require_once __DIR__ . '/_init.php';

$filter  = link_filter_from_request();
$backUrl = base_path('/admin/links.php' . ($filter['query'] !== '' ? '?' . $filter['query'] : ''));

if (!verify_csrf($_GET['_csrf'] ?? '')) {
    $_SESSION['flash_error'] = '导出链接已过期，请刷新页面后重试';
    redirect($backUrl);
}

/**
 * 阻止 Excel / WPS 把以 = + - @ 开头的内容当作公式执行
 */
function csv_safe_cell(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
}

$countStmt = db()->prepare('SELECT COUNT(*) AS c FROM links ' . $filter['where']);
$countStmt->execute($filter['params']);
$exportCount = (int)$countStmt->fetch()['c'];

log_event('export', '导出短链接 CSV，共 ' . $exportCount . ' 条'
    . ($filter['query'] !== '' ? '（筛选：' . mb_substr($filter['query'], 0, 80) . '）' : ''));

$stmt = db()->prepare('SELECT * FROM links ' . $filter['where'] . ' ' . $filter['order']);
$stmt->execute($filter['params']);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="adminurl-links-' . date('Ymd-His') . '.csv"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['短码', '短链接', '目标地址', '标题', '点击', '状态', '分组', '有效期', '创建时间', '更新时间']);

while ($row = $stmt->fetch()) {
    fputcsv($out, [
        csv_safe_cell((string)$row['code']),
        csv_safe_cell(short_url((string)$row['code'])),
        csv_safe_cell((string)$row['url']),
        csv_safe_cell((string)$row['title']),
        (int)$row['clicks'],
        csv_safe_cell(link_status_meta($row)['label']),
        csv_safe_cell((string)($row['tag'] ?? '')),
        csv_safe_cell(expiry_label($row)),
        (string)$row['created_at'],
        (string)$row['updated_at'],
    ]);
}

fclose($out);
exit;
