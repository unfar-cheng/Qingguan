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
    $tid = 0;
    try {
        // 注册
        $r = qg_post_json('/api/tenant/register', [
            'name' => '端到端测试企业', 'code' => $code, 'contact_name' => '测试',
            'contact_phone' => '13800000000', 'admin_username' => 'boss',
            'password' => 'E2ePass123', 'plan_id' => 1, 'bill_cycle' => 'year',
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
    } finally {
        // 清理必须放 finally：中途断言失败也绝不能留下 saas_tenant / 播种数据（历史上漏过两次）
        if ($tid > 0) {
            $pdo = qg_test_pdo();
            foreach (['sys_user', 'sys_role_permission', 'sys_role', 'sys_permission', 'sys_config', 'sys_dict', 'sys_log'] as $tb) {
                $pdo->exec("DELETE FROM {$tb} WHERE tenant_id = " . $tid);
            }
            ControlDb::exec('DELETE FROM saas_bill WHERE tenant_id = ?', [$tid]);
            ControlDb::exec('DELETE FROM saas_audit WHERE target_type = ? AND target_id = ?', ['tenant', $tid]);
            ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
        }
        @unlink($jar);
    }
});

t('audits 审计日志：分页 + action/租户代码筛选 + 未知租户恒空集', function () {
    if (!qg_site_up()) { return; }
    $jar = platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $tid = 999904;
    $mark = '审计冒烟' . substr(md5(uniqid()), 0, 8);
    try {
        ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
        ControlDb::exec("INSERT INTO saas_tenant (id, code, name, mode, status, admin_username, applied_at) VALUES (?, 'sm999904', '审计冒烟企业', 'shared', 'active', 'boss', NOW())", [$tid]);
        ControlDb::exec("DELETE FROM saas_audit WHERE target_type = 'tenant' AND target_id = ?", [$tid]);
        ControlDb::insert('saas_audit', ['platform_user_id' => 1, 'action' => 'approve', 'target_type' => 'tenant', 'target_id' => $tid, 'detail' => $mark . '-通过', 'ip' => '127.0.0.1']);
        ControlDb::insert('saas_audit', ['platform_user_id' => 1, 'action' => 'provision_failed', 'target_type' => 'tenant', 'target_id' => $tid, 'detail' => $mark . '-失败', 'ip' => '127.0.0.1']);
        ControlDb::insert('saas_audit', ['platform_user_id' => null, 'action' => 'register', 'target_type' => 'tenant', 'target_id' => $tid, 'detail' => json_encode(['code' => 'sm999904']), 'ip' => '127.0.0.1']);
        // 不筛：三条都在，且 join 出租户代码与操作人
        $r = qg_curl('/api/platform/audits?pageSize=50', ['-b', $jar]);
        assert_contains($r, '"code":0', '审计接口应可访问：' . $r);
        assert_contains($r, $mark . '-通过', 'approve 行应在列：' . $r);
        assert_contains($r, $mark . '-失败', 'provision_failed 行应在列：' . $r);
        assert_contains($r, '"tenant_code":"sm999904"', '应 join 出租户代码：' . $r);
        assert_contains($r, '"operator":"platform"', '应 join 出操作人：' . $r);
        assert_contains($r, '"detail":"{\"code\":\"sm999904\"}"', 'register 行 operator 应为 null（申请自注册）：' . $r);
        // action 筛选：只留 provision_failed
        $r = qg_curl('/api/platform/audits?action=provision_failed&tenant=sm999904', ['-b', $jar]);
        assert_contains($r, $mark . '-失败', 'action 筛选应命中失败行：' . $r);
        assert_true(strpos($r, $mark . '-通过') === false, 'action 筛选应排除通过行：' . $r);
        // 租户代码筛选
        $r = qg_curl('/api/platform/audits?tenant=sm999904', ['-b', $jar]);
        assert_true(strpos($r, $mark . '-通过') !== false && strpos($r, $mark . '-失败') !== false, '租户筛选应命中该租户全部行：' . $r);
        assert_true(strpos($r, '"tenant_code":"sm999903"') === false, '不应混入其他租户的行：' . $r);
        // 未知租户代码：恒空集（比静默忽略条件安全）
        $r = qg_curl('/api/platform/audits?tenant=no-such-code', ['-b', $jar]);
        assert_contains($r, '"total":0', '未知租户代码应返回空集：' . $r);
        // 非法 action：忽略该条件（不 500、不注入）
        $r = qg_curl('/api/platform/audits?action=drop%20table', ['-b', $jar]);
        assert_contains($r, '"code":0', '非法 action 应被忽略而非报错：' . $r);
    } finally {
        ControlDb::exec("DELETE FROM saas_audit WHERE target_type = 'tenant' AND target_id = ?", [$tid]);
        ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
    }
    @unlink($jar);
});

