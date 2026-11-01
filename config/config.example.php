<?php
/**
 * AdminURL 配置文件
 *
 * 首次安装时系统会把本文件复制为 config.php 并自动填入随机密钥。
 * 日常运行读取的是 config/config.php，修改配置请直接编辑该文件。
 */

return [

    // 站点地址（结尾不要带斜杠），例如 https://s.example.com
    'base_url' => 'http://localhost:8000',

    // SQLite 数据库文件路径
    'db_file' => dirname(__DIR__) . '/data/adminurl.db',

    // 随机密钥：用于 CSRF / 会话签名，安装时自动生成，请勿泄露
    'secret_key' => 'CHANGE_ME_IN_INSTALL',

    // 时区
    'timezone' => 'Asia/Shanghai',

    // 会话名称
    'session_name' => 'ADMINURL_SESSID',

    // 默认短码长度（3 - 12）
    'code_length' => 5,

    // 延迟计数：点击日志累计超过该数量时合并到主计数
    'click_flush_threshold' => 100,

    // 延迟计数：距上次合并超过该秒数时也触发合并
    'click_flush_interval' => 60,

    // 抓取链接标题的超时时间（秒）
    'fetch_timeout' => 6,

    // 登录失败锁定时间（分钟）
    'login_lock_minutes' => 10,

    // API 限流：同一 IP 每分钟最多请求数
    'api_rate_limit' => 300,

    // API 限流：同一令牌每分钟最多请求数
    'api_rate_token_limit' => 120,
];