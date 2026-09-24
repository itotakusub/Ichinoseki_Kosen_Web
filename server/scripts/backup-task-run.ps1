<#
.SYNOPSIS
  タスクスケジューラから呼ぶ週次バックアップ。**失敗したら、メールと Windows 通知で知らせる。**

.DESCRIPTION
  中身は backup-data.ps1 を呼ぶだけ。取り方は向こう1本に任せる(写すと同じ判断が2つになる)。

  ## なぜ要るのか

  以前のタスクは backup-data.ps1 を直接呼び、出力を backup-task.log に足すだけだった。
  **失敗しても誰にも知らせが来ない。** 記録を開きに行かない限り、
  毎週失敗していても「取れているつもり」が続く(2026-09-13 のレビューで判明)。

  ## 知らせ方を2つ持つ理由

    メール          … ホストの send-log.sh 経由。PC を見ていなくても届く。
                      ただし**ホストへ SSH できないと出せない** —— 失敗の原因がまさにそれのことが多い
    Windows の通知  … この PC の画面に出す。回線やホストが落ちていても出る

  片方だけだと、一番ありがちな失敗(回線・ホストが落ちている)で黙る。

  PC の電源が入っておらずタスク自体が走らなかった週は、ここでは何もできない。
  それはホスト側の日曜の便りが「手元の PC が今週取りに来ていません」と言う
  (host-backup.sh の --pc-stale-days)。

  ## 記録

  <控えの置き場>\backup-task.log に追記する(置き場は backup-lib.ps1 の Get-KmBackupRoot)。
  置き場そのものが無いときも、失敗として知らせる(記録は書けないのでメールと通知だけ)。

.EXAMPLE
  # 登録は register-backup-task.ps1 が行う。手で試すなら:
  .\backup-task-run.ps1

.EXAMPLE
  # 失敗の知らせを試す(繋がらないホスト。メールは出せないので、通知と「送れなかった」が出る)
  .\backup-task-run.ps1 -HostName no-such-host.invalid
#>
[CmdletBinding()]
param(
    [string]$HostName = 'ito4.jp',
    # 2026-09-18 から kmops(門番の鍵の行も kmops に在る。docs/12 §7-4 B 段 2)
    [string]$User = 'kmops',
    [string]$KeyPath = "$env:USERPROFILE\.ssh\km_ops",
    [string]$RemotePath = '/opt/kosenmap',
    [int]$Keep = 7,
    [int]$Port = 22,
    # 控えの置き場。未指定なら Get-KmBackupRoot
    [string]$BackupRoot = '',
    # メールを送らない(試験用)
    [switch]$NoMail,
    # Windows の通知を出さない(試験用)
    [switch]$NoToast,
    # メールの宛先ホスト。未指定なら取得元と同じ(試験で「取得は失敗させ、メールは届ける」ときに分ける)
    [string]$MailHostName = '',
    # 控え専用の鍵(門番付き)で取る。取得も失敗のメールも門番の決まった口を通る(backup-data.ps1 の -Gated)
    [switch]$Gated
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'backup-lib.ps1')
. (Join-Path $PSScriptRoot 'km-ssh.ps1')

$script:LogPath = $null
$script:Lines = New-Object System.Collections.Generic.List[string]

function Write-RunLog {
    param([string]$Text)
    $script:Lines.Add($Text)
    Write-Host $Text
    if ($script:LogPath) {
        Add-Content -LiteralPath $script:LogPath -Value $Text -Encoding utf8
    }
}

<#
  Windows の通知。

  **BurntToast に頼らない。** 入っていない PC では黙って出ない(この PC にも無い)。
  System.Windows.Forms の吹き出しは pwsh でも読める(実測)。
  出している間にプロセスが終わると吹き出しごと消えるので、表示時間ぶん待ってから片付ける。
#>
function Show-KmFailureToast {
    param([string]$Title, [string]$Text)
    try {
        Add-Type -AssemblyName System.Windows.Forms
        Add-Type -AssemblyName System.Drawing
        $icon = New-Object System.Windows.Forms.NotifyIcon
        $icon.Icon = [System.Drawing.SystemIcons]::Warning
        $icon.BalloonTipIcon = [System.Windows.Forms.ToolTipIcon]::Error
        $icon.BalloonTipTitle = $Title
        # 吹き出しの本文は 255 文字まで。超えると何も出ない
        $icon.BalloonTipText = $(if ($Text.Length -gt 250) { $Text.Substring(0, 250) + '…' } else { $Text })
        $icon.Visible = $true
        $icon.ShowBalloonTip(20000)
        Start-Sleep -Seconds 20
        $icon.Dispose()
        return $true
    } catch {
        Write-RunLog "  (Windows の通知を出せませんでした: $($_.Exception.Message))"
        return $false
    }
}

