<?php
namespace QG\controllers;

use QG\App;
use QG\Auth;
use QG\Db;
use QG\Req;
use QG\Response;
use QG\Router;

class OrderController
{
    private const LIST_SELECT = '
        o.*,
        pl.plan_name, pl.plan_code, pl.intro AS plan_intro,
        imp.company_name AS importer_name,
        (SELECT GROUP_CONCAT(c.container_no ORDER BY c.sort SEPARATOR ", ")
           FROM biz_order_container c WHERE c.order_id = o.id) AS container_nos,
        (SELECT COUNT(*) FROM biz_order_file f WHERE f.order_id = o.id) AS file_count,
        (SELECT ad.company FROM biz_order_address ad WHERE ad.order_id = o.id AND ad.addr_type = 1 LIMIT 1) AS shipper_name,
        (SELECT ad.company FROM biz_order_address ad WHERE ad.order_id = o.id AND ad.addr_type = 2 LIMIT 1) AS consignee_name,
        (SELECT i.declare_date FROM biz_isf i WHERE i.order_id = o.id ORDER BY i.id DESC LIMIT 1) AS declare_date,
        u.real_name AS creator_name';

    private const LIST_FROM = '
        FROM biz_order o
        LEFT JOIN biz_plan pl  ON pl.id = o.plan_id
        LEFT JOIN org_company imp ON imp.id = o.importer_id
        LEFT JOIN sys_user u   ON u.id = o.created_by';

    public static function routes(Router $r): void
    {
        $r->get('/orders', [self::class, 'index']);
        $r->get('/orders/stat', [self::class, 'stat']);
        $r->get('/orders/export', [self::class, 'export']);
        $r->get('/orders/{id}', [self::class, 'show']);
        $r->post('/orders', [self::class, 'store']);
        $r->put('/orders/{id}', [self::class, 'update']);
        $r->del('/orders/{id}', [self::class, 'destroy']);
        $r->get('/orders/{id}/logs', [self::class, 'logs']);
        $r->post('/orders/{id}/status', [self::class, 'changeStatus']);
        $r->post('/orders/{id}/verify', [self::class, 'verify']);
        $r->post('/orders/{id}/isf', [self::class, 'declareIsf']);
        $r->post('/orders/{id}/inspect', [self::class, 'addInspect']);
        $r->del('/orders/{id}/inspect/{iid}', [self::class, 'delInspect']);
    }

    /* ---------------- 列表 ---------------- */

    private static function buildWhere(): array
    {
        $module = Req::str('module');                 // isf | customs | pickup | inspect
        $where  = ['o.is_deleted = 0'];
        $bind   = [];

        switch ($module) {
            case 'isf':
                $where[] = 'o.isf_flag = 1';
                break;
            case 'pickup':
                $where[] = 'o.pickup_flag = 1';
                break;
            case 'inspect':
                $where[] = "(o.customs_status = 'inspecting' OR EXISTS (SELECT 1 FROM biz_inspect x WHERE x.order_id = o.id))";
                break;
            case 'customs':
            default:
                break;
        }

        $kw = Req::str('keyword');
        if ($kw !== '') {
            $where[] = '(o.mbl_no LIKE ? OR o.hbl_no LIKE ? OR o.container_type LIKE ? OR imp.company_name LIKE ?)';
            array_push($bind, "%{$kw}%", "%{$kw}%", "%{$kw}%", "%{$kw}%");
        }
        $like = [
            'mbl_no'        => 'o.mbl_no',
            'hbl_no'        => 'o.hbl_no',
            'customer_no'   => 'o.customer_no',
            'customer_order_no' => 'o.customer_order_no',
            'vessel'        => 'o.vessel',
            'customs_port'  => 'o.customs_port',
            'port_of_loading' => 'o.port_of_loading',
            'port_of_delivery' => 'o.port_of_delivery',
            'carrier'       => 'o.carrier',
        ];
        foreach ($like as $k => $col) {
            $v = Req::str($k);
            if ($v !== '') {
                $where[] = "{$col} LIKE ?";
                $bind[]  = "%{$v}%";
            }
        }

        $eq = [
            'match_status'   => 'o.match_status',
            'customs_status' => 'o.customs_status',
            'is_verified'    => 'o.is_verified',
            'isf_flag'       => 'o.isf_flag',
            'pickup_flag'    => 'o.pickup_flag',
            'plan_id'        => 'o.plan_id',
            'importer_id'    => 'o.importer_id',
            'route_type'     => 'o.route_type',
            'biz_type'       => 'o.biz_type',
            'container_type' => 'o.container_type',
            'inspect_type'   => 'o.inspect_type',
        ];
        foreach ($eq as $k => $col) {
            $v = Req::str($k);
            if ($v !== '') {
                $where[] = "{$col} = ?";
                $bind[]  = $v;
            }
        }

        // 柜号：需要 EXISTS 子查询
        $cn = Req::str('container_no');
        if ($cn !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM biz_order_container c2 WHERE c2.order_id = o.id AND c2.container_no LIKE ?)';
            $bind[]  = "%{$cn}%";
        }

