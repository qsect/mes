<?php declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * 通用「模块维护权限」解析器
 *
 * 设计口径（与用户确认）：
 *   - 管理员（is_admin=1 或账号名为 admin）恒可维护；
 *   - 普通用户必须被管理员在「系统管理 → 权限分配」里显式勾选
 *     「允许编辑与数据管理」（user_permissions.can_edit = 1）才可维护；
 *   - 未配置 = 不可维护（fail-closed），异常同样按不可维护处理。
 *
 * 这样无需新增权限表 / 新接口 / 新页面，直接复用既有的通用权限开关，
 * 也避免了各模块再各写一套 XXX_permissions 表。
 */
class ModulePerm
{
    /** 一次请求内缓存模块 id，避免同一请求重复查询 */
    private static array $moduleIdCache = [];

    /**
     * 当前用户是否可维护指定模块的数据
     *
     * @param array  $user       当前会话用户（$_SESSION['user']）
     * @param string $moduleCode 模块代码（modules.module_code，兼容用 route 匹配）
     */
    public static function canEdit(array $user, string $moduleCode): bool
    {
        if (empty($user)) {
            return false;
        }

        // 管理员恒可维护
        if (!empty($user['is_admin']) || (string)($user['username'] ?? '') === 'admin') {
            return true;
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0 || $moduleCode === '') {
            return false;
        }

        try {
            $db = Database::getConnection();

            $moduleId = self::resolveModuleId($db, $moduleCode);
            if ($moduleId <= 0) {
                // 模块未登记进 modules 表 → 无法授权 → 按不可维护处理
                return false;
            }

            $st = $db->prepare("SELECT can_edit FROM user_permissions WHERE user_id = ? AND module_id = ? LIMIT 1");
            $st->execute([$userId, $moduleId]);
            $val = $st->fetchColumn();

            return $val !== false && (int)$val === 1;
        } catch (Throwable $e) {
            error_log('[ModulePerm] canEdit 判定异常: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 解析模块 id：优先 module_code，兜底用 route 匹配（两者在历史数据中偶有差异）
     */
    private static function resolveModuleId(PDO $db, string $moduleCode): int
    {
        if (isset(self::$moduleIdCache[$moduleCode])) {
            return self::$moduleIdCache[$moduleCode];
        }

        $st = $db->prepare("SELECT id FROM modules WHERE module_code = ? OR route = ? ORDER BY (module_code = ?) DESC LIMIT 1");
        $st->execute([$moduleCode, $moduleCode, $moduleCode]);
        $id = (int)$st->fetchColumn();

        self::$moduleIdCache[$moduleCode] = $id;
        return $id;
    }

    /**
     * 统一的 403 响应（调用方直接 return 即可，Response::error 内部会终止输出）
     */
    public static function deny(string $actionLabel = '该操作'): void
    {
        Response::error(
            "权限不足：您没有{$actionLabel}的维护权限。请联系管理员在「系统管理 → 权限分配」中为您勾选「允许编辑与数据管理」。",
            [],
            403
        );
    }
}
