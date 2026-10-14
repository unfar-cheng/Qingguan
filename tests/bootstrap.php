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
