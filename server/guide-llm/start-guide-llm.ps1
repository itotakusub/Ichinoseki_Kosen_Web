<#
.SYNOPSIS
案内チャットの LLM を自宅 PC から出す(門番 server.py を起こし、ngrok で https に出す)。WSL は使わない。

.DESCRIPTION
2026-10-24、利用者の企画書「模擬店案内・会場内ルート案内システム」。ngrok は必須(利用者の決定)。
2026-10-25 に作り直した(利用者の指示「WSL は使わない」「Unsloth に API リクエストする」)。
同じ日に、頼む先を Unsloth から Bionic に替えた(利用者「Unsloth にバグが報告されていて使えないので Bionic に代替」)。

2026-10-29、利用者「Unsloth API が使えるようになったので変更」「AI を選択できるように」: 頼み先を 2 つ持つ。

  VPS の PHP ─ https ─ ngrok ─ 門番 server.py(127.0.0.1:GUIDE_LLM_PORT)─ Unsloth の API(UNSLOTH_API_URL)
                                                                          └ Bionic の API(BIONIC_API_URL)

  1. guide-llm.env を読む(見本は guide-llm.env.example。鍵は秘密なので guide-llm.env は git に入れない)
  2. 既定の頼み先(GUIDE_LLM_BACKEND)が答えるか確かめる。もう片方は書いてあれば答えるかだけ出す
  3. 門番 server.py を起こす(Windows の python。標準ライブラリだけなので入れるものは無い)。
     門番は既定の模型(GUIDE_LLM_MODEL。空なら頼み先でいま読んでいる模型)を、**読み込み済みでもアンロードしてから**
     読み込む(2026-10-25、利用者「既にロードされていたらアンロードからロード」)。ほかの模型は外す(GPU に 1 つだけ)。
     管理画面「案内チャット」で模型を選ぶと、門番がその模型に読み替える。
     鍵は**コマンドの文字列に載せず**、環境変数で渡す(プロセスの一覧に鍵が出ない)
  4. ngrok を固定のドメインで起こす(Ctrl+C で ngrok を止めると、門番も止める)

Website 側: .env の KM_GUIDE_LLM_URL に https://<NGROK_DOMAIN> を、KM_GUIDE_LLM_KEY に同じ鍵を入れて配備する。
管理画面「案内チャット」の「届くか確かめる」で確かめる。自宅 PC を止めても、地図の案内は言葉で探した結果だけ出す。

初めてのとき(1 回だけ):
  - ngrok を入れてログインする(winget install ngrok.ngrok → ngrok config add-authtoken <トークン>)。固定のドメインをダッシュボードで 1 つ取る
  - guide-llm.env.example を guide-llm.env に写して埋める(UNSLOTH_API_URL / BIONIC_API_URL は API の場所。
    Unsloth は鍵が要る: Unsloth の画面で API キーを作り、UNSLOTH_API_KEY に入れる)

模型を選ぶとき: 管理画面「案内チャット」の「模型」で選ぶ(既定は guide-llm.env の GUIDE_LLM_BACKEND / GUIDE_LLM_MODEL)。
速さは -Bench で測る(bench.py。既定の模型で、速さの中央値と、返事が JSON として読めた数を出す)。

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
$port = if ($conf['GUIDE_LLM_PORT']) { [int]$conf['GUIDE_LLM_PORT'] } else { 8192 }
# ドメインは https:// を付けても付けなくてもよい
$domain = ($conf['NGROK_DOMAIN'] ?? '') -replace '^https?://', '' -replace '/+$', ''

