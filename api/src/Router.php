<?php
namespace QG;

class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, bool $auth = true): void
    {
        $this->routes[] = [strtoupper($method), $pattern, $handler, $auth];
    }

    public function get(string $p, callable $h, bool $auth = true): void  { $this->add('GET', $p, $h, $auth); }
    public function post(string $p, callable $h, bool $auth = true): void { $this->add('POST', $p, $h, $auth); }
    public function put(string $p, callable $h, bool $auth = true): void  { $this->add('PUT', $p, $h, $auth); }
    public function del(string $p, callable $h, bool $auth = true): void  { $this->add('DELETE', $p, $h, $auth); }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $path   = '/' . trim($path, '/');

        foreach ($this->routes as [$m, $pattern, $handler, $auth]) {
            if ($m !== $method && $m !== 'ANY') {
                continue;
            }
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $path, $mm)) {
                continue;
            }
            $params = [];
            foreach ($mm as $k => $v) {
                if (!is_int($k)) {
                    $params[$k] = $v;
                }
            }
            if ($auth) {
                Auth::requireLogin();
            }
            try {
                $handler($params);
            } catch (\Throwable $e) {
                App::$errors[] = $e->getMessage();
                if (@App::config('app')['debug']) {
                    Response::fail($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), 500);
                }
                Response::fail('服务器错误：' . $e->getMessage(), 500);
            }
            return;
        }
        Response::fail('接口不存在：' . $method . ' ' . $path, 404, 404);
    }
}

class Req
{
    public static function all(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $raw  = file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $cache = array_merge($_GET, $_POST, is_array($json) ? $json : []);
        return $cache;
    }

    public static function input(string $key, $default = null)
    {
        $v = self::all()[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return $v === null ? '' : (string) $v;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function num(string $key, $default = null)
    {
        $v = self::input($key, $default);
        if ($v === '' || $v === null) {
            return $default;
        }
        return is_numeric($v) ? $v : $default;
    }

    /** 取日期：支持 2026-09-12 / 2026/09/12 / 时间戳；空返回 null */
    public static function date(string $key): ?string
    {
        $v = self::str($key);
        if ($v === '') {
            return null;
        }
        $v = str_replace('/', '-', $v);
        $t = strtotime($v);
        return $t ? date('Y-m-d', $t) : null;
    }

    public static function arr(string $key): array
    {
        $v = self::input($key, []);
        return is_array($v) ? $v : [];
    }

    public static function page(): array
    {
        $page = max(1, self::int('page', 1));
        $size = self::int('pageSize', App::pageSize());
        $size = $size > 0 && $size <= 500 ? $size : App::pageSize();
        return [$page, $size, ($page - 1) * $size];
    }

    public static function require_(string $key, string $label): string
    {
        $v = self::str($key);
        if ($v === '') {
            Response::fail($label . ' 不能为空');
        }
        return $v;
    }
}
