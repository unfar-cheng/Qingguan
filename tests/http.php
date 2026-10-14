<?php
// HTTP 小工具：调用 curl 命令行而不是 PHP 的 curl 扩展
// （生产机上 `php-curl` 未必装了，扩展缺失会让整个用例炸掉）。

function qg_base(): string
{
    return getenv('QG_BASE') ?: 'http://127.0.0.1:8080';
}

/** 打一个接口，$args 是额外透传给 curl 的参数（如 Cookie 文件） */
function qg_curl(string $path, array $args = []): string
{
    $parts = array_map('escapeshellarg', array_merge(['-sS', '-m', '10'], $args));
    $cmd = 'curl ' . implode(' ', $parts) . ' ' . escapeshellarg(qg_base() . $path) . ' 2>/dev/null';
    return (string) shell_exec($cmd);
}

function qg_post_json(string $path, array $body, array $args = []): string
{
    return qg_curl($path, array_merge(
        ['-X', 'POST', '-H', 'Content-Type: application/json', '-d', json_encode($body, JSON_UNESCAPED_UNICODE)],
        $args
    ));
}

function qg_site_up(): bool
{
    return qg_curl('/api/auth/me') !== '';
}
