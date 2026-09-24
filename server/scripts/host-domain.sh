#!/bin/sh
#
# ドメインを変える。**.env の KM_DOMAIN を書き換えるだけ。立ち上げ直しは人が行う。**
#
#   ./host-domain.sh check NEW          何が変わるかを見る(何も変えない)
#   ./host-domain.sh apply NEW          .env を控えてから書き換える(docker compose up はしない)
#   ./host-domain.sh check NEW --path /opt/kosenmap
#   ./host-domain.sh apply NEW --api-resource https://NEW/api   audience も一緒に移す(先に Logto に登録する)
#
# 順番(取扱説明書 09-new-host の「ドメインを変える」):
#   1. DNS の A レコードを NEW → このホストへ
#   2. ./host-cert.sh issue --domain NEW [--also 管理用の名前] --email <連絡先>
#   3. ./host-domain.sh check NEW   →   ./host-domain.sh apply NEW
#   4. apply が最後に出す「次にやること」を上から
#
# ## なぜ KM_DOMAIN だけなのか
#
# compose.vps.yaml が KM_DOMAIN から URL 系の値を導く
# (KM_APP_URL / APP_URL / LOGTO_ENDPOINT / KM_CERT / MAIL_FROM …)。
# ところが本番の .env には URL 系が**全部明示で入っている**(2026-09-14 に実測)。
# **明示の行は導出より勝つ**ので、KM_DOMAIN だけ変えると旧ドメインの行が残り、
# 半分だけ移った状態になる —— 公開ページは動くのにサインインだけ旧ドメインへ飛ぶ、など。
# apply は旧ドメインを含む URL 系の行を**消さずにコメントにして**、導出に任せる。
#
# ## audience(KM_API_RESOURCE)
#
# **既定では変えない。** 変えると、Logto の登録と配布済みのアプリのトークンが合わなくなる。
# 行が無ければ、いま使っている値を書いて固定する。
# **一緒に移すときだけ** --api-resource で新しい値を渡す(2026-09-17 の ito8795.com → ito4.jp)。
# そのときは先に src/scripts/logto-api-resource.php で Logto に同じ値のリソースを作っておくこと。
#
# ## 書き換える前に止まるもの
#
#   - 新しいドメインの証明書が無い … 書き換えてから気づくと、次の up で nginx が起動できない。
#                                    HSTS を有効にしてあるので、**誰もサイトに入れなくなる**
#   - compose が KM_DOMAIN から導いていない … 配備が古いと、コメントにした行が
#                                    **校内 LAN の既定値(192.168.3.29)へ落ちる**
#
# 手順の中の検査は全部「書き換えた .env の写し」で行う。**元の .env は最後の1回しか触らない。**
#
# POSIX sh。**`[ … ] && cmd` を単独で書かない**(set -e の下で、偽のときにスクリプトごと終わる)。

set -eu

# .env の写しと控えは秘密そのもの。**他人に読ませない**
umask 077

DEFAULT_ARGS=""

PATH_ROOT="/opt/kosenmap"
CMD=""
NEW=""
API_NEW=""

# 旧ドメインを含んでいたら**コメントにして導出に任せる**行。
# compose.vps.yaml が KM_DOMAIN から既定を作るものだけを並べる(それ以外は手で確かめる)
URL_KEYS="KM_APP_URL KM_CERT KM_CERT_KEY APP_URL LOGTO_ENDPOINT LOGTO_ADMIN_ENDPOINT PMA_ABSOLUTE_URI MAIL_FROM"

# compose の出力から見せるキー。**値に秘密を持たないものだけ**
PREVIEW_KEYS='KM_DOMAIN|KM_APP_URL|KM_CERT|KM_CERT_KEY|APP_URL|ENDPOINT|ADMIN_ENDPOINT|LOGTO_ENDPOINT|LOGTO_ADMIN_ENDPOINT|PMA_ABSOLUTE_URI|MAIL_FROM|ALLOWED_SENDER_DOMAINS|POSTFIX_myhostname|LOGTO_API_RESOURCE|KOSENMAP_LOGTO_AUDIENCE'

if [ -n "$DEFAULT_ARGS" ]; then
  # shellcheck disable=SC2086
  set -- $DEFAULT_ARGS "$@"
