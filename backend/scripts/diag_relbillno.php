<?php
declare(strict_types=1);

/**
 * 诊断：CEM 流程卡详情页 Text_Relbillno 提取失败的原因
 *
 * 背景：2026-10-14 批量补数时 6 个 XQ 工单 rel_no 为空（提示"详情页无 Text_Relbillno"），
 *       但用户在 CEM 界面能看到工程号。本脚本抓取指定流程卡的详情页原文，
 *        dump "relbillno"（不区分大小写）出现的所有位置及上下文，用于定位真实结构。
 *
 * 用法（服务器 CLI）：
 *   /www/server/php/82/bin/php backend/scripts/diag_relbillno.php 26101400016
 *   /www/server/php/82/bin/php backend/scripts/diag_relbillno.php xq2026-24083   （按工单号走映射库）
 *
 * 安全：只打印页面结构诊断信息，绝不打印任何凭据。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Not Found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 0. App\ 自动加载（与 public/index.php 一致）----
if (!class_exists('App\\Core\\Env', false)) {
    spl_autoload_register(function ($class) {
        $prefix = 'App\\';
        $baseDir = dirname(__DIR__) . '/app/';
        $class = ltrim($class, '\\');
        if (strncmp($prefix, $class, strlen($prefix)) === 0) {
            $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

// ---- 1. 静默载入 .env（先于任何输出，避免 header 警告）----
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || strpos($line, '=') === false) {
            continue;
        }
        [$n, $v] = explode('=', $line, 2);
        $_ENV[trim($n)] = trim($v, " \t\n\r\0\x0B\"'");
        $_SERVER[trim($n)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}

// ---- 2. 覆盖 checkUser：CLI 无会话，legacy_trace.php 顶层鉴权会 401 退出 ----
if (!function_exists('checkUser')) {
    function checkUser($requireAdmin = false) {
        return array('id' => 0, 'username' => 'cli-diag', 'full_name' => 'CLI 诊断脚本', 'is_admin' => 1);
    }
}

require_once dirname(__DIR__) . '/app/legacy_trace.php';

$kw = trim((string)($argv[1] ?? ''));
if ($kw === '') {
    echo "用法：php diag_relbillno.php <流程卡号或工单号>\n";
    echo "示例：php diag_relbillno.php 26101400016\n";
    echo "      php diag_relbillno.php xq2026-24083\n";
    exit(1);
}

// ---- 3. 解析流程卡号（与 backfill / check_or_fetch_erp 同口径）----
$tasksid = '';
if (preg_match('/^\d+$/', $kw)) {
    $tasksid = (string)$kw;
} else {
    $map = loadMap();
    $matched = array();
    foreach ($map['records'] as $cNo => $data) {
        if (!empty($data['plan_code']) && stripos($data['plan_code'], strtoupper($kw)) !== false) {
            $matched[] = (string)$cNo;
        }
    }
    if (count($matched) === 1) {
        $tasksid = $matched[0];
    } elseif (count($matched) > 1) {
        echo "工单【{$kw}】对应多个流程卡号：" . implode(', ', $matched) . "\n";
        exit(1);
    }
}
if ($tasksid === '') {
    echo "本地映射库未查询到【{$kw}】对应的流程卡\n";
    exit(1);
}

echo "输入：{$kw}  →  流程卡号：{$tasksid}\n";

// ---- 4. 登录并抓取详情页 ----
if (!cemEnsureLogin()) {
    echo "[失败] 登录 CEM 失败\n";
    exit(1);
}

$detailUrl = CEM_HOST . '/Quality_Track/Quality_Tasks_Operation.aspx?Tasksid=' . urlencode($tasksid);
$rawHtml = cemRequest($detailUrl);

echo "详情页 URL：{$detailUrl}\n";
echo '页面长度：' . strlen($rawHtml) . " 字节\n";

if ($rawHtml === '') {
    echo "[失败] 页面为空（curl 失败或被拒）\n";
    exit(1);
}

// 是否被重定向到了登录页（会话失效的典型表现）
if (stripos($rawHtml, 'login.aspx') !== false && stripos($rawHtml, 'Quality_Tasks_Operation') === false) {
    echo "[警告] 页面疑似登录页/会话失效页（含 login.aspx 但不含目标页面标识）\n";
}

// ---- 5. dump 所有 relbillno 出现位置（不区分大小写）----
$hits = preg_match_all('/relbillno/i', $rawHtml);
echo "页面中 'relbillno'（不区分大小写）出现次数：{$hits}\n";

if ($hits > 0) {
    echo "\n===== 每次出现的上下文（前后各 260 字符）=====\n";
    $offset = 0;
    $i = 0;
    while ($i < $hits && ($pos = stripos($rawHtml, 'relbillno', $offset)) !== false) {
        $start = max(0, $pos - 260);
        $ctx = substr($rawHtml, $start, 520);
        // 折叠多余空白便于阅读
        $ctx = preg_replace('/\s+/', ' ', $ctx);
        echo "\n--- 第 " . ($i + 1) . " 次（位置 {$pos}）---\n";
        echo $ctx . "\n";
        $offset = $pos + 9;
        $i++;
        if ($i >= 10) {
            echo "\n（超过 10 处，仅显示前 10 处）\n";
            break;
        }
    }
} else {
    echo "\n页面完全不含 'relbillno' —— 该流程卡详情页没有此字段，\n";
    echo "或字段 id 不同。dump 页面中所有 Text_ 开头的元素 id 供比对：\n";
    if (preg_match_all('/id=["\'](Text_[A-Za-z0-9_]+)["\']/i', $rawHtml, $ids)) {
        $ids = array_unique($ids[1]);
        echo '  ' . implode("\n  ", $ids) . "\n";
    } else {
        echo "  （页面无 Text_ 前缀元素）\n";
    }
    echo "\n===== 页面主体前 1500 字符（折叠空白）=====\n";
    echo preg_replace('/\s+/', ' ', substr($rawHtml, 0, 1500)) . "\n";
}

// ---- 6. 用 UV_Query 列表页反查「关联单据」（列表页是服务端渲染，应该能拿到）----
// 背景：详情页 Quality_Tasks_Operation.aspx 的 Text_Relbillno 是 JS 异步填充，
//       静态 HTML 里是空的；而 UV_Query.aspx（任务管理/流程卡查询）的 GridView1
//       第 4 列「关联单据」是服务端渲染的，所有卡号都能取到。
// 为看清列表页结构，这里同时 dump「初始列表（不搜索）」与「按流程卡号搜索结果」两批行。
function diagDumpUvRows(string $html, string $label, string $tasksid): string
{
    $tableHtml = $html;
    if (preg_match('/<table[^>]*id=["\']GridView1["\'][^>]*>(.*?)<\/table>/is', $html, $tm)) {
        $tableHtml = $tm[0];
    }
    $cardIdx = 0;
    $relIdx  = 4;
    if (preg_match('/<tr[^>]*>(.*?)<\/tr>/is', $tableHtml, $htr)
        && preg_match_all('/<th[^>]*>(.*?)<\/th>/is', $htr[1], $thList)) {
        foreach ($thList[1] as $i => $th) {
            $t = trim(preg_replace('/\s+/', '', strip_tags($th)));
            if ($t === '流程卡号') $cardIdx = $i;
            if ($t === '关联单据') $relIdx  = $i;
        }
    }

    $rows = array();
    if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tableHtml, $trList)) {
        foreach ($trList[1] as $tr) {
            if (stripos($tr, '<th') !== false) continue;
            if (strpos($tr, 'GridView1_lktransid') === false) continue;
            if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $tr, $tdList)) continue;
            $tds = array_map(
                function ($h) { return trim(preg_replace('/\s+/', ' ', strip_tags($h))); },
                $tdList[1]
            );
            if (!isset($tds[$cardIdx]) || !isset($tds[$relIdx])) continue;
            $rows[] = array('card' => $tds[$cardIdx], 'rel' => $tds[$relIdx]);
        }
    }

    echo "\n--- {$label}（共 " . count($rows) . " 行，格式：流程卡号 | 关联单据）---\n";
    $matched = '';
    foreach ($rows as $r) {
        echo "    {$r['card']}  |  {$r['rel']}\n";
        if ($r['card'] === $tasksid || strpos($r['card'], $tasksid) !== false || strpos($tasksid, $r['card']) !== false) {
            $matched = $r['rel'];
        }
    }
    return $matched;
}

$listUrl = CEM_HOST . '/Quality_Track/UV_Query.aspx';
$pageHtml = cemRequest($listUrl, null, CEM_HOST . '/main.aspx');

echo "\n===== UV_Query 列表页反查（目标流程卡号 {$tasksid}）=====\n";
if ($pageHtml === '') {
    echo "  [失败] 无法打开 UV_Query 列表页\n";
} else {
    $m1 = diagDumpUvRows($pageHtml, '初始列表（未搜索）', $tasksid);
    $fields = cemExtractHidden($pageHtml);
    $post = array_merge($fields, array(
        '__EVENTTARGET'   => '',
        '__EVENTARGUMENT' => '',
        'Txt_Find'        => $tasksid,
        'imgbtn.x'        => '10',
        'imgbtn.y'        => '10',
        'hidSQL'          => '',
    ));
    $resHtml = cemRequest($listUrl, $post, $listUrl);
    $m2 = diagDumpUvRows($resHtml, '按流程卡号搜索结果', $tasksid);
    $uvRel = $m1 !== '' ? $m1 : $m2;
    echo "  => 反查得到的关联单据(rel_no)：" . ($uvRel !== '' ? $uvRel : '（两批均未精确匹配到该流程卡号）') . "\n";
    if ($uvRel === '') {
        echo "  => 提示：初始列表(15行)不含目标卡号属正常（分页）；若搜索结果也为空，\n";
        echo "          说明 UV_Query 的 Txt_Find 搜索不匹配流程卡号列，需改用翻页或换关键字。\n";
    }
}

// ---- 7. 用现有正则试跑 + 用正式 CemClient::queryRelNoByCardNo 验证生产路径 ----
$relNo = '';
if (preg_match('/id=["\']Text_Relbillno["\'][^>]*>([^<]+)</i', $rawHtml, $m)) {
    $relNo = trim($m[1]);
}

// 直接走生产方法，验证搜索是否命中、能否稳定取到 rel_no
$prodRel = '';
try {
    require_once dirname(__DIR__) . '/includes/CemClient.php';
    $cem = new CemClient();
    if (!$cem->ensureLogin()) {
        echo "\n[警告] CemClient 登录失败，无法验证生产方法：" . $cem->lastError() . "\n";
    } else {
        $prodRel = $cem->queryRelNoByCardNo($tasksid);
    }
} catch (Throwable $e) {
    echo "\n[警告] 调用 CemClient::queryRelNoByCardNo 异常：" . $e->getMessage() . "\n";
}

echo "\n===== 结论 =====\n";
echo '详情页正则提取 rel_no：' . ($relNo !== '' ? $relNo : '（空 → 提取失败，确认 Text_Relbillno 是 JS 异步填充）') . "\n";
echo 'UV_Query 列表页反查 rel_no：' . ($uvRel ?? '（未执行）') . "\n";
echo 'CemClient::queryRelNoByCardNo 结果：' . ($prodRel !== '' ? $prodRel : '（空 → 生产方法本次未取到，需确认搜索语义）') . "\n";
if ($prodRel !== '') {
    echo "★ 生产方法有效：rel_no 提取逻辑应改为调用 CemClient::queryRelNoByCardNo(流程卡号)。\n";
} elseif ($uvRel !== '') {
    echo "★ UV_Query 手动反查可取，但 CemClient 方法未取到：多为搜索未命中流程卡号列，需改用翻页或换关键字。\n";
} else {
    echo "☆ 两种路径均未取到：需确认 UV_Query 搜索/翻页语义（目标卡号在后续分页）。\n";
}

