// poster-import.js
//
// ポスター・広告(admin/posters.php)の「まとめて取り込む」(2026-10-21。lib/poster-import.php)。
//   - 番号を 1 つずつ admin/api/poster-import.php へ送る(1 回の要求を短く保つ。まとめて送ると時間切れになる)
//   - 相手のサイトに負担を掛けないよう、1 件ごとに少し待つ
//   - 「止める」で、今の 1 件が終わったところで止まる
//   - 番号 0 は使わない(画面の最小値も 1。サーバーも断る)

(function () {
  'use strict';

  const form = document.querySelector('[data-km-poster-import]');
  if (!form) return;

  const startButton = form.querySelector('[data-km-import-start]');
  const stopButton = form.querySelector('[data-km-import-stop]');
  const progress = form.querySelector('[data-km-import-progress]');
  const bar = form.querySelector('[data-km-import-bar]');
  const summary = form.querySelector('[data-km-import-summary]');
  const log = form.querySelector('[data-km-import-log]');
  const reload = form.querySelector('[data-km-import-reload]');
  const csrf = form.querySelector('input[name="km_csrf"]');

  const WAIT_MS = 400;
  const LABELS = {
    created: '✅ 作った',
    updated: '🔁 直した',
    unchanged: '・ 変わりなし',
    missing: '— 無い番号',
    error: '⚠️ 失敗',
  };

  let stopRequested = false;

  function wait(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  function addLine(number, status, text) {
    const item = document.createElement('li');
    item.value = number;
    item.textContent = (LABELS[status] || status) + ' ' + text;
    if (status === 'error') item.className = 'text-danger';
    log.appendChild(item);
  }

  function importOne(template, number, enabled) {
    return fetch('./api/poster-import.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-KM-CSRF': csrf ? csrf.value : '' },
      body: JSON.stringify({ template: template, number: number, enabled: enabled }),
      credentials: 'same-origin',
    }).then(function (response) {
      return response.json().catch(function () { return { error: '応答を読めませんでした(' + response.status + ')。' }; });
    }).catch(function () {
      return { error: '送れませんでした。回線を確かめてください。' };
    });
  }

  stopButton.addEventListener('click', function () {
    stopRequested = true;
    stopButton.disabled = true;
    stopButton.textContent = '止めています…';
  });

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    const template = form.elements.template.value.trim();
    const first = parseInt(form.elements.first.value, 10);
    const last = parseInt(form.elements.last.value, 10);
    const enabled = form.elements.enabled.checked;
    if (template.indexOf('%N') < 0) {
      window.alert('URL に、番号の入る場所 %N を入れてください。');
      return;
    }
    if (!(first >= 1) || !(last >= first)) {
      window.alert('番号は 1 以上で、最後の番号は最初の番号以上にしてください(0 は使いません)。');
      return;
    }

    stopRequested = false;
    startButton.disabled = true;
    stopButton.hidden = false;
    stopButton.disabled = false;
    stopButton.textContent = '止める';
    progress.hidden = false;
    reload.hidden = true;
    log.textContent = '';
    bar.max = last - first + 1;
    bar.value = 0;

    const counts = { created: 0, updated: 0, unchanged: 0, missing: 0, error: 0 };
    function renderSummary(prefix) {
      summary.textContent = prefix + ' 作った ' + counts.created + '・直した ' + counts.updated
        + '・変わりなし ' + counts.unchanged + '・無い番号 ' + counts.missing + '・失敗 ' + counts.error;
    }

    for (let number = first; number <= last; number++) {
      if (stopRequested) break;
      renderSummary('No.' + number + ' を読んでいます…');
      const body = await importOne(template, number, enabled);
      if (body.error) {
        counts.error++;
        addLine(number, 'error', 'No.' + number + ': ' + body.error);
      } else {
        counts[body.status] = (counts[body.status] || 0) + 1;
        addLine(number, body.status, 'No.' + number + (body.title ? ' ' + body.title : '') + '(' + body.message + ')');
      }
      bar.value = number - first + 1;
      if (number < last) await wait(WAIT_MS);
    }

    renderSummary(stopRequested ? '止めました。' : '終わりました。');
    startButton.disabled = false;
    stopButton.hidden = true;
    reload.hidden = false;
  });
})();
