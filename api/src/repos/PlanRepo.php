<?php
namespace QG\repos;

use QG\Db;
use QG\Tenant;

class PlanRepo
{
    /** 列表（对齐 PlanController::index） */
    public static function page(string $keyword, string $status): array
    {
        $tid  = Tenant::requireId();
        $sql  = 'SELECT * FROM biz_plan WHERE tenant_id = ?';
        $bind = [$tid];
        if ($keyword !== '') {
            $sql .= ' AND (plan_name LIKE ? OR plan_code LIKE ?)';
            $bind[] = "%{$keyword}%";
            $bind[] = "%{$keyword}%";
        }
        if ($status !== '') {
            $sql .= ' AND status = ?';
            $bind[] = (int) $status;
        }
        return Db::all($sql . ' ORDER BY sort, id', $bind);
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM biz_plan WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    public static function exists(int $id): bool
    {
        return (bool) Db::val('SELECT id FROM biz_plan WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    /** 方案编码查重；$excludeId > 0 时排除自身（更新场景） */
    public static function codeExists(string $code, int $excludeId = 0): bool
    {
        $sql  = 'SELECT id FROM biz_plan WHERE tenant_id = ? AND plan_code = ?';
        $bind = [Tenant::requireId(), $code];
        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $bind[] = $excludeId;
        }
        return (bool) Db::val($sql, $bind);
    }

    public static function create(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('biz_plan', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Db::update('biz_plan', $data, 'tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    /** 对齐原 destroy 逻辑：该表无软删列，直接物理删除 */
    public static function softDelete(int $id): int
    {
        return Db::exec('DELETE FROM biz_plan WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    /** 引用该方案的订单数 */
    public static function usedByOrders(int $planId): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM biz_order WHERE tenant_id = ? AND plan_id = ? AND is_deleted = 0',
            [Tenant::requireId(), $planId]);
    }
}
