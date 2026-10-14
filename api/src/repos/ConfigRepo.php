<?php
namespace QG\repos;

use QG\Db;
use QG\Tenant;

class ConfigRepo
{
    public static function list(): array
    {
        return Db::all('SELECT * FROM sys_config WHERE tenant_id = ? ORDER BY id', [Tenant::requireId()]);
    }

    /** 键不存在则新建、存在则覆盖值（对齐 SystemController::configSave） */
    public static function save(array $map): void
    {
        $tid = Tenant::requireId();
        foreach ($map as $k => $v) {
            $k = (string) $k;
            if ($k === '') {
                continue;
            }
            $val = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            if (Db::val('SELECT id FROM sys_config WHERE tenant_id = ? AND cfg_key = ?', [$tid, $k])) {
                Db::update('sys_config', ['cfg_value' => $val], 'tenant_id = ? AND cfg_key = ?', [$tid, $k]);
            } else {
                Db::insert('sys_config', ['tenant_id' => $tid, 'group_key' => 'basic', 'cfg_key' => $k, 'cfg_value' => $val]);
            }
        }
    }
}
