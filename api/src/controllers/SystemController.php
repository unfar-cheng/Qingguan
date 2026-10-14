<?php
namespace QG\controllers;

use QG\App;
use QG\Auth;
use QG\Db;
use QG\Req;
use QG\Response;
use QG\Router;

class SystemController
{
    public static function routes(Router $r): void
    {
        $r->get('/staff', [self::class, 'staffList']);
        $r->post('/staff', [self::class, 'staffStore']);
        $r->put('/staff/{id}', [self::class, 'staffUpdate']);
        $r->del('/staff/{id}', [self::class, 'staffDelete']);
        $r->post('/staff/{id}/reset', [self::class, 'staffReset']);

        $r->get('/roles', [self::class, 'roleList']);
        $r->post('/roles', [self::class, 'roleStore']);
        $r->put('/roles/{id}', [self::class, 'roleUpdate']);
        $r->del('/roles/{id}', [self::class, 'roleDelete']);
        $r->put('/roles/{id}/permissions', [self::class, 'rolePermission']);
        $r->get('/permissions', [self::class, 'permissionTree']);
        $r->post('/permissions', [self::class, 'permissionStore']);
        $r->put('/permissions/{id}', [self::class, 'permissionUpdate']);
        $r->del('/permissions/{id}', [self::class, 'permissionDelete']);

        $r->get('/configs', [self::class, 'configList']);
        $r->put('/configs', [self::class, 'configSave']);

        $r->get('/dicts', [self::class, 'dictList']);
        $r->post('/dicts', [self::class, 'dictStore']);
        $r->put('/dicts/{id}', [self::class, 'dictUpdate']);
        $r->del('/dicts/{id}', [self::class, 'dictDelete']);

        $r->get('/logs', [self::class, 'logList']);
    }

    /* ---------------- 内部员工 ---------------- */

