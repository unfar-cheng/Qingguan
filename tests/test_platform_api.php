<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/bootstrap.php';

use QG\App;
use QG\ControlDb;

// 注意：本函数消耗 platform-login 限流额度（10 次/10 分钟）；tests/bootstrap.php 会在套件启动时自动清桶，
// 但同一次套件内多次 platform_jar() 仍共享 10 次额度。
function platform_jar(): ?string
{
    $jar = tempnam(sys_get_temp_dir(), 'qgpf');
    $r = qg_post_json('/api/platform/login', ['username' => 'platform', 'password' => 'Platform@12345'], ['-c', $jar]);
    if (strpos($r, '"code":0') === false) {
        @unlink($jar);
        // 区分「未播种」（静默跳过）与「播种了但登录失败」（必须红，不能被跳过掩盖）
        $n = (int) ControlDb::val('SELECT COUNT(*) FROM saas_platform_user WHERE username = ? AND status = 1', ['platform']);
        if ($n === 0) {
            return null; // 未播种平台超管
        }
        assert_true(false, '平台超管已播种但登录失败：' . $r);
    }
    return $jar;
}

t('平台登录成功且 /platform/me 返回 scope=platform', function () {
    if (!qg_site_up()) { return; }
    $jar = platform_jar();
    if (!$jar) { echo "      平台超管未播种，跳过（先跑 scripts/seed_platform_admin.php）\n"; return; }
    $me = qg_curl('/api/platform/me', ['-b', $jar]);
    assert_contains($me, '"scope":"platform"', '/platform/me 应带平台作用域：' . $me);
    @unlink($jar);
});

t('租户令牌调平台接口被 403', function () {
    if (!qg_site_up()) { return; }
    $jar = tempnam(sys_get_temp_dir(), 'qgck');
    qg_post_json('/api/auth/login', ['tenant' => 'demo', 'username' => 'admin', 'password' => 'admin123'], ['-c', $jar]);
    $r = qg_curl('/api/platform/tenants', ['-b', $jar]);
    assert_contains($r, '需要平台管理员权限', '租户令牌必须被平台接口拒绝：' . $r);
    $r2 = qg_post_json('/api/platform/logout', [], ['-b', $jar]);
    assert_contains($r2, '需要平台管理员权限', '租户令牌调平台 logout 也必须被拒：' . $r2);
    @unlink($jar);
});

t('未登录调平台接口被 401', function () {
    if (!qg_site_up()) { return; }
    $r = qg_curl('/api/platform/tenants');
    assert_contains($r, '"code":401', '未登录应 401：' . $r);
});

t('状态机守卫：rejected 租户 approve/reject/suspend/enable 全部被拒', function () {
    if (!qg_site_up()) { return; }
    $jar = platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $tid = 999901;
    try {
        ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
        ControlDb::exec("INSERT INTO saas_tenant (id, code, name, mode, status, admin_username, applied_at) VALUES (?, 'sm999901', '状态机冒烟', 'shared', 'rejected', 'boss', NOW())", [$tid]);
        $expect = ['approve' => '当前状态不可审核通过', 'reject' => '仅待审核的租户可驳回', 'suspend' => '仅已开通的租户可停用', 'enable' => '仅已停用的租户可重新启用'];
        foreach ($expect as $action => $msg) {
            $body = $action === 'reject' ? ['reason' => 'x'] : [];
            $r = qg_post_json('/api/platform/tenants/' . $tid . '/' . $action, $body, ['-b', $jar]);
            assert_contains($r, $msg, "{$action} 应被状态机拒绝：{$r}");
        }
        $row = ControlDb::one('SELECT status FROM saas_tenant WHERE id = ?', [$tid]);
        assert_eq('rejected', $row['status'], '状态不应被改动');
    } finally {
        ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
        ControlDb::exec("DELETE FROM saas_audit WHERE target_type = 'tenant' AND target_id = ?", [$tid]);
    }
    @unlink($jar);
});

