// test-mode.js
//
// テスト用アカウント(展示の PC。2026-10-15、利用者の指示。lib/test-mode.php)。
// **index.php が印の付いたアカウントのときだけ読み込む。** 一般の閲覧者には届かないので、記録もされない。
//
//   - window.kmTestLog(kind, data) … app.js が操作のたびに呼ぶ(無ければ app.js は何もしない)。
//     溜めて数秒おきに /api/test-events.php へ送る。ページを離れるときは keepalive で送り切る
//   - 「テスト中」の札と操作方法の「－」で小さくする/戻す
//   - 「始めから」… 次の来場者へ渡す。記録を区切り(visit を新しくし)、地図の状態と表示調整を戻して開き直す

(function () {
    'use strict';

    const VISIT_KEY = 'km-test-visit';
    const ENDPOINT = '/api/test-events.php';
    const FLUSH_MS = 3000;
    const BATCH_MAX = 50;   // lib/test-mode.php の KM_TEST_EVENTS_PER_REQUEST と同じ
    /** 来場者が変えたかもしれない、この端末の覚え(表示調整・経路の条件)。「始めから」で消す */
    const VISITOR_STORAGE_KEYS = ['km-map-tuning-v1', 'km-route-vertical-v1', 'km-route-options-v1'];

    const tokenMeta = document.querySelector('meta[name="km-csrf"]');
    const token = tokenMeta ? tokenMeta.getAttribute('content') : '';

    function newVisitId() {
        const bytes = new Uint8Array(8);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    }

    // 来場者の区切り。同じタブで開き直しても続き、「始めから」で新しくなる(sessionStorage はタブごと)
    let visit = null;
    try {
        visit = window.sessionStorage.getItem(VISIT_KEY);
    } catch (e) {
        visit = null;
    }
    if (!visit || !/^[0-9a-f]{16}$/.test(visit)) {
        visit = newVisitId();
        try { window.sessionStorage.setItem(VISIT_KEY, visit); } catch (e) { /* 覚えられなくても記録はできる */ }
    }

    let queue = [];
    let timer = null;

    function send(events, keepalive) {
        if (events.length === 0) return;
        try {
            window.fetch(ENDPOINT, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: !!keepalive,
                headers: { 'Content-Type': 'application/json', 'X-KM-CSRF': token },
                body: JSON.stringify({ visit: visit, events: events }),
            }).catch(function () { /* 送れなくても地図の操作は止めない(記録は失うだけ) */ });
        } catch (e) {
            // 同上
        }
    }

    function flush(keepalive) {
        window.clearTimeout(timer);
        timer = null;
        while (queue.length > 0) {
            send(queue.splice(0, BATCH_MAX), keepalive);
        }
    }

    /**
     * 操作を 1 件積む。data: { field, text, node, node2, floor }(要るものだけ)。
     * 同じ操作が続けて来たとき(同じ部屋を開き直すなど)も、そのまま数える —— 迷った跡も手がかりになる。
     */
    window.kmTestLog = function (kind, data) {
        const event = Object.assign({ kind: kind, t: Date.now() }, data || {});
        if (typeof event.text === 'string') event.text = event.text.trim().slice(0, 100);
        queue.push(event);
        if (queue.length >= BATCH_MAX) {
            flush(false);
        } else if (timer === null) {
            timer = window.setTimeout(function () { flush(false); }, FLUSH_MS);
        }
    };

    // タブを閉じる・移る直前に送り切る
    window.addEventListener('pagehide', function () { flush(true); });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flush(true);
    });

    window.kmTestLog('open', { floor: String(window.currentFloor || '') || undefined });

    function start() {
        // 「－」で小さくする/戻す(見出しだけ残る)
        document.querySelectorAll('[data-km-test-min]').forEach(function (button) {
            button.addEventListener('click', function () {
                const panel = button.closest('.km-test-panel');
                if (!panel) return;
                const minimized = panel.classList.toggle('km-test-minimized');
                button.setAttribute('aria-expanded', minimized ? 'false' : 'true');
                button.textContent = minimized ? '＋' : '－';
            });
        });

        const restart = document.getElementById('km-test-restart');
        if (restart) {
            restart.addEventListener('click', function () {
                window.kmTestLog('restart');
                flush(true);
                try {
                    window.sessionStorage.setItem(VISIT_KEY, newVisitId());
                    VISITOR_STORAGE_KEYS.forEach(function (key) { window.localStorage.removeItem(key); });
                } catch (e) {
                    // 覚えを消せなくても開き直す
                }
                // ?room= などは付けずに最初の画面へ
                window.location.replace('/');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
