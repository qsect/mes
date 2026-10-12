<template>
  <div class="mac-container">
    <!-- 顶部标题卡片 -->
    <div class="mac-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Connection /></el-icon>
          MAC 地址管理系统
        </span>
      </div>
    </div>

    <!-- 选项卡导航 -->
    <div class="mac-tabs">
      <button
        v-if="macPerms.can_apply"
        type="button"
        class="mac-tab-btn"
        :class="{ active: activeTab === 'apply' }"
        @click="switchTab('apply')"
      >
        <el-icon class="mr-1"><Check /></el-icon> MAC 地址申请
      </button>
      <button
        v-if="macPerms.can_config_ports"
        type="button"
        class="mac-tab-btn"
        :class="{ active: activeTab === 'ports' }"
        @click="switchTab('ports')"
      >
        <el-icon class="mr-1"><Setting /></el-icon> 产品网口配置
      </button>
      <button
        v-if="macPerms.can_import"
        type="button"
        class="mac-tab-btn"
        :class="{ active: activeTab === 'import' }"
        @click="switchTab('import')"
      >
        <el-icon class="mr-1"><Upload /></el-icon> MAC 批量录入
      </button>
      <button
        v-if="macPerms.can_view_records"
        type="button"
        class="mac-tab-btn"
        :class="{ active: activeTab === 'records' }"
        @click="switchTab('records')"
      >
        <el-icon class="mr-1"><List /></el-icon> 申请记录管理
      </button>
      <button
        v-if="macPerms.can_view_stats"
        type="button"
        class="mac-tab-btn"
        :class="{ active: activeTab === 'stats' }"
        @click="switchTab('stats')"
      >
        <el-icon class="mr-1"><DataLine /></el-icon> 库存统计看板
      </button>
      <button
        v-if="macPerms.can_view_pool"
        type="button"
        class="mac-tab-btn"
        :class="{ active: activeTab === 'pool' }"
        @click="switchTab('pool')"
      >
        <el-icon class="mr-1"><Document /></el-icon> 地址池总表
      </button>
      <button
        v-if="macPerms.can_view_logs"
        type="button"
        class="mac-tab-btn"
        :class="{ active: activeTab === 'logs' }"
        @click="switchTab('logs')"
      >
        <el-icon class="mr-1"><List /></el-icon> 录入日志
      </button>
    </div>

    <!-- 无权限占位 -->
    <div v-if="hasNoPermission" class="mac-card" style="text-align:center; padding:50px 20px;">
      <el-icon :size="48" color="#dc3545"><Lock /></el-icon>
      <h4 style="font-size:16px; color:#2c3e50; font-weight:600; margin-top:12px;">暂无 MAC 地址管理的操作权限</h4>
      <p style="font-size:12.5px; color:#6c757d; margin-top:6px;">请联系系统管理员分配相关权限。</p>
    </div>

    <!-- ================= 视图 1: MAC 地址申请 ================= -->
    <div v-show="activeTab === 'apply' && macPerms.can_apply">
      <div class="mac-card" style="padding:16px 20px;">
        <h3 style="margin:0 0 12px 0; font-size:14.5px; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:4px;"><Check /></el-icon>
          工单 MAC 分配申请
        </h3>

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
          <div style="display:flex; gap:6px; align-items:center; width:340px;">
            <label style="font-weight:600; font-size:12.5px; color:#495057; white-space:nowrap; margin-bottom:0;">工单号 *</label>
            <input
              v-model="applyWorkOrder"
              type="text"
              class="mac-input"
              placeholder="输入纯数字工单号..."
              @keyup.enter="checkWorkOrder"
            />
            <button type="button" class="btn-primary" @click="checkWorkOrder">查询</button>
          </div>

          <div v-if="currentWoInfo" class="mac-info-box">
            <span><strong>代码:</strong> <span style="font-family:monospace;">{{ currentWoInfo.code }}</span></span>
            <span><strong>描述:</strong> {{ currentWoInfo.description }}</span>
            <span v-if="autoConfigured" style="color:#67C23A; font-weight:600;">
              <el-icon style="vertical-align:middle;"><Check /></el-icon> 已自动匹配网口与类别
            </span>
          </div>
        </div>

        <div v-if="reapplyWarning" style="background:#fff3cd; color:#856404; border:1px solid #ffeeba; border-radius:4px; padding:8px 12px; margin-bottom:12px; font-size:12.5px;">
          {{ reapplyWarning }}
        </div>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px 14px; margin-top:8px;">
          <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end;">
            <div style="width:200px;">
              <label style="font-weight:600; display:block; margin-bottom:4px; font-size:12.5px; color:#495057;">
                MAC 分类 * <small v-if="autoCatMatched" style="color:#67C23A;">(自动匹配)</small>
              </label>
              <select v-model="applyCategoryId" class="mac-select" style="width:100%;">
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>

            <div style="width:130px;">
              <label style="font-weight:600; display:block; margin-bottom:4px; font-size:12.5px; color:#495057;">工单数量 (台) *</label>
              <input v-model.number="applyQty" type="number" min="1" class="mac-input" style="width:100%;" />
            </div>

            <div style="width:160px;">
              <label style="font-weight:600; display:block; margin-bottom:4px; font-size:12.5px; color:#495057;">
                网口数量 (个) * <small v-if="autoPortMatched" style="color:#67C23A;">(预设: {{ applyPort }}个)</small>
              </label>
              <input v-model.number="applyPort" type="number" min="1" class="mac-input" style="width:100%;" />
            </div>

            <div style="background:#e8f4fd; border:1px solid #cfe2ff; border-radius:4px; height:33px; padding:0 14px; display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#0c5460;">
              <span>需求总量:</span> <strong style="color:#409EFF; font-size:16px;">{{ applyQty * applyPort }}</strong> <span>个 MAC</span>
            </div>

            <div style="margin-left:auto;">
              <button type="button" class="btn-success" :disabled="submittingApply" @click="submitApply">
                <span v-if="submittingApply">分配中...</span>
                <span v-else>确认分配申请</span>
              </button>
            </div>
          </div>
        </div>

        <div v-if="showReapplyReason" style="margin-top:10px;">
          <label style="font-weight:600; display:block; margin-bottom:3px; font-size:12.5px; color:#F56C6C;">重新申请原因 *</label>
          <textarea v-model="reapplyReason" class="mac-input" rows="2" style="width:100%; height:auto;" placeholder="请详细说明重复申请原因..."></textarea>
        </div>
      </div>
    </div>

    <!-- ================= 视图 2: 产品网口配置 ================= -->
    <div v-show="activeTab === 'ports' && macPerms.can_config_ports">
      <div class="mac-card" style="padding:16px 20px; margin-bottom:12px;">
        <h3 style="margin:0 0 8px 0; font-size:14.5px; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:4px;"><Setting /></el-icon>
          产品网口与类别预设配置
        </h3>
        <p style="color:#64748b; font-size:12px; margin-bottom:14px;">
          在此预设产品代码对应的物料描述、网口数量及所属产品类别。申请 MAC 地址时输入工单，系统将自动关联对应类别和网口数。
        </p>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px 14px;">
          <div style="display:flex; gap:10px; flex-wrap:nowrap; align-items:flex-end; width:100%; overflow-x:auto;">
            <div style="display:flex; flex-direction:column; gap:4px; width:230px; flex-shrink:0;">
              <label style="font-weight:600; font-size:12.5px; color:#495057;">产品代码 *</label>
              <div style="display:flex; gap:4px;">
                <input v-model="cfgProdCode" type="text" class="mac-input" placeholder="输入产品代码..." @keyup.enter="queryProductDesc" />
                <button type="button" class="btn-primary btn-mini" style="padding:0 8px;" @click="queryProductDesc">查描述</button>
              </div>
            </div>

            <div style="display:flex; flex-direction:column; gap:4px; flex:1; min-width:200px;">
              <label style="font-weight:600; font-size:12.5px; color:#495057;">物料描述</label>
              <input v-model="cfgProdDesc" type="text" class="mac-input" placeholder="输入物料描述..." />
            </div>

            <div style="display:flex; flex-direction:column; gap:4px; width:105px; flex-shrink:0;">
              <label style="font-weight:600; font-size:12.5px; color:#495057;">网口数量 *</label>
              <input v-model.number="cfgPortCount" type="number" min="1" class="mac-input" style="text-align:center;" />
            </div>

            <div style="display:flex; flex-direction:column; gap:4px; width:150px; flex-shrink:0;">
              <label style="font-weight:600; font-size:12.5px; color:#495057;">产品类别 *</label>
              <select v-model="cfgCategoryId" class="mac-select" style="width:100%;">
                <option value="0">-- 请选择 --</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>

            <div style="flex-shrink:0;">
              <button type="button" class="btn-success" @click="savePortConfig">保存配置</button>
            </div>
          </div>
        </div>
      </div>

      <div class="mac-card">
        <div class="card-bar">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">已配置产品列表</span>
          <div style="display:flex; gap:6px;">
            <input v-model="portSearchKw" type="text" class="mac-input" style="width:200px;" placeholder="搜索代码或描述..." @keyup.enter="loadPortConfigs" />
            <button type="button" class="btn-primary" @click="loadPortConfigs">筛选</button>
          </div>
        </div>
        <div class="mac-table-wrapper">
          <table class="mac-fixed-table">
            <thead>
              <tr>
                <th style="width:60px; text-align:center;">序号</th>
                <th style="width:150px;">产品代码</th>
                <th>物料/产品描述</th>
                <th style="width:130px;">产品类别</th>
                <th style="width:95px; text-align:center;">网口数</th>
                <th style="width:100px;">维护人</th>
                <th style="width:135px;">更新时间</th>
                <th style="width:90px; text-align:center;">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, idx) in portConfigList" :key="item.id">
                <td style="text-align:center;">{{ idx + 1 }}</td>
                <td><strong style="font-family:monospace; color:#2c3e50;">{{ item.product_code }}</strong></td>
                <td :title="item.description"><span class="cell-truncate">{{ item.description || '-' }}</span></td>
                <td><span class="badge" style="background:#e8f4fd; color:#409EFF; padding:2px 6px; border-radius:4px;">{{ item.category_name || '未指定' }}</span></td>
                <td style="text-align:center;"><strong style="color:#409EFF; font-size:14px;">{{ item.port_count }}</strong></td>
                <td>{{ item.updated_by || '-' }}</td>
                <td><span style="font-size:11.5px; color:#64748b;">{{ item.updated_at }}</span></td>
                <td style="text-align:center; white-space:nowrap;">
                  <button type="button" class="btn-primary btn-mini" @click="editPortItem(item)">修改</button>
                  <button type="button" class="btn-danger btn-mini" style="margin-left:4px;" @click="deletePortItem(item.id)">删除</button>
                </td>
              </tr>
              <tr v-if="portConfigList.length === 0">
                <td colspan="8" style="padding:25px; color:#888;">暂无记录</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ================= 视图 3: MAC 批量录入 ================= -->
    <div v-show="activeTab === 'import' && macPerms.can_import">
      <div class="mac-card" style="padding:16px 20px;">
        <h3 style="margin:0 0 12px 0; font-size:14.5px; color:#2c3e50;">
          <el-icon style="color:#67C23A; vertical-align:middle; margin-right:4px;"><Upload /></el-icon>
          批量地址录入到地址池
        </h3>

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px 14px;">
          <div style="display:flex; align-items:center; gap:6px;">
            <label style="font-weight:600; font-size:12.5px; color:#495057; white-space:nowrap; margin-bottom:0;">录入分类 *</label>
            <select v-model="importCategoryId" class="mac-select" style="width:110px;">
              <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>

          <div style="display:flex; align-items:center; gap:6px;">
            <label style="font-weight:600; font-size:12.5px; color:#495057; white-space:nowrap; margin-bottom:0;">起始 MAC *</label>
            <input v-model="importStartMac" type="text" class="mac-input" style="width:180px; font-family:monospace;" placeholder="如: 000-014-234-000-000-000" />
          </div>

          <div style="display:flex; align-items:center; gap:6px;">
            <label style="font-weight:600; font-size:12.5px; color:#495057; white-space:nowrap; margin-bottom:0;">终止 MAC</label>
            <input v-model="importEndMac" type="text" class="mac-input" style="width:180px; font-family:monospace;" placeholder="留空则按数量..." />
          </div>

          <div style="display:flex; align-items:center; gap:6px;">
            <label style="font-weight:600; font-size:12.5px; color:#495057; white-space:nowrap; margin-bottom:0;">数量</label>
            <input v-model.number="importQuantity" type="number" min="1" class="mac-input" style="width:80px; text-align:center;" placeholder="数量" />
          </div>

          <div style="display:flex; align-items:center; gap:6px; flex:1; min-width:160px;">
            <label style="font-weight:600; font-size:12.5px; color:#495057; white-space:nowrap; margin-bottom:0;">备注</label>
            <input v-model="importRemarks" type="text" class="mac-input" style="width:100%;" placeholder="说明..." />
          </div>

          <button type="button" class="btn-primary" @click="submitImportMac">执行录入</button>
        </div>
      </div>
    </div>

    <!-- ================= 视图 4: 申请记录管理 ================= -->
    <div v-show="activeTab === 'records' && macPerms.can_view_records">
      <div class="mac-card">
        <div class="card-bar">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">MAC 地址申请历史总表</span>
          <div style="display:flex; gap:6px;">
            <input v-model="recordSearchKw" type="text" class="mac-input" style="width:220px;" placeholder="搜索工单、代码、分类、申请人..." @keyup.enter="loadRecords(1)" />
            <button type="button" class="btn-primary" @click="loadRecords(1)">搜索</button>
            <button v-if="isAdmin" type="button" class="btn-danger" @click="batchDeleteRecords">批量删除并释放</button>
          </div>
        </div>
        <div class="mac-table-wrapper">
          <table class="mac-fixed-table">
            <thead>
              <tr>
                <th v-if="isAdmin" style="width:36px; text-align:center;"><input type="checkbox" v-model="allRecSelected" @change="toggleSelectAllRec" /></th>
                <th style="width:90px;">工单号</th>
                <th style="width:90px;">代码</th>
                <th>描述</th>
                <th style="width:85px;">分类</th>
                <th style="width:60px; text-align:right;">台数</th>
                <th style="width:75px; text-align:right;">分配总数</th>
                <th style="width:165px;">起始 MAC</th>
                <th style="width:165px;">终止 MAC</th>
                <th style="width:75px;">申请人</th>
                <th style="width:115px;">时间</th>
                <th style="width:105px; text-align:center;">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in recordList" :key="r.id">
                <td v-if="isAdmin" style="text-align:center;"><input type="checkbox" v-model="selectedRecIds" :value="r.id" /></td>
                <td><strong>{{ r.work_order }}</strong></td>
                <td><span style="font-family:monospace;">{{ r.task_code || '-' }}</span></td>
                <td :title="r.description"><span class="cell-truncate">{{ r.description || '-' }}</span></td>
                <td><span class="badge" style="background:#e8f4fd; color:#409EFF; padding:2px 6px; border-radius:4px;">{{ r.category_name }}</span></td>
                <td style="text-align:right;">{{ r.order_quantity }}</td>
                <td style="text-align:right; font-weight:bold; color:#67C23A;">{{ r.total_mac_count }}</td>
                <td><span style="font-family:monospace; font-size:11.5px;">{{ r.start_mac }}</span></td>
                <td><span style="font-family:monospace; font-size:11.5px;">{{ r.end_mac }}</span></td>
                <td>{{ r.applicant_name }}</td>
                <td><span style="font-size:11.5px; color:#64748b;">{{ String(r.created_at || '').substring(5, 16) }}</span></td>
                <td style="text-align:center; white-space:nowrap;">
                  <button type="button" class="btn-outline btn-mini" @click="openDetailModal(r.id)">明细</button>
                  <button type="button" class="btn-primary btn-mini" style="margin-left:4px;" @click="exportDetailCsv(r.id)">导出</button>
                </td>
              </tr>
              <tr v-if="recordList.length === 0">
                <td :colspan="isAdmin ? 12 : 11" style="padding:35px; color:#888;">暂无记录</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mac-pagination-bar">
          <span>共 {{ totalRecords }} 条记录，第 {{ curRecordPage }}/{{ totalRecordPages }} 页</span>
          <el-pagination v-model:current-page="curRecordPage" :page-size="15" :total="totalRecords" layout="prev, pager, next" @current-change="loadRecords" />
        </div>
      </div>
    </div>

    <!-- ================= 视图 5: 库存统计看板 ================= -->
    <div v-show="activeTab === 'stats' && macPerms.can_view_stats">
      <div class="stats-cards-grid">
        <div v-for="s in statsList" :key="s.id" class="stat-mac-card">
          <h4 style="margin:0 0 8px 0; font-size:14.5px; color:#2c3e50; border-bottom:1px solid #dee2e6; padding-bottom:6px; font-weight:600;">
            {{ s.name }}
          </h4>
          <div style="display:flex; justify-content:space-between; margin-bottom:4px; font-size:12.5px;">
            <span style="color:#64748b;">总入库量:</span> <strong style="color:#409EFF;">{{ s.total_count }}</strong>
          </div>
          <div style="display:flex; justify-content:space-between; margin-bottom:4px; font-size:12.5px;">
            <span style="color:#64748b;">已分配数:</span> <strong style="color:#E6A23C;">{{ s.used_count }}</strong>
          </div>
          <div style="display:flex; justify-content:space-between; margin-bottom:4px; font-size:12.5px;">
            <span style="color:#64748b;">剩余可用:</span> <strong style="color:#67C23A; font-size:15px;">{{ s.available }}</strong>
          </div>
        </div>
      </div>
    </div>

    <!-- ================= 视图 6: 地址池总表 ================= -->
    <div v-show="activeTab === 'pool' && macPerms.can_view_pool">
      <div class="mac-card">
        <div class="card-bar">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">全局 MAC 地址池总表</span>
          <div style="display:flex; gap:6px;">
            <select v-model="poolCatFilter" class="mac-select" style="width:120px;">
              <option :value="0">全部分类</option>
              <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <select v-model="poolStatusFilter" class="mac-select" style="width:100px;">
              <option :value="-1">全部状态</option>
              <option :value="0">空闲</option>
              <option :value="1">已占用</option>
            </select>
            <input v-model="poolSearchKw" type="text" class="mac-input" style="width:160px;" placeholder="搜索 MAC 或工单..." @keyup.enter="loadPool(1)" />
            <button type="button" class="btn-primary" @click="loadPool(1)">筛选</button>
          </div>
        </div>
        <div class="mac-table-wrapper">
          <table class="mac-fixed-table">
            <thead>
              <tr>
                <th style="width:60px;">ID</th>
                <th>MAC 地址</th>
                <th style="width:120px;">所属分类</th>
                <th style="width:80px; text-align:center;">状态</th>
                <th style="width:120px;">占用工单</th>
                <th style="width:140px;">使用时间</th>
                <th style="width:140px;">录入时间</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in poolList" :key="p.id">
                <td>{{ p.id }}</td>
                <td><strong style="font-family:monospace; color:#2c3e50;">{{ p.mac_address }}</strong></td>
                <td>{{ p.category_name }}</td>
                <td style="text-align:center;">
                  <span class="badge" :style="{ background: p.status == 1 ? '#f8d7da' : '#d4edda', color: p.status == 1 ? '#721c24' : '#155724', padding:'2px 6px', borderRadius:'4px' }">
                    {{ p.status == 1 ? '已占用' : '空闲' }}
                  </span>
                </td>
                <td>{{ p.used_by_work_order || '-' }}</td>
                <td><span style="font-size:11.5px; color:#64748b;">{{ p.used_at || '-' }}</span></td>
                <td><span style="font-size:11.5px; color:#64748b;">{{ p.created_at }}</span></td>
              </tr>
              <tr v-if="poolList.length === 0">
                <td colspan="7" style="padding:25px; color:#888;">暂无地址数据</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mac-pagination-bar">
          <span>共 {{ totalPool }} 个地址，第 {{ curPoolPage }}/{{ totalPoolPages }} 页</span>
          <el-pagination v-model:current-page="curPoolPage" :page-size="25" :total="totalPool" layout="prev, pager, next" @current-change="loadPool" />
        </div>
      </div>
    </div>

    <!-- ================= 视图 7: 录入日志 ================= -->
    <div v-show="activeTab === 'logs' && macPerms.can_view_logs">
      <div class="mac-card">
        <div class="card-bar">
          <span style="font-size:13.5px; font-weight:600; color:#2c3e50;">MAC 地址批量录入操作日志</span>
        </div>
        <div class="mac-table-wrapper">
          <table class="mac-fixed-table">
            <thead>
              <tr>
                <th style="width:60px;">ID</th>
                <th style="width:110px;">分类</th>
                <th>起始 MAC</th>
                <th>终止 MAC</th>
                <th style="width:90px; text-align:right;">录入数量</th>
                <th style="width:100px;">操作人</th>
                <th style="width:140px;">录入时间</th>
                <th>备注说明</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in logList" :key="l.id">
                <td>{{ l.id }}</td>
                <td><strong>{{ l.category_name }}</strong></td>
                <td><span style="font-family:monospace;">{{ l.start_mac }}</span></td>
                <td><span style="font-family:monospace;">{{ l.end_mac || '-' }}</span></td>
                <td style="text-align:right; font-weight:bold; color:#409EFF;">{{ l.quantity }}</td>
                <td>{{ l.imported_by_name }}</td>
                <td><span style="font-size:11.5px; color:#64748b;">{{ l.imported_at }}</span></td>
                <td :title="l.remarks"><span class="cell-truncate">{{ l.remarks || '-' }}</span></td>
              </tr>
              <tr v-if="logList.length === 0">
                <td colspan="8" style="padding:25px; color:#888;">暂无录入日志</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mac-pagination-bar">
          <span>共 {{ totalLogs }} 条日志，第 {{ curLogPage }}/{{ totalLogPages }} 页</span>
          <el-pagination v-model:current-page="curLogPage" :page-size="20" :total="totalLogs" layout="prev, pager, next" @current-change="loadLogs" />
        </div>
      </div>
    </div>

    <!-- 分配明细弹窗 -->
    <el-dialog v-model="detailModalVisible" title="MAC 分配明细清单" width="620px" destroy-on-close>
      <div v-if="currentDetailRecord" style="background:#f0f7ff; border:1px solid #d0e3ff; padding:8px 12px; border-radius:4px; margin-bottom:10px; font-size:12.5px;">
        <strong>工单:</strong> {{ currentDetailRecord.work_order }} | <strong>分类:</strong> {{ currentDetailRecord.category_name }} | <strong>总量:</strong> {{ currentDetailRecord.total_mac_count }} 个
      </div>
      <div style="max-height:360px; overflow-y:auto; border:1px solid #dee2e6;">
        <table class="mac-fixed-table">
          <thead>
            <tr>
              <th style="width:60px; text-align:center;">序号</th>
              <th>分配的 MAC 地址</th>
              <th style="width:140px;">分配时间</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in detailList" :key="d.id">
              <td style="text-align:center;">#{{ d.sequence_no }}</td>
              <td><strong style="font-family:monospace; color:#2c3e50;">{{ d.mac_address }}</strong></td>
              <td><span style="font-size:11.5px; color:#64748b;">{{ d.created_at }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Connection, Setting, Plus, Download, Upload,
  Delete, Search, Document, Check, Warning, List, DataLine, Lock
} from '@element-plus/icons-vue';
import request from '@/api/request';

