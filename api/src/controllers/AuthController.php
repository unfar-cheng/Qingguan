<?php
namespace QG\controllers;

use QG\App;
use QG\Auth;
use QG\ControlDb;
use QG\RateLimit;
use QG\Req;
use QG\Response;
use QG\Router;
use QG\Tenant;
use QG\repos\PermissionRepo;
use QG\repos\UserRepo;

class AuthController
{
    public static function routes(Router $r): void
    {
        $r->post('/auth/login', [self::class, 'login'], false);
        $r->post('/auth/logout', [self::class, 'logout']);
        $r->get('/auth/me', [self::class, 'me']);
        $r->post('/auth/password', [self::class, 'password']);
        $r->get('/menu', [self::class, 'menu']);
    }

    public static function login(): void
    {
        if (!RateLimit::allow('tenant-login|' . App::remoteAddr(), 20, 600)) {
            Response::fail('尝试过于频繁，请 10 分钟后再试', 429, 429);
        }
        $code     = Req::require_('tenant', '租户代码');
        $username = Req::require_('username', '账号');
        $password = Req::str('password');
        if ($password === '') {
            Response::fail('密码不能为空');
        }

        $t = ControlDb::one('SELECT * FROM saas_tenant WHERE code = ? LIMIT 1', [$code]);
        if (!$t) {
            Response::fail('租户代码不存在');
        }
        if ($t['status'] === 'pending') {
            Response::fail('申请审核中，通过后即可登录');
        }
        if ($t['status'] === 'rejected') {
            Response::fail('申请未通过' . ($t['reject_reason'] !== '' ? '：' . $t['reject_reason'] : ''));
        }
        if ($t['expires_at'] !== null
            && \QG\Billing::state($t['expires_at'], true)['state'] === 'expired') {
            Response::fail('服务已到期，请联系平台续费');
        }
        if ($t['status'] !== 'active') {
            Response::fail('该租户已停用，请联系平台');
        }

        Tenant::set(['id' => (int) $t['id'], 'mode' => $t['mode'], 'db_name' => $t['db_name'], 'code' => $t['code']]);

        $u = UserRepo::findByUsername($username);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Response::fail('账号或密码错误');
        }
        if ((int) $u['status'] !== 1) {
            Response::fail('该账号已被停用，请联系管理员');
        }

        UserRepo::touchLogin((int) $u['id']);
        Auth::login($u, $t);
        App::log('auth', 'login', $u['id'], $username);
        unset($u['password_hash']);
        Response::ok($u, '登录成功');
    }

    public static function logout(): void
    {
        App::log('auth', 'logout', Auth::id());
        Auth::logout();
        Response::ok(null, '已退出');
    }

    public static function me(): void
    {
        $u = Auth::requireLogin();
        $u['permissions'] = Auth::permissionCodes();
        $u['billing'] = ['state' => 'none', 'days_left' => 0, 'trial' => false, 'expires_at' => $u['expires_at'] ?? null];
        if (($u['scope'] ?? 'tenant') === 'tenant' && !empty($u['expires_at'])) {
            $hasPaid = (bool) ControlDb::val(
                'SELECT COUNT(*) FROM saas_bill WHERE tenant_id = ? AND status = ?',
                [(int) $u['tenant_id'], 'paid']
            );
            $u['billing'] = \QG\Billing::state($u['expires_at'], $hasPaid) + ['expires_at' => $u['expires_at']];
        }
        Response::ok($u);
    }

    public static function password(): void
    {
        $u = Auth::requireLogin();
        $old = Req::str('old_password');
        $new = Req::str('new_password');
        if (strlen($new) < 6) {
            Response::fail('新密码至少 6 位');
        }
        $hash = UserRepo::passwordHash((int) $u['id']);
        if (!$hash || !password_verify($old, $hash)) {
            Response::fail('原密码不正确');
        }
        UserRepo::setPassword((int) $u['id'], password_hash($new, PASSWORD_DEFAULT));
        App::log('auth', 'password', $u['id']);
        Response::ok(null, '密码已修改');
    }

    public static function menu(): void
    {
        Auth::requireLogin();
        $codes = Auth::permissionCodes();
        $all   = PermissionRepo::menuList();

        $allow = static function (array $p) use ($codes): bool {
            return in_array('*', $codes, true) || in_array($p['code'], $codes, true);
        };

        $byId = [];
        foreach ($all as $p) {
            $byId[(int) $p['id']] = $p;
        }

        /* 1) 直接授权的节点 */
        $show = [];
        foreach ($all as $p) {
            if ($allow($p)) {
                $show[(int) $p['id']] = true;
            }
        }

        /* 2) 补齐祖先节点。
              只授权了子菜单、没授权父菜单时，父菜单只是分组容器（path 为空），
              必须一起下发 —— 否则下面 isset($tree[$pid]) 不成立，整枝被静默丢掉，
              表现就是「权限明明给了，账号左侧却什么都没有」。
              界面勾选时 el-tree 的半选父节点会被一起保存，但从 SQL / 接口直接写就容易漏，
              所以这里兜一层，让历史数据也能自愈。 */
        foreach (array_keys($show) as $id) {
            $cur = $id;
            for ($guard = 0; $guard < 100; $guard++) {
                $pid = (int) ($byId[$cur]['parent_id'] ?? 0);
                if ($pid <= 0 || !isset($byId[$pid]) || isset($show[$pid])) {
                    break;
                }
                $show[$pid] = true;
                $cur = $pid;
            }
        }

        $tree = [];
        foreach ($all as $p) {
            if ((int) $p['parent_id'] === 0 && isset($show[(int) $p['id']])) {
                $p['children'] = [];
                $tree[$p['id']] = $p;
            }
        }
        foreach ($all as $p) {
            $pid = (int) $p['parent_id'];
            if ($pid > 0 && isset($tree[$pid]) && isset($show[(int) $p['id']])) {
                $tree[$pid]['children'][] = $p;
            }
        }
        Response::ok(array_values($tree));
    }
}
