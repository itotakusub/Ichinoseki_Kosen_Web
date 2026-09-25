<?php

declare(strict_types=1);

/**
 * 地図データ公開設定の読み書き。admin/map-settings.php と api/map-data.php が共有する。
 *
 * ## 2つの軸がある
 *
 * | 設定 | 何を決めるか | 値 |
 * |---|---|---|
 * | `mode` | **教職員氏名**を出すか | hidden / password / public |
 * | `mapMode` | **地図そのもの**(ノード・経路・見取り図)を出すか | public / password |
 * |---|---|---|
 *
 * **分けてあるのは、片方だけ閉じたい場面があるため。** いまの本番は
 * 「地図は誰でも見られる / 氏名は誰にも出さない」(`mapMode=public`, `mode=hidden`)。
 * 逆に「地図はパスワード必須 / 氏名は解除した人に出す」も選べる。
 *
 * **パスワードは1つを共有する。** 2つ持たせると、どちらを聞かれているのか
 * 利用者に分からない。`$_SESSION['km_map_unlocked']` も1つで、
 * 一度入れれば両方の錠が開く。
 */

const KM_MAP_ACCESS_MODES = ['hidden', 'password', 'public'];

/** 地図そのものの公開範囲。**既定は public**(これまでの動きを変えない)。 */
const KM_MAP_VIEW_MODES = ['public', 'password'];

function km_map_access_config_path(): string
{
    return __DIR__ . '/../config/map-access.local.php';
}

/** @return array{mode:string,mapMode:string,passwordHash:?string,teacherSeesHidden:bool} */
function km_map_access_config(): array
{
    $path = km_map_access_config_path();
    $config = is_file($path) ? require $path : [];

    $mode = $config['mode'] ?? 'password';
    if (!in_array($mode, KM_MAP_ACCESS_MODES, true)) {
        $mode = 'password';
    }

    /*
     * **既定は public。** 設定ファイルにこの項目が無い(= これまでの版で書かれた)
     * 場合は、地図は誰でも見られる従来どおりの動きになる。
     * 未知の値も public に落とす —— ここで password に倒すと、
     * 設定を書き損じた瞬間に公開ページが全部閉じる。
     */
    $mapMode = $config['mapMode'] ?? 'public';
    if (!in_array($mapMode, KM_MAP_VIEW_MODES, true)) {
        $mapMode = 'public';
    }

    return [
        'mode' => $mode,
        'mapMode' => $mapMode,
        'passwordHash' => is_string($config['passwordHash'] ?? null) ? $config['passwordHash'] : null,
        /*
         * **hidden でも教職員には氏名を見せるか**(docs/15 段 D。2026-09-18)。**既定は見せない** ——
         * hidden は「誰にも出さない」ための止めどころで、既定で抜け道を作らない。
         * 厳密に true のときだけ有効(書き損じは見せない側に倒す)。
         */
        'teacherSeesHidden' => ($config['teacherSeesHidden'] ?? false) === true,
    ];
}

/**
 * 設定ファイルを書き換える。admin/map-settings.php からだけ呼ぶ。
 * $newPassword が null なら現在のハッシュを維持する(モードだけ変える場合)。
 */
function km_map_access_save(string $mode, ?string $newPassword, ?string $mapMode = null, ?bool $teacherSeesHidden = null): void
{
    if (!in_array($mode, KM_MAP_ACCESS_MODES, true)) {
        throw new InvalidArgumentException('不正な公開モードです。');
    }
    if ($mapMode !== null && !in_array($mapMode, KM_MAP_VIEW_MODES, true)) {
        throw new InvalidArgumentException('不正な地図の公開範囲です。');
    }

    $current = km_map_access_config();
    $passwordHash = $newPassword !== null && $newPassword !== ''
        ? password_hash($newPassword, PASSWORD_DEFAULT)
        : $current['passwordHash'];
    $resolvedMapMode = $mapMode ?? $current['mapMode'];
    $resolvedTeacherSeesHidden = $teacherSeesHidden ?? $current['teacherSeesHidden'];

    /*
     * **パスワードが無いのに password を選ばせない。**
     * 錠だけ掛かって鍵が無い状態になり、誰も —— 管理者以外 —— 開けられなくなる。
     */
    if ($passwordHash === null && ($mode === 'password' || $resolvedMapMode === 'password')) {
        throw new InvalidArgumentException(
            'パスワードが未設定です。パスワードを入力してから「パスワードが必要」を選んでください。'
        );
    }

    $php = "<?php\n\ndeclare(strict_types=1);\n\n"
        . "// admin/map-settings.php が書き換える。手動編集しないこと。\n"
        . "return [\n"
        . "    'mode' => " . var_export($mode, true) . ",\n"
        . "    'mapMode' => " . var_export($resolvedMapMode, true) . ",\n"
        . "    'passwordHash' => " . var_export($passwordHash, true) . ",\n"
        . "    'teacherSeesHidden' => " . var_export($resolvedTeacherSeesHidden, true) . ",\n"
        . "];\n";

    km_map_access_write_config(km_map_access_config_path(), $php);
}

