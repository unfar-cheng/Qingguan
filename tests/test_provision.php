<?php
require_once __DIR__ . '/bootstrap.php';

use QG\App;
use QG\ControlDb;
use QG\Tenant;
use QG\TenantProvisioner;

const PROV_TID = 9001;
const PROV_CODE = 'testprov9001';

function prov_cleanup(): void
{
    // 数据面（共享库）清 9001 的种子与账号
    Tenant::set(['id' => PROV_TID, 'mode' => 'shared', 'db_name' => '']);
    $pdo = Tenant::pdo();
    foreach (['sys_user', 'sys_role_permission', 'sys_role', 'sys_permission', 'sys_config', 'sys_dict', 'sys_log'] as $tb) {
        $pdo->exec("DELETE FROM {$tb} WHERE tenant_id = " . PROV_TID);
    }
    Tenant::set(null);
    // 控制面清租户与审计
    ControlDb::exec('DELETE FROM saas_audit WHERE target_type = ? AND target_id = ?', ['tenant', PROV_TID]);
    ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [PROV_TID]);
}

function prov_make_tenant(): array
{
    ControlDb::exec('DELETE FROM saas_tenant WHERE code = ?', [PROV_CODE]);
    $id = ControlDb::insert('saas_tenant', [
        'code' => PROV_CODE, 'name' => '开通测试企业', 'mode' => 'shared',
        'plan_id' => null, 'status' => 'pending',
        'contact_name' => '测试', 'contact_phone' => '13800000000',
        'admin_username' => 'boss9001',
        'admin_password_hash' => password_hash('Prov@12345', PASSWORD_DEFAULT),
        'applied_at' => App::now(),
    ]);
    // 固定住 id，便于重复跑用例
    ControlDb::exec('UPDATE saas_tenant SET id = ? WHERE id = ?', [PROV_TID, $id]);
    return ControlDb::one('SELECT * FROM saas_tenant WHERE id = ?', [PROV_TID]);
}

t('开通：播种角色/权限/配置/字典并创建管理员，状态置 active', function () {
    prov_cleanup();
    $t = prov_make_tenant();
    TenantProvisioner::provision($t, null);
    Tenant::set(['id' => PROV_TID, 'mode' => 'shared', 'db_name' => '']);
    $pdo = Tenant::pdo();
    $st = fn($sql) => $pdo->query($sql)->fetchColumn();
    assert_true((int) $st('SELECT COUNT(*) FROM sys_role WHERE tenant_id = ' . PROV_TID) >= 3, '应播种 3 个角色');
    assert_true((int) $st('SELECT COUNT(*) FROM sys_permission WHERE tenant_id = ' . PROV_TID) >= 20, '应播种权限树');
    assert_true((int) $st('SELECT COUNT(*) FROM sys_config WHERE tenant_id = ' . PROV_TID) >= 17, '应播种配置');
    assert_true((int) $st('SELECT COUNT(*) FROM sys_dict WHERE tenant_id = ' . PROV_TID) > 0, '应播种字典');
    $u = $pdo->query('SELECT u.*, r.code AS role_code FROM sys_user u LEFT JOIN sys_role r ON r.id = u.role_id WHERE u.tenant_id = ' . PROV_TID . " AND u.username = 'boss9001'")->fetch();
    assert_true((bool) $u, '应创建管理员');
    assert_eq('tenant_admin', $u['role_code'] ?? '', '管理员应挂 tenant_admin 角色');
    assert_true(password_verify('Prov@12345', $u['password_hash']), '密码哈希应可验证');
    Tenant::set(null);
    $after = ControlDb::one('SELECT * FROM saas_tenant WHERE id = ?', [PROV_TID]);
    assert_eq('active', $after['status'], '开通后应 active');
    assert_eq('', $after['admin_password_hash'], '开通后密码哈希应清空');
    prov_cleanup();
});

t('开通幂等：重复执行不产生重复数据', function () {
    prov_cleanup();
    $t = prov_make_tenant();
    TenantProvisioner::provision($t, null);
    TenantProvisioner::provision(ControlDb::one('SELECT * FROM saas_tenant WHERE id = ?', [PROV_TID]), null);
    Tenant::set(['id' => PROV_TID, 'mode' => 'shared', 'db_name' => '']);
    $pdo = Tenant::pdo();
    assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM sys_user WHERE tenant_id = ' . PROV_TID)->fetchColumn(), '管理员不应重复');
    assert_eq(3, (int) $pdo->query('SELECT COUNT(*) FROM sys_role WHERE tenant_id = ' . PROV_TID)->fetchColumn(), '角色不应重复');
    Tenant::set(null);
    prov_cleanup();
});

t('专用档本期拒绝开通', function () {
    prov_cleanup();
    $t = prov_make_tenant();
    ControlDb::exec("UPDATE saas_tenant SET mode = 'dedicated' WHERE id = ?", [PROV_TID]);
    $t = ControlDb::one('SELECT * FROM saas_tenant WHERE id = ?', [PROV_TID]);
    $msg = '';
    try {
        TenantProvisioner::provision($t, null);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
    }
    assert_contains($msg, '专用档', '应明确拒绝专用档');
    $after = ControlDb::one('SELECT status FROM saas_tenant WHERE id = ?', [PROV_TID]);
    assert_eq('pending', $after['status'], '失败后状态应保持 pending');
    prov_cleanup();
});
