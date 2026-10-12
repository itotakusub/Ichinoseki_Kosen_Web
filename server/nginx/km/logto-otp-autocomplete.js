/*
 * Logto のサインイン画面の確認コード欄に autocomplete="one-time-code" を付ける(2026-10-12、利用者の希望)。
 *
 * nginx(default.conf.template の 3001)が Logto の HTML の </head> の前に差し込む。Logto の中身には手を入れない。
 *
 * - Logto 1.44.0 のコードの 6 つの枠は autocomplete="off" で、ブラウザや OS に「確認コードの欄」と伝わらず、
 *   メールのコードの自動入力の候補が出ない(packages/experience/src/shared/components/VerificationCode)。
 * - 1 つ目の枠(data-id="0")に 6 桁がまとめて入れば、Logto の onInput / onPaste が 6 つの枠に分けて入れる。
 *   だから付けるのは 1 つ目の枠だけ。
 * - React は値の変わらない prop を書き戻さないので、付けた属性は残る。画面が切り替わって枠が作り直されたら付け直す。
 *
 * **付けるのは属性 1 つだけ。** 枠の値は読まない・送らない・保存しない。
 * 当たる枠が無い画面(Logto の更新で作りが変わった場合も)では何もしない。
 */
(function () {
  'use strict';

  var SELECTOR = 'input[inputmode="numeric"][data-id="0"]';

  function mark() {
    var inputs = document.querySelectorAll(SELECTOR);
    for (var i = 0; i < inputs.length; i++) {
      if (inputs[i].getAttribute('autocomplete') !== 'one-time-code') {
        inputs[i].setAttribute('autocomplete', 'one-time-code');
      }
    }
  }

  mark();
  new MutationObserver(mark).observe(document.documentElement, { childList: true, subtree: true });
})();
