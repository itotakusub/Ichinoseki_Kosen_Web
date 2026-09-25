<?php

declare(strict_types=1);

/**
 * KosenMap のホームページ(誰でも見られる公開マップ)。
 *
 * 旧実装(Downloaded/KosenMap/Main/index.php)からの変更点:
 *  - 旧 MySQL セッション認証($_SESSION['user_id'] のチェック)を削除。ログイン不要にした
 *  - is_admin 依存の検索パネル非表示分岐を削除。誰でも検索パネルを使える
 *  - is_admin 依存の管理者パネル(builder-panel / builder.js)をまるごと削除。
 *    地図の作成・編集はこの管理画面(admin/)側の権限と繋ぎ直すまで一時停止
 *  - ログアウトボタンを削除(ログイン自体が無いため)
 *  - Google Fonts への外部リンクを削除しシステムフォントへフォールバック
 *  - Leaflet を CDN からローカル配置(vendor-web/leaflet/)へ変更
 *  - 地図データは graph.js の直接読み込みではなく Main/app.js が
 *    /api/map-data.php を fetch する形に変更(教職員名の公開可否を
 *    admin/map-settings.php から制御できるようにするため)
 *
 * フェーズ4での追加:
 *  - 管理者ログイン・一般ログインの入口ボタン(Logto)。/admin/ と同じ sign-in.php を使う
 *  - APK ダウンロード導線と、教職員氏名を解除するパスワード入力 UI
 *    (証明書の導線は 1.0.5 で外した。下の info-panel を参照)
 */

/*
 * ここでのログイン状態チェックは「見るだけ」で、誰もリダイレクトしない。
 * admin/_inc/guard.php とは逆に、ここは fail open にする — 管理画面は
 * 「判定できないなら通さない」が正しいが、この公開マップは Logto が落ちていても
 * 地図自体は絶対に見られる状態を保つ方が正しいため。
 */
$loggedIn = false;
$displayName = null;
try {
    require __DIR__ . '/logto-client.php'; // $client, $appUrl を定義する
    $loggedIn = $client->isAuthenticated();
    if ($loggedIn) {
        $claims = $client->getIdTokenClaims();
        $displayName = $claims->name ?? $claims->username ?? $claims->email ?? $claims->sub;
    }

    /*
     * 教職員の印(docs/15 段 D。2026-09-18)。**開くたびに立て直す**(前の値を信じない)。
     *
     *   - ID トークンの organization_roles に、こちらの組織の教職員のロールがあること
     *   - アカウントが止められていないこと(**確かめられなければ立てない** —— 地図は誰でも見られるので、
     *     困るのは「教職員の特典が一時的に使えない」だけ)
     *   - 有効期限は ID トークンの期限まで(長く開きっぱなしのブラウザに権限を残さない)
     *
     * 地図の錠と氏名の判定(lib/map-access.php)は、この印を見る。
     */
    unset($_SESSION['km_map_teacher_until']);
    if ($loggedIn) {
        require_once __DIR__ . '/lib/staff-org.php';
        $roles = isset($claims->organization_roles) && is_array($claims->organization_roles) ? $claims->organization_roles : null;
        if (km_staff_org_claims_is_teacher($roles)) {
            try {
                require_once __DIR__ . '/lib/logto-management.php';
                if (!km_logto_user_is_suspended((string) $claims->sub)) {
                    $_SESSION['km_map_teacher_until'] = min((int) $claims->exp, time() + 3600);
                }
            } catch (Throwable $exception) {
                error_log('KosenMap home: teacher flag not set (suspension check failed): ' . $exception->getMessage());
            }
        }
    }
} catch (Throwable $exception) {
    error_log('KosenMap home: Logto session check failed (fail open, map still shown): ' . $exception->getMessage());
    $loggedIn = false;
    $displayName = null;
    // 確かめられなかったら教職員の印も外す(前の値を残さない)
    unset($_SESSION['km_map_teacher_until']);
}

function km_home_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/*
 * APK が登録されているか。あればダウンロード導線を出す。
 * ここも fail open — DB が読めなくても地図は出す(「無い」と扱うだけ)。
 */
