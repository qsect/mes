<template>
  <div class="wk-container">
    <!-- 顶部标题卡片 -->
    <div class="wk-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><DataAnalysis /></el-icon>
          周完成情况统计与趋势分析
        </span>
      </div>
    </div>

    <!-- 顶部选项卡切换 -->
    <div class="wk-nav-tabs">
      <button type="button" class="wk-tab-btn" :class="{ active: currentTab === 'dashboard' }" @click="switchTab('dashboard')">
        <el-icon class="mr-1"><TrendCharts /></el-icon> 产出量趋势分析看板
      </button>
      <button type="button" class="wk-tab-btn" :class="{ active: currentTab === 'list' }" @click="switchTab('list')">
        <el-icon class="mr-1"><List /></el-icon> 生产进度明细列表
      </button>
    </div>

    <!-- ================= 视图 1: 趋势看板 ================= -->
    <div v-show="currentTab === 'dashboard'">
      <!-- 汇总走势指标条 -->
      <div v-if="dashboardMetrics" class="trend-metric-box">
        <div class="trend-metric-item">
          <span style="font-size:12px; color:#64748b;">📊 当前总体产出均值</span>
          <span class="trend-metric-val" style="color:#2c3e50;">{{ Number(dashboardMetrics.overall_avg).toFixed(2) }}</span>
        </div>
        <div class="trend-metric-item">
          <span style="font-size:12px; color:#64748b;">⏪ 历史周次平均值</span>
          <span class="trend-metric-val" style="color:#64748b;">{{ Number(dashboardMetrics.overall_avg_prev).toFixed(2) }}</span>
        </div>
        <div class="trend-metric-item">
          <span style="font-size:12px; color:#64748b;">📈 最新周波动趋势</span>
          <span class="trend-metric-val" :class="trendClass">
            {{ dashboardMetrics.trend_direction === '增长' ? '↑' : (dashboardMetrics.trend_direction === '降低' ? '↓' : '-') }}
            {{ dashboardMetrics.trend_direction }} {{ dashboardMetrics.trend_percent }}%
          </span>
        </div>
        <div style="margin-left:auto; display:flex; gap:16px; align-items:center;">
          <span style="font-size:12.5px; color:#495057;">总标准装置: <strong>{{ Number(dashboardMetrics.grand_total_std).toFixed(2) }}</strong></span>
          <span style="font-size:12.5px; color:#495057;">总用时: <strong>{{ Number(dashboardMetrics.grand_total_hours).toFixed(2) }} 小时</strong></span>
        </div>
      </div>

      <!-- 工序汇总简表 -->
      <div class="wk-card" style="margin-bottom:12px;">
        <div class="wk-card-header">
          <span>各工序标准产出与工时汇总统计</span>
        </div>
        <div class="wk-table-wrapper" style="max-height:260px; min-height:120px;">
          <table class="wk-fixed-table">
            <thead>
              <tr>
                <th style="text-align:left; padding-left:10px;">工序名称</th>
                <th style="text-align:right;">总标准装置数</th>
                <th style="text-align:right;">总用时 (小时)</th>
                <th style="text-align:center; width:90px;">记录条数</th>
                <th style="text-align:right; width:150px;">单位小时产出量均值</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in summaryList" :key="r.process">
                <td style="text-align:left; padding-left:10px;"><strong>{{ r.process }}</strong></td>
                <td style="text-align:right;">{{ Number(r.total_standard_device).toFixed(2) }}</td>
                <td style="text-align:right;">{{ Number(r.total_hours_used).toFixed(2) }}</td>
                <td style="text-align:center;">{{ r.record_count }}</td>
                <td style="text-align:right; font-weight:bold; color:#007bff;">{{ Number(r.avg_hourly_output).toFixed(2) }}</td>
              </tr>
              <tr v-if="summaryList.length === 0">
                <td colspan="5" style="text-align:center; padding:25px; color:#888;">暂无汇总数据</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- 折线图卡片 -->
      <div class="wk-card">
        <div class="wk-card-header" style="display:flex; justify-content:space-between; align-items:center;">
          <span>每周单位小时产出量波动趋势</span>
          <div style="display:flex; align-items:center; gap:6px;">
            <span style="font-size:12.5px; font-weight:600; color:#495057;">工序筛选：</span>
            <select v-model="chartProcessFilter" class="wk-select" style="width:145px;" @change="onChartProcessChange">
              <option value="__all__">全部工序</option>
              <option v-for="p in chartProcessList" :key="p" :value="p">{{ p }}</option>
            </select>
          </div>
        </div>
        <div style="padding:14px;">
          <div style="position:relative; height:360px; width:100%;">
            <canvas ref="trendCanvasRef"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- ================= 视图 2: 明细列表 ================= -->
    <div v-show="currentTab === 'list'">
      <div class="wk-card">
        <div class="wk-filter-card">
          <div class="wk-filter-grid">
            <select v-model="filterWeek" class="wk-select" style="width:130px;" @change="loadTable(1)">
              <option value="">全部周次</option>
              <option v-for="w in weekList" :key="w" :value="w">{{ w }}</option>
            </select>
            <select v-model="filterProcess" class="wk-select" style="width:130px;" @change="loadTable(1)">
              <option value="">全部工序</option>
              <option v-for="p in procList" :key="p" :value="p">{{ p }}</option>
            </select>
            <input
              v-model="searchKeyword"
              type="text"
              class="wk-input"
              style="width:200px;"
              placeholder="搜索周次或备注..."
              @keyup.enter="loadTable(1)"
            />
            <button type="button" class="btn-primary btn-sm" @click="loadTable(1)">
              <el-icon class="mr-1"><Search /></el-icon> 查询
            </button>
            <button type="button" class="btn-outline btn-sm" @click="resetFilter">重置</button>
            <div style="display:flex; gap:6px; margin-left:auto;">
              <button v-if="isAdmin || allowedProcesses.length > 0" type="button" class="btn-success btn-sm" @click="openAddModal">
                <el-icon class="mr-1"><Plus /></el-icon> 添加记录
              </button>
              <button type="button" class="btn-primary btn-sm" @click="exportExcel">
                <el-icon class="mr-1"><Download /></el-icon> 导出 Excel
              </button>
            </div>
          </div>
        </div>

        <!-- 批量操作栏 -->
        <div v-if="selectedIds.length > 0 && canManage" class="wk-batch-bar">
          <span style="color:#0369a1;">已勾选 <strong style="color:#409EFF;">{{ selectedIds.length }}</strong> 条记录</span>
          <div style="display:flex; gap:6px;">
            <button type="button" class="btn-danger btn-mini" @click="batchDelete">
              <el-icon class="mr-1"><Delete /></el-icon> 批量删除
            </button>
            <button type="button" class="btn-outline btn-mini" @click="selectedIds = []">取消选择</button>
          </div>
        </div>

        <div class="wk-table-wrapper" v-loading="tableLoading">
          <table class="wk-fixed-table">
            <thead>
              <tr>
                <th class="col-cb"><input type="checkbox" v-model="allSelected" @change="toggleSelectAll" /></th>
                <th class="col-week">周次</th>
                <th class="col-proc">工序</th>
                <th class="col-num">用人数</th>
                <th class="col-num">用时(h)</th>
                <th class="col-num">标准装置</th>
                <th class="col-num">人均周产</th>
                <th class="col-num">时均产出</th>
                <th class="col-remarks">备注说明</th>
                <th class="col-user">操作者</th>
                <th class="col-time">创建时间</th>
                <th class="col-action">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in recordList" :key="r.id" :class="{ selected: selectedIds.includes(r.id) }">
                <td class="col-cb">
                  <input type="checkbox" v-model="selectedIds" :value="r.id" />
                </td>
                <td class="col-week">{{ r.week_number }}</td>
                <td class="col-proc"><span class="badge-tag badge-tag-proc">{{ r.process }}</span></td>
                <td class="col-num">{{ Number(r.worker_count).toFixed(2) }}</td>
                <td class="col-num">{{ Number(r.hours_used).toFixed(2) }}</td>
                <td class="col-num" style="font-weight:bold;">{{ Number(r.standard_device).toFixed(2) }}</td>
                <td class="col-num" style="color:#28a745; font-weight:600;">{{ Number(r.weekly_output_per_person || 0).toFixed(2) }}</td>
                <td class="col-num" style="color:#007bff; font-weight:600;">{{ Number(r.hourly_output || 0).toFixed(2) }}</td>
                <td class="col-remarks" :title="r.remarks">{{ r.remarks || '-' }}</td>
                <td class="col-user">{{ r.creator_name }}</td>
                <td class="col-time">{{ r.created_at ? r.created_at.substring(5, 16) : '-' }}</td>
                <td class="col-action">
                  <button v-if="r.can_edit" type="button" class="btn-primary btn-mini" title="编辑" @click="openEditModal(r)">
                    <el-icon><Edit /></el-icon>
                  </button>
                  <button v-if="r.can_delete" type="button" class="btn-danger btn-mini" style="margin-left:3px;" title="删除" @click="deleteSingle(r.id)">
                    <el-icon><Delete /></el-icon>
                  </button>
                </td>
              </tr>
              <tr v-if="recordList.length === 0">
                <td colspan="12" style="text-align:center; padding:35px; color:#888;">
                  暂无周完成情况记录
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="wk-pagination-bar">
          <div style="color:#64748b;">共 <strong>{{ totalRecords }}</strong> 条记录，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页</div>
          <el-pagination v-model:current-page="curPage" :page-size="30" :total="totalRecords" layout="prev, pager, next" @current-change="loadTable" />
        </div>
      </div>
    </div>

    <!-- 添加 / 编辑弹窗 -->
    <el-dialog v-model="modalVisible" :title="modalTitle" width="650px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;">
        <div class="form-grid-2">
          <div>
            <label class="form-label">周次 (如: 2026-W36) *</label>
            <input v-model="form.week_number" type="text" class="wk-input" style="width:100%;" placeholder="2026-W01" required />
          </div>

          <div>
            <label class="form-label">工序 *</label>
            <select v-model="form.process" class="wk-select" style="width:100%;" required>
              <option value="">请选择工序</option>
              <option v-for="p in formProcessOptions" :key="p" :value="p">{{ p }}</option>
            </select>
          </div>

          <div>
            <label class="form-label">用人数量 (人) *</label>
            <input v-model.number="form.worker_count" type="number" step="0.01" min="0" class="wk-input" style="width:100%;" required />
          </div>

          <div>
            <label class="form-label">用时 (小时) *</label>
            <input v-model.number="form.hours_used" type="number" step="0.01" min="0" class="wk-input" style="width:100%;" required />
          </div>

          <div>
            <label class="form-label">标准装置 (台) *</label>
            <input v-model.number="form.standard_device" type="number" step="0.01" min="0" class="wk-input" style="width:100%;" required />
          </div>

          <div>
            <label class="form-label">操作人</label>
            <input type="text" class="wk-input" :value="currentUserDisplayName" disabled style="background:#f8f9fa; width:100%;" />
          </div>

          <div style="grid-column: span 2;">
            <label class="form-label">备注说明</label>
            <textarea v-model="form.remarks" class="wk-input" rows="2" style="width:100%; height:auto;" placeholder="填写生产异常或进度备注..."></textarea>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button @click="modalVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitSave">保存进度记录</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  DataAnalysis, TrendCharts, List, Search, Plus, Download, Delete, Edit
} from '@element-plus/icons-vue';
import request from '@/api/request';

