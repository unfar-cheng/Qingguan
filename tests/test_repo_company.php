<?php
require_once __DIR__ . '/bootstrap.php';

// 每个用例都显式设一次租户上下文，避免影响其它测试文件
function qg_t8_ctx(int $id): void
{
    QG\Tenant::set(['id' => $id, 'mode' => 'shared', 'db_name' => '']);
}

t('CompanyRepo 只返回本租户的记录', function () {
    qg_t8_ctx(1);
    [$rows, $total] = QG\repos\CompanyRepo::page(1, '', '', 10, 0);
    foreach ($rows as $r) { assert_eq((int) $r['tenant_id'], 1, '不应混入其它租户'); }
    assert_true($total >= count($rows), 'total 应不小于本页条数');
});

t('CompanyRepo 列表 total 与直连口径一致', function () {
    qg_t8_ctx(1);
    [, $total] = QG\repos\CompanyRepo::page(1, '', '', 10, 0);
    $direct = (int) qg_test_val('SELECT COUNT(*) FROM org_company WHERE tenant_id = 1 AND is_deleted = 0 AND company_type = 1');
    assert_eq($total, $direct, '分页 total 应等于直连口径');
});

t('CompanyRepo 增删改查只在本租户内可见', function () {
    qg_t8_ctx(1);
    $id = QG\repos\CompanyRepo::create([
        'company_type' => 1,
        'company_name' => 'QG单测-发货人-任务8',
        'status'       => 1,
    ]);
    try {
        assert_true($id > 0, '新增应返回自增 id');
        $row = QG\repos\CompanyRepo::find($id);
        assert_eq((int) $row['tenant_id'], 1, '新增行必须落在当前租户');
        assert_true(QG\repos\CompanyRepo::exists($id), 'exists 应命中本租户');
        assert_eq(QG\repos\CompanyRepo::orderCount($id), 0, '新公司没有订单引用');

        assert_eq(QG\repos\CompanyRepo::update($id, ['city' => '单测市']), 1, 'update 应命中 1 行');
        assert_eq(QG\repos\CompanyRepo::find($id)['city'], '单测市', '更新应生效');

        qg_t8_ctx(99999);
        assert_eq(QG\repos\CompanyRepo::find($id), null, '其它租户不得读到该行');
        assert_eq(QG\repos\CompanyRepo::exists($id), false, '其它租户 exists 应为 false');
        assert_eq(QG\repos\CompanyRepo::update($id, ['city' => '越权市']), 0, '其它租户 update 不得命中');

        qg_t8_ctx(1);
        assert_eq(QG\repos\CompanyRepo::softDelete($id), 1, '软删应命中 1 行');
        assert_eq(QG\repos\CompanyRepo::find($id), null, '软删后本租户也读不到');
    } finally {
        qg_test_pdo()->exec('DELETE FROM org_company WHERE id = ' . (int) $id);
        qg_t8_ctx(1);
    }
});

t('CompanyRepo::orderStats 字段齐全且与本租户直连口径一致', function () {
    qg_t8_ctx(1);
    $s = QG\repos\CompanyRepo::orderStats(1);
    foreach (['released', 'forecast', 'inspecting', 'inspected'] as $k) {
        assert_true(array_key_exists($k, $s) && is_int($s[$k]), "字段 {$k} 应为整数");
    }
    $direct = (int) qg_test_val(
        'SELECT COUNT(DISTINCT i.order_id) FROM biz_inspect i
           JOIN biz_order o ON o.id = i.order_id AND o.tenant_id = i.tenant_id
          WHERE i.tenant_id = 1 AND o.is_deleted = 0 AND o.importer_id = 1'
    );
    assert_eq($s['inspected'], $direct, '已查验口径应与直连一致');
});
