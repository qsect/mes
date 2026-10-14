<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\ModulePerm;
use App\Core\Response;
use App\Core\Schema;
use App\Core\UserRole;
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

    /**
     * 默认老化车总数（首次初始化时写入配置表）。
     * ★ 实际总数以 `aging_settings.cart_total` 为准，不再用这个常量直接参与业务逻辑：
     *   车辆转走/减少时，管理员在页面上把总数调小即可，列表与看板会随之变化。
     */
    private const DEFAULT_CART_TOTAL = 140;

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

    /**
     * 是否可维护「老化车档案」（层数 / 状态 / 使用位置 / 备注）。
     * ★ 与 canManage 区分开：canManage 来自「权限分配」里勾选的 can_edit，
     *   而档案维护只允许 超级管理员 + 班长级管理角色，不受模块授权开关影响。
     */
    private bool $canEditCart;

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
        $this->canEditCart = UserRole::canMaintain($this->currentUser);

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

            // 模块配置（单行表，id 固定为 1）。车辆总数放这里，改一处即可全局生效。
            $this->db->exec("CREATE TABLE IF NOT EXISTS `aging_settings` (
                `id` TINYINT NOT NULL,
                `cart_total` SMALLINT UNSIGNED NOT NULL DEFAULT 140 COMMENT '老化车总数',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老化车模块配置';");
            $this->db->exec(
                "INSERT IGNORE INTO `aging_settings` (`id`, `cart_total`) VALUES (1, " . self::DEFAULT_CART_TOTAL . ")"
            );

            $this->db->exec("CREATE TABLE IF NOT EXISTS `aging_carts` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `cart_no` VARCHAR(10) NOT NULL COMMENT '老化车编号，001-NNN',
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

            $this->db->exec("CREATE TABLE IF NOT EXISTS `aging_repair_workers` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `repair_id` INT NOT NULL COMMENT '所属报修维修单ID',
                `user_id` INT NOT NULL DEFAULT 0 COMMENT '维修人员ID',
                `user_name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '维修人员姓名',
                `is_starter` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=首个接单开工的人',
                `joined_at` DATETIME NOT NULL COMMENT '加入维修时刻',
                `left_at` DATETIME NULL COMMENT '离开(本人完成)时刻，NULL 表示仍在维修',
                `raw_minutes` INT NOT NULL DEFAULT 0 COMMENT '本人自然时长(分钟)',
                `duration_minutes` INT NOT NULL DEFAULT 0 COMMENT '本人有效时长(分钟)',
                `status` VARCHAR(20) NOT NULL DEFAULT 'working' COMMENT 'working维修中/left已离开',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_repair_user_join` (`repair_id`, `user_id`, `joined_at`),
                INDEX `idx_repair_id` (`repair_id`),
                INDEX `idx_user_id` (`user_id`),
                INDEX `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老化车维修参与人（多人同时维修时各自独立计时）';");

            // 历史数据迁移：把已存在的单人维修记录转成参与人记录，
            // 否则老工单会显示为「0 人参与」，且历史工时无法按人统计。
            $this->db->exec(
                "INSERT IGNORE INTO `aging_repair_workers`
                    (`repair_id`, `user_id`, `user_name`, `is_starter`, `joined_at`, `left_at`,
                     `raw_minutes`, `duration_minutes`, `status`)
                 SELECT r.id, r.repairer_id, r.repairer_name, 1, r.repair_started_at,
                        CASE WHEN r.status = 'done' THEN r.repair_finished_at ELSE NULL END,
                        CASE WHEN r.status = 'done' THEN r.raw_minutes ELSE 0 END,
                        CASE WHEN r.status = 'done' THEN r.duration_minutes ELSE 0 END,
                        CASE WHEN r.status = 'done' THEN 'left' ELSE 'working' END
                 FROM `aging_repairs` r
                 WHERE r.repair_started_at IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM `aging_repair_workers` w WHERE w.repair_id = r.id)"
            );

            // 兼容本模块早期版本：老库缺列时自动补齐（ensureColumn 内部已做存在性判断）
            Schema::ensureColumn($this->db, 'aging_carts', 'layers',
                "`layers` TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '层数' AFTER `status`");
            Schema::ensureColumn($this->db, 'aging_carts', 'usage_type',
                "`usage_type` VARCHAR(20) NOT NULL DEFAULT '流动使用' COMMENT '使用位置：固定使用/流动使用' AFTER `layers`");

            $this->initCarts($this->cartTotal());

            // ★ 初始化是「静默」的（异常只进 error_log，不打断业务请求），
            //   但静默不等于可以忽略失败：这里强制复核核心表是否真的存在。
            //   缺表 → 撤销本次的就绪标记，下次请求继续重试，
            //   避免出现「首次失败后被永久标记为已就绪，表再也不会创建」的僵局。
            $missing = $this->missingTables();
            if (!empty($missing)) {
                Schema::clearReady(self::MODULE);
                error_log('[AgingCart] 表初始化未完成，缺表：' . implode(', ', $missing)
                    . '（常见原因：数据库账号无 CREATE 权限 / 磁盘只读；已撤销就绪标记，下次请求重试）');
            }
        } catch (Throwable $e) {
            error_log('[AgingCart] 表结构初始化失败: ' . $e->getMessage());
            Schema::clearReady(self::MODULE);
        }
    }

    /** 复核本模块的核心表是否真实存在（不看缓存、直接问 information_schema） */
    private function missingTables(): array
    {
        $required = ['aging_carts', 'aging_repairs', 'aging_repair_workers', 'aging_settings'];
        $missing = [];
        try {
            foreach ($required as $table) {
                $st = $this->db->prepare(
                    "SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
                );
                $st->execute([$table]);
                if ((int)$st->fetchColumn() === 0) {
                    $missing[] = $table;
                }
            }
        } catch (Throwable $e) {
            // 查不到就当作全部未知，返回空让上层不再重复报警
            error_log('[AgingCart] 表存在性校验失败: ' . $e->getMessage());
            return [];
        }
        return $missing;
    }

    // ============================================================
    //  车辆总数配置
    // ============================================================

    /**
     * 当前老化车总数（以 aging_settings 为准）。
     * 读不到时退回 DEFAULT_CART_TOTAL —— 与历史行为一致，不会因配置表异常导致模块不可用。
     */
    private function cartTotal(): int
    {
        try {
            $v = $this->db->query("SELECT `cart_total` FROM `aging_settings` WHERE `id` = 1")->fetchColumn();
            $n = (int)$v;
            if ($n >= 1 && $n <= 999) {
                return $n;
            }
        } catch (Throwable $e) {
            error_log('[AgingCart] 读取老化车总数失败: ' . $e->getMessage());
        }
        return self::DEFAULT_CART_TOTAL;
    }

    /** 初始化 / 补齐台账到 $total 辆（幂等：INSERT IGNORE + 唯一键） */
    private function initCarts(int $total): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO `aging_carts` (`cart_no`, `status`, `usage_type`) VALUES (?, 'normal', ?)"
            );
            for ($i = 1; $i <= $total; $i++) {
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
                'cart_total'  => $this->cartTotal(),
                'is_admin'    => $this->isAdmin,
                'can_manage'  => $this->canManage,
                // 档案维护（列表页改层数/状态/使用位置/备注）仅超管 + 班长
                'can_edit_cart' => $this->canEditCart,
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
            $cartTotal = $this->cartTotal();
            $normalCarts = $cartTotal - $needRepair;

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
            $now0 = $this->now();
            foreach ($latest as &$r) {
                $r['duration_text'] = WorkDuration::format((int)$r['duration_minutes']);
                // 补参与人明细：最近修好的卡片上要能看出是几个人一起修的
                $ws = $this->loadWorkers((int)$r['id'], $now0);
                $names = [];
                $work = 0;
                foreach ($ws as $w) {
                    $names[] = $w['user_name'];
                    $work += $w['duration_minutes'];
                }
                $r['workers'] = $ws;
                $r['worker_names'] = implode('、', $names);
                $r['worker_count'] = count($ws);
                $r['is_multi_worker'] = count($ws) > 1;
                $r['work_minutes'] = $work;
                $r['work_text'] = WorkDuration::format($work);
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

            // 本月维修人员工作量：★ 按「参与人」统计，不再按工单的 repairer_name。
            // 多人协作时每人只记自己的工时，谁的贡献算谁的，不再笼统记到工单人头上。
            $st = $this->db->prepare(
                "SELECT w.user_name AS name,
                        COUNT(DISTINCT w.repair_id) AS times,
                        COALESCE(SUM(w.duration_minutes), 0) AS minutes
                 FROM `aging_repair_workers` w
                 JOIN `aging_repairs` r ON r.id = w.repair_id AND r.status = 'done'
                       AND r.repair_finished_at IS NOT NULL
                       AND r.repair_finished_at >= ? AND r.repair_finished_at <= ?
                 GROUP BY w.user_name ORDER BY times DESC, minutes DESC LIMIT 10"
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

            // 本月投入工时合计（各人有效时长之和，含多人重叠部分）
            $st = $this->db->prepare(
                "SELECT COALESCE(SUM(w.duration_minutes), 0)
                 FROM `aging_repair_workers` w
                 JOIN `aging_repairs` r ON r.id = w.repair_id AND r.status = 'done'
                       AND r.repair_finished_at IS NOT NULL
                       AND r.repair_finished_at >= ? AND r.repair_finished_at <= ?"
            );
            $st->execute([$ps, $pe]);
            $monthWorkMinutes = (int)$st->fetchColumn();

            // 本月多人协作完成的工单数
            $st = $this->db->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT w.repair_id
                    FROM `aging_repair_workers` w
                    JOIN `aging_repairs` r ON r.id = w.repair_id AND r.status = 'done'
                          AND r.repair_finished_at IS NOT NULL
                          AND r.repair_finished_at >= ? AND r.repair_finished_at <= ?
                    GROUP BY w.repair_id HAVING COUNT(DISTINCT w.user_id) > 1
                 ) t"
            );
            $st->execute([$ps, $pe]);
            $monthCollab = (int)$st->fetchColumn();

            Response::success('success', [
                'period'            => $period,
                'cart_total'        => $cartTotal,
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
                // 投入工时合计（含多人重叠，通常 >= 上面的跨度合计）
                'month_work_minutes' => $monthWorkMinutes,
                'month_work_text'    => WorkDuration::format($monthWorkMinutes),
                // 本月有多人同时参与的已完工单数
                'month_collab'      => $monthCollab,
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
                'can_edit_cart'  => $this->canEditCart,
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
                $where[] = '(r.fault_desc LIKE ? OR r.repair_remark LIKE ? OR r.reporter_name LIKE ? OR r.repairer_name LIKE ?
                             OR EXISTS (SELECT 1 FROM `aging_repair_workers` w
                                        WHERE w.repair_id = r.id AND w.user_name LIKE ?))';
                $kw = '%' . $keyword . '%';
                $params[] = $kw;
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
            $meId = $this->userId();
            foreach ($rows as &$r) {
                $repairId = (int)$r['id'];
                $status = (string)$r['status'];

                // 口径一：修复跨度（开始 → 完成/现在）
                $r['duration_text'] = WorkDuration::format((int)$r['duration_minutes']);
                $r['raw_text'] = WorkDuration::format((int)$r['raw_minutes']);
                $r['elapsed_minutes'] = 0;
                $r['elapsed_text'] = '';
                if ($status === 'repairing' && !empty($r['repair_started_at'])) {
                    $elapsed = WorkDuration::minutes((string)$r['repair_started_at'], $now);
                    $r['elapsed_minutes'] = $elapsed;
                    $r['elapsed_text'] = WorkDuration::format($elapsed);
                }

                // 口径二：投入工时合计 + 参与人明细（多人同时维修的核心展示）
                $workers = $this->loadWorkers($repairId, $now);
                $workMinutes = 0;
                $rawWork = 0;
                $active = 0;
                $meJoined = false;
                foreach ($workers as $w) {
                    $workMinutes += $w['duration_minutes'];
                    $rawWork += $w['raw_minutes'];
                    if ($w['working']) {
                        $active++;
                        if ($w['user_id'] === $meId) {
                            $meJoined = true;
                        }
                    }
                }
                $r['workers'] = $workers;
                $r['worker_count'] = count($workers);
                $r['active_worker_count'] = $active;
                $r['is_multi_worker'] = count($workers) > 1;
                $r['work_minutes'] = $workMinutes;
                $r['work_text'] = WorkDuration::format($workMinutes);
                $r['raw_work_text'] = WorkDuration::format($rawWork);
                // 后端判定「我是否已在修」，前端不比对 local 用户，避免会话信息陈旧导致按钮错乱
                $r['me_joined'] = $meJoined;
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
                Response::error('老化车编号不存在，应为 001-' . sprintf('%03d', $this->cartTotal()));
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

    /**
     * 调整老化车总数（仅超级管理员 / 班长）
     *
     * 调大：自动补建新增编号的车辆（如 131 → 141）
     * 调小：移除编号大于总数的车辆，其历史报修记录保留在 aging_repairs 中
     *
     * ⚠️ 调小的前提：被移除的车辆不能有未完成的报修/维修单，
     *    否则会出现「车辆已不在册但报修单还开着」的脏数据。
     */
    public function setCartTotal(): void
    {
        if (!$this->canEditCart) {
            Response::error('权限不足：只有超级管理员和班长可以调整老化车总数。', [], 403);
            return;
        }

        try {
            $input = $this->input();
            $total = (int)($input['cart_total'] ?? 0);
            if ($total < 1 || $total > 999) {
                Response::error('老化车总数应在 1 ~ 999 之间');
                return;
            }

            $old = $this->cartTotal();
            if ($total === $old) {
                Response::success('总数未变化', ['cart_total' => $total]);
                return;
            }

            if ($total < $old) {
                $maxNo = sprintf('%03d', $total);
                $st = $this->db->prepare(
                    "SELECT GROUP_CONCAT(DISTINCT cart_no ORDER BY cart_no SEPARATOR ', ')
                     FROM `aging_repairs`
                     WHERE status IN ('pending', 'repairing') AND cart_no > ?"
                );
                $st->execute([$maxNo]);
                $busy = trim((string)$st->fetchColumn());
                if ($busy !== '') {
                    Response::error(
                        '以下老化车有未完成的报修/维修记录，请先完成或删除后再调整总数：' . $busy
                    );
                    return;
                }
            }

            $this->db->beginTransaction();

            $upd = $this->db->prepare(
                "INSERT INTO `aging_settings` (`id`, `cart_total`) VALUES (1, ?)
                 ON DUPLICATE KEY UPDATE `cart_total` = VALUES(`cart_total`)"
            );
            $upd->execute([$total]);

            if ($total < $old) {
                $del = $this->db->prepare("DELETE FROM `aging_carts` WHERE cart_no > ?");
                $del->execute([sprintf('%03d', $total)]);
            } else {
                $this->initCarts($total);
            }

            $this->db->commit();

            AuditService::log('老化车维修', '调整老化车总数 ' . $old . ' -> ' . $total);

            Response::success('老化车总数已更新为 ' . $total, [
                'cart_total' => $total,
                'removed'    => $total < $old,
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::exception($e, '调整老化车总数失败');
        }
    }

    // ============================================================
    //  接口：参与维修（多人同时维修的核心）
    //
    //  ★ 为什么这样设计：
    //    单人模型下「先到者把后到者挤掉」，多人同修必然丢人、丢工时。
    //    改为「一张工单 + N 个参与人」：每个人有自己的加入/离开时刻，各自独立计时。
    //
    //  ★ 工单落成两个口径的时长，含义不同，都保留：
    //    - 修复跨度 span_minutes：从第一个人开工到最后收工（WorkDuration 扣非工时后）
    //      → 回答「这辆车修了多久」，用于 MTTR / 停线时长。
    //    - 投入工时 work_minutes：各人有效时长之和
    //      → 回答「总共投入了多少人力」，用于工作量统计。多人重叠时它大于跨度，这是正常的。
    // ============================================================

    /**
     * 加入维修（幂等）。
     * - 工单待维修：本人为「开工者」，工单进入维修中并开始计跨度；
     * - 工单维修中：本人为「协助人」，从第 2 人起即为多人协作；
     * - 本人已在修：重复点击直接返回成功（防连点、防网络重试产生重复计时）。
     */
    private function joinRepair(): void
    {
        try {
            $input = $this->input();
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                Response::error('无效的记录ID');
                return;
            }

            $row = $this->fetchRepair($id);
            if (!$row) {
                Response::error('记录不存在');
                return;
            }
            $status = (string)$row['status'];
            if ($status === 'done') {
                Response::error('该记录已完成维修');
                return;
            }
            if (!in_array($status, ['pending', 'repairing'], true)) {
                Response::error('该记录状态不允许维修');
                return;
            }

            $now = $this->now();
            $nowStr = $now->format('Y-m-d H:i:s');
            $cartNo = (string)$row['cart_no'];

            $this->db->beginTransaction();

            // 本人在本单是否已在修（幂等判定，含已离开的需重新计算）
            $mine = $this->db->prepare(
                "SELECT id FROM `aging_repair_workers`
                 WHERE repair_id = ? AND user_id = ? AND status = 'working' AND left_at IS NULL LIMIT 1"
            );
            $mine->execute([$id, $this->userId()]);
            if ($mine->fetchColumn()) {
                $this->db->commit();
                Response::success('你已在本单维修中', ['already' => true, 'joined_at' => $nowStr]);
                return;
            }

            $isStarter = false;

            if ($status === 'pending') {
                // 条件更新 + rowCount 兜底：并发下两人同时抢首工时，只有一人成功
                $upd = $this->db->prepare(
                    "UPDATE `aging_repairs`
                     SET status = 'repairing', repairer_id = ?, repairer_name = ?, repair_started_at = ?
                     WHERE id = ? AND status = 'pending'"
                );
                $upd->execute([$this->userId(), $this->userName(), $nowStr, $id]);
                if ($upd->rowCount() > 0) {
                    $isStarter = true;
                    $cartUpd = $this->db->prepare("UPDATE `aging_carts` SET status = 'repairing' WHERE cart_no = ?");
                    $cartUpd->execute([$cartNo]);
                }
                // rowCount = 0：说明被并发他人抢先开局，本人降级为协助人继续参与
            }

            $ins = $this->db->prepare(
                "INSERT IGNORE INTO `aging_repair_workers`
                    (`repair_id`, `user_id`, `user_name`, `is_starter`, `joined_at`, `left_at`, `status`)
                 VALUES (?, ?, ?, ?, ?, NULL, 'working')"
            );
            $ins->execute([$id, $this->userId(), $this->userName(), $isStarter ? 1 : 0, $nowStr]);

            // INSERT IGNORE 被唯一键挡下 = 同一秒内重复提交，视为已成功，不影响计时
            $workers = $this->countWorkers($id);
            $this->db->commit();

            AuditService::log('老化车维修', ($isStarter ? '开始维修 ' : '参与维修 ') . $cartNo);

            Response::success($isStarter ? '已开始维修，计时开始' : '已加入维修，你的工时独立计算', [
                'is_starter'   => $isStarter,
                'joined_at'    => $nowStr,
                'worker_count' => $workers['total'],
                'active_count' => $workers['active'],
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::exception($e, '加入维修失败');
        }
    }

    /** 开始维修（首个百分点入口，保留以兼容旧调用） */
    public function start(): void
    {
        $this->joinRepair();
    }

    /** 参与维修（工单已在维修中时，第 2 人及以后走这里） */
    public function join(): void
    {
        $this->joinRepair();
    }

    /**
     * 本人完成 / 离开（不结束整张工单）。
     * 典型场景：三人一起修，张三先收工、李四王五继续 —— 张三的工时就停在他离开的时刻。
     */
    public function leave(): void
    {
        try {
            $input = $this->input();
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                Response::error('无效的记录ID');
                return;
            }

            $row = $this->fetchRepair($id);
            if (!$row) {
                Response::error('记录不存在');
                return;
            }
            if ((string)$row['status'] !== 'repairing') {
                Response::error('只有维修中的记录才能结束个人计时');
                return;
            }

            $now = $this->now();
            $nowStr = $now->format('Y-m-d H:i:s');

            $this->db->beginTransaction();

            $sel = $this->db->prepare(
                "SELECT id, joined_at FROM `aging_repair_workers`
                 WHERE repair_id = ? AND user_id = ? AND status = 'working' AND left_at IS NULL
                 ORDER BY id DESC LIMIT 1"
            );
            $sel->execute([$id, $this->userId()]);
            $mine = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$mine) {
                $this->db->rollBack();
                Response::error('你不在这张工单的维修人中');
                return;
            }

            $startDt = WorkDuration::parse((string)$mine['joined_at']);
            if ($startDt === null) {
                $this->db->rollBack();
                Response::error('加入时间无法解析，请联系管理员核对数据');
                return;
            }
            $rawMinutes = (int)floor(($now->getTimestamp() - $startDt->getTimestamp()) / 60);
            $duration = WorkDuration::minutes($startDt, $now);

            $upd = $this->db->prepare(
                "UPDATE `aging_repair_workers`
                 SET left_at = ?, raw_minutes = ?, duration_minutes = ?, status = 'left'
                 WHERE id = ? AND status = 'working' AND left_at IS NULL"
            );
            $upd->execute([$nowStr, $rawMinutes, $duration, (int)$mine['id']]);
            if ($upd->rowCount() === 0) {
                $this->db->rollBack();
                Response::error('你的维修计时已结束，请刷新后重试');
                return;
            }

            $workers = $this->countWorkers($id);
            $this->db->commit();

            AuditService::log('老化车维修', '结束个人维修计时 ' . (string)$row['cart_no']);

            Response::success('已结束你的维修计时', [
                'duration_minutes' => $duration,
                'duration_text'    => WorkDuration::format($duration),
                'left_at'          => $nowStr,
                'active_count'     => $workers['active'],
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::exception($e, '结束维修计时失败');
        }
    }

    /** 取一张工单的参与人统计：总人数 / 仍在修人数 */
    private function countWorkers(int $repairId): array
    {
        $st = $this->db->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'working' AND left_at IS NULL THEN 1 ELSE 0 END) AS active
             FROM `aging_repair_workers` WHERE repair_id = ?"
        );
        $st->execute([$repairId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'active' => 0];
        return ['total' => (int)$r['total'], 'active' => (int)$r['active']];
    }

    /**
     * 把某张工单上「仍在修」的参与人全部结账到 $end 时刻。
     *
     * ⚠️ 必须在事务内调用，且 $end 就是工单的 repair_finished_at：
     *    否则会出现「工单已完成、但某人还在计时」的脏数据。
     */
    private function closeWorkerRows(int $repairId, DateTimeImmutable $end): void
    {
        $st = $this->db->prepare(
            "SELECT id, joined_at FROM `aging_repair_workers`
             WHERE repair_id = ? AND status = 'working' AND left_at IS NULL"
        );
        $st->execute([$repairId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $endStr = $end->format('Y-m-d H:i:s');
        $upd = $this->db->prepare(
            "UPDATE `aging_repair_workers`
             SET left_at = ?, raw_minutes = ?, duration_minutes = ?, status = 'left'
             WHERE id = ? AND status = 'working' AND left_at IS NULL"
        );

        foreach ($rows as $w) {
            $startDt = WorkDuration::parse((string)$w['joined_at']);
            if ($startDt === null) {
                continue;
            }
            $raw = (int)floor(($end->getTimestamp() - $startDt->getTimestamp()) / 60);
            $minutes = WorkDuration::minutes($startDt, $end);
            $upd->execute([$endStr, $raw, $minutes, (int)$w['id']]);
        }
    }

    private function fetchRepair(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM `aging_repairs` WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * 读取某张工单的参与人明细。
     * 仍在修的人按传进来的时刻实时计算已用时（不落库，避免每帧写库）。
     */
    private function loadWorkers(int $repairId, DateTimeImmutable $ref): array
    {
        $st = $this->db->prepare(
            "SELECT id, user_id, user_name, is_starter, joined_at, left_at,
                    raw_minutes, duration_minutes, status
             FROM `aging_repair_workers` WHERE repair_id = ? ORDER BY id ASC"
        );
        $st->execute([$repairId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $w) {
            $joined = WorkDuration::parse((string)$w['joined_at']);
            $working = ((string)$w['status'] === 'working' && empty($w['left_at']));

            $minutes = (int)$w['duration_minutes'];
            $raw = (int)$w['raw_minutes'];
            if ($working && $joined !== null) {
                // 实时值：加入时刻到现在（结束时会正式落库）
                $raw = (int)floor(($ref->getTimestamp() - $joined->getTimestamp()) / 60);
                $minutes = WorkDuration::minutes($joined, $ref);
            }

            $out[] = [
                'worker_id'        => (int)$w['id'],
                'user_id'          => (int)$w['user_id'],
                'user_name'        => (string)$w['user_name'],
                'is_starter'       => (int)$w['is_starter'] === 1,
                'joined_at'        => (string)$w['joined_at'],
                'left_at'          => $w['left_at'] === null ? '' : (string)$w['left_at'],
                'working'          => $working,
                'duration_minutes' => $minutes,
                'duration_text'    => WorkDuration::format($minutes),
                'raw_minutes'      => $raw,
            ];
        }
        return $out;
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

            // 口径一：修复跨度（工单层面，回答「这辆车修了多久」）
            $spanRaw = (int)floor(($endDt->getTimestamp() - $startDt->getTimestamp()) / 60);
            $span = WorkDuration::minutes($startDt, $endDt);
            $finishedAt = $endDt->format('Y-m-d H:i:s');

            $this->db->beginTransaction();
            $upd = $this->db->prepare(
                "UPDATE `aging_repairs`
                 SET status = 'done', repair_finished_at = ?, raw_minutes = ?, duration_minutes = ?, repair_remark = ?
                 WHERE id = ? AND status = 'repairing'"
            );
            $upd->execute([$finishedAt, $spanRaw, $span, $remark, $id]);
            if ($upd->rowCount() === 0) {
                $this->db->rollBack();
                Response::error('记录状态已变更，请刷新后重试');
                return;
            }

            // 收工时把所有仍在修的人一并结账，不能让他们的时间停在上线为止
            $this->closeWorkerRows($id, $endDt);

            // 口径二：投入工时合计（各人有效时长之和，多人重叠时大于跨度为正常）
            $sum = $this->db->prepare(
                "SELECT COALESCE(SUM(duration_minutes), 0) FROM `aging_repair_workers` WHERE repair_id = ?"
            );
            $sum->execute([$id]);
            $workMinutes = (int)$sum->fetchColumn();

            $cartUpd = $this->db->prepare("UPDATE `aging_carts` SET status = 'normal' WHERE cart_no = ?");
            $cartUpd->execute([(string)$row['cart_no']]);
            $this->db->commit();

            AuditService::log('老化车维修', '完成维修 ' . (string)$row['cart_no']);

            Response::success('维修完成', [
                // 工单跨度
                'duration_minutes' => $span,
                'duration_text'    => WorkDuration::format($span),
                'raw_minutes'      => $spanRaw,
                'raw_text'         => WorkDuration::format($spanRaw),
                // 投入工时
                'work_minutes'     => $workMinutes,
                'work_text'        => WorkDuration::format($workMinutes),
                'finished_at'      => $finishedAt,
                'workers'          => $this->loadWorkers($id, $endDt),
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

            $this->db->beginTransaction();
            $delw = $this->db->prepare("DELETE FROM `aging_repair_workers` WHERE repair_id = ?");
            $delw->execute([$id]);
            $del = $this->db->prepare("DELETE FROM `aging_repairs` WHERE id = ?");
            $del->execute([$id]);
            $this->db->commit();

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
        // ★ 档案维护仅限 超级管理员 / 班长级管理角色，不受「权限分配」的 can_edit 影响
        if (!$this->canEditCart) {
            Response::error('权限不足：只有超级管理员和班长可以修改老化车档案信息。', [], 403);
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
                Response::error('老化车编号不存在，应为 001-' . sprintf('%03d', $this->cartTotal()));
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
            $headers = [
                '编号', '老化车', '故障类型', '故障现象', '状态', '报修人', '报修时间',
                '参与维修人员', '参与人数', '首接人', '开始维修', '完成时间',
                '修复跨度(分钟)', '自然跨度(分钟)', '投入工时合计(分钟)', '维修备注',
            ];

            $now0 = $this->now();
            $data = [];
            foreach ($rows as $r) {
                $ws = $this->loadWorkers((int)$r['id'], $now0);
                $names = [];
                $work = 0;
                $starter = '';
                foreach ($ws as $w) {
                    // 仍在修的人附实时工时，导出的那一刻即为截止值
                    $names[] = $w['user_name'] . '(' . $w['duration_text'] . ($w['working'] ? ' 在修中' : '') . ')';
                    $work += $w['duration_minutes'];
                    if ($w['is_starter']) {
                        $starter = $w['user_name'];
                    }
                }
                $data[] = [
                    (string)$r['id'],
                    (string)$r['cart_no'],
                    (string)$r['fault_type'],
                    (string)$r['fault_desc'],
                    $statusMap[(string)$r['status']] ?? (string)$r['status'],
                    (string)$r['reporter_name'],
                    (string)$r['reported_at'],
                    implode('、', $names),
                    (string)count($ws),
                    $starter,
                    (string)($r['repair_started_at'] ?? ''),
                    (string)($r['repair_finished_at'] ?? ''),
                    (string)$r['duration_minutes'],
                    (string)$r['raw_minutes'],
                    (string)$work,
                    (string)$r['repair_remark'],
                ];
            }

            // 列数随表头自动推导，避免以后加列漏改样式范围
            $colCount = count($headers);
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);

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
                $headRange = 'A1:' . $lastCol . '1';
                $ws->getStyle($headRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $ws->getStyle($headRange)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('2F5597');
                for ($i = 1; $i <= $colCount; $i++) {
                    $ws->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
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
