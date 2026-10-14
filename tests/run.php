<?php
require __DIR__ . '/lib.php';

foreach (glob(__DIR__ . '/test_*.php') as $f) {
    require $f;
}

$pass = 0; $fail = 0;
foreach ($GLOBALS['__tests'] as $name => $fn) {
    try {
        $fn();
        echo "PASS  {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "FAIL  {$name}\n      " . $e->getMessage() . "\n";
        $fail++;
    }
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