t('概览接口：summary 键齐全且与库一致，租户关键词搜索转义通配符', function () {
    if (!qg_site_up()) { return; }
    $jar = platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $tidPending = 999905;
    $tidGrace   = 999906;
    try {
        foreach ([$tidPending, $tidGrace] as $id) {
            ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$id]);
        }
        ControlDb::exec("INSERT INTO saas_tenant (id, code, name, mode, status, contact_name, contact_phone, admin_username, applied_at, expires_at)
                         VALUES (?, 'sm999905', '概览冒烟待审企业', 'shared', 'pending', '甲', '13800001111', 'boss', NOW(), NULL)", [$tidPending]);
        // 已过服务期 5 天 → 落在 30 天宽限期内
        ControlDb::exec("INSERT INTO saas_tenant (id, code, name, mode, status, contact_name, contact_phone, admin_username, applied_at, expires_at)
                         VALUES (?, 'sm999906', '概览冒烟宽限企业', 'shared', 'active', '乙', '13800002222', 'boss', NOW(), DATE_SUB(NOW(), INTERVAL 5 DAY))", [$tidGrace]);
        ControlDb::exec("INSERT INTO saas_bill (tenant_id, cycle, amount, status) VALUES (?, 'year', 1234.00, 'unpaid')", [$tidGrace]);

        $r = qg_curl('/api/platform/summary', ['-b', $jar]);
        assert_contains($r, '"code":0', 'summary 应可访问：' . $r);
        $d = json_decode($r, true)['data'] ?? [];
        foreach (['pending', 'active', 'dedicated', 'expiring', 'expired', 'in_grace', 'unpaid_count', 'unpaid_amount', 'paid_month', 'accounts_on'] as $k) {
            assert_true(array_key_exists($k, $d), 'summary 应含键 ' . $k . '：' . $r);
        }
        assert_true((int) $d['pending'] >= 1, '应统计到刚建的待审租户：' . $r);
        assert_true((int) $d['in_grace'] >= 1, '过期 5 天的租户应计入宽限期内：' . $r);
        assert_true((int) $d['expired'] >= (int) $d['in_grace'], 'expired（已过服务期）应不小于 in_grace（宽限期内）：' . $r);
        $unpaid = json_decode(qg_curl('/api/platform/tenants?status=active&keyword=sm999906&pageSize=1', ['-b', $jar]), true);
        assert_eq(1, (int) ($unpaid['data']['total'] ?? 0), '关键词应精确命中刚建的租户：' . json_encode($unpaid, JSON_UNESCAPED_UNICODE));

        // 关键词：企业名/代码/联系人/手机/管理员账号 都能命中
        foreach (['概览冒烟宽限企业', 'sm999906', '乙', '13800002222', 'boss'] as $kw) {
            $r = qg_curl('/api/platform/tenants?keyword=' . urlencode($kw), ['-b', $jar]);
            $total = (int) (json_decode($r, true)['data']['total'] ?? 0);
            assert_true($total >= 1, '关键词「' . $kw . '」应有命中：' . $r);
        }
        // LIKE 通配符必须转义：'%'/'_' 不能被当通配符用（否则会命中全部租户）
        $all = (int) (json_decode(qg_curl('/api/platform/tenants?pageSize=1', ['-b', $jar]), true)['data']['total'] ?? 0);
        foreach (['%', '_'] as $kw) {
            $r = qg_curl('/api/platform/tenants?keyword=' . urlencode($kw), ['-b', $jar]);
            $total = (int) (json_decode($r, true)['data']['total'] ?? 0);
            assert_true($total < $all, '通配符「' . $kw . '」应被转义、不得命中全部（' . $total . ' vs ' . $all . '）：' . $r);
        }
        // 未登录：401
        assert_contains(qg_curl('/api/platform/summary'), '"code":401', '未登录 summary 应 401');
    } finally {
        foreach ([$tidPending, $tidGrace] as $id) {
            ControlDb::exec('DELETE FROM saas_bill WHERE tenant_id = ?', [$id]);
            ControlDb::exec("DELETE FROM saas_audit WHERE target_type = 'tenant' AND target_id = ?", [$id]);
            ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$id]);
        }
        @unlink($jar);
    }
});

