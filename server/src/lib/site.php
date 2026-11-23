<?php

declare(strict_types=1);

/**
 * このサイトが外からどう見えているか(ホスト名・スキーム・ポート)の**単一の出所**。
 *
 * 1.0.0 では `192.168.3.29` が nginx・compose・PHP・JS に散らばっていた。校内 LAN の
 * IP アドレスで固定されている限りは動くが、**VPS + 独自ドメインへ移した瞬間に
 * 全部が嘘になる**。移行のたびに探し回らなくて済むよう、ここ1本に集約する。
 *
 * 正本は環境変数 `APP_URL`(既に logto-client.php が使っている値)。
 * 例: `APP_URL=https://kosenmap.example.jp` / `APP_URL=https://192.168.3.29:9443`
 *
 * **ここが返すのは「ブラウザから見た URL」だけ。** コンテナ間の通信先
 * (`mariadb` / `mailpit` などのサービス名)は別物で、ドメインを変えても
 * 変わらない。混ぜないこと。
 */

/** 既定値。env が無い環境(コンテナ外での CLI 実行など)でも壊れないように。 */
const KM_SITE_DEFAULT_URL = 'https://192.168.3.29:9443';

/**
 * サービスごとのポート。**画面の表示と死活監視で同じ表を使う**ため、ここに置く。
 * logto-core(3001)はコンテナの中の番号で、外には出していない(2026-10-19 から 443 番の auth.<ドメイン>。
 * ブラウザから見た番号は km_site_port が返す)。logto-admin(3002)はホストの 127.0.0.1 だけ(2026-11-02。SSH のトンネルで開く)。
 * phpmyadmin(8281)と soketi(6001)は 2026-11-02 に外した。
 * 変えるときは compose.yaml の ports と nginx の listen も揃えること。
 */
const KM_SITE_PORTS = [
    'web-http' => 8080,
    'web-https' => 9443,
    'logto-core' => 3001,
    'logto-admin' => 3002,
    'mailpit' => 8025,
    'mariadb' => 3306,
];

/** @return array{scheme:string, host:string, port:?int} */
function km_site_parts(): array
{
    static $parts = null;
    if ($parts !== null) {
        return $parts;
    }

    $url = (string) (getenv('APP_URL') ?: KM_SITE_DEFAULT_URL);
    $parsed = parse_url($url);
    if (!is_array($parsed) || !isset($parsed['host'])) {
        // 設定が壊れていても画面は出す。既定へ落として続ける
        error_log('km_site_parts(): APP_URL を解釈できません: ' . $url);
        $parsed = (array) parse_url(KM_SITE_DEFAULT_URL);
    }

    $parts = [
        'scheme' => (string) ($parsed['scheme'] ?? 'https'),
        'host' => (string) ($parsed['host'] ?? '192.168.3.29'),
        'port' => isset($parsed['port']) ? (int) $parsed['port'] : null,
    ];

    return $parts;
}

/**
 * 管理用のサービス。**管理画面を別オリジンにしたときは、管理画面と同じホスト名で開く**(2026-09-15)。
 *
 * ゲート(nginx の auth_request → admin/api/gate.php)が見るのは**セッション Cookie**で、
 * Cookie はホスト名ごとに分かれる(`__Host-` は Domain を持てない)。管理画面が admin.example.jp なら、
 * Mailpit も admin.example.jp:ポート で開かないとゲートで必ず弾かれる。
 * (phpMyAdmin と Logto Console は 2026-11-02 に nginx から外した。Console は KM_SITE_LOGTO_CONSOLE_URL)
 */
const KM_SITE_ADMIN_SERVICES = ['mailpit'];

/**
 * **Logto Console の開き方**(2026-11-02、利用者の指示「Logto も SSH から」)。
 *
 * Console はインターネットに出さず、ホストの 127.0.0.1:3002 にだけ出す(compose.yaml の logto)。
 * 手元で SOCKS のトンネル(`ssh -t -i ~/.ssh/km_vps -D 127.0.0.1:10800 km@<ホスト> "while touch .kosenmap-pma-wanted; do sleep 20; done"`)を張り、
 * そのトンネルを通す Chrome でこの URL を開く(2026-11-03。**kmops の鍵は転送が禁止**(no-port-forwarding)で通らない)。
 * phpMyAdmin(KM_SITE_PHPMYADMIN_URL)も同じトンネルの先。
 * Logto の ADMIN_ENDPOINT も同じ値(Console の戻り先は Logto がそこから作る)。
 */
