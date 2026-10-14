<?php
require_once __DIR__ . '/bootstrap.php';

// 每个用例都显式设一次租户上下文，避免影响其它测试文件
function qg_t13_ctx(int $id): void
{
    QG\Tenant::set(['id' => $id, 'mode' => 'shared', 'db_name' => '']);
}

t('FileRepo 附件只在本租户可见、不可删', function () {
    qg_t13_ctx(1);
    $id = QG\repos\FileRepo::create([
        'order_id'   => 1,
        'file_type'  => '单测',
        'file_name'  => 'QG单测-附件-任务13.pdf',
        'file_path'  => 'order/2026/10/unit-test.pdf',
        'file_size'  => 123,
        'created_at' => QG\App::now(),
    ]);
    try {
        assert_true($id > 0, '新增应返回自增 id');
        $row = QG\repos\FileRepo::find($id);
        assert_eq((int) $row['tenant_id'], 1, '新增行必须落在当前租户');
        assert_eq((int) $row['order_id'], 1, 'order_id 应原样写入');

        $ids = array_map('intval', array_column(QG\repos\FileRepo::listByOrder(1), 'id'));
        assert_true(in_array($id, $ids, true), '本租户附件列表应读得到新附件');

        qg_t13_ctx(99999);
        assert_eq(QG\repos\FileRepo::find($id), null, '其它租户不得读到该附件');
        assert_eq(QG\repos\FileRepo::listByOrder(1), [], '其它租户不得在该订单下读到附件');
        assert_eq(QG\repos\FileRepo::delete($id), 0, '其它租户 delete 不得命中');

        qg_t13_ctx(1);
        assert_eq(QG\repos\FileRepo::delete($id), 1, '本租户删除应命中 1 行');
        assert_eq(QG\repos\FileRepo::find($id), null, '删除后本租户也读不到');
    } finally {
        qg_test_pdo()->exec('DELETE FROM biz_order_file WHERE id = ' . (int) $id);
        qg_t13_ctx(1);
    }
});

t('FileRepo::orderExists 只认本租户订单', function () {
    $orderNo = 'QG-T13-' . bin2hex(random_bytes(4));
    qg_test_pdo()->exec("INSERT INTO biz_order (tenant_id, order_no, is_deleted) VALUES (1, '{$orderNo}', 0)");
    $orderId = (int) qg_test_pdo()->lastInsertId();
    try {
        qg_t13_ctx(1);
        assert_true(QG\repos\FileRepo::orderExists($orderId), '本租户订单应存在');

        qg_t13_ctx(99999);
        assert_eq(QG\repos\FileRepo::orderExists($orderId), false, '其它租户不得看到该订单');
    } finally {
        qg_test_pdo()->exec('DELETE FROM biz_order WHERE id = ' . $orderId);
        qg_t13_ctx(1);
    }
});
