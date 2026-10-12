<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PDO;
use Throwable;

class QualityController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private string $uploadDir;
    private array $userPerm;

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
        $this->isAdmin = (!empty($this->currentUser['is_admin']) or $uName === 'admin');
        $this->uploadDir = __DIR__ . '/../../public/uploads/quality/';
        \App\Core\Schema::ensureOnce('quality', function () { $this->initTables(); });
        $this->userPerm = $this->resolveUserPerm();
    }

    /**
     * ★ 权限加固（L2）：读取当前用户的质量问题模块权限。
     *   未配置时：可查看 = 1、可录入 = 0（默认关闭录入，需管理员在权限设置中授权）。
     */
    private function resolveUserPerm(): array
    {
        if ($this->isAdmin) {
            return ['can_view_records' => 1, 'can_input' => 1];
        }
        try {
            $stmt = $this->db->prepare("SELECT can_view_records, can_input FROM `quality_permissions` WHERE user_id = ? LIMIT 1");
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
            $this->db->exec("CREATE TABLE IF NOT EXISTS `quality_issues` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `batch_no` VARCHAR(50) NOT NULL COMMENT '批次号',
                `work_order` VARCHAR(100) DEFAULT NULL COMMENT '工单号',
                `task_code` VARCHAR(100) DEFAULT NULL,
                `description` TEXT,
                `sn` VARCHAR(50) NOT NULL,
                `process` VARCHAR(50) DEFAULT NULL COMMENT '发现工序',
                `issue_description` TEXT NOT NULL,
                `image_path` VARCHAR(255) DEFAULT NULL COMMENT '图片路径',
                `outsourced_unit` VARCHAR(255) DEFAULT NULL COMMENT '外协单位',
                `created_by` INT(11) NOT NULL,
                `created_by_name` VARCHAR(100) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT NULL,
                `is_deleted` TINYINT(1) DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_batch_no` (`batch_no`),
                KEY `idx_sn` (`sn`),
                KEY `idx_created_by` (`created_by`),
                KEY `idx_is_deleted` (`is_deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            if (!is_dir($this->uploadDir)) {
                @mkdir($this->uploadDir, 0755, true);
            }

            // ★ 权限加固（L2）：质量问题模块细粒度权限表
            $this->db->exec("CREATE TABLE IF NOT EXISTS `quality_permissions` (
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
            // ★ 凭据一律取自 .env（无源码兜底），配置缺失直接拒绝连接
            $cfg = \App\Core\ExternalDb::sqlsrvConfig();
            if ($cfg === null) return null;
            [$sqlsrv_host, $sqlsrv_port, $sqlsrv_db, $sqlsrv_user, $sqlsrv_pass] = $cfg;

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ];
            $dsn = "dblib:host={$sqlsrv_host}:{$sqlsrv_port};dbname={$sqlsrv_db};charset=UTF-8";
            $pdo = new PDO($dsn, $sqlsrv_user, $sqlsrv_pass, $options);
            $pdo->exec("SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; SET ANSI_PADDING ON; SET CONCAT_NULL_YIELDS_NULL ON; SET QUOTED_IDENTIFIER ON;");
            return $pdo;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function handleImageUpload(?array $file): ?string
    {
        if (!$file or !isset($file['error']) or $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed, true)) {
            return null;
        }

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if ($ext === '') $ext = 'jpg';
        $filename = 'issue_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $this->uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $target)) {
            return '/uploads/quality/' . $filename;
        }
        return null;
    }

    private function findOutsourcedUnit(string $workOrder): string
    {
        if ($workOrder === '') return '';
        try {
            $stmt = $this->db->prepare("SELECT vendor FROM osm_records WHERE work_order = ? LIMIT 1");
            $stmt->execute([$workOrder]);
            $v = $stmt->fetchColumn();
            if ($v) return trim((string)$v);
        } catch (Throwable $e) {}
        return '';
    }

    public function getBatchInfo(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;

        $sn = trim((string)($input['sn'] ?? ''));
        if (strlen($sn) < 16) {
            Response::error('SN 号长度不足 16 位');
            return;
        }

        $snPrefix = substr($sn, 0, 16);
        $sqlsrv = $this->getErpSqlSrvConnection();

        if ($sqlsrv) {
            try {
                $stmt = $sqlsrv->prepare("SELECT TOP 1 [任务] as work_order, [编码] as task_code, [描述] as description 
                                          FROM [J_全离散任务] 
                                          WHERE [批号] LIKE ? AND [状态] <> '已取消'");
                $stmt->execute(["%{$snPrefix}%"]);
                $row = $stmt->fetch();

                if ($row) {
                    $desc = trim(mb_convert_encoding((string)($row['description'] ?? ''), 'UTF-8', 'UTF-8,GBK,GB2312'));
                    Response::success('success', [
                        'found'       => true,
                        'batch_no'    => $snPrefix,
                        'work_order'  => trim((string)$row['work_order']),
                        'task_code'   => trim((string)$row['task_code']),
                        'description' => $desc
                    ]);
                    return;
                }
            } catch (Throwable $e) {}
        }

        try {
            $stmt =$this->db->prepare("SELECT task_order, parent_code, description FROM trace_tasks WHERE batch_prefix = ? OR batch_no LIKE ? LIMIT 1");
            $stmt->execute([$snPrefix, "%{$snPrefix}%"]);
            $local =$stmt->fetch();
            if ($local) {
                Response::success('success', [
                    'found'       => true,
                    'batch_no'    => $snPrefix,
                    'work_order'  => trim((string)$local['task_order']),
                    'task_code'   => trim((string)$local['parent_code']),
                    'description' => trim((string)$local['description'])
                ]);
                return;
            }
        } catch (Throwable $e) {}

        Response::success('未找到该 SN 对应的批次工单信息', ['found' => false]);
    }

    public function list(): void
    {
        try {
            $workOrder = trim((string)($_GET['work_order'] ?? ''));$sn        = trim((string)($_GET['sn'] ?? ''));$process   = trim((string)($_GET['process'] ?? ''));$page      = max(1, (int)($_GET['page'] ?? 1));$perPage   = 30;
            $offset    = ($page - 1) *$perPage;

            $where = ["qi.is_deleted = 0"];
            $params = [];

            if ($workOrder !== '') {$where[] = "qi.work_order LIKE ?";
                $params[] = "%{$workOrder}%";
            }
            if ($sn !== '') {$where[] = "qi.sn LIKE ?";
                $params[] = "%{$sn}%";
            }
            if ($process !== '') {$where[] = "qi.process = ?";
                $params[] =$process;
            }

            $whereSql = implode(' AND ',$where);

            $cntStmt =$this->db->prepare("SELECT COUNT(*) FROM quality_issues qi WHERE {$whereSql}");
            $cntStmt->execute($params);$totalRecords = (int)$cntStmt->fetchColumn();$totalPages = (int)ceil($totalRecords / $perPage);

            // 优先关联工号：LEFT JOIN users u_user ON qi.created_by_name = u_user.username
            $sql = "SELECT qi.*, 
                           COALESCE(NULLIF(u_user.full_name, ''), u_user.username, NULLIF(u_id.full_name, ''), u_id.username, qi.created_by_name, '未知用户') AS display_name
                    FROM quality_issues qi 
                    LEFT JOIN users u_user ON qi.created_by_name = u_user.username
                    LEFT JOIN users u_id ON qi.created_by = u_id.id
                    WHERE {$whereSql} 
                    ORDER BY qi.created_at DESC 
                    LIMIT {$offset}, {$perPage}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $records =$stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'records'       => $records,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'current_page'  => $page,
                'is_admin'      => $this->isAdmin,
                'user_perm'     => $this->userPerm
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取记录失败');
        }
    }

    public function add(): void
    {
        // ★ 权限加固（L2）：录入权限校验（默认未授权者不可录入）
        if (!$this->isAdmin && empty($this->userPerm['can_input'])) {
            Response::error('您没有录入质量问题的权限，请联系管理员开通', [], 403);
            return;
        }
        try {
            $input = array_merge($_GET,$_POST, $_REQUEST);$raw = file_get_contents('php://input');
            if (!empty($raw)) {
                $jsonData = json_decode($raw, true);
                if (is_array($jsonData)) {$input = array_merge($jsonData,$input);
                }
            }

            $type             = trim((string)($input['type'] ?? 'single'));
            $batch_no         = trim((string)($input['batch_no'] ?? ''));
            $work_order       = trim((string)($input['work_order'] ?? ''));
            $task_code        = trim((string)($input['task_code'] ?? ''));
            $description      = trim((string)($input['description'] ?? ''));
            $process          = trim((string)($input['process'] ?? ''));
            $issue_description = trim((string)($input['issue_description'] ?? ''));

            if ($process === '') {
                Response::error('请选择发现工序');
                return;
            }
            if ($issue_description === '') {
                Response::error('请填写问题现象描述');
                return;
            }

            $outsourced_unit =$this->findOutsourcedUnit($work_order);$imagePath = $this->handleImageUpload($_FILES['image'] ?? null);

            // 核心修复：存入当前用户确切登录工号 username
            $creatorUsername = (string)($this->currentUser['username'] ?? '');

            if ($type === 'single') {
                $sn = trim((string)($input['sn'] ?? ''));
                if (!preg_match('/^\d{22}$/',$sn)) {
                    Response::error('SN 号必须为 22 位纯数字');
                    return;
                }

                $sql = "INSERT INTO quality_issues (batch_no, work_order, task_code, description, sn, process, issue_description, image_path, outsourced_unit, created_by, created_by_name, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$batch_no, $work_order,$task_code, $description,$sn, $process,$issue_description, $imagePath,$outsourced_unit, (int)$this->currentUser['id'],$creatorUsername
                ]);

                AuditService::log('质量问题记录', "录入单台 SN 质量问题: {$sn}");
                Response::success('质量问题记录添加成功');
            } else {
                $snListRaw =$input['sn_list'] ?? '[]';
                $sn_list = is_array($snListRaw) ? $snListRaw : (json_decode((string)$snListRaw, true) ?: []);

                if (empty($sn_list)) {
                    Response::error('请至少录入一个有效的 SN 号');
                    return;
                }

                $sql = "INSERT INTO quality_issues (batch_no, work_order, task_code, description, sn, process, issue_description, image_path, outsourced_unit, created_by, created_by_name, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $this->db->prepare($sql);

                $count = 0;
                $this->db->beginTransaction();
                foreach ($sn_list as$snItem) {
                    $snVal = trim((string)$snItem);
                    if (!preg_match('/^\d{22}$/',$snVal)) continue;
                    $stmt->execute([$batch_no, $work_order,$task_code, $description,$snVal, $process,$issue_description, $imagePath,$outsourced_unit, (int)$this->currentUser['id'],$creatorUsername
                    ]);
                    $count++;
                }
                $this->db->commit();

                AuditService::log('质量问题记录', "批量录入质量问题记录 {$count} 条");
                Response::success("批量添加成功，共计录入 {$count} 条记录");
            }
        } catch (Throwable $e) {
            if ($this->db->inTransaction())$this->db->rollBack();
            Response::exception($e, '添加失败');
        }
    }

    public function update(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可修改问题描述', [], 403);
            return;
        }

        try {
            $input = array_merge($_GET,$_POST, $_REQUEST);$raw = file_get_contents('php://input');
            if (!empty($raw)) {
                $jsonData = json_decode($raw, true);
                if (is_array($jsonData))$input = array_merge($jsonData,$input);
            }

            $id                = (int)($input['id'] ?? 0);
            $issue_description = trim((string)($input['issue_description'] ?? ''));
            $process           = trim((string)($input['process'] ?? ''));

            if ($id <= 0 or$issue_description === '') {
                Response::error('参数不完整');
                return;
            }

            $imagePath = $this->handleImageUpload($_FILES['image'] ?? null);

            if ($imagePath) {$sql = "UPDATE quality_issues SET issue_description = ?, process = ?, image_path = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0";
                $stmt =$this->db->prepare($sql);$stmt->execute([$issue_description,$process, $imagePath,$id]);
            } else {
                $sql = "UPDATE quality_issues SET issue_description = ?, process = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$issue_description, $process,$id]);
            }

            AuditService::log('质量问题记录', "修改质量记录 ID: {$id}");
            Response::success('修改成功');
        } catch (Throwable $e) {
            Response::exception($e, '修改异常');
        }
    }

    public function delete(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可删除记录', [], 403);
            return;
        }

        try {
            $raw = file_get_contents('php://input');$input = json_decode($raw, true) ?:$_POST;
            $ids =$input['ids'] ?? [];

            if (empty($ids) or !is_array($ids)) {
                Response::error('请选择要删除的记录');
                return;
            }

            $ids = array_map('intval', $ids);$placeholders = implode(',', array_fill(0, count($ids), '?'));$sql = "UPDATE quality_issues SET is_deleted = 1, updated_at = NOW() WHERE id IN ($placeholders)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($ids);
            $count =$stmt->rowCount();

            AuditService::log('质量问题记录', "删除质量记录数量: {$count}");
            Response::success("成功删除 {$count} 条质量记录", ['count' => $count]);
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }

    public function export(): void
    {
        // ★ 权限加固（L2）：导出按查看权限控制
        if (!$this->isAdmin && empty($this->userPerm['can_view_records'])) {
            Response::error('您没有导出质量问题的权限', [], 403);
            return;
        }
        try {
            $selectedIdsRaw = trim((string)($_GET['selected_ids'] ?? ''));$where = ["qi.is_deleted = 0"];
            $params = [];

            if (!empty($selectedIdsRaw)) {
                $ids = array_filter(array_map('intval', explode(',',$selectedIdsRaw)));
                if (!empty($ids)) {$placeholders = implode(',', array_fill(0, count($ids), '?'));$where[] = "qi.id IN ($placeholders)";
                    $params =$ids;
                }
            }

            $whereSql = implode(' AND ', $where);$sql = "SELECT qi.*, 
                           COALESCE(NULLIF(u_user.full_name, ''), u_user.username, NULLIF(u_id.full_name, ''), u_id.username, qi.created_by_name, '未知用户') AS display_name
                    FROM quality_issues qi 
                    LEFT JOIN users u_user ON qi.created_by_name = u_user.username
                    LEFT JOIN users u_id ON qi.created_by = u_id.id
                    WHERE {$whereSql} 
                    ORDER BY qi.created_at DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows =$stmt->fetchAll(PDO::FETCH_ASSOC);

            $headers = ['ID', '工单号', '编码', '描述', 'SN号', '发现工序', '问题描述', '外协单位', '填写人(工号/姓名)', '填写时间'];

            // 优先导出 Excel（服务器已安装 PhpSpreadsheet）
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('质量问题统计');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($rows as $r) {
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['id'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)($r['work_order'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $line, (string)($r['task_code'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $line, (string)($r['description'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('E' . $line, (string)$r['sn'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('F' . $line, (string)($r['process'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('G' . $line, (string)$r['issue_description'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('H' . $line, (string)($r['outsourced_unit'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('I' . $line, (string)$r['display_name'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('J' . $line, (string)$r['created_at'], DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:J1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'J') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = '质量问题统计导出_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="质量问题统计导出_' . date('Ymd_His') . '.csv"');

            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, array_map('excelSafe', $headers));

            foreach ($rows as$r) {
                fputcsv($output, array_map('excelSafe', [
                    $r['id'],$r['work_order'] ?: '-',
                    $r['task_code'] ?: '-',$r['description'] ?: '-',
                    "\t" . $r['sn'],$r['process'] ?: '-',
                    $r['issue_description'],$r['outsourced_unit'] ?: '-',
                    $r['display_name'],$r['created_at']
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
                                      LEFT JOIN `quality_permissions` p ON u.id = p.user_id
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

            $stmt = $this->db->prepare("INSERT INTO `quality_permissions` (user_id, can_view_records, can_input)
                                        VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE
                                        can_view_records = VALUES(can_view_records), can_input = VALUES(can_input)");
            $stmt->execute([$targetId, $canView, $canInput]);

            Response::success('权限保存成功');
        } catch (Throwable $e) {
            Response::exception($e, '保存失败');
        }
    }
}