<?php
namespace QG;

/**
 * 专用档（dedicated）租户库的命名与凭据派生。
 * 密码 = HMAC-SHA256('db-pass:<code>', app.secret) 的 hex，现算现用、不落任何存储；
 * app.secret 轮换会使全部专用库账号密码失效——按 AGENTS.md 的重置口径用 ALTER USER 归位。
 * code 合法字符集与注册接口一致（2-30 位 [a-z0-9-]），含连字符的标识符在 DDL 里必须反引号包裹。
 */
class DedicatedDb
{
    /** 库名：qingguan_t_<code> */
    public static function name(string $code): string
    {
        self::assertCode($code);
        return 'qingguan_t_' . $code;
    }

    /** 库账号：qg_<code>（仅授权本库，见 Provisioner 的 GRANT） */
    public static function user(string $code): string
    {
        self::assertCode($code);
        return 'qg_' . $code;
    }

    /** 派生密码：64 位小写 hex */
    public static function pass(string $code): string
    {
        self::assertCode($code);
        return hash_hmac('sha256', 'db-pass:' . $code, App::secret());
    }

    /** 开通专用连接（config 的 provision_db 段）；未配置时抛可读异常，shared 路径完全不读取该段 */
    public static function provisionPdo(): \PDO
    {
        $c = App::config('provision_db');
        if (!$c || empty($c['pass'])) {
            throw new \RuntimeException('专用档开通未配置：config.php 缺少 provision_db 段（host/port/user/pass）');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], $c['port'], $c['charset'] ?? 'utf8mb4');
        return new \PDO($dsn, $c['user'], $c['pass'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    private static function assertCode(string $code): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,29}$/', $code)) {
            throw new \RuntimeException('非法租户代码：' . $code);
        }
    }
}
