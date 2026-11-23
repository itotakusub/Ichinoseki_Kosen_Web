<#
.SYNOPSIS
  検証機(既定 192.168.3.31)を本番と同じにする: 手元のコードを配備(up)→ nginx を起こし直す(restart)→ 本番の控えからデータを移す。

.DESCRIPTION
  2026-11-08、利用者の指示「本番の控えから、検証機へデータを移し action=up,deploy,restart をまとめてやるショートカット」。
  デスクトップの「検証機を本番と同じに.cmd」から呼ぶ。

    1. 配備      deploy-to-host.ps1 -Action up(ファイルを置いて docker compose up -d。deploy を含む)
    2. 起こし直す docker compose restart reverse-proxy(nginx の設定はファイル 1 つの bind mount なので、up では読み直さない)
    3. データ    local-refresh.ps1 -CopyPublicImages(この PC にある**一番新しい本番の控え**を開く → 抜いてから入れる
                 → ダミーのファイル → 本番の公開の画像 → 本番の秘密と一致しないかを確かめる → 開いた控えを消す)

  ## 決まり(利用者の指示)

  - **検証機では控え(バックアップ)を作らない。** 中身は本番の控えから作る写しなので、取っても意味がない。
    deploy-to-host.ps1 も、本番以外へは -Backup を断る
  - **GitHub へ送らない。** deploy-to-host.ps1 は本番以外へは GitHub の控えに出さない(ここでも -GitHub none を渡す)
  - 本番は**読むだけ**(公開の画像を写すときだけ繋ぐ)。本番の控えを新しく取ることもしない
  - Logto を組む(-SeedLogto)のは初回だけなので、ここでは流さない

    .\testhost-sync.ps1                 1〜3 を全部
    .\testhost-sync.ps1 -SkipDeploy     3 だけ(データだけ入れ直す)
    .\testhost-sync.ps1 -SkipData       1・2 だけ(コードだけ)

  保存は BOM 付き UTF-8・LF。
#>
[CmdletBinding()]
param(
    [string]$HostName = '192.168.3.31',
    [string]$User = 'kmops',
    [string]$KeyPath = "$env:USERPROFILE\.ssh\km_test30_ops",
    [string]$RemotePath = '/opt/kosenmap',
    [switch]$SkipDeploy,
    [switch]$SkipData,
    [switch]$Help
)

if ($Help) {
    $kmHelp = Join-Path $PSScriptRoot 'km-help.ps1'
    if (Test-Path $kmHelp) { & $kmHelp -Path $PSCommandPath } else { Get-Help $PSCommandPath -Detailed }
    exit 0
}

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0

# 本番の名前・公開のアドレスには流さない(local-refresh.ps1 も同じことを確かめる。配備は先に走るのでここでも止める)
foreach ($p in @('ito4.jp', 'ito8795.com')) {
    if ($HostName -eq $p -or $HostName.EndsWith(".$p")) { throw "本番の名前($HostName)には流しません。" }
}
$addr = $null
if (-not [Net.IPAddress]::TryParse($HostName, [ref]$addr)) { throw "$HostName は IP ではありません(検証機の LAN の IP を渡してください)。" }
$b = $addr.GetAddressBytes()
if (-not (($b[0] -eq 10) -or ($b[0] -eq 192 -and $b[1] -eq 168) -or ($b[0] -eq 172 -and $b[1] -ge 16 -and $b[1] -le 31))) {
    throw "$HostName は LAN のアドレスではありません。"
}
if (-not (Test-Path $KeyPath)) { throw "鍵がありません: $KeyPath" }

$ssh = @('-i', $KeyPath, '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', "$User@$HostName")
# 相手が検証機の印を持つことを、配備の前に確かめる(配備はファイルを置いてしまうので)
& ssh @ssh "test -f /etc/kosenmap-test-host && grep -qx 'KM_ENV=local' $RemotePath/.env"
if ($LASTEXITCODE -ne 0) { throw "$HostName に検証機の印(/etc/kosenmap-test-host と KM_ENV=local)がありません。止めます。" }

$started = Get-Date
$results = [ordered]@{}

if (-not $SkipDeploy) {
    Write-Host ''
    Write-Host "==================== 1. 配備(-Action up)→ $HostName" -ForegroundColor Cyan
    & pwsh -NoProfile -File (Join-Path $PSScriptRoot 'deploy-to-host.ps1') -HostName $HostName -User $User -KeyPath $KeyPath -RemotePath $RemotePath -Action up -Yes -GitHub none
    $code = $LASTEXITCODE
    # 2 = 転送は済んだが後片付けに気になる点(本番でも出る自己検査の行など)。止めずに続ける
    if ($code -ne 0 -and $code -ne 2) { throw "配備が失敗しました(終了コード $code)。データは入れていません。" }
    $results['配備'] = if ($code -eq 0) { 'OK' } else { 'OK(後片付けに気になる点。上の出力)' }

    Write-Host ''
    Write-Host '==================== 2. nginx を起こし直す(restart reverse-proxy)' -ForegroundColor Cyan
    & ssh @ssh "cd $RemotePath && docker compose restart reverse-proxy && docker compose exec -T reverse-proxy nginx -t"
    if ($LASTEXITCODE -ne 0) { throw 'nginx を起こし直せませんでした。' }
    $results['nginx'] = 'OK'
}

if (-not $SkipData) {
    Write-Host ''
    Write-Host '==================== 3. 本番の控えからデータを移す(抜いてから)' -ForegroundColor Cyan
    & pwsh -NoProfile -File (Join-Path $PSScriptRoot 'local-refresh.ps1') -HostName $HostName -User $User -KeyPath $KeyPath -RemotePath $RemotePath -CopyPublicImages
    if ($LASTEXITCODE -ne 0) { throw "データを移せませんでした(終了コード $LASTEXITCODE)。上の ★ を確かめてください。" }
    $results['データ'] = 'OK'
}

Write-Host ''
Write-Host '==================== 4. 様子' -ForegroundColor Cyan
& ssh @ssh "cd $RemotePath && docker compose ps --format '{{.Name}}  {{.Status}}'"
Write-Host ''
foreach ($k in $results.Keys) { Write-Host ("  {0,-6} {1}" -f $k, $results[$k]) -ForegroundColor Green }
Write-Host ("済みました({0:mm\:ss})。控えは作っていません・GitHub へは送っていません。" -f ((Get-Date) - $started)) -ForegroundColor Green
