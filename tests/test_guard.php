<?php
require_once __DIR__ . '/lib.php';

t('守卫：无仓储外 Db:: 调用、仓储 SQL 均带 tenant_id', function () {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/guard_no_raw_business_sql.php') . ' 2>&1';
    exec($cmd, $out, $code);
    assert_eq($code, 0, "守卫未通过：\n" . implode("\n", $out));
});
