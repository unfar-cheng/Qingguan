<?php
namespace QG;

use PDO;

class Tenant
{
    private static ?array $ctx = null;
    private static array $pools = [];

    public static function set(?array $ctx): void
    {
        self::$ctx = $ctx;
    }

    public static function ctx(): ?array
    {
        return self::$ctx;
    }

    public static function id(): int
    {
        return (int) (self::$ctx['id'] ?? 0);
    }

    public static function mode(): string
    {
        return self::$ctx['mode'] ?? 'shared';
    }

    public static function requireId(): int
    {
        $id = self::id();
        if ($id <= 0) {
            Response::fail('缺少租户上下文', 403, 403);
        }
        return $id;
    }

    /** 当前租户的数据面连接（共享档→共享库+共享账号；专用档→各自库+派生账号） */
    public static function pdo(): PDO
    {
        $ctx = self::$ctx;
        if (!$ctx) {
            Response::fail('缺少租户上下文', 403, 403);
        }
        $dedicated = ($ctx['mode'] ?? 'shared') === 'dedicated' && !empty($ctx['db_name']);
        if ($dedicated) {
            /* code 守卫在池判断之前：池命中也不能绕过——长驻进程复用上下文时，防线不被缓存短路 */
            $code = (string) ($ctx['code'] ?? '');
            if ($code === '') {
                throw new \RuntimeException('专用档租户上下文缺少 code，无法派生库账号');
            }
        }
        $dbName = $dedicated ? $ctx['db_name'] : App::config('db')['name'];
        if (!isset(self::$pools[$dbName])) {
            $c = App::config('db');
            if ($dedicated) {
                $user = DedicatedDb::user($code);
                $pass = DedicatedDb::pass($code);
            } else {
                $user = $c['user'];
                $pass = $c['pass'];
            }
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $dbName, $c['charset']);
            self::$pools[$dbName] = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pools[$dbName];
    }

    /** 测试用：清空连接池 */
    public static function resetPools(): void
    {
        self::$pools = [];
    }
}
