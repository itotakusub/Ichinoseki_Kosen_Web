<#
.SYNOPSIS
  検証機の入れ物(192.168.3.29 の Windows)で、要らない機能・サービスを止める。**29 の管理者の PowerShell で流す。**

.DESCRIPTION
  段 5(plan.md・docs/13 §0)。利用者の指示(2026-11-07)「機能の追加の無効化すべきものを上げて無効化処理を行うコマンドを作成してください。
  これは Windows ですよ。検証機の方です」。

    .\testhost-windows.ps1                 一覧と、いまの状態を見るだけ(何も変えない。Windows の機能を読むのに数分かかる)
    .\testhost-windows.ps1 -Apply          止める(yes を聞く)。前の状態を C:\KosenMapTest\windows-disable-<日時>.json に残す
    .\testhost-windows.ps1 -Apply -IncludeWsl   WSL と仮想マシン プラットフォームも止める(Ubuntu の WSL を使わないなら)
    .\testhost-windows.ps1 -Undo -Record C:\KosenMapTest\windows-disable-<日時>.json   残した記録から元に戻す

  ## なぜ止めるか

  この PC は i7-6500U・メモリ 8GB・**HDD**・有線 100 Mbps。VM(本番の写し)に 2〜2.5GB を渡すと Windows に残るのは 5GB ほどで、
  **HDD を常に読む常駐(索引・先読み)と、外へ送る常駐が VM の足を引っ張る**。また、ふたを閉じると眠るのでサーバーにならない。

    2026-11-07 に読んだら、**誰かが「Windows の機能」をほぼ全部入れた状態**だった(IIS と FTP サーバー・メッセージ キュー・
    Simple TCP・LPD・SMB 1.0・Telnet・TFTP・MultiPoint・サンドボックス・組み込み機器のロックダウン…)。
    **FTP(ftpsvc)・LPD・Simple TCP・メッセージ キュー・WinRM は自動起動で動いていた**(LAN に口を開けている)。

    種類        止めるもの                                         理由
    ----------  -------------------------------------------------  -----------------------------------------------
    Windows の  IIS(Web・FTP サーバー)・WAS・WCF の起動口          LAN に口を開けている。使わない
    機能        メッセージ キュー・MultiPoint・Simple TCP・LPD/LPR  同上(Simple TCP は echo・chargen など古い口)
                SMB 1.0・PowerShell 2.0・Telnet・TFTP              古い穴(WannaCry 系・ダウングレード)・平文の通信
                NFS のクライアント・AD LDS・DataCenterBridging     使わない
                組み込み機器のロックダウン(キーボード フィルター・書き込みフィルター・シェル ランチャー…)  使わない。書き込みや入力を横取りしうる
                サンドボックス・Application Guard・Host Guardian    使わない(VM のメモリと取り合う)
                DirectPlay・旧コンポーネント・メディア再生・XPS・Work Folders・TIFF の索引・Recall  使わない(Recall は画面を記録する)
                (-IncludeWsl のときだけ)WSL・仮想マシン プラットフォーム・Hypervisor Platform・Windows コンテナー  Ubuntu の WSL を使わないなら
    追加機能    Internet Explorer モード・VBScript・ステップ記録ツール  古い穴・使わない
    サービス    Windows Search(索引)・SysMain(先読み)            HDD を常に読む。VM の円盤と取り合う
                利用状況の送信(DiagTrack など)・エラー報告・互換性の評価  外へ送る
                WinRM(リモート管理)                              SSH で足りる。LAN に口を開けている
                印刷(Spooler)・地図・位置情報・Xbox・デモ・LAN への名乗り(SSDP・UPnP)・スマホ連携  使わない
                (上の機能を止めると消えるが、再起動を待たずに止める)ftpsvc・IISADMIN・W3SVC・LPDSVC・simptcp・MSMQ・
                Net.Tcp/Pipe/Msmq の起動口・MultiPoint
    電源        ふたを閉じたとき → 何もしない(電源・電池とも)      ノート PC をサーバーにするため
                休止状態 → 切る(hiberfil.sys を消す)              HDD の数 GB と、復帰の失敗を避ける
    更新        配信の最適化 → ほかの PC と分け合わない(HTTP だけ)  LAN・外へ配らない
  **触らないもの**: Hyper-V・OpenSSH サーバー(sshd)・Chrome リモート デスクトップ・**JQuake とそのタスク**・Microsoft Defender・Sysmon・ファイル共有(Server)・HDD の落下保護(dynabook)・.NET・
  Windows Update(と Orchestrator)・インターネット接続の共有(SharedAccess。Hyper-V の Default Switch が使う)・
  リモート デスクトップ・Edge の更新・WSL(-IncludeWsl が無いとき)。

  Windows の機能を止めたものは**再起動のあとに効く**(このスクリプトは再起動しない。VM を止めてから利用者が再起動する)。
  Windows PowerShell 5.1 でも動く書き方(?? や && を使わない)。保存は BOM 付き UTF-8・LF。
