<?php
/**
 * AdminURL 访问来源分析
 *
 * click_logs 是延迟计数的缓冲队列，合并后会被清空；因此来源数据按
 * (day, host, device) 单独聚合到 click_audience，长期保留且体量可控。
 * 表由 ensure_audience_schema() 自愈创建，旧库无需手工迁移。
 */

function ensure_audience_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS click_audience (
    day    TEXT NOT NULL,
    host   TEXT NOT NULL,
    device TEXT NOT NULL,
    clicks INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (day, host, device)
)
SQL);
        db()->exec('CREATE INDEX IF NOT EXISTS idx_audience_day ON click_audience (day)');
    } catch (Throwable $e) {
        // 统计表不可用时不影响跳转主流程
    }
}

/**
 * UA 归类为设备类型（中文标签直接用于展示）
 */
function detect_device(string $ua): string
{
    $ua = strtolower(trim($ua));
    if ($ua === '') {
        return '未知';
    }
    if (preg_match('/bot|crawler|spider|slurp|curl|wget|python|httpclient|facebookexternalhit|headless/i', $ua)) {
        return '机器人';
    }
    if (preg_match('/ipad|tablet|kindle|silk|playbook/i', $ua)) {
        return '平板';
    }
    if (preg_match('/mobile|iphone|android|windows phone|blackberry|opera mini|ipod/i', $ua)) {
        return '移动';
    }
    if (preg_match('/windows|macintosh|linux|x11|cros/i', $ua)) {
        return '桌面';
    }
    return '其他';
}

/**
 * Referer 归一化为主机名；空值或站内来源单独归类
 */
function referrer_host(string $ref): string
{
    $ref = trim($ref);
    if ($ref === '') {
        return '直接访问';
    }
    $host = parse_url($ref, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return '直接访问';
    }
    $host = strtolower($host);
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }
    $self = parse_url((string)config('base_url', ''), PHP_URL_HOST);
    if (is_string($self) && $self !== '' && strcasecmp($host, $self) === 0) {
        return '站内来源';
    }
    return mb_substr($host, 0, 80);
}

/**
 * 记录一次点击的来源维度（与 click_logs 写入同处调用）
 */
function record_click_audience(string $referer, string $ua): void
{
    try {
        ensure_audience_schema();
        db()->prepare(
            'INSERT INTO click_audience (day, host, device, clicks) VALUES (?, ?, ?, 1)
             ON CONFLICT(day, host, device) DO UPDATE SET clicks = clicks + 1'
        )->execute([
            date('Y-m-d'),
            referrer_host($referer),
            detect_device($ua),
        ]);
    } catch (Throwable $e) {
        // 统计失败不影响跳转
    }
}

/**
 * 近 N 天的来源 / 设备 / 每日趋势
 *
 * @return array{hosts:array, devices:array, daily:array}
 */
function audience_breakdown(int $days = 30, int $limit = 8): array
{
    $days = max(1, min(365, $days));
    $limit = max(1, min(30, $limit));
    $since = date('Y-m-d', time() - ($days - 1) * 86400);
    try {
        ensure_audience_schema();
        $hosts = db()->prepare(
            'SELECT host, SUM(clicks) AS c FROM click_audience WHERE day >= ?
             GROUP BY host ORDER BY c DESC, host ASC LIMIT ' . $limit
        );
        $hosts->execute([$since]);

        $devices = db()->prepare(
            'SELECT device, SUM(clicks) AS c FROM click_audience WHERE day >= ?
             GROUP BY device ORDER BY c DESC, device ASC'
        );
        $devices->execute([$since]);

        $daily = db()->prepare(
            'SELECT day, SUM(clicks) AS c FROM click_audience WHERE day >= ?
             GROUP BY day ORDER BY day ASC'
        );
        $daily->execute([$since]);

        return [
            'hosts'   => $hosts->fetchAll(),
            'devices' => $devices->fetchAll(),
            'daily'   => $daily->fetchAll(),
        ];
    } catch (Throwable $e) {
        return ['hosts' => [], 'devices' => [], 'daily' => []];
    }
}

/**
 * 来源总览
 *
 * @return array{total:int, hosts:int, top_device:string}
 */
function audience_summary(int $days = 30): array
{
    $breakdown = audience_breakdown($days, 1);
    $total = 0;
    foreach ($breakdown['devices'] as $row) {
        $total += (int)$row['c'];
    }
    $hostCount = 0;
    try {
        $since = date('Y-m-d', time() - ($days - 1) * 86400);
        $stmt = db()->prepare('SELECT COUNT(DISTINCT host) FROM click_audience WHERE day >= ?');
        $stmt->execute([$since]);
        $hostCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // 忽略统计异常
    }
    return [
        'total'      => $total,
        'hosts'      => $hostCount,
        'top_device' => (string)($breakdown['devices'][0]['device'] ?? '暂无数据'),
    ];
}
