<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\PasswordPolicy;
use App\Core\Response;
use App\Core\Schema;
use App\Services\AuditService;
use PDO;
use Throwable;

class AuthController
{
    private PDO $db;

    /** 一次请求内缓存 modules 表的"可见"列名 */
    private static ?string $moduleVisibleColumn = null;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * 解析 modules 表中表示"是否可见/启用"的列名。
     *
     * 历史背景：模块管理界面（SystemController::saveModule / toggleModuleStatus）
     * 使用的是 `status`，而权限判定曾误用不存在的 `is_visible`，直接导致
     * /auth/me 抛 Unknown column → 权限体系整体失效。
     *
     * 这里做一次 information_schema 探测并缓存，兼容两种表结构：
     *   status（当前系统）> is_visible（历史/其他环境）> 兜底 status。
     * 使用固定常量拼接 SQL（不来自用户输入），不存在注入风险。
     */
    private function resolveModuleVisibleColumn(): string
    {
        if (self::$moduleVisibleColumn !== null) {
            return self::$moduleVisibleColumn;
        }
        try {
            $st = $this->db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modules'
                                        AND COLUMN_NAME IN ('status', 'is_visible')");
            $st->execute();
            $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (in_array('status', $cols, true)) {
                self::$moduleVisibleColumn = 'status';
            } elseif (in_array('is_visible', $cols, true)) {
                self::$moduleVisibleColumn = 'is_visible';
            } else {
                self::$moduleVisibleColumn = 'status';
            }
        } catch (Throwable $e) {
            error_log('[AuthController] 探测 modules 可见列失败: ' . $e->getMessage());
            self::$moduleVisibleColumn = 'status';
        }
        return self::$moduleVisibleColumn;
    }

    public function login(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');

        if ($username === '' || $password === '') {
            Response::error('请输入用户名和密码', [], 400);
            return;
        }

        // 确保 users 表具备强制改密相关列（幂等，仅首次探测）
        Schema::ensureUserPasswordColumns($this->db);
        Schema::ensureLoginAttemptsTable($this->db);

        $clientIp = $this->clientIp();

        // ★ 安全加固（M3）：登录失败限速，防止用统一初始密码批量爆破
        $maxAttempts = (int)($_ENV['LOGIN_MAX_ATTEMPTS'] ?? 5);
        $lockWindow  = (int)($_ENV['LOGIN_LOCK_WINDOW'] ?? 600);
        if ($this->isLoginLocked($username, $clientIp, $maxAttempts, $lockWindow)) {
            AuditService::log('系统认证', "用户[{$username}]登录被限速锁定", false);
            Response::error('登录失败次数过多，请 ' . max(1, (int)round($lockWindow / 60)) . ' 分钟后再试', [], 429);
            return;
        }

        // 联合查询 roles 表，获取准确的角色名称
        $stmt = $this->db->prepare("
            SELECT u.*, r.name AS real_role_name, r.code AS role_code
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.username = :u LIMIT 1
        ");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $this->recordLoginAttempt($username, $clientIp, false);
            AuditService::log('系统认证', "用户[{$username}]登录失败: 用户不存在", false);
            Response::error('账号或密码错误', [], 400);
            return;
        }

        $isPasswordValid = PasswordPolicy::verify($password, (string)$user['password']);

        if (!$isPasswordValid) {
            $this->recordLoginAttempt($username, $clientIp, false);
            AuditService::log('系统认证', "用户[{$username}]登录失败: 密码错误", false);
            Response::error('账号或密码错误', [], 400);
            return;
        }

        if ((int)$user['is_active'] !== 1) {
            $this->recordLoginAttempt($username, $clientIp, false);
            AuditService::log('系统认证', "用户[{$username}]登录失败: 账号已禁用", false);
            Response::error('该账号已被禁用，请联系管理员', [], 403);
            return;
        }

        // ★ 核心校正：根据真实角色校正 role 字段与 is_admin
        if (!empty($user['real_role_name'])) {
            $user['role'] = $user['real_role_name'];
        }
        // 只有明确 is_admin = 1 或者角色编码为 admin 才视作管理员
        $user['is_admin'] = ((int)$user['is_admin'] === 1 || ($user['role_code'] ?? '') === 'admin') ? 1 : 0;
        if ($user['is_admin'] === 0 && ($user['role'] === '管理员' || $user['role'] === 'admin')) {
            $user['role'] = $user['real_role_name'] ?: '普通员工';
        }

        // ★ 密码合规检测（管理员不参与）：
        //   1) 数据库标记必须改密（新建用户 / 管理员重置过密码）
        //   2) 当前登录密码本身不满足强度规则（例如仍未修改的统一初始密码 123456）
        //   命中任意一条都必须先修改为合规密码，才能正常使用系统。
        $mustChange = false;
        if ((int)$user['is_admin'] !== 1) {
            $mustChange = ((int)($user['must_change_password'] ?? 0) === 1)
                || !PasswordPolicy::isStrong($password);
        }
        $user['must_change_password'] = $mustChange ? 1 : 0;

        // ★ 安全加固（M4）：历史 md5 / 明文口令在登录成功后立即升级为现代哈希
        $storedPassword = (string)$user['password'];
        if (PasswordPolicy::needsRehash($storedPassword)) {
            try {
                $this->db->prepare("UPDATE users SET password = ?, password_updated_at = NOW() WHERE id = ?")
                         ->execute([PasswordPolicy::hash($password), (int)$user['id']]);
            } catch (Throwable $e) {
                error_log('[login] 口令哈希升级失败: ' . $e->getMessage());
            }
        }

        // 记录登录时间与 IP
        $updateStmt = $this->db->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = :ip WHERE id = :id");
        $updateStmt->execute([':ip' => $clientIp, ':id' => $user['id']]);

        // 登录成功：清除该用户/该 IP 的失败计数
        $this->recordLoginAttempt($username, $clientIp, true);

        // ★ 安全加固（M8）：登录成功后轮换 Session ID，防止会话固定攻击
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // 写入会话
        unset($user['password']);
        $_SESSION['user'] = $user;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_admin'] = $user['is_admin'];
        $_SESSION['must_change_password'] = $user['must_change_password'];
        $_SESSION['last_active'] = time();

        AuditService::log('系统认证', "用户[{$username}]登录成功");

        Response::success($mustChange ? '登录成功，请先修改初始密码' : '登录成功', [
            // ★ 安全加固（L7 收尾）：不再把 session_id 回传到响应体。
            //   会话凭证完全由 HttpOnly + SameSite Cookie 承载，前端也不需要它；
            //   回传会抵消 HttpOnly 的价值（一旦出现 XSS 即可读走会话）。
            'user'                => $user,
            'must_change_password' => $user['must_change_password'],
            'password_rule'       => PasswordPolicy::RULE_TEXT,
        ]);
    }

