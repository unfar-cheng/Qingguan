<?php
require_once __DIR__ . '/bootstrap.php';

t('订单列表严格按租户隔离', function () {
    // 用两行不同租户的订单做夹具
    $t1 = 9001; $t2 = 9002;
    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    QG\Db::exec('DELETE FROM biz_order WHERE tenant_id IN (?, ?) AND order_no LIKE ?', [$t1, $t2, 'ISO-%']);

    // biz_order 无 status 列，清关状态列名是 customs_status（见 db/schema.sql）
    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    QG\Db::insert('biz_order', ['tenant_id' => $t1, 'order_no' => 'ISO-A', 'is_deleted' => 0, 'customs_status' => 'forecast']);
    QG\Tenant::set(['id' => $t2, 'mode' => 'shared', 'db_name' => '']);
    QG\Db::insert('biz_order', ['tenant_id' => $t2, 'order_no' => 'ISO-B', 'is_deleted' => 0, 'customs_status' => 'forecast']);

    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    $a = QG\Db::all("SELECT order_no FROM biz_order WHERE tenant_id = ? AND order_no LIKE 'ISO-%'", [$t1]);
    assert_eq(count($a), 1, '租户1 只应看到自己的 ISO-A');
    assert_eq($a[0]['order_no'], 'ISO-A');

    // 仓储列表同样只返回本租户
    [$rows, $total] = QG\repos\OrderRepo::page(['o.order_no LIKE ?'], ['ISO-%'], 'o.id', 'desc', 50, 0);
    assert_eq($total, 1, '租户1 的订单总数应为 1');
    assert_eq(count($rows), 1, '租户1 的订单列表只应有 1 行');
    assert_eq($rows[0]['order_no'], 'ISO-A');

    QG\Tenant::set(['id' => $t2, 'mode' => 'shared', 'db_name' => '']);
    [$rows2, $total2] = QG\repos\OrderRepo::page(['o.order_no LIKE ?'], ['ISO-%'], 'o.id', 'desc', 50, 0);
    assert_eq($total2, 1, '租户2 的订单总数应为 1');
    assert_eq($rows2[0]['order_no'], 'ISO-B');

    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    QG\Db::exec('DELETE FROM biz_order WHERE tenant_id IN (?, ?) AND order_no LIKE ?', [$t1, $t2, 'ISO-%']);
    QG\Tenant::set(null);
});
