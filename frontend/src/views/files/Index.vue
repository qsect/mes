<template>
  <div class="fs-container">
    <!-- 顶部标题卡片 -->
    <div class="fs-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><FolderOpened /></el-icon>
          文件共享
        </span>
        <div style="display:flex; align-items:center; gap:8px;">
          <span
            class="net-switch-btn"
            :class="isLan ? 'lan' : 'wan'"
            title="点击可在【内网高速】与【外网公网】通道之间切换"
            @click="toggleNetwork"
          >
            <el-icon><component :is="isLan ? Lightning : Compass" /></el-icon>
            {{ isLan ? '内网高速通道' : '外网公网通道' }}
          </span>
          <el-button :loading="loading" @click="loadPath(currentPath)">
            <el-icon class="mr-1"><Refresh /></el-icon> 刷新
          </el-button>
        </div>
      </div>
    </div>

    <!-- 文件列表卡片 -->
    <div class="fs-card">
      <!-- 面包屑 -->
      <div class="fs-crumb">
        <a @click="loadPath('')">🏠 根目录</a>
        <template v-for="(part, i) in crumbParts" :key="i">
          <span class="sep">›</span>
          <span v-if="i === crumbParts.length - 1" class="current">{{ part.name }}</span>
          <a v-else @click="loadPath(part.path)">{{ part.name }}</a>
        </template>
      </div>

      <!-- 文件表格 -->
      <div class="fs-table-wrapper" v-loading="loading">
        <table class="fs-table">
          <thead>
            <tr>
              <th>名称</th>
              <th class="col-size">大小</th>
              <th class="col-time">修改时间</th>
            </tr>
          </thead>
          <tbody>
            <!-- 返回上级 -->
            <tr v-if="currentPath">
              <td>
                <a class="fs-name dir" @click="loadPath(parentPath)">
                  <span class="fs-icon">⬆️</span><span>返回上级</span>
                </a>
              </td>
              <td class="col-size">-</td>
              <td class="col-time">-</td>
            </tr>

            <!-- 目录 / 文件 -->
            <tr v-for="item in items" :key="item.path">
              <td>
                <a v-if="item.is_dir" class="fs-name dir" @click="loadPath(item.path)">
                  <span class="fs-icon">{{ fileIcon(item.name, true) }}</span>
                  <span>{{ item.name }}</span>
                </a>
                <a v-else class="fs-name file" :href="downloadUrl(item)" target="_blank">
                  <span class="fs-icon">{{ fileIcon(item.name, false) }}</span>
                  <span>{{ item.name }}</span>
                </a>
              </td>
              <td class="col-size">{{ item.size_text }}</td>
              <td class="col-time">{{ item.mtime_text }}</td>
            </tr>

            <!-- 空态 -->
            <tr v-if="items.length === 0 && !loading">
              <td colspan="3" class="fs-empty">（空目录）</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import { FolderOpened, Refresh, Lightning, Compass } from '@element-plus/icons-vue';
import request from '@/api/request';

const loading = ref(false);
const items = ref([]);
const currentPath = ref('');
const parentPath = ref('');
const isLan = ref(false);

const LS_KEY = 'fs_network_mode';

// 面包屑分段
const crumbParts = computed(() => {
  if (!currentPath.value) return [];
  const segs = currentPath.value.split('/').filter(Boolean);
  let acc = '';
  return segs.map((name) => {
    acc = acc ? acc + '/' + name : name;
    return { name, path: acc };
  });
});

