<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use PDO;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class AssetsController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $allFields;

    public function __construct()
    {
        $this->db = Database::getConnection('default');
        
        // ★ 安全修复：会话缺失时不再自动以数据库中的管理员身份登录，必须重新登录
        if (empty($_SESSION['user'])) {
            Response::error('登录已失效，请重新登录', ['need_login' => true], 401);
            exit;
        }
        
        $this->currentUser = $_SESSION['user'];
        $this->isAdmin = !empty($this->currentUser['is_admin']) || ($this->currentUser['username'] ?? '') === 'admin';

        $this->allFields = [
            'category'   => '分类',
            'name'       => '名称',
            'code'       => '编号',
            'model'      => '规格型号',
            'config'     => '配置明细',
            'user'       => '使用人/保管人',
            'location'   => '使用地点',
            'purpose'    => '用途',
            'mac1'       => 'MAC1',
            'ip1'        => 'IP1',
            'mac2'       => 'MAC2',
            'ip2'        => 'IP2',
            'status'     => '状态',
            'image_path' => '图示',
            'out_date'   => '出库日期',
            'price'      => '出库价格',
            'remark'     => '备注'
        ];

        \App\Core\Schema::ensureOnce('assets', function () { $this->initTables(); });
    }

    private function initTables(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `asset_categories` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(50) NOT NULL UNIQUE,
                `show_fields` text,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `assets` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `category` varchar(50) NOT NULL DEFAULT '',
                `name` varchar(100) NOT NULL DEFAULT '',
                `code` varchar(50) NOT NULL DEFAULT '',
                `model` varchar(100) DEFAULT '',
                `config` text,
                `user` varchar(50) NOT NULL DEFAULT '',
                `location` varchar(100) NOT NULL DEFAULT '',
                `purpose` varchar(100) DEFAULT '',
                `mac1` varchar(30) DEFAULT '',
                `ip1` varchar(30) DEFAULT '',
                `mac2` varchar(30) DEFAULT '',
                `ip2` varchar(30) DEFAULT '',
                `status` varchar(50) DEFAULT '在用',
                `image_path` varchar(255) DEFAULT '',
                `out_date` varchar(30) DEFAULT '',
                `price` decimal(10,2) DEFAULT 0.00,
                `remark` text,
                `created_by` varchar(100) DEFAULT '',
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_cat` (`category`),
                INDEX `idx_user` (`user`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `asset_user_permissions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL UNIQUE,
                `allowed_groups` text,
                `allowed_users` text,
                `can_edit` tinyint(1) NOT NULL DEFAULT 1,
                `can_delete` tinyint(1) NOT NULL DEFAULT 0,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $existingCols = $this->db->query("SHOW COLUMNS FROM `assets`")->fetchAll(PDO::FETCH_COLUMN);
            $neededCols = [
                'category'   => "varchar(50) NOT NULL DEFAULT ''",
                'name'       => "varchar(100) NOT NULL DEFAULT ''",
                'code'       => "varchar(50) NOT NULL DEFAULT ''",
                'model'      => "varchar(100) DEFAULT ''",
                'config'     => "text",
                'user'       => "varchar(50) NOT NULL DEFAULT ''",
                'location'   => "varchar(100) NOT NULL DEFAULT ''",
                'purpose'    => "varchar(100) DEFAULT ''",
                'mac1'       => "varchar(30) DEFAULT ''",
                'ip1'        => "varchar(30) DEFAULT ''",
                'mac2'       => "varchar(30) DEFAULT ''",
                'ip2'        => "varchar(30) DEFAULT ''",
                'status'     => "varchar(50) DEFAULT '在用'",
                'image_path' => "varchar(255) DEFAULT ''",
                'out_date'   => "varchar(30) DEFAULT ''",
                'price'      => "decimal(10,2) DEFAULT 0.00",
                'remark'     => "text",
                'created_by' => "varchar(100) DEFAULT ''"
            ];

            foreach ($neededCols as $col => $colDef) {
                if (!in_array($col, $existingCols)) {
                    $this->db->exec("ALTER TABLE `assets` ADD COLUMN `{$col}` {$colDef}");
                }
            }

            $catCnt = $this->db->query("SELECT COUNT(*) FROM `asset_categories`")->fetchColumn();
            if ($catCnt == 0) {
                $defaultFieldsMap = [
                    '计算机'   => ['category'=>1,'name'=>1,'code'=>1,'model'=>1,'config'=>1,'user'=>1,'location'=>1,'purpose'=>1,'mac1'=>1,'ip1'=>1,'mac2'=>1,'ip2'=>1,'status'=>1,'image_path'=>1,'out_date'=>0,'price'=>0,'remark'=>1],
                    '资产'     => ['category'=>1,'name'=>1,'code'=>1,'model'=>1,'config'=>1,'user'=>1,'location'=>1,'purpose'=>1,'mac1'=>0,'ip1'=>0,'mac2'=>0,'ip2'=>0,'status'=>1,'image_path'=>1,'out_date'=>1,'price'=>1,'remark'=>1],
                    '工装夹具' => ['category'=>1,'name'=>1,'code'=>1,'model'=>1,'config'=>1,'user'=>1,'location'=>1,'purpose'=>1,'mac1'=>0,'ip1'=>0,'mac2'=>0,'ip2'=>0,'status'=>1,'image_path'=>1,'out_date'=>0,'price'=>0,'remark'=>1],
                    '北变工装' => ['category'=>1,'name'=>1,'code'=>1,'model'=>1,'config'=>0,'user'=>1,'location'=>1,'purpose'=>1,'mac1'=>0,'ip1'=>0,'mac2'=>0,'ip2'=>0,'status'=>1,'image_path'=>1,'out_date'=>0,'price'=>0,'remark'=>1],
                    '其他'     => ['category'=>1,'name'=>1,'code'=>1,'model'=>1,'config'=>1,'user'=>1,'location'=>1,'purpose'=>1,'mac1'=>0,'ip1'=>0,'mac2'=>0,'ip2'=>0,'status'=>1,'image_path'=>1,'out_date'=>1,'price'=>1,'remark'=>1]
                ];
                $insCat = $this->db->prepare("INSERT INTO `asset_categories` (`name`, `show_fields`) VALUES (?, ?)");
                foreach ($defaultFieldsMap as $cName => $sf) {
                    $insCat->execute([$cName, json_encode($sf, JSON_UNESCAPED_UNICODE)]);
                }
            }
        } catch (Throwable $e) {}
    }

    private function getUserPermissions(): array
    {
        if ($this->isAdmin) {
            return ['is_admin' => true, 'allowed' => [], 'can_edit' => true, 'can_delete' => true];
        }

        $userId = (int)$this->currentUser['id'];
        $freshUser = null;
        try {
            $stU = $this->db->prepare("SELECT * FROM `users` WHERE `id` = ? LIMIT 1");
            $stU->execute([$userId]);
            $freshUser = $stU->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        $activeUser = $freshUser ?: $this->currentUser;
        $allowedList = [];
        $selfFullName = trim((string)($activeUser['full_name'] ?? ''));
        $selfUsername = trim((string)($activeUser['username'] ?? ''));

        if (!empty($selfFullName)) $allowedList[] = $selfFullName;
        if (!empty($selfUsername) && !in_array($selfUsername, $allowedList)) $allowedList[] = $selfUsername;

        $role = trim((string)($activeUser['role'] ?? ''));
        $team = trim((string)($activeUser['team'] ?? ''));
        $groupName = trim((string)($activeUser['group_name'] ?? ''));
        $dept = trim((string)($activeUser['department'] ?? ''));

        $isLead = false;
        if (preg_match('/(班长|组长|主管|领班|主任|部长|科长|经理|lead)/i', $role . ' ' . $groupName . ' ' . $team)) {
            $isLead = true;
        }

        $targetTeams = [];
        if (!empty($team) && $team !== '全部' && $team !== '全厂') $targetTeams[] = $team;
        if (!empty($groupName) && $groupName !== '全部' && !in_array($groupName, $targetTeams)) $targetTeams[] = $groupName;
        if (!empty($dept) && $dept !== '全部' && !in_array($dept, $targetTeams)) $targetTeams[] = $dept;

        $permRow = null;
        try {
            $stPerm = $this->db->prepare("SELECT `allowed_groups`, `allowed_users`, `can_edit`, `can_delete` FROM `asset_user_permissions` WHERE `user_id` = ? LIMIT 1");
            $stPerm->execute([$userId]);
            $permRow = $stPerm->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        if ($permRow && !empty($permRow['allowed_groups'])) {
            $extraGrps = array_filter(array_map('trim', explode(',', (string)$permRow['allowed_groups'])));
            foreach ($extraGrps as $eg) {
                if (!empty($eg) && !in_array($eg, $targetTeams)) {
                    $targetTeams[] = $eg;
                }
            }
        }

        $userCols = [];
        try {
            $userCols = $this->db->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {}

        $hasTeam = in_array('team', $userCols);
        $hasGroup = in_array('group_name', $userCols);
        $hasRole = in_array('role', $userCols);
        $hasDept = in_array('department', $userCols);
        $hasFullName = in_array('full_name', $userCols);

        if (($isLead || !empty($permRow['allowed_groups'])) && !empty($targetTeams)) {
            foreach ($targetTeams as $t) {
                if (empty($t)) continue;
                $whereParts = [];
                $params = [];
                if ($hasTeam) { $whereParts[] = "`team` LIKE ?"; $params[] = "%{$t}%"; }
                if ($hasGroup) { $whereParts[] = "`group_name` LIKE ?"; $params[] = "%{$t}%"; }
                if ($hasDept) { $whereParts[] = "`department` LIKE ?"; $params[] = "%{$t}%"; }
                if ($hasRole) { $whereParts[] = "`role` LIKE ?"; $params[] = "%{$t}%"; }

                if (!empty($whereParts)) {
                    $sqlTeam = "SELECT `username`" . ($hasFullName ? ", `full_name`" : "") . " FROM `users` WHERE " . implode(' OR ', $whereParts);
                    $stTeam = $this->db->prepare($sqlTeam);
                    $stTeam->execute($params);
                    while ($mUser = $stTeam->fetch(PDO::FETCH_ASSOC)) {
                        $fn = trim((string)($mUser['full_name'] ?? ''));
                        $un = trim((string)($mUser['username'] ?? ''));
                        if (!empty($fn) && !in_array($fn, $allowedList)) $allowedList[] = $fn;
                        if (!empty($un) && !in_array($un, $allowedList)) $allowedList[] = $un;
                    }
                }
            }
        }

        if ($permRow && !empty($permRow['allowed_users'])) {
            $customAllowed = array_filter(array_map('trim', explode(',', (string)$permRow['allowed_users'])));
            foreach ($customAllowed as $cu) {
                if (!empty($cu) && !in_array($cu, $allowedList)) {
                    $allowedList[] = $cu;
                }
            }
        }

        // ★ 是否可修改"使用人/保管人"字段：
        //   管理员 / 班长(组长等) / 被授权的组管理员 才可指定归属人；
        //   普通作业员只能编辑自己名下资产的其它信息，不能改归属人。
        $canAssign = $isLead || ($permRow && !empty($permRow['allowed_groups']));

        return [
            'is_admin'   => false,
            'allowed'    => array_values($allowedList),
            'can_edit'   => true,
            'can_delete' => $permRow ? (!empty($permRow['can_delete'])) : false,
            'can_assign' => $canAssign ? true : false
        ];
    }

    private function compressAndSaveImage(string $sourcePath, string $targetPath, int $maxSizeBytes = 307200): bool
    {
        $fileSize = filesize($sourcePath);
        if ($fileSize <= 260 * 1024) {
            return move_uploaded_file($sourcePath, $targetPath);
        }

        $info = getimagesize($sourcePath);
        if (!$info) return move_uploaded_file($sourcePath, $targetPath);

        $mime = $info['mime'];
        switch ($mime) {
            case 'image/jpeg': $image = imagecreatefromjpeg($sourcePath); break;
            case 'image/png':  $image = imagecreatefrompng($sourcePath); break;
            case 'image/webp': $image = imagecreatefromwebp($sourcePath); break;
            default: return move_uploaded_file($sourcePath, $targetPath);
        }

        if (!$image) return move_uploaded_file($sourcePath, $targetPath);

        $width = imagesx($image);
        $height = imagesy($image);
        $maxDim = 1280;

        if ($width > $maxDim || $height > $maxDim) {
            $ratio = min($maxDim / $width, $maxDim / $height);
            $newW = (int)($width * $ratio);
            $newH = (int)($height * $ratio);
            $newImg = imagecreatetruecolor($newW, $newH);
            imagealphablending($newImg, false);
            imagesavealpha($newImg, true);
            imagecopyresampled($newImg, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
            imagedestroy($image);
            $image = $newImg;
        }

        $quality = 85;
        imagejpeg($image, $targetPath, $quality);

        while (filesize($targetPath) > $maxSizeBytes && $quality > 25) {
            $quality -= 10;
            imagejpeg($image, $targetPath, $quality);
        }

        imagedestroy($image);
        return true;
    }

    public function dashboardStats(): void
    {
        try {
            $perms = $this->getUserPermissions();
            $where = ["1=1"];
            $params = [];
            if (!$this->isAdmin) {
                if (empty($perms['allowed'])) {
                    $where[] = "1=0";
                } else {
                    $phAllowed = implode(',', array_fill(0, count($perms['allowed']), '?'));
                    $where[] = "`user` IN ($phAllowed)";
                    foreach ($perms['allowed'] as $au) { $params[] = $au; }
                }
            }
            $whereSql = implode(' AND ', $where);

            $stTotal = $this->db->prepare("SELECT COUNT(*) as total_count, COALESCE(SUM(price), 0) as total_value FROM `assets` WHERE $whereSql");
            $stTotal->execute($params);
            $totalRow = $stTotal->fetch(PDO::FETCH_ASSOC);

            $stInUse = $this->db->prepare("SELECT COUNT(*) FROM `assets` WHERE $whereSql AND (`status` LIKE '%在用%' OR `status` LIKE '%正常%' OR `status` = '' OR `status` IS NULL)");
            $stInUse->execute($params);
            $inUseCount = (int)$stInUse->fetchColumn();

            $stAbnormal = $this->db->prepare("SELECT `id`, `category`, `name`, `code`, `user`, `location`, `status`, `remark` FROM `assets` WHERE $whereSql AND (`status` REGEXP '停用|坏|报废|停产|报修' OR `remark` REGEXP '停用|坏|报废|停产|报修') ORDER BY id DESC LIMIT 15");
            $stAbnormal->execute($params);
            $abnormalList = $stAbnormal->fetchAll(PDO::FETCH_ASSOC);

            $cats = $this->db->query("SELECT * FROM `asset_categories` ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $catCards = [];
            foreach ($cats as $c) {
                $catParams = $params;
                $catWhere = $where;
                $catWhere[] = "`category` = ?";
                $catParams[] = $c['name'];
                $catWhereSql = implode(' AND ', $catWhere);

                $stCat = $this->db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(price), 0) as val FROM `assets` WHERE $catWhereSql");
                $stCat->execute($catParams);
                $cRes = $stCat->fetch(PDO::FETCH_ASSOC);

                $stCatAb = $this->db->prepare("SELECT COUNT(*) FROM `assets` WHERE $catWhereSql AND (`status` REGEXP '停用|坏|报废|停产|报修' OR `remark` REGEXP '停用|坏|报废|停产|报修')");
                $stCatAb->execute($catParams);
                $abCnt = (int)$stCatAb->fetchColumn();

                $catCards[] = [
                    'id'             => $c['id'],
                    'name'           => $c['name'],
                    'count'          => (int)$cRes['cnt'],
                    'total_price'    => (float)$cRes['val'],
                    'abnormal_count' => $abCnt
                ];
            }

            Response::success('success', [
                'total_count'    => (int)$totalRow['total_count'],
                'total_value'    => (float)$totalRow['total_value'],
                'in_use_count'   => $inUseCount,
                'abnormal_count' => count($abnormalList),
                'abnormal_list'  => $abnormalList,
                'categories'     => $catCards
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取看板数据失败');
        }
    }

    public function getCategories(): void
    {
        try {
            $cats = $this->db->query("SELECT `id`, `name`, `show_fields` FROM `asset_categories` ORDER BY `id` ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cats as &$c) {
                $c['show_fields'] = !empty($c['show_fields']) ? json_decode((string)$c['show_fields'], true) : [];
            }
            Response::success('success', [
                'categories' => $cats,
                'all_fields' => $this->allFields,
                'user_perms' => $this->getUserPermissions(),
                'is_admin'   => $this->isAdmin
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取分类失败');
        }
    }

    public function list(): void
    {
        try {
            $perms = $this->getUserPermissions();
            $category = trim((string)($_GET['category'] ?? ''));
            $search = trim((string)($_GET['search'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 30;
            $offset = ($page - 1) * $limit;

            $where = ["1=1"];
            $params = [];

            if (!$this->isAdmin) {
                if (empty($perms['allowed'])) {
                    $where[] = "1=0";
                } else {
                    $phAllowed = implode(',', array_fill(0, count($perms['allowed']), '?'));
                    $where[] = "`user` IN ($phAllowed)";
                    foreach ($perms['allowed'] as $au) { $params[] = $au; }
                }
            }

            if ($category !== '__ALL__' && $category !== '') {
                $where[] = "`category` = ?";
                $params[] = $category;
            }

            if ($search !== '') {
                $where[] = "(`name` LIKE ? OR `code` LIKE ? OR `user` LIKE ? OR `location` LIKE ? OR `model` LIKE ? OR `config` LIKE ? OR `remark` LIKE ? OR `mac1` LIKE ? OR `ip1` LIKE ?)";
                for ($i = 0; $i < 9; $i++) $params[] = "%{$search}%";
            }

            $whereSql = implode(' AND ', $where);

            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM `assets` WHERE {$whereSql}");
            $cntStmt->execute($params);
            $total = (int)$cntStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($total / $limit));

            $stmt = $this->db->prepare("SELECT `id`, `category`, `name`, `code`, `model`, `config`, `user`, `location`, `purpose`, `mac1`, `ip1`, `mac2`, `ip2`, `status`, `image_path`, `out_date`, `price`, `remark` FROM `assets` WHERE {$whereSql} ORDER BY `id` DESC LIMIT {$offset}, {$limit}");
            $stmt->execute($params);
            $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stShareImg = $this->db->prepare("SELECT `image_path` FROM `assets` 
                WHERE `name` = ? AND `model` = ? AND `config` = ? AND `image_path` IS NOT NULL AND `image_path` != '' AND `image_path` NOT LIKE '!DISABLE_BORROW!%' 
                ORDER BY `id` DESC LIMIT 1");

            foreach ($assets as &$a) {
                $rawImg = trim((string)($a['image_path'] ?? ''));
                if (str_starts_with($rawImg, '!DISABLE_BORROW!')) {
                    $a['image_path'] = substr($rawImg, strlen('!DISABLE_BORROW!'));
                    $a['disable_borrow'] = 1;
                } elseif (empty($rawImg)) {
                    if (!empty($a['name'])) {
                        $stShareImg->execute([$a['name'], $a['model'] ?? '', $a['config'] ?? '']);
                        $shareImg = $stShareImg->fetchColumn();
                        if (!empty($shareImg)) {
                            $a['image_path'] = $shareImg;
                            $a['is_borrowed_img'] = 1;
                        }
                    }
                }
            }
            unset($a);

            $showFields = [];
            if ($category !== '__ALL__' && $category !== '') {
                $stCat = $this->db->prepare("SELECT `show_fields` FROM `asset_categories` WHERE `name` = ? LIMIT 1");
                $stCat->execute([$category]);
                $sfJson = $stCat->fetchColumn();
                if ($sfJson) $showFields = json_decode((string)$sfJson, true) ?: [];
            }
            
            if (empty($showFields)) {
                $showFields = array_fill_keys(array_keys($this->allFields), 1);
            }

            Response::success('success', [
                'list'             => $assets,
                'total'            => $total,
                'total_pages'      => $totalPages,
                'page'             => $page,
                'current_category' => $category,
                'show_fields'      => $showFields,
                'all_fields'       => $this->allFields,
                'user_perms'       => $perms
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '查询资产列表异常');
        }
    }

    public function save(): void
    {
        try {
            $perms = $this->getUserPermissions();
            $id = (int)($_POST['id'] ?? 0);
            $category = trim((string)($_POST['category'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $code = trim((string)($_POST['code'] ?? ''));
            $userPerson = trim((string)($_POST['user'] ?? ''));
            $location = trim((string)($_POST['location'] ?? ''));

            if (!$this->isAdmin) {
                if ($id > 0) {
                    $stOld = $this->db->prepare("SELECT `user` FROM `assets` WHERE `id` = ? LIMIT 1");
                    $stOld->execute([$id]);
                    $oldUser = trim((string)($stOld->fetchColumn() ?: ''));
                    if (!in_array($oldUser, $perms['allowed'])) {
                        Response::error("权限受限：您没有权限修改【{$oldUser}】名下的资产！");
                        return;
                    }
                    // ★ 无"分配归属"权限者（普通作业员），不允许修改使用人/保管人，强制保持原值
                    if (empty($perms['can_assign']) && $userPerson !== $oldUser) {
                        $userPerson = $oldUser;
                    }
                }
                if (!in_array($userPerson, $perms['allowed'])) {
                    Response::error("权限受限：您只能将保管人/使用人指定为您管辖的组员或自己！");
                    return;
                }
            }

            if (empty($category) || empty($name) || empty($code) || empty($userPerson) || empty($location)) {
                Response::error('分类、名称、编号、使用人及使用地点为必填项！');
                return;
            }

            $chk = $this->db->prepare("SELECT `id` FROM `assets` WHERE `category` = ? AND `code` = ? AND `id` != ?");
            $chk->execute([$category, $code, $id]);
            if ($chk->fetch()) {
                Response::error("编号【{$code}】在该分类中已存在！");
                return;
            }

            $imagePath = trim((string)($_POST['image_url'] ?? ''));
            if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../../public/uploads/assets/';
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                $filename = time() . '_' . mt_rand(100, 999) . '.jpg';
                $targetFile = $uploadDir . $filename;
                
                if ($this->compressAndSaveImage($_FILES['image_file']['tmp_name'], $targetFile, 307200)) {
                    $imagePath = '/uploads/assets/' . $filename;
                }
            }

            $disableBorrow = !empty($_POST['disable_borrowed_image']);
            if ($disableBorrow && !empty($imagePath)) {
                $imagePath = '!DISABLE_BORROW!' . ltrim($imagePath, '!DISABLE_BORROW!');
            }

            $statusVal = trim((string)($_POST['status'] ?? ''));
            if (empty($statusVal)) $statusVal = '在用';

            $operatorName = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : $this->currentUser['username'];

            $fields = [
                'category'   => $category,
                'name'       => $name,
                'code'       => $code,
                'model'      => trim((string)($_POST['model'] ?? '')),
                'config'     => trim((string)($_POST['config'] ?? '')),
                'user'       => $userPerson,
                'location'   => $location,
                'purpose'    => trim((string)($_POST['purpose'] ?? '')),
                'mac1'       => trim((string)($_POST['mac1'] ?? '')),
                'ip1'        => trim((string)($_POST['ip1'] ?? '')),
                'mac2'       => trim((string)($_POST['mac2'] ?? '')),
                'ip2'        => trim((string)($_POST['ip2'] ?? '')),
                'status'     => $statusVal,
                'out_date'   => trim((string)($_POST['out_date'] ?? '')),
                'price'      => (float)($_POST['price'] ?? 0),
                'remark'     => trim((string)($_POST['remark'] ?? ''))
            ];

            $colsInDb = $this->db->query("SHOW COLUMNS FROM `assets`")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('created_by', $colsInDb) && $id <= 0) {
                $fields['created_by'] = $operatorName;
            }

            if (!empty($imagePath)) {
                $fields['image_path'] = $imagePath;
            } elseif (isset($_POST['delete_image']) && (string)$_POST['delete_image'] === '1') {
                $fields['image_path'] = '';
            }

            if ($id > 0) {
                $setParts = [];
                $vals = [];
                foreach ($fields as $k => $v) {
                    $setParts[] = "`$k` = ?";
                    $vals[] = $v;
                }
                $vals[] = $id;
                $stmt = $this->db->prepare("UPDATE `assets` SET " . implode(', ', $setParts) . " WHERE `id` = ?");
                $stmt->execute($vals);
                AuditService::log('资产管理', '修改资产');
                Response::success('资产修改成功！');
            } else {
                $cols = array_keys($fields);
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $stmt = $this->db->prepare("INSERT INTO `assets` (`" . implode('`, `', $cols) . "`) VALUES ($ph)");
                $stmt->execute(array_values($fields));
                AuditService::log('资产管理', '新增资产');
                Response::success('资产录入成功！', ['id' => $this->db->lastInsertId()]);
            }
        } catch (Throwable $e) {
            Response::exception($e, '保存失败');
        }
    }

    public function listUserPermissions(): void
    {
        if (!$this->isAdmin) {
            Response::error('只有管理员可配置资产权限', [], 403);
            return;
        }
        try {
            $sql = "SELECT u.id as user_id, u.username, u.full_name, u.role, u.team, 
                           p.allowed_groups, 
                           p.allowed_users, 
                           COALESCE(p.can_edit, 1) as can_edit, 
                           COALESCE(p.can_delete, 0) as can_delete 
                    FROM `users` u 
                    LEFT JOIN `asset_user_permissions` p ON u.id = p.user_id 
                    ORDER BY u.id ASC";
            $users = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            $groupList = [];
            try {
                $teamCols = $this->db->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('team', $teamCols)) {
                    $tList = $this->db->query("SELECT DISTINCT `team` FROM `users` WHERE `team` IS NOT NULL AND `team` != ''")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($tList as $t) {
                        if (!in_array($t, $groupList)) $groupList[] = $t;
                    }
                }
            } catch (Throwable $e) {}

            if (empty($groupList)) {
                $groupList = ['装置装配', '装置调试', '装置包装', '技术/质检', '管理/其他'];
            }

            Response::success('success', [
                'users'            => $users,
                'available_groups' => $groupList
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '获取权限列表失败');
        }
    }

    public function saveUserPermissions(): void
    {
        if (!$this->isAdmin) {
            Response::error('无权操作', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $userId = (int)($input['user_id'] ?? 0);
            $allowedGroups = is_array($input['allowed_groups'] ?? null) ? implode(',', $input['allowed_groups']) : trim((string)($input['allowed_groups'] ?? ''));
            $allowedUsers = is_array($input['allowed_users'] ?? null) ? implode(',', $input['allowed_users']) : trim((string)($input['allowed_users'] ?? ''));
            $canEdit = !empty($input['can_edit']) ? 1 : 0;
            $canDelete = !empty($input['can_delete']) ? 1 : 0;

            if ($userId <= 0) {
                Response::error('无效的用户ID');
                return;
            }

            $stmt = $this->db->prepare("INSERT INTO `asset_user_permissions` (`user_id`, `allowed_groups`, `allowed_users`, `can_edit`, `can_delete`, `updated_at`) 
                                  VALUES (?, ?, ?, ?, ?, NOW()) 
                                  ON DUPLICATE KEY UPDATE `allowed_groups` = VALUES(`allowed_groups`), `allowed_users` = VALUES(`allowed_users`), `can_edit` = VALUES(`can_edit`), `can_delete` = VALUES(`can_delete`), `updated_at` = NOW()");
            $stmt->execute([$userId, $allowedGroups, $allowedUsers, $canEdit, $canDelete]);

            AuditService::log('资产管理', '保存用户资产权限');
            Response::success('用户资产权限保存成功！');
        } catch (Throwable $e) {
            Response::exception($e, '保存权限异常');
        }
    }

    public function delete(): void
    {
        $perms = $this->getUserPermissions();
        if (!$this->isAdmin && empty($perms['can_delete'])) {
            Response::error('您没有删除资产的权限！', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $ids = $input['ids'] ?? [];

            if (empty($ids) || !is_array($ids)) {
                Response::error('请勾选要删除的资产');
                return;
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("DELETE FROM `assets` WHERE `id` IN ($ph)");
            $stmt->execute($ids);

            AuditService::log('资产管理', '删除资产');
            Response::success('所选资产已成功删除！');
        } catch (Throwable $e) {
            Response::exception($e, '删除异常');
        }
    }

    public function saveCategory(): void
    {
        if (!$this->isAdmin) {
            Response::error('无权操作', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;

            $id = (int)($input['id'] ?? 0);
            $name = trim((string)($input['name'] ?? ''));
            $showFields = json_encode($input['show_fields'] ?? [], JSON_UNESCAPED_UNICODE);

            if (empty($name)) {
                Response::error('分类名称不能为空');
                return;
            }

            if ($id > 0) {
                $stmt = $this->db->prepare("UPDATE `asset_categories` SET `name` = ?, `show_fields` = ? WHERE `id` = ?");
                $stmt->execute([$name, $showFields, $id]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO `asset_categories` (`name`, `show_fields`) VALUES (?, ?)");
                $stmt->execute([$name, $showFields]);
            }
            AuditService::log('资产管理', '保存资产分类配置');
            Response::success('分类及字段配置保存成功！');
        } catch (Throwable $e) {
            Response::exception($e, '保存分类失败');
        }
    }

    public function deleteCategory(): void
    {
        if (!$this->isAdmin) {
            Response::error('无权操作', [], 403);
            return;
        }
        try {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: $_POST;
            $id = (int)($input['id'] ?? ($_POST['id'] ?? 0));
            $this->db->prepare("DELETE FROM `asset_categories` WHERE `id` = ?")->execute([$id]);
            AuditService::log('资产管理', '删除资产分类');
            Response::success('分类已删除');
        } catch (Throwable $e) {
            Response::error('删除失败');
        }
    }

    public function importCsv(): void
    {
        try {
            $category = trim((string)($_POST['category'] ?? ''));
            if (empty($category) || $category === '__ALL__' || $category === '__DASHBOARD__') {
                $category = '计算机';
            }

            $matrix = null;
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower((string)pathinfo((string)($_FILES['file']['name'] ?? ''), PATHINFO_EXTENSION));
                if (in_array($ext, ['xlsx', 'xls'], true) && class_exists(Spreadsheet::class)) {
                    $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
                    $matrix = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
                } else {
                    $matrix = $this->csvToMatrix((string)file_get_contents($_FILES['file']['tmp_name']));
                }
            } else {
                $raw = file_get_contents('php://input');
                $input = json_decode($raw, true) ?: [];
                if (!empty($input['csv_content'])) {
                    $matrix = $this->csvToMatrix((string)$input['csv_content']);
                }
            }

            if (empty($matrix)) {
                Response::error('文件内容为空或格式不受支持（支持 .xlsx / .xls / .csv）！');
                return;
            }

            $header = array_shift($matrix);
            $header = array_map(static fn($v) => trim((string)$v), is_array($header) ? $header : []);

            $flipFields = array_flip($this->allFields);
            $standardHeader = [];
            foreach ($header as $h) {
                $standardHeader[] = $flipFields[$h] ?? $h;
            }

            $colsInDb = $this->db->query("SHOW COLUMNS FROM `assets`")->fetchAll(PDO::FETCH_COLUMN);
            $hasCreatedBy = in_array('created_by', $colsInDb);
            $operatorName = !empty($this->currentUser['full_name']) ? $this->currentUser['full_name'] : $this->currentUser['username'];

            $successCount = 0;
            $skipped = [];
            $stmtCheck = $this->db->prepare("SELECT `id` FROM `assets` WHERE `category` = ? AND `code` = ? LIMIT 1");

            $this->db->beginTransaction();
            foreach ($matrix as $row) {
                if (!is_array($row)) continue;
                $row = array_map(static fn($v) => trim((string)$v), $row);
                if (empty($row) || count(array_filter($row, static fn($v) => $v !== '')) === 0) continue;

                $data = [];
                foreach ($standardHeader as $idx => $keyName) {
                    $data[$keyName] = trim((string)($row[$idx] ?? ''));
                }

                $code = trim($data['code'] ?? '');
                $name = trim($data['name'] ?? '');
                if (empty($code) || empty($name)) continue;

                $rowCat = !empty($data['category']) ? $data['category'] : $category;

                $stmtCheck->execute([$rowCat, $code]);
                if ($stmtCheck->fetch()) {
                    $skipped[] = "编号【{$code}】已存在，已跳过";
                    continue;
                }

                $statusVal = trim($data['status'] ?? '');
                if (empty($statusVal) || in_array(strtolower($statusVal), ['null', 'nan'])) {
                    $statusVal = '在用';
                }

                $priceVal = 0.00;
                if (!empty($data['price']) && is_numeric($data['price'])) {
                    $priceVal = (float)$data['price'];
                }

                $insData = [
                    'category'   => $rowCat,
                    'name'       => $name,
                    'code'       => $code,
                    'model'      => $data['model'] ?? '',
                    'config'     => $data['config'] ?? '',
                    'user'       => $data['user'] ?? '',
                    'location'   => $data['location'] ?? '',
                    'purpose'    => $data['purpose'] ?? '',
                    'mac1'       => $data['mac1'] ?? '',
                    'ip1'        => $data['ip1'] ?? '',
                    'mac2'       => $data['mac2'] ?? '',
                    'ip2'        => $data['ip2'] ?? '',
                    'status'     => $statusVal,
                    'out_date'   => $data['out_date'] ?? '',
                    'price'      => $priceVal,
                    'remark'     => $data['remark'] ?? ''
                ];

                if ($hasCreatedBy) {
                    $insData['created_by'] = $operatorName;
                }

                $cols = array_keys($insData);
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $stmtIns = $this->db->prepare("INSERT INTO `assets` (`" . implode('`, `', $cols) . "`) VALUES ($ph)");
                $stmtIns->execute(array_values($insData));
                $successCount++;
            }

            $this->db->commit();
            $msg = "🎉 导入完成！成功录入 {$successCount} 项资产。";
            if (!empty($skipped)) {
                $msg .= " (跳过 " . count($skipped) . " 条重复项)";
            }
            AuditService::log('资产管理', '导入资产CSV');
            Response::success($msg);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::exception($e, '数据写入异常');
        }
    }

    public function exportCsv(): void
    {
        try {
            $idsStr = trim((string)($_GET['ids'] ?? ''));
            $category = trim((string)($_GET['category'] ?? ''));

            if (!empty($idsStr)) {
                $ids = array_filter(array_map('intval', explode(',', $idsStr)));
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $this->db->prepare("SELECT * FROM `assets` WHERE `id` IN ($ph)");
                $stmt->execute($ids);
            } elseif ($category !== '__ALL__' && $category !== '') {
                $stmt = $this->db->prepare("SELECT * FROM `assets` WHERE `category` = ?");
                $stmt->execute([$category]);
            } else {
                $stmt = $this->db->query("SELECT * FROM `assets` ORDER BY `id` DESC");
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $headers = array_values($this->allFields);
            $fieldKeys = array_keys($this->allFields);

            // 优先导出 Excel（服务器已安装 PhpSpreadsheet）
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('资产台账');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($rows as $r) {
                    $col = 'A';
                    foreach ($fieldKeys as $key) {
                        if ($key === 'price') {
                            $sheet->setCellValueExplicit($col . $line, (string)($r['price'] ?? ''), DataType::TYPE_STRING);
                        } else {
                            $sheet->setCellValueExplicit($col . $line, (string)($r[$key] ?? ''), DataType::TYPE_STRING);
                        }
                        $col++;
                    }
                    $line++;
                }

                $lastCol = 'A';
                for ($i = 1; $i < count($headers); $i++) { $lastCol++; }
                $sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:' . $lastCol . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', $lastCol) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = '资产数据导出_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="资产数据导出_' . date('Ymd_His') . '.csv"');
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, array_map('excelSafe', $headers));
            foreach ($rows as $r) {
                $line = [];
                foreach ($fieldKeys as $col) {
                    $line[] = $r[$col] ?? '';
                }
                fputcsv($output, array_map('excelSafe', $line));
            }
            fclose($output);
            exit;
        } catch (Throwable $e) {
            Response::exception($e, '导出失败');
        }
    }

    /**
     * 将 CSV 文本解析为二维数组（自动处理 BOM 与常见中文编码），
     * 供 Excel / CSV 两种导入路径统一消费。
     */
    private function csvToMatrix(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $encode = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5'], true);
        if ($encode && $encode !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encode);
        }

        $matrix = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) as $lineStr) {
            $lineStr = trim($lineStr);
            if ($lineStr === '') continue;
            $matrix[] = str_getcsv($lineStr);
        }
        return $matrix;
    }
}