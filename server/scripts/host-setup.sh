#!/bin/sh
#
# 配備したあとの後片付けを1本で行う。**ホスト側で走る。**
#
# ## なぜ要るのか
#
# deploy-to-host.ps1 はファイルを置くだけで、所有権には触れない。触れないのが正しい ——
# 大半のファイルは配備利用者(km)の持ち物のままでよい。
#
# ところが一部だけは **www-data の持ち物でなければ動かない**:
#
#   config/*.local.php  PHP が読む。map-access.local.php は**書き込みもする**
#   uploads/            PHP が書く(アップロード、配信する地図 JSON)
#   cache/              PHP が書く(JWKS)
#
# ここを手で直す運用にしていたため、実際に2つの不具合になった:
#
#   - 「地図データ公開設定」の保存だけが失敗する(config が書けない)
#   - new-map-release.ps1 の転送が落ちる(uploads が書けない)
#
# **推測で直さない。** www-data の uid はコンテナに聞き、グループは現地の値を保つ。
#
# ## 何もしないのが既定
#
# 引数なしなら **調べて報告するだけ**(--dry-run 相当)。直すときは --fix を付ける。
# 「走らせたら何かが変わっていた」を作らない。
#
# ## 使い方
#
#   ./host-setup.sh                 いまの状態を調べる(何も変えない)
#   ./host-setup.sh --fix           所有権と権限を直す
#   ./host-setup.sh --fix --up      直したうえで docker compose up -d
#   ./host-setup.sh --fix --composer  vendor/ を入れ直す
#   ./host-setup.sh --path /opt/kosenmap   場所を明示する
#
# 手元の Windows からは scripts/host-setup.ps1 が同じものを送り込んで実行する。

set -eu

# ---------------------------------------------------------------- 引数

FIX=0
DO_UP=0
DO_COMPOSER=0
PROJECT=""

# 手元の PowerShell から流し込むときは、ここへ引数が差し込まれる
# (scripts/host-setup.ps1)。標準入力から実行すると $@ を渡せないため。
# サーバー上で直に叩くときは、いつもどおり引数が優先される。
DEFAULT_ARGS=""
# shellcheck disable=SC2086
[ $# -gt 0 ] || set -- $DEFAULT_ARGS

while [ $# -gt 0 ]; do
    case "$1" in
        --fix)      FIX=1 ;;
        --up)       DO_UP=1 ;;
        --composer) DO_COMPOSER=1 ;;
        --path)     PROJECT="${2:-}"; shift ;;
        -h|--help)  sed -n '2,40p' "$0"; exit 0 ;;
        *) echo "知らない引数です: $1" >&2; exit 2 ;;
    esac
    shift
done

# ---------------------------------------------------------------- 場所を決める

if [ -z "$PROJECT" ]; then
    # このスクリプトは <project>/scripts/ に置かれている
    PROJECT="$(cd "$(dirname "$0")/.." && pwd)"
fi

SRC="$PROJECT/src"

if [ ! -f "$PROJECT/compose.yaml" ] || [ ! -d "$SRC" ]; then
    echo "ここは KosenMap の置き場ではないようです: $PROJECT" >&2
    echo "  --path で場所を指定してください(compose.yaml がある階層)。" >&2
    exit 2
fi

# **コンテナ名を直に書かない。** compose に聞けば、改名しても付いてくる。
#
# 2026-09-07 に `dev-*` → `km-*` へ改めた(本番と校内の検証機が `docker ps` で
# 見分けられず、公開中のサイトを止めかけたため)。ここが直書きのままだと、
# **配備の後片付けだけが「コンテナが無い」で落ちる。**
#
# 落とし所は3段:
#   1. compose に聞く          … 平時。名前を変えても追随する
#   2. 新しい名前を直に探す    … compose の名前を変えた直後(旧プロジェクトが残っている間)
#   3. 古い名前へ落ちる        … まだ入れ替えていないホスト
resolve_container() {
    # $1 = compose のサービス名 / $2 = 新しい名前 / $3 = 古い名前
    _id="$(cd "$PROJECT" && docker compose ps -q "$1" 2>/dev/null | head -n 1)"
    if [ -n "$_id" ]; then
        docker inspect -f '{{.Name}}' "$_id" 2>/dev/null | sed 's#^/##'
        return
    fi
    if docker inspect "$2" >/dev/null 2>&1; then printf '%s' "$2"; return; fi
    printf '%s' "$3"
}

