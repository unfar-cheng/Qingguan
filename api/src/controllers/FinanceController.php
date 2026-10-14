<?php
namespace QG\controllers;

use QG\App;
use QG\Auth;
use QG\Req;
use QG\Response;
use QG\Router;
use QG\repos\FinanceRepo;

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
        [$rows, $total] = FinanceRepo::accounts($kw, $size, $offset);
        Response::page($rows, $total, $page, $size);
    }

    public static function accountStore(): void
    {
        Auth::requireLogin();
        $companyId = Req::int('company_id');
        if (!$companyId || !FinanceRepo::companyExists($companyId)) {
            Response::fail('请选择有效的公司');
        }
        if (FinanceRepo::accountFindByCompany($companyId)) {
            Response::fail('该公司账户已存在');
        }
        $id = FinanceRepo::accountCreate(['company_id' => $companyId, 'balance' => Req::num('balance', 0), 'frozen' => 0]);
        Response::ok(['id' => $id], '已开户');
    }

    public static function accountAdjust(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        $acc = FinanceRepo::accountFind($id);
        if (!$acc) {
            Response::fail('账户不存在');
        }
        $amount = (float) Req::num('amount', 0);
        if ($amount == 0.0) {
            Response::fail('调整金额不能为 0');
        }
        FinanceRepo::accountUpdateBalance($id, (float) $acc['balance'] + $amount);
        App::log('finance', 'adjust', $id, ['amount' => $amount, 'remark' => Req::str('remark')]);
        Response::ok(null, '余额已调整');
    }

    /* ---------------- 充值记录 ---------------- */

    public static function recharges(): void
    {
        Auth::requireLogin();
        [$page, $size, $offset] = Req::page();
        $companyId = Req::str('company_id') !== '' ? Req::int('company_id') : null;
        $status    = Req::str('status') !== '' ? Req::int('status') : null;
        [$rows, $total] = FinanceRepo::rechargePage($companyId, $status, Req::str('keyword'), $size, $offset);
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
        $id = FinanceRepo::rechargeCreate([
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
        $row = FinanceRepo::rechargeFind($id);
        if (!$row) {
            Response::fail('记录不存在');
        }
        if ((int) $row['status'] === 1) {
            Response::fail('已入账的充值记录不能修改');
        }
        FinanceRepo::rechargeUpdate($id, [
            'amount'   => Req::num('amount', 0),
            'pay_type' => Req::str('pay_type'),
            'voucher'  => Req::str('voucher'),
            'remark'   => Req::str('remark'),
        ]);
        Response::ok(null, '已保存');
    }

    public static function rechargeDelete(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        $row = FinanceRepo::rechargeFind($id);
        if ($row && (int) $row['status'] === 1) {
            Response::fail('已入账的充值记录不能删除');
        }
        FinanceRepo::rechargeDelete($id);
        Response::ok(null, '已删除');
    }

    public static function rechargeAudit(array $p): void
    {
        Auth::requireLogin();
        $id     = (int) $p['id'];
        $status = Req::int('status', 1);
        $row    = FinanceRepo::rechargeFind($id);
        if (!$row) {
            Response::fail('记录不存在');
        }
        if ((int) $row['status'] === 1) {
            Response::fail('该充值已入账，请勿重复审核');
        }
        try {
            FinanceRepo::auditRecharge($id, $status);
        } catch (\Throwable $e) {
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
        $f = [];
        foreach (['company_id', 'order_id', 'status'] as $k) {
            if (Req::str($k) !== '') {
                $f[$k] = Req::int($k);
            }
        }
        $f['due_start'] = Req::str('due_start');
        $f['due_end']   = Req::str('due_end');
        [$rows, $total] = FinanceRepo::payablePage($f, $size, $offset);
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
        $id = FinanceRepo::payableCreate([
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
        FinanceRepo::payableUpdate((int) $p['id'], [
            'order_id'   => Req::int('order_id') ?: null,
            'company_id' => Req::int('company_id') ?: null,
            'fee_name'   => Req::str('fee_name'),
            'amount'     => Req::num('amount', 0),
            'currency'   => Req::str('currency', 'CNY') ?: 'CNY',
            'status'     => Req::int('status', 0),
            'due_date'   => Req::date('due_date'),
            'remark'     => Req::str('remark'),
        ]);
        Response::ok(null, '已保存');
    }

    public static function payableDelete(array $p): void
    {
        Auth::requireLogin();
        FinanceRepo::payableDelete((int) $p['id']);
        Response::ok(null, '已删除');
    }

    public static function payablePay(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        if (!FinanceRepo::payableExists($id)) {
            Response::fail('记录不存在');
        }
        FinanceRepo::payablePay($id, Req::int('status', 1), App::now());
        Response::ok(null, '已更新付款状态');
    }
}
