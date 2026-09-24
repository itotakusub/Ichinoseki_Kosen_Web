#!/bin/sh
#
# ローカル環境(LAN の中の検証機)を .env の切り替えで立てる。**本番では使わない。**
#
#   ./host-local.sh init --domain D [--admin-domain A] [--ip IP] [--lan CIDR]
#                                   自作 CA と証明書を作り、.env をローカル環境に切り替える
#   ./host-local.sh status          いまの状態を見る(何も変えない)
#
#   --domain       ローカルで使う名前(例 km.test、192-168-1-50.sslip.io)。**点を含む名前**
#                  (Android のビルドが localhost や IP を受け付けないため)
#   --admin-domain 管理画面を別オリジンにするときの名前(省略可)
#   --ip           この検証機の LAN の IPv4(省略すると hostname -I の先頭)
#   --lan          管理系ポート(8281・3002・8025)に入れる範囲(省略すると --ip の /24)
#
# ## なぜ要るのか(2026-09-17)
#
# 本番の構成を LAN の検証機へ持ち込むと、Let's Encrypt の証明書が取れず、nginx と MariaDB が
# 証明書を読めずに**再起動を繰り返した**。証明書の作り方は Old/vps-bootstrap.sh にしか残っておらず、
# 検証機を作り直すたびに手で組むことになっていた。**これ 1 本で何度でも同じ形に立てる。**
#
# ## 何を変えるか
#
#   init   … certs/ に自作 CA(無ければ)と、MariaDB・nginx の証明書(無い・名前が違う・残り 30 日を切った
#            ときだけ)を作る。古い証明書は certs/Old/<日時>/ へ退避する(消さない)。
#            .env を控えてから KM_ENV・KM_DOMAIN・COMPOSE_FILE を書き、本番の名前が残った URL 系の行を空にする。
#            nginx/km/allow-admin-home.local.conf に LAN の範囲を足す。**docker compose up はしない。**
#   status … 何も変えない
#
# ## 本番では止まる
#
# 次のどれかなら何もせずに止める:
#   - .env の KM_DOMAIN が本番のドメインで、KM_ENV=local でない
#   - KM_ENV=local でないのに、Let's Encrypt の証明書(test_letsencrypt の live/)がある
#   - 指定した名前が、公開のアドレスでこの機械以外を指している
#
# POSIX sh。**`[ … ] && cmd` を単独で書かない**(set -e の下で、偽のときにスクリプトごと終わる)。
# 手順は docs/13-local-env.ipynb。

set -eu

DEFAULT_ARGS=""

PATH_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CMD=""
DOMAIN=""
ADMIN=""
IP=""
LAN=""

# 本番のドメイン。**ローカルの .env にこの名前が残っていたら空にする**(画面のリンクが本番を指すため)。
# 2026-09-17 に ito8795.com → ito4.jp へ移した。旧ドメインも本番の証明書や控えに残っているので、どちらもローカルに使わせない
PROD_DOMAIN="ito4.jp"
PROD_DOMAINS="ito4.jp ito8795.com"
# アクセストークンの宛先名(audience)。接続先ではなく Logto に登録した名前。2026-09-17 に ito4.jp へ一緒に移した。
# **移行前の控えを戻したときは https://ito8795.com/api**(控えの Logto に登録されている値)を .env に書くこと
PROD_API_RESOURCE="https://ito4.jp/api"
LOCAL_COMPOSE="compose.yaml:compose.vps.yaml:compose.local.yaml"
# サーバー証明書の有効日数。ブラウザが長すぎる証明書を嫌うので 397 日にする(CA は 10 年)
SERVER_DAYS=397
RENEW_DAYS=30

if [ -n "$DEFAULT_ARGS" ]; then
  # shellcheck disable=SC2086
  set -- $DEFAULT_ARGS "$@"
fi

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --domain) DOMAIN="$2"; shift 2 ;;
    --admin-domain) ADMIN="$2"; shift 2 ;;
    --ip) IP="$2"; shift 2 ;;
    --lan) LAN="$2"; shift 2 ;;
    init|status) CMD="$1"; shift ;;
    -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

