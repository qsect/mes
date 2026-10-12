<template>
  <div class="dk-container">
    <!-- 1. 顶部卡片 -->
    <div class="dk-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 17.5px; font-weight: 600; color: #2c3e50;">
          <el-icon style="color: #409EFF; vertical-align: middle; margin-right: 6px;"><Box /></el-icon>
          装置包装配送管理与工单齐套看板
        </span>
      </div>
    </div>

    <!-- 2. 标签切换栏 -->
    <div class="dk-tabs">
      <button
        type="button"
        class="dk-tab-btn"
        :class="{ active: currentTab === 'dispatch' }"
        @click="switchTab('dispatch')"
      >
        <el-icon class="mr-1"><Van /></el-icon>
        配送单收料确认 (装置包装)
      </button>
      <button
        type="button"
        class="dk-tab-btn"
        :class="{ active: currentTab === 'kitting' }"
        @click="switchTab('kitting')"
      >
        <el-icon class="mr-1"><Finished /></el-icon>
        工单包装齐套情况
      </button>
    </div>

    <!-- ================= Tab 1: 配送单收料确认 ================= -->
    <div v-show="currentTab === 'dispatch'">
      <!-- 查询过滤栏 -->
      <div class="dk-card">
        <div class="dk-filter-card">
          <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 6px;">
              <span class="dk-label">配送单号:</span>
              <input
                v-model="queryForm.peisong_order"
                type="text"
                class="dk-input"
                style="width: 200px; font-family: monospace;"
                placeholder="如: PS2608100055"
                @input="onPsInput"
                @keyup.enter="handleDispatchQuery"
              />
            </div>
            <span style="color: #94a3b8; font-weight: bold; font-size: 13px;">或</span>
            <div style="display: flex; align-items: center; gap: 6px;">
              <span class="dk-label">工单号:</span>
              <input
                v-model="queryForm.bill_code"
                type="text"
                class="dk-input"
                style="width: 200px; font-family: monospace;"
                placeholder="如: 187025"
                @input="onBillInput"
                @keyup.enter="handleDispatchQuery"
              />
            </div>
            <button
              type="button"
              class="btn-primary btn-sm"
              :disabled="dispatchLoading"
              @click="handleDispatchQuery"
            >
              <el-icon class="mr-1"><Search /></el-icon>
              {{ dispatchLoading ? '查询中...' : '查询明细' }}
            </button>
            <button type="button" class="btn-outline btn-sm" @click="resetDispatchQuery">
              <el-icon class="mr-1"><Refresh /></el-icon>
              重置
            </button>
          </div>
        </div>

        <div v-loading="dispatchLoading" style="min-height: 180px; padding: 14px;">
          <!-- 空状态 -->
          <div v-if="!hasDispatchedQueried && dispatchGroups.length === 0" class="dk-empty">
            <el-icon style="font-size: 42px; margin-bottom: 8px; color: #cbd5e1;"><Document /></el-icon>
            <p style="margin: 0; font-size: 13.5px; color: #888;">请输入配送单号或工单号以检索包装物料</p>
          </div>

          <div v-else-if="hasDispatchedQueried && dispatchGroups.length === 0" class="dk-empty">
            <el-empty description="未检索到属于【装置包装】工序的配送物料" :image-size="70" />
          </div>

          <!-- 物料分组卡片列表 -->
          <div v-else>
            <div v-for="(g, idx) in dispatchGroups" :key="idx" class="dispatch-group-item">
              <div class="dispatch-group-header">
                <div>
                  <strong style="color: #1e293b; font-size: 14px;">
                    <span v-if="dispatchViewMode === 'by_peisong'">
                      📑 关联工单: <span style="color: #0284c7; font-family: monospace;">{{ g.wo }}</span> (配送单: {{ g.peisong_order }})
                    </span>
                    <span v-else>
                      📦 配送单: <span style="color: #0284c7; font-family: monospace;">{{ g.peisong_order }}</span> (工单: {{ g.wo }})
                    </span>
                  </strong>
                  <span class="badge badge-white" style="margin-left: 8px;">{{ g.items.length }} 项包装物料</span>
                </div>
                <div>
                  <span v-if="g.is_confirmed == 1" class="badge badge-kitted">
                    <el-icon class="mr-1"><Check /></el-icon> 本单已确认收料
                  </span>
                  <button
                    v-else
                    type="button"
                    class="btn-primary btn-mini"
                    :disabled="confirmingIdx === idx"
                    @click="handleConfirmReceipt(g, idx)"
                  >
                    <el-icon class="mr-1"><Checked /></el-icon>
                    {{ confirmingIdx === idx ? '提交中...' : '确认收料' }}
                  </button>
                </div>
              </div>

              <div class="dk-table-wrapper" style="max-height: 350px;">
                <table class="dk-fixed-table">
                  <thead>
                    <tr>
                      <th style="width: 50px; text-align: center;">#</th>
                      <th style="width: 170px;">物料编码</th>
                      <th>物料描述</th>
                      <th style="width: 140px; text-align: center;">所属工序</th>
                      <th style="width: 120px; text-align: right;">配送数量</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(it, i) in g.items" :key="i">
                      <td style="text-align: center; color: #94a3b8;">{{ i + 1 }}</td>
                      <td><strong style="font-family: monospace; color: #2c3e50;">{{ it.mater_no }}</strong></td>
                      <td style="text-align: left;">{{ it.mater_desc || '-' }}</td>
                      <td style="text-align: center;"><span class="badge badge-cat">{{ it.proc_name }}</span></td>
                      <td style="text-align: right; font-weight: bold; font-family: monospace; color: #0284c7;">
                        {{ it.send_qty }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ================= Tab 2: 工单包装齐套情况 ================= -->
    <div v-show="currentTab === 'kitting'">
      <div class="dk-card">
        <!-- 齐套过滤工具条 -->
        <div class="dk-filter-card">
          <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 6px;">
              <span class="dk-label">快速过滤工单:</span>
              <input
                v-model="kittingWoFilter"
                type="text"
                class="dk-input"
                style="width: 220px; font-family: monospace;"
                placeholder="留空查询全部，回车检索..."
                @keyup.enter="loadKittingList"
              />
            </div>
            <label class="dk-checkbox-label">
              <input type="checkbox" v-model="showArchived" @change="loadKittingList" />
              <span>显示已归档工单</span>
            </label>
            <button
              type="button"
              class="btn-success btn-sm"
              :disabled="kittingLoading"
              @click="loadKittingList"
            >
              <el-icon class="mr-1"><Refresh /></el-icon>
              {{ kittingLoading ? '核算中...' : '刷新齐套核算' }}
            </button>
            <div class="legend-box">
              <span class="legend-item"><span class="legend-color legend-green"></span> 包装已完成</span>
              <span class="legend-item"><span class="legend-color legend-yellow"></span> 包装进行中</span>
              <span class="legend-item"><span class="legend-color legend-white"></span> 包装未开始</span>
              <span class="legend-item"><span class="legend-color legend-gray"></span> 已归档</span>
            </div>
          </div>
        </div>

        <!-- 齐套主表 -->
        <div class="dk-table-wrapper" v-loading="kittingLoading">
          <table class="dk-fixed-table">
            <thead>
              <tr>
                <th style="width: 45px; text-align: center;">展开</th>
                <th style="width: 125px;">工单号</th>
                <th style="width: 140px;">物料代码</th>
                <th style="text-align: left;">产品描述</th>
                <th style="width: 100px; text-align: right;">计划数量</th>
                <th style="width: 160px; text-align: center;">包装进度</th>
                <th style="width: 140px; text-align: center;">状态</th>
                <th style="width: 100px; text-align: center;">确认人</th>
                <th style="width: 155px; text-align: center;">最新确认时间</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(item, idx) in kittingList" :key="item.wo">
                <tr
                  class="wo-row-main"
                  :class="getRowClass(item)"
                  @click="toggleRowDetail(item, idx)"
                >
                  <td style="text-align: center;">
                    <el-icon
                      class="expand-icon"
                      :class="{ expanded: expandedRowMap[item.wo] }"
                    >
                      <ArrowRight />
                    </el-icon>
                  </td>
                  <td><strong style="font-family: monospace; color: #2c3e50;">{{ item.wo }}</strong></td>
                  <td><span style="font-family: monospace;">{{ item.mater_code || '-' }}</span></td>
                  <td style="text-align: left;"><span class="cell-truncate" :title="item.mater_desc">{{ item.mater_desc || '-' }}</span></td>
                  <td style="text-align: right; font-weight: bold; font-family: monospace;">{{ item.plan_qty }}</td>
                  <td style="text-align: center;">
                    <strong style="color: #0284c7;">{{ item.done_qty }}</strong> / {{ item.plan_qty }}
                    <span style="font-size: 11.5px; color: #64748b; margin-left: 3px;">({{ item.progress_pct }}%)</span>
                  </td>
                  <td style="text-align: center;">
                    <span v-if="item.is_archived == 1" class="badge badge-gray">📦 已归档</span>
                    <span v-else-if="item.wo_status === 'green'" class="badge badge-kitted">✅ 包装完成</span>
                    <span v-else-if="item.wo_status === 'yellow'" class="badge badge-partial">⏳ 包装进行中</span>
                    <span v-else class="badge badge-white">⚪ 未开始包装</span>
                  </td>
                  <td style="text-align: center;">{{ item.last_confirm_user || '-' }}</td>
                  <td style="text-align: center; font-size: 12px; color: #64748b;">
                    {{ item.last_confirm_time || '-' }}
                  </td>
                </tr>

                <!-- 折叠内嵌物料明细行 -->
                <tr v-if="expandedRowMap[item.wo]" class="wo-detail-row">
                  <td colspan="9">
                    <div class="wo-detail-inner" v-loading="materialLoadingMap[item.wo]">
                      <div v-if="materialDataMap[item.wo]">
                        <div class="detail-header-stat">
                          <span style="font-weight: 600; color: #1e293b; font-size: 13px;">包装物料明细</span>
                          <span class="badge badge-white">共 {{ materialDataMap[item.wo].total_kinds }} 种</span>
                          <span class="badge badge-kitted">已齐套 {{ materialDataMap[item.wo].green_count }}</span>
                          <span class="badge badge-partial">部分到料 {{ materialDataMap[item.wo].yellow_count }}</span>
                          <span class="badge badge-zero">未到料 {{ materialDataMap[item.wo].white_count }}</span>
                          <span v-if="materialDataMap[item.wo].is_full_kitted" class="badge badge-kitted" style="font-weight: bold;">
                            🎉 包装物料已全部齐套
                          </span>
                        </div>

                        <table class="detail-table">
                          <thead>
                            <tr>
                              <th style="width: 45px; text-align: center;">#</th>
                              <th style="width: 170px;">物料编码</th>
                              <th>物料描述</th>
                              <th style="width: 110px; text-align: right;">需求量</th>
                              <th style="width: 110px; text-align: right;">已到料</th>
                              <th style="width: 130px; text-align: center;">齐套状态</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr
                              v-for="(m, mIdx) in materialDataMap[item.wo].materials"
                              :key="m.mater_no"
                              :class="'tr-mat-' + m.status"
                            >
                              <td style="text-align: center; color: #94a3b8;">{{ mIdx + 1 }}</td>
                              <td><strong style="font-family: monospace; color: #2c3e50;">{{ m.mater_no }}</strong></td>
                              <td style="text-align: left;">{{ m.mater_desc || '-' }}</td>
                              <td style="text-align: right; font-family: monospace;">{{ m.req_qty }}</td>
                              <td style="text-align: right; font-weight: bold; font-family: monospace;">{{ m.conf_qty }}</td>
                              <td style="text-align: center;">
                                <span v-if="m.status === 'green'" class="badge badge-kitted">✅ 已齐套</span>
                                <span v-else-if="m.status === 'yellow'" class="badge badge-partial">⚠️ 部分到料</span>
                                <span v-else class="badge badge-white">⚪ 未到料</span>
                              </td>
                            </tr>
                            <tr v-if="!materialDataMap[item.wo].materials || materialDataMap[item.wo].materials.length === 0">
                              <td colspan="6" style="text-align: center; padding: 18px; color: #888;">该工单暂无装置包装物料</td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>

              <tr v-if="kittingList.length === 0 && !kittingLoading">
                <td colspan="9" style="text-align: center; padding: 40px; color: #888;">
                  <el-empty description="暂无符合条件的工单包装齐套数据" :image-size="70" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Box, Van, Finished, Search, Refresh, Check, Checked, ArrowRight, Document
} from '@element-plus/icons-vue';
import request from '@/api/request';

