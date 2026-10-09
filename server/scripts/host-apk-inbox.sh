#!/bin/sh
#
# アプリの受け箱: GitHub の非公開リポジトリから新しい APK を取ってきて、web に検めさせる(2026-10-09)。
#
#   sh scripts/host-apk-inbox.sh --path /opt/kosenmap      (root の cron が 5 分ごとに。host-updates-setup.sh の版 9)
#
# ## 流れ(docs/02 の「アプリの配布」)
#
#   PC の scripts\apk-push.ps1 が itotakusub/kosenmap-apk に KosenMap.apk・KosenMap-admin.apk・latest.json を置く
#   → これが latest.json を見に行く(If-None-Match。変わっていなければ数百バイトで終わる)
#   → 変わっていれば、latest.json に書かれた APK を取って run/apk-inbox/<時刻>/ に置く(web には読み取り専用で渡してある)
#   → docker compose exec -T web php scripts/apk-inbox.php stage …(検めて受け箱へ。管理者へメール)
#   → 済んだら run/apk-inbox/<時刻>/ を消す
#
# **サーバーに外から APK を受け取る口は作らない**(利用者「セキュリティがガバになるのは嫌」)。ここは取りに行くだけ。
#
# ## 設定(ホストにだけ置く。リポジトリには置かない)
#
# `$PATH_ROOT/apk-inbox.local.conf`(`*.local.conf` なので配備でも GitHub の控えでも送らない)。**root の持ち物・600 にすること。**
# 中身は `名前=値` の行だけを読む(**シェルとして実行しない**)。
#
#   GITHUB_REPO=itotakusub/kosenmap-apk
#   GITHUB_TOKEN=github_pat_…        fine-grained トークン。このリポジトリの Contents を**読むだけ**
#
# 設定が無ければ何もしない(使わないホストに置いてもよい)。
#
# ## 鍵の扱い
#
# - **web には渡さない**(web が破られても鍵は漏れない)。読むのは root のこの台本だけ
# - コマンドの引数に出さない(curl には `-H @ファイル` で渡す。`ps` に出さない)。ファイルは 600 で、終わったら消す
# - 記録(/var/log/kosenmap/apk-inbox.log)にも出さない
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu

PATH_ROOT="/opt/kosenmap"
while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    -h|--help) sed -n '2,33p' "$0"; exit 0 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

CONF="$PATH_ROOT/apk-inbox.local.conf"
STATE_DIR="${KM_APK_INBOX_STATE_DIR:-/var/lib/kosenmap/apk-inbox}"
INBOX_DIR="$PATH_ROOT/run/apk-inbox"
API="${KM_APK_INBOX_API:-https://api.github.com}"
MAX_BYTES=210000000

if [ ! -f "$CONF" ]; then
  echo "設定がありません($CONF)。何もしません"
  exit 0
fi

# ---- 設定を読む(`名前=値` だけ。シェルとして実行しない) ----
GITHUB_REPO=""
GITHUB_TOKEN=""
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in
    ''|'#'*) continue ;;
  esac
  key="${line%%=*}"
  value="${line#*=}"
  case "$key" in
    GITHUB_REPO) GITHUB_REPO="$value" ;;
    GITHUB_TOKEN) GITHUB_TOKEN="$value" ;;
  esac
done < "$CONF"
# 字を絞る(リポジトリは「持ち主/名前」・トークンは英数字と _ だけ)
if ! printf '%s' "$GITHUB_REPO" | grep -Eq '^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$'; then
  echo "GITHUB_REPO の形が違います(持ち主/名前)" >&2
  exit 2
fi
if ! printf '%s' "$GITHUB_TOKEN" | grep -Eq '^[A-Za-z0-9_]{20,255}$'; then
  echo "GITHUB_TOKEN が無いか、形が違います(値は出しません)" >&2
  exit 2
fi

mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"

# 失敗の知らせ: cron は失敗(終わりのコード 1)のたびにメールを送る(send-log.sh --only-failure)。
# 5 分ごとに動くので、鍵の期限切れなどで**同じ失敗を 5 分おきに送らない** —— 同じ文なら 6 時間に 1 回だけ 1 で終わる
FAIL_FILE="$STATE_DIR/last-failure"
fail() {
  echo "$1" >&2
  _now="$(date +%s)"
  _last_msg="$(sed -n 2p "$FAIL_FILE" 2>/dev/null || true)"
  _last_at="$(sed -n 1p "$FAIL_FILE" 2>/dev/null || echo 0)"
  case "$_last_at" in ''|*[!0-9]*) _last_at=0 ;; esac
  if [ "$_last_msg" = "$1" ] && [ $((_now - _last_at)) -lt 21600 ]; then
    echo "(同じ失敗は 6 時間以内に知らせたので、今回は知らせません)"
    exit 0
  fi
  printf '%s\n%s\n' "$_now" "$1" > "$FAIL_FILE"
  exit 1
}
# 前の回が強制終了されて残った作業フォルダ(鍵のファイルが入りうる)を先に片付ける
rm -rf "$STATE_DIR"/.work.*
WORK="$(mktemp -d "$STATE_DIR/.work.XXXXXX")"
DROP=""
cleanup() {
  rm -rf "$WORK"
  if [ -n "$DROP" ]; then rm -rf "$DROP"; fi
}
trap cleanup EXIT INT TERM

