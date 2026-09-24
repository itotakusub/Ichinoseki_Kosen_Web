<?php

declare(strict_types=1);

/**
 * ホスト(Ubuntu)側のセキュリティ通知。**当てるのは自動、知らせるのがここ。**
 *
 * ## なぜ要るのか
 *
 * `unattended-upgrades` はセキュリティ更新を自動で当てるが、
 * **`Automatic-Reboot "false"` にしてある**(会期中に勝手に落ちる方が痛いため、
 * `scripts/host-updates-setup.sh` の判断)。つまり:
 *
 *   - カーネルや libc を当てたあと、**再起動するまで古いものが動き続ける**
 *   - 再起動を促すのは `/var/run/reboot-required` という**ファイルが1つ置かれるだけ**
 *   - 誰もホストにログインしなければ、**それは何ヶ月でも置かれたまま**になる
 *
 * 「自動で当たっているから安心」と「実際に効いている」は違う。その差を知らせる。
 *
 * ## Docker のパッケージが残るのは正常
 *
 * `docker-ce` / `docker-ce-cli` / `containerd.io` は**意図的に自動更新から外して**ある
 * (デーモンの再起動でコンテナが止まるため)。これを「未適用」として毎日鳴らすと、
 * **オオカミ少年になって本物を見落とす。** 分けて数え、分けて書く。
 *
 * ## 届かないことに気づける形にする
 *
 * `update-notice.php` と同じ作法。変化が無ければ送らないが、
 * 月に一度は heartbeat を送る —— **沈黙を「正常」と解釈させない。**
 *
 * ロジックをここに置いてあるのは、メールもホストも無しで文面を検証できるようにするため
 * (`scripts/check.php security-notice`)。
 */

/** 1通に並べる上限。超えたら件数だけ出す(メールが読めなくなるより良い)。 */
const KM_SECURITY_NOTICE_MAX_ITEMS = 20;

/**
 * 通知の1件。`kind` は下の8つだけ。
 *
 * - `reboot`     … 再起動しないと当たった更新が効かない。**放置されやすい筆頭**
 * - `pending`    … 当てられるのに当たっていないセキュリティ更新が残っている
 * - `unattended` … 自動更新の仕組みそのものが動いていない/失敗している
 * - `ssh`        … SSH の入口が開きすぎている。**総当たりはいつか当たる**
 * - `logto`      … Logto Console のアカウントに2段階認証が要らない。**管理の入口がパスワードだけ**
 * - `fail2ban`   … fail2ban が入っているのに動いていない(補助の仕組み。入口そのものではない)
 * - `support`    … リリースのサポート期限(切れると更新自体が出なくなる)
 * - `disk`       … 空き容量。**足りないと apt も DB も静かに失敗する**
 *
 * **混ぜない。** 打つ手が違う ——
 * `reboot` は会期を避けて再起動、`pending` は当たらない理由を調べる、
 * `unattended` は仕込み直し、`ssh` は入口を閉める、`logto` は2段階認証を必須にする、
 * `fail2ban` は設定を確かめて起こし直す、`support` は入れ替えの計画、`disk` は掃除。
 *
 * `fail2ban` と `logto` は scripts/host-security-check.sh が送る(2026-09-14 に追加)。
 * ここに載るまでは src/scripts/notify-security.php が本文の末尾に足して送っていた。
 */
const KM_SECURITY_NOTICE_KINDS = ['reboot', 'pending', 'unattended', 'ssh', 'logto', 'fail2ban', 'support', 'disk'];

/**
 * 「今すぐ手を動かす」種別。件名の出し分けに使う。
 *
 * `logto` は入れる。Console に入れれば、サイトの設定も利用者も変えられる —— SSH と同じ重さ。
 * `fail2ban` は入れない。鍵だけで入る設定なら総当たりは当たらず、止まっていても
 * 記録と負荷が増えるだけ(host-security-check.sh の注記と同じ判断)。
 */
const KM_SECURITY_NOTICE_URGENT = ['reboot', 'pending', 'unattended', 'ssh', 'logto'];

