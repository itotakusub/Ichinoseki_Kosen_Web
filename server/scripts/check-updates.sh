#!/bin/sh
#
# コンテナの更新を調べ、変化があれば管理者へメールを送る。
#
#   ./check-updates.sh                 調べて表示するだけ
#   ./check-updates.sh --notify        変化があればメールも送る
#   ./check-updates.sh --notify --heartbeat
#                                      変化が無くても送る(月に一度これを回す)
#   ./check-updates.sh --pins          compose.yaml へ貼る image: 行を出す
#
# **当てはしない。知らせるだけ。**
# 理由は src/lib/update-notice.php に書いてある —— Logto は起動時に DB の移行を走らせ、
# しかも管理画面のゲートそのもの(nginx の auth_request)なので、
# 落ちると直しに行く手段ごと失われる。
#
# POSIX sh。**`[ … ] && cmd` を単独で書かない** —— set -e の下では
# 判定が偽になった時点でスクリプトごと終わる(過去に踏んだ)。

set -eu

DEFAULT_ARGS=""

PATH_ROOT="/opt/kosenmap"
NOTIFY=0
HEARTBEAT=0
PINS_ONLY=0

# ---------------------------------------------------------------------------
# 同じ知らせを繰り返さない
# ---------------------------------------------------------------------------
# **新しい版は、上げるまで毎回「新しい版」のまま。**
# そのたびに送ると毎日同じメールが届き、読まれなくなる ——
# 本当に別の版が出たときには、もう開かれていない。
# 送るのは「中身が変わったとき」「KM_REMIND_DAYS 日たったとき」「heartbeat」だけ。
# (host-security-check.sh と同じ作法。片方だけにすると挙動が食い違う)
STATE_DIR="/var/lib/kosenmap"
STATE_FILE="$STATE_DIR/updates-last"
KM_REMIND_DAYS=7

# host-setup.sh と同じ差し込み口。PowerShell から標準入力で流すときに使う
if [ -n "$DEFAULT_ARGS" ]; then
  set -- $DEFAULT_ARGS "$@"
fi

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --notify) NOTIFY=1; shift ;;
    --heartbeat) HEARTBEAT=1; shift ;;
    --pins) PINS_ONLY=1; shift ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

cd "$PATH_ROOT"

HOST_LABEL="$(hostname 2>/dev/null || echo unknown)"

# ---------------------------------------------------------------------------
# 動いているコンテナの image を集める
# ---------------------------------------------------------------------------
# **compose.yaml を読まない。** 書いてあるものと動いているものは違いうる ——
# それを見つけるのがこのスクリプトの仕事。docker に聞く。
#
# `docker compose ps --format` に Go テンプレートは渡せない(compose v2 は
# table / json しか受けない)。id を採ってから inspect する。
# 区切りは `|`。イメージ名にもサービス名にも現れない。
running_images() {
  for _cid in $(docker compose ps -q 2>/dev/null); do
    docker inspect \
      --format '{{index .Config.Labels "com.docker.compose.service"}}|{{.Config.Image}}' \
      "$_cid" 2>/dev/null || true
  done
}

# そのイメージが実際に持っている digest。無ければ空
local_digest() {
  docker image inspect --format '{{if .RepoDigests}}{{index .RepoDigests 0}}{{end}}' "$1" 2>/dev/null \
    | sed -n 's/.*@\(sha256:[0-9a-f]*\)$/\1/p' | head -n 1
}

# レジストリ側の digest。
#
# **取れなければ空を返す。** 「聞けなかった」と「変わっていない」を混同しない ——
# 混同すると、レジストリが落ちている間じゅう「更新なし」と報告し続ける。
#
# buildx の imagetools を使うのは、これが**マニフェストリストの digest**を出すため。
# `docker manifest inspect --verbose` の Descriptor はプラットフォーム別の digest で、
# 手元の RepoDigests(リストの digest)と比べると毎回食い違う。
remote_digest() {
  docker buildx imagetools inspect "$1" 2>/dev/null \
    | sed -n 's/^Digest:[[:space:]]*\(sha256:[0-9a-f]*\).*/\1/p' | head -n 1
}

