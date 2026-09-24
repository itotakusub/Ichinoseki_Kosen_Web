<#
.SYNOPSIS
来場者アプリ用のアクセスコードQRを作る。

.DESCRIPTION
アプリが読み取るのは "KOSENMAP1:<アクセスコード>" という平文の文字列で、
このスクリプトはそれをQRコードのPNGにするだけ。

**外部のQR生成サービスは使わない。** アクセスコードを他所のサーバーへ送ることになり、
そこに残ったコードで誰でも地図を落とせるようになる。依存を足さずに済むよう、
QRの符号化そのものをここに実装してある(バイトモード、型1〜10)。

エンコードする文字列は Android の normalizeAccessCode / PHP の
km_app_map_normalize_code と同じ規則(大文字化・空白とハイフンとアンダースコアの除去)で
揃えてから包む。揃っていないと、読み取れたのにサーバーが照合できない。

.PARAMETER Code
アクセスコード。プレフィックスは自動で付くので含めないこと。

.PARAMETER OutputPath
PNGの出力先。省略するとカレントディレクトリに kosenmap-qr-<コード>.png を作る。

.PARAMETER ErrorCorrection
誤り訂正レベル。会場に貼って使うなら、汚れや一部が隠れても読める Q か H を勧める。

.PARAMETER Mask
マスクパターンを固定する(0〜7)。省略時は仕様どおり減点法で自動選択する。
検証用で、通常は指定しない。

.EXAMPLE
.\scripts\new-map-qr.ps1 -Code KOSEN2026

.EXAMPLE
.\scripts\new-map-qr.ps1 -Code KOSEN2026 -ErrorCorrection H -ModuleSize 12 -ShowInConsole
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$Code,

    [string]$OutputPath,

    [ValidateSet('L', 'M', 'Q', 'H')]
    [string]$ErrorCorrection = 'M',

    [ValidateRange(1, 64)]
    [int]$ModuleSize = 8,

    [ValidateRange(0, 16)]
    [int]$QuietZone = 4,

    [ValidateRange(-1, 7)]
    [int]$Mask = -1,

    [switch]$ShowInConsole,

    # 文字列をそのまま符号化する(プレフィックスも正規化も付けない)。検証用。
    [switch]$Raw,

    # PNGを書かず、モジュールの並びを "0101..." の行として出す。検証用。
    [switch]$MatrixOnly
)

$ErrorActionPreference = 'Stop'

# ---------------------------------------------------------------- 仕様の表

# 型ごとの [誤り訂正符号語数/ブロック, グループ1のブロック数, グループ1のデータ符号語数,
#           グループ2のブロック数, グループ2のデータ符号語数]
$script:QrBlocks = @{
    1  = @{ L = @(7, 1, 19, 0, 0);    M = @(10, 1, 16, 0, 0);   Q = @(13, 1, 13, 0, 0);   H = @(17, 1, 9, 0, 0) }
    2  = @{ L = @(10, 1, 34, 0, 0);   M = @(16, 1, 28, 0, 0);   Q = @(22, 1, 22, 0, 0);   H = @(28, 1, 16, 0, 0) }
    3  = @{ L = @(15, 1, 55, 0, 0);   M = @(26, 1, 44, 0, 0);   Q = @(18, 2, 17, 0, 0);   H = @(22, 2, 13, 0, 0) }
    4  = @{ L = @(20, 1, 80, 0, 0);   M = @(18, 2, 32, 0, 0);   Q = @(26, 2, 24, 0, 0);   H = @(16, 4, 9, 0, 0) }
    5  = @{ L = @(26, 1, 108, 0, 0);  M = @(24, 2, 43, 0, 0);   Q = @(18, 2, 15, 2, 16);  H = @(22, 2, 11, 2, 12) }
    6  = @{ L = @(18, 2, 68, 0, 0);   M = @(16, 4, 27, 0, 0);   Q = @(24, 4, 19, 0, 0);   H = @(28, 4, 15, 0, 0) }
    7  = @{ L = @(20, 2, 78, 0, 0);   M = @(18, 4, 31, 0, 0);   Q = @(18, 2, 14, 4, 15);  H = @(26, 4, 13, 1, 14) }
    8  = @{ L = @(24, 2, 97, 0, 0);   M = @(22, 2, 38, 2, 39);  Q = @(22, 4, 18, 2, 19);  H = @(26, 4, 14, 2, 15) }
    9  = @{ L = @(30, 2, 116, 0, 0);  M = @(22, 3, 36, 2, 37);  Q = @(20, 4, 16, 4, 17);  H = @(24, 4, 12, 4, 13) }
    10 = @{ L = @(18, 2, 68, 2, 69);  M = @(26, 4, 43, 1, 44);  Q = @(24, 6, 19, 2, 20);  H = @(28, 6, 15, 2, 16) }
}

