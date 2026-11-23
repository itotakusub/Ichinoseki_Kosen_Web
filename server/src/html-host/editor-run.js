/*
 * Web Code Editor のプレビューを走らせる側(2026-11-08。editor-run.html が読む)。
 *
 * つなぎ:
 *   ito4.jp/editor/(親)── postMessage ──▶ ここ(sandbox="allow-scripts"。オリジンなし)
 *                                             └─ <iframe sandbox="allow-scripts" srcdoc=利用者の文書>
 *
 * 受けるもの(親から):  {type: 'km-editor-run', runId: 整数, doc: 文字列(32,000,000 字まで)}
 * 送るもの(親へ):      {type: 'km-editor-ready'}・{type: 'km-editor-console', runId, level, text, path, line, col}
 *                       ・{type: 'km-editor-nav', runId, path}(プロジェクトの中の HTML へのリンクが押された。段 10)
 *   上限は段 10(2026-11-08)で 2,000,000 → 32,000,000 字(画像を data: URL で中に入れるため。1 プロジェクト 20MB)
 *
 * 守り:
 * - 受けるのは**親の窓から**だけ(event.source === window.parent)。形・型・長さを確かめ、違えば捨てる
 * - 親へ送る相手は本体のオリジンだけ(meta km-parent。html-view.php が入れる)。* にしない
 * - 利用者の文書からの知らせは、**いま出している枠から**来たものだけを、形を直してから親へ回す
 * - 文書は毎回新しい枠で開く(前の台本のタイマーや状態を持ち越さない)
 */
(function () {
    'use strict';

    var MAX_CHARS = 32000000;
    var LEVELS = ['log', 'info', 'warn', 'error', 'debug'];
    var parentOrigin = '';
    var meta = document.querySelector('meta[name="km-parent"]');
    if (meta) {
        parentOrigin = String(meta.getAttribute('content') || '');
    }
    if (!/^https:\/\/[A-Za-z0-9.-]+(:\d+)?$/.test(parentOrigin) || window.parent === window) {
        // 親が分からない・枠の外で開かれた: 何もしない
        return;
    }

    var frame = null;
    var currentRun = 0;

    function sendParent(message) {
        try {
            window.parent.postMessage(message, parentOrigin);
        } catch (e) {
            // 親が居なくなった
        }
    }

    function run(runId, doc) {
        if (frame) {
            frame.remove();
        }
        currentRun = runId;
        frame = document.createElement('iframe');
        frame.setAttribute('sandbox', 'allow-scripts');
        frame.setAttribute('title', 'Preview');
        frame.srcdoc = doc;
        document.body.appendChild(frame);
    }

    window.addEventListener('message', function (event) {
        var data = event.data;
        if (!data || typeof data !== 'object') {
            return;
        }
        if (event.source === window.parent) {
            if (data.type !== 'km-editor-run' || typeof data.doc !== 'string' || data.doc.length > MAX_CHARS
                || typeof data.runId !== 'number' || !isFinite(data.runId) || Math.floor(data.runId) !== data.runId) {
                return;
            }
            run(data.runId, data.doc);
            return;
        }
        if (frame && event.source === frame.contentWindow) {
            if (data.runId !== currentRun) {
                return;
            }
            if (data.type === 'km-editor-nav') {
                if (typeof data.path === 'string' && data.path !== '' && data.path.length <= 300) {
                    sendParent({ type: 'km-editor-nav', runId: currentRun, path: data.path });
                }
                return;
            }
            if (data.type !== 'km-editor-console' || LEVELS.indexOf(data.level) < 0 || typeof data.text !== 'string') {
                return;
            }
            sendParent({
                type: 'km-editor-console',
                runId: currentRun,
                level: data.level,
                text: data.text.slice(0, 4001),
                path: typeof data.path === 'string' ? data.path.slice(0, 300) : '',
                line: typeof data.line === 'number' && isFinite(data.line) ? Math.max(0, Math.floor(data.line)) : 0,
                col: typeof data.col === 'number' && isFinite(data.col) ? Math.max(0, Math.floor(data.col)) : 0
            });
        }
    });

    sendParent({ type: 'km-editor-ready' });
})();
