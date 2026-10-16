<?php
require_once __DIR__ . '/bootstrap.php';

use QG\TempPassword;

t('TempPassword：格式与字符集（无易混淆字符）', function () {
    $p = TempPassword::generate();
    assert_eq(12, strlen($p), '默认 12 位');
    assert_true((bool) preg_match('/^[A-HJ-NP-Za-hj-km-np-z2-9!@#$%^&*]{12}$/', $p), '字符集应排除 0O1lI：' . $p);
});

t('TempPassword：500 次生成无重复、长度可调、过短拒绝', function () {
    $seen = [];
    for ($i = 0; $i < 500; $i++) {
        $seen[TempPassword::generate()] = 1;
    }
    assert_eq(500, count($seen), '随机生成不应重复');
    assert_eq(16, strlen(TempPassword::generate(16)), '长度可调');
    $thrown = false;
    try { TempPassword::generate(7); } catch (Throwable $e) { $thrown = true; }
    assert_true($thrown, '过短长度应拒绝');
});
