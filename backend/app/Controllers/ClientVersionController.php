<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Core\Schema;
use App\Services\AuditService;
use PDO;
use Throwable;

/**
 * 客户端版本验证与推送（Impedance Recorder / 阻抗测试客户端）
 *
 * 设计口径：
 *   - 客户端在「登录前」调用 check_version 做预检，服务端版本更高且标记强制时，
 *     客户端必须更新，否则无法进入主界面。
 *   - check_version 是**公开接口**（不需要登录），这样登录前才能查到版本。
 *   - 版本发布记录存放在 client_versions 表，一条记录 = 一个已发布版本；
 *     生效版本 = 所有 is_published=1 记录中版本号最大的一条。
 *   - 强制更新由服务端 force_update 字段决定（1 = 强制）。
 */
class ClientVersionController
{
    /** 客户端标识，预留多客户端（如阻抗仪 / 条码器等）区分 */
    private const CLIENT_KEY = 'impedance_recorder';

    /**
     * 已知客户端清单：用于管理页下拉提示，避免手填标识打错。
     * 这里的项只是**建议值**，实际仍允许发布任意 client_key；
     * 新增客户端时把它的 key / 名称补进来即可（不改逻辑）。
     */
    private const KNOWN_CLIENTS = [
        'impedance_recorder'  => '阻抗测试客户端',
        'trace_client'        => '质量追溯客户端（Qt6 / Win10+）',
        'trace_client_win7'   => '质量追溯客户端（Win7 兼容版 / 32位）',
        'barcode_validator'   => '条码校验客户端',
        'trace_scanner'       => '追溯扫描客户端（Android）',
        'workshop_app'        => '装置车间 App（Android）',
        'label_print'         => '标签打印客户端',
    ];

    private PDO $db;
    private bool $isAdmin = false;
    private array $currentUser = [];

    public function __construct()
    {
        $this->db = Database::getConnection('default');
        Schema::ensureOnce('client_versions', function () { $this->initTable(); });

        // 注意：本控制器同时承载「公开读取」与「管理员管理」两类接口。
        // 这里不强制要求登录，登录校验放到各自方法里，否则公开接口会被误拦。
        if (!empty($_SESSION['user'])) {
            $this->currentUser = $_SESSION['user'];
            $this->isAdmin = !empty($this->currentUser['is_admin'])
                || (string)($this->currentUser['username'] ?? '') === 'admin';
        }
    }

    private function initTable(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `client_versions` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `client_key`    VARCHAR(64)  NOT NULL DEFAULT 'impedance_recorder',
                `version`       VARCHAR(32)  NOT NULL COMMENT '版本号，如 2.2',
                `min_version`   VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '低于此版本的客户端强制更新',
                `force_update`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=强制更新',
                `download_url`  VARCHAR(500) NOT NULL DEFAULT '' COMMENT '新版下载地址',
                `update_log`    TEXT         NULL COMMENT '更新说明',
                `is_published`  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=已发布生效',
                `created_by`    VARCHAR(100) NOT NULL DEFAULT '',
                `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_client_version` (`client_key`, `is_published`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Throwable $e) {
            // 忽略：无权限时不阻塞主流程
        }
    }

    /**
     * 版本号归一化：去掉 v 前缀与空白，仅保留主.次(.修订) 结构
     */
    private function normalizeVersion(string $v): string
    {
        $v = strtolower(trim($v));
        $v = ltrim($v, 'v');
        $v = preg_replace('/[^0-9.]/', '', $v) ?? '';
        return trim($v, '.');
    }

    /**
     * 版本比较：$a > $b 返回 1，等于 0，小于 -1。
     * 逐段数值比较，避免 "2.10" < "2.9" 这类字符串比较坑。
     */
    private function compareVersion(string $a, string $b): int
    {
        $a = $this->normalizeVersion($a);
        $b = $this->normalizeVersion($b);
        if ($a === '' && $b === '') return 0;
        if ($a === '') return -1;
        if ($b === '') return 1;

        $pa = array_map('intval', explode('.', $a));
        $pb = array_map('intval', explode('.', $b));
        $len = max(count($pa), count($pb));
        for ($i = 0; $i < $len; $i++) {
            $x = $pa[$i] ?? 0;
            $y = $pb[$i] ?? 0;
            if ($x > $y) return 1;
            if ($x < $y) return -1;
        }
        return 0;
    }

