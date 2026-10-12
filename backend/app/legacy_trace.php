<?php
// ★ 安全加固（2026-10-12）：本文件是追溯模块的历史遗留分发脚本，正常情况下
//   只应被 App\Controllers\TraceController::execLegacy() 通过 require 引入
//   （该路径已先经 TraceViewMiddleware 完成权限判定）。若被当作独立脚本直接
//   以 URL 请求，等于绕过中间件，因此这里显式拒绝：仅当「被请求的脚本就是本文件」
//   时才判定为直连。
if (isset($_SERVER['SCRIPT_FILENAME'])
    && @realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Not Found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 全局兼容函数定义
if (!function_exists("getDB")) {
    function getDB() {
        return \App\Core\Database::getConnection();
    }
}
if (!function_exists("checkUser")) {
    function checkUser($requireAdmin = false) {
        $user = $_SESSION["user"] ?? [];
        if (empty($user)) {
            \App\Core\Response::error("未登录或登录已超时", [], 401);
            exit;
        }
        if ($requireAdmin && empty($user["is_admin"])) {
            \App\Core\Response::error("权限不足", [], 403);
            exit;
        }
        return $user;
    }
}
if (!function_exists("sendJson")) {
    function sendJson($success, $message = "", $data = []) {
        if ($success) {
            \App\Core\Response::success($message, $data);
        } else {
            \App\Core\Response::error($message, $data);
        }
        exit;
    }
}

// api/trace.php - 质量追溯后端接口
ini_set('memory_limit', '512M');

$tokenFromReq = trim($_REQUEST['token'] ?? ($_REQUEST['export_token'] ?? ''));
if (!empty($tokenFromReq)) {
    if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = (stripos($tokenFromReq, 'Bearer ') === 0) ? $tokenFromReq : ('Bearer ' . $tokenFromReq);
    }
    if (empty($_SERVER['HTTP_X_TOKEN'])) {
        $_SERVER['HTTP_X_TOKEN'] = $tokenFromReq;
    }
}

require_once __DIR__ . '/../config/database.php';
// ★ 2026-10-12 相对化：原为 /www/wwwroot/vite/backend/includes/response.php 绝对路径
require_once __DIR__ . '/../includes/response.php';

// ★ 统一请求体解析：前端 axios 默认以 application/json 提交，PHP 不会自动填充 $_POST，
//   这里手工把 JSON 请求体合并进 $_POST / $_REQUEST，保证各分支都能取到参数。
if (!empty($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $rawBody = file_get_contents('php://input');
    if ($rawBody !== false && $rawBody !== '') {
        $jsonBody = json_decode($rawBody, true);
        if (is_array($jsonBody)) {
            $_POST    = array_merge($_POST, $jsonBody);
            $_REQUEST = array_merge($_REQUEST, $jsonBody);
        }
    }
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

// ★ 安全加固（H5）：CEM 凭据不再写死在源码，改为从 .env / config/cem.local.php 读取
if (!class_exists('\\App\\Core\\CemConfig')) {
    require_once __DIR__ . '/Core/CemConfig.php';
}
define('CEM_HOST',            \App\Core\CemConfig::host());
define('CEM_COOKIE_FILE',     sys_get_temp_dir() . '/cem_session_cookie.txt');
define('CEM_USER',            \App\Core\CemConfig::user());
define('CEM_PASS',            \App\Core\CemConfig::pass());
define('CEM_UA',              'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36');
define('CEM_FILTER_PREFIXES', '6002,6003,6004,7004,7005,7006,710');

// ★ 2026-10-12 相对化：原为 /www/wwwroot/vite/backend/app/cem/... 绝对路径
define('MAP_FILE_ABS',        __DIR__ . '/cem/cem_plan_map.json');
define('MAP_FILE_REL',        __DIR__ . '/cem/cem_plan_map.json');

function getFilterPrefixes() {
    return explode(',', CEM_FILTER_PREFIXES);
}

function loadMap() {
    // ★ 2026-10-12 修正兜底路径：原为 __DIR__ . '/cem_plan_map.json'（少了 cem/ 一层，必然失效）
    $targetFile = file_exists(MAP_FILE_ABS) ? MAP_FILE_ABS : (file_exists(MAP_FILE_REL) ? MAP_FILE_REL : __DIR__ . '/cem/cem_plan_map.json');
    if (!file_exists($targetFile)) return array('updated_at' => '', 'records' => array());
    $raw = @file_get_contents($targetFile);
    $arr = json_decode($raw, true);
    return (is_array($arr) && isset($arr['records'])) ? $arr : array('updated_at' => '', 'records' => array());
}

function toUtf8($html) {
    if (empty($html)) return '';
    if (preg_match('/charset\s*=\s*["\']?([\w-]+)/i', $html, $m)) {
        $charset = strtoupper($m[1]);
        if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
            return @mb_convert_encoding($html, 'UTF-8', $charset);
        }
    }
    return mb_check_encoding($html, 'UTF-8') ? $html : @mb_convert_encoding($html, 'UTF-8', 'GB18030');
}

function cemRequest($url, $post = null, $referer = null, $timeout = 25) {
    $ch = curl_init();
    $headers = array(
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: zh-CN,zh;q=0.9',
        'Connection: close',
    );
    curl_setopt_array($ch, array(
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => CEM_COOKIE_FILE,
        CURLOPT_COOKIEFILE     => CEM_COOKIE_FILE,
        CURLOPT_USERAGENT      => CEM_UA,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING       => 'gzip, deflate',
        CURLOPT_HTTPHEADER     => $headers,
    ));
    if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post);
    }
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res === false) return '';
    return toUtf8($res);
}

function cemExtractHidden($html) {
    $fields = array();
    if (preg_match_all('/<input[^>]*type=["\']hidden["\'][^>]*>/i', $html, $matches)) {
        foreach ($matches[0] as $tag) {
            preg_match('/name=["\']([^"\']*)["\']/i', $tag, $nm);
            preg_match('/value=["\']([^"\']*)["\']/i', $tag, $vm);
            if (!empty($nm[1])) {
                $fields[$nm[1]] = html_entity_decode($vm[1] ?? '', ENT_QUOTES, 'UTF-8');
            }
        }
    }
    return $fields;
}

function cemLogin() {
    $html = cemRequest(CEM_HOST . '/login.aspx');
    $fields = cemExtractHidden($html);
    $fields['userName']  = CEM_USER;
    $fields['userPwd']   = CEM_PASS;
    $fields['userLogin'] = '登 录';

    cemRequest(CEM_HOST . '/login.aspx', $fields, CEM_HOST . '/login.aspx');
    $mainHtml = cemRequest(CEM_HOST . '/main.aspx');
    return (strpos($mainHtml, CEM_USER) !== false || strpos($mainHtml, '欢迎您') !== false);
}

function cemEnsureLogin() {
    if (!file_exists(CEM_COOKIE_FILE)) return cemLogin();
    $mainHtml = cemRequest(CEM_HOST . '/main.aspx');
    if (strpos($mainHtml, CEM_USER) !== false || strpos($mainHtml, '欢迎您') !== false) {
        return true;
    }
    return cemLogin();
}