const currentTab = ref('dispatch');

// Tab 1 配送单查询
const queryForm = reactive({
  peisong_order: '',
  bill_code: ''
});
const dispatchLoading = ref(false);
const hasDispatchedQueried = ref(false);
const dispatchViewMode = ref('by_peisong');
const dispatchGroups = ref([]);
const confirmingIdx = ref(-1);

const onPsInput = () => {
  if (queryForm.peisong_order.trim()) {
    queryForm.bill_code = '';
  }
};
const onBillInput = () => {
  if (queryForm.bill_code.trim()) {
    queryForm.peisong_order = '';
  }
};

const resetDispatchQuery = () => {
  queryForm.peisong_order = '';
  queryForm.bill_code = '';
  dispatchGroups.value = [];
  hasDispatchedQueried.value = false;
};

const handleDispatchQuery = async () => {
  const ps = queryForm.peisong_order.trim();
  const bill = queryForm.bill_code.trim();
  if (!ps && !bill) {
    return ElMessage.warning('请输入配送单号或工单号！');
  }

  dispatchLoading.value = true;
  hasDispatchedQueried.value = true;
  try {
    const res = await request.get('/dispatch_kitting/query', {
      params: { peisong_order: ps, bill_code: bill }
    });
    if (res.data) {
      dispatchViewMode.value = res.data.view_mode || 'by_peisong';
      dispatchGroups.value = res.data.groups || [];
      if (dispatchGroups.value.length === 0) {
        ElMessage.info('未检索到属于【装置包装】工序的配送物料！');
      } else {
        ElMessage.success(`查询成功，共检索到 ${dispatchGroups.value.length} 个批次组`);
      }
    }
  } catch (err) {
    dispatchGroups.value = [];
    ElMessage.error(err.message || '查询配送明细失败');
  } finally {
    dispatchLoading.value = false;
  }
};

