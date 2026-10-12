<template>
  <div class="fp-container">
    <!-- 顶部标题卡片 -->
    <div class="fp-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Crop /></el-icon>
          首件检管理系统
        </span>
      </div>
    </div>

    <!-- 顶部 Tab 切换 -->
    <div class="fp-tabs">
      <button
        type="button"
        class="fp-tab-btn"
        :class="{ active: activeTab === 'record' }"
        @click="activeTab = 'record'"
      >
        <el-icon class="mr-1"><Crop /></el-icon> 首件检录入与记录
      </button>
      <button
        v-if="canManageCriteria"
        type="button"
        class="fp-tab-btn"
        :class="{ active: activeTab === 'criteria' }"
        @click="switchCriteriaTab"
      >
        <el-icon class="mr-1"><Setting /></el-icon> 判据标准管理
      </button>
    </div>

    <!-- ================= 视图 1: 录入与记录 ================= -->
    <div v-show="activeTab === 'record'">
      <!-- 快速扫码录入卡片 -->
      <div class="fp-card">
        <div class="fp-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#409EFF; vertical-align:middle; margin-right:4px;"><Plus /></el-icon>
            首件检快速扫码录入
          </span>
        </div>
        <div style="padding: 12px 14px;">
          <div class="fp-wo-row">
            <div class="fp-wo-input-group">
              <span class="fp-label req">工单号:</span>
              <div style="position:relative; flex:1;">
                <input
                  v-model="workOrderInput"
                  type="text"
                  class="fp-input"
                  placeholder="输入工单号回车..."
                  autocomplete="off"
                  @keyup.enter="queryWorkOrder"
                />
                <span v-if="searchingWo" style="position:absolute; right:8px; top:6px; color:#409EFF; font-size:12px;">查询中...</span>
              </div>
              <button type="button" class="btn-primary btn-sm" @click="queryWorkOrder">查询</button>
            </div>

            <div v-if="currentWorkOrder" class="fp-inline-info">
              <span><strong>编码:</strong> <span style="font-family:monospace;">{{ currentWorkOrder.task_code || '-' }}</span></span>
              <span style="color:#93c5fd;">|</span>
              <span class="cell-truncate"><strong>描述:</strong> {{ currentWorkOrder.description || '-' }}</span>
              <span style="color:#93c5fd;">|</span>
              <span><strong>批次:</strong> <strong style="color:#67C23A; font-family:monospace;">{{ currentWorkOrder.batch_no || '-' }}</strong></span>
              <span style="color:#93c5fd;">|</span>
              <span><strong>计划数:</strong> <strong style="color:#409EFF;">{{ currentWorkOrder.quantity || '-' }}</strong></span>
            </div>
          </div>

          <div class="fp-scan-row">
            <div class="fp-scan-col">
              <input
                ref="barcodeInputRef"
                v-model="barcodeInput"
                type="text"
                class="fp-input"
                style="width:100%;"
                placeholder="扫描或输入条形码..."
              />
            </div>

            <div class="fp-scan-col">
              <input
                v-model="qrcodeInput"
                type="text"
                class="fp-input"
                style="width:100%;"
                placeholder="扫描或输入二维码..."
              />
            </div>

            <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
              <span class="fp-label">校验判据:</span>
              <el-switch v-model="needCheck" active-text="是" inactive-text="否" inline-prompt />
            </div>

            <div style="flex-shrink:0;">
              <button type="button" class="btn-success btn-sm" :disabled="savingRecord" @click="saveRecord">
                <el-icon class="mr-1"><Check /></el-icon> 保存记录
              </button>
            </div>
          </div>

          <div v-if="saveResultTip" style="margin-top:10px; font-size:12.5px; padding:6px 12px; border-radius:4px;" :class="saveResultClass">
            {{ saveResultTip }}
          </div>
        </div>
      </div>

      <!-- 数据记录列表 -->
      <div class="fp-card">
        <div class="fp-filter-card">
          <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <input v-model="filterWo" type="text" class="fp-input" style="width:140px;" placeholder="工单号..." @keyup.enter="loadTable(1)" />
            <input v-model="filterBarcode" type="text" class="fp-input" style="width:180px;" placeholder="条码/二维码..." @keyup.enter="loadTable(1)" />
            <select v-model="filterResult" class="fp-select" style="width:115px;" @change="loadTable(1)">
              <option value="">全部结果</option>
              <option value="合格">合格</option>
              <option value="不合格">不合格</option>
              <option value="无">无 (未校验)</option>
            </select>
            <button type="button" class="btn-primary btn-sm" @click="loadTable(1)">
              <el-icon class="mr-1"><Search /></el-icon> 搜索
            </button>
            <button type="button" class="btn-outline btn-sm" @click="resetFilter">
              <el-icon class="mr-1"><Refresh /></el-icon> 重置
            </button>
            <button type="button" class="btn-primary btn-sm" style="margin-left:auto;" @click="exportCsv">
              <el-icon class="mr-1"><Download /></el-icon> 导出 Excel
            </button>
          </div>
        </div>

        <!-- 批量操作栏 -->
        <div v-if="selectedIds.length > 0 && canManage" class="fp-batch-bar">
          <span style="color:#0369a1;">已勾选 <strong style="color:#409EFF;">{{ selectedIds.length }}</strong> 条记录</span>
          <div style="display:flex; gap:6px;">
            <button type="button" class="btn-danger btn-mini" @click="batchDelete">
              <el-icon class="mr-1"><Delete /></el-icon> 批量删除
            </button>
            <button type="button" class="btn-outline btn-mini" @click="selectedIds = []">取消选择</button>
          </div>
        </div>

        <div class="fp-table-wrapper" v-loading="loading">
          <table class="fp-fixed-table" style="min-width: 1100px;">
            <thead>
              <tr>
                <th v-if="canManage" style="width: 36px; text-align: center;"><input type="checkbox" v-model="allSelected" @change="toggleSelectAll" /></th>
                <th style="width: 95px;">工单号</th>
                <th style="width: 90px;">代码</th>
                <th style="width: 20%; text-align: left;">描述</th>
                <th style="width: 110px;">批次</th>
                <th style="width: 15%; text-align: left;">条形码</th>
                <th style="width: 15%; text-align: left;">二维码</th>
                <th style="width: 60px; text-align:center;">校验</th>
                <th style="width: 75px; text-align:center;">结果</th>
                <th style="width: 80px;">创建人</th>
                <th style="width: 110px;">创建时间</th>
                <th v-if="canManage" style="width: 70px; text-align: center;">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in recordList" :key="r.id" :class="{ selected: selectedIds.includes(r.id) }">
                <td v-if="canManage" style="text-align:center;"><input type="checkbox" v-model="selectedIds" :value="r.id" /></td>
                <td><strong style="font-family:monospace; color:#2c3e50;">{{ r.work_order }}</strong></td>
                <td><span style="font-family:monospace;">{{ r.task_code || '-' }}</span></td>
                <td style="text-align:left;" :title="r.description"><span class="cell-truncate">{{ r.description || '-' }}</span></td>
                <td><span style="font-family:monospace; color:#67C23A;">{{ r.batch_no || '-' }}</span></td>
                <td style="text-align:left;" :title="r.barcode"><span class="cell-truncate">{{ r.barcode || '-' }}</span></td>
                <td style="text-align:left;" :title="r.qrcode"><span class="cell-truncate">{{ r.qrcode || '-' }}</span></td>
                <td style="text-align:center;">{{ r.need_check ? '是' : '否' }}</td>
                <td style="text-align:center;">
                  <span class="badge" :style="getResultBadgeStyle(r.check_result)">{{ r.check_result || '无' }}</span>
                </td>
                <td>{{ r.created_by_name }}</td>
                <td><span style="font-size:11.5px; color:#64748b;">{{ String(r.created_at || '').substring(5, 16) }}</span></td>
                <td v-if="canManage" style="text-align:center; white-space:nowrap;">
                  <button type="button" class="btn-danger btn-mini" @click="deleteSingle(r.id)">删除</button>
                </td>
              </tr>
              <tr v-if="recordList.length === 0">
                <td :colspan="canManage ? 12 : 11" style="padding:35px; color:#888;">暂无首件检记录</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="fp-pagination-bar">
          <div style="color:#64748b;">共 <strong>{{ totalRecords }}</strong> 条首件记录，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页</div>
          <el-pagination v-model:current-page="curPage" :page-size="30" :total="totalRecords" layout="prev, pager, next" @current-change="loadTable" />
        </div>
      </div>
    </div>

    <!-- ================= 视图 2: 判据标准管理 ================= -->
    <div v-show="activeTab === 'criteria' && canManageCriteria">
      <div class="fp-card">
        <div class="fp-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#409EFF; vertical-align:middle; margin-right:4px;"><Setting /></el-icon>
            工单首检判据设置
          </span>
        </div>
        <div style="padding: 12px 14px;">
          <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <div style="width:180px;">
              <input v-model="critWoInput" type="text" class="fp-input" style="width:100%;" placeholder="工单号 (如 172072)..." />
            </div>
            <div style="flex:1; min-width:200px;">
              <input v-model="critBarcodeExpected" type="text" class="fp-input" style="width:100%;" placeholder="期望比对的条形码内容..." />
            </div>
            <div style="flex:1; min-width:200px;">
              <input v-model="critQrcodeExpected" type="text" class="fp-input" style="width:100%;" placeholder="期望比对的二维码内容..." />
            </div>
            <div style="display:flex; gap:6px;">
              <button type="button" class="btn-primary btn-sm" @click="queryCriteria">查询判据</button>
              <button type="button" class="btn-success btn-sm" @click="saveCriteria">保存判据</button>
            </div>
          </div>
        </div>
      </div>

      <div class="fp-card">
        <div class="fp-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#67C23A; vertical-align:middle; margin-right:4px;"><List /></el-icon>
            已配置的判据标准库
          </span>
        </div>
        <div class="fp-table-wrapper" v-loading="criteriaLoading">
          <table class="fp-fixed-table">
            <thead>
              <tr>
                <th style="width:120px;">工单号</th>
                <th>条形码期望值</th>
                <th>二维码期望值</th>
                <th style="width:140px;">更新时间</th>
                <th style="width:110px; text-align:center;">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in criteriaList" :key="item.id">
                <td><strong style="font-family:monospace; color:#2c3e50;">{{ item.work_order }}</strong></td>
                <td style="text-align:left;"><span class="cell-truncate" style="font-family:monospace;">{{ item.barcode_expected || '-' }}</span></td>
                <td style="text-align:left;"><span class="cell-truncate" style="font-family:monospace;">{{ item.qrcode_expected || '-' }}</span></td>
                <td><span style="font-size:11.5px; color:#64748b;">{{ item.updated_at }}</span></td>
                <td style="text-align:center; white-space:nowrap;">
                  <button type="button" class="btn-primary btn-mini" @click="editCriteriaItem(item)">编辑</button>
                  <button v-if="canManageCriteria" type="button" class="btn-danger btn-mini" style="margin-left:4px;" @click="deleteCriteriaItem(item.id)">删除</button>
                </td>
              </tr>
              <tr v-if="criteriaList.length === 0">
                <td colspan="5" style="padding:30px; color:#888;">暂无配置的判据标准</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, nextTick } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Crop, Plus, Search, Refresh, Setting, Delete, Check, Download, List
} from '@element-plus/icons-vue';
import request from '@/api/request';