if [ -z "$CMD" ]; then
  echo "init / status のどれかを指定してください(--help で説明)" >&2
  exit 2
fi

if ! cd "$PATH_ROOT"; then
  echo "$PATH_ROOT へ移動できません。--path を確かめてください。" >&2
  exit 1
fi
if [ ! -f compose.yaml ] || [ ! -f compose.local.yaml ]; then
  echo "ここは KosenMap の置き場ではないか、compose.local.yaml がまだ配備されていません: $PATH_ROOT" >&2
  exit 2
fi

CERTS="$PATH_ROOT/certs"
PROBLEMS=0

ok()   { printf '  OK   %s\n' "$*"; }
note() { printf '       %s\n' "$*"; }
bad()  { printf '  ★    %s\n' "$*"; PROBLEMS=$((PROBLEMS + 1)); }
did()  { printf '  直し %s\n' "$*"; }
head_() { printf '\n== %s\n' "$*"; }

# .env から1つだけ読む。**.env を丸ごと source しない**(秘密を環境へ撒かない)
env_value() {
  if [ ! -f .env ]; then
    return 0
  fi
  sed -n "s/^$1=//p" .env | tail -n 1 | tr -d '\r' | sed "s/^\"\\(.*\\)\"\$/\\1/; s/^'\\(.*\\)'\$/\\1/"
}

# 1 行だけ書き換える。値は名前・パス・URL だけなので、| と改行が無いことを呼ぶ側で保証する
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

is_domain() {
  printf '%s' "$1" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$'
}

is_ipv4() {
  printf '%s' "$1" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$'
}

is_private_ipv4() {
  case "$1" in
    10.*|192.168.*|127.*) return 0 ;;
    172.*)
      _o="$(printf '%s' "$1" | cut -d. -f2)"
      if [ "$_o" -ge 16 ] && [ "$_o" -le 31 ]; then
        return 0
      fi
      return 1 ;;
    *) return 1 ;;
  esac
}

# root の持ち物にするときだけ使う(置き場の持ち主 kmops は docker グループに居るので sudo は要らない。host-setup.sh と同じ手)
as_root() {
  docker run --rm -v "$CERTS:/c" alpine sh -c "$1"
}

# 証明書の SAN を「DNS:a,IP Address:1.2.3.4」の形で、並びを揃えて返す
cert_san() {
  openssl x509 -noout -ext subjectAltName -in "$1" 2>/dev/null | tail -n +2 \
    | tr ',' '\n' | sed 's/^ *//; s/ *$//' | grep -v '^$' | sort -u | tr '\n' ',' | sed 's/,$//'
}

days_left() {
  _end="$(openssl x509 -noout -enddate -in "$1" 2>/dev/null | cut -d= -f2)"
  _e="$(date -d "$_end" +%s 2>/dev/null || echo 0)"
  echo $(( (_e - $(date +%s)) / 86400 ))
}

fingerprint() {
  openssl x509 -noout -fingerprint -sha256 -in "$1" 2>/dev/null | cut -d= -f2
}

# 作り直しが要るか: 無い・この CA で検証できない・名前が違う・残りが少ない
needs_new() {
  # $1 = 証明書 / $2 = 欲しい SAN(cert_san と同じ形)
  if [ ! -f "$1" ]; then
    return 0
  fi
  if ! openssl verify -CAfile "$CERTS/rootCA.pem" "$1" >/dev/null 2>&1; then
    return 0
  fi
  if [ "$(cert_san "$1")" != "$2" ]; then
    return 0
  fi
  if [ "$(days_left "$1")" -lt "$RENEW_DAYS" ]; then
    return 0
  fi
  return 1
}

# 古いものは消さずに certs/Old/<日時>/ へ
archive_old() {
  for _f in "$@"; do
    if [ -e "$CERTS/$_f" ]; then
      mkdir -p "$ARCHIVE"
      mv "$CERTS/$_f" "$ARCHIVE/"
    fi
  done
}