# 型ごとの位置合わせパターンの中心座標。型1には無い。
$script:QrAlignment = @{
    1 = @(); 2 = @(6, 18); 3 = @(6, 22); 4 = @(6, 26); 5 = @(6, 30)
    6 = @(6, 34); 7 = @(6, 22, 38); 8 = @(6, 24, 42); 9 = @(6, 26, 46); 10 = @(6, 28, 50)
}

# 符号語の後ろに付く余りビット。型1は0、型2〜6は7、型7〜10は0。
$script:QrRemainderBits = @{
    1 = 0; 2 = 7; 3 = 7; 4 = 7; 5 = 7; 6 = 7; 7 = 0; 8 = 0; 9 = 0; 10 = 0
}

# 形式情報に入る誤り訂正レベルの2ビット表現。数値の大小と強さの順が一致しないので表で持つ。
$script:QrEcBits = @{ L = 1; M = 0; Q = 3; H = 2 }

# ---------------------------------------------------------------- ガロア体 GF(256)

$script:GfExp = New-Object 'int[]' 512
$script:GfLog = New-Object 'int[]' 256
$value = 1
for ($i = 0; $i -lt 255; $i++) {
    $script:GfExp[$i] = $value
    $script:GfLog[$value] = $i
    $value = $value -shl 1
    if ($value -band 0x100) { $value = $value -bxor 0x11D }
}
for ($i = 255; $i -lt 512; $i++) { $script:GfExp[$i] = $script:GfExp[$i - 255] }

function Get-GfProduct {
    param([int]$A, [int]$B)
    if ($A -eq 0 -or $B -eq 0) { return 0 }
    return $script:GfExp[$script:GfLog[$A] + $script:GfLog[$B]]
}

<# 誤り訂正符号語の生成多項式。次数ぶんだけ (x - a^i) を掛け合わせる。 #>
function Get-GeneratorPolynomial {
    param([int]$Degree)
    [int[]]$poly = @(1)
    for ($i = 0; $i -lt $Degree; $i++) {
        [int[]]$next = New-Object 'int[]' ($poly.Length + 1)
        for ($j = 0; $j -lt $poly.Length; $j++) {
            $next[$j] = $next[$j] -bxor $poly[$j]
            $next[$j + 1] = $next[$j + 1] -bxor (Get-GfProduct $poly[$j] $script:GfExp[$i])
        }
        $poly = $next
    }
    return $poly
}

<# Reed-Solomon の剰余。これが誤り訂正符号語になる。 #>
function Get-ErrorCorrectionCodewords {
    param([int[]]$Data, [int]$Count)
    $generator = Get-GeneratorPolynomial -Degree $Count
    [int[]]$remainder = New-Object 'int[]' ($Data.Length + $Count)
    [Array]::Copy($Data, $remainder, $Data.Length)
    for ($i = 0; $i -lt $Data.Length; $i++) {
        $factor = $remainder[$i]
        if ($factor -eq 0) { continue }
        for ($j = 0; $j -lt $generator.Length; $j++) {
            $remainder[$i + $j] = $remainder[$i + $j] -bxor (Get-GfProduct $generator[$j] $factor)
        }
    }
    return $remainder[$Data.Length..($remainder.Length - 1)]
}

# ---------------------------------------------------------------- データ符号化

function Get-QrVersion {
    param([int]$ByteCount, [string]$Ec)
    for ($version = 1; $version -le 10; $version++) {
        $spec = $script:QrBlocks[$version][$Ec]
        $capacity = $spec[1] * $spec[2] + $spec[3] * $spec[4]
        # モード指示子4ビット + 文字数指示子(型1〜9は8ビット、型10以上は16ビット)
        $headerBits = 4 + $(if ($version -lt 10) { 8 } else { 16 })
        if ($ByteCount + [math]::Ceiling($headerBits / 8.0) -le $capacity) {
            # ヘッダを含めた正確なビット数で確かめ直す
            if ($headerBits + $ByteCount * 8 -le $capacity * 8) { return $version }
        }
    }
    throw "アクセスコードが長すぎます($ByteCount バイト)。型10(誤り訂正 $Ec)に収まりません。"
}

