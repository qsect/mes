<template>
  <div class="mod-container">
    <!-- 顶部统计 Banner -->
    <div class="mod-stat-banner">
      <div>
        <div style="font-size:13px; color:#6c757d; font-weight:500;">系统已注册模块总量</div>
        <div style="font-size:28px; font-weight:bold; color:#007bff; line-height:1.1; margin-top:2px;">
          {{ totalCount }}
        </div>
      </div>
      <div style="display:flex; gap:10px;">
        <button type="button" class="btn-success btn-sm" :disabled="syncing" @click="handleSyncAll">
          <el-icon class="mr-1"><Refresh /></el-icon>
          {{ syncing ? '正在同步内置模块...' : '一键同步/注册全量内置模块' }}
        </button>
        <button type="button" class="btn-primary btn-sm" @click="openAddModal">
          <el-icon class="mr-1"><Plus /></el-icon> 新增自定义模块
        </button>
      </div>
    </div>

    <!-- 模块清单卡片 -->
    <div class="card mod-card">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; padding:12px 18px;">
        <span style="font-size:16px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:4px;"><Menu /></el-icon>
          系统模块清单与运行状态
        </span>
      </div>

      <!-- 筛选过滤 -->
      <div class="mod-filter-card">
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
          <select v-model="filterCategory" class="mod-select" style="width:160px;" @change="loadModules">
            <option value="">全部分类</option>
            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
          </select>
          <input
            v-model="searchKeyword"
            type="text"
            class="mod-input"
            style="width:280px;"
            placeholder="搜索模块名称、标识码或功能描述..."
            @keyup.enter="loadModules"
          />
          <button type="button" class="btn-primary btn-sm" @click="loadModules">
            <el-icon class="mr-1"><Search /></el-icon> 搜索
          </button>
          <button type="button" class="btn-outline btn-sm" @click="resetFilter">
            <el-icon class="mr-1"><Refresh /></el-icon> 重置
          </button>
        </div>
      </div>

      <!-- 数据表格 (自适应列宽) -->
      <div class="card-body" style="padding:0;">
        <div style="width:100%; overflow-x:auto;" v-loading="loading">
          <table class="table-modules">
            <thead>
              <tr>
                <th style="width:60px; text-align:center;">排序</th>
                <th style="width:60px; text-align:center;">图标</th>
                <th style="width:160px;">模块名称</th>
                <th style="width:160px;">模块标识码</th>
                <th style="width:120px;">所属分类</th>
                <th>功能说明</th>
                <th style="width:90px; text-align:center;">状态</th>
                <th style="width:150px; text-align:center;">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in moduleList" :key="m.id">
                <td style="text-align:center; font-weight:bold; color:#6c757d;">{{ m.sort_order }}</td>
                <td style="text-align:center;">
                  <el-icon style="color:#007bff; font-size:16px;"><component :is="renderIconComponent(m.icon)" /></el-icon>
                </td>
                <td><strong>{{ m.name }}</strong></td>
                <td><code style="font-size:12px; color:#e83e8c;">{{ m.module_code }}</code></td>
                <td><span class="badge badge-cat">{{ m.category }}</span></td>
                <td><span class="cell-truncate" :title="m.description">{{ m.description || '-' }}</span></td>
                <td style="text-align:center;">
                  <span class="badge" :style="{ background: m.status == 1 ? '#d4edda' : '#f8d7da', color: m.status == 1 ? '#155724' : '#721c24' }">
                    {{ m.status == 1 ? '启用中' : '已停用' }}
                  </span>
                </td>
                <td style="text-align:center; white-space:nowrap;">
                  <button type="button" class="btn-outline btn-mini" @click="toggleStatus(m)">
                    {{ m.status == 1 ? '停用' : '启用' }}
                  </button>
                  <button type="button" class="btn-primary btn-mini" style="margin-left:4px;" @click="openEditModal(m)">
                    编辑
                  </button>
                  <button type="button" class="btn-danger btn-mini" style="margin-left:4px;" @click="deleteItem(m)">
                    删除
                  </button>
                </td>
              </tr>
              <tr v-if="moduleList.length === 0 && !loading">
                <td colspan="8" style="text-align:center; padding:35px; color:#888;">
                  未检索到模块，点击上方“一键同步”快速导入全量内置模块
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- 添加/编辑模块弹窗 -->
    <el-dialog v-model="modalVisible" :title="modalTitle" width="600px" destroy-on-close>
      <div style="padding:10px 14px;">
        <div class="form-grid-2">
          <div>
            <label class="form-label">模块名称 *</label>
            <input v-model="form.name" type="text" class="mod-input" style="width:100%;" placeholder="如：质量问题记录" required />
          </div>
          <div>
            <label class="form-label">模块标识码 (唯一Code) *</label>
            <input v-model="form.module_code" type="text" class="mod-input" style="width:100%;" placeholder="如：quality" required />
          </div>
          <div>
            <label class="form-label">所属分类 *</label>
            <input v-model="form.category" type="text" class="mod-input" style="width:100%;" placeholder="如：质量控制 / 生产协同" list="cat_suggestions" required />
            <datalist id="cat_suggestions">
              <option value="生产协同" />
              <option value="质量控制" />
              <option value="测试检验" />
              <option value="效率核算" />
              <option value="综合管理" />
              <option value="辅助工具" />
              <option value="系统管理" />
            </datalist>
          </div>
          <div>
            <label class="form-label">图标组件名称 *</label>
            <input v-model="form.icon" type="text" class="mod-input" style="width:100%;" placeholder="如：Operation / Box / Menu" required />
          </div>
          <div>
            <label class="form-label">路由 Path 对应名</label>
            <input v-model="form.route" type="text" class="mod-input" style="width:100%;" placeholder="默认与标识码一致" />
          </div>
          <div>
            <label class="form-label">菜单显示排序 (数字越小越靠前)</label>
            <input v-model.number="form.sort_order" type="number" class="mod-input" style="width:100%;" />
          </div>
          <div style="grid-column: span 2;">
            <label class="form-label">功能说明与描述</label>
            <textarea v-model="form.description" class="mod-input" rows="2" style="width:100%; height:auto;" placeholder="简述该模块的业务用途..."></textarea>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button @click="modalVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitSave">保存配置</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Menu, Refresh, Plus, Search, Delete,
  Odometer, Checked, Select, Crop, Box, Timer,
  Notebook, Van, Operation, Calendar, Files,
  Printer, Connection, ChatLineRound, User, Lock, Document
} from '@element-plus/icons-vue';
import request from '@/api/request';

