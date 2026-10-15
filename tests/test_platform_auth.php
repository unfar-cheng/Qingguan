<?php
require_once __DIR__ . '/bootstrap.php';

use QG\App;
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
