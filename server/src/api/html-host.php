<?php

declare(strict_types=1);

/**
 * HTML 展開サイトの道具のページ(html.php・Main/html-host.js)の口(2026-11-05。仕組みは lib/html-host.php)。
 *
 * どれも POST・CSRF(X-KM-CSRF。html.php の meta km-csrf)。action で分ける:
 *
 *   state          サインインしているか・保存できるか・サーバーのサイトの一覧(版つき)
 *   temp           一時フォルダに置く(サインインしていなくてよい)。zip・name・entry → id・key(鍵は道具のページが覚える)
 *   temp_patch     一時のものに CSS・JS を足す。id・key・zip
 *   temp_delete    一時のものを消す。id・key
 *   save           サーバーに保存(サインインした人)。site(空なら新しく)・name・entry・patch(1 なら足す)・zip
 *   rename         サイトの名前を変える。site・name
 *   delete_site    サイトを消す。site
 *   delete_version 版を消す。site・version
 *   ticket         見るための札を作って、開く URL を返す。site(・version)か、id・key(一時)
 *
 * 上げるものは道具のページが **1 つの zip にまとめて**送る(フォルダの形を保つため。落とした zip はそのまま)。
 * 誰のものかはサインインのセッションの sub だけで決める(利用者の ID は受け取らない)。
 * 入口の回数は nginx の km_html で絞る(本文は 21MB まで)
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/html-host.php';

require_method('POST');

/*
 * サインインのセッション(logto-client.php)。Logto に繋がらなくても一時フォルダは使えるようにする
 * (そのときは「サインインしていない」として扱う)
 */
$owner = null;
$ownerName = '';
$blocked = null;
try {
    require dirname(__DIR__) . '/logto-client.php';
    if ($client->isAuthenticated()) {
        $claims = $client->getIdTokenClaims();
        $owner = trim((string) ($claims->sub ?? '')) ?: null;
        $ownerName = (string) ($claims->name ?? $claims->username ?? $claims->email ?? '');
    }
} catch (Throwable $exception) {
    error_log('api/html-host.php session: ' . $exception->getMessage());
    require_once dirname(__DIR__) . '/lib/session.php';
    km_session_start();
}
if (!km_csrf_verify()) {
    respond(['success' => false, 'message' => 'ページを読み込み直してからやり直してください。'], 403);
}
// 大きな本文を読む間、同じ人のほかの要求を待たせない
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

try {
    $pdo = km_db();
    km_html_ensure_tables($pdo);
} catch (Throwable $exception) {
    error_log('api/html-host.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => 'いまサーバーに置けません(ブラウザの中には置けます)。'], 503);
}

/*
 * サーバーに保存できる人か(account.php と同じ守り)。
 *   - 止められたアカウントは使えない(判定できないときも通さない)
 *   - テスト用アカウント(展示の PC)は使えない(来場者が代わる代わる触るので、誰のものか分からなくなる)
 */
if ($owner !== null) {
    try {
        require_once dirname(__DIR__) . '/lib/logto-management.php';
        if (km_logto_user_is_suspended($owner)) {
            $blocked = 'このアカウントは止められています。';
        }
    } catch (Throwable $exception) {
        error_log('api/html-host.php suspended check failed: ' . $exception->getMessage());
        $blocked = 'いまアカウントを確かめられません。しばらくしてからお試しください。';
    }
    if ($blocked === null) {
        try {
            require_once dirname(__DIR__) . '/lib/test-mode.php';
            if (km_test_account_is_marked($pdo, $owner)) {
                $blocked = 'テスト用アカウントでは、サーバーに保存できません。';
            }
        } catch (Throwable $exception) {
            error_log('api/html-host.php test account check failed: ' . $exception->getMessage());
        }
    }
}

/** 上げた zip を読む(無ければ 400) @return array<string, string> */
function km_html_api_files(): array
{
    $file = $_FILES['zip'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        $error = is_array($file) ? (int) ($file['error'] ?? 0) : UPLOAD_ERR_NO_FILE;
        throw new InvalidArgumentException($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
            ? '大きすぎます(' . intdiv(KM_HTML_MAX_UPLOAD_BYTES, 1024 * 1024) . 'MB まで)。'
            : 'ファイルを受け取れませんでした。');
    }

    return km_html_read_upload((string) $file['tmp_name']);
}

function km_html_api_str(string $key, int $max = 300): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? mb_substr($value, 0, $max) : '';
}

/** 自分のサイトだけ(他人のものは「見つかりません」) */
function km_html_api_own_site(PDO $pdo, ?string $owner): array
{
    $site = km_html_site_find($pdo, km_html_api_str('site', 40));
    if ($owner === null || $site === null || !hash_equals($site['ownerSub'], $owner)) {
        throw new InvalidArgumentException('そのサイトは見つかりません。');
    }

    return $site;
}

/** 鍵の合う一時のものだけ */
function km_html_api_own_temp(PDO $pdo): array
{
    $row = km_html_temp_find($pdo, km_html_api_str('id', 40));
    if ($row === null || !km_html_temp_key_valid($row, km_html_api_str('key', 80))) {
        throw new InvalidArgumentException('見つかりません(一時フォルダのものは 24 時間で消えます)。');
    }

    return $row;
}

