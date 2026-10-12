<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use Exception;
use PDO;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class MacCalculator
{
    public static function hexAddressToNumber(string $hexMac): int
    {
        $hex = str_replace('-', '', $hexMac);
        return (int)hexdec($hex);
    }

    public static function numberToHexAddress(int $number): string
    {
        $hex = dechex($number);
        $hex = str_pad(strtoupper($hex), 12, '0', STR_PAD_LEFT);
        return implode('-', str_split($hex, 2));
    }

    public static function hexToDecimal(string $hexMac): string
    {
        $parts = explode('-', $hexMac);
        $decimalParts = [];
        foreach ($parts as $part) {
            $dec = hexdec($part);
            $decimalParts[] = str_pad((string)$dec, 3, '0', STR_PAD_LEFT);
        }
        return implode('-', $decimalParts);
    }

    public static function decimalToHex(string $decimalMac): string
    {
        $parts = explode('-', $decimalMac);
        $hexParts = [];
        foreach ($parts as $part) {
            $dec = (int)$part;
            $hex = dechex($dec);
            $hexParts[] = str_pad(strtoupper($hex), 2, '0', STR_PAD_LEFT);
        }
        return implode('-', $hexParts);
    }

    public static function macToNumber(string $mac): int
    {
        $mac = strtoupper(trim($mac));
        if (preg_match('/^\d{3}-\d{3}-\d{3}-\d{3}-\d{3}-\d{3}$/', $mac)) {
            $hexMac = self::decimalToHex($mac);
            return self::hexAddressToNumber($hexMac);
        }
        if (preg_match('/^[0-9A-F]{2}-[0-9A-F]{2}-[0-9A-F]{2}-[0-9A-F]{2}-[0-9A-F]{2}-[0-9A-F]{2}$/', $mac)) {
            return self::hexAddressToNumber($mac);
        }
        if (preg_match('/^[0-9A-F]{12}$/', $mac)) {
            return (int)hexdec($mac);
        }
        return 0;
    }

    public static function numberToMac(int $number, string $type): string
    {
        if ($type === 'decimal') {
            $hexAddress = self::numberToHexAddress($number);
            return self::hexToDecimal($hexAddress);
        }
        if ($type === 'hex') {
            return self::numberToHexAddress($number);
        }
        if ($type === 'switcher') {
            return str_pad(strtoupper(dechex($number)), 12, '0', STR_PAD_LEFT);
        }
        return '';
    }

    public static function calculateMacCount(string $startMac, string $endMac, string $type): int
    {
        $startNum = self::macToNumber($startMac);
        $endNum = self::macToNumber($endMac);
        return $endNum - $startNum + 1;
    }

    public static function getMacRange(string $startMac, int $count, string $type): array
    {
        $macs = [];
        $startNum = self::macToNumber($startMac);
        for ($i = 0; $i < $count; $i++) {
            $currentNum = $startNum + $i;
            $macs[] = self::numberToMac($currentNum, $type);
        }
        return $macs;
    }
}

