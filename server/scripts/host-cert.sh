#!/bin/sh
#
# Let's Encrypt の証明書を見る・更新する・新しく取る。**本番(compose.vps.yaml)用。**
#
#   ./host-cert.sh status                  残り日数と、nginx が**実際に出している**証明書(読むだけ)
#   ./host-cert.sh renew                   期限が近ければ更新し、更新されたら nginx に読み込ませる
#   ./host-cert.sh renew --dry-run         試験用の発行元で手順だけ通す(本物の証明書は変えない)
#   ./host-cert.sh issue --domain D --email E [--www] [--also NAME]… [--dry-run]
#                                          新しいドメインの証明書を取る(ドメインを変えるとき)
#                                          --also は同じ証明書に足す名前(管理画面を別オリジンにするときの
#                                          管理用のホスト名など。何度でも書ける。証明書の名前は D のまま)
#   ./host-cert.sh fix-conf [--dry-run]    更新の設定(renewal/*.conf)を webroot に揃える
#
#   cron(host-updates-setup.sh が仕込む):
#     send-log.sh --label 証明書の更新 --only-failure --run "host-cert.sh renew"
#
# ## certbot コンテナの 12 時間ループがあるのに、なぜ要るのか
#
# 1. **更新に失敗しても誰にも届かない。** ループは `--quiet` で、失敗しても眠り続ける。
#    healthcheck は残り 14 日を切るまで黙っており、それも `docker ps` を見た人にしか見えない。
#    HSTS を有効にしてあるので、**切れた時点で誰もサイトに入れなくなる。**
# 2. **更新しても、nginx は古い証明書を出し続ける。** 拾うのは 12 時間ごとの reload だけ。
#    ここでは更新を検出した直後に reload し、**出している証明書の指紋まで**突き合わせる。
# 3. **更新の設定が standalone のまま**だった(2026-09-14 に実測)。ループは毎回
#    `--webroot` を渡しているので通るが、**素の `certbot renew` は 80 番を掴めずに失敗する。**
#    手で叩いた人が「更新が壊れている」と誤解する。fix-conf で設定そのものを揃える。
#
# ループは残す(二重の備え)。certbot は自分でロックを取るので、同時に走ると片方が
# 「Another instance of Certbot is already running」で止まる —— そのときは待ってやり直す。
#
# ## 何を変えるか
#
#   status            … 何も変えない
#   renew --dry-run   … 何も変えない(試験用の発行元に問い合わせるだけ)
#   renew             … 期限が近い証明書だけ入れ替え、nginx を reload する(接続は切れない)
#   issue             … letsencrypt ボリュームに証明書を足す。**.env と nginx は変えない**
#   fix-conf          … renewal/*.conf の authenticator を書き換える
#
# POSIX sh。**`[ … ] && cmd` を単独で書かない**(set -e の下で、偽のときにスクリプトごと終わる)。

set -eu

DEFAULT_ARGS=""

PATH_ROOT="/opt/kosenmap"
CMD=""
DOMAIN=""
EMAIL=""
WWW=0
ALSO=""
DRY=0

# 残りがこれを切ったら注意、さらにこれを切ったら失敗(= cron からメールが届く)。
# certbot は残り 30 日で更新する。**21 日まで減っているのは更新が1週間以上効いていない**
WARN_DAYS=21
FAIL_DAYS=14
WEBROOT="/var/www/certbot"

if [ -n "$DEFAULT_ARGS" ]; then
  # shellcheck disable=SC2086
  set -- $DEFAULT_ARGS "$@"
fi

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --domain) DOMAIN="$2"; shift 2 ;;
    --email) EMAIL="$2"; shift 2 ;;
    --www) WWW=1; shift ;;
    --also) ALSO="$ALSO $2"; shift 2 ;;
    --dry-run) DRY=1; shift ;;
    status|renew|issue|fix-conf) CMD="$1"; shift ;;
    -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

if [ -z "$CMD" ]; then
  echo "status / renew / issue / fix-conf のどれかを指定してください(--help で説明)" >&2
  exit 2
fi

if ! cd "$PATH_ROOT"; then
  echo "$PATH_ROOT へ移動できません。--path を確かめてください。" >&2
  exit 1
fi

# .env から1つだけ読む。**.env を丸ごと source しない**(秘密を環境へ撒かない)
env_value() {
  sed -n "s/^$1=//p" .env 2>/dev/null | tail -n 1 | sed "s/^\"\\(.*\\)\"\$/\\1/; s/^'\\(.*\\)'\$/\\1/"
}

