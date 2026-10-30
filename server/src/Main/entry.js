// entry.js
//
// 最初の画面(2026-10-30、高専祭の振り返りのフェーズ 3 の 1。利用者の答え「最初の画面で選ばせる」)。
//
// 振り返り: 「地図を開いても、どこを押せばよいか分からない」「高齢の方には難しい」。地図を開くと、大きなボタンで探し方を聞く:
//   🔢 ポスター・企画の番号 / 🔤 キーワード / 🚪 部屋の名前 / 🗂 ジャンル / 🗺 すぐ地図へ
// 選ぶと 1 画面ずつ進む: 探す → 行き先を選ぶ → 「地図で見る」か「道順(出発地を選ぶ)」→ 経路。どの画面からでも地図へ出られる。
//
// - 探すのは地図の検索と同じ一致率・同じアイコン(app.js の window.kmEntryApi)。ジャンルは上のチップ(#km-category-chips)を
//   そのまま並べ、押すとチップを押したのと同じ一覧を出す(ジャンルの中身はチップの側で決める。フェーズ 3 の 2)
// - 「次から最初の画面を出さない」を端末に覚える(localStorage。サーバーへ送らない)。展示の PC(テスト用アカウント)では出さない
// - 共有のリンク(部屋の QR・場所・経路)から来た人には出さない(開いた場所が隠れる)。地図が開けないとき(錠)も出さない
// - チュートリアルは自動では始めない(tutorial.js が window.kmEntry を見る)。この画面の「使い方を見る」から呼ぶ
// - 下の操作の帯の「🏠 はじめ」からいつでも開き直せる(data-km-entry-open)
// - 「すぐ地図へ」で閉じたときだけ、広告を出す(Main/tutorial-end.js の kmShowAd。セッションで 1 回)。行き先を選んだ人には出さない

