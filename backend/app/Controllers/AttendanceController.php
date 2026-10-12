<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\ModulePerm;
use App\Core\Response;
use App\Services\AuditService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PDO;
use Throwable;

class AttendanceController
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
        \App\Core\Schema::ensureOnce('attendance', function () { $this->initTables(); });
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `att_employees` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(50) NOT NULL,
                `employee_no` varchar(50) NOT NULL UNIQUE,
                `team` varchar(50) NOT NULL DEFAULT '装置装配' COMMENT '所属班组',
                `foreman_id` int(11) DEFAULT NULL COMMENT '直属管辖班长(users.id)',
                `status` enum('onjob','offjob') NOT NULL DEFAULT 'onjob',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_status` (`status`),
                INDEX `idx_team` (`team`),
                INDEX `idx_foreman` (`foreman_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $cols = $this->db->query("SHOW COLUMNS FROM att_employees")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('team', $cols, true)) {
                $this->db->exec("ALTER TABLE `att_employees` ADD `team` varchar(50) NOT NULL DEFAULT '装置装配' AFTER `employee_no`");
            }
            if (!in_array('foreman_id', $cols, true)) {
                $this->db->exec("ALTER TABLE `att_employees` ADD `foreman_id` int(11) DEFAULT NULL AFTER `team`");
            }

            $this->db->exec("CREATE TABLE IF NOT EXISTS `att_cards` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `card_uid` varchar(32) NOT NULL UNIQUE,
                `current_employee_id` int(11) DEFAULT NULL,
                `status` enum('free','occupied') NOT NULL DEFAULT 'free',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_card_uid` (`card_uid`),
                INDEX `idx_card_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `att_offjob_records` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `card_id` int(11) DEFAULT NULL,
                `employee_id` int(11) NOT NULL,
                `start_time` datetime NOT NULL,
                `end_time` datetime DEFAULT NULL,
                `duration_seconds` int(11) DEFAULT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_record_card` (`card_id`),
                INDEX `idx_record_employee` (`employee_id`),
                INDEX `idx_record_start` (`start_time`),
                INDEX `idx_record_end` (`end_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 若 att_employees 记录数过少，自动从 users 表拉取同步初始化
            $empCount = (int)$this->db->query("SELECT COUNT(*) FROM att_employees")->fetchColumn();
            if ($empCount === 0) {
                $this->db->exec("INSERT INTO `att_employees` (`name`, `employee_no`, `team`, `status`)
                                 SELECT COALESCE(NULLIF(full_name, ''), username), username, COALESCE(NULLIF(team, ''), '装置装配'), 'onjob'
                                 FROM `users` WHERE `is_active` = 1
                                 ON DUPLICATE KEY UPDATE `team` = VALUES(`team`);");
            }
        } catch (Throwable $e) {}
    }

    private function getAttendanceManagedTeams(): array|string
    {
        if ($this->isAdmin) return '__ALL__';

        $uRole = (string)($this->currentUser['role'] ?? '');
        $uTeam = (string)($this->currentUser['team'] ?? '');
        $uName = (string)($this->currentUser['full_name'] ?? ($this->currentUser['username'] ?? ''));
        $combined = $uRole . ' ' . $uTeam . ' ' . $uName;

        $teams = [];
        if (str_contains($combined, '装配') || str_contains($combined, '孙远超') || str_contains($combined, 'sunyuanchao')) {
            $teams[] = '装置装配';
        }
        if (str_contains($combined, '调试') || str_contains($combined, '刘少俊') || str_contains($combined, 'liushaojun') ||
            str_contains($combined, '鲍敏') || str_contains($combined, '徐祥志')) {
            $teams[] = '装置调试';
        }
        if (str_contains($combined, '包装') || str_contains($combined, '楚永杰') || str_contains($combined, 'zhangyanrong')) {
            $teams[] = '装置包装';
        }

        if (!empty($uTeam) && $uTeam !== '全部' && !in_array($uTeam, $teams, true)) {
            $teams[] = $uTeam;
        }

        return !empty($teams) ? $teams : '__ALL__';
    }

    private function buildEmpScopeWhere(string $prefix = 'e.'): array
    {
        if ($this->isAdmin) return ['sql' => '1=1', 'params' => []];

        $managed = $this->getAttendanceManagedTeams();
        if ($managed === '__ALL__') return ['sql' => '1=1', 'params' => []];

        $whereParts = [];
        $params = [];

        $ph = implode(',', array_fill(0, count($managed), '?'));
        $whereParts[] = "{$prefix}team IN ($ph)";
        foreach ($managed as $m) {
            $params[] = $m;
        }

        $whereParts[] = "{$prefix}foreman_id = ?";
        $params[] = (int)($this->currentUser['id'] ?? 0);

        $sql = '(' . implode(' OR ', $whereParts) . ')';
        return ['sql' => $sql, 'params' => $params];
    }

    public function dashboard(): void
    {
        try {
            $scope = $this->buildEmpScopeWhere('e.');
            $whereSql = $scope['sql'];
            $params = $scope['params'];

            $sql = "SELECT r.id AS record_id, e.id AS employee_id, e.name, e.employee_no, e.team, c.card_uid, r.start_time, 
                           TIMESTAMPDIFF(MINUTE, r.start_time, NOW()) AS minutes 
                    FROM att_offjob_records r 
                    JOIN att_employees e ON r.employee_id = e.id 
                    LEFT JOIN att_cards c ON r.card_id = c.id
                    WHERE r.end_time IS NULL AND {$whereSql}
                    ORDER BY r.start_time ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $offjobRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalCount = count($offjobRows);
            $longCount = 0;
            $maxMinutes = 0;

            foreach ($offjobRows as &$r) {
                $m = (int)$r['minutes'];
                if ($m >= 30) $longCount++;
                if ($m > $maxMinutes) $maxMinutes = $m;

                if ($m >= 60) $r['level'] = 'danger';
                elseif ($m >= 30) $r['level'] = 'warning';
                else $r['level'] = 'normal';

                $r['start_time_fmt'] = date('H:i:s', strtotime((string)$r['start_time']));
            }
            unset($r);

            $freeCards = (int)$this->db->query("SELECT COUNT(*) FROM att_cards WHERE status = 'free'")->fetchColumn();

            $cntEmpStmt = $this->db->prepare("SELECT COUNT(*) FROM att_employees e WHERE e.status = 'onjob' AND {$whereSql}");
            $cntEmpStmt->execute($params);
            $onjobEmps = (int)$cntEmpStmt->fetchColumn();

            Response::success('success', [
                'list'        => $offjobRows,
                'total_count' => $totalCount,
                'long_count'  => $longCount,
                'max_minutes' => $maxMinutes,
                'free_cards'  => $freeCards,
                'onjob_emps'  => $onjobEmps,
                'is_admin'    => $this->isAdmin,
                'can_manage'  => ModulePerm::canEdit($this->currentUser, 'attendance'),
                'server_time' => date('Y-m-d H:i:s')
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取看板数据失败');
        }
    }

    public function bindCard(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $card_uid = trim((string)($input['card_uid'] ?? ''));
            $employee_id = (int)($input['employee_id'] ?? 0);

            if (empty($card_uid) || $employee_id <= 0) {
                Response::error('请选择员工并提供有效的卡片UID');
                return;
            }

            $this->db->beginTransaction();

            $cStmt = $this->db->prepare("SELECT id, status, current_employee_id FROM att_cards WHERE card_uid = ?");
            $cStmt->execute([$card_uid]);
            $card = $cStmt->fetch(PDO::FETCH_ASSOC);

            if (!$card) {
                $insCard = $this->db->prepare("INSERT INTO att_cards (card_uid, status) VALUES (?, 'free')");
                $insCard->execute([$card_uid]);
                $card = ['id' => (int)$this->db->lastInsertId(), 'status' => 'free'];
            }

            if ($card['status'] === 'occupied') {
                $this->db->rollBack();
                Response::error('该卡片处于占用状态，请先还卡后再发卡');
                return;
            }

            $eStmt = $this->db->prepare("SELECT id, name, team, foreman_id, status FROM att_employees WHERE id = ?");
            $eStmt->execute([$employee_id]);
            $emp = $eStmt->fetch(PDO::FETCH_ASSOC);

            if (!$emp) {
                $this->db->rollBack();
                Response::error('员工档案不存在');
                return;
            }

            if (!$this->isAdmin) {
                $managed = $this->getAttendanceManagedTeams();
                if ($managed !== '__ALL__') {
                    if (!in_array($emp['team'], $managed, true) && (int)$emp['foreman_id'] !== (int)($this->currentUser['id'] ?? 0)) {
                        $this->db->rollBack();
                        Response::error('您无权为非本管辖范围的员工办理离岗发卡');
                        return;
                    }
                }
            }

            if ($emp['status'] !== 'onjob') {
                $this->db->rollBack();
                Response::error("员工【{$emp['name']}】当前已处于离岗状态");
                return;
            }

            $this->db->prepare("UPDATE att_cards SET current_employee_id = ?, status = 'occupied' WHERE id = ?")
               ->execute([$employee_id, $card['id']]);

            $this->db->prepare("UPDATE att_employees SET status = 'offjob' WHERE id = ?")
               ->execute([$employee_id]);

            $this->db->prepare("INSERT INTO att_offjob_records (card_id, employee_id, start_time) VALUES (?, ?, NOW())")
               ->execute([$card['id'], $employee_id]);

            $this->db->commit();
            Response::success("员工【{$emp['name']}】离岗登记成功");
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::exception($e, '发卡登记失败');
        }
    }

    public function unbindCard(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $card_uid = trim((string)($input['card_uid'] ?? ''));
            $card_id = (int)($input['card_id'] ?? 0);

            if (empty($card_uid) && $card_id <= 0) {
                Response::error('请提供卡片 UID 或 ID');
                return;
            }

            $this->db->beginTransaction();

            if ($card_id > 0) {
                $cStmt = $this->db->prepare("SELECT c.id, c.status, c.current_employee_id, e.name AS emp_name, e.team, e.foreman_id FROM att_cards c LEFT JOIN att_employees e ON c.current_employee_id = e.id WHERE c.id = ?");
                $cStmt->execute([$card_id]);
            } else {
                $cStmt = $this->db->prepare("SELECT c.id, c.status, c.current_employee_id, e.name AS emp_name, e.team, e.foreman_id FROM att_cards c LEFT JOIN att_employees e ON c.current_employee_id = e.id WHERE c.card_uid = ?");
                $cStmt->execute([$card_uid]);
            }
            $card = $cStmt->fetch(PDO::FETCH_ASSOC);

            if (!$card || $card['status'] !== 'occupied') {
                $this->db->rollBack();
                Response::error('未找到被占用的离岗卡片');
                return;
            }

            $empId = $card['current_employee_id'];
            $empName = $card['emp_name'] ?: '员工';

            $this->db->prepare("UPDATE att_offjob_records SET end_time = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, start_time, NOW()) WHERE card_id = ? AND end_time IS NULL")
               ->execute([$card['id']]);

            $this->db->prepare("UPDATE att_cards SET current_employee_id = NULL, status = 'free' WHERE id = ?")
               ->execute([$card['id']]);

            if ($empId) {
                $this->db->prepare("UPDATE att_employees SET status = 'onjob' WHERE id = ?")
                   ->execute([$empId]);
            }

            $this->db->commit();
            Response::success("【{$empName}】返岗成功，卡片已释放为空闲");
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::exception($e, '还卡返岗失败');
        }
    }

    public function listEmployees(): void
    {
        try {
            $status = trim((string)($_GET['status'] ?? ''));
            $scope = $this->buildEmpScopeWhere('e.');
            $where = [$scope['sql']];
            $params = $scope['params'];

            if (!empty($status)) {
                $where[] = "e.status = ?";
                $params[] = $status;
            }
            $whereSql = implode(' AND ', $where);

            $stmt = $this->db->prepare("SELECT e.* FROM att_employees e WHERE {$whereSql} ORDER BY e.status DESC, e.team ASC, e.id ASC");
            $stmt->execute($params);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($list) && !$this->isAdmin) {
                $fallbackStmt = $this->db->prepare("SELECT e.* FROM att_employees e " . (!empty($status) ? "WHERE e.status = ?" : "") . " ORDER BY e.status DESC, e.id ASC LIMIT 50");
                $fallbackStmt->execute(!empty($status) ? [$status] : []);
                $list = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            Response::success('success', [
                'list'       => $list ?: [],
                'is_admin'   => $this->isAdmin,
                'can_manage' => ModulePerm::canEdit($this->currentUser, 'attendance')
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取员工列表失败');
        }
    }

    public function saveEmployee(): void
    {
        // ★ 权限加固：员工档案属"基础数据"，仅管理员或被授权（权限分配→允许编辑与数据管理）的用户可维护
        if (!ModulePerm::canEdit($this->currentUser, 'attendance')) {
            ModulePerm::deny('员工档案维护');
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $id = (int)($input['id'] ?? 0);
            $name = trim((string)($input['name'] ?? ''));
            $emp_no = trim((string)($input['employee_no'] ?? ''));
            $team = trim((string)($input['team'] ?? '装置装配'));

            if (empty($name) || empty($emp_no)) {
                Response::error('姓名和工号不能为空');
                return;
            }

            if ($id > 0) {
                $stmt = $this->db->prepare("UPDATE att_employees SET name = ?, employee_no = ?, team = ? WHERE id = ?");
                $stmt->execute([$name, $emp_no, $team, $id]);
                AuditService::log('人员离岗监控', "修改员工档案: [{$emp_no}] {$name}");
                Response::success('员工信息修改成功');
            } else {
                $foremanId = !$this->isAdmin ? (int)($this->currentUser['id'] ?? null) : null;
                $stmt = $this->db->prepare("INSERT INTO att_employees (name, employee_no, team, foreman_id, status) VALUES (?, ?, ?, ?, 'onjob')");
                $stmt->execute([$name, $emp_no, $team, $foremanId]);
                AuditService::log('人员离岗监控', "新增员工档案: [{$emp_no}] {$name}");
                Response::success('员工添加成功', ['id' => (int)$this->db->lastInsertId()]);
            }
        } catch (Throwable $e) {
            Response::exception($e, '保存失败');
        }
    }

    public function deleteEmployee(): void
    {
        try {
            // ★ 权限加固：管理员或被授权用户方可删除员工档案
            if (!$this->isAdmin && !ModulePerm::canEdit($this->currentUser, 'attendance')) {
                Response::error('只有管理员或被授权用户拥有删除员工档案权限', [], 403);
                return;
            }
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));

            $chk = $this->db->prepare("SELECT status FROM att_employees WHERE id = ?");
            $chk->execute([$id]);
            $st = $chk->fetchColumn();
            if ($st === 'offjob') {
                Response::error('该员工当前处于离岗中，禁止删除');
                return;
            }

            $this->db->prepare("DELETE FROM att_employees WHERE id = ?")->execute([$id]);
            AuditService::log('人员离岗监控', "删除员工档案 ID: [{$id}]");
            Response::success('员工删除成功');
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }

    public function listCards(): void
    {
        try {
            $sql = "SELECT c.*, e.name AS emp_name, e.employee_no 
                    FROM att_cards c 
                    LEFT JOIN att_employees e ON c.current_employee_id = e.id 
                    ORDER BY c.status DESC, c.id ASC";
            $cards = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            Response::success('success', ['list' => $cards, 'is_admin' => $this->isAdmin]);
        } catch (Throwable $e) {
            Response::exception($e, '获取卡片列表失败');
        }
    }

    public function saveCard(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $card_uid = trim((string)($input['card_uid'] ?? ''));

            if (empty($card_uid)) {
                Response::error('卡片UID不能为空');
                return;
            }

            $chk = $this->db->prepare("SELECT id FROM att_cards WHERE card_uid = ?");
            $chk->execute([$card_uid]);
            if ($chk->fetch()) {
                Response::error('该卡片UID已存在');
                return;
            }

            $this->db->prepare("INSERT INTO att_cards (card_uid, status) VALUES (?, 'free')")->execute([$card_uid]);
            Response::success('卡片添加成功');
        } catch (Throwable $e) {
            Response::exception($e, '保存卡片失败');
        }
    }

    public function deleteCard(): void
    {
        try {
            if (!$this->isAdmin) {
                Response::error('只有管理员拥有删除卡片权限', [], 403);
                return;
            }
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $id = (int)($input['id'] ?? 0);

            $chk = $this->db->prepare("SELECT status FROM att_cards WHERE id = ?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() === 'occupied') {
                Response::error('占用中的卡片不可直接删除，请先解绑');
                return;
            }

            $this->db->prepare("DELETE FROM att_cards WHERE id = ?")->execute([$id]);
            Response::success('卡片已删除');
        } catch (Throwable $e) {
            Response::exception($e, '删除失败');
        }
    }

    public function listRecords(): void
    {
        try {
            $name = trim((string)($_GET['name'] ?? ''));
            $emp_no = trim((string)($_GET['employee_no'] ?? ''));
            $startDate = trim((string)($_GET['start_date'] ?? ''));
            $endDate = trim((string)($_GET['end_date'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 30;
            $offset = ($page - 1) * $perPage;

            $scope = $this->buildEmpScopeWhere('e.');
            $where = [$scope['sql']];
            $params = $scope['params'];

            if ($name !== '') {
                $where[] = "e.name LIKE ?";
                $params[] = "%{$name}%";
            }
            if ($emp_no !== '') {
                $where[] = "e.employee_no LIKE ?";
                $params[] = "%{$emp_no}%";
            }
            if ($startDate !== '') {
                $where[] = "r.start_time >= ?";
                $params[] = $startDate . " 00:00:00";
            }
            if ($endDate !== '') {
                $where[] = "r.start_time <= ?";
                $params[] = $endDate . " 23:59:59";
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM att_offjob_records r JOIN att_employees e ON r.employee_id = e.id LEFT JOIN att_cards c ON r.card_id = c.id WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($totalRecords / $perPage);

            $sql = "SELECT r.*, e.name, e.employee_no, e.team, c.card_uid,
                           IFNULL(ROUND(r.duration_seconds / 60, 1), TIMESTAMPDIFF(MINUTE, r.start_time, NOW())) AS minutes
                    FROM att_offjob_records r 
                    JOIN att_employees e ON r.employee_id = e.id 
                    LEFT JOIN att_cards c ON r.card_id = c.id 
                    WHERE {$whereSql} 
                    ORDER BY r.start_time DESC 
                    LIMIT {$offset}, {$perPage}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'records'       => $records,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'current_page'  => $page,
                'is_admin'      => $this->isAdmin
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取历史记录失败');
        }
    }

    public function statistics(): void
    {
        try {
            $scope = $this->buildEmpScopeWhere('e.');
            $whereSql = $scope['sql'];
            $params = $scope['params'];

            $sql = "SELECT e.name, e.employee_no, e.team,
                           COUNT(r.id) AS times, 
                           ROUND(IFNULL(SUM(r.duration_seconds), 0) / 60, 1) AS total_minutes,
                           ROUND(IFNULL(AVG(r.duration_seconds), 0) / 60, 1) AS avg_minutes
                    FROM att_employees e
                    LEFT JOIN att_offjob_records r ON e.id = r.employee_id AND r.end_time IS NOT NULL
                    WHERE {$whereSql}
                    GROUP BY e.id
                    ORDER BY total_minutes DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            Response::success('success', ['stats' => $stats, 'is_admin' => $this->isAdmin]);
        } catch (Throwable $e) {
            Response::exception($e, '获取统计报表失败');
        }
    }

    public function export(): void
    {
        try {
            $name = trim((string)($_GET['name'] ?? ''));
            $emp_no = trim((string)($_GET['employee_no'] ?? ''));
            $startDate = trim((string)($_GET['start_date'] ?? ''));
            $endDate = trim((string)($_GET['end_date'] ?? ''));

            $scope = $this->buildEmpScopeWhere('e.');
            $where = [$scope['sql']];
            $params = $scope['params'];

            if ($name !== '') {
                $where[] = "e.name LIKE ?";
                $params[] = "%{$name}%";
            }
            if ($emp_no !== '') {
                $where[] = "e.employee_no LIKE ?";
                $params[] = "%{$emp_no}%";
            }
            if ($startDate !== '') {
                $where[] = "r.start_time >= ?";
                $params[] = $startDate . " 00:00:00";
            }
            if ($endDate !== '') {
                $where[] = "r.start_time <= ?";
                $params[] = $endDate . " 23:59:59";
            }

            $whereSql = implode(' AND ', $where);
            $sql = "SELECT e.name, e.employee_no, e.team, c.card_uid, r.start_time,
                           CASE WHEN r.end_time IS NULL THEN '离岗中' ELSE DATE_FORMAT(r.end_time, '%Y-%m-%d %H:%i:%s') END AS end_time_fmt,
                           IFNULL(ROUND(r.duration_seconds / 60, 1), TIMESTAMPDIFF(MINUTE, r.start_time, NOW())) AS minutes
                    FROM att_offjob_records r 
                    JOIN att_employees e ON r.employee_id = e.id
                    LEFT JOIN att_cards c ON r.card_id = c.id
                    WHERE {$whereSql} ORDER BY r.start_time DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $headers = ['员工姓名', '工号', '所属班组', 'IC卡UID', '离岗开始时间', '返岗结束时间', '离岗时长(分钟)'];

            // 优先导出 Excel（服务器已安装 PhpSpreadsheet）
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('离岗记录');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($rows as $r) {
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['name'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)$r['employee_no'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $line, (string)$r['team'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $line, (string)($r['card_uid'] ?: '已删除卡片'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('E' . $line, (string)$r['start_time'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('F' . $line, (string)$r['end_time_fmt'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('G' . $line, (string)$r['minutes'], DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:G1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'G') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = '离岗记录导出_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="离岗记录导出_' . date('Ymd_His') . '.csv"');

            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, array_map('excelSafe', $headers));

            foreach ($rows as $r) {
                fputcsv($output, array_map('excelSafe', [
                    $r['name'],
                    $r['employee_no'],
                    $r['team'],
                    $r['card_uid'] ?: '已删除卡片',
                    $r['start_time'],
                    $r['end_time_fmt'],
                    $r['minutes']
                ]));
            }

            fclose($output);
            exit;
        } catch (Throwable $e) {
            Response::exception($e, '导出失败');
        }
    }
}