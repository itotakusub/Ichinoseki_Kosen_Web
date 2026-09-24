<?php

declare(strict_types=1);

require __DIR__ . '/logto-client.php';

try {
    $client->handleSignInCallback();

    /*
     * **サインインが成立した瞬間にセッション ID を作り直す(セッション固定への対策)。**
     *
     * 攻撃者が事前に自分の知る ID を相手のブラウザへ持たせておくと、
     * 相手がログインした時点で **同じ ID で管理画面に入れてしまう**。
     * ここで作り直せば、相手が知っている古い ID は通らなくなる。
     *
     * SDK のトークンは $_SESSION に入っているが、session_regenerate_id() は
     * 中身を引き継ぐので**ログイン状態はそのまま**。true で古いファイルを消す。
     */
    session_regenerate_id(true);

    // sign-in.php が検証済みの戻り先を置いていれば、そこへ返す(管理画面用)。
    // 無ければ従来どおりトップへ。
    $returnTo = $_SESSION['km_return_to'] ?? '/';
    unset($_SESSION['km_return_to']);

    header('Location: ' . $appUrl . $returnTo, true, 302);
    exit;
} catch (Throwable $exception) {
    /*
     * **例外の内容を画面に出さない。**
     *
     * ここは未認証の相手が直接叩ける URL で、以前は例外クラス名とメッセージを
     * そのまま表示していた。Logto SDK の例外文には **内部のエンドポイント・
     * リクエストの中身・設定の不備**が混ざるため、認証の入口で手掛かりを配ることになる
     * (「エラーが応答本文に出ない」というこの構成の方針にも反していた)。
     *
     * 調査に要る情報はサーバーのログへ出す。利用者には**やり直す導線だけ**を見せる。
     * 照合用の短い ID を出しておくと、ログの該当行を突き合わせられる。
     */
    $incident = bin2hex(random_bytes(4));
    error_log(sprintf(
        'callback.php failed [%s]: %s: %s',
        $incident,
        $exception::class,
        $exception->getMessage()
    ));

    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');

    $id = htmlspecialchars($incident, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo <<<HTML
<!doctype html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex,nofollow">
    <title>サインインを完了できませんでした</title>
</head>
<body>
    <h1>サインインを完了できませんでした</h1>
    <p>お手数ですが、もう一度お試しください。</p>
    <p><a href="/">トップへ戻る</a></p>
    <p><small>参照番号: {$id}</small></p>
</body>
</html>
HTML;
}
