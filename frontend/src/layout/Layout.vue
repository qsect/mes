<template>
  <el-container class="app-layout">
    <el-aside width="240px" class="aside-menu">
      <div class="logo-box">
        <img :src="logoImg" alt="Logo" class="logo-img" />
      </div>

      <div class="sidebar-menu-tree">
        <!-- 仪表盘（直达，不参与折叠） -->
        <router-link to="/dashboard" class="menu-direct-item" active-class="active">
          <el-icon><Odometer /></el-icon>
          <span>系统首页</span>
        </router-link>

        <!-- 分组菜单（手风琴） -->
        <div
          v-for="(group, gKey) in visibleMenuGroups"
          :key="gKey"
          class="menu-category-group"
          :class="{ open: openGroup === gKey }"
        >
          <div class="menu-category-header" @click="toggleGroup(gKey)">
            <span class="cat-title">
              <el-icon class="cat-icon"><component :is="iconMap[group.icon] || Menu" /></el-icon>
              {{ group.label }}
            </span>
            <span class="cat-right">
              <span class="cat-count">{{ group.items.length }}</span>
              <el-icon class="arrow-icon"><ArrowRight /></el-icon>
            </span>
          </div>
          <div class="menu-sub-items">
            <router-link
              v-for="item in group.items"
              :key="item.path"
              :to="item.path"
              class="menu-sub-item"
              active-class="active"
            >
              <el-icon><component :is="iconMap[item.icon] || Menu" /></el-icon>
              <span>{{ item.label }}</span>
            </router-link>
          </div>
        </div>
      </div>

      <!-- 底部版本号 -->
      <div class="sidebar-footer">
        <span class="version-tag">V3.0</span>
      </div>
    </el-aside>

    <el-container>
      <el-header class="app-header">
        <div class="header-left">
          <span class="page-title">{{ $route.meta.title || '系统管理' }}</span>
        </div>
        <div class="header-right">
          <span class="user-greeting">欢迎，{{ username }}</span>
          <el-button type="primary" link @click="handleChangePassword">
            <el-icon class="mr-1"><Key /></el-icon> 修改密码
          </el-button>
          <el-button type="danger" link @click="handleLogout">退出登录</el-button>
        </div>
      </el-header>
      <el-main class="app-main">
        <router-view />
      </el-main>
    </el-container>
  </el-container>
</template>

<script setup>
import { ref, onMounted, watch, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessageBox, ElMessage } from 'element-plus';
import { useAuthStore } from '@/stores/auth';
import {
  Cpu, Odometer, Checked, Select, Box, Timer, Connection, Notebook, Calendar,
  Van, Operation, Crop, Files, Printer, ChatLineRound, Setting, User, Lock, Key,
  Menu, Document, DocumentChecked, Search, TrendCharts, Tickets, WarningFilled,
  Grid, Tools, HelpFilled, DataAnalysis, ArrowRight, FolderOpened,
  // 老化车（Bicycle）与客户端版本（Upload）：菜单图标必须在这里登记，
  // 否则 iconMap 取不到组件，菜单项会静默显示成「没有图标」（不报错）。
  Bicycle, Upload
} from '@element-plus/icons-vue';
import request from '@/api/request';

import logoImg from '@/assets/img/logo.png';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();

// 当前登录用户显示名（优先真实姓名，其次账号名）
const username = computed(() => {
  const u = authStore.user || {};
  return u.full_name || u.username || '';
});

// 是否管理员（与后端 is_admin 判定保持一致）
const isAdmin = computed(() => authStore.isAdmin);

const iconMap = {
  Cpu, Odometer, Checked, Select, Box, Timer, Connection, Notebook, Calendar,
  Van, Operation, Crop, Files, Printer, ChatLineRound, Setting, User, Lock, Key,
  Menu, Document, DocumentChecked, Search, TrendCharts, Tickets, WarningFilled,
  Grid, Tools, HelpFilled, DataAnalysis, FolderOpened,
  Bicycle, Upload
};

