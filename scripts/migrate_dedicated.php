#!/usr/bin/env php83
<?php
// 专用档迁移扇出（最小实现；《运维基础设施》阶段 2 正式机制落地后淘汰本脚本）。
// 用法：php83 scripts/migrate_dedicated.php <migration.sql> [--dry-run]
// 库清单 = 控制面 mode='dedicated' 且已开通（db_name 非空、active/suspended），按 db_name 排序。
// 每库独立执行、独立报告；某库失败不中断后续库；任一失败最终退出码 1。
// 连接走 config 的 provision_db 段（有 qingguan_t_% 的全部权限）。
// 语句切分沿用 ProvisionRepo::splitStatements 的约定：语句字符串值内不得含 ASCII 分号。
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
use QG\DedicatedDb;
use QG\repos\ProvisionRepo;

$file = $argv[1] ?? '';
$dry  = in_array('--dry-run', $argv, true);
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "用法：php83 scripts/migrate_dedicated.php <migration.sql> [--dry-run]\n");
    exit(1);
}
$sql = (string) file_get_contents($file);

$rows = ControlDb::all(
    "SELECT id, code, db_name FROM saas_tenant WHERE mode = 'dedicated' AND db_name <> '' AND status IN ('active','suspended') ORDER BY db_name"
);
if (!$rows) {
    echo "没有已开通的专用库，无事可做\n";
    exit(0);
}
echo ($dry ? '[dry-run] ' : '') . '待执行库（' . count($rows) . " 个）：\n";
foreach ($rows as $r) {
    echo "  - {$r['db_name']}（{$r['code']} #{$r['id']}）\n";
}
if ($dry) {
    exit(0);
}

$pdo  = DedicatedDb::provisionPdo();
$fail = 0;
foreach ($rows as $r) {
    $db = str_replace('`', '', $r['db_name']);
    try {
        $pdo->exec("USE `{$db}`");
        foreach (ProvisionRepo::splitStatements($sql) as $stmt) {
            // 迁移的幂等分支会 EXECUTE 出结果集（如 SELECT 1 空操作），必须取尽，
            // 否则下一句报 2014 "Cannot execute queries while other unbuffered queries are active"
            $st = $pdo->query($stmt);
            do {
                $st->fetchAll();
            } while ($st->nextRowset());
            $st->closeCursor();
        }
        echo "OK   {$r['db_name']}\n";
    } catch (Throwable $e) {
        $fail++;
        echo "FAIL {$r['db_name']}：" . $e->getMessage() . "\n";
    }
}
exit($fail ? 1 : 0);
