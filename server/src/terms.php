<?php

declare(strict_types=1);

/**
 * 利用規約(誰でも見られる公開ページ)。
 *
 * 本文は lib/legal.php、外枠は lib/legal-page.php。**このファイルには中身を書かない**
 * —— privacy.php と対になっており、片方にだけ節を足すと体裁がずれる。
 */

require_once __DIR__ . '/lib/legal-page.php';

km_legal_page('利用規約', km_legal_terms(), 'terms');
