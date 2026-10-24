// tutorial-end.js
//
// チュートリアルが終わったとき(2026-10-21、利用者の指示)。Main/tutorial.js の 'km-tutorial-finished' を受ける。
//
//   1. 広告(地点に置いていないポスター)があれば、**必ず 1 件**を出す。どれにするかは重み(data-ad-weight。全部 10%。
//      lib/posters.php の KM_POSTER_AD_WEIGHT)で選ぶ —— 全部同じ重みなので、1 件なら 100%・2 件なら 50%(2026-10-21、利用者の指示)
//   2. 広告を閉じたら(広告が 1 件も無いときはすぐ)、「チュートリアルが終わりました」の知らせ(index.php の #km-tutorial-end)を出す
//
// 広告を出すかどうか(表示しない / イベント中のみ / 常に表示)はサーバーが決め、出さないときは地図データの posters が空で届く。
// 重みは利用規約の第 7 条に書いてある。ここで値を持たない(data-ad-weight を読む)。広告を出したら数える(Main/poster-stats.js)。

(function () {
    'use strict';

    const end = document.getElementById('km-tutorial-end');
    if (!end) return;
    const weight = Number(end.dataset.adWeight) || 1;
    let returnFocus = null;

    function trapEscape(close) {
        const onKey = function (event) {
            if (event.key === 'Escape') {
                document.removeEventListener('keydown', onKey);
                close();
            }
        };
        document.addEventListener('keydown', onKey);
        return function () { document.removeEventListener('keydown', onKey); };
    }

    // ---- 2. 終わりの知らせ ----
    function showEnd() {
        end.hidden = false;
        const button = end.querySelector('[data-km-end-close]');
        const release = trapEscape(closeEnd);
        function closeEnd() {
            release();
            end.hidden = true;
            if (returnFocus && document.contains(returnFocus)) returnFocus.focus();
        }
        button.onclick = closeEnd;
        // 幕(カードの外)を押しても閉じる
        end.onclick = function (event) { if (event.target === end) closeEnd(); };
        button.focus();
    }

    // ---- 1. 広告 ----
    function ads() {
        const posters = (window.graphData && window.graphData.posters) || [];
        return posters.filter(function (poster) {
            return poster.isAd === true && typeof poster.imageUrl === 'string' && poster.imageUrl.indexOf('/api/poster-image.php?') === 0;
        });
    }

    function showAd(poster, then) {
        const layer = document.createElement('div');
        // 見た目は「ポスターを大きく見る」と同じ(styles.css の .km-poster-viewer)。「広告」の札だけ足す
        layer.className = 'km-poster-viewer km-ad-viewer';
        layer.setAttribute('role', 'dialog');
        layer.setAttribute('aria-modal', 'true');
        layer.setAttribute('aria-label', '広告');

        const card = document.createElement('div');
        card.className = 'km-poster-viewer-card';

        const badge = document.createElement('span');
        badge.className = 'km-ad-badge';
        badge.textContent = '広告';

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'icon-btn km-poster-viewer-close';
        close.setAttribute('aria-label', '広告を閉じる');
        close.textContent = '×';

        const image = document.createElement('img');
        image.className = 'km-poster-viewer-image';
        image.src = poster.imageUrl;
        image.alt = poster.title || '';

        const body = document.createElement('div');
        body.className = 'km-poster-viewer-body';
        const title = document.createElement('b');
        title.className = 'km-poster-viewer-title';
        title.textContent = poster.title || '';
        body.append(title);
        if (poster.description) {
            const desc = document.createElement('p');
            desc.className = 'km-poster-viewer-desc';
            desc.textContent = poster.description;
            body.append(desc);
        }
        const actions = document.createElement('div');
        actions.className = 'km-ad-actions';
        // リンクは http / https だけ(サーバーでも確かめてある)
        if (poster.linkUrl && /^https?:\/\//i.test(poster.linkUrl)) {
            const link = document.createElement('a');
            link.className = 'km-poster-link';
            link.href = poster.linkUrl;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = 'くわしく見る ↗';
            // 押したら数える(Main/poster-stats.js。同じ端末は 1 人)
            link.setAttribute('data-km-poster-link', String(poster.id));
            actions.append(link);
        }
        const done = document.createElement('button');
        done.type = 'button';
        done.className = 'secondary-btn km-ad-close';
        done.textContent = '閉じる';
        actions.append(done);
        body.append(actions);

        card.append(badge, close, image, body);
        layer.append(card);
        document.body.append(layer);
        // 出した回数を数える(Main/poster-stats.js)
        if (typeof window.kmPosterStat === 'function') window.kmPosterStat(poster.id, 'impression');

        let closed = false;
        const release = trapEscape(finish);
        function finish() {
            if (closed) return;
            closed = true;
            release();
            layer.remove();
            then();
        }
        close.addEventListener('click', finish);
        done.addEventListener('click', finish);
        layer.addEventListener('click', function (event) { if (event.target === layer) finish(); });
        done.focus();
    }

    /**
     * 重みで 1 件を選ぶ。全部同じ重み(10%)なので、どれも 1 / 件数 の割合になる
     * (1 件なら 100%・2 件なら 50%・10 件なら 10%)。
     */
    function pickAd(list) {
        let roll = Math.random() * weight * list.length;
        for (let i = 0; i < list.length; i++) {
            roll -= weight;
            if (roll < 0) return list[i];
        }
        return list[list.length - 1];
    }

    document.addEventListener('km-tutorial-finished', function () {
        returnFocus = document.activeElement;
        const list = ads();
        if (list.length > 0) {
            showAd(pickAd(list), showEnd);
            return;
        }
        showEnd();
    });

    /*
     * **チュートリアルを通らずに広告だけを出す入口**(2026-10-24、利用者の指示「共有から開いた時は、チュートリアル、
     * 前回の経路を全て無視(広告は無視しない)」)。広告はチュートリアルの終わりからしか出なかったので、
     * 共有のリンクから来た人(Main/app.js の focusFromUrl / focusRoomFromUrl)と、チュートリアルを止めているとき
     * (Main/welcome.js。管理画面「お知らせ」のスイッチ)はここから出す。選び方と数え方はチュートリアルの終わりと同じ。
     * **セッションで 1 回だけ**(開き直すたびに出さない)。終わりの知らせは出さない。出したら true
     */
    const AD_SESSION_KEY = 'km-ad-shown';
    window.kmShowAd = function () {
        try {
            if (window.sessionStorage.getItem(AD_SESSION_KEY) === '1') return false;
        } catch (e) { /* 読めなければ出す */ }
        const list = ads();
        if (list.length === 0) return false;
        try { window.sessionStorage.setItem(AD_SESSION_KEY, '1'); } catch (e) { /* 覚えられない */ }
        const focusBack = document.activeElement;
        showAd(pickAd(list), function () {
            if (focusBack && document.contains(focusBack) && typeof focusBack.focus === 'function') focusBack.focus();
        });
        return true;
    };

    // 検査・手元の試し用(確率を通さずに出す)。公開しても害は無い(出すのは地図データに載っている広告だけ)
    window.kmShowTutorialEnd = function (withAd) {
        const list = ads();
        if (withAd && list.length > 0) showAd(list[0], showEnd); else showEnd();
    };
}());
