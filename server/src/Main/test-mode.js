// test-mode.js
//
// 操作の記録(lib/test-mode.php)。**index.php が次のどちらかのときだけ読み込む**。それ以外の閲覧者には届かない。
//
//   - テスト用アカウント(展示の PC。2026-10-15、利用者の指示)… <meta name="km-test-mode">
//       「テスト中」の札と操作方法の「－」、「始めから」、触られないまま過ぎたら自動で「始めから」(<meta name="km-test-idle"> 分)
//   - イベント中の来場者(2026-10-15、利用者の指示)… <meta name="km-event-collect">
//       最初の画面で「情報を収集するのを許可します」(チェック済み)。外して OK なら送らない。
//       **決めるまでは送らずに溜める。** 決めないまま閉じたときは、チェックの状態に従う(付いていれば送る)
//
//   window.kmTestLog(kind, data) … app.js が操作のたびに呼ぶ(無ければ app.js は何もしない)。
//   溜めて数秒おきに /api/test-events.php へ送る。ページを離れるときは keepalive で送り切る

(function () {
    'use strict';

    const VISIT_KEY = 'km-test-visit';
    const ENDPOINT = '/api/test-events.php';
    const FLUSH_MS = 3000;
    const BATCH_MAX = 50;   // lib/test-mode.php の KM_TEST_EVENTS_PER_REQUEST と同じ
    /** 来場者が変えたかもしれない、この端末の覚え(表示調整・経路の条件)。「始めから」で消す */
    const VISITOR_STORAGE_KEYS = ['km-map-tuning-v1', 'km-route-vertical-v1', 'km-route-options-v1'];
    /** 自動で「始めから」にする前に、知らせて待つ秒数(「このまま使う」で取り消せる) */
    const IDLE_WARNING_SECONDS = 15;

    function meta(name) {
        const el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') : null;
    }

    const token = meta('km-csrf') || '';
    const testMode = meta('km-test-mode') !== null;
    const eventKey = testMode ? null : meta('km-event-collect');
    if (!testMode && !eventKey) return;

    function storageGet(store, key) {
        try { return store.getItem(key); } catch (e) { return null; }
    }
    function storageSet(store, key, value) {
        try { store.setItem(key, value); } catch (e) { /* 覚えられなくても続ける */ }
    }

    function newVisitId() {
        const bytes = new Uint8Array(8);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    }

    // 来場者の区切り。同じタブで開き直しても続き、「始めから」で新しくなる(sessionStorage はタブごと)
    let visit = storageGet(window.sessionStorage, VISIT_KEY);
    if (!visit || !/^[0-9a-f]{16}$/.test(visit)) {
        visit = newVisitId();
        storageSet(window.sessionStorage, VISIT_KEY, visit);
    }

    /*
     * 送ってよいか。テスト用アカウントは常に(画面の札で知らせている)。
     * イベント中は、許可の覚え(イベントごと)が yes なら送る / no なら記録もしない / まだなら溜めるだけ。
     */
    const CONSENT_KEY = eventKey ? 'km-event-consent:' + eventKey : null;
    let consent = testMode ? 'yes' : (storageGet(window.localStorage, CONSENT_KEY) || 'undecided');
    if (consent !== 'yes' && consent !== 'no') consent = 'undecided';

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
        if (consent !== 'yes') return;   // 決めるまでは溜めるだけ
        while (queue.length > 0) {
            send(queue.splice(0, BATCH_MAX), keepalive);
        }
    }

    /**
     * 操作を 1 件積む。data: { field, text, node, node2, floor }(要るものだけ)。
     * 同じ操作が続けて来たとき(同じ部屋を開き直すなど)も、そのまま数える —— 迷った跡も手がかりになる。
     */
    window.kmTestLog = function (kind, data) {
        if (consent === 'no') return;
        const event = Object.assign({ kind: kind, t: Date.now() }, data || {});
        if (typeof event.text === 'string') event.text = event.text.trim().slice(0, 100);
        queue.push(event);
        if (consent !== 'yes') {
            // 決めるまでの分は、多すぎたら古い方から捨てる(送らないまま溜め続けない)
            if (queue.length > 500) queue.splice(0, queue.length - 500);
            return;
        }
        if (queue.length >= BATCH_MAX) {
            flush(false);
        } else if (timer === null) {
            timer = window.setTimeout(function () { flush(false); }, FLUSH_MS);
        }
    };

    function leaving() {
        // 決めないまま閉じた: 許可のチェックが付いたままなら送る(チェック済みで出しているため)
        if (consent === 'undecided') {
            const box = document.getElementById('km-collect-consent');
            if (box && box.checked) consent = 'yes';
        }
        flush(true);
    }
    window.addEventListener('pagehide', leaving);
    // 別のタブへ移っただけでは「決めた」ことにしない(戻ってきて外すかもしれない)。決めた分だけ送る
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flush(true);
    });

    window.kmTestLog('open', { floor: String(window.currentFloor || '') || undefined });

    // ---- 「始めから」 ----------------------------------------------------------

    function restart(reason) {
        window.kmTestLog('restart', reason ? { text: reason } : undefined);
        flush(true);
        storageSet(window.sessionStorage, VISIT_KEY, newVisitId());
        try {
            VISITOR_STORAGE_KEYS.forEach(function (key) { window.localStorage.removeItem(key); });
        } catch (e) {
            // 覚えを消せなくても開き直す
        }
        // ?room= などは付けずに最初の画面へ
        window.location.replace('/');
    }

    /*
     * 触られないまま [分] が過ぎたら、知らせてから「始めから」(テスト用アカウントだけ)。
     * **1 度も触られていない画面は戻さない** —— 開き直しても同じ画面なので、繰り返すだけになる。
     */
    function startIdleWatch(minutes) {
        if (!(minutes > 0)) return;
        const idleMs = minutes * 60 * 1000;
        let touched = false;
        let idleTimer = null;
        let countdown = null;
        let notice = null;

        function hideNotice() {
            window.clearInterval(countdown);
            countdown = null;
            if (notice) notice.hidden = true;
        }

        function showNotice() {
            if (!notice) {
                notice = document.createElement('div');
                notice.className = 'km-idle-notice';
                notice.setAttribute('role', 'alertdialog');
                notice.setAttribute('aria-live', 'assertive');
                const text = document.createElement('p');
                text.className = 'km-idle-text';
                const keep = document.createElement('button');
                keep.type = 'button';
                keep.className = 'primary-btn km-idle-keep';
                keep.textContent = 'このまま使う';
                keep.addEventListener('click', function () { activity(); });
                notice.append(text, keep);
                document.body.appendChild(notice);
            }
            let left = IDLE_WARNING_SECONDS;
            const text = notice.querySelector('.km-idle-text');
            const render = function () { text.textContent = '操作がないので、' + left + ' 秒後に最初の画面に戻ります。'; };
            render();
            notice.hidden = false;
            notice.querySelector('.km-idle-keep').focus();
            countdown = window.setInterval(function () {
                left -= 1;
                if (left <= 0) {
                    hideNotice();
                    restart('idle');
                    return;
                }
                render();
            }, 1000);
        }

        function arm() {
            window.clearTimeout(idleTimer);
            idleTimer = window.setTimeout(showNotice, idleMs);
        }

        let lastMove = 0;
        function activity(event) {
            // マウスを動かしただけでも「見ている」とみなす(読んでいる最中に戻さない)。動きは間引く
            if (event && event.type === 'pointermove') {
                const now = Date.now();
                if (now - lastMove < 1000) return;
                lastMove = now;
            }
            touched = true;
            if (countdown !== null) hideNotice();
            arm();
        }

        ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart'].forEach(function (type) {
            window.addEventListener(type, function (event) {
                if (!touched && type === 'pointermove') return;   // 開いた直後の、触っていないマウスの揺れは数えない
                activity(event);
            }, { passive: true, capture: true });
        });
        // 触られるまでは数えない(arm しない)
    }

    // ---- イベント中の許可 -------------------------------------------------------

    function setupConsent() {
        const bar = document.getElementById('km-collect-bar');
        const box = document.getElementById('km-collect-consent');
        const ok = document.getElementById('km-collect-ok');
        if (!bar || !box || !ok) return;

        function open() {
            box.checked = consent !== 'no';
            bar.hidden = false;
        }

        ok.addEventListener('click', function () {
            consent = box.checked ? 'yes' : 'no';
            storageSet(window.localStorage, CONSENT_KEY, consent);
            bar.hidden = true;
            if (consent === 'yes') {
                flush(false);
            } else {
                queue = [];   // 許可しないなら、決めるまでに溜めた分も捨てる
            }
        });

        document.querySelectorAll('[data-km-collect-open]').forEach(function (button) {
            button.addEventListener('click', open);
        });

        // 最初の画面で 1 回だけ出す(決めたらイベントの間は覚える)
        if (consent === 'undecided') open();
    }

    function start() {
        if (testMode) {
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

            const restartButton = document.getElementById('km-test-restart');
            if (restartButton) restartButton.addEventListener('click', function () { restart(); });

            startIdleWatch(parseInt(meta('km-test-idle') || '0', 10));
        } else {
            setupConsent();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
