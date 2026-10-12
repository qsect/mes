<template>
  <div class="sk-container">
    <!-- 顶部标题卡片 -->
    <div class="sk-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Grid /></el-icon>
          技能矩阵管理系统
        </span>
      </div>
    </div>

    <!-- 顶部选项卡导航 -->
    <div class="sk-tabs">
      <button type="button" class="sk-tab-btn" :class="{ active: activeTab === 'matrix' }" @click="switchTab('matrix')">
        <el-icon class="mr-1"><Grid /></el-icon> 在岗人员技能分布矩阵
      </button>
      <button type="button" class="sk-tab-btn" :class="{ active: activeTab === 'entry' }" @click="switchTab('entry')">
        <el-icon class="mr-1"><Edit /></el-icon> 技能快速录入/申报
      </button>
      <button v-if="isForemanOrAdmin" type="button" class="sk-tab-btn" :class="{ active: activeTab === 'leaderboard' }" @click="switchTab('leaderboard')">
        <el-icon class="mr-1"><Trophy /></el-icon> 技能积分排行榜
      </button>
      <button v-if="isForemanOrAdmin" type="button" class="sk-tab-btn" :class="{ active: activeTab === 'workers' }" @click="switchTab('workers')">
        <el-icon class="mr-1"><UserFilled /></el-icon> 技能人员档案库
      </button>
      <button v-if="isForemanOrAdmin" type="button" class="sk-tab-btn" :class="{ active: activeTab === 'approve' }" @click="switchTab('approve')">
        <el-icon class="mr-1"><List /></el-icon> 审批中心
      </button>
    </div>

    <!-- 快捷等级选择浮层面板 (单元格点击唤出) -->
    <div v-show="pickerVisible" ref="pickerRef" class="sk-quick-picker" :style="pickerStyle" @click.stop>
      <button type="button" class="picker-btn picker-btn-del" @click="selectLevel(0)">清除 (-)</button>
      <button type="button" class="picker-btn lvl-badge lvl-1" @click="selectLevel(1)">L1</button>
      <button type="button" class="picker-btn lvl-badge lvl-2" @click="selectLevel(2)">L2</button>
      <button type="button" class="picker-btn lvl-badge lvl-3" @click="selectLevel(3)">L3</button>
      <button type="button" class="picker-btn lvl-badge lvl-4" @click="selectLevel(4)">L4</button>
      <button type="button" class="picker-btn lvl-badge lvl-5" @click="selectLevel(5)">L5</button>
    </div>

    <!-- ================= 视图 1: 技能矩阵分布 ================= -->
    <div v-show="activeTab === 'matrix'">
      <div class="sk-card">
        <div class="matrix-filter-bar">
          <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">在岗技能分布</span>
            <div style="display:flex; gap:6px; font-size:11.5px;">
              <span class="lvl-badge lvl-1">L1 了解</span>
              <span class="lvl-badge lvl-2">L2 熟练</span>
              <span class="lvl-badge lvl-3">L3 骨干</span>
              <span class="lvl-badge lvl-4">L4 能手</span>
              <span class="lvl-badge lvl-5">L5 专家</span>
            </div>
            <span class="tip-pill">
              💡 提示：点击您本人的技能等级单元格，可直接快捷修改并自动保存。修改他人技能请前往审批中心。
            </span>
          </div>
          <div style="display:flex; gap:8px;">
            <select v-model="filterTeam" class="sk-select" style="width:130px;" @change="loadMatrix(1)">
              <option value="">全部班组</option>
              <option value="装置装配">装置装配</option>
              <option value="装置调试">装置调试</option>
              <option value="装置包装">装置包装</option>
            </select>
            <input v-model="searchKeyword" type="text" class="sk-input" style="width:180px;" placeholder="搜索姓名或代码..." @keyup.enter="loadMatrix(1)" />
            <button type="button" class="btn-primary btn-sm" @click="loadMatrix(1)">
              <el-icon class="mr-1"><Search /></el-icon> 查询
            </button>
          </div>
        </div>

        <div class="sk-table-wrapper" v-loading="matrixLoading">
          <table class="sk-fixed-table">
            <thead>
              <tr>
                <th class="th-worker">姓名</th>
                <th class="th-prod">代码</th>
                <th class="th-desc">描述</th>
                <th v-for="p in fullProcessList" :key="p">{{ p }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, rIdx) in matrixRows" :key="rIdx">
                <td class="td-worker">{{ r.worker_name }}</td>
                <td class="td-prod">{{ r.product_code }}</td>
                <td class="td-desc" :title="r.description">{{ r.description || '-' }}</td>
                <td
                  v-for="p in fullProcessList"
                  :key="p"
                  :class="{ 'td-clickable': r.can_edit }"
                  @click="r.can_edit && openPicker($event, r, p)"
                >
                  <span v-if="(r.skills[p] || 0) > 0" class="lvl-badge" :class="`lvl-${r.skills[p]}`">
                    L{{ r.skills[p] }}
                  </span>
                  <span v-else style="color:#cbd5e1;">-</span>
                </td>
              </tr>
              <tr v-if="matrixRows.length === 0">
                <td :colspan="fullProcessList.length + 3" style="text-align:center; padding:35px; color:#888;">
                  暂无符合条件的技能记录
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="sk-pagination-bar">
          <div style="color:#64748b;">共 <strong>{{ matrixTotal }}</strong> 行技能组合，第 <strong>{{ matrixPage }}</strong> / <strong>{{ matrixTotalPages }}</strong> 页</div>
          <el-pagination v-model:current-page="matrixPage" :page-size="60" :total="matrixTotal" layout="prev, pager, next" @current-change="loadMatrix" />
        </div>
      </div>
    </div>

    <!-- ================= 视图 2: 快速录入/申报 ================= -->
    <div v-show="activeTab === 'entry'">
      <div class="sk-card" style="margin-bottom:12px;">
        <div class="sk-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;"><i class="fas fa-pen"></i> 技能等级评估录入</span>
        </div>
        <div style="padding:14px;">
          <div class="sk-single-row">
            <div style="width: 280px; min-width: 240px;">
              <select v-model="entryWorkerId" class="sk-select" style="width:100%;" :disabled="!isForemanOrAdmin">
                <option v-for="w in workerOptions" :key="w.id" :value="w.id">
                  {{ w.worker_name }} ({{ w.team }} - {{ isForemanOrAdmin ? w.identity : '本人申报' }})
                </option>
              </select>
            </div>
            <div style="display:flex; gap:6px; width: 310px; min-width: 260px;">
              <input v-model="entryProdCode" type="text" class="sk-input" style="flex:1;" placeholder="输入产品代码..." @keyup.enter="queryProductProcesses" />
              <button type="button" class="btn-primary btn-sm" :disabled="queryingProc" @click="queryProductProcesses">
                <el-icon class="mr-1"><Search /></el-icon> {{ queryingProc ? '查询中...' : '查询工序' }}
              </button>
            </div>
            <div v-if="entryProdDesc" class="sk-inline-desc">
              <strong>描述:</strong> <span>{{ entryProdDesc }}</span>
            </div>
          </div>

          <div v-if="queriedProcesses.length > 0" style="margin-top:14px;">
            <label class="form-label" style="margin-bottom:8px;">工艺工序技能评定 (L1~L5 等级)：</label>
            <div class="process-grid-box">
              <div v-for="p in queriedProcesses" :key="p" class="proc-grid-item">
                <label style="font-size:12px; font-weight:600; color:#2c3e50; margin:0;">{{ p }}</label>
                <select v-model="entrySkillValues[p]" class="sk-select" style="width:72px; height:28px; padding:1px 4px;">
                  <option value="">-</option>
                  <option value="1">L1</option>
                  <option value="2">L2</option>
                  <option value="3">L3</option>
                  <option value="4">L4</option>
                  <option value="5">L5</option>
                </select>
              </div>
            </div>
            <div style="text-align:right; margin-top:12px;">
              <button type="button" class="btn-success btn-sm" :disabled="submittingSkills" @click="submitSkillsEntry">
                <el-icon class="mr-1"><Check /></el-icon> 提交技能申报
              </button>
            </div>
          </div>

          <div v-else class="entry-empty-tip">
            在上方输入产品代码点击【查询工序】即可进行技能等级申报与评定。
          </div>

          <!-- 技能等级标准参考表 -->
          <div class="sk-reference-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <span style="font-weight:600; color:#2c3e50; font-size:13.5px;">技能等级评定标准与排产折算系数参考表</span>
              <span style="font-size:12px; color:#6c757d;">
                {{ isAdmin ? '(管理员可直接修改并保存标准)' : '(填写评级时请对照标准执行)' }}
              </span>
            </div>
            <div style="overflow-x:auto;">
              <table class="sk-fixed-table" style="min-width:100%; border:1px solid #dee2e6;">
                <thead>
                  <tr style="background:#f8f9fa;">
                    <th style="width:70px; text-align:center;">等级</th>
                    <th style="width:140px;">等级名称</th>
                    <th style="text-align:left;">核心定义与行为特征描述</th>
                    <th style="width:110px; text-align:center;">折算人力系数</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="l in levelConfigs" :key="l.level_code">
                    <td style="text-align:center;"><span class="lvl-badge" :class="`lvl-${l.level_code.replace('L','')}`">{{ l.level_code }}</span></td>
                    <td>
                      <input v-model="l.level_name" type="text" class="sk-input" :readonly="!isAdmin" style="width:100%; height:30px; font-weight:600;" />
                    </td>
                    <td style="text-align:left;">
                      <input v-model="l.level_desc" type="text" class="sk-input" :readonly="!isAdmin" style="width:100%; height:30px;" />
                    </td>
                    <td style="text-align:center;">
                      <input v-model.number="l.coefficient" type="number" step="0.01" class="sk-input" :readonly="!isAdmin" style="width:80px; height:30px; text-align:center; color:#409EFF; font-weight:bold;" />
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-if="isAdmin" style="text-align:right; margin-top:8px;">
              <button type="button" class="btn-primary btn-sm" @click="saveLevels">保存等级标准与系数修改</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ================= 视图 3: 积分排行榜 ================= -->
    <div v-show="activeTab === 'leaderboard'">
      <div class="sk-banner">
        <div>
          <div style="font-size:12px; color:#6c757d; font-weight:500;">技能积分核算规则</div>
          <div style="font-size:12.5px; font-weight:bold; color:#007bff; margin-top:2px;">
            L1=0分 | L2=0.5分 | L3=1.0分 | L4=1.5分 | L5=2.0分
          </div>
        </div>
      </div>
      <div class="sk-card">
        <div class="sk-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;"><i class="fas fa-trophy"></i> 人员技能积分与熟练度排行榜</span>
        </div>
        <div class="sk-table-wrapper" v-loading="boardLoading">
          <table class="sk-fixed-table" style="min-width:100%;">
            <thead>
              <tr>
                <th style="width:60px; text-align:center;">排名</th>
                <th>姓名</th>
                <th>班组</th>
                <th>身份类别</th>
                <th style="font-weight:bold; color:#007bff; text-align:center;">总积分</th>
                <th style="text-align:center;">已掌握工序数 (≥L2)</th>
                <th style="text-align:center;">掌握产品数</th>
                <th>最擅长产品</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, idx) in leaderboardList" :key="item.id">
                <td style="text-align:center;"><strong>#{{ idx + 1 }}</strong></td>
                <td><strong>{{ item.name }}</strong></td>
                <td>{{ item.team }}</td>
                <td><span class="badge" style="background:#e8f4fd; color:#007bff;">{{ item.identity }}</span></td>
                <td style="text-align:center; font-weight:bold; font-size:14px; color:#28a745;">{{ Number(item.total_score).toFixed(1) }}</td>
                <td style="text-align:center;">{{ item.process_count }}</td>
                <td style="text-align:center;">{{ item.product_count }}</td>
                <td style="color:#059669; font-weight:500;">{{ item.best_product }}</td>
              </tr>
              <tr v-if="leaderboardList.length === 0">
                <td colspan="8" style="text-align:center; padding:35px; color:#888;">暂无排行数据</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ================= 视图 4: 人员档案库 ================= -->
    <div v-show="activeTab === 'workers'">
      <div class="sk-card">
        <div class="sk-card-header" style="display:flex; justify-content:space-between; align-items:center;">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;"><i class="fas fa-user-tag"></i> 人员档案库</span>
          <button type="button" class="btn-success btn-sm" @click="openAddWorkerModal">
            <el-icon class="mr-1"><Plus /></el-icon> 新增生产人员
          </button>
        </div>
        <div class="sk-table-wrapper" v-loading="workersLoading">
          <table class="sk-fixed-table" style="min-width:100%; text-align:left;">
            <thead>
              <tr>
                <th style="width:110px;">工号/学号</th>
                <th style="width:110px;">人员姓名</th>
                <th style="width:110px;">所属班组</th>
                <th style="width:110px;">身份类别</th>
                <th style="width:85px; text-align:center;">状态</th>
                <th style="width:120px;">直属责任班长</th>
                <th>绑定系统账号</th>
                <th style="width:90px; text-align:center;">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="w in workersList" :key="w.id">
                <td><strong style="font-family:monospace;">{{ w.worker_no }}</strong></td>
                <td><strong>{{ w.worker_name }}</strong></td>
                <td>{{ w.team }}</td>
                <td>
                  <span class="badge" :style="{ background: w.identity==='实习学生'?'#fff3cd':'#e8f4fd', color: w.identity==='实习学生'?'#856404':'#007bff' }">
                    {{ w.identity }}
                  </span>
                </td>
                <td style="text-align:center;">
                  <span class="badge" :style="{ background: w.work_status==='在岗'?'#d4edda':'#f8d7da', color: w.work_status==='在岗'?'#155724':'#721c24' }">
                    {{ w.work_status }}
                  </span>
                </td>
                <td>{{ w.foreman_name || '班组直管' }}</td>
                <td>{{ w.account_username ? `${w.account_fullname} (@${w.account_username})` : '未关联' }}</td>
                <td style="text-align:center;">
                  <button type="button" class="btn-primary btn-mini" @click="openEditWorkerModal(w)">编辑</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ================= 视图 5: 审批中心 ================= -->
    <div v-show="activeTab === 'approve'">
      <div class="sk-card" style="margin-bottom:14px;">
        <div class="sk-card-header">
          <span style="font-size:14px; font-weight:600; color:#2c3e50;">待审核技能清单 ({{ pendingList.length }})</span>
        </div>
        <div style="padding:10px 14px;">
          <div class="app-batch-bar">
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
              <label style="cursor:pointer; margin-bottom:0; font-weight:600;">
                <input type="checkbox" v-model="allPendingChecked" @change="toggleAllPending" /> 全选
              </label>
            </div>
            <div style="display:flex; gap:6px;">
              <button type="button" class="btn-success btn-sm" @click="batchReview('approve')"><el-icon class="mr-1"><Check /></el-icon> 集中批量通过</button>
              <button type="button" class="btn-danger btn-sm" @click="batchReview('reject')"><el-icon class="mr-1"><Close /></el-icon> 集中批量驳回</button>
            </div>
          </div>

          <div class="sk-table-wrapper" style="min-height:200px; max-height:340px;">
            <table class="sk-fixed-table" style="min-width:100%;">
              <thead>
                <tr style="background:#f0f0f0;">
                  <th style="width:42px; text-align:center;">选择</th>
                  <th style="width:80px;">员工</th>
                  <th style="width:85px;">班组</th>
                  <th style="width:105px;">产品代码</th>
                  <th style="width:115px;">工序</th>
                  <th style="width:75px; text-align:center;">申请等级</th>
                  <th style="width:125px; text-align:center;">更新时间</th>
                  <th style="width:115px; text-align:center;">操作</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in pendingList" :key="r.id">
                  <td style="text-align:center;"><input type="checkbox" v-model="selectedPendingIds" :value="r.id" /></td>
                  <td><strong>{{ r.worker_name }}</strong></td>
                  <td>{{ r.team }}</td>
                  <td><code>{{ r.product_code }}</code></td>
                  <td>{{ r.process_code }}</td>
                  <td style="text-align:center;"><span class="lvl-badge" :class="`lvl-${r.skill_level}`">L{{ r.skill_level }}</span></td>
                  <td style="text-align:center; font-size:11.5px; color:#64748b;">{{ r.updated_at ? r.updated_at.substring(5, 16) : '-' }}</td>
                  <td style="text-align:center; white-space:nowrap;">
                    <button type="button" class="btn-success btn-mini" @click="singleReview(r.id, 'approve')">通过</button>
                    <button type="button" class="btn-danger btn-mini" style="margin-left:4px;" @click="singleReview(r.id, 'reject')">驳回</button>
                  </td>
                </tr>
                <tr v-if="pendingList.length === 0">
                  <td colspan="8" style="text-align:center; padding:30px; color:#28a745;">暂无待审核记录</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- 人员档案弹窗 -->
    <el-dialog v-model="workerModalVisible" :title="workerModalTitle" width="560px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;">
        <div style="margin-bottom:12px;">
          <label class="form-label">工号/学号 *</label>
          <input v-model="workerForm.worker_no" type="text" class="sk-input" style="width:100%;" required />
        </div>
        <div style="margin-bottom:12px;">
          <label class="form-label">人员姓名 *</label>
          <input v-model="workerForm.worker_name" type="text" class="sk-input" style="width:100%;" required />
        </div>
        <div style="margin-bottom:12px;">
          <label class="form-label">所属班组 *</label>
          <select v-model="workerForm.team" class="sk-select" style="width:100%;">
            <option value="装置装配">装置装配</option>
            <option value="装置调试">装置调试</option>
            <option value="装置包装">装置包装</option>
          </select>
        </div>
        <div style="margin-bottom:12px;">
          <label class="form-label">身份类别 *</label>
          <select v-model="workerForm.identity" class="sk-select" style="width:100%;">
            <option value="正式员工">正式员工</option>
            <option value="实习学生">实习学生 (班长代填)</option>
            <option value="外协人员">外协人员</option>
          </select>
        </div>
        <div style="margin-bottom:12px;">
          <label class="form-label">直属责任班长</label>
          <select v-model="workerForm.foreman_id" class="sk-select" style="width:100%;">
            <option :value="null">-- 按班组自动匹配 --</option>
            <option v-for="u in foremanUsers" :key="u.id" :value="u.id">{{ u.full_name || u.username }}</option>
          </select>
        </div>
        <div style="margin-bottom:12px;">
          <label class="form-label">在岗状态 *</label>
          <select v-model="workerForm.work_status" class="sk-select" style="width:100%;">
            <option value="在岗">在岗</option>
            <option value="请假">请假</option>
            <option value="离职">离职</option>
          </select>
        </div>
      </div>
      <template #footer>
        <el-button @click="workerModalVisible = false">取消</el-button>
        <el-button type="primary" :loading="savingWorker" @click="submitWorkerForm">保存档案</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Grid, Edit, Trophy, UserFilled, List, Search, Plus, Check, Close
} from '@element-plus/icons-vue';
import request from '@/api/request';