const currentTab = ref('dashboard');
const isAdmin = ref(false);
// ★ 按钮级权限：批量删除（删除任意记录）需维护权限，无权限则不显示批量操作栏
const canManage = ref(false);
const allowedProcesses = ref([]);

// 趋势看板
const dashboardMetrics = ref(null);
const summaryList = ref([]);
const chartProcessFilter = ref('__all__');
const chartProcessList = ref([]);
const trendCanvasRef = ref(null);
let trendChartInstance = null;
let dashboardRawData = null;

// 列表查询
const weekList = ref([]);
const procList = ref([]);
const filterWeek = ref('');
const filterProcess = ref('');
const searchKeyword = ref('');
const tableLoading = ref(false);
const recordList = ref([]);
const totalRecords = ref(0);
const curPage = ref(1);
const totalPages = ref(1);
const selectedIds = ref([]);
const allSelected = ref(false);

// 弹窗
const modalVisible = ref(false);
const saving = ref(false);
const editingId = ref(null);
const form = reactive({
  week_number: '',
  process: '',
  worker_count: 0,
  hours_used: 0,
  standard_device: 0,
  remarks: ''
});

const currentUserDisplayName = computed(() => {
  const u = JSON.parse(localStorage.getItem('user') || '{}');
  return u.full_name || u.username || '当前用户';
});

