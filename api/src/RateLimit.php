<?php
namespace QG;

/**
 * 文件型滑动窗口限流器：按 bucket（如 "register|1.2.3.4"）计数。
 * 存 api/runtime/ratelimit/（该目录可写、不入库）。零迁移、单机够用。
 * 限流器自身故障（目录不可写等）放行，不挡业务。
 */
class RateLimit
{
    /** 测试可覆盖存储目录 */
    public static ?string $dir = null;

    /** 窗口内还有额度则记录本次并返回 true，否则返回 false */
    public static function allow(string $bucket, int $max, int $windowSec): bool
    {
        $dir = self::$dir ?? dirname(__DIR__) . '/runtime/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . sha1($bucket) . '.json';
        $now  = time();
        $fp   = @fopen($file, 'c+');
        if ($fp === false) {
            error_log('[RateLimit] 存储目录不可写，限流已放行：' . $dir);
            return true;
        }
        if (!@flock($fp, LOCK_EX)) {
            fclose($fp);
            return true;
        }
        try {
            $raw  = stream_get_contents($fp);
            $hits = $raw ? json_decode($raw, true) : [];
            if (!is_array($hits)) {
                $hits = [];
            }
            $hits = array_values(array_filter($hits, function ($t) use ($now, $windowSec) {
                return is_int($t) && $t > $now - $windowSec;
            }));
            if (count($hits) >= $max) {
                return false;
            }
            $hits[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($hits));
            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