# 頼み先(末尾の / と /v1 は外す)。Bionic は古い名前 LLM_API_URL / LLM_API_KEY でも読む
$baseUrl = { param($raw) (($raw ?? '').Trim().TrimEnd('/') -replace '/v1$', '').TrimEnd('/') }
$backends = [ordered]@{}
if ($conf['UNSLOTH_API_URL']) { $backends['unsloth'] = @{ url = & $baseUrl $conf['UNSLOTH_API_URL']; key = $conf['UNSLOTH_API_KEY'] ?? '' } }
$bionicUrl = if ($conf['BIONIC_API_URL']) { $conf['BIONIC_API_URL'] } else { $conf['LLM_API_URL'] }
$bionicKey = if ($conf['BIONIC_API_KEY']) { $conf['BIONIC_API_KEY'] } else { $conf['LLM_API_KEY'] ?? '' }
if ($bionicUrl) { $backends['bionic'] = @{ url = & $baseUrl $bionicUrl; key = $bionicKey } }
if ($backends.Count -eq 0) { throw 'guide-llm.env に UNSLOTH_API_URL か BIONIC_API_URL(この PC の中の API の場所)を入れてください。' }
$default = ($conf['GUIDE_LLM_BACKEND'] ?? '').ToLower()
if (-not $default -and $backends.Count -eq 1) { $default = @($backends.Keys)[0] }
if (-not $backends.Contains($default)) { throw "guide-llm.env の GUIDE_LLM_BACKEND は $(@($backends.Keys) -join ' / ') のどれかにしてください。いま: '$default'" }
if (($conf['KM_GUIDE_LLM_KEY'] ?? '').Length -lt 32) { throw 'guide-llm.env の KM_GUIDE_LLM_KEY は 32 字以上にしてください。' }
foreach ($name in $backends.Keys) {
    if ($backends[$name].url -notmatch '^https?://(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$') {
        throw "guide-llm.env の $($name.ToUpper())_API_URL に、この PC の中の API を入れてください(例: http://127.0.0.1:8888)。いま: '$($backends[$name].url)'"
    }
}
if ($port -in @($backends.Values | ForEach-Object { if ($_.url -match ':(\d+)$') { [int]$Matches[1] } })) {
    throw "門番の番号 GUIDE_LLM_PORT($port)が頼み先の API と同じです。別の番号(例: 8192)にしてください。"
}
if (-not $Bench -and -not $NoNgrok) {
    if (-not $domain) { throw 'guide-llm.env の NGROK_DOMAIN(ngrok の固定のドメイン)を入れてください。' }
    if (-not (Get-Command ngrok -ErrorAction SilentlyContinue)) { throw 'ngrok が見つかりません(winget install ngrok.ngrok → ngrok config add-authtoken …)。' }
}
# 本物の python を探す。「python」は Microsoft Store の案内(WindowsApps)に当たることがあるので、動かして確かめる
# (2026-10-29、Python 3.13 を外して C:\Python315 にした PC で「Python was not found」と出た)。py ランチャーを先に見る
# 同じ日に C:\Python315 は DLL が欠けていて動かなかったので、uv の python(uv python find)も見る。
# guide-llm.env の GUIDE_LLM_PYTHON に置き場所を書けば、それを先に使う
# C:\Python315 は Lib(標準ライブラリ)が無いまま「動いてしまう」(警告を出して終了コード 0)ので、
# 門番が使う標準ライブラリのファイルが在るかまで確かめる(2026-10-29 の -Bench で警告が出て止まった)
$pythonProbe = "import os, sys, sysconfig; lib = sysconfig.get_paths()['stdlib']; " +
    "ok = all(os.path.isfile(os.path.join(lib, *p)) for p in (('http', 'server.py'), ('json', '__init__.py'), ('urllib', 'request.py'))); " +
    "print(sys.executable if ok else '')"
$pythonExe = $null
$candidates = @()
if ($conf['GUIDE_LLM_PYTHON']) { $candidates += , @($conf['GUIDE_LLM_PYTHON']) }
$candidates += , @('py', '-3')
$candidates += , @('python')
$candidates += , @('python3')
if (Get-Command uv -ErrorAction SilentlyContinue) {
    $uvPython = try { (& uv python find 2>$null | Select-Object -First 1) } catch { $null }
    if ($uvPython) { $candidates += , @("$uvPython".Trim()) }
}
foreach ($candidate in $candidates) {
    if (-not (Get-Command $candidate[0] -ErrorAction SilentlyContinue)) { continue }
    $found = try { & $candidate[0] @($candidate | Select-Object -Skip 1) -c $pythonProbe 2>$null | Select-Object -Last 1 } catch { $null }
    if ($LASTEXITCODE -eq 0 -and "$found".Trim() -and (Test-Path -LiteralPath "$found".Trim())) { $pythonExe = "$found".Trim(); break }
    Write-Host ("python の候補 {0} は動かないか標準ライブラリが欠けているので使いません。" -f ($candidate -join ' ')) -ForegroundColor Yellow
}
Write-Host "python: $pythonExe"
if (-not $pythonExe) { throw 'python が見つかりません(門番 server.py を動かすのに使います。標準ライブラリだけで動きます)。' }
$python = [pscustomobject]@{ Source = $pythonExe }

