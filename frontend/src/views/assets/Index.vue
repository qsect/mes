<template>
  <div class="ast-container">
    <!-- 顶部标题卡片 -->
    <div class="ast-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Box /></el-icon>
          资产台账管理
        </span>
      </div>
    </div>

    <!-- 顶部分类导航栏 -->
    <div class="ast-nav-bar">
      <button
        type="button"
        class="ast-nav-btn dash-tab"
        :class="{ active: currentTab === '__DASHBOARD__' }"
        @click="switchTab('__DASHBOARD__')"
      >
        <el-icon class="mr-1"><DataLine /></el-icon> 资产看板
      </button>

      <button
        v-for="c in categoryList"
        :key="c.id"
        type="button"
        class="ast-nav-btn"
        :class="{ active: currentTab === c.name }"
        @click="switchTab(c.name)"
      >
        <el-icon class="mr-1"><Folder /></el-icon> {{ c.name }}
      </button>

      <button
        v-if="isAdmin"
        type="button"
        class="ast-nav-btn admin-all"
        :class="{ active: currentTab === '__ALL__' }"
        style="margin-left:auto;"
        @click="switchTab('__ALL__')"
      >
        <el-icon class="mr-1"><Box /></el-icon> 资产总表 (全员平铺)
      </button>
    </div>

    <!-- ================= 1. 资产看板视图 ================= -->
    <div v-show="currentTab === '__DASHBOARD__'">
      <div class="dash-kpi-grid">
        <div class="dash-kpi-card" style="border-left: 4px solid #409EFF;">
          <div>
            <div style="font-size:12px; color:#64748b; font-weight:600;">在册资产总数</div>
            <div class="dash-kpi-num" style="color:#409EFF;">{{ kpiData.total_count }}</div>
          </div>
          <el-icon :size="32" style="color:#409EFF; opacity:0.3;"><Box /></el-icon>
        </div>

        <div class="dash-kpi-card" style="border-left: 4px solid #67C23A;">
          <div>
            <div style="font-size:12px; color:#64748b; font-weight:600;">资产总价值估值</div>
            <div class="dash-kpi-num" style="color:#67C23A;">
              ¥{{ Number(kpiData.total_value || 0).toLocaleString('zh-CN', {minimumFractionDigits: 2}) }}
            </div>
          </div>
          <el-icon :size="32" style="color:#67C23A; opacity:0.3;"><DataLine /></el-icon>
        </div>

        <div class="dash-kpi-card" style="border-left: 4px solid #E6A23C;">
          <div>
            <div style="font-size:12px; color:#64748b; font-weight:600;">正常使用中资产</div>
            <div class="dash-kpi-num" style="color:#E6A23C;">{{ kpiData.in_use_count }}</div>
          </div>
          <el-icon :size="32" style="color:#E6A23C; opacity:0.3;"><Check /></el-icon>
        </div>

        <div class="dash-kpi-card" style="border-left: 4px solid #F56C6C;">
          <div>
            <div style="font-size:12px; color:#F56C6C; font-weight:600;">异常/报修/报废预警</div>
            <div class="dash-kpi-num" style="color:#F56C6C;">{{ kpiData.abnormal_count }}</div>
          </div>
          <el-icon :size="32" style="color:#F56C6C; opacity:0.3;"><Warning /></el-icon>
        </div>
      </div>

      <h4 style="font-size:13.5px; font-weight:bold; color:#2c3e50; margin:14px 0 10px 0;">
        <el-icon style="color:#409EFF; vertical-align:middle; margin-right:4px;"><Folder /></el-icon>
        各资产分类透视 (点击直接钻取)：
      </h4>

      <div class="dash-cat-grid">
        <div
          v-for="c in kpiData.categories"
          :key="c.id"
          class="dash-cat-card"
          @click="switchTab(c.name)"
        >
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
            <strong style="font-size:13.5px; color:#2c3e50;">
              <el-icon style="color:#409EFF; vertical-align:middle; margin-right:4px;"><Folder /></el-icon>
              {{ c.name }}
            </strong>
            <span class="badge" style="background:#e8f4fd; color:#409EFF; font-size:11.5px; padding:2px 6px; border-radius:4px;">
              {{ c.count }} 件
            </span>
          </div>
          <div style="font-size:12px; color:#64748b; margin-bottom:6px;">
            资产估值: <strong style="color:#2c3e50;">¥{{ Number(c.total_price || 0).toLocaleString('zh-CN', {minimumFractionDigits: 2}) }}</strong>
          </div>
          <div style="font-size:11px; display:flex; justify-content:space-between; align-items:center; border-top:1px dashed #eee; padding-top:6px;" :style="{ color: c.abnormal_count > 0 ? '#F56C6C' : '#67C23A' }">
            <span>{{ c.abnormal_count > 0 ? `⚠️ 异常待修: ${c.abnormal_count}` : '✅ 设备状态良好' }}</span>
            <span style="color:#409EFF; font-weight:600;">进入台账 &gt;</span>
          </div>
        </div>
      </div>

      <!-- 异常资产列表 -->
      <div class="ast-card" style="margin-top:12px;">
        <div style="padding:10px 14px; background:#fff5f5; border-bottom:1px solid #f5c6cb; color:#721c24; font-weight:600; font-size:13px;">
          <el-icon style="color:#F56C6C; vertical-align:middle; margin-right:4px;"><Warning /></el-icon>
          需关注的异常/损坏/待修资产列表 (实时汇总)
        </div>
        <div class="ast-table-wrapper" style="max-height:260px; min-height:120px;">
          <table class="ast-fixed-table">
            <thead>
              <tr style="background:#fff0f0;">
                <th style="width:70px;">分类</th>
                <th style="width:105px;">资产编号</th>
                <th>资产名称</th>
                <th style="width:80px;">责任人</th>
                <th>存放地点</th>
                <th style="width:80px;">当前状态</th>
                <th>异常备注</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in kpiData.abnormal_list" :key="a.id" class="row-danger">
                <td><span class="badge" style="background:#f8d7da; color:#721c24; padding:2px 6px; border-radius:4px;">{{ a.category }}</span></td>
                <td><strong style="font-family:monospace;">{{ a.code }}</strong></td>
                <td><strong>{{ a.name }}</strong></td>
                <td>{{ a.user || '-' }}</td>
                <td>{{ a.location || '-' }}</td>
                <td><span class="badge" style="background:#F56C6C; color:white; padding:2px 6px; border-radius:4px;">{{ a.status || '异常' }}</span></td>
                <td style="text-align:left; color:#721c24;">{{ a.remark || '-' }}</td>
              </tr>
              <tr v-if="!kpiData.abnormal_list || kpiData.abnormal_list.length === 0">
                <td colspan="7" style="padding:25px; color:#67C23A; text-align:center; font-weight:bold;">
                  🎉 无异常/报修资产数据
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ================= 2. 资产数据列表视图 ================= -->
    <div v-show="currentTab !== '__DASHBOARD__'">
      <div class="ast-card">
        <div style="padding:10px 14px; background:#f8f9fa; border-bottom:1px solid #dee2e6; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
          <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
            <button type="button" class="btn-primary btn-sm" @click="openAssetModal()">
              <el-icon class="mr-1"><Plus /></el-icon> 人工录入
            </button>
            <button type="button" class="btn-outline btn-sm" @click="openImportModal">
              <el-icon class="mr-1"><Upload /></el-icon> 批量导入
            </button>
            <button type="button" class="btn-outline btn-sm" @click="handleBatchExport">
              <el-icon class="mr-1"><Download /></el-icon> 批量导出
            </button>
            <button
              v-if="isAdmin || currentUserPerms.can_delete"
              type="button"
              class="btn-danger btn-sm"
              @click="handleBatchDelete"
            >
              <el-icon class="mr-1"><Delete /></el-icon> 批量删除
            </button>
            <button
              v-if="isAdmin"
              type="button"
              class="btn-outline btn-sm"
              style="border-color:#67C23A; color:#67C23A;"
              @click="openPermsModal"
            >
              <el-icon class="mr-1"><Setting /></el-icon> 资产权限分配
            </button>
            <button
              v-if="isAdmin"
              type="button"
              class="btn-outline btn-sm"
              style="border-color:#8e44ad; color:#8e44ad;"
              @click="openCategoryModal"
            >
              <el-icon class="mr-1"><Setting /></el-icon> 分类字段管理
            </button>
          </div>

          <div style="display:flex; gap:6px; align-items:center;">
            <input
              v-model="searchKeyword"
              type="text"
              class="ast-input"
              style="width:210px;"
              placeholder="搜索编号/名称/使用人/型号..."
              @keyup.enter="loadAssets(1)"
            />
            <button type="button" class="btn-primary btn-sm" @click="loadAssets(1)">
              <el-icon class="mr-1"><Search /></el-icon> 查询
            </button>
          </div>
        </div>

        <div class="ast-table-wrapper" v-loading="tableLoading">
          <table class="ast-fixed-table">
            <thead>
              <tr>
                <th style="width:34px;"><input type="checkbox" v-model="allSelected" @change="toggleSelectAll" /></th>
                <th v-for="col in activeTableCols" :key="col.key" :style="{ width: getColWidth(col.key) }">
                  {{ col.label }}
                </th>
                <th style="width:75px; text-align:center;">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in assetList" :key="item.id" :class="{ 'row-danger': isAbnormalRow(item) }">
                <td><input type="checkbox" v-model="selectedIds" :value="item.id" /></td>
                <td v-for="col in activeTableCols" :key="col.key" :title="String(item[col.key] || '')">
                  <template v-if="col.key === 'image_path'">
                    <button
                      v-if="item.image_path"
                      type="button"
                      class="btn-outline btn-mini"
                      @click="previewImage(item.image_path)"
                    >
                      <el-icon class="mr-1"><Picture /></el-icon> 查看
                    </button>
                    <span v-else style="color:#aaa;">无</span>
                  </template>
                  <template v-else-if="col.key === 'code'">
                    <strong style="font-family:monospace; color:#2c3e50;">{{ item.code }}</strong>
                  </template>
                  <template v-else-if="col.key === 'price'">
                    ¥{{ Number(item.price || 0).toFixed(2) }}
                  </template>
                  <template v-else>
                    {{ item[col.key] || '-' }}
                  </template>
                </td>
                <td style="white-space:nowrap; text-align:center;">
                  <button type="button" class="btn-primary btn-mini" @click="openAssetModal(item)">编辑</button>
                </td>
              </tr>
              <tr v-if="assetList.length === 0">
                <td :colspan="activeTableCols.length + 2" style="padding:35px; color:#888;">
                  暂无【{{ currentTab === '__ALL__' ? '资产总表' : currentTab }}】资产记录
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="ast-pagination-bar">
          <div style="color:#64748b;">
            共 <strong>{{ totalAssets }}</strong> 件资产，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页
          </div>
          <el-pagination
            v-model:current-page="curPage"
            :page-size="30"
            :total="totalAssets"
            layout="prev, pager, next"
            @current-change="loadAssets"
          />
        </div>
      </div>
    </div>

    <!-- 弹窗 1: 人工录入/编辑资产 -->
    <el-dialog v-model="assetModalVisible" :title="editingAssetId ? '编辑资产' : '人工录入新资产'" width="720px" destroy-on-close>
      <el-form label-width="110px" size="small">
        <div class="form-grid-2">
          <el-form-item label="所属分类" required>
            <el-select v-model="assetForm.category" style="width:100%;">
              <el-option v-for="c in categoryList" :key="c.id" :label="c.name" :value="c.name" />
            </el-select>
          </el-form-item>
          <el-form-item label="资产名称" required>
            <el-input v-model="assetForm.name" placeholder="请输入资产名称" />
          </el-form-item>
          <el-form-item label="资产编号" required>
            <el-input v-model="assetForm.code" placeholder="请输入编号" />
          </el-form-item>
          <el-form-item label="保管/使用人" required>
            <el-input v-model="assetForm.user" placeholder="使用人" :disabled="!canAssignPerson && !!editingAssetId" />
            <span v-if="!canAssignPerson && editingAssetId" style="font-size:12px; color:#e6a23c;">无权限修改使用人/保管人（仅管理员或班长可调整）</span>
          </el-form-item>
          <el-form-item label="使用地点" required>
            <el-input v-model="assetForm.location" placeholder="使用地点" />
          </el-form-item>
          <el-form-item label="规格型号">
            <el-input v-model="assetForm.model" placeholder="型号" />
          </el-form-item>
          <el-form-item label="MAC1">
            <el-input v-model="assetForm.mac1" placeholder="xx-xx-xx-xx-xx-xx" />
          </el-form-item>
          <el-form-item label="IP1">
            <el-input v-model="assetForm.ip1" placeholder="IP地址" />
          </el-form-item>
          <el-form-item label="MAC2">
            <el-input v-model="assetForm.mac2" placeholder="xx-xx-xx-xx-xx-xx" />
          </el-form-item>
          <el-form-item label="IP2">
            <el-input v-model="assetForm.ip2" placeholder="IP地址" />
          </el-form-item>
          <el-form-item label="出库价格">
            <el-input-number v-model="assetForm.price" :precision="2" :step="100" style="width:100%;" />
          </el-form-item>
          <el-form-item label="状态">
            <el-input v-model="assetForm.status" placeholder="在用/备用/报废" />
          </el-form-item>
        </div>

        <el-form-item label="配置明细">
          <el-input v-model="assetForm.config" type="textarea" :rows="2" placeholder="如 CPU / 内存 / 硬盘规格..." />
        </el-form-item>

        <el-form-item label="图示照片">
          <div style="width:100%;">
            <input type="file" accept="image/*" @change="handleImageSelected" />
            <div style="font-size:11.5px; color:#67C23A; margin-top:4px;">{{ imageTip }}</div>
            <el-input v-model="assetForm.image_url" placeholder="或填写外部图片 URL..." style="margin-top:6px;" />
            <div style="display:flex; gap:16px; margin-top:6px;">
              <el-checkbox v-model="assetForm.delete_image">删除当前图片</el-checkbox>
              <el-checkbox v-model="assetForm.disable_borrowed_image">不使用同配置借用图片</el-checkbox>
            </div>
          </div>
        </el-form-item>

        <el-form-item label="备注说明">
          <el-input v-model="assetForm.remark" type="textarea" :rows="2" placeholder="备注..." />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="assetModalVisible = false">取消</el-button>
        <el-button type="primary" :loading="savingAsset" @click="submitSaveAsset">提交保存</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 2: Excel / CSV 导入 -->
    <el-dialog v-model="importModalVisible" title="批量导入资产 (Excel/CSV)" width="460px" destroy-on-close>
      <el-form label-width="90px">
        <el-form-item label="目标分类">
          <el-input :model-value="currentTab === '__ALL__' || currentTab === '__DASHBOARD__' ? '计算机' : currentTab" readonly />
        </el-form-item>
        <el-form-item label="选择文件" required>
          <input ref="csvFileInputRef" type="file" accept=".xlsx,.xls,.csv, text/csv" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="importModalVisible = false">取消</el-button>
        <el-button type="primary" :loading="importingCsv" @click="submitImportCsv">开始导入</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 3: 权限分配 -->
    <el-dialog v-model="permsModalVisible" title="用户资产穿透查看与操作权限配置" width="860px" destroy-on-close>
      <div style="max-height:420px; overflow-y:auto;">
        <table class="ast-fixed-table" style="text-align:left;">
          <thead>
            <tr>
              <th style="width:110px;">用户</th>
              <th style="width:200px;">允许查看的班组/工种</th>
              <th>跨工种指定人员姓名 (逗号隔开)</th>
              <th style="width:70px; text-align:center;">允许编辑</th>
              <th style="width:70px; text-align:center;">允许删除</th>
              <th style="width:70px; text-align:center;">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in permsUserList" :key="u.user_id">
              <td>
                <strong>@{{ u.username }}</strong>
                <div style="font-size:11px; color:#409EFF;">{{ u.full_name }}</div>
              </td>
              <td>
                <div style="display:flex; flex-wrap:wrap; gap:4px;">
                  <el-checkbox
                    v-for="grp in availableGroups"
                    :key="grp"
                    v-model="u.groupCheckMap[grp]"
                    :label="grp"
                    size="small"
                  />
                </div>
              </td>
              <td>
                <el-input v-model="u.allowed_users" size="small" placeholder="指定人员" />
              </td>
              <td style="text-align:center;">
                <el-checkbox v-model="u.can_edit" :true-value="1" :false-value="0" />
              </td>
              <td style="text-align:center;">
                <el-checkbox v-model="u.can_delete" :true-value="1" :false-value="0" />
              </td>
              <td style="text-align:center;">
                <button type="button" class="btn-primary btn-mini" @click="saveSingleUserPerm(u)">保存</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </el-dialog>

    <!-- 弹窗 4: 分类与字段管理 -->
    <el-dialog v-model="categoryModalVisible" title="分类与展示字段配置" width="560px" destroy-on-close>
      <el-form label-width="90px" size="small">
        <el-form-item label="分类名称" required>
          <div style="display:flex; gap:8px; width:100%;">
            <el-input v-model="categoryForm.name" placeholder="分类名称" />
            <el-button @click="resetCategoryForm">新建空白分类</el-button>
          </div>
        </el-form-item>
        <el-form-item label="展示字段">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; max-height:220px; overflow-y:auto; border:1px solid #dee2e6; padding:8px; border-radius:4px; width:100%;">
            <el-checkbox
              v-for="(label, key) in allFieldsDict"
              :key="key"
              v-model="categoryForm.show_fields[key]"
              :label="label"
            />
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <el-button v-if="categoryForm.id" type="danger" @click="deleteCategory(categoryForm.id)">删除此分类</el-button>
          <span v-else></span>
          <div>
            <el-button @click="categoryModalVisible = false">取消</el-button>
            <el-button type="primary" @click="saveCategory">保存设置</el-button>
          </div>
        </div>
      </template>
    </el-dialog>

    <!-- 弹窗 5: 图片预览 -->
    <el-dialog v-model="previewModalVisible" width="600px" destroy-on-close>
      <img :src="previewImageUrl" style="max-width:100%; max-height:500px; display:block; margin:0 auto; border-radius:4px;" />
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Box, DataLine, Folder, Setting, Plus, Download, Upload,
  Delete, Search, Picture, Check, Warning
} from '@element-plus/icons-vue';
import request from '@/api/request';

