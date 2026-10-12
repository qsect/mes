/**
 * 富文本辅助函数单元测试（零依赖，直接用 node 运行）
 *
 *   node frontend/tests/richText.test.mjs
 *
 * 说明：node 环境下没有 window/DOMParser，htmlToText 会走「正则去标签」的
 *       退化分支 —— 这也正是构建产物在 SSR / 测试环境下的实际行为，测它有意义。
 */

import { htmlToText, isBlankHtml, escapeHtml, buildQuoteHtml } from '../src/utils/richText.js';

let passed = 0;
let failed = 0;
const failures = [];

function t(name, fn) {
  try {
    fn();
    passed++;
    console.log(`  [PASS] ${name}`);
  } catch (e) {
    failed++;
    failures.push([name, e.message]);
    console.log(`  [FAIL] ${name}\n         -> ${e.message}`);
  }
}

function eq(expected, actual, hint = '') {
  if (expected !== actual) {
    throw new Error(
      `期望 ${JSON.stringify(expected)}，实际 ${JSON.stringify(actual)}${hint ? `（${hint}）` : ''}`
    );
  }
}

function ok(actual, hint = '') {
  if (!actual) throw new Error(`期望为真值，实际 ${JSON.stringify(actual)}${hint ? `（${hint}）` : ''}`);
}

// ---------- escapeHtml ----------

t('escapeHtml：转义 & < > " \'', () => {
  eq('&amp;&lt;&gt;&quot;&#39;', escapeHtml('&<>"\''));
});

t('escapeHtml：普通文本保持不变、空值安全', () => {
  eq('hello', escapeHtml('hello'));
  eq('', escapeHtml(null));
  eq('', escapeHtml(undefined));
});

// ---------- htmlToText ----------

t('htmlToText：剥离标签保留文字', () => {
  eq('hello world', htmlToText('<p>hello world</p>'));
});

t('htmlToText：块级标签之间补换行，避免文字粘连', () => {
  eq('a\nb', htmlToText('<p>a</p><p>b</p>'));
});

t('htmlToText：<br> 转为换行', () => {
  eq('a\nb', htmlToText('a<br>b'));
});

t('htmlToText：空值与纯空白输入安全', () => {
  eq('', htmlToText(''));
  eq('', htmlToText(null));
  eq('', htmlToText('   '));
});

// ---------- isBlankHtml ----------

t('isBlankHtml：空串 / 空壳标签视为空', () => {
  eq(true, isBlankHtml(''));
  eq(true, isBlankHtml(null));
  eq(true, isBlankHtml('<p><br></p>'));
  eq(true, isBlankHtml('<p>&nbsp;&nbsp;</p>'));
});

t('isBlankHtml：有可见文字视为非空', () => {
  eq(false, isBlankHtml('<p>hi</p>'));
});

t('isBlankHtml：只插图片也视为有内容', () => {
  eq(false, isBlankHtml('<p><img src="x.png"></p>'));
});

// ---------- buildQuoteHtml ----------

t('buildQuoteHtml：生成 blockquote 且包含作者与楼层', () => {
  const html = buildQuoteHtml({ author: '张三', floor: 3, content: '<p>原始内容</p>' });
  ok(html.includes('<blockquote>'), '应包含 blockquote');
  ok(html.includes('张三'), '应包含作者昵称');
  ok(html.includes('第 3 楼'), '应包含楼层号');
  ok(html.includes('原始内容'), '应包含被引用正文');
});

t('buildQuoteHtml：作者名应被转义（防注入）', () => {
  const html = buildQuoteHtml({ author: '<img src=x onerror=alert(1)>', floor: 1, content: 'x' });
  ok(!html.includes('<img src=x'), '作者名中的标签必须被转义');
  ok(html.includes('&lt;img'), '应输出转义后的实体');
});

t('buildQuoteHtml：超长正文被截断并标注（节选）', () => {
  const long = 'A'.repeat(400);
  const html = buildQuoteHtml({ author: 'a', floor: 2, content: long });
  ok(html.includes('（节选）'), '超长引用应标注节选');
});

t('buildQuoteHtml：默认参数安全（不传参不报错）', () => {
  const html = buildQuoteHtml();
  ok(typeof html === 'string' && html.includes('<blockquote>'), '应返回合法 HTML 字符串');
});

// ---------- 汇总 ----------

console.log(`\n==== 结果 ====\n通过 ${passed} 项，失败 ${failed} 项`);
if (failed > 0) {
  console.log('\n失败明细：');
  for (const [name, msg] of failures) console.log(`  - ${name}\n    ${msg}`);
  process.exit(1);
}
console.log('全部通过');
