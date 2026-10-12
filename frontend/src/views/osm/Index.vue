<template>
  <div class="osm-container">
    <!-- 顶部标题卡片 -->
    <div class="osm-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Van /></el-icon>
          外协管理系统
        </span>
        <div style="display:flex; gap:6px;">
          <button v-if="isAdmin || osmPerms.can_upload" type="button" class="btn-primary btn-sm" @click="openUploadModal">
            <el-icon class="mr-1"><Upload /></el-icon> 批量导入
          </button>
          <button v-if="isAdmin || osmPerms.can_add_return" type="button" class="btn-success btn-sm" @click="openReturnModal">
            <el-icon class="mr-1"><Back /></el-icon> 添加返回
          </button>
          <button v-if="isAdmin" type="button" class="btn-outline btn-sm" style="border-color:#17a2b8; color:#17a2b8;" @click="openPermModal">
            <el-icon class="mr-1"><UserFilled /></el-icon> 权限设置
          </button>
          <button type="button" class="btn-primary btn-sm" @click="exportData">
            <el-icon class="mr-1"><Download /></el-icon> 导出数据
          </button>
        </div>
      </div>
    </div>

    <!-- 筛选过滤与表格卡片 -->
    <div class="osm-card">
      <div class="osm-filter-card">
        <div class="osm-filter-grid">
          <input v-model="filterStart" type="date" class="osm-input" style="width:135px;" title="发货起始日期" @change="loadTable(1)" />
          <span style="color:#888; font-size:12px;">至</span>
          <input v-model="filterEnd" type="date" class="osm-input" style="width:135px;" title="发货结束日期" @change="loadTable(1)" />
          <select v-model="filterVendor" class="osm-select" style="width:140px;" @change="loadTable(1)">
            <option value="">全部外协厂家</option>
            <option v-for="v in vendorList" :key="v" :value="v">{{ v }}</option>
          </select>
          <select v-model="filterStatus" class="osm-select" style="width:120px;" @change="loadTable(1)">
            <option value="">全部状态</option>
            <option value="未返回">未返回</option>
            <option value="部分返回">部分返回</option>
            <option value="全部返回">全部返回</option>
          </select>
          <input
            v-model="searchKeyword"
            type="text"
            class="osm-input"
            style="flex:1; min-width:180px;"
            placeholder="搜索工单/代码/描述/SN..."
            @keyup.enter="loadTable(1)"
          />
          <button type="button" class="btn-primary btn-sm" @click="loadTable(1)">
            <el-icon class="mr-1"><Search /></el-icon> 搜索
          </button>
          <button type="button" class="btn-outline btn-sm" @click="resetFilter">
            <el-icon class="mr-1"><Refresh /></el-icon> 重置
          </button>
        </div>
      </div>

      <!-- 批量操作栏 -->
      <div v-if="selectedIds.length > 0 && (isAdmin || osmPerms.can_delete)" class="osm-batch-bar">
        <span style="color:#0369a1;">已勾选 <strong style="color:#409EFF;">{{ selectedIds.length }}</strong> 条记录</span>
        <div style="display:flex; gap:6px;">
          <button type="button" class="btn-danger btn-mini" @click="batchDelete">
            <el-icon class="mr-1"><Delete /></el-icon> 批量删除
          </button>
          <button type="button" class="btn-outline btn-mini" @click="selectedIds = []">取消选择</button>
        </div>
      </div>

      <!-- 表格主体 -->
      <div class="osm-table-wrapper" v-loading="loading">
        <table class="osm-fixed-table" style="min-width:1250px;">
          <thead>
            <tr>
              <th v-if="isAdmin || osmPerms.can_delete" style="width:36px; text-align:center;">
                <input type="checkbox" v-model="allSelected" @change="toggleSelectAll" />
              </th>
              <th style="width:110px;">工单号</th>
              <th style="width:110px;">产品代码</th>
              <th style="text-align:left;">物料描述</th>
              <th style="width:130px;">外协厂家</th>
              <th style="width:85px; text-align:right;">发货数量</th>
              <th style="width:85px; text-align:right;">已返回数</th>
              <th style="width:95px; text-align:center;">当前状态</th>
              <th style="width:105px;">发货日期</th>
              <th style="width:105px;">要求返回</th>
              <th style="text-align:left;">备注说明</th>
              <th v-if="isAdmin || osmPerms.can_edit || osmPerms.can_delete" style="width:120px; text-align:center;">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in recordList" :key="r.id" :class="{ selected: selectedIds.includes(r.id) }">
              <td v-if="isAdmin || osmPerms.can_delete" style="text-align:center;">
                <input type="checkbox" v-model="selectedIds" :value="r.id" />
              </td>
              <td><strong style="font-family:monospace; color:#2c3e50;">{{ r.work_order }}</strong></td>
              <td><span style="font-family:monospace;">{{ r.product_code || '-' }}</span></td>
              <td style="text-align:left;"><span class="cell-truncate" :title="r.description">{{ r.description || '-' }}</span></td>
              <td><strong>{{ r.vendor }}</strong></td>
              <td style="text-align:right;">{{ r.quantity }}</td>
              <td style="text-align:right; font-weight:bold; color:#28a745;">{{ r.returned_quantity }}</td>
              <td style="text-align:center;">
                <span class="badge" :style="getStatusStyle(r.status)">{{ r.status }}</span>
              </td>
              <td><span style="font-size:11.5px; color:#64748b;">{{ r.send_date }}</span></td>
              <td><span style="font-size:11.5px; color:#64748b;">{{ r.required_return_date || '-' }}</span></td>
              <td style="text-align:left;"><span class="cell-truncate" :title="r.remarks">{{ r.remarks || '-' }}</span></td>
              <td v-if="isAdmin || osmPerms.can_edit || osmPerms.can_delete" style="text-align:center; white-space:nowrap;">
                <button v-if="isAdmin || osmPerms.can_edit" type="button" class="btn-primary btn-mini" @click="openEditModal(r)">编辑</button>
                <button v-if="isAdmin || osmPerms.can_delete" type="button" class="btn-danger btn-mini" style="margin-left:4px;" @click="deleteSingle(r)">删除</button>
              </td>
            </tr>
            <tr v-if="recordList.length === 0">
              <td colspan="12" style="text-align:center; padding:35px; color:#888;">暂无外协记录</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 分页栏 -->
      <div class="osm-pagination-bar">
        <div style="color:#64748b;">共 <strong>{{ totalRecords }}</strong> 条记录，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页</div>
        <el-pagination v-model:current-page="curPage" :page-size="30" :total="totalRecords" layout="prev, pager, next" @current-change="loadTable" />
      </div>
    </div>

    <!-- 弹窗 1: 编辑记录 -->
    <el-dialog v-model="editModalVisible" title="编辑外协记录" width="680px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;">
        <div class="form-grid-2">
          <div>
            <label class="form-label">工单号 *</label>
            <input v-model="editForm.work_order" type="text" class="osm-input" style="width:100%; font-family:monospace;" required />
          </div>
          <div>
            <label class="form-label">产品代码</label>
            <input v-model="editForm.product_code" type="text" class="osm-input" style="width:100%; font-family:monospace;" />
          </div>
          <div style="grid-column: span 2;">
            <label class="form-label">物料描述</label>
            <input v-model="editForm.description" type="text" class="osm-input" style="width:100%;" />
          </div>
          <div>
            <label class="form-label">外协厂家 *</label>
            <input v-model="editForm.vendor" type="text" class="osm-input" style="width:100%;" required />
          </div>
          <div>
            <label class="form-label">发货数量 *</label>
            <input v-model.number="editForm.quantity" type="number" min="1" class="osm-input" style="width:100%;" required />
          </div>
          <div>
            <label class="form-label">发货日期 *</label>
            <input v-model="editForm.send_date" type="date" class="osm-input" style="width:100%;" required />
          </div>
          <div>
            <label class="form-label">要求返回日期</label>
            <input v-model="editForm.required_return_date" type="date" class="osm-input" style="width:100%;" />
          </div>
          <div style="grid-column: span 2;">
            <label class="form-label">备注说明</label>
            <textarea v-model="editForm.remarks" class="osm-input" rows="2" style="width:100%; height:auto;"></textarea>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button @click="editModalVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitEdit">保存修改</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 2: 添加返回 (工单号输入比对模式) -->
    <el-dialog v-model="returnModalVisible" title="外协产品返回登记" width="560px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;">
        <div style="margin-bottom:12px;">
          <label class="form-label">输入外协工单号 *</label>
          <div style="display:flex; gap:6px;">
            <input
              ref="returnWoInputRef"
              v-model="returnWoInput"
              type="text"
              class="osm-input"
              style="flex:1; font-family:monospace;"
              placeholder="输入工单号后回车自动比对..."
              @keyup.enter="verifyReturnWo"
            />
            <button type="button" class="btn-primary btn-sm" :disabled="verifyingWo" @click="verifyReturnWo">
              {{ verifyingWo ? '比对中...' : '比对工单' }}
            </button>
          </div>
        </div>

        <!-- 比对成功信息卡片 -->
        <div v-if="matchedReturnRecord" class="return-info-box">
          <div><strong>外协厂家：</strong> {{ matchedReturnRecord.vendor }}</div>
          <div style="margin-top:2px;">
            <strong>发货总数：</strong> {{ matchedReturnRecord.quantity }} 件 | 
            <strong>已返回数：</strong> {{ matchedReturnRecord.returned_quantity }} 件 | 
            <strong style="color:#059669;">当前待返：{{ matchedReturnRecord.pending_qty }} 件</strong>
          </div>
        </div>

        <div style="margin-bottom:10px;">
          <label class="form-label">本次返回数量 (台/件) *</label>
          <input v-model.number="returnQty" type="number" min="1" class="osm-input" style="width:100%;" placeholder="输入本次返回件数..." required />
        </div>
        <div style="margin-bottom:10px;">
          <label class="form-label">实际返回日期 *</label>
          <input v-model="returnDate" type="date" class="osm-input" style="width:100%;" required />
        </div>
        <div style="margin-bottom:10px;">
          <label class="form-label">返回说明/验收情况</label>
          <textarea v-model="returnRemarks" class="osm-input" rows="2" style="width:100%; height:auto;" placeholder="填写检验结论或异常说明..."></textarea>
        </div>
      </div>
      <template #footer>
        <el-button @click="returnModalVisible = false">取消</el-button>
        <el-button type="success" :loading="submittingReturn" @click="submitReturn">确认返回登记</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 3: 批量导入 -->
    <el-dialog v-model="uploadModalVisible" title="批量导入外协数据 (Excel/CSV)" width="480px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;">
        <div style="margin-bottom:12px;">
          <label class="form-label">选择数据文件 (Excel/CSV) *</label>
          <input ref="fileInputRef" type="file" accept=".xlsx,.xls,.csv" class="osm-input" style="width:100%; height:36px; padding:4px;" />
        </div>
        <div style="font-size:11.5px; color:#64748b; line-height:1.6;">
          💡 导入格式提示：文件表头须依次包含 <code>工单号</code>、<code>产品代码</code>、<code>物料描述</code>、<code>外协厂家</code>、<code>发货数量</code>、<code>发货日期</code>。
        </div>
      </div>
      <template #footer>
        <el-button @click="uploadModalVisible = false">取消</el-button>
        <el-button type="primary" :loading="uploading" @click="submitUpload">开始导入</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 4: 细分权限配置 (管理员专属) -->
    <el-dialog v-if="isAdmin" v-model="permModalVisible" title="外协管理系统细分权限配置" width="700px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12px;" v-loading="permLoading">
        <div style="margin-bottom:10px; color:#64748b;">
          说明：在此修改保存后，外协系统专属权限即时生效。
        </div>
        <div style="max-height:360px; overflow-y:auto; border:1px solid #dee2e6;">
          <table class="perm-table">
            <thead>
              <tr>
                <th>账号</th>
                <th>真实姓名</th>
                <th style="text-align:center;">允许编辑</th>
                <th style="text-align:center;">允许删除</th>
                <th style="text-align:center;">允许导入</th>
                <th style="text-align:center;">允许登记返回</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in permUserList" :key="u.user_id">
                <td><strong>@{{ u.username }}</strong></td>
                <td>{{ u.full_name || u.username }}</td>
                <td style="text-align:center;"><input type="checkbox" v-model="u.can_edit" :true-value="1" :false-value="0" /></td>
                <td style="text-align:center;"><input type="checkbox" v-model="u.can_delete" :true-value="1" :false-value="0" /></td>
                <td style="text-align:center;"><input type="checkbox" v-model="u.can_upload" :true-value="1" :false-value="0" /></td>
                <td style="text-align:center;"><input type="checkbox" v-model="u.can_add_return" :true-value="1" :false-value="0" /></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <template #footer>
        <el-button @click="permModalVisible = false">取消</el-button>
        <el-button type="primary" :loading="savingPerms" @click="submitPermissions">保存权限设置</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, nextTick } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Van, Upload, Back, UserFilled, Download, Search, Refresh, Delete, Plus, Edit
} from '@element-plus/icons-vue';
import request from '@/api/request';