const currentTab = ref('__DASHBOARD__');
const isAdmin = ref(false);
const categoryList = ref([]);
const allFieldsDict = ref({});
const categoryShowFields = ref({});
const currentUserPerms = ref({ is_admin: false, can_edit: true, can_delete: false, can_assign: false });

// ★ 是否可修改"使用人/保管人"：管理员或班长(被授权组)可修改，普通作业员不可
const canAssignPerson = computed(() => isAdmin.value || !!currentUserPerms.value.can_assign);

// 看板指标
const kpiData = reactive({
  total_count: 0,
  total_value: 0,
  in_use_count: 0,
  abnormal_count: 0,
  categories: [],
  abnormal_list: []
});

// 数据表格
const searchKeyword = ref('');
const assetList = ref([]);
const totalAssets = ref(0);
const curPage = ref(1);
const totalPages = ref(1);
const tableLoading = ref(false);
const selectedIds = ref([]);
const allSelected = ref(false);

const activeTableCols = computed(() => {
  const cols = [];
  for (let k in allFieldsDict.value) {
    if (currentTab.value === '__ALL__' || categoryShowFields.value[k] === undefined || categoryShowFields.value[k] == 1) {
      cols.push({ key: k, label: allFieldsDict.value[k] });
    }
  }
  return cols;
});

