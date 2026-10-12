<template>
  <div class="diary-container">
    <!-- 顶部标题卡片 -->
    <div class="diary-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Notebook /></el-icon>
          工作日记管理系统
        </span>
        <div style="display:flex; gap:6px;">
          <button type="button" class="btn-success btn-sm" @click="openAddModal">
            <el-icon class="mr-1"><Plus /></el-icon> 添加工作日记
          </button>
          <button type="button" class="btn-primary btn-sm" @click="exportModalVisible = true">
            <el-icon class="mr-1"><Download /></el-icon> 导出数据
          </button>
        </div>
      </div>
    </div>

    <!-- 主卡片 -->
    <div class="diary-card">
      <!-- 组合筛选栏 -->
      <div class="diary-filter-card">
        <div class="diary-filter-grid">
          <input v-model="filterStartDate" type="date" class="diary-input" style="width:135px;" title="开始日期" />
          <span style="color:#888; font-size:12px;">至</span>
          <input v-model="filterEndDate" type="date" class="diary-input" style="width:135px;" title="结束日期" />
          <select v-model="filterWorkContent" class="diary-select" style="width:150px;">
            <option value="">全部工作内容</option>
            <option v-for="opt in workOpts" :key="opt" :value="opt">{{ opt }}</option>
          </select>
          <input v-model="filterOperator" type="text" class="diary-input" style="width:110px;" placeholder="作业者..." />
          <input v-model="filterSearchAll" type="text" class="diary-input" style="flex:1; min-width:180px;" placeholder="搜索工单、代码、描述、备注..." @keyup.enter="loadTable(1)" />
          <button type="button" class="btn-primary btn-sm" @click="loadTable(1)">
            <el-icon class="mr-1"><Search /></el-icon> 搜索
          </button>
          <button type="button" class="btn-outline btn-sm" @click="resetFilter">
            <el-icon class="mr-1"><Refresh /></el-icon> 重置
          </button>
        </div>
      </div>

      <!-- 批量操作栏 -->
      <div v-if="selectedIds.length > 0 && isAdmin" class="diary-batch-bar">
        <span style="color:#0369a1;">已勾选 <strong style="color:#409EFF;">{{ selectedIds.length }}</strong> 条日记</span>
        <div style="display:flex; gap:6px;">
          <button type="button" class="btn-danger btn-mini" @click="batchDelete">
            <el-icon class="mr-1"><Delete /></el-icon> 批量删除
          </button>
          <button type="button" class="btn-outline btn-mini" @click="selectedIds = []">取消选择</button>
        </div>
      </div>

      <!-- 表格主体 -->
      <div class="diary-table-wrapper" v-loading="loading">
        <table class="diary-fixed-table" style="min-width: 1200px;">
          <thead>
            <tr>
              <th style="width: 36px; text-align: center;"><input type="checkbox" v-model="allSelected" @change="toggleSelectAll" /></th>
              <th style="width: 85px;">日期</th>
              <th style="width: 85px;">工单号</th>
              <th style="width: 85px;">产品代码</th>
              <th class="col-desc">物料描述</th>
              <th style="width: 75px;">工单类型</th>
              <th style="width: 105px;">工作内容</th>
              <th style="width: 55px;">数量</th>
              <th style="width: 75px;">时长(分)</th>
              <th style="width: 75px;">作业者</th>
              <th style="width: 60px; text-align:center;">代写</th>
              <th class="col-remarks">备注说明</th>
              <th style="width: 130px; text-align: center;">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in recordList" :key="r.id" :class="{ selected: selectedIds.includes(r.id) }">
              <td style="text-align:center;"><input type="checkbox" v-model="selectedIds" :value="r.id" /></td>
              <td>{{ r.work_date }}</td>
              <td><strong style="font-family:monospace; color:#2c3e50;">{{ r.task_order || '-' }}</strong></td>
              <td><span style="font-family:monospace;">{{ r.product_code || '-' }}</span></td>
              <td class="col-desc"><span class="cell-truncate" :title="r.description">{{ r.description || '-' }}</span></td>
              <td>{{ r.task_type || '-' }}</td>
              <td><span class="badge" style="background:#e8f4fd; color:#409EFF; padding:2px 6px; border-radius:4px;">{{ r.work_content }}</span></td>
              <td>{{ r.quantity != null ? r.quantity : '-' }}</td>
              <td>{{ r.duration ?? '-' }}</td>
              <td>{{ r.operator }}</td>
              <td style="text-align:center;">
                <span class="badge" :style="{ background: r.is_ghost_write === '是' ? '#fff3cd' : '#e2e3e5', color: r.is_ghost_write === '是' ? '#856404' : '#383d41', padding:'2px 6px', borderRadius:'4px' }">
                  {{ r.is_ghost_write || '否' }}
                </span>
              </td>
              <td class="col-remarks"><span class="cell-truncate" :title="r.remarks">{{ r.remarks || '-' }}</span></td>
              <td style="text-align:center; white-space:nowrap;">
                <button type="button" class="btn-outline btn-mini" @click="openDetail(r)">详情</button>
                <button v-if="r.can_edit" type="button" class="btn-primary btn-mini" style="margin-left:3px;" @click="openEditModal(r)">编辑</button>
                <button v-if="isAdmin" type="button" class="btn-danger btn-mini" style="margin-left:3px;" @click="deleteSingle(r.id)">删除</button>
              </td>
            </tr>
            <tr v-if="recordList.length === 0">
              <td colspan="13" style="text-align:center; padding:35px; color:#888;">暂无工作日记记录</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 分页栏 -->
      <div class="diary-pagination-bar">
        <div style="color:#64748b;">共 <strong>{{ totalRecords }}</strong> 条记录，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页</div>
        <el-pagination v-model:current-page="curPage" :page-size="30" :total="totalRecords" layout="prev, pager, next" @current-change="loadTable" />
      </div>
    </div>

    <!-- 弹窗 1: 添加/编辑工作日记 -->
    <el-dialog v-model="modalVisible" :title="modalTitle" width="760px" destroy-on-close>
      <el-form label-width="100px" size="small">
        <div class="form-grid">
          <el-form-item label="工作日期" required>
            <el-input v-model="form.work_date" type="date" style="width:100%;" />
          </el-form-item>
          <el-form-item :label="form.work_content === '返工' ? '工单号 *' : '工单号'">
            <div style="display:flex; width:100%; gap:4px;">
              <el-input v-model="form.task_order" placeholder="工单号..." @blur="fetchTaskInfo" @keyup.enter="fetchTaskInfo" />
              <button type="button" class="btn-outline btn-mini" @click="fetchTaskInfo">检索</button>
            </div>
          </el-form-item>
          <el-form-item label="产品代码">
            <el-input v-model="form.product_code" readonly style="background:#f8f9fa;" />
          </el-form-item>
          <el-form-item label="工单类型">
            <el-input v-model="form.task_type" readonly style="background:#f8f9fa;" />
          </el-form-item>
          <div style="grid-column: span 2;">
            <el-form-item :label="form.work_content === '返工' ? '物料描述 *' : '物料描述'">
              <el-input v-model="form.description" type="textarea" :rows="2" readonly style="background:#f8f9fa;" />
            </el-form-item>
          </div>
          <el-form-item label="工作内容" required>
            <el-select v-model="form.work_content" style="width:100%;" @change="onWorkContentChange">
              <el-option v-for="opt in workOpts" :key="opt" :label="opt" :value="opt" />
            </el-select>
          </el-form-item>
          <el-form-item :label="form.work_content === '返工' ? '数量(个) *' : '数量 (个)'">
            <el-input-number v-model="form.quantity" :min="0" style="width:100%;" @change="checkQuota" />
            <div style="font-size:11.5px; margin-top:2px;" :style="quotaTipStyle">{{ quotaTipText }}</div>
          </el-form-item>
          <el-form-item label="工作时长(分)" required>
            <el-input-number v-model="form.duration" :min="1" style="width:100%;" />
          </el-form-item>
          <el-form-item label="是否代写" required>
            <el-select v-model="form.is_ghost_write" style="width:100%;">
              <el-option label="否" value="否" />
              <el-option label="是" value="是" />
            </el-select>
          </el-form-item>
          <el-form-item label="作业者">
            <el-input v-model="form.operator" disabled style="background:#f8f9fa;" />
          </el-form-item>
          <div style="grid-column: span 2;">
            <el-form-item :label="form.work_content === '其他' ? '备注说明 *' : '备注说明'">
              <el-input v-model="form.remarks" type="textarea" :rows="2" placeholder="工作心得或特殊说明..." />
              <div v-if="form.work_content === '其他'" style="color:#F56C6C; font-size:11px;">选择“其他”工作内容时，备注说明必须填写</div>
            </el-form-item>
          </div>
        </div>
      </el-form>
      <template #footer>
        <el-button @click="modalVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitSave">保存工作日记</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 2: 日记详情 -->
    <el-dialog v-model="detailModalVisible" title="工作日记详情" width="600px" destroy-on-close>
      <div v-if="detailRecord" style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:13px;">
        <div><strong>工作日期：</strong> {{ detailRecord.work_date }}</div>
        <div><strong>工单号：</strong> <strong style="font-family:monospace;">{{ detailRecord.task_order || '-' }}</strong></div>
        <div><strong>产品代码：</strong> <span style="font-family:monospace;">{{ detailRecord.product_code || '-' }}</span></div>
        <div><strong>工单类型：</strong> {{ detailRecord.task_type || '-' }}</div>
        <div><strong>工作内容：</strong> <span class="badge" style="background:#e8f4fd; color:#409EFF; padding:2px 6px;">{{ detailRecord.work_content }}</span></div>
        <div><strong>数量：</strong> {{ detailRecord.quantity != null ? detailRecord.quantity : '-' }}</div>
        <div><strong>工作时长：</strong> {{ detailRecord.duration }} 分钟</div>
        <div><strong>作业者：</strong> {{ detailRecord.operator }}</div>
        <div><strong>是否代写：</strong> {{ detailRecord.is_ghost_write || '否' }}</div>
        <div><strong>创建时间：</strong> {{ detailRecord.created_at }}</div>
        <div style="grid-column: span 2;"><strong>物料描述：</strong><div class="info-block">{{ detailRecord.description || '-' }}</div></div>
        <div style="grid-column: span 2;"><strong>备注说明：</strong><div class="info-block">{{ detailRecord.remarks || '-' }}</div></div>
      </div>
    </el-dialog>

    <!-- 弹窗 3: 导出数据 -->
    <el-dialog v-model="exportModalVisible" title="导出数据选项" width="440px" destroy-on-close>
      <el-radio-group v-model="exportType" style="display:flex; flex-direction:column; gap:10px; align-items:flex-start;">
        <el-radio label="all">导出全部数据 (权限范围内)</el-radio>
        <el-radio label="filtered">导出当前筛选条件下的数据</el-radio>
        <el-radio label="selected">导出已勾选的数据 ({{ selectedIds.length }} 条)</el-radio>
      </el-radio-group>
      <template #footer>
        <el-button @click="exportModalVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmExport"><el-icon class="mr-1"><Download /></el-icon> 开始导出 Excel</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 4: 全权限分配 (管理员专属) -->
    <el-dialog v-model="permModalVisible" title="工作日记全权限分配" width="650px" destroy-on-close>
      <div style="margin-bottom:8px; font-size:12px; color:#64748b;">
        说明：开启【全权限】后，该用户可直接查看并管理全厂所有人员的工作日记。
      </div>
      <div style="max-height:360px; overflow-y:auto;">
        <table class="diary-fixed-table" style="text-align:left;">
          <thead>
            <tr>
              <th>用户账号</th>
              <th>姓名</th>
              <th>角色</th>
              <th style="width:100px; text-align:center;">全权限 (查看所有)</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in permUserList" :key="u.id">
              <td><strong>@{{ u.username }}</strong></td>
              <td>{{ u.full_name || '-' }}</td>
              <td><span class="badge" :style="{ background: u.is_admin ? '#F56C6C' : '#e2e3e5', color: u.is_admin ? '#fff' : '#333', padding:'2px 6px' }">{{ u.is_admin ? '管理员' : '普通用户' }}</span></td>
              <td style="text-align:center;"><el-checkbox v-model="u.can_view_all" :true-value="1" :false-value="0" @change="updateUserPerm(u)" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Notebook, Plus, Download, Search, Refresh,
  Setting, Delete, Check, Close, Warning
} from '@element-plus/icons-vue';
import request from '@/api/request';

