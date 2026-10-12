<!--
  轻量富文本编辑器（零依赖）
  ---------------------------------------------------------------------------
  设计取向：仿 Discuz / phpwind 论坛的发帖回帖工具条 —— 功能够用、体积极小、
  不引入任何第三方编辑器包（不新增 npm 依赖，服务器离线构建也不受影响）。

  实现方式：contenteditable + document.execCommand。
  execCommand 虽是"过时"API，但在所有主流浏览器里长期稳定，且它产出的
  <b>/<i>/<u>/<strike>/<span style>/<blockquote>/<pre>/<ul>/<ol>/<a>/<img>
  恰好全部落在后端 DiscussController::sanitizeHtml 与前端 utils/sanitizeHtml.js
  的白名单内，因此内容往返（编辑 → 入库 → 渲染）不会丢格式。

  对外接口：
    v-model            双向绑定 HTML 字符串
    placeholder        占位提示
    upload-path        图片上传接口（相对 request 的 baseURL=/api，默认 /discuss/upload）
    maxlength          字数上限（按纯文本计）
    min-height         内容区最小高度
    暴露方法           focus() / clear() / getHtml() / setHtml()
    具名插槽 #actions  工具条右下角操作区（放"提交回复"这类按钮）
-->
<template>
  <div class="rt-editor" :class="{ 'is-focus': focused }">
    <!-- ─── 工具条 ─── -->
    <div class="rt-toolbar" @mousedown.prevent>
      <!-- 字符格式 -->
      <div class="rt-group">
        <button type="button" class="rt-btn rt-b" :class="{ on: state.bold }"
                title="加粗 (Ctrl+B)" @click="exec('bold')">B</button>
        <button type="button" class="rt-btn rt-i" :class="{ on: state.italic }"
                title="斜体 (Ctrl+I)" @click="exec('italic')">I</button>
        <button type="button" class="rt-btn rt-u" :class="{ on: state.underline }"
                title="下划线 (Ctrl+U)" @click="exec('underline')">U</button>
        <button type="button" class="rt-btn rt-s" :class="{ on: state.strikeThrough }"
                title="删除线" @click="exec('strikeThrough')">S</button>
      </div>

      <i class="rt-sep"></i>

      <!-- 颜色 / 字号 -->
      <div class="rt-group">
        <el-popover :teleported="false" placement="bottom-start" :width="218"
                    trigger="click" v-model:visible="colorVisible">
          <template #reference>
            <button type="button" class="rt-btn rt-btn-color" title="文字颜色" @click="rememberSelection">
              <span class="rt-A">A</span>
              <span class="rt-A-bar" :style="{ background: currentColor }"></span>
            </button>
          </template>
          <div class="rt-pop" @mousedown.prevent>
            <div class="rt-pop-title">文字颜色</div>
            <div class="rt-color-grid">
              <span v-for="c in COLOR_LIST" :key="c" class="rt-color-cell"
                    :style="{ background: c }" :title="c"
                    @click="applyColor(c)"></span>
            </div>
            <div class="rt-pop-title">背景高亮</div>
            <div class="rt-color-grid">
              <span v-for="c in HILITE_LIST" :key="c" class="rt-color-cell"
                    :style="{ background: c }" :title="c"
                    @click="applyHilite(c)"></span>
            </div>
          </div>
        </el-popover>

        <el-popover :teleported="false" placement="bottom-start" :width="126"
                    trigger="click" v-model:visible="sizeVisible">
          <template #reference>
            <button type="button" class="rt-btn rt-btn-text" title="字号" @click="rememberSelection">字号</button>
          </template>
          <div class="rt-pop" @mousedown.prevent>
            <div v-for="s in SIZE_LIST" :key="s.v" class="rt-size-item" @click="applySize(s.v)">
              <span :style="{ fontSize: s.preview + 'px' }">{{ s.label }}</span>
            </div>
          </div>
        </el-popover>
      </div>

      <i class="rt-sep"></i>

      <!-- 对齐 -->
      <div class="rt-group">
        <button type="button" class="rt-btn" title="左对齐" @click="exec('justifyLeft')">
          <span class="rt-lines rt-l"><i></i><i></i><i></i></span>
        </button>
        <button type="button" class="rt-btn" title="居中对齐" @click="exec('justifyCenter')">
          <span class="rt-lines rt-c"><i></i><i></i><i></i></span>
        </button>
        <button type="button" class="rt-btn" title="右对齐" @click="exec('justifyRight')">
          <span class="rt-lines rt-r"><i></i><i></i><i></i></span>
        </button>
      </div>

      <i class="rt-sep"></i>

      <!-- 列表 -->
      <div class="rt-group">
        <button type="button" class="rt-btn" title="无序列表" @click="exec('insertUnorderedList')">
          <span class="rt-ul"><span></span><span></span><span></span></span>
        </button>
        <button type="button" class="rt-btn" title="有序列表" @click="exec('insertOrderedList')">
          <span class="rt-ol"><span></span><span></span><span></span></span>
        </button>
      </div>

      <i class="rt-sep"></i>

      <!-- 引用 / 代码 / 分割线 -->
      <div class="rt-group">
        <button type="button" class="rt-btn rt-quote" title="插入引用" @click="toggleBlock('blockquote')">❝</button>
        <button type="button" class="rt-btn rt-btn-text" title="插入代码块" @click="toggleBlock('pre')">&lt;/&gt;</button>
        <el-popover :teleported="false" placement="bottom-start" :width="268"
                    trigger="click" v-model:visible="emojiVisible">
          <template #reference>
            <button type="button" class="rt-btn rt-btn-text" title="插入表情">☺</button>
          </template>
          <div class="rt-pop rt-emoji-pop" @mousedown.prevent>
            <span v-for="e in EMOJI_LIST" :key="e" class="rt-emoji" @click="insertEmoji(e)">{{ e }}</span>
          </div>
        </el-popover>
      </div>

      <i class="rt-sep"></i>

      <!-- 链接 / 图片 -->
      <div class="rt-group">
        <button type="button" class="rt-btn" title="插入链接" @click="createLink">
          <el-icon><Link /></el-icon>
        </button>
        <button type="button" class="rt-btn" :title="uploading ? '上传中…' : '插入图片'"
                :disabled="uploading" @click="pickImage">
          <el-icon :class="{ spinning: uploading }"><Picture /></el-icon>
        </button>
      </div>

      <!-- 撤销 / 重做 / 清除格式 -->
      <div class="rt-group rt-group-right">
        <button type="button" class="rt-btn" title="撤销 (Ctrl+Z)" @click="exec('undo')">
          <el-icon><RefreshLeft /></el-icon>
        </button>
        <button type="button" class="rt-btn" title="重做 (Ctrl+Y)" @click="exec('redo')">
          <el-icon><RefreshRight /></el-icon>
        </button>
        <button type="button" class="rt-btn" title="清除格式" @click="clearFormat">
          <el-icon><Brush /></el-icon>
        </button>
      </div>
    </div>

    <!-- ─── 编辑区 ─── -->
    <div class="rt-main">
      <div
        ref="editorRef"
        class="rt-content"
        contenteditable="true"
        spellcheck="false"
        :style="{ minHeight: minHeight + 'px' }"
        @input="onInput"
        @keyup="onKeyup"
        @mouseup="rememberSelection"
        @focus="onFocus"
        @blur="onBlur"
        @paste="onPaste"
        @keydown.tab.prevent="insertHtmlAtCaret('&nbsp;&nbsp;&nbsp;&nbsp;')"
      ></div>
      <div v-if="blank" class="rt-placeholder">{{ placeholder }}</div>
    </div>

    <!-- ─── 底部状态条 ─── -->
    <div class="rt-footer">
      <span class="rt-count" :class="{ over: maxlength > 0 && count > maxlength }">
        <template v-if="maxlength > 0">{{ count }} / {{ maxlength }}</template>
        <template v-else>{{ count }} 字</template>
      </span>
      <div class="rt-actions">
        <slot name="actions"></slot>
      </div>
    </div>

    <!-- 图片上传用的隐藏输入框 -->
    <input ref="fileInputRef" type="file" accept="image/*" class="rt-file" @change="onFileChange" />
  </div>
