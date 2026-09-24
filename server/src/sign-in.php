<?php

declare(strict_types=1);

use Logto\Sdk\InteractionMode;

require __DIR__ . '/logto-client.php';

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
