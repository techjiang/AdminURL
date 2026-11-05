<?php
/**
 * AdminURL 管理后台 - 数据库备份下载
 *
 * 使用 SQLite 的 VACUUM INTO 生成一致性快照，避免在 WAL 模式下直接复制
 * 数据库文件导致备份缺少未合并的事务；旧版本 SQLite 回退为 checkpoint 后复制。
 */

require_once __DIR__ . '/_init.php';

if (!verify_csrf($_GET['_csrf'] ?? '')) {
    $_SESSION['flash_error'] = '备份链接已过期，请刷新页面后重试';
    redirect(base_path('/admin/settings.php'));
}

$tmp = @tempnam(sys_get_temp_dir(), 'au_backup_');
if ($tmp === false) {
    $_SESSION['flash_error'] = '无法创建临时文件，请检查系统临时目录权限';
    redirect(base_path('/admin/settings.php'));
}

// VACUUM INTO 要求目标文件不存在
@unlink($tmp);

try {
    try {
        db()->exec('VACUUM INTO ' . db()->quote($tmp));
    } catch (Throwable $e) {
        db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        if (!@copy((string)config('db_file'), $tmp)) {
            throw new RuntimeException('无法复制数据库文件');
        }
    }
} catch (Throwable $e) {
    @unlink($tmp);
    $_SESSION['flash_error'] = '备份失败：' . $e->getMessage();
    redirect(base_path('/admin/settings.php'));
}

if (!is_file($tmp) || filesize($tmp) === 0) {
    @unlink($tmp);
    $_SESSION['flash_error'] = '备份文件生成失败';
    redirect(base_path('/admin/settings.php'));
}

log_event('backup', '下载数据库备份（' . number_format(filesize($tmp) / 1024, 1) . ' KB）');

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="adminurl-backup-' . date('Ymd-His') . '.db"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$out = fopen($tmp, 'rb');
fpassthru($out);
fclose($out);
@unlink($tmp);
exit;
