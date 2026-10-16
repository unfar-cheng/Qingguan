<?php
// 限流桶由 tests/bootstrap.php 在套件启动时自动清空，可重复跑。
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/bootstrap.php';

use QG\ControlDb;

t('公开套餐列表可访问且含 basic', function () {
    if (!qg_site_up()) { return; }
    $r = qg_curl('/api/plans/public');
    assert_contains($r, '"code":0', 'plans/public 应公开可取：' . $r);
    assert_contains($r, 'basic', '应包含 basic 套餐：' . $r);
    assert_true(strpos($r, 'price_month') !== false, '应带价格字段');
});

t('租户代码可用性校验：demo 已占用、非法格式被拒', function () {
    if (!qg_site_up()) { return; }
    $r = qg_curl('/api/tenant/code-check?code=demo');
    assert_contains($r, '"available":false', 'demo 应已占用：' . $r);
    $r = qg_curl('/api/tenant/code-check?code=' . urlencode('Bad_Code'));
    assert_contains($r, '"available":false', '非法格式不可用：' . $r);
});

t('注册校验：密码太短 / 联系方式全空 / 重复代码 被拒', function () {
    if (!qg_site_up()) { return; }
    $base = ['name' => '校验测试', 'code' => 'chk' . substr(md5(uniqid()), 0, 8),
        'contact_name' => '张三', 'admin_username' => 'boss', 'plan_id' => 1, 'bill_cycle' => 'year'];
    $r = qg_post_json('/api/tenant/register', $base + ['contact_phone' => '13800000000', 'password' => 'short']);
    assert_contains($r, '密码至少 8 位', '短密码应被拒：' . $r);
    $r = qg_post_json('/api/tenant/register', $base + ['password' => 'LongEnough1']);
    assert_contains($r, '至少填一个', '联系方式全空应被拒：' . $r);
    $r = qg_post_json('/api/tenant/register', ['code' => 'demo'] + $base + ['contact_phone' => '13800000000', 'password' => 'LongEnough1']);
    assert_contains($r, '已被占用', '重复代码应被拒：' . $r);
});

t('合法注册落 pending，且登录提示审核中', function () {
    if (!qg_site_up()) { return; }
    $code = 'reg' . substr(md5(uniqid()), 0, 8);
    $r = qg_post_json('/api/tenant/register', [
        'name' => '注册测试企业', 'code' => $code, 'contact_name' => '李四',
        'contact_email' => 'test@example.com', 'admin_username' => 'boss',
        'password' => 'RegPass123', 'plan_id' => 1, 'bill_cycle' => 'year',
    ]);
    assert_contains($r, '"code":0', '注册应成功：' . $r);
    $row = ControlDb::one('SELECT * FROM saas_tenant WHERE code = ?', [$code]);
    assert_eq('pending', $row['status'] ?? '', '应落 pending');
    assert_true(password_verify('RegPass123', $row['admin_password_hash']), '密码应已哈希暂存');
    $r = qg_post_json('/api/auth/login', ['tenant' => $code, 'username' => 'boss', 'password' => 'RegPass123']);
    assert_contains($r, '审核中', 'pending 租户登录应提示审核中：' . $r);
    $b = ControlDb::one('SELECT cycle, amount, status, remark FROM saas_bill WHERE tenant_id = ? ORDER BY id DESC LIMIT 1', [(int) $row['id']]);
    assert_eq('year', $b['cycle'] ?? '', '注册应生成第一张账单');
    assert_eq('unpaid', $b['status'] ?? '', '首张账单待支付');
    // 清理
    ControlDb::exec('DELETE FROM saas_bill WHERE tenant_id = ?', [(int) $row['id']]);
    ControlDb::exec('DELETE FROM saas_audit WHERE target_type = ? AND target_id = ?', ['tenant', (int) $row['id']]);
    ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [(int) $row['id']]);
});

t('code-check 合法未占用代码 available=true', function () {
    if (!qg_site_up()) { return; }
    $r = qg_curl('/api/tenant/code-check?code=ok-code1');
    assert_contains($r, '"available":true', '合法未占用代码应可用：' . $r);
});