fi

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --api-resource) API_NEW="$2"; shift 2 ;;
    check|apply) CMD="$1"; shift ;;
    -h|--help) sed -n '2,42p' "$0"; exit 0 ;;
    -*) echo "知らない引数: $1" >&2; exit 2 ;;
    *)
      if [ -n "$NEW" ]; then
        echo "ドメインは1つだけ指定してください: $1" >&2
        exit 2
      fi
      NEW="$1"
      shift
      ;;
  esac
done

if [ -z "$CMD" ] || [ -z "$NEW" ]; then
  echo "使い方: host-domain.sh check|apply NEW(--help で説明)" >&2
  exit 2
fi

# 大文字と末尾の点は DNS では同じ名前。**証明書の置き場の名前と揃えるため小文字にする**
NEW="$(printf '%s' "$NEW" | tr 'A-Z' 'a-z' | sed 's/\.$//')"
if ! printf '%s' "$NEW" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'; then
  echo "ドメインの形ではありません: $NEW(https:// やポートは付けない)" >&2
  exit 2
fi

if ! cd "$PATH_ROOT"; then
  echo "$PATH_ROOT へ移動できません。--path を確かめてください。" >&2
  exit 1
fi

# **読めるかまで見る。** 読めないまま進むと、下の KM_DOMAIN が空に見えて
# 「VPS の構成ではない」と見当違いの案内をしてしまう(root の 600 を km で読んだとき)
if [ ! -r .env ]; then
  echo "$PATH_ROOT/.env が無いか、読めません(持ち主か sudo を確かめてください)。" >&2
  exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "docker が見つかりません。" >&2
  exit 1
fi

# .env から1つだけ読む。**.env を丸ごと source しない**(秘密を環境へ撒かない。host-cert.sh と同じ)
env_value() {
  sed -n "s/^$1=//p" .env 2>/dev/null | tail -n 1 | tr -d '\r' | sed "s/^\"\\(.*\\)\"\$/\\1/; s/^'\\(.*\\)'\$/\\1/"
}

OLD="$(env_value KM_DOMAIN | tr 'A-Z' 'a-z' | sed 's/\.$//')"
STAMP="$(date +%Y%m%d-%H%M%S)"
BLOCK=0

block() {
  echo "★ $1"
  BLOCK=$((BLOCK + 1))
}

if [ -z "$OLD" ]; then
  cat >&2 <<MSG
.env に KM_DOMAIN がありません。

このスクリプトは **VPS(compose.yaml + compose.vps.yaml)で、あるドメインから別のドメインへ移す**ためのもの。
校内 LAN 構成(compose.yaml 単体)から移すときは、取扱説明書 09-new-host の手順で .env を作ってください。
MSG
  exit 2
fi

if [ "$OLD" = "$NEW" ]; then
  echo "KM_DOMAIN はすでに $NEW です。変えるものはありません。"
  echo "  証明書と応答の確認: ./scripts/host-cert.sh status"
  exit 0
fi

# ---------------------------------------------------------------------------
# audience(KM_API_RESOURCE)
# ---------------------------------------------------------------------------
# **いま実際に使っている値を固定する。** 動いている web に聞けるならその値、
# 聞けなければ compose の既定と同じ https://<旧>/api(アプリの既定もこれ)。
API_CURRENT="$(env_value KM_API_RESOURCE)"
API_RESOURCE_WRITE=""
API_SOURCE=""
API_FORCE=0
if [ -n "$API_NEW" ]; then
  # 空白・引用符・| を含む値は .env と awk を壊すので受け付けない
  if ! printf '%s' "$API_NEW" | grep -Eq '^https://[A-Za-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._~/-]*)?$'; then
    echo "--api-resource は https:// で始まる URL の形にしてください: $API_NEW" >&2
    exit 2
  fi
  API_RESOURCE_WRITE="$API_NEW"
  API_SOURCE="--api-resource で指定"
  API_FORCE=1
elif [ -z "$API_CURRENT" ]; then
  _running="$(timeout 20 docker compose exec -T web printenv KOSENMAP_LOGTO_AUDIENCE </dev/null 2>/dev/null | tr -d '\r' | head -n 1 || true)"
  case "$_running" in
    https://*)
      API_RESOURCE_WRITE="$_running"
      API_SOURCE="動いている web コンテナの値"
      ;;
    *)
      API_RESOURCE_WRITE="https://$OLD/api"
      API_SOURCE="旧ドメインからの既定"
      ;;
  esac