<# バイトモードで符号化し、パディングまで済ませた符号語列を返す。 #>
function Get-DataCodewords {
    param([byte[]]$Bytes, [int]$Version, [string]$Ec)

    $spec = $script:QrBlocks[$Version][$Ec]
    $dataCapacity = $spec[1] * $spec[2] + $spec[3] * $spec[4]

    $bits = New-Object 'System.Collections.Generic.List[int]'
    function Add-Bits {
        param($Target, [int]$Value, [int]$Length)
        for ($i = $Length - 1; $i -ge 0; $i--) { $Target.Add(($Value -shr $i) -band 1) | Out-Null }
    }

    Add-Bits $bits 0x4 4                                                   # バイトモード
    Add-Bits $bits $Bytes.Length $(if ($Version -lt 10) { 8 } else { 16 })  # 文字数
    foreach ($byte in $Bytes) { Add-Bits $bits $byte 8 }

    # 終端子は最大4ビット。容量が足りなければその分だけ短くする。
    $capacityBits = $dataCapacity * 8
    $terminator = [math]::Min(4, $capacityBits - $bits.Count)
    Add-Bits $bits 0 $terminator
    # バイト境界まで0で埋める
    if ($bits.Count % 8 -ne 0) { Add-Bits $bits 0 (8 - ($bits.Count % 8)) }

    [int[]]$codewords = New-Object 'int[]' $dataCapacity
    for ($i = 0; $i -lt $bits.Count; $i += 8) {
        $byte = 0
        for ($j = 0; $j -lt 8; $j++) { $byte = ($byte -shl 1) -bor $bits[$i + $j] }
        $codewords[$i / 8] = $byte
    }
    # 残りは 0xEC / 0x11 の繰り返しで埋める(仕様で定められた埋め草)
    $filled = [int][math]::Floor($bits.Count / 8)
    $pad = @(0xEC, 0x11)
    for ($i = $filled; $i -lt $dataCapacity; $i++) { $codewords[$i] = $pad[($i - $filled) % 2] }
    return $codewords
}

<# ブロックごとに誤り訂正を付け、仕様どおりの順に織り交ぜる。 #>
function Get-InterleavedCodewords {
    param([int[]]$Data, [int]$Version, [string]$Ec)

    $spec = $script:QrBlocks[$Version][$Ec]
    $ecPerBlock = $spec[0]
    $blocks = @()
    $offset = 0
    # キー名に Count は使えない。ハッシュテーブル自身の項目数プロパティに隠される。
    $groups = @(
        @{ BlockCount = $spec[1]; WordsPerBlock = $spec[2] }
        @{ BlockCount = $spec[3]; WordsPerBlock = $spec[4] }
    )
    foreach ($group in $groups) {
        for ($b = 0; $b -lt $group.BlockCount; $b++) {
            [int[]]$chunk = $Data[$offset..($offset + $group.WordsPerBlock - 1)]
            $offset += $group.WordsPerBlock
            $blocks += , @{ Data = $chunk; Ec = (Get-ErrorCorrectionCodewords -Data $chunk -Count $ecPerBlock) }
        }
    }

    $result = New-Object 'System.Collections.Generic.List[int]'
    $maxData = ($blocks | ForEach-Object { $_.Data.Length } | Measure-Object -Maximum).Maximum
    for ($i = 0; $i -lt $maxData; $i++) {
        foreach ($block in $blocks) { if ($i -lt $block.Data.Length) { $result.Add($block.Data[$i]) | Out-Null } }
    }
    for ($i = 0; $i -lt $ecPerBlock; $i++) {
        foreach ($block in $blocks) { $result.Add($block.Ec[$i]) | Out-Null }
    }
    return $result.ToArray()
}

# ---------------------------------------------------------------- 模様の配置

class QrCanvas {
    [int]$Size
    [bool[,]]$Modules
    [bool[,]]$IsFunction

