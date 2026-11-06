<?php
/**
 * AdminURL API 接口
 *
 * 用于二次开发与系统集成，统一 JSON 输出。
 *
 * 鉴权方式（二选一）：
 *   1. Authorization: Bearer <token>
 *   2. ?access_token=<token>
 *
 * 端点一览：
 *   POST /api/v1/links          创建短链     {url, code?, title?, expires_at?, tag?}
 *   GET  /api/v1/links          链接列表     {page?, size?, q?, tag?}
 *   GET  /api/v1/links/{code}   查询单个
 *   PUT  /api/v1/links/{code}   更新短链     {url?, title?, code?, expires_at?}
 *   POST /api/v1/links/{code}/clone 一键克隆（生成新短码，点击数从 0 开始）
 *   DELETE /api/v1/links/{code} 删除短链
 *   GET  /api/v1/stats          统计概览
 *   POST /api/v1/ua/check       检测 UA 是否被屏蔽  {ua}
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT . '/includes/schema.php';

// 旧数据库自动补齐有效期字段（幂等）
if (is_installed()) {
    ensure_lifecycle_schema();
}

// 强制仅 JSON
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path   = rtrim($path, '/');

// ---------- 鉴权 ----------
function api_token_from_request(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header !== '' && preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        return $m[1];
    }
    return trim((string)($_GET['access_token'] ?? ''));
}

function api_authenticate(): ?array
{
    $raw = api_token_from_request();
    if ($raw === '') {
        return null;
    }
    $hash = hash('sha256', $raw);
    $stmt = db()->prepare('SELECT * FROM api_tokens WHERE token_hash = ? AND status = 1 LIMIT 1');
    $stmt->execute([$hash]);
    $token = $stmt->fetch();
    if ($token === false) {
        return null;
    }
    // 更新最后使用时间（每分钟一次避免写放大）
    if ((time() - ((int)strtotime((string)($token['last_used_at'] ?? '2000-01-01')))) > 60) {
        db()->prepare('UPDATE api_tokens SET last_used_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), (int)$token['id']]);
    }
    return $token;
}

function api_fail(string $message, int $status = 400): never
{
    json_response(['code' => $status, 'ok' => false, 'message' => $message], $status);
}

/**
 * 滑动窗口限流：同一令牌 120 次/分钟，同一 IP 300 次/分钟
 * 超过阈值返回 429，避免接口被高频调用拖垮与暴力探测。
 */
function api_rate_limit(?array $token): void
{
    $limit_key  = 'ip:' . md5(client_ip());
    $this_limit = (int)config('api_rate_limit', 300);

    if ($token !== null) {
        $limit_key  = 'token:' . (int)$token['id'];
        $this_limit = (int)config('api_rate_token_limit', 120);
    }

    $now   = time();
    $win   = $now - ($now % 60); // 每分钟一个窗口
    $pdo   = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT hit_count FROM api_rate_limits WHERE bucket = ? AND window_start = ?');
        $stmt->execute([$limit_key, $win]);
        $row = $stmt->fetch();

        if ($row === false) {
            $pdo->prepare('INSERT INTO api_rate_limits (bucket, window_start, hit_count) VALUES (?, ?, 1)')
                ->execute([$limit_key, $win]);
        } else {
            $hit = (int)$row['hit_count'];
            if ($hit >= $this_limit) {
                $pdo->rollBack();
                api_fail('请求过于频繁，请稍后再试', 429);
            }
            $pdo->prepare('UPDATE api_rate_limits SET hit_count = hit_count + 1 WHERE bucket = ? AND window_start = ?')
                ->execute([$limit_key, $win]);
        }

        // 清理 5 分钟前的旧窗口（避免表无限膨胀）
        $pdo->prepare('DELETE FROM api_rate_limits WHERE window_start < ?')
            ->execute([$now - 300]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // 限流表异常时放行请求，避免计费/统计被误伤
        return;
    }
}

// ---------- 路由分发 ----------
$token = api_authenticate();
if ($token === null && $path !== '/api/v1/health') {
    api_fail('无效的访问令牌. 请在管理后台 -> API 接口 中获取', 401);
}

// 健康检查不参与限流
if ($path !== '/api/v1/health') {
    api_rate_limit($token);
}

// 提取路径参数：/api/v1/links/{code}
$route = '';
$param = '';
if (preg_match('#^/api/v1/links/([A-Za-z0-9]{1,32})/clone$#', $path, $m)) {
    $route = '/api/v1/links/{code}/clone';
    $param = $m[1];
} elseif (preg_match('#^/api/v1/links/([^/]+)$#', $path, $m)) {
    $route = '/api/v1/links/{code}';
    $param = $m[1];
} elseif ($path === '/api/v1/links') {
    $route = '/api/v1/links';
} elseif ($path === '/api/v1/stats') {
    $route = '/api/v1/stats';
} elseif ($path === '/api/v1/ua/check') {
    $route = '/api/v1/ua/check';
} elseif ($path === '/api/v1/health') {
    $route = '/api/v1/health';
} else {
    api_fail('接口不存在', 404);
}