const KM_SITE_LOGTO_CONSOLE_URL = 'http://localhost:3002';

/** phpMyAdmin(2026-11-03 にホストの 127.0.0.1:8281 だけで戻した。compose.yaml の phpmyadmin) */
const KM_SITE_PHPMYADMIN_URL = 'http://localhost:8281';

/** トンネルの SOCKS の待ち受け(手元の PC) */
const KM_SITE_TUNNEL_SOCKS_PORT = 10800;

/**
 * トンネルが開いている合図に、km の家で触る目印(scripts/host-pma-window.sh の WANT_NAME と同じ)。
 * phpMyAdmin はふだん止まっていて、これが 60 秒以内に触られていれば 1 分以内に起きる(2026-11-07、利用者の指示)
 */
const KM_SITE_PMA_WANT_FILE = '.kosenmap-pma-wanted';

/**
 * Console と phpMyAdmin へトンネルを張るコマンド(画面に出す)。鍵と利用者は運用の決まり(docs/05-containers の §5-2)に合わせる。
 * -N の代わりに目印を 20 秒ごとに触る。-t で端末を付けるので、切れると(SIGHUP)触るのも止まる
 */
function km_site_logto_console_tunnel(): string
{
    return 'ssh -t -i ~/.ssh/km_vps -D 127.0.0.1:' . KM_SITE_TUNNEL_SOCKS_PORT . ' km@' . km_site_host()
        . ' "while touch ' . KM_SITE_PMA_WANT_FILE . '; do sleep 20; done"';
}

/** そのトンネルを通す Chrome の起動(ふだんの Chrome とプロフィールを分ける。localhost もトンネルへ通す) */
function km_site_tunnel_chrome(): string
{
    return '"C:\Program Files\Google\Chrome\Application\chrome.exe" --user-data-dir="%LOCALAPPDATA%\KosenMap\chrome-socks"'
        . ' --proxy-server="socks5://127.0.0.1:' . KM_SITE_TUNNEL_SOCKS_PORT . '" --proxy-bypass-list="<-loopback>"'
        . ' ' . KM_SITE_LOGTO_CONSOLE_URL . ' ' . KM_SITE_PHPMYADMIN_URL;
}

/**
 * 管理画面の URL の部品。正本は環境変数 `ADMIN_URL`。**無ければ APP_URL と同じ**(= 別オリジンにしない)。
 *
 * ## なぜ分けるのか(security-review-2026-09-14 §7 の 9)
 *
 * 公開ページ(地図・問い合わせ・規約)と管理画面が**同じオリジン**だと、公開ページのどこか 1 か所に
 * スクリプトを差し込まれた時点で、そのスクリプトは管理画面を開いて CSRF トークンを読み、操作できる
 * (同一オリジンポリシーが何も隔てない)。管理画面を別のホスト名に置けば、公開ページの穴は管理画面に届かない。
 * nginx は管理用のホスト名では管理画面に要るパスしか返さない(default.conf.template の $km_host_role)。
 *
 * @return array{scheme:string, host:string, port:?int}
 */
function km_site_admin_parts(): array
{
    static $parts = null;
    if ($parts !== null) {
        return $parts;
    }

    $url = (string) (getenv('ADMIN_URL') ?: '');
    $parsed = $url === '' ? false : parse_url($url);
    if (!is_array($parsed) || !isset($parsed['host'])) {
        if ($url !== '') {
            error_log('km_site_admin_parts(): ADMIN_URL を解釈できません(APP_URL と同じとみなします): ' . $url);
        }

        return $parts = km_site_parts();
    }

    return $parts = [
        'scheme' => (string) ($parsed['scheme'] ?? 'https'),
        'host' => (string) $parsed['host'],
        'port' => isset($parsed['port']) ? (int) $parsed['port'] : null,
    ];
}

