#!/bin/sh
#
# ホストとコンテナごとの使用量を JSON にして置く(管理画面のタスクマネージャーが読む。2026-10-05)。
#
#   ./host-stats.sh --path /opt/kosenmap     run/hoststats/stats.json を書き直す(cron が 1 分ごとに呼ぶ。root)
#
# 同じ回で run/hoststats/bans.json(国の拒否・fail2ban・22 番の DROP。訪問者のページが読む。2026-10-08)も書く。
# 中身はファイルの下の「BAN の様子」。
#
# ## なぜホストのスクリプトなのか
#
# Glances にコンテナ別の数値を出させるには docker.sock を渡す必要がある(読み取りでも root 相当の口)。
# **それを渡さないと利用者が決めた**(2026-10-05)。代わりに root の cron が cgroup の値とログの大きさを読み、
# 生の数値のまま置く。**割合・CPU の使用率(前回との差)・単位への変換はブラウザがする** ——
# サーバーは書くだけ(利用者の指示「変換はクライアント側で」)。
#
# web へは run/hoststats を**読み取り専用で**渡す(compose の web の volumes)。
# 書くのは一時ファイルに書いてから mv する(読みかけの半端な JSON を渡さない)。
#
# 中身(数値はすべて生の値。bytes・usec・秒):
#   generatedAt, host{memTotal,memAvailable,swapTotal,swapFree,load1,load5,load15,uptime,
#                     diskTotal,diskUsed,diskAvail,netRx,netTx}, containers[{name,memCurrent,memMax,
#                     swapCurrent,cpuUsageUsec,oomKill,logBytes}]
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu

PATH_ROOT="/opt/kosenmap"
while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    -h|--help) sed -n '2,28p' "$0"; exit 0 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

OUT_DIR="$PATH_ROOT/run/hoststats"
mkdir -p "$OUT_DIR"
chmod 755 "$PATH_ROOT/run" "$OUT_DIR"

meminfo() {
  # kB → bytes
  awk -v k="$1:" '$1 == k { printf "%.0f", $2 * 1024; found = 1 } END { if (!found) printf "0" }' /proc/meminfo
}

read -r LOAD1 LOAD5 LOAD15 _rest < /proc/loadavg
UPTIME="$(awk '{ printf "%.0f", $1 }' /proc/uptime)"
DISK="$(df -B1 -P / | awk 'NR == 2 { print $2, $3, $4 }')"
DISK_TOTAL="$(echo "$DISK" | cut -d' ' -f1)"
DISK_USED="$(echo "$DISK" | cut -d' ' -f2)"
DISK_AVAIL="$(echo "$DISK" | cut -d' ' -f3)"
# ホストの外向きの通信の合計(lo・docker の網・veth・ブリッジは数えない)
NET="$(sed 's/:/ /' /proc/net/dev | awk 'NR > 2 { if ($1 ~ /^(lo|docker|veth|br-)/) next; rx += $2; tx += $10 }
        END { printf "%.0f %.0f", rx, tx }')"
NET_RX="${NET% *}"
NET_TX="${NET#* }"

TMP="$(mktemp "$OUT_DIR/.stats.XXXXXX")"
trap 'rm -f "$TMP"' EXIT INT TERM