    public function me(): void
    {
        if (empty($_SESSION['user_id'])) {
            Response::error('未登录或会话已过期', [], 401);
            return;
        }

        // 确保 users 表具备强制改密相关列（幂等，仅首次探测）
        Schema::ensureUserPasswordColumns($this->db);

        // ★ 安全修复：每次请求都从数据库重新校验当前用户的身份与管理员标记，
        //   自动修正历史遗留或被污染(误判为管理员)的会话，避免普通用户越权。
        $stmt = $this->db->prepare("
            SELECT u.*, r.name AS real_role_name, r.code AS role_code
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || (int)$user['is_active'] !== 1) {
            $_SESSION = [];
            Response::error('账号状态异常，请重新登录', ['need_login' => true], 401);
            return;
        }

        if (!empty($user['real_role_name'])) {
            $user['role'] = $user['real_role_name'];
        }
        // 只有明确 is_admin = 1 或者角色编码为 admin 才视作管理员
        $user['is_admin'] = ((int)$user['is_admin'] === 1 || ($user['role_code'] ?? '') === 'admin') ? 1 : 0;
        if ((int)$user['is_admin'] === 0 && ($user['role'] === '管理员' || $user['role'] === 'admin')) {
            $user['role'] = $user['real_role_name'] ?: '普通员工';
        }

        // ★ 强制改密状态同步：数据库标记或登录时写入的会话标记，任一命中即生效（管理员豁免）
        $mustChange = 0;
        if ((int)$user['is_admin'] !== 1
            && (((int)($user['must_change_password'] ?? 0) === 1) || ((int)($_SESSION['must_change_password'] ?? 0) === 1))) {
            $mustChange = 1;
        }
        $user['must_change_password'] = $mustChange;

        unset($user['password']);
        $_SESSION['user']    = $user;
        $_SESSION['is_admin'] = $user['is_admin'];
        $_SESSION['must_change_password'] = $mustChange;

        // ★ 修正：modules 表实际字段为 name / module_code / route / sort_order
        // ★ 根因修复（2026-10-11）：此前三处 SQL 均使用 `is_visible`，但 modules 表
        //   实际只有 `status`（模块管理界面读写的就是 status）。字段不存在会导致
        //   /auth/me 抛 "Unknown column 'is_visible'" → 返回 500 → 前端 menus /
        //   managed_routes 双双为空 → 路由守卫与菜单过滤全部失效（权限形同虚设）。
        //   这里做一次字段探测，兼容两种表结构，避免再次踩空。
        $visibleCol = $this->resolveModuleVisibleColumn();

        // 全部已登记且可见的模块路由（即权限体系当前"可管控"的范围）
        $allRoutes = array_values(array_unique(array_filter(
            $this->db->query("SELECT route FROM modules WHERE {$visibleCol} = 1 AND route IS NOT NULL AND route <> ''")
                     ->fetchAll(PDO::FETCH_COLUMN)
        )));

        // path 兜底：modules.route 未配置时退回 module_code（模块管理界面提示"默认与标识码一致"）。
        // 若 route 为空且不做兜底，该模块路由不会出现在 menus 中，
        // 会被前端 fail-closed 守卫与后端中间件一并拦掉（对所有人失效）。
        $selectCols = "m.id, m.parent_id, m.name AS title, m.module_code AS code,
                       COALESCE(NULLIF(m.route, ''), m.module_code) AS path,
                       m.icon, m.type, m.sort_order AS sort";

        if (!empty($user['is_admin']) && (int)$user['is_admin'] === 1) {
            // 管理员：全部可见模块
            $stmt = $this->db->query("
                SELECT {$selectCols}
                FROM modules m
                WHERE m.{$visibleCol} = 1
                ORDER BY m.sort_order ASC, m.id ASC
            ");
            $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $uid = (int)$user['id'];
            $rid = (int)($user['role_id'] ?? 0);

            // 是否已对该用户 / 该角色做过权限配置
            $uq = $this->db->prepare("SELECT COUNT(*) FROM user_permissions WHERE user_id = ?");
            $uq->execute([$uid]);
            $hasUserPerms = (int)$uq->fetchColumn() > 0;

            $rq = $this->db->prepare("SELECT COUNT(*) FROM role_modules WHERE role_id = ?");
            $rq->execute([$rid]);
            $hasRolePerms = (int)$rq->fetchColumn() > 0;

            $stmt = $this->db->prepare("
                SELECT {$selectCols},
                       up.can_view AS up_view,
                       (CASE WHEN rm.module_id IS NULL THEN 0 ELSE 1 END) AS role_grant
                FROM modules m
                LEFT JOIN user_permissions up ON up.module_id = m.id AND up.user_id = :uid
                LEFT JOIN role_modules   rm ON rm.module_id = m.id AND rm.role_id = :rid
                WHERE m.{$visibleCol} = 1
                ORDER BY m.sort_order ASC, m.id ASC
            ");
            $stmt->execute([':uid' => $uid, ':rid' => $rid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ★ 权限判定（fail-closed）：
            //   用户级 can_view 优先 → 角色级授权兜底 → 两者皆无 ⇒ 不可见。
            //   （此前"未配置任何权限 → 默认全部可见"的兜底会与本次修复冲突，
            //     因为管理员把勾全部取消后恰恰表现为需要"全部不可见"，故移除该兜底。
            //     正常流程下保存权限会为全部模块写行，此分支只影响从未配置过的账号。）
            $modules = [];
            foreach ($rows as $r) {
                if ($hasUserPerms) {
                    // 已配置用户级权限：以 can_view 为唯一依据（未勾选行的 up_view 为 0/NULL）
                    $granted = ((int)($r['up_view'] ?? 0) === 1);
                } elseif ($hasRolePerms) {
                    // 未配置用户级权限：退回角色级授权
                    $granted = ((int)$r['role_grant'] === 1);
                } else {
                    // 两处均未配置：拒绝（fail-closed），由管理员显式授权后可见
                    $granted = false;
                }
                if ($granted) {
                    unset($r['up_view'], $r['role_grant']);
                    $modules[] = $r;
                }
            }
        }

        Response::success('获取成功', [
            'user'           => $user,
            'menus'          => $modules,
            // 权限体系当前可管控的全部路由，供前端判断"未纳入管控的菜单项保持可见"
            'managed_routes' => $allRoutes,
        ]);
    }

    /**
     * 修改当前登录用户的密码
     * 说明：该接口在"强制改密"状态下也必须可访问（见 index.php 白名单）
     */
    public function changePassword(): void
    {
        if (empty($_SESSION['user_id'])) {
            Response::error('未登录或会话已过期', [], 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $oldPassword     = (string)($input['old_password'] ?? '');
        $newPassword     = (string)($input['new_password'] ?? '');
        $confirmPassword = (string)($input['confirm_password'] ?? '');

        if ($oldPassword === '' || $newPassword === '') {
            Response::error('请输入原密码与新密码');
            return;
        }
        if ($confirmPassword !== '' && $confirmPassword !== $newPassword) {
            Response::error('两次输入的新密码不一致');
            return;
        }
        if ($newPassword === $oldPassword) {
            Response::error('新密码不能与原密码相同');
            return;
        }
        if (!PasswordPolicy::isStrong($newPassword)) {
            Response::error(PasswordPolicy::describe($newPassword));
            return;
        }

        try {
            Schema::ensureUserPasswordColumns($this->db);

            $stmt = $this->db->prepare("SELECT id, username, password, is_admin FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                Response::error('账号不存在或已被删除，请重新登录', ['need_login' => true], 401);
                return;
            }

            if (!PasswordPolicy::verify($oldPassword, (string)$user['password'])) {
                AuditService::log('系统认证', "用户[{$user['username']}]修改密码失败: 原密码不正确", false);
                Response::error('原密码不正确，请重新输入');
                return;
            }

            $upd = $this->db->prepare("UPDATE users
                                       SET password = :p, must_change_password = 0, password_updated_at = NOW()
                                       WHERE id = :id");
            $upd->execute([
                ':p'  => PasswordPolicy::hash($newPassword),
                ':id' => (int)$user['id'],
            ]);

            // 同步清理会话中的强制改密标记
            $_SESSION['must_change_password'] = 0;
            if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
                $_SESSION['user']['must_change_password'] = 0;
            }

            AuditService::log('系统认证', "用户[{$user['username']}]修改密码成功");

            Response::success('密码修改成功，请牢记新密码', [
                'must_change_password' => 0,
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '密码修改失败');
        }
    }

    /**
     * 取客户端 IP（兼容反向代理）
     */
    private function clientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($ip !== '') {
            $parts = explode(',', $ip);
            $ip = trim($parts[0]);
        }
        if ($ip === '') {
            $ip = (string)($_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        }
        return mb_substr($ip, 0, 64);
    }

    /**
     * 是否因失败次数过多被暂时锁定（按用户名或 IP 统计）
     */
    private function isLoginLocked(string $username, string $ip, int $limit, int $windowSeconds): bool
    {
        if ($limit <= 0 || $windowSeconds <= 0) {
            return false;
        }
        try {
            $since = date('Y-m-d H:i:s', time() - $windowSeconds);
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE success = 0 AND attempted_at >= ? AND (username = ? OR ip = ?)"
            );
            $stmt->execute([$since, $username, $ip]);
            return (int)$stmt->fetchColumn() >= $limit;
        } catch (Throwable $e) {
            // 限速表不可用时不影响正常登录
            return false;
        }
    }

    /**
     * 记录一次登录尝试；成功时清除该用户/该 IP 的失败计数
     */
    private function recordLoginAttempt(string $username, string $ip, bool $success): void
    {
        try {
            if ($success) {
                $this->db->prepare("DELETE FROM login_attempts WHERE success = 0 AND (username = ? OR ip = ?)")
                         ->execute([$username, $ip]);
                return;
            }
            $this->db->prepare("INSERT INTO login_attempts (username, ip, success, attempted_at) VALUES (?, ?, 0, NOW())")
                     ->execute([$username, $ip]);
            // 顺带清理超过 24 小时的旧记录，避免表无限膨胀
            $this->db->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
        } catch (Throwable $e) {
            // 忽略：记录失败不影响主流程
        }
    }

    public function logout(): void
    {
        if (!empty($_SESSION['user'])) {
            AuditService::log('系统认证', "用户退出登录");
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        Response::success('已退出登录');
    }
}