const menuGroups = {
  production: {
    label: '生产执行',
    icon: 'Operation',
    items: [
      { path: '/progress',         label: '工单进度查询',   icon: 'Tickets' },
      { path: '/kitting',          label: '工单齐套查询',   icon: 'Files' },
      { path: '/dispatch-kitting',  label: '包装配送齐套',   icon: 'Box' },
      { path: '/label-print',      label: '标签打印管理',   icon: 'Printer' },
      { path: '/equipment',        label: '设备矩阵总览',   icon: 'Operation' }
    ]
  },
  quality: {
    label: '质量与检验',
    icon: 'Checked',
    items: [
      { path: '/trace',             label: '质量追溯系统',   icon: 'Checked' },
      { path: '/quality-trace',     label: 'CEM质量追溯',    icon: 'Search' },
      { path: '/barcode-validator', label: '条码验证比对',   icon: 'Select' },
      { path: '/first-piece',       label: '首件检管理',     icon: 'Crop' },
      { path: '/impedance',         label: '阻抗测试记录',   icon: 'Odometer' }
    ]
  },
  issue: {
    label: '质量异常处理',
    icon: 'WarningFilled',
    items: [
      { path: '/quality',      label: '质量问题记录',       icon: 'WarningFilled' },
      { path: '/troubleshoot', label: '问题排查记录',       icon: 'HelpFilled' },
      { path: '/beibian',      label: '北边返厂统计',       icon: 'Van' },
      { path: '/doc-error',    label: '文件错误记录',       icon: 'DocumentChecked' },
      { path: '/zywj',         label: '作业指导文件',       icon: 'Document' }
    ]
  },
  material: {
    label: '物料与标识',
    icon: 'Connection',
    items: [
      { path: '/mac',        label: 'MAC 地址管理',       icon: 'Connection' },
      { path: '/mac-query',  label: 'MAC/ID 信息查询',    icon: 'Search' }
    ]
  },
  asset: {
    label: '外协与资产',
    icon: 'Box',
    items: [
      { path: '/osm',        label: '外协管理系统',   icon: 'Van' },
      { path: '/assets',     label: '资产台账管理',   icon: 'Box' },
      { path: '/aging-cart', label: '老化车维修管理', icon: 'Bicycle' }
    ]
  },
  hr: {
    label: '人员与工时',
    icon: 'User',
    items: [
      { path: '/attendance', label: '人员离岗监控',   icon: 'Timer' },
      { path: '/diary',      label: '工作日记管理',   icon: 'Notebook' },
      { path: '/manhour',    label: '工时汇总系统',   icon: 'Calendar' },
      { path: '/skill',      label: '技能矩阵方案',   icon: 'Grid' }
    ]
  },
  report: {
    label: '统计看板',
    icon: 'DataAnalysis',
    items: [
      { path: '/weekly', label: '周完成统计',         icon: 'DataAnalysis' },
      { path: '/oee',    label: '班组效率',           icon: 'TrendCharts' }
    ]
  },
  tools: {
    label: '协作与工具',
    icon: 'Tools',
    items: [
      { path: '/tools',  label: '实用工具箱',   icon: 'Tools' },
      { path: '/discuz', label: '讨论交流区',   icon: 'ChatLineRound' },
      { path: '/files',  label: '文件共享',     icon: 'FolderOpened' }
    ]
  },
  system: {
    label: '系统管理',
    icon: 'Setting',
    items: [
      { path: '/system/users',       label: '用户管理',   icon: 'User' },
      { path: '/system/permissions', label: '权限分配',   icon: 'Key' },
      { path: '/system/roles',       label: '角色权限',   icon: 'Lock' },
      { path: '/system/modules',     label: '模块管理',   icon: 'Menu' },
      { path: '/system/audit-logs',  label: '审计日志',   icon: 'Document' },
      { path: '/system/client-version', label: '客户端版本', icon: 'Upload' }
    ]
  }
};

const openGroup = ref('');

// 归一化路由：去掉首尾斜杠，便于与后端 modules.route 对齐
const normalizePath = (p) => String(p || '').replace(/^\/+|\/+$/g, '');

