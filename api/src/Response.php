<?php
namespace QG;

class Response
{
    public static function json($data, int $http = 200): void
    {
        if (!headers_sent()) {
            http_response_code($http);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok($data = null, string $msg = 'ok'): void
    {
        self::json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    public static function page(array $rows, int $total, int $page, int $pageSize): void
    {
        self::ok(['list' => $rows, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize]);
    }

    public static function fail(string $msg, int $code = 1, int $http = 200): void
    {
        self::json(['code' => $code, 'msg' => $msg, 'data' => null], $http);
    }

    public static function needLogin(): void
    {
        self::json(['code' => 401, 'msg' => '登录已过期，请重新登录', 'data' => null]);
    }
}
