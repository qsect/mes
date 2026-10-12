<template>
  <div class="tl-container">
    <!-- 顶部标题卡片 -->
    <div class="tl-card" style="margin-bottom: 12px; padding: 12px 18px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:17.5px; font-weight:600; color:#2c3e50;">
          <el-icon style="color:#409EFF; vertical-align:middle; margin-right:6px;"><Tools /></el-icon>
          实用工具箱与文档生成
        </span>
      </div>
    </div>

    <!-- 选项卡导航 -->
    <div class="tl-tabs">
      <button type="button" class="tl-tab-btn" :class="{ active: activeTab === 'list' }" @click="activeTab = 'list'">
        <el-icon class="mr-1"><Grid /></el-icon> 常用工具导航
      </button>
      <button type="button" class="tl-tab-btn" :class="{ active: activeTab === 'beibian' }" @click="activeTab = 'beibian'">
        <el-icon class="mr-1"><Document /></el-icon> 北变标签转换
      </button>
      <button type="button" class="tl-tab-btn" :class="{ active: activeTab === 'qrcodes' }" @click="activeTab = 'qrcodes'">
        <el-icon class="mr-1"><Download /></el-icon> 二维码批量生成
      </button>
      <button type="button" class="tl-tab-btn" :class="{ active: activeTab === 'hebei' }" @click="activeTab = 'hebei'">
        <el-icon class="mr-1"><Box /></el-icon> 河北装箱清单生成
      </button>
      <button type="button" class="tl-tab-btn" :class="{ active: activeTab === 'henan' }" @click="activeTab = 'henan'">
        <el-icon class="mr-1"><CollectionTag /></el-icon> 河南 FTU 标签生成
      </button>
    </div>

    <!-- 视图 1: 常用工具导航 -->
    <div v-show="activeTab === 'list'">
      <div class="tl-card">
        <div style="padding: 16px;">
          <div class="tools-grid">
            <div class="tool-card-item">
              <div class="tool-icon">📄</div>
              <h3 class="tool-title">北变标签转换</h3>
              <p class="tool-desc">将单页 PDF 标签按 2×2 网格自动排版到横向 A4 Word</p>
              <button type="button" class="btn-primary btn-sm" @click="activeTab = 'beibian'">
                进入工具 →
              </button>
            </div>

            <div class="tool-card-item">
              <div class="tool-icon">📱</div>
              <h3 class="tool-title">二维码批量生成器</h3>
              <p class="tool-desc">上传 Excel 批量生成单页居中二维码 PDF 并打包下载</p>
              <button type="button" class="btn-primary btn-sm" @click="activeTab = 'qrcodes'">
                进入生成器 →
              </button>
            </div>

            <div class="tool-card-item">
              <div class="tool-icon">📦</div>
              <h3 class="tool-title">河北装箱清单</h3>
              <p class="tool-desc">纯 PHP 解析，自动按需求号分组、生成双二维码清单</p>
              <button type="button" class="btn-primary btn-sm" @click="activeTab = 'hebei'">
                进入生成器 →
              </button>
            </div>

            <div class="tool-card-item">
              <div class="tool-icon">🏷️</div>
              <h3 class="tool-title">河南 FTU 标签</h3>
              <p class="tool-desc">根据铭牌 ID 自动匹配出厂编号并生成 Word 标签</p>
              <button type="button" class="btn-primary btn-sm" @click="activeTab = 'henan'">
                进入生成器 →
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 视图 2: 北变标签转换 -->
    <div v-show="activeTab === 'beibian'">
      <div class="tl-card">
        <div class="tl-card-header">
          <span>北变标签转换 (PDF 标签排版至横向 A4 Word)</span>
        </div>
        <div style="padding: 18px;">
          <div
            v-if="!bbFile"
            class="tl-upload-area"
            :class="{ dragover: isDragOver.bb }"
            @click="triggerFileInput('bb')"
            @dragover.prevent="isDragOver.bb = true"
            @dragleave.prevent="isDragOver.bb = false"
            @drop.prevent="onDropFile($event, 'bb')"
          >
            <el-icon class="tl-upload-icon"><Document /></el-icon>
            <div class="tl-upload-text">点击或将 PDF 标签文件拖拽到此处</div>
            <div class="tl-upload-hint">支持 .pdf 格式文件</div>
            <input ref="fileInputBb" type="file" accept=".pdf" style="display:none;" @change="onFileChange($event, 'bb')" />
          </div>

          <div v-else class="tl-file-info">
            <span>已选文件：<strong>{{ bbFile.name }}</strong> ({{ (bbFile.size / 1024).toFixed(1) }} KB)</span>
            <button type="button" class="btn-outline btn-mini" @click="bbFile = null">重新选择</button>
          </div>

          <div style="display:flex; gap:10px; align-items:center; margin-top:14px;">
            <button type="button" class="btn-primary" :disabled="!bbFile || converting.bb" @click="executeConvert('bb')">
              <el-icon class="mr-1"><Tools /></el-icon> {{ converting.bb ? '正在生成 Word 文档...' : '生成横向 Word 文档' }}
            </button>
            <span v-if="statusMsg.bb" :style="{ color: statusMsgColor.bb, fontSize: '13px', fontWeight: 'bold' }">{{ statusMsg.bb }}</span>
          </div>

          <div class="tl-info-box">
            <strong>📌 排版规则说明：</strong>
            <ul>
              <li>系统自动提取上传 PDF 中的每一页，以高清晰度渲染。</li>
              <li>采用横向 A4 页面（2×2 布局），每 4 个标签自动合并为 1 页，无缝分页。</li>
              <li>自动消除表格与单元格内外边距，确保标签打印尺寸精确。</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- 视图 3: 二维码批量生成器 -->
    <div v-show="activeTab === 'qrcodes'">
      <div class="tl-card">
        <div class="tl-card-header" style="display:flex; justify-content:space-between; align-items:center;">
          <span>二维码批量生成并打包 (ZIP)</span>
          <button type="button" class="btn-outline btn-sm" @click="downloadQrTpl">
            <el-icon class="mr-1"><Download /></el-icon> 下载 Excel 模板
          </button>
        </div>
        <div style="padding: 18px;">
          <div
            v-if="!qrFile"
            class="tl-upload-area"
            :class="{ dragover: isDragOver.qr }"
            @click="triggerFileInput('qr')"
            @dragover.prevent="isDragOver.qr = true"
            @dragleave.prevent="isDragOver.qr = false"
            @drop.prevent="onDropFile($event, 'qr')"
          >
            <el-icon class="tl-upload-icon"><Upload /></el-icon>
            <div class="tl-upload-text">点击或将 Excel 拖拽到此处</div>
            <div class="tl-upload-hint">支持 .xlsx 或 .xls 格式</div>
            <input ref="fileInputQr" type="file" accept=".xlsx,.xls" style="display:none;" @change="onFileChange($event, 'qr')" />
          </div>

          <div v-else class="tl-file-info">
            <span>已选文件：<strong>{{ qrFile.name }}</strong> ({{ (qrFile.size / 1024).toFixed(1) }} KB)</span>
            <button type="button" class="btn-outline btn-mini" @click="qrFile = null">重新选择</button>
          </div>

          <div style="display:flex; gap:10px; align-items:center; margin-top:14px;">
            <button type="button" class="btn-primary" :disabled="!qrFile || converting.qr" @click="executeConvert('qr')">
              <el-icon class="mr-1"><Download /></el-icon> {{ converting.qr ? '正在批量生成并打包...' : '批量生成二维码 PDF 并打包下载' }}
            </button>
            <span v-if="statusMsg.qr" :style="{ color: statusMsgColor.qr, fontSize: '13px', fontWeight: 'bold' }">{{ statusMsg.qr }}</span>
          </div>

          <div class="tl-info-box">
            <strong>📌 模板与生成规范：</strong>
            <ul>
              <li>Excel 必需包含列：<strong>类型：、厂商：、型号：、ID：、硬件版本：、生产日期：</strong></li>
              <li>生产日期支持 Excel 日期单元格格式，自动统一转为 <code>YYYY年MM月DD日</code>。</li>
              <li>为每行数据生成单页居中二维码 A4 PDF（文件名为其 <code>ID</code>），并自动打包为 ZIP 下载。</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- 视图 4: 河北装箱清单 -->
    <div v-show="activeTab === 'hebei'">
      <div class="tl-card">
        <div class="tl-card-header">
          <span>河北项目装箱清单生成器</span>
        </div>
        <div style="padding: 18px;">
          <div
            v-if="!hbFile"
            class="tl-upload-area"
            :class="{ dragover: isDragOver.hb }"
            @click="triggerFileInput('hb')"
            @dragover.prevent="isDragOver.hb = true"
            @dragleave.prevent="isDragOver.hb = false"
            @drop.prevent="onDropFile($event, 'hb')"
          >
            <el-icon class="tl-upload-icon"><Box /></el-icon>
            <div class="tl-upload-text">点击或将河北项目 Excel 文件拖拽到此处</div>
            <div class="tl-upload-hint">支持 .xlsx 或 .xls 格式</div>
            <input ref="fileInputHb" type="file" accept=".xlsx,.xls" style="display:none;" @change="onFileChange($event, 'hb')" />
          </div>

          <div v-else class="tl-file-info">
            <span>已选文件：<strong>{{ hbFile.name }}</strong> ({{ (hbFile.size / 1024).toFixed(1) }} KB)</span>
            <button type="button" class="btn-outline btn-mini" @click="hbFile = null">重新选择</button>
          </div>

          <div style="display:flex; gap:10px; align-items:center; margin-top:14px;">
            <button type="button" class="btn-primary" :disabled="!hbFile || converting.hb" @click="executeConvert('hb')">
              <el-icon class="mr-1"><Tools /></el-icon> {{ converting.hb ? '正在生成装箱清单...' : '生成河北装箱清单 Word' }}
            </button>
            <span v-if="statusMsg.hb" :style="{ color: statusMsgColor.hb, fontSize: '13px', fontWeight: 'bold' }">{{ statusMsg.hb }}</span>
          </div>

          <div class="tl-info-box">
            <strong>📌 河北模板要求：</strong>
            <ul>
              <li>必需列：<strong>需求号、项目号、合同号、国网订单号、项目名称、序列号、电子标签实物ID号、开关编号、FTU ID号、物料描述</strong></li>
              <li>供应商自动填充为：<strong>积成电子股份有限公司</strong>。</li>
              <li>断路器二维码 = 电子标签实物ID号；FTU 出厂编号与二维码自动查库匹配。</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- 视图 5: 河南 FTU 标签 -->
    <div v-show="activeTab === 'henan'">
      <div class="tl-card">
        <div class="tl-card-header">
          <span>河南项目 FTU 标签生成器</span>
        </div>
        <div style="padding: 18px;">
          <div
            v-if="!hnFile"
            class="tl-upload-area"
            :class="{ dragover: isDragOver.hn }"
            @click="triggerFileInput('hn')"
            @dragover.prevent="isDragOver.hn = true"
            @dragleave.prevent="isDragOver.hn = false"
            @drop.prevent="onDropFile($event, 'hn')"
          >
            <el-icon class="tl-upload-icon"><CollectionTag /></el-icon>
            <div class="tl-upload-text">点击或将河南项目 Excel 文件拖拽到此处</div>
            <div class="tl-upload-hint">支持 .xlsx 或 .xls 格式</div>
            <input ref="fileInputHn" type="file" accept=".xlsx,.xls" style="display:none;" @change="onFileChange($event, 'hn')" />
          </div>

          <div v-else class="tl-file-info">
            <span>已选文件：<strong>{{ hnFile.name }}</strong> ({{ (hnFile.size / 1024).toFixed(1) }} KB)</span>
            <button type="button" class="btn-outline btn-mini" @click="hnFile = null">重新选择</button>
          </div>

          <div style="display:flex; gap:10px; align-items:center; margin-top:14px;">
            <button type="button" class="btn-primary" :disabled="!hnFile || converting.hn" @click="executeConvert('hn')">
              <el-icon class="mr-1"><Tools /></el-icon> {{ converting.hn ? '正在生成标签 Word...' : '生成河南 FTU 标签 Word' }}
            </button>
            <span v-if="statusMsg.hn" :style="{ color: statusMsgColor.hn, fontSize: '13px', fontWeight: 'bold' }">{{ statusMsg.hn }}</span>
          </div>

          <div class="tl-info-box">
            <strong>📌 河南模板要求：</strong>
            <ul>
              <li>必需列：<strong>中标批次、项目名称、IP地址、设备名称、设备类型、型号、中标厂家、生产厂家、铭牌ID、出厂编号、供货订单号、技术ID</strong></li>
              <li>根据“铭牌ID”自动从 <code>ftuid_records</code> 数据库查询并填充<strong>出厂编号</strong>与<strong>底部二维码内容</strong>。</li>
              <li>每页排版 2 个标签，中间自动以空行隔离。</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { ElMessage } from 'element-plus';
