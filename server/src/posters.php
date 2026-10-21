<?php

declare(strict_types=1);

/**
 * ポスターの一覧(誰でも見られる公開ページ。2026-10-21、利用者の指示。lib/posters.php)。
 *
 * ポスターの画像を並べる。**画像を押すと地図のその地点へ移り、詳細(画像・説明・URL)を開く**
 * (`/?room=<地点>&poster=<番号>`。部屋へ移る仕組みは QR と同じ。Main/app.js の focusRoomFromUrl)。
 * 地点に置いていないポスターは、画像を押すと URL を開く。
 *
 * 出すのは管理画面の「出し方」(表示しない / イベント中のみ / 常に表示)が「出す」のときだけ。
 * 地点の名前は**地図が出しているものだけ**使う(km_map_data。錠が掛かっていれば名前も地図への移動も出さない)。
 * 公開ページなので fail open(faq.php と同じ): DB に繋がらなくてもページは出し、読めないと伝える。
 */

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/posters.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/csp.php';
require_once __DIR__ . '/lib/csrf.php';   // 数える口(api/poster-event.php)に渡すトークン

km_csp_send('public');

$posters = [];
$nodes = [];
$unavailable = false;
try {
    // 地図の錠・教職員の印はセッションで決まる(lib/map-access.php)。index.php と同じ判定にする
    km_session_start();
    // お試しの閲覧リンクが取り消し・期限切れなら、ここで印を外す(index.php と同じ。古い印で錠の内側を見せない)
    require_once __DIR__ . '/lib/map-guest.php';
    km_map_guest_verify();
    require_once __DIR__ . '/lib/map-data.php';
    $pdo = km_db();
    $mapData = km_map_data($pdo);
    if (!empty($mapData['mapLocked'])) {
        // 地図を見せていない。ポスターは出すが、どこにあるか(地点の名前と地図への移動)は出さない
        $posters = km_posters_public($pdo, km_poster_event_active($pdo), []);
    } else {
        $posters = $mapData['posters'] ?? [];
        $nodes = $mapData['nodes'] ?? [];
    }
} catch (Throwable $exception) {
    error_log('posters.php (public) failed (fail open): ' . $exception->getMessage());
    $unavailable = true;
}

function km_posters_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 地点の名前(イベント中の名前があればそちら。地図の詳細と同じ) */
$placeOf = static function (array $poster) use ($nodes): ?string {
    $node = $poster['nodeId'] !== '' ? ($nodes[$poster['nodeId']] ?? null) : null;
    if ($node === null) {
        return null;
    }
    $name = (string) (($node['alias'] ?? '') !== '' ? $node['alias'] : $node['name']);

    return $name . '(' . km_poster_floor_label((string) $node['floor']) . ')';
};
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ポスター | 高専マップ案内</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <?php if ($posters !== [] && session_status() === PHP_SESSION_ACTIVE): ?>
        <?php /* リンクを押した人数を数える(Main/poster-stats.js が X-KM-CSRF で送る) */ ?>
        <meta name="km-csrf" content="<?= km_posters_e(km_csrf_token()) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= km_posters_e(km_public_asset('Main/styles.css')) ?>">
    <link rel="stylesheet" href="<?= km_posters_e(km_public_asset('Main/document.css')) ?>">
    <style<?= km_csp_nonce_attr() ?>>
        .km-doc-main.km-poster-wall-main { max-width: 72rem; }

        .km-poster-lead {
            color: var(--secondary);
            margin: 0 0 1.25rem;
            line-height: 1.7;
        }

        /* 縦長も横長も切らずに並べる。列の数は画面の幅で決まる(スマホでも 2 列。1 列だと「並べて」見えない) */
        .km-poster-wall {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(13.5rem, calc(50% - 0.5rem)), 1fr));
            gap: 1rem;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .km-poster-card {
            display: flex;
            flex-direction: column;
            background: var(--glass-heavy);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .km-poster-card-image {
            display: flex;
            align-items: center;
            justify-content: center;
            aspect-ratio: 3 / 4;
            background: color-mix(in srgb, var(--text) 5%, transparent);
            overflow: hidden;
        }

        a.km-poster-card-image:hover img,
        a.km-poster-card-image:focus-visible img {
            transform: scale(1.03);
        }

        .km-poster-card-image img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            transition: transform 0.2s ease;
        }

        .km-poster-card-body {
            padding: 0.75rem 0.9rem 0.9rem;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            flex: 1;
        }

        .km-poster-card-title {
            font-weight: 700;
            margin: 0;
            font-size: 1rem;
            overflow-wrap: anywhere;
        }

        .km-poster-card-place {
            font-size: 0.85rem;
            color: var(--primary-dark);
            margin: 0;
        }

        .km-poster-card-desc {
            font-size: 0.85rem;
            color: var(--secondary);
            margin: 0;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .km-poster-card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            margin-top: auto;
            padding-top: 0.35rem;
        }

        .km-poster-card-actions .km-doc-link,
        .km-poster-card-actions .km-doc-button {
            min-height: 2.5rem;
            margin: 0;
            padding: 0.3rem 0.9rem;
            font-size: 0.85rem;
        }

        .km-poster-empty {
            text-align: center;
            color: var(--secondary);
            padding: 3rem 1rem;
        }
    </style>
