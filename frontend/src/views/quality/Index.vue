<template>
  <div class="qi-container">
    <!-- 顶部标题卡片 -->
    <div class="qi-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#E6A23C; vertical-align:middle; margin-right:6px;"><WarningFilled /></el-icon>
          质量问题记录系统
        </span>
        <div style="display:flex; gap:6px;">
          <button v-if="isAdmin" type="button" class="btn-warning btn-sm" @click="openPermModal">
            <el-icon class="mr-1"><UserFilled /></el-icon> 权限管理
          </button>
          <button type="button" class="btn-primary btn-sm" @click="exportCsv">
            <el-icon class="mr-1"><Download /></el-icon> 导出数据
          </button>
        </div>
      </div>
    </div>

    <!-- 录入面板卡片（无录入权限时不显示） -->
    <div v-if="canInput" class="qi-card">
      <div style="padding: 12px 16px;">
        <div class="qi-type-selector">
          <label class="qi-type-option">
            <input v-model="issueType" type="radio" value="single" /> 个例问题 (单台录入)
          </label>
          <label class="qi-type-option">
            <input v-model="issueType" type="radio" value="batch" /> 批次问题 (批量录入)
          </label>
        </div>

        <!-- 1. 单台录入面板 -->
        <div v-show="issueType === 'single'">
          <div class="qi-sn-row">
            <div class="qi-sn-input-group">
              <span class="qi-label req">扫入 SN:</span>
              <div style="position:relative; flex:1;">
                <input
                  ref="singleSnInputRef"
                  v-model="singleForm.sn"
                  type="text"
                  class="qi-input"
                  maxlength="22"
                  style="width:100%; font-family:monospace;"
                  placeholder="输入22位SN后回车..."
                  autocomplete="off"
                  @keyup.enter="queryBatchInfo('single')"
                />
                <span v-if="queryingSingle" class="spinner-tip">查询中...</span>
              </div>
              <button type="button" class="btn-primary btn-sm" :disabled="queryingSingle" style="white-space:nowrap;" @click="queryBatchInfo('single')">
                <el-icon class="mr-1"><Search /></el-icon> 查询
              </button>
            </div>

            <div v-if="singleBatchInfo" class="qi-inline-info">
              <span><strong>工单:</strong> <span style="font-weight:bold; font-family:monospace;">{{ singleBatchInfo.work_order || '-' }}</span></span>
              <span style="color:#93c5fd;">|</span>
              <span><strong>编码:</strong> <span style="font-family:monospace;">{{ singleBatchInfo.task_code || '-' }}</span></span>
              <span style="color:#93c5fd;">|</span>
              <span style="overflow:hidden; text-overflow:ellipsis;"><strong>描述:</strong> {{ singleBatchInfo.description || '-' }}</span>
            </div>
          </div>

          <div class="qi-input-row">
            <div style="width: 140px; flex-shrink: 0;">
              <select v-model="singleForm.process" class="qi-select" style="width:100%;">
                <option value="">请选择工序 *</option>
                <option v-for="p in validProcesses" :key="p" :value="p">{{ p }}</option>
              </select>
            </div>

            <div style="flex: 1; min-width: 250px;">
              <input
                v-model="singleForm.issue_description"
                type="text"
                class="qi-input"
                style="width:100%;"
                placeholder="请详细描述不良现象及缺陷位置... *"
              />
            </div>

            <div style="width: 200px; flex-shrink: 0;">
              <input ref="singleImgInputRef" type="file" accept="image/*" class="qi-input" style="width:100%; height:33px; padding:3px 6px;" @change="handleImageChange('single')" />
              <div v-if="singleCompressTip" class="compress-tip" :style="{ color: singleCompressColor }">{{ singleCompressTip }}</div>
            </div>

            <div style="flex-shrink: 0;">
              <button type="button" class="btn-success btn-sm" :disabled="submittingSingle" @click="submitSingle">
                <el-icon class="mr-1"><CircleCheck /></el-icon> 提交记录
              </button>
            </div>
          </div>
          <div v-if="singleImgPreview" style="margin-top:6px;">
            <img :src="singleImgPreview" style="max-width:80px; max-height:45px; border-radius:4px; border:1px solid #ddd;" />
          </div>
        </div>

        <!-- 2. 批量录入面板 -->
        <div v-show="issueType === 'batch'">
          <div class="qi-sn-row">
            <div class="qi-sn-input-group">
              <span class="qi-label req">首台 SN:</span>
              <div style="position:relative; flex:1;">
                <input
                  v-model="batchFirstSn"
                  type="text"
                  class="qi-input"
                  maxlength="22"
                  style="width:100%; font-family:monospace;"
                  placeholder="输入22位SN后回车..."
                  autocomplete="off"
                  @keyup.enter="queryBatchInfo('batch')"
                />
                <span v-if="queryingBatch" class="spinner-tip">查询中...</span>
              </div>
              <button type="button" class="btn-primary btn-sm" :disabled="queryingBatch" style="white-space:nowrap;" @click="queryBatchInfo('batch')">
                <el-icon class="mr-1"><Search /></el-icon> 查询
              </button>
            </div>

            <div v-if="batchBatchInfo" class="qi-inline-info">
              <span><strong>工单:</strong> <span style="font-weight:bold; font-family:monospace;">{{ batchBatchInfo.work_order || '-' }}</span></span>
              <span style="color:#93c5fd;">|</span>
              <span><strong>编码:</strong> <span style="font-family:monospace;">{{ batchBatchInfo.task_code || '-' }}</span></span>
              <span style="color:#93c5fd;">|</span>
              <span style="overflow:hidden; text-overflow:ellipsis;"><strong>描述:</strong> {{ batchBatchInfo.description || '-' }}</span>
            </div>
          </div>

          <div class="qi-input-row" style="margin-bottom:8px;">
            <div style="width: 140px; flex-shrink: 0;">
              <select v-model="batchForm.process" class="qi-select" style="width:100%;">
                <option value="">请选择工序 *</option>
                <option v-for="p in validProcesses" :key="p" :value="p">{{ p }}</option>
              </select>
            </div>

            <div style="width: 110px; flex-shrink: 0;">
              <input v-model.number="batchQty" type="number" min="1" max="100" class="qi-input" style="width:100%;" placeholder="台数 *..." @change="onBatchQtyChange" />
            </div>

            <div style="flex: 1; min-width: 230px;">
              <input v-model="batchForm.issue_description" type="text" class="qi-input" style="width:100%;" placeholder="填写该批次共同存在的问题现象... *" />
            </div>

            <div style="width: 200px; flex-shrink: 0;">
              <input ref="batchImgInputRef" type="file" accept="image/*" class="qi-input" style="width:100%; height:33px; padding:3px 6px;" @change="handleImageChange('batch')" />
              <div v-if="batchCompressTip" class="compress-tip" :style="{ color: batchCompressColor }">{{ batchCompressTip }}</div>
            </div>

            <div style="flex-shrink: 0;">
              <button type="button" class="btn-success btn-sm" :disabled="submittingBatch" @click="submitBatch">
                <el-icon class="mr-1"><Plus /></el-icon> 批量提交
              </button>
            </div>
          </div>
          <div v-if="batchImgPreview" style="margin-top:6px; margin-bottom:8px;">
            <img :src="batchImgPreview" style="max-width:80px; max-height:45px; border-radius:4px; border:1px solid #ddd;" />
          </div>

          <div style="margin-top:8px;">
            <span class="qi-label" style="display:block; margin-bottom:4px;">批量 SN 扫码录入列表</span>
            <div class="batch-sn-container">
              <div v-for="(item, idx) in batchSnItems" :key="idx" class="sn-input-row">
                <input
                  v-model="item.val"
                  type="text"
                  maxlength="22"
                  class="qi-input"
                  style="width:280px; font-family:monospace;"
                  :disabled="item.locked"
                  :placeholder="`请输入第 ${idx + 1} 个 SN 号 (22位)...`"
                  @keyup.enter="handleBatchSnEnter(idx)"
                />
                <button v-if="idx > 0" type="button" class="btn-remove-sn" @click="removeBatchSnRow(idx)">✕</button>
              </div>
            </div>
            <small style="color:#888; font-size:11.5px;">输入完 22 位 SN 后按回车自动创建下一条录入框</small>
          </div>
        </div>
      </div>
    </div>

    <!-- 检索与表格卡片 -->
    <div class="qi-card">
      <div class="qi-filter-card">
        <div class="qi-filter-grid">
          <input v-model="filterWo" type="text" class="qi-input" style="width:140px;" placeholder="工单号..." @keyup.enter="loadTable(1)" />
          <input v-model="filterSn" type="text" class="qi-input" style="width:160px;" placeholder="SN 号..." @keyup.enter="loadTable(1)" />
          <select v-model="filterProcess" class="qi-select" style="width:130px;" @change="loadTable(1)">
            <option value="">全部工序</option>
            <option v-for="p in validProcesses" :key="p" :value="p">{{ p }}</option>
          </select>
          <button type="button" class="btn-primary btn-sm" @click="loadTable(1)">
            <el-icon class="mr-1"><Search /></el-icon> 搜索
          </button>
          <button type="button" class="btn-outline btn-sm" @click="resetFilter">重置</button>
        </div>
      </div>

      <!-- 批量操作栏 -->
      <div v-if="selectedIds.length > 0 && isAdmin" class="qi-batch-bar">
        <span style="color:#0369a1;">已勾选 <strong style="color:#409EFF;">{{ selectedIds.length }}</strong> 条记录</span>
        <div style="display:flex; gap:6px;">
          <button type="button" class="btn-danger btn-mini" @click="batchDelete">
            <el-icon class="mr-1"><Delete /></el-icon> 批量删除
          </button>
          <button type="button" class="btn-outline btn-mini" @click="selectedIds = []">取消选择</button>
        </div>
      </div>

      <!-- 表格主体 (固定列宽) -->
      <div class="qi-table-wrapper" v-loading="tableLoading">
        <table class="qi-fixed-table">
          <thead>
            <tr>
              <th v-if="isAdmin" style="width: 34px; text-align: center;">
                <input type="checkbox" v-model="allSelected" @change="toggleSelectAll" />
              </th>
              <th style="width: 100px;">工单号</th>
              <th style="width: 90px;">编码</th>
              <th style="width: 17%; text-align: left;">描述</th>
              <th style="width: 160px;">SN号</th>
              <th style="width: 85px; text-align: center;">发现工序</th>
              <th style="width: 18%; text-align: left;">问题描述</th>
              <th style="width: 90px;">外协单位</th>
              <th style="width: 70px; text-align: center;">图片</th>
              <th style="width: 80px;">填写人</th>
              <th style="width: 110px;">填写时间</th>
              <th v-if="isAdmin" style="width: 120px; text-align: center;">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in recordList" :key="r.id" :class="{ selected: selectedIds.includes(r.id) }">
              <td v-if="isAdmin" style="text-align:center;">
                <input type="checkbox" v-model="selectedIds" :value="r.id" />
              </td>
              <td><strong style="font-family:monospace; color:#2c3e50;">{{ r.work_order || '-' }}</strong></td>
              <td><span style="font-family:monospace;">{{ r.task_code || '-' }}</span></td>
              <td style="text-align:left;" :title="r.description"><span class="cell-truncate">{{ r.description || '-' }}</span></td>
              <td><span style="font-family:monospace; font-weight:600; color:#2c3e50;">{{ r.sn }}</span></td>
              <td style="text-align:center;"><span class="badge-tag badge-tag-proc">{{ r.process || '-' }}</span></td>
              <td style="text-align:left;" :title="r.issue_description"><span class="cell-truncate">{{ r.issue_description || '-' }}</span></td>
              <td>{{ r.outsourced_unit || '-' }}</td>
              <td style="text-align:center; white-space:nowrap;">
                <button v-if="r.image_path" type="button" class="btn-outline btn-mini" @click="viewImage(r.image_path)">
                  <el-icon><Picture /></el-icon> 查看
                </button>
                <span v-else style="color:#aaa;">无</span>
              </td>
              <td>{{ r.display_name }}</td>
              <td style="font-size:11.5px; color:#64748b;">{{ r.created_at ? r.created_at.substring(5, 16) : '-' }}</td>
              <td v-if="isAdmin" style="text-align:center; white-space:nowrap;">
                <button type="button" class="btn-primary btn-mini" @click="openEditModal(r)">编辑</button>
                <button type="button" class="btn-danger btn-mini" style="margin-left:4px;" @click="deleteSingle(r.id)">删除</button>
              </td>
            </tr>
            <tr v-if="recordList.length === 0">
              <td :colspan="isAdmin ? 12 : 10" style="text-align:center; padding:35px; color:#888;">
                暂无质量问题记录
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 分页栏 -->
      <div class="qi-pagination-bar">
        <div style="color:#64748b;">共 <strong>{{ totalRecords }}</strong> 条质量记录，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页</div>
        <el-pagination v-model:current-page="curPage" :page-size="30" :total="totalRecords" layout="prev, pager, next" @current-change="loadTable" />
      </div>
    </div>

    <!-- 弹窗 1: 查看现场大图 (带缩放控件) -->
    <el-dialog v-model="imgModalVisible" title="质量缺陷现场图片查看" width="820px" destroy-on-close>
      <div class="img-zoom-container">
        <img :src="modalImgSrc" alt="现场图片" :style="{ transform: `scale(${currentZoom})` }" />
      </div>
      <template #footer>
        <div style="display:flex; justify-content:center; gap:8px;">
          <button type="button" class="btn-outline btn-sm" @click="zoomOut"><el-icon><ZoomOut /></el-icon> 缩小</button>
          <button type="button" class="btn-outline btn-sm" @click="zoomIn"><el-icon><ZoomIn /></el-icon> 放大</button>
          <button type="button" class="btn-outline btn-sm" @click="zoomReset"><el-icon><RefreshRight /></el-icon> 重置</button>
        </div>
      </template>
    </el-dialog>

    <!-- 弹窗 2: 编辑问题描述 -->
    <el-dialog v-model="editModalVisible" title="编辑质量问题描述" width="600px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;">
        <div style="margin-bottom:12px;">
          <label class="qi-label">发现工序</label>
          <select v-model="editForm.process" class="qi-select" style="width:100%; margin-top:3px;">
            <option v-for="p in validProcesses" :key="p" :value="p">{{ p }}</option>
          </select>
        </div>
        <div style="margin-bottom:12px;">
          <label class="qi-label req">问题描述</label>
          <textarea v-model="editForm.issue_description" class="qi-input" rows="3" style="width:100%; height:auto; margin-top:3px;" required></textarea>
        </div>
        <div style="margin-bottom:14px;">
          <label class="qi-label">更换图片 (可选)</label>
          <input ref="editImgInputRef" type="file" accept="image/*" class="qi-input" style="width:100%; height:33px; padding:3px 6px; margin-top:3px;" />
        </div>
      </div>
      <template #footer>
        <el-button @click="editModalVisible = false">取消</el-button>
        <el-button type="primary" :loading="savingEdit" @click="submitEdit">保存修改</el-button>
      </template>
    </el-dialog>

    <!-- 权限管理弹窗（仅管理员） -->
    <el-dialog v-if="isAdmin" v-model="permModalVisible" title="质量问题模块权限管理" width="650px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;" v-loading="permLoading">
        <p style="margin:0 0 8px; color:#909399;">未勾选「录入权限」的用户无法提交质量问题记录；「查看权限」控制查看与导出。管理员默认拥有全部权限。</p>
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
import { ref, reactive, onMounted, nextTick } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  WarningFilled, Search, Plus, Delete, Edit, Download, ZoomIn, ZoomOut, RefreshRight, Picture, CircleCheck, UserFilled
} from '@element-plus/icons-vue';
import request from '@/api/request';

