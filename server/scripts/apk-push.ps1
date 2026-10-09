<#
.SYNOPSIS
  リリース版の APK 2 つを、GitHub の非公開リポジトリ(アプリの受け箱)へ送る(2026-10-09)。

.DESCRIPTION
  利用者の指示「Website に APK をいちいちアップロードするのが面倒」。流れ(docs/02 の「アプリの配布」):

    この台本 → itotakusub/kosenmap-apk に KosenMap.apk・KosenMap-admin.apk・latest.json を置いて push
    → 本番ホストの cron(scripts/host-apk-inbox.sh)が 5 分以内に取りに来て、受け箱に入れて管理者へメール
    → 管理者がメールの URL を開いて「公開する」を押す(開いただけでは公開しない)

  送る前に確かめること(どれかに落ちたら送らない):
    - 2 つとも署名済みで、**同じ鍵**(apksigner の証明書の SHA-256)
    - パッケージ名が一般用 com.ito.kosenmap・管理用 com.ito.kosenmap.admin
    - 版番号(versionCode)が 2 つで同じ
  サーバーの側でも、同じこと(と「今の配布物と同じ鍵か・版が上がるか」)をもう一度確かめる。

  **リポジトリには最新の 1 回分だけを残す。** 毎回 1 つのコミットに置き換えて force push する
  (APK は 1 回で約 56MB。履歴を残すとすぐ GB になる)。force push はこのリポジトリだけ(利用者の承認 2026-10-09)。
  鍵は GitHub の控えと同じ SSH の鍵(~/.ssh/web。~/.ssh/config の github-kosen)。

.PARAMETER TestRoot
  Android のプロジェクト(既定 C:\Users\itota\Documents\Test)。

.PARAMETER RepoDir
  手元の写し(既定 F:\Pull\kosenmap-apk。無ければ clone する)。

.PARAMETER WhatIf
  確かめて latest.json を作るだけ(push しない)。

.EXAMPLE
  pwsh -File scripts\apk-push.ps1
.EXAMPLE
  pwsh -File scripts\apk-push.ps1 -WhatIf
#>
[CmdletBinding()]
param(
    [string]$TestRoot = 'C:\Users\itota\Documents\Test',
    [string]$RepoDir = 'F:\Pull\kosenmap-apk',
    [string]$Remote = 'git@github-kosen:itotakusub/kosenmap-apk.git',
    [switch]$WhatIf
)

$ErrorActionPreference = 'Stop'

$expected = [ordered]@{
    apk       = @{ Flavor = 'visitor'; Name = 'KosenMap.apk'; Package = 'com.ito.kosenmap' }
    apk_admin = @{ Flavor = 'admin'; Name = 'KosenMap-admin.apk'; Package = 'com.ito.kosenmap.admin' }
}

# ---- build-tools(aapt2・apksigner)----
$sdkLine = Get-Content (Join-Path $TestRoot 'local.properties') | Where-Object { $_ -match '^sdk\.dir=' } | Select-Object -First 1
if (-not $sdkLine) { throw "local.properties に sdk.dir がありません($TestRoot)" }
$sdk = ($sdkLine -replace '^sdk\.dir=', '') -replace '\\\\', '\' -replace '\\:', ':'
$buildTools = Get-ChildItem (Join-Path $sdk 'build-tools') -Directory |
    Sort-Object { [version](($_.Name -replace '[^0-9.]', '') -replace '^\.', '0.') } | Select-Object -Last 1
$aapt2 = Join-Path $buildTools.FullName 'aapt2.exe'
$apksigner = Join-Path $buildTools.FullName 'apksigner.bat'

# ---- 2 つを確かめる ----
$files = @()
foreach ($slug in $expected.Keys) {
    $e = $expected[$slug]
    $apk = Join-Path $TestRoot "app\build\outputs\apk\$($e.Flavor)\release\app-$($e.Flavor)-release.apk"
    if (-not (Test-Path -LiteralPath $apk)) { throw "リリース版がありません: $apk(先に gradlew :app:assemble…Release)" }
    $badging = & $aapt2 dump badging $apk 2>$null | Select-String -Pattern '^package:' | Select-Object -First 1
    if (-not $badging -or $badging.Line -notmatch "name='([^']+)' versionCode='(\d+)' versionName='([^']*)'") { throw "APK の中を読めません: $apk" }
    $package, $code, $vname = $Matches[1], [int]$Matches[2], $Matches[3]
    if ($package -ne $e.Package) { throw "パッケージ名が違います: $apk は $package(期待 $($e.Package))" }
    $certs = & $apksigner verify --print-certs $apk 2>&1
    if ($LASTEXITCODE -ne 0) { throw "署名を確かめられません(署名していない?): $apk" }
    $signer = ($certs | Select-String -Pattern 'certificate SHA-256 digest:\s*([0-9a-f]{64})' | Select-Object -First 1).Matches.Groups[1].Value
    if (-not $signer) { throw "署名の証明書を読めません: $apk" }
    $item = Get-Item -LiteralPath $apk
    $files += [pscustomobject]@{
        Slug = $slug; Name = $e.Name; Package = $package; VersionCode = $code; VersionName = $vname
        Signer = $signer; Size = $item.Length; Sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $apk).Hash.ToLowerInvariant(); Path = $apk
    }
}
if (($files.VersionCode | Select-Object -Unique).Count -ne 1) { throw "2 つの版番号が違います: $($files.VersionCode -join ' / ')" }
if (($files.Signer | Select-Object -Unique).Count -ne 1) { throw '2 つが違う鍵で署名されています' }