fi

# ---------------------------------------------------------------------------
# 書き換えた .env を作る。**元の .env には触れない**(check と apply で同じものを使う)
# ---------------------------------------------------------------------------
build_env() {
  awk -v old="$OLD" -v new="$NEW" -v keys=" $URL_KEYS " -v stamp="$STAMP" -v api="$API_RESOURCE_WRITE" -v apiforce="$API_FORCE" '
    {
      line = $0
      sub(/\r$/, "", line)
      if (match(line, /^[A-Za-z_][A-Za-z0-9_]*=/)) {
        key = substr(line, 1, RLENGTH - 1)
        val = substr(line, RLENGTH + 1)
        if (key == "KM_DOMAIN") {
          if (!done_domain) {
            print "KM_DOMAIN=" new
          }
          done_domain = 1
          next
        }
        # 空の行は引用符付き(KM_API_RESOURCE="")でも空として置き換える。末尾に足すと同じキーが2行になる
        if (key == "KM_API_RESOURCE" && api != "" && (apiforce == "1" || val == "" || val == "\"\"" || val == "\047\047")) {
          if (!done_api) {
            print "KM_API_RESOURCE=" api
          }
          done_api = 1
          next
        }
        if (index(keys, " " key " ") > 0 && index(tolower(val), old) > 0) {
          print "# host-domain.sh " stamp ": 旧ドメイン(" old ")の値。KM_DOMAIN からの導出に任せる"
          print "#" line
          next
        }
      }
      print $0
    }
    END {
      if (!done_domain) {
        print "KM_DOMAIN=" new
      }
      if (api != "" && !done_api) {
        print ""
        print "# host-domain.sh " stamp ": アクセストークンの audience。Logto の API リソースの登録と同じ値にする"
        print "KM_API_RESOURCE=" api
      }
    }
  ' .env > "$1"
}

# 旧ドメインを含む行の一覧。"url KEY=VALUE"(コメントにする)/ "other KEY"(値は出さない)
list_old_lines() {
  awk -v old="$OLD" -v keys=" $URL_KEYS " '
    {
      line = $0
      sub(/\r$/, "", line)
      if (!match(line, /^[A-Za-z_][A-Za-z0-9_]*=/)) {
        next
      }
      key = substr(line, 1, RLENGTH - 1)
      val = substr(line, RLENGTH + 1)
      if (key == "KM_DOMAIN" || key == "KM_API_RESOURCE" || index(tolower(val), old) == 0) {
        next
      }
      if (index(keys, " " key " ") > 0) {
        print "url " key "=" val
      } else {
        print "other " key
      }
    }
  ' .env
}

# compose が「その .env で」どう読むか。**値は見せてよいキーだけ出す**
show_derived() {
  if ! _cfg="$(docker compose --env-file "$1" config 2>/dev/null)"; then
    block "docker compose がこの内容の .env を読めません(エラー文には秘密の断片が出るので表示しません)"
    # **echo にバックスラッシュを渡さない**(dash の echo は解釈する。check.php の shell 節)
    printf '%s\n' '   確かめ方: docker compose --env-file <ファイル> config -q ; echo $?'
    return 0
  fi
  printf '%s\n' "$_cfg" | grep -E "^[[:space:]]+($PREVIEW_KEYS):" | sed 's/^[[:space:]]*/  /' | sort -u

  # **導出が効いているか。** 配備が古いと、コメントにした行が校内 LAN の既定値へ落ちる
  _app="$(printf '%s\n' "$_cfg" | grep -E '^[[:space:]]+APP_URL:' | head -n 1)"
  _logto="$(printf '%s\n' "$_cfg" | grep -E '^[[:space:]]+LOGTO_ENDPOINT:' | head -n 1)"
  # **ホスト名として一致を見る。** 部分一致だと、新しい名前が旧の一部(kosen.ito8795.com → ito8795.com)の
  # ときに旧の値のままでも通ってしまう。ホスト名の後ろは ポート・パス・引用符・行末のどれか
  _new_re="$(printf '%s' "$2" | sed 's/\./\\./g')"
  if printf '%s' "$_app" | grep -Eqi "https://${_new_re}([:/\"']|\$)" \
    && printf '%s' "$_logto" | grep -Eqi "https://${_new_re}([:/\"']|\$)"; then
    echo "  → APP_URL と LOGTO_ENDPOINT が $2 を指しています"
  else
    block "APP_URL / LOGTO_ENDPOINT が $2 を指していません。compose.vps.yaml が KM_DOMAIN から導く版か確かめてください(先に配備)"
  fi

  # 旧ドメインが残っている場所。**キー名だけ出す**(audience は残って正しい)
  _left="$(printf '%s\n' "$_cfg" | grep -iF "$OLD" \
    | grep -vE '^[[:space:]]+(LOGTO_API_RESOURCE|KOSENMAP_LOGTO_AUDIENCE|KM_API_RESOURCE|ALLOWED_SENDER_DOMAINS):' \
    | sed -n 's/^[[:space:]-]*\([A-Za-z0-9_.-]*\)[:=].*/\1/p' | sort -u | tr '\n' ' ')"
  if [ -n "$_left" ]; then
    echo "  注意: 旧ドメイン($OLD)がまだ残っているキー: $_left"
  fi
  return 0
}

