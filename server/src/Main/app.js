// app.js

/**
 * 読み込み画面(index.php の #km-loading)の進捗表示。
 *
 * 「全部揃ってから地図を見せる」方式にしたので、**利用者はここしか見ていない時間が
 * ある**。何をどこまで読んだのかを必ず出す。要素が無いページ(admin/map-editor.php)
 * では、どの呼び出しも黙って何もしない。
 */
const kmLoading = (() => {
    const root = document.getElementById('km-loading');
    const bar = document.getElementById('km-loading-bar');
    const status = document.getElementById('km-loading-status');
    let done = false;

    /*
     * 段ごとに進み具合を持つ。**1本の変数で上書きし合う形にしない。**
     * 見取り図(通信)と地点の準備(CPU)は同時に進み、後者はすぐ終わるので、
     * 素直に「最後に更新した方」を出すと、実際は通信を待っているのに
     * 「地点を準備しています… 6/6」と出たまま止まって見える(実際にそうなった)。
     *
     * weight は進捗バーの取り分。かかる時間はほぼ通信なので見取り図に厚く配る。
     * 文言は「まだ終わっていない最初の段」を出す。
     */
    const stages = [
        { key: 'images', weight: 0.85, done: 0, total: 0, text: (d, t) => `見取り図を読み込んでいます… ${d}/${t}` },
        { key: 'markers', weight: 0.15, done: 0, total: 0, text: (d, t) => `地点を準備しています… ${d}/${t}` },
    ];

    function render() {
        if (!root || done) return;

        let ratio = 0;
        for (const stage of stages) {
            if (stage.total > 0) ratio += stage.weight * (stage.done / stage.total);
        }
        if (bar) bar.style.width = `${Math.round(Math.min(Math.max(ratio, 0), 1) * 100)}%`;

        if (status) {
            const pending = stages.find(stage => stage.total === 0 || stage.done < stage.total);
            status.textContent = pending && pending.total > 0
                ? pending.text(pending.done, pending.total)
                : '準備ができました';
        }
    }

    return {
        /** 既に閉じたか。起動処理の時間切れ判定が見る。 */
        get isDone() {
            return done || root === null;
        },
        /** @param {string} key 段の名前 @param {number} doneCount @param {number} total */
        progress(key, doneCount, total) {
            const stage = stages.find(s => s.key === key);
            if (!stage) return;
            stage.done = doneCount;
            stage.total = total;
            render();
        },
        /** 読み込み画面を閉じて地図を見せる。**何度呼んでも1回しか効かない。** */
        finish() {
            if (!root || done) return;
            // 途中で打ち切った場合でも、閉じる直前は満杯にしておく
            if (bar) bar.style.width = '100%';
            if (status) status.textContent = '準備ができました';
            done = true;
            root.classList.add('is-done');
            // 初回の操作案内(Main/tutorial.js)は、地図が出てから始める
            window.kmMapReady = true;
            document.dispatchEvent(new CustomEvent('km-map-ready'));
        },
    };
})();