    // ============================================================
    //  公开接口
    // ============================================================

    /**
     * 取某客户端「已发布」记录中版本号最大的一条
     *
     * @return array<string,mixed>|null
     */
    private function latestPublishedRow(string $clientKey): ?array
    {
        $stmt = $this->db->prepare("SELECT id, version, min_version, force_update, download_url, update_log, created_at
                                    FROM `client_versions`
                                    WHERE client_key = ? AND is_published = 1
                                    ORDER BY id DESC LIMIT 50");
        $stmt->execute([$clientKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $latest = null;
        foreach ($rows as $row) {
            if ($latest === null || $this->compareVersion((string)$row['version'], (string)$latest['version']) > 0) {
                $latest = $row;
            }
        }
        return $latest;
    }

    /**
     * GET/POST /api/client/check_version
     * 入参（可选）：version / current_version —— 客户端当前版本
     * 返回：是否需要更新、最新版本、是否强制、下载地址、更新说明
     */
    public function checkVersion(): void
    {
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_REQUEST;
            $clientKey = trim((string)($input['client_key'] ?? self::CLIENT_KEY));
            if ($clientKey === '') $clientKey = self::CLIENT_KEY;
            $clientVer = $this->normalizeVersion((string)($input['version'] ?? $input['current_version'] ?? ''));

            // 取该客户端已发布的最新版本（版本号用 PHP 侧逐段比较更稳，避免 "2.10" < "2.9"）
            $latest = $this->latestPublishedRow($clientKey);

            // 未发布任何版本 → 视为无需更新（避免误伤所有客户端）
            if ($latest === null) {
                Response::success('success', [
                    'client_key'    => $clientKey,
                    'current_version' => $clientVer,
                    'latest_version'  => '',
                    'min_version'     => '',
                    'force_update'    => false,
                    'need_update'     => false,
                    'download_url'    => '',
                    'update_log'      => '',
                ]);
                return;
            }

            $latestVer = $this->normalizeVersion((string)$latest['version']);
            $minVer    = $this->normalizeVersion((string)$latest['min_version']);

            $needUpdate = false;
            if ($clientVer !== '') {
                $needUpdate = $this->compareVersion($latestVer, $clientVer) > 0;
            } else {
                // 客户端未提供版本号：保守处理，不提示（避免老客户端被误拦）
                $needUpdate = false;
            }

            $force = !empty($latest['force_update']);

            // 客户端版本低于 min_version → 无论 force 字段如何都强制
            if ($clientVer !== '' && $minVer !== '' && $this->compareVersion($clientVer, $minVer) < 0) {
                $force = true;
            }

            Response::success('success', [
                'client_key'      => $clientKey,
                'current_version' => $clientVer,
                'latest_version'  => $latestVer,
                'min_version'     => $minVer,
                'force_update'    => (bool)$force,
                'need_update'     => (bool)$needUpdate,
                // ★ 关键：这里输出的一定是「免登录可下载」的完整 URL。
                //   若库里存的是 shared_files 下的相对路径，就地拼成公开下载端点，
                //   客户端无需改动即可用系统浏览器直接打开。
                'download_url'    => $this->publicDownloadUrl((string)$latest['download_url'], $clientKey, $latestVer),
                'update_log'      => (string)($latest['update_log'] ?? ''),
                'published_at'    => (string)($latest['created_at'] ?? ''),
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '版本检查失败');
        }
    }

    // ============================================================
    //  免登录下载（更新包）
    // ============================================================

    /**
     * 共享文件根目录：backend/public/shared_files（与 FilesController 一致）
     */
    private function shareRoot(): string
    {
        return dirname(__DIR__, 2) . '/public/shared_files';
    }

    /**
     * 站内相对路径的字符层面校验（不查磁盘，用于入库前拦截）
     *
     * 放行：电脑工具/xxx.exe、shared_files/手机工具/xxx.apk
     * 拒绝：绝对路径、.. 穿越、file:// 等其它协议
     */
    private function isSafeSharedPath(string $rel): bool
    {
        $rel = str_replace('\\', '/', trim($rel));
        if ($rel === '' || strpos($rel, "\0") !== false) {
            return false;
        }
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $rel)) {
            return false;                 // 非 http(s) 协议一律拒绝
        }
        if (isset($rel[0]) && $rel[0] === '/') {
            return false;                 // 绝对路径不允许
        }

        $body = preg_replace('#^shared_files/#i', '', $rel) ?? '';
        if ($body === '') {
            return false;
        }
        return !preg_match('#(^|/)\.\.(/|$)#', $body);
    }

    /**
     * 站内相对路径 → 真实绝对路径
     *
     * 规则（安全底线）：
     *   - 允许 "电脑工具/a.exe" 或 "shared_files/电脑工具/a.exe"（前缀可省）
     *   - 拒绝 ".."、"\0"、绝对路径，最终 realpath 必须落在 shared_files 之内
     *   - 不限制子目录层级：电脑工具 / 手机工具 / 任意自建目录都可用
     *
     * @param bool $mustBeFile true 则要求必须是文件
     */
    private function resolveShared(string $rel, bool $mustBeFile = true): ?string
    {
        $rel = str_replace('\\', '/', trim($rel));
        if ($rel === '' || strpos($rel, "\0") !== false) {
            return null;
        }
        // 去掉可选前缀 shared_files/（大小写不敏感），也去掉多余的斜杠
        $rel = preg_replace('#^/*shared_files/*#i', '', $rel) ?? '';
        $rel = ltrim($rel, '/');
        if ($rel === '' || strpos($rel, '..') !== false) {
            return null;
        }

        $root = realpath($this->shareRoot());
        if ($root === false) {
            return null;
        }
        $full = realpath($root . '/' . $rel);
        if ($full === false) {
            return null;
        }
        if ($full !== $root && strpos($full, $root . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        if ($mustBeFile && !is_file($full)) {
            return null;
        }
        if (!$mustBeFile && !is_dir($full)) {
            return null;
        }
        return $full;
    }

    /**
     * 把库里的下载地址变成「免登录可下载」的完整 URL
     *
     *   - http(s):// 开头：视为外链，原样返回（客户端浏览器能直连）
     *   - 其余：视为 shared_files 下的相对路径，拼成 /api/client/download
     *     该端点不校验会话，因此客户端未登录也能下载
     */
    private function publicDownloadUrl(string $url, string $clientKey, string $version): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return '';   // CLI / 无 Host 场景无从推导，宁可留空也不要给错地址
        }
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $isHttps ? 'https' : 'http';

        return $scheme . '://' . $host . '/api/client/download?'
            . http_build_query(['key' => $clientKey, 'v' => $version]);
    }

    /**
     * GET /api/client/download —— 免登录下载更新包（公开接口）
     *
     * 为什么需要它：
     *   客户端是「登录前」做版本预检的，拿到地址后用系统浏览器打开
     *   （QDesktopServices::openUrl），浏览器不带任何会话 Cookie。
     *   而 /api/files/download 要求登录、/backend/ 又被 nginx 拒绝，
     *   两者都会表现为「提示需要登录」。本端点专门服务这一场景。
     *
     * 参数（三选一）：
     *   id=N          指定版本记录
     *   key=xxx       指定客户端，取其最新已发布版本
     *   key=xxx&v=2.2 指定客户端 + 指定版本
     *
     * 安全约束（防任意文件读取）：
     *   - 只认 client_versions 中 is_published=1 记录里登记的地址，不接受任意 path
     *   - 站内路径须落在 shared_files 内，且拒绝脚本类扩展名
     */
    public function download(): void
    {
        // 浏览器 / 下载管理器常先发 HEAD 探测文件大小与类型，
        // HEAD 必须同样返回 200 + 正确的 Content-Type / Content-Length，
        // 否则探测方会认为文件不存在而放弃下载（只发响应头，不发正文）。
        $isHead = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');

        try {
            $id  = (int)($_GET['id'] ?? 0);
            $key = trim((string)($_GET['key'] ?? ''));
            $ver = $this->normalizeVersion((string)($_GET['v'] ?? $_GET['version'] ?? ''));

            $row = null;
            if ($id > 0) {
                $stmt = $this->db->prepare("SELECT id, version, download_url
                                            FROM `client_versions` WHERE id = ? AND is_published = 1 LIMIT 1");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } elseif ($key !== '') {
                if ($ver !== '') {
                    $stmt = $this->db->prepare("SELECT id, version, download_url
                                                FROM `client_versions`
                                                WHERE client_key = ? AND is_published = 1
                                                ORDER BY id DESC LIMIT 200");
                    $stmt->execute([$key]);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                        if ($this->normalizeVersion((string)$r['version']) === $ver) {
                            $row = $r;
                            break;
                        }
                    }
                } else {
                    $row = $this->latestPublishedRow($key);
                }
            }

            if (!$row) {
                http_response_code(404);
                exit('未找到可下载的版本');
            }

            $url = trim((string)($row['download_url'] ?? ''));
            if ($url === '') {
                http_response_code(404);
                exit('该版本未配置下载地址');
            }

            // 外链：302 跳转，让浏览器自己去取
            if (preg_match('#^https?://#i', $url)) {
                header('Location: ' . $url, true, 302);
                exit;
            }

            $full = $this->resolveShared($url);
            if ($full === null) {
                http_response_code(404);
                exit('安装包不存在或路径非法');
            }
            $this->streamFile($full, $isHead);
        } catch (Throwable $e) {
            http_response_code(500);
            error_log('[client/download] ' . $e->getMessage());
            exit('下载失败');
        }
    }

    /**
     * 二进制流式输出（分块，避免大包占满内存）
     *
     * @param bool $headOnly true = 只发响应头不发正文（HEAD 探测）
     */
    private function streamFile(string $full, bool $headOnly = false): void
    {
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        // 与 FilesController 一致的黑名单：不允许下发可执行脚本
        if (in_array($ext, ['php', 'phtml', 'phar', 'pht', 'cgi', 'pl', 'py', 'sh', 'bash'], true)) {
            http_response_code(403);
            exit('该类型文件不允许下载');
        }

        $filename = basename($full);
        $safeName = str_replace(['"', "\r", "\n"], '', $filename);
        $size     = @filesize($full);
        if ($size === false) {
            $size = 0;
        }

        // 安装包可能上百 MB，解除脚本执行时限，避免下载到一半被 max_execution_time 掐断
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . (string)$size);
        header('Cache-Control: public, max-age=300');
        header('X-Content-Type-Options: nosniff');

        if ($headOnly) {
            exit;
        }

        $fp = fopen($full, 'rb');
        if ($fp === false) {
            http_response_code(500);
            exit('无法读取文件');
        }
        while (!feof($fp)) {
            $buf = fread($fp, 8192);
            if ($buf === false) {
                break;
            }
            echo $buf;
            flush();
        }
        fclose($fp);
        exit;
    }

    // ============================================================
    //  管理员管理接口
    // ============================================================

    /** 仅管理员校验，未通过直接终止 */
    private function requireAdmin(): void
    {
        if ($this->isAdmin) {
            return;
        }
        Response::error('只有管理员可管理客户端版本', [], 403);
        exit;
    }

    /**
     * GET /api/client/list_clients —— 已接入的客户端清单（仅管理员）
     *
     * 返回两部分的并集：
     *   1) 代码中预置的 KNOWN_CLIENTS（便于发布尚未登记过版本的客户端）；
     *   2) 数据库 client_versions 中实际出现过的 client_key（含历史/自定义）。
     * 管理页据此渲染下拉框，避免手填标识打错导致版本线错乱。
     */
    public function listClients(): void
    {
        if (empty($_SESSION['user'])) {
            Response::error('未登录或登录已超时', ['need_login' => true], 401);
            return;
        }
        $this->requireAdmin();

        try {
            $clients = [];
            foreach (self::KNOWN_CLIENTS as $key => $name) {
                $clients[$key] = ['client_key' => $key, 'name' => $name, 'version_count' => 0, 'known' => true];
            }

            // 统计数据库中实际存在的 client_key
            $stmt = $this->db->query("SELECT client_key, COUNT(*) AS cnt, MAX(version) AS latest
                                      FROM `client_versions`
                                      GROUP BY client_key");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = (string)$row['client_key'];
                if ($key === '') continue;
                if (!isset($clients[$key])) {
                    $clients[$key] = ['client_key' => $key, 'name' => $key, 'version_count' => 0, 'known' => false];
                }
                $clients[$key]['version_count'] = (int)$row['cnt'];
            }

            Response::success('success', [
                'clients' => array_values($clients),
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取客户端清单失败');
        }
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1024 / 1024, 2) . ' MB';
    }

    /**
     * GET /api/client/list_shared —— 浏览 shared_files 目录（仅管理员）
     *
     * 用途：版本管理页「从共享文件选择」按钮的数据源。
     * 安装包散落在 电脑工具 / 手机工具 / 其它自建子目录时，手填路径极易写错，
     * 这里直接列目录让管理员点选，回填相对路径即可。
     */
    public function listShared(): void
    {
        if (empty($_SESSION['user'])) {
            Response::error('未登录或登录已超时', ['need_login' => true], 401);
            return;
        }
        $this->requireAdmin();

        try {
            $rel = str_replace('\\', '/', trim((string)($_GET['path'] ?? '')));
            $rel = preg_replace('#^/*shared_files/*#i', '', $rel) ?? '';
            $rel = trim($rel, '/');

            $dir = $rel === '' ? realpath($this->shareRoot()) : $this->resolveShared($rel, false);
            if ($dir === null || $dir === false) {
                Response::error('目录不存在或路径非法');
                return;
            }

            $entries = @scandir($dir);
            if ($entries === false) {
                Response::error('无法读取目录');
                return;
            }

            $items = [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (($entry[0] ?? '') === '.') {
                    continue;   // 隐藏文件跳过
                }
                $full    = $dir . '/' . $entry;
                $relPath = ($rel === '' ? '' : $rel . '/') . $entry;
                $isDir   = is_dir($full);
                $items[] = [
                    'name'      => $entry,
                    'path'      => $relPath,
                    'is_dir'    => $isDir,
                    'size_text' => $isDir ? '-' : $this->formatSize((int)filesize($full)),
                ];
            }

            usort($items, function (array $a, array $b): int {
                if ($a['is_dir'] !== $b['is_dir']) {
                    return $a['is_dir'] ? -1 : 1;
                }
                return strcasecmp($a['name'], $b['name']);
            });

            $parent = '';
            if ($rel !== '') {
                $p = dirname($rel);
                $parent = ($p === '.' || $p === '/' || $p === '\\') ? '' : $p;
            }

            Response::success('success', [
                'path'   => $rel,
                'parent' => $parent,
                'items'  => $items,
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '读取共享目录失败');
        }
    }

    /**
     * GET /api/client/list_versions —— 版本记录列表（仅管理员）
     */
    public function listVersions(): void
    {
        if (empty($_SESSION['user'])) {
            Response::error('未登录或登录已超时', ['need_login' => true], 401);
            return;
        }
        $this->requireAdmin();
        try {
            $stmt = $this->db->query("SELECT id, client_key, version, min_version, force_update,
                                             download_url, update_log, is_published, created_by,
                                             created_at, updated_at
                                      FROM `client_versions`
                                      ORDER BY id DESC LIMIT 200");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            // 附一列「客户端实际拿到的地址」：相对路径会展开成免登录下载端点，
            // 便于管理员一眼确认客户端能不能真的下下来。
            foreach ($rows as &$r) {
                $r['public_url'] = $this->publicDownloadUrl(
                    (string)$r['download_url'],
                    (string)$r['client_key'],
                    $this->normalizeVersion((string)$r['version'])
                );
            }
            unset($r);
            Response::success('success', ['records' => $rows]);
        } catch (Throwable $e) {
            Response::exception($e, '获取版本列表失败');
        }
    }

    /**
     * POST /api/client/save_version —— 新增/修改版本记录（仅管理员）
     */
    public function saveVersion(): void
    {
        if (empty($_SESSION['user'])) {
            Response::error('未登录或登录已超时', ['need_login' => true], 401);
            return;
        }
        $this->requireAdmin();

        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $id          = (int)($input['id'] ?? 0);
            $clientKey   = trim((string)($input['client_key'] ?? self::CLIENT_KEY)) ?: self::CLIENT_KEY;
            $version     = $this->normalizeVersion((string)($input['version'] ?? ''));
            $minVersion  = $this->normalizeVersion((string)($input['min_version'] ?? ''));
            $forceUpdate = !empty($input['force_update']) ? 1 : 0;
            $downloadUrl = trim((string)($input['download_url'] ?? ''));
            $updateLog   = trim((string)($input['update_log'] ?? ''));
            $isPublished = isset($input['is_published']) ? (!empty($input['is_published']) ? 1 : 0) : 1;

            if ($version === '') {
                Response::error('版本号不能为空');
                return;
            }
            if (!preg_match('/^\d+(\.\d+)*$/', $version)) {
                Response::error('版本号格式不正确，应形如 2.2 或 2.2.1');
                return;
            }
            if ($minVersion !== '' && !preg_match('/^\d+(\.\d+)*$/', $minVersion)) {
                Response::error('最低版本号格式不正确');
                return;
            }
            // 下载地址支持两种形态：
            //   1) http(s):// 外链 —— 原样下发给客户端
            //   2) shared_files 下的相对路径（如 电脑工具/TraceClient_v2.2.exe）
            //      —— 由 /api/client/download 免登录读取，客户端未登录也能下载
            if ($downloadUrl !== '' && !preg_match('#^https?://#i', $downloadUrl)) {
                if (!$this->isSafeSharedPath($downloadUrl)) {
                    Response::error('下载地址需为 http(s):// 开头的完整链接，或 shared_files 下的相对路径（如 电脑工具/xxx.exe），且不得包含 ..');
                    return;
                }
                if ($this->resolveShared($downloadUrl) === null) {
                    Response::error('在 shared_files 下找不到该文件，请确认文件已上传且路径正确：' . $downloadUrl);
                    return;
                }
            }

            $byName = (string)($this->currentUser['full_name'] ?? '') ?: (string)($this->currentUser['username'] ?? '');

            if ($id > 0) {
                $stmt = $this->db->prepare("UPDATE `client_versions`
                                            SET client_key = ?, version = ?, min_version = ?, force_update = ?,
                                                download_url = ?, update_log = ?, is_published = ?, updated_at = NOW()
                                            WHERE id = ?");
                $stmt->execute([$clientKey, $version, $minVersion, $forceUpdate, $downloadUrl, $updateLog, $isPublished, $id]);
                AuditService::log('客户端版本', "修改版本记录 #{$id} → v{$version}");
                Response::success('版本记录已更新');
            } else {
                // 同客户端同版本号去重：已存在则更新，避免重复记录
                $chk = $this->db->prepare("SELECT id FROM `client_versions` WHERE client_key = ? AND version = ? LIMIT 1");
                $chk->execute([$clientKey, $version]);
                $existId = (int)$chk->fetchColumn();

                if ($existId > 0) {
                    $stmt = $this->db->prepare("UPDATE `client_versions`
                                                SET min_version = ?, force_update = ?, download_url = ?,
                                                    update_log = ?, is_published = ?, updated_at = NOW()
                                                WHERE id = ?");
                    $stmt->execute([$minVersion, $forceUpdate, $downloadUrl, $updateLog, $isPublished, $existId]);
                    AuditService::log('客户端版本', "更新已存在的版本 v{$version}");
                    Response::success('该版本已存在，已更新其信息', ['id' => $existId]);
                } else {
                    $stmt = $this->db->prepare("INSERT INTO `client_versions`
                                                (client_key, version, min_version, force_update, download_url, update_log, is_published, created_by)
                                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$clientKey, $version, $minVersion, $forceUpdate, $downloadUrl, $updateLog, $isPublished, $byName]);
                    AuditService::log('客户端版本', "发布新版本 v{$version}");
                    Response::success('新版本已发布', ['id' => (int)$this->db->lastInsertId()]);
                }
            }
        } catch (Throwable $e) {
            Response::exception($e, '保存版本失败');
        }
    }

    /**
     * POST /api/client/delete_version —— 删除版本记录（仅管理员）
     */
    public function deleteVersion(): void
    {
        if (empty($_SESSION['user'])) {
            Response::error('未登录或登录已超时', ['need_login' => true], 401);
            return;
        }
        $this->requireAdmin();

        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                Response::error('无效的记录ID');
                return;
            }

            $stmt = $this->db->prepare("DELETE FROM `client_versions` WHERE id = ?");
            $stmt->execute([$id]);
            AuditService::log('客户端版本', "删除版本记录 #{$id}");
            Response::success('已删除');
        } catch (Throwable $e) {
            Response::exception($e, '删除失败');
        }
    }
}
