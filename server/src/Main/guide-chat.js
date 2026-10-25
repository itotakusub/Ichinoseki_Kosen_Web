/*
 * 案内チャット(2026-10-24、利用者の企画書「模擬店案内・会場内ルート案内システム」)。
 *
 * 左下の「💬 案内」で左の引き出し(Main/km-sheet.js)にチャットを開く。質問は api/guide-chat.php へ送り、
 * 返ってきた答えの文と、**サーバーが登録から作ったカード**を出す(カードは LLM の文からは作らない)。
 * カードの「地図で見る」は今の地図の詳細(kmOpenNode / kmOpenPoi)、「経路」は今の経路の案内(setAsLocation)。
 * 経路そのものはブラウザの dijkstra.js が引く(地図の案内と同じ)。
 *
 * 出すのは、管理画面「案内チャット」で選んだテスト用アカウントで開いたときだけ(index.php が読み込みを決める)。
 * 端末には残さない。サーバーは機能をよくするために質問と答えを残す(2026-10-25、利用者の指示。誰が聞いたかは残さない)。
 * 文字はすべて textContent で入れる(HTML として読まない)。
 *
 * 2026-10-25、利用者「AI は間違えることがありますと表示し、AI 案内は常に表示」→「チャット欄を開いたままに」:
 *   - 開いたページでチャットを引き出しの**土台**にする(KmSheet.setBase)。場所の詳細などを開いても、閉じればチャットに戻る
 *   - 入力欄の下に、いつも「AI は間違えることがあります」と、質問と答えを残すことを書く
 */
