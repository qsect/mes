<?php
// gongdan.php — CEM 流程卡与需求计划号查询系统（带文件日志版）
error_reporting(E_ALL);
ini_set('display_errors', '0');   // ★ 安全加固：不再向浏览器回显错误详情

// ★ 安全加固（H5）：CEM 凭据从 .env / config/cem.local.php 读取，不再写死在源码
if (!class_exists('\\App\\Core\\CemConfig')) {
    require_once __DIR__ . '/../Core/CemConfig.php';
}
define('CEM_HOST',           \App\Core\CemConfig::host());
define('CEM_COOKIE_FILE',    sys_get_temp_dir() . '/cem_session_cookie.txt');
define('CEM_USER',           \App\Core\CemConfig::user());
define('CEM_PASS',           \App\Core\CemConfig::pass());
define('CEM_UA',             'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36');
define('CEM_CREATOR_FILTER', '楚永杰');
define('CEM_DEFAULT_DAYS',   30);
define('CEM_FILTER_PREFIXES','6002,6003,6004,7004,7005,7006,710');
// ★ 2026-10-12 相对化：原为 /www/wwwroot/vite/backend/app/cem/... 绝对路径，
//   与部署目录强耦合（换目录即失效）。改用 __DIR__ 后本地/服务器同一份代码通用。
define('MAP_FILE', __DIR__ . '/cem_plan_map.json');
define('LOGS_DIR', __DIR__ . '/logs');

// ★ 连续多少条已存在记录后，在页末停止翻页
define('SKIP_THRESHOLD', 2);

function getFilterPrefixes() { 
    return explode(',', CEM_FILTER_PREFIXES); 
}

function alertAndStop($msg, $redirectUrl = '?') {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>';
    echo '<script>alert(' . json_encode($msg, JSON_UNESCAPED_UNICODE) . '); window.location.href = ' . json_encode($redirectUrl) . ';</script>';
    echo '</body></html>';
    exit;
}

// ==================== 日志记录功能 ====================
function writeSyncFileLog($msg) {
    if (!is_dir(LOGS_DIR)) {
        @mkdir(LOGS_DIR, 0755, true);
    }
    $logFile = LOGS_DIR . '/sync_' . date('Y-m-d') . '.log';
    $time = date('Y-m-d H:i:s');
    $cleanMsg = strip_tags($msg);
    @file_put_contents($logFile, "[{$time}] {$cleanMsg}\n", FILE_APPEND);
}

// ==================== 本地库管理 ====================
function loadMap() {
    if (!file_exists(MAP_FILE)) return array('updated_at' => '', 'records' => array());
    $raw = @file_get_contents(MAP_FILE);
    $arr = json_decode($raw, true);
    return (is_array($arr) && isset($arr['records'])) ? $arr : array('updated_at' => '', 'records' => array());
}

function saveMap($map) {
    @file_put_contents(MAP_FILE . '.tmp', json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @rename(MAP_FILE . '.tmp', MAP_FILE);
}

// ==================== HTTP 请求与转码 ====================
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
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        return "CURL_ERROR: " . $err;
    }
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

