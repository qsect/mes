<template>
  <div class="ts-container">
    <!-- 顶部标题卡片 -->
    <div class="ts-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><HelpFilled /></el-icon>
          问题排查记录系统
        </span>
        <div style="display:flex; gap:8px;">
          <button v-if="isAdmin" type="button" class="btn-warning btn-sm" @click="openPermModal">
            <el-icon class="mr-1"><UserFilled /></el-icon> 权限管理
          </button>
          <button type="button" class="btn-primary btn-sm" @click="exportCsv">
            <el-icon class="mr-1"><Download /></el-icon> 导出 Excel
          </button>
        </div>
      </div>
    </div>

    <!-- 录入卡片 -->
    <div class="ts-card">
      <div class="ts-card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span><i class="fas fa-wrench"></i> 问题排查快速录入</span>
        <label class="lock-label">
          <input type="checkbox" v-model="isLocked" @change="handleLockToggle" />
          <span><strong>锁定说明与地点</strong> (连续扫码复用)</span>
        </label>
      </div>

      <div style="padding: 14px 16px;">
        <div class="ts-sn-row">
          <div class="ts-sn-input-group">
            <span class="ts-label req">扫入 SN:</span>
            <div style="position:relative; flex:1;">
              <input
                ref="snInputRef"
                v-model="snInput"
                type="text"
                class="ts-input"
                maxlength="22"
                style="width:100%; font-family:monospace;"
                placeholder="输入22位SN后回车..."
                autocomplete="off"
                @keyup.enter="queryBatch"
              />
              <span v-if="querying" class="spinner-tip">查询中...</span>
            </div>
            <button type="button" class="btn-primary btn-sm" :disabled="querying" style="white-space:nowrap;" @click="queryBatch">
              <el-icon class="mr-1"><Search /></el-icon> 查询
            </button>
          </div>

          <div v-if="deviceBoxVisible" class="ts-inline-info">
            <span><strong>工单:</strong> <span style="font-weight:bold; font-family:monospace;">{{ batchInfo.work_order || '-' }}</span></span>
            <span style="color:#93c5fd;">|</span>
            <span><strong>编码:</strong> <span style="font-family:monospace;">{{ batchInfo.task_code || '-' }}</span></span>
            <span style="color:#93c5fd;">|</span>
            <span style="overflow:hidden; text-overflow:ellipsis;"><strong>描述:</strong> {{ batchInfo.description || '-' }}</span>
          </div>
        </div>

        <div class="ts-input-row">
          <div style="width: 155px; flex-shrink: 0;">
            <select v-model="formLocation" class="ts-select" :class="{ 'ts-locked': isLocked }" :disabled="isLocked" style="width:100%;">
              <option value="">请选择排查地点 *</option>
              <option v-for="l in locationOptions" :key="l" :value="l">{{ l }}</option>
            </select>
          </div>

          <div style="flex: 1; min-width: 250px;">
            <input
              v-model="formIssueDesc"
              type="text"
              class="ts-input"
              :class="{ 'ts-locked': isLocked }"
              :readonly="isLocked"
              style="width:100%;"
              placeholder="排查问题说明（如：接线松动、参数偏离等）*"
            />
          </div>

          <div style="display:flex; gap:8px; flex-shrink: 0;">
            <button type="button" class="btn-pass-action" :disabled="submitting" @click="handlePass">
              <el-icon class="mr-1"><Check /></el-icon> 合格
            </button>
            <button type="button" class="btn-fail-action" :disabled="submitting" @click="handleFail">
              <el-icon class="mr-1"><Close /></el-icon> 不合格
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- 列表卡片 -->
    <div class="ts-card">
      <div class="ts-filter-card">
        <div style="display:flex; gap:8px; align-items:center;">
          <input
            v-model="searchKeyword"
            type="text"
            class="ts-input"
            style="flex:1;"
            placeholder="模糊查找 (工单、代码、描述、SN号、问题说明、地点、结论或排查人搜索后回车)..."
            @keyup.enter="loadTable(1)"
          />
          <button type="button" class="btn-primary btn-sm" @click="loadTable(1)">
            <el-icon class="mr-1"><Search /></el-icon> 搜索
          </button>
          <button type="button" class="btn-outline btn-sm" @click="resetFilter">重置</button>
        </div>
      </div>

      <!-- 批量操作栏 -->
      <div v-if="selectedIds.length > 0 && isAdmin" class="ts-batch-bar">
        <span style="color:#0369a1;">已勾选 <strong style="color:#409EFF;">{{ selectedIds.length }}</strong> 条记录</span>
        <div style="display:flex; gap:6px;">
          <button type="button" class="btn-danger btn-mini" @click="batchDelete">
            <el-icon class="mr-1"><Delete /></el-icon> 批量删除
          </button>
          <button type="button" class="btn-outline btn-mini" @click="selectedIds = []">取消选择</button>
        </div>
      </div>

      <!-- 表格主体 -->
      <div class="ts-table-wrapper" v-loading="tableLoading">
        <table class="ts-fixed-table">
          <thead>
            <tr>
              <th v-if="isAdmin" style="width: 36px; text-align: center;">
                <input type="checkbox" v-model="allSelected" @change="toggleSelectAll" />
              </th>
              <th style="width: 100px;">工单号</th>
              <th style="width: 90px;">代码</th>
              <th style="width: 15%; text-align: left;">描述</th>
              <th style="width: 160px;">SN号</th>
              <th style="width: 95px; text-align:center;">排查地点</th>
              <th style="width: 18%; text-align: left;">排查问题说明</th>
              <th style="width: 80px; text-align:center;">结论</th>
              <th style="width: 15%; text-align: left;">问题现象</th>
              <th style="width: 80px;">排查人</th>
              <th style="width: 110px;">排查时间</th>
              <th v-if="isAdmin" style="width: 70px; text-align: center;">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in recordList" :key="r.id" :class="{ selected: selectedIds.includes(r.id) }">
              <td v-if="isAdmin" style="text-align:center;">
                <input type="checkbox" v-model="selectedIds" :value="r.id" />
              </td>
              <td><strong style="font-family:monospace; color:#2c3e50;">{{ r.work_order || '-' }}</strong></td>
              <td><span style="font-family:monospace;">{{ r.task_code || '-' }}</span></td>
              <td style="text-align:left;" :title="r.description"><span class="cell-truncate">{{ r.description || '-' }}</span></td>
              <td><span style="font-family:monospace; font-weight:600; color:#2c3e50;">{{ r.sn }}</span></td>
              <td style="text-align:center;"><span class="badge-tag badge-tag-loc">{{ r.parsed_location || '-' }}</span></td>
              <td style="text-align:left;" :title="r.parsed_base_issue"><span class="cell-truncate">{{ r.parsed_base_issue || '-' }}</span></td>
              <td style="text-align:center;">
                <span class="badge-tag" :class="r.parsed_conclusion === '合格' ? 'badge-tag-yes' : 'badge-tag-no'">
                  {{ r.parsed_conclusion }}
                </span>
              </td>
              <td style="text-align:left;" :title="r.parsed_defect">
                <span class="cell-truncate" style="color:#dc3545;">{{ r.parsed_defect || '-' }}</span>
              </td>
              <td>{{ r.display_operator }}</td>
              <td style="font-size:11.5px; color:#64748b;">{{ r.created_at ? r.created_at.substring(5, 16) : '-' }}</td>
              <td v-if="isAdmin" style="text-align:center;">
                <button type="button" class="btn-danger btn-mini" @click="deleteSingle(r.id)">删除</button>
              </td>
            </tr>
            <tr v-if="recordList.length === 0">
              <td :colspan="isAdmin ? 12 : 11" style="text-align:center; padding:35px; color:#888;">
                暂无排查记录
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 分页栏 -->
      <div class="ts-pagination-bar">
        <div style="color:#64748b;">共 <strong>{{ totalRecords }}</strong> 条排查记录，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页</div>
        <el-pagination v-model:current-page="curPage" :page-size="30" :total="totalRecords" layout="prev, pager, next" @current-change="loadTable" />
      </div>
    </div>

    <!-- 弹窗 1: 填写不合格具体现象 -->
    <el-dialog v-model="defectModalVisible" title="填写不合格具体现象" width="480px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;">
        <div style="margin-bottom:12px;">
          <label class="ts-label req">具体缺陷/故障现象</label>
          <textarea
            v-model="defectText"
            class="ts-input"
            rows="3"
            style="width:100%; height:auto; margin-top:4px;"
            placeholder="请详细描述该产品的不合格现象..."
          ></textarea>
        </div>
      </div>
      <template #footer>
        <el-button @click="defectModalVisible = false">取消</el-button>
        <el-button type="danger" :loading="submitting" @click="submitDefect">确认提交不合格</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 2: 权限管理 -->
    <el-dialog v-if="isAdmin" v-model="permModalVisible" title="问题排查模块权限管理" width="650px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;" v-loading="permLoading">
        <div style="max-height:380px; overflow-y:auto; border:1px solid #dee2e6;">
          <table class="ts-fixed-table" style="min-width:100%;">
            <thead>
              <tr>
                <th>用户名</th>
                <th>姓名</th>
                <th style="text-align:center;">查看记录权限</th>
                <th style="text-align:center;">录入权限</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in permUserList" :key="u.id">
                <td><strong>{{ u.username }}</strong></td>
                <td>{{ u.full_name || '-' }}</td>
                <td style="text-align:center;">
                  <input type="checkbox" v-model="u.can_view_records" :true-value="1" :false-value="0" @change="saveUserPerm(u)" />
                </td>
                <td style="text-align:center;">
                  <input type="checkbox" v-model="u.can_input" :true-value="1" :false-value="0" @change="saveUserPerm(u)" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <template #footer>
        <el-button @click="permModalVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, nextTick } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  HelpFilled, Search, Check, Close, Download, UserFilled, Delete
} from '@element-plus/icons-vue';
import request from '@/api/request';

