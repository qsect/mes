/**
 * 富文本辅助工具（零依赖）
 *
 * 配合 components/RichEditor.vue 使用，供讨论区发帖 / 回帖做：
 *   1) 内容判空（HTML 里可能只有 <p><br></p> 这类空壳）
 *   2) 纯文本摘要（楼层引用时只截取前 N 字，避免引用嵌套爆炸）
 *   3) 楼层引用块拼接（转义后再包一层 <blockquote>）
 *
 * 说明：本文件只做「读」与「拼」，不做净化——净化统一由
 *      utils/sanitizeHtml.js（前端渲染）与 DiscussController::sanitizeHtml（后端入库）负责。
 */

/**
 * HTML 转纯文本（块级元素之间补换行，避免文字粘连）
 */
export function htmlToText(html) {
  const src = String(html ?? '');
  if (src === '') return '';

  // SSR / 无 DOM 环境：退化为正则去标签
  if (typeof window === 'undefined' || typeof window.DOMParser === 'undefined') {
    return src
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<\/(p|div|li|blockquote|pre|h[1-6]|tr)>/gi, '\n')
      .replace(/<[^>]*>/g, '')
      .replace(/&nbsp;/g, ' ')
      .trim();
  }

  const doc = new DOMParser().parseFromString(src, 'text/html');
  doc.querySelectorAll('br').forEach((el) => el.replaceWith(doc.createTextNode('\n')));
  doc.querySelectorAll('p, div, li, blockquote, pre, h1, h2, h3, h4, h5, h6, tr').forEach((el) => {
    el.appendChild(doc.createTextNode('\n'));
  });
  return (doc.body.textContent || '').replace(/\u00a0/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
}

/**
 * 判断富文本是否为空
 * 规则：可见文字为空 且 不包含图片 ⇒ 视为空（只插了一张图也算有内容）
 */
export function isBlankHtml(html) {
  const src = String(html ?? '');
  if (src === '') return true;
  if (/<img\b/i.test(src)) return false;
  return htmlToText(src).replace(/\s+/g, '') === '';
}

/**
 * HTML 转义（用于把用户昵称 / 引用正文拼进 HTML 字符串）
 */
export function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  }[c]));
}

/**
 * 生成「楼层引用」HTML 片段
 * 只截取纯文本前 maxLen 字，防止多人引用层层套娃把正文撑爆。
 *
 * @param {{author?:string, floor?:number|string, content?:string, maxLen?:number}} opt
 * @returns {string} 可直接写入编辑器的 HTML
 */
export function buildQuoteHtml({ author, floor, content, maxLen = 300 } = {}) {
  let text = htmlToText(content);
  let truncated = false;
  if (text.length > maxLen) {
    text = text.slice(0, maxLen);
    truncated = true;
  }
  const body = escapeHtml(text).replace(/\n/g, '<br>');
  const from = `—— ${escapeHtml(author || '用户')} 发表于第 ${escapeHtml(floor ?? 1)} 楼${truncated ? '（节选）' : ''}`;

  return `<blockquote>${body}<br><span style="color:#909399;font-size:12px;">${from}</span></blockquote><p><br></p>`;
}

export default { htmlToText, isBlankHtml, escapeHtml, buildQuoteHtml };
