<template>
  <div class="ac-page">
    <!-- 顶部标题卡片 -->
    <div class="ac-card ac-head">
      <div class="ac-head-left">
        <span class="ac-title">
          <el-icon class="ac-title-icon"><Tools /></el-icon>
          老化车维修管理
        </span>
        <span class="ac-subtitle">
          共 {{ cartTotal }} 辆（001-{{ maxCartNo }}）· 报修与维修面向全员，编辑删除限管理员
        </span>
      </div>
      <div class="ac-head-right">
        <el-button type="primary" size="small" @click="openReport">
          <el-icon class="mr-1"><Plus /></el-icon> 报修
        </el-button>
        <el-button size="small" @click="refreshAll">
          <el-icon class="mr-1"><Refresh /></el-icon> 刷新
        </el-button>
      </div>
    </div>

    <!-- 看板 -->
    <div class="ac-stats" v-loading="loadingBoard">
      <div class="ac-stat" style="border-left-color:#e6a23c;">
        <div class="ac-stat-value" style="color:#e6a23c;">{{ board.need_repair || 0 }}</div>
        <div class="ac-stat-label">需要维修（辆）</div>
        <div class="ac-stat-sub">待修 {{ board.pending_count || 0 }} · 维修中 {{ board.repairing_count || 0 }}</div>
      </div>
      <div class="ac-stat" style="border-left-color:#409EFF;">
        <div class="ac-stat-value" style="color:#409EFF;">{{ board.repairing_count || 0 }}</div>
        <div class="ac-stat-label">正在维修（单）</div>
        <div class="ac-stat-sub">正常可用 {{ board.normal_count || 0 }} 辆</div>
      </div>
      <div class="ac-stat" style="border-left-color:#f56c6c;">
        <div class="ac-stat-value" style="color:#f56c6c;">{{ board.month_broken || 0 }}</div>
        <div class="ac-stat-label">本月损坏（辆）</div>
        <div class="ac-stat-sub">报修 {{ board.month_reports || 0 }} 次</div>
      </div>
      <div class="ac-stat" style="border-left-color:#67c23a;">
        <div class="ac-stat-value" style="color:#67c23a;">{{ board.month_fixed || 0 }}</div>
        <div class="ac-stat-label">本月修复（辆）</div>
        <div class="ac-stat-sub">完成 {{ board.month_fixed_times || 0 }} 单</div>
      </div>
      <div class="ac-stat" style="border-left-color:#909399;">
        <div class="ac-stat-value" style="color:#606266;">{{ board.month_avg_text || '0 分钟' }}</div>
        <div class="ac-stat-label">本月平均维修时长</div>
        <div class="ac-stat-sub">累计 {{ board.month_minutes_text || '0 分钟' }}</div>
      </div>
    </div>

    <!-- 统计周期与规则 -->
    <div class="ac-card ac-period">
      <div class="ac-period-left">
        <el-icon><Calendar /></el-icon>
        <b>统计期间：</b>{{ periodLabel }}
        <span class="ac-period-range">（{{ periodStart }} ~ {{ periodEnd }}，每月 26 日至次月 25 日为一个统计月）</span>
      </div>
      <div class="ac-period-right">
        <el-tag
          v-for="r in rules"
          :key="r"
          size="small"
          type="info"
          effect="plain"
          class="ac-rule-tag"
        >{{ r }}</el-tag>
      </div>
    </div>

    <el-tabs v-model="activeTab" class="ac-tabs">
      <!-- ============ 报修记录 ============ -->
      <el-tab-pane label="报修记录" name="records">
        <div class="ac-card">
          <div class="ac-filter">
            <el-select v-model="filter.status" placeholder="状态" clearable size="small" style="width:120px;">
              <el-option label="待维修" value="pending" />
              <el-option label="维修中" value="repairing" />
              <el-option label="已修复" value="done" />
            </el-select>
            <el-select
              v-model="filter.cart_no"
              placeholder="老化车编号"
              clearable
              filterable
              size="small"
              style="width:150px;"
            >
              <el-option v-for="c in cartOptions" :key="c" :label="c" :value="c" />
            </el-select>
            <el-date-picker
              v-model="filter.from"
              type="date"
              value-format="YYYY-MM-DD"
              placeholder="报修起始"
              size="small"
              style="width:150px;"
            />
            <el-date-picker
              v-model="filter.to"
              type="date"
              value-format="YYYY-MM-DD"
              placeholder="报修截止"
              size="small"
              style="width:150px;"
            />
            <el-input
              v-model="filter.keyword"
              placeholder="故障现象/备注/人员"
              clearable
              size="small"
              style="width:200px;"
              @keyup.enter="loadList"
            />
            <el-button type="primary" size="small" @click="loadList">
              <el-icon class="mr-1"><Search /></el-icon> 查询
            </el-button>
            <el-button size="small" @click="resetFilter">重置</el-button>
            <div style="flex:1;"></div>
            <el-button size="small" @click="exportRecords">
              <el-icon class="mr-1"><Download /></el-icon> 导出
            </el-button>
          </div>

          <el-table
            v-loading="loadingList"
            :data="records"
            size="small"
            border
            stripe
            style="width:100%;"
            empty-text="暂无报修记录"
          >
            <el-table-column prop="cart_no" label="车号" width="80" align="center">
              <template #default="{ row }">
                <b style="font-family:monospace;">{{ row.cart_no }}</b>
              </template>
            </el-table-column>
            <el-table-column prop="fault_type" label="故障类型" width="110" show-overflow-tooltip>
              <template #default="{ row }">
                <span>{{ row.fault_type || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="fault_desc" label="故障现象" min-width="200" show-overflow-tooltip />
            <el-table-column prop="status" label="状态" width="90" align="center">
              <template #default="{ row }">
                <el-tag :type="statusTagType(row.status)" size="small">{{ statusText(row.status) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="reporter_name" label="报修人" width="100" show-overflow-tooltip />
            <el-table-column prop="reported_at" label="报修时间" width="150" />
            <el-table-column prop="repairer_name" label="维修人" width="100" show-overflow-tooltip>
              <template #default="{ row }">
                <span>{{ row.repairer_name || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="维修时长" width="150">
              <template #default="{ row }">
                <span v-if="row.status === 'repairing'" style="color:#409EFF; font-weight:600;">
                  <el-icon><Timer /></el-icon> 已用 {{ row.elapsed_text }}
                </span>
                <span v-else-if="row.status === 'done'">
                  <b>{{ row.duration_text }}</b>
                  <span class="ac-raw">(自然 {{ row.raw_text }})</span>
                </span>
                <span v-else style="color:#c0c4cc;">-</span>
              </template>
            </el-table-column>
            <el-table-column prop="repair_remark" label="维修备注" min-width="160" show-overflow-tooltip>
              <template #default="{ row }">
                <span>{{ row.repair_remark || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="170" fixed="right" align="center">
              <template #default="{ row }">
                <el-button
                  v-if="row.status === 'pending'"
                  type="primary"
                  size="small"
                  link
                  @click="startRepair(row)"
                >开始维修</el-button>
                <el-button
                  v-if="row.status === 'repairing'"
                  type="success"
                  size="small"
                  link
                  @click="openFinish(row)"
                >完成</el-button>
                <template v-if="canManage">
                  <el-button size="small" link @click="openEdit(row)">编辑</el-button>
                  <el-button type="danger" size="small" link @click="removeRecord(row)">删除</el-button>
                </template>
                <span v-if="row.status === 'done' && !canManage" style="color:#c0c4cc;">-</span>
              </template>
            </el-table-column>
          </el-table>

          <div class="ac-pager">
            <el-pagination
              v-model:current-page="page"
              v-model:page-size="size"
              :page-sizes="[20, 50, 100]"
              :total="total"
              layout="total, sizes, prev, pager, next"
              size="small"
              @current-change="loadList"
              @size-change="loadList"
            />
          </div>
        </div>
      </el-tab-pane>

      <!-- ============ 老化车总览 ============ -->
      <el-tab-pane :label="`老化车总览(${cartTotal})`" name="carts">
        <div class="ac-card">
          <div class="ac-legend">
            <span><i class="ac-dot" style="background:#67c23a;"></i>正常</span>
            <span><i class="ac-dot" style="background:#e6a23c;"></i>待维修</span>
            <span><i class="ac-dot" style="background:#409EFF;"></i>维修中</span>
            <span class="ac-legend-tip">点击车号可查看该车的报修记录</span>
          </div>
          <div class="ac-grid" v-loading="loadingCarts">
            <div
              v-for="c in carts"
              :key="c.cart_no"
              class="ac-cell"
              :class="'ac-cell-' + c.status"
              @click="filterByCart(c.cart_no)"
            >
              <el-tooltip placement="top" :disabled="c.status === 'normal'">
                <template #content>
                  <div style="font-size:12px; line-height:1.6;">
                    <div><b>{{ c.cart_no }}</b>（{{ cartStatusText(c.status) }}）</div>
                    <div v-if="c.current_fault">故障：{{ c.current_fault }}</div>
                    <div>累计报修 {{ c.repair_times }} 次</div>
                    <div v-if="c.last_fixed_at">最近修复：{{ c.last_fixed_at }}</div>
                  </div>
                </template>
                <div class="ac-cell-body">
                  <div class="ac-cell-no">{{ c.cart_no }}</div>
                  <div class="ac-cell-times" v-if="c.repair_times > 0">{{ c.repair_times }}次</div>
                </div>
              </el-tooltip>
            </div>
          </div>
        </div>
      </el-tab-pane>

      <!-- ============ 维修统计 ============ -->
      <el-tab-pane label="维修统计" name="stats">
        <div class="ac-stat-row">
          <div class="ac-card ac-card-half">
            <div class="ac-card-title">最近修好的 5 辆老化车</div>
            <el-table :data="latestFixed" size="small" border style="width:100%;" empty-text="本月暂无修复记录">
              <el-table-column prop="cart_no" label="车号" width="70" align="center">
                <template #default="{ row }"><b>{{ row.cart_no }}</b></template>
              </el-table-column>
              <el-table-column prop="fault_desc" label="故障现象" min-width="150" show-overflow-tooltip />
              <el-table-column prop="repairer_name" label="维修人" width="90" show-overflow-tooltip />
              <el-table-column prop="duration_text" label="有效时长" width="120" />
              <el-table-column prop="repair_finished_at" label="完成时间" width="150" />
            </el-table>
          </div>

          <div class="ac-card ac-card-half">
            <div class="ac-card-title">本期故障次数最多的车辆</div>
            <el-table :data="topCarts" size="small" border style="width:100%;" empty-text="本期暂无报修">
              <el-table-column prop="cart_no" label="车号" width="90" align="center">
                <template #default="{ row }"><b>{{ row.cart_no }}</b></template>
              </el-table-column>
              <el-table-column prop="times" label="报修次数" width="100" align="center" />
              <el-table-column label="占比" min-width="120">
                <template #default="{ row }">
                  <el-progress
                    :percentage="percentOf(row.times)"
                    :stroke-width="12"
                    :show-text="false"
                    color="#f56c6c"
                  />
                </template>
              </el-table-column>
            </el-table>
          </div>
        </div>

        <div class="ac-card">
          <div class="ac-card-title">本期维修人员工作量</div>
          <el-table :data="workers" size="small" border style="width:100%;" empty-text="本期暂无完成记录">
            <el-table-column prop="name" label="维修人" width="140" />
            <el-table-column prop="times" label="完成单数" width="110" align="center" />
            <el-table-column prop="duration_text" label="有效时长合计" width="160" />
            <el-table-column label="平均单耗时" min-width="160">
              <template #default="{ row }">
                {{ avgOf(row.times, row.minutes) }}
              </template>
            </el-table-column>
          </el-table>
        </div>
      </el-tab-pane>
    </el-tabs>

    <!-- 报修对话框 -->
    <el-dialog v-model="reportVisible" title="老化车报修" width="520px" destroy-on-close>
      <el-form label-width="100px" size="small">
        <el-form-item label="老化车编号" required>
          <el-select
            v-model="reportForm.cart_no"
            filterable
            placeholder="选择或输入编号，如 001"
            style="width:100%;"
          >
            <el-option v-for="c in cartOptions" :key="c" :label="c" :value="c" />
          </el-select>
        </el-form-item>
        <el-form-item label="故障类型">
          <el-select
            v-model="reportForm.fault_type"
            filterable
            allow-create
            clearable
            placeholder="可自行输入"
            style="width:100%;"
          >
            <el-option v-for="t in faultTypes" :key="t" :label="t" :value="t" />
          </el-select>
        </el-form-item>
        <el-form-item label="故障现象" required>
          <el-input
            v-model="reportForm.fault_desc"
            type="textarea"
            :rows="3"
            maxlength="500"
            show-word-limit
            placeholder="请描述故障现象，如：第 3 层不通电 / 温度升不上去"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="reportVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitReport">提交报修</el-button>
      </template>
    </el-dialog>

    <!-- 完成维修对话框 -->
    <el-dialog v-model="finishVisible" title="完成维修" width="520px" destroy-on-close>
      <div v-if="finishRow" class="ac-finish-info">
        <div><b>{{ finishRow.cart_no }}</b> · {{ finishRow.fault_type || '未分类' }}</div>
        <div class="ac-finish-desc">{{ finishRow.fault_desc }}</div>
        <div class="ac-finish-time">
          开始：{{ finishRow.repair_started_at }}
          <el-divider direction="vertical" />
          已用时：<b style="color:#409EFF;">{{ finishRow.elapsed_text || '计算中' }}</b>
        </div>
      </div>
      <el-form label-width="100px" size="small" style="margin-top:10px;">
        <el-form-item label="维修备注">
          <el-input
            v-model="finishForm.repair_remark"
            type="textarea"
            :rows="3"
            maxlength="500"
            show-word-limit
            placeholder="可填写处理措施、更换配件等"
          />
        </el-form-item>
      </el-form>
      <div class="ac-tip">提交后系统按规则自动扣除不计入的时段，生成有效维修时长。</div>
      <template #footer>
        <el-button @click="finishVisible = false">取消</el-button>
        <el-button type="success" :loading="saving" @click="submitFinish">确认完成</el-button>
      </template>
    </el-dialog>

    <!-- 编辑对话框（管理员） -->
    <el-dialog v-model="editVisible" title="编辑报修记录" width="520px" destroy-on-close>
      <el-form label-width="100px" size="small">
        <el-form-item label="老化车编号" required>
          <el-select v-model="editForm.cart_no" filterable style="width:100%;">
            <el-option v-for="c in cartOptions" :key="c" :label="c" :value="c" />
          </el-select>
        </el-form-item>
        <el-form-item label="故障类型">
          <el-select
            v-model="editForm.fault_type"
            filterable
            allow-create
            clearable
            style="width:100%;"
          >
            <el-option v-for="t in faultTypes" :key="t" :label="t" :value="t" />
          </el-select>
        </el-form-item>
        <el-form-item label="故障现象" required>
          <el-input v-model="editForm.fault_desc" type="textarea" :rows="3" maxlength="500" show-word-limit />
        </el-form-item>
        <el-form-item label="维修备注">
          <el-input v-model="editForm.repair_remark" type="textarea" :rows="2" maxlength="500" show-word-limit />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitEdit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Tools, Plus, Refresh, Search, Download, Timer, Calendar } from '@element-plus/icons-vue';
import request from '@/api/request';

const activeTab = ref('records');
const loadingBoard = ref(false);
const loadingList = ref(false);
const loadingCarts = ref(false);
const saving = ref(false);

const cartTotal = ref(140);
const faultTypes = ref([]);
const rules = ref([]);
const canManage = ref(false);

const board = ref({});
const records = ref([]);
const carts = ref([]);
const latestFixed = ref([]);
const topCarts = ref([]);
const workers = ref([]);

const page = ref(1);
const size = ref(20);
const total = ref(0);

const filter = reactive({
  status: '',
  cart_no: '',
  keyword: '',
  from: '',
  to: ''
});

const cartOptions = computed(() => {
  const n = cartTotal.value || 140;
  const arr = [];
  for (let i = 1; i <= n; i++) {
    arr.push(String(i).padStart(3, '0'));
  }
  return arr;
});

const maxCartNo = computed(() => String(cartTotal.value || 140).padStart(3, '0'));

const periodLabel = computed(() => (board.value.period && board.value.period.label) || '-');
const periodStart = computed(() => (board.value.period && board.value.period.start_date) || '-');
const periodEnd = computed(() => (board.value.period && board.value.period.end_date) || '-');

// 状态展示
function statusText(s) {
  return { pending: '待维修', repairing: '维修中', done: '已修复' }[s] || s || '-';
}
function statusTagType(s) {
  return { pending: 'warning', repairing: 'primary', done: 'success' }[s] || 'info';
}
function cartStatusText(s) {
  return { normal: '正常', pending: '待维修', repairing: '维修中' }[s] || s || '-';
}

// 统计辅助
function percentOf(times) {
  const max = topCarts.value.reduce((m, r) => Math.max(m, Number(r.times) || 0), 0);
  if (!max) return 0;
  return Math.round(((Number(times) || 0) / max) * 100);
}
function avgOf(times, minutes) {
  const t = Number(times) || 0;
  const m = Number(minutes) || 0;
  if (!t) return '-';
  const avg = Math.round(m / t);
  const h = Math.floor(avg / 60);
  const mm = avg % 60;
  return h > 0 ? `${h} 小时 ${mm} 分钟` : `${mm} 分钟`;
}

// ---------- 数据加载 ----------

async function loadInit() {
  try {
    const res = await request.get('/aging_cart/init');
    const d = res.data || {};
    cartTotal.value = d.cart_total || 140;
    faultTypes.value = d.fault_types || [];
    rules.value = d.rules || [];
    canManage.value = !!d.can_manage;
  } catch (e) {
    /* 拦截器已提示 */
  }
}

async function loadBoard() {
  loadingBoard.value = true;
  try {
    const res = await request.get('/aging_cart/dashboard');
    const d = res.data || {};
    board.value = d;
    latestFixed.value = d.latest_fixed || [];
    topCarts.value = d.top_carts || [];
    workers.value = d.workers || [];
  } catch (e) {
    /* 拦截器已提示 */
  } finally {
    loadingBoard.value = false;
  }
}

async function loadList() {
  loadingList.value = true;
  try {
    const res = await request.get('/aging_cart/list', {
      params: {
        status: filter.status,
        cart_no: filter.cart_no,
        keyword: filter.keyword,
        from: filter.from,
        to: filter.to,
        page: page.value,
        size: size.value
      }
    });
    const d = res.data || {};
    records.value = d.list || [];
    total.value = d.total || 0;
    if (typeof d.can_manage === 'boolean') {
      canManage.value = d.can_manage;
    }
  } catch (e) {
    /* 拦截器已提示 */
  } finally {
    loadingList.value = false;
  }
}

async function loadCarts() {
  loadingCarts.value = true;
  try {
    const res = await request.get('/aging_cart/carts');
    carts.value = (res.data && res.data.list) || [];
    if (carts.value.length) {
      cartTotal.value = carts.value.length;
    }
  } catch (e) {
    /* 拦截器已提示 */
  } finally {
    loadingCarts.value = false;
  }
}

function refreshAll() {
  loadBoard();
  loadList();
  loadCarts();
}

function resetFilter() {
  filter.status = '';
  filter.cart_no = '';
  filter.keyword = '';
  filter.from = '';
  filter.to = '';
  page.value = 1;
  loadList();
}

function filterByCart(cartNo) {
  filter.cart_no = cartNo;
  filter.status = '';
  page.value = 1;
  activeTab.value = 'records';
  loadList();
}

function exportRecords() {
  const params = new URLSearchParams();
  if (filter.status) params.set('status', filter.status);
  if (filter.cart_no) params.set('cart_no', filter.cart_no);
  if (filter.from) params.set('from', filter.from);
  if (filter.to) params.set('to', filter.to);
  const qs = params.toString();
  // 同源请求，浏览器自动携带会话 Cookie；导出由后端以附件形式返回
  window.open('/api/aging_cart/export' + (qs ? '?' + qs : ''), '_blank');
}

// ---------- 报修 ----------

const reportVisible = ref(false);
const reportForm = reactive({ cart_no: '', fault_type: '', fault_desc: '' });

function openReport() {
  reportForm.cart_no = '';
  reportForm.fault_type = '';
  reportForm.fault_desc = '';
  reportVisible.value = true;
}

async function submitReport() {
  if (!reportForm.cart_no) {
    ElMessage.warning('请选择老化车编号');
    return;
  }
  if (!reportForm.fault_desc.trim()) {
    ElMessage.warning('请填写故障现象');
    return;
  }
  saving.value = true;
  try {
    await request.post('/aging_cart/report', {
      cart_no: reportForm.cart_no,
      fault_type: reportForm.fault_type,
      fault_desc: reportForm.fault_desc.trim()
    });
    ElMessage.success('报修成功');
    reportVisible.value = false;
    refreshAll();
  } catch (e) {
    /* 拦截器已提示 */
  } finally {
    saving.value = false;
  }
}

// ---------- 开始维修 ----------

async function startRepair(row) {
  try {
    await ElMessageBox.confirm(
      `确认开始维修 ${row.cart_no}？计时将立即开始。`,
      '开始维修',
      { type: 'warning', confirmButtonText: '开始', cancelButtonText: '取消' }
    );
  } catch (e) {
    return;
  }
  try {
    await request.post('/aging_cart/start', { id: row.id });
    ElMessage.success('已开始维修');
    refreshAll();
  } catch (e) {
    /* 拦截器已提示 */
  }
}

// ---------- 完成维修 ----------

const finishVisible = ref(false);
const finishRow = ref(null);
const finishForm = reactive({ repair_remark: '' });

function openFinish(row) {
  finishRow.value = row;
  finishForm.repair_remark = row.repair_remark || '';
  finishVisible.value = true;
}

async function submitFinish() {
  if (!finishRow.value) return;
  saving.value = true;
  try {
    const res = await request.post('/aging_cart/finish', {
      id: finishRow.value.id,
      repair_remark: finishForm.repair_remark.trim()
    });
    const d = (res && res.data) || {};
    ElMessage.success(`维修完成，有效时长 ${d.duration_text || '-'}`);
    finishVisible.value = false;
    refreshAll();
  } catch (e) {
    /* 拦截器已提示 */
  } finally {
    saving.value = false;
  }
}

// ---------- 编辑 / 删除（管理员） ----------

const editVisible = ref(false);
const editForm = reactive({ id: 0, cart_no: '', fault_type: '', fault_desc: '', repair_remark: '' });

function openEdit(row) {
  editForm.id = row.id;
  editForm.cart_no = row.cart_no;
  editForm.fault_type = row.fault_type || '';
  editForm.fault_desc = row.fault_desc || '';
  editForm.repair_remark = row.repair_remark || '';
  editVisible.value = true;
}

async function submitEdit() {
  if (!editForm.fault_desc.trim()) {
    ElMessage.warning('故障现象不能为空');
    return;
  }
  saving.value = true;
  try {
    await request.post('/aging_cart/save', {
      id: editForm.id,
      cart_no: editForm.cart_no,
      fault_type: editForm.fault_type,
      fault_desc: editForm.fault_desc.trim(),
      repair_remark: editForm.repair_remark.trim()
    });
    ElMessage.success('保存成功');
    editVisible.value = false;
    refreshAll();
  } catch (e) {
    /* 拦截器已提示 */
  } finally {
    saving.value = false;
  }
}

async function removeRecord(row) {
  try {
    await ElMessageBox.confirm(
      `确认删除 ${row.cart_no} 的这条报修记录？删除后不可恢复。`,
      '删除确认',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' }
    );
  } catch (e) {
    return;
  }
  try {
    await request.post('/aging_cart/delete', { id: row.id });
    ElMessage.success('已删除');
    refreshAll();
  } catch (e) {
    /* 拦截器已提示 */
  }
}

// 维修中的记录需要滚动刷新「已用时」，避免页面停留过久显示陈旧
let tickTimer = null;

onMounted(async () => {
  await loadInit();
  await loadBoard();
  await loadList();
  loadCarts();

  tickTimer = setInterval(() => {
    if (document.visibilityState === 'visible' && records.value.some((r) => r.status === 'repairing')) {
      loadList();
    }
  }, 60000);
});

onBeforeUnmount(() => {
  if (tickTimer) {
    clearInterval(tickTimer);
    tickTimer = null;
  }
});
</script>

<style scoped>
.ac-page {
  padding: 12px;
  background: #f5f7fa;
  min-height: 100%;
}

.ac-card {
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
  padding: 12px 16px;
  margin-bottom: 12px;
}

.ac-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.ac-head-left {
  display: flex;
  align-items: baseline;
  gap: 12px;
  flex-wrap: wrap;
}

.ac-title {
  font-size: 17.5px;
  font-weight: 600;
  color: #2c3e50;
}

.ac-title-icon {
  color: #409eff;
  vertical-align: middle;
  margin-right: 6px;
}

.ac-subtitle {
  font-size: 12px;
  color: #64748b;
}

.ac-stats {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}

@media (max-width: 1200px) {
  .ac-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.ac-stat {
  background: #fff;
  border: 1px solid #e4e7ed;
  border-left: 4px solid #409eff;
  border-radius: 6px;
  padding: 12px 14px;
}

.ac-stat-value {
  font-size: 26px;
  font-weight: 700;
  line-height: 1.2;
}

.ac-stat-label {
  font-size: 13px;
  color: #2c3e50;
  margin-top: 4px;
  font-weight: 600;
}

.ac-stat-sub {
  font-size: 12px;
  color: #909399;
  margin-top: 2px;
}

.ac-period {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  font-size: 12.5px;
  color: #606266;
}

.ac-period-range {
  color: #909399;
}

.ac-period-right {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.ac-rule-tag {
  max-width: 100%;
}

.ac-tabs {
  margin-top: 4px;
}

.ac-filter {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
  margin-bottom: 10px;
}

.ac-pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 10px;
}

.ac-raw {
  color: #c0c4cc;
  font-size: 12px;
  margin-left: 4px;
}

.ac-legend {
  display: flex;
  gap: 16px;
  align-items: center;
  font-size: 12px;
  color: #606266;
  margin-bottom: 10px;
  flex-wrap: wrap;
}

.ac-legend-tip {
  color: #909399;
}

.ac-dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 2px;
  margin-right: 4px;
  vertical-align: middle;
}

.ac-grid {
  display: grid;
  grid-template-columns: repeat(20, minmax(0, 1fr));
  gap: 6px;
}

@media (max-width: 1400px) {
  .ac-grid {
    grid-template-columns: repeat(10, minmax(0, 1fr));
  }
}

.ac-cell {
  border: 1px solid #e4e7ed;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s;
  background: #fff;
}

.ac-cell:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
  transform: translateY(-1px);
}

.ac-cell-body {
  padding: 6px 2px;
  text-align: center;
}

.ac-cell-no {
  font-family: monospace;
  font-size: 12px;
  font-weight: 700;
}

.ac-cell-times {
  font-size: 10px;
  color: #909399;
}

.ac-cell-normal .ac-cell-no {
  color: #67c23a;
}

.ac-cell-pending {
  background: #fdf6ec;
  border-color: #f5dab1;
}

.ac-cell-pending .ac-cell-no {
  color: #e6a23c;
}

.ac-cell-repairing {
  background: #ecf5ff;
  border-color: #b3d8ff;
}

.ac-cell-repairing .ac-cell-no {
  color: #409eff;
}

.ac-stat-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

@media (max-width: 1200px) {
  .ac-stat-row {
    grid-template-columns: minmax(0, 1fr);
  }
}

.ac-card-half {
  margin-bottom: 12px;
}

.ac-card-title {
  font-size: 13.5px;
  font-weight: 600;
  color: #2c3e50;
  margin-bottom: 8px;
}

.ac-finish-info {
  background: #f5f7fa;
  border-radius: 4px;
  padding: 10px 12px;
  font-size: 13px;
  color: #2c3e50;
}

.ac-finish-desc {
  color: #606266;
  margin-top: 4px;
  word-break: break-all;
}

.ac-finish-time {
  margin-top: 6px;
  font-size: 12.5px;
  color: #606266;
}

.ac-tip {
  font-size: 12px;
  color: #909399;
  margin-top: 6px;
}

.mr-1 {
  margin-right: 4px;
}
</style>
