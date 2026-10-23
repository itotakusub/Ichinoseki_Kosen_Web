/*
 * 左から出る引き出し(2026-10-23、利用者の指示「Google マップのように、場所の詳細は左から」)。
 *
 * 場所の詳細・表示調整・履歴・予定・ポスターの拡大表示は、どれもこの 1 つの引き出しに出す。
 * ダウンロード・設定(右の .km-drawer。Main/panel.js)と同じ動き(滑り出す・閉じている間は inert・Esc で閉じる)。
 *
 * - **1 つだけ開く。** 別のものを開くと中身が入れ替わる。`push: true` で開いたものは「←」で前の中身へ戻れる
 *   (場所の詳細 → ポスターの拡大 → 戻る)
 * - **地図は覆わない**(幕を置かない)。右側の地図はそのまま動かせる(Google マップの場所の欄と同じ)
 * - スマホ(≤768px。styles.css と同じ値)は下から出るシート。上のつまみを押すと高さが広がる
 * - 中身は呼ぶ側が作った要素をそのまま入れる。閉じると外すだけで捨てない(呼ぶ側が持っていれば、また入れられる)
 *
 * 使い方:
 *   KmSheet.open({ key: 'place', title: '第二実習室', content: element, onClose: fn, push: false })
 *   KmSheet.close()        … 開いているものを閉じる
 *   KmSheet.close('place') … いま開いているのが place のときだけ閉じる
 *   KmSheet.isOpen('place')
 */
(function () {
    'use strict';

    let sheet = null;
    let titleEl = null;
    let bodyEl = null;
    let backBtn = null;
    let closeBtn = null;
    // いまの中身と、「←」で戻る先
    let current = null;
    const stack = [];
    let lastFocus = null;

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function ensure() {
        if (sheet) return;
        sheet = el('aside', 'km-sheet glass-panel');
        sheet.id = 'km-sheet';
        sheet.setAttribute('aria-hidden', 'true');
        sheet.setAttribute('aria-labelledby', 'km-sheet-title');
        sheet.inert = true;

        // スマホのつまみ(押すと広げる・戻す)
        const grip = el('button', 'km-sheet-grip');
        grip.type = 'button';
        grip.setAttribute('aria-label', '広げる');
        grip.addEventListener('click', function () {
            const expanded = sheet.classList.toggle('is-expanded');
            grip.setAttribute('aria-label', expanded ? '縮める' : '広げる');
        });

        const head = el('div', 'km-sheet-head');
        backBtn = el('button', 'km-sheet-back', '←');
        backBtn.type = 'button';
        backBtn.title = '戻る';
        backBtn.setAttribute('aria-label', '戻る');
        backBtn.hidden = true;
        backBtn.addEventListener('click', back);
        titleEl = el('h2', 'km-sheet-title');
        titleEl.id = 'km-sheet-title';
        closeBtn = el('button', 'km-sheet-close', '×');
        closeBtn.type = 'button';
        closeBtn.title = '閉じる';
        closeBtn.setAttribute('aria-label', '閉じる');
        closeBtn.addEventListener('click', function () { close(); });
        head.append(backBtn, titleEl, closeBtn);

        bodyEl = el('div', 'km-sheet-body');
        sheet.append(grip, head, bodyEl);
        (document.getElementById('ui-layer') || document.body).appendChild(sheet);

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || !current) return;
            // ほかの確かめの画面(ダウンロード・設定の引き出しなど)が開いているときは、そちらに任せる
            const drawer = document.getElementById('info-panel');
            if (drawer && drawer.classList.contains('is-open')) return;
            if (stack.length > 0) back(); else close();
        });
    }

    function show(entry) {
        bodyEl.innerHTML = '';
        bodyEl.appendChild(entry.content);
        bodyEl.scrollTop = 0;
        titleEl.textContent = entry.title || '';
        backBtn.hidden = stack.length === 0;
        sheet.dataset.kmSheet = entry.key;
    }

    function setOpen(open) {
        sheet.classList.toggle('is-open', open);
        sheet.setAttribute('aria-hidden', open ? 'false' : 'true');
        sheet.inert = !open;
        document.body.classList.toggle('km-sheet-open', open);
        if (!open) sheet.classList.remove('is-expanded');
        // 地図の寄せの余白が変わる(app.js の mapFitPadding が引き出しを数える)
        window.dispatchEvent(new CustomEvent('km:sheet', { detail: { open: open, key: open && current ? current.key : null } }));
    }

    function finish(entry) {
        if (entry && typeof entry.onClose === 'function') {
            try { entry.onClose(); } catch (e) { console.error(e); }
        }
    }

    function open(options) {
        ensure();
        const entry = {
            key: String(options.key || 'sheet'),
            title: options.title || '',
            content: options.content,
            onClose: options.onClose,
        };
        if (current && options.push) {
            stack.push(current);
        } else {
            // 入れ替え: 前の中身(戻る先も)は閉じたことにする
            finish(current);
            while (stack.length > 0) finish(stack.pop());
        }
        const wasOpen = sheet.classList.contains('is-open');
        current = entry;
        show(entry);
        if (!wasOpen) {
            lastFocus = document.activeElement;
            setOpen(true);
        } else {
            window.dispatchEvent(new CustomEvent('km:sheet', { detail: { open: true, key: entry.key } }));
        }
        // 読み上げと Tab の位置を引き出しへ(地図の上で押した直後でも、閉じるがすぐ押せる)
        if (options.focus !== false) closeBtn.focus({ preventScroll: true });
    }

    function back() {
        if (stack.length === 0) { close(); return; }
        finish(current);
        current = stack.pop();
        show(current);
        window.dispatchEvent(new CustomEvent('km:sheet', { detail: { open: true, key: current.key } }));
    }

    function close(key) {
        if (!sheet || !current) return;
        if (key !== undefined && current.key !== key && !stack.some(function (entry) { return entry.key === key; })) return;
        finish(current);
        while (stack.length > 0) finish(stack.pop());
        current = null;
        setOpen(false);
        bodyEl.innerHTML = '';
        if (lastFocus && typeof lastFocus.focus === 'function' && document.body.contains(lastFocus)) {
            lastFocus.focus({ preventScroll: true });
        }
        lastFocus = null;
    }

    /** 中身を差し替える(同じ key のまま。例: 場所の詳細を作り直したとき) */
    function replace(key, content, title) {
        if (!current || current.key !== key) return false;
        current.content = content;
        if (title !== undefined) current.title = title;
        show(current);
        return true;
    }

    window.KmSheet = {
        open: open,
        close: close,
        back: back,
        replace: replace,
        isOpen: function (key) { return !!current && (key === undefined || current.key === key); },
        currentKey: function () { return current ? current.key : null; },
        element: function () { ensure(); return sheet; },
    };
}());
