<template>
  <div class="oee-container">
    <!-- 顶部标题卡片 -->
    <div class="oee-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Odometer /></el-icon>
          班组效率  管理看板
        </span>
      </div>
    </div>

    <!-- 顶部选项卡导航 -->
    <div class="oee-tabs">
      <button
        type="button"
        class="oee-tab-btn"
        :class="{ active: currentTab === 'dashboard' }"
        @click="switchTab('dashboard')"
      >
        <el-icon class="mr-1"><TrendCharts /></el-icon> 班组效率趋势看板
      </button>
      <button
        type="button"
        class="oee-tab-btn"
        :class="{ active: currentTab === 'list' }"
        @click="switchTab('list')"
      >
        <el-icon class="mr-1"><List /></el-icon> 班组效率明细清单
      </button>
    </div>

    <!-- ================= 视图 1: 趋势看板 ================= -->
    <div v-show="currentTab === 'dashboard'">
      <!-- 汇总数据指标 Banner -->
      <div class="oee-stat-banner">
        <div>
          <div style="font-size:12px; color:#6c757d; font-weight:500;">当前整体平均效率</div>
          <div class="oee-stat-number" style="color:#409EFF;">{{ overallAvgText }}</div>
        </div>
        <div>
          <div style="font-size:12px; color:#6c757d; font-weight:500;">历史各月平均值</div>
          <div class="oee-stat-number" style="color:#6c757d;">{{ prevAvgText }}</div>
        </div>
        <div>
          <div style="font-size:12px; color:#6c757d; font-weight:500;">最新月度波动趋势</div>
          <div class="oee-stat-number" :class="trendClass">{{ trendDisplayText }}</div>
        </div>
        <div style="margin-left:auto; display:flex; align-items:center; gap:6px;">
          <label style="font-size:12.5px; font-weight:600; color:#495057; margin-bottom:0;">选择班组：</label>
          <select v-model="selectedTeamForChart" class="oee-select" style="width:145px;" @change="loadDashboardData">
            <option v-if="isAdmin || allowedTeams.length > 1" value="__all__">全部班组</option>
            <option v-for="t in allowedTeams" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
      </div>

      <!-- 月度折线图卡片 -->
      <div class="oee-card">
        <div class="oee-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#409EFF; vertical-align:middle; margin-right:4px;"><TrendCharts /></el-icon>
            班组月度效率变化折线图 (产出/小时 × 折算系数)
          </span>
          <span style="font-size:11.5px; color:#888;">💡 实线：已录入数据 | 虚线：未录入跨月过渡</span>
        </div>
        <div style="padding:14px;">
          <div style="position:relative; height:340px; width:100%;">
            <canvas ref="trendCanvasRef"></canvas>
          </div>
        </div>
      </div>

      <!-- 跨年度对比图卡片 -->
      <div class="oee-card">
        <div class="oee-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#67C23A; vertical-align:middle; margin-right:4px;"><Calendar /></el-icon>
            跨年度效率对比 (1~12月及年度平均)
          </span>
          <span style="font-size:11.5px; color:#888;">💡 实线：实际月份效率 | 虚线：缺失月份过渡</span>
        </div>
        <div style="padding:14px;">
          <div style="position:relative; height:320px; width:100%; margin-bottom:12px;">
            <canvas ref="yearCanvasRef"></canvas>
          </div>
          <div v-if="yearTrendLines.length > 0" class="year-trend-box">
            <div style="font-weight:bold; margin-bottom:4px; color:#E6A23C;">
              年度趋势分析研判：
            </div>
            <div v-for="(line, idx) in yearTrendLines" :key="idx" style="line-height:1.8;">
              • {{ line }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ================= 视图 2: 明细列表 ================= -->
    <div v-show="currentTab === 'list'">
      <div class="oee-card">
        <div class="oee-filter-card">
          <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <select v-model="filterTeam" class="oee-select" style="width:130px;" @change="loadTable(1)">
              <option value="">全部班组</option>
              <option v-for="t in allowedTeams" :key="t" :value="t">{{ t }}</option>
            </select>
            <input v-model="filterStart" type="month" class="oee-input" style="width:135px;" @change="loadTable(1)" />
            <span style="color:#888; font-size:12px;">至</span>
            <input v-model="filterEnd" type="month" class="oee-input" style="width:135px;" @change="loadTable(1)" />
            <button type="button" class="btn-primary btn-sm" @click="loadTable(1)">
              <el-icon class="mr-1"><Search /></el-icon> 查询
            </button>
            <button type="button" class="btn-outline btn-sm" @click="resetFilter">重置</button>
            <div style="display:flex; gap:6px; margin-left:auto;">
              <button v-if="isAdmin || editTeams.length > 0" type="button" class="btn-success btn-sm" @click="openAddModal">
                <el-icon class="mr-1"><Plus /></el-icon> 添加记录
              </button>
              <button type="button" class="btn-primary btn-sm" @click="exportCsv">
                <el-icon class="mr-1"><Download /></el-icon> 导出 Excel
              </button>
            </div>
          </div>
        </div>

        <!-- 批量操作栏 -->
        <div v-if="selectedIds.length > 0 && isAdmin" class="oee-batch-bar">
          <span style="color:#0369a1;">已勾选 <strong style="color:#409EFF;">{{ selectedIds.length }}</strong> 条记录</span>
          <div style="display:flex; gap:6px;">
            <button type="button" class="btn-danger btn-mini" @click="batchDelete">
              <el-icon class="mr-1"><Delete /></el-icon> 批量删除
            </button>
            <button type="button" class="btn-outline btn-mini" @click="selectedIds = []">取消选择</button>
          </div>
        </div>

        <!-- 表格主体 (固定列宽) -->
        <div class="oee-table-wrapper" v-loading="tableLoading">
          <table class="oee-fixed-table">
            <thead>
              <tr>
                <th v-if="isAdmin" class="col-cb"><input type="checkbox" v-model="allSelected" @change="toggleSelectAll" /></th>
                <th class="col-team">班组</th>
                <th class="col-month">月份</th>
                <th class="col-num">总产量</th>
                <th class="col-num">委外产量</th>
                <th class="col-num">实际产量</th>
                <th class="col-num">用时(h)</th>
                <th class="col-eff">效率</th>
                <th class="col-remarks">备注说明</th>
                <th class="col-user">创建人</th>
                <th class="col-action">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in recordList" :key="r.id" :class="{ selected: selectedIds.includes(r.id) }">
                <td v-if="isAdmin" class="col-cb"><input type="checkbox" v-model="selectedIds" :value="r.id" /></td>
                <td class="col-team">{{ r.team }}</td>
                <td class="col-month"><span class="badge-tag badge-tag-proc">{{ r.stat_month }}</span></td>
                <td class="col-num">{{ Number(r.total_output || 0).toFixed(2) }}</td>
                <td class="col-num">{{ Number(r.outsourced_output || 0).toFixed(2) }}</td>
                <td class="col-num" style="font-weight:bold;">{{ Number(r.actual_output || 0).toFixed(2) }}</td>
                <td class="col-num">{{ Number(r.actual_time || 0).toFixed(2) }}</td>
                <td class="col-eff">{{ Number(r.efficiency || 0).toFixed(2) }}</td>
                <td class="col-remarks" :title="r.remarks">{{ r.remarks || '-' }}</td>
                <td class="col-user">{{ r.creator_name }}</td>
                <td class="col-action">
                  <button v-if="r.can_edit" type="button" class="btn-primary btn-mini" title="编辑" @click="openEditModal(r)">
                    <el-icon><Edit /></el-icon>
                  </button>
                  <button v-if="isAdmin" type="button" class="btn-danger btn-mini" style="margin-left:3px;" title="删除" @click="deleteSingle(r.id)">
                    <el-icon><Delete /></el-icon>
                  </button>
                </td>
              </tr>
              <tr v-if="recordList.length === 0">
                <td :colspan="isAdmin ? 11 : 10" style="text-align:center; padding:35px; color:#888;">
                  暂无班组效率记录
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="oee-pagination-bar">
          <div style="color:#64748b;">共 <strong>{{ totalRecords }}</strong> 条记录，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页</div>
          <el-pagination v-model:current-page="curPage" :page-size="30" :total="totalRecords" layout="prev, pager, next" @current-change="loadTable" />
        </div>
      </div>
    </div>

    <!-- 添加/编辑弹窗 -->
    <el-dialog v-model="modalVisible" :title="modalTitle" width="600px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;">
        <div class="form-grid-2">
          <div>
            <label class="form-label">所属班组 *</label>
            <select v-model="form.team" class="oee-select" style="width:100%;" required>
              <option v-for="t in editTeams" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>

          <div>
            <label class="form-label">统计月份 *</label>
            <input v-model="form.stat_month" type="month" class="oee-input" style="width:100%;" required />
          </div>

          <div>
            <label class="form-label">总产量 (台) *</label>
            <input v-model.number="form.total_output" type="number" step="0.01" min="0" class="oee-input" style="width:100%;" placeholder="0.00" required />
          </div>

          <div>
            <label class="form-label">委外产量 (台) *</label>
            <input v-model.number="form.outsourced_output" type="number" step="0.01" min="0" class="oee-input" style="width:100%;" placeholder="0.00" required />
          </div>

          <div>
            <label class="form-label">实际用时 (小时) *</label>
            <input v-model.number="form.actual_time" type="number" step="0.01" min="0.01" class="oee-input" style="width:100%;" placeholder="用时..." required />
          </div>

          <div>
            <label class="form-label">操作人</label>
            <input type="text" class="oee-input" :value="currentUserDisplayName" disabled style="background:#f8f9fa; width:100%;" />
          </div>

          <div style="grid-column: span 2;">
            <label class="form-label">备注说明</label>
            <textarea v-model="form.remarks" class="oee-input" rows="2" style="width:100%; height:auto;" placeholder="填写生产说明..."></textarea>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button @click="modalVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitSave">保存记录</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Odometer, TrendCharts, List, Calendar, Search, Plus, Delete, Edit, Download
} from '@element-plus/icons-vue';
import request from '@/api/request';

