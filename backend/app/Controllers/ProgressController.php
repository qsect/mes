<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use PDO;
use PDOException;
use Throwable;

class ProgressController
{
    private array $currentUser;
    private array $targetZyzx;

    public function __construct()
    {
        if (empty($_SESSION['user'])) {
            Response::error("请先登录", [], 401);
            exit;
        }

        $this->currentUser = $_SESSION['user'];
        $this->targetZyzx = ['装置装配', '装置调试', '装置包装', '装置检验'];
    }

    private function getMesConnection(): ?PDO
    {
        try {
            // ★ 凭据一律取自 .env（无源码兜底），配置缺失直接拒绝连接
            $cfg = \App\Core\ExternalDb::mesConfig();
            if ($cfg === null) return null;
            [$mes_host, $mes_port, $mes_db, $mes_user, $mes_pass] = $cfg;

            return new PDO("mysql:host={$mes_host};port={$mes_port};dbname={$mes_db};charset=utf8mb4", $mes_user, $mes_pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => true,
                PDO::ATTR_TIMEOUT            => 8
            ]);
        } catch (PDOException $e) {
            return null;
        }
    }

    public function query(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;

        $query = trim((string)($input['q'] ?? ''));
        if ($query === '') {
            Response::error('请输入 SN 或工单号');
            return;
        }

        $pdo = $this->getMesConnection();
        if (!$pdo) {
            Response::error('MES 生产数据源连接失败，请检查 .env 配置');
            return;
        }

        $sn_history          = [];
        $work_order_progress = [];
        $sn_current          = null;
        $task_info           = null;
        $total_sn_count      = 0;

        $is_sn = (preg_match('/^\d+$/', $query) && strlen($query) >= 16);

        try {
            if ($is_sn) {
                // 1. 获取 SN 当前最新状态
                $sql = "SELECT lj.*, gx.name AS gx_name, node.name AS node_name, zy.name AS zyzx_name
                        FROM tab_log_job lj
                        LEFT JOIN tab_base_gx gx ON lj.gx_id = gx.id
                        LEFT JOIN tab_base_node node ON lj.node_id = node.id
                        LEFT JOIN tab_base_zyzx zy ON lj.zyzx_id = zy.id
                        WHERE lj.sn = :sn
                        ORDER BY lj.update_time DESC LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':sn' => $query]);
                $sn_current = $stmt->fetch();

                if (!$sn_current) {
                    Response::error('未找到该 SN 的信息，请确认 SN 是否正确');
                    return;
                }

                $task_no = (string)$sn_current['task_no'];

                // 2. 获取工单基本信息与工单计划总数 (优先以 task_number 为准)
                $sqlPlan = "SELECT task_no, mater_no, mater_desc, task_number, plan_start, plan_end FROM tab_plan WHERE task_no = :task_no";
                $stmtPlan = $pdo->prepare($sqlPlan);
                $stmtPlan->execute([':task_no' => $task_no]);
                $task_info = $stmtPlan->fetch();

                if (!$task_info) {
                    $sqlJob = "SELECT DISTINCT task_no, mater_no, mater_desc, task_number FROM tab_job WHERE task_no = :task_no";
                    $stmtJob = $pdo->prepare($sqlJob);
                    $stmtJob->execute([':task_no' => $task_no]);
                    $task_info = $stmtJob->fetch();
                }

                // 3. SN 工序历史明细
                $zyzx_placeholders = [];
                $zyzx_params = [];
                foreach ($this->targetZyzx as $idx => $name) {
                    $key = ':zyzx_' . $idx;
                    $zyzx_placeholders[] = $key;
                    $zyzx_params[$key] = $name;
                }
                $zyzx_in = implode(',', $zyzx_placeholders);

                $sqlHistory = "SELECT ls.gx_id, gx.name AS gx_name, ls.gy_name, ls.result, ls.update_time AS last_time
                               FROM tab_log_step ls
                               INNER JOIN (
                                   SELECT sn, gx_id, MAX(id) AS max_id
                                   FROM tab_log_step
                                   WHERE sn = :sn
                                   GROUP BY sn, gx_id
                               ) latest ON ls.id = latest.max_id
                               JOIN tab_base_gx gx ON ls.gx_id = gx.id
                               JOIN tab_base_zyzx zy ON gx.zyzx_id = zy.id
                               WHERE zy.name IN ($zyzx_in)
                                 AND gx.name != '标识打印'
                               ORDER BY ls.update_time ASC, ls.id ASC";

                $stmtHist = $pdo->prepare($sqlHistory);
                $hist_params = array_merge([':sn' => $query], $zyzx_params);
                $stmtHist->execute($hist_params);
                $sn_history = $stmtHist->fetchAll();

                // 4. 总 SN 计划数统计
                $planQty = isset($task_info['task_number']) ? (int)$task_info['task_number'] : 0;
                if ($planQty > 0) {
                    $total_sn_count = $planQty;
                } else {
                    $sqlTotal = "SELECT COUNT(DISTINCT sn) AS total FROM tab_log_job WHERE task_no = :task_no";
                    $stmtTotal = $pdo->prepare($sqlTotal);
                    $stmtTotal->execute([':task_no' => $task_no]);
                    $total_sn_count = (int)$stmtTotal->fetchColumn();
                }
            } else {
                // 工单号查询
                $task_no = $query;
                $sqlPlan = "SELECT task_no, mater_no, mater_desc, task_number, plan_start, plan_end FROM tab_plan WHERE task_no = :task_no";
                $stmtPlan = $pdo->prepare($sqlPlan);
                $stmtPlan->execute([':task_no' => $task_no]);
                $task_info = $stmtPlan->fetch();

                if (!$task_info) {
                    $sqlJob = "SELECT DISTINCT task_no, mater_no, mater_desc, task_number FROM tab_job WHERE task_no = :task_no";
                    $stmtJob = $pdo->prepare($sqlJob);
                    $stmtJob->execute([':task_no' => $task_no]);
                    $task_info = $stmtJob->fetch();
                }

                if ($task_info) {
                    $planQty = isset($task_info['task_number']) ? (int)$task_info['task_number'] : 0;
                    if ($planQty > 0) {
                        $total_sn_count = $planQty;
                    } else {
                        $sqlTotal = "SELECT COUNT(DISTINCT sn) AS total FROM tab_log_job WHERE task_no = :task_no";
                        $stmtTotal = $pdo->prepare($sqlTotal);
                        $stmtTotal->execute([':task_no' => $task_no]);
                        $total_sn_count = (int)$stmtTotal->fetchColumn();
                    }
                } else {
                    Response::error('未找到该工单号的信息');
                    return;
                }
            }

            // 5. 工单各工序完成进度汇总
            if ($task_info && isset($task_no) && $total_sn_count > 0) {
                $zyzx_placeholders = [];
                $zyzx_params = [];
                foreach ($this->targetZyzx as $idx => $name) {
                    $key = ':zyzx_' . $idx;
                    $zyzx_placeholders[] = $key;
                    $zyzx_params[$key] = $name;
                }
                $zyzx_in_clause = implode(',', $zyzx_placeholders);

                $sqlRoute = "SELECT j.gx_id, MIN(j.seq) AS seq, gx.name AS gx_name
                             FROM tab_job j
                             JOIN tab_base_gx gx ON j.gx_id = gx.id
                             JOIN tab_base_zyzx zy ON j.zyzx_id = zy.id
                             WHERE j.task_no = :task_no
                               AND zy.name IN ($zyzx_in_clause)
                               AND gx.name != '标识打印'
                             GROUP BY j.gx_id, gx.name
                             ORDER BY seq ASC";
                $stmtRoute = $pdo->prepare($sqlRoute);
                $route_params = array_merge([':task_no' => $task_no], $zyzx_params);
                $stmtRoute->execute($route_params);
                $routeProcesses = $stmtRoute->fetchAll();

                if (!empty($routeProcesses)) {
                    $gx_ids = array_column($routeProcesses, 'gx_id');
                    $gx_placeholders = [];
                    $gx_params = [];
                    foreach ($gx_ids as $idx => $gx_id) {
                        $key = ':gx_' . $idx;
                        $gx_placeholders[] = $key;
                        $gx_params[$key] = $gx_id;
                    }
                    $gx_in_clause = implode(',', $gx_placeholders);

                    $sqlProgress = "SELECT ls.gx_id, COUNT(DISTINCT ls.sn) AS completed_count
                                    FROM tab_log_step ls
                                    JOIN tab_base_node node ON ls.node_id = node.id
                                    WHERE ls.task_no = :task_no
                                      AND ls.result = 1
                                      AND node.pos = 2
                                      AND ls.gx_id IN ($gx_in_clause)
                                      AND ls.sn IN (SELECT DISTINCT sn FROM tab_log_job WHERE task_no = :task_no_sub)
                                    GROUP BY ls.gx_id";
                    $stmtProg = $pdo->prepare($sqlProgress);
                    $progress_params = array_merge([':task_no' => $task_no, ':task_no_sub' => $task_no], $gx_params);
                    $stmtProg->execute($progress_params);
                    $progressRows = $stmtProg->fetchAll();

                    $progress_map = [];
                    foreach ($progressRows as $row) {
                        $progress_map[$row['gx_id']] = (int)$row['completed_count'];
                    }

                    foreach ($routeProcesses as $rp) {
                        $gx_id = $rp['gx_id'];
                        $gx_name = $rp['gx_name'];
                        $completed = $progress_map[$gx_id] ?? 0;
                        $work_order_progress[] = [
                            'gx_name'         => $gx_name,
                            'completed_count' => $completed
                        ];
                    }

                    // 智能判定修正 SN 节点状态
                    if ($is_sn && $sn_current) {
                        $curr_gx_id = $sn_current['gx_id'] ?? null;
                        $is_current_gx_done = false;

                        if ($curr_gx_id) {
                            $curr_completed = $progress_map[$curr_gx_id] ?? 0;
                            if ($total_sn_count > 0 && $curr_completed >= $total_sn_count) {
                                $is_current_gx_done = true;
                            }

                            if (!$is_current_gx_done) {
                                $sqlCheckSnEnd = "SELECT 1 FROM tab_log_step ls
                                                  JOIN tab_base_node node ON ls.node_id = node.id
                                                  WHERE ls.sn = :sn 
                                                    AND ls.gx_id = :gx_id 
                                                    AND node.pos = 2 
                                                    AND ls.result = 1
                                                  LIMIT 1";
                                $stmtCheckSnEnd = $pdo->prepare($sqlCheckSnEnd);
                                $stmtCheckSnEnd->execute([':sn' => $query, ':gx_id' => $curr_gx_id]);
                                if ($stmtCheckSnEnd->fetchColumn()) {
                                    $is_current_gx_done = true;
                                }
                            }
                        }

                        if ($is_current_gx_done) {
                            $sn_current['en'] = 2;
                        }
                    }
                }
            }

            Response::success('success', [
                'is_sn'               => $is_sn,
                'task_info'           => $task_info,
                'sn_current'          => $sn_current,
                'sn_history'          => $sn_history,
                'work_order_progress' => $work_order_progress,
                'total_sn_count'      => $total_sn_count
            ]);

        } catch (Throwable $e) {
            Response::exception($e, '查询异常');
        }
    }
}