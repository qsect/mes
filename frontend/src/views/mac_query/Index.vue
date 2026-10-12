<template>
  <div class="mq-container">
    <!-- 顶部标题卡片 -->
    <div class="mq-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Search /></el-icon>
          MAC / ID 信息追溯查询
        </span>
      </div>
    </div>

    <!-- 搜索查询工具栏 (单行紧凑布局) -->
    <div class="mq-search-card">
      <div class="mq-search-row">
        <div class="mq-input-group" style="margin-right: 20px;">
          <label><el-icon style="color:#409EFF;"><Document /></el-icon> 装置 SN:</label>
          <input
            ref="snInputRef"
            v-model="snInput"
            type="text"
            class="mq-input"
            style="width: 230px;"
            placeholder="输入 SN 编码..."
            autofocus
            autocomplete="off"
            @keyup.enter="handleQuery(false)"
          />
        </div>

        <div class="mq-input-group" style="margin-right: 18px;">
          <label><el-icon style="color:#67C23A;"><Document /></el-icon> 工单号:</label>
          <input
            v-model="taskInput"
            type="text"
            class="mq-input"
            style="width: 170px;"
            placeholder="输入工单号..."
            autocomplete="off"
            @keyup.enter="handleQuery(false)"
          />
        </div>

        <div style="display:flex; gap:6px; align-items:center;">
          <button type="button" class="btn-primary" :disabled="loading" style="min-width:85px;" @click="handleQuery(false)">
            <el-icon class="mr-1"><Search /></el-icon> {{ loading ? '查询中...' : '查 询' }}
          </button>
          <button type="button" class="btn-outline" :disabled="loading" title="强制跳过缓存重新提取最新数据" style="white-space:nowrap;" @click="handleQuery(true)">
            <el-icon class="mr-1"><Refresh /></el-icon> 强制刷新
          </button>
          <button type="button" class="btn-success" :disabled="dataList.length === 0" style="white-space:nowrap;" @click="exportCsv">
            <el-icon class="mr-1"><Download /></el-icon> 导出 Excel
          </button>
        </div>

        <div style="margin-left: auto; display: flex; align-items: center;">
          <span style="color:#67C23A; font-weight:600; font-size:13px;">
            共 {{ dataList.length }} 条符合条件记录 {{ fromCache ? '(来自高速缓存)' : '' }}
          </span>
        </div>
      </div>
    </div>

    <!-- 查询数据表格 -->
    <div class="mq-card">
      <div class="mq-table-wrapper" v-loading="loading">
        <table class="mq-table">
          <thead>
            <tr>
              <th style="width:60px; text-align:center;">序号</th>
              <th style="width:230px;">装置 SN</th>
              <th>二维码信息 / MAC 地址</th>
              <th style="width:160px;">工单号</th>
              <th style="width:170px;">操作时间</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, idx) in dataList" :key="idx">
              <td style="text-align:center; font-weight:600; color:#888;">{{ idx + 1 }}</td>
              <td><strong style="font-family:monospace; color:#2c3e50;">{{ item.sn || '-' }}</strong></td>
              <td><span class="qr-badge">{{ item.qr_info || '-' }}</span></td>
              <td><span class="badge" style="background:#e8f4fd; color:#409EFF; font-family:monospace; padding:2px 6px; border-radius:4px;">{{ item.task_no || '-' }}</span></td>
              <td><span style="font-size:12px; color:#666;">{{ item.run_time || '-' }}</span></td>
            </tr>
            <tr v-if="dataList.length === 0 && !loading">
              <td colspan="5" style="text-align:center; padding:45px 20px; color:#888;">
                <p style="margin:0; font-size:14px;">请输入装置 SN 或工单号执行检索</p>
                <div v-if="hasSearched" style="font-size:12px; color:#aaa; margin-top:4px;">(未匹配到符合特征前缀的记录)</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, nextTick } from 'vue';
