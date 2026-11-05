// poster-bulk.js
//
// ポスター・広告の一括の出す・止める(admin/_inc/partials/poster-bulk.php。2026-11-03)。
//   - 「全部に✓」([data-km-bulk-all])で、行の✓([data-km-bulk-item])をまとめて付け外しする
//   - 選んだ数を出し、✓が 0 件のときは「✓を付けたものを…」を押せなくする
//     (押してもサーバーが断るが、押す前に分かるようにする)
//
// 管理画面の CSP はインラインの属性を動かさないので、ここで受ける。

(function () {
  'use strict';

  const toolbar = document.querySelector('[data-km-poster-bulk]');
  if (!toolbar) return;

  const items = () => Array.from(document.querySelectorAll('[data-km-bulk-item]'));
  const allBoxes = Array.from(document.querySelectorAll('[data-km-bulk-all]'));
  const count = toolbar.querySelector('[data-km-bulk-count]');
  const needsCheck = Array.from(toolbar.querySelectorAll('[data-km-bulk-needs-check]'));

  const refresh = () => {
    const list = items();
    const checked = list.filter((box) => box.checked).length;
    if (count) count.textContent = String(checked);
    needsCheck.forEach((button) => { button.disabled = checked === 0; });
    allBoxes.forEach((box) => {
      box.checked = list.length > 0 && checked === list.length;
      box.indeterminate = checked > 0 && checked < list.length;
    });
  };

  allBoxes.forEach((box) => {
    box.addEventListener('change', () => {
      items().forEach((item) => { item.checked = box.checked; });
      refresh();
    });
  });
  document.addEventListener('change', (event) => {
    if (event.target instanceof HTMLInputElement && event.target.matches('[data-km-bulk-item]')) {
      refresh();
    }
  });
  // 戻るボタンで戻ったとき、ブラウザが✓を覚えていることがある
  window.addEventListener('pageshow', refresh);
  refresh();
})();
