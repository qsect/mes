<?php
declare(strict_types=1);
/**
 * 追溯推送「原样诊断」脚本（CLI，仅限服务器手动执行）
 *
 * 用途：复刻 CemPushService::pushTask 的 H/I 计算与上传流程，但
 *   - 不依赖 pushTask 的成功/失败判定（原样打印 CEM 返回的 alert 与响应片段）
 *   - 把生成的 Excel 留到 exports/quality_trace/diag_<task>.xlsx 供人工核对
 *   - 打印 DB 里该工单的 tasksid/rel_no、扫码条数、以及最终写入 H/I 列的值
 *
 * 这样能区分两类问题：
 *   A. 传给 CEM 的 H/I 就是错的（如 XQ 单 tasksid 为空 → H 退化成工单号）
 *      → CEM 收到"成功"但无法按流程卡号关联到任何任务 → 界面不显示
 *   B. H/I 是对的，但 CEM 返回的成功其实是误导，或导入被静默丢弃
 *
 * 用法：
 *   /www/server/php/82/bin/php backend/scripts/diag_push_raw.php XQ2026-24083
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not Found']);
    exit;
}

// ---- App\ 自动加载 ----
if (!class_exists('App\\Core\\Env', false)) {
    spl_autoload_register(function ($class) {
        $prefix = 'App\\';
        $baseDir = dirname(__DIR__) . '/app/';
        $class = ltrim($class, '\\');
        if (strncmp($prefix, $class, strlen($prefix)) === 0) {
            $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) require_once $file;
        }
    });
}

// ---- 静默载入 .env ----
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (strpos($line, '=') !== false) {
            [$n, $v] = explode('=', $line, 2);
            $n = trim($n);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            $_ENV[$n] = $v;
            $_SERVER[$n] = $v;
        }
    }
}

// ---- CLI 合成管理员（与 backfill 脚本同口径）----
if (!function_exists('checkUser')) {
    function checkUser($requireAdmin = false) {
        return ['id' => 0, 'username' => 'cli-diag', 'full_name' => 'CLI 诊断', 'is_admin' => 1];
    }
}

require_once dirname(__DIR__) . '/app/legacy_trace.php';

// ---- 参数 ----
$taskOrder = $argv[1] ?? '';
if ($taskOrder === '') {
    echo "用法: php diag_push_raw.php <工单号>\n";
    exit(1);
}
$taskOrder = trim($taskOrder);

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    echo "[错误] 未安装 PhpSpreadsheet，无法生成 Excel。\n";
    exit(1);
}
require_once $vendorAutoload;
require_once __DIR__ . '/../includes/CemClient.php';
require_once __DIR__ . '/../app/Core/Database.php';

$db = \App\Core\Database::getConnection('default');
if (!$db) {
    echo "[错误] 数据库连接失败\n";
    exit(1);
}

// ---- 1. 读取工单与扫码明细 ----
$sql = "SELECT b.task_order, b.box_sn, b.parent_code,
               s.child_code, s.child_sn, s.scanned_at,
               u.id AS scanner_id, COALESCE(u.badge_no, u.username, u.id) AS scanner_emp_no,
               t.order_type, t.tasksid, t.rel_no
        FROM `trace_boxes` b
        INNER JOIN `trace_scans` s ON b.id = s.box_id
        LEFT JOIN `users` u ON s.scanned_by = u.id
        LEFT JOIN `trace_tasks` t ON b.task_order = t.task_order
        WHERE b.task_order = ?
        ORDER BY b.id ASC, s.id ASC";
$stmt = $db->prepare($sql);
$stmt->execute([$taskOrder]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($records)) {
    echo "[错误] 工单【{$taskOrder}】没有任何扫码追溯记录，无法推送/诊断。\n";
    exit(1);
}

$orderType = trim($records[0]['order_type'] ?? '');
$tasksid   = trim($records[0]['tasksid'] ?? '');
$relNo     = trim($records[0]['rel_no'] ?? '');
$isXqOrder = ($orderType === 'xq_project' || stripos($taskOrder, 'XQ') === 0);

echo "========== 诊断工单: {$taskOrder} ==========\n";
echo "order_type   : " . ($orderType === '' ? '(空)' : $orderType) . "\n";
echo "isXqOrder    : " . ($isXqOrder ? '是' : '否') . "\n";
echo "DB.tasksid   : " . ($tasksid === '' ? '(空!)' : $tasksid) . "\n";
echo "DB.rel_no    : " . ($relNo === '' ? '(空!)' : $relNo) . "\n";
echo "扫码明细条数 : " . count($records) . "\n";

// ---- 2. 计算 H/I（与 CemPushService::pushTask 完全一致）----
$client = new CemClient();
$cardError = '';
CemClient::assertConfigured();
if (!$client->ensureLogin()) {
    echo "[错误] CEM 登录失败：" . $client->lastError() . "\n";
    exit(1);
}
echo "CEM 登录     : OK\n";

if ($isXqOrder) {
    $finalTaskNo    = !empty($tasksid) ? $tasksid : $taskOrder;
    $finalProjectNo = !empty($relNo) ? $relNo : '';
} else {
    $finalProjectNo = $taskOrder;
    $finalTaskNo    = '';
    try {
        $finalTaskNo = $client->queryProjectCardNo($taskOrder);
    } catch (Throwable $e) {
        $cardError = $e->getMessage();
    }
    if (empty($finalTaskNo)) $finalTaskNo = $taskOrder;
}

echo "最终 H(任务号) = " . $finalTaskNo . "\n";
echo "最终 I(工程号) = " . ($finalProjectNo === '' ? '(空)' : $finalProjectNo) . "\n";
if (!empty($cardError)) echo "[警告] 数字工单查询流程卡异常: {$cardError}\n";

if ($isXqOrder && $tasksid === '') {
    echo "\n*** 关键发现：XQ 工单 tasksid 为空 → H 列退化成工单号本身 ***\n";
    echo "*** CEM 按流程卡号(26开头11位)索引，用 XQ 工单号查不到 → 界面不显示 ***\n";
    echo "*** 解决：前端对该工单先「查询ERP」再「保存配置」，或跑 backfill_tasksid.php 补数 ***\n\n";
}

// ---- 3. 生成 Excel（与 pushTask 同款 9 列），留档供核对 ----
$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Sheet1');
$headers = ['装置条码','装置物料编码','箱柜条码','箱柜编码','扫码人员ID','扫码时间','设备号','任务号','工程号'];
$sheet->fromArray($headers, null, 'A1');
$rowIdx = 2;
foreach ($records as $r) {
    $scannerId = !empty($r['scanner_emp_no']) ? $r['scanner_emp_no'] : ($r['scanner_id'] ?: '20280');
    $sheet->setCellValueExplicit('A' . $rowIdx, (string)($r['child_sn'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B' . $rowIdx, substr((string)($r['child_code'] ?? ''), 0, 10), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('C' . $rowIdx, (string)($r['box_sn'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('D' . $rowIdx, substr((string)($r['parent_code'] ?? ''), 0, 10), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('E' . $rowIdx, (string)$scannerId, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('F' . $rowIdx, (string)($r['scanned_at'] ?? date('Y-m-d H:i:s')), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('G' . $rowIdx, 'CK3R084X1400604', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('H' . $rowIdx, (string)$finalTaskNo, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('I' . $rowIdx, (string)$finalProjectNo, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $rowIdx++;
}
$exportDir = __DIR__ . '/../exports/quality_trace/';
if (!is_dir($exportDir)) @mkdir($exportDir, 0755, true);
$safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $taskOrder);
$filename = 'diag_' . $safeName . '_' . date('YmdHis') . '.xlsx';
$filepath = $exportDir . $filename;
$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save($filepath);
$spreadsheet->disconnectWorksheets();
unset($spreadsheet, $writer);
echo "诊断 Excel 已生成: {$filepath}\n";

// ---- 4. 真实上传并原样打印 CEM 返回 ----
echo "\n========== 真实上传到 CEM（原样打印返回）==========\n";
try {
    $uploadRes = $client->uploadQualityExcel($filepath, $filename);
    $alertMsg  = $uploadRes['alert'];
    $respHtml  = $uploadRes['html'];

    echo "CEM alert 弹窗原文: " . ($alertMsg === '' ? '(空)' : $alertMsg) . "\n";
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($respHtml)));
    echo "响应正文片段     : " . mb_substr($plain, 0, 200) . "\n";

    // 复刻 pushTask 的判定，便于对照
    if (!empty($alertMsg)) {
        if (stripos($alertMsg, '失败') !== false || stripos($alertMsg, '错误') !== false || stripos($alertMsg, 'error') !== false) {
            echo "pushTask 会判定 : 失败 (因为 alert 含失败/错误)\n";
        } else {
            echo "pushTask 会判定 : 【成功】(alert 不含失败/错误 → 误判风险点)\n";
        }
    } else {
        if (stripos($respHtml, '成功') !== false) {
            echo "pushTask 会判定 : 【成功】(响应含'成功')\n";
        } else {
            echo "pushTask 会判定 : 失败 (CEM 未返回成功弹窗)\n";
        }
    }
} catch (Throwable $e) {
    echo "[异常] 上传过程抛错: " . $e->getMessage() . "\n";
}

echo "\n提示：用 Excel/WPS 打开上面的诊断文件，核对 H 列(任务号)是否是 26 开头的 11 位流程卡号、\n";
echo "I 列(工程号)是否正确。若 H 是 XQ 工单号或普通数字工单号，则是数据源问题，不是 CEM 本身。\n";
