<?php
namespace QG\controllers;

use QG\App;
use QG\Db;
use QG\Req;
use QG\Response;
use QG\Router;

class PlanController
{
    public static function routes(Router $r): void
    {
        $r->get('/plans', [self::class, 'index']);
        $r->get('/plans/{id}', [self::class, 'show']);
        $r->post('/plans', [self::class, 'store']);
        $r->put('/plans/{id}', [self::class, 'update']);
        $r->del('/plans/{id}', [self::class, 'destroy']);
    }

    public static function index(): void
    {
        $keyword = Req::str('keyword');
        $where = ['1=1'];
        $bind  = [];
        if ($keyword !== '') {
            $where[] = '(plan_name LIKE ? OR plan_code LIKE ?)';
            $bind[] = "%{$keyword}%";
            $bind[] = "%{$keyword}%";
        }
        if (Req::str('status') !== '') {
            $where[] = 'status = ?';
            $bind[] = Req::int('status');
        }
        $rows = Db::all(
            'SELECT * FROM biz_plan WHERE ' . implode(' AND ', $where) . ' ORDER BY sort, id',
            $bind
        );
        Response::ok($rows);
    }

    public static function show(array $p): void
    {
        $row = Db::one('SELECT * FROM biz_plan WHERE id = ?', [(int) $p['id']]);
        $row ? Response::ok($row) : Response::fail('方案不存在');
    }

    private static function payload(): array
    {
        return [
            'plan_code'       => Req::str('plan_code'),
            'plan_name'       => Req::str('plan_name'),
            'amount'          => Req::num('amount', null),
            'applicable_port' => Req::str('applicable_port'),
            'customs_port'    => Req::str('customs_port'),
            'intro'           => Req::str('intro'),
            'notice'          => Req::str('notice'),
            'sort'            => Req::int('sort', 0),
            'status'          => Req::int('status', 1),
        ];
    }

    public static function store(): void
    {
        $data = self::payload();
        if ($data['plan_code'] === '' || $data['plan_name'] === '') {
            Response::fail('方案编码与方案名称为必填');
        }
        if (Db::val('SELECT id FROM biz_plan WHERE plan_code = ?', [$data['plan_code']])) {
            Response::fail('方案编码已存在');
        }
        $id = Db::insert('biz_plan', $data);
        App::log('plan', 'create', $id, $data);
        Response::ok(['id' => $id], '已新增');
    }

    public static function update(array $p): void
    {
        $id  = (int) $p['id'];
        $row = Db::one('SELECT * FROM biz_plan WHERE id = ?', [$id]);
        if (!$row) {
            Response::fail('方案不存在');
        }
        $data = self::payload();
        if (!empty($data['plan_code']) && Db::val('SELECT id FROM biz_plan WHERE plan_code = ? AND id <> ?', [$data['plan_code'], $id])) {
            Response::fail('方案编码已存在');
        }
        Db::update('biz_plan', $data, 'id = ?', [$id]);
        App::log('plan', 'update', $id, $data);
        Response::ok(null, '已保存');
    }

    public static function destroy(array $p): void
    {
        $id = (int) $p['id'];
        $used = Db::val('SELECT COUNT(*) FROM biz_order WHERE plan_id = ? AND is_deleted = 0', [$id]);
        if ($used) {
            Response::fail('该方案已被 ' . $used . ' 个订单引用，不能删除，可改为停用');
        }
        Db::exec('DELETE FROM biz_plan WHERE id = ?', [$id]);
        App::log('plan', 'delete', $id);
        Response::ok(null, '已删除');
    }
}