const activeTab = ref('record');
const isAdmin = ref(false);
const canManageCriteria = ref(false);
// ★ 按钮级权限：删除记录（破坏性操作）需维护权限，无权限则隐藏勾选框与删除按钮
const canManage = ref(false);

// 1. 录入区
const workOrderInput = ref('');
const barcodeInput = ref('');
const qrcodeInput = ref('');
const needCheck = ref(false);
const currentWorkOrder = ref(null);
const searchingWo = ref(false);
const savingRecord = ref(false);
const barcodeInputRef = ref(null);
const saveResultTip = ref('');
const saveResultClass = ref('');

const queryWorkOrder = async () => {
  const wo = workOrderInput.value.trim();
  if (!wo) return ElMessage.warning('请输入工单号');

  searchingWo.value = true;
  try {
    const res = await request.post('/first_piece/get_work_order', { work_order: wo });
    if (res.data) {
      currentWorkOrder.value = res.data;
      nextTick(() => barcodeInputRef.value?.focus());
    }
  } finally {
    searchingWo.value = false;
  }
};

const saveRecord = async () => {
  if (!currentWorkOrder.value) {
    return ElMessage.warning('请先输入工单号并查询确认工单信息！');
  }
  const b = barcodeInput.value.trim();
  const q = qrcodeInput.value.trim();
  if (!b && !q) {
    return ElMessage.warning('请至少扫描或输入一个条形码或二维码！');
  }

  savingRecord.value = true;
  try {
    const res = await request.post('/first_piece/save_record', {
      work_order: currentWorkOrder.value.work_order,
      task_code: currentWorkOrder.value.task_code,
      description: currentWorkOrder.value.description,
      quantity: currentWorkOrder.value.quantity,
      batch_no: currentWorkOrder.value.batch_no,
      barcode: b,
      qrcode: q,
      need_check: needCheck.value ? 1 : 0
    });

    const result = res.data?.check_result || '无';
    saveResultTip.value = `✅ 记录保存成功！ 判定结果：【${result}】`;
    saveResultClass.value = result === '合格' ? 'tip-success' : (result === '不合格' ? 'tip-danger' : 'tip-info');

    barcodeInput.value = '';
    qrcodeInput.value = '';
    loadTable(1);
  } finally {
    savingRecord.value = false;
  }
};

