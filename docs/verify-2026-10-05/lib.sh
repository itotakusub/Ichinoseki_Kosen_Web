# 検証の共通部品。各スクリプトの先頭で `source "$(dirname "$0")/lib.sh"` する。
#
# 判定は 3 つだけ。**推測で埋めない。**
#   REPRO … 所見が成り立つ(脆弱性・不具合が再現した)
#   FIXED … 所見が成り立たない(直っている)
#   ERROR … 検証そのものの失敗(サーバーが居ない・前提の操作が通らない・比較の対照がおかしい)。終了コード 2
#
# 2026-09-25 版(docs/verify-2026-09-25)は合否を自動で出さず、出力を目で読んで判定していた。
# そのため「環境が壊れていても、壊れていない時と同じ見た目の出力になる」箇所があった(この版の README を参照)。
set -uo pipefail
V="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$V"
# shellcheck disable=SC1091
source "$V/env.sh"
SUMMARY="$V/results/summary.tsv"
B=http://127.0.0.1:3900
CUR="${CUR:-?}"

oneline() { printf '%s' "$1" | tr '\r\n\t' '   '; }

verdict() { # $1=番号 $2=REPRO|FIXED $3=理由
    local label
    case "$2" in
        REPRO) label="成り立つ" ;;
        FIXED) label="成り立たない" ;;
        *) die "$1" "判定の値が不正です: $2" ;;
    esac
    printf '%s\t%s\t%s\n' "$1" "$2" "$(oneline "$3")" >> "$SUMMARY"
    echo "[判定] $1: $label —— $3"
}

# die() がコマンド置換 $(...) の中(サブシェル)で呼ばれても、スクリプト本体まで止める。
# 以前は `x=$(q ...)` の q が失敗すると、止まるのはサブシェルだけで本体は続き、
# ERROR の行の後に誤った判定の行まで書いた(2026-10-05、n_xss_rank.sh で発覚)。
trap 'exit 2' TERM
die() { # $1=番号 $2=理由。検証の失敗として記録し、終了コード 2 で抜ける
    printf '%s\tERROR\t%s\n' "$1" "$(oneline "$2")" >> "$SUMMARY"
    echo "[検証の失敗] $1: $2" >&2
    if [ "${BASHPID:-$$}" != "$$" ]; then kill -TERM "$$" 2>/dev/null; fi
    exit 2
}

q() { # DB に問い合わせる。失敗したら検証の失敗
    local out
    out=$(docker exec kmdb mariadb -uroot -prootpw Kosen_map -N -e "$1" 2>&1) || die "$CUR" "DB に問い合わせられません: $out"
    printf '%s' "$out"
}

# http METHOD PATH [curl の引数...] → HTTP_CODE / HTTP_BODY / HTTP_HEADERS
http() {
    local m=$1 p=$2 tmp
    shift 2
    tmp=$(mktemp -d)
    HTTP_CODE=$(curl -sS --noproxy '*' -X "$m" -D "$tmp/h" -o "$tmp/b" -w '%{http_code}' "$@" "$B$p" 2>"$tmp/e") \
        || { local e; e=$(cat "$tmp/e"); rm -rf "$tmp"; die "$CUR" "curl が失敗しました: $m $p $e"; }
    HTTP_BODY=$(cat "$tmp/b")
    HTTP_HEADERS=$(cat "$tmp/h")
    rm -rf "$tmp"
}

expect_code() { # $1=期待する HTTP の状態 $2=何をしたか
    [ "$HTTP_CODE" = "$1" ] || die "$CUR" "$2: HTTP $HTTP_CODE(期待 $1)。本文: ${HTTP_BODY:0:300}"
}

expect_redirect() { # $1=Location に含まれるべき文字列 $2=何をしたか
    local loc
    loc=$(printf '%s' "$HTTP_HEADERS" | tr -d '\r' | awk -F': ' 'tolower($1)=="location"{print $2}')
    [ "$HTTP_CODE" = "302" ] && [[ "$loc" == *"$1"* ]] || die "$CUR" "$2: HTTP $HTTP_CODE Location=${loc:-なし}(期待: 302 で $1)"
}

session_cookie() { # 直前の応答の Set-Cookie からセッション ID を取り出す
    printf '%s' "$HTTP_HEADERS" | tr -d '\r' | sed -nE 's/^[Ss]et-[Cc]ookie: __Host-KMSID=([^;]+).*/\1/p' | tail -n 1
}

# jget KEY... : 標準入力の JSON から値を出す。JSON でなければ検証の失敗。無いキーは __MISSING__
jget() {
    local out
    out=$(php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($d)) { fwrite(STDERR, "JSON ではありません"); exit(3); }
        $v = $d;
        foreach (array_slice($argv, 1) as $k) {
            if (is_array($v) && array_key_exists($k, $v)) { $v = $v[$k]; } else { echo "__MISSING__"; exit(0); }
        }
        echo is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    ' "$@" 2>&1) || die "$CUR" "応答が JSON ではありません: ${out:0:200}"
    printf '%s' "$out"
}

need_servers() { # 検証用サーバーと DB が居ることを確かめる
    curl -fsS --noproxy '*' -o /dev/null http://127.0.0.1:3901/oidc/.well-known/openid-configuration 2>/dev/null \
        || die "$CUR" "偽の Logto(:3901)が応答しません。servers.sh を先に"
    local c
    c=$(curl -sS --noproxy '*' -o /dev/null -w '%{http_code}' "$B/api/map-data.php" 2>/dev/null) || c=000
    [ "$c" = "200" ] || die "$CUR" "Web(:3900)の /api/map-data.php が HTTP $c(期待 200)。servers.sh と DB を確かめて"
}

# 設定ファイル(require で読む PHP。config/*.local.php)を書き換えた後に呼ぶ。
# 検証用の php -S(と本番の Apache)は OPcache が有効で revalidate_freq=2 秒なので、それより長く待たないと古い設定が使われうる。
# アプリは opcache_invalidate を呼ばない(2026-10-05 に確認)。
config_changed() { sleep 3; }
