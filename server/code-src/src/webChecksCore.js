/*
 * 自作の「Web の検査」(段 11-8。2026-11-09)の純粋な部分。node で試す(src/scripts/js/editor.test.js)。
 *
 *   - CSS のクラス名・id を集める(プロジェクトの .css と、HTML の <style>)
 *   - HTML の class="…" / id="…" の中にいるか(補完を出すか)
 *   - HTML の class に、どこにも無いクラス名が無いか(HTML CSS Support の代わり)
 *   - HTMLHint の規則(既定 + .htmlhintrc.json)・ESLint の設定(推奨の規則 + ブラウザの大域変数)
 *
 * HTMLHint・ESLint・IntelliSense for CSS class names・HTML CSS Support の拡張機能は、どれもブラウザの入口が無い
 * (Node 専用)ので入れられない。中身の検査の部品(htmlhint・ESLint の Linter。どちらも MIT)はブラウザで動くので、
 * それを使う自作の拡張機能(webChecks.js)にした
 */

/** CSS の文字列からクラス名と id を集める(コメント・文字列・url() の中は見ない) */
export function collectCssNames(css) {
  const classes = new Set();
  const ids = new Set();
  const text = String(css)
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/"(?:\\.|[^"\\\n])*"|'(?:\\.|[^'\\\n])*'/g, '""')
    .replace(/url\([^)]*\)/gi, 'url()');
  // セレクタの部分だけを見る({ … } の中の宣言は飛ばす。@media などの中のセレクタは見る)
  let selector = '';
  const flush = () => {
    for (const m of selector.matchAll(/\.(-?[_a-zA-Z\u00a0-\uffff][\w\u00a0-\uffff-]*)/g)) {
      classes.add(m[1]);
    }
    for (const m of selector.matchAll(/#(-?[_a-zA-Z\u00a0-\uffff][\w\u00a0-\uffff-]*)/g)) {
      ids.add(m[1]);
    }
    selector = '';
  };
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (ch === '{') {
      if (/^\s*@/.test(selector)) {
        selector = ''; // @media・@supports などは中身にセレクタがある
        continue;
      }
      flush();
      // 宣言の { … } を飛ばす(入れ子の CSS は浅く見る)
      let depth = 1;
      i++;
      for (; i < text.length && depth > 0; i++) {
        if (text[i] === '{') {
          depth++;
        } else if (text[i] === '}') {
          depth--;
        }
      }
      i--;
    } else if (ch === '}' || ch === ';') {
      selector = '';
    } else {
      selector += ch;
    }
  }
  return { classes, ids };
}

/** HTML の <style>…</style> の中身 */
export function styleBlocks(html) {
  return [...String(html).matchAll(/<style\b[^>]*>([\s\S]*?)<\/style\s*>/gi)].map((m) => m[1]);
}

/** HTML がほかのサイトの CSS を読んでいるか(Bootstrap など。そのときは「無いクラス名」を言わない) */
export function hasExternalStylesheet(html) {
  for (const m of String(html).matchAll(/<link\b[^>]*>/gi)) {
    const tag = m[0];
    if (/\brel\s*=\s*["']?[^"'>]*stylesheet/i.test(tag) && /\bhref\s*=\s*["']?\s*(https?:)?\/\//i.test(tag)) {
      return true;
    }
  }
  return /@import\s+(url\()?\s*["']?(https?:)?\/\//i.test(styleBlocks(html).join('\n'));
}

/**
 * 行の offset の位置が class="…" / id="…" の値の中か。
 * @returns {{kind: 'class'|'id', prefix: string}|null} prefix は打ちかけの名前
 */
export function attrContext(lineBefore) {
  const m = /(?:^|\s)(class|id)\s*=\s*(["'])([^"']*)$/i.exec(String(lineBefore));
  if (!m) {
    return null;
  }
  const kind = m[1].toLowerCase() === 'class' ? 'class' : 'id';
  const value = m[3];
  const prefix = kind === 'class' ? value.split(/\s+/).pop() : value;
  return { kind, prefix };
}

/**
 * HTML の class="…" にある、どこにも無いクラス名の場所。テンプレートの記号({{ }}・${ })を含む値は見ない
 * @returns {{name: string, offset: number}[]}
 */
export function unknownClasses(html, known) {
  const out = [];
  const text = String(html);
  // <script>・<style>・コメントの中は見ない(同じ長さの空白に置き換えて場所を保つ)
  const masked = text.replace(/<!--[\s\S]*?-->|<script\b[\s\S]*?<\/script\s*>|<style\b[\s\S]*?<\/style\s*>/gi, (s) => s.replace(/[^\n]/g, ' '));
  for (const tag of masked.matchAll(/<[a-zA-Z][^>]*>/g)) {
    for (const m of tag[0].matchAll(/\sclass\s*=\s*(["'])([^"']*)\1/gi)) {
      const value = m[2];
      if (/[{}$<>]/.test(value)) {
        continue;
      }
      const valueStart = tag.index + m.index + m[0].length - 1 - value.length;
      for (const w of value.matchAll(/\S+/g)) {
        if (!known.has(w[0])) {
          out.push({ name: w[0], offset: valueStart + w.index });
        }
      }
    }
  }
  return out;
}

/** HTMLHint の規則: 既定(htmlhint の defaultRuleset)に、.htmlhintrc.json(JSON)を重ねる。読めなければ既定のまま */
/**
 * 既定から外す規則(2026-11-13、利用者「<a を入力したときのいらないコメントを削除」)。
 * spec-char-escape は、タグを打ちかけた「<a」の < を「エスケープされていない文字」として、打つたびに行の右へ赤い文を出す。
 * .htmlhintrc.json に "spec-char-escape": true と書けば戻せる
 */
export const HTMLHINT_OFF_BY_DEFAULT = ['spec-char-escape'];

export function htmlhintRules(defaults, rcText) {
  const rules = { ...defaults };
  HTMLHINT_OFF_BY_DEFAULT.forEach((id) => delete rules[id]);
  if (typeof rcText !== 'string' || rcText.trim() === '') {
    return { rules, error: null };
  }
  try {
    const rc = JSON.parse(rcText);
    if (!rc || typeof rc !== 'object' || Array.isArray(rc)) {
      return { rules, error: 'object' };
    }
    for (const [k, v] of Object.entries(rc)) {
      if (/^[a-z][a-z0-9-]*$/.test(k) && (typeof v === 'boolean' || typeof v === 'string' || typeof v === 'number' || (v && typeof v === 'object'))) {
        if (v === false) {
          delete rules[k];
        } else {
          rules[k] = v;
        }
      }
    }
    return { rules, error: null };
  } catch {
    return { rules, error: 'json' };
  }
}

/** ESLint で script と module のどちらとして読むか(.mjs・import/export の文があれば module) */
export function sourceTypeOf(path, code) {
  if (/\.mjs$/i.test(path)) {
    return 'module';
  }
  if (/\.cjs$/i.test(path)) {
    return 'commonjs';
  }
  return /^\s*(import\s*[\w{*'"]|export\s+(default|const|let|var|function|class|async|\{|\*))/m.test(String(code)) ? 'module' : 'script';
}
