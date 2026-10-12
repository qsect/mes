import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import request from '@/api/request';

export const useAuthStore = defineStore('auth', () => {
  // ★ 安全加固（L7）：不再把会话凭证（token）保存在 localStorage。
  //   会话完全由后端下发的 HttpOnly Cookie 维持——前端 JS 读不到，XSS 也偷不走。
  //   localStorage 仅保留「非敏感的用户展示信息」，用于路由守卫与界面渲染。
  const user = ref(JSON.parse(localStorage.getItem('user') || 'null'));
  // 当前用户实际可访问的菜单模块（来自后端 /auth/me）
  const menus = ref([]);
  // 权限体系当前可管控的全部模块路由；未纳入管控的菜单项保持默认可见
  const managedRoutes = ref([]);

  // 是否管理员：与后端判定保持一致（is_admin = 1 或账号名为 admin）
  const isAdmin = computed(() => {
    const u = user.value;
    if (!u) return false;
    return u.is_admin === 1 || u.is_admin === true || u.username === 'admin';
  });

  // 是否需要强制修改密码：首次登录或密码强度不达标（管理员豁免，由后端判定）
  const mustChangePassword = computed(() => {
    const u = user.value;
    if (!u) return false;
    return u.must_change_password === 1 || u.must_change_password === true;
  });

  // 是否已登录（本地判断，仅用于路由守卫快速放行；真正鉴权始终由后端完成）
  const isLoggedIn = computed(() => !!user.value);

  // 统一写入用户信息，并同步到 localStorage（供路由守卫与各页面读取）
  function setUser(u) {
    user.value = u || null;
    if (u) {
      localStorage.setItem('user', JSON.stringify(u));
    } else {
      localStorage.removeItem('user');
    }
  }

  async function login(username, password) {
    const res = await request.post('/auth/login', { username, password });
    // 会话 Cookie 已由后端 Set-Cookie 下发，前端无需保存任何凭证
    setUser(res.data.user);
  }

  async function fetchCurrentUser() {
    const res = await request.get('/auth/me');
    setUser(res.data.user);
    menus.value = res.data.menus || [];
    managedRoutes.value = res.data.managed_routes || [];
    return user.value;
  }

  // 修改密码成功后：清除本地"强制改密"标记
  function clearMustChangePassword() {
    if (user.value) {
      user.value = { ...user.value, must_change_password: 0 };
      localStorage.setItem('user', JSON.stringify(user.value));
    }
  }

  function logout() {
    user.value = null;
    menus.value = [];
    managedRoutes.value = [];
    localStorage.removeItem('user');
    localStorage.removeItem('token'); // 清理历史遗留的旧凭证（如曾存过）
  }

  return { user, menus, managedRoutes, isAdmin, isLoggedIn, mustChangePassword, login, fetchCurrentUser, clearMustChangePassword, logout };
});
