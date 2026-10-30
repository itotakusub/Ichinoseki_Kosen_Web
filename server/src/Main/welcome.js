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
    // 読んだお知らせの id(2026-10-24。index.php の data-notices・lib/notices.php)。「,」でつなぐ
    const NOTICES_KEY = 'km-notices-read';

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

        /*
         * まだ読んでいないお知らせ(2026-10-24)。**「はじめに」を読んだことのある人にも、新しいお知らせがあれば出す。**
         * 覚えられない環境では「読んでいない」とみなす(その画面を閉じるまでは出し直さない。下の close)
         */
        const notices = (dialog.dataset.notices || '').split(',').filter(Boolean);
        function readNotices() {
            try {
                return (window.localStorage.getItem(NOTICES_KEY) || '').split(',').filter(Boolean);
            } catch (e) {
                return [];
            }
        }
        function unreadNotices() {
            const read = readNotices();
            return notices.filter(function (id) { return read.indexOf(id) < 0; });
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
                // 出したお知らせは読んだことにする(古い id は、もう出さないので覚えておく数を増やさない)
                const keep = readNotices().filter(function (id) { return notices.indexOf(id) >= 0; });
                window.localStorage.setItem(NOTICES_KEY, keep.concat(unreadNotices()).join(','));
            } catch (e) {
                // 覚えられなくても閉じる。次に開いたときにまた出るだけ
            }
            if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
            // 初回の操作案内(Main/tutorial.js)は「はじめに」を閉じてから始める
            document.dispatchEvent(new CustomEvent('km-welcome-closed'));
            /*
             * チュートリアルを止めているときは、ここで広告を出す(2026-10-24。管理画面「お知らせ」のスイッチ。
             * 広告はチュートリアルの終わりから出していたので、止めると広告も出なくなっていた)。
             * 出すのはセッションで 1 回だけ(Main/tutorial-end.js の kmShowAd)
             */
            // 最初の画面(Main/entry.js)があるときは出さない。そちらが「すぐ地図へ」で閉じたときに出す(2026-10-30)
            if (dialog.dataset.tutorial === 'off' && !window.kmEntry && typeof window.kmShowAd === 'function') {
                window.setTimeout(window.kmShowAd, 300);
            }
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

        /*
         * **共有のリンク(部屋の QR `/?room=`・場所 `/?poi=`・経路 `/?from=&to=`)から来た人には出さない**
         * (2026-10-14 に部屋の QR、2026-10-24 に共有のリンク全部へ広げた。利用者の指示)。
         * 出すと、開いた場所や経路がこの幕の下に隠れる。読んだ印も付けないので、
         * あとで普通に開いたときには出る。印は index.php の <meta name="km-from-share">。
         */
        const fromShare = document.querySelector('meta[name="km-from-share"]') !== null;
        if ((!seen() || unreadNotices().length > 0) && !fromShare) open();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
