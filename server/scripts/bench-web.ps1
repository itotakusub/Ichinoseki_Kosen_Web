<#
.SYNOPSIS
  この PC から本番の Web の応答を測り、ベンチマークの履歴に足す(2026-11-06)。

.DESCRIPTION
  決まった URL の組(静的ファイル 2 つと、配っている版の口 api/release.php)へ、合わせて -Requests 回を -Concurrency 本ずつ HTTPS で送る。
  測るもの: かかった時間・1 秒あたりの数・p50 / p95(ミリ秒)・失敗の数。

  結果は ssh(kmops)越しに `docker compose exec -T web php scripts/bench-record.php` へ渡し、
  管理画面のタスクマネージャー →「ベンチマーク」の履歴に「この PC から」として残る(-NoSave で残さない)。

  ## 回数の上限

  nginx の回数の制限(/api/ は 1 分に 240・まとめて 120)に掛からないよう、既定は 100 回・同時 5 本。
  上限は 300 回・同時 10 本(それ以上は受け付けない)。**家・学校など、測った場所でも数字は変わる**ので、
  名前(-Label)に場所を書いておくと比べやすい。

.EXAMPLE
  .\bench-web.ps1 -Label 家

.EXAMPLE
  # 保存しないで見るだけ
  .\bench-web.ps1 -NoSave
#>
[CmdletBinding()]
param(
    [string]$BaseUrl = 'https://ito4.jp',
    [ValidateRange(10, 300)][int]$Requests = 100,
    [ValidateRange(1, 10)][int]$Concurrency = 5,
    # 履歴に出す名前(任意。60 文字まで)
    [string]$Label = '',
    [switch]$NoSave,
    [string]$HostName = 'ito4.jp',
    [string]$User = 'kmops',
    [string]$KeyPath = "$env:USERPROFILE\.ssh\km_ops",
    [string]$RemotePath = '/opt/kosenmap',
    # オプションとその意味を出して終わる
    [switch]$Help
)

# -Help: オプションとその意味を出して終わる(説明はこのファイルの先頭のコメントから km-help.ps1 が読む)
if ($Help) {
    $kmHelp = Join-Path $PSScriptRoot 'km-help.ps1'
    if (Test-Path $kmHelp) { & $kmHelp -Path $PSCommandPath } else { Get-Help $PSCommandPath -Detailed }
    exit 0
}

$ErrorActionPreference = 'Stop'

# lib/bench.php の KM_BENCH_WEB_URLS と同じ組(中から測る段と比べられるように)
$paths = @('/Main/styles.css', '/Main/app.js', '/api/release.php')

$handler = [System.Net.Http.SocketsHttpHandler]::new()
$handler.AutomaticDecompression = [System.Net.DecompressionMethods]::All
$handler.AllowAutoRedirect = $false
$handler.MaxConnectionsPerServer = $Concurrency
$client = [System.Net.Http.HttpClient]::new($handler)
$client.Timeout = [TimeSpan]::FromSeconds(15)
$client.DefaultRequestHeaders.UserAgent.ParseAdd('KosenMap-bench/1')

# 温める(TLS の握手と DNS を測る数に入れない)
foreach ($p in $paths) {
    try { [void]$client.GetAsync($BaseUrl + $p).GetAwaiter().GetResult() } catch { }
}

$times = [System.Collections.Generic.List[double]]::new()
$errors = 0
$queue = [System.Collections.Generic.Queue[string]]::new()
for ($i = 0; $i -lt $Requests; $i++) { $queue.Enqueue($paths[$i % $paths.Count]) }

Write-Host "測っています: $Requests 回・同時 $Concurrency 本($BaseUrl)"
$wall = [System.Diagnostics.Stopwatch]::StartNew()
$inflight = @()
while ($queue.Count -gt 0 -or $inflight.Count -gt 0) {
    while ($queue.Count -gt 0 -and $inflight.Count -lt $Concurrency) {
        $sw = [System.Diagnostics.Stopwatch]::StartNew()
        $inflight += [pscustomobject]@{ Task = $client.GetAsync($BaseUrl + $queue.Dequeue()); Watch = $sw }
    }
    $index = [System.Threading.Tasks.Task]::WaitAny([System.Threading.Tasks.Task[]]@($inflight | ForEach-Object { $_.Task }))
    $done = $inflight[$index]
    $done.Watch.Stop()
    $times.Add($done.Watch.Elapsed.TotalMilliseconds)
    if ($done.Task.IsFaulted -or [int]$done.Task.Result.StatusCode -ne 200) {
        $errors++
    } else {
        # 本文まで読み切ってから次へ(読みかけで閉じると、測っているのはヘッダーまでになる)
        [void]$done.Task.Result.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
    }
    $inflight = @($inflight | Where-Object { $_ -ne $done })
}
$wall.Stop()
$client.Dispose()

$sorted = $times | Sort-Object
$at = { param($p) [math]::Round($sorted[[math]::Min($sorted.Count - 1, [math]::Floor($p * $sorted.Count))], 1) }
$seconds = $wall.Elapsed.TotalSeconds
$result = [ordered]@{
    label = $Label
    requests = $times.Count
    concurrency = $Concurrency
    errors = $errors
    seconds = [math]::Round($seconds, 4)
    rps = [math]::Round($times.Count / $seconds, 1)
    p50 = & $at 0.5
    p95 = & $at 0.95
}
Write-Host ("かかった時間 {0:N2} 秒・{1} 回/秒・p50 {2} ms・p95 {3} ms・失敗 {4}" -f $seconds, $result.rps, $result.p50, $result.p95, $errors)

if ($NoSave) {
    Write-Host '保存しませんでした(-NoSave)。'
    exit 0
}

$json = $result | ConvertTo-Json -Compress
$b64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($json))
# 本文は base64 で渡す(名前に空白や引用符があってもシェルで崩れない)
ssh -i $KeyPath -o BatchMode=yes "$User@$HostName" "cd '$RemotePath' && echo $b64 | base64 -d | docker compose exec -T web php scripts/bench-record.php"
if ($LASTEXITCODE -ne 0) {
    Write-Host '履歴に保存できませんでした(上の表示を確かめてください)。' -ForegroundColor Red
    exit 1
}
