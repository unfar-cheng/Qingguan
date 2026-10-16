<?php
namespace QG\repos;

use QG\ControlDb;

/** 账单仓储（控制面 saas_bill）。租户侧入口一律传 Tenant::requireId() 的 id 并绑定 tenant_id。 */
class BillRepo
{
    public static function forTenant(int $tid, int $limit = 100): array
    {
        return ControlDb::all(
            'SELECT id, cycle, amount, status, created_at, paid_at, void_reason, remark
               FROM saas_bill WHERE tenant_id = ? ORDER BY id DESC LIMIT ' . (int) $limit,
            [$tid]
        );
    }

    public static function hasPaid(int $tid): bool
    {
        return (bool) ControlDb::val('SELECT COUNT(*) FROM saas_bill WHERE tenant_id = ? AND status = ?', [$tid, 'paid']);
    }

    public static function create(int $tid, string $cycle, float $amount, string $remark = ''): int
    {
        return ControlDb::insert('saas_bill', [
            'tenant_id' => $tid, 'cycle' => $cycle, 'amount' => $amount,
            'status' => 'unpaid', 'remark' => $remark,
        ]);
    }

    /** 平台侧专用（按 id 查，无租户过滤）；租户侧一律走 forTenant()，勿用本方法。 */
    public static function findUnpaid(int $billId): ?array
    {
        $r = ControlDb::one('SELECT * FROM saas_bill WHERE id = ? AND status = ? LIMIT 1', [$billId, 'unpaid']);
        return $r ?: null;
    }
}
