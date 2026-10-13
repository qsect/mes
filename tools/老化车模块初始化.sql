-- ==================================================================
--  老化车维修管理模块 —— 初始化脚本
--
--  执行方式（服务器）：
--    mysql -uroot -p vite < /www/wwwroot/vite/tools/老化车模块初始化.sql
--    或在宝塔「数据库 → 导入」里执行本文件
--
--  幂等说明：模块登记用 WHERE NOT EXISTS 保护，重复执行不会产生重复记录。
--
--  ★ 数据表不需要手工建立：
--    aging_carts（老化车台账）、aging_repairs（报修维修记录）由后端在模块
--    首次被访问时自动创建，并自动初始化 001~140 共 140 辆台账。
--    本脚本只负责「模块登记」这一件后端不适合代劳的事（涉及权限表）。
-- ==================================================================

-- 1. 登记模块（菜单与权限分配里可见）
--    route 必须是前端路由去掉斜杠的形式：/aging-cart -> aging-cart
--    前端菜单还要在 frontend/src/layout/Layout.vue 的 menuGroups 里同步加一项，
--    两处缺一都会导致普通用户看不到入口（管理员不受影响）。
INSERT INTO `modules` (`name`, `module_code`, `category`, `icon`, `route`, `sort_order`, `description`, `status`)
SELECT '老化车维修管理', 'aging_cart', '生产执行', 'Bicycle', 'aging-cart', 30, '老化车报修、维修计时与维修看板', 1
WHERE NOT EXISTS (
  SELECT 1 FROM `modules` WHERE `module_code` = 'aging_cart'
);

-- 2.（可选）直接给某个角色授予查看权限
--    一般情况下请走页面：「系统管理 → 角色权限」或「权限分配」勾选，不必手写 SQL。
--    下面这段仅在需要批量初始化时使用，role_id 请按实际替换。
--
-- INSERT INTO `role_modules` (`role_id`, `module_id`, `can_view`)
-- SELECT r.id, m.id, 1
-- FROM `roles` r, `modules` m
-- WHERE r.role_code = 'operator' AND m.module_code = 'aging_cart'
--   AND NOT EXISTS (
--     SELECT 1 FROM `role_modules` rm WHERE rm.role_id = r.id AND rm.module_id = m.id
--   );

-- 3. 验证（执行后应能看到 1 行记录）
SELECT id, name, module_code, category, icon, route, sort_order, status
FROM `modules`
WHERE `module_code` = 'aging_cart';
