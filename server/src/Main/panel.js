// panel.js
//
// 「ダウンロード・設定」パネルの開閉と、教職員氏名の解除フォームだけを担当する。
// 地図の描画ロジック(app.js)とは関心が別なのでファイルを分けてある。

document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('info-panel-toggle');
    const panel = document.getElementById('info-panel');
    const closeBtn = document.getElementById('info-panel-close');
    const unlockSection = document.getElementById('km-unlock-section');

    /*
     * ---- ログインの2つを、画面幅で置き換える ----
     *
     * スマホでは、トップバーの #km-auth-links を「ダウンロード・設定」パネルの
     * #km-auth-slot へ移す(2026-09-05、利用者の指示)。
     *
     * **同じ要素を動かす。複製しない。** ログイン中と未ログインで中身が変わるので、
     * 2つ置くと出し分けの枝が2箇所になり、片方だけ直したときに必ず食い違う。
     *
     * 幅は styles.css の `@media (max-width: 768px)` と同じ値にすること ——
     * ずれると「バーからは消えたが、パネルにも出ていない」幅が生まれる。
     */
    const authLinks = document.getElementById('km-auth-links');
    const authSlot = document.getElementById('km-auth-slot');
    const authSection = document.getElementById('km-auth-section');
    const topActions = document.querySelector('.top-actions');
    const narrowScreen = window.matchMedia('(max-width: 768px)');

    function placeAuthLinks() {
        if (!authLinks || !authSlot || !topActions) return;

        if (narrowScreen.matches) {
            if (authLinks.parentNode !== authSlot) authSlot.appendChild(authLinks);
            if (authSection) authSection.hidden = false;
        } else {
            // トップバーでは ⚙️ より左。並び順まで元に戻す
            if (authLinks.parentNode !== topActions) topActions.insertBefore(authLinks, topActions.firstChild);
            if (authSection) authSection.hidden = true;
        }

        // バーの高さが変わるので、地図の収まりを取り直す(app.js が公開している)
        if (typeof window.kmRefitMap === 'function') window.kmRefitMap();
    }

    placeAuthLinks();
    narrowScreen.addEventListener('change', placeAuthLinks);

    if (!toggleBtn || !panel || !unlockSection) return;

    /*
     * ---- 横から出る引き出し ----(2026-09-25、利用者の指示)
     *
     * 以前は下から出るシートで、開くと地図の下半分と左下の操作・右下のズームが隠れた。
     * **右から滑り出させる**(動きは styles.css の `.km-drawer`)。
     *
     * 閉じている間は `inert` にする —— 画面の外へ滑らせただけでは、
     * タブで辿ると**見えない引き出しの中の的**にフォーカスが入ってしまう。
     * 後ろの幕(#km-drawer-scrim)を押しても、Esc でも閉じる。
     */
    const scrim = document.getElementById('km-drawer-scrim');
    let lastFocus = null;

    function setPanelOpen(open) {
        panel.classList.toggle('is-open', open);
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        panel.inert = !open;
        if (scrim) scrim.hidden = !open;
        toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            lastFocus = document.activeElement;
            if (closeBtn) closeBtn.focus();
        } else if (lastFocus && typeof lastFocus.focus === 'function') {
            // inert にした引き出しの中にフォーカスを残すと、body へ飛ばされて現在地を失う
            lastFocus.focus();
            lastFocus = null;
        }
    }
    // 「はじめにを読む」(welcome.js)が、引き出しを閉じてから開く
    window.kmCloseInfoPanel = () => setPanelOpen(false);

    toggleBtn.setAttribute('aria-controls', 'info-panel');
    toggleBtn.setAttribute('aria-expanded', 'false');
    toggleBtn.addEventListener('click', () => {
        const open = !panel.classList.contains('is-open');
        if (open) renderUnlockSection();
        setPanelOpen(open);
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', () => setPanelOpen(false));
    }
    if (scrim) {
        scrim.addEventListener('click', () => setPanelOpen(false));
    }
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && panel.classList.contains('is-open')) setPanelOpen(false);
    });

    function renderUnlockSection() {
        const meta = window.kmMapMeta || {};

        /*
         * **地図そのものに錠が掛かっているときを最優先で出す。**
         *
         * 以前は「教職員氏名」の状態しか見ておらず、地図側だけパスワードを掛けると
         * 入力欄が出ないまま地図が真っ白になった —— 利用者には開ける手段が無い。
         * こちらが掛かっている間は、氏名の話をしても意味が無いので出さない。
         */
        if (meta.mapLocked) {
            // 地図側の錠。**同じフォームが地図の上にも出ている**(app.js)。
            // どちらで入れても結果は同じなので、片方だけ直して食い違わないよう
            // 組み立ては Main/unlock.js の1箇所に置いてある。
            window.kmRenderUnlockForm(unlockSection, {
                heading: '地図の表示',
                description: '地図・経路・見取り図を見るにはパスワードが必要です。',
            });
            return;
        }

        if (meta.namesUnlocked) {
            showStatus('教職員氏名', '✅ 現在表示中です。');
            return;
        }

        if (meta.mode === 'hidden') {
            showStatus('教職員氏名', '現在、氏名の表示は無効化されています。');
            return;
        }

        if (meta.mode !== 'password') {
            // 地図データを取得できていない(サーバー未接続など)場合はここに来る。
            showStatus('教職員氏名', '現在の状態を確認できませんでした。');
            return;
        }

        window.kmRenderUnlockForm(unlockSection, {
            heading: '教職員氏名の表示',
        });
    }

    /**
     * 入力欄の要らない「いまこうなっています」を出す。
     *
     * **クラスで作る。** ここは以前 style="…" 属性で書かれていたが、1.0.1 で CSP から
     * 'unsafe-inline' を外したため属性は適用されず、**文字だけが裸で出ていた**。
     * 見た目は Main/styles.css の .km-unlock-* に置いてある(解除フォームと共通)。
     */
    function showStatus(heading, text) {
        unlockSection.innerHTML = '';

        const title = document.createElement('h4');
        title.className = 'km-unlock-heading';
        title.textContent = heading;

        const body = document.createElement('p');
        body.className = 'km-unlock-description';
        body.textContent = text;

        unlockSection.appendChild(title);
        unlockSection.appendChild(body);
    }
});
