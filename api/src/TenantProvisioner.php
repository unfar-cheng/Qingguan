<?php
namespace QG;

use QG\repos\ProvisionRepo;

/**
 * 租户开通：幂等。重复调用安全；任何一步失败抛异常并把租户留在 pending，
 * 由调用方（PlatformController）记 saas_audit 的 provision_failed。
 */
class TenantProvisioner
{
    public static function provision(array $tenant, ?int $platformUserId): void
    {
        $tid = (int) ($tenant['id'] ?? 0);
        if ($tid <= 0) {
            throw new \RuntimeException('租户 id 非法');
        }
        if (($tenant['mode'] ?? 'shared') !== 'shared') {
            throw new \RuntimeException('专用档（物理隔离）开通尚未开放，归二期');
        }
        $status = (string) ($tenant['status'] ?? '');
        if (!in_array($status, ['pending', 'active'], true)) {
            throw new \RuntimeException('当前状态不可开通：' . ($tenant['status'] ?? ''));
        }
        // 首次开通（pending）必须有账号与密码哈希；active 重放时哈希已在首次开通后清空，
        // 只要账号还在即可（管理员已存在，ensureAdminUser 会跳过）。
        if (empty($tenant['admin_username']) || ($status === 'pending' && empty($tenant['admin_password_hash']))) {
            throw new \RuntimeException('缺少管理员账号或密码哈希，无法开通');
        }

        Tenant::set(['id' => $tid, 'mode' => 'shared', 'db_name' => '']);
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

        // 状态守卫：UPDATE 只在 status='pending' 时命中。0 行说明期间被 reject/并发改写——
        // 已是 active 视为幂等达成；否则抛异常走 provision_failed，绝不把「已驳回」的租户翻成 active。
        $n = ControlDb::exec(
            "UPDATE saas_tenant SET status = 'active', reviewed_at = NOW(), reviewed_by = ?, admin_password_hash = '', reject_reason = '' WHERE id = ? AND status = 'pending'",
            [$platformUserId, $tid]
        );
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
            'detail'           => '开通成功（共享档播种 + 管理员 ' . $tenant['admin_username'] . '）',
            'ip'               => App::ip(),
        ]);
    }
}