<#
  ホストの send-log.sh へ、記録の末尾を流してメールにする。

  **日本語を SSH のコマンド行に載せない。** 見出し(--label)も記録も、
  Invoke-KmSshStdin で**標準入力に UTF-8 のバイトとして**流す sh スクリプトの中に置く。
  コマンド行に載せると、Windows 側の符号化で化けうる。

  記録はヒアドキュメントで渡す。区切り語は毎回ランダムにして、
  記録の中に同じ行があって途中で切れる、を起こさない。
  区切りを引用しているので、記録の中の $ や ` は展開されない。
  秘密の伏せ字は向こう(src/lib/log-notice.php)が掛ける。
#>
function Send-KmFailureMail {
    $target = if ($MailHostName -ne '') { $MailHostName } else { $HostName }
    Initialize-KmSsh -HostName $target -User $User -KeyPath $KeyPath -Port $Port -Gated:$Gated
    $tail = ($script:Lines | Select-Object -Last 120) -join "`n"
    $marker = 'KMLOG_' + [guid]::NewGuid().ToString('N')
    <#
      **試験の知らせは、件名で試験と分かるようにする。**
      -MailHostName は「取得は失敗させ、メールだけ届ける」試験のためのもの。
      付けずに送ると、存在しないホスト名の入った「失敗」が本物と見分けられない
      (2026-09-13 に試験の1通が、本物の失敗と受け取られた)。
    #>
    $testMark = if ($MailHostName -ne '') { '【試験】' } else { '' }
    if ($Gated) {
        # 門番の鍵ではシェルを渡せない。記録だけを標準入力で送り、件名は門番が決める(report-failure)
        Invoke-KmSshStdin -Script $tail -Shell $(if ($testMark) { 'report-failure --test' } else { 'report-failure' }) | Out-Null
        return
    }
    # **`${}` で区切る。** 日本語は変数名に使える文字なので、`$testMark手元の…` は
    # 「testMark手元の週次バックアップ」という未定義の変数になり、見出しの頭が丸ごと消える
    $label = "${testMark}手元の週次バックアップ ($env:COMPUTERNAME)" -replace "'", ''
    $remote = @"
cat <<'$marker' | $RemotePath/scripts/send-log.sh --path $RemotePath --label '$label' --status ng
$tail
$marker
"@
    Invoke-KmSshStdin -Script $remote | Out-Null
}

# ---------------------------------------------------------------- 取る

$ok = $false
$reason = ''
try {
    $root = Get-KmBackupRoot -Override $BackupRoot
    $script:LogPath = Join-Path $root 'backup-task.log'
    Write-RunLog ''
    Write-RunLog ('===== {0} 週次バックアップ ({1}) =====' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $env:COMPUTERNAME)

    $pull = Join-Path $PSScriptRoot 'backup-data.ps1'
    $pullArgs = @{
        HostName   = $HostName
        User       = $User
        KeyPath    = $KeyPath
        RemotePath = $RemotePath
        Keep       = $Keep
        Port       = $Port
        BackupRoot = $root
        Gated      = [bool]$Gated
    }

    <#
      **経過も結果もここで1本にする。** backup-data.ps1 の経過は Write-Host(情報ストリーム)で、
      結果は値。`*>&1` で束ね、Archive を持つ値だけを結果として受け取り、残りは記録へ書く。
      途中で throw されれば、そのまま下の catch に来る。
    #>
    $result = $null
    & $pull @pullArgs *>&1 | ForEach-Object {
        if ($null -ne $_ -and $_.PSObject.Properties['Archive']) {
            $result = $_
        } else {
            Write-RunLog "$_"
        }
    }
    if (-not $result) { throw 'backup-data.ps1 が結果を返しませんでした。' }

    $ok = $true
    Write-RunLog "取れました: $($result.OutDir)"
}
catch {
    $reason = $_.Exception.Message
    Write-RunLog "★ 失敗しました: $reason"
}

if ($ok) { exit 0 }

# ---------------------------------------------------------------- 知らせる

$mailNote = ''
if ($NoMail) {
    $mailNote = 'メールは送っていません(-NoMail)。'
} else {
    try {
        Send-KmFailureMail
        $mailNote = '管理者にメールを送りました。'
    } catch {
        $mailNote = 'メールは送れませんでした(ホストへ繋がりません)。'
        Write-RunLog "  メールの送信に失敗: $($_.Exception.Message)"
    }
}
Write-RunLog "  $mailNote"

if (-not $NoToast) {
    Show-KmFailureToast -Title 'KosenMap の週次バックアップに失敗しました' -Text "$reason`n$mailNote" | Out-Null
}

exit 1
