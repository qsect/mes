<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\AuditService;
use PDO;
use Throwable;

class DispatchKittingController
{
    /**
     * ★ 安全加固（H1）：该控制器所有接口均对外部 ERP / MES 做代理查询，
     *   必须登录后才能访问；此前整个类没有构造函数，导致 query / queryWoKitting /
     *   queryWoMaterials 三个接口匿名即可访问。
     */
    public function __construct()
    {
        if (function_exists('checkUser')) {
            checkUser();
            return;
        }
        if (empty($_SESSION['user'])) {
            \App\Core\Response::error('登录已失效，请重新登录', ['need_login' => true], 401);
            exit;
        }
    }

    private function ensureBizTables(PDO $pdo): void
    {
        if (\App\Core\Schema::isReady('dispatch_biz')) { return; }
        $sqls = [
            "CREATE TABLE IF NOT EXISTS `tab_delivery_receipt` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `peisong_order` VARCHAR(64) NOT NULL,
              `wo` VARCHAR(64) NOT NULL,
              `mater_no` VARCHAR(64) NOT NULL,
              `mater_desc` VARCHAR(255) DEFAULT '',
              `send_qty` DECIMAL(18, 4) NOT NULL DEFAULT 0.0000,
              `confirm_user` VARCHAR(64) DEFAULT '',
              `confirm_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_wo` (`wo`), INDEX `idx_ps` (`peisong_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            "CREATE TABLE IF NOT EXISTS `tab_delivery_batch` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `peisong_order` VARCHAR(64) NOT NULL UNIQUE,
              `wo` VARCHAR(64) NOT NULL,
              `status` TINYINT NOT NULL DEFAULT 1,
              `confirm_user` VARCHAR(64) DEFAULT '',
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            "CREATE TABLE IF NOT EXISTS `tab_work_order_material_archive` (
              `wo` VARCHAR(64) PRIMARY KEY,
              `is_finished` TINYINT NOT NULL DEFAULT 1,
              `finished_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              `remark` VARCHAR(255) DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            "CREATE TABLE IF NOT EXISTS `tab_wo_packing_progress` (
              `wo` VARCHAR(64) PRIMARY KEY,
              `done_qty` INT NOT NULL DEFAULT 0,
              `cached_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_cached_at` (`cached_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ];
        foreach ($sqls as $sql) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
        \App\Core\Schema::markReady('dispatch_biz');
    }

    private function getExactColName(array $columns, array $targetNames)
    {
        foreach ($targetNames as $target) {
            foreach ($columns as $col) {
                if (trim($col) === $target) return $col;
            }
        }
        foreach ($targetNames as $target) {
            foreach ($columns as $col) {
                if (mb_stripos($col, $target) !== false) return $col;
            }
        }
        return false;
    }

    private function resolveProcessName($procName, $procNo, $procShort): string
    {
        $name = trim((string)$procName);
        if (!empty($name) && $name !== '-' && !preg_match('/^[-0-9]+$/', $name)) return $name;
        $no = trim((string)$procNo);
        if (strpos($no, '30') !== false) return '装置装配';
        if (strpos($no, '40') !== false) return '装置调试';
        if (strpos($no, '60') !== false) return '装置包装';
        $short = trim((string)$procShort);
        if (!empty($short)) {
            if (strpos($short, '装配') !== false) return '装置装配';
            if (strpos($short, '包装') !== false) return '装置包装';
            if (strpos($short, '调试') !== false) return '装置调试';
            if ($short === '装置') return '装置装配';
            return $short;
        }
        return '';
    }

    private function isPackingProcess(string $procName): bool
    {
        $name = trim($procName);
        if ($name === '') return false;
        return strpos($name, '包装') !== false || stripos($name, 'pack') !== false;
    }

    private function fetchBomInfoByWos($pdo_sqlsrv, array $woList): array
    {
        $result = [];
        if (empty($woList) || !$pdo_sqlsrv) return $result;
        $chunks = array_chunk(array_values(array_unique($woList)), 50);

        foreach ($chunks as $chunk) {
            try {
                $wosParam = implode(',', $chunk);
                $sqlBom = "SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; EXEC [dbo].[自定义ERP组件_P] @WorkOrders = ?";
                $stmtBom = $pdo_sqlsrv->prepare($sqlBom);
                $stmtBom->execute([$wosParam]);
                while ($stmtBom->columnCount() === 0 && $stmtBom->nextRowset()) {}

                $bomCols = [];
                for ($i = 0; $i < $stmtBom->columnCount(); $i++) {
                    $meta = $stmtBom->getColumnMeta($i);
                    $bomCols[] = $meta['name'];
                }
                $rawBoms = $stmtBom->fetchAll(PDO::FETCH_ASSOC);

                $cBWo        = $this->getExactColName($bomCols, ['工单号','任务号','工单']) ?: ($bomCols[0] ?? '');
                $cBProcName  = $this->getExactColName($bomCols, ['工序名称']) ?: '';
                $cBProcNo    = $this->getExactColName($bomCols, ['工序号'])   ?: '';
                $cBProcShort = $this->getExactColName($bomCols, ['工序名'])   ?: '';
                $cBMat       = $this->getExactColName($bomCols, ['组件编码','物料代码','物料编码','料号','mat_no','item_no']) ?: '';
                $cBDesc      = $this->getExactColName($bomCols, ['组件描述','物料名称','物料描述','子项名称','mat_desc','item_name']) ?: '';
                $cBReq       = $this->getExactColName($bomCols, ['需求量','需求数量','数量','qty']) ?: '';

                foreach ($rawBoms as $bRow) {
                    $wo  = trim((string)($cBWo  ? ($bRow[$cBWo]  ?? '') : ''));
                    $mat = trim((string)($cBMat ? ($bRow[$cBMat] ?? '') : ''));
                    if ($wo === '' || $mat === '') continue;

                    $procNameRaw  = ($cBProcName  && isset($bRow[$cBProcName]))  ? trim((string)$bRow[$cBProcName])  : '';
                    $procNoRaw    = ($cBProcNo    && isset($bRow[$cBProcNo]))    ? trim((string)$bRow[$cBProcNo])    : '';
                    $procShortRaw = ($cBProcShort && isset($bRow[$cBProcShort])) ? trim((string)$bRow[$cBProcShort]) : '';
                    if ($procNoRaw !== '' && (strpos($procNoRaw, '-') !== false || floatval($procNoRaw) < 0)) continue;

                    $realProc = $this->resolveProcessName($procNameRaw, $procNoRaw, $procShortRaw);
                    $desc     = ($cBDesc && isset($bRow[$cBDesc])) ? trim((string)$bRow[$cBDesc]) : '';
                    $req      = ($cBReq  && isset($bRow[$cBReq]))  ? floatval(str_replace(',', '', (string)$bRow[$cBReq])) : 0;
                    $hasProc  = ($procNameRaw !== '' || $procNoRaw !== '' || $procShortRaw !== '');

                    if (!isset($result[$wo])) $result[$wo] = [];
                    if (!isset($result[$wo][$mat])) {
                        $result[$wo][$mat] = [
                            'mater_desc' => $desc, 'proc_name' => $realProc,
                            'has_proc' => $hasProc, 'req_qty' => $req
                        ];
                    } else {
                        $result[$wo][$mat]['req_qty'] += $req;
                        if ($result[$wo][$mat]['mater_desc'] === '' && $desc !== '') {
                            $result[$wo][$mat]['mater_desc'] = $desc;
                        }
                        if ($realProc !== '' && $this->isPackingProcess($realProc)) {
                            $result[$wo][$mat]['proc_name'] = $realProc;
                            $result[$wo][$mat]['has_proc'] = true;
                        } elseif ($result[$wo][$mat]['proc_name'] === '' && $realProc !== '') {
                            $result[$wo][$mat]['proc_name'] = $realProc;
                            $result[$wo][$mat]['has_proc'] = $hasProc;
                        }
                    }
                }
            } catch (Throwable $e) { continue; }
        }
        return $result;
    }

    private function getWorkOrderMetaBatch($pdo_mes, array $woList): array
    {
        $result = [];
        if (empty($woList) || !$pdo_mes) return $result;

        try {
            $ph = implode(',', array_fill(0, count($woList), '?'));
            $stmt = $pdo_mes->prepare(
                "SELECT task_no, 
                        MAX(mater_no)   AS mater_no, 
                        MAX(mater_desc) AS mater_desc, 
                        MAX(task_number) AS task_number
                 FROM tab_plan WHERE task_no IN ($ph)
                 GROUP BY task_no"
            );
            $stmt->execute(array_values($woList));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $wo = trim((string)($row['task_no'] ?? ''));
                if ($wo === '') continue;
                $result[$wo] = [
                    'mater_code' => trim((string)($row['mater_no']   ?? '')),
                    'mater_desc' => trim((string)($row['mater_desc'] ?? '')),
                    'plan_qty'   => floatval($row['task_number'] ?? 0)
                ];
            }

            $missing = array_values(array_diff($woList, array_keys($result)));
            if (!empty($missing)) {
                $ph2 = implode(',', array_fill(0, count($missing), '?'));
                $stmt2 = $pdo_mes->prepare(
                    "SELECT task_no, 
                            MAX(mater_no)   AS mater_no, 
                            MAX(mater_desc) AS mater_desc, 
                            MAX(task_number) AS task_number
                     FROM tab_job WHERE task_no IN ($ph2) GROUP BY task_no"
                );
                $stmt2->execute($missing);
                foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $wo = trim((string)($row['task_no'] ?? ''));
                    if ($wo === '' || isset($result[$wo])) continue;
                    $result[$wo] = [
                        'mater_code' => trim((string)($row['mater_no']   ?? '')),
                        'mater_desc' => trim((string)($row['mater_desc'] ?? '')),
                        'plan_qty'   => floatval($row['task_number'] ?? 0)
                    ];
                }
            }
        } catch (Throwable $e) {}

        return $result;
    }

    private function getPackingProgressCached(PDO $pdo_biz, $pdo_mes, array $woList): array
    {
        $result = [];
        if (empty($woList)) return $result;
        foreach ($woList as $w) $result[$w] = 0;

        try {
            $ph = implode(',', array_fill(0, count($woList), '?'));
            $stmt = $pdo_biz->prepare(
                "SELECT wo, done_qty FROM tab_wo_packing_progress WHERE wo IN ($ph)"
            );
            $stmt->execute(array_values($woList));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $result[$r['wo']] = intval($r['done_qty']);
            }
        } catch (Throwable $e) {}

        $missing = [];
        try {
            $ph = implode(',', array_fill(0, count($woList), '?'));
            $stmt = $pdo_biz->prepare(
                "SELECT wo FROM tab_wo_packing_progress WHERE wo IN ($ph)"
            );
            $stmt->execute(array_values($woList));
            $cached = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN, 0));
            foreach ($woList as $w) {
                if (!isset($cached[$w])) $missing[] = $w;
            }
        } catch (Throwable $e) {
            $missing = $woList;
        }

        if (!empty($missing) && $pdo_mes) {
            $realtime = $this->getPackingProgressBatch($pdo_mes, $missing);
            foreach ($realtime as $wo => $cnt) {
                $result[$wo] = $cnt;
            }
            try {
                $stmtUpsert = $pdo_biz->prepare(
                    "INSERT INTO tab_wo_packing_progress (wo, done_qty, cached_at) 
                     VALUES (?, ?, NOW()) 
                     ON DUPLICATE KEY UPDATE done_qty = VALUES(done_qty), cached_at = NOW()"
                );
                foreach ($realtime as $wo => $cnt) {
                    $stmtUpsert->execute([$wo, $cnt]);
                }
            } catch (Throwable $e) {}
        }

        return $result;
    }

    private function getPackingProgressBatch($pdo_mes, array $woList): array
    {
        $result = [];
        if (empty($woList) || !$pdo_mes) return $result;
        foreach ($woList as $w) $result[$w] = 0;

        try {
            $ph = implode(',', array_fill(0, count($woList), '?'));
            $sql1 = "SELECT j.task_no, j.gx_id
                     FROM tab_job j
                     JOIN tab_base_gx gx ON j.gx_id = gx.id
                     JOIN tab_base_zyzx zy ON j.zyzx_id = zy.id
                     WHERE j.task_no IN ($ph)
                       AND zy.name = '装置包装'
                       AND gx.name != '标识打印'";
            $stmt1 = $pdo_mes->prepare($sql1);
            $stmt1->execute(array_values($woList));
            $rows = $stmt1->fetchAll(PDO::FETCH_ASSOC);

            $allGxIds = [];
            foreach ($rows as $r) {
                $gx = $r['gx_id'];
                if ($gx !== null) $allGxIds[$gx] = true;
            }
            $allGxIds = array_keys($allGxIds);
            if (empty($allGxIds)) return $result;

            $phWos = implode(',', array_fill(0, count($woList), '?'));
            $phGx  = implode(',', array_fill(0, count($allGxIds), '?'));

            $sql2 = "SELECT ls.task_no, ls.gx_id, COUNT(DISTINCT ls.sn) AS done_qty
                     FROM tab_log_step ls
                     JOIN tab_base_node node ON ls.node_id = node.id
                     WHERE ls.task_no IN ($phWos)
                       AND ls.gx_id IN ($phGx)
                       AND ls.result = 1
                       AND node.pos = 2
                       AND ls.sn IN (SELECT DISTINCT sn FROM tab_log_job WHERE task_no IN ($phWos))
                     GROUP BY ls.task_no, ls.gx_id";
            $stmt2 = $pdo_mes->prepare($sql2);
            $params = array_merge(array_values($woList), $allGxIds, array_values($woList));
            $stmt2->execute($params);

            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $wo = trim((string)$r['task_no']);
                $cnt = intval($r['done_qty']);
                if (!isset($result[$wo]) || $cnt > $result[$wo]) {
                    $result[$wo] = $cnt;
                }
            }
        } catch (Throwable $e) {}

        return $result;
    }

    private function getLastConfirmInfoBatch($pdo_biz, array $woList): array
    {
        $result = [];
        if (empty($woList) || !$pdo_biz) return $result;

        try {
            $ph = implode(',', array_fill(0, count($woList), '?'));
            $sql = "SELECT b.wo, b.confirm_user, b.created_at
                    FROM tab_delivery_batch b
                    INNER JOIN (
                        SELECT wo, MAX(created_at) AS max_time
                        FROM tab_delivery_batch
                        WHERE wo IN ($ph)
                        GROUP BY wo
                    ) t ON b.wo = t.wo AND b.created_at = t.max_time";
            $stmt = $pdo_biz->prepare($sql);
            $stmt->execute(array_values($woList));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $wo = (string)$row['wo'];
                if ($wo === '') continue;
                $result[$wo] = [
                    'confirm_user' => (string)($row['confirm_user'] ?? ''),
                    'confirm_time' => (string)($row['created_at']   ?? '')
                ];
            }
        } catch (Throwable $e) {}

        return $result;
    }

    /**
     * 1. 配送单/工单物料明细查询 (Tab 1)
     */
    public function query(): void
    {
        $peisongOrder = trim($_GET['peisong_order'] ?? ($_POST['peisong_order'] ?? ''));
        $billCode     = trim($_GET['bill_code'] ?? ($_POST['bill_code'] ?? ''));

        if (empty($peisongOrder) && empty($billCode)) {
            sendJson(false, '请输入配送单号或工单号！');
        }

        $pdo_sqlsrv = function_exists('getSqlSrvDB') ? getSqlSrvDB() : null;
        $pdo_biz    = function_exists('getDB') ? getDB() : null;

        if (!$pdo_sqlsrv) sendJson(false, 'ERP 数据库未连接');
        if ($pdo_biz) $this->ensureBizTables($pdo_biz);

        try {
            $pdo_sqlsrv->exec("SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; SET ANSI_PADDING ON; SET QUOTED_IDENTIFIER ON;");

            $viewMode  = !empty($peisongOrder) ? 'by_peisong' : 'by_bill';
            $searchVal = !empty($peisongOrder) ? $peisongOrder : $billCode;
            if (!preg_match('/^[A-Za-z0-9_,\-]+$/', $searchVal)) {
                sendJson(false, '单号格式不合法');
            }

            $stmt = null; $directError = null; $usedDirect = false;
            try {
                $searchSafe = str_replace("'", "''", $searchVal);
                $inList     = "'" . str_replace(',', "','", $searchSafe) . "'";
                $whereField = ($viewMode === 'by_peisong') ? 'm.PS_TASK_CODE' : 'm.bill_code';
                $oracleSql  = "SELECT d.plmcreatedate 创建时间, m.PS_TASK_CODE 配送单号, m.bill_code 工单号,
                       mater_code 物料编码, out_qty 数量, batchs
                FROM BS_WMS_DSEND d LEFT JOIN BS_WMS_MSEND m ON m.send_code = d.send_code
                WHERE $whereField IN ($inList)";
                $sql = "SELECT * FROM OPENQUERY(SRM, '" . str_replace("'", "''", $oracleSql) . "') AS T";
                $stmt = $pdo_sqlsrv->query($sql);
                $usedDirect = true;
            } catch (Throwable $e) {
                error_log('[dispatch_kitting] 直连查询失败: ' . $e->getMessage());
                $directError = '直连查询失败，已回退存储过程';
                $stmt = null;
            }

            if ($stmt === null) {
                $sqlProc = ($viewMode === 'by_peisong')
                    ? "SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; EXEC [dbo].[自定义制单查询_P] @peisong_order = ?"
                    : "SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; EXEC [dbo].[自定义制单查询_P] @bill_code = ?";
                $stmt = $pdo_sqlsrv->prepare($sqlProc);
                $stmt->execute([$searchVal]);
            }

            while ($stmt->columnCount() === 0 && $stmt->nextRowset()) {}

            $columns = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                $columns[] = $meta['name'];
            }

            $rawResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rawResults)) {
                sendJson(false, '未检索到相关数据');
            }

            $colPeisong = $this->getExactColName($columns, ['配送单号', '配送单', 'peisong', 'ps_no']) ?: '';
            $colBill    = $this->getExactColName($columns, ['工单号', '任务号', '工单', 'bill', 'mo', 'wo']) ?: '';
            $colMatNo   = $this->getExactColName($columns, ['组件编码', '物料编码', '物料代码', '料号', 'mat_no', 'item_no']) ?: '';
            $colMatDesc = $this->getExactColName($columns, ['组件描述', '物料描述', '物料名称', '子项名称', '规格', '品名', 'mat_desc']) ?: '';
            $colQty     = $this->getExactColName($columns, ['数量', '配送数量', '实发数量', 'qty', 'send_qty']) ?: '';

            $woSet = [];
            foreach ($rawResults as $row) {
                if ($colBill && isset($row[$colBill])) {
                    $w = trim((string)$row[$colBill]);
                    if ($w !== '') $woSet[$w] = true;
                }
            }
            $woMatInfo = $this->fetchBomInfoByWos($pdo_sqlsrv, array_keys($woSet));

            $confirmedBatchMap = [];
            if ($pdo_biz) {
                try {
                    $batchRows = $pdo_biz->query("SELECT peisong_order FROM tab_delivery_batch")->fetchAll(PDO::FETCH_COLUMN, 0);
                    $confirmedBatchMap = array_fill_keys($batchRows, true);
                } catch (Throwable $e) {}
            }

            $groups = [];
            $matchedWoList = [];
            $filteredCount = 0;
            $procStats = [];

            foreach ($rawResults as $row) {
                $pVal    = $colPeisong ? trim((string)($row[$colPeisong] ?? '')) : '';
                $bVal    = $colBill    ? trim((string)($row[$colBill]    ?? '')) : '';
                $matNo   = $colMatNo   ? trim((string)($row[$colMatNo]   ?? '')) : '';
                $matDesc = $colMatDesc ? trim((string)($row[$colMatDesc] ?? '')) : '';
                if ($pVal === '') $pVal = '未知配送单';
                if ($bVal === '') $bVal = '未知工单';

                $realProc = '';
                if ($bVal !== '未知工单' && $matNo !== '' && isset($woMatInfo[$bVal][$matNo])) {
                    $info = $woMatInfo[$bVal][$matNo];
                    if ($matDesc === '' && $info['mater_desc'] !== '') $matDesc = $info['mater_desc'];
                    if ($info['has_proc']) $realProc = $info['proc_name'];
                }

                if ($realProc !== '' && !$this->isPackingProcess($realProc)) {
                    $filteredCount++;
                    $procStats[$realProc] = ($procStats[$realProc] ?? 0) + 1;
                    continue;
                }

                $matchedWoList[$bVal] = true;
                $groupKey = ($viewMode === 'by_peisong') ? $bVal : $pVal;
                if (!isset($groups[$groupKey])) {
                    $groups[$groupKey] = [
                        'group_code' => $groupKey, 'peisong_order' => $pVal, 'wo' => $bVal,
                        'is_confirmed' => isset($confirmedBatchMap[$pVal]) ? 1 : 0, 'items' => []
                    ];
                }
                $qtyVal = ($colQty && isset($row[$colQty])) ? floatval(str_replace(',', '', (string)$row[$colQty])) : 0;
                $groups[$groupKey]['items'][] = [
                    'peisong_order' => $pVal, 'wo' => $bVal,
                    'mater_no' => $matNo, 'mater_desc' => $matDesc,
                    'proc_name' => $realProc ?: '装置包装', 'send_qty' => $qtyVal
                ];
            }

            if (empty($groups)) {
                sendJson(false, '该单号下未发现属于【装置包装】工序的配送物料！', [
                    'filtered_count' => $filteredCount, 'proc_stats' => $procStats
                ]);
            }

            sendJson(true, 'success', [
                'view_mode'      => $viewMode,
                'columns'        => $columns,
                'groups'         => array_values($groups),
                'related_wos'    => array_keys($matchedWoList),
                'total_groups'   => count($groups),
                'filtered_count' => $filteredCount,
                'used_direct'    => $usedDirect,
                'direct_error'   => $directError
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '查询失败');
        }
    }

    /**
     * 2. 确认收料
     */
    public function confirmReceipt(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $peisongOrder = trim($input['peisong_order'] ?? '');
        $wo           = trim($input['wo'] ?? '');
        $items        = $input['items'] ?? [];

        if (empty($peisongOrder) || empty($items) || !is_array($items)) {   // ← 已修正 \vert{}\vert{} → ||
            sendJson(false, '参数缺失');
        }

        $pdo_biz = function_exists('getDB') ? getDB() : null;
        if (!$pdo_biz) sendJson(false, '业务数据库未连接');

        $this->ensureBizTables($pdo_biz);
        $user = function_exists('checkUser') ? checkUser() : [];
        $userName = $user['full_name'] ?? ($user['username'] ?? '系统用户');

        $validItems = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            if (trim((string)($it['mater_no'] ?? '')) === '') continue;
            $validItems[] = $it;
        }
        if (empty($validItems)) sendJson(false, '物料明细为空');

        try {
            $pdo_biz->beginTransaction();
            $stmtBatch = $pdo_biz->prepare(
                "INSERT INTO tab_delivery_batch (peisong_order, wo, confirm_user) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE confirm_user = VALUES(confirm_user), created_at = NOW()"
            );
            $stmtBatch->execute([$peisongOrder, $wo, $userName]);

            $stmtDel = $pdo_biz->prepare("DELETE FROM tab_delivery_receipt WHERE peisong_order = ?");
            $stmtDel->execute([$peisongOrder]);

            $placeholders = [];
            $params = [];
            foreach ($validItems as $it) {
                $placeholders[] = "(?, ?, ?, ?, ?, ?)";
                $params[] = $peisongOrder;
                $params[] = $wo ?: trim((string)($it['wo'] ?? ''));
                $params[] = trim((string)$it['mater_no']);
                $params[] = trim((string)($it['mater_desc'] ?? ''));
                $params[] = floatval($it['send_qty'] ?? 0);
                $params[] = $userName;
            }
            $stmtIns = $pdo_biz->prepare(
                "INSERT INTO tab_delivery_receipt (peisong_order, wo, mater_no, mater_desc, send_qty, confirm_user)
                 VALUES " . implode(',', $placeholders)
            );
            $stmtIns->execute($params);

            $pdo_biz->commit();
            AuditService::log('齐套发料', '确认收料');
            sendJson(true, '确认收料成功！共 ' . count($validItems) . ' 条');
        } catch (Throwable $e) {
            if ($pdo_biz->inTransaction()) $pdo_biz->rollBack();
            Response::exception($e, '写入数据库失败');
        }
    }

    /**
     * 3. 工单包装齐套列表 (Tab 2)
     */
    public function queryWoKitting(): void
    {
        $woQuery      = trim($_GET['wo'] ?? ($_POST['wo'] ?? ''));
        $showArchived = !empty($_GET['show_archived'] ?? ($_POST['show_archived'] ?? 0));

        $pdo_biz = function_exists('getDB') ? getDB() : null;
        $pdo_mes = function_exists('getMesDB') ? getMesDB() : null;
        if (!$pdo_biz || !$pdo_mes) sendJson(false, '数据库连接异常');   // ← 已修正 \vert{}\vert{} → ||

        $this->ensureBizTables($pdo_biz);
        $pdo_biz->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $pdo_mes->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

        try {
            $archivedRows = $pdo_biz->query(
                "SELECT wo, finished_at, remark FROM tab_work_order_material_archive WHERE is_finished = 1"
            )->fetchAll(PDO::FETCH_ASSOC);
            $archivedMap = [];
            foreach ($archivedRows as $ar) {
                $archivedMap[(string)$ar['wo']] = $ar;
            }

            if (!empty($woQuery) && isset($archivedMap[$woQuery]) && !$showArchived) {
                sendJson(true, 'success', [
                    'is_archived' => true,
                    'message'     => "工单 {$woQuery} 的装置包装工序已全部完成，物料已标记为使用消耗并已归档。",
                    'list'        => []
                ]);
            }

            $targetWos = [];
            if (!empty($woQuery)) {
                $targetWos = [$woQuery];
            } else {
                $receiptWos = $pdo_biz->query("SELECT DISTINCT wo FROM tab_delivery_receipt")
                    ->fetchAll(PDO::FETCH_COLUMN, 0);

                $seen = [];
                foreach ($receiptWos as $rw) {
                    if (empty($rw)) continue;
                    $rw = (string)$rw;
                    if ($showArchived) {
                        if (!isset($seen[$rw])) { $targetWos[] = $rw; $seen[$rw] = true; }
                    } else {
                        if (!isset($archivedMap[$rw]) && !isset($seen[$rw])) {
                            $targetWos[] = $rw;
                            $seen[$rw] = true;
                        }
                    }
                }

                if ($showArchived) {
                    foreach ($archivedMap as $awo => $info) {
                        $awo = (string)$awo;
                        if (!isset($seen[$awo])) {
                            $targetWos[] = $awo;
                            $seen[$awo] = true;
                        }
                    }
                }
            }

            if (empty($targetWos)) {
                sendJson(true, 'success', ['list' => [], 'message' => '暂无工单数据']);
            }

            $MAX_WOS = 100;
            $truncated = count($targetWos) > $MAX_WOS;
            $wosUsed = array_slice($targetWos, 0, $MAX_WOS);

            $woMeta         = $this->getWorkOrderMetaBatch($pdo_mes, $wosUsed);
            $packProgress   = $this->getPackingProgressCached($pdo_biz, $pdo_mes, $wosUsed);
            $lastConfirmMap = $this->getLastConfirmInfoBatch($pdo_biz, $wosUsed);

            $resultList = [];
            foreach ($wosUsed as $curWo) {
                $meta    = $woMeta[$curWo] ?? ['mater_code' => '', 'mater_desc' => '', 'plan_qty' => 0];
                $planQty = $meta['plan_qty'];
                $doneQty = $packProgress[$curWo] ?? 0;

                $isArchived = isset($archivedMap[$curWo]) ? 1 : 0;

                $woStatus = 'white';
                if ($planQty > 0 && $doneQty >= $planQty) $woStatus = 'green';
                elseif ($doneQty > 0) $woStatus = 'yellow';
                if ($isArchived) $woStatus = 'green';

                $progressPct = $planQty > 0 ? round($doneQty / $planQty * 100, 1) : 0;
                $lastInfo    = $lastConfirmMap[$curWo] ?? ['confirm_user' => '', 'confirm_time' => ''];

                $resultList[] = [
                    'wo'                => $curWo,
                    'mater_code'        => $meta['mater_code'],
                    'mater_desc'        => $meta['mater_desc'],
                    'plan_qty'          => $planQty,
                    'done_qty'          => $doneQty,
                    'progress_pct'      => $progressPct,
                    'wo_status'         => $woStatus,
                    'is_archived'       => $isArchived,
                    'archived_at'       => $isArchived ? ($archivedMap[$curWo]['finished_at'] ?? '') : '',
                    'archive_note'      => $isArchived ? ($archivedMap[$curWo]['remark'] ?? '') : '',
                    'last_confirm_user' => $lastInfo['confirm_user'],
                    'last_confirm_time' => $lastInfo['confirm_time']
                ];
            }

            usort($resultList, function($a, $b) {
                $ta = (string)($a['last_confirm_time'] ?? '');
                $tb = (string)($b['last_confirm_time'] ?? '');
                $hasA = ($ta !== '');
                $hasB = ($tb !== '');

                if ($hasA && $hasB) {
                    $cmp = strcmp($tb, $ta);
                    if ($cmp !== 0) return $cmp;
                    return strcmp($b['wo'], $a['wo']);
                }
                if ($hasA && !$hasB) return -1;
                if (!$hasA && $hasB) return 1;
                return strcmp($b['wo'], $a['wo']);
            });

            sendJson(true, 'success', [
                'list'      => $resultList,
                'truncated' => $truncated,
                'wos_count' => count($wosUsed)
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '查询失败');
        }
    }

    /**
     * 4. 按工单查物料明细
     */
    public function queryWoMaterials(): void
    {
        $wo = trim($_GET['wo'] ?? ($_POST['wo'] ?? ''));
        if ($wo === '') sendJson(false, '缺少工单号');

        $pdo_sqlsrv = function_exists('getSqlSrvDB') ? getSqlSrvDB() : null;
        $pdo_biz    = function_exists('getDB') ? getDB() : null;
        if (!$pdo_sqlsrv || !$pdo_biz) sendJson(false, '数据库连接异常');   // ← 已修正 \vert{}\vert{} → ||

        $pdo_biz->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

        try {
            $woBomInfo = $this->fetchBomInfoByWos($pdo_sqlsrv, [$wo]);
            $matMap    = $woBomInfo[$wo] ?? [];

            $confirmedMap = [];
            $stmtRec = $pdo_biz->prepare(
                "SELECT mater_no, SUM(send_qty) AS total_confirmed
                 FROM tab_delivery_receipt WHERE wo = :wo GROUP BY mater_no"
            );
            $stmtRec->execute([':wo' => $wo]);
            foreach ($stmtRec->fetchAll(PDO::FETCH_ASSOC) as $rr) {
                $confirmedMap[$rr['mater_no']] = floatval($rr['total_confirmed']);
            }

            $materials = [];
            $greenCount = 0;
            $yellowCount = 0;
            $whiteCount = 0;
            foreach ($matMap as $matNo => $info) {
                if (!$info['has_proc'] || !$this->isPackingProcess($info['proc_name'])) continue;

                $reqQty    = $info['req_qty'];
                $confQty   = $confirmedMap[$matNo] ?? 0.0;
                $targetQty = ceil($reqQty);
                $status    = 'white';
                if (($confQty >= $targetQty || $confQty >= $reqQty) && $reqQty > 0) {   // ← 已修正 \vert{}\vert{} → ||
                    $status = 'green';
                    $greenCount++;
                } elseif ($confQty > 0) {
                    $status = 'yellow';
                    $yellowCount++;
                } else {
                    $status = 'white';
                    $whiteCount++;
                }
                $materials[] = [
                    'mater_no'   => $matNo,
                    'mater_desc' => $info['mater_desc'],
                    'req_qty'    => $reqQty,
                    'conf_qty'   => $confQty,
                    'status'     => $status
                ];
            }

            sendJson(true, 'success', [
                'wo'             => $wo,
                'total_kinds'    => count($materials),
                'green_count'    => $greenCount,
                'yellow_count'   => $yellowCount,
                'white_count'    => $whiteCount,
                'is_full_kitted' => (count($materials) > 0 && $greenCount === count($materials)),
                'materials'      => $materials
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '加载物料明细失败');
        }
    }
}