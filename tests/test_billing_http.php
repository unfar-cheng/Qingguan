<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/http.php';

use QG\ControlDb;
use QG\Db;
use QG\Tenant;

// 与 api/index.php 的 web 请求保持同一时区：分支 3（超宽限）要把响应里的 expires_at（web 墙钟）
// 与测试本地的 now 做秒级比较，时区不一致会差 8 小时（CLI php83 默认 UTC）。
date_default_timezone_set('Asia/Shanghai');

/**
 * 账单与服务期全链路 HTTP 用例（计划 2026-10-16-billing-expiry 任务 9）。
 * 覆盖：平台确认收款（pending 租户拒绝 / 三分支重算 / paid 幂等拒绝）、
 * 自助续费生成待付账单、超宽限登录拦截、租户账单横向隔离。
 * 合成号段 910001-910010（与 test_provision 的 9001、test_platform_api 的 9999xx 错开），
 * 每个用例自带造数（控制面直插 + 数据面播种管理员）与 finally 清理；
 * 清理一律按精确 tid 删，不得用区间条件（库里有 express/woniu 等真实租户）。
 */

function bh_tid(int $n): int
{
    return 910000 + $n;
}

/** 平台 jar：文件内只登录一次、后续复用（platform-login 限流 10 次/10 分钟，全套件共享） */
function bh_platform_jar(): ?string
{
    static $jar = false;
    if ($jar === false) {
        $jar = platform_jar();
    }
    return $jar;
}

function bh_login(string $tenant, string $username, string $password): ?string
{
    $jar = tempnam(sys_get_temp_dir(), 'qgck');
    $r = qg_post_json('/api/auth/login', ['tenant' => $tenant, 'username' => $username, 'password' => $password], ['-c', $jar]);
    if (strpos($r, '"code":0') === false) {
        @unlink($jar);
        return null;
    }
    return $jar;
}

/** 清一个合成租户的全部现场（数据面共享库 + 控制面）。只按精确 tid / code 删。 */
function bh_cleanup(int $n): void
{
    $tid = bh_tid($n);
    Tenant::set(['id' => $tid, 'mode' => 'shared', 'db_name' => '']);
    $pdo = Tenant::pdo();
    foreach (['sys_user', 'sys_role_permission', 'sys_role', 'sys_permission', 'sys_config', 'sys_dict', 'sys_log'] as $tb) {
        $pdo->exec('DELETE FROM ' . $tb . ' WHERE tenant_id = ' . $tid);
    }
    Tenant::set(null);
    ControlDb::exec('DELETE FROM saas_bill WHERE tenant_id = ?', [$tid]);
    ControlDb::exec("DELETE FROM saas_audit WHERE target_type = 'tenant' AND target_id = ?", [$tid]);
    ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
    // 同 code 不同 id 的历史残留（code 唯一键）
    ControlDb::exec('DELETE FROM saas_tenant WHERE code = ?', ['billht' . $n]);
}

/**
 * 控制面直插造租户（id=9100n、code=billht<n>、status/expires_at/bill_cycle 可控），先清残留。
 * $expiresAt 传 null = 不参与到期逻辑。
 */
function bh_make_tenant(int $n, string $status, ?string $expiresAt, string $cycle = 'year', int $planId = 1): int
{
    bh_cleanup($n);
    $data = [
        'id' => bh_tid($n), 'code' => 'billht' . $n, 'name' => '账单HTTP测试租户' . $n, 'mode' => 'shared',
        'plan_id' => $planId, 'status' => $status, 'bill_cycle' => $cycle,
        'contact_name' => '测试', 'contact_phone' => '13800000000',
        'admin_username' => 'boss' . $n, 'applied_at' => date('Y-m-d H:i:s'),
    ];
    if ($expiresAt !== null) {
        $data['expires_at'] = $expiresAt;
    }
    ControlDb::insert('saas_tenant', $data);
    return bh_tid($n);
}

function bh_make_bill(int $tid, string $cycle, float $amount): int
{
    return ControlDb::insert('saas_bill', [
        'tenant_id' => $tid, 'cycle' => $cycle, 'amount' => $amount, 'status' => 'unpaid', 'remark' => '账单HTTP测试',
    ]);
}

