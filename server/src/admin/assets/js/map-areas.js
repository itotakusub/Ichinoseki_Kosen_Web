/*
 * 地図の色分け(admin/map-areas.php。2026-10-30)。各階の床の画像を、Main/area-segmentation.js で塗って
 * admin/api/map-areas.php へ送る。**1 回に 1 つの階**(1 枚 1600×1200 で数百 ms。間を空けて画面を固めない)。
 *
 * 座標: サーバーの材料は Web の地図と同じ(y は上向き)。画素へは範囲(coordWidth・coordHeight。無ければ画像の大きさ)で直す
 * (Main/app.js の boundsForFloor と同じ考え方。範囲と画像の大きさが違う階もある)。文字は textContent で入れる。
 */
(function () {
  'use strict';

  const root = document.getElementById('km-map-areas');
  const area = window.KM_AREA_SEGMENTATION;
  if (!root || !area) return;

  const rowsBody = document.getElementById('km-map-areas-rows');
  const statusEl = document.getElementById('km-map-areas-status');
  const allButton = document.getElementById('km-map-areas-all');
  const csrf = document.querySelector('meta[name="km-csrf"]')?.content ?? '';
  const SHOWN_KINDS = ['room', 'corridor', 'stairs', 'building', 'road'];
  const CHECK_KINDS = ['leak', 'unknown', 'ambiguous'];
  let floors = [];
  let busy = false;

  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  function loadImage(url) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('床の画像を読めませんでした'));
      img.src = url;
    });
  }

  /** Web の座標(y は上向き)を画像の画素(y は下向き)へ */
  function toPixels(floor, width, height) {
    const cw = Number(floor.coordWidth) > 0 ? Number(floor.coordWidth) : width;
    const ch = Number(floor.coordHeight) > 0 ? Number(floor.coordHeight) : height;
    const sx = width / cw;
    const sy = height / ch;
    return {
      seeds: floor.seeds.map((s) => Object.assign({}, s, { x: s.x * sx, y: (ch - s.y) * sy })),
      walls: floor.walls.map((w) => ({ x1: w[0] * sx, y1: (ch - w[1]) * sy, x2: w[2] * sx, y2: (ch - w[3]) * sy })),
    };
  }

  function blobToBase64(blob) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result).replace(/^data:[^,]*,/, ''));
      reader.onerror = () => reject(new Error('画像を送る形にできませんでした'));
      reader.readAsDataURL(blob);
    });
  }

  async function post(body) {
    const response = await fetch(root.dataset.src, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-KM-CSRF': csrf },
      body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error || `送れませんでした(${response.status})`);
    return data;
  }

  /** 1 つの階を塗って送る。返すのは種類ごとの区画の数 */
  async function paintFloor(floor, row) {
    const img = await loadImage(floor.imageUrl);
    const width = img.naturalWidth;
    const height = img.naturalHeight;
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(img, 0, 0);
    const source = ctx.getImageData(0, 0, width, height);
    const input = toPixels(floor, width, height);
    const map = area.segmentAreas(source.data, width, height, input.seeds, input.walls, !!floor.outdoor);
    const painted = area.paintAreas(source.data, map, (kind) => area.colorOf(kind, false));
    ctx.putImageData(new ImageData(painted, width, height), 0, 0);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
    if (!blob) throw new Error('PNG にできませんでした');
    const saved = await post({ floor: floor.floor, source: floor.imageUrl, png: await blobToBase64(blob) });
    floor.painted = { at: saved.at, current: true };
    // 見本は小さく(確かめの色で塗り直す: 漏れ・名前なし・あいまいが目立つ)
    const debug = area.paintAreas(source.data, map, (kind) => area.colorOf(kind, true));
    ctx.putImageData(new ImageData(debug, width, height), 0, 0);
    const thumb = row.querySelector('canvas');
    thumb.width = 240;
    thumb.height = Math.round(240 * height / width);
    thumb.getContext('2d').drawImage(canvas, 0, 0, thumb.width, thumb.height);
    return area.countKinds(map);
  }

  function statusText(floor) {
    if (!floor.painted) return 'まだ塗っていない';
    return floor.painted.current ? `塗ってある(${floor.painted.at})` : '床の画像を差し替えたので古い(出していない)';
  }

  function render() {
    rowsBody.replaceChildren();
    floors.forEach((floor) => {
      const row = el('tr');
      row.append(el('td', 'fw-bold', floor.label));
      const status = el('td', 'fs-7', statusText(floor));
      const counts = el('td', 'fs-7 text-body-secondary', '—');
      const checks = el('td', 'fs-7 text-body-secondary', '—');
      const preview = el('td');
      const thumb = el('canvas', 'border rounded');
      thumb.width = 0;
      thumb.height = 0;
      preview.append(thumb);
      const actions = el('td', 'text-nowrap');
      const make = el('button', 'btn btn-sm btn-outline-primary me-1', floor.painted ? '作り直す' : '作る');
      make.type = 'button';
      const clear = el('button', 'btn btn-sm btn-outline-secondary', '外す');
      clear.type = 'button';
      clear.disabled = !floor.painted;
      actions.append(make, clear);
      row.append(status, counts, checks, preview, actions);
      rowsBody.append(row);

      const run = async () => {
        status.textContent = '塗っています…';
        try {
          const kinds = await paintFloor(floor, row);
          status.textContent = statusText(floor);
          counts.textContent = SHOWN_KINDS.map((k) => kinds[k] || 0).join('・');
          checks.textContent = CHECK_KINDS.map((k) => kinds[k] || 0).join('・');
          checks.className = 'fs-7 ' + ((kinds.leak || 0) > 0 ? 'text-danger fw-bold' : 'text-body-secondary');
          make.textContent = '作り直す';
          clear.disabled = false;
        } catch (error) {
          status.textContent = error.message;
          status.className = 'fs-7 text-danger';
        }
      };
      floor.run = run;
      make.addEventListener('click', async () => {
        if (busy) return;
        busy = true;
        await run();
        busy = false;
      });
      clear.addEventListener('click', async () => {
        if (busy || !window.confirm(`${floor.label} の色分けを外しますか？(公開の地図は塗っていない床に戻ります)`)) return;
        busy = true;
        try {
          await post({ floor: floor.floor, clear: true });
          floor.painted = null;
          status.textContent = statusText(floor);
          clear.disabled = true;
          make.textContent = '作る';
        } catch (error) {
          status.textContent = error.message;
        }
        busy = false;
      });
    });
  }

  allButton.addEventListener('click', async () => {
    if (busy) return;
    busy = true;
    allButton.disabled = true;
    for (let i = 0; i < floors.length; i++) {
      statusEl.textContent = `${i + 1} / ${floors.length} 階目を塗っています…`;
      await floors[i].run();
      // 階の間に画面を描かせる
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
    statusEl.textContent = 'すべての階を塗りました。公開の地図を開き直すと出ます。';
    allButton.disabled = false;
    busy = false;
  });

  fetch(root.dataset.src, { credentials: 'same-origin' })
    .then((response) => response.json())
    .then((data) => {
      if (!Array.isArray(data.floors)) throw new Error(data.error || '地図を読めませんでした');
      floors = data.floors;
      render();
      statusEl.textContent = `${floors.length} 階`;
      allButton.disabled = floors.length === 0;
    })
    .catch((error) => {
      statusEl.textContent = error.message;
      statusEl.className = 'text-danger fs-7';
    });
}());
