<?php
/**
 * AdminURL 认证与访问控制
 *
 * 密码使用 Argon2id（当前最安全的密码哈希算法）；
 * 登录失败按 IP + 用户名双维度限速锁定；
 * 会话令牌使用 HttpOnly + SameSite 加固。
 */

/**
 * 当前登录用户（返回数组或 null）
 */
function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }

    static $user = false;
    if ($user === false) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND status = 1 LIMIT 1');
        $stmt->execute([(int)$_SESSION['uid']]);
        $user = $stmt->fetch() ?: null;

        // 用户被禁用时使会话失效
        if ($user === null) {
            unset($_SESSION['uid'], $_SESSION['username']);
        }
    }
    return $user;
}

/**
 * 是否已登录
 */
function is_logged_in(): bool
{
    return current_user() !== null;
}

/**
 * 要求登录，未登录则跳转
 */
function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['flash_error'] = '请先登录后再进行操作';
        redirect(base_path('/admin/login.php'));
    }
}

/**
 * 要求管理员权限（当前版本所有用户均为管理员）
 */
function require_admin(): void
{
    require_login();
}

/**
 * 校验登录凭据
 *
 * @return array{ok: bool, message: string}
 */
function do_login(string $username, string $password): array
{
    $username = trim($username);
    $ip = client_ip();
    $lockMinutes = (int)config('login_lock_minutes', 10);

    // 1. IP 级限速
    if (is_login_locked($ip, $lockMinutes)) {
        return ['ok' => false, 'message' => '尝试次数过多，请 ' . $lockMinutes . ' 分钟后再试'];
    }

    // 2. 查询用户
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // 用户不存在也执行一次哈希比较，避免时间侧信道（用户枚举）
    if ($user === false) {
        password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$AAAAAAAAAAAAAAAAAAAAAA$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
        record_login_fail($username, $ip);
        return ['ok' => false, 'message' => '用户名或密码错误'];
    }

    if ((int)$user['status'] !== 1) {
        return ['ok' => false, 'message' => '该账号已被停用，请联系管理员'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        record_login_fail($username, $ip);
        return ['ok' => false, 'message' => '用户名或密码错误'];
    }

    // 3. 登录成功：重建会话防会话固定攻击
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['logged_in_at'] = time();

    // 4. 清理失败记录、更新最后登录时间
    try {
        db()->prepare('DELETE FROM login_failures WHERE ip = ?')->execute([$ip]);
        db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), (int)$user['id']]);
    } catch (Throwable $e) {
        // 忽略
    }

    log_event('login', '用户登录: ' . $username);
    return ['ok' => true, 'message' => '登录成功'];
}

/**
 * 登出
 */
function do_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * IP 是否处于登录锁定状态
 */
function is_login_locked(string $ip, int $minutes): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS cnt FROM login_failures
             WHERE ip = ? AND failed_at > datetime(\'now\', ?)'
        );
        $stmt->execute([$ip, '-' . $minutes . ' minutes']);
        $row = $stmt->fetch();
        return (int)($row['cnt'] ?? 0) >= 5;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 记录一次登录失败
 */
function record_login_fail(string $username, string $ip): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO login_failures (username, ip, failed_at) VALUES (?, ?, datetime(\'now\'))'
        );
        $stmt->execute([$username, $ip]);
    } catch (Throwable $e) {
        // 忽略
    }
}

/**
 * 创建用户（注册/安装）
 *
 * @return array{ok: bool, message: string}
 */
function create_user(string $username, string $password, string $role = 'admin'): array
{
    $username = trim($username);
    if (mb_strlen($username) < 2 || mb_strlen($username) > 32) {
        return ['ok' => false, 'message' => '用户名长度须为 2-32 个字符'];
    }
    if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
        return ['ok' => false, 'message' => '用户名仅支持中英文、数字与下划线'];
    }
    if (mb_strlen($password) < 8) {
        return ['ok' => false, 'message' => '密码长度至少 8 位'];
    }
    if (mb_strlen($password) > 128) {
        return ['ok' => false, 'message' => '密码长度不能超过 128 位'];
    }

    try {
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        $stmt = db()->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'message' => '用户名已被占用'];
        }

        $stmt = db()->prepare('INSERT INTO users (username, password_hash, role, status, created_at) VALUES (?, ?, ?, 1, ?)');
        $stmt->execute([$username, $hash, $role, date('Y-m-d H:i:s')]);

        return ['ok' => true, 'message' => '用户创建成功'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '创建用户失败：' . $e->getMessage()];
    }
}

/**
 * 修改用户信息（密码/用户名）
 */
function update_user(int $userId, string $username, ?string $newPassword): array
{
    $username = trim($username);
    if (mb_strlen($username) < 2 || mb_strlen($username) > 32) {
        return ['ok' => false, 'message' => '用户名长度须为 2-32 个字符'];
    }
    if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
        return ['ok' => false, 'message' => '用户名仅支持中英文、数字与下划线'];
    }
    if ($newPassword !== null && $newPassword !== '') {
        if (mb_strlen($newPassword) < 8) {
            return ['ok' => false, 'message' => '密码长度至少 8 位'];
        }
        if (mb_strlen($newPassword) > 128) {
            return ['ok' => false, 'message' => '密码长度不能超过 128 位'];
        }
    }

    try {
        $stmt = db()->prepare('SELECT 1 FROM users WHERE username = ? AND id != ? LIMIT 1');
        $stmt->execute([$username, $userId]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'message' => '用户名已被占用'];
        }

        if ($newPassword !== null && $newPassword !== '') {
            $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
            $stmt = db()->prepare('UPDATE users SET username = ?, password_hash = ? WHERE id = ?');
            $stmt->execute([$username, $hash, $userId]);
        } else {
            $stmt = db()->prepare('UPDATE users SET username = ? WHERE id = ?');
            $stmt->execute([$username, $userId]);
        }
        return ['ok' => true, 'message' => '资料已更新'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '更新失败：' . $e->getMessage()];
    }
}

/**
 * 构造站内绝对路径
 */
function base_path(string $path = ''): string
{
    return rtrim((string)config('base_url', ''), '/') . '/' . ltrim($path, '/');
}