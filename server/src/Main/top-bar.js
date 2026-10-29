/*
 * 全画面と横画面の上の帯(2026-10-30、振り返りのフェーズ 2 の 4。アプリの TopBarController.kt と同じ動き)。
 *
 * - 右下(ズームの上)に「全画面」のボタン。requestFullscreen が使える端末だけ出す(iPhone の Safari は使えない)
 * - **全画面のとき・スマホを横にしたとき**は、上の帯(検索・チップ・アカウント)を隠して地図を広く使う。
 *   - 上の端(40px)から下へ 24px 引くか、上の真ん中の取っ手(▼)を押すと、帯が地図の上に下りてくる
 *   - 4 秒のあいだ帯に触らなければ、また隠れる。検索に打っている間・候補やアカウントの欄が開いている間は隠さない
 * - 縦に戻す・全画面を出ると、いつもの形に戻る
 *
 * 地図(Leaflet)は app.js が作る。このファイルは app.js の後に読む(ボタンを地図の右下に置く)。
 */
(function () {
    'use strict';

    const AUTO_HIDE_MS = 4000;
    const EDGE_PX = 40;
    const PULL_PX = 24;
    // 横にしたスマホ(高さが低い)。タブレットや PC の横長の画面は今までどおり
    const landscapePhone = window.matchMedia('(orientation: landscape) and (max-height: 500px)');
    const body = document.body;
    let hideTimer = null;
    let dragStartY = null;

    const handle = document.createElement('button');
    handle.type = 'button';
    handle.id = 'km-topbar-handle';
    handle.className = 'km-topbar-handle';
    handle.title = '上の検索の欄を出す';
    handle.setAttribute('aria-label', '上の検索の欄を出す');
    handle.textContent = '▼';
    body.appendChild(handle);

    function isFullscreen() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement);
    }

    /** 帯を自動で隠す形か(全画面か、横にしたスマホ) */
    function autoMode() {
        return isFullscreen() || landscapePhone.matches;
    }

    /** 使っている最中は隠さない */
    function barInUse() {
        const active = document.activeElement;
        if (active && active.closest && active.closest('#km-searchbox, #km-account, #km-category-chips')) return true;
        const account = document.getElementById('km-account');
        return !!(account && account.open);
    }

    function scheduleHide() {
        window.clearTimeout(hideTimer);
        hideTimer = window.setTimeout(function () {
            if (barInUse()) {
                scheduleHide();
                return;
            }
            body.classList.remove('km-topbar-peek');
        }, AUTO_HIDE_MS);
    }

    function showBar() {
        if (!autoMode()) return;
        body.classList.add('km-topbar-peek');
        // 拡大で地図以外を隠していても(app.js の km-chrome-hidden)、引いたら出す
        body.classList.remove('km-chrome-hidden');
        scheduleHide();
    }

    function apply() {
        const auto = autoMode();
        body.classList.toggle('km-topbar-auto', auto);
        if (!auto) {
            window.clearTimeout(hideTimer);
            body.classList.remove('km-topbar-peek');
        }
        syncFullscreenButton();
    }

    handle.addEventListener('click', showBar);

    // 上の端から下へ引く。帯を出したら、その指で地図は動かさない(capture で先に受けて止める)
    let consuming = false;
    window.addEventListener('touchstart', function (event) {
        consuming = false;
        dragStartY = null;
        if (!autoMode() || body.classList.contains('km-topbar-peek') || event.touches.length !== 1) return;
        if (event.touches[0].clientY <= EDGE_PX) dragStartY = event.touches[0].clientY;
    }, { capture: true, passive: true });
    window.addEventListener('touchmove', function (event) {
        if (consuming) {
            event.stopPropagation();
            return;
        }
        if (dragStartY === null || event.touches.length !== 1) return;
        if (event.touches[0].clientY - dragStartY >= PULL_PX) {
            dragStartY = null;
            consuming = true;
            event.stopPropagation();
            showBar();
        }
    }, { capture: true, passive: true });
    window.addEventListener('touchend', function () {
        dragStartY = null;
        consuming = false;
    }, { capture: true, passive: true });

    // 帯に触っている間は、隠れるまでの時間を延ばす
    ['pointerdown', 'focusin', 'input'].forEach(function (type) {
        document.addEventListener(type, function (event) {
            if (!body.classList.contains('km-topbar-peek')) return;
            const target = event.target;
            if (target && target.closest && target.closest('#km-searchbox, #km-account, #km-category-chips')) scheduleHide();
        });
    });
    // キーで動かす人は Tab で帯へ行けるように、帯へ入ったら出す
    document.addEventListener('focusin', function (event) {
        const target = event.target;
        if (autoMode() && target && target.closest && target.closest('#km-searchbox, #km-account, #km-category-chips')) showBar();
    });

    /*
     * ---- 全画面のボタン ----
     * ズームの +/− の上に置く(Leaflet の右下。後から足したものが上に並ぶ)
     */
    const canFullscreen = !!(document.documentElement.requestFullscreen || document.documentElement.webkitRequestFullscreen)
        && document.fullscreenEnabled !== false;
    let fullscreenButton = null;

    function syncFullscreenButton() {
        if (!fullscreenButton) return;
        const on = isFullscreen();
        const label = on ? '全画面をやめる' : '全画面で見る';
        fullscreenButton.title = label;
        fullscreenButton.setAttribute('aria-label', label);
        fullscreenButton.setAttribute('aria-pressed', on ? 'true' : 'false');
        fullscreenButton.textContent = on ? '⤡' : '⛶';
    }

    function toggleFullscreen() {
        if (isFullscreen()) {
            (document.exitFullscreen || document.webkitExitFullscreen).call(document);
            return;
        }
        const root = document.documentElement;
        const request = root.requestFullscreen || root.webkitRequestFullscreen;
        const result = request.call(root, { navigationUI: 'hide' });
        if (result && typeof result.catch === 'function') result.catch(function () {});
    }

    function addFullscreenControl() {
        if (!canFullscreen || fullscreenButton || !window.L || !window.map || typeof window.map.getZoom !== 'function') return;
        const FullscreenControl = window.L.Control.extend({
            options: { position: 'bottomright' },
            onAdd: function () {
                const box = window.L.DomUtil.create('div', 'leaflet-bar km-fullscreen-control');
                const button = window.L.DomUtil.create('a', 'km-fullscreen-btn', box);
                button.href = '#';
                button.setAttribute('role', 'button');
                window.L.DomEvent.disableClickPropagation(box);
                window.L.DomEvent.on(button, 'click', function (event) {
                    window.L.DomEvent.preventDefault(event);
                    toggleFullscreen();
                });
                fullscreenButton = button;
                syncFullscreenButton();
                return box;
            },
        });
        new FullscreenControl().addTo(window.map);
    }
    // app.js は地図を DOMContentLoaded の後に作る(それまで window.map は #map の要素)。作り終えたら km:map-ready が来る
    addFullscreenControl();
    window.addEventListener('km:map-ready', addFullscreenControl);

    document.addEventListener('fullscreenchange', apply);
    document.addEventListener('webkitfullscreenchange', apply);
    if (typeof landscapePhone.addEventListener === 'function') {
        landscapePhone.addEventListener('change', apply);
    } else if (typeof landscapePhone.addListener === 'function') {
        landscapePhone.addListener(apply);
    }
    apply();
}());
