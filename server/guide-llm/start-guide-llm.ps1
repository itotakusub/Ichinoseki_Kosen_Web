<#
.SYNOPSIS
案内チャットの LLM を自宅 PC から出す(門番 server.py を起こし、ngrok で https に出す)。WSL は使わない。

.DESCRIPTION
2026-10-24、利用者の企画書「模擬店案内・会場内ルート案内システム」。ngrok は必須(利用者の決定)。
2026-10-25 に作り直した(利用者の指示「WSL は使わない」「Unsloth に API リクエストする」)。
同じ日に、頼む先を Unsloth から Bionic に替えた(利用者「Unsloth にバグが報告されていて使えないので Bionic に代替」)。

  VPS の PHP ─ https ─ ngrok ─ 門番 server.py(127.0.0.1:GUIDE_LLM_PORT)─ Bionic の API(LLM_API_URL)

  1. guide-llm.env を読む(見本は guide-llm.env.example。鍵は秘密なので guide-llm.env は git に入れない)
  2. Bionic の API が答えるか確かめる(先に Bionic で模型を読んで、API を出しておくこと)
  3. 門番 server.py を起こす(Windows の python。標準ライブラリだけなので入れるものは無い)。
     門番は、GUIDE_LLM_MODEL の識別名が Bionic の /v1/models にあるかを確かめ、無ければ止まる。
     鍵は**コマンドの文字列に載せず**、環境変数で渡す(プロセスの一覧に鍵が出ない)
  4. ngrok を固定のドメインで起こす(Ctrl+C で ngrok を止めると、門番も止める)

Website 側: .env の KM_GUIDE_LLM_URL に https://<NGROK_DOMAIN> を、KM_GUIDE_LLM_KEY に同じ鍵を入れて配備する。
管理画面「案内チャット」の「届くか確かめる」で確かめる。自宅 PC を止めても、地図の案内は言葉で探した結果だけ出す。

初めてのとき(1 回だけ):
  - ngrok を入れてログインする(winget install ngrok.ngrok → ngrok config add-authtoken <トークン>)。固定のドメインをダッシュボードで 1 つ取る
  - guide-llm.env.example を guide-llm.env に写して埋める(LLM_API_URL は Bionic の API の場所)

模型を選ぶとき: Bionic で読む模型を 4 つ(Gemma4 E4B / 12B QAT / Qwen3.5-9B / GPT-OSS-20B)のどれかにし、
guide-llm.env の GUIDE_LLM_MODEL(識別名)と GUIDE_LLM_FAMILY(4 つのどれか)を合わせて -Bench で測る
(bench.py。速さの中央値と、返事が JSON として読めた数を出す)。

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
$upstream = ($conf['LLM_API_URL'] ?? '').TrimEnd('/')
# ドメインは https:// を付けても付けなくてもよい
$domain = ($conf['NGROK_DOMAIN'] ?? '') -replace '^https?://', '' -replace '/+$', ''

if ($conf.ContainsKey('UNSLOTH_API_URL') -and -not $conf.ContainsKey('LLM_API_URL')) {
    throw 'guide-llm.env の UNSLOTH_API_URL / UNSLOTH_API_KEY は LLM_API_URL / LLM_API_KEY に名前が変わりました(2026-10-25、Bionic へ替えた)。'
}
if (-not $conf['GUIDE_LLM_MODEL']) { throw 'guide-llm.env の GUIDE_LLM_MODEL(Bionic に読ませている模型の識別名)を入れてください。' }
if (($conf['KM_GUIDE_LLM_KEY'] ?? '').Length -lt 32) { throw 'guide-llm.env の KM_GUIDE_LLM_KEY は 32 字以上にしてください。' }
if ($upstream -notmatch '^https?://(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$') {
    throw "guide-llm.env の LLM_API_URL に、この PC の中の Bionic の API を入れてください(例: http://127.0.0.1:1235)。いま: '$upstream'"
}
if (-not $Bench -and -not $NoNgrok) {
    if (-not $domain) { throw 'guide-llm.env の NGROK_DOMAIN(ngrok の固定のドメイン)を入れてください。' }
    if (-not (Get-Command ngrok -ErrorAction SilentlyContinue)) { throw 'ngrok が見つかりません(winget install ngrok.ngrok → ngrok config add-authtoken …)。' }
}
$python = (Get-Command python -ErrorAction SilentlyContinue) ?? (Get-Command py -ErrorAction SilentlyContinue)
if (-not $python) { throw 'python が見つかりません(門番 server.py を動かすのに使います。標準ライブラリだけで動きます)。' }

# ---- 2. Bionic の API が答えるか(模型が読み込まれているかは門番が /v1/models で確かめる) ----
$upstreamHeaders = @{}
if ($conf['LLM_API_KEY']) { $upstreamHeaders['Authorization'] = 'Bearer ' + $conf['LLM_API_KEY'] }
try {
    # 一覧の口が無い API(404)でも、答えてはいるので先へ進む
    $probe = Invoke-WebRequest -Uri "$upstream/v1/models" -Headers $upstreamHeaders -TimeoutSec 5 -SkipHttpErrorCheck
} catch {
    throw "Bionic の API が答えません($upstream/v1/models)。先に Bionic で模型を読み、API を出してください。"
}
if ($probe.StatusCode -in 401, 403) { throw "Bionic の API が鍵を断りました($($probe.StatusCode))。guide-llm.env の LLM_API_KEY を確かめてください。" }
Write-Host "Bionic の API が答えました: $upstream" -ForegroundColor Green

# ---- 3. 門番を起こす(鍵は環境変数で渡す) ----
$env:GUIDE_LLM_MODEL = $conf['GUIDE_LLM_MODEL']
$env:GUIDE_LLM_FAMILY = $conf['GUIDE_LLM_FAMILY'] ?? ''
$env:KM_GUIDE_LLM_KEY = $conf['KM_GUIDE_LLM_KEY']
$env:GUIDE_LLM_PORT = "$port"
$env:LLM_API_URL = $upstream
$env:LLM_API_KEY = $conf['LLM_API_KEY'] ?? ''
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
    Remove-Item Env:KM_GUIDE_LLM_KEY, Env:LLM_API_KEY -ErrorAction SilentlyContinue
}
