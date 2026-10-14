<?php
declare(strict_types=1);

/**
 * CEM 推送诊断脚本（CLI，仅限服务器手动执行）
 *
 * 用途：当「上传 CEM」失败、或页面没有任何成功/失败提示时，
 *       用本脚本在命令行完整复现推送流程，把失败点精确定位到具体环节。
 *
 * 用法：
 *   /www/server/php/82/bin/php backend/scripts/diag_cem.php <工单号>
 * 例：
 *   /www/server/php/82/bin/php backend/scripts/diag_cem.php 192881
 * 只检查配置与登录（不实际推送）：
 *   /www/server/php/82/bin/php backend/scripts/diag_cem.php --check
 *
 * ★ 安全：只输出凭据的「是否配置」与长度，绝不打印明文。
 */

// ---- 0. 注册 App\ 自动加载（与 public/index.php 的 PSR-4 完全一致）----
// ★ 本项目没有 composer.json，App\ 命名空间由 index.php 里的 spl_autoload_register 提供。
//   诊断脚本是独立 CLI 入口，不注册它就会在运行期报 Class "App\Core\Env" not found。
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

// ---- 1. 载入 .env（与 public/index.php 保持一致）----
$envFile = dirname(__DIR__) . '/.env';
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
    echo "[OK]   已载入 .env\n";
} else {
    echo "[警告] .env 不存在：{$envFile}\n";
}

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    echo "[错误] 缺少 vendor/autoload.php，PhpSpreadsheet 不可用\n";
}

require_once dirname(__DIR__) . '/includes/CemClient.php';

// ---- 2. 凭据检查（脱敏）----
function cemMask(string $v): string
{
    if ($v === '') {
        return '(空) ← 未配置！';
    }
    return '已配置（长度 ' . mb_strlen($v) . '，不显示明文）';
}

echo "\n========== 一、CEM 凭据 ==========\n";
$host = CemClient::HOST;
$user = CemClient::cemUser();
$pass = CemClient::cemPass();
echo 'CEM_HOST  : ' . $host . "\n";
echo 'CEM_USER  : ' . cemMask($user) . "\n";
echo 'CEM_PASS  : ' . cemMask($pass) . "\n";

if ($user === '' || $pass === '') {
    echo "\n>>> 结论：CEM 凭据缺失，登录必然失败。\n";
    echo "    请在下面任一处补充 CEM_USER / CEM_PASS（二选一即可）：\n";
    echo "      a) /www/wwwroot/vite/backend/.env\n";
    echo "      b) /www/wwwroot/vite/backend/config/cem.local.php\n";
    echo "         内容形如：  <?php return ['CEM_USER' => 'xxx', 'CEM_PASS' => 'yyy'];\n";
    exit(1);
}

// ---- 3. 连通性 + 登录 ----
echo "\n========== 二、网络与登录 ==========\n";
$client = new CemClient();

$t0 = microtime(true);
$ch = curl_init($host . '/login.aspx');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_NOBODY         => true,
]);
curl_exec($ch);
$err  = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
printf("登录页可达性: HTTP %d（%.2fs）%s\n", $code, microtime(true) - $t0, $err ? " cURL错误: {$err}" : '');

if ($code === 0) {
    echo ">>> 结论：服务器连不上 {$host}。请检查：\n";
    echo "    - 服务器能否访问外网 / 该域名（curl -I {$host}/login.aspx）\n";
    echo "    - 是否有防火墙或 DNS 限制\n";
    exit(1);
}

$cookieDir = dirname(CemClient::COOKIE_FILE);
echo 'Cookie 目录: ' . $cookieDir . '（可写: ' . (is_writable($cookieDir) ? '是' : '否 ← 会导致登录失败') . "）\n";

$ok = $client->ensureLogin();
echo 'ensureLogin(): ' . ($ok ? 'true' : 'false') . "\n";
if (!$ok) {
    echo ">>> 结论：CEM 登录失败。常见原因：\n";
    echo "    1) 账号或口令不对 / 已过期\n";
    echo "    2) CEM 改版导致登录页字段名变化（__VIEWSTATE / userName / userPwd）\n";
    echo "    3) Cookie 文件不可写\n";
    exit(1);
}
echo "[OK]   登录成功\n";

// ---- 4. 仅做检查则到此结束 ----
$arg = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($arg === '' || $arg === '--check') {
    echo "\n（未指定工单号，跳过实际推送）\n";
    exit(0);
}

// ---- 5. 完整复现推送 ----
echo "\n========== 三、完整推送复现：{$arg} ==========\n";
require_once dirname(__DIR__) . '/includes/CemPushService.php';
require_once dirname(__DIR__) . '/app/Core/Database.php';

try {
    $db = App\Core\Database::getConnection('default');
    $st = $db->prepare("SELECT COUNT(*) FROM `trace_scans` s
                        INNER JOIN `trace_boxes` b ON b.id = s.box_id
                        WHERE b.task_order = ?");
    $st->execute([$arg]);
    $cnt = (int)$st->fetchColumn();
    echo "该工单扫码明细数: {$cnt}\n";
    if ($cnt === 0) {
        echo ">>> 该工单没有扫码记录，pushTask 会抛「没有任何扫码追溯记录」\n";
    }

    $res = CemPushService::pushTask($arg);
    echo "[成功] " . json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
} catch (Throwable $e) {
    echo '[失败] ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo "\n提示：上面这条消息就是页面应该回显给用户的内容。\n";
    echo "      若页面「完全没有提示」，问题在前端回显链路而非 CEM 本身。\n";
    exit(1);
}
