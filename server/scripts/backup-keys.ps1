<#
.SYNOPSIS
  バックアップを暗号化するための鍵を、この PC で1回だけ作る。

.DESCRIPTION
  ## 誰が何を持つか

    この PC   秘密鍵(backup-private.pem)  … **開ける唯一のもの**
    ホスト    証明書(backup-cert.pem)     … 閉じるだけ。開けられない

  ホストは自分で作ったバックアップを**自分では開けない。**
  乗っ取られても、そこに置いてある過去のバックアップは読めない。

  ## 秘密鍵を失うと、すべてのバックアップが開かない

  だから既定では**上書きしない。** 作り直すと、それ以前に取ったものは
  永久に開けなくなる(古い秘密鍵を残しておけば開ける)。

  置き場所は `%USERPROFILE%\.kosenmap\` —— **リポジトリの中に置かない。**
  backups/ は配備対象外だが、鍵は「間違って配る」経路から遠ざける。

.EXAMPLE
  # 作って、ホストへ証明書を置く
  .\backup-keys.ps1

.EXAMPLE
  # 作るだけ(ホストへは置かない)
  .\backup-keys.ps1 -SkipUpload

.EXAMPLE
  # 状態を見るだけ
  .\backup-keys.ps1 -Check
#>
[CmdletBinding()]
param(
    [string]$HostName = 'ito4.jp',
    # 証明書を置き場へ書くので、置き場の持ち主 kmops で繋ぐ(2026-09-18。docs/12 §7-4 B 段 2)
    [string]$User = 'kmops',
    [string]$KeyPath = "$env:USERPROFILE\.ssh\km_ops",
    [string]$RemotePath = '/opt/kosenmap',
    [int]$Port = 22,
    # 鍵の置き場。**リポジトリの外**
    [string]$KeyDir = "$env:USERPROFILE\.kosenmap",
    # 有効期限。切れても復号はできる(CMS は期限を見ない)が、目安として長めに取る
    [int]$Days = 7300,
    [switch]$SkipUpload,
    [switch]$Check,
    [switch]$Interactive,
    # **本当に作り直すとき**だけ。古いバックアップは開けなくなる
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

$privatePath = Join-Path $KeyDir 'backup-private.pem'
$certPath = Join-Path $KeyDir 'backup-cert.pem'

function Get-OpenSslPath {
    $cmd = Get-Command openssl -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    foreach ($candidate in @(
        "$env:ProgramFiles\OpenSSL-Win64\bin\openssl.exe",
        "$env:ProgramFiles\Git\usr\bin\openssl.exe"
    )) {
        if (Test-Path $candidate) { return $candidate }
    }
    throw @"
openssl が見つかりません。

  どちらかを入れてください:
    winget install --id FireDaemon.OpenSSL
    (または Git for Windows に同梱の C:\Program Files\Git\usr\bin\openssl.exe)
"@
}

$openssl = Get-OpenSslPath
Write-Host "openssl: $openssl"

if ($Check) {
    Write-Host ''
    Write-Host '=== いまの状態 ==='
    if (Test-Path $privatePath) {
        Write-Host "  秘密鍵   あり: $privatePath"
        $fp = & $openssl x509 -in $certPath -noout -fingerprint -sha256 2>$null
        if ($fp) { Write-Host "  証明書   $fp" }
    } else {
        Write-Host "  秘密鍵   ありません: $privatePath"
        Write-Host '           .\backup-keys.ps1 で作ってください'
    }
    exit 0
}

if ((Test-Path $privatePath) -and (-not $Force)) {
    Write-Host ''
    Write-Host "秘密鍵は既にあります: $privatePath"
    Write-Host ''
    Write-Host '  **作り直しません。** 作り直すと、それ以前に取ったバックアップは'
    Write-Host '  この鍵では開けなくなります(古い鍵を残していれば開けます)。'
    Write-Host '  どうしても作り直すときだけ -Force を付けてください。'
    Write-Host ''
    Write-Host '  ホストへ証明書だけ置き直すには:'
    Write-Host '    .\backup-keys.ps1 -SkipUpload:$false   (この鍵のまま送ります)'
    if ($SkipUpload) { exit 0 }
} else {
    if (-not (Test-Path $KeyDir)) { New-Item -ItemType Directory -Path $KeyDir -Force | Out-Null }

    Write-Host ''
    Write-Host '[1/2] 鍵を作ります(4096 bit)'
    <#
      **パスフレーズを掛けない(-nodes)。**
      掛けると、週次の自動取得が毎回入力を求めて止まる ——
      「自動で取る」と「毎回人が居る」は両立しない。
      その代わり、置き場所を %USERPROFILE% に寄せて ACL で本人だけにする。
    #>
    & $openssl req -x509 -newkey rsa:4096 -keyout $privatePath -out $certPath `
        -days $Days -nodes -subj "/CN=KosenMap Backup/O=KosenMap" 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw '鍵を作れませんでした' }

    <#
      **本人だけが読めるようにする。** 既定の継承では Users も読めることがある。
      ここを緩いままにすると、鍵を分けた意味が薄れる。
    #>
    $acl = Get-Acl $privatePath
    $acl.SetAccessRuleProtection($true, $false)   # 継承を切る
    $acl.Access | ForEach-Object { $acl.RemoveAccessRule($_) | Out-Null }
    $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
        "$env:USERDOMAIN\$env:USERNAME", 'FullControl', 'Allow')))
    Set-Acl -Path $privatePath -AclObject $acl

    Write-Host "      秘密鍵: $privatePath  (**この PC にだけ置きます**)"
    Write-Host "      証明書: $certPath"
}

if ($SkipUpload) {
    Write-Host ''
    Write-Host 'ホストへは置きませんでした(-SkipUpload)。'
    Write-Host '  置くときは、この証明書をホストの次の場所へ:'
    Write-Host "    $RemotePath/backup-cert.pem"
    exit 0
}

Write-Host ''
Write-Host '[2/2] ホストへ証明書を置きます(**秘密鍵は送りません**)'

. (Join-Path $PSScriptRoot 'km-ssh.ps1')
Initialize-KmSsh -HostName $HostName -User $User -KeyPath $KeyPath -Port $Port -Interactive:$Interactive
Assert-KmSshReady

Copy-KmTo -Path $certPath -Destination "$RemotePath/backup-cert.pem" | Out-Null

# 置けたことを、こちらの指紋と突き合わせて確かめる。
# **「送った」ではなく「同じものが在る」まで見る**(取り違えた証明書だと、
# 作られたバックアップが手元の鍵で開かない —— 開けようとした日に初めて分かる)
$localFp = (& $openssl x509 -in $certPath -noout -fingerprint -sha256) -replace '.*=', ''
$remoteFp = (Invoke-KmSsh -Command "openssl x509 -in $RemotePath/backup-cert.pem -noout -fingerprint -sha256") -replace '.*=', ''
if ($localFp.Trim() -ne $remoteFp.Trim()) {
    throw "ホストへ置いた証明書がこちらのものと違います。`n  こちら: $localFp`n  ホスト: $remoteFp"
}

Write-Host "      指紋が一致しました: $($localFp.Trim())"
Write-Host ''
Write-Host '準備できました。ホストで次を実行すると、暗号化されたバックアップができます:'
Write-Host "  sudo $RemotePath/scripts/host-backup.sh"
Write-Host ''
Write-Host '  **秘密鍵を失うと、取ったものは二度と開けません。**'
Write-Host "  $privatePath を、この PC とは別の場所にも1つ控えておいてください。"
