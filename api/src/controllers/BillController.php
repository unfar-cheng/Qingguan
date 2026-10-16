<?php
namespace QG\controllers;

use QG\Auth;
use QG\ControlDb;
use QG\Req;
use QG\Response;
use QG\Router;
use QG\Tenant;
use QG\repos\BillRepo;

/** 租户侧账单中心：仅租户管理员可见（本租户账单 + 自助续费生成待付账单）。 */
class BillController
{
    public static function routes(Router $r): void
    {
        $r->get('/bills', [self::class, 'list']);
        $r->post('/bills', [self::class, 'renew']);
    }

    public static function list(): void
    {
        Auth::requireLogin();
        if (!Auth::isTenantAdmin()) {
            Response::fail('仅租户管理员可查看账单', 403, 403);
        }
        Response::ok(BillRepo::forTenant(Tenant::requireId()));
    }

    public static function renew(): void
    {
        Auth::requireLogin();
        if (!Auth::isTenantAdmin()) {
            Response::fail('仅租户管理员可续费', 403, 403);
        }
        $cycle = Req::str('cycle');
        if (!in_array($cycle, ['month', 'year'], true)) {
            Response::fail('请选择月付或年付');
        }
        $tid = Tenant::requireId();
        $planId = (int) ControlDb::val('SELECT plan_id FROM saas_tenant WHERE id = ?', [$tid]);
        if (!$planId) {
            Response::fail('当前租户未关联套餐，请联系平台');
        }
        $col = $cycle === 'month' ? 'price_month' : 'price_year';
        $amount = (float) ControlDb::val("SELECT {$col} FROM saas_plan WHERE id = ?", [$planId]);
        BillRepo::create($tid, $cycle, $amount, '自助续费');
        Response::ok(null, '账单已生成，转账后请联系平台确认');
    }
}