document.addEventListener('DOMContentLoaded', async () => {
    // ---- 地図データの取得 ----
    // 旧実装は graph.js が同期的に定義するグローバル graphData をそのまま使っていた。
    // 今は MariaDB 化した内容を受け取る。形は graph.js と揃えてある
    // ({nodes: {id: node}}, {edges: [{source,target,distance}]})ので以降は変えていない。
    //
    // 取得元は2通り:
    //   1. index.php が HTML へ同梱した <script type="application/json" id="km-map-data">
    //      … 公開ページはこちら。**往復が発生しない**ので即座に読める
    //   2. /api/map-data.php を fetch
    //      … admin/map-editor.php(同梱しない)と、1が無いときのフォールバック
    const graphData = await readMapData();

    async function readMapData() {
        const inline = document.getElementById('km-map-data');
        if (inline) {
            try {
                return JSON.parse(inline.textContent);
            } catch (error) {
                // 壊れた同梱データで詰むより、取りに行き直した方がよい
                console.error('同梱された地図データを読めませんでした。APIへ切り替えます:', error);
            }
        }
        /*
         * **待ち続けない。** 応答が返らないまま接続だけ残ると、読み込み画面が閉じても
         * 地図が空のままになり、利用者は再読み込みを重ねる(= サーバーの負荷が増える)。
         * 時間切れは取得失敗と同じ扱いにして、下の「取得できませんでした」を出す。
         */
        return fetchWithTimeout('/api/map-data.php')
            .then((res) => res.json())
            .catch((error) => {
                console.error('地図データの取得に失敗しました:', error);
                return null;
            });
    }

    /**
     * タイムアウト付きの fetch(既定 15 秒)。時間切れは AbortError で reject する。
     *
     * **打ち切るのは応答ヘッダーが届くまで。** 本文(地図データ)は大きく、遅い回線で
     * 15 秒を超えても正常なことがあるので含めない。本文が止まった場合は nginx の
     * send_timeout / proxy_read_timeout(60 秒)で接続が切れ、res.json() が失敗する。
     *
     * **このファイルの中に置く。** 共通ファイルを増やすと index.php と
     * admin/map-editor.php の両方で読み込み順を合わせる必要が出るため。
     */
    /**
     * 地図が描けないとき(錠が掛かっている・データが無い)。**地図の操作を画面から下げる。**
     *
     * このあとの初期化(検索の開閉・階の切り替え・閉じるボタン)は走らないので、
     * 検索・階・表示調整のボタンは**押しても何も起きない**まま画面に残っていた。
     * スマホでは検索のシートが開いたまま解除欄の上に被さり、「パスワードを入れる前は
     * ボタンが効かない」状態になっていた(2026-09-25、利用者の指摘)。
     *
     * 残すのはトップバー(ログイン・ダウンロード・設定)と解除欄だけ。
     * 見た目は styles.css の `.km-map-unavailable`。
     */
    function markMapUnavailable() {
        document.body.classList.add('km-map-unavailable');
    }

    function fetchWithTimeout(url, options = {}, timeoutMs = 15000) {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), timeoutMs);
        return fetch(url, { ...options, signal: controller.signal })
            .finally(() => window.clearTimeout(timer));
    }

    // Main/panel.js(ダウンロード・パスワード解除パネル)が参照する。
    // namesUnlocked/mode は /api/map-data.php のレスポンスにそのまま入っている。
    window.kmMapMeta = {
        namesUnlocked: graphData && graphData.namesUnlocked === true,
        mode: graphData && graphData.mode,
        // 地図そのものに錠が掛かっているか。掛かっていればノードも階も空で届く。
        mapLocked: graphData && graphData.mapLocked === true,
        // どちらかの錠がパスワードを求めているか。入力欄を出すかの判断に使う。
        passwordRequested: graphData && graphData.passwordRequested === true,
    };

    /*
     * 地図そのものに錠が掛かっている場合。
     *
     * **空の地図を描かない。** サーバーは nodes と edges を空で返すが、空配列は
     * JavaScript では真なので、下の「取得できませんでした」の判定はすり抜ける。
     * そのまま進むと**何も無い地図が黙って表示される**ことになり、
     * 利用者には壊れているようにしか見えない。理由と、次にすることを出す。
     */
    if (graphData && graphData.mapLocked === true) {
        markMapUnavailable();
        const mapEl = document.getElementById('map');
        if (mapEl) {
            const notice = document.createElement('div');
            notice.className = 'km-map-error km-map-locked';

            /*
             * **入力欄をここに出す。**
             *
             * 以前は「右下の『ダウンロード・設定』から入力してください」と案内するだけ
             * だった。地図が真っ白な画面で、隠れているパネルを開かせるのは無理がある ——
             * 実際、開ける手段が無いという報告になった。**塞いだ本人が鍵を渡す。**
             *
             * フォームは Main/unlock.js が組み立てる(パネル側と同じもの)。
             * admin/map-editor.php は unlock.js を読まないので、有無を見てから呼ぶ
             * (そちらは guard.php で錠の内側に入るため、ここには来ない)。
             */
            if (graphData.passwordRequested && typeof window.kmRenderUnlockForm === 'function') {
                window.kmRenderUnlockForm(notice, {
                    heading: 'パスワードが必要です',
                    description: '地図・経路・見取り図を見るにはパスワードを入力してください。',
                    autoFocus: true,
                });
            } else {
                notice.textContent = graphData.passwordRequested
                    ? '地図を見るにはパスワードが必要です。右下の「ダウンロード・設定」からパスワードを入力してください。'
                    : '現在、地図は公開されていません。';
            }
            mapEl.prepend(notice);
        }
        kmLoading.finish();
        return;
    }

    // データベースが落ちている・移行前などで nodes/edges が無い場合は、
    // 意味の無いエラーで落ちる前にここで止める。
    if (!graphData || !graphData.nodes || !graphData.edges) {
        markMapUnavailable();
        console.error('地図データを取得できませんでした:', graphData && graphData.error);
        const mapEl = document.getElementById('map');
        if (mapEl) {
            // 見た目は styles.css のクラスで作る。style="…" 属性は CSP に弾かれて
            // **文字だけが裸で出る**(このメッセージ自体が読めなくなっていた)。
            const notice = document.createElement('div');
            notice.className = 'km-map-error';
            notice.textContent = '地図データを読み込めませんでした。時間をおいて再度お試しください。';
            mapEl.prepend(notice);
        }
        // 読み込み画面を残すと、失敗したことが利用者に伝わらないまま固まる
        kmLoading.finish();
        return;
    }

    // 地図編集ツール(admin/map-editor.php の editor.js)が読み書きする。
    // 閲覧だけの公開ページでも公開しておいて構わない(中身は /api/map-data.php が
    // 返したものと同じで、教職員氏名は解除していなければ既に null になっている)。
    window.graphData = graphData;

    /*
     * ---- ポスター(2026-10-21、lib/posters.php) ----
     *
     * 出し方(表示しない / イベント中のみ / 常に表示)はサーバーが決め、出さないときは空で届く。
     * 地点に置いたものは、その地点の詳細に出し、地図の名前に 🖼️ を付ける。
     * 一覧のページ(/posters.php)から来たとき(`?room=…&poster=<番号>`)は、そのポスターを詳細の先頭に出す。
     */
    const postersById = new Map();
    const postersByNode = new Map();
    (graphData.posters || []).forEach(function (poster) {
        postersById.set(Number(poster.id), poster);
        // 企画の番号で置いたポスターは、その番号の地点全部に出す(2026-10-23。nodeIds。無い古い応答は nodeId だけ)
        const nodeIds = Array.isArray(poster.nodeIds) && poster.nodeIds.length > 0 ? poster.nodeIds : (poster.nodeId ? [poster.nodeId] : []);
        nodeIds.forEach(function (nodeId) {
            if (!postersByNode.has(nodeId)) postersByNode.set(nodeId, []);
            postersByNode.get(nodeId).push(poster);
        });
        // 名前が番号の一時地点(臨時の地点)にも出す。鍵は 'poi:<番号>'(2026-10-23)
        (poster.poiIds || []).forEach(function (poiId) {
            const key = 'poi:' + poiId;
            if (!postersByNode.has(key)) postersByNode.set(key, []);
            postersByNode.get(key).push(poster);
        });
    });

    /*
     * ---- 企画のスケジュール(2026-10-23、lib/event-schedule.php) ----
     * 一般に出す印の行だけが届く。場所は企画の番号から引いた地点(nodeIds)。地点の詳細に「この場所の予定」を出す。
     * 一覧は Main/event-schedule.js が出す
     */
    const scheduleByNode = new Map();
    (graphData.schedule || []).forEach(function (entry) {
        (entry.nodeIds || []).forEach(function (nodeId) {
            if (!scheduleByNode.has(nodeId)) scheduleByNode.set(nodeId, []);
            scheduleByNode.get(nodeId).push(entry);
        });
        (entry.poiIds || []).forEach(function (poiId) {
            const key = 'poi:' + poiId;
            if (!scheduleByNode.has(key)) scheduleByNode.set(key, []);
            scheduleByNode.get(key).push(entry);
        });
    });
    const focusPosterMeta = document.querySelector('meta[name="km-focus-poster"]');
    const focusPosterId = focusPosterMeta ? Number(focusPosterMeta.getAttribute('content')) : 0;

    // ---- 初期設定 ----
    /*
     * 階を移るときの好み(2026-09-22)。**端末に覚える**(閲覧者ごとの好みなのでサーバーへは送らない)。
     * 値を変えるとグラフの重みが変わるので、`dijkstra` を作り直す。
     */
    const KM_VERTICAL_KEY = 'km-route-vertical-v1';

    function loadVerticalPreference() {
        try {
            const saved = window.localStorage.getItem(KM_VERTICAL_KEY);
            if (saved === 'elevator' || saved === 'stairs' || saved === 'any') return saved;
        } catch (e) {
            // 読めないだけ。指定なしで進む
        }
        return 'any';
    }

    let verticalPreference = loadVerticalPreference();

    /*
     * 経路の条件(2026-09-25)。C 階の移動を減らす / D 雨の日。**端末に覚える**(閲覧者ごとの好み)。
     * 重みそのもの(KM_ROUTE_WEIGHTS)は触らせない —— 決めるのは管理側で、将来は
     * 配信データの routeWeights で一般の既定を配る予定(graphData.routeWeights があれば使う)。
     */
    const KM_ROUTE_OPTIONS_KEY = 'km-route-options-v1';

    function loadRouteOptions() {
        try {
            const saved = JSON.parse(window.localStorage.getItem(KM_ROUTE_OPTIONS_KEY) || '{}');
            return { fewerFloors: saved.fewerFloors === true, rain: saved.rain === true };
        } catch (e) {
            return { fewerFloors: false, rain: false };
        }
    }

    let routeOptions = loadRouteOptions();

    function dijkstraOptions() {
        return {
            vertical: verticalPreference,
            fewerFloors: routeOptions.fewerFloors,
            rain: routeOptions.rain,
            weights: graphData.routeWeights,
        };
    }

    let dijkstra = new Dijkstra(graphData.nodes, graphData.edges, dijkstraOptions());

    function rebuildDijkstra() {
        dijkstra = new Dijkstra(graphData.nodes, graphData.edges, dijkstraOptions());
    }
    // 地図編集ツールが地点や線を変えたあとにも作り直せるようにしておく
    window.kmRebuildDijkstra = rebuildDijkstra;

    // 階の初期値。index.php 側でも同名のグローバルを立てていたが、こちらが後から
    // 上書きするため二重管理になっていた。**編集ツールは新規ノードの階として
    // この値をそのまま保存する**ので、ずれていると違う階に作られてしまう。
    // 正本をここ1箇所に統一し、index.php 側の宣言は外してある。
    window.currentFloor = "1"; // 文字列として扱う
    let currentRoutePath = null;
    /*
     * 出発地・目的地は**地点の id で持つ**(2026-10-23、利用者の報告「出発地・目的地にできない。ボタンが反応しない」)。
     * 以前は入力欄の文字(札)から引き直していたので、同じ札の地点が 2 つあると先の方になり、
     * 一時地点(つなぎ先が道や階段)は札にできず黙って断られていた。手で打ち直したら id は捨てて、文字から引く
     */
    let routeStartId = null;
    let routeEndId = null;
    // 一時地点を選んだときはその番号(共有のリンクに入れる。つなぎ先の道の id では、開いた人に名前が出ない)
    let routeStartPoi = null;
    let routeEndPoi = null;
    // 経路を引き直したところ。この 1 回だけ経路に合わせて寄せる(階を移るたびに拡大率を戻さない。2026-10-23)
    let routeFitPending = false;
    /*
     * 階のレールを畳むための状態(使うのは下の「階のレールを自動で畳む」節)。
     *
     * **宣言だけ先に置く。** 畳む処理は地図の `dragstart` からも呼ばれ、
     * そちらの登録はこの節より前にある —— 途中に await が1つ入るだけで
     * 「初期化前の変数に触れた」で止まる(検索パネルで一度そうなった)。
     */
    const KM_FLOOR_IDLE_MS = 5000;
    let floorIdleTimer = null;
    let floorPointerInside = false;
    let currentMarkers = [];
    let adminEdges = []; // 管理者用の全経路表示用

    // UI要素
    const startInput = document.getElementById('start-input');
    const endInput = document.getElementById('end-input');
    const searchBtn = document.getElementById('search-btn');
    const resetBtn = document.getElementById('reset-btn');
    const mobileSearchToggle = document.getElementById('mobile-search-toggle');
    const searchPanel = document.getElementById('search-panel');

    /*
     * ルート案内の帯(index.php の #km-route-bar)。2026-09-25 に下から出るシート
     * (guidance-panel)から置き換えた。**出すのは次に目指す 1 か所だけ。**
     * 手順の組み立ては buildRouteSteps、帯の書き換えは renderRouteBar(下の方)。
     */
    const routeBar = document.getElementById('km-route-bar');
    const routeText = document.getElementById('km-route-text');
    const routeSub = document.getElementById('km-route-sub');
    const routeNextBtn = document.getElementById('km-route-next');
    const routeCloseBtn = document.getElementById('km-route-close');
    const routeTargetBtn = document.getElementById('km-route-target');
    let routeSteps = [];
    let routeStepIndex = 0;
    // 「位置の更新」で地点を選んでもらっている最中か(下の「位置の更新」の節)
    let pickingPosition = false;

    /**
     * 検索パネルの開閉。**スマホ専用ではなくなった**(以前は .hidden-mobile という名前で、
     * 実際にモバイル幅でしか効かなかった)。デスクトップでも幅360pxのパネルが地図に
     * 重なって見える範囲を狭めていたため、どの画面幅でも畳めるようにしてある。
     *
     * ボタンは左下の操作バーのピル(`.km-action-btn`)。
     * **`textContent` で丸ごと書き換えない** —— 中に絵文字と文字の2つの要素があり、
     * 丸ごと入れ替えると次に開いたとき差し替える先が消えている。
     */
    /*
     * ページごと送られたままにしない(2026-10-24、利用者の指摘)。スマホでキーボードを出すと、ブラウザが入力欄を見せようとして
     * ページ全体を上へ送ることがあり、閉じたあとも上の検索の窓が画面の外に残った。このページは送らない作り
     * (html・body は overflow: hidden)なので、入力欄から離れたときとキーボードが下りたときに元へ戻す
     */
    function resetPageScroll() {
        const active = document.activeElement;
        if (active && (active.tagName === 'INPUT' || active.tagName === 'SELECT' || active.tagName === 'TEXTAREA')) return;
        const root = document.scrollingElement || document.documentElement;
        if (root.scrollTop !== 0 || root.scrollLeft !== 0) window.scrollTo(0, 0);
        if (document.body.scrollTop !== 0) document.body.scrollTop = 0;
    }
    document.addEventListener('focusout', () => window.setTimeout(resetPageScroll, 150));
    if (window.visualViewport) window.visualViewport.addEventListener('resize', () => window.setTimeout(resetPageScroll, 150));

    function setSearchPanelCollapsed(collapsed, refit = true) {
        if (!searchPanel) return;
        searchPanel.classList.toggle('collapsed', collapsed);
        // 経路の欄と左の引き出し(場所の詳細など)は同じ左側に出る。経路の欄を開いたら引き出しは閉じる(2026-10-23)
        if (!collapsed && window.KmSheet) window.KmSheet.close();
        document.body.classList.toggle('km-route-panel-open', !collapsed);
        if (mobileSearchToggle) {
            const icon = mobileSearchToggle.querySelector('.km-action-icon');
            const label = mobileSearchToggle.querySelector('.km-action-label');
            // 場所の検索は上の窓に移ったので、このボタンは「経路」(2026-10-23)
            if (icon) icon.textContent = collapsed ? '🧭' : '✕';
            if (label) label.textContent = collapsed ? '経路' : '閉じる';
            mobileSearchToggle.classList.toggle('is-active', !collapsed);
            mobileSearchToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
        /*
         * 畳んだぶん地図を広く使えるよう、収まりを取り直す。
         *
         * **起動時の1回だけは取り直さない**(`refit = false`)。
         * `refitCurrentFloor()` はこの下で `let` 宣言している `currentFloorBounds` を
         * 読むので、まだ宣言に達していない時点で呼ぶと
         * 「Cannot access 'currentFloorBounds' before initialization」で止まり、
         * **地図が読み込み画面のまま出てこない**(実際にそうなった)。
         * どのみち階を出すときに収まりを取り直すので、ここで呼ぶ必要が無い。
         */
        if (!refit) return;

        /*
         * **畳み終えてから測る。**
         *
         * 収まりの計算(`mapFitPadding`)はパネルの実寸を読む。押した直後は
         * まだ変形の途中(0.3秒)で、パネルは画面の外に居る位置を返す ——
         * その値で余白を決めると **0 として扱われ、地図がシートの下へ潜ったまま**になる。
         * 開いた側でだけ起きるので、閉じる操作では気づけなかった。
         *
         * 保険のタイマーも置く。動きを減らす設定などで変形が起きないと
         * `transitionend` は飛んでこない。
         */
        let refitted = false;
        const finish = () => {
            if (refitted) return;
            refitted = true;
            refitCurrentFloor();
        };
        searchPanel.addEventListener('transitionend', finish, { once: true });
        window.setTimeout(finish, 400);
    }

    if (mobileSearchToggle && searchPanel) {
        mobileSearchToggle.addEventListener('click', () => {
            setSearchPanelCollapsed(!searchPanel.classList.contains('collapsed'));
        });

        /*
         * **スマホでは畳んだ状態から始める**(2026-09-05)。
         * 下から出るシートが開いたまま読み込むと、画面の下半分が入力欄で埋まり、
         * 地図が見える前に「まず閉じる」から始めることになる。
         * アプリも同じで、検索は押して開く(`MapScreen.kt` の `showSearchDialog`)。
         *
         * PC は左に立つカードで地図と場所を取り合わないので、開いたまま。
         *
         * **どちらの場合もこの関数を通す。** 開いたままにする側で呼ばないと、
         * ピルが「検索」と書かれたまま(=閉じているときの顔)で開いた状態になり、
         * 押すとどうなるのかが読めない。
         */
        /*
         * 2026-10-23 から PC でも畳んで始める(Google マップ風。まず上の窓で場所を探し、「経路」で開く)
         */
        setSearchPanelCollapsed(true, false);
    }

    /*
     * パネル自身の「閉じる」(2026-09-05、利用者の指示)。
     *
     * 開け閉ての的は左下のピルにもあるが、**PC では開いたパネルが左上、ピルが左下**で、
     * 画面の端から端まで離れている。閉じ方を探して目を動かすことになっていた。
     *
     * **状態は増やさない** —— 同じ `setSearchPanelCollapsed()` を通すので、
     * ピルの顔(🔍/✕)もこちらで閉じたときに一緒に戻る。
     */
    const searchCloseBtn = document.getElementById('search-close');
    if (searchCloseBtn && searchPanel) {
        searchCloseBtn.addEventListener('click', () => setSearchPanelCollapsed(true));
    }

    const datalist = document.getElementById('node-options');
    const floorBtns = document.querySelectorAll('.floor-btn');

    // 管理者用グローバル変数 (builder.jsと共有)
    window.builderMode = "none";
    window.selectedNodesForEdge = [];

    // セレクトボックスの初期化（全ノードをプルダウンに追加）
    if (!window.isAdmin) {
        populateSelectBoxes();
    }

    // ---- Leaflet マップ初期化 ----
    window.map = L.map('map', {
        crs: L.CRS.Simple,
        minZoom: -1,
        maxZoom: 3,
        zoomControl: false,
        /*
         * 倍率を 0.25 刻みにする。既定の 1 刻みだと、収まる一番大きい倍率が
         * **実際に使える広さの半分**になることがある(2倍飛びなので)。
         * 検索パネルを畳んでも地図が大きくならない、という形で現れていた。
         * ボタンでの拡大縮小は従来どおり 1 ずつ動かす(zoomDelta は既定の 1)。
         */
        zoomSnap: 0.25,
    });
    /*
     * ズームボタンは**右下**(2026-09-05)。左下は操作ピル(検索・表示調整)が使う。
     *
     * 以前は 'bottomleft' に置き、スマホ幅のときだけ Main/zoom.js が
     * トップバーへ引っ越しさせていた。バーを一段に細くしたことで置き場所が無くなり、
     * そもそも画面の一番上は**片手で持ったときに親指が最も届かない**。
     * zoom.js は Old/Main/ へ退避した。
     */
    L.control.zoom({ position: 'bottomright' }).addTo(window.map);

    /*
     * 経路の重ね(2026-10-23、利用者の指摘「出発の表示がノードよりも後ろ。最前面に」)。
     * Leaflet の既定では地点の点(markerPane 600)より札(tooltipPane 650)が前で、出発・到着の印は点と同じ層にいた。
     *   kmRouteLine 610 … 経路の線と途中の点。地点の点より前、札より後ろ
     *   kmRoutePins 680 … 出発・到着の印。札より前、吹き出し(popupPane 700)より後ろ
     * z-index は CSSOM で入れる(style 属性ではないので CSP に掛からない。Leaflet 自身も同じことをしている)
     */
    window.map.createPane('kmRouteLine').style.zIndex = '610';
    window.map.createPane('kmRoutePins').style.zIndex = '680';

    /*
     * 地図の初期化が終わったことを知らせる。**送出は残す** ——
     * 地図を触る側から見れば「使える状態になった」合図(Main/top-bar.js が全画面のボタンを置く。2026-10-30)。
     */
    window.dispatchEvent(new CustomEvent('km:map-ready'));

    // 先頭の "/" があることが重要。ブラウザは <img src> をページの URL 基準で解決するため、
    // "Main/Picture/…" のような文書相対だと /admin/map-editor.php から読んだときに
    // /admin/Main/Picture/… になって 404 する。ルート相対にしておけば公開ページ("/")と
    // 管理画面(/admin/…)のどちらから読んでも同じ場所を指す。
    const fallbackFloorImages = {
        "outside": "/Main/Picture/外全体図.png",
        "1": "/Main/Picture/１階.png",
        "2": "/Main/Picture/２階.png",
        "3": "/Main/Picture/３階.png",
        "4": "/Main/Picture/４階.png",
        "5": "/Main/Picture/５階.png"
    };

    /*
     * 見取り図のURLは、サーバーが返す floors(km_map_floors 由来)を正本にする。
     * lib/map-data.php が ?v=<更新時刻> を付けた形で返すので、**nginx に 30日の
     * キャッシュ指示を入れても差し替えが反映される**。
     * floors が空/壊れている場合だけ、上の埋め込みへ落ちる(地図が出ない方が困る)。
     */
    const floorImages = (() => {
        const fromServer = {};
        for (const floor of (graphData.floors || [])) {
            if (floor && floor.id != null && typeof floor.svgPath === 'string' && floor.svgPath !== '') {
                fromServer[String(floor.id)] = floor.svgPath;
            }
        }
        return Object.keys(fromServer).length > 0 ? fromServer : fallbackFloorImages;
    })();

    /*
     * 場所ごとに色分けした床(2026-10-30、振り返りのフェーズ 1。管理画面「地図の色分け」が塗って置く。lib/area-paint.php)。
     * 塗っていない階・塗ったあと床を差し替えた階は無い(塗っていない床を出す)。表示調整の「場所ごとの色分け」で切れる
     * (tuning より先に読むので、ここでは保存した値を直に見る。切り替えは applyTuning が reloadFloorImages で反映する)
     */
    const floorAreaImages = {};
    (graphData.floors || []).forEach((floor) => {
        if (floor && floor.id != null && typeof floor.areaPath === 'string' && floor.areaPath !== '') floorAreaImages[String(floor.id)] = floor.areaPath;
    });
    let areaColorsOn = window.KM_MAP_STYLE ? window.KM_MAP_STYLE.load().areaColors !== false : true;
    /** その階で出す床の画像(色分けがあって ON ならそちら) */
    function floorImageUrl(floor) {
        return (areaColorsOn && floorAreaImages[floor]) || floorImages[floor];
    }

    /*
     * ---- ノード座標系 ----
     *
     * **ノードの座標は画像の解像度に従属させない。**
     *
     * ノードは admin/assets/js/map-editor.js が `Math.round(event.latlng.lng)` で作る。
     * L.CRS.Simple では latlng が imageOverlay の bounds の座標そのものなので、
     * bounds を画像の固有サイズから作っていると「画像を差し替えた瞬間に全ノードがずれる」。
     * 実際、見取り図を 1122x1122 の SVG から 801x801 の PNG へ替えたときに約1.4倍ずれる。
     *
     * そこで座標系は km_map_floors(coord_width / coord_height)を正本にする。
     * 値が無い階だけ、従来どおり画像の固有サイズへ落ちる(移行前と同じ挙動)。
     */
    const floorCoordSpace = (() => {
        const map = {};
        for (const floor of (graphData.floors || [])) {
            if (!floor || floor.id == null) continue;
            const w = Number(floor.coordWidth);
            const h = Number(floor.coordHeight);
            if (Number.isFinite(w) && w > 0 && Number.isFinite(h) && h > 0) {
                map[String(floor.id)] = { w, h };
            }
        }
        return map;
    })();

    /** その階の bounds。座標系が分かっていればそれを使い、無ければ画像の実寸に従う。 */
    function boundsForFloor(floor, naturalWidth, naturalHeight) {
        const space = floorCoordSpace[String(floor)];
        if (space) return [[0, 0], [space.h, space.w]];

        const w = (naturalWidth && naturalWidth > 0) ? naturalWidth : 1500;
        const h = (naturalHeight && naturalHeight > 0) ? naturalHeight : 1000;
        return [[0, 0], [h, w]];
    }

    /*
     * ---- 画像・ノードの事前読み込み ----
     *
     * 従来は changeFloor() のたびに new Image() でネットワークから読みに行っていた。
     * ブラウザキャッシュが効けば2回目以降は速いが、キャッシュが効かない環境
     * (キャッシュ無効化・初回切替など)では毎回ロード待ちが発生し、切替直後に
     * 建物が一瞬崩れて見える原因になっていた。ここで先読みしてサイズ込みでキャッシュしておき、
     * 切替時はキャッシュを最優先で使う。**読むのはいまの階と隣の階だけ**(2026-10-30。下の neighborFloors)。
     */
    const preloadedImages = {}; // floor -> { src, bounds }
    const imagePreloadPromises = {};

    function preloadFloorImage(floor) {
        if (imagePreloadPromises[floor]) return imagePreloadPromises[floor];
        const url = floorImageUrl(floor);
        if (!url) return Promise.resolve(null);

        imagePreloadPromises[floor] = new Promise((resolve) => {
            const img = new Image();
            img.onload = function () {
                const entry = { src: img.src, bounds: boundsForFloor(floor, this.width, this.height) };
                preloadedImages[floor] = entry;
                resolve(entry);
            };
            img.onerror = function () {
                console.error("Failed to preload image: " + img.src);
                delete imagePreloadPromises[floor]; // 失敗時は次回また試せるようにする
                resolve(null);
            };
            img.src = encodeURI(url);
        });
        return imagePreloadPromises[floor];
    }

    /*
     * 全階の見取り図を読み込む。**読み込み画面はこれが終わるまで閉じない。**
     *
     * 同時に投げる数を絞る(PRELOAD_CONCURRENCY)。共有ホスティングや PHP 組み込み
     * サーバーは同時接続数が少なく、一度に6枚投げるとコネクションが握りつぶされる
     * ことがある(開発サーバーで実測)。かといって1枚ずつでは遅いので、間を取る。
     * 本番は nginx の HTTP/2 で多重化されるため、この程度で詰まらない。
     *
     * 表示中の階を最優先で読む。読み込み画面を抜けた直後に見えるのはその階なので、
     * 万一タイムアウトで打ち切られても地図が出せる状態にしておく。
     */
    const PRELOAD_CONCURRENCY = 3;

    /**
     * 読み込む順を決める。**Object.keys() の順に頼らない。**
     *
     * JS のオブジェクトは整数風のキー("1"〜"5")を昇順で先に、文字列キー("outside")を
     * 後ろに並べる。今はその偶然で外全体図(74KB・全画像の約半分)が最後に来ているが、
     * 階の id を足したり変えたりすると黙って順序が変わる。ここで明示しておく。
     *
     * 現在の階 → 数字の階(昇順) → その他、の順。重い外全体図は後回しでよい
     * (最初に映すのは数字の階で、そちらが先に揃うほど早く地図を見せられる)。
     */
    function preloadOrder(firstFloor) {
        const first = String(firstFloor);
        const rest = Object.keys(floorImages).filter(floor => floor !== first);
        rest.sort((a, b) => {
            const na = Number(a), nb = Number(b);
            const aNum = Number.isFinite(na), bNum = Number.isFinite(nb);
            if (aNum && bNum) return na - nb;
            if (aNum) return -1;   // 数字の階を先に
            if (bNum) return 1;
            return a.localeCompare(b);
        });
        return [first, ...rest].filter(floor => floorImages[floor]);
    }

    /**
     * 隣の階(上下)。並びは 外 → 1F → 2F → …(外の隣は 1F)。数字でない階(足した建物など)はその後ろ。
     * **先読みはいまの階と隣だけ**(2026-10-30、振り返りのフェーズ 2「軽くする」)。
     * それまでは全階を読んでいた。遅い端末では 1600×1200 の絵を 6 枚ほどいて持つだけで重い
     */
    function neighborFloors(floor) {
        const ordered = preloadOrder(floor).slice();
        ordered.sort((a, b) => floorRank(a) - floorRank(b) || a.localeCompare(b));
        const at = ordered.indexOf(String(floor));
        if (at < 0) return [];
        return [ordered[at - 1], ordered[at + 1]].filter(Boolean);
    }
    function floorRank(floor) {
        if (floor === 'outside') return 0;
        const n = Number(floor);
        return Number.isFinite(n) ? n : 1000;
    }
    /** 切り替えた先の階の隣を、裏で読んでおく */
    function preloadNeighborFloors(floor) {
        neighborFloors(floor).forEach((next) => { preloadFloorImage(next); });
    }

    /**
     * いまの階と隣の階の見取り図を読む(ほかの階は切り替えたときに読む)。
     *
     * @param {Function} onFirstReady 現在の階が読めた時点で呼ぶ。**ここで地図を見せる。**
     *   全階を待ってから見せていた頃は、1Mbps で「1階は6.0秒で揃っているのに
     *   読み込み画面が閉じるのは19.3秒」という状態だった(実測)。
     * @returns {Promise<void>} 読み終えたら解決する(裏で進む)
     */
    async function preloadAllFloorImages(firstFloor, onProgress, onFirstReady) {
        const queue = [String(firstFloor)].concat(neighborFloors(firstFloor)).filter(floor => floorImages[floor]);
        const total = queue.length;
        let loaded = 0;
        let next = 0;

        // 表示中の階だけは先に読み切る(残りと競争させない)
        if (queue.length > 0) {
            next = 1;
            await preloadFloorImage(queue[0]);
            loaded += 1;
            if (onProgress) onProgress(loaded, total);
            if (onFirstReady) onFirstReady();
        }

        const worker = async () => {
            while (next < queue.length) {
                const floor = queue[next++];
                await preloadFloorImage(floor);
                loaded += 1;
                if (onProgress) onProgress(loaded, total);
            }
        };

        await Promise.all(
            Array.from({ length: Math.min(PRELOAD_CONCURRENCY, queue.length) }, worker)
        );
    }

    /*
     * 全ノード・全エッジは/api/map-data.phpから一括取得済み(=事前読み込み済み)だが、
     * renderGlobalLabels/renderGlobalEdges は毎回 graphData 全体を走査してから
     * 現在階のものだけに絞っていた。起動時に一度だけ階ごとへ仕分けておけば、
     * フロア切替のたびに無関係な階のノード・エッジまで見に行かずに済む。
     */
    const nodesByFloor = new Map();
    const edgesByFloor = new Map();

    /*
     * 管理画面(admin/map-editor.php)は window.graphData.nodes/edges を直接書き換えてから
     * renderGlobalLabels()/renderGlobalEdges() を呼ぶ(ノード追加・移動・削除、経路の追加・削除)。
     * そのため事前分類したマップをキャッシュしたままだと編集が反映されなくなる。
     * 管理者モードのときだけ、描画のたびに graphData から作り直す
     * (編集操作の頻度は低く、公開ページの主な負荷源であるズーム連打とは別なので割ける)。
     */
    function buildFloorIndex() {
        nodesByFloor.clear();
        for (const [id, node] of Object.entries(graphData.nodes)) {
            /*
             * イベント中に出さない地点(2026-10-14。lib/map-events.php の km_map_event_hidden)。
             * **印も名前も描かない。** 経路の計算(graphData.nodes をそのまま使う)からは外さない ——
             * 通さないのは通行止めで、出さないのとは別。編集画面では掴めるように出す。
             */
            if (node.hidden && !window.isAdmin) continue;
            const floor = String(node.floor);
            if (!nodesByFloor.has(floor)) nodesByFloor.set(floor, []);
            nodesByFloor.get(floor).push([id, node]);
        }

        edgesByFloor.clear();
        graphData.edges.forEach(edge => {
            const n1 = graphData.nodes[edge.source];
            const n2 = graphData.nodes[edge.target];
            if (!n1 || !n2) return;
            if (String(n1.floor) !== String(n2.floor)) return; // 階をまたぐ経路の一部は描画対象外
            const floor = String(n1.floor);
            if (!edgesByFloor.has(floor)) edgesByFloor.set(floor, []);
            // closed / closureReason はイベントモードの重ね合わせ(lib/map-events.php)
            // wall はサーバーが決める(両端が壁の線だけ)。**ここで種類を見に行かない** ——
            // 経路探索と描画で判定が分かれると、案内と見た目が食い違う
            edgesByFloor.get(floor).push({
                n1, n2,
                closed: !!edge.closed,
                closureReason: edge.closureReason || '',
                wall: !!edge.wall,
            });
        });
    }
    buildFloorIndex();

    /*
     * ---- ノード(マーカー)の事前生成 ----
     *
     * ここが**このページで一番重かった処理**。renderGlobalLabels() は呼ばれるたびに
     * 現在階のマーカーを全部 removeLayer() してから作り直していた。しかも
     * zoomend にぶら下がっているので、**拡大縮小するたび**に 100 個前後の
     * circleMarker と、それにぶら下がる常時表示ツールチップの DOM が丸ごと
     * 捨てられ、作り直されていた(実測: 1階・zoom2 で 1 回あたり 35〜85ms)。
     *
     * ノード自体は /api/map-data.php で全階ぶん取得済みなのだから、マーカーも
     * 最初に 1 度だけ作ってしまえばよい。外・1〜5階の全ノードぶんを起動時に
     * 用意しておき、階の切り替えとズームでは**作り直さず、表示の出し入れだけ**を行う。
     *
     * 出し入れの単位は LayerGroup。表示条件ごとに 4 つに分けてあるので、
     * 「引いたら建物名だけ」「寄ったら部屋名」の切り替えがグループの
     * 付け外し 1 回で済む(以前は 1 個ずつ判定して作り直していた)。
     */
    /*
     * 表示調整。**閲覧者ごとの好み**なので localStorage に置く(サーバーには送らない)。
     * 既定値は「設定を触っていない人の見た目が変わらない」ように選んである
     * (`Main/map-style.js` を参照)。
     */
    const mapStyle = window.KM_MAP_STYLE;
    const tuning = mapStyle.load();
    /*
     * 文字の大きさだけは**描き直しでは変わらない。**
     * ラベルは Leaflet の tooltip で、大きさを持っているのは CSS の側なので、
     * マーカーを作り直しても同じ字で出てくる(つまみが効いていなかった原因)。
     * ここで CSS 変数へ渡す。**編集画面(調整パネルを出さない)でも通る場所に置く。**
     */
    mapStyle.applyLabelScale(tuning);

    /**
     * 表示調整で何か変わったときに、地図を引き直す。
     *
     * **作り置きを捨ててから作り直す。** 大きさや形はマーカーの中身なので、
     * 出し入れだけでは変わらない(`buildFloorMarkers` は作成済みを返す)。
     */
    function applyTuning() {
        mapStyle.applyLabelScale(tuning);
        // 場所ごとの色分けを切り替えたら、床の画像を読み直す(先読みした絵を捨てて、いまの階から)
        if ((tuning.areaColors !== false) !== areaColorsOn) {
            areaColorsOn = tuning.areaColors !== false;
            Object.keys(preloadedImages).forEach((floor) => { delete preloadedImages[floor]; });
            Object.keys(imagePreloadPromises).forEach((floor) => { delete imagePreloadPromises[floor]; });
            loadFloorImageDynamic(String(window.currentFloor));
        }
        clearFloorMarkers();
        renderGlobalLabels();
        renderGlobalEdges();
        renderEventOverlay();
        renderBuildingSwitcher();
        renderBuildingOverlays();
        // 文字の大きさが変わるとラベルの箱も変わる。重なりを取り直す
        if (window.kmDeclutterLabels) window.kmDeclutterLabels();
    }

    // 編集画面には出さない(そちらは全部出したままにしてある)
    if (!window.isAdmin && window.KM_MAP_TUNING) {
        window.KM_MAP_TUNING.install(tuning, applyTuning);
    }

    // floor -> { 種類 -> LayerGroup }
    const floorMarkerLayers = new Map();
    // いま地図に載せている LayerGroup。差分を取って、変わったぶんだけ付け外しする。
    let activeMarkerGroups = [];
    // ノードID -> マーカー。検索結果からポップアップを開くのに使う。
    const markersById = new Map();
    // 臨時の地点の番号 -> マーカー。「予定」の一覧から開くため(2026-10-23。公開の地図は部屋のマーカーと一緒に作る)
    const poiMarkers = new Map();

    const KM_POI_ICONS = {
        food: '🍢', exhibit: '🎨', reception: '📋',
        firstaid: '🚑', toilet: '🚻', stage: '🎤', other: '📍',
    };

    /** 臨時の地点の種類の言葉(Website の値。lib/map-events.php の KM_MAP_EVENT_POI_CATEGORIES) */
    const KM_POI_LABELS = {
        food: '模擬店', exhibit: '展示', reception: '受付',
        firstaid: '救護', toilet: 'トイレ', stage: 'ステージ', other: '臨時の地点',
    };

    /**
     * ノードの図形。**種類ごとに色と形を変える**(アプリと同じ)。
     *
     * `circleMarker` では形を描き分けられないので `divIcon` に SVG を入れる。
     * 685 ノードを一度に置くと重いが、**地図に載せるのは今の階だけ**なので
     * 実際に DOM へ出るのは 120〜250 個。
     */
    /**
     * 図形の半径と、それを収める箱の大きさ。**1箇所で決める。**
     * マーカーの当たり判定(`iconSize`)と中身の SVG が別々に計算すると、
     * 大きさを変えたときに図形と箱がずれ、**掴める場所と見えている場所が食い違う。**
     */
    function nodeBox(type) {
        const setting = mapStyle.typeSetting(tuning, type);
        const r = 7 * (tuning.nodeSize / 100) * (setting.size / 100);

        return { r: r, box: Math.ceil(r * 2.6) };
    }

    function nodeIconHtml(node, type) {
        const setting = mapStyle.typeSetting(tuning, type);
        const color = mapStyle.color(type);
        const { r, box } = nodeBox(type);
        const hasLabel = type !== 'road' && String(node.title || node.name || '').trim() !== '';

        // ラベルがある地点は中心に白を置く。アプリの `NODE_CORE_RADIUS` と同じ見せ方
        const core = hasLabel
            ? `<circle cx="0" cy="0" r="${(r * 0.42).toFixed(2)}" fill="#ffffff"></circle>`
            : '';

        return `<svg width="${box}" height="${box}" viewBox="${-box / 2} ${-box / 2} ${box} ${box}"
                     class="km-node-shape" aria-hidden="true">
                  <path d="${mapStyle.shapePath(setting.shape, r)}" fill="${color}"></path>
                  ${core}
                </svg>`;
    }

    function createNodeMarker(id, node) {
        const type = String(node.type || 'room');
        const setting = mapStyle.typeSetting(tuning, type);
        const box = nodeBox(type).box;
        // ポスターのある地点。図形に輪を付け、名前に 🖼️ を付ける(styles.css の .km-node-has-poster)
        const hasPoster = postersByNode.has(id);
        // イベント中の見分け(2026-10-24): イベントの地点は赤い太い縁、関係ない地点は薄く(下の eventNodeRole)
        const role = eventNodeRole(node, hasPoster);

        const marker = L.marker([node.y, node.x], {
            icon: L.divIcon({
                className: 'km-node km-node-' + type + (hasPoster ? ' km-node-has-poster' : '') + (role ? ' km-node-' + role : ''),
                html: nodeIconHtml(node, type),
                iconSize: [box, box],
                iconAnchor: [box / 2, box / 2],
            }),
            keyboard: false,
            // 掴みやすさは変えない。編集画面で小さな点を掴む操作がある
            interactive: true,
        });

        // ツールチップは html: true で描くので、DB 由来の文字列は必ずエスケープする
        // 企画の地点はイベント中、一時地点と同じく番号のポスターの見出しを出す(2026-10-23)
        const project = projectDisplayFor(id, node);
        let tooltipContent = (hasPoster ? '<span class="km-label-poster" aria-hidden="true">🖼️</span>' : '')
            /*
             * **札はすべて 7 文字以上なら「6 文字 + …」**(2026-10-24、利用者の指示「すべてのノードを 6 文字で…にする」)。
             * それまではポスターの見出しと一時地点だけを縮めていた。詳細・検索の候補・案内の帯は縮めない(読む所)
             */
            + escapeHtml(shortPoiName(project ? project.name : displayNameFor(node)));
        if (window.isAdmin) {
            /*
             * **`style="…"` を書かない。** CSP に `'unsafe-inline'` が無く、
             * style 属性には nonce もハッシュも効かない(`lib/csp.php`)。
             * ここは以前インラインの style を持っており、**効かないまま気付かれていなかった。**
             */
            /*
             * 出すもの(ID・座標・先生の名前)は地図編集の切り替えに従う(2026-10-23。admin/map-editor.php・map-editor.js)。
             * 切り替えが無い画面では今までどおり全部出す
             */
            const prefs = window.kmAdminLabelPrefs || { id: true, xy: true, names: true };
            const name = prefs.names ? displayNameFor(node) : (node.alias || node.name);
            tooltipContent = '<div class="km-admin-tip">'
                + (prefs.id ? `<span class="km-admin-tip-id">${escapeHtml(id)}</span><br>` : '')
                + escapeHtml(name)
                // 企画の番号(管理アプリで振る)と、イベント中に出さない印(2026-10-23。地図編集で見分けるため)
                + (Array.isArray(node.projects) && node.projects.length > 0
                    ? `<br><span class="km-admin-tip-project">企画 ${node.projects.map(escapeHtml).join('・')}</span>` : '')
                + (node.hidden ? '<br><span class="km-admin-tip-hidden">イベント中は出さない</span>' : '')
                + (prefs.xy ? `<br><span class="km-admin-tip-xy">(${node.x}, ${node.y})</span>` : '')
                + '</div>';
        }

        /*
         * 文字を出すか。**道の点は名前を持たないことが多い**ので、
         * 図形だけにする(アプリの `hasLabel` と同じ判定)。
         */
        const hasLabel = type !== 'road' && String(node.name || '').trim() !== '';
        const typeAllowsLabel = setting.label === null
            ? tuning.labelMode !== 'icon_only'
            : setting.label;
        const drawText = hasLabel && typeAllowsLabel;

        if (drawText || window.isAdmin) {
            marker.bindTooltip(tooltipContent, {
                permanent: true,
                direction: 'top',
                offset: [0, -box / 2],
                interactive: true,
                className: 'km-label km-label-' + type + (role ? ' km-label-' + role : ''),
                html: true,
            });
        }

        if (!window.isAdmin) {
            /*
             * 詳細は**左の引き出し**に出す(2026-10-23、Google マップ風。下の openPlaceSheet)。
             * 引き出しの無いページだけ、今までどおり点の吹き出し。
             *
             * **onclick 属性は使えない。** CSP(script-src に 'unsafe-inline' なし)は
             * インラインのイベントハンドラを実行させない — nonce もハッシュも
             * 属性には効かない。実際 1.0.1 でここが動かなくなっていた。
             * 行き先は data 属性に置き、クリックは委譲リスナー(handleDetailClick)で受ける。
             */
            if (!window.KmSheet) marker.bindPopup(nodeDetailHtml(id, node, type));
            // どの地点の詳細が開いたかを、popupopen で引けるようにする(テスト用アカウントの記録)
            marker.kmNodeId = id;
        }

        marker.on('click', (e) => {
            /*
             * 「位置の更新」で地点を選んでもらっている最中(2026-09-25)。**選べる地点なら選択として受け取り、
             * 詳細は開かない**(bindPopup が先に開くので、ここで閉じる)。選べない地点は、ふつうに詳細を出す。
             */
            if (typeof window.kmRoutePicking === 'function' && window.kmRoutePicking(id)) {
                L.DomEvent.stopPropagation(e);
                window.map.closePopup();
                return;
            }
            // 編集ツールが動いている間は、どのモードでもマーカーのクリックを
            // そちらへ渡す(移動・編集・削除でもマーカーを掴む必要があるため)。
            // stopPropagation で地図側のクリックには流さない
            // — 例えば「移動」では、対象を選ぶクリックが移動先の指定として
            // 二重に解釈されてしまう。
            if (window.builderMode && window.builderMode !== "none") {
                L.DomEvent.stopPropagation(e);
                if (window.handleMarkerClickForBuilder) {
                    window.handleMarkerClickForBuilder(id, marker);
                }
                return;
            }
            // 公開の地図: 左の引き出しに詳細(2026-10-23)
            if (!window.isAdmin && window.KmSheet) {
                L.DomEvent.stopPropagation(e);
                openPlaceSheet({ nodeId: id });
            }
        });

        return marker;
    }

    /**
     * 臨時の地点(一時ノード)のマーカー。**部屋(外は建物)の地点と同じ見た目と振る舞い**(2026-10-23、利用者の指示)。
     * 形・色・大きさ・札の出し方は表示調整のその種類の設定に従う。名前はポスターの見出し(poiDisplay。札は 7 文字以上なら縮める)。
     * 吹き出しは poiDetailHtml。案内はつなぎ先の地点へ(公開の地図だけで使う)
     */
    function createPoiMarker(poi, type) {
        const shown = poiDisplay(poi);
        const setting = mapStyle.typeSetting(tuning, type);
        const box = nodeBox(type).box;
        const hasPoster = postersByNode.has('poi:' + poi.id);

        // 一時地点はイベントの地点(赤い太い縁。2026-10-24)。一時地点はイベント中にしか無い
        const role = window.isAdmin ? '' : 'event';

        const marker = L.marker([poi.y, poi.x], {
            icon: L.divIcon({
                className: 'km-node km-node-' + type + (hasPoster ? ' km-node-has-poster' : '') + (role ? ' km-node-' + role : ''),
                html: nodeIconHtml({ name: shown.name }, type),
                iconSize: [box, box],
                iconAnchor: [box / 2, box / 2],
            }),
            keyboard: false,
            interactive: true,
        });

        const typeAllowsLabel = setting.label === null ? tuning.labelMode !== 'icon_only' : setting.label;
        if (shown.name.trim() !== '' && typeAllowsLabel) {
            marker.bindTooltip((hasPoster ? '<span class="km-label-poster" aria-hidden="true">🖼️</span>' : '')
                + escapeHtml(shortPoiName(shown.name)), {
                permanent: true,
                direction: 'top',
                offset: [0, -box / 2],
                interactive: true,
                className: 'km-label km-label-' + type + (role ? ' km-label-' + role : ''),
                html: true,
            });
        }
        // 詳細は左の引き出し(2026-10-23)。引き出しの無いページだけ点の吹き出し
        if (window.KmSheet) {
            marker.on('click', (e) => {
                L.DomEvent.stopPropagation(e);
                openPlaceSheet({ poiId: Number(poi.id) });
            });
        } else {
            marker.bindPopup(poiDetailHtml(poi, shown));
        }
        return marker;
    }

    /**
     * 地点の詳細。アプリのボトムシート(`NodeInfoBottomSheet`)に合わせる。
     *
     * **担当者名を独立した項目にする。** 以前は `"部屋名 氏名"` と1本の文字列に
     * 繋いでいたので、どこからが人の名前なのか読む側に判断させていた。
     */
    /**
     * その地点を出発地・目的地にしてよいか。**判定はここ1箇所。**
     *
     * 選べる入口が4つある(候補一覧・地点の詳細・分類検索・手入力)。
     * 別々に書くと必ずずれ、**片方からだけ選べる**状態になる。
     *
     * ## 1〜5F の建物名を外す(2026-09-03、利用者の指示)
     *
     * 建物名は「その建物のどこか」でしかない。屋内の階で目的地に選ばれても、
     * **その階のどこへ向かえばよいのか決められない。**
     * 屋外図では建物そのものが行き先になりうるので、そちらは残す。
     *
     * **地図からは消さない。** 場所を探す手がかりとして要る(利用者の判断)。
     */
    function isRoutableNode(node) {
        if (!node) return false;
        // イベント中に出さない地点は、行き先の候補にも出さない(2026-10-14)
        if (node.hidden) return false;
        // 通路の点・壁・Wi-Fi ルーターは、そもそも人が行く場所ではない
        if (node.type === 'road' || node.type === 'wall' || node.type === 'wifi_router') return false;
        if (!String(node.name || '').trim()) return false;
        if (node.type === 'facility' && String(node.floor) !== 'outside') return false;

        return true;
    }
    // 検査(src/scripts/js/)から呼ぶ
    window.kmIsRoutableNode = isRoutableNode;

    /*
     * ======== 施設から中の階へ ========(2026-09-22、利用者の要望)
     *
     * 屋外の建物名(facility)を押しても、これまでは「中の部屋を選んでください」と
     * 言うだけだった。**どの階があるのかも、どう入るのかも分からない。**
     *
     * 建物の階は**接続ID(transferGroupId)から分かる。** 本番の実測では
     * 「4号棟 出入り口」「4号棟 階段」「エレベーター 4号棟」のように、
     * 階段・エレベーター・出入口の接続IDに建物名が入っている。
     * その接続IDを持つ地点が居る階が、その建物の階。
     *
     * **新しい項目を足さない。** 建物ごとの平面図も要らない(今の図はキャンパス全体の
     * 1階〜5階で、階を移せば同じ場所が写っている)。
     */
    function normalizeBuildingText(value) {
        return String(value || '').replace(/[\s　]+/g, '').toLowerCase();
    }

    /**
     * 建物の階(2026-09-24)。**平面図 1 枚が 1 つの階。**
     * 中にまだ地点が無くても出したいので、地点ではなく名前の手がかりで結ぶ
     * (手がかりはサーバーから届く。`lib/building-floors.php` が正本)。
     */
    function buildingFloorLinksFor(facilityNode) {
        const key = normalizeBuildingText(facilityNode && facilityNode.name);
        if (key.length < 2) return [];

        return (graphData.buildingFloors || [])
            .filter(function (floor) {
                return (floor.keywords || []).some(function (word) {
                    return key.indexOf(normalizeBuildingText(word)) >= 0;
                });
            })
            .filter(function (floor) { return Boolean(floorImages[String(floor.id)]); })
            .map(function (floor) {
                // その階に地点があれば、そこへ寄せる。無ければ階を開くだけ
                let nodeId = '';
                for (const [id, node] of Object.entries(graphData.nodes)) {
                    if (String(node.floor) === String(floor.id)) { nodeId = id; break; }
                }
                return { floor: String(floor.id), nodeId: nodeId, label: floor.label };
            });
    }

    function facilityFloorLinks(facilityNode) {
        const key = normalizeBuildingText(facilityNode && facilityNode.name);
        if (key.length < 2) return [];   // 1文字の建物名では他と当たりすぎる

        const byFloor = new Map();
        for (const [id, node] of Object.entries(graphData.nodes)) {
            if (String(node.floor) === 'outside') continue;
            if (normalizeBuildingText(node.transferGroupId).indexOf(key) < 0) continue;
            const floor = String(node.floor);
            const current = byFloor.get(floor);
            // 入口として分かりやすい順に選ぶ(出入口 → 階段・エレベーター → その他)
            const rank = node.type === 'entrance' ? 0 : (node.type === 'stairs' ? 1 : 2);
            if (!current || rank < current.rank) byFloor.set(floor, { id: id, rank: rank });
        }

        const campus = Array.from(byFloor.entries())
            .map(function (entry) { return { floor: entry[0], nodeId: entry[1].id, label: entry[0] + 'F' }; })
            .sort(function (a, b) { return Number(a.floor) - Number(b.floor); });

        // キャンパスの階(1F〜5F)が先、その建物だけの平面図が後
        return campus.concat(buildingFloorLinksFor(facilityNode));
    }
    // 検査(src/scripts/js/)から呼ぶ
    window.kmFacilityFloorLinks = facilityFloorLinks;

    function nodeDetailHtml(id, node, type) {
        const floorLabel = String(node.floor) === 'outside' ? '外' : `${node.floor}F`;
        const typeLabel = mapStyle.LABELS[type] || type;
        // イベント中の名前は**置き換える**(2026-10-14、利用者の判断)。普段の名前は下に小さく残す
        // 企画の地点はイベント中、ポスターの見出しを名前に・説明を備考に(一時地点の吹き出しと同じ。2026-10-23)
        const project = projectDisplayFor(id, node);
        const title = project ? project.name : (node.alias || node.name);

        let rows = '';
        if ((project && project.fromPoster) || node.alias) {
            rows += `<div class="km-detail-row"><span>普段の名前</span><b>${escapeHtml(node.name)}</b></div>`;
        }
        if (node.subtitle) {
            rows += `<div class="km-detail-row"><span>部屋番号</span><b>${escapeHtml(node.subtitle)}</b></div>`;
        }
        if (node.occupantName) {
            rows += `<div class="km-detail-row"><span>担当</span><b>${escapeHtml(node.occupantName)}</b></div>`;
        }
        if (node.type2) {
            rows += `<div class="km-detail-row"><span>分類</span><b>${escapeHtml(node.type2)}</b></div>`;
        }
        if (node.note) {
            rows += `<div class="km-detail-row"><span>メモ</span><b>${escapeHtml(node.note)}</b></div>`;
        }
        if (node.closed) {
            rows += `<div class="km-detail-row km-detail-closed"><span>立入禁止</span>`
                + `<b>${escapeHtml(node.closureReason || '')}</b></div>`;
        }
        // 地点の URL(2026-10-21)。サーバーが http / https だけに確かめてあるが、ここでも形を見てから出す
        if (node.linkUrl && /^https?:\/\//i.test(node.linkUrl)) {
            rows += `<a class="km-detail-link" href="${escapeHtml(node.linkUrl)}" target="_blank" rel="noopener noreferrer">`
                + `${escapeHtml(node.linkLabel || 'くわしく見る')} ↗</a>`;
        }
        // 企画の番号(2026-10-23。管理アプリで振る)
        if (Array.isArray(node.projects) && node.projects.length > 0) {
            rows += `<div class="km-detail-row"><span>企画</span><b>${node.projects.map(escapeHtml).join('・')}</b></div>`;
        }
        if (project && project.note) {
            rows += `<div class="km-detail-row"><span>備考</span><b>${escapeHtml(project.note)}</b></div>`;
        }
        const posters = posterListHtml(id);
        const schedule = scheduleListHtml(id);

        /*
         * **押せないボタンを出さない。** 目的地にできない地点で
         * 灰色のボタンだけ見せても、なぜ押せないのかは伝わらない。
         * 建物名のときは、代わりに何を選べばよいかを一行で言う。
         */
        /*
         * ボタン(2026-10-23 から Google マップ風に「経路」「ここから出発」「共有」)。
         * 「経路」はここを目的地にして、出発地を選んでもらう(経路の欄が開く)
         */
        const share = `<button type="button" class="popup-btn secondary km-detail-share" data-km-share="place" data-km-node="${escapeHtml(id)}">↗ 共有</button>`;
        const actions = isRoutableNode(node)
            ? `<div class="km-detail-actions">
                 <button class="popup-btn km-detail-route" data-km-pick="end" data-km-node="${escapeHtml(id)}">🧭 経路</button>
                 <button class="popup-btn secondary" data-km-pick="start" data-km-node="${escapeHtml(id)}">ここから出発</button>
                 ${share}
               </div>`
            : (node.type === 'facility'
                ? `<p class="km-detail-note">建物そのものは行き先にできません。中の部屋を選んでください。</p><div class="km-detail-actions">${share}</div>`
                : `<div class="km-detail-actions">${share}</div>`);

        /*
         * 中の階へ入る道(2026-09-22)。**階があるときだけ出す。**
         * 中の地図がまだ無い建物で空のボタンを並べても、押せる先が無い。
         */
        const links = node.type === 'facility' ? facilityFloorLinks(node) : [];
        const enter = links.length === 0
            ? ''
            : `<div class="km-detail-enter">
                 <span class="km-detail-enter-label">中へ</span>
                 ${links.map((link) => `<button class="popup-btn secondary km-enter-btn"
                        data-km-enter="${escapeHtml(link.floor)}"
                        data-km-node="${escapeHtml(link.nodeId)}">${escapeHtml(link.label || link.floor)}</button>`).join('')}
               </div>`;

        // ブックマーク(2026-10-23。Main/user-history.js が押したときと開いたときの状態を受け持つ)
        const bookmark = window.KmUserHistory && window.KmUserHistory.enabled
            ? `<button type="button" class="popup-btn secondary km-bookmark-btn" data-km-bookmark="${escapeHtml(id)}" aria-pressed="false">☆ ブックマーク</button>`
            : '';

        return `
            <div class="popup-content km-detail">
                <span class="popup-title">${escapeHtml(title)}</span>
                <div class="km-detail-meta">
                    <span class="km-detail-chip km-chip-${escapeHtml(type)}">${escapeHtml(typeLabel)}</span>
                    <span class="km-detail-floor">${escapeHtml(floorLabel)}</span>
                </div>
                ${rows}
                ${schedule}
                ${posters}
                ${enter}
                ${actions}
                ${bookmark}
            </div>
        `;
    }

    /** スケジュールの日時を「11/3 10:00〜11:00」の形に(サーバーは Y-m-d H:i:s。端末の時刻帯に関係なく字のまま読む) */
    function scheduleTimeLabel(entry) {
        const parse = (value) => /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(String(value || ''));
        const start = parse(entry.startsAt);
        if (!start) return '';
        const end = parse(entry.endsAt);
        const day = `${Number(start[2])}/${Number(start[3])}`;
        let label = `${day} ${start[4]}:${start[5]}`;
        if (end) {
            label += end[1] === start[1] && end[2] === start[2] && end[3] === start[3]
                ? `〜${end[4]}:${end[5]}`
                : `〜${Number(end[2])}/${Number(end[3])} ${end[4]}:${end[5]}`;
        }
        return label;
    }
    window.kmScheduleTimeLabel = scheduleTimeLabel;

    /** 予定が開催中か(始まり〜終わり。終わりが無ければ始まりから 1 時間)。字の日時を端末の時刻として比べる */
    function scheduleIsNow(entry, now) {
        const toDate = (value) => {
            const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(String(value || ''));
            return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5])) : null;
        };
        const start = toDate(entry.startsAt);
        if (!start) return false;
        const end = toDate(entry.endsAt) || new Date(start.getTime() + 3600000);
        return start <= now && now < end;
    }
    window.kmScheduleIsNow = scheduleIsNow;

    /** 地点の詳細に出す「この場所の予定」(2026-10-23)。開催中には印を付ける */
    function scheduleListHtml(nodeId) {
        const list = scheduleByNode.get(nodeId) || [];
        if (list.length === 0) return '';
        const now = new Date();
        return `<div class="km-schedule-list" aria-label="この場所の予定"><div class="km-schedule-head">この場所の予定</div>${list.map(function (entry) {
            const live = scheduleIsNow(entry, now);
            return `<div class="km-schedule-item${live ? ' is-live' : ''}">
                <span class="km-schedule-time">${escapeHtml(scheduleTimeLabel(entry))}${live ? ' <em>開催中</em>' : ''}</span>
                <b class="km-schedule-title">${escapeHtml(entry.title)}</b>
                ${entry.organizer ? `<span class="km-schedule-org">${escapeHtml(entry.organizer)}</span>` : ''}
                ${entry.description ? `<span class="km-schedule-desc">${escapeHtml(entry.description)}</span>` : ''}
            </div>`;
        }).join('')}</div>`;
    }

    /**
     * 地点の詳細に出すポスター(2026-10-21)。一覧のページから来たポスターを先頭にし、印を付ける。
     * 画像の箱は**大きさを決めてある**(styles.css の .km-poster-thumb) —— 画像が読み終わってから
     * 吹き出しの高さが変わると、寄せた位置からずれる。押すと大きく見られる(openPosterViewer)。
     */
    function posterListHtml(nodeId) {
        const list = (postersByNode.get(nodeId) || []).slice();
        if (list.length === 0) return '';
        list.sort(function (a, b) {
            return (Number(b.id) === focusPosterId) - (Number(a.id) === focusPosterId);
        });

        return `<div class="km-poster-list" aria-label="ポスター">${list.map(function (poster) {
            const focused = Number(poster.id) === focusPosterId;
            const link = poster.linkUrl && /^https?:\/\//i.test(poster.linkUrl)
                ? `<a class="km-poster-link" href="${escapeHtml(poster.linkUrl)}" target="_blank" rel="noopener noreferrer" data-km-poster-link="${Number(poster.id)}">🌐 外のサイトで見る ↗</a>`
                : '';
            return `<figure class="km-poster${focused ? ' is-focused' : ''}">
                <button type="button" class="km-poster-thumb" data-km-poster-view="${escapeHtml(String(poster.id))}"
                        aria-label="${escapeHtml(poster.title)}(大きく見る)">
                    <img src="${escapeHtml(poster.thumbUrl || poster.imageUrl)}" alt="" width="${Number(poster.width) || 0}" height="${Number(poster.height) || 0}" loading="lazy">
                </button>
                <figcaption class="km-poster-caption">
                    <b class="km-poster-title">${escapeHtml(poster.title)}</b>
                    ${poster.description ? `<span class="km-poster-desc">${escapeHtml(poster.description)}</span>` : ''}
                    ${link}
                    <button type="button" class="secondary-btn km-like-btn" data-km-poster-like="${Number(poster.id)}" aria-pressed="false">♡ ${Number(poster.likes) || 0}</button>
                </figcaption>
            </figure>`;
        }).join('')}</div>`;
    }

    // ポスターを大きく見るのは左の引き出し(下の「場所の詳細・検索・共有」の節の openPosterViewer。2026-10-23)

    /** その階のマーカー一式を作る(作成済みならそれを返す)。種類ごとに分ける。 */
    function buildFloorMarkers(floor) {
        const key = String(floor);
        const cached = floorMarkerLayers.get(key);
        if (cached) return cached;

        const groups = {};
        Object.keys(mapStyle.COLORS).forEach(function (type) {
            groups[type] = L.layerGroup();
        });

        for (const [id, node] of nodesByFloor.get(key) || []) {
            const type = String(node.type || '');
            /*
             * **知らない種類は描かない。** 色も形も決められないものを
             * 灰色の点で出すと、「そういう地点がある」と読まれてしまう。
             * (移行 SQL を流す前の `point` や、旧実装の `ad` がここに来る)
             */
            if (!groups[type]) continue;

            const marker = createNodeMarker(id, node);
            markersById.set(id, marker);
            groups[type].addLayer(marker);
        }

        /*
         * 臨時の地点(一時ノード)も部屋の地点と同じに描く(2026-10-23、利用者の指示「Room ノードのような動作。外は Facility」)。
         * 同じ種類の束に入れるので、出し入れ(表示調整の種類・倍率)・外の建物名を薄くする・札の重なりの間引きも部屋・建物と同じに効く。
         * 編集画面は renderEventOverlay が今までどおり印で描く
         */
        if (!window.isAdmin) {
            (graphData.eventPois || []).forEach(function (poi) {
                if (String(poi.floor) !== key) return;
                const type = poiNodeType(poi);
                if (!groups[type]) return;
                const marker = createPoiMarker(poi, type);
                poiMarkers.set(Number(poi.id), marker);
                groups[type].addLayer(marker);
            });
        }

        floorMarkerLayers.set(key, groups);
        return groups;
    }

    /** 作り置きを全部捨てる。graphData が書き換わった(管理画面での編集)ときに使う。 */
    function clearFloorMarkers() {
        activeMarkerGroups.forEach(group => window.map.removeLayer(group));
        activeMarkerGroups = [];
        floorMarkerLayers.clear();
        markersById.clear();
        poiMarkers.clear();
    }

    /** 画面の描画を止めないよう、空いた時間に実行する。 */
    function runWhenIdle(task) {
        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(task, { timeout: 2000 });
        } else {
            window.setTimeout(task, 0);
        }
    }

    /**
     * 外・1〜5階の全ノードぶんのマーカーを作っておく。
     *
     * まとめて作るとその間だけ画面が固まるので、空き時間に**1 階ずつ**進める。
     * ここまで済ませておけば、階を切り替えたときのマーカー生成コストがゼロになる。
     *
     * 読み込み画面はこの完了も待つ(進捗の最後の段)。待たずに閉じると、
     * 「地図は出たのに最初の階切り替えでだけ引っかかる」状態が残ってしまう。
     *
     * @returns {Promise<void>} 全階ぶんを作り終えたら解決する
     */
    function prebuildAllFloorMarkers(onProgress) {
        const floors = [...nodesByFloor.keys()];
        let index = 0;

        return new Promise((resolve) => {
            const step = () => {
                if (index >= floors.length) {
                    resolve();
                    return;
                }
                buildFloorMarkers(floors[index++]);
                if (onProgress) onProgress(index, floors.length);
                runWhenIdle(step);
            };
            runWhenIdle(step);
        });
    }

    let imageOverlay = L.imageOverlay("", [[0, 0], [1, 1]]).addTo(window.map);

    /*
     * ---- 収まりの計算 ----
     *
     * #map は画面全面(inset: 0)で、その上に UI レイヤーが浮いている。素の fitBounds は
     * **地図コンテナの大きさしか見ない**ので、トップバー・検索パネル・フロアバーの裏に
     * 建物が隠れる。実際「地図が見にくい」の主因がこれだった。
     *
     * パネルの寸法は固定値にしない。ログイン中の名前が出る/出ないでトップバーの高さが
     * 変わるし、パネルは畳める。**その場で実測**して余白に変換する。
     */
    let currentFloorBounds = null;
    let userMovedMap = false; // 利用者が自分で動かしたら、勝手に戻さない

    const PANEL_GAP = 12; // パネルと地図内容のあいだに置く隙間
    const EDGE_GAP = 20;
    // 共有のリンクから来た人に広告を出すまでの間(開いた場所・経路を先に見せる。下の「共有のリンクから開いたとき」)
    const SHARE_AD_DELAY_MS = 1500;

    function isPanelVisible(element) {
        if (!element || element.classList.contains('collapsed')) return false;
        const style = window.getComputedStyle(element);
        if (style.display === 'none' || style.visibility === 'hidden') return false;
        const rect = element.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    }

    function mapFitPadding() {
        const width = window.innerWidth;
        const height = window.innerHeight;
        let top = EDGE_GAP, left = EDGE_GAP, right = EDGE_GAP, bottom = EDGE_GAP;

        // 上の検索の窓と、その下の種類のチップ(2026-10-23 に上のバーから置き換えた)
        ['km-searchbox', 'km-category-chips'].forEach((elementId) => {
            const element = document.getElementById(elementId);
            if (isPanelVisible(element)) top = Math.max(top, element.getBoundingClientRect().bottom + PANEL_GAP);
        });

        // 左の引き出し(場所の詳細など。Main/km-sheet.js)。PC は左に立つ、スマホは下から出る
        const sheetEl = document.getElementById('km-sheet');
        if (sheetEl && sheetEl.classList.contains('is-open')) {
            // 滑り出している途中でも、出きったときの大きさで数える(位置ではなく幅と高さを見る)
            if (width > 768) {
                left = Math.max(left, sheetEl.offsetWidth + PANEL_GAP);
            } else {
                bottom = Math.max(bottom, sheetEl.offsetHeight + PANEL_GAP);
            }
        }

        const panel = document.getElementById('search-panel');
        if (isPanelVisible(panel)) {
            const rect = panel.getBoundingClientRect();
            // デスクトップでは左に立つ縦長のカード、モバイルでは上いっぱいの欄(2026-10-24 に下から上へ移した)
            if (width > 768) {
                left = Math.max(left, rect.right + PANEL_GAP);
            } else {
                top = Math.max(top, rect.bottom + PANEL_GAP);
            }
        }

        /*
         * 階の切り替えは**右のふちの縦並び**へ移した(2026-09-05)。
         * ここは以前「下辺」として数えていて、そのままにすると
         * **右が隠れたまま、下だけ無駄に空く**(見た目からは原因が分からない)。
         */
        const floorNav = document.querySelector('.floor-nav');
        if (isPanelVisible(floorNav)) {
            right = Math.max(right, width - floorNav.getBoundingClientRect().left + PANEL_GAP);
        }

        // 左下の操作ピル(検索・表示調整)。開いた表示調整のパネルも同じ側から出る
        const actionBar = document.querySelector('.km-action-bar');
        if (isPanelVisible(actionBar)) {
            bottom = Math.max(bottom, height - actionBar.getBoundingClientRect().top + PANEL_GAP);
        }

        // ルート案内の帯(操作ピルのすぐ上)。出ている間は、経路がその裏に潜らないように
        const routeBarEl = document.getElementById('km-route-bar');
        if (routeBarEl && !routeBarEl.hidden && isPanelVisible(routeBarEl)) {
            bottom = Math.max(bottom, height - routeBarEl.getBoundingClientRect().top + PANEL_GAP);
        }

        /*
         * 余白が画面を食い尽くすと Leaflet が破綻する(縦横が 0 以下になる)。
         *
         * **辺ごとに 40% で頭を打つ。** 以前は「左右の合計」で見て、超えたら
         * 左だけを削っていた —— 右だけで 75% を超えると左が**負の値**になり、
         * その先の計算がまとめて狂う。階のレールが右へ移って右が伸びる形になったので、
         * 片側だけを削る形は成り立たなくなった。
         */
        left = Math.min(left, width * 0.4);
        right = Math.min(right, width * 0.4);
        top = Math.min(top, height * 0.4);
        bottom = Math.min(bottom, height * 0.4);

        return {
            paddingTopLeft: L.point(Math.round(left), Math.round(top)),
            paddingBottomRight: L.point(Math.round(right), Math.round(bottom)),
        };
    }

    /**
     * 余白を考えたうえで、その階の全体が入るように収める。
     *
     * **ズームの下限も一緒に決め直す。** 既定の minZoom は -1 で、画面が狭いと
     * 全体を入れるのに -1 より小さい倍率が要る。そのとき Leaflet は下限で止まるので、
     * fitBounds を呼んでいるのに**右側や下側がはみ出したまま**になる
     * (実測: 幅400pxで zoom が -1 に張り付き、6号棟から先が画面の外に出ていた)。
     */
    function applyFit(rawBounds) {
        fitMinZoom(rawBounds);
        window.map.fitBounds(L.latLngBounds(rawBounds), mapFitPadding());
    }

    /** その階の全体が入る倍率(fitMinZoom が出す)。拡大したら部品を隠す所(下の「地図以外を隠す」)の基準 */
    let floorFitZoom = null;

    /** その階の全体が入る倍率まで、ズームの下限を下げる(収めはしない。経路の途中で階を移るとき用。2026-10-23) */
    function fitMinZoom(rawBounds) {
        /*
         * 呼び出し側は [[0,0],[h,w]] の素の配列を渡してくる(Leaflet はそれを受け付ける)。
         * ここでは getNorthWest() などを使うので、**LatLngBounds へ揃えてから触る**。
         * 配列のまま扱おうとして例外になり、画像の描画ごと止まった。
         */
        const bounds = L.latLngBounds(rawBounds);
        const padding = mapFitPadding();
        const total = padding.paddingTopLeft.add(padding.paddingBottomRight);

        /*
         * 必要な倍率は自分で出す。**map.getBoundsZoom() は現在の minZoom で
         * 頭打ちにされる**ため、下限そのものを決めたいこの用途には使えない
         * (実測: 余白 0 で呼んでも -1 が返り、「これ以上引けない」ことに気付けない)。
         * zoom 0 の座標系に投影して、入れたい寸法との比から素直に計算する。
         */
        const available = window.map.getSize().subtract(total);
        const northWest = window.map.project(bounds.getNorthWest(), 0);
        const southEast = window.map.project(bounds.getSouthEast(), 0);
        const boundsSize = southEast.subtract(northWest);
        const width = Math.abs(boundsSize.x);
        const height = Math.abs(boundsSize.y);

        if (available.x > 0 && available.y > 0 && width > 0 && height > 0) {
            const scale = Math.min(available.x / width, available.y / height);
            // 下限は zoomSnap(0.25)の刻みに合わせて、必要なぶんだけ下げる
            const fitZoom = Math.floor(Math.log2(scale) * 4) / 4;
            floorFitZoom = fitZoom;
            window.map.setMinZoom(Math.min(-1, fitZoom));
        }
    }

    /** いまの経路がその階で始まる所。無ければ null */
    function firstRouteLatLngOn(floor) {
        for (const id of currentRoutePath || []) {
            const node = graphData.nodes[id];
            if (node && String(node.floor) === String(floor)) return [node.y, node.x];
        }
        return null;
    }

    /** いまの階を、パネルを避けた形で収め直す。 */
    function refitCurrentFloor(force = false) {
        if (!currentFloorBounds) return;
        window.map.invalidateSize();
        if (userMovedMap && !force) return; // 自分で拡大した人の視点を奪わない
        applyFit(currentFloorBounds);
    }
    window.kmRefitMap = refitCurrentFloor; // パネル側(panel.js)からも呼べるように

    /*
     * ---- 見取り図が読めなかったときの案内 ----
     *
     * 読めなかった階の表示をそのままにすると、**前の階の見取り図が残ったまま**
     * フロアバーだけが新しい階を指す状態になる。利用者からは「読み込めなかった」
     * ではなく「違う階が表示されている」ようにしか見えない(実際にそう見えた)。
     * 古い絵を消し、何が起きたのかを出し、やり直せるようにする。
     *
     * 地図の操作は止めない — 他の階へは移れるべきなので、覆いかぶせない小さな札にする。
     * index.php には置かず JS で作る。失敗して初めて要るものなので先に描く必要が無く、
     * こうしておけば同じ app.js を読む admin/map-editor.php でも出る。
     */
    let floorNotice = null;
    let floorNoticeText = null;
    let floorNoticeRetry = null;

    const FLOOR_NOTICE_LOADING = '見取り図を読み込んでいます…';
    const FLOOR_NOTICE_FAILED = 'この階の見取り図を読み込めませんでした。通信環境を確認して、もう一度お試しください。';

    /**
     * @param {'loading'|'failed'} state
     *   loading … まだ取得中。**今いる階だけ先に読む方式にしたので、裏の取得が
     *             追いつく前に切り替えられると数秒待つことがある**(1Mbps で実測)。
     *             何も出さないと地図が消えただけに見えるので、状況を出す。
     *   failed  … 再試行も尽きた。やり直す手段を添える。
     */
    function showFloorNotice(state) {
        if (!floorNotice) {
            floorNotice = document.createElement('div');
            floorNotice.className = 'km-floor-notice';
            // 見た目はクラスで作る。style="…" は CSP に弾かれる(lib/csp.php)
            const card = document.createElement('div');
            card.className = 'km-floor-notice-card';

            floorNoticeText = document.createElement('p');
            floorNoticeText.className = 'km-floor-notice-text';

            floorNoticeRetry = document.createElement('button');
            floorNoticeRetry.type = 'button';
            floorNoticeRetry.className = 'secondary-btn km-floor-notice-retry';
            floorNoticeRetry.textContent = 'もう一度試す';
            floorNoticeRetry.addEventListener('click', () => {
                hideFloorNotice();
                loadFloorImageDynamic(window.currentFloor);
            });

            card.append(floorNoticeText, floorNoticeRetry);
            floorNotice.append(card);
            document.body.append(floorNotice);
        }

        const loading = state === 'loading';
        floorNoticeText.textContent = loading ? FLOOR_NOTICE_LOADING : FLOOR_NOTICE_FAILED;
        // 取得中はまだ押せることが無いので、ボタンごと隠す
        floorNoticeRetry.classList.toggle('is-hidden', loading);
        floorNotice.classList.toggle('is-loading', loading);
        floorNotice.classList.add('is-visible');
    }

    function hideFloorNotice() {
        if (floorNotice) floorNotice.classList.remove('is-visible');
    }

    /**
     * 読めなかった階に切り替わったときの後始末。
     * 古い絵を消したうえで、いまの階のラベルは出す(データはあるので、
     * 「絵だけが無い」ことが伝わる状態にしておく)。
     */
    function clearFloorImage() {
        if (imageOverlay) {
            window.map.removeLayer(imageOverlay);
            imageOverlay = null;
        }
        // 収める対象が無くなったので、画面サイズ変更時に前の階へ合わせ直さないようにする
        currentFloorBounds = null;
        renderGlobalLabels();
        renderGlobalEdges();
        renderEventOverlay();
        renderBuildingSwitcher();
        renderBuildingOverlays();
    }

    /** プリロード済み(またはロードし終えた)画像を実際に地図へ反映する。 */
    function applyFloorImage(src, bounds) {
        hideFloorNotice(); // 読めたので、前に出していた案内は引っ込める

        // 既存のオーバーレイを削除して再作成（Android WebViewの描画バグ対策）
        if (imageOverlay) {
            window.map.removeLayer(imageOverlay);
        }
        imageOverlay = L.imageOverlay(src, bounds).addTo(window.map);

        /*
         * 階を切り替えたら、その階の全体像をもう一度見せる。ここは「利用者が
         * 自分で動かしたか」に関わらず収め直してよい(別の階へ移った時点で
         * それまでの視点は意味を持たない)。
         */
        currentFloorBounds = bounds;
        window.map.invalidateSize(); // コンテナサイズの再計算
        /*
         * **経路を出している間は、拡大率を戻さない**(2026-10-23、利用者の指摘「ルートを設定して別の階に行くと
         * 拡大率がリセットされて面倒」)。下限の倍率だけその階に合わせ直し、位置は drawPath がその階の経路の始まりへ動かす。
         * 経路を引いた直後(routeFitPending)と、経路の無いときは今までどおり階の全体を見せる。
         */
        if (currentRoutePath && !routeFitPending) {
            fitMinZoom(bounds);
            if (!firstRouteLatLngOn(window.currentFloor)) {
                window.map.setView(L.latLngBounds(bounds).getCenter(), window.map.getZoom());
            }
        } else {
            userMovedMap = false;
            applyFit(bounds);
        }

        renderGlobalLabels();
        renderGlobalEdges();
        renderEventOverlay();
        renderBuildingSwitcher();
        renderBuildingOverlays();

        if (currentRoutePath) {
            drawPath(currentRoutePath);
        }
    }

    const IMAGE_RETRY_LIMIT = 3;

    function loadFloorImageDynamic(floor, attempt = 0) {
        if (!floorImages[floor]) return;

        // 先読み済みなら即座に反映(ネットワーク待ちが発生しない)
        const cached = preloadedImages[floor];
        if (cached) {
            applyFloorImage(cached.src, cached.bounds);
            // 次に行きそうな階(上下)を裏で読んでおく。先読みは隣だけにした(neighborFloors)
            preloadNeighborFloors(floor);
            return;
        }

        /*
         * 先読みがまだ済んでいない階。ここから取り直すが、**待っているあいだ前の階の絵を
         * 残さない。** 残すと「違う階が表示されている」状態になる(見えている時間が短い
         * だけで、諦めたときと同じ誤解を与える)。
         *
         * 消したうえで、取得中であることを出す。今いる階だけ先に読んで地図を見せる方式に
         * したため、**裏の取得が追いつく前に切り替えられると数秒空く**(1Mbps で実測)。
         * 何も出さないと地図が消えただけに見えてしまう。
         */
        if (attempt === 0) {
            clearFloorImage();
            showFloorNotice('loading');
        }

        preloadFloorImage(floor).then((entry) => {
            // 非同期の間に他の階へ切り替えられていたら、古い画像を出さない
            if (String(window.currentFloor) !== String(floor)) return;

            if (entry) {
                applyFloorImage(entry.src, entry.bounds);
                preloadNeighborFloors(floor);   // いまの階が載ってから(取り合わせない)
                return;
            }

            /*
             * 読み込みに失敗した。回線が一瞬切れただけということが多い(実測: 開発用の
             * PHP 組み込みサーバーでも、同時接続が重なると普通に落ちる)。
             * **今表示しようとしている階に限って**少し待って試し直す。
             * ここで諦めると、地図が白いまま何も起きないページになってしまう。
             */
            if (attempt < IMAGE_RETRY_LIMIT) {
                window.setTimeout(() => loadFloorImageDynamic(floor, attempt + 1), 400 * (attempt + 1));
                return;
            }

            /*
             * 試し直しても駄目だった。**ここで黙って戻らないこと。**
             * 戻ると前の階の絵が残り、フロアバーだけが新しい階を指す —— 利用者には
             * 「違う階が出ている」ようにしか見えない。古い絵を消して事情を出す。
             */
            console.error(`見取り図を読み込めませんでした(${IMAGE_RETRY_LIMIT}回試行): ${floorImages[floor]}`);
            clearFloorImage();
            showFloorNotice('failed');
        });
    }

    /*
     * ---- 起動時の読み込み ----
     *
     * **今いる階が使える状態になった時点で読み込み画面を閉じる。** 残りの階は裏で読み続ける。
     *
     * 以前は全6階が揃うまで見せない作りにしていた。速い回線では全部で1秒未満なので
     * 差が出ないが、細い回線では致命的だった —— 1Mbps の実測で、1階の見取り図は
     * 6.0秒 で届いているのに読み込み画面が閉じるのは 19.3秒。**13秒は「もう見せられる
     * ものを見せずに待たせていた」時間**だった。
     *
     * 今いる階のマーカーは applyFloorImage() → renderGlobalLabels() が同期的に作るので、
     * 画像が地図へ載った時点で操作できる状態は揃っている。
     *
     * **抜け道は残す。** 今いる階の1枚すら取れないことは普通に起こる(学内WiFiの混雑、
     * モバイルの瞬断)。時間切れで打ち切り、揃っているぶんで開始する。
     */
    const LOADING_TIMEOUT_MS = 15000;

    /*
     * ======== QR から開いた部屋 ========(2026-10-14、利用者の指示「URL にオプションを付ける」)
     *
     * `/?room=<地点の id>` で開かれたら、その部屋の階へ移って寄せ、詳細を開く。
     * id は index.php が字の形だけ確かめて `<meta name="km-focus-room">` に入れる(DB は引かない)。
     * **イベントの外でも効く**(普段の掲示にも使える)。
     *
     * 詳細は印のポップアップではなく、その場で開く —— 拡大の度合いによっては部屋の印が
     * まだ出ていない(種類ごとの出し入れ)。印が無くても詳細は出したい。
     */
    let focusRoomDone = false;
    function focusRoomFromUrl() {
        if (focusRoomDone) return;
        focusRoomDone = true;
        const meta = document.querySelector('meta[name="km-focus-room"]');
        const id = meta ? meta.getAttribute('content') : '';
        if (!id) return;
        const node = graphData.nodes[id];
        if (!node || node.hidden) {
            // 出さない地点・地図から消えた部屋。**黙らない** —— QR を読んだのに何も起きないと壊れて見える
            showRoomNotice('この部屋は今は案内していません。地図から探してください。');
            return;
        }
        if (focusPosterId && !postersById.has(focusPosterId)) {
            // 一覧のページを開いたあとで出し方が変わった・止められた。部屋は開く
            showRoomNotice('このポスターは今は掲示していません。');
        }
        const floor = String(node.floor);
        const open = () => {
            userMovedMap = true;   // このあと収め直して位置を戻さない
            /*
             * 部屋を**見えている範囲の下寄り**に置く。詳細の吹き出しは部屋の上へ伸びるので、
             * 真ん中に置くとトップバーの裏に潜る(手元で実測)。見えている範囲は、
             * トップバー・検索パネル・階の並びを除いたところ(mapFitPadding)。
             */
            const zoom = Math.max(window.map.getZoom(), 1);
            const padding = mapFitPadding();
            const size = window.map.getSize();
            const visibleX = (padding.paddingTopLeft.x + size.x - padding.paddingBottomRight.x) / 2;
            const visibleY = padding.paddingTopLeft.y
                + (size.y - padding.paddingTopLeft.y - padding.paddingBottomRight.y) * 0.75;
            const center = window.map.project([node.y, node.x], zoom)
                .add([size.x / 2 - visibleX, size.y / 2 - visibleY]);
            window.map.setView(window.map.unproject(center, zoom), zoom, { animate: false });
            // 詳細は左の引き出し(2026-10-23)。引き出しの無いページは今までどおり、その場の吹き出し
            if (!openPlaceSheet({ nodeId: id })) {
                L.popup({ autoPan: false })
                    .setLatLng([node.y, node.x])
                    .setContent(nodeDetailHtml(id, node, String(node.type || 'room')))
                    .openOn(window.map);
            }
        };
        if (floor !== String(window.currentFloor) && floorImages[floor]) {
            // 階の図は非同期に載る。**載ってから寄せる**(kmEnterBuilding と同じ)
            window.map.once('moveend', open);
            changeFloor(floor);
        } else {
            open();
        }
    }

    function showRoomNotice(text) {
        const notice = document.createElement('div');
        notice.className = 'km-room-notice';
        notice.setAttribute('role', 'status');
        notice.textContent = text;
        document.body.append(notice);
        window.setTimeout(() => notice.remove(), 6000);
    }

    (async () => {
        const imagesDone = preloadAllFloorImages(
            window.currentFloor,
            (loaded, total) => kmLoading.progress('images', loaded, total),
            () => {
                // 今いる階が読めた。地図へ載せて、ここで見せてしまう
                loadFloorImageDynamic(window.currentFloor);
                kmLoading.finish();
                window.setTimeout(focusRoomFromUrl, 0);
            }
        );

        // マーカーの作り置きは表示を待たせない(今いる階のぶんは上で作られている)
        const markersDone = prebuildAllFloorMarkers((built, total) => {
            kmLoading.progress('markers', built, total);
        });

        /*
         * 時間切れの見張り。onFirstReady が来ていれば kmLoading.finish() は
         * 二度目を無視するので、ここは「1枚目すら来なかったとき」の保険として働く。
         */
        window.setTimeout(() => {
            if (!kmLoading.isDone) {
                console.warn(`地図の準備が ${LOADING_TIMEOUT_MS}ms 以内に終わりませんでした。揃っているぶんで開始します。`);
                loadFloorImageDynamic(window.currentFloor);
                kmLoading.finish();
                window.setTimeout(focusRoomFromUrl, 0);
            }
        }, LOADING_TIMEOUT_MS);

        // 残りは裏で進む。失敗しても表示済みの地図には影響させない
        Promise.all([imagesDone, markersDone]).catch(() => {});
    })();

    /*
     * ======== ラベルの重なりをほどく ========(2026-09-22、利用者の指摘)
     *
     * **屋外で施設名が重なって読めない。** 1〜3号棟のように建物の点が数十 px しか
     * 離れていない場所があり、名前のピルは点より遥かに大きいので必ず被る。
     *
     * 座標そのものは触らない —— 地図の正本で、アプリにも同じものが配られる。
     * **見せ方の側で、ずらして、それでも駄目なら引っ込める。**
     *
     *   1. 大事なものから順に置く(施設 → 出入口 → 階段 → 部屋 → その他)
     *   2. 重なったら上下にずらす → 点の横・下に付ける → **点から離して線で結ぶ**(2026-10-14)
     *   3. どこへ置いても重なるなら、**そのラベルは出さない**(点は出したまま。
     *      押せば名前が読めるので、情報は失われない)。2026-10-30 から建物の中も同じ(下の declutterLabels)
     *
     * 置き方の計算は `Main/label-layout.js`(アプリと同じ手順)。
     * ズームを上げると点どうしの間が開くので、隠れていたものから順に戻ってくる。
     *
     * **大事な順**(2026-10-30、振り返りのフェーズ 2「大事な地点だけ出す」): イベントの地点 > 施設・出入口 > 階段 > 部屋 > ほか。
     * 中くらいの倍率では下の方から隠れる。イベントで関係ない地点(薄くしている)は一番後ろ
     */
    const KM_LABEL_PRIORITY = { facility: 1, entrance: 1, stairs: 2, room: 3, wifi_router: 4, road: 5, wall: 6 };
    const KM_LABEL_PRIORITY_EVENT = 0;
    const KM_LABEL_PRIORITY_UNRELATED = 10;
    let declutterTimer = null;
    let lastDeclutterKey = '';
    let declutterGeneration = 0;
    /** 1 コマに書く札の数(書くだけなので軽い。測るのは 1 回にまとめる) */
    const DECLUTTER_CHUNK = 60;

    function labelPriority(el) {
        if (el.classList.contains('km-label-event')) return KM_LABEL_PRIORITY_EVENT;
        const type = (el.className.match(/km-label-([a-z_]+)/) || [])[1];
        const base = KM_LABEL_PRIORITY[type] === undefined ? 9 : KM_LABEL_PRIORITY[type];
        return el.classList.contains('km-label-unrelated') ? KM_LABEL_PRIORITY_UNRELATED + base : base;
    }

    /** 札の要素 → その点のマーカー(点の中心を測って、線の根元にする) */
    function markersByLabel() {
        const byLabel = new Map();
        forEachPointMarker(function (marker) {
            const tip = marker.getTooltip && marker.getTooltip();
            const el = tip && tip.getElement && tip.getElement();
            if (el && el.isConnected) byLabel.set(el, marker);
        });
        return byLabel;
    }

    /** 地点のマーカー全部(臨時の地点も部屋と同じに数える。2026-10-23) */
    function forEachPointMarker(fn) {
        markersById.forEach(fn);
        poiMarkers.forEach(fn);
    }

    function clearLabelLayout(el) {
        el.classList.remove('km-label-hidden', 'km-label-led');
        el.style.marginTop = '';
        el.style.marginLeft = '';
        ['--km-lead-x', '--km-lead-y', '--km-lead-len', '--km-lead-ang'].forEach(function (name) {
            el.style.removeProperty(name);
        });
    }

    function declutterLabels() {
        /*
         * **編集画面では間引かない。** 管理者は全部見えている前提で位置を直す
         * (隠れたラベルを探して地図を拡大する作業になってしまう)。
         */
        if (window.isAdmin) return;
        const all = Array.from(document.querySelectorAll('.km-label'));
        if (all.length === 0) return;

        // 前回の結果を戻してから測る。**戻さずに測ると、ずらした位置を元の位置として扱う**
        all.forEach(clearLabelLayout);
        const layoutApi = window.KM_LABEL_LAYOUT;
        if (!layoutApi) return;

        /*
         * 置けない札は**建物の中でも出さない**(2026-10-30、振り返りのフェーズ 2。利用者の判断「B」)。
         * それまで建物の中は消さずに元の位置で重ねていたが(2026-09-25)、重なった札はどれも読めなかった。
         * 点は残るので押せば名前が分かり、拡大すると出てくる。**選んだ場所の札だけは隠さない**(keep)
         */
        const selectedAt = selectionRing ? selectionRing.getLatLng() : null;

        /*
         * 薄く出している建物名は**数に入れない。** 下敷きの文字なので、
         * 押せる地点の名前を押しのけたり、隠したりしてはいけない。
         */
        const faded = window.map.getContainer().classList.contains('km-facility-faded');
        const labels = all.filter(function (el) {
            return !(faded && el.classList.contains('km-label-facility'));
        });

        /*
         * **先に全部測ってから、まとめて書く。** 測る・書くを交互にすると、
         * 札の数だけ組版をやり直させることになる(建物の中は 200 個を超える)。
         */
        const byLabel = markersByLabel();
        const items = [];
        const targets = [];
        const nodes = [];
        forEachPointMarker(function (marker) {
            const icon = marker.getElement && marker.getElement();
            if (!icon || !icon.isConnected) return;
            const r = icon.getBoundingClientRect();
            if (r.width === 0 && r.height === 0) return;
            nodes.push({ x: (r.left + r.right) / 2, y: (r.top + r.bottom) / 2, radius: r.width / 2 });
        });
        labels.forEach(function (el) {
            const base = el.getBoundingClientRect();
            if (base.width === 0 && base.height === 0) return;   // そもそも出ていない
            const marker = byLabel.get(el);
            const icon = marker && marker.getElement && marker.getElement();
            const r = icon ? icon.getBoundingClientRect() : null;
            items.push({
                box: { left: base.left, top: base.top, right: base.right, bottom: base.bottom },
                anchor: r && r.width > 0 ? { x: (r.left + r.right) / 2, y: (r.top + r.bottom) / 2 } : null,
                radius: r ? r.width / 2 : 0,
                priority: labelPriority(el),
                keep: !!(selectedAt && marker && marker.getLatLng && marker.getLatLng().equals(selectedAt)),
            });
            targets.push(el);
        });
        /*
         * 札の元の margin-top(leaflet.css の .leaflet-tooltip-top は -6px)。ずらす量はこれに**足して**書く。
         * 2026-10-30 まで dy だけを書いて -6px を消していたので、上下にずらした札は 6px 下へ落ち、隣の札と重なっていた
         */
        const baseMarginTop = targets.length > 0 ? (parseFloat(window.getComputedStyle(targets[0]).marginTop) || 0) : 0;
        const c = window.map.getContainer().getBoundingClientRect();
        const placements = layoutApi.layout(items, {
            hideWhenCrowded: true,
            bounds: { left: c.left, top: c.top, right: c.right, bottom: c.bottom },
            nodes: nodes,
        });

        /*
         * **書くのはコマに分ける**(2026-10-30、振り返りのフェーズ 2。利用者の案「数ミリ秒ずらす」)。建物の中は札が 200 を超え、
         * 一度に書くとズームの直後に 1 コマ大きく止まった。DECLUTTER_CHUNK 個ずつ requestAnimationFrame で書く。
         * 途中で次の並べ直しが始まったら、古い分は書かない(generation)
         */
        const generation = ++declutterGeneration;
        let next = 0;
        const writeChunk = function () {
            if (generation !== declutterGeneration) return;
            const end = Math.min(placements.length, next + DECLUTTER_CHUNK);
            for (; next < end; next++) applyPlacement(placements[next], targets[next], items[next], baseMarginTop);
            if (next < placements.length) {
                window.requestAnimationFrame(writeChunk);
                return;
            }
            // 札が落ち着いたので、経路の線の重なりを塗り直す(札の位置で透かす所が変わる)
            if (routeLineRuns.length > 0) routeLineRuns.forEach(paintRouteRun);
        };
        writeChunk();
    }

    /** 1 つの札に置き方を書く(declutterLabels がコマに分けて呼ぶ) */
    function applyPlacement(p, el, item, baseMarginTop) {
        if (p === null) {
            el.classList.add('km-label-hidden');
            return;
        }
        if (p.dy !== 0) el.style.marginTop = (baseMarginTop + p.dy) + 'px';
        if (p.dx !== 0) el.style.marginLeft = p.dx + 'px';
        if (p.leader) {
            /*
             * 線は札の ::after で描く(styles.css の .km-label-led)。札と一緒に動くので、
             * 地図を動かしている間もずれない。始点は札の縁、向きは点の方。
             * **style 属性ではなく CSSOM で入れる**(CSP は style 属性を止めるが、JS からの代入は通す)。
             */
            const box = item.box;
            const left = box.left + p.dx;
            const top = box.top + p.dy;
            const vx = p.leader.x1 - p.leader.x2;
            const vy = p.leader.y1 - p.leader.y2;
            el.classList.add('km-label-led');
            // 1.5px は札の枠。::after の left/top は枠の内側から測る
            el.style.setProperty('--km-lead-x', (p.leader.x2 - left - 1.5).toFixed(1) + 'px');
            el.style.setProperty('--km-lead-y', (p.leader.y2 - top - 1.5).toFixed(1) + 'px');
            el.style.setProperty('--km-lead-len', Math.sqrt(vx * vx + vy * vy).toFixed(1) + 'px');
            el.style.setProperty('--km-lead-ang', (Math.atan2(vy, vx) * 180 / Math.PI).toFixed(1) + 'deg');
        }
    }

    /*
     * 連続して呼ばれる(ズーム中・移動中)ので、落ち着いてから1回だけ測る。
     *
     * **横へ動かしただけ(拡大率も階も札の数も同じ)なら測り直さない**(2026-10-30、振り返りのフェーズ 2「ズームがカクつく」)。
     * 札と点は同じだけ動くので、ずらし方は変わらない。それまでは moveend のたびに全部の札を測り直していた(200 個 × 2 回の測り)
     */
    function declutterKey() {
        return [window.map.getZoom(), String(window.currentFloor), document.querySelectorAll('.km-label').length].join('|');
    }
    function scheduleDeclutter(force) {
        if (force !== true && declutterKey() === lastDeclutterKey) return;
        window.clearTimeout(declutterTimer);
        declutterTimer = window.setTimeout(function () {
            lastDeclutterKey = declutterKey();
            declutterLabels();
        }, 120);
    }
    // 外から呼ばれるのは札の中身が変わったとき(表示調整・階の切り替えなど)。必ず測り直す
    window.kmDeclutterLabels = function () { scheduleDeclutter(true); };

    // 部屋の詳細を開いた(テスト用アカウントの記録。createNodeMarker が marker.kmNodeId を付ける)
    window.map.on('popupopen', (e) => {
        const source = e.popup && e.popup._source;
        if (source && source.kmNodeId) testLog('detail', { node: source.kmNodeId, floor: String(window.currentFloor) });
        // 履歴の「開いた場所」とブックマークの ☆(2026-10-23。Main/user-history.js)
        if (source && source.kmNodeId) window.dispatchEvent(new CustomEvent('km:node-opened', { detail: { id: source.kmNodeId } }));
    });

    window.map.on('zoomend', () => {
        renderGlobalLabels();
        scheduleDeclutter(true);
    });
    window.map.on('moveend', () => scheduleDeclutter(false));

    /*
     * 利用者が自分で拡大・移動したかを覚えておく。Leaflet はプログラムからの
     * fitBounds でも同じイベントを出すので、**入力由来かどうか**を見る
     * (originalEvent があるのは実際の操作のとき)。
     */
    window.map.on('dragstart', () => {
        userMovedMap = true;
        // 地図を触った = 見る先が地図へ移った。階の一覧は畳む(下の「畳む」節)
        setFloorNavCollapsed(true);
    });
    window.map.on('zoomstart', (event) => {
        if (!event || !event.originalEvent) return;   // fitBounds では畳まない
        userMovedMap = true;
        setFloorNavCollapsed(true);
    });

    /*
     * ======== 拡大したら地図以外を隠す ========(2026-10-30、振り返りのフェーズ 2)
     *
     * スマホで部屋を探して拡大すると、上の検索・チップと下の操作の帯で地図の半分近くが埋まる。
     * **利用者が自分で**その階の全体の 4 倍(2 段)より拡大したら、検索・チップ・アカウント・操作の帯を薄く消す。
     * 縮めるか、地図の何もない所を軽くたたくと戻る。階のボタン・経路の帯・ズームのボタンは残す(今どこかと戻り方)。
     *
     * 「自分で」は、地図の上の操作(指・ホイール・ズームのボタン・キー)から 1.5 秒以内の拡大で見る。
     * Leaflet はピンチやホイールの zoomstart に originalEvent を付けないので、上の zoomstart の判定は使えない。
     * 検索の候補で飛んだとき(fitBounds)は隠さない
     */
    const KM_CHROME_HIDE_STEPS = 2;
    const KM_CHROME_INPUT_MS = 1500;
    let mapInputAt = 0;
    ['pointerdown', 'wheel', 'touchstart', 'keydown'].forEach(function (type) {
        window.map.getContainer().addEventListener(type, function () { mapInputAt = Date.now(); }, { passive: true });
    });
    function setChromeHidden(hidden) {
        document.body.classList.toggle('km-chrome-hidden', hidden);
    }
    /** 使っている最中は隠さない(検索に打っている・候補・アカウントの欄・経路の欄が開いている) */
    function chromeInUse() {
        const active = document.activeElement;
        if (active && active.closest && active.closest('#km-searchbox, #km-account, #km-category-chips, #km-action-bar')) return true;
        const account = document.getElementById('km-account');
        if (account && account.open) return true;
        return document.body.classList.contains('km-route-panel-open');
    }
    window.map.on('zoomend', function () {
        if (floorFitZoom === null) return;
        const deep = window.map.getZoom() - floorFitZoom >= KM_CHROME_HIDE_STEPS;
        if (!deep) {
            setChromeHidden(false);
        } else if (Date.now() - mapInputAt < KM_CHROME_INPUT_MS && !chromeInUse()) {
            setChromeHidden(true);
        }
    });
    // 地図の何もない所をたたく(点を押したときは点が止めるので来ない)
    window.map.on('click', function () { setChromeHidden(false); });
    // キーで動かす人は、隠れたボタンへ Tab で行けない。Tab・Esc で戻す
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Tab' || event.key === 'Escape') setChromeHidden(false);
    });

    // 画面サイズが変わったら収め直す。連続発火するので落ち着いてから1回だけ。
    let resizeTimer = null;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => refitCurrentFloor(), 200);
    });

    /**
     * 経路が見つからなかった理由を言い分ける。
     *
     * **同じ文言で済ませない。**
     *   - イベント中なら「いま塞がっているから」で、別の日には通れる
     *   - 屋外と中をまたぐのに出入口の対応付けが1つも無いなら、**地図の登録漏れ**。
     *     これは「経路が見つかりません」と言われても直しようが無い ——
     *     利用者の指摘(2026-09-05)は、まさにこの状態を「壊れている」と読んだもの
     *   - どちらでもなければ、素直に繋がっていない
     */
    function routeFailureMessage(startId, endId) {
        if (dijkstra.blockedByEvent > 0) {
            return 'イベント中の通行止めにより、経路が見つかりませんでした。\n地図上の赤い破線が通れない場所です。';
        }

        const start = graphData.nodes[startId];
        const end = graphData.nodes[endId];
        const outside = (node) => node && String(node.floor) === 'outside';
        // 片方だけが屋外 = 出入口を通る必要がある
        const needsEntrance = start && end && outside(start) !== outside(end);

        if (needsEntrance && dijkstra.entranceTransfers === 0) {
            return window.isAdmin
                ? '屋外と建物の中を繋ぐ出入口が1組もありません。\n'
                    + '1階側と屋外側の出入口ノードに**同じ接続ID**を付けてください'
                    + '(アプリの「接続ID」と同じもの)。'
                : '屋外と建物の中を繋ぐ出入口が地図に登録されていないため、\n'
                    + '外と中をまたぐ案内ができません。管理者にお知らせください。';
        }

        return '経路が見つかりませんでした';
    }

    // ---- UIイベントリスナー ----
    /*
     * 階を移るときの好み。**選び直したらその場で引き直す** ——
     * 経路が出たまま好みだけ変わると、画面の線と設定が食い違う。
     */
    const verticalSelect = document.getElementById('vertical-pref');
    if (verticalSelect) {
        verticalSelect.value = verticalPreference;
        verticalSelect.addEventListener('change', () => {
            verticalPreference = verticalSelect.value === 'elevator' || verticalSelect.value === 'stairs'
                ? verticalSelect.value
                : 'any';
            try {
                window.localStorage.setItem(KM_VERTICAL_KEY, verticalPreference);
            } catch (e) {
                // 保存できなくても、この画面の間は効く
            }
            rebuildDijkstra();
            if (currentRoutePath && startInput && endInput && startInput.value && endInput.value) {
                searchBtn.click();
            }
        });
    }

    /*
     * C・D の切り替え。**選び直したらその場で引き直す**(「階を移るとき」と同じ理由)。
     */
    [['route-fewer-floors', 'fewerFloors'], ['route-rain', 'rain']].forEach(([elementId, key]) => {
        const box = document.getElementById(elementId);
        if (!box) return;
        box.checked = routeOptions[key];
        box.addEventListener('change', () => {
            routeOptions = Object.assign({}, routeOptions, { [key]: box.checked });
            try {
                window.localStorage.setItem(KM_ROUTE_OPTIONS_KEY, JSON.stringify(routeOptions));
            } catch (e) {
                // 保存できなくても、この画面の間は効く
            }
            rebuildDijkstra();
            updateRouteOptionsState();
            if (currentRoutePath && startInput && endInput && startInput.value && endInput.value) {
                searchBtn.click();
            }
        });
    });

    /*
     * 「経路の設定」は畳んである(2026-10-24。スマホで経路の欄を短くし、キーボードに隠れないようにした)。
     * 畳んでいても効いている設定が分かるよう、見出しの横に出す
     */
    const routeOptionsState = document.getElementById('km-route-options-state');
    function updateRouteOptionsState() {
        if (!routeOptionsState) return;
        const parts = [];
        if (verticalPreference === 'elevator') parts.push('エレベーター優先');
        if (verticalPreference === 'stairs') parts.push('階段優先');
        if (routeOptions.fewerFloors) parts.push('階の移動を減らす');
        if (routeOptions.rain) parts.push('雨の日');
        routeOptionsState.textContent = parts.join('・');
    }
    updateRouteOptionsState();
    if (verticalSelect) verticalSelect.addEventListener('change', updateRouteOptionsState);

    /*
     * テスト用アカウント(展示の PC。2026-10-15)の操作の記録。`window.kmTestLog` は
     * Main/test-mode.js が**印の付いたアカウントのときだけ**用意する。無ければ何もしない
     * (一般の閲覧者の操作は、どこへも送らない)。
     */
    function testLog(kind, data) {
        if (typeof window.kmTestLog === 'function') window.kmTestLog(kind, data);
    }

    // 出発地・目的地の欄に打った語(候補から選んだときも change が来る)。地点に当たれば一緒に残す
    [[startInput, 'start'], [endInput, 'goal']].forEach(([input, field]) => {
        if (!input) return;
        input.addEventListener('change', () => {
            const text = input.value.trim();
            if (text === '') return;
            testLog('search', { field: field, text: text, node: resolveNodeByLabel(text) });
        });
    });

    // 手で打ち直したら、選んであった地点(id)は捨てる。案内開始は打った文字から引く
    if (startInput) startInput.addEventListener('input', () => { routeStartId = null; routeStartPoi = null; });
    if (endInput) endInput.addEventListener('input', () => { routeEndId = null; routeEndPoi = null; });

    // 共有のリンクから引いている間だけ true(focusFromUrl)。km:route に載せて履歴に入れないようにする
    let routeFromShare = false;

    // ---- UIイベントリスナー ----
    if (searchBtn) {
        searchBtn.addEventListener('click', () => {
            const startText = startInput.value;
            const endText = endInput.value;

            const startNode = routeStartId || resolveNodeByLabel(startText);
            const endNode = routeEndId || resolveNodeByLabel(endText);

            if (!startNode || !endNode) {
                // 片方だけのときは、足りない方を選んでもらう(黙って何もしないと、ボタンが壊れて見える)
                if (startNode || endNode) {
                    openRoutePanel(startNode ? 'end' : 'start');
                    return;
                }
                alert('リストから出発地と目的地を選択または正しく入力してください');
                return;
            }
            if (startNode === endNode) {
                alert('出発地と目的地が同じです。');
                return;
            }

            const path = dijkstra.findShortestPath(startNode, endNode);
            // 条件も残す(階段・エレベーターの好み、雨の日など)。引けなかったことも手がかりになる
            testLog('route', {
                node: startNode,
                node2: endNode,
                text: [
                    'vertical=' + verticalPreference,
                    routeOptions.fewerFloors ? 'fewerFloors' : '',
                    routeOptions.rain ? 'rain' : '',
                    path ? '' : 'notFound',
                ].filter(Boolean).join(','),
            });
            if (path) {
                // 履歴の「経路」と「検索した言葉」(2026-10-23。Main/user-history.js)
                window.dispatchEvent(new CustomEvent('km:route', { detail: { from: startNode, to: endNode, fromText: startText, toText: endText, shared: routeFromShare } }));
                currentRoutePath = path;
                routeFitPending = true;
                setRouteHint('');
                /*
                 * 好みを変えて引き直したときも、**最初の区間から**案内し直す ——
                 * 経路が変われば、それまでの「いま何番目」は別の経路の番号になる。
                 */
                routeSteps = buildRouteSteps(path);
                routeStepIndex = 0;
                renderRouteBar();
                if (window.innerWidth <= 768 && searchPanel) {
                    setSearchPanelCollapsed(true);
                }
                /*
                 * **出発地の階を開く。** いまの階に経路が無いと、線が 1 本も見えないまま
                 * 「案内が始まった」ことになる(従来はそうだった)。
                 * 階を変えると、図が載ったところで applyFloorImage → drawPath が走る。
                 */
                const startFloor = String(graphData.nodes[path[0]].floor);
                if (startFloor !== String(window.currentFloor) && floorImages[startFloor]) {
                    changeFloor(startFloor);
                } else {
                    drawPath(path);
                }
            } else {
                alert(routeFailureMessage(startNode, endNode));
            }
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            startInput.value = '';
            endInput.value = '';
            routeStartId = null;
            routeEndId = null;
            routeStartPoi = null;
            routeEndPoi = null;
            setRouteHint('');
            clearRoute();
        });
    }

    /*
     * 経路の欄の一言(2026-10-23)。片方だけ選んだときに「出発地を選んでください」を出す。
     * 欄は検索パネル(#search-panel)。無ければ作らない
     */
    let routeHint = null;
    function setRouteHint(text) {
        if (!searchPanel) return;
        if (!routeHint) {
            routeHint = document.createElement('p');
            routeHint.className = 'km-route-hint';
            routeHint.setAttribute('role', 'status');
            const inner = searchPanel.querySelector('.search-inner');
            if (inner) inner.insertBefore(routeHint, inner.firstChild);
        }
        routeHint.textContent = text;
        routeHint.hidden = text === '';
    }

    /** 経路の欄を開き、足りない方の欄へフォーカスを置く('start' か 'end') */
    function openRoutePanel(missing) {
        setSearchPanelCollapsed(false);
        if (window.KmSheet) window.KmSheet.close('place');
        setRouteHint(missing === 'start'
            ? '出発地を選んでください(検索するか、地図の地点を押して「ここから出発」)'
            : '目的地を選んでください(検索するか、地図の地点を押して「経路」)');
        const input = missing === 'start' ? startInput : endInput;
        if (input) window.setTimeout(() => input.focus(), 50);
    }
    window.kmOpenRoutePanel = openRoutePanel;

    /**
     * 経路を消して、案内の帯を下げる。**入力欄は残す**(✕ は「案内をやめる」で、
     * 同じ行き先をもう一度引くことはよくある)。入力まで消すのはリセットの役目。
     */
    function clearRoute() {
        currentRoutePath = null;
        routeSteps = [];
        routeStepIndex = 0;
        currentMarkers.forEach(m => window.map.removeLayer(m));
        currentMarkers = [];
        routeLineRuns = [];
        renderRouteBar();
    }

    if (routeCloseBtn) routeCloseBtn.addEventListener('click', clearRoute);
    if (routeNextBtn) routeNextBtn.addEventListener('click', togglePositionPick);
    if (routeTargetBtn) routeTargetBtn.addEventListener('click', focusRouteTarget);

    floorBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            const floor = e.target.dataset.floor;
            changeFloor(floor);
        });
    });

    /*
     * ======== 階のレールを自動で畳む ========(2026-09-05、利用者の指示)
     *
     * 丸を6つ縦に積むと右のふちに 300px 前後の帯ができ、**地図の右側が常に隠れる**。
     * 階を変えるのは「たまに」の操作なので、ふだんは丸1つ(いまの階)にしておき、
     * 押したときだけ一覧を出す。
     *
     * 畳む合図は3つ。**どれも「もう使い終わった」と読める瞬間だけ**にする:
     *   1. 階を選んだ            —— 用が済んだ(changeFloor の末尾)
     *   2. 地図を触った・回した   —— 見る先が地図へ移った
     *   3. しばらく触っていない   —— KM_FLOOR_IDLE_MS
     *
     * **畳んでも地図の収まりは取り直さない。** 取り直すと、何も操作していないのに
     * 一定時間後に地図がひとりでに動く。余白(mapFitPadding)はレールの実寸を読むので、
     * 次に取り直すときの姿で自然に合う。
     *
     * **起動時は開いたまま出す。** 畳んだ姿から始めると、丸1つが階の一覧だと
     * 気づけない。一度見せてから畳むことで、置き場所が残る。
     */
    /* 状態(KM_FLOOR_IDLE_MS / floorIdleTimer / floorPointerInside)は上で宣言済み。理由はそこに */

    /** 畳んだ取っ手に出す名前。ボタンの文字(外 / 1F …)をそのまま借りる */
    function floorToggleTextFor(floor) {
        const btn = document.querySelector(`.floor-nav .floor-btn[data-floor="${floor}"]`);
        // 建物の中は並びにボタンが無い。id(bldg_…)ではなく名前を出す(2026-10-21)
        return btn ? btn.textContent.trim() : floorLabelFor(floor);
    }

    function syncFloorToggle() {
        const nav = document.getElementById('floor-nav');
        const toggle = document.getElementById('floor-toggle');
        if (!nav || !toggle) return;
        const collapsed = nav.classList.contains('is-collapsed');
        const label = toggle.querySelector('.floor-toggle-label');
        /*
         * **中の要素ごと入れ替えない。** 検索のピルで一度やらかしている ——
         * textContent で丸ごと書き換えると、次に差し替える先が消える。
         */
        if (label) label.textContent = collapsed ? floorToggleTextFor(window.currentFloor) : '✕';
        const name = collapsed ? '階を選ぶ' : '階の一覧を閉じる';
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', name);
        toggle.setAttribute('title', name);
    }

    function startFloorIdleTimer() {
        window.clearTimeout(floorIdleTimer);
        const nav = document.getElementById('floor-nav');
        if (!nav || nav.classList.contains('is-collapsed')) return;
        floorIdleTimer = window.setTimeout(() => {
            /*
             * マウスが乗っている間は畳まない。読んでいる最中に押す先が消える。
             *
             * **一覧の中にキーボードの位置があるときも畳まない。**
             * 畳むと `display: none` でその要素ごと消えるので、
             * タブで辿っている人はフォーカスを body へ飛ばされ、現在地を失う。
             *
             * 見るのは**一覧の中だけ**(取っ手は除く)。取っ手はマウスで押した
             * 直後もフォーカスを持つので、そこまで数えると二度と畳まなくなる ——
             * そして取っ手は畳んでも消えないので、守る必要が無い。
             */
            const scroll = document.querySelector('.floor-nav .floor-scroll');
            /*
             * `:focus` ではなく `activeElement` で見る。**窓が裏に回っていると
             * `:focus` はどれにも当たらない**(実測)ので、タブで辿っている最中に
             * 別の窓へ切り替えて戻ってくると、守りが外れる。
             */
            const focusInList = !!(scroll && scroll.contains(document.activeElement));
            if (floorPointerInside || focusInList) {
                startFloorIdleTimer();
                return;
            }
            setFloorNavCollapsed(true);
        }, KM_FLOOR_IDLE_MS);
    }

    function setFloorNavCollapsed(collapsed) {
        const nav = document.getElementById('floor-nav');
        if (!nav) return;
        nav.classList.toggle('is-collapsed', collapsed);
        syncFloorToggle();
        if (collapsed) {
            window.clearTimeout(floorIdleTimer);
        } else {
            startFloorIdleTimer();
        }
    }

    const floorNavEl = document.getElementById('floor-nav');
    const floorToggleEl = document.getElementById('floor-toggle');
    if (floorNavEl && floorToggleEl) {
        floorToggleEl.addEventListener('click', () => {
            setFloorNavCollapsed(!floorNavEl.classList.contains('is-collapsed'));
        });

        /*
         * **マウスのときだけ「乗っている」と数える。**
         * 指で1回触ると pointerenter は飛んでくるが pointerleave は来ない ——
         * 種類を見ないと、スマホでは一度触った時点で二度と畳まなくなる。
         */
        floorNavEl.addEventListener('pointerenter', (e) => {
            if (e.pointerType === 'mouse') floorPointerInside = true;
        });
        floorNavEl.addEventListener('pointerleave', (e) => {
            if (e.pointerType !== 'mouse') return;
            floorPointerInside = false;
            startFloorIdleTimer();
        });
        // レールを触っているあいだは数え直す(送っている最中に消えないように)
        floorNavEl.addEventListener('pointerdown', startFloorIdleTimer);

        /*
         * 地図を触ったときに畳むのは `window.map.on('dragstart' / 'zoomstart')` 側。
         * **この判断を2つ持たない** —— あちらは既に「人が動かしたか」を
         * `originalEvent` の有無で見分けている(fitBounds でも同じイベントが飛ぶ)。
         * ここで別のやり方を足すと、起動直後の収まり合わせで
         * 一度も見せないまま畳む、という取りこぼし方をする。
         */

        syncFloorToggle();
        startFloorIdleTimer();

        /*
         * ======== 階の列の上でホイール・縦のスワイプ ========(2026-10-30、振り返りのフェーズ 3 の 4)
         *
         * 畳んだ丸(いまの階)の上でホイールを回すか、指で上下にはらうと、上下の階へ移る。上へ = 上の階(外 → 1F → 2F …)。
         * 開いた一覧が縦に送れる(階が多くてはみ出している)ときは、一覧を送る方を優先する。
         * 地図の上のホイールは拡大なので、列の上では地図へ渡さない(preventDefault)。続けて回しても 1 段ずつ(250ms)
         */
        const floorStepOrder = () => Array.from(floorBtns)
            .map(b => String(b.dataset.floor || ''))
            .filter(f => f !== '' && floorImages[f])
            .sort((a, b) => floorRank(a) - floorRank(b) || a.localeCompare(b));
        let floorStepAt = 0;
        function stepFloor(direction) {
            const now = Date.now();
            if (now - floorStepAt < 250) return;
            const order = floorStepOrder();
            const at = order.indexOf(String(window.currentFloor));
            const next = order[at + direction];
            if (at < 0 || !next) return;
            floorStepAt = now;
            changeFloor(next);
        }
        const floorListScrolls = () => {
            const scroll = floorNavEl.querySelector('.floor-scroll');
            return !floorNavEl.classList.contains('is-collapsed') && !!scroll && scroll.scrollHeight > scroll.clientHeight + 1;
        };
        floorNavEl.addEventListener('wheel', (e) => {
            if (floorListScrolls() || Math.abs(e.deltaY) < Math.abs(e.deltaX) || e.deltaY === 0) return;
            e.preventDefault();
            e.stopPropagation();
            stepFloor(e.deltaY < 0 ? 1 : -1);
        }, { passive: false });
        let floorSwipeY = null;
        floorNavEl.addEventListener('touchstart', (e) => {
            floorSwipeY = e.touches.length === 1 && !floorListScrolls() ? e.touches[0].clientY : null;
        }, { passive: true });
        floorNavEl.addEventListener('touchend', (e) => {
            if (floorSwipeY === null || e.changedTouches.length !== 1) return;
            const dy = e.changedTouches[0].clientY - floorSwipeY;
            floorSwipeY = null;
            // 30px 以上はらったときだけ(軽く触っただけなら、今までどおり押した扱い)
            if (Math.abs(dy) < 30) return;
            e.preventDefault();   // はらった指で取っ手を「押した」ことにしない(一覧が開かない)
            stepFloor(dy < 0 ? 1 : -1);
        });
    }

    // 種類でまとめて探すのは、検索の窓の下のチップ(下の「種類でまとめて探す」。2026-10-23)


    // ---- 関数類 ----

    /*
     * HTML へ差し込む前のエスケープ(1.0.0 のセキュリティ確認で追加)。
     *
     * 部屋名・教職員氏名は DB の値で、管理画面の地図編集から書き換えられる。
     * それを innerHTML やツールチップ(html: true)へそのまま入れていたため、
     * 名前に HTML を仕込むと**公開ページを見た人の画面で動いてしまう**状態だった。
     * 入れる側(管理者)は信頼できる相手だが、出す先は誰でも見られる公開ページなので塞ぐ。
     */
    function escapeHtml(value) {
        return String(value ?? '').replace(
            /[&<>"']/g,
            (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]
        );
    }

    // 部屋名 + (パスワード解除済みで割り当てがあれば)教職員氏名 をまとめて組み立てる。
    // 移行前の graph.js は "教員室 管-310 架空 三郎" のように1本の文字列だったが、
    // フェーズ3の DB 移行で name(部屋名)と occupantName(教職員氏名)を別カラムに
    // 分離したため、表示側で元の見た目に組み立て直す。
    function displayNameFor(node) {
        /*
         * イベント中の臨時名称。**置き換える**(2026-10-14、利用者の判断)。
         * 以前は「ステージ会場(第1体育館)」と併記していたが、イベントの名前(模擬店・展示)が
         * 主役になる会期では長くて読めない。普段の名前は地点の詳細に残す(nodeDetailHtml)。
         */
        if (node.alias) {
            return node.alias;
        }
        return node.occupantName ? `${node.name} ${node.occupantName}` : node.name;
    }

    /**
     * 企画の番号がある地点(教室など)の、イベント中の名前と備考(2026-10-23、利用者の指摘「教室に企画番号を設定したが、うまく表示されない」)。
     * 一時地点(poiDisplay)と同じ見せ方 —— 番号のポスターがあれば、名前はポスターの見出し・備考はポスターの説明。
     * ポスターが無ければ、番号を名前の前に付ける。**イベント中だけ**(普段は部屋の名前のまま)。当たらなければ null
     */
    function projectDisplayFor(id, node) {
        const numbers = Array.isArray(node.projects) ? node.projects : [];
        if (!graphData.event || numbers.length === 0) return null;
        const base = node.alias || node.name;
        const posters = (postersByNode.get(id) || []).filter(p => p.projectNo && numbers.indexOf(p.projectNo) >= 0);
        if (posters.length === 0) {
            // イベント中の名前そのものが番号(「32」)なら、二重に出さない
            const prefix = numbers.filter(n => n !== String(base || '').trim().toUpperCase()).join('・');
            return { name: prefix ? `${prefix} ${base}` : base, note: '', fromPoster: false };
        }
        return {
            name: posters.map(p => p.title).join('・'),
            note: posters.map(p => p.description).filter(Boolean).join(' / '),
            fromPoster: true,
        };
    }

    /**
     * イベント中の地点の見分け(2026-10-24、利用者の指示「イベントノードは太い赤い縁」「イベントで関係ないものは透明度を上げる(50%〜)」)。
     *   'event'     … イベントの地点。臨時の名前(alias)・企画の番号(projects)・ポスターのどれかがある(一時地点は createPoiMarker で 'event')
     *   'unrelated' … それ以外(階段・出入口・トイレ・道なども。出してはいるが、イベントには関係ない)
     *   ''          … イベント中でない・管理画面(地図編集では見分けない)
     * 見た目は styles.css の .km-node-event / .km-node-unrelated(札は .km-label-*)。アプリの eventNodeUuids(MapEvent.kt)と同じ決め方
     */
    function eventNodeRole(node, hasPoster) {
        if (window.isAdmin || !graphData.event) return '';
        const hasProject = Array.isArray(node.projects) && node.projects.length > 0;
        return node.alias || hasProject || hasPoster ? 'event' : 'unrelated';
    }

    function getLabelForNode(id, node) {
        const floorLabel = String(node.floor) === "outside" ? "外" : `${node.floor}F`;
        // 臨時名称があるときは、その名前で引く(2026-10-14 から置き換え。displayNameFor と同じ)
        const name = node.alias || node.name;
        // 企画の地点はポスターの見出しでも探せる(部屋の名前も残す。2026-10-23)
        const project = projectDisplayFor(id, node);
        if (project) {
            return project.fromPoster ? `[${floorLabel}] ${project.name}(${node.name})` : `[${floorLabel}] ${project.name}`;
        }
        return `[${floorLabel}] ${name}`;
    }

    /**
     * 入力欄の文字列から目的のノード ID を引く。
     *
     * 候補一覧(datalist)には**臨時の地点も混ざる**ので、ノードだけを探すと
     * 「一覧から選んだのに『正しく入力してください』と言われる」ことになる。
     * 臨時の地点はアンカーのノードへ読み替える。
     */
    function resolveNodeByLabel(text) {
        /*
         * **選べない地点は、手で打たれても受けない。**
         * 候補一覧から外しただけでは、前に選んだ文字列が入力欄に残っていたり、
         * 覚えて打った人が通ってしまう。**入口ごとに塞ぐ。**
         */
        const hit = Object.keys(graphData.nodes)
            .find(id => isRoutableNode(graphData.nodes[id])
                && getLabelForNode(id, graphData.nodes[id]) === text);
        if (hit) return hit;

        const poi = (graphData.eventPois || []).find(p => {
            if (!p.anchorNodeId) return false;
            const floorLabel = String(p.floor) === "outside" ? "外" : `${p.floor}F`;
            // 名前が番号の一時地点はポスターの見出しで探せる(2026-10-23。poiDisplay)
            return `[${floorLabel}] ${poiDisplay(p).name}` === text;
        });

        return poi && graphData.nodes[poi.anchorNodeId] ? poi.anchorNodeId : undefined;
    }

    function populateSelectBoxes() {
        if (!datalist) return;
        datalist.innerHTML = '';
        const labels = [];
        for (const [id, node] of Object.entries(graphData.nodes)) {
            // 出発地・目的地に選べるものだけを候補に出す(判定は isRoutableNode)
            if (!isRoutableNode(node)) continue;
            labels.push(getLabelForNode(id, node));
        }

        /*
         * 臨時の地点(模擬店など)も候補に入れる。**アンカーを持つものだけ。**
         * 経路グラフ上に点が無い地点を選ばせても案内できないので、
         * 「探せるのに案内できない」状態を作らない。
         */
        (graphData.eventPois || []).forEach(poi => {
            if (!poi.anchorNodeId) return;
            const anchor = graphData.nodes[poi.anchorNodeId];
            if (!anchor) return;
            const floorLabel = String(poi.floor) === "outside" ? "外" : `${poi.floor}F`;
            labels.push(`[${floorLabel}] ${poiDisplay(poi).name}`);
        });

        labels.sort((a, b) => a.localeCompare(b, 'ja'));
        labels.forEach(label => {
            const opt = document.createElement('option');
            opt.value = label;
            datalist.appendChild(opt);
        });
    }

    window.renderGlobalLabels = function() {
        if (window.isAdmin) {
            // 編集直後で graphData が変わっている可能性があるため、作り直す
            buildFloorIndex();
            clearFloorMarkers();
        }

        const groups = buildFloorMarkers(window.currentFloor);
        const zoom = window.map.getZoom();

        /*
         * 種類ごとに出し入れする(アプリの `visibleGeneralMapNodes` と同じ決め方)。
         *
         * **編集画面では間引かない。** 移動・編集・削除・結合で掴みたいマーカーが
         * そもそも存在しなくなる(管理画面のカードに収めた初期表示は zoom -1 で、
         * どの閾値も下回る)。閲覧側の挙動は変わらない。
         */
        const floor = String(window.currentFloor);
        const wanted = [];
        Object.keys(groups).forEach(function (type) {
            if (window.isAdmin || mapStyle.isVisible(tuning, type, zoom, floor)) {
                wanted.push(groups[type]);
            }
        });

        /*
         * 屋外で拡大したときの建物名(2026-09-25)。**図形を消し、名前を薄く、押せなくする。**
         * 出し入れの顔ぶれは変わらない(建物名は出したまま)ので、**下の早期リターンより前で**
         * 切り替えること —— 後ろに置くと、閾値をまたいでも見た目が変わらない。
         * 見た目は styles.css の `.km-facility-faded`。
         */
        window.map.getContainer().classList.toggle(
            'km-facility-faded',
            !window.isAdmin && mapStyle.facilityFaded(tuning, zoom, floor)
        );

        /*
         * 出す顔ぶれが前回と同じなら何もしない。**ここが効く。** この関数は zoomend
         * にぶら下がっていて、拡大縮小のたびに呼ばれるが、実際に表示が変わるのは
         * 閾値(ZOOM_THRESHOLD)をまたいだ一瞬だけ。それ以外は素通りさせる。
         */
        const unchanged = wanted.length === activeMarkerGroups.length
            && wanted.every((group, i) => group === activeMarkerGroups[i]);
        if (unchanged) return;

        activeMarkerGroups.forEach(group => {
            if (!wanted.includes(group)) window.map.removeLayer(group);
        });
        wanted.forEach(group => {
            if (!window.map.hasLayer(group)) group.addTo(window.map);
        });
        activeMarkerGroups = wanted;
    }


    /*
     * ---- イベントモードの重ね合わせ ----
     *
     * 通行止め(赤い破線と ✕)と臨時の地点(模擬店・受付など)を描く。
     *
     * **管理者だけでなく公開ページでも描く。** 通常のエッジは管理者にしか出していないが、
     * 「この廊下は通れません」は来場者にこそ要る情報で、出さないと
     * 経路が遠回りになった理由が伝わらない。
     */
    /**
     * 臨時の地点に出す名前と備考(2026-10-23、利用者の指示「名前はポスターに対応した名前、備考には説明」)。
     * 名前が企画の番号の一時地点で、その番号のポスターがあれば、ポスターの見出しと説明。無ければ地点の名前とメモ
     */
    function poiDisplay(poi) {
        const poster = (postersByNode.get('poi:' + poi.id) || [])[0];
        const number = /^[0-9A-Za-z][0-9A-Za-z_-]{0,15}$/.test(String(poi.name || '').trim()) ? String(poi.name).trim().toUpperCase() : null;
        return {
            name: poster ? poster.title : String(poi.name || ''),
            note: poster && poster.description ? poster.description : String(poi.note || ''),
            number: number,
        };
    }

    /** 札の名前。**7 文字以上なら 6 文字 + …**(文字は見た目の 1 文字で数える。絵文字や合成文字を割らない) */
    function shortPoiName(name) {
        const chars = Array.from(String(name || ''));
        return chars.length >= 7 ? chars.slice(0, 6).join('') + '…' : chars.join('');
    }
    window.kmShortPoiName = shortPoiName;

    /** 臨時の地点が地図で何として振る舞うか(2026-10-23、利用者の指示「一時ノードは Room ノードのような動作。外は Facility」) */
    function poiNodeType(poi) {
        /*
         * いつも部屋(2026-10-23、利用者の判断「Outside の Facility は厳しい。Room みたいに」)。
         * 建物にしていた間は、外で拡大すると建物名と一緒に薄くなり、押せなくなっていた
         */
        return 'room';
    }

    /** 臨時の地点の吹き出し。部屋の詳細(nodeDetailHtml)と同じ形(種類は部屋・外は建物。模擬店などは「分類」に出す) */
    function poiDetailHtml(poi, shown) {
        const floorLabel = String(poi.floor) === 'outside' ? '外' : `${poi.floor}F`;
        const type = poiNodeType(poi);
        let rows = `<div class="km-detail-row"><span>分類</span><b>${escapeHtml(KM_POI_LABELS[poi.category] || KM_POI_LABELS.other)}</b></div>`;
        if (shown.number) {
            rows += `<div class="km-detail-row"><span>企画</span><b>${escapeHtml(shown.number)}</b></div>`;
        }
        if (shown.note) {
            rows += `<div class="km-detail-row"><span>備考</span><b>${escapeHtml(shown.note)}</b></div>`;
        }
        // ボタンは onclick を書かない(CSP)。data 属性 + 委譲で拾う。案内先(つなぎ先)が無ければ出さない
        const actions = poi.anchorNodeId && graphData.nodes[poi.anchorNodeId]
            ? `<div class="km-detail-actions">
                 <button class="popup-btn km-detail-route" data-km-pick="end" data-km-node="${escapeHtml(poi.anchorNodeId)}" data-km-poi="${Number(poi.id)}">🧭 経路</button>
                 <button class="popup-btn secondary" data-km-pick="start" data-km-node="${escapeHtml(poi.anchorNodeId)}" data-km-poi="${Number(poi.id)}">ここから出発</button>
                 <button type="button" class="popup-btn secondary km-detail-share" data-km-share="place" data-km-poi="${Number(poi.id)}">↗ 共有</button>
               </div>`
            : '<p class="km-detail-note">この地点はまだ道につながっていないので、案内できません。</p>';
        return `
            <div class="popup-content km-detail">
                <span class="popup-title">${escapeHtml(shown.name)}</span>
                <div class="km-detail-meta">
                    <span class="km-detail-chip km-chip-${escapeHtml(type)}">${escapeHtml(mapStyle.LABELS[type] || type)}</span>
                    <span class="km-detail-floor">${escapeHtml(floorLabel)}</span>
                </div>
                ${rows}
                ${scheduleListHtml('poi:' + poi.id)}
                ${posterListHtml('poi:' + poi.id)}
                ${actions}
            </div>
        `;
    }

    let eventLayers = [];

    function renderEventOverlay() {
        eventLayers.forEach(layer => window.map.removeLayer(layer));
        eventLayers = [];

        const floor = String(window.currentFloor);

        // --- 通行止めの経路 ---
        (edgesByFloor.get(floor) || []).forEach(({ n1, n2, closed, closureReason }) => {
            if (!closed) return;
            const line = L.polyline([[n1.y, n1.x], [n2.y, n2.x]], {
                color: '#dc2626', weight: 5, opacity: 0.85, dashArray: '10, 6',
            }).addTo(window.map);
            line.bindPopup(closureReason
                ? `<b>通行止め</b><br>${escapeHtml(closureReason)}`
                : '<b>通行止め</b>');
            eventLayers.push(line);

            const mid = L.circleMarker([(n1.y + n2.y) / 2, (n1.x + n2.x) / 2], {
                radius: 9, color: '#dc2626', weight: 2, fillColor: '#fff', fillOpacity: 1,
            }).addTo(window.map);
            mid.bindTooltip('✕', { permanent: true, direction: 'center', className: 'km-closure-mark' });
            mid.bindPopup(closureReason
                ? `<b>通行止め</b><br>${escapeHtml(closureReason)}`
                : '<b>通行止め</b>');
            eventLayers.push(mid);
        });

        // --- 立入禁止の地点 ---
        (nodesByFloor.get(floor) || []).forEach(([, node]) => {
            if (!node.closed) return;
            const marker = L.circleMarker([node.y, node.x], {
                radius: 12, color: '#dc2626', weight: 3, fillColor: '#fecaca', fillOpacity: 0.85,
            }).addTo(window.map);
            marker.bindPopup(
                `<b>${escapeHtml(displayNameFor(node))}</b><br>立入禁止`
                + (node.closureReason ? `<br>${escapeHtml(node.closureReason)}` : '')
            );
            eventLayers.push(marker);
        });

        /*
         * --- 臨時の地点(地図編集の画面だけ) ---
         * 公開の地図では部屋(外は建物)の地点と同じに描く(2026-10-23、利用者の指示。buildFloorMarkers の createPoiMarker)。
         * 編集画面は今までどおり印で描く(地点の札と取り違えないため)
         */
        if (!window.isAdmin) return;
        (graphData.eventPois || []).forEach(poi => {
            if (String(poi.floor) !== floor) return;
            const icon = KM_POI_ICONS[poi.category] || KM_POI_ICONS.other;
            const marker = L.marker([poi.y, poi.x], {
                icon: L.divIcon({
                    className: 'km-event-poi',
                    html: `<span class="km-event-poi-icon">${icon}</span>`,
                    iconSize: [28, 28],
                    iconAnchor: [14, 14],
                }),
            }).addTo(window.map);

            // 部屋と同じ吹き出し(km-detail)にする(2026-10-23、利用者の指示)。名前はポスターの見出し・備考はポスターの説明
            const shown = poiDisplay(poi);
            marker.bindPopup(poiDetailHtml(poi, shown));
            // 地図の札は 7 文字以上なら縮める(部屋の札と同じ形)
            marker.bindTooltip(escapeHtml(shortPoiName(shown.name)), {
                permanent: true,
                direction: 'top',
                offset: [0, -14],
                className: 'km-label km-label-poi',
            });
            eventLayers.push(marker);
        });
    }

    /**
     * 線を引く。**アプリと同じ見せ方に揃えた**(2026-09-03)。
     *
     * それまでは管理者にしか出していなかった。アプリ側は来場者にも
     * 壁を実線で、通路の線は破線で描いている(`GeneralMapNodeRenderer` の
     * `shouldDrawGeneralMapLine` / `isWallLine`)。
     *
     * **道グラフは既定で伏せる。** 通路の点を繋いだ線を出すと、
     * 建物の形より線の網の方が目立ってしまう。表示調整で出せる。
     */
    function renderGlobalEdges() {
        if (window.isAdmin) buildFloorIndex(); // 編集直後で graphData が変わっている可能性があるため

        adminEdges.forEach(e => window.map.removeLayer(e));
        adminEdges = [];

        const showRoadGraph = window.isAdmin || tuning.roadGraph;
        const floorEdges = edgesByFloor.get(String(window.currentFloor)) || [];

        floorEdges.forEach(({ n1, n2, wall }) => {
            const touchesRoad = n1.type === 'road' || n2.type === 'road';
            if (touchesRoad && !showRoadGraph) return;
            /*
             * 壁の線を消せるようにした(2026-09-05、利用者の要望)。
             *
             * **経路には影響しない。** 壁は `dijkstra.js` が最初からグラフに載せて
             * いないので、ここで消しても通れるようにはならない ——
             * 消えるのは線だけで、案内が壁を抜けることはない。
             */
            if (wall && !tuning.wallLines) return;

            const color = (n1.type === 'stairs' || n2.type === 'stairs')
                ? mapStyle.color('stairs')
                : (wall ? mapStyle.color('wall') : '#94a3b8');

            const polyline = L.polyline([[n1.y, n1.x], [n2.y, n2.x]], {
                color: color,
                weight: wall ? 3 : 2,
                opacity: wall ? 0.85 : 0.5,
                // **壁は実線。**通れないものと通れるものを、線の引き方で分ける
                dashArray: wall ? null : '4, 4',
            }).addTo(window.map);
            adminEdges.push(polyline);

            if (tuning.distances && !wall) {
                /*
                 * 距離。**画素をそのまま出さない** —— 図面 1600px が何メートルかは
                 * 校正しないと分からない。いまは校正値を持っていないので、
                 * アプリと同じ「m」表記は出せる状態にない。**px と明示する。**
                 */
                const label = L.marker([(n1.y + n2.y) / 2, (n1.x + n2.x) / 2], {
                    icon: L.divIcon({
                        className: 'km-edge-distance',
                        html: escapeHtml(String(Math.round(Math.hypot(n2.x - n1.x, n2.y - n1.y))) + ' px'),
                        iconSize: [48, 14],
                        iconAnchor: [24, 7],
                    }),
                    interactive: false,
                    keyboard: false,
                }).addTo(window.map);
                adminEdges.push(label);
            }
        });
    }

    /*
     * ---- 建物平面図の重ね ----
     *
     * 屋外図の上に、建物の中の見取り図を小さく置く。
     * アプリは APK の中の画像で描いており(`MapOverlayRenderer.kt`)、
     * **Website には無かった** —— 同じ配置データを持っているのに描けていなかった。
     *
     * 画像は 600×600 で、配置は「中心 (x,y)・倍率・角度」。アプリと同じ置き方をする。
     */
    let buildingLayers = [];
    /** 屋外図の上で「建物 1F / 2F」を切り替える。null は全部出す。 */
    let buildingFloor = null;

    function buildingOverlaysForFloor() {
        const floor = String(window.currentFloor);
        const all = (graphData.buildings && graphData.buildings.items) || [];

        return all.filter(function (item) {
            if (String(item.floor) !== floor) return false;
            // 接尾辞の無い画像(平屋)は、どちらを選んでいても出す
            if (buildingFloor === null || item.buildingFloor === null) return true;
            return item.buildingFloor === buildingFloor;
        });
    }

    /*
     * **2026-09-24 から、屋外図の上には重ねない**(利用者の指示)。
     *
     * 平面図は「1 枚 = 1 つの階」になった(`lib/building-floors.php`)。同じ絵を
     * 屋外にも小さく重ねると、**同じ建物が 2 か所に出て、どちらを見ればよいのか分からない。**
     *
     * 配置のデータ(`km_map_overlays`)は消さずに残してある —— 戻したくなったら
     * この 1 行を外せば元どおり描く。
     */
    const KM_DRAW_BUILDING_OVERLAYS = false;

    function renderBuildingOverlays() {
        buildingLayers.forEach(layer => window.map.removeLayer(layer));
        buildingLayers = [];
        if (!KM_DRAW_BUILDING_OVERLAYS) return;

        buildingOverlaysForFloor().forEach(function (item) {
            // 画像の実寸 600px を倍率で伸ばし、中心を (x,y) に合わせる
            const half = (600 * (item.scale || 1)) / 2;
            const bounds = [[item.y - half, item.x - half], [item.y + half, item.x + half]];
            const layer = L.imageOverlay(
                '/api/floor-image.php?building=' + encodeURIComponent(item.imageKey),
                bounds,
                { opacity: item.opacity === undefined ? 1 : item.opacity, interactive: false }
            ).addTo(window.map);

            if (item.rotationDegrees) {
                /*
                 * 角度。**Leaflet は回転を知らない**ので、画像そのものに掛ける。
                 *
                 * **`transform` は使えない。** Leaflet は位置決めに
                 * `translate3d(...)` を入れており、上書きすると
                 * **回した絵が左上へ飛ぶ**(実際に飛んだ。検証で見つけた)。
                 * 足し算しようにも、Leaflet は動かすたびに書き直すので追いつかない。
                 *
                 * 独立した `rotate` プロパティを使う —— `transform` とは別枠で
                 * 合成されるので、Leaflet の位置決めと喧嘩しない。
                 * 対応していない古いブラウザでは**回らないだけ**で、位置は正しい。
                 *
                 * `style="…"` 属性は CSP に弾かれるが、**プロパティへの代入は
                 * 属性ではない**ので通る。
                 */
                const applyRotation = function () {
                    const el = layer.getElement();
                    if (el) {
                        el.style.rotate = item.rotationDegrees + 'deg';
                    }
                };
                layer.once('load', applyRotation);
                applyRotation();
            }

            buildingLayers.push(layer);
        });
    }

    /**
     * 「建物 1F / 2F」の切り替え。**置かれているときだけ出す。**
     *
     * アプリも、その階に平面図があるときにだけ出している(`MapScreen`)。
     * 常に出すと、押しても何も変わらないボタンが並ぶ。
     */
    let buildingSwitcher = null;

    function renderBuildingSwitcher() {
        const floor = String(window.currentFloor);
        // 重ね合わせをやめたので、切り替えるものが無い(上の KM_DRAW_BUILDING_OVERLAYS)
        const all = KM_DRAW_BUILDING_OVERLAYS
            ? ((graphData.buildings && graphData.buildings.items) || [])
            : [];
        const floors = [];
        all.forEach(function (item) {
            if (String(item.floor) === floor && item.buildingFloor !== null
                && floors.indexOf(item.buildingFloor) < 0) {
                floors.push(item.buildingFloor);
            }
        });
        floors.sort();

        if (buildingSwitcher) {
            buildingSwitcher.remove();
            buildingSwitcher = null;
        }
        if (floors.length < 2) {
            // 選ぶものが1つしか無いなら切り替えは要らない
            buildingFloor = null;
            return;
        }
        if (floors.indexOf(buildingFloor) < 0) {
            buildingFloor = floors[0];
        }

        buildingSwitcher = document.createElement('div');
        buildingSwitcher.className = 'km-building-switcher';
        floors.forEach(function (n) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'km-building-btn' + (n === buildingFloor ? ' active' : '');
            button.textContent = '建物 ' + n + 'F';
            button.addEventListener('click', function () {
                buildingFloor = n;
                renderBuildingSwitcher();
                renderBuildingOverlays();
            });
            buildingSwitcher.appendChild(button);
        });
        document.body.appendChild(buildingSwitcher);
    }

    // 地図編集ツールが、経路を追加・削除した直後に引き直すために呼ぶ。
    // (これまでモジュール内の関数だったので、外から再描画できなかった)
    window.renderGlobalEdges = renderGlobalEdges;
    // イベント編集モード(admin/assets/js/editor.js)も、重ね合わせを変えたら引き直す
    window.renderEventOverlay = renderEventOverlay;

    function changeFloor(floor) {
        if (String(floor) !== String(window.currentFloor)) testLog('floor', { floor: String(floor) });
        window.currentFloor = floor;
        floorBtns.forEach(b => b.classList.remove('active'));
        const activeBtn = document.querySelector(`.floor-btn[data-floor="${floor}"]`);
        if (activeBtn) activeBtn.classList.add('active');
        // 前の階について出していた案内を持ち越さない(この階も駄目なら出し直される)
        hideFloorNotice();
        /*
         * 階を選んだら一覧を畳む。用が済んだのに残ると、地図の右が隠れたままになる。
         * **地点の一覧や建物の切り替えから階が変わったときもここを通る** ——
         * 取っ手に出している階名も、この呼び出しで書き換わる。
         */
        setFloorNavCollapsed(true);
        if (floorImages[floor]) loadFloorImageDynamic(floor);
        // 階が変われば並びも変わる。出すラベルを取り直す(上の「ラベルの重なりをほどく」)
        if (window.kmDeclutterLabels) window.kmDeclutterLabels();
        // 経路が使う階のチップの「いまの階」を塗り直す(下の renderRouteFloors。宣言より前に呼ばれうるので window 経由)
        if (window.kmRenderRouteFloors) window.kmRenderRouteFloors();
    }

    /**
     * 経路の線。**太い赤の上に白の破線を重ねる**(アプリの `drawRouteLines` と同じ)。
     *
     * 1本の赤い破線だと、通行止めの赤い破線と見分けが付かない。
     * 地の色が濃い見取り図の上でも、白が乗ることで線が追える。
     *
     * 2本の線を1つの FeatureGroup にまとめて返す —— 呼ぶ側は
     * 「経路の線を1つ足した」として扱えばよく、消すときも1回で済む。
     */
    function drawRouteLine(latlngs) {
        const group = L.featureGroup().addTo(window.map);
        const run = { latlngs: latlngs, group: group };
        routeLineRuns.push(run);
        paintRouteRun(run);
        return group;
    }

    /*
     * ======== 線が地点や札に重なる所は透かす ========(2026-10-24、利用者の指示
     * 「ルート案内をしたときにルートの線が被っていたら、透明度を上げる(60〜80%)」)
     *
     * 経路の線は地点の印より上の層(pane kmRouteLine)に描くので、重なると地点が隠れて読めない。
     * 画面の座標で、いま出している地点の印と札の箱に**重なる区間だけ** 70% 透かす(Main/route-view.js の splitRouteByObstacles)。
     * 札の出し入れと位置は拡大・移動で変わるので、動き終わるたびに塗り直す(下の refreshRouteOverlap)。
     */
    const ROUTE_LINE_OPACITY = 0.9;
    const ROUTE_OVERLAP_OPACITY = 0.3;   // 70% 透かす
    const ROUTE_OVERLAP_PAD = 3;         // 線の太さ(7px)の半分。中心線が箱のふちを通るときも重なりとみなす
    let routeLineRuns = [];              // [{latlngs, group}] いま描いている経路の線(階ごとの切れ目ごと)

    function paintRouteRun(run) {
        run.group.clearLayers();
        routeOverlapPieces(run.latlngs).forEach((piece) => {
            const opacity = piece.overlap ? ROUTE_OVERLAP_OPACITY : ROUTE_LINE_OPACITY;
            // 地点の点より前に描く。線そのものは押せなくする(下の点を押せるように)
            run.group.addLayer(L.polyline(piece.latlngs, {
                color: '#ef4444', weight: 7, opacity: opacity, pane: 'kmRouteLine', interactive: false,
            }));
            run.group.addLayer(L.polyline(piece.latlngs, {
                color: '#ffffff', weight: 2.5, opacity: opacity, dashArray: '10, 10', pane: 'kmRouteLine', interactive: false,
            }));
        });
    }

    /** 線を「重なる区間」と「重ならない区間」に分ける。分けられないとき(route-view.js が無い等)は 1 本のまま */
    function routeOverlapPieces(latlngs) {
        const view = window.KmRouteView;
        if (!view || !window.map || latlngs.length < 2) return [{ latlngs: latlngs, overlap: false }];
        const map = window.map;
        const points = latlngs.map((ll) => {
            const p = map.latLngToContainerPoint(ll);
            return { x: p.x, y: p.y };
        });
        return view.splitRouteByObstacles(points, routeObstacleRects(), ROUTE_OVERLAP_PAD).map((run) => ({
            overlap: run.overlap,
            latlngs: run.points.map((p) => map.containerPointToLatLng([p.x, p.y])),
        }));
    }

    /** いま出している地点の印と札の箱(地図の左上を原点にした画面の座標)。隠した札・大きさの無いものは数えない */
    function routeObstacleRects() {
        const container = window.map.getContainer();
        const origin = container.getBoundingClientRect();
        const rects = [];
        container.querySelectorAll('.leaflet-marker-pane .km-node, .leaflet-tooltip-pane .km-label').forEach((el) => {
            if (el.classList.contains('km-label-hidden')) return;
            const r = el.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) return;
            rects.push({ left: r.left - origin.left, top: r.top - origin.top, right: r.right - origin.left, bottom: r.bottom - origin.top });
        });
        return rects;
    }

    /*
     * 拡大率が変わったら塗り直す。札の並べ直し(kmDeclutterLabels)が先に済むよう、少し待つ(並べ直しが書き終えたときにも塗り直す)。
     * **横へ動かしただけでは塗り直さない**(2026-10-30、振り返りのフェーズ 2)。線と札は同じだけ動くので重なる所は変わらない。
     * それまでは moveend のたびに全部の印と札の箱を測っていた
     */
    let routeOverlapTimer = null;
    function refreshRouteOverlap() {
        if (routeOverlapTimer) window.clearTimeout(routeOverlapTimer);
        routeOverlapTimer = window.setTimeout(() => {
            routeOverlapTimer = null;
            routeLineRuns.forEach(paintRouteRun);
        }, 250);
    }
    window.map.on('zoomend', refreshRouteOverlap);

    function drawPath(pathNodeIds) {
        currentMarkers.forEach(m => window.map.removeLayer(m));
        currentMarkers = [];
        routeLineRuns = [];
        let currentFloorLatLngs = [];
        let pathBoundsGroup = new L.FeatureGroup();
        // この階で経路が始まる所(階を移ったとき、拡大率を保ったままここへ動かす)
        let firstOnFloor = null;

        for (let i = 0; i < pathNodeIds.length; i++) {
            const id = pathNodeIds[i];
            const node = graphData.nodes[id];
            const isStart = (i === 0);
            const isEnd = (i === pathNodeIds.length - 1);

            if (String(node.floor) === String(window.currentFloor)) {
                currentFloorLatLngs.push([node.y, node.x]);
                if (!firstOnFloor) firstOnFloor = [node.y, node.x];

                // 出発・到着ピン、または中間地点の表示
                if (isStart || isEnd) {
                    const pinClass = isStart ? 'pin-start' : 'pin-end';
                    const pinText = isStart ? '出発' : '到着';
                    /*
                     * 大きくする(2026-10-30、高専祭の振り返り「高齢の方が目的地を見失う」)。到着は赤い面に白い字で、
                     * 指す点に脈打つ輪(km-dest-pulse)を付ける。吹き出しの高さが変わったら下の fitBounds の上の余白も直す
                     */
                    const pinIcon = L.divIcon({
                        className: `route-pin ${pinClass}`,
                        html: `<div class="pin-bubble">${pinText}</div><div class="pin-arrow"></div>${isEnd ? '<span class="km-dest-pulse" aria-hidden="true"></span>' : ''}`,
                        iconSize: [96, 56],
                        iconAnchor: [48, 56]
                    });
                    const marker = L.marker([node.y, node.x], {
                        icon: pinIcon,
                        // 札より前の専用の層(上の kmRoutePins)。同じ層の中でも前へ
                        pane: 'kmRoutePins',
                        zIndexOffset: 2000,
                        interactive: false,
                        keyboard: false,
                    }).addTo(window.map);
                    currentMarkers.push(marker);
                    pathBoundsGroup.addLayer(marker);
                } else if (node.type !== 'road') {
                    /*
                     * **名前は必ずエスケープする。** Leaflet のツールチップは文字列を
                     * innerHTML で入れる。ここだけ escapeHtml を通しておらず、地点名に
                     * 仕込んだ HTML が経路を引いた人の画面へ出る状態だった(2026-09-14 の診断)。
                     */
                    const marker = L.circleMarker([node.y, node.x], {
                        radius: 6, color: '#e11d48', fillColor: '#f43f5e', fillOpacity: 1, pane: 'kmRouteLine',
                    }).bindTooltip(escapeHtml(node.alias || node.name)).addTo(window.map);
                    currentMarkers.push(marker);
                    pathBoundsGroup.addLayer(marker);
                }
            } else if (currentFloorLatLngs.length > 0) {
                const polyline = drawRouteLine(currentFloorLatLngs);
                currentMarkers.push(polyline);
                pathBoundsGroup.addLayer(polyline);
                currentFloorLatLngs = [];
            }
        }
        if (currentFloorLatLngs.length > 0) {
            const polyline = drawRouteLine(currentFloorLatLngs);
            currentMarkers.push(polyline);
            pathBoundsGroup.addLayer(polyline);
        }
        /*
         * 寄せるのは**経路を引いた直後の 1 回だけ**(2026-10-23、利用者の指摘「別の階に行くと拡大率がリセットされて面倒」)。
         * 階を移ったあと(案内の帯・階のボタン)は拡大率をそのままにして、その階の経路の始まりへ動かすだけ。
         */
        if (routeFitPending && pathBoundsGroup.getLayers().length > 0) {
            routeFitPending = false;
            /*
             * **パネルと帯を避けて収める**(mapFitPadding は案内の帯も数える)。
             * 以前は四方 50px 固定で、出発・到着のピンが下の操作やシートの裏に潜った。
             */
            const padding = mapFitPadding();
            // 出発・到着の吹き出しは点の**上へ 56px** 伸びる。そのぶん上を空けないと検索の窓の裏に潜る
            padding.paddingTopLeft = padding.paddingTopLeft.add([0, 64]);
            window.map.fitBounds(pathBoundsGroup.getBounds(), padding);
        } else if (firstOnFloor) {
            window.map.setView(firstOnFloor, window.map.getZoom());
        }
    }


    /*
     * ポップアップのボタンを受ける委譲リスナー。
     *
     * ポップアップは**開くたびに作り直される**ので、ボタン1つずつに
     * addEventListener を付ける形にすると付け直しの管理が要る。地図コンテナで
     * まとめて受ければ、いつ作られたものでも拾える。
     */
    /*
     * 2026-10-23 から、公開の地図の詳細は左の引き出し(Main/km-sheet.js)に出る。**同じ口で受ける**
     * (引き出しにもこの関数を付ける。下の「場所の詳細・検索・共有」の節)
     */
    function handleDetailClick(event) {
        const enterButton = event.target.closest('[data-km-enter]');
        if (enterButton) {
            window.kmEnterBuilding(enterButton.dataset.kmEnter, enterButton.dataset.kmNode);
            return;
        }
        // ポスターの画像(地点の詳細)。大きく見る
        const posterButton = event.target.closest('[data-km-poster-view]');
        if (posterButton) {
            openPosterViewer(posterButton.dataset.kmPosterView, posterButton);
            return;
        }
        // ♡(ポスターのいいね)
        const likeButton = event.target.closest('[data-km-poster-like]');
        if (likeButton) {
            togglePosterLike(likeButton);
            return;
        }
        // 共有(場所)
        const shareButton = event.target.closest('[data-km-share]');
        if (shareButton) {
            sharePlace(shareButton);
            return;
        }
        const button = event.target.closest('[data-km-pick]');
        if (!button) return;
        window.setAsLocation(button.dataset.kmNode, button.dataset.kmPick, { poiId: button.dataset.kmPoi });
    }
    document.getElementById('map')?.addEventListener('click', handleDetailClick);

    /**
     * 建物の中(その階)へ移る。**入口の地点まで寄せてから開く** ——
     * 階だけ変えると、キャンパス全体の図のどこを見ればよいのか分からない。
     */
    window.kmEnterBuilding = function (floor, nodeId) {
        if (!floorImages[String(floor)]) return;
        const node = nodeId ? graphData.nodes[nodeId] : null;
        window.map.closePopup();
        changeFloor(String(floor));
        /*
         * **地点が無くても開く。** 建物の階(平面図 1 枚 = 1 つの階)は、中をこれから
         * 作るところでは地点が 0 件 —— そのときは図を出すだけにする。
         */
        if (!node) return;
        /*
         * 階の図は非同期に載る。**載ってから寄せる** —— 先に setView すると
         * 図が載ったときの収め直し(applyFit)で位置が戻ってしまう。
         */
        window.map.once('moveend', () => {
            window.map.setView([node.y, node.x], Math.max(window.map.getZoom(), 1));
            const marker = markersById.get(nodeId);
            window.renderGlobalLabels();
            if (marker && window.map.hasLayer(marker)) marker.openPopup();
        });
    };

    /**
     * 出発地・目的地にする。`opts.poiId` は臨時の地点(一時ノード)の番号 —— そのときの nodeId はつなぎ先。
     * 両方そろえば経路を出す。片方だけなら経路の欄を開いて、もう片方を選んでもらう(2026-10-23)。
     */
    window.setAsLocation = function (nodeId, type, opts) {
        const node = graphData.nodes[nodeId];
        if (!node) return;
        const poiId = opts && opts.poiId !== undefined && opts.poiId !== null && opts.poiId !== '' ? Number(opts.poiId) : null;
        const poi = poiId !== null ? (graphData.eventPois || []).find(p => Number(p.id) === poiId) : null;
        /*
         * **口の側でも弾く。** `window.setAsLocation` は誰でも呼べる形で外に出ている。
         * ただし一時地点のつなぎ先は道・階段のことがある(取り込みが道を先に選ぶ)ので、一時地点のときは弾かない
         * —— ここで弾いていたのが「ボタンが反応しない」の原因だった(2026-10-23)
         */
        if (poi ? String(poi.anchorNodeId) !== String(nodeId) : !isRoutableNode(node)) return;
        const label = poi ? poiSearchLabel(poi) : getLabelForNode(nodeId, node);
        if (type === 'start') {
            routeStartId = nodeId;
            routeStartPoi = poi ? Number(poi.id) : null;
            if (startInput) startInput.value = label;
        } else {
            routeEndId = nodeId;
            routeEndPoi = poi ? Number(poi.id) : null;
            if (endInput) endInput.value = label;
            // 呼ぶ側が出発地をこのあと入れるとき(案内チャットの経路。opts.noTestStart)は入れない(2026-10-25)
            if (!routeStartId && !(opts && opts.noTestStart)) applyTestStart(nodeId);
        }
        window.map.closePopup();
        if (routeStartId && routeEndId) {
            searchBtn.click();
        } else {
            openRoutePanel(type === 'start' ? 'end' : 'start');
        }
    };

    /**
     * テスト用アカウント(展示の PC)は、出発地が空なら展示の場所を入れる(2026-10-25、利用者「テストユーザーのみ、
     * 出発点を Let's Play Densan に」)。地点は index.php の <meta name="km-test-start">(一般の人には出さない)。
     * 目的地と同じ所・地図に無い所・経路に使えない所なら入れない(いつもどおり出発地を選んでもらう)
     */
    function applyTestStart(endId) {
        const meta = document.querySelector('meta[name="km-test-start"]');
        const startId = meta ? meta.getAttribute('content') : '';
        const node = startId ? graphData.nodes[startId] : null;
        if (!node || startId === endId || !isRoutableNode(node)) return;
        routeStartId = startId;
        routeStartPoi = null;
        if (startInput) startInput.value = getLabelForNode(startId, node);
    }

    /**
     * 出発地・目的地を空にする(2026-10-24。案内チャット Main/guide-chat.js が新しい経路を出す前に呼ぶ)。
     * 前の経路の端が残ったまま片方だけ入れると、その古い端で経路を引いてしまう
     */
    window.kmClearRouteEnds = function () {
        routeStartId = null;
        routeStartPoi = null;
        routeEndId = null;
        routeEndPoi = null;
        if (startInput) startInput.value = '';
        if (endInput) endInput.value = '';
    };

    /** 臨時の地点の札(検索の候補・経路の欄と同じ)。名前が番号ならポスターの見出し */
    function poiSearchLabel(poi) {
        const floorLabel = String(poi.floor) === 'outside' ? '外' : `${poi.floor}F`;
        return `[${floorLabel}] ${poiDisplay(poi).name}`;
    }

    /**
     * 階の呼び名。ボタンの文字(外 / 1F …)→ 建物の階の名前(図書館 1F …)→ 「nF」の順に探す。
     */
    function floorLabelFor(floor) {
        const key = String(floor);
        const btn = document.querySelector(`.floor-nav .floor-btn[data-floor="${key}"]`);
        if (btn) return btn.textContent.trim();
        const building = (graphData.buildingFloors || []).find(f => String(f.id) === key);
        if (building && building.label) return building.label;
        const known = (graphData.floors || []).find(f => String(f.id) === key);
        if (known && known.label) return known.label;
        return key === 'outside' ? '外' : `${key}F`;
    }

    /** 目的地の呼び名。一時地点を選んだときは、つなぎ先(道)ではなく一時地点の名前(2026-10-23) */
    function goalLabel(node) {
        if (routeEndPoi !== null) {
            const poi = (graphData.eventPois || []).find(p => Number(p.id) === routeEndPoi);
            if (poi) return poiDisplay(poi).name;
        }
        return routeNodeLabel(node);
    }

    /** 案内の帯に出す地点の呼び名。**名前が無くても何のことか分かる言葉にする。** */
    function routeNodeLabel(node) {
        const name = String((node && node.name) || '').trim();
        if (name) return name;
        if (node && node.type === 'stairs') {
            return Dijkstra.isElevator(node) ? 'エレベーター' : '階段';
        }
        if (node && node.type === 'entrance') return '出入口';
        return 'この地点';
    }

    /**
     * 経路を「目指す所」の列にする(2026-09-25)。
     *
     * 目指す所は**階が変わる所(階段・エレベーター・出入口)と目的地だけ。**
     * 途中の部屋を全部並べていた頃は、案内が画面の半分を埋めていた。
     *
     * 階段で 1F → 2F → 3F と続けて上るときは、**1 つにまとめる**
     * (途中の 2F の踊り場で「位置の更新」を押させない)。
     *
     * @returns {{nodeId: string, floor: string, nextFloor?: string, kind: 'transfer'|'goal'}[]}
     */
    function buildRouteSteps(pathNodeIds) {
        const steps = [];
        for (let i = 0; i < pathNodeIds.length - 1; i++) {
            const node = graphData.nodes[pathNodeIds[i]];
            const next = graphData.nodes[pathNodeIds[i + 1]];
            if (!node || !next || String(next.floor) === String(node.floor)) continue;

            const prev = i > 0 ? graphData.nodes[pathNodeIds[i - 1]] : null;
            const last = steps[steps.length - 1];
            const arrivedByTransfer = prev && String(prev.floor) !== String(node.floor);
            if (last && last.kind === 'transfer' && arrivedByTransfer) {
                // 乗り継いだ先でそのまま次の階へ。行き先の階だけ書き換える
                last.nextFloor = String(next.floor);
                continue;
            }
            steps.push({
                nodeId: pathNodeIds[i],
                floor: String(node.floor),
                nextFloor: String(next.floor),
                kind: 'transfer',
            });
        }
        const goalId = pathNodeIds[pathNodeIds.length - 1];
        if (goalId !== undefined && graphData.nodes[goalId]) {
            steps.push({ nodeId: goalId, floor: String(graphData.nodes[goalId].floor), kind: 'goal' });
        }
        return steps;
    }
    // 検査(src/scripts/js/)から呼ぶ
    window.kmBuildRouteSteps = buildRouteSteps;

    /**
     * 案内の帯を書き換える。**出すのは次に目指す 1 か所だけ。**
     *
     * 帯が出ている間は body に `km-route-active` を付ける —— 左下から出る表示調整や
     * スマホの検索シートは、帯の高さぶん上へ逃げる(styles.css)。
     */
    /*
     * ======== 経路が使う階をいつも見せる ========(2026-10-24、利用者の指示「ルートを設定したときに、使用する階を常時表示」)
     *
     * 案内の帯の上に「使う階 1F → 3F → 1F」のチップを並べる(押すとその階へ。いまの階は塗る)。
     * 右の階の列でも、経路が通る階のボタンに印を付ける(.floor-btn.is-on-route)。
     * 階の並びは Main/route-view.js の routeFloorSequence(続けて同じ階は畳む。戻る階はもう一度出す)。
     */
    /*
     * 左下の操作の帯の高さを測って CSS へ渡す(styles.css の .km-route-dock が --km-action-bar-height の上に置く)。
     * スマホでは操作が 2 行に折り返すので、1 行ぶんの決め打ちでは案内の帯と階の列が操作に重なった(2026-10-24、実測)
     */
    const actionBarEl = document.getElementById('km-action-bar');
    if (actionBarEl && typeof ResizeObserver === 'function') {
        new ResizeObserver(() => {
            document.documentElement.style.setProperty('--km-action-bar-height', `${actionBarEl.offsetHeight}px`);
        }).observe(actionBarEl);
    }

    const routeFloorsEl = document.getElementById('km-route-floors');
    function renderRouteFloors(active) {
        const view = window.KmRouteView;
        const sequence = active && view && currentRoutePath
            ? view.routeFloorSequence(currentRoutePath.map((id) => (graphData.nodes[id] ? graphData.nodes[id].floor : null)))
            : [];
        const onRoute = new Set(sequence);
        document.querySelectorAll('.floor-nav .floor-btn[data-floor]').forEach((btn) => {
            btn.classList.toggle('is-on-route', onRoute.has(btn.dataset.floor));
        });
        const nav = document.getElementById('floor-nav');
        if (nav) nav.classList.toggle('has-route', sequence.length > 0);
        if (!routeFloorsEl) return;
        routeFloorsEl.hidden = sequence.length === 0;
        routeFloorsEl.replaceChildren();
        if (sequence.length === 0) return;
        const head = document.createElement('span');
        head.className = 'km-route-floors-head';
        head.textContent = '使う階';
        routeFloorsEl.appendChild(head);
        sequence.forEach((floor, index) => {
            if (index > 0) {
                const arrow = document.createElement('span');
                arrow.className = 'km-route-floors-arrow';
                arrow.setAttribute('aria-hidden', 'true');
                arrow.textContent = '→';
                routeFloorsEl.appendChild(arrow);
            }
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'km-route-floor' + (String(floor) === String(window.currentFloor) ? ' is-current' : '');
            chip.textContent = floorLabelFor(floor);
            chip.title = `${floorLabelFor(floor)}を見る`;
            if (String(floor) === String(window.currentFloor)) chip.setAttribute('aria-current', 'true');
            chip.addEventListener('click', () => changeFloor(String(floor)));
            routeFloorsEl.appendChild(chip);
        });
    }
    window.kmRenderRouteFloors = () => renderRouteFloors(currentRoutePath !== null && routeSteps.length > 0);

    function renderRouteBar() {
        if (!routeBar) return;
        const active = currentRoutePath !== null && routeSteps.length > 0;
        routeBar.hidden = !active;
        document.body.classList.toggle('km-route-active', active);
        renderRouteFloors(active);
        if (!active) {
            stopPositionPick();
            return;
        }

        // 位置を選んでもらっている間は、帯を「問いかけ」にする(下の togglePositionPick)
        routeBar.classList.toggle('is-picking', pickingPosition);
        if (routeNextBtn) routeNextBtn.textContent = pickingPosition ? 'やめる' : '位置の更新';
        if (pickingPosition) {
            routeBar.classList.remove('is-arrived');
            if (routeNextBtn) routeNextBtn.hidden = false;
            routeText.textContent = '現在見える部屋と合う名前のノードを選択してください';
            routeSub.textContent = '地図の地点か、下の候補を押してください';
            return;
        }

        const arrived = routeStepIndex >= routeSteps.length;
        routeBar.classList.toggle('is-arrived', arrived);
        // 着いたあとも「位置の更新」は残す(違う所に居たと分かったら、そこから引き直せるように)
        if (routeNextBtn) routeNextBtn.hidden = false;

        if (arrived) {
            const goal = graphData.nodes[routeSteps[routeSteps.length - 1].nodeId];
            routeText.textContent = `${goalLabel(goal)}に到着しました`;
            routeSub.textContent = '✕ で案内を終えます';
            return;
        }

        const step = routeSteps[routeStepIndex];
        const node = graphData.nodes[step.nodeId];
        /*
         * **今いる所が乗り継ぎの地点**(「位置の更新」で階段やエレベーターを選んだとき)。
         * 「階段に向かう」では、もう着いている所へ向かえと言うことになる。行き先の階を言う。
         */
        const standingThere = step.kind === 'transfer' && currentRoutePath && step.nodeId === currentRoutePath[0];
        routeText.textContent = standingThere
            ? `${routeNodeLabel(node)}から ${floorLabelFor(step.nextFloor)} へ`
            : `${step.kind === 'goal' ? goalLabel(node) : routeNodeLabel(node)}に向かう`;
        const where = floorLabelFor(step.floor);
        const counter = `${routeStepIndex + 1}/${routeSteps.length}`;
        routeSub.textContent = step.kind === 'goal'
            ? `${counter} · ${where} · 目的地`
            : `${counter} · ${where} · 着いたら ${floorLabelFor(step.nextFloor)} へ`;
    }

    /*
     * ======== 位置の更新 ========(2026-09-25、利用者の指示で作り直した)
     *
     * **Website は現在地を測れない**(アプリのように Wi-Fi で追えない)。そこで利用者に聞く ——
     * 「現在見える部屋と合う名前のノードを選択してください」。
     *
     * 選ばれた地点を**新しい出発地にして、目的地まで引き直す**。案内の帯は、そこから次に目指す所に変わる。
     * 目的地そのものが選ばれたら「到着しました」にする。
     *
     * 選び方は2つ:
     *   - 地図の地点を押す(createNodeMarker の click が window.kmRoutePicking へ渡す)
     *   - 帯の上に出す候補を押す(いま見えている範囲の、選べる地点。目指している所に近い順)
     * 見えている階に居ないときのために、経路が通る階へ切り替えるボタンも並べる。
     */
    const routePick = document.getElementById('km-route-pick');
    const KM_ROUTE_PICK_LIMIT = 8;

    function togglePositionPick() {
        if (pickingPosition) {
            stopPositionPick();
            renderRouteBar();
            return;
        }
        if (currentRoutePath === null) return;
        pickingPosition = true;
        window.kmRoutePicking = pickPosition;
        renderRouteBar();
        renderPickCandidates();
    }

    function stopPositionPick() {
        pickingPosition = false;
        window.kmRoutePicking = null;
        if (routePick) {
            routePick.hidden = true;
            routePick.innerHTML = '';
        }
        if (routeBar) routeBar.classList.remove('is-picking');
    }

    /** 候補の地点。**いま見えている範囲**の選べる地点を、目指している所に近い順に。 */
    function pickCandidates() {
        const floor = String(window.currentFloor);
        const bounds = window.map.getBounds().pad(0.1);
        const step = routeSteps[Math.min(routeStepIndex, routeSteps.length - 1)];
        const target = step ? graphData.nodes[step.nodeId] : null;
        const center = target && String(target.floor) === floor
            ? { x: target.x, y: target.y }
            : { x: window.map.getCenter().lng, y: window.map.getCenter().lat };

        return (nodesByFloor.get(floor) || [])
            .filter(([, node]) => isRoutableNode(node) && bounds.contains([node.y, node.x]))
            .map(([id, node]) => ({ id, node, d: Math.hypot(node.x - center.x, node.y - center.y) }))
            .sort((a, b) => a.d - b.d)
            .slice(0, KM_ROUTE_PICK_LIMIT);
    }

    function renderPickCandidates() {
        if (!routePick || !pickingPosition) return;
        routePick.innerHTML = '';
        routePick.hidden = false;

        const list = pickCandidates();
        const chips = document.createElement('div');
        chips.className = 'km-route-pick-list';
        if (list.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'km-route-pick-empty';
            empty.textContent = 'この範囲に選べる地点がありません。地図を動かすか、階を切り替えてください。';
            chips.appendChild(empty);
        }
        list.forEach(({ id, node }) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'km-route-pick-item';
            // 名前は DB の値。textContent で入れる(エスケープ不要)
            button.textContent = routeNodeLabel(node);
            button.addEventListener('click', () => pickPosition(id));
            chips.appendChild(button);
        });
        routePick.appendChild(chips);

        // 経路が通る階のうち、いま見ていない階へ。上の階へ上がった直後などに使う
        const floors = [];
        (currentRoutePath || []).forEach((nodeId) => {
            const f = String(graphData.nodes[nodeId].floor);
            if (f !== String(window.currentFloor) && floors.indexOf(f) < 0 && floorImages[f]) floors.push(f);
        });
        if (floors.length > 0) {
            const row = document.createElement('div');
            row.className = 'km-route-pick-floors';
            const label = document.createElement('span');
            label.textContent = '別の階にいるとき:';
            row.appendChild(label);
            floors.forEach((f) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'km-route-pick-floor';
                button.textContent = floorLabelFor(f);
                button.addEventListener('click', () => changeFloor(f));
                row.appendChild(button);
            });
            routePick.appendChild(row);
        }
    }

    /**
     * 地点が選ばれた。**選べない地点なら true を返さない**(地図の側では、ふつうに詳細が開く)。
     * @returns {boolean} 受け取ったか
     */
    function pickPosition(nodeId) {
        if (!pickingPosition) return false;
        const node = graphData.nodes[nodeId];
        if (!isRoutableNode(node)) return false;

        stopPositionPick();
        const goalId = currentRoutePath[currentRoutePath.length - 1];
        if (nodeId === goalId) {
            // 目的地に居る。引き直すものが無い
            routeStepIndex = routeSteps.length;
            renderRouteBar();
            return true;
        }
        // 出発地を置き換えて、目的地まで引き直す(案内開始と同じ道を通す)
        routeStartId = nodeId;
        routeStartPoi = null;
        if (startInput) startInput.value = getLabelForNode(nodeId, node);
        searchBtn.click();
        return true;
    }

    // 地図を動かしたり階を変えたりしたら、候補を取り直す(見えている範囲が変わるので)
    window.map.on('moveend', () => {
        if (pickingPosition) renderPickCandidates();
    });
    /** 帯の行き先を押したら、その地点を地図の真ん中に出す(別の階ならその階を開いてから)。 */
    function focusRouteTarget() {
        if (routeSteps.length === 0) return;
        const step = routeSteps[Math.min(routeStepIndex, routeSteps.length - 1)];
        const node = graphData.nodes[step.nodeId];
        if (!node) return;
        let centered = false;
        const center = () => {
            if (centered) return;
            centered = true;
            window.map.setView([node.y, node.x], Math.max(window.map.getZoom(), 1));
        };
        if (String(step.floor) !== String(window.currentFloor) && floorImages[step.floor]) {
            // 図が載ってから寄せる。先に寄せると、載ったときの動きで戻される。
            // 拡大率を保つようにしたので(2026-10-23)動かないこともある —— 保険の時間差も置く
            window.map.once('moveend', center);
            window.setTimeout(center, 700);
            changeFloor(step.floor);
            return;
        }
        center();
    }

    /*
     * ======== 場所の詳細・検索・共有(Google マップ風。2026-10-23、利用者の指示) ========
     *
     * 流れは「場所を検索(一致率順)→ 左の引き出しに場所の詳細 → 経路」。先生に「完成形は Google マップだね」と言われたので、
     * 動きを参考にした(見た目は写さない)。
     *
     * - 地点の詳細は地図の吹き出しではなく、左から出る引き出し(Main/km-sheet.js)に出す。押した点には輪を付ける
     * - 上の検索の窓・経路の欄の出発地と目的地は、どれも一致率の順の候補(Main/search-rank.js)。候補のアイコンはその場所のポスター
     * - 「共有」は場所(?room= / ?poi=)と経路(?from=&to=)の URL。navigator.share が無ければリンクを写す
     */
    const sheet = window.KmSheet || null;
    window.map.createPane('kmSelect').style.zIndex = '640';
    let selectionRing = null;

    /*
     * 選んだ場所には大きな赤いピンと脈打つ輪(2026-10-30、高齢の方の声「どこを選んだか分からない」)。
     * それまでは青い輪(半径 15px)で、地点の印と見分けにくかった。ピンの先が場所を指す(iconAnchor は下の真ん中)
     */
    function markSelected(latlng) {
        clearSelected();
        selectionRing = L.marker(latlng, {
            pane: 'kmSelect',
            interactive: false,
            keyboard: false,
            icon: L.divIcon({
                className: 'km-dest-pin',
                html: '<span class="km-dest-pulse" aria-hidden="true"></span><span class="km-dest-head" aria-hidden="true"></span>',
                iconSize: [44, 52],
                iconAnchor: [22, 48],
            }),
        }).addTo(window.map);
        // 選んだ場所の札は隠さない(declutterLabels の keep)。重なりで隠れていたら出す
        scheduleDeclutter(true);
    }
    function clearSelected() {
        if (!selectionRing) return;
        window.map.removeLayer(selectionRing);
        selectionRing = null;
        scheduleDeclutter(true);
    }

    /** 引き出しが隠している所を避けて、その点が見えるように動かす(拡大率は変えない) */
    function revealLatLng(latlng) {
        /*
         * mapFitPadding は 1 辺を画面の 40% で頭打ちにする(寄せの計算が壊れないように)。スマホの引き出しは 50% を超えるので、
         * ここでは引き出しの大きさそのもので「見えている所」を出し、点がその外ならその真ん中へ動かす
         */
        const sheetEl = document.getElementById('km-sheet');
        if (!sheetEl || !sheetEl.classList.contains('is-open')) return;
        const point = window.map.latLngToContainerPoint(latlng);
        const size = window.map.getSize();
        const top = mapFitPadding().paddingTopLeft.y;
        let dx = 0;
        let dy = 0;
        if (size.x > 768) {
            const left = sheetEl.offsetWidth + PANEL_GAP;
            if (point.x < left + 24 || point.x > size.x - 24) dx = point.x - (left + size.x) / 2;
        } else {
            const bottom = size.y - sheetEl.offsetHeight - PANEL_GAP;
            if (point.y > bottom - 24 || point.y < top) dy = point.y - (top + bottom) / 2;
        }
        if (dx !== 0 || dy !== 0) window.map.panBy([dx, dy]);
    }

    function floorText(floor) {
        return String(floor) === 'outside' ? '外' : floorLabelFor(floor);
    }

    /**
     * 場所の詳細を左の引き出しに出す。target は { nodeId } か { poiId }。
     * 引き出しが無いページ(地図編集)では false を返す(呼ぶ側が今までの吹き出しを使う)
     */
    function openPlaceSheet(target) {
        if (!sheet) return false;
        let html;
        let title;
        let latlng;
        if (target.poiId !== undefined && target.poiId !== null) {
            const poi = (graphData.eventPois || []).find(p => Number(p.id) === Number(target.poiId));
            if (!poi) return false;
            const shown = poiDisplay(poi);
            html = poiDetailHtml(poi, shown);
            title = shown.name;
            latlng = [poi.y, poi.x];
            testLog('detail', { node: poi.anchorNodeId || '', text: 'poi:' + poi.id, floor: String(poi.floor) });
        } else {
            const id = target.nodeId;
            const node = graphData.nodes[id];
            if (!node) return false;
            const project = projectDisplayFor(id, node);
            html = nodeDetailHtml(id, node, String(node.type || 'room'));
            title = project ? project.name : displayNameFor(node);
            latlng = [node.y, node.x];
            testLog('detail', { node: id, floor: String(window.currentFloor) });
        }
        const content = document.createElement('div');
        content.className = 'km-place';
        // 中身は nodeDetailHtml / poiDetailHtml(DB の値はそちらでエスケープ済み)
        content.innerHTML = html;
        sheet.open({ key: 'place', title: title, content: content, onClose: clearSelected, focus: false });
        markSelected(latlng);
        // ポスターの ♡ の数と、この端末が押したか
        const likeIds = Array.from(content.querySelectorAll('[data-km-poster-like]')).map(b => Number(b.dataset.kmPosterLike));
        likeIds.forEach(posterId => {
            const poster = postersById.get(posterId);
            if (poster && !posterLikeState.has(posterId)) posterLikeState.set(posterId, { count: Number(poster.likes) || 0, liked: false });
        });
        paintLikes();
        refreshPosterLikes(likeIds);
        // 場所を開いたら経路の欄は畳む(どちらも左に出る。経路は詳細の「経路」から開き直せる)
        if (searchPanel && !searchPanel.classList.contains('collapsed')) setSearchPanelCollapsed(true, false);
        // 引き出しが出きって(0.3 秒)、寄せの動きも終わってから、隠れた所を避けて動かす
        window.setTimeout(() => revealLatLng(latlng), 550);
        if (target.nodeId) window.dispatchEvent(new CustomEvent('km:node-opened', { detail: { id: target.nodeId } }));
        return true;
    }
    window.kmOpenPlace = openPlaceSheet;

    /**
     * その場所へ移って詳細を開く(検索・一覧・予定・ポスターから)。階が違えばその階へ移ってから。
     * 拡大は「部屋が見えるところ」まで(小さいときだけ上げる)
     */
    function goToPlace(target) {
        let floor;
        let latlng;
        if (target.poiId !== undefined && target.poiId !== null) {
            const poi = (graphData.eventPois || []).find(p => Number(p.id) === Number(target.poiId));
            if (!poi) return;
            floor = String(poi.floor);
            latlng = [poi.y, poi.x];
        } else {
            const node = graphData.nodes[target.nodeId];
            if (!node) return;
            floor = String(node.floor);
            latlng = [node.y, node.x];
            testLog('pick', { node: target.nodeId });
        }
        let done = false;
        const go = () => {
            if (done) return;
            done = true;
            userMovedMap = true;   // 階の図が載ったときの収め直しで戻さない
            window.map.setView(latlng, Math.max(window.map.getZoom(), 2));
            window.renderGlobalLabels();
            if (!openPlaceSheet(target)) {
                // 引き出しの無いページ: 今までどおり点の吹き出し
                const marker = target.poiId != null ? poiMarkers.get(Number(target.poiId)) : markersById.get(target.nodeId);
                if (marker && window.map.hasLayer(marker)) marker.openPopup();
            }
        };
        if (floor !== String(window.currentFloor) && floorImages[floor]) {
            window.map.once('moveend', go);
            window.setTimeout(go, 700);
            changeFloor(floor);
        } else {
            go();
        }
    }

    // 臨時の地点を開く(Main/event-schedule.js の「地図で見る」・ポスターの「地図で見る」)
    window.kmOpenPoi = function (id) { goToPlace({ poiId: Number(id) }); };
    // 履歴とブックマークの一覧から開く(Main/user-history.js)
    window.kmOpenNode = function (id) { if (graphData.nodes[id]) goToPlace({ nodeId: id }); };
    /** 一覧から選んだ地点へ寄せて、詳細を開く(カテゴリーの結果)。 */
    function openNodeFromList(node) {
        goToPlace({ nodeId: node.id });
    }

    /* ---------------- 検索の候補(一致率順) ---------------- */

    const rankApi = window.KmSearchRank || null;
    let searchItems = null;

    /** 候補にするもの。地点(人が行く所)・臨時の地点。名前のほかに別名(ポスターの見出し・部屋番号・分類・企画の番号)も引く */
    function buildSearchItems() {
        const items = [];
        for (const [id, node] of Object.entries(graphData.nodes)) {
            if (node.hidden) continue;
            if (node.type === 'road' || node.type === 'wall' || node.type === 'wifi_router' || node.type === 'area') continue;
            if (!String(node.name || '').trim()) continue;
            const project = projectDisplayFor(id, node);
            const posters = postersByNode.get(id) || [];
            items.push({
                kind: 'node',
                id: id,
                floor: String(node.floor),
                name: project ? project.name : (node.alias || node.name),
                extras: [node.name, node.alias, node.title, node.subtitle, node.type2, mapStyle.LABELS[node.type]]
                    .concat(node.projects || [], posters.map(p => p.title))
                    .filter(Boolean),
                type: String(node.type || 'room'),
                routable: isRoutableNode(node),
                poster: posters[0] || null,
            });
        }
        (graphData.eventPois || []).forEach(poi => {
            const shown = poiDisplay(poi);
            const posters = postersByNode.get('poi:' + poi.id) || [];
            items.push({
                kind: 'poi',
                poiId: Number(poi.id),
                id: poi.anchorNodeId || '',
                floor: String(poi.floor),
                name: shown.name,
                extras: [poi.name, KM_POI_LABELS[poi.category] || '', poi.note || ''].concat(posters.map(p => p.title)).filter(Boolean),
                type: poiNodeType(poi),
                routable: Boolean(poi.anchorNodeId && graphData.nodes[poi.anchorNodeId]),
                poster: posters[0] || null,
            });
        });
        return items;
    }

    function searchCandidates(query, onlyRoutable) {
        if (!rankApi) return [];
        if (!searchItems) searchItems = buildSearchItems();
        const pool = onlyRoutable ? searchItems.filter(item => item.routable) : searchItems;
        return rankApi.rank(pool, query, { floor: String(window.currentFloor), limit: 8 });
    }

    /** 候補のアイコン。ポスターがあればその画像、無ければ種類の色と形(地図の点と同じ) */
    function candidateIcon(item) {
        const box = document.createElement('span');
        box.className = 'km-result-icon';
        if (item.poster && item.poster.imageUrl) {
            const img = document.createElement('img');
            // 小さなアイコンなので縮小版(2026-10-30。lib/image-thumb.php)
            img.src = item.poster.thumbUrl || item.poster.imageUrl;
            img.alt = '';
            img.loading = 'lazy';
            img.decoding = 'async';
            box.classList.add('has-poster');
            box.appendChild(img);
        } else {
            // nodeIconHtml は地図の点と同じ SVG(色と形は表示調整の種類の設定)。名前は入らない
            box.innerHTML = nodeIconHtml({ name: item.name }, item.type);
        }
        return box;
    }

    /**
     * 入力欄に一致率順の候補を付ける。選ぶと onPick(item)。↑↓ で選び Enter で決める。Esc で閉じる
     * @param {HTMLInputElement} input
     * @param {HTMLElement} list ul(role=listbox)
     * @param {(item: object) => void} onPick
     * @param {boolean} onlyRoutable 出発地・目的地の欄は、案内できる所だけ
     */
    function attachSearch(input, list, onPick, onlyRoutable) {
        if (!input || !list) return;
        let items = [];
        let active = -1;

        function close() {
            list.hidden = true;
            list.innerHTML = '';
            items = [];
            active = -1;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }
        function highlight(index) {
            active = index;
            Array.from(list.children).forEach((li, i) => li.setAttribute('aria-selected', String(i === index)));
            const current = list.children[index];
            if (current) {
                input.setAttribute('aria-activedescendant', current.id);
                current.scrollIntoView({ block: 'nearest' });
            }
        }
        function pick(index) {
            const item = items[index];
            if (!item) return;
            close();
            onPick(item);
        }
        function render() {
            const query = input.value.trim();
            items = query === '' ? [] : searchCandidates(query, onlyRoutable);
            list.innerHTML = '';
            if (query === '') { close(); return; }
            if (items.length === 0) {
                const empty = document.createElement('li');
                empty.className = 'km-result-empty';
                empty.textContent = '見つかりませんでした。別の言葉で探すか、下の種類のボタンを使ってください。';
                list.appendChild(empty);
            }
            items.forEach((item, index) => {
                const li = document.createElement('li');
                li.className = 'km-result';
                li.id = list.id + '-' + index;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', 'false');
                const text = document.createElement('span');
                text.className = 'km-result-text';
                const name = document.createElement('b');
                name.className = 'km-result-name';
                name.textContent = item.name;   // DB の値。textContent で入れる
                const sub = document.createElement('small');
                sub.className = 'km-result-sub';
                sub.textContent = [floorText(item.floor), item.kind === 'poi' ? '臨時' : (mapStyle.LABELS[item.type] || '')].filter(Boolean).join(' · ');
                text.append(name, sub);
                li.append(candidateIcon(item), text);
                // 押したとき入力欄からフォーカスが外れて閉じる前に決める
                li.addEventListener('mousedown', (event) => { event.preventDefault(); pick(index); });
                list.appendChild(li);
            });
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            active = -1;
        }

        input.addEventListener('input', render);
        input.addEventListener('focus', () => { if (input.value.trim() !== '') render(); });
        input.addEventListener('blur', () => window.setTimeout(close, 150));
        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' && items.length > 0) {
                event.preventDefault();
                highlight(Math.min(active + 1, items.length - 1));
            } else if (event.key === 'ArrowUp' && items.length > 0) {
                event.preventDefault();
                highlight(Math.max(active - 1, 0));
            } else if (event.key === 'Enter') {
                if (items.length > 0) {
                    event.preventDefault();
                    pick(active >= 0 ? active : 0);
                }
            } else if (event.key === 'Escape') {
                close();
            }
        });
    }

    // 上の検索の窓: 選ぶとその場所へ
    const placeInput = document.getElementById('km-place-input');
    const placeClear = document.getElementById('km-place-clear');
    attachSearch(placeInput, document.getElementById('km-place-results'), (item) => {
        placeInput.value = item.name;
        if (placeClear) placeClear.hidden = false;
        testLog('search', { field: 'place', text: item.name, node: item.kind === 'node' ? item.id : '' });
        // 履歴の「検索した言葉」(Main/user-history.js)
        window.dispatchEvent(new CustomEvent('km:search', { detail: { text: item.name } }));
        goToPlace(item.kind === 'poi' ? { poiId: item.poiId } : { nodeId: item.id });
        placeInput.dispatchEvent(new CustomEvent('km-place-chosen'));
        placeInput.blur();
    }, false);
    if (placeInput && placeClear) {
        placeInput.addEventListener('input', () => { placeClear.hidden = placeInput.value === ''; });
        placeClear.addEventListener('click', () => {
            placeInput.value = '';
            placeClear.hidden = true;
            placeInput.focus();
            if (sheet) sheet.close('place');
        });
    }
    // 経路の欄の出発地・目的地: 選ぶと出発地・目的地にする(両方そろえば経路を出す)
    [[startInput, 'km-start-results', 'start'], [endInput, 'km-end-results', 'end']].forEach(([input, listId, field]) => {
        attachSearch(input, document.getElementById(listId), (item) => {
            window.setAsLocation(item.id, field, item.kind === 'poi' ? { poiId: item.poiId } : undefined);
            // 操作の案内(Main/tutorial.js)と記録は change を見ている
            input.dispatchEvent(new Event('change'));
        }, true);
    });
    // 「経路」(検索の窓の 🧭)。場所を開いていればそこを目的地に
    const routeOpenBtn = document.getElementById('km-route-open');
    if (routeOpenBtn) {
        routeOpenBtn.addEventListener('click', () => {
            const collapsed = !searchPanel || searchPanel.classList.contains('collapsed');
            if (!collapsed) { setSearchPanelCollapsed(true); return; }
            openRoutePanel(routeEndId ? 'start' : 'end');
        });
    }
    // ⇅ 入れ替え
    const swapBtn = document.getElementById('km-route-swap');
    if (swapBtn && startInput && endInput) {
        swapBtn.addEventListener('click', () => {
            [startInput.value, endInput.value] = [endInput.value, startInput.value];
            [routeStartId, routeEndId] = [routeEndId, routeStartId];
            [routeStartPoi, routeEndPoi] = [routeEndPoi, routeStartPoi];
            if ((routeStartId || startInput.value) && (routeEndId || endInput.value)) searchBtn.click();
        });
    }

    /* ---------------- 種類でまとめて探す(チップ) ---------------- */

    const CATEGORY_PATTERNS = {
        WC: /WC|トイレ|便所|お手洗い/i,
        stairs: /階段|エレベーター|EV/i,
        room: /室|教室|実験|講義|ラボ/i,
        facility: /棟|館|ホール|センター/i,
    };
    document.querySelectorAll('[data-km-category]').forEach((chip) => {
        chip.addEventListener('click', () => {
            const type = chip.dataset.kmCategory;
            const title = chip.textContent.trim();
            testLog('category', { text: title });
            const results = [];
            if (type === 'event') {
                (graphData.eventPois || []).forEach(poi => results.push({ poiId: Number(poi.id), name: poiDisplay(poi).name, floor: String(poi.floor), type: 'room', poster: (postersByNode.get('poi:' + poi.id) || [])[0] || null }));
                for (const [id, node] of Object.entries(graphData.nodes)) {
                    if (node.hidden || !Array.isArray(node.projects) || node.projects.length === 0) continue;
                    const project = projectDisplayFor(id, node);
                    results.push({ id: id, name: project ? project.name : displayNameFor(node), floor: String(node.floor), type: String(node.type), poster: (postersByNode.get(id) || [])[0] || null });
                }
            } else {
                const pattern = CATEGORY_PATTERNS[type];
                for (const [id, node] of Object.entries(graphData.nodes)) {
                    // 人が行ける場所だけ。通路の点・壁・Wi-Fi ルーター・イベント中に出さない地点は出さない
                    if (node.type === 'road' || node.type === 'wall' || node.type === 'wifi_router' || node.hidden) continue;
                    const shownName = node.alias || node.name;
                    if (node.type === type || (pattern && (pattern.test(node.name) || pattern.test(shownName)))) {
                        results.push({ id: id, name: shownName, floor: String(node.floor), type: String(node.type), poster: (postersByNode.get(id) || [])[0] || null });
                    }
                }
            }
            // いまの階を先に、そのあと階の順・名前の順
            const current = String(window.currentFloor);
            results.sort((a, b) => (a.floor === current ? 0 : 1) - (b.floor === current ? 0 : 1)
                || String(a.floor).localeCompare(String(b.floor), 'ja', { numeric: true })
                || String(a.name).localeCompare(String(b.name), 'ja'));
            showCategoryResults(title, results);
        });
    });

    /** 種類でまとめた結果を、左の引き出しに並べる */
    function showCategoryResults(title, results) {
        if (!sheet) return;
        const box = document.createElement('div');
        box.className = 'km-category-sheet';
        const count = document.createElement('p');
        count.className = 'km-category-count';
        count.textContent = results.length === 0 ? '見つかりませんでした。' : `${results.length} 件`;
        box.appendChild(count);
        const list = document.createElement('ul');
        list.className = 'km-category-list';
        results.forEach(item => {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'km-result km-category-item';
            const text = document.createElement('span');
            text.className = 'km-result-text';
            const name = document.createElement('b');
            name.className = 'km-result-name';
            name.textContent = item.name;
            const sub = document.createElement('small');
            sub.className = 'km-result-sub';
            sub.textContent = floorText(item.floor);
            text.append(name, sub);
            button.append(candidateIcon(item), text);
            // 選んだら、その場所の詳細へ(「←」でこの一覧へ戻れる)
            button.addEventListener('click', () => {
                const target = item.poiId !== undefined ? { poiId: item.poiId } : { nodeId: item.id };
                goToPlace(target);
            });
            li.appendChild(button);
            list.appendChild(li);
        });
        box.appendChild(list);
        sheet.open({ key: 'category', title: title, content: box });
    }

    /* ---------------- 共有 ---------------- */

    function toast(text) {
        const note = document.createElement('div');
        note.className = 'km-room-notice km-toast';
        note.setAttribute('role', 'status');
        note.textContent = text;
        document.body.append(note);
        window.setTimeout(() => note.remove(), 3500);
    }

    /** URL を共有する。共有の画面があればそれ、無ければクリップボードへ写す */
    async function shareUrl(path, title) {
        const url = new URL(path, window.location.origin).toString();
        testLog('share', { text: path });
        if (navigator.share) {
            try {
                await navigator.share({ title: title, text: title, url: url });
                return;
            } catch (error) {
                if (error && error.name === 'AbortError') return;   // 自分で閉じた
            }
        }
        try {
            await navigator.clipboard.writeText(url);
            toast('リンクを写しました');
        } catch (error) {
            // 写せない(古いブラウザ・権限)。リンクを見せて手で写してもらう
            window.prompt('このリンクを写してください', url);
        }
    }

    function sharePlace(button) {
        if (button.dataset.kmPoi) {
            const poi = (graphData.eventPois || []).find(p => Number(p.id) === Number(button.dataset.kmPoi));
            shareUrl('/?poi=' + Number(button.dataset.kmPoi), poi ? poiDisplay(poi).name : '高専マップ案内');
            return;
        }
        const id = button.dataset.kmNode;
        const node = graphData.nodes[id];
        shareUrl('/?room=' + encodeURIComponent(id), node ? displayNameFor(node) : '高専マップ案内');
    }

    function shareRoute() {
        if (!currentRoutePath || currentRoutePath.length < 2) return;
        const from = currentRoutePath[0];
        const to = currentRoutePath[currentRoutePath.length - 1];
        const label = `${startInput && startInput.value ? startInput.value : routeNodeLabel(graphData.nodes[from])} → ${endInput && endInput.value ? endInput.value : routeNodeLabel(graphData.nodes[to])}`;
        // 一時地点はその番号で渡す(?fromPoi= / ?toPoi=)。開いた人の欄に、つなぎ先の道ではなく一時地点の名前が出る
        const fromPart = routeStartPoi !== null && routeStartId === from ? 'fromPoi=' + routeStartPoi : 'from=' + encodeURIComponent(from);
        const toPart = routeEndPoi !== null && routeEndId === to ? 'toPoi=' + routeEndPoi : 'to=' + encodeURIComponent(to);
        // 経路の選択も載せる(2026-10-29)。載せないと、開いた人の端末の設定で別の経路が引かれる(階段を避けたのに階段の経路が出る)
        const optionParts = [
            verticalPreference === 'elevator' || verticalPreference === 'stairs' ? 'vp=' + verticalPreference : '',
            routeOptions.fewerFloors ? 'ff=1' : '',
            routeOptions.rain ? 'rain=1' : '',
        ].filter(Boolean);
        shareUrl('/?' + [fromPart, toPart].concat(optionParts).join('&'), label + '(高専マップ案内)');
    }
    const routeShareBtn = document.getElementById('km-route-share');
    if (routeShareBtn) routeShareBtn.addEventListener('click', shareRoute);

    /* ---------------- ポスター(拡大・♡・地図で見る) ---------------- */

    /** ポスターを大きく見る。左の引き出しに出す(場所の詳細から開けば「←」で戻れる) */
    function openPosterViewer(posterId) {
        const poster = postersById.get(Number(posterId));
        if (!poster || !sheet) return;
        const box = document.createElement('div');
        box.className = 'km-poster-sheet';
        const img = document.createElement('img');
        img.className = 'km-poster-sheet-image';
        img.src = poster.imageUrl;
        img.alt = poster.title;
        box.appendChild(img);
        const actions = document.createElement('div');
        actions.className = 'km-poster-sheet-actions';
        actions.appendChild(posterLikeButton(poster));
        if (poster.linkUrl && /^https?:\/\//i.test(poster.linkUrl)) {
            const link = document.createElement('a');
            link.className = 'secondary-btn km-poster-link';
            link.href = poster.linkUrl;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = '🌐 外のサイトで見る ↗';
            // 押したら数える(Main/poster-stats.js。同じ端末は 1 人)
            link.setAttribute('data-km-poster-link', String(Number(poster.id)));
            actions.appendChild(link);
        }
        box.appendChild(actions);
        if (poster.description) {
            const desc = document.createElement('p');
            desc.className = 'km-poster-sheet-desc';
            desc.textContent = poster.description;
            box.appendChild(desc);
        }
        // 結びつけた場所(企画の番号の地点・一時地点)へ(2026-10-23、利用者の指示)
        const places = posterPlaces(poster);
        if (places.length > 0) {
            const head = document.createElement('p');
            head.className = 'km-poster-sheet-head';
            head.textContent = poster.projectNo ? `企画 ${poster.projectNo} の場所` : '場所';
            box.appendChild(head);
            places.forEach(place => {
                const go = document.createElement('button');
                go.type = 'button';
                go.className = 'secondary-btn km-poster-go';
                go.textContent = `📍 ${place.label}(${floorText(place.floor)})`;
                go.addEventListener('click', () => goToPlace(place.target));
                box.appendChild(go);
            });
        }
        sheet.open({ key: 'poster', title: poster.title, content: box, push: sheet.isOpen() });
        refreshPosterLikes([poster.id]);
    };

    /** ポスターを結びつけた場所(地図に出している地点と一時地点) */
    function posterPlaces(poster) {
        const places = [];
        const nodeIds = Array.isArray(poster.nodeIds) && poster.nodeIds.length > 0 ? poster.nodeIds : (poster.nodeId ? [poster.nodeId] : []);
        nodeIds.forEach(id => {
            const node = graphData.nodes[id];
            if (node && !node.hidden) places.push({ target: { nodeId: id }, label: node.alias || node.name, floor: node.floor });
        });
        (poster.poiIds || []).forEach(poiId => {
            const poi = (graphData.eventPois || []).find(p => Number(p.id) === Number(poiId));
            if (poi) places.push({ target: { poiId: Number(poi.id) }, label: String(poi.name || ''), floor: poi.floor });
        });
        return places;
    }

    /*
     * ♡(ポスターのいいね。2026-10-23、利用者の指示)。数は地図のデータの poster.likes、押したかは api/poster-like.php の GET。
     * 端末の印はこの端末のでたらめな値(localStorage)。サーバーは鍵つきの印にしてだけ持つ(lib/poster-likes.php)
     */
    const posterLikeState = new Map();   // id → { count, liked }
    // 端末の印は**♡ を押すときだけ作る**(プライバシーポリシーのとおり。見るだけの人の端末には何も残さない)
    function deviceId(create) {
        try {
            let id = window.localStorage.getItem('km-device-id');
            if (!/^[0-9a-f]{32}$/.test(id || '')) {
                if (!create) return null;
                const bytes = new Uint8Array(16);
                window.crypto.getRandomValues(bytes);
                id = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
                window.localStorage.setItem('km-device-id', id);
            }
            return id;
        } catch (e) {
            return null;
        }
    }
    function csrfToken() {
        const meta = document.querySelector('meta[name="km-csrf"]');
        return meta ? meta.getAttribute('content') : '';
    }
    function posterLikeButton(poster) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'secondary-btn km-like-btn';
        button.dataset.kmPosterLike = String(Number(poster.id));
        if (!posterLikeState.has(Number(poster.id))) posterLikeState.set(Number(poster.id), { count: Number(poster.likes) || 0, liked: false });
        paintLike(button);
        return button;
    }
    function paintLike(button) {
        const state = posterLikeState.get(Number(button.dataset.kmPosterLike)) || { count: 0, liked: false };
        button.textContent = `${state.liked ? '♥' : '♡'} ${state.count}`;
        button.classList.toggle('is-liked', state.liked);
        button.setAttribute('aria-pressed', String(state.liked));
        button.setAttribute('aria-label', state.liked ? `いいねを外す(${state.count})` : `いいね(${state.count})`);
    }
    function paintLikes() {
        document.querySelectorAll('[data-km-poster-like]').forEach(paintLike);
    }
    async function refreshPosterLikes(ids) {
        if (ids.length === 0) return;
        const device = deviceId(false);
        try {
            // 端末の印は URL に載せない(アクセスの記録に残る)。見出しで送る
            const response = await fetch('/api/poster-like.php?ids=' + ids.map(Number).join(','), { credentials: 'same-origin', headers: device ? { 'X-KM-Device': device } : {} });
            if (!response.ok) return;
            const data = await response.json();
            (data.likes || []).forEach(entry => posterLikeState.set(Number(entry.id), { count: Number(entry.count) || 0, liked: Boolean(entry.liked) }));
            paintLikes();
        } catch (e) {
            // 数が読めなくても見る分には困らない
        }
    }
    async function togglePosterLike(button) {
        const id = Number(button.dataset.kmPosterLike);
        const device = deviceId(true);
        if (!device) { toast('この端末では ♡ を覚えられません'); return; }
        const before = posterLikeState.get(id) || { count: 0, liked: false };
        const like = !before.liked;
        // 先に見た目を変える(押した手応え)。断られたら戻す
        posterLikeState.set(id, { count: Math.max(0, before.count + (like ? 1 : -1)), liked: like });
        paintLikes();
        try {
            const response = await fetch('/api/poster-like.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-KM-CSRF': csrfToken() },
                body: JSON.stringify({ poster: id, device: device, like: like }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) throw new Error(data.message || 'failed');
            posterLikeState.set(id, { count: Number(data.count) || 0, liked: Boolean(data.liked) });
            testLog('like', { text: 'poster:' + id + (like ? ':on' : ':off') });
        } catch (error) {
            posterLikeState.set(id, before);
            toast(error && error.message && error.message !== 'failed' ? error.message : '♡ を送れませんでした。時間をおいてもう一度試してください');
        }
        paintLikes();
    }

    /* ---------------- 引き出しの中のボタン(地図の吹き出しと同じ口) ---------------- */

    if (sheet) {
        sheet.element().addEventListener('click', handleDetailClick);
        // 地図の寄せの余白に引き出しを数え直す
        window.addEventListener('km:sheet', (event) => {
            document.body.classList.toggle('km-place-open', Boolean(event.detail && event.detail.open));
            if (!event.detail || !event.detail.open) clearSelected();
        });
    }

    /* ---------------- 共有のリンクから開いたとき ---------------- */

    let focusShareDone = false;
    function focusFromUrl() {
        if (focusShareDone) return;
        focusShareDone = true;
        const read = (name) => {
            const meta = document.querySelector(`meta[name="${name}"]`);
            return meta ? meta.getAttribute('content') : '';
        };
        const poiId = read('km-focus-poi');
        // 経路の端は地点の id か一時地点の番号。どちらも、いまの地図にあるものだけ
        const end = (idName, poiName) => {
            const id = read(idName);
            if (id) return graphData.nodes[id] && !graphData.nodes[id].hidden ? { id: id, label: getLabelForNode(id, graphData.nodes[id]), poi: null } : false;
            const poiNo = read(poiName);
            if (!poiNo) return null;
            const poi = (graphData.eventPois || []).find(p => Number(p.id) === Number(poiNo));
            return poi && poi.anchorNodeId && graphData.nodes[poi.anchorNodeId] ? { id: poi.anchorNodeId, label: poiSearchLabel(poi), poi: Number(poi.id) } : false;
        };
        const from = end('km-focus-from', 'km-focus-from-poi');
        const to = end('km-focus-to', 'km-focus-to-poi');
        if (from !== null && to !== null) {
            if (!from || !to) {
                showRoomNotice('共有された経路の場所は、今は案内していません。地図から探してください。');
                return;
            }
            routeStartId = from.id;
            routeStartPoi = from.poi;
            routeEndId = to.id;
            routeEndPoi = to.poi;
            if (startInput) startInput.value = from.label;
            if (endInput) endInput.value = to.label;
            /*
             * 共有した人の経路の選択で引く(2026-10-29)。**このページの間だけ**で、端末に保存した設定は書き換えない
             * (localStorage に書かない。欄の表示だけ合わせる。読み込み直せば自分の設定に戻る)
             */
            const shared = (read('km-focus-route-options') || '').split(',');
            if (shared[0] === 'elevator' || shared[0] === 'stairs' || shared[0] === 'any') {
                verticalPreference = shared[0];
                routeOptions = { fewerFloors: shared.includes('ff'), rain: shared.includes('rain') };
                if (verticalSelect) verticalSelect.value = verticalPreference;
                const ffBox = document.getElementById('route-fewer-floors');
                const rainBox = document.getElementById('route-rain');
                if (ffBox) ffBox.checked = routeOptions.fewerFloors;
                if (rainBox) rainBox.checked = routeOptions.rain;
                rebuildDijkstra();
                updateRouteOptionsState();
            }
            // 共有から引いた経路は「前回の経路」(履歴)に入れない(Main/user-history.js が km:route の shared を見る)
            routeFromShare = true;
            searchBtn.click();
            routeFromShare = false;
            return;
        }
        if (poiId) {
            if (!(graphData.eventPois || []).some(p => Number(p.id) === Number(poiId))) {
                showRoomNotice('この場所は今は案内していません(イベントが終わったのかもしれません)。');
                return;
            }
            goToPlace({ poiId: Number(poiId) });
        }
    }
    window.setTimeout(() => {
        if (window.kmMapReady) focusFromUrl();
        else document.addEventListener('km-map-ready', () => window.setTimeout(focusFromUrl, 0), { once: true });
    }, 0);

    /*
     * 共有のリンクから来た人にも**広告は出す**(2026-10-24、利用者の指示「共有から開いた時は、チュートリアル、前回の経路を
     * 全て無視(広告は無視しない)」)。広告はチュートリアルの終わりから出していたので、チュートリアルを出さない共有では
     * 出なくなる。開いた場所・経路を出し終えてから(focusFromUrl / focusRoomFromUrl のあと)、セッションで 1 回だけ
     * (Main/tutorial-end.js の kmShowAd)
     */
    if (document.querySelector('meta[name="km-from-share"]')) {
        const showShareAd = () => window.setTimeout(() => {
            if (typeof window.kmShowAd === 'function') window.kmShowAd();
        }, SHARE_AD_DELAY_MS);
        if (window.kmMapReady) showShareAd();
        else document.addEventListener('km-map-ready', showShareAd, { once: true });
    }
});
