<template>
  <div class="bb-container">
    <!-- 顶部标题卡片 -->
    <div class="bb-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Van /></el-icon>
          北边返厂统计
        </span>
        <div style="display:flex; gap:8px;">
          <button type="button" class="btn-success btn-sm" @click="openAddModal">
            <el-icon class="mr-1"><Plus /></el-icon> 添加信息
          </button>
          <button type="button" class="btn-primary btn-sm" @click="exportData">
            <el-icon class="mr-1"><Download /></el-icon> 导出数据
          </button>
        </div>
      </div>
    </div>

    <div class="bb-card">
      <!-- 搜索与筛选工具栏 -->
      <div class="bb-toolbar">
        <div class="bb-search-area">
          <select v-model="searchType" class="bb-select" style="width: 130px;">
            <option value="all">全部字段</option>
            <option value="work_order">工单号</option>
            <option value="sn">SN</option>
            <option value="psn">PSN</option>
            <option value="description">描述</option>
            <option value="operator">操作人</option>
            <option value="remarks">备注</option>
            <option value="is_returned">是否返回</option>
          </select>
          <input
            v-model="searchKeyword"
            type="text"
            class="bb-input"
            style="flex:1; min-width:230px;"
            placeholder="输入关键词后回车搜索..."
            @keyup.enter="loadTable(1)"
          />
          <button type="button" class="btn-primary btn-sm" @click="loadTable(1)">
            <el-icon class="mr-1"><Search /></el-icon> 搜索
          </button>
          <button type="button" class="btn-outline btn-sm" @click="resetSearch">
            <el-icon class="mr-1"><Refresh /></el-icon> 重置
          </button>
        </div>
      </div>

      <!-- 批量操作栏 -->
      <div v-if="selectedIds.length > 0 && isAdmin" class="bb-batch-bar">
        <span style="color:#0369a1;">已选择 <strong style="color:#409EFF;">{{ selectedIds.length }}</strong> 条记录</span>
        <div style="display:flex; gap:6px;">
          <button type="button" class="btn-danger btn-mini" @click="batchDelete">
            <el-icon class="mr-1"><Delete /></el-icon> 批量删除
          </button>
          <button type="button" class="btn-outline btn-mini" @click="selectedIds = []">取消选择</button>
        </div>
      </div>

      <!-- 表格主体 (固定列宽) -->
      <div class="bb-table-wrapper" v-loading="loading">
        <table class="bb-fixed-table" style="min-width: 1280px;">
          <thead>
            <tr>
              <th style="width: 36px; text-align: center;"><input type="checkbox" v-model="allSelected" @change="toggleSelectAll" /></th>
              <th style="width: 95px;">工单</th>
              <th class="col-bb-desc">描述</th>
              <th style="width: 185px;">SN</th>
              <th style="width: 125px;">PSN</th>
              <th style="width: 105px;">返回北边时间</th>
              <th style="width: 75px; text-align: center;">是否返回</th>
              <th style="width: 105px;">返回时间</th>
              <th style="width: 80px;">操作人</th>
              <th class="col-bb-rem">备注</th>
              <th style="width: 160px; text-align: center;">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in recordList" :key="r.id" :class="{ selected: selectedIds.includes(r.id) }">
              <td style="text-align:center;"><input type="checkbox" v-model="selectedIds" :value="r.id" /></td>
              <td><strong style="font-family:monospace; color:#2c3e50;">{{ r.work_order }}</strong></td>
              <td class="col-bb-desc"><span class="cell-truncate" :title="r.description">{{ r.description || '-' }}</span></td>
              <td><span style="font-family:monospace; color:#495057;">{{ r.sn }}</span></td>
              <td><span style="font-family:monospace; font-weight:600; color:#2c3e50;">{{ r.psn }}</span></td>
              <td>{{ r.return_time }}</td>
              <td style="text-align:center;">
                <span class="badge-tag" :class="r.is_returned === '是' ? 'badge-tag-yes' : 'badge-tag-no'">
                  {{ r.is_returned || '否' }}
                </span>
              </td>
              <td><span :style="{ color: r.actual_return_date ? '#333' : '#aaa' }">{{ r.actual_return_date || '-' }}</span></td>
              <td>{{ r.operator }}</td>
              <td class="col-bb-rem"><span class="cell-truncate" :title="r.remarks">{{ r.remarks || '-' }}</span></td>
              <td class="col-bb-actions" style="text-align:center; white-space:nowrap;">
                <button type="button" class="btn-outline btn-mini" @click="openDetail(r)">详情</button>
                <button v-if="isAdmin" type="button" class="btn-primary btn-mini" style="margin-left:4px;" @click="openEditModal(r)">编辑</button>
                <button v-if="isAdmin" type="button" class="btn-danger btn-mini" style="margin-left:4px;" @click="deleteSingle(r)">删除</button>
              </td>
            </tr>
            <tr v-if="recordList.length === 0">
              <td colspan="11" style="text-align:center; padding:35px; color:#888;">暂无返厂记录</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 分页栏 -->
      <div class="bb-pagination-bar">
        <div style="color:#64748b;">共 <strong>{{ totalRecords }}</strong> 条记录，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页</div>
        <el-pagination v-model:current-page="curPage" :page-size="30" :total="totalRecords" layout="prev, pager, next" @current-change="loadTable" />
      </div>
    </div>

    <!-- 弹窗 1: 添加/编辑返厂记录 -->
    <el-dialog v-model="modalVisible" :title="modalTitle" width="760px" destroy-on-close>
      <el-form label-width="110px" size="small">
        <div class="form-grid">
          <div>
            <el-form-item label="SN (22位) *" required>
              <div style="position:relative; width:100%;">
                <input
                  ref="snInputRef"
                  v-model="form.sn"
                  type="text"
                  maxlength="22"
                  class="bb-input"
                  style="width:100%; font-family:monospace;"
                  placeholder="输入22位SN后回车检索..."
                  @blur="fetchBySn(false)"
                  @keyup.enter="handleSnEnter"
                />
                <span v-if="searchingSn" style="position:absolute; right:10px; top:6px; color:#409EFF; font-size:12px;">
                  检索中...
                </span>
              </div>
              <small style="color:#888; font-size:11.5px;">按回车从离散任务中检索工单号及物料描述并跳转到 PSN</small>
            </el-form-item>
          </div>

          <div>
            <el-form-item label="工单号 *" required>
              <div style="display:flex; gap:4px; width:100%;">
                <input
                  ref="woInputRef"
                  v-model="form.work_order"
                  type="text"
                  class="bb-input"
                  style="flex:1;"
                  placeholder="由SN自动带出或手动输入..."
                  @blur="fetchByOrder"
                  @keyup.enter="focusPsn"
                />
                <button type="button" class="btn-outline btn-mini" @click="fetchByOrder">查描述</button>
              </div>
            </el-form-item>
          </div>

          <div class="form-full">
            <el-form-item label="物料描述 *" required>
              <textarea v-model="form.description" class="bb-input" rows="2" style="width:100%; height:auto;" placeholder="自动带出或手动填写..."></textarea>
            </el-form-item>
          </div>

          <div>
            <el-form-item label="PSN (13位) *" required>
              <input
                ref="psnInputRef"
                v-model="form.psn"
                type="text"
                maxlength="13"
                class="bb-input"
                style="width:100%; font-family:monospace;"
                placeholder="13位数字和字母组合..."
              />
            </el-form-item>
          </div>

          <div>
            <el-form-item label="返回北变时间 *" required>
              <input v-model="form.return_time" type="date" class="bb-input" style="width:100%;" />
            </el-form-item>
          </div>

          <div>
            <el-form-item label="是否返回">
              <select v-model="form.is_returned" class="bb-select" style="width:100%;">
                <option value="否">否</option>
                <option value="是">是</option>
              </select>
            </el-form-item>
          </div>

          <div>
            <el-form-item label="返回时间 (选填)">
              <input v-model="form.actual_return_date" type="date" class="bb-input" style="width:100%;" />
            </el-form-item>
          </div>

          <div>
            <el-form-item label="操作人">
              <input v-model="form.operator" class="bb-input" disabled style="background:#f8f9fa; width:100%;" />
            </el-form-item>
          </div>

          <div class="form-full">
            <el-form-item label="备注说明">
              <textarea v-model="form.remarks" class="bb-input" rows="2" style="width:100%; height:auto;" placeholder="可填写特殊说明..."></textarea>
            </el-form-item>
          </div>
        </div>
      </el-form>
      <template #footer>
        <el-button @click="modalVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitSave">保存记录</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 2: 详情展示 -->
    <el-dialog v-model="detailModalVisible" title="返厂记录详情" width="620px" destroy-on-close>
      <div v-if="detailRecord" style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:12.5px;">
        <div><strong>工单号：</strong> <span style="font-family:monospace; font-weight:bold;">{{ detailRecord.work_order }}</span></div>
        <div><strong>返回北变时间：</strong> {{ detailRecord.return_time }}</div>
        <div><strong>SN：</strong> <span style="font-family:monospace;">{{ detailRecord.sn }}</span></div>
        <div><strong>PSN：</strong> <span style="font-family:monospace; font-weight:600;">{{ detailRecord.psn }}</span></div>
        <div><strong>是否返回：</strong> {{ detailRecord.is_returned || '否' }}</div>
        <div><strong>返回时间：</strong> {{ detailRecord.actual_return_date || '-' }}</div>
        <div><strong>操作人：</strong> {{ detailRecord.operator }}</div>
        <div><strong>登记时间：</strong> {{ detailRecord.created_at }}</div>
        <div style="grid-column: span 2;">
          <strong>物料描述：</strong>
          <div class="info-block">{{ detailRecord.description || '-' }}</div>
        </div>
        <div style="grid-column: span 2;">
          <strong>备注说明：</strong>
          <div class="info-block">{{ detailRecord.remarks || '-' }}</div>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, nextTick, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Van, Plus, Download, Search, Refresh, Delete, Check, Close, Warning
} from '@element-plus/icons-vue';
import request from '@/api/request';

