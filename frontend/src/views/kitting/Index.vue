<template>
  <div class="kit-container">
    <!-- 顶部标题卡片 -->
    <div class="kit-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Files /></el-icon>
          工单齐套查询
        </span>
      </div>
    </div>

    <!-- 搜索查询栏 -->
    <div class="kit-search-card">
      <form class="kit-search-row" @submit.prevent="handleQuery">
        <label style="font-weight:600; color:#1e293b; display:flex; align-items:center; gap:6px; margin-bottom:0; font-size:13px;">
          <el-icon style="color:#409EFF;"><Search /></el-icon> 工单号 / 任务号:
        </label>
        <input
          v-model="workOrdersInput"
          type="text"
          class="kit-input"
          style="width: 380px; font-family: monospace;"
          placeholder="输入工单号/任务号 (支持多个，空格或逗号分隔)..."
          autofocus
          autocomplete="off"
        />
        <button type="submit" class="btn-primary" :disabled="loading" style="min-width:95px;">
          <el-icon class="mr-1"><Search /></el-icon>
          {{ loading ? '查询中...' : '开始查询' }}
        </button>
      </form>
    </div>

    <!-- 查询结果区域 -->
    <div v-show="hasQueried">
      <!-- 1. 工序齐套概览 -->
      <div class="kit-sum-card">
        <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:bold; color:#0f172a;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:4px;"><PieChart /></el-icon>
          工序齐套状态概览 (点击表格行可快捷联动筛选)：
        </h4>
        <table class="kit-sum-table">
          <thead>
            <tr>
              <th>工单号</th>
              <th>工序名称</th>
              <th>物料种类数</th>
              <th>已齐套种类</th>
              <th>不齐套种类</th>
              <th>未发放种类</th>
              <th>工序齐套率</th>
              <th>工序齐套状态</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in summaryList"
              :key="item.wo + '___' + item.process"
              class="quick-sum-row"
              :title="'点击快捷联动筛选【' + item.process + '】'"
              @click="quickFilterProcess(item.process)"
            >
              <td><strong>{{ item.wo }}</strong></td>
              <td><strong>{{ item.process }}</strong></td>
              <td>{{ item.total_mat }}</td>
              <td style="color:#155724; font-weight:bold;">{{ item.kitted }}</td>
              <td style="color:#721c24; font-weight:bold;">{{ item.unkitted }}</td>
              <td style="color:#64748b;">{{ item.unissued }}</td>
              <td><strong>{{ item.rate }}%</strong></td>
              <td>
                <span v-if="item.state_badge === 'kitted'" class="kit-badge kit-badge-kitted">✅ 齐套</span>
                <span v-else-if="item.state_badge === 'unissued'" class="kit-badge kit-badge-unissued">⚪ 未发放</span>
                <span v-else class="kit-badge kit-badge-unkitted">❌ 不齐套</span>
              </td>
            </tr>
            <tr v-if="summaryList.length === 0">
              <td colspan="8" style="padding:20px; color:#888;">暂无概览数据</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 2. 工序筛选与状态色标 -->
      <div class="kit-filter-bar">
        <div style="display:flex; align-items:center; gap:8px;">
          <label style="font-weight:600; margin-bottom:0; font-size:13px; color:#334155;">
            <el-icon style="color:#409EFF; vertical-align:middle;"><CollectionTag /></el-icon> 筛选工序名称:
          </label>
          <select v-model="selectedProcess" class="kit-select" style="width:230px;" @change="onProcessFilterChange">
            <option value="">-- 全部工序 (共 {{ allMaterials.length }} 项物料) --</option>
            <option v-for="p in processOptions" :key="p" :value="p">{{ p }}</option>
          </select>
          <span v-if="filterCountText" style="font-size:12px; color:#409EFF; font-weight:600;">
            {{ filterCountText }}
          </span>
        </div>

        <div style="font-size:12px; display:flex; gap:14px; align-items:center;">
          <span style="font-weight:600;">状态图例:</span>
          <span><span class="legend-box" style="background:#d4edda; border-color:#86efac;"></span>齐套</span>
          <span><span class="legend-box" style="background:#f8d7da; border-color:#fca5a5;"></span>不齐套/缺料</span>
          <span><span class="legend-box" style="background:#ffffff; border-color:#cbd5e1;"></span>未发放</span>
        </div>
      </div>

      <!-- 3. 物料明细表格 -->
      <div class="kit-card">
        <div class="kit-table-wrapper" ref="tableWrapperRef" v-loading="loading">
          <table class="kit-wide-table">
            <thead>
              <tr>
                <th style="width:110px; text-align:center;">齐套状态</th>
                <th style="width:110px;">工序名称</th>
                <th v-for="c in resultColumns" :key="c">{{ c }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, idx) in displayedMaterials"
                :key="idx"
                :class="'row-' + row._calc_state"
              >
                <td style="text-align:center;">
                  <span v-if="row._calc_state === 'kitted'" class="kit-badge kit-badge-kitted">✅ 齐套</span>
                  <span v-else-if="row._calc_state === 'unkitted'" class="kit-badge kit-badge-unkitted">
                    ❌ 缺料 {{ row._diff_qty > 0 ? `(差 ${row._diff_qty})` : '' }}
                  </span>
                  <span v-else class="kit-badge kit-badge-unissued">⚪ 未发放</span>
                </td>
                <td><strong>{{ row._calc_process }}</strong></td>
                <td v-for="c in resultColumns" :key="c">
                  {{ row[c] !== null && row[c] !== undefined ? row[c] : '' }}
                </td>
              </tr>
              <tr v-if="displayedMaterials.length === 0">
                <td :colspan="resultColumns.length + 2" style="text-align:center; padding:30px; color:#888;">
                  暂无物料组件记录
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { ElMessage } from 'element-plus';
import {
  Files, Search, PieChart, CollectionTag
} from '@element-plus/icons-vue';
import request from '@/api/request';

