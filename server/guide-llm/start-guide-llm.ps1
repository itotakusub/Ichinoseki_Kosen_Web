<#
.SYNOPSIS
案内チャットの LLM を自宅 PC から出す(門番 server.py を起こし、ngrok で https に出す)。WSL は使わない。

.DESCRIPTION
2026-10-24、利用者の企画書「模擬店案内・会場内ルート案内システム」。Unsloth と ngrok は必須(利用者の決定)。
2026-10-25 に作り直した(利用者の指示「WSL は使わない」「Unsloth に API リクエストする」)。

  VPS の PHP ─ https ─ ngrok ─ 門番 server.py(127.0.0.1:GUIDE_LLM_PORT)─ Unsloth の API(UNSLOTH_API_URL)

  1. guide-llm.env を読む(見本は guide-llm.env.example。鍵は秘密なので guide-llm.env は git に入れない)
  2. Unsloth の API が答えるか確かめる(先に Unsloth で模型を読んで、API を出しておくこと)
  3. 門番 server.py を起こす(Windows の python。標準ライブラリだけなので入れるものは無い)。
     鍵は**コマンドの文字列に載せず**、環境変数で渡す(プロセスの一覧に鍵が出ない)
  4. ngrok を固定のドメインで起こす(Ctrl+C で ngrok を止めると、門番も止める)

Website 側: .env の KM_GUIDE_LLM_URL に https://<NGROK_DOMAIN> を、KM_GUIDE_LLM_KEY に同じ鍵を入れて配備する。
管理画面「案内チャット」の「届くか確かめる」で確かめる。自宅 PC を止めても、地図の案内は言葉で探した結果だけ出す。

初めてのとき(1 回だけ):
  - ngrok を入れてログインする(winget install ngrok.ngrok → ngrok config add-authtoken <トークン>)。固定のドメインをダッシュボードで 1 つ取る
  - guide-llm.env.example を guide-llm.env に写して埋める(UNSLOTH_API_URL は Unsloth の API の場所)

模型を選ぶとき: Unsloth で読む模型を 4 つ(Gemma4 E4B / 12B QAT / Qwen3.5-9B / GPT-OSS-20B)のどれかにし、
guide-llm.env の GUIDE_LLM_MODEL を合わせて -Bench で測る(bench.py。速さの中央値と、返事が JSON として読めた数を出す)。

.PARAMETER Bench
門番を起こし、bench.py で 20 件の質問を測ってから止める(ngrok は起こさない)

.PARAMETER NoNgrok
門番だけを起こす(手元で試すとき)

.EXAMPLE
pwsh -File start-guide-llm.ps1

.EXAMPLE
pwsh -File start-guide-llm.ps1 -Bench
#>
[CmdletBinding()]
param(
    [switch]$Bench,
    [switch]$NoNgrok,
    # オプションとその意味を出して終わる
    [switch]$Help
)

if ($Help) {
    $kmHelp = Join-Path $PSScriptRoot '..\scripts\km-help.ps1'
    if (Test-Path $kmHelp) { & $kmHelp -Path $PSCommandPath } else { Get-Help $PSCommandPath -Detailed }
    exit 0
}

$ErrorActionPreference = 'Stop'

# ---- 1. 設定を読む ----
$envFile = Join-Path $PSScriptRoot 'guide-llm.env'
if (-not (Test-Path -LiteralPath $envFile)) {
    throw "guide-llm.env がありません。guide-llm.env.example を写して埋めてください: $envFile"
}
$conf = @{}
foreach ($line in Get-Content -LiteralPath $envFile -Encoding UTF8) {
    if ($line -match '^\s*#' -or $line -notmatch '=') { continue }
    $name, $value = $line -split '=', 2
    $conf[$name.Trim()] = $value.Trim()
}
$port = if ($conf['GUIDE_LLM_PORT']) { [int]$conf['GUIDE_LLM_PORT'] } else { 8888 }
$upstream = ($conf['UNSLOTH_API_URL'] ?? '').TrimEnd('/')
# ドメインは https:// を付けても付けなくてもよい
$domain = ($conf['NGROK_DOMAIN'] ?? '') -replace '^https?://', '' -replace '/+$', ''