const isAdmin = ref(false);
const loading = ref(false);
const recordList = ref([]);
const totalRecords = ref(0);
const curPage = ref(1);
const totalPages = ref(1);
const selectedIds = ref([]);
const allSelected = ref(false);

const searchType = ref('all');
const searchKeyword = ref('');

const snInputRef = ref(null);
const woInputRef = ref(null);
const psnInputRef = ref(null);
const searchingSn = ref(false);

const loadTable = async (p = 1) => {
  curPage.value = p;
  loading.value = true;
  try {
    const res = await request.get('/beibian/list', {
      params: {
        page: p,
        search_type: searchType.value,
        search_all: searchKeyword.value.trim()
      }
    });
    if (res.data) {
      recordList.value = res.data.records || [];
      totalRecords.value = res.data.total_records || 0;
      totalPages.value = res.data.total_pages || 1;
      isAdmin.value = !!res.data.is_admin;
      selectedIds.value = [];
      allSelected.value = false;
    }
  } finally {
    loading.value = false;
  }
};

const resetSearch = () => {
  searchType.value = 'all';
  searchKeyword.value = '';
  loadTable(1);
};

const toggleSelectAll = () => {
  if (allSelected.value) {
    selectedIds.value = recordList.value.map(r => r.id);
  } else {
    selectedIds.value = [];
  }
};