require_once __DIR__ . '/lib/assets.php';   // km_public_asset(): 更新時刻を付けてキャッシュを破棄する
require_once __DIR__ . '/lib/csp.php';
require_once __DIR__ . '/lib/csrf.php';     // ログアウトのフォームに載せるトークン
require_once __DIR__ . '/lib/site.php';     // 管理者ログインの入口を管理画面のオリジンに向ける

// 出力より前に送る。地図は自前の JS しか読まないので一番狭い方針でよい
km_csp_send('public');

$apkAvailable = false;
try {
    require_once __DIR__ . '/lib/db.php';
    require_once __DIR__ . '/lib/distributables.php';
    $apkAvailable = km_dist_find(km_db(), 'apk') !== null;
} catch (Throwable $exception) {
    error_log('KosenMap home: APK lookup failed (treated as none): ' . $exception->getMessage());
}

/*
 * 地図データを HTML へ同梱する。
 *
 * 以前は app.js が /api/map-data.php を fetch していたが、それは **HTML と CSS/JS を
 * 読み終えてから**始まる。地図が出るまでに HTML → CSS/JS → API → 画像 と4波が
 * 直列に並び、モバイル回線ではそのぶん待たされていた。
 * このページは上の APK 確認で**どのみち DB へ繋いでいる**ので、ついでに読んで
 * 埋め込む。API の1往復と DB アクセスがまるごと消える。
 *
 * ここも fail open。読めなければ埋め込まないだけで、app.js が従来どおり
 * /api/map-data.php へ取りに行く(admin/map-editor.php と同じ経路)。
 */