import {
  Tools, Document, Grid, Box, CollectionTag, Download, Upload
} from '@element-plus/icons-vue';

const activeTab = ref('list');

// 文件绑定引用
const bbFile = ref(null);
const qrFile = ref(null);
const hbFile = ref(null);
const hnFile = ref(null);

const fileInputBb = ref(null);
const fileInputQr = ref(null);
const fileInputHb = ref(null);
const fileInputHn = ref(null);

const isDragOver = reactive({ bb: false, qr: false, hb: false, hn: false });
const converting = reactive({ bb: false, qr: false, hb: false, hn: false });
const statusMsg = reactive({ bb: '', qr: '', hb: '', hn: '' });
const statusMsgColor = reactive({ bb: '#67C23A', qr: '#67C23A', hb: '#67C23A', hn: '#67C23A' });

const triggerFileInput = (type) => {
  if (type === 'bb') fileInputBb.value?.click();
  else if (type === 'qr') fileInputQr.value?.click();
  else if (type === 'hb') fileInputHb.value?.click();
  else if (type === 'hn') fileInputHn.value?.click();
};

const onFileChange = (e, type) => {
  const file = e.target.files?.[0];
  if (file) setFile(file, type);
};

const onDropFile = (e, type) => {
  isDragOver[type] = false;
  const file = e.dataTransfer.files?.[0];
  if (file) setFile(file, type);
};