cert_ready() {
  _path="/etc/letsencrypt/live/$1/fullchain.pem"
  if docker compose ps --status running --services 2>/dev/null | grep -qx certbot; then
    docker compose exec -T certbot test -f "$_path" </dev/null >/dev/null 2>&1
  else
    docker compose run --rm --no-deps -T --entrypoint test certbot -f "$_path" </dev/null >/dev/null 2>&1
  fi
}

# 証明書の名前(SAN)を 1 行 1 つで出す
cert_names() {
  _path="/etc/letsencrypt/live/$1/fullchain.pem"
  docker compose run --rm --no-deps -T --entrypoint openssl certbot x509 -noout -ext subjectAltName -in "$_path" </dev/null 2>/dev/null \
    | tr ',' '\n' | sed -n 's/^[[:space:]]*DNS://p' | tr 'A-Z' 'a-z'
}

# DKIM の鍵(mail_dkim ボリューム)。mailserver の像で見る(ボリュームの実体名を compose に任せる)
dkim_run() {
  docker compose run --rm --no-deps -T --entrypoint sh mailserver -c "$1" </dev/null 2>/dev/null
}

# ---------------------------------------------------------------------------
# 調べる(check も apply も同じ)
# ---------------------------------------------------------------------------
echo "ドメインを変えます: $OLD → $NEW(${CMD})"

echo ""
echo "== 1. 構成"
_compose_file="$(env_value COMPOSE_FILE)"
case "$_compose_file" in
  *compose.vps.yaml*) echo "  COMPOSE_FILE=$_compose_file" ;;
  *) block "COMPOSE_FILE に compose.vps.yaml がありません(${_compose_file:-未設定})。導出は VPS の構成にしかありません" ;;
esac

echo ""
echo "== 2. .env の書き換え"
echo "  KM_DOMAIN=$OLD → $NEW"
_others=""
_lines="$(list_old_lines)"
if [ -n "$_lines" ]; then
  while IFS= read -r _l; do
    case "$_l" in
      url\ *) echo "  コメントにして導出に任せる: ${_l#url }" ;;
      other\ *) _others="$_others ${_l#other }" ;;
    esac
  done <<EOF
$_lines
EOF
fi
if [ "$API_FORCE" = "1" ]; then
  echo "  KM_API_RESOURCE=${API_CURRENT:-(未設定)} → $API_RESOURCE_WRITE(--api-resource。**Logto に同じ値のリソースがあること**)"
elif [ -n "$API_CURRENT" ]; then
  echo "  KM_API_RESOURCE はそのまま: $API_CURRENT"
else
  echo "  KM_API_RESOURCE=$API_RESOURCE_WRITE を書き足す(${API_SOURCE}。ドメインを変えても変えない)"
fi
if [ -n "$_others" ]; then
  echo "  手で確かめる(旧ドメインを含むが、導出の対象ではない。値は表示しません):$_others"
fi

TMP_ENV="$(mktemp "$PATH_ROOT/.env.host-domain.XXXXXX")"
# **INT と TERM では抜ける。** 後始末だけを INT に掛けると、sh は後始末のあと**続きを実行する** ——
# Ctrl+C で写しを消したまま apply の書き換えまで進み、`cat 写し > .env` が .env を空にする
trap 'rm -f "$TMP_ENV"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
build_env "$TMP_ENV"

