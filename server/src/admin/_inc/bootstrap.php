<?php

/**
 * 管理画面の共通ブートストラップ。
 *
 * ページの先頭で define('KM_ADMIN', true) してから require してください。
 * 認証が要るページは guard.php を require します(こちらを内部で読み込みます)。
 */

declare(strict_types=1);

if (!defined('KM_ADMIN')) {
    http_response_code(404);
    exit;
}

/*
 * 表示に使うタイムゾーン。
 *
 * 本体は docker/php/99-timezone.ini の date.timezone で、これはその保険。ini が読まれない
 * 環境(コンテナ外で動かした、bind mount が反映されていない等)でも管理画面の時刻表示が
 * 狂わないようにする。php:8.4-apache の既定は UTC で、**PHP は TZ 環境変数を見ない**ため、
 * 設定漏れは「9時間ずれた画面」として静かに現れる — 実際に本番でそうなっていた。
 */
// TZ に Windows 形式("Tokyo Standard Time" 等)が入っていると set は false を返して
// 警告を出す。警告が出力へ混ざると JSON 応答やヘッダーを壊すので、黙って既定へ落とす。
if (!@date_default_timezone_set((string) (getenv('TZ') ?: 'Asia/Tokyo'))) {
    date_default_timezone_set('Asia/Tokyo');
}

/**
 * リリース版。表示に使うのはフッターだけなので、出典はここ1箇所に置く。
 * (画面ごとに書くと必ずどこかが古くなる)
 */
const KM_VERSION = '1.0.3';

/*
 * 公開 URL の組み立て(ホスト名・ポート)は lib/site.php に集約してある。
 * 管理画面のどのページからも使えるよう、ここで読み込んでおく。
 */
require_once dirname(__DIR__, 2) . '/lib/site.php';

/*
 * CSP。**出力が始まる前に送る**必要があるので、ページ本体より先に読み込むこの場所で送る。
 * 管理画面は AdminLTE を読むので admin プロファイル(reduce-motion 用 <style> の
 * ハッシュと、チャット・死活監視が使う WebSocket の接続先を含む)。
 */
require_once dirname(__DIR__, 2) . '/lib/csp.php';
km_csp_send('admin');

/**
 * 管理画面が要求する API リソース。Logto の API resource indicator と一致させる。
 *
 * **Logto Console に登録した文字列とバイト単位で一致していないと通らない。**
 * env で上書きできるようにしてあるのは、ドメイン移行のときに Logto 側の登録を
 * 変えるタイミングと、こちらの APP_URL を変えるタイミングがずれるため
 * (logto-client.php の LOGTO_API_RESOURCE と同じ値を見る)。
 */
define('KM_ADMIN_RESOURCE', rtrim((string) (getenv('LOGTO_API_RESOURCE') ?: km_site_url(null, '/api')), '/'));

/** この scope を持つユーザーだけが管理画面に入れる。role kosenmap-admin に紐づく。 */
const KM_ADMIN_SCOPE = 'admin:users:read';

/**
 * **状態を変える操作**(管理画面の POST と、phpMyAdmin などへのゲート)に要る scope。
 *
 * 以前は KM_ADMIN_SCOPE(read)1つで、テーブル削除も地図の配信も DB の直接操作も
 * できていた(診断 server-authz#2)。いまの kosenmap-admin は4スコープを全部持つので
 * 挙動は変わらないが、将来「閲覧だけ」のロールに read だけを渡したとき、
 * 名前どおり**見るだけ**になるようにする。Bearer 側(logto_guard.php の is_admin)は
 * もともと write も要求している。
 */
const KM_ADMIN_WRITE_SCOPE = 'admin:users:write';

/**
 * 書き込みの権限が無い POST を断って終わる。
 *
 * admin/api/ の下は fetch から呼ばれるので JSON、それ以外は短い HTML で返す。
 * 403.php へ飛ばさないのは、あちらが「管理者の権限が無い」と案内するページだから。
 */
