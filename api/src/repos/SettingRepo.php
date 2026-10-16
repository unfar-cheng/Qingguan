<?php
namespace QG\repos;

use QG\ControlDb;

/** 平台设置（控制面 saas_setting 键值）。键以 PlatformController::SETTING_KEYS 白名单为准。 */
class SettingRepo
{
    public static function get(string $key): ?string
    {
        $v = ControlDb::val('SELECT setting_value FROM saas_setting WHERE setting_key = ?', [$key]);
        return $v === null ? null : (string) $v;
    }

    /** @param string[] $keys */
    public static function all(array $keys): array
    {
        $out = array_fill_keys($keys, null);
        if (!$keys) { return $out; }
        $ph = implode(',', array_fill(0, count($keys), '?'));
        foreach (ControlDb::all(
            'SELECT setting_key, setting_value FROM saas_setting WHERE setting_key IN (' . $ph . ')',
            $keys
        ) as $r) {
            $out[$r['setting_key']] = (string) $r['setting_value'];
        }
        return $out;
    }

    /** 清空设置（回落代码侧兜底）；返回受影响行数 */
    public static function delete(string $key): int
    {
        return ControlDb::exec('DELETE FROM saas_setting WHERE setting_key = ?', [$key]);
    }

    public static function put(string $key, string $value, int $platformUserId): void
    {
        ControlDb::exec(
            'INSERT INTO saas_setting (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?',
            [$key, $value, $platformUserId, $value, $platformUserId]
        );
    }
}
