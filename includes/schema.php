<?php
/**
 * AdminURL 数据库结构定义
 *
 * 在安装时执行：install/ 页面与 CLI 安装均调用本文件内的 migrate_schema()。
 */

/**
 * 执行建表（幂等：已存在的表跳过）
 */
function migrate_schema(): void
{
    $pdo = db();

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'admin',
    status        INTEGER NOT NULL DEFAULT 1,
    created_at    TEXT NOT NULL,
    last_login_at TEXT
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS links (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    code       TEXT NOT NULL UNIQUE,
    url        TEXT NOT NULL,
    title      TEXT NOT NULL DEFAULT '',
    user_id    INTEGER NOT NULL DEFAULT 0,
    clicks     INTEGER NOT NULL DEFAULT 0,
    status     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_links_code ON links (code)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_links_created ON links (created_at DESC)');

    // 点击日志：延迟计数的缓冲队列
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS click_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id    INTEGER NOT NULL,
    ip         TEXT NOT NULL DEFAULT '',
    ua         TEXT NOT NULL DEFAULT '',
    referer    TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
)
SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_click_logs_link ON click_logs (link_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_click_logs_created ON click_logs (created_at)');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS click_daily (
    link_id INTEGER NOT NULL,
    day     TEXT NOT NULL,
    clicks  INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (link_id, day)
)
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_click_daily_day ON click_daily (day)');

    // 站点设置 KV
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
)
SQL);

    // User-Agent 屏蔽规则
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ua_blacklist (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    pattern    TEXT NOT NULL UNIQUE,
    remark     TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
)
SQL);

    // API 令牌（存哈希）
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS api_tokens (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    name         TEXT NOT NULL DEFAULT '',
    token_hash   TEXT NOT NULL UNIQUE,
    status       INTEGER NOT NULL DEFAULT 1,
    last_used_at TEXT,
    created_at   TEXT NOT NULL
)
SQL);

    // API 请求限流（滑动窗口，按 IP + 令牌维度）
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS api_rate_limits (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket     TEXT NOT NULL,
    window_start INTEGER NOT NULL,
    hit_count  INTEGER NOT NULL DEFAULT 0,
    UNIQUE (bucket, window_start)
)
SQL);

    // 登录失败记录（限速锁定）
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS login_failures (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    username   TEXT NOT NULL DEFAULT '',
    ip         TEXT NOT NULL DEFAULT '',
    failed_at  TEXT NOT NULL
)
SQL);

    // 操作日志
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    action     TEXT NOT NULL,
    detail     TEXT NOT NULL DEFAULT '',
    ip         TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
)
SQL);

    // YOURLS 迁移记录（防止重复导入）
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS migrations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    source     TEXT NOT NULL,
    imported   INTEGER NOT NULL DEFAULT 0,
    skipped    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
)
SQL);

    // 默认设置
    $defaults = [
        'site_name'       => 'AdminURL',
        'site_desc'       => '同一个世界，同一个互联网！',
        'public_shorten'  => '0',   // 开放使用开关：默认关闭
        'public_register' => '0',   // 开放注册开关：默认关闭
        'redirect_type'   => '302', // 跳转方式：301 / 302
        'short_path_mode' => 'root',// 短链形态：root = /{code}，s = /s/{code} 兼容拓展
        'auto_title'      => '1',   // 自动抓取标题
        'footer_text'     => '',
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (`key`, `value`) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }
}

/**
 * 系统是否已安装（存在数据库文件且包含 users 表）
 */
function is_installed(): bool
{
    $dbFile = config('db_file', ROOT . '/data/adminurl.db');
    if (!is_file($dbFile)) {
        return false;
    }
    try {
        $row = db()->query('SELECT COUNT(*) AS c FROM sqlite_master WHERE type=\'table\' AND name=\'users\'')->fetch();
        return (int)($row['c'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}