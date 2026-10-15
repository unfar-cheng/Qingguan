<?php
require_once __DIR__ . '/bootstrap.php';

use QG\RateLimit;

/** 递归删除目录（测试 tmp 清理用） */
function qg_rl_rrmdir(string $dir): void
{
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $p = $dir . '/' . $e;
        if (is_dir($p) && !is_link($p)) { qg_rl_rrmdir($p); } else { @unlink($p); }
    }
    @rmdir($dir);
}

/** 注册一个限流用例：独占 tmp 目录，结束时恢复 RateLimit::$dir 并清理现场 */
function qg_rl_case(string $name, callable $fn): void
{
    t($name, function () use ($fn) {
        $dir = sys_get_temp_dir() . '/qg-rl-' . uniqid();
        mkdir($dir, 0775, true);
        RateLimit::$dir = $dir;
        try {
            $fn($dir);
        } finally {
            RateLimit::$dir = null;
            @chmod($dir, 0775); // 目录不可写用例结束后先恢复权限才能删
            qg_rl_rrmdir($dir);
        }
    });
}

qg_rl_case('窗口内前 N 次放行、第 N+1 次拦截', function (string $dir) {
    $bucket = 'test|' . uniqid();
    assert_true(RateLimit::allow($bucket, 2, 3600), '第 1 次应放行');
    assert_true(RateLimit::allow($bucket, 2, 3600), '第 2 次应放行');
    assert_true(!RateLimit::allow($bucket, 2, 3600), '第 3 次应拦截');
});

qg_rl_case('不同 bucket 互不影响', function (string $dir) {
    $a = 'a|' . uniqid();
    $b = 'b|' . uniqid();
    RateLimit::allow($a, 1, 3600);
    assert_true(!RateLimit::allow($a, 1, 3600), 'a 应已被限');
    assert_true(RateLimit::allow($b, 1, 3600), 'b 不应受 a 影响');
});

qg_rl_case('存储文件损坏时自愈为合法 JSON', function (string $dir) {
    $bucket = 'corrupt|' . uniqid();
    $file = $dir . '/' . sha1($bucket) . '.json';
    file_put_contents($file, '{not-json');
    assert_true(RateLimit::allow($bucket, 5, 3600), '损坏的存量文件不应影响放行');
    $hits = json_decode((string) file_get_contents($file), true);
    assert_true(is_array($hits), '文件应被重写为合法 JSON');
    assert_eq(count($hits), 1, '重写后应恰好记录本次');
});

qg_rl_case('窗口过期后计数重置', function (string $dir) {
    $bucket = 'expire|' . uniqid();
    $window = 60;
    assert_true(RateLimit::allow($bucket, 1, $window), '第 1 次应放行');
    assert_true(!RateLimit::allow($bucket, 1, $window), '窗口内第 2 次应拦截');
    // 手工把存量时间戳改到窗外，模拟窗口滑过（不给 RateLimit 注入时钟）
    $file = $dir . '/' . sha1($bucket) . '.json';
    file_put_contents($file, json_encode([time() - $window - 1]));
    assert_true(RateLimit::allow($bucket, 1, $window), '窗口过期后应重新放行');
});

qg_rl_case('存储目录不可写时 fail-open 放行', function (string $dir) {
    // 若以 root 跑测试会误过（root 无视 000 权限）；本套件以 frappe 用户跑
    chmod($dir, 0000);
    assert_true(RateLimit::allow('unwritable|' . uniqid(), 5, 3600), '目录不可写应放行，不挡业务');
});
