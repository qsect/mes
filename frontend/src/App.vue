<template>
  <el-config-provider :locale="zhCn">
    <!--
      ★ 强制改密硬闸门：
      只要当前账号处于「必须修改密码」状态（首次登录 / 密码强度不达标），
      整站只渲染改密页，不渲染任何业务页面，也就不存在"提醒了却找不到入口"的情况。
      用户改完密码后 mustChangePassword 变为 false，界面自动恢复正常。
    -->
    <ChangePassword v-if="authStore.mustChangePassword" />
    <router-view v-else />
  </el-config-provider>
</template>

<script setup>
import { ElConfigProvider } from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import { useAuthStore } from '@/stores/auth'
import ChangePassword from '@/views/ChangePassword.vue'

const authStore = useAuthStore()
</script>

<style>
html, body, #app {
  margin: 0;
  padding: 0;
  width: 100%;
  height: 100%;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  -webkit-font-smoothing: antialiased;
}
</style>