/**
 * 設定ファイルを差し替える。**2通りの書き方を順に試す。**
 *
 * ## なぜ一時ファイルだけでは駄目だったのか
 *
 * 本来は「一時ファイルへ書いてから rename」が正しい。rename は不可分なので、
 * 書いている最中に公開ページが `require` しても、途中までの PHP を読むことがない。
 * 途中まで読むと `require` が致命的エラーになり、**サイト全体が 500 になる**。
 *
 * ところが本番ではこの一時ファイルを作れない。`src/config/` は配備利用者(km)の
 * ものだが、PHP は www-data として動く。`Old/docs/vps-migration.md` の手順は
 * **`*.local.php` だけ** を `chown 33` しているので、
 *
 *   - 既存ファイルへの書き込み … できる(所有者が www-data、0640)
 *   - ディレクトリへの新規作成 … **できない**(`map-access.local.php.tmp` が作れない)
 *
 * となる。これが「保存に失敗しました。サーバーのログを確認してください。」の正体で、
 * **管理画面からの保存は本番で一度も成功していなかった**。
 *
 * ディレクトリを www-data に開けて解決しないのは、PHP 側に穴が空いたときに
 * **設定ファイルを新しく置かれる**ことになるため。書き込みは既存の1ファイルに留める。
 *
 * 直接書く方は不可分ではない。それでも許容するのは、書くのが 200 バイト程度で
 * 1回の write に収まること、管理者が保存を押した瞬間しか起きないことによる。
 * **保存できないことの害の方が大きい。**
 */
function km_map_access_write_config(string $path, string $php): void
{
    /*
     * 失敗の理由を掴む。`display_errors = Off` なので警告は画面に出ず、
     * false が返ってくるだけでは「なぜ書けないのか」が分からない。
     * error_get_last() は無関係な古い警告を拾うことがあるため、ここで捕まえる。
     */
    $failure = null;
    set_error_handler(static function (int $severity, string $message) use (&$failure): bool {
        $failure = $message;

        return true;   // 既定のハンドラへ渡さない
    });

    try {
        if (is_writable(dirname($path))) {
            $tmp = $path . '.tmp';
            if (file_put_contents($tmp, $php, LOCK_EX) !== false && rename($tmp, $path)) {
                // rename で作り直すと権限が umask 任せ(通常 0644)になる。
                // パスワードのハッシュを同じホストの他の利用者に見せない。
                chmod($path, 0640);

                return;
            }
            if (is_file($tmp)) {
                unlink($tmp);   // 途中まで書けた .tmp を残さない
            }
        }

        if (file_put_contents($path, $php, LOCK_EX) !== false) {
            return;
        }
    } finally {
        restore_error_handler();
    }

    throw new RuntimeException(sprintf(
        '設定ファイル %s へ書き込めませんでした(%s)。'
            . 'ホストで次を実行して、PHP に書き込み権限を与えてください: '
            . 'docker compose exec -u root web chown www-data config/%s'
            . ' && docker compose exec -u root web chmod 640 config/%s',
        $path,
        $failure ?? '原因不明',
        basename($path),
        basename($path)
    ));
}

/**
 * このセッションで**利用者がパスワードを入れたか**。2つの錠が共有する。
 *
 * **管理者かどうかは見ない。** 以前は admin/_inc/guard.php がこの同じ印を立てており、
 * 管理画面を開いた人は公開ページでも錠の内側に入っていた。その結果:
 *
 *   - 「パスワードが必要」に設定した本人が、公開ページで**普通に地図を見られてしまう**。
 *     設定が効いていないようにしか見えず、実際そう報告された
 *   - `mode = 'hidden'` にしてあっても、管理者には教職員氏名が出ていた
 *
 * 管理者の抜け道は [km_map_admin_session] に分けてある。**印を分ければ、
 * 公開ページは管理者に対しても設定どおりに閉じる** —— 設定した本人が確かめられる。
 */
