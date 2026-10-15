<?php
require_once __DIR__ . '/bootstrap.php';

use QG\Auth;

t('platform payload（puid，无 uid）能通过 verify', function () {
    $token = Auth::sign(['scope' => 'platform', 'puid' => 1, 'uname' => 'platform', 'exp' => time() + 3600]);
    $p = Auth::verify($token);
    assert_true($p !== null, '带 puid 的 platform token 应能通过校验');
    assert_eq('platform', $p['scope']);
    assert_eq(1, (int) $p['puid']);
});

t('过期 token 仍被拒', function () {
    $token = Auth::sign(['scope' => 'platform', 'puid' => 1, 'exp' => time() - 10]);
    assert_true(Auth::verify($token) === null, '过期 token 必须校验失败');
});

t('登录后 self::$user 不保留 password_hash', function () {
    // CLI 下已有输出，setcookie() 会告警；仅在测试侧静音，不改 Auth 实现。
    $lvl = error_reporting(0);
    try {
        Auth::login(
            ['id' => 9001, 'username' => 'x', 'password_hash' => '$2y$10$fakehash'],
            ['id' => 9001, 'mode' => 'shared', 'db_name' => '']
        );
        $u = Auth::user();
        assert_true(!array_key_exists('password_hash', $u), '租户登录后 Auth::user() 不应残留 password_hash');

        Auth::platformLogin(['id' => 1, 'username' => 'platform', 'password_hash' => '$2y$10$fakehash']);
        $u = Auth::user();
        assert_true(!array_key_exists('password_hash', $u), '平台登录后 Auth::user() 不应残留 password_hash');
        assert_eq('platform', $u['scope']);
    } finally {
        error_reporting($lvl);
    }
});