const loading = ref(false);
const syncing = ref(false);
const saving = ref(false);

const moduleList = ref([]);
const categories = ref([]);
const totalCount = ref(0);

const filterCategory = ref('');
const searchKeyword = ref('');

const modalVisible = ref(false);
const editingId = ref(null);

const form = reactive({
  name: '',
  module_code: '',
  category: '业务功能',
  icon: 'Menu',
  route: '',
  sort_order: 10,
  description: ''
});

const modalTitle = computed(() => editingId.value ? `✏️ 编辑模块 [${form.name}]` : '➕ 新增自定义模块登记');

// 图标组件动态解析
const iconDict = {
  Odometer, Checked, Select, Crop, Box, Timer,
  Notebook, Van, Operation, Calendar, Files,
  Printer, Connection, ChatLineRound, User, Lock, Document,
  Menu, Search, Refresh, Plus, Delete
};

const renderIconComponent = (iconName) => {
  if (!iconName) return Menu;
  // 兼容 FontAwesome 遗留类名转换
  if (iconName.includes('fa-print')) return Printer;
  if (iconName.includes('fa-truck')) return Van;
  if (iconName.includes('fa-cogs') || iconName.includes('fa-cog')) return Operation;
  if (iconName.includes('fa-box')) return Box;
  if (iconName.includes('fa-calendar')) return Calendar;
  if (iconName.includes('fa-barcode') || iconName.includes('fa-qrcode')) return Crop;
  if (iconName.includes('fa-user')) return User;
  if (iconName.includes('fa-history')) return Timer;

  return iconDict[iconName] || Menu;
};

const loadModules = async () => {
  loading.value = true;
  try {
    const res = await request.get('/system/modules', {
      params: {
        category: filterCategory.value,
        search: searchKeyword.value.trim()
      }
    });
    if (res.data) {
      moduleList.value = res.data.list || [];
      categories.value = res.data.categories || [];
      totalCount.value = res.data.total || moduleList.value.length;
    }
  } finally {
    loading.value = false;
  }
};