const setFile = (file, type) => {
  const ext = file.name.split('.').pop().toLowerCase();
  if (type === 'bb' && ext !== 'pdf') {
    return ElMessage.warning('请选择 PDF 格式文件！');
  }
  if (['qr', 'hb', 'hn'].includes(type) && !['xlsx', 'xls'].includes(ext)) {
    return ElMessage.warning('请选择 Excel 格式文件 (.xlsx / .xls)！');
  }

  if (type === 'bb') bbFile.value = file;
  else if (type === 'qr') qrFile.value = file;
  else if (type === 'hb') hbFile.value = file;
  else if (type === 'hn') hnFile.value = file;

  statusMsg[type] = '';
};

const downloadQrTpl = () => {
  window.open('/api/tools/download_qrcode_tpl', '_blank');
};

const executeConvert = async (type) => {
  let file = null;
  let actionName = '';
  let fieldName = '';
  let defaultName = '';

  if (type === 'bb') {
    file = bbFile.value;
    actionName = 'beibian_pdf2word_convert';
    fieldName = 'pdf_file';
    defaultName = '北变标签汇总.docx';
  } else if (type === 'qr') {
    file = qrFile.value;
    actionName = 'batch_qrcode_generate';
    fieldName = 'excel_file';
    defaultName = '二维码批量打包.zip';
  } else if (type === 'hb') {
    file = hbFile.value;
    actionName = 'hebei_packing_convert';
    fieldName = 'excel_file';
    defaultName = '河北装箱清单.docx';
  } else if (type === 'hn') {
    file = hnFile.value;
    actionName = 'henan_packing_convert';
    fieldName = 'excel_file';
    defaultName = '河南FTU标签.docx';
  }

  if (!file) return;

  converting[type] = true;
  statusMsg[type] = '正在处理数据并构建输出，请稍候...';
  statusMsgColor[type] = '#409EFF';

  const fd = new FormData();
  fd.append(fieldName, file);

  try {
    const res = await fetch(`/api/tools/${actionName}`, {
      method: 'POST',
      body: fd
    });

    if (!res.ok) {
      const errText = await res.text();
      throw new Error(errText || `HTTP ${res.status}`);
    }

    const disposition = res.headers.get('Content-Disposition');
    let filename = defaultName;
    if (disposition && disposition.includes('filename=')) {
      const match = disposition.match(/filename="?([^"]+)"?/);
      if (match) filename = decodeURIComponent(match[1]);
    }

    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);

    statusMsg[type] = '✓ 生成成功！文件已开始下载';
    statusMsgColor[type] = '#67C23A';
    ElMessage.success('文档生成成功');
  } catch (err) {
    statusMsg[type] = `✕ 处理失败：${err.message}`;
    statusMsgColor[type] = '#F56C6C';
    ElMessage.error(err.message);
  } finally {
    converting[type] = false;
  }
};
</script>