const isAdmin = ref(false);

const locationOptions = [
  '装置装配', '装置调试', '装置检验', '装置包装', 
  '维修区', '仓库', '板卡车间', '系统车间', '客户现场', '其他'
];

// 录入相关
const snInputRef = ref(null);
const snInput = ref('');
const querying = ref(false);
const submitting = ref(false);
const isLocked = ref(false);
const lockedIssue = ref('');
const lockedLocation = ref('');

const formLocation = ref('');
const formIssueDesc = ref('');

const deviceBoxVisible = ref(false);
const batchInfo = reactive({
  work_order: '',
  task_code: '',
  description: ''
});

// 不合格弹窗
const defectModalVisible = ref(false);
const defectText = ref('');

// 列表相关
const tableLoading = ref(false);
const recordList = ref([]);
const totalRecords = ref(0);
const curPage = ref(1);
const totalPages = ref(1);
const searchKeyword = ref('');
const selectedIds = ref([]);
const allSelected = ref(false);

// 权限弹窗
const permModalVisible = ref(false);
const permLoading = ref(false);
const permUserList = ref([]);

const handleLockToggle = () => {
  if (isLocked.value) {
    if (!formIssueDesc.value.trim() || !formLocation.value) {
      ElMessage.warning('请先填写排查问题说明并选择排查地点后再锁定！');
      isLocked.value = false;
      return;
    }
    lockedIssue.value = formIssueDesc.value.trim();
    lockedLocation.value = formLocation.value;
  } else {
    lockedIssue.value = '';
    lockedLocation.value = '';
  }
};

