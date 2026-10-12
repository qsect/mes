<template>
  <div class="att-container">
    <!-- 顶部标题卡片 -->
    <div class="att-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Timer /></el-icon>
          人员离岗监控与统计
        </span>
      </div>
    </div>

    <!-- 选项卡导航 -->
    <div class="att-tabs">
      <button
        type="button"
        class="att-tab-btn"
        :class="{ active: activeTab === 'dashboard' }"
        @click="switchTab('dashboard')"
      >
        <el-icon class="mr-1"><Timer /></el-icon> 离岗实时看板
      </button>
      <button
        type="button"
        class="att-tab-btn"
        :class="{ active: activeTab === 'records' }"
        @click="switchTab('records')"
      >
        <el-icon class="mr-1"><List /></el-icon> 历史离岗明细
      </button>
      <button
        type="button"
        class="att-tab-btn"
        :class="{ active: activeTab === 'stats' }"
        @click="switchTab('stats')"
      >
        <el-icon class="mr-1"><DataLine /></el-icon> 员工离岗报表
      </button>
      <button
        type="button"
        class="att-tab-btn"
        :class="{ active: activeTab === 'emps' }"
        @click="switchTab('emps')"
      >
        <el-icon class="mr-1"><User /></el-icon> 员工档案管理
      </button>
      <button
        type="button"
        class="att-tab-btn"
        :class="{ active: activeTab === 'cards' }"
        @click="switchTab('cards')"
      >
        <el-icon class="mr-1"><CreditCard /></el-icon> IC卡片管理
      </button>
    </div>

    <!-- ================= 视图 1: 实时离岗看板 ================= -->
    <div v-show="activeTab === 'dashboard'">
      <!-- 统计指标网格 -->
      <div class="att-stats-grid">
        <div class="att-stat-item">
          <div>
            <div style="font-size:12px; color:#64748b; font-weight:500;">当前离岗人数</div>
            <div class="att-stat-val">{{ dashData.total_count }}</div>
          </div>
          <el-icon :size="32" style="color:#409EFF; opacity:0.8;"><Timer /></el-icon>
        </div>

        <div class="att-stat-item">
          <div>
            <div style="font-size:12px; color:#64748b; font-weight:500;">超时离岗 (≥30分钟)</div>
            <div class="att-stat-val" style="color:#F56C6C;">{{ dashData.long_count }}</div>
          </div>
          <el-icon :size="32" style="color:#F56C6C; opacity:0.8;"><Warning /></el-icon>
        </div>

        <div class="att-stat-item">
          <div>
            <div style="font-size:12px; color:#64748b; font-weight:500;">最长离岗时长</div>
            <div class="att-stat-val" style="font-size:20px; color:#E6A23C;">
              {{ formatMaxMinutes(dashData.max_minutes) }}
            </div>
          </div>
          <el-icon :size="32" style="color:#E6A23C; opacity:0.85;"><Timer /></el-icon>
        </div>

        <div class="att-stat-item">
          <div>
            <div style="font-size:12px; color:#64748b; font-weight:500;">可用空闲卡 / 在岗人员</div>
            <div class="att-stat-val" style="font-size:20px; color:#67C23A;">
              {{ dashData.free_cards }} 张 / {{ dashData.onjob_emps }} 人
            </div>
          </div>
          <el-icon :size="32" style="color:#67C23A; opacity:0.85;"><Check /></el-icon>
        </div>
      </div>

      <!-- 实时监控表格 -->
      <div class="att-card">
        <div class="att-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#409EFF; vertical-align:middle;"><Refresh /></el-icon>
            车间人员实时离岗动态 (30秒自动轮询刷新)
          </span>
          <div style="display:flex; gap:6px;">
            <button type="button" class="btn-success btn-sm" @click="openBindModal">
              <el-icon class="mr-1"><Postcard /></el-icon> 离岗发卡登记
            </button>
            <button type="button" class="btn-primary btn-sm" @click="loadDashboard">
              <el-icon class="mr-1"><Refresh /></el-icon> 手动刷新
            </button>
          </div>
        </div>
        <div class="att-table-wrapper" v-loading="dashLoading">
          <table class="att-fixed-table" style="min-width:750px;">
            <thead>
              <tr>
                <th>员工姓名</th>
                <th>工号</th>
                <th>IC卡UID</th>
                <th>所属班组</th>
                <th>离岗起始时间</th>
                <th>已离岗时长</th>
                <th>状态警示</th>
                <th style="width:120px; text-align:center;">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in dashList" :key="r.record_id">
                <td><strong>{{ r.name }}</strong></td>
                <td><span style="font-family:monospace;">{{ r.employee_no }}</span></td>
                <td><span style="font-family:monospace; color:#64748b;">{{ r.card_uid || '-' }}</span></td>
                <td><span class="badge" style="background:#e8f4fd; color:#409EFF; padding:2px 6px; border-radius:4px;">{{ r.team }}</span></td>
                <td>{{ r.start_time }}</td>
                <td>
                  <span :class="getTimeBadgeClass(r.level)">⏱️ {{ r.minutes }} 分钟</span>
                </td>
                <td>
                  <span :class="getTimeBadgeClass(r.level)">{{ getStateText(r.level) }}</span>
                </td>
                <td style="text-align:center;">
                  <button type="button" class="btn-primary btn-mini" @click="handleReturnCard(r.card_uid)">
                    <el-icon class="mr-1"><Check /></el-icon> 返岗还卡
                  </button>
                </td>
              </tr>
              <tr v-if="dashList.length === 0">
                <td colspan="8" style="text-align:center; padding:35px; color:#888;">
                  <el-icon color="#67C23A" style="vertical-align:middle; margin-right:4px;"><Check /></el-icon>
                  当前所辖人员全员在岗，暂无离岗人员
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ================= 视图 2: 历史离岗明细 ================= -->
    <div v-show="activeTab === 'records'">
      <div class="att-card">
        <div class="att-card-header" style="flex-wrap:wrap; gap:8px;">
          <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <input v-model="recFilterName" type="text" class="att-input" style="width:130px;" placeholder="姓名..." @keyup.enter="loadRecords(1)" />
            <input v-model="recFilterNo" type="text" class="att-input" style="width:130px;" placeholder="工号..." @keyup.enter="loadRecords(1)" />
            <input v-model="recFilterStart" type="date" class="att-input" style="width:145px;" />
            <span style="color:#888; font-size:12px;">至</span>
            <input v-model="recFilterEnd" type="date" class="att-input" style="width:145px;" />
            <button type="button" class="btn-primary btn-sm" @click="loadRecords(1)">
              <el-icon class="mr-1"><Search /></el-icon> 筛选
            </button>
            <button type="button" class="btn-outline btn-sm" @click="resetRecordsFilter">
              <el-icon class="mr-1"><Refresh /></el-icon> 重置
            </button>
          </div>
          <button type="button" class="btn-primary btn-sm" @click="exportRecords">
            <el-icon class="mr-1"><Download /></el-icon> 导出 Excel
          </button>
        </div>
        <div class="att-table-wrapper" v-loading="recLoading">
          <table class="att-fixed-table" style="min-width:850px;">
            <thead>
              <tr>
                <th>员工姓名</th>
                <th>工号</th>
                <th>所属班组</th>
                <th>IC卡UID</th>
                <th>离岗开始时间</th>
                <th>返岗结束时间</th>
                <th style="text-align:right;">离岗时长(分钟)</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in recordList" :key="r.id">
                <td><strong>{{ r.name }}</strong></td>
                <td><span style="font-family:monospace;">{{ r.employee_no }}</span></td>
                <td>{{ r.team }}</td>
                <td><span style="font-family:monospace; color:#64748b;">{{ r.card_uid || '已删除卡片' }}</span></td>
                <td>{{ r.start_time }}</td>
                <td>
                  <span v-if="r.end_time">{{ r.end_time }}</span>
                  <span v-else style="color:#F56C6C; font-weight:bold;">离岗中</span>
                </td>
                <td style="text-align:right; font-weight:bold;">{{ r.minutes }}</td>
              </tr>
              <tr v-if="recordList.length === 0">
                <td colspan="7" style="text-align:center; padding:30px; color:#888;">暂无历史记录</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="att-pagination-bar">
          <div style="color:#64748b;">共 <strong>{{ totalRecords }}</strong> 条记录，第 <strong>{{ curRecPage }}</strong> / <strong>{{ totalRecPages }}</strong> 页</div>
          <el-pagination v-model:current-page="curRecPage" :page-size="30" :total="totalRecords" layout="prev, pager, next" @current-change="loadRecords" />
        </div>
      </div>
    </div>

    <!-- ================= 视图 3: 员工离岗报表 ================= -->
    <div v-show="activeTab === 'stats'">
      <div class="att-card">
        <div class="att-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#409EFF; vertical-align:middle;"><DataLine /></el-icon>
            各员工离岗频次与累计时长报表
          </span>
        </div>
        <div class="att-table-wrapper" v-loading="statsLoading">
          <table class="att-fixed-table">
            <thead>
              <tr>
                <th>姓名</th>
                <th>工号</th>
                <th>所属班组</th>
                <th style="text-align:right;">累计离岗次数</th>
                <th style="text-align:right;">累计总时长 (分钟)</th>
                <th style="text-align:right;">平均每次时长 (分钟)</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in statsList" :key="s.employee_no">
                <td><strong>{{ s.name }}</strong></td>
                <td><span style="font-family:monospace;">{{ s.employee_no }}</span></td>
                <td>{{ s.team }}</td>
                <td style="text-align:right;">{{ s.times }} 次</td>
                <td style="text-align:right; font-weight:bold; color:#409EFF;">{{ s.total_minutes }} 分钟</td>
                <td style="text-align:right;">{{ s.avg_minutes }} 分钟</td>
              </tr>
              <tr v-if="statsList.length === 0">
                <td colspan="6" style="padding:30px; color:#888;">暂无统计数据</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ================= 视图 4: 员工档案管理 ================= -->
    <div v-show="activeTab === 'emps'">
      <div class="att-card">
        <div class="att-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#409EFF; vertical-align:middle;"><User /></el-icon>
            员工档案管理
          </span>
          <button v-if="canManage" type="button" class="btn-success btn-sm" @click="openAddEmpModal">
            <el-icon class="mr-1"><Plus /></el-icon> 添加员工
          </button>
        </div>
        <div class="att-table-wrapper" v-loading="empLoading">
          <table class="att-fixed-table">
            <thead>
              <tr>
                <th style="width:70px;">ID</th>
                <th>员工姓名</th>
                <th>工号</th>
                <th>所属班组</th>
                <th style="text-align:center;">当前状态</th>
                <th style="width:130px; text-align:center;">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in empList" :key="e.id">
                <td>{{ e.id }}</td>
                <td><strong>{{ e.name }}</strong></td>
                <td><span style="font-family:monospace;">{{ e.employee_no }}</span></td>
                <td>{{ e.team }}</td>
                <td style="text-align:center;">
                  <span class="badge" :style="{ background: e.status === 'onjob' ? '#d4edda' : '#f8d7da', color: e.status === 'onjob' ? '#155724' : '#721c24', padding:'2px 6px', borderRadius:'4px' }">
                    {{ e.status === 'onjob' ? '在岗' : '离岗' }}
                  </span>
                </td>
                <td style="text-align:center; white-space:nowrap;">
                  <template v-if="canManage">
                    <button type="button" class="btn-outline btn-mini" @click="openEditEmpModal(e)">编辑</button>
                    <button type="button" class="btn-danger btn-mini" style="margin-left:4px;" @click="deleteEmp(e.id)">删除</button>
                  </template>
                  <span v-else style="color:#c0c4cc;">—</span>
                </td>
              </tr>
              <tr v-if="empList.length === 0">
                <td colspan="6" style="padding:25px; color:#888;">暂无员工档案</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ================= 视图 5: IC卡片管理 ================= -->
    <div v-show="activeTab === 'cards'">
      <div class="att-card">
        <div class="att-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#409EFF; vertical-align:middle;"><CreditCard /></el-icon>
            IC卡片管理
          </span>
          <button type="button" class="btn-success btn-sm" @click="cardModalVisible = true">
            <el-icon class="mr-1"><Plus /></el-icon> 登记新卡片
          </button>
        </div>
        <div class="att-table-wrapper" v-loading="cardsLoading">
          <table class="att-fixed-table">
            <thead>
              <tr>
                <th style="width:70px;">ID</th>
                <th>卡片 UID</th>
                <th style="text-align:center;">状态</th>
                <th>当前持有人</th>
                <th style="width:130px; text-align:center;">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in cardList" :key="c.id">
                <td>{{ c.id }}</td>
                <td><strong style="font-family:monospace;">{{ c.card_uid }}</strong></td>
                <td style="text-align:center;">
                  <span class="badge" :style="{ background: c.status === 'free' ? '#d4edda' : '#fff3cd', color: c.status === 'free' ? '#155724' : '#856404', padding:'2px 6px', borderRadius:'4px' }">
                    {{ c.status === 'free' ? '空闲' : '占用' }}
                  </span>
                </td>
                <td>
                  <span v-if="c.emp_name">{{ c.emp_name }} ({{ c.employee_no }})</span>
                  <span v-else style="color:#888;">无</span>
                </td>
                <td style="text-align:center; white-space:nowrap;">
                  <button v-if="c.status === 'occupied'" type="button" class="btn-outline btn-mini" @click="handleForceUnbind(c.id)">强制解绑</button>
                  <button v-else-if="isAdmin" type="button" class="btn-danger btn-mini" @click="deleteCard(c.id)">删除</button>
                  <span v-else style="color:#888;">-</span>
                </td>
              </tr>
              <tr v-if="cardList.length === 0">
                <td colspan="5" style="padding:25px; color:#888;">暂无卡片数据</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- 弹窗 1: 离岗发卡登记 -->
    <el-dialog v-model="bindModalVisible" title="离岗发卡登记" width="480px" destroy-on-close>
      <el-form label-width="110px" size="small">
        <el-form-item label="选择离岗员工" required>
          <el-select
            v-model="bindForm.employee_id"
            style="width:100%;"
            filterable
            placeholder="请选择所辖在岗员工"
          >
            <el-option
              v-for="e in onjobEmpList"
              :key="e.id"
              :label="`${e.name} (${e.employee_no} - ${e.team})`"
              :value="e.id"
            />
          </el-select>
          <div v-if="onjobEmpList.length === 0" style="color:#F56C6C; font-size:12px; margin-top:4px;">
            ⚠️ 当前管辖范围内没有在岗员工，或员工尚未同步至档案。
          </div>
        </el-form-item>
        <el-form-item label="分配卡片 UID" required>
          <el-input v-model="bindForm.card_uid" placeholder="输入卡片UID (如 04:12:34:56)..." />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="bindModalVisible = false">取消</el-button>
        <el-button type="primary" :loading="bindingCard" @click="submitBindCard">确认发卡离岗</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 2: 员工档案添加/编辑 -->
    <el-dialog v-model="empModalVisible" :title="empForm.id ? '编辑员工档案' : '添加员工档案'" width="440px" destroy-on-close>
      <el-form label-width="90px" size="small">
        <el-form-item label="姓名" required>
          <el-input v-model="empForm.name" placeholder="员工姓名" />
        </el-form-item>
        <el-form-item label="工号" required>
          <el-input v-model="empForm.employee_no" placeholder="工号" />
        </el-form-item>
        <el-form-item label="所属班组" required>
          <el-select v-model="empForm.team" style="width:100%;">
            <el-option label="装置装配" value="装置装配" />
            <el-option label="装置调试" value="装置调试" />
            <el-option label="装置包装" value="装置包装" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="empModalVisible = false">取消</el-button>
        <el-button type="primary" @click="submitSaveEmp">保存</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 3: 登记新卡片 -->
    <el-dialog v-model="cardModalVisible" title="登记新卡片" width="400px" destroy-on-close>
      <el-form label-width="90px" size="small">
        <el-form-item label="卡片 UID" required>
          <el-input v-model="newCardUid" placeholder="如 04:12:34:56:78" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="cardModalVisible = false">取消</el-button>
        <el-button type="primary" @click="submitSaveCard">添加卡片</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Timer, User, Plus, Download, Search, Check,
  Warning, List, DataLine, Refresh, CreditCard, Postcard
} from '@element-plus/icons-vue';
import request from '@/api/request';