<style scoped>
.tl-container {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.tl-card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
  margin-bottom: 12px;
}
.tl-card-header {
  padding: 10px 16px;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  font-size: 14.5px;
  font-weight: 600;
  color: #2c3e50;
}

.tl-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  border-bottom: 2px solid #dee2e6;
  flex-wrap: wrap;
}
.tl-tab-btn {
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
.tl-tab-btn.active {
  color: #007bff;
  border-bottom-color: #007bff;
}

.tools-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 14px;
}
.tool-card-item {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  padding: 18px 20px;
  text-align: center;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: center;
  transition: all 0.2s ease;
}
.tool-card-item:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 123, 255, 0.12);
  border-color: #007bff;
}
.tool-icon { font-size: 34px; margin-bottom: 8px; }
.tool-title { font-size: 15px; font-weight: 600; color: #2c3e50; margin: 0 0 6px 0; }
.tool-desc { color: #64748b; font-size: 12.5px; margin: 0 0 16px 0; line-height: 1.5; min-height: 38px; }

.tl-upload-area {
  border: 2px dashed #409EFF;
  border-radius: 6px;
  padding: 40px 20px;
  text-align: center;
  cursor: pointer;
  background: #f8fafc;
  transition: all 0.25s ease;
  margin-bottom: 14px;
}
.tl-upload-area:hover, .tl-upload-area.dragover {
  border-color: #0056b3;
  background: #eef6ff;
}
.tl-upload-icon { font-size: 42px; color: #409EFF; margin-bottom: 8px; }
.tl-upload-text { font-size: 14.5px; font-weight: 600; color: #2c3e50; }
.tl-upload-hint { font-size: 12px; color: #64748b; margin-top: 4px; }

.tl-file-info {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #eef6ff;
  border: 1px solid #d0e3ff;
  border-radius: 4px;
  padding: 10px 16px;
  margin-bottom: 14px;
  font-size: 13.5px;
  color: #0056b3;
}

.tl-info-box {
  background: #f8f9fa;
  border-left: 4px solid #007bff;
  padding: 12px 16px;
  border-radius: 4px;
  font-size: 12.5px;
  color: #495057;
  line-height: 1.7;
  margin-top: 16px;
}
.tl-info-box ul { margin: 6px 0 0 18px; padding: 0; }

.btn-primary {
  background: #007bff;
  color: #fff;
  border: 1px solid #007bff;
  border-radius: 4px;
  padding: 6px 18px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  font-size: 13px;
  font-weight: 600;
}
.btn-primary:hover { background: #0069d9; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

.btn-outline {
  background: #fff;
  color: #495057;
  border: 1px solid #ced4da;
  border-radius: 4px;
  padding: 4px 10px;
  font-size: 12px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}

.btn-sm { font-size: 12px; height: 32px; padding: 0 14px; }
.btn-mini { padding: 2px 8px; font-size: 11.5px; }
.mr-1 { margin-right: 4px; }
</style>