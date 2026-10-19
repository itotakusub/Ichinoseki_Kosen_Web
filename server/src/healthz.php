<?php

declare(strict_types=1);

/**
 * web の healthcheck だけが叩くページ(compose.yaml。2026-10-12)。
 *
 * 以前は verify.php(確認コードの橋渡しページ)を叩いていたが、メールのテンプレートから URL を外したので
 * verify.php は Old/src/ へ退避した。**DB を触らず**、Apache と PHP が動いていることだけを見る
 * (静的ファイルではなく PHP にするのは、Apache は生きているが PHP が死んでいる状態を見逃さないため)。
 *
 * 外からは nginx が 404 を返す(default.conf.template の location = /healthz.php)。
 * 万一届いても、コンテナの中(127.0.0.1)以外には 404 を返す。
 */

header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=UTF-8');

if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}

echo "ok\n";