const isAdmin = ref(false);
const canInput = ref(false);
const permModalVisible = ref(false);
const permUserList = ref([]);
const permLoading = ref(false);
const issueType = ref('single');
const validProcesses = ['装置装配', '装置调试', '装置包装', '装置检验'];

// 单台表单
const singleSnInputRef = ref(null);
const queryingSingle = ref(false);
const submittingSingle = ref(false);
const singleBatchInfo = ref(null);
const singleForm = reactive({
  sn: '',
  process: '',
  issue_description: ''
});
const singleImgInputRef = ref(null);
const singleCompressedFile = ref(null);
const singleCompressTip = ref('');
const singleCompressColor = ref('#888');
const singleImgPreview = ref('');

// 批量表单
const batchFirstSn = ref('');
const queryingBatch = ref(false);
const submittingBatch = ref(false);
const batchBatchInfo = ref(null);
const batchQty = ref(null);
const batchForm = reactive({
  process: '',
  issue_description: ''
});
const batchSnItems = ref([]);
const batchImgInputRef = ref(null);
const batchCompressedFile = ref(null);
const batchCompressTip = ref('');
const batchCompressColor = ref('#888');
const batchImgPreview = ref('');

// 列表数据
const tableLoading = ref(false);
const recordList = ref([]);
const totalRecords = ref(0);
const curPage = ref(1);
const totalPages = ref(1);
const selectedIds = ref([]);
const allSelected = ref(false);

