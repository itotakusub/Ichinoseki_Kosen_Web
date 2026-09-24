<?php

declare(strict_types=1);

/**
 * プライバシーポリシー(誰でも見られる公開ページ)。
 *
 * 本文は lib/legal.php、外枠は lib/legal-page.php。
 *
 * **収集を増やしたら lib/legal.php の表も直すこと。** 書いてあることと実際の扱いが
 * ずれた文書は、無いより悪い。
 */

require_once __DIR__ . '/lib/legal-page.php';

km_legal_page('プライバシーポリシー', km_legal_privacy(), 'privacy');
