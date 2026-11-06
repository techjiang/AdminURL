<?php
/**
 * AdminURL 目标地址健康检查
 *
 * 用 PHP 流封装发 HEAD 请求，不依赖 curl 扩展；
 * 复用 is_valid_url() / resolve_public_ip() 的 SSRF 防护，禁止探测内网目标。
 * 结果写入 links.check_code 与 links.checked_at，供后台列表与检查页展示。
 */

/**
 * 探测单个目标地址
 *
 * @return array{code: ?int, ok: bool, error: string}
 */
function health_check_url(string $url, int $timeout = 4): array
{
    $fail = static fn(string $msg): array => ['code' => null, 'ok' => false, 'error' => $msg];
    if (!is_valid_url($url)) {
        return $fail('目标地址无效');
    }
    $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
    if ($host === '' || resolve_public_ip($host) === null) {
        return $fail('目标主机不可达或被安全策略拦截');
    }

    $ssl = ['verify_peer' => false, 'verify_peer_name' => false];
    $caFile = detect_ca_file();
    if ($caFile !== null) {
        $ssl = ['cafile' => $caFile, 'verify_peer' => true, 'verify_peer_name' => true];
    }

    $ctx = stream_context_create([
        'http' => [
            'method'          => 'HEAD',
            'timeout'         => $timeout,
            'follow_location' => 1,
            'max_redirects'   => 2,
            'ignore_errors'   => true,
            'header'          => "User-Agent: AdminURL-Health/1.0\r\nAccept: */*\r\n",
        ],
        'ssl' => $ssl,
    ]);

    $respHeaders = [];
    @file_get_contents($url, false, $ctx);
    if (isset($http_response_header) && is_array($http_response_header)) {
        $respHeaders = $http_response_header;
    }

    $code = null;
    foreach ($respHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $code = (int)$m[1];
        }
    }
    if ($code === null) {
        return $fail('连接失败或请求超时');
    }
    return ['code' => $code, 'ok' => $code >= 200 && $code < 400, 'error' => ''];
}

/**
 * 健康状态展示
 *
 * @return array{label:string, class:string}
 */
function health_meta(?int $code): array
{
    if ($code === null) {
        return ['label' => '未检测', 'class' => 'badge-warn'];
    }
    if ($code >= 200 && $code < 400) {
        return ['label' => $code . ' 正常', 'class' => 'badge-ok'];
    }
    if ($code >= 400 && $code < 500) {
        return ['label' => $code . ' 不可访问', 'class' => 'badge-danger'];
    }
    return ['label' => $code . ' 服务异常', 'class' => 'badge-danger'];
}

/**
 * 批量检查（优先未检测与最久未检测的启用链接）
 *
 * @return array{checked:int, ok:int, bad:int, details:array}
 */
function run_health_checks(int $limit = 20): array
{
    $limit = max(1, min(50, $limit));
    try {
        $rows = db()->query(
            'SELECT id, code, url FROM links WHERE status = 1
             ORDER BY checked_at IS NULL DESC, checked_at ASC, id DESC LIMIT ' . $limit
        )->fetchAll();
    } catch (Throwable $e) {
        return ['checked' => 0, 'ok' => 0, 'bad' => 0, 'details' => []];
    }

    $upd = db()->prepare('UPDATE links SET check_code = ?, checked_at = ? WHERE id = ?');
    $done = 0;
    $ok = 0;
    $bad = 0;
    $details = [];
    foreach ($rows as $row) {
        $res = health_check_url((string)$row['url'], 4);
        $upd->execute([$res['code'], date('Y-m-d H:i:s'), (int)$row['id']]);
        $done++;
        if ($res['ok']) { $ok++; } else { $bad++; }
        $details[] = [
            'code'  => (string)$row['code'],
            'http'  => $res['code'],
            'error' => $res['error'],
        ];
    }
    return ['checked' => $done, 'ok' => $ok, 'bad' => $bad, 'details' => $details];
}

/**
 * 启用链接的健康总览
 *
 * @return array{total:int, ok:int, bad:int, unchecked:int}
 */
function health_summary(): array
{
    try {
        $row = db()->query(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN check_code BETWEEN 200 AND 399 THEN 1 ELSE 0 END) AS ok,
                    SUM(CASE WHEN check_code >= 400 OR check_code IS NULL AND checked_at IS NOT NULL THEN 1 ELSE 0 END) AS bad,
                    SUM(CASE WHEN check_code IS NULL THEN 1 ELSE 0 END) AS unchecked
             FROM links WHERE status = 1"
        )->fetch();
        return [
            'total'     => (int)($row['total'] ?? 0),
            'ok'        => (int)($row['ok'] ?? 0),
            'bad'       => (int)($row['bad'] ?? 0),
            'unchecked' => (int)($row['unchecked'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['total' => 0, 'ok' => 0, 'bad' => 0, 'unchecked' => 0];
    }
}
