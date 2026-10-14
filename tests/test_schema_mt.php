<?php
require_once __DIR__ . '/bootstrap.php';

t('所有业务表都有 tenant_id 且现有行归租户 1', function () {
    $tables = ['sys_user','sys_role','sys_permission','sys_role_permission','sys_config','sys_dict','sys_log',
        'org_company','biz_plan','biz_order','biz_order_container','biz_order_hscode','biz_order_address',
        'biz_isf','biz_inspect','biz_pickup','biz_order_file','biz_order_log',
        'fin_account','fin_recharge','fin_payable'];
    foreach ($tables as $tbl) {
        $col = qg_test_val("SHOW COLUMNS FROM `{$tbl}` LIKE 'tenant_id'");
        assert_true($col !== null, "{$tbl} 缺 tenant_id");
    }
    assert_eq((int) qg_test_val('SELECT COUNT(*) FROM sys_user WHERE tenant_id <> 1'), 0, '现有用户都应归租户 1');
});

t('唯一键已改造成租户复合键，且旧键不再存在', function () {
    $expect = [
        'sys_user'       => ['uk_username',  'uk_tenant_username'],
        'sys_role'       => ['uk_code',      'uk_tenant_code'],
        'sys_permission' => ['uk_code',      'uk_tenant_code'],
        'sys_config'     => ['uk_cfg_key',   'uk_tenant_cfg_key'],
        'sys_dict'       => ['uk_type_key',  'uk_tenant_type_key'],
        'biz_plan'       => ['uk_plan_code', 'uk_tenant_plan'],
        'biz_order'      => ['uk_order_no',  'uk_tenant_order_no'],
        'fin_account'    => ['uk_company',   'uk_tenant_company'],
    ];
    foreach ($expect as $tbl => [$old, $new]) {
        assert_eq((int) qg_test_val(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?", [$tbl, $old]
        ), 0, "{$tbl}.{$old} 应已删除");
        assert_eq((int) qg_test_val(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?", [$tbl, $new]
        ), 1, "{$tbl}.{$new} 应存在");
    }
});

t('列表复合索引已就位', function () {
    foreach ([['biz_order','idx_tenant_list'],['org_company','idx_tenant_list'],['biz_order_file','idx_tenant_order']] as [$tbl,$idx]) {
        assert_eq((int) qg_test_val(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?", [$tbl, $idx]
        ), 1, "{$tbl}.{$idx} 应存在");
    }
});
