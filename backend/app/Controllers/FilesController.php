<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Core\Schema;
use PDO;
use Throwable;

/**
 * 文件共享模块（Files）
 *
 * 由旧版单页系统 api/files.php 迁移至新架构。
 * 功能对齐旧版增强版：
 *   1. 目录浏览（list）：目录项、面包屑、父目录
 *   2. 文件下载（download）：流式输出，支持鉴权透传
 *   3. 连通性探测（ping / ping_img）：供前端判断线路
 *
 * 安全要点：
 *   - 路径经 safeResolvePath() 归一化：禁止 .. 穿越、\0 注入，
 *     并强制 realpath 落在共享根目录内（防符号链接逃逸）
 *   - 下载文件名做响应头注入清洗
 *   - 鉴权：会话缺失直接 401（不自动以管理员身份登录）
 */
class FilesController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;

    /** 共享根目录（绝对路径） */
    private string $shareRoot;

    public function __construct()
    {
        // 探测接口无需鉴权，须在 session/DB 之前返回
        $this->handlePing();

        $this->db = Database::getConnection();

        // ★ 安全基线：会话缺失必须重新登录，不得自动降级为管理员
        if (empty($_SESSION['user'])) {
            Response::error('登录已失效，请重新登录', ['need_login' => true], 401);
            exit;
        }

        $this->currentUser = $_SESSION['user'];
        $this->isAdmin = !empty($this->currentUser['is_admin'])
            || (string)($this->currentUser['username'] ?? '') === 'admin';

        // 共享目录：backend/public/shared_files（随站点部署，便于备份）
        $root = __DIR__ . '/../../public/shared_files';
        Schema::ensureOnce('files_dirs', function () use ($root) {
            if (!is_dir($root)) {
                @mkdir($root, 0755, true);
            }
        });

        if (!is_dir($root)) {
            @mkdir($root, 0755, true);
        }
        $real = realpath($root);
        if ($real === false) {
            Response::error('共享目录不可用');
            exit;
        }
        $this->shareRoot = $real;
    }

    /**
     * 极速探测：/api/files/ping 或 ?action=ping_img
     * 不做鉴权、不触碰数据库，由构造函数在初始化前调用。
     */
    private function handlePing(): void
    {
        $action = (string)($_GET['action'] ?? '');
        if ($action !== 'ping' && $action !== 'ping_img') {
            return;
        }

        header('Cache-Control: no-cache, no-store, must-revalidate');

        if ($action === 'ping_img') {
            header('Content-Type: image/gif');
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            exit;
        }

        header('Content-Type: text/plain; charset=utf-8');
        echo 'pong';
        exit;
    }

    /** 显式探测入口（路由目标），构造函数已提前处理 */
    public function ping(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'pong';
        exit;
    }

    /**
     * 路径归一化 + 越界校验
     *
     * @return array{rel:string,full:string}|false
     */
    private function safeResolvePath(string $relativePath)
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = trim($relativePath, '/');

        $parts = [];
        foreach (explode('/', $relativePath) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                array_pop($parts);
                continue;
            }
            if (strpos($p, "\0") !== false) {
                return false;
            }
            $parts[] = $p;
        }

        $clean = implode('/', $parts);
        $full  = $this->shareRoot . ($clean === '' ? '' : '/' . $clean);
        $real  = realpath($full);

        if ($real === false) {
            return false;
        }
        if ($real !== $this->shareRoot
            && strpos($real, $this->shareRoot . DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }

        return ['rel' => $clean, 'full' => $real];
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . ' MB';
        }
        return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
    }

    /** 请求是否来自内网 / 本机 */
    private function isInternalRequest(): bool
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return false;
        }
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    // ============================================================
    //  1. 目录列表
    // ============================================================
    public function list(): void
    {
        $rel      = (string)($_GET['path'] ?? '');
        $resolved = $this->safeResolvePath($rel);

        if (!$resolved || !is_dir($resolved['full'])) {
            Response::error('路径不存在或非法');
            return;
        }

        $entries = @scandir($resolved['full']);
        if ($entries === false) {
            Response::error('无法读取目录');
            return;
        }

        $items = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (isset($entry[0]) && $entry[0] === '.') {
                continue;   // 跳过隐藏文件
            }

            $fullPath = $resolved['full'] . '/' . $entry;
            $relPath  = ($resolved['rel'] === '' ? '' : $resolved['rel'] . '/') . $entry;
            $isDir    = is_dir($fullPath);
            $size     = $isDir ? 0 : (int)filesize($fullPath);
            $mtime    = (int)filemtime($fullPath);

            $items[] = [
                'name'       => $entry,
                'path'       => $relPath,
                'is_dir'     => $isDir,
                'size'       => $size,
                'size_text'  => $isDir ? '-' : $this->formatSize($size),
                'mtime_text' => date('Y-m-d H:i', $mtime),
            ];
        }

        usort($items, function (array $a, array $b): int {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $a['is_dir'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        $parent = dirname($resolved['rel']);
        if ($parent === '.' || $parent === '\\' || $parent === '/') {
            $parent = '';
        }

        Response::success('success', [
            'path'         => $resolved['rel'],
            'parent'       => $parent,
            'items'        => $items,
            'is_lan'       => $this->isInternalRequest(),
            'lan_base_url' => '',   // 同源部署，无需内网直连前缀
        ]);
    }

    // ============================================================
    //  2. 文件下载
    // ============================================================
    public function download(): void
    {
        $rel      = (string)($_GET['path'] ?? '');
        $resolved = $this->safeResolvePath($rel);

        if (!$resolved || is_dir($resolved['full'])) {
            http_response_code(404);
            exit('文件不存在');
        }

        // ★ 安全：文件类型白名单外的可执行脚本拒绝下载（防误存脚本被直接拉走）
        $ext = strtolower(pathinfo($resolved['full'], PATHINFO_EXTENSION));
        if (in_array($ext, ['php', 'phtml', 'phar', 'pht', 'cgi', 'pl', 'py', 'sh', 'bash'], true)) {
            http_response_code(403);
            exit('该类型文件不允许下载');
        }

        $filename = basename($resolved['full']);
        $safeName = str_replace(['"', "\r", "\n"], '', $filename);

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . filesize($resolved['full']));
        header('Cache-Control: no-cache');
        header('Pragma: no-cache');

        readfile($resolved['full']);
        exit;
    }
}
