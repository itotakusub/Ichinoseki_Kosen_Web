#!/bin/sh
#
# 使っていない Docker のイメージと、古いビルドのキャッシュを片付ける(2026-11-06、利用者の指示「自動で解放」)。
#
#   sudo sh ./host-docker-prune.sh --path /opt/kosenmap            何を消すかを出すだけ(既定)
#   sudo sh ./host-docker-prune.sh --path /opt/kosenmap --apply    消す
#
# cron(host-updates-setup.sh の版 11)が毎週日曜 5:47 に --apply で呼ぶ。失敗したときだけ send-log.sh が送る。
#
# ## 消さないもの
#
#   - どれかのコンテナ(止まっているものも)が使っているイメージ
#   - compose に書いてあるイメージ(`docker compose config --images`)。**起こしていない profile の分は残さない**
#     (本番の mailpit。使うときは compose が取り直す)
#   - web の Dockerfile の FROM(作り直しのたびに取り直さない)
#   - host-image-scan.sh の Trivy(版と digest で固定したもの)と、スクリプトが使う道具(KEEP_REFS)
#   - **compose のイメージごとに、1 つ前の版を 1 つ**(戻すときに取り直さなくて済む。digest で固定してあるので、
#     消えても `docker compose pull` で取り直せる)
#   - **ボリュームには触らない**(データそのもの。host-emergency.sh と同じ決まり)
#
# ビルドのキャッシュは BUILD_CACHE_DAYS 日より古いものだけ消す(web を作り直すときに効く分は残る)。
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu
LC_ALL=C
export LC_ALL

PATH_ROOT="/opt/kosenmap"
APPLY=0
BUILD_CACHE_DAYS=14
# ビルドの控えの合計の上限(2026-11-08)。1 回の web の作り直しで約 1GB 増える(11-08 の実測)。直近 3 回ぶんを残す
BUILD_CACHE_MAX=3gb
# スクリプトが docker run で使う道具(host-setup.sh・host-local.sh の alpine、composer の install)
KEEP_REFS="alpine:latest composer:2"

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --apply) APPLY=1; shift ;;
    -h|--help) sed -n '2,26p' "$0"; exit 0 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

if [ "$(id -u)" -ne 0 ]; then
  echo "root で動かしてください(sudo sh $0 --path $PATH_ROOT)。" >&2
  exit 2
fi
if ! cd "$PATH_ROOT"; then
  echo "$PATH_ROOT へ移動できません。--path を確かめてください。" >&2
  exit 1
fi

# 検査(host-image-scan.sh)が docker save している間は待つ(同じ錠。最大 1 時間)
SCAN_LOCK="/var/lib/kosenmap/image-scan/.lock"
if [ -f "$SCAN_LOCK" ]; then
  exec 9>"$SCAN_LOCK"
  if ! flock -w 3600 9; then
    echo "イメージの検査が 1 時間たっても終わりません。今回は片付けません。" >&2
    exit 1
  fi
fi

WORK="$(mktemp -d /var/tmp/km-docker-prune.XXXXXX)"
trap 'rm -rf "$WORK"' EXIT INT TERM

# 名前(repo:tag や repo@digest)をイメージの ID にする。無ければ何も出さない
image_id() {
  docker image inspect --format '{{.Id}}' "$1" 2>/dev/null || true
}
# 名前から repo の部分だけ(タグと digest を外す。ポート付きのレジストリの : は残す)
repo_of() {
  printf '%s' "$1" | sed -e 's/@sha256:.*$//' -e 's#:[^:/]*$##'
}

echo "== いまの使い方 =="
docker system df

# ---------------------------------------------------------------------------
# 残す ID を集める
# ---------------------------------------------------------------------------
KEEP="$WORK/keep.ids"
: > "$KEEP"

# 1. コンテナが使っているもの(止まっているものも)
docker ps -a --format '{{.Image}}' | sort -u | while read -r _ref; do
  image_id "$_ref"
done >> "$KEEP"
for _cid in $(docker ps -aq); do
  docker inspect --format '{{.Image}}' "$_cid" 2>/dev/null || true
done >> "$KEEP"

# 2. compose のイメージ(と、その repo の名前を覚えておく。1 つ前の版を残すのに使う)
COMPOSE_IMAGES="$(docker compose config --images 2>/dev/null | sort -u || true)"
if [ -z "$COMPOSE_IMAGES" ]; then
  echo "compose からイメージの一覧を取れませんでした。消すと戻せなくなるかもしれないので止めます。" >&2
  exit 1
fi
: > "$WORK/repos"
for _ref in $COMPOSE_IMAGES; do
  image_id "$_ref" >> "$KEEP"
  repo_of "$_ref" >> "$WORK/repos"
  echo >> "$WORK/repos"
done

# 3. Dockerfile の FROM・Trivy・道具
for _ref in $(sed -n 's/^FROM[[:space:]]\{1,\}\([^[:space:]]*\).*/\1/p' docker/php/Dockerfile 2>/dev/null) \
    $(sed -n 's/^TRIVY_IMAGE="\([^"]*\)"$/\1/p' scripts/host-image-scan.sh 2>/dev/null) $KEEP_REFS; do
  image_id "$_ref" >> "$KEEP"
  # Dockerfile の FROM は repo:tag でも残す(digest の像と同じ ID でないことがある)
  image_id "$(printf '%s' "$_ref" | sed 's/@sha256:.*$//')" >> "$KEEP"
