-- ============================================================
--  老化车多人同时维修：补建参与人表（手工兜底）
--
--  适用现象：SELECT COUNT(*) FROM aging_repair_workers
--            → #1146 - Table 'vite.aging_repair_workers' doesn't exist
--
--  说明：正常情况下本表由 Schema::ensureOnce('aging_cart') 在
--        「访问一次老化车页面」时自动创建，无需手工执行本脚本。
--        这里提供手工路径，用于：数据库账号没有 CREATE 权限、
--        或不想依赖自愈逻辑、或需要立刻补救的场景。
--        全部语句幂等，重复执行不会产生重复数据。
--
--  执行：ROOT=/www/wwwroot/vite
--        mysql -uroot -p vite < "$ROOT/tools/老化车多人维修补表.sql"
-- ============================================================

-- 1) 参与人表（多人同时维修时各计各的工时）
CREATE TABLE IF NOT EXISTS `aging_repair_workers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `repair_id` INT NOT NULL COMMENT '所属报修维修单ID',
  `user_id` INT NOT NULL DEFAULT 0 COMMENT '维修人员ID',
  `user_name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '维修人员姓名',
  `is_starter` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=首个接单开工的人',
  `joined_at` DATETIME NOT NULL COMMENT '加入维修时刻',
  `left_at` DATETIME NULL COMMENT '离开(本人完成)时刻，NULL 表示仍在维修',
  `raw_minutes` INT NOT NULL DEFAULT 0 COMMENT '本人自然时长(分钟)',
  `duration_minutes` INT NOT NULL DEFAULT 0 COMMENT '本人有效时长(分钟)',
  `status` VARCHAR(20) NOT NULL DEFAULT 'working' COMMENT 'working维修中/left已离开',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_repair_user_join` (`repair_id`, `user_id`, `joined_at`),
  INDEX `idx_repair_id` (`repair_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老化车维修参与人（多人同时维修时各自独立计时）';

-- 2) 历史数据迁移：把已有的单人维修记录转成参与人记录
--    ※ 必须在上一步建表之后执行
--    ※ NOT EXISTS 保证幂等：已补过的工单不会重复插入
INSERT IGNORE INTO `aging_repair_workers`
    (`repair_id`, `user_id`, `user_name`, `is_starter`, `joined_at`, `left_at`,
     `raw_minutes`, `duration_minutes`, `status`)
SELECT r.id, r.repairer_id, r.repairer_name, 1, r.repair_started_at,
       CASE WHEN r.status = 'done' THEN r.repair_finished_at ELSE NULL END,
       CASE WHEN r.status = 'done' THEN r.raw_minutes ELSE 0 END,
       CASE WHEN r.status = 'done' THEN r.duration_minutes ELSE 0 END,
       CASE WHEN r.status = 'done' THEN 'left' ELSE 'working' END
FROM `aging_repairs` r
WHERE r.repair_started_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `aging_repair_workers` w WHERE w.repair_id = r.id);

-- 3) 核对结果（应看到参与人记录数 > 0，且与已开工/已完成的工单数一致）
SELECT
  COUNT(*)                                                        AS 参与人记录数,
  SUM(CASE WHEN status = 'working' THEN 1 ELSE 0 END)             AS 仍在维修人次,
  COUNT(DISTINCT repair_id)                                       AS 涉及工单数
FROM `aging_repair_workers`;