#>
[CmdletBinding()]
param(
    [switch]$Apply,
    [switch]$Undo,
    [string]$Record = '',
    [switch]$IncludeWsl,
    [switch]$Yes,
    [string]$RecordDir = 'C:\KosenMapTest',
    # オプションとその意味を出して終わる
    [switch]$Help
)

# -Help: オプションとその意味を出して終わる(2026-10-16。説明はこのファイルの先頭のコメントから km-help.ps1 が読む)
if ($Help) {
    $kmHelp = Join-Path $PSScriptRoot 'km-help.ps1'
    if (Test-Path $kmHelp) { & $kmHelp -Path $PSCommandPath } else { Get-Help $PSCommandPath -Detailed }
    exit 0
}

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

# ---------------------------------------------------------------- 一覧(ここだけ直せば足し引きできる)
$Services = @(
    @{ Name = 'WSearch'; Why = '索引。HDD を常に読む' },
    @{ Name = 'SysMain'; Why = '先読み。HDD とメモリを VM と取り合う' },
    @{ Name = 'DiagTrack'; Why = '利用状況を Microsoft へ送る' },
    @{ Name = 'dmwappushservice'; Why = '利用状況の送信の補助' },
    @{ Name = 'InventorySvc'; Why = '互換性の評価(送信)' },
    @{ Name = 'WerSvc'; Why = 'エラー報告を送る' },
    @{ Name = 'PcaSvc'; Why = '互換性アシスタント(窓を出す)' },
    @{ Name = 'WinRM'; Why = 'リモート管理。SSH で足りる・LAN に口を開ける' },
    @{ Name = 'Spooler'; Why = '印刷。使わない・穴の入口' },
    @{ Name = 'Fax'; Why = 'FAX' },
    @{ Name = 'MapsBroker'; Why = '地図の自動更新' },
    @{ Name = 'lfsvc'; Why = '位置情報' },
    @{ Name = 'RetailDemo'; Why = '店頭デモ' },
    @{ Name = 'WMPNetworkSvc'; Why = 'Media Player の共有' },
    @{ Name = 'XblAuthManager'; Why = 'Xbox' },
    @{ Name = 'XblGameSave'; Why = 'Xbox' },
    @{ Name = 'XboxNetApiSvc'; Why = 'Xbox' },
    @{ Name = 'XboxGipSvc'; Why = 'Xbox' },
    @{ Name = 'SSDPSRV'; Why = 'LAN への名乗り(SSDP)' },
    @{ Name = 'upnphost'; Why = 'LAN への名乗り(UPnP)' },
    @{ Name = 'FDResPub'; Why = 'LAN への名乗り(機能探索の公開)' },
    @{ Name = 'CDPSvc'; Why = 'つながったデバイス(スマホ連携)' },
    # 下の機能を止めると消えるが、再起動を待たずに止める(LAN に口を開けているもの)
    @{ Name = 'ftpsvc'; Why = 'FTP サーバー(IIS)' },
    @{ Name = 'W3SVC'; Why = 'Web サーバー(IIS)' },
    @{ Name = 'IISADMIN'; Why = 'IIS の管理' },
    @{ Name = 'WMSVC'; Why = 'IIS のリモート管理' },
    @{ Name = 'LPDSVC'; Why = 'LPD(印刷の受け口。515 番)' },
    @{ Name = 'simptcp'; Why = 'Simple TCP(echo・chargen など)' },
    @{ Name = 'MSMQ'; Why = 'メッセージ キュー' },
    @{ Name = 'MSMQTriggers'; Why = 'メッセージ キューのトリガー' },
    @{ Name = 'NetMsmqActivator'; Why = 'WCF の起動口(MSMQ)' },
    @{ Name = 'NetPipeActivator'; Why = 'WCF の起動口(Pipe)' },
    @{ Name = 'NetTcpActivator'; Why = 'WCF の起動口(TCP)' },
    @{ Name = 'Wms'; Why = 'MultiPoint' },
    @{ Name = 'WmsRepair'; Why = 'MultiPoint の修復' }
)
# Windows の機能。名前か正規表現(^ で始まるもの)で拾い、**いま有効なものだけ**を 1 回の dism で止める(1 つずつだと HDD で何十分もかかる)
$Features = @(
    @{ Match = '^IIS-'; Why = 'IIS(Web・FTP サーバー)' },
    @{ Match = '^WAS-'; Why = 'Windows プロセス アクティブ化(IIS・WCF 用)' },
    @{ Match = '^WCF-(HTTP-Activation|HTTP-Activation45|MSMQ-Activation45|NonHTTP-Activation|Pipe-Activation45|TCP-Activation45|TCP-PortSharing45)$'; Why = 'WCF の起動口' },
    @{ Match = '^MSMQ-'; Why = 'メッセージ キュー' },
    @{ Match = '^MultiPoint-'; Why = 'MultiPoint' },
    @{ Match = '^SMB1Protocol'; Why = 'SMB 1.0(WannaCry 系の穴)' },
    @{ Match = '^MicrosoftWindowsPowerShellV2'; Why = 'PowerShell 2.0(ダウングレードの穴)' },
    @{ Match = 'SimpleTCP'; Why = 'Simple TCP(echo・chargen など)' },
    @{ Match = 'TelnetClient'; Why = 'Telnet(平文)' },
    @{ Match = 'TFTP'; Why = 'TFTP(平文・認証なし)' },
    @{ Match = '^Printing-Foundation-(LPDPrintService|LPRPortMonitor|InternetPrinting-Client)$'; Why = 'LPD・LPR・インターネット印刷' },
    @{ Match = 'Printing-XPSServices-Features'; Why = 'XPS' },
    @{ Match = '^(ClientForNFS-Infrastructure|ServicesForNFS-ClientOnly|NFS-Administration)$'; Why = 'NFS のクライアント' },
    @{ Match = 'DirectoryServices-ADAM-Client'; Why = 'AD LDS' },
    @{ Match = 'DataCenterBridging'; Why = 'データ センター ブリッジング' },
    @{ Match = '^Client-(DeviceLockdown|EmbeddedBootExp|EmbeddedLogon|EmbeddedShellLauncher|KeyboardFilter|UnifiedWriteFilter)$'; Why = '組み込み機器のロックダウン' },
    @{ Match = '^Containers-(DisposableClientVM|Server-For-Application-Guard)$'; Why = 'サンドボックス・Application Guard' },
    @{ Match = 'HostGuardian'; Why = 'Host Guardian(シールドされた VM 用)' },
    @{ Match = '^(DirectPlay|LegacyComponents)$'; Why = '旧コンポーネント' },
    @{ Match = '^(MediaPlayback|WindowsMediaPlayer)$'; Why = 'メディア再生' },
    @{ Match = 'WorkFolders-Client'; Why = 'Work Folders' },
    @{ Match = 'TIFFIFilter'; Why = 'TIFF の文字の索引' },
    @{ Match = 'Recall'; Why = 'Recall(画面を記録する)' }
)
if ($IncludeWsl) {
    $Features += @{ Match = '^(Microsoft-Windows-Subsystem-Linux|VirtualMachinePlatform|HypervisorPlatform)$'; Why = 'WSL・仮想マシン プラットフォーム・Hypervisor Platform(Ubuntu の WSL を使わないなら)' }
    $Features += @{ Match = '^Containers(-HNS|-SDN)?$'; Why = 'Windows コンテナー(Hyper-V の VM には要らない)' }
}
# 追加機能(Capability)。名前の頭で拾う
$Capabilities = @(
    @{ Prefix = 'Browser.InternetExplorer~'; Why = 'Internet Explorer モード' },
    @{ Prefix = 'VBSCRIPT~'; Why = 'VBScript(古い穴)' },
    @{ Prefix = 'App.StepsRecorder~'; Why = 'ステップ記録ツール' }
)
# 触らない(一覧に紛れ込んでも止める前に弾く)
$Protected = @('vmms', 'vmcompute', 'HvHost', 'sshd', 'chromoting', 'WinDefend', 'MDCoreSvc', 'mpssvc', 'wuauserv', 'UsoSvc', 'SharedAccess', 'TermService', 'edgeupdate', 'edgeupdatem', 'WslService', 'LxssManager', 'LanmanServer', 'LanmanWorkstation', 'DSDHDDProtectService')
$ProtectedFeature = '^(Microsoft-Hyper-V|HyperV-|NetFx|Microsoft-RemoteDesktopConnection|MSRDC-Infrastructure|Printing-PrintToPDFServices-Features|Printing-Foundation-Features|Sysmon|SmbDirect|Windows-Identity-Foundation|WCF-Services45)'
$DoKey = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\DeliveryOptimization'
$LidGuid = '5ca83367-6e45-459f-a27b-476b1d01c936'
$ButtonsGuid = '4f971e89-eebd-4455-a8de-9e59040e7347'