const activeTab = ref('dashboard');
const isAdmin = ref(false);
// ★ 按钮级权限：员工档案「新增/编辑/删除」需维护权限，无权限直接隐藏按钮
const canManage = ref(false);
let autoRefreshTimer = null;

// 1. 看板数据
const dashLoading = ref(false);
const dashData = reactive({
  total_count: 0,
  long_count: 0,
  max_minutes: 0,
  free_cards: 0,
  onjob_emps: 0
});
const dashList = ref([]);

const formatMaxMinutes = (mins) => {
  if (!mins) return '0分钟';
  if (mins >= 60) return `${Math.floor(mins / 60)}小时${mins % 60}分`;
  return `${mins}分钟`;
};

const getTimeBadgeClass = (lvl) => {
  if (lvl === 'danger') return 'badge-danger-time';
  if (lvl === 'warning') return 'badge-warning-time';
  return 'badge-normal-time';
};

const getStateText = (lvl) => {
  if (lvl === 'danger') return '严重超时';
  if (lvl === 'warning') return '超时提醒';
  return '离岗中';
};

const loadDashboard = async () => {
  dashLoading.value = true;
  try {
    const res = await request.get('/attendance/dashboard');
    if (res.data) {
      Object.assign(dashData, res.data);
      dashList.value = res.data.list || [];
      isAdmin.value = !!res.data.is_admin;
      canManage.value = !!res.data.can_manage;
    }
  } finally {
    dashLoading.value = false;
  }
};

