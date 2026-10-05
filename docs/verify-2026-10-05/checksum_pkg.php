<?php
// 配信データ(api/app-map.php が返すもの)を、サーバーの本物の km_app_map_build_package() で作って書き出す。
// Android 側の docs/verify-2026-10-05(CHK)が、アプリの本物の parseMapPackage() と verifyMapChecksum() で読み、
// 「サーバーの json_encode と Gson の書き戻しが同じ文字列になり、チェックサムが一致する」かを確かめる。
// 使い方: php checksum_pkg.php <出力先ディレクトリ> [server/src の場所]
// 値はすべて架空。
declare(strict_types=1);
$out = $argv[1] ?? '';
$src = $argv[2] ?? __DIR__ . '/../../server/src';
if ($out === '' || (!is_dir($out) && !mkdir($out, 0777, true))) { fwrite(STDERR, "出力先を作れません\n"); exit(2); }
require_once $src . '/lib/app-map.php';
if (!function_exists('km_app_map_build_package')) { fwrite(STDERR, "km_app_map_build_package がありません\n"); exit(2); }

// 地図は、保存された JSON を json_decode したもの(stdClass)として渡す(api/app-map.php と同じ形)
$cases = [
    'text' => json_encode(['nodes' => [['uuid' => 'n1', 'title' => '架空棟 2F 😀', 'note' => "行1\nタブ\t制御\u{0001} \u{2028} \u{2029} / \\ \" <b>&amp;</b> ' = ? \u{007F}", 'floor' => '2F']], 'lines' => []], JSON_UNESCAPED_UNICODE),
    'numbers' => '{"nodes":[{"uuid":"n1","x":0.1,"y":1.0,"big":1e100,"neg0":-0.0,"int":123456789012345678,"small":1.5e-7,"huge":12345678901234567890,"third":0.3333333333333333}],"lines":[]}',
    'empty' => '{"nodes":[],"lines":[],"meta":{},"list":[[],{}],"keys":{"1":"a","0":"b","":"c"}}',
];
$big = ['nodes' => [], 'lines' => []];
for ($i = 0; $i < 5000; $i++) {
    $big['nodes'][] = ['uuid' => sprintf('n%05d', $i), 'x' => $i * 1.25, 'y' => $i / 7, 'title' => "架空の部屋 $i", 'floor' => ($i % 5 + 1) . 'F'];
}
$cases['large'] = json_encode($big, JSON_UNESCAPED_UNICODE);
$n = 0;
foreach ($cases as $name => $json) {
    $map = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    $body = km_app_map_build_package($map, 'kosen-main', 7, '2026-10-05T09:00:00+09:00', '2026-10-12T09:00:00+09:00', null, true);
    if ($body === null) { fwrite(STDERR, "$name: 組み立てに失敗\n"); exit(2); }
    // 自分で検算: 埋めた map の文字列のハッシュが checksum と同じか(サーバー側の前提)
    $pkg = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $mapJson = json_encode(json_decode($json, false), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($pkg['checksum'] !== 'sha256:' . hash('sha256', $mapJson) || !str_contains($body, $mapJson)) {
        fwrite(STDERR, "$name: サーバー側の前提が成り立たない\n"); exit(2);
    }
    file_put_contents("$out/$name.json", $body);
    $n++;
}
// 壊れた UTF-8 は、組み立てに失敗する(null)はず(端末に食い違った文字列を送らない)
$broken = json_decode('{"nodes":[{"uuid":"n1","title":"x"}],"lines":[]}');
$broken->nodes[0]->title = "\xC3\x28";
$r = km_app_map_build_package($broken, 'kosen-main', 7, '2026-10-05T09:00:00+09:00', '2026-10-12T09:00:00+09:00', null, true);
echo "壊れた UTF-8 の組み立て: ", $r === null ? "失敗(null)" : "成功してしまう", PHP_EOL;
echo "$n 件の配信データを $out に書いた", PHP_EOL;
