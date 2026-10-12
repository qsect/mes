-- ============================================================
-- 静态菜单 vs modules 表 对账（在宝塔 phpMyAdmin 的 vite 库执行）
-- 目的：找出「侧边栏有入口、但 modules 表未登记或 route 不匹配」的模块。
--       这类模块对非管理员一律不可访问（前端守卫按 menus 白名单拦截）。
-- hit 列为 (未登记) 即为问题项。
-- 注：若报 Unknown column 'status'，把下面的 status 换成 is_visible。
-- ============================================================
SELECT t.p AS menu_path,
       COALESCE((
         SELECT GROUP_CONCAT(CONCAT(mo.module_code, '|route=', COALESCE(mo.route, '-'), '|status=', COALESCE(mo.status, '-')))
         FROM modules mo
         WHERE mo.route = t.p
            OR mo.module_code = TRIM(BOTH '/' FROM t.p)
            OR CONCAT('/', mo.module_code) = t.p
       ), '(未登记)') AS hit
FROM (
  SELECT '/progress' AS p
  UNION ALL
  SELECT '/kitting' AS p
  UNION ALL
  SELECT '/dispatch-kitting' AS p
  UNION ALL
  SELECT '/label-print' AS p
  UNION ALL
  SELECT '/equipment' AS p
  UNION ALL
  SELECT '/trace' AS p
  UNION ALL
  SELECT '/quality-trace' AS p
  UNION ALL
  SELECT '/barcode-validator' AS p
  UNION ALL
  SELECT '/first-piece' AS p
  UNION ALL
  SELECT '/impedance' AS p
  UNION ALL
  SELECT '/quality' AS p
  UNION ALL
  SELECT '/troubleshoot' AS p
  UNION ALL
  SELECT '/beibian' AS p
  UNION ALL
  SELECT '/doc-error' AS p
  UNION ALL
  SELECT '/zywj' AS p
  UNION ALL
  SELECT '/mac' AS p
  UNION ALL
  SELECT '/mac-query' AS p
  UNION ALL
  SELECT '/osm' AS p
  UNION ALL
  SELECT '/assets' AS p
  UNION ALL
  SELECT '/attendance' AS p
  UNION ALL
  SELECT '/diary' AS p
  UNION ALL
  SELECT '/manhour' AS p
  UNION ALL
  SELECT '/skill' AS p
  UNION ALL
  SELECT '/weekly' AS p
  UNION ALL
  SELECT '/oee' AS p
  UNION ALL
  SELECT '/tools' AS p
  UNION ALL
  SELECT '/discuz' AS p
  UNION ALL
  SELECT '/files' AS p
  UNION ALL
  SELECT '/system/users' AS p
  UNION ALL
  SELECT '/system/permissions' AS p
  UNION ALL
  SELECT '/system/roles' AS p
  UNION ALL
  SELECT '/system/modules' AS p
  UNION ALL
  SELECT '/system/audit-logs' AS p
  UNION ALL
  SELECT '/system/client-version' AS p
) t
ORDER BY (hit = '(未登记)') DESC, t.p;
