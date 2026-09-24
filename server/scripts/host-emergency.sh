#!/bin/sh
#
# もしものときに、まず1本これを叩く。**何も直さない。何も止めない。**
#
#   ./host-emergency.sh                      いまどうなっているかを見る
#   ./host-emergency.sh --collect            同じ内容をファイルにも残す(添付して送れる)
#   ./host-emergency.sh --collect /tmp/a.txt 出力先を指定する
#   ./host-emergency.sh --logs 200           落ちているコンテナのログを 200 行見る
#
# ## なぜ「直さない」のか
#
# 事故の最中に自動で手を出すと、**何が起きていたのかが消える。**
# 直した結果しか残らないので、次に同じことが起きたときにまた一から調べることになる。
# ここは「見て、そのまま残して、打つ手を出す」までにする。
# 実際に打つのは人 —— 出力の最後に、そのまま貼れる形で並べてある。
#
# ## メールで送らない
#
# 通知(check-updates.sh / host-security-check.sh)は web コンテナ経由でメールを出すが、
# **もしもの時に落ちているのはその web かもしれない。**
# ここは外に頼らず、手元に出すことだけをする。
#
# POSIX sh。Ubuntu の /bin/sh は dash なので、**bash だけの書き方を混ぜない**
# (`>(…)` のプロセス置換は構文解析の時点で落ち、1行も走らないまま終わる)。
# `[ … ] && cmd` を単独で書かない(set -e の下で落ちる)。
#
# 調べもの1つが失敗しても最後まで進むこと —— **途中で止まると、
# 一番見たい下の方が出ないまま終わる。** 各行に `|| true` を付けてあるのはそのため。

set -eu

# **--collect のファイルを他人に読ませない。** 中身は止まったコンテナのログとシステムのエラー。
# 指定していなかったので、sudo で走らせると root の umask(022)で**誰でも読める 644** になり、
# /tmp に残り続けていた(2026-09-14 の診断)。host-backup.sh と同じく 077 にする
umask 077

DEFAULT_ARGS=""

PATH_ROOT="/opt/kosenmap"
COLLECT=0
COLLECT_PATH=""
LOG_LINES=60
CERT_WARN_DAYS=21

if [ -n "$DEFAULT_ARGS" ]; then
  set -- $DEFAULT_ARGS "$@"
fi

while [ $# -gt 0 ]; do
  case "$1" in
    --path) PATH_ROOT="$2"; shift 2 ;;
    --logs) LOG_LINES="$2"; shift 2 ;;
    --collect)
      COLLECT=1
      # 次が別の指定(--…)なら、出力先は既定にする
      if [ $# -ge 2 ] && [ "${2#--}" = "$2" ]; then
        COLLECT_PATH="$2"
        shift 2
      else
        shift
      fi
      ;;
    *) echo "知らない引数: $1" >&2; exit 2 ;;
  esac
done

if [ "$COLLECT" -eq 1 ] && [ -z "$COLLECT_PATH" ]; then
  COLLECT_PATH="/tmp/kosenmap-emergency-$(date +%Y%m%d-%H%M%S).txt"
fi

heading() { echo ""; echo "===== $1 ====="; }
run() { echo "\$ $*"; "$@" 2>&1 || true; echo ""; }

# そのサービスが動いているか。
#
# **`docker compose ps --format` や `--status` に頼らない。** compose の版で
# 受ける書き方が違う(v2 は --format に Go テンプレートを受けない)。
# id を採って inspect する形なら、どの版でも同じ答えになる
# —— check-updates.sh が同じ理由で同じ書き方をしている。
service_running() {
  _cid="$(docker compose ps -q "$1" 2>/dev/null | head -n 1 || true)"
  if [ -z "$_cid" ]; then
    return 1
  fi
  _state="$(docker inspect -f '{{.State.Running}}' "$_cid" 2>/dev/null || echo false)"
  if [ "$_state" = "true" ]; then
    return 0
  fi
  return 1
}

# そのサービスの健康状態。
#
# **中の人に叩きに行かない。** 最初はそうしていて、2つとも外した(実測、2026-09-07):
#
#   - サービス名を決め打ちしていた。この構成の逆プロキシは `nginx` ではなく
#     **`reverse-proxy`** で、`service "nginx" is not running` としか出なかった
#     —— 落ちているのか名前が違うのか、出力からは読み分けられない
#   - `mariadb-admin ping` は資格情報が要る。**Access denied** が返ってきて、
#     サーバーは生きているのに「駄目そう」に見えた
#
# compose は既に healthcheck を持っている。**その答えをそのまま出す。**
service_health() {
  _cid="$(docker compose ps -q "$1" 2>/dev/null | head -n 1 || true)"
  if [ -z "$_cid" ]; then
    echo "  ★ $1  居ません"
    return 0
  fi
  _health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}健康診断なし{{end}}' "$_cid" 2>/dev/null || echo 不明)"
  _running="$(docker inspect -f '{{.State.Running}}' "$_cid" 2>/dev/null || echo false)"
  case "$_health" in
    healthy)   echo "  $1  健全" ;;
    starting)  echo "  $1  起動中" ;;
    unhealthy) echo "  ★ $1  不健全" ;;
    *)
      if [ "$_running" = "true" ]; then
        echo "  $1  動いています($_health)"
      else
        echo "  ★ $1  止まっています"
      fi
      ;;
  esac
}

