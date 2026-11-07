<#
.SYNOPSIS
  プロジェクト状況(管理画面の「プロジェクト状況」・かんばん)を手元から読み書きする(2026-11-07)。鍵は server/docs/.env の KM_PROJ_PASSKEY。

.DESCRIPTION
  本番の api/project-tasks.php を叩く。鍵はヘッダー X-KM-Proj-Passkey で送り、**画面にも URL にも出さない。**
  本番に鍵を置くのは scripts/proj-passkey-sync.ps1。

  コマンド:
    list    一覧(-Topic・-Status・-PlanRef で絞る。-PlanRef は前方一致。例: -PlanRef 段7)
    get     1 件(-Id)
    add     足す(-Title は必須。-Status・-Progress・-Topic・-PlanRef・-Note)。-File に JSON の配列を渡すとまとめて足す(50 件まで・全部か無しか)
    update  直す(-Id と、変える項目だけ)。-Note '' で消す
    remove  消す(-Id。確かめてから。-Yes で確かめを省く)
    topics  分類と状態の一覧

  状態は done(完了)・progress(進行中)・decision(要判断)・todo(未着手)。
  分類は server・security・web・app・mapdata・devenv・monitor(空 = なし)。

.PARAMETER Command
  list / get / add / update / remove / topics。

.PARAMETER File
  add でまとめて足すときの JSON。[{"title":"…","topic":"web","planRef":"段7-1","note":"…"}, …]

.PARAMETER Json
  結果を JSON のまま出す(ほかの道具へ渡すとき)。

.EXAMPLE
  pwsh -File scripts\proj-tasks.ps1 list -PlanRef 段7
.EXAMPLE
  pwsh -File scripts\proj-tasks.ps1 add -Title 'Logto を 1.47 へ' -Topic server -PlanRef 段7-1 -Note '利用者が配備'
.EXAMPLE
  pwsh -File scripts\proj-tasks.ps1 update -Id 42 -Status done
.EXAMPLE
  pwsh -File scripts\proj-tasks.ps1 add -File C:\temp\stage7.json
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('list', 'get', 'add', 'update', 'remove', 'topics')]
    [string]$Command = 'list',
    # get・update・remove の対象
    [int]$Id = 0,
    [string]$Title,
    # done / progress / decision / todo
    [string]$Status,
    # 0〜100。省くと add は 0(done なら 100)、update は変えない
    [int]$Progress = -1,
    # 分類(list では絞り込み)
    [string]$Topic,
    # plan.md の段の番号(list では前方一致の絞り込み)
    [string]$PlanRef,
    # メモ。update で '' を渡すと消す
    [string]$Note,
    [string]$File = '',
    [switch]$Json,
    # remove の確かめを省く
    [switch]$Yes,
    [string]$BaseUrl = 'https://ito4.jp',
    # 鍵のファイル(既定 server/docs/.env)
    [string]$EnvPath = '',
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

if ($EnvPath -eq '') { $EnvPath = Join-Path (Split-Path -Parent $PSScriptRoot) 'docs\.env' }
if (-not (Test-Path -LiteralPath $EnvPath)) { throw "鍵のファイルがありません: $EnvPath" }
if ($BaseUrl -notmatch '^https://[A-Za-z0-9.-]+(:\d+)?$' -and $BaseUrl -notmatch '^http://(localhost|127\.0\.0\.1)(:\d+)?$') {
    throw "BaseUrl は https://<ホスト> か http://localhost にしてください: $BaseUrl"
}

$raw = [IO.File]::ReadAllText((Resolve-Path -LiteralPath $EnvPath).Path)
$found = [regex]::Matches($raw, '(?m)^[ \t]*KM_PROJ_PASSKEY[ \t]*=[ \t]*(\S*)[ \t]*\r?$')
if ($found.Count -ne 1 -or $found[0].Groups[1].Value.Length -lt 32) { throw "$EnvPath の KM_PROJ_PASSKEY= が読めないか、32 字より短いです。" }
$headers = @{ 'X-KM-Proj-Passkey' = $found[0].Groups[1].Value; 'Accept' = 'application/json' }
$endpoint = "$BaseUrl/api/project-tasks.php"

function Invoke-KmProj {
    param([string]$Method, [string]$Query = '', $Body = $null)
    $req = @{ Uri = $endpoint + $Query; Method = $Method; Headers = $headers; SkipHttpErrorCheck = $true; TimeoutSec = 30 }
    if ($null -ne $Body) {
        $text = if ($Body -is [string]) { $Body } else { ConvertTo-Json -InputObject $Body -Depth 6 -Compress }
        $req.Body = [Text.Encoding]::UTF8.GetBytes($text)
        $req.ContentType = 'application/json; charset=utf-8'
    }
    # 応答はハッシュ表として読む。分類の「なし」の鍵が空文字 "" で、既定の読み方(PSCustomObject)はそれを受け付けず、文字列のまま返す(11-07)
    $web = Invoke-WebRequest @req
    $code = [int]$web.StatusCode
    $res = $null
    try { $res = $web.Content | ConvertFrom-Json -AsHashtable } catch { $res = $null }
    if ($code -ge 400 -or $null -eq $res -or -not $res.success) {
        $msg = if ($res -and $res.message) { $res.message } else { "$($web.Content)" }
        throw "失敗しました(HTTP $code): $msg"
    }
    return $res
}

