<?php
declare(strict_types=1);

namespace App\Middlewares;

use App\Core\Database;
use App\Core\Response;
use Throwable;

/**
 * 质量追溯模块「查看权限」中间件（fail-closed）
 *
 * 为什么单独一套：
 *   质量追溯（trace）用的是专属权限表 trace_permissions，字段更细
 *   （can_add_material / can_scan / can_view / can_edit_record / can_delete_record），
 *   与通用 user_permissions 不是一个体系，故不能复用 ModuleViewMiddleware。
 *
 * 判定口径：
 *   - 管理员放行；
 *   - 普通用户查 trace_permissions.can_view：
 *       · 有记录   → 按 can_view 判定（0 即拒绝）
 *       · 无记录   → 沿用 TraceController 的既有默认（默认可见），保持行为一致
 *   - 判定异常 → 拒绝（fail-closed）。
 */
class TraceViewMiddleware
{
    public function handle(): void
    {
        $user = $_SESSION['user'] ?? [];
        if (empty($user)) {
            Response::error('未登录或登录已超时', ['need_login' => true], 401);
            exit;
        }

        if (!empty($user['is_admin']) || (string)($user['username'] ?? '') === 'admin') {
            return;
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            self::deny();
        }

        try {
            $db = Database::getConnection();
            $st = $db->prepare("SELECT can_view FROM trace_permissions WHERE user_id = ? LIMIT 1");
            $st->execute([$userId]);
            $val = $st->fetchColumn();

            // 无记录时保持既有默认（可见），与 TraceController::calcUserPerms 一致
            if ($val === false) {
                return;
            }
            if ((int)$val === 1) {
                return;
            }
            self::deny();
        } catch (Throwable $e) {
            error_log('[TraceViewMiddleware] 判定异常: ' . $e->getMessage());
            self::deny();
        }
    }

    private static function deny(): void
    {
        Response::error(
            '权限不足：您没有质量追溯模块的查看权限。请联系管理员在「系统管理 → 权限分配」中为您勾选。',
            [],
            403
        );
        exit;
    }
}
