<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Shanghai');

$root = __DIR__;

spl_autoload_register(function (string $class) use ($root): void {
    $prefix = 'QG\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $root . '/src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require $root . '/src/App.php';
require $root . '/src/Response.php';

\QG\App::config();

// 运行期警告也返回 JSON，避免前端拿到 HTML
set_exception_handler(function (\Throwable $e): void {
    \QG\Response::fail('服务器异常：' . $e->getMessage(), 500, 500);
});
set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new \ErrorException($str, 0, $no, $file, $line);
});

$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '') {
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $pos  = strpos($uri, '/api/');
    $path = $pos !== false ? substr($uri, $pos + 4) : '/';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && isset($_POST['_method'])) {
    $method = strtoupper((string) $_POST['_method']);
}

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new \QG\Router();

\QG\controllers\AuthController::routes($router);
\QG\controllers\OrderController::routes($router);
\QG\controllers\PlanController::routes($router);
\QG\controllers\OrgController::routes($router);
\QG\controllers\FinanceController::routes($router);
\QG\controllers\SystemController::routes($router);
\QG\controllers\UploadController::routes($router);

$router->dispatch($method, $path);