const activeTab = ref('matrix');
const isAdmin = ref(false);
const isForemanOrAdmin = ref(false);

const fullProcessList = ref([]);
const foremanUsers = ref([]);

// 矩阵视图状态
const matrixLoading = ref(false);
const matrixRows = ref([]);
const matrixTotal = ref(0);
const matrixPage = ref(1);
const matrixTotalPages = ref(1);
const filterTeam = ref('');
const searchKeyword = ref('');

// 快速等级选择气泡
const pickerVisible = ref(false);
const pickerRef = ref(null);
const pickerStyle = reactive({ top: '0px', left: '0px' });
const activeCellData = ref(null);

// 录入视图状态
const workerOptions = ref([]);
const entryWorkerId = ref(null);
const entryProdCode = ref('');
const entryProdDesc = ref('');
const queryingProc = ref(false);
const queriedProcesses = ref([]);
const entrySkillValues = reactive({});
const submittingSkills = ref(false);
const levelConfigs = ref([]);

// 排行榜
const boardLoading = ref(false);
const leaderboardList = ref([]);

// 人员档案
const workersLoading = ref(false);
const workersList = ref([]);
const workerModalVisible = ref(false);
const savingWorker = ref(false);
const workerModalTitle = ref('新增生产人员');
const workerForm = reactive({
  id: 0,
  worker_no: '',
  worker_name: '',
  team: '装置装配',
  identity: '正式员工',
  work_status: '在岗',
  foreman_id: null
});