// ---------------- 录入/编辑表单 ----------------
const modalVisible = ref(false);
const saving = ref(false);
const editingId = ref(null);

const form = reactive({
  work_order: '',
  description: '',
  sn: '',
  psn: '',
  return_time: '',
  is_returned: '否',
  actual_return_date: '',
  operator: '',
  remarks: ''
});

const modalTitle = computed(() => editingId.value ? `✏️ 编辑返厂记录 [工单: ${form.work_order}]` : '➕ 添加新记录');

const openAddModal = () => {
  editingId.value = null;
  const user = JSON.parse(localStorage.getItem('user') || '{}');
  Object.assign(form, {
    work_order: '',
    description: '',
    sn: '',
    psn: '',
    return_time: new Date().toISOString().split('T')[0],
    is_returned: '否',
    actual_return_date: '',
    operator: user.full_name || user.username || '',
    remarks: ''
  });
  modalVisible.value = true;
  nextTick(() => snInputRef.value?.focus());
};

const openEditModal = (r) => {
  editingId.value = r.id;
  Object.assign(form, r);
  modalVisible.value = true;
};

const fetchBySn = async (showNotice = true) => {
  const snVal = form.sn.trim();
  if (!snVal) return false;

  searchingSn.value = true;
  try {
    const res = await request.post('/beibian/search_sn', { sn: snVal });
    if (res.data?.found) {
      if (res.data.work_order) form.work_order = res.data.work_order;
      if (res.data.description) form.description = res.data.description;
      if (showNotice) ElMessage.success('已自动带出离散任务工单及物料描述');
      return true;
    } else {
      if (showNotice) ElMessage.info('未在离散任务中检索到该批号对应的工单，请手动输入');
    }
  } catch (e) {
  } finally {
    searchingSn.value = false;
  }
  return false;
};