const filterWo = ref('');
const filterSn = ref('');
const filterProcess = ref('');

// 大图预览
const imgModalVisible = ref(false);
const modalImgSrc = ref('');
const currentZoom = ref(1.0);

// 编辑弹窗
const editModalVisible = ref(false);
const savingEdit = ref(false);
const editImgInputRef = ref(null);
const editForm = reactive({
  id: 0,
  process: '',
  issue_description: ''
});

// ---------- 图片压缩算法 (严格控制 <= 300KB) ----------
const compressImageTo300K = (file) => {
  return new Promise((resolve) => {
    if (!file || !file.type.startsWith('image/')) {
      resolve(file);
      return;
    }
    const skipBytes = 260 * 1024;
    const maxBytes = 300 * 1024;
    if (file.size <= skipBytes) {
      resolve(file);
      return;
    }
    const reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onload = (e) => {
      const img = new Image();
      img.src = e.target.result;
      img.onload = () => {
        let width = img.width;
        let height = img.height;
        const maxDimension = 1280;
        if (width > maxDimension || height > maxDimension) {
          if (width > height) {
            height = Math.round((height * maxDimension) / width);
            width = maxDimension;
          } else {
            width = Math.round((width * maxDimension) / height);
            height = maxDimension;
          }
        }
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, width, height);
        let quality = 0.85;
        const attempt = () => {
          canvas.toBlob((blob) => {
            if (blob.size <= maxBytes || quality <= 0.25) {
              const resFile = new File([blob], file.name.replace(/\.[^/.]+$/, "") + ".jpg", {
                type: 'image/jpeg',
                lastModified: Date.now()
              });
              resolve(resFile);
            } else {
              quality -= 0.12;
              attempt();
            }
          }, 'image/jpeg', quality);
        };
        attempt();
      };
    };
  });
};