const queryBatch = async () => {
  const snVal = snInput.value.trim();
  if (!/^\d{22}$/.test(snVal)) {
    ElMessage.warning('SN 号必须为 22 位纯数字！');
    snInputRef.value?.focus();
    return;
  }

  querying.value = true;
  try {
    const res = await request.post('/troubleshoot/get_batch_info', { sn: snVal });
    if (res.data && res.data.found) {
      batchInfo.work_order = res.data.work_order || '';
      batchInfo.task_code = res.data.task_code || '';
      batchInfo.description = res.data.description || '';
      deviceBoxVisible.value = true;
      ElMessage.success('匹配到工单批次信息！');
    } else {
      batchInfo.work_order = '';
      batchInfo.task_code = '';
      batchInfo.description = '未检索到离散工单 (允许继续提交)';
      deviceBoxVisible.value = true;
    }
  } finally {
    querying.value = false;
  }
};

const submitConclusion = async (conclusion, defect = '') => {
  const snVal = snInput.value.trim();
  const issueVal = isLocked.value ? lockedIssue.value : formIssueDesc.value.trim();
  const locVal = isLocked.value ? lockedLocation.value : formLocation.value;

  if (!snVal || !/^\d{22}$/.test(snVal)) {
    ElMessage.warning('请先扫入或输入有效的 22 位 SN 号！');
    snInputRef.value?.focus();
    return;
  }
  if (!locVal) {
    return ElMessage.warning('请选择排查地点！');
  }
  if (!issueVal) {
    return ElMessage.warning('请输入排查问题说明！');
  }

  submitting.value = true;
  try {
    const res = await request.post('/troubleshoot/add', {
      sn: snVal,
      issue_description: issueVal,
      location: locVal,
      conclusion: conclusion,
      defect_phenomenon: defect,
      work_order: batchInfo.work_order,
      task_code: batchInfo.task_code,
      description: batchInfo.description
    });
    ElMessage.success(res.message || `【${conclusion}】排查记录已保存！`);
    snInput.value = '';
    deviceBoxVisible.value = false;
    batchInfo.work_order = '';
    batchInfo.task_code = '';
    batchInfo.description = '';

    if (!isLocked.value) {
      formIssueDesc.value = '';
      formLocation.value = '';
    }

    loadTable(1);
    nextTick(() => snInputRef.value?.focus());
  } finally {
    submitting.value = false;
  }
};

