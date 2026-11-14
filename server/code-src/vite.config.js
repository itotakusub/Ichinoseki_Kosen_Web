/*
 * VS Code 型のエディタ(https://ito4.jp/code/。段 11。2026-11-08)の組み立て。
 *
 *   npm run build   → ../src/code/ に出す(配備するのはこちら。この code-src/ は配備しない)
 *
 * - 部品は @codingame/monaco-vscode-api(VS Code の OSS の本体を npm に分けたもの。MIT)。
 *   monaco-editor・vscode は package.json で同じ版の別名にしてある(editor-src の monaco-editor とは別物)
 * - VS Code の CSS は文字列として読み、本体が adoptedStyleSheets で入れる(作者の例と同じ。<style> を差し込まない)
 * - 入口は src/main.js(殻 ../src/code/index.php)と src/preview-main.js(プレビューの窓 preview.php)。
 *   殻は CSP の nonce のため PHP で、.vite/manifest.json から名前を読む
 */
import { defineConfig } from 'vite';
import { dirname, relative, resolve, sep } from 'node:path';
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import vsixPlugin from '@codingame/monaco-vscode-rollup-vsix-plugin';
import { stripMdnDocs } from '../editor-src/build/strip-mdn-docs.js';

/*
 * 同梱の拡張機能から、ブラウザでは読まないファイルを外す(段 11-5。2026-11-09)。
 *
 * vsix の取り込み部品は、Web 版の入口(package.json の browser)がある拡張機能を「どのファイルを読むか分からない」として
 * 中身を全部出す(Prettier は 14.4MB)。Prettier の Web 版の入口 dist/web-extension.cjs は Prettier 本体と言語の部品を
 * 抱えた 1 つのファイルで、ほかのファイルを読まない(11-09 に中を確かめた: 動的な import・fetch・node_modules の参照なし)。
 * そこで、拡張機能の中の **登録(registerFileUrl)を外し、どこからも指されなくなった出力も消す**。
 * vsix そのものは変えない(Open VSX の sha256 と照合できるまま)。
 *   KEEP: 拡張機能ごとに残すもの。直下のファイル(package.json・翻訳・README・LICENSE・THIRD-PARTY-NOTICES・アイコン)と Web 版の入口
 */
const VSIX_KEEP = {
  // Web 版の入口は Prettier 本体とその部品を抱えるので、そのライセンスの表示(node_modules/prettier の 2 つ)も残す
  'esbenp.prettier-vscode-12.4.0.vsix': (p) => (!p.includes('/') && !/^(CLAUDE\.md|tsconfig.*\.json)$/i.test(p))
    || ['dist/web-extension.cjs', 'node_modules/prettier/LICENSE', 'node_modules/prettier/THIRD-PARTY-NOTICES.md'].includes(p),
  // Path IntelliSense の Web 版の入口 dist/web/extension.js も 1 ファイル(json5・minimatch を抱える。11-09 に中を確かめた: 通信・eval・動的な読み込みなし)
  'christian-kohler.path-intellisense-2.8.0.vsix': (p) => (!p.includes('/') && !p.startsWith('.')) || ['dist/web/extension.js', 'icon/path-intellisense.png'].includes(p),
};
function trimVsix() {
  const dropped = new Set();
  return {
    name: 'km-trim-vsix',
    enforce: 'post',
    transform(code, id) {
      const name = Object.keys(VSIX_KEEP).find((n) => id.endsWith(`/${n}`) || id.endsWith(`\\${n}`));
      if (!name) {
        return null;
      }
      const keep = VSIX_KEEP[name];
      const lines = code.split('\n').filter((line) => {
        const m = /^registerFileUrl\('([^']+)'/.exec(line.trim());
        if (m && !keep(m[1])) {
          dropped.add(`${name}/${m[1]}`);
          return false;
        }
        return true;
      });
      return { code: lines.join('\n'), map: null };
    },
    generateBundle(_options, bundle) {
      if (dropped.size === 0) {
        return;
      }
      // どの JS からも名前で指されていない、その拡張機能の出力を消す
      const code = Object.values(bundle).filter((o) => o.type === 'chunk').map((o) => o.code).join('\n');
      for (const [fileName, out] of Object.entries(bundle)) {
        const owner = Object.keys(VSIX_KEEP).find((n) => fileName.includes(`/${n}/`));
        if (owner && out.type === 'asset' && !code.includes(fileName.slice(fileName.lastIndexOf('/') + 1))) {
          delete bundle[fileName];
        }
      }
    },
  };
}

