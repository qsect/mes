<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\ModulePerm;
use App\Core\Response;
use App\Core\Schema;
use App\Core\WorkDuration;
use App\Services\AuditService;
use PDO;
use Throwable;
use DateTimeImmutable;

/**
 * 老化车维修管理
 *
 * 业务口径（按需求确认）：
 *   1. 老化车编号固定 001~140，模块初始化时自动建立台账；
 *   2. 任意有查看权限的用户都能报修（填写老化车编号 + 故障现象）；
 *   3. 任意有查看权限的用户都能接单维修：点「开始维修」开始计时，
 *      点「完成」结束并可填备注，系统自动统计有效维修时长；
 *   4. 维修时长只统计「有效时段」，规则见 WORK_WINDOWS / LUNCH_* 常量；
 *   5. 看板展示待修数量、最近修好的 5 辆、本月损坏/修复车辆数；
 *      月份口径为「每月 26 日 ~ 次月 25 日」，如 2027 年 1 月 = 2026-12-26 ~ 2027-01-25；
 *   6. 权限：查看权限由 ModuleViewMiddleware('aging_cart') 把关；
 *      有查看权限者即可报修与维修；编辑、删除限管理员（ModulePerm::canEdit）。
 *
 * ★ 时间基准：本模块一律显式使用 Asia/Shanghai 时区，不依赖服务器 date.timezone，
 *   否则「8:30 之前不算」这类时段规则会因时区偏差整体错位。
 */
class AgingCartController
{
    /** 模块代码：需与 modules 表登记一致，用于模块权限与审计 */
    private const MODULE = 'aging_cart';

    /** 老化车总数（编号 001~140） */
    private const CART_TOTAL = 140;

    /**
     * 故障类型预设（按需求固定 4 项，顺序即下拉顺序）。
     * 历史数据中出现过的其它类型会被追加在后面，避免老记录编辑时校验不通过。
     */
    private const FAULT_TYPES = ['车辆变形', '车辆掉螺丝', '轮子异常', '其他'];

    /** 只有这些故障类型才强制要求填写故障现象，其余「可写可不写」 */
    private const FAULT_DESC_REQUIRED = ['其他'];

    /** 使用位置选项，默认「流动使用」 */
    private const USAGE_TYPES = ['固定使用', '流动使用'];
    private const DEFAULT_USAGE = '流动使用';

    /** 车辆状态（列表页口径）：后端实际存 normal/pending/repairing/fault，前端只呈现「正常/故障」 */
    private const STATUS_NORMAL = 'normal';
    private const STATUS_FAULT = 'fault';

    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private bool $canManage;

    public function __construct()
    {
        $this->db = Database::getConnection();

        // ★ 安全基线：会话缺失不再兜底放行，必须重新登录
        if (empty($_SESSION['user'])) {
            Response::error('登录已失效，请重新登录', ['need_login' => true], 401);
            exit;
        }

        $this->currentUser = $_SESSION['user'];
        $uname = (string)($this->currentUser['username'] ?? '');
        $this->isAdmin = !empty($this->currentUser['is_admin']) || $uname === 'admin';
        $this->canManage = $this->isAdmin || ModulePerm::canEdit($this->currentUser, self::MODULE);

        Schema::ensureOnce(self::MODULE, function () {
            $this->initTables();
        });
    }

    // ============================================================
    //  基础工具
    // ============================================================

    private function now(): DateTimeImmutable
    {
        return WorkDuration::now();
    }

    private function userId(): int
    {
        return (int)($this->currentUser['id'] ?? 0);
    }