main() {
  echo "KosenMap 緊急時の調べもの"
  echo "日時: $(date '+%Y-%m-%d %H:%M:%S %z' 2>/dev/null || true)"
  echo "ホスト: $(hostname 2>/dev/null || echo unknown)"
  echo "対象: $PATH_ROOT"

  heading "1. ホストの様子"
  run uptime
  if command -v free >/dev/null 2>&1; then
    run free -h
  fi
  run df -h /
  # **Docker のデータ置き場を別に見る。** / に余裕があっても、
  # ここが満杯だとコンテナは書けずに落ちる
  if [ -d /var/lib/docker ]; then
    run df -h /var/lib/docker
  fi
  if [ -f /var/run/reboot-required ]; then
    echo "★ 再起動の要求が置かれています(host-security-check.sh も参照)"
    cat /var/run/reboot-required.pkgs 2>/dev/null || true
    echo ""
  fi

  heading "2. コンテナ"
  if ! command -v docker >/dev/null 2>&1; then
    echo "★ docker がありません。ここから先は調べられません。"
    return 0
  fi
  if ! cd "$PATH_ROOT" 2>/dev/null; then
    echo "★ $PATH_ROOT へ移動できません。--path を確かめてください。"
    return 0
  fi

  run docker compose ps
  run docker system df

  # **上がっていないものを名指しする。** 表を目で追わせない ——
  # 事故の最中に読み解く負担を増やさない
  echo "-- 上がっていないもの --"
  DOWN=""
  for _svc in $(docker compose config --services 2>/dev/null || true); do
    if ! service_running "$_svc"; then
      echo "  ★ $_svc"
      if [ -z "$DOWN" ]; then
        DOWN="$_svc"
      else
        DOWN="$DOWN $_svc"
      fi
    fi
  done
  if [ -z "$DOWN" ]; then
    echo "  ありません(すべて動いています)"
  fi
  echo ""

  # 落ちているものだけログを出す。**全部出すと本題が埋まる**
  for _svc in $DOWN; do
    echo "-- $_svc の直近 $LOG_LINES 行 --"
    docker compose logs --tail "$LOG_LINES" "$_svc" 2>&1 || true
    echo ""
  done

  heading "3. 健康状態"
  for _svc in $(docker compose config --services 2>/dev/null || true); do
    service_health "$_svc"
  done

  echo ""
  echo "-- 外から見た応答(このホスト自身から) --"
  # **中から聞くだけでは足りない。** 全部 healthy でも、前段で止まっていれば
  # 誰も入れない。実際に TLS を張って番号を貰う
  if command -v curl >/dev/null 2>&1; then
    for _url in http://127.0.0.1/ https://127.0.0.1/; do
      _code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "$_url" 2>/dev/null || echo 000)"
      if [ "$_code" = "000" ]; then
        echo "  ★ $_url  応答なし"
      else
        echo "  $_url  $_code"
      fi
    done
  else
    echo "  curl がありません"
  fi

  heading "4. 証明書の残り"
  # **実際に配っている証明書を見る。** ファイルを並べるだけでは、
  # どれが使われているのか分からない(この構成は certbot の分が別の場所に在る)
  if ! command -v openssl >/dev/null 2>&1; then
    echo "  openssl がありません"
  else
    _served="$(echo | openssl s_client -connect 127.0.0.1:443 2>/dev/null \
      | openssl x509 -noout -subject -enddate 2>/dev/null || true)"
    if [ -n "$_served" ]; then
      echo "-- いま配っているもの --"
      printf '%s\n' "$_served" | sed 's/^/  /'
      _served_end="$(printf '%s\n' "$_served" | sed -n 's/^notAfter=//p')"
      if [ -n "$_served_end" ]; then
        _e="$(date -d "$_served_end" +%s 2>/dev/null || echo 0)"
        if [ "$_e" -gt 0 ]; then
          _d=$(( (_e - $(date +%s)) / 86400 ))
          if [ "$_d" -lt "$CERT_WARN_DAYS" ]; then
            echo "  ★ あと ${_d} 日"
          else
            echo "  あと ${_d} 日"
          fi
        fi
      fi
      echo ""
    fi

    if [ -d "$PATH_ROOT/certs" ]; then
      echo "-- certs/ に置いてあるもの --"
      for _pem in "$PATH_ROOT"/certs/*.pem; do
        if [ ! -f "$_pem" ]; then
          continue
        fi
        _end="$(openssl x509 -in "$_pem" -noout -enddate 2>/dev/null | sed 's/^notAfter=//' || true)"
        if [ -z "$_end" ]; then
          continue    # 鍵ファイル(-key.pem)は証明書ではない
        fi
        _end_epoch="$(date -d "$_end" +%s 2>/dev/null || echo 0)"
        _days=$(( (_end_epoch - $(date +%s)) / 86400 ))
        if [ "$_end_epoch" -eq 0 ]; then
          echo "  $(basename "$_pem")  期限を読めません"
        elif [ "$_days" -lt 0 ]; then
          echo "  ★ $(basename "$_pem")  $(( 0 - _days )) 日前に切れています"
        elif [ "$_days" -lt "$CERT_WARN_DAYS" ]; then
          echo "  ★ $(basename "$_pem")  あと ${_days} 日"
        else
          echo "  $(basename "$_pem")  あと ${_days} 日"
        fi
      done
    fi
  fi

  heading "5. システムのログ(直近のエラーだけ)"
  if command -v journalctl >/dev/null 2>&1; then
    _log="$(journalctl -p err -n 400 --no-pager 2>/dev/null || true)"
    # **SSH への総当たりで埋めない。** インターネットに出ているホストでは
    # 1日に数千件出る。そのまま並べると、**本題が 40 行の外へ押し出される**
    # (実測: 直近 40 件がすべて sshd の preauth だった、2026-09-07)。
    # 件数だけ出して、中身は host-security-check.sh に任せる。
    _noise_pattern='sshd.*(preauth|Invalid user|Failed password|Connection reset by peer)'
    _noise="$(printf '%s\n' "$_log" | grep -Ec "$_noise_pattern" || true)"
    _rest="$(printf '%s\n' "$_log" | grep -Ev "$_noise_pattern" || true)"
    if [ "${_noise:-0}" -gt 0 ]; then
      echo "  SSH への総当たり: ${_noise} 件(この一覧からは外しました)"
      echo "  → 入口の設定は host-security-check.sh が見ます"
      echo ""
    fi
    if [ -z "$(printf '%s' "$_rest" | tr -d '[:space:]')" ]; then
      echo "  ほかにエラーはありません"
    else
      printf '%s\n' "$_rest" | tail -n 30
    fi
  else
    tail -n 40 /var/log/syslog 2>/dev/null || echo "  読めるログがありません"
  fi

  heading "6. 打つ手"
  cat <<'STEPS'
上から順に試すこと。**下へ行くほど戻しにくくなる。**

1) まず1つだけ立て直す(いちばん軽い)
     cd /opt/kosenmap
     docker compose up -d <サービス名>
     docker compose logs --tail 100 <サービス名>

2) 管理画面に入れない / ログインできない
   Logto が落ちると、nginx の auth_request ごと死にます。
   管理画面から直そうとしないこと —— その入口自体が Logto に依っています。
     docker compose up -d postgres logto
     docker compose logs --tail 200 logto
   起動には時間がかかります(初回は DB の移行が走る)。90 秒は待つこと。

3) ディスクが満杯
     docker system df
     docker image prune -f          # 使っていないイメージだけ
     sudo journalctl --vacuum-time=14d
   volume は消さないこと。**データそのものです。**

4) 更新を当てた直後におかしくなった
   版を戻します。compose.yaml の image: を前の版へ書き戻してから:
     docker compose up -d
   ただし Logto は**起動時に DB の移行を走らせる**ので、版を戻すだけでは戻りません。
   その場合はバックアップからの復元になります(5)。

5) 復元
   バックアップは手元の Windows から取っています:
     scripts/backup-data.ps1
   復元はデータを上書きします。**先に今の状態を1つ取ってから**行うこと ——
   壊れていても、取っておけば後から調べられます。

6) 再起動が要ると出ていた場合
     sudo reboot
   戻ったら docker compose ps で全部 up になっているか、
   実際にログインできるかまで確かめること。

--- 記録を残す ---
この出力をファイルにも残すには:
     ./host-emergency.sh --collect
STEPS
}

# **まとめるときは1本のパイプで tee に渡す。**
# `exec > >(tee …)` はプロセス置換で、dash では構文解析の時点で落ちる。
if [ "$COLLECT" -eq 1 ]; then
  main | tee "$COLLECT_PATH"
  echo ""
  echo "まとめました: $COLLECT_PATH"
  echo "作った本人だけが読めます。送り終えたら消すこと: rm -f $COLLECT_PATH"
else
  main
fi
