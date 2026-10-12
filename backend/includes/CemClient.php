<?php
// includes/CemClient.php
// 说明：模拟登录 cem.ieslab.cn，查询流程卡号并真实上传追溯 Excel 文件至 Quality_Track/ExcelToQuality.aspx

class CemClient
{
    const HOST         = 'http://cem.ieslab.cn';
    const COOKIE_FILE  = '/tmp/cem_session_cookie.txt';
    const COOKIE_LOCK  = '/tmp/cem_session_cookie.lock';

    /**
     * ★ 安全加固（H5）：CEM 凭据不再写死在源码，改为从环境变量 / .env 读取，
     *   回退到未纳入版本库的 backend/config/cem.local.php。
     */
    private static function cred(string $key, string $default = ''): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if (is_string($v) && $v !== '') {
            return $v;
        }
        if (function_exists('getenv')) {
            $g = getenv($key);
            if ($g !== false && $g !== '') {
                return (string)$g;
            }
        }
        $file = __DIR__ . '/../config/cem.local.php';
        if (is_file($file)) {
            $data = include $file;
            if (is_array($data) && !empty($data[$key])) {
                return (string)$data[$key];
            }
        }
        return $default;
    }

    public static function cemUser(): string { return self::cred('CEM_USER'); }
    public static function cemPass(): string { return self::cred('CEM_PASS'); }

    // 真实路径
    const UPLOAD_URL   = '/Quality_Track/ExcelToQuality.aspx';
    const UV_QUERY_URL = '/Quality_Track/UV_Query.aspx';   // 任务管理 / 流程卡查询页面

    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function ensureLogin()
    {
        if ($this->hasValidSession()) return true;
        return $this->login();
    }

    public function hasValidSession()
    {
        if (!file_exists(self::COOKIE_FILE)) return false;
        try {
            $html = $this->rawRequest(self::HOST . '/main.aspx', null, null, true);
            if (preg_match('/name=["\']Hid_userName["\'][^>]*value=["\']([^"\']*)["\']/i', $html, $m)) {
                return trim($m[1]) === self::cemUser();
            }
            if (strpos($html, 'userLogin') !== false || strpos($html, 'userPwd') !== false) {
                return false;
            }
            return (strpos($html, self::cemUser()) !== false || strpos($html, '欢迎您') !== false);
        } catch (Exception $e) {
            return false;
        }
    }

    public function login()
    {
        $lock = @fopen(self::COOKIE_LOCK, 'c');
        if ($lock && flock($lock, LOCK_EX)) {
            try {
                if ($this->hasValidSession()) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                    return true;
                }

                if (file_exists(self::COOKIE_FILE)) @unlink(self::COOKIE_FILE);

                $html = $this->rawRequest(self::HOST . '/login.aspx');
                $fields = $this->extractAllHiddenFields($html);

                $postData = array_merge($fields, array(
                    'userName'  => self::cemUser(),
                    'userPwd'   => self::cemPass(),
                    'userLogin' => '登 录',
                ));
                $this->rawRequest(self::HOST . '/login.aspx', $postData, self::HOST . '/login.aspx');

                flock($lock, LOCK_UN);
                fclose($lock);

                @chmod(self::COOKIE_FILE, 0666);
                @chmod(self::COOKIE_LOCK, 0666);
                return $this->hasValidSession();
            } catch (Exception $e) {
                if ($lock) {
                    @flock($lock, LOCK_UN);
                    @fclose($lock);
                }
                return false;
            }
        }
        return false;
    }

    /**
     * 查询工单对应的流程卡号
     * UV_Query.aspx 页面：
     *   - 搜索框 name="Txt_Find"
     *   - 图片按钮 name="imgbtn"（提交时用 imgbtn.x / imgbtn.y）
     *   - 隐藏字段 name="hidSQL"（保持为空，只走模糊查找）
     *   - 表格 id="GridView1"
     *     第 0 列 = 流程卡号（如 26092100016）
     *     第 4 列 = 关联单据（纯数字工单号 如 192881；也可能是 j 开头工程号）
     *
     * @param string $taskOrder 工单号（纯数字，如 192881）
     * @return string 流程卡号（如 26092100016）
     */
    public function queryProjectCardNo($taskOrder)
    {
        $this->ensureLogin();
        $taskOrder = (string)$taskOrder;
        $targetUrl = self::HOST . self::UV_QUERY_URL;

        // 1) GET 打开页面（拿 ViewState + 可能已带结果）
        try {
            $pageHtml = $this->rawRequest($targetUrl, null, self::HOST . '/main.aspx');
        } catch (Exception $e) {
            throw new Exception('无法打开 CEM 任务查询页面: ' . $e->getMessage());
        }

        $matched = $this->parseCardNoFromHtml($pageHtml, $taskOrder);
        if ($matched !== '') {
            @file_put_contents('/tmp/cem_query_success.txt',
                "时间: " . date('Y-m-d H:i:s') . "\n来源: 页面首屏\n工单: {$taskOrder}\n流程卡号: {$matched}\n");
            return $matched;
        }

        $baseFields = $this->extractAllHiddenFields($pageHtml);

        // 2) 提交搜索（严格按页面表单：Txt_Find + imgbtn.x/y + hidSQL 空）
        $postData = array_merge($baseFields, array(
            '__EVENTTARGET'   => '',
            '__EVENTARGUMENT' => '',
            'Txt_Find'        => $taskOrder,
            'imgbtn.x'        => '10',
            'imgbtn.y'        => '10',
            'hidSQL'          => '',
        ));

        try {
            $resultHtml = $this->rawRequest($targetUrl, $postData, $targetUrl);
        } catch (Exception $e) {
            throw new Exception('查询 CEM 任务失败: ' . $e->getMessage());
        }

        // Session 掉线 → 重登再来
        if (strpos($resultHtml, 'userLogin') !== false && strpos($resultHtml, 'userPwd') !== false) {
            @unlink(self::COOKIE_FILE);
            if ($this->login()) {
                return $this->queryProjectCardNo($taskOrder);
            }
        }

        // 调试存档（无论成败都存一份，便于排查）
        $debugFile = '/tmp/cem_query_result_' . preg_replace('/[^\w\-]/', '_', $taskOrder) . '.html';
        @file_put_contents($debugFile, $resultHtml);

        // 3) 解析结果
        $cardNo = $this->parseCardNoFromHtml($resultHtml, $taskOrder);
        if ($cardNo !== '') {
            @file_put_contents('/tmp/cem_query_success.txt',
                "时间: " . date('Y-m-d H:i:s') . "\n来源: POST 查询\n工单: {$taskOrder}\n流程卡号: {$cardNo}\n");
            return $cardNo;
        }

        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($resultHtml)));
        $short = mb_substr($plain, 0, 120);
        throw new Exception("在 CEM 任务管理页面未查到任务【{$taskOrder}】对应的流程卡号。调试HTML: {$debugFile} (响应片段: {$short})");
    }

    /**
     * 从 GridView1 结果表里解析流程卡号
     * 规则：
     *   1. 只在 GridView1 表格内扫描（不误伤页面其他表格）
     *   2. 表头动态识别"流程卡号""关联单据"两列的索引（不再硬编码 0 / 4）
     *   3. 只有"关联单据"列命中输入工单号的行，才取其第 0 列流程卡号
     *   4. 严格校验流程卡号格式（26 开头、11 位数字）
     *   5. 移除原来的"无论如何返回第一行"兜底 —— 这是之前错值的根源
     */
    private function parseCardNoFromHtml($html, $taskOrder)
    {
        if (empty($html)) return '';
        $taskOrder = (string)$taskOrder;

        // 只截取 GridView1 这张表
        $tableHtml = $html;
        if (preg_match('/<table[^>]*id=["\']GridView1["\'][^>]*>(.*?)<\/table>/is', $html, $tm)) {
            $tableHtml = $tm[0];
        }

        // 1) 动态识别列索引
        $cardIdx  = 0;   // "流程卡号" 默认第 0 列
        $orderIdx = 4;   // "关联单据" 默认第 4 列

        if (preg_match('/<tr[^>]*>(.*?)<\/tr>/is', $tableHtml, $headerTr)
            && preg_match_all('/<th[^>]*>(.*?)<\/th>/is', $headerTr[1], $thList)
        ) {
            foreach ($thList[1] as $i => $thHtml) {
                $headText = trim(preg_replace('/\s+/', '', strip_tags($thHtml)));
                if ($headText === '流程卡号') $cardIdx  = $i;
                if ($headText === '关联单据') $orderIdx = $i;
            }
        }

        // 流程卡号格式：26 开头，11 位（如 26092100016）
        $cardPattern = '/^2\d{10}$/';

        // 2) 扫描每一行数据
        if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tableHtml, $trList)) {
            foreach ($trList[1] as $tr) {
                // 跳过表头行、分页行
                if (stripos($tr, '<th') !== false) continue;
                if (strpos($tr, 'GridView1_lktransid') === false) continue;

                if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $tr, $tdList)) continue;

                $cleanTds = array();
                foreach ($tdList[1] as $tdHtml) {
                    $cleanTds[] = trim(preg_replace('/\s+/', ' ', strip_tags($tdHtml)));
                }

                if (!isset($cleanTds[$cardIdx]) || !isset($cleanTds[$orderIdx])) continue;

                $card  = trim($cleanTds[$cardIdx]);
                $order = trim($cleanTds[$orderIdx]);

                // 关联单据列匹配：全等 / 包含 / 被包含，任一成立即算命中
                $orderMatched = ($order === $taskOrder)
                    || (strpos($order, $taskOrder) !== false)
                    || ($order !== '' && strpos($taskOrder, $order) !== false);

                if (!$orderMatched) continue;

                // 格式校验，避免把非流程卡号误当流程卡号
                if (preg_match($cardPattern, $card)) {
                    return $card;
                }
            }
        }

        return '';
    }

    /**
     * 纯 PHP 原生 cURL 模拟选择文件并点击"导入数据"
     */
    public function uploadQualityExcel($localFilePath, $uploadName = null)
    {
        if (!file_exists($localFilePath)) {
            throw new Exception('待上传的本地 Excel 文件不存在: ' . $localFilePath);
        }
        $this->ensureLogin();

        $targetUrl = self::HOST . self::UPLOAD_URL;

        $pageHtml = $this->rawRequest($targetUrl, null, self::HOST . '/main.aspx');

        if (strpos($pageHtml, 'FileUpload1') === false && strpos($pageHtml, 'Button1') === false) {
            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($pageHtml)));
            throw new Exception("无法载入 CEM Excel 导入页面: " . mb_substr($plain, 0, 150));
        }

        $fields = $this->extractAllHiddenFields($pageHtml);

        if ($uploadName === null) {
            $uploadName = basename($localFilePath);
        }

        $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        if (preg_match('/\.xls$/i', $localFilePath)) {
            $mimeType = 'application/vnd.ms-excel';
        }

        $postData = array(
            '__EVENTTARGET'        => '',
            '__EVENTARGUMENT'      => '',
            '__VIEWSTATE'          => $fields['__VIEWSTATE'] ?? '',
            '__VIEWSTATEGENERATOR' => $fields['__VIEWSTATEGENERATOR'] ?? 'C4357332',
            '__EVENTVALIDATION'    => $fields['__EVENTVALIDATION'] ?? '',
            'FileUpload1'          => new CURLFile($localFilePath, $mimeType, $uploadName),
            'Button1'              => '导入数据',
        );

        if (isset($fields['__VIEWSTATEENCRYPTED'])) {
            $postData['__VIEWSTATEENCRYPTED'] = $fields['__VIEWSTATEENCRYPTED'];
        }

        $resultHtml = $this->rawMultipartRequest($targetUrl, $postData, $targetUrl);

        if (strpos($resultHtml, 'userLogin') !== false && strpos($resultHtml, 'userPwd') !== false) {
            @unlink(self::COOKIE_FILE);
            if ($this->login()) {
                return $this->uploadQualityExcel($localFilePath, $uploadName);
            }
        }

        $alertMsg = '';
        if (preg_match('/alert\s*\(\s*["\']([^"\']+)["\']\s*\)/iu', $resultHtml, $mAlert)) {
            $alertMsg = trim($mAlert[1]);
        }

        @file_put_contents('/tmp/cem_last_upload_result.html', $resultHtml);

        return array(
            'alert' => $alertMsg,
            'html'  => $resultHtml,
        );
    }

    private function rawRequest($url, $postData = null, $referer = null, $isCheck = false)
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_COOKIEJAR      => self::COOKIE_FILE,
            CURLOPT_COOKIEFILE     => self::COOKIE_FILE,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_TIMEOUT        => $isCheck ? 15 : 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_HTTPHEADER     => array(
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            ),
        ));
        if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) throw new Exception('CURL 错误: ' . $err);
        if ($httpCode === 404) throw new Exception('页面不存在 (404): ' . $url);

        return $body;
    }

    private function rawMultipartRequest($url, $fields, $referer = null)
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_COOKIEJAR      => self::COOKIE_FILE,
            CURLOPT_COOKIEFILE     => self::COOKIE_FILE,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_HTTPHEADER     => array(
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Cache-Control: no-cache',
                'Origin: ' . self::HOST,
                'Upgrade-Insecure-Requests: 1',
            ),
        ));
        if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new Exception('CURL 上传错误: ' . $err);
        return $body;
    }

    private function extractAllHiddenFields($html)
    {
        $fields = array();
        if (preg_match_all('/<input[^>]*type=["\']hidden["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $inputTag) {
                if (preg_match('/name=["\']([^"\']*)["\']/i', $inputTag, $nameM)) {
                    $name = $nameM[1];
                    $val = '';
                    if (preg_match('/value=["\']([^"\']*)["\']/i', $inputTag, $valM)) {
                        $val = html_entity_decode($valM[1], ENT_QUOTES, 'UTF-8');
                    }
                    $fields[$name] = $val;
                }
            }
        }
        return $fields;
    }
}