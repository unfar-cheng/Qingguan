<?php
require_once __DIR__ . '/bootstrap.php';

use QG\Billing;

const T0 = 1792152000; // 2026-10-16 12:00:00 UTC 对应的固定时钟

t('Billing::state：none/normal/soon/grace/expired 五态', function () {
    assert_eq('none', Billing::state(null, false)['state']);
    assert_eq('normal', Billing::state('2027-03-01 00:00:00', false, T0)['state'], '剩余>30天');
    $s = Billing::state(date('Y-m-d H:i:s', T0 + 15 * 86400), false, T0);
    assert_eq('soon', $s['state']);
    assert_eq(15, $s['days_left'], '剩余天数按自然日');
    $s = Billing::state(date('Y-m-d H:i:s', T0 - 10 * 86400), false, T0);
    assert_eq('grace', $s['state']);
    assert_true($s['trial'], '无付款记录的宽限 = 试用');
    assert_eq(20, $s['days_left'], '宽限剩余 = 距宽限截止');
    assert_eq('grace', Billing::state(date('Y-m-d H:i:s', T0 - 10 * 86400), true, T0)['state']);
    assert_true(!Billing::state(date('Y-m-d H:i:s', T0 - 10 * 86400), true, T0)['trial'], '有付款记录 = 非试用');
    assert_eq('expired', Billing::state(date('Y-m-d H:i:s', T0 - 31 * 86400), true, T0)['state'], '超宽限');
});

t('Billing::state：边界（恰好到期日、宽限最后一天）', function () {
    assert_eq('soon', Billing::state(date('Y-m-d H:i:s', T0), false, T0)['state'], '到期时刻=now 仍是临期(0天)');
    assert_eq('grace', Billing::state(date('Y-m-d H:i:s', T0 - 30 * 86400), true, T0)['state'], '宽限最后一天');
    assert_eq('expired', Billing::state(date('Y-m-d H:i:s', T0 - 30 * 86400 - 1), true, T0)['state']);
});

t('Billing::isExpired：拦截判定与账单无关', function () {
    assert_true(!Billing::isExpired(null));
    assert_true(!Billing::isExpired(date('Y-m-d H:i:s', T0 - 10 * 86400)), '宽限内不拦');
    assert_true(Billing::isExpired(date('Y-m-d H:i:s', T0 - 31 * 86400)), '超宽限拦截');
});

t('Billing::nextExpiry：三分支重算', function () {
    // 分支1：未到期续费 → 旧到期日 + 周期（到期日在 T0 之后才算「未到期」）
    assert_eq('2028-01-15 00:00:00', Billing::nextExpiry('year', '2027-01-15 00:00:00', T0));
    // 分支2：宽限内（含试用，expires_at=审核日）→ 旧到期日 + 周期（T0-11 天，仍在 30 天宽限内；试用期白送）
    assert_eq('2026-11-05 00:00:00', Billing::nextExpiry('month', '2026-10-05 00:00:00', T0));
    assert_eq('2027-10-05 00:00:00', Billing::nextExpiry('year', '2026-10-05 00:00:00', T0));
    // 分支3：已超宽限 → 付款日起算（now+周期，月付落在 T0 的下个月）
    $r = Billing::nextExpiry('month', '2026-08-01 00:00:00', T0);
    assert_true(strpos($r, date('Y-m', T0 + 16 * 86400)) === 0, '超宽限按 now+周期起算：' . $r);
    // NULL 到期（不应发生，兜底）→ now 起算
    assert_true(Billing::nextExpiry('month', null, T0) !== '');
    // 非法周期
    $thrown = false;
    try { Billing::nextExpiry('week', '2026-01-15 00:00:00', T0); } catch (Throwable $e) { $thrown = true; }
    assert_true($thrown, '非法周期应拒绝');
});
