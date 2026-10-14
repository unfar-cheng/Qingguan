<?php
namespace QG;

use PDO;
use PDOException;

class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $c = App::config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
        try {
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            Response::fail('数据库连接失败：' . $e->getMessage(), 500);
        }
        return self::$pdo;
    }

    public static function all(string $sql, array $bind = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($bind);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $bind = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($bind);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $bind = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($bind);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $bind = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($bind);
        return $st->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $st   = self::pdo()->prepare($sql);
        $st->execute(array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $bind = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $k) {
            $sets[] = '`' . $k . '`=?';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE ' . $where;
        $st  = self::pdo()->prepare($sql);
        $st->execute(array_merge(array_values($data), $bind));
        return $st->rowCount();
    }

    public static function begin(): void   { self::pdo()->beginTransaction(); }
    public static function commit(): void  { self::pdo()->commit(); }
    public static function rollback(): void { if (self::pdo()->inTransaction()) { self::pdo()->rollBack(); } }
}
