#!/bin/bash
# W-49: パスワードの失敗で IP が残り、成功しても・時間が経っても消す処理が無いか
cd "$(dirname "$0")"; source env.sh
q() { docker exec kmdb mariadb -uroot -prootpw Kosen_map -N -e "$1"; }
echo "1) 失敗の記録(監査ログ): action, IP"; q "SELECT action, INET6_NTOA(ip_address) FROM km_admin_log WHERE action='map.unlock_failed'"
echo "2) 失敗回数の表: IP, 回数"; q "SELECT INET6_NTOA(ip_address), failure_count FROM km_map_unlock_attempts"
echo "3) 同じ IP から正しいパスワード(B)で成功させる"
curl -s --noproxy '*' -H 'Content-Type: application/json' -H 'X-Real-IP: 198.51.100.2' -d '{"password":"PassB"}' http://127.0.0.1:3900/api/map-unlock.php; echo
echo "4) 成功の後も失敗回数の表に行が残るか: IP, 回数"; q "SELECT INET6_NTOA(ip_address), failure_count FROM km_map_unlock_attempts"
echo "5) これらの表を消す(DELETE・TRUNCATE・期限つきの掃除)処理がコードにあるか:"
grep -rnE "(DELETE FROM|TRUNCATE)[^;]*(km_admin_log|km_map_unlock_attempts|\{\\\$table\})" websrc/lib websrc/api websrc/admin websrc/scripts --include=*.php | grep -v "scripts/check.php" || echo "  (該当なし)"
