/*
 * 複数ファイルのプロジェクトを、プレビュー用の 1 つの文書に組む(段 10。2026-11-08)。**純粋関数だけ**(node で試す)。
 *
 * 枠の中(sandbox・オリジンなし)からはプロジェクトのファイルを URL で取れないので、入口の HTML から指しているものを中身に置き換える:
 *   <link rel=stylesheet href>  → <style>(CSS の @import・url() も)
 *   <script src>                → 中に書く(type=module は data: URL。import './x.js' も data: URL へ書き換える)
 *   <img src>・srcset・<source>・<video>・<audio>・<track>・poster・<link rel=icon> など → data: URL
 *   style="… url(…)"            → data: URL
 * 外の URL(https:・// など)には触らない。
 *
 * 実行中のこと(様子を知らせる台本 = bridge が受け持つ):
 *   fetch('data.json')・XMLHttpRequest  → プロジェクトのファイルなら中身(data: URL)を返す
 *   <a href="about.html"> を押す         → 親へ km-editor-nav を知らせる(親が入口を替えて組み直す)
 *   エラー                              → どのファイルの何行目か(中に書いたスクリプトは区間の表、data: のモジュールは URL の表で引く)
 *
 * files: Map<path, {text?: string, dataUrl?: string, mime: string}>(画像などの dataUrl は呼ぶ側が前もって作る)
 */
import { assembleDocument, escapeScriptText, escapeStyleText, lineOf } from './htmlBuilder.js';
import { isHtml, resolveRef } from '../project/model.js';

export const PROJECT_PREVIEW_MAX_CHARS = 32_000_000;
/** fetch で返せるようにする大きさ(これより大きい画像などは表に入れない。文書が大きくなりすぎないように) */
const FETCH_BINARY_MAX = 1024 * 1024;

