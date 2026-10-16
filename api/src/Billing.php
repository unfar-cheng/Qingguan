<?php
namespace QG;

/**
 * 服务期计算（纯函数，不碰 DB）。
 * 状态机与三分支重算规则见 docs/superpowers/specs/2026-10-16-billing-expiry-design.md §3。
 * 约定：试用 = 「无 paid 账单 + expires_at=审核日」的宽限态，不落状态列。
 */
class Billing
{
    public const GRACE_DAYS = 30;

    /** @return array{state:string, days_left:int, trial:bool} state: none|normal|soon|grace|expired */
    public static function state(?string $expiresAt, bool $hasPaid, int $now = null): array
    {
        if ($expiresAt === null || $expiresAt === '') {
            return ['state' => 'none', 'days_left' => 0, 'trial' => false];
        }
        $now = $now ?: time();
        $exp = strtotime($expiresAt);
        $graceEnd = $exp + self::GRACE_DAYS * 86400;
        if ($now > $graceEnd) {
            return ['state' => 'expired', 'days_left' => 0, 'trial' => false];
        }
        if ($now > $exp) {
            return ['state' => 'grace', 'days_left' => self::daysBetween($now, $graceEnd), 'trial' => !$hasPaid];
        }
        $left = self::daysBetween($now, $exp);
        return ['state' => $left <= self::GRACE_DAYS ? 'soon' : 'normal', 'days_left' => $left, 'trial' => false];
    }

    /** 登录/会话拦截判定：仅看是否超宽限，与账单无关（trial 语义不参与）。$now 供测试注入固定时钟。 */
    public static function isExpired(?string $expiresAt, int $now = null): bool
    {
        return self::state($expiresAt, true, $now)['state'] === 'expired';
    }

    /** 到期日三分支重算（确认收款时调用）：未到期/宽限内 → 旧到期日+周期；超宽限 → now+周期 */
    public static function nextExpiry(string $cycle, ?string $expiresAt, int $now): string
    {
        if (!in_array($cycle, ['month', 'year'], true)) {
            throw new \RuntimeException('未知付费周期：' . $cycle);
        }
        $exp = ($expiresAt !== null && $expiresAt !== '') ? strtotime($expiresAt) : false;
        $base = ($exp !== false && $now <= $exp + self::GRACE_DAYS * 86400) ? $exp : $now;
        return date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s', $base) . ' ' . ($cycle === 'month' ? '+1 month' : '+1 year')));
    }

    private static function daysBetween(int $from, int $to): int
    {
        return $to <= $from ? 0 : (int) ceil(($to - $from) / 86400);
    }
}
