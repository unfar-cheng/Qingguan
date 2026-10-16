<?php
namespace QG\repos;

use QG\App;
use QG\Db;
use QG\Tenant;

class UserRepo
{
    /** 员工列表（对齐 SystemController::staffList，补 tenant_id） */
    public static function page(string $keyword, string $status, int $page, int $size, int $offset): array
    {
        $tid   = Tenant::requireId();
        $where = ['u.is_deleted = 0'];
        $bind  = [$tid];
        if ($keyword !== '') {
            $where[] = '(u.username LIKE ? OR u.real_name LIKE ? OR u.phone LIKE ?)';
            array_push($bind, "%{$keyword}%", "%{$keyword}%", "%{$keyword}%");
        }
        if ($status !== '') {
            $where[] = 'u.status = ?';
            $bind[]  = (int) $status;
        }
        $w     = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM sys_user u WHERE u.tenant_id = ? AND {$w}", $bind);
        $rows  = Db::all(
            "SELECT u.id, u.tenant_id, u.username, u.real_name, u.phone, u.email, u.dept, u.role_id, u.status,
                    u.last_login_at, u.last_login_ip, u.created_at, r.name AS role_name
               FROM sys_user u
               LEFT JOIN sys_role r ON r.id = u.role_id AND r.tenant_id = u.tenant_id
              WHERE u.tenant_id = ? AND {$w} ORDER BY u.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        return [$rows, $total];
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM sys_user WHERE tenant_id = ? AND id = ? AND is_deleted = 0',
            [Tenant::requireId(), $id]);
    }

    public static function exists(int $id): bool
    {
        return (bool) Db::val('SELECT id FROM sys_user WHERE tenant_id = ? AND id = ? AND is_deleted = 0',
            [Tenant::requireId(), $id]);
    }

    /** 登录用：按账号取用户（含停用/未删除判定交由调用方） */
    public static function findByUsername(string $username): ?array
    {
        return Db::one('SELECT * FROM sys_user WHERE tenant_id = ? AND username = ? AND is_deleted = 0 LIMIT 1',
            [Tenant::requireId(), $username]);
    }

    /** 会话解析用：带角色名与角色标识 */
    public static function findWithRole(int $id): ?array
    {
        return Db::one(
            'SELECT u.*, r.name AS role_name, r.code AS role_code
               FROM sys_user u
               LEFT JOIN sys_role r ON r.id = u.role_id AND r.tenant_id = u.tenant_id
              WHERE u.tenant_id = ? AND u.id = ? AND u.is_deleted = 0 LIMIT 1',
            [Tenant::requireId(), $id]
        );
    }

    public static function passwordHash(int $id): ?string
    {
        $v = Db::val('SELECT password_hash FROM sys_user WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
        return $v === null ? null : (string) $v;
    }

    public static function create(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('sys_user', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Db::update('sys_user', $data, 'tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    /** 记录一次登录时间/IP */
    public static function touchLogin(int $id): int
    {
        return Db::update('sys_user', ['last_login_at' => App::now(), 'last_login_ip' => App::ip()],
            'tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    public static function setPassword(int $id, string $hash): int
    {
        return Db::update('sys_user', ['password_hash' => $hash], 'tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    /** 改密成功后清零首登强改标记 */
    public static function clearMustChangePassword(int $id): int
    {
        return Db::exec('UPDATE sys_user SET must_change_password = 0 WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    /** 软删：同时停用（对齐 SystemController::staffDelete） */
    public static function softDelete(int $id): int
    {
        return Db::exec('UPDATE sys_user SET is_deleted = 1, status = 0 WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    /** 账号查重；$excludeId > 0 时排除自身 */
    public static function existsUsername(string $username, int $excludeId = 0): bool
    {
        $sql  = 'SELECT id FROM sys_user WHERE tenant_id = ? AND username = ?';
        $bind = [Tenant::requireId(), $username];
        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $bind[] = $excludeId;
        }
        return (bool) Db::val($sql, $bind);
    }

    /** 某角色下的在用员工数（角色列表 / 删除前置校验） */
    public static function countByRole(int $roleId): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM sys_user WHERE tenant_id = ? AND role_id = ? AND is_deleted = 0',
            [Tenant::requireId(), $roleId]);
    }
}
