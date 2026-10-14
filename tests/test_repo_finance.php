<?php
require_once __DIR__ . '/bootstrap.php';

// fin_account 无 status 列（见 db/schema.sql），列名以实际 schema 为准
t('充值审核的事务只在同租户内生效', function () {
    $t1 = 9101;
    $t2 = 9102;
    QG\Tenant::set(['id' => 1, 'mode' => 'shared', 'db_name' => '']);
    QG\Db::exec('DELETE FROM fin_recharge WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Db::exec('DELETE FROM fin_account WHERE tenant_id IN (?,?)', [$t1, $t2]);

    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    $acc1 = QG\Db::insert('fin_account', ['tenant_id' => $t1, 'company_id' => 9001, 'balance' => 100, 'frozen' => 0]);
    $rec1 = QG\Db::insert('fin_recharge', ['tenant_id' => $t1, 'company_id' => 9001, 'amount' => 50, 'status' => 0]);

    QG\Tenant::set(['id' => $t2, 'mode' => 'shared', 'db_name' => '']);
    $acc2 = QG\Db::insert('fin_account', ['tenant_id' => $t2, 'company_id' => 9002, 'balance' => 100, 'frozen' => 0]);

    // 租户2 拿租户1 的充值单 id 审核，应被拒
    $threw = false;
    try {
        QG\repos\FinanceRepo::approveRecharge((int) $rec1);
    } catch (Throwable $e) {
        $threw = true;
    }
    assert_true($threw, '跨租户审核应被拒');

    // 租户2 余额不受影响
    assert_eq((int) QG\Db::val('SELECT balance FROM fin_account WHERE tenant_id = ? AND id = ?', [$t2, $acc2]), 100);
    // 租户1 的充值单也没被改动
    assert_eq((int) QG\Db::val('SELECT status FROM fin_recharge WHERE tenant_id = ? AND id = ?', [$t1, $rec1]), 0);

    QG\Tenant::set(['id' => 1, 'mode' => 'shared', 'db_name' => '']);
    QG\Db::exec('DELETE FROM fin_recharge WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Db::exec('DELETE FROM fin_account WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Tenant::set(null);
});

t('同租户充值审核入账，余额只加本租户账户', function () {
    $t1 = 9101;
    QG\Tenant::set(['id' => 1, 'mode' => 'shared', 'db_name' => '']);
    QG\Db::exec('DELETE FROM fin_recharge WHERE tenant_id = ?', [$t1]);
    QG\Db::exec('DELETE FROM fin_account WHERE tenant_id = ?', [$t1]);

    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    $acc = QG\Db::insert('fin_account', ['tenant_id' => $t1, 'company_id' => 9001, 'balance' => 100, 'frozen' => 0]);
    $rec = QG\Db::insert('fin_recharge', ['tenant_id' => $t1, 'company_id' => 9001, 'amount' => 50, 'status' => 0]);

    QG\repos\FinanceRepo::approveRecharge((int) $rec);
    assert_eq((int) QG\Db::val('SELECT status FROM fin_recharge WHERE tenant_id = ? AND id = ?', [$t1, $rec]), 1, '审核后应为已入账');
    assert_eq((int) QG\Db::val('SELECT balance FROM fin_account WHERE tenant_id = ? AND id = ?', [$t1, $acc]), 150, '余额应加 50');

    $threw = false;
    try {
        QG\repos\FinanceRepo::approveRecharge((int) $rec);
    } catch (Throwable $e) {
        $threw = true;
    }
    assert_true($threw, '重复审核应被拒');

    QG\Db::exec('DELETE FROM fin_recharge WHERE tenant_id = ?', [$t1]);
    QG\Db::exec('DELETE FROM fin_account WHERE tenant_id = ?', [$t1]);
    QG\Tenant::set(null);
});