// 当前用户可访问的路由集合
const allowedSet = computed(() => new Set((authStore.menus || []).map(m => normalizePath(m.path))));

// ★ 权限控制（fail-closed，2026-10-11 修复）：
//   1) 「系统管理」分组仅管理员可见；
//   2) 菜单项一律以后端授权的可访问路由（allowedSet）为准 —— 未被授权就不显示。
//   修复背景：此前写作「仅当该路由已被权限体系管控(managedSet)且未授权时才隐藏」，
//   条件是 `!managed || allowed`。一旦 managedRoutes 为空（后端 /auth/me 异常等），
//   所有项都会被判为「未纳入管控」而**全部显示**，与"取消勾选却仍可见"的现象一致。
//   现改为严格以 allowedSet 为准，不再依赖 managedRoutes。
const visibleMenuGroups = computed(() => {
  const out = {};
  for (const gKey of Object.keys(menuGroups)) {
    if (gKey === 'system' && !isAdmin.value) continue;

    const group = menuGroups[gKey];
    const items = group.items.filter((it) => {
      const p = normalizePath(it.path);
      return allowedSet.value.has(p);
    });
    if (items.length > 0) {
      out[gKey] = { ...group, items };
    }
  }
  return out;
});

function toggleGroup(gKey) {
  openGroup.value = openGroup.value === gKey ? '' : gKey;
}

function syncGroupWithRoute() {
  const path = route.path;
  for (const gKey of Object.keys(visibleMenuGroups.value)) {
    if (visibleMenuGroups.value[gKey].items.some(it => it.path === path)) {
      openGroup.value = gKey;
      return;
    }
  }
}

onMounted(async () => {
  // 拉取当前用户与可访问菜单，确保权限判定准确（每次挂载都刷新一次）
  if (authStore.isLoggedIn) {
    try {
      await authStore.fetchCurrentUser();
    } catch (e) {}
  }
  syncGroupWithRoute();
});

watch(() => route.path, syncGroupWithRoute);

const handleChangePassword = () => {
  router.push('/change-password');
};

const handleLogout = () => {
  ElMessageBox.confirm('确定要退出登录吗？', '提示', {
    type: 'warning',
    confirmButtonText: '确定',
    cancelButtonText: '取消'
  }).then(async () => {
    try {
      await request.post('/auth/logout');
    } catch (e) {}
    authStore.logout();
    ElMessage.success('已安全退出');
    router.push('/login');
  });
};
</script>

<style scoped>
.app-layout {
  height: 100vh;
  width: 100vw;
}

/* ============ 侧边栏 ============ */
.aside-menu {
  background-color: #3b4f66;
  border-right: 1px solid #2a3b4c;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* ============ Logo 区 ============ */
.logo-box {
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 16px;
  background-color: #141f2c;
  flex-shrink: 0;
  overflow: hidden;
}
.logo-img {
  height: 38px;
  width: auto;
  max-width: 100%;
  object-fit: contain;
  display: block;
}

.sidebar-menu-tree {
  padding: 8px 0;
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
}
.sidebar-menu-tree::-webkit-scrollbar {
  width: 6px;
}
.sidebar-menu-tree::-webkit-scrollbar-thumb {
  background: #4a5f78;
  border-radius: 3px;
}
.sidebar-menu-tree::-webkit-scrollbar-track {
  background: transparent;
}

/* ============ 侧边栏底部版本号 ============ */
.sidebar-footer {
  flex-shrink: 0;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #2a3b4c;
  border-top: 1px solid #1f2d3c;
  color: #8aa0b8;
  font-size: 12px;
  letter-spacing: 1px;
  user-select: none;
}
.version-tag {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 10px;
  background: rgba(96, 165, 250, 0.12);
  color: #60a5fa;
  font-weight: 600;
  font-size: 11.5px;
}

/* 仪表盘直达项 */
.menu-direct-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 11px 18px;
  font-size: 13.5px;
  font-weight: 600;
  color: #d1dbe6;
  text-decoration: none;
  border-left: 3px solid transparent;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  transition: all 0.2s;
  cursor: pointer;
}
.menu-direct-item :deep(.el-icon) {
  color: #8aa0b8;
  font-size: 14px;
  width: 18px;
  text-align: center;
}
.menu-direct-item:hover {
  background: #2f4054;
  color: #ffffff;
}
.menu-direct-item.active {
  color: #ffffff;
  background: #2563eb;
  border-left-color: #60a5fa;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}
.menu-direct-item.active :deep(.el-icon) {
  color: #ffffff;
}

/* 分组容器 */
.menu-category-group {
  margin-bottom: 2px;
}

.menu-category-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 11px 18px;
  font-size: 13.5px;
  font-weight: 700;
  color: #d1dbe6;
  cursor: pointer;
  user-select: none;
  transition: all 0.2s;
  border-left: 3px solid transparent;
  border-bottom: 1px solid transparent;
}
.menu-category-header:hover {
  background: #2f4054;
  color: #ffffff;
}
.menu-category-header .cat-title {
  display: flex;
  align-items: center;
  gap: 10px;
}
.menu-category-header :deep(.cat-icon) {
  color: #8aa0b8;
  font-size: 14px;
  width: 18px;
  text-align: center;
}

