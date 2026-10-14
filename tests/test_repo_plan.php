<?php
require_once __DIR__ . '/bootstrap.php';

t('PlanRepo 同租户可复用 plan_code、跨租户互不影响', function () {
    QG\Tenant::set(['id' => 1, 'mode' => 'shared', 'db_name' => '']);
    $a = QG\repos\PlanRepo::create(['plan_code' => 'T8-CODE', 'plan_name' => '租户1方案', 'status' => 1]);
    assert_true($a > 0);
    QG\Tenant::set(['id' => 2, 'mode' => 'shared', 'db_name' => '']);
    $b = QG\repos\PlanRepo::create(['plan_code' => 'T8-CODE', 'plan_name' => '租户2方案', 'status' => 1]);
    assert_true($b > 0, '第二个租户用同一 plan_code 不应撞唯一键');
    // 清理
    QG\Db::exec('DELETE FROM biz_plan WHERE tenant_id IN (1,2) AND plan_code = ?', ['T8-CODE']);
    QG\Tenant::set(null);
});
