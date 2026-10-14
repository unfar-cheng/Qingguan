<?php
namespace QG\repos;

use QG\App;
use QG\Db;
use QG\Tenant;

class OrderRepo
{
    private const LIST_SELECT = '
        o.*,
        pl.plan_name, pl.plan_code, pl.intro AS plan_intro,
        imp.company_name AS importer_name,
        (SELECT GROUP_CONCAT(c.container_no ORDER BY c.sort SEPARATOR ", ")
           FROM biz_order_container c WHERE c.tenant_id = o.tenant_id AND c.order_id = o.id) AS container_nos,
        (SELECT COUNT(*) FROM biz_order_file f
           WHERE f.tenant_id = o.tenant_id AND f.order_id = o.id) AS file_count,
        (SELECT ad.company FROM biz_order_address ad
           WHERE ad.tenant_id = o.tenant_id AND ad.order_id = o.id AND ad.addr_type = 1 LIMIT 1) AS shipper_name,
        (SELECT ad.company FROM biz_order_address ad
           WHERE ad.tenant_id = o.tenant_id AND ad.order_id = o.id AND ad.addr_type = 2 LIMIT 1) AS consignee_name,
        (SELECT i.declare_date FROM biz_isf i
           WHERE i.tenant_id = o.tenant_id AND i.order_id = o.id ORDER BY i.id DESC LIMIT 1) AS declare_date,
        u.real_name AS creator_name';

    private const LIST_FROM = '
        FROM biz_order o
        LEFT JOIN biz_plan pl  ON pl.id = o.plan_id
        LEFT JOIN org_company imp ON imp.id = o.importer_id
        LEFT JOIN sys_user u   ON u.id = o.created_by';

