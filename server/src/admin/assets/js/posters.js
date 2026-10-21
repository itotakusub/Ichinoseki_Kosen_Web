// posters.js
//
// ポスター・広告(admin/posters.php。2026-10-21)の画面の動き。
//   - 画像の取り方(今のまま / ファイル / URL)で、入力欄を出し分ける
//   - プレビュー: ファイルはこの場で読み(data: に変える)、URL はサーバーに取りに行かせる(admin/api/poster-preview.php)
//   - 見出し・説明・URL・地点を打つと、プレビューの文字も変わる
//   - 地点の選ぶ欄を、名前で絞り込む
//
// 管理画面の CSP は img-src が 'self' と data: だけ。外部の URL をそのまま <img> に入れても出ないので、
// URL のプレビューはサーバーを通す。

(function () {
  'use strict';

  // ---- 地点の絞り込み(ポスターと地点の URL の両方) ----
  document.querySelectorAll('[data-km-node-filter]').forEach(function (input) {
    const select = document.getElementById(input.dataset.kmNodeFilter);
    if (!select) return;
    input.addEventListener('input', function () {
      const words = input.value.trim().toLowerCase();
      select.querySelectorAll('optgroup').forEach(function (group) {
        let visible = 0;
        group.querySelectorAll('option').forEach(function (option) {
          const hit = words === '' || option.textContent.toLowerCase().indexOf(words) >= 0;
          // 選んでいるものは隠さない(隠すと、何を選んでいるのか分からなくなる)
          option.hidden = !hit && !option.selected;
          if (!option.hidden) visible++;
        });
        group.hidden = visible === 0;
      });
    });
  });

  const form = document.querySelector('[data-km-poster-form]');
  if (!form) return;

  const fileInput = form.querySelector('[data-km-image-file]');
  const urlInput = form.querySelector('[data-km-image-url]');
  const urlButton = form.querySelector('[data-km-image-url-preview]');
  const errorBox = form.querySelector('[data-km-image-error]');
  const previewImg = form.querySelector('[data-km-preview-img]');
  const previewEmpty = form.querySelector('[data-km-preview-empty]');
  const previewMeta = form.querySelector('[data-km-preview-meta]');
  const nodeSelect = form.querySelector('#km-poster-node');
  const csrf = form.querySelector('input[name="km_csrf"]');
  // 編集で「今の画像のまま」に戻したとき用に、最初の画像を覚えておく
  const initialSrc = previewImg && !previewImg.hidden ? previewImg.getAttribute('src') : null;

  // サーバー側の上限と揃える(lib/posters.php の KM_POSTER_MAX_BYTES / KM_POSTER_MAX_DIMENSION)
  const MAX_BYTES = 8 * 1024 * 1024;
  const MAX_DIMENSION = 4096;

  function showError(text) {
    errorBox.textContent = text || '';
    errorBox.hidden = !text;
  }

  function formatBytes(bytes) {
    if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
  }

  function setPreviewImage(src, metaText) {
    if (!src) {
      previewImg.hidden = true;
      previewImg.removeAttribute('src');
      previewEmpty.hidden = false;
      previewMeta.textContent = '';
      return;
    }
    previewImg.src = src;
    previewImg.hidden = false;
    previewEmpty.hidden = true;
    previewMeta.textContent = metaText || '';
  }

  // ---- 画像の取り方 ----
  function currentSource() {
    const checked = form.querySelector('input[name="image_source"]:checked');
    return checked ? checked.value : 'file';
  }

  function applySource() {
    const source = currentSource();
    form.querySelectorAll('[data-km-src-panel]').forEach(function (panel) {
      panel.hidden = panel.dataset.kmSrcPanel !== source;
    });
    // 選んだ取り方の欄だけ必須にする(今のままなら要らない)
    fileInput.required = source === 'file';
    urlInput.required = source === 'url';
    showError('');
    if (source === 'keep') {
      setPreviewImage(initialSrc, '今の画像');
    } else if (source === 'file') {
      previewFile();
    } else {
      setPreviewImage(null);
    }
  }

  form.querySelectorAll('input[name="image_source"]').forEach(function (radio) {
    radio.addEventListener('change', applySource);
  });

  // ---- ファイルのプレビュー(この場で読む。サーバーへはまだ送らない) ----
  function previewFile() {
    const file = fileInput.files && fileInput.files[0];
    if (!file) {
      setPreviewImage(null);
      return;
    }
    if (file.size > MAX_BYTES) {
      setPreviewImage(null);
      showError('画像は ' + formatBytes(MAX_BYTES) + ' 以内にしてください(いまは ' + formatBytes(file.size) + ')。');
      return;
    }
    if (!/^image\/(png|jpeg|gif|webp)$/.test(file.type)) {
      setPreviewImage(null);
      showError('PNG / JPEG / GIF / WebP の画像を選んでください(PDF や SVG は使えません)。');
      return;
    }
    const reader = new FileReader();
    reader.onload = function () {
      const probe = new Image();
      probe.onload = function () {
        if (probe.naturalWidth > MAX_DIMENSION || probe.naturalHeight > MAX_DIMENSION) {
          showError('画像は縦横それぞれ ' + MAX_DIMENSION + 'px 以内にしてください(いまは '
            + probe.naturalWidth + '×' + probe.naturalHeight + 'px)。縮めてから選び直してください。');
        } else {
          showError('');
        }
        setPreviewImage(reader.result, probe.naturalWidth + '×' + probe.naturalHeight + 'px・' + formatBytes(file.size));
      };
      probe.onerror = function () {
        setPreviewImage(null);
        showError('画像として読めませんでした。');
      };
      probe.src = reader.result;
    };
    reader.readAsDataURL(file);
  }

  fileInput.addEventListener('change', previewFile);

  // ---- URL のプレビュー(サーバーが取りに行き、確かめた画像を data: で返す) ----
  let urlRequest = 0;
  function previewUrl() {
    const url = urlInput.value.trim();
    if (!url) {
      showError('画像の URL を入れてください。');
      return;
    }
    const request = ++urlRequest;
    urlButton.disabled = true;
    showError('');
    previewMeta.textContent = '取りに行っています…';
    fetch('./api/poster-preview.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-KM-CSRF': csrf ? csrf.value : '' },
      body: JSON.stringify({ url: url }),
      credentials: 'same-origin',
    })
      .then(function (response) {
        return response.json().catch(function () { return { error: '応答を読めませんでした(' + response.status + ')。' }; });
      })
      .then(function (body) {
        if (request !== urlRequest) return;   // 押し直された。古い答えは捨てる
        if (body.error) {
          setPreviewImage(null);
          showError(body.error);
          return;
        }
        setPreviewImage(body.dataUrl, body.width + '×' + body.height + 'px・' + formatBytes(body.bytes) + '(保存するとこの画像を取り込みます)');
      })
      .catch(function () {
        if (request !== urlRequest) return;
        setPreviewImage(null);
        showError('取りに行けませんでした。回線を確かめてください。');
      })
      .finally(function () {
        urlButton.disabled = false;
      });
  }

  urlButton.addEventListener('click', previewUrl);
  urlInput.addEventListener('keydown', function (event) {
    // Enter で保存まで走らせない(プレビューにとどめる)
    if (event.key === 'Enter') {
      event.preventDefault();
      previewUrl();
    }
  });

  // ---- 文字のプレビュー ----
  const title = form.querySelector('[data-km-preview-input="title"]');
  const description = form.querySelector('[data-km-preview-input="description"]');
  const link = form.querySelector('[data-km-preview-input="link"]');
  const previewTitle = form.querySelector('[data-km-preview-title]');
  const previewDesc = form.querySelector('[data-km-preview-desc]');
  const previewPlace = form.querySelector('[data-km-preview-place]');
  const previewLink = form.querySelector('[data-km-preview-link]');

  function renderText() {
    previewTitle.textContent = title.value.trim() || '(見出し)';
    previewDesc.textContent = description.value.trim();
    const option = nodeSelect && nodeSelect.selectedOptions[0];
    previewPlace.textContent = option && option.value ? '📍 ' + option.textContent : '📢 広告(地点に置かない。チュートリアルの終わりに確率で出て、一覧のページにも出ます)';
    previewLink.hidden = link.value.trim() === '';
  }

  [title, description, link].forEach(function (input) { input.addEventListener('input', renderText); });
  if (nodeSelect) nodeSelect.addEventListener('change', renderText);

  renderText();
  applySource();
})();
