<?php
// Path: tools/qr-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The portal's QR pictures, drawn and READ BACK — self-test 🔳🔎 (#623)
 * -----------------------------------------------------------------------------
 * WHAT WAS WRONG (found 13 October 2026)
 * --------------------------------------
 * From the day it was written in July 2026, `Portal\Core\Qr` drew pictures
 * that no reader could read. Asset labels, the noticeboard's share panel and
 * the `/qr` page all showed a picture that looked like a QR code and was not
 * one. Nothing failed: every page answered and every picture was drawn. No
 * test had ever tried to READ a picture, so nothing noticed for three months.
 * This file draws pictures with the portal's own class and reads each one
 * back.
 *
 * HOW A TEST CAN READ A QR CODE
 * -----------------------------
 * With `tools/qr-test-reader.php`, a small reader kept for tests only. A
 * reader written by the same hands as the drawing could share its mistakes,
 * so before it is trusted with anything it must read pictures drawn by a
 * DIFFERENT program, `qrencode`. Those are kept in `tools/fixtures/qr/` as
 * plain rows of 0 and 1, so this test needs no other program to run.
 *
 * WHAT IT RUNS
 * ------------
 *   A. The reader is proved first, on pictures the portal did not draw: it
 *      reads every kept `qrencode` picture exactly; between them they use
 *      every level and all eight masks; and it REFUSES a picture with one
 *      square turned over, in each kind of place a square can be (data, a
 *      format mark, a size mark, a corner square, a timing line, an
 *      alignment square). Its way of finding a picture inside a PNG or an
 *      SVG is proved on the same pictures: the square size, the clear
 *      border, light-on-dark, a square that is not one flat colour.
 *   B. The portal's own pictures, read back: the lengths that were tried and
 *      failed in October (5, 27, 35, 99, 155, 170 and 200 bytes); EVERY size
 *      the class offers (1 to 40) at EVERY level (L, M, Q, H), with text that
 *      exactly fills the size and text one byte longer; an `otpauth://`
 *      address of the shape the two-factor set-up page makes, with a long
 *      organisation name and e-mail address; accented and non-Latin letters;
 *      every byte value; the clear border; the picture's size; a caption;
 *      colours; the `data:` address; and the choice of mask.
 *   C. What the class refuses: text one byte too long for the biggest QR
 *      code, at each level, is refused in plain words and never cut short.
 *   Q. Only with `--with-qrencode`: `qrencode` draws a picture at every size
 *      and level there and then, the reader reads each one, and the portal's
 *      picture of the same text is compared with it. This is the proof of
 *      every number in the tables; part A keeps a sample of it.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - A phone's camera. Every picture here is read from its exact pixels or
 *   its drawing commands, upright and unblurred. Nothing here holds a phone
 *   up to a screen or to a printed label, in good light or bad.
 * - A printed label. Whether a 14 mm label prints squares big enough for a
 *   camera depends on the printer and the phone.
 * - Every reader. The pictures are proved to keep to the QR standard
 *   (ISO/IEC 18004), which readers are built to. macOS's own reader was
 *   tried by hand on 14 October 2026 and read them; that is not run here.
 * - That a browser paints the SVG as written. The reader follows the drawing
 *   commands; it is not a browser.
 * - That an old reader shows accented or non-Latin letters rightly. The
 *   bytes are proved to come back the same. A picture does not say which
 *   alphabet they are in.
 * - That the mask chosen is the one another program would choose. It is
 *   checked against the reader's own count by the standard's four rules.
 *   `qrencode` reads the third rule differently and chooses differently
 *   about one time in four; every choice is a picture that reads.
 * - The numbers in the tables at sizes and levels that have no kept
 *   picture, UNLESS it is run with `--with-qrencode`. Without it, the portal
 *   and the reader are proved to agree at all 160, and the reader is proved
 *   against `qrencode` at the kept ones. Both would have to hold the same
 *   wrong number at the same place to pass. With it, all 160 are proved.
 *
 * Usage:  php tools/qr-selftest.php
 *             No database, no network, no other program. Needs PHP's gd
 *             extension (to read PNG pictures). About a minute.
 *         php tools/qr-selftest.php --with-qrencode
 *             The same, plus part Q. Needs the `qrencode` program
 *             (QRENCODE=/path/to/qrencode if it is not on the PATH).
 *         php tools/qr-selftest.php --make-fixtures
 *             Draws the kept pictures again with `qrencode`. Checks nothing.
 * Exit:   0 only when every check ran and passed and the last line says the
 *         run reached its end; 1 otherwise, including every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/623
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a plain run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 67;
/** How many more part Q makes. */
const EXTRA_CHECKS_WITH_QRENCODE = 9;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$mode = $argv[1] ?? '';
if (in_array($mode, ['', '--with-qrencode', '--make-fixtures'], true) === false || count($argv) > 2) {
    refuse('this takes no argument, or --with-qrencode, or --make-fixtures');
}

$repo = (string) realpath(dirname(__DIR__));
$sep  = DIRECTORY_SEPARATOR;
require __DIR__ . $sep . 'qr-test-reader.php';
require $repo . $sep . 'web' . $sep . '_core' . $sep . 'QrEncoder.php';
require $repo . $sep . 'web' . $sep . '_core' . $sep . 'Qr.php';
// The portal's own maker of the address an authenticator app reads. It needs nothing else of the portal.
require $repo . $sep . 'web' . $sep . '_core' . $sep . 'Totp.php';

use Portal\Core\Qr;
use Portal\Core\Totp;

$fixtureDir = __DIR__ . $sep . 'fixtures' . $sep . 'qr';
const LEVELS = ['L', 'M', 'Q', 'H'];

// =============================================================================
// 🧰 Text to draw
// =============================================================================

/**
 * Plain letters, digits and address marks, the same every run. Worked out by
 * a small sum of its own rather than PHP's random numbers, so that the kept
 * pictures can never stop matching because PHP changed.
 */
function sampleText(int $bytes, int $seed): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789:/?&=%-._~ ';
    $state    = ($seed * 2654435761 + 12345) & 0x7FFFFFFF;
    $out      = '';
    for ($i = 0; $i < $bytes; $i++) {
        $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
        $out  .= $alphabet[($state >> 16) % strlen($alphabet)];
    }
    return $out;
}

