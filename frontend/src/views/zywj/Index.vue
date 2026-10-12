<template>
  <div class="zywj-container">
    <!-- 顶部标题卡片 -->
    <div class="zywj-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Document /></el-icon>
          作业指导文件管理 (SOP)
        </span>
        <button v-if="isAdmin" type="button" class="btn-primary btn-sm" @click="openUploadModal">
          <el-icon class="mr-1"><Upload /></el-icon> 上传作业文件
        </button>
      </div>
    </div>

    <!-- 筛选过滤卡片 -->
    <div class="zywj-card">
      <div class="zywj-filter-card">
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
          <select v-model="filterCategory" class="zywj-select" style="width:160px;" @change="loadTable(1)">
            <option value="">全部分类</option>
            <option v-for="c in categoryList" :key="c" :value="c">{{ c }}</option>
          </select>
          <input
            v-model="searchKeyword"
            type="text"
            class="zywj-input"
            style="width:260px;"
            placeholder="搜索文件标题、编号、产品代码..."
            @keyup.enter="loadTable(1)"
          />
          <button type="button" class="btn-primary btn-sm" @click="loadTable(1)">
            <el-icon class="mr-1"><Search /></el-icon> 搜索
          </button>
          <button type="button" class="btn-outline btn-sm" @click="resetFilter">
            <el-icon class="mr-1"><Refresh /></el-icon> 重置
          </button>
        </div>
      </div>

      <!-- 表格主体 -->
      <div class="zywj-table-wrapper" v-loading="loading">
        <table class="zywj-table">
          <thead>
            <tr>
              <th style="width:120px;">文件编号</th>
              <th>文件标题 / SOP 名称</th>
              <th style="width:130px;">所属分类</th>
              <th style="width:120px;">适配产品</th>
              <th style="width:110px;">作业工序</th>
              <th style="width:80px; text-align:center;">版本</th>
              <th style="width:90px; text-align:right;">大小</th>
              <th style="width:100px;">上传人</th>
              <th style="width:140px;">更新时间</th>
              <th style="width:130px; text-align:center;">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in recordList" :key="r.id">
              <td><code style="font-size:12px; color:#2c3e50;">{{ r.doc_no }}</code></td>
              <td>
                <strong style="color:#007bff; cursor:pointer;" @click="previewFile(r)">
                  <el-icon style="vertical-align:middle; margin-right:3px;"><Document /></el-icon>
                  {{ r.title }}
                </strong>
              </td>
              <td><span class="badge-cat">{{ r.category }}</span></td>
              <td><span style="font-family:monospace;">{{ r.product_code || '通用' }}</span></td>
              <td>{{ r.process_name || '-' }}</td>
              <td style="text-align:center;"><span class="badge-ver">{{ r.version }}</span></td>
              <td style="text-align:right; font-size:11.5px; color:#666;">{{ formatSize(r.file_size) }}</td>
              <td>{{ r.uploader_name }}</td>
              <td><span style="font-size:11.5px; color:#64748b;">{{ r.created_at }}</span></td>
              <td style="text-align:center; white-space:nowrap;">
                <button type="button" class="btn-outline btn-mini" title="预览文件" @click="previewFile(r)">
                  <el-icon><View /></el-icon> 预览
                </button>
                <a :href="r.file_path" :download="r.title" class="btn-primary btn-mini" style="margin-left:4px; text-decoration:none;" title="下载文件">
                  <el-icon><Download /></el-icon>
                </a>
                <button v-if="isAdmin" type="button" class="btn-danger btn-mini" style="margin-left:4px;" title="删除" @click="deleteItem(r)">
                  <el-icon><Delete /></el-icon>
                </button>
              </td>
            </tr>
            <tr v-if="recordList.length === 0 && !loading">
              <td colspan="10" style="text-align:center; padding:35px; color:#888;">
                暂无作业指导文件
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 分页栏 -->
      <div class="zywj-pagination-bar">
        <div style="color:#64748b;">共 <strong>{{ totalRecords }}</strong> 份文件，第 <strong>{{ curPage }}</strong> / <strong>{{ totalPages }}</strong> 页</div>
        <el-pagination v-model:current-page="curPage" :page-size="25" :total="totalRecords" layout="prev, pager, next" @current-change="loadTable" />
      </div>
    </div>

    <!-- 上传作业指导文件弹窗 -->
    <el-dialog v-model="uploadModalVisible" title="上传作业指导文件 (SOP)" width="580px" destroy-on-close>
      <div style="padding:10px 14px; font-size:12.5px;">
        <div class="form-grid-2">
          <div>
            <label class="form-label">文件编号</label>
            <input v-model="form.doc_no" type="text" class="zywj-input" style="width:100%; font-family:monospace;" placeholder="如：SOP-ASM-001" />
          </div>
          <div>
            <label class="form-label">版本号 *</label>
            <input v-model="form.version" type="text" class="zywj-input" style="width:100%;" placeholder="如：V1.0" required />
          </div>
          <div style="grid-column: span 2;">
            <label class="form-label">文件标题 *</label>
            <input v-model="form.title" type="text" class="zywj-input" style="width:100%;" placeholder="如：主板焊接作业指导规程" required />
          </div>
          <div>
            <label class="form-label">所属分类 *</label>
            <input v-model="form.category" type="text" class="zywj-input" style="width:100%;" placeholder="如：装配作业指导" list="zywj_cats" required />
            <datalist id="zywj_cats">
              <option v-for="c in categoryList" :key="c" :value="c" />
            </datalist>
          </div>
          <div>
            <label class="form-label">适配产品代码</label>
            <input v-model="form.product_code" type="text" class="zywj-input" style="width:100%; font-family:monospace;" placeholder="如：6002... (留空表示通用)" />
          </div>
          <div>
            <label class="form-label">对应工序名称</label>
            <input v-model="form.process_name" type="text" class="zywj-input" style="width:100%;" placeholder="如：装置装配 / 板卡调试" />
          </div>
          <div>
            <label class="form-label">选择本地文件 (PDF/图片) *</label>
            <input ref="fileInputRef" type="file" accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx" class="zywj-input" style="width:100%; height:33px; padding:3px 6px;" required />
          </div>
          <div style="grid-column: span 2;">
            <label class="form-label">备注说明</label>
            <textarea v-model="form.remarks" class="zywj-input" rows="2" style="width:100%; height:auto;" placeholder="简述文件修改点或作业注意事项..."></textarea>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button @click="uploadModalVisible = false">取消</el-button>
        <el-button type="primary" :loading="uploading" @click="submitUpload">确认上传并发布</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  Document, Upload, Search, Refresh, Delete, View, Download
} from '@element-plus/icons-vue';
import request from '@/api/request';