const categories = ref([]);
const activeTab = ref('apply');
const isAdmin = ref(false);

const macPerms = reactive({
  can_apply: 1,
  can_config_ports: 0,
  can_import: 0,
  can_view_records: 1,
  can_view_stats: 1,
  can_view_pool: 0,
  can_view_logs: 0,
  is_admin: 0
});

const hasNoPermission = computed(() => {
  return !macPerms.can_apply && !macPerms.can_config_ports && !macPerms.can_import
    && !macPerms.can_view_records && !macPerms.can_view_stats && !macPerms.can_view_pool && !macPerms.can_view_logs;
});

// 1. 申请
const applyWorkOrder = ref('');
const currentWoInfo = ref(null);
const applyCategoryId = ref(null);
const applyQty = ref(1);
const applyPort = ref(1);
const autoConfigured = ref(false);
const autoCatMatched = ref(false);
const autoPortMatched = ref(false);
const reapplyWarning = ref('');
const showReapplyReason = ref(false);
const reapplyReason = ref('');
const submittingApply = ref(false);

const checkWorkOrder = async () => {
  const wo = applyWorkOrder.value.trim();
  if (!wo || !/^\d{6,}$/.test(wo)) return ElMessage.warning('请输入正确的纯数字工单号（至少6位）');

  try {
    const res = await request.post('/mac/get_work_order_info', { work_order: wo });
    if (res.data) {
      const { applied, order_info, auto_port_count, auto_category_id } = res.data;
      currentWoInfo.value = order_info;

      if (order_info) {
        if (order_info.quantity > 0) applyQty.value = order_info.quantity;
        let matched = false;
        if (auto_port_count > 0) {
          applyPort.value = auto_port_count;
          autoPortMatched.value = true;
          matched = true;
        } else {
          autoPortMatched.value = false;
        }
        if (auto_category_id > 0) {
          applyCategoryId.value = auto_category_id;
          autoCatMatched.value = true;
          matched = true;
        } else {
          autoCatMatched.value = false;
        }
        autoConfigured.value = matched;
      } else {
        autoConfigured.value = false;
        autoCatMatched.value = false;
        autoPortMatched.value = false;
      }

      if (applied) {
        reapplyWarning.value = `⚠️ 提示：工单【${wo}】此前已申请过 MAC 地址！重新申请需填写原因。`;
        showReapplyReason.value = true;
      } else {
        reapplyWarning.value = '';
        showReapplyReason.value = false;
      }
    }
  } catch (e) {}
};

