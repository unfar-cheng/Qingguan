<?php
namespace QG\controllers;

use QG\App;
use QG\Auth;
use QG\Db;
use QG\Req;
use QG\Response;
use QG\Router;

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
        $username = Req::require_('username', '账号');
        $password = Req::str('password');
        if ($password === '') {
            Response::fail('密码不能为空');
        }

        $u = Db::one('SELECT * FROM sys_user WHERE username = ? AND is_deleted = 0 LIMIT 1', [$username]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Response::fail('账号或密码错误');
        }
        if ((int) $u['status'] !== 1) {
            Response::fail('该账号已被停用，请联系管理员');
        }

        Db::update('sys_user', ['last_login_at' => App::now(), 'last_login_ip' => App::ip()], 'id = ?', [$u['id']]);
        Auth::login($u);
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
        $row = Db::one('SELECT password_hash FROM sys_user WHERE id = ?', [$u['id']]);
        if (!$row || !password_verify($old, $row['password_hash'])) {
            Response::fail('原密码不正确');
        }
        Db::update('sys_user', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
        App::log('auth', 'password', $u['id']);
        Response::ok(null, '密码已修改');
    }

    public static function menu(): void
    {
        Auth::requireLogin();
        $codes = Auth::permissionCodes();
        $all   = Db::all('SELECT id, parent_id, name, code, type, path, icon, sort FROM sys_permission WHERE status = 1 AND type = 1 ORDER BY sort, id');

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