// ==================== 提取逻辑 ====================
function extractPlanCodeFromDetail($html) {
    if (preg_match('/(XQ\d{4}[-_][a-zA-Z0-9]+)/i', $html, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/id=["\'][^"\']*Text_plancode[^"\']*["\'][^>]*>([^<]+)<\//i', $html, $m)) {
        $val = trim($m[1]);
        if ($val !== '' && $val !== '&nbsp;') return $val;
    }
    if (preg_match('/<input[^>]*id=["\'][^"\']*Text_plancode[^"\']*["\'][^>]*value=["\']([^"\']+)["\']/i', $html, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/(XQ\d{6,})/i', $html, $m)) {
        return trim($m[1]);
    }
    return '';
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

    $plain = strip_tags(toUtf8($printHtml));
    if (preg_match('/已存在[^\d到\r\n]*(\d+)\s*到/u', $plain, $bm)) {
        return trim($bm[1]);
    }
    if (preg_match('/已存在[^\w到\r\n]*([A-Za-z0-9]+)\s*到/u', $plain, $bm)) {
        return trim($bm[1]);
    }
    return '';
}

function parseListPageRows($html, $cutoffDate, &$isOlderThanCutoff, &$minDateOnPage) {
    $results = array();
    $minDateOnPage = '';
    if (!preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $trList)) return $results;

    $validDatesOnPage = array();

    foreach ($trList[1] as $tr) {
        if (stripos($tr, '<th') !== false) continue;
        if (strpos($tr, 'Page$') !== false) continue;

        $cardNo = '';
        if (preg_match('/GridView1_lktransid_\d+["\'][^>]*>([^<]+)<\/a>/i', $tr, $cm)) {
            $cardNo = trim($cm[1]);
        } elseif (preg_match('/[a-zA-Z0-9_]+_lktransid_\d+["\'][^>]*>([^<]+)<\/a>/i', $tr, $cm)) {
            $cardNo = trim($cm[1]);
        } elseif (preg_match('/<a[^>]*>(\d{8,14})<\/a>/i', $tr, $cm)) {
            $cardNo = trim($cm[1]);
        } elseif (preg_match('/>(\d{8,14})<\/a>/i', $tr, $cm)) {
            $cardNo = trim($cm[1]);
        }
        if (!$cardNo) continue;

        if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $tr, $tdMatches)) continue;

        $tds = array_map(function($v) {
            $v = strip_tags($v);
            $v = html_entity_decode($v, ENT_QUOTES, 'UTF-8');
            $v = str_replace(array("\xC2\xA0", "&nbsp;"), ' ', $v);
            return trim($v);
        }, $tdMatches[1]);

        $creator = '';
        $createDate = '';
        $tdCount = count($tds);

        for ($i = $tdCount - 1; $i >= 0; $i--) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tds[$i])) {
                $createDate = $tds[$i];
                if ($i > 0) $creator = $tds[$i - 1];
                break;
            }
        }

        if ($creator === '' || mb_strpos($creator, CEM_CREATOR_FILTER) === false) {
            foreach ($tds as $td) {
                if ($td !== '' && mb_strpos($td, CEM_CREATOR_FILTER) !== false && $td !== '创建人') {
                    $creator = $td;
                    if ($createDate === '') {
                        foreach ($tds as $td2) {
                            if (preg_match('/\d{4}-\d{2}-\d{2}/', $td2, $dm)) {
                                $createDate = $dm[0];
                                break;
                            }
                        }
                    }
                    break;
                }
            }
        }

        if (!empty($createDate)) {
            $validDatesOnPage[] = $createDate;
        }

        if ($creator !== '' && mb_strpos($creator, CEM_CREATOR_FILTER) !== false) {
            $results[] = array(
                'card_no'     => $cardNo,
                'create_date' => $createDate,
                'creator'     => CEM_CREATOR_FILTER,
            );
        }
    }

    if (!empty($validDatesOnPage)) {
        sort($validDatesOnPage);
        $minDateOnPage = $validDatesOnPage[0];

        $allOlder = true;
        foreach ($validDatesOnPage as $d) {
            if ($d >= $cutoffDate) {
                $allOlder = false;
                break;
            }
        }
        if ($allOlder) {
            $isOlderThanCutoff = true;
        }
    }

    return $results;
}

function findNextPageArgument($html, $currentPage) {
    $targetNum = $currentPage + 1;
    if (preg_match('/__doPostBack\(["\']GridView1["\'],["\'](Page\$' . $targetNum . ')["\']\)/i', $html, $m)) {
        return $m[1];
    }

    if (preg_match_all('/<a[^>]*href=["\']javascript:__doPostBack\((\&quot;|[\'"])GridView1(\&quot;|[\'"]),\s*(\&quot;|[\'"])(Page\$[^\'"]+)(\&quot;|[\'"])\)["\'][^>]*>(.*?)<\/a>/is', $html, $matches)) {
        $args  = $matches[4];
        $texts = $matches[6];

        foreach ($args as $idx => $arg) {
            $btnText = trim(strip_tags($texts[$idx]));
            if ($btnText === '...' || $btnText === '&gt;' || $btnText === '下一页') {
                if (preg_match('/Page\$(\d+)/', $arg, $numMatch)) {
                    if (intval($numMatch[1]) > $currentPage) {
                        return $arg;
                    }
                } else {
                    return $arg;
                }
            }
        }
    }

    if (strpos($html, "Page\${$targetNum}") !== false) {
        return "Page\${$targetNum}";
    }

    return null;
}

