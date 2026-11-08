#!/bin/sh
#
# 検証機(機密なしのコピー)の MariaDB から、機密と個人の情報を抜いてダミーにする。**本番では止まる。**
#
#   sh scripts/local-sanitize.sh                      抜く(何度流してもよい)
#   sh scripts/local-sanitize.sh --check              残っていないかを数えるだけ(何も変えない。残っていれば終了コード 1)
#   sh scripts/local-sanitize.sh --container NAME     compose の mariadb ではなく、使い捨ての MariaDB(local-import.sh が立てる)に
#
# ## なぜ要るのか(2026-10-05 → 2026-11-07 に台帳で作り直した)
#
# 検証機に「機密なしのコピー」を置くため。控えから戻すのは **MariaDB だけ**
# (.env・config/*.local.php・Logto・uploads/ は持ち込まず、秘密は host-local.sh init --fresh-secrets で新しく作る)。
# 10-05 の版は空にする表を 25 個並べていたが、11-07 に本番の 99 表と突き合わせると、利用者の識別子や氏名を持つ表が
# 漏れていた(試験モードの記録・案内チャットの記録・地図の控えを作った人・英語名と読み…)。そこで:
#
#   - **全部の表を台帳(scripts/local-sanitize.tables)で keep / scrub / wipe に分ける。台帳に無い表は空にして警告**
#   - **列の名前で拾う決まり**を全部の残す表に掛ける(人の識別子・名前・投票のハッシュ → dummy-…)
#   - 文字の列の中のメールアドレス → contact@example.test、本番の名前 → 検証機の名前
#
# ## 中身の作り方(誤って読んでも分からない・見ればダミーと分かる)
#
#   地点        … 教職員氏名 →「教員A001」、メモ → 空、部屋・施設の名前 →「教員室 D012」・副題 →「D-012」
#   英語名・読み … ダミーにした部屋・施設・ポスターの分を「Room D012」に・読みと別名は消す(読みから氏名が分かる)
#   ポスター    … 題 →「ポスター P001」、説明・リンク → 空(画像は local-dummy-files.php がダミーを作る)
#   予定        … 主催 →「主催 001」、説明 →「(ダミーの説明)」。題は残す
#   設定        … 試験モードの PIN → 未設定・案内 AI の利用者 → 空・アンケートの URL → https://example.test/survey
#   人の列      … 塩(検証機の local-state/sanitize-salt。本番には無い)で混ぜたハッシュ。同じ人は同じダミーになる(数や並びは本番どおり)
#   タスク      … 空にしてダミーを 3 件
#
# 無い表は飛ばす(控えの時期で表の揃いが違うため)。
# POSIX sh。**`[ … ] && cmd` を単独で書かない**(set -e の下で、偽のときにスクリプトごと終わる)。

set -eu

cd "$(dirname "$0")/.."

PROD_DOMAINS="ito4.jp ito8795.com"
MARKER=/etc/kosenmap-test-host
LEDGER=scripts/local-sanitize.tables
MODE="apply"
CONTAINER=""
while [ $# -gt 0 ]; do
  case "$1" in
    --check) MODE="check"; shift ;;
    --container) CONTAINER="$2"; shift 2 ;;
    -h|--help) sed -n '2,33p' "$0"; exit 0 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

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
DOMAIN="$(env_value KM_DOMAIN)"
for _p in $PROD_DOMAINS; do
  case "$DOMAIN" in
    "$_p"|*".$_p")
      echo "★ KM_DOMAIN が本番の名前($DOMAIN)です。止めます。" >&2
      exit 1 ;;
  esac
done
if ! printf '%s' "$DOMAIN" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'; then
  echo "★ KM_DOMAIN が名前の形ではありません: $DOMAIN" >&2
  exit 1
fi
if [ ! -f "$MARKER" ]; then
  echo "★ $MARKER がありません。検証機(testhost-vm.ps1 で作った VM)でだけ流します。" >&2
  exit 1