{
  printf '{"generatedAt":%s,' "$(date +%s)"
  printf '"host":{"memTotal":%s,"memAvailable":%s,"swapTotal":%s,"swapFree":%s,' \
    "$(meminfo MemTotal)" "$(meminfo MemAvailable)" "$(meminfo SwapTotal)" "$(meminfo SwapFree)"
  printf '"load1":%s,"load5":%s,"load15":%s,"uptime":%s,' "$LOAD1" "$LOAD5" "$LOAD15" "$UPTIME"
  printf '"diskTotal":%s,"diskUsed":%s,"diskAvail":%s,"netRx":%s,"netTx":%s},' \
    "${DISK_TOTAL:-0}" "${DISK_USED:-0}" "${DISK_AVAIL:-0}" "${NET_RX:-0}" "${NET_TX:-0}"
  printf '"containers":['
  _first=1
  docker ps --no-trunc --format '{{.ID}} {{.Names}}' 2>/dev/null | while read -r _id _name; do
    # 名前は docker が許す文字だけ(英数・_・.・-)。JSON に入れても逃がす文字が無い
    case "$_name" in
      *[!A-Za-z0-9_.-]*) continue ;;
    esac
    _cg="/sys/fs/cgroup/system.slice/docker-$_id.scope"
    _mem="$(cat "$_cg/memory.current" 2>/dev/null || echo 0)"
    _max="$(cat "$_cg/memory.max" 2>/dev/null || echo 0)"
    case "$_max" in max) _max=0 ;; esac
    _swap="$(cat "$_cg/memory.swap.current" 2>/dev/null || echo 0)"
    _cpu="$(awk '$1 == "usage_usec" { print $2 }' "$_cg/cpu.stat" 2>/dev/null || true)"
    _oom="$(awk '$1 == "oom_kill" { print $2 }' "$_cg/memory.events" 2>/dev/null || true)"
    _log=0
    for _f in /var/lib/docker/containers/"$_id"/"$_id"-json.log*; do
      if [ -f "$_f" ]; then
        _log=$((_log + $(stat -c %s "$_f")))
      fi
    done
    if [ "$_first" -eq 0 ]; then printf ','; fi
    _first=0
    printf '{"name":"%s","memCurrent":%s,"memMax":%s,"swapCurrent":%s,"cpuUsageUsec":%s,"oomKill":%s,"logBytes":%s}' \
      "$_name" "${_mem:-0}" "${_max:-0}" "${_swap:-0}" "${_cpu:-0}" "${_oom:-0}" "$_log"
  done
  printf ']}\n'
} > "$TMP"

chmod 644 "$TMP"
mv "$TMP" "$OUT_DIR/stats.json"
trap - EXIT INT TERM

#
# ## BAN の様子(2026-10-08。管理画面の訪問者のページが読む。admin/api/visitor-bans.php)
#
# run/hoststats/bans.json に、次を**数と IP と国のコードだけ**書く(どれも形を確かめてから)。
#   geoblock … 国の拒否(host-geoblock.sh の nftables の表 inet km_geoblock)。拒否する国・当てているか・落とした数・
#              反転しているか(invert。true なら deny は「通す国」)
#   fail2ban … 牢ごとの、いま BAN している IP・BAN した数・失敗の数
#   iptables … kosenmap-ssh-kick が fail2ban なしで足した 22 番の DROP と、落とした数
# 取れないものは false・空・0。**stats.json を書いたあとに書く**(こちらが落ちてもタスクマネージャーは止めない)。
#
json_ips() {
  # 空白・カンマで区切られた IP を JSON の配列の中身にする。IP の字だけ・500 件まで
  tr ' \t,' '\n\n\n' | grep -E '^[0-9A-Fa-f:.]+$' | grep -E '[.:]' | head -n 500 \
    | awk 'NF { printf "%s\"%s\"", (n++ ? "," : ""), $1 }'
}
GB_CONF="$PATH_ROOT/geoblock.local.conf"
gb_countries() {
  if [ -f "$GB_CONF" ]; then
    sed -n "s/^$1=//p" "$GB_CONF" | tail -n 1 | tr 'A-Z' 'a-z' | tr -c 'a-z\n' ' ' | tr ' ' '\n' \
      | grep -E '^[a-z]{2}$' | awk '{ printf "%s\"%s\"", (n++ ? "," : ""), $1 }'
  fi
}

GB_CONFIGURED=false
if [ -f "$GB_CONF" ]; then GB_CONFIGURED=true; fi
# 反転(DENY_INVERT=yes: 書いた国だけ通す。host-geoblock.sh と同じ読み方)
GB_INVERT=false
if [ -f "$GB_CONF" ]; then
  case "$(sed -n 's/^DENY_INVERT=//p' "$GB_CONF" | tail -n 1 | tr 'A-Z' 'a-z' | tr -cd 'a-z0-9')" in
    yes|1|true|on) GB_INVERT=true ;;
  esac
fi
GB_ACTIVE=false
GB_COUNTS="0 0 0 0"
if command -v nft >/dev/null 2>&1 && nft list table inet km_geoblock >/dev/null 2>&1; then
  GB_ACTIVE=true
  # counter packets N bytes M。管理用ポートの行は「!= @admin」、ほかは拒否する国
  GB_COUNTS="$(nft list chain inet km_geoblock pre 2>/dev/null | awk '/counter packets/ {
      p = 0; b = 0
      for (i = 1; i < NF; i++) { if ($i == "packets") p = $(i + 1); if ($i == "bytes") b = $(i + 1) }
      if ($0 ~ /!= @admin/) { ap += p; ab += b } else { dp += p; db += b }
    } END { printf "%.0f %.0f %.0f %.0f", dp, db, ap, ab }')"