t('reject 参数校验：空原因 / 超 200 字被拒，合法原因落库', function () {
    if (!qg_site_up()) { return; }
    $jar = platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $tid = 999902;
    try {
        ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
        ControlDb::exec("INSERT INTO saas_tenant (id, code, name, mode, status, admin_username, applied_at) VALUES (?, 'sm999902', '驳回校验冒烟', 'shared', 'pending', 'boss2', NOW())", [$tid]);
        $r = qg_post_json('/api/platform/tenants/' . $tid . '/reject', [], ['-b', $jar]);
        assert_contains($r, '驳回原因不能为空', '空原因应被拒：' . $r);
        $r = qg_post_json('/api/platform/tenants/' . $tid . '/reject', ['reason' => str_repeat('超', 201)], ['-b', $jar]);
        assert_contains($r, '不能超过 200 字', '超长原因应被拒：' . $r);
        $row = ControlDb::one('SELECT status, reject_reason, reviewed_by FROM saas_tenant WHERE id = ?', [$tid]);
        assert_eq('pending', $row['status'], '两次失败后状态不应变');
        $r = qg_post_json('/api/platform/tenants/' . $tid . '/reject', ['reason' => '资料不齐全'], ['-b', $jar]);
        assert_contains($r, '"code":0', '合法原因应通过：' . $r);
        $row = ControlDb::one('SELECT status, reject_reason, reviewed_by FROM saas_tenant WHERE id = ?', [$tid]);
        assert_eq('rejected', $row['status'], '合法 reject 后应 rejected');
        assert_eq('资料不齐全', $row['reject_reason'], '驳回原因应落库');
        assert_true((int) $row['reviewed_by'] > 0, 'reviewed_by 应记录审核人');
    } finally {
        ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
        ControlDb::exec("DELETE FROM saas_audit WHERE target_type = 'tenant' AND target_id = ?", [$tid]);
    }
    @unlink($jar);
});

t('tenants 列表不泄露敏感字段', function () {
    if (!qg_site_up()) { return; }
    $jar = platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $tid = 999903;
    try {
        ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
        ControlDb::exec("INSERT INTO saas_tenant (id, code, name, mode, status, db_name, admin_username, applied_at) VALUES (?, 'sm999903', '库名展示冒烟', 'dedicated', 'active', 'qingguan_t_sm999903', 'boss', NOW())", [$tid]);
        $r = qg_curl('/api/platform/tenants?pageSize=500', ['-b', $jar]);
        assert_contains($r, '"code":0', '平台列表应可访问：' . $r);
        assert_true(strpos($r, 'password_hash') === false, '列表不得出现 password_hash：' . $r);
        // db_name 自专用档方案（spec 2026-10-15 前端节：dedicated 行展示库名）起是平台列表的合法字段，
        // 旧断言「不得出现 db_name」过时；自播种合成专用档租户断言精确值，不依赖库里恰好有专用档行。
        assert_true(strpos($r, '"db_name":"qingguan_t_sm999903"') !== false, '列表应带出专用档 db_name 精确值：' . $r);
    } finally {
        ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
        ControlDb::exec("DELETE FROM saas_audit WHERE target_type = 'tenant' AND target_id = ?", [$tid]);
    }
    @unlink($jar);
});

t('审核端到端：注册→approve→新租户管理员可登录→重复 approve 幂等', function () {
    if (!qg_site_up()) { return; }
    $jar = platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $code = 'e2e' . substr(md5(uniqid()), 0, 8);
    // 注册
    $r = qg_post_json('/api/tenant/register', [
        'name' => '端到端测试企业', 'code' => $code, 'contact_name' => '测试',
        'contact_phone' => '13800000000', 'admin_username' => 'boss',
        'password' => 'E2ePass123', 'plan_id' => 1,
    ]);
    assert_contains($r, '"code":0', '注册应成功：' . $r);
    $tid = (int) ControlDb::val('SELECT id FROM saas_tenant WHERE code = ?', [$code]);
    assert_true($tid > 0, '控制面应有 pending 租户');
    // approve
    $r = qg_post_json('/api/platform/tenants/' . $tid . '/approve', [], ['-b', $jar]);
    assert_contains($r, '"code":0', 'approve 应成功：' . $r);
    // 重复 approve 幂等
    $r = qg_post_json('/api/platform/tenants/' . $tid . '/approve', [], ['-b', $jar]);
    assert_contains($r, '"code":0', '重复 approve 应幂等成功：' . $r);
    // 状态机回归：开通后驳回必须被拒（approve/reject 互斥，任务 4 质量审查 I1 的配套用例）
    $r = qg_post_json('/api/platform/tenants/' . $tid . '/reject', ['reason' => '不该成功'], ['-b', $jar]);
    assert_contains($r, '仅待审核的租户可驳回', 'active 租户不可驳回：' . $r);
    // 新租户管理员可登录
    $jar2 = tempnam(sys_get_temp_dir(), 'qgck');
    $r = qg_post_json('/api/auth/login', ['tenant' => $code, 'username' => 'boss', 'password' => 'E2ePass123'], ['-c', $jar2]);
    assert_contains($r, '"code":0', '新租户管理员应能登录：' . $r);
    @unlink($jar2);
    // 清理
    $pdo = qg_test_pdo();
    foreach (['sys_user', 'sys_role_permission', 'sys_role', 'sys_permission', 'sys_config', 'sys_dict', 'sys_log'] as $tb) {
        $pdo->exec("DELETE FROM {$tb} WHERE tenant_id = " . $tid);
    }
    ControlDb::exec('DELETE FROM saas_audit WHERE target_type = ? AND target_id = ?', ['tenant', $tid]);
    ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
    @unlink($jar);
});
