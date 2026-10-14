<?php declare(strict_types=1);

// 1. 全局配置与 JSON 错误处理
ini_set("display_errors", "0");
error_reporting(E_ALL);

// ★ 安全加固：异常详情只写日志，绝不回显给客户端（避免泄露路径 / SQL / 调用栈）
set_exception_handler(function (Throwable $e) {
    $rid = bin2hex(random_bytes(6));
    error_log("[{$rid}] " . get_class($e) . ": " . $e->getMessage()
        . " @ " . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString());

    http_response_code(500);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "success"    => false,
        "message"    => "系统内部错误，请稍后重试或联系管理员",
        "request_id" => $rid,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// 2. PSR-4 自动加载
spl_autoload_register(function ($class) {
    $prefix = "App\\";
    $baseDir = __DIR__ . "/../app/";
    // ★ 容错：类名可能带前导反斜杠（如 "\App\Middlewares\X"），先剥掉再比对，
    //   否则 strncmp 不匹配会静默跳过加载 → 运行期 "Class not found" → 500。
    $class = ltrim($class, "\\");
    if (strncmp($prefix, $class, strlen($prefix)) === 0) {
        $file = $baseDir . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// 2.1 加载 Composer 第三方依赖（PhpSpreadsheet 等，供 Excel 导入导出使用）
//     本地若未安装 vendor 会自动跳过，相关功能将自动回退为 CSV 格式
$composerAutoload = __DIR__ . "/../vendor/autoload.php";
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// 3. 加载 .env
$envFile = __DIR__ . "/../.env";
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === "" || str_starts_with($line, "#")) continue;
        if (strpos($line, "=") !== false) {
            [$name, $value] = explode("=", $line, 2);
            $name  = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// 4. 跨域与 Session 初始化
// ★ 安全加固：CORS 仅放行自有域名，不再使用 "*"（可由 .env 的 CORS_ORIGINS 覆盖，逗号分隔）
$corsAllowed = ['https://ieslab.com', 'https://www.ieslab.com'];
if (!empty($_ENV['CORS_ORIGINS'])) {
    $corsAllowed = array_values(array_filter(array_map('trim', explode(',', (string)$_ENV['CORS_ORIGINS']))));
}
$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($reqOrigin !== '' && in_array($reqOrigin, $corsAllowed, true)) {
    header('Access-Control-Allow-Origin: ' . $reqOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-Id, X-Requested-With, X-Token");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ★ 安全加固（L7 收尾）：会话凭证只允许走请求头，不再从 URL 查询串读取。
//   原因：把 ?token=<session_id> 写进 URL 会经由 nginx access_log、浏览器历史、
//   Referer 外泄；而 L7 之后前端已完全依赖 HttpOnly Cookie，URL 传参已无用途。
$token = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["HTTP_X_TOKEN"] ?? $_SERVER["HTTP_X_SESSION_ID"] ?? "";
if (str_starts_with($token, "Bearer ")) {
    $token = trim(substr($token, 7));
}
$token = trim((string)$token);

// ★ 安全加固：过滤客户端历史遗留 / 异常传值。
//   旧版前端曾把 token 存进 localStorage，取空时会回传字符串 "undefined"；
//   而 "undefined" 恰好全部落在 PHP 会话 ID 的合法字符集内，PHP 会真的接受它，
//   导致所有这类客户端共用同一个 session_id。这里统一置空，让其回落到 Cookie 会话。
$badTokens = ['undefined', 'null', 'false', 'nan', 'empty'];

$headerSid = trim((string)($_SERVER["HTTP_X_SESSION_ID"] ?? ""));
if (in_array(strtolower($headerSid), $badTokens, true)) {
    $headerSid = "";
}
if (in_array(strtolower($token), $badTokens, true)) {
    $token = "";
}

// 如果存在 Session ID，则在 session_start 之前设定（仅接受请求头来源，且必须是合法会话 ID 格式）
if ($headerSid !== "" && preg_match('/^[a-zA-Z0-9,\-]{16,128}$/', $headerSid)) {
    session_id($headerSid);
} elseif (!empty($token) && preg_match('/^[a-zA-Z0-9,\-]{16,128}$/', $token)) {
    session_id($token);
}

// ★ 安全加固：显式设置会话 Cookie 属性（HttpOnly / SameSite=Lax / 尽可能 Secure）
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
$sessionCookieParams = [
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
];

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params($sessionCookieParams);
    session_start();
}

// 若会话已开启但 user 仍为空，且传入了与当前 session_id 不同的有效 token，尝试切换并载入
if (empty($_SESSION["user"]) && !empty($token) && session_id() !== $token && preg_match('/^[a-zA-Z0-9,\-]{16,128}$/', $token)) {
    session_write_close();
    session_set_cookie_params($sessionCookieParams);
    session_id($token);
    session_start();
}

// ★ 安全加固：会话空闲超时（默认 8 小时，可由 .env 的 SESSION_IDLE_TIMEOUT 覆盖）
$idleTimeout = (int)($_ENV['SESSION_IDLE_TIMEOUT'] ?? 28800);
if ($idleTimeout > 0 && !empty($_SESSION['user'])) {
    $lastActive = (int)($_SESSION['last_active'] ?? 0);
    if ($lastActive > 0 && (time() - $lastActive) > $idleTimeout) {
        // 超过空闲时限：清空会话，后续鉴权会自然返回 401 要求重新登录
        $_SESSION = [];
    } else {
        $_SESSION['last_active'] = time();
    }
}

if (!function_exists("getDB")) {
    function getDB() {
        return \App\Core\Database::getConnection();
    }
}

if (!function_exists("getMesDB")) {
    /**
     * MES 生产数据源（独立 MySQL 库）
     * 读取 .env：MES_DB_HOST / MES_DB_PORT / MES_DB_NAME / MES_DB_USER / MES_DB_PASS
     * @return \PDO|null
     */
    function getMesDB() {
        static $pdo = null;
        if ($pdo instanceof \PDO) return $pdo;

        try {
            $host = $_ENV['MES_DB_HOST'] ?? ($_SERVER['MES_DB_HOST'] ?? '');
            $port = $_ENV['MES_DB_PORT'] ?? ($_SERVER['MES_DB_PORT'] ?? '3306');
            $name = $_ENV['MES_DB_NAME'] ?? ($_SERVER['MES_DB_NAME'] ?? '');
            $user = $_ENV['MES_DB_USER'] ?? ($_SERVER['MES_DB_USER'] ?? '');
            $pass = $_ENV['MES_DB_PASS'] ?? ($_SERVER['MES_DB_PASS'] ?? '');

            if ($host === '' || $name === '') return null;

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_TIMEOUT            => 5,
            ]);
            return $pdo;
        } catch (\Throwable $e) {
            error_log('[getMesDB] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists("getSqlSrvDB")) {
    /**
     * 外部 SQL Server 工单库（ERP 数据源）
     * ★ 改用 FreeTDS dblib 驱动（与 LabelPrintController 一致，无需 pdo_sqlsrv 扩展）
     * 读取 .env：SQLSRV_HOST / SQLSRV_PORT / SQLSRV_DB / SQLSRV_USER / SQLSRV_PASS
     * @return \PDO|null
     */
    function getSqlSrvDB() {
        static $pdo = null;
        static $failed = false;
        if ($pdo instanceof \PDO) return $pdo;
        if ($failed) return null;

        try {
            $host = $_ENV['SQLSRV_HOST'] ?? ($_SERVER['SQLSRV_HOST'] ?? '');
            $port = $_ENV['SQLSRV_PORT'] ?? ($_SERVER['SQLSRV_PORT'] ?? '1433');
            $name = $_ENV['SQLSRV_DB']   ?? ($_SERVER['SQLSRV_DB']   ?? '');
            $user = $_ENV['SQLSRV_USER'] ?? ($_SERVER['SQLSRV_USER'] ?? '');
            $pass = $_ENV['SQLSRV_PASS'] ?? ($_SERVER['SQLSRV_PASS'] ?? '');

            if ($host === '') {
                error_log('[getSqlSrvDB] SQLSRV_HOST 为空');
                $failed = true;
                return null;
            }

            // ★ 关键：dblib 驱动，DSN 用「冒号」分隔 host 和 port
            $dsn = "dblib:host={$host}:{$port};dbname={$name};charset=UTF-8";
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT            => 8,
            ]);

            // 与 LabelPrintController 保持一致，设置会话选项
            $pdo->exec("SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; SET ANSI_PADDING ON; SET CONCAT_NULL_YIELDS_NULL ON; SET QUOTED_IDENTIFIER ON;");

            return $pdo;
        } catch (\Throwable $e) {
            error_log('[getSqlSrvDB] ' . $e->getMessage());
            $failed = true;
            return null;
        }
    }
}

if (!function_exists("checkUser")) {
    function checkUser($requireAdmin = false) {
        $user = $_SESSION["user"] ?? [];
        if (empty($user)) {
            \App\Core\Response::error("未登录或登录已超时", ["need_login" => true], 401);
            exit;
        }
        if ($requireAdmin && empty($user["is_admin"])) {
            \App\Core\Response::error("权限不足，仅管理员可访问", [], 403);
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

if (!function_exists("excelSafe")) {
    /**
     * ★ 安全加固（L1）：防止 Excel / CSV 公式注入。
     * 以 = + - @ 开头的单元格会被 Excel 当作公式执行，统一加前置单引号转为文本。
     * 同时覆盖「制表符/回车 + 公式前缀」的常见绕过组合（不影响单纯以制表符强制文本的列）。
     */
    function excelSafe($value) {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (preg_match('/^[=+\-@]/', $value)) {
            return "'" . $value;
        }
        if (preg_match('/^[\t\r\n]+[=+\-@]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }
}

// 5. 核心路由表注册
$router = new \App\Core\Router();

$router->add("POST", "/api/auth/login",               [\App\Controllers\AuthController::class, "login"]);
$router->add("GET",  "/api/auth/me",                  [\App\Controllers\AuthController::class, "me"]);
$router->add("POST", "/api/auth/logout",              [\App\Controllers\AuthController::class, "logout"]);
$router->add("POST", "/api/auth/change_password",     [\App\Controllers\AuthController::class, "changePassword"]);
$router->add("GET",  "/api/system/users",             [\App\Controllers\SystemController::class, "users"]);
$router->add("POST", "/api/system/users/save",        [\App\Controllers\SystemController::class, "saveUser"]);
$router->add("POST", "/api/system/users/reset-all-passwords", [\App\Controllers\SystemController::class, "resetAllPasswords"]);
$router->add("POST", "/api/system/users/delete",      [\App\Controllers\SystemController::class, "deleteUser"]);
$router->add("GET",  "/api/system/roles",             [\App\Controllers\SystemController::class, "roles"]);
$router->add("GET",  "/api/system/role-modules",      [\App\Controllers\SystemController::class, "roleModules"]);
$router->add("POST", "/api/system/role-modules/save", [\App\Controllers\SystemController::class, "saveRolePermissions"]);

// ===== 用户级权限（含细分权限） =====
$router->add("GET",  "/api/system/permissions",       [\App\Controllers\SystemController::class, "userPermissions"]);
$router->add("POST", "/api/system/permissions/save",  [\App\Controllers\SystemController::class, "saveUserPermissions"]);
// ===================================

$router->add("GET",  "/api/system/audit-logs",        [\App\Controllers\SystemController::class, "auditLogs"]);

// 模块管理
$router->add("GET",  "/api/system/modules",               [\App\Controllers\SystemController::class, "modules"]);
$router->add("POST", "/api/system/modules/sync",          [\App\Controllers\SystemController::class, "syncModules"]);
$router->add("POST", "/api/system/modules/save",          [\App\Controllers\SystemController::class, "saveModule"]);
$router->add("POST", "/api/system/modules/toggle-status", [\App\Controllers\SystemController::class, "toggleModuleStatus"]);
$router->add("POST", "/api/system/modules/delete",        [\App\Controllers\SystemController::class, "deleteModule"]);

// 包装配送与齐套（DispatchKitting）
$router->add("GET",  "/api/dispatch_kitting/query",             [\App\Controllers\DispatchKittingController::class, "query"], [['App\\Middlewares\\ModuleViewMiddleware', 'dispatch_kitting']]);
$router->add("POST", "/api/dispatch_kitting/query",             [\App\Controllers\DispatchKittingController::class, "query"], [['App\\Middlewares\\ModuleViewMiddleware', 'dispatch_kitting']]);
$router->add("POST", "/api/dispatch_kitting/confirm_receipt",   [\App\Controllers\DispatchKittingController::class, "confirmReceipt"], [['App\\Middlewares\\ModuleViewMiddleware', 'dispatch_kitting']]);
$router->add("GET",  "/api/dispatch_kitting/query_wo_kitting",  [\App\Controllers\DispatchKittingController::class, "queryWoKitting"], [['App\\Middlewares\\ModuleViewMiddleware', 'dispatch_kitting']]);
$router->add("POST", "/api/dispatch_kitting/query_wo_kitting",  [\App\Controllers\DispatchKittingController::class, "queryWoKitting"], [['App\\Middlewares\\ModuleViewMiddleware', 'dispatch_kitting']]);
$router->add("GET",  "/api/dispatch_kitting/query_wo_materials",[\App\Controllers\DispatchKittingController::class, "queryWoMaterials"], [['App\\Middlewares\\ModuleViewMiddleware', 'dispatch_kitting']]);
$router->add("POST", "/api/dispatch_kitting/query_wo_materials",[\App\Controllers\DispatchKittingController::class, "queryWoMaterials"], [['App\\Middlewares\\ModuleViewMiddleware', 'dispatch_kitting']]);

// 追溯模块
$router->add("GET",  "/api/trace/init",               [\App\Controllers\TraceController::class, "init"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("GET",  "/api/trace/checkOrFetchErp",    [\App\Controllers\TraceController::class, "checkOrFetchErp"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("POST", "/api/trace/saveConfig",         [\App\Controllers\TraceController::class, "saveConfig"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("GET",  "/api/trace/startTrace",         [\App\Controllers\TraceController::class, "startTrace"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("POST", "/api/trace/scanBarcode",        [\App\Controllers\TraceController::class, "scanBarcode"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("GET",  "/api/trace/queryRecords",       [\App\Controllers\TraceController::class, "queryRecords"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("POST", "/api/trace/updateScanRecord",   [\App\Controllers\TraceController::class, "updateScanRecord"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("POST", "/api/trace/deleteScanRecord",   [\App\Controllers\TraceController::class, "deleteScanRecord"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("GET",  "/api/trace/listConfigs",        [\App\Controllers\TraceController::class, "listConfigs"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("GET",  "/api/trace/listPermissions",    [\App\Controllers\TraceController::class, "listPermissions"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("POST", "/api/trace/savePermissions",    [\App\Controllers\TraceController::class, "savePermissions"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("GET",  "/api/trace/exportExcel",        [\App\Controllers\TraceController::class, "exportExcel"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("POST", "/api/trace/exportExcel",        [\App\Controllers\TraceController::class, "exportExcel"], [['App\\Middlewares\\TraceViewMiddleware']]);
$router->add("POST", "/api/trace/manualPushCem",      [\App\Controllers\TraceController::class, "manualPushCem"], [['App\\Middlewares\\TraceViewMiddleware']]);

// 讨论区模块 (Discuz / Discuss)
$router->add("GET",  "/api/discuss/init",             [\App\Controllers\DiscussController::class, "init"], [['App\\Middlewares\\ModuleViewMiddleware', 'discuss']]);
$router->add("GET",  "/api/discuss/list_topics",      [\App\Controllers\DiscussController::class, "listTopics"], [['App\\Middlewares\\ModuleViewMiddleware', 'discuss']]);
$router->add("GET",  "/api/discuss/get_topic_detail", [\App\Controllers\DiscussController::class, "getTopicDetail"], [['App\\Middlewares\\ModuleViewMiddleware', 'discuss']]);
$router->add("POST", "/api/discuss/create_topic",     [\App\Controllers\DiscussController::class, "createTopic"], [['App\\Middlewares\\ModuleViewMiddleware', 'discuss']]);
$router->add("POST", "/api/discuss/edit_topic",       [\App\Controllers\DiscussController::class, "editTopic"], [['App\\Middlewares\\ModuleViewMiddleware', 'discuss']]);
$router->add("POST", "/api/discuss/reply_topic",      [\App\Controllers\DiscussController::class, "replyTopic"], [['App\\Middlewares\\ModuleViewMiddleware', 'discuss']]);
$router->add("POST", "/api/discuss/upload",           [\App\Controllers\DiscussController::class, "upload"], [['App\\Middlewares\\ModuleViewMiddleware', 'discuss']]);
$router->add("POST", "/api/discuss/manage_action",    [\App\Controllers\DiscussController::class, "manageAction"], [['App\\Middlewares\\ModuleViewMiddleware', 'discuss']]);
$router->add("GET",  "/api/discuss/list_notifications", [\App\Controllers\DiscussController::class, "listNotifications"], [['App\\Middlewares\\ModuleViewMiddleware', 'discuss']]);
$router->add("POST", "/api/discuss/mark_all_read",    [\App\Controllers\DiscussController::class, "markAllRead"], [['App\\Middlewares\\ModuleViewMiddleware', 'discuss']]);
// 首页公告（公开，不受讨论区查看权限限制）
$router->add("GET",  "/api/discuss/list_notices",      [\App\Controllers\DiscussController::class, "listNotices"]);
$router->add("GET",  "/api/discuss/get_notice_detail", [\App\Controllers\DiscussController::class, "getNoticeDetail"]);
// 公告已读（按用户持久化）
$router->add("GET",  "/api/discuss/list_notice_reads",[\App\Controllers\DiscussController::class, "listNoticeReads"]);
$router->add("POST", "/api/discuss/mark_notice_read", [\App\Controllers\DiscussController::class, "markNoticeRead"]);

// 条码验证模块 (Barcode Validator)
$router->add("GET",  "/api/barcode_validator/fetch_workorder",    [\App\Controllers\BarcodeValidatorController::class, "fetchWorkorder"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("POST", "/api/barcode_validator/fetch_workorder",    [\App\Controllers\BarcodeValidatorController::class, "fetchWorkorder"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("POST", "/api/barcode_validator/upload_reference",   [\App\Controllers\BarcodeValidatorController::class, "uploadReference"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("GET",  "/api/barcode_validator/progress",           [\App\Controllers\BarcodeValidatorController::class, "progress"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("POST", "/api/barcode_validator/scan_validate",      [\App\Controllers\BarcodeValidatorController::class, "scanValidate"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("POST", "/api/barcode_validator/submit_row",         [\App\Controllers\BarcodeValidatorController::class, "submitRow"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("GET",  "/api/barcode_validator/get_completed_rows", [\App\Controllers\BarcodeValidatorController::class, "getCompletedRows"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("GET",  "/api/barcode_validator/get_settings",       [\App\Controllers\BarcodeValidatorController::class, "getSettings"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("POST", "/api/barcode_validator/save_settings",      [\App\Controllers\BarcodeValidatorController::class, "saveSettings"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("GET",  "/api/barcode_validator/get_reference_rows", [\App\Controllers\BarcodeValidatorController::class, "getReferenceRows"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("POST", "/api/barcode_validator/delete_completed",   [\App\Controllers\BarcodeValidatorController::class, "deleteCompleted"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("GET",  "/api/barcode_validator/export_completed",   [\App\Controllers\BarcodeValidatorController::class, "exportCompleted"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("GET",  "/api/barcode_validator/get_my_perm",        [\App\Controllers\BarcodeValidatorController::class, "getMyPerm"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);
$router->add("POST", "/api/barcode_validator/update_completed",   [\App\Controllers\BarcodeValidatorController::class, "updateCompleted"], [['App\\Middlewares\\ModuleViewMiddleware', 'barcode_validator']]);

// 资产台账模块 (Assets)
$router->add("GET",  "/api/assets/dashboard_stats",        [\App\Controllers\AssetsController::class, "dashboardStats"], [['App\\Middlewares\\ModuleViewMiddleware', 'assets']]);
$router->add("GET",  "/api/assets/get_categories",         [\App\Controllers\AssetsController::class, "getCategories"], [['App\\Middlewares\\ModuleViewMiddleware', 'assets']]);
$router->add("GET",  "/api/assets/list",                   [\App\Controllers\AssetsController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'assets']]);
$router->add("POST", "/api/assets/save",                   [\App\Controllers\AssetsController::class, "save"], [['App\\Middlewares\\ModuleViewMiddleware', 'assets']]);
$router->add("GET",  "/api/assets/list_user_permissions",  [\App\Controllers\AssetsController::class, "listUserPermissions"], [['App\\Middlewares\\ModuleViewMiddleware', 'assets']]);
$router->add("POST", "/api/assets/save_user_permissions",  [\App\Controllers\AssetsController::class, "saveUserPermissions"], [['App\\Middlewares\\ModuleViewMiddleware', 'assets']]);
$router->add("POST", "/api/assets/delete",                 [\App\Controllers\AssetsController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'assets']]);
$router->add("POST", "/api/assets/save_category",          [\App\Controllers\AssetsController::class, "saveCategory"], [['App\\Middlewares\\ModuleViewMiddleware', 'assets']]);
$router->add("POST", "/api/assets/delete_category",        [\App\Controllers\AssetsController::class, "deleteCategory"], [['App\\Middlewares\\ModuleViewMiddleware', 'assets']]);
$router->add("POST", "/api/assets/import_csv",             [\App\Controllers\AssetsController::class, "importCsv"], [['App\\Middlewares\\ModuleViewMiddleware', 'assets']]);
$router->add("GET",  "/api/assets/export_csv",             [\App\Controllers\AssetsController::class, "exportCsv"], [['App\\Middlewares\\ModuleViewMiddleware', 'assets']]);

// MAC 地址管理模块 (MAC)
$router->add("GET",  "/api/mac/init",                 [\App\Controllers\MacController::class, "init"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("POST", "/api/mac/get_work_order_info",  [\App\Controllers\MacController::class, "getWorkOrderInfo"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("POST", "/api/mac/get_product_desc",     [\App\Controllers\MacController::class, "getProductDesc"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("GET",  "/api/mac/list_product_ports",   [\App\Controllers\MacController::class, "listProductPorts"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("POST", "/api/mac/save_product_port",    [\App\Controllers\MacController::class, "saveProductPort"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("POST", "/api/mac/delete_product_port",  [\App\Controllers\MacController::class, "deleteProductPort"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("POST", "/api/mac/import_mac",           [\App\Controllers\MacController::class, "importMac"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("POST", "/api/mac/apply_mac",            [\App\Controllers\MacController::class, "applyMac"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("GET",  "/api/mac/get_records",          [\App\Controllers\MacController::class, "getRecords"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("GET",  "/api/mac/get_record_details",   [\App\Controllers\MacController::class, "getRecordDetails"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("GET",  "/api/mac/get_stats",            [\App\Controllers\MacController::class, "getStats"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("GET",  "/api/mac/get_pool",             [\App\Controllers\MacController::class, "getPool"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("POST", "/api/mac/delete_records",       [\App\Controllers\MacController::class, "deleteRecords"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("GET",  "/api/mac/get_import_logs",      [\App\Controllers\MacController::class, "getImportLogs"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);
$router->add("GET",  "/api/mac/export_details",       [\App\Controllers\MacController::class, "exportDetails"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac']]);

// 人员离岗监控模块 (Attendance)
$router->add("GET",  "/api/attendance/dashboard",       [\App\Controllers\AttendanceController::class, "dashboard"], [['App\\Middlewares\\ModuleViewMiddleware', 'attendance']]);
$router->add("POST", "/api/attendance/bind_card",       [\App\Controllers\AttendanceController::class, "bindCard"], [['App\\Middlewares\\ModuleViewMiddleware', 'attendance']]);
$router->add("POST", "/api/attendance/unbind_card",     [\App\Controllers\AttendanceController::class, "unbindCard"], [['App\\Middlewares\\ModuleViewMiddleware', 'attendance']]);
$router->add("GET",  "/api/attendance/list_employees",  [\App\Controllers\AttendanceController::class, "listEmployees"], [['App\\Middlewares\\ModuleViewMiddleware', 'attendance']]);
$router->add("POST", "/api/attendance/save_employee",   [\App\Controllers\AttendanceController::class, "saveEmployee"], [['App\\Middlewares\\ModuleViewMiddleware', 'attendance']]);
$router->add("POST", "/api/attendance/delete_employee", [\App\Controllers\AttendanceController::class, "deleteEmployee"], [['App\\Middlewares\\ModuleViewMiddleware', 'attendance']]);
$router->add("GET",  "/api/attendance/list_cards",      [\App\Controllers\AttendanceController::class, "listCards"], [['App\\Middlewares\\ModuleViewMiddleware', 'attendance']]);
$router->add("POST", "/api/attendance/save_card",       [\App\Controllers\AttendanceController::class, "saveCard"], [['App\\Middlewares\\ModuleViewMiddleware', 'attendance']]);
$router->add("POST", "/api/attendance/delete_card",     [\App\Controllers\AttendanceController::class, "deleteCard"], [['App\\Middlewares\\ModuleViewMiddleware', 'attendance']]);
$router->add("GET",  "/api/attendance/list_records",    [\App\Controllers\AttendanceController::class, "listRecords"], [['App\\Middlewares\\ModuleViewMiddleware', 'attendance']]);
$router->add("GET",  "/api/attendance/statistics",      [\App\Controllers\AttendanceController::class, "statistics"], [['App\\Middlewares\\ModuleViewMiddleware', 'attendance']]);
$router->add("GET",  "/api/attendance/export",          [\App\Controllers\AttendanceController::class, "export"], [['App\\Middlewares\\ModuleViewMiddleware', 'attendance']]);

// 工作日记管理模块 (Diary)
$router->add("GET",  "/api/diary/list",               [\App\Controllers\DiaryController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'diary']]);
$router->add("POST", "/api/diary/add",                [\App\Controllers\DiaryController::class, "add"], [['App\\Middlewares\\ModuleViewMiddleware', 'diary']]);
$router->add("POST", "/api/diary/update",             [\App\Controllers\DiaryController::class, "update"], [['App\\Middlewares\\ModuleViewMiddleware', 'diary']]);
$router->add("POST", "/api/diary/delete",             [\App\Controllers\DiaryController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'diary']]);
$router->add("POST", "/api/diary/search_task",        [\App\Controllers\DiaryController::class, "searchTask"], [['App\\Middlewares\\ModuleViewMiddleware', 'diary']]);
$router->add("POST", "/api/diary/check_quantity",     [\App\Controllers\DiaryController::class, "checkQuantity"], [['App\\Middlewares\\ModuleViewMiddleware', 'diary']]);
$router->add("GET",  "/api/diary/get_users",          [\App\Controllers\DiaryController::class, "getUsers"], [['App\\Middlewares\\ModuleViewMiddleware', 'diary']]);
$router->add("POST", "/api/diary/update_permission",  [\App\Controllers\DiaryController::class, "updatePermission"], [['App\\Middlewares\\ModuleViewMiddleware', 'diary']]);
$router->add("GET",  "/api/diary/export",             [\App\Controllers\DiaryController::class, "export"], [['App\\Middlewares\\ModuleViewMiddleware', 'diary']]);

// 北边返厂统计模块 (Beibian)
$router->add("GET",  "/api/beibian/list",             [\App\Controllers\BeibianController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'beibian']]);
$router->add("POST", "/api/beibian/add",              [\App\Controllers\BeibianController::class, "add"], [['App\\Middlewares\\ModuleViewMiddleware', 'beibian']]);
$router->add("POST", "/api/beibian/update",           [\App\Controllers\BeibianController::class, "update"], [['App\\Middlewares\\ModuleViewMiddleware', 'beibian']]);
$router->add("GET",  "/api/beibian/detail",           [\App\Controllers\BeibianController::class, "detail"], [['App\\Middlewares\\ModuleViewMiddleware', 'beibian']]);
$router->add("POST", "/api/beibian/delete",           [\App\Controllers\BeibianController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'beibian']]);
$router->add("GET",  "/api/beibian/search_sn",        [\App\Controllers\BeibianController::class, "searchSn"], [['App\\Middlewares\\ModuleViewMiddleware', 'beibian']]);
$router->add("POST", "/api/beibian/search_sn",        [\App\Controllers\BeibianController::class, "searchSn"], [['App\\Middlewares\\ModuleViewMiddleware', 'beibian']]);
$router->add("GET",  "/api/beibian/search_order",     [\App\Controllers\BeibianController::class, "searchOrder"], [['App\\Middlewares\\ModuleViewMiddleware', 'beibian']]);
$router->add("POST", "/api/beibian/search_order",     [\App\Controllers\BeibianController::class, "searchOrder"], [['App\\Middlewares\\ModuleViewMiddleware', 'beibian']]);
$router->add("GET",  "/api/beibian/export",           [\App\Controllers\BeibianController::class, "export"], [['App\\Middlewares\\ModuleViewMiddleware', 'beibian']]);

// 设备矩阵模块 (Equipment)
$router->add("GET",  "/api/equipment/init",               [\App\Controllers\EquipmentController::class, "init"], [['App\\Middlewares\\ModuleViewMiddleware', 'equipment']]);
$router->add("GET",  "/api/equipment/list_matrix",        [\App\Controllers\EquipmentController::class, "listMatrix"], [['App\\Middlewares\\ModuleViewMiddleware', 'equipment']]);
$router->add("GET",  "/api/equipment/get_product_info",   [\App\Controllers\EquipmentController::class, "getProductInfo"], [['App\\Middlewares\\ModuleViewMiddleware', 'equipment']]);
$router->add("POST", "/api/equipment/get_product_info",   [\App\Controllers\EquipmentController::class, "getProductInfo"], [['App\\Middlewares\\ModuleViewMiddleware', 'equipment']]);
$router->add("POST", "/api/equipment/save_equipment",     [\App\Controllers\EquipmentController::class, "saveEquipment"], [['App\\Middlewares\\ModuleViewMiddleware', 'equipment']]);
$router->add("POST", "/api/equipment/delete_equipment",   [\App\Controllers\EquipmentController::class, "deleteEquipment"], [['App\\Middlewares\\ModuleViewMiddleware', 'equipment']]);

// 首件检管理模块 (FirstPiece)
$router->add("GET",  "/api/first_piece/init",             [\App\Controllers\FirstPieceController::class, "init"], [['App\\Middlewares\\ModuleViewMiddleware', 'first_piece']]);
$router->add("GET",  "/api/first_piece/get_work_order",   [\App\Controllers\FirstPieceController::class, "getWorkOrder"], [['App\\Middlewares\\ModuleViewMiddleware', 'first_piece']]);
$router->add("POST", "/api/first_piece/get_work_order",   [\App\Controllers\FirstPieceController::class, "getWorkOrder"], [['App\\Middlewares\\ModuleViewMiddleware', 'first_piece']]);
$router->add("POST", "/api/first_piece/save_record",      [\App\Controllers\FirstPieceController::class, "saveRecord"], [['App\\Middlewares\\ModuleViewMiddleware', 'first_piece']]);
$router->add("GET",  "/api/first_piece/list",             [\App\Controllers\FirstPieceController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'first_piece']]);
$router->add("GET",  "/api/first_piece/get_criteria",     [\App\Controllers\FirstPieceController::class, "getCriteria"], [['App\\Middlewares\\ModuleViewMiddleware', 'first_piece']]);
$router->add("POST", "/api/first_piece/save_criteria",    [\App\Controllers\FirstPieceController::class, "saveCriteria"], [['App\\Middlewares\\ModuleViewMiddleware', 'first_piece']]);
$router->add("GET",  "/api/first_piece/list_criteria",    [\App\Controllers\FirstPieceController::class, "listCriteria"], [['App\\Middlewares\\ModuleViewMiddleware', 'first_piece']]);
$router->add("POST", "/api/first_piece/delete_criteria",  [\App\Controllers\FirstPieceController::class, "deleteCriteria"], [['App\\Middlewares\\ModuleViewMiddleware', 'first_piece']]);
$router->add("POST", "/api/first_piece/delete",           [\App\Controllers\FirstPieceController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'first_piece']]);
$router->add("GET",  "/api/first_piece/export",           [\App\Controllers\FirstPieceController::class, "export"], [['App\\Middlewares\\ModuleViewMiddleware', 'first_piece']]);

// 阻抗测试记录模块 (Impedance)
$router->add("GET",  "/api/impedance/get_batch_info",     [\App\Controllers\ImpedanceController::class, "getBatchInfo"], [['App\\Middlewares\\ModuleViewMiddleware', 'impedance']]);
$router->add("POST", "/api/impedance/get_batch_info",     [\App\Controllers\ImpedanceController::class, "getBatchInfo"], [['App\\Middlewares\\ModuleViewMiddleware', 'impedance']]);
$router->add("POST", "/api/impedance/calculate_errors",   [\App\Controllers\ImpedanceController::class, "calculateErrors"], [['App\\Middlewares\\ModuleViewMiddleware', 'impedance']]);
$router->add("GET",  "/api/impedance/list",               [\App\Controllers\ImpedanceController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'impedance']]);
$router->add("POST", "/api/impedance/add",                [\App\Controllers\ImpedanceController::class, "add"], [['App\\Middlewares\\ModuleViewMiddleware', 'impedance']]);
$router->add("POST", "/api/impedance/update",             [\App\Controllers\ImpedanceController::class, "update"], [['App\\Middlewares\\ModuleViewMiddleware', 'impedance']]);
$router->add("POST", "/api/impedance/delete",             [\App\Controllers\ImpedanceController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'impedance']]);
$router->add("GET",  "/api/impedance/export",             [\App\Controllers\ImpedanceController::class, "export"], [['App\\Middlewares\\ModuleViewMiddleware', 'impedance']]);
$router->add("GET",  "/api/impedance/get_permissions",    [\App\Controllers\ImpedanceController::class, "getPermissions"], [['App\\Middlewares\\ModuleViewMiddleware', 'impedance']]);
$router->add("POST", "/api/impedance/update_permission",  [\App\Controllers\ImpedanceController::class, "updatePermission"], [['App\\Middlewares\\ModuleViewMiddleware', 'impedance']]);

// 工单齐套查询模块 (Kitting)
$router->add("POST", "/api/kitting/query",                [\App\Controllers\KittingController::class, "query"], [['App\\Middlewares\\ModuleViewMiddleware', 'kitting']]);
$router->add("GET",  "/api/kitting/query",                [\App\Controllers\KittingController::class, "query"], [['App\\Middlewares\\ModuleViewMiddleware', 'kitting']]);

// 标签打印模块 (LabelPrint)
$router->add("GET",  "/api/label_print/query_order",      [\App\Controllers\LabelPrintController::class, "queryOrder"], [['App\\Middlewares\\ModuleViewMiddleware', 'label_print']]);
$router->add("POST", "/api/label_print/query_order",      [\App\Controllers\LabelPrintController::class, "queryOrder"], [['App\\Middlewares\\ModuleViewMiddleware', 'label_print']]);
$router->add("POST", "/api/label_print/execute_print",    [\App\Controllers\LabelPrintController::class, "executePrint"], [['App\\Middlewares\\ModuleViewMiddleware', 'label_print']]);
$router->add("GET",  "/api/label_print/list_history",     [\App\Controllers\LabelPrintController::class, "listHistory"], [['App\\Middlewares\\ModuleViewMiddleware', 'label_print']]);

// MAC / ID 信息查询模块 (MacQuery)
$router->add("GET",  "/api/mac_query/query",              [\App\Controllers\MacQueryController::class, "query"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac_query']]);
$router->add("POST", "/api/mac_query/query",              [\App\Controllers\MacQueryController::class, "query"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac_query']]);
$router->add("GET",  "/api/mac_query/export",             [\App\Controllers\MacQueryController::class, "export"], [['App\\Middlewares\\ModuleViewMiddleware', 'mac_query']]);

// 工时汇总管理模块 (Manhour)
$router->add("GET",  "/api/manhour/categories",           [\App\Controllers\ManhourController::class, "categories"], [['App\\Middlewares\\ModuleViewMiddleware', 'manhour']]);
$router->add("GET",  "/api/manhour/get_code_meta",        [\App\Controllers\ManhourController::class, "getCodeMeta"], [['App\\Middlewares\\ModuleViewMiddleware', 'manhour']]);
$router->add("GET",  "/api/manhour/list",                 [\App\Controllers\ManhourController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'manhour']]);
$router->add("POST", "/api/manhour/add",                  [\App\Controllers\ManhourController::class, "add"], [['App\\Middlewares\\ModuleViewMiddleware', 'manhour']]);
$router->add("POST", "/api/manhour/update",               [\App\Controllers\ManhourController::class, "update"], [['App\\Middlewares\\ModuleViewMiddleware', 'manhour']]);
$router->add("POST", "/api/manhour/delete",               [\App\Controllers\ManhourController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'manhour']]);

// OEE 班组效率管理模块 (OEE)
$router->add("GET",  "/api/oee/init",                     [\App\Controllers\OeeController::class, "init"], [['App\\Middlewares\\ModuleViewMiddleware', 'oee']]);
$router->add("GET",  "/api/oee/list",                     [\App\Controllers\OeeController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'oee']]);
$router->add("POST", "/api/oee/add",                      [\App\Controllers\OeeController::class, "add"], [['App\\Middlewares\\ModuleViewMiddleware', 'oee']]);
$router->add("POST", "/api/oee/update",                   [\App\Controllers\OeeController::class, "update"], [['App\\Middlewares\\ModuleViewMiddleware', 'oee']]);
$router->add("GET",  "/api/oee/dashboard_data",           [\App\Controllers\OeeController::class, "dashboardData"], [['App\\Middlewares\\ModuleViewMiddleware', 'oee']]);
$router->add("POST", "/api/oee/delete",                   [\App\Controllers\OeeController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'oee']]);
$router->add("GET",  "/api/oee/export",                   [\App\Controllers\OeeController::class, "export"], [['App\\Middlewares\\ModuleViewMiddleware', 'oee']]);

// 外协管理系统模块 (OSM) 路由映射
$router->add("GET",  "/api/osm/init",                     [\App\Controllers\OsmController::class, "init"], [['App\\Middlewares\\ModuleViewMiddleware', 'osm']]);
$router->add("GET",  "/api/osm/list",                     [\App\Controllers\OsmController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'osm']]);
$router->add("POST", "/api/osm/save",                     [\App\Controllers\OsmController::class, "save"], [['App\\Middlewares\\ModuleViewMiddleware', 'osm']]);
$router->add("POST", "/api/osm/delete",                   [\App\Controllers\OsmController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'osm']]);
$router->add("GET",  "/api/osm/get_pending_returns",      [\App\Controllers\OsmController::class, "getPendingReturns"], [['App\\Middlewares\\ModuleViewMiddleware', 'osm']]);
$router->add("POST", "/api/osm/add_return",               [\App\Controllers\OsmController::class, "addReturn"], [['App\\Middlewares\\ModuleViewMiddleware', 'osm']]);
$router->add("POST", "/api/osm/upload_osm",               [\App\Controllers\OsmController::class, "uploadOsm"], [['App\\Middlewares\\ModuleViewMiddleware', 'osm']]);
$router->add("GET",  "/api/osm/export",                   [\App\Controllers\OsmController::class, "export"], [['App\\Middlewares\\ModuleViewMiddleware', 'osm']]);
$router->add("GET",  "/api/osm/list_permissions",         [\App\Controllers\OsmController::class, "listPermissions"], [['App\\Middlewares\\ModuleViewMiddleware', 'osm']]);
$router->add("POST", "/api/osm/save_permissions",         [\App\Controllers\OsmController::class, "savePermissions"], [['App\\Middlewares\\ModuleViewMiddleware', 'osm']]);

// 作业指导文件管理模块 (Zywj) 路由映射
$router->add("GET",  "/api/zywj/init",    [\App\Controllers\ZywjController::class, "init"], [['App\\Middlewares\\ModuleViewMiddleware', 'zywj']]);
$router->add("GET",  "/api/zywj/list",    [\App\Controllers\ZywjController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'zywj']]);
$router->add("POST", "/api/zywj/upload",  [\App\Controllers\ZywjController::class, "upload"], [['App\\Middlewares\\ModuleViewMiddleware', 'zywj']]);
$router->add("POST", "/api/zywj/delete",  [\App\Controllers\ZywjController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'zywj']]);

// 文件错误记录模块 (DocError) 路由映射
$router->add("GET",  "/api/doc_error/init",              [\App\Controllers\DocErrorController::class, "init"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("GET",  "/api/doc_error/get_my_perm",       [\App\Controllers\DocErrorController::class, "getMyPerm"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("GET",  "/api/doc_error/get_work_order",    [\App\Controllers\DocErrorController::class, "getWorkOrder"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("POST", "/api/doc_error/get_work_order",    [\App\Controllers\DocErrorController::class, "getWorkOrder"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("GET",  "/api/doc_error/list",              [\App\Controllers\DocErrorController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("POST", "/api/doc_error/list",              [\App\Controllers\DocErrorController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("GET",  "/api/doc_error/get",               [\App\Controllers\DocErrorController::class, "get"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("POST", "/api/doc_error/get",               [\App\Controllers\DocErrorController::class, "get"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("POST", "/api/doc_error/add",               [\App\Controllers\DocErrorController::class, "add"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("POST", "/api/doc_error/update",            [\App\Controllers\DocErrorController::class, "update"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("POST", "/api/doc_error/delete",            [\App\Controllers\DocErrorController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("GET",  "/api/doc_error/export",            [\App\Controllers\DocErrorController::class, "export"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("GET",  "/api/doc_error/get_permissions",   [\App\Controllers\DocErrorController::class, "getPermissions"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);
$router->add("POST", "/api/doc_error/update_permission", [\App\Controllers\DocErrorController::class, "updatePermission"], [['App\\Middlewares\\ModuleViewMiddleware', 'doc_error']]);

// 工单进度与 SN 查询模块 (Progress) 路由映射
$router->add("GET",  "/api/progress/query",  [\App\Controllers\ProgressController::class, "query"], [['App\\Middlewares\\ModuleViewMiddleware', 'progress']]);
$router->add("POST", "/api/progress/query",  [\App\Controllers\ProgressController::class, "query"], [['App\\Middlewares\\ModuleViewMiddleware', 'progress']]);

// 质量问题记录系统模块 (Quality) 路由映射
$router->add("POST", "/api/quality/get_batch_info", [\App\Controllers\QualityController::class, "getBatchInfo"], [['App\\Middlewares\\ModuleViewMiddleware', 'quality']]);
$router->add("GET",  "/api/quality/get_batch_info", [\App\Controllers\QualityController::class, "getBatchInfo"], [['App\\Middlewares\\ModuleViewMiddleware', 'quality']]);
$router->add("GET",  "/api/quality/list",           [\App\Controllers\QualityController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'quality']]);
$router->add("POST", "/api/quality/add",            [\App\Controllers\QualityController::class, "add"], [['App\\Middlewares\\ModuleViewMiddleware', 'quality']]);
$router->add("POST", "/api/quality/update",         [\App\Controllers\QualityController::class, "update"], [['App\\Middlewares\\ModuleViewMiddleware', 'quality']]);
$router->add("POST", "/api/quality/delete",         [\App\Controllers\QualityController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'quality']]);
$router->add("GET",  "/api/quality/export",         [\App\Controllers\QualityController::class, "export"], [['App\\Middlewares\\ModuleViewMiddleware', 'quality']]);
$router->add("GET",  "/api/quality/get_permissions", [\App\Controllers\QualityController::class, "getPermissions"], [['App\\Middlewares\\ModuleViewMiddleware', 'quality']]);
$router->add("POST", "/api/quality/update_permission",[\App\Controllers\QualityController::class, "updatePermission"], [['App\\Middlewares\\ModuleViewMiddleware', 'quality']]);

// 技能矩阵管理模块 (Skill) 路由映射
$router->add("GET",  "/api/skill/init",                  [\App\Controllers\SkillController::class, "init"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);
$router->add("GET",  "/api/skill/matrix_overview",       [\App\Controllers\SkillController::class, "matrixOverview"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);
$router->add("POST", "/api/skill/quick_update_cell",     [\App\Controllers\SkillController::class, "quickUpdateCell"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);
$router->add("GET",  "/api/skill/get_product_processes", [\App\Controllers\SkillController::class, "getProductProcesses"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);
$router->add("POST", "/api/skill/get_product_processes", [\App\Controllers\SkillController::class, "getProductProcesses"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);
$router->add("GET",  "/api/skill/get_levels",            [\App\Controllers\SkillController::class, "getLevels"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);
$router->add("POST", "/api/skill/save_levels",           [\App\Controllers\SkillController::class, "saveLevels"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);
$router->add("GET",  "/api/skill/list_workers",          [\App\Controllers\SkillController::class, "listWorkers"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);
$router->add("POST", "/api/skill/save_worker",           [\App\Controllers\SkillController::class, "saveWorker"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);
$router->add("POST", "/api/skill/submit_skills",         [\App\Controllers\SkillController::class, "submitSkills"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);
$router->add("GET",  "/api/skill/leaderboard",           [\App\Controllers\SkillController::class, "leaderboard"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);
$router->add("GET",  "/api/skill/list_pending",          [\App\Controllers\SkillController::class, "listPending"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);
$router->add("POST", "/api/skill/review_skills",         [\App\Controllers\SkillController::class, "reviewSkills"], [['App\\Middlewares\\ModuleViewMiddleware', 'skill']]);

// 实用工具箱模块 (Tools) 路由映射
$router->add("GET",  "/api/tools/download_qrcode_tpl",   [\App\Controllers\ToolsController::class, "downloadQrcodeTpl"], [['App\\Middlewares\\ModuleViewMiddleware', 'tools']]);
$router->add("POST", "/api/tools/beibian_pdf2word_convert", [\App\Controllers\ToolsController::class, "beibianPdf2wordConvert"], [['App\\Middlewares\\ModuleViewMiddleware', 'tools']]);
$router->add("POST", "/api/tools/batch_qrcode_generate",   [\App\Controllers\ToolsController::class, "batchQrcodeGenerate"], [['App\\Middlewares\\ModuleViewMiddleware', 'tools']]);
$router->add("POST", "/api/tools/hebei_packing_convert",   [\App\Controllers\ToolsController::class, "hebeiPackingConvert"], [['App\\Middlewares\\ModuleViewMiddleware', 'tools']]);
$router->add("POST", "/api/tools/henan_packing_convert",   [\App\Controllers\ToolsController::class, "henanPackingConvert"], [['App\\Middlewares\\ModuleViewMiddleware', 'tools']]);

// 问题排查记录系统 (Troubleshoot) 路由映射
$router->add("POST", "/api/troubleshoot/get_batch_info",    [\App\Controllers\TroubleshootController::class, "getBatchInfo"], [['App\\Middlewares\\ModuleViewMiddleware', 'troubleshoot']]);
$router->add("GET",  "/api/troubleshoot/get_batch_info",    [\App\Controllers\TroubleshootController::class, "getBatchInfo"], [['App\\Middlewares\\ModuleViewMiddleware', 'troubleshoot']]);
$router->add("GET",  "/api/troubleshoot/list",              [\App\Controllers\TroubleshootController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'troubleshoot']]);
$router->add("POST", "/api/troubleshoot/add",               [\App\Controllers\TroubleshootController::class, "add"], [['App\\Middlewares\\ModuleViewMiddleware', 'troubleshoot']]);
$router->add("POST", "/api/troubleshoot/delete",            [\App\Controllers\TroubleshootController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'troubleshoot']]);
$router->add("GET",  "/api/troubleshoot/get_permissions",   [\App\Controllers\TroubleshootController::class, "getPermissions"], [['App\\Middlewares\\ModuleViewMiddleware', 'troubleshoot']]);
$router->add("POST", "/api/troubleshoot/update_permission", [\App\Controllers\TroubleshootController::class, "updatePermission"], [['App\\Middlewares\\ModuleViewMiddleware', 'troubleshoot']]);
$router->add("GET",  "/api/troubleshoot/export",            [\App\Controllers\TroubleshootController::class, "export"], [['App\\Middlewares\\ModuleViewMiddleware', 'troubleshoot']]);

// 周完成情况统计模块 (Weekly) 路由映射
$router->add("GET",  "/api/weekly/filter_options", [\App\Controllers\WeeklyController::class, "filterOptions"], [['App\\Middlewares\\ModuleViewMiddleware', 'weekly']]);
$router->add("GET",  "/api/weekly/list",           [\App\Controllers\WeeklyController::class, "list"], [['App\\Middlewares\\ModuleViewMiddleware', 'weekly']]);
$router->add("GET",  "/api/weekly/dashboard_data", [\App\Controllers\WeeklyController::class, "dashboardData"], [['App\\Middlewares\\ModuleViewMiddleware', 'weekly']]);
$router->add("POST", "/api/weekly/add",            [\App\Controllers\WeeklyController::class, "add"], [['App\\Middlewares\\ModuleViewMiddleware', 'weekly']]);
$router->add("POST", "/api/weekly/update",         [\App\Controllers\WeeklyController::class, "update"], [['App\\Middlewares\\ModuleViewMiddleware', 'weekly']]);
$router->add("POST", "/api/weekly/delete",         [\App\Controllers\WeeklyController::class, "delete"], [['App\\Middlewares\\ModuleViewMiddleware', 'weekly']]);
$router->add("GET",  "/api/weekly/export",         [\App\Controllers\WeeklyController::class, "export"], [['App\\Middlewares\\ModuleViewMiddleware', 'weekly']]);

// 客户端版本验证与推送模块 (ClientVersion)
// ★ 注意：check_version 必须是公开接口（不挂 ModuleViewMiddleware、不要求登录），
//   否则客户端无法在「登录前」做版本预检，"不更新无法进入"就无从实现。
$router->add("GET",  "/api/client/check_version",  [\App\Controllers\ClientVersionController::class, "checkVersion"]);
$router->add("POST", "/api/client/check_version",  [\App\Controllers\ClientVersionController::class, "checkVersion"]);
// 免登录下载更新包：客户端「登录前」用系统浏览器打开此地址，不能要求会话。
// 不挂任何中间件；控制器内部只认 client_versions 中已发布记录登记的地址，不接受任意 path。
$router->add("GET",  "/api/client/download",       [\App\Controllers\ClientVersionController::class, "download"]);
// HEAD 探测：浏览器/下载工具常先发 HEAD 确认大小与类型，缺失会被判定为文件不存在
$router->add("HEAD", "/api/client/download",       [\App\Controllers\ClientVersionController::class, "download"]);
// 管理接口（控制器内部自行校验管理员身份）
$router->add("GET",  "/api/client/list_clients",   [\App\Controllers\ClientVersionController::class, "listClients"]);
$router->add("GET",  "/api/client/list_shared",    [\App\Controllers\ClientVersionController::class, "listShared"]);
$router->add("GET",  "/api/client/list_versions",  [\App\Controllers\ClientVersionController::class, "listVersions"]);
$router->add("POST", "/api/client/save_version",   [\App\Controllers\ClientVersionController::class, "saveVersion"]);
$router->add("POST", "/api/client/delete_version", [\App\Controllers\ClientVersionController::class, "deleteVersion"]);

// 老化车维修管理 (AgingCart) 路由映射
// ★ 注意：中间件参数必须是「中间件列表」，单个中间件也要包两层方括号。
$router->add("GET",  "/api/aging_cart/init",      [\App\Controllers\AgingCartController::class, "init"],      [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
$router->add("GET",  "/api/aging_cart/dashboard", [\App\Controllers\AgingCartController::class, "dashboard"], [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
$router->add("GET",  "/api/aging_cart/carts",     [\App\Controllers\AgingCartController::class, "carts"],     [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
$router->add("GET",  "/api/aging_cart/list",      [\App\Controllers\AgingCartController::class, "list"],      [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
$router->add("POST", "/api/aging_cart/report",    [\App\Controllers\AgingCartController::class, "report"],    [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
$router->add("POST", "/api/aging_cart/start",     [\App\Controllers\AgingCartController::class, "start"],     [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
// 多人同时参与同一张工单：各自独立计时
$router->add("POST", "/api/aging_cart/join",      [\App\Controllers\AgingCartController::class, "join"],      [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
// 本人收工（不结束整张工单）
$router->add("POST", "/api/aging_cart/leave",     [\App\Controllers\AgingCartController::class, "leave"],     [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
$router->add("POST", "/api/aging_cart/finish",    [\App\Controllers\AgingCartController::class, "finish"],    [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
$router->add("POST", "/api/aging_cart/save",      [\App\Controllers\AgingCartController::class, "save"],      [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
$router->add("POST", "/api/aging_cart/save_cart", [\App\Controllers\AgingCartController::class, "saveCart"],  [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
$router->add("POST", "/api/aging_cart/set_cart_total", [\App\Controllers\AgingCartController::class, "setCartTotal"], [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
$router->add("POST", "/api/aging_cart/delete",    [\App\Controllers\AgingCartController::class, "delete"],    [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);
$router->add("GET",  "/api/aging_cart/export",    [\App\Controllers\AgingCartController::class, "export"],    [['App\\Middlewares\\ModuleViewMiddleware', 'aging_cart']]);

// 文件共享模块 (Files) 路由映射
$router->add("GET",  "/api/files/list",     [\App\Controllers\FilesController::class, "list"],     [['App\\Middlewares\\ModuleViewMiddleware', 'files']]);
$router->add("GET",  "/api/files/download", [\App\Controllers\FilesController::class, "download"], [['App\\Middlewares\\ModuleViewMiddleware', 'files']]);
$router->add("GET",  "/api/files/ping",     [\App\Controllers\FilesController::class, "ping"]);

// 5.1 强制改密拦截：非管理员首次登录 / 密码强度不达标时，
//     只放行登录、退出、获取身份、修改密码这几类接口，其余一律拒绝。
$pwdGuardPath = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
$pwdGuardPath = "/" . trim($pwdGuardPath, "/");
$pwdGuardWhitelist = [
    "/api/auth/login",
    "/api/auth/logout",
    "/api/auth/me",
    "/api/auth/change_password",
    // 客户端版本预检与免登录下载都是公开接口，不应被强制改密拦截
    "/api/client/check_version",
    "/api/client/download",
];
if (!empty($_SESSION["must_change_password"])
    && str_starts_with($pwdGuardPath, "/api/")
    && !in_array($pwdGuardPath, $pwdGuardWhitelist, true)) {
    \App\Core\Response::error("为保障账号安全，请先将初始密码修改为符合规则的密码", [
        "code"                 => "PASSWORD_CHANGE_REQUIRED",
        "need_change_password" => true,
    ], 403);
}

// 6. ★ 安全加固：已移除动态 RESTful 兜底派发。
//    原逻辑会把 /api/{模块}/{方法} 映射到「任意控制器的任意 public 方法」，
//    使未注册的内部方法也变成可访问接口（并绕过路由表的 HTTP 方法约束），
//    是匿名越权访问的直接入口。现仅使用上方显式注册的路由表，未注册一律 404。

// 6.5 ★ 安全加固（L5）：导出 / 工具箱转换等 CPU 密集接口限流，
//     防止少量并发把 PHP-FPM / CPU 打满而拖垮全站。阈值见 .env（RATE_*）。
\App\Core\RateLimiter::guard($_SERVER["REQUEST_URI"] ?? "");
\App\Core\RateLimiter::gc();

// 7. 派发路由
$router->dispatch();