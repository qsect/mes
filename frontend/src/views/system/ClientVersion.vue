<template>
  <div class="client-version-page">
    <el-alert
      title="客户端版本管理"
      type="info"
      :closable="false"
      show-icon
      style="margin-bottom: 16px;"
    >
      <template #default>
        客户端启动时会请求 <code>/api/client/check_version</code>。
        这里发布的<b>最高版本</b>会被当作最新版本；勾选「强制更新」后，
        版本较低的客户端必须更新才能进入系统。<br />
        <b>下载地址</b>可以填两种：
        ① <code>http(s)://</code> 外链；
        ② 共享目录下的相对路径，如 <code>电脑工具/TraceClient_v2.2.exe</code>。<br />
        填相对路径时，系统会自动生成<b>免登录</b>下载地址
        （<code>/api/client/download</code>），客户端在登录前就能直接下载。
      </template>
    </el-alert>

    <el-card shadow="never">
      <template #header>
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <span>已发布版本</span>
          <el-button type="primary" @click="openDialog()">发布新版本</el-button>
        </div>
      </template>

      <el-table :data="records" v-loading="loading" border stripe>
        <el-table-column prop="id" label="ID" width="70" align="center" />
        <el-table-column prop="client_key" label="客户端" width="200">
          <template #default="{ row }">
            <span>{{ clientName(row.client_key) }}</span>
            <div style="color:#999; font-size:12px;">{{ row.client_key }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="version" label="版本号" width="100" align="center">
          <template #default="{ row }">
            <el-tag type="primary">v{{ row.version }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="min_version" label="最低支持版本" width="130" align="center">
          <template #default="{ row }">
            <span v-if="row.min_version">v{{ row.min_version }}</span>
            <span v-else style="color:#999;">—</span>
          </template>
        </el-table-column>
        <el-table-column prop="force_update" label="强制更新" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="row.force_update ? 'danger' : 'info'">
              {{ row.force_update ? '强制' : '可选' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="is_published" label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="row.is_published ? 'success' : 'warning'">
              {{ row.is_published ? '已发布' : '已下线' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="download_url" label="下载地址" min-width="240" show-overflow-tooltip>
          <template #default="{ row }">
            <template v-if="row.download_url">
              <div style="font-size:12px; color:#999;">配置值：{{ row.download_url }}</div>
              <el-link
                v-if="row.public_url"
                :href="row.public_url"
                target="_blank"
                type="primary"
                style="font-size:12px;"
              >
                客户端实际下载：{{ row.public_url }}
              </el-link>
              <el-link v-else :href="row.download_url" target="_blank" type="primary" style="font-size:12px;">
                {{ row.download_url }}
              </el-link>
            </template>
            <span v-else style="color:#999;">未填写</span>
          </template>
        </el-table-column>
        <el-table-column prop="update_log" label="更新说明" min-width="180" show-overflow-tooltip />
        <el-table-column prop="created_by" label="发布人" width="110" />
        <el-table-column prop="created_at" label="发布时间" width="170" />
        <el-table-column label="操作" width="140" align="center" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDialog(row)">编辑</el-button>
            <el-button link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog
      v-model="dialogVisible"
      :title="form.id ? '编辑版本记录' : '发布新版本'"
      width="560px"
    >
      <el-form :model="form" label-width="120px">
        <el-form-item label="客户端标识" required>
          <el-select
            v-model="form.client_key"
            filterable
            allow-create
            default-first-option
            placeholder="选择或输入客户端标识"
            style="width: 300px;"
            @change="onClientChange"
          >
            <el-option
              v-for="c in clients"
              :key="c.client_key"
              :label="`${c.name}（${c.client_key}）`"
              :value="c.client_key"
            />
          </el-select>
          <div style="color: #999; font-size: 12px; margin-top: 4px;">
            可直接输入新的标识以接入新客户端（小写字母/数字/下划线）。
            标识需与客户端 <code>Config.h</code> 中的 <code>CLIENT_KEY</code> 完全一致。
          </div>
        </el-form-item>
        <el-form-item label="版本号" required>
          <el-input v-model="form.version" placeholder="如 2.2 或 2.2.1" style="width: 200px;" />
          <span style="margin-left: 10px; color: #999; font-size: 12px;">纯数字加点，不要带 v</span>
        </el-form-item>
        <el-form-item label="最低支持版本">
          <el-input v-model="form.min_version" placeholder="留空表示不限制" style="width: 200px;" />
          <div style="color: #999; font-size: 12px; margin-top: 4px;">
            低于此版本号的客户端将<b>强制</b>更新（优先级高于下面的开关）
          </div>
        </el-form-item>
        <el-form-item label="强制更新">
          <el-switch v-model="form.force_update" />
          <span style="margin-left: 10px; color: #999; font-size: 12px;">
            开启后，低于该版本的客户端必须更新才能进入
          </span>
        </el-form-item>
        <el-form-item label="下载地址">
          <div style="display:flex; gap:8px; width:100%;">
            <el-input
              v-model="form.download_url"
              placeholder="https://... 外链，或 电脑工具/xxx.exe 这样的共享目录相对路径"
            />
            <el-button style="flex-shrink:0;" @click="openPicker">从共享文件选择</el-button>
          </div>
          <div style="color:#999; font-size:12px; margin-top:4px; line-height:1.7;">
            安装包已上传到 <code>shared_files</code>（如「电脑工具」「手机工具」等子目录）时，
            直接点右侧按钮挑选即可，无需手抄路径。<br />
            客户端实际拿到的地址（免登录）：
            <span v-if="previewUrl" style="color:#409eff;">{{ previewUrl }}</span>
            <span v-else style="color:#bbb;">填写后自动生成</span>
          </div>
        </el-form-item>
        <el-form-item label="更新说明">
          <el-input
            v-model="form.update_log"
            type="textarea"
            :rows="4"
            placeholder="本次更新内容，会展示给客户端用户"
          />
        </el-form-item>
        <el-form-item label="发布状态">
          <el-switch v-model="form.is_published" active-text="已发布" inactive-text="已下线" />
          <span style="margin-left: 10px; color: #999; font-size: 12px;">
            只有「已发布」的版本才会被客户端读取
          </span>
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="onSave">保存</el-button>
      </template>
    </el-dialog>

    <!-- 共享文件选择器：列出 shared_files 下的目录与文件，选中即回填相对路径 -->
    <el-dialog v-model="pickerVisible" title="选择安装包（shared_files）" width="640px">
      <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
        <el-button size="small" :disabled="!pickerPath" @click="loadShared(pickerParent)">
          返回上级
        </el-button>
        <span style="color:#666; font-size:12px;">
          当前目录：{{ pickerPath || '（根目录）' }}
        </span>
      </div>
      <el-table
        :data="pickerItems"
        v-loading="pickerLoading"
        size="small"
        border
        height="360"
        @row-dblclick="onPickerRow"
      >
        <el-table-column label="名称" min-width="260">
          <template #default="{ row }">
            <span :style="row.is_dir ? 'font-weight:600;' : ''">{{ row.name }}</span>
          </template>
        </el-table-column>
        <el-table-column label="大小" width="110" align="center" prop="size_text" />
        <el-table-column label="操作" width="90" align="center">
          <template #default="{ row }">
            <el-button v-if="row.is_dir" link type="primary" @click="loadShared(row.path)">进入</el-button>
            <el-button v-else link type="success" @click="pickFile(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div style="color:#999; font-size:12px; margin-top:8px;">
        双击目录进入、双击文件直接选中。
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import request from '@/api/request';

const records = ref([]);
const clients = ref([]);
const loading = ref(false);
const saving = ref(false);
const dialogVisible = ref(false);

const emptyForm = () => ({
  id: 0,
  client_key: 'impedance_recorder',
  version: '',
  min_version: '',
  force_update: false,
  download_url: '',
  update_log: '',
  is_published: true
});

const form = ref(emptyForm());

const loadRecords = async () => {
  loading.value = true;
  try {
    const res = await request.get('/client/list_versions');
    records.value = res.data.records || [];
  } finally {
    loading.value = false;
  }
};

const loadClients = async () => {
  try {
    const res = await request.get('/client/list_clients');
    clients.value = res.data.clients || [];
  } catch (e) {
    clients.value = [];
  }
};

// 切换客户端标识时，默认将当前记录重置为"新版本"（id=0 会造成误改）
// 仅在新建场景下有用；编辑时该回调不影响已选中的 id。
const onClientChange = () => {};

// ---------- 共享文件选择器 ----------
const pickerVisible = ref(false);
const pickerLoading = ref(false);
const pickerPath = ref('');
const pickerParent = ref('');
const pickerItems = ref([]);

const loadShared = async (path = '') => {
  pickerLoading.value = true;
  try {
    const res = await request.get('/client/list_shared', { params: { path } });
    pickerPath.value = res.data.path || '';
    pickerParent.value = res.data.parent || '';
    pickerItems.value = res.data.items || [];
  } catch (e) {
    ElMessage.error('读取共享目录失败：' + (e?.response?.data?.message || e?.message || '请确认有管理员权限'));
    pickerItems.value = [];
  } finally {
    pickerLoading.value = false;
  }
};

const openPicker = () => {
  pickerVisible.value = true;
  loadShared('');
};

const onPickerRow = (row) => {
  if (row.is_dir) {
    loadShared(row.path);
  } else {
    pickFile(row);
  }
};

const pickFile = (row) => {
  form.value.download_url = row.path;
  pickerVisible.value = false;
  ElMessage.success('已选择：' + row.path);
};

// 客户端最终拿到的下载地址：相对路径会展开成免登录端点 /api/client/download
const previewUrl = computed(() => {
  const url = (form.value.download_url || '').trim();
  if (!url) return '';
  if (/^https?:\/\//i.test(url)) return url;
  const key = encodeURIComponent(form.value.client_key || '');
  const ver = encodeURIComponent((form.value.version || '').trim());
  return `${window.location.origin}/api/client/download?key=${key}&v=${ver}`;
});

// 由 client_key 反查友好名称（找不到就原样显示 key）
const clientName = (key) => {
  const hit = clients.value.find((c) => c.client_key === key);
  return hit ? hit.name : key;
};

const openDialog = (row) => {
  if (row) {
    form.value = {
      id: row.id,
      client_key: row.client_key,
      version: row.version,
      min_version: row.min_version || '',
      force_update: !!Number(row.force_update),
      download_url: row.download_url || '',
      update_log: row.update_log || '',
      is_published: !!Number(row.is_published)
    };
  } else {
    form.value = emptyForm();
  }
  dialogVisible.value = true;
};

const onSave = async () => {
  if (!form.value.version || !/^\d+(\.\d+)*$/.test(form.value.version)) {
    ElMessage.warning('请填写正确的版本号，如 2.2');
    return;
  }
  saving.value = true;
  try {
    const res = await request.post('/client/save_version', {
      ...form.value,
      force_update: form.value.force_update ? 1 : 0,
      is_published: form.value.is_published ? 1 : 0
    });
    ElMessage.success(res.message || '保存成功');
    dialogVisible.value = false;
    loadRecords();
  } finally {
    saving.value = false;
  }
};

const onDelete = (row) => {
  ElMessageBox.confirm(
    `确定删除版本记录 v${row.version} 吗？删除后客户端将不再收到该版本提示。`,
    '删除确认',
    { type: 'warning' }
  ).then(async () => {
    const res = await request.post('/client/delete_version', { id: row.id });
    ElMessage.success(res.message || '已删除');
    loadRecords();
  }).catch(() => {});
};

onMounted(() => {
  loadClients();
  loadRecords();
});
</script>
