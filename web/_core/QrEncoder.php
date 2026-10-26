<?php
// Path: _core/QrEncoder.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — The squares of a QR code (internal to Portal\Core\Qr) 🔳
 * -----------------------------------------------------------------------------
 * Works out which squares of a QR code are dark for a piece of text. It draws
 * nothing: `Portal\Core\Qr` turns the squares into a picture, and is the ONLY
 * thing that may use this class. Everything else calls `Qr`.
 *
 * It follows the QR standard, ISO/IEC 18004 ("QR Code Model 2"): every size
 * from 1 (21 squares across) to 40 (177 across), and all four levels of error
 * correction (L, M, Q and H: how much of a damaged label can still be read).
 * It adds no library and fetches nothing, because the portal runs on shared
 * hosting with no Composer.
 *
 * WHAT WAS WRONG (#623, found 13 October 2026, repaired 14 October 2026)
 * --------------------------------------------------------------------
 * No reader could read a picture this class made, from the day it was written
 * in July 2026.
 *
 * 1. THE FAULT THAT STOPPED EVERY READER. Fifteen squares beside the corners
 *    (the "format marks") tell a reader which level and which mask the rest
 *    was written with. They were written back to front: the first square got
 *    the last bit. A reader looks at those squares before anything else, finds
 *    a value the standard does not allow, and gives up. Everything else in the
 *    picture was right. It was proved by changing that ONE line in a copy:
 *    macOS's own reader then read every picture, and read none before.
 *    The line was `($combined >> (14 - $i)) & 1`; see writeFormatMarks().
 * 2. Text over 213 bytes stopped the page with an error. The code meant to cut
 *    the text short and draw the rest, which would have been worse: a picture
 *    that leads somewhere other than where it was asked to. Now the sizes go
 *    up to 40, and text too long even for that is refused in plain words.
 * 3. The level a caller asked for was ignored: every picture was level M.
 * 4. The mask was always the first of the eight. The standard tells a drawer
 *    to try all eight and keep the one that is easiest to read. Readers cope
 *    with any of them, so this stopped nobody, but it is done properly now.
 *
 * (The picture also had no clear border round it, and a small PNG could come
 * out blank. Those two were in Qr.php and are recorded there.)
 *
 * TRIED AND REJECTED: keeping the top size at 10 and refusing longer text.
 * The address an authenticator app scans on the two-factor set-up page is
 * over 213 bytes for an organisation with a long name, so that page would
 * have shown no picture to exactly the people with the longest names.
 *
 * WHAT IT CANNOT DO
 * -----------------
 * - It writes text one byte at a time (the standard's "byte mode"). It does
 *   not use the shorter ways of writing digits or capital letters, so a
 *   picture of digits alone is a little bigger than it could be.
 * - It does not say which alphabet the bytes are in. The portal's text is
 *   UTF-8 and today's readers take it as that, but the standard lets a reader
 *   assume Latin-1, so an old reader could show accented or non-Latin letters
 *   wrongly. A plain web address is not affected.
 * - The longest text is 2,953 bytes at level L, 2,331 at M, 1,663 at Q and
 *   1,273 at H. Longer text is refused, never cut short.
 * - It cannot make a picture easy to scan. A long text makes a picture with
 *   many small squares; drawn small or printed small, a camera may not make
 *   them out. Keep what goes into a QR code short.
 *
 * HOW IT IS CHECKED: `tools/qr-selftest.php` reads this class's pictures back
 * with a reader of its own that was first proved on pictures from a different
 * program (`qrencode`).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @internal  Use Portal\Core\Qr instead of calling this directly.
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/275
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/623
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class QrEncoder
{
    /**
     * 📋 Error-correction words in each block, by level and by size (1 to 40;
     * place 0 is unused). From Table 9 of the standard.
     *
     * These numbers, and the block counts below, were each checked against
     * the `qrencode` program at every size and level on 14 October 2026
     * (`tools/qr-selftest.php --with-qrencode` runs that check again). A
     * wrong number here makes a picture no reader can read, at that one size
     * and level only, so do not change one without running it.
     */
    private const EC_WORDS_PER_BLOCK = [
        'L' => [0, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'M' => [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        'Q' => [0, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'H' => [0, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    /** 📋 How many blocks the words are split into, by level and by size. */
    private const BLOCK_COUNT = [
        'L' => [0, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        'M' => [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        'Q' => [0, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        'H' => [0, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    /** The two bits that name each level in the format marks (not in the order L, M, Q, H). */
    private const LEVEL_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    /** The biggest size the standard has. */
    private const LARGEST_SIZE = 40;

    /** @var int[] Powers of 2 in the field of 256 values the error correction is worked in. */
    private array $gfExp;
    /** @var int[] The other way round: which power gives a value. */
    private array $gfLog;

    /** @var array<int, int[]> The divider for each count of error-correction words, kept once made. */
    private array $dividers = [];

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
     * Make a level safe to use: L, M, Q or H in either case. Anything else
     * counts as M, the level every picture had before a level could be chosen.
     */
    public static function cleanLevel(string $level): string
    {
        $level = strtoupper(trim($level));
        return (isset(self::LEVEL_BITS[$level]) === true) ? $level : 'M';
    }

    /** The longest text, in bytes, that can be drawn at a level. */
    public static function longestText(string $level): int
    {
        return self::capacity(self::LARGEST_SIZE, self::cleanLevel($level));
    }

    /**
     * Work out the squares for a piece of text.
     *
     * @param string $content The text, as the bytes it is made of.
     * @param string $level   L, M, Q or H (see cleanLevel()).
     *
     * @return array<int, array<int, bool>> Rows of squares, true for dark.
     *         There is NO clear border in it: Qr adds that when it draws.
     *
     * @throws \LengthException When the text is too long for the biggest QR
     *         code. The message is plain words, fit to show to a person.
     */
    public function encode(string $content, string $level = 'M'): array
    {
        $level  = self::cleanLevel($level);
        $length = strlen($content);

        // 📏 The smallest size that holds the text.
        $version = 0;
        for ($v = 1; $v <= self::LARGEST_SIZE; $v++) {
            if ($length <= self::capacity($v, $level)) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            // This used to cut the text short and draw the rest. A QR code
            // that leads somewhere other than where it was asked to is worse
            // than none, so it is refused instead.
            throw new \LengthException(
                'This text is too long for a QR code. It is ' . number_format($length) . ' bytes, and the most that fits is '
                . number_format(self::longestText($level)) . ' bytes at error-correction level ' . $level . '. Use a shorter text or address.'
            );
        }

        $n          = 17 + 4 * $version;
        $totalWords = intdiv(self::dataSquareCount($version), 8);
        $ecLen      = self::EC_WORDS_PER_BLOCK[$level][$version];
        $blockCount = self::BLOCK_COUNT[$level][$version];
        $room       = ($totalWords - $ecLen * $blockCount) * 8; // bits for the message

        // ✍️ The message as bits: "this is 8-bit text", how long it is, the
        //    text, an end mark, then filler up to the room there is.
        $bits = '0100' . str_pad(decbin($length), ($version <= 9) ? 8 : 16, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($content[$i])), 8, '0', STR_PAD_LEFT);
        }
        // The end mark is four empty bits, or fewer when the room runs out
        // first. capacity() has already made sure the text itself fits, so
        // the count here is never below nothing (it once was: see point 2 of
        // the header).
        $bits .= str_repeat('0', min(4, $room - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $filler = ['11101100', '00010001'];
        for ($turn = 0; strlen($bits) < $room; $turn++) {
            $bits .= $filler[$turn % 2];
        }
        $words = [];
        foreach (str_split($bits, 8) as $eight) {
            $words[] = (int) bindec($eight);
        }

        // 🧩 Split the words into blocks and work out each block's
        //    error-correction words. When the blocks cannot all be the same
        //    length, the LAST ones are one word longer.
        $shortLen   = intdiv($totalWords, $blockCount);
        $shortCount = $blockCount - ($totalWords % $blockCount);
        $dataBlocks = [];
        $ecBlocks   = [];
        $at         = 0;
        for ($b = 0; $b < $blockCount; $b++) {
            $blockLen     = $shortLen - $ecLen + (($b >= $shortCount) ? 1 : 0);
            $block        = array_slice($words, $at, $blockLen);
            $at          += $blockLen;
            $dataBlocks[] = $block;
            $ecBlocks[]   = $this->errorCorrectionWords($block, $ecLen);
        }

        // 🃏 Deal the words out like cards: the first word of every block,
        //    then the second of every block, and so on. Damage in one place
        //    is then shared between the blocks instead of ruining one.
        $final = [];
        for ($i = 0; $i <= $shortLen - $ecLen; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i]) === true) {
                    $final[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($ecBlocks as $block) {
                $final[] = $block[$i];
            }
        }

        // 🧱 The fixed squares, which are the same for every text of this
        //    size. $fixed remembers which squares they are, so that the
        //    message and the mask leave them alone.
        $squares = array_fill(0, $n, array_fill(0, $n, 0));
        $fixed   = array_fill(0, $n, array_fill(0, $n, false));
        $this->placeCornerSquares($squares, $fixed, $n);
        $this->placeTimingLines($squares, $fixed, $n);
        $this->placeAlignmentSquares($squares, $fixed, $n, $version);
        $squares[$n - 8][8] = 1; // one square that is always dark
        $fixed[$n - 8][8]   = true;
        $this->keepRoomForFormatMarks($fixed, $n);
        if ($version >= 7) {
            $this->writeSizeMarks($squares, $fixed, $n, $version);
        }

        $this->placeMessage($squares, $fixed, $n, $final);

        // 🎭 The mask. A message can happen to make big blank patches, or
        //    shapes that look like the corner squares, and a reader loses its
        //    place on those. So the message squares are mixed with one of
        //    eight fixed patterns; the format marks say which, and the reader
        //    takes it off again. The standard says to try all eight and keep
        //    the one with the lowest count of awkward shapes. This used to
        //    use the first pattern every time.
        $best      = [];
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $trial = $squares;
            $this->applyMask($trial, $fixed, $n, $mask);
            $this->writeFormatMarks($trial, $n, $level, $mask);
            $score = $this->awkwardness($trial, $n);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best      = $trial;
            }
        }

        $out = [];
        foreach ($best as $row) {
            $line = [];
            foreach ($row as $square) {
                $line[] = ($square === 1);
            }
            $out[] = $line;
        }
        return $out;
    }

    // =========================================================================
    // 📏 Sizes and room
    // =========================================================================

    /** The longest 8-bit text, in bytes, that a size holds at a level. */
    private static function capacity(int $version, string $level): int
    {
        $dataWords = intdiv(self::dataSquareCount($version), 8)
            - self::EC_WORDS_PER_BLOCK[$level][$version] * self::BLOCK_COUNT[$level][$version];
        // Four bits say "8-bit text". The length then takes 8 bits up to
        // size 9, and 16 bits from size 10 (section 7.4.1 of the standard).
        $headBits = 4 + (($version <= 9) ? 8 : 16);
        return intdiv($dataWords * 8 - $headBits, 8);
    }

    /** How many squares of a size are left for the message and its error correction. */
    private static function dataSquareCount(int $version): int
    {
        $count = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $alignments = intdiv($version, 7) + 2;
            $count -= (25 * $alignments - 10) * $alignments - 55;
            if ($version >= 7) {
                $count -= 36; // the two sets of size marks
            }
        }
        return $count;
    }

    /**
     * Where the centres of the small alignment squares are for a size, along
     * one edge. The same list serves across and down.
     *
     * @return int[]
     */
    private static function alignmentCentres(int $version): array
    {
        if ($version === 1) {
            return [];
        }
        $count = intdiv($version, 7) + 2;
        // Evenly spaced, in steps of a whole even number. Size 32 is the one
        // size where the standard's table does not follow that sum.
        $step = ($version === 32) ? 26 : intdiv($version * 4 + $count * 2 + 1, $count * 2 - 2) * 2;
        $out  = [];
        $at   = 17 + 4 * $version - 7;
        for ($i = 0; $i < $count - 1; $i++) {
            array_unshift($out, $at);
            $at -= $step;
        }
        array_unshift($out, 6);
        return $out;
    }

    // =========================================================================
    // 🧮 Error correction (Reed-Solomon)
    // =========================================================================

    /**
     * The error-correction words for one block of data.
     *
     * @param int[] $data
     *
     * @return int[]
     */
    private function errorCorrectionWords(array $data, int $count): array
    {
        if (isset($this->dividers[$count]) === false) {
            // The divider: (x - 1)(x - 2)(x - 4)..., $count factors long.
            $divider = [1];
            for ($i = 0; $i < $count; $i++) {
                $divider = $this->multiplyPolynomials($divider, [1, $this->gfExp[$i]]);
            }
            $this->dividers[$count] = $divider;
        }
        $divider = $this->dividers[$count];

        // Long division; what is left over at the end is the answer.
        $rest      = array_merge($data, array_fill(0, $count, 0));
        $dataCount = count($data);
        for ($i = 0; $i < $dataCount; $i++) {
            $lead = $rest[$i];
            if ($lead === 0) {
                continue;
            }
            $leadLog = $this->gfLog[$lead];
            foreach ($divider as $j => $coefficient) {
                // A coefficient of nothing has no logarithm. The table gives
                // 0 for it, which would be read as "times one". None of the
                // dividers a QR code uses has one, but skip it all the same.
                if ($coefficient !== 0) {
                    $rest[$i + $j] ^= $this->gfExp[$this->gfLog[$coefficient] + $leadLog];
                }
            }
        }
        return array_slice($rest, $dataCount);
    }

    /**
     * @param int[] $a
     * @param int[] $b
     *
     * @return int[]
     */
    private function multiplyPolynomials(array $a, array $b): array
    {
        $result = array_fill(0, count($a) + count($b) - 1, 0);
        foreach ($a as $i => $ac) {
            if ($ac === 0) {
                continue;
            }
            foreach ($b as $j => $bc) {
                if ($bc === 0) {
                    continue;
                }
                $result[$i + $j] ^= $this->gfExp[$this->gfLog[$ac] + $this->gfLog[$bc]];
            }
        }
        return $result;
    }

    // =========================================================================
    // 🧱 The fixed squares
    // =========================================================================

    /** The three big corner squares, each with a light edge one square wide. */
    private function placeCornerSquares(array &$squares, array &$fixed, int $n): void
    {
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$left, $top]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $x = $left + $dx;
                    $y = $top + $dy;
                    if ($x < 0 || $y < 0 || $x >= $n || $y >= $n) {
                        continue;
                    }
                    $dark = ($dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6) && (
                        $dx === 0 || $dx === 6 || $dy === 0 || $dy === 6
                        || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4)
                    );
                    $squares[$y][$x] = ($dark === true) ? 1 : 0;
                    $fixed[$y][$x]   = true;
                }
            }
        }
    }

    /** The two dotted lines between the corner squares: dark, light, dark, light. */
    private function placeTimingLines(array &$squares, array &$fixed, int $n): void
    {
        for ($i = 8; $i < $n - 8; $i++) {
            $bit             = (($i % 2) === 0) ? 1 : 0;
            $squares[6][$i]  = $bit;
            $squares[$i][6]  = $bit;
            $fixed[6][$i]    = true;
            $fixed[$i][6]    = true;
        }
    }

    /** The small squares that let a reader keep its place across a big picture. */
    private function placeAlignmentSquares(array &$squares, array &$fixed, int $n, int $version): void
    {
        $centres = self::alignmentCentres($version);
        foreach ($centres as $cy) {
            foreach ($centres as $cx) {
                // None where a corner square already is.
                if (($cx === 6 && $cy === 6) || ($cx === $n - 7 && $cy === 6) || ($cx === 6 && $cy === $n - 7)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $dark = (abs($dx) === 2 || abs($dy) === 2 || ($dx === 0 && $dy === 0));
                        $squares[$cy + $dy][$cx + $dx] = ($dark === true) ? 1 : 0;
                        $fixed[$cy + $dy][$cx + $dx]   = true;
                    }
                }
            }
        }
    }

    /** Keep the message out of the squares the format marks will be written in. */
    private function keepRoomForFormatMarks(array &$fixed, int $n): void
    {
        for ($i = 0; $i < 9; $i++) {
            $fixed[8][$i] = true;
            $fixed[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $fixed[8][$n - 1 - $i] = true;
            $fixed[$n - 1 - $i][8] = true;
        }
    }

    /**
     * 🏷️ Write the fifteen format marks, twice: which level, and which mask.
     *
     * THE ORDER IS THE WHOLE POINT. Square number $i gets bit number $i,
     * counting bits from the LOW end. This used to take `14 - $i`, the bit
     * counted from the other end, so the marks were written back to front and
     * no reader could read any picture (#623). Figure 25 of the standard shows
     * the order; `tools/qr-selftest.php` fails if it is turned round again.
     */
    private function writeFormatMarks(array &$squares, int $n, string $level, int $mask): void
    {
        // Five bits of meaning, then ten check bits, then a fixed pattern
        // mixed in so that the marks are never all light.
        $five = (self::LEVEL_BITS[$level] << 3) | $mask;
        $rest = $five << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($rest & (1 << $i)) !== 0) {
                $rest ^= 0x537 << ($i - 10);
            }
        }
        $word = (($five << 10) | $rest) ^ 0x5412;

        for ($i = 0; $i < 15; $i++) {
            $bit = ($word >> $i) & 1;
            // First copy: round the top-left corner square.
            if ($i < 6) {
                $squares[$i][8] = $bit;
            } elseif ($i < 8) {
                $squares[$i + 1][8] = $bit; // steps over the timing line
            } elseif ($i === 8) {
                $squares[8][7] = $bit;
            } else {
                $squares[8][14 - $i] = $bit;
            }
            // Second copy: under the top-right corner square, then beside
            // the bottom-left one.
            if ($i < 8) {
                $squares[8][$n - 1 - $i] = $bit;
            } else {
                $squares[$n - 15 + $i][8] = $bit;
            }
        }
    }

    /** The size marks: eighteen squares, twice, on pictures of size 7 and above. */
    private function writeSizeMarks(array &$squares, array &$fixed, int $n, int $version): void
    {
        $rest = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if (($rest & (1 << $i)) !== 0) {
                $rest ^= 0x1F25 << ($i - 12);
            }
        }
        $word = ($version << 12) | $rest;

        for ($i = 0; $i < 18; $i++) {
            $bit = ($word >> $i) & 1;
            $a   = intdiv($i, 3);
            $b   = $n - 11 + ($i % 3);
            $squares[$b][$a] = $bit;
            $squares[$a][$b] = $bit;
            $fixed[$b][$a]   = true;
            $fixed[$a][$b]   = true;
        }
    }

    // =========================================================================
    // 🧵 The message, and the mask
    // =========================================================================

    /**
     * Lay the words into the free squares: two columns at a time from the
     * right-hand edge, up the first pair, down the next, and so on.
     *
     * @param int[] $words
     */
    private function placeMessage(array &$squares, array $fixed, int $n, array $words): void
    {
        $stream = '';
        foreach ($words as $word) {
            $stream .= str_pad(decbin($word), 8, '0', STR_PAD_LEFT);
        }
        $length = strlen($stream);
        $at     = 0;
        $upward = true;
        $column = $n - 1;
        while ($column > 0) {
            if ($column === 6) {
                $column--; // the upright timing line takes a whole column
            }
            for ($step = 0; $step < $n; $step++) {
                $y = ($upward === true) ? $n - 1 - $step : $step;
                for ($side = 0; $side < 2; $side++) {
                    $x = $column - $side;
                    if ($fixed[$y][$x] === true) {
                        continue;
                    }
                    // Up to seven squares are left over after the last word.
                    // They stay empty.
                    $squares[$y][$x] = ($at < $length && $stream[$at] === '1') ? 1 : 0;
                    $at++;
                }
            }
            $upward  = ($upward === false);
            $column -= 2;
        }
    }

    /** Turn over every free square that pattern number $mask picks out. */
    private function applyMask(array &$squares, array $fixed, int $n, int $mask): void
    {
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($fixed[$y][$x] === true) {
                    continue;
                }
                switch ($mask) {
                    case 0:
                        $turn = (($x + $y) % 2) === 0;
                        break;
                    case 1:
                        $turn = ($y % 2) === 0;
                        break;
                    case 2:
                        $turn = ($x % 3) === 0;
                        break;
                    case 3:
                        $turn = (($x + $y) % 3) === 0;
                        break;
                    case 4:
                        $turn = ((intdiv($x, 3) + intdiv($y, 2)) % 2) === 0;
                        break;
                    case 5:
                        $turn = ((($x * $y) % 2) + (($x * $y) % 3)) === 0;
                        break;
                    case 6:
                        $turn = (((($x * $y) % 2) + (($x * $y) % 3)) % 2) === 0;
                        break;
                    default:
                        $turn = (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0;
                        break;
                }
                if ($turn === true) {
                    $squares[$y][$x] ^= 1;
                }
            }
        }
    }

    /**
     * ⚖️ Count the shapes in a finished picture that a reader finds awkward,
     * by the four rules of section 7.8.3 of the standard. Lower is better.
     *
     * WHAT IT CANNOT DO: promise the same choice as another program. The
     * standard's third rule can be read more than one way, and `qrencode`
     * chooses a different mask about one time in four. Every choice is a
     * picture readers read; only the count differs.
     */
    private function awkwardness(array $squares, int $n): int
    {
        $score = 0;

        // Rule 1 (five or more of one colour in a line) and rule 3 (anything
        // shaped like a corner square: dark, light, three dark, light, dark,
        // with a wide light gap on one side). Rows first, then columns.
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $colour = 0;
                $run    = 0;
                // The last seven runs, newest first. The white border round
                // the picture counts as light, so the first and last runs of
                // every line are lengthened by a whole picture's width.
                $runs = [0, 0, 0, 0, 0, 0, 0];
                for ($b = 0; $b < $n; $b++) {
                    $square = ($pass === 0) ? $squares[$a][$b] : $squares[$b][$a];
                    if ($square === $colour) {
                        $run++;
                        if ($run === 5) {
                            $score += 3;
                        } elseif ($run > 5) {
                            $score++;
                        }
                        continue;
                    }
                    if ($runs[0] === 0) {
                        $run += $n;
                    }
                    $runs = [$run, $runs[0], $runs[1], $runs[2], $runs[3], $runs[4], $runs[5]];
                    if ($colour === 0) {
                        $score += self::cornerLookalikes($runs) * 40;
                    }
                    $colour = $square;
                    $run    = 1;
                }
                if ($colour === 1) {
                    if ($runs[0] === 0) {
                        $run += $n;
                    }
                    $runs = [$run, $runs[0], $runs[1], $runs[2], $runs[3], $runs[4], $runs[5]];
                    $run  = 0;
                }
                $run += $n;
                if ($runs[0] === 0) {
                    $run += $n;
                }
                $runs   = [$run, $runs[0], $runs[1], $runs[2], $runs[3], $runs[4], $runs[5]];
                $score += self::cornerLookalikes($runs) * 40;
            }
        }

        // Rule 2: every block of two by two in one colour.
        // Rule 4 needs the count of dark squares; it is taken in the same pass.
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            $row  = $squares[$y];
            $next = ($y + 1 < $n) ? $squares[$y + 1] : null;
            for ($x = 0; $x < $n; $x++) {
                $c = $row[$x];
                $dark += $c;
                if ($next !== null && $x + 1 < $n && $row[$x + 1] === $c && $next[$x] === $c && $next[$x + 1] === $c) {
                    $score += 3;
                }
            }
        }

        // Rule 4: how far the share of dark squares is from a half, counted
        // in steps of five in a hundred.
        $total  = $n * $n;
        $score += (intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1) * 10;

        return $score;
    }

    /**
     * Do the last runs of a line make the shape of a corner square? Called
     * when a light run has just ended. $runs[1] to $runs[5] are the five runs
     * before it (dark, light, dark, light, dark), and $runs[6] the light run
     * before those.
     *
     * @param int[] $runs
     *
     * @return int 0, 1 or 2: one for each side that has the wide light gap.
     */
    private static function cornerLookalikes(array $runs): int
    {
        $unit = $runs[1];
        if ($unit <= 0 || $runs[2] !== $unit || $runs[3] !== $unit * 3 || $runs[4] !== $unit || $runs[5] !== $unit) {
            return 0;
        }
        return (($runs[0] >= $unit * 4 && $runs[6] >= $unit) ? 1 : 0)
            + (($runs[6] >= $unit * 4 && $runs[0] >= $unit) ? 1 : 0);
    }
}
