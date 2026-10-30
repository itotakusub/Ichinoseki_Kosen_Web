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
//     **2026-10-30 から、最初の画面(Main/entry.js)がある地図では自動で始めない**(その画面の「使い方を見る」から)
//   - テスト用アカウント(展示の PC)は**来場者ごと**(「始めから」のたび。lib/test-mode.php)
//   - QR(?room=)から来た人には出さない(部屋の詳細を見に来ている)。地図が開けないとき(錠)も出さない
//   - 「ダウンロード・設定」の「操作の案内を見る」からいつでも(data-km-tutorial-open)

(function () {
    'use strict';

    const SEEN_KEY = 'km-tutorial-seen';
    const VERSION = '2026-10-15';   // 中身を大きく変えたら上げる(見た人にも 1 回だけ出し直す)
    const PAD = 6;

    function byId(id) { return document.getElementById(id); }

    /*
     * **いま見えている範囲**(2026-10-19、利用者の指摘「スマホでチュートリアルの表示がうまくいっていない」)。
     * スマホで欄を押すとキーボードが出て、見える範囲(visual viewport)が半分ほどになる。
     * window.innerHeight はキーボードを差し引かないので、それで置くと吹き出しがキーボードの裏に隠れた。
     * 位置(getBoundingClientRect・position: fixed)はどちらもレイアウトの座標なので、見える範囲もその座標で返す。
     */
    function viewportBox() {
        const vv = window.visualViewport;
        if (vv) {
            return { top: vv.offsetTop, left: vv.offsetLeft, width: vv.width, height: vv.height };
        }
        return { top: 0, left: 0, width: window.innerWidth, height: window.innerHeight };
    }

    /**
     * 押す所が見えているか。**見える範囲の外にあるものは見えていないとみなす**(2026-10-19)。
     * スマホでは検索の欄が閉じると、中の欄(カテゴリー検索など)は画面の下の外へ滑っていく。
     * 以前は大きさだけを見ていたので、外にある欄に枠と吹き出しを置き、**暗い幕だけが残って吹き出しが見えなかった。**
     */
    function visible(el) {
        if (!el) return false;
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) return false;
        const style = window.getComputedStyle(el);
        if (style.visibility === 'hidden' || style.display === 'none' || Number(style.opacity) <= 0.05) return false;
        const box = viewportBox();
        return r.bottom > box.top + 4 && r.top < box.top + box.height - 4
            && r.right > box.left + 4 && r.left < box.left + box.width - 4;
    }

    function narrowScreen() {
        return window.innerWidth <= 768;
    }

    /*
     * 押す所が見えないときの代わり。**検索の欄の中にある所**(出発地・目的地・案内開始・カテゴリー検索)は、
     * スマホでは欄が閉じていると見えないので、「検索」のボタンを指して開いてもらう。開けば毎フレームの見直しで元の手順に戻る。
     */
    const OPEN_SEARCH_TEXT = '「経路」を押して、道順を調べる欄を開いてください。';
    function resolveTarget(step) {
        const el = step.target ? step.target() : null;
        if (el && visible(el)) return { el: el, text: step.text };
        const toggle = byId('mobile-search-toggle');
        if (el && el.closest && el.closest('#search-panel') && toggle && visible(toggle)) {
            return { el: toggle, text: step.fallbackText || OPEN_SEARCH_TEXT };
        }
        return { el: null, text: step.text };
    }

    /*
     * 手順。target は押す所(無ければ画面の真ん中に吹き出しだけ)。
     * when は「出す条件」、advance は「これが起きたら次へ」(要素と出来事の名前)。
     */
    /*
     * 2026-10-23 から Google マップ風の流れ(場所を検索 → 左の引き出しに詳細 → 「経路」で出発地を選ぶ)。
     * 場所を選ぶと Main/app.js が検索の窓に km-place-chosen を出す
     */
    const STEPS = [
        {
            target: function () { return byId('km-place-input'); },
            text: 'ここに行きたい場所の名前を打つと、近い順に候補が出ます。候補を選ぶと、その場所が開きます。',
            advance: function () { return [byId('km-place-input'), 'km-place-chosen']; },
        },
        {
            target: function () { return document.querySelector('#km-sheet .km-detail-route'); },
            text: '「経路」を押すと、ここまでの道順を調べられます。',
            fallbackText: '地図の上の点(部屋)を押しても、その場所の詳しい情報が出ます。',
            advance: function () { return [document.querySelector('#km-sheet .km-detail-route'), 'click']; },
        },
        {
            target: function () { return byId('start-input'); },
            text: 'いまいる場所(出発地)を打って選ぶと、地図に道順が出ます。',
            advance: function () { return [byId('start-input'), 'change']; },
        },
        {
            target: function () { return byId('floor-nav'); },
            text: '階はここで切り替えます。「外」は校舎の外の地図です。道順を出したままでも、拡大したまま移れます。',
        },
        {
            target: function () { return byId('km-category-chips'); },
            text: 'トイレや階段は、ここのボタンでまとめて探せます。',
        },
        {
            target: null,
            text: '場所の詳細の「共有」で、その場所や道順のリンクを送れます。',
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
        // キーボードの出し入れで見える範囲が変わる(window の resize は来ないことがある)
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', place);
            window.visualViewport.addEventListener('scroll', place);
        }
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
        if (step) {
            // 押す所(と、見えないときの代わり)の位置と、見える範囲(キーボードで縮む)を見る
            const resolved = resolveTarget(step);
            const r = resolved.el ? resolved.el.getBoundingClientRect() : { left: 0, top: 0, width: 0, height: 0 };
            const box = viewportBox();
            const key = [r.left, r.top, r.width, r.height, box.top, box.left, box.width, box.height].map(Math.round).join(',')
                + '|' + resolved.text;
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

    /**
     * 押す所の周りに枠を置き、吹き出しを上か下(入らなければ横、それも駄目なら真ん中)に置く。
     * **吹き出しは必ず見えている範囲に収める**(2026-10-19)。見える範囲はキーボードで縮む(viewportBox)。
     */
    function place() {
        if (!layer || current < 0) return;
        const step = order[current];
        const resolved = resolveTarget(step);
        const el = resolved.el;
        const textEl = card.querySelector('.km-tour-text');
        if (textEl.textContent !== resolved.text) textEl.textContent = resolved.text;
        const arrow = card.querySelector('.km-tour-arrow');
        const box = viewportBox();
        const minTop = box.top + 8;
        const maxBottom = box.top + box.height - 8;
        const cw = Math.min(320, box.width - 24);
        card.style.width = cw + 'px';
        // キーボードで見える範囲がとても狭いときは、本文を巻物にする(はみ出して隠れないように)。
        // 吹き出し全体を巻物にすると、外にはみ出して描いている矢印が切れるので、本文だけ
        textEl.style.maxHeight = Math.max(48, Math.round(box.height - 120)) + 'px';
        const clampTop = function (value, height) {
            return Math.max(minTop, Math.min(value, maxBottom - height));
        };

        if (!el) {
            // 押す所が無い手順: 画面全体を少し暗くして、見えている範囲の真ん中に吹き出しだけ
            spot.classList.add('is-none');
            card.classList.remove('is-below', 'is-above', 'is-left');
            card.style.left = Math.round(box.left + (box.width - cw) / 2) + 'px';
            card.style.top = Math.round(clampTop(box.top + box.height / 2 - card.offsetHeight / 2, card.offsetHeight)) + 'px';
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
        const fitsBelow = r.bottom + PAD + gap + ch <= maxBottom;
        const fitsAbove = r.top - PAD - gap - ch >= minTop;
        /*
         * 置く向きは「下に入るなら下」。**スマホで経路の欄の中を指すときだけは下に固定する**(2026-10-24)。
         * 欄は画面の**上いっぱい**に出るようになった(キーボードに隠れないようにするため)ので、上には何も置けない ——
         * それまでは上に寄せていた(欄が下から出るシートで、下に置くと次の欄とキーボードに隠れたため)。
         * 下に入りきらなくても、吹き出しは必ず見える範囲に収める(clampTop)
         */
        const inRoutePanel = narrowScreen() && el.closest && el.closest('#search-panel') !== null;
        const placeAbove = !inRoutePanel && !fitsBelow && fitsAbove;
        let left = Math.min(Math.max(box.left + 12, r.left + r.width / 2 - cw / 2), box.left + box.width - cw - 12);
        let top = placeAbove ? r.top - PAD - gap - ch : r.bottom + PAD + gap;
        card.classList.toggle('is-below', !placeAbove);
        card.classList.toggle('is-above', placeAbove);
        // 上にも下にも入らず、横に余裕がある(右端の階の列など)ときは、左に置く
        if (!fitsBelow && !fitsAbove && r.left - box.left > cw + 40) {
            left = r.left - PAD - gap - cw;
            top = r.top + r.height / 2 - ch / 2;
            card.classList.remove('is-below', 'is-above');
            card.classList.add('is-left');
        } else {
            card.classList.remove('is-left');
        }
        top = clampTop(top, ch);
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
        const wasRunning = layer !== null;
        if (layer) {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
            if (window.visualViewport) {
                window.visualViewport.removeEventListener('resize', place);
                window.visualViewport.removeEventListener('scroll', place);
            }
            document.removeEventListener('keydown', onKey);
            layer.remove();
        }
        const lastIndex = current;
        layer = null;
        current = -1;
        /*
         * 終わったことを知らせる(2026-10-21)。最後まで進んだときも、スキップ・Esc のときも出す ——
         * 受けるのは Main/tutorial-end.js(広告を確率で出し、閉じたら「チュートリアルが終わりました」の知らせ)。
         */
        if (wasRunning) {
            document.dispatchEvent(new CustomEvent('km-tutorial-finished', {
                detail: { completed: lastIndex >= order.length - 1 },
            }));
        }
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
        /*
         * 最初の画面(Main/entry.js)があるときは自動では始めない(2026-10-30、振り返りのフェーズ 3 の 1「チュートリアルは入口から呼べる使い方に下げる」)。
         * 最初の画面の「使い方を見る」と、ダウンロード・設定の「操作の案内を見る」から始める
         */
        if (window.kmEntry) return;
        /*
         * 共有のリンク(部屋の QR・場所・経路)から来た人には出さない(2026-10-24、利用者の指示)。印は index.php の
         * <meta name="km-from-share">。見たことにはしないので、あとで普通に開いたときには出る。広告は tutorial-end.js の kmShowAd が出す
         */
        if (document.querySelector('meta[name="km-from-share"]')) return;
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
