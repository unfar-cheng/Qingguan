<?php
require_once __DIR__ . '/bootstrap.php';

t('UserRepo 列表按租户隔离', function () {
    QG\Tenant::set(['id' => 1, 'mode' => 'shared', 'db_name' => '']);
    [$rows, $total] = QG\repos\UserRepo::page('', '', 1, 50, 0);
    assert_true($total >= count($rows), 'total 应不小于本页条数');
    foreach ($rows as $u) {
        assert_eq((int) $u['tenant_id'], 1, '不应出现他租户用户');
    }
    QG\Tenant::set(null);
});

t('syncPermissions 只覆盖本租户授权，同 code 角色不撞键', function () {
    $t1 = 9201;
    $t2 = 9202;
    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    QG\Db::exec('DELETE FROM sys_role_permission WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Db::exec('DELETE FROM sys_role WHERE tenant_id IN (?,?)', [$t1, $t2]);

    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    $r1 = QG\Db::insert('sys_role', ['tenant_id' => $t1, 'name' => 'A', 'code' => 'SAME', 'status' => 1]);
    QG\repos\RoleRepo::syncPermissions((int) $r1, [1, 2]);

    QG\Tenant::set(['id' => $t2, 'mode' => 'shared', 'db_name' => '']);
    $r2 = QG\Db::insert('sys_role', ['tenant_id' => $t2, 'name' => 'B', 'code' => 'SAME', 'status' => 1]);
    QG\repos\RoleRepo::syncPermissions((int) $r2, [3]);

    assert_eq((int) QG\Db::val('SELECT COUNT(*) FROM sys_role_permission WHERE tenant_id = ? AND role_id = ?', [$t1, $r1]), 2);
    assert_eq((int) QG\Db::val('SELECT COUNT(*) FROM sys_role_permission WHERE tenant_id = ? AND role_id = ?', [$t2, $r2]), 1);

    // 再同步一次：全量覆盖应只清本角色，别的角色的授权不受影响
    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    QG\repos\RoleRepo::syncPermissions((int) $r1, [4]);
    assert_eq((int) QG\Db::val('SELECT COUNT(*) FROM sys_role_permission WHERE tenant_id = ? AND role_id = ?', [$t1, $r1]), 1);
    assert_eq((int) QG\Db::val('SELECT COUNT(*) FROM sys_role_permission WHERE tenant_id = ? AND role_id = ?', [$t2, $r2]), 1);

    QG\Db::exec('DELETE FROM sys_role_permission WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Db::exec('DELETE FROM sys_role WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Tenant::set(null);
});

t('LogRepo 订单日志按租户读写', function () {
    $t1 = 9301;
    $t2 = 9302;
    $oid = 999001;
    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    QG\Db::exec('DELETE FROM biz_order_log WHERE tenant_id IN (?,?)', [$t1, $t2]);

    QG\repos\LogRepo::writeOrderLog(['order_id' => $oid, 'action' => '创建', 'remark' => '新建订单']);
    QG\Tenant::set(['id' => $t2, 'mode' => 'shared', 'db_name' => '']);
    QG\repos\LogRepo::writeOrderLog(['order_id' => $oid, 'action' => '编辑']);

    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    $rows = QG\repos\LogRepo::orderLogs($oid);
    assert_eq(count($rows), 1, '只应看到本租户的订单日志');
    assert_eq($rows[0]['action'], '创建');
    assert_true(array_key_exists('operator_name', $rows[0]), '应带 operator_name');

    QG\Db::exec('DELETE FROM biz_order_log WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Tenant::set(null);
});

t('App::log 经 LogRepo 写入本租户 sys_log', function () {
    $t = 9401;
    QG\Tenant::set(['id' => $t, 'mode' => 'shared', 'db_name' => '']);
    QG\Db::exec('DELETE FROM sys_log WHERE tenant_id = ?', [$t]);

    QG\App::log('test', 'repo', 1, 'hello');
    // 目标为空时（系统设置保存那条日志就是这样）必须写 NULL：sys_log.target_id 是整型列，
    // 写成 '' 在严格模式下会直接报 Incorrect integer value
    QG\App::log('test', 'repo-null-target', null, 'no target');

    [$rows, $total] = QG\repos\LogRepo::page(50, 0);
    assert_eq($total, 2, '本租户应恰好写入两条日志');
    assert_eq($rows[1]['module'], 'test');
    assert_eq((int) $rows[1]['tenant_id'], $t);
    assert_eq($rows[0]['target_id'], null, '空目标应落成 NULL');
    assert_eq((int) $rows[1]['target_id'], 1);

    QG\Db::exec('DELETE FROM sys_log WHERE tenant_id = ?', [$t]);
    QG\Tenant::set(null);
});