import { ElMessage } from 'element-plus';
import {
  Search, Refresh, Download, Document
} from '@element-plus/icons-vue';
import request from '@/api/request';

const snInput = ref('');
const taskInput = ref('');
const snInputRef = ref(null);
const loading = ref(false);
const fromCache = ref(false);
const hasSearched = ref(false);
const dataList = ref([]);
const lastSn = ref('');
const lastTask = ref('');

const handleQuery = async (force = false) => {
  const snVal = snInput.value.trim();
  const taskVal = taskInput.value.trim();

  if (!snVal && !taskVal) {
    ElMessage.warning('请至少输入【装置 SN】或【工单号】');
    snInputRef.value?.focus();
    return;
  }

  loading.value = true;
  try {
    const res = await request.post('/mac_query/query', {
      sn: snVal,
      task: taskVal,
      force: force ? 1 : 0
    });

    hasSearched.value = true;
    if (res.data) {
      dataList.value = res.data.list || [];
      fromCache.value = !!res.data.from_cache;
      lastSn.value = snVal;
      lastTask.value = taskVal;

      if (dataList.value.length === 0) {
        ElMessage.info(`未匹配到特征前缀记录 (MES 返回 ${res.data.raw_count || 0} 条原始数据)`);
      } else {
        ElMessage.success(`查询成功，共检索到 ${dataList.value.length} 条记录`);
      }

      snInput.value = '';
      taskInput.value = '';
      nextTick(() => snInputRef.value?.focus());
    }
  } finally {
    loading.value = false;
  }
};

const exportCsv = () => {
  if (dataList.value.length === 0) return;
  const qs = new URLSearchParams({
    sn: lastSn.value || '',
    task: lastTask.value || ''
  }).toString();
  window.open(`/api/mac_query/export?${qs}`, '_blank');
};
</script>

<style scoped>
.mq-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.mq-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}

.mq-search-card {
  background: white;
  border-radius: 8px;
  border: 1px solid #e3e6f0;
  padding: 14px 20px;
  margin-bottom: 12px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}

.mq-search-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-start;
  gap: 10px;
}

.mq-input-group {
  display: flex;
  align-items: center;
  gap: 8px;
}
.mq-input-group label {
  font-weight: 600;
  color: #2c3e50;
  font-size: 13px;
  margin-bottom: 0;
  white-space: nowrap;
}

.mq-table-wrapper {
  max-height: calc(100vh - 220px);
  min-height: 340px;
  overflow-y: auto;
  overflow-x: auto;
  border-top: 1px solid #dee2e6;
}
.mq-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 13px;
  text-align: left;
}
.mq-table thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 10;
  padding: 9px 14px;
  border-bottom: 2px solid #dee2e6;
  border-right: 1px solid #dee2e6;
  font-weight: 600;
  color: #2c3e50;
  white-space: nowrap;
}
.mq-table tbody td {
  padding: 8px 14px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  vertical-align: middle;
}
.mq-table tbody tr:hover {
  background-color: #f8fbfe;
}

.qr-badge {
  font-family: Consolas, "Courier New", monospace;
  background: #f1f6fd;
  border: 1px solid #d8e5f8;
  color: #0056b3;
  padding: 3px 8px;
  border-radius: 4px;
  font-size: 12px;
  display: inline-block;
  word-break: break-all;
}

.mq-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.mq-input:focus {
  border-color: #80bdff;
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

.btn-outline {
  background: #fff;
  color: #495057;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 6px 12px;
  font-size: 12.5px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}

.btn-success {
  background: #28a745;
  color: #fff;
  border: 1px solid #28a745;
  border-radius: 4px;
  padding: 6px 14px;
  font-size: 12.5px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}
.btn-success:hover { background: #218838; }

.btn-success:disabled, .btn-primary:disabled, .btn-outline:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.mr-1 { margin-right: 4px; }
</style>