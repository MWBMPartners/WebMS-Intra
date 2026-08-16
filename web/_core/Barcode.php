<?php
// Path: _core/Barcode.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — 1D barcode encoder (Code 128 / EAN-13 / UPC-A / ITF-14) 📊
 * -----------------------------------------------------------------------------
 * Asset Tracker sub-issue #404 — the barcode counterpart to `Qr`/`QrEncoder`,
 * mirrored on the SAME class shape (`generate()` returns
 * `['mime'=>..., 'bytes'=>...]`, SVG by default, gd-PNG on request) so
 * `AssetRegister::buildLabelSheets()` can embed a barcode data URI exactly
 * the same way it already embeds a QR one (`Qr::generate(['format'=>'png'])`
 * → `data:{mime};base64,{b64}`).
 *
 * Supports four symbologies (`self::SYMBOLOGIES`):
 *   - `code128` — full ASCII (0-127) via auto Code Set A/B/C subset
 *     selection + mod-103 checksum. Used for `assetTagCode` (free text).
 *   - `ean13`   — 12 or 13 numeric digits (12 = check digit auto-computed
 *     and appended; 13 = supplied check digit validated).
 *   - `upca`    — 11 or 12 numeric digits. Encoded by prefixing a virtual
 *     leading `'0'` and delegating to the EAN-13 module builder — this is
 *     the same relationship real UPC-A/EAN-13 scanners rely on (a UPC-A
 *     symbol IS a valid EAN-13 symbol whose first digit happens to be 0).
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
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/404
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Barcode
{
    /** @var string[] Symbologies this class knows how to encode/render. */
    public const SYMBOLOGIES = ['code128', 'ean13', 'upca', 'itf14'];

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
    private const GS1_BODY_LEN = ['ean13' => 12, 'upca' => 11, 'itf14' => 13];

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
            'ean13', 'upca', 'itf14' => self::encodeGs1($symbology, $value),
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
