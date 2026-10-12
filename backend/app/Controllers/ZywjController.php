<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use PDO;
use Throwable;

class ZywjController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private string $uploadDir;

    public function __construct()
    {
        $this->db = Database::getConnection();

        // ★ 安全修复：会话缺失时不再自动以数据库中的管理员身份登录，必须重新登录
        if (empty($_SESSION['user'])) {
            Response::error('登录已失效，请重新登录', ['need_login' => true], 401);
            exit;
        }

        $this->currentUser = $_SESSION['user'];$uName = $this->currentUser['username'] ?? '';$this->isAdmin = (!empty($this->currentUser['is_admin']) or$uName === 'admin');
        $this->uploadDir = __DIR__ . '/../../public/uploads/zywj/';\App\Core\Schema::ensureOnce('zywj', function () { $this->initTables(); });
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `zywj_documents` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `doc_no` varchar(100) NOT NULL,
                `title` varchar(200) NOT NULL,
                `category` varchar(50) NOT NULL DEFAULT '通用指导',
                `product_code` varchar(100) DEFAULT '',
                `process_name` varchar(100) DEFAULT '',
                `version` varchar(20) NOT NULL DEFAULT 'V1.0',
                `file_path` varchar(255) NOT NULL,
                `file_size` int(11) NOT NULL DEFAULT 0,
                `file_type` varchar(20) NOT NULL DEFAULT 'pdf',
                `remarks` text,
                `uploader_id` int(11) NOT NULL DEFAULT 0,
                `uploader_name` varchar(100) NOT NULL DEFAULT '',
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_product_code` (`product_code`),
                INDEX `idx_category` (`category`),
                INDEX `idx_process_name` (`process_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            if (!is_dir($this->uploadDir)) {
                @mkdir($this->uploadDir, 0755, true);
            }
        } catch (Throwable $e) {}
    }

    public function init(): void
    {
        try {
            $categories =$this->db->query("SELECT DISTINCT category FROM zywj_documents WHERE category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
            $processes =$this->db->query("SELECT DISTINCT process_name FROM zywj_documents WHERE process_name != '' ORDER BY process_name ASC")->fetchAll(PDO::FETCH_COLUMN);

            Response::success('success', [
                'categories' => !empty($categories) ?$categories : ['装配作业指导', '调试作业指导', '检验作业指导', '包装作业指导', '通用SOP'],
                'processes'  => $processes,
                'is_admin'   => $this->isAdmin
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '初始化失败');
        }
    }

    public function list(): void
    {
        try {
            $category    = trim((string)($_GET['category'] ?? ''));$processName = trim((string)($_GET['process_name'] ?? ''));$search      = trim((string)($_GET['search'] ?? ''));$page        = max(1, (int)($_GET['page'] ?? 1));$perPage     = 25;
            $offset      = ($page - 1) *$perPage;

            $where = ["1=1"];
            $params = [];

            if ($category !== '') {$where[] = "category = ?";
                $params[] =$category;
            }
            if ($processName !== '') {$where[] = "process_name = ?";
                $params[] =$processName;
            }
            if ($search !== '') {
                $kw = "\%{$search}%";
                $where[] = "(title LIKE ? OR doc_no LIKE ? OR product_code LIKE ? OR remarks LIKE ?)";
                for ($i = 0; $i < 4; $i++) {
                    $params[] =$kw;
                }
            }

            $whereSql = implode(' AND ',$where);

            $cntStmt =$this->db->prepare("SELECT COUNT(*) FROM zywj_documents WHERE {$whereSql}");
            $cntStmt->execute($params);$totalRecords = (int)$cntStmt->fetchColumn();$totalPages = (int)ceil($totalRecords / $perPage);

            $sql = "SELECT * FROM zywj_documents WHERE {$whereSql} ORDER BY id DESC LIMIT {$offset}, {$perPage}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $records =$stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('success', [
                'records'       => $records,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'current_page'  => $page,
                'is_admin'      => $this->isAdmin
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取文件列表失败');
        }
    }

    public function upload(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员具备上传作业指导书权限', [], 403);
            return;
        }

        if (!isset($_FILES['file']) or$_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('请选择需要上传的文件！');
            return;
        }

        try {
            $docNo       = trim((string)($_POST['doc_no'] ?? ''));
            $title       = trim((string)($_POST['title'] ?? ''));
            $category    = trim((string)($_POST['category'] ?? '通用指导'));
            $productCode = trim((string)($_POST['product_code'] ?? ''));
            $processName = trim((string)($_POST['process_name'] ?? ''));
            $version     = trim((string)($_POST['version'] ?? 'V1.0'));
            $remarks     = trim((string)($_POST['remarks'] ?? ''));

            if (empty($title)) {
                Response::error('文件标题为必填项');
                return;
            }

            $file = $_FILES['file'];$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));$allowedExts = ['pdf', 'png', 'jpg', 'jpeg', 'docx', 'xlsx'];
            if (!in_array($ext,$allowedExts, true)) {
                Response::error('仅支持上传 PDF、图片、Word 或 Excel 格式文件！');
                return;
            }

            $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetPath = $this->uploadDir .$filename;

            if (!move_uploaded_file($file['tmp_name'],$targetPath)) {
                Response::error('移动存储文件失败，请检查目录权限');
                return;
            }

            $uploaderName = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] :$this->currentUser['username'];
            $relativeUrl = '/uploads/zywj/' .$filename;

            $stmt =$this->db->prepare("INSERT INTO zywj_documents 
                (doc_no, title, category, product_code, process_name, version, file_path, file_size, file_type, remarks, uploader_id, uploader_name, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$docNo ?: ('SOP-' . date('Ymd-His')),
                $title,$category,
                $productCode,$processName,
                $version,$relativeUrl,
                (int)$file['size'],
                $ext,$remarks,
                (int)$this->currentUser['id'],$uploaderName
            ]);

            AuditService::log('作业指导文件', '上传作业指导书: ' . $title);
            Response::success('作业指导文件上传并归档成功！', ['id' => (int)$this->db->lastInsertId()]);
        } catch (Throwable $e) {
            Response::exception($e, '上传异常');
        }
    }

    public function delete(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可删除作业文件', [], 403);
            return;
        }

        try {
            $raw = file_get_contents('php://input');$input = json_decode($raw, true) ?:$_POST;
            $id = (int)($input['id'] ?? 0);

            if ($id <= 0) {
                Response::error('参数无效');
                return;
            }

            $stmt =$this->db->prepare("SELECT file_path, title FROM zywj_documents WHERE id = ?");
            $stmt->execute([$id]);
            $doc =$stmt->fetch(PDO::FETCH_ASSOC);

            if ($doc) {
                $fullPath = __DIR__ . '/../../public' .$doc['file_path'];
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
                $this->db->prepare("DELETE FROM zywj_documents WHERE id = ?")->execute([$id]);
                AuditService::log('作业指导文件', '删除作业指导书: ' . $doc['title']);
            }

            Response::success('文件已成功删除');
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }
}
