<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use PDO;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class ImpedanceController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $userPerm;

    public function __construct()
    {
        $this->db = Database::getConnection('default');

        // ★ 安全修复：会话缺失时不再自动以数据库中的管理员身份登录，必须重新登录
        if (empty($_SESSION['user'])) {
            Response::error('登录已失效，请重新登录', ['need_login' => true], 401);
            exit;
        }

        $this->currentUser = $_SESSION['user'];
        $this->isAdmin = !empty($this->currentUser['is_admin']) || ($this->currentUser['username'] ?? '') === 'admin';
        \App\Core\Schema::ensureOnce('impedance', function () { $this->initTables(); });
        $this->userPerm = $this->resolveUserPerm();
    }

    /**
     * ★ 权限加固（L2）：读取当前用户的阻抗模块权限。
     *   未配置时：可查看 = 1、可录入 = 0（默认关闭录入，需管理员在权限设置中授权）。
     */
    private function resolveUserPerm(): array
    {
        if ($this->isAdmin) {
            return ['can_view_records' => 1, 'can_input' => 1];
        }
        try {
            $stmt = $this->db->prepare("SELECT can_view_records, can_input FROM `impedance_permissions` WHERE user_id = ? LIMIT 1");
            $stmt->execute([(int)($this->currentUser['id'] ?? 0)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'can_view_records' => (int)$row['can_view_records'],
                    'can_input'        => (int)$row['can_input'],
                ];
            }
        } catch (Throwable $e) {}
        return ['can_view_records' => 1, 'can_input' => 0];
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `impedance_records` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `sn` VARCHAR(22) NOT NULL,
                `work_order` VARCHAR(100) NOT NULL,
                `code` VARCHAR(100),
                `description` TEXT,
                `protect_impedance1` DECIMAL(15,4),
                `protect_impedance2` DECIMAL(15,4),
                `protect_impedance3` DECIMAL(15,4),
                `protect_impedance4` DECIMAL(15,4),
                `protect_impedance5` DECIMAL(15,4),
                `protect_impedance6` DECIMAL(15,4),
                `protect_impedance7` DECIMAL(15,4),
                `protect_impedance8` DECIMAL(15,4),
                `protect_impedance9` DECIMAL(15,4),
                `control_impedance1` DECIMAL(15,4),
                `control_impedance2` DECIMAL(15,4),
                `control_impedance3` DECIMAL(15,4),
                `protect_error` DECIMAL(10,4),
                `control_error` DECIMAL(10,4),
                `protect_conclusion` VARCHAR(20) DEFAULT '未填写',
                `control_conclusion` VARCHAR(20) DEFAULT '未填写',
                `operator` VARCHAR(100) NOT NULL,
                `error_standard` INT DEFAULT 10,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_sn` (`sn`),
                INDEX `idx_work_order` (`work_order`),
                INDEX `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // ★ 权限加固（L2）：阻抗模块细粒度权限表
            $this->db->exec("CREATE TABLE IF NOT EXISTS `impedance_permissions` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `user_id` INT(11) NOT NULL,
                `can_view_records` TINYINT(1) NOT NULL DEFAULT 1,
                `can_input` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Throwable $e) {}
    }

    private function getErpSqlSrvConnection(): ?PDO
    {
        try {
            // 从 .env 读取 SQL Server ERP 配置
            // ★ 凭据一律取自 .env（无源码兜底），配置缺失直接拒绝连接
            $cfg = \App\Core\ExternalDb::sqlsrvConfig();
            if ($cfg === null) return null;
            [$sqlsrv_host, $sqlsrv_port, $sqlsrv_db, $sqlsrv_user, $sqlsrv_pass] = $cfg;

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 8,
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
            // 从 .env 读取 MES 数据库配置
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

    private function calculateImpedanceErrors(array $data): array
    {
        $errorStandard = (isset($data['use_5_percent']) && (string)$data['use_5_percent'] === '1') ? 5 : (isset($data['error_standard']) ? (int)$data['error_standard'] : 10);
        if ($errorStandard !== 5) $errorStandard = 10;

        // 1. 保护阻抗 1-9
        $protectValues = [];
        for ($i = 1; $i <= 9; $i++) {
            $val = trim((string)($data['protect_impedance' . $i] ?? ''));
            if ($val !== '' && is_numeric($val) && (float)$val > 0) {
                $protectValues[] = round((float)$val, 4);
            }
        }

        $protectError = null;
        $protectConclusion = '未填写';
        $pCount = count($protectValues);

        if ($pCount >= 2) {
            $pMax = max($protectValues);
            $pMin = min($protectValues);
            $protectError = ($pMin > 0) ? round(($pMax - $pMin) / $pMin * 100, 4) : 100.0;
            $protectConclusion = ($protectError <= $errorStandard) ? '合格' : '不合格';
        } elseif ($pCount === 1) {
            $protectConclusion = '单值不计算';
        }

        // 2. 测控阻抗 1-3
        $controlValues = [];
        for ($i = 1; $i <= 3; $i++) {
            $val = trim((string)($data['control_impedance' . $i] ?? ''));
            if ($val !== '' && is_numeric($val) && (float)$val > 0) {
                $controlValues[] = round((float)$val, 4);
            }
        }

        $controlError = null;
        $controlConclusion = '未填写';
        $cCount = count($controlValues);

        if ($cCount >= 2) {
            $cMax = max($controlValues);
            $cMin = min($controlValues);
            $controlError = ($cMin > 0) ? round(($cMax - $cMin) / $cMin * 100, 4) : 100.0;
            $controlConclusion = ($controlError <= $errorStandard) ? '合格' : '不合格';
        } elseif ($cCount === 1) {
            $controlConclusion = '单值不计算';
        }

        return [
            'error_standard'     => $errorStandard,
            'protect_error'      => $protectError,
            'control_error'      => $controlError,
            'protect_conclusion' => $protectConclusion,
            'control_conclusion' => $controlConclusion,
            'protect_count'      => $pCount,
            'control_count'      => $cCount
        ];
    }

    public function getBatchInfo(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;
        $sn = trim((string)($input['sn'] ?? ''));

        if (empty($sn)) {
            Response::error('SN 不能为空');
            return;
        }

        $historyCount = 0;
        $lastRecord = null;
        try {
            $hStmt = $this->db->prepare("SELECT id, created_at, operator, protect_conclusion, control_conclusion FROM impedance_records WHERE sn = ? ORDER BY created_at DESC");
            $hStmt->execute([$sn]);
            $history = $hStmt->fetchAll(PDO::FETCH_ASSOC);
            $historyCount = count($history);
            if ($historyCount > 0) {
                $lastRecord = $history[0];
            }
        } catch (Throwable $e) {}

        $batchNumber = strlen($sn) >= 16 ? substr($sn, 0, 16) : $sn;
        $found = false;
        $workOrder = '';
        $code = '';
        $desc = '';

        // 1. 从 SQL Server ERP [J_全离散任务] 检索
        $sqlsrv = $this->getErpSqlSrvConnection();
        if ($sqlsrv) {
            try {
                $stmt = $sqlsrv->prepare("SELECT TOP 1 [任务] as work_order, [编码] as code, [描述] as description FROM [J_全离散任务] WHERE [批号] LIKE ? AND [状态] <> '已取消'");
                $stmt->execute(["%{$batchNumber}%"]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $workOrder = trim((string)$row['work_order']);
                    $code = trim((string)$row['code']);
                    $desc = trim(mb_convert_encoding((string)($row['description'] ?? ''), 'UTF-8', 'UTF-8,GBK,GB2312'));
                    $found = true;
                }
            } catch (Throwable $e) {}
        }

        // 2. 从 MES 数据库检索兜底
        if (!$found) {
            $mesPdo = $this->getMesConnection();
            if ($mesPdo) {
                try {
                    $stmtPlan = $mesPdo->prepare("SELECT task_no, mater_no, mater_desc FROM tab_plan WHERE batch_no LIKE ? OR task_no LIKE ? LIMIT 1");
                    $stmtPlan->execute(["%{$batchNumber}%", "%{$batchNumber}%"]);
                    $pRow = $stmtPlan->fetch(PDO::FETCH_ASSOC);
                    if ($pRow) {
                        $workOrder = trim((string)$pRow['task_no']);
                        $code = trim((string)$pRow['mater_no']);
                        $desc = trim((string)($pRow['mater_desc'] ?? ''));
                        $found = true;
                    }
                } catch (Throwable $e) {}
            }
        }

        // 3. 从本地 trace_tasks 检索兜底
        if (!$found) {
            try {
                $stmt = $this->db->prepare("SELECT task_order, parent_code, description FROM trace_tasks WHERE batch_prefix = ? OR batch_no LIKE ? LIMIT 1");
                $stmt->execute([$batchNumber, "%{$batchNumber}%"]);
                $tRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($tRow) {
                    $workOrder = trim((string)$tRow['task_order']);
                    $code = trim((string)$tRow['parent_code']);
                    $desc = trim((string)($tRow['description'] ?? ''));
                    $found = true;
                }
            } catch (Throwable $e) {}
        }

        Response::success('success', [
            'found'         => $found,
            'work_order'    => $workOrder,
            'code'          => $code,
            'description'   => $desc,
            'history_count' => $historyCount,
            'last_record'   => $lastRecord
        ]);
    }

    public function calculateErrors(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;

        $calc = $this->calculateImpedanceErrors($input);
        Response::success('success', $calc);
    }

    public function list(): void
    {
        try {
            $search = trim((string)($_GET['search_all'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 30;
            $offset = ($page - 1) * $perPage;

            $where = ["1=1"];
            $params = [];

            if ($search !== '') {
                $kw = "%{$search}%";
                $where[] = "(sn LIKE ? OR work_order LIKE ? OR code LIKE ? OR description LIKE ? OR operator LIKE ? OR protect_conclusion LIKE ? OR control_conclusion LIKE ?)";
                for ($i = 0; $i < 7; $i++) {
                    $params[] = $kw;
                }
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM impedance_records WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($totalRecords / $perPage);

            $sql = "SELECT * FROM impedance_records WHERE {$whereSql} ORDER BY created_at DESC, id DESC LIMIT {$offset}, {$perPage}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'records'       => $records,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'current_page'  => $page,
                'is_admin'      => $this->isAdmin,
                'user_perm'     => $this->userPerm
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取列表失败');
        }
    }

    public function add(): void
    {
        // ★ 权限加固（L2）：录入权限校验（默认未授权者不可录入）
        if (!$this->isAdmin && empty($this->userPerm['can_input'])) {
            Response::error('您没有录入阻抗记录的权限，请联系管理员开通', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $sn = trim((string)($input['sn'] ?? ''));
            $work_order = trim((string)($input['work_order'] ?? ''));
            $code = trim((string)($input['code'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $forceDuplicate = !empty($input['force_duplicate']);

            if (empty($sn) || empty($work_order)) {
                Response::error('SN 和工单号不能为空');
                return;
            }
            if (!preg_match('/^\d{22}$/', $sn)) {
                Response::error('SN 必须为 22 位纯数字');
                return;
            }

            // 若未携带强制确认标记，检测是否有重复历史并提示前端确认
            // ✅ 使用 Response::success 返回，避免前端 axios 拦截器将其作为异常抛出
            if (!$forceDuplicate) {
                $chk = $this->db->prepare("SELECT id, created_at, operator, protect_conclusion, control_conclusion FROM impedance_records WHERE sn = ? ORDER BY created_at DESC LIMIT 1");
                $chk->execute([$sn]);
                $exist = $chk->fetch(PDO::FETCH_ASSOC);
                if ($exist) {
                    Response::success('DUPLICATE_SN_WARN', [
                        'is_duplicate' => true,
                        'sn'           => $sn,
                        'last_time'    => $exist['created_at'],
                        'operator'     => $exist['operator'],
                        'message'      => "该 SN [{$sn}] 已在 {$exist['created_at']} 由 {$exist['operator']} 测试过。是否确定要进行【重复测试/复测录入】？"
                    ]);
                    return;
                }
            }

            $calc = $this->calculateImpedanceErrors($input);
            $operator = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : $this->currentUser['username'];

            $sql = "INSERT INTO impedance_records 
                (sn, work_order, code, description, 
                 protect_impedance1, protect_impedance2, protect_impedance3, protect_impedance4, 
                 protect_impedance5, protect_impedance6, protect_impedance7, protect_impedance8, protect_impedance9,
                 control_impedance1, control_impedance2, control_impedance3,
                 protect_error, control_error, protect_conclusion, control_conclusion, operator,
                 error_standard, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $sn, $work_order, $code, $description,
                ($input['protect_impedance1'] ?? '') !== '' ? $input['protect_impedance1'] : null,
                ($input['protect_impedance2'] ?? '') !== '' ? $input['protect_impedance2'] : null,
                ($input['protect_impedance3'] ?? '') !== '' ? $input['protect_impedance3'] : null,
                ($input['protect_impedance4'] ?? '') !== '' ? $input['protect_impedance4'] : null,
                ($input['protect_impedance5'] ?? '') !== '' ? $input['protect_impedance5'] : null,
                ($input['protect_impedance6'] ?? '') !== '' ? $input['protect_impedance6'] : null,
                ($input['protect_impedance7'] ?? '') !== '' ? $input['protect_impedance7'] : null,
                ($input['protect_impedance8'] ?? '') !== '' ? $input['protect_impedance8'] : null,
                ($input['protect_impedance9'] ?? '') !== '' ? $input['protect_impedance9'] : null,
                ($input['control_impedance1'] ?? '') !== '' ? $input['control_impedance1'] : null,
                ($input['control_impedance2'] ?? '') !== '' ? $input['control_impedance2'] : null,
                ($input['control_impedance3'] ?? '') !== '' ? $input['control_impedance3'] : null,
                $calc['protect_error'], $calc['control_error'],
                $calc['protect_conclusion'], $calc['control_conclusion'],
                $operator, $calc['error_standard']
            ]);

            AuditService::log('阻抗测试', '新增阻抗记录');
            Response::success('阻抗测试记录保存成功！', ['id' => (int)$this->db->lastInsertId()]);
        } catch (Throwable $e) {
            Response::exception($e, '添加失败');
        }
    }

    public function update(): void
    {
        try {
            if (!$this->isAdmin) {
                Response::error('只有管理员可编辑记录', [], 403);
                return;
            }

            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $id = (int)($input['record_id'] ?? ($input['id'] ?? 0));
            if ($id <= 0) {
                Response::error('无效的记录ID');
                return;
            }

            $sn = trim((string)($input['sn'] ?? ''));
            $work_order = trim((string)($input['work_order'] ?? ''));
            $code = trim((string)($input['code'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));

            if (empty($sn) || empty($work_order)) {
                Response::error('SN 和工单号不能为空');
                return;
            }
            if (!preg_match('/^\d{22}$/', $sn)) {
                Response::error('SN 必须为 22 位纯数字');
                return;
            }

            $oldStmt = $this->db->prepare("SELECT error_standard FROM impedance_records WHERE id = ?");
            $oldStmt->execute([$id]);
            $oldStd = $oldStmt->fetchColumn();
            $input['error_standard'] = $oldStd ? (int)$oldStd : 10;

            $calc = $this->calculateImpedanceErrors($input);
            $operator = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : $this->currentUser['username'];

            $sql = "UPDATE impedance_records SET 
                sn = ?, work_order = ?, code = ?, description = ?,
                protect_impedance1 = ?, protect_impedance2 = ?, protect_impedance3 = ?, protect_impedance4 = ?,
                protect_impedance5 = ?, protect_impedance6 = ?, protect_impedance7 = ?, protect_impedance8 = ?, protect_impedance9 = ?,
                control_impedance1 = ?, control_impedance2 = ?, control_impedance3 = ?,
                protect_error = ?, control_error = ?, protect_conclusion = ?, control_conclusion = ?,
                operator = ?, updated_at = NOW()
                WHERE id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $sn, $work_order, $code, $description,
                ($input['protect_impedance1'] ?? '') !== '' ? $input['protect_impedance1'] : null,
                ($input['protect_impedance2'] ?? '') !== '' ? $input['protect_impedance2'] : null,
                ($input['protect_impedance3'] ?? '') !== '' ? $input['protect_impedance3'] : null,
                ($input['protect_impedance4'] ?? '') !== '' ? $input['protect_impedance4'] : null,
                ($input['protect_impedance5'] ?? '') !== '' ? $input['protect_impedance5'] : null,
                ($input['protect_impedance6'] ?? '') !== '' ? $input['protect_impedance6'] : null,
                ($input['protect_impedance7'] ?? '') !== '' ? $input['protect_impedance7'] : null,
                ($input['protect_impedance8'] ?? '') !== '' ? $input['protect_impedance8'] : null,
                ($input['protect_impedance9'] ?? '') !== '' ? $input['protect_impedance9'] : null,
                ($input['control_impedance1'] ?? '') !== '' ? $input['control_impedance1'] : null,
                ($input['control_impedance2'] ?? '') !== '' ? $input['control_impedance2'] : null,
                ($input['control_impedance3'] ?? '') !== '' ? $input['control_impedance3'] : null,
                $calc['protect_error'], $calc['control_error'],
                $calc['protect_conclusion'], $calc['control_conclusion'],
                $operator, $id
            ]);

            AuditService::log('阻抗测试', '修改阻抗记录');
            Response::success('记录修改成功');
        } catch (Throwable $e) {
            Response::exception($e, '更新失败');
        }
    }

    public function delete(): void
    {
        try {
            if (!$this->isAdmin) {
                Response::error('只有管理员可删除测试记录', [], 403);
                return;
            }

            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $ids = $input['ids'] ?? [];
            if (empty($ids) || !is_array($ids)) {
                Response::error('请选择要删除的记录');
                return;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("DELETE FROM impedance_records WHERE id IN ($placeholders)");
            $stmt->execute(array_map('intval', $ids));
            $count = $stmt->rowCount();

            AuditService::log('阻抗测试', '删除阻抗记录');
            Response::success("成功删除 {$count} 条阻抗记录", ['count' => $count]);
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }

    public function export(): void
    {
        // ★ 权限加固（L2）：导出按查看权限控制
        if (!$this->isAdmin && empty($this->userPerm['can_view_records'])) {
            Response::error('您没有导出阻抗记录的权限', [], 403);
            return;
        }
        try {
            $search = trim((string)($_GET['search_all'] ?? ''));
            $where = ["1=1"];
            $params = [];

            if ($search !== '') {
                $kw = "%{$search}%";
                $where[] = "(sn LIKE ? OR work_order LIKE ? OR code LIKE ? OR description LIKE ? OR operator LIKE ? OR protect_conclusion LIKE ? OR control_conclusion LIKE ?)";
                for ($i = 0; $i < 7; $i++) {
                    $params[] = $kw;
                }
            }

            $whereSql = implode(' AND ', $where);
            $stmt = $this->db->prepare("SELECT * FROM impedance_records WHERE {$whereSql} ORDER BY created_at DESC, id DESC");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $headers = ['SN', '工单', '代码', '描述', '保护阻抗1', '保护阻抗2', '保护阻抗3', '保护阻抗4', '保护阻抗5', '保护阻抗6', '保护阻抗7', '保护阻抗8', '保护阻抗9', '测控阻抗1', '测控阻抗2', '测控阻抗3', '保护误差(%)', '测控误差(%)', '保护结论', '测控结论', '操作人', '误差标准', '创建时间'];

            // 优先导出 Excel（服务器已安装 PhpSpreadsheet）
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('阻抗测试记录');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($rows as $r) {
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['sn'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)$r['work_order'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $line, (string)$r['code'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $line, (string)$r['description'], DataType::TYPE_STRING);
                    for ($i = 1; $i <= 9; $i++) {
                        $key = 'protect_impedance' . $i;
                        $col = chr(ord('E') + $i - 1);
                        if ($r[$key] !== null && $r[$key] !== '') {
                            $sheet->setCellValue($col . $line, (float)$r[$key]);
                        } else {
                            $sheet->setCellValueExplicit($col . $line, '', DataType::TYPE_STRING);
                        }
                    }
                    for ($i = 1; $i <= 3; $i++) {
                        $key = 'control_impedance' . $i;
                        $col = chr(ord('N') + $i - 1);
                        if ($r[$key] !== null && $r[$key] !== '') {
                            $sheet->setCellValue($col . $line, (float)$r[$key]);
                        } else {
                            $sheet->setCellValueExplicit($col . $line, '', DataType::TYPE_STRING);
                        }
                    }
                    if ($r['protect_error'] !== null && $r['protect_error'] !== '') {
                        $sheet->setCellValue('Q' . $line, (float)$r['protect_error']);
                    } else {
                        $sheet->setCellValueExplicit('Q' . $line, '', DataType::TYPE_STRING);
                    }
                    if ($r['control_error'] !== null && $r['control_error'] !== '') {
                        $sheet->setCellValue('R' . $line, (float)$r['control_error']);
                    } else {
                        $sheet->setCellValueExplicit('R' . $line, '', DataType::TYPE_STRING);
                    }
                    $sheet->setCellValueExplicit('S' . $line, (string)$r['protect_conclusion'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('T' . $line, (string)$r['control_conclusion'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('U' . $line, (string)$r['operator'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('V' . $line, '≤' . ($r['error_standard'] ?: 10) . '%', DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('W' . $line, (string)$r['created_at'], DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:W1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:W1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'W') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = '阻抗测试记录_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="阻抗测试记录_' . date('Ymd_His') . '.csv"');

            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, array_map('excelSafe', $headers));

            foreach ($rows as $r) {
                fputcsv($output, array_map('excelSafe', [
                    "\t" . $r['sn'],
                    $r['work_order'],
                    $r['code'],
                    $r['description'],
                    $r['protect_impedance1'] !== null ? number_format((float)$r['protect_impedance1'], 4) : '',
                    $r['protect_impedance2'] !== null ? number_format((float)$r['protect_impedance2'], 4) : '',
                    $r['protect_impedance3'] !== null ? number_format((float)$r['protect_impedance3'], 4) : '',
                    $r['protect_impedance4'] !== null ? number_format((float)$r['protect_impedance4'], 4) : '',
                    $r['protect_impedance5'] !== null ? number_format((float)$r['protect_impedance5'], 4) : '',
                    $r['protect_impedance6'] !== null ? number_format((float)$r['protect_impedance6'], 4) : '',
                    $r['protect_impedance7'] !== null ? number_format((float)$r['protect_impedance7'], 4) : '',
                    $r['protect_impedance8'] !== null ? number_format((float)$r['protect_impedance8'], 4) : '',
                    $r['protect_impedance9'] !== null ? number_format((float)$r['protect_impedance9'], 4) : '',
                    $r['control_impedance1'] !== null ? number_format((float)$r['control_impedance1'], 4) : '',
                    $r['control_impedance2'] !== null ? number_format((float)$r['control_impedance2'], 4) : '',
                    $r['control_impedance3'] !== null ? number_format((float)$r['control_impedance3'], 4) : '',
                    $r['protect_error'] !== null ? number_format((float)$r['protect_error'], 4) : '',
                    $r['control_error'] !== null ? number_format((float)$r['control_error'], 4) : '',
                    $r['protect_conclusion'],
                    $r['control_conclusion'],
                    $r['operator'],
                    '≤' . ($r['error_standard'] ?: 10) . '%',
                    $r['created_at']
                ]));
            }

            fclose($output);
            exit;
        } catch (Throwable $e) {
            Response::exception($e, '导出失败');
        }
    }

    /**
     * ★ 权限加固（L2）：权限列表（仅管理员）
     */
    public function getPermissions(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可访问权限设置', [], 403);
            return;
        }
        try {
            $stmt = $this->db->query("SELECT u.id, u.username, u.full_name, u.is_admin,
                                             COALESCE(p.can_view_records, 1) AS can_view_records,
                                             COALESCE(p.can_input, 0) AS can_input
                                      FROM `users` u
                                      LEFT JOIN `impedance_permissions` p ON u.id = p.user_id
                                      ORDER BY u.id ASC");
            Response::success('success', ['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            Response::exception($e, '获取权限列表失败');
        }
    }

    /**
     * ★ 权限加固（L2）：保存单个用户的权限（仅管理员）
     */
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
            $canView  = !empty($input['can_view_records']) ? 1 : 0;
            $canInput = !empty($input['can_input']) ? 1 : 0;

            if ($targetId <= 0) {
                Response::error('无效用户');
                return;
            }

            $stmt = $this->db->prepare("INSERT INTO `impedance_permissions` (user_id, can_view_records, can_input)
                                        VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE
                                        can_view_records = VALUES(can_view_records), can_input = VALUES(can_input)");
            $stmt->execute([$targetId, $canView, $canInput]);

            AuditService::log('阻抗测试', '保存阻抗权限');
            Response::success('权限保存成功');
        } catch (Throwable $e) {
            Response::exception($e, '保存失败');
        }
    }
}