(function () {
    'use strict';

    const openButton = document.getElementById('km-guide-open');
    if (!openButton) return;
    const csrfMeta = document.querySelector('meta[name="km-csrf"]');
    const csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
    const QUESTION_MAX = 200;

    let box = null;       // 引き出しの中身(開き直しても会話を残す。ページを閉じれば消える)
    let log = null;
    let input = null;
    let sendButton = null;
    let busy = false;

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    /*
     * 例(2026-10-25、利用者「ページに例を上げてそれを押せば自動入力して送信」「企画番号で検索をかけたほうが正答しやすいので例に」
     * 「苦手・生成しにくい文も例として」)。押せる例は入力して送る。苦手な質問は理由と並べるだけ(押しても送らない)
     */
    const EXAMPLES = [
        { text: '企画番号 8 の場所を教えて', note: 'いちばん確実' },
        { text: '食べ物の店を教えて' },
        { text: '怖い企画はどこ？' },
        { text: 'トイレはどこ？' },
        { text: '第二実習室から体育館への行き方' },
        { text: '休憩できる場所は？' },
    ];
    const HARD = [
        ['おすすめ・人気の店は？', '順位の元になるデータ(味・人気)がありません'],
        ['いちばん空いている店は？', '待ち時間・混み具合のデータがありません'],
        ['たこ焼きはいくら？', '値段・メニューは、ポスターの説明に書いてあるときだけ答えられます'],
        ['ロボコンの展示は？', '登録の名前と違う呼び方・略し方は見つけにくいです。企画番号か、ポスターの名前で聞いてください'],
        ['近くのトイレは？', 'いまいる場所が分かりません。「◯◯から△△への行き方」のように場所の名前を入れてください'],
        ['「あ」「こんにちは」など', '短すぎる言葉・あいさつには答えられません'],
    ];

    function examplesBlock() {
        const wrap = el('div', 'km-guide-examples');
        wrap.appendChild(el('p', 'km-guide-examples-head', '例(押すと聞けます)'));
        const list = el('div', 'km-guide-chips');
        EXAMPLES.forEach((example) => {
            const chip = el('button', 'km-guide-chip' + (example.note ? ' is-recommended' : ''), example.text);
            chip.type = 'button';
            if (example.note) chip.appendChild(el('span', 'km-guide-chip-note', example.note));
            chip.addEventListener('click', () => {
                if (busy) return;
                input.value = example.text;
                ask(example.text);
            });
            list.appendChild(chip);
        });
        wrap.appendChild(list);
        const hard = el('details', 'km-guide-hard');
        hard.appendChild(el('summary', '', '苦手な質問(答えられない・まちがえやすい)'));
        const items = el('ul', 'km-guide-hard-list');
        HARD.forEach(([text, reason]) => {
            const li = el('li');
            li.append(el('b', '', text), el('span', '', reason));
            items.appendChild(li);
        });
        hard.appendChild(items);
        wrap.appendChild(hard);
        return wrap;
    }

    function build() {
        box = el('div', 'km-guide');
        log = el('div', 'km-guide-log');
        log.setAttribute('aria-live', 'polite');
        log.appendChild(bubble('bot', '模擬店や場所を聞いてください。企画番号で聞くと、いちばん確実に見つかります。'));
        log.appendChild(examplesBlock());

        const form = el('form', 'km-guide-form');
        input = el('input', 'km-guide-input');
        input.type = 'text';
        input.maxLength = QUESTION_MAX;
        input.placeholder = '質問を入力';
        input.setAttribute('aria-label', '質問');
        input.autocomplete = 'off';
        sendButton = el('button', 'primary-btn km-guide-send', '送る');
        sendButton.type = 'submit';
        form.append(input, sendButton);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            ask(input.value.trim());
        });
        const note = el('p', 'km-guide-note', 'AIは間違えることがあります。大事なことは会場の案内や係の人に確かめてください。質問と答えは、案内をよくするために保存します(誰が聞いたかは残しません)。');
        // 入力欄と注意書きは一緒に下へ貼り付ける(会話が長くなっても注意書きが見える)
        const foot = el('div', 'km-guide-foot');
        foot.append(form, note);
        box.append(log, foot);
    }

    function bubble(who, text) {
        const node = el('div', 'km-guide-bubble km-guide-' + who, text);
        return node;
    }

    function scrollToEnd() {
        const body = log.closest('.km-sheet-body');
        (body || log).scrollTop = (body || log).scrollHeight;
    }

    function cardFor(card) {
        const node = el('div', 'km-guide-card');
        node.appendChild(el('b', 'km-guide-card-name', card.name));
        const sub = [card.category, `${card.place}(${card.floor})`].filter(Boolean).join(' · ');
        node.appendChild(el('span', 'km-guide-card-sub', sub));
        if (card.detail) node.appendChild(el('span', 'km-guide-card-detail', card.detail));
        const meta = [];
        // 質問との関係の率(2026-10-25。LLM が付けたときだけ)
        if (typeof card.relevance === 'number') meta.push('関係 ' + card.relevance + '%');
        if (card.likes > 0) meta.push('♡ ' + card.likes);
        if (Array.isArray(card.times) && card.times.length > 0) meta.push(card.times.join('、'));
        if (meta.length > 0) node.appendChild(el('span', 'km-guide-card-meta', meta.join(' · ')));

        const actions = el('div', 'km-guide-card-actions');
        const look = el('button', 'secondary-btn', '地図で見る');
        look.type = 'button';
        look.addEventListener('click', () => {
            if (card.poiId && typeof window.kmOpenPoi === 'function') window.kmOpenPoi(card.poiId);
            else if (card.nodeId && typeof window.kmOpenNode === 'function') window.kmOpenNode(card.nodeId);
        });
        actions.appendChild(look);
        if (card.nodeId && typeof window.setAsLocation === 'function') {
            const route = el('button', 'primary-btn', '経路');
            route.type = 'button';
            route.addEventListener('click', () => {
                if (typeof window.kmClearRouteEnds === 'function') window.kmClearRouteEnds();
                // 目的地にする。出発地は経路の欄で選んでもらう(片方だけなら欄が開く)
                window.setAsLocation(card.nodeId, 'end', card.poiId ? { poiId: card.poiId } : undefined);
            });
            actions.appendChild(route);
        }
        node.appendChild(actions);
        return node;
    }

    /*
     * 評価(2026-10-25、利用者「評価も付けられるようにし、BAD だったら理由を聞く」)。
     * 👍 はそのまま送る。👎 は理由を選んでもらい(ひとことは任意)、送る。理由は lib/guide-chat.php の KM_GUIDE_BAD_REASONS と同じ
     */
    const BAD_REASONS = [
        ['place', '場所や店が違う'],
        ['route', '経路が違う'],
        ['missing', '探しているものが出てこない'],
        ['wrong', '答えの内容がまちがっている'],
        ['unclear', '答えが分かりにくい'],
        ['slow', '答えが遅い'],
        ['other', 'その他'],
    ];
    const NOTE_MAX = 200;
    let rateSeq = 0;

    async function sendRating(rate) {
        const response = await fetch('/api/guide-chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-KM-CSRF': csrf },
            credentials: 'same-origin',
            body: JSON.stringify({ rate: rate }),
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.success !== true) {
            throw new Error((data && data.message) || '評価を送れませんでした。');
        }
    }

    function ratingFor(token) {
        const wrap = el('div', 'km-guide-rate');
        const row = el('div', 'km-guide-rate-row');
        row.appendChild(el('span', 'km-guide-rate-label', 'この答えはどうでしたか?'));
        const good = el('button', 'secondary-btn km-guide-rate-btn', '👍 よい');
        const bad = el('button', 'secondary-btn km-guide-rate-btn', '👎 よくない');
        good.type = 'button';
        bad.type = 'button';
        good.setAttribute('aria-label', 'よい答えだった');
        bad.setAttribute('aria-label', 'よくない答えだった');
        row.append(good, bad);
        const status = el('span', 'km-guide-rate-status');
        status.setAttribute('role', 'status');
        wrap.append(row, status);

        function done(text) {
            row.remove();
            form.remove();
            status.textContent = text;
        }

        // 👎 の理由を聞く欄(押してから出す)
        const form = el('form', 'km-guide-rate-form');
        form.hidden = true;
        const fieldset = el('fieldset', 'km-guide-rate-reasons');
        fieldset.appendChild(el('legend', '', 'よくなかった理由を選んでください'));
        const name = 'km-guide-reason-' + (++rateSeq);
        BAD_REASONS.forEach(([value, label]) => {
            const option = el('label', 'km-guide-rate-reason');
            const radio = el('input');
            radio.type = 'radio';
            radio.name = name;
            radio.value = value;
            option.append(radio, document.createTextNode(label));
            fieldset.appendChild(option);
        });
        const note = el('textarea', 'km-guide-rate-note');
        note.maxLength = NOTE_MAX;
        note.rows = 2;
        note.placeholder = 'ひとこと(なくてもよい)';
        note.setAttribute('aria-label', 'よくなかった理由のひとこと');
        const submit = el('button', 'primary-btn km-guide-rate-send', '送る');
        submit.type = 'submit';
        form.append(fieldset, note, submit);
        wrap.appendChild(form);

        good.addEventListener('click', async () => {
            good.disabled = true;
            bad.disabled = true;
            try {
                await sendRating({ token: token, rating: 'good' });
                done('ありがとうございました。');
            } catch (error) {
                status.textContent = error.message;
                good.disabled = false;
                bad.disabled = false;
            }
        });
        bad.addEventListener('click', () => {
            bad.setAttribute('aria-pressed', 'true');
            form.hidden = false;
            const first = form.querySelector('input[type="radio"]');
            if (first) first.focus();
            scrollToEnd();
        });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const chosen = form.querySelector('input[type="radio"]:checked');
            if (!chosen) {
                status.textContent = '理由を 1 つ選んでください。';
                return;
            }
            submit.disabled = true;
            try {
                await sendRating({ token: token, rating: 'bad', reason: chosen.value, note: note.value.trim() });
                done('ありがとうございました。案内をよくするために使います。');
            } catch (error) {
                status.textContent = error.message;
                submit.disabled = false;
            }
        });
        return wrap;
    }

    function showRoute(route) {
        if (!route || !route.to || !route.to.nodeId || typeof window.setAsLocation !== 'function') return;
        if (typeof window.kmClearRouteEnds === 'function') window.kmClearRouteEnds();
        const opts = (end) => (end.poiId ? { poiId: end.poiId } : {});
        /*
         * 目的地を先に(出発地が無ければ、経路の欄が開いて出発地を選んでもらう)。両方そろえば経路を出す。
         * **テスト用アカウントの出発地(Let's play Densan。Main/app.js の applyTestStart)はここでは入れない**
         * —— 入れると、AI が決めた出発地より先に Densan からの経路を引いてしまう(2026-10-25、利用者「～から体育館への行き方などの時に、
         * 出発点を Let's play densan から外して、AI の経路を出すように」)。出発地を言わなかったときは、サーバーがもう Densan を
         * route.from に入れて返す(lib/guide-chat.php の km_guide_plan)。言ったのに見つからなかったときは、黙って Densan にせず欄で選んでもらう
         */
        window.setAsLocation(route.to.nodeId, 'end', Object.assign(opts(route.to), { noTestStart: true }));
        if (route.from && route.from.nodeId) window.setAsLocation(route.from.nodeId, 'start', opts(route.from));
    }

    async function ask(question) {
        if (busy || question === '') return;
        if (question.length > QUESTION_MAX) {
            log.appendChild(bubble('error', `質問は ${QUESTION_MAX} 字までにしてください。`));
            return;
        }
        busy = true;
        sendButton.disabled = true;
        input.value = '';
        log.appendChild(bubble('me', question));
        const waiting = bubble('bot km-guide-waiting', '考えています…');
        log.appendChild(waiting);
        scrollToEnd();
        try {
            const response = await fetch('/api/guide-chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-KM-CSRF': csrf },
                credentials: 'same-origin',
                body: JSON.stringify({ question: question }),
            });
            const data = await response.json().catch(() => null);
            waiting.remove();
            if (!response.ok || !data || data.success !== true) {
                log.appendChild(bubble('error', (data && data.message) || 'いま案内できません。しばらくしてから試してください。'));
                return;
            }
            const answer = bubble(data.fallback ? 'bot km-guide-fallback' : 'bot', data.answer || '');
            log.appendChild(answer);
            (Array.isArray(data.cards) ? data.cards : []).forEach((card) => log.appendChild(cardFor(card)));
            if (data.route) {
                const go = el('button', 'primary-btn km-guide-route', data.route.from ? '地図に経路を出す' : '目的地にして出発地を選ぶ');
                go.type = 'button';
                go.addEventListener('click', () => showRoute(data.route));
                log.appendChild(go);
            }
            // 残せた答えにだけ評価を出す(合言葉が無ければ出さない)
            if (typeof data.rateToken === 'string' && /^[0-9a-f]{32}$/.test(data.rateToken)) {
                log.appendChild(ratingFor(data.rateToken));
            }
        } catch (error) {
            waiting.remove();
            log.appendChild(bubble('error', '送れませんでした。電波を確かめて、もう一度試してください。'));
        } finally {
            busy = false;
            sendButton.disabled = false;
            scrollToEnd();
            input.focus();
        }
    }

    // チャットを引き出しの土台にする(閉じない。ほかの中身を閉じると戻ってくる)。入力欄にはまだ触れない(スマホでキーボードを出さない)
    function pin() {
        if (!window.KmSheet) return false;
        if (!box) build();
        window.KmSheet.setBase({ key: 'guide', title: '💬 案内', content: box });
        return true;
    }
    if (!pin()) window.addEventListener('load', pin, { once: true });

    // 「💬 案内」は、ほかの中身を開いているときにチャットへ戻すボタン
    openButton.addEventListener('click', () => {
        if (!pin()) return;
        if (window.KmSheet.currentKey() !== 'guide') window.KmSheet.close();
        window.setTimeout(() => { if (input) input.focus(); }, 300);
    });
}());
