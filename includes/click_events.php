<?php
/**
 * AdminURL 访问明细
 *
 * click_logs 是会被合并清空的缓冲队列，click_audience 是按天聚合的长期统计；
 * click_events 补充「最近一次具体访问」的明细视图，按时间倒序保留固定条数，
 * 避免明细无限膨胀。表由 ensure_click_events_schema() 自愈创建。
 */

define('CLICK_EVENTS_KEEP', 5000);

function ensure_click_events_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS click_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id    INTEGER NOT NULL,
    code       TEXT NOT NULL,
    ip         TEXT NOT NULL DEFAULT '',
    ua         TEXT NOT NULL DEFAULT '',
    referer    TEXT NOT NULL DEFAULT '',
    host       TEXT NOT NULL DEFAULT '',
    device     TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
)
SQL);
        db()->exec('CREATE INDEX IF NOT EXISTS idx_click_events_created ON click_events (created_at DESC)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_click_events_code ON click_events (code)');
    } catch (Throwable $e) {
        // 明细表不可用时不影响跳转主流程
    }
}

/**
 * 记录一次访问明细，并裁剪到最近 CLICK_EVENTS_KEEP 条
 */
function record_click_event(int $linkId, string $code, string $referer, string $ua): void
{
    try {
        ensure_click_events_schema();
        db()->prepare(
            'INSERT INTO click_events (link_id, code, ip, ua, referer, host, device, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $linkId,
            $code,
            client_ip(),
            mb_substr($ua, 0, 500),
            mb_substr($referer, 0, 500),
            referrer_host($referer),
            detect_device($ua),
            date('Y-m-d H:i:s'),
        ]);
        $cutoff = (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM click_events')->fetchColumn() - CLICK_EVENTS_KEEP;
        if ($cutoff > 0) {
            db()->prepare('DELETE FROM click_events WHERE id <= ?')->execute([$cutoff]);
        }
    } catch (Throwable $e) {
        // 统计失败不影响跳转
    }
}

/**
 * 分页查询访问明细
 *
 * @return array{rows:array, total:int, pages:int}
 */
function click_events_list(string $q, int $page, int $perPage = 20): array
{
    ensure_click_events_schema();
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $where = '';
    $params = [];
    if ($q !== '') {
        $where = ' WHERE (code LIKE ? OR host LIKE ? OR device LIKE ?)';
        $params = ["%$q%", "%$q%", "%$q%"];
    }
    try {
        $count = db()->prepare('SELECT COUNT(*) FROM click_events' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $stmt = db()->prepare('SELECT * FROM click_events' . $where
            . ' ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->execute([...$params, $perPage, ($page - 1) * $perPage]);
        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'pages' => $pages];
    } catch (Throwable $e) {
        return ['rows' => [], 'total' => 0, 'pages' => 1];
    }
}

/**
 * 明细总览
 *
 * @return array{total:int, today:int, hosts:int, devices:int}
 */
function click_events_summary(): array
{
    ensure_click_events_schema();
    try {
        $stmt = db()->prepare('SELECT COUNT(*) AS total,
                    SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS today,
                    COUNT(DISTINCT host) AS hosts,
                    COUNT(DISTINCT device) AS devices
             FROM click_events');
        $stmt->execute([date('Y-m-d 00:00:00')]);
        $r = $stmt->fetch();
        return [
            'total'   => (int)($r['total'] ?? 0),
            'today'   => (int)($r['today'] ?? 0),
            'hosts'   => (int)($r['hosts'] ?? 0),
            'devices' => (int)($r['devices'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['total' => 0, 'today' => 0, 'hosts' => 0, 'devices' => 0];
    }
}

/**
 * 清空全部明细，返回删除条数
 */
function click_events_clear(): int
{
    ensure_click_events_schema();
    try {
        $count = (int)db()->query('SELECT COUNT(*) FROM click_events')->fetchColumn();
        db()->exec('DELETE FROM click_events');
        return $count;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * IP 脱敏展示：IPv4 隐藏末两段，IPv6 隐藏后半部分
 */
function mask_ip(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '') {
        return '—';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return $parts[0] . '.' . $parts[1] . '.*.*';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $parts = explode(':', $ip);
        return implode(':', array_slice($parts, 0, 3)) . ':*';
    }
    return '—';
}
