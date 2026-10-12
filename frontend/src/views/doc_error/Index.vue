<template>
  <div class="de-container">
    <!-- 顶部标题卡片 -->
    <div class="de-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><DocumentChecked /></el-icon>
          文件错误记录
        </span>
        <div style="display:flex; gap:8px;">
          <button v-if="canManage" type="button" class="btn-warning btn-sm" @click="openPermModal">
            <el-icon class="mr-1"><UserFilled /></el-icon> 权限管理
          </button>
          <button v-if="canExport" type="button" class="btn-success btn-sm" @click="exportFile">
            <el-icon class="mr-1"><Download /></el-icon> 导出
          </button>
        </div>
      </div>
    </div>

    <!-- 录入卡片 -->
    <div v-if="canInput" class="de-card">
      <div style="padding: 12px 14px;">
        <div class="de-row-1">
          <div class="de-input-combo">
            <span class="de-label req">工单号:</span>
            <input
              v-model="form.work_order"
              type="text"
              class="de-input"
              style="width:150px;"
              placeholder="输入工单号"
              autocomplete="off"
              @keyup.enter="queryWorkOrder"
            />
            <button type="button" class="btn-primary btn-sm" style="white-space:nowrap;" @click="queryWorkOrder">
              <el-icon class="mr-1"><Search /></el-icon> 查询
            </button>
          </div>

          <div class="de-wo-badge" :title="woBoxTitle">
            <span><strong>编码:</strong> {{ woBox.code || '暂无' }}</span>
            <span style="margin:0 8px; color:#93c5fd;">|</span>
            <span><strong>描述:</strong> {{ woBox.description || '请输入工单号查询' }}</span>
          </div>

          <div class="de-input-combo">
            <span class="de-label req">发现工序:</span>
            <select v-model="form.discovery_process" class="de-select" style="width:130px;">
              <option value="">请选择工序...</option>
              <option v-for="p in processes" :key="p" :value="p">{{ p }}</option>
            </select>
          </div>
        </div>

        <div class="de-row-2">
          <div class="de-input-combo" style="width:100%;">
            <span class="de-label req">问题描述:</span>
            <input
              v-model="form.file_error_desc"
              type="text"
              class="de-input"
              style="width:100%;"
              placeholder="请详细描述图纸或作业文件具体错误..."
            />
          </div>

          <div class="de-input-combo" style="width:100%;">
            <span class="de-label">备注:</span>
            <input v-model="form.remark" type="text" class="de-input" style="width:100%;" placeholder="可选说明..." />
          </div>

          <div style="display:flex; gap:6px; white-space:nowrap;">
            <button type="button" class="btn-success btn-sm" :disabled="submitting" @click="submitRecord">
              <el-icon class="mr-1"><Select /></el-icon> 提交保存
            </button>
            <button type="button" class="btn-outline btn-sm" @click="resetForm">
              <el-icon class="mr-1"><RefreshLeft /></el-icon> 重置
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- 列表卡片 -->
    <div class="de-card">
      <div class="de-search-row">
        <div style="width:100%;">
          <input
            v-model="filters.keyword"
            type="text"
            class="de-input"
            style="width:100%;"
            placeholder="模糊查找 (输入工单号、描述、工序、姓名、问题关键字后回车)..."
            @keyup.enter="loadTable(1)"
          />
        </div>

        <div style="display:flex; align-items:center; gap:6px; white-space:nowrap;">
          <span class="de-label">是否修改:</span>
          <select v-model="filters.is_modified" class="de-select" style="width:95px;" @change="loadTable(1)">
            <option value="">全部</option>
            <option value="1">已修改</option>
            <option value="0">未修改</option>
          </select>
          <button type="button" class="btn-primary btn-sm" @click="loadTable(1)">
            <el-icon class="mr-1"><Search /></el-icon> 搜索
          </button>
          <button type="button" class="btn-outline btn-sm" @click="resetFilter">
            <el-icon class="mr-1"><Refresh /></el-icon> 重置
          </button>
        </div>

        <div style="display:flex; align-items:center; gap:6px; white-space:nowrap;">
          <button v-if="canEditPerm" type="button" class="btn-primary btn-sm" @click="openEditModal">
            <el-icon class="mr-1"><Edit /></el-icon> 编辑
          </button>
          <button v-if="canDeletePerm" type="button" class="btn-danger btn-sm" @click="deleteSelected">
            <el-icon class="mr-1"><Delete /></el-icon> 删除
          </button>
          <span style="font-size:11.5px; color:#6c757d;">已选 <strong style="color:#007bff;">{{ selectedIds.length }}</strong> 条</span>
        </div>
      </div>

      <div class="de-table-wrapper" v-loading="loading">
        <table class="de-fixed-table">
          <thead>
            <tr>
              <th style="width:36px; text-align:center;">
                <input type="checkbox" :checked="allSelected" @change="toggleSelectAll($event)" />
              </th>
              <th style="width:105px; text-align:left;">工单号</th>
              <th style="width:100px; text-align:left;">编码</th>
              <th style="width:16%; text-align:left;">工单描述</th>
              <th style="width:18%; text-align:left;">文件问题描述</th>
              <th style="width:90px;">发现工序</th>
              <th style="width:135px;">发现时间</th>
              <th style="width:85px;">发现人</th>
              <th style="width:80px; text-align:center;">是否修改</th>
              <th style="width:135px;">修改时间/人</th>
              <th style="width:12%; text-align:left;">备注说明</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="r in recordList"
              :key="r.id"
              :class="{ selected: selectedIds.includes(r.id) }"
            >
              <td style="text-align:center;">
                <input type="checkbox" v-model="selectedIds" :value="r.id" @change="syncSelectAllState" />
              </td>
              <td style="text-align:left;"><strong style="font-family:monospace; color:#2c3e50;">{{ r.work_order }}</strong></td>
              <td style="text-align:left;">{{ r.task_code || '-' }}</td>
              <td style="text-align:left;" :title="r.description"><span class="cell-desc">{{ r.description || '-' }}</span></td>
              <td style="text-align:left;" :title="r.file_error_desc"><span class="cell-desc">{{ r.file_error_desc }}</span></td>
              <td>{{ r.discovery_process || '-' }}</td>
              <td style="white-space:nowrap; font-size:11.5px;">{{ r.discovery_time || '-' }}</td>
              <td>{{ r.display_discoverer || r.discoverer_name || '未知' }}</td>
              <td style="text-align:center;">
                <span class="badge-tag" :class="parseInt(r.is_modified) === 1 ? 'badge-tag-yes' : 'badge-tag-no'">
                  {{ parseInt(r.is_modified) === 1 ? '是' : '否' }}
                </span>
              </td>
              <td style="white-space:nowrap; font-size:11px;">
                <template v-if="r.modify_time">
                  {{ r.modify_time }}<br /><small style="color:#6c757d;">{{ r.display_modifier || r.modifier_name || '' }}</small>
                </template>
                <template v-else>-</template>
              </td>
              <td style="text-align:left;" :title="r.remark"><span class="cell-desc">{{ r.remark || '-' }}</span></td>
            </tr>
            <tr v-if="recordList.length === 0 && !loading">
              <td colspan="11" style="padding:35px; color:#888; text-align:center;">暂无符合条件的记录</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="de-pagination-bar">
        <div style="color:#64748b;">共 <strong>{{ totalRecords }}</strong> 条记录，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页</div>
        <el-pagination v-model:current-page="curPage" :page-size="30" :total="totalRecords" layout="prev, pager, next" @current-change="loadTable" />
      </div>
    </div>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="editModalVisible" title="编辑记录" width="560px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;">
        <div style="margin-bottom:10px;">
          <label class="de-label">工单号</label>
          <input v-model="editForm.work_order" type="text" class="de-input" readonly style="background:#f8f9fa; cursor:not-allowed; margin-top:3px; width:100%;" />
        </div>
        <div style="margin-bottom:10px;">
          <label class="de-label req">文件问题描述</label>
          <textarea v-model="editForm.file_error_desc" class="de-input" rows="3" style="margin-top:3px; width:100%; height:auto;"></textarea>
        </div>
        <div style="margin-bottom:10px;">
          <label class="de-label req">发现工序</label>
          <select v-model="editForm.discovery_process" class="de-select" style="margin-top:3px; width:100%;">
            <option v-for="p in processes" :key="p" :value="p">{{ p }}</option>
          </select>
        </div>
        <div style="margin-bottom:10px;">
          <label class="de-label">是否修改</label>
          <select v-model="editForm.is_modified" class="de-select" style="margin-top:3px; width:100%;">
            <option :value="0">否</option>
            <option :value="1">是</option>
          </select>
        </div>
        <div style="margin-bottom:6px;">
          <label class="de-label">备注说明</label>
          <textarea v-model="editForm.remark" class="de-input" rows="2" style="margin-top:3px; width:100%; height:auto;"></textarea>
        </div>
      </div>
      <template #footer>
        <el-button @click="editModalVisible = false">取消</el-button>
        <el-button type="primary" :loading="editSaving" @click="saveEditRecord">保存修改</el-button>
      </template>
    </el-dialog>

    <!-- 权限管理弹窗 -->
    <el-dialog v-if="canManage" v-model="permModalVisible" title="文件错误记录 - 权限管理" width="720px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;" v-loading="permLoading">
        <div style="max-height:400px; overflow-y:auto; border:1px solid #dee2e6;">
          <table class="de-fixed-table" style="min-width:100%;">
            <thead>
              <tr>
                <th>用户名</th>
                <th>姓名</th>
                <th style="text-align:center;">录入</th>
                <th style="text-align:center;">编辑</th>
                <th style="text-align:center;">删除</th>
                <th style="text-align:center;">导出</th>
                <th style="text-align:center;">权限管理</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in permUserList" :key="u.id">
                <td><strong>{{ u.username }}</strong></td>
                <td>{{ u.full_name || '-' }}</td>
                <td style="text-align:center;"><input type="checkbox" v-model="u.can_input" :true-value="1" :false-value="0" @change="saveUserPerm(u)" /></td>
                <td style="text-align:center;"><input type="checkbox" v-model="u.can_edit" :true-value="1" :false-value="0" @change="saveUserPerm(u)" /></td>
                <td style="text-align:center;"><input type="checkbox" v-model="u.can_delete" :true-value="1" :false-value="0" @change="saveUserPerm(u)" /></td>
                <td style="text-align:center;"><input type="checkbox" v-model="u.can_export" :true-value="1" :false-value="0" @change="saveUserPerm(u)" /></td>
                <td style="text-align:center;"><input type="checkbox" v-model="u.can_manage" :true-value="1" :false-value="0" @change="saveUserPerm(u)" /></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div style="margin-top:8px; color:#94a3b8;">提示：管理员默认拥有全部权限，无需在此分配。</div>
      </div>
      <template #footer>
        <el-button @click="permModalVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  DocumentChecked, Search, Refresh, RefreshLeft, Select, Edit, Delete, Download, UserFilled
} from '@element-plus/icons-vue';
import request from '@/api/request';