# サーバー証明書を 1 組作る
issue_server() {
  # $1 = ファイル名の頭(mariadb-server など) / $2 = CN / $3 = openssl の SAN(DNS:a,IP:1.2.3.4)
  _t="$(mktemp -d)"
  openssl req -newkey rsa:2048 -sha256 -nodes \
    -keyout "$_t/key.pem" -out "$_t/req.csr" -subj "/CN=$2" 2>/dev/null
  printf 'subjectAltName=%s\nextendedKeyUsage=serverAuth\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\n' "$3" > "$_t/ext.cnf"
  openssl x509 -req -in "$_t/req.csr" -days "$SERVER_DAYS" -sha256 \
    -CA "$CERTS/rootCA.pem" -CAkey "$CERTS/rootCA-key.pem" \
    -set_serial "0x$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')" \
    -extfile "$_t/ext.cnf" -out "$_t/cert.pem" 2>/dev/null
  archive_old "$1.pem" "$1-key.pem"
  install -m 644 "$_t/cert.pem" "$CERTS/$1.pem"
  install -m 640 "$_t/key.pem" "$CERTS/$1-key.pem"
  find "$_t" -delete
}

# ---------------------------------------------------------------------------
# init
# ---------------------------------------------------------------------------
do_init() {
  head_ '1. 本番ではないことを確かめる'
  if [ -z "$DOMAIN" ] || ! is_domain "$DOMAIN"; then
    echo "--domain に点を含む名前を指定してください(例 km.test)。localhost や IP は Android のビルドが受け付けません。" >&2
    return 2
  fi
  if [ -n "$ADMIN" ] && ! is_domain "$ADMIN"; then
    echo "--admin-domain がドメインの形ではありません: $ADMIN" >&2
    return 2
  fi
  for _p in $PROD_DOMAINS; do
    for _n in "$DOMAIN" "$ADMIN"; do
      case "$_n" in
        "$_p"|*".$_p")
          echo "本番のドメイン($_p)とその下の名前はローカル環境に使えません: $_n" >&2
          return 1 ;;
      esac
    done
  done

  _env_mode="$(env_value KM_ENV)"
  _env_domain="$(env_value KM_DOMAIN)"
  _env_is_prod=0
  for _p in $PROD_DOMAINS; do
    if [ "$_env_domain" = "$_p" ]; then
      _env_is_prod=1
    fi
  done
  if [ "$_env_is_prod" = "1" ] && [ "$_env_mode" != "local" ]; then
    echo "★ この .env は本番のもの(KM_DOMAIN=$_env_domain、KM_ENV なし)です。本番のホストでは実行しません。" >&2
    echo "  控えから持ってきた .env なら、先に KM_ENV=local を書いてからもう一度実行してください。" >&2
    return 1
  fi
  if [ "$_env_mode" != "local" ] && docker volume inspect test_letsencrypt >/dev/null 2>&1; then
    _live="$(docker run --rm -v test_letsencrypt:/le:ro alpine sh -c 'ls -d /le/live/*/ 2>/dev/null | head -n 1' 2>/dev/null || true)"
    if [ -n "$_live" ]; then
      echo "★ Let's Encrypt の証明書があります($_live)。本番のホストのようなので実行しません。" >&2
      return 1
    fi
  fi
  ok "本番の .env でも、Let's Encrypt の証明書のあるホストでもありません"

  if [ -z "$IP" ]; then
    IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
  fi
  if ! is_ipv4 "$IP"; then
    echo "この機械の IPv4 を決められません。--ip で指定してください。" >&2
    return 2
  fi
  _mine=" $(hostname -I 2>/dev/null) "
  for _name in "$DOMAIN" $ADMIN; do
    _ips="$(getent ahostsv4 "$_name" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ')"
    if [ -z "$_ips" ]; then
      note "$_name はこの機械からは引けません(PC と Android から引ければ足ります。docs/13 §2)"
      continue
    fi
    for _a in $_ips; do
      case "$_mine" in
        *" $_a "*) continue ;;
      esac
      if ! is_private_ipv4 "$_a"; then
        echo "★ $_name が公開のアドレス $_a(この機械ではない)を指しています。本番の名前ではありませんか。" >&2
        return 1
      fi
    done
    ok "$_name → $_ips"
  done
  if [ -f nginx/km/hsts-enable.conf ]; then
    echo "★ nginx/km/hsts-enable.conf があります。ローカルの名前に HSTS を覚えさせると、証明書を作り直したときにブラウザで開けなくなります。" >&2
    echo "  消さずに nginx/km/Old/ へ移してから、もう一度実行してください。" >&2
    return 1
  fi

  head_ '2. 自作 CA と証明書(certs/)'
  if ! command -v openssl >/dev/null 2>&1; then
    echo "openssl がありません(sudo apt install openssl)。" >&2
    return 1
  fi
  mkdir -p "$CERTS"
  ARCHIVE="$CERTS/Old/$(date +%Y%m%d-%H%M%S)"

  if [ -f "$CERTS/rootCA.pem" ] && [ -f "$CERTS/rootCA-key.pem" ]; then
    ok "CA を使い回します: $(openssl x509 -noout -subject -in "$CERTS/rootCA.pem" | sed 's/^subject=//')"
  elif [ -e "$CERTS/rootCA.pem" ] || [ -e "$CERTS/rootCA-key.pem" ]; then
    echo "★ CA が片方だけあります(本番の certs/ を持ってきた?)。certs/ を消さずに別の名前へ移してから、もう一度実行してください。" >&2
    return 1
  else
    _t="$(mktemp -d)"
    openssl req -x509 -newkey rsa:2048 -sha256 -days 3650 -nodes \
      -keyout "$_t/key.pem" -out "$_t/ca.pem" \
      -subj "/CN=KosenMap Local CA ($(hostname))" \
      -addext "basicConstraints=critical,CA:TRUE" \
      -addext "keyUsage=critical,keyCertSign,cRLSign" 2>/dev/null
    install -m 644 "$_t/ca.pem" "$CERTS/rootCA.pem"
    install -m 600 "$_t/key.pem" "$CERTS/rootCA-key.pem"
    find "$_t" -delete
    did "CA を作りました(10 年)。指紋 $(fingerprint "$CERTS/rootCA.pem")"
  fi
  # Android と Windows に入れやすい名前の写し(中身は同じ PEM)
  install -m 644 "$CERTS/rootCA.pem" "$CERTS/kosenmap-local-ca.crt"

  # MariaDB: web も phpMyAdmin もホスト名 mariadb で繋いで検証する(Old/vps-bootstrap.sh と同じ SAN)
  if needs_new "$CERTS/mariadb-server.pem" "DNS:localhost,DNS:mariadb,IP Address:127.0.0.1"; then
    issue_server mariadb-server mariadb "DNS:mariadb,DNS:localhost,IP:127.0.0.1"
    # mariadb の公式イメージは uid 999(mysql)で動く。**読めないと起動に失敗する**
    as_root "chown 999:999 /c/mariadb-server-key.pem && chmod 640 /c/mariadb-server-key.pem"
    did "MariaDB の証明書を作りました(SAN mariadb・localhost・127.0.0.1、${SERVER_DAYS} 日)"
  else
    ok "MariaDB の証明書はそのまま使えます(残り $(days_left "$CERTS/mariadb-server.pem") 日)"
  fi

  _want="DNS:$DOMAIN
