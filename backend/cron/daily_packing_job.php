<?php
// cron/daily_packing_job.php - 包装进度刷新 + 完工归档（合并任务）
//
// ★ 安全加固：本脚本仅允许命令行执行。
//   原代码用 $_GET['token'] !== 'YOUR_CRON_SECURE_KEY' 做保护，但那是模板占位符、
//   从未替换成真实口令，属"自欺欺人的死代码"；而且紧随其后的 bootstrap.php
//   本身就会对任何非 CLI 请求返回 403，URL 触发从设计上就不可能成功。
//   这里改为显式 CLI-only，语义清晰、无占位符残留。
//
//   正确的计划任务配置方式（宝塔 → 计划任务 → Shell 脚本）：
//   /www/server/php/82/bin/php /www/wwwroot/vite/backend/cron/daily_packing_job.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/bootstrap.php';

$pdo_biz = function_exists('getDB')    ? getDB()    : null;
$pdo_mes = function_exists('getMesDB') ? getMesDB() : null;

if (!$pdo_biz) die("业务数据库连接失败\n");
if (!$pdo_mes) die("MES 数据库连接失败\n");

$pdo_biz->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$pdo_mes->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

$jobStart = microtime(true);
echo str_repeat('=', 60) . "\n";
echo "[" . date('Y-m-d H:i:s') . "] 开始执行【包装进度刷新 + 完工归档】\n";
echo str_repeat('=', 60) . "\n";

// ============================================================
// 阶段 1：刷新包装进度缓存
// ============================================================
echo "\n[阶段 1] 刷新包装进度缓存\n";
echo str_repeat('-', 60) . "\n";

