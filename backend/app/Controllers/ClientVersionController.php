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
        'impedance_recorder' => '阻抗测试客户端',
        'barcode_validator'  => '条码校验客户端',
        'trace_scanner'      => '追溯扫描客户端',
        'label_print'        => '标签打印客户端',
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

            // 取该客户端已发布的最新版本（id 最大 = 最近发布；版本号用 PHP 侧比较更稳）
            $stmt = $this->db->prepare("SELECT version, min_version, force_update, download_url, update_log, created_at
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
                'download_url'    => (string)$latest['download_url'],
                'update_log'      => (string)($latest['update_log'] ?? ''),
                'published_at'    => (string)($latest['created_at'] ?? ''),
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '版本检查失败');
        }
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
            Response::success('success', ['records' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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
            if ($downloadUrl !== '' && !preg_match('#^https?://#i', $downloadUrl)) {
                Response::error('下载地址必须以 http:// 或 https:// 开头');
                return;
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