DNS:localhost
IP Address:$IP
IP Address:127.0.0.1"
  _ext="DNS:$DOMAIN,DNS:localhost,IP:$IP,IP:127.0.0.1"
  if [ -n "$ADMIN" ] && [ "$ADMIN" != "$DOMAIN" ]; then
    _want="$_want
DNS:$ADMIN"
    _ext="$_ext,DNS:$ADMIN"
  fi
  _want="$(printf '%s\n' "$_want" | sort -u | tr '\n' ',' | sed 's/,$//')"
  if needs_new "$CERTS/local-server.pem" "$_want"; then
    issue_server local-server "$DOMAIN" "$_ext"
    did "nginx の証明書を作りました($(cert_san "$CERTS/local-server.pem")、${SERVER_DAYS} 日)"
    note "nginx が動いていれば、読み込ませる: docker compose exec reverse-proxy nginx -s reload"
  else
    ok "nginx の証明書はそのまま使えます(残り $(days_left "$CERTS/local-server.pem") 日)"
  fi
  if [ -d "$ARCHIVE" ]; then
    note "古い証明書は $ARCHIVE へ退避しました"
  fi

  head_ '3. .env をローカル環境に切り替える'
  if [ -f .env ]; then
    _bak=".env.bak-$(date +%Y%m%d-%H%M%S)"
    cp -p .env "$_bak"
    note "控え: $_bak"
  else
    ( umask 077 && : > .env )
    did ".env を作りました(秘密の値は控えの env.txt から足してください)"
  fi
  set_env KM_ENV local
  set_env KM_DOMAIN "$DOMAIN"
  set_env COMPOSE_FILE "$LOCAL_COMPOSE"
  if [ -n "$ADMIN" ]; then
    set_env KM_ADMIN_DOMAIN "$ADMIN"
  fi
  # **本番の名前が残った URL の行を空にする。** 書いてあると導出より勝ち、ローカルの画面が本番を指す
  for _key in KM_APP_URL APP_URL LOGTO_ENDPOINT LOGTO_ADMIN_ENDPOINT PMA_ABSOLUTE_URI KM_ADMIN_URL ADMIN_URL KM_CERT KM_CERT_KEY MAIL_FROM; do
    _v="$(env_value "$_key")"
    for _p in $PROD_DOMAINS; do
      case "$_v" in
        *"$_p"*) set_env "$_key" ""; did "$_key を空にしました(本番の名前が入っていた)"; break ;;
      esac
    done
  done
  # アクセストークンの宛先名は**ドメインを変えても据え置く**。控えから戻した Logto に登録されているのは本番の値
  if [ -z "$(env_value KM_API_RESOURCE)" ]; then
    set_env KM_API_RESOURCE "$PROD_API_RESOURCE"
    did "KM_API_RESOURCE=$PROD_API_RESOURCE を書きました(控えから戻した Logto の登録と同じ値。名前であって接続先ではない)"
  fi
  ok "KM_ENV=local / KM_DOMAIN=$DOMAIN${ADMIN:+ / KM_ADMIN_DOMAIN=$ADMIN} / COMPOSE_FILE=$LOCAL_COMPOSE"

  head_ '4. 管理系ポートに入れる範囲'
  if [ -z "$LAN" ]; then
    LAN="$(printf '%s' "$IP" | cut -d. -f1-3).0/24"
  fi
  _allow=nginx/km/allow-admin-home.local.conf
  touch "$_allow"
  if grep -qx "allow $LAN;" "$_allow"; then
    ok "$_allow に $LAN があります"
  else
    printf '# %s に host-local.sh init が足した(ローカル環境の LAN)\nallow %s;\n' "$(date +%F)" "$LAN" >> "$_allow"
    did "$_allow に $LAN を足しました"
  fi

  cat <<EOF

