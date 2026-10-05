#!/bin/sh
#
# ローカル環境(LAN の検証機)の MariaDB から、機密と個人の情報を抜く。**本番では止まる。**
#
#   sh scripts/local-sanitize.sh            抜く(何度流してもよい)
#   sh scripts/local-sanitize.sh --check    残っていないかを数えるだけ(何も変えない)
#
# ## なぜ要るのか(2026-10-05)
#
# 検証機に「機密なしのコピー」を置くため。控えから戻すのは **MariaDB だけ**
# (.env・config/*.local.php・Logto・uploads/ は持ち込まず、秘密は host-local.sh init --fresh-secrets で新しく作る)。
# 戻した直後、web を起動する前にこれを流す。
#
# ## 何をするか(細かいものはダミーか空にする)
#
#   ダミーに替える … 地点の教職員氏名(occupant_name)を「教員A001」のような連番に。地点のメモ(note)は空に
#   空にする       … 操作記録・問い合わせ・チャット・教職員の申請と割り当て・仮アカウントとリンク・
#                    利用者の設定と画像・在席・ランキングの利用者と検索語・試行回数・鍵(km_app_secrets。使うときに作り直される)・
#                    ファイル管理と配布物(実体の uploads/ は写していない)・表の管理の削除控え・タスク
#   書き換える     … FAQ の中のメールアドレスを contact@example.test に
#   残す           … 地点・線・階・平面図の範囲・イベント・FAQ・規約・Wi-Fi 学習・経路の重み・補正
#
# 無い表は飛ばす(控えの時期で表の揃いが違うため)。
# POSIX sh。**`[ … ] && cmd` を単独で書かない**(set -e の下で、偽のときにスクリプトごと終わる)。

set -eu

cd "$(dirname "$0")/.."

PROD_DOMAINS="ito4.jp ito8795.com"
MODE="apply"
case "${1:-}" in
  --check) MODE="check" ;;
  '') ;;
  -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
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
  echo "★ .env が KM_ENV=local ではありません。ローカル環境(host-local.sh init のあと)でだけ流します。" >&2
  exit 1
fi
_domain="$(env_value KM_DOMAIN)"
for _p in $PROD_DOMAINS; do
  case "$_domain" in
    "$_p"|*".$_p")
      echo "★ KM_DOMAIN が本番の名前($_domain)です。止めます。" >&2
      exit 1 ;;
  esac
done

# mariadb コンテナの中で、root で流す。**パスワードは引数ではなく MYSQL_PWD で**(host-backup.sh と同じ)
run_sql() {
  docker compose exec -T mariadb sh -c '
    set -e
    DB=$(printf %s "$MARIADB_DATABASE" | tr -d "\r\n ")
    MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
    export MYSQL_PWD
    exec mariadb -uroot --default-character-set=utf8mb4 -N -B "$DB"
  '
}

EMAIL_RE='[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}'

# 空にする表(個人の情報・操作の記録・鍵・実体を写していないファイルの台帳)
WIPE_TABLES="km_admin_log km_form_submissions km_chat_messages km_chat_reads
km_staff_requests km_staff_node_assignments km_staff_node_edits
km_map_guest_links km_map_guest_accounts km_guest_unlock_attempts
km_user_profiles km_app_settings km_app_presence km_user_stats_cache
km_map_ranking_users km_map_ranking_queries km_map_ranking_query_sources km_map_ranking_hidden_queries
km_map_unlock_attempts km_app_unlock_attempts km_app_secrets
km_files km_distributables km_table_manage_deleted km_tasks"

# ---------------------------------------------------------------- 数える(--check と、抜いたあと)
count_left() {
  _in=""
  for _t in $WIPE_TABLES; do
    _in="$_in${_in:+,}'$_t'"
  done
  run_sql <<SQL
SELECT CONCAT('  空にする表で行が残っているもの: ', COALESCE(GROUP_CONCAT(CONCAT(table_name, '=', table_rows)), 'なし'))
  FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name IN ($_in) AND table_rows > 0;
SELECT CONCAT('  地点の氏名(ダミー以外): ', COUNT(*)) FROM km_map_nodes
 WHERE COALESCE(occupant_name, '') <> '' AND occupant_name NOT REGEXP '^教員[A-Z][0-9]{3}\$';
SELECT CONCAT('  地点のメモ: ', COUNT(*)) FROM km_map_nodes WHERE COALESCE(note, '') <> '';
SELECT CONCAT('  FAQ のメールアドレス(ダミー以外): ', COUNT(*)) FROM km_faq
 WHERE REPLACE(question, 'contact@example.test', '') REGEXP '$EMAIL_RE'
    OR REPLACE(answer, 'contact@example.test', '') REGEXP '$EMAIL_RE';
SQL
}

if [ "$MODE" = "check" ]; then
  echo "== 残っていないか(何も変えない)"
  count_left
  exit 0
fi

# ---------------------------------------------------------------- 抜く
echo "== 1. 空にする表"
_present="$(run_sql <<'SQL'
SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE();
SQL
)"
_wipe=""
for _t in $WIPE_TABLES; do
  if printf '%s\n' "$_present" | grep -qx "$_t"; then
    _wipe="$_wipe TRUNCATE TABLE \`$_t\`;"
    printf '  空に   %s\n' "$_t"
  fi
done
if [ -n "$_wipe" ]; then
  printf 'SET FOREIGN_KEY_CHECKS=0;%s SET FOREIGN_KEY_CHECKS=1;\n' "$_wipe" | run_sql
fi

echo "== 2. 地点の氏名をダミーに・メモを空に"
# 並びは id で固定する(流し直しても同じ地点は同じダミー名になる)。A001〜Z999 まで
run_sql <<'SQL'
SET @n := 0;
UPDATE km_map_nodes
   SET occupant_name = CONCAT('教員', CHAR(65 + FLOOR((@n := @n + 1) / 1000) % 26), LPAD(@n % 1000, 3, '0'))
 WHERE COALESCE(occupant_name, '') <> ''
 ORDER BY id;
UPDATE km_map_nodes SET note = NULL WHERE COALESCE(note, '') <> '';
SQL

echo "== 3. FAQ のメールアドレス"
run_sql <<SQL
UPDATE km_faq
   SET question = REGEXP_REPLACE(question, '$EMAIL_RE', 'contact@example.test'),
       answer   = REGEXP_REPLACE(answer,   '$EMAIL_RE', 'contact@example.test')
 WHERE REPLACE(question, 'contact@example.test', '') REGEXP '$EMAIL_RE'
    OR REPLACE(answer, 'contact@example.test', '') REGEXP '$EMAIL_RE';
SQL

echo "== 4. 残っていないか"
count_left
echo
echo "抜きました。氏名の一覧との突き合わせは、手元から(docs/13 の「機密なしのコピー」)。"
