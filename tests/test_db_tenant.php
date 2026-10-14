<?php
require_once __DIR__ . '/bootstrap.php';

t('设置租户上下文后 Db 能读数据', function () {
    QG\Tenant::set(['id' => 1, 'mode' => 'shared', 'db_name' => '']);
    $n = QG\Db::val('SELECT COUNT(*) FROM sys_user WHERE tenant_id = 1');
    assert_true((int) $n >= 1, '租户 1 至少有一个用户');
});

t('未设置租户上下文时 Db 拒绝执行', function () {
    QG\Tenant::set(null);
    $threw = false;
    try { QG\Db::val('SELECT COUNT(*) FROM sys_user'); } catch (Throwable $e) { $threw = true; }
    assert_true($threw, '无租户上下文时不应放行 SQL');
});
