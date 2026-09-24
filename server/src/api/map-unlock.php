<?php

declare(strict_types=1);

/**
 * 地図の occupant_name を解除するためのパスワード確認エンドポイント。
 * 成功すると $_SESSION['km_map_unlocked'] = true になり、ブラウザセッション中は保持される。
 */

// Cookie 属性は lib/session.php に一本化(同じ内容を書き写さない)
require_once __DIR__ . '/../lib/session.php';
km_session_start();

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/map-access.php';
require_once __DIR__ . '/../lib/map-rate-limit.php';
require_once __DIR__ . '/../lib/admin-log.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function km_map_unlock_respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    km_map_unlock_respond(['success' => false, 'message' => 'POST で送信してください。'], 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
$password = is_array($input) ? (string) ($input['password'] ?? '') : '';

if ($password === '') {
    km_map_unlock_respond(['success' => false, 'message' => 'パスワードを入力してください。'], 400);
}

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('map-unlock.php db failed: ' . $exception->getMessage());
    km_map_unlock_respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

$config = km_map_access_config();

/*
 * **どちらの錠でもパスワードを受け付ける。**
 *
 * 以前は氏名の mode だけを見ていたので、地図側だけ password にすると
 * 「入力欄は出るのに 409 で断られる」ことになる。錠は2つ、パスワードは1つ。
 */
if (!km_map_password_requested($config)) {
    km_map_unlock_respond(['success' => false, 'message' => 'パスワード解除は現在有効ではありません。'], 409);
}

/*
 * **イベント中は、氏名だけを目的とした解除を断る。**
 *
 * km_map_data() 側で氏名を伏せているので、通しても氏名は出ない。
 * 「解除できたのに名前が出ない」は利用者から見て故障にしか見えないので、入口で断る。
 *
 * ただし**地図そのものに錠が掛かっているときは断らない。** そちらは解除すれば
 * 実際に地図が見えるようになるので、断ると「イベント中は地図が見られない」という
 * 別の壊れ方になる —— イベント中こそ来場者が地図を見たい。
 */
require_once __DIR__ . '/../lib/map-events.php';
if ($config['mapMode'] !== 'password' && km_map_event_overlay($pdo)['hideOccupantNames']) {
    km_map_unlock_respond([
        'success' => false,
        'message' => 'イベント期間中は教職員氏名を表示できません。',
    ], 409);
}

/*
 * 錠は 'web'。アプリのアクセスコード('app')とは別に数える(lib/map-rate-limit.php)。
 *
 * **照合の前に1回数える。** 以前は「ロック中か読む → 照合 → 失敗なら記録」で、
 * 読むだけの判定は枠を確保しないため、同時に送れば 8 回を超えて試せた(critic#0)。
 * 数えるのは 409 で断る分の後 —— 解除が無効なときの要求で回数を減らさない。
 */
if (!km_map_unlock_attempt($pdo, 'web')) {
    km_map_unlock_respond(['success' => false, 'message' => '試行回数が多すぎます。しばらく待ってから再試行してください。'], 429);
}

if ($config['passwordHash'] === null || !password_verify($password, $config['passwordHash'])) {
    // 回数は km_map_unlock_attempt() で数え済み。ここで重ねて記録しない
    // 公開側なので $KM_USER は無い。誰がではなく「どこから何回失敗したか」が知りたい情報なので、
    // 実行者 null / IP のみで足りる。
    km_admin_log_record('system', 'map.unlock_failed');
    km_map_unlock_respond(['success' => false, 'message' => 'パスワードが正しくありません。'], 401);
}

km_map_clear_unlock_failures($pdo, 'web');

/*
 * **権限が上がる瞬間にセッション ID を作り直す(セッション固定への対策)。**
 *
 * これが無いと、攻撃者が先に自分の知っているセッション ID を相手のブラウザに
 * 持たせておき、相手が正しいパスワードで解除した時点で **同じ ID を使って
 * 教職員氏名を読める**。ID を作り直せば、相手が知っている古い ID は無効になる。
 *
 * true を渡して古いセッションファイルも消す(残しておくと同じ問題が残る)。
 */
session_regenerate_id(true);
$_SESSION['km_map_unlocked'] = true;

km_map_unlock_respond(['success' => true]);
