/*
 * 管理画面のお知らせを ✗ で閉じる(2026-10-05、利用者の指示)。
 *
 * 対象は各ページの上にある**情報のお知らせ**(`.alert.alert-info.d-flex.align-items-start` の形)だけ。
 * エラー・設定の警告・送信の結果(flash)は閉じられないままにする —— 見落とすと困るものを消させない。
 *
 * 閉じたものは**この端末のブラウザ**(localStorage)に覚える。サーバーには何も送らない
 * (サーバーのメモリを使わない。利用者の指示「クライアント側で行える処理」)。
 * 覚える名前は「ページのパス + お知らせの文の辞書キー」。文を書き換えて**キーが変わったら、また出る。**
 * `data-km-notice="名前"` があればそれを使い、`data-km-notice-fixed` があれば閉じられないままにする。
 */
(() => {
  const STORAGE_KEY = 'kmadmin-dismissed';

  const load = () => {
    try {
      const value = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '[]');
      return Array.isArray(value) ? value : [];
    } catch {
      return [];
    }
  };

  const save = (list) => {
    try {
      // 増え続けないよう、新しい方から 200 件だけ残す
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(list.slice(-200)));
    } catch {
      // 保存できない(プライベートモードなど)なら、その場で隠すだけ
    }
  };

  const noticeId = (alert) => {
    if (alert.dataset.kmNotice) {
      return `${window.location.pathname}|${alert.dataset.kmNotice}`;
    }
    const keyed = alert.querySelector('[data-i18n-html], [data-i18n]');
    const key = keyed ? (keyed.getAttribute('data-i18n-html') || keyed.getAttribute('data-i18n')) : '';
    return key ? `${window.location.pathname}|${key}` : '';
  };

  const label = () => (document.documentElement.lang || '').startsWith('en') ? 'Dismiss' : '閉じる';

  document.addEventListener('DOMContentLoaded', () => {
    const dismissed = new Set(load());
    document.querySelectorAll('.app-content .alert.alert-info.d-flex.align-items-start').forEach((alert) => {
      if (alert.dataset.kmNoticeFixed !== undefined) {
        return;
      }
      const id = noticeId(alert);
      if (!id) {
        return;
      }
      if (dismissed.has(id)) {
        alert.remove();
        return;
      }
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn-close ms-auto flex-shrink-0';
      button.setAttribute('aria-label', label());
      button.addEventListener('click', () => {
        const list = load().filter((item) => item !== id);
        list.push(id);
        save(list);
        alert.remove();
      });
      alert.appendChild(button);
    });
  });
})();
