<?php
// includes/CemPushService.php
// 说明：工单追溯数据导出为标准 9 列 xlsx，纯 PHP 模拟真实上传至 CEM 平台

class CemPushService
{

    private static function log($msg) {
        // ★ 2026-10-12 相对化：原为 /www/wwwroot/vite/... 绝对路径，改为基于本文件位置推导
        $logFile = dirname(__DIR__) . "/storage/logs/cem_push.log";
        $dir = dirname($logFile);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $time = date("Y-m-d H:i:s");
        @file_put_contents($logFile, "[{$time}] {$msg}\n", FILE_APPEND);
    }

    private static function getExportDir()
    {
        $dir = __DIR__ . '/../exports/quality_trace/';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new Exception('无法创建导出目录: ' . $dir);
            }
        }
        return $dir;
    }

    /**
     * 推送追溯数据到 CEM
     * H 列（任务号）：数字工单 = CEM 流程卡号；XQ 工单 = CEM 流程卡号(tasksid)
     * I 列（工程号）：数字工单 = 纯数字工单号；XQ 工单 = 真正的工程号(rel_no，J 开头)
     */
    public static function pushTask($taskOrder, $customParams = array())
    {
        self::log("========== 开始推送工单: {$taskOrder} ==========");
        $vendorAutoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($vendorAutoload)) {
            throw new Exception('未安装 PhpSpreadsheet，请执行: composer require phpoffice/phpspreadsheet');
        }
        require_once $vendorAutoload;
        require_once __DIR__ . '/CemClient.php';
        require_once __DIR__ . '/../app/Core/Database.php';

        $db = \App\Core\Database::getConnection('default');
        if (!$db) {
            throw new Exception('数据库连接失败');
        }

        // 1. 读取该工单全部扫码明细（同时取出 trace_tasks 中的 order_type / tasksid / rel_no）
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
        $stmt->execute(array($taskOrder));
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($records)) {
            throw new Exception("工单 [{$taskOrder}] 没有任何扫码追溯记录");
        }

        // 2. 判断工单类型，确定 H 列（任务号）与 I 列（工程号）
        $orderType = trim($records[0]['order_type'] ?? '');
        $tasksid   = trim($records[0]['tasksid'] ?? '');
        $relNo     = trim($records[0]['rel_no'] ?? '');
        $isXqOrder = ($orderType === 'xq_project' || stripos($taskOrder, 'XQ') === 0);

        $client    = new CemClient();
        $cardError = '';

        if ($isXqOrder) {
            // XQ 单：
            //   H 任务号 = CEM 流程卡号(tasksid)
            //   I 工程号 = 真正的工程号(rel_no，J 开头)
            $finalTaskNo    = !empty($tasksid) ? $tasksid : $taskOrder;
            $finalProjectNo = !empty($relNo) ? $relNo : '';
        } else {
            // 数字工单：
            //   H 任务号 = CEM 流程卡号（向 CEM 查询）
            //   I 工程号 = 工单号本身（添加物料时的那个纯数字工单号）
            $finalProjectNo = $taskOrder;
            $finalTaskNo    = '';
            try {
                $finalTaskNo = $client->queryProjectCardNo($taskOrder);
            } catch (Throwable $e) {
                $cardError = $e->getMessage();
            }
            if (empty($finalTaskNo)) {
                // 查不到流程卡号时回退为工单号，避免 H 列留空
                $finalTaskNo = $taskOrder;
            }
        }

        // 3. 生成 9 列标准 xlsx
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');

        $headers = array(
            '装置条码', '装置物料编码', '箱柜条码', '箱柜编码',
            '扫码人员ID', '扫码时间', '设备号', '任务号', '工程号',
        );
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
            // ★ H 列 = 任务号（流程卡号）    I 列 = 工程号
            $sheet->setCellValueExplicit('H' . $rowIdx, (string)$finalTaskNo, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('I' . $rowIdx, (string)$finalProjectNo, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            $rowIdx++;
        }

        // 4. 保存 Excel 文件
        $exportDir = self::getExportDir();
        $safeName  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $taskOrder);
        $filename  = 'MES_' . $safeName . '_' . date('YmdHis') . '.xlsx';
        $filepath  = $exportDir . $filename;

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filepath);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $writer);

        if (!empty($cardError)) {
            throw new Exception("{$cardError} (已保存Excel: {$filename})");
        }

        // 5. 纯 PHP cURL 模拟上传
        $success   = false;
        $resultMsg = '';

        try {
            $uploadRes = $client->uploadQualityExcel($filepath, $filename);
            $alertMsg  = $uploadRes['alert'];
            $respHtml  = $uploadRes['html'];

            if (!empty($alertMsg)) {
                if (stripos($alertMsg, '失败') !== false || stripos($alertMsg, '错误') !== false || stripos($alertMsg, 'error') !== false) {
                    $success   = false;
                    $resultMsg = "CEM 提示: " . $alertMsg;
                } else {
                    $success   = true;
                    $resultMsg = $alertMsg;
                }
            } else {
                if (stripos($respHtml, '成功') !== false) {
                    $success   = true;
                    $resultMsg = "CEM 导入完成";
                } else {
                    $plain     = trim(preg_replace('/\s+/u', ' ', strip_tags($respHtml)));
                    $short     = mb_substr($plain, 0, 100);
                    $success   = false;
                    $resultMsg = "CEM 未返回导入成功弹窗 (响应: {$short})，详细内容见 /tmp/cem_last_upload_result.html";
                }
            }
        } catch (Throwable $e) {
            $success   = false;
            $resultMsg = $e->getMessage();
        }

        // 6. 回写任务表状态
        try {
            $stUpd = $db->prepare("UPDATE `trace_tasks` 
                                   SET `cem_pushed` = ?, `cem_pushed_at` = NOW(), `cem_push_result` = ? 
                                   WHERE `task_order` = ?");
            $stUpd->execute(array(
                $success ? 1 : 0,
                mb_substr($resultMsg, 0, 255),
                $taskOrder,
            ));
        } catch (Throwable $e) {}

        if (!$success) {
            throw new Exception($resultMsg);
        }

        return array(
            'success'    => true,
            'count'      => count($records),
            'message'    => $resultMsg,
            'file'       => $filename,
            'task_no'    => $finalTaskNo,
            'project_no' => $finalProjectNo,
        );
    }
}