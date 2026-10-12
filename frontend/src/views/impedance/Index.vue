<template>
  <div class="imp-container">
    <!-- 顶部标题卡片 -->
    <div class="imp-card" style="padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Odometer /></el-icon>
          阻抗测试记录系统
        </span>
        <div style="display:flex; gap:6px;">
          <button v-if="isAdmin" type="button" class="btn-warning btn-sm" @click="openPermModal">
            <el-icon class="mr-1"><UserFilled /></el-icon> 权限管理
          </button>
          <button v-if="canInput" type="button" class="btn-success btn-sm" @click="openAddModal">
            <el-icon class="mr-1"><Plus /></el-icon> 添加测试记录
          </button>
          <button type="button" class="btn-primary btn-sm" @click="exportData">
            <el-icon class="mr-1"><Download /></el-icon> 导出数据
          </button>
        </div>
      </div>
    </div>

    <!-- 主表格卡片 -->
    <div class="imp-card imp-main-card">
      <div class="imp-toolbar">
        <div class="imp-search-area">
          <input
            v-model="searchKeyword"
            type="text"
            class="imp-input"
            style="flex:1; min-width:260px;"
            placeholder="输入 SN、工单、代码、描述、操作人或结论搜索..."
            @keyup.enter="loadTable(1)"
          />
          <button type="button" class="btn-primary btn-sm" @click="loadTable(1)">
            <el-icon class="mr-1"><Search /></el-icon> 搜索
          </button>
          <button type="button" class="btn-outline btn-sm" @click="resetSearch">
            <el-icon class="mr-1"><Refresh /></el-icon> 重置
          </button>
        </div>
      </div>

      <!-- 批量操作栏 -->
      <div v-if="selectedIds.length > 0 && isAdmin" class="imp-batch-bar">
        <span style="color:#0369a1;">已勾选 <strong style="color:#409EFF;">{{ selectedIds.length }}</strong> 条记录</span>
        <div style="display:flex; gap:6px;">
          <button type="button" class="btn-danger btn-mini" @click="batchDelete">
            <el-icon class="mr-1"><Delete /></el-icon> 批量删除
          </button>
          <button type="button" class="btn-outline btn-mini" @click="selectedIds = []">取消选择</button>
        </div>
      </div>

      <!-- 表格主体 -->
      <div class="imp-table-wrapper" v-loading="loading">
        <table class="imp-dense-table">
          <thead>
            <tr>
              <th style="width: 30px;"><input type="checkbox" v-model="allSelected" @change="toggleSelectAll" /></th>
              <th style="width: 158px;">SN</th>
              <th style="width: 75px;">工单</th>
              <th style="width: 80px;">代码</th>
              <th>描述</th>
              <th v-for="i in 9" :key="'p' + i" style="width: 45px;">保阻{{ i }}</th>
              <th v-for="i in 3" :key="'c' + i" style="width: 45px;">测阻{{ i }}</th>
              <th style="width: 54px;">保误差</th>
              <th style="width: 54px;">测误差</th>
              <th style="width: 50px;">保结论</th>
              <th style="width: 50px;">测结论</th>
              <th style="width: 64px;">操作人</th>
              <th style="width: 44px;">标准</th>
              <th style="width: 76px;">创建时间</th>
              <th style="width: 130px; text-align:center;">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in recordList" :key="r.id" :class="{ selected: selectedIds.includes(r.id) }">
              <td style="text-align:center;"><input type="checkbox" v-model="selectedIds" :value="r.id" /></td>
              <td :title="r.sn"><strong style="font-family:monospace; color:#2c3e50;">{{ r.sn }}</strong></td>
              <td :title="r.work_order"><strong>{{ r.work_order }}</strong></td>
              <td :title="r.code"><span style="font-family:monospace;">{{ r.code || '-' }}</span></td>
              <td :title="r.description" style="text-align:left;">{{ r.description || '-' }}</td>
              <td v-for="i in 9" :key="'val_p' + i" class="imp-num-cell">
                {{ r['protect_impedance' + i] !== null ? Number(r['protect_impedance' + i]).toFixed(4) : '-' }}
              </td>
              <td v-for="i in 3" :key="'val_c' + i" class="imp-num-cell">
                {{ r['control_impedance' + i] !== null ? Number(r['control_impedance' + i]).toFixed(4) : '-' }}
              </td>
              <td class="imp-num-cell" :style="getErrorStyle(r.protect_error, r.error_standard)">
                {{ r.protect_error !== null ? Number(r.protect_error).toFixed(4) + '%' : '-' }}
              </td>
              <td class="imp-num-cell" :style="getErrorStyle(r.control_error, r.error_standard)">
                {{ r.control_error !== null ? Number(r.control_error).toFixed(4) + '%' : '-' }}
              </td>
              <td><span class="badge" :style="getConclusionBadgeStyle(r.protect_conclusion)">{{ r.protect_conclusion }}</span></td>
              <td><span class="badge" :style="getConclusionBadgeStyle(r.control_conclusion)">{{ r.control_conclusion }}</span></td>
              <td :title="r.operator">{{ r.operator }}</td>
              <td><span class="badge" style="background:#e8f4fd; color:#409EFF; font-size:10px;">≤{{ r.error_standard || 10 }}%</span></td>
              <td style="font-size:11.5px; color:#64748b;">{{ r.created_at ? r.created_at.substring(5, 16) : '-' }}</td>
              <td class="imp-op-cell">
                <div class="imp-op-btns">
                  <button type="button" class="btn-outline btn-mini" @click="openDetail(r)">详情</button>
                  <button v-if="isAdmin" type="button" class="btn-primary btn-mini" @click="openEditModal(r)">编辑</button>
                  <button v-if="isAdmin" type="button" class="btn-danger btn-mini" @click="deleteSingle(r)">删除</button>
                </div>
              </td>
            </tr>
            <tr v-if="recordList.length === 0">
              <td colspan="26" style="padding:40px; color:#888;">暂无阻抗测试记录</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 分页栏 -->
      <div class="imp-pagination-bar">
        <div style="color:#64748b;">共 <strong>{{ totalRecords }}</strong> 条阻抗测试记录，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页</div>
        <el-pagination v-model:current-page="curPage" :page-size="30" :total="totalRecords" layout="prev, pager, next" @current-change="loadTable" />
      </div>
    </div>

    <!-- 弹窗 1: 添加/编辑测试记录 -->
    <el-dialog v-model="modalVisible" :title="modalTitle" width="860px" destroy-on-close>
      <el-form label-width="90px" size="small">
        <div class="form-grid-4">
          <el-form-item label="SN (22位) *" required>
            <div style="position:relative; width:100%;">
              <input
                ref="snInputRef"
                v-model="form.sn"
                type="text"
                maxlength="22"
                class="imp-input"
                style="width:100%; font-family:monospace;"
                placeholder="输入22位SN后回车..."
                :readonly="!!editingId"
                @blur="fetchBatchInfo"
                @keyup.enter="fetchBatchInfo"
              />
              <span v-if="searchingSn" style="position:absolute; right:8px; top:6px; color:#409EFF; font-size:11px;">查询中...</span>
            </div>
          </el-form-item>
          <el-form-item label="工单号 *" required>
            <input v-model="form.work_order" type="text" class="imp-input" style="width:100%;" placeholder="工单号..." />
          </el-form-item>
          <el-form-item label="产品代码">
            <input v-model="form.code" type="text" class="imp-input" readonly style="background:#f8f9fa; width:100%;" />
          </el-form-item>
          <el-form-item label="物料描述">
            <input v-model="form.description" type="text" class="imp-input" readonly style="background:#f8f9fa; width:100%;" />
          </el-form-item>
        </div>

        <div v-if="snHistoryTip" style="font-size:12px; color:#E6A23C; margin-bottom:10px; background:#fdf6ec; padding:6px 12px; border-radius:4px; border:1px solid #faecd8;">
          {{ snHistoryTip }}
        </div>

        <div class="imp-section-box" style="display:flex; justify-content:space-between; align-items:center;">
          <div style="font-weight:600; font-size:12.5px; color:#2c3e50;">误差合格标准设定：</div>
          <el-radio-group v-model="form.error_standard" :disabled="!!editingId" @change="calculateErrors">
            <el-radio :label="10">误差 ≤ 10% (默认)</el-radio>
            <el-radio :label="5">误差 ≤ 5%</el-radio>
          </el-radio-group>
        </div>

        <!-- 保护阻抗 (1-9) -->
        <div class="imp-section-box">
          <div style="font-weight:600; font-size:12.5px; color:#2c3e50; margin-bottom:6px;">
            保护阻抗 (Ω) <small style="color:#888; font-weight:normal;">(录入 ≥ 2 个有效值将自动计算极值误差)</small>
          </div>
          <div class="imp-grid-9">
            <div v-for="i in 9" :key="'inp_p' + i">
              <label style="font-size:11px; display:block; color:#666; margin-bottom:2px;">阻抗{{ i }}</label>
              <input
                v-model="form['protect_impedance' + i]"
                type="number"
                step="0.0001"
                min="0.0001"
                class="imp-val-input"
                placeholder="选填"
                @input="calculateErrors"
              />
            </div>
          </div>
        </div>

        <!-- 测控阻抗 (1-3) -->
        <div class="imp-section-box">
          <div style="font-weight:600; font-size:12.5px; color:#2c3e50; margin-bottom:6px;">
            测控阻抗 (Ω) <small style="color:#888; font-weight:normal;">(录入 ≥ 2 个有效值将自动计算极值误差)</small>
          </div>
          <div class="imp-grid-3">
            <div v-for="i in 3" :key="'inp_c' + i">
              <label style="font-size:11px; display:block; color:#666; margin-bottom:2px;">测控{{ i }}</label>
              <input
                v-model="form['control_impedance' + i]"
                type="number"
                step="0.0001"
                min="0.0001"
                class="imp-val-input"
                placeholder="选填"
                @input="calculateErrors"
              />
            </div>
          </div>
        </div>

        <!-- 实时计算结果看板 -->
        <div class="imp-calc-box">
          <div>
            <div style="font-size:11.5px; color:#666;">保护误差</div>
            <div style="font-size:15px; font-weight:bold; color:#409EFF;">
              {{ calcRes.protect_error !== null ? Number(calcRes.protect_error).toFixed(4) + '%' : '-' }}
            </div>
          </div>
          <div>
            <div style="font-size:11.5px; color:#666;">测控误差</div>
            <div style="font-size:15px; font-weight:bold; color:#409EFF;">
              {{ calcRes.control_error !== null ? Number(calcRes.control_error).toFixed(4) + '%' : '-' }}
            </div>
          </div>
          <div>
            <div style="font-size:11.5px; color:#666;">保护结论</div>
            <div style="font-size:13.5px; font-weight:bold;" :style="getConclusionColor(calcRes.protect_conclusion)">
              {{ calcRes.protect_conclusion }}
            </div>
          </div>
          <div>
            <div style="font-size:11.5px; color:#666;">测控结论</div>
            <div style="font-size:13.5px; font-weight:bold;" :style="getConclusionColor(calcRes.control_conclusion)">
              {{ calcRes.control_conclusion }}
            </div>
          </div>
        </div>
      </el-form>

      <template #footer>
        <el-button @click="modalVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitSave(false)">保存阻抗记录</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 2: 详情展示 -->
    <el-dialog v-model="detailModalVisible" title="阻抗测试详细数据" width="620px" destroy-on-close>
      <div v-if="detailRecord" style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:12.5px;">
        <div><strong>SN：</strong> <strong style="font-family:monospace;">{{ detailRecord.sn }}</strong></div>
        <div><strong>工单号：</strong> <strong>{{ detailRecord.work_order }}</strong></div>
        <div><strong>产品代码：</strong> <span style="font-family:monospace;">{{ detailRecord.code || '-' }}</span></div>
        <div><strong>误差标准：</strong> ≤{{ detailRecord.error_standard || 10 }}%</div>
        <div style="grid-column: span 2;">
          <strong>物料描述：</strong>
          <div class="info-block">{{ detailRecord.description || '-' }}</div>
        </div>
        <div style="grid-column: span 2;">
          <strong>保护阻抗明细：</strong>
          <div class="info-block" style="display:flex; flex-wrap:wrap; gap:8px;">
            <span v-for="i in 9" :key="'dt_p' + i" v-show="detailRecord['protect_impedance' + i] !== null">
              保护{{ i }}: <strong>{{ detailRecord['protect_impedance' + i] }}</strong> Ω
            </span>
          </div>
        </div>
        <div style="grid-column: span 2;">
          <strong>测控阻抗明细：</strong>
          <div class="info-block" style="display:flex; flex-wrap:wrap; gap:8px;">
            <span v-for="i in 3" :key="'dt_c' + i" v-show="detailRecord['control_impedance' + i] !== null">
              测控{{ i }}: <strong>{{ detailRecord['control_impedance' + i] }}</strong> Ω
            </span>
          </div>
        </div>
        <div><strong>保护误差：</strong> {{ detailRecord.protect_error !== null ? Number(detailRecord.protect_error).toFixed(4) + '%' : '-' }} ({{ detailRecord.protect_conclusion }})</div>
        <div><strong>测控误差：</strong> {{ detailRecord.control_error !== null ? Number(detailRecord.control_error).toFixed(4) + '%' : '-' }} ({{ detailRecord.control_conclusion }})</div>
        <div><strong>操作人：</strong> {{ detailRecord.operator }}</div>
        <div><strong>创建时间：</strong> {{ detailRecord.created_at }}</div>
      </div>
    </el-dialog>

    <!-- 权限管理弹窗（仅管理员） -->
    <el-dialog v-if="isAdmin" v-model="permModalVisible" title="阻抗模块权限管理" width="650px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;" v-loading="permLoading">
        <p style="margin:0 0 8px; color:#909399;">未勾选「录入权限」的用户无法提交阻抗记录；「查看权限」控制查看与导出。管理员默认拥有全部权限。</p>
        <div style="max-height:380px; overflow-y:auto; border:1px solid #dee2e6;">
          <table style="min-width:100%; width:100%; border-collapse:collapse;">
            <thead>
              <tr style="background:#f5f7fa;">
                <th style="text-align:left; padding:6px 10px;">用户名</th>
                <th style="text-align:left; padding:6px 10px;">姓名</th>
                <th style="text-align:center; padding:6px 10px;">查看权限</th>
                <th style="text-align:center; padding:6px 10px;">录入权限</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in permUserList" :key="u.id">
                <td style="padding:6px 10px; border-top:1px solid #ebeef5;"><strong>{{ u.username }}</strong></td>
                <td style="padding:6px 10px; border-top:1px solid #ebeef5;">{{ u.full_name || '-' }}</td>
                <td style="text-align:center; padding:6px 10px; border-top:1px solid #ebeef5;">
                  <input type="checkbox" v-model="u.can_view_records" :true-value="1" :false-value="0" @change="saveUserPerm(u)" />
                </td>
                <td style="text-align:center; padding:6px 10px; border-top:1px solid #ebeef5;">
                  <input type="checkbox" v-model="u.can_input" :true-value="1" :false-value="0" @change="saveUserPerm(u)" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <template #footer>
        <el-button @click="permModalVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, nextTick, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Odometer, Plus, Download, Search, Refresh, Delete, UserFilled
} from '@element-plus/icons-vue';
import request from '@/api/request';

