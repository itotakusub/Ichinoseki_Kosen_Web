/*
 * HTML・CSS・JavaScript を 1 つの文書に組む(2026-11-08。計画書 5-3)。**純粋関数だけ**(node で試す)。
 *
 * - HTML は**断片でも完全な文書でもよい**。<head>・<body>・<html> があればそこへ差し込み、無ければ包む
 * - CSS は <head> の終わりに <style>、JS は <body> の終わりに <script>(DOM ができてから動く)
 * - 中身の `</style` `</script` は `<\/…` に直す(要素が途中で閉じて、残りが HTML として読まれないように)
 * - <head> の初めに「様子を知らせる台本」(console・エラーを走らせる側へ送る。下の consoleBridge)を置く。
 *   利用者の台本より先に動かすため
 * - JS の 1 行目が文書の何行目かを数えて台本に渡す(エラーの行番号を、JS の欄の行番号に直すため)
 *
 * 組んだ文書は別のオリジン(html.<ドメイン>/editor-run.html)の、さらに sandbox の枠の中で動く。
 * ここで何かを「安全にする」のではない(守りは枠とオリジン)。崩れずに組むことだけを受け持つ。
 */
export const PREVIEW_MAX_CHARS = 2_000_000;

export function escapeStyleText(css) {
  return String(css ?? '').replace(/<\/(style)/gi, '<\\/$1');
}

export function escapeScriptText(js) {
  return String(js ?? '')
    .replace(/<\/(script)/gi, '<\\/$1')
    .replace(/<!--/g, '<\\!--');
}

/** 文字の位置 index が何行目か(1 から) */
export function lineOf(text, index) {
  let line = 1;
  for (let i = 0; i < index && i < text.length; i++) {
    if (text.charCodeAt(i) === 10) {
      line++;
    }
  }
  return line;
}

/**
 * 走らせる文書の中で console とエラーを拾い、親(走らせる側)へ送る台本。
 * 利用者の台本が console を差し替えても困らないよう、最初に元を握っておく。
 * 送るのは文字だけ(長さは 4000 まで)。相手の確かめは走らせる側と親がする。
 */
export function consoleBridge(runId, jsStartLine) {
  const id = Number.isSafeInteger(runId) ? runId : 0;
  const start = Number.isSafeInteger(jsStartLine) ? jsStartLine : 0;
  return `(function(){var P=parent,ID=${id},JS=${start};`
    + 'function f(v){try{if(typeof v==="string")return v;if(v instanceof Error)return v.name+": "+v.message;'
    + 'if(typeof v==="function")return "function "+(v.name||"(anonymous)");var s=JSON.stringify(v);return s===undefined?String(v):s}catch(e){return String(v)}}'
    + 'function send(level,args,line,col){try{var t=Array.prototype.map.call(args,f).join(" ");if(t.length>4000)t=t.slice(0,4000)+"…";'
    + 'P.postMessage({type:"km-editor-console",runId:ID,level:level,text:t,line:line||0,col:col||0},"*")}catch(e){}}'
    + '["log","info","warn","error","debug"].forEach(function(k){var o=console[k];console[k]=function(){send(k,arguments);if(o)return o.apply(console,arguments)}});'
    + 'addEventListener("error",function(e){var l=e.lineno||0;if(JS&&l>=JS)l=l-JS+1;else l=0;send("error",[e.message||"Error"],l,e.colno||0)});'
    + 'addEventListener("unhandledrejection",function(e){send("error",["Uncaught (in promise) "+f(e.reason)])});'
    + '})();';
}

const HEAD_OPEN = /<head(\s[^>]*)?>/i;
const HTML_OPEN = /<html(\s[^>]*)?>/i;
const BODY_OPEN = /<body(\s[^>]*)?>/i;

function insertAt(text, index, piece) {
  return text.slice(0, index) + piece + text.slice(index);
}

function safeLang(value) {
  return /^[a-z]{2}(-[A-Za-z0-9]+)?$/.test(value ?? '') ? value : 'ja';
}

export function isFullDocument(html) {
  return HEAD_OPEN.test(html) || BODY_OPEN.test(html) || HTML_OPEN.test(html);
}

/**
 * 文書を組む(プレビューとダウンロードで同じ置き方にする)。
 * headTag は <head> の初め、styleTag は </head> の前、scriptTag は最後の </body> の前。空なら置かない。
 * @returns {{text: string, scriptAt: number}} scriptAt は scriptTag を置いた位置(置かなければ -1)
 */
