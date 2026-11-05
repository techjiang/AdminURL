<?php
/**
 * AdminURL 短链跳转
 *
 * 主路由：/{code}          标准根路径短链（默认生成形式）
 * 拓展路由：/s/{code}       兼容旧链接，始终可访问
 * 兜底：go.php?s={code}    直连查询参数
 *
 * 三种入口最终归一到同一套短码解析流程；系统保留路径名（admin / api / assets 等）
 * 不会被当作短码，避免与后台、API 和静态目录冲突。
 *
 * 特性：
 *  - UA 屏蔽：命中黑名单的访问直接拒绝，不跳转、不计数
 *  - 延迟计数：点击只写入 click_logs 缓冲队列，达到阈值或间隔后再批量合并到 links.clicks，
 *    避免高并发下对主表反复 UPDATE 造成压力
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once ROOT . '/includes/schema.php';

// 未安装时引导安装
if (!is_installed()) {
    redirect(base_path('/install/'));
}

// 获取短码：优先查询参数，其次解析请求路径 /{code} 或 /s/{code}
$code = trim((string)($_GET['s'] ?? ''));
if ($code === '') {
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    if (preg_match('#^/(?:s/)?([A-Za-z0-9]{1,32})/?$#', $path, $m)) {
        $code = (string)$m[1];
    }
}

if ($code === '' || !preg_match('/^[A-Za-z0-9]{1,32}$/', $code)) {
    render_short_error(404, '短链接不存在', '这个地址没有对应的短链接，请检查是否输入完整。');
}

// 系统保留路径名不得作为短码（防御 Web 服务器伪静态配置遗漏）
$reservedCodes = [
    'admin', 'api', 'assets', 'config', 'data', 'docs', 'includes', 'install',
    'go', 'index', 'router', 'robots', 'favicon', 's', 'changelog', 'license', 'readme',
];
if (in_array(strtolower($code), $reservedCodes, true)) {
    render_short_error(404, '短链接不存在', '该地址属于系统保留路径，不能作为短链接访问。');
}

// ============ 访问控制 ============
// 「开放使用」关闭时，短链仅限登录管理员访问；开启后匿名访客可直接跳转。
// API 调用方不受此限制（使用令牌鉴权，见 api/index.php）。
$publicShorten = (string)setting('public_shorten', '0') === '1';
if (!$publicShorten && !is_logged_in()) {
    redirect(base_path('/admin/login.php'));
}

// 查询链接
$stmt = db()->prepare('SELECT * FROM links WHERE code = ? LIMIT 1');
$stmt->execute([$code]);
$link = $stmt->fetch();

if ($link === false) {
    render_short_error(404, '短链接不存在', '你访问的短链接不存在或已被删除。');
}

// 链接被禁用
if ((int)$link['status'] !== 1) {
    render_short_error(404, '短链接已停用', '该短链接已被管理员停用，暂时无法访问。');
}

// ============ UA 屏蔽 ============
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (ua_match($ua)) {
    render_short_error(403, '访问被拒绝', '当前访问方式已被站点屏蔽规则拦截。');
}

// ============ 延迟计数 ============
record_click_delayed((int)$link['id']);

// ============ 跳转 ============
$redirectType = (string)setting('redirect_type', '302');
header('Location: ' . $link['url'], true, $redirectType === '301' ? 301 : 302);
exit;

/**
 * 品牌化错误页（404 / 403）
 *
 * 短链跳转失败时展示，独立内联样式，自动适配深浅色，不依赖后台 CSS。
 */
function render_short_error(int $status, string $title, string $desc): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');

    $site = e((string)setting('site_name', 'AdminURL'));
    $home = e(base_path('/'));
    $favicon = e(base_path('/assets/img/favicon.svg'));
    $code = (string)$status;
    $title = e($title);
    $desc = e($desc);

    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>{$code} · {$site}</title>
