/*
 * 別のサイトに埋め込む地図を描く(段 15。2026-11-14。入口は embed/index.php、仕組みは lib/map-embed.php)。
 *
 * 公開ページの Main/app.js は使わない(画面の部品に強く結びついていて、埋め込みに要らない機能が多い)。
 * 見た目(種類ごとの色・形・どの倍率から出すか)は公開ページと同じ Main/map-style.js から取る。
 *
 * v1 でできること(利用者 11-14「見る・階・地点を指すまで」): 地図を見る・階の切り替え・拡大縮小・
 * 地点を押すと名前・外から地点を指して目立たせる。検索と道案内は次の版。
 *
 * ## 親(埋め込んだページ)との話し方(postMessage。/embed/v1.js が包む)
 *
 *   親 → 枠  { kosenmap: 1, cmd: 'focus', id: '<地点>' } / { kosenmap: 1, cmd: 'setFloor', floor: '<階>' }
 *   枠 → 親  { kosenmap: 1, event: 'ready' | 'floor' | 'select', detail: {…} }
 *
 * - 受け取るのは**直接の親から**、**登録したオリジン(cfg.parents)から**のものだけ
 * - 送る相手もそのオリジンに決め打ちする(知らないページへ地点の名前を流さない)
 * - DB 由来の文字(名前)は textContent で入れる。HTML として組まない
 */