== 次にやること(docs/13-local-env.ipynb)
  1. 名前を引けるようにする: LAN のルーターの DNS か、この PC の hosts に
       $IP  $DOMAIN${ADMIN:+ $ADMIN}
     (Android は hosts を持たないので、ルーターの DNS か sslip.io の名前を使う)
  2. CA を入れる: certs/kosenmap-local-ca.crt を PC(信頼されたルート証明機関)と Android(CA 証明書)へ
  3. 控えから戻す(初回だけ)→ Logto の戻り先: docker compose exec -T -u www-data web php scripts/logto-domain.php --from=<控えの本番の名前> --to=$DOMAIN --apply
     (2026-09-17 の移行より前の控えは ito8795.com、後は $PROD_DOMAIN)
  4. ./scripts/host-setup.sh --fix → docker compose build web soketi → docker compose up -d
  5. ./scripts/host-local.sh status
EOF
}

# ---------------------------------------------------------------------------
# status
# ---------------------------------------------------------------------------
do_status() {
  _mode="$(env_value KM_ENV)"
  _domain="$(env_value KM_DOMAIN)"
  _admin="$(env_value KM_ADMIN_DOMAIN)"

  head_ '切り替え(.env)'
  if [ "$_mode" = "local" ]; then
    ok "KM_ENV=local(ローカル環境)"
  else
    bad "KM_ENV=local ではありません(いまは「${_mode:-なし}」= 本番の扱い)。init で切り替えます"
  fi
  if [ "$(env_value COMPOSE_FILE)" = "$LOCAL_COMPOSE" ]; then
    ok "COMPOSE_FILE=$LOCAL_COMPOSE"
  else
    bad "COMPOSE_FILE が $LOCAL_COMPOSE ではありません(いまは「$(env_value COMPOSE_FILE)」)"
  fi
  note "KM_DOMAIN=${_domain:-なし}${_admin:+ / KM_ADMIN_DOMAIN=$_admin}"

  head_ '証明書(certs/)'
  for _f in rootCA.pem mariadb-server.pem mariadb-server-key.pem local-server.pem local-server-key.pem; do
    if [ -d "$CERTS/$_f" ]; then
      bad "certs/$_f が**ディレクトリ**です(ファイルが無いまま up した跡)。中を確かめて退避し、init で作り直す"
    elif [ ! -f "$CERTS/$_f" ]; then
      bad "certs/$_f がありません —— この状態で up すると nginx か MariaDB が再起動を繰り返します(init で作る)"
    fi
  done
  if [ -f "$CERTS/rootCA.pem" ]; then
    ok "CA: $(openssl x509 -noout -subject -in "$CERTS/rootCA.pem" | sed 's/^subject=//')(残り $(days_left "$CERTS/rootCA.pem") 日)"
    note "指紋 $(fingerprint "$CERTS/rootCA.pem")(PC と Android に入れたものと見比べる)"
    for _f in mariadb-server.pem local-server.pem; do
      if [ ! -f "$CERTS/$_f" ]; then
        continue
      fi
      _d="$(days_left "$CERTS/$_f")"
      if ! openssl verify -CAfile "$CERTS/rootCA.pem" "$CERTS/$_f" >/dev/null 2>&1; then
        bad "$_f はこの CA で検証できません(init で作り直す)"
      elif [ "$_d" -lt "$RENEW_DAYS" ]; then
        bad "$_f の残りが $_d 日です(init で作り直す)"
      else
        ok "$_f 残り $_d 日 / $(cert_san "$CERTS/$_f")"
      fi
    done
    if [ -f "$CERTS/local-server.pem" ]; then
      for _name in "$_domain" $_admin; do
        if [ -z "$_name" ]; then
          continue
        fi
        case ",$(cert_san "$CERTS/local-server.pem")," in
          *",DNS:$_name,"*) ;;
          *) bad "nginx の証明書に $_name がありません(init --domain $_domain${_admin:+ --admin-domain $_admin} で作り直す)" ;;
        esac
      done
    fi
  fi

  head_ 'nginx が出している証明書'
  if [ -n "$_domain" ] && [ -f "$CERTS/local-server.pem" ]; then
    # 標準入力は空で渡す(s_client は入力が尽きたら閉じる)
    _served="$(printf '' | timeout 10 openssl s_client -connect 127.0.0.1:443 -servername "$_domain" 2>/dev/null \
      | openssl x509 -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2 || true)"
    if [ -z "$_served" ]; then
      note "443 番から受け取れません(nginx が止まっているか、まだ up していない)"
    elif [ "$_served" = "$(fingerprint "$CERTS/local-server.pem")" ]; then
      ok "certs/local-server.pem と同じものを出しています"
    else
      bad "nginx が**古い証明書**を出しています: docker compose exec reverse-proxy nginx -s reload"
    fi
  fi

  if [ "$PROBLEMS" -gt 0 ]; then
    printf '\n★ %s 件\n' "$PROBLEMS"
    return 1
  fi
  printf '\nすべて揃っています。\n'
}

case "$CMD" in
  init) do_init ;;
  status) do_status ;;
esac
