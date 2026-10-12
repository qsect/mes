<?php
declare(strict_types=1);

namespace App\Middlewares;

use App\Core\Database;
use App\Core\Response;
use Throwable;

/**
 * 模块「查看权限」中间件（fail-closed）
 *
 * 解决的问题（2026-10-11）：
 *   此前所有业务控制器只校验「是否登录」，不校验「能否查看该模块」。
 *   前端虽然会隐藏菜单 / 拦截路由，但只要直接请求接口（Postman / curl /
 *   禁用 JS），未授权模块的数据依然能原样拉取 —— 权限体系形同虚设。
 *
 * 本中间件挂在路由上，在控制器执行前统一判定：
 *   - 管理员（is_admin=1 或用户名 admin）直接放行；
 *   - 普通用户：该模块必须被显式授权「查看」才放行。
 *     判定顺序：user_permissions.can_view → role_modules 授权 → 均无 ⇒ 拒绝。
 *
 * 用法（注册路由时挂载）：
 *   $router->add('GET', '/api/equipment/list_matrix',
 *       [EquipmentController::class, 'listMatrix'], [ 'module' => 'equipment' ]);
 *
 * 注意：一个路由只需挂一次；模块代码取 modules.module_code / route。
 */
class ModuleViewMiddleware
{
    /** @var string 待校验的模块代码，由 Router 从路由定义注入 */
    private string $moduleCode;

    public function __construct(string $moduleCode = '')
    {
        $this->moduleCode = $moduleCode;
    }

    public function handle(): void
    {
        $moduleCode = $this->moduleCode;
        if ($moduleCode === '') {
            return;   // 未指定模块，不做模块级限制（仍受各自控制器的登录校验保护）
        }

        $user = $_SESSION['user'] ?? [];
        if (empty($user)) {
            // 与全局 checkUser 口径一致：未登录直接 401
            Response::error('未登录或登录已超时', ['need_login' => true], 401);
            exit;
        }

        // 管理员恒可查看
        if (!empty($user['is_admin']) || (string)($user['username'] ?? '') === 'admin') {
            return;
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            self::deny();
        }

        try {
            $db = Database::getConnection();

            // 解析模块 id（module_code 优先，兜底 route）
            $st = $db->prepare("SELECT id FROM modules WHERE module_code = ? OR route = ? ORDER BY (module_code = ?) DESC LIMIT 1");
            $st->execute([$moduleCode, $moduleCode, $moduleCode]);
            $moduleId = (int)$st->fetchColumn();

            if ($moduleId <= 0) {
                // 模块未登记进 modules 表 → 无法授权。
                // 为避免误伤（菜单中的模块可能尚未登记），此时不做拦截，
                // 由前端菜单过滤 + 控制器自身逻辑兜底。
                return;
            }

            // ① 用户级 can_view
            $uq = $db->prepare("SELECT COUNT(*) FROM user_permissions WHERE user_id = ?");
            $uq->execute([$userId]);
            $hasUserPerms = (int)$uq->fetchColumn() > 0;

            if ($hasUserPerms) {
                $cv = $db->prepare("SELECT can_view FROM user_permissions WHERE user_id = ? AND module_id = ? LIMIT 1");
                $cv->execute([$userId, $moduleId]);
                $val = $cv->fetchColumn();
                if ($val !== false && (int)$val === 1) {
                    return;   // 已授权查看
                }
                self::deny();
            }

            // ② 角色级授权兜底
            $rid = (int)($user['role_id'] ?? 0);
            if ($rid > 0) {
                $rq = $db->prepare("SELECT COUNT(*) FROM role_modules WHERE role_id = ? AND module_id = ?");
                $rq->execute([$rid, $moduleId]);
                if ((int)$rq->fetchColumn() > 0) {
                    return;   // 角色已授权
                }
            }

            self::deny();
        } catch (Throwable $e) {
            error_log('[ModuleViewMiddleware] 判定异常(' . $moduleCode . '): ' . $e->getMessage());
            // fail-closed：判定出错时拒绝，避免异常导致越权
            self::deny();
        }
    }

    private static function deny(): void
    {
        Response::error(
            '权限不足：您没有该模块的查看权限。请联系管理员在「系统管理 → 权限分配」中为您勾选「查看」。',
            [],
            403
        );
        exit;
    }
}
