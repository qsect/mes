/**
 * 轻量 HTML 净化工具（零依赖）
 *
 * 用途：所有 ``v-html`` 渲染点统一改用 ``v-safe-html`` 指令（见 main.js），
 *      避免讨论区 / 公告等用户可编辑内容造成存储型 XSS。
 *
 * 策略：DOMParser 解析 → 标签白名单 → 属性白名单 → 危险协议与事件属性剔除。
 *      不使用正则直接改写 HTML，避免绕过；浏览器不可用时退化为纯文本。
 */

// 允许保留的标签
const ALLOWED_TAGS = new Set([
  'P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'STRIKE', 'DEL', 'INS', 'MARK',
  'UL', 'OL', 'LI', 'A', 'IMG', 'CODE', 'PRE', 'BLOCKQUOTE',
  'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
  'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TD', 'TH', 'CAPTION', 'COLGROUP', 'COL',
  'SPAN', 'DIV', 'HR', 'FIGURE', 'FIGCAPTION', 'FONT', 'CENTER'
]);

// 整体丢弃（含其子节点）的标签——保留会带来脚本执行 / 样式覆盖风险
const DROP_TAGS = new Set([
  'SCRIPT', 'STYLE', 'IFRAME', 'FRAME', 'FRAMESET', 'OBJECT', 'EMBED',
  'LINK', 'META', 'BASE', 'FORM', 'TITLE', 'NOSCRIPT', 'TEMPLATE', 'SVG', 'MATH'
]);

// 按标签允许的属性
const ALLOWED_ATTRS = {
  A: ['href', 'title', 'target', 'rel'],
  IMG: ['src', 'alt', 'title', 'width', 'height'],
  TD: ['colspan', 'rowspan', 'align', 'valign'],
  TH: ['colspan', 'rowspan', 'align', 'valign'],
  COL: ['span', 'width'],
  COLGROUP: ['span', 'width'],
  FONT: ['color', 'face', 'size'],
  '*': ['class', 'style', 'title', 'align', 'width', 'height']
};

// 允许的链接/资源协议（其余一律剔除属性）
const SAFE_URI = /^(https?:|mailto:|tel:|\/|#|\.\/|\.\.\/)/i;

// style 中的危险片段
const UNSAFE_STYLE = /expression\s*\(|javascript:|vbscript:|-moz-binding|@import/i;

function sanitizeElement(el, doc) {
  const tag = el.tagName.toUpperCase();

  // 危险标签：整体删除
  if (DROP_TAGS.has(tag)) {
    el.remove();
    return;
  }

  // 非白名单标签：清洗其子树后，用子节点替换自身（保留文本与合法内容）
  if (!ALLOWED_TAGS.has(tag)) {
    sanitizeChildren(el, doc);
    const frag = doc.createDocumentFragment();
    while (el.firstChild) frag.appendChild(el.firstChild);
    if (el.parentNode) el.parentNode.replaceChild(frag, el);
    return;
  }

  // 过滤属性
  const allowed = new Set([...(ALLOWED_ATTRS[tag] || []), ...(ALLOWED_ATTRS['*'] || [])]);
  for (const attr of Array.from(el.attributes)) {
    const name = attr.name.toLowerCase();
    const value = attr.value;

    // 事件属性 / 危险属性
    if (name.startsWith('on') || name === 'srcdoc' || name === 'formaction' || name === 'xlink:href') {
      el.removeAttribute(attr.name);
      continue;
    }
    if (!allowed.has(name)) {
      el.removeAttribute(attr.name);
      continue;
    }
    // 链接与资源协议白名单
    if (name === 'href' || name === 'src') {
      const raw = value.trim();
      if (!SAFE_URI.test(raw)) {
        el.removeAttribute(attr.name);
        continue;
      }
    }
    // style 兜底
    if (name === 'style' && UNSAFE_STYLE.test(value)) {
      el.removeAttribute(attr.name);
      continue;
    }
  }

  // 外链新窗口：补 noopener 防反向控制
  if (tag === 'A' && el.getAttribute('target') === '_blank') {
    el.setAttribute('rel', 'noopener noreferrer');
  }

  sanitizeChildren(el, doc);
}

function sanitizeChildren(parent, doc) {
  // 快照后再遍历，避免边遍历边修改
  for (const child of Array.from(parent.childNodes)) {
    if (child.nodeType === 8) {          // 注释节点
      parent.removeChild(child);
      continue;
    }
    if (child.nodeType === 1) {          // 元素节点
      sanitizeElement(child, doc);
    }
    // 文本节点保持原样
  }
}

/**
 * 净化 HTML 字符串
 * @param {*} input 任意输入（null/undefined 返回空串）
 * @returns {string} 安全的 HTML 片段
 */
export function sanitizeHtml(input) {
  if (input === null || input === undefined) return '';
  const html = String(input);
  if (html === '') return '';

  // SSR / 无 DOM 环境：退化为纯文本，避免出现未净化内容
  if (typeof window === 'undefined' || typeof window.DOMParser === 'undefined') {
    return html.replace(/[<>]/g, '');
  }

  try {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    sanitizeChildren(doc.body, doc);
    return doc.body.innerHTML;
  } catch (e) {
    // 解析异常时保守处理：转义尖括号
    return html.replace(/[<>]/g, '');
  }
}

export default sanitizeHtml;
