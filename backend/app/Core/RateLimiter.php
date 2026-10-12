<?php declare(strict_types=1);

namespace App\Core;

/**
 * ★ 安全 / 稳定性加固（L5）：轻量级文件滑动窗口限流器。
 *
 * 背景：导出（Excel 生成）与工具箱转换（PDF→Word、批量二维码）属于 CPU 密集接口，
 *       此前完全无限流，少量并发即可把 PHP-FPM / CPU 打满，影响全站。
 *
 * 设计：不引入 Redis、不新增数据表，用「文件 + flock」实现按「用户（或 IP）+ 分组」的
 *       滑动窗口计数。无法加锁或写文件时**放行**（限流不应阻断正常业务）。
 */
class RateLimiter
{
    /** 限流数据目录（backend/storage/ratelimit） */
    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/storage/ratelimit';
    }

    private static function file(string $bucket, string $identity): string
    {
        $hash = sha1($bucket . '|' . $identity);
        return self::dir() . '/' . substr($hash, 0, 2) . '/' . $hash . '.json';
    }

    /** 请求身份：优先登录用户，否则退化为客户端 IP。 */
    public static function identity(): string
    {
        $uid = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);
        if (!empty($uid)) {
            return 'u' . (int)$uid;
        }
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $ip = trim(explode(',', (string)$ip)[0]);
        return 'ip' . ($ip !== '' ? $ip : 'unknown');
    }

    /**
     * 滑动窗口计数。
     * @return bool true=允许本次请求；false=窗口内已超限
     */
    public static function allow(string $bucket, string $identity, int $max, int $windowSeconds): bool
    {
        if ($max <= 0 || $windowSeconds <= 0) {
            return true;
        }

        $file = self::file($bucket, $identity);
        $dir  = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return true; // 无法建目录：放行，不阻断业务
        }

        $fh = @fopen($file, 'c+');
        if (!$fh) {
            return true;
        }

        try {
            if (!@flock($fh, LOCK_EX)) {
                return true;
            }
            $now  = time();
            $hits = json_decode((string)stream_get_contents($fh), true);
            if (!is_array($hits)) {
                $hits = [];
            }
            // 丢弃窗口外的时间戳
            $hits = array_values(array_filter($hits, static function ($t) use ($now, $windowSeconds) {
                return is_int($t) && $t > $now - $windowSeconds;
            }));

            if (count($hits) >= $max) {
                return false;
            }

            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            fflush($fh);
            return true;
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * 由 index.php 在路由派发前调用：命中受限接口且超限时，直接返回 429。
     */
    public static function guard(string $uri): void
    {
        if ((int)($_ENV['RATE_LIMIT_ENABLED'] ?? 1) !== 1) {
            return;
        }

        $path = '/' . trim(parse_url($uri, PHP_URL_PATH) ?: '', '/');

        $bucket = null;
        $max    = 0;
        $window = 60;

        if (preg_match('#^/api/tools/#i', $path)) {
            // 转换类（PDF→Word / 批量二维码）：CPU 最重，阈值最紧
            $bucket = 'convert';
            $max    = (int)($_ENV['RATE_CONVERT_MAX'] ?? 3);
            $window = (int)($_ENV['RATE_CONVERT_WINDOW'] ?? 60);
        } elseif (preg_match('#^/api/.+/export#i', $path)) {
            // 导出类（Excel 生成）
            $bucket = 'export';
            $max    = (int)($_ENV['RATE_EXPORT_MAX'] ?? 30);
            $window = (int)($_ENV['RATE_EXPORT_WINDOW'] ?? 60);
        }

        if ($bucket === null) {
            return;
        }

        if (!self::allow($bucket, self::identity(), $max, $window)) {
            @header('Retry-After: ' . $window);
            Response::error('操作过于频繁，请稍后重试', [
                'code'        => 'RATE_LIMITED',
                'retry_after' => $window,
            ], 429);
        }
    }

    /** 机会式清理：随机概率删除 24 小时未更新的限流文件，避免长期堆积。 */
    public static function gc(): void
    {
        if (random_int(1, 200) !== 1) {
            return;
        }
        $cutoff = time() - 86400;
        foreach (glob(self::dir() . '/*/*.json') ?: [] as $f) {
            if (@filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }
}
