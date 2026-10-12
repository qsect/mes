<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use Exception;
use PDO;
use Throwable;

class LabelPrintController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private string $printApiUrl;

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
        
        // 从环境变量读取 BarTender 打印服务 API 地址
        $this->printApiUrl = getenv('PRINT_API_URL') ?: ($_ENV['PRINT_API_URL'] ?? 'http://172.18.7.24:5000');
        
        \App\Core\Schema::ensureOnce('label_print', function () { $this->initTables(); });
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `print_records` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `order_number` varchar(100) NOT NULL,
                `template_code` varchar(100) NOT NULL,
                `serial_number` varchar(100) NOT NULL,
                `template_path` text,
                `quantity` int(11) DEFAULT 1,
                `print_data` text,
                `printed_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `printed_by` varchar(100) NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_order_number` (`order_number`),
                INDEX `idx_printed_at` (`printed_at`)
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

    public function queryOrder(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;
        $orderNumber = trim((string)($input['order_number'] ?? ''));

        if (empty($orderNumber)) {
            Response::error('请输入工单号');
            return;
        }

        $sqlsrv = $this->getErpSqlSrvConnection();
        if (!$sqlsrv) {
            Response::error('SQL Server 数据源未连接，无法查询工单');
            return;
        }

        try {
            $stmt = $sqlsrv->prepare("
                SELECT TOP 1 [任务] as task, [编码] as code, [描述] as description, [数量] as quantity, [批号] as batch_number
                FROM [J_全离散任务] 
                WHERE ([任务] = ? OR [编码] = ?) AND [状态] <> '已取消'
                ORDER BY [任务] DESC
            ");
            $stmt->execute([$orderNumber, $orderNumber]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                Response::error("错误：工单 {$orderNumber} 不存在或状态已取消。");
                return;
            }

            $productCode = trim((string)$order['code']);
            $description = trim(mb_convert_encoding((string)($order['description'] ?? ''), 'UTF-8', 'UTF-8,GBK,GB2312'));
            $batchNumber = trim((string)($order['batch_number'] ?? ''));
            $quantity    = (int)($order['quantity'] ?? 1);
            if ($quantity <= 0) $quantity = 1;

            // 动态判定模板规则代码
            $taskCode = $productCode;
            if ((str_starts_with($productCode, '6002') || str_starts_with($productCode, '6003')) 
                && stripos($description, 'FTU-Z') === false) {
                $taskCode = '板卡模板';
            } elseif (str_starts_with($productCode, '7006') && stripos($description, 'FTU-Z') !== false) {
                $taskCode = '罩式FTU';
            }

            // 请求 Windows 打印服务获取模板列表
            $listUrl = $this->printApiUrl . "/list_templates?task_code=" . urlencode($taskCode);
            $ch = curl_init($listUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Connection: close']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $templates = [];
            if ($httpCode === 200 && $response) {
                $templates = json_decode((string)$response, true) ?: [];
            }

            $chkStmt = $this->db->prepare("SELECT COUNT(*) FROM print_records WHERE order_number = ?");
            $chkStmt->execute([$orderNumber]);
            $hasPrinted = (int)$chkStmt->fetchColumn() > 0;

            Response::success('success', [
                'order_number'        => trim((string)$order['task']),
                'product_code'        => $productCode,
                'description'         => $description,
                'batch_number'        => $batchNumber ?: $orderNumber,
                'quantity'            => $quantity,
                'task_code'           => $taskCode,
                'templates'           => $templates,
                'has_printed'         => $hasPrinted,
                'print_server_online' => ($httpCode === 200)
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '查询工单异常');
        }
    }

    public function executePrint(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $orderNumber  = trim((string)($input['order_number'] ?? ''));
            $templatePath = trim((string)($input['template_path'] ?? ''));
            $productCode  = trim((string)($input['product_code'] ?? ''));
            $startSerial  = trim((string)($input['start_serial'] ?? ''));
            $quantity     = (int)($input['quantity'] ?? 1);
            $forcePrint   = !empty($input['force_print']);

            if (empty($orderNumber) || empty($templatePath)) {
                Response::error('工单号和模板路径不能为空');
                return;
            }

            if (empty($startSerial)) {
                $startSerial = $orderNumber;
            }
            if ($quantity <= 0) {
                $quantity = 1;
            }

            // 检查历史打印记录
            $chkStmt = $this->db->prepare("SELECT COUNT(*) FROM print_records WHERE order_number = ?");
            $chkStmt->execute([$orderNumber]);
            $hasPrinted = (int)$chkStmt->fetchColumn() > 0;

            if ($hasPrinted && !$forcePrint) {
                Response::success('has_printed_warning', [
                    'need_confirm' => true,
                    'message'      => "工单【{$orderNumber}】已打印过。重新打印将覆盖记录，是否确认继续？"
                ]);
                return;
            }

            $payloadData = [
                'template_path' => $templatePath,
                'start_serial'  => (string)$startSerial,
                'quantity'      => (int)$quantity
            ];

            $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE);

            $ch = curl_init($this->printApiUrl . "/print");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Connection: close'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && !$curlError) {
                $result = json_decode((string)$response, true);
                if ($result && isset($result['success']) && $result['success'] === true) {
                    if ($forcePrint) {
                        $delStmt = $this->db->prepare("DELETE FROM print_records WHERE order_number = ?");
                        $delStmt->execute([$orderNumber]);
                    }

                    $operator = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : $this->currentUser['username'];
                    $printData = json_encode([
                        'template'     => $templatePath,
                        'start_serial' => $startSerial,
                        'quantity'     => $quantity
                    ], JSON_UNESCAPED_UNICODE);

                    $insStmt = $this->db->prepare("INSERT INTO print_records (order_number, template_code, serial_number, template_path, quantity, print_data, printed_at, printed_by) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)");
                    $insStmt->execute([$orderNumber, $productCode, $startSerial, $templatePath, $quantity, $printData, $operator]);

                    AuditService::log('标签打印', '执行标签打印');
                    Response::success('打印任务已成功发送，BarTender 正在打印，请检查出纸。', [
                        'order_number' => $orderNumber,
                        'start_serial' => $startSerial,
                        'quantity'     => $quantity
                    ]);
                } else {
                    Response::error('打印客户端响应错误: ' . ($result['message'] ?? 'BarTender 启动或打印异常'));
                }
            } else {
                Response::error("连接打印客户端失败 (HTTP {$httpCode})" . ($curlError ? "：{$curlError}" : "，请确认打印客户端已启动并点击【启动服务】"));
            }
        } catch (Throwable $e) {
            Response::exception($e, '打印执行异常');
        }
    }

    public function listHistory(): void
    {
        try {
            $search = trim((string)($_GET['search'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 30;
            $offset = ($page - 1) * $perPage;

            $where = ["1=1"];
            $params = [];

            if ($search !== '') {
                $kw = "%{$search}%";
                $where[] = "(order_number LIKE ? OR template_code LIKE ? OR serial_number LIKE ? OR printed_by LIKE ?)";
                $params = [$kw, $kw, $kw, $kw];
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM print_records WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($totalRecords / $perPage);

            $sql = "SELECT * FROM print_records WHERE {$whereSql} ORDER BY printed_at DESC LIMIT {$offset}, {$perPage}";
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
            Response::exception($e, '获取历史打印记录失败');
        }
    }
}