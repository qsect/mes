<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use PDO;
use PDOException;
use Throwable;

class ManhourController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $standardProcessOrder;

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

        $this->standardProcessOrder = [
            '板卡接线', '线缆加工', '装置装配', '组件装配', 'QC检查',
            '板卡成品入库调试', '板卡程序烧录', '板卡调试', '保护功能',
            '参数设置', '大电流测试', '功能测试', '绝缘耐压测试',
            '拷机前调试', '校准', '载波调试', '装置调试',
            '阻抗测试', '包装'
        ];

        \App\Core\Schema::ensureOnce('manhour', function () { $this->initTables(); });
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `manhour_categories` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `name` varchar(100) NOT NULL,
              `slug` varchar(100) NOT NULL UNIQUE,
              `description` varchar(255) DEFAULT NULL,
              `is_active` tinyint(1) NOT NULL DEFAULT 1,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `category_columns` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `category_id` int(11) NOT NULL,
              `column_name` varchar(100) NOT NULL,
              `display_name` varchar(100) NOT NULL,
              `display_order` int(11) NOT NULL DEFAULT 1,
              PRIMARY KEY (`id`),
              KEY `idx_cat_id` (`category_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `category_permissions` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `category_id` int(11) NOT NULL,
              `can_view` tinyint(1) NOT NULL DEFAULT 1,
              `can_edit` tinyint(1) NOT NULL DEFAULT 0,
              `updated_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_user_cat` (`user_id`,`category_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `manhour_summary` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `code` varchar(50) NOT NULL UNIQUE,
              `description` text,
              `process_hours` text DEFAULT NULL,
              `total_hours` decimal(10,2) DEFAULT 0.00,
              `created_by` varchar(50) DEFAULT NULL,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $sumCols = $this->db->query("SHOW COLUMNS FROM manhour_summary")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('process_hours', $sumCols, true)) {
                $this->db->exec("ALTER TABLE `manhour_summary` ADD `process_hours` text DEFAULT NULL");
            }

            // 初始化默认分类
            $catCnt = (int)$this->db->query("SELECT COUNT(*) FROM manhour_categories")->fetchColumn();
            if ($catCnt === 0) {
                $this->db->exec("INSERT INTO `manhour_categories` (`name`, `slug`, `description`) VALUES 
                    ('完整工时', 'full-hours', '全流程工时汇总'),
                    ('装置装配', 'device-assembly', '装配车间工序工时'),
                    ('装置调试', 'device-debug', '调试车间工序工时'),
                    ('装置包装', 'device-pack', '包装车间工序工时');");
            }
        } catch (Throwable $e) {}
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

    private function isExcludedProcess(string $name): bool
    {
        $blackList = ['标签打印', '标识打印', 'ATS测试', '自动-绝缘耐压', '自动-基础功能', '自动-保护功能'];
        return in_array(trim($name), $blackList, true);
    }

    private function getProcessZyzx(string $pName): string
    {
        if (empty($pName)) return '装置调试';
        $p = trim($pName);

        $assemblyKeys = [
            'board_wiring', 'cable_processing', 'component_assembly', 'device_assembly',
            'device_assembly2', 'device_potting', '板卡接线', '线缆加工', '装置装配',
            '组件装配', '装置装配2', '装置灌胶', '装配', '接线', '线缆', '灌胶', '组件', '打胶', '点胶'
        ];
        foreach ($assemblyKeys as $ak) {
            if ($p === $ak || (mb_strlen($ak, 'UTF-8') >= 2 && mb_strpos($p, $ak) !== false)) {
                return '装置装配';
            }
        }

        $packagingKeys = [
            'packaging', 'device_pack', 'qc_check', 'device_debugging', '包装', 'QC检查', 'QC', '装置包装', '装箱', '检验'
        ];
        foreach ($packagingKeys as $pk) {
            if ($p === $pk || (mb_strlen($pk, 'UTF-8') >= 2 && mb_strpos($p, $pk) !== false)) {
                return '装置包装';
            }
        }

        return '装置调试';
    }

    private function getUserAllowedZyzx(): string
    {
        if ($this->isAdmin) return '__ALL__';

        $uRole = $this->currentUser['role'] ?? '';
        $uTeam = $this->currentUser['team'] ?? '';
        $uName = $this->currentUser['full_name'] ?? ($this->currentUser['username'] ?? '');
        $combined = $uRole . ' ' . $uTeam . ' ' . $uName;

        if (mb_strpos($combined, '装配') !== false) {
            return '装置装配';
        } elseif (mb_strpos($combined, '调试') !== false) {
            return '装置调试';
        } elseif (mb_strpos($combined, '包装') !== false) {
            return '装置包装';
        }

        return '__ALL__';
    }

    public function categories(): void
    {
        try {
            $allowedZyzx = $this->getUserAllowedZyzx();

            if ($this->isAdmin) {
                $categories = $this->db->query("SELECT * FROM manhour_categories WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $this->db->prepare("
                    SELECT c.*, cp.can_view, cp.can_edit
                    FROM manhour_categories c
                    INNER JOIN category_permissions cp ON c.id = cp.category_id AND cp.user_id = ?
                    WHERE c.is_active = 1 AND cp.can_view = 1
                    ORDER BY c.id ASC
                ");
                $stmt->execute([(int)$this->currentUser['id']]);
                $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($categories)) {
                    $allCats = $this->db->query("SELECT * FROM manhour_categories WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
                    $categories = [];
                    foreach ($allCats as $cat) {
                        if ($allowedZyzx === '__ALL__') {
                            $cat['can_view'] = 1;
                            $cat['can_edit'] = 0;
                            $categories[] = $cat;
                        } elseif (mb_strpos((string)$cat['name'], $allowedZyzx) !== false) {
                            $cat['can_view'] = 1;
                            $cat['can_edit'] = 1;
                            $categories[] = $cat;
                        }
                    }
                }
            }

            $totalRecords = (int)$this->db->query("SELECT COUNT(*) FROM manhour_summary")->fetchColumn();
            $totalHours = (float)$this->db->query("SELECT COALESCE(SUM(total_hours), 0) FROM manhour_summary")->fetchColumn();
            $totalCategories = count($categories);

            Response::success('success', [
                'categories' => $categories,
                'is_admin'   => $this->isAdmin,
                'user_zyzx'  => $allowedZyzx,
                'stats'      => [
                    'total_records'    => $totalRecords,
                    'total_hours'      => $totalHours,
                    'total_categories' => $totalCategories
                ]
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取分类失败');
        }
    }

    public function getCodeMeta(): void
    {
        try {
            $code = trim((string)($_GET['code'] ?? ($_POST['code'] ?? '')));
            $catSlug = trim((string)($_GET['cat'] ?? ($_POST['cat'] ?? '')));
            if (empty($code)) {
                Response::error('代码不能为空');
                return;
            }

            $materDesc = null;
            $rawProcesses = [];

            // 1) 联查 MES 数据库中的物料描述
            $mesDb = $this->getMesConnection();
            if ($mesDb) {
                try {
                    $stmtJob = $mesDb->prepare("SELECT mater_desc FROM tab_job WHERE mater_no = :mater_no AND mater_desc IS NOT NULL AND TRIM(mater_desc) != '' LIMIT 1");
                    $stmtJob->execute([':mater_no' => $code]);
                    $val = $stmtJob->fetchColumn();
                    if ($val) $materDesc = trim((string)$val);

                    if (!$materDesc) {
                        $stmtPlan = $mesDb->prepare("SELECT mater_desc FROM tab_plan WHERE mater_no = :mater_no AND mater_desc IS NOT NULL AND TRIM(mater_desc) != '' LIMIT 1");
                        $stmtPlan->execute([':mater_no' => $code]);
                        $val = $stmtPlan->fetchColumn();
                        if ($val) $materDesc = trim((string)$val);
                    }

                    if (!$materDesc) {
                        $stmtBase = $mesDb->prepare("SELECT `desc` FROM tab_base_mater WHERE CAST(id AS CHAR) = :mater_no AND `desc` IS NOT NULL AND TRIM(`desc`) != '' LIMIT 1");
                        $stmtBase->execute([':mater_no' => $code]);
                        $val = $stmtBase->fetchColumn();
                        if ($val) $materDesc = trim((string)$val);
                    }
                } catch (Throwable $e) {}
            }

            // 2) 联查 SQL Server 描述兜底
            if (!$materDesc) {
                $sqlsrv = $this->getErpSqlSrvConnection();
                if ($sqlsrv) {
                    try {
                        $stmt = $sqlsrv->prepare("SELECT TOP 1 [描述] as description FROM [J_全离散任务] WHERE [编码] = ? ORDER BY [任务] DESC");
                        $stmt->execute([$code]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row && !empty($row['description'])) {
                            $materDesc = trim(mb_convert_encoding((string)$row['description'], 'UTF-8', 'UTF-8,GBK,GB2312'));
                        }
                    } catch (Throwable $e) {}
                }
            }

            if (!$materDesc) $materDesc = "产品 {$code}";

            // 3) 从 MES 获取该物料绑定的所有工艺工序路线
            if ($mesDb) {
                try {
                    $sqlProcesses = "SELECT DISTINCT g.name AS process_name, zy.name AS zyzx_name, r.seq
                                     FROM tab_craft_route r
                                     JOIN tab_base_gx g ON r.gx_id = g.id
                                     LEFT JOIN tab_base_zyzx zy ON g.zyzx_id = zy.id
                                     WHERE r.mater_no = :mater_no
                                     ORDER BY r.seq ASC";
                    $stmt = $mesDb->prepare($sqlProcesses);
                    $stmt->execute([':mater_no' => $code]);
                    $rawProcesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {}
            }

            $processedList = [];
            foreach ($rawProcesses as $p) {
                $pName = trim((string)$p['process_name']);
                if ($this->isExcludedProcess($pName)) continue;

                $zyzx = !empty($p['zyzx_name']) ? trim((string)$p['zyzx_name']) : $this->getProcessZyzx($pName);

                $processedList[] = [
                    'gx_name'   => $pName,
                    'zyzx_name' => $zyzx,
                    'seq'       => (int)$p['seq']
                ];
            }

            $catName = '';
            if (!empty($catSlug)) {
                $catStmt = $this->db->prepare("SELECT name FROM manhour_categories WHERE slug = ?");
                $catStmt->execute([$catSlug]);
                $catName = (string)$catStmt->fetchColumn();
            }

            $filteredProcesses = [];
            $seen = [];

            foreach ($processedList as $p) {
                $pName = $p['gx_name'];
                $zyzx = $p['zyzx_name'];
                if (isset($seen[$pName])) continue;

                $matched = false;
                // 当在完整工时页面、或未指定具体页面时返回全部工艺工序；否则按中心名称过滤
                if ($catName === '完整工时' || empty($catName) || $catName === '全部' || $catSlug === 'full-hours') {
                    $matched = true;
                } elseif (str_contains($catName, $zyzx) || str_contains($zyzx, $catName)) {
                    $matched = true;
                }

                if ($matched) {
                    $seen[$pName] = true;
                    $filteredProcesses[] = $p;
                }
            }

            $orderRef = $this->standardProcessOrder;
            usort($filteredProcesses, function ($a, $b) use ($orderRef) {
                $idxA = array_search($a['gx_name'], $orderRef, true);
                $idxB = array_search($b['gx_name'], $orderRef, true);
                if ($idxA !== false && $idxB !== false) return $idxA - $idxB;
                if ($idxA !== false) return -1;
                if ($idxB !== false) return 1;
                return $a['seq'] - $b['seq'];
            });

            Response::success('success', [
                'code'          => $code,
                'description'   => $materDesc,
                'category_name' => $catName,
                'processes'     => $filteredProcesses
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '查询工序异常');
        }
    }

    public function list(): void
    {
        try {
            $slug = trim((string)($_GET['cat'] ?? ''));
            if ($slug === '') {
                Response::error('未指定分类');
                return;
            }

            $stmt = $this->db->prepare("SELECT * FROM manhour_categories WHERE slug = ? AND is_active = 1");
            $stmt->execute([$slug]);
            $cat = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cat) {
                Response::error('分类不存在');
                return;
            }

            $allowedZyzx = $this->getUserAllowedZyzx();
            $canView = $this->isAdmin;
            $canEdit = $this->isAdmin;

            if (!$this->isAdmin) {
                $pStmt = $this->db->prepare("SELECT can_view, can_edit FROM category_permissions WHERE user_id = ? AND category_id = ?");
                $pStmt->execute([(int)$this->currentUser['id'], $cat['id']]);
                $perm = $pStmt->fetch(PDO::FETCH_ASSOC);
                if ($perm) {
                    $canView = !empty($perm['can_view']);
                    $canEdit = !empty($perm['can_edit']);
                } else {
                    if ($allowedZyzx === '__ALL__' || mb_strpos((string)$cat['name'], $allowedZyzx) !== false) {
                        $canView = true;
                        $canEdit = ($allowedZyzx !== '__ALL__');
                    }
                }
            }

            if (!$canView) {
                Response::error('您无权查看此工时分类', [], 403);
                return;
            }

            $isFullHours = ($cat['name'] === '完整工时' || $cat['slug'] === 'full-hours');

            if ($isFullHours) {
                $columns = [
                    ['column_name' => 'code', 'display_name' => '代码'],
                    ['column_name' => 'description', 'display_name' => '描述'],
                    ['column_name' => 'assembly_hours', 'display_name' => '装置装配', 'is_center' => true, 'center_name' => '装置装配', 'target_slug' => 'device-assembly'],
                    ['column_name' => 'debug_hours', 'display_name' => '装置调试', 'is_center' => true, 'center_name' => '装置调试', 'target_slug' => 'device-debug'],
                    ['column_name' => 'pack_hours', 'display_name' => '装置包装', 'is_center' => true, 'center_name' => '装置包装', 'target_slug' => 'device-pack'],
                    ['column_name' => 'total_hours', 'display_name' => '总工时']
                ];
            } else {
                $colStmt = $this->db->prepare("SELECT column_name, display_name FROM category_columns WHERE category_id = ? ORDER BY display_order ASC");
                $colStmt->execute([(int)$cat['id']]);
                $rawColumns = $colStmt->fetchAll(PDO::FETCH_ASSOC);

                $frontColumns = [];
                foreach ($rawColumns as $c) {
                    if ($this->isExcludedProcess($c['column_name']) || $this->isExcludedProcess($c['display_name'])) continue;
                    if ($c['column_name'] !== 'total_hours') {
                        $frontColumns[] = $c;
                    }
                }

                $colNames = array_column($frontColumns, 'column_name');
                if (!in_array('code', $colNames, true)) {
                    array_unshift($frontColumns, ['column_name' => 'code', 'display_name' => '代码']);
                }
                if (!in_array('description', $colNames, true)) {
                    array_splice($frontColumns, 1, 0, [['column_name' => 'description', 'display_name' => '描述']]);
                }

                $frontColumns[] = ['column_name' => 'total_hours', 'display_name' => '总工时'];
                $columns = $frontColumns;
            }

            $search = trim((string)($_GET['search'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 30;
            $offset = ($page - 1) * $perPage;

            $where = ["1=1"];
            $params = [];
            if ($search !== '') {
                $kw = "%{$search}%";
                $where[] = "(code LIKE ? OR description LIKE ?)";
                $params[] = $kw;
                $params[] = $kw;
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM manhour_summary WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($totalRecords / $perPage);

            $sql = "SELECT * FROM manhour_summary WHERE {$whereSql} ORDER BY code DESC LIMIT {$offset}, {$perPage}";
            $dataStmt = $this->db->prepare($sql);
            $dataStmt->execute($params);
            $rawRecords = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            $records = [];
            $categoryTotalHours = 0.0;

            foreach ($rawRecords as $r) {
                $pHours = [];
                if (!empty($r['process_hours'])) {
                    $pHours = json_decode((string)$r['process_hours'], true);
                    if (!is_array($pHours)) $pHours = [];
                }

                foreach ($r as $k => $v) {
                    if (!in_array($k, ['id', 'code', 'description', 'process_hours', 'total_hours', 'created_by', 'created_at', 'updated_at'], true)) {
                        $num = (float)$v;
                        if ($num > 0 && !isset($pHours[$k])) {
                            $pHours[$k] = $num;
                        }
                    }
                }

                $rowItem = $r;
                $rowTotal = 0.0;

                if ($isFullHours) {
                    $assemblyHours = 0.0;
                    $debugHours = 0.0;
                    $packHours = 0.0;
                    $centerDetails = ['装置装配' => [], '装置调试' => [], '装置包装' => []];

                    foreach ($pHours as $pName => $hVal) {
                        if ($this->isExcludedProcess($pName)) continue;
                        $val = (float)$hVal;
                        if ($val <= 0) continue;

                        $zyzx = $this->getProcessZyzx($pName);

                        if ($zyzx === '装置装配') {
                            $assemblyHours += $val;
                        } elseif ($zyzx === '装置包装') {
                            $packHours += $val;
                        } else {
                            $debugHours += $val;
                        }
                        $centerDetails[$zyzx][$pName] = $val;
                    }

                    $rowItem['assembly_hours'] = $assemblyHours;
                    $rowItem['debug_hours']    = $debugHours;
                    $rowItem['pack_hours']     = $packHours;
                    $rowItem['total_hours']    = ($assemblyHours + $debugHours + $packHours);
                    $rowItem['center_details'] = $centerDetails;
                    $categoryTotalHours += $rowItem['total_hours'];
                } else {
                    foreach ($columns as $c) {
                        $cn = $c['column_name'];
                        if (!in_array($cn, ['code', 'description', 'total_hours', 'id', 'created_by', 'created_at', 'updated_at', 'process_hours'], true)) {
                            $val = isset($pHours[$cn]) ? $pHours[$cn] : (isset($r[$cn]) ? $r[$cn] : 0);
                            $rowItem[$cn] = (float)$val;
                            $rowTotal += (float)$val;
                            $categoryTotalHours += (float)$val;
                        }
                    }
                    $rowItem['total_hours'] = $rowTotal;
                }

                $records[] = $rowItem;
            }

            Response::success('success', [
                'category'             => $cat,
                'is_full_hours'        => $isFullHours,
                'columns'              => $columns,
                'records'              => $records,
                'total_records'        => $totalRecords,
                'total_pages'          => $totalPages,
                'current_page'         => $page,
                'category_total_hours' => $categoryTotalHours,
                'can_edit'             => $canEdit,
                'is_admin'             => $this->isAdmin
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取数据列表失败');
        }
    }

    public function add(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $code = trim((string)($input['code'] ?? ''));
            if (empty($code)) {
                Response::error('产品代码不能为空');
                return;
            }

            $desc = !empty($input['description']) ? trim((string)$input['description']) : "产品 {$code}";
            $catSlug = trim((string)($input['cat'] ?? ''));
            $rawHoursMap = is_array($input['hours'] ?? null) ? $input['hours'] : [];

            $allowedZyzx = $this->getUserAllowedZyzx();

            $hoursMap = [];
            $curTotal = 0.0;
            foreach ($rawHoursMap as $k => $v) {
                if ($this->isExcludedProcess((string)$k)) continue;
                if (!$this->isAdmin && $allowedZyzx !== '__ALL__' && $this->getProcessZyzx((string)$k) !== $allowedZyzx) {
                    continue;
                }
                $hoursMap[$k] = round((float)$v, 2);
                $curTotal += $hoursMap[$k];
            }

            $stmt = $this->db->prepare("SELECT * FROM manhour_summary WHERE code = ?");
            $stmt->execute([$code]);
            $exist = $stmt->fetch(PDO::FETCH_ASSOC);

            $userName = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : $this->currentUser['username'];

            if ($exist) {
                $oldHours = [];
                if (!empty($exist['process_hours'])) {
                    $oldHours = json_decode((string)$exist['process_hours'], true);
                    if (!is_array($oldHours)) $oldHours = [];
                }
                $mergedHours = array_merge($oldHours, $hoursMap);
                $fullTotal = array_sum($mergedHours);

                $up = $this->db->prepare("UPDATE manhour_summary SET process_hours = ?, total_hours = ?, updated_at = NOW() WHERE id = ?");
                $up->execute([json_encode($mergedHours, JSON_UNESCAPED_UNICODE), $fullTotal, $exist['id']]);
            } else {
                $ins = $this->db->prepare("INSERT INTO manhour_summary (code, description, process_hours, total_hours, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                $ins->execute([$code, $desc, json_encode($hoursMap, JSON_UNESCAPED_UNICODE), $curTotal, $userName]);
            }

            if (!empty($catSlug) && $catSlug !== 'full-hours') {
                $catStmt = $this->db->prepare("SELECT id FROM manhour_categories WHERE slug = ?");
                $catStmt->execute([$catSlug]);
                $catId = (int)$catStmt->fetchColumn();

                if ($catId > 0) {
                    $existCols = $this->db->prepare("SELECT column_name FROM category_columns WHERE category_id = ?");
                    $existCols->execute([$catId]);
                    $existingColNames = $existCols->fetchAll(PDO::FETCH_COLUMN);

                    $maxOrder = (int)$this->db->query("SELECT COALESCE(MAX(display_order), 0) FROM category_columns WHERE category_id = {$catId}")->fetchColumn();

                    foreach ($hoursMap as $pName => $val) {
                        if ($this->isExcludedProcess((string)$pName)) continue;
                        if (!in_array($pName, $existingColNames, true)) {
                            $insCol = $this->db->prepare("INSERT INTO category_columns (category_id, column_name, display_name, display_order) VALUES (?, ?, ?, ?)");
                            $insCol->execute([$catId, $pName, $pName, ++$maxOrder]);
                        }
                    }
                }
            }

            AuditService::log('工时管理', '新增工时记录');
            Response::success('添加保存成功');
        } catch (Throwable $e) {
            Response::exception($e, '添加保存异常');
        }
    }

    public function update(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                Response::error('无效的记录ID');
                return;
            }

            $rawHoursMap = is_array($input['hours'] ?? null) ? $input['hours'] : [];
            $allowedZyzx = $this->getUserAllowedZyzx();

            $hoursMap = [];
            foreach ($rawHoursMap as $k => $v) {
                if ($this->isExcludedProcess((string)$k)) continue;
                if (!$this->isAdmin && $allowedZyzx !== '__ALL__' && $this->getProcessZyzx((string)$k) !== $allowedZyzx) {
                    continue;
                }
                $hoursMap[$k] = round((float)$v, 2);
            }

            $stmt = $this->db->prepare("SELECT * FROM manhour_summary WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                Response::error('记录不存在');
                return;
            }

            $oldHours = [];
            if (!empty($row['process_hours'])) {
                $oldHours = json_decode((string)$row['process_hours'], true);
                if (!is_array($oldHours)) $oldHours = [];
            }

            $mergedHours = array_merge($oldHours, $hoursMap);
            $fullTotal = array_sum($mergedHours);

            $up = $this->db->prepare("UPDATE manhour_summary SET process_hours = ?, total_hours = ?, updated_at = NOW() WHERE id = ?");
            $up->execute([json_encode($mergedHours, JSON_UNESCAPED_UNICODE), $fullTotal, $id]);

            AuditService::log('工时管理', '修改工时记录');
            Response::success('工时保存成功');
        } catch (Throwable $e) {
            Response::exception($e, '保存工时异常');
        }
    }

    public function delete(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员拥有删除权限', [], 403);
            return;
        }

        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];

            if (empty($ids)) {
                Response::error('请选择要删除的记录');
                return;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("DELETE FROM manhour_summary WHERE id IN ($placeholders)");
            $stmt->execute(array_map('intval', $ids));
            $count = $stmt->rowCount();

            AuditService::log('工时管理', '删除工时记录');
            Response::success("成功删除 {$count} 条工时记录", ['count' => $count]);
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }
}