function termPrint($msg, $color = '#ffffff') {
    $time = date('H:i:s');
    writeSyncFileLog($msg);

    if (php_sapi_name() === 'cli') {
        echo "[{$time}] {$msg}\n";
    } else {
        $safeMsg = addslashes($msg);
        echo "<script>document.getElementById('console').innerHTML += `<div style=\"color:{$color}\">[{$time}] {$safeMsg}</div>`; window.scrollTo(0, document.body.scrollHeight);</script>";
        echo str_repeat(' ', 4096) . "\n";
        @ob_flush();
        @flush();
    }
}

// ==================== 翻页同步核心逻辑 ====================
function runSyncRealtime($days = CEM_DEFAULT_DAYS) {
    @set_time_limit(0);
    @ini_set('max_execution_time', 0);
    @ini_set('memory_limit', '512M');
    
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Accel-Buffering: no');
        @ini_set('zlib.output_compression', 0);
        @ini_set('implicit_flush', 1);
        while (ob_get_level() > 0) { ob_end_flush(); }
        ob_implicit_flush(1);

        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<style>body{background:#181818;color:#ffffff;font-family:Consolas, Monaco, "Courier New", monospace;font-size:13px;padding:12px;margin:0;line-height:1.6;} #console div{word-break:break-all;}</style>';
        echo '</head><body><div id="console"></div>';
        echo str_repeat(' ', 4096) . "\n";
        @flush();
    }

    $runType = (php_sapi_name() === 'cli') ? 'CLI定时任务' : 'Web人工手动';
    $cutoffDate = date('Y-m-d', strtotime('-' . $days . ' days'));

    termPrint("==================================================", "#00e5ff");
    termPrint("🚀 启动 CEM 流程卡增量同步进程 [模式: {$runType}]", "#00e5ff");
    termPrint("⚙ 同步规则：创建人【" . CEM_CREATOR_FILTER . "】，筛选近 {$days} 天（>= {$cutoffDate}）", "#ffd740");
    termPrint("⚙ 增量策略：连续 " . SKIP_THRESHOLD . " 条已存在则停止翻页（页末判断，不丢当前页剩余卡片）", "#ffd740");
    termPrint("==================================================", "#00e5ff");

    termPrint("正在验证 CEM 登录状态...", "#e0e0e0");
    if (!cemEnsureLogin()) {
        termPrint("❌ 登录失败，请检查账号密码或网络连接。", "#ff5252");
        return;
    }
    termPrint("✔ 登录成功，会话保持正常。", "#69f0ae");

    $listUrl = CEM_HOST . '/Quality_Track/UV_Query.aspx';
    termPrint("正在打开工单列表初始第 1 页...", "#e0e0e0");
    $currentHtml = cemRequest($listUrl, null, CEM_HOST . '/main.aspx');

    $map = loadMap();
    $page = 1;
    $totalAdded = 0;
    $totalSkipped = 0;
    $consecutiveSkipped = 0;

    while ($page <= 300) {
        $isOlderThanCutoff = false;
        $minDateOnPage = '';
        $pageAdded = 0;
        $pageSkipped = 0;

        termPrint("--------------------------------------------------", "#555555");
        termPrint("📄 正在扫描第 {$page} 页工单表格...", "#40c4ff");

        $dbgHtmlLen   = strlen($currentHtml);
        $dbgTrCount   = preg_match_all('/<tr[^>]*>/i', $currentHtml);
        $dbgChuCount  = substr_count($currentHtml, CEM_CREATOR_FILTER);

        $targetCards = parseListPageRows($currentHtml, $cutoffDate, $isOlderThanCutoff, $minDateOnPage);

        termPrint("🔍 [DEBUG] 页长:{$dbgHtmlLen} 行数:{$dbgTrCount} 含'楚永杰':{$dbgChuCount}次 最早日期:{$minDateOnPage}", "#ff9800");
        termPrint("第 {$page} 页命中【" . CEM_CREATOR_FILTER . "】的流程卡 " . count($targetCards) . " 条", "#e0e0e0");

        foreach ($targetCards as $item) {
            $cardNo = $item['card_no'];

            if (!empty($map['records'][$cardNo]['plan_code'])) {
                $consecutiveSkipped++;
                $totalSkipped++;
                $pageSkipped++;
                termPrint("  ↳ [已存在] 流程卡 {$cardNo} 已绑定 [{$map['records'][$cardNo]['plan_code']}] (连续已存在: {$consecutiveSkipped}/" . SKIP_THRESHOLD . ")", "#888888");
                continue;   // ★ 不再 break，只 continue
            }

            // 遇到新记录 → 连续已存在计数归零
            $consecutiveSkipped = 0;

            termPrint("  ↳ 正在读取新卡号 [{$cardNo}] 的详情页...", "#e0e0e0");
            $detailUrl  = CEM_HOST . "/Quality_Track/Quality_Tasks_Operation.aspx?Tasksid={$cardNo}";
            $detailHtml = cemRequest($detailUrl, null, $listUrl, 10);
            $planCode   = extractPlanCodeFromDetail($detailHtml);

            if ($planCode !== '') {
                $map['records'][$cardNo] = array(
                    'card_no'     => $cardNo,
                    'plan_code'   => $planCode,
                    'create_date' => $item['create_date'],
                    'creator'     => CEM_CREATOR_FILTER,
                    'synced_at'   => date('Y-m-d H:i:s'),
                );
                $map['updated_at'] = date('Y-m-d H:i:s');
                saveMap($map);
                $totalAdded++;
                $pageAdded++;
                termPrint("  ✔ 【成功绑定】流程卡 {$cardNo} ➔ 需求计划号: {$planCode}", "#69f0ae");
            } else {
                termPrint("  ✖ 【未识别】流程卡 {$cardNo} 未找到 XQ 计划号", "#ffab40");
            }

            usleep(60000);
        }

        // ★ 停止条件1：连续 N 条已存在（页末才判断，不丢当前页剩余卡片）
        if ($consecutiveSkipped >= SKIP_THRESHOLD) {
            termPrint("💡 本页处理完毕，已连续遇到 {$consecutiveSkipped} 条已同步记录（阈值 " . SKIP_THRESHOLD . "），表明已对接上次数据边界，停止翻页。", "#69f0ae");
            break;
        }

        // ★ 停止条件2：日期越界（硬兜底）
        if ($isOlderThanCutoff) {
            termPrint("⏱ 第 {$page} 页所有单据均早于 {$cutoffDate}（本页最早: {$minDateOnPage}），已达到所选天数边界，停止翻页。", "#ffd740");
            break;
        }

        $nextArgument = findNextPageArgument($currentHtml, $page);
        if (!$nextArgument) {
            termPrint("🏁 页面已无下一页链接或省略号导航，所有工单扫描完毕！", "#00e5ff");
            break;
        }

        termPrint("正在翻向下一页 (导航参数: {$nextArgument})...", "#b0bec5");

        $hidden = cemExtractHidden($currentHtml);
        $nextPost = array_merge($hidden, array(
            '__EVENTTARGET'   => 'GridView1',
            '__EVENTARGUMENT' => $nextArgument,
            'Txt_Find'        => '',
            'hidSQL'          => $hidden['hidSQL'] ?? '',
        ));

        $nextHtml = cemRequest($listUrl, $nextPost, $listUrl, 30);
        if (strpos($nextHtml, 'CURL_ERROR:') === 0) {
            termPrint("❌ 翻页网络超时: " . substr($nextHtml, 11) . "，重试一次...", "#ffab40");
            sleep(1);
            $nextHtml = cemRequest($listUrl, $nextPost, $listUrl, 30);
            if (strpos($nextHtml, 'CURL_ERROR:') === 0) {
                termPrint("❌ 重试依然失败，本次同步安全终止并保留已入库数据。", "#ff5252");
                break;
            }
        }

        $currentHtml = $nextHtml;
        $page++;
    }

    termPrint("==================================================", "#00e5ff");
    termPrint("🎉 同步流程圆满结束！本次新增: {$totalAdded} 条，跳过已存在: {$totalSkipped} 条，本地库累计已收录 " . count($map['records']) . " 条。", "#69f0ae");
    termPrint("📄 详细日志已同步保存至 logs/sync_" . date('Y-m-d') . ".log", "#69f0ae");
    if (php_sapi_name() !== 'cli') {
        termPrint("提示：关闭当前窗口或刷新页面，即可输入需求计划号进行反查。", "#ffffff");
        echo '</body></html>';
    }
}