function Say([string]$m) { Write-Host $m }
function Head([string]$m) { Write-Host ''; Write-Host "== $m" }

function Assert-Admin {
    $p = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    if (-not $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw '管理者の PowerShell で流してください(スタートを右クリック → ターミナル(管理者))。'
    }
}

function Get-LidAction([string]$Kind) {
    # powercfg の出力から「現在の AC/DC 電源設定のインデックス」を読む(表示の言葉に頼らず、最後の 2 つの 0x 値を AC・DC とみなす)
    $vals = @(powercfg /query SCHEME_CURRENT $ButtonsGuid $LidGuid | Select-String '0x[0-9a-fA-F]{8}' | ForEach-Object { [Convert]::ToInt32(($_.Matches[0].Value), 16) })
    if ($vals.Count -lt 2) { return $null }
    if ($Kind -eq 'AC') { return $vals[$vals.Count - 2] }
    return $vals[$vals.Count - 1]
}

function Get-State {
    $state = [ordered]@{ services = @{}; features = @{}; capabilities = @{}; lidAc = $null; lidDc = $null; hibernate = $null; doMode = $null }
    foreach ($s in $Services) {
        $x = Get-CimInstance Win32_Service -Filter "Name='$($s.Name)'" -ErrorAction SilentlyContinue
        if ($x) { $state.services[$s.Name] = @{ start = [string]$x.StartMode; running = ($x.State -eq 'Running') } }
    }
    Say '  Windows の機能を読んでいます(HDD なので数分かかります)…'
    $all = @(Get-WindowsOptionalFeature -Online | Where-Object { $_.State -eq 'Enabled' } | ForEach-Object { $_.FeatureName })
    foreach ($name in $all) {
        if ($name -match $ProtectedFeature) { continue }
        foreach ($f in $Features) {
            if ($name -match $f.Match) { $state.features[$name] = $f.Why; break }
        }
    }
    Say '  追加機能を読んでいます…'
    $caps = @(Get-WindowsCapability -Online | Where-Object { $_.State -eq 'Installed' } | ForEach-Object { $_.Name })
    foreach ($name in $caps) {
        foreach ($c in $Capabilities) {
            if ($name.StartsWith($c.Prefix)) { $state.capabilities[$name] = $c.Why }
        }
    }
    $state.lidAc = Get-LidAction 'AC'
    $state.lidDc = Get-LidAction 'DC'
    $h = Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Control\Power' -Name HibernateEnabled -ErrorAction SilentlyContinue
    if ($h) { $state.hibernate = [int]$h.HibernateEnabled }
    $d = Get-ItemProperty $DoKey -Name DODownloadMode -ErrorAction SilentlyContinue
    if ($d) { $state.doMode = [int]$d.DODownloadMode }
    return $state
}