</head>

<body>
    <header class="km-doc-header">
        <h1>ポスター</h1>
        <nav class="km-doc-nav">
            <a href="/" class="km-doc-link">地図へ戻る</a>
        </nav>
    </header>

    <main class="km-doc-main km-poster-wall-main">
        <?php if ($unavailable): ?>
            <p class="km-poster-empty">ただいま読み込めません。しばらくしてからもう一度お試しください。</p>
        <?php elseif ($posters === []): ?>
            <p class="km-poster-empty">いま掲示しているポスターはありません。</p>
        <?php else: ?>
            <p class="km-poster-lead">画像を押すと、地図でその場所を開きます。</p>
            <ul class="km-poster-wall">
                <?php foreach ($posters as $poster): ?>
                    <?php
                    $place = $placeOf($poster);
                    // 地点が地図に出ていれば地図へ。無ければ URL へ(どちらも無ければ押せない画像)
                    $mapHref = $place !== null
                        ? '/?room=' . rawurlencode($poster['nodeId']) . '&poster=' . (int) $poster['id']
                        : null;
                    $imageHref = $mapHref ?? ($poster['linkUrl'] !== '' ? $poster['linkUrl'] : null);
                    ?>
                    <li class="km-poster-card" id="poster-<?= (int) $poster['id'] ?>">
                        <?php if ($imageHref !== null): ?>
                            <a class="km-poster-card-image" href="<?= km_posters_e($imageHref) ?>"
                                <?= $mapHref === null ? 'target="_blank" rel="noopener noreferrer" data-km-poster-link="' . (int) $poster['id'] . '"' : '' ?>
                                aria-label="<?= km_posters_e($poster['title'] . ($mapHref !== null ? '(地図で見る)' : '(リンクを開く)')) ?>">
                                <img src="<?= km_posters_e($poster['imageUrl']) ?>" alt="<?= km_posters_e($poster['title']) ?>" width="<?= (int) $poster['width'] ?>" height="<?= (int) $poster['height'] ?>" loading="lazy">
                            </a>
                        <?php else: ?>
                            <div class="km-poster-card-image">
                                <img src="<?= km_posters_e($poster['imageUrl']) ?>" alt="<?= km_posters_e($poster['title']) ?>" width="<?= (int) $poster['width'] ?>" height="<?= (int) $poster['height'] ?>" loading="lazy">
                            </div>
                        <?php endif; ?>
                        <div class="km-poster-card-body">
                            <h2 class="km-poster-card-title"><?= km_posters_e($poster['title']) ?></h2>
                            <?php if ($place !== null): ?>
                                <p class="km-poster-card-place">📍 <?= km_posters_e($place) ?></p>
                            <?php endif; ?>
                            <?php if ($poster['description'] !== ''): ?>
                                <p class="km-poster-card-desc"><?= km_posters_e($poster['description']) ?></p>
                            <?php endif; ?>
                            <div class="km-poster-card-actions">
                                <?php if ($mapHref !== null): ?>
                                    <a class="km-doc-button" href="<?= km_posters_e($mapHref) ?>">地図で見る</a>
                                <?php endif; ?>
                                <?php if ($poster['linkUrl'] !== ''): ?>
                                    <?php /* URL は lib/posters.php が http / https だけに確かめてある */ ?>
                                    <a class="km-doc-link" href="<?= km_posters_e($poster['linkUrl']) ?>" target="_blank" rel="noopener noreferrer" data-km-poster-link="<?= (int) $poster['id'] ?>">くわしく見る ↗</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>
    <?php /* リンクを押した人数を数える(同じ端末は 1 人。数だけ) */ ?>
    <script defer src="<?= km_posters_e(km_public_asset('Main/poster-stats.js')) ?>"></script>
</body>

</html>
