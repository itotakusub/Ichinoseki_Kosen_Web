<#
.SYNOPSIS
  配備・バックアップ・復元の3スクリプトが共有する SSH の接続部分。

.DESCRIPTION
  ## なぜ切り出したか

  以前は3つのスクリプトがそれぞれ `ssh -o BatchMode=yes` を直接呼んでいた。
  **BatchMode=yes は「パスフレーズを聞かない」だけでなく、初回接続のホスト鍵確認も拒否する。**
  そのため *まだ known_hosts に無いホスト* —— つまり新しく建てた VPS —— に対しては

      Host key verification failed.        (終了コード 255)

  としか出ずに止まる。**何をすれば直るのかがどこにも出ない。**
  実際、テストサーバーへの復元がこれで失敗した。

  BatchMode 自体は捨てない。外すと、鍵が通らないときに
  **パスワード入力待ちで無言のまま固まる**(自動実行では最悪の壊れ方)。
  そこで「BatchMode は維持したまま、**繋ぐ前に確かめて、駄目な理由を日本語で言う**」に変えた。

  ## 繋ぐ前に見るもの(Assert-KmSshReady)

  | 見るもの | 駄目なときに出すもの |
  |---|---|
  | 秘密鍵がある | パスを間違えていないか。`.pub` を渡していないか |
  | 鍵にパスフレーズが無い | ssh-agent に預ける手順、または -Interactive |
  | **ホスト鍵が known_hosts にある** | **指紋を見せて -AcceptHostKey を促す** |
  | 認証が通る | 公開鍵が authorized_keys にあるか、ユーザー名は合っているか |

  ## ホスト鍵の受け入れについて

  `-AcceptHostKey` は **TOFU(最初に見たものを信じる)** であり、
  経路上に割り込まれていれば偽の鍵を覚えてしまう。だから黙って受け入れず、
  **指紋を表示してから**登録する。VPS 業者のコンソールに出る指紋と見比べれば、
  割り込みを検出できる。見比べないなら、それは「LAN 内なら大丈夫だろう」と
  同じ強さの仮定でしかない —— そのことも画面に書く。

  ## 改行コードについて(既知の罠)

  **PowerShell はネイティブコマンドへパイプするとき行末を CRLF に戻す。**
  こちら側で LF に正規化しても無意味で、CR が各行末に残り、行の最後の引数にくっつく。
  過去に `Unknown database 'Kosen_map '` や `tar: uploads\r` という形で3回踏んだ。
  受け取る側で落とすしかない —— Invoke-KmSshStdin がそれをやる。
#>

# **Set-StrictMode はここでは掛けない。** dot-source した側のスコープにも効いてしまい、
# 既に実績のある backup-data.ps1 の挙動を変えかねないため。

# Initialize-KmSsh で埋める接続情報
$script:KmSsh = @{
    HostName    = ''
    User        = ''
    KeyPath     = ''
    Port        = 22
    Interactive = $false
    Ready       = $false
}

function Initialize-KmSsh {
    param(
        [Parameter(Mandatory)][string]$HostName,
        [Parameter(Mandatory)][string]$User,
        [Parameter(Mandatory)][string]$KeyPath,
        [int]$Port = 22,
        # BatchMode を外す。鍵にパスフレーズがある / パスワード認証しか無いときだけ
        [switch]$Interactive,
        <#
          **控え専用の鍵(ssh-backup-gate.sh の門番付き)で繋ぐ**(2026-09-17)。
          門番はシェルを渡さないので、接続の確認は `ping`、取り出しは旧来の scp(`-O`)になる。
        #>
        [switch]$Gated
    )
    $script:KmSsh = @{
        HostName    = $HostName
        User        = $User
        KeyPath     = [Environment]::ExpandEnvironmentVariables($KeyPath)
        Port        = $Port
        Interactive = [bool]$Interactive
        Gated       = [bool]$Gated
        Ready       = $false
    }
}

function Get-KmSshTarget { "$($script:KmSsh.User)@$($script:KmSsh.HostName)" }