/*
 * ESLint の Linter(eslint/universal。MIT)をブラウザで動かす(段 11-8。2026-11-09。自作の検査 src/webChecks.js が使う)。
 *
 * - 規則の設定の確かめ(ajv の compile)は new Function で関数を作る。/code/ の CSP は 'unsafe-eval' を許さないので、
 *   確かめを「いつも通す」ものに替える。規則と設定はこちらで決めた推奨のもの(@eslint/js の recommended)だけで、
 *   利用者の eslint.config.js は実行しないので、確かめを外しても困らない
 * - node:path は src/shims/path.js(sep・extname・join だけ使う)へ向ける
 * - 時間を測るかどうかの process.env.TIMING は「測らない」に固定する
 * どれも、形が変わって見つからなければ組み立てを止める(版を上げたときに気づくため)
 */
const ESLINT_PATCHES = [
  { file: 'eslint/lib/config/config.js', from: 'getAjv().compile(schema)', to: 'Object.assign(() => true, { errors: null })', count: 1 },
  { file: 'eslint/lib/linter/timing.js', from: 'process.env.TIMING', to: '(void 0)', count: 4 },
];
function eslintBrowser() {
  const shim = resolve(import.meta.dirname, 'src/shims/path.js');
  return {
    name: 'km-eslint-browser',
    enforce: 'pre',
    resolveId(source, importer) {
      if (!importer || !/node_modules[\\/]eslint[\\/]/.test(importer)) {
        return null;
      }
      if (source === 'node:path' || source === 'path') {
        return shim;
      }
      // esquery は module の版(ESM)が default だけを出す。ESLint は require で parse などを引くので、CJS の版(main)を読む
      // (11-09 に Chrome で「r.parse is not a function」が出て見つけた)
      if (source === 'esquery') {
        return resolve(import.meta.dirname, 'node_modules/esquery/dist/esquery.min.js');
      }
      return null;
    },
    transform(code, id) {
      const norm = id.replace(/\\/g, '/');
      const patch = ESLINT_PATCHES.find((p) => norm.endsWith(`/node_modules/${p.file}`));
      if (!patch) {
        return null;
      }
      const found = code.split(patch.from).length - 1;
      if (found !== patch.count) {
        this.error(`km-eslint-browser: ${patch.file} に「${patch.from}」が ${patch.count} か所あるはずが ${found} か所(ESLint の版が変わった?)`);
      }
      return { code: code.split(patch.from).join(patch.to), map: null };
    },
  };
}

/*
 * HTML の候補の一覧に出る、付属の英語の説明(MDN の文と「MDN Reference」のリンク)を出さない
 * (2026-11-13、利用者「<a を入力したときのいらないコメント(Mozilla のコメント)を削除」)。
 *
 * VS Code 付属の HTML の言語サーバー(htmlServerMain.js)は、カーソルを合わせたときの説明は設定
 * (html.hover.documentation・references)を見るが、**候補の説明は設定を渡さずに作る**ので、設定では消せない。
 * 説明を作る関数は 1 つで、候補からは設定が undefined のまま呼ばれる。そこで「設定が無ければ説明を作らない」に書き換える
 * (カーソルを合わせたときは設定が渡るので変わらない)。
 * 形が変わって見つからなければ組み立てを止める(部品の版を上げたときに気づくため)
 */
/*
 * 2026-11-13(続き): CSS の候補の説明も外す(利用者「CSS の候補も外す」)。CSS の説明の入口は
 * getEntryDescription(項目, markdown か, 設定) の 1 つで、候補からは設定なしで呼ばれる。CSS の言語サーバーと、
 * HTML の言語サーバーの中の CSS(<style> の中)の両方にあるので、両方の部品を書き換える。
 */