<link rel="icon" href="{$favicon}" type="image/svg+xml">
<style>
:root{--bg:#f5f6fb;--card:#fff;--text:#171d33;--muted:#5f6981;--border:#e8eaf3}
@media (prefers-color-scheme:dark){:root{--bg:#0b0f1a;--card:#131a29;--text:#eef2ff;--muted:#a0acc4;--border:#252f46}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);color:var(--text);font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;-webkit-font-smoothing:antialiased}
body::before{content:"";position:fixed;top:-220px;left:50%;width:820px;height:600px;transform:translateX(-50%);background:radial-gradient(ellipse,rgba(91,91,246,.18),transparent 70%);pointer-events:none}
.box{position:relative;width:min(480px,calc(100% - 36px));padding:38px 34px;text-align:center;background:var(--card);border:1px solid var(--border);border-radius:22px;box-shadow:0 18px 50px rgba(23,29,51,.11)}
.badge{display:inline-flex;align-items:center;justify-content:center;width:62px;height:62px;border-radius:18px;color:#fff;background:linear-gradient(135deg,#5b5bf6,#9155f7);font-size:22px;font-weight:800;letter-spacing:-.03em;box-shadow:0 10px 26px rgba(91,91,246,.28)}
h1{margin:22px 0 8px;font-size:22px;font-weight:750;letter-spacing:-.02em}
p{margin:0;color:var(--muted);font-size:14px}
a{display:inline-flex;align-items:center;justify-content:center;margin-top:26px;min-height:44px;padding:0 24px;border-radius:11px;background:linear-gradient(120deg,#5b5bf6,#9155f7);color:#fff;text-decoration:none;font-weight:650;font-size:14px}
a:hover{opacity:.9}
.foot{margin-top:26px;padding-top:18px;border-top:1px solid var(--border);font-size:12px;color:var(--muted)}
</style>
</head>
<body>
<div class="box">
<div class="badge">{$code}</div>
<h1>{$title}</h1>
<p>{$desc}</p>
<a href="{$home}">返回首页</a>
<div class="foot">Powered by {$site}</div>
</div>
</body>
</html>
HTML;
    exit;
}

/**
 * 延迟计数：点击写入缓冲队列，达到阈值或超时后合并
 */
function record_click_delayed(int $linkId): void
{
    record_click_daily($linkId);
    try {
        $stmt = db()->prepare(
            'INSERT INTO click_logs (link_id, ip, ua, referer, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $linkId,
            client_ip(),
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 500),
            date('Y-m-d H:i:s'),
        ]);
        maybe_flush_clicks();
    } catch (Throwable $e) {
        // 计数失败不影响跳转
    }
}

/**
 * 判断当前是否需要合并点击缓冲
 */
function maybe_flush_clicks(): void
{
    static $flushing = false;
    if ($flushing) {
        return;
    }
    $flushing = true;

    try {
        $threshold = (int)config('click_flush_threshold', 100);
        $interval  = (int)config('click_flush_interval', 60);

        // 距离上次合并的时间
        $last = (int)setting('last_click_flush', 0);
        $elapsed = time() - $last;

        // 缓冲队列长度
        $cnt = (int)db()->query('SELECT COUNT(*) AS c FROM click_logs')->fetch()['c'];

        if ($cnt >= $threshold || $elapsed >= $interval) {
            flush_clicks();
        }
    } catch (Throwable $e) {
        // 忽略
    }
    $flushing = false;
}

/**
 * 批量合并：把缓冲队列中的点击数累加到 links.clicks，然后清空缓冲
 */
function flush_clicks(): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec(
            'UPDATE links SET clicks = clicks + (
                SELECT COUNT(*) FROM click_logs WHERE click_logs.link_id = links.id
             )'
        );
        $pdo->exec('DELETE FROM click_logs');
        set_setting('last_click_flush', (string)time());
        $pdo->commit();
        log_event('click_flush', '合并点击计数');
    } catch (Throwable $e) {
        $pdo->rollBack();
    }
}