</template>

<script setup>
import { ref, reactive, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Link, Picture, RefreshLeft, RefreshRight, Brush } from '@element-plus/icons-vue';
import request from '@/api/request';
import { isBlankHtml, htmlToText, escapeHtml } from '@/utils/richText';
import { sanitizeHtml } from '@/utils/sanitizeHtml';

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '请输入内容…' },
  uploadPath: { type: String, default: '/discuss/upload' },
  maxlength: { type: Number, default: 0 },
  minHeight: { type: Number, default: 130 }
});

const emit = defineEmits(['update:modelValue', 'change']);

const editorRef = ref(null);
const fileInputRef = ref(null);

const focused = ref(false);
const blank = ref(true);
const uploading = ref(false);
const currentColor = ref('#303133');
const count = ref(0);
const colorVisible = ref(false);
const sizeVisible = ref(false);
const emojiVisible = ref(false);

const state = reactive({ bold: false, italic: false, underline: false, strikeThrough: false });

// 工具条色板（Discuz 风格：常用色一眼可选，不做取色器，避免臃肿）
const COLOR_LIST = [
  '#303133', '#606266', '#909399', '#c0c4cc', '#ffffff',
  '#f56c6c', '#e6a23c', '#f7ba2a', '#67c23a', '#20b2aa',
  '#409eff', '#2f6fdd', '#722ed1', '#eb2f96', '#8b5a2b'
];
const HILITE_LIST = [
  '#fff3cd', '#ffe0e0', '#d9f7be', '#d6e4ff', '#f9f0ff',
  '#e6fffb', '#f0f0f0', '#ffe7ba', '#ffd6e7', '#f4f4f5'
];
const SIZE_LIST = [
  { v: 12, label: '小', preview: 12 },
  { v: 14, label: '常规', preview: 14 },
  { v: 16, label: '中', preview: 16 },
  { v: 18, label: '大', preview: 18 },
  { v: 24, label: '特大', preview: 22 },
  { v: 32, label: '超大', preview: 26 }
];
// 精选表情（避免引入表情库；够用即可）
const EMOJI_LIST = [
  '😀', '😁', '😂', '🤣', '😊', '😍', '😘', '😎',
  '🤔', '😐', '😴', '😢', '😭', '😡', '😱', '🥳',
  '🤝', '👍', '👎', '👌', '🙏', '💪', '🎉', '🔥',
  '✨', '⭐', '✅', '❌', '⚠️', '❓', '❗', '💡',
  '📌', '📎', '📁', '🖥️', '⚙️', '🔧', '🔍', '📊',
  '📈', '📅', '⏰', '🚀', '🏆', '☕', '❤️', '💯'
];