# ---------------------------------------------------------------------------
# certbot を叩く口。**動いているコンテナへ exec する**(新しいコンテナを作らない)
# ---------------------------------------------------------------------------
certbot_defined() {
  docker compose config --services 2>/dev/null | grep -qx certbot
}

certbot_running() {
  docker compose ps --status running --services 2>/dev/null | grep -qx certbot
}

certbot_exec() {
  if certbot_running; then
    docker compose exec -T certbot certbot "$@"
  else
    docker compose run --rm --no-deps -T --entrypoint certbot certbot "$@"
  fi
}

certbot_sh() {
  if certbot_running; then
    docker compose exec -T certbot sh -c "$1"
  else
    docker compose run --rm --no-deps -T --entrypoint sh certbot -c "$1"
  fi
}

# 名前|期限|SAN|指紋 を1行ずつ
cert_list() {
  certbot_sh '
    for d in /etc/letsencrypt/live/*/; do
      if [ ! -f "$d/fullchain.pem" ]; then continue; fi
      n=$(basename "$d")
      e=$(openssl x509 -noout -enddate -in "$d/fullchain.pem" | cut -d= -f2)
      s=$(openssl x509 -noout -ext subjectAltName -in "$d/fullchain.pem" 2>/dev/null | tail -n +2 | tr -d " \n")
      f=$(openssl x509 -noout -fingerprint -sha256 -in "$d/fullchain.pem" | cut -d= -f2)
      a=$(sed -n "s/^authenticator *= *//p" "/etc/letsencrypt/renewal/$n.conf" 2>/dev/null)
      echo "$n|$e|$s|$f|$a"
    done'
}

# nginx が 443 で**実際に出している**証明書の指紋。更新しても reload しなければ古いまま
served_fingerprint() {
  # 標準入力は空で渡す(s_client は入力が尽きたら閉じる)。**行頭を echo にしない** ——
  # 行末の継続の `\` が check.php の「echo にバックスラッシュを渡さない」に当たる
  printf '' | timeout 15 openssl s_client -connect 127.0.0.1:443 -servername "$1" 2>/dev/null \
    | openssl x509 -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2
}

days_until() {
  _end="$(date -d "$1" +%s 2>/dev/null || echo 0)"
  echo $(( (_end - $(date +%s)) / 86400 ))
}

reload_proxy() {
  if ! docker compose exec -T reverse-proxy nginx -t >/dev/null 2>&1; then
    echo "★ nginx の設定検査が通りません。reload しません:"
    docker compose exec -T reverse-proxy nginx -t 2>&1 | sed 's/^/    /'
    return 1
  fi
  docker compose exec -T reverse-proxy nginx -s reload
  echo "nginx に新しい証明書を読み込ませました(reload。接続は切れません)。"
  sleep 2
}

# ---------------------------------------------------------------------------
# status
# ---------------------------------------------------------------------------
do_status() {
  _rc=0
  _domain="$(env_value KM_DOMAIN)"
  _found=0

  if ! _lines="$(cert_list)"; then
    echo "★ 証明書を読めませんでした(certbot のコンテナ / letsencrypt ボリュームを確かめてください)"
    return 1
  fi
  if [ -z "$_lines" ]; then
    echo "★ 証明書がありません(/etc/letsencrypt/live が空)。issue で取ってください。"
    return 1
  fi

  echo "証明書(注意 ${WARN_DAYS} 日 / 失敗 ${FAIL_DAYS} 日を切ったら):"
  while IFS='|' read -r _name _end _san _fp _auth; do
    [ -n "$_name" ] || continue
    _days="$(days_until "$_end")"
    _mark="  "
    if [ "$_days" -lt "$FAIL_DAYS" ]; then
      _mark="★ "; _rc=1
    elif [ "$_days" -lt "$WARN_DAYS" ]; then
      _mark="注 "
    fi
    echo "${_mark}${_name}  残り ${_days} 日(${_end})  名前: ${_san:-?}  更新方式: ${_auth:-?}"
    if [ "$_auth" = "standalone" ]; then
      echo "   注意: 更新方式が standalone です。コンテナのループと cron は webroot を渡すので通りますが、"
      echo "         素の certbot renew は失敗します。 ./host-cert.sh fix-conf で揃えられます。"
    fi

    if [ "$_name" = "$_domain" ]; then
      _found=1
      # 管理画面を別オリジンにしているなら、その名前も証明書に入っていること(2026-09-15)。
      # 入っていないと、管理用のホストを開いた時点でブラウザが証明書の名前の不一致で止める
      _admin="$(env_value KM_ADMIN_DOMAIN)"
      if [ -n "$_admin" ] && [ "$_admin" != "$_domain" ]; then
        case ",${_san}," in
          *",DNS:${_admin},"*) echo "   管理用の名前(${_admin})も入っています。" ;;
          *) echo "★ 管理用の名前(${_admin})が証明書にありません。issue --domain ${_domain} --also ${_admin} で足してください。"; _rc=1 ;;
        esac
      fi
      _served="$(served_fingerprint "$_domain" || true)"
      if [ -z "$_served" ]; then
        echo "★ 443 番から証明書を受け取れません(nginx が動いているか確かめてください)"
        _rc=1
      elif [ "$_served" != "$_fp" ]; then
        echo "★ nginx が**古い証明書**を出しています(更新後に reload されていない)。renew で直ります。"
        _rc=1
      else
        echo "   nginx が出している証明書と一致しています。"
      fi
    fi
  done <<EOF