# ssh / scp に共通で渡す引数。scp はポート指定が -P、ssh は -p なので呼ぶ側で足す
function Get-KmSshArgs {
    <#
      **ConnectTimeout は「つながるまで」しか見ない。** つながった後に回線が死ぬと、
      ssh / scp は相手を待ち続けて戻ってこない。ServerAlive で15秒ごとに相手を確かめ、
      4回続けて返事が無ければ(約1分)切る。
    #>
    $a = @('-i', $script:KmSsh.KeyPath, '-o', 'ConnectTimeout=10', '-o', 'IdentitiesOnly=yes',
        '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=4')
    # **BatchMode は既定で維持する。** 外すと認証に失敗したとき無言で入力待ちになる
    if (-not $script:KmSsh.Interactive) { $a += @('-o', 'BatchMode=yes') }
    return $a
}

<#
  known_hosts に載っているかを見る。ssh-keygen -F は見つかれば 0 を返す。
  既定ポート以外は "[host]:port" という形で記録されるので、それに合わせる。
#>
function Test-KmHostKeyKnown {
    $name = if ($script:KmSsh.Port -eq 22) { $script:KmSsh.HostName } else { "[$($script:KmSsh.HostName)]:$($script:KmSsh.Port)" }
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        & ssh-keygen -F $name 2>&1 | Out-Null
        return ($LASTEXITCODE -eq 0)
    } finally { $ErrorActionPreference = $prev }
}

# known_hosts に載っている名前を、指紋の形にして返す
function Get-KmHostKeyFingerprints {
    $name = if ($script:KmSsh.Port -eq 22) { $script:KmSsh.HostName } else { "[$($script:KmSsh.HostName)]:$($script:KmSsh.Port)" }
    $tmp = New-TemporaryFile
    try {
        $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        try {
            & ssh-keygen -F $name 2>$null | Set-Content -Path $tmp -Encoding ascii
            return @(& ssh-keygen -lf $tmp 2>$null)
        } finally { $ErrorActionPreference = $prev }
    } finally { Remove-Item $tmp -Force -ErrorAction SilentlyContinue }
}

<#
  ホスト鍵を取り込む。**指紋を見せてから**登録するのが本筋。

  ## ssh-keyscan が使えないことがある(実測)

  この PC の OpenSSH は 9.5p2 で、その `ssh-keyscan` は新しいサーバーと
  鍵交換方式を合意できずに落ちる:

      choose_kex: unsupported KEX method sntrup761x25519-sha512@openssh.com

  **同じ版の `ssh` 本体は、同じ相手に問題なく繋がる。** keyscan だけが弱い。
  Ubuntu 24.04(OpenSSH 9.6)も同じ方式を提示するので、keyscan だけに頼ると
  **実際の VPS でこの経路が必ず失敗する**(テスト用の Debian 13 / OpenSSH 10 で確認した)。

  そこで二段構えにする:

    1. keyscan が通れば、**繋ぐ前に**指紋を見せて登録する(こちらが望ましい)
    2. 駄目なら ssh の accept-new に任せ、**登録された指紋を後から見せる**。
       この場合「見比べる前に信じてしまっている」ので、そのことを隠さず書く
