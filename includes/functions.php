<?php
/**
 * AdminURL 通用函数库
 */

/**
 * 读取配置项
 */
function config(?string $key = null, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = $GLOBALS['config'] ?? [];
    }
    if ($key === null) {
        return $cfg;
    }
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

/**
 * HTML 转义输出
 */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 跳转
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * 读取站点设置的 KV 表
 */
function setting(string $key, mixed $default = null): mixed
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query('SELECT `key`, `value` FROM settings')->fetchAll();
            foreach ($rows as $row) {
                $cache[$row['key']] = $row['value'];
            }
        } catch (Throwable $e) {
            // 表尚未初始化时返回默认值
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/**
 * 写入站点设置
 */
function set_setting(string $key, mixed $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
         ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`'
    );
    $stmt->execute([$key, (string)$value]);
}

/**
 * JSON 响应输出
 */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 生成带签名的 CSRF Token
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 输出 CSRF 隐藏域
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * 校验 CSRF Token
 */
function verify_csrf(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 生成短码使用的字符集（去掉易混淆字符 0/O/1/l/I）
 */
function short_code_alphabet(): string
{
    return 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
}

/**
 * 随机生成短码
 */
function random_short_code(int $length): string
{
    $alphabet = short_code_alphabet();
    $maxIndex = strlen($alphabet) - 1;
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $maxIndex)];
    }
    return $code;
}

/**
 * 生成一个在数据库中唯一的短码
 */
function generate_unique_code(int $length = 5): string
{
    // 保留路径，避免与系统路由冲突
    $reserved = ['admin', 'api', 'assets', 'install', 'login', 'register', 'settings', 'migrate', 'go', 'index', 'docs', 's', 'static', 'robots', 'favicon'];
    do {
        $code = random_short_code($length);
    } while (in_array(strtolower($code), $reserved, true) || link_exists($code));
    return $code;
}

/**
 * 判断短码是否存在
 */
function link_exists(string $code): bool
{
    $stmt = db()->prepare('SELECT 1 FROM links WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    return (bool)$stmt->fetch();
}

/**
 * 校验长链接是否为合法 HTTP(S) URL
 */
function is_valid_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048) {
        return false;
    }
    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return false;
    }

    // 直接以 IP 访问的，拒绝内网/保留地址，防 SSRF
    if (filter_var($parts['host'], FILTER_VALIDATE_IP)) {
        if (is_private_ip($parts['host'])) {
            return false;
        }
    } elseif (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $parts['host']) !== 1) {
        return false;
    }
    return true;
}

/**
 * 获取客户端真实 IP
 */
function client_ip(): string
{
    $candidates = [];
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $ip) {
            $candidates[] = trim($ip);
        }
    }
    $candidates[] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * 判断 IP 是否属于私网/保留地址（用于防 SSRF）
 */
function is_private_ip(string $ip): bool
{
    $ip = trim($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }
        $long = (int)$long;
        // 0.0.0.0/8, 10.0.0.0/8, 100.64.0.0/10, 127.0.0.0/8,
        // 169.254.0.0/16, 172.16.0.0/12, 192.0.0.0/24,
        // 192.168.0.0/16, 198.18.0.0/15, 224.0.0.0/4
        $ipv4Ranges = [
            [0, 0x00FFFFFF],
            [0x0A000000, 0x0AFFFFFF],
            [0x64400000, 0x647FFFFF],
            [0x7F000000, 0x7FFFFFFF],
            [0xA9FE0000, 0xA9FEFFFF],
            [0xAC100000, 0xAC1FFFFF],
            [0xC0000000, 0xC00000FF],
            [0xC0A80000, 0xC0A8FFFF],
            [0xC6120000, 0xC633FFFF],
            [0xE0000000, 0xFFFFFFFF],
        ];
        foreach ($ipv4Ranges as [$start, $end]) {
            if ($long >= $start && $long <= $end) {
                return true;
            }
        }
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $low = strtolower($ip);
        if ($low === '::' || $low === '::1' || str_starts_with($low, '::ffff:')) {
            return true;
        }
        // fc00::/7, fe80::/10
        if (str_starts_with($low, 'fc') || str_starts_with($low, 'fd')
            || str_starts_with($low, 'fe8') || str_starts_with($low, 'fe9')
            || str_starts_with($low, 'fea') || str_starts_with($low, 'feb')) {
            return true;
        }
    }
    return false;
}

/**
 * 解析 URL 并返回可信任的 IP（防 SSRF：拒绝内网目标）
 * 返回 null 表示目标不可访问
 */
function resolve_public_ip(string $host): ?string
{
    // 若 host 本身是 IP
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return is_private_ip($host) ? null : (string)$host;
    }

    // DNS 解析，取第一个公网地址
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if ($records === false || count($records) === 0) {
        return null;
    }
    foreach ($records as $r) {
        $ip = $r['ip'] ?? $r['ipv6'] ?? null;
        if ($ip && !is_private_ip($ip)) {
            return (string)$ip;
        }
    }
    return null;
}

/**
 * 探测系统可用的 CA 证书包路径；找不到返回 null
 */
function detect_ca_file(): ?string
{
    static $cached = false;
    static $caFile = null;
    if ($cached !== false) {
        return $caFile;
    }
    $cached = true;

    $candidates = [];
    // PHP 配置指定的 CA
    $iniCurl = (string)ini_get('curl.cainfo');
    $iniOpenssl = (string)ini_get('openssl.cafile');
    if ($iniCurl !== '') {
        $candidates[] = $iniCurl;
    }
    if ($iniOpenssl !== '') {
        $candidates[] = $iniOpenssl;
    }
    // 数据目录内的自定义 CA（管理员可自行放置）
    $candidates[] = ROOT . '/data/cacert.pem';
    $candidates[] = ROOT . '/data/ca-bundle.crt';
    // 常见系统路径
    $candidates[] = '/etc/ssl/certs/ca-certificates.crt';
    $candidates[] = '/etc/ssl/certs/ca-bundle.crt';
    $candidates[] = '/etc/pki/tls/certs/ca-bundle.crt';
    $candidates[] = '/etc/ssl/ca-bundle.pem';
    $candidates[] = 'C:/Windows/System32/curl-ca-bundle.crt';

    foreach ($candidates as $path) {
        if ($path !== '' && is_file($path) && is_readable($path)) {
            $caFile = $path;
            break;
        }
    }
    return $caFile;
}

/**
 * 抓取页面标题（带防 SSRF 与超时与大小限制）
 * 失败返回空字符串
 */
function fetch_page_title(string $url, int $timeout = 6): string
{
    $url = trim($url);
    if (!is_valid_url($url)) {
        return '';
    }

    $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
    if ($host === '' || resolve_public_ip($host) === null) {
        return '';
    }

    if (!function_exists('curl_init')) {
        return '';
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return '';
    }

    // 探测系统可用的 CA 证书包；存在则强制校验对端证书，缺失时仅在该环境下降级为不校验（SSRF 防护仍生效）
    $caFile = detect_ca_file();
    $sslVerify = $caFile !== null;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => $sslVerify,
        CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        CURLOPT_CAINFO         => $caFile ?: '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AdminURL/1.0; +' . (config('base_url') ?: '') . ')',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_ENCODING       => '',
        CURLOPT_NOBODY         => false,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 400) {
        return '';
    }

    // 限制响应体大小，防止恶意大页面拖垮服务
    $maxBytes = 512 * 1024;
    if (strlen($body) > $maxBytes) {
        $body = substr($body, 0, $maxBytes);
    }

    // 探测字符集并转 UTF-8
    $charset = 'auto';
    if (preg_match('/charset\s*=\s*["\']?([a-zA-Z0-9_\-]+)/i', substr($body, 0, 4096), $m)) {
        $charset = $m[1];
    }
    if (function_exists('mb_convert_encoding') && strcasecmp($charset, 'utf-8') !== 0) {
        $body = @mb_convert_encoding($body, 'UTF-8', $charset);
    }

    // 去掉 script/style 里的干扰
    $body = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', '', $body) ?? $body;

    // 优先 og:title，其次 <title>
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\'](.*?)["\']/is', $body, $m)
        || preg_match('/<meta[^>]+content=["\'](.*?)["\'][^>]+property=["\']og:title["\']/is', $body, $m)
        || preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
        $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
        return mb_substr($title, 0, 200);
    }

    return '';
}

/**
 * 判断 UA 是否命中屏蔽规则（用于 UA 屏蔽与后台调试）
 * 支持子串匹配与 * 通配符；返回 bool
 */
function ua_match(string $ua): bool
{
    if ($ua === '') {
        return false;
    }
    static $rules = null;
    if ($rules === null) {
        try {
            $rules = db()->query('SELECT pattern FROM ua_blacklist ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $rules = [];
        }
    }
    foreach ($rules as $pattern) {
        $pattern = trim($pattern);
        if ($pattern === '') {
            continue;
        }
        if (strpos($pattern, '*') !== false) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
            if (preg_match($regex, $ua)) {
                return true;
            }
        } elseif (stripos($ua, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * 短链路径前缀
 *
 * 默认使用根路径 /{code}（标准短链形态）；
 * 管理员可在设置中切换为 /s/{code} 兼容拓展模式，用于沿用旧链接。
 * 无论生成哪种形式，服务端始终同时解析两种路径。
 */
function short_path_prefix(): string
{
    return (string)setting('short_path_mode', 'root') === 's' ? '/s/' : '/';
}

/**
 * 构造完整短链接
 */
function short_url(string $code): string
{
    $base = rtrim((string)config('base_url', ''), '/');
    return $base . short_path_prefix() . ltrim($code, '/');
}

/**
 * 记录登录/操作日志（保留最近 500 条）
 */
function log_event(string $action, string $detail = ''): void
{
    try {
        $stmt = db()->prepare('INSERT INTO logs (action, detail, ip, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$action, mb_substr($detail, 0, 500), client_ip(), date('Y-m-d H:i:s')]);
        db()->exec('DELETE FROM logs WHERE id NOT IN (SELECT id FROM logs ORDER BY id DESC LIMIT 500)');
    } catch (Throwable $e) {
        // 日志失败不影响主流程
    }
}