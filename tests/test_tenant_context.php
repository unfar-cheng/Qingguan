<?php
require_once __DIR__ . '/bootstrap.php';

t('Tenant 未设置时 id 为 0，requireId 抛错', function () {
    QG\Tenant::set(null);
    assert_eq(QG\Tenant::id(), 0);
    $threw = false;
    try { QG\Tenant::requireId(); } catch (Throwable $e) { $threw = true; }
    assert_true($threw, '无上下文时 requireId 应报错');
});

t('Tenant 设置后能读回 id 与 mode', function () {
    QG\Tenant::set(['id' => 7, 'mode' => 'shared', 'db_name' => '']);
    assert_eq(QG\Tenant::id(), 7);
    assert_eq(QG\Tenant::mode(), 'shared');
    QG\Tenant::set(null);
});
