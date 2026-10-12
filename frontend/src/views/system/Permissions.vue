<template>
  <div class="perm-container">
    <div class="perm-card" style="margin-bottom:12px;">
      <div class="perm-card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:4px;"><Key /></el-icon>
          用户模块权限分配
        </span>
      </div>
    </div>

    <div class="perm-card">
      <div style="padding:14px 18px;">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px; flex-wrap:wrap;">
          <label style="font-weight:600; font-size:13px; color:#495057; margin-bottom:0;">选择授权用户:</label>
          <select v-model="selectedUserId" class="perm-select" style="width:280px;" @change="onUserChange">
            <option value="">-- 请选择用户 --</option>
            <option v-for="u in users" :key="u.id" :value="u.id">
              {{ u.full_name || u.username }} (@{{ u.username }})
            </option>
          </select>
          <span style="font-size:12px; color:#64748b;">
            💡 支持常规模块权限、质量追溯、首件检判据、MAC 地址及外协管理 (OSM) 细分权限；<br/>
            设备矩阵 / 周完成统计 / 人员离岗的<b>数据维护权</b>也在此页授权，未勾选的用户只能查看与日常录入。
          </span>        </div>

        <div v-if="selectedUserId">
          <div v-if="errorMsg" style="color:#dc3545; margin-bottom:10px; font-size:12.5px; white-space:pre-wrap;">{{ errorMsg }}</div>

          <div class="perm-table-wrapper" v-loading="loading">
            <table class="perm-table">
              <thead>
                <tr>
                  <th style="width:110px; text-align:left;">分类</th>
                  <th style="width:150px; text-align:left;">模块名称</th>
                  <th style="width:130px; text-align:left;">模块代码</th>
                  <th style="width:80px; text-align:center;">查看权限</th>
                  <th style="text-align:left;">业务操作与细分权限配置</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in matrix" :key="p.module_id">
                  <td style="text-align:left;">
                    <span class="badge badge-cat">{{ p.category_name || '未分类' }}</span>
                  </td>
                  <td style="text-align:left;"><strong>{{ p.module_name }}</strong></td>
                  <td style="text-align:left;"><code style="font-family:monospace; color:#e83e8c;">{{ p.module_code }}</code></td>
                  <td style="text-align:center;">
                    <input type="checkbox" v-model="p.can_view" :true-value="1" :false-value="0" style="cursor:pointer;" />
                  </td>
                  <td style="text-align:left;">
                    <!-- trace 质量追溯 -->
                    <div v-if="p.module_code === 'trace'" class="sub-perm-box">
                      <label><input type="checkbox" v-model="tracePerms.can_add_material" :true-value="1" :false-value="0" /> 添加/编辑物料</label>
                      <label><input type="checkbox" v-model="tracePerms.can_scan" :true-value="1" :false-value="0" /> 追溯扫码</label>
                      <label><input type="checkbox" v-model="tracePerms.can_view" :true-value="1" :false-value="0" /> 查看/导出记录</label>
                      <label><input type="checkbox" v-model="tracePerms.can_edit_record" :true-value="1" :false-value="0" /> 修改扫码记录</label>
                      <label><input type="checkbox" v-model="tracePerms.can_delete_record" :true-value="1" :false-value="0" /> 删除扫码记录</label>
                    </div>

                    <!-- first_piece 首件检 -->
                    <div v-else-if="p.module_code === 'first_piece'" class="sub-perm-box">
                      <label><input type="checkbox" v-model="fpPerms.can_manage_criteria" :true-value="1" :false-value="0" /> 判据标准管理</label>
                    </div>

                    <!-- mac 地址管理 -->
                    <div v-else-if="p.module_code === 'mac'" class="sub-perm-box">
                      <label><input type="checkbox" v-model="macPerms.can_apply" :true-value="1" :false-value="0" /> MAC地址申请</label>
                      <label><input type="checkbox" v-model="macPerms.can_config_ports" :true-value="1" :false-value="0" /> 产品网口配置</label>
                      <label><input type="checkbox" v-model="macPerms.can_import" :true-value="1" :false-value="0" /> MAC批量录入</label>
                      <label><input type="checkbox" v-model="macPerms.can_view_records" :true-value="1" :false-value="0" /> 申请记录管理</label>
                      <label><input type="checkbox" v-model="macPerms.can_view_stats" :true-value="1" :false-value="0" /> 库存统计看板</label>
                      <label><input type="checkbox" v-model="macPerms.can_view_pool" :true-value="1" :false-value="0" /> 地址池总表</label>
                      <label><input type="checkbox" v-model="macPerms.can_view_logs" :true-value="1" :false-value="0" /> 录入日志</label>
                    </div>

                    <!-- osm 外协 -->
                    <div v-else-if="p.module_code === 'osm'" class="sub-perm-box">
                      <label><input type="checkbox" v-model="osmPerms.can_edit" :true-value="1" :false-value="0" /> 编辑</label>
                      <label><input type="checkbox" v-model="osmPerms.can_delete" :true-value="1" :false-value="0" /> 删除</label>
                      <label><input type="checkbox" v-model="osmPerms.can_upload" :true-value="1" :false-value="0" /> 上传</label>
                      <label><input type="checkbox" v-model="osmPerms.can_add_return" :true-value="1" :false-value="0" /> 添加返回</label>
                    </div>

                    <!-- barcode_validator 条码验证比对 -->
                    <div v-else-if="p.module_code === 'barcode_validator'" class="sub-perm-box">
                      <label><input type="checkbox" v-model="barcodePerms.can_save_settings" :true-value="1" :false-value="0" /> 验证设置</label>
                      <label><input type="checkbox" v-model="barcodePerms.can_delete" :true-value="1" :false-value="0" /> 删除记录</label>
                      <label><input type="checkbox" v-model="barcodePerms.can_edit" :true-value="1" :false-value="0" /> 编辑记录</label>
                    </div>

                    <!-- discuss 综合讨论交流区 -->
                    <div v-else-if="p.module_code === 'discuss'" class="sub-perm-box">
                      <label><input type="checkbox" v-model="discussPerms.can_view" :true-value="1" :false-value="0" /> 查看</label>
                      <label><input type="checkbox" v-model="discussPerms.can_create_topic" :true-value="1" :false-value="0" /> 发帖</label>
                      <label><input type="checkbox" v-model="discussPerms.can_reply" :true-value="1" :false-value="0" /> 回复</label>
                      <label><input type="checkbox" v-model="discussPerms.can_edit" :true-value="1" :false-value="0" /> 编辑自己的帖子</label>
                      <label><input type="checkbox" v-model="discussPerms.can_delete" :true-value="1" :false-value="0" /> 删帖(仅管理员)</label>
                    </div>

                    <!-- 设备矩阵：数据维护（对应通用 can_edit） -->
                    <div v-else-if="p.module_code === 'equipment'" class="sub-perm-box">
                      <label><input type="checkbox" v-model="p.can_edit" :true-value="1" :false-value="0" /> 设备数据维护（新增/修改/删除）</label>
                    </div>

                    <!-- 周完成统计：仅"删除任意记录"需要授权，日常录入不受此开关限制 -->
                    <div v-else-if="p.module_code === 'weekly'" class="sub-perm-box">
                      <label><input type="checkbox" v-model="p.can_edit" :true-value="1" :false-value="0" /> 删除任意周统计记录（日常录入无需授权）</label>
                    </div>

                    <!-- 人员离岗监控：员工档案维护（卡片绑定等日常操作不受限制） -->
                    <div v-else-if="p.module_code === 'attendance'" class="sub-perm-box">
                      <label><input type="checkbox" v-model="p.can_edit" :true-value="1" :false-value="0" /> 员工档案维护（新增/修改/删除）</label>
                    </div>

                    <!-- 默认：允许编辑与数据管理 -->
                    <label v-else style="cursor:pointer; margin-bottom:0; display:inline-flex; align-items:center; gap:4px; font-size:12.5px;">
                      <input type="checkbox" v-model="p.can_edit" :true-value="1" :false-value="0" /> 允许编辑与数据管理
                    </label>
                  </td>
                </tr>
                <tr v-if="matrix.length === 0 && !loading">
                  <td colspan="5" style="text-align:center; padding:30px; color:#888;">该用户暂无模块数据</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div style="margin-top:14px; text-align:right;">
            <button type="button" class="btn-primary btn-sm" :disabled="saving" @click="savePerms">
              <el-icon class="mr-1"><Select /></el-icon>
              保存权限更改
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import { Key, Select } from '@element-plus/icons-vue';
import request from '@/api/request';

