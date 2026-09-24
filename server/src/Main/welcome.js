// welcome.js
//
// 「はじめに」(index.php の #km-welcome)の開け閉て。**初めて開いたときに 1 回だけ出す。**
//
// 見たかどうかは閲覧者の手元に覚える(localStorage)。サーバーへは送らない ——
// 誰がいつ読んだかを集める理由が無い。
//
// **覚えられない環境でも地図は使えること。** プライベートウィンドウなどで保存に失敗したら、
// その画面を閉じるまでは出し直さない(開くたびに毎回出るのは、読んだ人には邪魔なだけ)。

(function () {
    'use strict';

    const KEY = 'km-welcome-seen';

    function start() {
        const dialog = document.getElementById('km-welcome');
        const ok = document.getElementById('km-welcome-ok');
        if (!dialog || !ok) return;

        // 版を上げれば、読んだことのある人にも 1 回だけ出し直す(index.php の $kmWelcomeVersion)
        const version = dialog.dataset.version || '1';
        let lastFocus = null;

        function seen() {
            try {
                return window.localStorage.getItem(KEY) === version;
            } catch (e) {
                return false;
            }
        }

        function open() {
            lastFocus = document.activeElement;
            dialog.hidden = false;
            document.body.classList.add('km-welcome-open');
            // 読み上げとキーボードの人が、開いたことに気づけるよう最初の的へ
            ok.focus();
        }

        function close() {
            dialog.hidden = true;
            document.body.classList.remove('km-welcome-open');
            try {
                window.localStorage.setItem(KEY, version);
            } catch (e) {
                // 覚えられなくても閉じる。次に開いたときにまた出るだけ
            }
            if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
        }

        ok.addEventListener('click', close);
        dialog.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') close();
            /*
             * **タブで外へ抜けさせない。** 抜けると、見えない後ろの地図の上を
             * フォーカスが歩き回る(開いている間、後ろは操作できない作りなので)。
             */
            if (event.key === 'Tab') {
                const items = Array.from(dialog.querySelectorAll('a[href], button'));
                if (items.length === 0) return;
                const first = items[0];
                const last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
        // 幕(カードの外)を押しても閉じる
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) close();
        });

        // 「ダウンロード・設定」の「はじめにを読む」から開き直す
        document.querySelectorAll('[data-km-welcome-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (typeof window.kmCloseInfoPanel === 'function') window.kmCloseInfoPanel();
                open();
            });
        });

        if (!seen()) open();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