// 审批中心
const pendingList = ref([]);
const selectedPendingIds = ref([]);
const allPendingChecked = ref(false);

const switchTab = (tab) => {
  activeTab.value = tab;
  pickerVisible.value = false;
  if (tab === 'matrix') loadMatrix(1);
  else if (tab === 'entry') { loadEntryWorkers(); loadLevels(); }
  else if (tab === 'leaderboard') loadLeaderboard();
  else if (tab === 'workers') loadWorkers();
  else if (tab === 'approve') loadApproveList();
};

const initModule = async () => {
  try {
    const res = await request.get('/skill/init');
    if (res.data) {
      fullProcessList.value = res.data.processes || [];
      isAdmin.value = !!res.data.is_admin;
      isForemanOrAdmin.value = !!(res.data.is_admin || res.data.is_foreman);
      foremanUsers.value = res.data.foreman_users || [];
      levelConfigs.value = res.data.levels || [];
    }
  } catch (e) {}
  loadMatrix(1);
};

const loadMatrix = async (p = 1) => {
  matrixPage.value = p;
  matrixLoading.value = true;
  pickerVisible.value = false;
  try {
    const res = await request.get('/skill/matrix_overview', {
      params: {
        page: p,
        team: filterTeam.value,
        search: searchKeyword.value.trim()
      }
    });
    if (res.data) {
      matrixRows.value = res.data.rows || [];
      matrixTotal.value = res.data.total || 0;
      matrixTotalPages.value = res.data.total_pages || 1;
    }
  } finally {
    matrixLoading.value = false;
  }
};

