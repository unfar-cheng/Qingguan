<?php
require_once __DIR__ . '/lib.php';

$base = getenv('QG_BASE') ?: 'http://127.0.0.1:8080';

function qg_http(string $url, array $opt = []): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, $opt + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body === false ? '' : (string) $body;
}

function qg_site_up(string $base): bool
{
    return qg_http($base . '/api/auth/me') !== '';
}

t('缺租户代码时登录被拒', function () use ($base) {
    if (!qg_site_up($base)) { echo "      站点未启动，跳过（先跑 tools/dev-server.php）\n"; return; }
    $r = qg_http($base . '/api/auth/login', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => '{"username":"admin","password":"admin123"}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    assert_contains($r, '租户代码');
});

t('租户代码不存在时登录被拒', function () use ($base) {
    if (!qg_site_up($base)) { return; }
    $r = qg_http($base . '/api/auth/login', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => '{"tenant":"nosuchtenant","username":"admin","password":"admin123"}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    assert_contains($r, '租户代码不存在');
});

t('正确租户代码 + 账号可登录，会话带 scope=tenant 与 tid', function () use ($base) {
    if (!qg_site_up($base)) { return; }
    $jar = tempnam(sys_get_temp_dir(), 'qgck');
    $r = qg_http($base . '/api/auth/login', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => '{"tenant":"demo","username":"admin","password":"admin123"}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR  => $jar,
    ]);
    assert_contains($r, '"code":0', '登录应成功：' . $r);

    $me = qg_http($base . '/api/auth/me', [CURLOPT_COOKIEFILE => $jar]);
    assert_contains($me, '"scope":"tenant"', '/auth/me 应带租户作用域：' . $me);
    assert_contains($me, '"tenant_code":"demo"', '/auth/me 应带租户代码：' . $me);
    unlink($jar);
});

t('错误密码登录被拒', function () use ($base) {
    if (!qg_site_up($base)) { return; }
    $r = qg_http($base . '/api/auth/login', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => '{"tenant":"demo","username":"admin","password":"wrong-pass"}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    assert_contains($r, '账号或密码错误');
});