// ---------- 健康检查 ----------
if ($route === '/api/v1/health') {
    json_response([
        'ok'      => true,
        'app'     => 'AdminURL',
        'version' => '1.0.0',
        'time'    => date('Y-m-d H:i:s'),
        'status'  => is_installed() ? 'ready' : 'not_installed',
    ]);
}

// ---------- 创建短链 ----------
if ($route === '/api/v1/links' && $method === 'POST') {
    $payload = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?: []);
    $url = trim((string)($payload['url'] ?? ''));
    $code = trim((string)($payload['code'] ?? ''));
    $title = trim((string)($payload['title'] ?? ''));
    $expiry = validate_expiry_input((string)($payload['expires_at'] ?? ''));
    $tag    = normalize_tag((string)($payload['tag'] ?? ''));

    if (!is_valid_url($url)) {
        api_fail('url 必须是有效的 http/https 链接');
    }
    if (!$expiry['ok']) {
        api_fail('expires_at: ' . $expiry['error']);
    }
    if ($code !== '' && !preg_match('/^[A-Za-z0-9]{3,32}$/', $code)) {
        api_fail('code 仅支持 3-32 位字母或数字');
    }
    if ($code !== '' && is_reserved_code($code)) {
        api_fail('code 是系统保留路径，请换一个', 400);
    }
    if ($code !== '' && link_exists($code)) {
        api_fail('短码已被占用: ' . $code, 409);
    }

    $code = $code !== '' ? $code : generate_unique_code((int)config('code_length', 5));

    if ($title === '' && (string)setting('auto_title', '1') === '1') {
        $title = fetch_page_title($url, (int)config('fetch_timeout', 6));
    }

    try {
        db()->prepare(
            'INSERT INTO links (code, url, title, user_id, status, expires_at, tag, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)'
        )->execute([
            $code, $url, mb_substr($title, 0, 200), (int)$token['user_id'], $expiry['value'], $tag,
            date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        api_fail('创建失败: ' . $e->getMessage(), 500);
    }

    log_event('api_shorten', 'API 创建短链接 ' . $code);
    json_response([
        'ok'         => true,
        'short'      => short_url($code),
        'code'       => $code,
        'long_url'   => $url,
        'title'      => $title,
        'expires_at' => $expiry['value'],
        'tag'        => $tag,
        'expired'    => false,
    ], 201);
}

// ---------- 列表 ----------
if ($route === '/api/v1/links' && $method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $size = min(100, max(1, (int)($_GET['size'] ?? 20)));
    $q = trim((string)($_GET['q'] ?? ''));
    $tag = trim((string)($_GET['tag'] ?? ''));

    $conditions = [];
    $params = [];
    if ($q !== '') {
        $conditions[] = '(code LIKE ? OR url LIKE ? OR title LIKE ?)';
        array_push($params, "%$q%", "%$q%", "%$q%");
    }
    if ($tag === '__none') {
        $conditions[] = "tag = ''";
    } elseif ($tag !== '') {
        $conditions[] = 'tag = ?';
        $params[] = $tag;
    }
    $where = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $total = (int)db()->query('SELECT COUNT(*) AS c FROM links' . $where)->fetch()['c'];
    $offset = ($page - 1) * $size;

    $stmt = db()->prepare('SELECT id, code, url, title, clicks, status, expires_at, tag, created_at, updated_at FROM links' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->execute([...$params, $size, $offset]);
    $rows = array_map(function ($r) {
        $r['short_url'] = short_url($r['code']);
        $r['clicks'] = (int)$r['clicks'];
        $r['expired'] = link_is_expired($r);
        return $r;
    }, $stmt->fetchAll());

    json_response([
        'ok'      => true,
        'page'    => $page,
        'size'    => $size,
        'total'   => $total,
        'pages'   => max(1, (int)ceil($total / $size)),
        'links'   => $rows,
    ]);
}

// ---------- 查询单个 ----------
if ($route === '/api/v1/links/{code}' && $method === 'GET') {
    $stmt = db()->prepare('SELECT id, code, url, title, clicks, status, expires_at, tag, created_at, updated_at FROM links WHERE code = ? LIMIT 1');
    $stmt->execute([$param]);
    $row = $stmt->fetch();
    if ($row === false) {
        api_fail('短链不存在', 404);
    }
    $row['short_url'] = short_url($row['code']);
    $row['clicks'] = (int)$row['clicks'];
    $row['expired'] = link_is_expired($row);
    json_response(['ok' => true, 'link' => $row]);
}

// ---------- 更新 ----------
if ($route === '/api/v1/links/{code}' && $method === 'PUT') {
    $stmt = db()->prepare('SELECT * FROM links WHERE code = ? LIMIT 1');
    $stmt->execute([$param]);
    $row = $stmt->fetch();
    if ($row === false) {
        api_fail('短链不存在', 404);
    }

    $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $newCode = trim((string)($payload['code'] ?? $row['code']));
    $newUrl  = trim((string)($payload['url'] ?? $row['url']));
    $newTitle = trim((string)($payload['title'] ?? $row['title']));
    $expiryRaw = array_key_exists('expires_at', $payload)
        ? (string)$payload['expires_at']
        : (string)($row['expires_at'] ?? '');
    // 编辑允许保留已经过去的有效期，否则已过期链接无法保存其他字段
    $expiry = validate_expiry_input($expiryRaw, false);
    $newTag = array_key_exists('tag', $payload)
        ? normalize_tag((string)$payload['tag'])
        : (string)($row['tag'] ?? '');

    if (!is_valid_url($newUrl)) {
        api_fail('url 必须是有效的 http/https 链接');
    }
    if (!$expiry['ok']) {
        api_fail('expires_at: ' . $expiry['error']);
    }
    if (!preg_match('/^[A-Za-z0-9]{3,32}$/', $newCode)) {
        api_fail('code 仅支持 3-32 位字母或数字');
    }
    if (is_reserved_code($newCode)) {
        api_fail('code 是系统保留路径，请换一个', 400);
    }
    $stmt = db()->prepare('SELECT id FROM links WHERE code = ? AND id != ? LIMIT 1');
    $stmt->execute([$newCode, (int)$row['id']]);
    if ($stmt->fetch()) {
        api_fail('短码已被占用: ' . $newCode, 409);
    }

    db()->prepare('UPDATE links SET code = ?, url = ?, title = ?, expires_at = ?, tag = ?, updated_at = ? WHERE id = ?')
        ->execute([
            $newCode, $newUrl, mb_substr($newTitle, 0, 200), $expiry['value'], $newTag,
            date('Y-m-d H:i:s'), (int)$row['id'],
        ]);
    log_event('api_edit', 'API 更新短链接 ' . $newCode);

    json_response([
        'ok'         => true,
        'message'    => '更新成功',
        'code'       => $newCode,
        'short_url'  => short_url($newCode),
        'expires_at' => $expiry['value'],
        'tag'        => $newTag,
        'expired'    => $expiry['value'] !== null && link_is_expired($expiry['value']),
    ]);
}

// ---------- 删除 ----------
if ($route === '/api/v1/links/{code}' && $method === 'DELETE') {
    $stmt = db()->prepare('SELECT id FROM links WHERE code = ? LIMIT 1');
    $stmt->execute([$param]);
    $row = $stmt->fetch();
    if ($row === false) {
        api_fail('短链不存在', 404);
    }
    db()->prepare('DELETE FROM links WHERE id = ?')->execute([(int)$row['id']]);
    db()->prepare('DELETE FROM click_logs WHERE link_id = ?')->execute([(int)$row['id']]);
    log_event('api_delete', 'API 删除短链接 ' . $param);
    json_response(['ok' => true, 'message' => '删除成功']);
}

// ---------- 一键克隆 ----------
if ($route === '/api/v1/links/{code}/clone' && $method === 'POST') {
    $stmt = db()->prepare('SELECT * FROM links WHERE code = ? LIMIT 1');
    $stmt->execute([$param]);
    $row = $stmt->fetch();
    if ($row === false) {
        api_fail('短链不存在', 404);
    }
    try {
        $clone = clone_link_row($row, (int)$token['user_id']);
    } catch (Throwable $e) {
        api_fail('克隆失败: ' . $e->getMessage(), 500);
    }
    log_event('api_clone', 'API 克隆短链接 ' . $param . ' -> ' . $clone['code']);
    json_response([
        'ok'         => true,
        'code'       => $clone['code'],
        'short_url'  => short_url($clone['code']),
        'source'     => $param,
        'expires_at' => $clone['expires_at'],
        'tag'        => $clone['tag'],
        'clicks'     => 0,
    ], 201);
}

// ---------- 统计 ----------
if ($route === '/api/v1/stats' && $method === 'GET') {
    $stats = [
        'total_links'  => (int)db()->query('SELECT COUNT(*) AS c FROM links')->fetch()['c'],
        'total_clicks' => (int)db()->query('SELECT COALESCE(SUM(clicks),0) AS c FROM links')->fetch()['c'],
        'pending'      => (int)db()->query('SELECT COUNT(*) AS c FROM click_logs')->fetch()['c'],
        'blocked_ua'   => (int)db()->query('SELECT COUNT(*) AS c FROM ua_blacklist')->fetch()['c'],
    ];
    json_response(['ok' => true, 'stats' => $stats]);
}

// ---------- UA 检测 ----------
if ($route === '/api/v1/ua/check' && $method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $ua = trim((string)($payload['ua'] ?? ''));
    if ($ua === '') {
        api_fail('ua 不能为空');
    }
    json_response(['ok' => true, 'blocked' => ua_match($ua)]);
}

// 其他组合：方法不支持
api_fail('请求方法不支持', 405);