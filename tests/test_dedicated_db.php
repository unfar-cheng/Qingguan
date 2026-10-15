<?php
require_once __DIR__ . '/bootstrap.php';

use QG\App;
use QG\DedicatedDb;
use QG\Tenant;

t('DedicatedDb：命名与派生格式', function () {
    assert_eq('qingguan_t_demo', DedicatedDb::name('demo'));
    assert_eq('qg_demo', DedicatedDb::user('demo'));
    assert_eq(64, strlen(DedicatedDb::pass('demo')), '派生密码应为 64 位 hex');
    assert_true((bool) preg_match('/^[0-9a-f]{64}$/', DedicatedDb::pass('demo')), '派生密码应为小写 hex');
});

t('DedicatedDb：同 code 派生稳定、不同 code 派生不同', function () {
    assert_eq(DedicatedDb::pass('demo'), DedicatedDb::pass('demo'));
    assert_true(DedicatedDb::pass('demo') !== DedicatedDb::pass('demo2'), '不同 code 密码必须不同');
    assert_true(DedicatedDb::name('a-b') !== DedicatedDb::name('ab'), '派生不归一化：连字符不消失，a-b 与 ab 是不同库');
});

t('DedicatedDb：非法 code 拒绝', function () {
    foreach (['', 'A', '-x', 'a_b', 'a_b!!', str_repeat('a', 31)] as $bad) {
        $thrown = false;
        try { DedicatedDb::name($bad); } catch (Throwable $e) { $thrown = true; }
        assert_true($thrown, "code 「{$bad}」应被拒绝");
    }
});

t('DedicatedDb：provision_db 未配置时抛可读异常', function () {
    $orig = App::config();
    $cfg = $orig;
    unset($cfg['provision_db']);
    App::overrideConfig($cfg);
    try {
        DedicatedDb::provisionPdo();
        assert_true(false, '应抛异常');
    } catch (RuntimeException $e) {
        assert_contains($e->getMessage(), '未配置');
    } finally {
        App::overrideConfig($orig);
    }
});

t('Tenant::pdo 专用档：上下文缺少 code 抛明确异常', function () {
    Tenant::resetPools();
    Tenant::set(['id' => 9002, 'mode' => 'dedicated', 'db_name' => 'qingguan_t_9002']);
    try {
        Tenant::pdo();
        assert_true(false, '应抛异常');
    } catch (RuntimeException $e) {
        assert_contains($e->getMessage(), '缺少 code');
    } finally {
        Tenant::set(null);
        Tenant::resetPools();
    }
});

t('Tenant::pdo 专用档：带 code 时走派生凭据连接（不存在的库→PDO 异常）', function () {
    Tenant::resetPools();
    Tenant::set(['id' => 9002, 'mode' => 'dedicated', 'db_name' => 'qingguan_t_9002', 'code' => '9002']);
    try {
        Tenant::pdo();
        assert_true(false, 'qingguan_t_9002 不存在，连接应失败');
    } catch (PDOException $e) {
        assert_true(true, 'PDO 连接异常 = 已走到专用连接路径（真库连通性在任务 7 e2e 验证）');
    } finally {
        Tenant::set(null);
        Tenant::resetPools();
    }
});
