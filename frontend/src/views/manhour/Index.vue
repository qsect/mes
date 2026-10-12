<template>
  <div class="mh-container">
    <!-- 顶部标题卡片 -->
    <div class="mh-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Timer /></el-icon>
          工时汇总系统
        </span>
      </div>
    </div>

    <!-- 顶部工时分类选项卡 -->
    <div class="mh-tabs" v-loading="catLoading">
      <button
        v-for="tab in categoryList"
        :key="tab.slug"
        type="button"
        class="mh-tab-btn"
        :class="{ active: tab.slug === currentCatSlug }"
        @click="switchCategory(tab.slug)"
      >
        <el-icon class="mr-1"><component :is="getCategoryIcon(tab.name)" /></el-icon>
        {{ tab.name }}
      </button>
    </div>

    <!-- 检索与工具栏卡片 -->
    <div class="mh-card" style="margin-bottom: 10px;">
      <div style="padding: 8px 12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
        <div style="display:flex; gap:6px; align-items:center;">
          <button v-if="canEdit" type="button" class="btn-success btn-sm" @click="toggleAddPanel">
            <el-icon class="mr-1"><Plus /></el-icon> 添加记录
          </button>
          <div style="font-size:12.5px; color:#64748b; margin-left:6px;">
            <strong>当前分类：</strong> {{ currentCategory?.name || '-' }}
          </div>
        </div>

        <div style="display:flex; gap:6px; align-items:center;">
          <input
            v-model="searchKeyword"
            type="text"
            class="mh-input"
            style="width: 210px;"
            placeholder="输入代码或描述搜索..."
            @keyup.enter="loadTable(1)"
          />
          <button type="button" class="btn-primary btn-sm" @click="loadTable(1)">
            <el-icon class="mr-1"><Search /></el-icon> 搜索
          </button>
          <button v-if="searchKeyword" type="button" class="btn-outline btn-sm" @click="resetSearch">
            <el-icon class="mr-1"><Refresh /></el-icon> 重置
          </button>
        </div>
      </div>
    </div>

    <!-- 汇总数据指示栏 -->
    <div class="mh-summary-bar">
      <div><strong>记录总计：</strong> 共 {{ totalRecords }} 款产品 (当前页 {{ recordList.length }} 条)</div>
      <div><strong>汇总总工时：</strong> <span style="color:#409EFF; font-weight:bold; font-size:14px;">{{ Number(categoryTotalHours || 0).toFixed(2) }} 小时</span></div>
    </div>

    <!-- 批量删除栏 -->
    <div v-if="selectedIds.length > 0 && isAdmin" class="mh-batch-bar">
      <span style="color:#0369a1;">已选择 <strong style="color:#409EFF;">{{ selectedIds.length }}</strong> 条记录</span>
      <div style="display:flex; gap:6px;">
        <button type="button" class="btn-danger btn-mini" @click="batchDelete">
          <el-icon class="mr-1"><Delete /></el-icon> 批量删除
        </button>
        <button type="button" class="btn-outline btn-mini" @click="selectedIds = []">取消选择</button>
      </div>
    </div>

    <!-- 快捷添加表单面板 -->
    <div v-if="showAddPanel && canEdit" class="mh-card add-panel">
      <div class="add-panel-header">
        <span>➕ 新增【{{ currentCategory?.name }}】工时记录</span>
      </div>
      <div style="padding:12px;">
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:8px;">
          <div style="flex:1; min-width:200px;">
            <label style="font-size:12px; font-weight:600; display:block; margin-bottom:3px; color:#495057;">产品代码 *</label>
            <div style="display:flex; gap:4px; position:relative;">
              <input
                ref="addCodeInputRef"
                v-model="addForm.code"
                type="text"
                class="mh-input"
                style="flex:1; font-family:monospace;"
                placeholder="输入代码按回车查工艺..."
                @blur="fetchCodeMeta"
                @keyup.enter="fetchCodeMeta"
              />
              <button type="button" class="btn-outline btn-mini" :disabled="fetchingMeta" @click="fetchCodeMeta">
                {{ fetchingMeta ? '查询中...' : '查工艺' }}
              </button>
            </div>
            <div style="font-size:11px; margin-top:2px;" :style="metaTipStyle">{{ metaTipText }}</div>
          </div>
          <div style="flex:2; min-width:240px;">
            <label style="font-size:12px; font-weight:600; display:block; margin-bottom:3px; color:#495057;">产品描述</label>
            <input v-model="addForm.description" type="text" class="mh-input" style="width:100%;" placeholder="自动检索物料描述..." />
          </div>
        </div>

        <!-- 动态工序工时录入网格 -->
        <div v-if="matchedProcesses.length > 0" class="process-grid-box">
          <div style="font-weight:600; font-size:12px; margin-bottom:6px; color:#2c3e50;">
            匹配的【{{ currentCategory?.name }}】工序工时录入：
          </div>
          <div class="proc-inputs-grid">
            <div v-for="p in matchedProcesses" :key="p.gx_name">
              <label style="font-size:11.5px; font-weight:600; display:block; margin-bottom:2px; color:#495057;">
                {{ getHeaderDisplayName(p.gx_name) }} <span style="font-size:10.5px; color:#888;">({{ p.zyzx_name }})</span>
              </label>
              <input
                v-model="addForm.hours[p.gx_name]"
                type="number"
                step="0.01"
                min="0"
                class="mh-input"
                style="width:100%; height:28px; font-size:12px;"
              />
            </div>
          </div>
        </div>

        <div style="text-align:right; margin-top:10px; display:flex; justify-content:flex-end; gap:6px;">
          <button type="button" class="btn-outline btn-sm" @click="showAddPanel = false">取消</button>
          <button type="button" class="btn-success btn-sm" :disabled="submittingAdd" @click="submitAdd">
            <el-icon class="mr-1"><Check /></el-icon> 提交保存记录
          </button>
        </div>
      </div>
    </div>

    <!-- 数据主表格 -->
    <div class="mh-card">
      <div class="mh-table-container" v-loading="tableLoading">
        <table class="mh-auto-table">
          <thead>
            <tr>
              <th v-if="isAdmin" class="col-cb"><input type="checkbox" v-model="allSelected" @change="toggleSelectAll" /></th>
              <th
                v-for="c in columns"
                :key="c.column_name"
                :class="getColumnClass(c.column_name)"
                :style="getColumnWidthStyle(c)"
                :title="c.display_name"
              >
                {{ c.display_name }}
              </th>
              <th v-if="canEdit" class="col-action">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in recordList" :key="r.id">
              <td v-if="isAdmin" class="col-cb"><input type="checkbox" v-model="selectedIds" :value="r.id" /></td>
              <template v-for="c in columns" :key="c.column_name">
                <td v-if="c.column_name === 'code'" class="col-code" :title="r.code">
                  {{ r.code }}
                </td>
                <td v-else-if="c.column_name === 'description'" class="col-desc" :title="r.description">
                  {{ r.description || '-' }}
                </td>
                <td
                  v-else-if="isFullHours && c.is_center"
                  class="mh-drill-cell"
                  :title="`点击查看【${c.center_name}】明细工时`"
                  @click="openDrillModal(r, c)"
                >
                  {{ Number(r[c.column_name] || 0).toFixed(2) }}
                </td>
                <td v-else-if="c.column_name === 'total_hours'" class="col-total" :title="Number(r.total_hours || 0).toFixed(2)">
                  {{ Number(r.total_hours || 0).toFixed(2) }}
                </td>
                <td v-else :title="Number(r[c.column_name] || 0).toFixed(2)">
                  {{ Number(r[c.column_name] || 0).toFixed(2) }}
                </td>
              </template>
              <td v-if="canEdit" class="col-action" style="white-space:nowrap;">
                <button type="button" class="btn-primary btn-mini" title="编辑工时" @click="openEditModal(r)">
                  <el-icon><Edit /></el-icon>
                </button>
                <button v-if="isAdmin" type="button" class="btn-danger btn-mini" style="margin-left:3px;" title="删除记录" @click="deleteSingle(r)">
                  <el-icon><Delete /></el-icon>
                </button>
              </td>
            </tr>
            <tr v-if="recordList.length === 0">
              <td :colspan="columns.length + (isAdmin ? 1 : 0) + (canEdit ? 1 : 0)" style="text-align:center; padding:35px; color:#888;">
                暂无工时记录
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 分页栏 -->
      <div class="mh-pagination-bar">
        <div style="color:#64748b;">
          共 <strong>{{ totalRecords }}</strong> 款产品，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页
        </div>
        <el-pagination v-model:current-page="curPage" :page-size="30" :total="totalRecords" layout="prev, pager, next" @current-change="loadTable" />
      </div>
    </div>

    <!-- 弹窗 1: 作业中心工序工时下钻弹窗 -->
    <el-dialog v-model="drillModalVisible" :title="drillTitle" width="650px" destroy-on-close>
      <div v-if="drillRecord" style="font-size:12.5px;">
        <div style="margin-bottom:12px; padding:8px 10px; background:#f8f9fa; border-radius:4px; border:1px solid #dee2e6;">
          <strong>代码：</strong> <span style="font-family:monospace; font-weight:bold;">{{ drillRecord.code }}</span><br />
          <strong>描述：</strong> {{ drillRecord.description || '-' }}
        </div>

        <div v-if="isEditingDrill" style="font-size:11.5px; color:#856404; background:#fff3cd; border:1px solid #ffeeba; padding:6px 10px; border-radius:4px; margin-bottom:10px;">
          💡 <strong>编辑模式已开启：</strong> 您现在可以修改各工序工时，修改后请点击右下角【保存明细工时】。
        </div>

        <div v-if="drillKeys.length === 0" style="text-align:center; padding:30px; color:#888;">
          该产品在当前中心下暂无细分工序数据
        </div>
        <div v-else style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:10px;">
          <div v-for="pName in drillKeys" :key="pName" style="background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px; padding:8px 10px;">
            <label style="font-size:12px; font-weight:600; display:block; margin-bottom:3px; color:#2c3e50;">
              {{ getHeaderDisplayName(pName) }}
            </label>
            <div style="display:flex; align-items:center; gap:6px;">
              <input
                v-model="drillHours[pName]"
                type="number"
                step="0.01"
                min="0"
                class="mh-input"
                style="width:100%; height:28px; font-size:12px;"
                :readonly="!isEditingDrill"
              />
              <span style="font-size:12px; color:#888;">h</span>
            </div>
          </div>
        </div>
      </div>
      <template #footer>
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <button type="button" class="btn-outline btn-sm" @click="jumpToTargetCategory">
            切换到该分类页面
          </button>
          <div style="display:flex; gap:6px;">
            <button v-if="canEdit && drillKeys.length > 0" type="button" class="btn-outline btn-sm" @click="isEditingDrill = !isEditingDrill">
              {{ isEditingDrill ? '锁定工时' : '开启编辑' }}
            </button>
            <button v-if="isEditingDrill" type="button" class="btn-primary btn-sm" @click="saveDrillHours">
              保存明细工时
            </button>
          </div>
        </div>
      </template>
    </el-dialog>

    <!-- 弹窗 2: 编辑整行工时 -->
    <el-dialog v-model="editModalVisible" :title="`✏️ 编辑工时明细 [${editingRecord?.code || ''}]`" width="680px" destroy-on-close>
      <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:10px; font-size:12px;">
        <div v-for="c in editableColumns" :key="c.column_name">
          <label style="font-weight:600; display:block; margin-bottom:3px; color:#495057;">
            {{ c.display_name }}
          </label>
          <input
            v-model="editHours[c.column_name]"
            type="number"
            step="0.01"
            min="0"
            class="mh-input"
            style="width:100%; height:30px;"
          />
        </div>
      </div>
      <template #footer>
        <el-button @click="editModalVisible = false">取消</el-button>
        <el-button type="primary" :loading="savingEdit" @click="submitEdit">保存工时</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Timer, Calendar, Tools, Cpu, Box, Search, Plus, Delete, Edit, Refresh, Check
} from '@element-plus/icons-vue';
import request from '@/api/request';