/**
 * 受け取った JSON を検証して整える。**壊れた入力で送らない。**
 *
 * @return array{items:array<int,array{kind:string,summary:string,detail:string}>,
 *               host:string, os:string, heartbeat:bool, deferred:array<int,string>}
 * @throws InvalidArgumentException 形が違うとき
 */
function km_security_notice_parse(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('通知の内容を JSON として読めません');
    }

    $items = [];
    foreach ($decoded['items'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $kind = (string) ($item['kind'] ?? '');
        $summary = trim((string) ($item['summary'] ?? ''));
        if ($summary === '' || !in_array($kind, KM_SECURITY_NOTICE_KINDS, true)) {
            // **黙って捨てない。** 種別も要約も無い行は、作った側が間違えている
            throw new InvalidArgumentException('通知の項目が不正です: ' . json_encode($item));
        }
        $items[] = [
            'kind' => $kind,
            'summary' => $summary,
            'detail' => trim((string) ($item['detail'] ?? '')),
        ];
    }

    /*
     * 意図的に自動更新から外しているパッケージ。**問題として数えない。**
     * ただし本文には出す —— 「残っているのは承知のうえ」だと分かるように。
     */
    $deferred = [];
    foreach ($decoded['deferred'] ?? [] as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $deferred[] = $name;
        }
    }

    return [
        'items' => $items,
        'deferred' => $deferred,
        'host' => trim((string) ($decoded['host'] ?? '')) ?: 'このホスト',
        'os' => trim((string) ($decoded['os'] ?? '')),
        'heartbeat' => ($decoded['heartbeat'] ?? false) === true,
    ];
}

/** その報告に「今すぐ手を動かす」ものが含まれるか。 */
function km_security_notice_has_urgent(array $items): bool
{
    foreach ($items as $item) {
        if (in_array($item['kind'] ?? '', KM_SECURITY_NOTICE_URGENT, true)) {
            return true;
        }
    }

    return false;
}

/** 件名。**何が起きたかを件名だけで分かるようにする**(一覧で開かずに判断できる)。 */
function km_security_notice_subject(array $items, bool $heartbeat = false): string
{
    if ($items === []) {
        return $heartbeat
            ? '[KosenMap] ホストのセキュリティは問題ありません(定期のお知らせ)'
            : '[KosenMap] ホストのセキュリティは問題ありません';
    }

    $kinds = array_column($items, 'kind');

    /*
     * **入口が開いていることを最優先で出す。**
     * 総当たりは止められないが、パスワードで入れる状態なら**いつか当たる。**
     * 再起動の遅れは時間の問題だが、こちらは取り返しがつかない。
     */
    if (in_array('ssh', $kinds, true)) {
        return '[KosenMap] SSH の入口が開いています(パスワードで入れる状態)';
    }
    /*
     * **管理の入口も同じ重さ。** Console はパスワードだけで入れると、
     * 漏れた1つでサイトの設定も利用者も変えられる。再起動より先に出す。
     */
    if (in_array('logto', $kinds, true)) {
        return '[KosenMap] Logto Console に2段階認証が要りません(パスワードだけで入れる状態)';
    }
    // **再起動は次に出す。** 当たっているのに効いていない、が一番見落とされる
    if (in_array('reboot', $kinds, true)) {
        return '[KosenMap] ホストの再起動が必要です(更新が効いていません)';
    }
    if (in_array('unattended', $kinds, true)) {
        return '[KosenMap] ホストの自動セキュリティ更新が動いていません';
    }
    if (in_array('pending', $kinds, true)) {
        $count = count(array_filter($kinds, static fn (string $k): bool => $k === 'pending'));

        return '[KosenMap] 当たっていないセキュリティ更新があります (' . $count . '件)';
    }
    // 急ぎではない(補助の仕組み)が、「入っているから守られている」と思い込ませない
    if (in_array('fail2ban', $kinds, true)) {
        return '[KosenMap] fail2ban が入っているのに動いていません';
    }

    return '[KosenMap] ホストの確認: ' . count($items) . '件';
}

/**
 * 本文。**次に何をすればよいかまで書く。**
 *
 * 「更新があります」だけのメールは、受け取っても手が動かない。
 * 種別ごとに、その場で打てる手順を置く。
 */