function km_map_password_entered(array $config): bool
{
    /*
     * **どのパスワードで解いたかまで見る**(2026-09-25、診断 W-46)。
     *
     * 以前は印が true かだけを見ていたので、パスワードが漏れて替えても、
     * **漏れたパスワードで既に解除した人は教職員氏名を見続けられた。**
     * いまは解除したときの設定の要約(km_map_password_fingerprint)を入れておき、今の設定と比べる。
     * 替えた瞬間に、全員がもう一度パスワードを聞かれる(意図どおり)。
     * 以前の形(true)は一致しないので、配備のあと一度だけ入れ直しになる。
     */
    $stored = $_SESSION['km_map_unlocked'] ?? null;
    $current = km_map_password_fingerprint($config['passwordHash'] ?? null);

    return is_string($stored) && $current !== null && hash_equals($current, $stored);
}

/**
 * パスワードの設定の要約。**解除の印に入れ、今の設定と比べるためだけに使う。**
 *
 * password_hash() の出力は毎回違う塩を持つので、同じパスワードを入れ直しても要約は変わる
 * (= 「保存し直した」で全員の解除が外れる。外したいときの操作としても使える)。
 * 未設定なら null(誰も解除できない)。
 */
function km_map_password_fingerprint(?string $passwordHash): ?string
{
    if ($passwordHash === null || $passwordHash === '') {
        return null;
    }

    return hash('sha256', 'km-map-unlock|' . $passwordHash);
}

/**
 * このブラウザセッションが**管理画面を通っているか**。admin/_inc/guard.php が立てる。
 *
 * 使ってよいのは「管理者向けの面」だけ:
 *   - admin/map-editor.php …… km_map_data(..., forAdmin: true) で錠を素通りする
 *   - api/floor-image.php …… その編集画面が読む見取り図
 *
 * **公開ページの判定に混ぜないこと。** 混ぜると上の不具合に戻る。
 *
 * 印は認証の代わりではない。guard.php が Logto の確認・停止確認・スコープ確認を
 * すべて通した**後**に立てるので、立っていること自体が「管理者として通った」の記録になる。
 */
function km_map_admin_session(): bool
{
    return ($_SESSION['km_map_admin'] ?? false) === true;
}

/**
 * このセッションが**教職員として通っているか**(docs/15 段 D。index.php が立てる)。
 *
 * 印は「いつまで有効か」の時刻で持つ。**ID トークンの期限まで**しか効かない ——
 * 管理画面で取り消しても、長く開きっぱなしのブラウザに権限が残り続けないように(API のトークンと同じ最長 1 時間)。
 * 立てる側(index.php)は、ID トークンの organization_roles と、アカウントが止められていないことを確かめてから立てる。
 */
function km_map_teacher_session(): bool
{
    $until = $_SESSION['km_map_teacher_until'] ?? 0;

    return is_int($until) && $until > time();
}

/** 現在のリクエストで occupant_name を返してよいか。 */
function km_map_names_unlocked(array $config): bool
{
    return match ($config['mode']) {
        'public' => true,
        // hidden は既定で誰にも出さない。管理画面で「教職員には見せる」にしたときだけ教職員に出す
        'hidden' => ($config['teacherSeesHidden'] ?? false) === true && km_map_teacher_session(),
        'password' => km_map_password_entered($config) || km_map_teacher_session(),
        default => false,
    };
}

/**
 * 現在のリクエストで**地図そのもの**(ノード・経路・見取り図)を返してよいか。
 *
 * ここが false のとき、公開ページは地図を1件も描かない。
 * `api/map-data.php` は 403、見取り図は `api/floor-image.php` が断る。
 * **3経路とも同じこの関数を見る** —— どれか1つでも別の判定を書くと、そこから漏れる。
 */
function km_map_view_unlocked(array $config): bool
{
    return match ($config['mapMode']) {
        'public' => true,
        // 教職員はパスワード無しで地図を見られる(docs/15 段 D)
        'password' => km_map_password_entered($config) || km_map_teacher_session(),
        default => true,
    };
}

/**
 * パスワードの入力欄を出すべきか。
 *
 * **どちらか一方でもパスワードを要求していれば出す。** 以前は氏名の mode だけを見ており、
 * 地図側だけ password にすると入力欄が出ないまま何も見えなくなる。
 */
function km_map_password_requested(array $config): bool
{
    return $config['mode'] === 'password' || $config['mapMode'] === 'password';
}
