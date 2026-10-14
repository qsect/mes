<template>
  <div class="trace-container">
    <!-- 顶部标题卡片 -->
    <div class="tr-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align: middle; margin-right: 4px;"><ShieldCheck /></el-icon>
          质量追溯系统
        </span>
      </div>
    </div>

    <!-- 顶部Tab切换栏 -->
    <div class="tr-tabs">
      <button v-if="userPerms.can_scan" type="button" class="tr-tab-btn" :class="{ active: activeTab === 'scan' }" @click="switchTab('scan')">
        <el-icon style="margin-right: 4px;"><Barcode /></el-icon> 追溯扫码工作台
      </button>
      <button v-if="userPerms.can_add_material" type="button" class="tr-tab-btn" :class="{ active: activeTab === 'add' }" @click="switchTab('add')">
        <el-icon style="margin-right: 4px;"><CirclePlus /></el-icon> 添加/编辑追溯物料
      </button>
      <button v-if="userPerms.can_view" type="button" class="tr-tab-btn" :class="{ active: activeTab === 'query' }" @click="switchTab('query')">
        <el-icon style="margin-right: 4px;"><Search /></el-icon> 追溯记录查询与导出
      </button>
      <button v-if="userPerms.can_scan || userPerms.can_add_material || userPerms.can_view || userPerms.is_admin" type="button" class="tr-tab-btn" :class="{ active: activeTab === 'configs' }" @click="switchTab('configs')">
        <el-icon style="margin-right: 4px;"><List /></el-icon> 已配置单据库
      </button>
      <button v-if="userPerms.is_admin" type="button" class="tr-tab-btn" :class="{ active: activeTab === 'perms' }" @click="switchTab('perms')">
        <el-icon style="margin-right: 4px;"><Lock /></el-icon> 追溯权限配置
      </button>
    </div>

    <!-- 无权限提示 -->
    <div v-if="!hasAnyPerm" class="no-perm-box">
      <el-icon :size="48" color="#dc3545"><Lock /></el-icon>
      <h4>暂无质量追溯系统的任何操作权限</h4>
    </div>

    <!-- ================= 视图 1: 追溯扫码工作台 ================= -->
    <div v-show="activeTab === 'scan'" class="tr-card">
      <div class="card-bar">
        <span><el-icon style="color:#409EFF; vertical-align: middle;"><Operation /></el-icon> 追溯扫码工作台</span>
      </div>
      <div style="padding: 14px;">
        <div class="tr-single-row">
          <div style="display:flex; gap:6px; width:340px;">
            <input
              ref="scanWoInputRef"
              v-model="scanWoInput"
              type="text"
              class="tr-input"
              placeholder="输入工单号/XQ单号回车开始..."
              @keyup.enter="handleStartTrace"
            />
            <button type="button" class="btn-primary" @click="handleStartTrace">开始追溯</button>
          </div>

          <div v-if="currentTask" class="tr-inline-info">
            <span><strong>单据:</strong> {{ currentTask.task_order }}</span>
            <span><strong>一级条码/产品:</strong> <span style="font-family:monospace; color:#0284c7; font-weight:bold;">{{ currentTask.parent_code }}</span></span>
            <span><strong>总数:</strong> <strong style="color:#0284c7; font-size:15px; font-family:Consolas, monospace;">{{ currentTask.quantity }}</strong></span>
            <span><strong>已完成:</strong> <strong style="color:#16a34a; font-size:15px; font-family:Consolas, monospace;">{{ completedBoxes }}</strong></span>
            <button 
              type="button" 
              class="btn-primary btn-mini" 
              style="margin-left: 12px; background: #0284c7; border: none; padding: 4px 10px; border-radius: 4px; color: #fff; cursor: pointer;"
              :disabled="pushingCem"
              @click="handlePushCem"
            >
              <span v-if="pushingCem">上传中...</span>
              <span v-else>🚀 上传CEM</span>
            </button>
            <span v-if="currentTask && currentTask.cem_pushed == 1" style="color: #16a34a; font-size: 12px; margin-left: 6px;">
              (✔ 已同步CEM)
            </span>
          </div>
        </div>

        <div v-if="currentTask" style="margin-top:12px;">
          <div class="station-banner">
            <div style="display:flex; align-items:center; gap:8px;">
              <label style="font-weight:bold; margin-bottom:0; color:#856404; font-size:12.5px;">当前操作工位：</label>
              <select v-model="selectedPoint" class="tr-select" @change="handlePointChange">
                <option v-for="p in scanPointsCount" :key="p" :value="p">扫码点 {{ p }}</option>
              </select>
            </div>
            <div style="font-size:12.5px; color:#856404;">
              <strong>当前一级条码 SN：</strong>
              <span style="font-family:monospace; font-weight:bold; font-size:14px; color:#0284c7;">{{ currentBoxSn || '未扫描一级条码 (首码请扫一级条码/装置SN)' }}</span>
              <span v-if="isCurBoxDone" class="badge-done" style="margin-left:8px;">本工位已齐套</span>
            </div>
          </div>

          <div class="tr-single-row">
            <input
              ref="barcodeInputRef"
              v-model="barcodeInput"
              type="text"
              class="tr-input"
              :placeholder="barcodePlaceholder"
              style="font-size:13px; font-family:monospace; height:36px; flex:1; font-weight:bold;"
              @keyup.enter="handleBarcodeScan"
            />
            <button type="button" class="btn-outline" style="height:36px; font-size:12px;" @click="resetBoxScanState('')">换下一台/重扫</button>
          </div>
          <div :style="{ color: scanFeedbackSuccess ? '#155724' : '#721c24' }" style="margin-top:4px; font-size:12.5px; font-weight:bold;">
            {{ scanFeedbackMsg }}
          </div>

          <div style="font-size:13px; font-weight:bold; margin:10px 0 4px 0; color:#2c3e50;">
            <el-icon style="vertical-align: middle;"><Cpu /></el-icon> 本装置所需二级物料实时清单：
          </div>
          <div class="mat-grid">
            <div
              v-for="m in traceMaterials"
              :key="m.child_code"
              class="mat-card"
              :class="{ done: (currentScannedMap[m.child_code] || 0) >= parseFloat(m.unit_quantity) }"
              :style="{ opacity: parseInt(m.scan_point) === selectedPoint ? 1 : 0.4, filter: parseInt(m.scan_point) === selectedPoint ? 'none' : 'grayscale(0.6)' }"
            >
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                <strong style="font-family:monospace; color:#2c3e50;">{{ m.child_code }}</strong>
                <span class="badge" style="background:#e8f4fd; color:#0284c7; padding:2px 6px; border-radius:4px; font-size:11px;">点 {{ m.scan_point }}</span>
              </div>
              <div style="font-size:12px; color:#6c757d; margin-bottom:6px;">{{ m.child_name }}</div>
              <div style="display:flex; justify-content:space-between; font-size:12px; font-weight:bold;">
                <span>已扫: <span :style="{ color: (currentScannedMap[m.child_code] || 0) >= parseFloat(m.unit_quantity) ? '#16a34a' : '#dc3545' }">{{ currentScannedMap[m.child_code] || 0 }}</span> / 所需: {{ m.unit_quantity }}</span>
                <span>{{ (currentScannedMap[m.child_code] || 0) >= parseFloat(m.unit_quantity) ? '✅ 已齐套' : '⏳ 待扫描' }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ================= 视图 2: 添加/编辑追溯物料 ================= -->
    <div v-show="activeTab === 'add'" class="tr-card">
      <div class="card-bar">
        <span><el-icon style="color:#409EFF; vertical-align: middle;"><Setting /></el-icon> 读取工单/XQ工程单据组件并配置追溯物料</span>
      </div>
      <div style="padding:14px;">
        <div class="tr-single-row">
          <div style="display:flex; gap:6px; width:360px;">
            <input v-model="addWoInput" type="text" class="tr-input" placeholder="输入工单号或 XQ2026-xxxxx..." @keyup.enter="handleFetchErp" />
            <button type="button" class="btn-primary" @click="handleFetchErp">查询组件及齐套</button>
          </div>
          <div v-if="erpTask" class="tr-inline-info">
            <span><strong>单据类型:</strong> <span style="font-weight:bold; color:#0284c7;">{{ erpIsXq ? '工程发货单 (XQ)' : '生产工单' }}</span></span>
            <span><strong>一级条码:</strong> <span style="font-family:monospace; font-weight:bold;">{{ erpTask.parent_code }}</span></span>
            <span><strong>描述:</strong> {{ erpTask.description }}</span>
            <span><strong>总数:</strong> <strong style="color:#0284c7;">{{ erpTask.quantity }}</strong></span>
          </div>
        </div>

        <div v-if="hasZeroStockSelected" class="kitting-alert">
          阻断提醒：当前勾选物料中存在实发出库数为 0 的物料（红底），严禁保存并禁止开始追溯！请先等待仓库发料齐套。
        </div>

        <div v-if="erpMaterials.length > 0" style="margin-top:12px;">
          <div style="background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px; padding:10px 14px; margin-bottom:10px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
              <label style="font-weight:bold; font-size:12.5px;">多点扫码设置：</label>
              <select v-model="cfgScanPoints" class="tr-select">
                <option :value="1">单点扫码 (1点)</option>
                <option :value="2">2 个扫码点</option>
                <option :value="3">3 个扫码点</option>
                <option :value="4">4 个扫码点</option>
                <option :value="5">5 个扫码点</option>
              </select>
            </div>
            <div style="display:flex; align-items:center; gap:12px; font-size:12px;">
              <span style="display:inline-flex; align-items:center; gap:4px;"><span style="width:12px; height:12px; background:#52c41a; border-radius:2px; display:inline-block;"></span> 绿底：完全齐套</span>
              <span style="display:inline-flex; align-items:center; gap:4px;"><span style="width:12px; height:12px; background:#faad14; border-radius:2px; display:inline-block;"></span> 橙底：部分发料</span>
              <span style="display:inline-flex; align-items:center; gap:4px;"><span style="width:12px; height:12px; background:#ff4d4f; border-radius:2px; display:inline-block;"></span> 红底：出库为 0 (禁止添加物料)</span>
            </div>
          </div>

          <div class="sk-table-wrapper">
            <table class="sk-fixed-table">
              <thead>
                <tr>
                  <th style="width:40px;"><input type="checkbox" v-model="allMatSelected" @change="toggleSelectAllMat" /></th>
                  <th>二级物料代码</th>
                  <th>二级物料名称 / 规格描述</th>
                  <th style="width:100px; text-align:right;">单台使用量</th>
                  <th style="width:100px; text-align:right;">需求总数</th>
                  <th style="width:100px; text-align:right;">实发出库数</th>
                  <th style="width:110px; text-align:center;">齐套状态</th>
                  <th style="width:130px; text-align:center;">指派扫码点</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in erpMaterials" :key="m.code" :class="'tr-mat-' + m.color_state">
                  <td><input type="checkbox" v-model="m.selected" /></td>
                  <td><strong style="font-family:monospace; color:#2c3e50;">{{ m.code }}</strong></td>
                  <td style="text-align:left;">{{ m.name }}</td>
                  <td style="text-align:right; font-weight:bold; color:#0284c7;">{{ m.unit_quantity }}</td>
                  <td style="text-align:right;">{{ m.req_quantity }}</td>
                  <td style="text-align:right; font-weight:bold;">{{ m.send_quantity }}</td>
                  <td>
                    <span v-if="m.color_state === 'green'" class="badge-kitted">完全齐套</span>
                    <span v-else-if="m.color_state === 'orange'" class="badge-partial">部分发料</span>
                    <span v-else class="badge-zero">出库为0</span>
                  </td>
                  <td>
                    <select v-model="m.scan_point" class="tr-select" style="height:28px; width:110px; margin:0 auto; padding:2px 4px;">
                      <option v-for="p in cfgScanPoints" :key="p" :value="p">扫码点 {{ p }}</option>
                    </select>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div style="text-align:right; margin-top:12px;">
            <button
              type="button"
              :class="hasZeroStockSelected ? 'btn-danger' : 'btn-success'"
              :disabled="hasZeroStockSelected"
              style="height:33px; font-size:12.5px; padding:0 20px;"
              @click="handleSaveMatConfig"
            >
              {{ hasZeroStockSelected ? '存在出库为0的物料，禁止保存追溯物料' : '保存追溯物料配置并启动追溯' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ================= 视图 3: 追溯记录查询与导出 ================= -->
    <div v-show="activeTab === 'query'" class="tr-card">
      <div class="card-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <span><el-icon style="color:#409EFF; vertical-align: middle;"><Document /></el-icon> 质量追溯明细档案库</span>
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
          <input v-model="querySearchKw" type="text" class="tr-input" style="width:200px;" placeholder="输入单号/箱号/SN..." @keyup.enter="loadQueryRecords(1)" />
          <button type="button" class="btn-primary" @click="loadQueryRecords(1)">查询</button>
          <button type="button" class="btn-success" @click="handleExportExcel">导出 Excel</button>
          <button type="button" class="btn-info" @click="handleManualPushCem">一键推送当前单据至 CEM</button>
        </div>
      </div>
      <div class="sk-table-wrapper">
        <table class="sk-fixed-table">
          <thead>
            <tr>
              <th>二级物料条码 (SN)</th>
              <th>二级物料代码</th>
              <th>一级条码/装置号</th>
              <th>产品编码</th>
              <th>扫码工位</th>
              <th>扫码人员</th>
              <th>扫码时间</th>
              <th>工单/单据号</th>
              <th v-if="userPerms.is_admin || userPerms.can_edit_record || userPerms.can_delete_record" style="width:110px; text-align:center;">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in queryRecords" :key="r.scan_id">
              <td><strong style="font-family:monospace; color:#2c3e50;">{{ r.child_sn }}</strong></td>
              <td><span style="font-family:monospace;">{{ r.child_code ? r.child_code.substring(0, 10) : '-' }}</span></td>
              <td><span style="font-family:monospace; font-weight:bold; color:#0284c7;">{{ r.box_sn }}</span></td>
              <td><span style="font-family:monospace;">{{ r.parent_code ? r.parent_code.substring(0, 10) : '-' }}</span></td>
              <td><span class="badge" style="background:#e8f4fd; color:#0284c7; padding:2px 6px; border-radius:4px; font-size:11px;">点 {{ r.scan_point || 1 }}</span></td>
              <td>{{ r.scanner_name || '-' }}</td>
              <td><span style="font-size:11.5px; color:#64748b;">{{ r.scanned_at }}</span></td>
              <td><strong>{{ r.task_order }}</strong></td>
              <td v-if="userPerms.is_admin || userPerms.can_edit_record || userPerms.can_delete_record" style="text-align:center; white-space:nowrap;">
                <button v-if="userPerms.is_admin || userPerms.can_edit_record" type="button" class="btn-primary btn-mini" @click="openEditModal(r)">修改</button>
                <button v-if="userPerms.is_admin || userPerms.can_delete_record" type="button" class="btn-danger btn-mini" style="margin-left:3px;" @click="handleDeleteRecord(r)">删除</button>
              </td>
            </tr>
            <tr v-if="queryRecords.length === 0">
              <td :colspan="userPerms.is_admin || userPerms.can_edit_record || userPerms.can_delete_record ? 9 : 8" style="padding:35px; color:#888;">暂无追溯记录</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="pagination-bar">
        <span>共 <strong>{{ queryTotal }}</strong> 条追溯记录，第 <strong>{{ queryCurPage }}</strong> / <strong>{{ queryTotalPages }}</strong> 页</span>
        <el-pagination
          v-model:current-page="queryCurPage"
          :page-size="30"
          :total="queryTotal"
          layout="prev, pager, next"
          @current-change="loadQueryRecords"
        />
      </div>
    </div>

    <!-- ================= 视图 4: 已配置单据库 ================= -->
    <div v-show="activeTab === 'configs'" class="tr-card">
      <div class="card-bar">
        <span><el-icon style="color:#409EFF; vertical-align: middle;"><Collection /></el-icon> 已配置追溯物料的单据库</span>
      </div>
      <div class="cfg-table-wrapper">
        <table class="cfg-fixed-table">
          <thead>
            <tr>
              <th style="width:130px;">单据号</th>
              <th style="width:105px;">类型</th>
              <th style="width:170px;">一级条码/物料</th>
              <th style="width:22%;">描述/工程名</th>
              <th style="width:65px; text-align:right;">总数</th>
              <th style="width:75px; text-align:right;">已完成数</th>
              <th style="width:75px; text-align:center;">状态</th>
              <th style="width:65px; text-align:center;">工位点</th>
              <th style="width:85px;">配置人</th>
              <th style="width:95px; text-align:center;">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in configsList" :key="c.id">
              <td :title="c.task_order"><strong>{{ c.task_order }}</strong></td>
              <td>
                <span class="badge" :style="{ background: c.order_type === 'xq_project' ? '#e6f7ff' : '#f0f2f5', color: c.order_type === 'xq_project' ? '#096dd9' : '#595959', padding:'2px 6px', borderRadius:'4px' }">
                  {{ c.order_type === 'xq_project' ? '工程发货(XQ)' : '生产工单' }}
                </span>
              </td>
              <td :title="c.parent_code"><code style="font-family:monospace;">{{ c.parent_code }}</code></td>
              <td :title="c.description"><span class="cell-truncate">{{ c.description || '-' }}</span></td>
              <td style="text-align:right; font-weight:bold; color:#0284c7; font-family:Consolas, monospace;">{{ c.quantity }}</td>
              <td style="text-align:right; font-weight:bold; font-family:Consolas, monospace;" :style="{ color: parseInt(c.completed_count) >= parseInt(c.quantity) ? '#16a34a' : '#ea580c' }">{{ c.completed_count }}</td>
              <td style="text-align:center;">
                <span v-if="parseInt(c.completed_count) >= parseInt(c.quantity)" class="badge-kitted">完工</span>
                <span v-else class="badge-partial">进行中</span>
              </td>
              <td style="text-align:center;"><span class="badge" style="background:#e8f4fd; color:#0284c7; padding:2px 6px; border-radius:4px;">{{ c.scan_points }}点</span></td>
              <td>{{ c.creator_name || c.creator_user || '-' }}</td>
              <td style="text-align:center; white-space:nowrap;">
                <button v-if="parseInt(c.completed_count) < parseInt(c.quantity) && userPerms.can_scan" type="button" class="btn-primary btn-mini" @click="directTraceFromConfig(c.task_order)">扫码</button>
                <button v-if="userPerms.can_add_material" type="button" class="btn-outline btn-mini" style="margin-left:3px;" @click="directEditFromConfig(c.task_order)">编辑</button>
                <button type="button" class="btn-primary btn-mini" style="margin-left:3px; background:#0284c7; border:none;" title="推送至 CEM 平台" @click="handlePushFromConfig(c)">推送CEM</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="pagination-bar">
        <span>共 <strong>{{ configsTotal }}</strong> 个单据</span>
        <el-pagination
          v-model:current-page="configsCurPage"
          :page-size="15"
          :total="configsTotal"
          layout="prev, pager, next"
          @current-change="loadConfigsList"
        />
      </div>
    </div>

    <!-- ================= 视图 5: 追溯权限分配 ================= -->
    <div v-show="activeTab === 'perms'" class="tr-card">
      <div class="card-bar" style="display:flex; justify-content:space-between; align-items:center;">
        <span><el-icon style="color:#409EFF; vertical-align: middle;"><Lock /></el-icon> 追溯系统细分权限分配</span>
        <button type="button" class="btn-primary" style="height:32px; font-size:12px;" @click="handleSavePerms">保存权限设置</button>
      </div>
      <div class="sk-table-wrapper">
        <table class="sk-fixed-table" style="text-align:left;">
          <thead>
            <tr>
              <th style="width:110px;">工号</th>
              <th style="width:110px;">用户名</th>
              <th style="width:110px;">姓名</th>
              <th style="width:120px; text-align:center;">添加/编辑物料</th>
              <th style="width:100px; text-align:center;">追溯扫码</th>
              <th style="width:120px; text-align:center;">查看/导出记录</th>
              <th style="width:120px; text-align:center;">修改扫码记录</th>
              <th style="width:120px; text-align:center;">删除扫码记录</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in permsList" :key="u.user_id">
              <td><strong>{{ u.badge_no || '-' }}</strong></td>
              <td>@{{ u.username }}</td>
              <td>{{ u.full_name }}</td>
              <td style="text-align:center;"><input type="checkbox" v-model="u.can_add_material" :true-value="1" :false-value="0" /></td>
              <td style="text-align:center;"><input type="checkbox" v-model="u.can_scan" :true-value="1" :false-value="0" /></td>
              <td style="text-align:center;"><input type="checkbox" v-model="u.can_view" :true-value="1" :false-value="0" /></td>
              <td style="text-align:center;"><input type="checkbox" v-model="u.can_edit_record" :true-value="1" :false-value="0" /></td>
              <td style="text-align:center;"><input type="checkbox" v-model="u.can_delete_record" :true-value="1" :false-value="0" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 弹窗 1: 修改扫码记录明细 -->
    <el-dialog v-model="editModalVisible" title="修改扫码明细" width="460px" destroy-on-close>
      <el-form label-width="140px" size="small">
        <el-form-item label="所属一级条码/装置号">
          <el-input :model-value="editForm.box_sn" disabled />
        </el-form-item>
        <el-form-item label="二级物料代码">
          <el-input :model-value="editForm.child_code" disabled />
        </el-form-item>
        <el-form-item label="二级物料条码 (SN)">
          <el-input v-model="editForm.child_sn" style="font-family:monospace;" />
        </el-form-item>
        <el-form-item label="扫码工位">
          <el-select v-model="editForm.scan_point" style="width:100%;">
            <el-option :value="1" label="扫码点 1" />
            <el-option :value="2" label="扫码点 2" />
            <el-option :value="3" label="扫码点 3" />
            <el-option :value="4" label="扫码点 4" />
            <el-option :value="5" label="扫码点 5" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editModalVisible = false">取消</el-button>
        <el-button type="primary" @click="submitEditRecord">保存修改</el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 2: 工单完工异步推送 CEM 对话框 -->
    <el-dialog v-model="cemDialogVisible" title="工单扫码已完成" width="460px" :show-close="false" :close-on-click-modal="false">
      <div style="text-align:center; padding:10px 0;">
        <p style="font-size:15px; font-weight:bold; color:#1e293b; margin-bottom:8px;">
          🎉 工单【{{ cemTargetWo }}】已完成全部条码扫码！
        </p>
        <p style="color:#64748b; font-size:13px; margin:0;">
          {{ cemStatusText }}
        </p>
      </div>
      <template #footer>
        <el-button type="primary" :disabled="cemLoading" @click="closeCemDialog">确定 (Enter)</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import request from '@/api/request';

// 权限体系
const userPerms = ref({ can_add_material: 0, can_scan: 0, can_view: 0, can_edit_record: 0, can_delete_record: 0, is_admin: 0 });
const hasAnyPerm = computed(() => {
  const p = userPerms.value;
  return p.can_scan || p.can_add_material || p.can_view || p.is_admin;
});

const activeTab = ref('scan');

// 蜂鸣音（880Hz 原生 AudioContext）
const playBeepSuccess = () => {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(880, ctx.currentTime);
    gain.gain.setValueAtTime(0.15, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.15);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.15);
  } catch (e) {}
};

// 浮动通知条
const showFloatingTip = (msg) => {
  let tip = document.getElementById('tr_scan_floating_tip');
  if (!tip) {
    tip = document.createElement('div');
    tip.id = 'tr_scan_floating_tip';
    tip.style.cssText = `
      position: fixed; top: 25px; right: 25px; z-index: 9999;
      background: #16a34a; color: #fff; padding: 10px 18px;
      border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.18);
      font-size: 13.5px; font-weight: bold; pointer-events: none;
      transition: opacity 0.3s ease, transform 0.3s ease;
      opacity: 0; transform: translateY(-10px);
    `;
    document.body.appendChild(tip);
  }
  tip.innerHTML = msg;
  tip.style.opacity = '1';
  tip.style.transform = 'translateY(0)';
  clearTimeout(tip.timer);
  tip.timer = setTimeout(() => {
    tip.style.opacity = '0';
    tip.style.transform = 'translateY(-10px)';
  }, 2000);
};

// =================== 视图 1: 追溯扫码 ===================
const scanWoInput = ref('');
const scanWoInputRef = ref(null);
const barcodeInput = ref('');
const barcodeInputRef = ref(null);
const currentTask = ref(null);
const traceMaterials = ref([]);
const completedBoxes = ref(0);
const selectedPoint = ref(1);
const scanPointsCount = ref(1);
const currentBoxId = ref(0);
const currentBoxSn = ref('');
const currentScannedMap = ref({});
const isCurBoxDone = ref(false);
const barcodePlaceholder = ref('请扫描（一级物料/装置SN）...');
const scanFeedbackMsg = ref('');
const scanFeedbackSuccess = ref(true);

const handleStartTrace = async () => {
  const wo = scanWoInput.value.trim();
  if (!wo) return ElMessage.warning('请输入单号！');

  try {
    const res = await request.get('/trace/startTrace', { params: { work_order: wo } });
    currentTask.value = res.data.task;
    traceMaterials.value = res.data.materials || [];
    completedBoxes.value = res.data.completed_boxes || 0;
    scanPointsCount.value = parseInt(res.data.task.scan_points) || 1;
    selectedPoint.value = 1;
    resetBoxScanState('');
  } catch (e) {
    currentTask.value = null;
    traceMaterials.value = [];
  }
};

const resetBoxScanState = (customMsg) => {
  currentBoxId.value = 0;
  currentBoxSn.value = '';
  currentScannedMap.value = {};
  isCurBoxDone.value = false;
  barcodePlaceholder.value = '请扫描（一级物料/装置SN）...';
  scanFeedbackMsg.value = customMsg || '';
  scanFeedbackSuccess.value = true;
  barcodeInput.value = '';
  nextTick(() => barcodeInputRef.value?.focus());
};

const handlePointChange = () => {
  resetBoxScanState(`【扫码点 ${selectedPoint.value}】请先扫描（一级物料/装置SN）定位待追溯对象`);
};

const handleBarcodeScan = async () => {
  const code = barcodeInput.value.trim();
  if (!code) return;

  try {
    const res = await request.post('/trace/scanBarcode', {
      task_order: currentTask.value.task_order,
      sn: code,
      box_id: currentBoxId.value,
      scan_point: selectedPoint.value
    });

    scanFeedbackMsg.value = '✅ ' + res.message;
    scanFeedbackSuccess.value = true;
    barcodeInput.value = '';

    if (res.data.completed_boxes !== undefined) {
      completedBoxes.value = res.data.completed_boxes;
    }

    if (res.data.is_box) {
      currentBoxId.value = res.data.box_id;
      currentBoxSn.value = res.data.box_sn;
      currentScannedMap.value = res.data.box_scanned || {};
      barcodePlaceholder.value = '请扫描（二级物料板卡SN）...';
    } else {
      currentScannedMap.value = res.data.box_scanned || {};

      if (res.data.cur_point_completed) {
        const oldSn = currentBoxSn.value;
        if (res.data.task_all_completed) {
          triggerTaskCompleted(currentTask.value.task_order);
        } else {
          resetBoxScanState(`【扫码点 ${selectedPoint.value}】上一台(${oldSn})已齐套，请扫描下一台（一级物料/装置SN）`);
          playBeepSuccess();
          showFloatingTip(`✅ 装置 <b>${oldSn}</b> 在【扫码点 ${selectedPoint.value}】齐套！`);
        }
      }
    }
  } catch (err) {
    scanFeedbackMsg.value = '❌ ' + (err.message || '扫码拦截');
    scanFeedbackSuccess.value = false;
    barcodeInput.value = '';
  } finally {
    nextTick(() => barcodeInputRef.value?.focus());
  }
};

// CEM 弹窗联动
const cemDialogVisible = ref(false);
const cemTargetWo = ref('');
const cemStatusText = ref('');
const cemLoading = ref(false);

const triggerTaskCompleted = (wo) => {
  cemTargetWo.value = wo;
  cemStatusText.value = '正在将追溯明细同步至 CEM 中，请稍候...';
  cemLoading.value = true;
  cemDialogVisible.value = true;

  request.post('/trace/manualPushCem', { task_order: wo }, { timeout: 120000 }).then((res) => {
    // 成功时也把服务端返回的明细条数带上，避免"提示成功但到底传了几条"不清楚
    const n = res && res.data && res.data.count;
    cemStatusText.value = '✅ CEM 已经同步完成！'
      + (n ? `本单共上传 ${n} 条追溯明细。` : '本单所有追溯明细已顺利上传至 CEM 平台。');
    cemLoading.value = false;
  }).catch((e) => {
    // ★ 显示服务端返回的真实原因，而不是一句笼统的"未成功"
    const detail = (e && e.message) ? String(e.message) : '';
    cemStatusText.value = '⚠️ 本地扫码已完成，但 CEM 上传未成功。'
      + (detail ? `原因：${detail}。` : '')
      + '可在【追溯记录查询与导出】页随时补推。';
    cemLoading.value = false;
  });
};

const closeCemDialog = () => {
  cemDialogVisible.value = false;
  currentTask.value = null;
  scanWoInput.value = '';
  resetBoxScanState('');
};

// =================== 视图 2: 添加物料 ===================
const addWoInput = ref('');
const erpTask = ref(null);
const erpIsXq = ref(false);
const erpMaterials = ref([]);
const cfgScanPoints = ref(1);

const allMatSelected = ref(true);
const toggleSelectAllMat = () => {
  erpMaterials.value.forEach(m => m.selected = allMatSelected.value);
};

const hasZeroStockSelected = computed(() => {
  return erpMaterials.value.some(m => m.selected && m.color_state === 'red');
});

const handleFetchErp = async () => {
  const wo = addWoInput.value.trim();
  if (!wo) return ElMessage.warning('请输入工单号或 XQ 发货单号！');

  try {
    const res = await request.get('/trace/checkOrFetchErp', { params: { work_order: wo } });
    erpTask.value = res.data.task;
    erpIsXq.value = res.data.is_xq;
    erpMaterials.value = (res.data.materials || []).map(m => ({ ...m, selected: true }));
  } catch (e) {}
};

const handleSaveMatConfig = async () => {
  const selected = erpMaterials.value.filter(m => m.selected);
  if (selected.length === 0) return ElMessage.warning('请勾选至少一项追溯物料！');

  await request.post('/trace/saveConfig', {
    task_order: erpTask.value.task_order,
    parent_code: erpTask.value.parent_code,
    parent_name: erpTask.value.description,
    quantity: erpTask.value.quantity,
    batch_no: erpTask.value.batch_no || '',
    scan_points: cfgScanPoints.value,
    children: selected
  });

  ElMessage.success('追溯配置保存成功！已自动切换至扫码台');
  activeTab.value = 'scan';
  scanWoInput.value = erpTask.value.task_order;
  handleStartTrace();
};

// =================== 视图 3: 记录查询 ===================
const querySearchKw = ref('');
const queryRecords = ref([]);
const queryTotal = ref(0);
const queryCurPage = ref(1);
const queryTotalPages = ref(1);

const loadQueryRecords = async (page = 1) => {
  queryCurPage.value = page;
  const res = await request.get('/trace/queryRecords', {
    params: { page, search: querySearchKw.value.trim() }
  });
  queryRecords.value = res.data.records || [];
  queryTotal.value = res.data.total;
  queryTotalPages.value = res.data.total_pages;
};

// 记录修改弹窗
const editModalVisible = ref(false);
const editForm = ref({ scan_id: 0, box_sn: '', child_code: '', child_sn: '', scan_point: 1 });
const openEditModal = (r) => {
  editForm.value = { ...r, scan_point: r.scan_point || 1 };
  editModalVisible.value = true;
};
const submitEditRecord = async () => {
  await request.post('/trace/updateScanRecord', editForm.value);
  ElMessage.success('修改记录成功！');
  editModalVisible.value = false;
  loadQueryRecords(queryCurPage.value);
};

const handleDeleteRecord = (r) => {
  ElMessageBox.confirm(`确定要删除条码 [${r.child_sn}] 的扫码记录吗？删除后该装置需重新补扫。`, '确认删除', {
    type: 'warning'
  }).then(async () => {
    await request.post('/trace/deleteScanRecord', { scan_id: r.scan_id, child_sn: r.child_sn, box_sn: r.box_sn });
    ElMessage.success('删除成功');
    loadQueryRecords(queryCurPage.value);
  });
};

const handleExportExcel = () => {
  const kw = querySearchKw.value.trim();

  const form = document.createElement("form");
  form.style.display = "none";
  form.method = "POST";
  // ★ L7：不再通过 URL / 隐藏域传 token。同源表单提交会自动携带 HttpOnly 会话 Cookie。
  form.action = "/api/trace/exportExcel";
  form.target = "_blank";

  const searchInput = document.createElement("input");
  searchInput.type = "hidden";
  searchInput.name = "search";
  searchInput.value = kw;
  form.appendChild(searchInput);

  document.body.appendChild(form);
  form.submit();

  setTimeout(() => {
    document.body.removeChild(form);
  }, 3000);
};

const handleManualPushCem = async () => {
  const targetWo = querySearchKw.value.trim();
  if (!targetWo) return ElMessage.warning('请输入要推送的单号！');
  const loading = ElLoading.service({ text: `正在推送工单【${targetWo}】至 CEM 平台，请耐心等待...`, background: 'rgba(0,0,0,0.7)' });
  try {
    const res = await request.post('/trace/manualPushCem', { task_order: targetWo }, { timeout: 120000 });
    ElMessage.success(res.message || 'CEM 平台推送处理成功！');
  } catch (err) {
    ElMessage.error(err.message || '推送失败，请检查网络或单号');
  } finally {
    loading.close();
  }
};

const handlePushFromConfig = async (row) => {
  const targetWo = row.task_order;
  if (!targetWo) return;
  const loading = ElLoading.service({ text: `正在推送单据【${targetWo}】至 CEM 平台...`, background: 'rgba(0,0,0,0.7)' });
  try {
    const res = await request.post('/trace/manualPushCem', { task_order: targetWo }, { timeout: 120000 });
    ElMessage.success(res.message || '推送成功');
    row.cem_pushed = 1;
  } catch (err) {
    ElMessage.error(err.message || '推送失败');
  } finally {
    loading.close();
  }
};

// =================== 视图 4: 已配置单据库 ===================
const configsList = ref([]);
const configsTotal = ref(0);
const configsCurPage = ref(1);

const loadConfigsList = async (page = 1) => {
  configsCurPage.value = page;
  const res = await request.get('/trace/listConfigs', { params: { page } });
  configsList.value = res.data.list || [];
  configsTotal.value = res.data.total;
};

const directTraceFromConfig = (wo) => {
  activeTab.value = 'scan';
  scanWoInput.value = wo;
  handleStartTrace();
};

const directEditFromConfig = (wo) => {
  activeTab.value = 'add';
  addWoInput.value = wo;
  handleFetchErp();
};

// =================== 视图 5: 权限管理 ===================
const permsList = ref([]);
const loadPermsList = async () => {
  const res = await request.get('/trace/listPermissions');
  permsList.value = res.data.list || [];
};
const handleSavePerms = async () => {
  await request.post('/trace/savePermissions', { permissions: permsList.value });
  ElMessage.success('权限保存成功！');
  loadPermsList();
};

// Tab 切换逻辑
const switchTab = (t) => {
  activeTab.value = t;
  if (t === 'query') loadQueryRecords(1);
  else if (t === 'configs') loadConfigsList(1);
  else if (t === 'perms') loadPermsList();
};

onMounted(async () => {
  const res = await request.get('/trace/init');
  userPerms.value = res.data.user_perms;
  if (userPerms.value.can_scan) activeTab.value = 'scan';
  else if (userPerms.value.can_add_material) activeTab.value = 'add';
  else if (userPerms.value.can_view) activeTab.value = 'query';
  else if (userPerms.value.is_admin) activeTab.value = 'perms';
});
</script>

<style scoped>
.trace-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.tr-card { background: #fff; border: 1px solid #dee2e6; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); margin-bottom: 12px; overflow: hidden; }
.card-bar { padding: 10px 14px; background: #f8f9fa; border-bottom: 1px solid #dee2e6; font-size: 13.5px; font-weight: 600; color: #2c3e50; }

.tr-tabs { display: flex; gap: 8px; margin-bottom: 12px; border-bottom: 2px solid #dee2e6; flex-wrap: wrap; }
.tr-tab-btn {
  display: inline-flex; align-items: center; white-space: nowrap; padding: 9px 18px; border: none; background: transparent;
  font-size: 15px; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.2s;
}
.tr-tab-btn.active { color: #007bff; border-bottom-color: #007bff; }

.tr-single-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
.tr-inline-info { flex: 1; display: flex; align-items: center; gap: 16px; background: #f0f7ff; border: 1px solid #d0e3ff; border-radius: 4px; padding: 0 14px; height: 34px; line-height: 32px; font-size: 13px; color: #2c3e50; min-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.station-banner { background:#fff3cd; border:1px solid #ffeeba; border-radius:4px; padding:8px 12px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
.tr-input { height: 33px; font-size: 12.5px; border: 1px solid #ced4da; border-radius: 4px; padding: 0 10px; outline: none; }
.tr-input:focus { border-color: #80bdff; box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25); }
.tr-select { height: 30px; font-size: 12px; border: 1px solid #ced4da; border-radius: 4px; padding: 0 8px; outline: none; }

.btn-primary { background: #007bff; color: #fff; border: 1px solid #007bff; border-radius: 4px; padding: 6px 14px; font-size: 12.5px; cursor: pointer; }
.btn-primary:hover { background: #0069d9; }
.btn-success { background: #28a745; color: #fff; border: 1px solid #28a745; border-radius: 4px; padding: 6px 14px; font-size: 12.5px; cursor: pointer; }
.btn-info { background: #17a2b8; color: #fff; border: 1px solid #17a2b8; border-radius: 4px; padding: 6px 14px; font-size: 12.5px; cursor: pointer; }
.btn-danger { background: #dc3545; color: #fff; border: 1px solid #dc3545; border-radius: 4px; padding: 6px 14px; font-size: 12.5px; cursor: pointer; }
.btn-outline { background: #fff; color: #495057; border: 1px solid #ced4da; border-radius: 4px; padding: 6px 14px; font-size: 12.5px; cursor: pointer; }
.btn-mini { padding: 2px 6px; font-size: 11px; }

.badge-done { background: #16a34a; color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 11px; }
.badge-kitted { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; }
.badge-partial { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; }
.badge-zero { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; }

.mat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; margin: 10px 0; }
.mat-card { background: #f8f9fa; border: 1px solid #dee2e6; border-left: 4px solid #007bff; border-radius: 4px; padding: 8px 12px; }
.mat-card.done { background: #d4edda; border-left-color: #28a745; border-color: #c3e6cb; }

.sk-table-wrapper { max-height: calc(100vh - 350px); min-height: 300px; overflow-y: auto; overflow-x: auto; border-top: 1px solid #dee2e6; }
.sk-fixed-table { width: 100% !important; border-collapse: separate; border-spacing: 0; font-size: 13px; text-align: center; color: #333; }
.sk-fixed-table thead th { position: sticky; top: 0; background: #f8f9fa; z-index: 10; padding: 8px; border-bottom: 2px solid #dee2e6; border-right: 1px solid #dee2e6; white-space: nowrap; font-weight: 600; color: #2c3e50; }
.sk-fixed-table tbody td { padding: 7px 6px; border-bottom: 1px solid #eee; border-right: 1px solid #eee; white-space: nowrap; vertical-align: middle; }
.sk-fixed-table tbody tr:hover { background-color: #f8fafc; }

.cfg-table-wrapper { max-height: calc(100vh - 350px); min-height: 300px; overflow-y: auto; overflow-x: hidden !important; border-top: 1px solid #dee2e6; width: 100%; }
.cfg-fixed-table { width: 100% !important; border-collapse: separate; border-spacing: 0; table-layout: fixed !important; font-size: 12.5px; text-align: center; color: #333; }
.cfg-fixed-table thead th { position: sticky; top: 0; background: #f8f9fa; z-index: 10; padding: 8px 6px; border-bottom: 2px solid #dee2e6; border-right: 1px solid #dee2e6; white-space: nowrap; font-weight: 600; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; }
.cfg-fixed-table tbody td { padding: 6px 5px; border-bottom: 1px solid #eee; border-right: 1px solid #eee; white-space: nowrap; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }
.cfg-fixed-table tbody tr:hover { background-color: #f8fafc; }

.cell-truncate { display: block; width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: left; }
.tr-mat-green { background-color: #f6ffed !important; }
.tr-mat-orange { background-color: #fffbe6 !important; }
.tr-mat-red { background-color: #fff1f0 !important; }

.kitting-alert { background: #fff2f0; border: 1px solid #ffccc7; border-radius: 4px; padding: 10px 14px; margin-bottom: 12px; color: #cf1322; font-size: 13px; font-weight: bold; }
.pagination-bar { padding: 8px 14px; background: #f8f9fa; border-top: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; font-size: 12px; flex-wrap: wrap; gap: 8px; }
.no-perm-box { text-align: center; padding: 50px 20px; background: #fff; border-radius: 6px; border: 1px solid #dee2e6; }
</style>