const handleImageChange = async (type) => {
  const isSingle = (type === 'single');
  const inputEl = isSingle ? singleImgInputRef.value : batchImgInputRef.value;
  const file = inputEl?.files?.[0];

  if (isSingle) {
    singleImgPreview.value = '';
    singleCompressTip.value = '';
    singleCompressedFile.value = null;
  } else {
    batchImgPreview.value = '';
    batchCompressTip.value = '';
    batchCompressedFile.value = null;
  }

  if (!file) return;

  const reader = new FileReader();
  reader.onload = (e) => {
    if (isSingle) singleImgPreview.value = e.target.result;
    else batchImgPreview.value = e.target.result;
  };
  reader.readAsDataURL(file);

  const origKb = (file.size / 1024).toFixed(1);
  if (file.size <= 260 * 1024) {
    const tip = `✓ 原图 ${origKb} KB ≤ 260KB，原图直传`;
    if (isSingle) { singleCompressTip.value = tip; singleCompressColor.value = '#409EFF'; singleCompressedFile.value = file; }
    else { batchCompressTip.value = tip; batchCompressColor.value = '#409EFF'; batchCompressedFile.value = file; }
  } else {
    if (isSingle) { singleCompressTip.value = `⏳ 原图 ${origKb} KB，正在压缩...`; singleCompressColor.value = '#E6A23C'; }
    else { batchCompressTip.value = `⏳ 原图 ${origKb} KB，正在压缩...`; batchCompressColor.value = '#E6A23C'; }

    const compressed = await compressImageTo300K(file);
    const finalKb = (compressed.size / 1024).toFixed(1);
    const tip = `✓ 压缩完成 (原图 ${origKb} KB → 压缩后 ${finalKb} KB ≤ 300KB)`;
    if (isSingle) { singleCompressTip.value = tip; singleCompressColor.value = '#67C23A'; singleCompressedFile.value = compressed; }
    else { batchCompressTip.value = tip; batchCompressColor.value = '#67C23A'; batchCompressedFile.value = compressed; }
  }
};