function extractMinBarcodeFromList($html) {
    if (empty($html)) return '';

    $anchors = array('>条码<', '>条码：<', '>条码:<', '>条码</', '>条码 ');
    $anchorPos = false;
    foreach ($anchors as $a) {
        $p = strpos($html, $a);
        if ($p !== false && ($anchorPos === false || $p < $anchorPos)) {
            $anchorPos = $p;
        }
    }

    if ($anchorPos === false) {
        foreach (array('>工程号<', '>箱柜号<', '>任务号<') as $a) {
            $p = strpos($html, $a);
            if ($p !== false) {
                $anchorPos = $p;
                break;
            }
        }
    }

    $scanHtml = ($anchorPos !== false) ? substr($html, $anchorPos) : $html;

    $barcodes = array();

    if (preg_match_all('/>(\d{20,26})</', $scanHtml, $m)) {
        foreach ($m[1] as $v) $barcodes[] = $v;
    }
    if (preg_match_all('/<td[^>]*>[\s\x{00A0}]*(\d{20,26})[\s\x{00A0}]*<\/td>/isu', $scanHtml, $m)) {
        foreach ($m[1] as $v) $barcodes[] = $v;
    }
    if (preg_match_all('/\b(\d{20,26})\b/', $scanHtml, $m)) {
        foreach ($m[1] as $v) $barcodes[] = $v;
    }

    if (empty($barcodes)) return '';

    $barcodes = array_values(array_unique($barcodes));
    sort($barcodes, SORT_NATURAL | SORT_FLAG_CASE);

    return $barcodes[0];
}

function fetchExistingBarcode($tasksid, $detailHtml) {
    $specid = '1';
    if (preg_match('/id=["\']Hidlabid["\'][^>]*value=["\']([^"\']*)["\']/i', $detailHtml, $m)) {
        $specid = trim($m[1]);
    } elseif (preg_match('/name=["\']Hidlabid["\'][^>]*value=["\']([^"\']*)["\']/i', $detailHtml, $m)) {
        $specid = trim($m[1]);
    }

    $batchno = '';
    if (preg_match('/id=["\']TxtBarcodeM["\'][^>]*value=["\']([^"\']*)["\']/i', $detailHtml, $m)) {
        $batchno = trim($m[1]);
    } elseif (preg_match('/name=["\']TxtBarcodeM["\'][^>]*value=["\']([^"\']*)["\']/i', $detailHtml, $m)) {
        $batchno = trim($m[1]);
    }

    $droptype = '105';
    if (preg_match('/<select[^>]*id=["\']Drop_Type["\'][^>]*>(.*?)<\/select>/is', $detailHtml, $sm)) {
        if (preg_match('/<option[^>]*selected=["\']selected["\'][^>]*value=["\']([^"\']*)["\']/i', $sm[1], $om)) {
            $droptype = trim($om[1]);
        } elseif (preg_match('/<option[^>]*value=["\']([^"\']*)["\'][^>]*selected/i', $sm[1], $om)) {
            $droptype = trim($om[1]);
        }
    }

    $printUrl = CEM_HOST . "/Quality_Track/cg_PrintModleP.aspx?taskID=" . urlencode($tasksid)
              . "&specid=" . urlencode($specid)
              . "&batchno=" . urlencode($batchno)
              . "&droptype=" . urlencode($droptype);

    $referer = CEM_HOST . "/Quality_Track/Quality_Tasks_Operation.aspx?Tasksid=" . urlencode($tasksid);
    $printHtml = cemRequest($printUrl, null, $referer, 15);
    if (!$printHtml || strpos($printHtml, 'CURL_ERROR') === 0) return '';

    $printHtml = toUtf8($printHtml);

    $minBarcode = extractMinBarcodeFromList($printHtml);
    if ($minBarcode !== '') return $minBarcode;

    $minBarcode = extractMinBarcodeFromList($detailHtml);
    if ($minBarcode !== '') return $minBarcode;

    return '';
}

$user = checkUser();
$db = getDB();
$isAdmin = !empty($user['is_admin']) || ($user['username'] ?? '') === 'admin';

