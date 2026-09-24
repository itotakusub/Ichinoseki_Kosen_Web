<?php

declare(strict_types=1);

/**
 * アカウント設定(サインインした人なら誰でも使える公開ページ)。
 *
 * ## なぜ管理画面と別に要るのか
 *
 * 同じ内容が admin/profile.php にもあるが、**あちらは管理者しか開けない**
 * (guard.php が `admin:users:read` を要求し、無ければ 403 へ飛ばす)。
 * 一般ログインの利用者は自分の表示名すら変えられなかった ——
 * **その表示名はアプリのランキングに出る**ので、変えたい人が必ず出る。
 *
 * Account API は本人のトークンで本人の情報だけを触るものなので、
 * 管理者かどうかは元から関係が無い。判定を借りていたのが誤りだった。
 *
 * ## 管理画面とは体裁だけが違う
 *
 * 中身の処理は lib/logto-account.php に集約してある。ここと admin/profile.php は
 * **同じ関数を呼ぶ**ので、片方だけ本人確認を省くような食い違いが起きない。
 */

require __DIR__ . '/logto-client.php';   // $client, $appUrl。セッションもここで始まる
// 削除が自動で片付くかを、その場で確かめて言うため(鍵が無ければ何も飛んでこない)
require_once __DIR__ . '/lib/logto-webhook.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/logto-account.php';
require_once __DIR__ . '/lib/legal.php';   // km_legal_e()
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/csp.php';
require_once __DIR__ . '/lib/user-error.php';
// 停止判定(km_logto_user_is_suspended)。管理画面の guard.php と同じ関数を使う
require_once __DIR__ . '/lib/logto-management.php';

km_csp_send('public');

/*
 * サインインしているか。**していなくてもページは出す** —— リダイレクトすると
 * 「押したら知らない画面へ飛ばされた」になる。ここで案内してから送る。
 */
$signedIn = false;
$claims = null;
/** 停止されていた / 判定できなかった。サインインの案内の代わりに理由を出す */
$blockedReason = null;
try {
    $signedIn = $client->isAuthenticated();
    if ($signedIn) {
        $claims = $client->getIdTokenClaims();
    }
} catch (Throwable $exception) {
    error_log('account.php: Logto session check failed: ' . $exception->getMessage());
    $signedIn = false;
}

/*
 * **停止されたアカウントを通さない**(2026-09-14、診断 server-authz#1)。
 *
 * このページだけ停止判定をしていなかった。Logto で止めても Web のセッションは残るので、
 * 止められた本人が表示名を変えたり、**アカウントを消して監査ログの自分の行を
 * 匿名化したり**できる余地があった(guard.php・gate.php・logto_guard.php は判定済み)。
 *
 * 判定できないときも通さない(fail closed。guard.php と同じ)。そのときはセッションを
 * 捨てない —— Logto の一時的な不調で、止められていない人まで追い出さないため。
 * 削除の直前にもう一度は呼ばない: 同じリクエストの中で、上の判定から数ミリ秒しか経たない。
 */
if ($signedIn) {
    $subjectForCheck = (string) ($claims->sub ?? '');
    try {
        if ($subjectForCheck === '' || km_logto_user_is_suspended($subjectForCheck)) {
            $KM_USER = [
                'sub' => $subjectForCheck,
                'name' => (string) ($claims->name ?? $claims->username ?? $subjectForCheck),
            ];
            require_once __DIR__ . '/lib/admin-log.php';
            km_admin_log_record('auth', 'account.suspended_blocked', 'account.php');
            error_log('account.php: suspended account was blocked: ' . $subjectForCheck);
            unset($KM_USER);
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            $blockedReason = 'suspended';
            $signedIn = false;
        }
    } catch (Throwable $exception) {
        error_log('account.php: 停止判定ができないため通しません: ' . $exception::class . ': ' . $exception->getMessage());
        $blockedReason = 'unknown';
        $signedIn = false;
    }
}

$errors = [];
$notice = null;
$account = null;
$accountUnavailable = false;