const DOC_PATCHES = {
  html: {
    label: 'HTML の説明を作る関数',
    from: /function (\w+)\(h,d=\{\},g\)\{let T=\{kind:g\?"markdown":"plaintext",value:""\};if\(h\.description&&d\.documentation!==!1\)/,
    to: (m, name) => m.replace(`function ${name}(h,d={},g){`, `function ${name}(h,d,g){if(d===void 0)return;`),
  },
  css: {
    label: 'CSS の説明を作る関数',
    from: /function (\w+)\((\w),(\w),(\w)\)\{let (\w);if\(\3\?\5=\{kind:"markdown",value:\w+\(\2,\4\)\}:\5=\{kind:"plaintext",value:\w+\(\2,\4\)\},\5\.value!==""\)return \5\}/,
    to: (m, name, entry, markdown, settings) => m.replace(`function ${name}(${entry},${markdown},${settings}){`, `function ${name}(${entry},${markdown},${settings}){if(${settings}===void 0)return;`),
  },
};
const DOC_TARGETS = [
  { pkg: 'monaco-vscode-html-language-features-default-extension', file: 'htmlServerMain.js', patches: ['html', 'css'] },
  { pkg: 'monaco-vscode-css-language-features-default-extension', file: 'cssServerMain.js', patches: ['css'] },
];
/*
 * 2026-11-14: JS の型の定義(付属の TypeScript の lib.dom.d.ts など)の MDN の説明も外す
 * (利用者「JS で MDN のコメントが出ています。消してください」)。JS の候補の説明・カーソルを合わせたときの説明はここから来る。
 * 外し方は editor-src/build/strip-mdn-docs.js(/editor/ と同じ関数。@deprecated は残す)。
 */
const TS_PKG = 'monaco-vscode-typescript-language-features-default-extension';
const CODINGAME_DIR = resolve(import.meta.dirname, 'node_modules/@codingame');
/** 書き換えた部品の置き場(node_modules の中なので配備も git もしない。組み立てのたびに作り直す) */
const PATCHED_DIR = resolve(import.meta.dirname, 'node_modules/.km-patched');

/*
 * **書き換えは組み立ての前に行う**(2026-11-14 に直した)。
 * 部品は拡張機能の index.js から `registerFileUrl('…', new URL('./resources/<名前>', import.meta.url).toString(), {"mimeType":…,"size":…})`
 * で指されている。書き換えた写しを PATCHED_DIR に作り、その URL をそちらへ向け直す(size も直す)。こうすると Vite が写しの中身で名前のハッシュを付け、
 * それを指す workbench の名前のハッシュも変わる。
 * (11-13 の形は、出来上がったあとで部品の名前を替えて指す所を書き直していた。それだと workbench の中身が変わっても名前が変わらず、
 *  前の workbench を覚えているブラウザが、もう無い古い名前の部品を取りに行って言語の機能が動かなくなるおそれがあった)
 */
function patchedResources() {
  /** `${pkg}/${file}` → { path, size, hits } */
  const replaced = new Map();
  const writeCopy = (pkg, file, text) => {
    const path = resolve(PATCHED_DIR, pkg, file);
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, text);
    replaced.set(`${pkg}/${file}`, { path, size: Buffer.byteLength(text), hits: 0 });
  };
  return {
    name: 'km-patched-resources',
    apply: 'build',
    enforce: 'pre',
    buildStart() {
      replaced.clear();
      rmSync(PATCHED_DIR, { recursive: true, force: true });
      for (const target of DOC_TARGETS) {
        let patched = readFileSync(resolve(CODINGAME_DIR, target.pkg, 'resources', target.file), 'utf8');
        for (const key of target.patches) {
          const patch = DOC_PATCHES[key];
          const found = patched.match(new RegExp(patch.from.source, 'g')) ?? [];
          if (found.length !== 1) {
            this.error(`km-patched-resources: ${target.file} の${patch.label}が ${found.length} か所(1 か所のはず。部品の版が変わった?)`);
          }
          patched = patched.replace(patch.from, patch.to);
        }
        writeCopy(target.pkg, target.file, patched);
      }
      let removed = 0;
      const libDir = resolve(CODINGAME_DIR, TS_PKG, 'resources');
      for (const file of readdirSync(libDir).filter((name) => /^lib\.[\w.]+\.d\.ts\.txt$/.test(name))) {
        const text = readFileSync(resolve(libDir, file), 'utf8');
        if (!text.includes('[MDN Reference](')) continue;
        const result = stripMdnDocs(text);
        if (result.left !== 0) {
          this.error(`km-patched-resources: ${file} に MDN の説明が ${result.left} 個残った(書き方が変わった?)`);
        }
        removed += result.removed;
        writeCopy(TS_PKG, file, result.text);
      }
      if (removed === 0) {
        this.error('km-patched-resources: MDN の説明を持つ型の定義が見つからない(部品の版が変わった?)');
      }
    },
    transform(code, id) {
      const match = id.split('?')[0].match(/[\\/]@codingame[\\/](monaco-vscode-[\w-]+)[\\/]index\.js$/);
      if (!match) return null;
      let out = code;
      let changed = false;
      for (const [key, info] of replaced) {
        if (!key.startsWith(`${match[1]}/`)) continue;
        const file = key.slice(match[1].length + 1);
        let rel = relative(dirname(id.split('?')[0]), info.path).split(sep).join('/');
        if (!rel.startsWith('.')) rel = `./${rel}`;
        const escaped = file.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const pattern = new RegExp(`new URL\\('\\./resources/${escaped}', import\\.meta\\.url\\)\\.toString\\(\\), \\{"mimeType":"([^"]+)","size":\\d+\\}`, 'g');
        out = out.replace(pattern, (_all, mime) => {
          info.hits++;
          changed = true;
          return `new URL('${rel}', import.meta.url).toString(), {"mimeType":"${mime}","size":${info.size}}`;
        });
      }
      return changed ? { code: out, map: null } : null;
    },
    buildEnd(error) {
      if (error) return;
      const missed = [...replaced].filter(([, info]) => info.hits === 0).map(([key]) => key);
      if (missed.length > 0) {
        this.error(`km-patched-resources: 書き換えた部品を指す所が見つからない(index.js の形が変わった?): ${missed.join(', ')}`);
      }
    },
  };
}export default defineConfig({
  base: '/code/',
  publicDir: 'public',
  plugins: [
    // Open VSX から版を固定して取り寄せ、中身を検めた拡張機能(extensions/*.vsix。版と sha256 は extensions/extensions.lock.json)を組み込む
    vsixPlugin(),
    trimVsix(),
    eslintBrowser(),
    patchedResources(),
    {
      name: 'load-vscode-css-as-string',
      enforce: 'pre',
      async resolveId(source, importer, options) {
        const resolved = await this.resolve(source, importer, options);
        if (resolved && /node_modules[\\/](@codingame[\\/]monaco-vscode|vscode|monaco-editor).*\.css$/.test(resolved.id)) {
          return { ...resolved, id: `${resolved.id}?inline` };
        }
        return undefined;
      },
    },
    {
      // 付属の拡張機能の source map(計 59MB)は配らない(開発の道具でしか読まない。無くても動く)
      name: 'drop-extension-source-maps',
      apply: 'build',
      generateBundle(_options, bundle) {
        for (const name of Object.keys(bundle)) {
          if (name.endsWith('.map')) {
            delete bundle[name];
          }
        }
      },
    },
  ],
  build: {
    outDir: resolve(import.meta.dirname, '../src/code'),
    emptyOutDir: true,
    manifest: true,
    target: 'esnext',
    chunkSizeWarningLimit: 20000,
    assetsInlineLimit: 0,
    rollupOptions: {
      // preview: プレビューの窓(preview.php。段 11-3)。VS Code の本体は読まない
      input: { main: resolve(import.meta.dirname, 'src/main.js'), preview: resolve(import.meta.dirname, 'src/preview-main.js') },
    },
  },
  worker: {
    format: 'es',
  },
  experimental: {
    // vsix のファイルの URL は拡張機能ホスト(枠の中の blob の worker)が読む。`/code/assets/…` のままでは
    // そこで解決できない(Failed to fetch)ので、付属の拡張機能と同じく import.meta.url からの完全な URL にする
    renderBuiltUrl(filename, { hostType }) {
      return hostType === 'js' && filename.includes('.vsix/') ? { relative: true } : undefined;
    },
  },
  esbuild: {
    minifySyntax: false,
  },
});
