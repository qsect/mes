<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PDO;
use PDOException;
use Throwable;

class MacQueryController
{
    private PDO $db;
    private array $currentUser;
    private bool $isAdmin;
    private array $filterPrefixes;

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

        $this->filterPrefixes = [
            '00-0E-EA',
            '0.14.234',
            '类型：D30',
            '类型：F30',
            '名       称：保护测控单元'
        ];
    }

    private function getMesConnection(): ?PDO
    {
        try {
            // 严格从 .env 读取 MES 数据库配置
            // ★ 凭据一律取自 .env（无源码兜底），配置缺失直接拒绝连接
            $cfg = \App\Core\ExternalDb::mesConfig();
            if ($cfg === null) return null;
            [$mes_host, $mes_port, $mes_db, $mes_user, $mes_pass] = $cfg;

            return new PDO("mysql:host={$mes_host};port={$mes_port};dbname={$mes_db};charset=utf8mb4", $mes_user, $mes_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 6
            ]);
        } catch (PDOException $e) {
            return null;
        }
    }

    public function query(): void
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_REQUEST;

        $snKeyword   = trim((string)($input['sn'] ?? ''));
        $taskKeyword = trim((string)($input['task'] ?? ''));
        $force       = !empty($input['force']);

        if (empty($snKeyword) && empty($taskKeyword)) {
            Response::error('请至少输入【装置 SN】或【工单号】进行查询');
            return;
        }

        // 本地缓存机制 (当仅通过单一 SN 查询且未强制刷新时生效)
        $cacheDir = __DIR__ . '/../../storage/cache_qr/';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $cacheKey  = md5('sn_' . $snKeyword . '_task_' . $taskKeyword);
        $cacheFile = $cacheDir . $cacheKey . '.json';

        if (!$force && !empty($snKeyword) && empty($taskKeyword) && file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
            $cachedData = json_decode((string)file_get_contents($cacheFile), true);
            if ($cachedData) {
                $cachedData['from_cache'] = true;
                Response::success('success', $cachedData);
                return;
            }
        }

        $pdo = $this->getMesConnection();
        if (!$pdo) {
            Response::error('MES 生产数据源连接失败，请检查 .env 配置');
            return;
        }

        try {
            $where = ["1=1"];
            $params = [];

            if (!empty($snKeyword)) {
                if (strlen($snKeyword) > 15) {
                    $where[] = "sn = ?";
                    $params[] = $snKeyword;
                } else {
                    $where[] = "sn LIKE ?";
                    $params[] = $snKeyword . '%';
                }
            }

            if (!empty($taskKeyword)) {
                $where[] = "task_no = ?";
                $params[] = $taskKeyword;
            }

            $whereSql = implode(' AND ', $where);
            $limit = !empty($taskKeyword) ? 500 : 80;

            $sql = "SELECT sn, qr_info, task_no, job_no, gx_id, line_id, run_time 
                    FROM tab_log_qr 
                    WHERE {$whereSql} 
                    ORDER BY run_time DESC 
                    LIMIT {$limit}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 过滤匹配特征前缀
            $filtered = [];
            foreach ($rows as $row) {
                $info = trim((string)($row['qr_info'] ?? ''));
                $matched = false;
                foreach ($this->filterPrefixes as $prefix) {
                    if (str_starts_with($info, $prefix)) {
                        $matched = true;
                        break;
                    }
                }
                if ($matched) {
                    $filtered[] = $row;
                }
            }

            $resultPayload = [
                'list'       => $filtered,
                'raw_count'  => count($rows),
                'sn'         => $snKeyword,
                'task'       => $taskKeyword,
                'count'      => count($filtered),
                'from_cache' => false
            ];

            if (!empty($snKeyword) && empty($taskKeyword)) {
                @file_put_contents($cacheFile, json_encode($resultPayload, JSON_UNESCAPED_UNICODE));
            }

            Response::success('success', $resultPayload);

        } catch (PDOException $e) {
            Response::exception($e, 'MES 数据库查询失败');
        }
    }

    /**
     * 导出查询结果为 Excel（服务器已安装 PhpSpreadsheet 时），否则回退 CSV。
     */
    public function export(): void
    {
        $snKeyword   = trim((string)($_GET['sn'] ?? ''));
        $taskKeyword = trim((string)($_GET['task'] ?? ''));

        if (empty($snKeyword) && empty($taskKeyword)) {
            Response::error('缺少导出条件，请先执行查询');
            return;
        }

        $pdo = $this->getMesConnection();
        if (!$pdo) {
            Response::error('MES 生产数据源连接失败，请检查 .env 配置');
            return;
        }

        try {
            $where = ["1=1"];
            $params = [];

            if (!empty($snKeyword)) {
                if (strlen($snKeyword) > 15) {
                    $where[] = "sn = ?";
                    $params[] = $snKeyword;
                } else {
                    $where[] = "sn LIKE ?";
                    $params[] = $snKeyword . '%';
                }
            }
            if (!empty($taskKeyword)) {
                $where[] = "task_no = ?";
                $params[] = $taskKeyword;
            }

            $whereSql = implode(' AND ', $where);
            $limit = !empty($taskKeyword) ? 500 : 80;

            $sql = "SELECT sn, qr_info, task_no, job_no, gx_id, line_id, run_time
                    FROM tab_log_qr
                    WHERE {$whereSql}
                    ORDER BY run_time DESC
                    LIMIT {$limit}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $filtered = [];
            foreach ($rows as $row) {
                $info = trim((string)($row['qr_info'] ?? ''));
                foreach ($this->filterPrefixes as $prefix) {
                    if (str_starts_with($info, $prefix)) {
                        $filtered[] = $row;
                        break;
                    }
                }
            }

            $headers = ['SN', '二维码信息', '工单号', '操作时间'];

            // 优先导出 Excel
            if (class_exists(Spreadsheet::class)) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('MAC_ID查询结果');
                $sheet->fromArray($headers, null, 'A1');

                $line = 2;
                foreach ($filtered as $r) {
                    $sheet->setCellValueExplicit('A' . $line, (string)$r['sn'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $line, (string)$r['qr_info'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $line, (string)$r['task_no'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $line, (string)$r['run_time'], DataType::TYPE_STRING);
                    $line++;
                }

                $sheet->getStyle('A1:D1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:D1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
                foreach (range('A', 'D') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $filename = 'MAC_ID_Export_' . date('Ymd_His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                header('Cache-Control: max-age=0');
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="MAC_ID_Export_' . date('Ymd_His') . '.csv"');
            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, array_map('excelSafe', $headers));
            foreach ($filtered as $r) {
                fputcsv($output, array_map('excelSafe', [
                    "\t" . $r['sn'],
                    $r['qr_info'],
                    "\t" . $r['task_no'],
                    $r['run_time']
                ]));
            }
            fclose($output);
            exit;
        } catch (Throwable $e) {
            Response::exception($e, '导出失败');
        }
    }
}