const openPicker = (event, row, proc) => {
  event.stopPropagation();
  activeCellData.value = { row, proc };
  const rect = event.currentTarget.getBoundingClientRect();
  pickerStyle.top = `${rect.bottom + window.scrollY + 2}px`;
  pickerStyle.left = `${Math.max(10, rect.left + window.scrollX - 40)}px`;
  pickerVisible.value = true;
};

const closePicker = () => {
  pickerVisible.value = false;
  activeCellData.value = null;
};

const selectLevel = async (level) => {
  if (!activeCellData.value) return;
  const { row, proc } = activeCellData.value;
  closePicker();

  try {
    const res = await request.post('/skill/quick_update_cell', {
      worker_id: row.worker_id,
      product_code: row.product_code,
      process_code: proc,
      level: level
    });
    ElMessage.success(res.message || '更新成功');
    row.skills[proc] = level;
  } catch (e) {}
};

// 录入相关
const loadEntryWorkers = async () => {
  try {
    const res = await request.get('/skill/list_workers', { params: { status: '在岗' } });
    if (res.data) {
      workerOptions.value = res.data.list || [];
      if (workerOptions.value.length > 0 && !entryWorkerId.value) {
        entryWorkerId.value = workerOptions.value[0].id;
      }
    }
  } catch (e) {}
};