const currentTab = ref('dashboard');
const isAdmin = ref(false);
const allowedTeams = ref([]);
// ★ 可填写班组（小于等于可查看班组）
const editTeams = ref([]);
const selectedTeamForChart = ref('__all__');

// 看板状态
const overallAvgText = ref('-');
const prevAvgText = ref('-');
const trendDisplayText = ref('-');
const trendClass = ref('trend-flat');
const yearTrendLines = ref([]);
const trendCanvasRef = ref(null);
const yearCanvasRef = ref(null);
let trendChartInstance = null;
let yearChartInstance = null;

// 列表状态
const tableLoading = ref(false);
const recordList = ref([]);
const totalRecords = ref(0);
const curPage = ref(1);
const totalPages = ref(1);
const selectedIds = ref([]);
const allSelected = ref(false);

const filterTeam = ref('');
const filterStart = ref('');
const filterEnd = ref('');

// 弹窗
const modalVisible = ref(false);
const saving = ref(false);
const editingId = ref(null);
const form = reactive({
  team: '',
  stat_month: '',
  total_output: 0,
  outsourced_output: 0,
  actual_time: 0,
  remarks: ''
});

const currentUserDisplayName = computed(() => {
  const u = JSON.parse(localStorage.getItem('user') || '{}');
  return u.full_name || u.username || '当前用户';
});