// 2. 列表区
const filterWo = ref('');
const filterBarcode = ref('');
const filterResult = ref('');
const recordList = ref([]);
const totalRecords = ref(0);
const curPage = ref(1);
const totalPages = ref(1);
const loading = ref(false);
const selectedIds = ref([]);
const allSelected = ref(false);

const getResultBadgeStyle = (res) => {
  if (res === '合格') return { background: '#d4edda', color: '#155724', padding: '2px 6px', borderRadius: '4px', fontWeight: 'bold' };
  if (res === '不合格') return { background: '#f8d7da', color: '#721c24', padding: '2px 6px', borderRadius: '4px', fontWeight: 'bold' };
  return { background: '#e2e3e5', color: '#383d41', padding: '2px 6px', borderRadius: '4px' };
};

const loadTable = async (p = 1) => {
  curPage.value = p;
  loading.value = true;
  try {
    const res = await request.get('/first_piece/list', {
      params: {
        page: p,
        work_order: filterWo.value.trim(),
        barcode: filterBarcode.value.trim(),
        check_result: filterResult.value
      }
    });
    if (res.data) {
      recordList.value = res.data.records || [];
      totalRecords.value = res.data.total_records || 0;
      totalPages.value = res.data.total_pages || 1;
      isAdmin.value = !!res.data.is_admin;
      canManageCriteria.value = !!res.data.can_manage_criteria;
      selectedIds.value = [];
      allSelected.value = false;
    }
  } finally {
    loading.value = false;
  }
};