const workOpts = [
  '板卡成品入库调试', '板卡程序烧录', '板卡调试', '拷机前调试',
  '绝缘耐压测试', '功能测试', '大电流测试', '校准', '保护功能',
  '参数设置', '三防涂覆', '自动测试', '阻抗测试', 'QC检查',
  '装置调试', '返工', '其他'
];

const isAdmin = ref(false);
const loading = ref(false);
const recordList = ref([]);
const totalRecords = ref(0);
const curPage = ref(1);
const totalPages = ref(1);
const selectedIds = ref([]);
const allSelected = ref(false);

const filterStartDate = ref('');
const filterEndDate = ref('');
const filterWorkContent = ref('');
const filterOperator = ref('');
const filterSearchAll = ref('');

const loadTable = async (p = 1) => {
  curPage.value = p;
  loading.value = true;
  try {
    const res = await request.get('/diary/list', {
      params: {
        page: p,
        filter_start_date: filterStartDate.value,
        filter_end_date: filterEndDate.value,
        filter_work_content: filterWorkContent.value,
        filter_operator: filterOperator.value.trim(),
        search_all: filterSearchAll.value.trim()
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

const resetFilter = () => {
  filterStartDate.value = '';
  filterEndDate.value = '';
  filterWorkContent.value = '';
  filterOperator.value = '';
  filterSearchAll.value = '';
  loadTable(1);
};

const toggleSelectAll = () => {
  if (allSelected.value) {
    selectedIds.value = recordList.value.map(r => r.id);
  } else {
    selectedIds.value = [];
  }
};

// ---------------- 录入/编辑与配额校验 ----------------
const modalVisible = ref(false);
const saving = ref(false);
const editingId = ref(null);
const quotaTipText = ref('');
const quotaTipStyle = ref({});

const form = reactive({
  work_date: '',
  task_order: '',
  product_code: '',
  task_type: '',
  description: '',
  work_content: '',
  quantity: 0,
  duration: 60,
  is_ghost_write: '否',
  operator: '',
  remarks: ''
});

const modalTitle = computed(() => editingId.value ? `✏️ 编辑工作日记 [${form.work_date}]` : '➕ 添加工作日记');

const openAddModal = () => {
  editingId.value = null;
  const user = JSON.parse(localStorage.getItem('user') || '{}');
  Object.assign(form, {
    work_date: new Date().toISOString().split('T')[0],
    task_order: '',
    product_code: '',
    task_type: '',
    description: '',
    work_content: '',
    quantity: 0,
    duration: 60,
    is_ghost_write: '否',
    operator: user.full_name || user.username || '',
    remarks: ''
  });
  quotaTipText.value = '';
  modalVisible.value = true;
};

const openEditModal = (r) => {
  editingId.value = r.id;
  Object.assign(form, r);
  modalVisible.value = true;
  checkQuota();
};

const fetchTaskInfo = async () => {
  const order = form.task_order.trim();
  if (!order) return;
  try {
    const res = await request.post('/diary/search_task', { task_order: order });
    if (res.data?.found && res.data?.task_info) {
      const info = res.data.task_info;
      form.product_code = info.product_code || '';
      form.description = info.description || '';
      form.task_type = info.task_type || '';
      checkQuota();
    }
  } catch (e) {}
};

const onWorkContentChange = () => {
  checkQuota();
};

const checkQuota = async () => {
  const order = form.task_order.trim();
  const wc = form.work_content;
  const qty = form.quantity || 0;
  if (!order || !wc) {
    quotaTipText.value = '';
    return;
  }
  try {
    const res = await request.post('/diary/check_quantity', {
      task_order: order,
      work_content: wc,
      quantity: qty,
      exclude_id: editingId.value || 0
    });
    if (res.data) {
      const d = res.data;
      if (d.has_limit) {
        if (d.remaining <= 0 && qty <= 0) {
          quotaTipText.value = `⚠️ 工单总数 ${d.total_quantity} 个，该工序已全部录完 (剩余: 0)`;
          quotaTipStyle.value = { color: '#dc3545', fontWeight: 'bold' };
        } else if (d.available) {
          quotaTipText.value = `✓ 工序总数: ${d.total_quantity}，他人已填: ${d.used_quantity}，当前最多还可填: ${d.remaining} 个`;
          quotaTipStyle.value = { color: '#28a745' };
        } else {
          quotaTipText.value = `❌ 超出工单上限！总数: ${d.total_quantity}，已占用: ${d.used_quantity}，最多只可填 ${d.remaining} 个`;
          quotaTipStyle.value = { color: '#dc3545', fontWeight: 'bold' };
        }
      } else {
        quotaTipText.value = 'ℹ️ 任务中未设置总数量限制';
        quotaTipStyle.value = { color: '#007bff' };
      }
    }
  } catch (e) {}
};

const submitSave = async () => {
  if (!form.work_date || !form.work_content || form.duration <= 0) {
    return ElMessage.warning('工作日期、工作内容和工作时长必须填写且时长大于0');
  }
  if (form.work_content === '返工' && (!form.task_order || form.quantity <= 0)) {
    return ElMessage.warning('选择“返工”时，工单号和数量必须填写！');
  }
  if (form.work_content === '其他' && !form.remarks.trim()) {
    return ElMessage.warning('选择“其他”工作内容时，备注说明必须填写！');
  }

  saving.value = true;
  try {
    const act = editingId.value ? '/diary/update' : '/diary/add';
    const payload = { ...form, record_id: editingId.value };
    const res = await request.post(act, payload);
    ElMessage.success(res.message || '保存成功');
    modalVisible.value = false;
    loadTable(curPage.value);
  } finally {
    saving.value = false;
  }
};

// ---------------- 详情查看 ----------------
const detailModalVisible = ref(false);
const detailRecord = ref(null);
const openDetail = (r) => {
  detailRecord.value = r;
  detailModalVisible.value = true;
};

// ---------------- 批量与单条删除 ----------------
const deleteSingle = (id) => {
  ElMessageBox.confirm('确定要删除这条工作日记吗？', '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/diary/delete', { ids: [id] });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const batchDelete = () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请勾选记录！');
  ElMessageBox.confirm(`确定要永久删除选中的 ${selectedIds.value.length} 条工作日记吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/diary/delete', { ids: selectedIds.value });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

// ---------------- 导出 ----------------
const exportModalVisible = ref(false);
const exportType = ref('all');
const confirmExport = () => {
  const ids = selectedIds.value.join(',');
  const url = `/api/diary/export?export_type=${exportType.value}&filter_start_date=${encodeURIComponent(filterStartDate.value)}&filter_end_date=${encodeURIComponent(filterEndDate.value)}&filter_work_content=${encodeURIComponent(filterWorkContent.value)}&filter_operator=${encodeURIComponent(filterOperator.value.trim())}&search_all=${encodeURIComponent(filterSearchAll.value.trim())}&selected_ids=${encodeURIComponent(ids)}`;
  window.open(url, '_blank');
  exportModalVisible.value = false;
};

// ---------------- 全权限分配 (管理员专属) ----------------
const permModalVisible = ref(false);
const permUserList = ref([]);
const openPermModal = async () => {
  permModalVisible.value = true;
  const res = await request.get('/diary/get_users');
  if (res.data) {
    permUserList.value = res.data.users || [];
  }
};

const updateUserPerm = async (u) => {
  try {
    await request.post('/diary/update_permission', { user_id: u.id, can_view_all: u.can_view_all });
    ElMessage.success('权限更新成功');
  } catch (e) {
    u.can_view_all = u.can_view_all ? 0 : 1;
  }
};

onMounted(() => {
  loadTable(1);
});
</script>

<style scoped>
.diary-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.diary-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.diary-filter-card {
  padding: 8px 12px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}
.diary-filter-grid {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}
.diary-batch-bar {
  background: #e0f2fe;
  padding: 6px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #bae6fd;
  font-size: 12.5px;
}

.diary-table-wrapper {
  width: 100%;
  overflow-x: auto;
  max-height: calc(100vh - 350px);
  min-height: 280px;
  border-top: 1px solid #dee2e6;
}
.diary-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed !important;
  font-size: 13px;
  text-align: center;
  color: #333;
}
.diary-fixed-table thead th {
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
.diary-fixed-table tbody td {
  padding: 7px 6px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
}
.diary-fixed-table tbody tr:hover { background-color: #f8fafc; }
.diary-fixed-table tbody tr.selected { background-color: #e0f2fe !important; }

.col-desc { width: 20% !important; min-width: 140px; text-align: left; }
.col-remarks { width: 15% !important; min-width: 120px; text-align: left; }
.cell-truncate { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle; }

.diary-pagination-bar {
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
.info-block {
  background: #f8f9fa;
  padding: 6px 10px;
  border-radius: 4px;
  margin-top: 3px;
  border: 1px solid #dee2e6;
}

.diary-input {
  height: 32px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
}
.diary-input:focus { border-color: #80bdff; }

.diary-select {
  height: 32px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 8px;
  outline: none;
  background: #fff;
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