const submitApply = async () => {
  const wo = applyWorkOrder.value.trim();
  if (!wo) return ElMessage.warning('请输入工单号');
  if (showReapplyReason.value && !reapplyReason.value.trim()) {
    return ElMessage.warning('请填写重复申请原因！');
  }

  submittingApply.value = true;
  try {
    const res = await request.post('/mac/apply_mac', {
      work_order: wo,
      category_id: applyCategoryId.value,
      order_quantity: applyQty.value,
      port_count: applyPort.value,
      is_reapplied: showReapplyReason.value ? 1 : 0,
      reapply_reason: reapplyReason.value.trim(),
      task_code: currentWoInfo.value?.code || '',
      description: currentWoInfo.value?.description || ''
    });
    ElMessage.success(res.message || '申请分配成功');
    applyWorkOrder.value = '';
    currentWoInfo.value = null;
    showReapplyReason.value = false;
    reapplyReason.value = '';
    if (macPerms.can_view_records) switchTab('records');
  } finally {
    submittingApply.value = false;
  }
};

// 2. 网口配置
const cfgProdCode = ref('');
const cfgProdDesc = ref('');
const cfgPortCount = ref(1);
const cfgCategoryId = ref(0);
const portSearchKw = ref('');
const portConfigList = ref([]);

