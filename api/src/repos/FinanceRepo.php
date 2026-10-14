<?php
namespace QG\repos;

use QG\Db;
use QG\Tenant;

class FinanceRepo
{
    /* ---------------- 账户余额 ---------------- */

    /** 账户列表（公司为主表，左连余额账户；对齐 FinanceController::accounts） */
    public static function accounts(string $keyword, int $size, int $offset): array
    {
        $tid  = Tenant::requireId();
        $bind = [$tid];
        $kw   = '';
        if ($keyword !== '') {
            $kw     = ' AND c.company_name LIKE ?';
            $bind[] = "%{$keyword}%";
        }
        $total = (int) Db::val(
            "SELECT COUNT(*) FROM org_company c
              WHERE c.tenant_id = ? AND c.is_deleted = 0 AND c.company_type = 2{$kw}",
            $bind
        );
        $rows = Db::all(
            "SELECT c.id AS company_id, c.company_name, c.country, c.city,
                    IFNULL(a.id, 0) AS account_id, IFNULL(a.balance, 0) AS balance, IFNULL(a.frozen, 0) AS frozen,
                    a.updated_at,
                    (SELECT IFNULL(SUM(r.amount),0) FROM fin_recharge r
                      WHERE r.tenant_id = c.tenant_id AND r.company_id = c.id AND r.status = 1) AS total_recharge,
                    (SELECT IFNULL(SUM(p.amount),0) FROM fin_payable p
                      WHERE p.tenant_id = c.tenant_id AND p.company_id = c.id) AS total_payable
               FROM org_company c
               LEFT JOIN fin_account a ON a.tenant_id = c.tenant_id AND a.company_id = c.id
              WHERE c.tenant_id = ? AND c.is_deleted = 0 AND c.company_type = 2{$kw}
              ORDER BY c.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        return [$rows, $total];
    }

    /** 开户前校验公司有效（对应 FinanceController::accountStore 第一处校验） */
    public static function companyExists(int $companyId): bool
    {
        return (bool) Db::val('SELECT id FROM org_company WHERE tenant_id = ? AND id = ? AND is_deleted = 0',
            [Tenant::requireId(), $companyId]);
    }

    public static function accountFind(int $id): ?array
    {
        return Db::one('SELECT * FROM fin_account WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    public static function accountFindByCompany(int $companyId): ?array
    {
        return Db::one('SELECT * FROM fin_account WHERE tenant_id = ? AND company_id = ?',
            [Tenant::requireId(), $companyId]);
    }

    public static function accountCreate(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('fin_account', $data);
    }

    public static function accountUpdateBalance(int $id, float $balance): int
    {
        return Db::update('fin_account', ['balance' => $balance], 'tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    /* ---------------- 充值记录 ---------------- */

    /** 充值列表，$companyId / $status 为 null 表示不过滤 */
    public static function rechargePage(?int $companyId, ?int $status, string $keyword, int $size, int $offset): array
    {
        $tid   = Tenant::requireId();
        $bind  = [$tid];
        $extra = '';
        if ($companyId !== null) {
            $extra .= ' AND r.company_id = ?';
            $bind[] = $companyId;
        }
        if ($status !== null) {
            $extra .= ' AND r.status = ?';
            $bind[] = $status;
        }
        if ($keyword !== '') {
            $extra .= ' AND c.company_name LIKE ?';
            $bind[] = "%{$keyword}%";
        }
        $total = (int) Db::val(
            "SELECT COUNT(*) FROM fin_recharge r
               LEFT JOIN org_company c ON c.tenant_id = r.tenant_id AND c.id = r.company_id
              WHERE r.tenant_id = ?{$extra}",
            $bind
        );
        $rows = Db::all(
            "SELECT r.*, c.company_name, u.real_name AS operator_name
               FROM fin_recharge r
               LEFT JOIN org_company c ON c.tenant_id = r.tenant_id AND c.id = r.company_id
               LEFT JOIN sys_user u ON u.tenant_id = r.tenant_id AND u.id = r.operator_id
              WHERE r.tenant_id = ?{$extra} ORDER BY r.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        return [$rows, $total];
    }

    public static function rechargeFind(int $id): ?array
    {
        return Db::one('SELECT * FROM fin_recharge WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    public static function rechargeCreate(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('fin_recharge', $data);
    }

    public static function rechargeUpdate(int $id, array $data): int
    {
        return Db::update('fin_recharge', $data, 'tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    public static function rechargeDelete(int $id): int
    {
        return Db::exec('DELETE FROM fin_recharge WHERE tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    /**
     * 充值审核：置状态；入账（$status=1）时把金额加到同租户公司账户上，没有账户就新开。
     * 事务内每条 SQL 都带 tenant_id，跨租户传 id 会因查不到记录而抛异常。
     */
    public static function auditRecharge(int $id, int $status): void
    {
        $tid = Tenant::requireId();
        Db::begin();
        try {
            $row = Db::one('SELECT * FROM fin_recharge WHERE tenant_id = ? AND id = ?', [$tid, $id]);
            if (!$row) {
                throw new \RuntimeException('充值单不存在或已处理');
            }
            if ((int) $row['status'] === 1) {
                throw new \RuntimeException('该充值已入账，请勿重复审核');
            }
            Db::update('fin_recharge', ['status' => $status], 'tenant_id = ? AND id = ?', [$tid, $id]);
            if ($status === 1) {
                $companyId = (int) $row['company_id'];
                $acc       = Db::one('SELECT * FROM fin_account WHERE tenant_id = ? AND company_id = ?',
                    [$tid, $companyId]);
                if ($acc) {
                    Db::update('fin_account', ['balance' => (float) $acc['balance'] + (float) $row['amount']],
                        'tenant_id = ? AND id = ?', [$tid, (int) $acc['id']]);
                } else {
                    Db::insert('fin_account', ['company_id' => $companyId, 'balance' => (float) $row['amount'],
                                               'frozen' => 0, 'tenant_id' => $tid]);
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** 审核通过（入账） */
    public static function approveRecharge(int $id): void
    {
        self::auditRecharge($id, 1);
    }

    /* ---------------- 应付账单 ---------------- */

    /** 应付列表，$f 支持 company_id / order_id / status / due_start / due_end */
    public static function payablePage(array $f, int $size, int $offset): array
    {
        $tid   = Tenant::requireId();
        $bind  = [$tid];
        $extra = '';
        foreach (['company_id' => 'p.company_id', 'order_id' => 'p.order_id', 'status' => 'p.status'] as $k => $col) {
            if (!isset($f[$k]) || $f[$k] === '' || $f[$k] === null) {
                continue;
            }
            $extra .= " AND {$col} = ?";
            $bind[] = (int) $f[$k];
        }
        if (!empty($f['due_start'])) {
            $extra .= ' AND p.due_date >= ?';
            $bind[] = $f['due_start'];
        }
        if (!empty($f['due_end'])) {
            $extra .= ' AND p.due_date <= ?';
            $bind[] = $f['due_end'];
        }
        $total = (int) Db::val("SELECT COUNT(*) FROM fin_payable p WHERE p.tenant_id = ?{$extra}", $bind);
        $rows  = Db::all(
            "SELECT p.*, c.company_name, o.mbl_no, o.order_no
               FROM fin_payable p
               LEFT JOIN org_company c ON c.tenant_id = p.tenant_id AND c.id = p.company_id
               LEFT JOIN biz_order o ON o.tenant_id = p.tenant_id AND o.id = p.order_id
              WHERE p.tenant_id = ?{$extra} ORDER BY p.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        return [$rows, $total];
    }

    public static function payableExists(int $id): bool
    {
        return (bool) Db::val('SELECT id FROM fin_payable WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    public static function payableCreate(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('fin_payable', $data);
    }

    public static function payableUpdate(int $id, array $data): int
    {
        return Db::update('fin_payable', $data, 'tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    public static function payableDelete(int $id): int
    {
        return Db::exec('DELETE FROM fin_payable WHERE tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    public static function payablePay(int $id, int $status, string $paidAt): int
    {
        return Db::update('fin_payable', ['status' => $status, 'paid_at' => $paidAt], 'tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }
}