try {
    $db->exec("CREATE TABLE IF NOT EXISTS `trace_permissions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL UNIQUE,
        `can_add_material` tinyint(1) NOT NULL DEFAULT 0,
        `can_scan` tinyint(1) NOT NULL DEFAULT 1,
        `can_view` tinyint(1) NOT NULL DEFAULT 1,
        `can_edit_record` tinyint(1) NOT NULL DEFAULT 0,
        `can_delete_record` tinyint(1) NOT NULL DEFAULT 0,
        `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $db->exec("CREATE TABLE IF NOT EXISTS `trace_tasks` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `task_order` varchar(100) NOT NULL UNIQUE,
        `parent_code` varchar(100) NOT NULL,
        `description` varchar(255) DEFAULT '',
        `quantity` int(11) NOT NULL DEFAULT 1,
        `batch_prefix` varchar(60) DEFAULT '',
        `batch_no` varchar(100) DEFAULT '' COMMENT 'ERP离散任务表完整批号',
        `scan_points` tinyint(2) NOT NULL DEFAULT 1,
        `order_type` varchar(20) NOT NULL DEFAULT 'standard',
        `tasksid` varchar(50) DEFAULT '' COMMENT 'CEM流程卡号',
        `rel_no` varchar(100) DEFAULT '' COMMENT '工程关联单据号',
        `cem_pushed` tinyint(1) NOT NULL DEFAULT 0,
        `cem_pushed_at` datetime DEFAULT NULL,
        `cem_push_result` varchar(255) DEFAULT '',
        `created_by` int(11) NOT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_task_order` (`task_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $taskCols = $db->query("SHOW COLUMNS FROM trace_tasks")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('order_type', $taskCols)) $db->exec("ALTER TABLE `trace_tasks` ADD `order_type` varchar(20) NOT NULL DEFAULT 'standard'");
    if (!in_array('tasksid', $taskCols))    $db->exec("ALTER TABLE `trace_tasks` ADD `tasksid` varchar(50) DEFAULT ''");
    if (!in_array('rel_no', $taskCols))     $db->exec("ALTER TABLE `trace_tasks` ADD `rel_no` varchar(100) DEFAULT ''");
    if (!in_array('batch_no', $taskCols))   $db->exec("ALTER TABLE `trace_tasks` ADD `batch_no` varchar(100) DEFAULT ''");

    $db->exec("CREATE TABLE IF NOT EXISTS `trace_bom_config` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `task_order` varchar(100) NOT NULL,
        `parent_code` varchar(100) NOT NULL,
        `parent_name` varchar(255) DEFAULT '',
        `child_code` varchar(50) NOT NULL,
        `child_name` varchar(255) DEFAULT '',
        `unit_quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
        `scan_point` tinyint(2) NOT NULL DEFAULT 1,
        `created_by` int(11) NOT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_task_point` (`task_order`, `scan_point`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $db->exec("CREATE TABLE IF NOT EXISTS `trace_boxes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `task_order` varchar(100) NOT NULL,
        `box_sn` varchar(60) NOT NULL UNIQUE,
        `parent_code` varchar(100) NOT NULL,
        `status` tinyint(1) NOT NULL DEFAULT 0,
        `created_by` int(11) NOT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        `completed_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        INDEX `idx_task_status` (`task_order`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $db->exec("CREATE TABLE IF NOT EXISTS `trace_scans` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `task_order` varchar(100) NOT NULL,
        `box_id` int(11) NOT NULL,
        `scan_point` tinyint(2) NOT NULL DEFAULT 1,
        `child_code` varchar(50) NOT NULL,
        `child_sn` varchar(60) NOT NULL,
        `scanned_by` int(11) NOT NULL,
        `scanned_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_child_sn` (`child_sn`),
        INDEX `idx_box_child` (`box_id`, `child_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

function getTraceUserPerms($db, $userId, $isAdmin) {
    if ($isAdmin) {
        return array('can_add_material' => 1, 'can_scan' => 1, 'can_view' => 1, 'can_edit_record' => 1, 'can_delete_record' => 1, 'is_admin' => 1);
    }
    $st = $db->prepare("SELECT can_add_material, can_scan, can_view, can_edit_record, can_delete_record FROM `trace_permissions` WHERE `user_id` = ? LIMIT 1");
    $st->execute(array($userId));
    $p = $st->fetch(PDO::FETCH_ASSOC);
    return array(
        'can_add_material'  => $p ? intval($p['can_add_material']) : 0,
        'can_scan'          => $p ? intval($p['can_scan']) : 1,
        'can_view'          => $p ? intval($p['can_view']) : 1,
        'can_edit_record'   => $p ? intval($p['can_edit_record']) : 0,
        'can_delete_record' => $p ? intval($p['can_delete_record']) : 0,
        'is_admin'          => 0
    );
}

$userPerms = getTraceUserPerms($db, $user['id'], $isAdmin);

function findExactCol($columns, $targetNames) {
    foreach ($targetNames as $target) {
        foreach ($columns as $col) {
            if (trim($col) === $target) return $col;
        }
    }
    foreach ($targetNames as $target) {
        foreach ($columns as $col) {
            if (strpos($col, $target) !== false) return $col;
        }
    }
    return false;
}

function getErpSqlSrvConnection() {
    if (function_exists('getSqlSrvDB')) {
        $pdo = getSqlSrvDB();
        if ($pdo) return $pdo;
    }
    // ★ 凭据一律取自 .env（无源码兜底），配置缺失直接拒绝连接
    $cfg = class_exists('\\App\\Core\\ExternalDb') ? \App\Core\ExternalDb::sqlsrvConfig() : null;
    if ($cfg === null) {
        return null;
    }
    list($sqlsrv_host, $sqlsrv_port, $sqlsrv_db, $sqlsrv_user, $sqlsrv_pass) = $cfg;

    $options = array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 15,
    );
    $dsn = "dblib:host=$sqlsrv_host:$sqlsrv_port;dbname=$sqlsrv_db;charset=UTF-8";
    $pdo = new PDO($dsn, $sqlsrv_user, $sqlsrv_pass, $options);
    $pdo->exec("SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; SET ANSI_PADDING ON; SET CONCAT_NULL_YIELDS_NULL ON; SET QUOTED_IDENTIFIER ON;");
    return $pdo;
}

if ($action === 'init') {
    sendJson(true, 'success', array('user_perms' => $userPerms));
}

// 1. 查询工单组件并校验齐套状况
if ($action === 'check_or_fetch_erp') {
    if (!$userPerms['can_add_material']) sendJson(false, '您没有添加/编辑追溯物料的权限！');

    $wo = trim($_REQUEST['work_order'] ?? '');
    if (empty($wo)) sendJson(false, '请输入工单号！');

    $isXq = (stripos($wo, 'XQ') === 0);

    $chkTask = $db->prepare("SELECT * FROM `trace_tasks` WHERE `task_order` = ? LIMIT 1");
    $chkTask->execute(array($wo));
    $existingTask = $chkTask->fetch(PDO::FETCH_ASSOC);

    $savedConfig = array();
    if (!empty($existingTask)) {
        $stBom = $db->prepare("SELECT child_code, unit_quantity, scan_point FROM `trace_bom_config` WHERE `task_order` = ?");
        $stBom->execute(array($wo));
        while ($r = $stBom->fetch(PDO::FETCH_ASSOC)) {
            $savedConfig[$r['child_code']] = array(
                'unit_quantity' => floatval($r['unit_quantity']),
                'scan_point'    => intval($r['scan_point'])
            );
        }
    }

    $kittingSummary = array();
    try {
        $sqlsrv = getErpSqlSrvConnection();
        $sqlKitting = "SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; EXEC [dbo].[自定义ERP组件_P] @WorkOrders = ?";
        $stmtK = $sqlsrv->prepare($sqlKitting);
        $stmtK->execute(array($wo));
        while ($stmtK->columnCount() === 0 && $stmtK->nextRowset()) {}

        $kCols = array();
        for ($i = 0; $i < $stmtK->columnCount(); $i++) {
            $meta = $stmtK->getColumnMeta($i);
            $kCols[] = $meta['name'];
        }
        $kRows = $stmtK->fetchAll(PDO::FETCH_ASSOC);

        $colCode    = findExactCol($kCols, array('物料编码', '组件编码', '编码', '子项物料代码')) ?: '物料编码';
        $colReqQty  = findExactCol($kCols, array('需求量', '需求数量'));
        $colSendQty = findExactCol($kCols, array('已发料', '已发料数量', '实发数量', '完成量'));

        foreach ($kRows as $kr) {
            $mCode = trim((string)($kr[$colCode] ?? ''));
            if (!$mCode) continue;

            $reqStr  = $colReqQty ? trim((string)($kr[$colReqQty] ?? '0')) : '0';
            $sendStr = $colSendQty ? trim((string)($kr[$colSendQty] ?? '0')) : '0';
            $reqQty  = floatval(str_replace(',', '', $reqStr));
            $sendQty = floatval(str_replace(',', '', $sendStr));

            if (!isset($kittingSummary[$mCode])) {
                $kittingSummary[$mCode] = array('req' => 0, 'send' => 0);
            }
            $kittingSummary[$mCode]['req']  += $reqQty;
            $kittingSummary[$mCode]['send'] += $sendQty;
        }
    } catch (Throwable $e) {}

    // 工程发货 XQ 单号处理
    if ($isXq) {
        $kwClean = strtoupper($wo);
        $tasksid = '';

        if (preg_match('/^\d+$/', $kwClean)) {
            $tasksid = $kwClean;
        } else {
            $map = loadMap();
            $matched = array();
            foreach ($map['records'] as $cNo => $data) {
                if (!empty($data['plan_code']) && stripos($data['plan_code'], $kwClean) !== false) {
                    $matched[] = $cNo;
                }
            }
            if (count($matched) === 1) {
                $tasksid = $matched[0];
            } elseif (count($matched) > 1) {
                sendJson(false, "需求计划号【{$wo}】对应多个流程卡号：" . implode(', ', $matched) . "，请使用具体流程卡号查询！");
            }
        }

        if (empty($tasksid)) {
            sendJson(false, "在本地映射库未查询到【{$wo}】对应的流程卡！请先在流程卡查询页面同步。");
        }

        if (!cemEnsureLogin()) {
            sendJson(false, "登录 CEM 失败，请检查网络连接。");
        }

        $detailUrl = CEM_HOST . "/Quality_Track/Quality_Tasks_Operation.aspx?Tasksid=" . urlencode($tasksid);
        $rawHtml = cemRequest($detailUrl);

        if (strlen($rawHtml) < 600 || strpos($rawHtml, '未找到') !== false || strpos($rawHtml, '不存在') !== false) {
            sendJson(false, "系统内未查询到流程卡号【{$tasksid}】的相关数据！");
        }

        $relNo = '';
        $labName = '';
        if (preg_match('/id=["\']Text_Relbillno["\'][^>]*>([^<]+)<\//i', $rawHtml, $m)) $relNo = trim($m[1]);
        if (preg_match('/id=["\']Labname["\'][^>]*>([^<]+)<\//i', $rawHtml, $m)) $labName = trim($m[1]);

        $detailBarcode = fetchExistingBarcode($tasksid, $rawHtml);

        if (!empty($existingTask) && !empty($detailBarcode) && $existingTask['parent_code'] !== $detailBarcode) {
            $db->prepare("UPDATE `trace_tasks` SET `parent_code` = ?, `batch_prefix` = ? WHERE `task_order` = ?")
               ->execute(array($detailBarcode, substr($detailBarcode, 0, 16), $wo));
            $existingTask['parent_code']   = $detailBarcode;
            $existingTask['batch_prefix']  = substr($detailBarcode, 0, 16);
        }

        $task = array(
            'task_order'   => $wo,
            'tasksid'      => $tasksid,
            'parent_code'  => !empty($detailBarcode) ? $detailBarcode : $wo,
            'description'  => $labName ?: ('工程发货单 ' . $wo),
            'quantity'     => 1,
            'batch_prefix' => !empty($detailBarcode) ? substr($detailBarcode, 0, 16) : '',
            'order_type'   => 'xq_project',
            'rel_no'       => $relNo,
            'existing_sn'  => $detailBarcode
        );

        $prefixes = getFilterPrefixes();
        $materials = array();

        if (preg_match('/<table[^>]*id=["\']myGridView["\'][^>]*>(.*?)<\/table>/is', $rawHtml, $gridMatch)) {
            $inner = $gridMatch[1];
            if (preg_match_all('/<tr[^>]*>.*?<\/tr>/is', $inner, $rows)) {
                foreach ($rows[0] as $idx => $row) {
                    if (stripos($row, '<th') !== false || strpos($row, 'Page$') !== false) continue;

                    if (!preg_match('/<td[^>]*>(.*?)<\/td>/is', $row, $cm)) continue;

                    $code = preg_replace('/\s+/', '', trim(strip_tags($cm[1])));
                    if (empty($code)) continue;

                    $matched = empty($prefixes);
                    foreach ($prefixes as $p) {
                        if ($code !== '' && strpos($code, trim($p)) === 0) {
                            $matched = true;
                            break;
                        }
                    }

                    if ($matched) {
                        preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $row, $tdMatches);
                        $tds = array();
                        foreach ($tdMatches[1] as $tdContent) {
                            $cText = html_entity_decode(strip_tags($tdContent), ENT_QUOTES, 'UTF-8');
                            $tds[] = trim(str_replace(array('&nbsp;', "\r", "\n", "\t"), ' ', $cText));
                        }

                        $mCode = $code;
                        $mName = isset($tds[1]) ? $tds[1] : $mCode;

                        $rowText = strip_tags($row);
                        $mQty = 1;

                        if (preg_match('/PCS\s*([0-9]+(?:\.[0-9]+)?)/i', $rowText, $qm)) {
                            $mQty = intval(round(floatval($qm[1])));
                        } else {
                            foreach ($tds as $tVal) {
                                if (preg_match('/^([1-9]\d*(?:\.\d+)?)$/', $tVal, $qm2)) {
                                    $mQty = intval(round(floatval($qm2[1])));
                                    break;
                                }
                            }
                        }
                        if ($mQty <= 0) $mQty = 1;

                        $reqTotal = isset($kittingSummary[$mCode]) ? $kittingSummary[$mCode]['req'] : $mQty;
                        $sendTotal = isset($kittingSummary[$mCode]) ? $kittingSummary[$mCode]['send'] : $mQty;

                        $colorState = 'red';
                        if ($sendTotal >= $reqTotal && $reqTotal > 0) {
                            $colorState = 'green';
                        } elseif ($sendTotal > 0 && $sendTotal < $reqTotal) {
                            $colorState = 'orange';
                        }

                        $materials[] = array(
                            'code'           => $mCode,
                            'name'           => $mName,
                            'unit_quantity'  => $mQty,
                            'scan_point'     => isset($savedConfig[$mCode]) ? $savedConfig[$mCode]['scan_point'] : 1,
                            'req_quantity'   => $reqTotal,
                            'send_quantity'  => $sendTotal,
                            'color_state'    => $colorState
                        );
                    }
                }
            }
        }

        \App\Services\AuditService::log('质量追溯', '拉取ERP齐套数据');
        sendJson(true, 'success', array(
            'is_configured' => (!empty($existingTask) ? true : false),
            'existing_task' => $existingTask,
            'task'          => $task,
            'materials'     => array_values($materials),
            'is_xq'         => true
        ));
    }

    try {
        $sqlsrv = getErpSqlSrvConnection();
        $st = $sqlsrv->prepare("SELECT TOP 1 [任务] as task_order, [编码] as parent_code, [描述] as description, [数量] as quantity, [批号] as batch FROM [J_全离散任务] WHERE ([任务] = ? OR [编码] = ?) AND [状态] <> '已取消'");
        $st->execute(array($wo, $wo));
        $task = $st->fetch(PDO::FETCH_ASSOC);

        if (!$task) sendJson(false, '未在 ERP 中查到该工单信息');

        $woNo = trim($task['task_order']);
        $totalQty = intval(round(floatval($task['quantity'])));
        if ($totalQty <= 0) $totalQty = 1;

        $task['quantity'] = $totalQty;
        $task['description'] = trim(mb_convert_encoding($task['description'] ?? '', 'UTF-8', 'UTF-8,GBK,GB2312'));
        $task['batch_prefix'] = !empty($task['batch']) ? substr(trim($task['batch']), 0, 16) : '';
        $task['batch_no']     = !empty($task['batch']) ? trim($task['batch']) : '';
        $task['order_type'] = 'standard';

        if (!empty($existingTask) && intval($existingTask['quantity']) !== $totalQty) {
            $db->prepare("UPDATE `trace_tasks` SET `quantity` = ? WHERE `task_order` = ?")->execute(array($totalQty, $woNo));
            $existingTask['quantity'] = $totalQty;
        }

        $stmtProc = $sqlsrv->prepare("EXEC [dbo].[自定义ERP组件_P] @WorkOrders = ?");
        $stmtProc->execute(array($woNo));
        $rawRows = $stmtProc->fetchAll(PDO::FETCH_ASSOC);

        $cols = array();
        for ($i = 0; $i < $stmtProc->columnCount(); $i++) {
            $meta = $stmtProc->getColumnMeta($i);
            $cols[] = $meta['name'];
        }

        $colCode    = findExactCol($cols, array('物料编码', '组件编码', '编码', '子项物料代码')) ?: '物料编码';
        $colName    = findExactCol($cols, array('物料名称', '组件描述', '物料描述', '描述')) ?: '物料名称';
        $colProcNo  = findExactCol($cols, array('工序号', '工序代码'));
        $colReqQty  = findExactCol($cols, array('需求量', '需求数量'));
        $colSendQty = findExactCol($cols, array('已发料', '已发料数量', '实发数量', '完成量'));

        $materials = array();
        $validPrefixes = array('6002', '6003', '6004', '7004', '7005', '7006');

        foreach ($rawRows as $r) {
            $procNoVal = trim((string)($colProcNo ? ($r[$colProcNo] ?? '') : ''));
            if ($procNoVal !== '') {
                if (strpos($procNoVal, '-') !== false || floatval($procNoVal) < 0) continue;
            }

            $mCode = trim((string)($r[$colCode] ?? ''));
            if (empty($mCode)) continue;

            $prefix4 = substr($mCode, 0, 4);
            if (!in_array($prefix4, $validPrefixes)) continue;

            $mName   = trim(mb_convert_encoding((string)($r[$colName] ?? ''), 'UTF-8', 'UTF-8,GBK,GB2312'));
            $reqStr  = $colReqQty ? trim((string)($r[$colReqQty] ?? '0')) : '0';
            $sendStr = $colSendQty ? trim((string)($r[$colSendQty] ?? '0')) : '0';

            $reqQty  = floatval(str_replace(',', '', $reqStr));
            $sendQty = floatval(str_replace(',', '', $sendStr));

            if (!isset($materials[$mCode])) {
                $materials[$mCode] = array(
                    'code'          => $mCode,
                    'name'          => $mName,
                    'req_quantity'  => 0,
                    'send_quantity' => 0
                );
            }
            if ($materials[$mCode]['name'] === '' && $mName !== '') {
                $materials[$mCode]['name'] = $mName;
            }
            $materials[$mCode]['req_quantity']  += $reqQty;
            $materials[$mCode]['send_quantity'] += $sendQty;
        }

        $resultMaterials = array();
        foreach ($materials as $mCode => $m) {
            $reqTotal  = floatval($m['req_quantity']);
            $sendTotal = floatval($m['send_quantity']);

            $unitQty = ($totalQty > 0) ? round($reqTotal / $totalQty, 2) : $reqTotal;
            if ($unitQty <= 0) $unitQty = 1;

            $colorState = 'red';
            if ($reqTotal > 0 && $sendTotal >= $reqTotal) {
                $colorState = 'green';
            } elseif ($sendTotal > 0 && $sendTotal < $reqTotal) {
                $colorState = 'orange';
            }

            $resultMaterials[] = array(
                'code'          => $mCode,
                'name'          => ($m['name'] !== '' ? $m['name'] : $mCode),
                'unit_quantity' => $unitQty,
                'scan_point'    => isset($savedConfig[$mCode]) ? intval($savedConfig[$mCode]['scan_point']) : 1,
                'req_quantity'  => $reqTotal,
                'send_quantity' => $sendTotal,
                'color_state'   => $colorState
            );
        }

        \App\Services\AuditService::log('质量追溯', '拉取ERP齐套数据');
        sendJson(true, 'success', array(
            'is_configured' => (!empty($existingTask) ? true : false),
            'existing_task' => $existingTask,
            'task'          => $task,
            'materials'     => $resultMaterials,
            'is_xq'         => false
        ));
    } catch (Throwable $e) {
        error_log('[trace][check_or_fetch_erp] ' . $e->getMessage());
        sendJson(false, '读取 ERP 工单组件失败，请稍后重试或联系管理员！');
    }
}

// =========================================================================
// 以下为历史遗留分支（原文件被截断后缺失，此处补全）
// =========================================================================

$in = $_REQUEST;

/* ---------------------------------------------------------------------
 * 公共小工具
 * ------------------------------------------------------------------ */

/** 统计某装置（box）在指定扫码点下，每个二级物料的已扫数量 */
function trBoxPointScanned($db, $boxId, $scanPoint) {
    $st = $db->prepare("SELECT child_code, COUNT(*) AS cnt FROM `trace_scans` WHERE `box_id` = ? AND `scan_point` = ? GROUP BY child_code");
    $st->execute(array($boxId, $scanPoint));
    $map = array();
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $map[$r['child_code']] = intval($r['cnt']);
    }
    return $map;
}

