#!/bin/sh
#
# メモリ・スワップ・負荷が高いとき、コンテナがメモリ不足で止められたときにメールで知らせる(2026-10-05、利用者の指示)。
#
#   ./host-resource-alert.sh --path /opt/kosenmap            調べて、閾値を超えていれば送る(cron が 5 分ごとに呼ぶ。root)
#   ./host-resource-alert.sh --path /opt/kosenmap --dry-run  調べて結果を出すだけ(送らない・覚えない)
#   ./host-resource-alert.sh --path /opt/kosenmap --test     閾値に関係なく 1 通送る(届くかの確かめ)
#
# 閾値(環境変数で変えられる):
#   KM_ALERT_MEM_AVAIL_PCT=15   使えるメモリがこの % を切ったら
#   KM_ALERT_SWAP_USED_PCT=50   スワップの使用がこの % を超えたら
#   KM_ALERT_LOAD_PER_CPU=2.0   5 分平均の負荷が CPU 1 個あたりこれを超えたら
#   コンテナの oom_kill(cgroup の memory.events)が前回より増えたら、いつでも
#
# ## 同じ知らせを繰り返さない
#
# 鳴っている間ずっと 5 分ごとに届くと読まれなくなる。**中身(どの条件か)が変わったときと、
# 同じ中身でも 1 時間たったとき**だけ送る(host-security-check.sh の重複止めと同じ考え)。
# 覚えておく場所は /var/lib/kosenmap/resource-alert.state(root だけが読み書き)。
#
# ## 届かない場合
#
# 送るのは send-log.sh → web の中の notify-log.php。**web が止まっている・メモリ不足で落ちているときは
# メールも出ない**(今の知らせ全部と同じ制約)。そのときは管理画面のタスクマネージャーと
# scripts/host-emergency.sh で見る。
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu

PATH_ROOT="/opt/kosenmap"
DRY_RUN=0
TEST=0
while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    --test) TEST=1; shift ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

MEM_AVAIL_PCT="${KM_ALERT_MEM_AVAIL_PCT:-15}"
SWAP_USED_PCT="${KM_ALERT_SWAP_USED_PCT:-50}"
LOAD_PER_CPU="${KM_ALERT_LOAD_PER_CPU:-2.0}"
# 覚えておく場所。root でない利用者で試すときだけ KM_ALERT_STATE_DIR で変える
STATE_DIR="${KM_ALERT_STATE_DIR:-/var/lib/kosenmap}"
STATE="$STATE_DIR/resource-alert.state"
REPEAT_SECONDS=3600

kb() {
  awk -v k="$1:" '$1 == k { print $2; found = 1 } END { if (!found) print 0 }' /proc/meminfo
}

MEM_TOTAL="$(kb MemTotal)"
MEM_AVAIL="$(kb MemAvailable)"
SWAP_TOTAL="$(kb SwapTotal)"
SWAP_FREE="$(kb SwapFree)"
CPUS="$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 1)"
LOAD5="$(awk '{ print $2 }' /proc/loadavg)"

MEM_AVAIL_NOW="$(awk -v a="$MEM_AVAIL" -v t="$MEM_TOTAL" 'BEGIN { printf "%.1f", (t > 0 ? a / t * 100 : 100) }')"
SWAP_USED_NOW="$(awk -v f="$SWAP_FREE" -v t="$SWAP_TOTAL" 'BEGIN { printf "%.1f", (t > 0 ? (t - f) / t * 100 : 0) }')"
LOAD_NOW="$(awk -v l="$LOAD5" -v c="$CPUS" 'BEGIN { printf "%.2f", (c > 0 ? l / c : l) }')"

PROBLEMS=""
add() { PROBLEMS="${PROBLEMS}${PROBLEMS:+
}$1"; }

if awk -v n="$MEM_AVAIL_NOW" -v t="$MEM_AVAIL_PCT" 'BEGIN { exit !(n < t) }'; then
  add "使えるメモリが ${MEM_AVAIL_NOW}% です(閾値 ${MEM_AVAIL_PCT}% 未満)"