const queryProductDesc = async () => {
  const code = cfgProdCode.value.trim();
  if (!code) return ElMessage.warning('请输入产品代码');
  try {
    const res = await request.post('/mac/get_product_desc', { product_code: code });
    if (res.data) {
      cfgProdDesc.value = res.data.description || '';
      if (res.data.port_count > 0) cfgPortCount.value = res.data.port_count;
      if (res.data.category_id > 0) cfgCategoryId.value = res.data.category_id;
    }
  } catch (e) {}
};

const savePortConfig = async () => {
  if (!cfgProdCode.value.trim()) return ElMessage.warning('请输入产品代码');
  if (cfgCategoryId.value <= 0) return ElMessage.warning('请选择产品类别');

  try {
    const res = await request.post('/mac/save_product_port', {
      product_code: cfgProdCode.value.trim(),
      description: cfgProdDesc.value.trim(),
      port_count: cfgPortCount.value,
      category_id: cfgCategoryId.value
    });
    ElMessage.success(res.message || '保存成功');
    cfgProdCode.value = '';
    cfgProdDesc.value = '';
    cfgPortCount.value = 1;
    cfgCategoryId.value = 0;
    loadPortConfigs();
  } catch (e) {}
};

const loadPortConfigs = async () => {
  try {
    const res = await request.get('/mac/list_product_ports', {
      params: { search: portSearchKw.value.trim() }
    });
    portConfigList.value = res.data?.list || [];
  } catch (e) {}
};

