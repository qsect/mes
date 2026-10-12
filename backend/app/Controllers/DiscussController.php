<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use PDO;
use Throwable;

class DiscussController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $perms = [];

    public function __construct()
    {
        $this->db = Database::getConnection('default');
        $this->currentUser = function_exists('checkUser') ? checkUser() : ($_SESSION['user'] ?? []);
        $username = (string)($this->currentUser['username'] ?? '');
        $this->isAdmin = !empty($this->currentUser['is_admin']) || $username === 'admin';
        $this->perms = $this->resolvePerms();
    }

    /**
     * ★ 安全加固（H3）：讨论区/公告正文白名单净化，防止存储型 XSS。
     * 前端用 v-html 渲染正文，若原样入库，任意发帖用户即可注入脚本窃取登录态。
     * 策略：仅保留安全的展示型标签，剔除全部 on* 事件属性与危险协议。
     */
    private function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // 1) 去掉控制字符（保留 \t \n \r）
        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $html) ?? $html;

        // 2) 标签白名单（不支持 <script>/<style>/<iframe>/<object> 等）
        //    ★ 2026-10-12 富文本编辑器接入：补 <font>/<strike>/<mark> 与
        //      <del>/<ins>/<tfoot>/<caption>/<colgroup>/<col>/<center>，
        //      使白名单与前端 utils/sanitizeHtml.js 保持一致——
        //      execCommand('strikeThrough') 产出 <strike>、字号兜底可能产出 <font>，
        //      若不在此放行，用户设置的删除线 / 字号会在入库时被悄悄剥掉。
        $allowed = '<p><br><strong><b><em><i><u><s><strike><del><ins><mark><ul><ol><li><a><img><code><pre>'
                 . '<blockquote><h1><h2><h3><h4><h5><h6><table><thead><tbody><tfoot><tr><td><th>'
                 . '<caption><colgroup><col><span><div><hr><figure><figcaption><font><center>';
        $clean = strip_tags($html, $allowed);

        // 3) 去掉所有 on* 事件属性（onclick/onerror/onload...）
        $clean = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? $clean;

        // 4) 危险协议：javascript: / vbscript: / data:（data:image 也一并拦掉，图片走上传接口）
        $clean = preg_replace(
            '/(href|src|action|formaction)\s*=\s*("|\')\s*(javascript|vbscript|data)\s*:[^"\']*(\2)/i',
            '$1="#"',
            $clean
        ) ?? $clean;

        // 5) 剔除 CSS expression 与 url(javascript:)
        $clean = preg_replace('/expression\s*\(/i', '', $clean) ?? $clean;
        $clean = preg_replace('/url\s*\(\s*("|\')?\s*javascript:[^)]*\)/i', 'none', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * 解析当前用户在「综合讨论交流区」的细粒度权限
     * 管理员默认拥有全部权限
     */
    private function resolvePerms(): array
    {
        if ($this->isAdmin) {
            return [
                'can_view'         => 1,
                'can_create_topic' => 1,
                'can_reply'        => 1,
                'can_edit'         => 1,
                'can_delete'       => 1,
                'is_admin'         => 1,
            ];
        }
        $uid = (int)($this->currentUser['id'] ?? 0);
        $p = null;
        try {
            $st = $this->db->prepare("SELECT can_view, can_create_topic, can_reply, can_edit, can_delete
                                      FROM `discuss_permissions` WHERE user_id = ? LIMIT 1");
            $st->execute([$uid]);
            $p = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}

        return [
            'can_view'         => $p ? (int)$p['can_view'] : 1,
            'can_create_topic' => $p ? (int)$p['can_create_topic'] : 1,
            'can_reply'        => $p ? (int)$p['can_reply'] : 1,
            'can_edit'         => $p ? (int)$p['can_edit'] : 1,
            'can_delete'       => $p ? (int)$p['can_delete'] : 0,
            'is_admin'         => 0,
        ];
    }

    private function getPermissions(): array
    {
        return [
            'can_view'         => (bool)$this->perms['can_view'],
            'can_create_topic' => (bool)$this->perms['can_create_topic'],
            'can_reply'        => (bool)$this->perms['can_reply'],
            'can_edit'         => (bool)$this->perms['can_edit'],
            // 删帖/锁帖/置顶仅管理员
            'can_delete_topic' => $this->isAdmin,
            'can_delete_reply' => $this->isAdmin,
            'can_close_topic'  => $this->isAdmin,
            'can_pin_topic'    => $this->isAdmin,
            'can_upload'       => (bool)$this->perms['can_create_topic'],
        ];
    }

    /**
     * 确保"公告已读"表存在（幂等）
     */
    private function ensureNoticeReadTable(): void
    {
        if (\App\Core\Schema::isReady('discuss_notice_read')) { return; }
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `notice_reads` (
              `id` INT PRIMARY KEY AUTO_INCREMENT,
              `user_id` INT NOT NULL,
              `topic_id` INT NOT NULL,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_user_topic` (`user_id`,`topic_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Throwable $e) {}
        \App\Core\Schema::markReady('discuss_notice_read');
    }

    /**
     * 解析"公告"分类的 ID
     */
    private function resolveNoticeCategoryId(): int
    {
        try {
            $cats = $this->db->query("SELECT id, name, slug FROM discuss_categories WHERE status = 1")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cats as $c) {
                $n = (string)($c['name'] ?? '');
                $s = (string)($c['slug'] ?? '');
                if (mb_strpos($n, '公告') !== false || $s === 'notice' || $s === 'announcement') {
                    return (int)$c['id'];
                }
            }
        } catch (Throwable $e) {}
        return 0;
    }

    /**
     * 首页公告列表（公开：所有登录用户可见，不受讨论区"查看"权限限制）
     */
    public function listNotices(): void
    {
        try {
            $catId = $this->resolveNoticeCategoryId();
            if ($catId <= 0) {
                Response::success('success', ['topics' => [], 'category_id' => 0]);
                return;
            }
            $st = $this->db->prepare("SELECT t.id, t.title, t.created_at,
                                             COALESCE(NULLIF(u.full_name,''), u.username, '系统') AS author_name
                                      FROM discuss_topics t
                                      LEFT JOIN users u ON t.user_id = u.id
                                      WHERE t.category_id = ?
                                      ORDER BY t.created_at DESC, t.id DESC
                                      LIMIT 10");
            $st->execute([$catId]);
            Response::success('success', ['topics' => $st->fetchAll(PDO::FETCH_ASSOC), 'category_id' => $catId]);
        } catch (Throwable $e) {
            Response::exception($e, '获取公告失败');
        }
    }

    /**
     * 首页公告详情（公开：仅限"公告"分类）
     */
    public function getNoticeDetail(): void
    {
        $topicId = (int)($_GET['id'] ?? 0);
        if ($topicId <= 0) {
            Response::error('无效的公告ID');
            return;
        }
        try {
            $catId = $this->resolveNoticeCategoryId();
            $st = $this->db->prepare("SELECT t.*, COALESCE(NULLIF(u.full_name,''), u.username, '系统') AS author_name
                                      FROM discuss_topics t
                                      LEFT JOIN users u ON t.user_id = u.id
                                      WHERE t.id = ? LIMIT 1");
            $st->execute([$topicId]);
            $topic = $st->fetch(PDO::FETCH_ASSOC);
            if (!$topic || ($catId > 0 && (int)$topic['category_id'] !== $catId)) {
                Response::error('公告不存在', [], 404);
                return;
            }
            Response::success('success', ['topic' => $topic]);
        } catch (Throwable $e) {
            Response::exception($e, '获取公告详情失败');
        }
    }

    /**
     * 当前用户已读的公告(帖子)ID列表
     */
    public function listNoticeReads(): void
    {
        try {
            $uid = (int)($this->currentUser['id'] ?? 0);
            if ($uid <= 0) {
                Response::success('success', ['topic_ids' => []]);
                return;
            }
            $this->ensureNoticeReadTable();
            $st = $this->db->prepare("SELECT topic_id FROM notice_reads WHERE user_id = ?");
            $st->execute([$uid]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            Response::success('success', ['topic_ids' => $ids]);
        } catch (Throwable $e) {
            Response::exception($e, '获取已读公告失败');
        }
    }

    /**
     * 标记公告已读（按用户持久化：一次查看即为已读）
     */
    public function markNoticeRead(): void
    {
        $input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        $topicId = (int)($input['topic_id'] ?? 0);
        $uid = (int)($this->currentUser['id'] ?? 0);
        if ($uid <= 0 || $topicId <= 0) {
            Response::error('参数不完整');
            return;
        }
        try {
            $this->ensureNoticeReadTable();
            $st = $this->db->prepare("INSERT IGNORE INTO notice_reads (user_id, topic_id, created_at) VALUES (?, ?, NOW())");
            $st->execute([$uid, $topicId]);
            Response::success('已标记为已读');
        } catch (Throwable $e) {
            Response::exception($e, '标记失败');
        }
    }

    public function init(): void
    {
        try {
            $cats = $this->db->query("SELECT * FROM discuss_categories WHERE status = 1 ORDER BY sort_order ASC")
                             ->fetchAll(PDO::FETCH_ASSOC);
            $unreadCount = 0;
            if (!empty($this->currentUser['id'])) {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM discuss_notifications WHERE user_id = ? AND is_read = 0");
                $stmt->execute([$this->currentUser['id']]);
                $unreadCount = (int)$stmt->fetchColumn();
            }

            Response::success('success', [
                'categories'           => $cats ?: [],
                'unread_notifications' => $unreadCount,
                'permissions'          => $this->getPermissions(),
                'is_admin'             => $this->isAdmin,
                'current_user'         => $this->currentUser
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '初始化讨论区失败');
        }
    }

    public function listTopics(): void
    {
        // ★ 权限校验：查看
        if (empty($this->perms['can_view'])) {
            Response::error('您没有查看讨论区的权限', [], 403);
            return;
        }
        try {
            $categoryId = (int)($_GET['category_id'] ?? 0);
            $search = trim((string)($_GET['search'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = max(1, (int)($_GET['per_page'] ?? 15));
            $offset = ($page - 1) * $perPage;

            $where = ["1=1"];
            $params = [];

            if ($categoryId > 0) {
                $where[] = "t.category_id = ?";
                $params[] = $categoryId;
            }
            if ($search !== '') {
                $kw = '%' . $search . '%';
                $where[] = "(t.title LIKE ? OR t.content LIKE ?)";
                $params[] = $kw;
                $params[] = $kw;
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM discuss_topics t WHERE {$whereSql}");
            $cntStmt->execute($params);
            $totalRecords = (int)$cntStmt->fetchColumn();

            $sql = "SELECT t.*, 
                           COALESCE(NULLIF(u.full_name, ''), u.username, '用户') AS author_name,
                           u.badge_no AS author_badge_no,
                           COALESCE(c.name, '综合') AS category_name, 
                           c.slug AS category_slug
                    FROM discuss_topics t
                    LEFT JOIN users u ON t.user_id = u.id
                    LEFT JOIN discuss_categories c ON t.category_id = c.id
                    WHERE {$whereSql}
                    ORDER BY t.is_pinned DESC, COALESCE(t.last_reply_at, t.created_at) DESC
                    LIMIT {$offset}, {$perPage}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'topics'       => $topics ?: [],
                'total'        => $totalRecords,
                'current_page' => $page,
                'per_page'     => $perPage
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取帖子列表失败');
        }
    }

    public function getTopicDetail(): void
    {
        // ★ 权限校验：查看
        if (empty($this->perms['can_view'])) {
            Response::error('您没有查看讨论区的权限', [], 403);
            return;
        }
        try {
            $topicId = (int)($_GET['id'] ?? 0);
            if ($topicId <= 0) {
                Response::error('无效的帖子 ID');
                return;
            }

            $this->db->prepare("UPDATE discuss_topics SET view_count = view_count + 1 WHERE id = ?")->execute([$topicId]);

            $stmt = $this->db->prepare("SELECT t.*, 
                                               COALESCE(NULLIF(u.full_name, ''), u.username, '用户') AS author_name,
                                               u.badge_no AS author_badge_no,
                                               COALESCE(c.name, '综合') AS category_name
                                        FROM discuss_topics t
                                        LEFT JOIN users u ON t.user_id = u.id
                                        LEFT JOIN discuss_categories c ON t.category_id = c.id
                                        WHERE t.id = ?");
            $stmt->execute([$topicId]);
            $topic = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$topic) {
                Response::error('帖子不存在或已被删除');
                return;
            }

            $rStmt = $this->db->prepare("SELECT r.*, 
                                                COALESCE(NULLIF(u.full_name, ''), u.username, '用户') AS author_name,
                                                u.badge_no AS author_badge_no
                                         FROM discuss_replies r 
                                         LEFT JOIN users u ON r.user_id = u.id 
                                         WHERE r.topic_id = ? 
                                         ORDER BY r.created_at ASC");
            $rStmt->execute([$topicId]);
            $replies = $rStmt->fetchAll(PDO::FETCH_ASSOC);

            $currentUserId = (int)($this->currentUser['id'] ?? 0);
            $isAuthor = ((int)($topic['user_id'] ?? 0) === $currentUserId);
            $canReply = empty($topic['is_closed']);

            Response::success('success', [
                'topic'     => $topic,
                'replies'   => $replies ?: [],
                'can_reply' => $canReply,
                'is_author' => $isAuthor,
                // 编辑：需具备编辑权限，且仅能编辑自己发的帖子（管理员不受限）
                'can_edit'  => ($this->isAdmin || !empty($this->perms['can_edit'])) && ($this->isAdmin || $isAuthor)
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取帖子详情失败');
        }
    }

    public function createTopic(): void
    {
        // ★ 权限校验：发帖
        if (empty($this->perms['can_create_topic'])) {
            Response::error('您没有发帖权限', [], 403);
            return;
        }
        try {
            $input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
            $title = trim((string)($input['title'] ?? ''));
            $categoryId = (int)($input['category_id'] ?? 0);
            $content = $this->sanitizeHtml((string)($input['content'] ?? ''));

            if (empty($title) || empty($content) || $categoryId <= 0) {
                Response::error('标题、分类和帖子内容均为必填项');
                return;
            }

            $userId = (int)($this->currentUser['id'] ?? 1);
            $stmt = $this->db->prepare("INSERT INTO discuss_topics (category_id, user_id, title, content, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$categoryId, $userId, $title, $content]);
            $topicId = (int)$this->db->lastInsertId();

            AuditService::log('讨论区', '发布帖子');
            Response::success('帖子发布成功', ['topic_id' => $topicId]);
        } catch (Throwable $e) {
            Response::exception($e, '发帖失败');
        }
    }

    public function editTopic(): void
    {
        // ★ 权限校验：编辑（仅能编辑自己发的帖子，管理员不受限）
        if (empty($this->perms['can_edit'])) {
            Response::error('您没有编辑权限', [], 403);
            return;
        }
        try {
            $input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
            $topicId = (int)($input['id'] ?? 0);
            $categoryId = (int)($input['category_id'] ?? 0);
            $title = trim((string)($input['title'] ?? ''));
            $content = $this->sanitizeHtml((string)($input['content'] ?? ''));

            if ($topicId <= 0 || empty($title) || empty($content)) {
                Response::error('参数不完整');
                return;
            }

            $stmt = $this->db->prepare("SELECT user_id FROM discuss_topics WHERE id = ?");
            $stmt->execute([$topicId]);
            $authorId = (int)$stmt->fetchColumn();

            $currentUserId = (int)($this->currentUser['id'] ?? 0);
            if (!$this->isAdmin && $authorId !== $currentUserId) {
                Response::error('只有作者或管理员允许编辑');
                return;
            }

            if ($categoryId > 0) {
                $upStmt = $this->db->prepare("UPDATE discuss_topics SET category_id = ?, title = ?, content = ? WHERE id = ?");
                $upStmt->execute([$categoryId, $title, $content, $topicId]);
            } else {
                $upStmt = $this->db->prepare("UPDATE discuss_topics SET title = ?, content = ? WHERE id = ?");
                $upStmt->execute([$title, $content, $topicId]);
            }

            AuditService::log('讨论区', '修改帖子');
            Response::success('帖子修改成功');
        } catch (Throwable $e) {
            Response::exception($e, '修改帖子失败');
        }
    }

    public function replyTopic(): void
    {
        // ★ 权限校验：回复
        if (empty($this->perms['can_reply'])) {
            Response::error('您没有回复权限', [], 403);
            return;
        }
        try {
            $input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
            $topicId = (int)($input['topic_id'] ?? 0);
            $content = $this->sanitizeHtml((string)($input['content'] ?? ''));

            if ($topicId <= 0 || empty($content)) {
                Response::error('回复内容不能为空');
                return;
            }

            $tStmt = $this->db->prepare("SELECT user_id, title, is_closed FROM discuss_topics WHERE id = ?");
            $tStmt->execute([$topicId]);
            $topic = $tStmt->fetch(PDO::FETCH_ASSOC);

            if (!$topic) {
                Response::error('帖子不存在');
                return;
            }
            if (!empty($topic['is_closed'])) {
                Response::error('该帖子已被锁定，暂不允许回复');
                return;
            }

            $userId = (int)($this->currentUser['id'] ?? 1);
            $stmt = $this->db->prepare("INSERT INTO discuss_replies (topic_id, user_id, content, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$topicId, $userId, $content]);
            $replyId = (int)$this->db->lastInsertId();

            $this->db->prepare("UPDATE discuss_topics SET reply_count = reply_count + 1, last_reply_user_id = ?, last_reply_at = NOW() WHERE id = ?")
                     ->execute([$userId, $topicId]);

            // 发送回复消息提醒
            if ((int)$topic['user_id'] !== $userId) {
                $senderName = $this->currentUser['full_name'] ?? $this->currentUser['username'] ?? '用户';
                $msg = "{$senderName} 回复了您的帖子「" . mb_substr($topic['title'], 0, 25) . "」";
                $this->db->prepare("INSERT INTO discuss_notifications (user_id, from_user_id, topic_id, reply_id, type, message) VALUES (?, ?, ?, ?, 'reply', ?)")
                         ->execute([$topic['user_id'], $userId, $topicId, $replyId, $msg]);
            }

            AuditService::log('讨论区', '发表回复');
            Response::success('回复发表成功', ['reply_id' => $replyId]);
        } catch (Throwable $e) {
            Response::exception($e, '回复失败');
        }
    }

    public function upload(): void
    {
        try {
            $file = $_FILES['file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                Response::error('文件上传失败，未获取到有效文件');
                return;
            }

            $uploadDir = __DIR__ . '/../../public/uploads/discuss/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            // ★ 安全加固：上传大小上限（5MB），避免超大文件占满磁盘 / 拖垮 PHP-FPM
            if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
                Response::error('图片大小不能超过 5MB');
                return;
            }

            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                Response::error('仅允许上传主流图片格式(jpg, png, gif, webp)');
                return;
            }

            // ★ 安全加固：除扩展名外再做真实内容嗅探，防止改扩展名的脚本 / 伪装文件落盘
            $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = $finfo ? (string)finfo_file($finfo, (string)$file['tmp_name']) : '';
                if ($finfo) finfo_close($finfo);
                if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
                    Response::error('文件内容不是有效的图片，已拒绝上传');
                    return;
                }
                // 扩展名与真实类型不一致时，按真实类型改写扩展名
                $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (isset($mimeToExt[$mime])) {
                    $ext = $mimeToExt[$mime];
                }
            }

            $filename = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                AuditService::log('讨论区', '上传图片');
                Response::success('上传成功', [
                    'url' => '/uploads/discuss/' . $filename
                ]);
                return;
            }

            Response::error('保存文件失败');
        } catch (Throwable $e) {
            Response::exception($e, '上传异常');
        }
    }

    public function manageAction(): void
    {
        try {
            $input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
            $subAction = trim((string)($input['sub_action'] ?? ''));
            $topicId = (int)($input['topic_id'] ?? 0);
            $replyId = (int)($input['reply_id'] ?? 0);

            switch ($subAction) {
                case 'toggle_pin':
                    if (!$this->isAdmin) {
                        Response::error('无权操作置顶', [], 403);
                        return;
                    }
                    $this->db->prepare("UPDATE discuss_topics SET is_pinned = IF(COALESCE(is_pinned,0)=1, 0, 1) WHERE id = ?")->execute([$topicId]);
                    AuditService::log('讨论区', '变更帖子置顶');
                    Response::success('置顶状态已变更');
                    break;

                case 'toggle_close':
                    if (!$this->isAdmin) {
                        Response::error('无权操作锁定', [], 403);
                        return;
                    }
                    $this->db->prepare("UPDATE discuss_topics SET is_closed = IF(COALESCE(is_closed,0)=1, 0, 1) WHERE id = ?")->execute([$topicId]);
                    AuditService::log('讨论区', '变更帖子锁定');
                    Response::success('帖子锁定状态已变更');
                    break;

                case 'delete_topic':
                    // ★ 删帖仅管理员（含删除他人帖子）
                    if (!$this->isAdmin) {
                        Response::error('只有管理员可以删除帖子', [], 403);
                        return;
                    }
                    $this->db->prepare("DELETE FROM discuss_replies WHERE topic_id = ?")->execute([$topicId]);
                    $this->db->prepare("DELETE FROM discuss_topics WHERE id = ?")->execute([$topicId]);
                    AuditService::log('讨论区', '删除帖子');
                    Response::success('帖子及回复已彻底删除');
                    break;

                case 'delete_reply':
                    // ★ 删除回复仅管理员
                    if (!$this->isAdmin) {
                        Response::error('只有管理员可以删除回复', [], 403);
                        return;
                    }
                    $stmt = $this->db->prepare("SELECT user_id, topic_id FROM discuss_replies WHERE id = ?");
                    $stmt->execute([$replyId]);
                    $reply = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$reply) {
                        Response::error('该回复不存在');
                        return;
                    }
                    $this->db->prepare("DELETE FROM discuss_replies WHERE id = ?")->execute([$replyId]);
                    $this->db->prepare("UPDATE discuss_topics SET reply_count = GREATEST(0, reply_count - 1) WHERE id = ?")->execute([$reply['topic_id']]);
                    AuditService::log('讨论区', '删除回复');
                    Response::success('回复已删除');
                    break;

                default:
                    Response::error('未知管理指令');
            }
        } catch (Throwable $e) {
            Response::exception($e, '管理操作执行失败');
        }
    }

    public function listNotifications(): void
    {
        try {
            $stmt = $this->db->prepare("SELECT n.*, COALESCE(NULLIF(u.full_name, ''), u.username, '用户') AS from_username, t.title AS topic_title
                                        FROM discuss_notifications n
                                        LEFT JOIN users u ON n.from_user_id = u.id
                                        LEFT JOIN discuss_topics t ON n.topic_id = t.id
                                        WHERE n.user_id = ?
                                        ORDER BY n.created_at DESC LIMIT 50");
            $stmt->execute([$this->currentUser['id'] ?? 0]);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', ['notifications' => $list ?: []]);
        } catch (Throwable $e) {
            Response::exception($e, '获取通知列表失败');
        }
    }

    public function markAllRead(): void
    {
        try {
            $this->db->prepare("UPDATE discuss_notifications SET is_read = 1 WHERE user_id = ?")
                     ->execute([$this->currentUser['id'] ?? 0]);
            Response::success('所有通知已标记为已读');
        } catch (Throwable $e) {
            Response::exception($e, '操作失败');
        }
    }
}