<?php
/**
 * AdminURL 点击统计
 *
 * click_daily 按「链接 + 日期」聚合点击量，与延迟计数的缓冲队列 click_logs 相互独立：
 * 缓冲队列会被定期清空，日聚合表则长期保留，用于趋势分析。
 * 表结构采用 CREATE TABLE IF NOT EXISTS 自愈，已安装的旧数据库无需手动迁移。
 */

function ensure_stats_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS click_daily (
                link_id INTEGER NOT NULL,
                day     TEXT NOT NULL,
                clicks  INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (link_id, day)
            )'
        );
        db()->exec('CREATE INDEX IF NOT EXISTS idx_click_daily_day ON click_daily (day)');
    } catch (Throwable $e) {
        // 统计不可用时不影响正常跳转
    }
}

/**
 * 记录一次点击到日聚合表
 */
function record_click_daily(int $linkId): void
{
    try {
        ensure_stats_schema();
        db()->prepare(
            'INSERT INTO click_daily (link_id, day, clicks) VALUES (?, ?, 1)
             ON CONFLICT(link_id, day) DO UPDATE SET clicks = clicks + 1'
        )->execute([$linkId, date('Y-m-d')]);
    } catch (Throwable $e) {
        // 统计失败不影响跳转
    }
}

/**
 * 近 14 天点击序列；传入 linkId 则只统计单条链接
 *
 * @return array<string, int> ['2026-11-01' => 3, ...]
 */
function daily_clicks(?int $linkId = null): array
{
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
        $days[date('Y-m-d', strtotime('-' . $i . ' days'))] = 0;
    }

    try {
        ensure_stats_schema();
        $sql = 'SELECT day, SUM(clicks) AS c FROM click_daily WHERE day >= ?';
        $params = [array_key_first($days)];
        if ($linkId !== null) {
            $sql .= ' AND link_id = ?';
            $params[] = $linkId;
        }
        $sql .= ' GROUP BY day';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            if (isset($days[$r['day']])) {
                $days[$r['day']] = (int)$r['c'];
            }
        }
    } catch (Throwable $e) {
        // 表不可用时返回全 0 序列
    }

    return $days;
}

/**
 * 统计卡通用数值
 */
function click_summary(?int $linkId = null): array
{
    $daily = daily_clicks($linkId);
    return [
        'today' => (int)end($daily),
        'week'  => array_sum(array_slice($daily, -7)),
        'peak'  => (int)max($daily),
        'total' => array_sum($daily),
    ];
}

/**
 * 实时累计点击数
 *
 * links.clicks 由延迟计数定期合并，会短暂滞后；未合并的点击仍在 click_logs 缓冲队列中。
 * 两者相加即为不重复、不滞后的累计值。传入 linkId 则只统计单条链接。
 */
function live_total_clicks(?int $linkId = null): int
{
    try {
        $sql = 'SELECT COALESCE(SUM(clicks), 0) FROM links';
        $params = [];
        if ($linkId !== null) {
            $sql .= ' WHERE id = ?';
            $params[] = $linkId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $merged = (int)$stmt->fetchColumn();

        $sql = 'SELECT COUNT(*) FROM click_logs';
        $params = [];
        if ($linkId !== null) {
            $sql .= ' WHERE link_id = ?';
            $params[] = $linkId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return $merged + (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