/* ────────────────────────── 选区管理 ──────────────────────────
   点击工具条上的按钮 / 弹出面板时，浏览器可能把焦点从编辑区移走，
   导致 window.getSelection() 丢失。这里持续记录最后一次落在编辑区
   内的 Range，执行命令前先恢复，保证"作用于光标处/选中处"。 */
let savedRange = null;

function rememberSelection() {
  const sel = window.getSelection();
  if (!sel || sel.rangeCount === 0) return;
  const range = sel.getRangeAt(0);
  const el = editorRef.value;
  if (el && range.commonAncestorContainer && el.contains(range.commonAncestorContainer)) {
    savedRange = range.cloneRange();
  }
}

function restoreSelection() {
  const el = editorRef.value;
  if (!el) return;
  el.focus();
  if (savedRange && el.contains(savedRange.commonAncestorContainer)) {
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(savedRange);
  }
}

/* ────────────────────────── 取值 / 通知 ────────────────────────── */

function getHtml() {
  const el = editorRef.value;
  if (!el) return '';
  const html = el.innerHTML;
  return isBlankHtml(html) ? '' : html;
}

function refreshMeta() {
  const html = getHtml();
  blank.value = html === '';
  count.value = htmlToText(html).length;
}

function emitChange() {
  const html = getHtml();
  emit('update:modelValue', html);
  emit('change', html);
  blank.value = html === '';
  count.value = htmlToText(html).length;
}