/** 统计某单据已完工的装置数量（status = 1） */
function trCountCompletedBoxes($db, $taskOrder) {
    $st = $db->prepare("SELECT COUNT(*) FROM `trace_boxes` WHERE `task_order` = ? AND `status` = 1");
    $st->execute(array($taskOrder));
    return intval($st->fetchColumn());
}

/** 判断某装置是否所有扫码点的二级物料都已扫满 */
function trIsBoxFullyCompleted($db, $taskOrder, $boxId) {
    $stBom = $db->prepare("SELECT child_code, unit_quantity, scan_point FROM `trace_bom_config` WHERE `task_order` = ?");
    $stBom->execute(array($taskOrder));
    $need = array();
    while ($r = $stBom->fetch(PDO::FETCH_ASSOC)) {
        $need[intval($r['scan_point']) . '|' . $r['child_code']] = floatval($r['unit_quantity']);
    }
    if (empty($need)) return false;

    $stScan = $db->prepare("SELECT scan_point, child_code, COUNT(*) AS cnt FROM `trace_scans` WHERE `box_id` = ? GROUP BY scan_point, child_code");
    $stScan->execute(array($boxId));
    $got = array();
    while ($r = $stScan->fetch(PDO::FETCH_ASSOC)) {
        $got[intval($r['scan_point']) . '|' . $r['child_code']] = intval($r['cnt']);
    }

    foreach ($need as $key => $qty) {
        if ($qty <= 0) $qty = 1;
        if (intval($got[$key] ?? 0) < $qty) return false;
    }
    return true;
}