// 后端接口（对应 index.php 新增的路由；request 的 baseURL 已含 /api）
const API = {
  users:     '/system/users',
  getPerms:  '/system/permissions',       // GET  ?user_id=X
  savePerms: '/system/permissions/save',  // POST { user_id, permissions, trace_perms, ... }
};

const users = ref([]);
const selectedUserId = ref('');
const matrix = ref([]);

// 细分权限状态
const tracePerms = ref({ can_add_material:0, can_scan:1, can_view:1, can_edit_record:0, can_delete_record:0 });
const fpPerms    = ref({ can_manage_criteria: 0 });
const macPerms   = ref({ can_apply:1, can_config_ports:0, can_import:0, can_view_records:1, can_view_stats:1, can_view_pool:0, can_view_logs:0 });
const osmPerms   = ref({ can_edit:0, can_delete:0, can_upload:0, can_add_return:0 });
// ★ 条码验证比对 细分权限
const barcodePerms = ref({ can_save_settings:1, can_delete:0, can_edit:0 });
// ★ 综合讨论交流区 细分权限
const discussPerms = ref({ can_view:1, can_create_topic:1, can_reply:1, can_edit:1, can_delete:0 });

const loading = ref(false);
const saving = ref(false);
const errorMsg = ref('');

const normalizeInts = (obj, defaults) => {
  const out = { ...defaults };
  if (obj && typeof obj === 'object') {
    for (const k of Object.keys(defaults)) {
      if (k in obj) out[k] = Number(obj[k]) ? 1 : 0;
    }
  }
  return out;
};