    public static function staffList(): void
    {
        Auth::requireLogin();
        $kw = Req::str('keyword');
        $where = ['u.is_deleted = 0'];
        $bind  = [];
        if ($kw !== '') {
            $where[] = '(u.username LIKE ? OR u.real_name LIKE ? OR u.phone LIKE ?)';
            array_push($bind, "%{$kw}%", "%{$kw}%", "%{$kw}%");
        }
        if (Req::str('status') !== '') {
            $where[] = 'u.status = ?';
            $bind[]  = Req::int('status');
        }
        [$page, $size, $offset] = Req::page();
        $w = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM sys_user u WHERE {$w}", $bind);
        $rows  = Db::all(
            "SELECT u.id, u.username, u.real_name, u.phone, u.email, u.dept, u.role_id, u.status,
                    u.last_login_at, u.last_login_ip, u.created_at, r.name AS role_name
               FROM sys_user u LEFT JOIN sys_role r ON r.id = u.role_id
              WHERE {$w} ORDER BY u.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        Response::page($rows, $total, $page, $size);
    }

    public static function staffStore(): void
    {
        Auth::requireLogin();
        $username = Req::require_('username', '账号');
        $real     = Req::require_('real_name', '姓名');
        $pwd      = Req::str('password') ?: '123456';
        if (strlen($pwd) < 6) {
            Response::fail('密码至少 6 位');
        }
        if (Db::val('SELECT id FROM sys_user WHERE username = ?', [$username])) {
            Response::fail('账号已存在');
        }
        $id = Db::insert('sys_user', [
            'username'      => $username,
            'real_name'     => $real,
            'password_hash' => password_hash($pwd, PASSWORD_DEFAULT),
            'phone'         => Req::str('phone'),
            'email'         => Req::str('email'),
            'dept'          => Req::str('dept'),
            'role_id'       => Req::int('role_id') ?: null,
            'status'        => Req::int('status', 1),
            'created_at'    => App::now(),
        ]);
        App::log('staff', 'create', $id, $username);
        Response::ok(['id' => $id], '已新增');
    }

    public static function staffUpdate(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        if (!Db::val('SELECT id FROM sys_user WHERE id = ? AND is_deleted = 0', [$id])) {
            Response::fail('员工不存在');
        }
        $data = [
            'real_name' => Req::require_('real_name', '姓名'),
            'phone'     => Req::str('phone'),
            'email'     => Req::str('email'),
            'dept'      => Req::str('dept'),
            'role_id'   => Req::int('role_id') ?: null,
            'status'    => Req::int('status', 1),
        ];
        $newName = Req::str('username');
        if ($newName !== '') {
            if (Db::val('SELECT id FROM sys_user WHERE username = ? AND id <> ?', [$newName, $id])) {
                Response::fail('账号已存在');
            }
            $data['username'] = $newName;
        }
        if ($id === Auth::id() && (int) $data['status'] !== 1) {
            Response::fail('不能停用自己');
        }
        Db::update('sys_user', $data, 'id = ?', [$id]);
        App::log('staff', 'update', $id, $data);
        Response::ok(null, '已保存');
    }

    public static function staffDelete(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        if ($id === Auth::id()) {
            Response::fail('不能删除自己');
        }
        Db::update('sys_user', ['is_deleted' => 1, 'status' => 0], 'id = ?', [$id]);
        App::log('staff', 'delete', $id);
        Response::ok(null, '已删除');
    }

    public static function staffReset(array $p): void
    {
        Auth::requireLogin();
        $id  = (int) $p['id'];
        $pwd = Req::str('password') ?: '123456';
        if (strlen($pwd) < 6) {
            Response::fail('密码至少 6 位');
        }
        Db::update('sys_user', ['password_hash' => password_hash($pwd, PASSWORD_DEFAULT)], 'id = ?', [$id]);
        App::log('staff', 'reset', $id);
        Response::ok(null, '密码已重置');
    }

    /* ---------------- 角色 / 权限 ---------------- */

    public static function roleList(): void
    {
        Auth::requireLogin();
        $rows = Db::all('SELECT * FROM sys_role ORDER BY id');
        foreach ($rows as &$r) {
            $r['permission_ids'] = array_map('intval', array_column(
                Db::all('SELECT permission_id FROM sys_role_permission WHERE role_id = ?', [(int) $r['id']]),
                'permission_id'
            ));
            $r['user_count'] = (int) Db::val('SELECT COUNT(*) FROM sys_user WHERE role_id = ? AND is_deleted = 0', [(int) $r['id']]);
        }
        unset($r);
        Response::ok($rows);
    }

    public static function roleStore(): void
    {
        Auth::requireLogin();
        $name = Req::require_('name', '角色名称');
        $code = Req::require_('code', '角色标识');
        if (Db::val('SELECT id FROM sys_role WHERE code = ?', [$code])) {
            Response::fail('角色标识已存在');
        }
        $id = Db::insert('sys_role', ['name' => $name, 'code' => $code, 'remark' => Req::str('remark'), 'status' => 1, 'created_at' => App::now()]);
        self::syncRolePermission($id, Req::arr('permission_ids'));
        App::log('role', 'create', $id, $name);
        Response::ok(['id' => $id], '已新增');
    }

    public static function roleUpdate(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        $role = Db::one('SELECT * FROM sys_role WHERE id = ?', [$id]);
        if (!$role) {
            Response::fail('角色不存在');
        }
        Db::update('sys_role', [
            'name'   => Req::require_('name', '角色名称'),
            'remark' => Req::str('remark'),
            'status' => Req::int('status', 1),
        ], 'id = ?', [$id]);
        self::syncRolePermission($id, Req::arr('permission_ids'));
        App::log('role', 'update', $id);
        Response::ok(null, '已保存');
    }

    public static function rolePermission(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        self::syncRolePermission($id, Req::arr('permission_ids'));
        App::log('role', 'permission', $id);
        Response::ok(null, '权限已保存');
    }

    private static function syncRolePermission(int $roleId, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, static function ($v) { return $v > 0; }));

