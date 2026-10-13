import { createRouter, createWebHistory } from 'vue-router';
import { ElMessage } from 'element-plus';
import Layout from '@/layout/Layout.vue';
import { useAuthStore } from '@/stores/auth';

// 仅管理员可访问的路由前缀
const ADMIN_ONLY_PREFIX = '/system';

// ★ 自助路由白名单：登录后人人可用，不参与模块权限管控（与后端保持一致）。
//   这些页面不在 modules 表登记的模块菜单里，若一并纳入 fail-closed 校验，
//   普通用户会被拦下并提示"没有该模块的访问权限"。
//   - dashboard      首页，登录后的默认落地页
//   - change-password 修改自己的密码（后端 /api/auth/change_password 未挂模块中间件）
const SELF_SERVICE_PATHS = new Set(['dashboard', 'login', 'change-password']);

// ★ 防刷屏守卫提示（2026-10-12）：
//   路由守卫在「重定向竞争」等异常情况下会被连续触发。若每次都弹 ElMessage，
//   提示框会在屏幕上快速堆叠、盖住整个表单并拦截鼠标点击，
//   表现为"页面锁死、连内容都选不中"（首次登录强制改密的死循环就是这样）。
//   这里按文案分别做时间窗去重，从表现层兜住这类失败模式。
//   注意：死循环里往往是「强制改密」与「无模块权限」两条提示交替出现，
//   所以必须用 Map 按文案独立计时，单变量记录会互相覆盖、去重失效。
const lastWarnAt = new Map();
function warnOnce(text, gapMs = 1500) {
  const now = Date.now();
  if (now - (lastWarnAt.get(text) || 0) < gapMs) return;
  lastWarnAt.set(text, now);
  ElMessage.warning(text);
}

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/Login.vue'),
    meta: { title: '登录' }
  },
  {
    // 强制改密 / 主动改密（独立页面，不套用主布局，未改密时无法离开）
    path: '/change-password',
    name: 'ChangePassword',
    component: () => import('@/views/ChangePassword.vue'),
    meta: { title: '修改密码' }
  },
  {
    path: '/',
    component: Layout,
    redirect: '/dashboard',
    children: [
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: () => import('@/views/Dashboard.vue'),
        meta: { title: '装置车间综合协作平台' }
      },
      // 质量追溯模块
      {
        path: 'trace',
        name: 'TraceSystem',
        component: () => import('@/views/trace/Index.vue'),
        meta: { title: '质量追溯系统' }
      },
      // 周完成情况统计与趋势看板
      {
        path: 'weekly',
        name: 'WeeklyProduction',
        component: () => import('@/views/weekly/Index.vue'),
        meta: { title: '周完成统计' }
      },
      // 问题排查记录系统
      {
        path: 'troubleshoot',
        name: 'TroubleshootRecords',
        component: () => import('@/views/troubleshoot/Index.vue'),
        meta: { title: '问题排查记录' }
      },
      // 实用工具箱模块
      {
        path: 'tools',
        name: 'ToolsManagement',
        component: () => import('@/views/tools/Index.vue'),
        meta: { title: '实用工具箱' }
      },
      // 技能矩阵管理
      {
        path: 'skill',
        name: 'SkillMatrix',
        component: () => import('@/views/skill/Index.vue'),
        meta: { title: '技能矩阵方案' }
      },
      // CEM 质量追溯查询模块
      {
        path: 'quality-trace',
        name: 'QualityTrace',
        component: () => import('@/views/quality_trace/Index.vue'),
        meta: { title: 'CEM质量追溯' }
      },
      // 质量问题记录系统
      {
        path: 'quality',
        name: 'QualityIssueManagement',
        component: () => import('@/views/quality/Index.vue'),
        meta: { title: '质量问题记录' }
      },
      // 工单进度与 SN 查询模块
      {
        path: 'progress',
        name: 'ProgressQuery',
        component: () => import('@/views/progress/Index.vue'),
        meta: { title: '工单进度查询' }
      },
      // 条码比对与验证工具
      {
        path: 'barcode-validator',
        name: 'BarcodeValidator',
        component: () => import('@/views/barcode/Index.vue'),
        meta: { title: '条码验证比对' }
      },
      // 资产台账管理系统
      {
        path: 'assets',
        name: 'AssetManagement',
        component: () => import('@/views/assets/Index.vue'),
        meta: { title: '资产台账管理' }
      },
      // 人员离岗监控
      {
        path: 'attendance',
        name: 'AttendanceMonitoring',
        component: () => import('@/views/attendance/Index.vue'),
        meta: { title: '人员离岗监控' }
      },
      // MAC 地址管理系统
      {
        path: 'mac',
        name: 'MacManagement',
        component: () => import('@/views/mac/Index.vue'),
        meta: { title: 'MAC 地址管理' }
      },
      // MAC / ID 信息追溯查询
      {
        path: 'mac-query',
        name: 'MacQuery',
        component: () => import('@/views/mac_query/Index.vue'),
        meta: { title: 'MAC/ID 信息查询' }
      },
      // 工作日记管理系统
      {
        path: 'diary',
        name: 'WorkDiary',
        component: () => import('@/views/diary/Index.vue'),
        meta: { title: '工作日记管理' }
      },
      // 工时汇总系统
      {
        path: 'manhour',
        name: 'ManhourSummary',
        component: () => import('@/views/manhour/Index.vue'),
        meta: { title: '工时汇总系统' }
      },
      // OEE 班组效率管理
      {
        path: 'oee',
        name: 'OeeManagement',
        component: () => import('@/views/oee/Index.vue'),
        meta: { title: '班组效率 (OEE)' }
      },
      // 外协管理系统
      {
        path: 'osm',
        name: 'OsmManagement',
        component: () => import('@/views/osm/Index.vue'),
        meta: { title: '外协管理系统' }
      },
      // 作业指导文件管理
      {
        path: 'zywj',
        name: 'ZywjManagement',
        component: () => import('@/views/zywj/Index.vue'),
        meta: { title: '作业指导文件 (SOP)' }
      },
      // 文件错误记录
      {
        path: 'doc-error',
        name: 'DocErrorRecord',
        component: () => import('@/views/doc_error/Index.vue'),
        meta: { title: '文件错误记录' }
      },
      // 北边返厂统计模块
      {
        path: 'beibian',
        name: 'BeibianReturn',
        component: () => import('@/views/beibian/Index.vue'),
        meta: { title: '北边返厂统计' }
      },
      // 设备矩阵总览模块
      {
        path: 'equipment',
        name: 'EquipmentMatrix',
        component: () => import('@/views/equipment/Index.vue'),
        meta: { title: '设备矩阵总览' }
      },
      // 老化车维修管理（注意：目录 aging_cart 下文件名是「大写」的 Index.vue，与导入保持一致）
      {
        path: 'aging-cart',
        name: 'AgingCart',
        component: () => import('@/views/aging_cart/Index.vue'),
        meta: { title: '老化车维修管理' }
      },
      // 首件检管理系统
      {
        path: 'first-piece',
        name: 'FirstPiece',
        component: () => import('@/views/first_piece/Index.vue'),
        meta: { title: '首件检管理' }
      },
      // 阻抗测试记录系统
      {
        path: 'impedance',
        name: 'ImpedanceRecord',
        component: () => import('@/views/impedance/Index.vue'),
        meta: { title: '阻抗测试记录' }
      },
      // 工单齐套查询模块
      {
        path: 'kitting',
        name: 'KittingQuery',
        component: () => import('@/views/kitting/Index.vue'),
        meta: { title: '工单齐套查询' }
      },
      // ★ 新增：装置包装配送管理与齐套看板
      {
        path: 'dispatch-kitting',
        name: 'DispatchKitting',
        component: () => import('@/views/dispatch_kitting/Index.vue'),
        meta: { title: '装置包装齐套看板' }
      },
      // 标签打印管理系统
      {
        path: 'label-print',
        name: 'LabelPrint',
        component: () => import('@/views/label_print/Index.vue'),
        meta: { title: '标签打印管理' }
      },
      // 讨论交流区
      {
        path: 'discuz',
        name: 'DiscuzForum',
        component: () => import('@/views/discuz/Index.vue'),
        meta: { title: '综合讨论交流区' }
      },
      // 文件共享
      {
        path: 'files',
        name: 'FileShare',
        component: () => import('@/views/files/Index.vue'),
        meta: { title: '文件共享' }
      },
      // 系统设置
      {
        path: 'system/users',
        name: 'SystemUsers',
        component: () => import('@/views/system/Users.vue'),
        meta: { title: '用户管理' }
      },
      {
        path: 'system/permissions',
        name: 'SystemPermissions',
        component: () => import('@/views/system/Permissions.vue'),
        meta: { title: '权限分配' }
      },
      {
        path: 'system/roles',
        name: 'SystemRoles',
        component: () => import('@/views/system/Roles.vue'),
        meta: { title: '角色权限' }
      },
      {
        path: 'system/modules',
        name: 'SystemModules',
        component: () => import('@/views/system/Modules.vue'),
        meta: { title: '模块管理' }
      },
      {
        path: 'system/audit-logs',
        name: 'SystemAuditLogs',
        component: () => import('@/views/system/AuditLogs.vue'),
        meta: { title: '审计日志' }
      },
      {
        path: 'system/client-version',
        name: 'SystemClientVersion',
        component: () => import('@/views/system/ClientVersion.vue'),
        meta: { title: '客户端版本' }
      }
    ]
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/dashboard'
  }
];