// ==================== 智能解析并提取纯净物料表格 ====================
function extractCleanDetail($html, $prefixes, &$hasFilteredData, &$metaInfo) {
    $hasFilteredData = false;
    $metaInfo = array(
        'rel_no'   => '',
        'lab_name' => '',
    );
    $html = toUtf8($html);

    if (preg_match('/id=["\']Text_Relbillno["\'][^>]*>([^<]+)<\//i', $html, $m)) {
        $metaInfo['rel_no'] = trim($m[1]);
    }
    if (preg_match('/id=["\']Labname["\'][^>]*>([^<]+)<\//i', $html, $m)) {
        $metaInfo['lab_name'] = trim($m[1]);
    }

    if (!preg_match('/<table[^>]*id=["\']myGridView["\'][^>]*>(.*?)<\/table>/is', $html, $gridMatch)) {
        return '';
    }

    $inner = $gridMatch[1];
    if (!preg_match_all('/<tr[^>]*>.*?<\/tr>/is', $inner, $rows)) {
        return '';
    }

    $cleanRows = '';
    $dataRowCount = 0;

    foreach ($rows[0] as $row) {
        if (stripos($row, '<th') !== false) {
            $thRow = preg_replace('/style=["\'][^"\']*["\']/i', '', $row);
            $cleanRows .= $thRow;
            continue;
        }

        if (strpos($row, 'Page$') !== false) continue;

        if (preg_match('/<td[^>]*>(.*?)<\/td>/is', $row, $cm)) {
            $code = preg_replace('/\s+/', '', trim(strip_tags($cm[1])));
            $matched = empty($prefixes);
            foreach ($prefixes as $p) {
                if ($code !== '' && strpos($code, $p) === 0) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                $rowClean = preg_replace('/<a[^>]*Linkclose[^>]*>.*?<\/a>/is', '', $row);
                $cleanRows .= $rowClean;
                $dataRowCount++;
            }
        }
    }

    if ($dataRowCount > 0) {
        $hasFilteredData = true;
    }

    return '<table class="material-table">' . $cleanRows . '</table>';
}

