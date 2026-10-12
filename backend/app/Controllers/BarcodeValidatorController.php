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

class BarcodeValidatorController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $perms = [];

    public function __construct()
    {
        $this->db = Database::getConnection('default');
        $this->currentUser = function_exists('checkUser') ? checkUser() : ($_SESSION['user'] ?? []);
        $username = (string)($this->currentUser['username'] ?? '');
        $this->isAdmin = !empty($this->currentUser['is_admin']) || $username === 'admin';
        \App\Core\Schema::ensureOnce('barcode_validator', function () { $this->initTables(); });
        $this->perms = $this->resolvePerms();
    }

    /**
     * 解析当前用户在「条码验证比对」模块的细粒度权限
     * 管理员默认拥有全部权限；普通用户读取 barcode_validator_permissions
     */
    private function resolvePerms(): array
    {
        if ($this->isAdmin) {
            return [
                'can_save_settings' => 1,
                'can_delete'        => 1,
                'can_edit'          => 1,
                'is_admin'          => 1,
            ];
        }
        $uid = (int)($this->currentUser['id'] ?? 0);
        $st = $this->db->prepare("SELECT can_save_settings, can_delete, can_edit
                                  FROM `barcode_validator_permissions` WHERE user_id = ? LIMIT 1");
        $st->execute([$uid]);
        $p = $st->fetch(PDO::FETCH_ASSOC);

        return [
            'can_save_settings' => $p ? (int)$p['can_save_settings'] : 1,
            'can_delete'        => $p ? (int)$p['can_delete'] : 0,
            'can_edit'          => $p ? (int)$p['can_edit'] : 0,
            'is_admin'          => 0,
        ];
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `barcode_reference` (
              `id` INT PRIMARY KEY AUTO_INCREMENT,
              `work_order` VARCHAR(50) NOT NULL,
              `row_index` INT NOT NULL,
              `mod1` VARCHAR(100) DEFAULT '',
              `mod2` VARCHAR(100) DEFAULT '',
              `mod3` VARCHAR(100) DEFAULT '',
              `mod4` VARCHAR(100) DEFAULT '',
              `mod5` VARCHAR(100) DEFAULT '',
              `mask_barcode` VARCHAR(100) DEFAULT '',
              `mask_qr` VARCHAR(100) DEFAULT '',
              `batch_prefix` VARCHAR(16) DEFAULT '',
              `upload_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_work_row` (`work_order`, `row_index`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `barcode_completed_rows` (
              `id` INT PRIMARY KEY AUTO_INCREMENT,
              `work_order` VARCHAR(50) NOT NULL,
              `row_index` INT NOT NULL,
              `operator` VARCHAR(50) NOT NULL,
              `sn` VARCHAR(50) NOT NULL,
              `mod1` VARCHAR(100) DEFAULT '',
              `mod2` VARCHAR(100) DEFAULT '',
              `mod3` VARCHAR(100) DEFAULT '',
              `mod4` VARCHAR(100) DEFAULT '',
              `mod5` VARCHAR(100) DEFAULT '',
              `mask_barcode` VARCHAR(100) DEFAULT '',
              `mask_qr` VARCHAR(100) DEFAULT '',
              `completed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_wo_row` (`work_order`, `row_index`),
              INDEX `idx_sn` (`sn`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `barcode_user_settings` (
              `id` INT PRIMARY KEY AUTO_INCREMENT,
              `user_id` INT NOT NULL,
              `work_order` VARCHAR(50) NOT NULL,
              `enabled_fields` VARCHAR(255) DEFAULT '',
              `order_fields` VARCHAR(255) DEFAULT '',
              `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_work_order` (`work_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // ★ 条码验证比对模块的按用户细粒度权限
            $this->db->exec("CREATE TABLE IF NOT EXISTS `barcode_validator_permissions` (
              `id` INT PRIMARY KEY AUTO_INCREMENT,
              `user_id` INT NOT NULL,
              `can_save_settings` TINYINT(1) DEFAULT 1 COMMENT '验证字段设置',
              `can_delete`        TINYINT(1) DEFAULT 0 COMMENT '删除记录',
              `can_edit`          TINYINT(1) DEFAULT 0 COMMENT '编辑记录',
              `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_barcode_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
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
                PDO::ATTR_TIMEOUT            => 15,
            ];
            $dsn = "dblib:host={$sqlsrv_host}:{$sqlsrv_port};dbname={$sqlsrv_db};charset=UTF-8";
            $pdo = new PDO($dsn, $sqlsrv_user, $sqlsrv_pass, $options);
            $pdo->exec("SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; SET ANSI_PADDING ON; SET CONCAT_NULL_YIELDS_NULL ON; SET QUOTED_IDENTIFIER ON;");
            return $pdo;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function fetchWorkorder(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;
        $wo = trim((string)($input['work_order'] ?? ''));
        if (empty($wo)) {
            Response::error('请输入工单号');
            return;
        }

        $sqlsrv = $this->getErpSqlSrvConnection();
        if (!$sqlsrv) {
            Response::error('ERP SQL Server 数据源连接失败');
            return;
        }

        try {
            $stmt = $sqlsrv->prepare("
                SELECT TOP 1 [任务] as work_order, [编码] as code, [描述] as description, [数量] as quantity, [批号] as batch
                FROM [J_全离散任务] 
                WHERE ([任务] = ? OR [编码] = ?) AND [状态] <> '已取消'
                ORDER BY [任务] DESC
            ");
            $stmt->execute([$wo, $wo]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $desc = trim(mb_convert_encoding($row['description'] ?? '', 'UTF-8', 'UTF-8,GBK,GB2312'));
                $batchPrefix = !empty($row['batch']) ? substr(trim($row['batch']), 0, 16) : '';
                Response::success('success', [
                    'work_order'   => trim($row['work_order']),
                    'code'         => trim($row['code']),
                    'description'  => $desc,
                    'quantity'     => (int)$row['quantity'],
                    'batch_prefix' => $batchPrefix
                ]);
            } else {
                Response::error('未找到该工单信息或已取消');
            }
        } catch (Throwable $e) {
            Response::exception($e, '工单查询异常');
        }
    }

    public function uploadReference(): void
    {
        try {
            $workOrder = trim((string)($_POST['work_order'] ?? ($_GET['work_order'] ?? '')));
            if (empty($workOrder)) {
                Response::error('工单号不能为空');
                return;
            }

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                Response::error('文件上传失败，请重新选择');
                return;
            }

            $file = $_FILES['file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $rows = [];

            if ($ext === 'csv') {
                $handle = fopen($file['tmp_name'], 'r');
                if (!$handle) {
                    Response::error('无法打开 CSV 文件');
                    return;
                }
                fgetcsv($handle);
                while (($data = fgetcsv($handle)) !== false) {
                    $data = array_pad($data, 7, '');
                    if (empty(array_filter($data))) continue;
                    $rows[] = [
                        'mod1' => trim($data[0]),
                        'mod2' => trim($data[1]),
                        'mod3' => trim($data[2]),
                        'mod4' => trim($data[3]),
                        'mod5' => trim($data[4]),
                        'mask_barcode' => trim($data[5]),
                        'mask_qr' => trim($data[6])
                    ];
                }
                fclose($handle);
            } elseif (in_array($ext, ['xlsx', 'xls'])) {
                $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
                if (!file_exists($autoloadPath)) {
                    Response::error('服务器未配置 PhpSpreadsheet 库，请使用 CSV 格式上传');
                    return;
                }
                require_once $autoloadPath;

                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
                $worksheet = $spreadsheet->getActiveSheet();
                $highestRow = $worksheet->getHighestRow();
                for ($row = 2; $row <= $highestRow; $row++) {
                    $cols = [];
                    for ($col = 1; $col <= 7; $col++) {
                        $cols[] = trim((string)($worksheet->getCellByColumnAndRow($col, $row)->getValue() ?? ''));
                    }
                    if (empty(array_filter($cols))) continue;
                    $rows[] = [
                        'mod1' => $cols[0],
                        'mod2' => $cols[1],
                        'mod3' => $cols[2],
                        'mod4' => $cols[3],
                        'mod5' => $cols[4],
                        'mask_barcode' => $cols[5],
                        'mask_qr' => $cols[6]
                    ];
                }
            } else {
                Response::error('仅支持 .xlsx, .xls 或 .csv 格式');
                return;
            }

            if (empty($rows)) {
                Response::error('参考表格内容为空或格式不正确');
                return;
            }

            $this->db->beginTransaction();
            $this->db->prepare("DELETE FROM barcode_reference WHERE work_order = ?")->execute([$workOrder]);

            $sql = "INSERT INTO barcode_reference (work_order, row_index, mod1, mod2, mod3, mod4, mod5, mask_barcode, mask_qr) VALUES ";
            $placeholders = [];
            $params = [];
            $rIdx = 0;
            foreach ($rows as $r) {
                $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $params[] = $workOrder;
                $params[] = $rIdx++;
                $params[] = $r['mod1'];
                $params[] = $r['mod2'];
                $params[] = $r['mod3'];
                $params[] = $r['mod4'];
                $params[] = $r['mod5'];
                $params[] = $r['mask_barcode'];
                $params[] = $r['mask_qr'];
            }

            $sql .= implode(',', $placeholders);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $this->db->commit();

            AuditService::log('条码校验', '导入参考数据');
            Response::success("导入成功，共 {$rIdx} 行参考数据", ['count' => $rIdx]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::exception($e, '导入异常');
        }
    }

    public function progress(): void
    {
        try {
            $workOrder = trim((string)($_REQUEST['work_order'] ?? ''));
            if (empty($workOrder)) {
                Response::error('工单号不能为空');
                return;
            }

            $refStmt = $this->db->prepare("SELECT * FROM barcode_reference WHERE work_order = ? ORDER BY row_index ASC");
            $refStmt->execute([$workOrder]);
            $refRows = $refStmt->fetchAll(PDO::FETCH_ASSOC);

            $compStmt = $this->db->prepare("SELECT row_index FROM barcode_completed_rows WHERE work_order = ? ORDER BY row_index ASC");
            $compStmt->execute([$workOrder]);
            $completedRows = $compStmt->fetchAll(PDO::FETCH_COLUMN);
            $completedRows = array_map('intval', $completedRows);

            Response::success('success', [
                'totalRows'     => count($refRows),
                'completedRows' => $completedRows,
                'reference'     => $refRows
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取进度失败');
        }
    }

    public function scanValidate(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $workOrder = trim((string)($input['work_order'] ?? ''));
            $rowIndex = (int)($input['row_index'] ?? -1);
            $field = trim((string)($input['field'] ?? ''));
            $value = trim((string)($input['value'] ?? ''));
            $batchPrefix = trim((string)($input['batch_prefix'] ?? ''));
            $quantity = (int)($input['quantity'] ?? 0);

            if (empty($workOrder) || $rowIndex < 0 || empty($field) || empty($value)) {
                Response::error('校验参数不完整');
                return;
            }

            if ($field === 'sn') {
                if (strlen($value) !== 22) {
                    Response::error('装置 SN 长度必须为 22 位，当前输入: ' . $value);
                    return;
                }
                if (!empty($batchPrefix) && substr($value, 0, 16) !== $batchPrefix) {
                    Response::error('SN 前 16 位与工单批次不匹配！输入: ' . substr($value, 0, 16) . '，期望: ' . $batchPrefix);
                    return;
                }

                $snSuffix = (int)substr($value, -6);
                if ($quantity > 0 && ($snSuffix < 1 || $snSuffix > $quantity)) {
                    Response::error("SN 编号超出计划范围 (000001 ~ " . str_pad((string)$quantity, 6, '0', STR_PAD_LEFT) . ")");
                    return;
                }

                $chkSn = $this->db->prepare("SELECT id FROM barcode_completed_rows WHERE work_order = ? AND sn = ? LIMIT 1");
                $chkSn->execute([$workOrder, $value]);
                if ($chkSn->fetch()) {
                    Response::error('该 SN 在本工单中已完成过校验，禁止重复使用');
                    return;
                }

                AuditService::log('条码校验', 'SN校验通过');
                Response::success('SN 校验通过');
            } else {
                $allowedFields = ['mod1', 'mod2', 'mod3', 'mod4', 'mod5', 'mask_barcode', 'mask_qr'];
                if (!in_array($field, $allowedFields)) {
                    Response::error('未知比对字段');
                    return;
                }

                $refStmt = $this->db->prepare("SELECT `{$field}` FROM barcode_reference WHERE work_order = ? AND row_index = ? LIMIT 1");
                $refStmt->execute([$workOrder, $rowIndex]);
                $expected = trim((string)($refStmt->fetchColumn() ?: ''));

                if ($expected === '') {
                    Response::error('该字段在参考表中未配置期望值');
                    return;
                }

                $compareValue = '';
                if (preg_match('/\d{22}/', $value, $matches)) {
                    $compareValue = $matches[0];
                } else {
                    $digitsOnly = preg_replace('/\D/', '', $value);
                    if (strlen($digitsOnly) === 22) {
                        $compareValue = $digitsOnly;
                    }
                }

                if (empty($compareValue)) {
                    Response::error('扫描内容中未找到有效的 22 位纯数字编码: ' . $value);
                    return;
                }

                if ($compareValue !== $expected) {
                    Response::error("比对不一致！扫描值: {$compareValue}，参考期望值: {$expected}");
                    return;
                }

                AuditService::log('条码校验', '条码比对通过');
                Response::success('比对一致，验证通过');
            }
        } catch (Throwable $e) {
            Response::exception($e, '校验异常');
        }
    }

    public function submitRow(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $workOrder = trim((string)($input['work_order'] ?? ''));
            $rowIndex = (int)($input['row_index'] ?? -1);
            $sn = trim((string)($input['sn'] ?? ''));
            $mod1 = trim((string)($input['mod1'] ?? ''));
            $mod2 = trim((string)($input['mod2'] ?? ''));
            $mod3 = trim((string)($input['mod3'] ?? ''));
            $mod4 = trim((string)($input['mod4'] ?? ''));
            $mod5 = trim((string)($input['mod5'] ?? ''));
            $maskBarcode = trim((string)($input['mask_barcode'] ?? ''));
            $maskQR = trim((string)($input['mask_qr'] ?? ''));
            $operator = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : ($this->currentUser['username'] ?? '未知');

            if (empty($workOrder) || $rowIndex < 0 || empty($sn)) {
                Response::error('提交数据不完整');
                return;
            }

            $chk = $this->db->prepare("SELECT id FROM barcode_completed_rows WHERE work_order = ? AND row_index = ?");
            $chk->execute([$workOrder, $rowIndex]);
            if ($chk->fetch()) {
                Response::success('该行已提交');
                return;
            }

            $sql = "INSERT INTO barcode_completed_rows (work_order, row_index, operator, sn, mod1, mod2, mod3, mod4, mod5, mask_barcode, mask_qr, completed_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$workOrder, $rowIndex, $operator, $sn, $mod1, $mod2, $mod3, $mod4, $mod5, $maskBarcode, $maskQR]);

            AuditService::log('条码校验', '提交校验行');
            Response::success("第 " . ($rowIndex + 1) . " 行校验完成");
        } catch (Throwable $e) {
            Response::exception($e, '提交行异常');
        }
    }

    public function getCompletedRows(): void
    {
        try {
            $workOrder = trim((string)($_GET['work_order'] ?? ($_POST['work_order'] ?? '')));
            $search = trim((string)($_GET['search'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = (int)($_GET['limit'] ?? 30);
            $offset = ($page - 1) * $perPage;

            $where = ["1=1"];
            $params = [];

            if ($workOrder !== '') {
                $where[] = "work_order = ?";
                $params[] = $workOrder;
            }
            if ($search !== '') {
                $kw = "%" . $search . "%";
                $where[] = "(work_order LIKE ? OR sn LIKE ? OR mod1 LIKE ? OR mask_barcode LIKE ? OR operator LIKE ?)";
                for ($i = 0; $i < 5; $i++) $params[] = $kw;
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM barcode_completed_rows WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($totalRecords / $perPage);

            $sql = "SELECT * FROM barcode_completed_rows WHERE {$whereSql} ORDER BY id DESC LIMIT {$offset}, {$perPage}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'rows'          => $rows,
                'total'         => $totalRecords,
                'total_pages'   => $totalPages,
                'current_page'  => $page
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取已完成记录失败');
        }
    }

    public function getSettings(): void
    {
        $workOrder = trim((string)($_REQUEST['work_order'] ?? ''));
        $defaultEnabled = ['mod1', 'mod2', 'mod3', 'mod4', 'mod5', 'mask_barcode', 'mask_qr'];
        $defaultOrder = [];

        $stmt = $this->db->prepare("SELECT enabled_fields, order_fields FROM barcode_user_settings WHERE work_order = ? ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$workOrder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $enabled = array_filter(explode(',', (string)$row['enabled_fields']));
            $order = array_filter(explode(',', (string)$row['order_fields']));
            Response::success('success', [
                'enabled_fields' => array_values($enabled ?: $defaultEnabled),
                'order_fields'   => array_values($order)
            ]);
        } else {
            Response::success('success', [
                'enabled_fields' => $defaultEnabled,
                'order_fields'   => $defaultOrder
            ]);
        }
    }

    public function getMyPerm(): void
    {
        Response::success('success', [
            'perm'     => $this->perms,
            'is_admin' => $this->isAdmin ? 1 : 0,
        ]);
    }

    public function saveSettings(): void
    {
        // ★ 权限校验：验证字段设置
        if (empty($this->perms['can_save_settings'])) {
            Response::error('没有「验证设置」权限，请联系管理员开通', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $workOrder = trim((string)($input['work_order'] ?? ''));
            $enabled = trim((string)($input['enabled_fields'] ?? ''));
            $order = trim((string)($input['order_fields'] ?? ''));

            if (empty($workOrder)) {
                Response::error('工单号不能为空');
                return;
            }

            $userId = (int)($this->currentUser['id'] ?? 0);
            $stmt = $this->db->prepare("INSERT INTO barcode_user_settings (user_id, work_order, enabled_fields, order_fields, updated_at) 
                                  VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$userId, $workOrder, $enabled, $order]);

            AuditService::log('条码校验', '保存校验设置');
            Response::success('设置保存成功');
        } catch (Throwable $e) {
            Response::exception($e, '保存设置失败');
        }
    }

    public function getReferenceRows(): void
    {
        $workOrder = trim((string)($_REQUEST['work_order'] ?? ''));
        $page = max(1, (int)($_REQUEST['page'] ?? 1));
        $limit = max(1, (int)($_REQUEST['limit'] ?? 30));
        $offset = ($page - 1) * $limit;

        if (empty($workOrder)) {
            Response::success('success', ['rows' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0]);
            return;
        }

        $cnt = $this->db->prepare("SELECT COUNT(*) FROM barcode_reference WHERE work_order = ?");
        $cnt->execute([$workOrder]);
        $total = (int)$cnt->fetchColumn();
        $totalPages = (int)ceil($total / $limit);

        $stmt = $this->db->prepare("SELECT * FROM barcode_reference WHERE work_order = ? ORDER BY row_index ASC LIMIT {$offset}, {$limit}");
        $stmt->execute([$workOrder]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success('success', [
            'rows'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $totalPages
        ]);
    }

    public function deleteCompleted(): void
    {
        // ★ 权限校验：删除记录
        if (empty($this->perms['can_delete'])) {
            Response::error('没有「删除记录」权限，请联系管理员开通', [], 403);
            return;
        }
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            Response::error('请选择记录');
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM barcode_completed_rows WHERE id = ?");
        $stmt->execute([$id]);
        AuditService::log('条码校验', '删除已完成行');
        Response::success('删除成功');
    }

    /**
     * 编辑已完成记录（按权限控制）
     */
    public function updateCompleted(): void
    {
        // ★ 权限校验：编辑记录
        if (empty($this->perms['can_edit'])) {
            Response::error('没有「编辑记录」权限，请联系管理员开通', [], 403);
            return;
        }
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            Response::error('请选择记录');
            return;
        }

        $editable = ['sn', 'mod1', 'mod2', 'mod3', 'mod4', 'mod5', 'mask_barcode', 'mask_qr'];
        $sets = [];
        $params = [];
        foreach ($editable as $f) {
            if (array_key_exists($f, $input)) {
                $sets[] = "`{$f}` = ?";
                $params[] = trim((string)$input[$f]);
            }
        }
        if (empty($sets)) {
            Response::error('没有需要更新的字段');
            return;
        }
        $params[] = $id;

        try {
            $stmt = $this->db->prepare("UPDATE `barcode_completed_rows` SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($params);
            AuditService::log('条码校验', '修改已完成行');
            Response::success('记录已更新');
        } catch (Throwable $e) {
            Response::exception($e, '更新失败');
        }
    }

    public function exportCompleted(): void
    {
        try {
            $workOrder = trim((string)($_GET['work_order'] ?? ''));
            $search = trim((string)($_GET['search'] ?? ''));

            $where = ["1=1"];
            $params = [];

            if ($workOrder !== '') {
                $where[] = "work_order = ?";
                $params[] = $workOrder;
            }
            if ($search !== '') {
                $kw = "%" . $search . "%";
                $where[] = "(work_order LIKE ? OR sn LIKE ? OR mod1 LIKE ? OR mask_barcode LIKE ? OR operator LIKE ?)";
                for ($i = 0; $i < 5; $i++) $params[] = $kw;
            }

            $whereSql = implode(' AND ', $where);
            $stmt = $this->db->prepare("SELECT * FROM barcode_completed_rows WHERE {$whereSql} ORDER BY work_order ASC, row_index ASC");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $filenameWO = $workOrder ? $workOrder : '全部记录';
            $headers = ['工单号', '行号', '装置SN', '模组1', '模组2', '模组3', '模组4', '模组5', '面膜条码', '面膜二维码', '操作者', '完成时间'];

            // 优先导出 Excel（服务器已安装 PhpSpreadsheet）
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('条码比对已完成记录');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($rows as $r) {
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['work_order'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)($r['row_index'] + 1), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $line, (string)$r['sn'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $line, (string)$r['mod1'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('E' . $line, (string)$r['mod2'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('F' . $line, (string)$r['mod3'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('G' . $line, (string)$r['mod4'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('H' . $line, (string)$r['mod5'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('I' . $line, (string)$r['mask_barcode'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('J' . $line, (string)$r['mask_qr'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('K' . $line, (string)$r['operator'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('L' . $line, (string)$r['completed_at'], DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:L1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:L1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'L') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = '条码比对已完成记录_' . $filenameWO . '_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="条码比对已完成记录_' . $filenameWO . '_' . date('Ymd_His') . '.csv"');

            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, array_map('excelSafe', $headers));

            foreach ($rows as $r) {
                fputcsv($output, array_map('excelSafe', [
                    "\t" . $r['work_order'],
                    $r['row_index'] + 1,
                    "\t" . $r['sn'],
                    "\t" . $r['mod1'],
                    "\t" . $r['mod2'],
                    "\t" . $r['mod3'],
                    "\t" . $r['mod4'],
                    "\t" . $r['mod5'],
                    "\t" . $r['mask_barcode'],
                    "\t" . $r['mask_qr'],
                    $r['operator'],
                    $r['completed_at']
                ]));
            }

            fclose($output);
            exit;
        } catch (Throwable $e) {
            Response::exception($e, '导出失败');
        }
    }
}