/** 管理画面のオリジン。末尾にスラッシュは付けない。別オリジンにしていなければ km_site_origin() と同じ。 */
function km_site_admin_origin(): string
{
    $parts = km_site_admin_parts();

    return $parts['scheme'] . '://' . $parts['host'] . ($parts['port'] === null ? '' : ':' . $parts['port']);
}

/** 管理画面を公開ページと別のオリジンに置いているか。 */
function km_site_admin_split(): bool
{
    return strtolower(km_site_admin_origin()) !== strtolower(km_site_origin());
}

/**
 * **いま来ている要求**のオリジン(公開か管理のどちらか)。サインインの戻り先・サインアウト後の行き先に使う。
 *
 * Host ヘッダーは利用者が自由に書けるので、**そのまま URL に使わない**。設定した 2 つのどちらに当たるかだけを見て、
 * 当たらなければ公開側を返す(nginx はそもそも他の名前を通さないが、ここでも信じない)。
 */
function km_site_request_origin(): string
{
    if (!km_site_admin_split()) {
        return km_site_origin();
    }

    $requested = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $admin = km_site_admin_parts();
    $adminHost = strtolower($admin['host']);
    $adminHostPort = $admin['port'] === null ? $adminHost : $adminHost . ':' . $admin['port'];
    $defaultPort = $admin['scheme'] === 'https' ? ':443' : ':80';

    if ($requested === $adminHostPort || ($admin['port'] === null && $requested === $adminHost . $defaultPort)) {
        return km_site_admin_origin();
    }

    return km_site_origin();
}

/**
 * ローカル環境(LAN の検証機)か。compose.local.yaml が web に `KM_ENV=local` を渡す(2026-09-17)。
 *
 * **画面の表示にだけ使う。** 本番と見間違えないため(2026-09-07 に検証機と本番を取り違えかけた)。
 * 守りの判断には使わない —— 環境変数ひとつで緩む作りにしない。
 */
function km_site_is_local(): bool
{
    return getenv('KM_ENV') === 'local';
}

/** ホスト名だけ。死活監視の接続先や、画面に「接続先」として出すときに使う。 */
function km_site_host(): string
{
    return km_site_parts()['host'];
}

/** 本体(9443 相当)の URL。末尾にスラッシュは付けない。 */
function km_site_origin(): string
{
    $parts = km_site_parts();
    $port = $parts['port'];

    return $parts['scheme'] . '://' . $parts['host'] . ($port === null ? '' : ':' . $port);
}

/**
 * 付随サービスの URL を組む。
 *
 * `km_site_url('logto-admin', '/console/users/xxx')` → `http://localhost:3002/console/users/xxx`(SSH のトンネルで開く)
 * `km_site_url(null, '/faq.php')` → 本体の URL + パス
 *
 * **標準ポート(443/80)ならポート番号を付けない。** ドメイン運用に移ったとき
 * `https://example.jp:443` のような不格好な URL にならないようにする。
 */
function km_site_url(?string $service = null, string $path = ''): string
{
    if ($service === null) {
        return km_site_origin() . $path;
    }
    // Logto の入口は LOGTO_ENDPOINT が正本(2026-10-19。auth.<ドメイン> の 443 番に寄せた。km_site_logto_endpoint の注記)
    if ($service === 'logto-core') {
        return km_site_logto_endpoint() . $path;
    }
    // Console は SSH のトンネルの先(2026-11-02。KM_SITE_LOGTO_CONSOLE_URL の注記)
    if ($service === 'logto-admin') {
        return KM_SITE_LOGTO_CONSOLE_URL . $path;
    }

    $parts = km_site_parts();
    if (!array_key_exists($service, KM_SITE_PORTS)) {
        error_log('km_site_url(): 知らないサービスです: ' . $service);

        return km_site_origin() . $path;
    }
    // **表ではなく km_site_port() を使う。** 本体のポートは配備で変わるため
    $port = km_site_port($service);

    /*
     * 本体と同じポートなら origin をそのまま使う。VPS で 443 に載せた場合、
     * web-https の 9443 は使わなくなるため。
     */
    if ($service === 'web-https' || $port === null || $port === $parts['port']) {
        return km_site_origin() . $path;
    }

    $scheme = $service === 'web-http' ? 'http' : $parts['scheme'];
    $omitPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    // 管理用のサービスは管理画面と同じホスト名で開く(KM_SITE_ADMIN_SERVICES の説明)
    $host = in_array($service, KM_SITE_ADMIN_SERVICES, true) ? km_site_admin_parts()['host'] : $parts['host'];

    return $scheme . '://' . $host . ($omitPort ? '' : ':' . $port) . $path;
}

