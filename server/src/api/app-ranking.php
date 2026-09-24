<?php

declare(strict_types=1);

/**
 * ランキングの取得と記録。
 *
 *   GET  ?year=2026            3種類のランキングを返す
 *   POST {"visits":[…], "searches":[…], "participate":true}
 *                              まとめて積む
 *   POST {"action":"forget"}   利用者ランキングから自分の記録を消す
 *
 * **「よく行かれた場所」と「調べられた語」は個人を紐付けない。** ログインの有無に
 * かかわらず回数だけ積む。
 *
 * **「利用者ランキング」は participate:true を送ってきたときだけ記録する。**
 * 既定は参加しない。参加をやめたら action:forget で消せる。
 * ここを緩めると、行動履歴を本人の同意なくサーバーへ溜めることになる。
 *
 * **GET の `users[].userId` は Logto の sub ではない**(年ごとの鍵つきの印)。
 * **GET の `searches` は、3つ以上の出どころから来た語だけ**。どちらも lib/app-ranking.php の冒頭を参照。
 * POST は nginx でも回数を絞っている(`location = /api/app-ranking.php`)。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../logto_config.php';
require_once __DIR__ . '/../logto_guard.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/app-ranking.php';
// 出どころ(IP)の読み方は総当たり対策と同じ1本を使う(X-Real-IP。偽装できない)
require_once __DIR__ . '/../lib/map-rate-limit.php';
// 表示名を引く。logto_guard.php も読むが、あちらは logto_principal() の**中**で
// 条件付きに読むので、Bearer が無い経路では定義されない。ここで明示的に読む。
require_once __DIR__ . '/../lib/logto-management.php';

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/app-ranking.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

// ---------------------------------------------------------------- 記録

if ($method === 'POST') {
    // 64KB を超える本文は 413(api_bootstrap.php)。差分は数 KB に収まる
    $input = km_api_json_body();

    $principal = logto_optional_principal();
    $subject = is_array($principal) ? trim((string) ($principal['subject'] ?? '')) : '';

    if (($input['action'] ?? '') === 'forget') {
        // 消すのは本人だけ。userId は受け取らない。
        if ($subject === '') {
            respond(['success' => false, 'message' => 'ログインしてから操作してください。'], 401);
        }
        km_ranking_forget_user($pdo, $subject);
        respond(['success' => true, 'forgotten' => true]);
    }

    $participate = ($input['participate'] ?? false) === true;
    $recordAs = $participate && $subject !== '' ? $subject : null;

    /*
     * 表示名は **Logto から引く**。`$principal['username']` は使わない。
     *
     * 理由が2つある:
     *
     * 1. **アクセストークンに profile 系のクレームが載らない。** guard が組み立てる
     *    `username` は実際には `sub`(UUID)へ落ちており、そのまま出すと
     *    ランキングに UUID が並ぶ
     * 2. **`username` の並びには `email` が入っている。** ランキングは来場者にも
     *    見えるので、メールアドレスが公開の画面へ出る経路を作らない
     *
     * 往復は増えない —— 停止判定が既に users/{id} を取っており、
     * km_logto_user_display_name() は同じ応答(60秒キャッシュ)から読む。
     * 名前が無い利用者は null のままで、一覧では「名前なし」と出る。
     */
    $displayName = $recordAs !== null ? km_logto_user_display_name($recordAs) : null;

    try {
        km_ranking_record(
            $pdo,
            is_array($input['visits'] ?? null) ? $input['visits'] : [],
            is_array($input['searches'] ?? null) ? $input['searches'] : [],
            // **参加の意思と、ログインしていることの両方が要る。**
            $recordAs,
            $displayName,
            // 語を一覧に出すかの判定に使う。IP そのものは保存されない
            km_map_rate_limit_ip()
        );
    } catch (Throwable $exception) {
        error_log('api/app-ranking.php record failed: ' . $exception->getMessage());
        respond(['success' => false, 'message' => '記録できませんでした。'], 500);
    }

    respond(['success' => true, 'recorded' => true]);
}

// ---------------------------------------------------------------- 取得

if ($method !== 'GET') {
    respond(['success' => false, 'message' => 'GET か POST を使ってください。'], 405);
}

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
// 変な年で表を舐めさせない。
if ($year < 2000 || $year > (int) date('Y') + 1) {
    $year = (int) date('Y');
}

respond([
    'success' => true,
    'year' => $year,
    'years' => km_ranking_years($pdo),
    'places' => km_ranking_places($pdo, $year),
    'searches' => km_ranking_queries($pdo, $year),
    'users' => km_ranking_users($pdo, $year),
]);