// ===== 权限 =====
const isAdmin = ref(false);
const perm = reactive({
  can_input: 1,
  can_edit: 0,
  can_delete: 0,
  can_export: 0,
  can_manage: 0
});

const canInput   = computed(() => isAdmin.value || !!perm.can_input);
const canEditPerm  = computed(() => isAdmin.value || !!perm.can_edit);
const canDeletePerm = computed(() => isAdmin.value || !!perm.can_delete);
const canExport  = computed(() => isAdmin.value || !!perm.can_export);
const canManage  = computed(() => isAdmin.value || !!perm.can_manage);

const processes = ref(['装置装配', '装置调试', '装置包装']);

// ===== 录入 =====
const submitting = ref(false);
const form = reactive({
  work_order: '',
  task_code: '',
  description: '',
  file_error_desc: '',
  discovery_process: '',
  remark: ''
});
const woBox = reactive({ code: '', description: '' });
const woBoxTitle = computed(() => {
  const code = woBox.code || '-';
  const desc = woBox.description || '请输入工单号查询';
  return `工单号: ${form.work_order || '-'}\n编码: ${code}\n描述: ${desc}`;
});

// ===== 列表 =====
const loading = ref(false);
const recordList = ref([]);
const totalRecords = ref(0);
const curPage = ref(1);
const totalPages = ref(1);
const filters = reactive({ keyword: '', is_modified: '' });
const selectedIds = ref([]);
const allSelected = ref(false);

