<?php
namespace QG\repos;

use QG\Db;
use QG\Tenant;

class DictRepo
{
    /** 字典列表，$type 非空时按类型过滤 */
    public static function list(string $type): array
    {
        $tid  = Tenant::requireId();
        $sql  = 'SELECT * FROM sys_dict WHERE tenant_id = ?';
        $bind = [$tid];
        if ($type !== '') {
            $sql .= ' AND dict_type = ?';
            $bind[] = $type;
        }
        $sql .= ' ORDER BY dict_type, sort, id';
        return Db::all($sql, $bind);
    }

    /** (dict_type, dict_key) 查重；$excludeId > 0 时排除自身 */
    public static function keyExists(string $type, string $key, int $excludeId = 0): bool
    {
        $sql  = 'SELECT id FROM sys_dict WHERE tenant_id = ? AND dict_type = ? AND dict_key = ?';
        $bind = [Tenant::requireId(), $type, $key];
        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $bind[] = $excludeId;
        }
        return (bool) Db::val($sql, $bind);
    }

    public static function create(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('sys_dict', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Db::update('sys_dict', $data, 'tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    public static function delete(int $id): int
    {
        return Db::exec('DELETE FROM sys_dict WHERE tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }
}