const handlePass = () => {
  submitConclusion('合格');
};

const handleFail = () => {
  defectText.value = '';
  defectModalVisible.value = true;
};

const submitDefect = () => {
  if (!defectText.value.trim()) {
    return ElMessage.warning('请填写不合格现象描述！');
  }
  defectModalVisible.value = false;
  submitConclusion('不合格', defectText.value.trim());
};

const loadTable = async (p = 1) => {
  curPage.value = p;
  tableLoading.value = true;
  try {
    const res = await request.get('/troubleshoot/list', {
      params: {
        page: p,
        keyword: searchKeyword.value.trim()
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
    tableLoading.value = false;
  }
};

const resetFilter = () => {
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

const deleteSingle = (id) => {
  ElMessageBox.confirm('确定要删除该条问题排查记录吗？', '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/troubleshoot/delete', { ids: [id] });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const batchDelete = () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请勾选记录！');
  ElMessageBox.confirm(`确定要永久删除选中的 ${selectedIds.value.length} 条排查记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/troubleshoot/delete', { ids: selectedIds.value });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const exportCsv = () => {
  const kw = searchKeyword.value.trim();
  window.open(`/api/troubleshoot/export?keyword=${encodeURIComponent(kw)}`, '_blank');
};

const openPermModal = async () => {
  permModalVisible.value = true;
  permLoading.value = true;
  try {
    const res = await request.get('/troubleshoot/get_permissions');
    if (res.data) {
      permUserList.value = res.data.users || [];
    }
  } finally {
    permLoading.value = false;
  }
};

const saveUserPerm = async (u) => {
  try {
    await request.post('/troubleshoot/update_permission', {
      user_id: u.id,
      can_view_records: u.can_view_records,
      can_input: u.can_input
    });
    ElMessage.success('权限更新成功');
  } catch (e) {}
};

onMounted(() => {
  loadTable(1);
});
</script>

<style scoped>
.ts-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.ts-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.ts-card-header {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  font-size: 13.5px;
  font-weight: 600;
  color: #2c3e50;
}

.lock-label {
  cursor: pointer;
  font-size: 12.5px;
  display: flex;
  align-items: center;
  gap: 6px;
  color: #007bff;
  user-select: none;
  margin-bottom: 0;
}

.ts-sn-row {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 10px;
}
.ts-sn-input-group {
  display: flex;
  gap: 6px;
  align-items: center;
  width: 370px;
  min-width: 310px;
}
.ts-inline-info {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 10px;
  background: #f0f7ff;
  border: 1px dashed #93c5fd;
  border-radius: 4px;
  padding: 0 12px;
  height: 33px;
  line-height: 31px;
  font-size: 12.5px;
  color: #0284c7;
  min-width: 280px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.ts-input-row {
  display: flex;
  gap: 10px;
  align-items: center;
  width: 100%;
  flex-wrap: wrap;
}

.ts-label {
  font-size: 12.5px;
  font-weight: 600;
  color: #495057;
  white-space: nowrap;
  flex-shrink: 0;
}
.ts-label.req::after {
  content: "*";
  color: #dc3545;
  margin-left: 2px;
}

.spinner-tip {
  position: absolute;
  right: 8px;
  top: 8px;
  font-size: 11px;
  color: #409EFF;
}

.ts-locked {
  background: #e8f4fd !important;
  border-color: #28a745 !important;
}

.btn-pass-action {
  height: 33px;
  padding: 0 16px;
  background: #28a745;
  color: white;
  border: none;
  border-radius: 4px;
  font-weight: 600;
  font-size: 12.5px;
  cursor: pointer;
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
}
.btn-pass-action:hover { background: #218838; }

.btn-fail-action {
  height: 33px;
  padding: 0 16px;
  background: #dc3545;
  color: white;
  border: none;
  border-radius: 4px;
  font-weight: 600;
  font-size: 12.5px;
  cursor: pointer;
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
}
.btn-fail-action:hover { background: #c82333; }

.ts-filter-card {
  padding: 8px 12px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}
.ts-batch-bar {
  background: #e0f2fe;
  padding: 6px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #bae6fd;
  font-size: 12.5px;
}

.ts-table-wrapper {
  width: 100%;
  overflow-x: auto;
  max-height: calc(100vh - 380px);
  min-height: 280px;
  border-top: 1px solid #dee2e6;
}
.ts-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed !important;
  font-size: 12.5px;
  text-align: center;
  color: #333;
}
.ts-fixed-table thead th {
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
.ts-fixed-table tbody td {
  padding: 6px 6px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ts-fixed-table tbody tr:hover { background: #f8fafc; }
.ts-fixed-table tbody tr.selected { background: #e0f2fe !important; }

.cell-truncate {
  display: block;
  width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: left;
}

.badge-tag {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 10px;
  font-size: 11.5px;
  font-weight: 500;
}
.badge-tag-yes { background: #d4edda; color: #155724; }
.badge-tag-no { background: #f8d7da; color: #721c24; }
.badge-tag-loc { background: #e8f4fd; color: #007bff; }

.ts-pagination-bar {
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

.ts-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.ts-input:focus { border-color: #80bdff; }

.ts-select {
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

.btn-warning {
  background: #ffc107;
  color: #212529;
  border: 1px solid #ffc107;
  border-radius: 4px;
  padding: 5px 12px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}
.btn-warning:hover { background: #e0a800; }

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
.btn-mini { padding: 2px 7px; font-size: 11.5px; }
.mr-1 { margin-right: 4px; }
</style>