# ---------------------------------------------------------------------------
# Logto だけは「版番号」で見る
# ---------------------------------------------------------------------------
# digest の比較で分かるのは「タグの中身が入れ替わったか」だけで、
# **固定したタグでは何も起きない。** 人が知りたいのは「新しい版が出たか」なので、
# 公開されているリリースを見る。
# 他のイメージに同じ手は使えない(版の出所がばらばら)。
logto_latest_release() {
  curl -fsS --max-time 20 \
    -H 'Accept: application/vnd.github+json' \
    https://api.github.com/repos/logto-io/logto/releases/latest 2>/dev/null \
    | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1
}

is_floating_tag() {
  case "$1" in
    *:latest|*:alpine|*:stable|*:main|*:edge) return 0 ;;
    *) return 1 ;;
  esac
}

# digest で固定したものは動かないので比べない
is_pinned_digest() {
  case "$1" in
    *@sha256:*) return 0 ;;
    *) return 1 ;;
  esac
}

# `名前:タグ@sha256:…` の形なら `名前:タグ` を返す(2026-09-15)。タグの無い digest だけの固定なら空。
# **2026-09-15 から全イメージを digest で固定した。** タグも残してあるのは、ここで
# 「タグの今の中身が、固定した digest から動いたか(= 修正版が出たか)」を読むため。
pinned_tag() {
  _ref="${1%@*}"
  if [ "$_ref" = "$1" ]; then
    return 0
  fi
  case "${_ref##*/}" in
    *:*) printf '%s' "$_ref" ;;
  esac
}

# ---------------------------------------------------------------------------
# 自前でビルドするサービスと、その Dockerfile
# ---------------------------------------------------------------------------
# **ここに無い build: サービスは、ビルド元が見張られない。**
# compose.yaml に build: を足したら、ここにも足すこと(check.php が突き合わせる)。
#
# 動いている像(kosenmap-web など)はどのレジストリにも無いので、像そのものは比べられない。
# 見るのは FROM と COPY --from= に書いた**ビルド元**。pull では変わらず、
# `docker compose build --pull` し直すまで当たらない。
BUILD_DOCKERFILES="web=docker/php/Dockerfile soketi=docker/soketi/Dockerfile"

is_local_build() {
  for _pair in $BUILD_DOCKERFILES; do
    if [ "${_pair%%=*}" = "$1" ]; then
      return 0
    fi
  done
  return 1
}

json_escape() {
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

ITEMS=""
add_item() {
  _entry="{\"name\":\"$(json_escape "$1")\",\"current\":\"$(json_escape "$2")\",\"available\":\"$(json_escape "$3")\",\"kind\":\"$4\"}"
  if [ -z "$ITEMS" ]; then
    ITEMS="$_entry"
  else
    ITEMS="$ITEMS,$_entry"
  fi
}

# **パイプで while を回さない。** サブシェルになり、中で立てた ITEMS が消える。
IMAGE_LIST="$(running_images)"
if [ -z "$IMAGE_LIST" ]; then
  echo "動いているコンテナがありません。--path が正しいか確かめてください: $PATH_ROOT" >&2
  exit 1
fi

echo "== 動いているコンテナ =="
for _line in $IMAGE_LIST; do
  _service="${_line%%|*}"
  _image="${_line#*|}"
  _digest="$(local_digest "$_image")"
  if is_floating_tag "$_image"; then
    echo "  $_service  $_image  ${_digest:-digest不明}  ← タグが固定されていません"
  else
    echo "  $_service  $_image  ${_digest:-digest不明}"
  fi
done

if [ "$PINS_ONLY" -eq 1 ]; then
  echo ""
  echo "== compose.yaml へ貼る行(いま動いているものを固定する) =="
  for _line in $IMAGE_LIST; do
    _service="${_line%%|*}"
    _image="${_line#*|}"
    # **貼ってはいけないものは、そもそも出さない。**
    #   ビルドするサービス … 像はどのレジストリにも無い。image: に替えると build: が消え、
    #                        取りに行けずに起動しない
    # 以前は10本全部を出していて、貼る側が見分ける必要があった(2026-09-09)
    #
    # **タグを残して digest を足す**(2026-09-15)。以前はタグを落として `名前@digest` を出していたので、
    # logto は貼れなかった(版番号をタグから読むため)。`名前:タグ@digest` なら logto も貼れて、
    # 系列のタグ(mariadb:11.4 など)も「固定より新しい修正版」を知らせ続けられる(compare_pinned)。
    if is_local_build "$_service"; then
      echo "    # $_service … ビルドするサービスなので貼らないこと(build: が消えて起動しなくなる)"
      continue
    fi
    _digest="$(local_digest "$_image")"
    _name="${_image%%@*}"
    echo "    # $_service"
    if [ -n "$_digest" ]; then
      echo "    image: ${_name}@${_digest}"
    else
      echo "    # digest を読めません。docker compose pull のあと、もう一度実行してください"
      echo "    image: ${_image}"
    fi
  done
  exit 0
fi

# ---------------------------------------------------------------------------
# 比べる
# ---------------------------------------------------------------------------
echo ""
echo "== 比べた結果 =="

# 比べ方は1つ。動くタグ・系列タグ・ビルド元で、違うのは「知らせの種類」だけ
compare_digest() {
  # $1 = 像の名前 / $2 = 種類(digest / series / base) / $3 = 表示に添える説明(空でよい)
  _have="$(local_digest "$1")"
  _want="$(remote_digest "$1")"
  _label="$1${3:+ ($3)}"
  if [ -z "$_want" ]; then
    echo "  $_label  レジストリに聞けませんでした(判定を保留)"
  elif [ -z "$_have" ]; then
    echo "  $_label  手元の digest を読めません(判定を保留)"
  elif [ "$_have" = "$_want" ]; then
    echo "  $_label  変化なし"
  else
    case "$2" in
      digest) echo "  $_label  ★ 同じタグの中身が入れ替わっています" ;;
      series) echo "  $_label  ★ 修正版が出ています(系列のタグの中身が入れ替わりました)" ;;
      base)   echo "  $_label  ★ ビルド元が更新されています(build --pull するまで当たりません)" ;;
    esac
    add_item "$_label" "$_have" "$_want" "$2"
  fi
}

