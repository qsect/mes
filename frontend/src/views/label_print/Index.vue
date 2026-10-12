<template>
  <div class="lp-container">
    <!-- 顶部标题卡片 -->
    <div class="lp-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Printer /></el-icon>
          标签打印管理系统
        </span>
      </div>
    </div>

    <!-- 顶部选项卡导航 -->
    <div class="lp-tabs">
      <button
        type="button"
        class="lp-tab-btn"
        :class="{ active: activeTab === 'print' }"
        @click="activeTab = 'print'"
      >
        <el-icon class="mr-1"><Printer /></el-icon> 标签打印
      </button>
      <button
        type="button"
        class="lp-tab-btn"
        :class="{ active: activeTab === 'history' }"
        @click="switchHistoryTab"
      >
        <el-icon class="mr-1"><Clock /></el-icon> 打印历史记录
      </button>
    </div>

    <!-- ================= 选项卡 1: 执行打印 ================= -->
    <div v-show="activeTab === 'print'">
      <div class="lp-search-box">
        <h3 style="margin-top:0; color:#2c3e50; font-size:18px; display:flex; align-items:center; gap:8px;">
          <el-icon style="color:#409EFF;"><Printer /></el-icon> 工单标签打印任务
        </h3>

        <form style="margin-top:15px;" @submit.prevent="handleQueryOrder">
          <div style="margin-bottom:15px;">
            <label style="font-weight:600; display:block; margin-bottom:6px; font-size:13px; color:#333;">
              工单号 (任务名称或编码) *
            </label>
            <div style="display:flex; gap:10px;">
              <input
                v-model="orderInput"
                type="text"
                class="lp-input"
                style="flex:1; font-size:14px;"
                placeholder="请输入工单号"
                :disabled="cooldownCount > 0"
                autofocus
                autocomplete="off"
              />
              <button
                type="submit"
                class="btn-primary"
                :disabled="querying || cooldownCount > 0"
                style="min-width:115px;"
              >
                <el-icon class="mr-1"><Search /></el-icon>
                {{ cooldownCount > 0 ? `就绪 (${cooldownCount}s)` : (querying ? '查询中...' : '查询工单') }}
              </button>
            </div>
          </div>
        </form>

        <div style="font-size:12px; color:#888; text-align:center;">
          ※ 系统将根据工单自动识别产品代码，并由打印服务器提供匹配模板选项。
        </div>

        <!-- 工单信息与模板选择区 -->
        <div v-if="orderData" style="margin-top:20px;">
          <div class="lp-order-info">
            <h4 style="margin-top:0; margin-bottom:12px; color:#409EFF; font-size:15px; display:flex; align-items:center; gap:4px;">
              <el-icon><InfoFilled /></el-icon> 工单信息检索成功
            </h4>
            <div class="lp-info-grid">
              <div><strong>工单号：</strong> <span style="font-weight:bold;">{{ orderData.order_number }}</span></div>
              <div><strong>产品代码：</strong> <span style="font-family:monospace;">{{ orderData.product_code }}</span></div>
              <div><strong>起始编号 (批号)：</strong> <span style="font-family:monospace; color:#67C23A; font-weight:bold;">{{ orderData.batch_number }}</span></div>
              <div><strong>打印数量：</strong> <span style="font-weight:bold; color:#409EFF;">{{ orderData.quantity }}</span> 份</div>
              <div style="grid-column: span 2;"><strong>物料描述：</strong> <span>{{ orderData.description || '-' }}</span></div>
            </div>
          </div>

          <form style="margin-top:20px;" @submit.prevent="executePrint(false)">
            <div style="font-weight:600; margin-bottom:10px; font-size:14px; color:#333;">
              请选择适用的标签模板：
            </div>

            <div v-if="orderData.templates && orderData.templates.length > 0">
              <label
                v-for="t in orderData.templates"
                :key="t.path"
                class="template-item"
                :class="{ active: selectedTemplatePath === t.path }"
              >
                <input
                  type="radio"
                  name="selected_tpl"
                  :value="t.path"
                  v-model="selectedTemplatePath"
                />
                <div style="font-weight:600; color:#333; margin-left:6px;">{{ t.name }}</div>
              </label>
            </div>
            <div v-else style="padding:15px; background:#fff3cd; color:#856404; border-radius:6px; font-size:13px;">
              ⚠️ 打印服务器未找到代码【{{ orderData.task_code }}】对应的标签模板文件，请检查打印机模板目录。
            </div>

            <div style="margin-top:20px; text-align:right;">
              <button
                type="submit"
                class="btn-success"
                :disabled="printing || !selectedTemplatePath || cooldownCount > 0"
                style="padding:10px 28px; font-size:15px;"
              >
                <el-icon class="mr-1"><Position /></el-icon>
                {{ printing ? '正在发送打印机...' : '开始打印' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- ================= 选项卡 2: 打印历史记录 ================= -->
    <div v-show="activeTab === 'history'">
      <div class="lp-card">
        <div class="lp-card-header">
          <span style="font-weight:600; font-size:13.5px; color:#2c3e50;">
            <el-icon style="color:#409EFF; vertical-align:middle; margin-right:4px;"><Clock /></el-icon>
            标签打印历史记录明细
          </span>
          <div style="display:flex; gap:8px;">
            <input
              v-model="histSearchKw"
              type="text"
              class="lp-input"
              style="width:240px;"
              placeholder="搜索工单、代码、操作人..."
              @keyup.enter="loadHistory(1)"
            />
            <button type="button" class="btn-primary btn-sm" @click="loadHistory(1)">
              <el-icon class="mr-1"><Search /></el-icon> 搜索
            </button>
          </div>
        </div>

        <div class="lp-table-wrapper" v-loading="histLoading">
          <table class="lp-table">
            <thead>
              <tr>
                <th style="width:110px;">工单号</th>
                <th style="width:110px;">物料代码</th>
                <th style="width:160px;">起始序列号</th>
                <th>模板文件</th>
                <th style="width:110px; text-align:right;">打印数量</th>
                <th style="width:100px;">操作者</th>
                <th style="width:150px;">打印时间</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in histList" :key="r.id">
                <td><strong>{{ r.order_number }}</strong></td>
                <td><span style="font-family:monospace;">{{ r.template_code }}</span></td>
                <td><span style="font-family:monospace; color:#67C23A; font-weight:bold;">{{ r.serial_number }}</span></td>
                <td :title="r.template_path">{{ extractFileName(r.template_path) }}</td>
                <td style="text-align:right;"><strong>{{ r.quantity }}</strong> 份</td>
                <td>{{ r.printed_by }}</td>
                <td><span style="font-size:12px; color:#666;">{{ r.printed_at }}</span></td>
              </tr>
              <tr v-if="histList.length === 0">
                <td colspan="7" style="text-align:center; padding:40px; color:#888;">暂无打印记录</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="lp-pagination-bar">
          <div style="color:#64748b;">共 <strong>{{ histTotal }}</strong> 条记录，第 <strong>{{ histPage }}</strong> / <strong>{{ histTotalPages }}</strong> 页</div>
          <el-pagination v-model:current-page="histPage" :page-size="30" :total="histTotal" layout="prev, pager, next" @current-change="loadHistory" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Printer, Search, Refresh, Position, Clock, InfoFilled
} from '@element-plus/icons-vue';
import request from '@/api/request';

const activeTab = ref('print');
const orderInput = ref('');
const querying = ref(false);
const printing = ref(false);
const orderData = ref(null);
const selectedTemplatePath = ref('');
const cooldownCount = ref(0);

// 历史记录
const histSearchKw = ref('');
const histList = ref([]);
const histTotal = ref(0);
const histPage = ref(1);
const histTotalPages = ref(1);
const histLoading = ref(false);

const extractFileName = (pathStr) => {
  if (!pathStr) return '-';
  const parts = pathStr.split(/[\\/]/);
  return parts[parts.length - 1];
};

const handleQueryOrder = async () => {
  const orderNo = orderInput.value.trim();
  if (!orderNo) return ElMessage.warning('请输入工单号');

  querying.value = true;
  try {
    const res = await request.post('/label_print/query_order', { order_number: orderNo });
    if (res.data) {
      orderData.value = res.data;
      if (orderData.value.templates && orderData.value.templates.length > 0) {
        selectedTemplatePath.value = orderData.value.templates[0].path;
      } else {
        selectedTemplatePath.value = '';
      }
    }
  } finally {
    querying.value = false;
  }
};

const startCooldown = (seconds = 3) => {
  cooldownCount.value = seconds;
  const timer = setInterval(() => {
    cooldownCount.value--;
    if (cooldownCount.value <= 0) {
      clearInterval(timer);
    }
  }, 1000);
};

const executePrint = async (force = false) => {
  if (!orderData.value || !selectedTemplatePath.value) {
    return ElMessage.warning('请选择一个适用的标签模板！');
  }

  printing.value = true;
  try {
    const res = await request.post('/label_print/execute_print', {
      order_number: orderData.value.order_number,
      template_path: selectedTemplatePath.value,
      product_code: orderData.value.product_code,
      start_serial: orderData.value.batch_number || orderData.value.order_number,
      quantity: orderData.value.quantity,
      force_print: force ? 1 : 0
    });

    if (res.data && res.data.need_confirm) {
      ElMessageBox.confirm(res.data.message || '该工单已打印过，是否覆盖重新打印？', '提示', { type: 'warning' }).then(() => {
        executePrint(true);
      });
      return;
    }

    ElMessage.success(res.message || '打印任务已成功发送！');
    orderData.value = null;
    orderInput.value = '';
    selectedTemplatePath.value = '';
    startCooldown(3); // 锁定3秒冷却，释放 BarTender 打印句柄
  } finally {
    printing.value = false;
  }
};

const switchHistoryTab = () => {
  activeTab.value = 'history';
  loadHistory(1);
};

const loadHistory = async (p = 1) => {
  histPage.value = p;
  histLoading.value = true;
  try {
    const res = await request.get('/label_print/list_history', {
      params: {
        page: p,
        search: histSearchKw.value.trim()
      }
    });
    if (res.data) {
      histList.value = res.data.records || [];
      histTotal.value = res.data.total_records || 0;
      histTotalPages.value = res.data.total_pages || 1;
    }
  } finally {
    histLoading.value = false;
  }
};
</script>

<style scoped>
.lp-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.lp-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.lp-card-header {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.lp-tabs {
  display: flex;
  gap: 10px;
  margin-bottom: 15px;
  border-bottom: 2px solid #e3e6f0;
}
.lp-tab-btn {
  padding: 9px 18px;
  border: none;
  background: transparent;
  font-size: 15px;
  font-weight: 600;
  color: #666;
  cursor: pointer;
  border-bottom: 3px solid transparent;
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
}
.lp-tab-btn.active {
  color: #007bff;
  border-bottom-color: #007bff;
}

.lp-search-box {
  max-width: 650px;
  margin: 0 auto;
  padding: 25px;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e3e6f0;
  box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.lp-order-info {
  background: #f8f9fa;
  border: 1px solid #e3e6f0;
  border-radius: 8px;
  padding: 18px;
  margin-top: 20px;
}
.lp-info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  font-size: 13.5px;
}

.template-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: #fff;
  border: 1px solid #dcdfe6;
  border-radius: 6px;
  margin-bottom: 8px;
  cursor: pointer;
  transition: all 0.2s;
}
.template-item:hover, .template-item.active {
  border-color: #007bff;
  background: #f0f7ff;
}

.lp-table-wrapper {
  width: 100%;
  overflow-x: auto;
  border-top: 1px solid #dee2e6;
}
.lp-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.lp-table th {
  background: #f8f9fa;
  padding: 9px 12px;
  border-bottom: 2px solid #dee2e6;
  color: #2c3e50;
  text-align: left;
}
.lp-table td {
  padding: 8px 12px;
  border-bottom: 1px solid #eee;
}
.lp-table tr:hover { background-color: #f8fafc; }

.lp-pagination-bar {
  padding: 8px 14px;
  background: #f8f9fa;
  border-top: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
}

.lp-input {
  height: 33px;
  font-size: 13px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.lp-input:focus { border-color: #80bdff; }

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
  justify-content: center;
}
.btn-primary:hover { background: #0069d9; }
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
  justify-content: center;
}
.btn-success:hover { background: #218838; }
.mr-1 { margin-right: 4px; }
</style>
