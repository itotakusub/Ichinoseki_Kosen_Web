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
import { resolve } from 'node:path';
import { createHash } from 'node:crypto';
import vsixPlugin from '@codingame/monaco-vscode-rollup-vsix-plugin';

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
 * 部品は出来合いの 1 ファイルで、名前の末尾は元の中身のハッシュ。中身を変えたら名前も変える(古い中身がブラウザに残らないように)。
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
  { name: 'htmlServerMain', patches: ['html', 'css'] },
  { name: 'cssServerMain', patches: ['css'] },
];
function htmlNoCompletionDocs() {
  return {
    name: 'km-html-no-completion-docs',
    apply: 'build',
    enforce: 'post',
    generateBundle(_options, bundle) {
      for (const target of DOC_TARGETS) {
        const pattern = new RegExp(`(^|/)${target.name}-[\\w-]+\\.js$`);
        const entries = Object.entries(bundle).filter(([name, out]) => out.type === 'asset' && pattern.test(name));
        if (entries.length !== 1) {
          this.error(`km-html-no-completion-docs: ${target.name} の出力が ${entries.length} 個(1 個のはず)`);
        }
        const [oldName, asset] = entries[0];
        let patched = typeof asset.source === 'string' ? asset.source : Buffer.from(asset.source).toString('utf8');
        for (const key of target.patches) {
          const patch = DOC_PATCHES[key];
          const found = patched.match(new RegExp(patch.from.source, 'g')) ?? [];
          if (found.length !== 1) {
            this.error(`km-html-no-completion-docs: ${target.name} の${patch.label}が ${found.length} か所(1 か所のはず。部品の版が変わった?)`);
          }
          patched = patched.replace(patch.from, patch.to);
        }
        const hash = createHash('sha256').update(patched).digest('base64url').slice(0, 8);
        const newName = oldName.replace(new RegExp(`${target.name}-[\\w-]+\\.js$`), `${target.name}-${hash}.js`);
        const oldBase = oldName.slice(oldName.lastIndexOf('/') + 1);
        const newBase = newName.slice(newName.lastIndexOf('/') + 1);
        let references = 0;
        for (const out of Object.values(bundle)) {
          if (out.type === 'chunk' && out.code.includes(oldBase)) {
            references += out.code.split(oldBase).length - 1;
            out.code = out.code.split(oldBase).join(newBase);
          } else if (out.type === 'asset' && out !== asset && typeof out.source === 'string' && out.source.includes(oldBase)) {
            references += out.source.split(oldBase).length - 1;
            out.source = out.source.split(oldBase).join(newBase);
          }
        }
        if (references === 0) {
          this.error(`km-html-no-completion-docs: ${oldBase} を指している所が見つかりません`);
        }
        // 出力の表への代入は Rolldown が受けない(無視される)。古い名前を消し、新しい名前で出し直す
        delete bundle[oldName];
        this.emitFile({ type: 'asset', fileName: newName, source: patched });
      }
    },
  };
}
export default defineConfig({
  base: '/code/',
  publicDir: 'public',
  plugins: [
    // Open VSX から版を固定して取り寄せ、中身を検めた拡張機能(extensions/*.vsix。版と sha256 は extensions/extensions.lock.json)を組み込む
    vsixPlugin(),
    trimVsix(),
    eslintBrowser(),
    htmlNoCompletionDocs(),
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