fi
if [ ! -f "$LEDGER" ]; then
  echo "★ $LEDGER がありません(配備し直す)。" >&2
  exit 1
fi

# MariaDB の中で root で流す。**パスワードは引数ではなく MYSQL_PWD で**(host-backup.sh と同じ)
run_sql() {
  if [ -n "$CONTAINER" ]; then
    docker exec -i "$CONTAINER" sh -c '
      set -e
      MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
      export MYSQL_PWD
      exec mariadb -uroot --default-character-set=utf8mb4 -N -B "$MARIADB_DATABASE"
    '
  else
    docker compose exec -T mariadb sh -c '
      set -e
      DB=$(printf %s "$MARIADB_DATABASE" | tr -d "\r\n ")
      MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
      export MYSQL_PWD
      exec mariadb -uroot --default-character-set=utf8mb4 -N -B "$DB"
    '
  fi
}

# 塩。**検証機にだけ置く**(本番の値と突き合わせて元に戻せないように)。同じ塩なら同じ人は同じダミーになる
# 置き場は local-state/(検証機だけの、置き場の持ち主の 700)。run/ は本番と同じく root の持ち物で書けない(2026-11-08)
SALT_FILE=local-state/sanitize-salt
if [ ! -s "$SALT_FILE" ]; then
  ( umask 077 && mkdir -p local-state )
  ( umask 077 && od -An -N24 -tx1 /dev/urandom | tr -d ' \n' > "$SALT_FILE" )
fi
SALT="$(tr -cd '0-9a-f' < "$SALT_FILE")"

# メールアドレス(バックスラッシュを使わない形。生成した SQL の中でも崩れない)
EMAIL_RE='[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+[.][A-Za-z]{2,}'
# 人の識別子・名前の列(表に関係なく、列の名前で拾う)
PERSON_COLS='^(sub|user_id|user_sub|owner_sub|actor_sub|owner_name|user_name|actor_name|[a-z]+_by)$'
HASH_COLS='^(voter_hash|source_hash|rate_hash)$'

# ---------------------------------------------------------------- 台帳
ledger() {
  # $1 = keep|scrub|wipe(空なら全部の名前)
  sed 's/#.*//' "$LEDGER" | awk -v want="$1" 'NF >= 2 && (want == "" || $2 == want) { print $1 }'
}
_bad_kind="$(sed 's/#.*//' "$LEDGER" | awk 'NF >= 2 && $2 != "keep" && $2 != "scrub" && $2 != "wipe" { print $1 }')"
if [ -n "$_bad_kind" ]; then
  echo "★ 台帳に keep / scrub / wipe 以外があります: $_bad_kind" >&2
  exit 1
fi
sql_list() {
  _l=""
  for _t in $1; do
    _l="$_l${_l:+,}'$_t'"
  done
  printf '%s' "${_l:-''}"
}
PRESENT="$(printf 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY 1;\n' | run_sql)"
present() { printf '%s\n' "$PRESENT" | grep -qx "$1"; }
UNKNOWN=""
for _t in $PRESENT; do
  if ! ledger "" | grep -qx "$_t"; then
    UNKNOWN="$UNKNOWN $_t"
  fi
done
WIPE="$(ledger wipe) $UNKNOWN"
KEEP_LIST="$(sql_list "$(ledger keep) $(ledger scrub)")"
WIPE_LIST="$(sql_list "$WIPE")"

# ---------------------------------------------------------------- 数える(--check と、抜いたあと)
PROBLEMS=0
count_line() {
  # 標準入力の「名前<TAB>数」を出し、数が 0 でなければ ★
  while IFS="$(printf '\t')" read -r _label _n; do
    if [ -z "$_label" ]; then
      continue
    fi
    if [ "${_n:-0}" = "0" ]; then
      printf '  OK   %s: 0\n' "$_label"
    else
      printf '  ★    %s: %s\n' "$_label" "$_n"
      PROBLEMS=$((PROBLEMS + 1))
    fi
  done
}