# ---- 2. 頼み先が答えるか(模型の一覧・読み込みは門番がする) ----
foreach ($name in $backends.Keys) {
    $headers = @{}
    if ($backends[$name].key) { $headers['Authorization'] = 'Bearer ' + $backends[$name].key }
    $status = try { (Invoke-WebRequest -Uri "$($backends[$name].url)/v1/models" -Headers $headers -TimeoutSec 5 -SkipHttpErrorCheck).StatusCode } catch { 0 }
    $label = if ($name -eq $default) { "$name(既定)" } else { $name }
    if ($status -eq 200) {
        Write-Host "$label の API が答えました: $($backends[$name].url)" -ForegroundColor Green
    } elseif ($name -ne $default) {
        Write-Warning "$label の API が答えません($status)。管理画面では $name の模型は選べません。"
    } elseif ($status -in 401, 403) {
        throw "$label の API が鍵を断りました($status)。guide-llm.env の $($name.ToUpper())_API_KEY を確かめてください(Unsloth は画面で API キーを作る)。"
    } else {
        throw "$label の API が答えません($($backends[$name].url)/v1/models)。先に $name を起こし、API を出してください。"
    }
}

# ---- 3. 門番を起こす(鍵は環境変数で渡す)。既定の模型を読み直すまで待つ ----
$env:GUIDE_LLM_BACKEND = $default
$env:GUIDE_LLM_MODEL = $conf['GUIDE_LLM_MODEL'] ?? ''
$env:GUIDE_LLM_REASONING_EFFORT = $conf['GUIDE_LLM_REASONING_EFFORT'] ?? ''
$env:GUIDE_LLM_CONTEXT_LENGTH = $conf['GUIDE_LLM_CONTEXT_LENGTH'] ?? ''
$env:KM_GUIDE_LLM_KEY = $conf['KM_GUIDE_LLM_KEY']
$env:GUIDE_LLM_PORT = "$port"
$env:UNSLOTH_API_URL = if ($backends.Contains('unsloth')) { $backends['unsloth'].url } else { '' }
$env:UNSLOTH_API_KEY = if ($backends.Contains('unsloth')) { $backends['unsloth'].key } else { '' }
$env:BIONIC_API_URL = if ($backends.Contains('bionic')) { $backends['bionic'].url } else { '' }
$env:BIONIC_API_KEY = if ($backends.Contains('bionic')) { $backends['bionic'].key } else { '' }
Remove-Item Env:LLM_API_URL, Env:LLM_API_KEY -ErrorAction SilentlyContinue
$server = Start-Process -FilePath $python.Source -ArgumentList @((Join-Path $PSScriptRoot 'server.py')) -PassThru -NoNewWindow

try {
    # 模型の読み直しに数分かかることがある(大きい模型)。15 分まで待つ
    $ready = $false
    $deadline = (Get-Date).AddMinutes(15)
    while ((Get-Date) -lt $deadline) {
        if ($server.HasExited) { throw '門番 server.py が止まりました(上の出力を見てください)。' }
        try {
            $null = Invoke-RestMethod -Uri "http://127.0.0.1:$port/health" -TimeoutSec 3
            $ready = $true
            break
        } catch {
            Start-Sleep -Seconds 1
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
    Remove-Item Env:KM_GUIDE_LLM_KEY, Env:UNSLOTH_API_KEY, Env:BIONIC_API_KEY -ErrorAction SilentlyContinue
}