const getColWidth = (k) => {
  if (k === 'category') return '65px';
  if (k === 'code') return '105px';
  if (k === 'price' || k === 'status' || k === 'user') return '75px';
  if (k === 'image_path') return '68px';
  if (k === 'mac1' || k === 'mac2') return '145px';
  if (k === 'ip1' || k === 'ip2') return '115px';
  return 'auto';
};

const isAbnormalRow = (item) => {
  return /停用|坏|报废|停产|报修/.test(JSON.stringify(item));
};

const switchTab = (tab) => {
  currentTab.value = tab;
  if (tab === '__DASHBOARD__') {
    loadDashboard();
  } else {
    loadAssets(1);
  }
};

const loadDashboard = async () => {
  try {
    const res = await request.get('/assets/dashboard_stats');
    if (res.data) {
      Object.assign(kpiData, res.data);
    }
  } catch (err) {}
};

const initData = async () => {
  try {
    const res = await request.get('/assets/get_categories');
    if (res.data) {
      allFieldsDict.value = res.data.all_fields || {};
      categoryList.value = res.data.categories || [];
      currentUserPerms.value = res.data.user_perms || currentUserPerms.value;
      isAdmin.value = !!res.data.is_admin;
      loadDashboard();
    }
  } catch (err) {}
};

