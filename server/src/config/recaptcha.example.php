<?php

/**
 * 公開の問い合わせフォーム(src/contact.php)で使う reCAPTCHA の設定の見本。
 * 実際の値は git 管理外の recaptcha.local.php に置く(db.local.php と同じパターン)。
 * .env(compose.yaml の RECAPTCHA_*)へ入れるなら、このファイルは要らない。
 *
 * 優先順位は lib/recaptcha.php 側で:
 *   環境変数(RECAPTCHA_*) > このファイル形式の recaptcha.local.php
 *
 * ---
 *
 * **どちらの方式かは、置いた値で決まる。** Google のコンソールは Google Cloud 側へ統合され、
 * いま新規に作るキーは Enterprise になる。クラシック版のキーを既に持っているならそちらも使える。
 *
 *   Enterprise … siteKey + projectId + apiKey  (assessments API で検証)
 *   クラシック  … siteKey + secretKey          (siteverify で検証)
 *
 * Enterprise の値が揃っていればそちらを優先する。どちらも揃っていなければ「未設定」として
 * **フォーム自体を出さない**(検証できないものを受け付けないため)。
 *
 * ---
 *
 * Google 側の前提:
 *   1. 種類は **チェックボックス(v2「私はロボットではありません」相当)**
 *   2. **ドメインの検証を外す。** このサイトは https://192.168.3.29:9443 という IP アドレスで
 *      動いており、コンソールのドメイン欄はホスト名しか受け付けない。
 *      Enterprise では「WAF 機能」ではなく通常のキーとして作り、
 *      **「ドメインを確認する / オリジンを検証する」を無効**にしておく
 *   3. Enterprise を使うなら、そのプロジェクトで **reCAPTCHA Enterprise API を有効化**し、
 *      API キーを作る。**API キーは「reCAPTCHA Enterprise API」だけに制限**しておくこと
 *      (制限の無い API キーは、漏れたときに他の API まで使われる)
 *   4. siteKey はブラウザに出る前提のもので秘密ではない。
 *      **secretKey と apiKey はサーバー側だけで使う**(HTML にも JS にも出さない)
 */

declare(strict_types=1);

return [
    // 共通。ブラウザに出る(秘密ではない)。6L… で始まる40文字程度
    'siteKey' => '',

    // Enterprise 版で使う。projectId は Google Cloud のプロジェクト ID(秘密ではない)、
    // apiKey は AIza… で始まる39文字程度。**apiKey は秘密**
    'projectId' => '',
    'apiKey' => '',

    // クラシック版で使う。**秘密**。Enterprise の値を入れるならこちらは空でよい
    'secretKey' => '',
];