const handleConfirmReceipt = async (group, idx) => {
  try {
    await ElMessageBox.confirm(
      `确定对配送单【${group.peisong_order}】(关联工单 ${group.wo}) 执行收料确认？`,
      '确认收料',
      { type: 'warning', confirmButtonText: '确定收料', cancelButtonText: '取消' }
    );

    confirmingIdx.value = idx;
    const res = await request.post('/dispatch_kitting/confirm_receipt', {
      peisong_order: group.peisong_order,
      wo: group.wo,
      items: group.items
    });
    ElMessage.success(res.message || '确认收料成功！');
    group.is_confirmed = 1;
  } catch (err) {
    if (err !== 'cancel') {
      ElMessage.error(err.message || '收料失败');
    }
  } finally {
    confirmingIdx.value = -1;
  }
};

// Tab 2 工单包装齐套情况
const kittingWoFilter = ref('');
const showArchived = ref(false);
const kittingLoading = ref(false);
const kittingList = ref([]);
const expandedRowMap = reactive({});
const materialLoadingMap = reactive({});
const materialDataMap = reactive({});

const getRowClass = (item) => {
  if (item.is_archived == 1) return 'wo-row-archived';
  if (item.wo_status === 'green') return 'wo-row-green';
  if (item.wo_status === 'yellow') return 'wo-row-yellow';
  return 'wo-row-white';
};