const handleReturnCard = (uid) => {
  ElMessageBox.confirm('确认该员工已返岗并回收离岗卡吗？', '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/attendance/unbind_card', { card_uid: uid });
    ElMessage.success(res.message || '返岗成功');
    loadDashboard();
  });
};

// 发卡登记
const bindModalVisible = ref(false);
const bindingCard = ref(false);
const onjobEmpList = ref([]);
const bindForm = reactive({ employee_id: null, card_uid: '' });

const openBindModal = async () => {
  bindForm.employee_id = null;
  bindForm.card_uid = '';
  bindModalVisible.value = true;
  try {
    const res = await request.get('/attendance/list_employees', { params: { status: 'onjob' } });
    onjobEmpList.value = res.data?.list || [];
  } catch (err) {
    onjobEmpList.value = [];
  }
};

const submitBindCard = async () => {
  if (!bindForm.employee_id || !bindForm.card_uid.trim()) {
    return ElMessage.warning('请选择员工并输入卡片UID！');
  }
  bindingCard.value = true;
  try {
    const res = await request.post('/attendance/bind_card', bindForm);
    ElMessage.success(res.message || '发卡成功');
    bindModalVisible.value = false;
    loadDashboard();
  } finally {
    bindingCard.value = false;
  }
};

// 2. 历史记录
const recLoading = ref(false);
const recFilterName = ref('');
const recFilterNo = ref('');
const recFilterStart = ref('');
const recFilterEnd = ref('');
const recordList = ref([]);
const totalRecords = ref(0);
const curRecPage = ref(1);
const totalRecPages = ref(1);