function km_admin_forbid_write(): never
{
    http_response_code(403);
    header('Cache-Control: no-store');
    $message = 'この操作には書き込みの権限(' . KM_ADMIN_WRITE_SCOPE . ')が必要です。';

    if (str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/api/')) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo <<<HTML
        <!doctype html>
        <html lang="ja">
        <head><meta charset="UTF-8"><title>権限がありません</title></head>
        <body>
          <h1>変更できません</h1>
          <p>{$escaped}</p>
          <p><a href="./index.php">ダッシュボードへ戻る</a></p>
        </body>
        </html>
        HTML;
    exit;
}

/**
 * 自前の静的ファイルに更新時刻を付ける(キャッシュ破棄)。
 *
 * `km_asset('./assets/i18n/ja.js')` → `./assets/i18n/ja.js?v=1786341234`
 *
 * これが無いと、**配備しても利用者のブラウザは古いファイルを使い続ける**。
 * 実際にフェーズ15で、辞書へ足した文言が画面に出ず(キーがそのまま表示され)、
 * サーバー上のファイルには入っているのにブラウザだけ2件古い、という状態になった。
 *
 * 対象は自前のものだけ。AdminLTE などの vendor は無編集で運用していて中身が変わらないので、
 * そのままキャッシュさせた方がよい。
 *
 * ファイルが見つからないときは何も付けない(存在しないパスでも画面は壊さない)。
 */
function km_asset(string $relativePath): string
{
    static $versions = [];

    if (!array_key_exists($relativePath, $versions)) {
        // './assets/...' はページ(/admin/*.php)から見た相対パス。実体は admin/ の下にある
        $path = dirname(__DIR__) . '/' . ltrim($relativePath, './');
        $mtime = is_file($path) ? filemtime($path) : false;
        $versions[$relativePath] = $mtime === false ? null : (string) $mtime;
    }

    return $versions[$relativePath] === null
        ? $relativePath
        : $relativePath . '?v=' . $versions[$relativePath];
}

/** HTML エスケープ。テンプレート内で毎回書くには長いので短縮形を用意する。 */
function km_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/*
 * CSRF 対策。
 *
 * 管理画面は「テーブルを消す」「地図の公開設定を変える」といった取り返しのつきにくい
 * 操作を持つので、状態を変える POST には必ずトークンを付ける。
 *
 * **実体は lib/csrf.php に置いてある。** 公開ページの account.php(利用者が自分の
 * パスワードを変える)も同じ守りが要るが、このファイルは KM_ADMIN が無いと止まるため
 * 外から読めない。同じ検証を2つ書かないよう、判定だけ lib へ出した。
 */
require_once dirname(__DIR__, 2) . '/lib/csrf.php';

/*
 * 画面に出すエラー文。**例外の文面をそのまま出さない**(lib/user-error.php の説明)。
 * 管理画面の全ページで使うので、ここで読んでおく。
 */
require_once dirname(__DIR__, 2) . '/lib/user-error.php';

/** 同じディレクトリ内のページへリダイレクトして終了する。 */
function km_redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}

/**
 * 設定不備で認証を判定できないときに呼ぶ。
 *
 * 「判定できないから通す」は最悪の挙動なので、必ず 503 で止める。
 * 原因はサーバーログにだけ残し、画面には出さない(内部構成を晒さないため)。
 */
function km_fail_closed(Throwable $exception): never
{
    error_log('KosenMap admin is misconfigured: ' . $exception::class . ': ' . $exception->getMessage());

    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo <<<HTML
        <!doctype html>
        <html lang="ja">
        <head><meta charset="UTF-8"><title>管理画面を利用できません</title></head>
        <body>
          <h1>管理画面を利用できません</h1>
          <p>認証の設定が未完了のため、安全のため停止しました。サーバーのログを確認してください。</p>
        </body>
        </html>
        HTML;
    exit;
}
