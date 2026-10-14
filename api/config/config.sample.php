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