const fetchByOrder = async () => {
  const wo = form.work_order.trim();
  if (!wo) return;
  try {
    const res = await request.post('/beibian/search_order', { work_order: wo });
    if (res.data?.found && res.data?.description) {
      form.description = res.data.description;
      ElMessage.success('已匹配物料描述');
    }
  } catch (e) {}
};

const handleSnEnter = async () => {
  await fetchBySn(true);
  nextTick(() => {
    psnInputRef.value?.focus();
  });
};

const focusPsn = () => {
  psnInputRef.value?.focus();
};

const submitSave = async () => {
  if (!form.work_order || !form.description || !form.sn || !form.psn || !form.return_time) {
    return ElMessage.warning('工单号、描述、SN、PSN、返回北变时间为必填项');
  }
  if (!/^\d+$/.test(form.work_order)) return ElMessage.warning('工单号必须为纯数字');
  if (!/^\d{22}$/.test(form.sn)) return ElMessage.warning('SN必须为22位纯数字');
  if (!/^[A-Za-z0-9]{13}$/.test(form.psn)) return ElMessage.warning('PSN必须为13位字母或数字的组合');

  saving.value = true;
  try {
    const act = editingId.value ? '/beibian/update' : '/beibian/add';
    const payload = { ...form, id: editingId.value };
    const res = await request.post(act, payload);
    ElMessage.success(res.message || '保存成功');
    modalVisible.value = false;
    loadTable(curPage.value);
  } finally {
    saving.value = false;
  }
};

// ---------------- 详情 ----------------
const detailModalVisible = ref(false);
const detailRecord = ref(null);
const openDetail = (r) => {
  detailRecord.value = r;
  detailModalVisible.value = true;
};

// ---------------- 删除 ----------------
const deleteSingle = (r) => {
  ElMessageBox.confirm(`确定要删除工单 [${r.work_order}]、SN [${r.sn}] 的返厂记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/beibian/delete', { ids: [r.id] });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const batchDelete = () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请勾选记录！');
  ElMessageBox.confirm(`确定要永久删除选中的 ${selectedIds.value.length} 条返厂记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/beibian/delete', { ids: selectedIds.value });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

// ---------------- 导出 ----------------
const exportData = () => {
  const type = searchType.value;
  const all = searchKeyword.value.trim();
  window.open(`/api/beibian/export?search_type=${encodeURIComponent(type)}&search_all=${encodeURIComponent(all)}`, '_blank');
};

onMounted(() => {
  loadTable(1);
});
</script>

<style scoped>
.bb-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.bb-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.bb-toolbar {
  padding: 8px 12px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.bb-search-area {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
  flex: 1;
}
.bb-batch-bar {
  background: #e0f2fe;
  padding: 6px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #bae6fd;
  font-size: 12.5px;
}

.bb-table-wrapper {
  width: 100%;
  overflow-x: auto;
  max-height: calc(100vh - 350px);
  min-height: 280px;
  border-top: 1px solid #dee2e6;
}
.bb-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed !important;
  font-size: 13px;
  text-align: center;
  color: #333;
}
.bb-fixed-table thead th {
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
}
.bb-fixed-table tbody td {
  padding: 7px 6px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bb-fixed-table tbody td.col-bb-actions {
  overflow: visible !important;
  text-overflow: clip !important;
}
.bb-fixed-table tbody tr:hover { background-color: #f8fafc; }
.bb-fixed-table tbody tr.selected { background-color: #e0f2fe !important; }

.col-bb-desc { width: 22% !important; min-width: 150px; text-align: left; }
.col-bb-rem { width: 15% !important; min-width: 120px; text-align: left; }
.cell-truncate { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle; }

.badge-tag { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 11.5px; font-weight: 500; }
.badge-tag-yes { background: #d4edda; color: #155724; }
.badge-tag-no { background: #f8d7da; color: #721c24; }

.bb-pagination-bar {
  padding: 8px 14px;
  background: #f8f9fa;
  border-top: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  flex-wrap: wrap;
  gap: 8px;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 14px;
}
.form-full { grid-column: span 2; }

.info-block {
  background: #f8f9fa;
  padding: 6px 10px;
  border-radius: 4px;
  margin-top: 3px;
  border: 1px solid #dee2e6;
}

.bb-input {
  height: 32px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.bb-input:focus { border-color: #80bdff; }

.bb-select {
  height: 32px;
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