#>
function Add-KmHostKey {
    $h = $script:KmSsh.HostName
    $knownHosts = Join-Path $env:USERPROFILE '.ssh\known_hosts'
    if (-not (Test-Path (Split-Path $knownHosts))) {
        New-Item -ItemType Directory -Path (Split-Path $knownHosts) -Force | Out-Null
    }

    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { $keys = & ssh-keyscan -p $script:KmSsh.Port -t ed25519,rsa $h 2>$null }
    finally { $ErrorActionPreference = $prev }
    $keys = @($keys | Where-Object { $_ -and $_ -notmatch '^\s*#' })

    if ($keys.Count -gt 0) {
        Write-Host ''
        Write-Host "  $h のホスト鍵の指紋:"
        foreach ($k in $keys) {
            $tmp = New-TemporaryFile
            try {
                Set-Content -Path $tmp -Value $k -Encoding ascii
                $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
                try { Write-Host "    $(& ssh-keygen -lf $tmp 2>&1)" } finally { $ErrorActionPreference = $prev }
            } finally { Remove-Item $tmp -Force -ErrorAction SilentlyContinue }
        }
        Add-Content -Path $knownHosts -Value ($keys -join "`n")
        Write-Host ''
        Write-Host '  **この指紋を VPS 業者のコンソールに出るものと見比べてください。**'
        Write-Host '  見比べずに登録するのは「経路上に誰も居ないだろう」と仮定することと同じです。'
        Write-Host "  known_hosts に登録しました: $knownHosts"
        return
    }

    # --- 2段目: ssh 本体の accept-new に任せる ---
    Write-Host '  (ssh-keyscan が使えないため、ssh 本体の accept-new で取り込みます)'
    $sshArgs = @('-i', $script:KmSsh.KeyPath, '-o', 'ConnectTimeout=10', '-o', 'IdentitiesOnly=yes',
                 '-o', 'StrictHostKeyChecking=accept-new', '-p', "$($script:KmSsh.Port)")
    if (-not $script:KmSsh.Interactive) { $sshArgs += @('-o', 'BatchMode=yes') }

    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        $out = & ssh @sshArgs (Get-KmSshTarget) 'true' 2>&1
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $prev }

    if (-not (Test-KmHostKeyKnown)) {
        throw @"
ホスト鍵を取得できませんでした: $h : $($script:KmSsh.Port)
$out
  SSH が動いていないか、そのポートに届いていません。次を確かめてください:
    - VPS が起動しているか
    - 業者のパケットフィルタで SSH ポートが開いているか
    - ポート番号(-Port)が合っているか
"@
    }

    Write-Host ''
    Write-Host "  known_hosts に登録しました。$h のホスト鍵の指紋:"
    foreach ($fp in (Get-KmHostKeyFingerprints)) { Write-Host "    $fp" }
    Write-Host ''
    Write-Host '  **この経路では、見比べる前に登録してしまっています。**'
    Write-Host '  いま業者のコンソールに出る指紋と見比べてください。違っていたら:'
    # 既定ポート以外は [host]:port という名前で記録されている
    $rName = if ($script:KmSsh.Port -eq 22) { $h } else { "[$h]:$($script:KmSsh.Port)" }
    Write-Host "    ssh-keygen -R `"$rName`""
    if ($code -ne 0) { Write-Host "  (接続自体は exit $code でした。認証は次の段で確かめます)" }
}

<#
  秘密鍵にパスフレーズが掛かっているか。**鍵ファイルを自分で読んで決める。**

  ## なぜ ssh-keygen に聞かないのか

  `ssh-keygen -y -f <鍵> -P ''` が定石だが、**Windows PowerShell 5.1 は
  空文字の引数を外部コマンドへ渡すときに落とす。** ssh-keygen からは

      option requires an argument -- P

  が返り、**パスフレーズの無い鍵でも必ず exit 1** になる。
  つまり「掛かっている」と誤判定する —— 実際にこれで配備が止まった。
  (PowerShell 7 では空文字がそのまま渡るので**再現しない**。
  同じスクリプトが起動した shell によって違う答えを出す、という形になる)

  `-P '""'` に戻すのも駄目。**パスフレーズが `""` の2文字である鍵を
  「無し」と判定してしまう**(`ssh-keygen -N '""'` はそういう鍵を作る)。

  ## 何を見ているか

  | 形式 | 見るところ |
  |---|---|
  | OpenSSH 新形式 | `openssh-key-v1` の直後の **ciphername**。`none` なら平文 |
  | 旧 PEM | `Proc-Type: 4,ENCRYPTED` / `DEK-Info:` の有無 |

  読めない・知らない形式のときは **ssh-keygen へ落とす**(`cmd` 経由なら
  空の引数が正しく渡る)。**分からないものを「大丈夫」と扱わない。**
#>
function Test-KmKeyEncrypted {
    param([Parameter(Mandatory)][string]$Path)

    $text = $null
    try { $text = [IO.File]::ReadAllText($Path) } catch { $text = $null }

    if ($text) {
        # 旧 PEM(-----BEGIN RSA PRIVATE KEY----- など)
        if ($text -match '(?m)^\s*(Proc-Type:\s*4,ENCRYPTED|DEK-Info:)') { return $true }

        if ($text -match '-----BEGIN OPENSSH PRIVATE KEY-----') {
            try {
                $body = ($text -split "`r?`n" | Where-Object { $_ -notmatch '^-----' }) -join ''
                $bytes = [Convert]::FromBase64String($body.Trim())

                <#
                  形式(PROTOCOL.key):
                    "openssh-key-v1\0"   15 バイト
                    string ciphername    4バイトの長さ(ビッグエンディアン)＋本体
                  ciphername が "none" なら暗号化されていない。
                #>
                $magic = [Text.Encoding]::ASCII.GetString($bytes, 0, 15)
                if ($magic -eq "openssh-key-v1`0") {
                    $len = [int]$bytes[15] * 16777216 + [int]$bytes[16] * 65536 +
                           [int]$bytes[17] * 256 + [int]$bytes[18]
                    if ($len -gt 0 -and $len -lt 64 -and (19 + $len) -le $bytes.Length) {
                        $cipher = [Text.Encoding]::ASCII.GetString($bytes, 19, $len)
                        return ($cipher -ne 'none')
                    }
                }
            } catch {
                # 壊れている / 想定外。下の ssh-keygen へ落とす
            }
        }
    }

    <#
      読めなかったときの保険。**cmd を挟む** —— cmd は `""` を
      「空の引数」として正しく渡すので、5.1 でも意図どおりに動く。
      それでも判定できなければ「掛かっている」側に倒す
      (分からないものを大丈夫と言わない)。
    #>
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        & cmd /c "ssh-keygen -y -f `"$Path`" -P `"`" >NUL 2>NUL"
        return ($LASTEXITCODE -ne 0)
    } catch {
        return $true
    } finally { $ErrorActionPreference = $prev }
}