const isAdmin = ref(false);
const canInput = ref(false);
const permModalVisible = ref(false);
const permUserList = ref([]);
const permLoading = ref(false);
const loading = ref(false);
const recordList = ref([]);
const totalRecords = ref(0);
const curPage = ref(1);
const totalPages = ref(1);
const selectedIds = ref([]);
const allSelected = ref(false);
const searchKeyword = ref('');

const snInputRef = ref(null);
const searchingSn = ref(false);
const snHistoryTip = ref('');

const modalVisible = ref(false);
const saving = ref(false);
const editingId = ref(null);

const form = reactive({
  sn: '',
  work_order: '',
  code: '',
  description: '',
  error_standard: 10,
  protect_impedance1: '',
  protect_impedance2: '',
  protect_impedance3: '',
  protect_impedance4: '',
  protect_impedance5: '',
  protect_impedance6: '',
  protect_impedance7: '',
  protect_impedance8: '',
  protect_impedance9: '',
  control_impedance1: '',
  control_impedance2: '',
  control_impedance3: ''
});

const calcRes = reactive({
  protect_error: null,
  control_error: null,
  protect_conclusion: '未填写',
  control_conclusion: '未填写'
});

const modalTitle = computed(() => editingId.value ? `✏️ 编辑阻抗测试记录 [SN: ${form.sn}]` : '➕ 添加阻抗测试记录');

