#!/usr/bin/env php83
<?php
// 存量附件搬迁：uploads/order/... → uploads/t<tid>/order/...，并同步 biz_order_file.file_path。
// 幂等：file_path 已带 tN/ 前缀的行不再匹配 'order/%'，重复执行零改动。
// 用法：php83 scripts/migrate_uploads_namespace.php [--dry-run]
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
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

use QG\App;

$dry = in_array('--dry-run', $argv, true);
$cfg = App::config('db');
$app = App::config('app');
$base = rtrim($app['upload_dir'], '/\\');

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']),
    $cfg['user'], $cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$rows = $pdo->query("SELECT id, tenant_id, file_path FROM biz_order_file WHERE file_path LIKE 'order/%'")->fetchAll();
$total = count($rows);
$moved = 0;
$missing = 0;
echo ($dry ? '[dry-run] ' : '') . "待搬迁：{$total} 条\n";
$upd = $pdo->prepare('UPDATE biz_order_file SET file_path = ? WHERE id = ?');
foreach ($rows as $r) {
    $old = $r['file_path'];
    $new = 't' . (int) $r['tenant_id'] . '/' . $old;
    $src = $base . '/' . $old;
    $dst = $base . '/' . $new;
    if (!is_file($src)) {
        echo "  [缺文件] #{$r['id']} {$old}（跳过，DB 不动）\n";
        $missing++;
        continue;
    }
    if ($dry) {
        echo "  {$old} -> {$new}\n";
        continue;
    }
    if (!is_dir(dirname($dst)) && !mkdir(dirname($dst), 0775, true)) {
        echo "  [失败] 无法建目录：" . dirname($dst) . "\n";
        $missing++;
        continue;
    }
    if (!rename($src, $dst)) {
        echo "  [失败] 移动失败：{$old}\n";
        $missing++;
        continue;
    }
    $upd->execute([$new, (int) $r['id']]);
    $moved++;
}
echo ($dry ? '[dry-run] ' : '') . "完成：迁移 {$moved}，缺文件/失败 {$missing}，共 {$total}\n";
