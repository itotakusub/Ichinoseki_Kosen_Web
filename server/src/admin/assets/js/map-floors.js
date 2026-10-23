// map-floors.js
//
// 階と平面図(admin/map-floors.php。2026-10-21)の画面の動き。
//   - 画像を選ぶと、この場で読んでプレビューを出す(サーバーへはまだ送らない)
//   - 座標の範囲と縦横の比が違えば知らせる(画像を範囲に合わせて伸ばすので、地点の位置がずれて見える)
//   - 階を足すときは、種類で「建物の手がかり」の欄と並び順の既定を切り替える
//   - zip の表: 当てる階を変えたら、縦横の比を確かめ直す。同じ階に 2 枚当てたら知らせる

(function () {
  'use strict';

  // サーバー側の上限と揃える(lib/map-floors.php の KM_MAP_FLOOR_MAX_BYTES / KM_MAP_FLOOR_MAX_DIMENSION)
  const MAX_BYTES = 8 * 1024 * 1024;
  const MAX_DIMENSION = 4096;

  function formatBytes(bytes) {
    if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
  }

  // 縦横の比が 2% を超えて違うか(lib/map-floors.php の km_map_floor_aspect_differs と同じ)
  function aspectDiffers(width, height, coordWidth, coordHeight) {
    if (!width || !height || !coordWidth || !coordHeight) return false;
    return Math.abs((width / height) / (coordWidth / coordHeight) - 1) > 0.02;
  }

  document.querySelectorAll('[data-km-floor-image-form]').forEach(function (form) {
    const input = form.querySelector('[data-km-floor-file]');
    const preview = form.querySelector('[data-km-floor-preview]');
    const empty = form.querySelector('[data-km-floor-preview-empty]');
    const meta = form.querySelector('[data-km-floor-meta]');
    const error = form.querySelector('[data-km-floor-error]');
    const coordWidth = Number(form.dataset.coordWidth) || 0;
    const coordHeight = Number(form.dataset.coordHeight) || 0;
    if (!input || !preview) return;

    function show(src, text, problem) {
      preview.hidden = !src;
      if (src) preview.src = src; else preview.removeAttribute('src');
      if (empty) empty.hidden = Boolean(src) || !empty.textContent;
      meta.textContent = text || '';
      error.textContent = problem || '';
      error.hidden = !problem;
    }

    input.addEventListener('change', function () {
      const file = input.files && input.files[0];
      if (!file) { show(null, '', ''); return; }
      if (file.size > MAX_BYTES) { show(null, '', '画像は ' + formatBytes(MAX_BYTES) + ' 以内にしてください(いまは ' + formatBytes(file.size) + ')。'); return; }
      if (!/^image\/(png|jpeg|gif|webp)$/.test(file.type)) { show(null, '', 'PNG / JPEG / GIF / WebP の画像を選んでください(PDF や SVG は使えません)。'); return; }
      const reader = new FileReader();
      reader.onload = function () {
        const probe = new Image();
        probe.onload = function () {
          const w = probe.naturalWidth;
          const h = probe.naturalHeight;
          let text = w + '×' + h + 'px・' + formatBytes(file.size);
          let problem = '';
          if (w > MAX_DIMENSION || h > MAX_DIMENSION) {
            problem = '画像は縦横それぞれ ' + MAX_DIMENSION + 'px 以内にしてください。縮めてから選び直してください。';
          } else if (coordWidth && coordHeight) {
            text += aspectDiffers(w, h, coordWidth, coordHeight)
              ? ' —— ⚠ 縦横の比が座標の範囲(' + coordWidth + '×' + coordHeight + ')と違います。画像が伸びて表示され、地点の位置がずれて見えます。'
              : ' —— 縦横の比は座標の範囲(' + coordWidth + '×' + coordHeight + ')と同じです。';
          } else {
            text += ' —— 座標の範囲はこの大きさになります。';
          }
          show(reader.result, text, problem);
        };
        probe.onerror = function () { show(null, '', '画像として読めませんでした。'); };
        probe.src = reader.result;
      };
      reader.readAsDataURL(file);
    });
  });

  // ---- 階を足す: 種類で欄を切り替える ----
  const keywordsRow = document.querySelector('[data-km-keywords-row]');
  const sortInput = document.querySelector('[data-km-new-sort]');
  const kindRadios = document.querySelectorAll('input[name="kind"]');
  let sortTouched = false;
  if (sortInput) sortInput.addEventListener('input', function () { sortTouched = true; });
  function applyKind(changed) {
    const checked = document.querySelector('input[name="kind"]:checked');
    const building = checked && checked.value === 'building';
    if (keywordsRow) {
      keywordsRow.hidden = !building;
      const input = keywordsRow.querySelector('input');
      if (input) input.required = building;
    }
    // 打ち直していなければ、並び順の既定もその種類の末尾にする
    if (changed && sortInput && !sortTouched && checked) sortInput.value = checked.dataset.nextSort;
  }
  kindRadios.forEach(function (radio) { radio.addEventListener('change', function () { applyKind(true); }); });
  applyKind(false);

  // ---- zip の表 ----
  const selects = Array.from(document.querySelectorAll('[data-km-zip-assign]'));
  function checkAssign() {
    const counts = {};
    selects.forEach(function (select) { if (select.value) counts[select.value] = (counts[select.value] || 0) + 1; });
    selects.forEach(function (select) {
      const warn = select.parentElement.querySelector('[data-km-zip-warn]');
      const option = select.selectedOptions[0];
      let text = '';
      if (select.value && counts[select.value] > 1) {
        text = '同じ階に 2 枚以上当てています。どちらか一方にしてください。';
      } else if (select.value && option && aspectDiffers(Number(select.dataset.width), Number(select.dataset.height),
        Number(option.dataset.coordWidth), Number(option.dataset.coordHeight))) {
        text = '⚠ 縦横の比が座標の範囲(' + option.dataset.coordWidth + '×' + option.dataset.coordHeight + ')と違います。伸びて表示されます。';
      }
      if (warn) warn.textContent = text;
    });
  }
  selects.forEach(function (select) { select.addEventListener('change', checkAssign); });
  checkAssign();
})();