function Show-State($state) {
    Head 'サービス(止める → 無効)'
    foreach ($s in $Services) {
        if (-not $state.services.ContainsKey($s.Name)) { continue }
        $v = $state.services[$s.Name]
        $mark = if ($v.start -eq 'Disabled' -and -not $v.running) { 'OK  ' } else { '止め' }
        Say ("  {0} {1,-18} {2,-8} {3,-4} {4}" -f $mark, $s.Name, $v.start, $(if ($v.running) { '動作' } else { '停止' }), $s.Why)
    }
    Head "Windows の機能(有効なもののうち止めるもの $($state.features.Count) 個。再起動のあとに効く)"
    foreach ($k in ($state.features.Keys | Sort-Object)) { Say ("  止め {0,-48} {1}" -f $k, $state.features[$k]) }
    if ($state.features.Count -eq 0) { Say '  OK   止めるものはありません' }
    if (-not $IncludeWsl) { Say '  (WSL・仮想マシン プラットフォーム・Hypervisor Platform・Windows コンテナーは触らない。止めるなら -IncludeWsl)' }
    Head '追加機能(取り除く)'
    foreach ($k in ($state.capabilities.Keys | Sort-Object)) { Say ("  止め {0,-48} {1}" -f $k, $state.capabilities[$k]) }
    if ($state.capabilities.Count -eq 0) { Say '  OK   取り除くものはありません' }
    Head '電源・更新'
    $lidText = { param($v) if ($null -eq $v) { '読めない(この電源プランに項目が無い。-Apply で設定する)' } elseif ($v -eq 0) { '何もしない' } elseif ($v -eq 1) { 'スリープ' } elseif ($v -eq 2) { '休止' } else { 'シャットダウン' } }
    Say ("  {0} ふたを閉じたとき(電源)  : {1}" -f $(if ($state.lidAc -eq 0) { 'OK  ' } else { '止め' }), (& $lidText $state.lidAc))
    Say ("  {0} ふたを閉じたとき(電池)  : {1}" -f $(if ($state.lidDc -eq 0) { 'OK  ' } else { '止め' }), (& $lidText $state.lidDc))
    Say ("  {0} 休止状態                  : {1}" -f $(if ($state.hibernate -eq 0) { 'OK  ' } else { '止め' }), $(if ($state.hibernate -eq 0) { '切' } else { '入' }))
    Say ("  {0} 配信の最適化の分け合い    : {1}" -f $(if ($state.doMode -eq 0) { 'OK  ' } else { '止め' }), $(if ($state.doMode -eq 0) { 'HTTP だけ' } else { '既定(LAN と分け合う)' }))
    Head '触らないもの'
    Say '  Hyper-V・OpenSSH サーバー・Chrome リモート デスクトップ・JQuake とそのタスク・Defender・Sysmon・Windows Update・'
    Say '  インターネット接続の共有(Default Switch が使う)・ファイル共有・リモート デスクトップ・Edge の更新・HDD の落下保護・.NET'
}