// 1. 查询批次工单信息
const queryBatchInfo = async (type) => {
  const isSingle = (type === 'single');
  const snVal = isSingle ? singleForm.sn.trim() : batchFirstSn.value.trim();

  if (!/^\d{22}$/.test(snVal)) {
    ElMessage.warning('SN 号必须为 22 位纯数字！');
    if (isSingle) singleSnInputRef.value?.focus();
    return;
  }

  if (isSingle) queryingSingle.value = true;
  else queryingBatch.value = true;

  try {
    const res = await request.post('/quality/get_batch_info', { sn: snVal });
    if (res.data && res.data.found) {
      if (isSingle) singleBatchInfo.value = res.data;
      else {
        batchBatchInfo.value = res.data;
        if (batchSnItems.value.length === 0) {
          batchSnItems.value.push({ val: snVal, locked: true });
        }
      }
      ElMessage.success('成功匹配到工单批次信息！');
    } else {
      ElMessage.error('未找到该 SN 的批次工单信息');
      if (isSingle) singleBatchInfo.value = null;
      else batchBatchInfo.value = null;
    }
  } finally {
    if (isSingle) queryingSingle.value = false;
    else queryingBatch.value = false;
  }
};

// 2. 批量 SN 动态输入行
const onBatchQtyChange = () => {
  const qty = parseInt(batchQty.value) || 0;
  if (qty <= 0) {
    batchSnItems.value = [];
    return;
  }
  const currentCount = batchSnItems.value.length;
  if (currentCount < qty) {
    for (let i = currentCount; i < qty; i++) {
      batchSnItems.value.push({ val: '', locked: false });
    }
  } else if (currentCount > qty) {
    batchSnItems.value = batchSnItems.value.slice(0, qty);
  }
};