const formProcessOptions = computed(() => {
  return isAdmin.value ? procList.value : allowedProcesses.value;
});

const modalTitle = computed(() => {
  return editingId.value ? `✏️ 编辑生产进度 [${form.week_number} - ${form.process}]` : '➕ 添加生产进度记录';
});

const trendClass = computed(() => {
  if (!dashboardMetrics.value) return 'trend-flat';
  const d = dashboardMetrics.value.trend_direction;
  if (d === '增长') return 'trend-up';
  if (d === '降低') return 'trend-down';
  return 'trend-flat';
});

// 动态确保 Chart.js 已加载
const ensureChartJs = () => {
  if (typeof window.Chart !== 'undefined') return Promise.resolve(true);
  return new Promise((resolve) => {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js';
    s.onload = () => resolve(true);
    s.onerror = () => resolve(false);
    document.head.appendChild(s);
  });
};

const switchTab = (tab) => {
  currentTab.value = tab;
  if (tab === 'dashboard') {
    loadDashboard();
  } else {
    loadTable(curPage.value);
  }
};

const loadFilterOptions = async () => {
  try {
    const res = await request.get('/weekly/filter_options');
    if (res.data) {
      weekList.value = res.data.weeks || [];
      procList.value = res.data.valid_processes || res.data.processes || [];
      allowedProcesses.value = res.data.allowed_processes || [];
      isAdmin.value = !!res.data.is_admin;
      canManage.value = !!res.data.can_manage;
    }
  } catch (e) {}
};