const isAdmin = ref(false);
const loading = ref(false);
const recordList = ref([]);
const totalRecords = ref(0);
const curPage = ref(1);
const totalPages = ref(1);

const categoryList = ref([]);
const filterCategory = ref('');
const searchKeyword = ref('');

const uploadModalVisible = ref(false);
const uploading = ref(false);
const fileInputRef = ref(null);

const form = reactive({
  doc_no: '',
  title: '',
  version: 'V1.0',
  category: '装配作业指导',
  product_code: '',
  process_name: '',
  remarks: ''
});

const formatSize = (bytes) => {
  if (!bytes) return '0 KB';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return (bytes / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i];
};

const initModule = async () => {
  try {
    const res = await request.get('/zywj/init');
    if (res.data) {
      categoryList.value = res.data.categories || [];
      isAdmin.value = !!res.data.is_admin;
    }
  } catch (e) {}
  loadTable(1);
};

const loadTable = async (p = 1) => {
  curPage.value = p;
  loading.value = true;
  try {
    const res = await request.get('/zywj/list', {
      params: {
        page: p,
        category: filterCategory.value,
        search: searchKeyword.value.trim()
      }
    });
    if (res.data) {
      recordList.value = res.data.records || [];
      totalRecords.value = res.data.total_records || 0;
      totalPages.value = res.data.total_pages || 1;
      isAdmin.value = !!res.data.is_admin;
    }
  } finally {
    loading.value = false;
  }
};

const resetFilter = () => {
  filterCategory.value = '';
  searchKeyword.value = '';
  loadTable(1);
};

const previewFile = (r) => {
  if (r.file_path) {
    window.open(r.file_path, '_blank');
  }
};

const openUploadModal = () => {
  Object.assign(form, {
    doc_no: '',
    title: '',
    version: 'V1.0',
    category: categoryList.value[0] || '装配作业指导',
    product_code: '',
    process_name: '',
    remarks: ''
  });
  uploadModalVisible.value = true;
};

const submitUpload = async () => {
  const file = fileInputRef.value?.files?.[0];
  if (!file) return ElMessage.warning('请选择需要上传的指导书文件！');
  if (!form.title.trim()) return ElMessage.warning('请输入文件标题！');

  const fd = new FormData();
  fd.append('file', file);
  fd.append('doc_no', form.doc_no);
  fd.append('title', form.title.trim());
  fd.append('version', form.version.trim());
  fd.append('category', form.category.trim());
  fd.append('product_code', form.product_code.trim());
  fd.append('process_name', form.process_name.trim());
  fd.append('remarks', form.remarks.trim());

  uploading.value = true;
  try {
    const res = await request.post('/zywj/upload', fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });
    ElMessage.success(res.message || '上传成功！');
    uploadModalVisible.value = false;
    loadTable(1);
  } finally {
    uploading.value = false;
  }
};

const deleteItem = (r) => {
  ElMessageBox.confirm(`确定要删除文件【${r.title}】吗？删除后文件不可恢复！`, '提示', { type: 'warning' }).then(async () => {
    const res = await request.post('/zywj/delete', { id: r.id });
    ElMessage.success(res.message || '删除成功');
    loadTable(curPage.value);
  });
};

onMounted(() => {
  initModule();
});
</script>

<style scoped>
.zywj-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.zywj-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.zywj-filter-card {
  padding: 10px 14px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}

.zywj-table-wrapper {
  width: 100%;
  overflow-x: auto;
  min-height: 320px;
}
.zywj-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  text-align: left;
}
.zywj-table th {
  background: #f8f9fa;
  padding: 9px 12px;
  border-bottom: 2px solid #dee2e6;
  font-weight: 600;
  color: #2c3e50;
  white-space: nowrap;
}
.zywj-table td {
  padding: 8px 12px;
  border-bottom: 1px solid #eee;
  vertical-align: middle;
}
.zywj-table tr:hover { background-color: #f8fbfe; }

.badge-cat {
  background: #e8f4fd;
  color: #007bff;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 500;
}
.badge-ver {
  background: #e6f4ea;
  color: #137333;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-family: monospace;
}

.zywj-pagination-bar {
  padding: 8px 14px;
  background: #f8f9fa;
  border-top: 1px solid #dee2e6;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12.5px;
}

.form-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.form-label {
  font-weight: 600;
  display: block;
  margin-bottom: 3px;
  color: #495057;
}

.zywj-input {
  height: 33px;
  font-size: 12.5px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 0 10px;
  outline: none;
  box-sizing: border-box;
}
.zywj-input:focus { border-color: #80bdff; }

.zywj-select {
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

.btn-sm { font-size: 12.5px; height: 33px; }
.btn-mini { padding: 2px 7px; font-size: 11px; height: 22px; line-height: 18px; }
.mr-1 { margin-right: 4px; }
</style>