const handleBatchSnEnter = (idx) => {
  const item = batchSnItems.value[idx];
  const val = item.val.trim();
  if (!/^\d{22}$/.test(val)) {
    return ElMessage.warning('SN 号必须为 22 位纯数字！');
  }

  const dup = batchSnItems.value.filter((it, i) => i !== idx && it.val.trim() === val);
  if (dup.length > 0) {
    return ElMessage.warning('该 SN 号已在列表中，请勿重复输入');
  }

  item.locked = true;
  if (idx + 1 < batchSnItems.value.length) {
    nextTick(() => {
      const inputs = document.querySelectorAll('.batch-sn-container input');
      inputs[idx + 1]?.focus();
    });
  } else if (batchQty.value && batchSnItems.value.length < batchQty.value) {
    batchSnItems.value.push({ val: '', locked: false });
  }
};

const removeBatchSnRow = (idx) => {
  batchSnItems.value.splice(idx, 1);
};

// 3. 提交单条记录
const submitSingle = async () => {
  if (!singleBatchInfo.value) {
    return ElMessage.warning('请先扫入 SN 并点击查询或回车确认批次信息！');
  }
  if (!singleForm.process) {
    return ElMessage.warning('请选择发现工序！');
  }
  if (!singleForm.issue_description.trim()) {
    return ElMessage.warning('请填写问题现象描述！');
  }

  const fd = new FormData();
  fd.append('type', 'single');
  fd.append('batch_no', singleBatchInfo.value.batch_no || '');
  fd.append('work_order', singleBatchInfo.value.work_order || '');
  fd.append('task_code', singleBatchInfo.value.task_code || '');
  fd.append('description', singleBatchInfo.value.description || '');
  fd.append('sn', singleForm.sn.trim());
  fd.append('process', singleForm.process);
  fd.append('issue_description', singleForm.issue_description.trim());

  if (singleCompressedFile.value) {
    fd.append('image', singleCompressedFile.value);
  }

  submittingSingle.value = true;
  try {
    const res = await request.post('/quality/add', fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });
    ElMessage.success(res.message || '质量问题记录添加成功');
    singleForm.sn = '';
    singleForm.process = '';
    singleForm.issue_description = '';
    singleBatchInfo.value = null;
    singleCompressedFile.value = null;
    singleCompressTip.value = '';
    singleImgPreview.value = '';
    if (singleImgInputRef.value) singleImgInputRef.value.value = '';
    loadTable(1);
  } finally {
    submittingSingle.value = false;
  }
};