count_left() {
  PROBLEMS=0
  if [ -n "$UNKNOWN" ]; then
    printf '  ★    台帳に無い表:%s(空にした。台帳に足すこと)\n' "$UNKNOWN"
  fi
  # 空にする表で行が残っているもの(table_rows は見積もりなので数え直す)
  _sql=""
  for _t in $WIPE; do
    if present "$_t"; then
      _sql="$_sql SELECT '$_t', COUNT(*) FROM \`$_t\`;"
    fi
  done
  # **パイプの先で count_line を呼ばない**(sh ではパイプの先が子のシェルになり、PROBLEMS の数が消える)。いったんファイルへ
  _tmp="$(mktemp)"
  printf '%s\n' "$_sql" | run_sql \
    | awk -F '\t' '$2 != 0 { s += $2; n = n " " $1 } END { printf "空にする表で残っている行%s\t%d\n", n, s }' > "$_tmp"
  {
    run_sql <<SQL
SELECT '地点の氏名(ダミー以外)', COUNT(*) FROM km_map_nodes
 WHERE COALESCE(occupant_name, '') <> '' AND occupant_name NOT REGEXP '^教員[A-Z][0-9]{3}\$';
SELECT '地点のメモ', COUNT(*) FROM km_map_nodes WHERE COALESCE(note, '') <> '';
SELECT '部屋・施設の名前(ダミー以外)', COUNT(*) FROM km_map_nodes
 WHERE type IN ('room', 'facility') AND (COALESCE(title, '') NOT REGEXP ' D[0-9]{3}\$' OR COALESCE(name, '') <> COALESCE(title, ''));
SQL
    if present km_posters; then
      printf "SELECT 'ポスターの題(ダミー以外)', COUNT(*) FROM km_posters WHERE title NOT REGEXP '^ポスター P[0-9]{3}\$' OR COALESCE(description, '') <> '' OR COALESCE(link_url, '') <> '';\n" | run_sql
    fi
    if present km_settings; then
      printf "SELECT '設定の秘密(PIN・案内 AI の利用者・外の URL)', COUNT(*) FROM km_settings WHERE (name IN ('test_mode_signout_pin_hash', 'guide_chat_sub', 'tutorial_end_phone_qr') AND value <> '') OR (name IN ('test_mode_survey_url', 'tutorial_end_survey_url') AND value NOT IN ('', 'https://example.test/survey'));\n" | run_sql
    fi
    # 列の名前で拾うもの・メール・本番の名前は、残す表の全部の文字の列を数える
    run_sql <<SQL | run_sql | awk -F '\t' '{ s[$1] += $2 } END { for (k in s) printf "%s\t%d\n", k, s[k] }' | sort
SELECT CONCAT('SELECT ''人の列(ダミー以外)'', COUNT(*) FROM \`', table_name, '\` WHERE \`', column_name, '\` IS NOT NULL AND \`', column_name, '\` <> '''' AND \`', column_name, '\` NOT LIKE ''dummy-%'' AND \`', column_name, '\` NOT LIKE ''ダミー利用者 %'';')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name IN ($KEEP_LIST)
   AND data_type IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext')
   AND column_name REGEXP '$PERSON_COLS';
SELECT CONCAT('SELECT ''メールアドレス(example.test 以外)'', COUNT(*) FROM \`', table_name, '\` WHERE REPLACE(\`', column_name, '\`, ''contact@example.test'', '''') REGEXP ''$EMAIL_RE'';')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name IN ($KEEP_LIST)
   AND data_type IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext');
SELECT CONCAT('SELECT ''本番の名前(ito4.jp・ito8795.com)'', COUNT(*) FROM \`', table_name, '\` WHERE \`', column_name, '\` LIKE ''%ito4.jp%'' OR \`', column_name, '\` LIKE ''%ito8795.com%'';')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name IN ($KEEP_LIST)
   AND data_type IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext');
SQL
  } >> "$_tmp"
  count_line < "$_tmp"
  rm -f "$_tmp"
  return 0
}

