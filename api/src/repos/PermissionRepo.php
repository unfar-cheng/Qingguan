<?php
namespace QG\repos;

use QG\Db;
use QG\Tenant;

class PermissionRepo
{
    /** 权限节点全量（权限树用，顺序即展示顺序） */
    public static function all(): array
    {
        return Db::all('SELECT id, parent_id, name, code, type, path, icon, sort, status
                          FROM sys_permission WHERE tenant_id = ? ORDER BY sort, id',
            [Tenant::requireId()]);
    }

    /** 左侧菜单用：启用的菜单节点（type=1） */
    public static function menuList(): array
    {
        return Db::all('SELECT id, parent_id, name, code, type, path, icon, sort
                          FROM sys_permission WHERE tenant_id = ? AND status = 1 AND type = 1 ORDER BY sort, id',
            [Tenant::requireId()]);
    }

    /** id => parent_id 映射（授权与菜单的祖先补齐用） */
    public static function parents(): array
    {
        $rows = Db::all('SELECT id, parent_id FROM sys_permission WHERE tenant_id = ?', [Tenant::requireId()]);
        $map  = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = (int) $row['parent_id'];
        }
        return $map;
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM sys_permission WHERE tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    public static function exists(int $id): bool
    {
        return (bool) Db::val('SELECT id FROM sys_permission WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    public static function parentId(int $id): int
    {
        return (int) Db::val('SELECT parent_id FROM sys_permission WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    /** 标识占用者（用于给出「已被 xx 占用」的人话提示）；$excludeId > 0 时排除自身 */
    public static function codeOwner(string $code, int $excludeId = 0): ?array
    {
        return Db::one('SELECT name FROM sys_permission WHERE tenant_id = ? AND code = ? AND id <> ? LIMIT 1',
            [Tenant::requireId(), $code, $excludeId]);
    }

    /** 同一页面路径的占用者；$excludeId > 0 时排除自身 */
    public static function pathOwner(string $path, int $excludeId = 0): ?array
    {
        return Db::one('SELECT name FROM sys_permission WHERE tenant_id = ? AND path = ? AND id <> ? LIMIT 1',
            [Tenant::requireId(), $path, $excludeId]);
    }

    public static function childCount(int $parentId): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM sys_permission WHERE tenant_id = ? AND parent_id = ?',
            [Tenant::requireId(), $parentId]);
    }

    /** 该节点被多少个角色授权引用 */
    public static function roleLinkCount(int $permissionId): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM sys_role_permission WHERE tenant_id = ? AND permission_id = ?',
            [Tenant::requireId(), $permissionId]);
    }

    public static function create(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('sys_permission', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Db::update('sys_permission', $data, 'tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    /** 删除节点，并把各角色对它的授权一并清掉 */
    public static function delete(int $id): int
    {
        $tid = Tenant::requireId();
        Db::exec('DELETE FROM sys_role_permission WHERE tenant_id = ? AND permission_id = ?', [$tid, $id]);
        return Db::exec('DELETE FROM sys_permission WHERE tenant_id = ? AND id = ?', [$tid, $id]);
    }

    /** 某角色被授权的权限 code（超管由调用方短路返回 '*'） */
    public static function codesForRole(int $roleId): array
    {
        $rows = Db::all(
            'SELECT p.code FROM sys_permission p
               JOIN sys_role_permission rp ON rp.permission_id = p.id AND rp.tenant_id = p.tenant_id
              WHERE p.tenant_id = ? AND rp.role_id = ? AND p.status = 1',
            [Tenant::requireId(), $roleId]
        );
        return array_column($rows, 'code');
    }
}
