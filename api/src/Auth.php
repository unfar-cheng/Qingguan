<?php
namespace QG;

use QG\repos\PermissionRepo;
use QG\repos\UserRepo;

class Auth
{
    private const COOKIE = 'qg_token';
    private static ?array $user = null;

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/'));
    }

    public static function sign(array $payload): string
    {
        $body = self::b64(json_encode($payload));
        $sig  = self::b64(hash_hmac('sha256', $body, App::secret(), true));
        return $body . '.' . $sig;
    }

    public static function verify(?string $token): ?array
    {
        if (!$token || strpos($token, '.') === false) {
            return null;
        }
        [$body, $sig] = explode('.', $token, 2);
        $expect = self::b64(hash_hmac('sha256', $body, App::secret(), true));
        if (!hash_equals($expect, $sig)) {
            return null;
        }
        $data = json_decode(self::unb64($body), true);
        if (!is_array($data) || (empty($data['uid']) && empty($data['puid']))) {
            return null;
        }
        if (!empty($data['exp']) && $data['exp'] < time()) {
            return null;
        }
        return $data;
    }

    public static function login(array $user, array $tenant): void
    {
        $ttl = (int) App::config('app')['token_ttl'];
        $token = self::sign([
            'scope' => 'tenant',
            'tid'   => (int) $tenant['id'],
            'uid'   => (int) $user['id'],
            'uname' => $user['username'],
            'exp'   => time() + $ttl,
        ]);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + $ttl,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);
        $_COOKIE[self::COOKIE] = $token;
        Tenant::set(['id' => (int) $tenant['id'], 'mode' => $tenant['mode'], 'db_name' => $tenant['db_name']]);
        unset($user['password_hash']);
        self::$user = $user;
    }

    public static function logout(): void
    {
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        unset($_COOKIE[self::COOKIE]);
        self::$user = null;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user ?: null;
        }
        $payload = self::verify($_COOKIE[self::COOKIE] ?? null);
        if (!$payload) {
            self::$user = [];
            return null;
        }

        if (($payload['scope'] ?? '') === 'platform') {
            $u = ControlDb::one('SELECT * FROM saas_platform_user WHERE id = ? AND status = 1 LIMIT 1',
                [(int) ($payload['puid'] ?? 0)]);
            if (!$u) {
                self::$user = [];
                return null;
            }
            unset($u['password_hash']);
            $u['scope'] = 'platform';
            self::$user = $u;
            return $u;
        }

        // 租户侧会话：先据 tid 解析租户并建好数据面上下文，再取用户
        $t = ControlDb::one('SELECT * FROM saas_tenant WHERE id = ? LIMIT 1', [(int) ($payload['tid'] ?? 0)]);
        if (!$t || $t['status'] !== 'active') {
            self::$user = [];
            return null;
        }
        Tenant::set(['id' => (int) $t['id'], 'mode' => $t['mode'], 'db_name' => $t['db_name'], 'code' => $t['code']]);

        $u = UserRepo::findWithRole((int) $payload['uid']);
        if (!$u || (int) $u['status'] !== 1) {
            self::$user = [];
            return null;
        }
        unset($u['password_hash']);
        $u['scope']       = 'tenant';
        $u['tenant_code'] = $t['code'];
        $u['tenant_name'] = $t['name'];
        $u['tenant_logo'] = $t['logo_url'];
        self::$user = $u;
        return $u;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): array
    {
        $u = self::user();
        if (!$u) {
            Response::needLogin();
        }
        return $u;
    }

    public static function isTenantAdmin(): bool
    {
        $u = self::user();
        return $u && ($u['role_code'] ?? '') === 'tenant_admin';
    }

    /** 当前用户可访问的权限 code 列表；租户系统管理员返回 ['*'] */
    public static function permissionCodes(): array
    {
        $u = self::user();
        if (!$u || ($u['scope'] ?? 'tenant') === 'platform') {
            return [];
        }
        if (($u['role_code'] ?? '') === 'tenant_admin') {
            return ['*'];
        }
        return PermissionRepo::codesForRole((int) $u['role_id']);
    }

    public static function can(string $code): bool
    {
        $codes = self::permissionCodes();
        return in_array('*', $codes, true) || in_array($code, $codes, true);
    }

    /** 平台管理员登录：签发 scope=platform 的令牌（payload 用 puid，无租户上下文） */
    public static function platformLogin(array $puser): void
    {
        $ttl = (int) App::config('app')['token_ttl'];
        $token = self::sign([
            'scope' => 'platform',
            'puid'  => (int) $puser['id'],
            'uname' => $puser['username'],
            'exp'   => time() + $ttl,
        ]);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + $ttl,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);
        $_COOKIE[self::COOKIE] = $token;
        unset($puser['password_hash']);
        $puser['scope'] = 'platform';
        self::$user = $puser;
    }

    /** 要求平台管理员：未登录 401；登录了但是租户令牌 403（两边令牌互不通用） */
    public static function requirePlatform(): array
    {
        $u = self::requireLogin();
        if (($u['scope'] ?? 'tenant') !== 'platform') {
            Response::fail('需要平台管理员权限', 403, 403);
        }
        return $u;
    }
}