const loadRecords = async (p = 1) => {
  curRecPage.value = p;
  recLoading.value = true;
  try {
    const res = await request.get('/attendance/list_records', {
      params: {
        page: p,
        name: recFilterName.value.trim(),
        employee_no: recFilterNo.value.trim(),
        start_date: recFilterStart.value,
        end_date: recFilterEnd.value
      }
    });
    if (res.data) {
      recordList.value = res.data.records || [];
      totalRecords.value = res.data.total_records || 0;
      totalRecPages.value = res.data.total_pages || 1;
    }
  } finally {
    recLoading.value = false;
  }
};

const resetRecordsFilter = () => {
  recFilterName.value = '';
  recFilterNo.value = '';
  recFilterStart.value = '';
  recFilterEnd.value = '';
  loadRecords(1);
};

const exportRecords = () => {
  window.open(
    `/api/attendance/export?name=${encodeURIComponent(recFilterName.value.trim())}&employee_no=${encodeURIComponent(recFilterNo.value.trim())}&start_date=${encodeURIComponent(recFilterStart.value)}&end_date=${encodeURIComponent(recFilterEnd.value)}`,
    '_blank'
  );
};

// 3. 统计报表
const statsLoading = ref(false);
const statsList = ref([]);

const loadStats = async () => {
  statsLoading.value = true;
  try {
    const res = await request.get('/attendance/statistics');
    statsList.value = res.data?.stats || [];
  } finally {
    statsLoading.value = false;
  }
};