function Show-KmTasks($tasks) {
    $statusName = @{ done = '完了'; progress = '進行中'; decision = '要判断'; todo = '未着手' }
    $tasks | ForEach-Object {
        [pscustomobject]@{
            Id = $_.id
            段 = $_.planRef
            状態 = $statusName[$_.status] ?? $_.status
            進捗 = "$($_.progress)%"
            分類 = $_.topic
            タスク = $_.title
            メモ = if ($_.note.Length -gt 40) { $_.note.Substring(0, 40) + '…' } else { $_.note }
        }
    } | Format-Table -AutoSize -Wrap
}

# 渡された項目だけを本文にする(渡していない項目は送らない = 変えない)
$bound = $PSBoundParameters
function Get-KmFields {
    $body = [ordered]@{}
    if ($bound.ContainsKey('Title')) { $body.title = $Title }
    if ($bound.ContainsKey('Status')) { $body.status = $Status }
    if ($Progress -ge 0) { $body.progress = $Progress }
    if ($bound.ContainsKey('Topic')) { $body.topic = $Topic }
    if ($bound.ContainsKey('PlanRef')) { $body.planRef = $PlanRef }
    if ($bound.ContainsKey('Note')) { $body.note = $Note }
    return $body
}

switch ($Command) {
    'list' {
        $q = @()
        if ($PSBoundParameters.ContainsKey('Topic')) { $q += 'topic=' + [uri]::EscapeDataString($Topic) }
        if ($PSBoundParameters.ContainsKey('Status')) { $q += 'status=' + [uri]::EscapeDataString($Status) }
        if ($PSBoundParameters.ContainsKey('PlanRef')) { $q += 'plan=' + [uri]::EscapeDataString($PlanRef) }
        $res = Invoke-KmProj -Method GET -Query ($(if ($q) { '?' + ($q -join '&') } else { '' }))
        if ($Json) { $res.tasks | ConvertTo-Json -Depth 4; break }
        Show-KmTasks $res.tasks
        Write-Host "$(@($res.tasks).Count) 件"
    }
    'get' {
        if ($Id -le 0) { throw '-Id が要ります。' }
        $res = Invoke-KmProj -Method GET -Query "?id=$Id"
        if ($Json) { $res.task | ConvertTo-Json -Depth 4; break }
        [pscustomobject]$res.task | Format-List id, planRef, title, status, progress, topic, note, updatedAt
    }
    'topics' {
        $res = Invoke-KmProj -Method GET -Query '?topic=__none__'
        if ($Json) { [ordered]@{ topics = $res.topics; statuses = $res.statuses } | ConvertTo-Json -Depth 4; break }
        Write-Host '分類:'
        $res.topics.GetEnumerator() | ForEach-Object { "  {0,-10} {1}" -f $(if ($_.Key -eq '') { '(空)' } else { $_.Key }), $_.Value }
        Write-Host "状態: $($res.statuses -join ' / ')"
    }
    'add' {
        if ($File -ne '') {
            $text = [IO.File]::ReadAllText((Resolve-Path -LiteralPath $File).Path)
            $list = $text | ConvertFrom-Json -NoEnumerate
            if ($list -isnot [array]) { throw "$File は JSON の配列にしてください。" }
            $res = Invoke-KmProj -Method POST -Body ([ordered]@{ tasks = $list })
            if ($Json) { $res.tasks | ConvertTo-Json -Depth 4; break }
            Show-KmTasks $res.tasks
            Write-Host "$(@($res.tasks).Count) 件を足しました。" -ForegroundColor Green
            break
        }
        if (-not $PSBoundParameters.ContainsKey('Title')) { throw '-Title が要ります(まとめて足すなら -File)。' }
        $res = Invoke-KmProj -Method POST -Body (Get-KmFields)
        if ($Json) { $res.task | ConvertTo-Json -Depth 4; break }
        Show-KmTasks @($res.task)
        Write-Host "足しました(Id $($res.task.id))。" -ForegroundColor Green
    }
    'update' {
        if ($Id -le 0) { throw '-Id が要ります。' }
        $body = Get-KmFields
        if ($body.Count -eq 0) { throw '変える項目(-Title・-Status・-Progress・-Topic・-PlanRef・-Note)を 1 つ以上渡してください。' }
        $res = Invoke-KmProj -Method PATCH -Query "?id=$Id" -Body $body
        if ($Json) { $res.task | ConvertTo-Json -Depth 4; break }
        Show-KmTasks @($res.task)
        Write-Host '直しました。' -ForegroundColor Green
    }
    'remove' {
        if ($Id -le 0) { throw '-Id が要ります。' }
        $one = (Invoke-KmProj -Method GET -Query "?id=$Id").task
        Show-KmTasks @($one)
        if (-not $Yes) {
            $answer = Read-Host 'このタスクを消しますか? (yes で消す)'
            if ($answer -ne 'yes') { Write-Host '消しませんでした。'; break }
        }
        $null = Invoke-KmProj -Method DELETE -Query "?id=$Id"
        Write-Host "消しました(Id $Id)。" -ForegroundColor Green
    }
}
