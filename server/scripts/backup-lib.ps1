<#
  バックアップの共通部分。backup-data.ps1 と open-backup.ps1 が読む。

  **1箇所に置く。** 「取ったものを検査する」も「暗号を開く」も両方から要るので、
  片方に書くともう片方で似て非なるものが生えて、
  **開き方が2通りある**状態になる(復元の当日にそれをやると詰む)。
#>

<#
  openssl を探す。**無ければ分かる形で止める** ——
  暗号化したのに開けない、が一番まずい。
#>
function Get-KmOpenSsl {
    $cmd = Get-Command openssl -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    foreach ($candidate in @(
        "$env:ProgramFiles\OpenSSL-Win64\bin\openssl.exe",
        "$env:ProgramFiles\Git\usr\bin\openssl.exe"
    )) {
        if (Test-Path $candidate) { return $candidate }
    }
    throw @"
openssl が見つかりません。バックアップを開けません。

  winget install --id FireDaemon.OpenSSL
  (または Git for Windows 同梱の C:\Program Files\Git\usr\bin\openssl.exe)
"@
}

<#
  暗号化されたバックアップを開いて、指定のフォルダへ展開する。

  **`-binary` を必ず付ける。** 付けないと CMS が中身を「文字」として扱い、
  改行を変換して**壊れた tar.gz** になる
  (実測: 200,000 バイトの入力が 200,734 バイトになって展開できなくなった)。
  開くまで気づけない種類の壊れ方なので、ここは削らないこと。
#>
function Open-KmBackup {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$PrivateKey,
        [Parameter(Mandatory)][string]$Destination
    )

    if (-not (Test-Path $Path)) { throw "バックアップがありません: $Path" }
    if (-not (Test-Path $PrivateKey)) {
        throw @"
秘密鍵がありません: $PrivateKey

  この鍵でしか開きません。控えを別の場所に置いていないか確かめてください。
  まだ作っていない場合は: .\backup-keys.ps1
"@
    }

    $openssl = Get-KmOpenSsl
    if (-not (Test-Path $Destination)) { New-Item -ItemType Directory -Path $Destination -Force | Out-Null }
    $bundle = Join-Path $Destination 'bundle.tar.gz'

    & $openssl cms -decrypt -binary -inform DER -in $Path -inkey $PrivateKey -out $bundle
    if ($LASTEXITCODE -ne 0) {
        throw @"
復号できませんでした: $Path

  ホストに置いた証明書と、この PC の鍵が対になっていない可能性があります:
    .\backup-keys.ps1 -Check
"@
    }

    & tar -xzf $bundle -C $Destination
    if ($LASTEXITCODE -ne 0) { throw "取り出せませんでした: $bundle" }
    Remove-Item $bundle -Force -ErrorAction SilentlyContinue

    return $Destination
}

<#
  取ったものを検査する。

  **「ファイルがある」を成功と見なさない。**
  終端まで書けているか、主要テーブルに行が入っているかまで見る ——
  空のダンプでもファイルはできるし、大きさもそれらしく出る。

  ここに出た数字を、復元したあとの数字と突き合わせる(docs/03-backup.ipynb の「戻す」)。