const getErrorStyle = (err, std) => {
  if (err === null) return {};
  const maxStd = std || 10;
  return {
    color: err <= maxStd ? '#67C23A' : '#F56C6C',
    fontWeight: 'bold'
  };
};

const getConclusionBadgeStyle = (c) => {
  if (c === '合格') return { background: '#d4edda', color: '#155724', padding: '2px 5px', borderRadius: '4px', fontWeight: 'bold' };
  if (c === '不合格') return { background: '#f8d7da', color: '#721c24', padding: '2px 5px', borderRadius: '4px', fontWeight: 'bold' };
  if (c === '单值不计算') return { background: '#e3f2fd', color: '#1976d2', padding: '2px 5px', borderRadius: '4px' };
  if (c === '未填写') return { background: '#fff3cd', color: '#856404', padding: '2px 5px', borderRadius: '4px' };
  return { background: '#f0f0f0', color: '#666', padding: '2px 5px', borderRadius: '4px' };
};

const getConclusionColor = (c) => {
  if (c === '合格') return { color: '#67C23A' };
  if (c === '不合格') return { color: '#F56C6C' };
  return { color: '#333' };
};

const loadTable = async (p = 1) => {
  curPage.value = p;
  loading.value = true;
  try {
    const res = await request.get('/impedance/list', {
      params: {
        page: p,
        search_all: searchKeyword.value.trim()
      }
    });
    if (res.data) {
      recordList.value = res.data.records || [];
      totalRecords.value = res.data.total_records || 0;
      totalPages.value = res.data.total_pages || 1;
      isAdmin.value = !!res.data.is_admin;
      canInput.value = isAdmin.value || !!res.data.user_perm?.can_input;
      selectedIds.value = [];
      allSelected.value = false;
    }
  } finally {
    loading.value = false;
  }
};

