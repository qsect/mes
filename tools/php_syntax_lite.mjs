/**
 * 轻量 PHP 静态自检（本地无 PHP 解释器时的兜底手段）
 *
 * 目的：挡住「漏括号 / 引号不闭合 / 注释未闭合」这类最常见的语法错误。
 * 做法：逐字符扫描，跳过单引号串、双引号串、heredoc/nowdoc、// # 与 /* *\/ 注释，
 *       再统计 ( ) { } [ ] 是否配平，并检查 <?php 开头、结尾无闭合标签。
 *
 * 局限：不能替代 php -l（不校验语句结构、类型、分号），部署时仍须跑真正的 php -l。
 *
 * 用法：node tools/php_syntax_lite.mjs <file1.php> [file2.php ...]
 */
import { readFileSync } from 'node:fs';

const files = process.argv.slice(2);
if (files.length === 0) {
  console.error('用法: node tools/php_syntax_lite.mjs <file1.php> [...]');
  process.exit(2);
}

function scan(src) {
  const problems = [];
  const stack = [];
  const pairs = { ')': '(', '}': '{', ']': '[' };
  const opens = { '(': '(', '{': '{', '[': '[' };
  let i = 0;
  const n = src.length;
  let line = 1;

  const push = (ch) => stack.push({ ch, line });
  const pop = (ch) => {
    const top = stack.pop();
    if (!top || top.ch !== pairs[ch]) {
      problems.push(`第 ${line} 行：多余的 "${ch}"${top ? `（期望匹配第 ${top.line} 行的 "${top.ch}"）` : ''}`);
    }
  };

  while (i < n) {
    const c = src[i];
    const c2 = src[i + 1];

    if (c === '\n') { line++; i++; continue; }

    // 行注释
    if (c === '/' && c2 === '/') { while (i < n && src[i] !== '\n') i++; continue; }
    if (c === '#') { while (i < n && src[i] !== '\n') i++; continue; }
    // 块注释
    if (c === '/' && c2 === '*') {
      const startLine = line;
      i += 2;
      let closed = false;
      while (i < n) {
        if (src[i] === '\n') line++;
        if (src[i] === '*' && src[i + 1] === '/') { i += 2; closed = true; break; }
        i++;
      }
      if (!closed) problems.push(`第 ${startLine} 行：块注释未闭合`);
      continue;
    }

    // 单引号字符串
    if (c === "'") {
      const startLine = line;
      i++;
      let closed = false;
      while (i < n) {
        if (src[i] === '\\') { i += 2; continue; }
        if (src[i] === "'") { i++; closed = true; break; }
        if (src[i] === '\n') line++;
        i++;
      }
      if (!closed) problems.push(`第 ${startLine} 行：单引号字符串未闭合`);
      continue;
    }

    // 双引号字符串（含转义与 {$var} 内的括号，一律整体跳过）
    if (c === '"') {
      const startLine = line;
      i++;
      let closed = false;
      while (i < n) {
        if (src[i] === '\\') { i += 2; continue; }
        if (src[i] === '"') { i++; closed = true; break; }
        if (src[i] === '\n') line++;
        i++;
      }
      if (!closed) problems.push(`第 ${startLine} 行：双引号字符串未闭合`);
      continue;
    }

    // heredoc / nowdoc
    if (c === '<' && c2 === '<' && src[i + 2] === '<') {
      let j = i + 3;
      let quote = '';
      if (src[j] === "'" || src[j] === '"') { quote = src[j]; j++; }
      let label = '';
      while (j < n && /[A-Za-z_][A-Za-z0-9_]*/.test(src[j])) { label += src[j]; j++; }
      if (label) {
        // 找到标签后第一个换行，之后直到行首出现结束标签为止
        while (j < n && src[j] !== '\n') j++;
        j++;
        const endNeedle = quote ? `${quote}${label}${quote}` : label;
        const rest = src.slice(j);
        const idx = rest.search(new RegExp(`^${endNeedle}\\b`, 'm'));
        if (idx < 0) {
          problems.push(`heredoc/nowdoc 标识符 ${label} 未闭合（起于第 ${line} 行）`);
          i = n;
        } else {
          const skipped = rest.slice(0, idx);
          line += (skipped.match(/\n/g) || []).length;
          i = j + idx + endNeedle.length;
        }
        continue;
      }
    }

    if (opens[c]) { push(c); i++; continue; }
    if (pairs[c]) { pop(c); i++; continue; }

    i++;
  }

  for (const s of stack) {
    problems.push(`第 ${s.line} 行：括号 "${s.ch}" 未闭合`);
  }
  return problems;
}

let bad = 0;
for (const f of files) {
  let src;
  try {
    src = readFileSync(f, 'utf8');
  } catch (e) {
    console.log(`[读取失败] ${f}: ${e.message}`);
    bad++;
    continue;
  }
  const problems = scan(src);
  if (!src.startsWith('<?php')) problems.push('文件未以 <?php 开头');
  if (/\?>\s*$/.test(src)) problems.push('文件末尾存在 ?> 闭合标签（建议去掉）');
  if (problems.length === 0) {
    console.log(`[OK]   ${f}（${src.split('\n').length} 行）`);
  } else {
    bad++;
    console.log(`[错误] ${f}`);
    problems.forEach((p) => console.log(`        - ${p}`));
  }
}
process.exit(bad > 0 ? 1 : 0);
