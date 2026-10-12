<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use PDO;
use Throwable;

// 确保核心依赖类随时就绪
require_once __DIR__ . "/../Core/Database.php";
require_once __DIR__ . "/../Core/Response.php";

class TraceController
{
    private PDO $db;
    private array $user;
    private array $userPerms;

    public function __construct()
    {
        $this->db = Database::getConnection();
        
        // ★ 安全修复：会话缺失时不再自动以数据库中的管理员身份登录，必须重新登录
        if (empty($_SESSION["user"])) {
            Response::error("登录已失效，请重新登录", ["need_login" => true], 401);
            exit;
        }
        
        $this->user = $_SESSION["user"];
        $this->userPerms = $this->calcUserPerms();
    }

    private function calcUserPerms(): array
    {
        $isAdmin = !empty($this->user["is_admin"]) || ($this->user["username"] ?? "") === "admin";
        if ($isAdmin) {
            return [
                "can_add_material"  => 1,
                "can_scan"          => 1,
                "can_view"          => 1,
                "can_edit_record"   => 1,
                "can_delete_record" => 1,
                "is_admin"          => 1
            ];
        }
        $st = $this->db->prepare("SELECT can_add_material, can_scan, can_view, can_edit_record, can_delete_record FROM trace_permissions WHERE user_id = ? LIMIT 1");
        $st->execute([$this->user["id"]]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        return [
            "can_add_material"  => $p ? (int)$p["can_add_material"] : 0,
            "can_scan"          => $p ? (int)$p["can_scan"] : 1,
            "can_view"          => $p ? (int)$p["can_view"] : 1,
            "can_edit_record"   => $p ? (int)$p["can_edit_record"] : 0,
            "can_delete_record" => $p ? (int)$p["can_delete_record"] : 0,
            "is_admin"          => 0
        ];
    }

    public function init(): void
    {
        Response::success("success", [
            "user_perms" => $this->userPerms,
            "user"       => [
                "id"        => $this->user["id"],
                "username"  => $this->user["username"],
                "full_name" => $this->user["full_name"] ?? "",
                "is_admin"  => $this->userPerms["is_admin"]
            ]
        ]);
    }

            public function listConfigs(): void
    {
        try {
            $page   = max(1, (int)($_GET["page"] ?? 1));
            $limit  = 15;
            $offset = ($page - 1) * $limit;

            $total = (int)$this->db->query("SELECT COUNT(*) FROM trace_tasks")->fetchColumn();

            // 查出全量用户字典（以工号 badge_no 和 id 作为索引）
            $uStmt = $this->db->query("SELECT id, username, full_name, badge_no FROM users");
            $usersList = $uStmt->fetchAll(PDO::FETCH_ASSOC);
            $userByBadge = [];
            $userById = [];
            foreach ($usersList as $u) {
                $name = !empty($u["full_name"]) ? $u["full_name"] : $u["username"];
                if (!empty($u["badge_no"])) {
                    $userByBadge[(string)$u["badge_no"]] = $name;
                    $userByBadge[ltrim((string)$u["badge_no"], "0")] = $name;
                }
                $userById[(string)$u["id"]] = $name;
            }

            // 特殊历史编号直接映射到对应人员名字
            $specialMap = [
                "1"  => $userByBadge["10279"] ?? "李日辉",
                "19" => $userByBadge["01531"] ?? $userByBadge["1531"] ?? "刘莉",
                "26" => $userByBadge["20280"] ?? "孙远超",
                "71" => $userByBadge["20280"] ?? "孙远超",
            ];

            $sql = "SELECT t.*,
                           (SELECT COUNT(*) FROM trace_boxes WHERE task_order = t.task_order AND status = 1) as completed_count
                    FROM trace_tasks t
                    ORDER BY t.id DESC
                    LIMIT {$offset}, {$limit}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 格式化每一行，彻底杜绝纯数字 ID 泄露到前端
            foreach ($list as &$item) {
                $rawCreated = trim((string)($item["created_by"] ?? ""));
                
                // 优先级：1. 特殊历史对照 2. 工号对照 3. 兜底原值
                $realName = $specialMap[$rawCreated] 
                            ?? $userByBadge[$rawCreated] 
                            ?? $userByBadge[ltrim($rawCreated, "0")] 
                            ?? $userById[$rawCreated] 
                            ?? $rawCreated;

                $item["creator_name"]     = $realName;
                $item["created_by_name"]  = $realName;
                $item["creator"]          = $realName;
                // 同时把 created_by 也赋为人名，防止前端直接使用 {{ row.created_by }}
                $item["created_by_raw"]   = $rawCreated;
                $item["created_by"]       = $realName;
            }

            Response::success("success", [
                "list"        => $list,
                "tasks"       => $list,
                "total"       => $total,
                "total_pages" => (int)ceil($total / $limit),
                "page"        => $page
            ]);
        } catch (Throwable $e) {
            Response::exception($e, "获取单据配置库异常");
        }
    }

    public function queryRecords(): void
    {
        if (empty($this->userPerms["can_view"])) {
            Response::error("您没有查看追溯记录的权限！", [], 403);
            return;
        }

        try {
            $search = trim($_GET["search"] ?? "");
            $page   = max(1, (int)($_GET["page"] ?? 1));
            $limit  = 30;
            $offset = ($page - 1) * $limit;

            $where = ["1=1"];
            $params = [];

            if ($search !== "") {
                $where[] = "(b.task_order LIKE ? OR b.box_sn LIKE ? OR s.child_code LIKE ? OR s.child_sn LIKE ?)";
                for ($i = 0; $i < 4; $i++) {
                    $params[] = "%" . $search . "%";
                }
            }

            $whereSql = implode(" AND ", $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM trace_boxes b INNER JOIN trace_scans s ON b.id = s.box_id WHERE {$whereSql}");
            $cntStmt->execute($params);
            $total = (int)$cntStmt->fetchColumn();

            // 优先按工号（兼容带0和不带0）以及用户名匹配，彻底避免新旧主键错配
                                    $sql = "SELECT b.task_order, b.box_sn, b.parent_code, s.id as scan_id, s.scan_point, s.child_code, s.child_sn, s.scanned_at,
                           u.badge_no as scanner_emp_no,
                           COALESCE(NULLIF(u.full_name, ''), u.username, s.scanned_by) as scanner_name,
                           u.username as scanner_user
                    FROM trace_boxes b
                    INNER JOIN trace_scans s ON b.id = s.box_id
                    LEFT JOIN users u ON (
                        CAST(u.badge_no AS CHAR) = CAST(s.scanned_by AS CHAR)
                        OR TRIM(LEADING '0' FROM CAST(u.badge_no AS CHAR)) = TRIM(LEADING '0' FROM CAST(s.scanned_by AS CHAR))
                        OR u.full_name = s.scanned_by
                        OR u.username = s.scanned_by
                    )
                    WHERE {$whereSql}
                    ORDER BY s.id DESC
                    LIMIT {$offset}, {$limit}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success("success", [
                "records"     => $records,
                "total"       => $total,
                "total_pages" => (int)ceil($total / $limit),
                "page"        => $page
            ]);
        } catch (Throwable $e) {
            Response::exception($e, "查询追溯记录异常");
        }
    }

    public function checkOrFetchErp(): void { $this->execLegacy("check_or_fetch_erp"); }
    public function saveConfig(): void { $this->execLegacy("save_config"); }
    public function startTrace(): void { $this->execLegacy("start_trace"); }
    public function scanBarcode(): void { $this->execLegacy("scan_barcode"); }
    public function updateScanRecord(): void { $this->execLegacy("update_scan_record"); }
    public function deleteScanRecord(): void { $this->execLegacy("delete_scan_record"); }
    
    
    public function manualPushCem(): void { $this->execLegacy("manual_push_cem"); }
    public function exportExcel(): void { $this->execLegacy("export_excel"); }

            public function listPermissions(): void
    {
        if (empty($this->userPerms["is_admin"])) {
            Response::error("只有管理员可以管理权限！", [], 403);
            return;
        }

        try {
            $sql = "SELECT u.id, u.id as user_id, u.username, u.full_name, u.badge_no, u.role, u.is_admin,
                           COALESCE(p.can_add_material, 0) as can_add_material,
                           COALESCE(p.can_scan, 1) as can_scan,
                           COALESCE(p.can_view, 1) as can_view,
                           COALESCE(p.can_edit_record, 0) as can_edit_record,
                           COALESCE(p.can_delete_record, 0) as can_delete_record
                    FROM users u
                    LEFT JOIN trace_permissions p ON u.id = p.user_id
                    WHERE u.is_active = 1
                    ORDER BY u.is_admin DESC, u.id ASC";

            $stmt = $this->db->query($sql);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($users as &$item) {
                $item["id"]                = (int)$item["id"];
                $item["user_id"]           = (int)$item["user_id"];
                $item["can_add_material"]  = (int)$item["can_add_material"];
                $item["can_scan"]          = (int)$item["can_scan"];
                $item["can_view"]          = (int)$item["can_view"];
                $item["can_edit_record"]   = (int)$item["can_edit_record"];
                $item["can_delete_record"] = (int)$item["can_delete_record"];
                $item["is_admin"]          = (int)$item["is_admin"];
            }

            Response::success("success", [
                "list"        => $users,
                "users"       => $users,
                "permissions" => $users
            ]);
        } catch (Throwable $e) {
            Response::exception($e, "获取权限列表异常");
        }
    }

    public function savePermissions(): void
    {
        if (empty($this->userPerms["is_admin"])) {
            Response::error("只有管理员可以修改权限！", [], 403);
            return;
        }

        try {
            $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
            $userId          = (int)($input["user_id"] ?? 0);
            $canAddMaterial  = !empty($input["can_add_material"]) ? 1 : 0;
            $canScan         = !empty($input["can_scan"]) ? 1 : 0;
            $canView         = !empty($input["can_view"]) ? 1 : 0;
            $canEditRecord   = !empty($input["can_edit_record"]) ? 1 : 0;
            $canDeleteRecord = !empty($input["can_delete_record"]) ? 1 : 0;

            if ($userId <= 0) {
                Response::error("无效的用户ID");
                return;
            }

            $sql = "INSERT INTO trace_permissions (user_id, can_add_material, can_scan, can_view, can_edit_record, can_delete_record, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                    can_add_material = VALUES(can_add_material),
                    can_scan = VALUES(can_scan),
                    can_view = VALUES(can_view),
                    can_edit_record = VALUES(can_edit_record),
                    can_delete_record = VALUES(can_delete_record),
                    updated_at = NOW()";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $canAddMaterial, $canScan, $canView, $canEditRecord, $canDeleteRecord]);

            AuditService::log('质量追溯', '保存追溯权限');
            Response::success("权限保存成功");
        } catch (Throwable $e) {
            Response::exception($e, "保存权限异常");
        }
    }

    private function execLegacy(string $act): void
    {
        $_GET["action"] = $act;
        $_POST["action"] = $act;
        $_REQUEST["action"] = $act;

        $user = $this->user;
        $db = $this->db;
        $userPerms = $this->userPerms;
        $isAdmin = ($userPerms["is_admin"] === 1);

        require __DIR__ . "/../legacy_trace.php";
        exit;
    }
}
