<?php
// 部署时复制本文件为 config.php，并按环境填写。config.php 已在 .gitignore 中，不会入库。
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'qingguan',
        'user'    => 'qingguan',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    // 控制面库（租户/套餐/平台管理员/平台审计），与业务库分开
    'control_db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'qingguan_control',
        'user'    => 'qingguan',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    // 专用档开通专用（二期）：能建 qingguan_t_% 库与 qg_% 账号，别的一概不能。
    // 缺失或 pass 为空时，专用档审核通过会报「未配置」并保持 pending，共享档完全不受影响。
    'provision_db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'qg_provision',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'secret'       => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
        'token_ttl'    => 86400 * 7,
        'upload_dir'   => dirname(__DIR__, 2) . '/uploads',
        'upload_url'   => '/uploads',
        'max_upload'   => 50 * 1024 * 1024,
        'allow_ext'    => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'txt'],
        'page_size'    => 50,
        'debug'        => false,
    ],
];
