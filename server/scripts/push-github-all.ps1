<#
.SYNOPSIS
GitHub の控え2つ(Android と Website)へ、手元の原本を写してから検査して push する。

.DESCRIPTION
2026-09-25、利用者の指示。**1回で両方を出す。** 中でやることは控えごとに同じ:

  1. 写す  … Android: scripts\sync-from-app.ps1 / Website: tools\sync-from-website.ps1
  2. 出す  … それぞれの push-github.ps1(秘密の検査 → commit → push → PR の説明を開く)

**片方が止まっても、もう片方は出す。** 最後にまとめて結果を言い、どちらかが止まっていれば 1 で終わる。
秘密の検査に当たって止まった控えは、中身を確かめてからその控えの push-github.ps1 を -Force で走らせる
(**ここからは -Force を渡さない** —— 両方まとめて検査を外す道を作らない)。

deploy-to-host.ps1 が、配備が済んだあとにこれを呼ぶ(-GitHub で選ぶ。既定は両方)。

## 認証

SSH(~/.ssh/config の github-kosen / github-kosen-web)。対話の画面を開かずに通る。

  pwsh -File scripts\push-github-all.ps1 -Message "…"
  pwsh -File scripts\push-github-all.ps1 -Target web -NoOpen
  pwsh -File scripts\push-github-all.ps1 -CheckOnly        # 写して検査するだけ(出さない)

.PARAMETER Target
all(既定)/ android / web

.PARAMETER Message
コミットの一言。両方の控えに同じ一言を使う。省略するとそれぞれの既定文。

.PARAMETER NoOpen
push のあとにプルリクエストの説明を開かない。

.PARAMETER NoSync
写さずに、控えにある変更だけを出す。

.PARAMETER CheckOnly
写して検査するだけ。commit も push もしない。
#>
[CmdletBinding()]
param(
    [ValidateSet('all', 'android', 'web')]
    [string]$Target = 'all',
    [string]$Message = '',
    [switch]$NoOpen,
    [switch]$NoSync,
    [switch]$CheckOnly,
    [string]$AndroidMirror = 'F:\Pull\Ichinoseki_Kosen',
    [string]$WebMirror = 'F:\Pull\Ichinoseki_Kosen_Web'
)

$ErrorActionPreference = 'Stop'

$mirrors = @(
    [pscustomobject]@{
        Key = 'android'; Name = 'Android'; Root = $AndroidMirror
        Sync = 'scripts\sync-from-app.ps1'; Push = 'scripts\push-github.ps1'
    },
    [pscustomobject]@{
        Key = 'web'; Name = 'Website'; Root = $WebMirror
        Sync = 'tools\sync-from-website.ps1'; Push = 'tools\push-github.ps1'
    }
) | Where-Object { $Target -eq 'all' -or $_.Key -eq $Target }

<#
  **2つの push-github.ps1 は同じ中身のはず。** ずれていたら知らせる(止めはしない)。
  片方だけ直すと、同じ種類の秘密が片方の控えでだけ素通りする。
#>
$pushScripts = @(
    (Join-Path $AndroidMirror 'scripts\push-github.ps1'),
    (Join-Path $WebMirror 'tools\push-github.ps1')
) | Where-Object { Test-Path $_ }
if ($pushScripts.Count -eq 2) {
    $hashes = $pushScripts | ForEach-Object { (Get-FileHash $_).Hash } | Sort-Object -Unique
    if (@($hashes).Count -ne 1) {
        Write-Host '★ 2つの push-github.ps1 の中身が違います。直した方をもう片方へ写してください:' -ForegroundColor Yellow
        $pushScripts | ForEach-Object { Write-Host "    $_" -ForegroundColor Yellow }
        Write-Host ''
    }
}

$results = @()
foreach ($m in $mirrors) {
    Write-Host ''
    Write-Host "==================== $($m.Name) ($($m.Root))" -ForegroundColor Cyan
    if (-not (Test-Path (Join-Path $m.Root '.git'))) {
        Write-Host "控えがありません。飛ばします: $($m.Root)" -ForegroundColor Yellow
        $results += [pscustomobject]@{ Name = $m.Name; Result = '飛ばした(控えが無い)'; Ok = $true }
        continue
    }

    try {
        if (-not $NoSync) {
            & pwsh -NoProfile -File (Join-Path $m.Root $m.Sync)
            if ($LASTEXITCODE -ne 0) { throw '写す段で止まりました。' }
        }

        $pushArgs = @('-NoProfile', '-File', (Join-Path $m.Root $m.Push))
        if ($Message -ne '') { $pushArgs += @('-Message', $Message) }
        if ($NoOpen) { $pushArgs += '-NoOpen' }
        if ($CheckOnly) { $pushArgs += '-CheckOnly' }
        & pwsh @pushArgs
        if ($LASTEXITCODE -ne 0) { throw '検査か push で止まりました(上の出力を確認してください)。' }

        $results += [pscustomobject]@{ Name = $m.Name; Result = $(if ($CheckOnly) { '検査だけ: 当たりなし' } else { '出しました' }); Ok = $true }
    } catch {
        Write-Host $_.Exception.Message -ForegroundColor Red
        $results += [pscustomobject]@{ Name = $m.Name; Result = "止まった: $($_.Exception.Message)"; Ok = $false }
    }
}

Write-Host ''
Write-Host '==================== まとめ' -ForegroundColor Cyan
foreach ($r in $results) {
    $color = if ($r.Ok) { 'Green' } else { 'Red' }
    Write-Host ("  {0,-8} {1}" -f $r.Name, $r.Result) -ForegroundColor $color
}

if ($results | Where-Object { -not $_.Ok }) {
    Write-Host ''
    Write-Host '止まった控えは、中身を確かめてから、その控えの push-github.ps1 を直接走らせてください。' -ForegroundColor Yellow
    exit 1
}
exit 0