(function () {
    'use strict';

    const SKIP_KEY = 'km-entry-skip';
    const testMode = document.querySelector('meta[name="km-test-mode"]') !== null;
    const fromShare = document.querySelector('meta[name="km-from-share"]') !== null;

    // チュートリアルに「この画面がある」ことを知らせる(読み込みの順: entry.js → tutorial.js)
    window.kmEntry = { open: function () { open(); } };

    let layer = null;
    let panel = null;
    let lastFocus = null;
    let chosen = null;

    function skipped() {
        if (testMode) return false;
        try {
            return window.localStorage.getItem(SKIP_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function setSkipped(value) {
        try {
            if (value) window.localStorage.setItem(SKIP_KEY, '1');
            else window.localStorage.removeItem(SKIP_KEY);
        } catch (e) {
            // 覚えられなくても動く(次に開いたときにまた出るだけ)
        }
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function button(className, icon, label, onClick) {
        const b = el('button', className);
        b.type = 'button';
        if (icon) b.appendChild(el('span', 'km-entry-icon', icon)).setAttribute('aria-hidden', 'true');
        b.appendChild(el('span', 'km-entry-label', label));
        b.addEventListener('click', onClick);
        return b;
    }

    function build() {
        layer = el('div', 'km-entry');
        layer.id = 'km-entry';
        panel = el('div', 'km-entry-panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-labelledby', 'km-entry-title');
        layer.appendChild(panel);
        layer.addEventListener('keydown', onKey);
        document.body.appendChild(layer);
    }

    /** 画面を入れ替える。見出し・戻る・閉じるはどの画面にも付ける */
    function show(title, body, backTo) {
        panel.replaceChildren();
        const head = el('div', 'km-entry-head');
        if (backTo) {
            head.appendChild(button('km-entry-back', '←', 'もどる', backTo));
        }
        const h = el('h2', 'km-entry-title', title);
        h.id = 'km-entry-title';
        head.appendChild(h);
        const close = button('km-entry-close', '✕', '地図へ', function () { closeToMap(true); });
        close.setAttribute('aria-label', '閉じて地図を見る');
        head.appendChild(close);
        panel.appendChild(head);
        panel.appendChild(body);
        const first = panel.querySelector('input, .km-entry-choice, .km-entry-result, .km-entry-action');
        if (first) first.focus();
    }

    function home() {
        chosen = null;
        const body = el('div', 'km-entry-body');
        body.appendChild(el('p', 'km-entry-lead', 'どうやって探しますか?'));
        const grid = el('div', 'km-entry-choices');
        grid.appendChild(button('km-entry-choice', '🔢', 'ポスター・企画の番号から', function () { finder('number'); }));
        grid.appendChild(button('km-entry-choice', '🔤', 'キーワードから', function () { finder('keyword'); }));
        grid.appendChild(button('km-entry-choice', '🚪', '部屋の名前から', function () { finder('room'); }));
        grid.appendChild(button('km-entry-choice', '🗂', 'ジャンルから', genres));
        grid.appendChild(button('km-entry-choice km-entry-choice-map', '🗺', 'すぐ地図へ', function () { closeToMap(true); }));
        body.appendChild(grid);

        const foot = el('div', 'km-entry-foot');
        // チュートリアルのスイッチが OFF なら、index.php が案内のボタンを置かない(ここにも出さない)
        const tutorialButton = document.querySelector('[data-km-tutorial-open]');
        if (tutorialButton) {
            foot.appendChild(button('km-entry-link', '❔', '使い方を見る', function () {
                closeToMap(false);
                tutorialButton.click();
            }));
        }
        /*
         * お知らせは下の 1 行(プランの「お知らせは最初の画面の下の 1 行」)。一覧のページへ。
         * スイッチが OFF なら index.php が設定の中にリンクを置かないので、ここにも出さない(新しいお知らせは「はじめに」が先に出している)
         */
        const noticeLink = document.querySelector('a[href="/notice.php"]');
        if (noticeLink) {
            const a = el('a', 'km-entry-link');
            a.href = '/notice.php';
            a.append(el('span', 'km-entry-icon', '📢'), el('span', 'km-entry-label', 'お知らせ'));
            foot.appendChild(a);
        }
        if (!testMode) {
            const label = el('label', 'km-entry-skip');
            const box = el('input');
            box.type = 'checkbox';
            box.checked = skipped();
            box.addEventListener('change', function () { setSkipped(box.checked); });
            label.append(box, document.createTextNode('次からこの画面を出さない(下の「🏠 はじめ」で開けます)'));
            foot.appendChild(label);
        }
        body.appendChild(foot);
        show('どこへ行きますか?', body, null);
    }

    const MODES = {
        number: { title: 'ポスター・企画の番号', placeholder: '例: 12', inputmode: 'numeric', types: null },
        keyword: { title: 'キーワード', placeholder: '例: 電算部、たこ焼き、受付', inputmode: 'text', types: null },
        room: { title: '部屋の名前', placeholder: '例: 2-1、物理実験室', inputmode: 'text', types: ['room'] },
    };

    /** 文字で探す画面(番号・キーワード・部屋) */
    function finder(mode, onPick, opts) {
        const api = window.kmEntryApi;
        const conf = MODES[mode] || MODES.keyword;
        const body = el('div', 'km-entry-body');
        const input = el('input', 'km-entry-input');
        input.type = 'search';
        input.placeholder = conf.placeholder;
        input.setAttribute('inputmode', conf.inputmode);
        input.setAttribute('aria-label', conf.title);
        input.autocomplete = 'off';
        input.spellcheck = false;
        const list = el('ul', 'km-entry-results');
        list.setAttribute('aria-live', 'polite');
        const render = function () {
            list.replaceChildren();
            const query = input.value.trim();
            if (query === '' || !api) return;
            const items = api.search(query, { types: conf.types, onlyRoutable: !!(opts && opts.onlyRoutable) });
            if (items.length === 0) {
                list.appendChild(el('li', 'km-entry-empty', '見つかりませんでした。ほかの言葉か、もどって別の探し方を試してください。'));
                return;
            }
            items.forEach(function (item) {
                const li = el('li');
                const b = el('button', 'km-entry-result');
                b.type = 'button';
                b.appendChild(api.icon(item));
                const text = el('span', 'km-entry-result-text');
                text.append(el('b', '', item.name), el('small', '', api.sub(item)));
                b.appendChild(text);
                b.addEventListener('click', function () { (onPick || picked)(item); });
                li.appendChild(b);
                list.appendChild(li);
            });
        };
        input.addEventListener('input', render);
        body.append(input, list);
        show(opts && opts.title ? opts.title : conf.title + 'で探す', body, opts && opts.back ? opts.back : home);
    }

    /** ジャンル: 上のチップをそのまま並べる。押すとチップと同じ一覧(地図の側)を出す */
    function genres() {
        const body = el('div', 'km-entry-body');
        const grid = el('div', 'km-entry-choices km-entry-genres');
        document.querySelectorAll('#km-category-chips [data-km-category]').forEach(function (chip) {
            const b = el('button', 'km-entry-choice', chip.textContent.trim());
            b.type = 'button';
            b.addEventListener('click', function () {
                closeToMap(false);
                chip.click();
            });
            grid.appendChild(b);
        });
        body.appendChild(grid);
        show('ジャンルから探す', body, home);
    }

    /** 行き先を選んだ: 地図で見るか、道順か */
    function picked(item) {
        chosen = item;
        const api = window.kmEntryApi;
        const body = el('div', 'km-entry-body');
        const card = el('div', 'km-entry-chosen');
        card.appendChild(api.icon(item));
        const text = el('span', 'km-entry-result-text');
        text.append(el('b', '', item.name), el('small', '', api.sub(item)));
        card.appendChild(text);
        body.appendChild(card);
        const actions = el('div', 'km-entry-actions');
        actions.appendChild(button('km-entry-action', '📍', '地図で場所を見る', function () {
            closeToMap(false);
            api.open(item);
        }));
        if (item.routable) {
            actions.appendChild(button('km-entry-action km-entry-action-route', '🚶', 'ここへの道順(出発地を選ぶ)', startPicker));
        } else {
            actions.appendChild(el('p', 'km-entry-note', 'この場所へは道順を出せません(地図で場所を見てください)。'));
        }
        body.appendChild(actions);
        show('ここへ行きますか?', body, home);
    }

    /** 出発地を選ぶ。文字で探すか、地図の上(経路の欄)で選ぶ */
    function startPicker() {
        const target = chosen;
        if (!target) return home();
        finder('keyword', function (from) {
            closeToMap(false);
            window.kmEntryApi.route(target, from);
        }, {
            title: 'どこから行きますか?',
            onlyRoutable: true,
            back: function () { picked(target); },
        });
        // 文字で探す代わりに、経路の欄(出発地の候補・テスト用の出発地)で選ぶ
        panel.querySelector('.km-entry-body').appendChild(button('km-entry-link', '🧭', '出発地は経路の欄で選ぶ', function () {
            closeToMap(false);
            window.kmEntryApi.route(target, null);
        }));
    }

    function open() {
        if (!window.kmEntryApi || document.body.classList.contains('km-map-unavailable')) return;
        if (!layer) build();
        lastFocus = document.activeElement;
        layer.hidden = false;
        document.body.classList.add('km-entry-open');
        home();
    }

    /** 閉じる。toMap は「すぐ地図へ」「✕」(行き先を選ばずに閉じた)。そのときだけ広告を出す */
    function closeToMap(toMap) {
        if (!layer || layer.hidden) return;
        layer.hidden = true;
        document.body.classList.remove('km-entry-open');
        if (toMap && lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
        if (toMap && typeof window.kmShowAd === 'function') window.setTimeout(window.kmShowAd, 300);
    }

    function onKey(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeToMap(true);
            return;
        }
        // タブで外へ抜けさせない(後ろの地図の上をフォーカスが歩き回る)
        if (event.key === 'Tab') {
            const items = Array.from(panel.querySelectorAll('button, input, a[href]')).filter(function (n) { return !n.disabled; });
            if (items.length === 0) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    }

    function startWhenReady() {
        document.querySelectorAll('[data-km-entry-open]').forEach(function (b) {
            b.addEventListener('click', function () {
                if (typeof window.kmCloseInfoPanel === 'function') window.kmCloseInfoPanel();
                open();
            });
        });
        if (window.isAdmin || fromShare || skipped()) return;
        const welcome = document.getElementById('km-welcome');
        const begin = function () {
            // 「はじめに」(利用規約など)が出ていれば、閉じてから
            if (welcome && !welcome.hidden) {
                document.addEventListener('km-welcome-closed', function once() {
                    document.removeEventListener('km-welcome-closed', once);
                    window.setTimeout(open, 200);
                });
                return;
            }
            // 読み込み画面が消えきってから(styles.css の .km-loading は 0.35 秒で消える)
            window.setTimeout(open, 500);
        };
        if (window.kmMapReady) {
            begin();
        } else {
            document.addEventListener('km-map-ready', function once() {
                document.removeEventListener('km-map-ready', once);
                begin();
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startWhenReady);
    } else {
        startWhenReady();
    }
}());
