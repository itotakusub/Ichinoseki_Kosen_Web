<#
.SYNOPSIS
  backup-data.ps1 を、この PC のタスクスケジューラへ週1で登録する。

.DESCRIPTION
  ## なぜ「この PC」から取るのか

  VPS 側に cron を置いて自分自身をバックアップさせる方が一見簡単だが、
  **取ったものが同じ機械の上にしか無い**のでは、その機械が壊れたときに一緒に失われる。
  この PC から SSH で取りに行けば、**別の場所に残る**。
  VPS 側へ鍵を置かずに済む(鍵はこの PC にだけある)という利点もある。

  ## 何度実行してもよい

  同名のタスクがあれば作り直す。設定を変えたいときは、値を変えて再実行するだけでよい。

  ## 注意

  取得したものには **.env と config/*.local.php(秘密)が入る**(暗号化はされている)。
  保存先は控えの置き場(既定 D:\Backups。backup-lib.ps1 の Get-KmBackupRoot)。
  リポジトリの外なので、リポジトリを写しても一緒には渡らない。

  ## 失敗したら知らせる

  タスクが呼ぶのは backup-task-run.ps1。失敗するとホスト経由のメールと
  Windows の通知を出す。**登録し直さないと、古い実行内容のまま動き続ける。**

.EXAMPLE
  # 既定(毎週日曜 3:00 に ito4.jp から取る)
  .\register-backup-task.ps1

.EXAMPLE
  # 曜日と時刻を変える
  .\register-backup-task.ps1 -DayOfWeek Monday -At 04:30

.EXAMPLE
  # 控え専用の鍵(門番付き。~\.ssh\km_backup)で取る(docs/12 §7-4)
  .\register-backup-task.ps1 -Gated

.EXAMPLE
  # 登録せず、何が登録されるかだけ見る
  .\register-backup-task.ps1 -WhatIfOnly
#>
[CmdletBinding()]
param(
    [string]$TaskName = 'KosenMap バックアップ(週次)',
    [string]$HostName = 'ito4.jp',
    # 2026-09-18 から kmops(門番の鍵の行も kmops に在る。docs/12 §7-4 B 段 2)
    [string]$User = 'kmops',
    # 未指定なら -Gated のとき km_backup(控え専用・門番付き)、そうでなければ km_ops
    [string]$KeyPath = '',
    [string]$RemotePath = '/opt/kosenmap',
    [ValidateSet('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday')]
    [string]$DayOfWeek = 'Sunday',
    [string]$At = '03:00',
    [int]$Keep = 7,
    # 控え専用の鍵(ホストの ssh-backup-gate.sh の門番付き)で取る。**無人で動く鍵が、控えを作って取る以外に使えなくなる**
    [switch]$Gated,
    [switch]$WhatIfOnly
)

$ErrorActionPreference = 'Stop'

$script = Join-Path $PSScriptRoot 'backup-data.ps1'
if (-not (Test-Path $script)) { throw "backup-data.ps1 が見つかりません: $script" }
if ($KeyPath -eq '') { $KeyPath = if ($Gated) { "$env:USERPROFILE\.ssh\km_backup" } else { "$env:USERPROFILE\.ssh\km_ops" } }
if (-not (Test-Path $KeyPath)) { throw "秘密鍵が見つかりません: $KeyPath" }

<#
  **PowerShell 7(pwsh.exe)で走らせる。**

  もとの理由は「5.1 では**読めない**」だった。この一連のスクリプトが BOM 無しの
  UTF-8 で書いてあり、Windows PowerShell 5.1 は BOM が無いと CP932 として読むため、
  **日本語のコメントが文字化けして構文が壊れていた**(実際に登録して走らせたところ、
  `Missing expression after ','` などが大量に出て終了コード 1、ログも作られなかった)。

  **その理由はもう無い。** scripts/*.ps1 は全て BOM 付きにし、check.php の
  `powershell` 群が先頭3バイトを見張っている(2026-09-07)。5.1 でも構文解析は通る。

  それでも pwsh で走らせ続けるのは、**実行時**の差が km-ssh.ps1 に残っているから ——
  5.1 は空文字の引数を落とし、標準入力に BOM を書く(下に続く)。
  **週1で無人で走るものは、動くと分かっている方で走らせる。**
#>
<#
  **`?.` を使わない。** null 条件演算子は PowerShell 7 からで、
  Windows PowerShell 5.1 では**構文解析の時点で落ちる** ——
  つまり「実行したら失敗する」ではなく「**このファイルは読めない**」。
  5.1 のウィンドウから叩くと、何をしようとしたかも表示されずに終わる。

  同じ罠を km-ssh.ps1 でも踏んだ(5.1 が空文字の引数を落とし、
  パスフレーズの無い鍵を「掛かっている」と誤判定していた)。
  **手元のスクリプトは、どちらの shell で叩かれても同じ答えを返すこと。**
#>
$pwshCommand = Get-Command pwsh -ErrorAction SilentlyContinue
$pwsh = if ($pwshCommand) { $pwshCommand.Source } else { $null }
if (-not $pwsh) {
    $fallback = "$env:ProgramFiles\PowerShell\7\pwsh.exe"
    if (Test-Path $fallback) { $pwsh = $fallback }
}
if (-not $pwsh) {
    throw @"
PowerShell 7(pwsh.exe)が見つかりません。

  週1で無人で走るものなので、実行時の挙動が確かめてある PowerShell 7 で登録します
  (5.1 は空文字の引数を落とし、標準入力に BOM を書きます)。入れてください:

    winget install --id Microsoft.PowerShell
"@
}

# ログを残す。**失敗しても気付けるように**、成否に関わらず1ファイルへ追記する。
# 置き場は backup-lib.ps1 の Get-KmBackupRoot 1か所で決める(既定 D:\Backups)。
# 無ければここで止まる —— 登録だけ通って毎週失敗し続けるより良い
. (Join-Path $PSScriptRoot 'backup-lib.ps1')
$logDir = Get-KmBackupRoot
$logPath = Join-Path $logDir 'backup-task.log'

Write-Host '=== 登録する内容 ==='
Write-Host "  タスク名 : $TaskName"
Write-Host "  実行     : 毎週 $DayOfWeek $At"
Write-Host "  取得元   : ${User}@${HostName}:${RemotePath}"
Write-Host "  鍵       : $KeyPath$(if ($Gated) { '(控え専用・門番付き)' })"
Write-Host "  保持     : $Keep 世代"
Write-Host "  ログ     : $logPath"
Write-Host "  実行系   : $pwsh"

if ($WhatIfOnly) {
    Write-Host ''
    Write-Host '-WhatIfOnly のため登録しません。'
    exit 0
}

if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }

<#
  **backup-data.ps1 を直接呼ばず、backup-task-run.ps1 を挟む。**

  以前は backup-data.ps1 の出力を backup-task.log へ足すだけで、
  **失敗しても誰にも知らせが来なかった**(2026-09-13 のレビューで判明)。
  挟んだ側が、失敗したらホスト経由のメールと Windows の通知を出す。記録もそちらが書く。

  -File で渡す。-Command に引用を重ねる形は、パスに空白や ' が入ると崩れる。
#>
$runner = Join-Path $PSScriptRoot 'backup-task-run.ps1'
if (-not (Test-Path $runner)) { throw "backup-task-run.ps1 が見つかりません: $runner" }
$action = New-ScheduledTaskAction `
    -Execute $pwsh `
    -Argument ("-NoProfile -ExecutionPolicy Bypass -File `"$runner`" -HostName `"$HostName`" -User `"$User`" " +
        "-KeyPath `"$KeyPath`" -RemotePath `"$RemotePath`" -Keep $Keep -BackupRoot `"$logDir`"" + $(if ($Gated) { ' -Gated' } else { '' })) `
    -WorkingDirectory $PSScriptRoot

$trigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek $DayOfWeek -At $At

<#
  StartWhenAvailable: 予定時刻に PC が落ちていたら、起動後に取り返す。
  **これが無いと、電源を切っていた週はまるごと取り逃す**(実際に停電で止まったことがある)。
  DontStopIfGoingOnBatteries 等はノート PC でも動くように。
#>
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -DontStopIfGoingOnBatteries `
    -AllowStartIfOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1)

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host ''
    Write-Host '  既存のタスクを削除しました(作り直します)'
}

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description 'KosenMap の VPS から DB・uploads・設定を取得して backups/ に保存する。' | Out-Null

Write-Host ''
Write-Host '登録しました。'
Write-Host ''
Write-Host '  すぐ試すには:'
Write-Host "    Start-ScheduledTask -TaskName '$TaskName'"
Write-Host '  結果を見るには:'
Write-Host "    Get-Content '$logPath' -Tail 30"
Write-Host ''
Write-Host '  **「ファイルができた」を成功と見なさないこと。** ログに出る行数が'
Write-Host '  ライブと一致しているかまで見る(km_map_nodes 604 など)。'
