<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use PDO;
use Throwable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class DocErrorController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $userPerm;
    private array $validProcesses;

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->currentUser = function_exists('checkUser') ? checkUser() : ($_SESSION['user'] ?? []);
        $uName = (string)($this->currentUser['username'] ?? '');
        $this->isAdmin = (!empty($this->currentUser['is_admin']) || $uName === 'admin');

        $this->validProcesses = ['装置装配', '装置调试', '装置包装'];

        \App\Core\Schema::ensureOnce('doc_error', function () { $this->initTables(); });
        $this->userPerm = $this->resolveUserPerm();
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `doc_error_records` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `work_order` VARCHAR(64) NOT NULL,
                `task_code` VARCHAR(128) DEFAULT '',
                `description` TEXT,
                `file_error_desc` TEXT NOT NULL,
                `discovery_process` VARCHAR(50) NOT NULL,
                `discovery_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `discoverer_id` INT(11) DEFAULT NULL,
                `discoverer_name` VARCHAR(64) NOT NULL DEFAULT '',
                `is_modified` TINYINT(1) NOT NULL DEFAULT 0,
                `modify_time` DATETIME DEFAULT NULL,
                `modifier_id` INT(11) DEFAULT NULL,
                `modifier_name` VARCHAR(64) DEFAULT '',
                `remark` TEXT,
                `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_work_order` (`work_order`),
                KEY `idx_discoverer` (`discoverer_name`),
                KEY `idx_process` (`discovery_process`),
                KEY `idx_is_modified` (`is_modified`),
                KEY `idx_is_deleted` (`is_deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $cols = $this->db->query("SHOW COLUMNS FROM `doc_error_records`")->fetchAll(PDO::FETCH_COLUMN);
            $alterMap = [
                'task_code'       => "VARCHAR(128) DEFAULT ''",
                'description'     => "TEXT",
                'discoverer_id'   => "INT(11) DEFAULT NULL",
                'modifier_id'     => "INT(11) DEFAULT NULL",
                'modifier_name'   => "VARCHAR(64) DEFAULT ''",
                'modify_time'     => "DATETIME DEFAULT NULL",
                'updated_at'      => "DATETIME DEFAULT NULL",
            ];
            foreach ($alterMap as $col => $def) {
                if (!in_array($col, $cols, true)) {
                    try { $this->db->exec("ALTER TABLE `doc_error_records` ADD `{$col}` {$def}"); } catch (Throwable $e) {}
                }
            }

            $this->db->exec("CREATE TABLE IF NOT EXISTS `doc_error_permissions` (
                `user_id` INT(11) NOT NULL,
                `can_input` TINYINT(1) NOT NULL DEFAULT 1,
                `can_edit` TINYINT(1) NOT NULL DEFAULT 0,
                `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
                `can_export` TINYINT(1) NOT NULL DEFAULT 0,
                `can_manage` TINYINT(1) NOT NULL DEFAULT 0,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Throwable $e) {}
    }

    private function resolveUserPerm(): array
    {
        if ($this->isAdmin) {
            return ['can_input' => 1, 'can_edit' => 1, 'can_delete' => 1, 'can_export' => 1, 'can_manage' => 1];
        }
        try {
            $stmt = $this->db->prepare("SELECT can_input, can_edit, can_delete, can_export, can_manage FROM doc_error_permissions WHERE user_id = ?");
            $stmt->execute([(int)($this->currentUser['id'] ?? 0)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'can_input'  => (int)$row['can_input'],
                    'can_edit'   => (int)$row['can_edit'],
                    'can_delete' => (int)$row['can_delete'],
                    'can_export' => (int)$row['can_export'],
                    'can_manage' => (int)$row['can_manage'],
                ];
            }
        } catch (Throwable $e) {}
        return ['can_input' => 1, 'can_edit' => 0, 'can_delete' => 0, 'can_export' => 0, 'can_manage' => 0];
    }

    private function currentDisplayName(): string
    {
        $name = (string)($this->currentUser['full_name'] ?? '');
        if ($name === '') {
            $name = (string)($this->currentUser['username'] ?? '');
        }
        return $name;
    }

    private function getErpSqlSrvConnection(): ?PDO
    {
        try {
            // ★ 凭据一律取自 .env（无源码兜底），配置缺失直接拒绝连接
            $cfg = \App\Core\ExternalDb::sqlsrvConfig();
            if ($cfg === null) return null;
            [$host, $port, $db, $user, $pass] = $cfg;

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ];
            $dsn = "dblib:host={$host}:{$port};dbname={$db};charset=UTF-8";
            $pdo = new PDO($dsn, $user, $pass, $options);
            $pdo->exec("SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; SET ANSI_PADDING ON; SET CONCAT_NULL_YIELDS_NULL ON; SET QUOTED_IDENTIFIER ON;");
            return $pdo;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function buildFilters(array $input): array
    {
        $keyword    = trim((string)($input['keyword'] ?? ''));
        $dateFrom   = trim((string)($input['date_from'] ?? ''));
        $dateTo     = trim((string)($input['date_to'] ?? ''));
        $isModified = isset($input['is_modified']) && $input['is_modified'] !== '' ? trim((string)$input['is_modified']) : '';
        $workOrder  = trim((string)($input['work_order'] ?? ''));
        $discoverer = trim((string)($input['discoverer'] ?? ''));

        $where  = ["(r.is_deleted = 0 OR r.is_deleted IS NULL)"];
        $params = [];

        if ($keyword !== '') {
            $kw = "%{$keyword}%";
            $where[] = "(r.work_order LIKE ? OR r.task_code LIKE ? OR r.description LIKE ? OR r.file_error_desc LIKE ? OR r.discoverer_name LIKE ? OR r.modifier_name LIKE ? OR r.remark LIKE ?)";
            $params = array_merge($params, array_fill(0, 7, $kw));
        }
        if ($dateFrom !== '') {
            $where[] = "r.discovery_time >= ?";
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = "r.discovery_time <= ?";
            $params[] = $dateTo . ' 23:59:59';
        }
        if ($isModified !== '') {
            $where[] = "r.is_modified = ?";
            $params[] = (int)$isModified;
        }
        if ($workOrder !== '') {
            $where[] = "r.work_order LIKE ?";
            $params[] = "%{$workOrder}%";
        }
        if ($discoverer !== '') {
            $where[] = "(r.discoverer_name LIKE ? OR u1.full_name LIKE ? OR u1.username LIKE ?)";
            $params[] = "%{$discoverer}%";
            $params[] = "%{$discoverer}%";
            $params[] = "%{$discoverer}%";
        }

        return ['sql' => implode(' AND ', $where), 'params' => $params];
    }

    private function baseSelect(): string
    {
        return "SELECT r.*,
                       COALESCE(NULLIF(r.discoverer_name, ''), u1.full_name, u1.username, '未知') AS display_discoverer,
                       COALESCE(NULLIF(r.modifier_name, ''), u2.full_name, u2.username, '-') AS display_modifier
                FROM doc_error_records r
                LEFT JOIN users u1 ON r.discoverer_id = u1.id
                LEFT JOIN users u2 ON r.modifier_id = u2.id";
    }

    public function init(): void
    {
        Response::success('success', [
            'perm'      => $this->userPerm,
            'processes' => $this->validProcesses,
            'is_admin'  => $this->isAdmin,
        ]);
    }

    public function getMyPerm(): void
    {
        Response::success('success', ['perm' => $this->userPerm, 'is_admin' => $this->isAdmin]);
    }

    public function getWorkOrder(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;
        $workOrder = trim((string)($input['work_order'] ?? ''));

        if ($workOrder === '') {
            Response::error('工单号不能为空');
            return;
        }

        $sqlsrv = $this->getErpSqlSrvConnection();
        if (!$sqlsrv) {
            Response::success('工单数据源未连接，可继续手动提交', ['found' => false]);
            return;
        }

        try {
            $sql = "SELECT TOP 1 [编码] as code, [描述] as description FROM [J_全离散任务] WHERE [任务] = ? AND [状态] <> '已取消'";
            $stmt = $sqlsrv->prepare($sql);
            $stmt->execute([$workOrder]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                Response::success('工单信息已获取', [
                    'found'       => true,
                    'work_order'  => $workOrder,
                    'code'        => trim((string)($row['code'] ?? '')),
                    'description' => trim(mb_convert_encoding((string)($row['description'] ?? ''), 'UTF-8', 'UTF-8,GBK,GB2312')),
                ]);
            } else {
                Response::success('未找到对应工单信息，可继续提交', ['found' => false]);
            }
        } catch (Throwable $e) {
            error_log('[文档错误] 工单查询失败: ' . $e->getMessage());
            Response::success('查询失败，可继续提交', ['found' => false]);
        }
    }

    public function list(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_REQUEST;

            $page    = max(1, (int)($input['page'] ?? 1));
            $perPage = 30;
            $offset  = ($page - 1) * $perPage;

            $f = $this->buildFilters($input);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM doc_error_records r LEFT JOIN users u1 ON r.discoverer_id = u1.id WHERE {$f['sql']}");
            $cntStmt->execute($f['params']);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages   = (int)ceil($totalRecords / $perPage);

            $sql = $this->baseSelect() . " WHERE {$f['sql']} ORDER BY r.id DESC LIMIT {$offset}, {$perPage}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($f['params']);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($list as &$row) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = mb_convert_encoding($v, 'UTF-8', 'UTF-8,GBK,GB2312');
                    }
                }
                if (empty($row['modify_time'])) {
                    $row['modify_time'] = !empty($row['updated_at']) && !empty($row['is_modified']) ? $row['updated_at'] : '';
                }
            }
            unset($row);

            Response::success('success', [
                'list'          => $list,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'current_page'  => $page,
                'is_admin'      => $this->isAdmin,
                'perm'          => $this->userPerm,
                'processes'     => $this->validProcesses,
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取列表失败');
        }
    }

    public function get(): void
    {
        try {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                Response::error('无效记录ID');
                return;
            }
            $stmt = $this->db->prepare("SELECT * FROM doc_error_records WHERE id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                Response::error('记录不存在');
                return;
            }
            Response::success('success', $row);
        } catch (Throwable $e) {
            Response::exception($e, '读取记录失败');
        }
    }

    public function add(): void
    {
        try {
            if (!$this->isAdmin && empty($this->userPerm['can_input'])) {
                Response::error('您没有录入权限', [], 403);
                return;
            }

            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $workOrder = trim((string)($input['work_order'] ?? ''));
            $taskCode  = trim((string)($input['task_code'] ?? ''));
            $desc      = trim((string)($input['description'] ?? ''));
            $fileError = trim((string)($input['file_error_desc'] ?? ''));
            $process   = trim((string)($input['discovery_process'] ?? ''));
            $remark    = trim((string)($input['remark'] ?? ''));

            if ($workOrder === '' || $process === '' || $fileError === '') {
                Response::error('工单号、发现工序和文件问题描述不能为空');
                return;
            }
            if (!in_array($process, $this->validProcesses, true)) {
                Response::error('选择的工序无效');
                return;
            }

            $uName = $this->currentDisplayName();

            $stmt = $this->db->prepare("INSERT INTO doc_error_records
                (work_order, task_code, description, file_error_desc, discovery_process, discovery_time, discoverer_id, discoverer_name, remark, is_modified, is_deleted, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, 0, 0, NOW(), NOW())");
            $stmt->execute([
                $workOrder, $taskCode, $desc, $fileError, $process,
                (int)($this->currentUser['id'] ?? 0), $uName, $remark,
            ]);

            AuditService::log('文件错误记录', "登记作业文件错误 [工单: {$workOrder} / 发现工序: {$process}]");
            Response::success('登记成功', ['id' => (int)$this->db->lastInsertId()]);
        } catch (Throwable $e) {
            Response::exception($e, '新增失败');
        }
    }

    public function update(): void
    {
        try {
            if (!$this->isAdmin && empty($this->userPerm['can_edit'])) {
                Response::error('您没有编辑权限', [], 403);
                return;
            }

            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $id         = (int)($input['id'] ?? 0);
            $fileError  = trim((string)($input['file_error_desc'] ?? ''));
            $process    = trim((string)($input['discovery_process'] ?? ''));
            $isModified = (int)($input['is_modified'] ?? 0);
            $remark     = trim((string)($input['remark'] ?? ''));

            if ($id <= 0 || $fileError === '' || $process === '') {
                Response::error('修改内容不完整');
                return;
            }

            $modifierName = $this->currentDisplayName();
            if ($modifierName === '') {
                $modifierName = '管理员';
            }

            $stmt = $this->db->prepare("UPDATE doc_error_records SET
                file_error_desc = ?,
                discovery_process = ?,
                is_modified = ?,
                modify_time = NOW(),
                updated_at = NOW(),
                modifier_id = ?,
                modifier_name = ?,
                remark = ?
                WHERE id = ?");
            $res = $stmt->execute([
                $fileError, $process, $isModified,
                (int)($this->currentUser['id'] ?? 0), $modifierName,
                $remark, $id,
            ]);

            if ($res) {
                AuditService::log('文件错误记录', "修改作业文件错误记录 [ID: {$id}]");
                Response::success('修改已保存');
            } else {
                Response::error('数据库更新失败');
            }
        } catch (Throwable $e) {
            Response::exception($e, '保存异常');
        }
    }

    public function delete(): void
    {
        try {
            if (!$this->isAdmin && empty($this->userPerm['can_delete'])) {
                Response::error('您没有删除权限', [], 403);
                return;
            }

            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? []))));
            if (empty($ids)) {
                Response::error('请选择要删除的记录');
                return;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("UPDATE doc_error_records SET is_deleted = 1, updated_at = NOW() WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $count = $stmt->rowCount();

            AuditService::log('文件错误记录', "批量删除作业文件错误记录 {$count} 条");
            Response::success("成功删除 {$count} 条记录", ['count' => $count]);
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }

    public function export(): void
    {
        try {
            if (!$this->isAdmin && empty($this->userPerm['can_export'])) {
                Response::error('您没有导出权限', [], 403);
                return;
            }

            $f = $this->buildFilters($_GET);
            $sql = $this->baseSelect() . " WHERE {$f['sql']} ORDER BY r.id DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($f['params']);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $headers = ['ID', '工单号', '代码', '工单描述', '文件问题描述', '发现工序', '发现时间', '发现人', '是否修改', '修改时间', '修改人', '备注说明'];

            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('文件错误记录');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($rows as $r) {
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['id'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)$r['work_order'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $line, (string)($r['task_code'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $line, (string)($r['description'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('E' . $line, (string)$r['file_error_desc'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('F' . $line, (string)$r['discovery_process'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('G' . $line, (string)$r['discovery_time'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('H' . $line, (string)$r['display_discoverer'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('I' . $line, !empty($r['is_modified']) ? '是' : '否', DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('J' . $line, (string)($r['modify_time'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('K' . $line, (string)($r['display_modifier'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('L' . $line, (string)($r['remark'] ?: '-'), DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:L1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:L1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'L') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = '文件错误记录_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');

                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            $filename = '文件错误记录_' . date('Ymd_His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, array_map('excelSafe', $headers));
            foreach ($rows as $r) {
                fputcsv($output, array_map('excelSafe', [
                    $r['id'],
                    $r['work_order'],
                    $r['task_code'],
                    $r['description'],
                    $r['file_error_desc'],
                    $r['discovery_process'],
                    $r['discovery_time'],
                    $r['display_discoverer'],
                    !empty($r['is_modified']) ? '是' : '否',
                    $r['modify_time'] ?: '',
                    $r['display_modifier'],
                    $r['remark'],
                ]));
            }
            fclose($output);
            exit;
        } catch (Throwable $e) {
            Response::exception($e, '导出失败');
        }
    }

    public function getPermissions(): void
    {
        if (!$this->isAdmin && empty($this->userPerm['can_manage'])) {
            Response::error('无权限访问此功能', [], 403);
            return;
        }
        try {
            $sql = "SELECT u.id, u.username, u.full_name, u.is_admin,
                           COALESCE(p.can_input, 1)  AS can_input,
                           COALESCE(p.can_edit, 0)   AS can_edit,
                           COALESCE(p.can_delete, 0) AS can_delete,
                           COALESCE(p.can_export, 0) AS can_export,
                           COALESCE(p.can_manage, 0) AS can_manage
                    FROM users u
                    LEFT JOIN doc_error_permissions p ON u.id = p.user_id
                    ORDER BY u.id ASC";
            $stmt = $this->db->query($sql);
            Response::success('success', ['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            Response::exception($e, '获取权限列表失败');
        }
    }

    public function updatePermission(): void
    {
        if (!$this->isAdmin && empty($this->userPerm['can_manage'])) {
            Response::error('无权限访问此功能', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $targetId = (int)($input['user_id'] ?? 0);
            if ($targetId <= 0) {
                Response::error('无效的用户ID');
                return;
            }

            $canInput  = !empty($input['can_input']) ? 1 : 0;
            $canEdit   = !empty($input['can_edit']) ? 1 : 0;
            $canDelete = !empty($input['can_delete']) ? 1 : 0;
            $canExport = !empty($input['can_export']) ? 1 : 0;
            $canManage = !empty($input['can_manage']) ? 1 : 0;

            $stmt = $this->db->prepare("REPLACE INTO doc_error_permissions (user_id, can_input, can_edit, can_delete, can_export, can_manage, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$targetId, $canInput, $canEdit, $canDelete, $canExport, $canManage]);

            AuditService::log('文件错误记录', "更新用户权限 [用户ID: {$targetId}]");
            Response::success('权限保存成功');
        } catch (Throwable $e) {
            Response::exception($e, '保存失败');
        }
    }
}