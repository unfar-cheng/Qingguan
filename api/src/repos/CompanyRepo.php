<?php
namespace QG\repos;

use QG\Db;
use QG\Tenant;

class CompanyRepo
{
    /** 列表（对齐 OrgController::index 第 45-49 行，补 tenant_id） */
    public static function page(int $type, string $keyword, string $status, int $size, int $offset): array
    {
        $tid   = Tenant::requireId();
        $where = ['is_deleted = 0', 'company_type = ?'];
        $bind  = [$tid, $type];
        if ($keyword !== '') {
            $where[] = '(company_name LIKE ? OR city LIKE ? OR address LIKE ?)';
            array_push($bind, "%{$keyword}%", "%{$keyword}%", "%{$keyword}%");
        }
        if ($status !== '') { $where[] = 'status = ?'; $bind[] = (int) $status; }
        $w     = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM org_company WHERE tenant_id = ? AND {$w}", $bind);
        $rows  = Db::all("SELECT * FROM org_company WHERE tenant_id = ? AND {$w} ORDER BY id DESC LIMIT {$size} OFFSET {$offset}", $bind);
        return [$rows, $total];
    }

    /** 下拉（对齐 OrgController::options 第 65-72 行） */
    public static function options(int $type, string $kw): array
    {
        $tid  = Tenant::requireId();
        $bind = [$tid, $type];
        $sql  = 'SELECT id, company_name, country, state_province, city, zip_code, address, phone, contact, bond_no
                   FROM org_company WHERE tenant_id = ? AND is_deleted = 0 AND status = 1 AND company_type = ?';
        if ($kw !== '') { $sql .= ' AND company_name LIKE ?'; $bind[] = "%{$kw}%"; }
        $sql .= ' ORDER BY company_name LIMIT 200';
        return Db::all($sql, $bind);
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM org_company WHERE tenant_id = ? AND id = ? AND is_deleted = 0',
            [Tenant::requireId(), $id]);
    }

    public static function exists(int $id): bool
    {
        return (bool) Db::val('SELECT id FROM org_company WHERE tenant_id = ? AND id = ? AND is_deleted = 0',
            [Tenant::requireId(), $id]);
    }

    public static function create(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('org_company', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Db::update('org_company', $data, 'tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    public static function softDelete(int $id): int
    {
        return Db::exec('UPDATE org_company SET is_deleted = 1 WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    /** 被订单引用的数量（对齐 OrgController::destroy 的占用检查） */
    public static function orderCount(int $companyId): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM biz_order WHERE tenant_id = ? AND is_deleted = 0 AND importer_id = ?',
            [Tenant::requireId(), $companyId]);
    }

    /** 进口商统计口径（对齐 OrgController::statOf 的聚合查询，补 tenant_id） */
    public static function orderStats(int $companyId): array
    {
        $tid = Tenant::requireId();
        $row = Db::one(
            "SELECT
               SUM(customs_status = 'released')   AS released,
               SUM(customs_status = 'forecast')   AS forecast,
               SUM(customs_status = 'inspecting') AS inspecting
             FROM biz_order WHERE tenant_id = ? AND is_deleted = 0 AND importer_id = ?",
            [$tid, $companyId]
        );
        $inspected = (int) Db::val(
            'SELECT COUNT(DISTINCT i.order_id) FROM biz_inspect i
               JOIN biz_order o ON o.id = i.order_id AND o.tenant_id = i.tenant_id
              WHERE i.tenant_id = ? AND o.is_deleted = 0 AND o.importer_id = ?',
            [$tid, $companyId]
        );
        return ['released' => (int) ($row['released'] ?? 0), 'forecast' => (int) ($row['forecast'] ?? 0),
                'inspecting' => (int) ($row['inspecting'] ?? 0), 'inspected' => $inspected];
    }
}
