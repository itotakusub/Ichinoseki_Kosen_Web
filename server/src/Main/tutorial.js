// tutorial.js
//
// 初回の操作案内(2026-10-15、利用者の指示「ここを押して出発点を決めてください、と矢印かハイライトで」)。
//
// 押す所を**明るく抜いて枠で囲み**、そばに吹き出し(矢印付き)で何をするかを出す。順に:
//   検索を開く → 出発地 → 目的地 → 案内開始 → 階の切り替え → カテゴリー検索 → 地図の点
// **案内の上からも押せる**(暗い幕は見た目だけで、押した操作はそのまま効く)。
// 言われた操作をすると次へ進む(出発地を選ぶ・案内開始を押す など)。「次へ」「スキップ」でも進める。
//
// いつ出すか:
//   - 初めて開いたとき 1 回(見たことは localStorage。サーバーへは送らない)。「はじめに」が出ていれば閉じてから
//   - テスト用アカウント(展示の PC)は**来場者ごと**(「始めから」のたび。lib/test-mode.php)
//   - QR(?room=)から来た人には出さない(部屋の詳細を見に来ている)。地図が開けないとき(錠)も出さない
//   - 「ダウンロード・設定」の「操作の案内を見る」からいつでも(data-km-tutorial-open)

(function () {
    'use strict';

    const SEEN_KEY = 'km-tutorial-seen';
    const VERSION = '2026-10-15';   // 中身を大きく変えたら上げる(見た人にも 1 回だけ出し直す)
    const PAD = 6;

    function byId(id) { return document.getElementById(id); }

    function visible(el) {
        if (!el) return false;
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) return false;
        const style = window.getComputedStyle(el);
        return style.visibility !== 'hidden' && style.display !== 'none' && Number(style.opacity) > 0.05;
    }

    function searchCollapsed() {
        const panel = byId('search-panel');
        return !panel || panel.classList.contains('collapsed');
    }

    /*
     * 手順。target は押す所(無ければ画面の真ん中に吹き出しだけ)。
     * when は「出す条件」、advance は「これが起きたら次へ」(要素と出来事の名前)。
     */
    const STEPS = [
        {
            target: function () { return byId('mobile-search-toggle'); },
            when: searchCollapsed,
            text: 'まず「検索」を押して、道順を調べる欄を開きます。',
            advance: function () { return [byId('mobile-search-toggle'), 'click']; },
        },
        {
            target: function () { return byId('start-input'); },
            text: 'ここを押して、出発地を決めてください。いまいる場所の名前を打つと候補が出ます。',
            advance: function () { return [byId('start-input'), 'change']; },
        },
        {
            target: function () { return byId('end-input'); },
            text: '次に、ここで行きたい場所(目的地)を選びます。',
            advance: function () { return [byId('end-input'), 'change']; },
        },
        {
            target: function () { return byId('search-btn'); },
            text: '「案内開始」を押すと、地図に道順が出ます。',
            advance: function () { return [byId('search-btn'), 'click']; },
        },
        {
            target: function () { return byId('floor-nav'); },
            text: '階はここで切り替えます。「外」は校舎の外の地図です。',
        },
        {
            target: function () { return byId('category-select'); },
            text: 'トイレや階段は「カテゴリー検索」でまとめて探せます。',
        },
        {
            target: null,
            text: '地図の上の点(部屋)を押すと、詳しい情報が出ます。「ここを目的地にする」でも道順を出せます。',
            last: true,
        },
    ];

    let layer = null;
    let spot = null;
    let card = null;
    let current = -1;
    let order = [];
    let detach = null;

    function seenKey() {
        // テスト用アカウントは来場者ごとに出す(「始めから」で区切り直す。Main/test-mode.js の visit)
        if (document.querySelector('meta[name="km-test-mode"]')) {
            try {
                return SEEN_KEY + ':visit:' + (window.sessionStorage.getItem('km-test-visit') || '');
            } catch (e) {
                return SEEN_KEY;
            }
        }
        return SEEN_KEY;
    }

    function seen() {
        try {
            const store = document.querySelector('meta[name="km-test-mode"]') ? window.sessionStorage : window.localStorage;
            return store.getItem(seenKey()) === VERSION;
        } catch (e) {
            return false;
        }
    }

    function markSeen() {
        try {
            const store = document.querySelector('meta[name="km-test-mode"]') ? window.sessionStorage : window.localStorage;
            store.setItem(seenKey(), VERSION);
        } catch (e) {
            // 覚えられなくても閉じる(次に開いたときにまた出るだけ)
        }
    }

    function build() {
        layer = document.createElement('div');
        layer.className = 'km-tour';
        spot = document.createElement('div');
        spot.className = 'km-tour-spot';
        card = document.createElement('div');
        card.className = 'km-tour-card';
        card.setAttribute('role', 'dialog');
        card.setAttribute('aria-live', 'polite');
        card.setAttribute('aria-label', '操作の案内');

        const count = document.createElement('p');
        count.className = 'km-tour-count';
        const text = document.createElement('p');
        text.className = 'km-tour-text';
        const actions = document.createElement('div');
        actions.className = 'km-tour-actions';
        const skip = document.createElement('button');
        skip.type = 'button';
        skip.className = 'secondary-btn km-tour-skip';
        skip.textContent = 'スキップ';
        skip.addEventListener('click', finish);
        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'primary-btn km-tour-next';
        next.addEventListener('click', function () { go(current + 1); });
        actions.append(skip, next);
        const arrow = document.createElement('span');
        arrow.className = 'km-tour-arrow';
        arrow.setAttribute('aria-hidden', 'true');
        card.append(arrow, count, text, actions);

        layer.append(spot, card);
        document.body.appendChild(layer);
        follow();
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);
        document.addEventListener('keydown', onKey);
    }

    /*
     * **押す所を追いかける。** 検索のシートは滑って出てくる(transform のアニメーション)ので、
     * 置いた瞬間の位置はまだ途中のことがある(スマホで、枠が欄の 300px 上に残った)。
     * 案内を出している間は毎フレーム位置を見て、動いていたら置き直す(見るのは 1 要素だけ)。
     */
    let lastRect = '';
    function follow() {
        if (!layer) return;
        const step = order[current];
        const el = step && step.target ? step.target() : null;
        if (el) {
            const r = el.getBoundingClientRect();
            const key = [r.left, r.top, r.width, r.height, window.innerWidth, window.innerHeight].map(Math.round).join(',');
            if (key !== lastRect) {
                lastRect = key;
                place();
            }
        }
        window.requestAnimationFrame(follow);
    }

    function onKey(event) {
        if (event.key === 'Escape') finish();
    }

    /** 押す所の周りに枠を置き、吹き出しを上か下(入らなければ真ん中)に置く */
    function place() {
        if (!layer || current < 0) return;
        const step = order[current];
        const el = step.target ? step.target() : null;
        const arrow = card.querySelector('.km-tour-arrow');
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const cw = Math.min(320, vw - 24);
        card.style.width = cw + 'px';

        if (!el || !visible(el)) {
            // 押す所が無い手順: 画面全体を少し暗くして、真ん中に吹き出しだけ
            spot.classList.add('is-none');
            card.classList.remove('is-below', 'is-above');
            card.style.left = Math.round((vw - cw) / 2) + 'px';
            card.style.top = Math.round(vh / 2 - card.offsetHeight / 2) + 'px';
            arrow.hidden = true;
            return;
        }

        const r = el.getBoundingClientRect();
        spot.classList.remove('is-none');
        spot.style.left = (r.left - PAD) + 'px';
        spot.style.top = (r.top - PAD) + 'px';
        spot.style.width = (r.width + PAD * 2) + 'px';
        spot.style.height = (r.height + PAD * 2) + 'px';

        const ch = card.offsetHeight;
        const gap = 16;
        const below = r.bottom + PAD + gap + ch <= vh - 8;
        const above = r.top - PAD - gap - ch >= 8;
        let top;
        let left = Math.min(Math.max(12, r.left + r.width / 2 - cw / 2), vw - cw - 12);
        card.classList.toggle('is-below', below || !above);
        card.classList.toggle('is-above', !below && above);
        if (below || !above) {
            top = Math.min(r.bottom + PAD + gap, vh - ch - 8);
        } else {
            top = r.top - PAD - gap - ch;
        }
        // 横に余裕が無い(右端の階の列など)ときは、左に置く
        if (!below && !above && r.left > cw + 40) {
            left = r.left - PAD - gap - cw;
            top = Math.min(Math.max(8, r.top + r.height / 2 - ch / 2), vh - ch - 8);
            card.classList.remove('is-below', 'is-above');
            card.classList.add('is-left');
        } else {
            card.classList.remove('is-left');
        }
        card.style.left = Math.round(left) + 'px';
        card.style.top = Math.round(top) + 'px';
        arrow.hidden = false;
        // 矢印は押す所の真ん中を指す
        if (card.classList.contains('is-left')) {
            arrow.style.left = '';
            arrow.style.top = Math.round(Math.min(Math.max(16, r.top + r.height / 2 - top), ch - 16)) + 'px';
        } else {
            arrow.style.top = '';
            arrow.style.left = Math.round(Math.min(Math.max(16, r.left + r.width / 2 - left), cw - 16)) + 'px';
        }
    }

    function go(index) {
        if (detach) { detach(); detach = null; }
        if (index >= order.length) { finish(); return; }
        current = index;
        const step = order[current];
        card.querySelector('.km-tour-count').textContent = (current + 1) + ' / ' + order.length;
        card.querySelector('.km-tour-text').textContent = step.text;
        card.querySelector('.km-tour-next').textContent = step.last ? 'はじめる' : '次へ';
        place();

        if (step.advance) {
            const pair = step.advance();
            const el = pair && pair[0];
            if (el) {
                const handler = function () {
                    // 出発地・目的地は、候補に当たる名前を選んだときだけ進む(打ちかけで進めない)
                    if (pair[1] === 'change' && String(el.value || '').trim() === '') return;
                    window.setTimeout(function () { go(current + 1); }, 250);
                };
                el.addEventListener(pair[1], handler);
                detach = function () { el.removeEventListener(pair[1], handler); };
            }
        }
    }

    function finish() {
        if (detach) { detach(); detach = null; }
        markSeen();
        if (layer) {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
            document.removeEventListener('keydown', onKey);
            layer.remove();
        }
        layer = null;
        current = -1;
    }

    function start() {
        if (layer) return;
        if (document.body.classList.contains('km-map-unavailable')) return;
        // 出す条件に合う手順だけ(検索がもう開いていれば「検索を押す」は飛ばす)
        order = STEPS.filter(function (step) { return !step.when || step.when(); });
        build();
        go(0);
    }

    function startWhenReady() {
        if (seen()) return;
        if (document.querySelector('meta[name="km-focus-room"]')) return;   // QR から来た人
        const welcome = byId('km-welcome');
        const begin = function () {
            if (welcome && !welcome.hidden) {
                document.addEventListener('km-welcome-closed', function once() {
                    document.removeEventListener('km-welcome-closed', once);
                    window.setTimeout(start, 300);
                });
                return;
            }
            // 読み込み画面が消えきってから(styles.css の .km-loading は 0.35 秒で消える)
            window.setTimeout(start, 900);
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

    function init() {
        document.querySelectorAll('[data-km-tutorial-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (typeof window.kmCloseInfoPanel === 'function') window.kmCloseInfoPanel();
                window.setTimeout(start, 200);
            });
        });
        startWhenReady();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
