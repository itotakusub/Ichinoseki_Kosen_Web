<#
.SYNOPSIS
  配備後の後片付け(所有権・権限・コンテナ)をホストで実行する。

.DESCRIPTION
  中身は scripts/host-setup.sh。**ここは送り込むだけ**で、判断は一切しない。
  ロジックを2つ持つと、片方だけ直されて食い違う。

  ## なぜ標準入力で送るのか

  `deploy-to-host.ps1` の転送対象は src / compose / docker / nginx だけで、
  **scripts/ はホストへ配られない**。ファイルとして置くと、次に直したとき
  ホスト側が古いままになる(気づけない種類のずれ)。
  毎回そのときの中身を流し込めば、**古い版が動くことがない**。実行後に何も残らない。

  サーバーへ入って直に叩きたいときは、host-setup.sh をコピーして

      ./host-setup.sh --fix --up

  としてもよい。同じものが動く。

.PARAMETER Fix
  所有権と権限を直す。**付けなければ調べて報告するだけ。**

.PARAMETER Up
  docker compose up -d まで行う。compose.yaml や nginx を変えたときに。

.PARAMETER Composer
  vendor/ を入れ直す。composer.json / composer.lock を変えたときに。

.EXAMPLE
  # まず調べる(何も変えない)
  .\host-setup.ps1

.EXAMPLE
  # 配備のあとの定番
  .\host-setup.ps1 -Fix -Up
#>
[CmdletBinding()]
param(
    [switch]$Fix,
    [switch]$Up,
    [switch]$Composer,
    # ホスト側の置き場。既定は docker が知っている場所から自動で決める
    [string]$RemotePath,
    [string]$HostName = 'ito4.jp',
    # 置き場の持ち主で繋ぐ。2026-09-18 から kmops(docs/12 §7-4 B 段 2)
    [string]$User = 'kmops',
    [string]$KeyPath = "$env:USERPROFILE\.ssh\km_ops",
    [int]$Port = 22,
    [switch]$Interactive
)

$ErrorActionPreference = 'Stop'

$scriptPath = Join-Path $PSScriptRoot 'host-setup.sh'
if (-not (Test-Path $scriptPath)) { throw "host-setup.sh が見つかりません: $scriptPath" }

. (Join-Path $PSScriptRoot 'km-ssh.ps1')
Initialize-KmSsh -HostName $HostName -User $User -KeyPath $KeyPath -Port $Port -Interactive:$Interactive
Assert-KmSshReady

<#
  置き場を決める。
  **こちらで決め打ちしない。** web コンテナの mount 元をホストに聞けば、
  実際に動いている場所が分かる(Old/new-map-release.ps1 が使っていた手)。
  src の1つ上が compose.yaml のある階層。
#>
if (-not $RemotePath) {
    # 名前ではなく compose のサービスの札で引く(km-ssh.ps1)。
    # **コンテナ名を変えても動く** —— 2026-09-07 に dev-* から km-* へ改めた
    $RemotePath = Get-KmRemoteProjectPath -AllowFailure
    if ($RemotePath -ne '') {
        Write-Host "置き場: $RemotePath (web コンテナの mount 元から判断)"
    } else {
        # コンテナが止まっていると聞けない。既定へ落として、そのことを言う
        $RemotePath = '/opt/kosenmap'
        Write-Host "置き場: $RemotePath (コンテナに聞けなかったので既定)" -ForegroundColor Yellow
    }
}

<#
  引数は標準入力では渡せないので、スクリプトの中の DEFAULT_ARGS へ差し込む。

  **$args という名前は使わない。** PowerShell の自動変数と衝突する。
#>
$shArgs = @('--path', $RemotePath)
if ($Fix) { $shArgs += '--fix' }
if ($Up) { $shArgs += '--up' }
if ($Composer) { $shArgs += '--composer' }

<#
  **UTF-8 だと言い切って読む。**

  `Get-Content -Raw` は BOM の無いファイルを Windows PowerShell 5.1 では
  ANSI(CP932)として読む。host-setup.sh は BOM 無し UTF-8 なので、
  そのまま読むと日本語のコメントが化けたままホストへ送られる。
  **.sh に BOM を付けて解決してはいけない** —— 先頭は `#!/bin/sh` でなければならず、
  BOM が挟まると shebang として読まれない。読む側で決める。
#>
$body = [IO.File]::ReadAllText($scriptPath, (New-Object Text.UTF8Encoding $false))

# 万一 BOM が混ざっていたら落とす。先頭に見えない3バイトが残ると sh が面食らう
$body = $body.TrimStart([char]0xFEFF)

if ($body -notmatch '(?m)^DEFAULT_ARGS=""$') {
    throw 'host-setup.sh に DEFAULT_ARGS の差し込み口がありません。'
}
$body = $body -replace '(?m)^DEFAULT_ARGS=""$', ('DEFAULT_ARGS="' + ($shArgs -join ' ') + '"')

if (-not $Fix) {
    Write-Host ''
    Write-Host '調べるだけです。直すには -Fix を付けてください。' -ForegroundColor Yellow
}

# AllowFailure。**気になる点が残ると sh 側は 1 で終わる**ので、
# それを「転送の失敗」と混同しない。中身は下で判断する。
$output = Invoke-KmSshStdin -Script $body -Shell sh -AllowFailure
$output | ForEach-Object { Write-Host $_ }

<#
  **何も返ってこないのは「異常なし」ではない。**
  接続やスクリプトの起動そのものが失敗すると出力が空になる。
  それを「気になる点あり」と同じ扱いにすると、原因から遠ざかる。
#>
if (-not $output) {
    throw 'ホストから何も返ってきませんでした。接続または sh の起動に失敗しています。'
}

if ($output -match 'すべて問題ありません') {
    exit 0
}

Write-Host ''
Write-Host '気になる点が残っています。上の出力を確認してください。' -ForegroundColor Yellow
exit 1