// ==================== CLI 模式与路由分发 ====================
if (php_sapi_name() === 'cli') {
    $cliDays = CEM_DEFAULT_DAYS;
    if (isset($argv[1]) && is_numeric($argv[1]) && intval($argv[1]) > 0) {
        $cliDays = intval($argv[1]);
    }
    runSyncRealtime($cliDays);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    $customDays = isset($_GET['days']) ? max(1, intval($_GET['days'])) : CEM_DEFAULT_DAYS;
    runSyncRealtime($customDays);
    exit;
}

$kw      = isset($_GET['kw']) ? trim($_GET['kw']) : '';
$tasksid = isset($_GET['tasksid']) ? trim($_GET['tasksid']) : '';
$detailTableHtml = '';
$detailPlanCode  = '';
$detailBarcode   = '';
$metaInfo        = array('rel_no' => '', 'lab_name' => '');

if ($kw !== '' && $tasksid === '') {
    $kwClean = strtoupper($kw);
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
            alertAndStop("需求计划号【{$kw}】对应多个流程卡号：" . implode(', ', $matched) . "，请使用具体流程卡号查询！");
        } else {
            alertAndStop("未查询到需求计划号【{$kw}】的对应信息！请核对输入或点击右上角同步。");
        }
    }
}

if ($tasksid !== '') {
    if (!cemEnsureLogin()) {
        alertAndStop("登录 CEM 失败，请检查网络连接或系统配置。");
    }

    $detailUrl = CEM_HOST . "/Quality_Track/Quality_Tasks_Operation.aspx?Tasksid=" . urlencode($tasksid);
    $rawHtml = cemRequest($detailUrl);

    if (strlen($rawHtml) < 600 || strpos($rawHtml, '未找到') !== false || strpos($rawHtml, '不存在') !== false) {
        alertAndStop("系统内未查询到流程卡号【{$tasksid}】的相关数据！");
    }

    $hasFilteredData = false;
    $detailTableHtml = extractCleanDetail($rawHtml, getFilterPrefixes(), $hasFilteredData, $metaInfo);

    if (!$hasFilteredData) {
        alertAndStop("流程卡号【{$tasksid}】下未查询到符合前缀规则的物料信息！");
    }

    $planCode = extractPlanCodeFromDetail($rawHtml);
    if ($planCode) {
        $detailPlanCode = $planCode;
    }

    $detailBarcode = fetchExistingBarcode($tasksid, $rawHtml);

    if ($planCode || $detailBarcode) {
        $map = loadMap();
        $oldRec = $map['records'][$tasksid] ?? array('card_no' => $tasksid);
        if ($planCode) $oldRec['plan_code'] = $planCode;
        if ($detailBarcode) $oldRec['barcode'] = $detailBarcode;
        $oldRec['synced_at'] = date('Y-m-d H:i:s');
        $map['records'][$tasksid] = $oldRec;
        $map['updated_at'] = date('Y-m-d H:i:s');
        saveMap($map);
    }
}

