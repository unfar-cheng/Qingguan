<?php
namespace QG;

class App
{
    private static ?array $cfg = null;
    public static array $errors = [];

    public static function config(?string $key = null)
    {
        if (self::$cfg === null) {
            $file = dirname(__DIR__) . '/config/config.php';
            if (!is_file($file)) {
                Response::fail('缺少配置文件 api/config/config.php', 500);
            }
            self::$cfg = require $file;
        }
        return $key === null ? self::$cfg : (self::$cfg[$key] ?? null);
    }

    public static function secret(): string
    {
        $s = self::config('app')['secret'];
        return $s === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING' ? 'qg-default-secret-please-change' : $s;
    }

    public static function pageSize(): int
    {
        return (int) (self::config('app')['page_size'] ?: 50);
    }

    public static function ip(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = explode(',', $_SERVER[$k])[0];
                return trim($ip);
            }
        }
        return '0.0.0.0';
    }

    /** 真实来源 IP。本部署没有可信反代，绝不能用 X-Forwarded-For（可伪造）——限流与审计防伪用它。 */
    public static function remoteAddr(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function rand(int $len = 6): string
    {
        $s = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $r = '';
        for ($i = 0; $i < $len; $i++) {
            $r .= $s[random_int(0, strlen($s) - 1)];
        }
        return $r;
    }

    public static function log(string $module, string $action, $targetId = null, $detail = null): void
    {
        try {
            repos\LogRepo::write($module, $action, $targetId, $detail);
        } catch (\Throwable $e) {
        }
    }
}