const editPortItem = (row) => {
  cfgProdCode.value = row.product_code;
  cfgProdDesc.value = row.description || '';
  cfgPortCount.value = row.port_count;
  cfgCategoryId.value = row.category_id || 0;
};

const deletePortItem = (id) => {
  ElMessageBox.confirm('确定删除该产品的网口及类别配置吗？', '提示', { type: 'warning' }).then(async () => {
    await request.post('/mac/delete_product_port', { id });
    ElMessage.success('已删除');
    loadPortConfigs();
  });
};

// 3. 批量录入
const importCategoryId = ref(null);
const importStartMac = ref('');
const importEndMac = ref('');
const importQuantity = ref(null);
const importRemarks = ref('');

const submitImportMac = async () => {
  if (!importStartMac.value.trim()) return ElMessage.warning('请输入起始 MAC 地址');
  try {
    const res = await request.post('/mac/import_mac', {
      category_id: importCategoryId.value,
      start_mac: importStartMac.value.trim(),
      end_mac: importEndMac.value.trim(),
      quantity: importQuantity.value,
      remarks: importRemarks.value.trim()
    });
    ElMessage.success(res.message || '录入成功');
    importStartMac.value = '';
    importEndMac.value = '';
    importQuantity.value = null;
    importRemarks.value = '';
    if (macPerms.can_view_stats) switchTab('stats');
  } catch (e) {}
};