try {
    $pdo_biz->exec("CREATE TABLE IF NOT EXISTS `tab_wo_packing_progress` (
      `wo` VARCHAR(64) PRIMARY KEY,
      `done_qty` INT NOT NULL DEFAULT 0,
      `cached_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_cached_at` (`cached_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $sql = "SELECT DISTINCT r.wo 
            FROM tab_delivery_receipt r 
            LEFT JOIN tab_work_order_material_archive a ON r.wo = a.wo 
            WHERE a.wo IS NULL OR a.is_finished = 0";
    $wos = $pdo_biz->query($sql)->fetchAll(PDO::FETCH_COLUMN, 0);

    if (empty($wos)) {
        echo "无需刷新的工单。\n";
    } else {
        echo "待刷新工单: " . count($wos) . " 个\n";

        $stmtUpsert = $pdo_biz->prepare(
            "INSERT INTO tab_wo_packing_progress (wo, done_qty, cached_at) 
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE done_qty = VALUES(done_qty), cached_at = NOW()"
        );

        $refreshSuccess = 0;
        $refreshFail = 0;

        foreach ($wos as $wo) {
            try {
                $stmt1 = $pdo_mes->prepare(
                    "SELECT j.gx_id 
                     FROM tab_job j
                     JOIN tab_base_gx gx ON j.gx_id = gx.id
                     JOIN tab_base_zyzx zy ON j.zyzx_id = zy.id
                     WHERE j.task_no = :task_no 
                       AND zy.name = :zy_name 
                       AND gx.name != '标识打印'"
                );
                $stmt1->execute([':task_no' => $wo, ':zy_name' => '装置包装']);
                $gxIds = array_values(array_unique($stmt1->fetchAll(PDO::FETCH_COLUMN, 0)));

                $maxDone = 0;
                foreach ($gxIds as $gxId) {
                    $stmt2 = $pdo_mes->prepare(
                        "SELECT COUNT(DISTINCT ls.sn) 
                         FROM tab_log_step ls
                         JOIN tab_base_node node ON ls.node_id = node.id
                         WHERE ls.task_no = :task_no 
                           AND ls.gx_id = :gx_id
                           AND ls.result = 1 
                           AND node.pos = 2
                           AND ls.sn IN (SELECT DISTINCT sn FROM tab_log_job WHERE task_no = :task_no_sub)"
                    );
                    $stmt2->execute([
                        ':task_no' => $wo,
                        ':gx_id' => $gxId,
                        ':task_no_sub' => $wo
                    ]);
                    $cnt = intval($stmt2->fetchColumn() ?: 0);
                    if ($cnt > $maxDone) $maxDone = $cnt;
                }

                $stmtUpsert->execute([$wo, $maxDone]);
                echo "  ✅ {$wo}: {$maxDone}\n";
                $refreshSuccess++;
            } catch (Throwable $e) {
                echo "  ❌ {$wo}: " . $e->getMessage() . "\n";
                $refreshFail++;
            }
        }
        echo "阶段 1 结果：成功 {$refreshSuccess}，失败 {$refreshFail}\n";
    }
} catch (Throwable $e) {
    echo "阶段 1 异常：" . $e->getMessage() . "\n";
}

// ============================================================
// 阶段 2：装置包装完工自动归档
// ============================================================
echo "\n[阶段 2] 装置包装完工自动归档\n";
echo str_repeat('-', 60) . "\n";

try {
    $sqlUnfinished = "SELECT DISTINCT r.wo 
                      FROM tab_delivery_receipt r 
                      LEFT JOIN tab_work_order_material_archive a ON r.wo = a.wo 
                      WHERE a.wo IS NULL OR a.is_finished = 0";
    $activeWos = $pdo_biz->query($sqlUnfinished)->fetchAll(PDO::FETCH_COLUMN, 0);

    if (empty($activeWos)) {
        echo "暂无需要核验进度的工单。\n";
    } else {
        $archivedCount = 0;
        $stillRunningCount = 0;
        $skipCount = 0;

        foreach ($activeWos as $task_no) {
            // 计划总数
            $planQty = 0;
            try {
                $stmtPlan = $pdo_mes->prepare("SELECT task_number FROM tab_plan WHERE task_no = :task_no");
                $stmtPlan->execute([':task_no' => $task_no]);
                $planQty = intval($stmtPlan->fetchColumn() ?: 0);
            } catch (Throwable $e) {}

            if ($planQty <= 0) {
                try {
                    $stmtJob = $pdo_mes->prepare("SELECT task_number FROM tab_job WHERE task_no = :task_no LIMIT 1");
                    $stmtJob->execute([':task_no' => $task_no]);
                    $planQty = intval($stmtJob->fetchColumn() ?: 0);
                } catch (Throwable $e) {}
            }

            if ($planQty <= 0) {
                echo "  跳过 {$task_no}：无计划总数\n";
                $skipCount++;
                continue;
            }

            // 装置包装工序
            $sqlPackGx = "SELECT j.gx_id, gx.name AS gx_name
                          FROM tab_job j
                          JOIN tab_base_gx gx ON j.gx_id = gx.id
                          JOIN tab_base_zyzx zy ON j.zyzx_id = zy.id
                          WHERE j.task_no = :task_no
                            AND zy.name = :zy_name
                            AND gx.name != '标识打印'
                          ORDER BY j.seq DESC LIMIT 1";
            $stmtPackGx = $pdo_mes->prepare($sqlPackGx);
            $stmtPackGx->execute([':task_no' => $task_no, ':zy_name' => '装置包装']);
            $packGx = $stmtPackGx->fetch(PDO::FETCH_ASSOC);

            if (!$packGx) {
                echo "  跳过 {$task_no}：无装置包装工序\n";
                $skipCount++;
                continue;
            }

            $packGxId   = $packGx['gx_id'];
            $packGxName = $packGx['gx_name'];

            // 完成数
            $sqlCompleted = "SELECT COUNT(DISTINCT ls.sn) 
                             FROM tab_log_step ls
                             JOIN tab_base_node node ON ls.node_id = node.id
                             WHERE ls.task_no = :task_no
                               AND ls.gx_id = :gx_id
                               AND ls.result = 1
                               AND node.pos = 2
                               AND ls.sn IN (SELECT DISTINCT sn FROM tab_log_job WHERE task_no = :task_no_sub)";
            $stmtComp = $pdo_mes->prepare($sqlCompleted);
            $stmtComp->execute([':task_no' => $task_no, ':gx_id' => $packGxId, ':task_no_sub' => $task_no]);
            $completedCount = intval($stmtComp->fetchColumn() ?: 0);

            if ($completedCount >= $planQty) {
                $remark = "装置包装工序【{$packGxName}】已完成 ({$completedCount}/{$planQty})，物料标记为已消耗归档";
                $insArchive = $pdo_biz->prepare(
                    "INSERT INTO tab_work_order_material_archive (wo, is_finished, finished_at, remark) 
                     VALUES (?, 1, NOW(), ?) 
                     ON DUPLICATE KEY UPDATE is_finished = 1, finished_at = NOW(), remark = VALUES(remark)"
                );
                $insArchive->execute([$task_no, $remark]);
                echo "  ✅ 已归档 {$task_no} ({$completedCount}/{$planQty})\n";
                $archivedCount++;
            } else {
                echo "  ⏳ 进行中 {$task_no} ({$completedCount}/{$planQty})\n";
                $stillRunningCount++;
            }
        }
        echo "阶段 2 结果：归档 {$archivedCount}，进行中 {$stillRunningCount}，跳过 {$skipCount}\n";
    }
} catch (Throwable $e) {
    echo "阶段 2 异常：" . $e->getMessage() . "\n";
}

// ============================================================
// 汇总
// ============================================================
$totalElapsed = round(microtime(true) - $jobStart, 2);
echo "\n" . str_repeat('=', 60) . "\n";
echo "[" . date('Y-m-d H:i:s') . "] 全部完成，总耗时 {$totalElapsed}s\n";
echo str_repeat('=', 60) . "\n";