    /** 列表分页。$where/$bind 是控制器解析出的筛选条件（不含租户条件），这里补 o.tenant_id */
    public static function page(array $where, array $bind, string $sortBy, string $sortDir, int $size, int $offset): array
    {
        $tid = Tenant::requireId();
        array_unshift($where, 'o.tenant_id = ?');
        array_unshift($bind, $tid);
        $w     = implode(' AND ', $where);
        $total = (int) Db::val('SELECT COUNT(*) ' . self::LIST_FROM . ' WHERE ' . $w, $bind);
        $rows  = Db::all(
            'SELECT ' . self::LIST_SELECT . ' ' . self::LIST_FROM . ' WHERE ' . $w
            . self::orderBy($sortBy, $sortDir) . " LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        return [$rows, $total];
    }

    /** 导出用全量列表（对齐 OrderController::export） */
    public static function exportRows(array $where, array $bind, string $sortBy, string $sortDir, int $limit = 50000): array
    {
        $tid = Tenant::requireId();
        array_unshift($where, 'o.tenant_id = ?');
        array_unshift($bind, $tid);
        return Db::all(
            'SELECT ' . self::LIST_SELECT . ' ' . self::LIST_FROM . ' WHERE ' . implode(' AND ', $where)
            . self::orderBy($sortBy, $sortDir) . " LIMIT {$limit}",
            $bind
        );
    }

    private static function orderBy(string $col, string $dir): string
    {
        $allow = ['o.created_at', 'o.etd', 'o.eta', 'o.id', 'o.mbl_no'];
        $col   = in_array($col, $allow, true) ? $col : 'o.id';
        $d     = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        return " ORDER BY {$col} {$d}, o.id DESC";
    }

    public static function stat(): array
    {
        return [
            'total'      => self::countWhere(''),
            'forecast'   => self::countWhere(' AND customs_status = ?', ['forecast']),
            'declaring'  => self::countWhere(' AND customs_status = ?', ['declaring']),
            'inspecting' => self::countWhere(' AND customs_status = ?', ['inspecting']),
            'released'   => self::countWhere(' AND customs_status = ?', ['released']),
            'unverified' => self::countWhere(' AND is_verified = 0'),
            'isf'        => self::countWhere(' AND isf_flag = 1'),
            'pickup'     => self::countWhere(' AND pickup_flag = 1'),
            // 发货人 / 进口商计数复用 CompanyRepo 的租户内计数
            'shipper'    => CompanyRepo::page(1, '', '', 1, 0)[1],
            'importer'   => CompanyRepo::page(2, '', '', 1, 0)[1],
        ];
    }

    private static function countWhere(string $extra, array $bind = []): int
    {
        return (int) Db::val(
            'SELECT COUNT(*) FROM biz_order WHERE tenant_id = ? AND is_deleted = 0' . $extra,
            array_merge([Tenant::requireId()], $bind)
        );
    }

    /** 详情主表行（带列表用的关联字段） */
    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT ' . self::LIST_SELECT . ' ' . self::LIST_FROM . ' WHERE o.tenant_id = ? AND o.id = ? AND o.is_deleted = 0',
            [Tenant::requireId(), $id]
        );
    }

    /** 主表原始行（编辑 / 状态变更用） */
    public static function row(int $id): ?array
    {
        return Db::one('SELECT * FROM biz_order WHERE tenant_id = ? AND id = ? AND is_deleted = 0',
            [Tenant::requireId(), $id]);
    }

    public static function exists(int $id): bool
    {
        return (bool) Db::val('SELECT id FROM biz_order WHERE tenant_id = ? AND id = ? AND is_deleted = 0',
            [Tenant::requireId(), $id]);
    }

    /** 详情页 6 张子表（订单日志走 LogRepo::orderLogs） */
    public static function children(int $orderId): array
    {
        $tid = Tenant::requireId();
        return [
            'containers' => Db::all('SELECT * FROM biz_order_container WHERE tenant_id = ? AND order_id = ? ORDER BY sort, id', [$tid, $orderId]),
            'hscodes'    => Db::all('SELECT * FROM biz_order_hscode WHERE tenant_id = ? AND order_id = ? ORDER BY sort, id', [$tid, $orderId]),
            'addresses'  => Db::all('SELECT * FROM biz_order_address WHERE tenant_id = ? AND order_id = ? ORDER BY addr_type', [$tid, $orderId]),
            'files'      => Db::all('SELECT * FROM biz_order_file WHERE tenant_id = ? AND order_id = ? ORDER BY id DESC', [$tid, $orderId]),
            'isfs'       => Db::all('SELECT * FROM biz_isf WHERE tenant_id = ? AND order_id = ? ORDER BY id DESC', [$tid, $orderId]),
            'inspects'   => Db::all('SELECT * FROM biz_inspect WHERE tenant_id = ? AND order_id = ? ORDER BY id DESC', [$tid, $orderId]),
        ];
    }

    /** 重写订单的三张子表（柜子 / HS CODE / 抬头） */
    public static function saveChildren(int $orderId, array $containers, array $hscodes, array $addresses, bool $replace = true): void
    {
        $tid = Tenant::requireId();
        if ($replace) {
            foreach (['biz_order_container', 'biz_order_hscode', 'biz_order_address'] as $t) {
                Db::exec("DELETE FROM {$t} WHERE tenant_id = ? AND order_id = ?", [$tid, $orderId]);
            }
        }

        foreach ($containers as $i => $c) {
            if (!is_array($c)) {
                continue;
            }
            $row = [
                'tenant_id'      => $tid,
                'order_id'       => $orderId,
                'container_no'   => trim((string) ($c['container_no'] ?? '')),
                'container_type' => trim((string) ($c['container_type'] ?? '')),
                'gross_weight'   => ($c['gross_weight'] ?? '') === '' ? null : $c['gross_weight'],
                'packages'       => ($c['packages'] ?? '') === '' ? null : (int) $c['packages'],
                'cbm'            => ($c['cbm'] ?? '') === '' ? null : $c['cbm'],
                'sort'           => (int) $i,
            ];
            if ($row['container_no'] === '' && $row['container_type'] === '' && $row['gross_weight'] === null) {
                continue;
            }
            Db::insert('biz_order_container', $row);
        }

        foreach ($hscodes as $i => $h) {
            if (!is_array($h) || trim((string) ($h['hs_code'] ?? '')) === '') {
                continue;
            }
            Db::insert('biz_order_hscode', [
                'tenant_id'      => $tid,
                'order_id'       => $orderId,
                'hs_code'        => trim((string) $h['hs_code']),
                'origin_country' => trim((string) ($h['origin_country'] ?? '')),
                'sort'           => (int) $i,
            ]);
        }

        foreach ($addresses as $a) {
            if (!is_array($a)) {
                continue;
            }
            $type = (int) ($a['addr_type'] ?? 0);
            if ($type < 1 || $type > 3) {
                continue;
            }
            $row = [
                'tenant_id'      => $tid,
                'order_id'       => $orderId,
                'addr_type'      => $type,
                'source_id'      => (int) ($a['source_id'] ?? 0) ?: null,
                'company'        => trim((string) ($a['company'] ?? '')),
                'country'        => trim((string) ($a['country'] ?? '')),
                'state_province' => trim((string) ($a['state_province'] ?? '')),
                'city'           => trim((string) ($a['city'] ?? '')),
                'zip_code'       => trim((string) ($a['zip_code'] ?? '')),
                'address'        => trim((string) ($a['address'] ?? '')),
                'phone'          => trim((string) ($a['phone'] ?? '')),
                'remark'         => trim((string) ($a['remark'] ?? '')),
            ];
            Db::exec('DELETE FROM biz_order_address WHERE tenant_id = ? AND order_id = ? AND addr_type = ?', [$tid, $orderId, $type]);
            Db::insert('biz_order_address', $row);
        }
    }

    public static function create(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('biz_order', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Db::update('biz_order', $data, 'tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    public static function softDelete(int $id): int
    {
        return Db::exec('UPDATE biz_order SET is_deleted = 1, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [App::now(), Tenant::requireId(), $id]);
    }

    /** MBL 查重；$excludeId > 0 时排除自身 */
    public static function mblExists(string $mbl, int $excludeId = 0): bool
    {
        $sql  = 'SELECT id FROM biz_order WHERE tenant_id = ? AND mbl_no = ? AND is_deleted = 0';
        $bind = [Tenant::requireId(), $mbl];
        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $bind[] = $excludeId;
        }
        return (bool) Db::val($sql, $bind);
    }

    public static function nextOrderNo(): string
    {
        $prefix = 'QG' . date('Ymd');
        $last   = Db::val('SELECT order_no FROM biz_order WHERE tenant_id = ? AND order_no LIKE ? ORDER BY id DESC LIMIT 1',
            [Tenant::requireId(), $prefix . '%']);
        $seq = $last ? ((int) substr($last, -4) + 1) : 1;
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public static function addIsf(int $orderId, int $status, string $matchMsg = ''): int
    {
        return Db::insert('biz_isf', [
            'tenant_id'    => Tenant::requireId(),
            'order_id'     => $orderId,
            'declare_date' => App::now(),
            'status'       => $status,
            'match_msg'    => $matchMsg,
        ]);
    }

    /** $row 取 inspect_type / inspect_date / abnormal / result / fee / remark / created_by */
    public static function addInspect(int $orderId, array $row): int
    {
        $row['tenant_id'] = Tenant::requireId();
        $row['order_id']  = $orderId;
        return Db::insert('biz_inspect', $row);
    }

    public static function delInspect(int $id, int $orderId): int
    {
        return Db::exec('DELETE FROM biz_inspect WHERE tenant_id = ? AND id = ? AND order_id = ?',
            [Tenant::requireId(), $id, $orderId]);
    }
}
