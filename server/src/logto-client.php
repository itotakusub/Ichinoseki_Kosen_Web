<?php

declare(strict_types=1);

use Logto\Sdk\Constants\UserScope;
use Logto\Sdk\LogtoClient;
use Logto\Sdk\LogtoConfig;

require __DIR__ . '/vendor/autoload.php';
// SDK のトークンの読み違いを直した子クラス(lib/logto-sdk-client.php の説明)
require_once __DIR__ . '/lib/logto-sdk-client.php';

/*
 * Logto PHP SDKの標準ストレージはPHPセッションです。
 * LogtoClient生成時にsession_start()されるため、その前にCookie属性を設定します。
 *
 * 属性の指定は lib/session.php に集約してあります(admin/api/gate.php も
 * SDK を作る前にセッションを覗くため、開始する場所が2つになったので)。
 */
require_once __DIR__ . '/lib/session.php';
km_session_start();

function requiredEnv(string $name): string
{
    $value = getenv($name);

    if ($value === false || trim($value) === '') {
        throw new RuntimeException("環境変数 {$name} が設定されていません。");
    }

    return trim($value);
}

// ホスト名の既定値は lib/site.php に集約してある(APP_URL が正本)
require_once __DIR__ . '/lib/site.php';

/*
 * サインインの戻り先(callback.php)とサインアウト後の行き先を組む元。**いま来ているホストに合わせる**(2026-09-15)。
 * 管理画面を別オリジン(ADMIN_URL)にしたとき、管理画面から入った人の Cookie は管理用のホストにしか付かない ——
 * 公開側の callback.php へ戻すと、そこでは未ログインのまま、管理画面へ戻っても入れない。
 * 別オリジンにしていなければ今までどおり APP_URL。**Logto にも両方の callback.php を登録すること**(docs/12 §5)。
 */
$appUrl = km_site_request_origin();

/*
 * 管理画面 (/admin) の権限判定に使う API リソース。
 * Logto Console の API resource indicator と一致させます。Android アプリと共用で、
 * ここへ紐づく role kosenmap-admin が admin:* の permission を持ちます。
 */
$adminApiResource = rtrim(getenv('LOGTO_API_RESOURCE') ?: km_site_url(null, '/api'), '/');

$client = new KmLogtoClient(
    new LogtoConfig(
        endpoint: rtrim(getenv('LOGTO_ENDPOINT') ?: km_site_url('logto-core'), '/'),
        appId: requiredEnv('LOGTO_APP_ID'),
        appSecret: requiredEnv('LOGTO_APP_SECRET'),
        /*
         * email と roles は画面にユーザー名とロールを出すため。
         * admin:* は管理画面の権限判定用で、Logto がユーザーの role に応じて
         * 実際に付与された分だけをアクセストークンへ載せます。
         * 一般向けページはこのトークンを使わないので、挙動は変わりません。
         */
        scopes: [
            UserScope::email->value,
            UserScope::roles->value,
            /*
             * profile は Account API(lib/logto-account.php)で使う。
             * 管理画面のプロフィールから、利用者名・表示名・パスワードを
             * 本人が変えられるようにするため。
             *
             * **スコープはサインインした時点で確定する。** これを足す前から続いている
             * セッションのトークンには入っておらず、Account API が 403 を返す。
             * その場合は「サインアウトして入り直す」で直る(画面にそう出る)。
             */
            UserScope::profile->value,
            /*
             * 教職員の判定(docs/15。2026-09-18)。ID トークンに `organization_roles`(`組織ID:ロール名`)が載る。
             * **サインインした時点で確定する**ので、承認されたあとは入り直すまで反映されない(画面にそう出す)。
             */
            UserScope::organizationRoles->value,
            'admin:users:read',
            'admin:users:write',
            'admin:api-keys:read',
            'admin:api-keys:write',
        ],
        resources: [$adminApiResource],
    ),
);