const resetFilter = () => {
  filterCategory.value = '';
  searchKeyword.value = '';
  loadModules();
};

const handleSyncAll = async () => {
  syncing.value = true;
  try {
    const res = await request.post('/system/modules/sync');
    ElMessage.success(res.message || '全量内置模块同步成功！');
    loadModules();
  } finally {
    syncing.value = false;
  }
};

const toggleStatus = async (m) => {
  try {
    await request.post('/system/modules/toggle-status', { id: m.id });
    ElMessage.success('模块状态已切换');
    loadModules();
  } catch (e) {}
};

const openAddModal = () => {
  editingId.value = null;
  Object.assign(form, {
    name: '',
    module_code: '',
    category: '生产协同',
    icon: 'Menu',
    route: '',
    sort_order: 10,
    description: ''
  });
  modalVisible.value = true;
};

const openEditModal = (m) => {
  editingId.value = m.id;
  Object.assign(form, {
    name: m.name,
    module_code: m.module_code,
    category: m.category || '业务功能',
    icon: m.icon || 'Menu',
    route: m.route || m.module_code,
    sort_order: m.sort_order || 10,
    description: m.description || ''
  });
  modalVisible.value = true;
};

const submitSave = async () => {
  if (!form.name.trim() || !form.module_code.trim()) {
    return ElMessage.warning('模块名称和模块标识码不能为空！');
  }

  saving.value = true;
  try {
    const res = await request.post('/system/modules/save', {
      id: editingId.value,
      ...form
    });
    ElMessage.success(res.message || '保存成功');
    modalVisible.value = false;
    loadModules();
  } finally {
    saving.value = false;
  }
};

const deleteItem = (m) => {
  ElMessageBox.confirm(`确定要删除模块【${m.name}】的注册信息吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/system/modules/delete', { id: m.id });
    ElMessage.success(res.message || '删除成功');
    loadModules();
  });
};

onMounted(() => {
  loadModules();
});
</script>

<style scoped>
.mod-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.mod-card {
  background: white;
  border-radius: 8px;
  border: 1px solid #e3e6f0;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
}

.mod-stat-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f0f7ff;
  border: 1px solid #d0e3ff;
  color: #2c3e50;
  border-radius: 8px;
  padding: 14px 22px;
  margin-bottom: 15px;
}

.mod-filter-card {
  background: white;
  border: 1px solid #e3e6f0;
  border-radius: 8px;
  padding: 12px 20px;
  margin: 15px 20px 15px 20px;
}

.table-modules {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 13px;
  text-align: left;
}
.table-modules thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 10;
  padding: 10px 14px;
  border-bottom: 2px solid #dee2e6;
  border-right: 1px solid #dee2e6;
  font-weight: 600;
  color: #2c3e50;
  white-space: nowrap;
}
.table-modules tbody td {
  padding: 8px 14px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  vertical-align: middle;
  white-space: nowrap;
}
.table-modules tbody tr:hover {
  background-color: #f8fbfe;
}

.badge-cat {
  background: #e8f4fd;
  color: #007bff;
  padding: 3px 8px;
  border-radius: 4px;
  font-size: 11.5px;
  font-weight: 500;
}

.cell-truncate {
  display: inline-block;
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: middle;
}

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

.mod-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.mod-input:focus { border-color: #80bdff; }

.mod-select {
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
  background: #007bff;
  color: #fff;
  border: 1px solid #007bff;
  border-radius: 4px;
  padding: 6px 14px;
  font-size: 12.5px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}
.btn-primary:hover { background: #0069d9; }

.btn-success {
  background: #28a745;
  color: #fff;
  border: 1px solid #28a745;
  border-radius: 4px;
  padding: 6px 14px;
  font-size: 12.5px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}
.btn-success:hover { background: #218838; }

.btn-danger {
  background: #dc3545;
  color: #fff;
  border: 1px solid #dc3545;
  border-radius: 4px;
  padding: 4px 10px;
  font-size: 11px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}

.btn-outline {
  background: #fff;
  color: #495057;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 6px 12px;
  font-size: 12.5px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}

.btn-sm { font-size: 12.5px; height: 33px; }
.btn-mini { padding: 3px 8px; font-size: 11.5px; }
.mr-1 { margin-right: 4px; }
</style>