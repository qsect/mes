<?php
declare(strict_types=1);

/**
 * 追溯工单 tasksid / rel_no 补数脚本（CLI，仅限服务器手动执行）
 *
 * 背景：2026-10-14 修复上线前创建的工单，旧版 save_config 从不写 tasksid/rel_no
 *       （注释却称"由 check_or_fetch_erp 阶段写入"，实际无任何代码写这两列），
 *       导致推送 CEM 时 H/I 列回退为 XQ 工单号本身，CEM 界面按流程卡号索引查不到。
 *       本脚本按 check_or_fetch_erp 的同一口径（本地映射库 + CEM 流程卡详情页）
 *       补齐这两列，不依赖前端重新保存，也不触碰扫码记录与物料配置。
 *
 * 用法：
 *   /www/server/php/82/bin/php backend/scripts/backfill_tasksid.php XQ2026-23895
 *   /www/server/php/82/bin/php backend/scripts/backfill_tasksid.php --all-xq
 *   /www/server/php/82/bin/php backend/scripts/backfill_tasksid.php XQ2026-23895 --force
 *
 * 说明：
 *   - 默认只补 tasksid 为空的工单；--force 才覆盖已有值（慎用）。
 *   - 数字工单不在处理范围：其流程卡号在推送/导出时实时查询 CEM，无需落库。
 *   - ★ 安全：只打印工单号与解析结果，绝不打印任何凭据。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Not Found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 0. 注册 App\ 自动加载（与 public/index.php 的 PSR-4 完全一致）----
// ★ 本项目没有 composer.json，App\ 命名空间由 index.php 的 spl_autoload_register 提供。
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

// ---- 1. 静默载入 .env（不 echo：后面 require 的 response.php 会 session_start/header，
//        先有输出就会刷一排 "headers already sent" 警告）----
$envFile = dirname(__DIR__) . '/.env';
$envLoaded = false;
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            [$n, $v] = explode('=', $line, 2);
            $n = trim($n);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            $_ENV[$n] = $v;
            $_SERVER[$n] = $v;
        }
    }
    $envLoaded = true;
}

// ---- 2. 覆盖 checkUser：CLI 无会话，而 legacy_trace.php 顶层第 270 行
//        `$user = checkUser();` 每次 include 都会执行，不覆盖就 401 退出。
//        该文件全部函数都有 function_exists 守卫，这里是既定扩展点。
//        返回合成管理员：isAdmin=true → getTraceUserPerms 直接早返回全权限，
//        不查 trace_permissions、不写任何数据。 ----
if (!function_exists('checkUser')) {
    function checkUser($requireAdmin = false) {
        return array('id' => 0, 'username' => 'cli-backfill', 'full_name' => 'CLI 补数脚本', 'is_admin' => 1);
    }
}

// ---- 3. 引入遗留追溯脚本，复用 loadMap / cemEnsureLogin / cemRequest ----
//    与 check_or_fetch_erp 的 XQ 分支逐行同口径；CLI 下 $action 为空，不触发任何业务分支，
//    顶层仅执行幂等 DDL 初始化（生产表现状即如此，无副作用）。
require_once dirname(__DIR__) . '/app/legacy_trace.php';

echo $envLoaded ? "[OK]   已载入 .env\n" : "[警告] .env 不存在：{$envFile}\n";
echo "[OK]   追溯模块已载入（CLI 合成管理员，不触碰权限表）\n";

foreach (array('loadMap', 'cemEnsureLogin', 'cemRequest') as $fn) {
    if (!function_exists($fn)) {
        echo "  [错误] 追溯模块函数缺失：{$fn}，请确认 legacy_trace.php 已上传\n";
        exit(1);
    }
}

/**
 * 按 check_or_fetch_erp 的 XQ 分支同口径解析流程卡号与工程关联单据号
 *
 * @return array{0:string,1:string} [tasksid, rel_no]
 * @throws RuntimeException 任一环节失败时抛出，信息可直接展示给运维
 */
function resolveXqCard(string $wo): array
{
    $kwClean = strtoupper($wo);
    $tasksid = '';

    if (preg_match('/^\d+$/', $kwClean)) {
        // 与 check_or_fetch_erp 一致：纯数字输入按流程卡号本身处理
        $tasksid = (string)$kwClean;
    } else {
        $map = loadMap();
        $matched = array();
        foreach ($map['records'] as $cNo => $data) {
            if (!empty($data['plan_code']) && stripos($data['plan_code'], $kwClean) !== false) {
                $matched[] = $cNo;
            }
        }
        if (count($matched) === 1) {
            // ★ JSON 映射库的流程卡号是数字键，json_decode 后会变成 int；
            //   本脚本 declare(strict_types=1)，必须显式转字符串，
            //   否则后续 urlencode() 等内部函数报 TypeError。
            $tasksid = (string)$matched[0];
        } elseif (count($matched) > 1) {
            throw new RuntimeException('需求计划号【' . $wo . '】对应多个流程卡号：' . implode(', ', $matched) . '，请使用具体流程卡号查询');
        }
    }

    if ($tasksid === '') {
        throw new RuntimeException('本地映射库未查询到【' . $wo . '】对应的流程卡！请先在【流程卡查询】页面同步映射');
    }

    // ★ rel_no 取自已服务渲染的 UV_Query 列表页第 4 列「关联单据」，
    //   详情页 Text_Relbillno 是 JS 异步填充，静态 HTML 永远为空（2026-10-14 踩雷）。
    require_once dirname(__DIR__) . '/includes/CemClient.php';
    $cem = new CemClient();
    if (!$cem->ensureLogin()) {
        throw new RuntimeException('登录 CEM 失败，请检查网络连接与凭据配置');
    }
    $relNo = $cem->queryRelNoByCardNo($tasksid);

    return array($tasksid, $relNo);
}

