<?php
// Path: _core/QrEncoder.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Minimal QR encoder (internal) 🔳
 * -----------------------------------------------------------------------------
 * Used by Portal\Core\Qr. Not a general QR library — covers byte-mode,
 * EC level M, versions 1-10. That's enough for any URL up to ~250 chars
 * (covers all portal use cases: feed URLs, invite tokens, vCards).
 *
 * Implementation follows ISO/IEC 18004 with shortcuts where the limited
 * scope allows. Reed-Solomon over GF(256), Galois log/exp tables built at
 * load time.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @internal  Use Portal\Core\Qr instead of calling this directly.
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/275
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class QrEncoder
{
    // Byte capacity (bits) for version 1-10 at EC level M, byte mode.
    private const CAPACITY_BYTES_M = [
        1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
        6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213,
    ];

    // EC bytes per block, blocks per group for version × EC=M.
    // Format: [ecPerBlock, [groupCount, blockCount, dataPerBlock], ...]
    private const RS_PARAMS_M = [
        1  => [10, [[1, 1, 16]]],
        2  => [16, [[1, 1, 28]]],
        3  => [26, [[1, 1, 44]]],
        4  => [18, [[1, 2, 32]]],
        5  => [24, [[1, 2, 43]]],
        6  => [16, [[1, 4, 27]]],
        7  => [18, [[1, 4, 31]]],
        8  => [22, [[1, 2, 38], [1, 2, 39]]],
        9  => [22, [[1, 3, 36], [1, 2, 37]]],
        10 => [26, [[1, 4, 43], [1, 1, 44]]],
    ];

    /** @var int[] */
    private array $gfExp;
    /** @var int[] */
    private array $gfLog;

    public function __construct()
    {
        $this->gfExp = array_fill(0, 512, 0);
        $this->gfLog = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $this->gfExp[$i] = $x;
            $this->gfLog[$x] = $i;
            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $this->gfExp[$i] = $this->gfExp[$i - 255];
        }
    }

    /**
     * @return array<int, array<int, bool>>
     */
    public function encode(string $content): array
    {
        // 🪞 Pick the smallest version that fits in byte mode at EC=M.
        $version = 0;
        $length  = strlen($content);
        foreach (self::CAPACITY_BYTES_M as $v => $capacity) {
            if ($length <= $capacity) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            // Overflow — truncate with an ellipsis marker. Caller can
            // shorten upstream for cleaner output.
            $content = substr($content, 0, self::CAPACITY_BYTES_M[10] - 1) . '…';
            $version = 10;
            $length  = strlen($content);
        }

        // Bit stream: mode (4 bits) + length (8 bits for v1-9, 16 bits for v10) + bytes + terminator.
        $bits = '0100'; // byte mode
        $lenBits = $version <= 9 ? 8 : 16;
        $bits .= str_pad(decbin($length), $lenBits, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($content[$i])), 8, '0', STR_PAD_LEFT);
        }

        [$ecPerBlock, $groups] = self::RS_PARAMS_M[$version];
        $totalDataBytes = 0;
        $blockSpecs     = [];
        foreach ($groups as $g) {
            for ($i = 0; $i < $g[1]; $i++) {
                $blockSpecs[] = $g[2];
                $totalDataBytes += $g[2];
            }
        }
        $totalDataBits = $totalDataBytes * 8;

        // Terminator + byte alignment.
        $bits .= str_repeat('0', min(4, $totalDataBits - strlen($bits)));
        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }
        // Pad bytes 0xEC, 0x11.
        $pads = ['11101100', '00010001'];
        $p = 0;
        while (strlen($bits) < $totalDataBits) {
            $bits .= $pads[$p % 2];
            $p++;
        }

        // Split into blocks → compute Reed-Solomon ECC for each.
        $dataBlocks = [];
        $ecBlocks   = [];
        $cursor     = 0;
        foreach ($blockSpecs as $dataBytes) {
            $block = [];
            for ($i = 0; $i < $dataBytes; $i++) {
                $byte = substr($bits, ($cursor + $i) * 8, 8);
                $block[] = bindec($byte);
            }
            $cursor += $dataBytes;
            $dataBlocks[] = $block;
            $ecBlocks[]   = $this->rsEncode($block, $ecPerBlock);
        }

        // Interleave.
        $maxDataLen = max(array_map('count', $dataBlocks));
        $finalBytes = [];
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($dataBlocks as $b) {
                if (isset($b[$i])) {
                    $finalBytes[] = $b[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $b) {
                $finalBytes[] = $b[$i];
            }
        }

        // Place into a matrix.
        $modules = 17 + 4 * $version;
        $matrix = array_fill(0, $modules, array_fill(0, $modules, null));
        $reserved = array_fill(0, $modules, array_fill(0, $modules, false));

        $this->placeFinderPatterns($matrix, $reserved, $modules);
        $this->placeTimingPatterns($matrix, $reserved, $modules);
        $this->placeAlignmentPatterns($matrix, $reserved, $modules, $version);
        // Dark module (always set).
        $matrix[$modules - 8][8] = true;
        $reserved[$modules - 8][8] = true;
        // Reserve format info area.
        $this->reserveFormatInfo($reserved, $modules);
        // Reserve version info area for versions >= 7.
        if ($version >= 7) {
            $this->reserveVersionInfo($reserved, $modules);
        }

        // Place data bits using zig-zag pattern.
        $this->placeData($matrix, $reserved, $modules, $finalBytes);

        // Choose mask 0 (simple and good enough for our use cases — full
        // mask evaluation would require evaluating all 8 masks which adds
        // ~200 LOC for marginal benefit).
        $maskPattern = 0;
        $this->applyMask($matrix, $reserved, $modules, $maskPattern);

        // Write format info (EC level M = 00, mask 0 = 000 → 00 000).
        $this->writeFormatInfo($matrix, $modules, $maskPattern);
        if ($version >= 7) {
            $this->writeVersionInfo($matrix, $modules, $version);
        }

        // Convert nulls to false for output.
        $out = [];
        for ($y = 0; $y < $modules; $y++) {
            $row = [];
            for ($x = 0; $x < $modules; $x++) {
                $row[] = $matrix[$y][$x] === true;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param int[] $data
     * @return int[]
     */
    private function rsEncode(array $data, int $ecLen): array
    {
        // Build generator polynomial.
        $gen = [1];
        for ($i = 0; $i < $ecLen; $i++) {
            $next = [];
            $next[] = $gen[0];
            $expI = $this->gfExp[$i];
            for ($j = 1; $j < count($gen); $j++) {
                $next[] = $gen[$j] ^ $this->gfExp[$this->gfLog[$gen[$j - 1]] + $i];
            }
            $next[] = $this->gfExp[$this->gfLog[$gen[count($gen) - 1]] + $i];
            // Above approximates poly multiplication via log addition; simpler version:
            $gen = self::polyMul($gen, [1, $this->gfExp[$i]], $this->gfExp, $this->gfLog);
        }

        $msg = array_merge($data, array_fill(0, $ecLen, 0));
        for ($i = 0; $i < count($data); $i++) {
            $coef = $msg[$i];
            if ($coef === 0) {
                continue;
            }
            $logCoef = $this->gfLog[$coef];
            for ($j = 0; $j < count($gen); $j++) {
                $msg[$i + $j] ^= $this->gfExp[($this->gfLog[$gen[$j]] + $logCoef) % 255];
            }
        }
        return array_slice($msg, count($data));
    }

    /**
     * @param int[] $a
     * @param int[] $b
     * @param int[] $exp
     * @param int[] $log
     * @return int[]
     */
    private static function polyMul(array $a, array $b, array $exp, array $log): array
    {
        $result = array_fill(0, count($a) + count($b) - 1, 0);
        foreach ($a as $i => $ac) {
            if ($ac === 0) {
                continue;
            }
            $la = $log[$ac];
            foreach ($b as $j => $bc) {
                if ($bc === 0) {
                    continue;
                }
                $lb = $log[$bc];
                $result[$i + $j] ^= $exp[($la + $lb) % 255];
            }
        }
        return $result;
    }

    private function placeFinderPatterns(array &$matrix, array &$reserved, int $modules): void
    {
        $positions = [[0, 0], [$modules - 7, 0], [0, $modules - 7]];
        foreach ($positions as [$ox, $oy]) {
            for ($y = -1; $y <= 7; $y++) {
                for ($x = -1; $x <= 7; $x++) {
                    $mx = $ox + $x;
                    $my = $oy + $y;
                    if ($mx < 0 || $my < 0 || $mx >= $modules || $my >= $modules) {
                        continue;
                    }
                    $isFinder = ($x >= 0 && $x <= 6 && $y >= 0 && $y <= 6) && (
                        $x === 0 || $x === 6 || $y === 0 || $y === 6 ||
                        ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4)
                    );
                    $matrix[$my][$mx] = $isFinder;
                    $reserved[$my][$mx] = true;
                }
            }
        }
    }

    private function placeTimingPatterns(array &$matrix, array &$reserved, int $modules): void
    {
        for ($i = 8; $i < $modules - 8; $i++) {
            $bit = ($i % 2) === 0;
            $matrix[6][$i] = $bit;
            $matrix[$i][6] = $bit;
            $reserved[6][$i] = true;
            $reserved[$i][6] = true;
        }
    }

    private function placeAlignmentPatterns(array &$matrix, array &$reserved, int $modules, int $version): void
    {
        if ($version < 2) {
            return;
        }
        // Alignment-pattern coordinate table (versions 2-10).
        static $coords = [
            2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
            6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
            10 => [6, 28, 50],
        ];
        $list = $coords[$version];
        foreach ($list as $cy) {
            foreach ($list as $cx) {
                // Skip finder-pattern overlaps.
                if (($cx === 6 && $cy === 6) ||
                    ($cx === $modules - 7 && $cy === 6) ||
                    ($cx === 6 && $cy === $modules - 7)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $mx = $cx + $dx;
                        $my = $cy + $dy;
                        if ($mx < 0 || $my < 0 || $mx >= $modules || $my >= $modules) {
                            continue;
                        }
                        $absX = abs($dx);
                        $absY = abs($dy);
                        $isOn = ($absX === 2 || $absY === 2) || ($absX === 0 && $absY === 0);
                        $matrix[$my][$mx] = $isOn;
                        $reserved[$my][$mx] = true;
                    }
                }
            }
        }
    }

    private function reserveFormatInfo(array &$reserved, int $modules): void
    {
        for ($i = 0; $i < 9; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$modules - 1 - $i] = true;
            $reserved[$modules - 1 - $i][8] = true;
        }
    }

    private function reserveVersionInfo(array &$reserved, int $modules): void
    {
        for ($y = 0; $y < 6; $y++) {
            for ($x = $modules - 11; $x < $modules - 8; $x++) {
                $reserved[$y][$x] = true;
                $reserved[$x][$y] = true;
            }
        }
    }

    private function placeData(array &$matrix, array $reserved, int $modules, array $bytes): void
    {
        $bitStream = '';
        foreach ($bytes as $b) {
            $bitStream .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $cursor = 0;
        $upward = true;
        $col = $modules - 1;
        while ($col > 0) {
            if ($col === 6) {
                $col--; // skip the timing column
            }
            for ($r = 0; $r < $modules; $r++) {
                $y = $upward ? $modules - 1 - $r : $r;
                for ($c = 0; $c < 2; $c++) {
                    $x = $col - $c;
                    if ($reserved[$y][$x] === true) {
                        continue;
                    }
                    $bit = $cursor < strlen($bitStream) ? $bitStream[$cursor] === '1' : false;
                    $matrix[$y][$x] = $bit;
                    $cursor++;
                }
            }
            $upward = !$upward;
            $col -= 2;
        }
    }

    private function applyMask(array &$matrix, array $reserved, int $modules, int $pattern): void
    {
        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($reserved[$y][$x] === true) {
                    continue;
                }
                $invert = false;
                switch ($pattern) {
                    case 0: $invert = (($x + $y) % 2) === 0; break;
                    case 1: $invert = ($y % 2) === 0; break;
                    case 2: $invert = ($x % 3) === 0; break;
                    case 3: $invert = (($x + $y) % 3) === 0; break;
                }
                if ($invert === true) {
                    $matrix[$y][$x] = $matrix[$y][$x] !== true;
                }
            }
        }
    }

    private function writeFormatInfo(array &$matrix, int $modules, int $maskPattern): void
    {
        // EC level M = 00, mask = $maskPattern (3 bits) → 5-bit data, BCH(15,5) ECC.
        $data = (0 << 3) | ($maskPattern & 0x7); // 5 bits
        // Compute 10 EC bits via BCH(15,5) with generator 0b10100110111.
        $bch = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($bch & (1 << $i)) !== 0) {
                $bch ^= 0x537 << ($i - 10);
            }
        }
        $combined = (($data << 10) | $bch) ^ 0x5412;

        // Place in two regions.
        for ($i = 0; $i < 15; $i++) {
            $bit = (($combined >> (14 - $i)) & 1) === 1;
            // Around top-left finder
            if ($i < 6) {
                $matrix[$i][8] = $bit;
            } elseif ($i < 8) {
                $matrix[$i + 1][8] = $bit;
            } elseif ($i === 8) {
                $matrix[8][7] = $bit;
            } else {
                $matrix[8][14 - $i] = $bit;
            }
            // Right + bottom of finders
            if ($i < 8) {
                $matrix[8][$modules - 1 - $i] = $bit;
            } else {
                $matrix[$modules - 15 + $i][8] = $bit;
            }
        }
    }

    private function writeVersionInfo(array &$matrix, int $modules, int $version): void
    {
        // BCH(18,6) for version info.
        $bch = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if (($bch & (1 << $i)) !== 0) {
                $bch ^= 0x1F25 << ($i - 12);
            }
        }
        $combined = ($version << 12) | $bch;

        for ($i = 0; $i < 18; $i++) {
            $bit = (($combined >> $i) & 1) === 1;
            $x = (int) ($i / 3);
            $y = $modules - 11 + ($i % 3);
            $matrix[$y][$x] = $bit;
            $matrix[$x][$y] = $bit;
        }
    }
}
