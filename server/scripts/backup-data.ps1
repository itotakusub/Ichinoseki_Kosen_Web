<#
.SYNOPSIS
  稼働中のホストから、移行と復旧に必要なデータ一式を取得してこの PC へ持ち帰る。

.DESCRIPTION
  取得するもの:
    1. MariaDB のダンプ        … アプリのデータ全部(地図・タスク・問い合わせ・監査ログ…)
    2. Postgres のダンプ        … Logto(ユーザー・アプリ登録・ロール)
    3. src/uploads/            … ファイル管理・プロフィール画像・配布物の**実体**
    4. src/config/*.local.php  … DB 接続情報など(**秘密を含む**)
    5. .env                    … 環境変数(**秘密を含む**)

  **DB の行だけ移して uploads を忘れると、一覧には出るのに落とせない**状態になる。
  だから既定では全部まとめて取る。

  ## やり方の方針(2026-09-07 に変えた)

  **作るのはホスト。暗号化もホスト。この PC は持ち帰って開ける役。**

    ホスト   scripts/host-backup.sh がダンプ → まとめる → **その場で暗号化**
    この PC  暗号のまま受け取る → 一時フォルダで復号 → 検査 → **平文を消す**

  以前はこの PC からダンプを撃ち、平文のまま運んでいた。中身は DB 全部と
  .env と config/*.local.php —— **復元に要るものは、そのまま乗っ取りに要るもの**
  でもあるのに、ホストの /tmp にも この PC の backups/ にも平文で置かれていた。
  (その頃の手順は Old/backup-data-plaintext.ps1)

  ## 誰が開けるのか

  暗号化に使うのは証明書(公開鍵)だけで、**開ける秘密鍵はこの PC にしかない。**
  ホストは自分で作ったバックアップを自分では開けない。
  鍵を作るのは backup-keys.ps1(最初に1回だけ)。

  **秘密鍵を失うと、取ったものは二度と開けない。** 控えを別の場所にも置くこと。

  ## それでも検査はする

  「ファイルができた」を成功と見なさない、という方針は変えていない。
  一時フォルダへ復号して行数まで数え、**見終わったら平文を消す。**
  数字だけは summary.txt に平文で残す(開かずに前週と見比べられるように)。

.EXAMPLE
  .\backup-data.ps1
  .\backup-data.ps1 -HostName 203.0.113.10 -User km -KeyPath ~\.ssh\km-vps -RemotePath /opt/kosenmap
#>
[CmdletBinding()]
param(
    # 既定は本番。**以前は校内 LAN(192.168.3.29)を指していたが、そちらはもう使わない。**
    # 鍵の既定も実在しない km-deploy を指していたので km_vps へ直した(2026-08-29)。
    [string]$HostName = 'ito4.jp',
    # 2026-09-18 から kmops(置き場の持ち主・docker あり。docs/12 §7-4 B 段 2)。-Gated なら鍵は km_backup を渡す
    [string]$User = 'kmops',
    [string]$KeyPath = "$env:USERPROFILE\.ssh\km_ops",
    # ホスト側の server/ に相当するフォルダ。未指定なら docker inspect から探す
    [string]$RemotePath = '/opt/kosenmap',
    # 保存先(この PC)。既定は <控えの置き場>\<ホスト>-<日時>\
    [string]$OutDir = '',
    # 控えの置き場。既定は backup-lib.ps1 の Get-KmBackupRoot(D:\Backups)
    [string]$BackupRoot = '',
    # 何世代残すか。古いものから消す
    [int]$Keep = 7,
    [int]$Port = 22,
    # 復号に使う秘密鍵の置き場。**リポジトリの外**(backup-keys.ps1 と揃える)
    [string]$KeyDir = "$env:USERPROFILE\.kosenmap",
    # 初めて繋ぐホスト(新しい VPS など)でホスト鍵を取り込む
    [switch]$AcceptHostKey,
    # 鍵にパスフレーズがあるとき
    [switch]$Interactive,
    <#
      **控え専用の鍵(ホストの scripts/ssh-backup-gate.sh の門番付き)で取る**(2026-09-17。docs/12 §7-4)。
      その鍵は「作る(backup)」と「取る(scp -O)」しかできない。盗まれても、暗号化された控えが読めるだけ。
      ホストへ任意のコマンドを送らないので、-RemotePath は既定の /opt/kosenmap のまま使う(探しに行けない)。
    #>
    [switch]$Gated
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$target = "$User@$HostName"

# 接続部分は共有(初回接続やパスフレーズの見分けは km-ssh.ps1 が担う)
. (Join-Path $PSScriptRoot 'km-ssh.ps1')
# 検査・復号・世代整理は open-backup.ps1 と共通(**開き方を2通り持たない**)
. (Join-Path $PSScriptRoot 'backup-lib.ps1')
Initialize-KmSsh -HostName $HostName -User $User -KeyPath $KeyPath -Port $Port -Interactive:$Interactive -Gated:$Gated

function Invoke-Remote {
    param([Parameter(Mandatory)][string]$Command, [switch]$AllowFailure)
    return Invoke-KmSsh -Command $Command -AllowFailure:$AllowFailure
}

<#
  **ダンプをこの PC から撃たなくなった。**

  以前はコンテナへスクリプトを流し込んで /tmp へ書かせ、docker cp で運んでいた。
  そこには「ホストのシェルが cmd だと `>` を横取りする」「PowerShell は
  ネイティブへのパイプで行末を CRLF に戻すので、受け取る側で `tr -d '\r'` する」
  といった罠が詰まっていた —— **ホストの sh で完結させれば、どれも起きない。**

  いまはホスト側の scripts/host-backup.sh が全部やる。
  当時の手順と罠は Old/backup-data-plaintext.ps1 に残してある。
#>

<#
  **同時に 2 つ走らせない。**

  2026-09-18 02:00、週次のタスク(手で起動)と手で流したこのスクリプトが重なった。先に終わった方の世代整理が、
  もう一方の**取得中でまだ空の保存先**を「空の世代」として消し、後の方が「取得に失敗しました」で落ちて失敗の知らせを出した。
  PC 全体で 1 つの名前付きミューテックスを取り、先に走っている方が終わるまで待つ(タスクの制限 1 時間に収まる 30 分まで)。
  プロセスが終われば(落ちても)OS が放すので、取りっぱなしで次が永久に待つことはない。
#>
$kmBackupMutex = [System.Threading.Mutex]::new($false, 'Global\KosenMapBackupData')
$kmBackupLocked = $false
try {
    if (-not $kmBackupMutex.WaitOne(0)) {
        Write-Host '      別の控えが走っています。終わるまで待ちます(最大 30 分)'
        $kmBackupLocked = $kmBackupMutex.WaitOne([TimeSpan]::FromMinutes(30))
    } else {
        $kmBackupLocked = $true
    }
} catch [System.Threading.AbandonedMutexException] {
    # 前の持ち主が落ちた。取れている
    $kmBackupLocked = $true
}
if (-not $kmBackupLocked) { throw '別の控え(backup-data.ps1)が 30 分以上終わりません。終わってから流し直してください' }

Write-Host "[1/6] $target へ疎通確認"
Assert-KmSshReady -AcceptHostKey:$AcceptHostKey

if ($RemotePath -eq '' -and $Gated) {
    throw '-Gated のときは -RemotePath を省けません(門番の鍵ではホストの置き場を探しに行けない)。'
}
if ($RemotePath -eq '') {
    # 名前ではなく compose のサービスの札で引く(km-ssh.ps1)。
    # **コンテナ名を変えても動く** —— 2026-09-07 に dev-* から km-* へ改めた
    $RemotePath = Get-KmRemoteProjectPath
}
Write-Host "      ホスト側の server/ = $RemotePath"

# **止まっているかの判断はホスト側に任せる**(host-backup.sh が見る)。
# こちらでも見ると、条件が2箇所になって片方だけ直される

<#
  世代フォルダの名前に**取得元のホスト名を入れる**。

  以前は日時だけだったため、`backups/` に校内 LAN と本番の `.env` が
  混在していた(`20260828-132405` が旧 LAN、`20260828-123904` が本番)。
  中身を開くまで見分けが付かず、**復元のときに取り違える経路**になっていた。

  ホスト名にはファイル名に使えない文字が入りうるので、安全な字だけに落とす。
#>
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$hostTag = ($HostName -replace '[^A-Za-z0-9.\-]', '_')
# 置き場は backup-lib.ps1 の Get-KmBackupRoot 1か所で決める(既定 D:\Backups)。
# 無ければここで止まる —— リポジトリの中(server\backups)へ黙って落ちない
$BackupRoot = Get-KmBackupRoot -Override $BackupRoot
if ($OutDir -eq '') { $OutDir = Join-Path $BackupRoot "$hostTag-$stamp" }
New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
Write-Host "      保存先: $OutDir"

<#
  ---- ここから、ホストに作らせて暗号のまま持ち帰る(2026-09-07)----

  以前はこの PC からダンプを撃ち、**平文のまま** docker cp → scp で運んでいた。
  中身は DB 全部と .env と config/*.local.php —— **復元に要るものは、
  そのまま乗っ取りに要るもの**でもある。それが

    - ホストの /tmp と /opt/kosenmap に平文で置かれ
    - この PC の backups/ にも平文で残っていた

  ホスト側で暗号化してから運ぶ形に変えた。開ける秘密鍵はこの PC にしかないので、
  **ホストは自分で作ったバックアップを自分では開けない。**

  検査(行数の突き合わせ)は今までどおり行う ——
  そのために一時フォルダへ復号し、**見終わったら平文を消す。**
  「ファイルができた」を成功と見なさない、という方針は変えていない。
#>
<#
  **失敗したら、作りかけのフォルダを残さない。**

  ここから先で落ちると、[1/6] で作った保存先だけが**空のまま**残る
  (2026-09-07 に2つ残した)。世代整理はフォルダの数で数えるので、
  **空の失敗跡が本物の世代を1つずつ押し出す。**
  消す方向の間違いは取り返しがつかないので、その場で片付ける。

  中身が1つでもあるなら消さない —— 途中まで取れたものは調べる価値がある。
#>
trap {
    if ((Test-Path $OutDir) -and
        @(Get-ChildItem $OutDir -Recurse -File -Force -ErrorAction SilentlyContinue).Count -eq 0) {
        Remove-Item $OutDir -Recurse -Force -ErrorAction SilentlyContinue
        Write-Host "      空の保存先を片付けました: $OutDir"
    }
    break
}

Write-Host '[2/6] ホストで作らせる(その場で暗号化)'
$remoteScript = "$RemotePath/scripts/host-backup.sh"
<#
  **ホストに世代数を渡さない。** 何本残すかはホスト側(host-backup.sh の既定 14)が決める。

  以前はここで `--keep 3` を渡していた。host-backup.sh は作ったあとに世代整理をするので、
  **この PC から1回取るたびに、ホストの控えが3本まで削られていた。**
  2026-09-13 の週次タスクで、cron が毎晩積んだ6本(9/7〜9/12)が一度に消えた
  (手元の backup-task.log に「消しました」が6行、「3 世代まで残します」)。

  cron の側は 14 本のつもりで動いているので、ホストを見ても設定は正しく見える。
  **消していたのは別の機械から来た1回**で、ホストの記録には残らない。
#>
# 門番の鍵では `backup` だけが通る(門番が host-backup.sh を同じ置き場で走らせる)
$made = if ($Gated) { Invoke-Remote 'backup' } else { Invoke-Remote "sh $remoteScript --path $RemotePath" }
$made | ForEach-Object { Write-Host "      $_" }

<#
  **出来たものを名指しで受け取る。** ls の一番新しいものを拾う形にすると、
  作成に失敗した回に**前回のものを持ち帰って「成功」に見える**。

  ただし「名指し」を**文章から拾ってはいけない。** 以前はここで
  `km-backup-*.cms` に見える行の**最後のもの**を取っていたが、世代整理が

      消しました: km-backup-ubuntu-20260907-114924.tar.gz.cms

  と出すので、**いま作ったものではなく、いま消したものを掴んだ**
  (2026-09-07。scp が "No such file or directory" で落ちて発覚した)。
  作ったものと消したものは同じ形をしているので、**文章では見分けられない。**

  host-backup.sh が機械向けに1行だけ出す `KM-ARCHIVE <名前>` を見る。
  人向けの案内文が増えても減っても、この行は動かない。
#>
$encName = $made |
    ForEach-Object { if ($_ -match '^\s*KM-ARCHIVE\s+(\S+)\s*$') { $Matches[1] } } |
    Select-Object -First 1
if (-not $encName) {
    throw @"
ホスト側が KM-ARCHIVE の行を出しませんでした。

  バックアップが作られなかったか、ホスト側の host-backup.sh が古いかのどちらかです。
  上の出力を確認し、古い場合は .\deploy-to-host.ps1 -Action up で配備し直してください。
"@
}
Write-Host "      $encName"

Write-Host '[3/6] 暗号のまま持ち帰る'
$encLocal = Join-Path $OutDir $encName
Copy-KmFrom -Path "$RemotePath/backups/$encName" -Destination $encLocal | Out-Null
Write-Host ("      {0:N0} バイト" -f (Get-Item $encLocal).Length)

Write-Host '[4/6] この PC で復号(検査のため。あとで消します)'
$privateKey = Join-Path $KeyDir 'backup-private.pem'

<#
  一時フォルダは **%TEMP% ではなく backups/ の下**に置く。
  %TEMP% は他の利用者からも辿れる場所にあることがあり、
  ほんの数秒でも平文の .env をそこへ広げたくない。
#>
$work = Join-Path $OutDir '.open'
try {
    Open-KmBackup -Path $encLocal -PrivateKey $privateKey -Destination $work | Out-Null

    Write-Host '[5/6] 取得したものを検査'
    Test-KmBackup -Dir $work -SummaryPath (Join-Path $OutDir 'summary.txt') | Out-Null
}
finally {
    # **平文を残さない。** 検査が失敗しても必ず消す
    Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host '[6/6] 世代を整理'
Invoke-KmBackupRotate -BackupRoot $BackupRoot -Keep $Keep

Write-Host ''
Write-Host "完了しました: $OutDir"
Write-Host '  中身は暗号化されています。開くには:'
Write-Host "    .\open-backup.ps1 -Path `"$encLocal`""
Write-Host ''
Write-Host '  **秘密鍵を失うと、取ったものは二度と開けません。**'
Write-Host "  $privateKey の控えを、この PC とは別の場所にも置いてください。"

<#
  **呼んだ側へは、文章ではなく値で返す。**

  ここまでの経過は全部 Write-Host なので**パイプラインには乗らない。**
  つまり `$r = & .\backup-data.ps1` で受かるのはこの1つだけになる。

  ホスト側で同じことを `KM-ARCHIVE` の行でやっている。理由も同じ ——
  出来たものの名前を**人向けの出力から拾わせない。**
  拾わせると、案内文を1行足しただけで呼ぶ側が壊れる
  (実際にホスト側で踏んだ。世代整理の「消しました:」を掴んだ)。
#>
[pscustomobject]@{
    OutDir     = $OutDir
    Archive    = $encLocal
    ArchiveName = $encName
    Summary    = (Join-Path $OutDir 'summary.txt')
    PrivateKey = $privateKey
}
