/*
 * 戻るボタンで戻った地図のサインインの状態を確かめる(2026-10-25、利用者の報告「posters.php からブラウザの戻るボタンで
 * 地図へ戻ると、ログインが切れている(ログインボタンを押すとすぐ入れる)」)。
 *
 * 戻るボタンでは、ブラウザが前の画面をメモリから戻すことがある(bfcache。Safari や最近の Chrome は no-store の画面でも戻す)。
 * その画面がサインインする前に描いたものだと、セッションは生きているのに右上に「ログイン」が出る。
 *
 * **メモリから戻ったときだけ**(pageshow の persisted)api/session-state.php に聞き、描いたときの状態
 * (index.php の <meta name="km-logged-in">)と違えば読み直す。同じなら何もしない(地図の位置・開いている欄はそのまま)。
 * 普通に開いたときは聞かない(来場者の多い日に要求を増やさない)。聞けなければ何もしない。
 */
(function () {
    'use strict';

    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        const meta = document.querySelector('meta[name="km-logged-in"]');
        if (!meta) return;
        const shown = meta.getAttribute('content') === '1';
        fetch('/api/session-state.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (data && typeof data.loggedIn === 'boolean' && data.loggedIn !== shown) {
                    window.location.reload();
                }
            })
            .catch(function () { /* 聞けなければ今の画面のまま */ });
    });
}());
