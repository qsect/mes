#!/usr/bin/env node
/**
 * Vue 单文件组件（SFC）静态自检
 *
 * 用途：改完 .vue 后、跑完整 vite build 之前，先快速验证语法与 <script setup> 是否可编译。
 * 只依赖项目里已有的 @vue/compiler-sfc，不需要起构建（构建一次要十几秒，改样式/改模板时太慢）。
 *
 * 用法：
 *   node tools/vue_sfc_check.mjs frontend/src/views/foo/Index.vue [更多文件...]
 *   node tools/vue_sfc_check.mjs --glob "frontend/src/views/**\/*.vue"
 *
 * 检查项：
 *   1. parse()：模板/SCRIPT/样式分块能否被正确切分（标签未闭合、写错 <script> 位置等会在此暴露）
 *   2. compileScript()：<script setup> 宏与语法编译
 *   3. compileTemplate()：模板表达式编译（含 v-bind / v-on 内联箭头函数等写法）
 *
 * 退出码：全部通过 0，任一文件失败 1。
 */
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { createRequire } from 'node:module';

// @vue/compiler-sfc 装在 frontend/node_modules 里，而脚本位于 tools/，
// 直接 import 会解析失败，这里显式以 frontend/package.json 为基准去 require。
const frontendPkg = join(process.cwd(), 'frontend', 'package.json');
const require = createRequire(existsSync(frontendPkg) ? frontendPkg : join(process.cwd(), 'index.js'));
const { parse, compileScript, compileTemplate } = require('@vue/compiler-sfc');

const args = process.argv.slice(2);
if (!args.length) {
  console.error('用法: node tools/vue_sfc_check.mjs <file.vue> [file.vue ...]');
  process.exit(1);
}

function walkPatterns(list) {
  const out = [];
  for (const p of list) {
    if (!p.includes('*')) {
      out.push(p);
      continue;
    }
    // 取通配符之前的部分作为遍历根，够用且不必引入 glob 依赖
    const parts = p.split('/');
    const base = [];
    for (const seg of parts) {
      if (seg.includes('*')) break;
      base.push(seg);
    }
    const root = base.join('/') || '.';
    if (!existsSync(root)) continue;
    const rx = new RegExp(
      '^' + p.split('/').map((s) => (s === '**' ? '.*' : s.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '[^/]*'))).join('/') + '$'
    );
    const stack = [root];
    while (stack.length) {
      const dir = stack.pop();
      for (const name of readdirSync(dir)) {
        const full = join(dir, name).replace(/\\/g, '/');
        const st = statSync(full);
        if (st.isDirectory()) {
          stack.push(full);
        } else if (rx.test(full)) {
          out.push(full);
        }
      }
    }
  }
  return [...new Set(out)];
}

const files = walkPatterns(args);
let failed = 0;

for (const file of files) {
  let source;
  try {
    source = readFileSync(file, 'utf-8');
  } catch (e) {
    console.error(`[缺失] ${file}: ${e.message}`);
    failed++;
    continue;
  }

  const id = 'sfc-check';
  try {
    const { descriptor, errors } = parse(source, { filename: file });
    if (errors.length) {
      throw new Error(errors.map((e) => (e.message || String(e))).join('; '));
    }

    if (descriptor.script || descriptor.scriptSetup) {
      compileScript(descriptor, { id });
    }

    if (descriptor.template) {
      const res = compileTemplate({
        id,
        filename: file,
        source: descriptor.template.content,
        compilerOptions: { bindingMetadata: descriptor.scriptSetup ? undefined : undefined }
      });
      if (res.errors && res.errors.length) {
        throw new Error(res.errors.map((e) => (e.message || String(e))).join('; '));
      }
    }

    console.log(`[OK]   ${file}（${source.split('\n').length} 行）`);
  } catch (e) {
    console.error(`[失败] ${file}\n       ${e.message}`);
    failed++;
  }
}

console.log(failed ? `\n共 ${files.length} 个文件，${failed} 个失败` : `\n全部通过（${files.length} 个文件）`);
process.exit(failed ? 1 : 0);