# **タグと digest の両方で固定したもの**(`名前:タグ@sha256:…`。2026-09-15 から)。
# 動くのは digest なので pull しても変わらない。タグの今の中身と固定した digest を比べ、
# 違えば修正版が出ている —— compose / Dockerfile の digest を書き換えて入れ替えるまで当たらない。
compare_pinned() {
  # $1 = 名前:タグ@sha256:… / $2 = 種類(series / base) / $3 = 表示に添える説明(空でよい)
  _tag="$(pinned_tag "$1")"
  _pinned="${1##*@}"
  _label="$_tag${3:+ ($3)}"
  _want="$(remote_digest "$_tag")"
  if [ -z "$_want" ]; then
    echo "  $_label  レジストリに聞けませんでした(判定を保留)"
  elif [ "$_want" = "$_pinned" ]; then
    echo "  $_label  変化なし(固定した digest がタグの最新)"
  else
    echo "  $_label  ★ 固定した digest より新しい中身がタグに出ています(digest を書き換えるまで当たりません)"
    add_item "$_label" "$_pinned" "$_want" "$2"
  fi
}

# **系列のタグも比べる。** 以前は動くタグ(latest / alpine …)しか比べておらず、
# `postgres:17-alpine` / `mariadb:11.4` / `phpmyadmin:5.2.3-apache` は
# 固定もされず見張られてもいなかった —— 修正版が出ても誰も知らなかった(2026-09-13)。
for _line in $IMAGE_LIST; do
  _service="${_line%%|*}"
  _image="${_line#*|}"
  # 自前でビルドしたもの・版番号で見る logto は、ここでは比べない
  if is_local_build "$_service" || [ "$_service" = "logto" ]; then
    continue
  fi
  # digest で固定したもの: タグも書いてあれば、固定より新しい修正版が出たかを見る。digest だけなら比べようが無い
  if is_pinned_digest "$_image"; then
    if [ -n "$(pinned_tag "$_image")" ]; then
      compare_pinned "$_image" series ""
    fi
    continue
  fi
  if is_floating_tag "$_image"; then
    compare_digest "$_image" digest ""
  else
    compare_digest "$_image" series ""
  fi
done

