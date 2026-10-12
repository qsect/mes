<template>
  <div class="sys-container">
    <!-- 顶部统计横幅 -->
    <div class="sys-stat-banner">
      <div>
        <div style="font-size:13px; color:#6c757d; font-weight:500;">系统在册用户总数</div>
        <div style="font-size:28px; font-weight:bold; color:#007bff; line-height:1.1; margin-top:2px;">{{ tableData.length }}</div>
      </div>
      <div style="font-size:12px; color:#6c757d; max-width:520px;">
        支持为人员分配 <strong>装配/调试/包装班长、技工、实习学生</strong> 等多维度岗位角色与所属班组。
      </div>
    </div>

    <!-- 用户清单卡片 -->
    <div class="sys-card">
      <div class="sys-card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:16px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:4px;"><User /></el-icon>
          系统用户与岗位角色管理
        </span>
        <div style="display:flex; gap:8px; align-items:center;">
          <button type="button" class="btn-warning btn-sm" @click="resetAllPasswords">
            <el-icon class="mr-1"><RefreshRight /></el-icon> 一键重置密码为 {{ DEFAULT_PASSWORD }}
          </button>
          <button type="button" class="btn-success btn-sm" @click="handleOpenDialog()">
            <el-icon class="mr-1"><Plus /></el-icon> 新建用户
          </button>
        </div>
      </div>

      <!-- 筛选行 -->
      <div class="sys-filter-card">
        <div style="display:flex; gap:10px; align-items:center;">
          <input
            v-model="keyword"
            type="text"
            class="sys-input"
            style="width:280px;"
            placeholder="搜索工号 / 姓名 / 账号..."
            @keyup.enter="loadData"
          />
          <button type="button" class="btn-primary btn-sm" @click="loadData">
            <el-icon class="mr-1"><Search /></el-icon> 查询
          </button>
          <button type="button" class="btn-outline btn-sm" @click="resetFilter">重置</button>
        </div>
      </div>

      <!-- 表格 -->
      <div class="sys-table-wrapper" v-loading="loading">
        <table class="sys-table">
          <thead>
            <tr>
              <th style="width:120px;">公司ID/工号</th>
              <th style="width:130px;">用户名</th>
              <th style="width:120px;">真实姓名</th>
              <th style="width:130px;">所属班组</th>
              <th style="width:150px;">岗位角色</th>
              <th style="width:110px; text-align:center;">权限等级</th>
              <th style="width:100px; text-align:center;">账号状态</th>
              <th>联系电话</th>
              <th style="width:150px; text-align:center;">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in tableData" :key="u.id">
              <td>
                <strong v-if="u.badge_no" style="color:#007bff;">{{ u.badge_no }}</strong>
                <span v-else style="color:#888;">{{ u.id }}</span>
              </td>
              <td>
                <strong>{{ u.username }}</strong>
                <span v-if="u.must_change_password" class="badge" style="background:#fff3cd; color:#856404; margin-left:5px;">待改密</span>
              </td>
              <td><strong>{{ u.full_name || '-' }}</strong></td>
              <td><span class="badge badge-team">{{ u.team || '全部' }}</span></td>
              <td><span class="badge" :style="roleBadgeStyle(u)">{{ u.role_name || '普通员工' }}</span></td>
              <td style="text-align:center;">
                <span class="badge" :style="u.is_admin ? 'background:#dc3545; color:#fff;' : 'background:#f1f5f9; color:#475569;'">
                  {{ u.is_admin ? '管理员' : '普通用户' }}
                </span>
              </td>
              <td style="text-align:center;">
                <span :style="{ color: u.is_active ? '#28a745' : '#dc3545', fontWeight: 'bold' }">
                  {{ u.is_active ? '正常' : '禁用' }}
                </span>
              </td>
              <td>{{ u.phone || '-' }}</td>
              <td style="text-align:center; white-space:nowrap;">
                <button type="button" class="btn-primary btn-mini" @click="handleOpenDialog(u)">编辑</button>
                <button type="button" class="btn-danger btn-mini" style="margin-left:4px;" @click="deleteUser(u)">删除</button>
              </td>
            </tr>
            <tr v-if="tableData.length === 0 && !loading">
              <td colspan="9" style="text-align:center; padding:35px; color:#888;">暂无用户数据</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 用户编辑/新建弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? `编辑用户 [${form.username}]` : '新建用户账号'" width="620px" destroy-on-close>
      <div style="padding:10px 14px;">
        <div class="form-grid-2">
          <div>
            <label class="form-label">公司ID / 工号</label>
            <input v-model="form.badge_no" type="text" class="sys-input" style="width:100%;" placeholder="自定义公司内部ID (如: 20280)" />
          </div>
          <div>
            <label class="form-label">用户名 (登录账号) *</label>
            <input v-model="form.username" type="text" class="sys-input" style="width:100%;" :disabled="!!form.id" placeholder="登录账号" />
          </div>
          <div>
            <label class="form-label">真实姓名 *</label>
            <input v-model="form.full_name" type="text" class="sys-input" style="width:100%;" placeholder="姓名" />
          </div>
          <div>
            <label class="form-label">所属班组 *</label>
            <select v-model="form.team" class="sys-select" style="width:100%;">
              <option v-for="t in teamOptions" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div>
            <label class="form-label">岗位角色 *</label>
            <select v-model="form.role_id" class="sys-select" style="width:100%;">
              <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
          </div>
          <div>
            <label class="form-label">联系电话</label>
            <input v-model="form.phone" type="text" class="sys-input" style="width:100%;" placeholder="手机号 / 分机号" />
          </div>
          <div>
            <label class="form-label">登录密码</label>
            <input v-model="form.password" type="password" class="sys-input" style="width:100%;" :placeholder="passwordPlaceholder" />
            <div style="font-size:11.5px; color:#909399; margin-top:4px; line-height:1.5;">
              统一初始密码为 <strong>{{ DEFAULT_PASSWORD }}</strong>；非管理员账号首次登录时必须自行修改为
              「8 位以上 + 大写 + 小写 + 数字 + 特殊字符」的合规密码。
            </div>
          </div>
          <div>
            <label class="form-label">系统权限等级</label>
            <select v-model="form.is_admin" class="sys-select" style="width:100%;">
              <option :value="0">普通用户</option>
              <option :value="1">超级管理员</option>
            </select>
          </div>
          <div>
            <label class="form-label">账号状态</label>
            <select v-model="form.is_active" class="sys-select" style="width:100%;">
              <option :value="1">正常启用</option>
              <option :value="0">禁用封锁</option>
            </select>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitSave">保存用户信息</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { User, Plus, Search, RefreshRight } from '@element-plus/icons-vue';