    QrCanvas([int]$size) {
        $this.Size = $size
        $this.Modules = New-Object 'bool[,]' $size, $size
        $this.IsFunction = New-Object 'bool[,]' $size, $size
    }

    # 座標は (x=列, y=行)。
    [void] SetFunction([int]$x, [int]$y, [bool]$dark) {
        if ($x -lt 0 -or $y -lt 0 -or $x -ge $this.Size -or $y -ge $this.Size) { return }
        $this.Modules[$y, $x] = $dark
        $this.IsFunction[$y, $x] = $true
    }
}

function New-QrCanvas {
    param([int]$Version)

    $size = $Version * 4 + 17
    $canvas = [QrCanvas]::new($size)

    # **タイミングパターンを先に引く。** 行6・列6を端から端まで塗ってから、
    # 位置検出パターンで上書きさせる。逆にすると位置検出パターンの下辺・右辺が
    # 市松模様に潰れ、読み取り機が位置を掴めなくなる。
    for ($i = 0; $i -lt $size; $i++) {
        $canvas.SetFunction(6, $i, ($i % 2 -eq 0))
        $canvas.SetFunction($i, 6, ($i % 2 -eq 0))
    }

    # 位置検出パターン3つ(周囲の分離帯を含めて9x9で塗る)
    #
    # 座標の組をハッシュテーブルで持つのは読みやすさのためだけではない。
    # PowerShell は**カンマ演算子が減算より優先される**ので、`@($size - 4, 3)` は
    # `$size - @(4, 3)` と解釈されて落ちる。括弧で囲えば済むが、それを知らずに
    # 触ると再発するため、算術と配列リテラルを混ぜない形にしてある。
    $centers = @(
        @{ X = 3;         Y = 3 }
        @{ X = $size - 4; Y = 3 }
        @{ X = 3;         Y = $size - 4 }
    )
    foreach ($center in $centers) {
        for ($dy = -4; $dy -le 4; $dy++) {
            for ($dx = -4; $dx -le 4; $dx++) {
                $distance = [math]::Max([math]::Abs($dx), [math]::Abs($dy))
                $canvas.SetFunction($center.X + $dx, $center.Y + $dy, ($distance -ne 2 -and $distance -ne 4))
            }
        }
    }

    # 位置合わせパターン(位置検出パターンと重なる3隅は置かない)
    $positions = $script:QrAlignment[$Version]
    $last = $positions.Count - 1
    for ($a = 0; $a -le $last; $a++) {
        for ($b = 0; $b -le $last; $b++) {
            if (($a -eq 0 -and $b -eq 0) -or ($a -eq 0 -and $b -eq $last) -or ($a -eq $last -and $b -eq 0)) { continue }
            for ($dy = -2; $dy -le 2; $dy++) {
                for ($dx = -2; $dx -le 2; $dx++) {
                    $distance = [math]::Max([math]::Abs($dx), [math]::Abs($dy))
                    $canvas.SetFunction($positions[$a] + $dx, $positions[$b] + $dy, ($distance -ne 1))
                }
            }
        }
    }

    # 形式情報の領域を仮の値で押さえ、データを流し込む対象から外す。
    # **行8・列8を端から端まで塗ってはいけない。** その中には (6,8) と (8,6) の
    # タイミングモジュールが含まれており、潰すと位置合わせが崩れる。
    # 実際に置かれる座標だけを触るよう、本番と同じ Set-QrFormatInfo を使う。
    Set-QrFormatInfo -Canvas $canvas -Ec 'L' -Pattern 0

    # 型情報(型7以上のみ)。BCH(18,6) で誤り訂正ビットを作る。
    if ($Version -ge 7) {
        $remainder = $Version
        for ($i = 0; $i -lt 12; $i++) {
            $remainder = ($remainder -shl 1) -bxor ((($remainder -shr 11) * 0x1F25))
        }
        $bits = ($Version -shl 12) -bor $remainder
        for ($i = 0; $i -lt 18; $i++) {
            $bit = (($bits -shr $i) -band 1) -eq 1
            $a = $size - 11 + $i % 3
            # **[int] は切り捨てではなく四捨五入する。** [int](2/3) は 1 になり、
            # 型情報が別の行へ書かれて型7以上が読めなくなる。必ず Floor を使う。
            $b = [int][math]::Floor($i / 3)
            $canvas.SetFunction($a, $b, $bit)
            $canvas.SetFunction($b, $a, $bit)
        }
    }

    return $canvas
}

