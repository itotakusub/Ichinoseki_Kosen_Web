#!/bin/sh
#
# 控えの MariaDB のダンプを、**使い捨ての MariaDB(網なし・データはメモリの上)で抜いてから**検証機の DB へ入れる。
# 検証機(/etc/kosenmap-test-host のある VM)でだけ動く。**本番では止まる。**
#
#   sh scripts/local-import.sh < km-mariadb.sql
#     (PC からは scripts/local-refresh.ps1 が、開いた控えのダンプを SSH の標準入力で流し込む。ファイルを置かない)
#
# ## なぜ要るのか(2026-11-07 段 5-2)
#
# 10-05 の手順は、生のダンプを検証機の円盤に置いて本物の DB へ戻し、そのあと local-sanitize.sh で書き換えていた。
# これだと書き換える前の行が InnoDB の古いページ・undo・binlog に残り、**円盤を読めば本番の氏名が出てくる**。
# そこで生の行は円盤に触れさせない:
#
#   1. 使い捨ての MariaDB を立てる(compose と同じイメージ・--network none・/var/lib/mysql は tmpfs)
#   2. 標準入力のダンプをそこへ入れる
#   3. local-sanitize.sh --container で抜き、--check が 0 件でなければ**ここで止める**(本物の DB には何も入れない)
#   4. 抜いたあとのダンプだけを、本物の DB(compose の mariadb)へ入れる。DB は作り直す(古い表を残さない)
#   5. 使い捨ての MariaDB を消す(何があっても。trap)
#
# web は 4 の前に止め、終わっても止めたまま(local-refresh.ps1 がダミーのファイルを作ってから起こす)。
# POSIX sh。**`[ … ] && cmd` を単独で書かない**(set -e の下で、偽のときにスクリプトごと終わる)。

set -eu

cd "$(dirname "$0")/.."

PROD_DOMAINS="ito4.jp ito8795.com"
MARKER=/etc/kosenmap-test-host
NAME="km-sanitize-$$"

case "${1:-}" in
  -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
  '') ;;
  *) echo "知らない引数: $1" >&2; exit 2 ;;
esac

env_value() {
  if [ ! -f .env ]; then
    return 0
  fi
  sed -n "s/^$1=//p" .env | tail -n 1 | tr -d '\r' | sed "s/^\"\\(.*\\)\"\$/\\1/; s/^'\\(.*\\)'\$/\\1/"
}

# ---------------------------------------------------------------- 本番では止まる
if [ "$(env_value KM_ENV)" != "local" ]; then
  echo "★ .env が KM_ENV=local ではありません。検証機(host-local.sh init のあと)でだけ流します。" >&2
  exit 1
fi
_domain="$(env_value KM_DOMAIN)"
for _p in $PROD_DOMAINS; do
  case "$_domain" in
    "$_p"|*".$_p") echo "★ KM_DOMAIN が本番の名前($_domain)です。止めます。" >&2; exit 1 ;;
  esac
done
if [ ! -f "$MARKER" ]; then
  echo "★ $MARKER がありません。検証機(testhost-vm.ps1 で作った VM)でだけ流します。" >&2
  exit 1
fi
if [ -t 0 ]; then
  echo "★ ダンプを標準入力で渡してください(sh scripts/local-import.sh < km-mariadb.sql)。" >&2
  exit 2
fi

# compose と同じイメージ(digest まで)。compose.yaml の mariadb の image 行
IMAGE="$(docker compose config --images 2>/dev/null | grep -E '^mariadb:' | head -n 1 || true)"
if [ -z "$IMAGE" ]; then
  echo "★ compose の mariadb のイメージが分かりません(docker compose config --images)。" >&2
  exit 1
fi

cleanup() {
  docker rm -f "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

echo "== 1. 使い捨ての MariaDB($IMAGE・網なし・tmpfs)"
_root="$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')"
# パスワードは引数に載せない(--env-file で渡し、すぐ消す)
_envf="$(mktemp)"
chmod 600 "$_envf"
printf 'MARIADB_ROOT_PASSWORD=%s\nMARIADB_DATABASE=kosenmap\n' "$_root" > "$_envf"
docker run -d --name "$NAME" --network none \
  --tmpfs /var/lib/mysql:rw,size=768m,mode=0700 \
  --memory 900m --env-file "$_envf" \
  "$IMAGE" --innodb-buffer-pool-size=128M --skip-log-bin >/dev/null
rm -f "$_envf"
_i=0
until docker logs "$NAME" 2>&1 | grep -q 'MariaDB init process done'; do
  _i=$((_i + 1))
  if [ "$_i" -gt 90 ]; then
    echo "★ 使い捨ての MariaDB が起きません(docker logs $NAME)。" >&2
    exit 1
  fi
  sleep 2
done
until docker exec "$NAME" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot -e "SELECT 1"' >/dev/null 2>&1; do
  _i=$((_i + 1))
  if [ "$_i" -gt 150 ]; then
    echo "★ 使い捨ての MariaDB に繋がりません。" >&2
    exit 1
  fi
  sleep 2
done
echo "  起きました"

echo "== 2. 控えのダンプを入れる(標準入力から。円盤には置かない)"
docker exec -i "$NAME" sh -c '
  set -e
  MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  export MYSQL_PWD
  exec mariadb -uroot --default-character-set=utf8mb4 "$MARIADB_DATABASE"
'
_tables="$(docker exec "$NAME" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \"$MARIADB_DATABASE\""')"
echo "  表 $_tables 個"
if [ "$_tables" -lt 10 ]; then
  echo "★ 表が少なすぎます(ダンプが途中で切れた?)。本物の DB には入れません。" >&2
  exit 1
fi

echo "== 3. 抜く(使い捨ての方で)"
sh scripts/local-sanitize.sh --container "$NAME"
if ! sh scripts/local-sanitize.sh --check --container "$NAME"; then
  echo "★ 抜き残しがあります。本物の DB には入れません(使い捨ての方は消します)。" >&2
  exit 1
fi

echo "== 4. 抜いたあとのダンプを本物の DB へ(web を止めてから・DB は作り直す)"
docker compose stop web >/dev/null 2>&1 || true
docker compose up -d mariadb >/dev/null
_i=0
until docker compose exec -T mariadb sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot -e "SELECT 1"' >/dev/null 2>&1; do
  _i=$((_i + 1))
  if [ "$_i" -gt 60 ]; then
    echo "★ 検証機の MariaDB に繋がりません(docker compose logs mariadb)。" >&2
    exit 1
  fi
  sleep 2
done
docker compose exec -T mariadb sh -c '
  set -e
  DB=$(printf %s "$MARIADB_DATABASE" | tr -d "\r\n ")
  MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  export MYSQL_PWD
  mariadb -uroot -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4;"
'
docker exec "$NAME" sh -c '
  set -e
  MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  export MYSQL_PWD
  exec mariadb-dump -uroot --single-transaction --routines --events --default-character-set=utf8mb4 "$MARIADB_DATABASE"
' | docker compose exec -T mariadb sh -c '
  set -e
  DB=$(printf %s "$MARIADB_DATABASE" | tr -d "\r\n ")
  MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  export MYSQL_PWD
  exec mariadb -uroot --default-character-set=utf8mb4 "$DB"
'

echo "== 5. 本物の DB でも数える"
sh scripts/local-sanitize.sh --check

mkdir -p run
printf '%s 控えから入れた(表 %s 個・抜いてから)\n' "$(date '+%F %T')" "$_tables" >> run/local-import.log
echo
echo "入れました。web は止めたまま —— 次は local-refresh.ps1 の続き(ダミーのファイル → 起動)。"
