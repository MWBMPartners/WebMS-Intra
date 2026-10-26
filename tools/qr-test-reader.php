<?php
// Path: tools/qr-test-reader.php
/**
 * -----------------------------------------------------------------------------
 * A QR reader for tests only 🔎🔳 (#623)
 * -----------------------------------------------------------------------------
 * The portal draws QR pictures (`Portal\Core\Qr`). For three months it drew
 * pictures no reader could read, and nothing noticed, because no test ever
 * tried to READ one. This file is the reader the tests use.
 *
 * It lives in `tools/` on purpose. Only `web/` is deployed, so this never
 * reaches a customer's server, and nothing under `web/` may use it.
 *
 * WHO USES IT
 *   tools/qr-selftest.php        proves this reader first, then the portal's
 *                                own pictures with it.
 *   tools/two-step-selftest.php  reads the picture on the two-factor set-up
 *                                page back.
 *
 * WHY IT CAN BE TRUSTED: it shares nothing with the code it tests. It has its
 * own copy of every table and its own arithmetic, and `tools/qr-selftest.php`
 * first makes it read pictures drawn by a different program (`qrencode`),
 * kept in `tools/fixtures/qr/`. A reader that only ever read the portal's own
 * pictures could agree with the portal about a mistake they both make.
 *
 * IT IS STRICT, NOT FORGIVING, AND THAT IS THE POINT. A phone's reader repairs
 * damage: a QR code carries spare words so that a scratched label still
 * reads. This reader repairs nothing. One wrong square anywhere is a refusal,
 * with words that say where. A forgiving reader would have hidden a small
 * fault in the drawing; this one cannot.
 *
 * WHAT IT CANNOT DO
 * - It is not a camera. It reads a picture whose squares are exactly square,
 *   upright and one flat colour each. It says nothing about a photograph, a
 *   printed label, a curved surface or poor light.
 * - It reads the 8-bit ("byte") way of writing text only, which is the only
 *   way the portal writes. A picture written another way is refused in words.
 * - For an SVG picture it reads the drawing commands the portal writes (one
 *   background rectangle and one path of small rectangles). It does not prove
 *   that a browser paints those commands the same way.
 * - Passing here does not prove that every reader in the world reads the
 *   picture. It proves the picture keeps to the QR standard (ISO/IEC 18004),
 *   which is what readers are built to.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/623
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * 🚫 The reader's "no". Its message is plain words that say what is wrong with
 * the picture, so a failing test explains itself.
 */
final class QrTestReaderRefusal extends \RuntimeException
{
}

