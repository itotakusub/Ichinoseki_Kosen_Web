<#
.SYNOPSIS
  暗号化されたバックアップを、この PC で開く。

.DESCRIPTION
  控えの置き場(既定 D:\Backups。backup-lib.ps1 の Get-KmBackupRoot)に置いてあるのは
  暗号化されたものだけなので、中身を見る・復元に使うときはこれで開く。

  ## 開いたものは、既定で消す

  **開いたものは平文。** .env と config/*.local.php、DB の全ダンプが入っている。

  以前は控えの隣に opened-<日時> を作ったまま終わり、「用が済んだら消してください」と
  表示するだけだった。**2度とも消されずに残った**(security-review-2026-09-10 の P0。
  そのうち1つは世代ごと D:\Backups へ移され、平文のまま残っていた)。
  表示で頼むのではなく、作りで消す。

    既定   … 開いて検査したら消す(中身が本物かを確かめる用)。消せなかったら終了コード 1
    -Keep  … 残す(復元に使うとき)。<置き場>\_opened\<日時> に置く。
              消すのは世代整理(backup-lib.ps1 の Remove-KmOpenedLeftovers)で、
              **作ってから24時間を過ぎたものを、次に backup-data.ps1 が動いたときに消す。**
              backup-data.ps1 は週次のタスク(と deploy-to-host.ps1 -Backup)でしか動かないので、
              **最長で約1週間残る。** 以前は「24時間で消える」と表示していたが、実際と違った(2026-09-14 の診断)

  秘密鍵は %USERPROFILE%\.kosenmap\backup-private.pem。
  **これを失うと、取ったものは二度と開かない。**

.EXAMPLE
  # 一番新しいものを開いて検査する(平文は残らない)
  .\open-backup.ps1

.EXAMPLE
  # 復元に使うので、指定して開いて残す
  .\open-backup.ps1 -Path D:\Backups\ito4.jp-20260920-030000\km-backup-ubuntu-20260913-085635.tar.gz.cms -Keep

.EXAMPLE
  # 開かずに、何が在るかだけ見る
  .\open-backup.ps1 -List
#>
[CmdletBinding()]
param(
    # 開くファイル。未指定なら置き場で一番新しいもの
    [string]$Path = '',
    # 展開先。未指定なら <置き場>\_opened\<日時>
    [string]$Destination = '',
    [string]$KeyDir = "$env:USERPROFILE\.kosenmap",
    # 控えの置き場。未指定なら Get-KmBackupRoot
    [string]$BackupRoot = '',
    # 開いたものを残す(復元に使うとき)。付けなければ検査のあと消す
    [switch]$Keep,
    [switch]$List
)

$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'backup-lib.ps1')

$backupsDir = Get-KmBackupRoot -Override $BackupRoot
# _opened の下は開いた平文で、控えではない
$openedPrefix = (Join-Path $backupsDir '_opened') + '\'
$all = Get-ChildItem -LiteralPath $backupsDir -Recurse -Filter '*.tar.gz.cms' -ErrorAction SilentlyContinue |
    Where-Object { -not $_.FullName.StartsWith($openedPrefix, [System.StringComparison]::OrdinalIgnoreCase) } |
    Sort-Object LastWriteTime -Descending

if ($List) {
    Write-Host "=== $backupsDir ==="
    if (-not $all) {
        Write-Host '  まだありません(.\backup-data.ps1 で取得します)'
        exit 0
    }
    foreach ($f in $all) {
        Write-Host ("  {0,-58} {1,12:N0} バイト  {2}" -f $f.Name, $f.Length, $f.LastWriteTime)
        # 数字だけは平文で残してある。**開かずに前の週と見比べられる**
        $summary = Join-Path $f.Directory.FullName 'summary.txt'
        if (Test-Path $summary) {
            (Get-Content $summary | Select-String 'km_map_nodes') | ForEach-Object { Write-Host "      $_" }
        }
    }
    exit 0
}

if ($Path -eq '') {
    if (-not $all) { throw "控えの置き場に暗号化されたバックアップがありません: $backupsDir" }
    $Path = $all[0].FullName
    Write-Host "一番新しいものを開きます: $($all[0].Name)"
}

if ($Destination -eq '') {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $Destination = Join-Path $backupsDir "_opened\$stamp"
}

$privateKey = Join-Path $KeyDir 'backup-private.pem'
$leftover = $false
try {
    Open-KmBackup -Path $Path -PrivateKey $privateKey -Destination $Destination | Out-Null

    Write-Host ''
    Write-Host "開きました: $Destination"
    Get-ChildItem -LiteralPath $Destination | ForEach-Object {
        Write-Host ("  {0,-26} {1,12:N0} バイト" -f $_.Name, $_.Length)
    }

    # 開いた中身が本物かまで見る。**開けたことと、中身が入っていることは別**
    Write-Host ''
    Test-KmBackup -Dir $Destination | Out-Null
}
finally {
    # **-Keep でなければ消す。** 検査が失敗しても消す(失敗した回ほど平文が置き去りにされやすい)
    if (-not $Keep) {
        Remove-Item -LiteralPath $Destination -Recurse -Force -ErrorAction SilentlyContinue
        <#
          **消えたかを確かめる。** SilentlyContinue なので、開いているファイルがあると黙って残る。
          以前は確かめないまま「消しました」と表示していた(2026-09-14 の診断)。
          例外で抜ける途中でも、残っていることだけは画面に出す。
        #>
        $leftover = Test-Path -LiteralPath $Destination
        if ($leftover) {
            Write-Warning "開いた平文を消せませんでした(開いているファイルが無いか確かめて、手で消してください): $Destination"
        }
    }
}

Write-Host ''
if ($Keep) {
    Write-Host '  **ここには .env と config/*.local.php(秘密)が平文で入っています。**'
    Write-Host "  置き場: $Destination"
    Write-Host '  消えるのは、24時間を過ぎたあと次に backup-data.ps1 が動いたとき(週次のタスク)です。最長で約1週間残ります。'
    Write-Host '  用が済んだらすぐ消すこと:'
    Write-Host "    Remove-Item -Recurse -Force `"$Destination`""
} elseif ($leftover) {
    # **「消しました」と言わない。** 残っているのに 0 で終わると、呼んだ側も消えたと読む
    Write-Host '  ★ 検査は済みましたが、開いた平文が残っています(上の警告)。' -ForegroundColor Yellow
    Write-Host "    Remove-Item -Recurse -Force `"$Destination`""
    exit 1
} else {
    Write-Host '  検査が済んだので、開いた平文は消しました。中身を使うときは -Keep を付けてください。'
}

<#
  **呼んだ側へは値で返す**(backup-data.ps1 と同じ作法)。
  deploy-to-host.ps1 -OpenBackup は、ここから開いた場所を受け取る。
#>
[pscustomobject]@{
    Archive = $Path
    Opened  = $(if ($Keep) { $Destination } else { '' })
    Kept    = [bool]$Keep
}