// 4. 申请记录
const recordSearchKw = ref('');
const recordList = ref([]);
const totalRecords = ref(0);
const curRecordPage = ref(1);
const totalRecordPages = ref(1);
const selectedRecIds = ref([]);
const allRecSelected = ref(false);

const loadRecords = async (p = 1) => {
  curRecordPage.value = p;
  try {
    const res = await request.get('/mac/get_records', {
      params: { page: p, search: recordSearchKw.value.trim() }
    });
    if (res.data) {
      recordList.value = res.data.records || [];
      totalRecords.value = res.data.total_records || 0;
      totalRecordPages.value = res.data.total_pages || 1;
      selectedRecIds.value = [];
      allRecSelected.value = false;
    }
  } catch (e) {}
};

const toggleSelectAllRec = () => {
  if (allRecSelected.value) {
    selectedRecIds.value = recordList.value.map(r => r.id);
  } else {
    selectedRecIds.value = [];
  }
};

const batchDeleteRecords = () => {
  if (selectedRecIds.value.length === 0) return ElMessage.warning('请勾选要删除的记录');
  ElMessageBox.confirm(`确定删除选中的 ${selectedRecIds.value.length} 条记录并释放 MAC 地址回池吗？`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/mac/delete_records', { ids: selectedRecIds.value });
    ElMessage.success(res.message || '删除成功');
    loadRecords(curRecordPage.value);
  });
};