const resetFilter = () => {
  filterWo.value = '';
  filterBarcode.value = '';
  filterResult.value = '';
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
  ElMessageBox.confirm('确定要删除该条首件检记录吗？', '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/first_piece/delete', { ids: [id] });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const batchDelete = () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请勾选记录！');
  ElMessageBox.confirm(`确定要永久删除选中的 ${selectedIds.value.length} 条首件检记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/first_piece/delete', { ids: selectedIds.value });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const exportCsv = () => {
  const ids = selectedIds.value.join(',');
  window.open(`/api/first_piece/export?selected_ids=${encodeURIComponent(ids)}`, '_blank');
};

// 3. 判据标准
const critWoInput = ref('');
const critBarcodeExpected = ref('');
const critQrcodeExpected = ref('');
const criteriaList = ref([]);
const criteriaLoading = ref(false);

const switchCriteriaTab = () => {
  activeTab.value = 'criteria';
  loadCriteriaList();
};

const loadCriteriaList = async () => {
  criteriaLoading.value = true;
  try {
    const res = await request.get('/first_piece/list_criteria');
    criteriaList.value = res.data?.list || [];
  } finally {
    criteriaLoading.value = false;
  }
};

const queryCriteria = async () => {
  const wo = critWoInput.value.trim();
  if (!wo) return ElMessage.warning('请输入工单号');
  const res = await request.get('/first_piece/get_criteria', { params: { work_order: wo } });
  if (res.data?.criteria) {
    critBarcodeExpected.value = res.data.criteria.barcode_expected || '';
    critQrcodeExpected.value = res.data.criteria.qrcode_expected || '';
  } else {
    critBarcodeExpected.value = '';
    critQrcodeExpected.value = '';
    ElMessage.info('该工单暂未配置判据，可直接输入后保存。');
  }
};

const saveCriteria = async () => {
  const wo = critWoInput.value.trim();
  if (!wo) return ElMessage.warning('工单号不能为空！');
  const res = await request.post('/first_piece/save_criteria', {
    work_order: wo,
    barcode_expected: critBarcodeExpected.value.trim(),
    qrcode_expected: critQrcodeExpected.value.trim()
  });
  ElMessage.success(res.message || '保存成功');
  loadCriteriaList();
};

const editCriteriaItem = (item) => {
  critWoInput.value = item.work_order;
  critBarcodeExpected.value = item.barcode_expected || '';
  critQrcodeExpected.value = item.qrcode_expected || '';
};

const deleteCriteriaItem = (id) => {
  ElMessageBox.confirm('确定要删除该工单的判据标准吗？', '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/first_piece/delete_criteria', { id });
    ElMessage.success(res.message || '删除成功');
    loadCriteriaList();
  });
};

onMounted(async () => {
  const initRes = await request.get('/first_piece/init');
  if (initRes.data) {
    isAdmin.value = !!initRes.data.is_admin;
    canManageCriteria.value = !!initRes.data.can_manage_criteria;
    canManage.value = !!initRes.data.can_manage;
  }
  loadTable(1);
});
</script>

<style scoped>
.fp-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.fp-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.fp-card-header {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.fp-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  border-bottom: 2px solid #dee2e6;
}
.fp-tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 9px 18px;
  border: none;
  background: transparent;
  font-size: 15px;
  font-weight: 600;
  color: #64748b;
  cursor: pointer;
  border-bottom: 3px solid transparent;
  transition: all 0.2s;
  white-space: nowrap;
}
.fp-tab-btn.active {
  color: #007bff;
  border-bottom-color: #007bff;
}

.fp-wo-row {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 10px;
}
.fp-wo-input-group {
  display: flex;
  gap: 6px;
  align-items: center;
  width: 320px;
  min-width: 270px;
}
.fp-inline-info {
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
.cell-truncate {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: inline-block;
  vertical-align: middle;
  width: 100%;
}

.fp-scan-row {
  display: flex;
  gap: 10px;
  align-items: center;
  width: 100%;
  flex-wrap: wrap;
}
.fp-scan-col {
  flex: 1;
  min-width: 220px;
}
.fp-label {
  font-size: 12.5px;
  font-weight: 600;
  color: #495057;
  white-space: nowrap;
  flex-shrink: 0;
}
.fp-label.req::after {
  content: "*";
  color: #dc3545;
  margin-left: 2px;
}

.fp-filter-card {
  padding: 8px 12px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}
.fp-batch-bar {
  background: #e0f2fe;
  padding: 6px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #bae6fd;
  font-size: 12.5px;
}

.fp-table-wrapper {
  width: 100%;
  overflow-x: auto;
  border-top: 1px solid #dee2e6;
}
.fp-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed !important;
  font-size: 12.5px;
  text-align: center;
  color: #333;
}
.fp-fixed-table thead th {
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
.fp-fixed-table tbody td {
  padding: 6px 6px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.fp-fixed-table tbody tr:hover { background-color: #f8fafc; }
.fp-fixed-table tbody tr.selected { background-color: #e0f2fe !important; }

.fp-pagination-bar {
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

.tip-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.tip-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.tip-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }

.fp-input {
  height: 32px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.fp-input:focus { border-color: #80bdff; }

.fp-select {
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