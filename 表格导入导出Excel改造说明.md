# 表格导入导出：CSV 全面升级支持 Excel（.xlsx）

> 改造原则：**所有原本只支持 CSV 的导入/导出，一律升级为 Excel 优先**；
> 原本已支持 Excel 的模块（质量追溯、文件错误记录、生产进度周报、条码比对导入、实用工具箱）**保持不动**。
> 服务器已安装 PhpSpreadsheet，因此线上会输出 `.xlsx`；本地未安装 vendor 时自动回退 CSV，功能不会报错。

---

## 一、统一实现方式

每个导出/导入方法都改为「Excel 优先 + CSV 兜底」：

```php
if (class_exists(Spreadsheet::class)) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('xxx');
    $sheet->fromArray($headers, null, 'A1');
    // ...逐行写入...
    // 表头蓝底白字加粗 + 自动列宽
    $filename = 'xxx_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}
// 以下为原 CSV 逻辑（兜底，仅当未安装 PhpSpreadsheet 时执行）
```

要点：
- **工单号 / SN / MAC / 编号等长数字**统一用 `setCellValueExplicit(..., DataType::TYPE_STRING)`，
  防止科学计数法、丢失前导零（等价于原 CSV 里 `"\t" . $value` 的作用，且更干净）。
- **纯数值列**（总产量、阻抗值、效率等）用 `setCellValue(..., (float)...)`，在 Excel 中可直接参与计算。
- 表头统一：加粗 + 白字 + 蓝底 `2F5597`，所有列自动列宽。

---

## 二、关键基础设施改动（必看）

**`backend/public/index.php`**

