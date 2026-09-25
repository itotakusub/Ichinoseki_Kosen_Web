#!/bin/bash
# W-46: 地図のパスワードを替えても、前のパスワードで解除したセッションは氏名を見続けられるか
cd "$(dirname "$0")"; source env.sh
B=http://127.0.0.1:3900
setpw() { (cd websrc && php -r 'require "lib/map-access.php"; km_map_access_save("password", $argv[1], "public");' "$1") && echo "[設定] 氏名の錠 = password、パスワード = $1"; }
names() { curl -s --noproxy '*' ${1:+-H "Cookie: __Host-KMSID=$1"} $B/api/map-data.php | php -r '$d=json_decode(stream_get_contents(STDIN),true); $n=$d["nodes"]; $o=is_array($n)? array_values($n)[0]["occupantName"] ?? null : null; echo "namesUnlocked=", json_encode($d["namesUnlocked"]??null), " occupantName=", json_encode($o, JSON_UNESCAPED_UNICODE), "\n";'; }
unlock() { curl -s --noproxy '*' -D - -o /tmp/unlock.body -H 'Content-Type: application/json' -H "X-Real-IP: $2" -d "{\"password\":\"$1\"}" $B/api/map-unlock.php | grep -i '^set-cookie' | sed -E 's/.*__Host-KMSID=([^;]+).*/\1/'; echo "  応答: $(cat /tmp/unlock.body)" >&2; }
setpw PassA
echo "1) Cookie なし:"; names
echo "2) パスワード A で解除:"; S=$(unlock PassA 198.51.100.1); echo "  セッション: ${S:0:8}…"
echo "3) 解除したセッションで:"; names "$S"
setpw PassB
echo "4) パスワードを B に替えた後、同じセッションで:"; names "$S"
echo "5) 新しいセッションで古いパスワード A を試す:"; S2=$(unlock PassA 198.51.100.2); names "$S2"