const loadLevels = async () => {
  try {
    const res = await request.get('/skill/get_levels');
    if (res.data) {
      levelConfigs.value = res.data.levels || [];
    }
  } catch (e) {}
};

const saveLevels = async () => {
  try {
    const res = await request.post('/skill/save_levels', { configs: levelConfigs.value });
    ElMessage.success(res.message || '保存成功');
    loadLevels();
  } catch (e) {}
};

const queryProductProcesses = async () => {
  const code = entryProdCode.value.trim();
  if (!code) return ElMessage.warning('请输入产品物料代码！');

  queryingProc.value = true;
  try {
    const res = await request.get('/skill/get_product_processes', { params: { mater_no: code } });
    if (res.data) {
      entryProdDesc.value = res.data.description || '';
      queriedProcesses.value = res.data.processes || [];
      queriedProcesses.value.forEach(p => {
        entrySkillValues[p] = '';
      });
    }
  } finally {
    queryingProc.value = false;
  }
};

const submitSkillsEntry = async () => {
  if (!entryWorkerId.value || !entryProdCode.value.trim()) {
    return ElMessage.warning('请确认评估人员并输入产品代码！');
  }

  const skillsData = [];
  queriedProcesses.value.forEach(p => {
    if (entrySkillValues[p]) {
      skillsData.push({ process_code: p, level: parseInt(entrySkillValues[p]) });
    }
  });

  if (skillsData.length === 0) {
    return ElMessage.warning('请至少为一个工序评定等级！');
  }

  submittingSkills.value = true;
  try {
    const res = await request.post('/skill/submit_skills', {
      worker_id: entryWorkerId.value,
      product_code: entryProdCode.value.trim(),
      skills_data: skillsData
    });
    ElMessage.success(res.message || '提交成功');
    entryProdCode.value = '';
    entryProdDesc.value = '';
    queriedProcesses.value = [];
    switchTab('matrix');
  } finally {
    submittingSkills.value = false;
  }
};