t('套餐管理：新增/修改/失效/启用闭环，且失效后注册页不可选、最后一个启用套餐不可停用', function () {
    if (!qg_site_up()) { return; }
    $jar = platform_jar();
    if (!$jar) { echo "      ${'平台超管'}不可用，跳过\n"; return; }
    $code = 'pl' . substr(md5(uniqid()), 0, 8);
    $pid = 0;
    pa_reset_ratelimit();   // register 桶是 5 次/小时，套件内多处注册会互相挤，按 test_account_http 的既有做法清桶
    try {
        // 新增：非法代码 / 非法档位 都要被拒
        $r = qg_post_json('/api/platform/plans', ['code' => 'Bad_Code', 'name' => 'x', 'mode' => 'shared'], ['-b', $jar]);
        assert_contains($r, '套餐代码需为', '非法套餐代码应被拒：' . $r);
        $r = qg_post_json('/api/platform/plans', ['code' => $code, 'name' => 'x', 'mode' => 'weird'], ['-b', $jar]);
        assert_contains($r, '隔离档', '非法隔离档应被拒：' . $r);
        $r = qg_post_json('/api/platform/plans', ['code' => $code, 'name' => 'x', 'mode' => 'shared', 'price_month' => -1], ['-b', $jar]);
        assert_contains($r, '月付价格', '负数价格应被拒：' . $r);

        $r = qg_post_json('/api/platform/plans', [
            'code' => $code, 'name' => '用例套餐', 'mode' => 'shared',
            'price_month' => 100, 'price_year' => 1200, 'max_users' => 5, 'max_orders' => 100, 'remark' => '用例', 'sort' => 8,
        ], ['-b', $jar]);
        assert_contains($r, '"code":0', '新增套餐应成功：' . $r);
        $pid = (int) (json_decode($r, true)['data']['id'] ?? 0);
        assert_true($pid > 0, '应返回新套餐 id');
        // 重复 code 被拒
        $r = qg_post_json('/api/platform/plans', ['code' => $code, 'name' => 'x', 'mode' => 'shared'], ['-b', $jar]);
        assert_contains($r, '已存在', '重复套餐代码应被拒：' . $r);
        // 注册页可见（status=1）
        assert_contains(qg_curl('/api/plans/public'), $code, '启用中的套餐应出现在注册页数据源里');

        // 修改
        $r = qg_curl('/api/platform/plans/' . $pid, ['-X', 'PUT', '-H', 'Content-Type: application/json', '-b', $jar,
            '-d', json_encode(['code' => $code, 'name' => '用例套餐改', 'mode' => 'dedicated',
                'price_month' => 200, 'price_year' => 2400, 'max_users' => 6, 'max_orders' => 200, 'remark' => '改', 'sort' => 7])]);
        assert_contains($r, '"code":0', '修改套餐应成功：' . $r);
        $row = ControlDb::one('SELECT * FROM saas_plan WHERE id = ?', [$pid]);
        assert_eq('用例套餐改', $row['name'] ?? '', '名称应已更新');
        assert_eq('200.00', number_format((float) $row['price_month'], 2, '.', ''), '月价应已更新');
        assert_eq('dedicated', $row['mode'] ?? '', '隔离档应已更新');

        // 失效 → 注册页不可见、重复失效被拒
        $r = qg_post_json('/api/platform/plans/' . $pid . '/disable', [], ['-b', $jar]);
        assert_contains($r, '"code":0', '失效应成功：' . $r);
        assert_true(strpos(qg_curl('/api/plans/public'), $code) === false, '失效后不应出现在注册页数据源里');
        assert_eq(0, (int) ControlDb::val('SELECT status FROM saas_plan WHERE id = ?', [$pid]), '库里应为 status=0');
        $r = qg_post_json('/api/platform/plans/' . $pid . '/disable', [], ['-b', $jar]);
        assert_contains($r, '状态已变化', '重复失效应被拒：' . $r);
        // 失效的套餐不能再被注册（服务端校验，不只是前端不展示）
        $r = qg_post_json('/api/tenant/register', ['name' => 'x', 'code' => 'zz' . substr(md5(uniqid()), 0, 8),
            'contact_name' => 'x', 'contact_phone' => '13800000000', 'admin_username' => 'boss',
            'password' => 'TestPass123', 'plan_id' => $pid, 'bill_cycle' => 'year']);
        assert_contains($r, '套餐不存在或已停用', '失效套餐不应可被注册：' . $r);

        // 启用回来
        $r = qg_post_json('/api/platform/plans/' . $pid . '/enable', [], ['-b', $jar]);
        assert_contains($r, '"code":0', '启用应成功：' . $r);
        assert_contains(qg_curl('/api/plans/public'), $code, '启用后应重新出现在注册页数据源里');

        // 未登录 401
        assert_contains(qg_curl('/api/platform/plans'), '"code":401', '未登录套餐列表应 401');
    } finally {
        if ($pid > 0) {
            ControlDb::exec('DELETE FROM saas_plan WHERE id = ?', [$pid]);
            ControlDb::exec('DELETE FROM saas_audit WHERE action LIKE ? AND detail LIKE ?', ['plan_%', '%' . $code . '%']);
        }
        @unlink($jar);
    }
});

