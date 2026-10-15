#!/usr/bin/env php83
<?php
// 播种/重置平台超管。用法：php83 scripts/seed_platform_admin.php <username> <password≥10位> [姓名]
// 幂等：username 已存在则重置密码并启用。重置时若省略姓名参数，real_name 会一并重置为 username。
// 密码强度：本脚本只强制 ≥10 位；生产请用独立强密码，勿复用 dev 的示例（Platform@12345 仅测试环境）。
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$root = dirname(__DIR__) . '/api';
spl_autoload_register(function (string $class) use ($root): void {
    $prefix = 'QG\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require $root . '/src/App.php';
require $root . '/src/Response.php';

use QG\ControlDb;

$u = $argv[1] ?? '';
$p = $argv[2] ?? '';
if ($u === '' || strlen($p) < 10) {
    fwrite(STDERR, "用法：php83 scripts/seed_platform_admin.php <username> <password≥10位> [姓名]\n");
    exit(1);
}
$hash = password_hash($p, PASSWORD_DEFAULT);
$exists = (bool) ControlDb::val('SELECT COUNT(*) FROM saas_platform_user WHERE username = ?', [$u]);
if ($exists) {
    ControlDb::exec('UPDATE saas_platform_user SET password_hash = ?, real_name = ?, status = 1 WHERE username = ?',
        [$hash, $argv[3] ?? $u, $u]);
    echo "已重置：{$u}\n";
} else {
    $id = ControlDb::insert('saas_platform_user', [
        'username' => $u, 'real_name' => $argv[3] ?? $u, 'password_hash' => $hash, 'status' => 1,
    ]);
    echo "已创建：{$u} (#{$id})\n";
}