<# 符号語をジグザグに流し込む。列6(タイミングパターン)は飛ばす。 #>
function Set-QrData {
    param([QrCanvas]$Canvas, [int[]]$Codewords)

    $size = $Canvas.Size
    $bitIndex = 0
    $totalBits = $Codewords.Length * 8
    for ($right = $size - 1; $right -ge 1; $right -= 2) {
        if ($right -eq 6) { $right = 5 }
        for ($vertical = 0; $vertical -lt $size; $vertical++) {
            for ($j = 0; $j -lt 2; $j++) {
                $x = $right - $j
                $upward = (($right + 1) -band 2) -eq 0
                $y = $(if ($upward) { $size - 1 - $vertical } else { $vertical })
                if (-not $Canvas.IsFunction[$y, $x] -and $bitIndex -lt $totalBits) {
                    $byte = $Codewords[$bitIndex -shr 3]
                    $Canvas.Modules[$y, $x] = ((($byte -shr (7 - ($bitIndex -band 7))) -band 1) -eq 1)
                    $bitIndex++
                }
            }
        }
    }
}

function Test-MaskCondition {
    param([int]$Pattern, [int]$X, [int]$Y)
    switch ($Pattern) {
        0 { return (($X + $Y) % 2 -eq 0) }
        1 { return ($Y % 2 -eq 0) }
        2 { return ($X % 3 -eq 0) }
        3 { return ((($X + $Y) % 3) -eq 0) }
        4 { return (([math]::Floor($X / 3) + [math]::Floor($Y / 2)) % 2 -eq 0) }
        5 { return ((($X * $Y) % 2 + ($X * $Y) % 3) -eq 0) }
        6 { return (((($X * $Y) % 2 + ($X * $Y) % 3) % 2) -eq 0) }
        7 { return ((((($X + $Y) % 2) + (($X * $Y) % 3)) % 2) -eq 0) }
    }
    throw "不正なマスク番号: $Pattern"
}

function Invoke-QrMask {
    param([QrCanvas]$Canvas, [int]$Pattern)
    for ($y = 0; $y -lt $Canvas.Size; $y++) {
        for ($x = 0; $x -lt $Canvas.Size; $x++) {
            if (-not $Canvas.IsFunction[$y, $x] -and (Test-MaskCondition -Pattern $Pattern -X $x -Y $y)) {
                $Canvas.Modules[$y, $x] = -not $Canvas.Modules[$y, $x]
            }
        }
    }
}

<# 形式情報(誤り訂正レベル+マスク番号)を BCH(15,5) で符号化して2か所に置く。 #>
function Set-QrFormatInfo {
    param([QrCanvas]$Canvas, [string]$Ec, [int]$Pattern)

    $data = ($script:QrEcBits[$Ec] -shl 3) -bor $Pattern
    $remainder = $data
    for ($i = 0; $i -lt 10; $i++) {
        $remainder = ($remainder -shl 1) -bxor ((($remainder -shr 9) * 0x537))
    }
    $bits = ((($data -shl 10) -bor $remainder) -bxor 0x5412)

    $size = $Canvas.Size
    for ($i = 0; $i -le 5; $i++) { $Canvas.SetFunction(8, $i, ((($bits -shr $i) -band 1) -eq 1)) }
    $Canvas.SetFunction(8, 7, ((($bits -shr 6) -band 1) -eq 1))
    $Canvas.SetFunction(8, 8, ((($bits -shr 7) -band 1) -eq 1))
    $Canvas.SetFunction(7, 8, ((($bits -shr 8) -band 1) -eq 1))
    for ($i = 9; $i -lt 15; $i++) { $Canvas.SetFunction(14 - $i, 8, ((($bits -shr $i) -band 1) -eq 1)) }

    for ($i = 0; $i -lt 8; $i++) { $Canvas.SetFunction($size - 1 - $i, 8, ((($bits -shr $i) -band 1) -eq 1)) }
    for ($i = 8; $i -lt 15; $i++) { $Canvas.SetFunction(8, $size - 15 + $i, ((($bits -shr $i) -band 1) -eq 1)) }
    $Canvas.SetFunction(8, $size - 8, $true)
}