<#
  繋ぐ前の点検。**失敗する理由を、直し方と一緒に出す**のがこの関数の役目。
#>
function Assert-KmSshReady {
    param([switch]$AcceptHostKey)

    if ($script:KmSsh.Ready) { return }

    $key = $script:KmSsh.KeyPath
    $target = Get-KmSshTarget

    # --- 1. 秘密鍵 ---
    if (-not (Test-Path $key)) {
        $hint = if (Test-Path "$key.pub") { "`n  ($key.pub はあります。**渡すのは .pub の付かない方**です)" } else { '' }
        throw "秘密鍵が見つかりません: $key$hint"
    }
    if ($key -like '*.pub') {
        throw "公開鍵を指定しています: $key`n  **.pub の付かない方**(秘密鍵)を -KeyPath に渡してください。"
    }

    <#
      --- 2. パスフレーズ。BatchMode のままだと解錠できずに失敗する ---

      判定は [Test-KmKeyEncrypted]。**鍵ファイルを自分で読む。**
      ssh-keygen に空のパスフレーズを渡す手は、**Windows PowerShell 5.1 で成立しない**
      (下記)。過去に2度ここで誤判定している:

        1. `-P '""'` … パスフレーズが `""` の2文字である鍵まで「無し」と判定した
        2. `-P ''`  … 5.1 が**空の引数を落とす**ので、
                      `option requires an argument -- P` で必ず失敗し、
                      **パスフレーズの無い鍵まで「掛かっている」と判定した**

      どちらも「鍵は正しいのに落ちる」形で、原因から一番遠いところに現れる。
      外部コマンドの引数渡しに頼るのをやめ、**中身を見て決める。**
    #>
    if (-not $script:KmSsh.Interactive) {
        $locked = Test-KmKeyEncrypted -Path $key

        if ($locked) {
            $agentHasKeys = $false
            $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
            try { & ssh-add -l 2>&1 | Out-Null; $agentHasKeys = ($LASTEXITCODE -eq 0) }
            finally { $ErrorActionPreference = $prev }

            if (-not $agentHasKeys) {
                throw @"
鍵 $key にパスフレーズが掛かっています。
  BatchMode ではパスフレーズを聞けないので、このままでは繋がりません。次のどちらかを:

    A) ssh-agent に一度だけ預ける(以後この PC では自動で通る)
         Start-Service ssh-agent
         ssh-add "$key"

    B) 今回だけ対話で入力する
         -Interactive を付けて実行する
"@
            }
        }
    }

    # --- 3. ホスト鍵。**テストサーバーへの復元が失敗した原因はここ** ---
    if (-not (Test-KmHostKeyKnown)) {
        if ($AcceptHostKey) {
            Write-Host "  $($script:KmSsh.HostName) は初めて繋ぐホストです。ホスト鍵を取り込みます。"
            Add-KmHostKey
        }
        else {
            throw @"
$($script:KmSsh.HostName) のホスト鍵が known_hosts にありません(初めて繋ぐホスト)。
  BatchMode では確認を聞けないため、ssh は "Host key verification failed." で止まります。

  次のどちらかで登録してください:

    A) このスクリプトに任せる(指紋を表示してから登録します)
         同じコマンドに -AcceptHostKey を付けて実行する

    B) 手で一度繋いで確認する(**指紋を業者のコンソールと見比べられる**ので、より確実)
         ssh -i "$($script:KmSsh.KeyPath)" -p $($script:KmSsh.Port) $target
