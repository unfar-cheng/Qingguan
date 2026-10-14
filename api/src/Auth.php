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
        if (!is_array($data) || empty($data['uid'])) {
            return null;
        }
        if (!empty($data['exp']) && $data['exp'] < time()) {
            return null;
        }
        return $data;
    }

    public static function login(array $user): void
    {
        $ttl = (int) App::config('app')['token_ttl'];
        $token = self::sign(['uid' => (int) $user['id'], 'uname' => $user['username'], 'exp' => time() + $ttl]);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + $ttl,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);
        $_COOKIE[self::COOKIE] = $token;
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
        $u = UserRepo::findWithRole((int) $payload['uid']);
        if (!$u || (int) $u['status'] !== 1) {
            self::$user = [];
            return null;
        }
        unset($u['password_hash']);
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

    public static function isSuper(): bool
    {
        $u = self::user();
        return $u && $u['role_code'] === 'super';
    }

    /** 当前用户可访问的权限 code 列表；超管返回 ['*'] */
    public static function permissionCodes(): array
    {
        $u = self::user();
        if (!$u) {
            return [];
        }
        if ($u['role_code'] === 'super') {
            return ['*'];
        }
        return PermissionRepo::codesForRole((int) $u['role_id']);
    }

    public static function can(string $code): bool
    {
        $codes = self::permissionCodes();
        return in_array('*', $codes, true) || in_array($code, $codes, true);
    }
}