const loadAssets = async (p = 1) => {
  curPage.value = p;
  tableLoading.value = true;
  try {
    const res = await request.get('/assets/list', {
      params: {
        category: currentTab.value,
        search: searchKeyword.value.trim(),
        page: p
      }
    });
    if (res.data) {
      assetList.value = res.data.list || [];
      totalAssets.value = res.data.total || 0;
      totalPages.value = res.data.total_pages || 1;
      categoryShowFields.value = res.data.show_fields || {};
      selectedIds.value = [];
      allSelected.value = false;
    }
  } finally {
    tableLoading.value = false;
  }
};

const toggleSelectAll = () => {
  if (allSelected.value) {
    selectedIds.value = assetList.value.map(a => a.id);
  } else {
    selectedIds.value = [];
  }
};

// ---------------- 图片压缩与保存 (≤260KB原图直传、>260KB智能压缩) ----------------
let compressedBlob = null;
const imageTip = ref('(≤260KB原图直传，>260KB自动压缩至300KB内)');

const compressImage = async (file) => {
  return new Promise((resolve) => {
    if (!file || !file.type.startsWith('image/')) return resolve(file);
    if (file.size <= 260 * 1024) return resolve(file);

    const reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onload = (e) => {
      const img = new Image();
      img.src = e.target.result;
      img.onload = () => {
        let width = img.width;
        let height = img.height;
        const maxDim = 1280;
        if (width > maxDim || height > maxDim) {
          if (width > height) {
            height = Math.round((height * maxDim) / width);
            width = maxDim;
          } else {
            width = Math.round((width * maxDim) / height);
            height = maxDim;
          }
        }
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, width, height);

        let q = 0.85;
        function tryCompress() {
          canvas.toBlob((blob) => {
            if (blob.size <= 300 * 1024 || q <= 0.25) {
              resolve(new File([blob], file.name.replace(/\.[^/.]+$/, "") + ".jpg", { type: 'image/jpeg' }));
            } else {
              q -= 0.12;
              tryCompress();
            }
          }, 'image/jpeg', q);
        }
        tryCompress();
      };
    };
  });
};