// 4. 员工档案
const empLoading = ref(false);
const empList = ref([]);
const empModalVisible = ref(false);
const empForm = reactive({ id: null, name: '', employee_no: '', team: '装置装配' });

const loadEmps = async () => {
  empLoading.value = true;
  try {
    const res = await request.get('/attendance/list_employees');
    empList.value = res.data?.list || [];
    if (res.data && typeof res.data.can_manage !== 'undefined') {
      canManage.value = !!res.data.can_manage;
    }
  } finally {
    empLoading.value = false;
  }
};

const openAddEmpModal = () => {
  Object.assign(empForm, { id: null, name: '', employee_no: '', team: '装置装配' });
  empModalVisible.value = true;
};

const openEditEmpModal = (e) => {
  Object.assign(empForm, e);
  empModalVisible.value = true;
};

const submitSaveEmp = async () => {
  if (!empForm.name.trim() || !empForm.employee_no.trim()) {
    return ElMessage.warning('姓名和工号不能为空！');
  }
  const res = await request.post('/attendance/save_employee', empForm);
  ElMessage.success(res.message || '保存成功');
  empModalVisible.value = false;
  loadEmps();
};

const deleteEmp = (id) => {
  ElMessageBox.confirm('确定要删除该员工档案吗？', '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/attendance/delete_employee', { id });
    ElMessage.success(res.message || '删除成功');
    loadEmps();
  });
};