/** 重新计算并写回某装置的完工状态 */
function trRefreshBoxStatus($db, $taskOrder, $boxId) {
    if ($boxId <= 0) return;
    if (trIsBoxFullyCompleted($db, $taskOrder, $boxId)) {
        $db->prepare("UPDATE `trace_boxes` SET `status` = 1, `completed_at` = IFNULL(`completed_at`, NOW()) WHERE `id` = ?")
           ->execute(array($boxId));
    } else {
        $db->prepare("UPDATE `trace_boxes` SET `status` = 0, `completed_at` = NULL WHERE `id` = ?")
           ->execute(array($boxId));
    }
}

/* ---------------------------------------------------------------------
 * 2. 保存追溯物料配置
 * ------------------------------------------------------------------ */
if ($action === 'save_config') {
    if (empty($userPerms['can_add_material']) && empty($userPerms['is_admin'])) {
        sendJson(false, '您没有添加/编辑追溯物料的权限！');
    }

    $taskOrder  = trim((string)($in['task_order'] ?? ''));
    $parentCode = trim((string)($in['parent_code'] ?? ''));
    $parentName = trim((string)($in['parent_name'] ?? ''));
    $quantity   = intval($in['quantity'] ?? 1);
    $batchNo    = trim((string)($in['batch_no'] ?? ''));
    $scanPoints = intval($in['scan_points'] ?? 1);
    $children   = $in['children'] ?? array();
    if (is_string($children)) $children = json_decode($children, true) ?: array();

    if ($taskOrder === '' || $parentCode === '') sendJson(false, '参数不完整，请重新查询工单后再保存！');
    if ($quantity <= 0)   $quantity = 1;
    if ($scanPoints < 1)  $scanPoints = 1;
    if ($scanPoints > 5)  $scanPoints = 5;
    if (!is_array($children) || count($children) === 0) sendJson(false, '请至少勾选一项追溯物料！');

    foreach ($children as $c) {
        $cCode = trim((string)($c['code'] ?? ''));
        if ($cCode === '') sendJson(false, '存在无效的物料行，请重新查询工单！');
        if (floatval($c['send_quantity'] ?? 0) <= 0) {
            sendJson(false, "物料【{$cCode}】实发出库数为 0，禁止保存追溯配置！");
        }
    }

    try {
        $db->beginTransaction();

        $stChk = $db->prepare("SELECT id FROM `trace_tasks` WHERE `task_order` = ? LIMIT 1");
        $stChk->execute(array($taskOrder));
        $existId = $stChk->fetchColumn();

        $batchPrefix = ($batchNo !== '') ? substr($batchNo, 0, 16) : '';

        if ($existId) {
            // 注意：order_type / tasksid / rel_no 由 check_or_fetch_erp 阶段写入，此处不覆盖
            $db->prepare("UPDATE `trace_tasks` SET `parent_code` = ?, `description` = ?, `quantity` = ?, `batch_prefix` = ?, `batch_no` = ?, `scan_points` = ? WHERE `task_order` = ?")
               ->execute(array($parentCode, $parentName, $quantity, $batchPrefix, $batchNo, $scanPoints, $taskOrder));
        } else {
            $db->prepare("INSERT INTO `trace_tasks` (`task_order`, `parent_code`, `description`, `quantity`, `batch_prefix`, `batch_no`, `scan_points`, `order_type`, `created_by`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute(array(
                   $taskOrder, $parentCode, $parentName, $quantity, $batchPrefix, $batchNo, $scanPoints,
                   (stripos($taskOrder, 'XQ') === 0 ? 'xq_project' : 'standard'),
                   intval($user['id'])
               ));
        }

        $db->prepare("DELETE FROM `trace_bom_config` WHERE `task_order` = ?")->execute(array($taskOrder));

        $stIns = $db->prepare("INSERT INTO `trace_bom_config` (`task_order`, `parent_code`, `parent_name`, `child_code`, `child_name`, `unit_quantity`, `scan_point`, `created_by`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($children as $c) {
            $unitQty = floatval($c['unit_quantity'] ?? 1);
            if ($unitQty <= 0) $unitQty = 1;
            $sp = intval($c['scan_point'] ?? 1);
            if ($sp < 1) $sp = 1;
            if ($sp > $scanPoints) $sp = $scanPoints;

            $stIns->execute(array(
                $taskOrder,
                $parentCode,
                $parentName,
                trim((string)$c['code']),
                trim((string)($c['name'] ?? '')),
                $unitQty,
                $sp,
                intval($user['id'])
            ));
        }

        $db->commit();
        \App\Services\AuditService::log('质量追溯', '保存追溯物料配置');
        sendJson(true, '追溯物料配置保存成功');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[trace][save_config] ' . $e->getMessage());
        sendJson(false, '保存追溯物料配置失败，请稍后重试或联系管理员！');
    }
}

