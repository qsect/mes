<template>
  <div class="prog-container">
    <!-- 顶部标题卡片 -->
    <div class="prog-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Odometer /></el-icon>
          工单进度与 SN 综合查询
        </span>
      </div>
    </div>

    <!-- 检索输入卡片 -->
    <div class="progress-search-card">
      <h3 style="color:#2c3e50; font-size:16px; margin:0 0 12px 0; display:flex; align-items:center; gap:8px;">
        <el-icon style="color:#409EFF;"><Search /></el-icon> 综合进度快速查询
      </h3>
      <div style="display:flex; gap:12px; flex-wrap:wrap;">
        <input
          v-model="inputQuery"
          type="text"
          class="prog-input"
          style="flex:1; min-width:280px; font-size:14.5px;"
          placeholder="请输入 22 位 SN 或工单号查询..."
          autofocus
          autocomplete="off"
          @keyup.enter="handleQuery"
        />
        <button type="button" class="btn-primary" :disabled="loading" style="padding:0 24px; height:38px; font-size:14px;" @click="handleQuery">
          <el-icon class="mr-1"><Search /></el-icon> {{ loading ? '查询中...' : '查 询' }}
        </button>
      </div>
    </div>

    <!-- 查询结果展示区 -->
    <div v-loading="loading">
      <!-- 初始未查询占位 -->
      <div v-if="!hasQueried && !queryResult" class="prog-placeholder">
        <el-icon style="font-size:46px; margin-bottom:12px; color:#ccd1d9;"><Tickets /></el-icon>
        <p style="margin:0; font-size:14.5px; color:#888;">请输入 SN 或 工单号 开始检索生产流转进度</p>
      </div>

      <!-- 结果内容 -->
      <div v-if="queryResult">
        <!-- 1. 工单基本信息 -->
        <div v-if="queryResult.task_info" class="prog-card">
          <div class="prog-card-header">
            <el-icon style="color:#409EFF; margin-right:4px;"><Document /></el-icon>
            <strong>工单基本信息</strong>
          </div>
          <div style="padding:14px 18px;">
            <div class="progress-info-grid">
              <div class="progress-info-item">
                <span class="progress-info-label">工单号</span>
                <span class="progress-info-val">{{ queryResult.task_info.task_no }}</span>
              </div>
              <div class="progress-info-item">
                <span class="progress-info-label">物料编码</span>
                <span class="progress-info-val">{{ queryResult.task_info.mater_no }}</span>
              </div>
              <div class="progress-info-item" style="grid-column: span 2;">
                <span class="progress-info-label">物料描述</span>
                <span class="progress-info-val">{{ queryResult.task_info.mater_desc || '-' }}</span>
              </div>
              <div class="progress-info-item">
                <span class="progress-info-label">工单计划总数</span>
                <span class="progress-info-val" style="color:#409EFF; font-weight:bold;">
                  {{ queryResult.total_sn_count || queryResult.task_info.task_number || 0 }}
                </span>
              </div>
              <div v-if="queryResult.task_info.plan_start" class="progress-info-item">
                <span class="progress-info-label">计划开始</span>
                <span class="progress-info-val" style="font-size:13.5px;">{{ queryResult.task_info.plan_start }}</span>
              </div>
              <div v-if="queryResult.task_info.plan_end" class="progress-info-item">
                <span class="progress-info-label">计划结束</span>
                <span class="progress-info-val" style="font-size:13.5px;">{{ queryResult.task_info.plan_end }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- 2. SN 当前状态 (仅当查询输入为 SN 时显示) -->
        <div v-if="queryResult.is_sn && queryResult.sn_current" class="prog-card" style="border-left: 4px solid #409EFF;">
          <div class="prog-card-header">
            <el-icon style="color:#409EFF; margin-right:4px;"><Tickets /></el-icon>
            <strong>SN 当前生产状态 [ {{ queryResult.sn_current.sn }} ]</strong>
          </div>
          <div style="padding:14px 18px;">
            <div class="progress-info-grid">
              <div class="progress-info-item">
                <span class="progress-info-label">当前工序</span>
                <span class="progress-info-val">{{ queryResult.sn_current.gx_name || '未知' }}</span>
              </div>
              <div class="progress-info-item">
                <span class="progress-info-label">当前节点</span>
                <span class="progress-info-val">{{ queryResult.sn_current.node_name || '未知' }}</span>
              </div>
              <div class="progress-info-item">
                <span class="progress-info-label">作业中心</span>
                <span class="progress-info-val">{{ queryResult.sn_current.zyzx_name || '未知' }}</span>
              </div>
              <div class="progress-info-item">
                <span class="progress-info-label">更新时间</span>
                <span class="progress-info-val" style="font-size:13px;">{{ queryResult.sn_current.update_time || '-' }}</span>
              </div>
              <div class="progress-info-item">
                <span class="progress-info-label">当前状态</span>
                <span class="progress-info-val">
                  <span class="badge" :style="getEnStatusStyle(queryResult.sn_current.en)">
                    {{ getEnStatusText(queryResult.sn_current.en) }}
                  </span>
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- 3. SN 工序历程明细 -->
        <div v-if="queryResult.is_sn && queryResult.sn_history && queryResult.sn_history.length > 0" class="prog-card">
          <div class="prog-card-header">
            <el-icon style="color:#67C23A; margin-right:4px;"><List /></el-icon>
            <strong>SN 工序历程明细</strong>
          </div>
          <div style="overflow-x:auto;">
            <table class="prog-table">
              <thead>
                <tr>
                  <th style="width: 25%;">工序</th>
                  <th style="width: 30%;">步骤名称</th>
                  <th style="width: 15%;">结果</th>
                  <th style="width: 30%;">完成时间</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(h, idx) in queryResult.sn_history" :key="idx">
                  <td><strong>{{ h.gx_name || h.gx_id }}</strong></td>
                  <td>{{ h.gy_name || '-' }}</td>
                  <td>
                    <span v-if="h.result == 1" class="badge" style="background:#d4edda; color:#155724;">成功</span>
                    <span v-else-if="h.result == 0" class="badge" style="background:#f8d7da; color:#721c24;">失败</span>
                    <span v-else class="badge" style="background:#e2e3e5; color:#383d41;">未知</span>
                  </td>
                  <td>{{ h.last_time || '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- 4. 工单各工序完成进度汇总 -->
        <div v-if="queryResult.work_order_progress && queryResult.work_order_progress.length > 0" class="prog-card">
          <div class="prog-card-header">
            <el-icon style="color:#E6A23C; margin-right:4px;"><Odometer /></el-icon>
            <strong>工单各工序完成进度</strong>
          </div>
          <div style="overflow-x:auto;">
            <table class="prog-table">
              <thead>
                <tr>
                  <th style="width: 30%;">工序名称</th>
                  <th style="width: 25%;">已完成 / 工单总数</th>
                  <th style="width: 45%;">完成比例</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(p, idx) in queryResult.work_order_progress" :key="idx">
                  <td><strong>{{ p.gx_name }}</strong></td>
                  <td>{{ p.completed_count }} / {{ queryResult.total_sn_count }}</td>
                  <td>
                    <div class="pg-bar-wrapper">
                      <div class="pg-bar-bg">
                        <div class="pg-bar-fill" :style="{ width: calcPercent(p.completed_count, queryResult.total_sn_count) + '%' }">
                          {{ calcPercent(p.completed_count, queryResult.total_sn_count) }}%
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { ElMessage } from 'element-plus';
import {
  Search, Odometer, List, Document, Tickets
} from '@element-plus/icons-vue';
import request from '@/api/request';

const inputQuery = ref('');
const loading = ref(false);
const hasQueried = ref(false);
const queryResult = ref(null);

const getEnStatusText = (en) => {
  const map = { 0: '已导入', 1: '进行中', 2: '已完成' };
  return map[en] || '未知';
};

const getEnStatusStyle = (en) => {
  if (en == 2) return { background: '#d4edda', color: '#155724' };
  if (en == 1) return { background: '#fff3cd', color: '#856404' };
  if (en == 0) return { background: '#d1ecf1', color: '#0c5460' };
  return { background: '#e2e3e5', color: '#383d41' };
};

const calcPercent = (completed, total) => {
  const c = parseInt(completed) || 0;
  const t = parseInt(total) || 0;
  if (t <= 0) return 0;
  return Math.min(100, Math.round((c / t) * 100));
};

const handleQuery = async () => {
  const q = inputQuery.value.trim();
  if (!q) {
    ElMessage.warning('请输入 SN 或工单号');
    return;
  }

  loading.value = true;
  hasQueried.value = true;
  try {
    const res = await request.post('/progress/query', { q });
    if (res.data) {
      queryResult.value = res.data;
      ElMessage.success('数据检索成功');
    }
  } catch (err) {
    queryResult.value = null;
  } finally {
    loading.value = false;
  }
};
</script>

<style scoped>
.prog-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.prog-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.prog-card-header {
  padding: 10px 16px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: flex;
  align-items: center;
  font-size: 14.5px;
  color: #2c3e50;
}

.progress-search-card {
  background: white;
  border-radius: 8px;
  border: 1px solid #e3e6f0;
  padding: 18px 22px;
  margin-bottom: 14px;
}

.prog-placeholder {
  text-align: center;
  padding: 55px 20px;
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
}

.progress-info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 12px;
}
.progress-info-item {
  display: flex;
  flex-direction: column;
  background: #f8f9fc;
  padding: 10px 14px;
  border-radius: 6px;
  border: 1px solid #e3e6f0;
}
.progress-info-label {
  font-size: 12px;
  color: #888;
  margin-bottom: 4px;
  font-weight: 500;
}
.progress-info-val {
  font-size: 15px;
  font-weight: 600;
  color: #2c3e50;
  word-break: break-all;
}

.prog-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  text-align: left;
}
.prog-table th {
  background: #f8f9fa;
  padding: 9px 14px;
  border-bottom: 2px solid #dee2e6;
  font-weight: 600;
  color: #2c3e50;
  white-space: nowrap;
}
.prog-table td {
  padding: 8px 14px;
  border-bottom: 1px solid #eee;
  vertical-align: middle;
}
.prog-table tr:hover { background-color: #f8fbfe; }

.badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 11.5px;
  font-weight: 500;
}

.pg-bar-wrapper {
  display: flex;
  align-items: center;
  gap: 12px;
}
.pg-bar-bg {
  flex: 1;
  height: 18px;
  background: #e9ecef;
  border-radius: 10px;
  overflow: hidden;
  min-width: 120px;
}
.pg-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, #3498db, #2980b9);
  border-radius: 10px;
  text-align: center;
  line-height: 18px;
  color: #fff;
  font-size: 11px;
  font-weight: bold;
  transition: width 0.4s ease;
}

.prog-input {
  height: 38px;
  font-size: 13.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 12px;
  outline: none;
  box-sizing: border-box;
}
.prog-input:focus { border-color: #80bdff; }

.btn-primary {
  background: #007bff;
  color: #fff;
  border: 1px solid #007bff;
  border-radius: 4px;
  padding: 6px 16px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}
.btn-primary:hover { background: #0069d9; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.mr-1 { margin-right: 4px; }
</style>