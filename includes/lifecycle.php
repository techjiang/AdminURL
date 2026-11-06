<?php
/**
 * AdminURL 短链生命周期
 *
 * expires_at 以 'Y-m-d H:i:s' 文本存储（站点时区），NULL / 空串表示永久有效。
 * 新装数据库由 migrate_schema() 直接建列；已安装的旧库通过 ensure_lifecycle_schema()
 * 在首次请求时幂等补齐，无需手工执行 SQL，也不会改动任何已有数据。
 */

/**
 * 幂等升级：为旧数据库补上 expires_at 列与索引
 *
 * 每个请求只检查一次；表尚不存在（未安装）时直接跳过，交由 migrate_schema() 处理。
 */
function ensure_lifecycle_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $columns = [];
        foreach ($pdo->query('PRAGMA table_info(links)')->fetchAll() as $col) {
            $columns[] = (string)$col['name'];
        }
        if ($columns === []) {
            return;
        }
        if (!in_array('expires_at', $columns, true)) {
            $pdo->exec('ALTER TABLE links ADD COLUMN expires_at TEXT DEFAULT NULL');
        }
        if (!in_array('tag', $columns, true)) {
            $pdo->exec("ALTER TABLE links ADD COLUMN tag TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('check_code', $columns, true)) {
            $pdo->exec('ALTER TABLE links ADD COLUMN check_code INTEGER DEFAULT NULL');
        }
        if (!in_array('checked_at', $columns, true)) {
            $pdo->exec('ALTER TABLE links ADD COLUMN checked_at TEXT DEFAULT NULL');
        }
        if (!in_array('device', array_map(static fn($c) => (string)$c['name'], $pdo->query('PRAGMA table_info(click_logs)')->fetchAll()), true)) {
            $pdo->exec("ALTER TABLE click_logs ADD COLUMN device TEXT NOT NULL DEFAULT ''");
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_links_expires ON links (expires_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_links_tag ON links (tag)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_click_logs_device ON click_logs (device)');
    } catch (Throwable $e) {
        // 升级失败时保持原有行为：未设置有效期的链接仍然永久有效
    }
}

/**
 * 校验并归一化有效期输入
 *
 * 接受 ''（清除有效期）、'YYYY-MM-DD'、'YYYY-MM-DD HH:MM'、'YYYY-MM-DD HH:MM:SS'
 * 以及 <input type="datetime-local"> 的 'YYYY-MM-DDTHH:MM'。
 *
 * @return array{ok: bool, value: ?string, error: string}
 */
function validate_expiry_input(string $raw, bool $mustBeFuture = true): array
{
    $fail = static fn(string $msg): array => ['ok' => false, 'value' => null, 'error' => $msg];
    $raw = trim($raw);
    if ($raw === '') {
        return ['ok' => true, 'value' => null, 'error' => ''];
    }

    $raw = str_replace('T', ' ', $raw);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/', $raw, $m) !== 1) {
        return $fail('有效期格式不正确，请填写 YYYY-MM-DD HH:MM');
    }

    $year = (int)$m[1];
    $month = (int)$m[2];
    $day = (int)$m[3];
    $hour = (int)($m[4] ?? 0);
    $minute = (int)($m[5] ?? 0);
    $second = (int)($m[6] ?? 0);
    if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
        return $fail('有效期不是有效的日期时间');
    }

    $value = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    if ($mustBeFuture && strtotime($value) <= time()) {
        return $fail('有效期必须晚于当前时间');
    }
    return ['ok' => true, 'value' => $value, 'error' => ''];
}

/**
 * 数据库值 -> datetime-local 输入框值
 */
function expiry_for_input(mixed $stored): string
{
    $stored = trim((string)$stored);
    if ($stored === '') {
        return '';
    }
    $ts = strtotime($stored);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

/**
 * 链接是否已过期（可传整行数组，也可直接传有效期字符串）
 */
function link_is_expired(array|string $link): bool
{
    $value = is_array($link) ? (string)($link['expires_at'] ?? '') : $link;
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    $ts = strtotime($value);
    return $ts !== false && $ts <= time();
}

/**
 * 有效期的展示文案
 */
function expiry_label(array|string $link): string
{
    $value = is_array($link) ? (string)($link['expires_at'] ?? '') : $link;
    $value = trim($value);
    if ($value === '') {
        return '永久有效';
    }
    return link_is_expired($value) ? $value . '（已过期）' : $value;
}

/**
 * 状态徽章：已停用 > 已过期 > 正常，全站统一口径
 *
 * @return array{label: string, class: string}
 */
function link_status_meta(array $row): array
{
    if ((int)($row['status'] ?? 1) !== 1) {
        return ['label' => '已停用', 'class' => 'badge-warn'];
    }
    if (link_is_expired($row)) {
        return ['label' => '已过期', 'class' => 'badge-danger'];
    }
    return ['label' => '正常', 'class' => 'badge-ok'];
}

/**
 * 过期与即将到期的链接数量（仅统计启用中的链接）
 *
 * @return array{expired: int, expiring: int}
 */
function link_expiry_stats(int $days = 7): array
{
    $now = date('Y-m-d H:i:s');
    $until = date('Y-m-d H:i:s', time() + $days * 86400);
    $base = "FROM links WHERE status = 1 AND expires_at IS NOT NULL AND expires_at <> ''";
    try {
        $stmt = db()->prepare('SELECT COUNT(*) ' . $base . ' AND expires_at <= ?');
        $stmt->execute([$now]);
        $expired = (int)$stmt->fetchColumn();

        $stmt = db()->prepare('SELECT COUNT(*) ' . $base . ' AND expires_at > ? AND expires_at <= ?');
        $stmt->execute([$now, $until]);
        $expiring = (int)$stmt->fetchColumn();
        return ['expired' => $expired, 'expiring' => $expiring];
    } catch (Throwable $e) {
        return ['expired' => 0, 'expiring' => 0];
    }
}

/**
 * 一键克隆：复制目标地址、标题与尚未到期的有效期，生成全新短码
 *
 * 新链接默认启用、点击数从 0 开始；若原链接已经过期，则不再继承过期时间，
 * 避免克隆出来立刻无法访问。
 *
 * @return array{code: string, expires_at: ?string, tag: string}
 */
function clone_link_row(array $row, int $userId = 0): array
{
    $expiry = trim((string)($row['expires_at'] ?? ''));
    if (link_is_expired($expiry)) {
        $expiry = '';
    }

    $code = generate_unique_code((int)config('code_length', 5));
    $now = date('Y-m-d H:i:s');
    db()->prepare(
        'INSERT INTO links (code, url, title, user_id, status, expires_at, tag, created_at, updated_at)
         VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)'
    )->execute([
        $code,
        (string)$row['url'],
        (string)($row['title'] ?? ''),
        $userId,
        $expiry !== '' ? $expiry : null,
        (string)($row['tag'] ?? ''),
        $now,
        $now,
    ]);

    return [
        'code'       => $code,
        'expires_at' => $expiry !== '' ? $expiry : null,
        'tag'        => (string)($row['tag'] ?? ''),
    ];
}
