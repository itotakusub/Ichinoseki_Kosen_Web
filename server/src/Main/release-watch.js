/*
 * 新しい版を配備したら「ページを更新してください」と出す(2026-11-02、利用者の指示)。
 *
 * 配備のあとも、開いたままのタブは古い JS・古い画面で動き続ける。ページに埋めた印(<meta name="km-release">。
 * lib/release.php)と、api/release.php が返すいまの印を比べ、違えば画面の上に帯を出す。
 *
 * - 聞くのは 5 分ごと(タブが表に出ているときだけ)と、タブ・窓が表に戻ったとき(前に聞いてから 1 分たっていれば)
 * - **自動では読み込み直さない**(書きかけの入力を消さない)。入力欄を触っていたら「入力中の内容は消えます」と添える
 * - 「あとで」で閉じた印は、このタブではもう出さない(さらに新しい版が来たらまた出す)
 * - 配備の途中の 502 などは黙って次に回す
 *
 * 公開ページと管理画面の両方で読む。どちらの CSS にも頼らない(CSP で style 属性と <style> が使えないので、
 * 見た目は CSSOM で付ける。CSSOM は CSP の対象外)。文字は textContent で入れる。
 */
(function () {
    'use strict';

    const meta = document.querySelector('meta[name="km-release"]');
    const shown = meta ? meta.getAttribute('content') : '';
    if (!shown) return;

    const CHECK_MS = 5 * 60 * 1000;
    const MIN_GAP_MS = 60 * 1000;
    const DISMISS_KEY = 'km-release-dismissed';
    const english = /^en\b/i.test(document.documentElement.lang || '');
    const TEXT = english
        ? { message: 'A new version has been deployed. Please reload the page.', dirty: 'Anything you are typing will be lost.', reload: 'Reload', later: 'Later' }
        : { message: '新しい版を配備しました。ページを更新してください。', dirty: '入力中の内容は消えます。', reload: '更新する', later: 'あとで' };

    let lastCheck = 0;
    let timer = null;
    let banner = null;
    let dirtyNote = null;
    let dirty = false;

    const dismissed = function () {
        try { return sessionStorage.getItem(DISMISS_KEY) || ''; } catch (e) { return ''; }
    };
    const rememberDismissed = function (release) {
        try { sessionStorage.setItem(DISMISS_KEY, release); } catch (e) { /* 覚えられなくても閉じるだけはする */ }
    };

    // 入力欄を触ったら「書きかけ」とみなす(検索欄も含める。消えて困るのは同じ)
    document.addEventListener('input', function (event) {
        const target = event.target;
        if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement
            || (target instanceof HTMLElement && target.isContentEditable)) {
            dirty = true;
            if (dirtyNote) dirtyNote.hidden = false;
        }
    }, true);

    const button = function (label, primary) {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        Object.assign(b.style, {
            font: 'inherit', fontSize: '0.875rem', lineHeight: '1.2', padding: '0.4rem 0.9rem', borderRadius: '999px', cursor: 'pointer',
            border: primary ? '1px solid #fff' : '1px solid rgba(255,255,255,0.5)',
            background: primary ? '#fff' : 'transparent', color: primary ? '#1d3a5f' : '#fff', fontWeight: primary ? '700' : '400',
        });
        return b;
    };

    const showBanner = function (release) {
        if (banner) return;
        banner = document.createElement('div');
        banner.setAttribute('role', 'status');
        banner.setAttribute('aria-live', 'polite');
        banner.dataset.kmRelease = release;
        Object.assign(banner.style, {
            position: 'fixed', top: '0.75rem', left: '50%', transform: 'translateX(-50%)', zIndex: '2147483000',
            display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '0.5rem 0.75rem', boxSizing: 'border-box',
            width: 'max-content', maxWidth: 'calc(100vw - 1.5rem)', padding: '0.65rem 0.9rem', borderRadius: '0.75rem',
            background: '#1d3a5f', color: '#fff', boxShadow: '0 6px 24px rgba(0,0,0,0.25)',
            fontFamily: 'system-ui, -apple-system, "Segoe UI", sans-serif', fontSize: '0.9rem', lineHeight: '1.4',
        });
        const text = document.createElement('div');
        Object.assign(text.style, { flex: '1 1 14rem', minWidth: '0' });
        const message = document.createElement('div');
        message.textContent = TEXT.message;
        dirtyNote = document.createElement('div');
        dirtyNote.textContent = TEXT.dirty;
        Object.assign(dirtyNote.style, { fontSize: '0.8125rem', color: '#ffd28a' });
        dirtyNote.hidden = !dirty;
        text.append(message, dirtyNote);
        const actions = document.createElement('div');
        Object.assign(actions.style, { display: 'flex', gap: '0.5rem', marginLeft: 'auto' });
        const reload = button(TEXT.reload, true);
        reload.addEventListener('click', function () { window.location.reload(); });
        const later = button(TEXT.later, false);
        later.addEventListener('click', function () {
            rememberDismissed(release);
            banner.remove();
            banner = null;
            dirtyNote = null;
        });
        actions.append(reload, later);
        banner.append(text, actions);
        document.body.appendChild(banner);
    };

    const check = function () {
        lastCheck = Date.now();
        fetch('/api/release.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                const current = data && typeof data.release === 'string' ? data.release : '';
                if (current && current !== shown && current !== dismissed()) {
                    showBanner(current);
                }
            })
            .catch(function () { /* 配備の途中など。次に聞いたときにわかる */ });
    };

    const schedule = function () {
        if (timer !== null) window.clearTimeout(timer);
        timer = null;
        if (document.hidden) return;
        timer = window.setTimeout(function () {
            timer = null;
            if (!document.hidden) check();
            schedule();
        }, CHECK_MS);
    };

    const back = function () {
        if (!document.hidden && Date.now() - lastCheck >= MIN_GAP_MS) check();
        schedule();
    };

    document.addEventListener('visibilitychange', back);
    window.addEventListener('focus', back);
    // 戻るボタンでメモリから戻った画面も、古い版かもしれない
    window.addEventListener('pageshow', function (event) { if (event.persisted) back(); });
    lastCheck = Date.now();
    schedule();
}());