$map = loadMap();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>CEM 流程卡 & 需求计划号反查系统</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif; background: #f0f2f5; margin: 0; padding: 20px; color: #333; }
    .box { max-width: 1400px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
    .top-bar { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #1890ff; padding-bottom: 12px; margin-bottom: 20px; }
    .sync-area { display: flex; align-items: center; gap: 8px; }
    .days-select { padding: 6px 10px; font-size: 13px; border: 1px solid #d9d9d9; border-radius: 4px; outline: none; background: #fff; }
    .search-row { display: flex; gap: 10px; margin-bottom: 15px; }
    .search-row input { flex: 1; padding: 10px 14px; font-size: 15px; border: 1px solid #d9d9d9; border-radius: 4px; outline: none; }
    .search-row button { padding: 10px 24px; font-size: 15px; background: #1890ff; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
    .search-row button:hover { background: #096dd9; }
    .sync-btn { background: #52c41a; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; font-size: 13px; cursor: pointer; text-decoration: none; font-weight: bold; }
    .sync-btn:hover { background: #389e0d; }
    
    .meta-card { background: #fafafa; border: 1px solid #e8e8e8; border-radius: 6px; padding: 14px 18px; margin: 15px 0 20px; display: flex; flex-wrap: wrap; gap: 18px; align-items: center; font-size: 14px; }
    .meta-item { display: flex; align-items: center; gap: 6px; }
    .meta-item b { color: #555; }
    .meta-val { color: #111; font-weight: bold; }
    .plan-tag { display: inline-block; background: #f6ffed; border: 1px solid #b7eb8f; color: #389e0d; padding: 2px 8px; border-radius: 4px; font-weight: bold; }
    .barcode-tag { display: inline-block; background: #e6f7ff; border: 1px solid #91d5ff; color: #096dd9; padding: 2px 8px; border-radius: 4px; font-weight: bold; }
    
    .material-wrap { overflow-x: auto; margin-top: 10px; }
    .material-table { width: 100%; border-collapse: collapse; font-size: 13px; border: 1px solid #d9d9d9; }
    .material-table th { background: #e6edf8 !important; color: #000000 !important; font-weight: bold; padding: 10px 8px; text-align: left; border: 1px solid #b8cde8; }
    .material-table th a { color: #000000 !important; text-decoration: none; }
    .material-table td { padding: 8px; border: 1px solid #e8e8e8; color: #222; }
    .material-table tr:nth-child(even) { background: #fafafa; }
    .material-table tr:hover { background: #f0f7ff; }
    .material-table input[type="text"] { border: 1px solid #d9d9d9; padding: 3px 6px; border-radius: 3px; font-size: 13px; }
    
    #logFrame { width: 100%; height: 380px; background: #181818; border: 1px solid #333; border-radius: 6px; margin-bottom: 20px; display: none; }
</style>
</head>
<body>
<div class="box">
    <div class="top-bar">
        <h2 style="margin:0; font-size: 20px;">CEM 流程卡 & 需求计划号快速查询</h2>
        <div class="sync-area">
            <span style="font-size: 13px; color: #666; margin-right: 8px;">已收录：<b><?= count($map['records']) ?></b> 条</span>
            <label style="font-size: 13px; color: #555;">同步范围：</label>
            <select id="syncDays" class="days-select">
                <option value="3">近 3 天</option>
                <option value="7">近 7 天</option>
                <option value="15">近 15 天</option>
                <option value="30" selected>近 30 天</option>
                <option value="60">近 60 天</option>
                <option value="90">近 90 天</option>
            </select>
            <a class="sync-btn" href="javascript:void(0)" onclick="startSyncStream()">⚡ 启动人工同步</a>
        </div>
    </div>

    <iframe id="logFrame" frameborder="0"></iframe>

    <form class="search-row" method="get" action="">
        <input type="text" name="kw" value="<?= htmlspecialchars($kw ?: $tasksid) ?>" placeholder="直接输入需求计划号（如 XQ2026-21846）或流程卡号（纯数字）" autofocus>
        <button type="submit">查 询</button>
        <?php if ($kw || $tasksid): ?>
            <a href="?" style="align-self: center; color: #888; text-decoration: none; margin-left: 8px;">重置</a>
        <?php endif; ?>
    </form>

    <?php if ($tasksid !== '' && $detailTableHtml !== ''): ?>
        <div style="margin-top: 15px;">
            <a href="?" style="color: #1890ff; text-decoration: none; font-size: 14px;">← 返回重新查询</a>
            
            <div class="meta-card">
                <div class="meta-item">
                    <b>流程卡号:</b>
                    <span class="meta-val"><?= htmlspecialchars($tasksid) ?></span>
                </div>
                <?php if ($detailPlanCode): ?>
                <div class="meta-item">
                    <b>需求计划号:</b>
                    <span class="plan-tag"><?= htmlspecialchars($detailPlanCode) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($detailBarcode): ?>
                <div class="meta-item">
                    <b>已存在条码:</b>
                    <span class="barcode-tag"><?= htmlspecialchars($detailBarcode) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($metaInfo['rel_no']): ?>
                <div class="meta-item">
                    <b>关联单据号:</b>
                    <span class="meta-val"><?= htmlspecialchars($metaInfo['rel_no']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($metaInfo['lab_name']): ?>
                <div class="meta-item" style="flex-basis: 100%;">
                    <b>工程名称:</b>
                    <span class="meta-val"><?= htmlspecialchars($metaInfo['lab_name']) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="material-wrap">
                <?= $detailTableHtml ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function startSyncStream() {
    const days = document.getElementById('syncDays').value;
    if (!confirm(`确定启动人工同步？将扫描近 ${days} 天工单，遇到连续 2 条已同步记录将自动停止。`)) return;
    const f = document.getElementById('logFrame');
    f.style.display = 'block';
    f.src = `?action=sync&days=${days}&_t=` + new Date().getTime();
}
</script>
</body>
</html>