const workOrdersInput = ref('');
const loading = ref(false);
const hasQueried = ref(false);

const allMaterials = ref([]);
const resultColumns = ref([]);
const summaryList = ref([]);
const processOptions = ref([]);
const selectedProcess = ref('');

const tableWrapperRef = ref(null);

const filterCountText = computed(() => {
  if (!selectedProcess.value) return '';
  const cnt = displayedMaterials.value.length;
  return `【${selectedProcess.value}】共 ${cnt} 项物料`;
});

const displayedMaterials = computed(() => {
  if (!selectedProcess.value) {
    return allMaterials.value;
  }
  return allMaterials.value.filter(m => m._calc_process === selectedProcess.value);
});

const handleQuery = async () => {
  const woVal = workOrdersInput.value.trim();
  if (!woVal) {
    return ElMessage.warning('请输入工单号/任务号！');
  }

  loading.value = true;
  try {
    const res = await request.post('/kitting/query', { work_orders: woVal });
    if (res.data) {
      allMaterials.value = res.data.materials || [];
      resultColumns.value = res.data.columns || [];
      summaryList.value = res.data.summary_list || [];
      processOptions.value = res.data.processes || [];
      selectedProcess.value = '';
      hasQueried.value = true;
      ElMessage.success(`查询成功，共加载 ${allMaterials.value.length} 项物料组件！`);
    }
  } finally {
    loading.value = false;
  }
};

const quickFilterProcess = (proc) => {
  selectedProcess.value = proc;
  tableWrapperRef.value?.scrollIntoView({ behavior: 'smooth' });
};

const onProcessFilterChange = () => {
  // 联动刷新展示列表
};
</script>

<style scoped>
.kit-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.kit-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}

.kit-search-card {
  background: white;
  border-radius: 8px;
  border: 1px solid #e3e6f0;
  padding: 14px 20px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}

.kit-search-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-start;
  gap: 10px;
}

.kit-badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: bold;
  white-space: nowrap;
}
.kit-badge-kitted {
  background: #dcfce7;
  color: #166534;
  border: 1px solid #86efac;
}
.kit-badge-unkitted {
  background: #fee2e2;
  color: #991b1b;
  border: 1px solid #fca5a5;
}
.kit-badge-unissued {
  background: #f1f5f9;
  color: #475569;
  border: 1px solid #cbd5e1;
}

/* 概览表格 */
.kit-sum-card {
  background: white;
  border: 1px solid #e3e6f0;
  border-radius: 8px;
  padding: 14px 18px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}
.kit-sum-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.kit-sum-table th {
  background: #f8fafc;
  padding: 8px 12px;
  border: 1px solid #e2e8f0;
  text-align: center;
  color: #475569;
}
.kit-sum-table td {
  padding: 8px 12px;
  border: 1px solid #e2e8f0;
  text-align: center;
}
.kit-sum-table tr.quick-sum-row {
  cursor: pointer;
  transition: background-color 0.2s;
}
.kit-sum-table tr.quick-sum-row:hover {
  background-color: #f1f5f9;
}

/* 筛选工具栏 */
.kit-filter-bar {
  background: white;
  padding: 10px 18px;
  border-radius: 6px;
  border: 1px solid #e3e6f0;
  margin-bottom: 12px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.legend-box {
  display: inline-block;
  width: 12px;
  height: 12px;
  border: 1px solid #ccc;
  vertical-align: middle;
  margin-right: 4px;
}

/* 物料明细表格 */
.kit-table-wrapper {
  max-height: calc(100vh - 300px);
  min-height: 280px;
  overflow-y: auto;
  overflow-x: auto;
  border-top: 1px solid #dee2e6;
}
.kit-wide-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 12px;
  text-align: left;
  white-space: nowrap;
}
.kit-wide-table thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 10;
  padding: 9px 12px;
  border-bottom: 2px solid #dee2e6;
  border-right: 1px solid #dee2e6;
  font-weight: 600;
  color: #2c3e50;
}
.kit-wide-table tbody td {
  padding: 7px 12px;
  border-bottom: 1px solid #e2e8f0;
  border-right: 1px solid #e2e8f0;
}

/* 行状态背景色 */
.kit-wide-table tr.row-kitted td {
  background-color: #d4edda !important;
  color: #155724 !important;
}
.kit-wide-table tr.row-unkitted td {
  background-color: #f8d7da !important;
  color: #721c24 !important;
}
.kit-wide-table tr.row-unissued td {
  background-color: #ffffff !important;
  color: #212529 !important;
}
.kit-wide-table tr:hover td {
  filter: brightness(0.96);
}

.kit-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.kit-input:focus { border-color: #80bdff; }

.kit-select {
  height: 33px;
  font-size: 13px;
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
  padding: 6px 14px;
  font-size: 12.5px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}
.btn-primary:hover { background: #0069d9; }
.mr-1 { margin-right: 4px; }
</style>