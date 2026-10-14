<?php
namespace QG\controllers;

use QG\App;
use QG\Db;
use QG\Req;
use QG\Response;
use QG\Router;

class OrgController
{
    /** 1 发货人 2 进口商(收货人) 3 通知方 */
    private const TYPES = [1 => '发货人', 2 => '进口商', 3 => '通知方'];

    public static function routes(Router $r): void
    {
        $r->get('/companies', [self::class, 'index']);
        $r->get('/companies/options', [self::class, 'options']);
        $r->get('/companies/{id}', [self::class, 'show']);
        $r->post('/companies', [self::class, 'store']);
        $r->put('/companies/{id}', [self::class, 'update']);
        $r->del('/companies/{id}', [self::class, 'destroy']);
        $r->get('/companies/{id}/stats', [self::class, 'stats']);
    }

    public static function index(): void
    {
        $type    = Req::int('type', 1);
        $keyword = Req::str('keyword');
        $status  = Req::str('status');
        [$page, $size, $offset] = Req::page();

        $where = ['is_deleted = 0', 'company_type = ?'];
        $bind  = [$type];
        if ($keyword !== '') {
            $where[] = '(company_name LIKE ? OR city LIKE ? OR address LIKE ?)';
            array_push($bind, "%{$keyword}%", "%{$keyword}%", "%{$keyword}%");
        }
        if ($status !== '') {
            $where[] = 'status = ?';
            $bind[]  = (int) $status;
        }
        $w = implode(' AND ', $where);

        $total = (int) Db::val("SELECT COUNT(*) FROM org_company WHERE {$w}", $bind);
        $rows  = Db::all(
            "SELECT * FROM org_company WHERE {$w} ORDER BY id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );

        if ($type === 2) {
            foreach ($rows as &$row) {
                $row['stats'] = self::statOf((int) $row['id']);
            }
            unset($row);
        }
        Response::page($rows, $total, $page, $size);
    }

    public static function options(): void
    {
        $type = Req::int('type', 1);
        $kw   = Req::str('keyword');
        $bind = [$type];
        $sql  = 'SELECT id, company_name, country, state_province, city, zip_code, address, phone, contact, bond_no
                   FROM org_company WHERE is_deleted = 0 AND status = 1 AND company_type = ?';
        if ($kw !== '') {
            $sql .= ' AND company_name LIKE ?';
            $bind[] = "%{$kw}%";
        }
        $sql .= ' ORDER BY company_name LIMIT 200';
        Response::ok(Db::all($sql, $bind));
    }

    public static function show(array $p): void
    {
        $row = Db::one('SELECT * FROM org_company WHERE id = ? AND is_deleted = 0', [(int) $p['id']]);
        $row ? Response::ok($row) : Response::fail('记录不存在');
    }

    private static function payload(): array
    {
        return [
            'company_type'   => Req::int('company_type', 1),
            'company_name'   => Req::str('company_name'),
            'country'        => Req::str('country'),
            'state_province' => Req::str('state_province'),
            'city'           => Req::str('city'),
            'zip_code'       => Req::str('zip_code'),
            'address'        => Req::str('address'),
            'phone'          => Req::str('phone'),
            'contact'        => Req::str('contact'),
            'email'          => Req::str('email'),
            'bond_no'        => Req::str('bond_no'),
            'remark'         => Req::str('remark'),
            'status'         => Req::int('status', 1),
            'manage_status'  => Req::int('manage_status', 1),
        ];
    }

    public static function store(): void
    {
        $data = self::payload();
        if ($data['company_name'] === '') {
            Response::fail('公司名称不能为空');
        }
        $data['created_by'] = \QG\Auth::id();
        $id = Db::insert('org_company', $data);
        App::log('company', 'create', $id, $data);
        Response::ok(['id' => $id], '已新增');
    }

    public static function update(array $p): void
    {
        $id = (int) $p['id'];
        if (!Db::val('SELECT id FROM org_company WHERE id = ? AND is_deleted = 0', [$id])) {
            Response::fail('记录不存在');
        }
        $data = self::payload();
        if ($data['company_name'] === '') {
            Response::fail('公司名称不能为空');
        }
        Db::update('org_company', $data, 'id = ?', [$id]);
        App::log('company', 'update', $id, $data);
        Response::ok(null, '已保存');
    }

    public static function destroy(array $p): void
    {
        $id = (int) $p['id'];
        $used = Db::val('SELECT COUNT(*) FROM biz_order WHERE importer_id = ? AND is_deleted = 0', [$id]);
        if ($used) {
            Response::fail('该公司已被 ' . $used . ' 个订单引用，不能删除，可改为停用');
        }
        Db::update('org_company', ['is_deleted' => 1], 'id = ?', [$id]);
        App::log('company', 'delete', $id);
        Response::ok(null, '已删除');
    }

    /**
     * 进口商统计口径（对应「进口商管理」后四列）
     * 已放行 = 订单状态 released
     * 已查验 = 已产生查验记录
     * 已预报 = 订单状态 forecast
     * 查验中 = 订单状态 inspecting
     * 单位：单（柜）
     */
    public static function stats(array $p): void
    {
        Response::ok(self::statOf((int) $p['id']));
    }

    private static function statOf(int $id): array
    {
        $row = Db::one(
            "SELECT
                SUM(customs_status = 'released')   AS released,
                SUM(customs_status = 'forecast')   AS forecast,
                SUM(customs_status = 'inspecting') AS inspecting
             FROM biz_order WHERE importer_id = ? AND is_deleted = 0",
            [$id]
        ) ?: [];
        $inspected = (int) Db::val(
            'SELECT COUNT(DISTINCT o.id) FROM biz_order o
               JOIN biz_inspect i ON i.order_id = o.id
              WHERE o.importer_id = ? AND o.is_deleted = 0',
            [$id]
        );
        return [
            'released'   => (int) ($row['released'] ?? 0),
            'inspected'  => $inspected,
            'forecast'   => (int) ($row['forecast'] ?? 0),
            'inspecting' => (int) ($row['inspecting'] ?? 0),
        ];
    }
}