const handleImageSelected = async (e) => {
  const file = e.target.files[0];
  if (!file) return;
  const origKb = (file.size / 1024).toFixed(1);
  if (file.size <= 260 * 1024) {
    compressedBlob = file;
    imageTip.value = `✅ 原图大小 ${origKb} KB ≤ 260KB，无需压缩，原图直传`;
  } else {
    imageTip.value = `⏳ 原图 ${origKb} KB > 260KB，正在压缩...`;
    compressedBlob = await compressImage(file);
    const finalKb = (compressedBlob.size / 1024).toFixed(1);
    imageTip.value = `✅ 压缩完成 (原图 ${origKb} KB ➜ 压缩后: ${finalKb} KB ≤ 300KB)`;
  }
};

// ---------------- 资产录入/编辑 ----------------
const assetModalVisible = ref(false);
const editingAssetId = ref(null);
const savingAsset = ref(false);
const assetForm = reactive({
  category: '',
  name: '',
  code: '',
  user: '',
  location: '',
  model: '',
  mac1: '',
  ip1: '',
  mac2: '',
  ip2: '',
  price: 0,
  status: '在用',
  config: '',
  remark: '',
  image_url: '',
  delete_image: false,
  disable_borrowed_image: false
});

const openAssetModal = (item = null) => {
  compressedBlob = null;
  imageTip.value = '(≤260KB原图直传，>260KB自动压缩至300KB内)';
  if (item) {
    editingAssetId.value = item.id;
    Object.assign(assetForm, item);
    assetForm.delete_image = false;
    assetForm.disable_borrowed_image = !!item.disable_borrow;
  } else {
    editingAssetId.value = null;
    Object.assign(assetForm, {
      category: currentTab.value === '__ALL__' || currentTab.value === '__DASHBOARD__' ? (categoryList.value[0]?.name || '计算机') : currentTab.value,
      name: '',
      code: '',
      user: '',
      location: '',
      model: '',
      mac1: '',
      ip1: '',
      mac2: '',
      ip2: '',
      price: 0,
      status: '在用',
      config: '',
      remark: '',
      image_url: '',
      delete_image: false,
      disable_borrowed_image: false
    });
  }
  assetModalVisible.value = true;
};

