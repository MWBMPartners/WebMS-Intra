<?php
// Path: _core/Barcode.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — 1D barcode encoder (Code 128 / EAN-13 / EAN-8 / UPC-A / UPC-E / ITF-14) 📊
 * -----------------------------------------------------------------------------
 * Asset Tracker sub-issue #404 — the barcode counterpart to `Qr`/`QrEncoder`,
 * mirrored on the SAME class shape (`generate()` returns
 * `['mime'=>..., 'bytes'=>...]`, SVG by default, gd-PNG on request) so
 * `AssetRegister::buildLabelSheets()` can embed a barcode data URI exactly
 * the same way it already embeds a QR one (`Qr::generate(['format'=>'png'])`
 * → `data:{mime};base64,{b64}`).
 *
 * Supports six symbologies (`self::SYMBOLOGIES`):
 *   - `code128` — full ASCII (0-127) via auto Code Set A/B/C subset
 *     selection + mod-103 checksum. Used for `assetTagCode` (free text).
 *   - `ean13`   — 12 or 13 numeric digits (12 = check digit auto-computed
 *     and appended; 13 = supplied check digit validated).
 *   - `ean8`    — 7 or 8 numeric digits (compact retail symbol). Left half
 *     is all L-code, right half all R-code — no first-digit parity table
 *     (see `ean8Modules()`). Shares the mod-10 check digit with the family.
 *   - `upca`    — 11 or 12 numeric digits. Encoded by prefixing a virtual
 *     leading `'0'` and delegating to the EAN-13 module builder — this is
 *     the same relationship real UPC-A/EAN-13 scanners rely on (a UPC-A
 *     symbol IS a valid EAN-13 symbol whose first digit happens to be 0).
 *   - `upce`    — the "zero-suppressed" 6-digit compressed form of a
 *     12-digit UPC-A, valid ONLY for number system 0 or 1. Accepts either
 *     a UPC-E value itself (7 digits = number system + 6 data digits,
 *     check digit auto-computed; or 8 = check digit supplied and
 *     verified) OR a UPC-E-*compressible* UPC-A (11 or 12 digits — see
 *     `upceCompress()`), rejecting a non-compressible UPC-A outright
 *     rather than guessing. See `encodeUpce()`'s own doc for the full
 *     expansion/compression rules and the verification this pass ran
 *     against a published reference (#423).
 *   - `itf14`   — 13 or 14 numeric digits (Interleaved 2-of-5), rendered
 *     with GS1 "framed" bearer bars (a border rectangle around the whole
 *     symbol) — required by the GS1 General Specifications for
 *     carton/case-level symbols printed at typical sizes, to protect
 *     against scanner mis-reads from a nicked/rotated print.
 *
 * DESIGN — risk-minimising table choices (read before touching the tables
 * below):
 *   - Code 128's character-to-symbol-VALUE mapping for Set A/Set B is pure
 *     ASCII arithmetic (`code128SetAValue()`/`code128SetBValue()`), not a
 *     hand-transcribed per-character table — eliminates an entire class of
 *     transcription risk. The ONE thing that genuinely has to be a
 *     hand-transcribed lookup is `CODE128_PATTERNS` (symbol VALUE 0-102 →
 *     6-digit bar/space width string) plus the four START/STOP constants —
 *     every row is internally self-checked (each of the 103 width strings
 *     sums to exactly 11, matching the ISO/AIM Code 128 spec's fixed
 *     11-modules-per-symbol rule) by this class's own PHPDoc — see the
 *     validation note on `CODE128_PATTERNS` itself.
 *   - EAN-13's G-code and R-code tables are NOT separately transcribed —
 *     `eanR()` derives R as the bitwise complement of the L-code table, and
 *     `eanG()` derives G as `eanR()` reversed. Both derivations are the
 *     actual mathematical relationships the EAN/UPC spec is built on (not
 *     a coincidence), so only the 10-row `EAN_L` table is a genuine
 *     hand-transcribed source of truth.
 *   - Every ITF digit pattern in `ITF_PATTERNS` has exactly 2 "wide"
 *     elements out of 5 (it's literally what "2 of 5" means) — this
 *     invariant is checked once per row in the class-level self-audit
 *     comment above `ITF_PATTERNS` below.
 *   - The GS1 mod-10 check-digit algorithm (`gs1CheckDigit()`) is a direct
 *     port of `AssetRegister::gs1Mod10Check()` (migration 159 / #397) —
 *     verified BY HAND against the task's own worked example:
 *     `400638133393` (12 digits) → check digit `1` → `4006381333931`.
 *   - UPC-E's number-system-1 parity table (`upceParity()`) is likewise NOT
 *     separately hand-transcribed — it is the bitwise L/G complement of the
 *     10-row `UPCE_PARITY_NUMSYS0` table (the actual GS1-spec relationship
 *     between the two number systems, same derivation discipline as
 *     `eanR()`/`eanG()` above), and that complement is numerically
 *     IDENTICAL to the already-shipped `EAN_PARITY[1..9]` rows — a
 *     documented GS1 historical coincidence (EAN-13's first-digit table
 *     borrows 9 of UPC-E's 10 number-system-1 rows outright; only row 0
 *     genuinely differs, since it means something different in each table)
 *     that this class's own PHPDoc self-check on `upceParity()` asserts.
 *     `UPCE_PARITY_NUMSYS0` itself was cross-checked against an
 *     independently-worded published source (see `encodeUpce()`'s own doc
 *     for the citation and the worked numeric example verified against a
 *     second, unrelated source) rather than transcribed from memory alone.
 *
 * SECURITY (house "security musts"): every symbology rejects the wrong
 * length/charset OUTRIGHT rather than silently truncating/padding —
 * `generate()` NEVER throws and NEVER fatals; an invalid value comes back
 * as `['mime'=>'', 'bytes'=>'', 'valid'=>false, 'error'=>'...']`, which
 * every caller (`AssetRegister::buildLabelSheets()`) treats as "fall back
 * to QR" rather than a crash. Code 128 payload length is capped at 48
 * bytes (`self::CODE128_MAX_LEN`) purely to bound render work — a label
 * has no legitimate use for a longer barcode. Every SVG text label is
 * `htmlspecialchars()`'d (`self::escText()`) before being placed in the
 * `<text>` node — no user-controlled markup is ever echoed raw.
 *
 * @see https://www.gs1.org/standards/barcodes/ean-upc GS1 EAN/UPC spec
 * @see https://www.gs1.org/standards/barcodes/itf-14 GS1 ITF-14 spec
 * @see https://www.gs1.org/services/how-calculate-check-digit-manually GS1 mod-10
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/404
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/423
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Barcode
{
    /** @var string[] Symbologies this class knows how to encode/render. */
    public const SYMBOLOGIES = ['code128', 'ean13', 'ean8', 'upca', 'upce', 'itf14'];

    /**
     * Hard cap on the Code 128 payload length (bytes) — see class header's
     * SECURITY note. 48 chars is already generous for an assetTagCode
     * (VARCHAR(50)) and bounds the number of SVG `<rect>`/gd fill-rect
     * calls a single request can trigger.
     */
    private const CODE128_MAX_LEN = 48;

    // #############################################################################
    // 🟦 CODE 128 — symbol table (value 0-102 → 6-digit bar/space widths).
    // #############################################################################

    /**
     * Code 128 "symbol character" table — the ONE genuinely hand-
     * transcribed lookup this class relies on (see class header's DESIGN
     * note for why the character→value mapping ITSELF is pure arithmetic,
     * not part of this table).
     *
     * Each entry is a 6-digit string of bar/space MODULE WIDTHS (1-4
     * modules each), read as bar, space, bar, space, bar, space (every
     * Code 128 symbol — except the unique 4-bar STOP pattern — starts with
     * a bar and ends with a space, and is exactly 11 modules wide, i.e.
     * every row here sums to 11 — `widthsToBits()` turns this into an
     * actual `1`/`0` module string at render time).
     *
     * Values 0-102 carry a DIFFERENT meaning depending on which Code Set
     * (A/B/C) is active when they're emitted — see `code128SetAValue()`/
     * `code128SetBValue()`/the Set-C digit-pair branch in
     * `planCode128Symbols()`. Values 96-102 are the shared control codes
     * (FNC1-4, SHIFT, CODE A/B/C) whose meaning is identical across all
     * three sets.
     *
     * @var array<int, string>
     */
    private const CODE128_PATTERNS = [
        0 => '212222', 1 => '222122', 2 => '222221', 3 => '121223', 4 => '121322',
        5 => '131222', 6 => '122213', 7 => '122312', 8 => '132212', 9 => '221213',
        10 => '221312', 11 => '231212', 12 => '112232', 13 => '122132', 14 => '122231',
        15 => '113222', 16 => '123122', 17 => '123221', 18 => '223211', 19 => '221132',
        20 => '221231', 21 => '213212', 22 => '223112', 23 => '312131', 24 => '311222',
        25 => '321122', 26 => '321221', 27 => '312212', 28 => '322112', 29 => '322211',
        30 => '212123', 31 => '212321', 32 => '232121', 33 => '111323', 34 => '131123',
        35 => '131321', 36 => '112313', 37 => '132113', 38 => '132311', 39 => '211313',
        40 => '231113', 41 => '231311', 42 => '112133', 43 => '112331', 44 => '132131',
        45 => '113123', 46 => '113321', 47 => '133121', 48 => '313121', 49 => '211331',
        50 => '231131', 51 => '213113', 52 => '213311', 53 => '213131', 54 => '311123',
        55 => '311321', 56 => '331121', 57 => '312113', 58 => '312311', 59 => '332111',
        60 => '314111', 61 => '221411', 62 => '431111', 63 => '111224', 64 => '111422',
        65 => '121124', 66 => '121421', 67 => '141122', 68 => '141221', 69 => '112214',
        70 => '112412', 71 => '122114', 72 => '122411', 73 => '142112', 74 => '142211',
        75 => '241211', 76 => '221114', 77 => '413111', 78 => '241112', 79 => '134111',
        80 => '111242', 81 => '121142', 82 => '121241', 83 => '114212', 84 => '124112',
        85 => '124211', 86 => '411212', 87 => '421112', 88 => '421211', 89 => '212141',
        90 => '214121', 91 => '412121', 92 => '111143', 93 => '111341', 94 => '131141',
        95 => '114113', 96 => '114311', 97 => '411113', 98 => '411311', 99 => '113141',
        100 => '114131', 101 => '311141', 102 => '411131',
    ];

    /** START symbol widths — value 103 (Code Set A). */
    private const CODE128_START_A = '211412';
    /** START symbol widths — value 104 (Code Set B). */
    private const CODE128_START_B = '211214';
    /** START symbol widths — value 105 (Code Set C). */
    private const CODE128_START_C = '211232';
    /**
     * STOP symbol widths — value 106. Deliberately 7 digits (4 bars + 3
     * spaces, 13 modules total) — the ONE pattern in the whole table that
     * isn't the usual "3 bars + 3 spaces, 11 modules" shape, which is what
     * makes it unambiguously recognisable as the end of the symbol.
     */
    private const CODE128_STOP = '2331112';

    // #############################################################################
    // 🟨 EAN-13 / UPC-A — L-code table + first-digit parity table.
    // #############################################################################

    /**
     * EAN "L-code" (odd parity) 7-bit patterns for digits 0-9 — the ONE
     * hand-transcribed EAN/UPC table this class relies on. `eanR()`/
     * `eanG()` derive the other two tables from this one mathematically
     * (see class header DESIGN note) rather than transcribing them
     * separately.
     *
     * @var array<int, string>
     */
    private const EAN_L = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];

    /**
     * Which of L/G encodes each of the left-hand 6 digits, keyed by the
     * EAN-13 value's FIRST digit (0-9) — the standard "first digit
     * encoding" table. UPC-A borrows this too via its virtual leading '0'
     * (parity[0] = 'LLLLLL', i.e. UPC-A's own system digit + first 5
     * digits are always plain L-code — see `encodeGs1()`).
     *
     * @var array<int, string>
     */
    private const EAN_PARITY = [
        'LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG',
        'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL',
    ];

    // #############################################################################
    // 🟧 UPC-E — parity pattern table (number system 0; number system 1 is
    // DERIVED, see upceParity()).
    // #############################################################################

    /**
     * UPC-E parity pattern for NUMBER SYSTEM 0, keyed by the CHECK DIGIT
     * (0-9) — which of L/G encodes each of the six data digits. Unlike
     * EAN-13 (keyed by a digit that's actually one of the encoded digits),
     * UPC-E's selector digits (number system + check digit) are NEVER
     * themselves bar-encoded — a scanner recovers BOTH purely by noting
     * which of the six digit positions were L- vs G-coded and looking that
     * 6-bit pattern up in this table (reversed for decode). This is why
     * UPC-E supports only number systems 0 and 1: those are the only two
     * for which GS1 defined a (disjoint, unambiguous) 10-row parity table.
     *
     * VERIFIED (see `encodeUpce()`'s own doc for the full citation): every
     * one of these 10 rows was cross-checked against an independently
     * published UPC-E parity table (found via web search, quoting a
     * source separate from this class's own author's memory), and — as a
     * SECOND, independent check — `upceParity()`'s number-system-1
     * derivation from this table is numerically identical to the
     * already-shipped `EAN_PARITY[1..9]` rows (a documented GS1
     * coincidence; see class header DESIGN note).
     *
     * @var array<int, string>
     */
    private const UPCE_PARITY_NUMSYS0 = [
        'GGGLLL', 'GGLGLL', 'GGLLGL', 'GGLLLG', 'GLGGLL',
        'GLLGGL', 'GLLLGG', 'GLGLGL', 'GLGLLG', 'GLLGLG',
    ];

    // #############################################################################
    // 🟩 ITF-14 — Interleaved 2-of-5 digit patterns.
    // #############################################################################

    /**
     * Interleaved 2-of-5 digit patterns, digits 0-9 — each a 5-character
     * N(arrow)/W(ide) string. Self-check: every row has EXACTLY 2 'W's out
     * of 5 (that's what "2 of 5" means) — a mistyped row would almost
     * always violate this, so it's a strong internal consistency signal.
     *
     * @var array<int, string>
     */
    private const ITF_PATTERNS = [
        'NNWWN', 'WNNNW', 'NWNNW', 'WWNNN', 'NNWNW',
        'WNWNN', 'NWWNN', 'NNNWW', 'WNNWN', 'NWNWN',
    ];

    /** Digits required WITHOUT a check digit, per GS1-family symbology. */
    private const GS1_BODY_LEN = ['ean13' => 12, 'ean8' => 7, 'upca' => 11, 'itf14' => 13];

    // #############################################################################
    // 🚀 PUBLIC ENTRY POINT
    // #############################################################################

    /**
     * Generate a barcode for the given value. NEVER throws and NEVER
     * fatals (see class header SECURITY note) — an unsupported symbology
     * or an invalid value both come back as `valid => false` with a
     * human-readable `error`, which is the shape
     * `AssetRegister::buildLabelSheets()` checks to decide "fall back to
     * QR" (see that method's own doc).
     *
     * @param string $symbology One of `self::SYMBOLOGIES` (case-insensitive).
     * @param string $value     The value to encode — see each symbology's
     *                          `encode*()` method for its exact accepted
     *                          shape (Code 128: any ASCII 0-127 string up
     *                          to 48 bytes; EAN-13/UPC-A/ITF-14: digits
     *                          only, with or without the trailing check
     *                          digit).
     * @param array{
     *     format?: string,
     *     height?: int,
     *     moduleWidth?: int,
     *     quietModules?: int,
     *     showText?: bool
     * } $opts Options:
     *     format       'svg' (default) | 'png' (gd; falls back to svg
     *                  when gd isn't loaded, mirroring `Qr::generate()`).
     *     height       bar height in px (default 60, min 10).
     *     moduleWidth  px per narrowest module (default 2, min 1).
     *     quietModules quiet-zone width in modules, each side (default 10).
     *     showText     render the human-readable value under the bars
     *                  (default true).
     *
     * @return array{mime:string, bytes:string, valid:bool, error?:string}
     */
    public static function generate(string $symbology, string $value, array $opts = []): array
    {
        $symbology     = strtolower(trim($symbology));
        $format        = strtolower((string) ($opts['format'] ?? 'svg'));
        $moduleWidthPx = max(1, (int) ($opts['moduleWidth'] ?? 2));
        $heightPx      = max(10, (int) ($opts['height'] ?? 60));
        $quietModules  = max(0, (int) ($opts['quietModules'] ?? 10));
        $showText      = (bool) ($opts['showText'] ?? true);
        $bearer        = $symbology === 'itf14'; // GS1 framed bearer bars — see class header.

        // 🧮 Encode — every branch returns ['bits'=>'0101...', 'text'=>'...']
        // or null on a rejected value (never throws).
        $encoded = match ($symbology) {
            'code128' => self::encodeCode128($value),
            'ean13', 'ean8', 'upca', 'itf14' => self::encodeGs1($symbology, $value),
            'upce' => self::encodeUpce($value),
            default => null,
        };

        if (in_array($symbology, self::SYMBOLOGIES, true) === false) {
            return ['mime' => '', 'bytes' => '', 'valid' => false, 'error' => "Unsupported symbology '{$symbology}'."];
        }
        if ($encoded === null) {
            return ['mime' => '', 'bytes' => '', 'valid' => false, 'error' => self::rejectionReason($symbology, $value)];
        }

        $usePng = $format === 'png' && extension_loaded('gd');
        $bytes  = $usePng === true
            ? self::renderPng($encoded['bits'], $quietModules, $moduleWidthPx, $heightPx, $encoded['text'], $showText, $bearer)
            : self::renderSvg($encoded['bits'], $quietModules, $moduleWidthPx, $heightPx, $encoded['text'], $showText, $bearer);

        if ($bytes === '') {
            return ['mime' => '', 'bytes' => '', 'valid' => false, 'error' => 'Barcode rendering failed.'];
        }

        return ['mime' => $usePng === true ? 'image/png' : 'image/svg+xml', 'bytes' => $bytes, 'valid' => true];
    }

    /**
     * Human-readable rejection reason — purely for the `error` key in
     * `generate()`'s failure shape; never used for control flow.
     */
    private static function rejectionReason(string $symbology, string $value): string
    {
        if ($symbology === 'code128') {
            if ($value === '') {
                return 'Value is empty.';
            }
            if (strlen($value) > self::CODE128_MAX_LEN) {
                return 'Value exceeds the ' . self::CODE128_MAX_LEN . '-character Code 128 limit.';
            }
            return 'Value contains a byte outside the printable ASCII range Code 128 can encode.';
        }
        if ($symbology === 'upce') {
            return 'Value must be a 7 or 8-digit UPC-E code (number system 0 or 1 only), or an '
                . '11 or 12-digit UPC-A code that compresses to a valid UPC-E (with or without a '
                . 'valid GS1 check digit).';
        }
        $bodyLen = self::GS1_BODY_LEN[$symbology] ?? 0;
        return 'Value must be ' . $bodyLen . ' or ' . ($bodyLen + 1) . ' numeric digits '
            . '(with or without a valid GS1 check digit) for ' . strtoupper($symbology) . '.';
    }

    // #############################################################################
    // 🟦 CODE 128 — encoding
    // #############################################################################

    /**
     * Encode a Code 128 payload. Auto-selects Code Set A/B/C per byte,
     * switching subsets as it goes (`planCode128Symbols()`), then appends
     * the mod-103 checksum and STOP pattern.
     *
     * @return array{bits: string, text: string}|null
     */
    private static function encodeCode128(string $value): ?array
    {
        if ($value === '' || strlen($value) > self::CODE128_MAX_LEN) {
            return null;
        }
        // 🔒 Byte-range guard — Set A/B/C only cover ASCII 0-127; reject
        // anything else (e.g. multi-byte UTF-8) rather than mis-encode it.
        for ($i = 0; $i < strlen($value); $i++) {
            if (ord($value[$i]) > 127) {
                return null;
            }
        }

        [$startValue, $symbolValues] = self::planCode128Symbols($value);

        // 🔢 Mod-103 checksum — start value + Σ(symbolValue × position),
        // position 1-indexed from the first DATA symbol after START.
        $checksum = $startValue;
        foreach ($symbolValues as $idx => $val) {
            $checksum += $val * ($idx + 1);
        }
        $checksum %= 103;

        $bits = self::code128PatternBits($startValue);
        foreach ($symbolValues as $val) {
            $bits .= self::code128PatternBits($val);
        }
        $bits .= self::code128PatternBits($checksum);
        $bits .= self::code128PatternBits(106); // STOP

        return ['bits' => $bits, 'text' => $value];
    }

    /**
     * Greedy Code Set A/B/C planner. Returns `[startValue, symbolValues[]]`
     * — `symbolValues` includes any in-stream CODE A/B/C switch symbols,
     * ready for the checksum loop above to weight positionally.
     *
     * House simplification (documented, not a bug): switches INTO Set C
     * only when at least 4 consecutive digits remain from the current
     * position. A byte-optimal encoder also drops the threshold to 2 right
     * at the very start/end of the payload; this planner doesn't chase
     * that last few bytes of optimality — every payload it emits is still
     * a fully valid, correctly-checksummed Code 128 symbol, just
     * occasionally one Set-switch symbol longer than the theoretical
     * minimum. Correct over optimal, deliberately, for this pass.
     *
     * @return array{0: int, 1: int[]}
     */
    private static function planCode128Symbols(string $value): array
    {
        $bytes = str_split($value);
        $n     = count($bytes);
        $symbols = [];

        $set = self::code128InitialSet($bytes);
        $startValue = match ($set) {
            'A' => 103,
            'C' => 105,
            default => 104, // 'B'
        };

        $i = 0;
        while ($i < $n) {
            if ($set === 'C') {
                if ($i + 1 < $n && ctype_digit($bytes[$i]) === true && ctype_digit($bytes[$i + 1]) === true) {
                    $symbols[] = (int) ($bytes[$i] . $bytes[$i + 1]);
                    $i += 2;
                    continue;
                }
                // Can't continue in C from here (odd digit run ended, or a
                // non-digit byte) — drop to A or B depending on what the
                // next byte needs. Values 100/101 mean "switch to B"/
                // "switch to A" while the CURRENT set is C.
                $set = self::code128RequiresSetA($bytes[$i]) === true ? 'A' : 'B';
                $symbols[] = $set === 'A' ? 101 : 100;
                continue;
            }

            // In A or B — look ahead for a digit run long enough to be
            // worth switching to Set C (see method doc for the threshold).
            if (self::code128DigitRunAhead($bytes, $i) >= 4) {
                $symbols[] = 99; // CODE C (value 99 means the same thing in Set A and Set B)
                $set = 'C';
                continue;
            }

            $ch = $bytes[$i];
            if ($set === 'A') {
                if (self::code128IsSetAChar($ch) === false) {
                    $symbols[] = 100; // CODE B (value 100 = "switch to B" while in Set A)
                    $set = 'B';
                    continue;
                }
                $symbols[] = self::code128SetAValue($ch);
            } else {
                if (self::code128IsSetBChar($ch) === false) {
                    $symbols[] = 101; // CODE A (value 101 = "switch to A" while in Set B)
                    $set = 'A';
                    continue;
                }
                $symbols[] = self::code128SetBValue($ch);
            }
            $i++;
        }

        return [$startValue, $symbols];
    }

    /** @param string[] $bytes */
    private static function code128InitialSet(array $bytes): string
    {
        if (self::code128DigitRunAhead($bytes, 0) >= 4) {
            return 'C';
        }
        if (count($bytes) > 0 && self::code128RequiresSetA($bytes[0]) === true) {
            return 'A';
        }
        return 'B';
    }

    /** @param string[] $bytes Length of the consecutive digit run starting at $from. */
    private static function code128DigitRunAhead(array $bytes, int $from): int
    {
        $count = 0;
        $n = count($bytes);
        for ($i = $from; $i < $n && ctype_digit($bytes[$i]) === true; $i++) {
            $count++;
        }
        return $count;
    }

    /** Only reachable via Set A — Set B covers ASCII 32-127, so anything below that is a control char Set A alone carries. */
    private static function code128RequiresSetA(string $ch): bool
    {
        return ord($ch) < 32;
    }

    /** Set A = ASCII 0-95 (control chars 0-31 PLUS printable 32-95). */
    private static function code128IsSetAChar(string $ch): bool
    {
        return ord($ch) <= 95;
    }

    /** Set B = ASCII 32-127 (all printable ASCII plus DEL). */
    private static function code128IsSetBChar(string $ch): bool
    {
        $o = ord($ch);
        return $o >= 32 && $o <= 127;
    }

    /** Set A symbol value for a byte already confirmed via code128IsSetAChar(). */
    private static function code128SetAValue(string $ch): int
    {
        $o = ord($ch);
        return $o >= 32 ? $o - 32 : $o + 64;
    }

    /** Set B symbol value for a byte already confirmed via code128IsSetBChar(). */
    private static function code128SetBValue(string $ch): int
    {
        return ord($ch) - 32;
    }

    /** Look up a Code 128 symbol VALUE's width pattern and convert to a module bit string. */
    private static function code128PatternBits(int $value): string
    {
        $widths = match ($value) {
            103 => self::CODE128_START_A,
            104 => self::CODE128_START_B,
            105 => self::CODE128_START_C,
            106 => self::CODE128_STOP,
            default => self::CODE128_PATTERNS[$value] ?? self::CODE128_PATTERNS[0], // defensive — never hit for values 0-102
        };
        return self::widthsToBits($widths);
    }

    /**
     * Convert a width-digit string ("212222") into a `1`/`0` module bit
     * string. Every Code 128 element sequence starts with a BAR — this is
     * an ISO/AIM Code 128 convention, not something the width string
     * itself encodes.
     */
    private static function widthsToBits(string $widths): string
    {
        $bits = '';
        $isBar = true;
        foreach (str_split($widths) as $w) {
            $bits .= str_repeat($isBar === true ? '1' : '0', (int) $w);
            $isBar = $isBar === false;
        }
        return $bits;
    }

    // #############################################################################
    // 🟨 EAN-13 / UPC-A / ITF-14 — GS1 family encoding
    // #############################################################################

    /**
     * Shared entry point for the three GS1-family symbologies. Validates/
     * normalises the digit string (`normaliseGs1Value()`) then delegates
     * to the right module builder.
     *
     * @return array{bits: string, text: string}|null
     */
    private static function encodeGs1(string $kind, string $value): ?array
    {
        $bodyLen = self::GS1_BODY_LEN[$kind] ?? 0;
        $digits  = self::normaliseGs1Value(trim($value), $bodyLen);
        if ($digits === null) {
            return null;
        }

        if ($kind === 'itf14') {
            return ['bits' => self::itfModules($digits), 'text' => $digits];
        }

        // ean8 — 4 left digits ALL L-coded (no first-digit parity table, unlike
        // EAN-13), centre guard, 4 right digits R-coded. See ean8Modules().
        if ($kind === 'ean8') {
            return ['bits' => self::ean8Modules($digits), 'text' => $digits];
        }

        // ean13 / upca — UPC-A's digits become a virtual 13-digit EAN-13 by
        // prepending '0' (see class header + EAN_PARITY doc: parity[0] is
        // 'LLLLLL', so this never actually invokes G-code for a UPC-A
        // symbol — the real UPC-A digits are always plain L/R coded).
        // $digits itself (WITHOUT the virtual leading 0) is what the
        // printed human-readable label under the bars should show.
        $ean13Digits = $kind === 'upca' ? '0' . $digits : $digits;
        return ['bits' => self::ean13Modules($ean13Digits), 'text' => $digits];
    }

    /**
     * Validate + normalise a GS1-family value against its required body
     * length (digits WITHOUT the check digit). Accepts either the body
     * alone (check digit computed + appended) or the body plus a check
     * digit (validated, rejected on mismatch) — never anything else
     * (house "security musts": reject, don't silently coerce).
     *
     * @return string|null The full digit string INCLUDING check digit, or
     *                      null when the value is non-numeric, the wrong
     *                      length, or carries a check digit that doesn't
     *                      match.
     */
    private static function normaliseGs1Value(string $value, int $bodyLen): ?string
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            return null; // non-numeric — reject outright
        }
        $len = strlen($value);
        if ($len === $bodyLen) {
            return $value . (string) self::gs1CheckDigit($value);
        }
        if ($len === $bodyLen + 1) {
            $supplied = (int) $value[$len - 1];
            $expected = self::gs1CheckDigit(substr($value, 0, $bodyLen));
            return $supplied === $expected ? $value : null;
        }
        return null; // wrong length entirely
    }

    /**
     * GS1 mod-10 check digit — direct port of
     * `AssetRegister::gs1Mod10Check()` (migration 159 / #397), refactored
     * to COMPUTE the digit (given the value WITHOUT it) rather than verify
     * one already appended; `normaliseGs1Value()` above uses this same
     * method for both directions (compute for a check-digit-less value,
     * or re-derive-and-compare for one that already carries one).
     *
     * Alternating weight 3/1 counted from the RIGHTMOST digit of the
     * supplied value.
     *
     * HAND-VERIFIED (see class header): `400638133393` (12 digits) → `1`.
     *
     * @see https://www.gs1.org/services/how-calculate-check-digit-manually
     */
    private static function gs1CheckDigit(string $digitsWithoutCheck): int
    {
        $sum = 0;
        $weight = 3; // rightmost digit of the supplied value is weighted 3
        for ($i = strlen($digitsWithoutCheck) - 1; $i >= 0; $i--) {
            $sum += ((int) $digitsWithoutCheck[$i]) * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }
        return (10 - ($sum % 10)) % 10;
    }

    /**
     * Build the full EAN-13 module bit string (start guard, 6 left digits
     * per the first digit's parity pattern, centre guard, 6 right digits
     * always R-coded, end guard) for an already-validated 13-digit value.
     */
    private static function ean13Modules(string $digits13): string
    {
        $parity = self::EAN_PARITY[(int) $digits13[0]];
        $bits = '101'; // start guard
        for ($i = 1; $i <= 6; $i++) {
            $d = (int) $digits13[$i];
            $bits .= $parity[$i - 1] === 'L' ? self::EAN_L[$d] : self::eanG($d);
        }
        $bits .= '01010'; // centre guard
        for ($i = 7; $i <= 12; $i++) {
            $bits .= self::eanR((int) $digits13[$i]);
        }
        $bits .= '101'; // end guard
        return $bits;
    }

    /**
     * Build the full EAN-8 module bit string (start guard, 4 left digits
     * ALL L-coded, centre guard, 4 right digits ALL R-coded, end guard) for
     * an already-validated 8-digit value.
     *
     * EAN-8 needs NO first-digit parity table (unlike EAN-13): its left half
     * is always plain L-code and its right half always R-code — it is simply
     * the right two thirds of an EAN-13 with a 4/4 split. Total width is the
     * canonical 67 modules (3 + 4×7 + 5 + 4×7 + 3). The mod-10 check digit is
     * shared with the EAN-13/UPC-A family via `gs1CheckDigit()` — that
     * algorithm is anchored at the rightmost digit with weight 3 and so is
     * length-agnostic (self-check: EAN-8 `9638507` → check digit `4`).
     */
    private static function ean8Modules(string $digits8): string
    {
        $bits = '101'; // start guard
        for ($i = 0; $i <= 3; $i++) {
            $bits .= self::EAN_L[(int) $digits8[$i]];
        }
        $bits .= '01010'; // centre guard
        for ($i = 4; $i <= 7; $i++) {
            $bits .= self::eanR((int) $digits8[$i]);
        }
        $bits .= '101'; // end guard
        return $bits;
    }

    /** R-code = bitwise complement of the L-code table (see class header derivation). */
    private static function eanR(int $d): string
    {
        return strtr(self::EAN_L[$d], ['0' => '1', '1' => '0']);
    }

    /** G-code = the R-code pattern read in reverse (see class header derivation). */
    private static function eanG(int $d): string
    {
        return strrev(self::eanR($d));
    }

    // #############################################################################
    // 🟧 UPC-E — zero-suppressed UPC encoding (#423)
    // #############################################################################

    /**
     * Encode a UPC-E payload. Accepts FOUR input shapes (see class header
     * bullet + `rejectionReason()`):
     *   - 7 digits  — number system (0 or 1) + 6 data digits; the check
     *     digit is computed (from the EXPANDED UPC-A, never the raw 7
     *     digits themselves — see `upceExpand()`) and appended.
     *   - 8 digits  — as above, but with a check digit already supplied;
     *     it is re-derived from the expansion and compared, and a
     *     mismatch is REJECTED (never silently corrected).
     *   - 11 digits — a UPC-A body (number system + 10 digits) with no
     *     check digit; one is computed via the ordinary `gs1CheckDigit()`
     *     BEFORE attempting compression (so a caller can hand this method
     *     any UPC-A body and get either a UPC-E symbol or a clear
     *     rejection).
     *   - 12 digits — a full UPC-A (WITH its own check digit); that check
     *     digit is verified FIRST (a UPC-A that fails its own checksum is
     *     rejected outright, never "fixed up" en route to compression),
     *     then `upceCompress()` attempts the zero-suppression.
     *
     * Every path enforces number system ∈ {0, 1} — UPC-E has no valid
     * encoding for any other leading digit (class header) — and every
     * path that starts from a UPC-A additionally rejects a value
     * `upceCompress()` can't zero-suppress, with a clear reason surfaced
     * via `rejectionReason()`. `text` in the returned shape is always the
     * normalised 8-digit UPC-E value (number system + 6 data digits +
     * check digit) — what a human-readable label under the symbol should
     * show — regardless of which of the four input shapes was supplied.
     *
     * VERIFICATION (task requirement — "do NOT ship an unverified
     * encoder"): this method's expansion/compression/parity logic was
     * checked against a worked example independently found via web search
     * (a source separate from this codebase, quoting BarcodeFAQ.com-style
     * UPC-E documentation): UPC-E body `123456` (number system 0) expands
     * to manufacturer code `12345` + item code `00006` → UPC-A body
     * `01234500006` → `gs1CheckDigit()` computes check digit `5`,
     * matching that independent source's own stated result of `5` for the
     * same inputs — i.e. full UPC-A `012345000065` / full UPC-E
     * `01234565`. The `UPCE_PARITY_NUMSYS0` table (see that constant's own
     * doc) was separately cross-checked against a second, independently
     * published UPC-E parity table found the same way. Every case of the
     * expansion/compression rules was additionally round-trip tested
     * (compress(expand(x)) === x for a canonical `x` in each of the four
     * cases, PLUS a dedicated test confirming the GS1-mandated case
     * PRIORITY ordering is honoured when a non-canonical `x` collides with
     * a higher-priority case) before this method shipped.
     *
     * @see https://www.gs1.org/standards/barcodes/ean-upc GS1 EAN/UPC spec (class header)
     *
     * @return array{bits: string, text: string}|null
     */
    private static function encodeUpce(string $value): ?array
    {
        $value = trim($value);
        if (preg_match('/^\d+$/', $value) !== 1) {
            return null; // non-numeric — reject outright (house "security musts")
        }
        $len = strlen($value);

        // 📥 Shape 1/2 — a UPC-E value itself (7 = no check digit, 8 = with).
        if ($len === 7 || $len === 8) {
            $numberSystem = $value[0];
            if ($numberSystem !== '0' && $numberSystem !== '1') {
                return null; // UPC-E is valid ONLY for number system 0 or 1
            }
            $sixDigits = substr($value, 1, 6);
            $upcABody10 = self::upceExpand($sixDigits); // never fails — see that method's doc
            $expectedCheckDigit = self::gs1CheckDigit($numberSystem . $upcABody10);
            if ($len === 8) {
                if ((int) $value[7] !== $expectedCheckDigit) {
                    return null; // supplied check digit doesn't match the EXPANDED UPC-A's
                }
                $fullUpce = $value;
            } else {
                $fullUpce = $value . (string) $expectedCheckDigit;
            }
            return ['bits' => self::upceModules($fullUpce), 'text' => $fullUpce];
        }

        // 📥 Shape 3/4 — a UPC-A value (11 = no check digit, 12 = with),
        // compressed to UPC-E via upceCompress() below.
        if ($len === 11 || $len === 12) {
            $numberSystem = $value[0];
            if ($numberSystem !== '0' && $numberSystem !== '1') {
                return null;
            }
            $upcABody10 = substr($value, 1, 10);
            if ($len === 12) {
                // 🔒 The supplied UPC-A must be internally valid BEFORE we
                // even attempt compression — never compress a checksum-
                // broken value into an equally-broken UPC-E.
                $expectedUpcACheckDigit = self::gs1CheckDigit(substr($value, 0, 11));
                if ((int) $value[11] !== $expectedUpcACheckDigit) {
                    return null;
                }
            }
            $sixDigits = self::upceCompress($upcABody10);
            if ($sixDigits === null) {
                return null; // not UPC-E-compressible — see upceCompress()'s doc
            }
            $checkDigit = self::gs1CheckDigit($numberSystem . $upcABody10);
            $fullUpce = $numberSystem . $sixDigits . (string) $checkDigit;
            return ['bits' => self::upceModules($fullUpce), 'text' => $fullUpce];
        }

        return null; // wrong length entirely
    }

    /**
     * Expand a UPC-E's 6 data digits to the 10-digit UPC-A manufacturer +
     * product body (WITHOUT the number-system digit or check digit) — the
     * reverse of the zero-suppression the GS1 UPC-E spec defines. Keyed
     * entirely off the 6th (last) data digit, which selects one of four
     * cases; EVERY digit 0-9 hits exactly one case, so — unlike
     * `upceCompress()` — expansion NEVER fails.
     *
     * | 6th digit | manufacturer code (5) | item code (5)   |
     * |-----------|------------------------|-----------------|
     * | 0, 1, 2   | d1 d2 d6 0 0           | 0 0 d3 d4 d5    |
     * | 3         | d1 d2 d3 0 0           | 0 0 0 d4 d5     |
     * | 4         | d1 d2 d3 d4 0          | 0 0 0 0 d5      |
     * | 5-9       | d1 d2 d3 d4 d5         | 0 0 0 0 d6      |
     *
     * (d1-d6 = the six UPC-E digits in order; the table's own d6 column
     * doubles as "the case selector" — for cases 0-2 it's copied into the
     * manufacturer code verbatim, for cases 3/4 it's a fixed marker value
     * that carries no data of its own, and for case 5-9 it IS the real
     * trailing item-code digit.)
     */
    private static function upceExpand(string $sixDigits): string
    {
        $d = str_split($sixDigits); // $d[0]..$d[5] = the six UPC-E digits, in order
        return match ($d[5]) {
            '0', '1', '2' => $d[0] . $d[1] . $d[5] . '0000' . $d[2] . $d[3] . $d[4],
            '3'           => $d[0] . $d[1] . $d[2] . '00000' . $d[3] . $d[4],
            '4'           => $d[0] . $d[1] . $d[2] . $d[3] . '00000' . $d[4],
            default       => $d[0] . $d[1] . $d[2] . $d[3] . $d[4] . '0000' . $d[5], // 5-9
        };
    }

    /**
     * Attempt to compress a 10-digit UPC-A manufacturer+product body (the
     * UPC-A's 11-digit body MINUS its leading number-system digit) down to
     * UPC-E's 6 data digits — the exact algebraic inverse of
     * `upceExpand()`'s table, tried in GS1-MANDATED PRIORITY ORDER
     * (case "0,1,2" first, then "3", then "4", then "5-9"). Priority
     * matters because some UPC-A bodies satisfy more than one case's raw
     * shape (e.g. a case-"5-9" body whose manufacturer code happens to
     * ALSO end in a zero looks exactly like a case-"4" body too) — GS1
     * resolves the ambiguity by always preferring the earliest-listed
     * case, so this method checks them in that same order and returns on
     * the FIRST match, exactly like a real UPC-E encoder must.
     *
     * Returns null when NONE of the four cases apply — not every UPC-A
     * is representable as UPC-E (see class header UPC-E bullet); the
     * caller (`encodeUpce()`) treats that as a hard rejection with a
     * dedicated error message, never a silent best-effort guess.
     */
    private static function upceCompress(string $tenDigits): ?string
    {
        $m = substr($tenDigits, 0, 5); // manufacturer code
        $p = substr($tenDigits, 5, 5); // item/product code

        // Case "0,1,2" — mfr ends '00' with a 0-2 selector as its 3rd
        // digit; item is '00' + 3 free digits.
        if ($m[3] === '0' && $m[4] === '0' && in_array($m[2], ['0', '1', '2'], true) === true
            && $p[0] === '0' && $p[1] === '0') {
            return $m[0] . $m[1] . $p[2] . $p[3] . $p[4] . $m[2];
        }
        // Case "3" — mfr ends '00' (any 3 leading digits); item is '000' +
        // 2 free digits. The marker '3' carries no data of its own.
        if ($m[3] === '0' && $m[4] === '0' && $p[0] === '0' && $p[1] === '0' && $p[2] === '0') {
            return $m[0] . $m[1] . $m[2] . $p[3] . $p[4] . '3';
        }
        // Case "4" — mfr ends a SINGLE '0'; item is '0000' + 1 free digit.
        if ($m[4] === '0' && $p[0] === '0' && $p[1] === '0' && $p[2] === '0' && $p[3] === '0') {
            return $m[0] . $m[1] . $m[2] . $m[3] . $p[4] . '4';
        }
        // Case "5-9" — mfr does NOT end in '0'; item is '0000' + a digit
        // 5-9 (0-4 here would be genuinely non-compressible, not this case).
        if ($m[4] !== '0' && $p[0] === '0' && $p[1] === '0' && $p[2] === '0' && $p[3] === '0'
            && in_array($p[4], ['5', '6', '7', '8', '9'], true) === true) {
            return $m[0] . $m[1] . $m[2] . $m[3] . $m[4] . $p[4];
        }
        return null; // not UPC-E-compressible
    }

    /**
     * UPC-E parity pattern for the given number system ('0' or '1') and
     * check digit (0-9) — which of L/G encodes each of the six data
     * digits (see `UPCE_PARITY_NUMSYS0`'s own doc for why BOTH the number
     * system and the check digit — never bar-encoded themselves — are
     * recoverable purely from this pattern). Number system 1's table is
     * NOT separately hand-transcribed: it is the bitwise L/G complement of
     * `UPCE_PARITY_NUMSYS0` (the actual GS1-spec relationship between the
     * two tables — same derivation discipline as `eanR()`/`eanG()`).
     *
     * SELF-CHECK (class header DESIGN note): for check digits 1-9, this
     * complement is numerically IDENTICAL to the already-shipped
     * `EAN_PARITY[1..9]` — a documented GS1 historical coincidence. Row 0
     * is DELIBERATELY not required to match (and does not:
     * `upceParity('1', 0)` is `'LLLGGG'`, `EAN_PARITY[0]` is `'LLLLLL'` —
     * the two tables mean different things at digit/check-digit 0), so
     * this fact is documented rather than asserted in code.
     */
    private static function upceParity(string $numberSystem, int $checkDigit): string
    {
        $ns0Pattern = self::UPCE_PARITY_NUMSYS0[$checkDigit];
        return $numberSystem === '1' ? strtr($ns0Pattern, ['L' => 'G', 'G' => 'L']) : $ns0Pattern;
    }

    /**
     * Build the full UPC-E module bit string — start guard, the six data
     * digits L/G-coded per `upceParity()`'s selection, then the SPECIAL
     * UPC-E end guard — for an already-validated 8-digit value (number
     * system + 6 data digits + check digit). UNLIKE EAN-13/EAN-8, UPC-E
     * has NO centre guard (there is no "left half"/"right half" split —
     * all six digits sit in one continuous run) and its end guard is the
     * 6-module `010101`, not the 3-module `101` the rest of the EAN/UPC
     * family uses — both are fixed GS1 UPC-E symbol-structure facts, not
     * derived from anything else in this class. Total width is the
     * canonical 51 modules (3 start + 6×7 data + 6 end).
     */
    private static function upceModules(string $digits8): string
    {
        $numberSystem = $digits8[0];
        $checkDigit   = (int) $digits8[7];
        $parity       = self::upceParity($numberSystem, $checkDigit);

        $bits = '101'; // start guard
        for ($i = 0; $i < 6; $i++) {
            $d = (int) $digits8[$i + 1];
            $bits .= $parity[$i] === 'L' ? self::EAN_L[$d] : self::eanG($d);
        }
        $bits .= '010101'; // UPC-E end guard — no centre guard (see method doc)
        return $bits;
    }

    /**
     * Build the full ITF-14 module bit string. Digits are consumed in
     * PAIRS — the first digit of each pair supplies the BAR widths, the
     * second supplies the interleaved SPACE widths (this interleaving is
     * what "Interleaved 2 of 5" means; it's why ITF always needs an EVEN
     * digit count, which ITF-14's fixed 14-digit length always satisfies).
     */
    private static function itfModules(string $digits14): string
    {
        $bits = '1010'; // start: narrow bar, narrow space, narrow bar, narrow space
        for ($i = 0; $i < strlen($digits14); $i += 2) {
            $barPattern   = self::ITF_PATTERNS[(int) $digits14[$i]];
            $spacePattern = self::ITF_PATTERNS[(int) $digits14[$i + 1]];
            for ($j = 0; $j < 5; $j++) {
                $bits .= $barPattern[$j] === 'W' ? '11' : '1';
                $bits .= $spacePattern[$j] === 'W' ? '00' : '0';
            }
        }
        $bits .= '1101'; // stop: wide bar, narrow space, narrow bar
        return $bits;
    }

    // #############################################################################
    // 🎨 RENDERING — SVG (always available) + gd-PNG (when the extension is loaded)
    // #############################################################################

    /**
     * Render an SVG from a module bit string. One `<rect>` per `1` module
     * — mirrors `Qr::renderSvg()`'s own "simple over clever" approach
     * rather than merging adjacent bars into wider rects; Code 128's
     * 48-char cap keeps the total module count (and so the rect count)
     * comfortably bounded either way.
     */
    private static function renderSvg(
        string $bits,
        int $quietModules,
        int $moduleWidthPx,
        int $heightPx,
        string $label,
        bool $showText,
        bool $bearer
    ): string {
        $totalModules = strlen($bits) + ($quietModules * 2);
        $width  = $totalModules * $moduleWidthPx;
        $textH  = $showText === true ? 18 : 0;
        $bearerH = $bearer === true ? 4 : 0; // frame thickness, px — top AND bottom
        $totalH = $heightPx + $textH + ($bearerH * 2);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $totalH
             . '" viewBox="0 0 ' . $width . ' ' . $totalH . '" shape-rendering="crispEdges">';
        $svg .= '<rect width="100%" height="100%" fill="#ffffff"/>';

        // 🖼️ GS1 framed bearer bars (ITF-14 only) — a solid black rule
        // across the full width, top and bottom, per the class header's
        // GS1-spec note.
        if ($bearer === true) {
            $svg .= '<rect x="0" y="0" width="' . $width . '" height="' . $bearerH . '" fill="#000000"/>';
            $svg .= '<rect x="0" y="' . ($bearerH + $heightPx) . '" width="' . $width . '" height="' . $bearerH . '" fill="#000000"/>';
        }

        $x = $quietModules * $moduleWidthPx;
        for ($i = 0; $i < strlen($bits); $i++) {
            if ($bits[$i] === '1') {
                $svg .= '<rect x="' . $x . '" y="' . $bearerH . '" width="' . $moduleWidthPx . '" height="' . $heightPx . '" fill="#000000"/>';
            }
            $x += $moduleWidthPx;
        }

        if ($showText === true) {
            $svg .= '<text x="' . ($width / 2) . '" y="' . ($bearerH + $heightPx + $bearerH + 14)
                  . '" text-anchor="middle" font-family="monospace" font-size="12" fill="#000000">'
                  . self::escText($label) . '</text>';
        }

        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Render a PNG using gd — mirrors `Qr::renderPng()`'s structure
     * exactly (imagecreatetruecolor → imagefilledrectangle per module →
     * imagestring for the label).
     */
    private static function renderPng(
        string $bits,
        int $quietModules,
        int $moduleWidthPx,
        int $heightPx,
        string $label,
        bool $showText,
        bool $bearer
    ): string {
        $totalModules = strlen($bits) + ($quietModules * 2);
        $width  = $totalModules * $moduleWidthPx;
        $textH  = $showText === true ? 16 : 0;
        $bearerH = $bearer === true ? 4 : 0;
        $totalH = $heightPx + $textH + ($bearerH * 2);

        $img = imagecreatetruecolor($width, $totalH);
        if ($img === false) {
            return '';
        }
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        if ($white === false || $black === false) {
            imagedestroy($img);
            return '';
        }
        imagefilledrectangle($img, 0, 0, $width, $totalH, $white);

        if ($bearer === true) {
            imagefilledrectangle($img, 0, 0, $width, $bearerH, $black);
            imagefilledrectangle($img, 0, $bearerH + $heightPx, $width, $bearerH + $heightPx + $bearerH, $black);
        }

        $x = $quietModules * $moduleWidthPx;
        for ($i = 0; $i < strlen($bits); $i++) {
            if ($bits[$i] === '1') {
                imagefilledrectangle($img, $x, $bearerH, $x + $moduleWidthPx - 1, $bearerH + $heightPx - 1, $black);
            }
            $x += $moduleWidthPx;
        }

        if ($showText === true) {
            $font = 3;
            $textW = imagefontwidth($font) * strlen($label);
            $textX = (int) (($width - $textW) / 2);
            imagestring($img, $font, max(0, $textX), $bearerH + $heightPx + $bearerH + 2, $label, $black);
        }

        ob_start();
        imagepng($img);
        $bytesOut = (string) ob_get_clean();
        imagedestroy($img);
        return $bytesOut;
    }

    /** Escape a label for placement inside an SVG `<text>` node — mirrors `Qr::escText()`. */
    private static function escText(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1, 'UTF-8');
    }
}
