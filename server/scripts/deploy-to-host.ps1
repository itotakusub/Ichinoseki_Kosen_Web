<#
.SYNOPSIS
  この作業ツリーの server/ を本番(ito4.jp)へ配備する。

.DESCRIPTION
  tar でまとめて scp し、ホスト側で展開する。**削除は一切しない**ので、ホストにしか
  無いファイル(.env / 証明書 / アップロード済みファイル / composer の vendor)が
  消える事故は起こらない。

  接続は SSH の公開鍵認証(パスフレーズ無しの専用鍵)。

  ## 配備先は本番だけ(2026-08-29)

  以前は `-Target staging` で校内 LAN の 192.168.3.29 へも出せたが、
  **そちらへの配備はもう行わないので消した。** 別のホストへ出す必要が生じたら
  `-HostName` / `-User` / `-KeyPath` / `-RemotePath` を直接渡す。

  **行き先が1つになったぶん、打ち間違いを止める仕掛けが要る。**
  そのため転送の直前に確認を求める。自動実行など、聞かれては困る場面では `-Yes`。
  何が送られるかを見るだけなら `-WhatIfOnly`(接続もしない)。

  ## 後片付けまで込みで1コマンド(2026-09-03)

  転送が終わったら **`host-setup.ps1 -Fix` を自動で呼ぶ。**
  配備はファイルを置くだけで所有権に触れないが、`config/*.local.php` と `uploads/` は
  **www-data の持ち物でないと動かない**。そこを人の記憶に任せていたために、
  「設定の保存だけ失敗する」「地図を送れない」が実際に起きた。
  **忘れると壊れる手順は、忘れられる形にしておかない。**

  1本のファイルには融かしていない。host-setup.ps1 は `-Fix` 無しで
  「調べるだけ」にも使うので、融かすと**配備しないと点検できなくなる**。

  終了コード: `0` 全部成功 / `2` 転送は成功したが後片付けに気になる点 /
              `3` 配備は済んだがバックアップが取れなかった / `1` 転送の失敗

.PARAMETER Action
  deploy   ファイルを置くだけ(コンテナには触れない)。既定
  up       置いたあと docker compose up -d(compose.yaml を変えたときはこれ)
  restart  置いたあと指定サービスを restart(default.conf だけ変えたとき等)

.PARAMETER NoSetup
  後片付け(host-setup.ps1 -Fix)を呼ばない。**普段は付けない。**

.PARAMETER Backup
  配備が済んだあと、backup-data.ps1 を呼んでバックアップも取る。

  **配備の「あと」なのには理由がある。** 先に取ると、
  取った控えが**配備前のもの**になり、配備で壊れたときに戻す先が1つ古くなる。
  逆に配備で壊れたなら、その状態を控えても仕方がない ——
  だから後片付け(所有権と PHP からの読み書きの確認)が通ったときだけ取る。

.PARAMETER BackupOnly
  配備せず、バックアップだけ取る。自動実行から呼ぶとき用。

