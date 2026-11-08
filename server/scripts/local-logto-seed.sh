#!/bin/sh
#
# 検証機の空の Logto を本番の形に組む(src/scripts/local-logto-seed.php の包み)。**検証機でだけ動く。**
#
#   sh scripts/local-logto-seed.sh < logto-email-templates.json          作るものを並べるだけ
#   sh scripts/local-logto-seed.sh --apply < logto-email-templates.json  作って、ID と秘密を .env に、ダミーの利用者を local-state/dummy-accounts.txt に
#
# 標準入力はメールのテンプレートの正本(PC の server/docs/logto-email-templates.json。docs/ は配備しないので PC から流す)。
# **値は画面に出さない。** .env に書いたキーの名前と、利用者の名前だけを出す。パスワードは local-state/dummy-accounts.txt(置き場の持ち主だけ読める。600)。
# 書いたあと web と logto を起こし直す(.env を読み直させる)。2026-11-07 段 5-4。
# POSIX sh。**`[ … ] && cmd` を単独で書かない**(set -e の下で、偽のときにスクリプトごと終わる)。

set -eu

cd "$(dirname "$0")/.."

PROD_DOMAINS="ito4.jp ito8795.com"
MARKER=/etc/kosenmap-test-host
APPLY=""
case "${1:-}" in
  --apply) APPLY="--apply" ;;
  '') ;;
  -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
  *) echo "知らない引数: $1" >&2; exit 2 ;;
esac

env_value() {
  if [ ! -f .env ]; then
    return 0
  fi
  sed -n "s/^$1=//p" .env | tail -n 1 | tr -d '\r' | sed "s/^\"\\(.*\\)\"\$/\\1/; s/^'\\(.*\\)'\$/\\1/"
}

set_env() {
  case "$2" in
    *'|'*|*'
'*) echo "書けない値です: $1" >&2; exit 2 ;;
  esac
  if grep -q "^$1=" .env; then
    sed -i "s|^$1=.*|$1=$2|" .env
  else
    printf '%s=%s\n' "$1" "$2" >> .env
  fi
}

if [ "$(env_value KM_ENV)" != "local" ] || [ ! -f "$MARKER" ]; then
  echo "★ 検証機(KM_ENV=local・$MARKER あり)でだけ流します。" >&2
  exit 1
fi
_domain="$(env_value KM_DOMAIN)"
for _p in $PROD_DOMAINS; do
  case "$_domain" in
    "$_p"|*".$_p") echo "★ KM_DOMAIN が本番の名前です。止めます。" >&2; exit 1 ;;
  esac
done
if [ -z "$(env_value LOGTO_M2M_APP_ID)" ] || [ -z "$(env_value LOGTO_M2M_APP_SECRET)" ]; then
  echo "★ .env の LOGTO_M2M_APP_ID・LOGTO_M2M_APP_SECRET が空です。" >&2
  echo "  Console(SSH のトンネルで http://localhost:3002)で m2m のアプリを作り、Management API の権限を付けて書く(docs/13 §4-3)。" >&2
  exit 1
fi

_out="$(mktemp)"
chmod 600 "$_out"
trap 'rm -f "$_out"' EXIT INT TERM
docker compose exec -T -u www-data web php scripts/local-logto-seed.php - $APPLY > "$_out"

if [ -z "$APPLY" ]; then
  exit 0
fi

echo "== .env に書く(値は出さない)"
cp -p .env ".env.bak-$(date +%Y%m%d-%H%M%S)"
_n=0
while IFS="$(printf '\t')" read -r _kind _key _value _rest; do
  if [ "$_kind" != "ENV" ]; then
    continue
  fi
  case "$_key" in
    LOGTO_APP_ID|LOGTO_APP_SECRET|KM_ANDROID_APP_ID|KM_LOGTO_ORG_ID|LOGTO_WEBHOOK_SIGNING_KEY) ;;
    *) echo "  ★ 知らないキーは書きません: $_key" >&2; continue ;;
  esac
  set_env "$_key" "$_value"
  printf '  書いた %s\n' "$_key"
  _n=$((_n + 1))
done < "$_out"

echo "== ダミーの利用者(local-state/dummy-accounts.txt。パスワードはこのファイルにだけ)"
( umask 077 && mkdir -p local-state )
_acc=local-state/dummy-accounts.txt
if [ ! -f "$_acc" ]; then
  ( umask 077 && printf '# 検証機のダミーの利用者(local-logto-seed.sh が作った)。利用者名\tメール\tパスワード\t役割\n' > "$_acc" )
fi
chmod 600 "$_acc"
grep '^ACCOUNT	' "$_out" | cut -f2- >> "$_acc" || true
grep '^ACCOUNT	' "$_out" | cut -f2,5 | sed 's/^/  作った /; s/	/(/; s/$/)/' || true

if [ "$_n" -gt 0 ]; then
  echo "== .env を読み直させる(web・logto)"
  docker compose up -d web logto >/dev/null
fi
echo "済みました。サインインは local-state/dummy-accounts.txt の利用者で(確認コードのメールは https://$_domain:8025)。"
