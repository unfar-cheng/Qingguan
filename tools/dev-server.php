<?php
// 本机开发用路由：php83 -S 127.0.0.1:8080 -t <项目根> tools/dev-server.php
// /api/* 交给 api/index.php，其余交给内置服务器当静态文件。
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($uri, '/api/')) {
    $_SERVER['PATH_INFO'] = substr($uri, 4);
    require dirname(__DIR__) . '/api/index.php';
    return true;
}

return false;