// 排行榜
const loadLeaderboard = async () => {
  boardLoading.value = true;
  try {
    const res = await request.get('/skill/leaderboard');
    if (res.data) {
      leaderboardList.value = res.data.rank_list || [];
    }
  } finally {
    boardLoading.value = false;
  }
};

// 人员档案
const loadWorkers = async () => {
  workersLoading.value = true;
  try {
    const res = await request.get('/skill/list_workers');
    if (res.data) {
      workersList.value = res.data.list || [];
    }
  } finally {
    workersLoading.value = false;
  }
};

const openAddWorkerModal = () => {
  workerModalTitle.value = '新增生产人员';
  Object.assign(workerForm, {
    id: 0,
    worker_no: '',
    worker_name: '',
    team: '装置装配',
    identity: '正式员工',
    work_status: '在岗',
    foreman_id: null
  });
  workerModalVisible.value = true;
};

const openEditWorkerModal = (w) => {
  workerModalTitle.value = `编辑档案 [${w.worker_name}]`;
  Object.assign(workerForm, {
    id: w.id,
    worker_no: w.worker_no,
    worker_name: w.worker_name,
    team: w.team,
    identity: w.identity,
    work_status: w.work_status,
    foreman_id: w.foreman_id || null
  });
  workerModalVisible.value = true;
};

