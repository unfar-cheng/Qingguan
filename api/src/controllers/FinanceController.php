<?php
namespace QG\controllers;

use QG\App;
use QG\Auth;
use QG\Db;
use QG\Req;
use QG\Response;
use QG\Router;

class FinanceController
{
    public static function routes(Router $r): void
    {
        $r->get('/finance/accounts', [self::class, 'accounts']);
        $r->post('/finance/accounts', [self::class, 'accountStore']);
        $r->post('/finance/accounts/{id}/adjust', [self::class, 'accountAdjust']);

        $r->get('/finance/recharges', [self::class, 'recharges']);
        $r->post('/finance/recharges', [self::class, 'rechargeStore']);
        $r->put('/finance/recharges/{id}', [self::class, 'rechargeUpdate']);
        $r->del('/finance/recharges/{id}', [self::class, 'rechargeDelete']);
        $r->post('/finance/recharges/{id}/audit', [self::class, 'rechargeAudit']);

        $r->get('/finance/payables', [self::class, 'payables']);
        $r->post('/finance/payables', [self::class, 'payableStore']);
        $r->put('/finance/payables/{id}', [self::class, 'payableUpdate']);
        $r->del('/finance/payables/{id}', [self::class, 'payableDelete']);
        $r->post('/finance/payables/{id}/pay', [self::class, 'payablePay']);
    }

    /* ---------------- 账户余额 ---------------- */

