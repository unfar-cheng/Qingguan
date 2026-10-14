<?php
// 极简断言库：保持"零依赖"，不引入 composer/PHPUnit。
$GLOBALS['__tests'] = [];

function t(string $name, callable $fn): void
{
    $GLOBALS['__tests'][$name] = $fn;
}

function assert_true($cond, string $msg = ''): void
{
    if (!$cond) { throw new RuntimeException('assert_true 失败：' . $msg); }
}

function assert_eq($a, $b, string $msg = ''): void
{
    if ($a !== $b) {
        throw new RuntimeException('assert_eq 失败：' . $msg
            . '（' . var_export($a, true) . ' !== ' . var_export($b, true) . '）');
    }
}

function assert_contains(string $hay, string $needle, string $msg = ''): void
{
    if (strpos($hay, $needle) === false) {
        throw new RuntimeException('assert_contains 失败：' . $msg);
    }
}