import request from '@/api/request';

// 与后端 App\Core\PasswordPolicy::DEFAULT_PASSWORD 保持一致
const DEFAULT_PASSWORD = '123456';

const keyword = ref('');
const loading = ref(false);
const saving = ref(false);
const tableData = ref([]);
const roles = ref([]);
const dialogVisible = ref(false);

const teamOptions = ['装置装配', '装置调试', '装置包装', '技术/质检', '管理/其他', '全部'];

const form = reactive({
  id: null,
  badge_no: '',
  username: '',
  full_name: '',
  role_id: null,
  team: '装置装配',
  phone: '',
  password: '',
  is_admin: 0,
  is_active: 1
});

const passwordPlaceholder = computed(() => (form.id
  ? '留空则不修改；填写则重置密码（对方下次登录须修改）'
  : `请输入初始密码，建议 ${DEFAULT_PASSWORD}`));

const roleBadgeStyle = (u) => {
  const name = String(u.role_name || '');
  if (u.is_admin) return 'background:#d1ecf1; color:#0c5460; font-weight:bold;';
  if (name.includes('班长')) return 'background:#d1ecf1; color:#0c5460; font-weight:bold;';
  if (name.includes('学生')) return 'background:#fff3cd; color:#856404;';
  if (name.includes('技工')) return 'background:#d4edda; color:#155724;';
  if (name.includes('管理')) return 'background:#e2e3e5; color:#383d41;';
  return 'background:#e8f4fd; color:#007bff;';
};

const loadData = async () => {
  loading.value = true;
  try {
    const res = await request.get('/system/users', { params: { keyword: keyword.value.trim() } });
    tableData.value = res.data || [];
  } finally {
    loading.value = false;
  }
};

const resetFilter = () => {
  keyword.value = '';
  loadData();
};

const loadRoles = async () => {
  const res = await request.get('/system/roles');
  roles.value = res.data || [];
  if (!form.role_id && roles.value.length) {
    form.role_id = roles.value[0].id;
  }
};

const handleOpenDialog = (row = null) => {
  if (row) {
    Object.assign(form, {
      id: row.id,
      badge_no: row.badge_no || '',
      username: row.username || '',
      full_name: row.full_name || '',
      role_id: row.role_id ?? (roles.value[0]?.id ?? null),
      team: row.team || '装置装配',
      phone: row.phone || '',
      password: '',
      is_admin: Number(row.is_admin) || 0,
      is_active: Number(row.is_active) ?? 1
    });
  } else {
    Object.assign(form, {
      id: null,
      badge_no: '',
      username: '',
      full_name: '',
      role_id: roles.value[0]?.id ?? null,
      team: '装置装配',
      phone: '',
      password: '',
      is_admin: 0,
      is_active: 1
    });
  }
  dialogVisible.value = true;
};

