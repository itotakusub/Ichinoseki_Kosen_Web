<?php

declare(strict_types=1);

use Logto\Sdk\InteractionMode;

/*
 * Logto のアプリの ID と秘密が .env に無いとき(新しく立てた検証機で、Console の設定の前など)は、
 * 500 ではなく「設定が未完了」と言って止める(2026-10-05)。管理画面の fail closed(503)と同じ考え。
 */
try {
    require __DIR__ . '/logto-client.php';
} catch (RuntimeException $exception) {
    error_log('sign-in.php: ' . $exception->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Retry-After: 300');
    echo <<<'HTML'
        <!doctype html>
        <html lang="ja">
        <head><meta charset="UTF-8"><title>サインインを利用できません</title></head>
        <body>
          <h1>サインインを利用できません</h1>
          <p>認証(Logto)の設定が未完了です。管理者は .env の LOGTO_APP_ID と LOGTO_APP_SECRET を確認してください。</p>
        </body>
        </html>
        HTML;
    exit;
}

$redirectUri = $appUrl . '/callback.php';

/*
 * 既定は今までどおり signUp のまま。管理画面のように「登録画面ではなく
 * サインイン画面を出したい」入口だけが ?mode=signIn を付けてきます。
 */
$interactionMode = ($_GET['mode'] ?? '') === 'signIn'
    ? InteractionMode::signIn
    : InteractionMode::signUp;

/*
 * 認証後の戻り先。オープンリダイレクトを避けるため、このサイト内の
 * 絶対パスだけを受け付けます("//" で始まるものは別ホストへ飛べるので弾く)。
 */
$return = (string) ($_GET['return'] ?? '');
if ($return !== '' && preg_match('#^/(?!/)[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*$#', $return) === 1) {
    $_SESSION['km_return_to'] = $return;
} else {
    unset($_SESSION['km_return_to']);
}

$location = $client->signIn(
    $redirectUri,
    interactionMode: $interactionMode
);

header('Location: ' . $location, true, 302);
exit;
