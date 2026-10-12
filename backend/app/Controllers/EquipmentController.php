<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\ModulePerm;
use App\Core\Response;
use App\Services\AuditService;
use PDO;
use PDOException;
use Throwable;

class EquipmentController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $globalProcessOrder;

    public function __construct()
    {
        $this->db = Database::getConnection('default');
        $this->currentUser = function_exists('checkUser') ? checkUser() : ($_SESSION['user'] ?? []);
        $username = (string)($this->currentUser['username'] ?? '');
        $this->isAdmin = !empty($this->currentUser['is_admin']) || $username === 'admin';

        $this->globalProcessOrder = [
            '板卡接线', '组件装配', '装置装配', '线缆加工', 'QC检查', '板卡成品入库调试',
            '板卡程序烧录', '板卡调试', '保护功能', '参数设置', '大电流测试', '功能测试',
            '绝缘耐压测试', '拷机前调试', '校准', '载波调试', '阻抗测试', 'ATS测试',
            '装置调试（非标类工单）', '包装'
        ];

        \App\Core\Schema::ensureOnce('equipment', function () { $this->initTables(); });
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `equipment` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(50) NOT NULL UNIQUE,
                `name` varchar(100) NOT NULL,
                `product_series` varchar(50) NOT NULL,
                `description` varchar(255) DEFAULT '',
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `equipment_processes` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `equipment_id` int(11) NOT NULL,
                `process_code` varchar(100) NOT NULL,
                `efficiency` decimal(6,2) DEFAULT NULL,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_equipment` (`equipment_id`),
                UNIQUE KEY `uk_equip_proc` (`equipment_id`, `process_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Throwable $e) {}
    }

    private function getMesConnection(): ?PDO
    {
        try {
            // ★ 凭据一律取自 .env（无源码兜底），配置缺失直接拒绝连接
            $cfg = \App\Core\ExternalDb::mesConfig();
            if ($cfg === null) return null;
            [$mes_host, $mes_port, $mes_db, $mes_user, $mes_pass] = $cfg;

            $dsn = "mysql:host={$mes_host};port={$mes_port};dbname={$mes_db};charset=utf8mb4";
            return new PDO($dsn, $mes_user, $mes_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5
            ]);
        } catch (PDOException $e) {
            return null;
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

    public function init(): void
    {
        // ★ 与 saveEquipment/deleteEquipment 的鉴权口径保持一致，
        //   前端据此直接隐藏「添加/编辑/删除」按钮，避免"点了才 403"。
        $canManage = ModulePerm::canEdit($this->currentUser, 'equipment');
        Response::success('success', [
            'processes'  => $this->globalProcessOrder,
            'can_edit'   => $canManage,
            'can_manage' => $canManage,
            'is_admin'   => $this->isAdmin
        ]);
    }

    public function listMatrix(): void
    {
        try {
            $search = trim((string)($_GET['search'] ?? ''));
            $where = ["1=1"];
            $params = [];

            if ($search !== '') {
                $kw = "%{$search}%";
                $where[] = "(code LIKE ? OR name LIKE ? OR product_series LIKE ? OR description LIKE ?)";
                for ($i = 0; $i < 4; $i++) {
                    $params[] = $kw;
                }
            }

            $whereSql = implode(' AND ', $where);
            $stmt = $this->db->prepare("SELECT id, code, name, product_series, description FROM `equipment` WHERE {$whereSql} ORDER BY name ASC, id DESC");
            $stmt->execute($params);
            $equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $matrix = [];
            if (!empty($equipment)) {
                $ids = implode(',', array_map('intval', array_column($equipment, 'id')));
                $effStmt = $this->db->query("SELECT equipment_id, process_code, efficiency FROM `equipment_processes` WHERE equipment_id IN ({$ids})");
                $effMap = [];
                while ($row = $effStmt->fetch(PDO::FETCH_ASSOC)) {
                    $effMap[$row['equipment_id']][$row['process_code']] = $row['efficiency'] !== null ? (float)$row['efficiency'] : null;
                }

                foreach ($equipment as $e) {
                    $e['procs'] = $effMap[$e['id']] ?? [];
                    $matrix[] = $e;
                }
            }

            Response::success('success', [
                'processes' => $this->globalProcessOrder,
                'list'      => $matrix
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取设备矩阵数据失败');
        }
    }

    public function getProductInfo(): void
    {
        $code = trim((string)($_REQUEST['code'] ?? ''));
        if (empty($code)) {
            Response::error('请输入产品代码');
            return;
        }

        $description = '';
        $processes = [];

        $mesDb = $this->getMesConnection();
        if ($mesDb !== null) {
            try {
                $sqlJob = "SELECT mater_desc FROM tab_job WHERE mater_no = :code AND mater_desc IS NOT NULL AND TRIM(mater_desc) != '' LIMIT 1";
                $st = $mesDb->prepare($sqlJob);
                $st->execute([':code' => $code]);
                $descJob = $st->fetchColumn();
                if ($descJob !== false && trim((string)$descJob) !== '') {
                    $description = trim((string)$descJob);
                }

                if (empty($description)) {
                    $sqlPlan = "SELECT mater_desc FROM tab_plan WHERE mater_no = :code AND mater_desc IS NOT NULL AND TRIM(mater_desc) != '' LIMIT 1";
                    $stPlan = $mesDb->prepare($sqlPlan);
                    $stPlan->execute([':code' => $code]);
                    $descPlan = $stPlan->fetchColumn();
                    if ($descPlan !== false && trim((string)$descPlan) !== '') {
                        $description = trim((string)$descPlan);
                    }
                }

                $sqlProcesses = "SELECT DISTINCT g.name AS process_name
                                 FROM tab_craft_route r
                                 LEFT JOIN tab_base_gx g ON r.gx_id = g.id
                                 WHERE r.mater_no = :code
                                 ORDER BY r.seq ASC";
                $stProc = $mesDb->prepare($sqlProcesses);
                $stProc->execute([':code' => $code]);
                $rawProcesses = $stProc->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($rawProcesses)) {
                    $atsKeywords = ['自动-绝缘耐压', '自动-基础功能', '自动-保护功能'];
                    $mapped = [];
                    foreach ($rawProcesses as $p) {
                        if (in_array($p, $atsKeywords, true)) {
                            $mapped[] = 'ATS测试';
                        } else {
                            $mapped[] = (string)$p;
                        }
                    }
                    $unique = array_unique($mapped);
                    $sorted = [];
                    foreach ($this->globalProcessOrder as $headerProc) {
                        if (in_array($headerProc, $unique, true)) {
                            $sorted[] = $headerProc;
                        }
                    }
                    $processes = $sorted;
                }
            } catch (Throwable $e) {}
        }

        if (empty($description)) {
            $sqlsrv = $this->getErpSqlSrvConnection();
            if ($sqlsrv !== null) {
                try {
                    $stExt = $sqlsrv->prepare("SELECT TOP 1 [描述] as description FROM [J_全离散任务] WHERE [编码] = ? AND [状态] <> '已取消'");
                    $stExt->execute([$code]);
                    $rowExt = $stExt->fetch(PDO::FETCH_ASSOC);
                    if ($rowExt && !empty($rowExt['description'])) {
                        $description = trim(mb_convert_encoding((string)$rowExt['description'], 'UTF-8', 'UTF-8,GBK,GB2312'));
                    }
                } catch (Throwable $e) {}
            }
        }

        if (empty($processes)) {
            $processes = $this->globalProcessOrder;
        }

        Response::success('success', [
            'code'        => $code,
            'description' => $description ?: '未检索到物料描述',
            'processes'   => $processes
        ]);
    }

    public function saveEquipment(): void
    {
        // ★ 权限加固：设备矩阵属"配置类"数据，仅管理员或被授权（权限分配→允许编辑与数据管理）的用户可维护
        if (!ModulePerm::canEdit($this->currentUser, 'equipment')) {
            ModulePerm::deny('设备矩阵维护');
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $id = (int)($input['id'] ?? 0);
            $code = trim((string)($input['code'] ?? ''));
            $name = trim((string)($input['name'] ?? ''));
            $series = trim((string)($input['product_series'] ?? ''));
            $desc = trim((string)($input['description'] ?? ''));
            $efficiencies = $input['efficiencies'] ?? [];

            if (empty($code) || empty($name) || empty($series)) {
                Response::error('设备编号、设备名称及代码不能为空！');
                return;
            }

            $chk = $this->db->prepare("SELECT id FROM `equipment` WHERE code = ? AND id != ?");
            $chk->execute([$code, $id]);
            if ($chk->fetch()) {
                Response::error("设备编号【{$code}】已存在！");
                return;
            }

            $this->db->beginTransaction();

            if ($id > 0) {
                $stmt = $this->db->prepare("UPDATE `equipment` SET code = ?, name = ?, product_series = ?, description = ? WHERE id = ?");
                $stmt->execute([$code, $name, $series, $desc, $id]);
                $equipId = $id;
            } else {
                $stmt = $this->db->prepare("INSERT INTO `equipment` (code, name, product_series, description) VALUES (?, ?, ?, ?)");
                $stmt->execute([$code, $name, $series, $desc]);
                $equipId = (int)$this->db->lastInsertId();
            }

            $insEff = $this->db->prepare("INSERT INTO `equipment_processes` (equipment_id, process_code, efficiency) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE efficiency = VALUES(efficiency)");
            foreach ($efficiencies as $procName => $effVal) {
                $val = ($effVal !== '' && $effVal !== null && is_numeric($effVal)) ? round((float)$effVal, 2) : null;
                $insEff->execute([$equipId, $procName, $val]);
            }

            $this->db->commit();
            AuditService::log('设备矩阵', ($id > 0 ? '修改' : '新增') . "设备: [{$code}] {$name}");
            Response::success('设备信息与工序效率系数保存成功！', ['id' => $equipId]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::exception($e, '保存失败');
        }
    }

    public function deleteEquipment(): void
    {
        // ★ 权限加固：删除属破坏性操作，仅管理员或被授权用户可执行
        if (!ModulePerm::canEdit($this->currentUser, 'equipment')) {
            ModulePerm::deny('设备矩阵维护');
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $id = (int)($input['id'] ?? 0);

            if ($id <= 0) {
                Response::error('无效的设备ID');
                return;
            }

            $this->db->beginTransaction();
            $this->db->prepare("DELETE FROM `equipment_processes` WHERE equipment_id = ?")->execute([$id]);
            $this->db->prepare("DELETE FROM `equipment` WHERE id = ?")->execute([$id]);
            $this->db->commit();

            AuditService::log('设备矩阵', "删除设备 ID: [{$id}]");
            Response::success('设备删除成功！');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::exception($e, '删除异常');
        }
    }
}