const modalTitle = computed(() => editingId.value ? `✏️ 编辑班组效率记录 [${form.team}]` : '➕ 添加班组效率记录');

// 动态载入 Chart.js
const ensureChartJs = async () => {
  if (typeof window.Chart !== 'undefined') return true;
  const loadScript = (url) => new Promise((resolve) => {
    const s = document.createElement('script');
    s.src = url;
    s.onload = () => resolve(true);
    s.onerror = () => resolve(false);
    document.head.appendChild(s);
  });
  let loaded = await loadScript('https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js');
  if (!loaded) {
    loaded = await loadScript('https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js');
  }
  return loaded;
};

const switchTab = (tab) => {
  currentTab.value = tab;
  if (tab === 'dashboard') {
    loadDashboardData();
  } else {
    loadTable(1);
  }
};

const initModule = async () => {
  try {
    const res = await request.get('/oee/init');
    if (res.data) {
      allowedTeams.value = res.data.allowed_teams || [];
      editTeams.value = res.data.edit_teams || [];
      isAdmin.value = !!res.data.is_admin;
      selectedTeamForChart.value = allowedTeams.value.length > 0 ? (isAdmin.value ? '装置车间' : allowedTeams.value[0]) : '__all__';
      if (editTeams.value.length > 0) {
        form.team = editTeams.value[0];
      }
    }
  } catch (e) {}
  loadDashboardData();
};

const loadDashboardData = async () => {
  try {
    const res = await request.get('/oee/dashboard_data', {
      params: { team: selectedTeamForChart.value }
    });
    if (res.data) {
      const d = res.data;
      overallAvgText.value = Number(d.overall_avg || 0).toFixed(2);
      prevAvgText.value = Number(d.prev_avg || 0).toFixed(2);

      if (d.trend_direction === '增长') {
        trendClass.value = 'trend-up';
        trendDisplayText.value = `↑ 增长 ${d.trend_percent}%`;
      } else if (d.trend_direction === '降低') {
        trendClass.value = 'trend-down';
        trendDisplayText.value = `↓ 降低 ${d.trend_percent}%`;
      } else {
        trendClass.value = 'trend-flat';
        trendDisplayText.value = `- 持平`;
      }

      yearTrendLines.value = d.year_trend_lines || [];

      await ensureChartJs();
      renderTrendChart(d);
      renderYearChart(d);
    }
  } catch (e) {}
};