/** 画面に返すサイトの形(持ち主の sub は返さない) */
function km_html_api_site_out(array $site): array
{
    return [
        'id' => $site['id'],
        'name' => $site['name'],
        'bytes' => $site['bytes'],
        'updatedAt' => $site['updatedAt'],
        'versions' => $site['versions'] ?? [],
    ];
}

function km_html_api_require_saver(?string $owner, ?string $blocked): string
{
    if ($owner === null) {
        respond(['success' => false, 'message' => 'サインインすると、サーバーに保存できます。'], 401);
    }
    if ($blocked !== null) {
        respond(['success' => false, 'message' => $blocked], 403);
    }

    return $owner;
}

try {
    switch ($action) {
        case 'state':
            $sites = ($owner !== null && $blocked === null) ? km_html_sites_of($pdo, $owner) : [];
            respond([
                'success' => true,
                'signedIn' => $owner !== null,
                'canSave' => $owner !== null && $blocked === null,
                'blocked' => $blocked,
                'name' => $ownerName,
                'sites' => array_map('km_html_api_site_out', $sites),
                'usedBytes' => $owner !== null && $blocked === null ? km_html_owner_bytes($pdo, $owner) : 0,
                'limits' => [
                    'uploadBytes' => KM_HTML_MAX_UPLOAD_BYTES,
                    'totalBytes' => KM_HTML_MAX_TOTAL_BYTES,
                    'files' => KM_HTML_MAX_FILES,
                    'versions' => KM_HTML_MAX_VERSIONS,
                    'sites' => KM_HTML_MAX_SITES_PER_OWNER,
                    'ownerBytes' => KM_HTML_MAX_OWNER_BYTES,
                ],
            ]);

        case 'temp':
            $result = km_html_temp_save($pdo, km_html_api_files(), km_html_api_str('name', 200), km_html_api_str('entry'));
            respond(['success' => true, 'temp' => $result]);

        case 'temp_patch':
            $row = km_html_api_own_temp($pdo);
            $result = km_html_temp_save($pdo, km_html_api_files(), '', km_html_api_str('entry'), $row);
            respond(['success' => true, 'temp' => $result]);

        case 'temp_delete':
            km_html_temp_delete($pdo, km_html_api_own_temp($pdo));
            respond(['success' => true]);

        case 'save':
            $saver = km_html_api_require_saver($owner, $blocked);
            $siteId = km_html_api_str('site', 40);
            $result = km_html_site_save(
                $pdo,
                $saver,
                $ownerName,
                $siteId === '' ? null : $siteId,
                km_html_api_str('name', 200),
                km_html_api_files(),
                km_html_api_str('entry'),
                km_html_api_str('patch', 2) === '1',
            );
            $result['site']['versions'] = km_html_site_versions($pdo, $result['site']['dbId']);
            respond([
                'success' => true,
                'site' => km_html_api_site_out($result['site']),
                'version' => $result['version'],
                'entry' => $result['entry'],
                'removed' => $result['removed'],
            ]);

        case 'rename':
            $site = km_html_api_own_site($pdo, km_html_api_require_saver($owner, $blocked));
            respond(['success' => true, 'name' => km_html_site_rename($pdo, $site, km_html_api_str('name', 200))]);

        case 'delete_site':
            km_html_site_delete($pdo, km_html_api_own_site($pdo, km_html_api_require_saver($owner, $blocked)));
            respond(['success' => true]);

        case 'delete_version':
            $site = km_html_api_own_site($pdo, km_html_api_require_saver($owner, $blocked));
            km_html_version_delete($pdo, $site, (int) km_html_api_str('version', 10));
            respond(['success' => true]);

        case 'ticket':
            if (km_html_api_str('site', 40) !== '') {
                $site = km_html_api_own_site($pdo, km_html_api_require_saver($owner, $blocked));
                $version = (int) km_html_api_str('version', 10);
                if ($version > 0 && !in_array($version, array_column(km_html_site_versions($pdo, $site['dbId']), 'version'), true)) {
                    throw new InvalidArgumentException('その版は見つかりません。');
                }
                $to = '/s/' . $site['id'] . '/' . ($version > 0 ? 'v' . $version . '/' : '');
                $ticket = km_html_ticket_issue($pdo, 'owner', $site['ownerSub'], $to);
            } else {
                $row = km_html_api_own_temp($pdo);
                $to = '/t/' . $row['public_id'] . '/';
                $ticket = km_html_ticket_issue($pdo, 'temp', (string) $row['public_id'], $to);
            }
            respond(['success' => true, 'url' => km_site_html_origin() . '/auth?t=' . $ticket]);

        default:
            respond(['success' => false, 'message' => 'action が違います。'], 400);
    }
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('api/html-host.php ' . $action . ' failed: ' . $exception->getMessage());
    // lib/html-host.php が投げる RuntimeException の文は利用者向け。DB の例外(PDOException も RuntimeException)は中身を出さない
    $userFacing = $exception instanceof RuntimeException && !$exception instanceof PDOException;
    respond(['success' => false, 'message' => $userFacing ? $exception->getMessage() : '置けませんでした。'], 500);
}