#>
function Test-KmBackup {
    param(
        [Parameter(Mandatory)][string]$Dir,
        [string]$SummaryPath = ''
    )

    $db = Join-Path $Dir 'km-mariadb.sql'
    $pg = Join-Path $Dir 'km-postgres.sql'
    $up = Join-Path $Dir 'km-uploads.tar.gz'

    foreach ($needed in @($db, $pg)) {
        if (-not (Test-Path $needed)) { throw "バックアップに入っていません: $(Split-Path -Leaf $needed)" }
    }

    # **終端まで書けているか。** 途中で切れても大きさは出る
    $tail = Get-Content $db -Tail 3 -ErrorAction SilentlyContinue
    if (($tail -join "`n") -notmatch 'Dump completed') {
        throw "MariaDB のダンプが途中で切れています(末尾に Dump completed がありません)"
    }

    $lines = @()
    $tables = (Select-String -Path $db -Pattern '^CREATE TABLE').Count
    $pgTables = (Select-String -Path $pg -Pattern '^CREATE TABLE').Count
    $lines += ("    MariaDB   {0,12:N0} バイト / テーブル {1} 個" -f (Get-Item $db).Length, $tables)
    $lines += ("    Postgres  {0,12:N0} バイト / テーブル {1} 個" -f (Get-Item $pg).Length, $pgTables)

    if (Test-Path $up) {
        $entries = (& tar -tzf $up | Measure-Object).Count
        $lines += ("    uploads   {0,12:N0} バイト / {1} エントリ" -f (Get-Item $up).Length, $entries)
    } else {
        $lines += '    uploads   ありません(ホスト側に uploads/ が無い場合はこれで正常)'
    }

    <#
      復元に要る秘密が入っているか。**入っていないと、戻せても動かない**

      設定の入れ物は**2通りある。**
        km-config.tar.gz … いまの形(host-backup.sh がまとめる)
        config/          … 古い形(この PC から取っていた頃のまま)

      古い世代を今の証明書で暗号化し直したものは後者なので、
      `km-config.tar.gz` だけを見ると **「ありません(復元に要ります)」と嘘をつく。**
      中身はちゃんと入っているのに壊れているように見え、
      **読んだ人がその世代を捨てかねない。**
      鳴らなくてよい警告を鳴らすと、鳴るべきときに読まれなくなる。
    #>
    if (Test-Path (Join-Path $Dir 'env.txt')) {
        $lines += '    env.txt  あり'
    } elseif (Test-Path (Join-Path $Dir '.env')) {
        $lines += '    .env  あり(古い形)'
    } else {
        $lines += '    env.txt  **ありません**(復元に要ります)'
    }

    if (Test-Path (Join-Path $Dir 'km-config.tar.gz')) {
        $lines += '    km-config.tar.gz  あり'
    } elseif (Test-Path (Join-Path $Dir 'config')) {
        $lines += ("    config/  あり(古い形。{0} ファイル)" -f
            @(Get-ChildItem (Join-Path $Dir 'config') -File -Force -ErrorAction SilentlyContinue).Count)
    } else {
        $lines += '    km-config.tar.gz  **ありません**(復元に要ります)'
    }

    <#
      主要テーブルの行数まで数える。
      mariadb-dump は INSERT のタプルを `),(` で並べるので、その数から求める。
    #>
    $sqlText = Get-Content $db -Raw
    $lines += ''
    $lines += '  主要テーブルの行数(復元後にこの数字と突き合わせる)'
    foreach ($t in 'km_map_nodes', 'km_map_edges', 'km_admin_log', 'km_form_submissions', 'km_tasks', 'km_faq', 'km_files', 'km_user_profiles',
                   'km_map_events', 'km_map_event_closures', 'km_map_event_pois', 'km_map_event_aliases') {
        $m = [regex]::Match($sqlText, "INSERT INTO ``$t`` VALUES(.*?);[\r\n]", 'Singleline')
        $rows = if ($m.Success) { ([regex]::Matches($m.Groups[1].Value, '\)\s*,\s*\(')).Count + 1 } else { 0 }
        $lines += ("    {0,-24} {1,6} 行" -f $t, $rows)
    }
    # 教職員氏名は**失うと戻せない**種類のデータなので、入っていることを明示的に見る
    $nodes = [regex]::Match($sqlText, "INSERT INTO ``km_map_nodes`` VALUES(.*?);[\r\n]", 'Singleline').Groups[1].Value
    $withName = ([regex]::Matches($nodes, "'[^']*[一-鿿][^']*','[^']*[一-鿿]")).Count
    $lines += ("    {0,-24} {1,6} 件" -f '(うち教職員氏名あり)', $withName)

    <#
      **空を通さない。** ここまでの検査は「形」しか見ていない ——
      地図が1件も入っていないダンプでも、テーブル定義があれば全部通る。
    #>
    $nodeRows = [regex]::Matches(
        [regex]::Match($sqlText, "INSERT INTO ``km_map_nodes`` VALUES(.*?);[\r\n]", 'Singleline').Groups[1].Value,
        '\)\s*,\s*\('
    ).Count
    if ($nodeRows -lt 1) {
        throw '地図の地点が1件も入っていません。ダンプを取り直してください。'
    }

    Write-Host ''
    Write-Host '  取得結果'
    $lines | ForEach-Object { Write-Host $_ }

    if ($SummaryPath -ne '') {
        <#
          **数字だけは平文で残す。**
          暗号化したものは開くまで中身が見えないので、
          「先週より行が減っていないか」を毎回開いて確かめることになる。
          行数に秘密は無い(名前も値も出さない)。
        #>
        $header = @(
            "取得: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')",
            '  ※ 中身は暗号化されています。開くには open-backup.ps1',
            ''
        )
        Set-Content -Path $SummaryPath -Value ($header + $lines) -Encoding utf8
    }

    return $lines
}

<#
  手元の控えの置き場。**決める場所はここ1つ。**

  以前は backup-data.ps1 / open-backup.ps1 / 世代整理 / 週次タスクの4か所が、それぞれ
  `server\backups` を直に組み立てていた。利用者が控えを D:\Backups へ移したところ、
  **移したものは世代整理にも「一番新しいものを開く」にも見えなくなった**(2026-09-13)。

  ## リポジトリの中に置かない

  控えそのものは暗号化されているが、開いた平文(opened-*)が隣に残る事故が2度起きている
  (security-review-2026-09-10 の P0)。リポジトリを渡す・写すと一緒に渡る場所に置かない。

  ## 置き場が無ければ止まる

  `server\backups` へ黙って落ちない。落ちると「D: に取っているつもり」のまま
  リポジトリの中に溜まり、しかも世代整理はそちらを見ない。

  上書きは %USERPROFILE%\.kosenmap\backup-root.txt の1行目、または -Override。
