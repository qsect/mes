<template>
  <div class="eq-container">
    <!-- 顶部标题卡片 -->
    <div class="eq-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Cpu /></el-icon>
          设备矩阵总览
        </span>
        <div>
          <button v-if="canManage" type="button" class="btn-success btn-sm" @click="openAddModal">
            <el-icon class="mr-1"><Plus /></el-icon> 添加新设备
          </button>
        </div>
      </div>
    </div>

    <!-- 顶部提示横幅 -->
    <div class="eq-banner">
      <div>
        <div style="font-size:13px; font-weight:bold; color:#007bff;">
          <el-icon style="vertical-align:middle; margin-right:4px;"><DataLine /></el-icon>
          设备工序效率系数配置库
        </div>
        <div style="font-size:12px; color:#64748b; margin-top:2px;">
          每个单元格显示设备在该工序上的标准效率系数，留空表示未配置或该设备不涉及该工序。
        </div>
      </div>
    </div>

    <!-- 矩阵表格卡片 -->
    <div class="eq-card">
      <div class="eq-card-header">
        <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">设备效率矩阵表</span>
        <div style="display:flex; gap:8px;">
          <input
            v-model="searchKeyword"
            type="text"
            class="eq-input"
            style="width:230px;"
            placeholder="搜索编号/名称/代码..."
            @keyup.enter="loadMatrix"
          />
          <button type="button" class="btn-primary btn-sm" @click="loadMatrix">
            <el-icon class="mr-1"><Search /></el-icon> 查询
          </button>
        </div>
      </div>

      <div class="eq-table-wrapper" v-loading="loading">
        <table class="eq-fixed-table">
          <thead>
            <tr>
              <th style="width:90px;">设备编号</th>
              <th style="width:120px;">设备名称</th>
              <th style="width:105px;">代码</th>
              <th style="width:160px; text-align:left;">描述</th>
              <th v-for="p in globalProcesses" :key="p">{{ p }}</th>
              <th v-if="canManage" style="width:110px; text-align:center;">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in matrixData" :key="item.id">
              <td><strong style="font-family:monospace; color:#2c3e50;">{{ item.code }}</strong></td>
              <td><strong>{{ item.name }}</strong></td>
              <td><code style="font-family:monospace;">{{ item.product_series }}</code></td>
              <td style="text-align:left; max-width:160px;" :title="item.description">
                <span class="cell-truncate">{{ item.description || '-' }}</span>
              </td>
              <td v-for="p in globalProcesses" :key="p">
                <span v-if="item.procs[p] !== undefined && item.procs[p] !== null" style="font-weight:bold; color:#007bff;">
                  {{ Number(item.procs[p]).toFixed(2) }}
                </span>
                <span v-else style="color:#ccc;">-</span>
              </td>
              <td v-if="canManage" style="text-align:center; white-space:nowrap;">
                <button type="button" class="btn-primary btn-mini" @click="openEditModal(item)">编辑</button>
                <button type="button" class="btn-danger btn-mini" style="margin-left:4px;" @click="deleteItem(item.id)">删除</button>
              </td>
            </tr>
            <tr v-if="matrixData.length === 0">
              <td :colspan="globalProcesses.length + (canManage ? 5 : 4)" style="text-align:center; padding:35px; color:#888;">
                暂无设备数据{{ canManage ? '，点击右上角【添加新设备】创建' : '' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 弹窗: 添加/编辑设备 -->
    <el-dialog v-model="modalVisible" :title="modalTitle" width="760px" destroy-on-close>
      <el-form label-width="110px" size="small">
        <div class="form-grid-2">
          <el-form-item label="设备编号 *" required>
            <el-input v-model="form.code" placeholder="如 EQ001..." />
          </el-form-item>
          <el-form-item label="设备名称 *" required>
            <el-input v-model="form.name" placeholder="如 自动装配机1号..." />
          </el-form-item>
        </div>

        <div class="form-grid-2">
          <el-form-item label="产品物料代码 *" required>
            <div style="display:flex; gap:6px; width:100%;">
              <el-input v-model="form.product_series" style="font-family:monospace;" placeholder="输入物料号查询工艺..." @blur="queryProductRoute(false)" />
              <button type="button" class="btn-outline btn-mini" @click="queryProductRoute(true)">查工艺</button>
            </div>
          </el-form-item>
          <el-form-item label="物料描述">
            <el-input v-model="form.description" readonly style="background:#f8f9fa;" placeholder="自动联查同步..." />
          </el-form-item>
        </div>

        <el-form-item label="工序效率配置">
          <div style="font-size:12px; color:#64748b; margin-bottom:4px; width:100%;">
            各工序效率系数配置 (留空表示该设备不涉及该工序)：
          </div>
          <div class="proc-grid">
            <div v-for="p in currentModalProcesses" :key="p" class="proc-card">
              <span class="proc-label">{{ p }}</span>
              <input
                v-model="form.efficiencies[p]"
                type="number"
                step="0.01"
                min="0"
                max="999.99"
                class="eq-proc-input"
                placeholder="留空"
              />
            </div>
          </div>
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="modalVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitSave">保存设备配置</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Cpu, Plus, Search, Delete, DataLine
} from '@element-plus/icons-vue';
import request from '@/api/request';

const globalProcesses = ref([]);
const matrixData = ref([]);
const loading = ref(false);
const searchKeyword = ref('');
// ★ 按钮级权限：无维护权限则不渲染「添加/编辑/删除」，避免"点了才 403"
const canManage = ref(false);

const modalVisible = ref(false);
const saving = ref(false);
const editingId = ref(null);
const currentModalProcesses = ref([]);

const form = reactive({
  code: '',
  name: '',
  product_series: '',
  description: '',
  efficiencies: {}
});

const modalTitle = computed(() => editingId.value ? `编辑设备 [${form.name}]` : '添加新设备');

const initModule = async () => {
  try {
    const res = await request.get('/equipment/init');
    if (res.data) {
      globalProcesses.value = res.data.processes || [];
      canManage.value = !!res.data.can_manage;
    }
  } catch (e) {}
  loadMatrix();
};

const loadMatrix = async () => {
  loading.value = true;
  try {
    const res = await request.get('/equipment/list_matrix', {
      params: { search: searchKeyword.value.trim() }
    });
    if (res.data) {
      globalProcesses.value = res.data.processes || globalProcesses.value;
      matrixData.value = res.data.list || [];
    }
  } finally {
    loading.value = false;
  }
};

const openAddModal = () => {
  editingId.value = null;
  currentModalProcesses.value = [...globalProcesses.value];
  Object.assign(form, {
    code: '',
    name: '',
    product_series: '',
    description: '',
    efficiencies: {}
  });
  modalVisible.value = true;
};

const openEditModal = (item) => {
  editingId.value = item.id;
  currentModalProcesses.value = [...globalProcesses.value];
  Object.assign(form, {
    code: item.code,
    name: item.name,
    product_series: item.product_series,
    description: item.description || '',
    efficiencies: { ...item.procs }
  });
  modalVisible.value = true;
};

const queryProductRoute = async (manualNotice = true) => {
  const codeVal = form.product_series.trim();
  if (!codeVal) return;
  try {
    const res = await request.post('/equipment/get_product_info', { code: codeVal });
    if (res.data) {
      form.description = res.data.description || '';
      if (res.data.processes && res.data.processes.length > 0) {
        currentModalProcesses.value = res.data.processes;
      }
      if (manualNotice) ElMessage.success('已匹配工艺路线与物料描述');
    }
  } catch (e) {}
};

const submitSave = async () => {
  if (!form.code.trim() || !form.name.trim() || !form.product_series.trim()) {
    return ElMessage.warning('设备编号、设备名称及代码不能为空！');
  }

  saving.value = true;
  try {
    const res = await request.post('/equipment/save_equipment', {
      id: editingId.value,
      code: form.code.trim(),
      name: form.name.trim(),
      product_series: form.product_series.trim(),
      description: form.description.trim(),
      efficiencies: form.efficiencies
    });
    ElMessage.success(res.message || '保存成功');
    modalVisible.value = false;
    loadMatrix();
  } finally {
    saving.value = false;
  }
};

const deleteItem = (id) => {
  ElMessageBox.confirm('确认删除该设备及其所有效率数据吗？', '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/equipment/delete_equipment', { id });
    ElMessage.success(res.message || '删除成功');
    loadMatrix();
  });
};