fi
GB_DENY_PKTS="$(echo "$GB_COUNTS" | cut -d' ' -f1)"
GB_DENY_BYTES="$(echo "$GB_COUNTS" | cut -d' ' -f2)"
GB_ADMIN_PKTS="$(echo "$GB_COUNTS" | cut -d' ' -f3)"
GB_ADMIN_BYTES="$(echo "$GB_COUNTS" | cut -d' ' -f4)"

F2B_RUNNING=false
F2B_JAILS=""
if command -v fail2ban-client >/dev/null 2>&1 && systemctl is-active --quiet fail2ban 2>/dev/null; then
  F2B_RUNNING=true
  for _jail in $(fail2ban-client status 2>/dev/null | sed -n 's/.*Jail list:[[:space:]]*//p' | tr ',' ' '); do
    case "$_jail" in
      ''|*[!A-Za-z0-9_.-]*) continue ;;
    esac
    _st="$(fail2ban-client status "$_jail" 2>/dev/null || true)"
    _cur="$(printf '%s\n' "$_st" | sed -n 's/.*Currently banned:[[:space:]]*\([0-9][0-9]*\).*/\1/p' | head -n 1)"
    _tot="$(printf '%s\n' "$_st" | sed -n 's/.*Total banned:[[:space:]]*\([0-9][0-9]*\).*/\1/p' | head -n 1)"
    _fail="$(printf '%s\n' "$_st" | sed -n 's/.*Total failed:[[:space:]]*\([0-9][0-9]*\).*/\1/p' | head -n 1)"
    _ips="$(printf '%s\n' "$_st" | sed -n 's/.*Banned IP list:[[:space:]]*//p' | json_ips)"
    F2B_JAILS="$F2B_JAILS${F2B_JAILS:+,}{\"name\":\"$_jail\",\"current\":${_cur:-0},\"total\":${_tot:-0},\"failed\":${_fail:-0},\"ips\":[$_ips]}"
  done
fi

# 22 番の DROP(kosenmap-ssh-kick --ban が fail2ban なしで足したもの)。列: pkts bytes target … source …
IPT_RULES="$( { iptables -nvxL INPUT 2>/dev/null || true; ip6tables -nvxL INPUT 2>/dev/null || true; } \
  | awk '$3 == "DROP" && /dpt:22([^0-9]|$)/ {
      for (i = 4; i <= NF; i++) {
        s = $i; sub(/\/(32|128)$/, "", s)
        if (s ~ /^[0-9A-Fa-f:.]+$/ && s ~ /[.:]/ && s != "0.0.0.0" && s != "::" && $i !~ /\/0$/) {
          printf "%s{\"ip\":\"%s\",\"packets\":%.0f}", (n++ ? "," : ""), s, $1; break
        }
      }
    }')"

BANS_TMP="$(mktemp "$OUT_DIR/.bans.XXXXXX")"
trap 'rm -f "$BANS_TMP"' EXIT INT TERM
{
  printf '{"generatedAt":%s,' "$(date +%s)"
  printf '"geoblock":{"configured":%s,"active":%s,"invert":%s,"deny":[%s],"admin":[%s],' \
    "$GB_CONFIGURED" "$GB_ACTIVE" "$GB_INVERT" "$(gb_countries DENY_COUNTRIES)" "$(gb_countries ADMIN_COUNTRIES)"
  printf '"dropped":{"packets":%s,"bytes":%s},"adminDropped":{"packets":%s,"bytes":%s}},' \
    "${GB_DENY_PKTS:-0}" "${GB_DENY_BYTES:-0}" "${GB_ADMIN_PKTS:-0}" "${GB_ADMIN_BYTES:-0}"
  printf '"fail2ban":{"running":%s,"jails":[%s]},' "$F2B_RUNNING" "$F2B_JAILS"
  printf '"iptables":[%s]}\n' "$IPT_RULES"
} > "$BANS_TMP"
chmod 644 "$BANS_TMP"
mv "$BANS_TMP" "$OUT_DIR/bans.json"
trap - EXIT INT TERM
