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
/** 教職員なのに ID トークンを取り直せなかった(特典を出せない)。画面に「サインインし直すと戻る」と出す */
$teacherNeedsSignIn = false;
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
            /*
             * **期限の切れた ID トークンは取り直してから判定する**(2026-09-25、診断 W-50)。
             * 以前は古いまま読んでいたので、有効期間が過ぎると教職員に氏名が出なくなった
             * (右上には「○○ さん」と出たまま)。取り直した ID トークンの organization_roles で見直す ——
             * Logto で組織から外された人は、ここで外れる。取り直せなければ印を立てず、理由を画面に出す。
             */
            $fresh = $client->kmFreshIdTokenClaims();
            $freshRoles = $fresh !== null && is_array($fresh->organization_roles ?? null) ? $fresh->organization_roles : null;
            if ($fresh === null) {
                $teacherNeedsSignIn = true;
            } elseif (km_staff_org_claims_is_teacher($freshRoles)) {
                try {
                    require_once __DIR__ . '/lib/logto-management.php';
                    if (!km_logto_user_is_suspended((string) $fresh->sub)) {
                        $_SESSION['km_map_teacher_until'] = min((int) $fresh->exp, time() + 3600);
                    }
                } catch (Throwable $exception) {
                    error_log('KosenMap home: teacher flag not set (suspension check failed): ' . $exception->getMessage());
                }
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

/*
 * お試しの閲覧リンク(lib/map-guest.php。2026-09-30)。**取り消し・期限切れならここで印を外す。**
 * 印の無い人は DB に問い合わせない。地図データの同梱(km_map_data)より前に済ませること。
 */
require_once __DIR__ . '/lib/session.php';
km_session_start();
require_once __DIR__ . '/lib/map-guest.php';
$guestViewing = km_map_guest_verify();
$guestUntil = $guestViewing ? (int) $_SESSION[KM_MAP_GUEST_SESSION_KEY]['until'] : null;
$guestName = $guestViewing ? km_map_guest_name() : null;

// 訪問者の記録に、閲覧者の種別を渡す(lib/visit-log.php。nginx がログに書き、利用者には返さない)
require_once __DIR__ . '/lib/visit-log.php';
if ($guestViewing) {
    km_visit_mark_viewer('guest', (string) ($_SESSION[KM_MAP_GUEST_SESSION_KEY]['account'] ?? ''));
} elseif ($loggedIn && isset($claims->sub)) {
    km_visit_mark_viewer('user', (string) $claims->sub);
} else {
    km_visit_mark_viewer('anon');
}

/*
 * テスト用アカウント(展示の PC。2026-10-15、lib/test-mode.php)。**開くたびに確かめ直す**(前の印を信じない)。
 * 印が付いていれば:「テスト中」の札とアンケートの QR、操作方法と「始めから」を出し、操作を記録する
 * (記録の API は下のセッションの印を見る)。確かめられなければ付いていないとみなす —— 一般の人を記録しない側へ倒す。
 */
require_once __DIR__ . '/lib/test-mode.php';
unset($_SESSION[KM_TEST_MODE_SESSION_KEY]);
$kmTestMode = null;
if ($loggedIn && isset($claims->sub)) {
    try {
        $testPdo = km_db();
        if (km_test_account_is_marked($testPdo, (string) $claims->sub)) {
            $_SESSION[KM_TEST_MODE_SESSION_KEY] = ['sub' => (string) $claims->sub, 'until' => time() + KM_TEST_MODE_SESSION_TTL];
            $surveyUrl = km_test_survey_url($testPdo);
            $surveyQr = null;
            if ($surveyUrl !== '' && km_test_survey_url_valid($surveyUrl)) {
                require_once __DIR__ . '/lib/qr.php';
                $surveyQr = str_replace('アクセスコードの QR', 'アンケートの QR', km_qr_svg(km_qr_matrix($surveyUrl), 4, 4));
            } else {
                $surveyUrl = '';
            }
            $kmTestMode = [
                'surveyUrl' => $surveyUrl,
                'surveyQr' => $surveyQr,
                'guide' => km_test_guide_lines(km_test_guide_text($testPdo)),
                // 触られないまま過ぎたら自動で「始めから」(分。0 は使わない)
                'idleMinutes' => km_test_idle_minutes($testPdo),
            ];
        }
    } catch (Throwable $exception) {
        error_log('KosenMap home: test-mode check failed (treated as not marked): ' . $exception->getMessage());
        unset($_SESSION[KM_TEST_MODE_SESSION_KEY]);
        $kmTestMode = null;
    }
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
/*
 * QR から開いたときの部屋(2026-10-14、利用者の指示「URL にオプションを付ける」)。
 * `/?room=<地点の id>`(アプリの uuid。ハイフンは有っても無くてもよい)。
 * **字の形だけ確かめて <meta> で渡す。DB は引かない** —— 探すのは Main/app.js が、届いた地図の中から行う
 * (錠やイベントで出さない地点は、そもそも地図に無いか hidden の印が付いている)。
 * 地図データの同梱とは別にしてあるのは、同梱できずに API へ取りに行ったときも効かせるため。
 */
$kmFocusRoom = null;
$roomParam = $_GET['room'] ?? '';
if (is_string($roomParam) && preg_match('/^[0-9A-Fa-f-]{32,36}$/', $roomParam) === 1) {
    $roomId = str_replace('-', '', $roomParam);
    $kmFocusRoom = strlen($roomId) === 32 ? $roomId : null;
}
/*
 * ポスターの一覧(/posters.php)から来たときのポスター(2026-10-21)。`/?room=<地点>&poster=<番号>`。
 * 部屋へ移るのは上の room で、こちらは詳細の中でそのポスターを先頭に出すだけ。**数字の形だけ確かめる。**
 */
$kmFocusPoster = null;
$posterParam = $_GET['poster'] ?? '';
if (is_string($posterParam) && preg_match('/^[1-9]\d{0,9}$/', $posterParam) === 1) {
    $kmFocusPoster = $posterParam;
}
/*
 * 共有のリンク(2026-10-23、利用者の指示「ルート設定時にシェアするなど」)。
 *   /?poi=<臨時の地点の番号>        … 一時地点を開く(地点の id を持たないので room とは別)
 *   /?from=<地点の id>&to=<地点の id> … その経路を出す(端が一時地点なら fromPoi= / toPoi= に番号)
 * room と同じく**字の形だけ確かめて <meta> で渡す**(探すのは Main/app.js。地図に無ければ知らせるだけ)
 */
$kmNodeIdParam = static function (string $name): ?string {
    $value = $_GET[$name] ?? '';
    if (!is_string($value) || preg_match('/^[0-9A-Fa-f-]{32,36}$/', $value) !== 1) {
        return null;
    }
    $id = strtolower(str_replace('-', '', $value));
    return strlen($id) === 32 ? $id : null;
};
$kmFocusFrom = $kmNodeIdParam('from');
$kmFocusTo = $kmNodeIdParam('to');
$kmPoiParam = static function (string $name): ?string {
    $value = $_GET[$name] ?? '';
    return is_string($value) && preg_match('/^[1-9]\d{0,9}$/', $value) === 1 ? $value : null;
};
$kmFocusPoi = null;
$poiParam = $_GET['poi'] ?? '';
if (is_string($poiParam) && preg_match('/^[1-9]\d{0,9}$/', $poiParam) === 1) {
    $kmFocusPoi = $poiParam;
}
$kmFocusFromPoi = $kmFocusFrom === null ? $kmPoiParam('fromPoi') : null;
$kmFocusToPoi = $kmFocusTo === null ? $kmPoiParam('toPoi') : null;

$inlineMapData = null;
$kmEvent = null;             // イベントモードの案内(バナー)。無ければ null
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

/*
 * 管理アプリで配った表示調整の既定(2026-10-23、利用者の指示「表示調整が Website に配られ、既定になっているか」。lib/display-defaults.php)。
 * Main/map-style.js が読み、閲覧者が自分で変えていない項目の既定にする。配っていなければ出さない。読めなくても地図は出す
 */
$kmDisplayDefaults = null;
try {
    require_once __DIR__ . '/lib/display-defaults.php';
    $storedDefaults = km_display_defaults_stored(km_db());
    if ($storedDefaults !== null) {
        $webDefaults = km_display_defaults_for_web($storedDefaults);
        if ($webDefaults !== []) {
            $kmDisplayDefaults = json_encode($webDefaults, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        }
    }
} catch (Throwable $exception) {
    error_log('KosenMap home: display defaults: ' . $exception->getMessage());
}

/*
 * イベント中の来場者の記録(2026-10-15、利用者の指示。lib/test-mode.php)。
 * **ログインしている・していないに関わらず**、最初の画面で「情報の収集を許可します」(チェック済み)を出す。
 * 誰の記録かは残さない(アカウント欄は event:<イベントの番号>)。テスト用アカウントのときは出さない(あちらが記録する)。
 * 開くたびに印を立て直す。イベントが終わっていれば印は無く、API は断る。
 */
unset($_SESSION[KM_EVENT_COLLECT_SESSION_KEY]);
$kmEventCollect = null;
if ($kmEvent !== null && $kmTestMode === null) {
    try {
        if (km_event_collect_enabled(km_db())) {
            $collectSubject = km_event_collect_subject($kmEvent['ids'] ?? []);
            $_SESSION[KM_EVENT_COLLECT_SESSION_KEY] = ['subject' => $collectSubject, 'until' => time() + KM_TEST_MODE_SESSION_TTL];
            $kmEventCollect = ['key' => $collectSubject];
        }
    } catch (Throwable $exception) {
        error_log('KosenMap home: event collection check failed (not collected): ' . $exception->getMessage());
        unset($_SESSION[KM_EVENT_COLLECT_SESSION_KEY]);
        $kmEventCollect = null;
    }
}

/*
 * 履歴とブックマークの同期(2026-10-23。Main/user-history.js・api/user-map-data.php)。サインインしている人だけ印を渡す。
 * 展示の PC(テスト用アカウント)では使わない(来場者の間で履歴が見えないように)
 */
$kmUserMapKey = ($loggedIn && $kmTestMode === null && isset($claims->sub) && (string) $claims->sub !== '')
    ? substr(hash('sha256', 'km-user-map:' . (string) $claims->sub), 0, 16)
    : null;
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <?php if ($kmFocusRoom !== null): ?>
        <?php /* QR の部屋(上の $kmFocusRoom。32 字の 16 進だけ)。Main/app.js の focusRoomFromUrl が読む */ ?>
        <meta name="km-focus-room" content="<?= km_home_e($kmFocusRoom) ?>">
        <?php if ($kmFocusPoster !== null): ?>
            <meta name="km-focus-poster" content="<?= km_home_e($kmFocusPoster) ?>">
        <?php endif; ?>
    <?php endif; ?>
    <?php /* 共有のリンク(上の $kmFocusFrom など。32 字の 16 進か数字だけ)。Main/app.js の focusFromUrl が読む */ ?>
    <?php if (($kmFocusFrom !== null || $kmFocusFromPoi !== null) && ($kmFocusTo !== null || $kmFocusToPoi !== null)): ?>
        <?php if ($kmFocusFrom !== null): ?><meta name="km-focus-from" content="<?= km_home_e($kmFocusFrom) ?>"><?php else: ?><meta name="km-focus-from-poi" content="<?= km_home_e((string) $kmFocusFromPoi) ?>"><?php endif; ?>
        <?php if ($kmFocusTo !== null): ?><meta name="km-focus-to" content="<?= km_home_e($kmFocusTo) ?>"><?php else: ?><meta name="km-focus-to-poi" content="<?= km_home_e((string) $kmFocusToPoi) ?>"><?php endif; ?>
    <?php endif; ?>
    <?php if ($kmFocusPoi !== null): ?>
        <meta name="km-focus-poi" content="<?= km_home_e($kmFocusPoi) ?>">
    <?php endif; ?>
    <?php if ($kmTestMode !== null): ?>
        <?php /* テスト用アカウントの印と、自動で「始めから」にするまでの分(Main/test-mode.js が読む。一般の人には出さない) */ ?>
        <meta name="km-test-mode" content="1">
        <meta name="km-test-idle" content="<?= (int) $kmTestMode['idleMinutes'] ?>">
    <?php elseif ($kmEventCollect !== null): ?>
        <?php /* イベント中の来場者の記録。値は許可の覚え(localStorage)をイベントごとに分けるための名前 */ ?>
        <meta name="km-event-collect" content="<?= km_home_e($kmEventCollect['key']) ?>">
    <?php endif; ?>
    <?php $kmHasPosters = ($kmMapData['posters'] ?? []) !== []; ?>
    <?php if ($kmTestMode !== null || $kmEventCollect !== null || $kmHasPosters || $kmUserMapKey !== null): ?>
        <?php /* 記録の送信に使うトークン(api/test-events.php・ポスターの数を数える api/poster-event.php・履歴の同期 api/user-map-data.php が X-KM-CSRF で見る) */ ?>
        <meta name="km-csrf" content="<?= km_home_e(km_csrf_token()) ?>">
    <?php endif; ?>
    <?php if ($kmUserMapKey !== null): ?>
        <?php /* 履歴とブックマークの同期(Main/user-history.js)。値はアカウントごとの印(sub を鍵つきで縮めたもの。元の ID には戻せない)。
                 経路の履歴をアカウントに置くかの答えを、アカウントごとに覚えるためだけに使う */ ?>
        <meta name="km-user-key" content="<?= km_home_e($kmUserMapKey) ?>">
    <?php endif; ?>
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
         * ログイン・ログアウトの欄(2026-10-23、利用者の指示「上のバーをなくして、ログインは設定項目に入れて」)。
         * **1 回だけ組み立てて 1 か所に出す** —— ふだんは「ダウンロード・設定」の中(#km-auth-slot)、
         * 展示の PC(テストモード)は引き出しが無いので、左下の操作の横の小さな欄(.km-test-auth)。
         * 2 つ書いて片方を隠す形にはしない(片方だけ直したときに食い違う)。
         */
        ob_start();
        ?>
                    <div class="action-group-left" id="km-auth-links">
                        <?php if ($guestViewing && $guestUntil !== null): ?>
                            <?php // お試しの閲覧中であることと期限を隠さない(期限が来たら黙って氏名が消えるだけにしない) ?>
                            <span class="user-chip" role="status">お試し: <?= km_home_e((string) $guestName) ?> さん(<?= km_home_e(date('n/j H:i', $guestUntil)) ?> まで)</span>
                        <?php endif; ?>
                        <?php if ($loggedIn): ?>
                            <span class="user-chip">👤 <?= km_home_e($displayName) ?> さん</span>
                            <?php if ($teacherNeedsSignIn): ?>
                                <?php // サインインの期限が切れ、取り直せなかった教職員(W-50)。黙って氏名を消さず、理由を出す ?>
                                <span class="user-chip" role="status">教職員の表示を出すには、サインインし直してください</span>
                            <?php endif; ?>
                            <?php if ($kmTestMode !== null): ?>
                                <?php
                                // **展示の PC では来場者に押させない**(2026-10-19)。閉じた欄の中に、係の人の合言葉の欄を置く。
                                // 止めるのは sign-out.php(合言葉が合わなければ出さない)。ここは押し間違いを防ぐだけ
                                ?>
                                <details class="km-test-signout">
                                    <summary>係の人</summary>
                                    <form method="post" action="/sign-out.php">
                                        <?= km_csrf_field() ?>
                                        <label>
                                            <span>合言葉</span>
                                            <input type="password" name="signout_pin" maxlength="32" autocomplete="off" required>
                                        </label>
                                        <button type="submit" class="secondary-btn">ログアウト</button>
                                    </form>
                                </details>
                            <?php else: ?>
                                <?php // POST + CSRF でだけ出られる。GET だと他サイトのリンクで追い出せた ?>
                                <form method="post" action="/sign-out.php" class="km-signout-form">
                                    <?= km_csrf_field() ?>
                                    <button type="submit" class="secondary-btn logout-btn">ログアウト</button>
                                </form>
                            <?php endif; ?>
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
        <?php
        $kmAuthLinksHtml = (string) ob_get_clean();
        // ポスターの一覧(2026-10-21、lib/posters.php)。**いま出しているポスターがあるときだけ**左下に出す
        $kmPosterCount = count($kmMapData['posters'] ?? []);
        ?>

        <?php
        /*
         * 場所の検索(2026-10-23、利用者の指示「Google マップのように、場所を検索から始めて次にルート案内。一致率順に」)。
         * 上のバー(題字・ログイン・⚙️)の代わりに、左上に検索の窓を 1 つ置く。打つと一致率の順に候補が出て
         * (Main/search-rank.js)、選ぶとその場所へ寄せて、左の引き出しに詳細を出す(Main/km-sheet.js)。
         * 候補の組み立てと選んだあとは Main/app.js。下のチップは種類ごとにまとめて探す(以前のカテゴリー検索)
         */
        ?>
        <div id="km-searchbox" class="km-searchbox glass-panel" role="search">
            <span class="km-searchbox-logo" aria-hidden="true" title="KosenMap">📍</span>
            <input id="km-place-input" class="km-searchbox-input" type="search" placeholder="場所を検索" autocomplete="off" spellcheck="false"
                   aria-label="場所を検索" role="combobox" aria-expanded="false" aria-controls="km-place-results" aria-autocomplete="list">
            <button id="km-place-clear" type="button" class="km-searchbox-btn" title="消す" aria-label="検索の言葉を消す" hidden>&times;</button>
            <button id="km-route-open" type="button" class="km-searchbox-btn km-searchbox-route" title="経路を調べる" aria-label="経路を調べる" aria-controls="search-panel">🧭</button>
            <ul id="km-place-results" class="km-search-results" role="listbox" aria-label="検索の候補" hidden></ul>
        </div>
        <div id="km-category-chips" class="km-chips" role="toolbar" aria-label="種類でまとめて探す">
            <button type="button" class="km-chip" data-km-category="WC">🚻 トイレ</button>
            <button type="button" class="km-chip" data-km-category="stairs">🏃 階段・EV</button>
            <button type="button" class="km-chip" data-km-category="room">📖 教室</button>
            <button type="button" class="km-chip" data-km-category="facility">🏢 建物</button>
            <?php if ($kmEvent !== null): ?>
                <button type="button" class="km-chip" data-km-category="event">🎪 イベント</button>
            <?php endif; ?>
        </div>
        <?php
        /*
         * イベントモードの案内の帯(.km-event-banner)は**出さない**(2026-10-23、利用者の指示「変な場所に配置されているので表示されないように」)。
         * 検索の窓・種類のチップと重なっていた。イベント中であることは種類のチップの「🎪 イベント」で分かる。
         * 帯にあった「記録の設定」は「ダウンロード・設定」の中へ移した(下の #km-event-section)
         */
        ?>

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
                        <?php
                        /*
                         * 出発地・目的地(2026-10-23 から一致率順の候補。Main/app.js の attachSearch)。
                         * 以前のブラウザの候補(datalist)は、打った文字の並び順でしか出せなかった。
                         * ⇅ は入れ替え
                         */
                        ?>
                        <div class="input-group km-route-inputs">
                            <div class="km-route-field">
                                <input type="text" id="start-input" placeholder="出発地(いまいる場所)" autocomplete="off" spellcheck="false" aria-label="出発地"
                                       role="combobox" aria-expanded="false" aria-controls="km-start-results" aria-autocomplete="list">
                                <ul id="km-start-results" class="km-search-results" role="listbox" aria-label="出発地の候補" hidden></ul>
                            </div>
                            <div class="km-route-field">
                                <input type="text" id="end-input" placeholder="目的地" autocomplete="off" spellcheck="false" aria-label="目的地"
                                       role="combobox" aria-expanded="false" aria-controls="km-end-results" aria-autocomplete="list">
                                <ul id="km-end-results" class="km-search-results" role="listbox" aria-label="目的地の候補" hidden></ul>
                            </div>
                            <button type="button" id="km-route-swap" class="km-route-swap" title="出発地と目的地を入れ替える" aria-label="出発地と目的地を入れ替える">⇅</button>
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
                    <?php /* カテゴリー検索は検索の窓の下のチップへ移した(2026-10-23)。結果は左の引き出しに出す */ ?>
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
            <?php
            /*
             * ⚙️ ダウンロード・設定と 🖼️ ポスターも、上のバーからここへ移した(2026-10-23、利用者の指示)。
             * 「経路」は出発地・目的地の欄(#search-panel)を開け閉てする(場所の検索は上の窓)
             */
            ?>
            <?php if ($kmTestMode === null): ?>
                <button id="info-panel-toggle" type="button" class="km-action-btn" title="ダウンロード・設定" aria-label="ダウンロード・設定">
                    <span class="km-action-icon" aria-hidden="true">⚙️</span>
                    <span class="km-action-label">設定</span>
                </button>
            <?php endif; ?>
            <?php if ($kmPosterCount > 0): ?>
                <a href="/posters.php" class="km-action-btn km-poster-btn" title="ポスター(<?= $kmPosterCount ?> 枚)" aria-label="ポスターの一覧(<?= $kmPosterCount ?> 枚)">
                    <span class="km-action-icon" aria-hidden="true">🖼️</span>
                    <span class="km-action-label">ポスター</span>
                </a>
            <?php endif; ?>
            <button
                id="mobile-search-toggle"
                type="button"
                class="km-action-btn"
                title="出発地・目的地の入力"
                aria-controls="search-panel"
                aria-expanded="true"
            >
                <span class="km-action-icon" aria-hidden="true">🧭</span>
                <span class="km-action-label">経路</span>
            </button>
        </div>
        <?php if ($kmTestMode !== null): ?>
            <?php /* 展示の PC はダウンロード・設定が無いので、係の人のログアウトはここ(左下の操作の上) */ ?>
            <div class="km-test-auth glass-panel"><?= $kmAuthLinksHtml ?></div>
        <?php endif; ?>

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
                <?php
                /*
                 * 並べるのは**校舎の階**(2026-10-21 から地図データの floors から作る。lib/map-floors.php)。
                 * 管理画面で足した階もここに出る。建物の中(building)は屋外の建物の「中へ」から開くので並べない。
                 * 組み込みの階は今までの文字(外・1F…)と読み上げの名前(外全体図・1階…)のまま。
                 * 地図データが無いとき(錠・DB の不調)は組み込みの 6 つ。
                 */
                require_once __DIR__ . '/lib/map-floors.php';
                $kmRailFloors = [];
                foreach (($kmMapData['floors'] ?? []) as $railFloor) {
                    if (($railFloor['kind'] ?? 'campus') === 'campus') {
                        $kmRailFloors[] = ['id' => (string) $railFloor['id'], 'label' => (string) $railFloor['label']];
                    }
                }
                if ($kmRailFloors === []) {
                    $kmRailFloors = array_map(static fn (array $f): array => ['id' => $f['id'], 'label' => $f['label']], KM_MAP_CAMPUS_FLOORS);
                }
                $kmRailTitles = array_column(KM_MAP_CAMPUS_FLOORS, 'title', 'id');
                $kmRailTexts = array_column(KM_MAP_CAMPUS_FLOORS, 'label', 'id');
                ?>
                <?php foreach ($kmRailFloors as $railFloor): ?>
                    <?php
                    $railText = $kmRailTexts[$railFloor['id']] ?? $railFloor['label'];
                    $railTitle = $kmRailTitles[$railFloor['id']] ?? $railFloor['label'];
                    ?>
                    <button class="floor-btn<?= $railFloor['id'] === KM_MAP_FLOOR_INITIAL ? ' active' : '' ?>" data-floor="<?= km_home_e($railFloor['id']) ?>" aria-label="<?= km_home_e($railTitle) ?>" title="<?= km_home_e($railTitle) ?>"><?= km_home_e($railText) ?></button>
                <?php endforeach; ?>
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
            <?php /* 経路の共有(2026-10-23、利用者の指示)。/?from=&to= のリンク(Main/app.js の shareRoute) */ ?>
            <button id="km-route-share" type="button" class="km-route-share" title="この経路を共有" aria-label="この経路を共有">↗</button>
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
         *
         * テスト用アカウント(展示の PC)では**書かない**(2026-10-19、利用者の指示)。開く手段を隠すだけでは、
         * 中の APK やアカウント設定への導線がページに残る。
         */
        ?>
        <?php if ($kmTestMode === null): ?>
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
                 * ログイン(2026-10-23 から PC でもここ。上のバーを外した)。中身は上で 1 回だけ組み立てた $kmAuthLinksHtml
                 */
                ?>
                <div class="info-section" id="km-auth-section">
                    <h4>ログイン</h4>
                    <div id="km-auth-slot"><?= $kmAuthLinksHtml ?></div>
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
                <?php if ($kmEvent !== null): ?>
                    <?php /* イベント中の案内(2026-10-23。地図の上の帯をやめたので、案内の文・くわしく・記録の設定はここ) */ ?>
                    <div class="info-section" id="km-event-section">
                        <h4>🎪 <?= km_home_e(implode(' / ', $kmEvent['names'])) ?></h4>
                        <p class="info-text"><?= km_home_e($kmEvent['bannerText'] ?? 'イベント中です。部屋の名前が変わっていたり、通れない道や出していない場所があります。') ?></p>
                        <?php if (!empty($kmEvent['bannerUrl'])): ?>
                            <?php /* リンク先は lib/map-events.php がサイト内の絶対パスだけに検証済み */ ?>
                            <a class="secondary-btn" href="<?= km_home_e($kmEvent['bannerUrl']) ?>">くわしく</a>
                        <?php endif; ?>
                        <?php if ($kmEventCollect !== null): ?>
                            <?php /* 許可をあとから変えられるように(Main/test-mode.js が #km-collect-bar を開き直す) */ ?>
                            <button type="button" class="secondary-btn km-collect-open" data-km-collect-open>記録の設定</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>                <div id="km-unlock-section"></div>
                <div class="info-section">
                    <h4>はじめに</h4>
                    <p class="info-text">
                        初めて開いたときに出した案内(利用規約・メールについて)を、もう一度読めます。
                    </p>
                    <button type="button" class="secondary-btn" data-km-welcome-open>はじめにを読む</button>
                    <?php /* 初回に出す操作の案内(Main/tutorial.js)をもう一度 */ ?>
                    <button type="button" class="secondary-btn" data-km-tutorial-open>操作の案内を見る</button>
                </div>
            </div>
        </aside>
        <?php endif; ?>
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

    <?php if ($kmDisplayDefaults !== null): ?>
        <?php /* 配った表示調整の既定(Main/map-style.js が読む。**map-style.js より前**に置く。nonce は km-map-data と同じ理由) */ ?>
        <script type="application/json" id="km-display-defaults"<?= km_csp_nonce_attr() ?>><?= $kmDisplayDefaults ?></script>
    <?php endif; ?>

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

    <?php if ($kmTestMode !== null): ?>
    <?php
    /*
     * テスト用アカウント(展示の PC。2026-10-15、利用者の指示)。右上に縦に積む。
     * どちらも「－」で小さくできる(見出しだけ残る)。「始めから」は小さくしても押せるよう見出しに置く。
     * 小さくした状態は覚えない —— 「始めから」で次の人には開いた状態で渡す。開け閉ては Main/test-mode.js。
     */
    ?>
    <div class="km-test-stack">
        <section id="km-test-card" class="km-test-panel km-test-card" aria-labelledby="km-test-card-title">
            <div class="km-test-head">
                <h2 id="km-test-card-title" class="km-test-title">🧪 テスト中</h2>
                <button type="button" class="km-test-min" data-km-test-min aria-expanded="true" aria-label="テスト中の札を小さくする">－</button>
            </div>
            <div class="km-test-body">
                <p class="km-test-note">この PC での操作(検索した語・選んだ場所など)を、改善のために記録しています。お名前などは記録しません。</p>
                <?php if ($kmTestMode['surveyQr'] !== null): ?>
                    <p class="km-test-survey-lead">アンケートにご協力ください</p>
                    <?php /* QR は lib/qr.php が作る SVG(中身は URL だけ。管理画面で https に限って確かめてある) */ ?>
                    <div class="km-test-qr"><?= $kmTestMode['surveyQr'] ?></div>
                    <a class="km-test-url" href="<?= km_home_e($kmTestMode['surveyUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= km_home_e($kmTestMode['surveyUrl']) ?></a>
                <?php endif; ?>
            </div>
        </section>

        <section id="km-test-guide" class="km-test-panel km-test-guide" aria-labelledby="km-test-guide-title">
            <div class="km-test-head">
                <h2 id="km-test-guide-title" class="km-test-title">操作方法</h2>
                <button type="button" id="km-test-restart" class="km-test-restart">↺ 始めから</button>
                <button type="button" class="km-test-min" data-km-test-min aria-expanded="true" aria-label="操作方法を小さくする">－</button>
            </div>
            <ol class="km-test-body km-test-steps">
                <?php foreach ($kmTestMode['guide'] as $line): ?>
                    <li><?= km_home_e($line) ?></li>
                <?php endforeach; ?>
            </ol>
            <div class="km-test-body">
                <?php /* 押す所を順に光らせる案内(Main/tutorial.js)。来場者ごとに最初は自動で出る */ ?>
                <button type="button" class="secondary-btn km-test-tour" data-km-tutorial-open>📍 画面で順に案内する</button>
            </div>
        </section>
    </div>
    <?php endif; ?>

    <?php if ($kmEventCollect !== null): ?>
    <?php
    /*
     * イベント中の情報の収集の許可(2026-10-15、利用者の指示)。**チェック済みで出す。**
     * 最初の画面で 1 回だけ出し(イベントごとに覚える)、上の帯の「記録の設定」から開き直せる。
     * 決めるまでは送らずに手元に溜め、外して OK なら捨てる(Main/test-mode.js)。
     * JS が動かない環境では出さない(そもそも記録しない)ので、hidden で置く。
     */
    ?>
    <div id="km-collect-bar" class="km-collect-bar" role="region" aria-labelledby="km-collect-title" hidden>
        <p id="km-collect-title" class="km-collect-title">📊 情報の収集について</p>
        <p class="km-collect-text">イベント期間中は、案内をよくするために、この画面での操作(検索した語・選んだ場所・開いた部屋・階の切り替え)を記録します。お名前やログインの情報は記録しません。</p>
        <div class="km-collect-actions">
            <label class="km-collect-check"><input type="checkbox" id="km-collect-consent" checked> 情報を収集するのを許可します</label>
            <button type="button" id="km-collect-ok" class="primary-btn km-collect-ok">OK</button>
        </div>
    </div>
    <?php endif; ?>

    <?php
    /*
     * チュートリアルが終わったときの知らせ(2026-10-21、利用者の指示。lib/posters.php)。
     * 開け閉ては Main/tutorial-end.js。広告(地点に置いていないポスター)があれば必ず 1 件を先に出し(どれにするかは data-ad-weight の重み。全部同じ)、閉じたらこれを出す。
     * 本文・アンケートの URL は管理画面「ポスター・広告」で変える。QR は外のサービスを使わずここで作る(lib/qr.php)。
     * 読めなければ既定の本文だけで出す(fail open)。
     */
    $kmTutorialEnd = ['text' => '', 'surveyUrl' => '', 'phoneQr' => true];
    try {
        require_once __DIR__ . '/lib/posters.php';
        $kmTutorialEnd = km_tutorial_end_config(km_db());
    } catch (Throwable $exception) {
        error_log('KosenMap home: tutorial end config failed (default text): ' . $exception->getMessage());
        $kmTutorialEnd['text'] = KM_TUTORIAL_END_TEXT_DEFAULT;
    }
    $kmEndQr = static function (string $url, string $label): string {
        require_once __DIR__ . '/lib/qr.php';
        return str_replace('アクセスコードの QR', km_home_e($label), km_qr_svg(km_qr_matrix($url), 4, 2));
    };
    $kmSiteUrl = km_site_url(null, '/');
    ?>
    <div id="km-tutorial-end" class="km-end-scrim" data-ad-weight="<?= (int) KM_POSTER_AD_WEIGHT ?>" hidden>
        <div class="km-end-card" role="dialog" aria-modal="true" aria-labelledby="km-end-title">
            <p id="km-end-title" class="km-end-title">🎉 チュートリアルが終わりました</p>
            <p class="km-end-text"><?= km_home_e($kmTutorialEnd['text']) ?></p>
            <?php if ($kmTutorialEnd['surveyUrl'] !== ''): ?>
                <div class="km-end-row">
                    <div class="km-end-qr"><?= $kmEndQr($kmTutorialEnd['surveyUrl'], 'アンケートの QR') ?></div>
                    <p>このページのアンケートを募集中です。<a href="<?= km_home_e($kmTutorialEnd['surveyUrl']) ?>" target="_blank" rel="noopener noreferrer">こちら</a>から答えられます(スマホで QR を読んでも開けます)。</p>
                </div>
            <?php endif; ?>
            <?php if ($kmTutorialEnd['phoneQr']): ?>
                <div class="km-end-row">
                    <div class="km-end-qr"><?= $kmEndQr($kmSiteUrl, '地図の QR') ?></div>
                    <p>スマホでもマップを見ることができます。QR を読むか、<span class="km-end-url"><?= km_home_e(preg_replace('#^https?://#', '', rtrim($kmSiteUrl, '/'))) ?></span> を開いてください。</p>
                </div>
            <?php endif; ?>
            <button type="button" class="primary-btn km-end-close" data-km-end-close>閉じる</button>
        </div>
    </div>

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
    <?php /* 左の引き出しと、検索の一致率(2026-10-23)。どちらも app.js・map-tuning.js より先に読む */ ?>
    <script defer src="<?= km_home_e(km_public_asset('Main/km-sheet.js')) ?>"></script>
    <script defer src="<?= km_home_e(km_public_asset('Main/search-rank.js')) ?>"></script>
    <script defer src="<?= km_home_e(km_public_asset('Main/map-style.js')) ?>"></script>
    <script defer src="<?= km_home_e(km_public_asset('Main/map-tuning.js')) ?>"></script>
    <script defer src="<?= km_home_e(km_public_asset('Main/label-layout.js')) ?>"></script>
    <?php if ($kmTestMode === null): ?>
        <?php /* 履歴とブックマーク(2026-10-23)。**app.js より先に**読む(地点の詳細の ☆ を出すかを app.js が見る)。展示の PC では読まない */ ?>
        <script defer src="<?= km_home_e(km_public_asset('Main/user-history.js')) ?>"></script>
    <?php endif; ?>
    <?php if ($kmTestMode !== null || $kmEventCollect !== null): ?>
        <?php /* テスト用アカウントとイベント中だけ。**app.js より先に**読み、window.kmTestLog を用意しておく(app.js は在れば呼ぶ) */ ?>
        <script defer src="<?= km_home_e(km_public_asset('Main/test-mode.js')) ?>"></script>
    <?php endif; ?>
    <script defer src="<?= km_home_e(km_public_asset('Main/app.js')) ?>"></script>
    <?php /* 企画のスケジュールの一覧(2026-10-23。lib/event-schedule.php)。一般に出す行があるときだけ「予定」を出す */ ?>
    <script defer src="<?= km_home_e(km_public_asset('Main/event-schedule.js')) ?>"></script>
    <script defer src="<?= km_home_e(km_public_asset('Main/panel.js')) ?>"></script>
    <script defer src="<?= km_home_e(km_public_asset('Main/welcome.js')) ?>"></script>
    <?php /* 初回の操作案内(2026-10-15)。「はじめに」を閉じてから・地図が出てから始める */ ?>
    <script defer src="<?= km_home_e(km_public_asset('Main/tutorial.js')) ?>"></script>
    <?php /* チュートリアルの終わりに、広告(確率)と「チュートリアルが終わりました」(2026-10-21) */ ?>
    <?php /* ポスター・広告の表示回数とリンクの人数を数える(2026-10-21)。tutorial-end.js より先に読む(window.kmPosterStat を使う) */ ?>
    <script defer src="<?= km_home_e(km_public_asset('Main/poster-stats.js')) ?>"></script>
    <script defer src="<?= km_home_e(km_public_asset('Main/tutorial-end.js')) ?>"></script>

</body>

</html>