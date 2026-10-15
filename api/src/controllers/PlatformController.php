<?php
namespace QG\controllers;

use QG\App;
use QG\Auth;
use QG\ControlDb;
use QG\RateLimit;
use QG\Req;
use QG\Response;
use QG\Router;
use QG\TenantProvisioner;

/** 平台侧：超管登录 + 租户审核。全部路由在 /api/platform/* 下，令牌 scope=platform。 */
class PlatformController
{
    public static function routes(Router $r): void
    {
        $r->post('/platform/login', [self::class, 'login'], false);
        $r->post('/platform/logout', [self::class, 'logout']);
        $r->get('/platform/me', [self::class, 'me']);
        $r->get('/platform/tenants', [self::class, 'tenants']);
        $r->post('/platform/tenants/{id}/approve', [self::class, 'approve']);
        $r->post('/platform/tenants/{id}/reject', [self::class, 'reject']);
        $r->post('/platform/tenants/{id}/suspend', [self::class, 'suspend']);
        $r->post('/platform/tenants/{id}/enable', [self::class, 'enable']);
    }

    public static function login(): void
    {
        if (!RateLimit::allow('platform-login|' . App::remoteAddr(), 10, 600)) {
            Response::fail('尝试过于频繁，请 10 分钟后再试', 429, 429);
        }
        $username = Req::require_('username', '账号');
        $password = Req::str('password');
        if ($password === '') {
            Response::fail('密码不能为空');
        }
        $u = ControlDb::one('SELECT * FROM saas_platform_user WHERE username = ? AND status = 1 LIMIT 1', [$username]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Response::fail('账号或密码错误');
        }
        ControlDb::exec('UPDATE saas_platform_user SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
            [App::ip(), (int) $u['id']]);
        Auth::platformLogin($u);
        self::audit((int) $u['id'], 'login', null, '');
        unset($u['password_hash']);
        $u['scope'] = 'platform';
        Response::ok($u, '登录成功');
    }

    public static function logout(): void
    {
        Auth::requirePlatform();
        Auth::logout();
        Response::ok(null, '已退出');
    }

    public static function me(): void
    {
        Response::ok(Auth::requirePlatform());
    }

    public static function tenants(): void
    {
        Auth::requirePlatform();
        [$page, $size, $offset] = Req::page();
        $status = Req::str('status');
        $where = '1=1';
        $bind  = [];
        if (in_array($status, ['pending', 'active', 'rejected', 'suspended'], true)) {
            $where .= ' AND t.status = ?';
            $bind[] = $status;
        }
        $total = (int) ControlDb::val("SELECT COUNT(*) FROM saas_tenant t WHERE {$where}", $bind);
        $rows  = ControlDb::all(
            "SELECT t.id, t.code, t.name, t.mode, t.status, t.contact_name, t.contact_phone, t.contact_email,
                    t.admin_username, t.reject_reason, t.applied_at, t.reviewed_at, t.logo_url,
                    p.name AS plan_name
               FROM saas_tenant t LEFT JOIN saas_plan p ON p.id = t.plan_id
              WHERE {$where} ORDER BY (t.status = 'pending') DESC, t.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        Response::page($rows, $total, $page, $size);
    }

    public static function approve(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = self::findTenant($p);
        if ($t['status'] === 'active') {
            Response::ok(null, '该租户已是开通状态（幂等）');
        }
        if ($t['status'] !== 'pending') {
            Response::fail('当前状态不可审核通过：' . $t['status']);
        }
        try {
            TenantProvisioner::provision($t, (int) $admin['id']);
        } catch (\Throwable $e) {
            // 并发 approve 兜底：两个管理员同时点「通过」时，loser 会撞 uk_tenant_username 的 1062。
            // 此时先重读控制面状态，若已是 active 则按幂等成功处理（任务 3 质量审查的结论）。
            $now = ControlDb::one('SELECT status FROM saas_tenant WHERE id = ? LIMIT 1', [(int) $t['id']]);
            if (($now['status'] ?? '') === 'active') {
                Response::ok(null, '该租户已在其他请求中开通（幂等）');
            }
            self::audit((int) $admin['id'], 'provision_failed', (int) $t['id'], mb_substr($e->getMessage(), 0, 500));
            Response::fail('开通失败：' . $e->getMessage());
        }
        Response::ok(null, '已开通');
    }

    public static function reject(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = self::findTenant($p);
        if ($t['status'] !== 'pending') {
            Response::fail('仅待审核的租户可驳回');
        }
        $reason = Req::str('reason');
        if ($reason === '') {
            Response::fail('驳回原因不能为空');
        }
        ControlDb::exec("UPDATE saas_tenant SET status = 'rejected', reject_reason = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?",
            [$reason, (int) $admin['id'], (int) $t['id']]);
        self::audit((int) $admin['id'], 'reject', (int) $t['id'], $reason);
        Response::ok(null, '已驳回');
    }

    public static function suspend(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = self::findTenant($p);
        if ($t['status'] !== 'active') {
            Response::fail('仅已开通的租户可停用');
        }
        ControlDb::exec("UPDATE saas_tenant SET status = 'suspended' WHERE id = ?", [(int) $t['id']]);
        self::audit((int) $admin['id'], 'suspend', (int) $t['id'], '');
        Response::ok(null, '已停用');
    }

    public static function enable(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = self::findTenant($p);
        if ($t['status'] !== 'suspended') {
            Response::fail('仅已停用的租户可重新启用');
        }
        ControlDb::exec("UPDATE saas_tenant SET status = 'active' WHERE id = ?", [(int) $t['id']]);
        self::audit((int) $admin['id'], 'enable', (int) $t['id'], '');
        Response::ok(null, '已启用');
    }

    private static function findTenant(array $p): array
    {
        $t = ControlDb::one('SELECT * FROM saas_tenant WHERE id = ? LIMIT 1', [(int) ($p['id'] ?? 0)]);
        if (!$t) {
            Response::fail('租户不存在');
        }
        return $t;
    }

    private static function audit(int $platformUserId, string $action, ?int $targetId, string $detail): void
    {
        ControlDb::insert('saas_audit', [
            'platform_user_id' => $platformUserId,
            'action'           => $action,
            'target_type'      => $targetId ? 'tenant' : '',
            'target_id'        => $targetId,
            'detail'           => $detail,
            'ip'               => App::ip(),
        ]);
    }
}