const loadDashboard = async () => {
  try {
    const res = await request.get('/weekly/dashboard_data');
    if (res.data) {
      dashboardRawData = res.data;
      dashboardMetrics.value = res.data;
      summaryList.value = res.data.dashboard_data || [];
      chartProcessList.value = res.data.processes || [];

      await ensureChartJs();
      renderChart(chartProcessFilter.value);
    }
  } catch (e) {}
};

const onChartProcessChange = () => {
  renderChart(chartProcessFilter.value);
};

const renderChart = (selectedProcess) => {
  if (!dashboardRawData || typeof window.Chart === 'undefined' || !trendCanvasRef.value) return;
  if (trendChartInstance) trendChartInstance.destroy();

  const ctx = trendCanvasRef.value.getContext('2d');
  const colors = ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c'];
  let datasets = [];

  if (selectedProcess === '__all__') {
    dashboardRawData.processes.forEach((proc, idx) => {
      datasets.push({
        label: proc,
        data: dashboardRawData.chart_data[proc] || [],
        borderColor: colors[idx % colors.length],
        backgroundColor: 'transparent',
        borderWidth: 2,
        tension: 0.2
      });
    });
  } else {
    const proc = selectedProcess;
    const avg = dashboardRawData.process_avg[proc] || 0;
    datasets.push({
      label: proc,
      data: dashboardRawData.chart_data[proc] || [],
      borderColor: '#4e73df',
      backgroundColor: 'transparent',
      borderWidth: 3,
      tension: 0.2
    });
    datasets.push({
      label: `平均参考线 (${avg.toFixed(2)})`,
      data: dashboardRawData.weeks.map(() => avg),
      borderColor: '#e74a3b',
      borderDash: [6, 4],
      pointRadius: 0,
      borderWidth: 2,
      fill: false
    });
  }

  trendChartInstance = new window.Chart(ctx, {
    type: 'line',
    data: {
      labels: dashboardRawData.weeks,
      datasets: datasets
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'top', labels: { boxWidth: 12 } }
      },
      scales: {
        x: { title: { display: true, text: '周次 (Week)' } },
        y: { title: { display: true, text: '单位小时产出量' }, beginAtZero: true }
      }
    }
  });
};

