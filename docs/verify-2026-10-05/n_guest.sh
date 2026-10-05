#!/bin/bash
# 新しいコード: お試しの閲覧リンク(仮アカウント)。管理者が仮アカウントを取り消したあと、
# 同じブラウザのセッションで、地図の錠の内側の見取り図(api/floor-image.php)をまだ取れるか。
# 氏名・地図データの経路(index.php・api/map-data.php)は km_map_guest_verify() で毎回 DB を見直す。見取り図の経路はどうか。
source "$(dirname "$0")/lib.sh"
CUR=N-GUEST; need_servers
CFG=websrc/config/map-access.local.php
BAK=$(mktemp); had=0; [ -f "$CFG" ] && { cp "$CFG" "$BAK"; had=1; }
restore() { if [ "$had" = 1 ]; then cp "$BAK" "$CFG"; else rm -f "$CFG"; fi; rm -f "$BAK"; sleep 3; }
trap restore EXIT
php -r 'file_put_contents($argv[1], "<?php return " . var_export(["mode" => "password", "mapMode" => "password", "passwordHash" => password_hash("PassA", PASSWORD_DEFAULT)], true) . ";\n");' "$CFG" || die N-GUEST "設定を書けません"
config_changed
IMG='/api/floor-image.php?building=bldg_gym1'
H=(-H 'X-Real-IP: 203.0.113.95')
http GET "$IMG" "${H[@]}"; echo "1) セッション無しで見取り図 → $HTTP_CODE"; expect_code 403 "対照: 錠を掛けた見取り図"
TOKEN=$(cd websrc && php -r 'require "lib/db.php"; require "lib/map-guest.php"; $pdo = km_db(); km_map_guest_ensure_table($pdo); echo km_map_guest_create($pdo, "検証", 1, 3, "admin-0001")["token"];') || die N-GUEST "リンクを作れません"
http GET "/guest.php?t=$TOKEN" "${H[@]}"; expect_code 200 "リンクを開く"
C1=$(session_cookie); CSRF=$(printf '%s' "$HTTP_BODY" | sed -nE 's/.*name="km_csrf" value="([0-9a-f]+)".*/\1/p' | head -1)
[ -n "$C1" ] && [ -n "$CSRF" ] || die N-GUEST "セッションか CSRF の値を取れません"
http POST /guest.php "${H[@]}" -H "Cookie: __Host-KMSID=$C1" --data-urlencode "action=create" --data-urlencode "t=$TOKEN" --data-urlencode "name=架空 次郎" --data-urlencode "km_csrf=$CSRF"
expect_code 200 "仮アカウントを作る"
C2=$(session_cookie); [ -n "$C2" ] && [ "$C2" != "$C1" ] || die N-GUEST "仮アカウントを作ってもセッション ID が作り直されない"
echo "2) 仮アカウントを作った(セッション ID は作り直された)"
http GET "$IMG" "${H[@]}" -H "Cookie: __Host-KMSID=$C2"; echo "3) 仮アカウントで見取り図 → $HTTP_CODE"; expect_code 200 "対照: 仮アカウントは見取り図を見られる"
(cd websrc && php -r 'require "lib/db.php"; require "lib/map-guest.php"; $pdo = km_db(); $id = (int) $pdo->query("SELECT MAX(id) FROM km_map_guest_accounts")->fetchColumn(); exit(km_map_guest_revoke_account($pdo, $id) ? 0 : 1);') || die N-GUEST "取り消せません"
echo "4) 管理者が仮アカウントを取り消した"
http GET "$IMG" "${H[@]}" -H "Cookie: __Host-KMSID=$C2"; after=$HTTP_CODE; echo "5) 取り消した後、同じセッションで見取り図 → $after"
http GET /api/map-data.php "${H[@]}" -H "Cookie: __Host-KMSID=$C2"; locked=$(printf '%s' "$HTTP_BODY" | jget mapLocked); echo "6) 同じセッションで地図データ → mapLocked=$locked(ここで見直しが走る)"
http GET "$IMG" "${H[@]}" -H "Cookie: __Host-KMSID=$C2"; after2=$HTTP_CODE; echo "7) 地図データを開いた後の見取り図 → $after2"
case "$after" in
    200) verdict N-GUEST REPRO "取り消した仮アカウントのセッションで、見取り図(錠の内側)をリンクの期限まで取れる。地図データ(map-data・公開ページ)を開くと見直しが走って閉じる(その後は $after2)" ;;
    403) verdict N-GUEST FIXED "取り消した直後から見取り図も取れない" ;;
    *) die N-GUEST "想定外の応答 $after" ;;
esac