const submitSaveAsset = async () => {
  if (!assetForm.name || !assetForm.code || !assetForm.user || !assetForm.location) {
    ElMessage.warning('名称、编号、使用人及使用地点为必填项！');
    return;
  }
  savingAsset.value = true;
  try {
    const fd = new FormData();
    if (editingAssetId.value) fd.append('id', editingAssetId.value);
    Object.keys(assetForm).forEach(k => {
      if (k === 'delete_image') fd.append(k, assetForm[k] ? '1' : '0');
      else if (k === 'disable_borrowed_image') fd.append(k, assetForm[k] ? '1' : '0');
      else fd.append(k, assetForm[k]);
    });
    if (compressedBlob) {
      fd.append('image_file', compressedBlob, compressedBlob.name);
    }
    const res = await request.post('/assets/save', fd);
    ElMessage.success(res.message || '保存成功');
    assetModalVisible.value = false;
    loadAssets(curPage.value);
  } finally {
    savingAsset.value = false;
  }
};

// ---------------- 批量导出 / 删除 ----------------
const handleBatchExport = () => {
  const ids = selectedIds.value.join(',');
  window.open(`/api/assets/export_csv?category=${encodeURIComponent(currentTab.value)}&ids=${encodeURIComponent(ids)}`, '_blank');
};

