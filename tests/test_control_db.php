<?php
require_once __DIR__ . '/bootstrap.php';

t('ControlDb 能连上控制面并读到套餐', function () {
    $rows = QG\ControlDb::all('SELECT code, mode FROM saas_plan ORDER BY sort');
    assert_true(count($rows) >= 2, '套餐至少 2 条');
    assert_eq($rows[0]['code'], 'basic');
});
