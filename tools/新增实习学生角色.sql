-- ============================================================
--  新增岗位角色：实习学生
--  适用：装置车间综合协作平台（/www/wwwroot/vite）
--  说明：roles 表没有后台增删界面，本脚本用于手工补齐；
--        后端 Schema::ensurePresetRoles() 也会在访问「角色权限」页时自动补，
--        两者口径一致、均幂等，跑几次都不会产生重复行。
--  执行：ROOT=/www/wwwroot/vite
--        mysql -uroot -p vite < "$ROOT/tools/新增实习学生角色.sql"
-- ============================================================

-- 1) 表不存在时兜底建表（已存在则为空操作，不会改动现有结构）
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL COMMENT '角色名称',
  `code` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '角色标识',
  `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '说明',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统角色';

-- 2) 幂等插入：按「角色名 或 标识」命中即跳过，绝不覆写既有配置
INSERT INTO `roles` (`name`, `code`, `description`)
SELECT '实习学生', 'intern_student', '实习学生：跟岗学习人员，模块权限需单独分配'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `roles` WHERE `name` = '实习学生' OR `code` = 'intern_student'
);

-- 3) 核对结果
SELECT `id`, `name`, `code`, `description` FROM `roles` ORDER BY `id` ASC;

-- 备注：
--   新角色的模块查看权限遵循系统 fail-closed 规则，默认「全部不可见」；
--   请在【系统管理 → 角色权限】中为「实习学生」勾选需要的模块后保存。