const isAdmin = ref(false);
const canEdit = ref(false);
const catLoading = ref(false);
const tableLoading = ref(false);

const categoryList = ref([]);
const currentCatSlug = ref('');
const currentCategory = ref(null);
const isFullHours = ref(false);

const columns = ref([]);
const recordList = ref([]);
const totalRecords = ref(0);
const curPage = ref(1);
const totalPages = ref(1);
const categoryTotalHours = ref(0);

const selectedIds = ref([]);
const allSelected = ref(false);
const searchKeyword = ref('');
const globalHeaderMap = reactive({});

const addCodeInputRef = ref(null);

const getCategoryIcon = (name) => {
  if (name.includes('完整工时')) return Calendar;
  if (name.includes('装置装配')) return Tools;
  if (name.includes('装置调试')) return Cpu;
  if (name.includes('装置包装')) return Box;
  return Timer;
};

const getHeaderDisplayName = (colName) => {
  return globalHeaderMap[colName] || colName;
};

const getColumnClass = (colName) => {
  if (colName === 'code') return 'col-code';
  if (colName === 'description') return 'col-desc';
  if (colName === 'total_hours') return 'col-total';
  return '';
};

const getColumnWidthStyle = (c) => {
  if (c.column_name === 'code') return 'width:88px;';
  if (c.column_name === 'description') return 'width:auto;';
  if (c.column_name === 'total_hours') return 'width:62px;';
  const len = (c.display_name || '').length;
  const calc = Math.max(54, len * 13 + 14);
  return `width:${calc}px;`;
};

