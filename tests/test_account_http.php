<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/http.php';

use QG\ControlDb;
use QG\Db;
use QG\Tenant;

/**
 * 账号与密码管理全链路 HTTP 用例（计划 2026-10-16-account-password 任务 7）。
 * 覆盖：平台代重置租户管理员（一次性临时密码 + 首登强改清零）、平台账号增/停/启/重置、
 * 平台账号保护规则、平台自改密（≥10 位）、平台收款信息（白名单 / 显式空串清空回落 / 租户侧回读）。
 *
 * 合成号段 920001-920010（与 test_provision 的 9001、test_billing_http 的 9100xx、test_platform_api 的 9999xx 错开）。
 * 铁律：**绝不改动 platform 账号 id=1 的状态与密码**（本机 dev 只播种了这一个平台账号，动了就把平台锁死）；
 * 每个用例自带造数与 finally 清理，清理一律按精确 tid / 明确用户名，不用区间条件。
 */

function ac_tid(int $n): int
{
    return 920000 + $n;
}

/**
 * 清空本机限流计数（仅开发脚手架）：platform-login 10 次/10 分钟、tenant-login 20 次/10 分钟
 * 是**全局共享桶**，本文件的登录次数会与其后的 HTTP 用例叠加（test_platform_api 自己就要登录 6 次），
 * 不清桶必出现 429 假红。与 tests/bootstrap.php 套件启动时清桶同源：
 * 生产 api/runtime 独立、限流逻辑分毫未动，这不是在破坏限流。
 */
function ac_reset_ratelimit(): void
{
    foreach (glob(dirname(__DIR__) . '/api/runtime/ratelimit/*.json') ?: [] as $f) {
        @unlink($f);
    }
}

/** 平台超管 jar：文件内只登录一次后续复用（platform_jar 本身每次调用都真登录，会吃限额） */
function ac_platform_jar(): ?string
{
    static $jar = false;
    if ($jar === false) { $jar = platform_jar(); }
    return $jar;
}

/** 租户登录：返回 [jar|null, 原始响应]。响应一并返回，负向断言才能区分「密码错」与「撞 429」 */
function ac_login(string $tenant, string $username, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'qgck');
    $r = qg_post_json('/api/auth/login', ['tenant' => $tenant, 'username' => $username, 'password' => $password], ['-c', $jar]);
    if (strpos($r, '"code":0') === false) {
        @unlink($jar);
        return [null, $r];
    }
    return [$jar, $r];
}

/** 平台账号登录：返回 [jar|null, 原始响应] */
function ac_platform_login(string $username, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'qgpf');
    $r = qg_post_json('/api/platform/login', ['username' => $username, 'password' => $password], ['-c', $jar]);
    if (strpos($r, '"code":0') === false) {
        @unlink($jar);
        return [null, $r];
    }
    return [$jar, $r];
}

/** PUT JSON（tests/http.php 只有 post 版） */
function ac_put_json(string $path, array $body, array $args = []): string
{
    return qg_curl($path, array_merge(
        ['-X', 'PUT', '-H', 'Content-Type: application/json', '-d', json_encode($body, JSON_UNESCAPED_UNICODE)],
        $args
    ));
}

/** 清一个合成租户的全部现场（数据面共享库 + 控制面）。只按精确 tid / code 删。 */
function ac_cleanup_tenant(int $n): void
{
    $tid = ac_tid($n);
    Tenant::set(['id' => $tid, 'mode' => 'shared', 'db_name' => '']);
    $pdo = Tenant::pdo();
    foreach (['sys_user', 'sys_role_permission', 'sys_role', 'sys_permission', 'sys_config', 'sys_dict', 'sys_log'] as $tb) {
        $pdo->exec('DELETE FROM ' . $tb . ' WHERE tenant_id = ' . $tid);
    }
    Tenant::set(null);
    ControlDb::exec('DELETE FROM saas_bill WHERE tenant_id = ?', [$tid]);
    ControlDb::exec("DELETE FROM saas_audit WHERE target_type = 'tenant' AND target_id = ?", [$tid]);
    ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
    ControlDb::exec('DELETE FROM saas_tenant WHERE code = ?', ['acctht' . $n]);   // 同 code 不同 id 的历史残留
}

