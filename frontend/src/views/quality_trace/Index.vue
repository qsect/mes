<template>
  <div class="qt-container">
    <!-- 顶部标题卡片 -->
    <div class="qt-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Search /></el-icon>
          CEM 质量追溯查询系统
        </span>
      </div>
    </div>

    <!-- 搜索筛选卡片 -->
    <div class="qt-search-card">
      <div class="qt-form">
        <div>
          <label>任务号 / 工单号</label>
          <input
            v-model="form.task_id"
            type="text"
            class="qt-input"
            placeholder="例如：172072"
            autocomplete="off"
            @keyup.enter="handleQuery"
          />
        </div>
        <div>
          <label>起始时间</label>
          <input v-model="form.start" type="date" class="qt-input" />
        </div>
        <div>
          <label>终止时间</label>
          <input v-model="form.end" type="date" class="qt-input" />
        </div>
      </div>

      <div class="qt-btn-group">
        <button type="button" class="btn-primary" :disabled="loading" @click="handleQuery">
          <el-icon class="mr-1"><Search /></el-icon> {{ loading ? '查询中...' : '快速查询' }}
        </button>
        <button type="button" class="btn-success" @click="doExport('btnexcel')">
          <el-icon class="mr-1"><Download /></el-icon> 导出全部明细
        </button>
        <button type="button" class="btn-info" @click="doExport('Button2')">
          <el-icon class="mr-1"><Check /></el-icon> 导出已完整追溯
        </button>
        <button type="button" class="btn-info" @click="doExport('Button3')">
          <el-icon class="mr-1"><Warning /></el-icon> 导出不完整追溯
        </button>
        <button type="button" class="btn-warning" :disabled="loadingCcjl" @click="loadCcjl">
          <el-icon class="mr-1"><Setting /></el-icon> {{ loadingCcjl ? '加载中...' : '装置调检记录' }}
        </button>
      </div>
    </div>

    <!-- 结果展示区域 -->
    <div v-loading="loading">
      <!-- 初始未查询占位 -->
      <div v-if="!hasQueried && !htmlReport && tableRows.length === 0" class="qt-empty">
        <el-icon style="font-size:42px; display:block; margin-bottom:12px; color:#ccd1d9;"><Document /></el-icon>
        <p style="margin:0; font-size:14px; color:#888;">请输入任务号或时间范围后点击“快速查询”</p>
      </div>

      <!-- 调检记录报告展示 (HTML 模式) -->
      <div v-if="htmlReport" class="qt-card">
        <div class="qt-card-header">
          <strong>装置调检报告</strong>
          <button type="button" class="btn-outline btn-mini" style="margin-left:auto;" @click="htmlReport = ''">关闭报告</button>
        </div>
        <div style="padding:16px; overflow-x:auto;" v-safe-html="htmlReport"></div>
      </div>

      <!-- 主查询表格展示 -->
      <div v-if="!htmlReport && tableRows.length > 0" class="qt-card">
        <div class="qt-card-header">
          <span>查询结果（共 {{ tableRows.length }} 条，点击高亮条码可展开下级物料）</span>
        </div>
        <div style="overflow-x:auto;">
          <table class="qt-table">
            <thead v-if="tableHeaders.length > 0">
              <tr>
                <th v-for="(h, idx) in tableHeaders" :key="idx">{{ h }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(row, rIdx) in tableRows" :key="rIdx">
                <tr>
                  <td v-for="(text, cIdx) in row.cells" :key="cIdx">
                    <template v-if="row.barcodes && row.barcodes[String(cIdx)]">
                      <a
                        href="javascript:void(0)"
                        class="qt-barcode-link"
                        @click="toggleDetail(row.barcodes[String(cIdx)], rIdx)"
                      >
                        {{ text }}
                      </a>
                    </template>
                    <template v-else>
                      {{ text }}
                    </template>
                  </td>
                </tr>

                <!-- 展开的二级物料行 -->
                <tr v-if="expandedRowIdx === rIdx" class="qt-detail-row">
                  <td :colspan="tableHeaders.length || row.cells.length">
                    <div class="qt-detail-inner" v-loading="loadingDetail">
                      <div v-if="detailHtml" v-safe-html="detailHtml"></div>
                      <div v-else-if="!loadingDetail" style="color:#888;">无下级数据</div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="hasQueried && !htmlReport && tableRows.length === 0 && !loading" class="qt-empty">
        <p style="margin:0; font-size:14px; color:#888;">未查询到相关数据</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { ElMessage } from 'element-plus';
import {
  Search, Download, Check, Warning, Setting, Document
} from '@element-plus/icons-vue';
import request from '@/api/request';

const loading = ref(false);
const loadingCcjl = ref(false);
const loadingDetail = ref(false);
const hasQueried = ref(false);

const form = reactive({
  task_id: '',
  start: '',
  end: ''
});

const tableHeaders = ref([]);
const tableRows = ref([]);
const htmlReport = ref('');

const expandedRowIdx = ref(-1);
const detailHtml = ref('');

const validateParams = () => {
  if (!form.task_id.trim() && !form.start && !form.end) {
    ElMessage.warning('请至少输入任务号或时间范围');
    return false;
  }
  return true;
};

const handleQuery = async () => {
  if (!validateParams()) return;

  loading.value = true;
  hasQueried.value = true;
  htmlReport.value = '';
  expandedRowIdx.value = -1;

  try {
    const res = await request.post('/quality_trace/query', {
      task_id: form.task_id.trim(),
      start: form.start,
      end: form.end
    });

    if (res.data) {
      tableHeaders.value = res.data.headers || [];
      tableRows.value = res.data.rows || [];
      if (tableRows.value.length === 0) {
        ElMessage.info('未查询到相关追溯数据');
      } else {
        ElMessage.success(`检索成功，共 ${tableRows.value.length} 条记录`);
      }
    }
  } catch (err) {
    tableHeaders.value = [];
    tableRows.value = [];
  } finally {
    loading.value = false;
  }
};

const toggleDetail = async (barcode, rowIdx) => {
  if (expandedRowIdx.value === rowIdx) {
    expandedRowIdx.value = -1;
    detailHtml.value = '';
    return;
  }

  expandedRowIdx.value = rowIdx;
  detailHtml.value = '';
  loadingDetail.value = true;

  try {
    const res = await request.post('/quality_trace/detail', { id: barcode });
    if (res.data) {
      detailHtml.value = res.data.html || '';
    }
  } catch (err) {
    detailHtml.value = '<span style="color:#F56C6C;">获取下级物料明细失败</span>';
  } finally {
    loadingDetail.value = false;
  }
};

const loadCcjl = async () => {
  if (!validateParams()) return;

  loadingCcjl.value = true;
  try {
    const res = await request.post('/quality_trace/ccjl', {
      task_id: form.task_id.trim(),
      start: form.start,
      end: form.end
    });

    if (res.data && res.data.html) {
      htmlReport.value = res.data.html;
      ElMessage.success('装置调检记录加载完成');
    }
  } finally {
    loadingCcjl.value = false;
  }
};

const doExport = (button) => {
  if (!validateParams()) return;

  const qs = new URLSearchParams({
    task_id: form.task_id.trim(),
    start: form.start,
    end: form.end,
    button: button
  }).toString();

  window.open(`/api/quality_trace/export?${qs}`, '_blank');
};
</script>

<style scoped>
.qt-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.qt-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.qt-card-header {
  padding: 10px 16px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: flex;
  align-items: center;
  font-size: 14.5px;
  color: #2c3e50;
  font-weight: 600;
}

.qt-search-card {
  background: white;
  border-radius: 8px;
  border: 1px solid #e3e6f0;
  padding: 18px 22px;
  margin-bottom: 14px;
}
.qt-form {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 15px;
  align-items: end;
}
.qt-form label {
  display: block;
  font-size: 13px;
  color: #555;
  margin-bottom: 5px;
  font-weight: 500;
}

.qt-input {
  width: 100%;
  height: 34px;
  padding: 0 10px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  font-size: 13.5px;
  box-sizing: border-box;
  outline: none;
}
.qt-input:focus { border-color: #80bdff; }

.qt-btn-group {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 15px;
}

.btn-primary {
  background: #007bff;
  color: #fff;
  border: 1px solid #007bff;
  border-radius: 4px;
  padding: 6px 16px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  font-size: 13px;
}
.btn-primary:hover { background: #0069d9; }

.btn-success {
  background: #28a745;
  color: #fff;
  border: 1px solid #28a745;
  border-radius: 4px;
  padding: 6px 16px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  font-size: 13px;
}
.btn-success:hover { background: #218838; }

.btn-info {
  background: #17a2b8;
  color: #fff;
  border: 1px solid #17a2b8;
  border-radius: 4px;
  padding: 6px 16px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  font-size: 13px;
}
.btn-info:hover { background: #138496; }

.btn-warning {
  background: #ffc107;
  color: #212529;
  border: 1px solid #ffc107;
  border-radius: 4px;
  padding: 6px 16px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  font-size: 13px;
}
.btn-warning:hover { background: #e0a800; }

.btn-outline {
  background: #fff;
  color: #495057;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 4px 10px;
  font-size: 12px;
  cursor: pointer;
}
.btn-mini { padding: 2px 7px; font-size: 11px; }

.qt-table {
  width: 100%;
  border-collapse: collapse;
  background: white;
  font-size: 13px;
}
.qt-table th, .qt-table td {
  padding: 9px 12px;
  border: 1px solid #e3e6f0;
  text-align: left;
}
.qt-table th {
  background: #507cd1;
  color: white;
  font-weight: 600;
  white-space: nowrap;
}
.qt-table tr:hover { background: #f8f9fc; }

.qt-barcode-link {
  color: #007bff;
  text-decoration: underline;
  cursor: pointer;
  font-weight: 500;
}
.qt-barcode-link:hover { color: #0056b3; }

.qt-empty {
  text-align: center;
  padding: 55px 20px;
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
}

.qt-detail-row td {
  background: #f0f8ff !important;
  padding: 14px !important;
}
.qt-detail-inner {
  max-height: 480px;
  overflow: auto;
}
:deep(.qt-detail-inner table) {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
  background: #fff;
}
:deep(.qt-detail-inner td), :deep(.qt-detail-inner th) {
  border: 1px solid #cfe2ff;
  padding: 6px 8px;
}
:deep(.qt-detail-inner th) {
  background: #e7f1ff;
  color: #0056b3;
}
.mr-1 { margin-right: 4px; }
</style>