done

# 4. compose の repo ごとに、残すものの外でいちばん新しい 1 つ(= 1 つ前の版)
#    一覧: 作られた時刻 ID repo(作られた時刻の新しい順)
docker images --no-trunc --format '{{.CreatedAt}}|{{.ID}}|{{.Repository}}' | sort -r > "$WORK/all"
sort -u "$KEEP" | grep -v '^$' > "$WORK/keep.sorted" || true
sort -u "$WORK/repos" | grep -v '^$' | while read -r _repo; do
  awk -F '|' -v repo="$_repo" '$3 == repo { print $2 }' "$WORK/all" | while read -r _id; do
    if ! grep -qx "$_id" "$WORK/keep.sorted"; then
      echo "$_id"
      break
    fi
  done
done > "$WORK/previous"
cat "$WORK/previous" >> "$KEEP"
sort -u "$KEEP" | grep -v '^$' > "$WORK/keep.sorted" || true

# ---------------------------------------------------------------------------
# 消すもの = 全部の ID − 残す ID
# ---------------------------------------------------------------------------
awk -F '|' '{ print $2 }' "$WORK/all" | sort -u > "$WORK/all.ids"
comm -23 "$WORK/all.ids" "$WORK/keep.sorted" > "$WORK/remove.ids"

echo ""
echo "== 残すもの(1 つ前の版として)=="
if [ -s "$WORK/previous" ]; then
  while read -r _id; do
    awk -F '|' -v id="$_id" '$2 == id { print "  " $3 "  " substr($2, 8, 12) "  " $1; exit }' "$WORK/all"
  done < "$WORK/previous"
else
  echo "  (なし)"
fi

echo ""
echo "== 消すイメージ =="
REMOVE_COUNT="$(wc -l < "$WORK/remove.ids" | tr -d ' ')"
if [ "$REMOVE_COUNT" -eq 0 ]; then
  echo "  (なし)"
fi
while read -r _id; do
  _names="$(docker image inspect --format '{{join .RepoTags ", "}}' "$_id" 2>/dev/null || true)"
  _size="$(docker image inspect --format '{{.Size}}' "$_id" 2>/dev/null || echo 0)"
  _repo="$(awk -F '|' -v id="$_id" '$2 == id { print $3; exit }' "$WORK/all")"
  echo "  ${_names:-$_repo <タグなし>}  $(printf '%s' "$_id" | cut -c8-19)  $((_size / 1048576))MB"
done < "$WORK/remove.ids"

if [ "$APPLY" -eq 0 ]; then
  echo ""
  echo "ビルドのキャッシュは ${BUILD_CACHE_DAYS} 日より古いものを消します(いま: $(docker builder du 2>/dev/null | sed -n 's/^Total:[[:space:]]*//p'))。"
  echo "見るだけで終わります。消すには --apply を付けてください。"
  exit 0
fi

# ---------------------------------------------------------------------------
# 消す
# ---------------------------------------------------------------------------
echo ""
echo "== 消します =="
SKIPPED=0
while read -r _id; do
  # 使われていないことは上で確かめた。タグが複数あっても消せるよう -f(動いているコンテナの像は docker が断る)
  if docker image rm -f "$_id" >/dev/null 2>"$WORK/err"; then
    echo "  消した: $(printf '%s' "$_id" | cut -c8-19)"
  else
    # 子のイメージがある・使い始めた、など。次の週にもう一度見る
    echo "  消せませんでした: $(printf '%s' "$_id" | cut -c8-19) $(head -c 200 "$WORK/err")"
    SKIPPED=$((SKIPPED + 1))
  fi
done < "$WORK/remove.ids"
docker image prune -f >/dev/null 2>&1 || true

echo ""
echo "== ビルドのキャッシュ(${BUILD_CACHE_DAYS} 日より古いもの)=="
CACHE_FAILED=0
# **-a(--all)が要る**(2026-11-08)。付けないと「どこからも指されていない(dangling)」控えしか消さず、
# ビルドの段の控えは何日たっても残る(11-08 の初回: 14 日より古いものが 0B・2 か月前のものも 10.66GB のまま)。
# -a でも消すのは「使われていない・最後に使ってから 14 日より古い」ものだけ。直近のビルドの控えは残るので、次の作り直しは速いまま
if ! docker builder prune -a -f --filter "until=$((BUILD_CACHE_DAYS * 24))h"; then
  echo "  ビルドのキャッシュを片付けられませんでした。" >&2
  CACHE_FAILED=1
fi
# **日数だけでは消えない**(2026-11-08。-a を付けても、8 月に作った「消してよい」控えが 93B しか消えなかった。Docker 29.9)。
# そこで合計の上限でも削る —— 上限を超えたぶんを、最後に使ったのが古い順に消す。直近の作り直しの控えは残る
echo ""
echo "== ビルドのキャッシュ(合計 ${BUILD_CACHE_MAX} まで)=="
if ! docker builder prune -a -f --max-used-space "$BUILD_CACHE_MAX"; then
  echo "  ビルドのキャッシュを上限まで減らせませんでした。" >&2
  CACHE_FAILED=1
fi

echo ""
echo "== 片付けたあと =="
docker system df

# 消せなかったイメージ($SKIPPED)は次の週に回るだけなので失敗にしない。キャッシュの失敗だけ知らせる
if [ "$CACHE_FAILED" -eq 1 ]; then
  exit 1
fi
exit 0
