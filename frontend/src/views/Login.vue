<template>
  <div class="login-wrapper">
    <div class="login-card">
      <div class="login-header">
        <h2>装置车间综合协作平台</h2>
        <p>制造执行与质量管控协作中心</p>
      </div>

      <el-form :model="loginForm" :rules="rules" ref="formRef" size="large" @keyup.enter="onSubmit">
        <el-form-item prop="username">
          <el-input 
            v-model="loginForm.username" 
            placeholder="请输入账号" 
            prefix-icon="User"
            autofocus 
          />
        </el-form-item>

        <el-form-item prop="password">
          <el-input 
            v-model="loginForm.password" 
            type="password" 
            placeholder="请输入密码" 
            prefix-icon="Lock" 
            show-password 
          />
        </el-form-item>

        <el-button 
          type="primary" 
          class="login-btn" 
          :loading="loading" 
          @click="onSubmit"
        >
          登 录
        </el-button>
      </el-form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ElMessage } from 'element-plus'
import { User, Lock } from '@element-plus/icons-vue'

const router = useRouter()
const authStore = useAuthStore()

const formRef = ref(null)
const loading = ref(false)

const loginForm = reactive({
  username: '',
  password: ''
})

const rules = {
  username: [{ required: true, message: '请输入工号或用户名', trigger: 'blur' }],
  password: [{ required: true, message: '请输入登录密码', trigger: 'blur' }]
}

const onSubmit = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    loading.value = true
    try {
      await authStore.login(loginForm.username, loginForm.password)

      // ★ 首次登录 / 密码强度不达标：先强制修改密码，再进入系统
      if (authStore.mustChangePassword) {
        ElMessage.warning('首次登录请先设置符合规则的新密码')
        router.push('/change-password')
        return
      }

      ElMessage.success('登录成功')
      router.push('/dashboard')
    } catch (err) {
      // 错误由 axios 拦截器统一弹出提示
    } finally {
      loading.value = false
    }
  })
}
</script>

<style scoped>
.login-wrapper {
  width: 100vw;
  height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
}
.login-card {
  width: 380px;
  padding: 40px 32px;
  background: #ffffff;
  border-radius: 8px;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
}
.login-header {
  text-align: center;
  margin-bottom: 28px;
}
.login-header h2 {
  margin: 0 0 8px 0;
  font-size: 20px;
  color: #1e293b;
}
.login-header p {
  margin: 0;
  font-size: 13px;
  color: #64748b;
}
.login-btn {
  width: 100%;
  margin-top: 8px;
  font-weight: bold;
}
</style>