function Set-LidAction([int]$Ac, [int]$Dc) {
    & powercfg /setacvalueindex SCHEME_CURRENT $ButtonsGuid $LidGuid $Ac
    & powercfg /setdcvalueindex SCHEME_CURRENT $ButtonsGuid $LidGuid $Dc
    & powercfg /setactive SCHEME_CURRENT
}

# 機能をまとめて 1 回の dism で(1 つずつだと HDD で何十分もかかる)
function Invoke-DismFeatures([string]$Verb, [string[]]$Names) {
    if ($Names.Count -eq 0) { return 0 }
    $dismArgs = @('/Online', "/$Verb", '/NoRestart', '/Quiet')
    foreach ($n in $Names) { $dismArgs += "/FeatureName:$n" }
    if ($Verb -eq 'Enable-Feature') { $dismArgs += '/All' }
    & dism.exe @dismArgs | Out-Null
    return $LASTEXITCODE
}

# ---------------------------------------------------------------- 戻す
if ($Undo) {
    Assert-Admin
    if (-not $Record -or -not (Test-Path $Record)) { throw '-Record に、-Apply が残した記録(C:\KosenMapTest\windows-disable-<日時>.json)を指定してください。' }
    $rec = Get-Content $Record -Raw -Encoding UTF8 | ConvertFrom-Json
    Head "戻す($Record)"
    foreach ($p in $rec.services.PSObject.Properties) {
        $start = switch ([string]$p.Value.start) { 'Auto' { 'Automatic' } 'Manual' { 'Manual' } default { 'Disabled' } }
        try {
            Set-Service -Name $p.Name -StartupType $start -ErrorAction Stop
            if ($p.Value.running) { Start-Service -Name $p.Name -ErrorAction SilentlyContinue }
            Say "  戻し サービス $($p.Name) → $start"
        } catch { Say "  ★ $($p.Name): $($_.Exception.Message)(機能を戻して再起動したあとに、もう一度 -Undo)" }
    }
    $names = @($rec.features.PSObject.Properties | ForEach-Object { $_.Name })
    if ($names.Count -gt 0) {
        Say "  機能を $($names.Count) 個戻します(dism。数分〜十数分)"
        $code = Invoke-DismFeatures 'Enable-Feature' $names
        Say "  dism の終了コード $code(3010 = 再起動が要る)"
    }
    foreach ($p in $rec.capabilities.PSObject.Properties) {
        try { Add-WindowsCapability -Online -Name $p.Name -ErrorAction Stop | Out-Null; Say "  戻し 追加機能 $($p.Name)" } catch { Say "  ★ $($p.Name): $($_.Exception.Message)" }
    }
    if ($null -ne $rec.lidAc -and $null -ne $rec.lidDc) { Set-LidAction ([int]$rec.lidAc) ([int]$rec.lidDc); Say '  戻し ふたを閉じたとき' }
    if ($rec.hibernate -eq 1) { & powercfg /h on; Say '  戻し 休止状態 → 入' }
    if ($null -eq $rec.doMode) { Remove-ItemProperty $DoKey -Name DODownloadMode -ErrorAction SilentlyContinue } else { Set-ItemProperty $DoKey -Name DODownloadMode -Value ([int]$rec.doMode) }
    Say '  戻し 配信の最適化'
    Say ''
    Say '戻しました。Windows の機能は再起動のあとに効きます。'
    exit 0
}