// 5. 卡片管理
const cardsLoading = ref(false);
const cardList = ref([]);
const cardModalVisible = ref(false);
const newCardUid = ref('');

const loadCards = async () => {
  cardsLoading.value = true;
  try {
    const res = await request.get('/attendance/list_cards');
    cardList.value = res.data?.list || [];
  } finally {
    cardsLoading.value = false;
  }
};

const submitSaveCard = async () => {
  if (!newCardUid.value.trim()) return ElMessage.warning('卡片UID不能为空！');
  const res = await request.post('/attendance/save_card', { card_uid: newCardUid.value.trim() });
  ElMessage.success(res.message || '添加成功');
  newCardUid.value = '';
  cardModalVisible.value = false;
  loadCards();
};

const handleForceUnbind = (id) => {
  ElMessageBox.confirm('强制解绑将自动使持卡员工返岗，确定解绑吗？', '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/attendance/unbind_card', { card_id: id });
    ElMessage.success(res.message || '解绑成功');
    loadCards();
  });
};

const deleteCard = (id) => {
  ElMessageBox.confirm('确定要删除该空闲卡片吗？', '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/attendance/delete_card', { id });
    ElMessage.success(res.message || '删除成功');
    loadCards();
  });
};

const switchTab = (tab) => {
  activeTab.value = tab;
  if (tab === 'dashboard') loadDashboard();
  else if (tab === 'records') loadRecords(1);
  else if (tab === 'stats') loadStats();
  else if (tab === 'emps') loadEmps();
  else if (tab === 'cards') loadCards();
};

onMounted(() => {
  loadDashboard();
  autoRefreshTimer = setInterval(() => {
    if (activeTab.value === 'dashboard') loadDashboard();
  }, 30000);
});

onUnmounted(() => {
  if (autoRefreshTimer) clearInterval(autoRefreshTimer);
});
</script>

<style scoped>
.att-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.att-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.att-card-header {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.att-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  border-bottom: 2px solid #dee2e6;
  flex-wrap: wrap;
}
.att-tab-btn {
  padding: 9px 18px;
  border: none;
  background: transparent;
  font-size: 15px;
  font-weight: 600;
  color: #64748b;
  cursor: pointer;
  border-bottom: 3px solid transparent;
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
}
.att-tab-btn.active {
  color: #007bff;
  border-bottom-color: #007bff;
}

.att-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}
.att-stat-item {
  background: #f0f7ff;
  border: 1px solid #d0e3ff;
  border-radius: 6px;
  padding: 12px 18px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.att-stat-val {
  font-size: 24px;
  font-weight: bold;
  color: #007bff;
  line-height: 1.1;
  margin-top: 2px;
}

.att-table-wrapper {
  width: 100%;
  overflow-x: auto;
  max-height: calc(100vh - 350px);
  min-height: 280px;
  border-top: 1px solid #dee2e6;
}
.att-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 12.5px;
  text-align: center;
  color: #333;
}
.att-fixed-table thead th {
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
}
.att-fixed-table tbody td {
  padding: 6px 5px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
}
.att-fixed-table tbody tr:hover {
  background-color: #f8fafc;
}

.badge-normal-time {
  background: #e8f4fd;
  color: #007bff;
  padding: 2px 7px;
  border-radius: 10px;
  font-size: 11.5px;
}
.badge-warning-time {
  background: #fff3cd;
  color: #856404;
  padding: 2px 7px;
  border-radius: 10px;
  font-size: 11.5px;
  font-weight: 600;
}
.badge-danger-time {
  background: #f8d7da;
  color: #721c24;
  padding: 2px 7px;
  border-radius: 10px;
  font-size: 11.5px;
  font-weight: bold;
}

.att-pagination-bar {
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

.att-input {
  height: 32px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
}
.att-input:focus { border-color: #80bdff; }

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