// ===== 编辑弹窗 =====
const editModalVisible = ref(false);
const editSaving = ref(false);
const editForm = reactive({
  id: 0,
  work_order: '',
  file_error_desc: '',
  discovery_process: '',
  is_modified: 0,
  remark: ''
});

// ===== 权限弹窗 =====
const permModalVisible = ref(false);
const permLoading = ref(false);
const permUserList = ref([]);

// ---------- 初始化 ----------
const initModule = async () => {
  try {
    const res = await request.get('/doc_error/init');
    if (res.data) {
      isAdmin.value = !!res.data.is_admin;
      if (Array.isArray(res.data.processes) && res.data.processes.length) {
        processes.value = res.data.processes;
      }
      if (res.data.perm) {
        Object.assign(perm, res.data.perm);
      }
    }
  } catch (e) {}
  loadTable(1);
};

// ---------- 工单查询 ----------
const queryWorkOrder = async () => {
  const wo = form.work_order.trim();
  if (!wo) return ElMessage.warning('请输入工单号');

  const res = await request.post('/doc_error/get_work_order', { work_order: wo });
  if (res.data && res.data.found) {
    form.task_code = res.data.code || '';
    form.description = res.data.description || '';
    woBox.code = res.data.code || '-';
    woBox.description = res.data.description || '-';
    ElMessage.success('已获取工单信息');
  } else {
    form.task_code = '';
    form.description = '';
    woBox.code = '-';
    woBox.description = '未查到工单信息（可继续手动提交）';
    ElMessage.info('工单外部源未查到，可继续提交');
  }
};