/**
 * 补一个工单；不触碰 trace_scans / trace_boxes / trace_bom_config
 *
 * @return array{0:string,1:string} [status, message]，status ∈ ok|skip|fail
 */
function backfillOne(PDO $db, string $wo, bool $force): array
{
    $st = $db->prepare("SELECT `task_order`, `order_type`, `tasksid`, `rel_no` FROM `trace_tasks` WHERE `task_order` = ? LIMIT 1");
    $st->execute(array($wo));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return array('skip', 'trace_tasks 无此记录');
    }

    $isXq = ($row['order_type'] === 'xq_project' || stripos($wo, 'XQ') === 0);
    if (!$isXq) {
        return array('skip', '数字工单的流程卡号在推送/导出时实时查询 CEM，无需补数');
    }

    $curTasksid = trim((string)($row['tasksid'] ?? ''));
    if ($curTasksid !== '' && !$force) {
        return array('skip', 'tasksid 已有值（' . $curTasksid . '），如需覆盖请加 --force');
    }

    list($tasksid, $relNo) = resolveXqCard($wo);
    if ($tasksid === '') {
        return array('fail', '解析结果为空，请检查映射库与 CEM 数据');
    }

    $db->prepare("UPDATE `trace_tasks` SET `tasksid` = ?, `rel_no` = ? WHERE `task_order` = ?")
       ->execute(array($tasksid, $relNo, $wo));

    $relShow = ($relNo !== '') ? $relNo : '(空，CEM 详情页无 Text_Relbillno)';
    return array('ok', 'tasksid=' . $tasksid . '  rel_no=' . $relShow);
}

// ---- 4. 解析参数 ----
$args  = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$allXq = in_array('--all-xq', $args, true);
$args  = array_values(array_diff($args, array('--force', '--all-xq')));

if (!$args && !$allXq) {
    echo "用法：\n";
    echo "  php backfill_tasksid.php <工单号> [更多工单号...]   补指定工单（默认只补空值）\n";
    echo "  php backfill_tasksid.php --all-xq                  补全部缺 tasksid 的 XQ 工单\n";
    echo "  php backfill_tasksid.php <工单号> --force          覆盖已有值（慎用）\n";
    exit(1);
}

$db = \App\Core\Database::getConnection();

$targets = $args;
if ($allXq) {
    $st = $db->query("SELECT `task_order` FROM `trace_tasks`
                      WHERE (`tasksid` IS NULL OR `tasksid` = '' OR `rel_no` IS NULL OR `rel_no` = '')
                        AND (`order_type` = 'xq_project' OR `task_order` LIKE 'XQ%')
                      ORDER BY `task_order`");
    $targets = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$targets) {
        echo "[完成] 没有缺 tasksid 的 XQ 工单，无需补数\n";
        exit(0);
    }
    echo '共 ' . count($targets) . " 个 XQ 工单待补数\n";
}

// ---- 5. 逐工单补数 ----
$okCount = $skipCount = $failCount = 0;
foreach ($targets as $wo) {
    $wo = trim((string)$wo);
    if ($wo === '') {
        continue;
    }
    try {
        list($status, $msg) = backfillOne($db, $wo, $force);
        if ($status === 'ok') {
            echo "  [OK]     {$wo}：{$msg}\n";
            $okCount++;
        } elseif ($status === 'skip') {
            echo "  [跳过]   {$wo}：{$msg}\n";
            $skipCount++;
        } else {
            echo "  [失败]   {$wo}：{$msg}\n";
            $failCount++;
        }
    } catch (Throwable $e) {
        echo '  [失败]   ' . $wo . '：' . $e->getMessage() . "\n";
        $failCount++;
    }
}

echo "\n========== 汇总 ==========\n";
echo "补数 {$okCount} 项 / 跳过 {$skipCount} 项 / 失败 {$failCount} 项\n";
if ($okCount > 0) {
    echo "提示：补数后即可重新推送 CEM（H 列=流程卡号、I 列=工程号），CEM 界面即可检索到。\n";
}
exit($failCount > 0 ? 1 : 0);
