-- =====================================================================
-- CEM 推送修复：补齐 trace_tasks.tasksid / rel_no 字段
--
-- 问题：XQ 工单在「查询 ERP」环节已从 CEM 拿到流程卡号 (tasksid) 和工程关联单据号
--       (rel_no)，但旧代码从未写入 trace_tasks，导致 pushTask 时 H/I 列回退成
--       XQ 工单号本身、I 列为空 —— CEM 虽"插入成功"，但界面按流程卡号索引
--       查不到数据，表现为"推送成功但不显示"。
--
-- 已修复（2026-10-14）：
--   1. legacy_trace.php save_config：INSERT/UPDATE 均写回 tasksid / rel_no
--   2. 前端 Index.vue handleSaveMatConfig：回传 tasksid / rel_no
--   3. legacy_trace.php export_excel：导出改为 9 列标准格式（与老系统一致）
--
-- 本 SQL 用于核对与手工兜底。正常流程下不需要执行第 3 步——
-- 重新走一遍「添加物料 → 查询ERP → 保存配置」即会自动补上 tasksid/rel_no。
-- =====================================================================

-- 1. 核对：哪些 XQ 工单缺失流程卡号 / 工程号（修复后应为 0 行）
SELECT task_order, order_type, tasksid, rel_no
FROM trace_tasks
WHERE order_type = 'xq_project' AND tasksid = '';

-- 2. 查看某个工单当前的 H/I 列取值（推送 CEM 用的就是这两个值）
SELECT task_order, order_type, tasksid, rel_no FROM trace_tasks WHERE task_order = 'XQ2026-23895';

-- 3. 手工兜底（仅当无法走 UI 重新保存时使用）：
--    把下面两个值替换成 CEM【任务管理】里该 XQ 单对应的真实流程卡号与工程关联单据号
--    UPDATE trace_tasks
--    SET    tasksid = '真实流程卡号(如26101000066)',
--           rel_no  = '真实工程号(如j2026711002)'
--    WHERE  task_order = 'XQ2026-23895';

-- 4. 修完复核：tasksid 应为 CEM 流程卡号（纯数字），rel_no 为工程号（j 开头）
SELECT task_order, tasksid, rel_no FROM trace_tasks WHERE task_order = 'XQ2026-23895';