/* ────────────────────────── 基础命令 ────────────────────────── */

function exec(cmd, val) {
  restoreSelection();
  document.execCommand(cmd, false, val);
  rememberSelection();
  emitChange();
  syncState();
}

function syncState() {
  if (!focused.value) return;
  try {
    state.bold = document.queryCommandState('bold');
    state.italic = document.queryCommandState('italic');
    state.underline = document.queryCommandState('underline');
    state.strikeThrough = document.queryCommandState('strikeThrough');
  } catch (e) { /* 个别内核不支持时忽略 */ }
}

/** 在光标处插入一段 HTML（含兜底手写插入） */
function insertHtmlAtCaret(html) {
  const el = editorRef.value;
  if (!el) return;
  restoreSelection();

  let ok = false;
  try { ok = document.execCommand('insertHTML', false, html); } catch (e) { ok = false; }

  if (!ok) {
    const sel = window.getSelection();
    if (sel && sel.rangeCount > 0) {
      const range = sel.getRangeAt(0);
      range.deleteContents();
      const holder = document.createElement('div');
      holder.innerHTML = html;
      const frag = document.createDocumentFragment();
      let node, last = null;
      while ((node = holder.firstChild)) last = frag.appendChild(node);
      range.insertNode(frag);
      if (last) {
        range.setStartAfter(last);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
      }
    } else {
      el.innerHTML += html;
    }
  }
  rememberSelection();
  emitChange();
}

/**
 * 用 <span style="…"> 包裹当前选区
 * 用于字号 / 文字颜色 / 背景高亮 —— 比 execCommand('fontSize') 更可控，
 * 且产出的是 <span style>，正好命中前后端白名单。
 */
function wrapSelection(styleStr) {
  const el = editorRef.value;
  if (!el) return;
  restoreSelection();

  const sel = window.getSelection();
  if (!sel || sel.rangeCount === 0 || sel.getRangeAt(0).collapsed) {
    ElMessage.info('请先选中要设置的文字');
    return;
  }
  const holder = document.createElement('div');
  holder.appendChild(sel.getRangeAt(0).cloneContents());
  insertHtmlAtCaret(`<span style="${styleStr}">${holder.innerHTML}</span>`);
}

function applyColor(color) {
  wrapSelection(`color:${color}`);
  currentColor.value = color;
  colorVisible.value = false;
}

function applyHilite(color) {
  wrapSelection(`background-color:${color}`);
  colorVisible.value = false;
}

function applySize(size) {
  wrapSelection(`font-size:${size}px`);
  sizeVisible.value = false;
}

/** 块级格式（引用 / 代码块）切换 */
function toggleBlock(tag) {
  restoreSelection();
  let cur = '';
  try { cur = String(document.queryCommandValue('formatBlock') || '').toLowerCase(); } catch (e) {}
  document.execCommand('formatBlock', false, cur === tag ? 'p' : tag);
  rememberSelection();
  emitChange();
}

function clearFormat() {
  restoreSelection();
  document.execCommand('removeFormat');
  document.execCommand('formatBlock', false, 'p');
  rememberSelection();
  emitChange();
  ElMessage.success('已清除选中内容的格式');
}

/* ────────────────────────── 链接 / 表情 ────────────────────────── */

