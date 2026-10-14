<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/http.php';

t('缺租户代码时登录被拒', function () {
    if (!qg_site_up()) { echo "      站点未启动，跳过（见 ops/apache/ 里的 vhost）\n"; return; }
    $r = qg_post_json('/api/auth/login', ['username' => 'admin', 'password' => 'admin123']);
    assert_contains($r, '租户代码');
});

t('租户代码不存在时登录被拒', function () {
    if (!qg_site_up()) { return; }
    $r = qg_post_json('/api/auth/login', ['tenant' => 'nosuchtenant', 'username' => 'admin', 'password' => 'admin123']);
    assert_contains($r, '租户代码不存在');
});

t('正确租户代码 + 账号可登录，会话带 scope=tenant 与租户代码', function () {
    if (!qg_site_up()) { return; }
    $jar = tempnam(sys_get_temp_dir(), 'qgck');
    $r = qg_post_json('/api/auth/login', ['tenant' => 'demo', 'username' => 'admin', 'password' => 'admin123'],
        ['-c', $jar]);
    assert_contains($r, '"code":0', '登录应成功：' . $r);

    $me = qg_curl('/api/auth/me', ['-b', $jar]);
    assert_contains($me, '"scope":"tenant"', '/auth/me 应带租户作用域：' . $me);
    assert_contains($me, '"tenant_code":"demo"', '/auth/me 应带租户代码：' . $me);
    @unlink($jar);
});

t('错误密码登录被拒', function () {
    if (!qg_site_up()) { return; }
    $r = qg_post_json('/api/auth/login', ['tenant' => 'demo', 'username' => 'admin', 'password' => 'wrong-pass']);
    assert_contains($r, '账号或密码错误');
});
