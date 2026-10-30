// poster-stats.js
//
// ポスター・広告の数を送る(2026-10-21、利用者の指示「広告の表示回数とリンクを踏んだ人数を保存」)。
// 受けるのは api/poster-event.php。**送るのはポスターの番号と種類だけ**(誰のものかは送らない)。
//
//   - リンクを押した: `<a data-km-poster-link="<番号>">` を押したら数える(地図の詳細・大きく見る幕・広告・一覧のページ)。
//     **同じ端末は 1 人**: 押したことを localStorage に覚え、2 度目からは送らない(サーバーもセッションで 1 回に絞る)
//   - 広告を出した: Main/tutorial-end.js が window.kmPosterStat(番号, 'impression') を呼ぶ
//
// 送れなくても何もしない(数え損ねても、見ている人には関係ない)。CSRF のトークンは <meta name="km-csrf">。

(function () {
    'use strict';

    const meta = document.querySelector('meta[name="km-csrf"]');

    function send(posterId, kind) {
        if (!meta) return;
        try {
            // keepalive: リンクで別のページへ移っても送り切る
            fetch('/api/poster-event.php', {
                method: 'POST',
                keepalive: true,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-KM-CSRF': meta.getAttribute('content') || '' },
                body: JSON.stringify({ poster: posterId, kind: kind }),
            }).catch(function () { /* 数え損ねるだけ */ });
        } catch (error) {
            // 古いブラウザ(keepalive が無い)など。数え損ねるだけ
        }
    }

    function clickedBefore(posterId) {
        try {
            const key = 'km-poster-clicked-' + posterId;
            if (window.localStorage.getItem(key)) return true;
            window.localStorage.setItem(key, '1');
        } catch (error) {
            // 覚えられない(プライベートブラウズなど)。サーバーのセッションの絞りだけになる
        }
        return false;
    }

    window.kmPosterStat = function (posterId, kind) {
        const id = Number(posterId);
        if (!Number.isInteger(id) || id <= 0) return;
        if (kind === 'click' && clickedBefore(id)) return;
        if (kind === 'click' || kind === 'impression') send(id, kind);
    };

    /*
     * 外のサイトへ出る前に 1 度確かめる(2026-10-30、振り返りのフェーズ 3 の 5「地図で見る と くわしく見る を押し間違える」)。
     * 行き先のサイト名を出す。やめたら移らず、数えもしない。中ボタン(新しいタブ)は本人がタブを選んでいるので聞かない
     */
    function confirmLeave(link) {
        let host = '';
        try {
            const url = new URL(link.href, window.location.href);
            if (url.origin === window.location.origin) return true;
            host = url.hostname;
        } catch (error) {
            return true;
        }
        return window.confirm('外のサイト(' + host + ')へ移ります。\n地図に戻るときは、このタブに戻ってください。');
    }

    // 中ボタン(新しいタブで開く)も数える。捕まえる段階で受ける(地図の吹き出しは中で止めることがある)
    ['click', 'auxclick'].forEach(function (type) {
        document.addEventListener(type, function (event) {
            const link = event.target && event.target.closest ? event.target.closest('a[data-km-poster-link]') : null;
            if (!link) return;
            if (type === 'click' && !confirmLeave(link)) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }
            window.kmPosterStat(link.getAttribute('data-km-poster-link'), 'click');
        }, true);
    });
}());