/**
 * そのサービスが**この配備で実際に使っているポート**。
 *
 * KM_SITE_PORTS は「nginx がどの番号で listen しているか」の表で、
 * **本体の 8080 / 9443 だけは配備によって変わる**(VPS では 80 / 443)。
 * 表をそのまま信じると、ドメイン運用で `https://example.jp:9443` という
 * 存在しない宛先を画面に出したり、死活監視が誤って down と言ったりする。
 *
 * 判定は APP_URL のポートから行う —— ポートが書かれていなければ 443 運用とみなす。
 *
 * @return int|null null は「既定ポート(https なら 443)」の意味
 */
function km_site_port(string $service): ?int
{
    $parts = km_site_parts();

    if ($service === 'web-https') {
        return $parts['port'];
    }
    if ($service === 'web-http') {
        // 本体が既定ポートなら平文も既定ポート、そうでなければ LAN 構成の 8080
        return $parts['port'] === null ? 80 : KM_SITE_PORTS['web-http'];
    }
    // Logto はブラウザから見た入口の番号(2026-10-19 に 443 番へ寄せた。km_site_logto_endpoint の注記)
    if ($service === 'logto-core') {
        $port = parse_url(km_site_logto_endpoint(), PHP_URL_PORT);

        return is_int($port) ? $port : null;
    }

    return KM_SITE_PORTS[$service] ?? null;
}

/**
 * **Logto(サインイン)の入口。** 末尾にスラッシュは付けない。正本は環境変数 `LOGTO_ENDPOINT`(compose が渡す)。
 *
 * ## 443 番へ寄せた(2026-10-19、利用者の指示)
 *
 * 学校の回線は 443 番以外を通さない(利用者が確かめた。解除は難しい)。以前の入口は `https://<ドメイン>:3001` で、
 * **学内からは誰もサインインできなかった**(展示の PC のテスト用アカウント・教職員・学内 Wi-Fi の管理アプリ)。
 * そこで `auth.<ドメイン>` の 443 番に Logto を置いた(nginx の auth の server。compose が LOGTO_ENDPOINT を渡す)。
 * 本番で切り替えて確かめたあと、:3001 の入口は外した(2026-10-19)。
 *
 * env が無いとき(コンテナの外の CLI など)は `https://auth.<本体のホスト>` へ落とす(compose の既定と同じ形)。
 */
function km_site_logto_endpoint(): string
{
    $endpoint = trim((string) getenv('LOGTO_ENDPOINT'));
    if ($endpoint !== '' && is_array($parsed = parse_url($endpoint)) && isset($parsed['host'])) {
        return rtrim($endpoint, '/');
    }
    $parts = km_site_parts();

    return $parts['scheme'] . '://auth.' . $parts['host'];
}

/**
 * **HTML 展開サイトの見せる側**(2026-11-05、利用者の指示。lib/html-host.php)。末尾にスラッシュは付けない。
 * 正本は環境変数 `KM_HTML_URL`(compose が渡す)。無ければ `https://html.<本体のホスト>`(本体と同じポートなら付けない)。
 *
 * **本体と別のオリジンにする**のが要点。上げた HTML のスクリプトは自由に動くので、本体の上で動かすと
 * 来場者として地図やアカウントの画面を操作できてしまう(本体の Cookie `__Host-KMSID` は別オリジンへ渡らない)
 */
function km_site_html_origin(): string
{
    $url = trim((string) getenv('KM_HTML_URL'));
    if ($url !== '' && is_array($parsed = parse_url($url)) && isset($parsed['host'], $parsed['scheme'])) {
        return strtolower($parsed['scheme']) . '://' . strtolower($parsed['host']) . (isset($parsed['port']) ? ':' . (int) $parsed['port'] : '');
    }
    $parts = km_site_parts();

    return $parts['scheme'] . '://html.' . $parts['host'] . ($parts['port'] === null ? '' : ':' . $parts['port']);
}

