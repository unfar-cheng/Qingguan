<?php
require_once __DIR__ . '/bootstrap.php';

t('不存在 code=super 的角色，且租户管理员为 tenant_admin', function () {
    QG\Tenant::set(['id' => 1, 'mode' => 'shared', 'db_name' => '']);
    assert_eq((int) QG\Db::val("SELECT COUNT(*) FROM sys_role WHERE code = 'super'"), 0);
    assert_true((int) QG\Db::val("SELECT COUNT(*) FROM sys_role WHERE code = 'tenant_admin'") >= 1);
    assert_eq(QG\Db::val("SELECT name FROM sys_role WHERE code = 'tenant_admin'"), '租户系统管理员');
});
