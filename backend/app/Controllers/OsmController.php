<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PDO;
use Throwable;

class OsmController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $osmPerms;

    public function __construct()
    {
        $this->db = Database::getConnection();

        // ★ 安全修复：会话缺失时不再自动以数据库中的管理员身份登录，必须重新登录
        if (empty($_SESSION['user'])) {
            Response::error('登录已失效，请重新登录', ['need_login' => true], 401);
            exit;
        }

        $this->currentUser = $_SESSION['user'];
        $this->isAdmin = !empty($this->currentUser['is_admin']) || ($this->currentUser['username'] ?? '') === 'admin';
        \App\Core\Schema::ensureOnce('osm', function () { $this->initTables(); });
        $this->osmPerms = $this->resolveOsmPermissions();
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `osm_records` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `work_order` varchar(50) NOT NULL,
                `product_code` varchar(50) DEFAULT '',
                `description` text,
                `vendor` varchar(100) NOT NULL DEFAULT '',
                `quantity` int(11) NOT NULL DEFAULT 1,
                `returned_quantity` int(11) NOT NULL DEFAULT 0,
                `status` varchar(20) NOT NULL DEFAULT '未返回',
                `send_date` date NOT NULL,
                `required_return_date` date DEFAULT NULL,
                `remarks` text,
                `created_by` int(11) NOT NULL DEFAULT 0,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_work_order` (`work_order`),
                INDEX `idx_vendor` (`vendor`),
                INDEX `idx_send_date` (`send_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 表结构自愈检测：补充缺失的字段
            $cols = $this->db->query("SHOW COLUMNS FROM `osm_records`")->fetchAll(PDO::FETCH_COLUMN);

            if (!in_array('vendor', $cols, true)) {
                $this->db->exec("ALTER TABLE `osm_records` ADD `vendor` varchar(100) NOT NULL DEFAULT '' AFTER `description`");
            }
            if (!in_array('returned_quantity', $cols, true)) {
                $this->db->exec("ALTER TABLE `osm_records` ADD `returned_quantity` int(11) NOT NULL DEFAULT 0 AFTER `quantity`");
            }
            if (!in_array('status', $cols, true)) {
                $this->db->exec("ALTER TABLE `osm_records` ADD `status` varchar(20) NOT NULL DEFAULT '未返回' AFTER `returned_quantity`");
            }
            if (!in_array('required_return_date', $cols, true)) {
                $this->db->exec("ALTER TABLE `osm_records` ADD `required_return_date` date DEFAULT NULL AFTER `send_date`");
            }
            if (!in_array('product_code', $cols, true)) {
                $this->db->exec("ALTER TABLE `osm_records` ADD `product_code` varchar(50) DEFAULT '' AFTER `work_order`");
            }

            $this->db->exec("CREATE TABLE IF NOT EXISTS `osm_returns` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `osm_id` int(11) NOT NULL,
                `return_quantity` int(11) NOT NULL,
                `return_date` date NOT NULL,
                `remarks` text,
                `created_by` int(11) NOT NULL DEFAULT 0,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_osm_id` (`osm_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `osm_permissions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL UNIQUE,
                `can_edit` tinyint(1) NOT NULL DEFAULT 0,
                `can_delete` tinyint(1) NOT NULL DEFAULT 0,
                `can_upload` tinyint(1) NOT NULL DEFAULT 0,
                `can_add_return` tinyint(1) NOT NULL DEFAULT 0,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Throwable $e) {}
    }

    private function resolveOsmPermissions(): array
    {
        if ($this->isAdmin) {
            return [
                'can_edit'       => 1,
                'can_delete'     => 1,
                'can_upload'     => 1,
                'can_add_return' => 1,
                'is_admin'       => 1
            ];
        }

        try {
            $st = $this->db->prepare("SELECT can_edit, can_delete, can_upload, can_add_return FROM osm_permissions WHERE user_id = ? LIMIT 1");
            $st->execute([(int)$this->currentUser['id']]);
            $p =$st->fetch(PDO::FETCH_ASSOC);
            return [
                'can_edit'       => $p ? (int)$p['can_edit'] : 0,
                'can_delete'     =>$p ? (int)$p['can_delete'] : 0,
                'can_upload'     =>$p ? (int)$p['can_upload'] : 0,
                'can_add_return' =>$p ? (int)$p['can_add_return'] : 0,
                'is_admin'       => 0
            ];
        } catch (Throwable$e) {
            return [
                'can_edit'       => 0,
                'can_delete'     => 0,
                'can_upload'     => 0,
                'can_add_return' => 0,
                'is_admin'       => 0
            ];
        }
    }

    public function init(): void
    {
        try {
            $vendors =$this->db->query("SELECT DISTINCT vendor FROM osm_records WHERE vendor IS NOT NULL AND vendor != '' ORDER BY vendor ASC")->fetchAll(PDO::FETCH_COLUMN);
            Response::success('success', [
                'osm_perms' => $this->osmPerms,
                'vendors'   => $vendors,
                'is_admin'  => $this->isAdmin
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '初始化失败');
        }
    }

    public function list(): void
    {
        try {
            $start   = trim((string)($_GET['start_date'] ?? ''));$end     = trim((string)($_GET['end_date'] ?? ''));$vendor  = trim((string)($_GET['vendor'] ?? ''));$status  = trim((string)($_GET['status'] ?? ''));$search  = trim((string)($_GET['search'] ?? ''));$page    = max(1, (int)($_GET['page'] ?? 1));$perPage = 30;
            $offset  = ($page - 1) *$perPage;

            $where  = ["1=1"];
            $params = [];

            if ($start !== '') {$where[]  = "send_date >= ?";
                $params[] =$start;
            }
            if ($end !== '') {$where[]  = "send_date <= ?";
                $params[] =$end;
            }
            if ($vendor !== '') {$where[]  = "vendor = ?";
                $params[] =$vendor;
            }
            if ($status !== '') {$where[]  = "status = ?";
                $params[] =$status;
            }
            if ($search !== '') {
                $kw       = "%{$search}%";
                $where[]  = "(work_order LIKE ? OR product_code LIKE ? OR description LIKE ? OR remarks LIKE ?)";
                for ($i = 0; $i < 4; $i++) {
                    $params[] =$kw;
                }
            }

            $whereSql = implode(' AND ',$where);

            $cntStmt =$this->db->prepare("SELECT COUNT(*) FROM osm_records WHERE {$whereSql}");
            $cntStmt->execute($params);$totalRecords = (int)$cntStmt->fetchColumn();$totalPages   = (int)ceil($totalRecords / $perPage);

            $sql  = "SELECT * FROM osm_records WHERE {$whereSql} ORDER BY send_date DESC, id DESC LIMIT {$offset}, {$perPage}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $records =$stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'records'       => $records,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'current_page'  => $page,
                'is_admin'      => $this->isAdmin,
                'osm_perms'     => $this->osmPerms
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取记录失败');
        }
    }

    public function save(): void
    {
        if (!$this->osmPerms['can_edit'] && !$this->isAdmin) {
            Response::error('权限受限：您没有编辑外协记录的权限！', [], 403);
            return;
        }

        try {
            $raw   = file_get_contents('php://input');$input = json_decode($raw, true) ?:$_POST;

            $id            = (int)($input['id'] ?? 0);$wo            = trim((string)($input['work_order'] ?? ''));$code          = trim((string)($input['product_code'] ?? ''));$desc          = trim((string)($input['description'] ?? ''));$vendor        = trim((string)($input['vendor'] ?? ''));$qty           = (int)($input['quantity'] ?? 1);$sendDate      = trim((string)($input['send_date'] ?? ''));$reqReturnDate = !empty($input['required_return_date']) ? trim((string)$input['required_return_date']) : null;
            $remarks       = trim((string)($input['remarks'] ?? ''));

            if (empty($wo) || empty($vendor) || empty($sendDate) ||$qty <= 0) {
                Response::error('工单号、外协厂家、发货数量和发货日期为必填项');
                return;
            }

            if ($id > 0) {
                $stmt =$this->db->prepare("UPDATE osm_records SET work_order=?, product_code=?, description=?, vendor=?, quantity=?, send_date=?, required_return_date=?, remarks=? WHERE id=?");
                $stmt->execute([$wo, $code,$desc, $vendor,$qty, $sendDate,$reqReturnDate, $remarks,$id]);
                AuditService::log('外协管理', "修改外协记录 [{$wo} / ID:{$id}]");
                Response::success('外协记录修改成功！');
            } else {
                $stmt =$this->db->prepare("INSERT INTO osm_records (work_order, product_code, description, vendor, quantity, send_date, required_return_date, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$wo, $code,$desc, $vendor,$qty, $sendDate,$reqReturnDate, $remarks, (int)$this->currentUser['id']]);
                $newId = (int)$this->db->lastInsertId();
                AuditService::log('外协管理', "新增外协记录 [{$wo} / ID:{$newId}]");
                Response::success('外协记录添加成功！', ['id' => $newId]);
            }
        } catch (Throwable $e) {
            Response::exception($e, '保存失败');
        }
    }

    public function delete(): void
    {
        if (!$this->osmPerms['can_delete'] && !$this->isAdmin) {
            Response::error('权限受限：您没有删除外协记录的权限！', [], 403);
            return;
        }

        try {
            $raw   = file_get_contents('php://input');$input = json_decode($raw, true) ?:$_POST;
            $ids   =$input['ids'] ?? [];

            if (empty($ids) || !is_array($ids)) {
                Response::error('请选择要删除的记录');
                return;
            }

            $ids = array_map('intval',$ids);
            $ph  = implode(',', array_fill(0, count($ids), '?'));

            $this->db->beginTransaction();$this->db->prepare("DELETE FROM osm_returns WHERE osm_id IN ($ph)")->execute($ids);
            $stmt =$this->db->prepare("DELETE FROM osm_records WHERE id IN ($ph)");
            $stmt->execute($ids);$count = $stmt->rowCount();$this->db->commit();

            AuditService::log('外协管理', "删除 {$count} 条外协记录");
            Response::success("成功删除 {$count} 条外协记录！");
        } catch (Throwable $e) {
            if ($this->db->inTransaction())$this->db->rollBack();
            Response::exception($e, '删除异常');
        }
    }

    public function getPendingReturns(): void
    {
        try {
            $stmt =$this->db->query("SELECT id, work_order, product_code, description, vendor, quantity, returned_quantity, (quantity - returned_quantity) as pending_qty 
                                FROM osm_records 
                                WHERE status != '全部返回' 
                                ORDER BY send_date ASC");
            $list =$stmt->fetchAll(PDO::FETCH_ASSOC);
            Response::success('success', ['list' => $list]);
        } catch (Throwable $e) {
            Response::exception($e, '获取待返回批次失败');
        }
    }

    public function addReturn(): void
    {
        if (!$this->osmPerms['can_add_return'] && !$this->isAdmin) {
            Response::error('权限受限：您没有登记外协返回的权限！', [], 403);
            return;
        }

        try {
            $raw   = file_get_contents('php://input');$input = json_decode($raw, true) ?:$_POST;

            $osmId      = (int)($input['osm_id'] ?? 0);
            $returnQty  = (int)($input['return_quantity'] ?? 0);
            $returnDate = trim((string)($input['return_date'] ?? ''));
            $remarks    = trim((string)($input['remarks'] ?? ''));

            if ($osmId <= 0 || $returnQty <= 0 || empty($returnDate)) {
                Response::error('请选择外协批次、填写有效返回数量与返回日期');
                return;
            }

            $this->db->beginTransaction();

            $st =$this->db->prepare("SELECT work_order, quantity, returned_quantity FROM osm_records WHERE id = ? FOR UPDATE");
            $st->execute([$osmId]);
            $rec =$st->fetch(PDO::FETCH_ASSOC);

            if (!$rec) {$this->db->rollBack();
                Response::error('外协记录不存在');
                return;
            }

            $totalQty  = (int)$rec['quantity'];$newRetQty = (int)$rec['returned_quantity'] +$returnQty;

            if ($newRetQty > $totalQty) {$this->db->rollBack();
                Response::error("累计返回数量 ({$newRetQty}) 超出发货总数 ({$totalQty})！");
                return;
            }

            $newStatus = ($newRetQty >=$totalQty) ? '全部返回' : '部分返回';

            $this->db->prepare("INSERT INTO osm_returns (osm_id, return_quantity, return_date, remarks, created_by) VALUES (?, ?, ?, ?, ?)")
               ->execute([$osmId, $returnQty,$returnDate, $remarks, (int)$this->currentUser['id']]);

            $this->db->prepare("UPDATE osm_records SET returned_quantity = ?, status = ? WHERE id = ?")
               ->execute([$newRetQty, $newStatus,$osmId]);

            $this->db->commit();
            AuditService::log('外协管理', "工单 [{$rec['work_order']}] 办理返回 {$returnQty} 件");
            Response::success('外协返回登记成功！');
        } catch (Throwable $e) {
            if ($this->db->inTransaction())$this->db->rollBack();
            Response::exception($e, '登记异常');
        }
    }

    public function uploadOsm(): void
    {
        if (!$this->osmPerms['can_upload'] && !$this->isAdmin) {
            Response::error('权限受限：您没有批量导入外协数据的权限！', [], 403);
            return;
        }

        if (!isset($_FILES['file']) ||$_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('文件上传失败，请选择有效的 Excel 或 CSV 文件');
            return;
        }

        $tmpFile =$_FILES['file']['tmp_name'];
        $ext = strtolower((string)pathinfo((string)($_FILES['file']['name'] ?? ''), PATHINFO_EXTENSION));

        $matrix = [];
        if (in_array($ext, ['xlsx', 'xls'], true) && class_exists(Spreadsheet::class)) {
            $spreadsheet = IOFactory::load($tmpFile);
            $matrix = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } else {
            if (($handle = fopen($tmpFile, 'r')) === false) {
                Response::error('无法打开上传文件');
                return;
            }
            while (($row = fgetcsv($handle)) !== false) {
                $matrix[] = $row;
            }
            fclose($handle);
            if (!empty($matrix) && isset($matrix[0][0]) && str_starts_with((string)$matrix[0][0], "\xEF\xBB\xBF")) {
                $matrix[0][0] = substr((string)$matrix[0][0], 3);
            }
        }

        if (empty($matrix)) {
            Response::error('文件内容为空');
            return;
        }

        $header = array_shift($matrix);

        $this->db->beginTransaction();$added = 0;
        $stmt  =$this->db->prepare("INSERT INTO osm_records (work_order, product_code, description, vendor, quantity, send_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");

        foreach ($matrix as $row) {
            if (!is_array($row) || count($row) < 4) continue;
            $row      = array_map(static fn($v) => trim((string)$v), $row);
            $wo       = trim((string)($row[0] ?? ''));$code     = trim((string)($row[1] ?? ''));$desc     = trim((string)($row[2] ?? ''));$vendor   = trim((string)($row[3] ?? ''));$qty      = max(1, (int)($row[4] ?? 1));$sendDate = !empty($row[5]) ? trim((string)$row[5]) : date('Y-m-d');

            if (!empty($wo) && !empty($vendor)) {$stmt->execute([$wo,$code, $desc,$vendor, $qty,$sendDate, (int)$this->currentUser['id']]);$added++;
            }
        }

        $this->db->commit();
        AuditService::log('外协管理', "批量导入 {$added} 条外协记录");
        Response::success("批量导入成功，共写入 {$added} 条外协记录！");
    }

    public function export(): void
    {
        try {
            $search = trim((string)($_GET['search'] ?? ''));
            $vendor = trim((string)($_GET['vendor'] ?? ''));
            $status = trim((string)($_GET['status'] ?? ''));

            $where  = ["1=1"];
            $params = [];
            if ($vendor !== '') {$where[]  = "vendor = ?";
                $params[] =$vendor;
            }
            if ($status !== '') {$where[]  = "status = ?";
                $params[] =$status;
            }
            if ($search !== '') {
                $kw       = "%{$search}%";
                $where[]  = "(work_order LIKE ? OR product_code LIKE ? OR description LIKE ? OR remarks LIKE ?)";
                for ($i = 0; $i < 4; $i++) {
                    $params[] =$kw;
                }
            }

            $whereSql = implode(' AND ',$where);
            $stmt     =$this->db->prepare("SELECT * FROM osm_records WHERE {$whereSql} ORDER BY send_date DESC");
            $stmt->execute($params);
            $rows =$stmt->fetchAll(PDO::FETCH_ASSOC);

            $headers = ['工单号', '产品代码', '物料描述', '外协厂家', '发货数量', '已返回数量', '状态', '发货日期', '要求返回日期', '备注'];

            // 优先导出 Excel（服务器已安装 PhpSpreadsheet）
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('外协管理');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($rows as $r) {
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['work_order'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)$r['product_code'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $line, (string)$r['description'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $line, (string)$r['vendor'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('E' . $line, (string)$r['quantity'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('F' . $line, (string)$r['returned_quantity'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('G' . $line, (string)$r['status'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('H' . $line, (string)$r['send_date'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('I' . $line, (string)($r['required_return_date'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('J' . $line, (string)($r['remarks'] ?: '-'), DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:J1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'J') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = '外协管理导出_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="外协管理导出_' . date('Ymd_His') . '.csv"');

            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, array_map('excelSafe', $headers));

            foreach ($rows as$r) {
                fputcsv($output, array_map('excelSafe', [
                    "\t" . $r['work_order'],
                    "\t" . $r['product_code'],
                    $r['description'],$r['vendor'],
                    $r['quantity'],$r['returned_quantity'],
                    $r['status'],$r['send_date'],
                    $r['required_return_date'] ?: '-',$r['remarks'] ?: '-'
                ]));
            }
            fclose($output);
            exit;
        } catch (Throwable $e) {
            Response::exception($e, '导出失败');
        }
    }

    public function listPermissions(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可访问权限设置', [], 403);
            return;
        }

        try {
            $sql = "SELECT u.id as user_id, u.username, u.full_name, 
                           COALESCE(p.can_edit, 0) as can_edit,
                           COALESCE(p.can_delete, 0) as can_delete,
                           COALESCE(p.can_upload, 0) as can_upload,
                           COALESCE(p.can_add_return, 0) as can_add_return
                    FROM `users` u
                    LEFT JOIN `osm_permissions` p ON u.id = p.user_id
                    ORDER BY u.id ASC";
            $list = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            Response::success('success', ['list' => $list]);
        } catch (Throwable $e) {
            Response::exception($e, '获取权限列表失败');
        }
    }

    public function savePermissions(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可以保存权限', [], 403);
            return;
        }

        try {
            $raw   = file_get_contents('php://input');$input = json_decode($raw, true) ?:$_POST;
            $perms =$input['permissions'] ?? [];

            $stmt =$this->db->prepare("INSERT INTO `osm_permissions` (`user_id`, `can_edit`, `can_delete`, `can_upload`, `can_add_return`, `updated_at`) 
                                  VALUES (?, ?, ?, ?, ?, NOW()) 
                                  ON DUPLICATE KEY UPDATE 
                                    `can_edit` = VALUES(`can_edit`),
                                    `can_delete` = VALUES(`can_delete`),
                                    `can_upload` = VALUES(`can_upload`),
                                    `can_add_return` = VALUES(`can_add_return`),
                                    `updated_at` = NOW()");

            foreach ($perms as$p) {
                $uid = (int)($p['user_id'] ?? 0);
                if ($uid > 0) {
                    $stmt->execute([$uid,
                        !empty($p['can_edit']) ? 1 : 0,                         !empty($p['can_delete']) ? 1 : 0,
                        !empty($p['can_upload']) ? 1 : 0,                         !empty($p['can_add_return']) ? 1 : 0
                    ]);
                }
            }

            AuditService::log('外协管理', '更新外协细分用户权限配置');
            Response::success('外协系统权限保存成功！');
        } catch (Throwable $e) {
            Response::exception($e, '保存权限异常');
        }
    }
}