const detailModalVisible = ref(false);
const currentDetailRecord = ref(null);
const detailList = ref([]);

const openDetailModal = async (id) => {
  detailModalVisible.value = true;
  try {
    const res = await request.get('/mac/get_record_details', { params: { record_id: id } });
    if (res.data) {
      currentDetailRecord.value = res.data.record;
      detailList.value = res.data.details || [];
    }
  } catch (e) {}
};

const exportDetailCsv = (recordId) => {
  window.open(`/api/mac/export_details?record_id=${recordId}`, '_blank');
};

// 5. 看板统计
const statsList = ref([]);
const loadStats = async () => {
  try {
    const res = await request.get('/mac/get_stats');
    statsList.value = res.data?.stats || [];
  } catch (e) {}
};

// 6. 地址池
const poolCatFilter = ref(0);
const poolStatusFilter = ref(-1);
const poolSearchKw = ref('');
const poolList = ref([]);
const totalPool = ref(0);
const curPoolPage = ref(1);
const totalPoolPages = ref(1);

const loadPool = async (p = 1) => {
  curPoolPage.value = p;
  try {
    const res = await request.get('/mac/get_pool', {
      params: {
        page: p,
        category_id: poolCatFilter.value,
        status: poolStatusFilter.value,
        search: poolSearchKw.value.trim()
      }
    });
    if (res.data) {
      poolList.value = res.data.list || [];
      totalPool.value = res.data.total || 0;
      totalPoolPages.value = res.data.total_pages || 1;
    }
  } catch (e) {}
};

