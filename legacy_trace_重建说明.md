# `legacy_trace.php` 重建说明

> 时间：2026-10-10 · 触发事故：质量追溯「导出 Excel」返回
> `{"success":false,"message":"系统内部错误，请稍后重试或联系管理员","request_id":"0b6f02ff1a5e"}`

---

## 一、结论：桌面那份文件救不了

| 来源 | 行数 | 结尾内容 | 可用性 |
|---|---|---|---|
| `D:\work\新系统\backend\app\legacy_trace.php`（原） | 647 | `$reqStr = $colReqQty ? ...`（`foreach` 未闭合） | ❌ |
| `C:\Users\lijiaqi\Desktop\legacy_trace.php` | 643 | 同上，**同一位置截断**（且是加固前的旧版，含明文 CEM 凭据） | ❌ |
| 服务器 `/www/wwwroot/vite/backend/app/legacy_trace.php` | 647 | 同上 | ❌ |

**证据链**（三份文件互相印证，行号完全吻合）：

1. 服务器 PHP 日志：`ParseError: Unclosed '{' on line 634 @ /www/wwwroot/vite/backend/app/legacy_trace.php:647`
2. 本地第 634 行正是 `foreach ($rawRows as $r) {`，文件到第 647 行结束、从未闭合
3. 用 PHP 8.2 对残缺副本做 `php -l`，**复现出完全一致**的报错（`line 634 … on line 647`）

> 桌面文件只是同一份残缺内容在加固前的快照，**不能用来恢复**。

---

## 二、重建依据（不是凭空写的）

- `TraceController::execLegacy()` 用 `require __DIR__ . '/../legacy_trace.php'` 注入 `$user / $db / $userPerms / $isAdmin`，
  并写死 action 名：`check_or_fetch_erp / save_config / start_trace / scan_barcode / update_scan_record / delete_scan_record / manual_push_cem / export_excel`
- 前端 `frontend/src/views/trace/Index.vue` 提供了每个 action 的**入参与返回字段契约**（逐字段还原）
- 数据表结构（`trace_tasks / trace_bom_config / trace_boxes / trace_scans / trace_permissions`）在残缺部分**完整保留**
- `includes/CemPushService::pushTask()`、`includes/CemClient` **完好未损**，推送分支直接复用
- 导出格式与其余 15 个导出控制器保持同一套「Excel 优先 + CSV 兜底 + 公式注入防护」写法

---

## 三、重建后的文件结构

**`backend/app/legacy_trace.php` — 1245 行**

| 行号 | 内容 |
|---|---|
| 1–48 | 兼容函数 `getDB/checkUser/sendJson` + CEM 配置常量（走 `CemConfig`，凭据不落源码） |
| 49–60 | **新增**：JSON 请求体合并（前端 axios 用 `application/json`，PHP 不会自动填 `$_POST`） |
| 61–395 | CEM/ERP 工具函数、建表 DDL、权限解析、`findExactCol`、ERP 连接 |
| 396 | `init` |
| 401 | `check_or_fetch_erp`（XQ 工程发货分支 **原文完好**；标准工单分支 **本次补全**） |
| 731–782 | **新增** 工具函数：`trBoxPointScanned` / `trCountCompletedBoxes` / `trIsBoxFullyCompleted` / `trRefreshBoxStatus` |
| 787 | **补全** `save_config` |
| 871 | **补全** `start_trace` |
| 906 | **补全** `scan_barcode` |
| 1038 | **补全** `update_scan_record` |
| 1090 | **补全** `delete_scan_record` |
| 1118 | **补全** `manual_push_cem` |
| 1151 | **补全** `export_excel`（Excel 优先 / CSV 兜底） |

**权限校验**（与前端按钮显隐一一对应）

| action | 校验的权限 |
|---|---|
| `save_config` | `can_add_material` |
| `start_trace` | `can_scan` 或 `can_add_material` |
| `scan_barcode` | `can_scan` |
| `update_scan_record` | `can_edit_record` |
| `delete_scan_record` | `can_delete_record` |
| `manual_push_cem` | 任一追溯权限 |
| `export_excel` | `can_view` |

---

## 四、⚠️ 两处「按推断实现」的业务规则（上线后请实测确认）

原文件已丢失，以下两条是**依据现有数据结构 + 前端交互反推**的，请用真实条码跑一遍确认：

### 1. 二级物料条码 → 物料编码的匹配规则

前端扫码只上传一个 `sn`（不上传 `child_code`），所以后端必须自己判断扫的是哪颗料：

- **主规则**：取本单本扫码点下所有 `child_code`，按长度从长到短，**SN 以哪个 child_code 开头**就算哪颗料
  （即工厂条码 = 物料编码 + 流水号）
