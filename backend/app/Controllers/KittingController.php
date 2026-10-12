<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use PDO;
use Throwable;

class KittingController
{
    private PDO $db;
    private array $currentUser;

    public function __construct()
    {
        $this->db = Database::getConnection('default');

        // ★ 安全修复：会话缺失时不再自动以数据库中的管理员身份登录，必须重新登录
        if (empty($_SESSION['user'])) {
            Response::error('登录已失效，请重新登录', ['need_login' => true], 401);
            exit;
        }

        $this->currentUser = $_SESSION['user'];
    }

    private function getErpSqlSrvConnection(): ?PDO
    {
        try {
            // 从 .env 读取 SQL Server ERP 配置
            // ★ 凭据一律取自 .env（无源码兜底），配置缺失直接拒绝连接
            $cfg = \App\Core\ExternalDb::sqlsrvConfig();
            if ($cfg === null) return null;
            [$sqlsrv_host, $sqlsrv_port, $sqlsrv_db, $sqlsrv_user, $sqlsrv_pass] = $cfg;

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 15,
            ];
            $dsn = "dblib:host={$sqlsrv_host}:{$sqlsrv_port};dbname={$sqlsrv_db};charset=UTF-8";
            $pdo = new PDO($dsn, $sqlsrv_user, $sqlsrv_pass, $options);
            $pdo->exec("SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; SET ANSI_PADDING ON; SET CONCAT_NULL_YIELDS_NULL ON; SET QUOTED_IDENTIFIER ON;");
            return $pdo;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getExactColName(array $columns, array $targetNames): string|false
    {
        foreach ($targetNames as $target) {
            foreach ($columns as $col) {
                if (trim((string)$col) === $target) {
                    return $col;
                }
            }
        }
        foreach ($targetNames as $target) {
            foreach ($columns as $col) {
                if (str_contains((string)$col, $target)) {
                    return $col;
                }
            }
        }
        return false;
    }

    private function resolveProcessName(string $procName, string $procNo, string $procShort): string
    {
        $name = trim($procName);
        if (!empty($name) && $name !== '-' && !preg_match('/^[-0-9]+$/', $name)) {
            return $name;
        }

        $no = trim($procNo);
        if (str_contains($no, '30')) return '装置装配';
        if (str_contains($no, '40')) return '装置调试';
        if (str_contains($no, '60')) return '装置包装';

        $short = trim($procShort);
        if (!empty($short)) {
            if (str_contains($short, '装配')) return '装置装配';
            if (str_contains($short, '包装')) return '装置包装';
            if (str_contains($short, '调试')) return '装置调试';
            if ($short === '装置') return '装置装配';
            return $short;
        }

        return !empty($name) ? $name : '装置装配';
    }

    public function query(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;

        $workOrdersInput = trim((string)($input['work_orders'] ?? ''));
        if (empty($workOrdersInput)) {
            Response::error('请输入任务号/工单号！');
            return;
        }

        $pdo_sqlsrv = $this->getErpSqlSrvConnection();
        if (!$pdo_sqlsrv) {
            Response::error('ERP 数据库未连接，请检查环境 SQL Server 配置。');
            return;
        }

        $lines = preg_split('/[\r\n,\s]+/', $workOrdersInput, -1, PREG_SPLIT_NO_EMPTY);
        $cleanOrders = array_unique(array_map('trim', $lines ?: []));

        if (empty($cleanOrders)) {
            Response::error('请输入有效的任务号！');
            return;
        }

        $workOrdersParam = implode(',', $cleanOrders);

        try {
            $pdo_sqlsrv->exec("SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; SET ANSI_PADDING ON; SET QUOTED_IDENTIFIER ON;");

            $sql = "SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; EXEC [dbo].[自定义ERP组件_P] @WorkOrders = ?";
            $stmt = $pdo_sqlsrv->prepare($sql);
            $stmt->execute([$workOrdersParam]);

            while ($stmt->columnCount() === 0 && $stmt->nextRowset()) {}

            $columns = [];
            $colCount = $stmt->columnCount();
            for ($i = 0; $i < $colCount; $i++) {
                $meta = $stmt->getColumnMeta($i);
                $columns[] = $meta['name'];
            }

            $rawResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rawResults)) {
                Response::error('未查询到相关组件数据，请核对任务号是否正确。');
                return;
            }

            $colWo        = $this->getExactColName($columns, ['工单号', '任务号', '工单']) ?: ($columns[0] ?? '');
            $colProcName  = $this->getExactColName($columns, ['工序名称']);
            $colProcNo    = $this->getExactColName($columns, ['工序号']);
            $colProcShort = $this->getExactColName($columns, ['工序名']);
            $colReqQty    = $this->getExactColName($columns, ['需求量', '需求数量']);
            $colSendQty   = $this->getExactColName($columns, ['已发料', '已发料数量', '实发数量', '完成量']);
            $colStatus    = $this->getExactColName($columns, ['状态']);

            $results = [];
            $processList = [];
            $summaryData = [];

            foreach ($rawResults as $row) {
                $procNoVal = trim((string)($colProcNo ? ($row[$colProcNo] ?? '') : ''));
                if ($procNoVal !== '') {
                    if (str_contains($procNoVal, '-') || (float)$procNoVal < 0) {
                        continue;
                    }
                }

                $wo = trim((string)($row[$colWo] ?? '通用工单'));

                $proc = $this->resolveProcessName(
                    (string)($colProcName ? ($row[$colProcName] ?? '') : ''),
                    $procNoVal,
                    (string)($colProcShort ? ($row[$colProcShort] ?? '') : '')
                );

                $reqStr  = $colReqQty ? trim((string)($row[$colReqQty] ?? '0')) : '0';
                $sendStr = $colSendQty ? trim((string)($row[$colSendQty] ?? '0')) : '0';
                $reqQty  = (float)str_replace(',', '', $reqStr);
                $sendQty = (float)str_replace(',', '', $sendStr);

                $targetQty = ceil($reqQty);
                $rowState = 'unissued';
                if (($sendQty >= $targetQty || $sendQty >= $reqQty) && $reqQty > 0) {
                    $rowState = 'kitted';
                } elseif ($sendQty > 0) {
                    $rowState = 'unkitted';
                } else {
                    $rowState = 'unissued';
                }

                $diffQty = 0.0;
                if ($rowState === 'unkitted') {
                    $diffQty = round($targetQty - $sendQty, 4);
                    if ($diffQty <= 0) {
                        $diffQty = round($reqQty - $sendQty, 4);
                    }
                }

                $row['_calc_state']   = $rowState;
                $row['_calc_process'] = $proc;
                $row['_calc_wo']      = $wo;
                $row['_diff_qty']     = $diffQty > 0 ? $diffQty : 0;
                $results[] = $row;

                $processList[$proc] = true;

                $groupKey = $wo . '___' . $proc;
                if (!isset($summaryData[$groupKey])) {
                    $summaryData[$groupKey] = [
                        'wo'        => $wo,
                        'process'   => $proc,
                        'total_mat' => 0,
                        'kitted'    => 0,
                        'unkitted'  => 0,
                        'unissued'  => 0,
                    ];
                }
                $summaryData[$groupKey]['total_mat']++;
                $summaryData[$groupKey][$rowState]++;
            }

            $summaryList = [];
            foreach ($summaryData as $item) {
                $rate = $item['total_mat'] > 0 ? round(($item['kitted'] / $item['total_mat']) * 100, 1) : 0;
                $item['rate'] = $rate;
                if ($item['kitted'] === $item['total_mat'] && $item['total_mat'] > 0) {
                    $item['state_badge'] = 'kitted';
                } elseif ($item['unissued'] === $item['total_mat']) {
                    $item['state_badge'] = 'unissued';
                } else {
                    $item['state_badge'] = 'unkitted';
                }
                $summaryList[] = $item;
            }

            Response::success('success', [
                'columns'      => $columns,
                'materials'    => $results,
                'summary_list' => $summaryList,
                'processes'    => array_keys($processList),
                'total_count'  => count($results)
            ]);
        } catch (Throwable $e) {
            Response::exception($e, '查询组件数据失败');
        }
    }
}