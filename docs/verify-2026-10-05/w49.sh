#!/bin/bash
# W-49: 地図のパスワード解除で IP が残るか(成功した人も含む)と、古い IP を消す処理が実際に動くか
#
# 9/25 版は「消す処理がコードに無い」を grep の出力を目で読んで決めていた。
# この版は、記録の日時を古くしてから解除の要求を 1 回送り、**実際に消えるか**で判定する。
source "$(dirname "$0")/lib.sh"
CUR=W-49; need_servers
OK_IP=198.51.100.31    # 1 回で正しく解除するだけの人
NG_IP=198.51.100.32    # 1 回だけ間違える人
setpw() {
    local out
    out=$(cd websrc && php -r 'require "lib/map-access.php"; km_map_access_save("password", $argv[1], "public"); echo "ok";' "$1" 2>&1)
    [ "$out" = "ok" ] || die W-49 "パスワードを設定できません: ${out:0:300}"
    config_changed
}
unlock() { http POST /api/map-unlock.php -H 'Content-Type: application/json' -H "X-Real-IP: $2" -d "{\"password\":\"$1\"}"; }
setpw PassC
unlock PassC "$OK_IP"; expect_code 200 "正しいパスワードでの解除"
unlock wrong "$NG_IP"; expect_code 401 "間違ったパスワード"
tables=$(q "SELECT table_name FROM information_schema.tables WHERE table_schema='Kosen_map' AND table_name IN ('km_map_unlock_attempts','km_app_unlock_attempts')")
[ -n "$tables" ] || die W-49 "解除の回数の表がありません"
ip_rows() { # $1=IP。解除の回数の表にある行の数
    local n=0 t
    for t in $tables; do n=$((n + $(q "SELECT COUNT(*) FROM $t WHERE ip_address = INET6_ATON('$1')"))); done
    echo "$n"
}
echo "1) 解除の回数の表: 成功だけの IP の行 $(ip_rows $OK_IP) / 失敗した IP の行 $(ip_rows $NG_IP)"
[ "$(ip_rows $NG_IP)" -ge 1 ] || die W-49 "失敗した IP の行がありません(回数を数えていない?)"
if [ "$(ip_rows $OK_IP)" -ge 1 ]; then verdict W-49a REPRO "1 回で正しく解除しただけの IP も、解除の回数の表に行として残る"
else verdict W-49a FIXED "成功しただけの IP は残らない"; fi
log_ip=$(q "SELECT COUNT(*) FROM km_admin_log WHERE action='map.unlock_failed' AND ip_address = INET6_ATON('$NG_IP')")
echo "2) 監査ログの map.unlock_failed に失敗した IP: $log_ip 行"
[ "$log_ip" -ge 1 ] || die W-49 "監査ログに失敗の記録がありません(前提が崩れている)"

echo "3) 記録を古くする(監査ログ 100 日前・回数の表 2 日前)→ 解除の要求を 1 回送る → 消えるか"
q "UPDATE km_admin_log SET created_at = DATE_SUB(NOW(), INTERVAL 100 DAY) WHERE ip_address IN (INET6_ATON('$OK_IP'), INET6_ATON('$NG_IP'))" >/dev/null
for t in $tables; do
    q "UPDATE $t SET last_failed_at = DATE_SUB(NOW(), INTERVAL 2 DAY), first_failed_at = DATE_SUB(NOW(), INTERVAL 2 DAY), locked_until = NULL WHERE ip_address IN (INET6_ATON('$OK_IP'), INET6_ATON('$NG_IP'))" >/dev/null
done
# 「今日はもう掃除した」の印があれば外す(9/25 のコードには印の表が無い)
if [ -n "$(q "SELECT table_name FROM information_schema.tables WHERE table_schema='Kosen_map' AND table_name='km_settings'")" ]; then
    q "DELETE FROM km_settings WHERE name='privacy_purged_on'" >/dev/null
fi
unlock wrong 198.51.100.33; expect_code 401 "掃除を起こすための解除の要求"
left_log=$(q "SELECT COUNT(*) FROM km_admin_log WHERE ip_address IN (INET6_ATON('$OK_IP'), INET6_ATON('$NG_IP'))")
left_rows=$(( $(ip_rows $OK_IP) + $(ip_rows $NG_IP) ))
echo "   古くした後に残る: 監査ログの IP $left_log 行 / 回数の表 $left_rows 行"
if [ "$left_log" -eq 0 ] && [ "$left_rows" -eq 0 ]; then verdict W-49b FIXED "古い IP は監査ログから消され、回数の表の行も消えた"
elif [ "$left_log" -gt 0 ] && [ "$left_rows" -gt 0 ]; then verdict W-49b REPRO "古くしても IP が監査ログと回数の表に残る(消す処理が動かない/無い)"
else verdict W-49b REPRO "一部だけ残る(監査ログの IP $left_log 行・回数の表 $left_rows 行)"; fi