// 7. 录入日志
const logList = ref([]);
const totalLogs = ref(0);
const curLogPage = ref(1);
const totalLogPages = ref(1);

const loadLogs = async (p = 1) => {
  curLogPage.value = p;
  try {
    const res = await request.get('/mac/get_import_logs', { params: { page: p } });
    if (res.data) {
      logList.value = res.data.logs || [];
      totalLogs.value = res.data.total || 0;
      totalLogPages.value = res.data.total_pages || 1;
    }
  } catch (e) {}
};

const switchTab = (tab) => {
  activeTab.value = tab;
  if (tab === 'ports') loadPortConfigs();
  else if (tab === 'records') loadRecords(1);
  else if (tab === 'stats') loadStats();
  else if (tab === 'pool') loadPool(1);
  else if (tab === 'logs') loadLogs(1);
};

onMounted(async () => {
  const res = await request.get('/mac/init');
  if (res.data) {
    categories.value = res.data.categories || [];
    Object.assign(macPerms, res.data.mac_perms || {});
    isAdmin.value = !!res.data.is_admin;
    if (categories.value.length > 0) {
      applyCategoryId.value = categories.value[0].id;
      importCategoryId.value = categories.value[0].id;
    }
  }
});
</script>

<style scoped>
.mac-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.mac-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
}
.card-bar {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.mac-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  border-bottom: 2px solid #dee2e6;
  flex-wrap: wrap;
}
.mac-tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
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
.mac-tab-btn.active {
  color: #007bff;
  border-bottom-color: #007bff;
}

.mac-info-box {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 12px;
  background: #f0f7ff;
  border: 1px solid #d0e3ff;
  border-radius: 4px;
  padding: 6px 14px;
  font-size: 12.5px;
  color: #2c3e50;
  min-width: 280px;
}

.stats-cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 12px;
}
.stat-mac-card {
  background: white;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  padding: 14px 18px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}

.mac-table-wrapper {
  width: 100%;
  overflow-x: auto;
  max-height: calc(100vh - 350px);
  min-height: 280px;
  border-top: 1px solid #dee2e6;
}
.mac-fixed-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 12.5px;
  text-align: center;
  color: #333;
}
.mac-fixed-table thead th {
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
.mac-fixed-table tbody td {
  padding: 6px 5px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  white-space: nowrap;
  vertical-align: middle;
}
.mac-fixed-table tbody tr:hover {
  background-color: #f8fafc;
}
.cell-truncate {
  display: inline-block;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mac-pagination-bar {
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

.mac-input {
  height: 32px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
}
.mac-input:focus { border-color: #80bdff; }

.mac-select {
  height: 32px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 8px;
  outline: none;
  background: #fff;
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
.btn-mini { padding: 2px 6px; font-size: 11px; }
.mr-1 { margin-right: 4px; }
</style>