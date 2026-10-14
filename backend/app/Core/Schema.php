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
    public const VERSION = '5';

    /** 请求内记忆，避免同一请求内重复 stat 文件 */
    private static array $readyMemo = [];

    /** 每次请求只探测一次 users 密码相关列，避免重复查询 information_schema */
    private static bool $userPasswordColumnsEnsured = false;

    /** 每次请求只探测一次登录限速表 */
    private static bool $loginAttemptsEnsured = false;

    /** 每次请求只探测一次预置角色 */
    private static bool $presetRolesEnsured = false;

    /**
     * 系统预置岗位角色：角色名 => [标识 code, 说明 description]
     * 新增岗位角色只需在此登记一行，各环境访问「岗位角色」下拉时会自动补齐。
     */
    private const PRESET_ROLES = [
        '实习学生' => ['intern_student', '实习学生：跟岗学习人员，模块权限需单独分配'],
    ];

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
     * 清除就绪标记：下一次请求会重新执行该模块的初始化。
     *
     * ★ 为什么需要：initTables() 这类初始化通常把异常 try/catch 吞掉
     *   （不想让 DDL 问题打断业务请求），但这会导致 ensureOnce 认为"执行过了"而写下就绪标记；
     *   一旦首次执行因缺权限等原因失败，就永远不会重试 —— 表现为
     *   「表永久缺失，而日志里只有一行，页面无任何报错」。
     *   初始化失败时显式调用本方法，让下一次请求继续尝试自愈。
     */
    public static function clearReady(string $key): void
    {
        unset(self::$readyMemo[$key]);
        $file = self::cacheFile($key);
        if (is_file($file)) {
            @unlink($file);
        }
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

    /**
     * 确保系统预置岗位角色存在（幂等）。
     *
     * ★ 背景：roles 表由建库时创建、后台没有增删角色的界面，
     *   过去新增一个岗位角色（如「实习学生」）只能手工执行 SQL，
     *   漏做时前端「岗位角色」下拉就选不到，且各环境还不一致。
     * ★ 只补不覆写：按「角色名 或 标识」命中即跳过，绝不改动管理员既有配置。
     * ★ 失败不写就绪标记，下次请求会重试（避免一次偶发错误导致永久缺失）。
     */
    public static function ensurePresetRoles(PDO $db): void
    {
        if (self::$presetRolesEnsured) {
            return;
        }
        self::$presetRolesEnsured = true;

        if (self::isReady('preset_roles')) {
            return;
        }

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `roles` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(50) NOT NULL COMMENT '角色名称',
                `code` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '角色标识',
                `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '说明',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统角色';");

            // 历史库可能缺列，补齐后再插入，避免 INSERT 直接报 Unknown column
            self::ensureColumn($db, 'roles', 'code', "`code` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '角色标识'");
            self::ensureColumn($db, 'roles', 'description', "`description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '说明'");

            foreach (self::PRESET_ROLES as $name => $meta) {
                [$code, $desc] = $meta;

                $chk = $db->prepare("SELECT COUNT(*) FROM `roles` WHERE `name` = ? OR `code` = ?");
                $chk->execute([$name, $code]);
                if ((int)$chk->fetchColumn() > 0) {
                    continue;
                }

                $ins = $db->prepare("INSERT INTO `roles` (`name`, `code`, `description`) VALUES (?, ?, ?)");
                $ins->execute([$name, $code, $desc]);
            }
        } catch (Throwable $e) {
            // 忽略：无建表/写权限时角色列表仍应正常返回，下次请求再试
            error_log('[Schema] 预置角色补齐失败: ' . $e->getMessage());
            return;
        }

        self::markReady('preset_roles');
    }
}
