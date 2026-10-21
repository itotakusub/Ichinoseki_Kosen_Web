// tutorial-end.js
//
// チュートリアルが終わったとき(2026-10-21、利用者の指示)。Main/tutorial.js の 'km-tutorial-finished' を受ける。
//
//   1. 広告(地点に置いていないポスター)があれば、data-ad-chance の確率(10%。lib/posters.php の KM_POSTER_AD_CHANCE)で 1 件を出す
//   2. 広告を閉じたら(出さなかったときはすぐ)、「チュートリアルが終わりました」の知らせ(index.php の #km-tutorial-end)を出す
//
// 広告を出すかどうか(表示しない / イベント中のみ / 常に表示)はサーバーが決め、出さないときは地図データの posters が空で届く。
// 広告の確率は利用規約の第 7 条に書いてある。ここで値を持たない(data-ad-chance を読む)。

(function () {
    'use strict';

    const end = document.getElementById('km-tutorial-end');
    if (!end) return;
    const chance = Number(end.dataset.adChance);
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

    document.addEventListener('km-tutorial-finished', function () {
        returnFocus = document.activeElement;
        const list = ads();
        if (list.length > 0 && chance > 0 && Math.random() < chance) {
            showAd(list[Math.floor(Math.random() * list.length)], showEnd);
            return;
        }
        showEnd();
    });

    // 検査・手元の試し用(確率を通さずに出す)。公開しても害は無い(出すのは地図データに載っている広告だけ)
    window.kmShowTutorialEnd = function (withAd) {
        const list = ads();
        if (withAd && list.length > 0) showAd(list[0], showEnd); else showEnd();
    };
}());
