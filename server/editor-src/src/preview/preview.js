/*
 * プレビューの枠とのやり取り(2026-11-08。段 10 で複数ファイルにした)。
 *
 *   このページ ── postMessage ──▶ <iframe sandbox="allow-scripts" src="html.<ドメイン>/editor-run.html">
 *                                     └ さらに中の sandbox の枠で、組んだ文書を動かす(editor-run.js)
 *
 * - 走らせる側は sandbox でオリジンを持たない(event.origin は "null")。なので相手の確かめは
 *   **event.source がいま置いている枠の窓か**で行う。送るときの宛先も '*' しか書けない
 *   (送るのは利用者が自分で書いたものだけ)
 * - 走らせる側が「用意できた」(km-editor-ready)と言うまでは送らず、最後の 1 つだけ覚えておく
 * - console・移動の知らせは runId が今の回と同じものだけを受ける(前の回の台本のタイマーなどを出さない)
 * - 組み立ては呼ぶ側(main.js が projectBuilder で)。ここは runId を配り、大きさを確かめて送るだけ
 */
import { PROJECT_PREVIEW_MAX_CHARS } from './projectBuilder.js';

const LEVELS = ['log', 'info', 'warn', 'error', 'debug'];

export function createPreview({ frame, runUrl, onConsole, onReady, onNav }) {
  let ready = false;
  let pending = null;
  let runId = 0;

  function post(message) {
    try {
      frame.contentWindow?.postMessage(message, '*');
    } catch {
      // 枠が無くなった
    }
  }

  function load() {
    ready = false;
    frame.src = runUrl;
  }

  window.addEventListener('message', (event) => {
    if (event.source !== frame.contentWindow) {
      return;
    }
    const data = event.data;
    if (!data || typeof data !== 'object') {
      return;
    }
    if (data.type === 'km-editor-ready') {
      ready = true;
      onReady?.();
      if (pending) {
        const next = pending;
        pending = null;
        post(next);
      }
      return;
    }
    if (data.runId !== runId) {
      return;
    }
    if (data.type === 'km-editor-console' && typeof data.text === 'string' && LEVELS.includes(data.level)) {
      onConsole?.({
        level: data.level,
        text: data.text.slice(0, 4001),
        path: typeof data.path === 'string' ? data.path.slice(0, 300) : '',
        line: Number.isSafeInteger(data.line) && data.line > 0 ? data.line : 0,
        col: Number.isSafeInteger(data.col) && data.col > 0 ? data.col : 0,
      });
    } else if (data.type === 'km-editor-nav' && typeof data.path === 'string' && data.path.length <= 300) {
      onNav?.(data.path);
    }
  });

  /**
   * @param {(runId: number) => {text: string}} build
   * @returns {{ok: boolean, reason?: string, built?: object}}
   */
  function run(build) {
    runId += 1;
    const built = build(runId);
    if (built.text.length > PROJECT_PREVIEW_MAX_CHARS) {
      return { ok: false, reason: 'too-large', built };
    }
    const message = { type: 'km-editor-run', runId, doc: built.text };
    if (ready) {
      post(message);
    } else {
      pending = message;
    }
    return { ok: true, built };
  }

  load();
  return { run, reload: load, isReady: () => ready };
}