// 1. 初始化分类与数据加载
const initCategories = async () => {
  catLoading.value = true;
  try {
    const res = await request.get('/manhour/categories');
    if (res.data) {
      const raw = res.data.categories || [];
      const orderRules = ['完整工时', '装置装配', '装置调试', '装置包装'];
      categoryList.value = [...raw].sort((a, b) => {
        let idxA = orderRules.findIndex(r => a.name.includes(r));
        let idxB = orderRules.findIndex(r => b.name.includes(r));
        if (idxA === -1) idxA = 99;
        if (idxB === -1) idxB = 99;
        return idxA - idxB;
      });

      if (categoryList.value.length > 0) {
        currentCatSlug.value = categoryList.value[0].slug;
        loadTable(1);
      }
    }
  } finally {
    catLoading.value = false;
  }
};

const switchCategory = (slug) => {
  if (slug === currentCatSlug.value) return;
  currentCatSlug.value = slug;
  showAddPanel.value = false;
  searchKeyword.value = '';
  loadTable(1);
};

const loadTable = async (p = 1) => {
  curPage.value = p;
  tableLoading.value = true;
  try {
    const res = await request.get('/manhour/list', {
      params: {
        cat: currentCatSlug.value,
        search: searchKeyword.value.trim(),
        page: p
      }
    });

    if (res.data) {
      currentCategory.value = res.data.category;
      isFullHours.value = !!res.data.is_full_hours;
      canEdit.value = !!res.data.can_edit;
      isAdmin.value = !!res.data.is_admin;
      categoryTotalHours.value = res.data.category_total_hours || 0;
      totalRecords.value = res.data.total_records || 0;
      totalPages.value = res.data.total_pages || 1;
      recordList.value = res.data.records || [];

      const rawCols = res.data.columns || [];
      rawCols.forEach(col => {
        if (col.column_name && col.display_name) {
          globalHeaderMap[col.column_name] = col.display_name;
        }
      });
      const otherCols = rawCols.filter(c => c.column_name !== 'total_hours');
      const totalCol = rawCols.find(c => c.column_name === 'total_hours') || { column_name: 'total_hours', display_name: '总工时' };
      columns.value = [...otherCols, totalCol];

      selectedIds.value = [];
      allSelected.value = false;
    }
  } finally {
    tableLoading.value = false;
  }
};