.PARAMETER OpenBackup
  取ったものをその場で解凍して残す(-Backup / -BackupOnly と一緒に使う)。

  **残るのは平文で、中に .env と config/*.local.php が入っている。**
  普段は付けないこと。付けたときは最後に置き場所と消し方を表示する。

.PARAMETER GitHub
  配備が済んだあと、GitHub の控えへも出す(2026-09-25、利用者の指示)。既定は all(Android と Website の両方)。
  web / android で片方だけ、none で出さない。中身は scripts\push-github-all.ps1
  (手元の原本を控えへ写す → 秘密の検査 → commit → push → プルリクエストの説明を開く)。

  **配備の「あと」に出す。** 本番に置けたものと GitHub の控えを揃えるため。
  **出せなくても配備の結果(終了コード)は変えない** —— 控えは控えで、本番はもう入れ替わっている。
  秘密の検査に当たって止まったときは、その控えの push-github.ps1 を確かめてから走らせる。

.EXAMPLE
  # まず下見(何が転送されるかを見るだけ。接続もしない)
  .\deploy-to-host.ps1 -WhatIfOnly

.EXAMPLE
  # 本番へ配備する(転送前に確認を求める)
  .\deploy-to-host.ps1

.EXAMPLE
  # 確認を省く(自動実行など)
  .\deploy-to-host.ps1 -Yes

.EXAMPLE
  # nginx の設定だけ入れ替える
  .\deploy-to-host.ps1 -Action restart -Services reverse-proxy

.EXAMPLE
  # 配備してから、そのままバックアップも取る
  .\deploy-to-host.ps1 -Action up -Backup

.EXAMPLE
  # バックアップだけ取って、中身も開いておく(**平文が残る**)
  .\deploy-to-host.ps1 -BackupOnly -OpenBackup
#>
[CmdletBinding()]
param(
    # 下の4つは既定で本番が入る。個別に指定すればそちらが優先される
    [string]$HostName = '',
    [string]$User = '',
    # ホスト上の server/ に相当するフォルダ。未指定なら docker inspect から自動で探す
    [string]$RemotePath = '',
    [string]$KeyPath = '',
    [ValidateSet('deploy', 'up', 'restart')]
    [string]$Action = 'deploy',
    [string[]]$Services = @(),
    [switch]$WhatIfOnly,
    # 転送前の確認を省く。**行き先は本番しかないので、普段は付けないこと**
    [switch]$Yes,
    [int]$Port = 22,
    # 初めて繋ぐホスト(新しい VPS)。指紋を表示してから known_hosts に登録する
    [switch]$AcceptHostKey,
    # 鍵にパスフレーズがあるとき
    [switch]$Interactive,
    <#
      後片付け(host-setup.ps1 -Fix)を呼ばない。**普段は付けないこと。**

      配備はファイルを置くだけで所有権に触れない(触れないのが正しい)。
      だが `config/*.local.php` と `uploads/` は **www-data の持ち物でないと動かない**。
      そこを人の記憶に任せていたために2つの不具合になった:
        - 「地図データ公開設定」の保存だけが失敗する
        - 地図の配信ファイルの書き込みが Permission denied で落ちる
      **忘れると壊れる手順は、忘れられる形にしておかない。**
    #>
    [switch]$NoSetup,
    # 配備のあとバックアップも取る(理由は .PARAMETER Backup)
    [switch]$Backup,
    # 配備せずバックアップだけ。自動実行から呼ぶとき用
    [switch]$BackupOnly,
    # 取ったものを解凍して残す。**平文が残る**ので普段は付けない
    [switch]$OpenBackup,
    # 手元に何世代残すか(backup-data.ps1 へそのまま渡す)
    [int]$BackupKeep = 7,
    # 配備のあと GitHub の控えへも出す(理由は .PARAMETER GitHub)
    [ValidateSet('all', 'web', 'android', 'none')]
    [string]$GitHub = 'all'
)

$ErrorActionPreference = 'Stop'

<#
  接続先を埋める。個別指定があればそちらを尊重する。

  **行き先は本番だけ。** 校内 LAN(192.168.3.29)への配備はもう行わないので、
  以前あった -Target の分岐は消した。代わりに転送の直前で確認を求める(-Yes で省略可)。
#>
<#
  **本番のホストの名前。** 2026-09-17 に ito8795.com → ito4.jp へ統一した(同じホスト)。
  旧の名前を渡されても本番として扱う(取り違えて「本番ではない」と表示しないため)。
  **known_hosts に ito4.jp の行が要る**(無いと BatchMode の SSH が止まる。docs/14 §0)。
#>
$prodHostNames = @('ito4.jp', 'ito8795.com')
$preset = @{
    HostName = 'ito4.jp'
    # 2026-09-18 から kmops(docker あり・sudo なし・鍵はパスフレーズ付き)。km は人が sudo で使う(docs/12 §7-4 B 段 2)
    User = 'kmops'
    KeyPath = "$env:USERPROFILE\.ssh\km_ops"
    RemotePath = '/opt/kosenmap'
    Label = '**本番(インターネットに公開中)**'
}
if ($HostName -eq '') { $HostName = $preset.HostName }
<#
  **本番以外へ送るときに「本番」と表示しない**(2026-09-17)。
  LAN の検証機(ローカル環境)へも同じスクリプトで送るようになったため。相手が本当にローカル環境かは、
  [3/5] で相手の .env の KM_ENV を読んで確かめる。
#>
if ($HostName -notin $prodHostNames) {
    $preset.Label = "**$HostName(本番ではないホスト)**"
}
if ($User -eq '') { $User = $preset.User }
if ($KeyPath -eq '') { $KeyPath = $preset.KeyPath }
if ($RemotePath -eq '') { $RemotePath = $preset.RemotePath }

$Root = Split-Path -Parent (Split-Path -Parent $PSCommandPath)   # …\Website\server

<#
  ---------------------------------------------------------------- バックアップ

  **backup-data.ps1 を呼ぶだけにする。融かさない。**

  取り方(ホストに作らせる / 暗号のまま運ぶ / 開いて行数を数える / 平文を消す)は
  向こうが持っている。こちらへ写すと**同じ判断が2箇所に増え、片方だけ直される。**
  実際にそれで壊した箇所がいくつもある(check-updates のサービス名、世代整理の条件)。

  返り値は**文章ではなく値**で受け取る。backup-data.ps1 の経過は全部 Write-Host なので
  パイプラインには乗らず、受かるのは最後の1オブジェクトだけになる。
  それでも「Archive を持つものを選ぶ」と書いておく ——
  向こうが将来何かを吐いても、ここが黙って壊れないように。
#>
function Invoke-KmBackupStep {
    param([switch]$Open)

    $script = Join-Path $PSScriptRoot 'backup-data.ps1'
    if (-not (Test-Path $script)) { throw "backup-data.ps1 が見つかりません: $script" }

    Write-Host '=== バックアップ (backup-data.ps1) ===' -ForegroundColor Cyan
    # **`$args` という名前にしない。** PowerShell の自動変数で、
    # 関数へ渡された引数そのもの。上書きすると読む人も自分も取り違える
    $backupArgs = @{
        HostName    = $HostName
        User        = $User
        KeyPath     = $KeyPath
        RemotePath  = $RemotePath
        Port        = $Port
        Keep        = $BackupKeep
        Interactive = [bool]$Interactive
    }

    $result = @(& $script @backupArgs) |
        Where-Object { $null -ne $_ -and $_.PSObject.Properties['Archive'] } |
        Select-Object -Last 1

    if (-not $result) {
        throw 'バックアップの結果を受け取れませんでした(backup-data.ps1 の出力を確認してください)。'
    }

    if (-not $Open) { return $result }

    <#
      **解凍すると平文が残る。**

      開き方も置き場も open-backup.ps1 1本に任せる —— **開き方を2通り持たない。**
      -OpenBackup は「中身を使いたい」という意思表示なので、-Keep を明示して渡す。
      置き場は <控えの置き場>\_opened\<日時> で、24時間を過ぎたものを**次の世代整理**
      (backup-data.ps1。週次のタスク)が消す。つまり**最長で約1週間残る**(2026-09-14 に表示を実際に合わせた)。
      (以前はここで世代フォルダの隣に opened-* を作っていて、消し忘れが2度起きた)

      開いた場所は**値で受け取る**(backup-data.ps1 の結果と同じ作法)。
    #>
    Write-Host ''
    Write-Host '=== 解凍 (open-backup.ps1 -Keep) ===' -ForegroundColor Cyan
    $opener = Join-Path $PSScriptRoot 'open-backup.ps1'
    if (-not (Test-Path $opener)) { throw "open-backup.ps1 が見つかりません: $opener" }

    $opened = @(& $opener -Path $result.Archive -Keep) |
        Where-Object { $null -ne $_ -and $_.PSObject.Properties['Opened'] } |
        Select-Object -Last 1
    if (-not $opened -or "$($opened.Opened)" -eq '') {
        throw '解凍した場所を受け取れませんでした(open-backup.ps1 の出力を確認してください)。'
    }

    $result | Add-Member -NotePropertyName Opened -NotePropertyValue $opened.Opened -Force
    return $result
}

<#
  **配備せずバックアップだけ取る道。**

  ここで抜ける —— 下は tar を作って転送する処理なので、
  通すと「バックアップだけ」と言いながらファイルを置いてしまう。
#>
if ($BackupOnly) {
    if ($WhatIfOnly) {
        Write-Host '-BackupOnly -WhatIfOnly: 何もしません。'
        # **`$HostName:` と続けて書かない。** `:` まで変数名として読まれ、
        # 構文解析の時点で落ちる(5.1 で実測)。`${}` で切る
        Write-Host "  取得元: ${User}@${HostName}:${RemotePath} / 世代 $BackupKeep"
        exit 0
    }
    $r = Invoke-KmBackupStep -Open:$OpenBackup
    Write-Host ''
    Write-Host "バックアップだけ取りました: $($r.OutDir)" -ForegroundColor Green
    if ($r.PSObject.Properties['Opened']) {
        Write-Host ''
        Write-Host '  **解凍したものには .env と config/*.local.php が平文で入っています。**' -ForegroundColor Yellow
        Write-Host "  24時間を過ぎたあと、次の世代整理(週次の backup-data.ps1)で消えます。最長で約1週間残ります。すぐ消すなら: Remove-Item -Recurse -Force `"$($r.Opened)`""
    }
    exit 0
}

<#
  接続先の文字列は **`$target` という名前にしない。**

  かつて `-Target` というパラメータがあった頃、PowerShell の変数が大文字小文字を
  区別しないせいで ValidateSet 付きの `$Target` と同じ変数になり、
  ホスト名を代入した瞬間に「Target として不正な値」で落ちた(実際に踏んだ)。
  パラメータ自体は消えたが、**同じ罠を踏み直さないよう名前は分けたままにしてある。**
#>
$sshTarget = "$User@$HostName"

Write-Host "配備先: $($preset.Label)"
Write-Host "        $sshTarget"

# 接続部分は共有(初回接続やパスフレーズの見分けは km-ssh.ps1 が担う)
. (Join-Path $PSScriptRoot 'km-ssh.ps1')
Initialize-KmSsh -HostName $HostName -User $User -KeyPath $KeyPath -Port $Port -Interactive:$Interactive

function Invoke-Remote {
    param([Parameter(Mandatory)][string]$Command, [switch]$AllowFailure)
    return Invoke-KmSsh -Command $Command -AllowFailure:$AllowFailure
}

<#
  リモートのシェルを見分ける。3種類ありうる:

    cmd         Windows OpenSSH の既定
    powershell  DefaultShell を変えている Windows
    sh          Linux(VPS)

  **`echo %COMSPEC%` だけでは Linux を見分けられない。** PowerShell も sh も
  `%COMSPEC%` をそのまま返すので、両者が同じに見えてしまう(1.0.1 以来ここが
  未対応のまま残っていた。Linux を PowerShell と誤判定して `Set-Location` を送っていた)。

  そこで **先に `uname -s` を撃つ**。Linux なら "Linux" が返り、Windows の
  どちらのシェルでも「コマンドが見つからない」で失敗する。
#>
$script:RemoteShell = ''
function Get-RemoteShell {
    if ($script:RemoteShell -ne '') { return $script:RemoteShell }

    $uname = "$(Invoke-Remote 'uname -s' -AllowFailure)"
    if ($uname -match '^\s*(Linux|Darwin|.*BSD)\s*$') {
        $script:RemoteShell = 'sh'
        return $script:RemoteShell
    }

    $probe = "$(Invoke-Remote 'echo %COMSPEC%')"
    $script:RemoteShell = if ($probe -match 'cmd\.exe') { 'cmd' } else { 'powershell' }
    return $script:RemoteShell
}

# リモートのパス区切りに合わせて連結する(Windows なら \、Linux なら /)
function Join-RemotePath {
    param([Parameter(Mandatory)][string]$Base, [Parameter(Mandatory)][string]$Leaf)
    if ((Get-RemoteShell) -eq 'sh') { return ($Base.TrimEnd('/') + '/' + $Leaf) }
    return ($Base.TrimEnd('\') + '\' + $Leaf)
}

# 指定フォルダへ移動してからコマンドを実行する。シェルの差はここで吸収する
function Invoke-RemoteIn {
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$Command, [switch]$AllowFailure)
    $line = switch (Get-RemoteShell) {
        'cmd' { "cd /d `"$Path`" && $Command" }
        'sh'  { "cd '$Path' && $Command" }
        default { "Set-Location '$Path'; $Command" }
    }
    return Invoke-Remote -Command $line -AllowFailure:$AllowFailure
}

# ---------------------------------------------------------------- 転送するもの

<#
  除外の理由(ここを間違えるとホスト側の正本を壊す):
    .env                    DB / Logto / Soketi の秘密。ローカルには存在すらしない
    certs/                  ホストの証明書
    src/config/*.local.php  db / logto-m2m / recaptcha / map-access(教職員氏名の解除パスワード
                            のハッシュ)/ app-map(Androidアプリ配信のアクセスコードのハッシュ)
    src/vendor/             composer がホスト側で入れる。composer.lock を変えたら別途 install
    src/uploads/            ファイル管理の実データ。**配信する地図JSONもここ**
    src/cache/              logto_guard.php が JWKS を置く先。ホスト側で 0700 で作られる
    Downloaded/             PHP バイナリと取得元アーカイブ。アプリではない

  逆に **転送する** もの:
    src/admin/vendor/       AdminLTE 等のフロント資材(composer の vendor とは別物)
    src/vendor-web/         Leaflet
#>
$include = @(
    './src', './compose.yaml', './docker', './nginx', './compose.vps.yaml'
    # ローカル環境(LAN の検証機)で重ねる差分。本番では .env の COMPOSE_FILE が読まないので、置いてあっても効かない
    './compose.local.yaml'
    <#
      **scripts/ は丸ごとは送らない**(上の説明のとおり)。だがここに並べたものだけは
      ホスト側に「置いてある」必要がある —— cron から呼ぶため、
      あるいは**事故の最中に手で叩くため**。
      標準入力で流す host-setup.sh の手は使えない(定期実行の相手が居ないし、
      もしもの時に手元から流し込める状態とは限らない)。

      置きっぱなしになる分、**直したら配備し直さないとホスト側が古いまま**になる。
      どれも当てる側ではなく調べて知らせる側なので、古くても壊れはしない。
    #>
    './scripts/check-updates.sh'
    './scripts/host-updates-setup.sh'
    './scripts/host-security-check.sh'
    './scripts/host-emergency.sh'
    './scripts/host-backup.sh'
    # 控え専用の鍵の門番(authorized_keys の command= で呼ばれる。docs/12 §7-4)
    './scripts/ssh-backup-gate.sh'
    # km の降格(docker を使う作業を専用の利用者へ。docs/12 §7-4 B)
    './scripts/host-ops-user.sh'
    # どのスクリプトの出力でもメールに載せる汎用の口。cron から呼ぶので置いておく
    './scripts/send-log.sh'
    <#
      証明書の更新(cron が send-log.sh 越しに毎日呼ぶ)と、ドメインの付け替え。
      後者は cron からは呼ばないが、**移すのはたいてい何かに追われているとき**なので先に置いておく。
      host-setup.sh の HOST_SCRIPTS と対(実行ビットはあちらが付ける)。
    #>
    './scripts/host-cert.sh'
    './scripts/host-domain.sh'
    <#
      Logto の DB を SUPERUSER でない利用者へ移す SQL と、Console のパスワード方針(2026-09-15)。
      docs/12 のセルがホスト側のこのファイルを読む。**控えから戻したあとにも流し直す**ので置いておく。
    #>
    './scripts/logto-db-role.sql'
    # ローカル環境を立てる(自作 CA と証明書・.env の切り替え)。**LAN の検証機で最初に叩く**(docs/13)
    './scripts/host-local.sh'
    <#
      SSH ログインの知らせと、切る・BAN する道具(2026-09-25)。
      **ここに置くのは原本だけ。** PAM と sudo から root で走るのは、ssh-login-notify-setup.sh --fix が
      /usr/local/sbin へ root:root 755 で写したもの(配備の利用者が書き換えられる場所を root に走らせない)。
      原本を直したら、配備のあとに setup --fix で写し直す。
    #>
    './scripts/ssh-login-notify.sh'
    './scripts/ssh-kick.sh'
    './scripts/ssh-login-notify-setup.sh'
)

<#
  除外パターンは **$include の中にあるものだけ** を書く。

  .env / certs/ / Downloaded/ / Old/ / scripts/ は $include に含まれていないので、
  そもそもアーカイブへ入らない。にもかかわらず --exclude に書くと害がある: tar のパターンは
  先頭で固定されないため、`--exclude=./scripts` は `./src/scripts/` まで巻き添えにする
  (実際にこれで移行スクリプトが転送されなくなっていた。いまは $mustContain の
  `./src/scripts/check.php` がその巻き添えを検出する)。
#>
$excludes = @(
    './src/vendor'                          # composer。ホスト側で install する
    './src/uploads'                         # ファイル管理の実データ(配信する地図JSONもここ)
    './src/cache'                           # JWKSキャッシュ。ホスト側で 0700 で作られる
    './src/config/db.local.php'             # DB の接続情報
    './src/config/logto-m2m.local.php'      # Logto m2m の秘密
    './src/config/map-access.local.php'     # 教職員氏名の解除パスワードのハッシュ
    './src/config/app-map.local.php'        # Androidアプリ配信のアクセスコードのハッシュ
    './src/config/recaptcha.local.php'      # reCAPTCHA の秘密鍵 / API キー
    './src/admin/_dev-session.php'          # 検証用。万一残っていてもホストへは出さない
    # 個人の固定 IP。リポジトリに所在情報を残さないため、ホスト側が正本
    # (見本は nginx/km/allow-admin-home.local.conf.example)
    './nginx/km/allow-admin-home.local.conf'
)

# アーカイブが正しいことを組み立て直後に確かめる。パターンの解釈違いで
# 「必要なファイルが入っていない」「秘密が混入した」を黙って通さないための保険。
$mustContain = @(
    './src/index.php'
    './src/lib/db.php'
    # src/scripts/ が丸ごと落ちていないことの見張り。**除外パターンの巻き添えを検出する**
    # ためだけに置いてある(下の $excludes の説明を参照)。
    './src/scripts/check.php'
    './src/admin/index.php'
    './src/admin/vendor/adminlte/css/adminlte.css'
    './src/vendor-web/leaflet/leaflet.js'
    './compose.yaml'
    './docker/php/99-timezone.ini'
    './nginx/default.conf.template'
    './nginx/km/proxy-web.conf'
    # 更新の確認と通知。**この2つは対**で、片方だけだと cron が空振りする
    './scripts/check-updates.sh'
    './src/scripts/notify-update.php'
    # ホストのセキュリティ確認と通知。こちらも**対**
    './scripts/host-security-check.sh'
    './src/scripts/notify-security.php'
    # バックアップと通知。こちらも**対**
    './scripts/host-backup.sh'
    './scripts/ssh-backup-gate.sh'
    './scripts/host-ops-user.sh'
    './src/scripts/notify-backup.php'
    # 実行結果・ログをメールに載せる口。**この2つも対**で、片方だけだと空振りする
    './scripts/send-log.sh'
    './src/scripts/notify-log.php'
    # もしものときに叩くもの。**事故の最中に転送はできない**ので、先に置いておく
    './scripts/host-emergency.sh'
    # SSH ログインの知らせと、切る・BAN する道具(2026-09-25)。知らせは notify-log.php と**対**
    './scripts/ssh-login-notify.sh'
    './scripts/ssh-kick.sh'
    './scripts/ssh-login-notify-setup.sh'
    # 証明書の更新。cron が send-log.sh 越しに呼ぶので、**無いと毎日「失敗」が届く**
    './scripts/host-cert.sh'
    # ドメインの付け替え。**この2つも対** —— .env を変えたあと Logto の戻り先を直さないとサインインできない
    './scripts/host-domain.sh'
    './src/scripts/logto-domain.php'
    # audience を一緒に移すとき(2026-09-17)。先に Logto に新しいリソースが無いと、切り替えた瞬間に全員が 401
    './src/scripts/logto-api-resource.php'
    # アカウント削除の後片付け。**3つ揃っていないと webhook が fatal になる**
    './src/api/logto-webhook.php'
    './src/lib/logto-webhook.php'
    './src/lib/account-delete.php'
    <#
      地図の見た目。**app.js は起動時に window.KM_MAP_STYLE を読む。**
      map-style.js が落ちると、公開ページの地図が丸ごと描けなくなる
      (しかも「読み込めませんでした」ではなく、真っ白なまま止まる)。
    #>
    './src/Main/map-style.js'
    './src/Main/map-tuning.js'
    <#
      建物平面図。**アプリの APK 内の画像の写し**で、これが無いと
      屋外図に重ねる見取り図だけが出ない(地図は出るので気づきにくい)。
    #>
    './src/Main/Picture/bldg/bldg_library_1f.png'
    # 配信の発行。3つ揃っていないと admin/map-publish.php が fatal になる
    './src/admin/map-publish.php'
    './src/lib/app-map-publish.php'
    './src/lib/qr.php'
    # Androidアプリへの地図配信。3つが揃っていないと api/app-map.php が fatal になる
    # (logto_guard.php の logto_optional_principal() に依存している)
    './src/api/app-map.php'
    './src/lib/app-map.php'
    './src/logto_guard.php'
    # アカウント画像と、アカウントへ預ける設定。
    # app-avatar.php は lib/profile.php の保存処理をそのまま呼ぶので、
    # 片方だけ古いと 500 になる。
    './src/api/app-avatar.php'
    './src/lib/profile.php'
    './src/api/app-settings.php'
    './src/lib/app-settings.php'
    # 人数表示。lib/logto-management.php が無いと app-stats.php が fatal になる。
    './src/api/app-stats.php'
    './src/lib/user-stats.php'
    './src/lib/logto-management.php'
    # ランキング。
    './src/api/app-ranking.php'
    './src/lib/app-ranking.php'
)
$mustNotContain = @(
    './src/config/db.local.php'
    './src/config/logto-m2m.local.php'
    './src/config/map-access.local.php'
    './src/config/app-map.local.php'
    './src/config/recaptcha.local.php'
    './src/admin/_dev-session.php'
)
$mustNotStartWith = @('./src/vendor/', './src/uploads/')

if ($WhatIfOnly) {
    Write-Host '=== 転送するもの ==='
    $include | ForEach-Object { Write-Host "  $_" }
    Write-Host '=== 除外するもの(ホスト側の正本を壊さないため) ==='
    $excludes | ForEach-Object { Write-Host "  $_" }
    exit 0
}

<#
  配備先が本番だけになったので、**打ち間違いを止めるものが既定値の側に無い。**
  以前は「既定が staging」がその役目を果たしていた。ここで人に一度確かめる。

  非対話(タスクスケジューラなど)で走らせると Read-Host は即座に空を返して
  中断になる。**それでよい** —— 黙って本番へ出るより、止まって気付ける方がよい。
  意図して自動化するときだけ -Yes を付ける。
#>
if (-not $Yes) {
    Write-Host ''
    Write-Host "  配備先 : $($preset.Label)" -ForegroundColor Yellow
    Write-Host "           $sshTarget`:$RemotePath"
    Write-Host "  動作   : $Action$(if ($Services) { " (" + ($Services -join ', ') + ")" })$(if ($Backup) { ' + バックアップ' })$(if ($Backup -and $OpenBackup) { '(解凍まで)' })$(if ($GitHub -ne 'none') { " + GitHub へ出す($GitHub)" })"
    Write-Host ''
    $answer = Read-Host "  $(if ($HostName -in $prodHostNames) { '本番' } else { $HostName })へ配備します。続けますか (yes/no)"
    if ($answer -ne 'yes') {
        Write-Host '中断しました。(確認を省くには -Yes、下見だけなら -WhatIfOnly)'
        exit 1
    }
    Write-Host ''
}

# ---------------------------------------------------------------- 事前の点検

Write-Host "[1/5] $sshTarget へ疎通確認"
Assert-KmSshReady -AcceptHostKey:$AcceptHostKey
Write-Host "      リモートのシェル: $(Get-RemoteShell)"

if ($RemotePath -eq '') {
    Write-Host '[2/5] ホスト側のプロジェクトパスを compose の札から特定'
    # 名前ではなくサービスの札で引く(km-ssh.ps1)。**コンテナ名を変えても動く**
    $RemotePath = Get-KmRemoteProjectPath
}
Write-Host "      ホスト側の server/ = $RemotePath"

Write-Host '[3/5] ホスト側の前提を確認(.env と docker compose)'

# リモートのシェルに合わせて安全にコマンドを組み立てる
$envPath = Join-RemotePath $RemotePath '.env'
$remoteCmd = switch (Get-RemoteShell) {
    'cmd' { 'if exist "' + $envPath + '" (echo HAS_ENV) else (echo NO_ENV)' }
    'sh'  { '[ -f "' + $envPath + '" ] && echo HAS_ENV || echo NO_ENV' }
    default { 'if (Test-Path "' + $envPath + '") { "HAS_ENV" } else { "NO_ENV" }' }
}

# 実行結果を取得して不要な空白・改行を除去
$envCheck = (Invoke-Remote $remoteCmd).Trim()

if ($envCheck -notmatch 'HAS_ENV') {
    throw "ホストに .env がありません($envPath)。秘密が入ったファイルなので、こちらからは作りません。"
}

<#
  **相手が本番かローカル環境かを、相手の .env で確かめて大きく出す**(2026-09-17)。
  2026-09-07 に、検証機と本番が `docker ps` で見分けられず公開中のサイトを止めかけた。
  ローカル環境は scripts/host-local.sh init が KM_ENV=local を書く。値だけを読み、ほかの行は読まない。
#>
if ((Get-RemoteShell) -eq 'sh') {
    $remoteMode = "$(Invoke-Remote ("sed -n 's/^KM_ENV=//p' '" + $envPath + "' | tail -n 1") -AllowFailure)".Trim().Trim('"', "'")
    if ($remoteMode -eq 'local') {
        if ($HostName -in $prodHostNames) {
            throw "本番のホスト($HostName)の .env が KM_ENV=local になっています。本番の .env に KM_ENV を書かないでください。"
        }
        Write-Host '      相手の .env: ★ ローカル環境(KM_ENV=local)' -ForegroundColor Green
    } elseif ($HostName -notin $prodHostNames) {
        Write-Host "      相手の .env: KM_ENV=local がありません —— $HostName は**本番の構成(Let's Encrypt)**として動きます" -ForegroundColor Yellow
        Write-Host '                   LAN の検証機なら、先にホストで scripts/host-local.sh init を実行してください(docs/13)' -ForegroundColor Yellow
    } else {
        Write-Host '      相手の .env: 本番(KM_ENV なし)' -ForegroundColor Yellow
    }
}

<#
  **初回配備では compose.yaml がまだ無い。** ここを必須にすると
  `no configuration file provided: not found` で止まり、**新しいホストへは一度も
  配備できない**(実際に 192.168.3.98 で踏んだ)。状況の報告であって前提ではないので、
  失敗しても続ける。
#>
<#
  **`docker compose ps --format` に Go テンプレートは渡せない。**
  compose v2 が受けるのは `table` と `json` だけで、テンプレートを渡すと

      no such service: {{.State}}

  になる(2026-09-03 の配備で実際に出た)。`docker ps` の方は受けるので
  混同しやすい。ここは素の `ps` にする —— **状況の報告であって、
  値を機械で読むわけではない。**
#>
$psOut = Invoke-RemoteIn -Path $RemotePath -Command 'docker compose ps' -AllowFailure
if ("$psOut" -match 'no configuration file|not found') {
    Write-Host '      compose.yaml がまだありません(初回配備とみなして続けます)'
} elseif ("$psOut" -match 'failed to read .*\.env') {
    <#
      **.env を compose が読めない。ここで止め、エラー文は表示しない。**

      エラー文には読めなかった行の中身 —— つまり**秘密の値の断片**がそのまま載る
      (2026-09-13、BACKUP_PASSPHRASE に `"` `'` `\` `$` を含む値を入れた直後に実際に出た)。
      この状態では `docker compose` が全部落ちるので、配備しても反映も確認もできない。
      夜のバックアップも DB を取れず、メールも出ない。
    #>
    throw @"
ホストの docker compose が .env を読めません(エラー文には秘密の値の断片が載るので表示しません)。
  値に引用符(" ')・\・$ が入っていないか確かめてください。英数字だけの値にするのが確実です。
  確かめ方(ホストで): docker compose config -q ; echo `$?   → 0 なら読めています
"@
} else {
    $psOut | ForEach-Object { Write-Host "      $_" }
}

# ---------------------------------------------------------------- 転送

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$archive = Join-Path ([IO.Path]::GetTempPath()) "km-deploy-$stamp.tar.gz"

Write-Host '[4/5] tar を作成して転送'
<#
  **`--no-fflags`: Windows のファイル属性を載せない。**

  付けないと bsdtar は属性を `SCHILY.fflags` という拡張ヘッダーで書き込み、
  ホストの GNU tar が1件ずつ「Ignoring unknown extended header keyword」と警告する
  (2026-09-13 の配備で41件)。害は無いが、**本当のエラーが警告の山に埋もれる。**

  なお**フォルダの「読み取り専用」はこれでは消えない** —— それはモード 555 として
  載る。ホスト側の対処は展開のところを参照。
#>
$tarArgs = @('-czf', $archive, '--no-fflags', '-C', $Root)
foreach ($e in $excludes) { $tarArgs += "--exclude=$e" }
$tarArgs += $include
& tar @tarArgs
if ($LASTEXITCODE -ne 0) { throw "tar の作成に失敗しました (exit $LASTEXITCODE)" }
Write-Host ("      {0:N1} MB" -f ((Get-Item $archive).Length / 1MB))

# 中身の検査。ここで落とせば、壊れた配備がホストへ届くことはない
$entries = & tar -tzf $archive
$missing = @($mustContain | Where-Object { $entries -notcontains $_ })
$leaked = @($mustNotContain | Where-Object { $entries -contains $_ })
foreach ($prefix in $mustNotStartWith) {
    $leaked += @($entries | Where-Object { $_.StartsWith($prefix) })
}
if ($missing.Count -gt 0 -or $leaked.Count -gt 0) {
    Remove-Item $archive -Force -ErrorAction SilentlyContinue
    $report = ''
    if ($missing.Count -gt 0) { $report += "`n入っているべきファイルが欠けています:`n  " + ($missing -join "`n  ") }
    if ($leaked.Count -gt 0) { $report += "`n除外したはずのものが混入しています:`n  " + (($leaked | Select-Object -First 10) -join "`n  ") }
    throw "アーカイブの検査に失敗したので転送しませんでした。$report"
}
Write-Host "      検査 OK($($entries.Count) エントリ)"

$remoteArchive = Join-RemotePath $RemotePath 'km-deploy.tar.gz'
Copy-KmTo -Path $archive -Destination $remoteArchive
Remove-Item $archive -Force

# tar は上書きするだけで、アーカイブに無いファイルには触れない(= 削除されない)
if ((Get-RemoteShell) -eq 'sh') {
    <#
      **展開の前後で、書けないディレクトリを書けるように戻す。**

      Windows のフォルダには「読み取り専用」の属性が付いていることがあり
      (`src` / `docker` / `nginx` など。2026-09-13 時点で41個)、bsdtar はそれを
      **ディレクトリのモード 555** として書く。GNU tar は展開の最後にそのモードを当てる。
      すると**1回目の配備は通り、次の配備で**

          tar: ./src/lib/db.php: Cannot open: File exists

      が全ファイルに出て止まる —— 持ち主の km でも、書けないディレクトリの中の
      ファイルは置き換えられない(unlink できず、O_EXCL の open が EEXIST になる)。
      **書けるディレクトリ(scripts/ など)の分だけは置き換わる**ので、途中まで配備された
      状態で止まる。

      手元の属性を外しても、エクスプローラーなどがまた付けうる。**受け取る側で吸収する:**

        - 前: 既に 555 になっているものを直す(これが無いと今回の状態から抜けられない)
        - **展開中: `--delay-directory-restore`**(下の説明)
        - 後: 今回の展開で 555 を当てられたものを直す(次の配備のため)

      ## 前後の chmod だけでは足りない

      **GNU tar は、ディレクトリのモードを展開の最後ではなく「そのディレクトリの外へ
      出たとき」に当てる。** bsdtar のアーカイブは `./src/` の子ディレクトリの項目を
      先に全部並べ、中身は後から来るので、中身に着く前に 555 が当たって落ちる。
      **空の場所への初回ですら落ちる**(本物のアーカイブの写しで 429 件)。
      `--delay-directory-restore` で、モードを当てるのを本当に最後まで遅らせる。

      /tmp で本物のアーカイブの写しを使い、「フラグ無しは初回も2回目も落ちる」
      「前後の chmod + フラグで、555 の状態から・繰り返し・空の場所への初回が
      すべて exit 0」を確かめた(2026-09-13)。GNU tar の選択肢なので、busybox の tar
      では動かない(本番は Ubuntu)。

      対象はアーカイブに入っているディレクトリだけ(一覧で `/` で終わるもの)。
      ホストにしかない src/cache(www-data 所有)などには触れない。

      **二重引用符を含むので、引数ではなく標準入力で流す** —— Windows PowerShell 5.1 は
      外部コマンドへの引数の二重引用符を崩す(km-ssh.ps1 の Invoke-KmSshStdin を参照)。

      展開は数秒で終わる。止まったら5分で打ち切る(失敗したときの tar は1ファイル1行の
      エラーを出す。以前はそれでパイプが詰まり、何も表示せずに止まっていた)。
    #>
    $extractScript = @'
set -u
cd '__KM_REMOTE_PATH__' || exit 1
km_writable_dirs() {
    tar -tzf km-deploy.tar.gz | sed -n 's:/$::p' | while IFS= read -r d; do
        if [ -d "$d" ] && [ ! -w "$d" ]; then chmod u+w "$d" || exit 1; fi
    done
}
km_writable_dirs && tar --delay-directory-restore -xzf km-deploy.tar.gz && km_writable_dirs && rm -f km-deploy.tar.gz
'@ -replace '__KM_REMOTE_PATH__', $RemotePath
    Invoke-KmSshStdin -Script $extractScript -TimeoutSec 300 | Out-Null
} else {
    $extract = switch (Get-RemoteShell) {
        'cmd' { 'tar -xzf km-deploy.tar.gz && del km-deploy.tar.gz' }
        default { 'tar -xzf km-deploy.tar.gz; Remove-Item km-deploy.tar.gz -Force' }
    }
    Invoke-RemoteIn -Path $RemotePath -Command $extract
}
Write-Host '      展開しました'

<#
  フロア画像(src/Main/Picture/１階.png など)はファイル名に全角文字を含む。両端とも
  同じ Windows のコードページなら往復するはずだが、ここが崩れると地図の画像だけが
  404 になり、しかもページ自体は表示されるので気づきにくい。展開後に実数を数えておく。

  **数えるのは .png。** 見取り図は SVG(中身は 7015x7015 の PNG を base64 で包んだもの)
  から、素の PNG へ差し替えた。旧 .svg は切り戻し用に残してあるので、.svg を数えていると
  「実際に配信されるファイルが1枚も無くても検査は通る」ことになる。
#>
$floorImageCount = switch (Get-RemoteShell) {
    'cmd' {
        # CMD用：エスケープ事故を防ぐため '+' で文字列を安全に結合
        (Invoke-Remote ('dir /b "' + $RemotePath + '\src\Main\Picture\*.png" 2>nul | find /c /v ""')).Trim()
    }
    'sh' {
        # **ls では数えない。** 全角ファイル名が崩れていても ls は何か表示するので、
        # find でファイルとして数える(数が合わなければ展開時に壊れている)
        (Invoke-Remote ("find '$RemotePath/src/Main/Picture' -maxdepth 1 -name '*.png' -type f 2>/dev/null | wc -l")).Trim()
    }
    default {
        (Invoke-Remote ('(Get-ChildItem "' + $RemotePath + '\src\Main\Picture\*.png" -ErrorAction SilentlyContinue | Measure-Object).Count')).Trim()
    }
}

Write-Host "      フロア画像: $floorImageCount 枚(6 が正解)"

if ($floorImageCount -ne '6') {
    Write-Warning 'フロア画像の枚数が合いません。全角ファイル名が展開時に崩れた可能性があります。'
}
# ---------------------------------------------------------------- 反映

Write-Host "[5/5] 反映 (Action=$Action)"
switch ($Action) {
    'deploy' {
        Write-Host '      ファイルを置いただけです。コンテナには触れていません。'
        Write-Host '      compose.yaml を変えたなら -Action up、default.conf だけなら -Action restart -Services reverse-proxy'
    }
    'up' {
        <#
          **古いプロジェクト名のコンテナが残っていないか、先に見る。**

          2026-09-07 に compose の名前を `Test` から `kosenmap` へ変えた。
          compose はプロジェクト名でコンテナを見分けるので、**改名した compose から見ると
          動いている `dev-*` は「他人のコンテナ」**になる。そのまま `up -d` すると

            - 新しい `km-nginx-proxy` を作ろうとする
            - しかし 80/443 は `dev-nginx-proxy` が握ったまま
            - `port is already allocated` で失敗する

          しかも**失敗するのは新しい方だけ**で、古い方は動き続ける。
          配備は「失敗した」と出るのにサイトは生きているので、
          何が起きたのか分かりにくい。

          入れ替えは一度きりで、**短い時間だが本当にサイトが落ちる。**
          自動でやらない —— 手順を示して止まる。
        #>
        $stale = "$(Invoke-RemoteIn -Path $RemotePath -Command 'docker ps -aq --filter label=com.docker.compose.project=test' -AllowFailure)".Trim()
        if ($stale -ne '') {
            throw @"
古い名前(プロジェクト `test` / コンテナ `dev-*`)のコンテナが残っています。

  この compose は `name: kosenmap` になったので、そのまま up -d すると
  80/443 を古い方が握ったままで **port is already allocated** になります。

  入れ替えはホスト側で1回だけ行ってください(**その間サイトが落ちます。1分ほど**):

    cd $RemotePath
    docker compose -p test down          # 古いコンテナを消す(ボリュームは残る)
    docker compose up -d                 # 新しい名前で立て直す

  ボリュームは `test_*` のまま使い続けます(compose.yaml の volumes: を参照)。
  **データは移動しません。**
"@
        }
        Invoke-RemoteIn -Path $RemotePath -Command 'docker compose up -d' | ForEach-Object { Write-Host "      $_" }
    }
    'restart' {
        if ($Services.Count -eq 0) { throw '-Action restart には -Services が要ります(例: -Services reverse-proxy)' }
        Invoke-RemoteIn -Path $RemotePath -Command "docker compose restart $($Services -join ' ')" |
            ForEach-Object { Write-Host "      $_" }
    }
}

Write-Host ''
Write-Host "転送と反映は完了しました(ホスト側の server/ = $RemotePath)。"
Write-Host ''

# ---------------------------------------------------------------- 後片付け

<#
  **ここで終わらせない。**

  配備はファイルを置くだけで、所有権には触れない(触れないのが正しい)。
  だが config/*.local.php と uploads/ は **www-data の持ち物でないと動かない**。
  そこを手作業に任せていたため、実際に2つの不具合になった:

    - 「地図データ公開設定」の保存だけが失敗する
    - 地図の配信ファイルの書き込みが Permission denied で落ちる

  host-setup.ps1 は、直したうえで**PHP の目で読み書きできることまで**確かめる。

  ## 1つのファイルに融かさない

  呼ぶだけにしてある。host-setup.ps1 は **`-Fix` を付けずに「調べるだけ」でも使う** ——
  1本に融かすと、**配備しないと点検できなくなる**。
  判断は host-setup.sh 1本のまま(ロジックを2つ持つと、片方だけ直されて食い違う)。
#>
$setupExit = 0
if ($NoSetup) {
    Write-Host '後片付けは行いませんでした(-NoSetup)。所有権を直すには:' -ForegroundColor Yellow
    Write-Host '  .\host-setup.ps1 -Fix'
    Write-Host ''
} else {
    <#
      composer は **lock が変わったときだけ** 入れ直す。

      毎回走らせない —— vendor/ を作り直す作業で、途中で失敗すると
      **サイトが動かない状態で残る**。配備のたびに賭ける類のものではない。
      逆に、変わったのに入れ直さないと「ホストだけ古い依存」が静かに残るので、
      **lock の中身を突き合わせて判断する。**
    #>
    $composerChanged = $false
    $localLock = Join-Path $Root 'src/composer.lock'
    if ((Get-RemoteShell) -eq 'sh' -and (Test-Path $localLock)) {
        $localHash = (Get-FileHash -Algorithm SHA256 -Path $localLock).Hash.ToLowerInvariant()
        $remoteHash = "$(Invoke-Remote "sha256sum '$RemotePath/src/composer.lock' 2>/dev/null | cut -d' ' -f1" -AllowFailure)".Trim()
        if ($remoteHash -ne '' -and $remoteHash -ne $localHash) {
            $composerChanged = $true
            Write-Host '  composer.lock が変わっています。vendor/ を入れ直します。' -ForegroundColor Yellow
        }
    }

    $setupScript = Join-Path $PSScriptRoot 'host-setup.ps1'
    if (-not (Test-Path $setupScript)) {
        throw "host-setup.ps1 が見つかりません: $setupScript"
    }

    Write-Host '=== 後片付けと確認 (host-setup.ps1 -Fix) ===' -ForegroundColor Cyan
    $setupArgs = @{
        Fix         = $true
        RemotePath  = $RemotePath
        HostName    = $HostName
        User        = $User
        KeyPath     = $KeyPath
        Port        = $Port
        Interactive = [bool]$Interactive
    }
    if ($composerChanged) { $setupArgs['Composer'] = $true }

    <#
      **後片付けの失敗で配備全体を失敗にしない。** ファイルはもう置かれている。
      「転送は成功、後片付けで気になる点」が一番ありがちな結果で、
      それを「配備失敗」と読ませると、直っているものまで疑うことになる。
      **終了コードは分けて報告する。**
    #>
    try {
        & $setupScript @setupArgs
        $setupExit = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
    } catch {
        $setupExit = 1
        Write-Host "  後片付けが途中で止まりました: $($_.Exception.Message)" -ForegroundColor Yellow
    }
    Write-Host ''
}

Write-Host 'composer.json / composer.lock を変えた場合は、続けてホスト側で次を実行してください:'
<#
  **`docker compose exec -T web composer install` は通らない。**
  web イメージには zip 拡張も unzip も無く、composer が dist を展開できずに
      The zip extension and unzip/7z commands are both missing, skipping.
  で失敗する(192.168.3.98 で確認)。**公式の composer イメージを使う。**
  -u で自分の uid にしておかないと vendor/ が root 所有になり、あとで消せなくなる。
#>
if ((Get-RemoteShell) -eq 'sh') {
    Write-Host "  docker run --rm -v $RemotePath/src:/app -u `"`$(id -u):`$(id -g)`" composer:2 install --no-dev --no-interaction"
} else {
    Write-Host "  docker run --rm -v `"${RemotePath}\src:/app`" composer:2 install --no-dev --no-interaction"
}

<#
  **終了コードは分けて持ち帰る。**

  転送は成功したが後片付けに気になる点がある、が一番ありがちな結果。
  そこを 0 で返すと自動実行が「全部問題なし」と読み、
  1 で返すと「配備が失敗した」と読まれて、置けているファイルまで疑うことになる。
  **2 を使う** —— どちらとも違う結果であることを、値そのもので言う。
#>
<#
  ---- バックアップ(-Backup のときだけ)

  **後片付けが通ったときだけ取る。**

  後片付けは「所有権が正しいか」「PHP から config と uploads を読み書きできるか」まで
  見ている。そこが引っかかっている状態で控えを取ると、
  **壊れた状態を「最新の控え」として残す**ことになり、
  いざ戻すときに一番新しいものが一番使えない、という順番になる。

  取らなかったことは黙らない —— 取れたか取らなかったかは、必ず言葉にして残す。
#>
$backupResult = $null
$backupExit = 0
if ($Backup) {
    Write-Host ''
    if ($setupExit -ne 0) {
        Write-Host 'バックアップは取っていません(後片付けに気になる点が残っているため)。' -ForegroundColor Yellow
        Write-Host '  直してから: .\deploy-to-host.ps1 -BackupOnly'
    } else {
        try {
            $backupResult = Invoke-KmBackupStep -Open:$OpenBackup
        } catch {
            $backupExit = 3
            Write-Host ''
            Write-Host "バックアップが取れませんでした: $($_.Exception.Message)" -ForegroundColor Yellow
            Write-Host '  配備そのものは終わっています。取り直すには: .\deploy-to-host.ps1 -BackupOnly'
        }
    }
}

<#
  ---- GitHub の控えへ出す(-GitHub none 以外)

  **配備が済んでから。** 本番に置けたものと控えを揃える。
  **出せなくても終了コードは変えない** —— 本番はもう入れ替わっており、控えが遅れているだけ。
  それでも黙らない。止まったことと、次にすることを言う。
#>
$githubNote = $null
if ($GitHub -ne 'none') {
    $pushAll = Join-Path $PSScriptRoot 'push-github-all.ps1'
    Write-Host ''
    Write-Host "GitHub の控えへ出します($GitHub)" -ForegroundColor Cyan
    try {
        & pwsh -NoProfile -File $pushAll -Target $GitHub -Message "配備に合わせて写す($(Get-Date -Format 'yyyy-MM-dd HH:mm'))"
        if ($LASTEXITCODE -ne 0) { throw "push-github-all.ps1 が $LASTEXITCODE で終わりました" }
        $githubNote = 'GitHub の控えへ出しました。'
    } catch {
        Write-Host "GitHub へ出せませんでした: $($_.Exception.Message)" -ForegroundColor Yellow
        Write-Host '  配備そのものは終わっています。出し直すには: pwsh -File .\push-github-all.ps1'
        $githubNote = '**GitHub の控えは出せていません**(上の出力を確認してください)。'
    }
}

Write-Host ''
if ($githubNote) {
    Write-Host $githubNote -ForegroundColor $(if ($githubNote.StartsWith('**')) { 'Yellow' } else { 'Green' })
}
if ($backupResult) {
    Write-Host "バックアップ: $($backupResult.OutDir)" -ForegroundColor Green
    if ($backupResult.PSObject.Properties['Opened']) {
        Write-Host '  **解凍したものには .env と config/*.local.php が平文で入っています。**' -ForegroundColor Yellow
        Write-Host "  24時間を過ぎたあと、次の世代整理(週次の backup-data.ps1)で消えます。最長で約1週間残ります。すぐ消すなら: Remove-Item -Recurse -Force `"$($backupResult.Opened)`""
    }
    Write-Host ''
}

<#
  終了コードは**起きたことの数だけ**分ける。まとめると読み分けられない。
    0 全部成功 / 2 後片付けに気になる点 / 3 配備は済んだがバックアップが取れなかった
  1(転送の失敗)は上流の throw が返す。
#>
if ($setupExit -eq 0 -and $backupExit -eq 0) {
    Write-Host '完了しました。' -ForegroundColor Green
    exit 0
}
if ($setupExit -ne 0) {
    Write-Host '転送は完了しています。**後片付けに気になる点が残りました**(上の出力を確認してください)。' -ForegroundColor Yellow
    Write-Host '調べ直すには: .\host-setup.ps1     直すには: .\host-setup.ps1 -Fix'
    exit 2
}
Write-Host '配備は完了しています。**バックアップだけが取れませんでした。**' -ForegroundColor Yellow
exit 3
