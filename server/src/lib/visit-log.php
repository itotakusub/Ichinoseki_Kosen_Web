<?php

declare(strict_types=1);

/**
 * 訪問者の記録(2026-10-06。2026-10-05 の計画 B、利用者の指示「外国からのアクセスを確かめる」)。
 *
 * **書くのは nginx、まとめるのはブラウザ。** PHP がするのは 2 つだけ:
 *
 *   1. 公開のページが応答ヘッダ X-KM-Viewer に閲覧者の種別を出す(km_visit_mark_viewer)。
 *      nginx がそれをログに書き、利用者には返さない(nginx/km/proxy-web.conf の proxy_hide_header)。
 *        anon            … サインインしていない
 *        guest:<数>      … お試しの閲覧リンクの仮アカウント(管理画面の一覧の番号)
 *        user:<印>       … サイトにサインインした人。Logto の sub を**鍵つきの印**にする(元へ戻せない)
 *        app:<印>        … アプリ(トークンで地図を取った人)。印の作り方は user と同じ
 *   2. 管理画面へ、指定した日数分の**生の行をそのまま**渡す(km_visit_log_send。admin/api/visit-log.php)。
 *
 * 置き場は web の中の /var/www/visitlog(ホストの run/visitlog を読み取り専用で。compose の web の volumes)。
 * 30 日を過ぎたファイルはホストの cron が消す(scripts/host-updates-setup.sh の版 8)。
 */

require_once __DIR__ . '/app-secret.php';

const KM_VISIT_LOG_DIR = '/var/www/visitlog';

/** 一度に渡す上限。超えたら新しい方から詰めて、古い方を切る(管理者のブラウザが読む量も抑える)。 */
const KM_VISIT_LOG_MAX_BYTES = 8 * 1024 * 1024;

/** 管理画面が選べる日数(ファイルを残すのは 30 日)。 */
const KM_VISIT_LOG_MAX_DAYS = 30;

/**
 * 閲覧者の種別をヘッダで出す。**出力が始まる前に呼ぶこと。** 失敗しても画面は止めない(記録が粗くなるだけ)。
 *
 * @param string      $kind 'anon' | 'guest' | 'user' | 'app'
 * @param string|null $id   guest は仮アカウントの番号、user・app は Logto の sub(ここで印に変える)
 */
function km_visit_mark_viewer(string $kind, ?string $id = null): void
{
    if (headers_sent()) {
        return;
    }
    $value = 'anon';
    try {
        if ($kind === 'guest' && $id !== null && ctype_digit($id)) {
            $value = 'guest:' . $id;
        } elseif (($kind === 'user' || $kind === 'app') && $id !== null && $id !== '') {
            require_once __DIR__ . '/db.php';
            // 用途ごとに鍵を分ける(lib/app-secret.php)。sub そのものはログに残さない
            $value = $kind . ':' . substr(km_app_keyed_hash(km_app_secret(km_db(), 'visit'), 'visit-viewer', $id), 0, 12);
        }
    } catch (Throwable $exception) {
        error_log('km_visit_mark_viewer: ' . $exception->getMessage());
        // 印を作れなくても、種別だけは残す
        $value = in_array($kind, ['guest', 'user', 'app'], true) ? $kind : 'anon';
    }
    header('X-KM-Viewer: ' . $value);
}

/**
 * 直近 $days 日分のファイル(古い順)。名前は nginx が付ける visit-YYYY-MM-DD.jsonl だけを拾う。
 *
 * @return list<string> フルパス
 */
function km_visit_log_files(int $days, ?string $dir = null): array
{
    $dir ??= KM_VISIT_LOG_DIR;
    $days = max(1, min(KM_VISIT_LOG_MAX_DAYS, $days));
    $files = [];
    $today = new DateTimeImmutable('today');
    for ($i = $days - 1; $i >= 0; $i--) {
        $path = $dir . '/visit-' . $today->modify("-{$i} days")->format('Y-m-d') . '.jsonl';
        if (is_file($path) && is_readable($path)) {
            $files[] = $path;
        }
    }

    return $files;
}

/**
 * どこから送るかを決める。**PHP では行を読み解かない**(メモリを使わない)。
 * 上限を超えるときは、新しい方を残して古い方を切る(切れ目は km_visit_log_send が行の頭に合わせる)。
 *
 * @param list<string> $files 古い順
 * @return array{plan: list<array{0:string,1:int}>, bytes:int, truncated:bool}
 */
function km_visit_log_plan(array $files, int $maxBytes = KM_VISIT_LOG_MAX_BYTES): array
{
    // 新しい方から数えて、どこから送るかを決める([パス, 読み始める位置])
    $plan = [];
    $remaining = $maxBytes;
    $truncated = false;
    foreach (array_reverse($files) as $path) {
        $size = (int) @filesize($path);
        if ($remaining <= 0) {
            $truncated = true;
            break;
        }
        if ($size <= $remaining) {
            array_unshift($plan, [$path, 0]);
            $remaining -= $size;
            continue;
        }
        array_unshift($plan, [$path, $size - $remaining]);
        $remaining = 0;
        $truncated = true;
    }

    return ['plan' => $plan, 'bytes' => $maxBytes - $remaining, 'truncated' => $truncated];
}

/**
 * 計画どおりに書き出す。途中から読むファイルは、最初の改行までを捨てる(行の途中から始めない)。
 *
 * @param list<array{0:string,1:int}> $plan
 */
function km_visit_log_send(array $plan): void
{
    foreach ($plan as [$path, $offset]) {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            continue;
        }
        if ($offset > 0) {
            // 1 つ前が改行なら、ちょうど行の頭。そうでなければ、その行の残りを捨てる
            fseek($handle, $offset - 1);
            if (fgetc($handle) !== "\n") {
                fgets($handle);
            }
        }
        fpassthru($handle);
        fclose($handle);
    }
}
