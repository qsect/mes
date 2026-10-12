<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\ModulePerm;
use App\Core\Response;
use App\Services\AuditService;
use PDO;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class FirstPieceController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private bool $canManageCriteria;

    public function __construct()
    {
        $this->db = Database::getConnection('default');
        $this->currentUser = function_exists('checkUser') ? checkUser() : ($_SESSION['user'] ?? []);
        $username = (string)($this->currentUser['username'] ?? '');
        $this->isAdmin = !empty($this->currentUser['is_admin']) || $username === 'admin';
        \App\Core\Schema::ensureOnce('first_piece', function () { $this->initTables(); });
        $this->canManageCriteria = $this->checkCanManageCriteria();
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `module16_records` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `work_order` VARCHAR(100) NOT NULL,
                `task_code` VARCHAR(100) DEFAULT NULL,
                `description` TEXT,
                `quantity` VARCHAR(50) DEFAULT NULL,
                `batch_no` VARCHAR(50) DEFAULT NULL,
                `barcode` TEXT,
                `qrcode` TEXT,
                `need_check` TINYINT(1) DEFAULT 0,
                `check_result` VARCHAR(20) DEFAULT '无',
                `created_by` INT NOT NULL,
                `created_by_name` VARCHAR(100) NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `is_deleted` TINYINT(1) DEFAULT 0,
                INDEX `idx_work_order` (`work_order`),
                INDEX `idx_created_at` (`created_at`),
                INDEX `idx_is_deleted` (`is_deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `module16_criteria` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `work_order` VARCHAR(100) NOT NULL UNIQUE,
                `barcode_expected` VARCHAR(255) DEFAULT NULL,
                `qrcode_expected` TEXT,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `first_piece_permissions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL UNIQUE,
                `can_manage_criteria` tinyint(1) NOT NULL DEFAULT 0,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Throwable $e) {}
    }

    private function checkCanManageCriteria(): bool
    {
        if ($this->isAdmin) return true;
        try {
            $st = $this->db->prepare("SELECT can_manage_criteria FROM first_piece_permissions WHERE user_id = ? LIMIT 1");
            $st->execute([(int)($this->currentUser['id'] ?? 0)]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
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

    public function init(): void
    {
        Response::success('success', [
            'is_admin'            => $this->isAdmin,
            'can_manage_criteria' => $this->canManageCriteria,
            'can_manage'          => ($this->isAdmin || ModulePerm::canEdit($this->currentUser, 'first_piece'))
        ]);
    }

    public function getWorkOrder(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;
        $wo = trim((string)($input['work_order'] ?? ''));

        if (empty($wo)) {
            Response::error('请输入工单号');
            return;
        }

        $found = false;
        $orderData = null;

        $sqlsrv = $this->getErpSqlSrvConnection();
        if ($sqlsrv) {
            try {
                $stmt = $sqlsrv->prepare("
                    SELECT TOP 1 [任务] as work_order, [编码] as task_code, [描述] as description, [数量] as quantity, [批号] as batch_no
                    FROM [J_全离散任务] 
                    WHERE ([任务] = ? OR [编码] = ?) AND [状态] <> '已取消'
                    ORDER BY [任务] DESC
                ");
                $stmt->execute([$wo, $wo]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $desc = trim(mb_convert_encoding((string)($row['description'] ?? ''), 'UTF-8', 'UTF-8,GBK,GB2312'));
                    $batch = !empty($row['batch_no']) ? substr(trim((string)$row['batch_no']), 0, 16) : '';
                    $orderData = [
                        'work_order'  => trim((string)$row['work_order']),
                        'task_code'   => trim((string)$row['task_code']),
                        'description' => $desc,
                        'quantity'    => (int)$row['quantity'],
                        'batch_no'    => $batch
                    ];
                    $found = true;
                }
            } catch (Throwable $e) {}
        }

        if (!$found) {
            try {
                $stmt = $this->db->prepare("SELECT task_order, parent_code, description, quantity, batch_prefix, batch_no FROM trace_tasks WHERE task_order = ? LIMIT 1");
                $stmt->execute([$wo]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $orderData = [
                        'work_order'  => trim((string)$row['task_order']),
                        'task_code'   => trim((string)$row['parent_code']),
                        'description' => trim((string)$row['description']),
                        'quantity'    => (int)$row['quantity'],
                        'batch_no'    => trim((string)($row['batch_prefix'] ?: $row['batch_no']))
                    ];
                    $found = true;
                }
            } catch (Throwable $e) {}
        }

        if ($found && $orderData) {
            Response::success('success', $orderData);
        } else {
            Response::error('未找到该工单信息或已取消');
        }
    }

    public function saveRecord(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $work_order = trim((string)($input['work_order'] ?? ''));
            $task_code = trim((string)($input['task_code'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $quantity = trim((string)($input['quantity'] ?? ''));
            $batch_no = trim((string)($input['batch_no'] ?? ''));
            $barcode = trim((string)($input['barcode'] ?? ''));
            $qrcode = trim((string)($input['qrcode'] ?? ''));
            $need_check = !empty($input['need_check']) ? 1 : 0;
            $manual_result = trim((string)($input['manual_result'] ?? ($input['check_result'] ?? '')));

            if (empty($work_order)) {
                Response::error('工单号不能为空');
                return;
            }
            if (empty($barcode) && empty($qrcode)) {
                Response::error('请至少扫描或输入一个条形码或二维码');
                return;
            }

            $check_result = '无';
            if (in_array($manual_result, ['合格', '不合格'], true)) {
                $check_result = $manual_result;
            } elseif ($need_check === 1) {
                $cStmt = $this->db->prepare("SELECT barcode_expected, qrcode_expected FROM module16_criteria WHERE work_order = ? LIMIT 1");
                $cStmt->execute([$work_order]);
                $crit = $cStmt->fetch(PDO::FETCH_ASSOC);

                if ($crit) {
                    $bOk = true;
                    $qOk = true;
                    if (!empty($crit['barcode_expected']) && $barcode !== trim((string)$crit['barcode_expected'])) {
                        $bOk = false;
                    }
                    if (!empty($crit['qrcode_expected']) && $qrcode !== trim((string)$crit['qrcode_expected'])) {
                        $qOk = false;
                    }
                    $check_result = ($bOk && $qOk) ? '合格' : '不合格';
                } else {
                    $check_result = '不合格';
                }
            }

            $creatorName = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : ($this->currentUser['username'] ?? '未知');

            $sql = "INSERT INTO module16_records 
                    (work_order, task_code, description, quantity, batch_no, barcode, qrcode, need_check, check_result, created_by, created_by_name, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $work_order, $task_code, $description, $quantity, $batch_no,
                $barcode, $qrcode, $need_check, $check_result,
                (int)($this->currentUser['id'] ?? 0), $creatorName
            ]);

            AuditService::log('首件检验', '保存首件检记录');
            Response::success('首件检记录保存成功', ['check_result' => $check_result]);
        } catch (Throwable $e) {
            Response::exception($e, '保存异常');
        }
    }

    public function list(): void
    {
        try {
            $wo = trim((string)($_GET['work_order'] ?? ''));
            $barcode = trim((string)($_GET['barcode'] ?? ''));
            $check_result = trim((string)($_GET['check_result'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 30;
            $offset = ($page - 1) * $perPage;

            $where = ["is_deleted = 0"];
            $params = [];

            if ($wo !== '') {
                $where[] = "work_order LIKE ?";
                $params[] = "%{$wo}%";
            }
            if ($barcode !== '') {
                $where[] = "(barcode LIKE ? OR qrcode LIKE ?)";
                $params[] = "%{$barcode}%";
                $params[] = "%{$barcode}%";
            }
            if ($check_result !== '') {
                $where[] = "check_result = ?";
                $params[] = $check_result;
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM module16_records WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($totalRecords / $perPage);

            $sql = "SELECT * FROM module16_records WHERE {$whereSql} ORDER BY created_at DESC LIMIT {$offset}, {$perPage}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'records'             => $records,
                'total_records'       => $totalRecords,
                'total_pages'         => $totalPages,
                'current_page'        => $page,
                'is_admin'            => $this->isAdmin,
                'can_manage_criteria' => $this->canManageCriteria
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取记录失败');
        }
    }

    public function getCriteria(): void
    {
        $wo = trim((string)($_GET['work_order'] ?? ($_POST['work_order'] ?? '')));
        if (empty($wo)) {
            Response::error('工单号不能为空');
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM module16_criteria WHERE work_order = ? LIMIT 1");
        $stmt->execute([$wo]);
        $crit = $stmt->fetch(PDO::FETCH_ASSOC);
        Response::success('success', ['criteria' => $crit ?: null]);
    }

    public function saveCriteria(): void
    {
        if (!$this->canManageCriteria) {
            Response::error('权限受限：您没有配置或修改判据标准的权限！', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $work_order = trim((string)($input['work_order'] ?? ''));
            $barcode_exp = trim((string)($input['barcode_expected'] ?? ''));
            $qrcode_exp = trim((string)($input['qrcode_expected'] ?? ''));

            if (empty($work_order)) {
                Response::error('请输入工单号');
                return;
            }

            $stmt = $this->db->prepare("INSERT INTO module16_criteria (work_order, barcode_expected, qrcode_expected, updated_at) 
                                  VALUES (?, ?, ?, NOW()) 
                                  ON DUPLICATE KEY UPDATE barcode_expected = VALUES(barcode_expected), qrcode_expected = VALUES(qrcode_expected), updated_at = NOW()");
            $stmt->execute([$work_order, $barcode_exp, $qrcode_exp]);

            Response::success('判据配置保存成功');
        } catch (Throwable $e) {
            Response::exception($e, '保存判据失败');
        }
    }

    public function listCriteria(): void
    {
        if (!$this->canManageCriteria) {
            Response::error('权限受限：您没有查看判据标准的权限！', [], 403);
            return;
        }
        try {
            $stmt = $this->db->query("SELECT * FROM module16_criteria ORDER BY updated_at DESC");
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
            Response::success('success', ['list' => $list]);
        } catch (Throwable $e) {
            Response::exception($e, '获取判据列表失败');
        }
    }

    public function deleteCriteria(): void
    {
        // ★ 权限加固：判据属配置数据，管理员或持「判据标准管理」授权者可删
        if (!$this->isAdmin && !$this->canManageCriteria) {
            Response::error('只有管理员或被授权用户可删除判据', [], 403);
            return;
        }
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;
        $id = (int)($input['id'] ?? ($_POST['id'] ?? 0));
        $stmt = $this->db->prepare("DELETE FROM module16_criteria WHERE id = ?");
        $stmt->execute([$id]);
        Response::success('判据已删除');
    }

    public function delete(): void
    {
        // ★ 权限加固：删除首件检记录属破坏性操作，管理员或被授权（权限分配→允许编辑与数据管理）用户方可执行
        if (!$this->isAdmin && !ModulePerm::canEdit($this->currentUser, 'first_piece')) {
            Response::error('只有管理员或被授权用户可删除记录', [], 403);
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
            $sql = "UPDATE module16_records SET is_deleted = 1 WHERE id IN ($placeholders)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_map('intval', $ids));
            $count = $stmt->rowCount();

            Response::success("成功删除 {$count} 条记录", ['count' => $count]);
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }

    public function export(): void
    {
        try {
            $selectedIdsRaw = trim((string)($_GET['selected_ids'] ?? ''));
            $where = ["is_deleted = 0"];
            $params = [];

            if (!empty($selectedIdsRaw)) {
                $ids = array_filter(array_map('intval', explode(',', $selectedIdsRaw)));
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $where[] = "id IN ($placeholders)";
                    $params = $ids;
                }
            }

            $whereSql = implode(' AND ', $where);
            $sql = "SELECT * FROM module16_records WHERE {$whereSql} ORDER BY created_at DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $headers = ['ID', '工单号', '编码', '描述', '批次', '条形码', '二维码', '需要判断', '判断结果', '创建人', '创建时间'];

            // 优先导出 Excel（服务器已安装 PhpSpreadsheet）
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('首件检记录');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($rows as $r) {
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['id'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)$r['work_order'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $line, (string)($r['task_code'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $line, (string)($r['description'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('E' . $line, (string)($r['batch_no'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('F' . $line, (string)$r['barcode'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('G' . $line, (string)$r['qrcode'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('H' . $line, $r['need_check'] ? '是' : '否', DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('I' . $line, (string)($r['check_result'] ?: '无'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('J' . $line, (string)$r['created_by_name'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('K' . $line, (string)$r['created_at'], DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:K1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:K1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'K') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = '首件检记录导出_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="首件检记录导出_' . date('Ymd_His') . '.csv"');

            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, array_map('excelSafe', $headers));

            foreach ($rows as $r) {
                fputcsv($output, array_map('excelSafe', [
                    $r['id'],
                    "\t" . $r['work_order'],
                    $r['task_code'] ?: '-',
                    $r['description'] ?: '-',
                    $r['batch_no'] ?: '-',
                    "\t" . $r['barcode'],
                    "\t" . $r['qrcode'],
                    $r['need_check'] ? '是' : '否',
                    $r['check_result'] ?: '无',
                    $r['created_by_name'],
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