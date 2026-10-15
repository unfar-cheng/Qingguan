<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/bootstrap.php';

use QG\App;
use QG\ControlDb;

/**
 * 品牌（Logo 上传 + 登录页 branding）与附件上传命名空间的 HTTP 级测试。
 * 需要站点在跑，否则逐条跳过。
 */

/** 登录 demo/admin 拿 cookie jar；站点不在返回 null */
function admin_jar(): ?string
{
    if (!qg_site_up()) { return null; }
    $jar = tempnam(sys_get_temp_dir(), 'qgck');
    $r = qg_post_json('/api/auth/login', ['tenant' => 'demo', 'username' => 'admin', 'password' => 'admin123'], ['-c', $jar]);
    if (strpos($r, '"code":0') === false) { @unlink($jar); return null; }
    return $jar;
}

/** 1x1 透明 PNG */
function tmp_png(): string
{
    $f = tempnam(sys_get_temp_dir(), 'qgpng') . '.png';
    file_put_contents($f, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    return $f;
}

t('Logo：png 上传成功并写回控制面 logo_url', function () {
    $jar = admin_jar();
    if (!$jar) { return; }
    $png = tmp_png();
    $r = qg_curl('/api/tenant/logo', ['-b', $jar, '-F', 'file=@' . $png . ';type=image/png']);
    assert_contains($r, '"code":0', 'png 应上传成功：' . $r);
    assert_contains($r, 't1/branding/logo.png', 'URL 应在 t1/branding 下：' . $r);
    $url = ControlDb::val("SELECT logo_url FROM saas_tenant WHERE code = 'demo'");
    assert_contains((string) $url, '/uploads/t1/branding/logo.png', '控制面应写回 logo_url');
    // 清理：还原 logo_url，删除落盘文件
    ControlDb::exec("UPDATE saas_tenant SET logo_url = '' WHERE code = 'demo'");
    $cfg = App::config('app');
    @unlink(rtrim($cfg['upload_dir'], '/\\') . '/t1/branding/logo.png');
    @unlink($png);
    @unlink($jar);
});

t('Logo：svg 被拒（XSS 风险）', function () {
    $jar = admin_jar();
    if (!$jar) { return; }
    $svg = tempnam(sys_get_temp_dir(), 'qgsvg') . '.svg';
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $r = qg_curl('/api/tenant/logo', ['-b', $jar, '-F', 'file=@' . $svg . ';type=image/svg+xml']);
    assert_contains($r, '仅支持 PNG/JPG', 'svg 应被拒：' . $r);
    @unlink($svg);
    @unlink($jar);
});

t('branding：demo 返回企业名，未知代码 found=false', function () {
    if (!qg_site_up()) { return; }
    $r = qg_curl('/api/tenant/branding?code=demo');
    assert_contains($r, '"found":true', 'demo 应命中：' . $r);
    assert_contains($r, '示例企业', '应返回租户名：' . $r);
    $r = qg_curl('/api/tenant/branding?code=nosuch');
    assert_contains($r, '"found":false', '未知代码 found=false：' . $r);
});

t('订单附件上传路径带 t<tid>/ 前缀', function () {
    $jar = admin_jar();
    if (!$jar) { return; }
    $png = tmp_png();
    $r = qg_curl('/api/upload', ['-b', $jar, '-F', 'file=@' . $png . ';type=image/png']);
    assert_contains($r, '"code":0', '上传应成功：' . $r);
    assert_contains($r, '"file_path":"t1/order/', 'file_path 应带 t1/ 前缀：' . $r);
    // 清理落盘文件
    $cfg = App::config('app');
    if (preg_match('/"file_path":"([^"]+)"/', $r, $m)) {
        @unlink(rtrim($cfg['upload_dir'], '/\\') . '/' . str_replace('\\/', '/', $m[1]));
    }
    @unlink($png);
    @unlink($jar);
});

t('附件搬迁脚本 dry-run 可执行且幂等', function () {
    $out = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && php83 scripts/migrate_uploads_namespace.php --dry-run 2>&1');
    assert_contains($out, 'dry-run', '应输出 dry-run 摘要：' . $out);
});