const router = createRouter({
  history: createWebHistory(),
  routes
});

// 归一化路由：去掉首尾斜杠，便于与后端 modules.route 对齐
const normalizePath = (p) => String(p || '').replace(/^\/+|\/+$/g, '');

router.beforeEach(async (to) => {
  const authStore = useAuthStore();
  // ★ L7：登录态由 HttpOnly Cookie（后端会话） + 本地非敏感用户信息共同体现，
  //   不再依赖 localStorage 中的 token。
  const loggedIn = !!authStore.user;

  // 未登录：除登录页外一律跳转登录
  if (to.path !== '/login' && !loggedIn) {
    return '/login';
  }

  // 已登录但尚未拿到用户/可访问菜单（例如刷新页面或登录后首次跳转）：补拉一次。
  // 用 menus.length 判断是否已拉取过（此前用 managedRoutes，该字段已不再参与权限判定）。
  if (loggedIn && to.path !== '/login' && (!authStore.user || !authStore.menus.length)) {
    try {
      await authStore.fetchCurrentUser();
    } catch (e) {
      // 会话失效由响应拦截器统一处理（清除本地信息并跳转登录页）
    }
  }

  // ★ 权限控制 1：系统管理相关路由仅管理员可访问
  if (to.path === ADMIN_ONLY_PREFIX || to.path.startsWith(ADMIN_ONLY_PREFIX + '/')) {
    if (!authStore.isAdmin) {
      warnOnce('权限不足，仅管理员可访问系统管理');
      return '/dashboard';
    }
  }

  // ★ 权限控制 2：强制改密。非管理员首次登录或密码强度不达标时，
  //   在完成改密之前只能停留在「修改密码」页（后端也会拦截其他接口）。
  if (loggedIn && to.path !== '/login' && to.path !== '/change-password' && authStore.mustChangePassword) {
    warnOnce('请先修改初始密码，设置符合规则的新密码后再使用系统');
    return '/change-password';
  }

  // ★ 权限控制 3：模块查看权限（fail-closed）
  //   前端可访问的模块 = 后端下发的 menus。凡是不在 menus 中的业务模块，
  //   即使知道地址直接访问也一律拦截。
  //   修复（2026-10-11）：此前仅在「已纳入管控(managed)」时才拦截，
  //   而 managedRoutes 为空（例如后端接口异常）会导致判断恒为 false → 全部放行，
  //   权限形同虚设。现改为：只要不在授权菜单里就拦截，不再依赖 managedRoutes。
  //   注意：后端 ModuleViewMiddleware 是真正的防线，此处仅做体验层的提前拦截。
  //   ★ 修复（2026-10-12）：change-password / dashboard 等自助路由不在 modules 表中，
  //     原本会被这条 fail-closed 校验一并拦掉 —— 表现为普通用户登录后点右上角
  //     「修改密码」提示"您没有该模块的访问权限"（首次登录时因为走 App.vue 的强制改密
  //     分支、不经过路由守卫，所以看起来是正常的）。现改用白名单放行。
  const pathKey = normalizePath(to.path);
  if (!authStore.isAdmin && pathKey && !SELF_SERVICE_PATHS.has(pathKey)) {
    const allowed = (authStore.menus || []).map((m) => normalizePath(m.path));
    if (!allowed.includes(pathKey)) {
      warnOnce('您没有该模块的访问权限');
      return '/dashboard';
    }
  }

  return true;
});

export default router;