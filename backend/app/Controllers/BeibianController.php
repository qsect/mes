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

class BeibianController
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
        \App\Core\Schema::ensureOnce('beibian', function () { $this->initTables(); });
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `module_records` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `work_order` VARCHAR(50) NOT NULL,
                `description` TEXT NOT NULL,
                `sn` VARCHAR(22) NOT NULL,
                `psn` VARCHAR(13) NOT NULL,
                `return_time` DATE NOT NULL,
                `is_returned` ENUM('是', '否') DEFAULT '否',
                `actual_return_date` DATE NULL,
                `operator` VARCHAR(100) NOT NULL,
                `remarks` TEXT,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_work_order_sn` (`work_order`, `sn`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
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

    public function list(): void
    {
        try {
            $searchAll = trim((string)($_GET['search_all'] ?? ''));
            $searchType = trim((string)($_GET['search_type'] ?? 'all'));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 30;
            $offset = ($page - 1) * $perPage;

            $where = ["1=1"];
            $params = [];

            if ($searchAll !== '') {
                $kw = "%{$searchAll}%";
                switch ($searchType) {
                    case 'work_order':
                        $where[] = "work_order LIKE ?";
                        $params[] = $kw;
                        break;
                    case 'sn':
                        $where[] = "sn LIKE ?";
                        $params[] = $kw;
                        break;
                    case 'psn':
                        $where[] = "psn LIKE ?";
                        $params[] = $kw;
                        break;
                    case 'description':
                        $where[] = "description LIKE ?";
                        $params[] = $kw;
                        break;
                    case 'operator':
                        $where[] = "operator LIKE ?";
                        $params[] = $kw;
                        break;
                    case 'remarks':
                        $where[] = "remarks LIKE ?";
                        $params[] = $kw;
                        break;
                    case 'is_returned':
                        $where[] = "is_returned = ?";
                        $params[] = $searchAll;
                        break;
                    case 'all':
                    default:
                        $where[] = "(work_order LIKE ? OR sn LIKE ? OR psn LIKE ? OR description LIKE ? OR operator LIKE ? OR remarks LIKE ? OR is_returned LIKE ?)";
                        for ($i = 0; $i < 7; $i++) {
                            $params[] = $kw;
                        }
                        break;
                }
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM module_records WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($totalRecords / $perPage);

            $sql = "SELECT * FROM module_records WHERE {$whereSql} ORDER BY created_at DESC LIMIT {$offset}, {$perPage}";
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
            Response::exception($e, '获取列表失败');
        }
    }

    public function searchSn(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;
        $sn = trim((string)($input['sn'] ?? ''));

        if (empty($sn)) {
            Response::error('SN 不能为空');
            return;
        }

        $found = false;
        $workOrder = '';
        $desc = '';

        $prefix16 = strlen($sn) >= 16 ? substr($sn, 0, 16) : $sn;

        $sqlsrv = $this->getErpSqlSrvConnection();
        if ($sqlsrv) {
            try {
                $stmt = $sqlsrv->prepare("
                    SELECT TOP 1 [任务] as work_order, [描述] as description, [批号] as batch
                    FROM [J_全离散任务]
                    WHERE ([批号] LIKE ? OR [批号] = ?) AND [状态] <> '已取消'
                    ORDER BY [任务] DESC
                ");
                $stmt->execute([$prefix16 . '%', $prefix16]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row && !empty($row['work_order'])) {
                    $workOrder = trim((string)$row['work_order']);
                    $desc = trim(mb_convert_encoding((string)($row['description'] ?? ''), 'UTF-8', 'UTF-8,GBK,GB2312'));
                    $found = true;
                }
            } catch (Throwable $e) {}
        }

        if (!$found) {
            $mesPdo = $this->getMesConnection();
            if ($mesPdo) {
                try {
                    $stmtMes = $mesPdo->prepare("SELECT task_no, mater_desc FROM tab_plan WHERE batch_no LIKE ? OR task_no LIKE ? LIMIT 1");
                    $stmtMes->execute([$prefix16 . '%', $prefix16 . '%']);
                    $mRow = $stmtMes->fetch(PDO::FETCH_ASSOC);
                    if ($mRow && !empty($mRow['task_no'])) {
                        $workOrder = trim((string)$mRow['task_no']);
                        $desc = trim((string)($mRow['mater_desc'] ?? ''));
                        $found = true;
                    }
                } catch (Throwable $e) {}
            }
        }

        if (!$found) {
            try {
                $stmt = $this->db->prepare("SELECT task_order, description FROM trace_tasks WHERE batch_prefix = ? OR batch_no LIKE ? LIMIT 1");
                $stmt->execute([$prefix16, "%{$prefix16}%"]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $workOrder = trim((string)$row['task_order']);
                    $desc = trim((string)($row['description'] ?? ''));
                    $found = true;
                }
            } catch (Throwable $e) {}
        }

        Response::success('success', [
            'found'       => $found,
            'work_order'  => $workOrder,
            'description' => $desc
        ]);
    }

    public function searchOrder(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_REQUEST;
            $workOrder = trim((string)($input['work_order'] ?? ''));

            if (empty($workOrder)) {
                Response::error('工单号不能为空');
                return;
            }

            $desc = '';
            $found = false;

            $sqlsrv = $this->getErpSqlSrvConnection();
            if ($sqlsrv) {
                try {
                    $stmt = $sqlsrv->prepare("SELECT TOP 1 [描述] as description FROM [J_全离散任务] WHERE [任务] = ? OR [编码] = ? ORDER BY [任务] DESC");
                    $stmt->execute([$workOrder, $workOrder]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && !empty($row['description'])) {
                        $desc = trim(mb_convert_encoding((string)$row['description'], 'UTF-8', 'UTF-8,GBK,GB2312'));
                        $found = true;
                    }
                } catch (Throwable $e) {}
            }

            Response::success('success', [
                'found'       => $found,
                'description' => $desc
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '查询工单信息失败');
        }
    }

    public function add(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $work_order = trim((string)($input['work_order'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $sn = trim((string)($input['sn'] ?? ''));
            $psn = trim((string)($input['psn'] ?? ''));
            $return_time = trim((string)($input['return_time'] ?? ''));
            $is_returned = trim((string)($input['is_returned'] ?? '否'));
            $actual_return_date = !empty($input['actual_return_date']) ? trim((string)$input['actual_return_date']) : null;
            $remarks = trim((string)($input['remarks'] ?? ''));

            if (empty($work_order) || empty($description) || empty($sn) || empty($psn) || empty($return_time)) {
                Response::error('工单号、描述、SN、PSN、返回北变时间为必填项');
                return;
            }

            if (!preg_match('/^\d+$/', $work_order)) {
                Response::error('工单号必须为纯数字');
                return;
            }
            if (!preg_match('/^\d{22}$/', $sn)) {
                Response::error('SN必须为22位纯数字');
                return;
            }
            if (!preg_match('/^[A-Za-z0-9]{13}$/', $psn)) {
                Response::error('PSN必须为13位字母或数字的组合');
                return;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $return_time)) {
                Response::error('返回北变时间格式不正确，应为 YYYY-MM-DD 格式');
                return;
            }
            if ($actual_return_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $actual_return_date)) {
                Response::error('返回时间格式不正确，应为 YYYY-MM-DD 格式');
                return;
            }

            $chk = $this->db->prepare("SELECT id FROM module_records WHERE work_order = ? AND sn = ? LIMIT 1");
            $chk->execute([$work_order, $sn]);
            if ($chk->fetch()) {
                Response::error('该工单和SN组合的记录已存在，不可重复添加');
                return;
            }

            $operator = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : ($this->currentUser['username'] ?? '未知');

            $sql = "INSERT INTO module_records (work_order, description, sn, psn, return_time, is_returned, actual_return_date, operator, remarks, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$work_order, $description, $sn, $psn, $return_time, $is_returned, $actual_return_date, $operator, $remarks]);

            AuditService::log('返厂管理', '新增返厂记录');
            Response::success('记录添加成功', ['id' => (int)$this->db->lastInsertId()]);
        } catch (Throwable $e) {
            Response::exception($e, '添加失败');
        }
    }

    public function update(): void
    {
        // ★ 权限校验：编辑仅管理员可用
        if (!$this->isAdmin) {
            Response::error('只有管理员可编辑返厂记录', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                Response::error('无效的记录ID');
                return;
            }

            $work_order = trim((string)($input['work_order'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $sn = trim((string)($input['sn'] ?? ''));
            $psn = trim((string)($input['psn'] ?? ''));
            $return_time = trim((string)($input['return_time'] ?? ''));
            $is_returned = trim((string)($input['is_returned'] ?? '否'));
            $actual_return_date = !empty($input['actual_return_date']) ? trim((string)$input['actual_return_date']) : null;
            $remarks = trim((string)($input['remarks'] ?? ''));

            if (empty($work_order) || empty($description) || empty($sn) || empty($psn) || empty($return_time)) {
                Response::error('工单号、描述、SN、PSN、返回北变时间为必填项');
                return;
            }

            if (!preg_match('/^\d+$/', $work_order)) { Response::error('工单号必须为纯数字'); return; }
            if (!preg_match('/^\d{22}$/', $sn)) { Response::error('SN必须为22位纯数字'); return; }
            if (!preg_match('/^[A-Za-z0-9]{13}$/', $psn)) { Response::error('PSN必须为13位字母或数字的组合'); return; }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $return_time)) { Response::error('返回北变时间格式不正确'); return; }
            if ($actual_return_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $actual_return_date)) { Response::error('返回时间格式不正确'); return; }

            $operator = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : ($this->currentUser['username'] ?? '未知');

            $sql = "UPDATE module_records SET work_order = ?, description = ?, sn = ?, psn = ?, return_time = ?, is_returned = ?, actual_return_date = ?, remarks = ?, operator = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$work_order, $description, $sn, $psn, $return_time, $is_returned, $actual_return_date, $remarks, $operator, $id]);

            AuditService::log('返厂管理', '修改返厂记录');
            Response::success('记录修改成功');
        } catch (Throwable $e) {
            Response::exception($e, '更新失败');
        }
    }

    public function detail(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $this->db->prepare("SELECT * FROM module_records WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            Response::success('success', ['record' => $row]);
        } else {
            Response::error('记录不存在');
        }
    }

    public function delete(): void
    {
        try {
            if (!$this->isAdmin) {
                Response::error('只有管理员拥有删除权限', [], 403);
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
            $stmt = $this->db->prepare("DELETE FROM module_records WHERE id IN ($placeholders)");
            $stmt->execute(array_map('intval', $ids));
            $count = $stmt->rowCount();

            AuditService::log('返厂管理', '删除返厂记录');
            Response::success("成功删除 {$count} 条返厂记录", ['count' => $count]);
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }

    public function export(): void
    {
        try {
            $searchAll = trim((string)($_GET['search_all'] ?? ''));
            $searchType = trim((string)($_GET['search_type'] ?? 'all'));

            $where = ["1=1"];
            $params = [];

            if ($searchAll !== '') {
                $kw = "%{$searchAll}%";
                if ($searchType === 'work_order') { $where[] = "work_order LIKE ?"; $params[] = $kw; }
                elseif ($searchType === 'sn') { $where[] = "sn LIKE ?"; $params[] = $kw; }
                elseif ($searchType === 'psn') { $where[] = "psn LIKE ?"; $params[] = $kw; }
                elseif ($searchType === 'is_returned') { $where[] = "is_returned = ?"; $params[] = $searchAll; }
                else {
                    $where[] = "(work_order LIKE ? OR sn LIKE ? OR psn LIKE ? OR description LIKE ? OR operator LIKE ? OR remarks LIKE ?)";
                    for ($i = 0; $i < 6; $i++) { $params[] = $kw; }
                }
            }

            $whereSql = implode(' AND ', $where);
            $stmt = $this->db->prepare("SELECT * FROM module_records WHERE {$whereSql} ORDER BY created_at DESC");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $headers = ['ID', '工单', '描述', 'SN', 'PSN', '返回北变时间', '是否返回', '返回时间', '操作人', '备注', '创建时间'];

            // 优先导出 Excel（服务器已安装 PhpSpreadsheet）
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('北边返厂记录');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($rows as $r) {
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['id'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)$r['work_order'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $line, (string)$r['description'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $line, (string)$r['sn'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('E' . $line, (string)$r['psn'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('F' . $line, (string)$r['return_time'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('G' . $line, (string)$r['is_returned'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('H' . $line, (string)($r['actual_return_date'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('I' . $line, (string)$r['operator'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('J' . $line, (string)$r['remarks'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('K' . $line, (string)$r['created_at'], DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:K1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:K1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'K') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = '北边返厂记录导出_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="北边返厂记录导出_' . date('Ymd_His') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, array_map('excelSafe', $headers));

            foreach ($rows as $r) {
                fputcsv($output, array_map('excelSafe', [
                    $r['id'],
                    "\t" . $r['work_order'],
                    $r['description'],
                    "\t" . $r['sn'],
                    "\t" . $r['psn'],
                    $r['return_time'],
                    $r['is_returned'],
                    $r['actual_return_date'] ?: '-',
                    $r['operator'],
                    $r['remarks'],
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