function km_security_notice_body(
    array $items,
    string $host,
    string $os = '',
    bool $heartbeat = false,
    array $deferred = [],
    ?DateTimeImmutable $checkedAt = null
): string {
    $checkedAt ??= new DateTimeImmutable('now');

    $lines = [];
    $lines[] = $host . ' のセキュリティ状態を調べました。';
    if ($os !== '') {
        $lines[] = 'OS: ' . $os;
    }
    $lines[] = '確認した時刻: ' . $checkedAt->format('Y-m-d H:i:s P');
    $lines[] = '';

    if ($items === []) {
        $lines[] = '問題は見つかりませんでした。';
        if ($deferred !== []) {
            $lines[] = '';
            $lines[] = '(自動更新から外しているパッケージ: ' . implode(', ', $deferred) . ')';
            $lines[] = 'これは意図した設定です。Docker のデーモンを再起動するとコンテナが止まるため、';
            $lines[] = '当てるときは会期を避けて人が実行します。';
        }
        if ($heartbeat) {
            $lines[] = '';
            // **メール本文に markdown を書かない。** 受け取る側は素のテキストで読む
            $lines[] = 'これは定期のお知らせです。このメールが届かなくなったら、';
            $lines[] = '問題が無いのではなく、確認の仕組みが止まっている可能性があります。';
        }

        return implode("\n", $lines) . "\n";
    }

    $shown = array_slice($items, 0, KM_SECURITY_NOTICE_MAX_ITEMS);
    foreach ($shown as $item) {
        $lines[] = '- [' . km_security_notice_kind_label($item['kind']) . '] ' . $item['summary'];
        if ($item['detail'] !== '') {
            foreach (explode("\n", $item['detail']) as $detailLine) {
                $lines[] = '    ' . $detailLine;
            }
        }
    }
    if (count($items) > count($shown)) {
        $lines[] = sprintf('- ほか %d 件', count($items) - count($shown));
    }

    $lines[] = '';
    $lines[] = '--- 手順 ---';
    foreach (km_security_notice_actions(array_column($items, 'kind')) as $line) {
        $lines[] = $line;
    }

    if ($deferred !== []) {
        $lines[] = '';
        $lines[] = '自動更新から外しているパッケージ: ' . implode(', ', $deferred);
        $lines[] = 'これは意図した設定です(Docker のデーモン再起動でコンテナが止まるため)。';
        $lines[] = '当てるときは会期を避け、先に scripts/backup-data.ps1 を実行してください。';
    }

    return implode("\n", $lines) . "\n";
}

/** 種別の見出し。**内部の識別子をそのまま出さない。** */
function km_security_notice_kind_label(string $kind): string
{
    return [
        'reboot' => '再起動',
        'pending' => '未適用',
        'unattended' => '自動更新',
        'ssh' => 'SSH の入口',
        // notify-security.php が載る前に使っていた見出しと揃える
        'logto' => 'Logto Console',
        'fail2ban' => 'fail2ban',
        'support' => 'サポート期限',
        'disk' => '空き容量',
    ][$kind] ?? $kind;
}

/**
 * 種別ごとの手順。**出た種別のぶんだけ出す。**
 * 関係の無い手順まで並べると、どれが自分に当たるのか読み取れなくなる。
 *
 * @param array<int,string> $kinds
 * @return array<int,string>
 */