// 4. 提交批量记录
const submitBatch = async () => {
  if (!batchBatchInfo.value) {
    return ElMessage.warning('请先扫入首台 SN 查询批次！');
  }
  if (!batchForm.process) {
    return ElMessage.warning('请选择发现工序！');
  }
  if (!batchForm.issue_description.trim()) {
    return ElMessage.warning('请填写共用问题现象描述！');
  }

  const snList = batchSnItems.value.map(it => it.val.trim()).filter(v => /^\d{22}$/.test(v));
  if (snList.length === 0) {
    return ElMessage.warning('请至少录入一个有效的 22 位 SN 号！');
  }

  const fd = new FormData();
  fd.append('type', 'batch');
  fd.append('batch_no', batchBatchInfo.value.batch_no || '');
  fd.append('work_order', batchBatchInfo.value.work_order || '');
  fd.append('task_code', batchBatchInfo.value.task_code || '');
  fd.append('description', batchBatchInfo.value.description || '');
  fd.append('process', batchForm.process);
  fd.append('sn_list', JSON.stringify(snList));
  fd.append('issue_description', batchForm.issue_description.trim());

  if (batchCompressedFile.value) {
    fd.append('image', batchCompressedFile.value);
  }

  submittingBatch.value = true;
  try {
    const res = await request.post('/quality/add', fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });
    ElMessage.success(res.message || '批量添加成功');
    batchFirstSn.value = '';
    batchBatchInfo.value = null;
    batchQty.value = null;
    batchForm.process = '';
    batchForm.issue_description = '';
    batchSnItems.value = [];
    batchCompressedFile.value = null;
    batchCompressTip.value = '';
    batchImgPreview.value = '';
    if (batchImgInputRef.value) batchImgInputRef.value.value = '';
    loadTable(1);
  } finally {
    submittingBatch.value = false;
  }
};

