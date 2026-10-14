<?php
// 守卫：① 仓储之外不得直接调 Db::；② 仓储里碰业务表的 SQL 必须带 tenant_id。
// 退出码非 0 = 有违规。
$root = dirname(__DIR__) . '/api/src';
$businessTables = ['org_company','biz_plan','biz_order','biz_order_container','biz_order_hscode',
    'biz_order_address','biz_isf','biz_inspect','biz_pickup','biz_order_file','biz_order_log',
    'fin_account','fin_recharge','fin_payable','sys_user','sys_role','sys_permission',
    'sys_role_permission','sys_config','sys_dict','sys_log'];
$errors = [];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    $p = $f->getPathname();
    if (!str_ends_with($p, '.php')) {
        continue;
    }
    $isRepo = str_contains($p, '/repos/');
    // 连接层自己当然会调 Db::，跳过
    if (!$isRepo && (str_ends_with($p, '/Db.php') || str_ends_with($p, '/ControlDb.php'))) {
        continue;
    }
    $src = file_get_contents($p);

    if (!$isRepo) {
        if (preg_match_all('/\bDb::(all|one|val|exec|insert|update)\s*\(/', $src, $ms, PREG_OFFSET_CAPTURE)) {
            foreach ($ms[0] as $i => $hit) {
                $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                $errors[] = "仓储外直接用了 Db::{$ms[1][$i][0]}()：{$p}:{$line}";
            }
        }
        continue;
    }

    if (preg_match_all('/[\'"]([^\'"]*(?:SELECT|INSERT|UPDATE|DELETE)[^\'"]*)[\'"]/i', $src, $ms, PREG_OFFSET_CAPTURE)) {
        foreach ($ms[1] as $hit) {
            $sql = $hit[0];
            foreach ($businessTables as $tbl) {
                if (preg_match('/\b' . preg_quote($tbl, '/') . '\b/i', $sql) && stripos($sql, 'tenant_id') === false) {
                    $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                    $errors[] = "仓储 SQL 缺 tenant_id：{$p}:{$line} → " . substr(preg_replace('/\s+/', ' ', $sql), 0, 90);
                }
            }
        }
    }
}

if ($errors) { fwrite(STDERR, implode("\n", array_unique($errors)) . "\n"); exit(1); }
echo "guard ok\n";
