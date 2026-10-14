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
