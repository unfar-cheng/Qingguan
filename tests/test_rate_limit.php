<?php
require_once __DIR__ . '/bootstrap.php';

use QG\RateLimit;

t('窗口内前 N 次放行、第 N+1 次拦截', function () {
    RateLimit::$dir = sys_get_temp_dir() . '/qg-rl-' . uniqid();
    $bucket = 'test|' . uniqid();
    assert_true(RateLimit::allow($bucket, 2, 3600), '第 1 次应放行');
    assert_true(RateLimit::allow($bucket, 2, 3600), '第 2 次应放行');
    assert_true(!RateLimit::allow($bucket, 2, 3600), '第 3 次应拦截');
});

t('不同 bucket 互不影响', function () {
    RateLimit::$dir = sys_get_temp_dir() . '/qg-rl-' . uniqid();
    $a = 'a|' . uniqid();
    $b = 'b|' . uniqid();
    RateLimit::allow($a, 1, 3600);
    assert_true(!RateLimit::allow($a, 1, 3600), 'a 应已被限');
    assert_true(RateLimit::allow($b, 1, 3600), 'b 不应受 a 影响');
});