- **兜底**：若前缀都不匹配，则看 SN 是否**包含**某个 child_code —— 只有唯一一个命中时才采纳，避免误判
- 都不匹配 → 拦截提示「条码不属于本扫码点配置的任何物料」

> 若现场条码规则不同（例如条码里不含物料编码），把 `scan_barcode` 里 `$matchedCode` 那一段改掉即可，位置集中、易改。

### 2. 「装置完成」的判定口径

- `trace_boxes.status = 1` ⟺ 该装置**所有扫码点**的二级物料都扫满（按 `trace_bom_config.unit_quantity` 逐点核对）
- 扫码页「已完成数」= `status = 1` 的装置数
- `task_all_completed` = 已完成数 ≥ `trace_tasks.quantity` → 前端弹出「扫码完成」并自动推送 CEM
- 修改 / 删除记录后会**自动重算**该装置状态（可回退为未完成）

> 多点扫码（`scan_points > 1`）时：同一台装置需要在每个扫码点各扫一轮；全部点齐了才算完工 —— 与前端「切换工位后要求重扫一级码」的交互一致。

---

## 五、部署步骤

```bash
# 1) 备份服务器现有文件（务必先做）
cp -a /www/wwwroot/vite/backend/app/legacy_trace.php \
      /root/legacy_trace.before_$(date +%Y%m%d%H%M).php

# 2) 上传覆盖
#    本地：D:\work\新系统\backend\app\legacy_trace.php
#    目标：/www/wwwroot/vite/backend/app/legacy_trace.php
#    权限：chown www:www ; chmod 644

# 3) 语法体检（必须通过，这是本次事故的根因防线）
/www/server/php/82/bin/php -l /www/wwwroot/vite/backend/app/legacy_trace.php
#   → 期望：No syntax errors detected in ...

# 4) 重启 PHP（OPcache validate_timestamps=0，不重启不生效）
systemctl restart php-fpm-82
```

### 顺手确认 CEM 依赖仍在（本次未改动，但推送分支依赖它）

```bash
ls -l /www/wwwroot/vite/backend/includes/CemPushService.php \
      /www/wwwroot/vite/backend/includes/CemClient.php \
      /www/wwwroot/vite/backend/config/cem.local.php
```

---

## 六、验证清单

| # | 用例 | 期望 |
|---|---|---|
| 1 | `php -l` | `No syntax errors` |
| 2 | 打开质量追溯页 | 各 Tab 正常，无 500 |
| 3 | 【添加/编辑追溯物料】查工单 → 保存 | 提示保存成功，自动跳扫码台并带出物料清单 |
| 4 | 扫一级条码 | 提示「一级条码【xx】已登记」，右侧出现二级物料卡片 |
| 5 | 扫二级物料 | 卡片「已扫 x/所需 y」递增；扫满提示齐套并蜂鸣 |
| 6 | 扫不属于本单的条码 | 拦截提示，不写库 |
| 7 | 扫重复条码 | 拦截提示「已被装置【xx】使用」 |
| 8 | 【追溯记录查询与导出】查询 | 列表有数据、扫码人显示姓名 |
| 9 | **导出 Excel**（本次故障点） | 直接下载 `.xlsx`，不再是 500 |
| 10 | 修改 / 删除记录 | 操作成功；装置完工状态同步回退 |
| 11 | 一键推送 CEM | 提示上传条数；失败时给出**已脱敏**的 CEM 业务提示 |
| 12 | 已配置单据库 | 「已完成数 / 状态」正确，可点「扫码 / 编辑 / 推送CEM」 |

**本地已完成的验证**：语法体检通过；用桩数据库对 8 个 action 做了运行时冒烟测试（含正常路径与拦截路径），
无致命错误、无未定义函数/方法。

---

## 七、如果还想找回原版（可选的第二步确认）

服务器上也许还留着改动前的完整副本，值得花 30 秒查一下：

```bash
# 全盘找同名备份
find /www /root /home -name "legacy_trace*.php*" 2>/dev/null | grep -v "vite/backend/app/legacy_trace.php$"

# 宝塔站点备份目录
ls -lat /www/backup/site/ 2>/dev/null | head -20
ls -lat /www/backup/database/ 2>/dev/null | head -10

# 若找到备份，先看行数：应远大于 647
wc -l <候选文件路径>
```

- 若找到 **>1000 行的完整版** → 发给我，我按它对齐复核本次重建的两条业务规则（其余代码本可直接沿用）
- 若只有 647 行的残缺版 → 直接用本次重建版即可