        $ranges = [
            'created'  => ['o.created_at', true],
            'release'  => ['o.release_date', false],
            'etd'      => ['o.etd', false],
            'eta'      => ['o.eta', false],
            'declare'  => ['(SELECT i.declare_date FROM biz_isf i WHERE i.order_id = o.id ORDER BY i.id DESC LIMIT 1)', true],
        ];
        foreach ($ranges as $key => [$col, $withTime]) {
            $from = Req::str($key . '_start');
            $to   = Req::str($key . '_end');
            if ($from !== '') {
                $where[] = "{$col} >= ?";
                $bind[]  = str_replace('/', '-', $from) . ($withTime && strlen($from) <= 10 ? ' 00:00:00' : '');
            }
            if ($to !== '') {
                $where[] = "{$col} <= ?";
                $bind[]  = str_replace('/', '-', $to) . ($withTime && strlen($to) <= 10 ? ' 23:59:59' : '');
            }
        }

        return [$where, $bind];
    }

    private static function orderBy(): string
    {
        $allow = ['o.created_at', 'o.etd', 'o.eta', 'o.id', 'o.mbl_no'];
        $col   = Req::str('sortBy', 'o.id');
        $col   = in_array($col, $allow, true) ? $col : 'o.id';
        $dir   = strtolower(Req::str('sortDir', 'desc')) === 'asc' ? 'ASC' : 'DESC';
        return " ORDER BY {$col} {$dir}, o.id DESC";
    }

    public static function index(): void
    {
        Auth::requireLogin();
        [$where, $bind] = self::buildWhere();
        [$page, $size, $offset] = Req::page();
        $w = implode(' AND ', $where);

        $total = (int) Db::val('SELECT COUNT(*) ' . self::LIST_FROM . ' WHERE ' . $w, $bind);
        $rows  = Db::all(
            'SELECT ' . self::LIST_SELECT . ' ' . self::LIST_FROM . ' WHERE ' . $w . self::orderBy() . " LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        Response::page(self::decorate($rows), $total, $page, $size);
    }

    private static function decorate(array $rows): array
    {
        $statusText = ['forecast' => '已预报', 'declaring' => '申报中', 'inspecting' => '查验中', 'released' => '已放行'];
        foreach ($rows as &$r) {
            $r['customs_status_text'] = $statusText[$r['customs_status'] ?? ''] ?? '—';
            $r['is_verified_text']    = ((int) $r['is_verified'] === 1) ? '已核销' : '未核销';
            $r['match_status_text']   = ((int) $r['match_status'] === 1) ? 'ISP匹配成功' : '未匹配';
            $r['isf_text']            = ((int) $r['isf_flag'] === 1) ? '是' : '否';
            $r['pickup_text']         = ((int) $r['pickup_flag'] === 1) ? '是' : '否';
        }
        unset($r);
        return $rows;
    }

    public static function export(): void
    {
        Auth::requireLogin();
        [$where, $bind] = self::buildWhere();
        $rows = Db::all('SELECT ' . self::LIST_SELECT . ' ' . self::LIST_FROM . ' WHERE ' . implode(' AND ', $where) . self::orderBy() . ' LIMIT 50000', $bind);
        $rows = self::decorate($rows);

        $cols = [
            'mbl_no' => '主提单号', 'hbl_no' => '分单号', 'container_nos' => '柜号',
            'plan_name' => '报价方案', 'plan_intro' => '方案介绍', 'plan_amount' => '报价', 'actual_fee' => '实收费用',
            'container_type' => '柜型', 'container_weight' => '柜重', 'etd' => 'ETD', 'eta' => 'ETA',
            'shipper_name' => '发货人', 'consignee_name' => '收货人', 'importer_name' => '进口商',
            'port_of_loading' => '起运港', 'port_of_delivery' => '目的港', 'customs_port' => '清关港',
            'port_of_discharge' => '卸货港', 'vessel' => '船名', 'voyage' => '船次',
            'route_type' => '航线类型', 'customs_status_text' => '清关状态', 'release_date' => '放行日期',
            'inspect_type' => '查验类型', 'inspect_abnormal' => '查验异常',
            'created_at' => '创建时间', 'is_verified_text' => '是否核销', 'remark' => '备注',
        ];

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="orders_' . date('YmdHis') . '.csv"');
        }
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, array_values($cols));
        foreach ($rows as $r) {
            $line = [];
            foreach (array_keys($cols) as $k) {
                $line[] = (string) ($r[$k] ?? '');
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    public static function stat(): void
    {
        Auth::requireLogin();
        Response::ok([
            'total'      => (int) Db::val('SELECT COUNT(*) FROM biz_order WHERE is_deleted = 0'),
            'forecast'   => (int) Db::val("SELECT COUNT(*) FROM biz_order WHERE is_deleted = 0 AND customs_status = 'forecast'"),
            'declaring'  => (int) Db::val("SELECT COUNT(*) FROM biz_order WHERE is_deleted = 0 AND customs_status = 'declaring'"),
            'inspecting' => (int) Db::val("SELECT COUNT(*) FROM biz_order WHERE is_deleted = 0 AND customs_status = 'inspecting'"),
            'released'   => (int) Db::val("SELECT COUNT(*) FROM biz_order WHERE is_deleted = 0 AND customs_status = 'released'"),
            'unverified' => (int) Db::val('SELECT COUNT(*) FROM biz_order WHERE is_deleted = 0 AND is_verified = 0'),
            'isf'        => (int) Db::val('SELECT COUNT(*) FROM biz_order WHERE is_deleted = 0 AND isf_flag = 1'),
            'pickup'     => (int) Db::val('SELECT COUNT(*) FROM biz_order WHERE is_deleted = 0 AND pickup_flag = 1'),
            'shipper'    => (int) Db::val('SELECT COUNT(*) FROM org_company WHERE is_deleted = 0 AND company_type = 1'),
            'importer'   => (int) Db::val('SELECT COUNT(*) FROM org_company WHERE is_deleted = 0 AND company_type = 2'),
        ]);
    }

    /* ---------------- 详情 ---------------- */

    public static function show(array $p): void
    {
        Auth::requireLogin();
        $id  = (int) $p['id'];
        $row = Db::one('SELECT ' . self::LIST_SELECT . ' ' . self::LIST_FROM . ' WHERE o.id = ? AND o.is_deleted = 0', [$id]);
        if (!$row) {
            Response::fail('订单不存在');
        }
        $d = self::decorate([$row])[0];
        $d['containers'] = Db::all('SELECT * FROM biz_order_container WHERE order_id = ? ORDER BY sort, id', [$id]);
        $d['hscodes']    = Db::all('SELECT * FROM biz_order_hscode WHERE order_id = ? ORDER BY sort, id', [$id]);
        $d['addresses']  = Db::all('SELECT * FROM biz_order_address WHERE order_id = ? ORDER BY addr_type', [$id]);
        $d['files']      = Db::all('SELECT * FROM biz_order_file WHERE order_id = ? ORDER BY id DESC', [$id]);
        $d['isfs']       = Db::all('SELECT * FROM biz_isf WHERE order_id = ? ORDER BY id DESC', [$id]);
        $d['inspects']   = Db::all('SELECT * FROM biz_inspect WHERE order_id = ? ORDER BY id DESC', [$id]);
        $d['logs']       = self::logsOf($id);
        Response::ok($d);
    }

    private static function logsOf(int $id): array
    {
        return Db::all(
            'SELECT l.*, u.real_name AS operator_name FROM biz_order_log l
               LEFT JOIN sys_user u ON u.id = l.operator_id
              WHERE l.order_id = ? ORDER BY l.id DESC LIMIT 200',
            [$id]
        );
    }

    public static function logs(array $p): void
    {
        Auth::requireLogin();
        Response::ok(self::logsOf((int) $p['id']));
    }

    /* ---------------- 新增 / 编辑 ---------------- */

    private static function payload(): array
    {
        return [
            'customer_no'       => Req::str('customer_no'),
            'customer_order_no' => Req::str('customer_order_no'),
            'mbl_no'            => Req::str('mbl_no'),
            'hbl_no'            => Req::str('hbl_no'),
            'carrier'           => Req::str('carrier'),
            'importer_id'       => Req::int('importer_id') ?: null,
            'vessel'            => Req::str('vessel'),
            'voyage'            => Req::str('voyage'),
            'origin_country'    => Req::str('origin_country', 'CN'),
            'dest_country'      => Req::str('dest_country', 'US'),
            'port_of_loading'   => Req::str('port_of_loading'),
            'port_of_delivery'  => Req::str('port_of_delivery'),
            'port_of_discharge' => Req::str('port_of_discharge'),
            'customs_port'      => Req::str('customs_port'),
            'etd'               => Req::date('etd'),
            'eta'               => Req::date('eta'),
            'isf_flag'          => Req::int('isf_flag', 1),
            'pickup_flag'       => Req::int('pickup_flag', 0),
            'plan_id'           => Req::int('plan_id') ?: null,
            'plan_amount'       => Req::num('plan_amount'),
            'actual_fee'        => Req::num('actual_fee'),
            'container_type'    => Req::str('container_type'),
            'container_weight'  => Req::num('container_weight'),
            'match_status'      => Req::int('match_status', 0),
            'customs_status'    => Req::str('customs_status', 'forecast') ?: 'forecast',
            'release_date'      => Req::date('release_date'),
            'inspect_type'      => Req::str('inspect_type'),
            'inspect_abnormal'  => Req::str('inspect_abnormal'),
            'route_type'        => Req::str('route_type'),
            'biz_type'          => Req::str('biz_type'),
            'is_verified'       => Req::int('is_verified', 0),
            'customer_remark'   => Req::str('customer_remark'),
            'remark'            => Req::str('remark'),
        ];
    }

    private static function saveChildren(int $orderId, bool $replace = true): void
    {
        if ($replace) {
            foreach (['biz_order_container', 'biz_order_hscode', 'biz_order_address'] as $t) {
                Db::exec("DELETE FROM {$t} WHERE order_id = ?", [$orderId]);
            }
        }

        foreach (Req::arr('containers') as $i => $c) {
            if (!is_array($c)) {
                continue;
            }
            $row = [
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

        foreach (Req::arr('hscodes') as $i => $h) {
            if (!is_array($h) || trim((string) ($h['hs_code'] ?? '')) === '') {
                continue;
            }
            Db::insert('biz_order_hscode', [
                'order_id'       => $orderId,
                'hs_code'        => trim((string) $h['hs_code']),
                'origin_country' => trim((string) ($h['origin_country'] ?? '')),
                'sort'           => (int) $i,
            ]);
        }

        foreach (Req::arr('addresses') as $a) {
            if (!is_array($a)) {
                continue;
            }
            $type = (int) ($a['addr_type'] ?? 0);
            if ($type < 1 || $type > 3) {
                continue;
            }
            $row = [
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
            Db::exec('DELETE FROM biz_order_address WHERE order_id = ? AND addr_type = ?', [$orderId, $type]);
            Db::insert('biz_order_address', $row);
        }
    }

    /** 各订单功能的「创建新订单」按钮权限（sys_permission.type=2，见 db/upgrade-2026-09-21b.sql）。
     *  前端提交时带 from_module（来自列表页跳转的 ?from=），据此判定该用哪个按钮权限；
     *  直接打开建单页（没有 from_module）时按「创建订单」页的权限算。 */
    private const CREATE_PERMS = [
        'create'  => 'order.create.new',
        'isf'     => 'order.isf.new',
        'customs' => 'order.customs.new',
        'pickup'  => 'order.pickup.new',
        'inspect' => 'order.inspect.new',
    ];

    public static function store(): void
    {
        Auth::requireLogin();
        /* 按钮级权限：创建订单也要校验（前端会置灰按钮，但不能只靠前端） */
        $from = Req::str('from_module', 'create');
        $code = self::CREATE_PERMS[$from] ?? self::CREATE_PERMS['create'];
        if (!Auth::can($code)) {
            Response::fail('没有创建订单的权限（需要 ' . $code . '），请联系管理员开通', 403);
        }
        $data = self::payload();
        if ($data['mbl_no'] === '') {
            Response::fail('主提单号/MBL 不能为空');
        }
        if (Db::val('SELECT id FROM biz_order WHERE mbl_no = ? AND is_deleted = 0', [$data['mbl_no']])) {
            Response::fail('主提单号已存在');
        }
        $data['order_no']   = self::genOrderNo();
        $data['created_by'] = Auth::id();
        $data['created_at'] = App::now();

        Db::begin();
        try {
            $id = Db::insert('biz_order', $data);
            self::saveChildren($id);
            if (Req::int('isf_flag', 1) === 1) {
                Db::insert('biz_isf', [
                    'order_id'     => $id,
                    'declare_date' => App::now(),
                    'status'       => 0,
                ]);
            }
            Db::insert('biz_order_log', [
                'order_id' => $id, 'action' => '创建', 'remark' => '新建订单',
                'operator_id' => Auth::id(), 'created_at' => App::now(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Response::fail('保存失败：' . $e->getMessage());
        }
        App::log('order', 'create', $id, $data['mbl_no']);
        Response::ok(['id' => $id, 'order_no' => $data['order_no']], '提交成功');
    }

    public static function update(array $p): void
    {
        Auth::requireLogin();
        $id  = (int) $p['id'];
        $old = Db::one('SELECT * FROM biz_order WHERE id = ? AND is_deleted = 0', [$id]);
        if (!$old) {
            Response::fail('订单不存在');
        }
        $data = self::payload();
        if ($data['mbl_no'] === '') {
            Response::fail('主提单号/MBL 不能为空');
        }
        if (Db::val('SELECT id FROM biz_order WHERE mbl_no = ? AND id <> ? AND is_deleted = 0', [$data['mbl_no'], $id])) {
            Response::fail('主提单号已存在');
        }
        $data['updated_at'] = App::now();

        Db::begin();
        try {
            Db::update('biz_order', $data, 'id = ?', [$id]);
            self::saveChildren($id);
            $changed = [];
            foreach ($data as $k => $v) {
                if (array_key_exists($k, $old) && (string) $old[$k] !== (string) $v) {
                    $changed[$k] = ['from' => $old[$k], 'to' => $v];
                }
            }
            Db::insert('biz_order_log', [
                'order_id' => $id, 'action' => '编辑',
                'detail'   => null,
                'remark'   => $changed ? ('变更：' . implode('，', array_keys($changed))) : '提交编辑（明细更新）',
                'operator_id' => Auth::id(), 'created_at' => App::now(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Response::fail('保存失败：' . $e->getMessage());
        }
        App::log('order', 'update', $id, array_keys($changed ?? []));
        Response::ok(null, '已保存');
    }

    public static function destroy(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        if (!Db::val('SELECT id FROM biz_order WHERE id = ? AND is_deleted = 0', [$id])) {
            Response::fail('订单不存在');
        }
        Db::update('biz_order', ['is_deleted' => 1, 'updated_at' => App::now()], 'id = ?', [$id]);
        App::log('order', 'delete', $id);
        Response::ok(null, '已删除');
    }

    public static function changeStatus(array $p): void
    {
        Auth::requireLogin();
        $id     = (int) $p['id'];
        $status = Req::str('customs_status');
        $allow  = ['forecast', 'declaring', 'inspecting', 'released'];
        if (!in_array($status, $allow, true)) {
            Response::fail('状态值不合法');
        }
        $old = Db::one('SELECT customs_status FROM biz_order WHERE id = ? AND is_deleted = 0', [$id]);
        if (!$old) {
            Response::fail('订单不存在');
        }
        $upd = ['customs_status' => $status, 'updated_at' => App::now()];
        if ($status === 'released') {
            $upd['release_date'] = Req::date('release_date') ?: date('Y-m-d');
        }
        Db::update('biz_order', $upd, 'id = ?', [$id]);
        Db::insert('biz_order_log', [
            'order_id' => $id, 'action' => '状态变更', 'field' => 'customs_status',
            'old_value' => $old['customs_status'], 'new_value' => $status,
            'remark' => Req::str('remark') ?: '清关状态变更',
            'operator_id' => Auth::id(), 'created_at' => App::now(),
        ]);
        App::log('order', 'status', $id, $status);
        Response::ok(null, '状态已更新');
    }

    public static function verify(array $p): void
    {
        Auth::requireLogin();
        $id  = (int) $p['id'];
        $val = Req::int('is_verified', 1) === 1 ? 1 : 0;
        if (!Db::val('SELECT id FROM biz_order WHERE id = ? AND is_deleted = 0', [$id])) {
            Response::fail('订单不存在');
        }
        Db::update('biz_order', ['is_verified' => $val, 'updated_at' => App::now()], 'id = ?', [$id]);
        Db::insert('biz_order_log', [
            'order_id' => $id, 'action' => '核销', 'field' => 'is_verified',
            'new_value' => $val, 'remark' => $val ? '标记已核销' : '取消核销',
            'operator_id' => Auth::id(), 'created_at' => App::now(),
        ]);
        Response::ok(null, $val ? '已核销' : '已取消核销');
    }

    public static function declareIsf(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        if (!Db::val('SELECT id FROM biz_order WHERE id = ? AND is_deleted = 0', [$id])) {
            Response::fail('订单不存在');
        }
        $status = Req::int('status', 1);
        Db::insert('biz_isf', [
            'order_id'     => $id,
            'declare_date' => App::now(),
            'status'       => $status,
            'match_msg'    => Req::str('match_msg') ?: ($status === 1 ? 'ISP匹配成功' : ''),
        ]);
        Db::update('biz_order', ['match_status' => $status === 1 ? 1 : 0, 'updated_at' => App::now()], 'id = ?', [$id]);
        App::log('order', 'isf', $id, $status);
        Response::ok(null, 'ISF 申报记录已登记');
    }

    public static function addInspect(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        if (!Db::val('SELECT id FROM biz_order WHERE id = ? AND is_deleted = 0', [$id])) {
            Response::fail('订单不存在');
        }
        $row = [
            'order_id'     => $id,
            'inspect_type' => Req::str('inspect_type'),
            'inspect_date' => Req::str('inspect_date') ?: App::now(),
            'abnormal'     => Req::str('abnormal'),
            'result'       => Req::str('result'),
            'fee'          => Req::num('fee'),
            'remark'       => Req::str('remark'),
            'created_by'   => Auth::id(),
        ];
        $iid = Db::insert('biz_inspect', $row);
        Db::update('biz_order', [
            'inspect_type'     => $row['inspect_type'],
            'inspect_abnormal' => $row['abnormal'],
            'customs_status'   => 'inspecting',
            'updated_at'       => App::now(),
        ], 'id = ?', [$id]);
        Db::insert('biz_order_log', [
            'order_id' => $id, 'action' => '查验', 'remark' => '登记查验记录：' . $row['inspect_type'],
            'operator_id' => Auth::id(), 'created_at' => App::now(),
        ]);
        Response::ok(['id' => $iid], '查验记录已登记');
    }

    public static function delInspect(array $p): void
    {
        Auth::requireLogin();
        Db::exec('DELETE FROM biz_inspect WHERE id = ? AND order_id = ?', [(int) $p['iid'], (int) $p['id']]);
        Response::ok(null, '已删除');
    }

    private static function genOrderNo(): string
    {
        $prefix = 'QG' . date('Ymd');
        $last   = Db::val('SELECT order_no FROM biz_order WHERE order_no LIKE ? ORDER BY id DESC LIMIT 1', [$prefix . '%']);
        $seq    = $last ? ((int) substr($last, -4) + 1) : 1;
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
