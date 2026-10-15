<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/bootstrap.php';

use QG\App;
use QG\ControlDb;

function platform_jar(): ?string
{
    $jar = tempnam(sys_get_temp_dir(), 'qgpf');
    $r = qg_post_json('/api/platform/login', ['username' => 'platform', 'password' => 'Platform@12345'], ['-c', $jar]);
    if (strpos($r, '"code":0') === false) {
        @unlink($jar);
        return null; // 未播种平台超管或站点未起
    }
    return $jar;
}

t('平台登录成功且 /platform/me 返回 scope=platform', function () {
    if (!qg_site_up()) { return; }
    $jar = platform_jar();
    if (!$jar) { echo "      平台超管未播种，跳过（先跑 scripts/seed_platform_admin.php）\n"; return; }
    $me = qg_curl('/api/platform/me', ['-b', $jar]);
    assert_contains($me, '"scope":"platform"', '/platform/me 应带平台作用域：' . $me);
    @unlink($jar);
});

t('租户令牌调平台接口被 403', function () {
    if (!qg_site_up()) { return; }
    $jar = tempnam(sys_get_temp_dir(), 'qgck');
    qg_post_json('/api/auth/login', ['tenant' => 'demo', 'username' => 'admin', 'password' => 'admin123'], ['-c', $jar]);
    $r = qg_curl('/api/platform/tenants', ['-b', $jar]);
    assert_contains($r, '需要平台管理员权限', '租户令牌必须被平台接口拒绝：' . $r);
    @unlink($jar);
});

t('未登录调平台接口被 401', function () {
    if (!qg_site_up()) { return; }
    $r = qg_curl('/api/platform/tenants');
    assert_contains($r, '"code":401', '未登录应 401：' . $r);
});
