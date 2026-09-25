<?php
// 検証用: セッションの中身を出す。php dumpsession.php <sid> [キー...]
session_save_path(__DIR__ . '/sessions'); session_name('__Host-KMSID'); session_id($argv[1]); session_start();
$keys = array_slice($argv, 2);
echo json_encode($keys ? array_intersect_key($_SESSION, array_flip($keys)) : $_SESSION, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