# ---------------------------------------------------------------- 見る
Assert-Admin
Head 'いまの状態'
$before = Get-State
Show-State $before
if (-not $Apply) {
    Say ''
    Say '見ただけです。止めるなら: .\testhost-windows.ps1 -Apply'
    exit 0
}

# ---------------------------------------------------------------- 止める
if (-not $Yes) {
    Say ''
    $ans = Read-Host '上の「止め」を止めます。続けるなら yes'
    if ($ans -ne 'yes') { throw 'やめました。' }
}
if (-not (Test-Path $RecordDir)) { New-Item -ItemType Directory -Path $RecordDir | Out-Null }
$recordPath = Join-Path $RecordDir ('windows-disable-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.json')
# 前の状態を先に書く(途中で止まっても戻せるように)
($before | ConvertTo-Json -Depth 5) | Set-Content -Path $recordPath -Encoding UTF8
Say "  前の状態を残しました: $recordPath"

Head 'サービス'
foreach ($s in $Services) {
    if ($Protected -contains $s.Name) { Say "  ★ $($s.Name) は触らない一覧にあるので飛ばします"; continue }
    if (-not $before.services.ContainsKey($s.Name)) { continue }
    $v = $before.services[$s.Name]
    if ($v.start -eq 'Disabled' -and -not $v.running) { continue }
    try {
        if ($v.running) { Stop-Service -Name $s.Name -Force -ErrorAction Stop }
        Set-Service -Name $s.Name -StartupType Disabled -ErrorAction Stop
        Say "  止め $($s.Name)($($s.Why))"
    } catch {
        Say "  ★ $($s.Name) を止められません: $($_.Exception.Message)"
    }
}

Head 'Windows の機能'
$names = @($before.features.Keys | Where-Object { $_ -notmatch $ProtectedFeature })
$needReboot = $false
if ($names.Count -gt 0) {
    Say "  $($names.Count) 個を止めます(dism をまとめて 1 回。HDD なので十数分かかることがある)"
    $code = Invoke-DismFeatures 'Disable-Feature' $names
    if ($code -eq 0 -or $code -eq 3010) {
        Say "  止め $($names.Count) 個(dism の終了コード $code)"
        $needReboot = $true
    } else {
        Say "  ★ dism が失敗しました(終了コード $code)。C:\Windows\Logs\DISM\dism.log を見る。もう一度 -Apply で、残りだけを試します"
    }
}

Head '追加機能'
foreach ($k in @($before.capabilities.Keys)) {
    try { Remove-WindowsCapability -Online -Name $k -ErrorAction Stop | Out-Null; Say "  取り除き $k" } catch { Say "  ★ $k を取り除けません: $($_.Exception.Message)" }
}

Head '電源・更新'
if ($before.lidAc -ne 0 -or $before.lidDc -ne 0) { Set-LidAction 0 0; Say '  止め ふたを閉じても何もしない(電源・電池とも)' }
if ($before.hibernate -ne 0) { & powercfg /h off; Say '  止め 休止状態(hiberfil.sys を消した)' }
if ($before.doMode -ne 0) {
    if (-not (Test-Path $DoKey)) { New-Item -Path $DoKey -Force | Out-Null }
    Set-ItemProperty -Path $DoKey -Name DODownloadMode -Value 0 -Type DWord
    Say '  止め 配信の最適化の分け合い(HTTP だけ)'
}

Say ''
Say "止めました。戻すとき: .\testhost-windows.ps1 -Undo -Record $recordPath"
if ($needReboot) { Say '★ Windows の機能は再起動のあとに効きます。VM を止めてから再起動してください(このスクリプトは再起動しません)。' }