// ---------- 提交 ----------
const submitRecord = async () => {
  if (!form.work_order.trim()) return ElMessage.warning('请输入工单号');
  if (!form.discovery_process) return ElMessage.warning('请选择发现工序');
  if (!form.file_error_desc.trim()) return ElMessage.warning('请填写文件问题描述');

  submitting.value = true;
  try {
    const res = await request.post('/doc_error/add', {
      work_order: form.work_order.trim(),
      task_code: form.task_code,
      description: form.description,
      file_error_desc: form.file_error_desc.trim(),
      discovery_process: form.discovery_process,
      remark: form.remark.trim()
    });
    ElMessage.success(res.message || '提交成功');
    resetForm();
    loadTable(1);
  } finally {
    submitting.value = false;
  }
};

const resetForm = () => {
  form.work_order = '';
  form.task_code = '';
  form.description = '';
  form.file_error_desc = '';
  form.discovery_process = '';
  form.remark = '';
  woBox.code = '';
  woBox.description = '';
};

// ---------- 列表 ----------
const loadTable = async (p = 1) => {
  curPage.value = p;
  loading.value = true;
  try {
    const res = await request.get('/doc_error/list', {
      params: {
        page: p,
        keyword: filters.keyword.trim(),
        is_modified: filters.is_modified
      }
    });
    if (res.data) {
      recordList.value = res.data.list || [];
      totalRecords.value = res.data.total_records || 0;
      totalPages.value = res.data.total_pages || 1;
      isAdmin.value = !!res.data.is_admin;
      if (res.data.perm) Object.assign(perm, res.data.perm);
      selectedIds.value = [];
      allSelected.value = false;
    }
  } finally {
    loading.value = false;
  }
};

const resetFilter = () => {
  filters.keyword = '';
  filters.is_modified = '';
  loadTable(1);
};

const toggleSelectAll = (e) => {
  allSelected.value = e.target.checked;
  selectedIds.value = allSelected.value ? recordList.value.map(r => r.id) : [];
};

const syncSelectAllState = () => {
  allSelected.value = recordList.value.length > 0 && selectedIds.value.length === recordList.value.length;
};

// ---------- 编辑 ----------
const openEditModal = async () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请先勾选需要编辑的一条记录');
  if (selectedIds.value.length > 1) return ElMessage.warning('每次只能选择一条记录进行编辑');

  const id = selectedIds.value[0];
  const res = await request.get('/doc_error/get', { params: { id } });
  if (res.data) {
    const d = res.data;
    editForm.id = d.id;
    editForm.work_order = d.work_order || '';
    editForm.file_error_desc = d.file_error_desc || '';
    editForm.discovery_process = d.discovery_process || processes.value[0] || '';
    editForm.is_modified = parseInt(d.is_modified) === 1 ? 1 : 0;
    editForm.remark = d.remark || '';
    editModalVisible.value = true;
  }
};

const saveEditRecord = async () => {
  if (!editForm.file_error_desc.trim()) return ElMessage.warning('文件问题描述不能为空');
  editSaving.value = true;
  try {
    const res = await request.post('/doc_error/update', {
      id: editForm.id,
      file_error_desc: editForm.file_error_desc.trim(),
      discovery_process: editForm.discovery_process,
      is_modified: editForm.is_modified,
      remark: editForm.remark.trim()
    });
    ElMessage.success(res.message || '记录修改成功');
    editModalVisible.value = false;
    loadTable(curPage.value);
  } finally {
    editSaving.value = false;
  }
};

