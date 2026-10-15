<?php
require_once __DIR__ . '/bootstrap.php';

// schema.sql 的 DDL 段必须与当前库（= 生产克隆 + 全部迁移）的 21 张表**逐列逐键一致**。
// 这是「每次表结构变更也要维护好 db/schema.sql」的自动化把闸：
//   改了 db/migration_*.sql 却忘了同步 db/schema.sql（或反过来）时，本用例会红。
//
// 做法：把文件里每条 CREATE TABLE 原样落到当前库里（表名加 __parity__ 前缀，
// IF NOT EXISTS 去掉），然后用 information_schema 逐表比对真实表与 parity 表的
// 列定义与索引。两边都来自 information_schema，口径天然一致（MariaDB 会把
// int 显示宽度渲染回来，所以无需再做归一）。finally 里清掉 parity 表。

t('db/schema.sql 的表集合与当前库一致', function () {
    $pdo = qg_test_pdo();
    $sql = file_get_contents(dirname(__DIR__) . '/db/schema.sql');
    assert_true(is_string($sql) && $sql !== '', '读不到 db/schema.sql');

    preg_match_all('/CREATE TABLE IF NOT EXISTS `(\w+)`\s*\((.*?)\n\)\s*ENGINE[^;]*;/s', $sql, $m, PREG_SET_ORDER);
    $declared = array_map(fn($r) => $r[1], $m);
    sort($declared);

    $live = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $live = array_values(array_filter($live, fn($t) => strpos($t, '__parity__') !== 0));
    sort($live);

    assert_eq($declared, $live,
        'db/schema.sql 声明的表集合 != 当前库的表集合（改了结构却没同步 schema.sql？）');
});

t('db/schema.sql 的每张表与当前库逐列、逐索引一致', function () {
    $pdo = qg_test_pdo();
    $sql = file_get_contents(dirname(__DIR__) . '/db/schema.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS `(\w+)`\s*\((.*?)\n\)\s*ENGINE[^;]*;/s', $sql, $m, PREG_SET_ORDER);

    $cols = function (string $tbl) use ($pdo): array {
        $st = $pdo->prepare(
            "SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, COLUMN_COMMENT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION"
        );
        $st->execute([$tbl]);
        return $st->fetchAll(PDO::FETCH_NUM);
    };
    $idx = function (string $tbl) use ($pdo): array {
        $st = $pdo->prepare(
            "SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              GROUP BY INDEX_NAME, NON_UNIQUE
              ORDER BY INDEX_NAME"
        );
        $st->execute([$tbl]);
        return $st->fetchAll(PDO::FETCH_NUM);
    };

    // 起点先清掉可能残留的 parity 表（上次测试中途挂了的话）
    foreach ($m as $r) {
        $pdo->exec('DROP TABLE IF EXISTS `__parity__' . $r[1] . '`');
    }
    try {
        foreach ($m as $r) {
            [$t, $stmt] = [$r[1], $r[0]];
            $parity = preg_replace(
                '/CREATE TABLE IF NOT EXISTS `' . preg_quote($t, '/') . '`/',
                'CREATE TABLE `__parity__' . $t . '`',
                $stmt
            );
            $pdo->exec($parity);   // 建不出来 = DDL 本身就有语法/类型问题，直接红
        }
        foreach ($m as $r) {
            $t = $r[1];
            assert_eq($cols($t), $cols("__parity__$t"), "$t 的列结构与 db/schema.sql 不一致");
            assert_eq($idx($t), $idx("__parity__$t"), "$t 的索引与 db/schema.sql 不一致");
        }
    } finally {
        foreach ($m as $r) {
            $pdo->exec('DROP TABLE IF EXISTS `__parity__' . $r[1] . '`');
        }
    }
});
