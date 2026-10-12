<template>
  <el-container class="layout-container">
    <el-aside width="220px" class="layout-aside">
      <div class="logo">
        <i class="el-icon-cpu"></i> 装置车间制造平台
      </div>
      <el-menu 
        default-active="/dashboard" 
        router 
        background-color="#304156" 
        text-color="#bfcbd9" 
        active-text-color="#409EFF"
      >
        <el-menu-item index="/dashboard">
          <el-icon>
            <component :is="Odometer" />
          </el-icon>
          <span>系统工作台</span>
        </el-menu-item>
        <!-- 后续业务模块扩展点 -->
      </el-menu>
    </el-aside>
    <el-container>
      <el-header class="layout-header">
        <div class="header-left">
          <span class="status-indicator"></span> 产线系统在线
        </div>
        <div class="header-right">
          <span class="user-name">{{ authStore.user?.full_name || '操作员' }}</span>
          <el-button type="danger" link @click="handleLogout">退出</el-button>
        </div>
      </el-header>
      <el-main class="layout-main">
        <router-view />
      </el-main>
    </el-container>
  </el-container>
</template>

<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { Odometer } from '@element-plus/icons-vue'

const authStore = useAuthStore()
const router = useRouter()

const handleLogout = () => {
  authStore.logout()
  router.push('/login')
}
</script>

<style scoped>
.layout-container {
  height: 100vh;
}
.layout-aside {
  background-color: #304156;
  color: #fff;
}
.logo {
  height: 50px;
  line-height: 50px;
  text-align: center;
  font-weight: bold;
  background: #263445;
  color: #ffffff;
}
.layout-header {
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #e6e6e6;
  padding: 0 20px;
}
.header-right {
  display: flex;
  align-items: center;
  gap: 12px;
}
.status-indicator {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #67C23A;
  margin-right: 6px;
}
.layout-main {
  background-color: #f0f2f5;
  padding: 16px;
}
</style>