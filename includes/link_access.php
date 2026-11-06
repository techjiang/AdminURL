<?php
/**
 * AdminURL 短链访问密码
 *
 * 密码用 bcrypt 单向哈希存入 links.access_password，明文不落库、不出现在导出文件里。
 * 验证通过后按 link_id 写入会话授权，同一访客无需重复输入；失败按短码在会话内限速。
 */

/**
 * 校验明密码（空串表示「不设置 / 不修改」，由调用方决定语义）
 *
 * @return array{ok: bool, error: string}
 */
function validate_link_password(string $plain): array
{
    if ($plain === '') {
        return ['ok' => true, 'error' => ''];
    }
    $len = mb_strlen($plain);
    if ($len < 4 || $len > 128) {
        return ['ok' => false, 'error' => '访问密码需为 4-128 个字符'];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * 生成密码哈希
 */
function hash_link_password(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT);
}

/**
 * 链接是否启用密码保护
 */
function link_is_protected(array|string $link): bool
{
    $value = is_array($link) ? (string)($link['access_password'] ?? '') : $link;
    return trim($value) !== '';
}

/**
 * 统一解析表单 / API 中的密码字段
 *
 * - clear 字段为真：返回 null（清除密码）
 * - 明文非空：校验后返回新哈希
 * - 明文为空：返回 $currentHash（保持不变）
 *
 * @return array{ok: bool, value: ?string, error: string}
 */
function resolve_link_password(array $input, ?string $currentHash, string $field = 'access_password', string $clearField = 'clear_access_password'): array
{
    if (!empty($input[$clearField])) {
        return ['ok' => true, 'value' => null, 'error' => ''];
    }
    $plain = trim((string)($input[$field] ?? ''));
    if ($plain === '') {
        return ['ok' => true, 'value' => $currentHash, 'error' => ''];
    }
    $check = validate_link_password($plain);
    if (!$check['ok']) {
        return ['ok' => false, 'value' => null, 'error' => $check['error']];
    }
    return ['ok' => true, 'value' => hash_link_password($plain), 'error' => ''];
}

/**
 * 同一短码 10 分钟内连续失败 5 次后暂停校验
 */
function link_password_locked(string $code): bool
{
    $key = strtolower($code);
    $state = $_SESSION['link_pw_fails'][$key] ?? null;
    if (!is_array($state)) {
        return false;
    }
    if (time() - (int)($state['first'] ?? 0) > 600) {
        unset($_SESSION['link_pw_fails'][$key]);
        return false;
    }
    return (int)($state['count'] ?? 0) >= 5;
}

/**
 * 记录一次密码校验失败
 */
function link_password_fail(string $code): void
{
    $key = strtolower($code);
    $state = $_SESSION['link_pw_fails'][$key] ?? null;
    if (!is_array($state) || time() - (int)($state['first'] ?? 0) > 600) {
        $state = ['count' => 0, 'first' => time()];
    }
    $state['count'] = (int)$state['count'] + 1;
    $_SESSION['link_pw_fails'][$key] = $state;
}

/**
 * 品牌化密码输入表单（供 go.php 的错误页容器复用）
 */
function render_password_form(string $error = ''): string
{
    $html = '<form method="post" class="pw-form">' . csrf_field()
        . '<input type="password" name="access_password" placeholder="请输入访问密码" required autofocus '
        . 'autocomplete="current-password" maxlength="128">'
        . '<button type="submit">继续访问</button></form>';
    if ($error !== '') {
        $html .= '<p class="pw-error">' . e($error) . '</p>';
    }
    return $html;
}