const handleBatchDelete = () => {
  if (selectedIds.value.length === 0) return ElMessage.warning('请勾选要删除的资产！');
  ElMessageBox.confirm(`确定要彻底删除选中的 ${selectedIds.value.length} 项资产吗？`, '提示', { type: 'warning' }).then(async () => {
    await request.post('/assets/delete', { ids: selectedIds.value });
    ElMessage.success('删除成功');
    loadAssets(curPage.value);
  });
};

// ---------------- Excel / CSV 导入 ----------------
const importModalVisible = ref(false);
const importingCsv = ref(false);
const csvFileInputRef = ref(null);

const openImportModal = () => {
  importModalVisible.value = true;
};

const submitImportCsv = async () => {
  const file = csvFileInputRef.value?.files[0];
  if (!file) return ElMessage.warning('请先选择 Excel 或 CSV 文件！');
  importingCsv.value = true;
  try {
    const fd = new FormData();
    fd.append('category', currentTab.value === '__ALL__' || currentTab.value === '__DASHBOARD__' ? '计算机' : currentTab.value);
    fd.append('file', file);
    const res = await request.post('/assets/import_csv', fd);
    ElMessage.success(res.message || '导入成功');
    importModalVisible.value = false;
    loadAssets(1);
  } finally {
    importingCsv.value = false;
  }
};

// ---------------- 权限配置 ----------------
const permsModalVisible = ref(false);
const permsUserList = ref([]);
const availableGroups = ref([]);

const openPermsModal = async () => {
  permsModalVisible.value = true;
  const res = await request.get('/assets/list_user_permissions');
  if (res.data) {
    availableGroups.value = res.data.available_groups || [];
    permsUserList.value = (res.data.users || []).map(u => {
      const gList = (u.allowed_groups || '').split(',').map(g => g.trim());
      const gMap = {};
      availableGroups.value.forEach(g => {
        gMap[g] = gList.includes(g);
      });
      return { ...u, groupCheckMap: gMap };
    });
  }
};

const saveSingleUserPerm = async (u) => {
  const selectedGrps = Object.keys(u.groupCheckMap).filter(k => u.groupCheckMap[k]);
  await request.post('/assets/save_user_permissions', {
    user_id: u.user_id,
    allowed_groups: selectedGrps.join(','),
    allowed_users: u.allowed_users,
    can_edit: u.can_edit,
    can_delete: u.can_delete
  });
  ElMessage.success('权限保存成功');
};

