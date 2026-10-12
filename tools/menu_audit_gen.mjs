// 提取 Layout.vue 静态菜单路径，生成「模块登记对账」SQL
import fs from 'fs';

const s = fs.readFileSync('src/layout/Layout.vue', 'utf8');
const m = s.match(/const menuGroups = \{[\s\S]*?\n\};/);
if (!m) { console.log('未匹配 menuGroups'); process.exit(1); }

const re = /path:\s*'([^']+)'/g;
const paths = [];
let x;
while ((x = re.exec(m[0])) !== null) paths.push(x[1]);

console.log('静态菜单条目数: ' + paths.length);

// 用 UNION ALL 构造路径清单（MySQL 5.x / MariaDB / 8.0 均可用，不用 CTE）
const union = paths
  .map((p, i) => `  SELECT '${p}' AS p` + (i === 0 ? '' : ''))
  .join('\n  UNION ALL\n');

const sql = `-- ============================================================
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
${union}
) t
ORDER BY (hit = '(未登记)') DESC, t.p;
`;

fs.writeFileSync('menu_audit.sql', sql, 'utf8');
console.log('已生成: frontend/menu_audit.sql  (' + sql.split('\n').length + ' 行)');