final class QrTestReader
{
    /**
     * 📋 Error-correction words in each block, by level and size (1 to 40).
     * Index 0 is unused. The reader's OWN copy: it must not borrow the
     * portal's, or a wrong number there would be wrong here too.
     */
    private const EC_WORDS_PER_BLOCK = [
        'L' => [0, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'M' => [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        'Q' => [0, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'H' => [0, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    /** 📋 How many blocks the words are split into, by level and size. */
    private const BLOCK_COUNT = [
        'L' => [0, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        'M' => [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        'Q' => [0, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        'H' => [0, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    /**
     * 📋 Where the small "alignment" squares sit, by size. Written out as the
     * standard's table (Annex E) on purpose: the portal works these out by a
     * formula, so the two are checked against each other.
     */
    private const ALIGNMENT_CENTRES = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
        11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62],
        14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74],
        17 => [6, 30, 54, 78], 18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86], 20 => [6, 34, 62, 90],
        21 => [6, 28, 50, 72, 94], 22 => [6, 26, 50, 74, 98], 23 => [6, 30, 54, 78, 102],
        24 => [6, 28, 54, 80, 106], 25 => [6, 32, 58, 84, 110], 26 => [6, 30, 58, 86, 114],
        27 => [6, 34, 62, 90, 118],
        28 => [6, 26, 50, 74, 98, 122], 29 => [6, 30, 54, 78, 102, 126], 30 => [6, 26, 52, 78, 104, 130],
        31 => [6, 30, 56, 82, 108, 134], 32 => [6, 34, 60, 86, 112, 138], 33 => [6, 30, 58, 86, 114, 142],
        34 => [6, 34, 62, 90, 118, 146],
        35 => [6, 30, 54, 78, 102, 126, 150], 36 => [6, 24, 50, 76, 102, 128, 154],
        37 => [6, 28, 54, 80, 106, 132, 158], 38 => [6, 32, 58, 84, 110, 136, 162],
        39 => [6, 26, 54, 82, 110, 138, 166], 40 => [6, 30, 58, 86, 114, 142, 170],
    ];

    /** The two bits that name each error-correction level in the format marks. */
    private const LEVEL_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    // =========================================================================
    // 📖 Reading a grid of squares
    // =========================================================================

    /**
     * Read a QR code from its squares.
     *
     * @param list<list<bool>> $grid The squares, row by row, with NO white
     *                               border. true is a dark square.
     *
     * @return array{text:string, version:int, level:string, mask:int, penalties:list<int>}
     *         text       the bytes the picture holds
     *         version    its size, 1 (21 squares across) to 40 (177 across)
     *         level      its error-correction level: L, M, Q or H
     *         mask       which of the eight patterns the data was mixed with
     *         penalties  this reader's own count of how hard each of the eight
     *                    patterns would be to read (lower is better); the
     *                    standard tells the drawer to pick the lowest
     *
     * @throws QrTestReaderRefusal when anything at all is not as the standard says.
     */
    public static function readGrid(array $grid): array
    {
        $n = count($grid);
        if ($n < 21 || $n > 177 || ($n - 17) % 4 !== 0) {
            throw new QrTestReaderRefusal('The picture is ' . $n . ' squares across, and no QR code is that size.');
        }
        foreach ($grid as $y => $row) {
            if (count($row) !== $n) {
                throw new QrTestReaderRefusal('Row ' . $y . ' has ' . count($row) . ' squares, but the picture is ' . $n . ' rows tall. A QR code is square.');
            }
        }
        $version = intdiv($n - 17, 4);

        // 🧱 1. The fixed squares: corners, timing lines, alignment squares.
        $kind = self::fixedSquares($version);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $k = $kind[$y][$x];
                if ($k === 'dark' && $grid[$y][$x] !== true) {
                    throw new QrTestReaderRefusal('A fixed square is light where it must be dark, at column ' . $x . ', row ' . $y . ' (' . self::whereIs($x, $y, $n) . ').');
                }
                if ($k === 'light' && $grid[$y][$x] !== false) {
                    throw new QrTestReaderRefusal('A fixed square is dark where it must be light, at column ' . $x . ', row ' . $y . ' (' . self::whereIs($x, $y, $n) . ').');
                }
            }
        }

        // 🏷️ 2. The format marks: fifteen squares, written twice, that say
        //       which level and which mask were used.
        $first  = 0;
        $second = 0;
        for ($i = 0; $i < 15; $i++) {
            [$ax, $ay] = self::formatSquare($i, 1, $n);
            [$bx, $by] = self::formatSquare($i, 2, $n);
            if ($grid[$ay][$ax] === true) {
                $first |= (1 << $i);
            }
            if ($grid[$by][$bx] === true) {
                $second |= (1 << $i);
            }
        }
        $found = self::formatMeaning($first);
        if ($found === null) {
            $hint = '';
            if (self::formatMeaning(self::reverseBits($first, 15)) !== null) {
                $hint = ' They DO spell one when read back to front, so the fifteen squares are written in the wrong order.';
            }
            throw new QrTestReaderRefusal('The format marks beside the top-left corner do not spell any of the 32 values the standard allows.' . $hint);
        }
        if ($second !== $first) {
            throw new QrTestReaderRefusal('The two copies of the format marks do not say the same thing.');
        }
        [$level, $mask] = $found;

        // 🔢 3. The size marks, on pictures of size 7 and above: eighteen
        //       squares, written twice.
        if ($version >= 7) {
            $want = self::versionWord($version);
            $one  = 0;
            $two  = 0;
            for ($i = 0; $i < 18; $i++) {
                $a = $n - 11 + ($i % 3);
                $b = intdiv($i, 3);
                if ($grid[$b][$a] === true) {
                    $one |= (1 << $i);
                }
                if ($grid[$a][$b] === true) {
                    $two |= (1 << $i);
                }
            }
            if ($one !== $want || $two !== $want) {
                throw new QrTestReaderRefusal('The size marks do not say size ' . $version . ', which is the size the picture is.');
            }
        }

        // 🧵 4. The data squares, in the standard's zig-zag order, with the
        //       mask taken off.
        $bits = [];
        $up   = true;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // the upright timing line takes a whole column
            }
            for ($step = 0; $step < $n; $step++) {
                $y = ($up === true) ? $n - 1 - $step : $step;
                for ($side = 0; $side < 2; $side++) {
                    $x = $right - $side;
                    if ($kind[$y][$x] !== 'data') {
                        continue;
                    }
                    $bit = $grid[$y][$x];
                    if (self::maskFlips($mask, $x, $y) === true) {
                        $bit = ($bit === false);
                    }
                    $bits[] = ($bit === true) ? 1 : 0;
                }
            }
            $up = ($up === false);
        }

        $rawSquares = self::dataSquareCount($version);
        if (count($bits) !== $rawSquares) {
            // Only this reader's own map of fixed squares can cause this.
            throw new QrTestReaderRefusal('This reader counted ' . count($bits) . ' data squares where size ' . $version . ' has ' . $rawSquares . '. The reader itself is wrong.');
        }
        $totalWords = intdiv($rawSquares, 8);
        for ($i = $totalWords * 8; $i < $rawSquares; $i++) {
            if ($bits[$i] !== 0) {
                throw new QrTestReaderRefusal('The few squares left over after the last word must be empty, and one is not.');
            }
        }
        $words = [];
        for ($w = 0; $w < $totalWords; $w++) {
            $value = 0;
            for ($b = 0; $b < 8; $b++) {
                $value = ($value << 1) | $bits[$w * 8 + $b];
            }
            $words[] = $value;
        }

        // 🧩 5. The words were dealt out block by block like cards. Gather
        //       each block again, then check its error-correction words.
        $blockCount = self::BLOCK_COUNT[$level][$version];
        $ecLen      = self::EC_WORDS_PER_BLOCK[$level][$version];
        $shortLen   = intdiv($totalWords, $blockCount);
        $shortCount = $blockCount - ($totalWords % $blockCount);
        $dataLen    = [];
        for ($b = 0; $b < $blockCount; $b++) {
            $dataLen[$b] = $shortLen - $ecLen + (($b >= $shortCount) ? 1 : 0);
        }
        $dataBlocks = array_fill(0, $blockCount, []);
        $ecBlocks   = array_fill(0, $blockCount, []);
        $at         = 0;
        $longest    = max($dataLen);
        for ($i = 0; $i < $longest; $i++) {
            for ($b = 0; $b < $blockCount; $b++) {
                if ($i < $dataLen[$b]) {
                    $dataBlocks[$b][] = $words[$at++];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            for ($b = 0; $b < $blockCount; $b++) {
                $ecBlocks[$b][] = $words[$at++];
            }
        }
        for ($b = 0; $b < $blockCount; $b++) {
            if (self::errorCorrectionWords($dataBlocks[$b], $ecLen) !== $ecBlocks[$b]) {
                throw new QrTestReaderRefusal('The error-correction words of block ' . ($b + 1) . ' of ' . $blockCount . ' do not fit its data. At least one square in the picture is wrong.');
            }
        }

        // 📜 6. The message itself.
        $stream = [];
        foreach ($dataBlocks as $block) {
            foreach ($block as $word) {
                for ($b = 7; $b >= 0; $b--) {
                    $stream[] = ($word >> $b) & 1;
                }
            }
        }
        $text = self::readMessage($stream, $version);

        return [
            'text'      => $text,
            'version'   => $version,
            'level'     => $level,
            'mask'      => $mask,
            'penalties' => self::penalties($grid, $kind, $level, $mask),
        ];
    }

    /**
     * 📜 Take the text out of the stream of bits.
     *
     * @param list<int> $stream
     */
    private static function readMessage(array $stream, int $version): string
    {
        $total = count($stream);
        $pos   = 0;
        $take  = static function (int $count) use (&$pos, $stream, $total): int {
            if ($pos + $count > $total) {
                throw new QrTestReaderRefusal('The message says it is longer than the picture has room for.');
            }
            $value = 0;
            for ($i = 0; $i < $count; $i++) {
                $value = ($value << 1) | $stream[$pos++];
            }
            return $value;
        };

        $text = '';
        while (true) {
            if ($total - $pos < 4) {
                // Fewer than four bits left: the end mark may be cut short.
                break;
            }
            $mode = $take(4);
            if ($mode === 0) {
                break; // the end mark
            }
            if ($mode !== 4) {
                throw new QrTestReaderRefusal('Part of the message is written in a way this test reader does not read (mode ' . $mode . '). It reads the 8-bit way only, which is the only way the portal writes.');
            }
            $length = $take(($version <= 9) ? 8 : 16);
            for ($i = 0; $i < $length; $i++) {
                $text .= chr($take(8));
            }
        }
        // What is left must be empty up to the next whole word, then the two
        // filler words the standard names, turn about.
        while ($pos < $total && $pos % 8 !== 0) {
            if ($stream[$pos++] !== 0) {
                throw new QrTestReaderRefusal('The gap after the message is not empty.');
            }
        }
        $fillers = [0xEC, 0x11];
        $turn    = 0;
        while ($pos < $total) {
            if ($take(8) !== $fillers[$turn % 2]) {
                throw new QrTestReaderRefusal('The filler after the message is not the filler the standard names.');
            }
            $turn++;
        }
        return $text;
    }

    // =========================================================================
    // 🧱 The fixed squares
    // =========================================================================

    /**
     * Which squares are fixed for a size, and what each must be.
     *
     * @return list<list<string>> 'dark', 'light', 'marks' (format and size
     *                            marks, read separately) or 'data'.
     */
    private static function fixedSquares(int $version): array
    {
        $n    = 17 + 4 * $version;
        $kind = array_fill(0, $n, array_fill(0, $n, 'data'));

        // The three corner squares, each with its light edging.
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$left, $top]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $x = $left + $dx;
                    $y = $top + $dy;
                    if ($x < 0 || $y < 0 || $x >= $n || $y >= $n) {
                        continue;
                    }
                    $inside = ($dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6);
                    $ring   = ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6);
                    $heart  = ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4);
                    $kind[$y][$x] = ($inside === true && ($ring === true || $heart === true)) ? 'dark' : 'light';
                }
            }
        }
        // The two timing lines: dark, light, dark, light.
        for ($i = 8; $i <= $n - 9; $i++) {
            $kind[6][$i] = ($i % 2 === 0) ? 'dark' : 'light';
            $kind[$i][6] = ($i % 2 === 0) ? 'dark' : 'light';
        }
        // The small alignment squares (none where a corner square sits).
        $centres = self::ALIGNMENT_CENTRES[$version];
        $last    = count($centres) - 1;
        foreach ($centres as $iy => $cy) {
            foreach ($centres as $ix => $cx) {
                if (($ix === 0 && $iy === 0) || ($ix === $last && $iy === 0) || ($ix === 0 && $iy === $last)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $edge = (max(abs($dx), abs($dy)) === 2);
                        $dot  = ($dx === 0 && $dy === 0);
                        $kind[$cy + $dy][$cx + $dx] = ($edge === true || $dot === true) ? 'dark' : 'light';
                    }
                }
            }
        }
        // The format marks (two copies) and the one square that is always dark.
        for ($i = 0; $i < 15; $i++) {
            [$ax, $ay] = self::formatSquare($i, 1, $n);
            [$bx, $by] = self::formatSquare($i, 2, $n);
            $kind[$ay][$ax] = 'marks';
            $kind[$by][$bx] = 'marks';
        }
        $kind[$n - 8][8] = 'dark';
        // The size marks, from size 7 up.
        if ($version >= 7) {
            for ($i = 0; $i < 18; $i++) {
                $a = $n - 11 + ($i % 3);
                $b = intdiv($i, 3);
                $kind[$b][$a] = 'marks';
                $kind[$a][$b] = 'marks';
            }
        }
        return $kind;
    }

    /**
     * Where format bit $i (0 is the lowest) sits, in copy 1 or copy 2.
     * This is Figure 25 of the standard.
     *
     * @return array{0:int,1:int} column, row
     */
    private static function formatSquare(int $i, int $copy, int $n): array
    {
        if ($copy === 1) {
            if ($i <= 5) {
                return [8, $i];
            }
            if ($i === 6) {
                return [8, 7];
            }
            if ($i === 7) {
                return [8, 8];
            }
            if ($i === 8) {
                return [7, 8];
            }
            return [14 - $i, 8];
        }
        if ($i <= 7) {
            return [$n - 1 - $i, 8];
        }
        return [8, $n - 15 + $i];
    }

    /** The fifteen format bits for a level and a mask. */
    private static function formatWord(string $level, int $mask): int
    {
        $five = (self::LEVEL_BITS[$level] << 3) | $mask;
        $rest = $five;
        for ($i = 0; $i < 10; $i++) {
            $rest = ($rest << 1) ^ ((($rest >> 9) & 1) * 0x537);
        }
        return (($five << 10) | ($rest & 0x3FF)) ^ 0x5412;
    }

    /** @return array{0:string,1:int}|null level and mask, or null when the word is none of the 32. */
    private static function formatMeaning(int $word): ?array
    {
        foreach (array_keys(self::LEVEL_BITS) as $level) {
            for ($mask = 0; $mask < 8; $mask++) {
                if (self::formatWord($level, $mask) === $word) {
                    return [$level, $mask];
                }
            }
        }
        return null;
    }

    /** The eighteen size bits for a size of 7 or more. */
    private static function versionWord(int $version): int
    {
        $rest = $version;
        for ($i = 0; $i < 12; $i++) {
            $rest = ($rest << 1) ^ ((($rest >> 11) & 1) * 0x1F25);
        }
        return ($version << 12) | ($rest & 0xFFF);
    }

    private static function reverseBits(int $value, int $width): int
    {
        $out = 0;
        for ($i = 0; $i < $width; $i++) {
            if ((($value >> $i) & 1) === 1) {
                $out |= (1 << ($width - 1 - $i));
            }
        }
        return $out;
    }

    /** Does mask $mask turn the square at column $x, row $y over? */
    private static function maskFlips(int $mask, int $x, int $y): bool
    {
        switch ($mask) {
            case 0:
                return (($x + $y) % 2) === 0;
            case 1:
                return ($y % 2) === 0;
            case 2:
                return ($x % 3) === 0;
            case 3:
                return (($x + $y) % 3) === 0;
            case 4:
                return ((intdiv($x, 3) + intdiv($y, 2)) % 2) === 0;
            case 5:
                return ((($x * $y) % 2) + (($x * $y) % 3)) === 0;
            case 6:
                return (((($x * $y) % 2) + (($x * $y) % 3)) % 2) === 0;
            default:
                return (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0;
        }
    }

    /**
     * How many squares of a size carry data and error correction, by the
     * usual formula. readGrid() counts the same thing a second way, from the
     * map of fixed squares, and refuses when the two differ: a wrong map and
     * a wrong formula would have to be wrong by the same amount to agree.
     */
    private static function dataSquareCount(int $version): int
    {
        $count = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $alignments = intdiv($version, 7) + 2;
            $count -= (25 * $alignments - 10) * $alignments - 55;
            if ($version >= 7) {
                $count -= 36;
            }
        }
        return $count;
    }

    /**
     * The longest text, in bytes, that a size holds at a level when it is
     * written the 8-bit way. Worked out from this reader's own tables.
     * `tools/qr-selftest.php --with-qrencode` proves every one of the 160
     * answers against `qrencode`: that many bytes fit the size, and one more
     * does not.
     */
    public static function capacityInBytes(int $version, string $level): int
    {
        if ($version < 1 || $version > 40 || isset(self::LEVEL_BITS[$level]) === false) {
            throw new \InvalidArgumentException('No such size or level.');
        }
        $dataWords = intdiv(self::dataSquareCount($version), 8)
            - self::EC_WORDS_PER_BLOCK[$level][$version] * self::BLOCK_COUNT[$level][$version];
        // Four bits say "8-bit text", then the length takes 8 bits up to
        // size 9 and 16 bits from size 10.
        $headBits = 4 + (($version <= 9) ? 8 : 16);
        return intdiv($dataWords * 8 - $headBits, 8);
    }

    /** A plain name for a place in the picture, for the refusal messages. */
    private static function whereIs(int $x, int $y, int $n): string
    {
        if ($x <= 7 && $y <= 7) {
            return 'the top-left corner square';
        }
        if ($x >= $n - 8 && $y <= 7) {
            return 'the top-right corner square';
        }
        if ($x <= 7 && $y >= $n - 8) {
            return 'the bottom-left corner square';
        }
        if ($x === 6 || $y === 6) {
            return 'a timing line';
        }
        if ($x === 8 && $y === $n - 8) {
            return 'the square that is always dark';
        }
        return 'an alignment square';
    }

    // =========================================================================
    // 🧮 Error correction (Reed-Solomon over a field of 256 values)
    // =========================================================================

    /** Multiply two values of the field, the slow, plain way: no tables to get wrong. */
    private static function fieldMultiply(int $a, int $b): int
    {
        $product = 0;
        for ($i = 7; $i >= 0; $i--) {
            $product = (($product << 1) ^ (($product >> 7) * 0x11D)) & 0x1FF;
            if (((($b >> $i) & 1)) === 1) {
                $product ^= $a;
            }
        }
        return $product & 0xFF;
    }

    /**
     * The error-correction words that belong to a block of data.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    private static function errorCorrectionWords(array $data, int $count): array
    {
        static $dividers = [];
        if (isset($dividers[$count]) === false) {
            // The divider is (x - 1)(x - 2)(x - 4)... with $count factors.
            $poly = array_fill(0, $count, 0);
            $poly[$count - 1] = 1;
            $root = 1;
            for ($i = 0; $i < $count; $i++) {
                for ($j = 0; $j < $count; $j++) {
                    $poly[$j] = self::fieldMultiply($poly[$j], $root);
                    if ($j + 1 < $count) {
                        $poly[$j] ^= $poly[$j + 1];
                    }
                }
                $root = self::fieldMultiply($root, 2);
            }
            $dividers[$count] = $poly;
        }
        $divider = $dividers[$count];
        $rest    = array_fill(0, $count, 0);
        foreach ($data as $word) {
            $factor = $word ^ $rest[0];
            array_shift($rest);
            $rest[] = 0;
            foreach ($divider as $i => $coefficient) {
                $rest[$i] ^= self::fieldMultiply($coefficient, $factor);
            }
        }
        return $rest;
    }

    // =========================================================================
    // ⚖️ The standard's count of how hard a picture is to read
    // =========================================================================

    /**
     * Count the penalty of each of the eight masks for this picture, the way
     * section 7.8.3 of the standard does. The drawer is told to pick the
     * lowest. Every reader copes with any of the eight, so a "wrong" choice
     * here still reads; the count is checked so that the choosing is known to
     * be alive.
     *
     * @param list<list<bool>>   $grid
     * @param list<list<string>> $kind
     *
     * @return list<int> eight numbers, mask 0 first
     */
    private static function penalties(array $grid, array $kind, string $level, int $usedMask): array
    {
        $n   = count($grid);
        $out = [];
        for ($mask = 0; $mask < 8; $mask++) {
            // The picture as it would be with this mask: take the used mask
            // off the data squares, put this one on, and write this mask's
            // format marks.
            $trial = $grid;
            for ($y = 0; $y < $n; $y++) {
                for ($x = 0; $x < $n; $x++) {
                    if ($kind[$y][$x] === 'data' && (self::maskFlips($usedMask, $x, $y) !== self::maskFlips($mask, $x, $y))) {
                        $trial[$y][$x] = ($trial[$y][$x] === false);
                    }
                }
            }
            $word = self::formatWord($level, $mask);
            for ($i = 0; $i < 15; $i++) {
                $on = ((($word >> $i) & 1) === 1);
                [$ax, $ay] = self::formatSquare($i, 1, $n);
                [$bx, $by] = self::formatSquare($i, 2, $n);
                $trial[$ay][$ax] = $on;
                $trial[$by][$bx] = $on;
            }
            $out[] = self::penaltyOf($trial);
        }
        return $out;
    }

    /** @param list<list<bool>> $g */
    private static function penaltyOf(array $g): int
    {
        $n     = count($g);
        $score = 0;
        $dark  = 0;

        // Every row, then every column, as a line of squares.
        $lines = [];
        for ($y = 0; $y < $n; $y++) {
            $lines[] = $g[$y];
            foreach ($g[$y] as $square) {
                if ($square === true) {
                    $dark++;
                }
            }
        }
        for ($x = 0; $x < $n; $x++) {
            $column = [];
            for ($y = 0; $y < $n; $y++) {
                $column[] = $g[$y][$x];
            }
            $lines[] = $column;
        }

        foreach ($lines as $line) {
            // Rule 1: five or more squares of one colour in a row.
            // The runs are kept for rule 3.
            $runs   = []; // [is dark, length]
            $colour = $line[0];
            $length = 0;
            foreach ($line as $square) {
                if ($square === $colour) {
                    $length++;
                    continue;
                }
                $runs[] = [$colour, $length];
                $colour = $square;
                $length = 1;
            }
            $runs[] = [$colour, $length];
            foreach ($runs as [, $runLength]) {
                if ($runLength >= 5) {
                    $score += 3 + ($runLength - 5);
                }
            }

            // Rule 3: anything shaped like a corner square (dark, light,
            // three dark, light, dark, in the proportions 1:1:3:1:1) with
            // four units of light on one side and at least one on the other.
            // The white border counts as light, so a line gains a long light
            // run at each end.
            if ($runs[0][0] === true) {
                array_unshift($runs, [false, 0]);
            }
            if ($runs[count($runs) - 1][0] === true) {
                $runs[] = [false, 0];
            }
            $runs[0][1] += $n;
            $runs[count($runs) - 1][1] += $n;
            $runCount = count($runs);
            for ($i = 3; $i + 3 < $runCount; $i++) {
                if ($runs[$i][0] !== true) {
                    continue;
                }
                $unit = $runs[$i - 2][1];
                if ($runs[$i][1] !== 3 * $unit || $runs[$i - 1][1] !== $unit || $runs[$i + 1][1] !== $unit || $runs[$i + 2][1] !== $unit) {
                    continue;
                }
                $before = $runs[$i - 3][1];
                $after  = $runs[$i + 3][1];
                if ($before >= 4 * $unit && $after >= $unit) {
                    $score += 40;
                }
                if ($after >= 4 * $unit && $before >= $unit) {
                    $score += 40;
                }
            }
        }

        // Rule 2: every two-by-two block of one colour.
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $g[$y][$x];
                if ($g[$y][$x + 1] === $c && $g[$y + 1][$x] === $c && $g[$y + 1][$x + 1] === $c) {
                    $score += 3;
                }
            }
        }

        // Rule 4: how far the share of dark squares is from half, in steps of
        // five in a hundred.
        $total = $n * $n;
        $steps = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        $score += $steps * 10;

        return $score;
    }

    // =========================================================================
    // 🖼️ From a picture to a grid of squares
    // =========================================================================

    /**
     * Read a fixture: lines of 0 and 1, one line a row, no white border.
     * Lines that begin with # are notes and are skipped.
     *
     * @return list<list<bool>>
     */
    public static function gridFromText(string $text): array
    {
        $grid = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^[01]+$/', $line) !== 1) {
                throw new QrTestReaderRefusal('A fixture row holds something other than 0 and 1.');
            }
            $row = [];
            foreach (str_split($line) as $ch) {
                $row[] = ($ch === '1');
            }
            $grid[] = $row;
        }
        return $grid;
    }

    /**
     * Find the QR code in a PNG picture, by its real pixels.
     *
     * @return array{grid:list<list<bool>>, cell:int, border:int, inverted:bool, width:int, height:int, contrast:float}
     *         See gridFromBrightness().
     */
    public static function gridFromPng(string $bytes): array
    {
        if (function_exists('imagecreatefromstring') === false) {
            throw new QrTestReaderRefusal('This PHP cannot open PNG pictures (the gd extension is not loaded).');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new QrTestReaderRefusal('The PNG could not be opened: it is not a picture.');
        }
        $width      = imagesx($image);
        $height     = imagesy($image);
        if ($width > 2100 || $height > 2200) {
            // Every pixel is looked at, and each costs memory. The portal
            // draws nothing wider than 2,048 pixels.
            throw new QrTestReaderRefusal('The PNG is ' . $width . ' by ' . $height . ' pixels, more than this test reader will look through.');
        }
        $trueColour = imageistruecolor($image);
        $brightness = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $pixel = (int) imagecolorat($image, $x, $y);
                if ($trueColour === true) {
                    $row[] = self::brightness(($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF);
                } else {
                    $c     = imagecolorsforindex($image, $pixel);
                    $row[] = self::brightness((int) $c['red'], (int) $c['green'], (int) $c['blue']);
                }
            }
            $brightness[] = $row;
        }
        return self::gridFromBrightness($brightness);
    }

    /**
     * Find the QR code in an SVG picture the portal drew.
     *
     * It understands exactly what `Portal\Core\Qr` writes: a `viewBox`, plain
     * rectangles, and one path made of small rectangles ("M x y h w v t h -w
     * z"). A caption (`<text>`) is ignored. Anything else is refused, so a
     * change to how the portal draws cannot slip past unread.
     *
     * @return array{grid:list<list<bool>>, cell:int, border:int, inverted:bool, width:int, height:int, contrast:float}
     */
    public static function gridFromSvg(string $svg): array
    {
        if (preg_match('/<svg\b[^>]*\bviewBox="0 0 (\d+) (\d+)"/', $svg, $m) !== 1) {
            throw new QrTestReaderRefusal('The SVG has no viewBox this reader understands.');
        }
        $width  = (int) $m[1];
        $height = (int) $m[2];
        if ($width < 21 || $height < 21 || $width > 4000 || $height > 4000) {
            throw new QrTestReaderRefusal('The SVG is ' . $width . ' by ' . $height . ' units, which cannot be a QR code.');
        }
        $canvas = array_fill(0, $height, array_fill(0, $width, 255.0));

        $paint = static function (int $x, int $y, int $w, int $h, float $value) use (&$canvas, $width, $height): void {
            if ($w <= 0 || $h <= 0 || $x < 0 || $y < 0 || $x + $w > $width || $y + $h > $height) {
                throw new QrTestReaderRefusal('The SVG draws a rectangle that is empty or outside the picture.');
            }
            for ($py = $y; $py < $y + $h; $py++) {
                for ($px = $x; $px < $x + $w; $px++) {
                    $canvas[$py][$px] = $value;
                }
            }
        };

        // Every element, in the order it is drawn.
        if (preg_match_all('/<(rect|path|text|svg|\/svg|\/text)\b([^>]*)>/', $svg, $tags, PREG_SET_ORDER) === false) {
            throw new QrTestReaderRefusal('The SVG could not be read.');
        }
        if (preg_match('/<(?!rect\b|path\b|text\b|svg\b|\/svg\b|\/text\b|\?xml\b)[a-zA-Z\/!]/', $svg) === 1) {
            throw new QrTestReaderRefusal('The SVG holds an element this reader does not know.');
        }
        foreach ($tags as $tag) {
            $name  = $tag[1];
            $attrs = $tag[2];
            if ($name === 'rect') {
                $fill = self::svgFill($attrs);
                if (preg_match('/\bwidth="100%"/', $attrs) === 1 && preg_match('/\bheight="100%"/', $attrs) === 1) {
                    $paint(0, 0, $width, $height, $fill);
                    continue;
                }
                $nums = [];
                foreach (['x', 'y', 'width', 'height'] as $key) {
                    if (preg_match('/\b' . $key . '="(\d+)"/', $attrs, $mm) !== 1) {
                        throw new QrTestReaderRefusal('The SVG has a rectangle without a whole-number ' . $key . '.');
                    }
                    $nums[$key] = (int) $mm[1];
                }
                $paint($nums['x'], $nums['y'], $nums['width'], $nums['height'], $fill);
                continue;
            }
            if ($name === 'path') {
                $fill = self::svgFill($attrs);
                if (preg_match('/\bd="([^"]*)"/', $attrs, $mm) !== 1) {
                    throw new QrTestReaderRefusal('The SVG has a path with nothing to draw.');
                }
                $d = trim($mm[1]);
                if ($d === '') {
                    continue;
                }
                $pattern = '/\GM(\d+) (\d+)h(\d+)v(\d+)h-(\d+)z\s*/';
                $offset  = 0;
                $length  = strlen($d);
                while ($offset < $length) {
                    if (preg_match($pattern, $d, $piece, 0, $offset) !== 1 || $piece[3] !== $piece[5]) {
                        throw new QrTestReaderRefusal('The SVG path holds a drawing command this reader does not know, near "' . substr($d, $offset, 24) . '".');
                    }
                    $paint((int) $piece[1], (int) $piece[2], (int) $piece[3], (int) $piece[4], $fill);
                    $offset += strlen($piece[0]);
                }
            }
        }
        return self::gridFromBrightness($canvas);
    }

    /** The brightness of the colour an SVG element is filled with. */
    private static function svgFill(string $attrs): float
    {
        if (preg_match('/\bfill="#([0-9A-Fa-f]{6})"/', $attrs, $m) !== 1) {
            throw new QrTestReaderRefusal('The SVG has a shape whose colour is not written as six hex digits.');
        }
        return self::brightness((int) hexdec(substr($m[1], 0, 2)), (int) hexdec(substr($m[1], 2, 2)), (int) hexdec(substr($m[1], 4, 2)));
    }

    /** How bright a colour looks, 0 (black) to 255 (white). */
    private static function brightness(int $red, int $green, int $blue): float
    {
        return 0.299 * $red + 0.587 * $green + 0.114 * $blue;
    }

    /**
     * Find the QR code in a grid of brightness values (one per pixel).
     *
     * The picture's own corner tells this which colour is the background.
     * Everything clearly on the other side of half-way is "ink". When no QR
     * code can be found that way, the other colour is tried as the
     * background: a picture drawn with NO clear border has a corner square
     * in its corner, so its corner pixel is ink. Such a picture is then
     * found, with a border of 0, and the caller can refuse it for that.
     *
     * @param list<list<float>> $b brightness, 0 to 255, row by row
     *
     * @return array{grid:list<list<bool>>, cell:int, border:int, inverted:bool, width:int, height:int, contrast:float}
     *         grid      the squares, with no border; true is ink
     *         cell      how many pixels wide one square is
     *         border    the clear border all round, in whole squares (the
     *                   standard asks for four); counted up to 16
     *         inverted  true when the ink is LIGHTER than the background.
     *                   Many readers refuse such a picture.
     *         width, height  the whole picture, in pixels
     *         contrast  the gap between the darkest and lightest pixel, of 255
     *
     * @throws QrTestReaderRefusal when no clean QR code is found.
     */
    public static function gridFromBrightness(array $b): array
    {
        $height = count($b);
        $width  = ($height > 0) ? count($b[0]) : 0;
        if ($height < 21 || $width < 21) {
            throw new QrTestReaderRefusal('The picture is ' . $width . ' by ' . $height . ' pixels: too small to hold a QR code.');
        }
        $low  = 255.0;
        $high = 0.0;
        foreach ($b as $row) {
            $low  = min($low, min($row));
            $high = max($high, max($row));
        }
        if ($high - $low < 1.0) {
            throw new QrTestReaderRefusal('The picture is one flat colour. Nothing is drawn in it.');
        }
        $middle      = ($low + $high) / 2;
        $cornerLight = ($b[0][0] > $middle);
        $firstNo     = null;
        foreach ([$cornerLight, ($cornerLight === false)] as $paperIsLight) {
            $ink = [];
            for ($y = 0; $y < $height; $y++) {
                $line = '';
                foreach ($b[$y] as $value) {
                    $line .= ((($value > $middle) !== $paperIsLight)) ? '1' : '0';
                }
                $ink[] = $line;
            }
            try {
                return self::gridFromInk($ink, $width, $height) + ['inverted' => ($paperIsLight === false), 'width' => $width, 'height' => $height, 'contrast' => $high - $low];
            } catch (QrTestReaderRefusal $no) {
                // Keep the FIRST reason: it is about the picture as its own
                // corner says it should be read.
                $firstNo = $firstNo ?? $no;
            }
        }
        throw $firstNo;
    }

    /**
     * Find the QR code among pixels already sorted into ink ("1") and
     * background ("0"), one string a row.
     *
     * @param list<string> $ink
     *
     * @return array{grid:list<list<bool>>, cell:int, border:int}
     *
     * @throws QrTestReaderRefusal
     */
    private static function gridFromInk(array $ink, int $width, int $height): array
    {

        // The first ink, reading from the top: the top-left corner square.
        $top  = -1;
        $left = -1;
        for ($y = 0; $y < $height; $y++) {
            $at = strpos($ink[$y], '1');
            if ($at !== false) {
                $top  = $y;
                $left = $at;
                break;
            }
        }
        if ($top < 0) {
            throw new QrTestReaderRefusal('No ink was found in the picture.');
        }
        // The top edge of that corner square is seven squares of ink.
        $run = strspn($ink[$top], '1', $left);
        if ($run % 7 !== 0) {
            throw new QrTestReaderRefusal('The top edge of the top-left corner square is ' . $run . ' pixels, which is not seven equal squares.');
        }
        $cell  = intdiv($run, 7);
        $right = strrpos($ink[$top], '1');
        $span  = $right - $left + 1;
        if ($span % $cell !== 0) {
            throw new QrTestReaderRefusal('The picture is not a whole number of squares wide.');
        }
        $n = intdiv($span, $cell);
        if ($n < 21 || $n > 177 || ($n - 17) % 4 !== 0) {
            throw new QrTestReaderRefusal('The picture is ' . $n . ' squares across, and no QR code is that size.');
        }
        if ($top + $n * $cell > $height) {
            throw new QrTestReaderRefusal('The picture is cut off at the bottom.');
        }

        // Every square must be ONE flat colour, every pixel of it.
        $grid = [];
        $full = str_repeat('1', $cell);
        $none = str_repeat('0', $cell);
        for ($my = 0; $my < $n; $my++) {
            $row = array_fill(0, $n, false);
            for ($py = 0; $py < $cell; $py++) {
                $line = $ink[$top + $my * $cell + $py];
                for ($mx = 0; $mx < $n; $mx++) {
                    $piece = substr($line, $left + $mx * $cell, $cell);
                    if ($piece !== $full && $piece !== $none) {
                        throw new QrTestReaderRefusal('The square at column ' . $mx . ', row ' . $my . ' is not one flat colour.');
                    }
                    $isInk = ($piece === $full);
                    if ($py === 0) {
                        $row[$mx] = $isInk;
                    } elseif ($row[$mx] !== $isInk) {
                        throw new QrTestReaderRefusal('The square at column ' . $mx . ', row ' . $my . ' is not one flat colour.');
                    }
                }
            }
            $grid[] = $row;
        }

        // The clear border: how many whole squares of background lie all round.
        $border = 0;
        for ($q = 1; $q <= 16; $q++) {
            $x0 = $left - $q * $cell;
            $y0 = $top - $q * $cell;
            $x1 = $left + ($n + $q) * $cell; // one past the last column
            $y1 = $top + ($n + $q) * $cell;  // one past the last row
            if ($x0 < 0 || $y0 < 0 || $x1 > $width || $y1 > $height) {
                break;
            }
            $clear = true;
            for ($y = $y0; $y < $y1 && $clear === true; $y++) {
                $inBand = ($y < $y0 + $cell || $y >= $y1 - $cell);
                if ($inBand === true) {
                    if (strpos(substr($ink[$y], $x0, $x1 - $x0), '1') !== false) {
                        $clear = false;
                    }
                } elseif (strpos(substr($ink[$y], $x0, $cell), '1') !== false || strpos(substr($ink[$y], $x1 - $cell, $cell), '1') !== false) {
                    $clear = false;
                }
            }
            if ($clear === false) {
                break;
            }
            $border = $q;
        }

        return ['grid' => $grid, 'cell' => $cell, 'border' => $border];
    }

    /**
     * Read a picture the portal made, whichever kind it is, and insist on the
     * white border the standard asks for.
     *
     * @param array{mime:string, bytes:string} $picture what Qr::generate() returns
     *
     * @return array{text:string, version:int, level:string, mask:int, penalties:list<int>, cell:int, border:int, inverted:bool, width:int, height:int, contrast:float}
     *
     * @throws QrTestReaderRefusal
     */
    public static function readPicture(array $picture, int $borderNeeded = 4): array
    {
        $mime = (string) ($picture['mime'] ?? '');
        if ($mime === 'image/png') {
            $found = self::gridFromPng((string) ($picture['bytes'] ?? ''));
        } elseif ($mime === 'image/svg+xml') {
            $found = self::gridFromSvg((string) ($picture['bytes'] ?? ''));
        } else {
            throw new QrTestReaderRefusal('The picture is of a kind this reader does not read: "' . $mime . '".');
        }
        if ($found['border'] < $borderNeeded) {
            throw new QrTestReaderRefusal('The clear border round the picture is ' . $found['border'] . ' squares wide. The standard asks for ' . $borderNeeded . ', and many readers find nothing without it.');
        }
        if ($found['inverted'] === true) {
            throw new QrTestReaderRefusal('The picture is light squares on a dark background. Many readers refuse that.');
        }
        $read = self::readGrid($found['grid']);
        unset($found['grid']);
        return $read + $found;
    }
}