// ---------------- 分类管理 ----------------
const categoryModalVisible = ref(false);
const categoryForm = reactive({ id: null, name: '', show_fields: {} });

const openCategoryModal = () => {
  const cur = categoryList.value.find(c => c.name === currentTab.value) || categoryList.value[0];
  if (cur) {
    categoryForm.id = cur.id;
    categoryForm.name = cur.name;
    const sf = cur.show_fields || {};
    const sfMap = {};
    Object.keys(allFieldsDict.value).forEach(k => {
      sfMap[k] = sf[k] === undefined || sf[k] == 1;
    });
    categoryForm.show_fields = sfMap;
  }
  categoryModalVisible.value = true;
};

const resetCategoryForm = () => {
  categoryForm.id = null;
  categoryForm.name = '';
  const sfMap = {};
  Object.keys(allFieldsDict.value).forEach(k => {
    sfMap[k] = true;
  });
  categoryForm.show_fields = sfMap;
};

const saveCategory = async () => {
  if (!categoryForm.name) return ElMessage.warning('分类名称不能为空');
  const sfMap = {};
  Object.keys(categoryForm.show_fields).forEach(k => {
    sfMap[k] = categoryForm.show_fields[k] ? 1 : 0;
  });
  await request.post('/assets/save_category', {
    id: categoryForm.id,
    name: categoryForm.name.trim(),
    show_fields: sfMap
  });
  ElMessage.success('分类配置已更新');
  categoryModalVisible.value = false;
  initData();
};

const deleteCategory = (id) => {
  ElMessageBox.confirm('确定要删除该分类吗？', '提示', { type: 'warning' }).then(async () => {
    await request.post('/assets/delete_category', { id });
    ElMessage.success('已删除');
    categoryModalVisible.value = false;
    currentTab.value = '__DASHBOARD__';
    initData();
  });
};

// ---------------- 图片预览 ----------------
const previewModalVisible = ref(false);
const previewImageUrl = ref('');

const previewImage = (src) => {
  previewImageUrl.value = src;
  previewModalVisible.value = true;
};

onMounted(() => {
  initData();
});
</script>

<style scoped>
.ast-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.ast-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
}
.ast-nav-bar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  border-bottom: 2px solid #dee2e6;
  overflow-x: auto;
  padding-bottom: 4px;
}
.ast-nav-btn {
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
  display: inline-flex;
  align-items: center;
}
.ast-nav-btn.active {
  color: #007bff;
  border-bottom-color: #007bff;
}
.ast-nav-btn.dash-tab {
  color: #007bff;
}
.ast-nav-btn.admin-all {
  color: #8e44ad;
}
.ast-nav-btn.admin-all.active {
  border-bottom-color: #8e44ad;
}

.dash-kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}
.dash-kpi-card {
  background: white;
  border: 1px solid #e3e6f0;
  border-radius: 6px;
  padding: 14px 18px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.dash-kpi-num {
  font-size: 24px;
  font-weight: bold;
  line-height: 1.1;
  margin-top: 4px;
}

.dash-cat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 10px;
  margin-bottom: 14px;
}
.dash-cat-card {
  background: #fdfdfd;
  border: 1px solid #dee2e6;
  border-left: 4px solid #007bff;
  border-radius: 6px;
  padding: 12px 16px;
  cursor: pointer;
  transition: all 0.2s;
}
.dash-cat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,123,255,0.12);
  border-color: #007bff;
  background: white;
}

.ast-table-wrapper {
  max-height: calc(100vh - 350px);
  min-height: 320px;
  overflow-y: auto;
  overflow-x: auto;
  border-top: 1px solid #dee2e6;
  width: 100%;
}
.ast-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 12.5px;
  text-align: center;
  color: #333;
}
.ast-fixed-table thead th {
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
.ast-fixed-table tbody td {
  padding: 6px 5px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
}
.ast-fixed-table tbody tr:hover {
  background-color: #f8fafc;
}
.row-danger td {
  color: #dc3545 !important;
  font-weight: bold;
  background: #fff5f5 !important;
}

.ast-pagination-bar {
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

.form-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 16px;
}

.ast-input {
  height: 32px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
}
.ast-input:focus {
  border-color: #80bdff;
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