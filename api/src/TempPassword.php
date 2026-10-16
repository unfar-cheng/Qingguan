<?php
namespace QG;

/**
 * 临时密码生成（代重置 / 新增账号）。12 位，含大小写+数字+符号，剔除易混淆字符（0Oo、1lIi）。
 * 服务端只存哈希；明文仅出现在一次性响应里，不落库、不进审计与日志。
 */
class TempPassword
{
    private const CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&*';

    public static function generate(int $length = 12): string
    {
        if ($length < 8) {
            throw new \RuntimeException('临时密码长度至少 8 位');
        }
        $max = strlen(self::CHARSET);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::CHARSET[random_int(0, $max - 1)];
        }
        return $out;
    }
}