# ---------------------------------------------------------------------------
# ビルド元(FROM)は**層の先頭**で比べる
# ---------------------------------------------------------------------------
# **手元のビルド元の digest とは比べない。** BuildKit はビルド元を像の一覧に残さないので
# 読めないことが多い(2026-09-13 に php:8.4-apache も node:16-bullseye-slim も「読めません」だった)。
# かといって `docker pull` してから比べると、**動いている web は古いビルド元のままなのに
# 「変化なし」と出る** —— 引いた像と、ビルドに使った像は別物だから。
#
# 知りたいのは「動いている像が、いまのビルド元から作られているか」。
# 像はビルド元の層の上に積むので、**動いている像の層の先頭が、レジストリにある
# ビルド元の層とそっくり同じなら**いまのビルド元から作られている。ずれていれば更新が出ている。
compare_base_layers() {
  # $1 = ビルド元 / $2 = 動いている像 / $3 = 表示に添える説明
  _label="$1 ($3)"
  _plat="$(docker version --format '{{.Server.Os}}/{{.Server.Arch}}' 2>/dev/null || echo linux/amd64)"
  _want_layers="$(docker buildx imagetools inspect "$1" \
    --format "{{range (index .Image \"$_plat\").RootFS.DiffIDs}}{{println .}}{{end}}" 2>/dev/null \
    | grep sha256 || true)"
  _have_layers="$(docker image inspect "$2" --format '{{range .RootFS.Layers}}{{println .}}{{end}}' 2>/dev/null \
    | grep sha256 || true)"
  if [ -z "$_want_layers" ]; then
    echo "  $_label  レジストリに聞けませんでした(判定を保留)"
    return 0
  fi
  if [ -z "$_have_layers" ]; then
    echo "  $_label  動いている像 $2 の層を読めません(判定を保留)"
    return 0
  fi
  _n="$(printf '%s\n' "$_want_layers" | wc -l)"
  if [ "$(printf '%s\n' "$_have_layers" | head -n "$_n")" = "$_want_layers" ]; then
    echo "  $_label  変化なし(動いている像は、いまのビルド元から作られています)"
  else
    echo "  $_label  ★ ビルド元が更新されています(build --pull するまで当たりません)"
    _built_at="$(docker image inspect "$2" --format '{{.Created}}' 2>/dev/null | cut -c1-19)"
    _want_digest="$(remote_digest "$1")"
    add_item "$_label" "${_built_at:-不明} にビルドしたもの" "${_want_digest:-不明}" base
  fi
}

for _pair in $BUILD_DOCKERFILES; do
  _service="${_pair%%=*}"
  _dockerfile="$PATH_ROOT/${_pair#*=}"
  if [ ! -f "$_dockerfile" ]; then
    echo "  $_service のビルド元  Dockerfile がありません: $_dockerfile(判定を保留)"
    continue
  fi
  _from="$(sed -n 's/^[Ff][Rr][Oo][Mm][[:space:]]\{1,\}\([^[:space:]]\{1,\}\).*/\1/p' "$_dockerfile")"
  _copy="$(sed -n 's/^[Cc][Oo][Pp][Yy][[:space:]].*--from=\([^[:space:]]\{1,\}\).*/\1/p' "$_dockerfile")"
  _built="$(printf '%s\n' "$IMAGE_LIST" | sed -n "s/^${_service}|//p" | head -n 1)"

  for _base in $_from; do
    if is_pinned_digest "$_base"; then
      # `FROM php:8.4-apache@sha256:…` のようにタグも書いてあれば、固定より新しいビルド元が出たかを見る
      if [ -n "$(pinned_tag "$_base")" ]; then
        compare_pinned "$_base" base "$_service のビルド元"
      fi
      continue
    fi
    if [ -z "$_built" ]; then
      echo "  $_base ($_service のビルド元)  $_service が動いていません(判定を保留)"
      continue
    fi
    compare_base_layers "$_base" "$_built" "$_service のビルド元"
  done

  # COPY --from= は取り込んだファイルが新しい層になるだけで、層の先頭は重ならない。
  # こちらは像どうしの digest で比べる(手元に無ければ保留と出す)
  for _base in $_copy; do
    if is_pinned_digest "$_base"; then
      # `FROM php:8.4-apache@sha256:…` のようにタグも書いてあれば、固定より新しいビルド元が出たかを見る
      if [ -n "$(pinned_tag "$_base")" ]; then
        compare_pinned "$_base" base "$_service のビルド元"
      fi
      continue
    fi
    # `COPY --from=fork` のような**段の名前**はイメージではない(その段の FROM は上で見ている)
    case "$_base" in
      *:*|*/*) ;;
      *) continue ;;
    esac
    compare_digest "$_base" base "$_service のビルド元"
  done
done

LOGTO_IMAGE=""
for _line in $IMAGE_LIST; do
  if [ "${_line%%|*}" = "logto" ]; then
    LOGTO_IMAGE="${_line#*|}"
  fi
done

if [ -n "$LOGTO_IMAGE" ]; then
  # `…/logto:1.43.0@sha256:…` から版番号だけを取る(digest を先に落とす。2026-09-15)
  _logto_ref="${LOGTO_IMAGE%@*}"
  LOGTO_CURRENT="${_logto_ref##*:}"
  LOGTO_LATEST="$(logto_latest_release || true)"
  LOGTO_LATEST="${LOGTO_LATEST#v}"
  if [ -z "$LOGTO_LATEST" ]; then
    echo "  logto  公開されている版を取れませんでした(判定を保留)"
  elif [ "$LOGTO_CURRENT" = "$LOGTO_LATEST" ]; then
    echo "  logto  $LOGTO_CURRENT (最新)"
  else
    echo "  logto  ★ 新しい版があります: $LOGTO_CURRENT → $LOGTO_LATEST"
    add_item "logto" "$LOGTO_CURRENT" "$LOGTO_LATEST" "release"
  fi
fi

# ---------------------------------------------------------------------------
# 知らせる
# ---------------------------------------------------------------------------
if [ "$NOTIFY" -eq 0 ]; then
  echo ""
  echo "調べただけです。メールを送るには --notify を付けてください。"
  exit 0
fi

if [ -z "$ITEMS" ] && [ "$HEARTBEAT" -eq 0 ]; then
  echo ""
  echo "変化がないので送りません。"
  # **直ったら忘れる。** 覚えたままだと、同じ版がまた出たときに黙ってしまう
  rm -f "$STATE_FILE" 2>/dev/null || true
  exit 0
fi

# 前回と同じ知らせか。**中身で見る**(時刻では毎回変わってしまう)
FINGERPRINT="$(printf '%s' "$ITEMS" | md5sum 2>/dev/null | awk '{print $1}' || true)"

should_send() {
  # 月に一度の便りは必ず出す。**ここを抑えると沈黙が正常に見える**
  if [ "$HEARTBEAT" -eq 1 ]; then
    return 0
  fi
  if [ ! -f "$STATE_FILE" ] || [ -z "$FINGERPRINT" ]; then
    return 0
  fi
  _prev_fp="$(sed -n '1p' "$STATE_FILE" 2>/dev/null || true)"
  _prev_at="$(sed -n '2p' "$STATE_FILE" 2>/dev/null || true)"
  if [ "$_prev_fp" != "$FINGERPRINT" ]; then
    return 0
  fi
  _age_days=$(( ($(date +%s) - ${_prev_at:-0}) / 86400 ))
  if [ "$_age_days" -ge "$KM_REMIND_DAYS" ]; then
    return 0
  fi
  return 1
}

if ! should_send; then
  echo ""
  echo "前回と同じ内容なので送りません(${KM_REMIND_DAYS} 日たてば念押しを送ります)。"
  echo "いま送るなら --heartbeat を付けてください。"
  exit 0
fi

HEARTBEAT_JSON=false
if [ "$HEARTBEAT" -eq 1 ]; then
  HEARTBEAT_JSON=true
fi

REPORT="{\"host\":\"$(json_escape "$HOST_LABEL")\",\"heartbeat\":$HEARTBEAT_JSON,\"items\":[$ITEMS]}"

echo ""
echo "== メールを送ります =="

# **送れてから覚える。** 先に覚えると、送信に失敗した回で「送った」ことになり、
# 次からは「前回と同じ」で黙る —— 一度きりの取りこぼしが**永久の沈黙**になる。
if printf '%s' "$REPORT" | docker compose exec -T web php scripts/notify-update.php; then
  if mkdir -p "$STATE_DIR" 2>/dev/null; then
    printf '%s\n%s\n' "$FINGERPRINT" "$(date +%s)" > "$STATE_FILE" 2>/dev/null || true
  fi
  exit 0
fi

echo "送れませんでした。次の実行でもう一度試します。" >&2
exit 1