# 鍵は 600 のファイルに書いて -H @ で渡す(printf は sh の組み込みなので、引数としてどこにも出ない)
umask 077
printf 'Authorization: Bearer %s\n' "$GITHUB_TOKEN" > "$WORK/auth"
GITHUB_TOKEN=""
umask 022

fetch() {
  # fetch <リポジトリの中のパス> <書き先> [If-None-Match の値]。戻りは HTTP の番号(標準出力)
  _path="$1"
  _out="$2"
  # 足す見出しは位置引数で持つ(変数を引用なしで広げると、値の空白で割れる)
  if [ -n "${3:-}" ]; then
    set -- -H "If-None-Match: $3"
  else
    set --
  fi
  curl -sS --proto =https --max-time 600 --max-filesize "$MAX_BYTES" \
    -H @"$WORK/auth" \
    -H 'Accept: application/vnd.github.raw+json' \
    -H 'X-GitHub-Api-Version: 2022-11-28' \
    -H 'User-Agent: kosenmap-apk-inbox' \
    "$@" \
    -D "$WORK/headers" -o "$_out" -w '%{http_code}' \
    "$API/repos/$GITHUB_REPO/contents/$_path"
}

ETAG_FILE="$STATE_DIR/latest.etag"
DONE_FILE="$STATE_DIR/latest.sha256"
OLD_ETAG="$(cat "$ETAG_FILE" 2>/dev/null || true)"
case "$OLD_ETAG" in
  *[!A-Za-z0-9\"/:._-]*) OLD_ETAG="" ;;   # 書き換えられた印は使わない
esac

CODE="$(fetch latest.json "$WORK/latest.json" "$OLD_ETAG" || echo 000)"
case "$CODE" in
  304)
    echo "変わっていません"
    exit 0
    ;;
  200) ;;
  404)
    # GitHub は、入れない非公開のリポジトリにも 404 を返す。黙って 0 で終わると、鍵の設定の誤りに気づけない
    fail "latest.json がありません(HTTP 404。$GITHUB_REPO にまだ一度も送っていないか、トークンにこのリポジトリが入っていません)"
    ;;
  *)
    fail "GitHub から latest.json を取れません(HTTP $CODE)。鍵の期限・権限を確かめてください"
    ;;
esac
NEW_ETAG="$(sed -n 's/^[Ee][Tt][Aa][Gg]:[[:space:]]*//p' "$WORK/headers" | tr -d '\r' | head -n 1)"

SHA="$(sha256sum "$WORK/latest.json" | cut -d' ' -f1)"
if [ "$SHA" = "$(cat "$DONE_FILE" 2>/dev/null || true)" ]; then
  echo "前に入れた届けと同じです"
  printf '%s\n' "$NEW_ETAG" > "$ETAG_FILE"
  exit 0
fi

# APK の名前(latest.json の "name")。字を絞り、2 つだけ
NAMES="$(grep -o '"name"[[:space:]]*:[[:space:]]*"[A-Za-z0-9._-]*\.apk"' "$WORK/latest.json" | sed 's/.*"\([A-Za-z0-9._-]*\.apk\)"$/\1/' | sort -u)"
COUNT="$(printf '%s\n' "$NAMES" | grep -c . || true)"
if [ "$COUNT" -ne 2 ]; then
  fail "latest.json の APK の名前が 2 つではありません($COUNT)"
fi

mkdir -p "$INBOX_DIR"
chmod 755 "$INBOX_DIR"
ID="$(date +%Y%m%d-%H%M%S)"
DROP="$INBOX_DIR/$ID"
mkdir -p "$DROP"
cp "$WORK/latest.json" "$DROP/latest.json"
for name in $NAMES; do
  CODE="$(fetch "$name" "$DROP/$name" || echo 000)"
  if [ "$CODE" != "200" ]; then
    fail "GitHub から $name を取れません(HTTP $CODE)"
  fi
  echo "取りました: $name($(wc -c < "$DROP/$name") バイト)"
done
chmod 755 "$DROP"
chmod 644 "$DROP"/*

# web に検めさせる(受け箱へ写して、管理者へメール)。
# **www-data で動かす**(既定の root で動かすと受け箱のファイルが root の持ち物になり、あとで web が消せない)
cd "$PATH_ROOT"
if docker compose exec -T -u www-data web php scripts/apk-inbox.php stage "/var/www/apkinbox/$ID"; then
  printf '%s\n' "$SHA" > "$DONE_FILE"
  printf '%s\n' "$NEW_ETAG" > "$ETAG_FILE"
  rm -f "$FAIL_FILE"
  exit 0
fi
fail "web が受け箱に入れられませんでした。次の回でもう一度試します"
