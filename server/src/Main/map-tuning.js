/*
 * 表示調整。アプリの `MapDisplayTuningPanel` に相当する。
 *
 * ## なぜ Website にも要るのか
 *
 * アプリ側は、ノードの大きさ・ラベルの大きさ・種類ごとの形と表示・
 * 出し始める倍率まで、その場で変えられる。Website には**何も無かった**。
 * 同じ地図なのに、片方だけ「見えにくいものを見えるようにできる」状態だった。
 *
 * ## 値は閲覧者の手元に置く
 *
 * `localStorage`。**サーバーへ送らない** —— 見た目の好みであって、
 * 誰かに配るものでも、他の閲覧者に効かせるものでもない。
 * 読めない環境(プライベートウィンドウなど)では既定のまま動く。
 *
 * ## `style="…"` を書かない
 *
 * CSP に `'unsafe-inline'` が無く、style 属性には nonce もハッシュも効かない
 * (`lib/csp.php`)。色は `background-color` を DOM の API で設定する ——
 * これは属性ではなくプロパティなので CSP に掛からない。
 */
(function () {
    'use strict';

    const style = window.KM_MAP_STYLE;
    if (!style) return;

    /** 変更を反映するために呼ぶもの。app.js が入れる。 */
    const listeners = [];

    /**
     * 開くボタン。**開閉を書き換える所すべてから同じ関数を通す。**
     *
     * パネルは「ボタン」「パネルの中の閉じる」「既定に戻す(作り直し)」の
     * 3箇所から開け閉てされる。ボタンの見た目をそれぞれの所で書くと、
     * 閉じたのにボタンだけ塗られたまま、という食い違いが必ず出る。
     */
    let toggleButton = null;

    function syncToggle(hidden) {
        if (!toggleButton) return;
        toggleButton.classList.toggle('is-active', !hidden);
        toggleButton.setAttribute('aria-expanded', hidden ? 'false' : 'true');
    }

    function notify() {
        listeners.forEach(function (fn) {
            try {
                fn();
            } catch (e) {
                // 1つ失敗しても他は反映する。**地図が中途半端に止まらないように**
                if (window.console) window.console.error(e);
            }
        });
    }

    function applyTheme(tuning) {
        const root = document.documentElement;
        if (tuning.theme === 'light' || tuning.theme === 'dark') {
            root.setAttribute('data-km-theme', tuning.theme);
        } else {
            // system。**属性を消す**ことで端末の設定へ戻す
            root.removeAttribute('data-km-theme');
        }
    }

    function build(tuning) {
        const panel = document.createElement('section');
        panel.className = 'km-tuning';
        panel.hidden = true;
        panel.setAttribute('aria-label', '表示調整');

        function heading(text, level) {
            const el = document.createElement(level);
            el.textContent = text;
            panel.appendChild(el);
        }

        function slider(labelText, key, min, max) {
            const label = document.createElement('label');
            const span = document.createElement('span');
            span.textContent = labelText + ' ' + tuning[key] + '%';
            const input = document.createElement('input');
            input.type = 'range';
            input.min = String(min);
            input.max = String(max);
            input.step = '5';
            input.value = String(tuning[key]);
            input.addEventListener('input', function () {
                tuning[key] = Number(input.value);
                span.textContent = labelText + ' ' + tuning[key] + '%';
                style.save(tuning);
                notify();
            });
            label.appendChild(span);
            label.appendChild(input);
            panel.appendChild(label);
        }

        function toggle(labelText, key) {
            const wrap = document.createElement('div');
            wrap.className = 'km-tuning-check';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.checked = !!tuning[key];
            input.id = 'km-tuning-' + key;
            input.addEventListener('change', function () {
                tuning[key] = input.checked;
                style.save(tuning);
                notify();
            });
            const label = document.createElement('label');
            label.setAttribute('for', input.id);
            label.textContent = labelText;
            wrap.appendChild(input);
            wrap.appendChild(label);
            panel.appendChild(wrap);
        }

        function select(labelText, key, options) {
            const label = document.createElement('label');
            label.textContent = labelText;
            const el = document.createElement('select');
            options.forEach(function (option) {
                const opt = document.createElement('option');
                opt.value = option[0];
                opt.textContent = option[1];
                if (tuning[key] === option[0]) opt.selected = true;
                el.appendChild(opt);
            });
            el.addEventListener('change', function () {
                tuning[key] = el.value;
                style.save(tuning);
                if (key === 'theme') applyTheme(tuning);
                notify();
            });
            label.appendChild(el);
            panel.appendChild(label);
        }

        heading('表示調整', 'h2');

        select('配色', 'theme', [
            ['system', '端末の設定に合わせる'],
            ['light', '明るい'],
            ['dark', '暗い'],
        ]);

        heading('大きさ', 'h3');
        slider('地点', 'nodeSize', 50, 200);
        slider('文字', 'labelSize', 50, 200);
        select('名前の出し方', 'labelMode', [
            ['icon_and_text', '図形と文字'],
            ['icon_only', '図形だけ'],
            ['text_only', '文字だけ'],
        ]);

        heading('出すもの', 'h3');
        toggle('部屋名と建物名', 'roomNames');
        // 屋外で拡大したときの建物名(2026-09-25)。アプリの設定「拡大したときの建物名(外)」と同じ
        select('拡大したときの建物名(外)', 'facilityZoomed', [
            ['faded', '図形を消して名前を薄く出す'],
            ['hidden', '消す'],
            ['shown', 'そのまま出す'],
        ]);
        toggle('Wi-Fi ルーターの位置', 'accessPoints');
        toggle('通路の点と線', 'roadGraph');
        // 壁は「通れない所」を見せる線。図面が混んで見えるときに消せるようにする
        toggle('壁の線', 'wallLines');
        toggle('経路の長さ', 'distances');

        heading('種類ごと', 'h3');
        Object.keys(style.COLORS).forEach(function (type) {
            const row = document.createElement('div');
            row.className = 'km-tuning-type';

            const name = document.createElement('span');
            const swatch = document.createElement('span');
            swatch.className = 'km-tuning-swatch';
            // **プロパティで設定する。** style 属性を書くと CSP に弾かれる
            swatch.style.backgroundColor = style.color(type);
            name.appendChild(swatch);
            name.appendChild(document.createTextNode(style.LABELS[type] || type));
            row.appendChild(name);

            const setting = style.typeSetting(tuning, type);

            const shape = document.createElement('select');
            style.SHAPES.forEach(function (value) {
                const opt = document.createElement('option');
                opt.value = value;
                opt.textContent = style.SHAPE_LABELS[value] || value;
                if (setting.shape === value) opt.selected = true;
                shape.appendChild(opt);
            });
            shape.addEventListener('change', function () {
                tuning.types[type] = Object.assign({}, tuning.types[type], { shape: shape.value });
                style.save(tuning);
                notify();
            });
            row.appendChild(shape);

            const visible = document.createElement('input');
            visible.type = 'checkbox';
            visible.checked = setting.visible;
            visible.title = '出す';
            visible.addEventListener('change', function () {
                tuning.types[type] = Object.assign({}, tuning.types[type], { visible: visible.checked });
                style.save(tuning);
                notify();
            });
            row.appendChild(visible);

            panel.appendChild(row);
        });

        const reset = document.createElement('button');
        reset.type = 'button';
        reset.className = 'km-tuning-reset';
        reset.textContent = '既定に戻す';
        reset.addEventListener('click', function () {
            /*
             * **画面ごと作り直す。** 値だけ戻すと、開いているつまみが古い値を
             * 表示したままになり、「押したのに戻っていない」ように見える。
             */
            Object.keys(style.DEFAULTS).forEach(function (key) {
                tuning[key] = key === 'types' ? {} : style.DEFAULTS[key];
            });
            style.save(tuning);
            applyTheme(tuning);
            notify();
            const rebuilt = build(tuning);
            panel.replaceWith(rebuilt);
            rebuilt.hidden = false;
        });
        panel.appendChild(reset);

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'km-tuning-close';
        close.textContent = '閉じる';
        close.addEventListener('click', function () {
            panel.hidden = true;
            syncToggle(true);
        });
        panel.appendChild(close);

        return panel;
    }

    window.KM_MAP_TUNING = {
        /**
         * パネルと開くボタンを置く。
         *
         * @param {object} tuning app.js が持っている設定(同じ実体を書き換える)
         * @param {Function} onChange 変わったときに呼ぶ
         */
        install: function (tuning, onChange) {
            listeners.push(onChange);
            applyTheme(tuning);

            let panel = build(tuning);
            document.body.appendChild(panel);

            /*
             * 開くボタンは**左下の操作バーの中**に入れる(2026-09-05)。
             * アプリの `MapScreen.kt` が「表示調整」「検索」を左下に並べているのに合わせる。
             *
             * それまでは右下に浮いた丸のアイコンだけで、
             *   - 検索の開閉はトップバー、表示調整は右下、と操作が画面の両端に散る
             *   - ⚙ の丸が2つ(トップバーの「ダウンロード・設定」と)あって見分けが付かない
             * という状態だった。
             *
             * 操作バーが無いページ(`admin/map-editor.php`)では、
             * 従来どおり浮いた丸として body に置く。**出ない状態にはしない。**
             */
            const bar = document.getElementById('km-action-bar');
            const button = document.createElement('button');
            button.type = 'button';
            button.title = '表示調整';

            if (bar) {
                button.className = 'km-action-btn';
                const icon = document.createElement('span');
                icon.className = 'km-action-icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = '⚙';
                const label = document.createElement('span');
                label.className = 'km-action-label';
                label.textContent = '表示調整';
                button.appendChild(icon);
                button.appendChild(label);
            } else {
                button.className = 'km-tuning-btn';
                button.setAttribute('aria-label', '表示調整');
                button.textContent = '⚙';
            }

            toggleButton = button;
            syncToggle(true);

            button.addEventListener('click', function () {
                // 作り直されている可能性があるので、その都度いまの要素を掴む
                panel = document.querySelector('.km-tuning');
                if (!panel) return;
                panel.hidden = !panel.hidden;
                // 開いていることをボタンの側にも出す(押した結果がどこに出たか結び付ける)
                syncToggle(panel.hidden);
            });

            (bar || document.body).appendChild(button);
        },
    };
}());
