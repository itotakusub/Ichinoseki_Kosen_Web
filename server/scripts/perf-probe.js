/*
 * 地図の動きの重さを測る(2026-10-29、高専祭の振り返りのフェーズ 0 の 6「直す前の値を残す」)。
 *
 * 公開の地図(ito4.jp)を開き、錠を解いてから、開発者ツールのコンソールにこのファイルの中身を貼って実行する。
 * フェーズ 2(動きの軽さ)の前と後で同じ手順で測り、docs/status.md に並べる。
 * 遅い端末の代わりに、開発者ツールの Performance で CPU を 6 倍遅くしてからも 1 回測る(設定は手で)。
 *
 * 測るもの:
 *   - 読み込み: 最初の応答・DOMContentLoaded・load・転送量・要求の数
 *   - 画面の部品の数: DOM 全体・地点の印・札
 *   - ズームの出し入れ 8 回と横への移動 8 回の間のコマの間隔(中央値・95%・最大・50ms を超えたコマ)と長い処理(longtask)
 *
 * 地図を動かすだけで、何も送らない・保存しない(結果はコンソールに出すだけ)。
 */
(async () => {
    'use strict';
    const map = window.map;
    if (!map || typeof map.setZoom !== 'function') {
        console.warn('地図がまだありません(錠を解いてから実行してください)');
        return;
    }
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const once = (name) => new Promise((resolve) => map.once(name, resolve));
    const nav = performance.getEntriesByType('navigation')[0] || {};
    const resources = performance.getEntriesByType('resource');

    const longTasks = [];
    let observer = null;
    try {
        observer = new PerformanceObserver((list) => list.getEntries().forEach((e) => longTasks.push(e.duration)));
        observer.observe({ type: 'longtask', buffered: false });
    } catch (e) {
        // longtask を測れないブラウザ(Safari など)。コマの間隔だけ出す
    }

    const gaps = [];
    let last = performance.now();
    let running = true;
    const tick = (t) => {
        gaps.push(t - last);
        last = t;
        if (running) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);

    const startZoom = map.getZoom();
    const center = map.getCenter();
    const started = performance.now();
    for (let i = 0; i < 4; i++) {
        const z = Math.min(map.getMaxZoom(), map.getZoom() + 1);
        if (z === map.getZoom()) break;
        map.setZoom(z, { animate: true });
        await once('zoomend');
        await sleep(400);
    }
    for (let i = 0; i < 8; i++) {
        map.panBy([i % 2 === 0 ? 240 : -240, 0], { animate: true });
        await once('moveend');
        await sleep(250);
    }
    for (let i = 0; i < 4; i++) {
        const z = Math.max(map.getMinZoom(), map.getZoom() - 1);
        if (z === map.getZoom()) break;
        map.setZoom(z, { animate: true });
        await once('zoomend');
        await sleep(400);
    }
    const elapsed = performance.now() - started;
    running = false;
    if (observer) observer.disconnect();
    map.setView(center, startZoom, { animate: false });

    const sorted = gaps.slice(1).sort((a, b) => a - b);
    const pick = (q) => (sorted.length ? sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * q))] : 0);
    const round = (n) => Math.round(n * 10) / 10;
    const result = {
        when: new Date().toISOString(),
        viewport: `${innerWidth}x${innerHeight}@${devicePixelRatio}`,
        cpuThreads: navigator.hardwareConcurrency || null,
        deviceMemoryGB: navigator.deviceMemory || null,
        load: {
            ttfbMs: Math.round(nav.responseStart || 0),
            domContentLoadedMs: Math.round(nav.domContentLoadedEventEnd || 0),
            loadMs: Math.round(nav.loadEventEnd || 0),
            transferKB: Math.round((resources.reduce((s, r) => s + (r.transferSize || 0), 0) + (nav.transferSize || 0)) / 1024),
            requests: resources.length + 1,
        },
        dom: {
            nodes: document.getElementsByTagName('*').length,
            markers: document.querySelectorAll('.leaflet-marker-icon').length,
            labels: document.querySelectorAll('.leaflet-tooltip').length,
        },
        motion: {
            seconds: round(elapsed / 1000),
            frames: sorted.length,
            fps: round(sorted.length / (elapsed / 1000)),
            frameMsMedian: round(pick(0.5)),
            frameMsP95: round(pick(0.95)),
            frameMsMax: round(sorted[sorted.length - 1] || 0),
            framesOver50ms: sorted.filter((g) => g > 50).length,
            longTasks: longTasks.length,
            longTaskMsTotal: Math.round(longTasks.reduce((s, d) => s + d, 0)),
        },
    };
    console.log(JSON.stringify(result, null, 2));
    return result;
})();