const loadKittingList = async () => {
  kittingLoading.value = true;
  try {
    const res = await request.get('/dispatch_kitting/query_wo_kitting', {
      params: {
        wo: kittingWoFilter.value.trim(),
        show_archived: showArchived.value ? 1 : 0
      }
    });
    if (res.data) {
      if (res.data.is_archived) {
        ElMessageBox.alert(res.data.message, '提示', {
          confirmButtonText: '我知道了',
          type: 'info'
        });
        kittingList.value = [];
      } else {
        kittingList.value = res.data.list || [];
      }
    }
  } catch (err) {
    kittingList.value = [];
    ElMessage.error(err.message || '加载齐套看板失败');
  } finally {
    kittingLoading.value = false;
  }
};

const toggleRowDetail = async (item) => {
  const wo = item.wo;
  expandedRowMap[wo] = !expandedRowMap[wo];

  // 展开且未加载过数据时请求物料明细
  if (expandedRowMap[wo] && !materialDataMap[wo]) {
    materialLoadingMap[wo] = true;
    try {
      const res = await request.get('/dispatch_kitting/query_wo_materials', {
        params: { wo }
      });
      if (res.data) {
        materialDataMap[wo] = res.data;
      }
    } catch (err) {
      ElMessage.error(`加载工单 ${wo} 物料明细失败: ` + (err.message || ''));
    } finally {
      materialLoadingMap[wo] = false;
    }
  }
};

const switchTab = (tab) => {
  currentTab.value = tab;
  if (tab === 'kitting' && kittingList.value.length === 0) {
    loadKittingList();
  }
};

onMounted(() => {
  // 默认直接停留在 Tab 1，准备查询
});
</script>