const resetSearch = () => {
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

// 2. 快捷添加记录
const showAddPanel = ref(false);
const fetchingMeta = ref(false);
const submittingAdd = ref(false);
const matchedProcesses = ref([]);
const metaTipText = ref('输入代码后光标移开或按回车，自动匹配工序');
const metaTipStyle = ref({ color: '#888' });

const addForm = reactive({
  code: '',
  description: '',
  hours: {}
});

const toggleAddPanel = () => {
  showAddPanel.value = !showAddPanel.value;
  if (showAddPanel.value) {
    addForm.code = '';
    addForm.description = '';
    addForm.hours = {};
    matchedProcesses.value = [];
    metaTipText.value = '输入代码后光标移开或按回车，自动匹配工序';
    metaTipStyle.value = { color: '#888' };
    nextTick(() => addCodeInputRef.value?.focus());
  }
};

const fetchCodeMeta = async () => {
  const codeVal = addForm.code.trim();
  if (!codeVal) return;

  fetchingMeta.value = true;
  metaTipText.value = '正在从 MES 工艺路线检索工序与物料描述...';
  metaTipStyle.value = { color: '#409EFF' };

  try {
    const res = await request.get('/manhour/get_code_meta', {
      params: { code: codeVal, cat: currentCatSlug.value }
    });
    if (res.data) {
      addForm.description = res.data.description || '';
      matchedProcesses.value = res.data.processes || [];
      addForm.hours = {};
      matchedProcesses.value.forEach(p => {
        addForm.hours[p.gx_name] = 0.00;
      });

      if (matchedProcesses.value.length > 0) {
        metaTipText.value = `✓ 已匹配 ${matchedProcesses.value.length} 个工序 (所属分类: ${res.data.category_name || currentCategory.value?.name})`;
        metaTipStyle.value = { color: '#67C23A' };
        ElMessage.success(`成功带出 ${matchedProcesses.value.length} 个工艺工序！`);
      } else {
        metaTipText.value = `⚠️ MES 未找到归属【${currentCategory.value?.name}】的工序，已作为普通记录录入`;
        metaTipStyle.value = { color: '#E6A23C' };
      }
    }
  } catch (err) {
    metaTipText.value = '⚠️ 检索失败，请检查网络或产品代码';
    metaTipStyle.value = { color: '#F56C6C' };
  } finally {
    fetchingMeta.value = false;
  }
};

const submitAdd = async () => {
  if (!addForm.code.trim()) {
    return ElMessage.warning('请输入产品代码！');
  }

  submittingAdd.value = true;
  try {
    const res = await request.post('/manhour/add', {
      code: addForm.code.trim(),
      description: addForm.description.trim(),
      cat: currentCatSlug.value,
      hours: addForm.hours
    });
    ElMessage.success(res.message || '添加成功！');
    showAddPanel.value = false;
    loadTable(1);
  } finally {
    submittingAdd.value = false;
  }
};

// 3. 完整工时明细下钻与编辑
const drillModalVisible = ref(false);
const drillRecord = ref(null);
const drillCenter = ref('');
const drillTargetSlug = ref('');
const drillHours = reactive({});
const isEditingDrill = ref(false);

const drillTitle = computed(() => `【${drillCenter.value}】工序工时明细`);
const drillKeys = computed(() => Object.keys(drillHours));

const openDrillModal = (record, col) => {
  drillRecord.value = record;
  drillCenter.value = col.center_name;
  drillTargetSlug.value = col.target_slug;
  isEditingDrill.value = false;

  const centerDetails = record.center_details ? record.center_details[col.center_name] || {} : {};
  Object.keys(drillHours).forEach(k => delete drillHours[k]);
  Object.assign(drillHours, centerDetails);

  drillModalVisible.value = true;
};

const jumpToTargetCategory = () => {
  drillModalVisible.value = false;
  currentCatSlug.value = drillTargetSlug.value;
  searchKeyword.value = drillRecord.value?.code || '';
  loadTable(1);
};

const saveDrillHours = async () => {
  try {
    const res = await request.post('/manhour/update', {
      id: drillRecord.value.id,
      cat: currentCatSlug.value,
      hours: drillHours
    });
    ElMessage.success(res.message || '明细工时更新成功！');
    drillModalVisible.value = false;
    loadTable(curPage.value);
  } catch (e) {}
};

// 4. 整行编辑工时
const editModalVisible = ref(false);
const editingRecord = ref(null);
const editHours = reactive({});
const savingEdit = ref(false);

const editableColumns = computed(() => {
  if (isFullHours.value) {
    if (!editingRecord.value) return [];
    const pHours = editingRecord.value.process_hours ? JSON.parse(editingRecord.value.process_hours) : {};
    return Object.keys(pHours).map(k => ({ column_name: k, display_name: getHeaderDisplayName(k) }));
  }
  return columns.value.filter(c => !['code', 'description', 'total_hours'].includes(c.column_name));
});

const openEditModal = (r) => {
  editingRecord.value = r;
  Object.keys(editHours).forEach(k => delete editHours[k]);

  if (isFullHours.value) {
    const pHours = r.process_hours ? JSON.parse(r.process_hours) : {};
    Object.assign(editHours, pHours);
  } else {
    editableColumns.value.forEach(c => {
      editHours[c.column_name] = r[c.column_name] || 0;
    });
  }

  editModalVisible.value = true;
};

const submitEdit = async () => {
  savingEdit.value = true;
  try {
    const res = await request.post('/manhour/update', {
      id: editingRecord.value.id,
      cat: currentCatSlug.value,
      hours: editHours
    });
    ElMessage.success(res.message || '工时保存成功！');
    editModalVisible.value = false;
    loadTable(curPage.value);
  } finally {
    savingEdit.value = false;
  }
};

// 5. 删除
const deleteSingle = (r) => {
  ElMessageBox.confirm(`确定要删除代码 [${r.code}] 的工时记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/manhour/delete', { ids: [r.id] });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const batchDelete = () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请勾选记录！');
  ElMessageBox.confirm(`确定要永久删除选中的 ${selectedIds.value.length} 条工时记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/manhour/delete', { ids: selectedIds.value });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

onMounted(() => {
  initCategories();
});
</script>

<style scoped>
.mh-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.mh-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}

.mh-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  border-bottom: 2px solid #dee2e6;
  flex-wrap: wrap;
}

.mh-tab-btn {
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
  display: inline-flex;
  align-items: center;
}
.mh-tab-btn.active {
  color: #007bff;
  border-bottom-color: #007bff;
}

.mh-summary-bar {
  background: #e8f4fd;
  border: 1px solid #b3d9ff;
  border-radius: 6px;
  padding: 7px 12px;
  margin-bottom: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 12.5px;
}

.mh-batch-bar {
  background: #e0f2fe;
  padding: 6px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #bae6fd;
  font-size: 12.5px;
  margin-bottom: 10px;
}

.add-panel {
  border-left: 4px solid #28a745;
}
.add-panel-header {
  padding: 8px 12px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  font-size: 13px;
  font-weight: 600;
  color: #2c3e50;
}

.process-grid-box {
  margin-top: 10px;
  background: #f8f9fc;
  padding: 10px;
  border-radius: 4px;
  border: 1px solid #e3e6f0;
}
.proc-inputs-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 8px;
}

