<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use PDO;
use Exception;
use Throwable;
use ZipArchive;
use Imagick;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\VerticalJc;

class ToolsController
{
    private PDO $db;
    private array $currentUser;

    public function __construct()
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $this->db = Database::getConnection();

        if (empty($_SESSION['user'])) {
            Response::error('请先登录', [], 401);
            exit;
        }
        $this->currentUser = $_SESSION['user'];
    }

    private function queryFtuRecord(string $code): array
    {
        $code = trim($code);
        if ($code === '') return ['', ''];
        try {
            $stmt = $this->db->prepare("SELECT `ftuid`, `qr_info` FROM `ftuid_records` WHERE `qr_code` = ? LIMIT 1");
            $stmt->execute([$code]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) {
                return [$res['ftuid'] ?? '', $res['qr_info'] ?? ''];
            }
            return ['未找到', ''];
        } catch (Throwable $e) {
            return ['查询失败', ''];
        }
    }

    private function createTempQrImage(string $text)
    {
        $text = trim($text);
        if ($text === '') return false;

        $tempFile = tempnam(sys_get_temp_dir(), 'qr_') . '.png';

        if (class_exists('\Endroid\QrCode\QrCode')) {
            $qrCode = new \Endroid\QrCode\QrCode($text);
            $qrCode->setSize(260);
            $qrCode->setMargin(4);
            if (class_exists('\Endroid\QrCode\Writer\PngWriter')) {
                $writer = new \Endroid\QrCode\Writer\PngWriter();
                $writer->write($qrCode)->saveToFile($tempFile);
            } else {
                file_put_contents($tempFile, $qrCode->writeString());
            }
            return $tempFile;
        }

        $apiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=" . urlencode($text);
        $imgData = @file_get_contents($apiUrl);
        if ($imgData) {
            file_put_contents($tempFile, $imgData);
            return $tempFile;
        }
        return false;
    }

    private function formatExcelProdDate($val): string
    {
        if (is_numeric($val)) {
            try {
                $dateTime = ExcelDate::excelToDateTimeObject($val);
                return $dateTime->format('Y年m月d日');
            } catch (Throwable $e) {
                return (string)$val;
            }
        }
        return trim((string)$val);
    }

    private function extractPdfPagesToImages(string $pdfPath): array
    {
        $imageFiles = [];
        $tmpDir = sys_get_temp_dir();

        if (extension_loaded('imagick')) {
            try {
                $imagick = new Imagick();
                $imagick->setResolution(200, 200);
                $imagick->readImage($pdfPath);
                foreach ($imagick as $i => $page) {
                    $page->setImageFormat('png');
                    $pagePath = $tmpDir . '/pdf_page_' . uniqid() . "_{$i}.png";
                    $page->writeImage($pagePath);
                    $imageFiles[] = $pagePath;
                }
                $imagick->clear();
                $imagick->destroy();
                return $imageFiles;
            } catch (Throwable $e) {}
        }

        $prefix = $tmpDir . '/pdf_render_' . uniqid();
        $cmd = "pdftoppm -png -r 200 " . escapeshellarg($pdfPath) . " " . escapeshellarg($prefix);
        exec($cmd, $out, $ret);

        if ($ret === 0) {
            $matches = glob($prefix . "-*.png");
            natsort($matches);
            return array_values($matches);
        }

        return $imageFiles;
    }

    public function downloadQrcodeTpl(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('模板');
        $headers = ['类型：', '厂商：', '型号：', 'ID：', '硬件版本：', '生产日期：'];
        $sheet->fromArray($headers, NULL, 'A1');
        $sheet->fromArray([
            ['柱上断路器', '积成电子', 'IES-600', 'ID202409001', 'V1.0.0', '2024年09月01日']
        ], NULL, 'A2');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="二维码生成模板.xlsx"');
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    }

    public function beibianPdf2wordConvert(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['pdf_file'])) {
            http_response_code(400);
            echo "请通过 POST 上传 PDF 文件";
            exit;
        }

        $file = $_FILES['pdf_file'];
        $imageFiles = [];

        try {
            $imageFiles = $this->extractPdfPagesToImages($file['tmp_name']);
            $totalPages = count($imageFiles);
            if ($totalPages === 0) throw new Exception("无法解析 PDF 内容或未安装 imagick/pdftoppm");

            $phpWord = new PhpWord();
            $section = $phpWord->addSection([
                'orientation'  => 'landscape',
                'pageSizeW'    => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(29.7),
                'pageSizeH'    => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(21.0),
                'marginTop'    => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.0),
                'marginBottom' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.0),
                'marginLeft'   => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.0),
                'marginRight'  => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.0),
            ]);

            $colWidthTwip = \PhpOffice\PhpWord\Shared\Converter::cmToTwip(13.85);
            $tableStyle = ['borderSize' => 0, 'borderColor' => 'FFFFFF', 'cellMargin' => 0, 'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER];
            $cellAlign = ['valign' => VerticalJc::CENTER];

            for ($i = 0; $i < $totalPages; $i += 4) {
                if ($i > 0) $section->addPageBreak();

                $table = $section->addTable($tableStyle);
                $table->addRow(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(9.2));
                for ($c = 0; $c < 2; $c++) {
                    $idx = $i + $c;
                    $cell = $table->addCell($colWidthTwip, $cellAlign);
                    if ($idx < $totalPages && file_exists($imageFiles[$idx])) {
                        $cell->addImage($imageFiles[$idx], ['width' => 380, 'alignment' => Jc::CENTER]);
                    }
                }

                $table->addRow(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(9.2));
                for ($c = 2; $c < 4; $c++) {
                    $idx = $i + $c;
                    $cell = $table->addCell($colWidthTwip, $cellAlign);
                    if ($idx < $totalPages && file_exists($imageFiles[$idx])) {
                        $cell->addImage($imageFiles[$idx], ['width' => 380, 'alignment' => Jc::CENTER]);
                    }
                }
            }

            $tempDocx = tempnam(sys_get_temp_dir(), 'word_bb_') . '.docx';
            \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($tempDocx);
            foreach ($imageFiles as $img) @unlink($img);

            AuditService::log('实用工具箱', '北变标签转换');

            $outName = '北变标签汇总_' . date('Ymd_His') . '.docx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="' . rawurlencode($outName) . '"');
            header('Content-Length: ' . filesize($tempDocx));
            header('Cache-Control: no-cache, must-revalidate');
            readfile($tempDocx);
            @unlink($tempDocx);
            exit;
        } catch (Throwable $e) {
            foreach ($imageFiles as $img) @unlink($img);
            http_response_code(500);
            error_log('[工具箱] 北变标签转换失败: ' . $e->getMessage());
            echo "转换失败，请稍后重试或联系管理员";
            exit;
        }
    }

    public function batchQrcodeGenerate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['excel_file'])) {
            http_response_code(400);
            echo "请通过 POST 上传 Excel 文件";
            exit;
        }

        $file = $_FILES['excel_file'];
        $tempPdfFiles = [];
        $tempImgFiles = [];

        try {
            $spreadsheet = IOFactory::load($file['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
            if (empty($rows)) throw new Exception("Excel 内容为空");

            $headerRow = array_shift($rows);
            $colMap = [];
            foreach ($headerRow as $k => $v) $colMap[trim((string)$v)] = $k;

            $requiredCols = ['类型：', '厂商：', '型号：', 'ID：', '硬件版本：', '生产日期：'];
            foreach ($requiredCols as $c) {
                if (!isset($colMap[$c])) throw new Exception("Excel 中缺少必需的列：【{$c}】");
            }

            $zipFile = tempnam(sys_get_temp_dir(), 'qr_zip_') . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception("无法创建 ZIP 压缩文件");
            }

            $count = 0;
            foreach ($rows as $r) {
                $idVal = trim((string)($r[$colMap['ID：']] ?? ''));
                if ($idVal === '') continue;

                $typeVal  = trim((string)($r[$colMap['类型：']] ?? ''));
                $mfgVal   = trim((string)($r[$colMap['厂商：']] ?? ''));
                $model    = trim((string)($r[$colMap['型号：']] ?? ''));
                $hwVer    = trim((string)($r[$colMap['硬件版本：']] ?? ''));
                $prodDate = $this->formatExcelProdDate($r[$colMap['生产日期：']] ?? '');

                $qrContent = "类型：{$typeVal}，厂商：{$mfgVal}，型号：{$model}，ID：{$idVal}，硬件版本：{$hwVer}，生产日期：{$prodDate}";
                $qrImgPath = $this->createTempQrImage($qrContent);
                if (!$qrImgPath) continue;

                $tempImgFiles[] = $qrImgPath;

                if (!class_exists('\FPDF')) {
                    if (file_exists(__DIR__ . '/../../vendor/setasign/fpdf/fpdf.php')) {
                        require_once __DIR__ . '/../../vendor/setasign/fpdf/fpdf.php';
                    }
                }

                $pdfDoc = new \FPDF('P', 'mm', 'A4');
                $pdfDoc->AddPage();
                $x = (210 - 80) / 2;
                $y = (297 - 80) / 2;
                $pdfDoc->Image($qrImgPath, $x, $y, 80, 80);

                $safeId = preg_replace('/[^\w\.\-_]/u', '', $idVal) ?: 'qrcode_' . ($count + 1);
                $tmpPdf = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';
                $pdfDoc->Output('F', $tmpPdf);
                $tempPdfFiles[] = $tmpPdf;

                $zip->addFile($tmpPdf, "{$safeId}.pdf");
                $count++;
            }

            $zip->close();

            foreach ($tempImgFiles as $f) @unlink($f);
            foreach ($tempPdfFiles as $f) @unlink($f);

            if ($count === 0) {
                @unlink($zipFile);
                throw new Exception("Excel 中未提取到有效的二维码数据行");
            }

            AuditService::log('实用工具箱', "批量生成二维码 {$count} 个");

            $outName = '二维码批量打包_' . date('Ymd_His') . '.zip';
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . rawurlencode($outName) . '"');
            header('Content-Length: ' . filesize($zipFile));
            header('Cache-Control: no-cache, must-revalidate');
            readfile($zipFile);
            @unlink($zipFile);
            exit;

        } catch (Throwable $e) {
            foreach ($tempImgFiles as $f) @unlink($f);
            foreach ($tempPdfFiles as $f) @unlink($f);
            if (isset($zipFile) && file_exists($zipFile)) @unlink($zipFile);
            http_response_code(500);
            error_log('[工具箱] 文件生成失败: ' . $e->getMessage());
            echo "生成失败，请稍后重试或联系管理员";
            exit;
        }
    }

    public function hebeiPackingConvert(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['excel_file'])) {
            http_response_code(400);
            echo "请通过 POST 上传 Excel 文件";
            exit;
        }

        $file = $_FILES['excel_file'];
        $tempQrFiles = [];

        try {
            $spreadsheet = IOFactory::load($file['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
            if (empty($rows)) throw new Exception("Excel 内容为空");

            $headerRow = array_shift($rows);
            $colMap = [];
            foreach ($headerRow as $k => $v) $colMap[trim((string)$v)] = $k;

            $requiredCols = ['需求号', '项目号', '合同号', '国网订单号', '项目名称', '序列号', '电子标签实物ID号', '开关编号', 'FTU ID号', '物料描述'];
            foreach ($requiredCols as $c) {
                if (!isset($colMap[$c])) throw new Exception("Excel 缺少必需的列：【{$c}】");
            }

            $dataList = [];
            foreach ($rows as $r) {
                $demandNo = trim((string)($r[$colMap['需求号']] ?? ''));
                if ($demandNo === '') continue;
                $dataList[] = [
                    '需求号'         => $demandNo,
                    '项目号'         => trim((string)($r[$colMap['项目号']] ?? '')),
                    '合同号'         => trim((string)($r[$colMap['合同号']] ?? '')),
                    '国网订单号'      => trim((string)($r[$colMap['国网订单号']] ?? '')),
                    '项目名称'       => trim((string)($r[$colMap['项目名称']] ?? '')),
                    '序列号'         => trim((string)($r[$colMap['序列号']] ?? '')),
                    '电子标签实物ID号' => trim((string)($r[$colMap['电子标签实物ID号']] ?? '')),
                    '开关编号'       => trim((string)($r[$colMap['开关编号']] ?? '')),
                    'FTU ID号'      => trim((string)($r[$colMap['FTU ID号']] ?? '')),
                    '物料描述'       => trim((string)($r[$colMap['物料描述']] ?? ''))
                ];
            }

            $demandMaxSerial = [];
            $demandGuowangMap = [];
            foreach ($dataList as $row) {
                $d = $row['需求号'];
                $gw = $row['国网订单号'];
                if ($gw !== '' && !isset($demandGuowangMap[$d])) $demandGuowangMap[$d] = $gw;
                $snNum = intval($row['序列号']);
                if (!isset($demandMaxSerial[$d]) || $snNum > $demandMaxSerial[$d]) $demandMaxSerial[$d] = $snNum;
            }

            $phpWord = new PhpWord();
            $phpWord->setDefaultFontName('宋体');
            $phpWord->setDefaultFontSize(11);

            $section = $phpWord->addSection([
                'marginTop'    => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.5),
                'marginBottom' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.0),
                'marginLeft'   => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.5),
                'marginRight'  => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.5)
            ]);

            $tableStyle = ['borderSize' => 6, 'borderColor' => '000000', 'cellMargin' => 80];
            $cellCenterAlign = ['valign' => VerticalJc::CENTER];

            foreach ($dataList as $idx => $row) {
                $dNo = $row['需求号'];
                $gwOrder = $row['国网订单号'] ?: ($demandGuowangMap[$dNo] ?? '');
                $snNum = intval($row['序列号']);
                $totalNum = $demandMaxSerial[$dNo] ?? $snNum;

                if ($idx % 2 === 0 && $idx !== 0) $section->addPageBreak();
                if ($idx % 2 === 1) $section->addTextBreak(2);

                $section->addText("装箱清单", ['name' => '黑体', 'size' => 18, 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 60]);
                $section->addText($dNo, ['name' => '宋体', 'size' => 11], ['alignment' => Jc::RIGHT, 'spaceAfter' => 60]);

                list($ftuSn, $ftuQr) = $this->queryFtuRecord($row['FTU ID号']);

                $infoTable = $section->addTable($tableStyle);
                $fields = [
                    ["供应商", "积成电子股份有限公司"],
                    ["国网订单号", $gwOrder],
                    ["项目名称", $row['项目名称']],
                    ["物料描述", $row['物料描述']],
                    ["一次设备实物资产ID", $row['电子标签实物ID号']],
                    ["二次ID", $row['FTU ID号']],
                    ["FTU 出厂编号", $ftuSn],
                    ["项目号", $row['项目号']]
                ];

                foreach ($fields as $f) {
                    $infoTable->addRow(320);
                    $infoTable->addCell(2600, $cellCenterAlign)->addText($f[0], ['name' => '宋体', 'size' => 11, 'bold' => true]);
                    $infoTable->addCell(6400, $cellCenterAlign)->addText($f[1], ['name' => '宋体', 'size' => 11, 'bold' => false]);
                }

                $section->addTextBreak(1);

                $qrTable = $section->addTable($tableStyle);
                $qrTable->addRow(300);
                $qrTable->addCell(4500, $cellCenterAlign)->addText("断路器实物ID二维码", ['name' => '宋体', 'size' => 11, 'bold' => true], ['alignment' => Jc::CENTER]);
                $qrTable->addCell(4500, $cellCenterAlign)->addText("FTU实物ID二维码", ['name' => '宋体', 'size' => 11, 'bold' => true], ['alignment' => Jc::CENTER]);

                $qrTable->addRow(1600);
                $qrCell1 = $qrTable->addCell(4500, $cellCenterAlign);
                $qf1 = $this->createTempQrImage($row['电子标签实物ID号']);
                if ($qf1) { $tempQrFiles[] = $qf1; $qrCell1->addImage($qf1, ['width' => 90, 'height' => 90, 'alignment' => Jc::CENTER]); }
                else { $qrCell1->addText("无", ['name' => '宋体', 'size' => 10], ['alignment' => Jc::CENTER]); }

                $qrCell2 = $qrTable->addCell(4500, $cellCenterAlign);
                $qf2 = $this->createTempQrImage($ftuQr);
                if ($qf2) { $tempQrFiles[] = $qf2; $qrCell2->addImage($qf2, ['width' => 90, 'height' => 90, 'alignment' => Jc::CENTER]); }
                else { $qrCell2->addText("无", ['name' => '宋体', 'size' => 10], ['alignment' => Jc::CENTER]); }

                $section->addText("第{$snNum}台/共{$totalNum}台", ['name' => '宋体', 'size' => 12], ['alignment' => Jc::RIGHT, 'spaceBefore' => 80]);
            }

            $tempDocx = tempnam(sys_get_temp_dir(), 'word_hb_') . '.docx';
            \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($tempDocx);
            foreach ($tempQrFiles as $f) @unlink($f);

            AuditService::log('实用工具箱', '生成河北装箱清单');

            $outName = '河北装箱清单_' . date('Ymd_His') . '.docx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="' . rawurlencode($outName) . '"');
            header('Content-Length: ' . filesize($tempDocx));
            header('Cache-Control: no-cache, must-revalidate');
            readfile($tempDocx);
            @unlink($tempDocx);
            exit;
        } catch (Throwable $e) {
            foreach ($tempQrFiles as $f) @unlink($f);
            http_response_code(500);
            error_log('[工具箱] 文件生成失败: ' . $e->getMessage());
            echo "生成失败，请稍后重试或联系管理员";
            exit;
        }
    }

    public function henanPackingConvert(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['excel_file'])) {
            http_response_code(400);
            echo "请通过 POST 上传 Excel 文件";
            exit;
        }

        $file = $_FILES['excel_file'];
        $tempQrFiles = [];

        try {
            $spreadsheet = IOFactory::load($file['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
            if (empty($rows)) throw new Exception("Excel 内容为空");

            $headerRow = array_shift($rows);
            $colMap = [];
            foreach ($headerRow as $k => $v) $colMap[trim((string)$v)] = $k;

            $requiredCols = ['中标批次', '项目名称', 'IP地址', '设备名称', '设备类型', '型号', '中标厂家', '生产厂家', '铭牌ID', '供货订单号', '技术ID'];
            foreach ($requiredCols as $c) {
                if (!isset($colMap[$c])) throw new Exception("Excel 缺少必需的列：【{$c}】");
            }

            $dataList = [];
            foreach ($rows as $r) {
                $mingpai = trim((string)($r[$colMap['铭牌ID']] ?? ''));
                if ($mingpai === '') continue;

                list($sn, $qr) = $this->queryFtuRecord($mingpai);

                $dataList[] = [
                    '中标批次'   => trim((string)($r[$colMap['中标批次']] ?? '')),
                    '项目名称'   => trim((string)($r[$colMap['项目名称']] ?? '')),
                    'IP地址'     => trim((string)($r[$colMap['IP地址']] ?? '')),
                    '设备名称'   => trim((string)($r[$colMap['设备名称']] ?? '')),
                    '设备类型'   => trim((string)($r[$colMap['设备类型']] ?? '')),
                    '型号'       => trim((string)($r[$colMap['型号']] ?? '')),
                    '中标厂家'   => trim((string)($r[$colMap['中标厂家']] ?? '')),
                    '生产厂家'   => trim((string)($r[$colMap['生产厂家']] ?? '')),
                    '铭牌ID'     => $mingpai,
                    '出厂编号'   => $sn,
                    '供货订单号' => trim((string)($r[$colMap['供货订单号']] ?? '')),
                    '技术ID'     => trim((string)($r[$colMap['技术ID']] ?? '')),
                    '_qr_info'   => $qr
                ];
            }

            if (empty($dataList)) throw new Exception("Excel 中未提取到有效数据");

            $phpWord = new PhpWord();
            $phpWord->setDefaultFontName('微软雅黑');
            $phpWord->setDefaultFontSize(10.5);

            $section = $phpWord->addSection([
                'marginTop'    => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.5),
                'marginBottom' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.5),
                'marginLeft'   => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.5),
                'marginRight'  => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.5)
            ]);

            $tableStyle = ['borderSize' => 6, 'borderColor' => '000000', 'cellMargin' => 60];
            $cellCenterAlign = ['valign' => VerticalJc::CENTER];

            $fieldList = [
                "中标批次", "项目名称", "IP地址", "设备名称", "设备类型", "型号",
                "中标厂家", "生产厂家", "铭牌ID", "出厂编号", "供货订单号", "技术ID"
            ];

            $renderHenanCard = function($row) use ($section, $tableStyle, $cellCenterAlign, $fieldList, &$tempQrFiles) {
                $table = $section->addTable($tableStyle);
                foreach ($fieldList as $label) {
                    $table->addRow(280);
                    $table->addCell(1800, $cellCenterAlign)->addText($label, ['name' => '微软雅黑', 'size' => 10.5]);
                    $table->addCell(7200, $cellCenterAlign)->addText($row[$label] ?? '', ['name' => '微软雅黑', 'size' => 10.5]);
                }

                $qrTable = $section->addTable($tableStyle);
                $qrTable->addRow(1400);
                $qrCell = $qrTable->addCell(9000, $cellCenterAlign);

                $qrInfo = $row['_qr_info'] ?? '';
                $qf = $this->createTempQrImage($qrInfo);
                if ($qf) {
                    $tempQrFiles[] = $qf;
                    $qrCell->addImage($qf, ['width' => 85, 'height' => 85, 'alignment' => Jc::CENTER]);
                } else {
                    $qrCell->addText("无二维码数据", ['name' => '微软雅黑', 'size' => 10], ['alignment' => Jc::CENTER]);
                }
            };

            $total = count($dataList);
            for ($i = 0; $i < $total; $i += 2) {
                if ($i > 0) $section->addPageBreak();
                $renderHenanCard($dataList[$i]);
                if ($i + 1 < $total) {
                    $section->addTextBreak(3);
                    $renderHenanCard($dataList[$i + 1]);
                }
            }

            $tempDocx = tempnam(sys_get_temp_dir(), 'word_hn_') . '.docx';
            \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($tempDocx);
            foreach ($tempQrFiles as $f) @unlink($f);

            AuditService::log('实用工具箱', '生成河南FTU标签');

            $outName = '河南FTU标签_' . date('Ymd_His') . '.docx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="' . rawurlencode($outName) . '"');
            header('Content-Length: ' . filesize($tempDocx));
            header('Cache-Control: no-cache, must-revalidate');
            readfile($tempDocx);
            @unlink($tempDocx);
            exit;
        } catch (Throwable $e) {
            foreach ($tempQrFiles as $f) @unlink($f);
            http_response_code(500);
            error_log('[工具箱] 文件生成失败: ' . $e->getMessage());
            echo "生成失败，请稍后重试或联系管理员";
            exit;
        }
    }
}