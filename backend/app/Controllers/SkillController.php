<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use PDO;
use PDOException;
use Throwable;

class SkillController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private bool $isForeman;
    private array $foremanTeams;
    private int $myWorkerId;
    private array $processOrder;

    public function __construct()
    {
        $this->db = Database::getConnection();

        // ★ 安全修复：会话缺失时不再自动以数据库中的管理员身份登录，必须重新登录
        if (empty($_SESSION['user'])) {
            Response::error('登录已失效，请重新登录', ['need_login' => true], 401);
            exit;
        }

        $this->currentUser = $_SESSION['user'];
        $uName = (string)($this->currentUser['username'] ?? '');
        // ★ 修正：仅超级管理员视为管理员。
        //   不再把 can_view_all(工作日记的"查看全部"标记)当作技能矩阵的管理员，
        //   否则普通作业员一旦被勾了该标记就会看到并管理所有人的技能。
        $this->isAdmin = (!empty($this->currentUser['is_admin']) or $uName === 'admin');

        $this->processOrder = [
            '板卡接线', '线缆加工', '装置装配', '组件装配', 'QC检查',
            '板卡成品入库调试', '板卡程序烧录', '板卡调试', '保护功能',
            '参数设置', '大电流测试', '功能测试', '绝缘耐压测试',
            '拷机前调试', '校准', '载波调试', '装置调试',
            'ATS测试', '阻抗测试', '包装'
        ];

        \App\Core\Schema::ensureOnce('skill', function () { $this->initTables(); });
        $this->resolveForemanState();
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `skill_workers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) DEFAULT NULL COMMENT '绑定的系统账号ID',
                `worker_no` varchar(50) NOT NULL COMMENT '工号/学号',
                `worker_name` varchar(50) NOT NULL COMMENT '姓名',
                `team` varchar(50) NOT NULL DEFAULT '装置装配' COMMENT '所属班组',
                `identity` enum('正式员工','实习学生','外协人员') NOT NULL DEFAULT '正式员工',
                `work_status` enum('在岗','请假','离职') NOT NULL DEFAULT '在岗',
                `foreman_id` int(11) DEFAULT NULL COMMENT '直属管辖班长',
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_worker_no` (`worker_no`),
                INDEX `idx_user_id` (`user_id`),
                INDEX `idx_foreman` (`foreman_id`),
                INDEX `idx_team_status` (`team`, `work_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $cols = $this->db->query("SHOW COLUMNS FROM skill_workers")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('foreman_id', $cols, true)) {
                $this->db->exec("ALTER TABLE `skill_workers` ADD `foreman_id` int(11) DEFAULT NULL COMMENT '直属管辖班长' AFTER `work_status`");
            }

            $this->db->exec("CREATE TABLE IF NOT EXISTS `skills` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `worker_id` int(11) NOT NULL COMMENT '关联 skill_workers.id',
                `product_code` varchar(100) NOT NULL COMMENT '产品代码',
                `process_code` varchar(100) NOT NULL COMMENT '工序名称',
                `skill_level` tinyint(2) NOT NULL DEFAULT 1 COMMENT '技能等级1~5',
                `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `reviewer_id` int(11) DEFAULT NULL COMMENT '审核人ID',
                `review_time` datetime DEFAULT NULL,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_worker_skill` (`worker_id`, `product_code`, `process_code`),
                INDEX `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `skill_levels` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `level_code` varchar(10) NOT NULL UNIQUE,
                `level_name` varchar(50) NOT NULL,
                `level_desc` text,
                `coefficient` decimal(5,2) NOT NULL DEFAULT 1.00,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $lvlCnt = (int)$this->db->query("SELECT COUNT(*) FROM `skill_levels`")->fetchColumn();
            if ($lvlCnt === 0) {
                $this->db->exec("INSERT INTO `skill_levels` (`level_code`, `level_name`, `level_desc`, `coefficient`) VALUES
                    ('L1', '初学者', '了解基本操作流程，在指导下可完成简单工作', 0.50),
                    ('L2', '熟练工', '能够独立按照作业指导书规范完成常规工序', 0.75),
                    ('L3', '熟练骨干', '熟练掌握全部细节，保证质量与节拍，能处理常见异常', 1.00),
                    ('L4', '工艺能手', '高效高质量完成任务，能解决复杂工艺问题并指导他人', 1.25),
                    ('L5', '专家技师', '精通全流程与底层原理，具备工艺改进、培训及新工序开发能力', 1.50);");
            }

            $this->db->exec("INSERT IGNORE INTO skill_workers (user_id, worker_no, worker_name, team, identity, work_status)
                       SELECT id, username, COALESCE(NULLIF(full_name,''), username), team, '正式员工', '在岗'
                       FROM users 
                       WHERE is_active = 1");
        } catch (Throwable $e) {}
    }

    private function resolveForemanState(): void
    {
        $role = (string)($this->currentUser['role'] ?? '');
        $team = (string)($this->currentUser['team'] ?? '');
        $teams = [];

        if (mb_strpos($role, '班长') !== false or mb_strpos($role, '组长') !== false or mb_strpos($role, '主管') !== false) {
            if ($team !== '' and $team !== '全部') {
                $teams[] = $team;
            }
        }

        $idStr = strtolower(($this->currentUser['username'] ?? '') . ' ' . ($this->currentUser['full_name'] ?? ''));
        if (str_contains($idStr, '孙远超')) $teams = array_unique(array_merge($teams, ['装置装配', '装置包装']));
        if (str_contains($idStr, '刘少俊') or str_contains($idStr, '鲍敏') or str_contains($idStr, '徐祥志')) {
            $teams = array_unique(array_merge($teams, ['装置调试']));
        }
        if (str_contains($idStr, '楚永杰')) $teams = array_unique(array_merge($teams, ['装置包装']));

        $this->foremanTeams = array_values($teams);
        $this->isForeman = (!empty($this->foremanTeams));

        $st = $this->db->prepare("SELECT id FROM skill_workers WHERE user_id = ? LIMIT 1");
        $st->execute([(int)$this->currentUser['id']]);
        $this->myWorkerId = (int)($st->fetchColumn() ?: 0);
    }

    private function buildForemanFilter(): array
    {
        if ($this->isAdmin) return ['sql' => '1=1', 'params' => []];
        if (empty($this->foremanTeams)) {
            return ['sql' => 'w.user_id = ?', 'params' => [(int)$this->currentUser['id']]];
        }
        $ph = implode(',', array_fill(0, count($this->foremanTeams), '?'));
        return [
            'sql' => "(w.team IN ($ph) OR w.foreman_id = ? OR w.user_id = ?)",
            'params' => array_merge($this->foremanTeams, [(int)$this->currentUser['id'], (int)$this->currentUser['id']])
        ];
    }

    private function getMesConnection(): ?PDO
    {
        try {
            // ★ 凭据一律取自 .env（无源码兜底），配置缺失直接拒绝连接
            $cfg = \App\Core\ExternalDb::mesConfig();
            if ($cfg === null) return null;
            [$mes_host, $mes_port, $mes_db, $mes_user, $mes_pass] = $cfg;

            return new PDO("mysql:host={$mes_host};port={$mes_port};dbname={$mes_db};charset=utf8mb4", $mes_user, $mes_pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5
            ]);
        } catch (PDOException $e) {
            return null;
        }
    }

    public function init(): void
    {
        try {
            $st = $this->db->prepare("SELECT * FROM skill_workers WHERE user_id = ? LIMIT 1");
            $st->execute([(int)$this->currentUser['id']]);
            $myWorker = $st->fetch();

            $levels = $this->db->query("SELECT * FROM skill_levels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $foremanUsers = $this->db->query("SELECT id, username, full_name, role, team FROM users WHERE is_admin = 1 OR role LIKE '%班长%' OR role LIKE '%组长%' OR role LIKE '%主管%' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'processes'     => $this->processOrder,
                'levels'        => $levels,
                'is_admin'      => $this->isAdmin,
                'is_foreman'    => $this->isForeman,
                'foreman_teams' => $this->foremanTeams,
                'foreman_users' => $foremanUsers,
                'my_worker'     => $myWorker ?: null,
                'my_worker_id'  => $this->myWorkerId
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '初始化失败');
        }
    }

    public function matrixOverview(): void
    {
        try {
            $team = trim((string)($_GET['team'] ?? ''));
            $search = trim((string)($_GET['search'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 60;
            $offset = ($page - 1) * $perPage;

            $filter = $this->buildForemanFilter();
            $where = ["w.work_status = '在岗'", "s.status IN ('pending', 'approved')", $filter['sql']];
            $params = $filter['params'];

            if ($team !== '') {
                $where[] = "w.team = ?";
                $params[] = $team;
            }
            if ($search !== '') {
                $where[] = "(w.worker_name LIKE ? OR s.product_code LIKE ?)";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            $whereSql = implode(' AND ', $where);

            $cntSql = "SELECT COUNT(DISTINCT w.id, s.product_code) 
                       FROM skill_workers w
                       JOIN skills s ON w.id = s.worker_id
                       WHERE {$whereSql}";
            $cntStmt = $this->db->prepare($cntSql);
            $cntStmt->execute($params);
            $total = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($total / $perPage);

            $grpSql = "SELECT w.id as worker_id, w.worker_name, w.team, w.identity, s.product_code, 
                              MAX(s.updated_at) as max_updated_at,
                              SUM(CASE WHEN s.status = 'pending' THEN 1 ELSE 0 END) as pending_count
                       FROM skill_workers w
                       JOIN skills s ON w.id = s.worker_id
                       WHERE {$whereSql}
                       GROUP BY w.id, w.worker_name, w.team, w.identity, s.product_code
                       ORDER BY max_updated_at DESC, w.team ASC, w.worker_name ASC
                       LIMIT {$offset}, {$perPage}";
            $grpStmt = $this->db->prepare($grpSql);
            $grpStmt->execute($params);
            $groupRows = $grpStmt->fetchAll(PDO::FETCH_ASSOC);

            $matrixRows = [];
            if (!empty($groupRows)) {
                $conds = [];
                $inParams = [];
                $allProdCodes = [];
                foreach ($groupRows as $g) {
                    $conds[] = "(s.worker_id = ? AND s.product_code = ?)";
                    $inParams[] = $g['worker_id'];
                    $inParams[] = $g['product_code'];
                    $allProdCodes[] = trim((string)$g['product_code']);
                }

                $skillSql = "SELECT s.worker_id, s.product_code, s.process_code, s.skill_level, s.status 
                             FROM skills s 
                             WHERE s.status IN ('pending', 'approved') AND (" . implode(' OR ', $conds) . ")";
                $sStmt = $this->db->prepare($skillSql);
                $sStmt->execute($inParams);
                $skillsData = $sStmt->fetchAll(PDO::FETCH_ASSOC);

                $skillMap = [];
                $skillStatusMap = [];
                foreach ($skillsData as $sd) {
                    $k = $sd['worker_id'] . '_' . $sd['product_code'];
                    $skillMap[$k][$sd['process_code']] = (int)$sd['skill_level'];
                    $skillStatusMap[$k][$sd['process_code']] = $sd['status'];
                }

                $lookupKeys = [];
                foreach ($allProdCodes as $c) {
                    $lookupKeys[] = $c;
                    $lookupKeys[] = ltrim($c, '0');
                    $lookupKeys[] = str_pad($c, 10, '0', STR_PAD_LEFT);
                }
                $lookupKeys = array_values(array_unique(array_filter($lookupKeys)));

                $descMap = [];
                if (!empty($lookupKeys)) {
                    $ph = implode(',', array_fill(0, count($lookupKeys), '?'));
                    try {
                        $mStmt = $this->db->prepare("SELECT `code`, `description` FROM `manhour_summary` WHERE `code` IN ($ph) AND `description` IS NOT NULL AND `description` != ''");
                        $mStmt->execute($lookupKeys);
                        while ($mr = $mStmt->fetch(PDO::FETCH_ASSOC)) {
                            $k = trim((string)$mr['code']);
                            $v = trim((string)$mr['description']);
                            $descMap[$k] = $v;
                            $descMap[ltrim($k, '0')] = $v;
                            $descMap[str_pad($k, 10, '0', STR_PAD_LEFT)] = $v;
                        }
                    } catch (Throwable $e) {}
                }

                foreach ($groupRows as $g) {
                    $k = $g['worker_id'] . '_' . $g['product_code'];
                    $pCode = trim((string)$g['product_code']);
                    $finalDesc = $descMap[$pCode] ?? ($descMap[ltrim($pCode, '0')] ?? ($descMap[str_pad($pCode, 10, '0', STR_PAD_LEFT)] ?? '-'));
                    $canEditRow = ($this->isAdmin or ($this->myWorkerId > 0 and (int)$g['worker_id'] === $this->myWorkerId));

                    $matrixRows[] = [
                        'worker_id'      => $g['worker_id'],
                        'worker_name'    => $g['worker_name'],
                        'team'           => $g['team'],
                        'identity'       => $g['identity'],
                        'product_code'   => $pCode,
                        'description'    => $finalDesc,
                        'can_edit'       => $canEditRow,
                        'has_pending'    => ((int)$g['pending_count'] > 0),
                        'updated_at'     => $g['max_updated_at'],
                        'skills'         => $skillMap[$k] ?? [],
                        'skills_status'  => $skillStatusMap[$k] ?? []
                    ];
                }
            }

            Response::success('success', [
                'rows'         => $matrixRows,
                'total'        => $total,
                'total_pages'  => $totalPages,
                'page'         => $page,
                'my_worker_id' => $this->myWorkerId
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取矩阵数据失败');
        }
    }

    public function quickUpdateCell(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $workerId    = (int)($input['worker_id'] ?? 0);
            $productCode = trim((string)($input['product_code'] ?? ''));
            $processCode = trim((string)($input['process_code'] ?? ''));
            $level       = (int)($input['level'] ?? 0);

            if ($workerId <= 0 or empty($productCode) or empty($processCode)) {
                Response::error('参数缺失');
                return;
            }

            if (!$this->isAdmin and $workerId !== $this->myWorkerId) {
                Response::error('权限受限：仅允许自评修改本人的技能等级！', [], 403);
                return;
            }

            if ($level <= 0) {
                $del = $this->db->prepare("DELETE FROM skills WHERE worker_id = ? AND product_code = ? AND process_code = ?");
                $del->execute([$workerId, $productCode, $processCode]);
                AuditService::log('技能矩阵', '清除技能评定');
                Response::success('技能评定已清除');
                return;
            }

            $status = ($this->isAdmin or $this->isForeman) ? 'approved' : 'pending';
            $reviewerId = ($this->isAdmin or $this->isForeman) ? (int)$this->currentUser['id'] : null;

            $stmt = $this->db->prepare("INSERT INTO skills (worker_id, product_code, process_code, skill_level, status, reviewer_id, review_time, updated_at) 
                                  VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW()) 
                                  ON DUPLICATE KEY UPDATE skill_level = VALUES(skill_level), status = VALUES(status), reviewer_id = VALUES(reviewer_id), review_time = NOW()");
            $stmt->execute([$workerId, $productCode, $processCode, $level, $status, $reviewerId]);

            AuditService::log('技能矩阵', '快速评定技能');
            Response::success('评定已自动保存生效！');
        } catch (Throwable $e) {
            Response::exception($e, '更新失败');
        }
    }

    public function getProductProcesses(): void
    {
        $materNo = trim((string)($_REQUEST['mater_no'] ?? ''));
        if (empty($materNo)) {
            Response::error('请输入物料代码');
            return;
        }

        $description = '';
        $productProcesses = [];

        try {
            $mStmt = $this->db->prepare("SELECT description FROM manhour_summary WHERE code = ? OR code = ? LIMIT 1");
            $mStmt->execute([$materNo, ltrim($materNo, '0')]);
            $description = $mStmt->fetchColumn() ?: '';
        } catch (Throwable $e) {}

        $mesPdo = $this->getMesConnection();
        if ($mesPdo) {
            try {
                $sqlProc = "SELECT DISTINCT gx.name AS gx_name, j.seq
                            FROM tab_job j
                            JOIN tab_base_gx gx ON j.gx_id = gx.id
                            WHERE (j.mater_no = ? OR j.mater_no = ?)
                              AND gx.name != '标识打印'
                            ORDER BY j.seq ASC";
                $stmtProc = $mesPdo->prepare($sqlProc);
                $stmtProc->execute([$materNo, ltrim($materNo, '0')]);
                $rows = $stmtProc->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($rows)) {
                    $productProcesses = array_values(array_unique(array_filter($rows)));
                }
            } catch (Throwable $e) {}
        }

        if (empty($productProcesses)) {
            try {
                $hStmt = $this->db->prepare("SELECT DISTINCT process_code FROM skills WHERE product_code = ?");
                $hStmt->execute([$materNo]);
                $hRows = $hStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($hRows)) {
                    $productProcesses = array_values(array_unique(array_filter($hRows)));
                }
            } catch (Throwable $e) {}
        }

        if (empty($productProcesses)) {
            $productProcesses = ['装置装配', '装置调试', '装置检验', '包装'];
        }

        Response::success('success', [
            'product_code' => $materNo,
            'description'  => $description ?: '产品物料描述已自动检索',
            'processes'    => $productProcesses
        ]);
    }

    public function getLevels(): void
    {
        try {
            $levels = $this->db->query("SELECT * FROM skill_levels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            Response::success('success', [
                'levels'   => $levels,
                'is_admin' => $this->isAdmin
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取等级配置失败');
        }
    }

    public function saveLevels(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可保存等级标准', [], 403);
            return;
        }

        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $configs = $input['configs'] ?? [];

            $stmt = $this->db->prepare("UPDATE skill_levels SET level_name = ?, level_desc = ?, coefficient = ? WHERE level_code = ?");
            foreach ($configs as $c) {
                $code = trim((string)($c['level_code'] ?? ''));
                $name = trim((string)($c['level_name'] ?? ''));
                $desc = trim((string)($c['level_desc'] ?? ''));
                $coeff = round((float)($c['coefficient'] ?? 1.0), 2);
                if ($code !== '' and $name !== '') {
                    $stmt->execute([$name, $desc, $coeff, $code]);
                }
            }
            AuditService::log('技能矩阵', '保存技能等级标准');
            Response::success('技能等级标准与系数已更新！');
        } catch (Throwable $e) {
            Response::exception($e, '保存失败');
        }
    }

    public function listWorkers(): void
    {
        try {
            $team = trim((string)($_GET['team'] ?? ''));
            $status = trim((string)($_GET['status'] ?? ''));
            $search = trim((string)($_GET['search'] ?? ''));

            $filter = $this->buildForemanFilter();
            $where = [$filter['sql']];
            $params = $filter['params'];

            if ($team !== '') { $where[] = "w.team = ?"; $params[] = $team; }
            if ($status !== '') { $where[] = "w.work_status = ?"; $params[] = $status; }
            if ($search !== '') {
                $where[] = "(w.worker_name LIKE ? OR w.worker_no LIKE ?)";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            $whereSql = implode(' AND ', $where);
            $sql = "SELECT w.*, u.username as account_username, u.full_name as account_fullname, u.role as account_role,
                           fm.full_name as foreman_name, fm.username as foreman_username
                    FROM skill_workers w
                    LEFT JOIN users u ON w.user_id = u.id
                    LEFT JOIN users fm ON w.foreman_id = fm.id
                    WHERE {$whereSql}
                    ORDER BY w.work_status ASC, w.team ASC, w.worker_name ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', ['list' => $list]);
        } catch (Throwable $e) {
            Response::exception($e, '获取人员档案失败');
        }
    }

    public function saveWorker(): void
    {
        if (!$this->isAdmin and !$this->isForeman) {
            Response::error('无权操作人员档案', [], 403);
            return;
        }

        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $id = (int)($input['id'] ?? 0);
            $workerNo = trim((string)($input['worker_no'] ?? ''));
            $workerName = trim((string)($input['worker_name'] ?? ''));
            $team = trim((string)($input['team'] ?? '装置装配'));
            $identity = trim((string)($input['identity'] ?? '正式员工'));
            $status = trim((string)($input['work_status'] ?? '在岗'));
            $foremanId = !empty($input['foreman_id']) ? (int)$input['foreman_id'] : null;

            if ($workerNo === '' or $workerName === '') {
                Response::error('工号与姓名不能为空！');
                return;
            }

            if ($id > 0) {
                $stmt = $this->db->prepare("UPDATE skill_workers SET worker_no=?, worker_name=?, team=?, identity=?, work_status=?, foreman_id=? WHERE id=?");
                $stmt->execute([$workerNo, $workerName, $team, $identity, $status, $foremanId, $id]);
                AuditService::log('技能矩阵', '修改人员档案');
                Response::success('人员档案已更新！');
            } else {
                $stmt = $this->db->prepare("INSERT INTO skill_workers (worker_no, worker_name, team, identity, work_status, foreman_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$workerNo, $workerName, $team, $identity, $status, $foremanId]);
                AuditService::log('技能矩阵', '新增人员档案');
                Response::success('人员档案创建成功！', ['id' => (int)$this->db->lastInsertId()]);
            }
        } catch (Throwable $e) {
            Response::exception($e, '保存档案失败');
        }
    }

    public function submitSkills(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $workerId = (int)($input['worker_id'] ?? 0);
            $productCode = trim((string)($input['product_code'] ?? ''));
            $skillsData = $input['skills_data'] ?? [];

            if ($workerId <= 0 or empty($productCode) or empty($skillsData)) {
                Response::error('请选择人员、产品代码并至少评估一个工序等级');
                return;
            }

            if (!$this->isAdmin and !$this->isForeman and $workerId !== $this->myWorkerId) {
                Response::error('仅允许为本人申报技能评定！', [], 403);
                return;
            }

            $status = ($this->isAdmin or $this->isForeman) ? 'approved' : 'pending';
            $reviewerId = ($this->isAdmin or $this->isForeman) ? (int)$this->currentUser['id'] : null;

            $this->db->beginTransaction();
            $stmt = $this->db->prepare("INSERT INTO skills (worker_id, product_code, process_code, skill_level, status, reviewer_id, review_time, updated_at) 
                                  VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW()) 
                                  ON DUPLICATE KEY UPDATE skill_level = VALUES(skill_level), status = VALUES(status), reviewer_id = VALUES(reviewer_id), review_time = NOW()");

            foreach ($skillsData as $item) {
                $proc = trim((string)($item['process_code'] ?? ''));
                $lvl = (int)($item['level'] ?? 0);
                if ($lvl >= 1 and $lvl <= 5 and !empty($proc)) {
                    $stmt->execute([$workerId, $productCode, $proc, $lvl, $status, $reviewerId]);
                }
            }

            $this->db->commit();
            AuditService::log('技能矩阵', '提交技能登记');
            Response::success(($status === 'approved') ? '技能登记成功（已直接生效）' : '技能申报成功，已提交主管审核！');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::exception($e, '提交技能失败');
        }
    }

    public function leaderboard(): void
    {
        // ★ 排行榜为全员数据，仅管理员或班长可查看
        if (!$this->isAdmin and !$this->isForeman) {
            Response::error('无权查看技能排行榜', [], 403);
            return;
        }
        try {
            $scoreMap = [1 => 0.0, 2 => 0.5, 3 => 1.0, 4 => 1.5, 5 => 2.0];

            $sql = "SELECT w.id, w.worker_name, w.team, w.identity, s.product_code, s.process_code, s.skill_level
                    FROM skill_workers w
                    JOIN skills s ON w.id = s.worker_id
                    WHERE w.work_status = '在岗' AND s.status = 'approved'
                    ORDER BY w.id ASC";
            $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            $workers = [];
            foreach ($rows as $r) {
                $wid = (int)$r['id'];
                if (!isset($workers[$wid])) {
                    $workers[$wid] = [
                        'name'          => $r['worker_name'],
                        'team'          => $r['team'],
                        'identity'      => $r['identity'],
                        'total_score'   => 0.0,
                        'procs'         => [],
                        'products'      => [],
                        'prod_scores'   => []
                    ];
                }
                $lvl = (int)$r['skill_level'];
                $sc = $scoreMap[$lvl] ?? 0.0;
                $workers[$wid]['total_score'] += $sc;

                if ($lvl >= 2) {
                    $workers[$wid]['procs'][$r['process_code']] = true;
                }
                $pCode = $r['product_code'];
                $workers[$wid]['products'][$pCode] = true;
                $workers[$wid]['prod_scores'][$pCode] = ($workers[$wid]['prod_scores'][$pCode] ?? 0.0) + $sc;
            }

            $rankList = [];
            foreach ($workers as $wid => $w) {
                $bestProd = '-';
                $maxSc = -1;
                foreach ($w['prod_scores'] as $p => $s) {
                    if ($s > $maxSc) {
                        $maxSc = $s;
                        $bestProd = $p;
                    }
                }

                $rankList[] = [
                    'id'            => $wid,
                    'name'          => $w['name'],
                    'team'          => $w['team'],
                    'identity'      => $w['identity'],
                    'total_score'   => round($w['total_score'], 1),
                    'process_count' => count($w['procs']),
                    'product_count' => count($w['products']),
                    'best_product'  => $bestProd
                ];
            }

            usort($rankList, function ($a, $b) {
                if ($a['total_score'] === $b['total_score']) {
                    return $b['process_count'] <=> $a['process_count'];
                }
                return ($a['total_score'] < $b['total_score']) ? 1 : -1;
            });

            Response::success('success', ['rank_list' => $rankList]);
        } catch (Throwable $e) {
            Response::exception($e, '计算排行榜失败');
        }
    }

    public function listPending(): void
    {
        if (!$this->isAdmin and !$this->isForeman) {
            Response::error('无权访问审批中心', [], 403);
            return;
        }

        try {
            $filter = $this->buildForemanFilter();
            $sql = "SELECT s.id, s.worker_id, w.worker_name, w.team, w.work_status, s.product_code, s.process_code, s.skill_level, s.updated_at
                    FROM skills s
                    JOIN skill_workers w ON s.worker_id = w.id
                    WHERE s.status = 'pending' AND {$filter['sql']}
                    ORDER BY s.updated_at DESC";
            $st = $this->db->prepare($sql);
            $st->execute($filter['params']);
            $pending = $st->fetchAll(PDO::FETCH_ASSOC);

            $sqlApp = "SELECT s.id, s.worker_id, w.worker_name, w.team, s.product_code, s.process_code, s.skill_level, s.updated_at
                       FROM skills s
                       JOIN skill_workers w ON s.worker_id = w.id
                       WHERE s.status = 'approved' AND {$filter['sql']}
                       ORDER BY s.review_time DESC, s.updated_at DESC
                       LIMIT 10";
            $stApp = $this->db->prepare($sqlApp);
            $stApp->execute($filter['params']);
            $approved = $stApp->fetchAll(PDO::FETCH_ASSOC);

            $sqlCnt = "SELECT COUNT(*) FROM skills s JOIN skill_workers w ON s.worker_id = w.id WHERE s.status = 'approved' AND {$filter['sql']}";
            $stCnt = $this->db->prepare($sqlCnt);
            $stCnt->execute($filter['params']);
            $totApp = (int)$stCnt->fetchColumn();

            Response::success('success', [
                'pending_list'   => $pending,
                'approved_list'  => $approved,
                'total_approved' => $totApp
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取审批列表失败');
        }
    }

    public function reviewSkills(): void
    {
        if (!$this->isAdmin and !$this->isForeman) {
            Response::error('无权执行技能审核', [], 403);
            return;
        }

        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $ids = array_map('intval', $input['ids'] ?? []);
            $decision = trim((string)($input['decision'] ?? ''));

            if (empty($ids) or !in_array($decision, ['approve', 'reject'], true)) {
                Response::error('参数无效');
                return;
            }

            $newStatus = ($decision === 'approve') ? 'approved' : 'rejected';
            $ph = implode(',', array_fill(0, count($ids), '?'));

            $stmt = $this->db->prepare("UPDATE skills SET status = ?, reviewer_id = ?, review_time = NOW() WHERE id IN ($ph)");
            $stmt->execute(array_merge([$newStatus, (int)$this->currentUser['id']], $ids));

            AuditService::log('技能矩阵', '审核技能');
            Response::success($decision === 'approve' ? '集中批量通过完成！' : '集中批量驳回完成！');
        } catch (Throwable $e) {
            Response::exception($e, '审核处理异常');
        }
    }
}