/**
 * **HTML 展開サイトの共有リンク**(2026-11-05、利用者の指示。lib/html-host.php)。末尾にスラッシュは付けない。
 * 正本は環境変数 `KM_SHARE_URL`。無ければ `https://share.<本体のホスト>`。
 *
 * 見せる側(html.<本体>)とも別のオリジンにする。html.<本体> にはほかの人が「このブラウザ」に置いたもの(IndexedDB)と
 * Service Worker があり、他人の共有ページのスクリプトが同じオリジンで動くと、それを読んだり消したりできてしまうため
 */
function km_site_share_origin(): string
{
    $url = trim((string) getenv('KM_SHARE_URL'));
    if ($url !== '' && is_array($parsed = parse_url($url)) && isset($parsed['host'], $parsed['scheme'])) {
        return strtolower($parsed['scheme']) . '://' . strtolower($parsed['host']) . (isset($parsed['port']) ? ':' . (int) $parsed['port'] : '');
    }
    $parts = km_site_parts();

    return $parts['scheme'] . '://share.' . $parts['host'] . ($parts['port'] === null ? '' : ':' . $parts['port']);
}

/**
 * **VS Code 型のエディタ(/code/)の枠**(段 11-3。2026-11-08)。末尾にスラッシュは付けない。
 * 正本は環境変数 `KM_WEBVIEW_URL`。無ければ `https://webview.<本体のホスト>`。
 *
 * 拡張機能ホスト(Prettier・Error Lens などを動かす枠)と webview(Markdown のプレビュー・ノートブックの出力)を
 * 本体と別のオリジンで動かす。本体の Cookie・IndexedDB(プロジェクト)・CSRF の札に、拡張機能や
 * 利用者の Markdown・ノートブックの中身が手を届かせないため。html.<本体> とも分ける(あちらは利用者が上げた HTML)
 */
function km_site_webview_origin(): string
{
    $url = trim((string) getenv('KM_WEBVIEW_URL'));
    if ($url !== '' && is_array($parsed = parse_url($url)) && isset($parsed['host'], $parsed['scheme'])) {
        return strtolower($parsed['scheme']) . '://' . strtolower($parsed['host']) . (isset($parsed['port']) ? ':' . (int) $parsed['port'] : '');
    }
    $parts = km_site_parts();

    return $parts['scheme'] . '://webview.' . $parts['host'] . ($parts['port'] === null ? '' : ':' . $parts['port']);
}

/** 「host:port」の表示用。接続先を人に見せるところで使う。 */
function km_site_host_port(string $service): string
{
    $port = km_site_port($service);
    if ($port === null) {
        // 既定ポート運用。番号を付けない方が実態に合う
        return km_site_host() . (km_site_parts()['scheme'] === 'https' ? ':443' : ':80');
    }

    return km_site_host() . ':' . $port;
}

/**
 * 画面に出す「接続先」の一覧。**JS 側で組み立てさせない**ためにここで作る。
 *
 * admin/assets/js/services.js は以前ポート番号を直書きしており、
 * ドメインへ移すと **この画面のリンクだけが古い宛先を指し続けた**。
 *
 * @return array<string, array{url:string, port:int|null}>
 */
function km_site_service_map(): array
{
    $scheme = km_site_parts()['scheme'];
    $map = [];
    foreach (array_keys(KM_SITE_PORTS) as $service) {
        /*
         * **表示用は必ず具体的な数字にする。**
         * km_site_port() は「既定ポート」を null で表すが、そのまま渡すと
         * JS 側の `?? 9443` のような既定値に落ちて **VPS なのに 9443 と表示される**。
         */
        $port = km_site_port($service) ?? ($scheme === 'https' ? 443 : 80);

        /*
         * URL を持たせないもの:
         *   mariadb … ブラウザで開くものではない
         *   mailpit … **本番では Mailpit を起動せず、8025 も publish していない**(2026-09-18)。
         *             URL を渡すと、管理画面に「開く」ボタンだけが残り、押しても繋がらない
         */
        $noUrl = $service === 'mariadb' || ($service === 'mailpit' && !km_site_is_local());
        $url = $noUrl ? null : km_site_url($service);
        $map[$service] = [
            'url' => $url,
            'port' => $port,
        ];
    }

    return $map;
}
