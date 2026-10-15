<?php
namespace QG\repos;

use QG\Db;
use QG\Tenant;

/** 开通播种：把 db/schema.sql 按目标 tenant_id 落到当前数据面连接，并创建租户管理员。 */
class ProvisionRepo
{
    /** 幂等执行 db/schema.sql（DDL IF NOT EXISTS + 种子 ON DUPLICATE KEY UPDATE） */
    public static function seedFromSchema(int $tid): void
    {
        $file = dirname(__DIR__, 3) . '/db/schema.sql';
        $sql  = @file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException('读不到 db/schema.sql');
        }
        $pdo = Tenant::pdo();
        $pdo->exec('SET @tenant_id := ' . $tid);
        // DDL（CREATE TABLE IF NOT EXISTS）会隐式提交当前事务——共享库上它们是空操作，
        // 但「隐式提交」本身照发不误（PHP 8.3 的 PDO::commit() 之后会报无活动事务）。
        // 所以先跑 DDL，再为种子 DML 重开一段事务，让调用方 begin/commit 真正兜住种子段。
        $hadTxn = $pdo->inTransaction();
        $ddl = [];
        $dml = [];
        foreach (self::splitStatements($sql) as $stmt) {
            if (preg_match('/^CREATE\b/i', $stmt)) {
                $ddl[] = $stmt;
            } else {
                $dml[] = $stmt;
            }
        }
        foreach ($ddl as $stmt) {
            $pdo->exec($stmt);
        }
        if ($hadTxn && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        foreach ($dml as $stmt) {
            $pdo->exec($stmt);
        }
    }

    /** 幂等建管理员：已存在（uk_tenant_username）则跳过 */
    public static function ensureAdminUser(int $tid, string $username, string $passwordHash, string $realName): void
    {
        $roleId = Db::val('SELECT id FROM sys_role WHERE tenant_id = ? AND code = ? LIMIT 1', [$tid, 'tenant_admin']);
        if (!$roleId) {
            throw new \RuntimeException('角色播种缺失：tenant_admin（schema.sql 种子未生效）');
        }
        $exists = Db::val('SELECT id FROM sys_user WHERE tenant_id = ? AND username = ? LIMIT 1', [$tid, $username]);
        if ($exists) {
            return;
        }
        Db::insert('sys_user', [
            'tenant_id'     => $tid,
            'username'      => $username,
            'real_name'     => $realName !== '' ? $realName : $username,
            'password_hash' => $passwordHash,
            'role_id'       => (int) $roleId,
            'status'        => 1,
        ]);
    }

    /** 去注释行后按分号切语句。约定：schema.sql 字符串值内不得含 ASCII 分号。 */
    private static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\r?\n/', $sql);
        $kept  = [];
        foreach ($lines as $ln) {
            if (preg_match('/^\s*--/', $ln)) {
                continue;
            }
            $kept[] = $ln;
        }
        $stmts = [];
        foreach (explode(';', implode("\n", $kept)) as $s) {
            $s = trim($s);
            if ($s !== '') {
                $stmts[] = $s;
            }
        }
        return $stmts;
    }
}
