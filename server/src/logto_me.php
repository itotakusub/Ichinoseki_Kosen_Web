<?php

declare(strict_types=1);

require_once __DIR__ . '/api_bootstrap.php';
require_once __DIR__ . '/logto_config.php';
require_once __DIR__ . '/logto_guard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'GETで送信してください。'], 405);
}

$principal = logto_require_principal();
respond([
    'success' => true,
    'subject' => $principal['subject'],
    'username' => $principal['username'],
    'email' => $principal['email'],
    'isAdmin' => (int) $principal['is_admin'] === 1,
    // 教職員(docs/15)。組織付きのトークンで問い合わせたときだけ true になりうる(logto_guard.php)
    'isTeacher' => ($principal['is_teacher'] ?? false) === true,
    'permissions' => $principal['permissions'],
]);
