#!/bin/sh
#
# Cloudflare の分析(WAF で止めた記録・時間ごとの通信量)を取ってきて、web に DB へ入れさせる(2026-11-08)。
#
#   sh scripts/host-cloudflare-import.sh --path /opt/kosenmap      (root の cron が 1 時間ごとに。host-updates-setup.sh の版 14)
#
# ## 流れ(lib/cloudflare-analytics.php の先頭にも書いてある)
#
#   web の `php scripts/cloudflare-import.php plan` が DB の続きから「何を問い合わせるか」を出す
#   → ここが curl で https://api.cloudflare.com/client/v4/graphql へ送る
#   → 答えを `php scripts/cloudflare-import.php ingest <名前>` に渡す(読み解いて DB へ。WAF の記録の国・ASN は位置の表にも)
#   → ingest が replan と言ったら(範囲を読んだ・1 ページで足りなかった)、plan からやり直す(6 回まで)
#
# ## 設定(ホストにだけ置く。リポジトリには置かない)
#
# `$PATH_ROOT/cloudflare.local.conf`(`*.local.conf` なので配備でも GitHub の控えでも送らない)。**root の持ち物・600 にすること。**
# 中身は `名前=値` の行だけを読む(**シェルとして実行しない**)。見本は cloudflare.local.conf.example。
#
#   CF_ZONE_ID=0123456789abcdef0123456789abcdef     ito4.jp のゾーンの ID(Cloudflare の画面の「概要」の右下)
#   CF_API_TOKEN=…                                  API トークン。権限は「ゾーン → Analytics → 読み取り」だけ・対象は ito4.jp だけ
#
# 設定が無ければ何もしない(使わないホストに置いてもよい)。
#
# ## 鍵の扱い
#
# - **web には渡さない**(web が破られても鍵は漏れない)。読むのは root のこの台本だけ
# - コマンドの引数に出さない(curl には `-H @ファイル` で渡す。`ps` に出さない)。ファイルは 600 で、終わったら消す
# - 同じ失敗は 6 時間に 1 回だけ知らせる(1 時間ごとに同じメールを送らない)
#
# POSIX sh。`[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。

set -eu

PATH_ROOT="/opt/kosenmap"
while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

CONF="$PATH_ROOT/cloudflare.local.conf"
STATE_DIR="${KM_CF_IMPORT_STATE_DIR:-/var/lib/kosenmap/cloudflare}"
API="https://api.cloudflare.com/client/v4/graphql"
MAX_BYTES=33554432
MAX_ROUNDS=6
TAB="$(printf '\t')"

if [ ! -f "$CONF" ]; then
  echo "設定がありません($CONF)。何もしません"
  exit 0
fi

# ---- 設定を読む(`名前=値` だけ。シェルとして実行しない) ----
CF_ZONE_ID=""
CF_API_TOKEN=""
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in
    ''|'#'*) continue ;;
  esac
  key="${line%%=*}"
  value="$(printf '%s' "${line#*=}" | tr -d '\r')"
  case "$key" in
    CF_ZONE_ID) CF_ZONE_ID="$value" ;;
    CF_API_TOKEN) CF_API_TOKEN="$value" ;;
  esac
done < "$CONF"
if ! printf '%s' "$CF_ZONE_ID" | grep -Eq '^[0-9a-f]{32}$'; then
  echo "CF_ZONE_ID の形が違います(32 桁の 16 進)" >&2
  exit 2
fi
if ! printf '%s' "$CF_API_TOKEN" | grep -Eq '^[A-Za-z0-9_-]{30,255}$'; then
  echo "CF_API_TOKEN が無いか、形が違います(値は出しません)" >&2
  exit 2
fi

mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"
cd "$PATH_ROOT"

web_php() {
  docker compose exec -T -u www-data web php scripts/cloudflare-import.php "$@"
}

FAIL_FILE="$STATE_DIR/last-failure"
fail() {
  echo "$1" >&2
  # 画面にも理由を出す(DB に書けなくても、ここで止めない)
  web_php fail "$1" </dev/null >/dev/null 2>&1 || true
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
cleanup() {
  rm -rf "$WORK"
}
trap cleanup EXIT INT TERM

# 鍵は 600 のファイルに書いて -H @ で渡す(printf は sh の組み込みなので、引数としてどこにも出ない)
umask 077
printf 'Authorization: Bearer %s\n' "$CF_API_TOKEN" > "$WORK/auth"
CF_API_TOKEN=""
umask 022

ROUND=0
ASKED=0
while [ "$ROUND" -lt "$MAX_ROUNDS" ]; do
  ROUND=$((ROUND + 1))
  if ! web_php plan --zone "$CF_ZONE_ID" </dev/null > "$WORK/plan"; then
    fail "web が問い合わせの計画を作れませんでした(DB に届かない・配備の途中など)"
  fi
  if [ ! -s "$WORK/plan" ]; then
    break
  fi
  REPLAN=0
  while IFS="$TAB" read -r name body; do
    case "$name" in
      settings|waf:*|traffic:*) ;;
      *) fail "web の計画に知らない名前があります" ;;
    esac
    printf '%s' "$body" > "$WORK/body"
    CODE="$(curl -sS --proto =https --max-time 120 --max-filesize "$MAX_BYTES" \
      -H @"$WORK/auth" \
      -H 'Content-Type: application/json' \
      -H 'User-Agent: kosenmap-cloudflare-import' \
      --data-binary @"$WORK/body" \
      -o "$WORK/answer" -w '%{http_code}' \
      "$API" 2>"$WORK/curl.err" || echo 000)"
    ASKED=$((ASKED + 1))
    case "$CODE" in
      200) ;;
      400|401|403)
        fail "Cloudflare が断りました(HTTP $CODE・$name)。トークンの期限・権限(ゾーン → Analytics → 読み取り)・ゾーンの ID を確かめてください"
        ;;
      000)
        fail "Cloudflare に届きません($name: $(head -c 200 "$WORK/curl.err" | tr '\n' ' '))"
        ;;
      *)
        fail "Cloudflare から取れません(HTTP $CODE・$name)"
        ;;
    esac
    if ! OUT="$(web_php ingest "$name" < "$WORK/answer" 2>&1)"; then
      fail "取り込めませんでした: $(printf '%s' "$OUT" | head -c 400 | tr '\n' ' ')"
    fi
    printf '%s\n' "$OUT" | grep -v '^replan$' || true
    if printf '%s\n' "$OUT" | grep -qx 'replan'; then
      REPLAN=1
      break
    fi
  done < "$WORK/plan"
  if [ "$REPLAN" -eq 0 ]; then
    break
  fi
done

web_php done "問い合わせ ${ASKED} 回" </dev/null >/dev/null 2>&1 || true
rm -f "$FAIL_FILE"
echo "済みました(問い合わせ ${ASKED} 回)"
exit 0
