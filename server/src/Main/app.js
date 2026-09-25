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
    function setSearchPanelCollapsed(collapsed, refit = true) {
        if (!searchPanel) return;
        searchPanel.classList.toggle('collapsed', collapsed);
        if (mobileSearchToggle) {
            const icon = mobileSearchToggle.querySelector('.km-action-icon');
            const label = mobileSearchToggle.querySelector('.km-action-label');
            if (icon) icon.textContent = collapsed ? '🔍' : '✕';
            if (label) label.textContent = collapsed ? '検索' : '閉じる';
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
        setSearchPanelCollapsed(window.matchMedia('(max-width: 768px)').matches, false);
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
     * 地図の初期化が終わったことを知らせる。**送出は残す** ——
     * いまの購読者は居ないが、地図を触る側から見れば「使える状態になった」合図で、
     * 退避した zoom.js を戻したときにもそのまま繋がる。
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
     * 建物が一瞬崩れて見える原因になっていた。ここで全フロア分をバックグラウンドで
     * 先読みしてサイズ込みでキャッシュしておき、切替時はキャッシュを最優先で使う。
     */
    const preloadedImages = {}; // floor -> { src, bounds }
    const imagePreloadPromises = {};

    function preloadFloorImage(floor) {
        if (imagePreloadPromises[floor]) return imagePreloadPromises[floor];
        const url = floorImages[floor];
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
     * 全階の見取り図を読む。
     *
     * @param {Function} onFirstReady 現在の階が読めた時点で呼ぶ。**ここで地図を見せる。**
     *   全階を待ってから見せていた頃は、1Mbps で「1階は6.0秒で揃っているのに
     *   読み込み画面が閉じるのは19.3秒」という状態だった(実測)。
     * @returns {Promise<void>} 全階ぶん読み終えたら解決する(裏で進む)
     */
    async function preloadAllFloorImages(firstFloor, onProgress, onFirstReady) {
        const queue = preloadOrder(firstFloor);
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

        const marker = L.marker([node.y, node.x], {
            icon: L.divIcon({
                className: 'km-node km-node-' + type,
                html: nodeIconHtml(node, type),
                iconSize: [box, box],
                iconAnchor: [box / 2, box / 2],
            }),
            keyboard: false,
            // 掴みやすさは変えない。編集画面で小さな点を掴む操作がある
            interactive: true,
        });

        // ツールチップは html: true で描くので、DB 由来の文字列は必ずエスケープする
        let tooltipContent = escapeHtml(displayNameFor(node));
        if (window.isAdmin) {
            /*
             * **`style="…"` を書かない。** CSP に `'unsafe-inline'` が無く、
             * style 属性には nonce もハッシュも効かない(`lib/csp.php`)。
             * ここは以前インラインの style を持っており、**効かないまま気付かれていなかった。**
             */
            tooltipContent = `<div class="km-admin-tip">
                <span class="km-admin-tip-id">${escapeHtml(id)}</span><br>
                ${escapeHtml(displayNameFor(node))}<br>
                <span class="km-admin-tip-xy">(${node.x}, ${node.y})</span>
            </div>`;
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
                className: 'km-label km-label-' + type,
                html: true,
            });
        }

        if (!window.isAdmin) {
            /*
             * **onclick 属性は使えない。** CSP(script-src に 'unsafe-inline' なし)は
             * インラインのイベントハンドラを実行させない — nonce もハッシュも
             * 属性には効かない。実際 1.0.1 でここが動かなくなっていた。
             * 行き先は data 属性に置き、クリックは下の委譲リスナーで受ける。
             */
            marker.bindPopup(nodeDetailHtml(id, node, type));
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
            }
        });

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
        const title = node.alias ? `${node.alias}(${node.name})` : node.name;

        let rows = '';
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

        /*
         * **押せないボタンを出さない。** 目的地にできない地点で
         * 灰色のボタンだけ見せても、なぜ押せないのかは伝わらない。
         * 建物名のときは、代わりに何を選べばよいかを一行で言う。
         */
        const actions = isRoutableNode(node)
            ? `<button class="popup-btn" data-km-pick="start" data-km-node="${escapeHtml(id)}">ここを出発地にする</button>
               <button class="popup-btn secondary" data-km-pick="end" data-km-node="${escapeHtml(id)}">ここを目的地にする</button>`
            : (node.type === 'facility'
                ? `<p class="km-detail-note">建物そのものは行き先にできません。中の部屋を選んでください。</p>`
                : '');

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

        return `
            <div class="popup-content km-detail">
                <span class="popup-title">${escapeHtml(title)}</span>
                <div class="km-detail-meta">
                    <span class="km-detail-chip km-chip-${escapeHtml(type)}">${escapeHtml(typeLabel)}</span>
                    <span class="km-detail-floor">${escapeHtml(floorLabel)}</span>
                </div>
                ${rows}
                ${enter}
                ${actions}
            </div>
        `;
    }

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

        floorMarkerLayers.set(key, groups);
        return groups;
    }

    /** 作り置きを全部捨てる。graphData が書き換わった(管理画面での編集)ときに使う。 */
    function clearFloorMarkers() {
        activeMarkerGroups.forEach(group => window.map.removeLayer(group));
        activeMarkerGroups = [];
        floorMarkerLayers.clear();
        markersById.clear();
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

        const topBar = document.querySelector('.top-bar');
        if (isPanelVisible(topBar)) {
            top = topBar.getBoundingClientRect().bottom + PANEL_GAP;
        }

        const panel = document.getElementById('search-panel');
        if (isPanelVisible(panel)) {
            const rect = panel.getBoundingClientRect();
            // デスクトップでは左に立つ縦長のカード、モバイルでは下から出るシート
            if (width > 768) {
                left = Math.max(left, rect.right + PANEL_GAP);
            } else {
                bottom = Math.max(bottom, height - rect.top + PANEL_GAP);
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
            window.map.setMinZoom(Math.min(-1, fitZoom));
        }

        window.map.fitBounds(bounds, padding);
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
        userMovedMap = false;
        window.map.invalidateSize(); // コンテナサイズの再計算
        applyFit(bounds);

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

    (async () => {
        const imagesDone = preloadAllFloorImages(
            window.currentFloor,
            (loaded, total) => kmLoading.progress('images', loaded, total),
            () => {
                // 今いる階が読めた。地図へ載せて、ここで見せてしまう
                loadFloorImageDynamic(window.currentFloor);
                kmLoading.finish();
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
     *   2. 重なったら上下に少しずらしてみる(点とピルを繋ぐ線は短いので ±30px まで)
     *   3. どこへずらしても重なるなら**そのラベルは出さない**(点は出したまま。
     *      押せば名前が読めるので、情報は失われない)
     *
     * ズームを上げると点どうしの間が開くので、隠れていたものから順に戻ってくる。
     */
    const KM_LABEL_PRIORITY = { facility: 0, entrance: 1, stairs: 2, room: 3, wifi_router: 4, road: 5, wall: 6 };
    const KM_LABEL_NUDGES = [0, -14, 14, -30, 30];
    let declutterTimer = null;

    function labelPriority(el) {
        const type = (el.className.match(/km-label-([a-z_]+)/) || [])[1];
        return KM_LABEL_PRIORITY[type] === undefined ? 9 : KM_LABEL_PRIORITY[type];
    }

    function overlaps(a, b) {
        // 2px の余白。ちょうど接している程度は「読める」ので隠さない
        return !(a.right <= b.left + 2 || a.left >= b.right - 2
            || a.bottom <= b.top + 2 || a.top >= b.bottom - 2);
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
        all.forEach(function (el) {
            el.classList.remove('km-label-hidden');
            el.style.marginTop = '';
        });

        /*
         * **屋外だけ**(2026-09-25、利用者の指示)。建物の中では部屋名が 1 つでも欠けると
         * 探せなくなるので、重なっても全部出す(上で戻したまま終える)。
         */
        if (String(window.currentFloor) !== 'outside') return;

        /*
         * 薄く出している建物名は**数に入れない。** 下敷きの文字なので、
         * 押せる地点の名前を押しのけたり、隠したりしてはいけない。
         */
        const faded = window.map.getContainer().classList.contains('km-facility-faded');
        const labels = all.filter(function (el) {
            return !(faded && el.classList.contains('km-label-facility'));
        });

        labels.sort(function (a, b) {
            const byType = labelPriority(a) - labelPriority(b);
            if (byType !== 0) return byType;
            // 同じ種類なら上から順に。並べ方を決めておかないと、少し動かすたびに
            // 隠れるものが入れ替わって**ちらつく**
            return a.getBoundingClientRect().top - b.getBoundingClientRect().top;
        });

        const placed = [];
        labels.forEach(function (el) {
            const base = el.getBoundingClientRect();
            if (base.width === 0 && base.height === 0) return;   // そもそも出ていない
            for (let i = 0; i < KM_LABEL_NUDGES.length; i++) {
                const shift = KM_LABEL_NUDGES[i];
                const rect = {
                    left: base.left, right: base.right,
                    top: base.top + shift, bottom: base.bottom + shift,
                };
                const hit = placed.some(function (other) { return overlaps(rect, other); });
                if (!hit) {
                    if (shift !== 0) el.style.marginTop = shift + 'px';
                    placed.push(rect);
                    return;
                }
            }
            el.classList.add('km-label-hidden');
        });
    }

    /** 連続して呼ばれる(ズーム中・移動中)ので、落ち着いてから1回だけ測る。 */
    function scheduleDeclutter() {
        window.clearTimeout(declutterTimer);
        declutterTimer = window.setTimeout(declutterLabels, 120);
    }
    window.kmDeclutterLabels = scheduleDeclutter;

    window.map.on('zoomend', () => {
        renderGlobalLabels();
        scheduleDeclutter();
    });
    window.map.on('moveend', scheduleDeclutter);

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
            if (currentRoutePath && startInput && endInput && startInput.value && endInput.value) {
                searchBtn.click();
            }
        });
    });

    // ---- UIイベントリスナー ----
    if (searchBtn) {
        searchBtn.addEventListener('click', () => {
            const startText = startInput.value;
            const endText = endInput.value;

            const startNode = resolveNodeByLabel(startText);
            const endNode = resolveNodeByLabel(endText);

            if (!startNode || !endNode) {
                alert('リストから出発地と目的地を選択または正しく入力してください');
                return;
            }

            const path = dijkstra.findShortestPath(startNode, endNode);
            if (path) {
                currentRoutePath = path;
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
            clearRoute();
        });
    }

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
        return btn ? btn.textContent.trim() : String(floor);
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
    }

    const categorySelect = document.getElementById('category-select');
    if (categorySelect) {
        categorySelect.addEventListener('change', () => {
            const type = categorySelect.value;
            if (!type) return;

            const results = [];
            const patterns = {
                'WC': /WC|トイレ|便所|お手洗い/i,
                'stairs': /階段|エレベーター|EV/i,
                'room': /室|教室|実験|講義|ラボ/i,
                'facility': /棟|館|ホール|センター/i
            };

            for (const [id, node] of Object.entries(graphData.nodes)) {
                // 人が行ける場所だけ。通路の点・壁・Wi-Fi ルーターは候補にしない
                if (node.type === 'road' || node.type === 'wall' || node.type === 'wifi_router') continue;
                const pattern = patterns[type];
                if (node.type === type || (pattern && pattern.test(node.name))) {
                    results.push({ id, name: node.name, floor: node.floor, x: node.x, y: node.y });
                }
            }

            if (results.length > 0) {
                // 見出しは選んだ項目の文字(「🚻 トイレ」など)。内部の値(WC)は出さない
                const option = categorySelect.options[categorySelect.selectedIndex];
                const title = option ? option.textContent.trim() : type;
                /*
                 * 結果は検索パネルの中に出るので、**パネルは畳まない**
                 * (以前は別のシートに出していたので、スマホでは畳んで場所を空けていた)。
                 * 1 件選ぶと、そのときに畳む(openNodeFromList)。
                 */
                showCategoryResults(title, results);
            } else {
                alert("該当する場所が見つかりませんでした。");
            }
            categorySelect.value = "";
        });
    }


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
    // 移行前の graph.js は "教員室 管-310 架空 太郎" のように1本の文字列だったが、
    // フェーズ3の DB 移行で name(部屋名)と occupantName(教職員氏名)を別カラムに
    // 分離したため、表示側で元の見た目に組み立て直す。
    function displayNameFor(node) {
        /*
         * イベント中の臨時名称。**元の名前も残して併記する。**
         * 「ステージ会場」だけにすると、普段この建物を知っている人が
         * どこのことか分からなくなる(来場者と在校生の両方が同じ地図を見る)。
         */
        if (node.alias) {
            return `${node.alias}(${node.name})`;
        }
        return node.occupantName ? `${node.name} ${node.occupantName}` : node.name;
    }

    function getLabelForNode(id, node) {
        const floorLabel = String(node.floor) === "outside" ? "外" : `${node.floor}F`;
        // 臨時名称があるときは**両方の名前で引ける**ようにする。
        // 来場者は「ステージ会場」で、在校生は「第1体育館」で探す
        const name = node.alias ? `${node.alias}(${node.name})` : node.name;
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
            return `[${floorLabel}] ${p.name}` === text;
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
            labels.push(`[${floorLabel}] ${poi.name}`);
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
    const KM_POI_ICONS = {
        food: '🍢', exhibit: '🎨', reception: '📋',
        firstaid: '🚑', toilet: '🚻', stage: '🎤', other: '📍',
    };

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

        // --- 臨時の地点 ---
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

            // ポップアップのボタンは onclick を書かない(CSP)。data 属性 + 委譲で拾う
            const anchorButtons = poi.anchorNodeId
                ? `<div class="popup-actions">
                     <button class="popup-btn" data-km-pick="start" data-km-node="${escapeHtml(poi.anchorNodeId)}">出発地に設定</button>
                     <button class="popup-btn" data-km-pick="end" data-km-node="${escapeHtml(poi.anchorNodeId)}">目的地に設定</button>
                   </div>`
                : '';
            marker.bindPopup(
                `<b>${escapeHtml(poi.name)}</b>`
                + (poi.note ? `<br>${escapeHtml(poi.note)}` : '')
                + anchorButtons
            );
            marker.bindTooltip(escapeHtml(poi.name), { permanent: false, direction: 'top' });
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
        const base = L.polyline(latlngs, { color: '#ef4444', weight: 7, opacity: 0.9 });
        const stripe = L.polyline(latlngs, {
            color: '#ffffff', weight: 2.5, opacity: 0.9, dashArray: '10, 10',
        });
        const group = L.featureGroup([base, stripe]).addTo(window.map);

        return group;
    }

    function drawPath(pathNodeIds) {
        currentMarkers.forEach(m => window.map.removeLayer(m));
        currentMarkers = [];
        let currentFloorLatLngs = [];
        let pathBoundsGroup = new L.FeatureGroup();

        for (let i = 0; i < pathNodeIds.length; i++) {
            const id = pathNodeIds[i];
            const node = graphData.nodes[id];
            const isStart = (i === 0);
            const isEnd = (i === pathNodeIds.length - 1);

            if (String(node.floor) === String(window.currentFloor)) {
                currentFloorLatLngs.push([node.y, node.x]);

                // 出発・到着ピン、または中間地点の表示
                if (isStart || isEnd) {
                    const pinClass = isStart ? 'pin-start' : 'pin-end';
                    const pinText = isStart ? '出発' : '到着';
                    const pinIcon = L.divIcon({
                        className: `route-pin ${pinClass}`,
                        html: `<div class="pin-bubble">${pinText}</div><div class="pin-arrow"></div>`,
                        iconSize: [60, 40],
                        iconAnchor: [30, 40]
                    });
                    const marker = L.marker([node.y, node.x], { 
                        icon: pinIcon, 
                        zIndexOffset: 2000 // 最前面に表示
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
                        radius: 6, color: '#e11d48', fillColor: '#f43f5e', fillOpacity: 1
                    }).bindTooltip(escapeHtml(node.name)).addTo(window.map);
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
        if (pathBoundsGroup.getLayers().length > 0) {
            /*
             * **パネルと帯を避けて収める**(mapFitPadding は案内の帯も数える)。
             * 以前は四方 50px 固定で、出発・到着のピンが下の操作やシートの裏に潜った。
             */
            const padding = mapFitPadding();
            // 出発・到着の吹き出しは点の**上へ 40px** 伸びる。そのぶん上を空けないとトップバーの裏に潜る
            padding.paddingTopLeft = padding.paddingTopLeft.add([0, 48]);
            window.map.fitBounds(pathBoundsGroup.getBounds(), padding);
        }
    }


    /*
     * ポップアップのボタンを受ける委譲リスナー。
     *
     * ポップアップは**開くたびに作り直される**ので、ボタン1つずつに
     * addEventListener を付ける形にすると付け直しの管理が要る。地図コンテナで
     * まとめて受ければ、いつ作られたものでも拾える。
     */
    document.getElementById('map')?.addEventListener('click', (event) => {
        const enterButton = event.target.closest('[data-km-enter]');
        if (enterButton) {
            window.kmEnterBuilding(enterButton.dataset.kmEnter, enterButton.dataset.kmNode);
            return;
        }
        const button = event.target.closest('[data-km-pick]');
        if (!button) return;
        window.setAsLocation(button.dataset.kmNode, button.dataset.kmPick);
    });

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

    window.setAsLocation = function (nodeId, type) {
        const node = graphData.nodes[nodeId];
        /*
         * **口の側でも弾く。** ボタンは出していないが、
         * `window.setAsLocation` は誰でも呼べる形で外に出ている
         * (イベントの臨時地点のポップアップからも呼ばれる)。
         */
        if (!isRoutableNode(node)) return;
        const label = getLabelForNode(nodeId, node);
        if (type === 'start') { if (startInput) startInput.value = label; }
        else { if (endInput) endInput.value = label; }
        window.map.closePopup();
        if (startInput && endInput && startInput.value && endInput.value) searchBtn.click();
    };

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
    function renderRouteBar() {
        if (!routeBar) return;
        const active = currentRoutePath !== null && routeSteps.length > 0;
        routeBar.hidden = !active;
        document.body.classList.toggle('km-route-active', active);
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
            routeText.textContent = `${routeNodeLabel(goal)}に到着しました`;
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
            : `${routeNodeLabel(node)}に向かう`;
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
        const center = () => window.map.setView([node.y, node.x], Math.max(window.map.getZoom(), 1));
        if (String(step.floor) !== String(window.currentFloor) && floorImages[step.floor]) {
            // 図が載ってから寄せる。先に寄せると、載ったときの収め直しで戻される
            window.map.once('moveend', center);
            changeFloor(step.floor);
            return;
        }
        center();
    }

    /**
     * カテゴリー検索の結果を、検索パネルの中に並べる(2026-09-25)。
     * 以前はルート案内のシートを借りていたが、そのシートは廃止した。
     */
    function showCategoryResults(title, results) {
        const box = document.getElementById('category-results');
        if (!box) return;
        box.innerHTML = '';
        box.hidden = false;

        const head = document.createElement('div');
        head.className = 'km-category-head';
        const heading = document.createElement('span');
        heading.textContent = `${title}(${results.length} 件)`;
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'km-category-close';
        close.setAttribute('aria-label', '結果を閉じる');
        close.textContent = '×';
        close.addEventListener('click', () => {
            box.hidden = true;
            box.innerHTML = '';
        });
        head.append(heading, close);
        box.appendChild(head);

        const list = document.createElement('ul');
        list.className = 'km-category-list';
        results.forEach(node => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'km-category-item';
            // 名前は DB の値。textContent で入れるのでエスケープは要らない
            const name = document.createElement('span');
            name.textContent = node.name;
            const floor = document.createElement('small');
            floor.textContent = floorLabelFor(node.floor);
            button.append(name, floor);
            button.addEventListener('click', () => openNodeFromList(node));
            item.appendChild(button);
            list.appendChild(item);
        });
        box.appendChild(list);
    }

    /** 一覧から選んだ地点へ寄せて、詳細を開く。 */
    function openNodeFromList(node) {
        if (window.innerWidth <= 768 && searchPanel) setSearchPanelCollapsed(true);
        if (String(node.floor) !== String(window.currentFloor)) changeFloor(node.floor);
        setTimeout(() => {
            /*
             * 寄った状態に見合うマーカーを載せてから開く。以前は表示中の
             * マーカーを座標で総当たりして探していたが、ID で引ければ済む
             * (同じ座標に複数のノードが立っていると誤爆する形でもあった)。
             *
             * **移動が終わってから開くこと。** setView はアニメーションする =
             * 直後の getZoom() はまだ寄る前の値を返しうる。その値で
             * renderGlobalLabels() を呼ぶと部屋のマーカーがまだ地図に載らず、
             * hasLayer() が false になってポップアップが出ない。
             */
            let opened = false;
            const openWhenReady = () => {
                if (opened) return;
                opened = true;
                window.renderGlobalLabels();
                const marker = markersById.get(node.id);
                if (marker && window.map.hasLayer(marker)) marker.openPopup();
            };

            // 既に同じ位置・倍率だと moveend が飛んでこないので、保険の時間差も置く
            window.map.once('moveend', openWhenReady);
            window.setTimeout(openWhenReady, 600);

            window.map.setView([node.y, node.x], 2);
        }, 300);
    }
});