"@
        }
    }

    # --- 4. 実際に通るか ---
    $sshArgs = @(Get-KmSshArgs) + @('-p', "$($script:KmSsh.Port)")
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        # 門番付きの鍵は任意のコマンドを通さないので、門番の ping で確かめる(同じく km-ok を返す)
        $probe = if ($script:KmSsh.Gated) { 'ping' } else { 'echo km-ok' }
        $out = & ssh @sshArgs $target $probe 2>&1
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $prev }

    if ($code -ne 0 -or "$out" -notmatch 'km-ok') {
        $text = "$out"
        $why = switch -Regex ($text) {
            'Permission denied' { @"
  公開鍵が受け入れられませんでした。VPS 側で確かめてください:
    - /home/$($script:KmSsh.User)/.ssh/authorized_keys に、この鍵の公開鍵($($script:KmSsh.KeyPath).pub)の1行があるか
    - 所有者と権限:  chown -R $($script:KmSsh.User): ~/.ssh && chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys
    - ユーザー名 '$($script:KmSsh.User)' が合っているか(-User で変えられます)
    - authorized_keys に from="..." を付けた場合、**この PC の現在の IP** が含まれているか
"@ }
            'Host key verification failed' { '  ホスト鍵の確認で失敗しました。-AcceptHostKey を付けて実行してください。' }
            'Connection refused' { "  接続を拒否されました。SSH が動いていないか、ポート $($script:KmSsh.Port) が違います。" }
            'Connection timed out|Operation timed out' { '  応答がありません。業者のパケットフィルタで SSH が閉じている可能性があります。' }
            'Could not resolve|Name or service not known' { '  ホスト名を解決できません。DNS がまだ向いていないなら IP で指定してください。' }
            'REMOTE HOST IDENTIFICATION HAS CHANGED' { @"
  **ホスト鍵が以前と変わっています。** VPS を作り直したなら正常ですが、
  そうでないなら経路上で割り込まれている可能性があります。作り直した場合は:
    ssh-keygen -R "$($script:KmSsh.HostName)"
"@ }
            default { '' }
        }
        throw "$target へ接続できませんでした (exit $code)。`n$text`n$why"
    }

    $script:KmSsh.Ready = $true
}

# --- 実行系 -------------------------------------------------------------

function Invoke-KmSsh {
    param([Parameter(Mandatory)][string]$Command, [switch]$AllowFailure)
    $sshArgs = @(Get-KmSshArgs) + @('-p', "$($script:KmSsh.Port)")
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    <#
      **向こうの出力は UTF-8 だと言い切って読む。**

      PowerShell は外部コマンドの出力を `[Console]::OutputEncoding` で解釈する。
      日本語環境の既定は CP932 なので、UTF-8 のバイト列がそのまま化ける ——
      `docker compose ps` の `…` が `窶ｦ` になって出ていた。

      **化けるのは表示だけ**なので害は小さいが、
      「文字化けしている出力」は読まれなくなる。本当の異常もそこに紛れる。
    #>
    $prevConsole = [Console]::OutputEncoding
    try {
        try { [Console]::OutputEncoding = New-Object Text.UTF8Encoding $false } catch { }
        $out = & ssh @sshArgs (Get-KmSshTarget) $Command 2>&1
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $prev
        try { [Console]::OutputEncoding = $prevConsole } catch { }
    }
    if ($code -ne 0 -and -not $AllowFailure) {
        throw "リモートで失敗しました (exit $code): $Command`n$out"
    }
    return $out
}

<#
  スクリプトを**標準入力から**流し込んで実行する。

  引数として渡さないのは、リモート側のシェル(Windows なら cmd)が
  `>` や `&` を横取りするため。

  ## 引用符を1つも使わない

  以前はこう組み立てていた:

      sh -c "tr -d '\r' | sh"

  **Windows PowerShell 5.1 は、この二重引用符を外部コマンドへ正しく渡せない。**
  引用が崩れて `sh -c tr -d '\r' | sh` になり、
  リモートでは `sh -c tr` が走って

      tr: missing operand

  で終わる —— **スクリプトが1行も実行されないのに、出力があるので
  「動いたが気になる点がある」ように見える**(2026-09-03 の配備で実際に起きた)。
  PowerShell 7 では再現しない。**同じスクリプトが shell によって違う結果を出す。**

  そこで:

    1. リモートへ渡すのは `sh` だけ(引用符も空白の入った引数も無い)
    2. CR は**送る前にこちらで落とす。** 向こうで tr を通さない
    3. 標準入力は**パイプ演算子を使わず**、プロセスへ直接バイトで書く ——
       パイプ越しだと PowerShell が改行を作り直しうる(それが tr を要った理由)
#>
function Invoke-KmSshStdin {
    param(
        [Parameter(Mandatory)][string]$Script,
        # 中身を実行する処理系。sh / bash
        [string]$Shell = 'sh',
        # その前に付けるもの。例: 'sudo -n'、'docker exec -i km-mariadb'
        [string]$Prefix = '',
        <#
          **待つ時間の上限(秒)。** 過ぎたら ssh を止めて throw する(-AllowFailure でも)。
          回線が生きたまま向こうが返ってこない場合は ServerAlive では切れないので、ここで切る。
          長くかかると分かっている処理だけ、呼ぶ側で延ばす。
        #>
        [int]$TimeoutSec = 600,
        [switch]$AllowFailure
    )

    <#
      リモートで実行する文字列。**空白で区切った単語だけ**にする。
      `$Prefix` は `docker exec -i km-mariadb` のような形で、これも引用符を含まない。
    #>
    $remote = "$Prefix $Shell".Trim()

    # **CR をここで落とす。** 向こうで tr を通す必要が無くなる
    $payload = $Script -replace "`r`n", "`n" -replace "`r", "`n"
    if (-not $payload.EndsWith("`n")) { $payload += "`n" }

    $sshArgs = @(Get-KmSshArgs) + @('-p', "$($script:KmSsh.Port)", (Get-KmSshTarget), $remote)

    <#
      **パイプではなくプロセスの標準入力へ直接書く。**

      `$payload | & ssh ...` だと、PowerShell が文字列を「行」として書き出す過程で
      改行を作り直す。5.1 では `$OutputEncoding` が ASCII なので**日本語も
      丸ごと `?` になる**。どちらもこのファイルの外からは見えない壊れ方で、
      「送ったスクリプトが向こうで動かない」としか分からない。

      ここでバイト列を決めてしまえば、版にも `$OutputEncoding` にも左右されない。
    #>
    <#
      **`ArgumentList` は使えない。** あれは .NET Core からのもので、
      Windows PowerShell 5.1(.NET Framework)には**存在しない** ——
      いま直そうとしているのと同じ「shell で結果が変わる」罠。
      両方で動く `Arguments`(1本の文字列)に、自分で引用を付けて渡す。

      引用の規則は Windows のコマンドラインのもの:
      空白を含む引数は `"` で囲み、中の `"` は `\"` に、
      閉じ引用符の直前の `\` は倍にする。
      **ここへ渡すのは ssh の選択肢とホスト名と `sh` だけ**なので実際には
      素通りするが、鍵のパスに空白が入る場合(`C:\Users\...\My Keys\...`)に効く。
    #>
    $quoted = foreach ($arg in $sshArgs) {
        if ($arg -match '[\s"]') {
            '"' + ($arg -replace '(\\*)"', '$1$1\"' -replace '(\\+)$', '$1$1') + '"'
        } else {
            $arg
        }
    }

    $startInfo = New-Object Diagnostics.ProcessStartInfo
    $startInfo.FileName = 'ssh'
    $startInfo.Arguments = ($quoted -join ' ')
    $startInfo.UseShellExecute = $false
    $startInfo.RedirectStandardInput = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $utf8 = New-Object Text.UTF8Encoding $false
    $startInfo.StandardOutputEncoding = $utf8
    $startInfo.StandardErrorEncoding = $utf8

    <#
      **子プロセスの標準入力に BOM を付けさせない。**

      .NET Framework(Windows PowerShell 5.1)は `Process.StandardInput` を
      `Console.InputEncoding` で作る。既定のそれは **BOM 付きの UTF-8** なので、
      標準入力に触れた時点で先頭に `EF BB BF` が書かれる(実測)。

      スクリプトの先頭に BOM が入ると、向こうの `sh` は1行目をコマンドとして
      読もうとして失敗する —— **見えない3バイト**なので、出力からは
      何が起きたのか分からない。PowerShell 7 では起きない。

      ここで BOM 無しの UTF-8 に差し替え、終わったら戻す。
    #>
    $previousInputEncoding = $null
    try {
        if ([Console]::InputEncoding.GetPreamble().Length -gt 0) {
            $previousInputEncoding = [Console]::InputEncoding
            [Console]::InputEncoding = $utf8
        }
    } catch {
        # 変えられない場面(標準入力が塞がれている等)もある。**黙って進めない**
        throw @"
標準入力の符号化を BOM 無しにできませんでした: $($_.Exception.Message)
  このまま送ると、スクリプトの先頭に見えない3バイト(BOM)が付き、
  リモートの sh が1行目を読めません。
  PowerShell 7 (pwsh) から実行すると、この問題は起きません。
"@
    }

    $process = [Diagnostics.Process]::Start($startInfo)
    try {
        <#
          **StreamWriter を挟まず、バイトをそのまま書く。**

          `New-Object IO.StreamWriter($stream, $encoding)` で書くと、
          5.1 では**先頭に BOM(EF BB BF)が付いた**(実測: 28 バイトのはずが 31)。
          スクリプトの先頭に BOM が入ると、向こうの `sh` は最初の1行を
          コマンドとして読めず落ちる —— しかも**見えない3バイト**なので、
          出力からは何が起きたのか分からない。

          自分で `GetBytes` すれば、符号化器の都合が入り込む余地が無い。
        #>
        $bytes = $utf8.GetBytes($payload)
        $process.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
        $process.StandardInput.BaseStream.Flush()
        $process.StandardInput.Close()

        <#
          **標準出力と標準エラーを同時に読み、待つ時間に上限を置く。**

          以前は「標準出力を読み切ってから標準エラーを読む」だった。向こうが
          **標準エラーへ大量に書くと**(tar が全ファイルに `Cannot open: File exists` を
          出したとき)、ssh.exe の標準エラーのパイプが埋まって ssh.exe が書けなくなり、
          **標準出力はいつまでも閉じない** —— 双方が相手を待って止まる。
          2026-09-13 の配備で、転送のあと何も表示せずに止まったのはこれ
          (ssh を止めてから出てきたエラーは、途中で切れていた)。

          先に WaitForExit だけするのも駄目(出力が多いとパイプが埋まって同じことになる)。
          両方を非同期で読み始めてから、上限つきで終わりを待つ。
        #>
        $timedOut = $false
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        if (-not $process.WaitForExit($TimeoutSec * 1000)) {
            $timedOut = $true
            try { $process.Kill() } catch { }
            [void]$process.WaitForExit(5000)
        }
        # ssh.exe が終わればパイプは閉じるので、読み取りもすぐ返る
        $stdout = if ($stdoutTask.Wait(5000)) { $stdoutTask.Result } else { '' }
        $stderr = if ($stderrTask.Wait(5000)) { $stderrTask.Result } else { '' }
        $code = if ($timedOut) { -1 } else { $process.ExitCode }
    } finally {
        $process.Dispose()
        if ($null -ne $previousInputEncoding) {
            try { [Console]::InputEncoding = $previousInputEncoding } catch { }
        }
    }

    $out = @()
    foreach ($line in (($stdout + $stderr) -split "`r?`n")) {
        if ($line -ne '') { $out += $line }
    }

    # 出力が長いと本当の原因が画面から流れる(tar は1ファイルごとに1行出す)。頭だけ見せる
    $shown = if ($out.Count -gt 30) { @($out | Select-Object -First 30) + "…(ほか $($out.Count - 30) 行)" } else { $out }

    if ($timedOut) {
        throw "リモートスクリプトが $TimeoutSec 秒で終わらなかったので、ssh を止めました。向こうでは処理が残っているかもしれません。`n$($shown -join "`n")"
    }
    if ($code -ne 0 -and -not $AllowFailure) {
        throw "リモートスクリプトが失敗しました (exit $code):`n$($shown -join "`n")"
    }

    return $out
}

function Copy-KmTo {
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$Destination, [switch]$Recurse)
    $a = @(Get-KmSshArgs) + @('-P', "$($script:KmSsh.Port)")
    if ($Recurse) { $a += '-r' }
    & scp @a $Path "$(Get-KmSshTarget):$Destination"
    if ($LASTEXITCODE -ne 0) { throw "転送に失敗しました: $Path -> $Destination" }
}

function Copy-KmFrom {
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$Destination, [switch]$Recurse, [switch]$AllowFailure)
    $a = @(Get-KmSshArgs) + @('-P', "$($script:KmSsh.Port)")
    if ($Recurse) { $a += '-r' }
    # 門番は SFTP(今の scp の既定)を通さない。旧来の scp の型(-O)で取る
    if ($script:KmSsh.Gated) { $a += '-O' }
    & scp @a "$(Get-KmSshTarget):$Path" $Destination
    if ($LASTEXITCODE -ne 0 -and -not $AllowFailure) { throw "取得に失敗しました: $Path" }
    return ($LASTEXITCODE -eq 0)
}

<#
  ホスト側の server/ に相当するフォルダを、**コンテナ名に頼らずに**割り出す。

  ## なぜ1箇所にまとめたか

  同じ処理が deploy-to-host.ps1 / host-setup.ps1 / backup-data.ps1 の3つに
  写してあった。**同じ判断を3つ持つと、片方だけ直される。**
  実際 2026-09-07 にコンテナ名を `dev-*` から `km-*` へ改めたとき、
  3箇所すべてを直す必要があった —— 1つ忘れれば、そこだけ静かに壊れる。

  ## なぜ名前で探さないか

  以前は `docker inspect … km-php-apache` と**名前を直に書いて**いた。
  名前を変えた瞬間に落ちるうえ、ここが動くのは「-RemotePath を省いたとき」だけなので
  **普段は誰も踏まず、省いた人のところでだけ壊れる。**

  compose が全コンテナに付ける札で引く。`com.docker.compose.service=web` は
  **サービス名**で、コンテナ名を変えてもプロジェクト名を変えても動かない。

  ## なぜ2回に分けるか

  `docker inspect -f … $(docker ps -q …)` と書きたくなるが、
  **入れ子のコマンド置換は sh と cmd で書き方が違う。**
  相手のシェルを問わないよう、名前を取ってから改めて inspect する。
#>
function Get-KmRemoteProjectPath {
    param([switch]$AllowFailure)

    $found = "$(Invoke-KmSsh -Command 'docker ps --filter label=com.docker.compose.service=web --format "{{.Names}}"' -AllowFailure)"
    $name = ($found -split "`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' } | Select-Object -First 1)

    if (-not $name) {
        if ($AllowFailure) { return '' }
        throw "web コンテナが見つかりませんでした(compose の札で探しています)。`n`n  止まっているか、compose で起動していない可能性があります。`n  -RemotePath でホスト側の server/ を直接指定してください。"
    }

    $tpl = 'docker inspect -f "{{range .Mounts}}{{if eq .Destination \"/var/www/html\"}}{{.Source}}{{end}}{{end}}" '
    $src = "$(Invoke-KmSsh -Command ($tpl + $name) -AllowFailure)".Trim()
    if ($src -eq '') {
        if ($AllowFailure) { return '' }
        throw "$name の /var/www/html マウント元が取れませんでした。-RemotePath で明示してください。"
    }

    <#
      Linux のパスを Windows 風に潰さない(Split-Path は `/` を分離してくれない)。

      **相手のシェルは、パスの形で見分ける。** 以前は deploy-to-host.ps1 にしか無い関数で
      判定しており、deploy-to-host.ps1 から呼ばれたときだけ動いていた(呼んだ側の関数が見えるため)。
      host-setup.ps1 を**単独で**走らせると「その関数が認識されません」で落ちていた
      (2026-09-14、取扱説明書のノートブックから叩いて発覚)。
    #>
    if ($src.StartsWith('/')) { return ($src -replace '/+src/?$', '') }
    return (Split-Path -Parent ($src -replace '/', '\'))
}