const isAdmin = ref(false);
const osmPerms = reactive({
  can_edit: 0,
  can_delete: 0,
  can_upload: 0,
  can_add_return: 0
});

const loading = ref(false);
const recordList = ref([]);
const totalRecords = ref(0);
const curPage = ref(1);
const totalPages = ref(1);
const selectedIds = ref([]);
const allSelected = ref(false);

const vendorList = ref([]);
const filterVendor = ref('');
const filterStatus = ref('');
const filterStart = ref('');
const filterEnd = ref('');
const searchKeyword = ref('');

const getStatusStyle = (st) => {
  if (st === '全部返回') return { background: '#d4edda', color: '#155724' };
  if (st === '部分返回') return { background: '#fff3cd', color: '#856404' };
  return { background: '#e2e3e5', color: '#383d41' };
};

const initModule = async () => {
  try {
    const res = await request.get('/osm/init');
    if (res.data) {
      Object.assign(osmPerms, res.data.osm_perms || {});
      vendorList.value = res.data.vendors || [];
      isAdmin.value = !!res.data.is_admin;
    }
  } catch (e) {}
  loadTable(1);
};

const loadTable = async (p = 1) => {
  curPage.value = p;
  loading.value = true;
  try {
    const res = await request.get('/osm/list', {
      params: {
        page: p,
        start_date: filterStart.value,
        end_date: filterEnd.value,
        vendor: filterVendor.value,
        status: filterStatus.value,
        search: searchKeyword.value.trim()
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
  filterStart.value = '';
  filterEnd.value = '';
  filterVendor.value = '';
  filterStatus.value = '';
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

// 1. 编辑功能
const editModalVisible = ref(false);
const saving = ref(false);
const editForm = reactive({
  id: 0,
  work_order: '',
  product_code: '',
  description: '',
  vendor: '',
  quantity: 1,
  send_date: '',
  required_return_date: '',
  remarks: ''
});

const openEditModal = (r) => {
  Object.assign(editForm, {
    id: r.id,
    work_order: r.work_order,
    product_code: r.product_code || '',
    description: r.description || '',
    vendor: r.vendor,
    quantity: Number(r.quantity),
    send_date: r.send_date,
    required_return_date: r.required_return_date || '',
    remarks: r.remarks || ''
  });
  editModalVisible.value = true;
};

const submitEdit = async () => {
  if (!editForm.work_order || !editForm.vendor || !editForm.send_date || editForm.quantity <= 0) {
    return ElMessage.warning('工单号、外协厂家、发货数量和发货日期为必填项！');
  }

  saving.value = true;
  try {
    const res = await request.post('/osm/save', editForm);
    ElMessage.success(res.message || '修改成功');
    editModalVisible.value = false;
    loadTable(curPage.value);
  } finally {
    saving.value = false;
  }
};

// 2. 添加返回功能 (检索比对)
const returnModalVisible = ref(false);
const returnWoInput = ref('');
const returnWoInputRef = ref(null);
const verifyingWo = ref(false);
const matchedReturnRecord = ref(null);
const returnQty = ref(1);
const returnDate = ref(new Date().toISOString().split('T')[0]);
const returnRemarks = ref('');
const submittingReturn = ref(false);

const openReturnModal = () => {
  matchedReturnRecord.value = null;
  returnWoInput.value = '';
  returnQty.value = 1;
  returnDate.value = new Date().toISOString().split('T')[0];
  returnRemarks.value = '';
  returnModalVisible.value = true;
  nextTick(() => returnWoInputRef.value?.focus());
};

const verifyReturnWo = async () => {
  const woVal = returnWoInput.value.trim();
  if (!woVal) return ElMessage.warning('请输入外协工单号！');

  verifyingWo.value = true;
  try {
    const res = await request.get('/osm/get_pending_returns');
    if (res.data) {
      const list = res.data.list || [];
      const match = list.find(item => String(item.work_order).trim() === woVal);
      if (match) {
        matchedReturnRecord.value = match;
        returnQty.value = Number(match.pending_qty);
        ElMessage.success('比对成功，已自动带出待返批次信息！');
      } else {
        matchedReturnRecord.value = null;
        ElMessageBox.alert(`未检索到工单【${woVal}】的待返回记录！可能该工单已全部返回或发货记录不存在。`, '比对提醒', { type: 'warning' });
      }
    }
  } finally {
    verifyingWo.value = false;
  }
};

const submitReturn = async () => {
  if (!matchedReturnRecord.value) {
    return ElMessage.warning('请先输入工单号并完成有效比对！');
  }
  if (returnQty.value <= 0) {
    return ElMessage.warning('返回数量必须大于 0！');
  }
  if (returnQty.value > matchedReturnRecord.value.pending_qty) {
    return ElMessage.error(`返回数量超出！该工单当前最多还需返回 ${matchedReturnRecord.value.pending_qty} 件。`);
  }

  submittingReturn.value = true;
  try {
    const res = await request.post('/osm/add_return', {
      osm_id: matchedReturnRecord.value.id,
      return_quantity: returnQty.value,
      return_date: returnDate.value,
      remarks: returnRemarks.value.trim()
    });
    ElMessage.success(res.message || '返回登记成功！');
    returnModalVisible.value = false;
    loadTable(curPage.value);
  } finally {
    submittingReturn.value = false;
  }
};

// 3. 批量导入
const uploadModalVisible = ref(false);
const fileInputRef = ref(null);
const uploading = ref(false);

const openUploadModal = () => {
  uploadModalVisible.value = true;
};

const submitUpload = async () => {
  const file = fileInputRef.value?.files?.[0];
  if (!file) return ElMessage.warning('请选择 Excel 或 CSV 文件！');

  const fd = new FormData();
  fd.append('file', file);

  uploading.value = true;
  try {
    const res = await request.post('/osm/upload_osm', fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });
    ElMessage.success(res.message || '导入成功！');
    uploadModalVisible.value = false;
    loadTable(1);
  } finally {
    uploading.value = false;
  }
};

// 4. 删除
const deleteSingle = (r) => {
  ElMessageBox.confirm(`确定要删除工单 [${r.work_order}] 的外协记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/osm/delete', { ids: [r.id] });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const batchDelete = () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请勾选记录！');
  ElMessageBox.confirm(`确定要永久删除选中的 ${selectedIds.value.length} 条外协记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/osm/delete', { ids: selectedIds.value });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

// 5. 导出
const exportData = () => {
  const s = searchKeyword.value.trim();
  const v = filterVendor.value;
  const st = filterStatus.value;
  window.open(`/api/osm/export?search=${encodeURIComponent(s)}&vendor=${encodeURIComponent(v)}&status=${encodeURIComponent(st)}`, '_blank');
};

// 6. 细分权限管理
const permModalVisible = ref(false);
const permLoading = ref(false);
const savingPerms = ref(false);
const permUserList = ref([]);

const openPermModal = async () => {
  permModalVisible.value = true;
  permLoading.value = true;
  try {
    const res = await request.get('/osm/list_permissions');
    if (res.data) {
      permUserList.value = res.data.list || [];
    }
  } finally {
    permLoading.value = false;
  }
};

const submitPermissions = async () => {
  savingPerms.value = true;
  try {
    const res = await request.post('/osm/save_permissions', {
      permissions: permUserList.value
    });
    ElMessage.success(res.message || '权限保存成功！');
    permModalVisible.value = false;
  } finally {
    savingPerms.value = false;
  }
};

onMounted(() => {
  initModule();
});
</script>

<style scoped>
.osm-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.osm-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}

.osm-filter-card {
  padding: 8px 12px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}
.osm-filter-grid {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}

.osm-batch-bar {
  background: #e0f2fe;
  padding: 6px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #bae6fd;
  font-size: 12.5px;
}

.osm-table-wrapper {
  width: 100%;
  overflow-x: auto;
  max-height: calc(100vh - 350px);
  min-height: 280px;
  border-top: 1px solid #dee2e6;
}
.osm-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 13px;
  text-align: center;
  color: #333;
}
.osm-fixed-table thead th {
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
.osm-fixed-table tbody td {
  padding: 7px 6px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
}
.osm-fixed-table tbody tr:hover { background-color: #f8fafc; }
.osm-fixed-table tbody tr.selected { background-color: #e0f2fe !important; }

.cell-truncate {
  max-width: 220px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: inline-block;
  vertical-align: middle;
}

.badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 500;
}

.return-info-box {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  padding: 8px 12px;
  margin-bottom: 12px;
  font-size: 12px;
  color: #166534;
}

.perm-table {
  width: 100%;
  border-collapse: collapse;
}
.perm-table th {
  background: #f8f9fa;
  padding: 6px 10px;
  border-bottom: 1px solid #dee2e6;
}
.perm-table td {
  padding: 6px 10px;
  border-bottom: 1px solid #eee;
}

.osm-pagination-bar {
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

.form-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.form-label {
  font-weight: 600;
  display: block;
  margin-bottom: 3px;
  color: #495057;
}

.osm-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.osm-input:focus { border-color: #80bdff; }

.osm-select {
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

.btn-sm { font-size: 12.5px; height: 33px; }
.btn-mini { padding: 2px 8px; font-size: 12px; }
.mr-1 { margin-right: 4px; }
</style>