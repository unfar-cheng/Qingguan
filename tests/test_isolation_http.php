<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/http.php';

/**
 * 租户隔离的 HTTP 级总测：造第二个租户的账号与订单，用真实会话打真实接口，
 * 验证「A 租户看不到、也取不到 B 租户的数据」。
 * 需要站点在跑（见 tools/dev-server.php），否则整条跳过。
 */

function qg_login(string $tenant, string $username, string $password): ?string
{
    $jar = tempnam(sys_get_temp_dir(), 'qgck');
    $r = qg_post_json('/api/auth/login', ['tenant' => $tenant, 'username' => $username, 'password' => $password],
        ['-c', $jar]);
    if (strpos($r, '"code":0') === false) {
        @unlink($jar);
        return null;
    }
    return $jar;
}

/** 建好租户 2（控制面）+ 它自己的角色/账号（数据面），返回 tenant id */
function qg_ensure_tenant2(): int
{
    $t = QG\ControlDb::one('SELECT * FROM saas_tenant WHERE code = ? LIMIT 1', ['iso2']);
    if (!$t) {
        $id = QG\ControlDb::insert('saas_tenant', [
            'code' => 'iso2', 'name' => '隔离测试租户', 'mode' => 'shared', 'status' => 'active',
            'applied_at' => date('Y-m-d H:i:s'), 'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        $t = QG\ControlDb::one('SELECT * FROM saas_tenant WHERE id = ?', [$id]);
    }
    $tid = (int) $t['id'];
    QG\Tenant::set(['id' => $tid, 'mode' => 'shared', 'db_name' => '']);

    $roleId = QG\Db::val('SELECT id FROM sys_role WHERE tenant_id = ? AND code = ?', [$tid, 'tenant_admin']);
    if (!$roleId) {
        $roleId = QG\Db::insert('sys_role', [
            'tenant_id' => $tid, 'name' => '租户系统管理员', 'code' => 'tenant_admin', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    $uid = QG\Db::val('SELECT id FROM sys_user WHERE tenant_id = ? AND username = ?', [$tid, 'admin']);
    if (!$uid) {
        QG\Db::insert('sys_user', [
            'tenant_id' => $tid, 'username' => 'admin', 'real_name' => '隔离测试管理员',
            'password_hash' => password_hash('admin123', PASSWORD_DEFAULT), 'role_id' => (int) $roleId,
            'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    return $tid;
}

function qg_make_order(int $tid, string $no): int
{
    QG\Tenant::set(['id' => $tid, 'mode' => 'shared', 'db_name' => '']);
    $id = QG\Db::val('SELECT id FROM biz_order WHERE tenant_id = ? AND order_no = ?', [$tid, $no]);
    if ($id) { return (int) $id; }
    return QG\Db::insert('biz_order', [
        'tenant_id' => $tid, 'order_no' => $no, 'mbl_no' => 'MBL-' . $no, 'customer_no' => 'ISO', 'created_at' => date('Y-m-d H:i:s'),
    ]);
}

t('HTTP 级：两个租户互不可见、跨租户取数被拒', function () {
    if (!qg_site_up()) {
        echo "      站点未启动，跳过（见 ops/apache/ 里的 vhost）\n";
        return;
    }

    $t2 = qg_ensure_tenant2();
    $o1 = qg_make_order(1, 'ISO-HTTP-1');
    $o2 = qg_make_order($t2, 'ISO-HTTP-2');

    $jar1 = qg_login('demo', 'admin', 'admin123');
    $jar2 = qg_login('iso2', 'admin', 'admin123');
    assert_true($jar1 !== null, '租户 1 登录失败');
    assert_true($jar2 !== null, '租户 2 登录失败');

    try {
        $list1 = qg_curl('/api/orders?pageSize=100', ['-b', $jar1]);
        $list2 = qg_curl('/api/orders?pageSize=100', ['-b', $jar2]);

        assert_contains($list1, 'ISO-HTTP-1', '租户 1 应看到自己的订单');
        assert_true(strpos($list1, 'ISO-HTTP-2') === false, '租户 1 不应看到租户 2 的订单');
        assert_contains($list2, 'ISO-HTTP-2', '租户 2 应看到自己的订单');
        assert_true(strpos($list2, 'ISO-HTTP-1') === false, '租户 2 不应看到租户 1 的订单');

        $cross = qg_curl('/api/orders/' . $o2, ['-b', $jar1]);
        assert_contains($cross, '订单不存在', '租户 1 拿租户 2 的订单 id 应取不到：' . $cross);

        $crossLogs = qg_curl('/api/orders/' . $o1 . '/logs', ['-b', $jar2]);
        assert_eq(trim($crossLogs), '{"code":0,"msg":"ok","data":[]}', '租户 2 读租户 1 的订单日志应为空');
    } finally {
        foreach ([$jar1, $jar2] as $jar) { if ($jar) { @unlink($jar); } }
        QG\Tenant::set(['id' => $t2, 'mode' => 'shared', 'db_name' => '']);
        QG\Db::exec('DELETE FROM biz_order WHERE order_no IN (?, ?)', ['ISO-HTTP-1', 'ISO-HTTP-2']);
        QG\Db::exec('DELETE FROM sys_user WHERE tenant_id = ?', [$t2]);
        QG\Db::exec('DELETE FROM sys_role WHERE tenant_id = ?', [$t2]);
        QG\ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$t2]);
        QG\Tenant::set(null);
    }
});