/** Bytes of every value, 0 to 255, the same every run. */
function sampleBytes(int $bytes, int $seed): string
{
    $state = ($seed * 40503 + 977) & 0x7FFFFFFF;
    $out   = '';
    for ($i = 0; $i < $bytes; $i++) {
        $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
        $out  .= chr(($state >> 16) & 0xFF);
    }
    return $out;
}

/**
 * An address of the shape the two-factor set-up page makes, from the
 * portal's own Totp::getUri(), with a long organisation name and a long
 * e-mail address. The key is made up; it is nobody's.
 */
function longAuthenticatorAddress(): string
{
    return Totp::getUri(
        'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
        'the.reverend.canon.bartholomew.featherstonehaugh@a-long-organisation-name.example.org',
        'The Parish Church of Saint Mary the Virgin and All Saints, Little Wittenham'
    );
}

/** Every byte value once, 0 to 255. */
function allByteValues(): string
{
    $all = '';
    for ($i = 0; $i < 256; $i++) {
        $all .= chr($i);
    }
    return $all;
}

/**
 * 📋 The kept pictures: a name, the level, and the text. `qrencode` drew each
 * one (see --make-fixtures); the file tools/fixtures/qr/<name>.txt holds its
 * squares. The text is here and not in the file, so that a kept picture
 * cannot "agree with itself".
 *
 * @return array<string, array{level:string, text:string}>
 */
function keptPictures(): array
{
    return [
        // The texts that were tried against macOS's reader on 13 October 2026.
        'hello'              => ['level' => 'M', 'text' => 'HELLO'],
        'address-35'         => ['level' => 'M', 'text' => 'https://portal.example.org/a/ABC123'],
        'bytes-27'           => ['level' => 'M', 'text' => sampleText(27, 27)],
        'bytes-99'           => ['level' => 'M', 'text' => sampleText(99, 99)],
        'bytes-155'          => ['level' => 'M', 'text' => sampleText(155, 155)],
        'bytes-170'          => ['level' => 'M', 'text' => sampleText(170, 170)],
        'bytes-200'          => ['level' => 'M', 'text' => sampleText(200, 200)],
        // Each of the other levels, small and with several blocks.
        'level-l-small'      => ['level' => 'L', 'text' => sampleText(17, 1)],
        'level-q-small'      => ['level' => 'Q', 'text' => sampleText(11, 2)],
        'level-h-small'      => ['level' => 'H', 'text' => sampleText(7, 3)],
        'level-l-blocks'     => ['level' => 'L', 'text' => sampleText(300, 4)],
        'level-q-blocks'     => ['level' => 'Q', 'text' => sampleText(150, 5)],
        'level-h-blocks'     => ['level' => 'H', 'text' => sampleText(177, 6)],
        // The edges where the rules change: size 7 is the first with size
        // marks; size 9 is the last whose length takes 8 bits, size 10 the
        // first that takes 16.
        'size-6-m'           => ['level' => 'M', 'text' => sampleText(106, 7)],
        'size-7-m'           => ['level' => 'M', 'text' => sampleText(107, 8)],
        'size-9-m'           => ['level' => 'M', 'text' => sampleText(180, 9)],
        'size-10-m'          => ['level' => 'M', 'text' => sampleText(181, 10)],
        // What the portal really draws.
        'authenticator'      => ['level' => 'M', 'text' => longAuthenticatorAddress()],
        'accents'            => ['level' => 'M', 'text' => "Ærøskøbing, Señor Müller, Ελληνικά, 日本語, العربية, עברית, 🙂"],
        'every-byte'         => ['level' => 'L', 'text' => allByteValues()],
        // Chosen only because qrencode draws it with the first of the eight
        // masks, which it picks for none of the pictures above. With it, all
        // eight are read (check A2 fails if that ever stops being so).
        'mask-0'             => ['level' => 'M', 'text' => 'A notice for hall 15'],
        // Big pictures: size 32 is the one size whose alignment squares do not
        // follow the usual sum, and size 40 is the biggest there is, filled
        // to its last byte.
        'size-32-m'          => ['level' => 'M', 'text' => sampleText(1500, 32)],
        'size-40-l-full'     => ['level' => 'L', 'text' => sampleText(2953, 40)],
    ];
}

// =============================================================================
// 🧰 qrencode (only for --make-fixtures and --with-qrencode)
// =============================================================================

/** Where the qrencode program is, or '' when there is none. */
function findQrencode(): string
{
    $named = getenv('QRENCODE');
    $tries = ($named !== false && $named !== '') ? [(string) $named] : [];
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir !== '') {
            $tries[] = $dir . DIRECTORY_SEPARATOR . 'qrencode';
        }
    }
    $tries[] = '/opt/homebrew/bin/qrencode';
    $tries[] = '/usr/local/bin/qrencode';
    $tries[] = '/usr/bin/qrencode';
    foreach ($tries as $try) {
        if (is_file($try) === true && is_executable($try) === true) {
            return $try;
        }
    }
    return '';
}

/**
 * Have qrencode draw a text, and return its squares with no border.
 * "-8" makes it write the text one byte at a time, as the portal does.
 * The command is a list (no shell), and the text goes in on standard input,
 * so no text can be mistaken for an option.
 *
 * @return list<list<bool>>
 */