const resetSearch = () => {
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

// ★ 权限加固（L2）：权限管理
const openPermModal = async () => {
  permModalVisible.value = true;
  permLoading.value = true;
  try {
    const res = await request.get('/impedance/get_permissions');
    if (res.data) permUserList.value = res.data.users || [];
  } finally {
    permLoading.value = false;
  }
};

const saveUserPerm = async (u) => {
  try {
    await request.post('/impedance/update_permission', {
      user_id: u.id,
      can_view_records: u.can_view_records,
      can_input: u.can_input
    });
    ElMessage.success('权限更新成功');
  } catch (e) {}
};

const openAddModal = () => {
  editingId.value = null;
  snHistoryTip.value = '';
  Object.assign(form, {
    sn: '',
    work_order: '',
    code: '',
    description: '',
    error_standard: 10,
    protect_impedance1: '',
    protect_impedance2: '',
    protect_impedance3: '',
    protect_impedance4: '',
    protect_impedance5: '',
    protect_impedance6: '',
    protect_impedance7: '',
    protect_impedance8: '',
    protect_impedance9: '',
    control_impedance1: '',
    control_impedance2: '',
    control_impedance3: ''
  });
  Object.assign(calcRes, {
    protect_error: null,
    control_error: null,
    protect_conclusion: '未填写',
    control_conclusion: '未填写'
  });
  modalVisible.value = true;
  nextTick(() => snInputRef.value?.focus());
};

const openEditModal = (r) => {
  editingId.value = r.id;
  snHistoryTip.value = '';
  Object.assign(form, {
    sn: r.sn,
    work_order: r.work_order,
    code: r.code || '',
    description: r.description || '',
    error_standard: r.error_standard || 10,
    protect_impedance1: r.protect_impedance1 !== null ? r.protect_impedance1 : '',
    protect_impedance2: r.protect_impedance2 !== null ? r.protect_impedance2 : '',
    protect_impedance3: r.protect_impedance3 !== null ? r.protect_impedance3 : '',
    protect_impedance4: r.protect_impedance4 !== null ? r.protect_impedance4 : '',
    protect_impedance5: r.protect_impedance5 !== null ? r.protect_impedance5 : '',
    protect_impedance6: r.protect_impedance6 !== null ? r.protect_impedance6 : '',
    protect_impedance7: r.protect_impedance7 !== null ? r.protect_impedance7 : '',
    protect_impedance8: r.protect_impedance8 !== null ? r.protect_impedance8 : '',
    protect_impedance9: r.protect_impedance9 !== null ? r.protect_impedance9 : '',
    control_impedance1: r.control_impedance1 !== null ? r.control_impedance1 : '',
    control_impedance2: r.control_impedance2 !== null ? r.control_impedance2 : '',
    control_impedance3: r.control_impedance3 !== null ? r.control_impedance3 : ''
  });
  modalVisible.value = true;
  calculateErrors();
};

const fetchBatchInfo = async () => {
  const snVal = form.sn.trim();
  snHistoryTip.value = '';
  if (!/^\d{22}$/.test(snVal)) return;

  searchingSn.value = true;
  try {
    const res = await request.post('/impedance/get_batch_info', { sn: snVal });
    if (res.data) {
      if (res.data.found) {
        form.work_order = res.data.work_order || '';
        form.code = res.data.code || '';
        form.description = res.data.description || '';
      }
      if (res.data.history_count > 0 && res.data.last_record) {
        snHistoryTip.value = `⚠️ 提示：该 SN 已存在 ${res.data.history_count} 条历史测试记录 (最近于 ${res.data.last_record.created_at} 由 ${res.data.last_record.operator} 录入)，提交时将作为复测记录保存。`;
      }
    }
  } catch (e) {
  } finally {
    searchingSn.value = false;
  }
};

const calculateErrors = async () => {
  const payload = {
    error_standard: form.error_standard,
    use_5_percent: form.error_standard === 5 ? '1' : '0'
  };
  for (let i = 1; i <= 9; i++) payload['protect_impedance' + i] = form['protect_impedance' + i];
  for (let i = 1; i <= 3; i++) payload['control_impedance' + i] = form['control_impedance' + i];

  try {
    const res = await request.post('/impedance/calculate_errors', payload);
    if (res.data) {
      Object.assign(calcRes, res.data);
    }
  } catch (e) {}
};

const submitSave = async (forceDuplicate = false) => {
  const sn = form.sn.trim();
  if (!/^\d{22}$/.test(sn)) {
    return ElMessage.warning('SN 必须为 22 位纯数字！');
  }
  if (!form.work_order.trim()) {
    return ElMessage.warning('工单号不能为空！');
  }

  if (calcRes.protect_conclusion === '不合格' || calcRes.control_conclusion === '不合格') {
    try {
      await ElMessageBox.confirm('⚠️ 警告：当前阻抗计算结论包含【不合格】，是否确认继续提交？', '提示', { type: 'warning' });
    } catch {
      return;
    }
  }

  saving.value = true;
  try {
    const act = editingId.value ? '/impedance/update' : '/impedance/add';
    const payload = {
      ...form,
      record_id: editingId.value,
      force_duplicate: forceDuplicate ? 1 : 0
    };

    const res = await request.post(act, payload);

    // 命中重复 SN：弹确认框，用户确认后带 force_duplicate=1 再提交
    if (res?.data?.is_duplicate) {
      saving.value = false;
      try {
        await ElMessageBox.confirm(
          res.data.message || '该 SN 存在历史记录，是否作为复测继续保存？',
          '复测确认提醒',
          { type: 'warning', confirmButtonText: '确认复测录入', cancelButtonText: '取消' }
        );
      } catch {
        return;
      }
      return submitSave(true);
    }

    ElMessage.success(res?.message || '保存成功');
    modalVisible.value = false;
    loadTable(curPage.value);
  } catch (e) {
    ElMessage.error(e?.message || '保存失败');
  } finally {
    saving.value = false;
  }
};

// 详情
const detailModalVisible = ref(false);
const detailRecord = ref(null);
const openDetail = (r) => {
  detailRecord.value = r;
  detailModalVisible.value = true;
};

// 删除
const deleteSingle = (r) => {
  ElMessageBox.confirm(`确定要永久删除 SN [${r.sn}] 的阻抗测试记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/impedance/delete', { ids: [r.id] });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const batchDelete = () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请勾选记录！');
  ElMessageBox.confirm(`确定要永久删除选中的 ${selectedIds.value.length} 条阻抗记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/impedance/delete', { ids: selectedIds.value });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const exportData = () => {
  window.open(`/api/impedance/export?search_all=${encodeURIComponent(searchKeyword.value.trim())}`, '_blank');
};

onMounted(() => {
  loadTable(1);
});
</script>

<style scoped>
/* 容器充满视口，纵向三段式布局；页面本身不滚动 */
.imp-container {
  height: 100vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-sizing: border-box;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.imp-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}

/* 顶部标题卡：固定高度，不参与伸缩 */
.imp-container > .imp-card:first-child {
  flex-shrink: 0;
}

/* 主表格卡：占满剩余高度，内部纵向 flex，让表格区域自己滚动 */
.imp-main-card {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  margin-bottom: 0;
}

.imp-toolbar {
  padding: 8px 12px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  flex-shrink: 0;
}
.imp-search-area {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
  flex: 1;
}
.imp-batch-bar {
  background: #e0f2fe;
  padding: 6px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #bae6fd;
  font-size: 12.5px;
  flex-shrink: 0;
}

/* 表格区域：flex 撑满剩余高度，自己内部滚动 */
.imp-table-wrapper {
  flex: 1;
  min-height: 0;
  width: 100%;
  overflow: auto;
  border-top: 1px solid #dee2e6;
}

.imp-dense-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 12px;
  text-align: center;
  color: #333;
  table-layout: fixed;
}
.imp-dense-table th {
  background: #f8f9fa;
  border-bottom: 2px solid #dee2e6;
  border-right: 1px solid #dee2e6;
  padding: 8px 3px;
  font-weight: 600;
  color: #2c3e50;
  position: sticky;
  top: 0;
  white-space: nowrap;
  z-index: 10;
  overflow: hidden;
  text-overflow: ellipsis;
}
.imp-dense-table td {
  padding: 6px 3px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  vertical-align: middle;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.imp-dense-table tr:hover { background-color: #f8fafc; }
.imp-dense-table tr.selected { background-color: #e0f2fe !important; }

/* 操作列：允许按钮完整显示，不裁切 */
.imp-op-cell {
  overflow: visible !important;
  text-overflow: clip !important;
  white-space: nowrap !important;
}
.imp-op-btns {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 4px;
  flex-wrap: nowrap;
}

.imp-num-cell {
  font-family: 'Consolas', 'Courier New', monospace;
  font-size: 11.5px;
}

/* 分页栏固定在卡片底部，不参与滚动 */
.imp-pagination-bar {
  padding: 8px 14px;
  background: #f8f9fa;
  border-top: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  flex-wrap: wrap;
  gap: 8px;
  flex-shrink: 0;
}

.form-grid-4 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0 10px;
}
.imp-section-box {
  background: #f8f9fa;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  padding: 8px 12px;
  margin-bottom: 10px;
}
.imp-grid-9 {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(75px, 1fr));
  gap: 6px;
}
.imp-grid-3 {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}
.imp-calc-box {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
  padding: 8px 12px;
  background: #e8f4fd;
  border-radius: 4px;
  text-align: center;
}

.info-block {
  background: #f8f9fa;
  padding: 6px 10px;
  border-radius: 4px;
  margin-top: 3px;
  border: 1px solid #dee2e6;
}

.imp-input {
  height: 32px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 8px;
  outline: none;
  box-sizing: border-box;
}
.imp-input:focus { border-color: #80bdff; }

.imp-val-input {
  width: 100%;
  height: 28px;
  padding: 2px 4px;
  font-size: 12px;
  text-align: center;
  border: 1px solid #ced4da;
  border-radius: 3px;
  outline: none;
  box-sizing: border-box;
}
.imp-val-input:focus { border-color: #80bdff; }

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
.btn-mini { padding: 2px 5px; font-size: 11px; }
.mr-1 { margin-right: 4px; }
</style>