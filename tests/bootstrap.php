<?php
require_once __DIR__ . '/lib.php';
$root = dirname(__DIR__) . '/api';
spl_autoload_register(function (string $class) use ($root): void {
    $prefix = 'QG\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $root . '/src/' . $rel . '.php';
    if (is_file($file)) { require $file; }
});
require $root . '/src/App.php';
require $root . '/src/Response.php';

function qg_test_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $c = QG\App::config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function qg_test_val(string $sql, array $bind = [])
{
    $st = qg_test_pdo()->prepare($sql);
    $st->execute($bind);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

// 测试套件运行前清掉本机限流计数：register 5/h、platform-login 与 tenant-login 10~20/10min
// 都会被一次全量套件耗尽，不清桶则 1 小时内重跑必 429 红。只影响开发脚手架——
// 生产部署的 api/runtime 独立、限流逻辑分毫未动，这不是在破坏限流。
foreach (glob(dirname(__DIR__) . '/api/runtime/ratelimit/*.json') ?: [] as $__rl_file) {
    @unlink($__rl_file);
}