if [ "$MODE" = "check" ]; then
  echo "== 残っていないか(何も変えない)"
  count_left
  if [ "$PROBLEMS" -gt 0 ] || [ -n "$UNKNOWN" ]; then
    printf '\n★ %s 件\n' "$PROBLEMS"
    exit 1
  fi
  echo
  echo "残っていません。"
  exit 0
fi

# ---------------------------------------------------------------- 抜く
echo "== 1. 空にする表"
if [ -n "$UNKNOWN" ]; then
  printf '  ★ 台帳に無い表を空にします:%s(scripts/local-sanitize.tables に足すこと)\n' "$UNKNOWN"
fi
_wipe=""
for _t in $WIPE; do
  if present "$_t"; then
    _wipe="$_wipe TRUNCATE TABLE \`$_t\`;"
  fi
done
if [ -n "$_wipe" ]; then
  printf 'SET FOREIGN_KEY_CHECKS=0;%s SET FOREIGN_KEY_CHECKS=1;\n' "$_wipe" | run_sql
fi
printf '  空にした表: %s 個\n' "$(printf '%s' "$_wipe" | grep -o TRUNCATE | wc -l | tr -d ' ')"

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

echo "== 2-2. 部屋・施設の名前をダミーに"
# 「教員室 D012」のように、分類(type2)+連番にする(利用者の指示「部屋名などもダミーに」)。副題(部屋番号など)は「D-012」。
# 道・階段・出入り口は「1号棟 階段」のような一般の名前なので残す
run_sql <<'SQL'
SET @n := 0;
UPDATE km_map_nodes
   SET name = CONCAT(COALESCE(NULLIF(type2, ''), IF(type = 'facility', '施設', '部屋')), ' D', LPAD(@n := @n + 1, 3, '0')),
       title = name,
       subtitle = IF(COALESCE(subtitle, '') = '', subtitle, CONCAT('D-', LPAD(@n, 3, '0')))
 WHERE type IN ('room', 'facility')
 ORDER BY id;
SQL

echo "== 2-3. 英語名・読み・別名(ダミーにした部屋・施設・ポスターの分)"
# 対象の ID は地点の uuid から - を抜いた 32 桁(lib/search-aliases.php の km_search_alias_target_valid)
if present km_english_names; then
  run_sql <<'SQL'
UPDATE km_english_names e
  JOIN km_map_nodes n ON e.target_kind = 'node' AND e.target_id = REPLACE(LOWER(n.uuid), '-', '')
   SET e.name = CONCAT(IF(n.type = 'facility', 'Facility ', 'Room '), SUBSTRING_INDEX(n.name, ' ', -1))
 WHERE n.type IN ('room', 'facility');
UPDATE km_english_names SET name = CONCAT('Poster P', LPAD(target_id, 3, '0')) WHERE target_kind = 'poster';
SQL
fi
if present km_search_aliases; then
  run_sql <<'SQL'
DELETE a FROM km_search_aliases a
  JOIN km_map_nodes n ON a.target_kind = 'node' AND a.target_id = REPLACE(LOWER(n.uuid), '-', '')
 WHERE n.type IN ('room', 'facility');
DELETE FROM km_search_aliases WHERE target_kind = 'poster';
SQL
fi

echo "== 2-4. ポスター・予定・設定"
if present km_posters; then
  run_sql <<'SQL'
UPDATE km_posters
   SET title = CONCAT('ポスター P', LPAD(id, 3, '0')), description = '', link_url = '', image_source_url = '';
SQL
fi
if present km_event_schedule; then
  run_sql <<'SQL'
UPDATE km_event_schedule
   SET organizer   = IF(COALESCE(organizer, '') = '', organizer, CONCAT('主催 ', LPAD(id, 3, '0'))),
       description = IF(COALESCE(description, '') = '', description, '(ダミーの説明)');
