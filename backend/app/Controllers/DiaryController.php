<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use Exception;
use PDO;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class DiaryController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;

    public function __construct()
    {
        $this->db = Database::getConnection('default');
        $this->currentUser = function_exists('checkUser') ? checkUser() : ($_SESSION['user'] ?? []);
        $username = (string)($this->currentUser['username'] ?? '');
        $this->isAdmin = !empty($this->currentUser['is_admin']) || $username === 'admin';
        \App\Core\Schema::ensureOnce('diary', function () { $this->initTables(); });
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `work_diaries` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `work_date` date NOT NULL,
              `task_order` varchar(50) DEFAULT NULL,
              `product_code` varchar(50) DEFAULT NULL,
              `description` text,
              `task_type` varchar(50) DEFAULT NULL,
              `work_content` varchar(100) NOT NULL,
              `quantity` int(11) DEFAULT 0,
              `duration` int(11) NOT NULL,
              `operator` varchar(100) NOT NULL,
              `is_ghost_write` enum('是','否') DEFAULT '否',
              `remarks` text,
              `created_by` int(11) NOT NULL,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_work_date` (`work_date`),
              INDEX `idx_task_order` (`task_order`),
              INDEX `idx_created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $userCols = $this->db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('can_view_all', $userCols, true)) {
                $this->db->exec("ALTER TABLE `users` ADD `can_view_all` tinyint(1) NOT NULL DEFAULT 0");
            }
        } catch (Throwable $e) {}
    }

    private function getErpSqlSrvConnection(): ?PDO
    {
        try {
            // ★ 凭据一律取自 .env（无源码兜底），配置缺失直接拒绝连接
            $cfg = \App\Core\ExternalDb::sqlsrvConfig();
            if ($cfg === null) return null;
            [$sqlsrv_host, $sqlsrv_port, $sqlsrv_db, $sqlsrv_user, $sqlsrv_pass] = $cfg;

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 10,
            ];
            $dsn = "dblib:host={$sqlsrv_host}:{$sqlsrv_port};dbname={$sqlsrv_db};charset=UTF-8";
            $pdo = new PDO($dsn, $sqlsrv_user, $sqlsrv_pass, $options);
            $pdo->exec("SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; SET ANSI_PADDING ON; SET CONCAT_NULL_YIELDS_NULL ON; SET QUOTED_IDENTIFIER ON;");
            return $pdo;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getMesConnection(): ?PDO
    {
        try {
            // ★ 凭据一律取自 .env（无源码兜底），配置缺失直接拒绝连接
            $cfg = \App\Core\ExternalDb::mesConfig();
            if ($cfg === null) return null;
            [$mes_host, $mes_port, $mes_db, $mes_user, $mes_pass] = $cfg;

            return new PDO("mysql:host={$mes_host};port={$mes_port};dbname={$mes_db};charset=utf8mb4", $mes_user, $mes_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5
            ]);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getDiaryManagedTeams(): ?array
    {
        if ($this->isAdmin) return null;

        $role = strtolower((string)($this->currentUser['role'] ?? ''));
        $team = strtolower((string)($this->currentUser['team'] ?? ''));
        $groupName = strtolower((string)($this->currentUser['group_name'] ?? ''));
        $dept = strtolower((string)($this->currentUser['department'] ?? ''));
        $fullName = strtolower((string)($this->currentUser['full_name'] ?? ''));
        $username = strtolower((string)($this->currentUser['username'] ?? ''));

        // ★ 关键：只有"管理角色"才拥有"所辖人员"范围。
        //   普通作业员即使所属班组包含"调试/装配/包装"等字样，也只能看到自己填写的内容。
        $isManager = (bool)preg_match(
            '/(班长|组长|主管|领班|主任|部长|科长|经理|foreman|leader|supervisor)/i',
            $role . ' ' . $groupName . ' ' . $dept . ' ' . $team
        );
        if (!$isManager) {
            return null; // → 仅本人
        }

        $teams = [];
        foreach ([$team, $groupName, $dept] as $g) {
            if ($g !== '' && $g !== '全部' && $g !== '全厂' && !in_array($g, $teams, true)) {
                $teams[] = $g;
            }
        }

        // 兼容既有：按关键词/姓名映射到三大班组（仅对管理角色生效）
        $combined = $role . '|' . $team . '|' . $groupName . '|' . $dept . '|' . $fullName . '|' . $username;
        if (str_contains($combined, '装配') || str_contains($combined, '孙远超') || str_contains($combined, 'sunyuanchao')) {
            if (!in_array('装置装配', $teams, true)) $teams[] = '装置装配';
        }
        if (str_contains($combined, '调试') || str_contains($combined, '刘少俊') || str_contains($combined, 'liushaojun') ||
            str_contains($combined, '鲍敏') || str_contains($combined, '徐祥志')) {
            if (!in_array('装置调试', $teams, true)) $teams[] = '装置调试';
        }
        if (str_contains($combined, '包装') || str_contains($combined, '楚永杰') || str_contains($combined, 'zhangyanrong')) {
            if (!in_array('装置包装', $teams, true)) $teams[] = '装置包装';
        }

        return !empty($teams) ? $teams : null;
    }

    private function buildDiaryPermissionWhere(): array
    {
        if ($this->isAdmin) {
            return ['sql' => '1=1', 'params' => [], 'has_full' => true, 'managed_ids' => []];
        }

        // ★ 不再依据 users.can_view_all 放开"查看全部"：
        //   该字段易被误设/默认值影响，导致普通作业员看到所有人的日记。
        //   现在严格按角色：超管=全部；班长=所辖人员；其余=仅本人。
        //   （如需为个别人开通"查看全部"，请改为在权限分配中按用户显式授权。）

        $managedTeams = $this->getDiaryManagedTeams();
        if ($managedTeams !== null) {
            $teamPh = implode(',', array_fill(0, count($managedTeams), '?'));
            $uStmt = $this->db->prepare("SELECT id FROM users WHERE team IN ($teamPh)");
            $uStmt->execute($managedTeams);
            $managedUserIds = $uStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $swUserIds = [];
            try {
                $swStmt = $this->db->prepare("SELECT user_id FROM skill_workers WHERE (team IN ($teamPh) OR foreman_id = ?) AND user_id IS NOT NULL");
                $swParams = array_merge($managedTeams, [(int)($this->currentUser['id'] ?? 0)]);
                $swStmt->execute($swParams);
                $swUserIds = $swStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Throwable $e) {}

            $allManagedIds = array_unique(array_merge([(int)($this->currentUser['id'] ?? 0)], $managedUserIds, $swUserIds));
            $allManagedIds = array_map('intval', array_filter($allManagedIds));

            if (!empty($allManagedIds)) {
                $idPh = implode(',', array_fill(0, count($allManagedIds), '?'));
                return [
                    'sql' => "d.created_by IN ($idPh)",
                    'params' => array_values($allManagedIds),
                    'has_full' => false,
                    'managed_ids' => $allManagedIds
                ];
            }
        }

        return [
            'sql' => "d.created_by = ?",
            'params' => [(int)($this->currentUser['id'] ?? 0)],
            'has_full' => false,
            'managed_ids' => [(int)($this->currentUser['id'] ?? 0)]
        ];
    }

    private function getQuantityQuota(string $task_order, string $work_content, int $exclude_id): array
    {
        $task_order = trim($task_order);
        $work_content = trim($work_content);
        if (empty($task_order) || empty($work_content)) {
            return ['has_limit' => false, 'total_quantity' => 0, 'used_quantity' => 0, 'remaining' => 0];
        }

        $total = 0;
        $sqlsrv = $this->getErpSqlSrvConnection();
        if ($sqlsrv) {
            try {
                $stmt = $sqlsrv->prepare("SELECT TOP 1 [数量] as total FROM [J_全离散任务] WHERE ([任务] = ? OR [编码] = ?) AND [状态] <> '已取消'");
                $stmt->execute([$task_order, $task_order]);
                $tVal = $stmt->fetchColumn();
                if ($tVal !== false && $tVal !== null) {
                    $total = (int)$tVal;
                }
            } catch (Throwable $e) {}
        }

        if ($total <= 0) {
            $mesPdo = $this->getMesConnection();
            if ($mesPdo) {
                try {
                    $stmtPlan = $mesPdo->prepare("SELECT task_number FROM tab_plan WHERE task_no = ? OR mater_no = ? LIMIT 1");
                    $stmtPlan->execute([$task_order, $task_order]);
                    $pVal = $stmtPlan->fetchColumn();
                    if ($pVal !== false && $pVal !== null) {
                        $total = (int)$pVal;
                    }
                } catch (Throwable $e) {}
            }
        }

        if ($total <= 0) {
            return ['has_limit' => false, 'total_quantity' => 0, 'used_quantity' => 0, 'remaining' => 0];
        }

        $sql = "SELECT COALESCE(SUM(quantity), 0) as used FROM work_diaries WHERE task_order = ? AND work_content = ?";
        $params = [$task_order, $work_content];
        if ($exclude_id > 0) {
            $sql .= " AND id != ?";
            $params[] = $exclude_id;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $used = (int)$stmt->fetchColumn();
        $remaining = max(0, $total - $used);

        return [
            'has_limit'      => true,
            'total_quantity' => $total,
            'used_quantity'  => $used,
            'remaining'      => $remaining
        ];
    }

    public function searchTask(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;
        $order = trim((string)($input['task_order'] ?? ''));

        if (empty($order)) {
            Response::error('工单号不能为空');
            return;
        }

        $found = false;
        $taskInfo = null;

        $sqlsrv = $this->getErpSqlSrvConnection();
        if ($sqlsrv) {
            try {
                $stmt = $sqlsrv->prepare("
                    SELECT TOP 1 [任务] as task, [编码] as code, [描述] as description, [数量] as quantity, [分类] as category
                    FROM [J_全离散任务]
                    WHERE ([任务] = ? OR [编码] = ?) AND [状态] <> '已取消'
                    ORDER BY [任务] DESC
                ");
                $stmt->execute([$order, $order]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $desc = trim(mb_convert_encoding($row['description'] ?? '', 'UTF-8', 'UTF-8,GBK,GB2312'));
                    $taskType = trim(mb_convert_encoding($row['category'] ?? '', 'UTF-8', 'UTF-8,GBK,GB2312'));
                    $taskInfo = [
                        'product_code'   => trim((string)$row['code']),
                        'description'    => $desc,
                        'total_quantity' => (int)$row['quantity'],
                        'task_type'      => $taskType
                    ];
                    $found = true;
                }
            } catch (Throwable $e) {}
        }

        if (!$found) {
            $mesPdo = $this->getMesConnection();
            if ($mesPdo) {
                try {
                    $pStmt = $mesPdo->prepare("SELECT task_no, mater_no, mater_desc, task_number FROM tab_plan WHERE task_no = ? OR mater_no = ? LIMIT 1");
                    $pStmt->execute([$order, $order]);
                    $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
                    if ($pRow) {
                        $taskInfo = [
                            'product_code'   => trim((string)$pRow['mater_no']),
                            'description'    => trim((string)$pRow['mater_desc']),
                            'total_quantity' => (int)$pRow['task_number'],
                            'task_type'      => '离散任务'
                        ];
                        $found = true;
                    }
                } catch (Throwable $e) {}
            }
        }

        Response::success('success', ['found' => $found, 'task_info' => $taskInfo]);
    }

    public function checkQuantity(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;

        $order = trim((string)($input['task_order'] ?? ''));
        $wc    = trim((string)($input['work_content'] ?? ''));
        $qty   = (int)($input['quantity'] ?? 0);
        $exId  = (int)($input['exclude_id'] ?? 0);

        $quota = $this->getQuantityQuota($order, $wc, $exId);

        if (!$quota['has_limit']) {
            Response::success('success', [
                'available'      => true,
                'has_limit'      => false,
                'remaining'      => 0,
                'total_quantity' => 0,
                'used_quantity'  => 0
            ]);
            return;
        }

        $available = ($qty <= $quota['remaining']);
        Response::success('success', [
            'available'      => $available,
            'has_limit'      => true,
            'remaining'      => $quota['remaining'],
            'total_quantity' => $quota['total_quantity'],
            'used_quantity'  => $quota['used_quantity']
        ]);
    }

    public function list(): void
    {
        try {
            $search = trim((string)($_GET['search_all'] ?? ''));
            $start = trim((string)($_GET['filter_start_date'] ?? ''));
            $end = trim((string)($_GET['filter_end_date'] ?? ''));
            $content = trim((string)($_GET['filter_work_content'] ?? ''));
            $operator = trim((string)($_GET['filter_operator'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 30;
            $offset = ($page - 1) * $perPage;

            $perm = $this->buildDiaryPermissionWhere();
            $where = [$perm['sql']];
            $params = $perm['params'];

            if ($search !== '') {
                $kw = "%{$search}%";
                $where[] = "(d.task_order LIKE ? OR d.product_code LIKE ? OR d.description LIKE ? OR d.remarks LIKE ? OR d.work_content LIKE ? OR d.operator LIKE ? OR d.task_type LIKE ?)";
                for ($i = 0; $i < 7; $i++) {
                    $params[] = $kw;
                }
            }
            if ($start !== '') {
                $where[] = "d.work_date >= ?";
                $params[] = $start;
            }
            if ($end !== '') {
                $where[] = "d.work_date <= ?";
                $params[] = $end;
            }
            if ($content !== '') {
                $where[] = "d.work_content = ?";
                $params[] = $content;
            }
            if ($operator !== '') {
                $where[] = "d.operator LIKE ?";
                $params[] = "%{$operator}%";
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM work_diaries d WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($totalRecords / $perPage);

            $sql = "SELECT d.* FROM work_diaries d WHERE {$whereSql} ORDER BY d.work_date DESC, d.id DESC LIMIT {$offset}, {$perPage}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($records as &$r) {
                $creatorId = (int)$r['created_by'];
                $r['can_edit'] = $this->isAdmin || $perm['has_full'] || in_array($creatorId, $perm['managed_ids'], true) || ($creatorId === (int)($this->currentUser['id'] ?? 0));
            }
            unset($r);

            Response::success('success', [
                'records'       => $records,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'current_page'  => $page,
                'has_full'      => $perm['has_full'],
                'is_admin'      => $this->isAdmin,
                'user_id'       => $this->currentUser['id'] ?? 0
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取日记列表失败');
        }
    }

    public function add(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $work_date = trim((string)($input['work_date'] ?? ''));
            $task_order = trim((string)($input['task_order'] ?? ''));
            $product_code = trim((string)($input['product_code'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $task_type = trim((string)($input['task_type'] ?? ''));
            $work_content = trim((string)($input['work_content'] ?? ''));
            $quantity = (int)($input['quantity'] ?? 0);
            $duration = (int)($input['duration'] ?? 0);
            $operator = trim((string)($input['operator'] ?? ''));
            $is_ghost_write = trim((string)($input['is_ghost_write'] ?? '否'));
            $remarks = trim((string)($input['remarks'] ?? ''));

            if (empty($work_date) || empty($work_content) || $duration <= 0) {
                Response::error('工作日期、工作内容和工作时长必须填写且时长大于0');
                return;
            }

            if ($work_content === '返工') {
                if (empty($task_order)) { Response::error('选择"返工"时，工单号必须填写'); return; }
                if (empty($description)) { Response::error('选择"返工"时，描述必须填写'); return; }
                if ($quantity <= 0) { Response::error('选择"返工"时，数量必须大于0'); return; }
            }

            if ($work_content === '其他' && empty($remarks)) {
                Response::error('选择"其他"工作内容时，备注说明必须填写');
                return;
            }

            if (!empty($task_order) && $quantity > 0) {
                $quota = $this->getQuantityQuota($task_order, $work_content, 0);
                if ($quota['has_limit'] && $quantity > $quota['remaining']) {
                    Response::error("工单【{$task_order}】在【{$work_content}】工序总数量为 {$quota['total_quantity']} 个，其他记录已填写 {$quota['used_quantity']} 个，最多还可填写 {$quota['remaining']} 个！");
                    return;
                }
            }

            if (empty($operator)) {
                $operator = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : ($this->currentUser['username'] ?? '未知');
            }

            $sql = "INSERT INTO work_diaries 
                (work_date, task_order, product_code, description, task_type, work_content, quantity, duration, operator, is_ghost_write, remarks, created_by, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $work_date, $task_order, $product_code, $description, $task_type,
                $work_content, $quantity, $duration, $operator, $is_ghost_write,
                $remarks, (int)($this->currentUser['id'] ?? 0)
            ]);

            AuditService::log('工作日记', '新增工作日记');
            Response::success('工作日记添加成功', ['id' => (int)$this->db->lastInsertId()]);
        } catch (Throwable $e) {
            Response::exception($e, '添加日记失败');
        }
    }

    public function update(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $id = (int)($input['record_id'] ?? ($input['id'] ?? 0));
            if ($id <= 0) {
                Response::error('记录ID无效');
                return;
            }

            $stmt = $this->db->prepare("SELECT created_by FROM work_diaries WHERE id = ?");
            $stmt->execute([$id]);
            $rec = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rec) {
                Response::error('记录不存在');
                return;
            }

            $perm = $this->buildDiaryPermissionWhere();
            $creatorId = (int)$rec['created_by'];
            $canEdit = $this->isAdmin || $perm['has_full'] || in_array($creatorId, $perm['managed_ids'], true) || ($creatorId === (int)($this->currentUser['id'] ?? 0));
            if (!$canEdit) {
                Response::error('您没有权限编辑该工作日记', [], 403);
                return;
            }

            $work_date = trim((string)($input['work_date'] ?? ''));
            $task_order = trim((string)($input['task_order'] ?? ''));
            $product_code = trim((string)($input['product_code'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $task_type = trim((string)($input['task_type'] ?? ''));
            $work_content = trim((string)($input['work_content'] ?? ''));
            $quantity = (int)($input['quantity'] ?? 0);
            $duration = (int)($input['duration'] ?? 0);
            $is_ghost_write = trim((string)($input['is_ghost_write'] ?? '否'));
            $remarks = trim((string)($input['remarks'] ?? ''));

            if (empty($work_date) || empty($work_content) || $duration <= 0) {
                Response::error('工作日期、工作内容和工作时长必须填写');
                return;
            }

            if ($work_content === '返工' && (empty($task_order) || $quantity <= 0)) {
                Response::error('选择"返工"时工单号和数量必须填写');
                return;
            }

            if ($work_content === '其他' && empty($remarks)) {
                Response::error('选择"其他"工作内容时，备注说明必须填写');
                return;
            }

            if (!empty($task_order) && $quantity > 0) {
                $quota = $this->getQuantityQuota($task_order, $work_content, $id);
                if ($quota['has_limit'] && $quantity > $quota['remaining']) {
                    Response::error("工单【{$task_order}】在【{$work_content}】工序总数量为 {$quota['total_quantity']} 个，其他记录已填写 {$quota['used_quantity']} 个，最多还可填写 {$quota['remaining']} 个！");
                    return;
                }
            }

            $sql = "UPDATE work_diaries SET 
                work_date = ?, task_order = ?, product_code = ?, description = ?, 
                task_type = ?, work_content = ?, quantity = ?, duration = ?, 
                is_ghost_write = ?, remarks = ?, updated_at = NOW() 
                WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $work_date, $task_order, $product_code, $description,
                $task_type, $work_content, $quantity, $duration,
                $is_ghost_write, $remarks, $id
            ]);

            AuditService::log('工作日记', '修改工作日记');
            Response::success('工作日记更新成功');
        } catch (Throwable $e) {
            Response::exception($e, '更新失败');
        }
    }

    public function delete(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可以删除工作日记', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $ids = $input['ids'] ?? [];

            if (empty($ids) || !is_array($ids)) {
                Response::error('请选择要删除的记录');
                return;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("DELETE FROM work_diaries WHERE id IN ($placeholders)");
            $stmt->execute(array_map('intval', $ids));
            $count = $stmt->rowCount();

            AuditService::log('工作日记', '删除工作日记');
            Response::success("成功删除 {$count} 条工作日记", ['count' => $count]);
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }

    public function getUsers(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可访问权限设置', [], 403);
            return;
        }
        try {
            $stmt = $this->db->query("SELECT id, username, full_name, is_admin, COALESCE(can_view_all, 0) as can_view_all FROM users ORDER BY id ASC");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            Response::success('success', ['users' => $users]);
        } catch (Throwable $e) {
            Response::exception($e, '获取用户列表失败');
        }
    }

    public function updatePermission(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可修改权限', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $targetId = (int)($input['user_id'] ?? 0);
            $canViewAll = !empty($input['can_view_all']) ? 1 : 0;

            if ($targetId <= 0) {
                Response::error('无效用户');
                return;
            }

            $stmt = $this->db->prepare("UPDATE users SET can_view_all = ? WHERE id = ?");
            $stmt->execute([$canViewAll, $targetId]);

            AuditService::log('工作日记', '设置日记查看权限');
            Response::success('权限设置成功');
        } catch (Throwable $e) {
            Response::exception($e, '修改失败');
        }
    }

    public function export(): void
    {
        try {
            $type = trim((string)($_GET['export_type'] ?? 'all'));
            $search = trim((string)($_GET['search_all'] ?? ''));
            $start = trim((string)($_GET['filter_start_date'] ?? ''));
            $end = trim((string)($_GET['filter_end_date'] ?? ''));
            $content = trim((string)($_GET['filter_work_content'] ?? ''));
            $operator = trim((string)($_GET['filter_operator'] ?? ''));
            $selectedIdsRaw = trim((string)($_GET['selected_ids'] ?? ''));

            $perm = $this->buildDiaryPermissionWhere();
            $where = [$perm['sql']];
            $params = $perm['params'];

            if ($type === 'selected' && !empty($selectedIdsRaw)) {
                $ids = array_filter(array_map('intval', explode(',', $selectedIdsRaw)));
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $where[] = "d.id IN ($placeholders)";
                    $params = array_merge($params, $ids);
                }
            } elseif ($type === 'filtered') {
                if ($search !== '') {
                    $kw = "%{$search}%";
                    $where[] = "(d.task_order LIKE ? OR d.product_code LIKE ? OR d.description LIKE ? OR d.remarks LIKE ? OR d.work_content LIKE ? OR d.operator LIKE ? OR d.task_type LIKE ?)";
                    for ($i = 0; $i < 7; $i++) {
                        $params[] = $kw;
                    }
                }
                if ($start !== '') { $where[] = "d.work_date >= ?"; $params[] = $start; }
                if ($end !== '') { $where[] = "d.work_date <= ?"; $params[] = $end; }
                if ($content !== '') { $where[] = "d.work_content = ?"; $params[] = $content; }
                if ($operator !== '') { $where[] = "d.operator LIKE ?"; $params[] = "%{$operator}%"; }
            }

            $whereSql = implode(' AND ', $where);
            $stmt = $this->db->prepare("SELECT d.* FROM work_diaries d WHERE {$whereSql} ORDER BY d.work_date DESC, d.created_at DESC");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $headers = ['日期', '工单号', '产品代码', '描述', '工单类型', '工作内容', '数量', '工作时长(分钟)', '作业者', '是否代写', '备注说明', '创建时间'];

            // 优先导出 Excel（服务器已安装 PhpSpreadsheet）
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('工作日记');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($rows as $r) {
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['work_date'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)($r['task_order'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $line, (string)($r['product_code'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $line, (string)($r['description'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('E' . $line, (string)($r['task_type'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('F' . $line, (string)$r['work_content'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('G' . $line, (string)($r['quantity'] ?: 0), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('H' . $line, (string)($r['duration'] ?: 0), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('I' . $line, (string)$r['operator'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('J' . $line, (string)($r['is_ghost_write'] ?: '否'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('K' . $line, (string)($r['remarks'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('L' . $line, (string)$r['created_at'], DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:L1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:L1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'L') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = '工作日记导出_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="工作日记导出_' . date('Ymd_His') . '.csv"');

            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, array_map('excelSafe', $headers));

            foreach ($rows as $r) {
                fputcsv($output, array_map('excelSafe', [
                    $r['work_date'],
                    $r['task_order'] ?: '-',
                    $r['product_code'] ?: '-',
                    $r['description'] ?: '-',
                    $r['task_type'] ?: '-',
                    $r['work_content'],
                    $r['quantity'] ?: 0,
                    $r['duration'] ?: 0,
                    $r['operator'],
                    $r['is_ghost_write'] ?: '否',
                    $r['remarks'] ?: '-',
                    $r['created_at']
                ]));
            }

            fclose($output);
            exit;
        } catch (Throwable $e) {
            Response::exception($e, '导出失败');
        }
    }
}