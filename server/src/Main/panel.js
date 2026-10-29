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
     * ログインの欄は右上のアカウントの欄(#km-account、<details>)。2026-10-29 にこの引き出しから移した
     * (スマホでは引き出しが開いている間 ⚙️ ごと隠れて、ログインに届かなかった)。
     * 開け閉ては <details> のまま。外を押したとき・Esc で閉じるのだけここで足す(展示の PC にも要るので、下の return より前)
     */
    const account = document.getElementById('km-account');
    if (account) {
        document.addEventListener('click', (event) => {
            if (account.open && !account.contains(event.target)) account.open = false;
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && account.open) {
                account.open = false;
                account.querySelector('summary')?.focus();
            }
        });
    }

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

        /*
         * 教職員氏名と教員室・研究室(2026-10-22、利用者の指示「権限チェックを厳格化。例外なし」)。
         * **パスワードでは開かない。** 本校の教職員(組織のメンバー)としてサインインした人にだけ出し、
         * 氏名とメモは先生が許可した地点だけ(lib/node-visibility.php)。入力欄は出さない。
         */
        if (meta.namesUnlocked) {
            showStatus('教職員氏名', '✅ 教職員としてサインインしています。教員室・研究室を表示し、先生が許可した地点では氏名とメモも表示します。');
            return;
        }

        showStatus('教職員氏名', '教員室・研究室と教職員氏名は、本校の教職員としてサインインしたときだけ表示します。');
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
