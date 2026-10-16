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
        // CREATE/ALTER/DROP/TRUNCATE/RENAME 等 DDL 会隐式提交当前事务——共享库上它们是
        // 空操作，但「隐式提交」本身照发不误（PHP 8.3 的 PDO::commit() 之后会报无活动事务）。
        // 所以先跑 DDL，再为种子 DML 重开一段事务，让调用方 begin/commit 真正兜住种子段。
        // 约定：schema.sql 的 DDL（含未来可能加入的 ALTER 等）必须全部位于种子数据之前；
        // 隐式提交语句只允许出现在重开事务之前。
        $hadTxn = $pdo->inTransaction();
        $ddl = [];
        $dml = [];
        foreach (self::splitStatements($sql) as $stmt) {
            if (preg_match('/^(CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', $stmt)) {
                $ddl[] = $stmt;
            } else {
                $dml[] = $stmt;
            }
        }
        $total = count($ddl) + count($dml);
        $done  = 0;
        self::execBatch($pdo, $ddl, $total, $done);
        if ($hadTxn && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        self::execBatch($pdo, $dml, $total, $done);
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
        if ($passwordHash === '') {
            throw new \RuntimeException('管理员密码哈希为空，拒绝建号（该租户需要平台重置后重开）');
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

    /**
     * 去注释行后按分号切语句。schema.sql 必须遵守的约定（切分器按此假设工作）：
     *  1) 语句字符串值内不得含 ASCII 分号（切段依赖）；
     *  2) 行尾注释必须用 `-- `（带空格）形式，且注释文字自身不得含 ASCII 分号；
     *  3) 文件尾不留「纯注释 + 无分号」的段落——下面的空段跳过只是兜底，不是许可。
     * 只剥「-- 开头」的行注释，绝不剥行尾注释（如 biz_plan.plan_name 的
     * `COMMENT '方案名称，如 方案A--美森'`，naive 行尾剥离会剥坏字符串值）。
     * 公开给 scripts/migrate_dedicated.php 复用——迁移文件与 schema.sql 遵守同一套切分约定。
     */
    public static function splitStatements(string $sql): array
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
            if ($s === '' || preg_replace('/^\s*--.*$/m', '', $s) === '') {
                continue;   // 空段，或整段只是注释（文件尾注释块、`;   -- 行尾注释` 黏出的段首注释）
            }
            $stmts[] = $s;
        }
        return $stmts;
    }

    /** 顺序执行一批语句；失败时带全局序号与语句片段重抛，便于定位 schema.sql 里的坏段。 */
    private static function execBatch(\PDO $pdo, array $stmts, int $total, int &$done): void
    {
        foreach ($stmts as $stmt) {
            $done++;
            try {
                $pdo->exec($stmt);
            } catch (\PDOException $e) {
                throw new \RuntimeException(
                    sprintf('播种第 %d/%d 条失败：%s（原错误：%s）', $done, $total, mb_substr($stmt, 0, 80), $e->getMessage()),
                    0,
                    $e
                );
            }
        }
    }
}
