<?php
/**
 * AdminURL 回收站
 *
 * 删除时不改主表结构、不加软删除标记，而是把整行序列化到 trash 表后从 links 移除，
 * 因此所有既有查询天然只看到在用链接，无需逐条追加 WHERE 条件。
 * 表由 ensure_trash_schema() 自愈创建，旧库无需手工迁移。
 */

function ensure_trash_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS trash (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id    INTEGER NOT NULL,
    code       TEXT NOT NULL,
    url        TEXT NOT NULL,
    title      TEXT NOT NULL DEFAULT '',
    clicks     INTEGER NOT NULL DEFAULT 0,
    payload    TEXT NOT NULL,
    deleted_at TEXT NOT NULL,
    deleted_by INTEGER NOT NULL DEFAULT 0
)
SQL);
        db()->exec('CREATE INDEX IF NOT EXISTS idx_trash_deleted ON trash (deleted_at DESC)');
    } catch (Throwable $e) {
        // 表不可用时由调用方回退为直接删除
    }
}

/**
 * 把一个链接移入回收站（单事务，失败则原链接保持不动）
 */
function trash_link_row(array $row, int $userId = 0): bool
{
    ensure_trash_schema();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO trash (link_id, code, url, title, clicks, payload, deleted_at, deleted_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int)$row['id'],
            (string)$row['code'],
            (string)$row['url'],
            (string)($row['title'] ?? ''),
            (int)($row['clicks'] ?? 0),
            json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            date('Y-m-d H:i:s'),
            $userId,
        ]);
        $id = (int)$row['id'];
        $pdo->prepare('DELETE FROM click_logs WHERE link_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM click_daily WHERE link_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM links WHERE id = ?')->execute([$id]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/**
 * 回收站列表
 */
function trash_list(int $limit = 100): array
{
    ensure_trash_schema();
    $limit = max(1, min(500, $limit));
    try {
        return db()->query('SELECT * FROM trash ORDER BY deleted_at DESC, id DESC LIMIT ' . $limit)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * 恢复一条：短码被占用时自动生成新短码
 *
 * @return array{ok: bool, code: string, message: string}
 */
function trash_restore(int $trashId): array
{
    ensure_trash_schema();
    $stmt = db()->prepare('SELECT * FROM trash WHERE id = ? LIMIT 1');
    $stmt->execute([$trashId]);
    $item = $stmt->fetch();
    if ($item === false) {
        return ['ok' => false, 'code' => '', 'message' => '回收站记录不存在'];
    }

    $row = json_decode((string)$item['payload'], true);
    if (!is_array($row)) {
        return ['ok' => false, 'code' => '', 'message' => '记录已损坏，无法恢复'];
    }

    $code = (string)($row['code'] ?? $item['code']);
    $renamed = false;
    if (link_exists($code) || is_reserved_code($code)) {
        $code = generate_unique_code((int)config('code_length', 5));
        $renamed = true;
    }

    $columns = [
        'code', 'url', 'title', 'user_id', 'clicks', 'status',
        'expires_at', 'tag', 'check_code', 'checked_at', 'access_password',
        'created_at', 'updated_at',
    ];
    $values = [];
    foreach ($columns as $col) {
        $values[$col] = $col === 'code' ? $code : ($row[$col] ?? null);
    }
    $values['created_at'] = (string)($row['created_at'] ?? date('Y-m-d H:i:s'));
    $values['updated_at'] = date('Y-m-d H:i:s');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sql = 'INSERT INTO links (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $pdo->prepare($sql)->execute(array_values($values));
        $pdo->prepare('DELETE FROM trash WHERE id = ?')->execute([$trashId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'code' => '', 'message' => '恢复失败：' . $e->getMessage()];
    }

    return [
        'ok'      => true,
        'code'    => $code,
        'message' => $renamed ? '短码已被占用，已恢复为新短码 ' . $code : '已恢复短链 ' . $code,
    ];
}

/**
 * 彻底删除一条回收站记录
 */
function trash_purge(int $trashId): bool
{
    ensure_trash_schema();
    try {
        return db()->prepare('DELETE FROM trash WHERE id = ?')->execute([$trashId]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 清空回收站，返回删除条数
 */
function trash_clear(): int
{
    ensure_trash_schema();
    try {
        $count = (int)db()->query('SELECT COUNT(*) FROM trash')->fetchColumn();
        db()->exec('DELETE FROM trash');
        return $count;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * 回收站汇总
 *
 * @return array{count:int, clicks:int, latest:string}
 */
function trash_summary(): array
{
    ensure_trash_schema();
    try {
        $row = db()->query('SELECT COUNT(*) AS c, COALESCE(SUM(clicks),0) AS clicks, MAX(deleted_at) AS latest FROM trash')->fetch();
        return [
            'count'  => (int)($row['c'] ?? 0),
            'clicks' => (int)($row['clicks'] ?? 0),
            'latest' => (string)($row['latest'] ?? ''),
        ];
    } catch (Throwable $e) {
        return ['count' => 0, 'clicks' => 0, 'latest' => ''];
    }
}
