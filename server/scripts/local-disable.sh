#!/bin/sh
#
# 検証機で、**外へ出る・本番の代わりに動いてしまう機能**を止める。**検証機(/etc/kosenmap-test-host・KM_ENV=local)でだけ動く。**
#
#   sh scripts/local-disable.sh                  一覧と、いま止まっているかを見るだけ(何も変えない。止まっていなければ終了コード 1)
#   sudo sh scripts/local-disable.sh --apply     止める(何度流してもよい)
#   sudo sh scripts/local-disable.sh --undo      cron の行だけ戻す(.env の空にしたキーは戻さない)
#
# ## なぜ要るのか(2026-11-07 段 5。利用者「機能の追加の無効化すべきものを上げて無効化処理を行うコマンドを作成してください」)
#
# 検証機は本番の写し。だが本番と同じ cron・設定のままだと、**外の相手に本物として振る舞う**ものがある。
#
#   止めるもの                         止め方                                  なぜ
#   ---------------------------------  --------------------------------------  ------------------------------------------------
#   アプリの受け箱(GitHub → 公開)    cron の行・apk-inbox.local.conf を退避  本物の受け箱から APK を取り込んで公開してしまう
#   国の拒否(IP の一覧を取って nft)  cron の行                                LAN の検証機に要らない。外から一覧を取る
#   証明書の更新(Let's Encrypt)      cron の行・certbot を止める             公開 DNS が無いので毎日失敗する
#   控え(host-backup.sh)              cron の行                                控えの鍵が無い・HDD を食う・本番の控えと紛れる
#   イメージの検査(Trivy)            cron の行                                週 1 回 DB を外から取る(遅い回線と HDD に重い)
#   外へのメール                       mailserver を止める・25/465/587 を断る   compose.local.yaml と testhost-bootstrap.sh が既に止める。ここは確かめるだけ
#   案内 AI(自宅 PC の LLM)          .env の KM_GUIDE_LLM_URL・KEY を空に     本番のトンネルの先(利用者の PC)へ問い合わせてしまう
#   reCAPTCHA(Google)                .env の RECAPTCHA_* を空に              本番の鍵で Google に問い合わせてしまう(検証機では持たない)
#
#   残すもの(本番と同じに振る舞わせる): 天気(気象庁の公開の JSON を読むだけ)・使用率・セキュリティ確認・更新の確認
#   (知らせは web の notify-log.php → Mailpit に溜まるだけ)・タスクマネージャー・phpMyAdmin の窓・訪問記録の片付け
#
# cron の行は消さずに先頭へ「#KM-LOCAL-OFF 」を付ける(--undo で戻せる)。**host-updates-setup.sh を流し直すと cron は元に戻る**ので、
# そのあとはこれも流し直す(--check が 1 を返すので気付ける)。
# POSIX sh。**`[ … ] && cmd` を単独で書かない**(set -e の下で、偽のときにスクリプトごと終わる)。

set -eu

cd "$(dirname "$0")/.."

PROD_DOMAINS="ito4.jp ito8795.com"
MARKER=/etc/kosenmap-test-host
CRON_FILE=/etc/cron.d/kosenmap-updates
TAG='#KM-LOCAL-OFF '
# 止める cron(行の中のスクリプトの名前で拾う)
OFF_JOBS="host-apk-inbox.sh host-geoblock.sh host-cert.sh host-backup.sh host-image-scan.sh"
# 空にする .env のキー
EMPTY_KEYS="KM_GUIDE_LLM_URL KM_GUIDE_LLM_KEY RECAPTCHA_SITE_KEY RECAPTCHA_SECRET_KEY RECAPTCHA_API_KEY RECAPTCHA_PROJECT_ID"
# 起動していてはいけないサービス(compose.local.yaml が profile で外している)
OFF_SERVICES="certbot mailserver"

MODE=check
case "${1:-}" in
  --apply) MODE=apply ;;
  --undo) MODE=undo ;;
  --check|'') MODE=check ;;
  -h|--help) sed -n '2,36p' "$0"; exit 0 ;;
  *) echo "知らない引数: $1" >&2; exit 2 ;;
esac

ok()   { printf '  OK   %s\n' "$*"; }
did()  { printf '  止め %s\n' "$*"; }
bad()  { printf '  ★    %s\n' "$*"; LEFT=$((LEFT + 1)); }
head_() { printf '\n== %s\n' "$*"; }
LEFT=0

env_value() {
  if [ ! -f .env ]; then
    return 0
  fi
  sed -n "s/^$1=//p" .env | tail -n 1 | tr -d '\r' | sed "s/^\"\\(.*\\)\"\$/\\1/; s/^'\\(.*\\)'\$/\\1/"
}

# ---------------------------------------------------------------- 本番では止まる
if [ ! -f "$MARKER" ] || [ "$(env_value KM_ENV)" != "local" ]; then
  echo "★ 検証機(/etc/kosenmap-test-host と KM_ENV=local)でだけ流します。" >&2
  exit 1
fi
_domain="$(env_value KM_DOMAIN)"
for _p in $PROD_DOMAINS; do
  case "$_domain" in
    "$_p"|*".$_p") echo "★ KM_DOMAIN が本番の名前です。止めます。" >&2; exit 1 ;;
  esac