    public static function accounts(): void
    {
        Auth::requireLogin();
        $kw = Req::str('keyword');
        [$page, $size, $offset] = Req::page();
        $where = ['c.is_deleted = 0', 'c.company_type = 2'];
        $bind  = [];
        if ($kw !== '') {
            $where[] = 'c.company_name LIKE ?';
            $bind[]  = "%{$kw}%";
        }
        $w = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM org_company c WHERE {$w}", $bind);
        $rows  = Db::all(
            "SELECT c.id AS company_id, c.company_name, c.country, c.city,
                    IFNULL(a.id, 0) AS account_id, IFNULL(a.balance, 0) AS balance, IFNULL(a.frozen, 0) AS frozen,
                    a.updated_at,
                    (SELECT IFNULL(SUM(r.amount),0) FROM fin_recharge r WHERE r.company_id = c.id AND r.status = 1) AS total_recharge,
                    (SELECT IFNULL(SUM(p.amount),0) FROM fin_payable p WHERE p.company_id = c.id) AS total_payable
               FROM org_company c LEFT JOIN fin_account a ON a.company_id = c.id
              WHERE {$w} ORDER BY c.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        Response::page($rows, $total, $page, $size);
    }

    public static function accountStore(): void
    {
        Auth::requireLogin();
        $companyId = Req::int('company_id');
        if (!$companyId || !Db::val('SELECT id FROM org_company WHERE id = ? AND is_deleted = 0', [$companyId])) {
            Response::fail('请选择有效的公司');
        }
        if (Db::val('SELECT id FROM fin_account WHERE company_id = ?', [$companyId])) {
            Response::fail('该公司账户已存在');
        }
        $id = Db::insert('fin_account', ['company_id' => $companyId, 'balance' => Req::num('balance', 0), 'frozen' => 0]);
        Response::ok(['id' => $id], '已开户');
    }

    public static function accountAdjust(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        $acc = Db::one('SELECT * FROM fin_account WHERE id = ?', [$id]);
        if (!$acc) {
            Response::fail('账户不存在');
        }
        $amount = (float) Req::num('amount', 0);
        if ($amount == 0.0) {
            Response::fail('调整金额不能为 0');
        }
        Db::update('fin_account', ['balance' => (float) $acc['balance'] + $amount], 'id = ?', [$id]);
        App::log('finance', 'adjust', $id, ['amount' => $amount, 'remark' => Req::str('remark')]);
        Response::ok(null, '余额已调整');
    }

    /* ---------------- 充值记录 ---------------- */

    public static function recharges(): void
    {
        Auth::requireLogin();
        [$page, $size, $offset] = Req::page();
        $where = ['1=1'];
        $bind  = [];
        if (Req::str('company_id') !== '') {
            $where[] = 'r.company_id = ?';
            $bind[]  = Req::int('company_id');
        }
        if (Req::str('status') !== '') {
            $where[] = 'r.status = ?';
            $bind[]  = Req::int('status');
        }
        $kw = Req::str('keyword');
        if ($kw !== '') {
            $where[] = 'c.company_name LIKE ?';
            $bind[]  = "%{$kw}%";
        }
        $w = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM fin_recharge r LEFT JOIN org_company c ON c.id = r.company_id WHERE {$w}", $bind);
        $rows  = Db::all(
            "SELECT r.*, c.company_name, u.real_name AS operator_name
               FROM fin_recharge r
               LEFT JOIN org_company c ON c.id = r.company_id
               LEFT JOIN sys_user u ON u.id = r.operator_id
              WHERE {$w} ORDER BY r.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        foreach ($rows as &$row) {
            $row['status_text'] = [0 => '待确认', 1 => '已入账', 2 => '已驳回'][(int) $row['status']] ?? '—';
        }
        unset($row);
        Response::page($rows, $total, $page, $size);
    }

    public static function rechargeStore(): void
    {
        Auth::requireLogin();
        $companyId = Req::int('company_id');
        $amount    = Req::num('amount');
        if (!$companyId || $amount === null || (float) $amount <= 0) {
            Response::fail('请选择公司并填写正确的充值金额');
        }
        $id = Db::insert('fin_recharge', [
            'company_id'  => $companyId,
            'amount'      => $amount,
            'pay_type'    => Req::str('pay_type'),
            'voucher'     => Req::str('voucher'),
            'status'      => 0,
            'remark'      => Req::str('remark'),
            'operator_id' => Auth::id(),
            'created_at'  => App::now(),
        ]);
        App::log('finance', 'recharge.create', $id);
        Response::ok(['id' => $id], '已登记，待审核');
    }

    public static function rechargeUpdate(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        $row = Db::one('SELECT * FROM fin_recharge WHERE id = ?', [$id]);
        if (!$row) {
            Response::fail('记录不存在');
        }
        if ((int) $row['status'] === 1) {
            Response::fail('已入账的充值记录不能修改');
        }
        Db::update('fin_recharge', [
            'amount'   => Req::num('amount', 0),
            'pay_type' => Req::str('pay_type'),
            'voucher'  => Req::str('voucher'),
            'remark'   => Req::str('remark'),
        ], 'id = ?', [$id]);
        Response::ok(null, '已保存');
    }

    public static function rechargeDelete(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        $row = Db::one('SELECT status FROM fin_recharge WHERE id = ?', [$id]);
        if ($row && (int) $row['status'] === 1) {
            Response::fail('已入账的充值记录不能删除');
        }
        Db::exec('DELETE FROM fin_recharge WHERE id = ?', [$id]);
        Response::ok(null, '已删除');
    }

    public static function rechargeAudit(array $p): void
    {
        Auth::requireLogin();
        $id     = (int) $p['id'];
        $status = Req::int('status', 1);
        $row    = Db::one('SELECT * FROM fin_recharge WHERE id = ?', [$id]);
        if (!$row) {
            Response::fail('记录不存在');
        }
        if ((int) $row['status'] === 1) {
            Response::fail('该充值已入账，请勿重复审核');
        }
        Db::begin();
        try {
            Db::update('fin_recharge', ['status' => $status], 'id = ?', [$id]);
            if ($status === 1) {
                $acc = Db::one('SELECT * FROM fin_account WHERE company_id = ?', [(int) $row['company_id']]);
                if ($acc) {
                    Db::update('fin_account', ['balance' => (float) $acc['balance'] + (float) $row['amount']], 'id = ?', [(int) $acc['id']]);
                } else {
                    Db::insert('fin_account', ['company_id' => (int) $row['company_id'], 'balance' => (float) $row['amount'], 'frozen' => 0]);
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Response::fail('审核失败：' . $e->getMessage());
        }
        App::log('finance', 'recharge.audit', $id, $status);
        Response::ok(null, $status === 1 ? '已入账' : '已驳回');
    }

    /* ---------------- 应付账单 ---------------- */

    public static function payables(): void
    {
        Auth::requireLogin();
        [$page, $size, $offset] = Req::page();
        $where = ['1=1'];
        $bind  = [];
        foreach (['company_id' => 'p.company_id', 'order_id' => 'p.order_id', 'status' => 'p.status'] as $k => $col) {
            if (Req::str($k) !== '') {
                $where[] = "{$col} = ?";
                $bind[]  = Req::int($k);
            }
        }
        $from = Req::str('due_start');
        if ($from !== '') {
            $where[] = 'p.due_date >= ?';
            $bind[]  = $from;
        }
        $to = Req::str('due_end');
        if ($to !== '') {
            $where[] = 'p.due_date <= ?';
            $bind[]  = $to;
        }
        $w = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM fin_payable p WHERE {$w}", $bind);
        $rows  = Db::all(
            "SELECT p.*, c.company_name, o.mbl_no, o.order_no
               FROM fin_payable p
               LEFT JOIN org_company c ON c.id = p.company_id
               LEFT JOIN biz_order o ON o.id = p.order_id
              WHERE {$w} ORDER BY p.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        foreach ($rows as &$row) {
            $row['status_text'] = [0 => '未付', 1 => '已付', 2 => '部分付'][(int) $row['status']] ?? '—';
        }
        unset($row);
        Response::page($rows, $total, $page, $size);
    }

    public static function payableStore(): void
    {
        Auth::requireLogin();
        $amount = Req::num('amount');
        if ($amount === null) {
            Response::fail('请填写金额');
        }
        $id = Db::insert('fin_payable', [
            'order_id'   => Req::int('order_id') ?: null,
            'company_id' => Req::int('company_id') ?: null,
            'fee_name'   => Req::str('fee_name'),
            'amount'     => $amount,
            'currency'   => Req::str('currency', 'CNY') ?: 'CNY',
            'status'     => Req::int('status', 0),
            'due_date'   => Req::date('due_date'),
            'remark'     => Req::str('remark'),
            'created_at' => App::now(),
        ]);
        App::log('finance', 'payable.create', $id);
        Response::ok(['id' => $id], '已新增');
    }

    public static function payableUpdate(array $p): void
    {
        Auth::requireLogin();
        Db::update('fin_payable', [
            'order_id'   => Req::int('order_id') ?: null,
            'company_id' => Req::int('company_id') ?: null,
            'fee_name'   => Req::str('fee_name'),
            'amount'     => Req::num('amount', 0),
            'currency'   => Req::str('currency', 'CNY') ?: 'CNY',
            'status'     => Req::int('status', 0),
            'due_date'   => Req::date('due_date'),
            'remark'     => Req::str('remark'),
        ], 'id = ?', [(int) $p['id']]);
        Response::ok(null, '已保存');
    }

    public static function payableDelete(array $p): void
    {
        Auth::requireLogin();
        Db::exec('DELETE FROM fin_payable WHERE id = ?', [(int) $p['id']]);
        Response::ok(null, '已删除');
    }

    public static function payablePay(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        if (!Db::val('SELECT id FROM fin_payable WHERE id = ?', [$id])) {
            Response::fail('记录不存在');
        }
        Db::update('fin_payable', ['status' => Req::int('status', 1), 'paid_at' => App::now()], 'id = ?', [$id]);
        Response::ok(null, '已更新付款状态');
    }
}