/* ---------------------------------------------------------------------
 * 3. 开始追溯（读取已配置的物料清单）
 * ------------------------------------------------------------------ */
if ($action === 'start_trace') {
    if (empty($userPerms['can_scan']) && empty($userPerms['can_add_material']) && empty($userPerms['is_admin'])) {
        sendJson(false, '您没有质量追溯的权限！');
    }

    $wo = trim((string)($in['work_order'] ?? ''));
    if ($wo === '') sendJson(false, '请输入工单号！');

    $st = $db->prepare("SELECT * FROM `trace_tasks` WHERE `task_order` = ? LIMIT 1");
    $st->execute(array($wo));
    $task = $st->fetch(PDO::FETCH_ASSOC);
    if (!$task) sendJson(false, '该单号尚未配置追溯物料，请先在【添加/编辑追溯物料】中配置！');

    $stBom = $db->prepare("SELECT child_code, child_name, unit_quantity, scan_point FROM `trace_bom_config` WHERE `task_order` = ? ORDER BY scan_point ASC, id ASC");
    $stBom->execute(array($wo));
    $materials = array();
    while ($r = $stBom->fetch(PDO::FETCH_ASSOC)) {
        $materials[] = array(
            'child_code'    => $r['child_code'],
            'child_name'    => $r['child_name'],
            'unit_quantity' => floatval($r['unit_quantity']),
            'scan_point'    => intval($r['scan_point'])
        );
    }

    \App\Services\AuditService::log('质量追溯', '启动追溯任务');
    sendJson(true, 'success', array(
        'task'            => $task,
        'materials'       => $materials,
        'completed_boxes' => trCountCompletedBoxes($db, $wo)
    ));
}

/* ---------------------------------------------------------------------
 * 4. 扫码（一级条码 = 装置SN；二级物料条码）
 * ------------------------------------------------------------------ */