echo ""
echo "== 3. 書き換えたあと compose が読む値"
show_derived "$TMP_ENV" "$NEW"

echo ""
echo "== 4. 新しいドメインの証明書"
_km_cert="$(env_value KM_CERT)"
if [ -n "$_km_cert" ] && ! printf '%s' "$_km_cert" | grep -qiF "$OLD"; then
  echo "  KM_CERT は独自の値のままです($_km_cert)。その証明書が $NEW を含むか、自分で確かめてください。"
elif cert_ready "$NEW"; then
  echo "  /etc/letsencrypt/live/$NEW/ があります"
  # **管理用の名前も入っているか。** 入っていないと、管理画面を開いた時点でブラウザが証明書の不一致で止める
  _names="$(cert_names "$NEW")"
  _admin="$(env_value KM_ADMIN_DOMAIN | tr 'A-Z' 'a-z')"
  for _want in "$NEW" $_admin; do
    if printf '%s\n' "$_names" | grep -qxF "$_want"; then
      echo "  名前 $_want: 入っています"
    else
      block "証明書に $_want が入っていません。取り直してください:"
      echo "     ./scripts/host-cert.sh issue --domain $NEW${_admin:+ --also $_admin} --email <連絡先>"
    fi
  done
else
  block "/etc/letsencrypt/live/$NEW/ がありません。先に取ってください:"
  _admin="$(env_value KM_ADMIN_DOMAIN | tr 'A-Z' 'a-z')"
  echo "     ./scripts/host-cert.sh issue --domain $NEW${_admin:+ --also $_admin} --email <連絡先>"
fi

echo ""
echo "== 5. DNS(止めはしない。証明書を取れていれば通っているはず)"
_mine="$(hostname -I 2>/dev/null || true)"
_ips="$(getent ahostsv4 "$NEW" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ')"
_hit=0
for _ip in $_ips; do
  case " $_mine " in *" $_ip "*) _hit=1 ;; esac
done
if [ -z "$_ips" ]; then
  echo "  注意: $NEW の名前を引けません"
elif [ "$_hit" -eq 1 ]; then
  echo "  $NEW → $_ips(このホスト)"
else
  echo "  注意: $NEW → $_ips は、このホストの IP($_mine)ではありません"
fi

echo ""
echo "== 6. DKIM の鍵(メールの署名)"
# boky/postfix は ALLOWED_SENDER_DOMAINS の名前ごとに <名前>.private を探し、無ければ**新しく作る**。
# 新しい鍵は DNS に登録した公開鍵と合わず、署名の検証が全部 fail になる。旧の鍵を新しい名前で複製する
# (DNS の mail._domainkey.<新> には旧と同じ公開鍵を登録する)
DKIM_COPY=0
case "$(env_value COMPOSE_FILE)" in
  *compose.local.yaml*) _local_env=1 ;;
  *) _local_env=0 ;;
esac
if [ "$_local_env" = "0" ]; then
  _keys="$(dkim_run 'ls /etc/opendkim/keys' || true)"
  if printf '%s\n' "$_keys" | grep -qxF "$NEW.private"; then
    echo "  $NEW.private があります(複製しない。DNS の公開鍵がこの鍵と合うか確かめること)"
  elif printf '%s\n' "$_keys" | grep -qxF "$OLD.private"; then
    echo "  $OLD.private を $NEW.private として複製します(apply のとき。DNS には旧と同じ公開鍵を登録する)"
    DKIM_COPY=1
  else
    echo "  注意: 旧の鍵($OLD.private)がありません。up すると新しい鍵が作られるので、公開鍵を DNS に登録すること"
  fi
else
  echo "  ローカル環境(compose.local.yaml)は mailserver を使わないので見ません"
fi

if [ "$BLOCK" -gt 0 ]; then
  echo ""
  echo "止まる理由が $BLOCK 件あります(上の ★)。.env は変えていません。"
  exit 1
fi

if [ "$CMD" = "check" ]; then
  echo ""
  echo "書き換えられる状態です。書き換えるには: ./scripts/host-domain.sh apply $NEW"
  exit 0
fi

# ---------------------------------------------------------------------------
# apply
# ---------------------------------------------------------------------------
if [ ! -w .env ]; then
  echo "★ .env を書き換えられません(持ち主か sudo を確かめてください)。何も変えていません。" >&2
  exit 1