export function assembleDocument(html, { headTag = '', styleTag = '', scriptTag = '', lang = 'ja', title = '' }) {
  let text;
  let scriptAt = -1;
  if (!isFullDocument(html)) {
    const titleTag = title === '' ? '' : `<title>${title.replace(/[<>&"]/g, '')}</title>\n`;
    text = `<!DOCTYPE html>\n<html lang="${safeLang(lang)}">\n<head>\n<meta charset="utf-8">\n`
      + '<meta name="viewport" content="width=device-width, initial-scale=1">\n'
      + titleTag
      + (headTag === '' ? '' : `${headTag}\n`)
      + (styleTag === '' ? '' : `${styleTag}\n`)
      + `</head>\n<body>\n${html}\n`;
    if (scriptTag !== '') {
      scriptAt = text.length;
      text += `${scriptTag}\n`;
    }
    return { text: `${text}</body>\n</html>\n`, scriptAt };
  }
  text = html;
  // 1. <head> の直後(無ければ <body> の前・<html> の直後・先頭のどれか)
  if (headTag !== '') {
    const m = HEAD_OPEN.exec(text);
    if (m) {
      text = insertAt(text, m.index + m[0].length, `\n${headTag}`);
    } else {
      const piece = `<head>\n${headTag}\n</head>\n`;
      const b = BODY_OPEN.exec(text);
      const h = HTML_OPEN.exec(text);
      if (b) {
        text = insertAt(text, b.index, piece);
      } else if (h) {
        text = insertAt(text, h.index + h[0].length, `\n${piece}`);
      } else {
        text = piece + text;
      }
    }
  }
  // 2. CSS: 最初の </head> の前
  if (styleTag !== '') {
    const close = text.search(/<\/head\s*>/i);
    text = close >= 0 ? insertAt(text, close, `${styleTag}\n`) : `${styleTag}\n${text}`;
  }
  // 3. JS: 最後の </body> の前(無ければ </html> の前、それも無ければ末尾)
  if (scriptTag !== '') {
    const lower = text.toLowerCase();
    let at = lower.lastIndexOf('</body');
    if (at < 0) {
      at = lower.lastIndexOf('</html');
    }
    if (at < 0) {
      at = text.length;
    }
    text = insertAt(text, at, `${scriptTag}\n`);
    scriptAt = at;
  }
  return { text, scriptAt };
}

/**
 * プレビューの文書。
 * @param {{html: string, css: string, js: string}} doc
 * @param {{runId?: number, lang?: string}} options
 * @returns {{text: string, jsStartLine: number}}
 */
export function buildPreviewDocument(doc, options = {}) {
  const html = String(doc?.html ?? '');
  const css = escapeStyleText(doc?.css);
  const js = escapeScriptText(doc?.js);
  const runId = Number.isSafeInteger(options.runId) ? options.runId : 0;
  const lang = safeLang(options.lang);
  const styleTag = css === '' ? '' : `<style>\n${css}\n</style>`;
  const scriptTag = js === '' ? '' : `<script>\n${js}\n</script>`;

  // 2 回組む: 1 回目で JS の始まる行を数え、2 回目でその行を台本に入れる(台本は 1 行なので行数は変わらない)
  const assemble = (start) => {
    const { text, scriptAt } = assembleDocument(html, {
      headTag: `<script>${consoleBridge(runId, start)}</script>`, styleTag, scriptTag, lang,
    });
    // JS の 1 行目 = <script> の次の行
    return { text, jsStartLine: scriptAt >= 0 ? lineOf(text, scriptAt) + 1 : 0 };
  };
  return assemble(assemble(0).jsStartLine);
}

/**
 * ダウンロードの文書(様子を知らせる台本は入れない)。
 * mode 'single' … CSS と JS を中に入れた 1 つの HTML
 * mode 'split'  … style.css・script.js を読む index.html(CSS・JS が空なら読まない)
 */
export function buildExportDocument(doc, { mode = 'single', lang = 'ja', title = 'Web Code Editor' } = {}) {
  const html = String(doc?.html ?? '');
  const css = String(doc?.css ?? '');
  const js = String(doc?.js ?? '');
  if (mode === 'split') {
    return assembleDocument(html, {
      styleTag: css.trim() === '' ? '' : '<link rel="stylesheet" href="style.css">',
      scriptTag: js.trim() === '' ? '' : '<script src="script.js"></script>',
      lang,
      title,
    }).text;
  }
  return assembleDocument(html, {
    styleTag: css.trim() === '' ? '' : `<style>\n${escapeStyleText(css)}\n</style>`,
    scriptTag: js.trim() === '' ? '' : `<script>\n${escapeScriptText(js)}\n</script>`,
    lang,
    title,
  }).text;
}
