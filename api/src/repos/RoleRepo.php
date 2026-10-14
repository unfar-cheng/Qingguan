<?php
namespace QG\repos;

use QG\Db;
use QG\Tenant;

class RoleRepo
{
    /** 角色列表，附带各角色的权限 id 与在用员工数 */
    public static function list(): array
    {
        $tid  = Tenant::requireId();
        $rows = Db::all('SELECT * FROM sys_role WHERE tenant_id = ? ORDER BY id', [$tid]);
        foreach ($rows as &$r) {
            $r['permission_ids'] = self::permissionIds((int) $r['id']);
            $r['user_count']     = UserRepo::countByRole((int) $r['id']);
        }
        unset($r);
        return $rows;
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM sys_role WHERE tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    /** 角色标识查重；$excludeId > 0 时排除自身 */
    public static function codeExists(string $code, int $excludeId = 0): bool
    {
        $sql  = 'SELECT id FROM sys_role WHERE tenant_id = ? AND code = ?';
        $bind = [Tenant::requireId(), $code];
        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $bind[] = $excludeId;
        }
        return (bool) Db::val($sql, $bind);
    }

    public static function create(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('sys_role', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Db::update('sys_role', $data, 'tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    /** 删除角色并清掉它的授权 */
    public static function delete(int $id): int
    {
        $tid = Tenant::requireId();
        Db::exec('DELETE FROM sys_role_permission WHERE tenant_id = ? AND role_id = ?', [$tid, $id]);
        return Db::exec('DELETE FROM sys_role WHERE tenant_id = ? AND id = ?', [$tid, $id]);
    }

    public static function permissionIds(int $roleId): array
    {
        $rows = Db::all('SELECT permission_id FROM sys_role_permission WHERE tenant_id = ? AND role_id = ?',
            [Tenant::requireId(), $roleId]);
        return array_map('intval', array_column($rows, 'permission_id'));
    }

    /**
     * 全量覆盖式写授权：先清本租户该角色的授权，再逐条写入。
     * 写之前自动补上祖先节点：菜单下发时要求父节点也在授权里（见 AuthController::menu()），
     * 否则子节点会被静默丢弃。前端 el-tree 勾选时半选的父节点会一并提交，
     * 但直接调接口 / 手写 SQL 很容易漏掉父节点，导致「授权了却看不到」——
     * 这里从接口层兜住，保证库里不会出现「有子授权、无父授权」的悬空记录。
     */
    public static function syncPermissions(int $roleId, array $permIds): void
    {
        $tid = Tenant::requireId();

        $ids = array_values(array_unique(array_filter(array_map('intval', $permIds), static function ($v) {
            return $v > 0;
        })));
        if ($ids) {
            $parents = PermissionRepo::parents();
            $add     = $ids;
            foreach ($ids as $id) {
                $cur = $id;
                for ($guard = 0; $guard < 100; $guard++) {
                    $pid = $parents[$cur] ?? 0;
                    if ($pid <= 0 || !isset($parents[$pid])) {
                        break;
                    }
                    $add[] = $pid;
                    $cur   = $pid;
                }
            }
            $ids = array_values(array_unique($add));
        }

        Db::exec('DELETE FROM sys_role_permission WHERE tenant_id = ? AND role_id = ?', [$tid, $roleId]);
        foreach ($ids as $pid) {
            Db::insert('sys_role_permission', ['tenant_id' => $tid, 'role_id' => $roleId, 'permission_id' => $pid]);
        }
    }
}