WEB_CONTAINER="$(resolve_container web km-php-apache dev-php-apache)"
PROXY_CONTAINER="$(resolve_container reverse-proxy km-nginx-proxy dev-nginx-proxy)"

# 失敗を数える。**最初の1件で止めない** —— 全部見てから報告した方が直しやすい
PROBLEMS=0
CHANGES=0

note()  { printf '  %s\n' "$*"; }
ok()    { printf '  \033[32mOK\033[0m   %s\n' "$*"; }
warn()  { printf '  \033[33m注意\033[0m %s\n' "$*"; PROBLEMS=$((PROBLEMS + 1)); }
bad()   { printf '  \033[31mNG\033[0m   %s\n' "$*"; PROBLEMS=$((PROBLEMS + 1)); }
did()   { printf '  \033[36m直し\033[0m %s\n' "$*"; CHANGES=$((CHANGES + 1)); }
head_() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }

# ---------------------------------------------------------------- docker の下見

head_ 'Docker'

if ! command -v docker >/dev/null 2>&1; then
    echo 'docker が見つかりません。' >&2
    exit 2
fi

if ! docker info >/dev/null 2>&1; then
    echo 'docker を操作できません(docker グループに入っていますか)。' >&2
    exit 2
fi
ok 'docker を操作できます'

web_running() { [ -n "$(docker ps -q -f "name=^${WEB_CONTAINER}$" 2>/dev/null)" ]; }

if web_running; then
    ok "$WEB_CONTAINER が動いています"
else
    warn "$WEB_CONTAINER が動いていません(--up で起動できます)"
fi

# **www-data の uid をここに書かない。**
# php:8.4-apache では 33 だが、イメージを替えたときに黙ってずれる。
# コンテナが動いていれば本人に聞く。動いていなければ既知の値へ落とし、
# そのことを画面に出す(黙って仮定しない)。
if web_running; then
    WWW_UID="$(docker exec "$WEB_CONTAINER" id -u www-data 2>/dev/null || echo 33)"
else
    WWW_UID=33
    note "コンテナが止まっているので www-data = 33 とみなします"
fi

# グループは**現地の値を保つ**。km が読めることに意味がある(640)
KEEP_GID="$(id -g)"
ok "所有者にする値: uid=$WWW_UID / gid=$KEEP_GID(このログイン利用者)"

# ---------------------------------------------------------------- 所有権と権限

head_ '所有権と権限'

# root を借りる。置き場の持ち主(kmops。2026-09-18 までは km)は docker グループに居るので sudo は要らない
as_root() {
    docker run --rm -v "$PROJECT:/p" alpine sh -c "$1"
}

# 現在の姿。%U:%G は名前、%a は8進の権限
show() { stat -c '%n  %U:%G  %a' "$1" 2>/dev/null || echo "$1  (ありません)"; }

# 期待どおりか。所有者 uid と権限だけを見る(グループ名は現地に任せる)
check_owner_mode() {
    path="$1"; want_uid="$2"; want_mode="$3"; label="$4"
    if [ ! -e "$path" ]; then
        return 1
    fi
    cur_uid="$(stat -c '%u' "$path")"
    cur_mode="$(stat -c '%a' "$path")"
    if [ "$cur_uid" = "$want_uid" ] && [ "$cur_mode" = "$want_mode" ]; then
        ok "$label"
        return 0
    fi
    warn "$label —— いま uid=$cur_uid mode=$cur_mode / 欲しいのは uid=$want_uid mode=$want_mode"
    return 2
}