onMounted(() => {
  initModule();
});
</script>

<style scoped>
.eq-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.eq-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.eq-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f0f7ff;
  border: 1px solid #d0e3ff;
  color: #2c3e50;
  border-radius: 6px;
  padding: 10px 18px;
  margin-bottom: 12px;
}
.eq-card-header {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.eq-table-wrapper {
  max-height: calc(100vh - 280px);
  min-height: 320px;
  overflow-y: auto;
  overflow-x: auto;
  border-top: 1px solid #dee2e6;
  width: 100%;
}
.eq-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 12.5px;
  text-align: center;
  color: #333;
}
.eq-fixed-table thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 10;
  padding: 8px 8px;
  border-bottom: 2px solid #dee2e6;
  border-right: 1px solid #dee2e6;
  white-space: nowrap;
  font-weight: 600;
  color: #2c3e50;
}
.eq-fixed-table tbody td {
  padding: 6px 6px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
}
.eq-fixed-table tbody tr:hover { background-color: #f8fafc; }

.cell-truncate {
  display: inline-block;
  max-width: 160px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: middle;
}

.form-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 16px;
}

.proc-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 8px;
  margin-top: 4px;
  max-height: 250px;
  overflow-y: auto;
  padding: 10px;
  background: #fafbfc;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  width: 100%;
  box-sizing: border-box;
}

.proc-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: white;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  padding: 4px 8px;
}

.proc-label {
  font-size: 12px;
  font-weight: 600;
  color: #2c3e50;
}

.eq-proc-input {
  width: 70px;
  height: 26px;
  font-size: 12px;
  text-align: center;
  border: 1px solid #ced4da;
  border-radius: 3px;
  outline: none;
}
.eq-proc-input:focus { border-color: #80bdff; }

.eq-input {
  height: 32px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.eq-input:focus { border-color: #80bdff; }

.btn-primary {
  background: #007bff;
  color: #fff;
  border: 1px solid #007bff;
  border-radius: 4px;
  padding: 5px 12px;
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
  padding: 5px 12px;
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
  padding: 5px 12px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}
.btn-outline {
  background: #fff;
  color: #495057;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 5px 12px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}
.btn-sm { font-size: 12px; height: 32px; }
.btn-mini { padding: 2px 6px; font-size: 11px; }
.mr-1 { margin-right: 4px; }
</style>