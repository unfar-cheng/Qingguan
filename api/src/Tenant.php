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

    /** 当前租户的数据面连接（共享档→共享库；专用档→各自库） */
    public static function pdo(): PDO
    {
        $ctx = self::$ctx;
        if (!$ctx) {
            Response::fail('缺少租户上下文', 403, 403);
        }
        $dbName = (($ctx['mode'] ?? 'shared') === 'dedicated' && !empty($ctx['db_name']))
            ? $ctx['db_name']
            : App::config('db')['name'];
        if (!isset(self::$pools[$dbName])) {
            $c   = App::config('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $dbName, $c['charset']);
            self::$pools[$dbName] = new PDO($dsn, $c['user'], $c['pass'], [
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