const initData = async () => {
  try {
    const res = await request.get(API.users);
    const list = res?.data?.list ?? res?.data ?? [];
    users.value = Array.isArray(list) ? list : [];
  } catch (e) {
    ElMessage.error('加载用户列表失败: ' + (e?.message || e));
  }
};

const onUserChange = async () => {
  errorMsg.value = '';
  matrix.value = [];
  if (!selectedUserId.value) return;

  loading.value = true;
  try {
    const res = await request.get(API.getPerms, {
      params: { user_id: selectedUserId.value }
    });
    const data = res?.data ?? {};

    const perms = Array.isArray(data.permissions) ? data.permissions : [];
    perms.forEach(p => {
      p.can_view = Number(p.can_view) ? 1 : 0;
      p.can_edit = Number(p.can_edit) ? 1 : 0;
    });
    matrix.value = perms;

    tracePerms.value = normalizeInts(data.trace_perms, { can_add_material:0, can_scan:1, can_view:1, can_edit_record:0, can_delete_record:0 });
    fpPerms.value    = normalizeInts(data.fp_perms,    { can_manage_criteria: 0 });
    macPerms.value   = normalizeInts(data.mac_perms,   { can_apply:1, can_config_ports:0, can_import:0, can_view_records:1, can_view_stats:1, can_view_pool:0, can_view_logs:0 });
    osmPerms.value   = normalizeInts(data.osm_perms,   { can_edit:0, can_delete:0, can_upload:0, can_add_return:0 });
    barcodePerms.value = normalizeInts(data.barcode_perms, { can_save_settings:1, can_delete:0, can_edit:0 });
    discussPerms.value = normalizeInts(data.discuss_perms, { can_view:1, can_create_topic:1, can_reply:1, can_edit:1, can_delete:0 });
  } catch (e) {
    errorMsg.value = '加载权限失败: ' + (e?.response?.data?.message || e?.message || e);
  } finally {
    loading.value = false;
  }
};

const savePerms = async () => {
  if (!selectedUserId.value) return ElMessage.warning('请先选择用户');
  if (!matrix.value.length) return ElMessage.warning('没有可保存的权限数据');

  saving.value = true;
  try {
    const payload = {
      user_id: Number(selectedUserId.value),
      permissions: matrix.value.map(p => ({
        module_id: p.module_id,
        can_view: p.can_view ? 1 : 0,
        can_edit: p.can_edit ? 1 : 0
      })),
      trace_perms: { ...tracePerms.value },
      fp_perms:    { ...fpPerms.value },
      mac_perms:   { ...macPerms.value },
      osm_perms:   { ...osmPerms.value },
      barcode_perms: { ...barcodePerms.value },
      discuss_perms: { ...discussPerms.value }
    };
    const res = await request.post(API.savePerms, payload);
    ElMessage.success(res?.message || '权限保存成功！');
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || e?.message || '保存失败');
  } finally {
    saving.value = false;
  }
};

onMounted(initData);
</script>

<style scoped>
.perm-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.perm-card {
  background: #fff;
  border: 1px solid #e3e6f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
}
.perm-card-header {
  padding: 12px 20px;
  border-bottom: 1px solid #e3e6f0;
}
.perm-table-wrapper {
  width: 100%;
  overflow-x: auto;
  max-height: calc(100vh - 330px);
  min-height: 320px;
  border-top: 1px solid #dee2e6;
}
.perm-table {
  width: 100% !important;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 13px;
}
.perm-table thead th {
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
  text-align: center;
}
.perm-table tbody td {
  padding: 8px 10px;
  border-bottom: 1px solid #eee;
  border-right: 1px solid #eee;
  vertical-align: middle;
}
.perm-table tbody tr:hover { background-color: #f8fafc; }
.badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11.5px;
  font-weight: 500;
}
.badge-cat { background: #e8f4fd; color: #007bff; }
.perm-select {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 8px;
  outline: none;
  background: #fff;
  box-sizing: border-box;
}
.sub-perm-box {
  display: inline-flex;
  gap: 10px;
  align-items: center;
  background: #f0f7ff;
  border: 1px solid #d0e3ff;
  padding: 4px 10px;
  border-radius: 4px;
  font-size: 12px;
  flex-wrap: wrap;
}
.sub-perm-box label {
  margin-bottom: 0;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 3px;
  font-weight: 500;
  color: #007bff;
}
.btn-primary {
  background: #007bff;
  color: #fff;
  border: 1px solid #007bff;
  border-radius: 4px;
  padding: 6px 16px;
  font-size: 12.5px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}
.btn-primary:hover { background: #0069d9; }
.btn-primary:disabled { opacity: .6; cursor: not-allowed; }
.btn-sm { font-size: 12.5px; height: 33px; }
.mr-1 { margin-right: 4px; }
</style>