# --- config/*.local.php ---
#
# **秘密なので 640。** 所有者 www-data が読み書きし、グループ(km)は読むだけ。
# 600 にしないこと: km がバックアップを取れなくなる。

CONFIG_FOUND=0
for f in "$SRC"/config/*.local.php; do
    [ -e "$f" ] || continue
    CONFIG_FOUND=1
    rel="config/$(basename "$f")"
    if check_owner_mode "$f" "$WWW_UID" 640 "$rel"; then :; else
        if [ "$FIX" = "1" ]; then
            as_root "chown $WWW_UID:$KEEP_GID '/p/src/$rel' && chmod 640 '/p/src/$rel'"
            did "$rel を uid=$WWW_UID / 640 にしました"
        fi
    fi
done

if [ "$CONFIG_FOUND" = "0" ]; then
    bad 'config/*.local.php が1つもありません。ここは配備されないので、手で置く必要があります'
    note '  見本: src/config/*.example.php / 手順: docs/09-new-host.ipynb'
fi

# **作らない。** 秘密を含むファイルを、こちらが空で作って「在る」ことにしない
for name in db logto-m2m recaptcha; do
    [ -e "$SRC/config/$name.local.php" ] || warn "config/$name.local.php がありません(機能が1つ止まります)"
done

# **PHP が書く 2 つだけは、無ければ空の設定で作る**(2026-09-15)。
#
# compose は src を読み取り専用で渡し、この 2 つだけを**ファイル単位で**書ける形で重ねる。
# ホストに無いまま up すると、**Docker がその場所にディレクトリを作る** —— 設定がディレクトリになり、
# 管理画面からの保存(アクセスコード・地図の錠)が必ず失敗する。空の設定は「未設定」と同じに読まれる
# (lib/app-map.php・lib/map-access.php)。中身は管理画面から入れる。
for name in map-access app-map; do
    rel="config/$name.local.php"
    if [ -d "$SRC/$rel" ]; then
        bad "$rel が**ディレクトリ**になっています(ファイルが無いまま up した跡)。中を確かめてから消し、--fix で作り直してください"
    elif [ ! -e "$SRC/$rel" ]; then
        if [ "$FIX" = "1" ]; then
            as_root "printf '<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n' > '/p/src/$rel' && chown $WWW_UID:$KEEP_GID '/p/src/$rel' && chmod 640 '/p/src/$rel'"
            did "$rel を空の設定で作りました(中身は管理画面から)"
        else
            warn "$rel がありません —— **この状態で up すると Docker がディレクトリを作ります**(--fix で空の設定を作ります)"
        fi
    fi
done

# --- uploads/ ---
#
# PHP が書く。ディレクトリは 750、中身は 640。

if [ -d "$SRC/uploads" ]; then
    if check_owner_mode "$SRC/uploads" "$WWW_UID" 750 'uploads/'; then :; else
        if [ "$FIX" = "1" ]; then
            as_root "chown -R $WWW_UID:$KEEP_GID /p/src/uploads && chmod 750 /p/src/uploads && find /p/src/uploads -type f -exec chmod 640 {} +"
            did 'uploads/ を www-data の持ち物にしました'
        fi
    fi
    # **中のファイルも見る。** 控え(host-backup.sh・門番の backup)はログイン利用者の権限で tar するので、
    # 1 つでも読めないと控えがまるごと失敗する。2026-09-18、控えから戻した検証機で画像のグループが root になっていて、
    # ディレクトリだけ見るこの確認は OK と言い、kmops の控えが「Permission denied」で落ちた
    unreadable="$(find "$SRC/uploads" -type f ! -readable 2>/dev/null | wc -l)"
    if [ "$unreadable" -eq 0 ]; then :; elif [ "$FIX" = "1" ]; then
        as_root "chgrp -R $KEEP_GID /p/src/uploads && find /p/src/uploads -type f -exec chmod g+r {} +"
        did "uploads/ の中で読めなかった $unreadable 件をこの利用者のグループで読めるようにしました(控えのため)"
    else
        warn "uploads/ の中に、この利用者が読めないファイルが $unreadable 件あります(控えが失敗します。--fix で直します)"
    fi
else
    if [ "$FIX" = "1" ]; then
        as_root "mkdir -p /p/src/uploads && chown $WWW_UID:$KEEP_GID /p/src/uploads && chmod 750 /p/src/uploads"
        did 'uploads/ を作りました'
    else
        warn 'uploads/ がありません(--fix で作ります)'
    fi
fi

# --- cache/ ---
#
# JWKS の置き場。**予測できる場所に書けると鍵束を先置きされる**ので 700。

if [ -d "$SRC/cache" ]; then
    if check_owner_mode "$SRC/cache" "$WWW_UID" 700 'cache/'; then :; else
        if [ "$FIX" = "1" ]; then
            as_root "chown -R $WWW_UID:$KEEP_GID /p/src/cache && chmod 700 /p/src/cache"
            did 'cache/ を www-data の持ち物にしました'
        fi
    fi
else
    if [ "$FIX" = "1" ]; then
        as_root "mkdir -p /p/src/cache && chown $WWW_UID:$KEEP_GID /p/src/cache && chmod 700 /p/src/cache"
        did 'cache/ を作りました'
    else
        # **PHP はもう作れない**(2026-09-15 から src は読み取り専用で渡す)。無いまま up すると Docker が root の持ち物で作り、
        # PHP が JWKS も Management API のトークンも置けず、管理画面が「判定できない」で止まる
        warn 'cache/ がありません —— **src は読み取り専用なので PHP は作れません**(--fix で作ります)'
    fi
fi

# ---------------------------------------------------------------- 証明書
#
# **nginx と MariaDB は、証明書のファイルが無いと起動できず、再起動を繰り返す**(restart: unless-stopped)。
# 2026-09-17 に LAN の検証機へ本番の構成を持ち込んで、実際にそうなった(Let's Encrypt が取れず、
# certs/ も空だった)。しかも無いパスを bind mount すると Docker が**ディレクトリを作る**ので、
# 2 回目からは「証明書のはずがディレクトリ」になる。**up の前にここで止める。**
#
#   ローカル環境(.env の KM_ENV=local) … certs/ の自作 CA と証明書(scripts/host-local.sh init が作る)
#   本番(compose.vps.yaml を読む)      … 上に加えて Let's Encrypt の live/<KM_DOMAIN>/

head_ '証明書(nginx と MariaDB が起動に使うもの)'

env_line() {
    sed -n "s/^$1=//p" "$PROJECT/.env" 2>/dev/null | tail -n 1 | tr -d '\r' | sed "s/^\"\\(.*\\)\"\$/\\1/; s/^'\\(.*\\)'\$/\\1/"
}
KM_ENV_VALUE="$(env_line KM_ENV)"
CERT_FILES='rootCA.pem mariadb-server.pem mariadb-server-key.pem'
if [ "$KM_ENV_VALUE" = "local" ]; then
    CERT_FILES="$CERT_FILES local-server.pem local-server-key.pem"
    ok "ローカル環境(KM_ENV=local)。Let's Encrypt は使いません(scripts/host-local.sh status)"
fi
for f in $CERT_FILES; do
    p="$PROJECT/certs/$f"
    if [ -d "$p" ]; then
        bad "certs/$f が**ディレクトリ**です(ファイルが無いまま up した跡)。中を確かめて退避し、作り直してください"
    elif [ -f "$p" ]; then
        ok "certs/$f"
    elif [ "$KM_ENV_VALUE" = "local" ]; then
        bad "certs/$f がありません —— **この状態で up すると再起動を繰り返します**(scripts/host-local.sh init で作る)"
    else
        bad "certs/$f がありません —— **この状態で up すると再起動を繰り返します**(本番の作り方は Old/vps-bootstrap.sh の 7。LAN の検証機なら scripts/host-local.sh init)"
    fi
done
if [ "$KM_ENV_VALUE" != "local" ]; then
    case ":$(env_line COMPOSE_FILE):" in
        *:compose.vps.yaml:*)
            KM_DOMAIN_VALUE="$(env_line KM_DOMAIN)"
            if [ -z "$KM_DOMAIN_VALUE" ]; then
                bad '.env に KM_DOMAIN がありません'
            elif docker run --rm -v test_letsencrypt:/le:ro alpine test -f "/le/live/$KM_DOMAIN_VALUE/fullchain.pem" 2>/dev/null; then
                ok "Let's Encrypt の証明書: live/$KM_DOMAIN_VALUE/"
            else
                bad "Let's Encrypt の証明書(live/$KM_DOMAIN_VALUE/)がありません —— **この状態で up すると nginx が再起動を繰り返します**。本番なら scripts/host-cert.sh issue、LAN の検証機なら scripts/host-local.sh init"
            fi
            ;;
    esac
fi

# ---------------------------------------------------------------- ホスト側スクリプト
#
# **配備は実行ビットを持ってこない。** 手元は Windows なので、tar に入る時点で 644。
# ホストに置かれた `.sh` は**そのままでは叩けない** ——
# `sudo /opt/kosenmap/scripts/host-security-check.sh` は
# 「Permission denied」ではなく **`command not found`** と出るので、
# 「配備されていない」と読み違える(実際にそう読まれた、2026-09-07)。
#
# **ここで直す。** `host-updates-setup.sh --fix` でも直せるが、
# **それ自体が実行できない**ので鶏と卵になる。
# この `host-setup.sh` は標準入力から流し込まれる(実行ビットが要らない)ので、
# 輪を断てるのはここだけ。

head_ 'ホスト側スクリプト'

# cron から呼ぶもの・事故のときに叩くもの・証明書とドメインを扱うもの。deploy-to-host.ps1 の $include と対
# (host-cert.sh は cron が send-log.sh 越しに呼ぶ。実行ビットが無いと毎日「失敗」のメールになる)
HOST_SCRIPTS='check-updates.sh host-security-check.sh host-updates-setup.sh host-emergency.sh host-backup.sh send-log.sh host-cert.sh host-domain.sh host-local.sh ssh-backup-gate.sh host-ops-user.sh'

for _hs in $HOST_SCRIPTS; do
    if [ ! -f "$PROJECT/scripts/$_hs" ]; then
        # **`\$include` を逃がす。** 素のままだと set -u の下で「include: parameter not set」になり、
        # 足りないスクリプトを報告する前にこの後片付けごと止まる(shellcheck SC2154。2026-09-14)
        bad "scripts/$_hs がありません(配備の deploy-to-host.ps1 の \$include に入っていますか)"
        continue
    fi
    if [ -x "$PROJECT/scripts/$_hs" ]; then
        ok "scripts/$_hs は実行できます"
    elif [ "$FIX" = "1" ]; then
        as_root "chmod +x /p/scripts/$_hs"
        did "scripts/$_hs を実行できるようにしました"
    else
        bad "scripts/$_hs を実行できません(--fix で直します)"
    fi
done

# ---------------------------------------------------------------- 退役したファイル
#
# **配備は消さない。** `deploy-to-host.ps1` は tar を展開するだけなので、
# 役目を終えて手元から外したファイルは**ホストに残り続ける**。
# 読み込んでいないので実害は出にくいが、
#   - 自己検査が毎回 NG を出す(本物の失敗がその中に埋もれる)
#   - 「まだ使っている」と誤解されて、次に触る人が読みに行く
# ので、片付けは1箇所にまとめる。
#
# **一覧に書いたものだけを消す。** パターンで消さない ——
# 書き間違えたときに、生きているファイルまで巻き添えにする。
# 消した経緯は docs/plan.md に残っているものだけを並べること。
RETIRED="src/Main/zoom.js"

head_ '退役したファイル'

RETIRED_FOUND=0
for _retired in $RETIRED; do
    if [ ! -e "$PROJECT/$_retired" ]; then
        continue
    fi
    RETIRED_FOUND=$((RETIRED_FOUND + 1))
    if [ "$FIX" = "1" ]; then
        as_root "rm -f /p/$_retired"
        did "$_retired を片付けました(役目を終えたもの)"
    else
        warn "$_retired が残っています(--fix で片付けます)"
    fi
done
if [ "$RETIRED_FOUND" = "0" ]; then
    ok '残っていません'
fi

# ---------------------------------------------------------------- composer

if [ "$DO_COMPOSER" = "1" ]; then
    head_ 'composer'
    # **web イメージでは通らない。** zip 拡張も unzip も無く dist を展開できない。
    # -u で自分の uid にしないと vendor/ が root 所有になり、あとで消せなくなる。
    docker run --rm -v "$SRC:/app" -u "$(id -u):$(id -g)" composer:2 \
        install --no-dev --no-interaction
    did 'vendor/ を入れ直しました'
elif [ ! -d "$SRC/vendor" ]; then
    head_ 'composer'
    bad 'src/vendor/ がありません。--composer を付けて実行してください'
fi

# ---------------------------------------------------------------- 起動

if [ "$DO_UP" = "1" ]; then
    head_ 'コンテナ'
    (cd "$PROJECT" && docker compose up -d)
    did 'docker compose up -d を実行しました'
    # 立ち上がりを待つ。ここで急いで検査すると「起動途中」を故障と読み違える
    sleep 5
fi

# ---------------------------------------------------------------- 検査

head_ '確認'

# **stat が通っても、PHP が読めるとは限らない。**
# 実際に読むのはコンテナの中の www-data なので、その目で確かめる。
# 「置けたのに動かない」を見逃さないための、ここが本題。
if web_running; then
    for name in db logto-m2m map-access app-map recaptcha; do
        f="$SRC/config/$name.local.php"
        [ -e "$f" ] || continue
        if docker exec "$WEB_CONTAINER" test -r "/var/www/html/config/$name.local.php"; then
            ok "PHP から config/$name.local.php を読めます"
        else
            bad "PHP から config/$name.local.php を読めません"
        fi
    done

    # **map-access.local.php だけは書き込みも要る。** 管理画面の「地図データ公開設定」が
    # ここを書き換える。読めるだけだと、保存のときにだけ失敗する
    if [ -e "$SRC/config/map-access.local.php" ]; then
        if docker exec "$WEB_CONTAINER" test -w /var/www/html/config/map-access.local.php; then
            ok 'PHP から config/map-access.local.php へ書けます(地図データ公開設定の保存)'
        else
            bad 'PHP から config/map-access.local.php へ書けません —— 保存だけが失敗します'
        fi
    fi

    # **ファイル単位の bind が外れていないか**(2026-09-17 に検証機で踏んだ)。
    # web が動いている間にホストでそのファイルを消す・置き換える(ディレクトリだったものを作り直す等)と、
    # カーネルがコンテナ側の bind を外す。docker inspect には残るが、中は src の :ro の方が見え、
    # 持ち主と権限が正しくても**書けない**。web を作り直せば付き直す
    for name in map-access app-map; do
        [ -f "$SRC/config/$name.local.php" ] || continue
        if ! docker exec "$WEB_CONTAINER" grep -q " /var/www/html/config/$name.local.php " /proc/self/mountinfo; then
            bad "config/$name.local.php の bind が web から外れています —— docker compose up -d --force-recreate web で付き直します"
        fi
    done

    if docker exec "$WEB_CONTAINER" test -w /var/www/html/uploads; then
        ok 'PHP から uploads/ へ書けます'
    else
        bad 'PHP から uploads/ へ書けません —— アップロードと地図配信が止まります'
    fi

    # 見取り図。**全角のファイル名が配備で崩れていないか**を実数で見る
    IMAGES="$(docker exec "$WEB_CONTAINER" sh -c \
        'find /var/www/html/Main/Picture -maxdepth 1 -name "*.png" -type f 2>/dev/null | wc -l' | tr -d '\r')"
    if [ "$IMAGES" = "6" ]; then
        ok "見取り図が6枚とも見えます"
    else
        bad "見取り図が ${IMAGES} 枚しかありません(6 が正解。全角ファイル名が崩れた可能性)"
    fi

    # 自己検査。DB も Logto も要らないので、ここで通ることに意味がある
    if docker exec "$WEB_CONTAINER" php /var/www/html/scripts/check.php >/tmp/km-check.log 2>&1; then
        ok "自己検査が通りました($(grep -o 'すべて通過 ([0-9]* 件)' /tmp/km-check.log || echo '件数不明'))"
    else
        bad '自己検査が落ちました。詳細:'
        grep -E 'FAIL|失敗' /tmp/km-check.log | head -n 20 | sed 's/^/       /'
    fi
    rm -f /tmp/km-check.log
else
    warn 'web コンテナが止まっているため、PHP からの確認はできません'
fi

# 管理ポートへ入れる送信元。
#
# **個人の固定 IP は配備されない。** ホスト側にしか無いので、
# 配備し直したあとに消えていないかを見る。
#
# **無いことは不具合ではない。** 固定 IP を書かず SSH 越しだけで入る運用は、
# むしろ安全な側 —— 回線が変わっても締め出されないし、所在情報も残らない。
# 以前ここを warn にしていたので、**正しく運用しているのに毎回「気になる点」が
# 出ていた。** 数えるべきでない指摘を数えると、出力そのものが読まれなくなる。
if ls "$SRC/../nginx/km"/allow-*.local.conf >/dev/null 2>&1; then
    ok "管理ポートの許可(allow-*.local.conf)が置かれています"
else
    ok '固定 IP の許可ファイルはありません(SSH 越しだけで入る運用)'
    note '  直接入りたい場合の見本: nginx/km/allow-admin-home.local.conf.example'
    note '  SSH 越しに入るときは **-D(SOCKS)** を使うこと:'
    note '    ssh -D 1080 <利用者>@<ホスト>'
    note '    → ブラウザの SOCKS5 プロキシを localhost:1080 に(DNS もプロキシ側で引く)'
    note '    → そのまま https://<ホスト>:8281 / :3002 を開く'
    #
    # **-L(ポート転送)では通れない。** 宛先が localhost になり、
    # ito4.jp の Cookie が送られないので Logto のゲートで必ず弾かれる
    # —— ログイン画面を永久に回る(2026-09-03 に実際に起きた)。
    note '  -L(ポート転送)では通れません。宛先が localhost になり、'
    note '  セッションの Cookie が送られないためログイン画面を回り続けます。'
fi

# nginx の設定。**配ったあと必ず見る** —— 書式を誤ると次の再起動で上がらなくなる
if [ -n "$(docker ps -q -f "name=^${PROXY_CONTAINER}$" 2>/dev/null)" ]; then
    if docker exec "$PROXY_CONTAINER" nginx -t >/dev/null 2>&1; then
        ok 'nginx の設定は妥当です'
    else
        bad 'nginx の設定に誤りがあります:'
        docker exec "$PROXY_CONTAINER" nginx -t 2>&1 | sed 's/^/       /'
    fi
else
    warn "$PROXY_CONTAINER が動いていません"
fi

# ---------------------------------------------------------------- まとめ

head_ 'まとめ'

if [ "$FIX" = "0" ]; then
    note '調べただけで、何も変えていません。直すには --fix を付けてください。'
fi
note "直した数: $CHANGES / 気になる点: $PROBLEMS"

if [ "$PROBLEMS" -gt 0 ]; then
    printf '\n\033[33m気になる点が残っています。上の NG と 注意 を確認してください。\033[0m\n'
    exit 1
fi

printf '\n\033[32mすべて問題ありません。\033[0m\n'
