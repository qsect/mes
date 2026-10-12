<?php declare(strict_types=1);

namespace App\Core;

/**
 * 统一环境变量读取器
 *
 * ★ 安全约定：所有数据库/外部服务凭据一律只从环境变量或 .env 读取，
 *   严禁在源码里写兜底默认值（源码泄露 = 凭据泄露）。
 *   配置缺失时返回空串，由调用方按「拒绝连接」处理。
 *
 * 读取优先级：getenv() → $_ENV → $_SERVER
 * （index.php / cron/bootstrap.php 解析 .env 后写入 $_ENV 与 $_SERVER，
 *   php-fpm 池内通过 env[] 注入的变量则由 getenv() 命中。）
 */
class Env
{
    /** 读取单个环境变量；不存在或为空时返回 $default */
    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
        }
        $value = trim((string)$value);
        return $value === '' ? $default : $value;
    }

    /** 是否存在非空的环境变量 */
    public static function has(string $key): bool
    {
        return self::get($key) !== '';
    }

    /**
     * 读取一组必填环境变量
     *
     * @param string[] $keys
     * @return array{0: array<string,string>, 1: string[]} [取值表, 缺失的键名列表]
     */
    public static function required(array $keys): array
    {
        $values = [];
        $missing = [];
        foreach ($keys as $k) {
            $v = self::get($k);
            if ($v === '') {
                $missing[] = $k;
            }
            $values[$k] = $v;
        }
        return [$values, $missing];
    }
}
