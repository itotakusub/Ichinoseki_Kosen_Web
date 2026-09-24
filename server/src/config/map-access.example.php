<?php

/**
 * 地図データの公開設定。実際の値は git 管理外の map-access.local.php に置く
 * (logto_config.local.php と同じパターン)。このファイルはコピー元の見本。
 *
 * admin/map-settings.php からこのファイルを書き換えて運用する。
 */

declare(strict_types=1);

return [
    /**
     * 'hidden'   occupant_name を常に隠す(何があっても返さない)
     * 'password' 正しいパスワードで解除した場合だけ返す(既定値)
     * 'public'   常に返す(パスワード不要)
     */
    'mode' => 'password',

    /** password_hash() の出力。null なら未設定として、password モードでは常に拒否する。 */
    'passwordHash' => null,
];