<# 仕様の4つの減点規則。値が小さいマスクほど読み取りやすい。 #>
function Get-QrPenalty {
    param([QrCanvas]$Canvas)

    $size = $Canvas.Size
    $score = 0

    # 規則1: 同色が5個以上連続
    for ($pass = 0; $pass -lt 2; $pass++) {
        for ($a = 0; $a -lt $size; $a++) {
            $runColor = $null
            $runLength = 0
            for ($b = 0; $b -lt $size; $b++) {
                $color = $(if ($pass -eq 0) { $Canvas.Modules[$a, $b] } else { $Canvas.Modules[$b, $a] })
                if ($color -eq $runColor) {
                    $runLength++
                    if ($runLength -eq 5) { $score += 3 } elseif ($runLength -gt 5) { $score++ }
                } else {
                    $runColor = $color
                    $runLength = 1
                }
            }
        }
    }

    # 規則2: 同色の2x2ブロック
    #
    # 添字の中の算術は必ず括弧で囲む。PowerShell はカンマ演算子を加算より優先するので、
    # `[$y, $x + 1]` は `[($y, $x) + 1]` と解釈され、3次元の添字を渡したことになって落ちる。
    for ($y = 0; $y -lt $size - 1; $y++) {
        for ($x = 0; $x -lt $size - 1; $x++) {
            $color = $Canvas.Modules[$y, $x]
            if ($color -eq $Canvas.Modules[$y, ($x + 1)] -and
                $color -eq $Canvas.Modules[($y + 1), $x] -and
                $color -eq $Canvas.Modules[($y + 1), ($x + 1)]) { $score += 3 }
        }
    }

    # 規則3: 位置検出パターンに似た並び(1:1:3:1:1 の前後に空き4)
    $patternA = @(1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0)
    $patternB = @(0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1)
    for ($pass = 0; $pass -lt 2; $pass++) {
        for ($a = 0; $a -lt $size; $a++) {
            for ($b = 0; $b -le $size - 11; $b++) {
                $matchA = $true
                $matchB = $true
                for ($k = 0; $k -lt 11; $k++) {
                    $bit = $(if ($pass -eq 0) { $Canvas.Modules[$a, ($b + $k)] } else { $Canvas.Modules[($b + $k), $a] })
                    $value = $(if ($bit) { 1 } else { 0 })
                    if ($value -ne $patternA[$k]) { $matchA = $false }
                    if ($value -ne $patternB[$k]) { $matchB = $false }
                }
                if ($matchA) { $score += 40 }
                if ($matchB) { $score += 40 }
            }
        }
    }

    # 規則4: 暗いモジュールの割合が50%から離れているほど減点
    $dark = 0
    for ($y = 0; $y -lt $size; $y++) {
        for ($x = 0; $x -lt $size; $x++) { if ($Canvas.Modules[$y, $x]) { $dark++ } }
    }
    $total = $size * $size
    # ここも切り捨てでなければならない([int] は四捨五入するので Floor を使う)。
    $k = [int][math]::Floor((([math]::Abs($dark * 20 - $total * 10) + $total - 1) / $total)) - 1
    $score += $k * 10

    return $score
}

# ---------------------------------------------------------------- 組み立て

function New-QrMatrix {
    param([string]$Text, [string]$Ec, [int]$ForcedMask = -1)

    $bytes = [System.Text.Encoding]::UTF8.GetBytes($Text)
    $version = Get-QrVersion -ByteCount $bytes.Length -Ec $Ec
    $data = Get-DataCodewords -Bytes $bytes -Version $version -Ec $Ec
    $codewords = Get-InterleavedCodewords -Data $data -Version $version -Ec $Ec

    # 余りビットは0のまま。符号語列の後ろに足りない分を0バイトとして持たせる。
    $remainderBits = $script:QrRemainderBits[$version]
    if ($remainderBits -gt 0) { $codewords += 0 }

    $best = $null
    $bestScore = [int]::MaxValue
    $candidates = $(if ($ForcedMask -ge 0) { @($ForcedMask) } else { 0..7 })
    foreach ($pattern in $candidates) {
        $canvas = New-QrCanvas -Version $version
        Set-QrData -Canvas $canvas -Codewords $codewords
        Invoke-QrMask -Canvas $canvas -Pattern $pattern
        Set-QrFormatInfo -Canvas $canvas -Ec $Ec -Pattern $pattern
        $score = $(if ($ForcedMask -ge 0) { 0 } else { Get-QrPenalty -Canvas $canvas })
        if ($score -lt $bestScore) {
            $bestScore = $score
            $best = @{ Canvas = $canvas; Mask = $pattern }
        }
    }

    return @{ Canvas = $best.Canvas; Version = $version; Mask = $best.Mask; Ec = $Ec; Text = $Text }
}