done
if [ "$MODE" != check ] && [ "$(id -u)" != 0 ]; then
  echo "cron は root の持ち物なので sudo で: sudo sh scripts/local-disable.sh $1" >&2
  exit 1
fi

# ---------------------------------------------------------------- cron
head_ "1. 定期処理($CRON_FILE)"
if [ ! -f "$CRON_FILE" ]; then
  ok "cron のファイルがありません(host-updates-setup.sh を流していない = どれも動いていない)"
else
  for _job in $OFF_JOBS; do
    _on="$(grep -v '^#' "$CRON_FILE" | grep -c "$_job" || true)"
    _off="$(grep -c "^$TAG.*$_job" "$CRON_FILE" || true)"
    if [ "$MODE" = undo ]; then
      if [ "$_off" -gt 0 ]; then
        _t="$(mktemp)"
        sed "s|^$TAG\\(.*$_job.*\\)\$|\\1|" "$CRON_FILE" > "$_t"
        cat "$_t" > "$CRON_FILE"
        rm -f "$_t"
        printf '  戻し %s(%s 行)\n' "$_job" "$_off"
      fi
      continue
    fi
    if [ "$_on" -eq 0 ]; then
      ok "$_job は動かない(止めた行 $_off)"
    elif [ "$MODE" = apply ]; then
      # 同じファイルに書き戻す(持ち主 root・644 を保つ。cron は root の持ち物でないファイルを読まない)
      _t="$(mktemp)"
      awk -v job="$_job" -v tag="$TAG" '!/^#/ && index($0, job) { print tag $0; next } { print }' "$CRON_FILE" > "$_t"
      cat "$_t" > "$CRON_FILE"
      rm -f "$_t"
      did "$_job($_on 行)"
    else
      bad "$_job が動く($_on 行)"
    fi
  done
fi

# ---------------------------------------------------------------- 受け箱の設定(GitHub のトークン)
head_ '2. アプリの受け箱の設定'
if [ -f apk-inbox.local.conf ]; then
  if [ "$MODE" = apply ]; then
    mkdir -p Old
    mv apk-inbox.local.conf "Old/apk-inbox.local.conf.disabled-$(date +%Y%m%d-%H%M%S)"
    did 'apk-inbox.local.conf を Old/ へ退避しました(検証機に GitHub の鍵を置かない)'
  else
    bad 'apk-inbox.local.conf がある(GitHub の受け箱の鍵。検証機には置かない)'
  fi
else
  ok 'apk-inbox.local.conf は無い'
fi

# ---------------------------------------------------------------- .env
head_ '3. .env の外へ繋ぐキー(案内 AI・reCAPTCHA)'
_changed=0
for _key in $EMPTY_KEYS; do
  if [ -z "$(env_value "$_key")" ]; then
    ok "$_key は空"
  elif [ "$MODE" = apply ]; then
    # 持ち主と権限を保つ(sed の -i は新しいファイルを作って root の持ち物にしてしまう)
    _t="$(mktemp)"
    sed "s|^$_key=.*|$_key=|" .env > "$_t"
    cat "$_t" > .env
    rm -f "$_t"
    did "$_key を空にしました"
    _changed=1
  else
    bad "$_key に値がある(検証機では空にする)"
  fi
done
if [ "$_changed" = 1 ]; then
  docker compose up -d web >/dev/null 2>&1 || true
  ok 'web を起こし直しました(.env を読み直す)'
fi

# ---------------------------------------------------------------- サービス
head_ '4. 起動していてはいけないサービス'
_running=" $(docker compose ps --services --status running 2>/dev/null | tr '\n' ' ') "
for _svc in $OFF_SERVICES; do
  case "$_running" in
    *" $_svc "*)
      if [ "$MODE" = apply ]; then
        docker compose stop "$_svc" >/dev/null
        did "$_svc を止めました"
      else
        bad "$_svc が動いている"
      fi ;;
    *) ok "$_svc は動いていない" ;;
  esac
done

# ---------------------------------------------------------------- 外へのメール(確かめるだけ)
head_ '5. 外へのメール(testhost-bootstrap.sh が止めている。確かめるだけ)'
if systemctl is-active km-block-smtp.service >/dev/null 2>&1; then
  ok 'コンテナから 25・465・587 を断っている(km-block-smtp.service)'
else
  bad 'km-block-smtp.service が動いていない(sudo sh testhost-bootstrap.sh --lan … を流し直す)'
fi
if [ "$(docker compose exec -T web printenv MAIL_HOST 2>/dev/null | tr -d '\r')" = "mailpit" ]; then
  ok 'web のメールは Mailpit へ'
else
  bad 'web の MAIL_HOST が mailpit ではない(COMPOSE_FILE に compose.local.yaml が入っているか)'
fi

if [ "$MODE" = check ]; then
  echo
  if [ "$LEFT" -gt 0 ]; then
    printf '★ 止まっていないものが %s 件(sudo sh scripts/local-disable.sh --apply)\n' "$LEFT"
    exit 1
  fi
  echo 'すべて止まっています。'
fi