// 文件类型图标
function fileIcon(name, isDir) {
  if (isDir) return '📁';
  const ext = (name.split('.').pop() || '').toLowerCase();
  const map = {
    zip: '🗜️', rar: '🗜️', '7z': '🗜️', tar: '🗜️', gz: '🗜️',
    pdf: '📕', doc: '📘', docx: '📘', xls: '📗', xlsx: '📗',
    ppt: '📙', pptx: '📙',
    jpg: '🖼️', jpeg: '🖼️', png: '🖼️', gif: '🖼️', webp: '🖼️',
    mp4: '🎬', avi: '🎬', mkv: '🎬',
    mp3: '🎵', wav: '🎵',
    txt: '📄', md: '📄', log: '📄', csv: '📊',
    exe: '⚙️', msi: '⚙️', apk: '📱', dmg: '💿', iso: '💿'
  };
  return map[ext] || '📄';
}

// 下载链接（同源，Cookie 自动携带）
function downloadUrl(item) {
  return `/api/files/download?path=${encodeURIComponent(item.path)}`;
}

// 切换内外网通道
function toggleNetwork() {
  const next = isLan.value ? 'wan' : 'lan';
  localStorage.setItem(LS_KEY, next);
  isLan.value = next === 'lan';
  ElMessage.success(`已切换至${isLan.value ? '内网高速' : '外网公网'}通道`);
}

// 加载目录
async function loadPath(path) {
  loading.value = true;
  try {
    const res = await request.get('/files/list', { params: { path: path || '' } });
    const data = res.data || {};
    items.value = data.items || [];
    currentPath.value = data.path || '';
    parentPath.value = data.parent || '';

    const saved = localStorage.getItem(LS_KEY);
    isLan.value = saved ? saved === 'lan' : !!data.is_lan;
  } catch (e) {
    items.value = [];
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  loadPath('');
});
</script>

<style scoped>
.fs-card {
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  margin-bottom: 12px;
}

.fs-crumb {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #e4e7ed;
  font-size: 13px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}
.fs-crumb a {
  color: #409eff;
  text-decoration: none;
  cursor: pointer;
}
.fs-crumb a:hover {
  text-decoration: underline;
}
.fs-crumb .sep {
  color: #adb5bd;
  margin: 0 6px;
}
.fs-crumb .current {
  color: #2c3e50;
  font-weight: 600;
}

.fs-table-wrapper {
  overflow-x: auto;
}
.fs-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.fs-table th {
  background: #f8f9fa;
  padding: 9px 12px;
  text-align: left;
  border-bottom: 2px solid #e4e7ed;
  font-weight: 600;
  color: #2c3e50;
  font-size: 12.5px;
}
.fs-table td {
  padding: 9px 12px;
  border-bottom: 1px solid #eee;
}
.fs-table tbody tr:hover {
  background: #f8fafc;
}
.fs-table .col-size {
  width: 110px;
  color: #64748b;
  white-space: nowrap;
}
.fs-table .col-time {
  width: 150px;
  color: #64748b;
  white-space: nowrap;
}

.fs-name {
  display: flex;
  align-items: center;
  gap: 8px;
  text-decoration: none;
  cursor: pointer;
}
.fs-name.dir {
  color: #e6a23c;
  font-weight: 500;
}
.fs-name.file {
  color: #2c3e50;
}
.fs-name.file:hover {
  color: #409eff;
  text-decoration: underline;
}
.fs-name.dir:hover {
  text-decoration: underline;
}
.fs-icon {
  font-size: 15px;
  width: 18px;
  display: inline-block;
  text-align: center;
}
.fs-empty {
  padding: 45px;
  text-align: center;
  color: #888;
}

.net-switch-btn {
  font-size: 12px;
  padding: 5px 10px;
  border-radius: 4px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  user-select: none;
  transition: all 0.2s ease;
}
.net-switch-btn.lan {
  background-color: #e6f7ff;
  color: #1890ff;
  border: 1px solid #91d5ff;
}
.net-switch-btn.lan:hover {
  background-color: #bae7ff;
}
.net-switch-btn.wan {
  background-color: #f6ffed;
  color: #52c41a;
  border: 1px solid #b7eb8f;
}
.net-switch-btn.wan:hover {
  background-color: #d9f7be;
}
</style>