.mh-table-container {
  width: 100% !important;
  max-width: 100% !important;
  overflow-x: auto;
  border-top: 1px solid #dee2e6;
  box-sizing: border-box;
}

.mh-auto-table {
  width: 100% !important;
  table-layout: fixed !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 12px;
  margin-bottom: 0 !important;
}

.mh-auto-table thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 10;
  padding: 6px 3px;
  border-bottom: 2px solid #dee2e6;
  border-right: 1px solid #dee2e6;
  font-weight: 600;
  color: #2c3e50;
  text-align: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.mh-auto-table tbody td {
  padding: 5px 3px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  vertical-align: middle;
  text-align: right;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.mh-auto-table tbody tr:hover { background-color: #f8fbfe; }

.col-cb { width: 34px !important; text-align: center !important; }
.col-code { width: 88px !important; font-family: monospace; font-weight: 600; color: #2c3e50; text-align: center !important; }
.col-desc { text-align: left !important; }
.col-total { width: 62px !important; font-weight: bold; color: #007bff; }
.col-action { width: 75px !important; text-align: center !important; }

.mh-drill-cell {
  color: #007bff;
  cursor: pointer;
  font-weight: 600;
  text-decoration: underline;
  text-underline-offset: 2px;
}
.mh-drill-cell:hover {
  color: #0056b3;
  background-color: #e8f4fd;
}

.mh-pagination-bar {
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

.mh-input {
  height: 32px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 8px;
  outline: none;
  box-sizing: border-box;
}
.mh-input:focus { border-color: #80bdff; }

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
.btn-mini { padding: 2px 5px; font-size: 11px; }
.mr-1 { margin-right: 4px; }
</style>