const submitSave = async () => {
  if (!form.username.trim() || !form.full_name.trim()) {
    return ElMessage.warning('用户名与真实姓名不能为空');
  }
  if (!form.id && !form.password) {
    return ElMessage.warning('新建用户时必须设置登录密码');
  }
  saving.value = true;
  try {
    const res = await request.post('/system/users/save', { ...form });
    ElMessage.success(res.message || '保存成功');
    dialogVisible.value = false;
    loadData();
  } finally {
    saving.value = false;
  }
};

const deleteUser = (u) => {
  ElMessageBox.confirm(`确定要删除用户【${u.username}】吗？该操作不可恢复！`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/system/users/delete', { id: u.id });
    ElMessage.success(res.message || '删除成功');
    loadData();
  });
};

const resetAllPasswords = () => {
  ElMessageBox.confirm(
    `确定要把【所有非管理员账号】的密码统一重置为 ${DEFAULT_PASSWORD} 吗？\n\n重置后这些人员下次登录必须先修改为合规密码，管理员账号不受影响。`,
    '批量重置密码',
    { type: 'warning', confirmButtonText: '确定重置', cancelButtonText: '取消' }
  ).then(async () => {
    const res = await request.post('/system/users/reset-all-passwords');
    ElMessage.success(res.message || '重置成功');
    loadData();
  }).catch(() => {});
};

onMounted(() => {
  loadRoles();
  loadData();
});
</script>

<style scoped>
.sys-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.sys-stat-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f0f7ff;
  border: 1px solid #d0e3ff;
  border-radius: 8px;
  padding: 14px 22px;
  margin-bottom: 15px;
}

.sys-card {
  background: #fff;
  border: 1px solid #e3e6f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
}
.sys-card-header {
  padding: 12px 20px;
  border-bottom: 1px solid #e3e6f0;
}

.sys-filter-card {
  background: #fff;
  border-bottom: 1px solid #e3e6f0;
  padding: 12px 20px;
}

.sys-table-wrapper {
  width: 100%;
  overflow: auto;
  max-height: calc(100vh - 320px);
  min-height: 320px;
  border-top: 1px solid #dee2e6;
}
.sys-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 12.5px;
  text-align: left;
}
.sys-table thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 10;
  padding: 9px 12px;
  border-bottom: 2px solid #dee2e6;
  border-right: 1px solid #dee2e6;
  font-weight: 600;
  color: #2c3e50;
  white-space: nowrap;
}
.sys-table tbody td {
  padding: 8px 12px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  vertical-align: middle;
}
.sys-table tbody tr:hover { background-color: #f8fbfe; }

.badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11.5px;
  font-weight: 500;
}
.badge-team { background: #e8f4fd; color: #007bff; }

.form-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.form-label {
  font-size: 12.5px;
  font-weight: 600;
  display: block;
  margin-bottom: 4px;
  color: #495057;
}

.sys-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.sys-input:focus { border-color: #80bdff; }

.sys-select {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 8px;
  outline: none;
  background: #fff;
  box-sizing: border-box;
}

.btn-primary {
  background: #007bff; color: #fff; border: 1px solid #007bff;
  border-radius: 4px; padding: 6px 14px; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center;
}
.btn-primary:hover { background: #0069d9; }
.btn-success {
  background: #28a745; color: #fff; border: 1px solid #28a745;
  border-radius: 4px; padding: 6px 14px; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center;
}
.btn-success:hover { background: #218838; }
.btn-warning {
  background: #ffc107; color: #664d03; border: 1px solid #ffc107;
  border-radius: 4px; padding: 6px 14px; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center;
}
.btn-warning:hover { background: #e0a800; }
.btn-danger {
  background: #dc3545; color: #fff; border: 1px solid #dc3545;
  border-radius: 4px; padding: 4px 10px; font-size: 11px; cursor: pointer; display: inline-flex; align-items: center;
}
.btn-outline {
  background: #fff; color: #495057; border: 1px solid #ced4da;
  border-radius: 4px; padding: 6px 12px; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center;
}
.btn-sm { font-size: 12.5px; height: 33px; }
.btn-mini { padding: 3px 8px; font-size: 11.5px; }
.mr-1 { margin-right: 4px; }
</style>