// ---------- 删除 ----------
const deleteSelected = () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请先勾选要删除的记录');
  ElMessageBox.confirm(`确定要删除选中的 ${selectedIds.value.length} 条记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/doc_error/delete', { ids: selectedIds.value });
    ElMessage.success(res.message || '删除成功');
    selectedIds.value = [];
    allSelected.value = false;
    loadTable(curPage.value);
  });
};

// ---------- 导出 ----------
const exportFile = () => {
  const params = new URLSearchParams();
  params.append('keyword', filters.keyword.trim());
  params.append('is_modified', filters.is_modified);
  window.open(`/api/doc_error/export?${params.toString()}`, '_blank');
};

// ---------- 权限管理 ----------
const openPermModal = async () => {
  permModalVisible.value = true;
  permLoading.value = true;
  try {
    const res = await request.get('/doc_error/get_permissions');
    if (res.data) {
      permUserList.value = res.data.users || [];
    }
  } finally {
    permLoading.value = false;
  }
};

const saveUserPerm = async (u) => {
  try {
    await request.post('/doc_error/update_permission', {
      user_id: u.id,
      can_input: u.can_input,
      can_edit: u.can_edit,
      can_delete: u.can_delete,
      can_export: u.can_export,
      can_manage: u.can_manage
    });
    ElMessage.success('权限更新成功');
  } catch (e) {}
};

onMounted(() => {
  initModule();
});
</script>

<style scoped>
.de-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.de-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  margin-bottom: 12px;
  overflow: hidden;
}

.de-row-1 {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 10px;
  align-items: center;
  margin-bottom: 8px;
  width: 100%;
}
.de-row-2 {
  display: grid;
  grid-template-columns: 2fr 1.2fr auto;
  gap: 10px;
  align-items: center;
  width: 100%;
}
.de-input-combo {
  display: flex;
  align-items: center;
  gap: 6px;
}
.de-label {
  font-size: 12.5px;
  font-weight: 600;
  color: #495057;
  white-space: nowrap;
  flex-shrink: 0;
}
.de-label.req::after { content: "*"; color: #dc3545; margin-left: 2px; }

.de-wo-badge {
  background: #f0f7ff;
  border: 1px dashed #93c5fd;
  border-radius: 4px;
  padding: 0 10px;
  height: 33px;
  line-height: 31px;
  font-size: 12.5px;
  color: #0284c7;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
  width: 100%;
  box-sizing: border-box;
}

.de-search-row {
  padding: 8px 12px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 10px;
  align-items: center;
  width: 100%;
}

.de-table-wrapper {
  width: 100%;
  overflow: auto;
  max-height: calc(100vh - 330px);
  min-height: 300px;
  border-top: 1px solid #dee2e6;
}
.de-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed !important;
  font-size: 13px;
  text-align: center;
  color: #333;
}
.de-fixed-table thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 10;
  padding: 8px 6px;
  border-bottom: 2px solid #dee2e6;
  border-right: 1px solid #dee2e6;
  white-space: nowrap;
  font-weight: 600;
  color: #2c3e50;
  overflow: hidden;
  text-overflow: ellipsis;
}
.de-fixed-table tbody td {
  padding: 7px 6px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.de-fixed-table tbody tr:hover { background: #f8fafc; }
.de-fixed-table tbody tr.selected { background: #e0f2fe !important; }

.cell-desc {
  display: block;
  width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: left;
}

.badge-tag { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 11.5px; font-weight: 500; }
.badge-tag-yes { background: #d4edda; color: #15803d; }
.badge-tag-no { background: #f8d7da; color: #721c24; }

.de-pagination-bar {
  padding: 8px 14px;
  background: #f8f9fa;
  border-top: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12.5px;
  flex-wrap: wrap;
  gap: 8px;
}

.de-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.de-input:focus { border-color: #80bdff; }

.de-select {
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
  border-radius: 4px; padding: 5px 12px; cursor: pointer; display: inline-flex; align-items: center;
}
.btn-primary:hover { background: #0069d9; }
.btn-success {
  background: #28a745; color: #fff; border: 1px solid #28a745;
  border-radius: 4px; padding: 5px 12px; cursor: pointer; display: inline-flex; align-items: center;
}
.btn-success:hover { background: #218838; }
.btn-warning {
  background: #ffc107; color: #212529; border: 1px solid #ffc107;
  border-radius: 4px; padding: 5px 12px; cursor: pointer; display: inline-flex; align-items: center;
}
.btn-warning:hover { background: #e0a800; }
.btn-danger {
  background: #dc3545; color: #fff; border: 1px solid #dc3545;
  border-radius: 4px; padding: 5px 12px; cursor: pointer; display: inline-flex; align-items: center;
}
.btn-outline {
  background: #fff; color: #495057; border: 1px solid #ced4da;
  border-radius: 4px; padding: 5px 12px; cursor: pointer; display: inline-flex; align-items: center;
}
.btn-sm { font-size: 12.5px; height: 33px; }
.mr-1 { margin-right: 4px; }
</style>