if ($action === 'scan_barcode') {
    if (empty($userPerms['can_scan']) && empty($userPerms['is_admin'])) {
        sendJson(false, '您没有扫码权限，请联系管理员开通！');
    }

    $taskOrder = trim((string)($in['task_order'] ?? ''));
    $sn        = trim((string)($in['sn'] ?? ''));
    $boxId     = intval($in['box_id'] ?? 0);
    $scanPoint = intval($in['scan_point'] ?? 1);
    if ($scanPoint < 1) $scanPoint = 1;

    if ($taskOrder === '' || $sn === '') sendJson(false, '扫码参数不完整，请重新开始追溯！');

    $st = $db->prepare("SELECT * FROM `trace_tasks` WHERE `task_order` = ? LIMIT 1");
    $st->execute(array($taskOrder));
    $task = $st->fetch(PDO::FETCH_ASSOC);
    if (!$task) sendJson(false, '该单号尚未配置追溯物料！');

    $stBom = $db->prepare("SELECT child_code, unit_quantity FROM `trace_bom_config` WHERE `task_order` = ? AND `scan_point` = ?");
    $stBom->execute(array($taskOrder, $scanPoint));
    $pointBom = array();
    while ($r = $stBom->fetch(PDO::FETCH_ASSOC)) {
        $pointBom[$r['child_code']] = floatval($r['unit_quantity']);
    }

    // ---- 第一层：登记 / 定位一级条码（装置SN） ----
    if ($boxId <= 0) {
        $stBox = $db->prepare("SELECT * FROM `trace_boxes` WHERE `task_order` = ? AND `box_sn` = ? LIMIT 1");
        $stBox->execute(array($taskOrder, $sn));
        $box = $stBox->fetch(PDO::FETCH_ASSOC);

        if ($box) {
            $boxId = intval($box['id']);
            $boxSn = $box['box_sn'];
        } else {
            $db->prepare("INSERT INTO `trace_boxes` (`task_order`, `box_sn`, `parent_code`, `status`, `created_by`) VALUES (?, ?, ?, 0, ?)")
               ->execute(array($taskOrder, $sn, $task['parent_code'], intval($user['id'])));
            $boxId = intval($db->lastInsertId());
            $boxSn = $sn;
        }

        \App\Services\AuditService::log('质量追溯', '扫码登记一级条码');
        sendJson(true, '一级条码【' . $boxSn . '】已登记，请继续扫描二级物料', array(
            'is_box'              => true,
            'box_id'              => $boxId,
            'box_sn'              => $boxSn,
            'box_scanned'         => (object)trBoxPointScanned($db, $boxId, $scanPoint),
            'completed_boxes'     => trCountCompletedBoxes($db, $taskOrder),
            'cur_point_completed' => false,
            'task_all_completed'  => false
        ));
    }

    // ---- 第二层：二级物料扫码 ----
    $stBox = $db->prepare("SELECT * FROM `trace_boxes` WHERE `id` = ? AND `task_order` = ? LIMIT 1");
    $stBox->execute(array($boxId, $taskOrder));
    $box = $stBox->fetch(PDO::FETCH_ASSOC);
    if (!$box) sendJson(false, '一级条码尚未登记，请先扫描一级物料/装置SN！');

    if (empty($pointBom)) sendJson(false, '【扫码点 ' . $scanPoint . '】未配置任何追溯物料！');

    // 依据 SN 前导编码匹配所属物料（工厂条码 = 物料编码 + 流水号）
    $codes = array_keys($pointBom);
    usort($codes, function ($a, $b) { return strlen($b) - strlen($a); });

    $matchedCode = null;
    foreach ($codes as $code) {
        if ($code !== '' && strpos($sn, $code) === 0) { $matchedCode = $code; break; }
    }
    if ($matchedCode === null) {
        // 兜底：仅当唯一一个物料编码出现在 SN 中时才认定，避免误判
        $hits = array();
        foreach ($codes as $code) {
            if ($code !== '' && strpos($sn, $code) !== false) $hits[] = $code;
        }
        if (count($hits) === 1) $matchedCode = $hits[0];
    }
    if ($matchedCode === null) {
        sendJson(false, '条码【' . $sn . '】不属于【扫码点 ' . $scanPoint . '】配置的任何物料，请核对！');
    }

    $unitQty = $pointBom[$matchedCode];
    if ($unitQty <= 0) $unitQty = 1;

    // 该装置本工位该物料是否已扫满
    $stScan = $db->prepare("SELECT COUNT(*) FROM `trace_scans` WHERE `box_id` = ? AND `scan_point` = ? AND `child_code` = ?");
    $stScan->execute(array($boxId, $scanPoint, $matchedCode));
    if (intval($stScan->fetchColumn()) >= $unitQty) {
        sendJson(false, '物料【' . $matchedCode . '】在扫码点 ' . $scanPoint . ' 已扫满 ' . $unitQty . ' 个，请勿重复扫描！');
    }

    // 全局唯一性校验
    $stDup = $db->prepare("SELECT b.box_sn FROM `trace_scans` s LEFT JOIN `trace_boxes` b ON b.id = s.box_id WHERE s.child_sn = ? LIMIT 1");
    $stDup->execute(array($sn));
    $dupBox = $stDup->fetchColumn();
    if ($dupBox !== false) {
        sendJson(false, '条码【' . $sn . '】已被装置【' . $dupBox . '】使用，请勿重复扫描！');
    }

    try {
        $db->prepare("INSERT INTO `trace_scans` (`task_order`, `box_id`, `scan_point`, `child_code`, `child_sn`, `scanned_by`) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute(array($taskOrder, $boxId, $scanPoint, $matchedCode, $sn, intval($user['id'])));
    } catch (Throwable $e) {
        sendJson(false, '该条码已存在，请勿重复扫描！');
    }

    $boxScanned = trBoxPointScanned($db, $boxId, $scanPoint);

    $curPointCompleted = true;
    foreach ($pointBom as $code => $need) {
        if ($need <= 0) $need = 1;
        if (intval($boxScanned[$code] ?? 0) < $need) { $curPointCompleted = false; break; }
    }

    if ($curPointCompleted) trRefreshBoxStatus($db, $taskOrder, $boxId);

    $completedBoxes   = trCountCompletedBoxes($db, $taskOrder);
    $taskAllCompleted = ($completedBoxes >= intval($task['quantity']));

    \App\Services\AuditService::log('质量追溯', '扫码录入二级物料');
    sendJson(true, '物料【' . $matchedCode . '】扫码成功', array(
        'is_box'              => false,
        'box_id'              => $boxId,
        'box_sn'              => $box['box_sn'],
        'box_scanned'         => (object)$boxScanned,
        'completed_boxes'     => $completedBoxes,
        'cur_point_completed' => $curPointCompleted,
        'task_all_completed'  => $taskAllCompleted
    ));
}

/* ---------------------------------------------------------------------
 * 5. 修改扫码记录
 * ------------------------------------------------------------------ */
if ($action === 'update_scan_record') {
    if (empty($userPerms['can_edit_record']) && empty($userPerms['is_admin'])) {
        sendJson(false, '您没有修改追溯记录的权限！');
    }

    $scanId    = intval($in['scan_id'] ?? 0);
    $childCode = trim((string)($in['child_code'] ?? ''));
    $childSn   = trim((string)($in['child_sn'] ?? ''));
    $scanPoint = intval($in['scan_point'] ?? 1);
    $boxSn     = trim((string)($in['box_sn'] ?? ''));

    if ($scanId <= 0) sendJson(false, '记录不存在或已被删除！');
    if ($childSn === '') sendJson(false, '二级物料条码不能为空！');
    if ($scanPoint < 1) $scanPoint = 1;

    $st = $db->prepare("SELECT * FROM `trace_scans` WHERE `id` = ? LIMIT 1");
    $st->execute(array($scanId));
    $rec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rec) sendJson(false, '记录不存在或已被删除！');

    $boxId = intval($rec['box_id']);
    if ($boxSn !== '') {
        $stB = $db->prepare("SELECT id FROM `trace_boxes` WHERE `task_order` = ? AND `box_sn` = ? LIMIT 1");
        $stB->execute(array($rec['task_order'], $boxSn));
        $foundBoxId = $stB->fetchColumn();
        if ($foundBoxId === false) {
            $db->prepare("UPDATE `trace_boxes` SET `box_sn` = ? WHERE `id` = ?")->execute(array($boxSn, $boxId));
        } elseif (intval($foundBoxId) !== $boxId) {
            $boxId = intval($foundBoxId);
        }
    }

    $stDup = $db->prepare("SELECT id FROM `trace_scans` WHERE `child_sn` = ? AND `id` <> ? LIMIT 1");
    $stDup->execute(array($childSn, $scanId));
    if ($stDup->fetchColumn() !== false) sendJson(false, '条码【' . $childSn . '】已存在于其他记录中！');

    try {
        $db->prepare("UPDATE `trace_scans` SET `box_id` = ?, `child_code` = ?, `child_sn` = ?, `scan_point` = ? WHERE `id` = ?")
           ->execute(array($boxId, $childCode, $childSn, $scanPoint, $scanId));
    } catch (Throwable $e) {
        sendJson(false, '保存失败：条码可能与已有记录冲突！');
    }

    trRefreshBoxStatus($db, $rec['task_order'], $boxId);
    if (intval($rec['box_id']) !== $boxId) trRefreshBoxStatus($db, $rec['task_order'], intval($rec['box_id']));

    \App\Services\AuditService::log('质量追溯', '修改扫码记录');
    sendJson(true, '修改记录成功');
}