/** 控制面直插造租户（active、expires_at=NULL 不参与到期逻辑），先清残留 */
function ac_make_tenant(int $n): int
{
    ac_cleanup_tenant($n);
    ControlDb::insert('saas_tenant', [
        'id' => ac_tid($n), 'code' => 'acctht' . $n, 'name' => '账号HTTP测试租户' . $n, 'mode' => 'shared',
        'plan_id' => 1, 'status' => 'active', 'bill_cycle' => 'year',
        'contact_name' => '测试', 'contact_phone' => '13800000000',
        'admin_username' => 'boss' . $n, 'applied_at' => date('Y-m-d H:i:s'),
    ]);
    return ac_tid($n);
}

/** 数据面（共享库）播种 tenant_admin 角色 + 管理员，写法对齐 test_billing_http 的 bh_seed_admin */
function ac_seed_admin(int $n, string $password): void
{
    $tid = ac_tid($n);
    Tenant::set(['id' => $tid, 'mode' => 'shared', 'db_name' => '']);
    $roleId = Db::val('SELECT id FROM sys_role WHERE tenant_id = ? AND code = ?', [$tid, 'tenant_admin']);
    if (!$roleId) {
        $roleId = Db::insert('sys_role', [
            'tenant_id' => $tid, 'name' => '租户系统管理员', 'code' => 'tenant_admin', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    Db::exec('DELETE FROM sys_user WHERE tenant_id = ?', [$tid]);
    Db::insert('sys_user', [
        'tenant_id' => $tid, 'username' => 'boss' . $n, 'real_name' => '账号测试管理员',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role_id' => (int) $roleId,
        'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
    ]);
    Tenant::set(null);
}

/** 清掉本文件造的平台账号（accpt 前缀）与本次运行新产生的账号审计行 */
function ac_cleanup_accounts(int $auditMark): void
{
    ControlDb::exec("DELETE FROM saas_platform_user WHERE username LIKE 'accpt%'");
    ControlDb::exec(
        "DELETE FROM saas_audit WHERE id > ? AND action IN
         ('account_create','account_disable','account_enable','account_reset','account_password')",
        [$auditMark]
    );
}

/** 账号相关动作用例的审计基线：只删本次运行新增的行，不动历史审计 */
function ac_audit_mark(): int
{
    return (int) ControlDb::val('SELECT COALESCE(MAX(id), 0) FROM saas_audit');
}

/** platform 账号 id=1 的现场（用例前后比对，证明测试没碰过它） */
function ac_platform1_snapshot(): array
{
    return ControlDb::one('SELECT status, must_change_password, password_hash FROM saas_platform_user WHERE id = 1') ?: [];
}

t('账号：平台代重置租户管理员密码全链路（一次性临时密码 → 首登强改 → 清零）', function () {
    if (!qg_site_up()) { return; }
    ac_reset_ratelimit();
    $jar = ac_platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $tid = ac_tid(1);
    $tempJar = null;
    $before = ac_platform1_snapshot();
    try {
        ac_make_tenant(1);
        ac_seed_admin(1, 'Aold12345');

        // 未登录 401（代重置是高危动作，鉴权必须先挡住）
        $r = qg_post_json('/api/platform/tenants/' . $tid . '/reset-admin-password', []);
        assert_contains($r, '"code":401', '未登录调代重置应 401：' . $r);
        assert_eq(0, (int) qg_test_val('SELECT must_change_password FROM sys_user WHERE tenant_id = ? AND username = ?', [$tid, 'boss1']),
            '未登录请求不得改动账号');

        // 平台代重置：一次性临时密码
        $r = qg_post_json('/api/platform/tenants/' . $tid . '/reset-admin-password', [], ['-b', $jar]);
        assert_contains($r, '"code":0', '平台代重置应成功：' . $r);
        $temp = (string) (json_decode($r, true)['data']['temp_password'] ?? '');
        assert_eq(12, strlen($temp), '一次性临时密码应为 12 位：' . $r);
        assert_true((bool) preg_match('/^[A-HJ-NP-Za-hj-km-np-z2-9!@#$%^&*]{12}$/', $temp), '临时密码应为无易混淆字符集：' . $temp);

        // 零明文口径：库里只存哈希，审计只记动作不记明文
        $hash = (string) qg_test_val('SELECT password_hash FROM sys_user WHERE tenant_id = ? AND username = ?', [$tid, 'boss1']);
        assert_true($hash !== '' && strpos($hash, $temp) === false, '数据面只应存密码哈希');
        assert_eq(1, (int) qg_test_val('SELECT must_change_password FROM sys_user WHERE tenant_id = ? AND username = ?', [$tid, 'boss1']),
            '代重置应置强改标记');
        $detail = (string) ControlDb::val("SELECT detail FROM saas_audit WHERE target_type = 'tenant' AND target_id = ? ORDER BY id DESC LIMIT 1", [$tid]);
        assert_contains($detail, '重置管理员账号', '审计应记录代重置动作：' . $detail);
        assert_true(strpos($detail, $temp) === false, '审计不得记录临时密码明文：' . $detail);

        // 旧密码立即失效（断言具体文案，避免把 429 之类的失败误当通过）
        [$oldJar, $r] = ac_login('acctht1', 'boss1', 'Aold12345');
        assert_true($oldJar === null, '代重置后旧密码应失效：' . $r);
        assert_contains($r, '账号或密码错误', '旧密码应报账号或密码错误：' . $r);

        // 临时密码可登录，且带出强改标记
        $tempJar = tempnam(sys_get_temp_dir(), 'qgck');
        $r = qg_post_json('/api/auth/login', ['tenant' => 'acctht1', 'username' => 'boss1', 'password' => $temp], ['-c', $tempJar]);
        assert_contains($r, '"code":0', '临时密码应能登录：' . $r);
        assert_contains($r, '"must_change_password":1', '临时密码首登应带出强改标记：' . $r);

        // 首登改密 → 标记清零
        $r = qg_post_json('/api/auth/password', ['old_password' => $temp, 'new_password' => 'Bnew12345'], ['-b', $tempJar]);
        assert_contains($r, '"code":0', '首登改密应成功：' . $r);
        $me = qg_curl('/api/auth/me', ['-b', $tempJar]);
        assert_contains($me, '"must_change_password":0', '改密后 /auth/me 应清零强改标记：' . $me);
        assert_eq(0, (int) qg_test_val('SELECT must_change_password FROM sys_user WHERE tenant_id = ? AND username = ?', [$tid, 'boss1']),
            '改密后库里强改标记应为 0');

        // 临时密码失效、新密码可用
        [$tJar, $r] = ac_login('acctht1', 'boss1', $temp);
        assert_true($tJar === null, '改密后临时密码应失效：' . $r);
        assert_contains($r, '账号或密码错误', '临时密码应报账号或密码错误：' . $r);
        [$newJar, $r] = ac_login('acctht1', 'boss1', 'Bnew12345');
        assert_true($newJar !== null, '新密码应能登录：' . $r);
        @unlink($newJar);

        // 代重置只对已开通租户生效
        ControlDb::exec("UPDATE saas_tenant SET status = 'suspended' WHERE id = ?", [$tid]);
        $r = qg_post_json('/api/platform/tenants/' . $tid . '/reset-admin-password', [], ['-b', $jar]);
        assert_contains($r, '仅已开通的租户可重置管理员密码', '停用租户代重置应被拒：' . $r);

        assert_eq($before, ac_platform1_snapshot(), '用例不得触碰 platform 账号 id=1');
    } finally {
        foreach ([$tempJar] as $j) { if ($j) { @unlink($j); } }
        ac_cleanup_tenant(1);
    }
});

t('平台账号：新增 / 停用 / 启用 / 重置密码全链路', function () {
    if (!qg_site_up()) { return; }
    ac_reset_ratelimit();
    $jar = ac_platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $before = ac_platform1_snapshot();
    $mark = ac_audit_mark();
    $jars = [];
    $name = 'accpt2';
    try {
        // 新增：一次性临时密码 + 强改标记
        $r = qg_post_json('/api/platform/accounts', ['username' => $name, 'real_name' => '冒烟账号'], ['-b', $jar]);
        assert_contains($r, '"code":0', '新增平台账号应成功：' . $r);
        $temp = (string) (json_decode($r, true)['data']['temp_password'] ?? '');
        assert_eq(12, strlen($temp), '新增应返回 12 位一次性临时密码：' . $r);
        $aid = (int) ControlDb::val('SELECT id FROM saas_platform_user WHERE username = ?', [$name]);
        assert_true($aid > 0, '新账号应已落控制面');
        $row = ControlDb::one('SELECT status, must_change_password, password_hash FROM saas_platform_user WHERE id = ?', [$aid]);
        assert_eq(1, (int) $row['status'], '新账号应启用');
        assert_eq(1, (int) $row['must_change_password'], '新账号应带强改标记');
        assert_true(strpos((string) $row['password_hash'], $temp) === false, '控制面只应存密码哈希');

        // 重复账号 / 非法账号名
        $r = qg_post_json('/api/platform/accounts', ['username' => $name, 'real_name' => '重复'], ['-b', $jar]);
        assert_contains($r, '该平台账号已存在', '重复账号应被拒：' . $r);
        $r = qg_post_json('/api/platform/accounts', ['username' => 'a', 'real_name' => '过短'], ['-b', $jar]);
        assert_contains($r, '3-30 位', '非法账号名应被拒：' . $r);
        $r = qg_post_json('/api/platform/accounts', ['username' => 'accptx', 'real_name' => '邮箱错', 'email' => 'not-an-email'], ['-b', $jar]);
        assert_contains($r, '邮箱格式不正确', '非法邮箱应被拒：' . $r);

        // 列表：含新账号、不回显密码
        $r = qg_curl('/api/platform/accounts', ['-b', $jar]);
        assert_contains($r, '"code":0', '账号列表应可访问：' . $r);
        assert_contains($r, '"username":"' . $name . '"', '列表应含新账号：' . $r);
        assert_true(strpos($r, $temp) === false, '列表不得回显临时密码明文：' . $r);
        assert_true(strpos($r, 'password_hash') === false, '列表不得出现 password_hash：' . $r);

        // 临时密码首登成功且带强改标记
        [$jar2, $r] = ac_platform_login($name, $temp);
        assert_true($jar2 !== null, '临时密码应能登录平台：' . $r);
        assert_contains($r, '"must_change_password":1', '新账号首登应带强改标记：' . $r);
        $jars[] = $jar2;

        // 停用：status=0（不物理删除），登录被拒，旧会话立即失效
        $r = qg_post_json('/api/platform/accounts/' . $aid . '/disable', [], ['-b', $jar]);
        assert_contains($r, '"code":0', '停用应成功：' . $r);
        assert_eq(0, (int) ControlDb::val('SELECT status FROM saas_platform_user WHERE id = ?', [$aid]), '停用应置 status=0');
        assert_true((bool) ControlDb::val('SELECT id FROM saas_platform_user WHERE id = ?', [$aid]), '停用不得物理删除账号');
        [$j3, $r] = ac_platform_login($name, $temp);
        assert_true($j3 === null, '停用账号不得登录：' . $r);
        assert_contains(qg_curl('/api/platform/me', ['-b', $jar2]), '"code":401', '停用后旧会话应失效');
        @unlink($jar2);

        // 启用 + 重置密码：换发新临时密码，旧临时密码失效
        $r = qg_post_json('/api/platform/accounts/' . $aid . '/enable', [], ['-b', $jar]);
        assert_contains($r, '"code":0', '启用应成功：' . $r);
        $r = qg_post_json('/api/platform/accounts/' . $aid . '/reset-password', [], ['-b', $jar]);
        assert_contains($r, '"code":0', '重置密码应成功：' . $r);
        $temp2 = (string) (json_decode($r, true)['data']['temp_password'] ?? '');
        assert_eq(12, strlen($temp2), '重置应返回 12 位一次性临时密码：' . $r);
        assert_true($temp2 !== $temp, '重置应换发新临时密码');
        assert_eq(1, (int) ControlDb::val('SELECT must_change_password FROM saas_platform_user WHERE id = ?', [$aid]),
            '重置应重新置强改标记');
        [$j4, $r] = ac_platform_login($name, $temp);
        assert_true($j4 === null, '重置后旧临时密码应失效：' . $r);
        assert_contains($r, '账号或密码错误', '旧临时密码应报账号或密码错误：' . $r);
        [$j5, $r] = ac_platform_login($name, $temp2);
        assert_true($j5 !== null, '新临时密码应能登录：' . $r);
        assert_contains($r, '"must_change_password":1', '重置后首登仍应带强改标记：' . $r);
        $jars[] = $j5;

        assert_eq($before, ac_platform1_snapshot(), '用例不得触碰 platform 账号 id=1');
    } finally {
        foreach ($jars as $j) { if ($j) { @unlink($j); } }
        ac_cleanup_accounts($mark);
    }
});

t('平台账号：保护规则（自停用 / 不存在 / 状态机 / 至少留一个启用账号）与未登录 401', function () {
    if (!qg_site_up()) { return; }
    ac_reset_ratelimit();
    $jar = ac_platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $before = ac_platform1_snapshot();
    $mark = ac_audit_mark();
    $aid = 0;
    try {
        // 未登录 401
        assert_contains(qg_curl('/api/platform/accounts'), '"code":401', '未登录读账号列表应 401');
        assert_contains(qg_post_json('/api/platform/accounts', ['username' => 'accptx', 'real_name' => 'x']), '"code":401',
            '未登录建账号应 401');
        assert_eq(0, (int) ControlDb::val("SELECT COUNT(*) FROM saas_platform_user WHERE username LIKE 'accpt%'"),
            '未登录请求不得建出账号');

        // 不能停用自己
        $me = json_decode(qg_curl('/api/platform/me', ['-b', $jar]), true);
        $selfId = (int) ($me['data']['id'] ?? 0);
        assert_true($selfId > 0, '应能取到平台会话账号 id：' . json_encode($me, JSON_UNESCAPED_UNICODE));
        $r = qg_post_json('/api/platform/accounts/' . $selfId . '/disable', [], ['-b', $jar]);
        assert_contains($r, '不能停用自己的账号', '停用自己应被拒：' . $r);
        assert_eq(1, (int) ControlDb::val('SELECT status FROM saas_platform_user WHERE id = ?', [$selfId]), '被拒后自己仍应启用');

        // 不存在的账号
        $r = qg_post_json('/api/platform/accounts/999999999/disable', [], ['-b', $jar]);
        assert_contains($r, '平台账号不存在', '停用不存在账号应报不存在：' . $r);
        $r = qg_post_json('/api/platform/accounts/999999999/enable', [], ['-b', $jar]);
        assert_contains($r, '平台账号不存在或已启用', '启用不存在账号应报不存在：' . $r);
        $r = qg_post_json('/api/platform/accounts/999999999/reset-password', [], ['-b', $jar]);
        assert_contains($r, '平台账号不存在', '重置不存在账号应报不存在：' . $r);

        // 状态机：停用两次 / 启用两次
        $r = qg_post_json('/api/platform/accounts', ['username' => 'accpt3', 'real_name' => '状态机冒烟'], ['-b', $jar]);
        assert_contains($r, '"code":0', '新增状态机测试账号应成功：' . $r);
        $aid = (int) ControlDb::val("SELECT id FROM saas_platform_user WHERE username = 'accpt3'");
        assert_true($aid > 0, '测试账号应已落库');
        assert_contains(qg_post_json('/api/platform/accounts/' . $aid . '/disable', [], ['-b', $jar]), '"code":0', '首次停用应成功');
        $r = qg_post_json('/api/platform/accounts/' . $aid . '/disable', [], ['-b', $jar]);
        assert_contains($r, '账号状态已变化，请刷新后重试', '重复停用应被拒：' . $r);
        assert_contains(qg_post_json('/api/platform/accounts/' . $aid . '/enable', [], ['-b', $jar]), '"code":0', '重新启用应成功');
        $r = qg_post_json('/api/platform/accounts/' . $aid . '/enable', [], ['-b', $jar]);
        assert_contains($r, '平台账号不存在或已启用', '重复启用应被拒：' . $r);

        // 等权保护不变式：任何时刻都至少留一个启用账号，会话不被自己锁死
        // （「停用最后一个启用账号」那条分支是并发防御：只有请求方账号在同一事务窗口内被
        //  别的请求停掉才会命中；本机 dev 只播种了 id=1 且它必须保持启用，故 HTTP 层构造不出来，
        //  见 AGENTS.md §9 与本次任务报告。）
        assert_true((int) ControlDb::val('SELECT COUNT(*) FROM saas_platform_user WHERE status = 1') >= 1,
            '平台必须至少保留一个启用账号');
        assert_contains(qg_curl('/api/platform/me', ['-b', $jar]), '"code":0', '受保护规则约束后平台会话仍应可用');
        assert_contains(qg_curl('/api/platform/accounts', ['-b', $jar]), '"code":0', '保护规则处理后账号列表仍应可用');

        assert_eq($before, ac_platform1_snapshot(), '用例不得触碰 platform 账号 id=1');
    } finally {
        ac_cleanup_accounts($mark);
    }
});

t('平台改密：原密码错 / 少于 10 位被拒，成功后清零强改标记', function () {
    if (!qg_site_up()) { return; }
    ac_reset_ratelimit();
    $jar = ac_platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $before = ac_platform1_snapshot();
    $mark = ac_audit_mark();
    $jars = [];
    try {
        $r = qg_post_json('/api/platform/accounts', ['username' => 'accpt4', 'real_name' => '改密冒烟'], ['-b', $jar]);
        assert_contains($r, '"code":0', '新增改密测试账号应成功：' . $r);
        $temp = (string) (json_decode($r, true)['data']['temp_password'] ?? '');
        $aid = (int) ControlDb::val("SELECT id FROM saas_platform_user WHERE username = 'accpt4'");
        assert_true($aid > 0 && strlen($temp) === 12, '改密测试账号与临时密码应就绪：' . $r);

        [$jar4, $r] = ac_platform_login('accpt4', $temp);
        assert_true($jar4 !== null, '改密测试账号应能登录：' . $r);
        $jars[] = $jar4;

        // 新密码长度下限 10 位（计划原文用的 'Bnew12345' 只有 9 位，够不上这道闸；这里换成 11 位）
        $r = qg_post_json('/api/platform/password', ['old_password' => $temp, 'new_password' => 'Short123'], ['-b', $jar4]);
        assert_contains($r, '新密码至少 10 位', '少于 10 位应被拒：' . $r);
        // 原密码错
        $r = qg_post_json('/api/platform/password', ['old_password' => 'WrongPass123', 'new_password' => 'Bnew1234567'], ['-b', $jar4]);
        assert_contains($r, '原密码不正确', '原密码错应被拒：' . $r);
        assert_eq(1, (int) ControlDb::val('SELECT must_change_password FROM saas_platform_user WHERE id = ?', [$aid]),
            '两次失败不得改动强改标记');
        [$still, $r] = ac_platform_login('accpt4', $temp);
        assert_true($still !== null, '两次失败后原密码仍应可用：' . $r);
        @unlink($still);

        // 成功改密 → 清零强改标记
        $r = qg_post_json('/api/platform/password', ['old_password' => $temp, 'new_password' => 'Bnew1234567'], ['-b', $jar4]);
        assert_contains($r, '"code":0', '合法改密应成功：' . $r);
        assert_eq(0, (int) ControlDb::val('SELECT must_change_password FROM saas_platform_user WHERE id = ?', [$aid]),
            '改密成功应清零强改标记');
        assert_contains(qg_curl('/api/platform/me', ['-b', $jar4]), '"must_change_password":0', '改密后 /platform/me 应清零标记');
        assert_eq(1, (int) ControlDb::val('SELECT status FROM saas_platform_user WHERE id = ?', [$aid]), '改密不应动 status');

        // 旧密码失效、新密码可登录
        [$tJar, $r] = ac_platform_login('accpt4', $temp);
        assert_true($tJar === null, '改密后旧密码应失效：' . $r);
        assert_contains($r, '账号或密码错误', '旧密码应报账号或密码错误：' . $r);
        [$nJar, $r] = ac_platform_login('accpt4', 'Bnew1234567');
        assert_true($nJar !== null, '新密码应能登录：' . $r);
        assert_contains($r, '"must_change_password":0', '改密后登录不应再带强改标记：' . $r);
        $jars[] = $nJar;

        // 审计不记密码明文
        $detail = (string) ControlDb::val("SELECT detail FROM saas_audit WHERE action = 'account_password' AND id > ? ORDER BY id DESC LIMIT 1", [$mark]);
        assert_contains($detail, '修改了密码', '应记录改密审计：' . $detail);
        assert_true(strpos($detail, $temp) === false && strpos($detail, 'Bnew1234567') === false, '审计不得记录密码明文：' . $detail);

        assert_eq($before, ac_platform1_snapshot(), '用例不得触碰 platform 账号 id=1');
    } finally {
        foreach ($jars as $j) { if ($j) { @unlink($j); } }
        ac_cleanup_accounts($mark);
    }
});

t('平台收款信息：白名单、清空回落与租户侧回读', function () {
    if (!qg_site_up()) { return; }
    ac_reset_ratelimit();
    $jar = ac_platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    // 测试前的设置行现场：原样恢复，不留垃圾也不覆盖既有配置
    $prior = ControlDb::one('SELECT setting_value, updated_by FROM saas_setting WHERE setting_key = ?', ['billing_notice']);
    $priorVal = $prior['setting_value'] ?? null;
    $jarT = null;
    try {
        // 未登录 401，且不得写入
        assert_contains(qg_curl('/api/platform/settings'), '"code":401', '未登录读设置应 401');
        assert_contains(ac_put_json('/api/platform/settings', ['billing_notice' => '未登录注入']), '"code":401', '未登录改设置应 401');
        assert_eq($priorVal, ControlDb::val('SELECT setting_value FROM saas_setting WHERE setting_key = ?', ['billing_notice']),
            '未登录请求不得写设置');

        // 白名单外的键：拒绝，且不落库
        $r = ac_put_json('/api/platform/settings', ['hacker_key' => 'x'], ['-b', $jar]);
        assert_contains($r, '没有需要更新的设置项', '白名单外的键应被拒：' . $r);
        assert_eq(null, ControlDb::val('SELECT setting_value FROM saas_setting WHERE setting_key = ?', ['hacker_key']),
            '白名单外的键不得落库');
        // 超长
        $r = ac_put_json('/api/platform/settings', ['billing_notice' => str_repeat('长', 2001)], ['-b', $jar]);
        assert_contains($r, '不能超过 2000 字', '超 2000 字应被拒：' . $r);
        assert_eq($priorVal, ControlDb::val('SELECT setting_value FROM saas_setting WHERE setting_key = ?', ['billing_notice']),
            '被拒的请求不得落库');

        // 写入 + 平台侧回读 + 租户侧回读
        $r = ac_put_json('/api/platform/settings', ['billing_notice' => '冒烟收款信息'], ['-b', $jar]);
        assert_contains($r, '"code":0', '保存收款信息应成功：' . $r);
        assert_eq('冒烟收款信息', ControlDb::val('SELECT setting_value FROM saas_setting WHERE setting_key = ?', ['billing_notice']),
            '设置应落控制面 saas_setting');
        $r = qg_curl('/api/platform/settings', ['-b', $jar]);
        assert_contains($r, '"billing_notice":"冒烟收款信息"', 'GET 应回读一致：' . $r);
        [$jarT, $r] = ac_login('demo', 'admin', 'admin123');
        assert_true($jarT !== null, 'demo 管理员应能登录（依赖已播种的 demo / admin / admin123）：' . $r);
        assert_contains(qg_curl('/api/auth/me', ['-b', $jarT]), '"notice":"冒烟收款信息"',
            '租户端 billing.notice 应取设置表：' . qg_curl('/api/auth/me', ['-b', $jarT]));

        // 显式空串 = 清空（删行），租户端回落兜底文案
        $r = ac_put_json('/api/platform/settings', ['billing_notice' => ''], ['-b', $jar]);
        assert_contains($r, '"code":0', '显式空串应被接受为清空：' . $r);
        assert_eq(null, ControlDb::val('SELECT setting_value FROM saas_setting WHERE setting_key = ?', ['billing_notice']),
            '显式空串应删掉设置行');
        $r = qg_curl('/api/platform/settings', ['-b', $jar]);
        assert_contains($r, '"billing_notice":null', '清空后 GET 应为 null：' . $r);
        $me = qg_curl('/api/auth/me', ['-b', $jarT]);
        assert_true(strpos($me, '冒烟收款信息') === false, '清空后租户端应回落兜底文案：' . $me);
        assert_contains($me, '"notice":"', '兜底文案不得为空：' . $me);
    } finally {
        if ($jarT) { @unlink($jarT); }
        if ($prior) {
            ControlDb::exec('UPDATE saas_setting SET setting_value = ? WHERE setting_key = ?',
                [$prior['setting_value'], 'billing_notice']);
        } else {
            ControlDb::exec('DELETE FROM saas_setting WHERE setting_key = ?', ['billing_notice']);
        }
        // 收尾清桶：本文件跑在套件最前，把共享限流桶恢复成「空」= 本文件未跑过时的状态
        ac_reset_ratelimit();
    }
});