.cat-right {
  display: flex;
  align-items: center;
  gap: 8px;
}
.cat-count {
  display: inline-block;
  min-width: 18px;
  height: 18px;
  line-height: 18px;
  padding: 0 5px;
  font-size: 10.5px;
  font-weight: 700;
  color: #8aa0b8;
  background: #2a3b4c;
  border-radius: 9px;
  text-align: center;
}
.menu-category-header:hover .cat-count {
  color: #d1dbe6;
  background: #3b4f66;
}
.menu-category-header :deep(.arrow-icon) {
  font-size: 11px;
  transition: transform 0.25s ease;
  color: #6b85a0;
}

.menu-category-group.open .menu-category-header {
  color: #ffffff;
  background: #2f4054;
  border-left-color: #60a5fa;
  border-bottom: 2px solid #1f2d3c;
}
.menu-category-group.open .menu-category-header :deep(.arrow-icon) {
  transform: rotate(90deg);
  color: #60a5fa;
}
.menu-category-group.open .menu-category-header :deep(.cat-icon) {
  color: #60a5fa;
}
.menu-category-group.open .cat-count {
  color: #60a5fa;
  background: #1f2d3c;
}

.menu-sub-items {
  display: none;
  background: #2a3b4c;
  padding: 6px 8px 8px 8px;
  border-bottom: 1px solid #1f2d3c;
}
.menu-category-group.open .menu-sub-items {
  display: block;
  animation: slideDown 0.2s ease;
}
@keyframes slideDown {
  from { opacity: 0; transform: translateY(-4px); }
  to   { opacity: 1; transform: translateY(0); }
}

.menu-sub-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 12px 7px 28px;
  margin: 2px 0;
  font-size: 13px;
  font-weight: 500;
  color: #b8c9db;
  text-decoration: none;
  border-radius: 6px;
  transition: all 0.15s;
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.menu-sub-item :deep(.el-icon) {
  color: #6b85a0;
  font-size: 12px;
  width: 16px;
  text-align: center;
  transition: color 0.15s;
  flex-shrink: 0;
}
.menu-sub-item span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.menu-sub-item:hover {
  color: #ffffff;
  background: #3b4f66;
}
.menu-sub-item:hover :deep(.el-icon) {
  color: #b8c9db;
}
.menu-sub-item.active {
  color: #ffffff !important;
  background: #2563eb !important;
  font-weight: 600;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.25);
}
.menu-sub-item.active :deep(.el-icon) {
  color: #ffffff !important;
}

/* ============ 顶栏 / 主内容 ============ */
.app-header {
  height: 60px;
  background-color: #fff;
  border-bottom: 1px solid #e6e6e6;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
}
.page-title {
  font-size: 18px;
  font-weight: 600;
  color: #303133;
}
.user-greeting {
  margin-right: 16px;
  font-size: 14px;
  color: #606266;
}
.app-main {
  background-color: #f5f7fa;
  padding: 20px;
}
</style>