$versionCode = $files[0].VersionCode
$versionName = ($files | Where-Object Slug -eq 'apk').VersionName
$manifest = [ordered]@{
    format        = 'kosenmap-apk'
    formatVersion = 1
    versionCode   = $versionCode
    versionName   = $versionName
    builtAt       = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz')
    signerSha256  = $files[0].Signer
    files         = @($files | ForEach-Object { [ordered]@{ slug = $_.Slug; name = $_.Name; package = $_.Package; size = $_.Size; sha256 = $_.Sha256 } })
}
$json = $manifest | ConvertTo-Json -Depth 5

Write-Host ("版 {0}(内部の番号 {1})・署名 {2}…" -f $versionName, $versionCode, $files[0].Signer.Substring(0, 12))
foreach ($f in $files) { Write-Host ("  {0,-20} {1,6:N1} MB  SHA-256 {2}…" -f $f.Name, ($f.Size / 1MB), $f.Sha256.Substring(0, 12)) }

if ($WhatIf) {
    Write-Host ''
    Write-Host '(-WhatIf: push しません)latest.json:'
    Write-Host $json
    return
}

# ---- 手元の写し ----
if (-not (Test-Path -LiteralPath (Join-Path $RepoDir '.git'))) {
    Write-Host "手元の写しを作ります: $RepoDir"
    git clone -q $Remote $RepoDir 2>&1 | Out-Host
    if ($LASTEXITCODE -ne 0) {
        throw "clone できません。GitHub に非公開のリポジトリ $Remote を作ってあるか確かめてください(docs/02 の「アプリの配布」)"
    }
}

# 前の中身を片付けて、3 つ(と README)だけを置く。git の管理の外のファイルも残さない
Get-ChildItem -LiteralPath $RepoDir -Force | Where-Object Name -ne '.git' | ForEach-Object { Remove-Item -LiteralPath $_.FullName -Recurse -Force }
foreach ($f in $files) { Copy-Item -LiteralPath $f.Path -Destination (Join-Path $RepoDir $f.Name) }
$utf8 = New-Object System.Text.UTF8Encoding $false
[IO.File]::WriteAllText((Join-Path $RepoDir 'latest.json'), $json + "`n", $utf8)
[IO.File]::WriteAllText((Join-Path $RepoDir 'README.md'), @"
# KosenMap のアプリの受け箱

**非公開。** KosenMap の Website(本番ホスト)が 5 分ごとに latest.json を見て、新しい APK を取りに来る。
公開するのは管理者(届いたらメールが来る。管理画面で「公開する」を押す)。

置くのは Website の server/scripts/apk-push.ps1 だけ。最新の 1 回分だけを残す(毎回 force push)。
"@ + "`n", $utf8)

git -C $RepoDir checkout -q --orphan next 2>&1 | Out-Null
git -C $RepoDir add -A
$staged = @(git -C $RepoDir diff --cached --name-only) + @(git -C $RepoDir ls-files) | Sort-Object -Unique
$allowed = @('KosenMap-admin.apk', 'KosenMap.apk', 'README.md', 'latest.json')
$extra = $staged | Where-Object { $_ -notin $allowed }
if ($extra) { throw "入れてはいけないファイルがあります(送りません): $($extra -join ', ')" }

$message = "版 $versionName(内部の番号 $versionCode)`n`nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git -C $RepoDir commit -q -m $message
if ($LASTEXITCODE -ne 0) { throw 'commit できません' }
git -C $RepoDir branch -q -M main
git -C $RepoDir push -q -f origin main 2>&1 | Out-Host
if ($LASTEXITCODE -ne 0) { throw 'push できません(SSH の鍵・リポジトリの権限を確かめてください)' }
# 手元の写しも 1 つのコミットだけにする(前の APK を .git に溜めない)
git -C $RepoDir reflog expire --expire=now --all 2>&1 | Out-Null
git -C $RepoDir gc -q --prune=now 2>&1 | Out-Null

Write-Host ''
Write-Host "送りました: $Remote(版 $versionName)" -ForegroundColor Green
Write-Host '5 分以内に本番ホストが取りに行き、届いたらメールが来ます。メールの URL を開いて「公開する」を押してください。'