async function createLink() {
  rememberSelection();
  let url = '';
  try {
    const { value } = await ElMessageBox.prompt('请输入链接地址', '插入链接', {
      inputValue: 'https://',
      inputPattern: /^(https?:\/\/|mailto:|tel:|\/|#)/i,
      inputErrorMessage: '请输入以 http(s):// 、mailto: 、tel: 或 / 开头的地址',
      confirmButtonText: '插入',
      cancelButtonText: '取消'
    });
    url = String(value || '').trim();
  } catch (e) {
    return; // 用户取消
  }
  if (!url) return;

  restoreSelection();
  const sel = window.getSelection();
  const collapsed = !sel || sel.rangeCount === 0 || sel.getRangeAt(0).collapsed;
  if (collapsed) {
    insertHtmlAtCaret(`<a href="${escapeHtml(url)}" target="_blank">${escapeHtml(url)}</a>`);
  } else {
    document.execCommand('createLink', false, url);
    emitChange();
  }
}

function insertEmoji(emoji) {
  insertHtmlAtCaret(emoji);
  emojiVisible.value = false;
}

/* ────────────────────────── 图片上传 ────────────────────────── */

function pickImage() {
  rememberSelection();
  fileInputRef.value?.click();
}

async function onFileChange(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  if (file.size > 5 * 1024 * 1024) {
    ElMessage.warning('图片大小不能超过 5MB');
    return;
  }

  const fd = new FormData();
  fd.append('file', file);
  uploading.value = true;
  try {
    const res = await request.post(props.uploadPath, fd);
    const url = res?.data?.url;
    if (!url) throw new Error('上传返回缺少 url');
    const safeUrl = /^(https?:\/\/|\/)/i.test(url) ? url : '';
    if (!safeUrl) throw new Error('返回的图片地址不合法');
    insertHtmlAtCaret(
      `<img src="${escapeHtml(safeUrl)}" alt="${escapeHtml(file.name)}" style="max-width:100%;height:auto;" />`
    );
    ElMessage.success('图片已插入');
  } catch (err) {
    ElMessage.error('图片上传失败：' + (err?.message || '未知错误'));
  } finally {
    uploading.value = false;
  }
}

/* ────────────────────────── 粘贴净化 ────────────────────────── */

function onPaste(ev) {
  const dt = ev.clipboardData;
  if (!dt) return;
  ev.preventDefault();

  const html = dt.getData('text/html');
  const text = dt.getData('text/plain');

  if (html) {
    // 走项目统一的白名单净化，防止从外部页面粘贴带脚本 / 样式污染的内容
    const clean = sanitizeHtml(html.replace(/<!--[\s\S]*?-->/g, ''));
    if (clean && !isBlankHtml(clean)) {
      insertHtmlAtCaret(clean);
      return;
    }
  }
  if (text) {
    insertHtmlAtCaret(escapeHtml(text).replace(/\r?\n/g, '<br>'));
  }
}

/* ────────────────────────── 事件 ────────────────────────── */

function onInput() { emitChange(); syncState(); }
function onKeyup() { rememberSelection(); syncState(); }
function onFocus() { focused.value = true; }
function onBlur() { focused.value = false; }

/* ────────────────────────── 生命周期 ────────────────────────── */

function doSelectionChange() {
  if (focused.value) rememberSelection();
}

onMounted(() => {
  const el = editorRef.value;
  if (el) el.innerHTML = props.modelValue || '';
  blank.value = isBlankHtml(props.modelValue);

  // 回车生成 <p> 而非 <div>，产出更干净、与白名单更贴合
  try {
    document.execCommand('defaultParagraphSeparator', false, 'p');
    document.execCommand('styleWithCSS', false, false);
  } catch (e) { /* 忽略 */ }

  document.addEventListener('selectionchange', doSelectionChange);
});

onBeforeUnmount(() => {
  document.removeEventListener('selectionchange', doSelectionChange);
});

// 外部改值（如提交后清空、打开编辑弹窗回填）
watch(() => props.modelValue, (val) => {
  const el = editorRef.value;
  if (!el) return;
  const next = val || '';
  if (el.innerHTML === next) return;
  // 编辑中不覆盖，避免光标跳动
  if (focused.value) return;
  el.innerHTML = next;
  blank.value = isBlankHtml(next);
  count.value = htmlToText(next).length;
});

/* ────────────────────────── 对外方法 ────────────────────────── */

function focus() { nextTick(() => editorRef.value?.focus()); }

function clear() {
  const el = editorRef.value;
  if (el) el.innerHTML = '';
  blank.value = true;
  count.value = 0;
  emit('update:modelValue', '');
}

function setHtml(html) {
  const el = editorRef.value;
  const next = html || '';
  if (el) el.innerHTML = next;
  blank.value = isBlankHtml(next);
  count.value = htmlToText(next).length;
  emit('update:modelValue', next);
}

defineExpose({ focus, clear, setHtml, getHtml, isEmpty: () => blank.value });
</script>

<style scoped>
.rt-editor {
  border: 1px solid #dcdfe6;
  border-radius: 6px;
  background: #fff;
  transition: border-color .2s, box-shadow .2s;
  position: relative;
}
.rt-editor.is-focus {
  border-color: #409eff;
  box-shadow: 0 0 0 2px rgba(64, 158, 255, .12);
}

/* ─── 工具条 ─── */
.rt-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 2px;
  padding: 4px 6px;
  background: #f7f8fa;
  border-bottom: 1px solid #ebeef5;
  border-radius: 6px 6px 0 0;
  user-select: none;
}
.rt-group { display: flex; align-items: center; gap: 2px; }
.rt-group-right { margin-left: auto; }
.rt-sep {
  width: 1px;
  height: 16px;
  margin: 0 4px;
  background: #dcdfe6;
  display: inline-block;
}

