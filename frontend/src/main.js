import { createApp } from 'vue';
import { createPinia } from 'pinia';
import ElementPlus from 'element-plus';
import 'element-plus/dist/index.css';
import zhCn from 'element-plus/es/locale/lang/zh-cn';

import App from './App.vue';
import router from './router';
import { sanitizeHtml } from './utils/sanitizeHtml';

const app = createApp(App);
app.use(createPinia());
app.use(router);
app.use(ElementPlus, { locale: zhCn });

// ★ 安全加固（H3）：注册 v-safe-html 指令，替代所有 v-html，
//   对渲染内容做白名单净化，防止讨论区/公告的存储型 XSS。
app.directive('safe-html', {
  mounted(el, binding) {
    el.innerHTML = sanitizeHtml(binding.value);
  },
  updated(el, binding) {
    if (binding.value === binding.oldValue) return;
    el.innerHTML = sanitizeHtml(binding.value);
  }
});

app.mount('#app');