function km_security_notice_actions(array $kinds): array
{
    $lines = [];

    /*
     * **並び順は件名の優先度と揃える。**
     * 件名で「SSH が開いています」と言っておいて、本文の手順が再起動から始まると、
     * どれから手を付けるのか読み取れない。
     */
    if (in_array('ssh', $kinds, true)) {
        $lines[] = '■ SSH の入口';
        $lines[] = '  総当たりは止められません(インターネットに出ている以上、毎日来ます)。';
        $lines[] = '  意味があるのは「当てられる入口が開いているか」だけです。';
        $lines[] = '  1. 手元から鍵でログインできることを先に確かめる(閉めてから入れなくなるのを防ぐ)';
        $lines[] = '  2. /etc/ssh/sshd_config.d/ に 1 ファイル置く:';
        $lines[] = '       PasswordAuthentication no';
        $lines[] = '       KbdInteractiveAuthentication no';
        $lines[] = '       PermitRootLogin prohibit-password';
        $lines[] = '  3. sudo sshd -t   (設定の文法を確かめる。ここで失敗したら再読み込みしない)';
        $lines[] = '  4. sudo systemctl reload ssh';
        $lines[] = '  いま繋いでいる接続は切れません。別の窓でログインできることを確かめてから閉じること。';
        $lines[] = '';
    }
    if (in_array('logto', $kinds, true)) {
        $lines[] = '■ Logto Console';
        $lines[] = '  Console に入れるアカウントは、サイトの設定も利用者も変えられます。';
        $lines[] = '  パスワードだけで入れる状態は、SSH の入口が開いているのと同じ重さです。';
        $lines[] = '  サイトの利用者(default テナント)とは別の設定で、Console の画面からは変えられません。';
        $lines[] = '  1. Console を使う人に、認証アプリの入ったスマートフォンを手元に用意してもらう';
        $lines[] = '  2. 先にバックアップを取る(Logto の DB を書き換えるため)';
        $lines[] = '  3. 項目の下に書いてある psql の1行で、admin テナントの MFA を Mandatory にする';
        $lines[] = '  4. 反映されないときは docker compose restart logto';
        $lines[] = '  5. 自分で Console に入り直し、認証アプリの登録を求められることを確かめる';
        $lines[] = '';
    }
    if (in_array('reboot', $kinds, true)) {
        $lines[] = '■ 再起動';
        $lines[] = '  更新は当たっていますが、再起動するまで古いものが動き続けます。';
        $lines[] = '  1. 会期中でないことを確かめる';
        $lines[] = '  2. sudo reboot';
        $lines[] = '  3. 戻ったら docker compose ps で全部 up になっているか見る';
        $lines[] = '  4. 実際にログインできることを1回確かめる';
        $lines[] = '';
    }
    if (in_array('pending', $kinds, true)) {
        $lines[] = '■ 未適用';
        $lines[] = '  自動で当たるはずのものが残っています。まず理由を見ます。';
        $lines[] = '  sudo unattended-upgrade --dry-run --debug';
        $lines[] = '  保留(hold)されていないか: apt-mark showhold';
        $lines[] = '';
    }
    if (in_array('unattended', $kinds, true)) {
        $lines[] = '■ 自動更新';
        $lines[] = '  仕込み直します(調べるだけなら --fix を付けない)。';
        $lines[] = '  sudo /opt/kosenmap/scripts/host-updates-setup.sh --fix';
        $lines[] = '';
    }
    if (in_array('fail2ban', $kinds, true)) {
        $lines[] = '■ fail2ban';
        $lines[] = '  鍵だけで入る設定なら総当たりは当たりません。fail2ban は記録と負荷を減らす補助です。';
        $lines[] = '  ただ、入っているのに止まっていると「守られている」と思い込みます。';
        $lines[] = '  1. sudo fail2ban-client -t   (設定の文法を確かめる。ここで失敗したら起動しない)';
        $lines[] = '  2. sudo systemctl enable --now fail2ban';
        $lines[] = '  3. systemctl is-active fail2ban で active になったか見る';
        $lines[] = '  使わないと決めたなら、止めたまま置かずに外してください(入っていなければ知らせません)。';
        $lines[] = '';
    }
    if (in_array('support', $kinds, true)) {
        $lines[] = '■ サポート期限';
        $lines[] = '  期限が切れるとセキュリティ更新そのものが出なくなります。';
        $lines[] = '  入れ替えは会期を避けて計画してください(先にバックアップ)。';
        $lines[] = '';
    }
    if (in_array('disk', $kinds, true)) {
        $lines[] = '■ 空き容量';
        $lines[] = '  足りないと apt も DB も静かに失敗します。';
        $lines[] = '  docker system df / sudo journalctl --vacuum-time=14d';
        $lines[] = '  docker image prune -f  (使っていないイメージだけ消えます)';
        $lines[] = '';
    }

    if ($lines !== [] && end($lines) === '') {
        array_pop($lines);
    }

    return $lines;
}