const submitWorkerForm = async () => {
  if (!workerForm.worker_no.trim() || !workerForm.worker_name.trim()) {
    return ElMessage.warning('工号与姓名不能为空！');
  }

  savingWorker.value = true;
  try {
    const res = await request.post('/skill/save_worker', workerForm);
    ElMessage.success(res.message || '保存成功');
    workerModalVisible.value = false;
    loadWorkers();
  } finally {
    savingWorker.value = false;
  }
};

// 审批中心
const loadApproveList = async () => {
  try {
    const res = await request.get('/skill/list_pending');
    if (res.data) {
      pendingList.value = res.data.pending_list || [];
      selectedPendingIds.value = [];
      allPendingChecked.value = false;
    }
  } catch (e) {}
};

const toggleAllPending = () => {
  if (allPendingChecked.value) {
    selectedPendingIds.value = pendingList.value.map(p => p.id);
  } else {
    selectedPendingIds.value = [];
  }
};

const singleReview = async (id, decision) => {
  try {
    const res = await request.post('/skill/review_skills', { ids: [id], decision });
    ElMessage.success(res.message || '处理成功');
    loadApproveList();
  } catch (e) {}
};

const batchReview = async (decision) => {
  if (selectedPendingIds.value.length === 0) {
    return ElMessage.warning('请至少勾选一条待审批记录！');
  }
  try {
    const res = await request.post('/skill/review_skills', { ids: selectedPendingIds.value, decision });
    ElMessage.success(res.message || '处理成功');
    loadApproveList();
  } catch (e) {}
};

onMounted(() => {
  initModule();
  document.addEventListener('click', closePicker);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', closePicker);
});
</script>

<style scoped>
.sk-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.sk-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  margin-bottom: 12px;
  overflow: hidden;
}
.sk-card-header {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}

.sk-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  border-bottom: 2px solid #dee2e6;
  flex-wrap: wrap;
}
.sk-tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
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
}
.sk-tab-btn.active {
  color: #007bff;
  border-bottom-color: #007bff;
}