const renderTrendChart = (data) => {
  if (typeof window.Chart === 'undefined' || !trendCanvasRef.value) return;
  if (trendChartInstance) trendChartInstance.destroy();

  const ctx = trendCanvasRef.value.getContext('2d');
  const colors = ['#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4', '#46f0f0'];
  const datasets = [];

  const buildSegmentRule = (baseColor) => ({
    borderDash: (ctx) => (ctx.p0.skip || ctx.p1.skip) ? [5, 5] : undefined,
    borderColor: (ctx) => (ctx.p0.skip || ctx.p1.skip) ? 'rgba(150, 150, 150, 0.45)' : baseColor
  });

  if (data.selected_team === '__all__') {
    (data.teams || []).forEach((t, idx) => {
      const color = colors[idx % colors.length];
      datasets.push({
        label: t,
        data: data.chart_data?.[t] || [],
        borderColor: color,
        backgroundColor: 'transparent',
        borderWidth: 2,
        tension: 0.15,
        spanGaps: true,
        segment: buildSegmentRule(color)
      });
    });
  } else {
    const t = data.selected_team;
    const avg = data.team_avg?.[t] || 0;
    datasets.push({
      label: t,
      data: data.chart_data?.[t] || [],
      borderColor: '#409EFF',
      backgroundColor: 'transparent',
      borderWidth: 3,
      tension: 0.15,
      spanGaps: true,
      segment: buildSegmentRule('#409EFF')
    });
    datasets.push({
      label: `平均值参考线 (${avg.toFixed(2)})`,
      data: (data.months || []).map(() => avg),
      borderColor: '#F56C6C',
      borderDash: [6, 4],
      pointRadius: 0,
      borderWidth: 2,
      fill: false
    });
  }

  trendChartInstance = new window.Chart(ctx, {
    type: 'line',
    data: { labels: data.months || [], datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'top', labels: { boxWidth: 12 } } },
      scales: {
        x: { title: { display: true, text: '统计月份' } },
        y: { title: { display: true, text: '效率 (产出/工时 × 系数)' }, beginAtZero: true }
      }
    }
  });
};

const renderYearChart = (data) => {
  if (typeof window.Chart === 'undefined' || !yearCanvasRef.value) return;
  if (yearChartInstance) yearChartInstance.destroy();

  const ctx = yearCanvasRef.value.getContext('2d');
  const yearColors = ['#3498db', '#e67e22', '#9b59b6', '#1abc9c', '#e74c3c', '#2ecc71'];
  const datasets = (data.all_years || []).map((y, idx) => {
    const color = yearColors[idx % yearColors.length];
    return {
      label: y + '年',
      data: data.year_chart_data?.[y] || [],
      borderColor: color,
      backgroundColor: 'transparent',
      borderWidth: 2,
      tension: 0.15,
      spanGaps: true,
      segment: {
        borderDash: (ctx) => (ctx.p0.skip || ctx.p1.skip) ? [5, 5] : undefined,
        borderColor: (ctx) => (ctx.p0.skip || ctx.p1.skip) ? 'rgba(160, 160, 160, 0.45)' : color
      }
    };
  });

  yearChartInstance = new window.Chart(ctx, {
    type: 'line',
    data: { labels: data.year_chart_labels || [], datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'top', labels: { boxWidth: 12 } } },
      scales: {
        x: { title: { display: true, text: '月份' } },
        y: { title: { display: true, text: '效率' }, beginAtZero: true }
      }
    }
  });
};

const loadTable = async (p = 1) => {
  curPage.value = p;
  tableLoading.value = true;
  try {
    const res = await request.get('/oee/list', {
      params: {
        page: p,
        filter_team: filterTeam.value,
        filter_start: filterStart.value,
        filter_end: filterEnd.value
      }
    });
    if (res.data) {
      recordList.value = res.data.records || [];
      totalRecords.value = res.data.total_records || 0;
      totalPages.value = res.data.total_pages || 1;
      isAdmin.value = !!res.data.is_admin;
      if (Array.isArray(res.data.edit_teams)) editTeams.value = res.data.edit_teams;
      selectedIds.value = [];
      allSelected.value = false;
    }
  } finally {
    tableLoading.value = false;
  }
};

const resetFilter = () => {
  filterTeam.value = '';
  filterStart.value = '';
  filterEnd.value = '';
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
  const monthStr = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  Object.assign(form, {
    team: editTeams.value[0] || allowedTeams.value[0] || '装置装配',
    stat_month: monthStr,
    total_output: 0,
    outsourced_output: 0,
    actual_time: 0,
    remarks: ''
  });
  modalVisible.value = true;
};