fi

# 写しが空なら書かない(作れなかった写しで .env を上書きしない)
if [ ! -s "$TMP_ENV" ]; then
  echo "★ 書き換えた写しが空です。何も変えていません。" >&2
  exit 1
fi

BAK=".env.bak-$STAMP"
echo ""
echo "== 6. 書き換え"
# **控えは持ち主も保ったまま 600 に。** 中身は .env と同じ秘密
cp -p .env "$BAK"
chmod 600 "$BAK"
echo "  控え: $PATH_ROOT/$BAK(600)"

# **`cat >` で書く。** mv で置き換えると .env の持ち主と権限が写しのもの(実行した人・600)に変わる
# **書き込みの失敗も戻す。** `>` は先に .env を空にするので、途中で落ちると(容量不足など)
# set -e で抜けたあと半端な .env だけが残る
if ! cat "$TMP_ENV" > .env || ! docker compose config -q >/dev/null 2>&1; then
  cat "$BAK" > .env
  echo "★ 書き換えた .env を書けないか、compose が読めませんでした。控えから戻しました(何も変わっていません)。" >&2
  exit 1
fi
echo "  .env を書き換えました(まだ立ち上げ直していません)"

if [ "$DKIM_COPY" = "1" ]; then
  # **持ち主と権限を保って複製する**(opendkim の 400)。既にあれば触らない(-n)
  if dkim_run "cd /etc/opendkim/keys && { [ -e '$NEW.private' ] || cp -p '$OLD.private' '$NEW.private'; } && { [ -e '$NEW.txt' ] || [ ! -e '$OLD.txt' ] || cp -p '$OLD.txt' '$NEW.txt'; } && [ -s '$NEW.private' ]"; then
    echo "  DKIM: $OLD.private を $NEW.private に複製しました"
  else
    echo "  ★ DKIM の鍵を複製できませんでした。up すると新しい鍵が作られます —— 先に手で複製してください:"
    echo "     docker compose run --rm --no-deps --entrypoint sh mailserver -c 'cd /etc/opendkim/keys && cp -p $OLD.private $NEW.private && cp -p $OLD.txt $NEW.txt'"
  fi
fi

cat <<EOF

次にやること(**このスクリプトは立ち上げ直しません**。上から順に):

  1. 立ち上げ直す(数十秒、サイトが途切れます)
       cd $PATH_ROOT && docker compose up -d

  2. 証明書と応答を確かめる
       ./scripts/host-cert.sh status

  3. Logto に登録してある戻り先(リダイレクト URI・サインアウト後・CORS・webhook)と、メールのコネクタの差出人を新しい名前へ
       docker compose exec -T -u www-data web php scripts/logto-domain.php --from=$OLD
       docker compose exec -T -u www-data web php scripts/logto-domain.php --from=$OLD --apply
     (1 本目は一覧だけ。書き換わるものを見てから 2 本目。**差出人が旧のままだと mailserver が拒み、確認コードが届かない**)

  4. メール(届かなくなるので早めに)
     - DNS: mail.$NEW の A、SPF(v=spf1 ip4:<このホストの IP> -all など)、DMARC(_dmarc.$NEW)
     - DKIM: 旧の鍵を複製したので、mail._domainkey.$NEW には**旧と同じ公開鍵**を登録する
         dig +short TXT mail._domainkey.$OLD
     - 3 のあと、Logto Console → コネクタ → メール(SMTP)でテスト送信し、SPF・DKIM・DMARC が PASS か見る
     - VPS の逆引き(PTR)を mail.$NEW に(VPS 事業者の管理画面。任意 —— 2026-08-29 は無しでも Gmail に届いた)

  5. reCAPTCHA: Google の管理画面で、許可するドメインに $NEW を足す

  6. Android: 両フレーバー(visitor / admin)を -Pkosenmap.domain=$NEW で作り直して配る
     (kosenmap.apiResource は .env の KM_API_RESOURCE と同じ値。違うとトークンが通らない)

  7. 旧ドメインの証明書(live/$OLD): もう使わない。新しい名前で動くのを確かめてから消す
       docker compose exec certbot certbot delete --cert-name $OLD

  8. 控え $BAK には秘密が入っています。落ち着いたら消すこと。

戻すとき:
  cd $PATH_ROOT && cat $BAK > .env && docker compose up -d
EOF