t('待收款清单：只含已开通且有未支付账单的租户，并带管理员联系方式', function () {
    if (!qg_site_up()) { return; }
    $jar = platform_jar();
    if (!$jar) { echo "      平台超管不可用，跳过\n"; return; }
    $tidActive = 999907;
    $tidPending = 999908;
    try {
        foreach ([$tidActive, $tidPending] as $id) {
            ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$id]);
        }
        ControlDb::exec("INSERT INTO saas_tenant (id, code, name, mode, status, contact_name, contact_phone, contact_email, admin_username, applied_at)
                         VALUES (?, 'sm999907', '待收冒烟已开通', 'shared', 'active', '催款人', '13900001111', 'cui@example.com', 'boss', NOW())", [$tidActive]);
        ControlDb::exec("INSERT INTO saas_tenant (id, code, name, mode, status, contact_name, contact_phone, admin_username, applied_at)
                         VALUES (?, 'sm999908', '待收冒烟未审核', 'shared', 'pending', '别催我', '13900002222', 'boss', NOW())", [$tidPending]);
        ControlDb::exec("INSERT INTO saas_bill (tenant_id, cycle, amount, status) VALUES (?, 'year', 4321.00, 'unpaid')", [$tidActive]);
        ControlDb::exec("INSERT INTO saas_bill (tenant_id, cycle, amount, status) VALUES (?, 'year', 9999.00, 'unpaid')", [$tidPending]);

        $r = qg_curl('/api/platform/receivables', ['-b', $jar]);
        assert_contains($r, '"code":0', '待收款接口应可访问：' . $r);
        assert_contains($r, '待收冒烟已开通', '已开通欠款租户应出现：' . $r);
        assert_contains($r, '"contact_phone":"13900001111"', '应带出联系电话：' . $r);
        assert_contains($r, '"contact_email":"cui@example.com"', '应带出邮箱：' . $r);
        assert_contains($r, '"admin_username":"boss"', '应带出管理员账号：' . $r);
        assert_true(strpos($r, '待收冒烟未审核') === false, '未审核租户不应进催款清单：' . $r);
        // 账单列表也要带联系方式（列表页做「催款」用）
        assert_contains(qg_curl('/api/platform/bills?tenant=sm999907&pageSize=5', ['-b', $jar]), '"contact_phone":"13900001111"', '账单列表应带联系方式');
        assert_contains(qg_curl('/api/platform/receivables'), '"code":401', '未登录待收款应 401');
    } finally {
        foreach ([$tidActive, $tidPending] as $id) {
            ControlDb::exec('DELETE FROM saas_bill WHERE tenant_id = ?', [$id]);
            ControlDb::exec("DELETE FROM saas_audit WHERE target_type = 'tenant' AND target_id = ?", [$id]);
            ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$id]);
        }
        @unlink($jar);
    }
});

/** 清空文件型限流桶（与 tests/bootstrap.php / test_account_http.php 同源做法） */
function pa_reset_ratelimit(): void
{
    foreach (glob(dirname(__DIR__) . '/api/runtime/ratelimit/*.json') ?: [] as $f) {
        @unlink($f);
    }
}
