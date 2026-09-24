// unlock.js
//
// パスワード解除フォームを組み立てる。**定義はここ1箇所だけ。**
//
// 出す場所は2つある:
//   1. 地図の上(app.js) …… 錠が掛かっていて、地図が1件も描けないとき
//   2. 「ダウンロード・設定」パネルの中(panel.js) …… 氏名だけを解除したいとき
//
// 以前は 2 にしか無く、地図側に錠を掛けると **入力欄へ辿り着く手段が無かった**。
// かといって同じ処理を app.js にも書くと、送信先や失敗時の文言が片方だけ直されて
// 食い違う。呼ぶ側は「どこに出すか」だけを決める。
//
// **admin/map-editor.php はこのファイルを読まない。** 管理者は guard.php で
// 錠の内側に入っているので、解除フォームを出す場面が無い。
// app.js 側は関数の有無を見てから呼ぶこと。

(function () {
    'use strict';

    /**
     * 解除フォームを container の中に作る。中身は毎回置き換える。
     *
     * @param {HTMLElement} container 差し込み先
     * @param {{heading?: string, description?: string, autoFocus?: boolean}} [options]
     */
    window.kmRenderUnlockForm = function (container, options) {
        if (!container) return;
        const opts = options || {};

        container.innerHTML = '';

        if (opts.heading) {
            const heading = document.createElement('h4');
            heading.className = 'km-unlock-heading';
            heading.textContent = opts.heading;
            container.appendChild(heading);
        }

        if (opts.description) {
            const description = document.createElement('p');
            description.className = 'km-unlock-description';
            description.textContent = opts.description;
            container.appendChild(description);
        }

        const field = document.createElement('div');
        field.className = 'km-unlock-field';

        const input = document.createElement('input');
        input.type = 'password';
        input.className = 'km-unlock-input';
        input.placeholder = 'パスワード';
        // 解除パスワードは利用者本人のものではない(掲示や口頭で配られる共有の鍵)。
        // 保存させると、端末を共有している次の人がそのまま入れてしまう。
        input.autocomplete = 'off';
        input.setAttribute('aria-label', 'パスワード');

        const button = document.createElement('button');
        button.type = 'button';
        // .primary-btn は公開ページの標準のボタン。見た目を作り直さない
        button.className = 'primary-btn km-unlock-submit';
        button.textContent = '解除';

        field.appendChild(input);
        field.appendChild(button);
        container.appendChild(field);

        const message = document.createElement('p');
        message.className = 'km-unlock-message';
        container.appendChild(message);

        let sending = false;

        function submit() {
            // 連打で同じパスワードを何度も送らない(サーバー側の回数制限を自分で使い切る)
            if (sending) return;

            const password = input.value;
            if (!password) {
                message.dataset.state = 'error';
                message.textContent = 'パスワードを入力してください。';
                return;
            }

            sending = true;
            button.disabled = true;
            message.dataset.state = 'busy';
            message.textContent = '確認中...';

            /*
             * **待ち続けない(15 秒で打ち切る)。** 応答が返らないと「確認中...」のまま
             * ボタンが押せなくなり、利用者は再読み込みして送り直す。
             */
            const controller = new AbortController();
            const timer = window.setTimeout(function () { controller.abort(); }, 15000);

            fetch('/api/map-unlock.php', {
                method: 'POST',
                body: JSON.stringify({ password: password }),
                signal: controller.signal,
            })
                .then(function (res) {
                    return res.json().catch(function () {
                        /*
                         * JSON でない応答。nginx の回数制限(429)は HTML の本文で返るので、
                         * 「通信エラー」ではなく待ってほしいことを伝える。
                         */
                        if (res.status === 429) {
                            return { success: false, message: '試行回数が多すぎます。しばらく待ってから再試行してください。' };
                        }
                        throw new Error('HTTP ' + res.status);
                    });
                })
                .then(function (body) {
                    if (body && body.success) {
                        /*
                         * **再読み込みする。** 錠の判定はサーバー側で、地図データも
                         * 見取り図も HTML と一緒に配られている。ここで画面だけ書き換えても
                         * 中身は空のままなので、取り直すのが唯一の正しい道。
                         */
                        message.dataset.state = 'ok';
                        message.textContent = '解除しました。再読み込みします...';
                        window.setTimeout(function () { window.location.reload(); }, 600);
                        return;
                    }
                    sending = false;
                    button.disabled = false;
                    message.dataset.state = 'error';
                    message.textContent = (body && body.message) || 'パスワードが違います。';
                })
                .catch(function (error) {
                    sending = false;
                    button.disabled = false;
                    message.dataset.state = 'error';
                    message.textContent = error && error.name === 'AbortError'
                        ? 'サーバーから応答がありません。時間をおいて再試行してください。'
                        : '通信エラーが発生しました。';
                })
                .finally(function () {
                    window.clearTimeout(timer);
                });
        }

        button.addEventListener('click', submit);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') submit();
        });

        if (opts.autoFocus) {
            input.focus();
        }
    };
})();
