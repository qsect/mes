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

class OeeController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $allTeams;
    private array $allowedTeams;
    private array $editTeams = [];

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
        $this->allTeams = ['装置装配', '装置调试', '装置包装', '装置车间'];
        $this->allowedTeams = $this->resolveViewTeams();   // 可查看班组
        $this->editTeams    = $this->resolveEditTeams();   // 可填写班组
        \App\Core\Schema::ensureOnce('oee', function () { $this->initTables(); });
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `oee_records` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `team` varchar(50) NOT NULL,
                `stat_month` varchar(7) NOT NULL COMMENT '统计月份(YYYY-MM)',
                `total_output` decimal(12,2) NOT NULL DEFAULT 0,
                `outsourced_output` decimal(12,2) NOT NULL DEFAULT 0,
                `actual_output` decimal(12,2) NOT NULL COMMENT '实际产量(总产量-委外产量)',
                `actual_time` decimal(12,2) NOT NULL COMMENT '实际用时(小时)',
                `efficiency` decimal(12,2) NOT NULL,
                `remarks` text,
                `created_by` int(11) NOT NULL,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_team` (`team`),
                INDEX `idx_stat_month` (`stat_month`),
                INDEX `idx_created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Throwable $e) {}
    }

    private function getOeeCoefficient(string $team): float
    {
        switch ($team) {
            case '装置装配': return 0.323;
            case '装置调试': return 0.545;
            case '装置包装': return 0.133;
            case '装置车间': return 1.0;
            default: return 1.0;
        }
    }

    private function resolveViewTeams(): array
    {
        if ($this->isAdmin) return $this->allTeams;

        $username = strtolower((string)($this->currentUser['username'] ?? ''));
        $fullName = strtolower((string)($this->currentUser['full_name'] ?? ''));
        $role     = strtolower((string)($this->currentUser['role'] ?? ''));
        $team     = strtolower((string)($this->currentUser['team'] ?? ''));
        $identifier = $username . ' ' . $fullName . ' ' . $role . ' ' . $team;

        $teams = [];
        if (str_contains($identifier, '装配') || str_contains($identifier, '孙远超') || str_contains($identifier, 'sunyuanchao')) {
            $teams[] = '装置装配';
        }
        if (str_contains($identifier, '调试') || str_contains($identifier, '刘少俊') || str_contains($identifier, 'liushaojun') ||
            str_contains($identifier, '鲍敏') || str_contains($identifier, 'baomin') ||
            str_contains($identifier, '徐祥志') || str_contains($identifier, 'xuxiangzhi')) {
            $teams[] = '装置调试';
        }
        if (str_contains($identifier, '包装') || str_contains($identifier, '楚永杰') || str_contains($identifier, 'chuyongjie') || str_contains($identifier, 'zhangyanrong')) {
            $teams[] = '装置包装';
        }

        // 未匹配到任何班组 → 无查看权限（不再默认放开全部）
        return array_values(array_unique($teams));
    }

    /**
     * 可"填写/编辑"的班组范围（小于等于可查看范围）
     * 依据需求：孙远超→装置装配；刘少俊→装置调试；楚永杰→装置包装。
     * 徐祥志 / 鲍敏 仅可查看 装置调试，不可填写。
     */
    private function resolveEditTeams(): array
    {
        if ($this->isAdmin) return $this->allTeams;

        $username = strtolower((string)($this->currentUser['username'] ?? ''));
        $fullName = strtolower((string)($this->currentUser['full_name'] ?? ''));
        $identifier = $username . ' ' . $fullName;

        $teams = [];
        if (str_contains($identifier, '孙远超') || str_contains($identifier, 'sunyuanchao')) {
            $teams[] = '装置装配';
        }
        if (str_contains($identifier, '刘少俊') || str_contains($identifier, 'liushaojun')) {
            $teams[] = '装置调试';
        }
        if (str_contains($identifier, '楚永杰') || str_contains($identifier, 'chuyongjie') || str_contains($identifier, 'zhangyanrong')) {
            $teams[] = '装置包装';
        }

        return array_values(array_unique($teams));
    }

    private function buildPermissionWhere(): array
    {
        if ($this->isAdmin) return ['sql' => '1=1', 'params' => []];
        if (empty($this->allowedTeams)) return ['sql' => '1=0', 'params' => []];

        $placeholders = implode(',', array_fill(0, count($this->allowedTeams), '?'));
        return [
            'sql' => "r.team IN ($placeholders)",
            'params' => $this->allowedTeams
        ];
    }

    public function init(): void
    {
        Response::success('success', [
            'allowed_teams' => $this->allowedTeams,
            'edit_teams'    => $this->editTeams,
            'all_teams'     => $this->allTeams,
            'is_admin'      => $this->isAdmin
        ]);
    }

    public function list(): void
    {
        try {
            $filterTeam  = trim((string)($_GET['filter_team'] ?? ''));
            $filterStart = trim((string)($_GET['filter_start'] ?? ''));
            $filterEnd   = trim((string)($_GET['filter_end'] ?? ''));
            $page        = max(1, (int)($_GET['page'] ?? 1));
            $perPage     = 30;
            $offset      = ($page - 1) * $perPage;

            $perm = $this->buildPermissionWhere();
            $where = [$perm['sql']];
            $params = $perm['params'];

            if ($filterTeam !== '') {
                if ($this->isAdmin || in_array($filterTeam, $this->allowedTeams, true)) {
                    $where[] = "r.team = ?";
                    $params[] = $filterTeam;
                }
            }
            if ($filterStart !== '') {
                $where[] = "r.stat_month >= ?";
                $params[] = $filterStart;
            }
            if ($filterEnd !== '') {
                $where[] = "r.stat_month <= ?";
                $params[] = $filterEnd;
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM oee_records r WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages = (int)ceil($totalRecords / $perPage);

            $sql = "SELECT r.*, COALESCE(u.full_name, u.username, '用户') AS creator_name
                    FROM oee_records r 
                    LEFT JOIN users u ON r.created_by = u.id 
                    WHERE {$whereSql} 
                    ORDER BY r.stat_month DESC, r.created_at DESC 
                    LIMIT {$offset}, {$perPage}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($records as &$r) {
                $r['can_edit'] = $this->isAdmin || in_array($r['team'], $this->editTeams, true);
            }
            unset($r);

            Response::success('success', [
                'records'       => $records,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'current_page'  => $page,
                'is_admin'      => $this->isAdmin,
                'allowed_teams' => $this->allowedTeams,
                'edit_teams'    => $this->editTeams,
                'user_id'       => $this->currentUser['id']
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取记录列表失败');
        }
    }

    public function add(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $team              = trim((string)($input['team'] ?? ''));
            $stat_month        = trim((string)($input['stat_month'] ?? ''));
            $total_output      = round((float)($input['total_output'] ?? 0), 2);
            $outsourced_output = round((float)($input['outsourced_output'] ?? 0), 2);
            $actual_time       = round((float)($input['actual_time'] ?? 0), 2);
            $remarks           = trim((string)($input['remarks'] ?? ''));

            if (!$this->isAdmin && !in_array($team, $this->editTeams, true)) {
                Response::error('您没有权限添加该班组的数据');
                return;
            }
            if (!in_array($team, $this->allTeams, true)) {
                Response::error('班组选择无效');
                return;
            }
            if (!preg_match('/^\d{4}-\d{2}$/', $stat_month)) {
                Response::error('统计月份格式错误，应为 YYYY-MM');
                return;
            }
            if ($total_output < 0 || $outsourced_output < 0 || $actual_time <= 0) {
                Response::error('产量不能为负数且用时必须大于0');
                return;
            }
            if ($outsourced_output > $total_output) {
                Response::error('委外产量不能大于总产量');
                return;
            }

            $actual_output = $total_output - $outsourced_output;
            $base_eff      = ($actual_time > 0) ? ($actual_output / $actual_time) : 0;
            $coeff         = $this->getOeeCoefficient($team);
            $efficiency    = round($base_eff * $coeff, 2);

            $sql = "INSERT INTO oee_records (team, stat_month, total_output, outsourced_output, actual_output, actual_time, efficiency, remarks, created_by, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $team, $stat_month, $total_output, $outsourced_output,
                $actual_output, $actual_time, $efficiency, $remarks, (int)$this->currentUser['id']
            ]);

            AuditService::log('班组效率', '新增效率记录');
            Response::success('班组效率记录添加成功', ['id' => (int)$this->db->lastInsertId()]);
        } catch (Throwable $e) {
            Response::exception($e, '添加失败');
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

            $chk = $this->db->prepare("SELECT created_by, team FROM oee_records WHERE id = ?");
            $chk->execute([$id]);
            $oldRec = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$oldRec) {
                Response::error('记录不存在');
                return;
            }
            if (!$this->isAdmin && !in_array($oldRec['team'], $this->editTeams, true)) {
                Response::error('您没有权限编辑该班组的记录');
                return;
            }

            $team              = trim((string)($input['team'] ?? ''));
            $stat_month        = trim((string)($input['stat_month'] ?? ''));
            $total_output      = round((float)($input['total_output'] ?? 0), 2);
            $outsourced_output = round((float)($input['outsourced_output'] ?? 0), 2);
            $actual_time       = round((float)($input['actual_time'] ?? 0), 2);
            $remarks           = trim((string)($input['remarks'] ?? ''));

            if (!$this->isAdmin && !in_array($team, $this->editTeams, true)) {
                Response::error('无权操作该班组');
                return;
            }
            if (!preg_match('/^\d{4}-\d{2}$/', $stat_month)) {
                Response::error('统计月份格式错误');
                return;
            }
            if ($total_output < 0 || $outsourced_output < 0 || $actual_time <= 0) {
                Response::error('产量不能为负且用时需大于0');
                return;
            }
            if ($outsourced_output > $total_output) {
                Response::error('委外产量不能大于总产量');
                return;
            }

            $actual_output = $total_output - $outsourced_output;
            $base_eff      = ($actual_time > 0) ? ($actual_output / $actual_time) : 0;
            $coeff         = $this->getOeeCoefficient($team);
            $efficiency    = round($base_eff * $coeff, 2);

            $sql = "UPDATE oee_records SET team = ?, stat_month = ?, total_output = ?, outsourced_output = ?, actual_output = ?, actual_time = ?, efficiency = ?, remarks = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $team, $stat_month, $total_output, $outsourced_output,
                $actual_output, $actual_time, $efficiency, $remarks, $id
            ]);

            AuditService::log('班组效率', '修改效率记录');
            Response::success('记录修改成功');
        } catch (Throwable $e) {
            Response::exception($e, '更新失败');
        }
    }

    public function dashboardData(): void
    {
        try {
            $selectedTeam = trim((string)($_GET['team'] ?? ''));
            
            if (empty($selectedTeam) || ($selectedTeam !== '__all__' && !in_array($selectedTeam, $this->allowedTeams, true) && !$this->isAdmin)) {
                $selectedTeam = !empty($this->allowedTeams) ? $this->allowedTeams[0] : '__all__';
            }

            $perm = $this->buildPermissionWhere();
            $sql = "SELECT 
                        stat_month,
                        team,
                        SUM(total_output) AS total_output,
                        SUM(outsourced_output) AS outsourced_output,
                        SUM(actual_time) AS total_time
                    FROM oee_records r
                    WHERE {$perm['sql']}
                    GROUP BY stat_month, team
                    ORDER BY stat_month ASC, team ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($perm['params']);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $monthlyData = [];
            $monthlyRaw  = [];
            $allMonths   = [];

            foreach ($rows as $row) {
                $m       = $row['stat_month'];
                $t       = $row['team'];
                $totOut  = (float)$row['total_output'];
                $outOut  = (float)$row['outsourced_output'];
                $tTime   = (float)$row['total_time'];
                $actOut  = $totOut - $outOut;
                $baseEff = ($tTime > 0) ? ($actOut / $tTime) : 0;
                $eff     = round($baseEff * $this->getOeeCoefficient($t), 2);

                $monthlyData[$m][$t] = $eff;
                $monthlyRaw[$m][$t]  = ['actual_output' => $actOut, 'actual_time' => $tTime];

                if (!in_array($m, $allMonths, true)) $allMonths[] = $m;
            }
            sort($allMonths);

            $viewTeams = $this->isAdmin ? $this->allTeams : $this->allowedTeams;

            // 月度折线图数据
            $chartData = [];
            $teamAvg   = [];
            foreach ($viewTeams as $t) {
                $vals  = [];
                $valid = [];
                foreach ($allMonths as $m) {
                    $v = $monthlyData[$m][$t] ?? null;
                    $vals[] = $v;
                    if ($v !== null) $valid[] = $v;
                }
                $chartData[$t] = $vals;
                $teamAvg[$t]   = !empty($valid) ? round(array_sum($valid) / count($valid), 2) : 0;
            }

            // 年度对比图数据 (1~12月 + 年度平均)
            $allYears = [];
            foreach ($allMonths as $m) {
                $y = substr($m, 0, 4);
                if (!in_array($y, $allYears, true)) $allYears[] = $y;
            }
            sort($allYears);

            $yearChartLabels = ['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月','年度平均'];
            $yearChartData   = [];
            $yearlyAvgMap    = [];

            foreach ($allYears as $y) {
                $series      = [];
                $yearOutSum  = 0;
                $yearTimeSum = 0;

                for ($mi = 1; $mi <= 12; $mi++) {
                    $mkey = $y . '-' . str_pad((string)$mi, 2, '0', STR_PAD_LEFT);
                    if ($selectedTeam === '__all__') {
                        $mSum = 0; $mCnt = 0;
                        foreach ($viewTeams as $t) {
                            if (isset($monthlyData[$mkey][$t])) { $mSum += $monthlyData[$mkey][$t]; $mCnt++; }
                            if (isset($monthlyRaw[$mkey][$t])) {
                                $yearOutSum  += $monthlyRaw[$mkey][$t]['actual_output'] * $this->getOeeCoefficient($t);
                                $yearTimeSum += $monthlyRaw[$mkey][$t]['actual_time'];
                            }
                        }
                        $series[] = $mCnt > 0 ? round($mSum / $mCnt, 2) : null;
                    } else {
                        $series[] = isset($monthlyData[$mkey][$selectedTeam]) ? round($monthlyData[$mkey][$selectedTeam], 2) : null;
                        if (isset($monthlyRaw[$mkey][$selectedTeam])) {
                            $yearOutSum  += $monthlyRaw[$mkey][$selectedTeam]['actual_output'] * $this->getOeeCoefficient($selectedTeam);
                            $yearTimeSum += $monthlyRaw[$mkey][$selectedTeam]['actual_time'];
                        }
                    }
                }

                $yAvg = ($yearTimeSum > 0) ? round($yearOutSum / $yearTimeSum, 2) : null;
                $series[] = $yAvg;
                $yearChartData[$y] = $series;
                $yearlyAvgMap[$y]  = $yAvg;
            }

            // 年度趋势研判文字
            $yearTrendLines = [];
            if (count($allYears) >= 2) {
                for ($i = 1; $i < count($allYears); $i++) {
                    $py = $allYears[$i - 1]; $cy = $allYears[$i];
                    $pv = $yearlyAvgMap[$py]; $cv = $yearlyAvgMap[$cy];
                    if ($pv !== null && $cv !== null && $pv > 0) {
                        $diff = $cv - $pv;
                        $pct = round(($diff / $pv) * 100, 2);
                        if ($pct > 0) {
                            $yearTrendLines[] = "{$cy}年 较 {$py}年 年度平均效率由 {$pv} 提升至 {$cv}，增长 {$pct}%。";
                        } elseif ($pct < 0) {
                            $yearTrendLines[] = "{$cy}年 较 {$py}年 年度平均效率由 {$pv} 下降至 {$cv}，降低 " . abs($pct) . "%。";
                        } else {
                            $yearTrendLines[] = "{$cy}年 与 {$py}年 年度平均效率持平，均为 {$cv}。";
                        }
                    }
                }
            } elseif (count($allYears) === 1) {
                $yearTrendLines[] = "当前仅有 {$allYears[0]} 年数据，暂无法进行跨年度同比分析。";
            } else {
                $yearTrendLines[] = "暂无可用数据进行年度趋势分析。";
            }

            // 最新月度波动走势
            $overallAvg     = 0;
            $prevAvg        = 0;
            $trendDirection = '持平';
            $trendPercent   = 0;
            $latestMonth    = !empty($allMonths) ? end($allMonths) : null;

            if (!empty($allMonths)) {
                $validEffList = [];
                $prevEffList  = [];

                foreach ($allMonths as $m) {
                    $val = null;
                    if ($selectedTeam === '__all__') {
                        $sum = 0; $cnt = 0;
                        foreach ($viewTeams as $t) {
                            if (isset($monthlyData[$m][$t])) { $sum += $monthlyData[$m][$t]; $cnt++; }
                        }
                        if ($cnt > 0) $val = $sum / $cnt;
                    } else {
                        $val = $monthlyData[$m][$selectedTeam] ?? null;
                    }

                    if ($val !== null) {
                        $validEffList[] = $val;
                        if ($m !== $latestMonth) $prevEffList[] = $val;
                    }
                }

                if (!empty($validEffList)) $overallAvg = round(array_sum($validEffList) / count($validEffList), 2);
                if (!empty($prevEffList))  $prevAvg    = round(array_sum($prevEffList) / count($prevEffList), 2);

                if ($prevAvg > 0) {
                    $diff = $overallAvg - $prevAvg;
                    $trendPercent = round(($diff / $prevAvg) * 100, 2);
                    if ($trendPercent > 0) $trendDirection = '增长';
                    elseif ($trendPercent < 0) $trendDirection = '降低';
                    else $trendDirection = '持平';
                }
            }

            Response::success('success', [
                'months'            => $allMonths,
                'teams'             => $viewTeams,
                'selected_team'     => $selectedTeam,
                'chart_data'        => $chartData,
                'team_avg'          => $teamAvg,
                'overall_avg'       => $overallAvg,
                'prev_avg'          => $prevAvg,
                'trend_direction'   => $trendDirection,
                'trend_percent'     => abs($trendPercent),
                'latest_month'      => $latestMonth,
                'year_chart_labels' => $yearChartLabels,
                'year_chart_data'   => $yearChartData,
                'all_years'         => $allYears,
                'year_trend_lines'  => $yearTrendLines
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取看板数据失败');
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
            $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];

            if (empty($ids)) {
                Response::error('请选择要删除的记录');
                return;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("DELETE FROM oee_records WHERE id IN ($placeholders)");
            $stmt->execute(array_map('intval', $ids));
            $count = $stmt->rowCount();

            AuditService::log('班组效率', '删除效率记录');
            Response::success("成功删除 {$count} 条效率记录", ['count' => $count]);
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }

    public function export(): void
    {
        try {
            $filterTeam  = trim((string)($_GET['filter_team'] ?? ''));
            $filterStart = trim((string)($_GET['filter_start'] ?? ''));
            $filterEnd   = trim((string)($_GET['filter_end'] ?? ''));

            $perm = $this->buildPermissionWhere();
            $where = [$perm['sql']];
            $params = $perm['params'];

            if ($filterTeam !== '') {
                if ($this->isAdmin || in_array($filterTeam, $this->allowedTeams, true)) {
                    $where[] = "r.team = ?";
                    $params[] = $filterTeam;
                }
            }
            if ($filterStart !== '') { $where[] = "r.stat_month >= ?"; $params[] = $filterStart; }
            if ($filterEnd !== '')   { $where[] = "r.stat_month <= ?"; $params[] = $filterEnd; }

            $whereSql = implode(' AND ', $where);
            $sql = "SELECT r.*, COALESCE(u.full_name, u.username, '用户') AS creator_name
                    FROM oee_records r 
                    LEFT JOIN users u ON r.created_by = u.id 
                    WHERE {$whereSql} 
                    ORDER BY r.stat_month DESC, r.created_at DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $headers = ['班组', '统计月份', '总产量', '委外产量', '实际产量', '实际用时(小时)', '效率', '备注', '创建人', '创建时间'];

            // 优先导出 Excel（服务器已安装 PhpSpreadsheet）
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('OEE班组效率记录');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($rows as $r) {
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['team'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)$r['stat_month'], DataType::TYPE_STRING);
                    $sheet->setCellValue('C' . $line, (float)$r['total_output']);
                    $sheet->setCellValue('D' . $line, (float)$r['outsourced_output']);
                    $sheet->setCellValue('E' . $line, (float)$r['actual_output']);
                    $sheet->setCellValue('F' . $line, (float)$r['actual_time']);
                    $sheet->setCellValue('G' . $line, (float)$r['efficiency']);
                    $sheet->setCellValueExplicit('H' . $line, (string)($r['remarks'] ?: '-'), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('I' . $line, (string)$r['creator_name'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('J' . $line, (string)$r['created_at'], DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:J1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'J') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = 'OEE班组效率记录_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="OEE班组效率记录_' . date('Ymd_His') . '.csv"');

            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, array_map('excelSafe', $headers));

            foreach ($rows as $r) {
                fputcsv($output, array_map('excelSafe', [
                    $r['team'],
                    $r['stat_month'],
                    number_format((float)$r['total_output'], 2),
                    number_format((float)$r['outsourced_output'], 2),
                    number_format((float)$r['actual_output'], 2),
                    number_format((float)$r['actual_time'], 2),
                    number_format((float)$r['efficiency'], 2),
                    $r['remarks'] ?: '-',
                    $r['creator_name'],
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