const loadTable = async (p = 1) => {
  curPage.value = p;
  tableLoading.value = true;
  try {
    const res = await request.get('/weekly/list', {
      params: {
        page: p,
        filter_week: filterWeek.value,
        filter_process: filterProcess.value,
        search_all: searchKeyword.value.trim()
      }
    });
    if (res.data) {
      recordList.value = res.data.records || [];
      totalRecords.value = res.data.total_records || 0;
      totalPages.value = res.data.total_pages || 1;
      isAdmin.value = !!res.data.is_admin;
      canManage.value = !!res.data.can_manage;
      selectedIds.value = [];
      allSelected.value = false;
    }
  } finally {
    tableLoading.value = false;
  }
};

const resetFilter = () => {
  filterWeek.value = '';
  filterProcess.value = '';
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

const openAddModal = () => {
  editingId.value = null;
  const now = new Date();
  const oneJan = new Date(now.getFullYear(), 0, 1);
  const numberOfDays = Math.floor((now - oneJan) / (24 * 60 * 60 * 1000));
  const resultWeek = Math.ceil((now.getDay() + 1 + numberOfDays) / 7);

  Object.assign(form, {
    week_number: `${now.getFullYear()}-W${resultWeek.toString().padStart(2, '0')}`,
    process: formProcessOptions.value[0] || '装置装配',
    worker_count: 0,
    hours_used: 0,
    standard_device: 0,
    remarks: ''
  });
  modalVisible.value = true;
};

const openEditModal = (r) => {
  editingId.value = r.id;
  Object.assign(form, {
    week_number: r.week_number,
    process: r.process,
    worker_count: Number(r.worker_count),
    hours_used: Number(r.hours_used),
    standard_device: Number(r.standard_device),
    remarks: r.remarks || ''
  });
  modalVisible.value = true;
};

const submitSave = async () => {
  if (!form.week_number.trim() || !form.process) {
    return ElMessage.warning('周次和工序为必填项！');
  }
  if (!/^\d{4}-W\d{2}$/.test(form.week_number.trim())) {
    return ElMessage.warning('周次格式应形如 2026-W01！');
  }

  saving.value = true;
  try {
    const act = editingId.value ? '/weekly/update' : '/weekly/add';
    const payload = {
      record_id: editingId.value,
      ...form
    };
    const res = await request.post(act, payload);
    ElMessage.success(res.message || '保存成功');
    modalVisible.value = false;
    loadFilterOptions();
    if (currentTab.value === 'dashboard') loadDashboard();
    else loadTable(curPage.value);
  } finally {
    saving.value = false;
  }
};

const deleteSingle = (id) => {
  ElMessageBox.confirm('确定要删除该条生产进度记录吗？', '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/weekly/delete', { ids: [id] });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const batchDelete = () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请勾选记录！');
  ElMessageBox.confirm(`确定要永久删除选中的 ${selectedIds.value.length} 条记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/weekly/delete', { ids: selectedIds.value });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const exportExcel = () => {
  const w = filterWeek.value;
  const p = filterProcess.value;
  const s = searchKeyword.value.trim();
  window.open(`/api/weekly/export?filter_week=${encodeURIComponent(w)}&filter_process=${encodeURIComponent(p)}&search_all=${encodeURIComponent(s)}`, '_blank');
};

onMounted(async () => {
  await loadFilterOptions();
  loadDashboard();
});
</script>

<style scoped>
.wk-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.wk-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  margin-bottom: 12px;
  overflow: hidden;
}
.wk-card-header {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  font-size: 13.5px;
  font-weight: 600;
  color: #2c3e50;
}

.wk-nav-tabs {
  display: flex;
  gap: 10px;
  margin-bottom: 12px;
  border-bottom: 2px solid #dee2e6;
  flex-wrap: wrap;
}
.wk-tab-btn {
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
.wk-tab-btn.active {
  color: #007bff;
  border-bottom-color: #007bff;
}

.trend-metric-box {
  display: flex;
  gap: 20px;
  flex-wrap: wrap;
  padding: 14px 18px;
  background: #f0f7ff;
  border-radius: 6px;
  border: 1px solid #b3d9ff;
  margin-bottom: 12px;
}
.trend-metric-item {
  display: flex;
  flex-direction: column;
}
.trend-metric-val {
  font-size: 20px;
  font-weight: bold;
}
.trend-up { color: #28a745; }
.trend-down { color: #dc3545; }
.trend-flat { color: #ffc107; }

.wk-filter-card {
  padding: 8px 12px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}
.wk-filter-grid {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}
.wk-batch-bar {
  background: #e0f2fe;
  padding: 6px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #bae6fd;
  font-size: 12.5px;
}

.wk-table-wrapper {
  width: 100% !important;
  max-width: 100% !important;
  overflow-x: hidden !important;
  overflow-y: auto;
  max-height: calc(100vh - 350px);
  min-height: 280px;
  box-sizing: border-box;
}
.wk-fixed-table {
  width: 100% !important;
  max-width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed !important;
  font-size: 12px;
  color: #333;
  margin-bottom: 0 !important;
}
.wk-fixed-table thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 10;
  padding: 7px 4px;
  border-bottom: 2px solid #dee2e6;
  border-right: 1px solid #dee2e6;
  white-space: nowrap;
  font-weight: 600;
  color: #2c3e50;
  text-align: center;
}
.wk-fixed-table tbody td {
  padding: 6px 4px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
}
.wk-fixed-table tbody tr:hover { background: #f8fafc; }
.wk-fixed-table tbody tr.selected { background: #e0f2fe !important; }

.col-cb { width: 34px !important; text-align: center !important; }
.col-week { width: 85px !important; text-align: center !important; font-family: monospace; font-weight: 600; }
.col-proc { width: 75px !important; text-align: center !important; }
.col-num { width: 68px !important; text-align: right !important; }
.col-user { width: 90px !important; text-align: center !important; }
.col-time { width: 90px !important; text-align: center !important; font-size: 11px; color: #64748b; }
.col-action { width: 80px !important; text-align: center !important; }
.col-remarks { width: 65px !important; text-align: left !important; }

.badge-tag { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 500; }
.badge-tag-proc { background: #e8f4fd; color: #007bff; }

.wk-pagination-bar {
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

.wk-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.wk-input:focus { border-color: #80bdff; }

.wk-select {
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

.btn-sm { font-size: 12px; height: 32px; }
.btn-mini { padding: 1px 6px; font-size: 11px; height: 22px; line-height: 20px; }
.mr-1 { margin-right: 4px; }
</style>