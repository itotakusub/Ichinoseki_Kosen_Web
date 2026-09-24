<?php

declare(strict_types=1);

use Logto\Sdk\LogtoClient;
use Logto\Sdk\Models\AccessTokenClaims;
use Logto\Sdk\Models\IdTokenClaims;

/**
 * **トークンの中身を base64url として読む**(2026-09-17)。
 *
 * SDK(logto/sdk 0.3.1)の getIdTokenClaims() / getAccessTokenClaims() は JWT の本体を `base64_decode` で読む。
 * JWT は base64url(`-` と `_`・パディング無し)なので、本体に `-` か `_` が出るトークンだけ読み違え、
 * json_decode が null を返して `new IdTokenClaims(...null)` が TypeError になる。どの文字が出るかは
 * 名前などの中身で変わる —— **一般アカウントで管理画面を開くと「認証の設定が未完了」(fail closed の 503)が出た。**
 * 手元で試すと、よくある形のトークンの 4 分の 1 が読めなかった。
 *
 * vendor は composer で入れ直すと戻るので触らず、ここで読み方だけ差し替える。
 * **署名はここでも SDK でも検証しない**(SDK と同じ)。トークンは Logto からサーバーが直接受け取り、
 * セッションにだけ置いたもので、利用者が書き換えられない。API で受け取るトークンは logto_guard.php が検証する。
 */
final class KmLogtoClient extends LogtoClient
{
    /** @return array<string, mixed> */
    private static function kmJwtPayload(?string $jwt, string $what): array
    {
        $parts = explode('.', (string) $jwt);
        if (count($parts) !== 3 || $parts[1] === '') {
            throw new UnexpectedValueException("{$what} がありません(サインインし直すと直ります)。");
        }
        $b64 = strtr($parts[1], '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $json = base64_decode($b64, true);
        $claims = $json === false ? null : json_decode($json, true);
        if (!is_array($claims)) {
            throw new UnexpectedValueException("{$what} の中身を読めません。");
        }

        return $claims;
    }

    function getIdTokenClaims(): IdTokenClaims
    {
        return new IdTokenClaims(...self::kmJwtPayload($this->getIdToken(), 'ID トークン'));
    }

    function getAccessTokenClaims(string $resource = ''): AccessTokenClaims
    {
        return new AccessTokenClaims(...self::kmJwtPayload($this->getAccessToken($resource), 'アクセストークン'));
    }
}