.matrix-filter-bar {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.tip-pill {
  font-size: 11.5px;
  color: #0284c7;
  background: #e0f2fe;
  padding: 2px 8px;
  border-radius: 4px;
}

.sk-single-row {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.sk-inline-desc {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 8px;
  background: #f0f7ff;
  border: 1px solid #d0e3ff;
  border-radius: 4px;
  padding: 0 12px;
  height: 33px;
  font-size: 12.5px;
  color: #2c3e50;
  min-width: 240px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.process-grid-box {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 8px;
  margin-bottom: 12px;
}
.proc-grid-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #f8f9fa;
  padding: 5px 10px;
  border-radius: 4px;
  border: 1px solid #dee2e6;
}

.entry-empty-tip {
  text-align: center;
  padding: 25px;
  color: #888;
  background: #fafbfc;
  border-radius: 4px;
  border: 1px dashed #dee2e6;
  margin-bottom: 12px;
  font-size: 12.5px;
}

.sk-reference-card {
  background: #ffffff;
  border: 1px solid #e3e6f0;
  border-radius: 6px;
  padding: 14px 18px;
  margin-top: 14px;
}

.sk-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f0f7ff;
  border: 1px solid #d0e3ff;
  color: #2c3e50;
  border-radius: 6px;
  padding: 10px 18px;
  margin-bottom: 12px;
}

.app-batch-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f8f9fa;
  padding: 8px 12px;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  margin-bottom: 10px;
  font-size: 12.5px;
}

.sk-table-wrapper {
  max-height: calc(100vh - 350px);
  min-height: 320px;
  overflow-y: auto;
  overflow-x: auto;
  border-top: 1px solid #dee2e6;
}
.sk-fixed-table {
  width: 100% !important;
  min-width: 1300px;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: auto;
  font-size: 12.5px;
  text-align: center;
  color: #333;
}
.sk-fixed-table thead th {
  position: sticky;
  top: 0;
  background: #e6e9ec;
  z-index: 10;
  padding: 7px 4px;
  border-bottom: 2px solid #dee2e6;
  border-right: 1px solid #dee2e6;
  white-space: nowrap;
  font-weight: 600;
  color: #2c3e50;
}
.sk-fixed-table tbody td {
  padding: 5px 4px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
}
.sk-fixed-table tbody tr:hover { background-color: #f8fafc; }

.td-clickable {
  cursor: pointer !important;
  transition: background 0.15s ease;
}
.td-clickable:hover {
  background-color: #e0f2fe !important;
  outline: 1px dashed #0284c7;
}

th.th-worker, td.td-worker { position: sticky; left: 0; z-index: 5; background: #f8f9fa; font-weight: 600; text-align: center; width: 75px; min-width: 75px; }
th.th-prod, td.td-prod { position: sticky; left: 75px; z-index: 5; background: #f8f9fa; font-family: monospace; font-weight: 600; text-align: center; width: 95px; min-width: 95px; }
th.th-desc, td.td-desc { position: sticky; left: 170px; z-index: 5; background: #fafafa; text-align: left; max-width: 200px; min-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

thead th.th-worker, thead th.th-prod, thead th.th-desc { z-index: 20 !important; background: #d8dde2 !important; }

.lvl-badge { display: inline-block; padding: 2px 5px; border-radius: 4px; font-size: 11.5px; font-weight: 700; line-height: 1.2; user-select: none; }
.lvl-1 { background-color: #e2e8f0 !important; color: #475569 !important; border: 1px solid #cbd5e1; }
.lvl-2 { background-color: #bae6fd !important; color: #0369a1 !important; border: 1px solid #7dd3fc; }
.lvl-3 { background-color: #86efac !important; color: #14532d !important; border: 1px solid #4ade80; }
.lvl-4 { background-color: #fde047 !important; color: #713f12 !important; border: 1px solid #facc15; font-weight: 800; }
.lvl-5 { background-color: #fca5a5 !important; color: #7f1d1d !important; border: 1px solid #f87171; font-weight: 800; }

.badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; }

.sk-quick-picker {
  position: fixed;
  z-index: 99999;
  background: #ffffff;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  box-shadow: 0 8px 20px rgba(0,0,0,0.18);
  padding: 6px;
  display: flex;
  gap: 4px;
}
.picker-btn {
  border: none;
  background: #f1f5f9;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: transform 0.1s;
}
.picker-btn:hover { transform: scale(1.08); }
.picker-btn-del { background: #fee2e2; color: #991b1b; }

.sk-pagination-bar {
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

.sk-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.sk-input:focus { border-color: #80bdff; }

.sk-select {
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