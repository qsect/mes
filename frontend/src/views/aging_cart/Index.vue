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
        <el-button v-if="canEditCart" size="small" @click="openTotal">
          <el-icon class="mr-1"><Setting /></el-icon> 车辆总数：{{ cartTotal }}
        </el-button>
        <el-button type="primary" size="small" @click="openReport()">
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
        <div class="ac-stat-sub">
          待修 {{ board.pending_count || 0 }} · 维修中 {{ board.repairing_count || 0 }}
          <template v-if="board.manual_fault"> · 手动标记 {{ board.manual_fault }}</template>
        </div>
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
        <div class="ac-stat-label">本月平均修复跨度</div>
        <div class="ac-stat-sub">跨度累计 {{ board.month_minutes_text || '0 分钟' }}</div>
      </div>
      <div class="ac-stat" style="border-left-color:#722ed1;">
        <div class="ac-stat-value" style="color:#722ed1;">{{ board.month_work_text || '0 分钟' }}</div>
        <div class="ac-stat-label">本月投入工时（人力）</div>
        <div class="ac-stat-sub">
          多人协作 {{ board.month_collab || 0 }} 单
          <el-tooltip
            content="投入工时 = 各维修人有效时长之和（多人重叠各计各的）；修复跨度 = 从开工到收工的自然经过"
            placement="top"
          >
            <el-icon style="color:#909399;"><QuestionFilled /></el-icon>
          </el-tooltip>
        </div>
      </div>
    </div>

    <!-- 统计周期 -->
    <div class="ac-card ac-period">
      <div class="ac-period-left">
        <el-icon><Calendar /></el-icon>
        <b>统计期间：</b>{{ periodLabel }}
        <span class="ac-period-range">（{{ periodStart }} ~ {{ periodEnd }}）</span>
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
            <el-table-column label="故障现象" min-width="200" show-overflow-tooltip>
              <template #default="{ row }">
                <span v-if="row.fault_desc">{{ row.fault_desc }}</span>
                <span v-else style="color:#c0c4cc;">{{ row.fault_type || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="status" label="状态" width="90" align="center">
              <template #default="{ row }">
                <el-tag :type="statusTagType(row.status)" size="small">{{ statusText(row.status) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="reporter_name" label="报修人" width="100" show-overflow-tooltip />
            <el-table-column prop="reported_at" label="报修时间" width="150" />
            <el-table-column label="参与维修人" min-width="210">
              <template #default="{ row }">
                <div v-if="row.workers && row.workers.length" class="ac-workers">
                  <el-tooltip
                    v-for="w in row.workers"
                    :key="w.worker_id"
                    placement="top"
                    :content="`${w.user_name}：${w.joined_at} 加入${w.left_at ? '，' + w.left_at + ' 离开' : '（仍在维修）'}`"
                  >
                    <span class="ac-worker" :class="w.working ? 'ac-worker-on' : ''">
                      <el-icon class="ac-worker-ico"><User /></el-icon>
                      <span class="ac-worker-name">{{ w.user_name }}</span>
                      <b class="ac-worker-time">{{ w.duration_text }}</b>
                      <span v-if="w.is_starter" class="ac-worker-star">开工</span>
                    </span>
                  </el-tooltip>
                  <el-tag v-if="row.is_multi_worker" size="small" type="warning" effect="plain">
                    {{ row.worker_count }}人协作
                  </el-tag>
                  <span v-if="row.status === 'repairing' && row.active_worker_count === 0" class="ac-worker-idle">
                    当前无人维修
                  </span>
                </div>
                <span v-else style="color:#c0c4cc;">-</span>
              </template>
            </el-table-column>
            <el-table-column label="维修时长" width="200">
              <template #default="{ row }">
                <template v-if="row.status === 'repairing'">
                  <div style="color:#409EFF; font-weight:600;">
                    <el-icon><Timer /></el-icon> 跨度 {{ row.elapsed_text }}
                  </div>
                  <div class="ac-raw">
                    投入 {{ row.work_text }}
                    <span v-if="row.is_multi_worker">（{{ row.worker_count }}人合计）</span>
                  </div>
                </template>
                <template v-else-if="row.status === 'done'">
                  <div><b>{{ row.duration_text }}</b> <span class="ac-raw">跨度</span></div>
                  <div class="ac-raw">
                    投入 {{ row.work_text }}
                    <span v-if="row.is_multi_worker">（{{ row.worker_count }}人合计）</span>
                  </div>
                </template>
                <span v-else style="color:#c0c4cc;">-</span>
              </template>
            </el-table-column>
            <el-table-column prop="repair_remark" label="维修备注" min-width="160" show-overflow-tooltip>
              <template #default="{ row }">
                <span>{{ row.repair_remark || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="230" fixed="right" align="center">
              <template #default="{ row }">
                <el-button
                  v-if="row.status === 'pending'"
                  type="primary"
                  size="small"
                  link
                  @click="joinRepair(row)"
                >开始维修</el-button>
                <template v-else-if="row.status === 'repairing'">
                  <el-button
                    v-if="!row.me_joined"
                    type="primary"
                    size="small"
                    link
                    @click="joinRepair(row)"
                  >参与维修</el-button>
                  <el-button
                    v-else
                    size="small"
                    link
                    @click="leaveRepair(row)"
                  >我已完成</el-button>
                  <el-button type="success" size="small" link @click="openFinish(row)">收工</el-button>
                </template>
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

      <!-- ============ 老化车列表 ============ -->
      <el-tab-pane :label="`老化车列表(${cartTotal})`" name="carts">
        <!-- 状态看板：一个编号一个方块，正常绿 / 故障红；与下方列表共享 carts 数据，天然联动 -->
        <div class="ac-card">
          <div class="ac-board-head">
            <span class="ac-board-title">状态看板</span>
            <span class="ac-legend">
              <span><i class="ac-dot" style="background:#67c23a;"></i>正常 {{ normalCartCount }}</span>
              <span><i class="ac-dot" style="background:#f56c6c;"></i>故障 {{ faultCartCount }}</span>
            </span>
            <span class="ac-legend-tip">点击方块可只看这辆车，再点一次取消</span>
          </div>
          <div class="ac-grid" v-loading="loadingCarts">
            <div
              v-for="c in carts"
              :key="c.cart_no"
              class="ac-cell"
              :class="[
                isFault(c) ? 'ac-cell-fault' : 'ac-cell-normal',
                cartFilter.cart_no === c.cart_no ? 'ac-cell-active' : ''
              ]"
              :title="cellTitle(c)"
              @click="toggleFocusCart(c.cart_no)"
            >
              <div class="ac-cell-no">{{ c.cart_no }}</div>
              <div class="ac-cell-layers" v-if="c.layers">{{ c.layers }}层</div>
            </div>
          </div>
        </div>

        <div class="ac-card">
          <div class="ac-filter">
            <el-select v-model="cartFilter.status" placeholder="状态" clearable size="small" style="width:110px;">
              <el-option label="正常" value="normal" />
              <el-option label="故障" value="fault" />
            </el-select>
            <el-select
              v-model="cartFilter.usage_type"
              placeholder="使用位置"
              clearable
              size="small"
              style="width:120px;"
            >
              <el-option v-for="u in usageTypes" :key="u" :label="u" :value="u" />
            </el-select>
            <el-select v-model="cartFilter.layers" placeholder="层数" clearable size="small" style="width:100px;">
              <el-option v-for="n in layerOptions" :key="n" :label="`${n} 层`" :value="n" />
            </el-select>
            <el-input
              v-model="cartFilter.keyword"
              placeholder="备注 / 故障说明关键词"
              clearable
              size="small"
              style="width:210px;"
            />
            <el-button size="small" @click="resetCartFilter">重置</el-button>
            <el-tag
              v-if="cartFilter.cart_no"
              closable
              size="small"
              type="primary"
              effect="plain"
              @close="cartFilter.cart_no = ''"
            >只看 {{ cartFilter.cart_no }}</el-tag>
            <div style="flex:1;"></div>
            <span class="ac-legend-tip">
              <template v-if="canEditCart">列表默认只读，点操作列「编辑」后才能修改</template>
              <template v-else>车辆档案仅超级管理员和班长可维护</template>
            </span>
          </div>

          <el-table
            v-loading="loadingCarts"
            :data="filteredCarts"
            size="small"
            border
            stripe
            height="560"
            style="width:100%;"
            empty-text="没有符合条件的老化车"
          >
            <el-table-column prop="cart_no" label="编号" width="80" align="center" fixed>
              <template #default="{ row }">
                <b style="font-family:monospace;">{{ row.cart_no }}</b>
              </template>
            </el-table-column>

            <el-table-column label="层数" width="105" align="center">
              <template #default="{ row }">
                <el-input-number
                  v-if="isEditing(row)"
                  v-model="row.layers"
                  :min="1"
                  :max="99"
                  :precision="0"
                  :controls="false"
                  size="small"
                  style="width:78px;"
                  placeholder="-"
                />
                <span v-else :style="row.layers ? '' : 'color:#c0c4cc;'">{{ row.layers || '-' }}</span>
              </template>
            </el-table-column>

            <el-table-column label="状态" width="140" align="center">
              <template #default="{ row }">
                <el-select
                  v-if="isEditing(row) && statusEditable(row)"
                  v-model="row.status"
                  size="small"
                  style="width:110px;"
                >
                  <el-option label="正常" value="normal" />
                  <el-option label="故障" value="fault" />
                </el-select>
                <el-tooltip
                  v-else-if="isFault(row)"
                  :content="`已生成报修单（${statusText(row.status)}），完成维修后状态自动恢复为正常`"
                  placement="top"
                >
                  <el-tag type="danger" size="small">{{ faultTagText(row) }}</el-tag>
                </el-tooltip>
                <el-tag v-else type="success" size="small">正常</el-tag>
              </template>
            </el-table-column>

            <el-table-column label="故障说明" min-width="210" show-overflow-tooltip>
              <template #default="{ row }">
                <span v-if="isFault(row)" style="color:#f56c6c;">{{ faultText(row) }}</span>
                <span v-else style="color:#c0c4cc;">-</span>
              </template>
            </el-table-column>

            <el-table-column label="使用位置" width="130" align="center">
              <template #default="{ row }">
                <el-select
                  v-if="isEditing(row)"
                  v-model="row.usage_type"
                  size="small"
                  style="width:110px;"
                >
                  <el-option v-for="u in usageTypes" :key="u" :label="u" :value="u" />
                </el-select>
                <span v-else>{{ row.usage_type || '-' }}</span>
              </template>
            </el-table-column>

            <el-table-column label="备注" min-width="190">
              <template #default="{ row }">
                <el-input
                  v-if="isEditing(row)"
                  v-model="row.remark"
                  size="small"
                  maxlength="255"
                  placeholder="请填写备注"
                />
                <span v-else :style="row.remark ? '' : 'color:#c0c4cc;'">{{ row.remark || '-' }}</span>
              </template>
            </el-table-column>

            <el-table-column label="累计报修" width="90" align="center">
              <template #default="{ row }">
                <span :style="row.repair_times > 0 ? 'color:#e6a23c;font-weight:600;' : 'color:#c0c4cc;'">
                  {{ row.repair_times || 0 }}
                </span>
              </template>
            </el-table-column>

            <el-table-column label="操作" width="180" fixed="right" align="center">
              <template #default="{ row }">
                <!-- 编辑态：保存 / 取消（同一时刻只允许编辑一行） -->
                <template v-if="isEditing(row)">
                  <el-button
                    type="success"
                    size="small"
                    link
                    :loading="saving"
                    @click="saveEditCart(row)"
                  >保存</el-button>
                  <el-button size="small" link @click="cancelEditCart(row)">取消</el-button>
                </template>
                <!-- 只读态：有权限才显示「编辑」 -->
                <template v-else>
                  <el-button
                    v-if="canEditCart"
                    type="warning"
                    size="small"
                    link
                    @click="beginEditCart(row)"
                  >编辑</el-button>
                  <el-button
                    type="primary"
                    size="small"
                    link
                    :disabled="isFault(row)"
                    @click="openReport(row.cart_no)"
                  >{{ isFault(row) ? '已报修' : '报修' }}</el-button>
                  <el-button size="small" link @click="filterByCart(row.cart_no)">记录</el-button>
                </template>
              </template>
            </el-table-column>
          </el-table>
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
              <el-table-column label="维修人" width="130" show-overflow-tooltip>
                <template #default="{ row }">
                  {{ row.worker_names || row.repairer_name || '-' }}
                  <el-tag v-if="row.is_multi_worker" size="small" type="warning" effect="plain">
                    {{ row.worker_count }}人
                  </el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="duration_text" label="修复跨度" width="110" />
              <el-table-column label="投入工时" width="110">
                <template #default="{ row }">
                  <b>{{ row.work_text || '-' }}</b>
                </template>
              </el-table-column>
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
        <el-form-item label="故障类型" required>
          <el-select v-model="reportForm.fault_type" placeholder="请选择故障类型" style="width:100%;">
            <el-option v-for="t in faultTypes" :key="t" :label="t" :value="t" />
          </el-select>
        </el-form-item>
        <el-form-item label="故障现象" :required="descRequired(reportForm.fault_type)">
          <el-input
            v-model="reportForm.fault_desc"
            type="textarea"
            :rows="3"
            maxlength="500"
            show-word-limit
            :placeholder="descPlaceholder(reportForm.fault_type)"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="reportVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitReport">提交报修</el-button>
      </template>
    </el-dialog>

    <!-- 调整车辆总数对话框（超级管理员 / 班长） -->
    <el-dialog v-model="totalVisible" title="调整老化车总数" width="500px" destroy-on-close>
      <el-form label-width="110px" size="small">
        <el-form-item label="当前总数">
          <b>{{ cartTotal }}</b> 辆
          <span style="margin-left:8px; color:#909399; font-size:12px;">编号 001 - {{ maxCartNo }}</span>
        </el-form-item>
        <el-form-item label="调整为" required>
          <el-input-number
            v-model="totalForm.cart_total"
            :min="1"
            :max="999"
            :precision="0"
            controls-position="right"
            style="width:160px;"
          />
        </el-form-item>
        <div class="ac-tip" v-if="totalForm.cart_total < cartTotal">
          调小后将移除编号 <b>{{ nextCartNo }}</b> 之后的车辆，列表与看板同步减少；
          它们的历史报修记录仍保留在「报修记录」中。<br>
          若这些车有未完成的报修/维修单，提交时会被拒绝并列出具体车号。
        </div>
        <div class="ac-tip" v-else-if="totalForm.cart_total > cartTotal">
          调大后会自动补建新编号的车辆（{{ maxCartNo }} → {{ newMaxCartNo }}），新车辆默认「流动使用」。
        </div>
      </el-form>
      <template #footer>
        <el-button @click="totalVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitTotal">保存</el-button>
      </template>
    </el-dialog>

    <!-- 完成维修（收工）对话框 -->
    <el-dialog v-model="finishVisible" title="完成维修（收工）" width="600px" destroy-on-close>
      <div v-if="finishRow" class="ac-finish-info">
        <div><b>{{ finishRow.cart_no }}</b> · {{ finishRow.fault_type || '未分类' }}</div>
        <div class="ac-finish-desc">{{ finishRow.fault_desc }}</div>
        <div class="ac-finish-time">
          开始：{{ finishRow.repair_started_at }}
          <el-divider direction="vertical" />
          跨度：<b style="color:#409EFF;">{{ finishRow.elapsed_text || '计算中' }}</b>
        </div>

        <!-- 参与人结算：收工会一并结束所有仍在修的人的计时 -->
        <div class="ac-finish-workers">
          <div class="ac-finish-workers-title">
            参与维修人员
            <el-tag v-if="finishRow.is_multi_worker" size="small" type="warning" effect="plain">
              多人协作 {{ finishRow.worker_count }} 人
            </el-tag>
          </div>
          <el-table v-if="finishRow.workers && finishRow.workers.length" :data="finishRow.workers" size="small" border>
            <el-table-column label="维修人" min-width="90">
              <template #default="{ row }">
                {{ row.user_name }}
                <span v-if="row.is_starter" class="ac-worker-star">开工</span>
              </template>
            </el-table-column>
            <el-table-column prop="joined_at" label="加入时间" width="150" />
            <el-table-column label="状态" width="90" align="center">
              <template #default="{ row }">
                <el-tag :type="row.working ? 'success' : 'info'" size="small">
                  {{ row.working ? '在修中' : '已离开' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="个人工时" width="110" align="right">
              <template #default="{ row }"><b>{{ row.duration_text }}</b></template>
            </el-table-column>
          </el-table>
          <div v-else style="color:#909399; font-size:12px;">暂无参与人记录</div>
          <div class="ac-finish-sum">
            投入工时合计：<b>{{ finishRow.work_text }}</b>
            <span class="ac-raw">（修复跨度 {{ finishRow.elapsed_text }}，重叠部分各计各的）</span>
          </div>

          <!-- 收工会一并结束所有人的计时，这里显式列出受影响的人，避免误点 -->
          <el-alert
            v-if="activeWorkers(finishRow).length"
            class="ac-finish-alert"
            type="warning"
            show-icon
            :closable="false"
            :title="`还有 ${activeWorkers(finishRow).length} 人正在维修`"
            :description="`${activeWorkers(finishRow).map((w) => w.user_name).join('、')} 目前仍在计时，点「确认收工」会同时结束他们的维修计时，工单转为已修复。`"
          />
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
      <div class="ac-tip">
        收工后系统会按规则扣除不计入的时段；仍在维修的人员一并结束计时，工单转为已修复、车辆状态恢复为正常。
      </div>
      <template #footer>
        <el-button @click="finishVisible = false">取消</el-button>
        <el-button type="success" :loading="saving" @click="submitFinish">确认收工</el-button>
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
        <el-form-item label="故障类型" required>
          <el-select v-model="editForm.fault_type" placeholder="请选择故障类型" style="width:100%;">
            <el-option v-for="t in faultTypes" :key="t" :label="t" :value="t" />
          </el-select>
        </el-form-item>
        <el-form-item label="故障现象" :required="descRequired(editForm.fault_type)">
          <el-input
            v-model="editForm.fault_desc"
            type="textarea"
            :rows="3"
            maxlength="500"
            show-word-limit
            :placeholder="descPlaceholder(editForm.fault_type)"
          />
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
import { Tools, Plus, Refresh, Search, Download, Timer, Calendar, Setting, User, QuestionFilled } from '@element-plus/icons-vue';
import request from '@/api/request';

const activeTab = ref('records');
const loadingBoard = ref(false);
const loadingList = ref(false);
const loadingCarts = ref(false);
const saving = ref(false);

const cartTotal = ref(140);
const faultTypes = ref([]);
/** 这些故障类型必须填写故障现象（由后端 init 下发，当前为「其他」） */
const descRequiredTypes = ref([]);
const usageTypes = ref(['固定使用', '流动使用']);
const canManage = ref(false);
/** 档案维护权限：仅超级管理员 + 班长（由后端下发，前端只做按钮级隐藏） */
const canEditCart = ref(false);

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

/** 老化车列表页的筛选条件 */
const cartFilter = reactive({
  status: '',
  usage_type: '',
  layers: '',
  cart_no: '',
  keyword: ''
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

/** 列表页筛选后的老化车 */
const filteredCarts = computed(() => {
  const kw = String(cartFilter.keyword || '').trim().toLowerCase();
  return carts.value.filter((c) => {
    if (cartFilter.cart_no && c.cart_no !== cartFilter.cart_no) return false;
    if (cartFilter.status === 'normal' && isFault(c)) return false;
    if (cartFilter.status === 'fault' && !isFault(c)) return false;
    if (cartFilter.usage_type && c.usage_type !== cartFilter.usage_type) return false;
    if (cartFilter.layers !== '' && cartFilter.layers !== null
        && Number(c.layers || 0) !== Number(cartFilter.layers)) return false;
    if (kw) {
      const hay = `${c.cart_no} ${c.remark || ''} ${faultText(c)} ${c.usage_type || ''}`.toLowerCase();
      if (!hay.includes(kw)) return false;
    }
    return true;
  });
});

/** 层数筛选项取自已有数据，避免硬编码一堆用不到的层数 */
const layerOptions = computed(() => {
  const s = new Set();
  carts.value.forEach((c) => {
    if (c.layers) s.add(Number(c.layers));
  });
  return Array.from(s).sort((a, b) => a - b);
});

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
// ---- 老化车列表：状态按「正常 / 故障」两态呈现 ----
// 后端实际存 normal(正常) / pending(待维修) / repairing(维修中) / fault(管理员手动标记)，
// 除 normal 以外一律视为「故障」；修好后后端会把状态写回 normal，列表里的故障说明随之消失。
function isFault(row) {
  return !!row && row.status !== 'normal';
}

/** 故障说明：仅故障态展示；正常态返回空串，列表上显示为「-」 */
function faultText(row) {
  if (!isFault(row)) return '';
  return row.fault_text || '（未填写，直接标记为故障）';
}

/** 故障态且不可手动改写时（已有报修单）的标签文案 */
function faultTagText(row) {
  if (row.status === 'repairing') return '故障 · 维修中';
  if (row.status === 'pending') return '故障 · 待维修';
  return '故障';
}

/** 状态能否被手动改写：存在未完成报修单时不可改（必须走「完成维修」） */
function statusEditable(row) {
  return row.status === 'normal' || row.status === 'fault';
}

// ---- 行编辑态 ----
// 列表默认只读；点操作列「编辑」后该行才可改。同一时刻只允许编辑一行 ——
// 用对象引用（而非 cart_no）判定，避免编号重复时错行。
const editingRow = ref(null);
/** 进入编辑前的行数据快照，用于「取消」或保存失败时回滚 */
let cartSnapshot = null;

function isEditing(row) {
  return editingRow.value === row;
}

function beginEditCart(row) {
  if (editingRow.value && editingRow.value !== row) {
    cancelEditCart(editingRow.value);
  }
  cartSnapshot = {
    layers: row.layers,
    status: row.status,
    usage_type: row.usage_type,
    remark: row.remark
  };
  editingRow.value = row;
}

function cancelEditCart(row) {
  if (cartSnapshot) {
    Object.assign(row, cartSnapshot);
  }
  cartSnapshot = null;
  editingRow.value = null;
}

async function saveEditCart(row) {
  saving.value = true;
  try {
    await request.post('/aging_cart/save_cart', {
      cart_no: row.cart_no,
      layers: row.layers,
      status: row.status,
      usage_type: row.usage_type,
      remark: row.remark
    });
    ElMessage.success(`${row.cart_no} 已保存`);
    editingRow.value = null;
    cartSnapshot = null;
    refreshAll();
  } catch (e) {
    // 失败：回滚到快照，保留在编辑态让用户修正后重试
    if (cartSnapshot) {
      Object.assign(row, cartSnapshot);
    }
  } finally {
    saving.value = false;
  }
}

// ---- 故障现象的条件必填：仅「其他」需要填写 ----
function descRequired(faultType) {
  return descRequiredTypes.value.includes(faultType);
}

function descPlaceholder(faultType) {
  if (faultType === '其他') {
    return '请详细描述故障现象（选择「其他」时必填）';
  }
  if (!faultType) {
    return '请先选择故障类型';
  }
  return '可补充说明具体情况，也可以留空';
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
    descRequiredTypes.value = d.fault_desc_required || [];
    if (Array.isArray(d.usage_types) && d.usage_types.length) {
      usageTypes.value = d.usage_types;
    }
    if (typeof d.can_edit_cart === 'boolean') {
      canEditCart.value = d.can_edit_cart;
    }
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
    const d = res.data || {};
    carts.value = (d.list || []).map((c) => ({
      ...c,
      usage_type: c.usage_type || '流动使用'
    }));
    if (Array.isArray(d.usage_types) && d.usage_types.length) {
      usageTypes.value = d.usage_types;
    }
    if (typeof d.can_edit_cart === 'boolean') {
      canEditCart.value = d.can_edit_cart;
    }
    // ★ 不在这里用列表行数回写 cartTotal：总数是配置项（aging_settings），
    //   由 init/set_cart_total 下发。若按行数回写，脏数据或请求竞态会让总数显示不可靠。
    if (carts.value.length && cartTotal.value < carts.value.length) {
      cartTotal.value = carts.value.length;
    }
    // 列表数据被整体替换后，旧的编辑行引用失效，直接退出编辑态
    editingRow.value = null;
    cartSnapshot = null;
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

function resetCartFilter() {
  cartFilter.status = '';
  cartFilter.usage_type = '';
  cartFilter.layers = '';
  cartFilter.cart_no = '';
  cartFilter.keyword = '';
}

/** 点击看板方块：只看这辆车；再点一次取消 */
function toggleFocusCart(cartNo) {
  cartFilter.cart_no = cartFilter.cart_no === cartNo ? '' : cartNo;
}

/** 看板方块的悬浮提示，与列表同一份数据 */
function cellTitle(c) {
  const parts = [`${c.cart_no}（${isFault(c) ? '故障' : '正常'}）`];
  if (c.layers) parts.push(`${c.layers} 层`);
  parts.push(c.usage_type || '流动使用');
  if (isFault(c)) parts.push(`故障：${faultText(c)}`);
  if (c.repair_times) parts.push(`累计报修 ${c.repair_times} 次`);
  if (c.last_fixed_at) parts.push(`最近修复 ${c.last_fixed_at}`);
  return parts.join(' | ');
}

/** 看板统计：与列表口径一致（非 normal 即故障） */
const faultCartCount = computed(() => carts.value.filter((c) => isFault(c)).length);
const normalCartCount = computed(() => carts.value.length - faultCartCount.value);

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

function openReport(cartNo) {
  reportForm.cart_no = cartNo || '';
  reportForm.fault_type = '';
  reportForm.fault_desc = '';
  reportVisible.value = true;
}

async function submitReport() {
  if (!reportForm.cart_no) {
    ElMessage.warning('请选择老化车编号');
    return;
  }
  if (!reportForm.fault_type) {
    ElMessage.warning('请选择故障类型');
    return;
  }
  if (descRequired(reportForm.fault_type) && !reportForm.fault_desc.trim()) {
    ElMessage.warning('故障类型为「其他」时，必须填写故障现象');
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

// ---------- 调整车辆总数（超级管理员 / 班长） ----------

const totalVisible = ref(false);
const totalForm = reactive({ cart_total: 140 });

/** 调小后第一个被移除的编号（用于提示影响范围） */
const nextCartNo = computed(() => {
  const n = Number(totalForm.cart_total) || 0;
  if (n < 1 || n >= cartTotal.value) return '';
  return String(n + 1).padStart(3, '0');
});

const newMaxCartNo = computed(() => {
  const n = Number(totalForm.cart_total) || 0;
  return n > 0 ? String(n).padStart(3, '0') : '-';
});

function openTotal() {
  totalForm.cart_total = cartTotal.value;
  totalVisible.value = true;
}

async function submitTotal() {
  const n = Number(totalForm.cart_total) || 0;
  if (n < 1 || n > 999) {
    ElMessage.warning('老化车总数应在 1 ~ 999 之间');
    return;
  }
  if (n === cartTotal.value) {
    ElMessage.info('总数未变化');
    totalVisible.value = false;
    return;
  }

  // 调小是「移除车辆」的破坏性操作，二次确认并明确列出影响范围
  if (n < cartTotal.value) {
    try {
      await ElMessageBox.confirm(
        `确认把老化车总数从 ${cartTotal.value} 辆调整为 ${n} 辆？\n\n`
        + `编号 ${String(n + 1).padStart(3, '0')} - ${maxCartNo.value} 的车辆将从列表和看板中移除。\n`
        + '它们的历史报修记录仍会保留在「报修记录」中。',
        '调整总数确认',
        { type: 'warning', confirmButtonText: '确认调整', cancelButtonText: '取消' }
      );
    } catch (e) {
      return;
    }
  }

  saving.value = true;
  try {
    const res = await request.post('/aging_cart/set_cart_total', { cart_total: n });
    const d = (res && res.data) || {};
    if (d.cart_total) {
      cartTotal.value = d.cart_total;
    }
    ElMessage.success(`老化车总数已更新为 ${cartTotal.value} 辆`);
    totalVisible.value = false;
    refreshAll();
  } catch (e) {
    /* 拦截器已提示 */
  } finally {
    saving.value = false;
  }
}

// ---------- 参与维修（多人同时维修）----------

/**
 * 加入维修。
 * - 工单待维修 → 本人是开工者，开始计工单跨度；
 * - 工单维修中 → 本人是协作人，从第 2 人起即为多人同时维修，各计各的工时；
 * - 已在参与中 → 后端幂等返回成功，防连点产生重复计时。
 */
async function joinRepair(row) {
  const isStart = row.status === 'pending';
  const title = isStart ? '开始维修' : '参与维修';
  try {
    await ElMessageBox.confirm(
      isStart
        ? `确认开始维修 ${row.cart_no}？计时将立即开始。`
        : `确认参与维修 ${row.cart_no}？\n\n当前已有 ${row.active_worker_count || 0} 人在维修，你的工时将独立计时，互不影响。`,
      title,
      { type: 'warning', confirmButtonText: isStart ? '开始' : '加入', cancelButtonText: '取消' }
    );
  } catch (e) {
    return;
  }
  try {
    const res = await request.post('/aging_cart/join', { id: row.id });
    const d = (res && res.data) || {};
    ElMessage.success(d.is_starter ? '已开始维修，计时开始' : '已加入维修，你的工时独立计算');
    refreshAll();
  } catch (e) {
    /* 拦截器已提示 */
  }
}

/** 仍在维修未结账的参与人：收工会把他们一并结掉，需显式提示 */
function activeWorkers(row) {
  return (row && Array.isArray(row.workers)) ? row.workers.filter((w) => w.working) : [];
}

/** 本人收工：只结束自己的计时，工单继续，其他人的计时不受影响 */
async function leaveRepair(row) {
  try {
    await ElMessageBox.confirm(
      `确认结束你在 ${row.cart_no} 上的维修计时吗？\n\n工单不会关闭，其他人仍在维修时可继续。`,
      '我的维修已完成',
      { type: 'warning', confirmButtonText: '完成我的部分', cancelButtonText: '取消' }
    );
  } catch (e) {
    return;
  }
  try {
    const res = await request.post('/aging_cart/leave', { id: row.id });
    const d = (res && res.data) || {};
    ElMessage.success(`你的维修计时已结束：${d.duration_text || '-'}`);
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
    const who = Array.isArray(d.workers) ? d.workers.length : 0;
    ElMessage.success(
      who > 1
        ? `收工完成：修复跨度 ${d.duration_text || '-'}，${who} 人投入工时合计 ${d.work_text || '-'}`
        : `维修完成，有效时长 ${d.duration_text || '-'}`
    );
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
  if (!editForm.fault_type) {
    ElMessage.warning('请选择故障类型');
    return;
  }
  if (descRequired(editForm.fault_type) && !editForm.fault_desc.trim()) {
    ElMessage.warning('故障类型为「其他」时，必须填写故障现象');
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

.ac-legend-tip {
  color: #909399;
  font-size: 12px;
}

/* 状态看板：一个编号一个方块，正常绿 / 故障红 */
.ac-board-head {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
  font-size: 12px;
  color: #606266;
  margin-bottom: 8px;
}

.ac-board-title {
  font-size: 13.5px;
  font-weight: 600;
  color: #2c3e50;
}

.ac-board-head .ac-legend-tip {
  margin-left: auto;
}

.ac-legend {
  display: flex;
  align-items: center;
  gap: 12px;
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

.ac-cell-layers {
  font-size: 10px;
  color: #909399;
}

.ac-cell-normal {
  background: #f0f9eb;
  border-color: #b3e19d;
}

.ac-cell-normal .ac-cell-no {
  color: #67c23a;
}

.ac-cell-fault {
  background: #fef0f0;
  border-color: #fab6b6;
}

.ac-cell-fault .ac-cell-no {
  color: #f56c6c;
}

/* 被看板选中的车：蓝色描边，和下方列表筛选结果对应 */
.ac-cell-active {
  outline: 2px solid #409eff;
  outline-offset: -1px;
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

/* ===== 多人协作：参与人标签 ===== */
.ac-workers {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  align-items: center;
}

.ac-worker {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 1px 6px;
  border: 1px solid #dcdfe6;
  border-radius: 3px;
  background: #fff;
  font-size: 12px;
  color: #606266;
  white-space: nowrap;
}

.ac-worker-on {
  border-color: #b3d8ff;
  background: #ecf5ff;
  color: #409eff;
}

.ac-worker-ico {
  font-size: 11px;
  color: #909399;
}

.ac-worker-time {
  color: #303133;
  font-weight: 600;
}

.ac-worker-star {
  padding: 0 3px;
  border-radius: 2px;
  background: #fdf6ec;
  color: #e6a23c;
  font-size: 10px;
  line-height: 14px;
}

.ac-worker-idle {
  color: #e6a23c;
  font-size: 12px;
}

/* ===== 收工对话框里的参与人结算 ===== */
.ac-finish-workers {
  margin-top: 10px;
  padding-top: 8px;
  border-top: 1px dashed #dcdfe6;
}

.ac-finish-workers-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 6px;
}

.ac-finish-sum {
  margin-top: 6px;
  font-size: 12.5px;
  color: #606266;
}

.ac-finish-alert {
  margin-top: 8px;
}

.mr-1 {
  margin-right: 4px;
}
</style>
