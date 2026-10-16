<?php
namespace QG;

use QG\repos\ProvisionRepo;

/**
 * 租户开通（shared / dedicated 两档）：幂等。重复调用安全；任何一步失败抛异常并把租户留在 pending，
 * 由调用方（PlatformController）记 saas_audit 的 provision_failed。
 * 专用档不做自动回滚（绝不代码自动 DROP），半成品由重试的幂等性收编。
 */
class TenantProvisioner
{
    /** 双 host 覆盖 TCP、反解、socket 三种连接路径，派生账号在任一路径下都可用 */
    private const PROVISION_HOSTS = ['localhost', '127.0.0.1'];

    public static function provision(array $tenant, ?int $platformUserId): void
    {
        $tid = (int) ($tenant['id'] ?? 0);
        if ($tid <= 0) {
            throw new \RuntimeException('租户 id 非法');
        }
        $mode = (string) ($tenant['mode'] ?? 'shared');
        if (!in_array($mode, ['shared', 'dedicated'], true)) {
            throw new \RuntimeException('未知服务档位：' . $mode);
        }
        $dbName = $mode === 'dedicated' ? DedicatedDb::name((string) $tenant['code']) : '';
        $status = (string) ($tenant['status'] ?? '');
        if (!in_array($status, ['pending', 'active'], true)) {
            throw new \RuntimeException('当前状态不可开通：' . ($tenant['status'] ?? ''));
        }
        // 首次开通（pending）必须有账号与密码哈希；active 重放时哈希已在首次开通后清空，
        // 只要账号还在即可（管理员已存在，ensureAdminUser 会跳过）。
        if (empty($tenant['admin_username']) || ($status === 'pending' && empty($tenant['admin_password_hash']))) {
            throw new \RuntimeException('缺少管理员账号或密码哈希，无法开通');
        }

        if ($mode === 'shared') {
            Tenant::set(['id' => $tid, 'mode' => 'shared', 'db_name' => '']);
            self::seedInTxn($tenant, $tid);
        } else {
            self::provisionDedicated($tenant, $tid);
        }

        // 状态守卫：UPDATE 只在 status='pending' 时命中。0 行说明期间被 reject/并发改写——
        // 已是 active 视为幂等达成；否则抛异常走 provision_failed，绝不把「已驳回」的租户翻成 active。
        // 专用档同时回写 db_name（失败重试时它是空的，成功才落）。
        if ($mode === 'shared') {
            $n = ControlDb::exec(
                "UPDATE saas_tenant SET status = 'active', reviewed_at = NOW(), reviewed_by = ?, admin_password_hash = '', reject_reason = '' WHERE id = ? AND status = 'pending'",
                [$platformUserId, $tid]
            );
        } else {
            $n = ControlDb::exec(
                "UPDATE saas_tenant SET status = 'active', db_name = ?, reviewed_at = NOW(), reviewed_by = ?, admin_password_hash = '', reject_reason = '' WHERE id = ? AND status = 'pending'",
                [$dbName, $platformUserId, $tid]
            );
        }
        if ($n === 0) {
            $now = ControlDb::one('SELECT status FROM saas_tenant WHERE id = ?', [$tid]);
            if (($now['status'] ?? '') !== 'active') {
                throw new \RuntimeException('租户状态已变化（现为 ' . ($now['status'] ?? '?') . '），开通中止');
            }
        }
        ControlDb::insert('saas_audit', [
            'platform_user_id' => $platformUserId,
            'action'           => 'approve',
            'target_type'      => 'tenant',
            'target_id'        => $tid,
            'detail'           => $mode === 'shared'
                ? '开通成功（共享档播种 + 管理员 ' . $tenant['admin_username'] . '）'
                : '开通成功（专用库 ' . $dbName . ' + 管理员 ' . $tenant['admin_username'] . '）',
            'ip'               => App::ip(),
        ]);
    }

    /**
     * 专用档：幂等 DDL（建库/建账号/授权，走 provision_db）→ 以租户自己的账号连专用库播种
     * （顺带验证账号与授权真实可用）。任何一步失败抛异常，租户留 pending，重新点「通过」即续跑。
     * 不做自动回滚：绝不代码自动 DROP，半成品由重试的幂等性收编。
     */
    private static function provisionDedicated(array $tenant, int $tid): void
    {
        $code = (string) ($tenant['code'] ?? '');
        $name = DedicatedDb::name($code);
        $user = DedicatedDb::user($code);
        $pass = DedicatedDb::pass($code);

        $pdo = DedicatedDb::provisionPdo();
        // $name/$user/$pass 均来自 DedicatedDb 的白名单派生（[a-z0-9-]），反引号内无注入面
        self::ddl($pdo, "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", '建库');
        foreach (self::PROVISION_HOSTS as $host) {
            // CREATE IF NOT EXISTS + ALTER IDENTIFIED BY：两步合一覆盖「新建」与「密码归位」，
            // 且是 MySQL 8.0 与 MariaDB 10.5 的共同子集（SET PASSWORD 的明文语义两边不一致，不用）
            self::ddl($pdo, "CREATE USER IF NOT EXISTS `{$user}`@`{$host}` IDENTIFIED BY '{$pass}'", '建账号+密码归位');
            self::ddl($pdo, "ALTER USER `{$user}`@`{$host}` IDENTIFIED BY '{$pass}'", '建账号+密码归位');
            self::ddl($pdo, "GRANT ALL PRIVILEGES ON `{$name}`.* TO `{$user}`@`{$host}`", '授权');
        }

        // 播种：Tenant::set 带 code，Tenant::pdo() 会用派生账号连专用库——连不上/授权不对在这里暴露
        Tenant::set(['id' => $tid, 'mode' => 'dedicated', 'db_name' => $name, 'code' => $code]);
        self::seedInTxn($tenant, $tid);
    }

    /**
     * 播种事务（shared / dedicated 共用）：以已设好的租户上下文执行 db/schema.sql 播种 + 建管理员；
     * 失败回滚并原样上抛；无论成败，结束都清空租户上下文。
     */
    private static function seedInTxn(array $tenant, int $tid): void
    {
        try {
            Db::begin();
            try {
                ProvisionRepo::seedFromSchema($tid);
                ProvisionRepo::ensureAdminUser($tid, $tenant['admin_username'], $tenant['admin_password_hash'], (string) ($tenant['name'] ?? ''));
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        } finally {
            Tenant::set(null);
        }
    }

    /** 执行一条开通 DDL；失败时消息带步骤定位（建库/建账号/授权），排障看审计即知挂在哪一步 */
    private static function ddl(\PDO $pdo, string $sql, string $what): void
    {
        try {
            $pdo->exec($sql);
        } catch (\Throwable $e) {
            throw new \RuntimeException("专用档 DDL 失败（{$what}）：" . $e->getMessage(), 0, $e);
        }
    }
}