function qrencodeSquares(string $program, string $text, string $level): array
{
    $pipes   = [];
    $process = proc_open(
        [$program, '-8', '-l', $level, '-m', '0', '-t', 'ASCII', '-o', '-'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (is_resource($process) === false) {
        refuse('qrencode could not be started');
    }
    fwrite($pipes[0], $text);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        refuse('qrencode failed: ' . trim($err));
    }
    $grid = [];
    foreach (explode("\n", rtrim($out, "\n")) as $line) {
        // Two characters a square: "##" is dark, two spaces are light.
        $row = [];
        for ($i = 0; $i + 1 < strlen($line); $i += 2) {
            $row[] = ($line[$i] === '#');
        }
        $grid[] = $row;
    }
    return $grid;
}

if ($mode === '--make-fixtures') {
    $program = findQrencode();
    if ($program === '') {
        refuse('the qrencode program was not found (set QRENCODE to its path)');
    }
    $version = trim((string) explode("\n", (string) shell_exec(escapeshellarg($program) . ' --version 2>&1'))[0]);
    if (is_dir($fixtureDir) === false && mkdir($fixtureDir, 0755, true) === false) {
        refuse('the fixtures folder could not be made');
    }
    foreach (keptPictures() as $name => $case) {
        $grid  = qrencodeSquares($program, $case['text'], $case['level']);
        $lines = [
            '# A QR picture drawn by ' . $version . ', NOT by the portal.',
            '# It is kept so that the test reader (tools/qr-test-reader.php) can be proved on a picture the portal did not draw.',
            '# Made by: php tools/qr-selftest.php --make-fixtures   (qrencode -8 -l ' . $case['level'] . ' -m 0 -t ASCII)',
            '# Picture "' . $name . '", level ' . $case['level'] . ', ' . count($grid) . ' squares across. Its text is in keptPictures() in tools/qr-selftest.php.',
            '# One row a line, 1 is a dark square, no clear border. Do not edit by hand.',
        ];
        foreach ($grid as $row) {
            $line = '';
            foreach ($row as $square) {
                $line .= ($square === true) ? '1' : '0';
            }
            $lines[] = $line;
        }
        file_put_contents($fixtureDir . $sep . $name . '.txt', implode("\n", $lines) . "\n");
        echo 'wrote ' . $name . '.txt (' . count($grid) . " squares across)\n";
    }
    echo 'Made ' . count(keptPictures()) . " kept pictures. Nothing was checked: run the test without --make-fixtures.\n";
    exit(0);
}

if (function_exists('imagecreatefromstring') === false || function_exists('imagepng') === false) {
    refuse('this PHP has no gd extension, which this TEST needs to read PNG pictures (the portal itself draws SVG without it)');
}
$qrencode = '';
if ($mode === '--with-qrencode') {
    $qrencode = findQrencode();
    if ($qrencode === '') {
        refuse('--with-qrencode was asked for and the qrencode program was not found (set QRENCODE to its path)');
    }
}

// =============================================================================
// 🧰 Reporting
// =============================================================================
$pass = 0;
$fail = 0;

/** ✅ Report one check. `$detail` is shown only on failure. */
function ok(string $label, bool $passed, string $detail = ''): void
{
    global $pass, $fail;
    if ($passed === true) {
        $pass++;
        echo 'PASS — ' . $label . "\n";
        return;
    }
    $fail++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
}

function head(string $text): void
{
    echo "\n=== " . $text . " ===\n";
}

/** A line that tells the reader something and is NOT a check. */
function note(string $text): void
{
    echo 'NOTE — ' . $text . "\n";
}

/**
 * Read a picture, and never let a refusal stop this file: a run that stops
 * part way can be mistaken for a pass.
 *
 * @param array{mime:string, bytes:string} $picture
 *
 * @return array<string, mixed> What the reader found, or ['refused' => why].
 */
function readBack(array $picture, int $borderNeeded = 4): array
{
    try {
        return QrTestReader::readPicture($picture, $borderNeeded);
    } catch (\Throwable $e) {
        return ['refused' => get_class($e) . ': ' . $e->getMessage()];
    }
}

/**
 * Draw with the portal's class and read back. Returns '' when the text came
 * back the same (and the size and level are as expected, where given), or a
 * short reason.
 *
 * @param array<string, mixed> $opts for Qr::generate()
 */
function drawnAndRead(string $text, array $opts, ?int $wantVersion = null, ?string $wantLevel = null, ?string $wantMime = null): string
{
    try {
        $picture = Qr::generate($text, $opts);
    } catch (\Throwable $e) {
        return 'not drawn: ' . get_class($e) . ': ' . $e->getMessage();
    }
    if ($wantMime !== null && $picture['mime'] !== $wantMime) {
        return 'a ' . $picture['mime'] . ' came back, not ' . $wantMime;
    }
    $read = readBack($picture);
    if (isset($read['refused']) === true) {
        return 'not read: ' . $read['refused'];
    }
    if ($read['text'] !== $text) {
        return 'a DIFFERENT text was read back (' . strlen((string) $read['text']) . ' bytes for ' . strlen($text) . ')';
    }
    if ($wantVersion !== null && $read['version'] !== $wantVersion) {
        return 'size ' . $read['version'] . ', not ' . $wantVersion;
    }
    if ($wantLevel !== null && $read['level'] !== $wantLevel) {
        return 'level ' . $read['level'] . ', not ' . $wantLevel;
    }
    return '';
}

/**
 * Was this refused, and for the reason named? Returns '' for yes.
 *
 * @param callable():mixed $attempt
 */
function refusedBecause(callable $attempt, string $wordsExpected): string
{
    try {
        $attempt();
    } catch (QrTestReaderRefusal $e) {
        return (str_contains($e->getMessage(), $wordsExpected) === true) ? '' : 'refused, but for another reason: ' . $e->getMessage();
    } catch (\Throwable $e) {
        return 'stopped with ' . get_class($e) . ': ' . $e->getMessage();
    }
    return 'NOT refused';
}

/**
 * This test's own painter of a grid as a PNG, used only in part A, on
 * qrencode's squares. It is NOT the portal's drawing code.
 *
 * @param list<list<bool>> $grid
 */
function paintPng(array $grid, int $cell, int $border, bool $lightOnDark = false, ?array $smudge = null): string
{
    $n     = count($grid);
    if ($n === 0) {
        // The kept picture this was to be painted from could not be read.
        // Give back nothing, so that the checks that use it FAIL; painting a
        // picture of no size would stop this file part way.
        return '';
    }
    $side  = ($n + 2 * $border) * $cell;
    $image = imagecreatetruecolor($side, $side);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    $grey  = imagecolorallocate($image, 90, 90, 90);
    $paper = ($lightOnDark === true) ? $black : $white;
    $ink   = ($lightOnDark === true) ? $white : $black;
    imagefilledrectangle($image, 0, 0, $side - 1, $side - 1, $paper);
    foreach ($grid as $y => $row) {
        foreach ($row as $x => $dark) {
            if ($dark === true) {
                imagefilledrectangle($image, ($x + $border) * $cell, ($y + $border) * $cell, ($x + $border + 1) * $cell - 1, ($y + $border + 1) * $cell - 1, $ink);
            }
        }
    }
    if ($smudge !== null) {
        // One pixel of a light square made dark: a square that is not one flat colour.
        imagesetpixel($image, ($smudge[0] + $border) * $cell, ($smudge[1] + $border) * $cell, $grey);
    }
    ob_start();
    imagepng($image);
    return (string) ob_get_clean();
}

/**
 * This test's own writer of a grid as an SVG in the form the portal uses,
 * used only in part A, on qrencode's squares.
 *
 * @param list<list<bool>> $grid
 */
function writeSvg(array $grid, int $border): string
{
    $n      = count($grid);
    $across = $n + 2 * $border;
    $d      = '';
    foreach ($grid as $y => $row) {
        foreach ($row as $x => $dark) {
            if ($dark === true) {
                $d .= 'M' . ($x + $border) . ' ' . ($y + $border) . 'h1v1h-1z';
            }
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300" viewBox="0 0 ' . $across . ' ' . $across . '">'
        . '<rect width="100%" height="100%" fill="#ffffff"/><path fill="#000000" d="' . $d . '"/></svg>';
}

echo "The portal's QR pictures, drawn and read back — self-test (#623)\n";
echo 'PHP ' . PHP_VERSION . ', gd ' . (string) (gd_info()['GD Version'] ?? '?') . ($qrencode !== '' ? ', with ' . $qrencode : ', without qrencode') . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

// =============================================================================
head('A. The reader is proved first, on pictures the portal did not draw');
// =============================================================================
$kept      = keptPictures();
$keptGrids = [];
$masksRead = [];
$levelsRead = [];
foreach ($kept as $name => $case) {
    $file = $fixtureDir . $sep . $name . '.txt';
    $why  = '';
    if (is_file($file) === false) {
        $why = 'the kept picture ' . $name . '.txt is missing';
    } else {
        try {
            $grid = QrTestReader::gridFromText((string) file_get_contents($file));
            $read = QrTestReader::readGrid($grid);
            if ($read['text'] !== $case['text']) {
                $why = 'a DIFFERENT text was read (' . strlen($read['text']) . ' bytes for ' . strlen($case['text']) . ')';
            } elseif ($read['level'] !== $case['level']) {
                $why = 'level ' . $read['level'] . ' was read, not ' . $case['level'];
            } else {
                $keptGrids[$name]            = $grid;
                $masksRead[$read['mask']]    = true;
                $levelsRead[$read['level']]  = true;
            }
        } catch (\Throwable $e) {
            $why = get_class($e) . ': ' . $e->getMessage();
        }
    }
    ok('A1 qrencode\'s picture "' . $name . '" (' . strlen($case['text']) . ' bytes, level ' . $case['level'] . ') is read back exactly', $why === '', $why);
}
ksort($masksRead);
ok('A2 between them the kept pictures use all four levels and all eight masks, so the reader is proved on each',
    count($levelsRead) === 4 && count($masksRead) === 8, 'levels: ' . implode(' ', array_keys($levelsRead)) . '; masks: ' . implode(' ', array_keys($masksRead)));

// The reader must say NO to a picture that is wrong. One square is turned
// over in qrencode's own picture, in each kind of place.
$base = $keptGrids['bytes-99'] ?? [];
$turn = static function (array $grid, int $x, int $y): array {
    if (isset($grid[$y][$x]) === true) {
        $grid[$y][$x] = ($grid[$y][$x] === false);
    }
    return $grid;
};
$n = count($base);
ok('A3 one DATA square turned over is refused (a phone would repair it; this reader must not)',
    $n > 0 && refusedBecause(static fn () => QrTestReader::readGrid($turn($base, $n - 1, $n - 1)), 'error-correction words') === '',
    $n === 0 ? 'the picture bytes-99 was not read' : refusedBecause(static fn () => QrTestReader::readGrid($turn($base, $n - 1, $n - 1)), 'error-correction words'));
ok('A4 one FORMAT mark turned over is refused, in the first copy and in the second',
    $n > 0 && refusedBecause(static fn () => QrTestReader::readGrid($turn($base, 8, 0)), 'format marks') === ''
    && refusedBecause(static fn () => QrTestReader::readGrid($turn($base, $n - 1, 8)), 'format marks') === '',
    $n === 0 ? 'the picture bytes-99 was not read' : refusedBecause(static fn () => QrTestReader::readGrid($turn($base, 8, 0)), 'format marks') . ' / ' . refusedBecause(static fn () => QrTestReader::readGrid($turn($base, $n - 1, 8)), 'format marks'));
ok('A5 one square of a corner square, of a timing line and of an alignment square turned over is refused, each',
    $n > 0 && refusedBecause(static fn () => QrTestReader::readGrid($turn($base, 3, 3)), 'top-left corner square') === ''
    && refusedBecause(static fn () => QrTestReader::readGrid($turn($base, 10, 6)), 'timing line') === ''
    && refusedBecause(static fn () => QrTestReader::readGrid($turn($base, $n - 7, $n - 7)), 'alignment square') === '',
    'see the three places');
$big = $keptGrids['size-7-m'] ?? [];
$bn  = count($big);
ok('A6 one SIZE mark turned over is refused (size 7 and above carry them)',
    $bn === 45 && refusedBecause(static fn () => QrTestReader::readGrid($turn($big, $bn - 11, 0)), 'size marks') === '',
    $bn === 0 ? 'the picture size-7-m was not read' : refusedBecause(static fn () => QrTestReader::readGrid($turn($big, $bn - 11, 0)), 'size marks'));
// The old fault itself, done to qrencode's picture: its fifteen format marks written back to front.
$backToFront = $base;
if ($n > 0) {
    $first  = [[8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]];
    $second = [];
    for ($i = 0; $i < 15; $i++) {
        $second[] = ($i <= 7) ? [$n - 1 - $i, 8] : [8, $n - 15 + $i];
    }
    foreach ([$first, $second] as $places) {
        foreach ($places as $i => [$x, $y]) {
            [$fromX, $fromY]     = $places[14 - $i];
            $backToFront[$y][$x] = $base[$fromY][$fromX];
        }
    }
}
ok('A7 the fault of #623 done to qrencode\'s own picture (the format marks written back to front) is refused, and the reader says what is wrong',
    $n > 0 && refusedBecause(static fn () => QrTestReader::readGrid($backToFront), 'written in the wrong order') === '',
    $n === 0 ? 'the picture bytes-99 was not read' : refusedBecause(static fn () => QrTestReader::readGrid($backToFront), 'written in the wrong order'));
ok('A8 a picture that is not a size a QR code can be, or is not square, is refused',
    refusedBecause(static fn () => QrTestReader::readGrid(array_fill(0, 22, array_fill(0, 22, false))), 'no QR code is that size') === ''
    && $n > 0 && refusedBecause(static fn () => QrTestReader::readGrid(array_slice($base, 0, $n - 1)), 'no QR code is that size') === ''
    && refusedBecause(static function () use ($base): void {
        $short = $base;
        array_pop($short[5]);
        QrTestReader::readGrid($short);
    }, 'A QR code is square') === '',
    'see the three shapes');

// Finding the picture inside a PNG and an SVG, proved on qrencode's squares
// painted by this test (not by the portal).
$found = null;
try {
    $found = QrTestReader::gridFromPng(paintPng($base, 3, 4));
} catch (\Throwable $e) {
    $found = ['refused' => $e->getMessage()];
}
ok('A9 in a PNG painted from qrencode\'s squares, 3 pixels a square with a border of 4: the same squares are found, 3 pixels each, border 4, not light-on-dark',
    $n > 0 && ($found['grid'] ?? null) === $base && ($found['cell'] ?? 0) === 3 && ($found['border'] ?? -1) === 4 && ($found['inverted'] ?? null) === false,
    isset($found['refused']) === true ? $found['refused'] : 'cell ' . ($found['cell'] ?? '?') . ', border ' . ($found['border'] ?? '?'));
$narrow = ['mime' => 'image/png', 'bytes' => paintPng($base, 3, 2)];
$none   = ['mime' => 'image/png', 'bytes' => paintPng($base, 3, 0)];
ok('A10 the same with a border of 2, and with none, is refused for its border when 4 is asked for, and read when the border is not asked for',
    $n > 0 && str_contains((string) (readBack($narrow)['refused'] ?? ''), 'clear border round the picture is 2 squares') === true
    && str_contains((string) (readBack($none)['refused'] ?? ''), 'clear border round the picture is 0 squares') === true
    && (readBack($narrow, 2)['text'] ?? null) === $kept['bytes-99']['text'] && (readBack($none, 0)['text'] ?? null) === $kept['bytes-99']['text'],
    'border 2: ' . (string) (readBack($narrow)['refused'] ?? 'read') . ' / none: ' . (string) (readBack($none)['refused'] ?? 'read'));
ok('A11 light squares on a dark background are refused, in words that say so',
    $n > 0 && str_contains((string) (readBack(['mime' => 'image/png', 'bytes' => paintPng($base, 3, 4, true)])['refused'] ?? ''), 'light squares on a dark background') === true,
    (string) (readBack(['mime' => 'image/png', 'bytes' => paintPng($base, 3, 4, true)])['refused'] ?? 'read'));
// (1, 1) is a light square in every QR code: it is inside the top-left corner square's light ring.
ok('A12 a square that is not one flat colour (one grey pixel in it) is refused',
    $n > 0 && str_contains((string) (readBack(['mime' => 'image/png', 'bytes' => paintPng($base, 3, 4, false, [1, 1])])['refused'] ?? ''), 'not one flat colour') === true,
    (string) (readBack(['mime' => 'image/png', 'bytes' => paintPng($base, 3, 4, false, [1, 1])])['refused'] ?? 'read'));
$fromSvg = readBack(['mime' => 'image/svg+xml', 'bytes' => writeSvg($base, 4)]);
ok('A13 in an SVG written from qrencode\'s squares in the form the portal uses: the same text is read, border 4',
    $n > 0 && ($fromSvg['text'] ?? null) === $kept['bytes-99']['text'] && ($fromSvg['border'] ?? -1) === 4 && ($fromSvg['cell'] ?? 0) === 1,
    (string) ($fromSvg['refused'] ?? 'border ' . ($fromSvg['border'] ?? '?')));
ok('A14 an SVG that holds a drawing command, or an element, the reader does not know is refused, not guessed at',
    refusedBecause(static fn () => QrTestReader::gridFromSvg(str_replace('h1v1h-1z', 'l1 0v1h-1z', writeSvg($base, 4))), 'drawing command this reader does not know') === ''
    && refusedBecause(static fn () => QrTestReader::gridFromSvg(str_replace('<path', '<circle cx="5" cy="5" r="2"/><path', writeSvg($base, 4))), 'an element this reader does not know') === '',
    'see the two SVGs');

// =============================================================================
head('B. The portal\'s own pictures, read back');
// =============================================================================
$tried = ['HELLO' => 'HELLO', 'a 35-byte address' => 'https://portal.example.org/a/ABC123'];
foreach ([27, 99, 155, 170, 200] as $bytes) {
    $tried[$bytes . ' bytes'] = sampleText($bytes, $bytes);
}
foreach (['svg' => 'image/svg+xml', 'png' => 'image/png'] as $format => $mime) {
    $wrong = [];
    foreach ($tried as $label => $text) {
        $why = drawnAndRead($text, ['format' => $format, 'size' => 256], null, 'M', $mime);
        if ($why !== '') {
            $wrong[] = $label . ': ' . $why;
        }
    }
    ok('B1 the texts that no reader could read in October (HELLO, a 35-byte address, 27, 99, 155, 170 and 200 bytes) are read back exactly, as ' . strtoupper($format),
        $wrong === [], implode(' | ', array_slice($wrong, 0, 4)));
}

// Every size at every level, filled exactly; and one byte more, which must
// take the next size up. The portal's choice of mask is gathered on the way.
$masksUsed  = [];
$notLowest  = [];
$borderSeen = [];
$drawn      = 0;
foreach (LEVELS as $level) {
    $wrongFull = [];
    $wrongOver = [];
    for ($version = 1; $version <= 40; $version++) {
        $capacity = QrTestReader::capacityInBytes($version, $level);
        foreach ([$capacity, $capacity + 1] as $bytes) {
            if ($version === 40 && $bytes > $capacity) {
                continue; // one over the biggest is part C
            }
            $text = sampleBytes($bytes, $version * 7 + $bytes);
            $want = ($bytes === $capacity) ? $version : $version + 1;
            $why  = '';
            $read = [];
            try {
                $read = readBack(Qr::generate($text, ['ecc' => $level]));
            } catch (\Throwable $e) {
                $why = 'not drawn: ' . get_class($e) . ': ' . $e->getMessage();
            }
            $drawn++;
            if ($why === '' && isset($read['refused']) === true) {
                $why = 'not read: ' . $read['refused'];
            } elseif ($why === '' && ($read['text'] !== $text || $read['version'] !== $want || $read['level'] !== $level)) {
                $why = 'read as size ' . $read['version'] . ' level ' . $read['level'] . ', text ' . ($read['text'] === $text ? 'the same' : 'DIFFERENT');
            }
            if ($why !== '') {
                if ($bytes === $capacity) {
                    $wrongFull[] = 'size ' . $version . ' (' . $bytes . ' bytes): ' . $why;
                } else {
                    $wrongOver[] = 'size ' . $version . ' + 1 byte (' . $bytes . '): ' . $why;
                }
                continue;
            }
            $masksUsed[$read['mask']]    = true;
            $borderSeen[$read['border']] = true;
            if ($read['penalties'][$read['mask']] !== min($read['penalties'])) {
                $notLowest[] = 'level ' . $level . ' size ' . $read['version'] . ': mask ' . $read['mask'];
            }
        }
    }
    ok('B2 level ' . $level . ': every size from 1 to 40, filled to its last byte, is read back exactly, at that size and that level',
        $wrongFull === [], count($wrongFull) . ' wrong: ' . implode(' | ', array_slice($wrongFull, 0, 3)));
    ok('B3 level ' . $level . ': one byte more than each size holds is drawn one size bigger and read back exactly',
        $wrongOver === [], count($wrongOver) . ' wrong: ' . implode(' | ', array_slice($wrongOver, 0, 3)));
}
ksort($masksUsed);
ok('B4 across those ' . $drawn . ' pictures the portal used all eight masks, and each time the one with the lowest count by the reader\'s own counting',
    $drawn === 316 && count($masksUsed) === 8 && $notLowest === [], 'masks used: ' . implode(' ', array_keys($masksUsed)) . '; not the lowest: ' . count($notLowest) . ' ' . implode(' | ', array_slice($notLowest, 0, 3)));
ok('B5 every one of them had a clear border of exactly four squares, drawn into the picture itself',
    array_keys($borderSeen) === [4], 'borders seen: ' . implode(' ', array_keys($borderSeen)));

// The same as PNG, at the usual level: real pixels.
$wrong = [];
foreach ([1, 2, 6, 7, 9, 10, 14, 20, 21, 27, 28, 32, 35, 40] as $version) {
    $text = sampleBytes(QrTestReader::capacityInBytes($version, 'M'), 500 + $version);
    $why  = drawnAndRead($text, ['format' => 'png', 'size' => 600], $version, 'M', 'image/png');
    if ($why !== '') {
        $wrong[] = 'size ' . $version . ': ' . $why;
    }
}
ok('B6 as PNG, fourteen sizes from 1 to 40 filled to the last byte are read back from their pixels', $wrong === [], implode(' | ', array_slice($wrong, 0, 3)));

$address = longAuthenticatorAddress();
$read    = readBack(Qr::generate($address, ['size' => 250, 'ecc' => 'M']));
parse_str((string) parse_url((string) ($read['text'] ?? ''), PHP_URL_QUERY), $asked);
ok('B7 an authenticator address of the shape the set-up page makes, with a long organisation name and e-mail address (' . strlen($address) . ' bytes), is read back exactly, and its key is the key that went in',
    strlen($address) >= 240 && ($read['text'] ?? null) === $address && str_starts_with($address, 'otpauth://totp/') === true && ($asked['secret'] ?? '') === 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
    (string) ($read['refused'] ?? 'length ' . strlen($address) . '; secret read: ' . (($asked['secret'] ?? '') === 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' ? 'the same' : 'DIFFERENT')));
$dataUri = Qr::dataUri($address, ['size' => 250, 'ecc' => 'M']);
$inside  = (str_starts_with($dataUri, 'data:image/svg+xml;base64,') === true) ? base64_decode(substr($dataUri, 26), true) : false;
ok('B8 Qr::dataUri() gives that picture as one "data:" address that holds the same picture, and the address shows neither the key nor the word otpauth',
    $inside !== false && (readBack(['mime' => 'image/svg+xml', 'bytes' => (string) $inside])['text'] ?? null) === $address
    && str_contains($dataUri, 'JBSWY3DP') === false && stripos($dataUri, 'otpauth') === false && str_contains((string) $inside, 'JBSWY3DP') === false,
    'starts: ' . substr($dataUri, 0, 30));
$pngUri = Qr::dataUri('HELLO', ['format' => 'png']);
ok('B9 asked for a PNG, Qr::dataUri() gives a "data:image/png" address that reads back',
    str_starts_with($pngUri, 'data:image/png;base64,') === true && (readBack(['mime' => 'image/png', 'bytes' => (string) base64_decode(substr($pngUri, 22), true)])['text'] ?? null) === 'HELLO', substr($pngUri, 0, 30));

$accents = $kept['accents']['text'];
ok('B10 accented and non-Latin letters (Danish, Spanish, German, Greek, Japanese, Arabic, Hebrew and a smiley) come back byte for byte, as SVG and as PNG',
    mb_check_encoding($accents, 'UTF-8') === true && drawnAndRead($accents, []) === '' && drawnAndRead($accents, ['format' => 'png']) === '',
    drawnAndRead($accents, []) . ' / ' . drawnAndRead($accents, ['format' => 'png']));
ok('B11 every byte value from 0 to 255 comes back, and so does a text of no bytes at all',
    drawnAndRead(allByteValues(), ['ecc' => 'L']) === '' && drawnAndRead('', [], 1, 'M') === '', drawnAndRead(allByteValues(), ['ecc' => 'L']) . ' / ' . drawnAndRead('', [], 1, 'M'));

$text = sampleText(40, 77);
ok('B12 the level may be written in small letters, and a level that is none of the four counts as M',
    drawnAndRead($text, ['ecc' => 'l'], null, 'L') === '' && drawnAndRead($text, ['ecc' => 'q'], null, 'Q') === '' && drawnAndRead($text, ['ecc' => 'h'], null, 'H') === ''
    && drawnAndRead($text, ['ecc' => 'X'], null, 'M') === '' && drawnAndRead($text, [], null, 'M') === '',
    'see the five levels');

// The picture's size.
$svg = Qr::generate($text, ['size' => 300]);
$png = Qr::generate($text, ['size' => 300, 'format' => 'png']);
$pngRead = readBack($png);
ok('B13 the picture is the size asked for: the SVG says 300 by 300, and the PNG is 300 by 300 pixels with squares of a whole number of pixels',
    $svg['mime'] === 'image/svg+xml' && preg_match('/^<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg" width="300" height="300" viewBox="0 0 (\d+) \1"/', $svg['bytes']) === 1
    && $png['mime'] === 'image/png' && ($pngRead['width'] ?? 0) === 300 && ($pngRead['height'] ?? 0) === 300 && ($pngRead['cell'] ?? 0) === intdiv(300, 29 + 8) && ($pngRead['border'] ?? 0) >= 4,
    'PNG: ' . (string) ($pngRead['refused'] ?? ($pngRead['width'] . ' by ' . $pngRead['height'] . ', ' . $pngRead['cell'] . ' pixels a square')));
// The biggest picture asked for at the smallest size: 185 squares across will not fit 64 pixels.
$full    = sampleBytes(QrTestReader::capacityInBytes(40, 'M'), 4040);
$tiny    = Qr::generate($full, ['size' => 64, 'format' => 'png']);
$tinyRead = readBack($tiny);
ok('B14 a PNG asked for smaller than its squares need is made bigger, never blank: size 40 at 64 pixels comes back 185 pixels across and reads',
    $tiny['mime'] === 'image/png' && ($tinyRead['text'] ?? null) === $full && ($tinyRead['width'] ?? 0) === 185 && ($tinyRead['cell'] ?? 0) === 1,
    (string) ($tinyRead['refused'] ?? ($tinyRead['width'] . ' pixels across')));
$small = readBack(Qr::generate($text, ['size' => 5, 'format' => 'png']));
// The biggest is only measured, not read: looking through four million pixels would take this test a long time.
$huge  = (array) getimagesizefromstring(Qr::generate($text, ['size' => 900000, 'format' => 'png'])['bytes']);
$hugeSvg = Qr::generate($text, ['size' => 900000]);
ok('B15 a size below 64 is drawn at 64 and a size above 2048 at 2048 (PNG and SVG), so one request cannot ask for a picture too big for the server',
    ($small['width'] ?? 0) === 64 && ($small['text'] ?? null) === $text && ($huge[0] ?? 0) === 2048 && ($huge[1] ?? 0) === 2048
    && str_contains($hugeSvg['bytes'], 'width="2048" height="2048"') === true && (readBack($hugeSvg)['text'] ?? null) === $text,
    (string) ($small['refused'] ?? $small['width']) . ' / ' . (string) ($huge[0] ?? '?') . ' by ' . (string) ($huge[1] ?? '?'));

// A caption, colours.
$captioned    = Qr::generate($text, ['size' => 256, 'caption' => 'Hall <A> & kitchen']);
$captionedPng = Qr::generate($text, ['size' => 256, 'format' => 'png', 'caption' => 'Hall A']);
$capRead      = readBack($captionedPng);
ok('B16 with a caption the picture still reads, as SVG and as PNG; the words are under the clear border, the picture is taller by 24 (SVG) or 20 (PNG) pixels, and the words are escaped',
    (readBack($captioned)['text'] ?? null) === $text && str_contains($captioned['bytes'], 'height="280"') === true && str_contains($captioned['bytes'], '>Hall &lt;A&gt; &amp; kitchen</text>') === true
    && ($capRead['text'] ?? null) === $text && ($capRead['width'] ?? 0) === 256 && ($capRead['height'] ?? 0) === 276,
    (string) (readBack($captioned)['refused'] ?? '') . ' / ' . (string) ($capRead['refused'] ?? ($capRead['width'] . ' by ' . $capRead['height'])));
$coloured = Qr::generate($text, ['fg' => '#1b2a49', 'bg' => '#fff8e7']);
$badColour = Qr::generate($text, ['fg' => 'red', 'bg' => '#12"><script>']);
ok('B17 a dark colour on a light one reads back as SVG and PNG; a colour that is not six hex digits is replaced by black or white and never written into the picture',
    (readBack($coloured)['text'] ?? null) === $text && str_contains($coloured['bytes'], 'fill="#1b2a49"') === true && str_contains($coloured['bytes'], 'fill="#fff8e7"') === true
    && drawnAndRead($text, ['fg' => '#1b2a49', 'bg' => '#fff8e7', 'format' => 'png']) === ''
    && (readBack($badColour)['text'] ?? null) === $text && str_contains($badColour['bytes'], 'script') === false && str_contains($badColour['bytes'], 'fill="#000000"') === true && str_contains($badColour['bytes'], 'fill="#ffffff"') === true,
    (string) (readBack($coloured)['refused'] ?? '') . ' / ' . (string) (readBack($badColour)['refused'] ?? ''));
$twoHundred = Qr::generate(sampleText(200, 200));
ok('B18 the SVG of a 200-byte text is small enough to write into a page (under 20,000 bytes; it was about 90,000)',
    strlen($twoHundred['bytes']) < 20000 && strlen($twoHundred['bytes']) > 2000, strlen($twoHundred['bytes']) . ' bytes');

// =============================================================================
head('C. What the class refuses');
// =============================================================================
$longest = ['L' => 2953, 'M' => 2331, 'Q' => 1663, 'H' => 1273];
ok('C1 Qr::longestText() gives the standard\'s own limits (2,953, 2,331, 1,663 and 1,273 bytes), and they are what the reader works out for size 40',
    Qr::longestText('L') === 2953 && Qr::longestText() === 2331 && Qr::longestText('q') === 1663 && Qr::longestText('H') === 1273
    && array_map(static fn (string $level): int => QrTestReader::capacityInBytes(40, $level), LEVELS) === array_values($longest),
    'L ' . Qr::longestText('L') . ', M ' . Qr::longestText() . ', Q ' . Qr::longestText('Q') . ', H ' . Qr::longestText('H'));
foreach ($longest as $level => $most) {
    $said = '';
    $kind = '';
    try {
        $picture = Qr::generate(sampleText($most + 1, 9), ['ecc' => $level]);
        $back    = readBack($picture);
        $said    = 'NOT refused: a picture came back' . (isset($back['text']) === true ? ' holding ' . strlen((string) $back['text']) . ' bytes' : '');
    } catch (\LengthException $e) {
        $kind = 'refused';
        $said = $e->getMessage();
    } catch (\Throwable $e) {
        $said = 'stopped with ' . get_class($e) . ': ' . $e->getMessage();
    }
    ok('C2 level ' . $level . ': one byte more than the longest (' . number_format($most + 1) . ' bytes) is refused in plain words that give both numbers, and is never cut short',
        $kind === 'refused' && str_contains($said, 'too long for a QR code') === true && str_contains($said, number_format($most + 1) . ' bytes') === true
        && str_contains($said, number_format($most) . ' bytes at error-correction level ' . $level) === true && preg_match('/exception|overflow|capacity|codeword|version|str_repeat/i', $said) !== 1,
        $said);
}
$dataRefused = '';
try {
    Qr::dataUri(sampleText(3000, 1));
    $dataRefused = 'NOT refused';
} catch (\LengthException $e) {
    $dataRefused = 'refused';
}
ok('C3 Qr::dataUri() refuses the same way, with no half-made address', $dataRefused === 'refused', $dataRefused);

// =============================================================================
if ($qrencode !== '') {
    head('Q. Against qrencode, there and then, at every size and level');
    // =========================================================================
    $sameMask  = 0;
    $sameGrid  = 0;
    $compared  = 0;
    foreach (LEVELS as $level) {
        $readerWrong = [];
        $portalWrong = [];
        for ($version = 1; $version <= 40; $version++) {
            $capacity = QrTestReader::capacityInBytes($version, $level);
            foreach ([$capacity, $capacity + 1] as $bytes) {
                if ($version === 40 && $bytes > $capacity) {
                    continue;
                }
                $text = sampleBytes($bytes, $version * 11 + $bytes);
                $want = ($bytes === $capacity) ? $version : $version + 1;
                $theirs = qrencodeSquares($qrencode, $text, $level);
                try {
                    $their = QrTestReader::readGrid($theirs);
                } catch (\Throwable $e) {
                    $readerWrong[] = 'size ' . $version . ' (' . $bytes . ' bytes): ' . $e->getMessage();
                    continue;
                }
                if ($their['text'] !== $text || $their['version'] !== $want || $their['level'] !== $level) {
                    $readerWrong[] = 'size ' . $version . ' (' . $bytes . ' bytes): read as size ' . $their['version'] . ' level ' . $their['level'];
                    continue;
                }
                try {
                    $ours = QrTestReader::gridFromSvg(Qr::generate($text, ['ecc' => $level])['bytes']);
                    $our  = QrTestReader::readGrid($ours['grid']);
                } catch (\Throwable $e) {
                    $portalWrong[] = 'size ' . $version . ' (' . $bytes . ' bytes): ' . $e->getMessage();
                    continue;
                }
                $compared++;
                if ($our['version'] !== $their['version'] || count($ours['grid']) !== count($theirs)) {
                    $portalWrong[] = 'size ' . $version . ' (' . $bytes . ' bytes): the portal drew size ' . $our['version'] . ', qrencode size ' . $their['version'];
                    continue;
                }
                if ($our['mask'] === $their['mask']) {
                    $sameMask++;
                    if ($ours['grid'] === $theirs) {
                        $sameGrid++;
                    } else {
                        $portalWrong[] = 'size ' . $version . ' (' . $bytes . ' bytes): same mask, but the squares differ';
                    }
                }
            }
        }
        ok('Q1 level ' . $level . ': the reader reads qrencode\'s picture at every size, filled to the last byte and one byte over (so every number in the reader\'s tables is right)',
            $readerWrong === [], count($readerWrong) . ' wrong: ' . implode(' | ', array_slice($readerWrong, 0, 3)));
        ok('Q2 level ' . $level . ': the portal draws the same text at the same size as qrencode, and wherever the two chose the same mask the pictures are the same, square for square',
            $portalWrong === [], count($portalWrong) . ' wrong: ' . implode(' | ', array_slice($portalWrong, 0, 3)));
    }
    ok('Q3 all 316 pairs were compared, and the two programs chose the same mask often enough for "square for square" to mean something (at least 100 times)',
        $compared === 316 && $sameMask >= 100 && $sameGrid === $sameMask, $compared . ' compared; same mask ' . $sameMask . ' times; identical ' . $sameGrid . ' times');
    note('the two programs chose the same mask in ' . $sameMask . ' of ' . $compared . ' pairs; in every one of those the pictures were identical.');
}

$expected = EXPECTED_CHECKS + (($qrencode !== '') ? EXTRA_CHECKS_WITH_QRENCODE : 0);
echo "\n{$pass} passed, {$fail} failed, " . ($pass + $fail) . ' ran of ' . $expected . " expected.\n";
if (($pass + $fail) !== $expected) {
    echo "FAIL — the number of checks that ran is not the number this file expects. A block was skipped or ran twice,\n";
    echo "or a check was added without updating the number at the top.\n";
    exit(1);
}
if ($fail > 0) {
    echo "FAIL — {$fail} check(s) failed. Read the FAIL lines above.\n";
    exit(1);
}
if ($qrencode === '') {
    note('part Q (every size and level against qrencode, there and then) was NOT run. Run with --with-qrencode where that program is installed.');
}
echo "PASS — all {$pass} checks passed.\n";
echo "REACHED THE END — the QR self-test ran every part" . (($qrencode !== '') ? ', part Q included' : ' except part Q') . ".\n";
exit(0);
