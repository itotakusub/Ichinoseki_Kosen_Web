<#
.SYNOPSIS
案内チャットの LLM を自宅 PC で動かす(Unsloth を WSL で起こし、ngrok で https に出す)。

.DESCRIPTION
2026-10-24、利用者の企画書「模擬店案内・会場内ルート案内システム」。Unsloth と ngrok は必須(利用者の決定)。

  1. guide-llm.env を読む(見本は guide-llm.env.example。鍵は秘密なので git に入れない)
  2. WSL の Ubuntu で server.py を起こす(Unsloth で模型を読み、127.0.0.1 だけで待つ)。
     模型の名前と鍵は**コマンドの文字列に載せず**、WSLENV で渡す(プロセスの一覧に鍵が出ない)
  3. 立ち上がるまで待つ(初回は模型の取り込みで数分〜十数分)
  4. ngrok を固定のドメインで起こす(Ctrl+C で ngrok を止めると、server.py も止める)

Website 側: .env の KM_GUIDE_LLM_URL に https://<NGROK_DOMAIN> を、KM_GUIDE_LLM_KEY に同じ鍵を入れて配備する。
管理画面「案内チャット」の「届くか確かめる」で確かめる。自宅 PC を止めても、地図の案内は言葉で探した結果だけ出す。

初めてのとき(1 回だけ):
  - WSL の Ubuntu と NVIDIA の WSL 用ドライバを入れる(nvidia-smi が WSL の中で動くこと)
  - ngrok を入れてログインする(winget install ngrok.ngrok → ngrok config add-authtoken <トークン>)。固定のドメインをダッシュボードで 1 つ取る
  - guide-llm.env を作って埋める → pwsh -File start-guide-llm.ps1 -Setup(仮想環境と Unsloth を入れる)

模型を選ぶとき: guide-llm.env の GUIDE_LLM_MODEL を 4 つ(Gemma4 E4B / 12B QAT / Qwen3.5-9B / GPT-OSS-20B)の
どれかにして -Bench で測る(bench.py。速さの中央値と、返事が JSON として読めた数を出す)。

.PARAMETER Setup
WSL の中に仮想環境(.venv)を作り、requirements.txt を入れて終わる

.PARAMETER Bench
server.py を起こし、bench.py で 20 件の質問を測ってから止める(ngrok は起こさない)

.PARAMETER NoNgrok
server.py だけを起こす(手元で試すとき)

.EXAMPLE
pwsh -File start-guide-llm.ps1

.EXAMPLE
pwsh -File start-guide-llm.ps1 -Bench
#>
[CmdletBinding()]
param(
    [switch]$Setup,
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
$distro = if ($conf['WSL_DISTRO']) { $conf['WSL_DISTRO'] } else { 'Ubuntu' }
$wslDir = $conf['WSL_DIR']
$port = if ($conf['GUIDE_LLM_PORT']) { [int]$conf['GUIDE_LLM_PORT'] } else { 8000 }
if (-not $wslDir) { throw 'guide-llm.env の WSL_DIR(WSL の中でこのフォルダを指すパス)を入れてください。' }
# パスに ' が入るとコマンドが壊れる。決まった字だけ通す
if ($wslDir -notmatch '^/[A-Za-z0-9_./ -]+$') { throw "WSL_DIR に使えない字があります: $wslDir" }

if ($Setup) {
    Write-Host "WSL($distro)に仮想環境を作り、Unsloth などを入れます(数分〜十数分)…"
    wsl -d $distro -- bash -lc "cd '$wslDir' && python3 -m venv .venv && . .venv/bin/activate && pip install -U pip && pip install -r requirements.txt"
    if ($LASTEXITCODE -ne 0) { throw '入れられませんでした(上の出力を見てください)。' }
    Write-Host '入れました。次は pwsh -File start-guide-llm.ps1 -Bench で模型を測ってください。' -ForegroundColor Green
    exit 0
}

if (-not $conf['GUIDE_LLM_MODEL']) { throw 'guide-llm.env の GUIDE_LLM_MODEL(模型の名前)を入れてください。' }
if (($conf['KM_GUIDE_LLM_KEY'] ?? '').Length -lt 32) { throw 'guide-llm.env の KM_GUIDE_LLM_KEY は 32 字以上にしてください。' }
if (-not $Bench -and -not $NoNgrok) {
    if (-not $conf['NGROK_DOMAIN']) { throw 'guide-llm.env の NGROK_DOMAIN(ngrok の固定のドメイン)を入れてください。' }
    if (-not (Get-Command ngrok -ErrorAction SilentlyContinue)) { throw 'ngrok が見つかりません(winget install ngrok.ngrok → ngrok config add-authtoken …)。' }
}

# ---- 2. server.py を起こす(鍵は WSLENV で渡す) ----
$env:GUIDE_LLM_MODEL = $conf['GUIDE_LLM_MODEL']
$env:KM_GUIDE_LLM_KEY = $conf['KM_GUIDE_LLM_KEY']
$env:GUIDE_LLM_PORT = "$port"
$env:GUIDE_LLM_MAX_SEQ = if ($conf['GUIDE_LLM_MAX_SEQ']) { $conf['GUIDE_LLM_MAX_SEQ'] } else { '4096' }
$env:WSLENV = 'GUIDE_LLM_MODEL/u:KM_GUIDE_LLM_KEY/u:GUIDE_LLM_PORT/u:GUIDE_LLM_MAX_SEQ/u'

Write-Host "模型を読みます: $($conf['GUIDE_LLM_MODEL'])"
$server = Start-Process -FilePath 'wsl' -ArgumentList @('-d', $distro, '--', 'bash', '-lc', "cd '$wslDir' && . .venv/bin/activate && exec python server.py") -PassThru -NoNewWindow

try {
    # ---- 3. 立ち上がるまで待つ(初回は模型の取り込みで長い) ----
    $deadline = (Get-Date).AddMinutes(20)
    $ready = $false
    while ((Get-Date) -lt $deadline) {
        if ($server.HasExited) { throw 'server.py が止まりました(上の出力を見てください)。' }
        try {
            $health = Invoke-RestMethod -Uri "http://127.0.0.1:$port/health" -TimeoutSec 3
            if ($health.ok) { $ready = $true; break }
        } catch {
            # まだ読んでいる
        }
        Start-Sleep -Seconds 5
    }
    if (-not $ready) { throw '20 分待っても立ち上がりませんでした。' }
    Write-Host "立ち上がりました(http://127.0.0.1:$port)。" -ForegroundColor Green

    if ($Bench) {
        wsl -d $distro -- bash -lc "cd '$wslDir' && . .venv/bin/activate && python bench.py"
        exit $LASTEXITCODE
    }
    if ($NoNgrok) {
        Write-Host 'ngrok は起こしません。Ctrl+C で止めます。'
        Wait-Process -Id $server.Id
        exit 0
    }

    # ---- 4. ngrok(固定のドメイン)。止めたら server.py も止める ----
    Write-Host "ngrok で出します: https://$($conf['NGROK_DOMAIN'])  (Ctrl+C で止める)"
    & ngrok http --url="https://$($conf['NGROK_DOMAIN'])" $port
} finally {
    if (-not $server.HasExited) {
        Stop-Process -Id $server.Id -ErrorAction SilentlyContinue
        # wsl の中の python も止める(127.0.0.1:$port を待っているもの)
        wsl -d $distro -- bash -lc "pkill -f 'python server.py' || true" | Out-Null
    }
    Remove-Item Env:KM_GUIDE_LLM_KEY -ErrorAction SilentlyContinue
}