$inlineMapData = null;
$kmEvent = null;              // イベントモードの案内(バナー)。無ければ null
$kmFirstFloorImage = null;    // 最初に映す階の見取り図。<head> で preload する
try {
    require_once __DIR__ . '/lib/map-data.php';
    /*
     * **JSON_HEX_TAG を外さないこと。** 部屋名・教職員氏名は管理画面から書き換えられる
     * DB の値で、そこに "</script>" が入っていると、素直に埋め込んだ場合に
     * script 要素がそこで終わったと解釈され、続きが HTML として実行されてしまう。
     * このフラグで "<" ">" が < > になるため、閉じタグを作れなくなる。
     * (1.0.0 のセキュリティ確認で innerHTML 側は塞いであるが、ここは別経路)
     */
    $kmMapData = km_map_data(km_db());
    $kmEvent = $kmMapData['event'] ?? null;
    $inlineMapData = json_encode(
        $kmMapData,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );

    /*
     * 最初に映す階の見取り図だけ、<head> で先に取りに行かせる。
     * app.js は CSS/JS を読み終えてから動き出すので、何もしないと画像の取得が
     * そのぶん後ろへずれる。preload なら HTML の解析と並行して始められる。
     * app.js の window.currentFloor と同じ "1" を指す(ずれると二重取得になる)。
     */
    foreach (($kmMapData['floors'] ?? []) as $floor) {
        if ((string) ($floor['id'] ?? '') === '1') {
            $kmFirstFloorImage = (string) $floor['svgPath'];
            break;
        }
    }
} catch (Throwable $exception) {
    error_log('KosenMap home: inline map data failed (app.js will fetch instead): ' . $exception->getMessage());
    $inlineMapData = null;
    $kmEvent = null;
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>高専マップ案内</title>

    <?php // 絶対パス。/favicon.svg は src/ 直下に置いてある。**条件の外に置くこと** ?>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">

    <?php if ($kmFirstFloorImage !== null): ?>
        <?php
        /*
         * 最初に映す階の見取り図。CSS/JS の取得と並行して始めさせる。
         *
         * URL は api/floor-image.php を指す(lib/map-data.php)。
         * **地図に錠が掛かっているときは floors が空**なので、ここは null のままになり、
         * preload そのものが出ない —— 錠の内側のものを先読みしに行かない。
         */
        ?>
        <link rel="preload" as="image" href="<?= km_home_e($kmFirstFloorImage) ?>" fetchpriority="high">
    <?php endif; ?>

    <?php
    /*
     * 地図の描画に要る JS を、**HTML を読み終える前に**取りに行かせる。
     *
     * <script> タグは </body> の直前にあり、しかもその直前に地図データの同梱 JSON
     * (十数KB)が入っている。ブラウザは HTML を最後まで受け取るまで leaflet.js の
     * 存在を知れないため、細い回線ではそこが素直に待ち時間になる
     * (1Mbps の実測: HTML 完了 4,411ms → スクリプト送信 4,916ms → leaflet.js 完了 9,940ms)。
     *
     * さらに defer のスクリプトは Chrome では Low 優先度になる。fetchpriority で引き上げる。
     *
     * **URL は下のタグと完全に一致させること。** 版(?v=…)がずれると二重に取得して逆効果。
     * 同じ km_public_asset() を通しているので自動的に一致する。
     *
     * panel.js は初回描画に要らないので入れない(帯域を奪うだけになる)。
     */
    ?>
    <link rel="preload" as="script" href="<?= km_home_e(km_public_asset('vendor-web/leaflet/leaflet.js')) ?>" fetchpriority="high">
    <link rel="preload" as="script" href="<?= km_home_e(km_public_asset('Main/dijkstra.js')) ?>" fetchpriority="high">
    <link rel="preload" as="script" href="<?= km_home_e(km_public_asset('Main/app.js')) ?>" fetchpriority="high">

    <?php
    /*
     * Leaflet にも版を付ける。「配置したきり変わらない」ので以前は付けていなかったが、
     * nginx が静的資材へ 30日 の Cache-Control を出すようになったため、**版が無いと
     * 差し替えても1か月古いままになる**。付けておけば入れ替えた瞬間に切り替わる。
     */
    ?>
    <!-- Leaflet.js CSS -->
    <link rel="stylesheet" href="<?= km_home_e(km_public_asset('vendor-web/leaflet/leaflet.css')) ?>" />
    <link rel="stylesheet" href="<?= km_home_e(km_public_asset('Main/styles.css')) ?>">
    <?php
    /*
     * ログアウトはフォームになった(sign-out.php の説明)。フォーム自体は箱を作らず、
     * 中のボタンが今までのリンクと同じ位置に並ぶようにする。
     */
    ?>
    <style<?= km_csp_nonce_attr() ?>>
        .km-signout-form { display: contents; }
    </style>
</head>

<body>

    <!-- マップ表示領域 (最背面) -->
    <div id="map"></div>

    <?php
    /*
     * 読み込み画面。**JS で作らずここに直接書く。**
     *
     * JS で作ると leaflet.js(144KB)と app.js のダウンロードを待つあいだ
     * 画面が白いままになり、「白いまま待たされる」という当の症状が消えない。
     * HTML に書いておけば、HTML が届いた時点で出る。
     *
     * 見た目は Main/styles.css のクラスで作る。style="…" 属性は CSP で弾かれる
     * (lib/csp.php を参照。nonce もハッシュも属性には効かない)。
     */
    ?>
    <div id="km-loading" class="km-loading">
        <div class="km-loading-card">
            <span class="km-loading-icon">📍</span>
            <p class="km-loading-title">地図を準備しています</p>
            <div class="km-loading-track">
                <div id="km-loading-bar" class="km-loading-bar"></div>
            </div>
            <p id="km-loading-status" class="km-loading-status">読み込みを開始しています…</p>
        </div>
    </div>

    <!-- UIレイヤー (前面) -->
    <div id="ui-layer">

        <?php
        /*
         * トップバー。**一段に収める**(2026-09-05、利用者の指示)。
         *
         * それまでは題字の下にログインの2つが縦に積まれ、スマホでは高さ 140px 前後を
         * 占めていた —— 地図の一番見たい上側が、押す用事の少ないボタンで埋まっていた。
         *
         * スマホでは、この中の #km-auth-links を「ダウンロード・設定」パネルの
         * #km-auth-slot へ **同じ要素のまま移す**(Main/panel.js)。
         * 2つ書いて片方を隠す形にはしない —— 片方だけ直したときに食い違う。
         */
        ?>
        <header class="top-bar">
            <div class="brand-area">
                <h1 class="app-title">
                    <span class="logo-icon">📍</span>
                    <span>KosenMap</span>
                </h1>

                <div class="top-actions">
                    <div class="action-group-left" id="km-auth-links">
                        <?php if ($loggedIn): ?>
                            <span class="user-chip">👤 <?= km_home_e($displayName) ?> さん</span>
                            <?php // POST + CSRF でだけ出られる。GET だと他サイトのリンクで追い出せた ?>
                            <form method="post" action="/sign-out.php" class="km-signout-form">
                                <?= km_csrf_field() ?>
                                <button type="submit" class="secondary-btn logout-btn">ログアウト</button>
                            </form>
                        <?php else: ?>
                            <?php
                            // **管理画面のオリジン(admin.ito4.jp)でサインインさせる**(2026-09-17)。公開側でサインインしてから
                            // /admin/ へ移ると、セッションの Cookie はホスト名ごとなので管理用のホストでもう一度サインインが要った。
                            // 別オリジンにしていなければ km_site_admin_origin() は公開側と同じ
                            ?>
                            <a href="<?= km_home_e(km_site_admin_origin()) ?>/sign-in.php?mode=signIn&return=<?= km_home_e(rawurlencode('/admin/')) ?>" class="secondary-btn login-btn">
                                管理者ログイン
                            </a>
                            <a href="/sign-in.php?mode=signIn&return=<?= km_home_e(rawurlencode('/')) ?>" class="secondary-btn login-btn">
                                一般ログイン
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="action-group-right">
                        <button id="info-panel-toggle" class="icon-btn" title="ダウンロード・設定" aria-label="ダウンロード・設定">⚙️</button>
                    </div>
                </div>
            </div>
        </header>

        <?php if ($kmEvent !== null): ?>
        <!--
            イベントモードの案内。
            バナー文言が未設定でも、**イベント中であること自体は伝える** ——
            通行止めや臨時の名称が出ている理由が分からないと、地図が壊れて見える。
        -->
        <div class="km-event-banner" role="status">
            <span class="km-event-banner-icon" aria-hidden="true">🎪</span>
            <span class="km-event-banner-text">
                <?= km_home_e($kmEvent['bannerText'] ?? (implode(' / ', $kmEvent['names']) . ' 開催中')) ?>
            </span>
            <?php if (!empty($kmEvent['bannerUrl'])): ?>
                <?php /* リンク先は lib/map-events.php がサイト内の絶対パスだけに検証済み */ ?>
                <a class="km-event-banner-link" href="<?= km_home_e($kmEvent['bannerUrl']) ?>">くわしく</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 検索パネル (浮遊カード) -->
            <div id="search-panel" class="search-panel glass-panel">
                <?php
                /*
                 * パネル自身の「閉じる」。開け閉ての的は左下のピルにもあるが、
                 * **PC では左下と左上で画面の端から端**まで離れており、
                 * 開いたパネルを見ている人の目の中に閉じ方が無かった。
                 *
                 * ピルは残す —— 閉じたあとに開き直す的は、パネルの外に要る。
                 */
                ?>
                <div class="search-head">
                    <h2 class="search-title">経路を調べる</h2>
                    <button
                        id="search-close"
                        type="button"
                        class="icon-btn"
                        title="検索を閉じる"
                        aria-label="検索を閉じる"
                        aria-controls="search-panel"
                    >&times;</button>
                </div>
                <div search-container>
                    <div class="search-inner">
                        <div class="input-group ">
                            <input type="text" id="start-input" list="node-options" placeholder="出発地から...">
                            <input type="text" id="end-input" list="node-options" placeholder="目的地まで...">
                        </div>
                        <?php
                        /*
                         * 階を移るときの好み(2026-09-22、利用者の要望)。**選ばれなかった方も使う** ——
                         * その建物に片方しか無いときに経路が消えないよう、重くするだけにしてある
                         * (`Main/dijkstra.js` の KM_VERTICAL_PENALTY)。選んだ値は端末に覚える。
                         */
                        ?>
                        <div class="km-route-pref">
                            <label for="vertical-pref">階を移るとき</label>
                            <select id="vertical-pref" class="km-route-pref-select">
                                <option value="any">指定なし</option>
                                <option value="elevator">エレベーター優先</option>
                                <option value="stairs">階段優先</option>
                            </select>
                        </div>
                        <?php
                        /*
                         * 経路の条件(2026-09-25、利用者の指示)。C 階の移動を減らす / D 雨の日。
                         * **どちらも重くするだけで、道を消さない**(Main/dijkstra.js の KM_ROUTE_WEIGHTS)。端末に覚える。
                         * 屋内優先(A)と部屋を通り抜けない(B)は既定の動きなので、ここには出さない。
                         */
                        ?>
                        <div class="km-route-pref km-route-conditions">
                            <label class="km-route-check"><input type="checkbox" id="route-fewer-floors"> 階の移動を減らす</label>
                            <label class="km-route-check"><input type="checkbox" id="route-rain"> 雨の日(外をなるべく通らない)</label>
                        </div>
                        <div class="button-group">
                            <button id="search-btn" class="primary-btn">案内開始</button>
                            <button id="reset-btn" class="secondary-btn">リセット</button>
                        </div>
                    </div>
                </div>
                    <datalist id="node-options"></datalist>
                    <select id="category-select" class="category-dropdown">
                        <option value="">🔍 カテゴリー検索...</option>
                        <option value="WC">🚻 トイレ</option>
                        <option value="stairs">🏃 階段/EV</option>
                        <option value="room">📖 教室</option>
                        <option value="facility">🏢 建物</option>
                    </select>
                    <?php
                    /*
                     * カテゴリー検索の結果。**検索パネルの中に出す**(2026-09-25)。
                     * 以前はルート案内のシートを借りて出していたが、そのシートは廃止した。
                     * 探した場所のすぐ下に並ぶ方が、どの操作の結果なのかも分かる。
                     */
                    ?>
                    <div id="category-results" class="km-category-results" aria-live="polite" hidden></div>
            </div>
        <script<?= km_csp_nonce_attr() ?>>
        window.onerror = function(msg, url, line, col, error) {
            console.error("JS Error: " + msg + " at " + url + ":" + line + ":" + col);
            return false;
        };
        // この公開ページは**閲覧専用**。地図の編集は管理画面の
        // /admin/map-editor.php(guard.php の内側)で行う。
        // app.js は window.isAdmin を「点の表示や座標つきツールチップを出すか」の
        // 判定にだけ使っており、保存の可否はサーバー側が判断する。
        window.isAdmin = false;
        // window.currentFloor は app.js が正本として設定する(以前ここでも代入していて
        // 二重管理になっていた)。
        </script>

        <?php
        /*
         * 地図の上の操作。**左下に集める**(2026-09-05、利用者の指示)。
         * 写す元はアプリの MapScreen.kt —— 左下に「表示調整」「検索」を並べている。
         *
         * それまでは検索の開閉がトップバーの ✖ アイコン、表示調整が右下に浮いた丸で、
         * 同じ「地図の見え方を変える操作」が画面の反対側に散っていた。
         *
         * 「表示調整」は Main/map-tuning.js が同じ形(.km-action-btn)でここへ足す。
         */
        ?>
        <div class="km-action-bar" id="km-action-bar">
            <button
                id="mobile-search-toggle"
                type="button"
                class="km-action-btn"
                title="出発地・目的地の入力"
                aria-controls="search-panel"
                aria-expanded="true"
            >
                <span class="km-action-icon" aria-hidden="true">🔍</span>
                <span class="km-action-label">検索</span>
            </button>
        </div>

        <?php
        /*
         * 階の切り替え。**右のふちに縦へ並べる**(アプリの Column(CenterEnd) と同じ)。
         *
         * 下に横並びで置いていたときは、下から出るシート(経路案内・設定・検索)と
         * 場所を取り合っていた。名前は「外全体図」から「外」へ —— 丸に収め、
         * どのボタンも同じ大きさにするため。読み上げ用の名前は aria-label に残す。
         *
         * 先頭の丸は**畳んだときに残る取っ手**(`Main/app.js` が開け閉てする)。
         * 畳んでいる間は**いまの階の名前**を出す ——
         * ただの ≡ にすると、畳んだ瞬間に「何階を見ているか」の手がかりが1つ減る。
         * 中の文字は JS が入れ替えるので、初期値は下の active と揃えておくこと。
         */
        ?>
        <nav class="floor-nav glass-panel" id="floor-nav" aria-label="階の切り替え">
            <button
                id="floor-toggle"
                type="button"
                class="floor-toggle"
                title="階の一覧を閉じる"
                aria-label="階の一覧を閉じる"
                aria-controls="floor-scroll"
                aria-expanded="true"
            ><span class="floor-toggle-label" aria-hidden="true">&times;</span></button>
            <div class="floor-scroll" id="floor-scroll">
                <button class="floor-btn" data-floor="outside" aria-label="外全体図" title="外全体図">外</button>
                <button class="floor-btn active" data-floor="1" aria-label="1階" title="1階">1F</button>
                <button class="floor-btn" data-floor="2" aria-label="2階" title="2階">2F</button>
                <button class="floor-btn" data-floor="3" aria-label="3階" title="3階">3F</button>
                <button class="floor-btn" data-floor="4" aria-label="4階" title="4階">4F</button>
                <button class="floor-btn" data-floor="5" aria-label="5階" title="5階">5F</button>
            </div>
        </nav>

        <?php
        /*
         * ルート案内。**1 行の帯にする**(2026-09-25、利用者の指示)。
         *
         * それまでは下から出るシート(guidance-panel)に全手順を並べていて、
         * **案内を始めた途端に画面の半分以上が手順の一覧で埋まり、肝心の経路が見えなかった。**
         *
         * 並びは左から「✕(やめる)」「いまの行き先」「位置の更新(次へ)」。
         * 行き先は**次に目指す 1 か所だけ**(階段・出入口・目的地)を出す。
         * Website は現在地を測れないので、「位置の更新」を押すと**今見えている部屋と合う名前の地点を選んでもらい**、
         * そこから目的地まで引き直す(2026-09-25、利用者の指示)。候補は帯の上の #km-route-pick に出す。
         *
         * **置き場所は左下の操作ピルのすぐ上。** 右は階のレールとズームを避ける
         * (スマホの検索シートと同じ幅の決め方)。見た目は styles.css の `.km-route-bar`。
         */
        ?>
        <div class="km-route-dock">
        <div id="km-route-pick" class="km-route-pick" aria-live="polite" hidden></div>
        <div id="km-route-bar" class="km-route-bar" role="region" aria-label="ルート案内" hidden>
            <button id="km-route-close" type="button" class="km-route-close" title="案内をやめる" aria-label="案内をやめる">&times;</button>
            <button id="km-route-target" type="button" class="km-route-target" title="行き先を地図の真ん中に出す">
                <span id="km-route-text" class="km-route-text"></span>
                <span id="km-route-sub" class="km-route-sub"></span>
            </button>
            <button id="km-route-next" type="button" class="km-route-next">位置の更新</button>
        </div>
        </div>

        <?php
        /*
         * ダウンロード・設定。**横から出る引き出しにする**(2026-09-25、利用者の指示)。
         *
         * 以前はルート案内と同じ「下から出るシート」を流用していて、開くと地図の下半分と
         * 左下の操作・右下のズームが隠れた。PC もスマホも**右から滑り出させ**、
         * 後ろの薄い幕を押しても閉じられるようにする(panel.js)。
         */
        ?>
        <div id="km-drawer-scrim" class="km-drawer-scrim" hidden></div>
        <aside id="info-panel" class="km-drawer glass-panel" aria-labelledby="info-panel-title" aria-hidden="true" inert>
            <div class="km-drawer-head">
                <h3 id="info-panel-title" class="km-drawer-title">ダウンロード・設定 🔧</h3>
                <button id="info-panel-close" type="button" class="km-drawer-close" title="閉じる" aria-label="閉じる">&times;</button>
            </div>
            <div class="km-drawer-body info-list">
                <?php
                /*
                 * ここにあった「ローカル証明書」の導線は 1.0.5 で外した。
                 *
                 * mkcert のルート CA を配っていたのは、**社内の IP へ直接繋いでいた頃**の名残。
                 * 本番は Let's Encrypt の証明書で出ているので、来場者の端末に自前の CA を
                 * 入れさせる理由が無い。むしろ「知らない CA をインストールさせる導線」が
                 * 公開ページに残っている方が危ない —— 同じ手口をそのまま真似される。
                 *
                 * `certs/rootCA.pem` 自体は消していない。**MariaDB の内部 TLS が同じ CA を
                 * 使っており、消すと DB が起動しない**(compose.yaml の --ssl-ca)。
                 */
                ?>
                <?php
                /*
                 * ログインの受け皿。**スマホのときだけ中身が入る。**
                 *
                 * Main/panel.js が、トップバーの #km-auth-links を画面幅に応じて
                 * ここへ移す(戻すときも同じ要素)。中身を PHP で二度書かないこと ——
                 * ログイン中と未ログインで出し分ける枝が2箇所になり、必ず食い違う。
                 */
                ?>
                <div class="info-section" id="km-auth-section" hidden>
                    <h4>ログイン状態</h4>
                    <div id="km-auth-slot"></div>
                </div>
                <div class="info-section">
                    <h4>Android アプリ</h4>
                    <?php if ($apkAvailable): ?>
                        <p class="info-text">
                            Android 端末にインストールできます。設定で「提供元不明のアプリ」を
                            許可してからインストールしてください。
                        </p>
                        <a href="/api/download.php?slug=apk" class="secondary-btn">APK をダウンロード</a>
                    <?php else: ?>
                        <p class="info-text is-last">
                            まだ配布用の APK はありません。
                        </p>
                    <?php endif; ?>
                </div>
                <div class="info-section">
                    <h4>よくある質問</h4>
                    <p class="info-text">
                        使い方や、教職員氏名の表示についての質問をまとめています。
                    </p>
                    <a href="/faq.php" class="secondary-btn">よくある質問を見る</a>
                </div>
                <div class="info-section">
                    <h4>お問い合わせ</h4>
                    <p class="info-text">
                        不具合の報告やご要望はこちらからお送りください。
                    </p>
                    <a href="/contact.php" class="secondary-btn">お問い合わせフォーム</a>
                </div>
                <?php
                /*
                 * アカウント設定への導線。**サインインしている人にだけ出す。**
                 *
                 * 変更の実体は Logto の Account API で、管理者かどうかは関係が無い。
                 * それでも導線が管理画面(admin/profile.php)にしか無く、
                 * 一般ログインの人は自分の表示名すら変えられなかった ——
                 * **その表示名はアプリのランキングに出る。**
                 */
                ?>
                <?php if ($loggedIn): ?>
                    <div class="info-section">
                        <h4>アカウント設定</h4>
                        <p class="info-text">
                            利用者名・表示名・パスワードを変更できます。
                            表示名はアプリのランキングに出る名前です。
                        </p>
                        <a href="/account.php" class="secondary-btn">アカウント設定を開く</a>
                    </div>
                <?php endif; ?>
                <?php
                /*
                 * 規約とポリシーへの導線。**公開ページのどこかから必ず辿れること**が要る
                 * (Play Store の審査でもアプリ内から到達できることを見られる)。
                 * ここと faq.php / contact.php のヘッダーから辿れる。
                 */
                ?>
                <div class="info-section">
                    <h4>規約とポリシー</h4>
                    <p class="info-text">
                        このサービスの利用条件と、情報の取り扱いについて。
                    </p>
                    <a href="/terms.php" class="secondary-btn">利用規約</a>
                    <a href="/privacy.php" class="secondary-btn">プライバシーポリシー</a>
                </div>
                <div id="km-unlock-section"></div>
                <div class="info-section">
                    <h4>はじめに</h4>
                    <p class="info-text">
                        初めて開いたときに出した案内(利用規約・メールについて)を、もう一度読めます。
                    </p>
                    <button type="button" class="secondary-btn" data-km-welcome-open>はじめにを読む</button>
                </div>
            </div>
        </aside>
    </div>

    <?php
    /*
     * はじめに(2026-09-25、利用者の要望)。**初めて開いたときに 1 回だけ出す。**
     *
     * 出すのは、使う前に知っておいてほしいことだけ —— 規約とプライバシー、
     * それと**メールが迷惑メールに入ることがある**こと(ログインの確認コードや
     * お問い合わせの返信が「届かない」と言われる原因の多くがこれ)。
     *
     * 見たかどうかは閲覧者の手元(localStorage)に覚える。**中身を変えたら版を上げる** ——
     * 上げれば、読んだことのある人にも次に開いたとき 1 回だけ出し直す。
     * 読み込み画面より手前に出す(地図の準備を待つあいだに読める)。
     * 開け閉ては Main/welcome.js。「ダウンロード・設定」からいつでも読み直せる。
     */
    $kmWelcomeVersion = '2026-09-25';
    $kmMailFrom = getenv('MAIL_FROM');
    $kmMailFrom = is_string($kmMailFrom) && str_contains($kmMailFrom, '@') ? $kmMailFrom : null;
    ?>
    <div id="km-welcome" class="km-welcome" data-version="<?= km_home_e($kmWelcomeVersion) ?>" hidden>
        <div class="km-welcome-card" role="dialog" aria-modal="true" aria-labelledby="km-welcome-title">
            <h2 id="km-welcome-title" class="km-welcome-title">📍 高専マップ案内へようこそ</h2>
            <p class="km-welcome-lead">
                校内の建物・部屋を探して、経路を案内する地図です。ログインしなくても使えます。
            </p>
            <ul class="km-welcome-list">
                <li>
                    <b>利用規約とプライバシーポリシー</b><br>
                    使う前に一度お読みください。使い始めた時点で、利用規約に同意したものとして扱います。
                    <span class="km-welcome-links">
                        <a href="/terms.php" target="_blank" rel="noopener">利用規約</a>
                        <a href="/privacy.php" target="_blank" rel="noopener">プライバシーポリシー</a>
                    </span>
                </li>
                <li>
                    <b>メールが届かないときは迷惑メールフォルダを確認してください</b><br>
                    ログインの確認コードやお問い合わせの返信は、迷惑メールとして振り分けられることがあります。
                    <?php if ($kmMailFrom !== null): ?>
                        <?= km_home_e($kmMailFrom) ?> を受信できるように設定しておくと確実です。
                    <?php else: ?>
                        このサイトからのメールを受信できるように設定しておくと確実です。
                    <?php endif; ?>
                </li>
                <li>
                    <b>現在地は使いません</b><br>
                    Web 版は端末の位置情報を読みません。案内中に「位置の更新」を押し、今見えている部屋の名前を選ぶと、そこから案内し直します。
                </li>
            </ul>
            <button id="km-welcome-ok" type="button" class="primary-btn km-welcome-ok">はじめる</button>
        </div>
    </div>

    <?php if ($inlineMapData !== null): ?>
        <?php
        /*
         * 地図データ。app.js がここから読む(無ければ /api/map-data.php へ取りに行く)。
         *
         * type="application/json" は実行されないが、**script 要素である以上 CSP の
         * script-src の対象**なので nonce が要る。付けないと丸ごと落とされる。
         */
        ?>
        <script type="application/json" id="km-map-data"<?= km_csp_nonce_attr() ?>><?= $inlineMapData ?></script>
    <?php endif; ?>

    <?php
    /*
     * defer を付ける。末尾に置いてあるので実行順は元から HTML パースの後だが、
     * defer にすると**パースと並行してダウンロード**される。相互の順序は保たれるので
     * leaflet.js → app.js の依存関係は崩れない。
     */
    ?>
    <!-- Leaflet.js -->
    <script defer src="<?= km_home_e(km_public_asset('vendor-web/leaflet/leaflet.js')) ?>"></script>
    <script defer src="<?= km_home_e(km_public_asset('Main/dijkstra.js')) ?>"></script>
    <?php
    /*
     * 解除フォームの組み立て。**app.js より前に置くこと。**
     * defer は書いた順に実行されるので、app.js が「錠が掛かっている」と分かった時点で
     * window.kmRenderUnlockForm が既に在る状態にしておく。
     * panel.js も同じものを使う。
     */
    ?>
    <script defer src="<?= km_home_e(km_public_asset('Main/unlock.js')) ?>"></script>
    <?php
    /*
     * 地図の見た目と表示調整。**app.js より先に読む** ——
     * app.js は起動時に `window.KM_MAP_STYLE` を読む。
     * `defer` は書いた順に実行されるので、この並びで足りる。
     */
    ?>
    <script defer src="<?= km_home_e(km_public_asset('Main/map-style.js')) ?>"></script>
    <script defer src="<?= km_home_e(km_public_asset('Main/map-tuning.js')) ?>"></script>
    <script defer src="<?= km_home_e(km_public_asset('Main/app.js')) ?>"></script>
    <script defer src="<?= km_home_e(km_public_asset('Main/panel.js')) ?>"></script>
    <script defer src="<?= km_home_e(km_public_asset('Main/welcome.js')) ?>"></script>

</body>

</html>