fi
if awk -v n="$SWAP_USED_NOW" -v t="$SWAP_USED_PCT" 'BEGIN { exit !(n > t) }'; then
  add "スワップを ${SWAP_USED_NOW}% 使っています(閾値 ${SWAP_USED_PCT}% 超)"
fi
if awk -v n="$LOAD_NOW" -v t="$LOAD_PER_CPU" 'BEGIN { exit !(n > t) }'; then
  add "5 分平均の負荷が CPU 1 個あたり ${LOAD_NOW} です(閾値 ${LOAD_PER_CPU} 超)"
fi

# コンテナのメモリ不足での停止(cgroup の oom_kill の数が前回より増えたもの)
OOM_NOW=""
for _cg in /sys/fs/cgroup/system.slice/docker-*.scope; do
  [ -f "$_cg/memory.events" ] || continue
  _id="${_cg##*/docker-}"
  _id="${_id%.scope}"
  _n="$(awk '$1 == "oom_kill" { print $2 }' "$_cg/memory.events")"
  OOM_NOW="${OOM_NOW}${_id} ${_n:-0}
"
done
OOM_BEFORE=""
if [ -f "$STATE.oom" ]; then
  OOM_BEFORE="$(cat "$STATE.oom")"
fi
_oom_lines="$(printf '%s' "$OOM_NOW" | while read -r _id _n; do
  [ -n "$_id" ] || continue
  _was="$(printf '%s\n' "$OOM_BEFORE" | awk -v i="$_id" '$1 == i { print $2 }')"
  if [ -n "$_was" ] && [ "$_n" -gt "$_was" ]; then
    _name="$(docker inspect -f '{{.Name}}' "$_id" 2>/dev/null | sed 's#^/##' || echo "$_id")"
    echo "コンテナ ${_name} がメモリ不足で止められました(oom_kill ${_was} → ${_n})"
  fi
done)"
if [ -n "$_oom_lines" ]; then
  add "$_oom_lines"
fi

REPORT="使えるメモリ ${MEM_AVAIL_NOW}% / スワップ使用 ${SWAP_USED_NOW}% / 負荷(5 分・CPU あたり)${LOAD_NOW}"

if [ "$DRY_RUN" -eq 1 ]; then
  echo "$REPORT"
  if [ -n "$PROBLEMS" ]; then echo "$PROBLEMS"; else echo "閾値を超えたものはありません"; fi
  exit 0
fi

mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"
printf '%s' "$OOM_NOW" > "$STATE.oom"

if [ "$TEST" -eq 1 ]; then
  PROBLEMS="(試しの知らせです。閾値に関係なく送っています)"
fi
if [ -z "$PROBLEMS" ]; then
  exit 0
fi

# 同じ中身なら 1 時間に 1 回まで(OOM はいつでも送る —— 数が増えたこと自体が新しい知らせ)
SIGNATURE="$(printf '%s' "$PROBLEMS" | sed 's/[0-9.]*%//g; s/[0-9.]* です//g' | cksum | cut -d' ' -f1)"
NOW="$(date +%s)"
if [ "$TEST" -eq 0 ] && [ -z "$_oom_lines" ] && [ -f "$STATE" ]; then
  read -r _sig _at < "$STATE" || true
  if [ "${_sig:-}" = "$SIGNATURE" ] && [ $((NOW - ${_at:-0})) -lt "$REPEAT_SECONDS" ]; then
    exit 0
  fi
fi
printf '%s %s\n' "$SIGNATURE" "$NOW" > "$STATE"

{
  echo "$PROBLEMS"
  echo
  echo "$REPORT"
  echo
  echo "== コンテナ(docker stats)"
  docker stats --no-stream --format '{{.Name}}  CPU {{.CPUPerc}}  メモリ {{.MemUsage}}' 2>/dev/null || true
  echo
  echo "== メモリ(free -h)"
  free -h 2>/dev/null || true
} | sh "$PATH_ROOT/scripts/send-log.sh" --path "$PATH_ROOT" --label 使用率の警告 --status ng