if (-not $conf['GUIDE_LLM_MODEL']) { throw 'guide-llm.env の GUIDE_LLM_MODEL(Unsloth に読ませている模型の名前)を入れてください。' }
if (($conf['KM_GUIDE_LLM_KEY'] ?? '').Length -lt 32) { throw 'guide-llm.env の KM_GUIDE_LLM_KEY は 32 字以上にしてください。' }
if ($upstream -notmatch '^https?://(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$') {
    throw "guide-llm.env の UNSLOTH_API_URL に、この PC の中の Unsloth の API を入れてください(例: http://127.0.0.1:8000)。いま: '$upstream'"
}
if (-not $Bench -and -not $NoNgrok) {
    if (-not $domain) { throw 'guide-llm.env の NGROK_DOMAIN(ngrok の固定のドメイン)を入れてください。' }
    if (-not (Get-Command ngrok -ErrorAction SilentlyContinue)) { throw 'ngrok が見つかりません(winget install ngrok.ngrok → ngrok config add-authtoken …)。' }
}
$python = (Get-Command python -ErrorAction SilentlyContinue) ?? (Get-Command py -ErrorAction SilentlyContinue)
if (-not $python) { throw 'python が見つかりません(門番 server.py を動かすのに使います。標準ライブラリだけで動きます)。' }

# ---- 2. Unsloth の API が答えるか ----
$upstreamHeaders = @{}
if ($conf['UNSLOTH_API_KEY']) { $upstreamHeaders['Authorization'] = 'Bearer ' + $conf['UNSLOTH_API_KEY'] }
try {
    $null = Invoke-RestMethod -Uri "$upstream/v1/models" -Headers $upstreamHeaders -TimeoutSec 5
} catch {
    throw "Unsloth の API が答えません($upstream/v1/models)。先に Unsloth で模型を読み、API を出してください。"
}
Write-Host "Unsloth の API が答えました: $upstream" -ForegroundColor Green

# ---- 3. 門番を起こす(鍵は環境変数で渡す) ----
$env:GUIDE_LLM_MODEL = $conf['GUIDE_LLM_MODEL']
$env:KM_GUIDE_LLM_KEY = $conf['KM_GUIDE_LLM_KEY']
$env:GUIDE_LLM_PORT = "$port"
$env:UNSLOTH_API_URL = $upstream
$env:UNSLOTH_API_KEY = $conf['UNSLOTH_API_KEY'] ?? ''
$server = Start-Process -FilePath $python.Source -ArgumentList @((Join-Path $PSScriptRoot 'server.py')) -PassThru -NoNewWindow

try {
    $ready = $false
    foreach ($i in 1..30) {
        if ($server.HasExited) { throw '門番 server.py が止まりました(上の出力を見てください)。' }
        try {
            $null = Invoke-RestMethod -Uri "http://127.0.0.1:$port/health" -TimeoutSec 3
            $ready = $true
            break
        } catch {
            Start-Sleep -Milliseconds 500
        }
    }
    if (-not $ready) { throw '門番が立ち上がりませんでした。' }
    Write-Host "門番が立ち上がりました(http://127.0.0.1:$port)。" -ForegroundColor Green

    if ($Bench) {
        & $python.Source (Join-Path $PSScriptRoot 'bench.py')
        exit $LASTEXITCODE
    }
    if ($NoNgrok) {
        Write-Host 'ngrok は起こしません。Ctrl+C で止めます。'
        Wait-Process -Id $server.Id
        exit 0
    }

    # ---- 4. ngrok(固定のドメイン)。止めたら門番も止める ----
    Write-Host "ngrok で出します: https://$domain  (Ctrl+C で止める)"
    & ngrok http --url="https://$domain" $port
} finally {
    if (-not $server.HasExited) { Stop-Process -Id $server.Id -ErrorAction SilentlyContinue }
    Remove-Item Env:KM_GUIDE_LLM_KEY, Env:UNSLOTH_API_KEY -ErrorAction SilentlyContinue
}