$_lines
EOF

  if [ -n "$_domain" ] && [ "$_found" -eq 0 ]; then
    echo "★ KM_DOMAIN(${_domain})の証明書がありません。issue --domain ${_domain} で取ってください。"
    _rc=1
  fi
  return "$_rc"
}

# ---------------------------------------------------------------------------
# renew
# ---------------------------------------------------------------------------
do_renew() {
  _before="$(cert_list || true)"
  set -- renew --webroot -w "$WEBROOT" --non-interactive --no-random-sleep-on-renew
  if [ "$DRY" -eq 1 ]; then
    set -- "$@" --dry-run
  fi

  _try=1
  while :; do
    set +e
    _out="$(certbot_exec "$@" 2>&1)"
    _code=$?
    set -e
    printf '%s\n' "$_out"
    if [ "$_code" -ne 0 ] && [ "$_try" -lt 3 ] && printf '%s' "$_out" | grep -q 'Another instance of Certbot is already running'; then
      echo "(certbot コンテナのループと重なりました。60 秒待ってやり直します: ${_try}/2)"
      _try=$((_try + 1))
      sleep 60
      continue
    fi
    break
  done

  if [ "$_code" -ne 0 ]; then
    echo "★ 更新に失敗しました(certbot の終了コード ${_code})。"
    echo "   よくある原因: 80 番の /.well-known/acme-challenge/ が届かない(DNS・ufw・nginx)、発行回数の上限"
    do_status || true
    return 1
  fi

  if [ "$DRY" -eq 1 ]; then
    echo "試しの更新は通りました(本物の証明書は変えていません)。"
    return 0
  fi

  _after="$(cert_list || true)"
  if [ "$_before" != "$_after" ]; then
    echo "証明書が入れ替わりました。"
    reload_proxy
  else
    echo "更新の時期ではありませんでした(残り 30 日を切ると更新します)。"
    # **更新が無くても、出している証明書が古ければ reload する**(ループ側で更新された場合)
    _domain="$(env_value KM_DOMAIN)"
    _fp="$(printf '%s\n' "$_after" | awk -F'|' -v d="$_domain" '$1 == d {print $4}')"
    _served="$(served_fingerprint "$_domain" || true)"
    if [ -n "$_fp" ] && [ -n "$_served" ] && [ "$_fp" != "$_served" ]; then
      echo "ただし nginx が古い証明書を出していたので読み込ませます。"
      reload_proxy
    fi
  fi
  do_status
}