SQL
fi
if present km_settings; then
  run_sql <<'SQL'
UPDATE km_settings SET value = '' WHERE name IN ('test_mode_signout_pin_hash', 'guide_chat_sub', 'tutorial_end_phone_qr');
UPDATE km_settings SET value = 'https://example.test/survey'
 WHERE name IN ('test_mode_survey_url', 'tutorial_end_survey_url') AND value <> '';
SQL
fi

echo "== 3. 人の列・メールアドレス・本番の名前(残す表の全部)"
# 生成した UPDATE をそのまま流す。塩はセッションの変数で渡す(生成した文に値を書かない)
run_sql <<SQL | { printf "SET @salt := '%s';\n" "$SALT"; cat; } | run_sql
SELECT CONCAT('UPDATE \`', table_name, '\` SET \`', column_name, '\` = ',
         IF(column_name REGEXP '_name\$',
            CONCAT('CONCAT(''ダミー利用者 '', LEFT(SHA2(CONCAT(@salt, \`', column_name, '\`), 256), 4))'),
            CONCAT('LEFT(CONCAT(''dummy-'', SHA2(CONCAT(@salt, \`', column_name, '\`), 256)), ', LEAST(COALESCE(character_maximum_length, 70), 70), ')')),
         ' WHERE \`', column_name, '\` IS NOT NULL AND \`', column_name, '\` <> '''' AND \`', column_name, '\` NOT LIKE ''dummy-%'' AND \`', column_name, '\` NOT LIKE ''ダミー利用者 %'';')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name IN ($KEEP_LIST)
   AND data_type IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext')
   AND column_name REGEXP '$PERSON_COLS';
SELECT CONCAT('UPDATE \`', table_name, '\` SET \`', column_name, '\` = LEFT(SHA2(CONCAT(@salt, \`', column_name, '\`), 256), ', COALESCE(character_maximum_length, 64), ') WHERE \`', column_name, '\` IS NOT NULL AND \`', column_name, '\` <> '''';')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name IN ($KEEP_LIST)
   AND data_type IN ('char', 'varchar')
   AND column_name REGEXP '$HASH_COLS';
SELECT CONCAT('UPDATE \`', table_name, '\` SET \`', column_name, '\` = REGEXP_REPLACE(\`', column_name, '\`, ''$EMAIL_RE'', ''contact@example.test'') WHERE REPLACE(\`', column_name, '\`, ''contact@example.test'', '''') REGEXP ''$EMAIL_RE'';')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name IN ($KEEP_LIST)
   AND data_type IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext');
SELECT CONCAT('UPDATE \`', table_name, '\` SET \`', column_name, '\` = REPLACE(REPLACE(\`', column_name, '\`, ''ito8795.com'', ''$DOMAIN''), ''ito4.jp'', ''$DOMAIN'') WHERE \`', column_name, '\` LIKE ''%ito4.jp%'' OR \`', column_name, '\` LIKE ''%ito8795.com%'';')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name IN ($KEEP_LIST)
   AND data_type IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext');
SQL

echo "== 4. プロジェクト状況にダミーを 3 件"
if present km_tasks; then
  run_sql <<'SQL'
INSERT INTO km_tasks (title, status, progress, topic, sort_order, created_at, updated_at) VALUES
  ('ダミーのタスク(検証機)1', 'todo', 0, '', 1, NOW(), NOW()),
  ('ダミーのタスク(検証機)2', 'progress', 50, '', 2, NOW(), NOW()),
  ('ダミーのタスク(検証機)3', 'decision', 10, '', 3, NOW(), NOW());
SQL
fi

echo "== 5. 残っていないか"
count_left
echo
if [ "$PROBLEMS" -gt 0 ]; then
  printf '★ %s 件残っています(上の ★)。web を起動する前に直す。\n' "$PROBLEMS"
  exit 1
fi
echo "抜きました。氏名の一覧との突き合わせは、手元から(docs/13 の「機密なしのコピー」)。"