    private function userName(): string
    {
        $name = trim((string)($this->currentUser['real_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($this->currentUser['name'] ?? ''));
        }
        if ($name === '') {
            $name = (string)($this->currentUser['username'] ?? '');
        }
        return $name;
    }

    /** 读取并解析 JSON 请求体，兼容表单提交 */
    private function input(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) {
            $data = [];
        }
        return $data ?: $_POST;
    }

    // ============================================================
    //  表结构自愈
    // ============================================================

    private function initTables(): void
    {
        try {
            // 注意：DDL 会隐式提交事务，必须在任何 beginTransaction() 之前执行
            $this->db->exec("CREATE TABLE IF NOT EXISTS `aging_carts` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `cart_no` VARCHAR(10) NOT NULL COMMENT '老化车编号，001-140',
                `status` VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT 'normal正常/pending待维修/repairing维修中/fault管理员标记故障',
                `layers` TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '层数',
                `usage_type` VARCHAR(20) NOT NULL DEFAULT '流动使用' COMMENT '使用位置：固定使用/流动使用',
                `location` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '所在区域',
                `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_cart_no` (`cart_no`),
                INDEX `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老化车台账';");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `aging_repairs` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `cart_no` VARCHAR(10) NOT NULL COMMENT '老化车编号',
                `fault_type` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '故障类型',
                `fault_desc` VARCHAR(500) NOT NULL COMMENT '故障现象',
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending待维修/repairing维修中/done已修复',
                `reporter_id` INT NOT NULL DEFAULT 0,
                `reporter_name` VARCHAR(64) NOT NULL DEFAULT '',
                `reported_at` DATETIME NOT NULL COMMENT '报修时间',
                `repairer_id` INT NOT NULL DEFAULT 0,
                `repairer_name` VARCHAR(64) NOT NULL DEFAULT '',
                `repair_started_at` DATETIME NULL COMMENT '开始维修时间',
                `repair_finished_at` DATETIME NULL COMMENT '完成维修时间',
                `raw_minutes` INT NOT NULL DEFAULT 0 COMMENT '自然时长(分钟)',
                `duration_minutes` INT NOT NULL DEFAULT 0 COMMENT '有效维修时长(分钟，已扣除不统计时段)',
                `repair_remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '维修备注',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_cart_no` (`cart_no`),
                INDEX `idx_status` (`status`),
                INDEX `idx_reported_at` (`reported_at`),
                INDEX `idx_finished_at` (`repair_finished_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老化车报修维修记录';");

            // 兼容本模块早期版本：老库缺列时自动补齐（ensureColumn 内部已做存在性判断）
            Schema::ensureColumn($this->db, 'aging_carts', 'layers',
                "`layers` TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '层数' AFTER `status`");
            Schema::ensureColumn($this->db, 'aging_carts', 'usage_type',
                "`usage_type` VARCHAR(20) NOT NULL DEFAULT '流动使用' COMMENT '使用位置：固定使用/流动使用' AFTER `layers`");

            $this->initCarts();
        } catch (Throwable $e) {
            error_log('[AgingCart] 表结构初始化失败: ' . $e->getMessage());
        }
    }

    /** 初始化 001~140 台账（幂等：INSERT IGNORE + 唯一键） */
    private function initCarts(): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO `aging_carts` (`cart_no`, `status`, `usage_type`) VALUES (?, 'normal', ?)"
            );
            for ($i = 1; $i <= self::CART_TOTAL; $i++) {
                $stmt->execute([sprintf('%03d', $i), self::DEFAULT_USAGE]);
            }
        } catch (Throwable $e) {
            error_log('[AgingCart] 老化车台账初始化失败: ' . $e->getMessage());
        }
    }

    // ============================================================
    //  时段与月度口径
    // ============================================================

    // ★ 月度口径（26 日起算）与有效时长算法统一由 App\Core\WorkDuration 提供：
    //   可脱离数据库做单元测试（backend/tests/AgingCartDurationTest.php），其它模块亦可复用。


    // ============================================================
    //  接口：初始化
    // ============================================================

    public function init(): void
    {
        try {
            $now = $this->now();

            Response::success('success', [
                // 固定 4 项打头，历史类型追加在后，保证老记录仍可编辑
                'fault_types'         => $this->allowedFaultTypes(),
                // 前端据此决定「故障现象」是否必填
                'fault_desc_required' => self::FAULT_DESC_REQUIRED,
                'usage_types'         => self::USAGE_TYPES,
                'status_options'      => [
                    ['value' => self::STATUS_NORMAL, 'label' => '正常'],
                    ['value' => self::STATUS_FAULT,  'label' => '故障'],
                ],
                'cart_total'  => self::CART_TOTAL,
                'is_admin'    => $this->isAdmin,
                'can_manage'  => $this->canManage,
                'period'      => WorkDuration::periodRange(WorkDuration::periodLabel($now)),
                'rules'       => WorkDuration::rules(),
                'server_time' => $now->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '初始化失败');
        }
    }

    /**
     * 允许的故障类型 = 预设 4 项 + 历史记录中出现过的类型（后者为兼容老数据）
     */
    private function allowedFaultTypes(): array
    {
        $types = self::FAULT_TYPES;
        try {
            $used = $this->db->query(
                "SELECT DISTINCT fault_type FROM `aging_repairs` WHERE fault_type <> '' ORDER BY fault_type ASC"
            )->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($used)) {
                foreach ($used as $t) {
                    $t = trim((string)$t);
                    if ($t !== '' && !in_array($t, $types, true)) {
                        $types[] = $t;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[AgingCart] 读取历史故障类型失败: ' . $e->getMessage());
        }
        return $types;
    }

    /** 该故障类型是否强制要求填写故障现象 */
    private function faultDescRequired(string $faultType): bool
    {
        return in_array($faultType, self::FAULT_DESC_REQUIRED, true);
    }

    /** 车辆状态在列表页的呈现口径：非 normal 一律「故障」 */
    private function cartStatusLabel(string $status): string
    {
        return $status === self::STATUS_NORMAL ? '正常' : '故障';
    }

    // ============================================================
    //  接口：看板
    // ============================================================

    public function dashboard(): void
    {
        try {
            $periodLabel = trim((string)($_GET['period'] ?? ''));
            $period = $periodLabel !== '' ? WorkDuration::periodRange($periodLabel) : WorkDuration::currentPeriod();
            $ps = $period['start'];
            $pe = $period['end'];

            // 需要维修的老化车：车辆状态非正常（含报修/维修中/管理员标记故障），
            // 或以报修单为准兜底 —— 两者取并集，避免车辆状态与报修单数据漂移时漏统计。
            $needRepair = (int)$this->db->query(
                "SELECT COUNT(*) FROM `aging_carts` c
                 WHERE c.status <> 'normal'
                    OR EXISTS (SELECT 1 FROM `aging_repairs` r
                               WHERE r.cart_no = c.cart_no AND r.status IN ('pending', 'repairing'))"
            )->fetchColumn();

            // 管理员手动标记故障、且无报修单的车（与报修单口径区分开）
            $manualFault = (int)$this->db->query(
                "SELECT COUNT(*) FROM `aging_carts` WHERE status = 'fault'"
            )->fetchColumn();

            $pendingCount = (int)$this->db->query("SELECT COUNT(*) FROM `aging_repairs` WHERE status = 'pending'")->fetchColumn();
            $repairingCount = (int)$this->db->query("SELECT COUNT(*) FROM `aging_repairs` WHERE status = 'repairing'")->fetchColumn();
            $normalCarts = self::CART_TOTAL - $needRepair;

            // 本月报修：按报修时间落在统计区间
            $st = $this->db->prepare("SELECT COUNT(DISTINCT cart_no) FROM `aging_repairs` WHERE reported_at >= ? AND reported_at <= ?");
            $st->execute([$ps, $pe]);
            $monthBroken = (int)$st->fetchColumn();

            $st = $this->db->prepare("SELECT COUNT(*) FROM `aging_repairs` WHERE reported_at >= ? AND reported_at <= ?");
            $st->execute([$ps, $pe]);
            $monthReports = (int)$st->fetchColumn();

            // 本月修复：按完成时间落在统计区间
            $st = $this->db->prepare(
                "SELECT COUNT(DISTINCT cart_no) FROM `aging_repairs`
                 WHERE status = 'done' AND repair_finished_at IS NOT NULL AND repair_finished_at >= ? AND repair_finished_at <= ?"
            );
            $st->execute([$ps, $pe]);
            $monthFixed = (int)$st->fetchColumn();

            $st = $this->db->prepare(
                "SELECT COUNT(*), COALESCE(SUM(duration_minutes), 0) FROM `aging_repairs`
                 WHERE status = 'done' AND repair_finished_at IS NOT NULL AND repair_finished_at >= ? AND repair_finished_at <= ?"
            );
            $st->execute([$ps, $pe]);
            $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
            $monthFixedTimes = (int)$row[0];
            $monthMinutes = (int)$row[1];
            $avgMinutes = $monthFixedTimes > 0 ? (int)round($monthMinutes / $monthFixedTimes) : 0;

            // 最近修好的 5 辆
            $latest = $this->db->query(
                "SELECT id, cart_no, fault_type, fault_desc, repairer_name, repair_started_at, repair_finished_at,
                        duration_minutes, repair_remark
                 FROM `aging_repairs`
                 WHERE status = 'done' AND repair_finished_at IS NOT NULL
                 ORDER BY repair_finished_at DESC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($latest as &$r) {
                $r['duration_text'] = WorkDuration::format((int)$r['duration_minutes']);
            }
            unset($r);

            // 本月故障次数最多的车辆 Top5
            $st = $this->db->prepare(
                "SELECT cart_no, COUNT(*) AS times
                 FROM `aging_repairs`
                 WHERE reported_at >= ? AND reported_at <= ?
                 GROUP BY cart_no ORDER BY times DESC, cart_no ASC LIMIT 5"
            );
            $st->execute([$ps, $pe]);
            $topCarts = $st->fetchAll(PDO::FETCH_ASSOC);

            // 本月维修人员工作量
            $st = $this->db->prepare(
                "SELECT repairer_name AS name, COUNT(*) AS times, COALESCE(SUM(duration_minutes), 0) AS minutes
                 FROM `aging_repairs`
                 WHERE status = 'done' AND repair_finished_at IS NOT NULL
                       AND repair_finished_at >= ? AND repair_finished_at <= ?
                 GROUP BY repairer_name ORDER BY times DESC, minutes DESC LIMIT 10"
            );
            $st->execute([$ps, $pe]);
            $workers = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($workers as &$w) {
                $w['duration_text'] = WorkDuration::format((int)$w['minutes']);
                if ((string)$w['name'] === '') {
                    $w['name'] = '未登记';
                }
            }
            unset($w);

            Response::success('success', [
                'period'            => $period,
                'cart_total'        => self::CART_TOTAL,
                'need_repair'       => $needRepair,
                'manual_fault'      => $manualFault,
                'pending_count'     => $pendingCount,
                'repairing_count'   => $repairingCount,
                'normal_count'      => $normalCarts,
                'month_broken'      => $monthBroken,
                'month_reports'     => $monthReports,
                'month_fixed'       => $monthFixed,
                'month_fixed_times' => $monthFixedTimes,
                'month_minutes'     => $monthMinutes,
                'month_avg_minutes' => $avgMinutes,
                'month_minutes_text' => WorkDuration::format($monthMinutes),
                'month_avg_text'    => WorkDuration::format($avgMinutes),
                'latest_fixed'      => $latest,
                'top_carts'         => $topCarts,
                'workers'           => $workers,
                'can_manage'        => $this->canManage,
                'is_admin'          => $this->isAdmin,
                'server_time'       => $this->now()->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取看板数据失败');
        }
    }

    // ============================================================
    //  接口：老化车台账（140 辆总览）
    // ============================================================

    public function carts(): void
    {
        try {
            $sql = "SELECT c.cart_no,
                           c.status,
                           c.layers,
                           c.usage_type,
                           c.location,
                           c.remark,
                           (SELECT COUNT(*) FROM `aging_repairs` r WHERE r.cart_no = c.cart_no) AS repair_times,
                           (SELECT r2.id FROM `aging_repairs` r2
                            WHERE r2.cart_no = c.cart_no AND r2.status IN ('pending', 'repairing')
                            ORDER BY r2.reported_at DESC LIMIT 1) AS current_repair_id,
                           (SELECT r2.fault_type FROM `aging_repairs` r2
                            WHERE r2.cart_no = c.cart_no AND r2.status IN ('pending', 'repairing')
                            ORDER BY r2.reported_at DESC LIMIT 1) AS current_fault_type,
                           (SELECT r2.fault_desc FROM `aging_repairs` r2
                            WHERE r2.cart_no = c.cart_no AND r2.status IN ('pending', 'repairing')
                            ORDER BY r2.reported_at DESC LIMIT 1) AS current_fault,
                           (SELECT MAX(r3.repair_finished_at) FROM `aging_repairs` r3
                            WHERE r3.cart_no = c.cart_no AND r3.status = 'done') AS last_fixed_at
                    FROM `aging_carts` c
                    ORDER BY c.cart_no ASC";
            $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$r) {
                $status = (string)$r['status'];
                $r['layers'] = ($r['layers'] === null || $r['layers'] === '') ? null : (int)$r['layers'];
                $r['usage_type'] = ((string)$r['usage_type'] === '') ? self::DEFAULT_USAGE : (string)$r['usage_type'];
                $r['status_label'] = $this->cartStatusLabel($status);
                $r['is_fault'] = $status !== self::STATUS_NORMAL;

                // 故障说明：仅故障态才有内容；修复后随状态回到 normal，列表里自动不再显示
                if (!$r['is_fault']) {
                    $r['fault_text'] = '';
                } else {
                    $ft = trim((string)($r['current_fault_type'] ?? ''));
                    $fd = trim((string)($r['current_fault'] ?? ''));
                    if ($ft === '' && $fd === '') {
                        $r['fault_text'] = '（未填写，直接标记为故障）';
                    } elseif ($fd === '' || $ft === $fd) {
                        $r['fault_text'] = $ft;
                    } else {
                        $r['fault_text'] = $ft . '：' . $fd;
                    }
                }
            }
            unset($r);

            Response::success('success', [
                'list'           => $rows,
                'total'          => count($rows),
                'can_manage'     => $this->canManage,
                'usage_types'    => self::USAGE_TYPES,
                'status_options' => [
                    ['value' => self::STATUS_NORMAL, 'label' => '正常'],
                    ['value' => self::STATUS_FAULT,  'label' => '故障'],
                ],
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取老化车台账失败');
        }
    }

    // ============================================================
    //  接口：报修记录列表
    // ============================================================

    public function list(): void
    {
        try {
            $status = trim((string)($_GET['status'] ?? ''));
            $cartNo = trim((string)($_GET['cart_no'] ?? ''));
            $keyword = trim((string)($_GET['keyword'] ?? ''));
            $from = trim((string)($_GET['from'] ?? ''));
            $to = trim((string)($_GET['to'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $size = (int)($_GET['size'] ?? 20);
            if ($size < 5) {
                $size = 5;
            }
            if ($size > 200) {
                $size = 200;
            }

            $where = ['1=1'];
            $params = [];

            if ($status !== '' && in_array($status, ['pending', 'repairing', 'done'], true)) {
                $where[] = 'r.status = ?';
                $params[] = $status;
            }
            if ($cartNo !== '') {
                $where[] = 'r.cart_no = ?';
                $params[] = $cartNo;
            }
            if ($keyword !== '') {
                $where[] = '(r.fault_desc LIKE ? OR r.repair_remark LIKE ? OR r.reporter_name LIKE ? OR r.repairer_name LIKE ?)';
                $kw = '%' . $keyword . '%';
                $params[] = $kw;
                $params[] = $kw;
                $params[] = $kw;
                $params[] = $kw;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $where[] = 'r.reported_at >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $where[] = 'r.reported_at <= ?';
                $params[] = $to . ' 23:59:59';
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM `aging_repairs` r WHERE {$whereSql}");
            $cntStmt->execute($params);
            $total = (int)$cntStmt->fetchColumn();

            $offset = ($page - 1) * $size;
            $sql = "SELECT r.* FROM `aging_repairs` r WHERE {$whereSql}
                    ORDER BY FIELD(r.status, 'repairing', 'pending', 'done'), r.reported_at DESC
                    LIMIT {$size} OFFSET {$offset}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $now = $this->now();
            foreach ($rows as &$r) {
                $r['duration_text'] = WorkDuration::format((int)$r['duration_minutes']);
                $r['raw_text'] = WorkDuration::format((int)$r['raw_minutes']);
                $r['elapsed_minutes'] = 0;
                $r['elapsed_text'] = '';
                if ((string)$r['status'] === 'repairing' && !empty($r['repair_started_at'])) {
                    $elapsed = WorkDuration::minutes((string)$r['repair_started_at'], $now);
                    $r['elapsed_minutes'] = $elapsed;
                    $r['elapsed_text'] = WorkDuration::format($elapsed);
                }
            }
            unset($r);

            Response::success('success', [
                'list'       => $rows,
                'total'      => $total,
                'page'       => $page,
                'size'       => $size,
                'can_manage' => $this->canManage,
                'is_admin'   => $this->isAdmin,
                'server_time' => $now->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取报修记录失败');
        }
    }

    // ============================================================
    //  接口：报修
    // ============================================================

    public function report(): void
    {
        try {
            $input = $this->input();
            $cartNo = trim((string)($input['cart_no'] ?? ''));
            $faultType = trim((string)($input['fault_type'] ?? ''));
            $faultDesc = trim((string)($input['fault_desc'] ?? ''));

            if ($cartNo === '') {
                Response::error('请选择或填写老化车编号');
                return;
            }
            if ($faultType === '') {
                Response::error('请选择故障类型');
                return;
            }
            if (!in_array($faultType, $this->allowedFaultTypes(), true)) {
                Response::error('故障类型无效，请从预设项中选择');
                return;
            }
            // 只有「其他」强制填写故障现象，其余类型可写可不写
            if ($this->faultDescRequired($faultType) && $faultDesc === '') {
                Response::error('故障类型为「' . $faultType . '」时，必须填写故障现象');
                return;
            }
            if (mb_strlen($faultDesc) > 500) {
                Response::error('故障现象不能超过 500 字');
                return;
            }

            // 编号合法性：必须是台账中已存在的车
            $chk = $this->db->prepare("SELECT id FROM `aging_carts` WHERE cart_no = ? LIMIT 1");
            $chk->execute([$cartNo]);
            if (!$chk->fetchColumn()) {
                Response::error('老化车编号不存在，应为 001-' . sprintf('%03d', self::CART_TOTAL));
                return;
            }

            // 同一辆车存在未完成的报修时，避免重复建单
            $dup = $this->db->prepare("SELECT id FROM `aging_repairs` WHERE cart_no = ? AND status IN ('pending', 'repairing') LIMIT 1");
            $dup->execute([$cartNo]);
            if ($dup->fetchColumn()) {
                Response::error('该老化车已有未完成的报修/维修记录，请先处理后再报修');
                return;
            }

            $now = $this->now()->format('Y-m-d H:i:s');

            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                "INSERT INTO `aging_repairs`
                    (cart_no, fault_type, fault_desc, status, reporter_id, reporter_name, reported_at)
                 VALUES (?, ?, ?, 'pending', ?, ?, ?)"
            );
            $stmt->execute([$cartNo, $faultType, $faultDesc, $this->userId(), $this->userName(), $now]);
            $newId = (int)$this->db->lastInsertId();

            $upd = $this->db->prepare("UPDATE `aging_carts` SET status = 'pending' WHERE cart_no = ?");
            $upd->execute([$cartNo]);
            $this->db->commit();

            AuditService::log('老化车维修', '报修 ' . $cartNo);

            Response::success('报修成功', ['id' => $newId]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::exception($e, '报修失败');
        }
    }

    // ============================================================
    //  接口：开始维修
    // ============================================================

    public function start(): void
    {
        try {
            $input = $this->input();
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                Response::error('无效的记录ID');
                return;
            }

            $stmt = $this->db->prepare("SELECT id, cart_no, status FROM `aging_repairs` WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                Response::error('记录不存在');
                return;
            }
            if ((string)$row['status'] === 'repairing') {
                Response::error('该记录已在维修中');
                return;
            }
            if ((string)$row['status'] === 'done') {
                Response::error('该记录已完成维修');
                return;
            }

            $now = $this->now()->format('Y-m-d H:i:s');

            $this->db->beginTransaction();
            // 条件更新 + rowCount 双重校验，避免并发下两人同时接单
            $upd = $this->db->prepare(
                "UPDATE `aging_repairs`
                 SET status = 'repairing', repairer_id = ?, repairer_name = ?, repair_started_at = ?
                 WHERE id = ? AND status = 'pending'"
            );
            $upd->execute([$this->userId(), $this->userName(), $now, $id]);
            if ($upd->rowCount() === 0) {
                $this->db->rollBack();
                Response::error('该报修已被他人接单，请刷新后重试');
                return;
            }

            $cartUpd = $this->db->prepare("UPDATE `aging_carts` SET status = 'repairing' WHERE cart_no = ?");
            $cartUpd->execute([(string)$row['cart_no']]);
            $this->db->commit();

            AuditService::log('老化车维修', '开始维修 ' . (string)$row['cart_no']);

            Response::success('已开始维修，计时开始', ['started_at' => $now]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::exception($e, '开始维修失败');
        }
    }

    // ============================================================
    //  接口：完成维修
    // ============================================================

    public function finish(): void
    {
        try {
            $input = $this->input();
            $id = (int)($input['id'] ?? 0);
            $remark = trim((string)($input['repair_remark'] ?? ''));
            if ($id <= 0) {
                Response::error('无效的记录ID');
                return;
            }
            if (mb_strlen($remark) > 500) {
                Response::error('维修备注不能超过 500 字');
                return;
            }

            $stmt = $this->db->prepare(
                "SELECT id, cart_no, status, repair_started_at FROM `aging_repairs` WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                Response::error('记录不存在');
                return;
            }
            if ((string)$row['status'] !== 'repairing') {
                Response::error('只有维修中的记录才能完成');
                return;
            }
            if (empty($row['repair_started_at'])) {
                Response::error('该记录缺少开始维修时间，无法统计时长');
                return;
            }

            $startDt = WorkDuration::parse((string)$row['repair_started_at']);
            $endDt = $this->now();
            if ($startDt === null) {
                Response::error('开始维修时间无法解析，请联系管理员核对数据');
                return;
            }
            if ($endDt < $startDt) {
                Response::error('完成时间早于开始时间，请核对服务器时间');
                return;
            }

            $rawMinutes = (int)floor(($endDt->getTimestamp() - $startDt->getTimestamp()) / 60);
            $duration = WorkDuration::minutes($startDt, $endDt);
            $finishedAt = $endDt->format('Y-m-d H:i:s');

            $this->db->beginTransaction();
            $upd = $this->db->prepare(
                "UPDATE `aging_repairs`
                 SET status = 'done', repair_finished_at = ?, raw_minutes = ?, duration_minutes = ?, repair_remark = ?
                 WHERE id = ? AND status = 'repairing'"
            );
            $upd->execute([$finishedAt, $rawMinutes, $duration, $remark, $id]);
            if ($upd->rowCount() === 0) {
                $this->db->rollBack();
                Response::error('记录状态已变更，请刷新后重试');
                return;
            }

            $cartUpd = $this->db->prepare("UPDATE `aging_carts` SET status = 'normal' WHERE cart_no = ?");
            $cartUpd->execute([(string)$row['cart_no']]);
            $this->db->commit();

            AuditService::log('老化车维修', '完成维修 ' . (string)$row['cart_no']);

            Response::success('维修完成', [
                'duration_minutes' => $duration,
                'duration_text'    => WorkDuration::format($duration),
                'raw_minutes'      => $rawMinutes,
                'raw_text'         => WorkDuration::format($rawMinutes),
                'finished_at'      => $finishedAt,
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::exception($e, '完成维修失败');
        }
    }

    // ============================================================
    //  接口：编辑 / 删除（仅管理员）
    // ============================================================

    public function save(): void
    {
        if (!$this->canManage) {
            ModulePerm::deny('编辑老化车记录');
            return;
        }

        try {
            $input = $this->input();
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                Response::error('无效的记录ID');
                return;
            }

            $stmt = $this->db->prepare("SELECT id, cart_no FROM `aging_repairs` WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                Response::error('记录不存在');
                return;
            }
            $oldCartNo = (string)$row['cart_no'];

            $cartNo = trim((string)($input['cart_no'] ?? $oldCartNo));
            $faultType = trim((string)($input['fault_type'] ?? ''));
            $faultDesc = trim((string)($input['fault_desc'] ?? ''));
            $remark = trim((string)($input['repair_remark'] ?? ''));

            if ($cartNo === '') {
                Response::error('老化车编号不能为空');
                return;
            }
            if ($faultType === '') {
                Response::error('请选择故障类型');
                return;
            }
            if (!in_array($faultType, $this->allowedFaultTypes(), true)) {
                Response::error('故障类型无效，请从预设项中选择');
                return;
            }
            // 与报修保持同一口径：仅「其他」强制填写故障现象
            if ($this->faultDescRequired($faultType) && $faultDesc === '') {
                Response::error('故障类型为「' . $faultType . '」时，必须填写故障现象');
                return;
            }
            if (mb_strlen($faultDesc) > 500 || mb_strlen($remark) > 500) {
                Response::error('故障现象与备注均不能超过 500 字');
                return;
            }

            $chk = $this->db->prepare("SELECT id FROM `aging_carts` WHERE cart_no = ? LIMIT 1");
            $chk->execute([$cartNo]);
            if (!$chk->fetchColumn()) {
                Response::error('老化车编号不存在');
                return;
            }

            $upd = $this->db->prepare(
                "UPDATE `aging_repairs` SET cart_no = ?, fault_type = ?, fault_desc = ?, repair_remark = ? WHERE id = ?"
            );
            $upd->execute([$cartNo, $faultType, $faultDesc, $remark, $id]);

            // 换了车号需要同步两辆车的状态
            $this->syncCartStatus($oldCartNo);
            if ($cartNo !== $oldCartNo) {
                $this->syncCartStatus($cartNo);
            }

            AuditService::log('老化车维修', '修改报修记录 #' . $id);

            Response::success('保存成功');
        } catch (Throwable $e) {
            Response::exception($e, '保存失败');
        }
    }

    public function delete(): void
    {
        if (!$this->canManage) {
            ModulePerm::deny('删除老化车记录');
            return;
        }

        try {
            $input = $this->input();
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                Response::error('无效的记录ID');
                return;
            }

            $stmt = $this->db->prepare("SELECT id, cart_no FROM `aging_repairs` WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                Response::error('记录不存在');
                return;
            }
            $cartNo = (string)$row['cart_no'];

            $del = $this->db->prepare("DELETE FROM `aging_repairs` WHERE id = ?");
            $del->execute([$id]);

            $this->syncCartStatus($cartNo);

            AuditService::log('老化车维修', '删除报修记录 #' . $id);

            Response::success('删除成功');
        } catch (Throwable $e) {
            Response::exception($e, '删除失败');
        }
    }

    // ============================================================
    //  接口：车辆档案维护（仅管理员）
    //      支持局部更新：layers / usage_type / remark / status
    // ============================================================

    public function saveCart(): void
    {
        if (!$this->canManage) {
            ModulePerm::deny('维护老化车档案');
            return;
        }

        try {
            $input = $this->input();
            $cartNo = trim((string)($input['cart_no'] ?? ''));
            if ($cartNo === '') {
                Response::error('老化车编号不能为空');
                return;
            }

            $chk = $this->db->prepare("SELECT id FROM `aging_carts` WHERE cart_no = ? LIMIT 1");
            $chk->execute([$cartNo]);
            if (!$chk->fetchColumn()) {
                Response::error('老化车编号不存在，应为 001-' . sprintf('%03d', self::CART_TOTAL));
                return;
            }

            // 该车是否存在未完成的报修/维修单（决定状态能否被手动改写）
            $open = $this->db->prepare(
                "SELECT status FROM `aging_repairs`
                 WHERE cart_no = ? AND status IN ('pending', 'repairing')
                 ORDER BY FIELD(status, 'repairing', 'pending') LIMIT 1"
            );
            $open->execute([$cartNo]);
            $openStatus = $open->fetchColumn();

            $sets = [];
            $params = [];

            // 层数：可留空（NULL），填写时限定 1~99
            if (array_key_exists('layers', $input)) {
                $raw = $input['layers'];
                if ($raw === null || $raw === '' || $raw === 'null') {
                    $sets[] = '`layers` = NULL';
                } else {
                    $layers = (int)$raw;
                    if ($layers < 1 || $layers > 99) {
                        Response::error('层数应在 1 ~ 99 之间');
                        return;
                    }
                    $sets[] = '`layers` = ?';
                    $params[] = $layers;
                }
            }

            // 使用位置
            if (array_key_exists('usage_type', $input)) {
                $usage = trim((string)$input['usage_type']);
                if ($usage !== '') {
                    if (!in_array($usage, self::USAGE_TYPES, true)) {
                        Response::error('使用位置只能是：' . implode(' / ', self::USAGE_TYPES));
                        return;
                    }
                    $sets[] = '`usage_type` = ?';
                    $params[] = $usage;
                }
            }

            // 备注
            if (array_key_exists('remark', $input)) {
                $remark = trim((string)$input['remark']);
                if (mb_strlen($remark) > 255) {
                    Response::error('备注不能超过 255 字');
                    return;
                }
                $sets[] = '`remark` = ?';
                $params[] = $remark;
            }

            // 状态：只允许在「正常 / 故障」之间切换，且必须与报修单口径一致
            if (array_key_exists('status', $input)) {
                $status = trim((string)$input['status']);
                if (!in_array($status, [self::STATUS_NORMAL, self::STATUS_FAULT], true)) {
                    Response::error('状态只能是「正常」或「故障」');
                    return;
                }
                if ($status === self::STATUS_NORMAL && $openStatus) {
                    Response::error(
                        '该老化车有未完成的报修/维修记录，请先在「报修记录」中完成维修，状态会自动恢复为正常'
                    );
                    return;
                }
                // 存在未完成报修单时不覆盖 pending/repairing（列表上同样呈现为「故障」）
                if (!($status === self::STATUS_FAULT && $openStatus)) {
                    $sets[] = '`status` = ?';
                    $params[] = $status;
                }
            }

            if (!$sets) {
                Response::error('没有需要保存的字段');
                return;
            }

            $params[] = $cartNo;
            $upd = $this->db->prepare('UPDATE `aging_carts` SET ' . implode(', ', $sets) . ' WHERE cart_no = ?');
            $upd->execute($params);

            AuditService::log('老化车维修', '维护车辆档案 ' . $cartNo);

            Response::success('已保存');
        } catch (Throwable $e) {
            Response::exception($e, '保存老化车档案失败');
        }
    }

    /** 依据未完成的报修记录回写老化车状态 */
    private function syncCartStatus(string $cartNo): void
    {
        try {
            $st = $this->db->prepare(
                "SELECT status FROM `aging_repairs` WHERE cart_no = ? AND status IN ('pending', 'repairing')
                 ORDER BY FIELD(status, 'repairing', 'pending') LIMIT 1"
            );
            $st->execute([$cartNo]);
            $status = $st->fetchColumn();
            $newStatus = $status ? (string)$status : 'normal';

            $upd = $this->db->prepare("UPDATE `aging_carts` SET status = ? WHERE cart_no = ?");
            $upd->execute([$newStatus, $cartNo]);
        } catch (Throwable $e) {
            error_log('[AgingCart] 同步车辆状态失败: ' . $e->getMessage());
        }
    }

    // ============================================================
    //  接口：导出
    // ============================================================

    public function export(): void
    {
        try {
            $status = trim((string)($_GET['status'] ?? ''));
            $cartNo = trim((string)($_GET['cart_no'] ?? ''));
            $from = trim((string)($_GET['from'] ?? ''));
            $to = trim((string)($_GET['to'] ?? ''));

            $where = ['1=1'];
            $params = [];
            if ($status !== '' && in_array($status, ['pending', 'repairing', 'done'], true)) {
                $where[] = 'status = ?';
                $params[] = $status;
            }
            if ($cartNo !== '') {
                $where[] = 'cart_no = ?';
                $params[] = $cartNo;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $where[] = 'reported_at >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $where[] = 'reported_at <= ?';
                $params[] = $to . ' 23:59:59';
            }
            $whereSql = implode(' AND ', $where);

            $stmt = $this->db->prepare("SELECT * FROM `aging_repairs` WHERE {$whereSql} ORDER BY reported_at DESC LIMIT 5000");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $statusMap = ['pending' => '待维修', 'repairing' => '维修中', 'done' => '已修复'];
            $headers = ['编号', '老化车', '故障类型', '故障现象', '状态', '报修人', '报修时间', '维修人', '开始维修', '完成时间', '有效时长(分钟)', '自然时长(分钟)', '维修备注'];

            $data = [];
            foreach ($rows as $r) {
                $data[] = [
                    (string)$r['id'],
                    (string)$r['cart_no'],
                    (string)$r['fault_type'],
                    (string)$r['fault_desc'],
                    $statusMap[(string)$r['status']] ?? (string)$r['status'],
                    (string)$r['reporter_name'],
                    (string)$r['reported_at'],
                    (string)$r['repairer_name'],
                    (string)($r['repair_started_at'] ?? ''),
                    (string)($r['repair_finished_at'] ?? ''),
                    (string)$r['duration_minutes'],
                    (string)$r['raw_minutes'],
                    (string)$r['repair_remark'],
                ];
            }

            // 优先输出 Excel（服务器已装 PhpSpreadsheet），否则回退 CSV
            if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
                $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $ws = $sheet->getActiveSheet();
                $ws->setTitle('老化车维修记录');
                $ws->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($data as $row) {
                    $col = 'A';
                    foreach ($row as $val) {
                        $ws->setCellValueExplicit($col . $line, $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        $col++;
                    }
                    $line++;
                }
                $ws->getStyle('A1:M1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $ws->getStyle('A1:M1')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'M') as $col) {
                    $ws->getColumnDimension($col)->setAutoSize(true);
                }

                while (ob_get_level()) {
                    ob_end_clean();
                }
                $filename = '老化车维修记录_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet);
                $writer->save('php://output');
                exit;
            }

            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="老化车维修记录_' . date('Ymd_His') . '.csv"');
            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, array_map('excelSafe', $headers));
            foreach ($data as $row) {
                fputcsv($output, array_map('excelSafe', $row));
            }
            fclose($output);
            exit;
        } catch (Throwable $e) {
            Response::exception($e, '导出失败');
        }
    }
}
