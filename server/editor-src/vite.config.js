/*
 * Web Code Editor(https://ito4.jp/editor/)の組み立て。2026-11-08。
 *
 *   npm run build   → ../src/editor/ に出す(配備するのはこちら。この editor-src/ は配備しない)
 *   npm run dev     → 手元で試す(index.html。プレビューは EDITOR_RUN_URL の別オリジンが要る)
 *
 * - 入口は src/main.js だけ。ページの殻は ../src/editor/index.php(CSP の nonce を付けるため PHP)。
 *   殻は .vite/manifest.json から入口の JS と CSS の名前を読む
 * - Monaco の worker は同じオリジンのファイルにする(CSP は worker-src 'self'。blob: は使わない)
 * - 出力を消して作り直す(emptyOutDir)。index.php は public/ に置いて一緒に出す
 */
import { defineConfig } from 'vite';
import { resolve } from 'node:path';

export default defineConfig({
  base: '/editor/',
  publicDir: 'public',
  resolve: {
    // monaco-editor の exports は .js しか通さない(codicon.css などを読めない)ので、ESM の置き場へ直接の別名を付ける
    // monaco-external は HTML・CSS の標準データ(補完の土台。languages.js が別のファイルとして読む)
    alias: {
      'monaco-esm': resolve(import.meta.dirname, 'node_modules/monaco-editor/esm/vs'),
      'monaco-external': resolve(import.meta.dirname, 'node_modules/monaco-editor/esm/external'),
    },
  },
  build: {
    outDir: resolve(import.meta.dirname, '../src/editor'),
    emptyOutDir: true,
    manifest: true,
    target: 'es2022',
    // Monaco は大きい(数 MB)。警告の線だけ上げる
    chunkSizeWarningLimit: 6000,
    assetsInlineLimit: 0,
    rollupOptions: {
      input: { main: resolve(import.meta.dirname, 'src/main.js') },
    },
  },
  worker: {
    format: 'es',
  },
});
