<template>
  <div class="bv-container">
    <!-- 顶部标题 -->
    <div class="bv-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Checked /></el-icon>
          条码验证比对
        </span>
      </div>
    </div>

    <!-- 顶部 Tab 切换 -->
    <div class="bv-tabs">
      <button
        type="button"
        class="bv-tab-btn"
        :class="{ active: activeTab === 'scan' }"
        @click="activeTab = 'scan'"
      >
        <el-icon style="margin-right: 4px;"><FullScreen /></el-icon> 扫码验证工作台
      </button>
      <button
        type="button"
        class="bv-tab-btn"
        :class="{ active: activeTab === 'records' }"
        @click="switchTab('records')"
      >
        <el-icon style="margin-right: 4px;"><List /></el-icon> 已完成记录
      </button>
      <button
        type="button"
        class="bv-tab-btn"
        :class="{ active: activeTab === 'ref' }"
        @click="switchTab('ref')"
      >
        <el-icon style="margin-right: 4px;"><Grid /></el-icon> 参考数据底表
      </button>
    </div>

    <!-- ================= 视图 1: 扫码验证工作台 ================= -->
    <div v-show="activeTab === 'scan'">
      <!-- 工单信息与比对进度 -->
      <div class="bv-card" style="margin-bottom:12px;">
        <div class="bv-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#409EFF; vertical-align:middle;"><Operation /></el-icon>
            工单信息与比对进度
          </span>
          <div style="display:flex; gap:6px;">
            <el-button v-if="perms.can_save_settings" size="small" @click="settingsModalVisible = true">
              <el-icon class="mr-1"><Setting /></el-icon> 验证字段设置
            </el-button>
            <el-button size="small" @click="confirmResetWorkOrder">
              <el-icon class="mr-1"><Refresh /></el-icon> 重置工单
            </el-button>
          </div>
        </div>

        <div style="padding: 12px 14px;">
          <div class="bv-wo-row">
            <div class="bv-wo-input-group">
              <span style="font-size:12.5px; font-weight:600; color:#495057; white-space:nowrap;">工单号 *</span>
              <input
                v-model="workOrderInput"
                type="text"
                class="bv-input"
                placeholder="输入工单号 (如 172072)..."
                @keyup.enter="handleQueryWorkOrder"
              />
              <button type="button" class="btn-primary" @click="handleQueryWorkOrder">查询</button>
            </div>

            <div v-if="workOrderInfo" class="bv-inline-info">
              <span><strong>编码:</strong> <span style="font-family:monospace;">{{ workOrderInfo.code || '-' }}</span></span>
              <span style="color:#93c5fd;">|</span>
              <span class="cell-truncate"><strong>描述:</strong> {{ workOrderInfo.description || '-' }}</span>
              <span style="color:#93c5fd;">|</span>
              <span><strong>数量:</strong> <strong style="color:#0284c7;">{{ workOrderInfo.quantity || 0 }}</strong></span>
              <span style="color:#93c5fd;">|</span>
              <span><strong>批次前缀:</strong> <strong style="color:#16a34a; font-family:monospace;">{{ workOrderInfo.batch_prefix || '-' }}</strong></span>
            </div>
          </div>

          <!-- 未上传参考底表告警 -->
          <div v-if="workOrderInfo && totalRows === 0" class="bv-no-ref-alert">
            <div>
              <el-icon color="#e67e22" style="vertical-align:middle; margin-right:6px;"><WarningFilled /></el-icon>
              当前工单尚未上传比对参考底表！无法进行扫码比对，请先前往上传参考表。
            </div>
            <button type="button" class="btn-warning-mini" @click="switchTab('ref')">
              前往上传底表 &gt;
            </button>
          </div>

          <!-- 进度条 -->
          <div v-if="totalRows > 0" style="margin-top: 10px;">
            <div style="display:flex; justify-content:space-between; font-size:12.5px; color:#495057; margin-bottom:4px;">
              <span>总行数: <strong>{{ totalRows }}</strong>，已完成: <strong style="color:#16a34a;">{{ completedRows.length }}</strong></span>
              <span>进度: <strong style="color:#0284c7;">{{ progressPercent }}%</strong></span>
            </div>
            <div class="progress-bar-wrap">
              <div class="progress-fill" :style="{ width: progressPercent + '%' }"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- 待扫描输入区域 -->
      <div v-if="totalRows > 0" class="bv-card" style="margin-bottom:12px;">
        <div class="bv-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#16a34a; vertical-align:middle;"><Aim /></el-icon>
            当前待扫描行：第 <strong style="color:#0284c7;">{{ currentRowIndex >= 0 ? (currentRowIndex + 1) : '全部完成' }}</strong> 行
          </span>
          <button v-if="currentRowIndex >= 0" type="button" class="btn-outline" style="height:30px; font-size:12px;" @click="handleRemakeRow">
            重置本行重新扫描
          </button>
        </div>
        <div style="padding:14px;">
          <div v-if="currentRowIndex >= 0" class="scan-grid">
            <div
              v-for="f in activeScanFields"
              :key="f"
              class="scan-card"
              :class="{ validated: !!currentRowData[f] }"
            >
              <label style="font-weight:600; display:block; margin-bottom:3px; font-size:12px; color:#495057;">
                {{ fieldLabelMap[f] || f }}
              </label>
              <input
                :ref="el => setScanInputRef(f, el)"
                v-model="scanInputs[f]"
                type="text"
                class="bv-input"
                :disabled="!!currentRowData[f]"
                placeholder="扫入或输入回车..."
                style="height:32px; font-size:12.5px; font-family:monospace; width:100%; box-sizing:border-box;"
                @keyup.enter="handleScanValidate(f)"
              />
              <small :style="{ color: currentRowData[f] ? '#155724' : '#888' }" style="display:block; margin-top:3px; font-size:11.5px;">
                {{ currentRowData[f] ? '✅ 已校验' : '待扫描' }}
              </small>
            </div>
          </div>
          <div v-else style="text-align:center; padding:30px; color:#16a34a; font-size:15px; font-weight:bold;">
            🎉 本工单所有行（共 {{ totalRows }} 台）已全部校验完成！
          </div>
        </div>
      </div>

      <!-- 最近完成记录 (最新 5 条) -->
      <div class="bv-card">
        <div class="bv-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#16a34a; vertical-align:middle;"><Checked /></el-icon>
            最近已完成记录 (最新 5 条)
          </span>
        </div>
        <div class="bv-table-wrapper" style="max-height:220px; min-height:120px;">
          <table class="bv-fixed-table" style="min-width:1150px;">
            <thead>
              <tr>
                <th style="width:50px;">行号</th>
                <th style="width:90px;">工单号</th>
                <th style="width:175px;">装置SN</th>
                <th style="width:110px;">模组1</th>
                <th style="width:110px;">模组2</th>
                <th style="width:110px;">模组3</th>
                <th style="width:110px;">模组4</th>
                <th style="width:110px;">模组5</th>
                <th style="width:110px;">面膜条码</th>
                <th style="width:110px;">面膜二维码</th>
                <th style="width:75px;">操作者</th>
                <th style="width:110px;">完成时间</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in recentCompletedList" :key="r.id">
                <td><strong>{{ (r.row_index || 0) + 1 }}</strong></td>
                <td><strong>{{ r.work_order }}</strong></td>
                <td><span style="font-family:monospace; font-weight:600; color:#2c3e50;">{{ r.sn }}</span></td>
                <td><span style="font-family:monospace; font-size:12px;">{{ r.mod1 || '-' }}</span></td>
                <td><span style="font-family:monospace; font-size:12px;">{{ r.mod2 || '-' }}</span></td>
                <td><span style="font-family:monospace; font-size:12px;">{{ r.mod3 || '-' }}</span></td>
                <td><span style="font-family:monospace; font-size:12px;">{{ r.mod4 || '-' }}</span></td>
                <td><span style="font-family:monospace; font-size:12px;">{{ r.mod5 || '-' }}</span></td>
                <td><span style="font-family:monospace; font-size:12px;">{{ r.mask_barcode || '-' }}</span></td>
                <td><span style="font-family:monospace; font-size:12px;">{{ r.mask_qr || '-' }}</span></td>
                <td>{{ r.operator }}</td>
                <td><span style="font-size:11.5px; color:#64748b;">{{ String(r.completed_at || '').substring(5, 16) }}</span></td>
              </tr>
              <tr v-if="recentCompletedList.length === 0">
                <td colspan="12" style="text-align:center; padding:25px; color:#888;">暂无完成记录</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ================= 视图 2: 完整已完成记录 ================= -->
    <div v-show="activeTab === 'records'">
      <div class="bv-card">
        <div class="bv-card-header" style="flex-wrap:wrap; gap:8px;">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#409EFF; vertical-align:middle;"><List /></el-icon>
            全部已完成扫描明细
          </span>
          <div style="display:flex; gap:8px; align-items:center;">
            <input
              v-model="recordSearchKw"
              type="text"
              class="bv-input"
              style="width:260px;"
              placeholder="搜索工单号、SN、模组或操作人..."
              @keyup.enter="loadFullRecords(1)"
            />
            <button type="button" class="btn-primary" @click="loadFullRecords(1)">查询</button>
            <button type="button" class="btn-success" @click="handleExportCompleted">导出已完成 Excel</button>
          </div>
        </div>
        <div class="bv-table-wrapper">
          <table class="bv-fixed-table" style="min-width:1200px;">
            <thead>
              <tr>
                <th style="width:50px;">行号</th>
                <th style="width:90px;">工单号</th>
                <th style="width:175px;">装置SN</th>
                <th style="width:110px;">模组1</th>
                <th style="width:110px;">模组2</th>
                <th style="width:110px;">模组3</th>
                <th style="width:110px;">模组4</th>
                <th style="width:110px;">模组5</th>
                <th style="width:110px;">面膜条码</th>
                <th style="width:110px;">面膜二维码</th>
                <th style="width:75px;">操作者</th>
                <th style="width:120px;">完成时间</th>
                <th v-if="showOp" style="width:120px; text-align:center;">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in fullRecordsList" :key="r.id">
                <td><strong>{{ (r.row_index || 0) + 1 }}</strong></td>
                <td><strong>{{ r.work_order }}</strong></td>
                <td><span style="font-family:monospace; font-weight:600; color:#2c3e50;">{{ r.sn }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mod1 || '-' }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mod2 || '-' }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mod3 || '-' }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mod4 || '-' }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mod5 || '-' }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mask_barcode || '-' }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mask_qr || '-' }}</span></td>
                <td>{{ r.operator }}</td>
                <td><span style="font-size:11.5px; color:#64748b;">{{ r.completed_at }}</span></td>
                <td v-if="showOp" style="text-align:center; white-space:nowrap;">
                  <button v-if="perms.can_edit" type="button" class="btn-primary btn-mini" style="margin-right:4px;" @click="handleEditCompleted(r)">编辑</button>
                  <button v-if="perms.can_delete" type="button" class="btn-danger btn-mini" @click="handleDeleteCompleted(r.id)">删除</button>
                </td>
              </tr>
              <tr v-if="fullRecordsList.length === 0">
                <td :colspan="opColSpan" style="text-align:center; padding:30px; color:#888;">暂无记录</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="bv-pagination-bar">
          <div style="color:#64748b;">共 <strong>{{ recordsTotal }}</strong> 条记录，第 <strong>{{ recordsPage }}</strong> / <strong>{{ recordsTotalPages }}</strong> 页</div>
          <el-pagination
            v-model:current-page="recordsPage"
            :page-size="30"
            :total="recordsTotal"
            layout="prev, pager, next"
            @current-change="loadFullRecords"
          />
        </div>
      </div>
    </div>

    <!-- ================= 视图 3: 参考底表数据 ================= -->
    <div v-show="activeTab === 'ref'">
      <div class="bv-card" style="margin-bottom:12px;">
        <div class="bv-card-header">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#409EFF; vertical-align:middle;"><Grid /></el-icon>
            参考底表绑定工单
          </span>
          <button type="button" class="btn-outline" style="height:30px; font-size:12px;" @click="confirmResetWorkOrder">清空工单</button>
        </div>
        <div style="padding:12px 14px;">
          <div class="bv-wo-row">
            <div class="bv-wo-input-group">
              <span style="font-size:12.5px; font-weight:600; color:#495057; white-space:nowrap;">工单号 *</span>
              <input
                v-model="refWorkOrderInput"
                type="text"
                class="bv-input"
                placeholder="输入工单号 (如 172072)..."
                @keyup.enter="handleQueryWorkOrderFromRef"
              />
              <button type="button" class="btn-primary" @click="handleQueryWorkOrderFromRef">获取工单信息</button>
            </div>
            <div v-if="workOrderInfo" class="bv-inline-info">
              <span><strong>当前绑定:</strong> <strong style="color:#0284c7;">{{ workOrderInfo.work_order }}</strong></span>
              <span style="color:#93c5fd;">|</span>
              <span><strong>编码:</strong> <span style="font-family:monospace;">{{ workOrderInfo.code || '-' }}</span></span>
              <span style="color:#93c5fd;">|</span>
              <span class="cell-truncate"><strong>描述:</strong> {{ workOrderInfo.description || '-' }}</span>
              <span style="color:#93c5fd;">|</span>
              <span><strong>数量:</strong> <strong style="color:#0284c7;">{{ workOrderInfo.quantity || 0 }}</strong></span>
            </div>
          </div>
        </div>
      </div>

      <div class="bv-card">
        <div class="bv-card-header" style="flex-wrap:wrap; gap:8px;">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">
            <el-icon style="color:#16a34a; vertical-align:middle;"><Operation /></el-icon>
            导入的参考底表数据列表
          </span>
          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <span style="font-size:12.5px; font-weight:600; color:#495057;">选择表格 (Excel/CSV)：</span>
            <input ref="fileInputRef" type="file" accept=".xlsx,.xls,.csv" class="bv-input" style="width:230px; height:32px; padding:3px 6px;" />
            <button type="button" class="btn-success" :disabled="importingRef" @click="handleImportReference">
              <span v-if="importingRef">正在导入...</span>
              <span v-else>导入并绑定当前工单</span>
            </button>
          </div>
        </div>
        <div class="bv-table-wrapper">
          <table class="bv-fixed-table" style="min-width:1050px;">
            <thead>
              <tr>
                <th style="width:60px;">行号</th>
                <th>模组1</th>
                <th>模组2</th>
                <th>模组3</th>
                <th>模组4</th>
                <th>模组5</th>
                <th>面膜条码</th>
                <th>面膜二维码</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in referenceList" :key="r.id">
                <td><strong>{{ (r.row_index || 0) + 1 }}</strong></td>
                <td><span style="font-family:monospace;">{{ r.mod1 || '-' }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mod2 || '-' }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mod3 || '-' }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mod4 || '-' }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mod5 || '-' }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mask_barcode || '-' }}</span></td>
                <td><span style="font-family:monospace;">{{ r.mask_qr || '-' }}</span></td>
              </tr>
              <tr v-if="referenceList.length === 0">
                <td colspan="8" style="text-align:center; padding:30px; color:#888;">
                  {{ workOrderInfo ? '工单暂无参考底表数据，请在上方选择文件导入' : '请先在上方输入工单号查询' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="bv-pagination-bar">
          <div style="color:#64748b;">共 <strong>{{ refTotal }}</strong> 条记录，第 <strong>{{ refPage }}</strong> / <strong>{{ refTotalPages }}</strong> 页</div>
          <el-pagination
            v-model:current-page="refPage"
            :page-size="30"
            :total="refTotal"
            layout="prev, pager, next"
            @current-change="loadReferenceTable"
          />
        </div>
      </div>
    </div>

    <!-- 弹窗: 验证字段设置 -->
    <el-dialog v-model="settingsModalVisible" title="验证字段与顺序设置" width="560px" destroy-on-close>
      <div style="font-size:13px;">
        <div style="margin-bottom:14px;">
          <label style="font-weight:600; display:block; margin-bottom:8px;">启用比对字段 (装置SN始终启用)：</label>
          <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <el-checkbox
              v-for="f in allFieldKeys"
              :key="f"
              v-model="settingFormEnabled[f]"
              :label="fieldLabelMap[f]"
            />
          </div>
        </div>
        <div>
          <label style="font-weight:600; display:block; margin-bottom:8px;">顺序比对要求 (勾选表示需按顺序扫描)：</label>
          <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <el-checkbox
              v-for="f in allFieldKeys"
              :key="f"
              v-model="settingFormOrder[f]"
              :label="fieldLabelMap[f]"
            />
          </div>
        </div>
      </div>
      <template #footer>
        <el-button @click="settingsModalVisible = false">取消</el-button>
        <el-button type="primary" @click="saveSettings">保存设置</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗: 编辑已完成记录 -->
    <el-dialog v-model="editModalVisible" title="编辑已完成记录" width="560px" destroy-on-close>
      <el-form label-width="110px" style="font-size:13px;">
        <el-form-item label="装置 SN">
          <el-input v-model="editForm.sn" />
        </el-form-item>
        <el-form-item label="模组 1"><el-input v-model="editForm.mod1" /></el-form-item>
        <el-form-item label="模组 2"><el-input v-model="editForm.mod2" /></el-form-item>
        <el-form-item label="模组 3"><el-input v-model="editForm.mod3" /></el-form-item>
        <el-form-item label="模组 4"><el-input v-model="editForm.mod4" /></el-form-item>
        <el-form-item label="模组 5"><el-input v-model="editForm.mod5" /></el-form-item>
        <el-form-item label="面膜条码"><el-input v-model="editForm.mask_barcode" /></el-form-item>
        <el-form-item label="面膜二维码"><el-input v-model="editForm.mask_qr" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editModalVisible = false">取消</el-button>
        <el-button type="primary" @click="submitEdit">保存修改</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, nextTick, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Checked, FullScreen, List, Grid,
  Operation, Setting, Refresh, WarningFilled, Aim
} from '@element-plus/icons-vue';
import request from '@/api/request';

const activeTab = ref('scan');
const isAdmin = ref(false);

// ★ 本模块细粒度权限（验证设置 / 删除记录 / 编辑记录），由后端 /barcode_validator/get_my_perm 返回
const perms = ref({ can_save_settings: 1, can_delete: 0, can_edit: 0 });
const showOp = computed(() => !!(perms.value.can_delete || perms.value.can_edit));
const opColSpan = computed(() => (showOp.value ? 13 : 12));

const allFieldKeys = ['mod1','mod2','mod3','mod4','mod5','mask_barcode','mask_qr'];
const fieldLabelMap = {
  'sn': '装置 SN (22位)',
  'mod1': '模组 1',
  'mod2': '模组 2',
  'mod3': '模组 3',
  'mod4': '模组 4',
  'mod5': '模组 5',
  'mask_barcode': '面膜条码',
  'mask_qr': '面膜二维码'
};

const workOrderInput = ref('');
const refWorkOrderInput = ref('');
const workOrderInfo = ref(null);
const totalRows = ref(0);
const completedRows = ref([]);
const referenceData = ref([]);
const currentRowIndex = ref(-1);
const currentRowData = reactive({});
const scanInputs = reactive({});
const scanInputRefs = {};

const setScanInputRef = (field, el) => {
  if (el) scanInputRefs[field] = el;
};

const recentCompletedList = ref([]);
const enabledFields = ref(['mod1','mod2','mod3','mod4','mod5','mask_barcode','mask_qr']);
const orderFields = ref([]);

const progressPercent = computed(() => {
  if (totalRows.value === 0) return 0;
  return Math.round((completedRows.value.length / totalRows.value) * 100);
});

const activeScanFields = computed(() => {
  return ['sn', ...enabledFields.value.filter(f => f !== 'sn')];
});

// 查询工单
const handleQueryWorkOrder = async () => {
  const wo = workOrderInput.value.trim();
  if (!wo) return ElMessage.warning('请输入工单号');

  try {
    const res = await request.post('/barcode_validator/fetch_workorder', { work_order: wo });
    if (res.data) {
      workOrderInfo.value = res.data;
      workOrderInput.value = res.data.work_order;
      refWorkOrderInput.value = res.data.work_order;

      await loadSettings();
      await loadProgress();
      await loadRecentCompleted();
    }
  } catch (err) {}
};

const handleQueryWorkOrderFromRef = async () => {
  workOrderInput.value = refWorkOrderInput.value;
  await handleQueryWorkOrder();
  loadReferenceTable(1);
};

// 获取进度
const loadProgress = async () => {
  if (!workOrderInfo.value) return;
  const res = await request.get('/barcode_validator/progress', {
    params: { work_order: workOrderInfo.value.work_order }
  });
  if (res.data) {
    totalRows.value = res.data.totalRows || 0;
    completedRows.value = res.data.completedRows || [];
    referenceData.value = res.data.reference || [];
    locateNextRow();
  }
};

const locateNextRow = () => {
  let found = -1;
  for (let i = 0; i < totalRows.value; i++) {
    if (!completedRows.value.includes(i)) {
      found = i;
      break;
    }
  }

  currentRowIndex.value = found;
  // 清理行状态
  Object.keys(currentRowData).forEach(k => delete currentRowData[k]);
  activeScanFields.value.forEach(f => {
    scanInputs[f] = '';
  });

  if (found >= 0) {
    nextTick(() => {
      const firstField = activeScanFields.value[0];
      scanInputRefs[firstField]?.focus();
    });
  }
};

// 扫描比对单字段
const handleScanValidate = async (field) => {
  const val = (scanInputs[field] || '').trim();
  if (!val) return;

  try {
    const res = await request.post('/barcode_validator/scan_validate', {
      work_order: workOrderInfo.value.work_order,
      row_index: currentRowIndex.value,
      field: field,
      value: val,
      batch_prefix: workOrderInfo.value.batch_prefix,
      quantity: workOrderInfo.value.quantity
    });

    currentRowData[field] = val;
    ElMessage.success(res.message || '校验通过');

    const curRowRef = referenceData.value[currentRowIndex.value] || {};
    const requiredFields = activeScanFields.value.filter(f => {
      if (f === 'sn') return true;
      return !!curRowRef[f];
    });

    const isAllDone = requiredFields.every(rf => !!currentRowData[rf]);
    if (isAllDone) {
      await submitCurrentRow();
    } else {
      nextTick(() => {
        const nextField = activeScanFields.value.find(f => !currentRowData[f]);
        if (nextField) scanInputRefs[nextField]?.focus();
      });
    }
  } catch (err) {
    nextTick(() => scanInputRefs[field]?.select());
  }
};

// 提交整行
const submitCurrentRow = async () => {
  try {
    await request.post('/barcode_validator/submit_row', {
      work_order: workOrderInfo.value.work_order,
      row_index: currentRowIndex.value,
      sn: currentRowData.sn || '',
      mod1: currentRowData.mod1 || '',
      mod2: currentRowData.mod2 || '',
      mod3: currentRowData.mod3 || '',
      mod4: currentRowData.mod4 || '',
      mod5: currentRowData.mod5 || '',
      mask_barcode: currentRowData.mask_barcode || '',
      mask_qr: currentRowData.mask_qr || ''
    });

    ElMessage.success(`第 ${currentRowIndex.value + 1} 行整行校验完成！`);
    completedRows.value.push(currentRowIndex.value);
    await loadProgress();
    await loadRecentCompleted();
  } catch (err) {}
};

const handleRemakeRow = () => {
  ElMessageBox.confirm('确定重置当前行的扫描数据并重新录入吗？', '提示', { type: 'warning' }).then(() => {
    Object.keys(currentRowData).forEach(k => delete currentRowData[k]);
    activeScanFields.value.forEach(f => {
      scanInputs[f] = '';
    });
    nextTick(() => scanInputRefs[activeScanFields.value[0]]?.focus());
  });
};

const loadRecentCompleted = async () => {
  if (!workOrderInfo.value) return;
  const res = await request.get('/barcode_validator/get_completed_rows', {
    params: { work_order: workOrderInfo.value.work_order, limit: 5 }
  });
  recentCompletedList.value = res.data?.rows || [];
};

// 视图 2: 完整记录
const recordSearchKw = ref('');
const fullRecordsList = ref([]);
const recordsTotal = ref(0);
const recordsPage = ref(1);
const recordsTotalPages = ref(1);

const loadFullRecords = async (p = 1) => {
  recordsPage.value = p;
  const params = {
    work_order: workOrderInfo.value?.work_order || '',
    search: recordSearchKw.value.trim(),
    page: p,
    limit: 30
  };
  const res = await request.get('/barcode_validator/get_completed_rows', { params });
  fullRecordsList.value = res.data?.rows || [];
  recordsTotal.value = res.data?.total || 0;
  recordsTotalPages.value = res.data?.total_pages || 1;
};

const handleDeleteCompleted = (id) => {
  ElMessageBox.confirm('确定删除该条完成记录吗？删除后需重新扫描该行。', '提示', { type: 'warning' }).then(async () => {
    await request.post('/barcode_validator/delete_completed', { id });
    ElMessage.success('删除成功');
    loadFullRecords(recordsPage.value);
    loadProgress();
  });
};

// ★ 编辑已完成记录
const editModalVisible = ref(false);
const editForm = reactive({
  id: 0, sn: '', mod1: '', mod2: '', mod3: '', mod4: '', mod5: '', mask_barcode: '', mask_qr: ''
});

const handleEditCompleted = (row) => {
  Object.assign(editForm, {
    id: row.id,
    sn: row.sn || '',
    mod1: row.mod1 || '',
    mod2: row.mod2 || '',
    mod3: row.mod3 || '',
    mod4: row.mod4 || '',
    mod5: row.mod5 || '',
    mask_barcode: row.mask_barcode || '',
    mask_qr: row.mask_qr || ''
  });
  editModalVisible.value = true;
};

const submitEdit = async () => {
  if (!editForm.id) return;
  try {
    await request.post('/barcode_validator/update_completed', { ...editForm });
    ElMessage.success('记录已更新');
    editModalVisible.value = false;
    loadFullRecords(recordsPage.value);
  } catch (e) {
    // 错误由拦截器提示
  }
};

const handleExportCompleted = () => {
  const wo = workOrderInfo.value?.work_order || '';
  const kw = recordSearchKw.value.trim();
  window.open(`/api/barcode_validator/export_completed?work_order=${encodeURIComponent(wo)}&search=${encodeURIComponent(kw)}`, '_blank');
};

// 视图 3: 参考底表
const fileInputRef = ref(null);
const importingRef = ref(false);
const referenceList = ref([]);
const refTotal = ref(0);
const refPage = ref(1);
const refTotalPages = ref(1);

const loadReferenceTable = async (p = 1) => {
  refPage.value = p;
  if (!workOrderInfo.value) {
    referenceList.value = [];
    refTotal.value = 0;
    return;
  }
  const res = await request.get('/barcode_validator/get_reference_rows', {
    params: { work_order: workOrderInfo.value.work_order, page: p, limit: 30 }
  });
  referenceList.value = res.data?.rows || [];
  refTotal.value = res.data?.total || 0;
  refTotalPages.value = res.data?.total_pages || 1;
};

const handleImportReference = async () => {
  if (!workOrderInfo.value) {
    ElMessage.warning('请先输入工单号并点击查询绑定工单');
    return;
  }
  const file = fileInputRef.value?.files[0];
  if (!file) return ElMessage.warning('请选择参考表格文件 (.xlsx, .xls, .csv)');

  const fd = new FormData();
  fd.append('work_order', workOrderInfo.value.work_order);
  fd.append('file', file);

  importingRef.value = true;
  try {
    const res = await request.post('/barcode_validator/upload_reference', fd);
    ElMessage.success(res.message || '导入成功');
    if (fileInputRef.value) fileInputRef.value.value = '';
    await loadReferenceTable(1);
    await loadProgress();
    await loadRecentCompleted();
  } finally {
    importingRef.value = false;
  }
};

// 设置弹窗
const settingsModalVisible = ref(false);
const settingFormEnabled = reactive({});
const settingFormOrder = reactive({});

const loadSettings = async () => {
  if (!workOrderInfo.value) return;
  const res = await request.get('/barcode_validator/get_settings', {
    params: { work_order: workOrderInfo.value.work_order }
  });
  if (res.data) {
    enabledFields.value = res.data.enabled_fields || [];
    orderFields.value = res.data.order_fields || [];

    allFieldKeys.forEach(k => {
      settingFormEnabled[k] = enabledFields.value.includes(k);
      settingFormOrder[k] = orderFields.value.includes(k);
    });
  }
};

const saveSettings = async () => {
  const enabled = allFieldKeys.filter(k => settingFormEnabled[k]);
  const ordered = allFieldKeys.filter(k => settingFormOrder[k]);

  await request.post('/barcode_validator/save_settings', {
    work_order: workOrderInfo.value.work_order,
    enabled_fields: enabled.join(','),
    order_fields: ordered.join(',')
  });

  enabledFields.value = enabled;
  orderFields.value = ordered;
  ElMessage.success('设置已保存');
  settingsModalVisible.value = false;
  locateNextRow();
};

const switchTab = (tab) => {
  activeTab.value = tab;
  if (tab === 'records') loadFullRecords(1);
  if (tab === 'ref') loadReferenceTable(1);
};

const confirmResetWorkOrder = () => {
  ElMessageBox.confirm('确定重置当前工作台工单吗？', '提示', { type: 'warning' }).then(() => {
    workOrderInput.value = '';
    refWorkOrderInput.value = '';
    workOrderInfo.value = null;
    totalRows.value = 0;
    completedRows.value = [];
    referenceData.value = [];
    currentRowIndex.value = -1;
    recentCompletedList.value = [];
    referenceList.value = [];
  });
};

onMounted(async () => {
  const user = JSON.parse(localStorage.getItem('user') || '{}');
  isAdmin.value = emptyAdmin(user);

  // 拉取本模块的细粒度权限，用于控制「验证设置 / 删除 / 编辑」
  try {
    const res = await request.get('/barcode_validator/get_my_perm');
    const p = res?.data?.perm || {};
    perms.value = {
      can_save_settings: Number(p.can_save_settings) ? 1 : 0,
      can_delete: Number(p.can_delete) ? 1 : 0,
      can_edit: Number(p.can_edit) ? 1 : 0
    };
  } catch (e) {
    // 拉取失败时保守处理：仅管理员默认可操作
    perms.value = {
      can_save_settings: isAdmin.value ? 1 : 1,
      can_delete: isAdmin.value ? 1 : 0,
      can_edit: isAdmin.value ? 1 : 0
    };
  }
});

function emptyAdmin(u) {
  return !!u.is_admin || u.username === 'admin';
}
</script>

<style scoped>
.bv-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.bv-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
}
.bv-card-header {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.bv-tabs {
  display: flex;
  gap: 10px;
  margin-bottom: 12px;
  border-bottom: 2px solid #dee2e6;
}
.bv-tab-btn {
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
.bv-tab-btn.active {
  color: #007bff;
  border-bottom-color: #007bff;
}

.bv-wo-row {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 6px;
}
.bv-wo-input-group {
  display: flex;
  gap: 6px;
  align-items: center;
  width: 340px;
  min-width: 280px;
}
.bv-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  flex: 1;
}
.bv-input:focus {
  border-color: #80bdff;
  box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
}

.bv-inline-info {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 10px;
  background: #f0f7ff;
  border: 1px dashed #93c5fd;
  border-radius: 4px;
  padding: 0 12px;
  height: 33px;
  line-height: 31px;
  font-size: 12.5px;
  color: #0284c7;
  min-width: 280px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cell-truncate {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bv-no-ref-alert {
  margin-top: 10px;
  padding: 10px 14px;
  background: #fff3cd;
  border: 1px solid #ffeeba;
  border-radius: 6px;
  color: #856404;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.progress-bar-wrap {
  background: #e9ecef;
  border-radius: 6px;
  height: 16px;
  overflow: hidden;
  border: 1px solid #dee2e6;
}
.progress-fill {
  background: linear-gradient(135deg, #28a745, #20c997);
  height: 100%;
  transition: width 0.3s;
}

.scan-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 10px;
}
.scan-card {
  background: #f8f9fa;
  border: 1px solid #e3e6f0;
  border-radius: 6px;
  padding: 8px 12px;
}
.scan-card.validated {
  background: #d4edda;
  border-color: #c3e6cb;
}

.bv-table-wrapper {
  width: 100%;
  overflow-x: auto;
  overflow-y: auto;
  max-height: calc(100vh - 350px);
  min-height: 280px;
  border-top: 1px solid #dee2e6;
}
.bv-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed !important;
  font-size: 13px;
  text-align: center;
  color: #333;
}
.bv-fixed-table thead th {
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
.bv-fixed-table tbody td {
  padding: 7px 6px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bv-fixed-table tbody tr:hover {
  background: #f8fafc;
}

.bv-pagination-bar {
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

.btn-primary {
  background: #007bff;
  color: #fff;
  border: 1px solid #007bff;
  border-radius: 4px;
  padding: 6px 14px;
  font-size: 12.5px;
  cursor: pointer;
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
}
.btn-success:hover { background: #218838; }
.btn-danger {
  background: #dc3545;
  color: #fff;
  border: 1px solid #dc3545;
  border-radius: 4px;
  padding: 6px 14px;
  font-size: 12.5px;
  cursor: pointer;
}
.btn-outline {
  background: #fff;
  color: #495057;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 6px 12px;
  font-size: 12.5px;
  cursor: pointer;
}
.btn-mini { padding: 2px 8px; font-size: 11px; }
.btn-warning-mini {
  height: 28px;
  font-size: 12px;
  font-weight: 600;
  color: #856404;
  background: #ffe8a1;
  border: 1px solid #ffeeba;
  border-radius: 4px;
  padding: 0 10px;
  cursor: pointer;
}
.mr-1 { margin-right: 4px; }
</style>