const openEditModal = (r) => {
  editingId.value = r.id;
  Object.assign(form, {
    team: r.team,
    stat_month: r.stat_month,
    total_output: Number(r.total_output),
    outsourced_output: Number(r.outsourced_output),
    actual_time: Number(r.actual_time),
    remarks: r.remarks || ''
  });
  modalVisible.value = true;
};

const submitSave = async () => {
  if (!form.team || !form.stat_month || form.actual_time <= 0) {
    return ElMessage.warning('班组、月份及实际用时为必填项且用时必须大于0！');
  }

  saving.value = true;
  try {
    const act = editingId.value ? '/oee/update' : '/oee/add';
    const res = await request.post(act, {
      id: editingId.value,
      ...form
    });
    ElMessage.success(res.message || '保存成功');
    modalVisible.value = false;
    if (currentTab.value === 'dashboard') loadDashboardData();
    else loadTable(curPage.value);
  } finally {
    saving.value = false;
  }
};

const deleteSingle = (id) => {
  ElMessageBox.confirm('确定要删除该条效率记录吗？', '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/oee/delete', { ids: [id] });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const batchDelete = () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请勾选记录！');
  ElMessageBox.confirm(`确定要永久删除选中的 ${selectedIds.value.length} 条效率记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/oee/delete', { ids: selectedIds.value });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const exportCsv = () => {
  const t = filterTeam.value;
  const s = filterStart.value;
  const e = filterEnd.value;
  window.open(`/api/oee/export?filter_team=${encodeURIComponent(t)}&filter_start=${encodeURIComponent(s)}&filter_end=${encodeURIComponent(e)}`, '_blank');
};

onMounted(() => {
  initModule();
});
</script>

<style scoped>
.oee-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.oee-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.oee-card-header {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.oee-tabs {
  display: flex;
  gap: 10px;
  margin-bottom: 12px;
  border-bottom: 2px solid #dee2e6;
  flex-wrap: wrap;
}
.oee-tab-btn {
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
.oee-tab-btn.active {
  color: #007bff;
  border-bottom-color: #007bff;
}

.oee-stat-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f0f7ff;
  border: 1px solid #b3d9ff;
  color: #2c3e50;
  border-radius: 6px;
  padding: 12px 18px;
  margin-bottom: 12px;
  flex-wrap: wrap;
  gap: 12px;
}
.oee-stat-number {
  font-size: 22px;
  font-weight: bold;
  line-height: 1.1;
  margin-top: 2px;
}

.trend-up { color: #28a745; }
.trend-down { color: #dc3545; }
.trend-flat { color: #f6c23e; }

.year-trend-box {
  background: #fffbe6;
  border: 1px solid #ffe58f;
  border-radius: 4px;
  padding: 10px 14px;
  font-size: 12.5px;
  color: #595959;
}

.oee-filter-card {
  padding: 8px 12px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}
.oee-batch-bar {
  background: #e0f2fe;
  padding: 6px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #bae6fd;
  font-size: 12.5px;
}

.oee-table-wrapper {
  width: 100% !important;
  max-width: 100% !important;
  overflow-x: hidden !important;
  overflow-y: auto;
  max-height: calc(100vh - 350px);
  min-height: 280px;
  box-sizing: border-box;
}
.oee-fixed-table {
  width: 100% !important;
  max-width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed !important;
  font-size: 12px;
  color: #333;
  margin-bottom: 0 !important;
}
.oee-fixed-table thead th {
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
.oee-fixed-table tbody td {
  padding: 6px 4px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
}
.oee-fixed-table tbody tr:hover { background: #f8fafc; }
.oee-fixed-table tbody tr.selected { background: #e0f2fe !important; }

.col-cb { width: 34px !important; text-align: center !important; }
.col-team { width: 85px !important; text-align: center !important; font-weight: 600; color: #2c3e50; }
.col-month { width: 80px !important; text-align: center !important; }
.col-num { width: 75px !important; text-align: right !important; }
.col-eff { width: 70px !important; text-align: right !important; font-weight: bold; color: #28a745; }
.col-user { width: 90px !important; text-align: center !important; }
.col-action { width: 75px !important; text-align: center !important; }
.col-remarks { width: 80px !important; text-align: left !important; }

.badge-tag { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 500; }
.badge-tag-proc { background: #e8f4fd; color: #007bff; }

.oee-pagination-bar {
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

.oee-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.oee-input:focus { border-color: #80bdff; }

.oee-select {
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