(function () {
    'use strict';

    const dataEl = document.getElementById('km-embed-data');
    if (!dataEl || !window.L) return;
    let cfg;
    try {
        cfg = JSON.parse(dataEl.textContent || '{}');
    } catch (e) {
        return;
    }
    const ja = cfg.lang !== 'en';
    const mapStyle = window.KM_MAP_STYLE || null;
    const tuning = mapStyle ? mapStyle.load() : null;
    const parents = Array.isArray(cfg.parents) ? cfg.parents.filter((o) => typeof o === 'string') : [];
    const floors = Array.isArray(cfg.floors) ? cfg.floors : [];
    const places = Array.isArray(cfg.places) ? cfg.places : [];
    const floorsById = new Map(floors.map((f) => [String(f.id), f]));
    const placesById = new Map(places.map((p) => [String(p.id), p]));
    const TYPE_LABELS = ja
        ? { room: '部屋', facility: '建物・施設', stairs: '階段', entrance: '出入口' }
        : { room: 'Room', facility: 'Building', stairs: 'Stairs', entrance: 'Entrance' };

    const nameOf = (place) => (!ja && place.nameEn ? place.nameEn : place.name);
    const floorLabel = (floor) => (ja ? floor.label : (floor.labelEn || floor.label));

    const map = L.map('km-embed-map', {
        crs: L.CRS.Simple,
        minZoom: -2,
        maxZoom: 3,
        zoomSnap: 0.25,
        zoomControl: false,
        attributionControl: false,
    });
    L.control.zoom({ position: 'bottomright', zoomInTitle: ja ? '拡大' : 'Zoom in', zoomOutTitle: ja ? '縮小' : 'Zoom out' }).addTo(map);

    let currentFloor = null;
    let overlay = null;
    let loadToken = 0;
    /** 種類ごとの印の束(倍率で出し入れする。公開ページの renderGlobalLabels と同じ決め方) */
    let groups = {};
    let focusMarker = null;
    let focusedId = null;
    /** 階を収めたときの倍率。ここから 1 段拡大するまでは名前を常には出さない(重なって読めないため) */
    let fitZoom = 0;
    /** 最初の階を出し終えたか(それまでの floor は知らせない。ready に入っている) */
    let started = false;

    // ---------------------------------------------------------------- 親との話
    function notify(event, detail) {
        if (window.parent === window) return;
        parents.forEach((origin) => {
            try {
                window.parent.postMessage({ kosenmap: 1, event: event, detail: detail }, origin);
            } catch (e) {
                // 相手が違えば届かないだけ
            }
        });
    }

    window.addEventListener('message', (event) => {
        if (event.source !== window.parent || parents.indexOf(event.origin) < 0) return;
        const data = event.data;
        if (!data || typeof data !== 'object' || data.kosenmap !== 1) return;
        if (data.cmd === 'focus' && typeof data.id === 'string') {
            focusPlace(data.id);
        } else if (data.cmd === 'setFloor' && typeof data.floor === 'string') {
            showFloor(data.floor, false);
        }
    });

    // ---------------------------------------------------------------- 印
    function nodeIcon(type) {
        const setting = mapStyle ? mapStyle.typeSetting(tuning, type) : { shape: 'circle', size: 100 };
        const r = 7 * ((tuning && tuning.nodeSize) || 100) / 100 * (setting.size || 100) / 100;
        const box = Math.ceil(r * 2.6);
        const path = mapStyle ? mapStyle.shapePath(setting.shape, r) : 'M0,' + (-r) + 'a' + r + ',' + r + ' 0 1,0 0,' + (r * 2) + 'a' + r + ',' + r + ' 0 1,0 0,' + (-r * 2);
        const color = mapStyle ? mapStyle.color(type) : '#06B6D4';
        // 中身は数と既知の値だけ(DB の文字は入れない)
        const html = '<svg width="' + box + '" height="' + box + '" viewBox="' + (-box / 2) + ' ' + (-box / 2) + ' ' + box + ' ' + box + '" aria-hidden="true">'
            + '<path d="' + path + '" fill="' + color + '"></path>'
            + '<circle cx="0" cy="0" r="' + (r * 0.42).toFixed(2) + '" fill="#ffffff"></circle></svg>';
        return L.divIcon({ className: 'km-embed-node km-embed-node-' + type, html: html, iconSize: [box, box], iconAnchor: [box / 2, box / 2] });
    }

    function popupContent(place) {
        const box = document.createElement('div');
        box.className = 'km-embed-popup';
        const title = document.createElement('strong');
        title.textContent = nameOf(place);
        const kind = document.createElement('span');
        kind.className = 'km-embed-popup-kind';
        const floor = floorsById.get(String(place.floor));
        kind.textContent = (TYPE_LABELS[place.type] || '') + (floor ? '・' + floorLabel(floor) : '');
        box.append(title, kind);
        return box;
    }

    function selectPlace(place) {
        notify('select', { id: place.id, name: nameOf(place), type: place.type, floor: place.floor });
    }

    function buildMarkers(floorId) {
        Object.values(groups).forEach((group) => map.removeLayer(group));
        groups = {};
        places.forEach((place) => {
            if (String(place.floor) !== floorId) return;
            const marker = L.marker([place.y, place.x], { icon: nodeIcon(place.type), title: nameOf(place), keyboard: true, riseOnHover: true });
            marker.bindTooltip(nameOf(place), { permanent: true, direction: 'top', offset: [0, -6], className: 'km-embed-label km-embed-label-node' });
            marker.bindPopup(() => popupContent(place), { closeButton: true, autoPan: true });
            marker.on('click', () => selectPlace(place));
            // 引いているときは、触れた印の名前だけ出す(印と名前は別の層にあるので CSS の隣りでは選べない)
            const hover = (on) => () => { const el = marker.getTooltip() && marker.getTooltip().getElement(); if (el) el.classList.toggle('km-embed-label-hover', on); };
            marker.on('mouseover', hover(true));
            marker.on('mouseout', hover(false));
            (groups[place.type] = groups[place.type] || L.layerGroup()).addLayer(marker);
        });
        applyVisibility();
    }

    function applyVisibility() {
        const zoom = map.getZoom();
        // 引いているときは名前を隠す(印に触れる・押すと出る)。CSS の .km-embed-labels-zoomed
        map.getContainer().classList.toggle('km-embed-labels-zoomed', zoom >= fitZoom + 1);
        Object.keys(groups).forEach((type) => {
            const show = !mapStyle || mapStyle.isVisible(tuning, type, zoom, currentFloor);
            if (show && !map.hasLayer(groups[type])) groups[type].addTo(map);
            if (!show && map.hasLayer(groups[type])) map.removeLayer(groups[type]);
        });
    }
    map.on('zoomend', applyVisibility);

    // ---------------------------------------------------------------- 階
    function campusFloors() {
        return floors.filter((f) => f.kind !== 'building');
    }

    function renderFloorButtons() {
        const nav = document.getElementById('km-embed-floors');
        if (!nav) return;
        const list = campusFloors();
        const current = floorsById.get(String(currentFloor));
        // 建物の中の階を開いているときは、その階も並べる(戻り先が分かるように)
        if (current && current.kind === 'building' && list.indexOf(current) < 0) list.push(current);
        nav.replaceChildren(...list.map((floor) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'km-embed-floor';
            button.textContent = floorLabel(floor);
            button.setAttribute('aria-pressed', String(String(floor.id) === String(currentFloor)));
            button.addEventListener('click', () => showFloor(String(floor.id), false));
            return button;
        }));
        nav.hidden = list.length <= 1;
    }

    /** 階を出す。keepView = true なら視点を収め直さない(focusPlace が自分で動かす) */
    function showFloor(floorId, keepView) {
        const floor = floorsById.get(String(floorId));
        if (!floor) return Promise.resolve(false);
        if (String(floorId) === String(currentFloor)) return Promise.resolve(true);
        currentFloor = String(floorId);
        const token = ++loadToken;
        renderFloorButtons();
        return new Promise((resolve) => {
            const image = new Image();
            const done = (ok) => {
                if (token !== loadToken) return resolve(false); // その間に別の階へ移った
                if (overlay) map.removeLayer(overlay);
                // 指した印は階を替えたら外す(同じ地点を指し直すなら focusPlace が付け直す)
                if (focusMarker) {
                    map.removeLayer(focusMarker);
                    focusMarker = null;
                }
                const bounds = floor.width > 0 && floor.height > 0
                    ? [[0, 0], [floor.height, floor.width]]
                    : [[0, 0], [image.naturalHeight || 1000, image.naturalWidth || 1500]];
                overlay = ok ? L.imageOverlay(floor.image, bounds).addTo(map) : null;
                map.setMaxBounds(L.latLngBounds(bounds).pad(0.5));
                fitZoom = map.getBoundsZoom(bounds);
                if (!keepView) map.fitBounds(bounds);
                buildMarkers(currentFloor);
                if (started) notify('floor', { floor: currentFloor });
                resolve(true);
            };
            image.onload = () => done(true);
            image.onerror = () => done(false);
            image.src = floor.image;
        });
    }

    // ---------------------------------------------------------------- 地点を指す
    function focusPlace(id) {
        const place = placesById.get(String(id));
        if (!place) return;
        focusedId = place.id;
        showFloor(String(place.floor), true).then(() => {
            if (focusedId !== place.id) return;
            if (focusMarker) map.removeLayer(focusMarker);
            focusMarker = L.marker([place.y, place.x], {
                icon: L.divIcon({ className: 'km-embed-focus', html: '<span></span>', iconSize: [36, 36], iconAnchor: [18, 18] }),
                interactive: false,
                keyboard: false,
                zIndexOffset: 1000,
            }).addTo(map);
            // 倍率で隠れる種類でも、指した地点の名前は出す
            focusMarker.bindTooltip(nameOf(place), { permanent: true, direction: 'top', offset: [0, -14], className: 'km-embed-label km-embed-label-focus' });
            map.setView([place.y, place.x], Math.max(map.getZoom(), 1));
        });
    }

    // ---------------------------------------------------------------- 始める
    window.addEventListener('resize', () => map.invalidateSize());
    const focusPlaceStart = cfg.focus ? placesById.get(String(cfg.focus)) : null;
    const first = (focusPlaceStart && floorsById.has(String(focusPlaceStart.floor)) && String(focusPlaceStart.floor))
        || (cfg.floor && floorsById.has(String(cfg.floor)) && String(cfg.floor))
        || (campusFloors()[0] && String(campusFloors()[0].id))
        || (floors[0] && String(floors[0].id));
    if (!first) return;
    showFloor(first, false).then(() => {
        if (focusPlaceStart) focusPlace(focusPlaceStart.id);
        started = true;
        notify('ready', {
            version: 1,
            floor: currentFloor,
            floors: floors.map((f) => ({ id: String(f.id), label: floorLabel(f), kind: f.kind })),
        });
    });
})();
