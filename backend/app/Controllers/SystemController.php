<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\PasswordPolicy;
use App\Core\Response;
use App\Core\Schema;
use App\Services\AuditService;
use PDO;
use Throwable;

class SystemController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->currentUser = function_exists('checkUser') ? checkUser() : ($_SESSION['user'] ?? []);
        $username = (string)($this->currentUser['username'] ?? '');
        $this->isAdmin = !empty($this->currentUser['is_admin']) || $username === 'admin';

        // ★ 安全修复：系统管理相关接口（用户 / 角色 / 权限 / 模块 / 审计日志）仅限管理员访问
        if (!$this->isAdmin) {
            Response::error('权限不足，仅管理员可访问系统管理', [], 403);
            exit;
        }
    }

    /**
     * 获取用户列表
     */
    public function users(): void
    {
        try {
            Schema::ensureUserPasswordColumns($this->db);

            $keyword = trim((string)($_GET['keyword'] ?? ''));
            $where = ["1=1"];
            $params = [];

            if ($keyword !== '') {
                $where[] = "(u.username LIKE ? OR u.full_name LIKE ? OR u.badge_no LIKE ? OR u.phone LIKE ?)";
                $kw = "%{$keyword}%";
                $params = [$kw, $kw, $kw, $kw];
            }

            $sql = "SELECT u.id, u.username, u.badge_no, u.full_name, u.team, u.role_id, u.phone, 
                           u.is_admin, u.is_active, u.must_change_password, u.password_updated_at, r.name AS role_name
                    FROM users u
                    LEFT JOIN roles r ON u.role_id = r.id
                    WHERE " . implode(" AND ", $where) . "
                    ORDER BY u.id DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', $list);
        } catch (Throwable $e) {
            Response::exception($e, '获取用户列表失败');
        }
    }

    /**
     * 保存/更新用户（已集成角色自动校正、全表角色修复及密码同步）
     */
    public function saveUser(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $id       = (int)($input['id'] ?? 0);
        $badgeNo  = trim((string)($input['badge_no'] ?? ''));
        $username = trim((string)($input['username'] ?? ''));
        $fullName = trim((string)($input['full_name'] ?? ''));
        $team     = trim((string)($input['team'] ?? ''));
        $roleId   = !empty($input['role_id']) ? (int)$input['role_id'] : null;
        $phone    = trim((string)($input['phone'] ?? ''));
        $password = trim((string)($input['password'] ?? ''));
        $isAdmin  = !empty($input['is_admin']) ? 1 : 0;
        $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        if ($username === '' || $fullName === '') {
            Response::error('用户名和姓名不能为空');
            return;
        }

        try {
            Schema::ensureUserPasswordColumns($this->db);

            // ★★★ 核心机制1：根据选中的 role_id 动态解析出标准角色名称与管理员身份 ★★★
            $roleText = '员工';
            if ($roleId !== null && $roleId > 0) {
                $rStmt = $this->db->prepare("SELECT name, code FROM roles WHERE id = ? LIMIT 1");
                $rStmt->execute([$roleId]);
                $roleRow = $rStmt->fetch(PDO::FETCH_ASSOC);
                if ($roleRow) {
                    $roleText = $roleRow['name'];
                    // 如果角色本身就是管理员角色或前端勾选了管理员
                    if ($roleRow['code'] === 'admin' || str_contains($roleRow['name'], '管理员')) {
                        $isAdmin = 1;
                    }
                }
            }

            if ($isAdmin === 1 && $roleText === '员工') {
                $roleText = '管理员';
            }

            if ($id > 0) {
                // ==================== 更新已有用户 ====================
                $setFields = [
                    'badge_no = :badge_no',
                    'full_name = :full_name',
                    'team = :team',
                    'role_id = :role_id',
                    'role = :role',            // ★ 修复：同步回写 role 列，彻底消灭历史残留的“管理员”文本
                    'phone = :phone',
                    'is_admin = :is_admin',
                    'is_active = :is_active'
                ];

                $params = [
                    ':badge_no'  => $badgeNo,
                    ':full_name' => $fullName,
                    ':team'      => $team,
                    ':role_id'   => $roleId,
                    ':role'      => $roleText,
                    ':phone'     => $phone,
                    ':is_admin'  => $isAdmin,
                    ':is_active' => $isActive,
                    ':id'        => $id
                ];

                // 核心修复点：如果有输入新密码，则哈希加密后更新 password
                if ($password !== '') {
                    $setFields[] = 'password = :password';
                    $setFields[] = 'password_updated_at = NOW()';
                    // ★ 非管理员被重置密码后，下次登录必须先自行修改为合规密码
                    $setFields[] = 'must_change_password = :must_change_password';
                    $params[':password'] = PasswordPolicy::hash($password);
                    $params[':must_change_password'] = $isAdmin === 1 ? 0 : 1;
                }

                $sql = "UPDATE users SET " . implode(', ', $setFields) . " WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);

                // ★★★ 核心机制2：治本修补。自动联动校正全库中所有非管理员用户的 role 字段 ★★★
                $this->db->exec("
                    UPDATE users u 
                    LEFT JOIN roles r ON u.role_id = r.id 
                    SET u.role = COALESCE(r.name, '普通用户') 
                    WHERE u.is_admin = 0 AND (u.role = '管理员' OR u.role IS NULL)
                ");

                if (class_exists('\\App\\Services\\AuditService')) {
                    AuditService::log('用户管理', "修改用户 [{$username}] 信息" . ($password !== '' ? ' (包含重置密码)' : ''));
                }
                Response::success('用户信息更新成功');
            } else {
                // ==================== 新增用户 ====================
                if ($password === '') {
                    Response::error('新增用户时密码不能为空');
                    return;
                }

                $chkStmt = $this->db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $chkStmt->execute([$username]);
                if ($chkStmt->fetch()) {
                    Response::error("用户名 [{$username}] 已存在，请更换");
                    return;
                }

                $hashedPassword = PasswordPolicy::hash($password);
                // ★ 新建的非管理员账号，首次登录必须自行修改密码
                $mustChangePassword = $isAdmin === 1 ? 0 : 1;

                $sql = "INSERT INTO users 
                        (badge_no, username, full_name, team, role_id, role, phone, password, is_admin, is_active, must_change_password, password_updated_at, created_at)
                        VALUES 
                        (:badge_no, :username, :full_name, :team, :role_id, :role, :phone, :password, :is_admin, :is_active, :must_change_password, NOW(), NOW())";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':badge_no'  => $badgeNo,
                    ':username'  => $username,
                    ':full_name' => $fullName,
                    ':team'      => $team,
                    ':role_id'   => $roleId,
                    ':role'      => $roleText,
                    ':phone'     => $phone,
                    ':password'  => $hashedPassword,
                    ':is_admin'  => $isAdmin,
                    ':is_active' => $isActive,
                    ':must_change_password' => $mustChangePassword
                ]);

                if (class_exists('\\App\\Services\\AuditService')) {
                    AuditService::log('用户管理', "新建用户 [{$username}]");
                }
                Response::success('用户创建成功', [
                    'id' => (int)$this->db->lastInsertId(),
                    'must_change_password' => $mustChangePassword,
                ]);
            }
        } catch (Throwable $e) {
            Response::exception($e, '保存用户失败');
        }
    }

    /**
     * 一键重置：把所有非管理员账号的密码统一重置为初始密码（123456），
     * 并标记为「首次登录需强制修改密码」。管理员账号不做任何调整。
     */
    public function resetAllPasswords(): void
    {
        try {
            Schema::ensureUserPasswordColumns($this->db);

            $sql = "UPDATE users u
                    LEFT JOIN roles r ON u.role_id = r.id
                    SET u.password = :p,
                        u.must_change_password = 1,
                        u.password_updated_at = NOW()
                    WHERE (u.is_admin = 0 OR u.is_admin IS NULL)
                      AND (r.code IS NULL OR r.code <> 'admin')";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':p' => PasswordPolicy::hash(PasswordPolicy::DEFAULT_PASSWORD)]);
            $count = $stmt->rowCount();

            AuditService::log('用户管理', "一键重置全部非管理员账号密码为初始密码（影响 {$count} 个账号）");

            Response::success(
                "已重置 {$count} 个非管理员账号的密码为 " . PasswordPolicy::DEFAULT_PASSWORD . "，他们下次登录时必须修改密码",
                ['count' => $count, 'default_password' => PasswordPolicy::DEFAULT_PASSWORD]
            );
        } catch (Throwable $e) {
            Response::exception($e, '批量重置密码失败');
        }
    }

    /**
     * 删除用户
     */
    public function deleteUser(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));

        if ($id <= 0) {
            Response::error('请提供有效的用户ID');
            return;
        }

        if ($id === (int)($this->currentUser['id'] ?? 0)) {
            Response::error('不能删除当前登录的账号');
            return;
        }

        try {
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);

            if (class_exists('\\App\\Services\\AuditService')) {
                AuditService::log('用户管理', "删除用户 ID: [{$id}]");
            }
            Response::success('用户已删除');
        } catch (Throwable $e) {
            Response::exception($e, '删除失败');
        }
    }

    /**
     * 角色列表
     */
    public function roles(): void
    {
        try {
            // 自动补齐预置岗位角色（如「实习学生」），幂等；
            // 这样新增角色不必再手工跑 SQL，各环境下拉选项自动一致
            Schema::ensurePresetRoles($this->db);

            $stmt = $this->db->query("SELECT * FROM roles ORDER BY id ASC");
            Response::success('success', $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            Response::exception($e, '获取角色列表失败');
        }
    }

    /**
     * 角色模块权限查询
     */
    public function roleModules(): void
    {
        try {
            $roleId = (int)($_GET['role_id'] ?? 0);
            $stmt = $this->db->prepare("SELECT module_id FROM role_modules WHERE role_id = ?");
            $stmt->execute([$roleId]);
            Response::success('success', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            Response::exception($e, '获取角色模块权限失败');
        }
    }

    /**
     * 保存角色模块权限
     */
    public function saveRolePermissions(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $roleId = (int)($input['role_id'] ?? 0);
        $moduleIds = $input['module_ids'] ?? [];

        if ($roleId <= 0) {
            Response::error('请选择角色');
            return;
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM role_modules WHERE role_id = ?")->execute([$roleId]);

            if (!empty($moduleIds)) {
                $ins = $this->db->prepare("INSERT INTO role_modules (role_id, module_id) VALUES (?, ?)");
                foreach ($moduleIds as $mId) {
                    $ins->execute([$roleId, (int)$mId]);
                }
            }
            $this->db->commit();
            Response::success('角色权限配置已保存');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::exception($e, '保存失败');
        }
    }

    /**
     * 用户个性化权限查询
     */
    public function userPermissions(): void
    {
        try {
            $userId = (int)($_GET['user_id'] ?? 0);
            if ($userId <= 0) {
                Response::error('无效用户');
                return;
            }

            // 确保权限表结构就绪（含自动补列），避免查询/写入报 "Unknown column"
            $this->ensureAllPermTables();

            $stmt = $this->db->prepare("
                SELECT m.id AS module_id, m.name AS module_name, m.module_code, m.category AS category_name,
                       COALESCE(up.can_view, 0) AS can_view,
                       COALESCE(up.can_edit, 0) AS can_edit
                FROM modules m
                LEFT JOIN user_permissions up ON m.id = up.module_id AND up.user_id = :uid
                ORDER BY m.sort_order ASC, m.id ASC
            ");
            $stmt->execute([':uid' => $userId]);
            $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $tracePerms = [];
            try {
                $traceStmt = $this->db->prepare("SELECT * FROM trace_permissions WHERE user_id = ? LIMIT 1");
                $traceStmt->execute([$userId]);
                $tracePerms = $traceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {}

            // ★ 条码验证比对 细分权限
            $barcodePerms = [];
            try {
                $this->ensureBarcodePermTable();
                $bStmt = $this->db->prepare("SELECT can_save_settings, can_delete, can_edit
                                             FROM barcode_validator_permissions WHERE user_id = ? LIMIT 1");
                $bStmt->execute([$userId]);
                $barcodePerms = $bStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {}

            // ★ 综合讨论交流区 细分权限
            $discussPerms = [];
            try {
                $this->ensureDiscussPermTable();
                $dStmt = $this->db->prepare("SELECT can_view, can_create_topic, can_reply, can_edit, can_delete
                                             FROM discuss_permissions WHERE user_id = ? LIMIT 1");
                $dStmt->execute([$userId]);
                $discussPerms = $dStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {}

            // ★ 首件检判据管理权限（供前端 fp_perms 回显）
            $fpPerms = [];
            try {
                $this->ensureFirstPiecePermTable();
                $fStmt = $this->db->prepare("SELECT can_manage_criteria FROM first_piece_permissions WHERE user_id = ? LIMIT 1");
                $fStmt->execute([$userId]);
                $fpPerms = $fStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {}

            Response::success('success', [
                'permissions'   => $perms,
                'trace_perms'   => $tracePerms,
                'barcode_perms' => $barcodePerms,
                'discuss_perms' => $discussPerms,
                'fp_perms'      => $fpPerms,
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取用户权限失败');
        }
    }

    /**
     * 保存用户个性化权限
     */
    public function saveUserPermissions(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $userId = (int)($input['user_id'] ?? 0);
        $permissions = $input['permissions'] ?? [];
        $tracePerms = $input['trace_perms'] ?? [];
        $barcodePerms = $input['barcode_perms'] ?? [];
        $discussPerms = $input['discuss_perms'] ?? [];
        $fpPerms = $input['fp_perms'] ?? [];

        if ($userId <= 0) {
            Response::error('无效用户');
            return;
        }

        // ★ 注意：CREATE TABLE / ALTER TABLE 等 DDL 会隐式提交事务，必须在 beginTransaction() 之前执行。
        //   这里一次性确保所有权限表存在且列齐全（自动补齐历史表缺失的列）。
        $this->ensureAllPermTables();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_permissions (user_id, module_id, can_view, can_edit, updated_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit), updated_at = NOW()
            ");

            foreach ($permissions as $p) {
                $mId = (int)($p['module_id'] ?? 0);
                if ($mId > 0) {
                    $stmt->execute([$userId, $mId, !empty($p['can_view']) ? 1 : 0, !empty($p['can_edit']) ? 1 : 0]);
                }
            }

            if (!empty($tracePerms)) {
                $stTrace = $this->db->prepare("
                    INSERT INTO trace_permissions (user_id, can_add_material, can_scan, can_view, can_edit_record, can_delete_record, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        can_add_material = VALUES(can_add_material),
                        can_scan = VALUES(can_scan),
                        can_view = VALUES(can_view),
                        can_edit_record = VALUES(can_edit_record),
                        can_delete_record = VALUES(can_delete_record),
                        updated_at = NOW()
                ");
                $stTrace->execute([
                    $userId,
                    !empty($tracePerms['can_add_material']) ? 1 : 0,
                    !empty($tracePerms['can_scan']) ? 1 : 0,
                    !empty($tracePerms['can_view']) ? 1 : 0,
                    !empty($tracePerms['can_edit_record']) ? 1 : 0,
                    !empty($tracePerms['can_delete_record']) ? 1 : 0,
                ]);
            }

            // ★ 条码验证比对 细分权限（验证设置 / 删除记录 / 编辑记录）
            //   （权限表已在事务开启前创建，此处只做写入，避免 DDL 隐式提交）
            if (!empty($barcodePerms)) {
                $stBarcode = $this->db->prepare("
                    INSERT INTO barcode_validator_permissions (user_id, can_save_settings, can_delete, can_edit, updated_at)
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        can_save_settings = VALUES(can_save_settings),
                        can_delete        = VALUES(can_delete),
                        can_edit          = VALUES(can_edit),
                        updated_at        = NOW()
                ");
                $stBarcode->execute([
                    $userId,
                    !empty($barcodePerms['can_save_settings']) ? 1 : 0,
                    !empty($barcodePerms['can_delete']) ? 1 : 0,
                    !empty($barcodePerms['can_edit']) ? 1 : 0,
                ]);
            }

            // ★ 综合讨论交流区 细分权限（发帖 / 查看 / 回复 / 编辑自己的帖子 / 删帖）
            if (!empty($discussPerms)) {
                $stDiscuss = $this->db->prepare("
                    INSERT INTO discuss_permissions (user_id, can_view, can_create_topic, can_reply, can_edit, can_delete, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        can_view         = VALUES(can_view),
                        can_create_topic = VALUES(can_create_topic),
                        can_reply        = VALUES(can_reply),
                        can_edit         = VALUES(can_edit),
                        can_delete       = VALUES(can_delete),
                        updated_at       = NOW()
                ");
                $stDiscuss->execute([
                    $userId,
                    !empty($discussPerms['can_view']) ? 1 : 0,
                    !empty($discussPerms['can_create_topic']) ? 1 : 0,
                    !empty($discussPerms['can_reply']) ? 1 : 0,
                    !empty($discussPerms['can_edit']) ? 1 : 0,
                    !empty($discussPerms['can_delete']) ? 1 : 0,
                ]);
            }

            // ★ 首件检「判据标准管理」权限
            //   （修复：前端一直在提交 fp_perms，但这里此前未落库，
            //     导致 first_piece_permissions.can_manage_criteria 永远为 0，
            //     非管理员无论如何都无法被授予判据管理权）
            if (is_array($fpPerms) && !empty($fpPerms)) {
                $stFp = $this->db->prepare("
                    INSERT INTO first_piece_permissions (user_id, can_manage_criteria, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        can_manage_criteria = VALUES(can_manage_criteria),
                        updated_at          = NOW()
                ");
                $stFp->execute([
                    $userId,
                    !empty($fpPerms['can_manage_criteria']) ? 1 : 0,
                ]);
            }

            $this->db->commit();
            Response::success('权限已成功保存');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::exception($e, '保存失败');
        }
    }

    /**
     * 补齐某张表缺失的列（存在则跳过）。用于兼容历史遗留的表结构。
     */
    private function ensureColumn(string $table, string $column, string $definition): void
    {
        try {
            $st = $this->db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $st->execute([$table, $column]);
            if ((int)$st->fetchColumn() === 0) {
                $this->db->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
            }
        } catch (Throwable $e) {}
    }

    /**
     * 确保「用户模块权限」表存在且包含所需列（幂等 + 自动补列）
     */
    private function ensureUserPermTable(): void
    {
        if (\App\Core\Schema::isReady('perm_user')) { return; }
        $this->db->exec("CREATE TABLE IF NOT EXISTS `user_permissions` (
          `id` INT PRIMARY KEY AUTO_INCREMENT,
          `user_id` INT NOT NULL,
          `module_id` INT NOT NULL,
          `can_view` TINYINT(1) DEFAULT 0,
          `can_edit` TINYINT(1) DEFAULT 0,
          `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `uk_user_module` (`user_id`,`module_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $this->ensureColumn('user_permissions', 'can_view', "`can_view` TINYINT(1) DEFAULT 0");
        $this->ensureColumn('user_permissions', 'can_edit', "`can_edit` TINYINT(1) DEFAULT 0");
        $this->ensureColumn('user_permissions', 'updated_at', "`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP");
        // 补齐唯一键，保证 ON DUPLICATE KEY UPDATE 生效（不存在时才添加）
        try {
            $idx = $this->db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_permissions' AND INDEX_NAME = 'uk_user_module'");
            $idx->execute();
            if ((int)$idx->fetchColumn() === 0) {
                $this->db->exec("ALTER TABLE `user_permissions` ADD UNIQUE KEY `uk_user_module` (`user_id`,`module_id`)");
            }
        } catch (Throwable $e) {}
        \App\Core\Schema::markReady('perm_user');
    }

    /**
     * 确保「质量追溯权限」表存在且包含所需列（幂等 + 自动补列）
     */
    private function ensureTracePermTable(): void
    {
        if (\App\Core\Schema::isReady('perm_trace')) { return; }
        $this->db->exec("CREATE TABLE IF NOT EXISTS `trace_permissions` (
          `id` INT PRIMARY KEY AUTO_INCREMENT,
          `user_id` INT NOT NULL,
          `can_add_material`  TINYINT(1) DEFAULT 0,
          `can_scan`          TINYINT(1) DEFAULT 1,
          `can_view`          TINYINT(1) DEFAULT 1,
          `can_edit_record`   TINYINT(1) DEFAULT 0,
          `can_delete_record` TINYINT(1) DEFAULT 0,
          `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `uk_trace_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $this->ensureColumn('trace_permissions', 'can_add_material', "`can_add_material` TINYINT(1) DEFAULT 0");
        $this->ensureColumn('trace_permissions', 'can_scan', "`can_scan` TINYINT(1) DEFAULT 1");
        $this->ensureColumn('trace_permissions', 'can_view', "`can_view` TINYINT(1) DEFAULT 1");
        $this->ensureColumn('trace_permissions', 'can_edit_record', "`can_edit_record` TINYINT(1) DEFAULT 0");
        $this->ensureColumn('trace_permissions', 'can_delete_record', "`can_delete_record` TINYINT(1) DEFAULT 0");
        \App\Core\Schema::markReady('perm_trace');
    }

    /**
     * 确保「条码验证比对」权限表存在且包含所需列（幂等 + 自动补列）
     */
    private function ensureBarcodePermTable(): void
    {
        if (\App\Core\Schema::isReady('perm_barcode')) { return; }
        $this->db->exec("CREATE TABLE IF NOT EXISTS `barcode_validator_permissions` (
          `id` INT PRIMARY KEY AUTO_INCREMENT,
          `user_id` INT NOT NULL,
          `can_save_settings` TINYINT(1) DEFAULT 1 COMMENT '验证字段设置',
          `can_delete`        TINYINT(1) DEFAULT 0 COMMENT '删除记录',
          `can_edit`          TINYINT(1) DEFAULT 0 COMMENT '编辑记录',
          `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `uk_barcode_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $this->ensureColumn('barcode_validator_permissions', 'can_save_settings', "`can_save_settings` TINYINT(1) DEFAULT 1");
        $this->ensureColumn('barcode_validator_permissions', 'can_delete', "`can_delete` TINYINT(1) DEFAULT 0");
        $this->ensureColumn('barcode_validator_permissions', 'can_edit', "`can_edit` TINYINT(1) DEFAULT 0");
        \App\Core\Schema::markReady('perm_barcode');
    }

    /**
     * 确保「综合讨论交流区」权限表存在且包含所需列（幂等 + 自动补列）
     */
    private function ensureDiscussPermTable(): void
    {
        if (\App\Core\Schema::isReady('perm_discuss')) { return; }
        $this->db->exec("CREATE TABLE IF NOT EXISTS `discuss_permissions` (
          `id` INT PRIMARY KEY AUTO_INCREMENT,
          `user_id` INT NOT NULL,
          `can_view`         TINYINT(1) DEFAULT 1 COMMENT '查看',
          `can_create_topic` TINYINT(1) DEFAULT 1 COMMENT '发帖',
          `can_reply`        TINYINT(1) DEFAULT 1 COMMENT '回复',
          `can_edit`         TINYINT(1) DEFAULT 1 COMMENT '编辑自己的帖子',
          `can_delete`       TINYINT(1) DEFAULT 0 COMMENT '删帖(仅管理员)',
          `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `uk_discuss_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $this->ensureColumn('discuss_permissions', 'can_view', "`can_view` TINYINT(1) DEFAULT 1");
        $this->ensureColumn('discuss_permissions', 'can_create_topic', "`can_create_topic` TINYINT(1) DEFAULT 1");
        $this->ensureColumn('discuss_permissions', 'can_reply', "`can_reply` TINYINT(1) DEFAULT 1");
        $this->ensureColumn('discuss_permissions', 'can_edit', "`can_edit` TINYINT(1) DEFAULT 1");
        $this->ensureColumn('discuss_permissions', 'can_delete', "`can_delete` TINYINT(1) DEFAULT 0");
        \App\Core\Schema::markReady('perm_discuss');
    }

    /**
     * 一次性确保全部权限相关表结构就绪（必须在事务开始前调用，DDL 会隐式提交事务）
     */
    private function ensureAllPermTables(): void
    {
        try { $this->ensureUserPermTable(); }    catch (Throwable $e) {}
        try { $this->ensureTracePermTable(); }   catch (Throwable $e) {}
        try { $this->ensureBarcodePermTable(); } catch (Throwable $e) {}
        try { $this->ensureDiscussPermTable(); } catch (Throwable $e) {}
        try { $this->ensureFirstPiecePermTable(); } catch (Throwable $e) {}
    }

    /**
     * 确保「首件检判据管理权限」表存在（幂等）
     * 说明：该表由 FirstPieceController 首次访问时创建；此处兜底创建，
     *       避免管理员在「权限分配」页直接授权时因表不存在而写入失败。
     */
    private function ensureFirstPiecePermTable(): void
    {
        if (\App\Core\Schema::isReady('perm_first_piece')) { return; }
        $this->db->exec("CREATE TABLE IF NOT EXISTS `first_piece_permissions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `can_manage_criteria` tinyint(1) NOT NULL DEFAULT 0,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        \App\Core\Schema::markReady('perm_first_piece');
    }

    /**
     * 审计日志列表
     */
    public function auditLogs(): void
    {
        try {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(1, (int)($_GET['limit'] ?? 15));
            $offset = ($page - 1) * $limit;

            $total = (int)$this->db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
            $stmt = $this->db->prepare("SELECT * FROM audit_logs ORDER BY id DESC LIMIT {$offset}, {$limit}");
            $stmt->execute();
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'list'  => $list,
                'total' => $total
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取审计日志失败');
        }
    }

    /**
     * 模块管理 - 列表
     */
    public function modules(): void
    {
        try {
            $category = trim((string)($_GET['category'] ?? ''));
            $search = trim((string)($_GET['search'] ?? ''));

            $where = ["1=1"];
            $params = [];

            if ($category !== '') {
                $where[] = "category = ?";
                $params[] = $category;
            }
            if ($search !== '') {
                $kw = "%{$search}%";
                $where[] = "(name LIKE ? OR module_code LIKE ? OR description LIKE ?)";
                $params[] = $kw;
                $params[] = $kw;
                $params[] = $kw;
            }

            $sql = "SELECT * FROM modules WHERE " . implode(" AND ", $where) . " ORDER BY sort_order ASC, id ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $cats = $this->db->query("SELECT DISTINCT category FROM modules WHERE category IS NOT NULL AND category != ''")->fetchAll(PDO::FETCH_COLUMN);

            Response::success('success', [
                'list'       => $list,
                'categories' => $cats,
                'total'      => count($list)
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取模块列表失败');
        }
    }

    /**
     * 模块管理 - 保存
     */
    public function saveModule(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $code = trim((string)($input['module_code'] ?? ''));
        $category = trim((string)($input['category'] ?? ''));
        $icon = trim((string)($input['icon'] ?? 'Menu'));
        $route = trim((string)($input['route'] ?? ''));
        $sortOrder = (int)($input['sort_order'] ?? 10);
        $description = trim((string)($input['description'] ?? ''));

        if ($name === '' || $code === '') {
            Response::error('模块名称和代码不能为空');
            return;
        }

        try {
            if ($id > 0) {
                $stmt = $this->db->prepare("UPDATE modules SET name = ?, module_code = ?, category = ?, icon = ?, route = ?, sort_order = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $code, $category, $icon, $route, $sortOrder, $description, $id]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO modules (name, module_code, category, icon, route, sort_order, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$name, $code, $category, $icon, $route, $sortOrder, $description]);
            }
            Response::success('模块保存成功');
        } catch (Throwable $e) {
            Response::exception($e, '保存失败');
        }
    }

    /**
     * 模块管理 - 切换状态
     */
    public function toggleModuleStatus(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            Response::error('无效模块ID');
            return;
        }
        try {
            $this->db->prepare("UPDATE modules SET status = IF(status = 1, 0, 1) WHERE id = ?")->execute([$id]);
            Response::success('状态变更成功');
        } catch (Throwable $e) {
            Response::exception($e, '操作失败');
        }
    }

    /**
     * 模块管理 - 删除
     */
    public function deleteModule(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            Response::error('无效模块ID');
            return;
        }
        try {
            $this->db->prepare("DELETE FROM modules WHERE id = ?")->execute([$id]);
            Response::success('模块已删除');
        } catch (Throwable $e) {
            Response::exception($e, '删除失败');
        }
    }

    /**
     * 模块管理 - 同步
     */
    public function syncModules(): void
    {
        Response::success('模块配置已同步');
    }
}