/** 数据面（共享库）播种 tenant_admin 角色 + 管理员 boss<n>，写法对齐 test_isolation_http 的 qg_ensure_tenant2 */
function bh_seed_admin(int $n, string $password): void
{
    $tid = bh_tid($n);
    Tenant::set(['id' => $tid, 'mode' => 'shared', 'db_name' => '']);
    $roleId = Db::val('SELECT id FROM sys_role WHERE tenant_id = ? AND code = ?', [$tid, 'tenant_admin']);
    if (!$roleId) {
        $roleId = Db::insert('sys_role', [
            'tenant_id' => $tid, 'name' => '租户系统管理员', 'code' => 'tenant_admin', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    Db::exec('DELETE FROM sys_user WHERE tenant_id = ?', [$tid]);
    Db::insert('sys_user', [
        'tenant_id' => $tid, 'username' => 'boss' . $n, 'real_name' => '账单测试管理员',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role_id' => (int) $roleId,
        'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
    ]);
    Tenant::set(null);
}

t('账单：pending 租户确认收款被拒', function () {
    if (!qg_site_up()) { return; }
    $jar = bh_platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $tid = bh_make_tenant(1, 'pending', null, 'year');
    try {
        $billId = bh_make_bill($tid, 'year', 6000.00);
        $r = qg_post_json('/api/platform/bills/' . $billId . '/confirm', [], ['-b', $jar]);
        assert_contains($r, '租户未开通，暂不能确认收款', 'pending 租户确认收款应被拒：' . $r);
        $row = ControlDb::one('SELECT status, paid_at FROM saas_bill WHERE id = ?', [$billId]);
        assert_eq('unpaid', $row['status'], '被拒后账单应仍 unpaid');
        assert_eq(null, $row['paid_at'], '被拒后 paid_at 不应有值');
    } finally {
        bh_cleanup(1);
    }
});

t('账单：确认收款三分支与幂等拒绝', function () {
    if (!qg_site_up()) { return; }
    $jar = bh_platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $now = time();
    // 到期日取月中整点，避开月末进退位歧义；期望值按 Billing::nextExpiry 同一套字符串算法推
    $specs = [
        2 => ['exp' => date('Y-m-d 12:00:00', $now + 10 * 86400), 'cycle' => 'year',  'amount' => 6000.00, 'branch' => '未到期'],
        3 => ['exp' => date('Y-m-d 12:00:00', $now - 10 * 86400), 'cycle' => 'month', 'amount' => 600.00,  'branch' => '宽限内'],
        4 => ['exp' => date('Y-m-d 12:00:00', $now - 40 * 86400), 'cycle' => 'month', 'amount' => 600.00,  'branch' => '超宽限'],
    ];
    $bills = [];
    try {
        foreach ($specs as $n => $s) {
            bh_make_tenant($n, 'active', $s['exp'], $s['cycle']);
            $bills[$n] = bh_make_bill(bh_tid($n), $s['cycle'], $s['amount']);
        }
        // 分支 1/2：未到期 / 宽限内 → 旧到期日 + 周期（精确到秒）
        foreach ([2, 3] as $n) {
            $r = qg_post_json('/api/platform/bills/' . $bills[$n] . '/confirm', ['ref' => 'HT-' . $n], ['-b', $jar]);
            assert_contains($r, '"code":0', "分支{$n}（{$specs[$n]['branch']}）确认收款应成功：" . $r);
            $expect = date('Y-m-d H:i:s', strtotime($specs[$n]['exp'] . ($specs[$n]['cycle'] === 'year' ? ' +1 year' : ' +1 month')));
            $d = json_decode($r, true);
            assert_eq($expect, $d['data']['expires_at'] ?? null, "分支{$n}（{$specs[$n]['branch']}）新到期日应=旧值+周期：" . $r);
            $row = ControlDb::one('SELECT status, paid_at FROM saas_bill WHERE id = ?', [$bills[$n]]);
            assert_eq('paid', $row['status'], "分支{$n} 账单应已 paid");
            assert_eq($expect, ControlDb::val('SELECT expires_at FROM saas_tenant WHERE id = ?', [bh_tid($n)]), "分支{$n} 库里到期日应已重算");
        }
        // 分支 3：超宽限 → now + 周期（±120 秒容差）
        $r = qg_post_json('/api/platform/bills/' . $bills[4] . '/confirm', ['ref' => 'HT-4'], ['-b', $jar]);
        assert_contains($r, '"code":0', '分支4（超宽限）确认收款应成功：' . $r);
        $got = strtotime((string) (json_decode($r, true)['data']['expires_at'] ?? ''));
        $mid = strtotime(date('Y-m-d H:i:s', $now) . ' +1 month');
        assert_true(abs($got - $mid) <= 120, "分支4（超宽限）新到期日应≈now+1 个月：{$r}（mid={$mid}）");
        assert_eq('paid', ControlDb::val('SELECT status FROM saas_bill WHERE id = ?', [$bills[4]]), '分支4 账单应已 paid');
        $dbGot = strtotime((string) ControlDb::val('SELECT expires_at FROM saas_tenant WHERE id = ?', [bh_tid(4)]));
        assert_true(abs($dbGot - $mid) <= 120, '分支4 库里到期日应≈now+1 个月');
        // 幂等拒绝：对已 paid 的账单再 confirm
        $r = qg_post_json('/api/platform/bills/' . $bills[2] . '/confirm', [], ['-b', $jar]);
        assert_contains($r, '账单不存在或不是待支付状态', '已 paid 账单再 confirm 应被拒：' . $r);
        $row = ControlDb::one('SELECT status, paid_at FROM saas_bill WHERE id = ?', [$bills[2]]);
        assert_eq('paid', $row['status'], '重放后账单应仍 paid 且不变');
    } finally {
        foreach (array_keys($specs) as $n) {
            bh_cleanup($n);
        }
    }
});

t('账单：自助续费生成待付账单（仅管理员）', function () {
    if (!qg_site_up()) { return; }
    bh_make_tenant(5, 'active', date('Y-m-d 12:00:00', time() + 20 * 86400), 'year');
    $jar = null;
    try {
        bh_seed_admin(5, 'BhPass123');
        $jar = bh_login('billht5', 'boss5', 'BhPass123');
        assert_true($jar !== null, '账单测试租户管理员应能登录');
        $r = qg_post_json('/api/bills', ['cycle' => 'month'], ['-b', $jar]);
        assert_contains($r, '"code":0', '自助续费应成功：' . $r);
        $row = ControlDb::one('SELECT id, status, cycle, amount FROM saas_bill WHERE tenant_id = ? ORDER BY id DESC LIMIT 1', [bh_tid(5)]);
        assert_eq('unpaid', $row['status'], '应生成 unpaid 新账单');
        assert_eq('month', $row['cycle'], '周期应为请求的 month');
        assert_eq('600.00', (string) $row['amount'], '金额应为 plan1 月价快照 600.00');
        $r = qg_curl('/api/bills', ['-b', $jar]);
        assert_contains($r, '"code":0', '账单列表应可访问：' . $r);
        assert_contains($r, '"id":' . $row['id'] . ',', '列表应含新账单：' . $r);
        assert_contains($r, '"amount":"600.00"', '列表应带出金额快照：' . $r);
        // 平台令牌无租户管理员身份 → 403（替代「无账单租户普通用户」路径的权限断言）
        $pjar = bh_platform_jar();
        if ($pjar) {
            $r = qg_curl('/api/bills', ['-b', $pjar]);
            assert_contains($r, '仅租户管理员可查看账单', '平台令牌调租户账单接口应 403：' . $r);
        }
    } finally {
        if ($jar) { @unlink($jar); }
        bh_cleanup(5);
    }
});

t('账单：过期租户登录被拦截', function () {
    if (!qg_site_up()) { return; }
    // 超宽限（-40 天）：登录必须被拦
    bh_make_tenant(6, 'active', date('Y-m-d H:i:s', time() - 40 * 86400), 'year');
    // 对照组：宽限内（-10 天）：可登录，/auth/me billing.state=grace
    $jar = null;
    try {
        bh_seed_admin(6, 'BhPass123');
        bh_make_tenant(7, 'active', date('Y-m-d H:i:s', time() - 10 * 86400), 'year');
        bh_seed_admin(7, 'BhPass123');
        $r = qg_post_json('/api/auth/login', ['tenant' => 'billht6', 'username' => 'boss6', 'password' => 'BhPass123']);
        assert_contains($r, '服务已到期，请联系平台续费', '超宽限（-40 天）登录应被拦截：' . $r);
        $jar = bh_login('billht7', 'boss7', 'BhPass123');
        assert_true($jar !== null, '宽限内（-10 天）登录应成功');
        $me = qg_curl('/api/auth/me', ['-b', $jar]);
        assert_contains($me, '"state":"grace"', '宽限租户 billing.state 应为 grace：' . $me);
        assert_contains($me, '"trial":true', '无付款记录的宽限 = 试用：' . $me);
    } finally {
        if ($jar) { @unlink($jar); }
        bh_cleanup(6);
        bh_cleanup(7);
    }
});

t('账单：租户 token 只能读本租户账单', function () {
    if (!qg_site_up()) { return; }
    $tidA = bh_make_tenant(8, 'active', date('Y-m-d 12:00:00', time() + 20 * 86400), 'year');
    $jarA = null; $jarB = null;
    try {
        $billA = bh_make_bill($tidA, 'year', 6011.00);
        bh_seed_admin(8, 'BhPass123');
        $tidB = bh_make_tenant(9, 'active', date('Y-m-d 12:00:00', time() + 20 * 86400), 'month');
        $billB = bh_make_bill($tidB, 'month', 602.20);
        bh_seed_admin(9, 'BhPass123');
        $jarA = bh_login('billht8', 'boss8', 'BhPass123');
        $jarB = bh_login('billht9', 'boss9', 'BhPass123');
        assert_true($jarA !== null && $jarB !== null, '两个租户管理员都应能登录');
        $r = qg_curl('/api/bills', ['-b', $jarA]);
        assert_contains($r, '"code":0', 'A 管理员应能读账单列表：' . $r);
        // /api/bills 返回裸数组（非分页信封）：首元素即 A 的账单，且全程不出现 B 的账单号/金额
        assert_contains($r, '"data":[{"id":' . $billA . ',', 'A 应恰好只见自己的账单（首元素）：' . $r);
        assert_contains($r, '"id":' . $billA . ',', 'A 应看到自己的账单号：' . $r);
        assert_contains($r, '"amount":"6011.00"', 'A 应看到自己的金额：' . $r);
        assert_true(strpos($r, '"id":' . $billB . ',') === false, 'A 不应看到 B 的账单号：' . $r);
        assert_true(strpos($r, '602.20') === false, 'A 不应看到 B 的金额：' . $r);
    } finally {
        foreach ([$jarA, $jarB] as $j) { if ($j) { @unlink($j); } }
        bh_cleanup(8);
        bh_cleanup(9);
    }
});