function Save-QrPng {
    param([QrCanvas]$Canvas, [string]$Path, [int]$Scale, [int]$Quiet)

    Add-Type -AssemblyName System.Drawing
    $side = ($Canvas.Size + $Quiet * 2) * $Scale
    $bitmap = New-Object System.Drawing.Bitmap($side, $side)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    try {
        $graphics.Clear([System.Drawing.Color]::White)
        $brush = [System.Drawing.Brushes]::Black
        for ($y = 0; $y -lt $Canvas.Size; $y++) {
            for ($x = 0; $x -lt $Canvas.Size; $x++) {
                if ($Canvas.Modules[$y, $x]) {
                    $graphics.FillRectangle($brush, ($x + $Quiet) * $Scale, ($y + $Quiet) * $Scale, $Scale, $Scale)
                }
            }
        }
    } finally {
        $graphics.Dispose()
    }
    $bitmap.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    $bitmap.Dispose()
}

function Show-QrInConsole {
    param([QrCanvas]$Canvas)
    # 上下2行を1文字に詰めて、コンソールの縦横比でも正方形に見えるようにする。
    $blocks = @{ '00' = ' '; '10' = "$([char]0x2580)"; '01' = "$([char]0x2584)"; '11' = "$([char]0x2588)" }
    $margin = '  '
    Write-Host ($margin + (' ' * ($Canvas.Size + 4)))
    for ($y = 0; $y -lt $Canvas.Size; $y += 2) {
        $line = $margin + '  '
        for ($x = 0; $x -lt $Canvas.Size; $x++) {
            $top = $(if ($Canvas.Modules[$y, $x]) { '1' } else { '0' })
            $bottom = $(if (($y + 1) -lt $Canvas.Size -and $Canvas.Modules[($y + 1), $x]) { '1' } else { '0' })
            # 暗いモジュールを背景色(白)側に描くと読み取り機が反転を嫌うので、
            # 前景が暗、背景が明になるよう白背景・黒前景で出す。
            $line += $blocks["$top$bottom"]
        }
        Write-Host $line -ForegroundColor Black -BackgroundColor White
    }
    Write-Host ($margin + (' ' * ($Canvas.Size + 4)))
}

# ---------------------------------------------------------------- 実行

if ($Raw) {
    $payload = $Code
    $normalized = $Code
} else {
    # Android の normalizeAccessCode / PHP の km_app_map_normalize_code と同じ規則
    $normalized = ($Code.Trim().ToUpperInvariant() -replace '[\s\-_]+', '')
    if ($normalized -eq '') { throw 'アクセスコードが空です。' }
    $payload = "KOSENMAP1:$normalized"
}

$result = New-QrMatrix -Text $payload -Ec $ErrorCorrection -ForcedMask $Mask

if ($MatrixOnly) {
    $canvas = $result.Canvas
    for ($y = 0; $y -lt $canvas.Size; $y++) {
        $row = New-Object System.Text.StringBuilder
        for ($x = 0; $x -lt $canvas.Size; $x++) {
            [void]$row.Append($(if ($canvas.Modules[$y, $x]) { '1' } else { '0' }))
        }
        $row.ToString()
    }
    return
}

if (-not $OutputPath) { $OutputPath = Join-Path (Get-Location) "kosenmap-qr-$normalized.png" }
Save-QrPng -Canvas $result.Canvas -Path $OutputPath -Scale $ModuleSize -Quiet $QuietZone

if ($ShowInConsole) { Show-QrInConsole -Canvas $result.Canvas }

[pscustomobject]@{
    Code       = $normalized
    Payload    = $payload
    Version    = $result.Version
    Modules    = "$($result.Canvas.Size) x $($result.Canvas.Size)"
    Ec         = $ErrorCorrection
    Mask       = $result.Mask
    OutputPath = (Resolve-Path $OutputPath).Path
}