/* ---------------------------------------------------------------------
 * 6. 删除扫码记录
 * ------------------------------------------------------------------ */
if ($action === 'delete_scan_record') {
    if (empty($userPerms['can_delete_record']) && empty($userPerms['is_admin'])) {
        sendJson(false, '您没有删除追溯记录的权限！');
    }

    $scanId  = intval($in['scan_id'] ?? 0);
    $childSn = trim((string)($in['child_sn'] ?? ''));
    if ($scanId <= 0 && $childSn === '') sendJson(false, '删除参数不完整！');

    if ($scanId > 0) {
        $st = $db->prepare("SELECT * FROM `trace_scans` WHERE `id` = ? LIMIT 1");
        $st->execute(array($scanId));
    } else {
        $st = $db->prepare("SELECT * FROM `trace_scans` WHERE `child_sn` = ? LIMIT 1");
        $st->execute(array($childSn));
    }
    $rec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rec) sendJson(false, '记录不存在或已被删除！');

    $db->prepare("DELETE FROM `trace_scans` WHERE `id` = ?")->execute(array($rec['id']));
    trRefreshBoxStatus($db, $rec['task_order'], intval($rec['box_id']));

    \App\Services\AuditService::log('质量追溯', '删除扫码记录');
    sendJson(true, '删除成功');
}

/* ---------------------------------------------------------------------
 * 7. 手工推送至 CEM 平台
 * ------------------------------------------------------------------ */
if ($action === 'manual_push_cem') {
    if (empty($userPerms['can_scan']) && empty($userPerms['can_add_material'])
        && empty($userPerms['can_view']) && empty($userPerms['is_admin'])) {
        sendJson(false, '您没有推送 CEM 的权限！');
    }

    $taskOrder = trim((string)($in['task_order'] ?? ''));
    if ($taskOrder === '') sendJson(false, '请输入要推送的单号！');

    require_once __DIR__ . '/../includes/CemPushService.php';
    @set_time_limit(300);

    try {
        $res = CemPushService::pushTask($taskOrder);
        \App\Services\AuditService::log('质量追溯', '手动推送CEM');
        sendJson(true, 'CEM 推送成功：共上传 ' . intval($res['count'] ?? 0) . ' 条追溯明细', array(
            'count' => intval($res['count'] ?? 0),
            'file'  => $res['file'] ?? ''
        ));
    } catch (Throwable $e) {
        error_log('[trace][manual_push_cem][' . $taskOrder . '] ' . $e->getMessage());
        // 明细只写日志，回显仅保留 CEM 的业务提示（去掉服务器绝对路径、截断长度）
        $msg = $e->getMessage();
        $msg = preg_replace('#/[A-Za-z0-9_.\-]+(/[A-Za-z0-9_.\-]+)+#', '', $msg);
        $msg = trim(preg_replace('/\s+/u', ' ', (string)$msg));
        if ($msg === '') $msg = 'CEM 平台未返回明确结果，请稍后重试';
        if (mb_strlen($msg) > 160) $msg = mb_substr($msg, 0, 160) . '...';
        sendJson(false, 'CEM 推送失败：' . $msg);
    }
}

/* ---------------------------------------------------------------------
 * 8. 导出追溯明细（Excel 优先，CSV 兜底）
 * ------------------------------------------------------------------ */
if ($action === 'export_excel') {
    if (empty($userPerms['can_view']) && empty($userPerms['is_admin'])) {
        sendJson(false, '您没有导出追溯记录的权限！');
    }

    try {
        $search = trim((string)($in['search'] ?? ''));
        $where  = array('1=1');
        $params = array();
        if ($search !== '') {
            $where[] = '(b.task_order LIKE ? OR b.box_sn LIKE ? OR s.child_code LIKE ? OR s.child_sn LIKE ?)';
            for ($i = 0; $i < 4; $i++) $params[] = '%' . $search . '%';
        }
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT b.task_order, b.box_sn, b.parent_code, s.scan_point, s.child_code, s.child_sn, s.scanned_at,
                       COALESCE(NULLIF(u.full_name, ''), u.username, s.scanned_by) AS scanner_name
                FROM `trace_boxes` b
                INNER JOIN `trace_scans` s ON b.id = s.box_id
                LEFT JOIN `users` u ON (
                    CAST(u.badge_no AS CHAR) = CAST(s.scanned_by AS CHAR)
                    OR TRIM(LEADING '0' FROM CAST(u.badge_no AS CHAR)) = TRIM(LEADING '0' FROM CAST(s.scanned_by AS CHAR))
                    OR u.full_name = s.scanned_by
                    OR u.username = s.scanned_by
                )
                WHERE {$whereSql}
                ORDER BY s.id DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $headers = array('二级物料条码(SN)', '二级物料代码', '一级条码/装置号', '产品编码', '扫码工位', '扫码人员', '扫码时间', '工单/单据号');

        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('质量追溯明细');
            $sheet->fromArray($headers, null, 'A1');

            $line = 2;
            foreach ($rows as $r) {
                $sheet->setCellValueExplicit('A' . $line, (string)$r['child_sn'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B' . $line, substr((string)$r['child_code'], 0, 10), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('C' . $line, (string)$r['box_sn'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('D' . $line, substr((string)$r['parent_code'], 0, 10), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('E' . $line, '点 ' . intval($r['scan_point']), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('F' . $line, (string)$r['scanner_name'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('G' . $line, (string)$r['scanned_at'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('H' . $line, (string)$r['task_order'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $line++;
            }

            $sheet->getStyle('A1:H1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle('A1:H1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
            foreach (range('A', 'H') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $filename = '质量追溯明细_' . date('Ymd_His') . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
            header('Cache-Control: max-age=0');
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
            exit;
        }

        // ---- CSV 兜底（未安装 PhpSpreadsheet 时）----
        $filename = '质量追溯明细_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');

        $output = fopen('php://output', 'w');
        fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, array_map('excelSafe', $headers));
        foreach ($rows as $r) {
            fputcsv($output, array_map('excelSafe', array(
                "\t" . $r['child_sn'],
                "\t" . substr((string)$r['child_code'], 0, 10),
                "\t" . $r['box_sn'],
                "\t" . substr((string)$r['parent_code'], 0, 10),
                '点 ' . intval($r['scan_point']),
                $r['scanner_name'],
                $r['scanned_at'],
                "\t" . $r['task_order']
            )));
        }

        fclose($output);
        exit;
    } catch (Throwable $e) {
        \App\Core\Response::exception($e, '导出失败');
    }
}