class MacController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $macPerms;

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
        \App\Core\Schema::ensureOnce('mac', function () { $this->initTables(); });
        $this->macPerms = $this->getMacUserPerms();
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `mac_categories` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `name` varchar(50) NOT NULL,
              `type` enum('decimal','hex','switcher') NOT NULL DEFAULT 'decimal',
              `format_pattern` varchar(100) DEFAULT NULL,
              `total_count` int(11) DEFAULT 0,
              `used_count` int(11) DEFAULT 0,
              `current_start` varchar(100) DEFAULT NULL,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `mac_address_pool` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `category_id` int(11) NOT NULL,
              `mac_address` varchar(100) NOT NULL UNIQUE,
              `status` tinyint(1) DEFAULT 0 COMMENT '0:未使用, 1:已使用',
              `used_by_work_order` varchar(100) DEFAULT NULL,
              `used_at` datetime DEFAULT NULL,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_cat_status` (`category_id`, `status`),
              INDEX `idx_mac_address` (`mac_address`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `mac_application_records` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `work_order` varchar(100) NOT NULL,
              `category_id` int(11) NOT NULL,
              `category_name` varchar(50) NOT NULL,
              `order_quantity` int(11) NOT NULL,
              `port_count` int(11) NOT NULL,
              `total_mac_count` int(11) NOT NULL,
              `start_mac` varchar(100) NOT NULL,
              `end_mac` varchar(100) NOT NULL,
              `applicant_id` int(11) NOT NULL,
              `applicant_name` varchar(100) NOT NULL,
              `is_reapplied` tinyint(1) DEFAULT 0,
              `reapply_reason` text,
              `task_code` varchar(100) DEFAULT NULL,
              `description` text,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_work_order` (`work_order`),
              INDEX `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `mac_application_details` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `record_id` int(11) NOT NULL,
              `work_order` varchar(100) NOT NULL,
              `mac_address` varchar(100) NOT NULL,
              `sequence_no` int(11) NOT NULL,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_record_id` (`record_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `mac_import_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `category_id` int(11) NOT NULL,
              `category_name` varchar(50) NOT NULL,
              `start_mac` varchar(100) NOT NULL,
              `end_mac` varchar(100) DEFAULT NULL,
              `quantity` int(11) NOT NULL,
              `imported_by` int(11) NOT NULL,
              `imported_by_name` varchar(100) DEFAULT NULL,
              `imported_at` datetime DEFAULT CURRENT_TIMESTAMP,
              `remarks` text,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `product_port_configs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `product_code` varchar(100) NOT NULL UNIQUE COMMENT '产品代码',
              `description` text COMMENT '物料/产品描述',
              `port_count` int(11) NOT NULL DEFAULT 1 COMMENT '网口数量',
              `category_id` int(11) NOT NULL DEFAULT 0 COMMENT '产品类别ID',
              `updated_by` varchar(100) DEFAULT NULL,
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_product_code` (`product_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $portCols = $this->db->query("SHOW COLUMNS FROM product_port_configs")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('category_id', $portCols, true)) {
                $this->db->exec("ALTER TABLE `product_port_configs` ADD `category_id` int(11) NOT NULL DEFAULT 0 AFTER `port_count`");
            }

            $this->db->exec("CREATE TABLE IF NOT EXISTS `mac_permissions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL UNIQUE,
                `can_apply` tinyint(1) NOT NULL DEFAULT 1,
                `can_config_ports` tinyint(1) NOT NULL DEFAULT 0,
                `can_import` tinyint(1) NOT NULL DEFAULT 0,
                `can_view_records` tinyint(1) NOT NULL DEFAULT 1,
                `can_view_stats` tinyint(1) NOT NULL DEFAULT 1,
                `can_view_pool` tinyint(1) NOT NULL DEFAULT 0,
                `can_view_logs` tinyint(1) NOT NULL DEFAULT 0,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $catCnt = (int)$this->db->query("SELECT COUNT(*) FROM mac_categories")->fetchColumn();
            if ($catCnt === 0) {
                $this->db->exec("INSERT INTO `mac_categories` (`name`, `type`, `format_pattern`, `total_count`, `used_count`) VALUES
                    ('保护类', 'decimal', '000-014-234-000-000-000', 0, 0),
                    ('测控类', 'hex', '00-0E-EA-00-00-00', 0, 0),
                    ('SL200/DSA', 'decimal', '000-014-234-000-000-000', 0, 0),
                    ('交换机', 'switcher', '000EEA000000', 0, 0);");
            }
        } catch (Throwable $e) {}
    }

    private function getMacUserPerms(): array
    {
        if ($this->isAdmin) {
            return [
                'can_apply'        => 1,
                'can_config_ports' => 1,
                'can_import'       => 1,
                'can_view_records' => 1,
                'can_view_stats'   => 1,
                'can_view_pool'    => 1,
                'can_view_logs'    => 1,
                'is_admin'         => 1
            ];
        }

        $st = $this->db->prepare("SELECT can_apply, can_config_ports, can_import, can_view_records, can_view_stats, can_view_pool, can_view_logs FROM mac_permissions WHERE user_id = ? LIMIT 1");
        $st->execute([(int)$this->currentUser['id']]);
        $p = $st->fetch(PDO::FETCH_ASSOC);

        return [
            'can_apply'        => $p ? (int)$p['can_apply'] : 1,
            'can_config_ports' => $p ? (int)$p['can_config_ports'] : 0,
            'can_import'       => $p ? (int)$p['can_import'] : 0,
            'can_view_records' => $p ? (int)$p['can_view_records'] : 1,
            'can_view_stats'   => $p ? (int)$p['can_view_stats'] : 1,
            'can_view_pool'    => $p ? (int)$p['can_view_pool'] : 0,
            'can_view_logs'    => $p ? (int)$p['can_view_logs'] : 0,
            'is_admin'         => 0
        ];
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

    public function init(): void
    {
        try {
            $categories = $this->db->query("SELECT * FROM mac_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            Response::success('success', [
                'categories' => $categories,
                'mac_perms'  => $this->macPerms,
                'is_admin'   => $this->isAdmin
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '初始化失败');
        }
    }

    public function getWorkOrderInfo(): void
    {
        if (empty($this->macPerms['can_apply'])) {
            Response::error('无权操作', [], 403);
            return;
        }
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;
        $wo = trim((string)($input['work_order'] ?? ''));

        if (empty($wo)) {
            Response::error('工单号不能为空');
            return;
        }

        $chkStmt = $this->db->prepare("SELECT id, is_reapplied, created_at FROM mac_application_records WHERE work_order = ? ORDER BY id DESC LIMIT 1");
        $chkStmt->execute([$wo]);
        $appliedRecord = $chkStmt->fetch(PDO::FETCH_ASSOC);

        $sqlsrv = $this->getErpSqlSrvConnection();
        $orderInfo = null;
        $autoPortCount = 0;
        $autoCategoryId = 0;

        if ($sqlsrv) {
            try {
                $stmt = $sqlsrv->prepare("SELECT TOP 1 [任务] as work_order, [编码] as code, [描述] as description, [数量] as quantity FROM [J_全离散任务] WHERE ([任务] = ? OR [编码] = ?) AND [状态] <> '已取消'");
                $stmt->execute([$wo, $wo]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $code = trim((string)$row['code']);
                    $orderInfo = [
                        'work_order'  => trim((string)$row['work_order']),
                        'code'        => $code,
                        'description' => trim(mb_convert_encoding($row['description'] ?? '', 'UTF-8', 'UTF-8,GBK,GB2312')),
                        'quantity'    => (int)$row['quantity']
                    ];

                    if (!empty($code)) {
                        $pStmt = $this->db->prepare("SELECT port_count, category_id FROM product_port_configs WHERE product_code = ? LIMIT 1");
                        $pStmt->execute([$code]);
                        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
                        if ($pRow) {
                            $autoPortCount = (int)$pRow['port_count'];
                            $autoCategoryId = (int)$pRow['category_id'];
                        }
                    }
                }
            } catch (Throwable $e) {}
        }

        Response::success('success', [
            'applied'          => !empty($appliedRecord),
            'record'           => $appliedRecord,
            'order_info'       => $orderInfo,
            'auto_port_count'  => $autoPortCount,
            'auto_category_id' => $autoCategoryId
        ]);
    }

    public function getProductDesc(): void
    {
        if (empty($this->macPerms['can_config_ports'])) {
            Response::error('无权操作', [], 403);
            return;
        }
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;
        $code = trim((string)($input['product_code'] ?? ''));

        if (empty($code)) {
            Response::error('产品代码不能为空');
            return;
        }

        $description = '';
        $existingPort = 0;
        $existingCategory = 0;

        $locStmt = $this->db->prepare("SELECT description, port_count, category_id FROM product_port_configs WHERE product_code = ? LIMIT 1");
        $locStmt->execute([$code]);
        $locRow = $locStmt->fetch(PDO::FETCH_ASSOC);
        if ($locRow) {
            $description = (string)$locRow['description'];
            $existingPort = (int)$locRow['port_count'];
            $existingCategory = (int)$locRow['category_id'];
        }

        if (empty($description)) {
            $sqlsrv = $this->getErpSqlSrvConnection();
            if ($sqlsrv) {
                try {
                    $stmt = $sqlsrv->prepare("SELECT TOP 1 [描述] as description FROM [J_全离散任务] WHERE [编码] = ?");
                    $stmt->execute([$code]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $description = trim(mb_convert_encoding($row['description'] ?? '', 'UTF-8', 'UTF-8,GBK,GB2312'));
                    }
                } catch (Throwable $e) {}
            }
        }

        Response::success('success', [
            'product_code' => $code,
            'description'  => $description ?: '未检索到产品描述，可手动输入',
            'port_count'   => $existingPort,
            'category_id'  => $existingCategory
        ]);
    }

    public function listProductPorts(): void
    {
        if (empty($this->macPerms['can_config_ports'])) {
            Response::error('您没有查看或维护产品网口配置的权限！', [], 403);
            return;
        }
        try {
            $search = trim((string)($_GET['search'] ?? ''));
            $where = "1=1";
            $params = [];

            if ($search !== '') {
                $where .= " AND (p.product_code LIKE ? OR p.description LIKE ?)";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            $sql = "SELECT p.*, c.name as category_name 
                    FROM product_port_configs p 
                    LEFT JOIN mac_categories c ON p.category_id = c.id 
                    WHERE {$where} 
                    ORDER BY p.updated_at DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', ['list' => $list]);
        } catch (Throwable $e) {
            Response::exception($e, '读取列表失败');
        }
    }

    public function saveProductPort(): void
    {
        if (empty($this->macPerms['can_config_ports'])) {
            Response::error('您没有修改产品网口配置的权限！', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $code = trim((string)($input['product_code'] ?? ''));
            $desc = trim((string)($input['description'] ?? ''));
            $ports = (int)($input['port_count'] ?? 1);
            $categoryId = (int)($input['category_id'] ?? 0);

            if (empty($code)) {
                Response::error('产品代码不能为空');
                return;
            }
            if ($ports <= 0) {
                Response::error('网口数量必须大于0');
                return;
            }
            if ($categoryId <= 0) {
                Response::error('请选择产品类别');
                return;
            }

            $operatorName = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : $this->currentUser['username'];

            $stmt = $this->db->prepare("INSERT INTO product_port_configs (product_code, description, port_count, category_id, updated_by, updated_at)
                                  VALUES (?, ?, ?, ?, ?, NOW())
                                  ON DUPLICATE KEY UPDATE description = VALUES(description), port_count = VALUES(port_count), category_id = VALUES(category_id), updated_by = VALUES(updated_by), updated_at = NOW()");
            $stmt->execute([$code, $desc, $ports, $categoryId, $operatorName]);

            AuditService::log('MAC地址管理', '保存产品网口配置');
            Response::success('产品网口及类别配置已成功保存！');
        } catch (Throwable $e) {
            Response::exception($e, '保存配置失败');
        }
    }

    public function deleteProductPort(): void
    {
        if (empty($this->macPerms['can_config_ports'])) {
            Response::error('您没有删除产品网口配置的权限！', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $id = (int)($input['id'] ?? 0);

            if ($id <= 0) {
                Response::error('参数错误');
                return;
            }

            $stmt = $this->db->prepare("DELETE FROM product_port_configs WHERE id = ?");
            $stmt->execute([$id]);

            AuditService::log('MAC地址管理', '删除产品网口配置');
            Response::success('该产品网口配置已删除！');
        } catch (Throwable $e) {
            Response::exception($e, '删除失败');
        }
    }

    public function importMac(): void
    {
        if (empty($this->macPerms['can_import'])) {
            Response::error('您没有批量录入 MAC 地址的权限！', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $categoryId = (int)($input['category_id'] ?? 0);
            $startMac = strtoupper(trim((string)($input['start_mac'] ?? '')));
            $endMac = strtoupper(trim((string)($input['end_mac'] ?? '')));
            $quantity = (int)($input['quantity'] ?? 0);
            $remarks = trim((string)($input['remarks'] ?? ''));

            if (empty($startMac)) {
                Response::error('请输入起始 MAC 地址');
                return;
            }

            $stmt = $this->db->prepare("SELECT * FROM mac_categories WHERE id = ?");
            $stmt->execute([$categoryId]);
            $cat = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cat) {
                Response::error('所选分类不存在');
                return;
            }

            if (!empty($endMac)) {
                $count = MacCalculator::calculateMacCount($startMac, $endMac, $cat['type']);
                if ($count <= 0 || $count > 10000) {
                    Response::error('MAC 地址范围无效或单次超过 10000 条上限');
                    return;
                }
                $macList = MacCalculator::getMacRange($startMac, $count, $cat['type']);
            } elseif ($quantity > 0) {
                if ($quantity > 10000) {
                    Response::error('单次录入数量最大为 10000 条');
                    return;
                }
                $macList = MacCalculator::getMacRange($startMac, $quantity, $cat['type']);
            } else {
                Response::error('请输入终止地址或指定数量');
                return;
            }

            $this->db->beginTransaction();

            $insStmt = $this->db->prepare("INSERT IGNORE INTO mac_address_pool (category_id, mac_address, status) VALUES (?, ?, 0)");
            $added = 0;
            foreach ($macList as $mac) {
                $insStmt->execute([$categoryId, $mac]);
                if ($insStmt->rowCount() > 0) {
                    $added++;
                }
            }
            $skipped = count($macList) - $added;

            $this->db->prepare("UPDATE mac_categories SET total_count = total_count + ? WHERE id = ?")->execute([$added, $categoryId]);

            $operatorName = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : $this->currentUser['username'];
            $logEndMac = !empty($endMac) ? $endMac : end($macList);

            $this->db->prepare("INSERT INTO mac_import_logs (category_id, category_name, start_mac, end_mac, quantity, imported_by, imported_by_name, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$categoryId, $cat['name'], $startMac, $logEndMac, $added, (int)$this->currentUser['id'], $operatorName, $remarks]);

            $this->db->commit();
            AuditService::log('MAC地址管理', '导入MAC地址');
            Response::success("录入完成：成功添加 {$added} 个 MAC 地址，跳过 {$skipped} 个重复地址", [
                'added'   => $added,
                'skipped' => $skipped
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::exception($e, '录入失败');
        }
    }

    public function applyMac(): void
    {
        if (empty($this->macPerms['can_apply'])) {
            Response::error('您没有申请分配 MAC 地址的权限！', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $workOrder = trim((string)($input['work_order'] ?? ''));
            $categoryId = (int)($input['category_id'] ?? 0);
            $orderQuantity = (int)($input['order_quantity'] ?? 0);
            $portCount = (int)($input['port_count'] ?? 0);
            $isReapplied = !empty($input['is_reapplied']) ? 1 : 0;
            $reapplyReason = trim((string)($input['reapply_reason'] ?? ''));
            $taskCode = trim((string)($input['task_code'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));

            if (empty($workOrder) || !preg_match('/^\d{6,}$/', $workOrder)) {
                Response::error('工单号必须为纯数字且不少于6位');
                return;
            }
            if ($orderQuantity <= 0 || $portCount <= 0) {
                Response::error('工单数量和网口数量必须大于0');
                return;
            }

            $totalMacCount = $orderQuantity * $portCount;

            $this->db->beginTransaction();

            $cStmt = $this->db->prepare("SELECT * FROM mac_categories WHERE id = ?");
            $cStmt->execute([$categoryId]);
            $category = $cStmt->fetch(PDO::FETCH_ASSOC);

            if (!$category) {
                $this->db->rollBack();
                Response::error('所选分类不存在');
                return;
            }

            $available = (int)$category['total_count'] - (int)$category['used_count'];
            if ($available < $totalMacCount) {
                $this->db->rollBack();
                Response::error("可用 MAC 地址不足！当前可用: {$available}，本次需要: {$totalMacCount}");
                return;
            }

            $pStmt = $this->db->prepare("SELECT id, mac_address FROM mac_address_pool WHERE category_id = ? AND status = 0 ORDER BY id ASC LIMIT ?");
            $pStmt->bindValue(1, $categoryId, PDO::PARAM_INT);
            $pStmt->bindValue(2, $totalMacCount, PDO::PARAM_INT);
            $pStmt->execute();
            $macPoolRows = $pStmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($macPoolRows) < $totalMacCount) {
                $this->db->rollBack();
                Response::error('提取可用 MAC 地址失败，请检查对应分类地址池');
                return;
            }

            $startMac = $macPoolRows[0]['mac_address'];
            $endMac = end($macPoolRows)['mac_address'];
            $applicantName = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : $this->currentUser['username'];

            $insRecord = $this->db->prepare("INSERT INTO mac_application_records 
                (work_order, category_id, category_name, order_quantity, port_count, total_mac_count, start_mac, end_mac, applicant_id, applicant_name, is_reapplied, reapply_reason, task_code, description, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $insRecord->execute([
                $workOrder, $categoryId, $category['name'], $orderQuantity, $portCount, $totalMacCount,
                $startMac, $endMac, (int)$this->currentUser['id'], $applicantName, $isReapplied, $reapplyReason, $taskCode, $description
            ]);
            $recordId = (int)$this->db->lastInsertId();

            $detStmt = $this->db->prepare("INSERT INTO mac_application_details (record_id, work_order, mac_address, sequence_no, created_at) VALUES (?, ?, ?, ?, NOW())");
            $updPool = $this->db->prepare("UPDATE mac_address_pool SET status = 1, used_by_work_order = ?, used_at = NOW() WHERE id = ?");

            $seq = 1;
            foreach ($macPoolRows as $row) {
                $detStmt->execute([$recordId, $workOrder, $row['mac_address'], $seq++]);
                $updPool->execute([$workOrder, $row['id']]);
            }

            $this->db->prepare("UPDATE mac_categories SET used_count = used_count + ? WHERE id = ?")->execute([$totalMacCount, $categoryId]);

            $this->db->commit();
            AuditService::log('MAC地址管理', '申请分配MAC地址');
            Response::success("MAC 地址申请成功，共分配 {$totalMacCount} 个地址", [
                'record_id'   => $recordId,
                'total_count' => $totalMacCount,
                'start_mac'   => $startMac,
                'end_mac'     => $endMac
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::exception($e, '申请分配异常');
        }
    }

    public function getRecords(): void
    {
        if (empty($this->macPerms['can_view_records'])) {
            Response::error('您没有查看申请记录的权限！', [], 403);
            return;
        }
        try {
            $search = trim((string)($_GET['search'] ?? ($_POST['search'] ?? '')));
            $page = max(1, (int)($_GET['page'] ?? ($_POST['page'] ?? 1)));
            $perPage = 15;
            $offset = ($page - 1) * $perPage;

            $where = ["1=1"];
            $params = [];

            if ($search !== '') {
                $kw = "%{$search}%";
                $where[] = "(work_order LIKE ? OR task_code LIKE ? OR description LIKE ? OR category_name LIKE ? OR applicant_name LIKE ?)";
                for ($i = 0; $i < 5; $i++) $params[] = $kw;
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM mac_application_records WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($totalRecords / $perPage);

            $sql = "SELECT * FROM mac_application_records WHERE {$whereSql} ORDER BY created_at DESC LIMIT {$offset}, {$perPage}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'records'       => $records,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'current_page'  => $page
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取记录失败');
        }
    }

    public function getRecordDetails(): void
    {
        if (empty($this->macPerms['can_view_records'])) {
            Response::error('无权查看明细', [], 403);
            return;
        }
        try {
            $recordId = (int)($_GET['record_id'] ?? ($_POST['record_id'] ?? 0));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 50;
            $offset = ($page - 1) * $perPage;

            $rStmt = $this->db->prepare("SELECT * FROM mac_application_records WHERE id = ?");
            $rStmt->execute([$recordId]);
            $record = $rStmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                Response::error('申请记录不存在');
                return;
            }

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM mac_application_details WHERE record_id = ?");
            $cntStmt->execute([$recordId]);
            $total = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($total / $perPage);

            $stmt = $this->db->prepare("SELECT * FROM mac_application_details WHERE record_id = ? ORDER BY sequence_no ASC LIMIT {$offset}, {$perPage}");
            $stmt->execute([$recordId]);
            $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'record'      => $record,
                'details'     => $details,
                'total'       => $total,
                'total_pages' => $totalPages,
                'page'        => $page
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取明细失败');
        }
    }

    public function getStats(): void
    {
        if (empty($this->macPerms['can_view_stats'])) {
            Response::error('您没有查看库存统计看板的权限！', [], 403);
            return;
        }
        try {
            $sql = "SELECT c.*, (c.total_count - c.used_count) as available FROM mac_categories c ORDER BY c.id ASC";
            $stats = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            Response::success('success', ['stats' => $stats]);
        } catch (Throwable $e) {
            Response::exception($e, '获取库存统计失败');
        }
    }

    public function getPool(): void
    {
        if (empty($this->macPerms['can_view_pool'])) {
            Response::error('您没有查看全局地址池总表的权限！', [], 403);
            return;
        }
        try {
            $categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : 0;
            $status = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : -1;
            $search = trim((string)($_GET['search'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 25;
            $offset = ($page - 1) * $perPage;

            $where = ["1=1"];
            $params = [];

            if ($categoryId > 0) {
                $where[] = "p.category_id = ?";
                $params[] = $categoryId;
            }
            if ($status >= 0) {
                $where[] = "p.status = ?";
                $params[] = $status;
            }
            if ($search !== '') {
                $kw = "%{$search}%";
                $where[] = "(p.mac_address LIKE ? OR p.used_by_work_order LIKE ?)";
                $params[] = $kw;
                $params[] = $kw;
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM mac_address_pool p WHERE {$whereSql}");
            $cntStmt->execute($params);
            $total = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($total / $perPage);

            $sql = "SELECT p.*, c.name as category_name 
                    FROM mac_address_pool p 
                    LEFT JOIN mac_categories c ON p.category_id = c.id 
                    WHERE {$whereSql} 
                    ORDER BY p.id ASC 
                    LIMIT {$offset}, {$perPage}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'list'        => $list,
                'total'       => $total,
                'total_pages' => $totalPages,
                'page'        => $page
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取地址池失败');
        }
    }

    public function deleteRecords(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可删除申请记录并释放地址', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $ids = is_array($input['ids'] ?? null) ? $input['ids'] : array_filter(array_map('intval', explode(',', (string)($input['record_ids'] ?? ''))));

            if (empty($ids)) {
                Response::error('请选择要删除的申请记录');
                return;
            }

            $this->db->beginTransaction();
            $releasedCount = 0;
            foreach ($ids as $rid) {
                $rStmt = $this->db->prepare("SELECT category_id, total_mac_count FROM mac_application_records WHERE id = ?");
                $rStmt->execute([(int)$rid]);
                $rec = $rStmt->fetch(PDO::FETCH_ASSOC);

                if ($rec) {
                    $this->db->prepare("UPDATE mac_address_pool p 
                                  JOIN mac_application_details d ON p.mac_address = d.mac_address 
                                  SET p.status = 0, p.used_by_work_order = NULL, p.used_at = NULL 
                                  WHERE d.record_id = ?")->execute([(int)$rid]);

                    $this->db->prepare("UPDATE mac_categories SET used_count = GREATEST(0, used_count - ?) WHERE id = ?")
                       ->execute([(int)$rec['total_mac_count'], (int)$rec['category_id']]);

                    $this->db->prepare("DELETE FROM mac_application_details WHERE record_id = ?")->execute([(int)$rid]);
                    $this->db->prepare("DELETE FROM mac_application_records WHERE id = ?")->execute([(int)$rid]);

                    $releasedCount += (int)$rec['total_mac_count'];
                }
            }

            $this->db->commit();
            AuditService::log('MAC地址管理', '删除MAC记录');
            Response::success("删除成功，共释放 {$releasedCount} 个已分配 MAC 地址回池");
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::exception($e, '删除异常');
        }
    }

    public function getImportLogs(): void
    {
        if (empty($this->macPerms['can_view_logs'])) {
            Response::error('您没有查看录入日志的权限！', [], 403);
            return;
        }
        try {
            $search = trim((string)($_GET['search'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset = ($page - 1) * $perPage;

            $where = ["1=1"];
            $params = [];

            if ($search !== '') {
                $kw = "%{$search}%";
                $where[] = "(category_name LIKE ? OR start_mac LIKE ? OR end_mac LIKE ? OR imported_by_name LIKE ? OR remarks LIKE ?)";
                for ($i = 0; $i < 5; $i++) $params[] = $kw;
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM mac_import_logs WHERE {$whereSql}");
            $cntStmt->execute($params);
            $total = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($total / $perPage);

            $sql = "SELECT * FROM mac_import_logs WHERE {$whereSql} ORDER BY imported_at DESC LIMIT {$offset}, {$perPage}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'logs'        => $logs,
                'total'       => $total,
                'total_pages' => $totalPages,
                'page'        => $page
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取日志失败');
        }
    }

    public function exportDetails(): void
    {
        if (empty($this->macPerms['can_view_records'])) {
            Response::error('无权导出', [], 403);
            return;
        }
        try {
            $recordId = (int)($_GET['record_id'] ?? 0);
            $rStmt = $this->db->prepare("SELECT * FROM mac_application_records WHERE id = ?");
            $rStmt->execute([$recordId]);
            $record = $rStmt->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                Response::error('记录不存在');
                return;
            }

            $stmt = $this->db->prepare("SELECT sequence_no, mac_address, created_at FROM mac_application_details WHERE record_id = ? ORDER BY sequence_no ASC");
            $stmt->execute([$recordId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 优先导出 Excel（服务器已安装 PhpSpreadsheet）
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('MAC分配明细');

                $sheet->setCellValueExplicit('A1', '工单号', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B1', (string)$record['work_order'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('A2', '分类', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B2', (string)$record['category_name'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('A3', '工单数量', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B3', (string)$record['order_quantity'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('A4', '网口数量', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B4', (string)$record['port_count'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('A5', 'MAC总数', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B5', (string)$record['total_mac_count'], DataType::TYPE_STRING);

                $sheet->setCellValueExplicit('A7', '序号', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B7', 'MAC地址', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('C7', '分配时间', DataType::TYPE_STRING);

                $line = 8;
                foreach ($rows as $r) {
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['sequence_no'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)$r['mac_address'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $line, (string)$r['created_at'], DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:B1')->getFont()->setBold(true);
                $sheet->getStyle('A7:C7')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A7:C7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'C') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = 'MAC_Detail_' . $record['work_order'] . '_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="MAC_Detail_' . $record['work_order'] . '_' . date('Ymd_His') . '.csv"');

            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, array_map('excelSafe', ['工单号', $record['work_order']]));
            fputcsv($output, array_map('excelSafe', ['分类', $record['category_name']]));
            fputcsv($output, array_map('excelSafe', ['工单数量', $record['order_quantity']]));
            fputcsv($output, array_map('excelSafe', ['网口数量', $record['port_count']]));
            fputcsv($output, array_map('excelSafe', ['MAC总数', $record['total_mac_count']]));
            fputcsv($output, array_map('excelSafe', []));
            fputcsv($output, array_map('excelSafe', ['序号', 'MAC地址', '分配时间']));

            foreach ($rows as $r) {
                fputcsv($output, array_map('excelSafe', [$r['sequence_no'], "\t" . $r['mac_address'], $r['created_at']]));
            }
            fclose($output);
            exit;
        } catch (Throwable $e) {
            Response::error('导出失败');
        }
    }
}