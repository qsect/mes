<template>
  <div class="cp-wrapper">
    <div class="cp-card">
      <div class="cp-header">
        <h2>修改登录密码</h2>
        <p v-if="forced">
          为了账号安全，首次登录（或密码强度不足）必须先修改密码
        </p>
        <p v-else>定期更换密码可以有效保护您的账号安全</p>
      </div>

      <el-alert
        v-if="forced"
        type="warning"
        :closable="false"
        show-icon
        title="未完成密码修改前，系统其他功能将不可用"
        style="margin-bottom:16px;"
      />

      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @keyup.enter="onSubmit">
        <el-form-item label="当前密码" prop="old_password">
          <el-input
            v-model="form.old_password"
            type="password"
            placeholder="请输入当前使用的密码（初始密码为 123456）"
            :prefix-icon="Lock"
            show-password
          />
        </el-form-item>

        <el-form-item label="新密码" prop="new_password">
          <el-input
            v-model="form.new_password"
            type="password"
            placeholder="请设置符合下方规则的新密码"
            :prefix-icon="Key"
            show-password
          />
          <div class="cp-rules">
            <span v-for="r in ruleList" :key="r.key" :class="['cp-rule', r.ok ? 'ok' : '']">
              <el-icon><CircleCheckFilled v-if="r.ok" /><CircleClose v-else /></el-icon>
              {{ r.text }}
            </span>
          </div>
        </el-form-item>

        <el-form-item label="确认新密码" prop="confirm_password">
          <el-input
            v-model="form.confirm_password"
            type="password"
            placeholder="请再次输入新密码"
            :prefix-icon="Key"
            show-password
          />
        </el-form-item>

        <el-button type="primary" class="cp-btn" :loading="loading" @click="onSubmit">
          确认修改
        </el-button>

        <div class="cp-footer">
          <!-- 非强制模式下（用户主动点右上角进来）给一个返回出口，
               否则只能改密或退出登录 -->
          <el-button v-if="!forced" link type="primary" @click="router.push('/dashboard')">返回</el-button>
          <el-button link type="info" @click="handleLogout">退出登录</el-button>
        </div>
      </el-form>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Lock, Key, CircleCheckFilled, CircleClose } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import request from '@/api/request'

const router = useRouter()
const authStore = useAuthStore()

const formRef = ref(null)
const loading = ref(false)

const forced = computed(() => authStore.mustChangePassword)

const form = reactive({
  old_password: '',
  new_password: '',
  confirm_password: ''
})

const hasLower = (v) => /[a-z]/.test(v)
const hasUpper = (v) => /[A-Z]/.test(v)
const hasDigit = (v) => /[0-9]/.test(v)
const hasSpecial = (v) => /[^A-Za-z0-9]/.test(v)

const ruleList = computed(() => [
  { key: 'len', text: '长度不少于 8 位', ok: String(form.new_password || '').length >= 8 },
  { key: 'upper', text: '包含大写字母', ok: hasUpper(form.new_password || '') },
  { key: 'lower', text: '包含小写字母', ok: hasLower(form.new_password || '') },
  { key: 'digit', text: '包含数字', ok: hasDigit(form.new_password || '') },
  { key: 'special', text: '包含特殊字符', ok: hasSpecial(form.new_password || '') }
])

const isStrong = (v) => ruleList.value.every((r) => r.ok)

const validateNewPassword = (rule, value, callback) => {
  if (!value) return callback(new Error('请输入新密码'))
  if (!isStrong(value)) return callback(new Error('新密码必须不少于 8 位，且同时包含大写字母、小写字母、数字和特殊字符'))
  if (value === form.old_password) return callback(new Error('新密码不能与原密码相同'))
  callback()
}

const validateConfirm = (rule, value, callback) => {
  if (!value) return callback(new Error('请再次输入新密码'))
  if (value !== form.new_password) return callback(new Error('两次输入的新密码不一致'))
  callback()
}

const rules = {
  old_password: [{ required: true, message: '请输入当前密码', trigger: 'blur' }],
  new_password: [{ required: true, validator: validateNewPassword, trigger: ['blur', 'change'] }],
  confirm_password: [{ required: true, validator: validateConfirm, trigger: 'blur' }]
}

const onSubmit = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    loading.value = true
    try {
      const res = await request.post('/auth/change_password', {
        old_password: form.old_password,
        new_password: form.new_password,
        confirm_password: form.confirm_password
      })
      ElMessage.success(res.message || '密码修改成功')
      authStore.clearMustChangePassword()
      router.replace('/dashboard')
    } catch (err) {
      // 错误由 axios 拦截器统一提示；若会话已失效则退出到登录页，
      // 避免残留在"待改密"状态却无法重新登录。
      if (err && err.response && err.response.status === 401) {
        authStore.logout()
        router.replace('/login')
      }
    } finally {
      loading.value = false
    }
  })
}

const handleLogout = async () => {
  try {
    await request.post('/auth/logout')
  } catch (e) {
    // 忽略
  }
  authStore.logout()
  router.replace('/login')
}
</script>

<style scoped>
.cp-wrapper {
  width: 100vw;
  height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
}
.cp-card {
  width: 440px;
  max-height: 92vh;
  overflow: auto;
  padding: 34px 32px;
  background: #ffffff;
  border-radius: 8px;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
}
.cp-header {
  text-align: center;
  margin-bottom: 20px;
}
.cp-header h2 {
  margin: 0 0 8px 0;
  font-size: 20px;
  color: #1e293b;
}
.cp-header p {
  margin: 0;
  font-size: 13px;
  color: #64748b;
}
.cp-btn {
  width: 100%;
  margin-top: 4px;
  font-weight: bold;
}
.cp-footer {
  text-align: center;
  margin-top: 12px;
}
.cp-rules {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 14px;
  margin-top: 8px;
  font-size: 12px;
  color: #909399;
}
.cp-rule {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.cp-rule.ok {
  color: #67c23a;
}
</style>
