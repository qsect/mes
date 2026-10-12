<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\ModulePerm;
use App\Core\Response;
use App\Services\AuditService;
use PDO;
use Throwable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class WeeklyController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $validProcesses;

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

        $this->validProcesses = [
            '装置装配', '外协装配', '厂站调试', '配网调试', 
            '综合调试', '自动测试', '调试外协', '装置包装', '包装发货'
        ];

        \App\Core\Schema::ensureOnce('weekly', function () { $this->initTables(); });
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `production_progress` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `week_number` VARCHAR(20) NOT NULL,
                `process` VARCHAR(50) NOT NULL,
                `worker_count` DECIMAL(12,2) NOT NULL,
                `hours_used` DECIMAL(12,2) NOT NULL,
                `standard_device` DECIMAL(12,2) NOT NULL,
                `weekly_output_per_person` DECIMAL(12,2),
                `hourly_output` DECIMAL(12,2),
                `remarks` TEXT,
                `created_by` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '用户名(username)',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_week_number` (`week_number`),
                KEY `idx_process` (`process`),
                KEY `idx_created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("ALTER TABLE `production_progress` MODIFY `created_by` VARCHAR(64) NOT NULL DEFAULT ''");
        } catch (Throwable $e) {}
    }

    private function getAllowedProcesses(): array
    {
        if ($this->isAdmin) {
            return $this->validProcesses;
        }

        $username = (string)($this->currentUser['username'] ?? '');

        if (str_contains($username, '孙远超')) return ['装置装配', '外协装配'];
        if (str_contains($username, '刘少俊')) return ['厂站调试', '配网调试', '综合调试', '调试外协', '自动测试'];
        if (str_contains($username, '鲍敏')) return ['厂站调试'];
        if (str_contains($username, '徐祥志')) return ['配网调试', '自动测试'];
        if (str_contains($username, '楚永杰')) return ['装置包装', '包装发货'];

        return [];
    }

    /**
     * 可"查看"的工序范围（通常 >= 可"填写"范围）
     * 例如：孙远超可填写 装置装配/外协装配，但可查看 装置装配/外协装配/装置包装/包装发货
     */
    private function getViewProcesses(): array
    {
        if ($this->isAdmin) {
            return $this->validProcesses;
        }

        $id = (string)($this->currentUser['username'] ?? '') . ' ' . (string)($this->currentUser['full_name'] ?? '');

        if (str_contains($id, '孙远超')) return ['装置装配', '外协装配', '装置包装', '包装发货'];
        if (str_contains($id, '刘少俊')) return ['厂站调试', '配网调试', '综合调试', '调试外协', '自动测试'];
        if (str_contains($id, '鲍敏')) return ['厂站调试'];
        if (str_contains($id, '徐祥志')) return ['配网调试', '自动测试'];
        if (str_contains($id, '楚永杰')) return ['装置包装', '包装发货'];

        return [];
    }

    private function buildPermissionWhere(): array
    {
        if ($this->isAdmin) {
            return ['sql' => '1=1', 'params' => []];
        }

        // ★ 列表/看板/导出按"可查看工序"范围过滤
        $allowed = $this->getViewProcesses();
        $myUsername = (string)($this->currentUser['username'] ?? '');

        if (!empty($allowed)) {
            $procHolders = implode(',', array_fill(0, count($allowed), '?'));
            $sql = "((process IN ($procHolders)) OR created_by = ?)";
            return ['sql' => $sql, 'params' => array_merge($allowed, [$myUsername])];
        }

        return ['sql' => "created_by = ?", 'params' => [$myUsername]];
    }

    public function filterOptions(): void
    {
        try {
            $perm = $this->buildPermissionWhere();
            $allowed = $this->getAllowedProcesses();

            $wStmt = $this->db->prepare("SELECT DISTINCT week_number FROM production_progress WHERE {$perm['sql']} ORDER BY week_number DESC LIMIT 60");
            $wStmt->execute($perm['params']);
            $weeks = $wStmt->fetchAll(PDO::FETCH_COLUMN);

            $pStmt = $this->db->prepare("SELECT DISTINCT process FROM production_progress WHERE {$perm['sql']} ORDER BY process ASC");
            $pStmt->execute($perm['params']);
            $processes = $pStmt->fetchAll(PDO::FETCH_COLUMN);

            Response::success('success', [
                'weeks'             => $weeks,
                'processes'         => $processes,
                'allowed_processes' => $allowed,          // 可填写工序（用于新增表单）
                'view_processes'    => $this->getViewProcesses(), // 可查看工序（用于筛选下拉）
                'valid_processes'   => $this->validProcesses,
                'is_admin'          => $this->isAdmin,
                'can_manage'        => ($this->isAdmin || ModulePerm::canEdit($this->currentUser, 'weekly'))
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取筛选选项失败');
        }
    }

    public function list(): void
    {
        try {
            $search  = trim((string)($_GET['search_all'] ?? ''));
            $week    = trim((string)($_GET['filter_week'] ?? ''));
            $proc    = trim((string)($_GET['filter_process'] ?? ''));
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 30;
            $offset  = ($page - 1) * $perPage;

            $allowed = $this->getAllowedProcesses();
            $perm = $this->buildPermissionWhere();
            $where = [$perm['sql']];
            $params = $perm['params'];

            if ($search !== '') {
                $kw = "%{$search}%";
                $where[] = "(pp.week_number LIKE ? OR pp.remarks LIKE ? OR pp.created_by LIKE ?)";
                $params[] = $kw;
                $params[] = $kw;
                $params[] = $kw;
            }
            if ($week !== '') {
                $where[] = "pp.week_number = ?";
                $params[] = $week;
            }
            if ($proc !== '') {
                $where[] = "pp.process = ?";
                $params[] = $proc;
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM production_progress pp WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();
            $totalPages   = (int)ceil($totalRecords / $perPage);

            // 直接查询原始 created_by，不关联 users.id
            $sql = "SELECT pp.* 
                    FROM production_progress pp
                    WHERE {$whereSql}
                    ORDER BY pp.week_number DESC, pp.process ASC, pp.created_at DESC
                    LIMIT {$offset}, {$perPage}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $currentUsername = (string)($this->currentUser['username'] ?? '');

            foreach ($records as &$r) {
                $r['creator_name'] = (string)$r['created_by'];

                $isOwner = ($r['creator_name'] === $currentUsername);
                $hasProcessAuth = in_array($r['process'], $allowed, true);
                $canOperate = ($this->isAdmin or $hasProcessAuth or $isOwner);

                $r['can_edit'] = $canOperate;
                $r['can_delete'] = $canOperate;
            }
            unset($r);

            Response::success('success', [
                'records'           => $records,
                'total_records'     => $totalRecords,
                'total_pages'       => $totalPages,
                'current_page'      => $page,
                'is_admin'          => $this->isAdmin,
                // ★ 「可删除任意记录」的能力标记（管理员或被授权），用于前端按钮级隐藏
                'can_manage'        => ($this->isAdmin || ModulePerm::canEdit($this->currentUser, 'weekly')),
                'allowed_processes' => $allowed
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取列表失败');
        }
    }

    public function dashboardData(): void
    {
        try {
            $perm = $this->buildPermissionWhere();

            $sql = "SELECT 
                        week_number,
                        process,
                        SUM(standard_device) AS sum_std,
                        SUM(hours_used) AS sum_hours,
                        COUNT(*) AS cnt
                    FROM production_progress
                    WHERE {$perm['sql']}
                    GROUP BY week_number, process
                    ORDER BY week_number ASC, process ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($perm['params']);
            $trendRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $weeksSet = [];
            $processSummaryMap = [];
            $processData = [];
            $grandTotalStd = 0.0;
            $grandTotalHours = 0.0;
            $grandTotalCount = 0;

            foreach ($trendRows as $row) {
                $w     = (string)$row['week_number'];
                $p     = (string)$row['process'];
                $std   = (float)$row['sum_std'];
                $hours = (float)$row['sum_hours'];
                $cnt   = (int)$row['cnt'];

                $weeksSet[$w] = true;

                if (!isset($processSummaryMap[$p])) {
                    $processSummaryMap[$p] = [
                        'process'               => $p,
                        'total_standard_device' => 0.0,
                        'total_hours_used'      => 0.0,
                        'record_count'          => 0
                    ];
                }

                $processSummaryMap[$p]['total_standard_device'] += $std;
                $processSummaryMap[$p]['total_hours_used']      += $hours;
                $processSummaryMap[$p]['record_count']          += $cnt;

                $grandTotalStd += $std;
                $grandTotalHours += $hours;
                $grandTotalCount += $cnt;

                $avg = ($hours > 0) ? round($std / $hours, 2) : 0.0;
                $processData[$p][$w] = $avg;
            }

            $dashboardData = [];
            foreach ($processSummaryMap as $row) {
                $row['avg_hourly_output'] = ($row['total_hours_used'] > 0) ? round($row['total_standard_device'] / $row['total_hours_used'], 2) : 0.0;
                $dashboardData[] = $row;
            }
            usort($dashboardData, fn($a, $b) => strcmp($a['process'], $b['process']));

            $overallAvg = ($grandTotalHours > 0) ? round($grandTotalStd / $grandTotalHours, 2) : 0.0;

            $weeks = array_keys($weeksSet);
            sort($weeks);

            $processesInView = array_keys($processSummaryMap);
            sort($processesInView);

            $chartData = [];
            $processAvg = [];
            foreach ($processesInView as $p) {
                $vals = [];
                $validVals = [];
                foreach ($weeks as $w) {
                    $v = $processData[$p][$w] ?? null;
                    $vals[] = $v;
                    if ($v !== null) $validVals[] = $v;
                }
                $chartData[$p] = $vals;
                $processAvg[$p] = !empty($validVals) ? round(array_sum($validVals) / count($validVals), 2) : 0.0;
            }

            $trendDirection = '持平';
            $trendPercent = 0.0;
            $overallAvgPrev = 0.0;

            if (!empty($weeks)) {
                $latestWeek = end($weeks);
                $prevStd = 0.0;
                $prevHours = 0.0;

                foreach ($trendRows as $r) {
                    if ($r['week_number'] !== $latestWeek) {
                        $prevStd += (float)$r['sum_std'];
                        $prevHours += (float)$r['sum_hours'];
                    }
                }

                $overallAvgPrev = ($prevHours > 0) ? round($prevStd / $prevHours, 2) : 0.0;
                if ($overallAvgPrev > 0) {
                    $diff = $overallAvg - $overallAvgPrev;
                    $trendPercent = round(($diff / $overallAvgPrev) * 100, 2);
                    if ($trendPercent > 0)$trendDirection = '增长';
                    elseif ($trendPercent < 0)$trendDirection = '降低';
                    else $trendDirection = '持平';
                }
            }

            Response::success('success', [
                'dashboard_data'    => $dashboardData,
                'grand_total_std'   => $grandTotalStd,
                'grand_total_hours' => $grandTotalHours,
                'grand_total_count' => $grandTotalCount,
                'overall_avg'       => $overallAvg,
                'overall_avg_prev'  => $overallAvgPrev,
                'trend_direction'   => $trendDirection,
                'trend_percent'     => abs($trendPercent),
                'weeks'             => $weeks,
                'processes'         => $processesInView,
                'chart_data'        => $chartData,
                'process_avg'       => $processAvg
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取看板数据失败');
        }
    }

    public function add(): void
    {
        try {
            $raw = file_get_contents('php://input');$input = json_decode($raw, true) ?:$_POST;

            $week_number     = trim((string)($input['week_number'] ?? ''));
            $process         = trim((string)($input['process'] ?? ''));
            $worker_count    = round((float)($input['worker_count'] ?? 0), 2);
            $hours_used      = round((float)($input['hours_used'] ?? 0), 2);
            $standard_device = round((float)($input['standard_device'] ?? 0), 2);
            $remarks         = trim((string)($input['remarks'] ?? ''));

            if (empty($week_number) or empty($process)) {
                Response::error('周次和工序为必填项');
                return;
            }
            if (!preg_match('/^\d{4}-W\d{2}$/',$week_number)) {
                Response::error('周次格式不正确，应为 2026-W01 格式');
                return;
            }
            if (!in_array($process,$this->validProcesses, true)) {
                Response::error('选择的工序无效');
                return;
            }

            $allowed =$this->getAllowedProcesses();
            if (!$this->isAdmin and !in_array($process,$allowed, true)) {
                Response::error('您无权录入该工序的数据', [], 403);
                return;
            }

            if ($worker_count < 0 or $hours_used < 0 or$standard_device < 0) {
                Response::error('数量与用时不能为负数');
                return;
            }

            $weekly_output = ($worker_count > 0) ? round($standard_device / $worker_count, 2) : 0.0;
            $hourly_output = ($hours_used > 0) ? round($standard_device / $hours_used, 2) : 0.0;

            // 严格以当前登录用户的 username 直接入库
            $currentUsername = (string)($this->currentUser['username'] ?? '');

            $sql = "INSERT INTO production_progress 
                (week_number, process, worker_count, hours_used, standard_device, weekly_output_per_person, hourly_output, remarks, created_by, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$week_number, $process,$worker_count, $hours_used,$standard_device, $weekly_output,$hourly_output, $remarks,$currentUsername
            ]);

            AuditService::log('周完成统计', "新增周进度 [{$week_number} / {$process} / {$currentUsername}]");
            Response::success('记录添加成功', ['id' => (int)$this->db->lastInsertId()]);
        } catch (Throwable $e) {
            Response::exception($e, '添加失败');
        }
    }

    public function update(): void
    {
        try {
            $raw = file_get_contents('php://input');$input = json_decode($raw, true) ?:$_POST;

            $id = (int)($input['record_id'] ?? ($input['id'] ?? 0));
            if ($id <= 0) {
                Response::error('无效的记录ID');
                return;
            }

            $chkStmt =$this->db->prepare("SELECT process, created_by FROM production_progress WHERE id = ?");
            $chkStmt->execute([$id]);
            $row =$chkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                Response::error('记录不存在');
                return;
            }

            $allowed =$this->getAllowedProcesses();
            $hasProcessAuth = in_array($row['process'], $allowed, true);$currentUsername = (string)($this->currentUser['username'] ?? '');$isOwner = ((string)$row['created_by'] ===$currentUsername);

            if (!$this->isAdmin and !$hasProcessAuth and !$isOwner) {
                Response::error('您没有权限编辑该记录', [], 403);
                return;
            }

            $week_number     = trim((string)($input['week_number'] ?? ''));
            $process         = trim((string)($input['process'] ?? ''));
            $worker_count    = round((float)($input['worker_count'] ?? 0), 2);
            $hours_used      = round((float)($input['hours_used'] ?? 0), 2);
            $standard_device = round((float)($input['standard_device'] ?? 0), 2);
            $remarks         = trim((string)($input['remarks'] ?? ''));

            if (empty($week_number) or empty($process)) {
                Response::error('周次和工序为必填项');
                return;
            }
            if (!preg_match('/^\d{4}-W\d{2}$/',$week_number)) {
                Response::error('周次格式不正确');
                return;
            }
            if (!in_array($process,$this->validProcesses, true)) {
                Response::error('工序无效');
                return;
            }

            if (!$this->isAdmin and !in_array($process,$allowed, true) and $process !==$row['process']) {
                Response::error('您不能将记录修改为无权限的工序', [], 403);
                return;
            }

            $weekly_output = ($worker_count > 0) ? round($standard_device / $worker_count, 2) : 0.0;
            $hourly_output = ($hours_used > 0) ? round($standard_device / $hours_used, 2) : 0.0;

            $sql = "UPDATE production_progress SET 
                week_number = ?, process = ?, worker_count = ?, hours_used = ?, 
                standard_device = ?, weekly_output_per_person = ?, hourly_output = ?, 
                remarks = ?, updated_at = NOW() 
                WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$week_number, $process,$worker_count, $hours_used,$standard_device, $weekly_output,$hourly_output, $remarks,$id
            ]);

            AuditService::log('周完成统计', "修改周进度 [ID:{$id} / {$week_number}]");
            Response::success('记录修改成功');
        } catch (Throwable $e) {
            Response::exception($e, '更新失败');
        }
    }

    public function delete(): void
    {
        try {
            $raw = file_get_contents('php://input');$input = json_decode($raw, true) ?:$_POST;

            $ids = array_filter(array_map('intval', (array)($input['ids'] ?? [])));
            if (empty($ids)) {
                Response::error('请选择合法的记录ID');
                return;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // ★ 权限加固：管理员或被授权（权限分配→允许编辑与数据管理）的用户可删除任意记录；
            //   未被授权的普通用户仍保留原有「仅能删除自己/本工序记录」的受限路径。
            if ($this->isAdmin || ModulePerm::canEdit($this->currentUser, 'weekly')) {
                $stmt =$this->db->prepare("DELETE FROM production_progress WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $count =$stmt->rowCount();
            } else {
                $allowed =$this->getAllowedProcesses();
                $currentUsername = (string)($this->currentUser['username'] ?? '');

                $subWhere = ["created_by = ?"];
                $subParams = [$currentUsername];

                if (!empty($allowed)) {$procHolders = implode(',', array_fill(0, count($allowed), '?'));$subWhere[] = "process IN ($procHolders)";
                    $subParams = array_merge($subParams,$allowed);
                }

                $permCondition = "(" . implode(' OR ', $subWhere) . ")";
                $sql = "DELETE FROM production_progress WHERE id IN ($placeholders) AND$permCondition";
                $allParams = array_merge($ids,$subParams);

                $stmt = $this->db->prepare($sql);
                $stmt->execute($allParams);
                $count =$stmt->rowCount();

                if ($count === 0) {
                    Response::error('删除失败：您无权删除所选的记录', [], 403);
                    return;
                }
            }

            AuditService::log('周完成统计', "删除 {$count} 条周进度记录");
            Response::success("成功删除 {$count} 条生产进度记录", ['count' => $count]);
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }

    public function export(): void
    {
        try {
            $search = trim((string)($_GET['search_all'] ?? ''));
            $week   = trim((string)($_GET['filter_week'] ?? ''));
            $proc   = trim((string)($_GET['filter_process'] ?? ''));

            $perm =$this->buildPermissionWhere();
            $where = [$perm['sql']];
            $params =$perm['params'];

            if ($search !== '') {
                $kw = "%{$search}%";
                $where[] = "(pp.week_number LIKE ? OR pp.remarks LIKE ? OR pp.created_by LIKE ?)";
                $params[] =$kw; $params[] =$kw; $params[] =$kw;
            }
            if ($week !== '') {$where[] = "pp.week_number = ?";
                $params[] =$week;
            }
            if ($proc !== '') {$where[] = "pp.process = ?";
                $params[] =$proc;
            }

            $whereSql = implode(' AND ', $where);$sql = "SELECT pp.* 
                    FROM production_progress pp
                    WHERE {$whereSql}
                    ORDER BY pp.week_number DESC, pp.process ASC, pp.created_at DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows =$stmt->fetchAll(PDO::FETCH_ASSOC);

            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();$sheet = $spreadsheet->getActiveSheet();$sheet->setTitle('生产进度周报');

                $headers = ['周次', '工序', '用人数量', '用时(小时)', '标准装置', '人均周产出量', '单位小时产出量', '备注说明', '操作者', '创建时间'];
                $sheet->fromArray($headers, NULL, 'A1');

                $line = 2;
                foreach ($rows as $r) {$sheet->setCellValueExplicit('A' . $line,$r['week_number'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' .$line, $r['process'], DataType::TYPE_STRING);$sheet->setCellValue('C' . $line, (float)$r['worker_count']);
                    $sheet->setCellValue('D' .$line, (float)$r['hours_used']);$sheet->setCellValue('E' . $line, (float)$r['standard_device']);
                    $sheet->setCellValue('F' .$line, (float)($r['weekly_output_per_person'] ?: 0));$sheet->setCellValue('G' . $line, (float)($r['hourly_output'] ?: 0));
                    $sheet->setCellValueExplicit('H' .$line, $r['remarks'] ?: '-', DataType::TYPE_STRING);$sheet->setCellValueExplicit('I' . $line, (string)$r['created_by'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('J' .$line, $r['created_at'], DataType::TYPE_STRING);$line++;
                }

                $sheet->getStyle('A1:J1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');$sheet->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');

                foreach (range('A', 'J') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = '周完成情况统计_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');

                $writer = new Xlsx($spreadsheet);$writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="周完成情况统计_' . date('Ymd_His') . '.csv"');
            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, array_map('excelSafe', ['周次', '工序', '用人数量', '用时(小时)', '标准装置', '人均周产出量', '单位小时产出量', '备注说明', '操作者', '创建时间']));
            foreach ($rows as$r) {
                fputcsv($output, array_map('excelSafe', [
                    $r['week_number'],$r['process'], $r['worker_count'],$r['hours_used'],
                    $r['standard_device'],$r['weekly_output_per_person'], $r['hourly_output'],$r['remarks'], (string)$r['created_by'],$r['created_at']
                ]));
            }
            fclose($output);
            exit;
        } catch (Throwable $e) {
            Response::exception($e, '导出失败');
        }
    }
}