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

class TroubleshootController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $userPerm;

    public function __construct()
    {
        $this->db = Database::getConnection();

        if (empty($_SESSION['user'])) {
            Response::error('请先登录', [], 401);
            exit;
        }

        $this->currentUser = $_SESSION['user'];
        $uName = (string)($this->currentUser['username'] ?? '');
        $this->isAdmin = (!empty($this->currentUser['is_admin']) or $uName === 'admin');

        \App\Core\Schema::ensureOnce('troubleshoot', function () { $this->initTables(); });
        $this->userPerm = $this->resolveUserPerm();
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `troubleshoot_records` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `work_order` varchar(100) DEFAULT NULL,
                `task_code` varchar(100) DEFAULT NULL,
                `description` text,
                `sn` varchar(50) NOT NULL,
                `issue_description` text NOT NULL,
                `operator_id` int(11) NOT NULL,
                `operator_name` varchar(100) NOT NULL,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT NULL,
                `is_deleted` tinyint(1) DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_sn` (`sn`),
                KEY `idx_work_order` (`work_order`),
                KEY `idx_operator_id` (`operator_id`),
                KEY `idx_is_deleted` (`is_deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `module15_permissions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `can_view_records` tinyint(1) DEFAULT 1,
                `can_input` tinyint(1) DEFAULT 1,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Throwable $e) {}
    }

    private function resolveUserPerm(): array
    {
        if ($this->isAdmin) {
            return ['can_view_records' => 1, 'can_input' => 1];
        }
        try {
            $stmt = $this->db->prepare("SELECT can_view_records, can_input FROM module15_permissions WHERE user_id = ?");
            $stmt->execute([(int)$this->currentUser['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'can_view_records' => (int)$row['can_view_records'],
                    'can_input'        => (int)$row['can_input']
                ];
            }
        } catch (Throwable $e) {}
        // ★ 权限加固（L2）：未配置权限时默认「不可录入」（查看仍默认开放）
        return ['can_view_records' => 1, 'can_input' => 0];
    }

    private function parseTroubleshootIssue(string $text): array
    {
        $result = [
            'location'          => '',
            'base_issue'        => '',
            'conclusion'        => '合格',
            'defect_phenomenon' => ''
        ];
        if ($text === '') return $result;

        if (preg_match('/^\[([^\]]+)\]\s*/', $text, $matches)) {
            $result['location'] = $matches[1];
            $text = substr($text, strlen($matches[0]));
        }
        if (preg_match('/\[合格\]$/', $text)) {
            $result['conclusion'] = '合格';
            $text = preg_replace('/\s*\[合格\]$/', '', $text);
        } elseif (preg_match('/\[不合格现象:\s*([^\]]+)\]$/', $text, $matches)) {
            $result['conclusion'] = '不合格';
            $result['defect_phenomenon'] = $matches[1];
            $text = preg_replace('/\s*\[不合格现象:\s*[^\]]+\]$/', '', $text);
        } elseif (preg_match('/\[不合格\]$/', $text)) {
            $result['conclusion'] = '不合格';
            $text = preg_replace('/\s*\[不合格\]$/', '', $text);
        }
        $result['base_issue'] = trim((string)$text);
        return $result;
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

    public function getBatchInfo(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;

        $sn = trim((string)($input['sn'] ?? ''));
        if (strlen($sn) !== 22) {
            Response::error('SN 号必须为 22 位');
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
                        'work_order'  => trim((string)$row['work_order']),
                        'task_code'   => trim((string)$row['task_code']),
                        'description' => $desc
                    ]);
                    return;
                }
            } catch (Throwable $e) {}
        }

        try {
            $stmt = $this->db->prepare("SELECT task_order, parent_code, description FROM trace_tasks WHERE batch_prefix = ? OR batch_no LIKE ? LIMIT 1");
            $stmt->execute([$snPrefix, "%{$snPrefix}%"]);
            $local = $stmt->fetch();
            if ($local) {
                Response::success('success', [
                    'found'       => true,
                    'work_order'  => trim((string)$local['task_order']),
                    'task_code'   => trim((string)$local['parent_code']),
                    'description' => trim((string)$local['description'])
                ]);
                return;
            }
        } catch (Throwable $e) {}

        Response::success('未找到对应工单信息', ['found' => false]);
    }

    public function list(): void
    {
        try {
            if (!$this->isAdmin and !$this->userPerm['can_view_records']) {
                Response::error('您没有查看排查记录的权限', [], 403);
                return;
            }

            $keyword = trim((string)($_GET['keyword'] ?? ''));
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 30;
            $offset  = ($page - 1) * $perPage;

            $where = ["r.is_deleted = 0"];
            $params = [];

            if ($keyword !== '') {
                $search = "%{$keyword}%";
                // 优先以工号 (u.username) 以及对应姓名 (u.full_name) 进行检索
                $where[] = "(r.work_order LIKE ? OR r.task_code LIKE ? OR r.description LIKE ? OR r.sn LIKE ? OR r.issue_description LIKE ? OR COALESCE(u_user.full_name, u_user.username, u_id.full_name, u_id.username, r.operator_name) LIKE ?)";
                for ($i = 0; $i < 6; $i++) {
                    $params[] = $search;
                }
            }

            $whereSql = implode(' AND ', $where);

            // 同时 LEFT JOIN 按工号匹配 (u_user) 与按 ID 匹配 (u_id)，以工号对应的用户优先
            $cntStmt = $this->db->prepare("SELECT COUNT(*) 
                                           FROM troubleshoot_records r 
                                           LEFT JOIN users u_user ON r.operator_name = u_user.username
                                           LEFT JOIN users u_id ON r.operator_id = u_id.id
                                           WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages   = (int)ceil($totalRecords / $perPage);

            $sql = "SELECT r.*, 
                           COALESCE(NULLIF(u_user.full_name, ''), u_user.username, NULLIF(u_id.full_name, ''), u_id.username, r.operator_name, '未知用户') AS display_operator 
                    FROM troubleshoot_records r
                    LEFT JOIN users u_user ON r.operator_name = u_user.username
                    LEFT JOIN users u_id ON r.operator_id = u_id.id
                    WHERE {$whereSql} 
                    ORDER BY r.created_at DESC 
                    LIMIT {$offset}, {$perPage}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($records as &$rec) {
                $parsed = $this->parseTroubleshootIssue((string)$rec['issue_description']);
                $rec['parsed_location']   = $parsed['location'];
                $rec['parsed_conclusion'] = $parsed['conclusion'];
                $rec['parsed_defect']     = $parsed['defect_phenomenon'];
                $rec['parsed_base_issue'] = $parsed['base_issue'];
            }
            unset($rec);

            Response::success('success', [
                'records'       => $records,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'current_page'  => $page,
                'is_admin'      => $this->isAdmin,
                'user_perm'     => $this->userPerm
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取排查列表失败');
        }
    }

    public function add(): void
    {
        try {
            if (!$this->isAdmin and !$this->userPerm['can_input']) {
                Response::error('您没有录入排查记录的权限', [], 403);
                return;
            }

            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: array_merge($_GET, $_POST);

            $sn                = trim((string)($input['sn'] ?? ''));
            $issue_description = trim((string)($input['issue_description'] ?? ''));
            $location          = trim((string)($input['location'] ?? ''));
            $conclusion        = trim((string)($input['conclusion'] ?? '合格'));
            $defect_phenomenon = trim((string)($input['defect_phenomenon'] ?? ''));
            $work_order        = trim((string)($input['work_order'] ?? ''));
            $task_code         = trim((string)($input['task_code'] ?? ''));
            $description       = trim((string)($input['description'] ?? ''));

            if ($sn === '' or $issue_description === '') {
                Response::error('SN 号和排查问题说明不能为空');
                return;
            }
            if (strlen($sn) !== 22 or !preg_match('/^\d+$/', $sn)) {
                Response::error('SN 号必须为 22 位纯数字');
                return;
            }

            $final_description = $issue_description;
            if ($location !== '') {
                $final_description = "[{$location}] " . $final_description;
            }
            if ($conclusion === '不合格' and $defect_phenomenon !== '') {
                $final_description .= " [不合格现象: " . $defect_phenomenon . "]";
            } elseif ($conclusion === '合格') {
                $final_description .= " [合格]";
            } else {
                $final_description .= " [" . $conclusion . "]";
            }

            $chk = $this->db->prepare("SELECT id FROM troubleshoot_records WHERE sn = ? AND issue_description = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE) LIMIT 1");
            $chk->execute([$sn, $final_description]);
            if ($chk->fetch()) {
                Response::error('该 SN 在 1 分钟内已录入过相同内容，请勿重复提交');
                return;
            }

            // 核心修复：工号与用户名保持精准绑定，存入登录工号 username
            $operatorUsername = (string)($this->currentUser['username'] ?? '');

            $sql = "INSERT INTO troubleshoot_records (work_order, task_code, description, sn, issue_description, operator_id, operator_name, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$work_order, $task_code, $description, $sn, $final_description, (int)$this->currentUser['id'], $operatorUsername]);

            AuditService::log('问题排查', "录入排查记录 [SN:{$sn} / 工号:{$operatorUsername}]");
            Response::success('排查记录添加成功');
        } catch (Throwable $e) {
            Response::exception($e, '添加失败');
        }
    }

    public function delete(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可删除记录', [], 403);
            return;
        }

        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $ids = array_map('intval', $input['ids'] ?? []);

            if (empty($ids)) {
                Response::error('请选择要删除的记录');
                return;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE troubleshoot_records SET is_deleted = 1, updated_at = NOW() WHERE id IN ($placeholders)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($ids);
            $count = $stmt->rowCount();

            AuditService::log('问题排查', "删除排查记录 {$count} 条");
            Response::success("成功删除 {$count} 条排查记录", ['count' => $count]);
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }

    public function getPermissions(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可访问权限设置', [], 403);
            return;
        }

        try {
            $stmt = $this->db->query("SELECT u.id, u.username, u.full_name, u.is_admin, 
                                            COALESCE(p.can_view_records, 1) AS can_view_records, 
                                            COALESCE(p.can_input, 1) AS can_input 
                                     FROM users u 
                                     LEFT JOIN module15_permissions p ON u.id = p.user_id 
                                     ORDER BY u.id ASC");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            Response::success('success', ['users' => $users]);
        } catch (Throwable $e) {
            Response::exception($e, '获取权限列表失败');
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
            $canView  = !empty($input['can_view_records']) ? 1 : 0;
            $canInput = !empty($input['can_input']) ? 1 : 0;

            if ($targetId <= 0) {
                Response::error('无效用户');
                return;
            }

            $stmt = $this->db->prepare("INSERT INTO module15_permissions (user_id, can_view_records, can_input) 
                                        VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE 
                                        can_view_records = VALUES(can_view_records), can_input = VALUES(can_input)");
            $stmt->execute([$targetId, $canView, $canInput]);

            AuditService::log('问题排查', "更新用户权限 [ID:{$targetId}]");
            Response::success('权限保存成功');
        } catch (Throwable $e) {
            Response::exception($e, '保存失败');
        }
    }

    public function export(): void
    {
        try {
            if (!$this->isAdmin and !$this->userPerm['can_view_records']) {
                Response::error('无导出权限', [], 403);
                return;
            }

            $keyword = trim((string)($_GET['keyword'] ?? ''));
            $where = ["r.is_deleted = 0"];
            $params = [];

            if ($keyword !== '') {
                $search = "%{$keyword}%";
                $where[] = "(r.work_order LIKE ? OR r.task_code LIKE ? OR r.description LIKE ? OR r.sn LIKE ? OR r.issue_description LIKE ? OR COALESCE(u_user.full_name, u_user.username, u_id.full_name, u_id.username, r.operator_name) LIKE ?)";
                for ($i = 0; $i < 6; $i++) {
                    $params[] = $search;
                }
            }

            $whereSql = implode(' AND ', $where);
            $sql = "SELECT r.*, 
                           COALESCE(NULLIF(u_user.full_name, ''), u_user.username, NULLIF(u_id.full_name, ''), u_id.username, r.operator_name, '未知用户') AS display_operator 
                    FROM troubleshoot_records r
                    LEFT JOIN users u_user ON r.operator_name = u_user.username
                    LEFT JOIN users u_id ON r.operator_id = u_id.id
                    WHERE {$whereSql} 
                    ORDER BY r.created_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $headers = ['ID', '工单号', '代码', '描述', 'SN号', '排查地点', '排查问题说明', '排查结论', '问题现象', '排查人(工号/姓名)', '排查时间'];

            // 优先导出 Excel（服务器已安装 PhpSpreadsheet）
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('问题排查记录');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($rows as $r) {
                    $parsed = $this->parseTroubleshootIssue((string)$r['issue_description']);
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['id'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)($r['work_order'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $line, (string)($r['task_code'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $line, (string)($r['description'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('E' . $line, (string)$r['sn'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('F' . $line, (string)($parsed['location'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('G' . $line, (string)($parsed['base_issue'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('H' . $line, (string)($parsed['conclusion'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('I' . $line, (string)($parsed['defect_phenomenon'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('J' . $line, (string)$r['display_operator'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('K' . $line, (string)$r['created_at'], DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:K1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:K1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'K') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = '问题排查记录导出_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="问题排查记录导出_' . date('Ymd_His') . '.csv"');

            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, array_map('excelSafe', $headers));

            foreach ($rows as $r) {
                $parsed = $this->parseTroubleshootIssue((string)$r['issue_description']);
                fputcsv($output, array_map('excelSafe', [
                    $r['id'],
                    $r['work_order'] ?: '-',
                    $r['task_code'] ?: '-',
                    $r['description'] ?: '-',
                    "\t" . $r['sn'],
                    $parsed['location'] ?: '-',
                    $parsed['base_issue'] ?: '-',
                    $parsed['conclusion'] ?: '-',
                    $parsed['defect_phenomenon'] ?: '-',
                    $r['display_operator'],
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