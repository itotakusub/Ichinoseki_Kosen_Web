<?php
// 検証用: 決めた ID でセッションを作る。使い方: php mksession.php <sid> '<JSON>'
// JSON の "_jwt" キーは {セッションのキー: クレーム} で、署名なしの JWT に変えて入れる(SDK は保存済みのトークンを検証しない)
[$_, $sid, $json] = $argv;
$vars = json_decode($json, true);
$jwt = fn(array $c) => rtrim(strtr(base64_encode('{"alg":"none"}'), '+/', '-_'), '=') . '.' . rtrim(strtr(base64_encode(json_encode($c)), '+/', '-_'), '=') . '.sig';
foreach ($vars['_jwt'] ?? [] as $key => $claims) { $vars[$key] = $jwt($claims); }
foreach ($vars['_atmap'] ?? [] as $resource => $claims) {
    $vars['logto::access_token_map'] = json_encode([$resource => ['token' => $jwt($claims), 'expiresAt' => time() + 3000]]);
}
unset($vars['_jwt'], $vars['_atmap']);
session_save_path(__DIR__ . '/sessions'); session_name('__Host-KMSID'); session_id($sid); session_start();
$_SESSION = $vars; session_write_close();
echo "session $sid written\n";