# ---------------------------------------------------------------------------
# issue
# ---------------------------------------------------------------------------
do_issue() {
  if [ -z "$DOMAIN" ] || [ -z "$EMAIL" ]; then
    echo "--domain と --email が要ります(email は期限切れの警告を Let's Encrypt から受け取る宛先)" >&2
    return 2
  fi
  if ! printf '%s' "$DOMAIN" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$'; then
    echo "ドメインの形ではありません: $DOMAIN" >&2
    return 2
  fi

  set -- "$DOMAIN"
  if [ "$WWW" -eq 1 ]; then
    set -- "$@" "www.$DOMAIN"
  fi
  for _extra in $ALSO; do
    if ! printf '%s' "$_extra" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$'; then
      echo "--also の値がドメインの形ではありません: $_extra" >&2
      return 2
    fi
    set -- "$@" "$_extra"
  done

  echo "== 1. DNS がこのホストを指しているか"
  _mine="$(hostname -I 2>/dev/null || true)"
  _bad=0
  for _name in "$@"; do
    _ips="$(getent ahostsv4 "$_name" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ')"
    _hit=0
    for _ip in $_ips; do
      case " $_mine " in *" $_ip "*) _hit=1 ;; esac
    done
    if [ -z "$_ips" ]; then
      echo "★ $_name は名前を引けません(A レコードが無い / まだ広まっていない)"
      _bad=1
    elif [ "$_hit" -eq 1 ]; then
      echo "  $_name → $_ips(このホスト)"
    else
      echo "★ $_name → $_ips は、このホストの IP($_mine)ではありません"
      _bad=1
    fi
  done
  if [ "$_bad" -ne 0 ]; then
    echo "DNS を直してから、もう一度実行してください。発行を試すと失敗回数の上限を消費します。"
    return 1
  fi

  echo "== 2. 80 番の確認用の道(/.well-known/acme-challenge/)が外から届くか"
  _tok="km-probe-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"
  certbot_sh "mkdir -p '$WEBROOT/.well-known/acme-challenge' && printf '%s' '$_tok' > '$WEBROOT/.well-known/acme-challenge/$_tok'"
  _bad=0
  for _name in "$@"; do
    _got="$(curl -fsS -m 15 "http://$_name/.well-known/acme-challenge/$_tok" 2>/dev/null || true)"
    if [ "$_got" = "$_tok" ]; then
      echo "  http://$_name/.well-known/acme-challenge/ … 届きました"
    else
      echo "★ http://$_name/.well-known/acme-challenge/ に届きません(ufw の 80 番・nginx の 80 番の server を確かめる)"
      _bad=1
    fi
  done
  certbot_sh "rm -f '$WEBROOT/.well-known/acme-challenge/$_tok'"
  if [ "$_bad" -ne 0 ]; then
    return 1
  fi

  echo "== 3. 発行"
  _args=""
  for _name in "$@"; do
    _args="$_args -d $_name"
  done
  if [ "$DRY" -eq 1 ]; then
    _args="$_args --dry-run"
  fi
  # --cert-name で置き場の名前を D に固定し、--expand で**既にある証明書に名前を足す**ことを許す
  # (無いと certbot は「名前が増えた」ことを尋ね、--non-interactive の下では止まる)
  # shellcheck disable=SC2086
  if ! certbot_exec certonly --webroot -w "$WEBROOT" --cert-name "$DOMAIN" --expand $_args --email "$EMAIL" \
      --agree-tos --no-eff-email --key-type ecdsa --non-interactive --keep-until-expiring; then
    echo "★ 発行に失敗しました。"
    return 1
  fi

  if [ "$DRY" -eq 1 ]; then
    echo "試しの発行は通りました。--dry-run を外すと本物を取ります。"
    return 0
  fi

  cat <<EOF

取れました: /etc/letsencrypt/live/$DOMAIN/
**まだ切り替わっていません。** 次は取扱説明書 09-new-host の「ドメインを変える」:
  1. scripts/host-domain.sh check $DOMAIN      (何が変わるかを見る)
  2. scripts/host-domain.sh apply $DOMAIN      (.env の KM_DOMAIN を書き換える)
  3. docker compose up -d                       (新しい名前で立ち上げ直す)
EOF
}

# ---------------------------------------------------------------------------
# fix-conf
# ---------------------------------------------------------------------------
do_fix_conf() {
  _lines="$(cert_list)"
  _changed=0
  while IFS='|' read -r _name _end _san _fp _auth; do
    [ -n "$_name" ] || continue
    if [ "$_auth" = "webroot" ]; then
      echo "  $_name: すでに webroot です"
      continue
    fi
    if [ "$DRY" -eq 1 ]; then
      echo "  $_name: ${_auth:-?} → webroot に揃えます(--dry-run なので変えていません)"
      continue
    fi
    echo "  $_name: ${_auth:-?} → webroot(試験用の発行元で1回通してから書き換えます)"
    certbot_exec reconfigure --cert-name "$_name" --webroot -w "$WEBROOT" --non-interactive
    _changed=1
  done <<EOF
$_lines
EOF
  if [ "$_changed" -eq 1 ]; then
    echo "書き換えました。証明書そのものは変わっていません。"
  fi
}

# **ローカル環境では Let's Encrypt を使わない**(2026-09-17)。証明書は scripts/host-local.sh が自作 CA で作る。
# cron(版 6)は毎日ここを呼ぶので、失敗扱いにせず 0 で終わる
if [ "$(env_value KM_ENV)" = "local" ]; then
  echo "ローカル環境(KM_ENV=local)では Let's Encrypt を使いません。証明書は scripts/host-local.sh status で見ます。"
  exit 0
fi

if ! certbot_defined; then
  echo "この構成には certbot がありません(校内 LAN の mkcert 構成など)。Let's Encrypt は使っていません。"
  exit 0
fi

case "$CMD" in
  status) do_status ;;
  renew) do_renew ;;
  issue) do_issue ;;
  fix-conf) do_fix_conf ;;
esac