const JS_TYPES = new Set(['', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript']);

/** CSS の url('…')・HTML の属性の中に置いても崩れない data: URL */
function safeUrl(url) {
  return url.replace(/'/g, '%27').replace(/\(/g, '%28').replace(/\)/g, '%29').replace(/"/g, '%22');
}

export function textDataUrl(text, mime) {
  return `data:${mime};charset=utf-8,${safeUrl(encodeURIComponent(text))}`;
}

function dataUrlOf(file) {
  if (!file) {
    return null;
  }
  if (file.dataUrl) {
    return file.dataUrl;
  }
  if (typeof file.text === 'string') {
    return textDataUrl(file.text, file.mime || 'text/plain');
  }
  return null;
}

function decodeAttr(value) {
  return String(value).replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
}

function encodeAttr(value) {
  return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
}

const ATTR_RE = /(\s)([^\s"'>\/=]+)(\s*=\s*)("([^"]*)"|'([^']*)'|([^\s"'=<>`]+))/g;

/** 属性の文字列を読む(名前は小文字) */
export function parseAttrs(attrText) {
  const attrs = {};
  for (const m of String(attrText ?? '').matchAll(ATTR_RE)) {
    attrs[m[2].toLowerCase()] = decodeAttr(m[5] ?? m[6] ?? m[7] ?? '');
  }
  return attrs;
}

/** 属性の値を書き換える(fn が null を返したものはそのまま) */
function rewriteAttrs(attrText, fn) {
  return String(attrText ?? '').replace(ATTR_RE, (all, space, name, eq, quoted, dq, sq, bare) => {
    const value = decodeAttr(dq ?? sq ?? bare ?? '');
    const next = fn(name.toLowerCase(), value);
    return next === null || next === undefined ? all : `${space}${name}${eq}"${encodeAttr(next)}"`;
  });
}

function dropAttrs(attrText, names) {
  return String(attrText ?? '').replace(ATTR_RE, (all, space, name) => (names.includes(name.toLowerCase()) ? '' : all));
}

function createContext(files, entry) {
  return {
    files,
    entry,
    warnings: [],
    modules: new Map(),
    moduleStack: new Set(),
    urlMap: {},
    segs: [],
  };
}

// ---- CSS ----

const IMPORT_RE = /@import\s+(?:url\(\s*(['"]?)([^'")]+)\1\s*\)|(['"])([^'"]+)\3)\s*([^;]*);/g;
const URL_RE = /url\(\s*(['"]?)([^'")]*?)\1\s*\)/g;

export function rewriteCss(css, cssPath, ctx, stack = new Set()) {
  const withImports = String(css).replace(IMPORT_RE, (all, q1, u1, q2, u2, media) => {
    const target = resolveRef(cssPath, u1 ?? u2);
    const file = target ? ctx.files.get(target) : null;
    if (!file || typeof file.text !== 'string') {
      return all;
    }
    if (stack.has(target)) {
      ctx.warnings.push({ code: 'css-cycle', path: target });
      return '';
    }
    const inner = rewriteCss(file.text, target, ctx, new Set([...stack, cssPath]));
    const m = String(media ?? '').trim();
    return m === '' ? `/* @import ${target} */\n${inner}\n` : `@media ${m} {\n${inner}\n}\n`;
  });
  return withImports.replace(URL_RE, (all, q, ref) => {
    if (/^data:/i.test(ref)) {
      return all;
    }
    const target = resolveRef(cssPath, ref);
    const url = target ? dataUrlOf(ctx.files.get(target)) : null;
    return url ? `url('${safeUrl(url)}')` : all;
  });
}

// ---- JS モジュール ----

const STATIC_IMPORT_RE = /(\bimport\s*(?:[\w$*{}\s,]+?\s*from\s*)?|\bexport\s*(?:\*(?:\s*as\s+[\w$]+)?|\{[^}]*\})\s*from\s*)(['"])([^'"\n]+)\2/g;
const DYNAMIC_IMPORT_RE = /\bimport\s*\(\s*(['"])([^'"\n]+)\1\s*\)/g;

function isRelativeSpecifier(spec) {
  return spec.startsWith('./') || spec.startsWith('../') || spec.startsWith('/');
}

export function rewriteModule(code, fromPath, ctx) {
  const swap = (spec) => {
    if (!isRelativeSpecifier(spec)) {
      return null;
    }
    const target = resolveRef(fromPath, spec);
    return target ? moduleUrl(target, ctx) : null;
  };
  return String(code)
    .replace(STATIC_IMPORT_RE, (all, head, q, spec) => {
      const url = swap(spec);
      return url ? `${head}${q}${url}${q}` : all;
    })
    .replace(DYNAMIC_IMPORT_RE, (all, q, spec) => {
      const url = swap(spec);
      return url ? `import(${q}${url}${q})` : all;
    });
}

/** プロジェクトの JS を data: URL のモジュールにする(依存を先に。輪になったら書き換えずに残す) */
export function moduleUrl(path, ctx) {
  if (ctx.modules.has(path)) {
    return ctx.modules.get(path);
  }
  const file = ctx.files.get(path);
  if (!file || typeof file.text !== 'string') {
    ctx.warnings.push({ code: 'missing', path });
    return null;
  }
  if (ctx.moduleStack.has(path)) {
    ctx.warnings.push({ code: 'module-cycle', path });
    return null;
  }
  ctx.moduleStack.add(path);
  const code = rewriteModule(file.text, path, ctx);
  ctx.moduleStack.delete(path);
  const url = `data:text/javascript;charset=utf-8,${safeUrl(encodeURIComponent(code))}`;
  ctx.modules.set(path, url);
  ctx.urlMap[url] = path;
  return url;
}

// ---- HTML ----

const SCRIPT_RE = /<script\b([^>]*)>([\s\S]*?)<\/script\s*>/gi;
const STYLE_RE = /<style\b([^>]*)>([\s\S]*?)<\/style\s*>/gi;
const TAG_RE = /<([a-zA-Z][\w-]*)(\s[^<>]*?)?(\/?)>/g;
const URL_TAGS = new Set(['img', 'source', 'video', 'audio', 'track', 'input', 'embed', 'object', 'image', 'use']);

/**
 * @returns {string} 書き換えた HTML。中に書く <script> には data-km-seg="番号" を付け、ctx.segs に
 *          { path, firstLine } を足す(firstLine は元のファイルで「>」がある行。区間の始まりは組んだあとで数える)
 */
export function rewriteHtml(html, htmlPath, ctx) {
  const source = String(html);
  const holds = [];
  const hold = (text) => {
    holds.push(text);
    return `\u0000KM${holds.length - 1}\u0000`;
  };
  const seg = (path, firstLine) => {
    ctx.segs.push({ path, firstLine });
    return ctx.segs.length - 1;
  };

  let out = source.replace(SCRIPT_RE, (all, attrText, content, offset) => {
    const attrs = parseAttrs(attrText);
    const type = String(attrs.type ?? '').trim().toLowerCase();
    if (!JS_TYPES.has(type)) {
      return hold(all); // importmap・JSON・テンプレートなど
    }
    const isModule = type === 'module';
    if ('src' in attrs) {
      const target = resolveRef(htmlPath, attrs.src);
      const file = target ? ctx.files.get(target) : null;
      if (!file || typeof file.text !== 'string') {
        if (target && !ctx.files.has(target)) {
          ctx.warnings.push({ code: 'missing', path: target, from: htmlPath });
        }
        return hold(all);
      }
      if (isModule) {
        const url = moduleUrl(target, ctx);
        return hold(url ? `<script${dropAttrs(attrText, ['src'])} src="${url}"></script>` : all);
      }
      const n = seg(target, 0);
      return hold(`<script${dropAttrs(attrText, ['src', 'defer', 'async'])} data-km-seg="${n}">\n${escapeScriptText(file.text)}\n</script>`);
    }
    const gtAt = offset + all.indexOf('>');
    const n = seg(htmlPath, lineOf(source, gtAt));
    const body = isModule ? rewriteModule(content, htmlPath, ctx) : content;
    return hold(`<script${attrText} data-km-seg="${n}">${body}</script>`);
  });

  out = out.replace(STYLE_RE, (all, attrText, content) => hold(`<style${attrText}>${escapeStyleText(rewriteCss(content, htmlPath, ctx))}</style>`));

  out = out.replace(TAG_RE, (all, tagName, attrText = '', selfClose) => {
    const tag = tagName.toLowerCase();
    const attrs = parseAttrs(attrText);
    if (tag === 'link') {
      const rel = String(attrs.rel ?? '').toLowerCase().split(/\s+/);
      const target = attrs.href ? resolveRef(htmlPath, attrs.href) : null;
      const file = target ? ctx.files.get(target) : null;
      if (rel.includes('stylesheet') && file && typeof file.text === 'string') {
        const media = attrs.media ? ` media="${encodeAttr(attrs.media)}"` : '';
        return hold(`<style data-km-src="${encodeAttr(target)}"${media}>\n${escapeStyleText(rewriteCss(file.text, target, ctx))}\n</style>`);
      }
      if (target && !file && rel.includes('stylesheet')) {
        ctx.warnings.push({ code: 'missing', path: target, from: htmlPath });
      }
      const url = dataUrlOf(file);
      return url ? `<${tagName}${rewriteAttrs(attrText, (name) => (name === 'href' ? url : null))}${selfClose}>` : all;
    }
    const styled = 'style' in attrs && /url\(/i.test(attrs.style);
    if (!URL_TAGS.has(tag) && !styled) {
      return all;
    }
    const next = rewriteAttrs(attrText, (name, value) => {
      if (name === 'style') {
        return /url\(/i.test(value) ? rewriteCss(value, htmlPath, ctx) : null;
      }
      if (!URL_TAGS.has(tag)) {
        return null;
      }
      if (name === 'src' || name === 'poster' || name === 'data' || name === 'href' || name === 'xlink:href') {
        const target = resolveRef(htmlPath, value);
        if (!target) {
          return null;
        }
        const url = dataUrlOf(ctx.files.get(target));
        if (!url) {
          ctx.warnings.push({ code: 'missing', path: target, from: htmlPath });
        }
        return url;
      }
      if (name === 'srcset') {
        return value.split(',').map((part) => {
          const m = /^\s*(\S+)(\s.*)?$/.exec(part);
          if (!m) {
            return part;
          }
          const target = resolveRef(htmlPath, m[1]);
          const url = target ? dataUrlOf(ctx.files.get(target)) : null;
          return url ? `${url}${m[2] ?? ''}` : part;
        }).join(',');
      }
      return null;
    });
    return `<${tagName}${next}${selfClose}>`;
  });

  return out.replace(/\u0000KM(\d+)\u0000/g, (all, i) => holds[Number(i)]);
}

// ---- 様子を知らせる台本 ----

/**
 * 1 行の台本。opts.console・opts.nav は プレビューのとき、opts.fetch はダウンロードでも入れる。
 * 表(JSON)は < を < にして </script> で切れないようにする。
 */
export function projectBridge({ runId = 0, entry = 'index.html', segs = [], urlMap = {}, fetchMap = {}, htmlPaths = [] }, opts = { console: true, nav: true, fetch: true }) {
  const json = (v) => JSON.stringify(v).replace(/</g, '\\u003c').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
  const id = Number.isSafeInteger(runId) ? runId : 0;
  let s = `(function(){var ID=${id},ENTRY=${json(entry)};`
    + 'function R(from,ref){ref=String(ref==null?"":ref).trim();if(!ref||ref.charAt(0)==="#"||ref.slice(0,2)==="//"||/^[A-Za-z][A-Za-z0-9+.-]*:/.test(ref))return null;'
    + 'ref=ref.replace(/[?#].*$/,"");if(!ref)return null;try{ref=decodeURIComponent(ref)}catch(e){}'
    + 'var b=ref.charAt(0)==="/"?[]:from.split("/").slice(0,-1),p=ref.replace(/^\\/+/,"").split("/");'
    + 'for(var i=0;i<p.length;i++){if(p[i]===""||p[i]===".")continue;if(p[i]===".."){if(!b.length)return null;b.pop();continue}b.push(p[i])}return b.length?b.join("/"):null}';
  if (opts.fetch) {
    s += `var F=${json(fetchMap)};`
      + 'if(window.fetch){var of=window.fetch;window.fetch=function(input,init){try{var u=typeof input==="string"?input:(input&&input.url);var p=R(ENTRY,u);if(p&&F[p])return of.call(window,F[p],init)}catch(e){}return of.apply(window,arguments)}}'
      + 'if(window.XMLHttpRequest){var oo=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(m,u){try{var p=R(ENTRY,u);if(p&&F[p]){var a=Array.prototype.slice.call(arguments);a[1]=F[p];return oo.apply(this,a)}}catch(e){}return oo.apply(this,arguments)}}';
  }
  if (opts.console) {
    s += `var P=parent,SEGS=${json(segs)},U=${json(urlMap)};`
      + 'function f(v){try{if(typeof v==="string")return v;if(v instanceof Error)return v.name+": "+v.message;'
      + 'if(typeof v==="function")return "function "+(v.name||"(anonymous)");var t=JSON.stringify(v);return t===undefined?String(v):t}catch(e){return String(v)}}'
      + 'function send(level,args,path,line,col){try{var t=Array.prototype.map.call(args,f).join(" ");if(t.length>4000)t=t.slice(0,4000)+"…";'
      + 'P.postMessage({type:"km-editor-console",runId:ID,level:level,text:t,path:path||"",line:line||0,col:col||0},"*")}catch(e){}}'
      + '["log","info","warn","error","debug"].forEach(function(k){var o=console[k];console[k]=function(){send(k,arguments);if(o)return o.apply(console,arguments)}});'
      + 'function where(file,line){if(file&&U[file])return[U[file],line];for(var i=0;i<SEGS.length;i++){var g=SEGS[i];if(line>=g.start&&line<=g.end)return[g.path,g.firstLine+line-g.start]}return["",0]}'
      + 'addEventListener("error",function(e){var w=where(e.filename,e.lineno||0);send("error",[e.message||"Error"],w[0],w[1],e.colno||0)});'
      + 'addEventListener("unhandledrejection",function(e){send("error",["Uncaught (in promise) "+f(e.reason)])});';
  }
  if (opts.nav) {
    s += `var H=${json(htmlPaths)};`
      + 'document.addEventListener("click",function(e){var a=e.target&&e.target.closest?e.target.closest("a[href]"):null;if(!a)return;'
      + 'var p=R(ENTRY,a.getAttribute("href"));if(p&&H.indexOf(p)>=0){e.preventDefault();try{parent.postMessage({type:"km-editor-nav",runId:ID,path:p},"*")}catch(x){}}},true);';
  }
  return `${s}})();`;
}

/**
 * プロジェクトを 1 つの文書に組む。
 * @param {Map<string, {text?: string, dataUrl?: string, mime: string}>} files
 * @param {string} entry 入口の HTML のパス
 * @param {{runId?: number, lang?: string, mode?: 'preview'|'export'}} options
 * @returns {{text: string, warnings: object[], segs: object[]}}
 */
export function buildProjectDocument(files, entry, options = {}) {
  const mode = options.mode === 'export' ? 'export' : 'preview';
  const entryFile = files.get(entry);
  const ctx = createContext(files, entry);
  const html = rewriteHtml(entryFile && typeof entryFile.text === 'string' ? entryFile.text : '', entry, ctx);

  const fetchMap = {};
  const htmlPaths = [];
  for (const [path, file] of files) {
    if (isHtml(path)) {
      htmlPaths.push(path);
      continue;
    }
    if (typeof file.text === 'string') {
      fetchMap[path] = textDataUrl(file.text, file.mime || 'text/plain');
    } else if (file.dataUrl && file.dataUrl.length <= FETCH_BINARY_MAX * 1.4) {
      fetchMap[path] = file.dataUrl;
    }
  }

  const opts = mode === 'export' ? { console: false, nav: false, fetch: Object.keys(fetchMap).length > 0 } : { console: true, nav: true, fetch: true };
  const assemble = (segTable) => {
    const bridge = projectBridge({ runId: options.runId, entry, segs: segTable, urlMap: ctx.urlMap, fetchMap, htmlPaths }, opts);
    const headTag = opts.console || opts.nav || opts.fetch ? `<script>${bridge}</script>` : '';
    return assembleDocument(html, { headTag, lang: options.lang ?? 'ja' }).text;
  };
  // 1 回目で区間の始まりの行を数え、2 回目で表を台本に入れる(台本は 1 行なので行は変わらない)
  const first = assemble([]);
  const segTable = ctx.segs.map((s, i) => {
    const at = first.indexOf(`data-km-seg="${i}"`);
    if (at < 0) {
      return null;
    }
    const gt = first.indexOf('>', at);
    const start = lineOf(first, gt);
    const close = first.indexOf('</script', gt);
    const end = lineOf(first, close < 0 ? first.length : close);
    return { path: s.path, firstLine: s.firstLine, start, end };
  }).filter(Boolean);
  const text = mode === 'preview' ? assemble(segTable) : first;
  return { text, warnings: ctx.warnings, segs: segTable };
}
