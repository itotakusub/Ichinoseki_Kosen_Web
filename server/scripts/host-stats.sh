#!/bin/sh
#
# ホストとコンテナごとの使用量を JSON にして置く(管理画面のタスクマネージャーが読む。2026-10-05)。
#
#   ./host-stats.sh --path /opt/kosenmap     run/hoststats/stats.json を書き直す(cron が 1 分ごとに呼ぶ。root)
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
    -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
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
