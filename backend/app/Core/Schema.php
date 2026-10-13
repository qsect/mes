<?php declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * 表结构自愈工具：用于兼容历史遗留的表结构，幂等执行。
 * 注意：DDL 会隐式提交事务，必须在 beginTransaction() 之前调用。
 *
 * ★ 性能加固（L6）：引入「就绪标记」机制。
 *   此前每个 HTTP 请求都会执行一遍 CREATE TABLE IF NOT EXISTS / information_schema 探测，
 *   19 个控制器叠加后是明显的无谓开销。现在某模块表结构探测成功后写入一个「版本化标记文件」，
 *   后续请求直接跳过探测；只有修改了表结构、把 VERSION 递增时，才会全量重探一次。
 */
class Schema
{
    /** 标记文件版本号：任何表结构变更后 +1，即可强制所有节点重新探测一次 */
    public const VERSION = '3';

    /** 请求内记忆，避免同一请求内重复 stat 文件 */
    private static array $readyMemo = [];

    /** 每次请求只探测一次 users 密码相关列，避免重复查询 information_schema */
    private static bool $userPasswordColumnsEnsured = false;

    /** 每次请求只探测一次登录限速表 */
    private static bool $loginAttemptsEnsured = false;

    /** 就绪标记目录（backend/storage/schema） */
    private static function cacheDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/schema';
    }

    private static function cacheFile(string $key): string
    {
        $key = preg_replace('/[^a-z0-9_\-]/i', '', $key) ?: 'default';
        return self::cacheDir() . '/' . $key . '.ready';
    }

    /**
     * 该模块的表结构是否已在「本版本」探测过。
     * 命中标记 → 本次请求无需再跑任何 DDL / information_schema 查询。
     */
    public static function isReady(string $key): bool
    {
        if (!empty(self::$readyMemo[$key])) {
            return true;
        }
        $file = self::cacheFile($key);
        if (is_file($file) && @file_get_contents($file) === self::VERSION) {
            self::$readyMemo[$key] = true;
            return true;
        }
        return false;
    }

    /** 写入就绪标记（幂等）。 */
    public static function markReady(string $key): void
    {
        self::$readyMemo[$key] = true;
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(self::cacheFile($key), self::VERSION);
    }

    /**
     * 若该模块表结构尚未就绪，则执行 $fn（建表/补列），成功后写入标记。
     * 典型用法（控制器构造函数内）：
     *   \App\Core\Schema::ensureOnce('impedance', function () { $this->initTables(); });
     */
    public static function ensureOnce(string $key, callable $fn): void
    {
        if (self::isReady($key)) {
            return;
        }
        $fn();
        self::markReady($key);
    }

    /**
     * 补齐某张表缺失的列（存在则跳过）
     */
    public static function ensureColumn(PDO $db, string $table, string $column, string $definition): void
    {
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $st->execute([$table, $column]);
            if ((int)$st->fetchColumn() === 0) {
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
            }
        } catch (Throwable $e) {
            // 忽略：无权限或并发加列时不影响主流程
        }
    }

    /**
     * 确保 users 表具备「强制改密标记 / 密码最后修改时间」两列
     */
    public static function ensureUserPasswordColumns(PDO $db): void
    {
        if (self::$userPasswordColumnsEnsured) {
            return;
        }
        self::$userPasswordColumnsEnsured = true;

        if (self::isReady('users_password_columns')) {
            return;
        }

        self::ensureColumn(
            $db,
            'users',
            'must_change_password',
            "`must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首次登录或被重置后需强制修改密码'"
        );
        self::ensureColumn(
            $db,
            'users',
            'password_updated_at',
            "`password_updated_at` DATETIME NULL COMMENT '密码最后修改时间'"
        );

        self::markReady('users_password_columns');
    }

    /**
     * 确保登录失败限速表存在（幂等，用于登录爆破防护）
     */
    public static function ensureLoginAttemptsTable(PDO $db): void
    {
        if (self::$loginAttemptsEnsured) {
            return;
        }
        self::$loginAttemptsEnsured = true;

        if (self::isReady('login_attempts')) {
            return;
        }

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `login_attempts` (
                `id` BIGINT PRIMARY KEY AUTO_INCREMENT,
                `username` VARCHAR(64) NOT NULL DEFAULT '',
                `ip` VARCHAR(64) NOT NULL DEFAULT '',
                `success` TINYINT(1) NOT NULL DEFAULT 0,
                `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_username_time` (`username`, `attempted_at`),
                INDEX `idx_ip_time` (`ip`, `attempted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Throwable $e) {
            // 忽略：无权限时登录仍可正常进行，仅失去限速保护
        }

        self::markReady('login_attempts');
    }
}