<style scoped>
.dk-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.dk-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.dk-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  border-bottom: 2px solid #dee2e6;
  flex-wrap: wrap;
}
.dk-tab-btn {
  display: inline-flex;
  align-items: center;
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
.dk-tab-btn.active {
  color: #007bff;
  border-bottom-color: #007bff;
}
.dk-filter-card {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}
.dk-label {
  font-size: 13px;
  font-weight: 600;
  color: #495057;
  white-space: nowrap;
}
.dk-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.dk-input:focus {
  border-color: #80bdff;
}
.dk-checkbox-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  font-size: 12.5px;
  color: #334155;
  user-select: none;
  margin-bottom: 0;
  font-weight: 600;
}
.legend-box {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 12px;
  color: #64748b;
  margin-left: auto;
  flex-wrap: wrap;
}
.legend-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.legend-color {
  width: 12px;
  height: 12px;
  border-radius: 2px;
  display: inline-block;
}
.legend-green { background: #d4edda; border: 1px solid #86efac; }
.legend-yellow { background: #fff3cd; border: 1px solid #fde047; }
.legend-white { background: #ffffff; border: 1px solid #cbd5e1; }
.legend-gray { background: #f3f4f6; border: 1px solid #9ca3af; }

.dispatch-group-item {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  margin-bottom: 14px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}
.dispatch-group-header {
  background: #f8fafc;
  padding: 10px 14px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid #dee2e6;
}

.dk-table-wrapper {
  width: 100%;
  overflow-x: auto;
  max-height: calc(100vh - 340px);
}
.dk-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 13px;
  text-align: center;
  color: #333;
}
.dk-fixed-table thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 10;
  padding: 8px 10px;
  border-bottom: 2px solid #dee2e6;
  border-right: 1px solid #dee2e6;
  white-space: nowrap;
  font-weight: 600;
  color: #2c3e50;
}
.dk-fixed-table tbody td {
  padding: 8px 10px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
}
.dk-fixed-table tbody tr.wo-row-main {
  cursor: pointer;
  transition: background-color 0.15s ease;
}
.dk-fixed-table tbody tr.wo-row-main:hover td {
  filter: brightness(0.97);
}

.wo-row-green td { background-color: #f6ffed !important; }
.wo-row-yellow td { background-color: #fffbe6 !important; }
.wo-row-white td { background-color: #ffffff !important; }
.wo-row-archived td { background-color: #f8fafc !important; color: #64748b !important; }

.expand-icon {
  font-size: 12px;
  color: #64748b;
  transition: transform 0.2s;
}
.expand-icon.expanded {
  transform: rotate(90deg);
}

.wo-detail-row td {
  padding: 0 !important;
  background: #f8fafc !important;
  border-bottom: 2px solid #cbd5e1 !important;
}
.wo-detail-inner {
  padding: 12px 18px;
}
.detail-header-stat {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.detail-table {
  width: 100%;
  border-collapse: collapse;
  background: #ffffff;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  font-size: 12.5px;
}
.detail-table th {
  background: #f1f5f9;
  padding: 7px 10px;
  border: 1px solid #e2e8f0;
  font-weight: 600;
  color: #334155;
  text-align: left;
}
.detail-table td {
  padding: 7px 10px;
  border: 1px solid #e2e8f0;
  vertical-align: middle;
}
.tr-mat-green td { background-color: #f6ffed !important; }
.tr-mat-yellow td { background-color: #fffbe6 !important; }
.tr-mat-white td { background-color: #ffffff !important; }

.badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 11.5px;
  font-weight: 500;
}
.badge-cat { background: #e8f4fd; color: #007bff; }
.badge-kitted { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.badge-partial { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
.badge-zero { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.badge-white { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
.badge-gray { background: #e2e8f0; color: #475569; border: 1px solid #cbd5e1; }

.cell-truncate {
  max-width: 260px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: inline-block;
  vertical-align: middle;
}
.dk-empty {
  text-align: center;
  padding: 40px 10px;
  color: #888;
}

.btn-primary {
  background: #007bff; color: #fff; border: 1px solid #007bff;
  border-radius: 4px; padding: 5px 12px; cursor: pointer;
  display: inline-flex; align-items: center; font-size: 12.5px;
}
.btn-primary:hover { background: #0069d9; }
.btn-success {
  background: #28a745; color: #fff; border: 1px solid #28a745;
  border-radius: 4px; padding: 5px 12px; cursor: pointer;
  display: inline-flex; align-items: center; font-size: 12.5px;
}
.btn-success:hover { background: #218838; }
.btn-outline {
  background: #fff; color: #495057; border: 1px solid #ced4da;
  border-radius: 4px; padding: 5px 12px; cursor: pointer;
  display: inline-flex; align-items: center; font-size: 12.5px;
}
.btn-sm { font-size: 12px; height: 33px; }
.btn-mini { padding: 3px 8px; font-size: 11.5px; }
.mr-1 { margin-right: 4px; }
</style>