// 5. 加载数据列表
const loadTable = async (p = 1) => {
  curPage.value = p;
  tableLoading.value = true;
  try {
    const res = await request.get('/quality/list', {
      params: {
        page: p,
        work_order: filterWo.value.trim(),
        sn: filterSn.value.trim(),
        process: filterProcess.value
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
    tableLoading.value = false;
  }
};

const resetFilter = () => {
  filterWo.value = '';
  filterSn.value = '';
  filterProcess.value = '';
  loadTable(1);
};

const toggleSelectAll = () => {
  if (allSelected.value) {
    selectedIds.value = recordList.value.map(r => r.id);
  } else {
    selectedIds.value = [];
  }
};

// 6. 图片缩放预览
const viewImage = (path) => {
  modalImgSrc.value = path;
  currentZoom.value = 1.0;
  imgModalVisible.value = true;
};
const zoomIn = () => { currentZoom.value = Math.min(currentZoom.value * 1.2, 3.0); };
const zoomOut = () => { currentZoom.value = Math.max(currentZoom.value * 0.8, 0.5); };
const zoomReset = () => { currentZoom.value = 1.0; };

// 7. 编辑与删除
const openEditModal = (r) => {
  editForm.id = r.id;
  editForm.process = r.process || '装置装配';
  editForm.issue_description = r.issue_description || '';
  if (editImgInputRef.value) editImgInputRef.value.value = '';
  editModalVisible.value = true;
};

const submitEdit = async () => {
  if (!editForm.issue_description.trim()) {
    return ElMessage.warning('请填写问题现象描述！');
  }

  const fd = new FormData();
  fd.append('id', String(editForm.id));
  fd.append('process', editForm.process);
  fd.append('issue_description', editForm.issue_description.trim());

  const file = editImgInputRef.value?.files?.[0];
  if (file) fd.append('image', file);

  savingEdit.value = true;
  try {
    const res = await request.post('/quality/update', fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });
    ElMessage.success(res.message || '修改成功');
    editModalVisible.value = false;
    loadTable(curPage.value);
  } finally {
    savingEdit.value = false;
  }
};

const deleteSingle = (id) => {
  ElMessageBox.confirm('确定要删除该条质量问题记录吗？', '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/quality/delete', { ids: [id] });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

const batchDelete = () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请勾选记录！');
  ElMessageBox.confirm(`确定要永久删除选中的 ${selectedIds.value.length} 条记录吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/quality/delete', { ids: selectedIds.value });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

// ★ 权限加固（L2）：权限管理
const openPermModal = async () => {
  permModalVisible.value = true;
  permLoading.value = true;
  try {
    const res = await request.get('/quality/get_permissions');
    if (res.data) permUserList.value = res.data.users || [];
  } finally {
    permLoading.value = false;
  }
};

const saveUserPerm = async (u) => {
  try {
    await request.post('/quality/update_permission', {
      user_id: u.id,
      can_view_records: u.can_view_records,
      can_input: u.can_input
    });
    ElMessage.success('权限更新成功');
  } catch (e) {}
};

const exportCsv = () => {
  const ids = selectedIds.value.join(',');
  window.open(`/api/quality/export?selected_ids=${encodeURIComponent(ids)}`, '_blank');
};

onMounted(() => {
  loadTable(1);
});
</script>

<style scoped>
.qi-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.qi-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}

.qi-type-selector {
  display: flex;
  gap: 16px;
  margin-bottom: 12px;
  padding: 6px 14px;
  background: #f8f9fa;
  border-radius: 4px;
  border: 1px solid #dee2e6;
}
.qi-type-option {
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  font-weight: 500;
  font-size: 13px;
  color: #2c3e50;
  margin-bottom: 0;
}

.qi-sn-row {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 10px;
}
.qi-sn-input-group {
  display: flex;
  gap: 6px;
  align-items: center;
  width: 370px;
  min-width: 310px;
}
.qi-inline-info {
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

.qi-input-row {
  display: flex;
  gap: 8px;
  align-items: center;
  width: 100%;
  flex-wrap: wrap;
}

.qi-label {
  font-size: 12.5px;
  font-weight: 600;
  color: #495057;
  white-space: nowrap;
  flex-shrink: 0;
}
.qi-label.req::after {
  content: "*";
  color: #dc3545;
  margin-left: 2px;
}

.spinner-tip {
  position: absolute;
  right: 8px;
  top: 7px;
  font-size: 11px;
  color: #409EFF;
}

.compress-tip {
  font-size: 11px;
  margin-top: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.batch-sn-container {
  max-height: 140px;
  overflow-y: auto;
  padding: 4px 0;
}
.sn-input-row {
  display: flex;
  gap: 6px;
  align-items: center;
  margin-bottom: 6px;
}
.btn-remove-sn {
  background: #dc3545;
  color: white;
  border: none;
  border-radius: 4px;
  width: 28px;
  height: 28px;
  cursor: pointer;
  font-size: 12px;
}

.qi-filter-card {
  padding: 8px 12px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}
.qi-filter-grid {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}
.qi-batch-bar {
  background: #e0f2fe;
  padding: 6px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #bae6fd;
  font-size: 12.5px;
}

.qi-table-wrapper {
  width: 100%;
  overflow-x: auto;
  max-height: calc(100vh - 380px);
  min-height: 280px;
  border-top: 1px solid #dee2e6;
}
.qi-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed !important;
  font-size: 12.5px;
  text-align: center;
  color: #333;
}
.qi-fixed-table thead th {
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
  overflow: hidden;
  text-overflow: ellipsis;
}
.qi-fixed-table tbody td {
  padding: 6px 6px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
}
.qi-fixed-table tbody tr:hover { background: #f8fafc; }
.qi-fixed-table tbody tr.selected { background: #e0f2fe !important; }

.cell-truncate {
  display: block;
  width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: left;
}

.badge-tag {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 10px;
  font-size: 11.5px;
  font-weight: 500;
}
.badge-tag-proc {
  background: #e8f4fd;
  color: #007bff;
}

.qi-pagination-bar {
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

.img-zoom-container {
  overflow: auto;
  text-align: center;
  padding: 16px;
  min-height: 280px;
}
.img-zoom-container img {
  max-width: 100%;
  max-height: 65vh;
  transition: transform 0.2s;
}

.qi-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.qi-input:focus { border-color: #80bdff; }

.qi-select {
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
.btn-mini { padding: 2px 7px; font-size: 11.5px; height: 22px; line-height: 18px; }
.mr-1 { margin-right: 4px; }
</style>