        /* 自动补上祖先节点：菜单下发时要求父节点也在授权里（见 AuthController::menu()），
           否则子节点会被静默丢弃。前端 el-tree 勾选时半选的父节点会一并提交，
           但直接调接口 / 手写 SQL 很容易漏掉父节点，导致「授权了却看不到」——
           这里从接口层兜住，保证库里不会出现「有子授权、无父授权」的悬空记录。 */
        if ($ids) {
            $parents = [];
            foreach (Db::all('SELECT id, parent_id FROM sys_permission') as $row) {
                $parents[(int) $row['id']] = (int) $row['parent_id'];
            }
            $add = $ids;
            foreach ($ids as $id) {
                $cur = $id;
                for ($guard = 0; $guard < 100; $guard++) {
                    $pid = $parents[$cur] ?? 0;
                    if ($pid <= 0 || !isset($parents[$pid])) {
                        break;
                    }
                    $add[] = $pid;
                    $cur = $pid;
                }
            }
            $ids = array_values(array_unique($add));
        }

        Db::exec('DELETE FROM sys_role_permission WHERE role_id = ?', [$roleId]);
        foreach ($ids as $pid) {
            Db::exec('INSERT IGNORE INTO sys_role_permission (role_id, permission_id) VALUES (?,?)', [$roleId, $pid]);
        }
    }

    public static function roleDelete(array $p): void
    {
        Auth::requireLogin();
        $id = (int) $p['id'];
        if (Db::val('SELECT code FROM sys_role WHERE id = ?', [$id]) === 'super') {
            Response::fail('超级管理员角色不能删除');
        }
        if (Db::val('SELECT COUNT(*) FROM sys_user WHERE role_id = ? AND is_deleted = 0', [$id])) {
            Response::fail('该角色下还有员工，不能删除');
        }
        Db::exec('DELETE FROM sys_role WHERE id = ?', [$id]);
        Db::exec('DELETE FROM sys_role_permission WHERE role_id = ?', [$id]);
        Response::ok(null, '已删除');
    }

    public static function permissionTree(): void
    {
        Auth::requireLogin();
        $all = Db::all('SELECT id, parent_id, name, code, type, path, icon, sort, status FROM sys_permission ORDER BY sort, id');

        /* ⚠️ 这里必须按**任意层级**递归组装，不能写成「只把 parent_id=0 的当父节点」那种两层写法。
           两类节点都是两层，加了 type=2 的按钮节点之后就有了第三层
           （顶级分组 → 二级菜单 → 按钮），两层写法会因为 isset($tree[$pid]) 不成立
           把按钮整批静默丢掉 —— 现象是「库里明明有按钮权限，权限页上看不到也勾不上」。
           ORDER BY sort, id 已保证同级顺序，$byParent 只是按父分组，不改顺序。 */
        $byParent = [];
        foreach ($all as $p) {
            $byParent[(int) $p['parent_id']][] = $p;
        }
        $build = static function (int $pid) use (&$build, $byParent): array {
            $out = [];
            foreach ($byParent[$pid] ?? [] as $n) {
                $n['children'] = $build((int) $n['id']);
                $out[] = $n;
            }
            return $out;
        };
        Response::ok($build(0));
    }

    /* ---------------- 菜单节点维护（增 / 改 / 删）----------------
       左侧菜单是 AuthController::menu() 从 sys_permission 里 type=1 的记录动态生成的，
       在这里加一条 → 刷新后台就能在菜单里看到入口。
       权限：统一要求拥有「权限管理」(system.role)，超管走 '*' 通配。 */

    public static function permissionStore(): void
    {
        Auth::requireLogin();
        $parent = Req::int('parent_id', 0);
        $code   = Req::require_('code', '权限标识');
        $path   = trim(Req::str('path'));
        self::assertPermissionInput($code, $parent, $path, 0);

        $id = Db::insert('sys_permission', [
            'parent_id' => $parent,
            'name'      => Req::require_('name', '菜单名称'),
            'code'      => $code,
            'type'      => Req::int('type', 1) === 2 ? 2 : 1,
            'path'      => $path,
            'icon'      => trim(Req::str('icon')),
            'sort'      => Req::int('sort', 0),
            'status'    => Req::int('status', 1) === 0 ? 0 : 1,
        ]);
        App::log('permission', 'create', $id, $code);
        Response::ok(['id' => $id], '已新增菜单节点，刷新页面即可在左侧看到');
    }

    public static function permissionUpdate(array $p): void
    {
        Auth::requireLogin();
        $id  = (int) $p['id'];
        $old = Db::one('SELECT id, name FROM sys_permission WHERE id = ?', [$id]);
        if (!$old) {
            Response::fail('菜单节点不存在，请刷新后重试');
        }

        $parent = Req::int('parent_id', 0);
        $code   = Req::require_('code', '权限标识');
        $path   = trim(Req::str('path'));
        self::assertPermissionInput($code, $parent, $path, $id);
        if ($parent > 0 && $parent !== $id && self::isDescendant($parent, $id)) {
            Response::fail('不能把「' . $old['name'] . '」挂到它自己的下级菜单里，会形成死循环');
        }

        Db::update('sys_permission', [
            'parent_id' => $parent,
            'name'      => Req::require_('name', '菜单名称'),
            'code'      => $code,
            'type'      => Req::int('type', 1) === 2 ? 2 : 1,
            'path'      => $path,
            'icon'      => trim(Req::str('icon')),
            'sort'      => Req::int('sort', 0),
            'status'    => Req::int('status', 1) === 0 ? 0 : 1,
        ], 'id = ?', [$id]);
        App::log('permission', 'update', $id, $code);
        Response::ok(null, '已保存，刷新页面即可生效');
    }

    public static function permissionDelete(array $p): void
    {
        Auth::requireLogin();
        $id  = (int) $p['id'];
        $row = Db::one('SELECT id, name FROM sys_permission WHERE id = ?', [$id]);
        if (!$row) {
            Response::fail('菜单节点不存在，请刷新后重试');
        }

        $child = (int) Db::val('SELECT COUNT(*) FROM sys_permission WHERE parent_id = ?', [$id]);
        if ($child > 0) {
            Response::fail('「' . $row['name'] . '」下面还有 ' . $child . ' 个下级菜单，请先删除下级');
        }

        $used = (int) Db::val('SELECT COUNT(*) FROM sys_role_permission WHERE permission_id = ?', [$id]);
        Db::exec('DELETE FROM sys_permission WHERE id = ?', [$id]);
        Db::exec('DELETE FROM sys_role_permission WHERE permission_id = ?', [$id]);
        App::log('permission', 'delete', $id, $row['name']);
        Response::ok(
            ['roles_affected' => $used],
            $used > 0 ? '已删除，并同时从 ' . $used . ' 个角色中移除了该权限' : '已删除'
        );
    }

    /** 菜单节点的公共校验：写权限、标识唯一、上级存在、页面路径不重复 */
    private static function assertPermissionInput(string $code, int $parent, string $path, int $excludeId): void
    {
        if (!Auth::can('system.role')) {
            Response::fail('没有「权限管理」权限，不能维护菜单节点');
        }
        if ($parent > 0 && $parent === $excludeId) {
            Response::fail('上级菜单不能选自己');
        }
        if ($parent > 0 && !Db::val('SELECT id FROM sys_permission WHERE id = ?', [$parent])) {
            Response::fail('上级菜单不存在（id=' . $parent . '），可能已被别人删掉了，请刷新后重试');
        }

        $hit = Db::one('SELECT name FROM sys_permission WHERE code = ? AND id <> ? LIMIT 1', [$code, $excludeId]);
        if ($hit) {
            Response::fail('权限标识「' . $code . '」已被「' . $hit['name'] . '」占用，换一个');
        }

        // 同一个页面路径挂两个菜单 → 左侧出现两个入口、且都去同一个页面，直接拦掉
        if ($path !== '') {
            $dup = Db::one('SELECT name FROM sys_permission WHERE path = ? AND id <> ? LIMIT 1', [$path, $excludeId]);
            if ($dup) {
                Response::fail('页面「' . $path . '」已经挂在菜单「' . $dup['name'] . '」下了，不能重复添加');
            }
        }
    }

    /** $maybeChild 是否位于 $rootId 的子孙链上（用于拦掉「把自己挂到自己下级」） */
    private static function isDescendant(int $maybeChild, int $rootId): bool
    {
        $cur   = $maybeChild;
        $guard = 0;
        while ($cur > 0 && $guard++ < 100) {
            if ($cur === $rootId) {
                return true;
            }
            $cur = (int) Db::val('SELECT parent_id FROM sys_permission WHERE id = ?', [$cur]);
        }
        return false;
    }

    /* ---------------- 系统配置（收发信息） ---------------- */

    public static function configList(): void
    {
        Auth::requireLogin();
        $rows = Db::all('SELECT * FROM sys_config ORDER BY id');
        $out  = [];
        foreach ($rows as $r) {
            $out[$r['cfg_key']] = $r['cfg_value'];
        }
        Response::ok(['items' => $rows, 'map' => $out]);
    }

    public static function configSave(): void
    {
        Auth::requireLogin();
        $map = Req::input('configs', []);
        if (!is_array($map)) {
            Response::fail('参数格式不正确');
        }
        foreach ($map as $k => $v) {
            $k = (string) $k;
            if ($k === '') {
                continue;
            }
            $exists = Db::val('SELECT id FROM sys_config WHERE cfg_key = ?', [$k]);
            if ($exists) {
                Db::update('sys_config', ['cfg_value' => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)], 'cfg_key = ?', [$k]);
            } else {
                Db::insert('sys_config', ['group_key' => 'basic', 'cfg_key' => $k, 'cfg_value' => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)]);
            }
        }
        App::log('config', 'save', null, array_keys($map));
        Response::ok(null, '已保存');
    }

    /* ---------------- 字典 ---------------- */

    public static function dictList(): void
    {
        Auth::requireLogin();
        $type = Req::str('type');
        $where = ['1=1'];
        $bind  = [];
        if ($type !== '') {
            $where[] = 'dict_type = ?';
            $bind[]  = $type;
        }
        Response::ok(Db::all('SELECT * FROM sys_dict WHERE ' . implode(' AND ', $where) . ' ORDER BY dict_type, sort, id', $bind));
    }

    public static function dictStore(): void
    {
        Auth::requireLogin();
        $type = Req::require_('dict_type', '字典类型');
        $key  = Req::require_('dict_key', '键');
        /* sys_dict 上有 (dict_type, dict_key) 唯一键，先查一次给出人话提示 */
        if (Db::val('SELECT id FROM sys_dict WHERE dict_type = ? AND dict_key = ?', [$type, $key])) {
            Response::fail('该类型下已存在键「' . $key . '」，请换个键或直接编辑已有项');
        }
        $id = Db::insert('sys_dict', [
            'dict_type'  => $type,
            'dict_key'   => $key,
            'dict_value' => Req::require_('dict_value', '值'),
            'sort'       => Req::int('sort'),
            'status'     => Req::int('status', 1),
        ]);
        Response::ok(['id' => $id], '已新增');
    }

    public static function dictUpdate(array $p): void
    {
        Auth::requireLogin();
        $type = Req::require_('dict_type', '字典类型');
        $key  = Req::require_('dict_key', '键');
        if (Db::val('SELECT id FROM sys_dict WHERE dict_type = ? AND dict_key = ? AND id <> ?', [$type, $key, (int) $p['id']])) {
            Response::fail('该类型下已存在键「' . $key . '」');
        }
        Db::update('sys_dict', [
            'dict_type'  => Req::require_('dict_type', '字典类型'),
            'dict_key'   => Req::require_('dict_key', '键'),
            'dict_value' => Req::require_('dict_value', '值'),
            'sort'       => Req::int('sort'),
            'status'     => Req::int('status', 1),
        ], 'id = ?', [(int) $p['id']]);
        Response::ok(null, '已保存');
    }

    public static function dictDelete(array $p): void
    {
        Auth::requireLogin();
        Db::exec('DELETE FROM sys_dict WHERE id = ?', [(int) $p['id']]);
        Response::ok(null, '已删除');
    }

    public static function logList(): void
    {
        Auth::requireLogin();
        [$page, $size, $offset] = Req::page();
        $rows = Db::all(
            "SELECT l.*, u.real_name AS operator_name FROM sys_log l
               LEFT JOIN sys_user u ON u.id = l.user_id
              ORDER BY l.id DESC LIMIT {$size} OFFSET {$offset}"
        );
        $total = (int) Db::val('SELECT COUNT(*) FROM sys_log');
        Response::page($rows, $total, $page, $size);
    }
}