if ($signedIn) {
    /*
     * 監査ログの実行者。admin/_inc/guard.php が組み立てるものと同じ形にしておく。
     * これが無いと、公開ページからの変更が「誰の操作か分からない行」として残る。
     */
    $KM_USER = [
        'sub' => (string) ($claims->sub ?? ''),
        'name' => (string) ($claims->name ?? $claims->username ?? $claims->sub ?? ''),
    ];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !km_csrf_verify()) {
        // 副作用を起こす前に弾く
        $errors[] = 'セッションの有効期限が切れています。ページを再読み込みしてからやり直してください。';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try {
            /*
             * 教職員の権限の申請(docs/15。2026-09-18)。**申請するだけで、権限はまだ付かない** ——
             * 管理者が管理画面で承認したときに付く。アクセストークンは要らないので、取る前に処理する。
             */
            if (($_POST['do_account'] ?? '') === 'staff_request') {
                require_once __DIR__ . '/lib/staff-org.php';
                require_once __DIR__ . '/lib/admin-log.php';
                if (!km_staff_org_enabled()) {
                    throw new KmLogtoAccountException('いまは申請を受け付けていません。');
                }
                $email = isset($claims->email) && is_string($claims->email) ? $claims->email : null;
                $note = (string) ($_POST['staff_note'] ?? '');
                try {
                    $requestId = km_staff_request_create(km_db(), (string) $KM_USER['sub'], $email, (string) $KM_USER['name'], $note);
                } catch (InvalidArgumentException $exception) {
                    // 文言は利用者向けに作ってある(既に申請中・長すぎる など)
                    throw new KmLogtoAccountException($exception->getMessage());
                }
                km_admin_log_record('auth', 'staff.requested', '#' . $requestId);
                km_staff_request_notify($requestId, (string) $KM_USER['name'], $email, trim($note));
                header('Location: /account.php?staff=1', true, 302);
                exit;
            }

            /*
             * 教職員による地点の変更の提案(docs/15 段 G)。**地図にはまだ入らない** —— 管理者が承認したときに入る。
             * 教職員かどうかは**今のサインインの ID トークン**(organization_roles)で確かめる。
             * 申請の表だけで決めると、取り消したあとも続くセッションから送れてしまう。
             */
            if (($_POST['do_account'] ?? '') === 'staff_node_edit') {
                require_once __DIR__ . '/lib/staff-nodes.php';
                require_once __DIR__ . '/lib/admin-log.php';
                $isTeacherNow = km_staff_org_claims_is_teacher(
                    isset($claims->organization_roles) && is_array($claims->organization_roles) ? $claims->organization_roles : null
                );
                if (!$isTeacherNow) {
                    throw new KmLogtoAccountException('教職員の権限が今のサインインにありません。一度サインアウトしてから入り直してください。');
                }
                $nodeId = (string) ($_POST['node_id'] ?? '');
                $fields = [];
                foreach (array_keys(KM_STAFF_NODE_FIELDS) as $field) {
                    if (isset($_POST['node_' . $field]) && is_string($_POST['node_' . $field])) {
                        $fields[$field] = $_POST['node_' . $field];
                    }
                }
                try {
                    $pdo = km_db();
                    $editId = km_staff_node_propose($pdo, $nodeId, (string) $KM_USER['sub'], $fields);
                } catch (InvalidArgumentException $exception) {
                    throw new KmLogtoAccountException($exception->getMessage());
                }
                if ($editId === null) {
                    header('Location: /account.php?node=same', true, 302);
                    exit;
                }
                km_admin_log_record('content', 'staffnode.proposed', '#' . $editId . ' ' . $nodeId);
                $node = km_map_node_find($pdo, $nodeId);
                km_staff_node_edit_notify($editId, (string) $KM_USER['name'], (string) ($node['name'] ?? $nodeId));
                header('Location: /account.php?node=sent', true, 302);
                exit;
            }

            $token = $client->getAccessToken();
            if (!is_string($token) || $token === '') {
                throw new KmLogtoAccountException(
                    'サインインの有効期限が切れています。入り直してからお試しください。'
                );
            }

            require_once __DIR__ . '/lib/admin-log.php';

            if (($_POST['do_account'] ?? '') === 'delete') {
                /*
                 * アカウントの削除。**Account API には削除が無い**(2026-09-03 に実測)ので、
                 * Management API の `DELETE /api/users/{id}` を、こちらが代理で呼ぶ。
                 *
                 * ## 消すのは「サインインしている本人」だけ
                 *
                 * 利用者が id を選べる余地を作らない。渡すのは `$KM_USER['sub']` ——
                 * トークンから来た値で、フォームからは触れない。
                 *
                 * ## パスワードを1度確かめる
                 *
                 * アクセストークンだけで消せると、**端末を借りられた場面でそのまま
                 * 消される。** パスワード変更と同じ確認をここでも通す
                 * (判定は km_logto_account_verify_password の1箇所)。
                 */
                require_once __DIR__ . '/lib/account-delete.php';
                require_once __DIR__ . '/lib/logto-management.php';

                km_logto_account_verify_password($token, (string) ($_POST['current_password'] ?? ''));

                $subject = (string) $KM_USER['sub'];
                if ($subject === '') {
                    throw new KmLogtoAccountException('サインインの情報を読み取れませんでした。');
                }

                /*
                 * **こちらのデータを先に消し、Logto は後。**
                 *
                 * 逆にすると、Logto の削除だけ成功して後片付けが失敗したときに、
                 * **もう誰のものか分からない行が残る**(`user_id` しか手がかりが無く、
                 * Logto から引けなくなる)。
                 *
                 * この順なら、Logto 側が失敗しても「データが消えたアカウント」が
                 * 残るだけで、もう一度実行すれば片付く。**見えない残骸を作らない。**
                 */
                $cleanup = km_account_delete_data(km_db(), $subject);
                /*
                 * **実行者を残さない。** 消した本人がサインイン中なので、
                 * 既定のままだと `$KM_USER` から名前と ID が入り、
                 * **後片付けが匿名化した直後に、消えた人の名前が1行だけ蘇る。**
                 * 実際にそうなっていた(タイムラインに「Test が…」と出た)。
                 */
                km_admin_log_record(
                    'system',
                    'account.deleted',
                    km_account_delete_summary($cleanup),
                    true
                );

                km_logto_management_delete_user($subject);

                /*
                 * **セッションを捨ててから出す。** 残すと、消えたアカウントの
                 * トークンで画面が動き続け、次の操作が意味不明な失敗になる。
                 */
                $_SESSION = [];
                session_destroy();
                header('Location: /?account_deleted=1', true, 302);
                exit;
            }

            if (($_POST['do_account'] ?? '') === 'password') {
                km_logto_account_change_password(
                    $token,
                    (string) ($_POST['current_password'] ?? ''),
                    (string) ($_POST['new_password'] ?? '')
                );
                // **新しいパスワードそのものは記録しない。** 変えたという事実だけ
                km_admin_log_record('auth', 'account.password');
                header('Location: /account.php?password=1', true, 302);
                exit;
            }

            km_logto_account_update_profile($token, [
                'username' => (string) ($_POST['account_username'] ?? ''),
                'name' => (string) ($_POST['account_name'] ?? ''),
            ]);
            km_admin_log_record('auth', 'account.profile');
            header('Location: /account.php?saved=1', true, 302);
            exit;
        } catch (KmLogtoAccountException $exception) {
            // 文言は利用者向けに作ってあるのでそのまま出す
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('account.php update failed: ' . $exception->getMessage());
            // 照合用 ID を添える(問い合わせを受けたとき、ログの行を引けるように)
            $errors[] = km_user_error_message($exception, 'アカウントを更新できませんでした。');
        }
    }

    if (isset($_GET['saved'])) {
        $notice = 'アカウントの情報を変更しました。';
    } elseif (isset($_GET['password'])) {
        $notice = 'パスワードを変更しました。';
    } elseif (isset($_GET['staff'])) {
        $notice = '教職員の権限を申請しました。管理者が確認して承認すると使えるようになります。';
    } elseif (($_GET['node'] ?? '') === 'sent') {
        $notice = '地点の変更を送りました。管理者が確認して承認すると地図に反映されます。';
    } elseif (($_GET['node'] ?? '') === 'same') {
        $notice = '変更された項目が無かったため、送りませんでした(未処理の提案があれば取り下げました)。';
    }

    /*
     * 教職員の申請の状態。**機能が無効なら欄ごと出さない**(組織 ID が空 = 受け付けていない)。
     * 読めなくてもページは出す(他の欄まで巻き込まない)。
     */
    $staffEnabled = false;
    $staffLatest = null;
    try {
        require_once __DIR__ . '/lib/staff-org.php';
        $staffEnabled = km_staff_org_enabled();
        if ($staffEnabled) {
            $staffLatest = km_staff_request_latest(km_db(), (string) $KM_USER['sub']);
        }
    } catch (Throwable $exception) {
        error_log('account.php staff request status failed: ' . $exception->getMessage());
        $staffEnabled = false;
    }

    /*
     * 割り当てられた地点(段 G)。**今のサインインで教職員のときだけ**読む。
     * 読めなくても他の欄は出す。
     */
    $staffActive = false;
    $staffNodes = [];
    if ($staffEnabled && ($staffLatest['status'] ?? null) === 'approved') {
        $staffActive = km_staff_org_claims_is_teacher(
            isset($claims->organization_roles) && is_array($claims->organization_roles) ? $claims->organization_roles : null
        );
        if ($staffActive) {
            try {
                require_once __DIR__ . '/lib/staff-nodes.php';
                $pdo = km_db();
                $pendingByNode = [];
                foreach (km_staff_node_edits($pdo, 'pending', (string) $KM_USER['sub']) as $edit) {
                    $pendingByNode[$edit['nodeId']] = $edit;
                }
                foreach (km_staff_node_assignments($pdo, (string) $KM_USER['sub']) as $assignment) {
                    $node = km_map_node_find($pdo, $assignment['nodeId']);
                    if ($node === null) {
                        continue;
                    }
                    $staffNodes[] = [
                        'id' => $assignment['nodeId'],
                        'label' => (string) ($node['name'] ?? $assignment['nodeId']),
                        'fields' => km_staff_node_current_fields($pdo, $node),
                        'pending' => $pendingByNode[$assignment['nodeId']] ?? null,
                    ];
                }
            } catch (Throwable $exception) {
                error_log('account.php staff nodes failed: ' . $exception->getMessage());
                $staffNodes = [];
            }
        }
    }

    /*
     * いまの値。読めなくてもページは出す —— Account API が無効でも、
     * 「使えない」と伝えられる画面がある方がよい。
     */
    try {
        $token = $client->getAccessToken();
        $account = is_string($token) && $token !== '' ? km_logto_account_profile($token) : null;
        $accountUnavailable = $account === null;
    } catch (Throwable $exception) {
        error_log('account.php fetch failed: ' . $exception->getMessage());
        $accountUnavailable = true;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>アカウント設定 | 高専マップ案内</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= km_legal_e(km_public_asset('Main/styles.css')) ?>">
    <?php // 文書ページと同じ外枠。フォームの見た目だけ下で足す ?>
    <link rel="stylesheet" href="<?= km_legal_e(km_public_asset('Main/document.css')) ?>">
    <style<?= km_csp_nonce_attr() ?>>
        .km-account-field { margin-bottom: 1.1rem; }

        .km-account-field label {
            display: block;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.35rem;
        }

        /* input[type="text"] は styles.css が拾うが、password は型で選ばれていないので拾わない */
        .km-account-field input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-md);
            font: inherit;
            background: #fff;
        }

        .km-account-hint {
            font-size: 0.85rem;
            color: var(--secondary);
            margin: 0.35rem 0 0;
            line-height: 1.6;
        }

        .km-account-alert {
            border-radius: var(--radius-md);
            padding: 0.85rem 1.1rem;
            margin-bottom: 1rem;
            line-height: 1.7;
        }

        /* 取り返しがつかない操作。**他の欄と同じ見た目にしない** */
        .km-account-danger {
            border: 1px solid #dc2626;
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-top: 0.75rem;
        }

        .km-account-danger-button {
            border: 1px solid #dc2626;
            background: #fee2e2;
            color: #7f1d1d;
            border-radius: var(--radius-md);
            padding: 0.6rem 1.1rem;
            font: inherit;
            cursor: pointer;
        }

        /* サインアウトはフォームになった(GET で出られないようにした)。見た目はリンクのまま */
        .km-signout-form { display: contents; }
        button.km-doc-link { background: none; font: inherit; cursor: pointer; }

        .km-account-alert.ok { background: #dcfce7; border: 1px solid #16a34a; color: #14532d; }
        .km-account-alert.err { background: #fee2e2; border: 1px solid #dc2626; color: #7f1d1d; }
        .km-account-alert.warn { background: #fef3c7; border: 1px solid #f59e0b; color: #78350f; }
    </style>
</head>

<body>
    <header class="km-doc-header">
        <h1>アカウント設定</h1>
        <nav class="km-doc-nav">
            <?php if ($signedIn): ?>
                <?php // POST + CSRF でだけ出られる(sign-out.php の説明) ?>
                <form method="post" action="/sign-out.php" class="km-signout-form">
                    <?= km_csrf_field() ?>
                    <button type="submit" class="km-doc-link">サインアウト</button>
                </form>
            <?php endif; ?>
            <a href="/" class="km-doc-link">地図へ戻る</a>
        </nav>
    </header>

    <main class="km-doc-main">
        <?php if ($blockedReason === 'suspended'): ?>
            <div class="km-account-alert err" role="alert">
                このアカウントは停止されています。心当たりが無い場合は
                <a href="/contact.php">お問い合わせ</a>からご連絡ください。
            </div>
        <?php elseif ($blockedReason === 'unknown'): ?>
            <div class="km-account-alert warn" role="alert">
                アカウントの状態を確認できませんでした。時間をおいてもう一度お試しください。
            </div>
        <?php endif; ?>
        <?php if (!$signedIn): ?>
            <section class="km-doc-section">
                <h2>サインインが必要です</h2>
                <p>このページでは、ご自身の利用者名・表示名・パスワードを変更できます。</p>
                <p>
                    <a href="/sign-in.php?mode=signIn&amp;return=<?= km_legal_e(rawurlencode('/account.php')) ?>"
                       class="km-doc-link">サインインする</a>
                </p>
            </section>
        <?php else: ?>
            <?php if ($notice !== null): ?>
                <div class="km-account-alert ok" role="status"><?= km_legal_e($notice) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $message): ?>
                <div class="km-account-alert err" role="alert"><?= km_legal_e($message) ?></div>
            <?php endforeach; ?>
            <?php if ($accountUnavailable): ?>
                <div class="km-account-alert warn" role="alert">
                    アカウント情報を読み込めませんでした。一度サインアウトして入り直すと解決することがあります。
                    それでも直らない場合は、Logto Console の「Sign-in &amp; account」&gt;「Account center」で
                    項目が有効になっているかを管理者にご確認ください(既定は Off です)。
                </div>
            <?php endif; ?>

            <section class="km-doc-section">
                <h2>表示名</h2>
                <form method="post">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="do_account" value="profile">

                    <?php
                    /*
                     * **編集できる項目だけを入力欄にする。**
                     * 何が編集できるかは lib/logto-account.php の
                     * KM_LOGTO_ACCOUNT_EDITABLE(= Logto Console の設定と対)。
                     * 出しておいて保存時に断られるのが、いちばん質の悪い作りになる。
                     */
                    ?>
                    <?php if (km_logto_account_field_editable('username')): ?>
                        <div class="km-account-field">
                            <label for="km-account-username">利用者名</label>
                            <input type="text" id="km-account-username" name="account_username"
                                   autocomplete="username"
                                   value="<?= km_legal_e((string) ($account['username'] ?? '')) ?>">
                        </div>
                    <?php elseif (($account['username'] ?? '') !== ''): ?>
                        <div class="km-account-field">
                            <label>利用者名</label>
                            <p class="km-account-hint">
                                <code><?= km_legal_e((string) $account['username']) ?></code>
                                —— サインインに使う識別子のため変更できません。
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="km-account-field">
                        <label for="km-account-name">表示名</label>
                        <input type="text" id="km-account-name" name="account_name"
                               value="<?= km_legal_e((string) ($account['name'] ?? '')) ?>">
                        <p class="km-account-hint">
                            アプリのランキングに出るのはこの名前です。メールアドレスは出ません。
                        </p>
                    </div>

                    <button type="submit" class="primary-btn">この内容にする</button>
                    <p class="km-account-hint">空欄にした項目は変更しません。</p>
                </form>
            </section>

            <section class="km-doc-section">
                <h2>パスワードの変更</h2>
                <form method="post">
                    <?= km_csrf_field() ?>
                    <input type="hidden" name="do_account" value="password">

                    <div class="km-account-field">
                        <label for="km-current-password">現在のパスワード</label>
                        <input type="password" id="km-current-password" name="current_password"
                               autocomplete="current-password" required>
                        <?php
                        /*
                         * **現在のパスワードを必ず聞く。** サインイン済みというだけで
                         * 変えられると、端末を借りられた場面でそのまま乗っ取られる。
                         */
                        ?>
                        <p class="km-account-hint">本人であることの確認に使います。</p>
                    </div>

                    <div class="km-account-field">
                        <label for="km-new-password">新しいパスワード</label>
                        <input type="password" id="km-new-password" name="new_password"
                               autocomplete="new-password" required>
                        <p class="km-account-hint">長さと文字種の条件は Logto の設定によります。</p>
                    </div>

                    <button type="submit" class="primary-btn">パスワードを変更する</button>
                </form>
            </section>

            <?php if ($staffEnabled): ?>
                <?php
                /*
                 * 教職員の権限の申請(docs/15)。**本人の申告だけでは付かない** —— 管理者が承認したときに付く。
                 * 付くと、地図の教職員氏名と閲覧不可の地点が見えるので、何が起きるかを先に書く。
                 */
                $staffStatus = $staffLatest['status'] ?? null;
                ?>
                <section class="km-doc-section">
                    <h2>教職員の権限</h2>
                    <?php if ($staffStatus === 'approved'): ?>
                        <?php // 今のサインインに載っているか($staffActive。ID トークンの organization_roles)。承認の前から続くセッションには無い ?>
                        <?php if ($staffActive): ?>
                            <p><strong>承認されています。</strong>教職員として地図の情報を見られます。</p>
                        <?php else: ?>
                            <p><strong>承認されています。</strong>ただし、いまのサインインにはまだ反映されていません。</p>
                            <p class="km-account-hint">
                                一度サインアウトしてから入り直してください(権限はサインインのときに受け取ります)。
                            </p>
                        <?php endif; ?>
                    <?php elseif ($staffStatus === 'pending'): ?>
                        <p><strong>申請を受け付けています。</strong>管理者の承認をお待ちください。</p>
                    <?php else: ?>
                        <p>
                            教職員の方は、ここから申請できます。<strong>管理者が確認して承認したときに</strong>使えるようになります
                            (教職員の氏名と、一般には表示しない地点が見えるようになります)。
                        </p>
                        <?php if ($staffStatus === 'rejected' || $staffStatus === 'revoked'): ?>
                            <p class="km-account-hint">以前の申請は承認されていません。心当たりが無い場合は管理者へお問い合わせください。</p>
                        <?php endif; ?>
                        <form method="post">
                            <?= km_csrf_field() ?>
                            <input type="hidden" name="do_account" value="staff_request">
                            <div class="km-account-field">
                                <label for="km-staff-note">お名前と所属(承認の確認に使います)</label>
                                <input type="text" id="km-staff-note" name="staff_note"
                                       maxlength="<?= (int) KM_STAFF_REQUEST_NOTE_MAX ?>" required
                                       placeholder="例: 高専 太郎 / 電気情報工学科">
                            </div>
                            <button type="submit" class="primary-btn">教職員として申請する</button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($staffActive && $staffNodes !== []): ?>
                <?php
                /*
                 * 担当の地点(docs/15 段 G)。**送っても地図にはすぐ入らない** —— 管理者が確かめて承認したときに入る。
                 * 位置・種類・経路は変えられない(管理者が地図編集で直す)。
                 */
                ?>
                <section class="km-doc-section">
                    <h2>担当の地点</h2>
                    <p>
                        管理者から割り当てられた地点の表示を直せます。<strong>送った内容は管理者が確認して承認したときに</strong>地図へ反映されます。
                        位置や種類を直したいときは、管理者へお知らせください。
                    </p>
                    <?php foreach ($staffNodes as $staffNode): ?>
                        <?php $f = $staffNode['fields']; $p = $staffNode['pending']; ?>
                        <form method="post" class="km-staff-node">
                            <?= km_csrf_field() ?>
                            <input type="hidden" name="do_account" value="staff_node_edit">
                            <input type="hidden" name="node_id" value="<?= km_legal_e($staffNode['id']) ?>">
                            <h3><?= km_legal_e($staffNode['label']) ?></h3>
                            <?php if ($p !== null): ?>
                                <p class="km-account-hint">
                                    <strong>確認待ちの変更があります</strong>(<?= km_legal_e(date('Y-m-d H:i', $p['createdAtEpoch'])) ?> に送信)。
                                    もう一度送ると、前の変更と置き換わります。
                                </p>
                            <?php endif; ?>
                            <?php
                            // 入力欄は「確認待ちの値」があればそれを、無ければ今の値を出す(同じものを送ると取り下げになる)
                            $shown = static fn (string $field): string => (string) ($p['changes'][$field]['to'] ?? $f[$field] ?? '');
                            $inputs = [
                                'title' => ['名前', 'text'],
                                'subtitle' => ['部屋番号など', 'text'],
                                'occupantName' => ['教職員氏名', 'text'],
                                'note' => ['メモ', 'textarea'],
                            ];
                            ?>
                            <?php foreach ($inputs as $field => [$label, $kind]): ?>
                                <?php if (!array_key_exists($field, $f)) { continue; } ?>
                                <?php $inputId = 'km-node-' . $field . '-' . $staffNode['id']; ?>
                                <div class="km-account-field">
                                    <label for="<?= km_legal_e($inputId) ?>"><?= km_legal_e($label) ?></label>
                                    <?php if ($kind === 'textarea'): ?>
                                        <textarea id="<?= km_legal_e($inputId) ?>" name="node_<?= km_legal_e($field) ?>" rows="3"
                                                  maxlength="<?= (int) KM_STAFF_NODE_FIELDS[$field] ?>"><?= km_legal_e($shown($field)) ?></textarea>
                                    <?php else: ?>
                                        <input type="text" id="<?= km_legal_e($inputId) ?>" name="node_<?= km_legal_e($field) ?>"
                                               maxlength="<?= (int) KM_STAFF_NODE_FIELDS[$field] ?>" value="<?= km_legal_e($shown($field)) ?>"
                                               <?= $field === 'title' ? 'required' : '' ?>>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <button type="submit" class="primary-btn">変更を送る(管理者の確認後に反映)</button>
                        </form>
                    <?php endforeach; ?>
                    <p class="km-account-hint">
                        教職員氏名は、地図の公開設定によっては一般の方にも表示されます。取り扱いは
                        <a href="/privacy.php">プライバシーポリシー</a>をご覧ください。
                    </p>
                </section>
            <?php endif; ?>

            <section class="km-doc-section">
                <h2>ここで変えられないもの</h2>
                <p>
                    メールアドレス・電話番号・多要素認証・パスキーは、確認コードのやり取りが
                    必要なためこのページでは扱っていません。Logto の画面で変更できます。
                </p>
                <?php
                /*
                 * **行き先を必ず示す。** 「Logto の画面から」とだけ書いて
                 * リンクを出さないのは、案内しているようで案内していない。
                 * 別ポートなので見た目が変わる —— そのことも書いておく。
                 */
                ?>
                <p>
                    <a href="<?= km_legal_e(km_logto_account_center_url()) ?>"
                       class="km-doc-link" target="_blank" rel="noopener noreferrer">
                        Logto のアカウント画面を開く
                    </a>
                </p>
                <p class="km-account-hint">
                    別のページ(認証サーバー)が開きます。見た目が変わりますが、同じアカウントです。
                </p>
                <p>
                    自分の情報の開示については
                    <a href="/privacy.php">プライバシーポリシー</a>をご覧ください。
                </p>
            </section>

            <?php
            /*
             * アカウントの削除。
             *
             * ## なぜここに置くことになったのか
             *
             * 当初は「Logto の画面で消してもらい、webhook で後片付けする」形にしていた。
             * だが **Account API には削除が無い**(2026-09-03 に実測)。
             * Logto の画面から自分を消すことはできず、
             * **Management API を使ってこちらが代理で消す**しかない。
             *
             * webhook は残してある —— 管理者が Logto Console から消した場合は、
             * そちらから片付けが動く。
             *
             * ## 押す前に、何が起きるかを全部書く
             *
             * 取り返しがつかない操作を、説明の無い場所に置かない。
             */
            ?>
            <section class="km-doc-section">
                <h2>アカウントの削除</h2>
                <p>
                    <strong>元に戻せません。</strong>
                    削除すると、この校内マップ側では次のものが消えます。
                </p>
                <ul>
                    <li>ランキングの記録(訪れた場所の件数と表示名)</li>
                    <li>アプリの設定でアカウントへ預けたもの</li>
                    <li>プロフィールとアカウント画像</li>
                    <li>お知らせの既読の記録と、オンライン状態</li>
                </ul>
                <p>
                    <strong>管理操作の記録だけは残ります。</strong>
                    ただし名前と ID は消えるので、その記録から個人には辿れません。
                    「いつ何が行われたか」を追えなくしないための扱いです。
                </p>
                <p class="km-account-hint">
                    アプリに入れた地図やアクセスコードは端末側にあります。
                    削除したあとも消えないので、必要なら端末で消してください。
                </p>
                <?php
                /*
                 * **開くまで見えない場所へ置く。** 表示名やパスワードの欄と
                 * 同じ高さに並べると、押し間違いが起きる。
                 *
                 * パスワードを求めるのは、**端末を借りられた場面でそのまま
                 * 消されないため**。パスワード変更と同じ確認を通す。
                 */
                ?>
                <details>
                    <summary class="km-doc-link">アカウントを削除する</summary>
                    <form method="post" class="km-account-danger">
                        <?= km_csrf_field() ?>
                        <input type="hidden" name="do_account" value="delete">
                        <div class="km-account-field">
                            <label for="km-delete-password">確認のため、現在のパスワードを入力してください</label>
                            <input type="password" id="km-delete-password" name="current_password"
                                   autocomplete="current-password" required>
                        </div>
                        <button type="submit" class="km-doc-link km-account-danger-button">
                            削除する(元に戻せません)
                        </button>
                    </form>
                </details>
                <p class="km-account-hint">
                    うまくいかない場合は
                    <a href="/contact.php">お問い合わせ</a>からご連絡ください。
                </p>
            </section>
        <?php endif; ?>
    </main>
</body>

</html>