.rt-btn {
  min-width: 26px;
  height: 26px;
  padding: 0 4px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid transparent;
  border-radius: 4px;
  background: transparent;
  color: #606266;
  font-size: 13px;
  line-height: 1;
  cursor: pointer;
  transition: background-color .15s, color .15s, border-color .15s;
}
.rt-btn:hover { background: #e9edf3; color: #409eff; }
.rt-btn:disabled { opacity: .45; cursor: not-allowed; }
.rt-btn.on { background: #dbe9ff; color: #1f6fd0; border-color: #b6d3fb; }

.rt-b { font-weight: 700; font-family: Georgia, "Times New Roman", serif; }
.rt-i { font-style: italic; font-family: Georgia, "Times New Roman", serif; }
.rt-u { text-decoration: underline; font-family: Georgia, "Times New Roman", serif; }
.rt-s { text-decoration: line-through; font-family: Georgia, "Times New Roman", serif; }
.rt-quote { font-size: 15px; font-weight: 700; color: #7a8b99; }
.rt-btn-text { font-size: 12px; letter-spacing: -.2px; }

/* 颜色按钮：A + 底部色条 */
.rt-btn-color { flex-direction: column; gap: 0; padding: 2px 4px; }
.rt-A { font-size: 13px; font-weight: 700; line-height: 1; font-family: Georgia, serif; }
.rt-A-bar {
  width: 14px; height: 3px; border-radius: 1px; margin-top: 1px; display: block;
}

/* CSS 绘制的对齐 / 列表图标（零依赖，风格统一） */
.rt-lines { display: flex; flex-direction: column; gap: 2px; width: 13px; }
.rt-lines > i { display: block; height: 2px; border-radius: 1px; background: currentColor; width: 100%; }
.rt-lines.rt-l { align-items: flex-start; }
.rt-lines.rt-c { align-items: center; }
.rt-lines.rt-r { align-items: flex-end; }
.rt-lines.rt-c > i:nth-child(2), .rt-lines.rt-r > i:nth-child(2) { width: 60%; }

.rt-ul, .rt-ol { display: flex; flex-direction: column; gap: 3px; width: 15px; }
.rt-ul > span, .rt-ol > span { display: flex; align-items: center; gap: 3px; height: 2px; }
.rt-ul > span::before {
  content: ''; width: 3px; height: 3px; border-radius: 50%;
  background: currentColor; flex: none;
}
.rt-ul > span::after, .rt-ol > span::after {
  content: ''; height: 2px; background: currentColor; flex: 1; border-radius: 1px;
}
.rt-ol { counter-reset: rtol; }
.rt-ol > span::before {
  counter-increment: rtol; content: counter(rtol);
  font-size: 8px; line-height: 1; font-weight: 700; flex: none;
}

.spinning { animation: rt-spin 1s linear infinite; }
@keyframes rt-spin { to { transform: rotate(360deg); } }

/* ─── 弹出面板 ─── */
.rt-pop { padding: 2px 0; max-height: 260px; overflow-y: auto; }
.rt-pop-title { font-size: 12px; color: #909399; margin: 4px 0 6px; }
.rt-color-grid { display: grid; grid-template-columns: repeat(10, 1fr); gap: 4px; margin-bottom: 8px; }
.rt-color-cell {
  width: 100%; aspect-ratio: 1 / 1; border-radius: 3px;
  border: 1px solid rgba(0, 0, 0, .12); cursor: pointer;
}
.rt-color-cell:hover { transform: scale(1.12); }
.rt-size-item {
  padding: 5px 8px; border-radius: 4px; cursor: pointer; color: #303133; line-height: 1.3;
}
.rt-size-item:hover { background: #f0f6ff; color: #409eff; }
.rt-emoji-pop { display: grid; grid-template-columns: repeat(8, 1fr); gap: 2px; }
.rt-emoji {
  font-size: 18px; text-align: center; padding: 3px 0;
  border-radius: 4px; cursor: pointer; line-height: 1.2;
}
.rt-emoji:hover { background: #f0f6ff; }

/* ─── 编辑区 ─── */
.rt-main { position: relative; }
.rt-content {
  padding: 10px 12px;
  font-size: 14px;
  line-height: 1.75;
  color: #303133;
  outline: none;
  word-break: break-word;
  overflow-wrap: anywhere;
}
.rt-placeholder {
  position: absolute;
  top: 10px;
  left: 12px;
  color: #a8abb2;
  font-size: 14px;
  pointer-events: none;
}

/* 编辑区内的富文本外观（内容由 innerHTML 注入，需用 :deep） */
.rt-content :deep(p) { margin: 0 0 8px; }
.rt-content :deep(p:last-child) { margin-bottom: 0; }
.rt-content :deep(blockquote) {
  margin: 6px 0;
  padding: 6px 10px;
  border-left: 3px solid #c8d6e5;
  background: #f6f8fa;
  color: #5a6673;
  border-radius: 0 4px 4px 0;
}
.rt-content :deep(pre) {
  margin: 6px 0;
  padding: 8px 10px;
  background: #f5f7fa;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  font-family: Consolas, Monaco, "Courier New", monospace;
  font-size: 13px;
  white-space: pre-wrap;
  overflow-x: auto;
}
.rt-content :deep(code) {
  padding: 1px 4px;
  background: #f2f3f5;
  border-radius: 3px;
  font-family: Consolas, Monaco, monospace;
  font-size: 13px;
}
.rt-content :deep(a) { color: #409eff; text-decoration: none; }
.rt-content :deep(a:hover) { text-decoration: underline; }
.rt-content :deep(img) { max-width: 100%; vertical-align: bottom; }
.rt-content :deep(ul), .rt-content :deep(ol) { margin: 6px 0; padding-left: 22px; }
.rt-content :deep(li) { margin: 2px 0; }
.rt-content :deep(hr) { border: none; border-top: 1px dashed #dcdfe6; margin: 10px 0; }

/* ─── 底部状态条 ─── */
.rt-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 4px 8px;
  border-top: 1px solid #ebeef5;
  background: #fafbfc;
  border-radius: 0 0 6px 6px;
  min-height: 34px;
}
.rt-count { font-size: 12px; color: #a8abb2; }
.rt-count.over { color: #f56c6c; font-weight: 600; }
.rt-actions { display: flex; align-items: center; gap: 8px; }

.rt-file { display: none; }
</style>
