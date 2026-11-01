<?php
/**
 * AdminURL 登出
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (is_logged_in()) {
    log_event('logout', '用户登出: ' . (current_user()['username'] ?? ''));
}
do_logout();
redirect(base_path('/admin/login.php'));