#>
function Get-KmBackupRoot {
    param([string]$Override = '')

    $root = $Override
    if ($root -eq '') {
        $conf = Join-Path $env:USERPROFILE '.kosenmap\backup-root.txt'
        if (Test-Path -LiteralPath $conf) {
            $root = "$(Get-Content -LiteralPath $conf -TotalCount 1 -ErrorAction SilentlyContinue)".Trim()
        }
    }
    if ($root -eq '') { $root = 'D:\Backups' }

    if (-not (Test-Path -LiteralPath $root -PathType Container)) {
        throw @"
控えの置き場がありません: $root

  作るか、別の場所を指定してください:
    New-Item -ItemType Directory '$root'
    Set-Content "`$env:USERPROFILE\.kosenmap\backup-root.txt" 'E:\Backups'
"@
    }
    return (Resolve-Path -LiteralPath $root).Path
}

<#
  開いた平文の残りを片付ける。**24時間より古いものだけ。**

  対象は2つ:
    <置き場>\_opened\*       … open-backup.ps1 -Keep が作る(いまの形)
    <置き場>\<世代>\opened-*  … 以前の形。控えの隣に作っていた

  中身は .env と config/*.local.php と DB の全ダンプ。
  「用が済んだら消してください」と表示するだけの作りでは、2度とも残った。
  今すぐ使っているかもしれないので、作ってすぐのものには触らない。
#>
function Remove-KmOpenedLeftovers {
    param(
        [Parameter(Mandatory)][string]$BackupRoot,
        [int]$OlderThanHours = 24
    )

    $cutoff = (Get-Date).AddHours(-$OlderThanHours)
    $targets = @()
    $openedRoot = Join-Path $BackupRoot '_opened'
    if (Test-Path -LiteralPath $openedRoot) {
        $targets += @(Get-ChildItem -LiteralPath $openedRoot -Directory -Force -ErrorAction SilentlyContinue)
    }
    $targets += @(Get-ChildItem -LiteralPath $BackupRoot -Directory -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -ne '_opened' } |
        ForEach-Object { Get-ChildItem -LiteralPath $_.FullName -Directory -Force -Filter 'opened-*' -ErrorAction SilentlyContinue })

    foreach ($dir in $targets) {
        if ($dir.LastWriteTime -lt $cutoff) {
            Write-Host "  開いた平文を削除: $($dir.FullName.Substring($BackupRoot.Length).TrimStart('\'))"
            Remove-Item -LiteralPath $dir.FullName -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}

<#
  世代を整理する。**ホストごとに $Keep 世代ずつ残す。**

  名前の降順で上から $Keep 個、という形にはしない ——
  先頭にホスト名が付いている以上それでは並ばず、
  **あるホストの最新を消して別のホストの古いものを残す**ことが起こりうる。
  消す方向の間違いは取り返しがつかない。

  `_` で始まるフォルダ(`_opened` など)は世代ではないので数えない。
  最後に、24時間より古い開いた平文を片付ける。
#>
function Invoke-KmBackupRotate {
    param(
        [Parameter(Mandatory)][string]$BackupRoot,
        [int]$Keep = 7
    )

    $sets = Get-ChildItem -LiteralPath $BackupRoot -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -notlike '_*' }

    <#
      **空の世代を先に片付ける。**

      取得が途中で失敗すると、保存先フォルダだけが作られて中身が無いまま残る
      (実際に2つ残った。2026-09-07)。ここはフォルダの**数**で世代を数えるので、
      **空の失敗跡が本物の世代を1つずつ押し出す** ——
      「7世代あるはず」が「5世代しかない」に静かに変わり、
      **消す方向の間違いなので気づいたときには戻せない。**

      中身が1つも無いフォルダは、残しても復元に使えない。数える前に落とす。

      **ただし作ってから 2 時間以内のものは消さない。** 別の控えが**いま取っている最中**の保存先も空だから。
      2026-09-18 02:00、週次のタスクと手で流した控えが重なり、先に終わった方がもう一方の保存先を
      「空の世代」として消し、後の方が「取得に失敗しました」で落ちて失敗の知らせまで出た。
      (同時に走らないよう backup-data.ps1 でロックも取るが、ここでも消す側を慎重にしておく)
    #>
    $freshLimit = (Get-Date).AddHours(-2)
    foreach ($dir in $sets) {
        if ($dir.CreationTime -gt $freshLimit) { continue }
        if (@(Get-ChildItem $dir.FullName -Recurse -File -Force -ErrorAction SilentlyContinue).Count -eq 0) {
            Write-Host "  空の世代を削除: $($dir.Name)"
            Remove-Item $dir.FullName -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
    $sets = Get-ChildItem -LiteralPath $BackupRoot -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -notlike '_*' }

    foreach ($group in ($sets | Group-Object { ($_.Name -replace '-?\d{8}-\d{6}$', '') })) {
        $ordered = $group.Group | Sort-Object Name -Descending
        if ($ordered.Count -le $Keep) { continue }
        $ordered | Select-Object -Skip $Keep | ForEach-Object {
            Write-Host "  古い世代を削除: $($_.Name)"
            Remove-Item -LiteralPath $_.FullName -Recurse -Force
        }
    }

    Remove-KmOpenedLeftovers -BackupRoot $BackupRoot
}
