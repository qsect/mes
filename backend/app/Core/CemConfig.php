<?php declare(strict_types=1);

namespace App\Core;

/**
 * CEM（外部质量追溯系统）访问配置。
 *
 * ★ 安全加固（H5）：凭据不再写死在源码中。
 *   读取优先级：
 *     1) 环境变量 / .env ：CEM_HOST / CEM_USER / CEM_PASS
 *     2) 本地未纳入版本库的 backend/config/cem.local.php（返回数组，键名同环境变量）
 *     3) 传入的默认值
 *
 * 注意：cem.local.php 已加入 .gitignore，部署时请手动拷贝到服务器，
 *       或直接把三项写入服务器 .env。
 */
class CemConfig
{
    /** @var array<string,string>|null 本地凭据缓存 */
    private static ?array $local = null;

    public static function get(string $key, string $default = ''): string
    {
        $key = strtoupper($key);

        $v = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if (!is_string($v) || $v === '') {
            if (function_exists('getenv')) {
                $g = getenv($key);
                if ($g !== false && $g !== '') {
                    return (string)$g;
                }
            }
        } else {
            return $v;
        }

        $local = self::localFile();
        if (!empty($local[$key]) && is_string($local[$key])) {
            return $local[$key];
        }

        return $default;
    }

    public static function host(): string
    {
        return self::get('CEM_HOST', 'http://cem.ieslab.cn');
    }

    public static function user(): string
    {
        return self::get('CEM_USER');
    }

    public static function pass(): string
    {
        return self::get('CEM_PASS');
    }

    /**
     * @return array<string,string>
     */
    private static function localFile(): array
    {
        if (self::$local !== null) {
            return self::$local;
        }
        self::$local = [];

        // backend/config/cem.local.php
        $file = dirname(__DIR__, 2) . '/config/cem.local.php';
        if (is_file($file)) {
            $data = include $file;
            if (is_array($data)) {
                self::$local = $data;
            }
        }
        return self::$local;
    }
}
