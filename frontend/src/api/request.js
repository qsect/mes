import axios from 'axios';
import { ElMessage } from 'element-plus';
import router from '@/router';

const service = axios.create({
  baseURL: '/api',
  timeout: 15000
});

// 请求拦截：★ L7 会话已 Cookie 化——凭证由浏览器自动携带的 HttpOnly Cookie 提供，
// 前端不再手动附带任何 token（即便发生 XSS 也无从 localStorage 窃取会话）。
service.interceptors.request.use((config) => config);

// 响应拦截：处理业务成功与错误提示
service.interceptors.response.use(
  (response) => {
    const res = response.data;
    if (!res.success) {
      ElMessage.error(res.message || '系统错误');
      return Promise.reject(new Error(res.message || 'Error'));
    }
    return res;
  },
  (error) => {
    // 关键优化：优先读取后端返回的友好提示信息（如“账号或密码错误”）
    const errorMsg = error.response?.data?.message;
    const bizCode = error.response?.data?.data?.code;

    // ★ 强制改密：后端拦截到未改密会话访问业务接口，统一跳转到修改密码页
    if (error.response && error.response.status === 403 && bizCode === 'PASSWORD_CHANGE_REQUIRED') {
      ElMessage.warning(errorMsg || '请先修改初始密码后再使用系统');
      if (router.currentRoute.value.path !== '/change-password') {
        router.push('/change-password');
      }
      return Promise.reject(error);
    }

    if (error.response && error.response.status === 401) {
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      ElMessage.warning(errorMsg || '登录已失效，请重新登录');
      router.push('/login');
    } else if (errorMsg) {
      ElMessage.error(errorMsg);
    } else {
      ElMessage.error(error.message || '网络连接异常');
    }
    return Promise.reject(error);
  }
);

export default service;