原来的自动加载只注册了 `App\` 命名空间，**从未加载 composer 的 `vendor/autoload.php`**，
因此 `class_exists(Spreadsheet::class)` 一直为 `false`（也就是说，DocError / Weekly 里原有的 Excel 分支很可能从未真正生效过）。

本次在第 2 步之后新增：

```php
// 2.1 加载 Composer 第三方依赖（PhpSpreadsheet 等，供 Excel 导入导出使用）
//     本地若未安装 vendor 会自动跳过，相关功能将自动回退为 CSV 格式
$composerAutoload = __DIR__ . "/../vendor/autoload.php";
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}
```

> 该改动在服务器上会让 PhpSpreadsheet 全局可用（顺带把 DocError / Weekly 的 Excel 导出真正点亮）；
> 本地没有 vendor 时静默跳过，不影响任何功能。

---

## 三、改造清单

### 1) 导出（12 个，CSV → 新增 Excel 输出）

| 模块 | 文件 | 方法 | 路由 |
|---|---|---|---|
| 资产台账 | `AssetsController.php` | `exportCsv` | `/api/assets/export_csv` |
| 人员离岗 | `AttendanceController.php` | `export` | `/api/attendance/export` |
| 条码比对 | `BarcodeValidatorController.php` | `exportCompleted` | `/api/barcode_validator/export_completed` |
| 工作日记 | `DiaryController.php` | `export` | `/api/diary/export` |
| 北边返厂 | `BeibianController.php` | `export` | `/api/beibian/export` |
| 阻抗测试 | `ImpedanceController.php` | `export` | `/api/impedance/export` |
| 首件检 | `FirstPieceController.php` | `export` | `/api/first_piece/export` |
| MAC 分配明细 | `MacController.php` | `exportDetails` | `/api/mac/export_details` |
| OEE 班组效率 | `OeeController.php` | `export` | `/api/oee/export` |
| 外协管理 | `OsmController.php` | `export` | `/api/osm/export` |
| 质量问题 | `QualityController.php` | `export` | `/api/quality/export` |
| 问题排查 | `TroubleshootController.php` | `export` | `/api/troubleshoot/export` |

### 2) 导入（2 个，新增支持 .xlsx/.xls 上传）

| 模块 | 文件 | 方法 | 说明 |
|---|---|---|---|
| 资产台账 | `AssetsController.php` | `importCsv` | 新增 `.xlsx/.xls` 分支；新增私有方法 `csvToMatrix()` 统一解析（BOM/GBK 兼容）；`.csv` 与 JSON `csv_content` 老路径不变 |
| 外协管理 | `OsmController.php` | `uploadOsm` | 按扩展名分流：Excel 用 PhpSpreadsheet 读取，CSV 用 `fgetcsv`，统一为二维数组后写库 |

### 3) 唯一一处「纯前端 JS 生成 CSV」→ 走后端 Excel

| 模块 | 改动 |
|---|---|
| MAC / ID 查询 | 新增 `MacQueryController::export` + 路由 `GET /api/mac_query/export`；前端不再本地拼 CSV，改为携带最近一次查询的 `sn/task` 调用后端导出 |

### 4) 前端（文案与文件选择器同步）

| 文件 | 改动 |
|---|---|
| `views/assets/Index.vue` | 弹窗标题「批量导入资产 (Excel/CSV)」，`accept=".xlsx,.xls,.csv, text/csv"`，提示语同步 |
| `views/osm/Index.vue` | 弹窗标题「批量导入外协数据 (Excel/CSV)」，`accept=".xlsx,.xls,.csv"`，提示语同步 |
| `views/attendance/Index.vue` | 「导出 CSV」→「导出 Excel」 |
| `views/barcode/Index.vue` | 「导出已完成 CSV」→「导出已完成 Excel」 |
| `views/diary/Index.vue` | 「开始导出 CSV」→「开始导出 Excel」 |
| `views/first_piece/Index.vue` | 「导出 CSV」→「导出 Excel」 |
| `views/oee/Index.vue` | 「导出 CSV」→「导出 Excel」 |
| `views/troubleshoot/index.vue` | 「导出 CSV」→「导出 Excel」 |
| `views/mac_query/Index.vue` | 「导出 CSV」→「导出 Excel」；改走 `/api/mac_query/export`，记录 lastSn/lastTask |

---

## 四、未改动（本已支持 Excel）

- 质量追溯 `TraceController::exportExcel`（走 legacy，已输出 Excel）
- 文件错误记录 `DocErrorController::export`（本就有 PhpSpreadsheet 分支）
- 生产进度周报 `WeeklyController::export`（同上）
- 条码比对导入 `BarcodeValidatorController::uploadReference`（已支持 .xlsx/.xls/.csv）
- 实用工具箱 `ToolsController`（本身就是 Excel）

---

## 五、部署步骤

1. 上传以下 **后端文件**（保持目录结构）：
   - `backend/public/index.php`
   - `backend/app/Controllers/` 下的：
     `AssetsController.php`、`AttendanceController.php`、`BarcodeValidatorController.php`、
     `DiaryController.php`、`BeibianController.php`、`ImpedanceController.php`、`FirstPieceController.php`、
     `MacController.php`、`OeeController.php`、`OsmController.php`、`QualityController.php`、
     `TroubleshootController.php`、`MacQueryController.php`
2. 重启 PHP（OPcache，必做）：
   ```bash
   systemctl restart php-fpm-82
   ```
3. 前端重新构建并覆盖 dist（或直接上传本地 `frontend/dist`）：
   ```bash
   cd /www/wwwroot/vite/frontend && npm run build
   ```
   > 建议构建前先 `rm -rf dist/assets`，避免历史包堆积（vite.config.js 的 `emptyOutDir` 仍为 false）。
4. 强刷浏览器（Ctrl+F5）。

---

## 六、自检清单

- [ ] 服务器 `backend/vendor/autoload.php` 存在（PhpSpreadsheet 已安装）
- [ ] 各模块点「导出 Excel」下载到 `.xlsx`，用 Excel 打开无乱码、长数字不丢前导零/不变科学计数法
- [ ] 资产「批量导入」可上传 `.xlsx`；外协「批量导入」可上传 `.xlsx`
- [ ] 断网/卸载 vendor 时，导出自动回退 `.csv`（兜底不报错）
