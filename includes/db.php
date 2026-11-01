<?php
/**
 * AdminURL 数据库连接
 *
 * 基于 PDO + SQLite，所有 SQL 统一走预处理参数绑定。
 */

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbFile = config('db_file', ROOT . '/data/adminurl.db');

    // 确保数据目录存在
    $dir = dirname($dbFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // SQLite 优化：WAL 模式提升并发读写能力
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}