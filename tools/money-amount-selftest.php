<?php
// Path: tools/money-amount-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test: a typed amount is read strictly, or refused with the reason (#1004) 💷
 * -----------------------------------------------------------------------------
 * Every money box used to turn its text into hundredths with
 * `(int) round((float) $typed * 100)`. Measured on PHP 8.5 (issue #1004): an
 * absurd figure WRAPPED — "184467440737095557.12" was stored silently as 40.96,
 * "1e17" came out negative so the page said "Amount cannot be negative" (untrue),
 * "-1e30" came out as a large positive number. Portal\Core\Currency::parseAmount()
 * is now the ONE converter, with two modes: a box a person types into takes
 * FIGURES ONLY (the owner, D-152); a FILE the portal reads (`fromFile`) — the
 * bank-statement import (D-152) and the asset import (D-153), nothing else —
 * also reads commas between groups of three and the record's own currency
 * sign or code: a sign is read as the organisation's OWN currency whenever
 * that currency is written with it ("$" is Australian dollars in an AUD
 * organisation; the owner, D-165, superseding D-155 and D-157). A file's
 * refusal gives a specific reason only where it is certain (round 9); a cell
 * beginning with a currency sign or code that is not read as the
 * organisation's currency HERE is told exactly that — a claim about this
 * portal, never "the currency does not use it", which was untrue of "₱" for the
 * Cuban peso (round 11) — and to convert the figure rather than relabel it,
 * without naming which other currency it is (round 10); everything else uncertain is
 * "That amount could not be read." with what IS read. No file refusal ever
 * advises a sign, nor putting the organisation's code in front of a figure
 * that may be in another currency.
 * This test proves it:
 *
 *   A  the converter itself (no database), over a TABLE OF DIMENSIONS rather
 *      than a few examples, in BOTH modes: every count of figures before the
 *      point (1 to 20) against every count after it (0 to 4), each sign, the
 *      same figures grouped with commas (1 to 15 figures), each kind of
 *      exponent, each placement of commas (a first group starting 0 included),
 *      whitespace at each end and inside, the empty box, each maximum (an INT
 *      column, a DECIMAL(10,2) column, a caller's own) at exactly the limit and
 *      one hundredth over, the currency's own sign and code against another's,
 *      non-ASCII figures, and every two-decimal amount from 0.00 to 99.99 for
 *      exactness; then parseHundredths() (the asset API's pence fields),
 *      storedDecimalToHundredths() and hundredthsToDecimal();
 *   B  over real HTTP, the FOUR venue handlers the issue names — invoice save
 *      (new and edited), allocation, payment, agreement and booking — each
 *      posted the issue's three values. Each is refused with a TRUE message
 *      read back off the page, and the database is read to prove nothing was
 *      stored;
 *   C  each handler is then posted an ordinary figure, which IS stored
 *      exactly: without that, "nothing stored" could mean a dead fixture
 *      rather than a refusal (#645's lesson);
 *   D  silent faults of the old code, and D-152's commas and signs, refused;
 *   E  projects, a gift, a giving campaign and the AI cap, each refused once
 *      and stored once;
 *   F  an offering count refuses a figure it could save but never CLOSE (the
 *      gift log is a signed INT), and a count at exactly the limit closes;
 *   G  the bank-statement import: a statement's commas and own sign read, a
 *      debit too large to store skipped and named rather than stopping the
 *      file, a bad cell refused with the converter's own reason, and a sign
 *      while NO currency is chosen refused saying so and what to do;
 *   H  every money EDIT form (venue invoice, agreement, booking, project,
 *      giving campaign, AI cap; the count form in F2b) read off its real page
 *      with an amount above 1,000 and posted back UNCHANGED: it must be
 *      prefilled with plain figures and save, or a plain Save is refused over
 *      a figure nobody typed;
 *   I  the asset import: "£1,234.56", "1,000" and "GBP 7.50" import exactly;
 *      a sign that is not read as GBP here is refused saying so (convert, do
 *      not relabel), and a misplaced comma with its reason.
 *
 * WHAT IT CANNOT SHOW, said plainly:
 *   - several handlers moved onto the converter are NOT driven here with a
 *     refused figure: payments checkout, project pledges, resources, asset
 *     save and maintenance, the asset API, the giving pledge, and the
 *     offering count's envelopes and "resolve". They call the converter
 *     section A tests; tools/currency-selftest.php drives several of them
 *     with ordinary figures only. Nor are their edit forms round-tripped in H
 *     (asset edit and maintenance, resources, the count's "resolve" form);
 *   - a browser: a number box would normally stop "1e17" being typed at all.
 *     A crafted request is exactly what reaches the handler, so that is what
 *     this posts.
 *
 * Run it on a THROWAWAY database built from web/_sql/full_schema.sql:
 *     SELFTEST_DB_NAME=selftest_money [SELFTEST_DB_HOST=… SELFTEST_DB_PORT=…
 *     SELFTEST_DB_USER=… SELFTEST_DB_PASS=…] [SELFTEST_WEB_PORT=9533]
 *         php tools/money-amount-selftest.php
 * Section A runs with no database at all (MONEY_SELFTEST_UNIT_ONLY=1 stops
 * there and says so). Section B needs curl and proc_open, and REFUSES rather
 * than skipping without them.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1004
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** The made-up global administrator. Nothing else in tools/ uses 10040x. */
const MA_ADMIN = 100401;

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null];

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\n";
    echo "Nothing more was checked, and this run fails. A skipped test reads exactly\n";
    echo "like a passing one, which is why this refuses rather than skipping.\n";
    exit(2);
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok === true) {
        $passed += 1;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed += 1;
    echo "  ✗ {$name}\n";
    if ($detail !== '') {
        echo "      {$detail}\n";
    }
}

function head(string $text): void
{
    echo "\n{$text}\n";
}

spl_autoload_register(static function (string $class) use ($repo, $sep): void {
    if (str_starts_with($class, 'Portal\\Core\\') === false) {
        return;
    }
    $file = $repo . $sep . 'web' . $sep . '_core' . $sep . substr($class, strlen('Portal\\Core\\')) . '.php';
    if (is_file($file) === true) {
        require_once $file;
    }
});

use Portal\Core\Currency;

const TOO_LARGE_INT = 'That amount is too large: the most that can be stored here is 21,474,836.47.';
const POWER_OF_TEN  = 'Write the amount out in full figures, for example 1500.00: a power of ten such as 1e3 is not accepted.';
const FIGURES_ONLY  = 'Type the amount in figures only, for example 1234.50: no commas, spaces, currency signs, letters or other characters.';
/**
 * The one way a FILE refusal says how to write an amount: figures and commas,
 * never a sign; the CODE only as confirmation of a figure ALREADY in that
 * currency, never "in front if you like" (round 10: that invited relabelling).
 */
function advice(string $code, bool $allowNegative = false): string
{
    // Round 17: where negatives are allowed the advice keeps the minus.
    $keep = $allowNegative === true ? ' Keep the minus sign in front of money paid out, for example -1,234.56.' : '';

    return ($code !== ''
        ? 'Write it in figures, with commas only between thousands if you like, for example 1,234.56. '
            . 'The code ' . $code . ' may go in front of a figure that is already in ' . $code . ' (' . $code . ' 1,234.56), '
            . 'but never in front of an amount in another currency.'
        : 'Write it in figures, with commas only between thousands if you like, for example 1,234.56.') . $keep;
}
/** Rounds 10-11: what a file cell beginning with a sign or code not read as `$code` here is told. A claim about the portal; never names the other currency. */
function convertWords(string $code): string
{
    return 'Only ' . $code . ' amounts are read here: if this figure is in another currency, convert it to ' . $code
        . ' first — do not just put ' . $code . ' in front of it.';
}
/**
 * Round 14: the ONE sentence for any cell with more than one currency marker in
 * front, the first ours. Round 16: where negatives are allowed it keeps the
 * minus, or following it would turn a payment out into a credit.
 */
function many(string $code, bool $allowNegative = false): string
{
    $front = $allowNegative === true
        ? 'nothing in front but its minus sign, if it has one, and at most the code ' . $code
        : 'nothing in front but, at most, the code ' . $code;

    return 'That amount has more than one currency sign or code in front. Only ' . $code . ' amounts are read here: '
        . 'write the figure with ' . $front . ', and if it is in another currency, convert it to ' . $code . ' first.';
}
function otherCur(string $code): string
{
    return 'That amount starts with a currency sign or code that is not read as ' . $code . ' here. ' . convertWords($code);
}
/** The ONE file-mode sentence for a refusal whose reason is not certain (#1004 round 9). */
function unreadable(string $code, bool $allowNegative = false): string
{
    return 'That amount could not be read. ' . advice($code, $allowNegative);
}
/** Round 18: where no negative is allowed, a cell with a minus anywhere that could not be read is also told this. */
const NOT_NEGATIVE_HERE = 'Amounts here cannot be negative.';
/** The could-not-be-read refusal for cell `$t` in the given mode, with round 18's note where it applies. */
function unreadableFor(string $t, string $code, bool $allowNegative = false): string
{
    return unreadable($code, $allowNegative) . (($allowNegative === false && str_contains($t, '-') === true) ? ' ' . NOT_NEGATIVE_HERE : '');
}
const FILE_NOT_FIGURES = 'That amount could not be read. Write it in figures, with commas only between thousands if you like, for example 1,234.56.';
const PLUS          = 'Leave out the plus sign: type the amount in figures, for example 12.50.';
const MINUS_PLACE   = 'A minus sign can only come first, for example -12.50.';
const ONE_POINT     = 'An amount can have only one decimal point, for example 12.50.';
const NO_FIGURES    = 'That has no figures in it: type the amount in figures, for example 12.50.';
const FILE_NO_FIGURES = FILE_NOT_FIGURES;
const FILE_PLUS     = FILE_NOT_FIGURES;
const MINUS_NOT_HERE      = 'A minus sign has no place here: the amount cannot be negative. Type the figures only, for example 12.50.';
const FILE_MINUS_NOT_HERE = 'A minus sign has no place here: the amount cannot be negative. Write it in figures, with commas only between thousands if you like, for example 1,234.56.';
const NO_CURRENCY_START  = 'That amount is written with a currency (';
const NO_CURRENCY_END    = '), but no currency is chosen for this organisation, so the portal cannot tell what money it is. Choose the organisation\'s currency in Admin, Settings, Organisation, then import the file again.';
const BAD_COMMAS    = 'The commas in that amount are in the wrong places: a comma may only separate groups of three figures (1,234.50), and the decimals follow a point (12.50).';
const TOO_PRECISE   = 'An amount can have at most two figures after the decimal point, for example 12.50.';
const NEGATIVE      = 'The amount cannot be negative.';

/**
 * The expected answer for figures written out, worked out here by TEXT arithmetic
 * the same way a person would, independently of the converter's own code.
 */
function expectedFor(string $whole, string $fraction, bool $negative, int $max, bool $allowNegative): int|string
{
    if (strlen($fraction) > 2) {
        return TOO_PRECISE;
    }
    $digits = ltrim($whole . str_pad($fraction, 2, '0'), '0');
    $digits = $digits === '' ? '0' : $digits;
    if ($negative === true && $digits !== '0' && $allowNegative === false) {
        return NEGATIVE;
    }
    $limit = (string) $max;
    if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
        return 'too large';
    }
    $n = (int) $digits;

    return $negative === true ? -$n : $n;
}

// =============================================================================
// A. The converter, with no database
// =============================================================================
head('Section A — Currency::parseAmount() over a table of dimensions, in both modes (no database)');

// The two modes (D-152): a box a person types into takes FIGURES ONLY; the
// bank-statement import (`$fromFile`) also reads commas between groups of
// three and the import's own currency sign, because that is how banks write.
$modes = ['typed' => false, 'statement' => true];

// A1 — figures before the point (1-20) × figures after it (0-4) × sign × both
// modes, against the INT maximum. Each figure is a 7, so 7777…, which crosses
// the INT limit at eight figures before the point: the table covers both sides.
$mismatches = [];
$cells      = 0;
foreach ($modes as $modeName => $fromFile) {
    foreach ([false, true] as $negative) {
        foreach ([false, true] as $allowNegative) {
            for ($w = 1; $w <= 20; $w++) {
                for ($f = 0; $f <= 4; $f++) {
                    $whole    = str_repeat('7', $w);
                    $fraction = str_repeat('3', $f);
                    $text     = ($negative === true ? '-' : '') . $whole . ($f > 0 ? '.' . $fraction : '');
                    $want     = expectedFor($whole, $fraction, $negative, Currency::INT_COLUMN_MAX, $allowNegative);
                    $got      = Currency::parseAmount($text, '', Currency::INT_COLUMN_MAX, $allowNegative, $fromFile);
                    $same     = $want === 'too large' ? (is_string($got) === true && str_starts_with($got, Currency::REFUSED_TOO_LARGE_START)) : $got === $want;
                    $cells++;
                    if ($same === false) {
                        $mismatches[] = $modeName . ' ' . $text . ($allowNegative === true ? ' (negatives allowed)' : '') . ' → ' . var_export($got, true) . ', wanted ' . var_export($want, true);
                    }
                }
            }
        }
    }
}
check('A1 every count of figures before the point (1 to 20) against every count after it (0 to 4), with and without a minus, negatives allowed or not, in BOTH modes (' . $cells . ' cells): stored exactly when it fits, refused with the right reason when it does not',
    $mismatches === [] && $cells === 800, implode(' | ', array_slice($mismatches, 0, 5)));

// A1s — the same, with the figures before the point written in groups of three
// with commas (1 to 15 figures, so 0 to 4 commas), in both modes: the
// statement reads them exactly; a typed box refuses every one with the
// figures-only sentence.
$mismatches = [];
$grouped    = 0;
for ($w = 1; $w <= 15; $w++) {
    for ($f = 0; $f <= 2; $f++) {
        $whole    = str_repeat('4', $w);
        $withCommas = strrev(implode(',', str_split(strrev($whole), 3)));
        $text     = $withCommas . ($f > 0 ? '.' . str_repeat('6', $f) : '');
        $want     = expectedFor($whole, str_repeat('6', $f), false, Currency::INT_COLUMN_MAX, false);
        $gotS     = Currency::parseAmount($text, '', Currency::INT_COLUMN_MAX, false, true);
        $gotT     = Currency::parseAmount($text, '');
        $sameS    = $want === 'too large' ? (is_string($gotS) === true && str_starts_with($gotS, Currency::REFUSED_TOO_LARGE_START)) : $gotS === $want;
        $sameT    = $w <= 3 ? $gotT === $gotS : $gotT === Currency::REFUSED_NOT_FIGURES;
        $grouped++;
        if ($sameS === false || $sameT === false) {
            $mismatches[] = $text . ' → statement ' . var_export($gotS, true) . ', typed ' . var_export($gotT, true);
        }
    }
}
check('A1s figures written in groups of three with commas (1 to 15 figures, 0 to 4 commas, 0 to 2 decimals; ' . $grouped . ' cells): the STATEMENT mode reads each exactly; a TYPED box refuses every one that has a comma, with the figures-only sentence (D-152)',
    $mismatches === [] && $grouped === 45, implode(' | ', array_slice($mismatches, 0, 5)));

// A2 — the same table against the DECIMAL(10,2) maximum and a caller's own maximum.
$mismatches = [];
foreach ([Currency::DECIMAL_10_2_MAX, 100000, 0] as $max) {
    for ($w = 1; $w <= 12; $w++) {
        for ($f = 0; $f <= 2; $f++) {
            $whole = str_repeat('9', $w);
            $text  = $whole . ($f > 0 ? '.' . str_repeat('9', $f) : '');
            $want  = expectedFor($whole, str_repeat('9', $f), false, $max, false);
            $got   = Currency::parseAmount($text, '', $max);
            $same  = $want === 'too large' ? (is_string($got) === true && str_starts_with($got, Currency::REFUSED_TOO_LARGE_START)) : $got === $want;
            if ($same === false) {
                $mismatches[] = $text . ' (max ' . $max . ') → ' . var_export($got, true);
            }
        }
    }
}
check('A2 the same figures against a DECIMAL(10,2) maximum, a caller\'s own maximum (1,000.00) and a maximum of nothing: each refused above its limit and nowhere else',
    $mismatches === [], implode(' | ', array_slice($mismatches, 0, 5)));

// A3 — each maximum at exactly the limit and one hundredth over.
check('A3 the INT column\'s limit: 21474836.47 is 2147483647 exactly; 21474836.48 is refused, saying what the most is',
    Currency::parseAmount('21474836.47', '') === 2147483647 && Currency::parseAmount('21474836.48', '') === TOO_LARGE_INT,
    var_export(Currency::parseAmount('21474836.48', ''), true));
check('A3b the DECIMAL(10,2) limit: 99999999.99 is 9999999999 exactly; 100000000.00 is refused, and the sentence names 99,999,999.99',
    Currency::parseAmount('99999999.99', '', Currency::DECIMAL_10_2_MAX) === 9999999999
    && Currency::parseAmount('100000000.00', '', Currency::DECIMAL_10_2_MAX) === 'That amount is too large: the most that can be stored here is 99,999,999.99.');
check('A3c with negatives allowed, the limit holds on that side too: -21474836.47 is accepted, -21474836.48 refused as too large',
    Currency::parseAmount('-21474836.47', '', allowNegative: true) === -2147483647 && Currency::parseAmount('-21474836.48', '', allowNegative: true) === TOO_LARGE_INT);
check('A3d the too-large sentence is written in the currency given: £21,474,836.47 for pounds, CHF 21,474,836.47 for francs',
    Currency::parseAmount('99999999999', 'GBP') === 'That amount is too large: the most that can be stored here is £21,474,836.47.'
    && Currency::parseAmount('99999999999', 'CHF') === 'That amount is too large: the most that can be stored here is CHF 21,474,836.47.');

// A4 — the issue's three values, each with its TRUE reason, in both modes.
$a4 = [];
foreach ($modes as $fromFile) {
    $a4[] = Currency::parseAmount('184467440737095557.12', '', fromFile: $fromFile) === TOO_LARGE_INT;
    $power = $fromFile === true ? unreadable('') : POWER_OF_TEN;
    $a4[] = Currency::parseAmount('1e17', '', fromFile: $fromFile) === $power;
    $a4[] = Currency::parseAmount('-1e30', '', fromFile: $fromFile) === ($fromFile === true ? unreadableFor('-1e30', '') : POWER_OF_TEN);
    $a4[] = Currency::parseAmount('-1e30', '', allowNegative: true, fromFile: $fromFile) === ($fromFile === true ? unreadable('', true) : POWER_OF_TEN);
}
check('A4 the issue\'s three values, in both modes: "184467440737095557.12" is TOO LARGE (it used to be stored as 40.96), "1e17" and "-1e30" are refused — as powers of ten in a typed box, and as "could not be read" in a file — never "cannot be negative" and never a number',
    in_array(false, $a4, true) === false,
    var_export([Currency::parseAmount('184467440737095557.12', ''), Currency::parseAmount('1e17', ''), Currency::parseAmount('-1e30', '')], true));

// A5 — exponents, every spelling, both modes.
$exps = ['1e17', '1E17', '1e+17', '1e-2', '1.5e2', '.5e1', '1,000e3', '-1e30', '9e999'];
$bad  = [];
foreach ($modes as $modeName => $fromFile) {
    foreach ($exps as $t) {
        if (Currency::parseAmount($t, '', allowNegative: true, fromFile: $fromFile) !== ($fromFile === true ? unreadable('', true) : POWER_OF_TEN)) {
            $bad[] = $modeName . ' ' . $t;
        }
    }
}
check('A5 a power of ten is refused in every spelling (' . implode(', ', $exps) . '), in both modes — a typed box says it is a power of ten; a file says it could not be read', $bad === [], implode(', ', $bad));

// A6 — signs.
$signs = [];
foreach ($modes as $modeName => $fromFile) {
    foreach ([true, false] as $allowNegative) {
        $minus = $allowNegative === true ? MINUS_PLACE : ($fromFile === true ? FILE_MINUS_NOT_HERE : MINUS_NOT_HERE);
        $cases = ['+5' => $fromFile === true ? unreadable('', $allowNegative) : PLUS, '--5' => $minus, '5-' => $minus, '1-2' => $minus,
            '-' => $fromFile === true ? unreadableFor('-', '', $allowNegative) : NO_FIGURES];
        foreach ($cases as $t => $want) {
            $got = Currency::parseAmount($t, '', allowNegative: $allowNegative, fromFile: $fromFile);
            if ($got !== $want) {
                $signs[] = $modeName . ($allowNegative === true ? ' (negatives allowed) ' : ' (no negatives) ') . $t . ' → ' . var_export($got, true);
            }
        }
    }
}
check('A6 signs: "-3" is refused where negatives are not allowed ("cannot be negative") and is -300 where they are; "-0" and "-0.00" are 0 either way; each other shape gets ITS true reason, in both modes, with negatives allowed AND not — "+5" the plus sign; "--5", "5-", "1-2": where a minus may stand when one is allowed, and that it has no place at all when none is (round 3 told a box that cannot be negative to put the minus first, which only led to "cannot be negative"); "-" alone no figures; "- 5" (a space) figures only',
    Currency::parseAmount('-3', '') === NEGATIVE && Currency::parseAmount('-3', '', allowNegative: true) === -300
    && Currency::parseAmount('-0', '') === 0 && Currency::parseAmount('-0.00', '') === 0 && $signs === []
    && Currency::parseAmount('- 5', '', allowNegative: true) === FIGURES_ONLY, implode(' | ', $signs));

// A7 — the decimal point.
$points = [];
foreach ($modes as $modeName => $fromFile) {
    foreach (['1.2.3', '12.5.5', '12..5', '12.50.', '..5'] as $t) {
        if (Currency::parseAmount($t, '', fromFile: $fromFile) !== ONE_POINT) {
            $points[] = $modeName . ' ' . $t . ' → ' . var_export(Currency::parseAmount($t, '', fromFile: $fromFile), true);
        }
    }
    if (Currency::parseAmount('.', '', fromFile: $fromFile) !== ($fromFile === true ? FILE_NO_FIGURES : NO_FIGURES)) {
        $points[] = $modeName . ' .';
    }
}
check('A7 the point: "12." is 1200, ".5" is 50, ".05" is 5, "007.50" is 750; "12.345" and "0.001" are refused as too many decimals; "1.2.3", "12.5.5", "12..5", "12.50." and "..5" as more than one decimal point (round 2 called them "commas, spaces, currency signs or letters", which they hold none of), and "." alone as no figures — in both modes',
    Currency::parseAmount('12.', '') === 1200 && Currency::parseAmount('.5', '') === 50 && Currency::parseAmount('.05', '') === 5
    && Currency::parseAmount('007.50', '') === 750 && $points === []
    && Currency::parseAmount('12.345', '') === TOO_PRECISE && Currency::parseAmount('0.001', '') === TOO_PRECISE, implode(' | ', $points));

// A8 — thousands separators, every placement, both modes.
$commasOk  = ['1,234' => 123400, '1,234.50' => 123450, '12,345,678.9' => 1234567890, '999,999' => 99999900, '100,000' => 10000000];
$commasBad = ['12,50', '1,23', '1,2345', ',123', '1,,234', '1234,567', '1,234,56', '12,345,6789', '0,500', '00,500', '012,345', '0,000,001'];
$okWrong   = [];
$badWrong  = [];
foreach ($commasOk as $t => $want) {
    if (Currency::parseAmount($t, '', fromFile: true) !== $want) {
        $okWrong[] = 'statement ' . $t;
    }
    if (Currency::parseAmount($t, '') !== FIGURES_ONLY) {
        $okWrong[] = 'typed ' . $t;
    }
}
foreach ($commasBad as $t) {
    if (Currency::parseAmount($t, '', fromFile: true) !== BAD_COMMAS) {
        $badWrong[] = 'statement ' . $t . ' → ' . var_export(Currency::parseAmount($t, '', fromFile: true), true);
    }
    if (Currency::parseAmount($t, '') !== FIGURES_ONLY) {
        $badWrong[] = 'typed ' . $t;
    }
}
check('A8 commas: a TYPED box refuses every comma with the figures-only sentence (D-152); a STATEMENT reads them only between groups of three (' . implode(', ', array_keys($commasOk)) . ') and refuses them anywhere else, including a decimal comma "12,50" (the old code stored 1,250.00) and a first group starting with 0 ("0,500", "00,500", "012,345")',
    $okWrong === [] && $badWrong === [], json_encode([$okWrong, $badWrong]));
check('A8b other separators are not amounts, in either mode: "1 234", "1_000", "1\'000"; a file\'s "1.234,50" (a comma after the point) is told the comma rule',
    Currency::parseAmount('1 234', '') === FIGURES_ONLY && Currency::parseAmount('1.234,50', '') === FIGURES_ONLY
    && Currency::parseAmount('1_000', '') === FIGURES_ONLY && Currency::parseAmount("1'000", '') === FIGURES_ONLY
    && Currency::parseAmount('1 234', '', fromFile: true) === FILE_NOT_FIGURES && Currency::parseAmount('1.234,50', '', fromFile: true) === BAD_COMMAS
    && Currency::parseAmount('1_000', '', fromFile: true) === FILE_NOT_FIGURES && Currency::parseAmount("1'000", '', fromFile: true) === FILE_NOT_FIGURES);

// A9 — whitespace and the empty box.
check('A9 spaces, tabs and line breaks at either end are ignored ("  12.50 ", "\t7\n"); an empty or blank box answers "No amount was entered."',
    Currency::parseAmount('  12.50 ', '') === 1250 && Currency::parseAmount("\t7\n", '') === 700
    && Currency::parseAmount('', '') === Currency::REFUSED_EMPTY && Currency::parseAmount("  \t ", '') === Currency::REFUSED_EMPTY
    && Currency::REFUSED_EMPTY === 'No amount was entered.');

// A10 — the currency's own sign and code, and another's, both modes.
$ownForms = [['£12.50', 'GBP', 1250], ['GBP 12.50', 'GBP', 1250], ['GBP12.50', 'GBP', 1250], ['-£3.00', 'GBP', -300], ['£-3.00', 'GBP', -300],
    ['CHF 3', 'CHF', 300], ['CA$5', 'CAD', 500], ['€1,234.50', 'EUR', 123450]];
$wrong = [];
foreach ($ownForms as [$t, $code, $want]) {
    if (Currency::parseAmount($t, $code, allowNegative: true, fromFile: true) !== $want) {
        $wrong[] = 'statement ' . $t;
    }
    if (Currency::parseAmount($t, $code, allowNegative: true) !== FIGURES_ONLY) {
        $wrong[] = 'typed ' . $t . ' → ' . var_export(Currency::parseAmount($t, $code, allowNegative: true), true);
    }
}
check('A10 the record\'s OWN sign or code ("£12.50", "GBP 12.50", "GBP12.50", "-£3.00", "£-3.00", "CHF 3", "CA$5", "€1,234.50"): a TYPED box refuses every one with the figures-only sentence (D-152); a STATEMENT reads each exactly',
    $wrong === [], implode(' | ', $wrong));
$other = [['$12.50', 'GBP'], ['£12.50', 'EUR'], ['£12.50', ''], ['USD 5', 'GBP'], ['₱12.50', 'CUP'], ['£12.50', 'EGP']];
$wrong = [];
foreach ($other as [$t, $code]) {
    if (is_int(Currency::parseAmount($t, $code, fromFile: true)) === true || is_int(Currency::parseAmount($t, $code)) === true) {
        $wrong[] = $t . ' in ' . ($code === '' ? 'no currency' : $code);
    }
}
check('A10b a sign or code that is not read as the organisation\'s currency here is refused in BOTH modes ("$12.50" for pounds, "£12.50" for euros, "USD 5" for pounds, "₱" for the Cuban peso, a bare "£" for the Egyptian pound, whose sign is "E£"), and with no currency known no sign is read at all — the old bank import took "$" or "€" as pounds',
    $wrong === [], implode(', ', $wrong));
$told = [];
foreach ([['£12.50', '£'], ['GBP 12.50', 'GBP'], ['-£3', '£'], ['€1,234.50', '€'], ['$5', '$']] as [$t, $sign]) {
    if (Currency::parseAmount($t, '', allowNegative: true, fromFile: true) !== NO_CURRENCY_START . $sign . NO_CURRENCY_END) {
        $told[] = 'no currency ' . $t . ' → ' . var_export(Currency::parseAmount($t, '', allowNegative: true, fromFile: true), true);
    }
}
foreach ([['USD 5', 'GBP', otherCur('GBP')], ['AUD 12.50', 'NZD', otherCur('NZD')],
          ['A$12.50', 'NZD', otherCur('NZD')], ['₹12.50', 'EUR', otherCur('EUR')], ['€5', 'GBP', otherCur('GBP')],
          ['NZ$5', 'AUD', otherCur('AUD')], ['$12.50', 'GBP', otherCur('GBP')], ['₱12.50', 'CUP', otherCur('CUP')],
          ['£12.50', 'EGP', otherCur('EGP')], ['¥5', 'EUR', otherCur('EUR')]] as [$t, $code, $want]) {
    if (Currency::parseAmount($t, $code, fromFile: true) !== $want) {
        $told[] = $code . ' ' . $t . ' → ' . var_export(Currency::parseAmount($t, $code, fromFile: true), true);
    }
}
check('A10e a FILE\'s amount in a currency that is not the organisation\'s says the TRUE reason and what to do: with no currency chosen ("£12.50", "GBP 12.50", "-£3", "€1,234.50", "$5") it says so and points at Organisation settings; another currency\'s CODE ("USD 5" for GBP) or a SIGN that is not read as the organisation\'s currency here ("$" for GBP, "₱" for CUP, a bare "£" for EGP, "A$" for NZD) is told it is not read as that currency HERE — never "does not use", which is untrue of "₱" for the Cuban peso and "£" for the Egyptian pound (round 11) — and to CONVERT, not relabel; the other currency is not named (round 10, D-165)',
    $told === [], implode(' | ', $told));

// A10f — D-165 (superseding D-155): a sign is read as the organisation's OWN
// currency whenever that currency is written with it, shared or not.
$own = [];
foreach ([['$12.50', 'USD', 1250], ['$12.50', 'AUD', 1250], ['$12.50', 'NZD', 1250], ['$12.50', 'CAD', 1250], ['-$3.00', 'AUD', -300],
          ['$12.50', 'HKD', 1250], ['$12.50', 'SGD', 1250], ['$12.50', 'MXN', 1250], ['$12.50', 'CUP', 1250], ['¥12', 'CNY', 1200],
          ['USD 12.50', 'USD', 1250], ['AUD 12.50', 'AUD', 1250], ['A$12.50', 'AUD', 1250], ['NZ$12.50', 'NZD', 1250], ['CA$12.50', 'CAD', 1250],
          ['CN¥12.50', 'CNY', 1250], ['NZD 1,234.50', 'NZD', 123450], ['$1,234.50', 'USD', 123450]] as [$t, $code, $want]) {
    $got = Currency::parseAmount($t, $code, allowNegative: true, fromFile: true);
    if ($got !== $want) {
        $own[] = $code . ' ' . $t . ' → ' . var_export($got, true);
    }
}
check('A10f a FILE cell\'s "$" or "¥" is read as the ORGANISATION\'S OWN currency (D-165, superseding D-155): US dollars in USD, Australian in AUD, and likewise NZD, CAD, HKD, SGD, the Mexican and Cuban pesos, the yuan in CNY; the code and longer signs ("A$", "NZ$", "CA$", "CN¥") read too',
    $own === [], implode(' | ', $own));

// A10g — D-165 (superseding D-157): "£" is read for EVERY currency written
// with it, by ICU's data; "₱" for the Philippine peso.
$pound = [];
foreach ([['£1,234.56', 'GBP', 123456], ['-£3.00', 'GBP', -300], ['£-3.00', 'GBP', -300], ['£12.50', 'GIP', 1250], ['£12.50', 'FKP', 1250],
          ['£12.50', 'SHP', 1250], ['£12.50', 'SYP', 1250], ['£12.50', 'SSP', 1250], ['E£12.50', 'EGP', 1250], ['L£12.50', 'LBP', 1250],
          ['GIP 12.50', 'GIP', 1250], ['₱12.50', 'PHP', 1250], ['CUP 12.50', 'CUP', 1250]] as [$t, $code, $want]) {
    if (Currency::parseAmount($t, $code, allowNegative: true, fromFile: true) !== $want) {
        $pound[] = $code . ' ' . $t . ' → ' . var_export(Currency::parseAmount($t, $code, allowNegative: true, fromFile: true), true);
    }
}
check('A10g "£" in a FILE is read as the organisation\'s own pounds whenever they are written "£" (D-165, superseding D-157): sterling, Gibraltar, Falkland Islands, St Helena, Syrian, South Sudanese; the Egyptian and Lebanese pounds by their own "E£" and "L£"; "₱" for the Philippine peso',
    $pound === [], implode(' | ', $pound));

// A10i — MORE THAN ONE currency marker in front, the first the organisation's
// own, gets ONE fixed sentence that names, counts and judges no marker (round
// 14). Rounds 10 to 13 built it from the markers, and each construction was
// found untrue for some cell. Two markers, ours or not, and three or four.
$twice = [];
foreach ([['GBP £12.50', 'GBP'], ['£GBP 12.50', 'GBP'], ['££12.50', 'GBP'], ['PHP ₱12.50', 'PHP'], ['AUD A$12.50', 'AUD'], ['EUR €5', 'EUR'],
          ['$USD 5', 'USD'], ['-£ £3', 'GBP'], ['GBP£12', 'GBP'], ['USD$5', 'USD'], ['EUR€5', 'EUR'], ['AUDA$5', 'AUD'], ['GBP   £12', 'GBP'],
          ['GBP $12', 'GBP'], ['GBP$12', 'GBP'], ['USD £12', 'USD'], ['£$12', 'GBP'], ['GBP US$12', 'GBP'], ['£USD 12', 'GBP'],
          ['CAD US$12', 'CAD'], ['CNY JP¥1,200', 'CNY'], ['GIP GB£5', 'GIP'], ['CADUS$12', 'CAD'], ['USD US$5', 'USD'], ['CAD CA$12', 'CAD'],
          ['CUP ₱12', 'CUP'],
          // Three and four markers (round 13's cells), ours or not.
          ['GBP £$12', 'GBP'], ['GBP GBP$12', 'GBP'], ['££$12', 'GBP'], ['£GBP €12', 'GBP'], ['GBP£US$12', 'GBP'], ['£££$12', 'GBP'],
          ['USD USD€5', 'USD'], ['$$€5', 'USD'], ['AED AED$12', 'AED'], ['GBP USD$12', 'GBP'], ['GBP ALL$5', 'GBP'], ['AED $AED 12', 'AED'],
          ['£££12', 'GBP'], ['GBP £ £12', 'GBP'], ['£-£3', 'GBP'],
          // More than one marker AND another fault: still the one sentence.
          ['££12.345', 'GBP'], ['£££1.2.3', 'GBP'], ['GBP £0,500', 'GBP']] as [$t, $code]) {
    foreach ([true, false] as $allowNegative) {
        $got = Currency::parseAmount($t, $code, allowNegative: $allowNegative, fromFile: true);
        // Round 17: with no negatives allowed, a cell carrying a minus is
        // refused as negative first, exactly as a one-marker cell is.
        $want = ($allowNegative === false && str_contains($t, '-') === true) ? NEGATIVE : many($code, $allowNegative);
        if ($got !== $want) {
            $twice[] = $code . ' ' . $t . ' (negatives ' . ($allowNegative === true ? 'allowed' : 'not allowed') . ') → ' . var_export($got, true);
        }
    }
}
// Round 17: with NO negatives allowed (the asset import), a cell with more than
// one marker AND a minus anywhere is refused as negative — never told to keep
// "nothing in front but, at most, the code", which read literally ("GBP 3")
// would store a positive cost where the file said minus 3. The one-marker
// cells are the comparison: they say the same.
foreach (['-££3', 'GBP-£12', '£-£3', '££-3', '-GBP£12', '££3-', '-£$12', 'GBP £-3'] as $t) {
    if (Currency::parseAmount($t, 'GBP', fromFile: true) !== NEGATIVE) {
        $twice[] = 'GBP ' . $t . ' (no negatives) → ' . var_export(Currency::parseAmount($t, 'GBP', fromFile: true), true);
    }
}
foreach (['-£3', 'GBP -3'] as $t) {
    if (Currency::parseAmount($t, 'GBP', fromFile: true) !== NEGATIVE) {
        $twice[] = 'GBP ' . $t . ' (no negatives, one marker) → ' . var_export(Currency::parseAmount($t, 'GBP', fromFile: true), true);
    }
}
// Round 16: the bank import's negative shapes keep their minus in the
// instruction — and following it reads the NEGATIVE amount, not a credit.
foreach (['-££3', 'GBP-£12', '-GBP£12', '£-£3', '-£$12.00'] as $t) {
    if (Currency::parseAmount($t, 'GBP', allowNegative: true, fromFile: true) !== many('GBP', true)) {
        $twice[] = 'GBP ' . $t . ' (negatives allowed) → ' . var_export(Currency::parseAmount($t, 'GBP', allowNegative: true, fromFile: true), true);
    }
}
if (str_contains(Currency::manyMarkersWords('GBP', true), 'its minus sign, if it has one') === false
    || str_contains(Currency::manyMarkersWords('GBP', false), 'minus') === true) {
    $twice[] = 'the PORTAL\'s sentence names the minus where negatives are not allowed, or not where they are';
}
foreach (['GBP -3', '-GBP 3', 'GBP-3', '-GBP3', '-3'] as $t) {
    if (Currency::parseAmount($t, 'GBP', allowNegative: true, fromFile: true) !== -300) {
        $twice[] = 'following the negative instruction, "' . $t . '" does not read as -3.00';
    }
}
check('A10i MORE THAN ONE currency marker in front, the first the organisation\'s own — two of ours ("GBP £12.50", "££12.50", "CAD CA$12"), ours then not ("GBP $12", "CAD US$12", "CUP ₱12"), three or four ("GBP £$12", "£££$12", "£££12", "GBP USD$12"), with or without spaces, and with another fault too ("££12.345", "-££3") — gets ONE fixed sentence naming and counting no marker (round 14); where negatives are allowed it keeps the minus ("-££3", "GBP-£12", "-GBP£12", "£-£3", "-£$12.00"), and "GBP -3", "-GBP 3", "GBP-3", "-GBP3", "-3" read as -3.00 (round 16); where they are NOT, a cell with a minus anywhere ("-££3", "GBP-£12", "£-£3", "££-3", "-GBP£12", "££3-") is refused as negative, as "-£3" and "GBP -3" are (round 17)',
    $twice === [], implode(' | ', array_slice($twice, 0, 4)));

// A10k — D-177: a sign made only of LETTERS is NOT read in a file. Every such
// cell is refused (never stored): the organisation's own letters sign asks for
// the code; any other letters are simply "not an amount", naming no sign.
// Round 7's check measured "Rs.50" stored as 0.50 before this.
$letters = [];
foreach ([['NPR', 'Rs'], ['LKR', 'Rs'], ['MUR', 'Rs'], ['PKR', 'Rs'], ['DKK', 'kr'], ['BOB', 'Bs'], ['IDR', 'Rp'], ['HUF', 'Ft'],
          ['MYR', 'RM'], ['ZAR', 'R'], ['CHF', 'Fr.'], ['SEK', 'kr']] as [$code, $sign]) {
    // Each spelling a cell may take, and the bank import's space-stripped form of each.
    foreach ([$sign . '50', $sign . ' 50', $sign . '.50', $sign . '. 50', $sign . '1,234.50', '-' . $sign . '5'] as $cell) {
        foreach ([$cell, str_replace(' ', '', $cell)] as $t) {
            $got = Currency::parseAmount($t, $code, allowNegative: true, fromFile: true);
            if (is_int($got) === true) {
                $letters[] = $code . ' "' . $t . '" STORED as ' . $got;
            } elseif ($got !== unreadable($code, true) && preg_match('/^(The amount cannot be negative\.|A minus sign)/', $got) !== 1) {
                $letters[] = $code . ' "' . $t . '" → ' . var_export($got, true);
            }
        }
    }
    // The plain cases must get the letters sentence exactly (not merely a refusal).
    foreach ([$sign . '50', $sign . ' 50'] as $t) {
        if (Currency::parseAmount($t, $code, fromFile: true) !== unreadable($code)) {
            $letters[] = $code . ' "' . $t . '" not told to write the code: ' . var_export(Currency::parseAmount($t, $code, fromFile: true), true);
        }
    }
}
check('A10k a sign made only of letters is NOT read in a file (D-177): "Rs50", "Rs 50", "Rs.50", "Rs. 50", "-Rs5" and the bank import\'s space-stripped forms are REFUSED — never stored — for NPR, LKR, MUR, PKR, DKK, BOB, IDR, HUF, MYR, ZAR, CHF and SEK, and each is told it could not be read, with what IS read (the three-letter code)',
    $letters === [], implode(' | ', array_slice($letters, 0, 4)));

$plain = [];
foreach (['R Smith', 'Fr. John', 'P.O. 1234', 'K.O.', 'L.A. 5', 'Q1 2026', 'ALL PAID', 'TRY again', 'Rent 12', 'R12', 'ZARR12'] as $t) {
    foreach (['GBP', ''] as $code) {
        $got = Currency::parseAmount($t, $code, fromFile: true);
        if ($got !== unreadable($code)) {
            $plain[] = ($code === '' ? 'no currency' : $code) . ' "' . $t . '" → ' . var_export($got, true);
        }
    }
}
check('A10l ordinary text and letters-only signs not the organisation\'s own ("R Smith", "Fr. John", "P.O. 1234", "K.O.", "L.A. 5", "Q1 2026", "ALL PAID", "TRY again", "Rent 12", "R12", "ZARR12"), in GBP and with no currency, get "could not be read" and what IS read — no sign or currency named',
    $plain === [], implode(' | ', array_slice($plain, 0, 4)));

$codes = [];
foreach ([['RUB 12', 'ZAR', otherCur('ZAR')], ['RUB12', 'ZAR', otherCur('ZAR')], ['PLN 5', 'BWP', otherCur('BWP')],
          ['USD$5', 'GBP', otherCur('GBP')], ['TOP 5', 'GBP', otherCur('GBP')], ['R$12', 'ZAR', otherCur('ZAR')],
          ['L£5', 'HNL', otherCur('HNL')], ['ZAR 12', 'ZAR', 1200], ['ZAR12', 'ZAR', 1200], ['ZAR -12', 'ZAR', -1200],
          ['CHF 12', 'CHF', 1200], ['NPR 1,234.50', 'NPR', 123450]] as [$t, $code, $want]) {
    $got = Currency::parseAmount($t, $code, allowNegative: true, fromFile: true);
    if ($got !== $want) {
        $codes[] = $code . ' "' . $t . '" → ' . var_export($got, true);
    }
}
$readLetters = [];
$lettersKnown = 0;
foreach ((new ReflectionClassConstant(Currency::class, 'SIGNS_OF'))->getValue() as $code => $list) {
    foreach ($list as $sign) {
        if (preg_match('/^\p{L}+\.?$/u', $sign) === 1) {
            $lettersKnown++;
            if (in_array($sign, Currency::signsOf($code), true) === true) {
                $readLetters[] = $code . ' ' . $sign;
            }
            if (is_int(Currency::parseAmount($sign . '12', $code, fromFile: true)) === true || is_int(Currency::parseAmount($sign . ' 12', $code, fromFile: true)) === true) {
                $readLetters[] = $code . ' "' . $sign . '12" stored';
            }
        }
    }
}
check('A10n NO letters-only sign in ICU\'s data (' . $lettersKnown . ' of them, every currency) is among the signs a file is read with, and "<sign>12" / "<sign> 12" is stored for none — the pin that goes red if letters-only reading is ever switched back on (D-177)',
    $readLetters === [] && $lettersKnown >= 20, implode(', ', array_slice($readLetters, 0, 6)));
check('A10m another currency\'s code or sign — "RUB 12", "RUB12", "PLN 5", "USD$5", "TOP 5", "R$12", "L£5" — is NOT named (round 9: every attempt to name it was untrue somewhere); each is told it is not read as the organisation\'s currency here and to convert (rounds 10-11); "ZAR 12", "ZAR12", "CHF 12", "NPR 1,234.50" read',
    $codes === [], implode(' | ', $codes));

// A10o — round 8's check: cells that were told untrue reasons, each in its own
// organisation. Each now gets "could not be read" (or, for two markers, says so);
// any run of spaces after the organisation's code or sign reads.
$r8 = [];
foreach ([['P.O. 1234', 'BWP'], ['K-12', 'MMK'], ['K.O. 12', 'MMK'], ['R.S.V.P.', 'ZAR'], ['R-rated', 'ZAR'], ['L.A. 5', 'HNL'],
          ['Q.E.D.', 'GTQ'], ['TOP-UP 20', 'GBP'], ['ALL-IN', 'GBP'], ['TOP-UP 20', ''], ['ALL PAID', ''], ['GBP' . "\t" . '12', 'GBP']] as [$t, $code]) {
    $got = Currency::parseAmount($t, $code, fromFile: true);
    if ($got !== unreadableFor($t, $code)) {
        $r8[] = ($code === '' ? 'no currency' : $code) . ' "' . $t . '" → ' . var_export($got, true);
    }
}
foreach ([['GBP  12.50', 'GBP', 1250], ['GBP     12.50', 'GBP', 1250], ['£   12.50', 'GBP', 1250], ['GBP  -12', 'GBP', -1200],
          ['AUD  1,234.50', 'AUD', 123450], ['$  5', 'USD', 500]] as [$t, $code, $want]) {
    if (Currency::parseAmount($t, $code, allowNegative: true, fromFile: true) !== $want) {
        $r8[] = $code . ' "' . $t . '" → ' . var_export(Currency::parseAmount($t, $code, allowNegative: true, fromFile: true), true);
    }
}
check('A10o round 8\'s untrue refusals, in their own organisations — BWP "P.O. 1234", MMK "K-12" and "K.O. 12", ZAR "R.S.V.P." and "R-rated", HNL "L.A. 5", GTQ "Q.E.D.", GBP "TOP-UP 20" and "ALL-IN" — each get "could not be read", naming no sign or currency; any run of SPACES after the organisation\'s code or sign reads ("GBP  12.50"); a TAB after the code ("GBP\\t12") is refused as could not be read, on purpose (round 10)',
    $r8 === [], implode(' | ', $r8));

// A10p — round 10: a cell beginning with a sign or code the organisation does
// NOT use is told so, and to convert rather than relabel. Round 9 told "$12" in
// a sterling organisation "could not be read … the code GBP in front if you
// like", which invites writing "GBP 12" over twelve dollars. Words and letters
// stay on "could not be read", and "US$12" in a US-dollar organisation too:
// "US$" is not in ICU's data and "$" IS the organisation's, so it is not
// claimed to be another currency.
$r10 = [];
foreach ([['$12', 'GBP'], ['€12.50', 'GBP'], ['USD 5', 'GBP'], ['-$3', 'GBP'], ['US$12', 'GBP'], ['CA$12', 'USD'], ['RUB12', 'ZAR']] as [$t, $code]) {
    foreach ([true, false] as $allowNegative) {
        $got = Currency::parseAmount($t, $code, allowNegative: $allowNegative, fromFile: true);
        if ($got !== otherCur($code)) {
            $r10[] = $code . ' "' . $t . '" → ' . var_export($got, true);
        }
    }
}
foreach ([['P.O. 1234', 'BWP'], ['R.S.V.P.', 'ZAR'], ['TOP-UP 20', 'GBP'], ['ALL-IN', 'GBP'], ['US$12', 'USD'], ['Rs50', 'NPR'], ['--$3', 'GBP']] as [$t, $code]) {
    $got = Currency::parseAmount($t, $code, allowNegative: true, fromFile: true);
    if ($got !== unreadable($code, true)) {
        $r10[] = $code . ' "' . $t . '" → ' . var_export($got, true);
    }
}
// No file sentence may tell a cell that may hold another currency to put the
// organisation's code in front "if you like".
foreach ([Currency::fileAdvice('GBP'), otherCur('GBP'), Currency::parseAmount('$12', 'GBP', fromFile: true)] as $s) {
    if (str_contains((string) $s, 'in front if you like') === true) {
        $r10[] = 'relabelling advice: ' . $s;
    }
}
check('A10p round 10: "$12", "€12.50", "USD 5", "-$3" and "US$12" in a GBP organisation (and "CA$12" in USD, "RUB12" in ZAR) are told that sign or code is not read as the organisation\'s currency here and to convert the figure, never to put GBP in front; "P.O. 1234", "R.S.V.P.", "TOP-UP 20", "ALL-IN", "Rs50" and "US$12" in USD still get "could not be read"; and no file sentence says "in front if you like"',
    $r10 === [], implode(' | ', $r10));

// A10q — the second-minus guard: a minus is taken once only. "-GBP-12" and
// "-£-3" carry TWO minus signs and must be refused, not read as one negative.
// Without `$negative === false &&` the second minus is quietly dropped and the
// cell stored as -12.00 (round 10's check asked for this to be pinned).
$minus2 = [];
foreach ([['-GBP-12', 'GBP'], ['-£-3', 'GBP'], ['-$-3', 'USD'], ['-GBP -12', 'GBP']] as [$t, $code]) {
    $got = Currency::parseAmount($t, $code, allowNegative: true, fromFile: true);
    if ($got !== MINUS_PLACE) {
        $minus2[] = $code . ' "' . $t . '" → ' . var_export($got, true);
    }
}
foreach ([['GBP-12', 'GBP', -1200], ['£-3', 'GBP', -300], ['-GBP 12', 'GBP', -1200], ['-£3', 'GBP', -300]] as [$t, $code, $want]) {
    if (Currency::parseAmount($t, $code, allowNegative: true, fromFile: true) !== $want) {
        $minus2[] = $code . ' "' . $t . '" → ' . var_export(Currency::parseAmount($t, $code, allowNegative: true, fromFile: true), true);
    }
}
check('A10q a minus is taken ONCE: "-GBP-12", "-£-3", "-$-3" (USD) and "-GBP -12" are refused (a minus can only come first), while "GBP-12", "£-3", "-GBP 12" and "-£3" each read as one negative',
    $minus2 === [], implode(' | ', $minus2));

// A10r — round 11: the sentence is a claim about THIS PORTAL ("not read as CUP
// here"), true by construction. Round 10 said "that CUP does not use", untrue
// for "₱" (Cuban peso), "£" (Egyptian pound), "C$" (Canadian dollar), "BR$"
// (real), signs those currencies really are written with that ICU's English
// data does not list for them. And what is NOT taken as a marker is pinned:
// four capitals before a sign ("ABCD$12" — `{1,3}` widened to `+` would take
// it), a TAB after a code or sign ("GBP\t12", "£\t12", "USD\t12" — only spaces
// are read), a bare code, a code followed by a word, and the organisation's
// code followed by ANOTHER code ("GBP USD 12"), all "could not be read".
$r11 = [];
foreach ([['₱12', 'CUP'], ['£12', 'EGP'], ['£12.50', 'EGP'], ['C$12', 'CAD'], ['BR$12', 'BRL'], ['USD 12', 'GBP'], ['USD$5', 'GBP'], ['ABC$12', 'GBP']] as [$t, $code]) {
    $got = Currency::parseAmount($t, $code, allowNegative: true, fromFile: true);
    if ($got !== otherCur($code)) {
        $r11[] = $code . ' "' . $t . '" → ' . var_export($got, true);
    }
}
foreach ([['ABCD$12', 'GBP'], ['GBP' . "\t" . '12', 'GBP'], ['£' . "\t" . '12', 'GBP'], ['USD' . "\t" . '12', 'GBP'], ['USD', 'GBP'],
          ['USD PAID', 'GBP'], ['GBP USD 12', 'GBP']] as [$t, $code]) {
    $got = Currency::parseAmount($t, $code, allowNegative: true, fromFile: true);
    if ($got !== unreadable($code, true)) {
        $r11[] = $code . ' ' . json_encode($t, JSON_UNESCAPED_UNICODE) . ' → ' . var_export($got, true);
    }
}
// No file sentence for any of these makes a claim about the WORLD.
foreach (['$12', '₱12', '£12', 'C$12', 'CUP ₱12', 'GBP $12'] as $t) {
    foreach (['GBP', 'CUP', 'EGP', 'CAD'] as $code) {
        $got = (string) Currency::parseAmount($t, $code, fromFile: true);
        if (preg_match('/does not use|is not one \S+ uses/', $got) === 1) {
            $r11[] = $code . ' "' . $t . '" claims about the world: ' . $got;
        }
    }
}
// A10s — round 12: two shapes the reading rests on, pinned so a narrowing
// goes red: a point straight after the code ("GBP .50" reads as 50; the
// `[0-9.]` in amountOrSignFollows()), and ONE capital joined to a sign ("X$12"
// is a marker, not read as GBP here; the `{1,3}` in lettersThenSymbolAtStart()).
$r12 = [];
foreach ([['GBP .50', 'GBP', 50], ['GBP -.50', 'GBP', -50], ['X$12', 'GBP', otherCur('GBP')], ['US$12', 'CAD', unreadable('CAD', true)],
          ['US$12', 'AUD', unreadable('AUD', true)], ['₫12', 'GBP', unreadable('GBP', true)], ['₫12', '', unreadable('', true)]] as [$t, $code, $want]) {
    $got = Currency::parseAmount($t, $code, allowNegative: true, fromFile: true);
    if ($got !== $want) {
        $r12[] = ($code === '' ? 'no currency' : $code) . ' "' . $t . '" → ' . var_export($got, true);
    }
}
check('A10s round 12: "GBP .50" reads as 50 and "GBP -.50" as -50; "X$12" (one capital joined to a sign) is a marker not read as GBP here; "US$12" in CAD and AUD (their "$" is their own) and "₫12" (a sign the converter does not recognise), with or without a currency, could not be read',
    $r12 === [], implode(' | ', $r12));

// A10u — round 15: the cells that must NOT get the more-than-one-marker
// sentence, because they do not have more than one marker IN FRONT — a marker
// after the figure ("£12$", "GBP 12£", "£12 €", "£12.50 GBP"), a code then a
// plain word ("GBP PAID 12"), or a single marker ("£12", "GBP 12"). Nothing
// pinned this side: a planted "any sign anywhere after our marker" or "a
// marker after the leading figures" left every check green. And the
// sentence's instruction must be FOLLOWABLE for every choosable currency:
// "nothing in front but, at most, the code" — round 14's "at most one ZAR
// sign or code" pointed at "R 12", which is refused (D-177).
$r15 = [];
foreach ([['£12$', 'GBP'], ['GBP 12£', 'GBP'], ['£12 €', 'GBP'], ['£12.50 GBP', 'GBP'], ['GBP 12 USD', 'GBP'], ['$12€', 'USD'],
          ['GBP PAID 12', 'GBP'], ['£12 $5', 'GBP'], ['GBP 1,234$', 'GBP']] as [$t, $code]) {
    foreach ([true, false] as $neg) {
        $got = Currency::parseAmount($t, $code, allowNegative: $neg, fromFile: true);
        if ($got === many($code) || is_int($got) === true) {
            $r15[] = $code . ' "' . $t . '" → ' . var_export($got, true);
        }
    }
}
foreach ([['£12', 'GBP', 1200], ['GBP 12', 'GBP', 1200], ['GBP -3', 'GBP', -300]] as [$t, $code, $want]) {
    if (Currency::parseAmount($t, $code, allowNegative: true, fromFile: true) !== $want) {
        $r15[] = $code . ' "' . $t . '" → ' . var_export(Currency::parseAmount($t, $code, allowNegative: true, fromFile: true), true);
    }
}
$followable = 0;
foreach (array_keys(Currency::choices()) as $code) {
    $followable++;
    if (Currency::parseAmount($code . ' 12', $code, fromFile: true) !== 1200 || Currency::parseAmount('12', $code, fromFile: true) !== 1200
        || Currency::parseAmount($code . ' -12', $code, allowNegative: true, fromFile: true) !== -1200
        || Currency::parseAmount('-12', $code, allowNegative: true, fromFile: true) !== -1200) {
        $r15[] = $code . ': "' . $code . ' 12", "12", "' . $code . ' -12" or "-12" not read, so the sentence\'s instruction cannot be followed';
    }
    // The instruction (after "read here:") may name a MINUS sign and nothing
    // else that is a sign: not "a ZAR sign", "the sign or code ZAR", "sign".
    foreach ([false, true] as $neg) {
        $words = Currency::manyMarkersWords($code, $neg);
        $instruction = (string) substr($words, (int) strpos($words, 'read here:'));
        if (preg_match('/\bsign\b/', str_replace('minus sign', '', $instruction)) === 1) {
            $r15[] = $code . ': the instruction asks for a sign: ' . $instruction;
        }
    }
}
check('A10u round 15: a marker AFTER the figure ("£12$", "GBP 12£", "£12 €", "£12.50 GBP", "GBP 12 USD", "$12€", "£12 $5", "GBP 1,234$"), a code then a plain word ("GBP PAID 12") never get the more-than-one-marker sentence (nor are they stored); one marker ("£12", "GBP 12", "GBP -3") reads; and for all ' . $followable . ' choosable currencies the sentence\'s instruction can be followed ("CODE 12" and "12" both read) and asks for no sign',
    $r15 === [] && $followable >= 100, implode(' | ', array_slice($r15, 0, 4)));

// A10v — round 17: where negatives ARE allowed (the bank import), the
// could-not-be-read advice says to keep the minus. Without it "-R3", "-kr3",
// "-3GBP" or "-£3.00DR" were told "Write it in figures… (ZAR 1,234.56)", and a
// reader who dropped the minus imported a payment out as a credit.
$r17 = [];
foreach ([['-R3', 'ZAR'], ['-kr3', 'SEK'], ['-3GBP', 'GBP'], ['-£3.00DR', 'GBP'], ['-abc', ''], ['-P.O. 1234', 'BWP']] as [$t, $code]) {
    $got = Currency::parseAmount($t, $code, allowNegative: true, fromFile: true);
    if ($got !== unreadable($code, true) || str_contains((string) $got, 'Keep the minus sign in front of money paid out') === false) {
        $r17[] = ($code === '' ? 'no currency' : $code) . ' "' . $t . '" → ' . var_export($got, true);
    }
}
// And where they are NOT, the advice says nothing about a minus.
if (str_contains(Currency::fileAdvice('GBP'), 'minus') === true || str_contains(Currency::fileAdvice('GBP', true), 'minus') === false) {
    $r17[] = 'fileAdvice() names the minus in the wrong mode';
}
// Following the advice keeps the money right: "-1,234.56" and "ZAR -1,234.56" read negative.
foreach (['-1,234.56', 'ZAR -1,234.56', '-ZAR 1,234.56'] as $t) {
    if (Currency::parseAmount($t, 'ZAR', allowNegative: true, fromFile: true) !== -123456) {
        $r17[] = 'following the advice, "' . $t . '" does not read as -1,234.56';
    }
}
// Round 18: where negatives are NOT allowed (the asset import), the same cells
// — and any other that could not be read with a minus anywhere — keep their
// advice and are also told "Amounts here cannot be negative.", so nothing
// invites dropping the minus to store a positive cost. A cell with no minus is
// not told it; no cell's reading changes.
foreach ([['-R3', 'ZAR'], ['-3GBP', 'GBP'], ['-£3.00DR', 'GBP'], ['-abc', 'GBP'], ['-P.O. 1234', 'BWP'], ['R-rated', 'ZAR'], ['-abc', '']] as [$t, $code]) {
    $got  = Currency::parseAmount($t, $code, fromFile: true);
    $want = unreadable($code) . ' ' . NOT_NEGATIVE_HERE;
    if ($got !== $want || str_ends_with((string) $got, 'Amounts here cannot be negative.') === false) {
        $r17[] = ($code === '' ? 'no currency' : $code) . ' "' . $t . '" (no negatives) → ' . var_export($got, true);
    }
}
foreach ([['abc', 'GBP'], ['R3', 'ZAR'], ['P.O. 1234', 'BWP']] as [$t, $code]) {
    if (Currency::parseAmount($t, $code, fromFile: true) !== unreadable($code)) {
        $r17[] = $code . ' "' . $t . '" (no negatives, no minus) → ' . var_export(Currency::parseAmount($t, $code, fromFile: true), true);
    }
}
foreach (['-R3', '-abc'] as $t) {
    if (str_contains((string) Currency::parseAmount($t, 'ZAR', allowNegative: true, fromFile: true), NOT_NEGATIVE_HERE) === true) {
        $r17[] = '"' . $t . '" told amounts cannot be negative where they can';
    }
}
check('A10v rounds 17-18: where negatives are allowed, a bank-import cell that could not be read ("-R3", "-kr3", "-3GBP", "-£3.00DR", "-abc", "-P.O. 1234") is told to keep the minus sign on money paid out, and following it ("-1,234.56", "ZAR -1,234.56") reads negative; where they are not, the advice names no minus, and a cell with a minus anywhere ("-R3", "-3GBP", "-£3.00DR", "-abc", "-P.O. 1234", "R-rated") is also told "Amounts here cannot be negative." — a cell with no minus is not',
    $r17 === [], implode(' | ', $r17));

// A10t — the "own refusal" with ONE marker: a cell with one of the
// organisation's markers and another fault is told that fault (round 14 keeps
// these; with more than one marker the cell gets A10i's one sentence).
$r13 = [];
foreach ([['£12.345', 'GBP', true, Currency::REFUSED_TOO_PRECISE], ['£1.2.3', 'GBP', true, ONE_POINT],
          ['-£3', 'GBP', false, Currency::REFUSED_NEGATIVE], ['GBP 0,500', 'GBP', true, BAD_COMMAS]] as [$t, $code, $neg, $want]) {
    $got = Currency::parseAmount($t, $code, allowNegative: $neg, fromFile: true);
    if ($got !== $want) {
        $r13[] = $code . ' "' . $t . '" → ' . var_export($got, true);
    }
}
// Letters-only signs are never markers, first or second (D-177): "GBP R12" has
// ONE marker followed by a word, so it is not the one sentence either.
foreach ([['GBP R12', 'ZAR'], ['GBP R12', 'GBP'], ['R12', 'ZAR'], ['Rs50', 'NPR'], ['kr12', 'SEK'], ['ZAR R12', 'ZAR']] as [$t, $code]) {
    $got = Currency::parseAmount($t, $code, allowNegative: true, fromFile: true);
    if ($got !== unreadable($code, true)) {
        $r13[] = $code . ' "' . $t . '" → ' . var_export($got, true);
    }
}
check('A10t with ONE of the organisation\'s markers and another fault, the cell gets that fault\'s own refusal ("£12.345", "£1.2.3", "GBP 0,500", "-£3" where no negative is allowed); letters-only signs ("GBP R12", "R12", "Rs50", "kr12", "ZAR R12") are never markers and could not be read',
    $r13 === [], implode(' | ', $r13));

check('A10r round 11: "₱12" in CUP, "£12" in EGP, "C$12" in CAD, "BR$12" in BRL, "USD 12", "USD$5" and "ABC$12" in GBP are told the sign or code is not read as that currency HERE (never "does not use"), and "CUP ₱12" gets the one more-than-one-marker sentence; "ABCD$12", a tab after a code or sign ("GBP\\t12", "£\\t12", "USD\\t12"), a bare "USD", "USD PAID" and "GBP USD 12" could not be read',
    $r11 === [], implode(' | ', $r11));

// A10j — every SYMBOLS sign (the one the portal WRITES) is also one its own
// currency is READ with, so a file the portal could have written reads back.
$symbols  = (new ReflectionClassConstant(Currency::class, 'SYMBOLS'))->getValue();
$unread   = [];
foreach ($symbols as $code => $sign) {
    if (in_array($sign, Currency::signsOf($code), true) === false || Currency::parseAmount($sign . '1,234.56', $code, fromFile: true) !== 123456) {
        $unread[] = $code . ' ' . $sign;
    }
}
check('A10j every sign the portal WRITES an amount with (' . count($symbols) . ' of them) is one its currency is read with, and "<sign>1,234.56" reads back as 123456',
    $unread === [] && count($symbols) >= 15, implode(', ', $unread));

// A10h — no FILE refusal ever ADVISES writing a currency sign, by any spelling:
// the instruction part of every sentence (after the reason) is checked against
// EVERY sign the converter reads, not for the word "sign" (round 5's check).
$allSigns    = [];
$letterSigns = [];
foreach ((new ReflectionClassConstant(Currency::class, 'SIGNS_OF'))->getValue() as $list) {
    foreach ($list as $sign) {
        if (preg_match('/[^\p{L}.]/u', $sign) === 1) {
            $allSigns[$sign] = true;
        } else {
            $letterSigns[$sign] = true;
        }
    }
}
$allSigns    = array_keys($allSigns);
$letterSigns = array_keys($letterSigns);
$advisedSign = [];
$refusals    = 0;
foreach (['', 'GBP', 'USD', 'AUD', 'NZD', 'CAD', 'CNY', 'GIP', 'FKP', 'EGP', 'PHP', 'CUP', 'EUR', 'CHF', 'ZAR'] as $code) {
    foreach (['abc', '+5', '--5', '5-', '.', '-', '1.2.3', '12.345', '0,500', '1e17', '$5', '¥5', '£5', '€5', '₱5', 'A$5', 'USD 5', 'GIP 5',
              '1 234', '12_000', '99999999999', 'GBP £5', '££5', 'E£5', 'R5'] as $t) {
        foreach ([true, false] as $allowNegative) {
            $got = Currency::parseAmount($t, $code, allowNegative: $allowNegative, fromFile: true);
            if (is_string($got) === false) {
                continue;
            }
            $refusals++;
            // The instruction part: from the first of these openings to the end.
            $at = PHP_INT_MAX;
            foreach (['Write it in figures', 'Write the three-letter code', 'Put only one in front', 'Import a file kept in', 'Choose the organisation', 'for example', 'Leave out', 'Only ', 'The code '] as $opening) {
                $pos = strpos($got, $opening);
                if ($pos !== false && $pos < $at) {
                    $at = $pos;
                }
            }
            $instruction = $at === PHP_INT_MAX ? '' : substr($got, $at);
            foreach ($allSigns as $sign) {
                // A sign with a symbol in it is looked for anywhere in the instruction.
                if ($instruction !== '' && str_contains($instruction, $sign) === true) {
                    $advisedSign[] = ($code === '' ? 'no currency' : $code) . ' ' . $t . ' → ' . $got;
                    break;
                }
            }
            foreach ($letterSigns as $sign) {
                // A letters-only sign ("R", "kr", "Fr.") as a sign: not inside a word, and followed by a figure.
                if ($instruction !== '' && preg_match('/(?<!\p{L})' . preg_quote($sign, '/') . ' ?[0-9]/u', $instruction) === 1) {
                    $advisedSign[] = ($code === '' ? 'no currency' : $code) . ' ' . $t . ' → ' . $got;
                    break;
                }
            }
            if (preg_match('/\b(own|currency.?s|its|the|a) (currency )?sign\b(?<!plus sign)(?<!minus sign)/i', $instruction) === 1) {
                $advisedSign[] = ($code === '' ? 'no currency' : $code) . ' ' . $t . ' → ' . $got;
            }
        }
    }
}
check('A10h no FILE refusal advises writing a currency sign by ANY spelling — the instruction part of each of ' . $refusals . ' refusals (15 currencies, 25 cells, negatives allowed or not) is checked against all ' . (count($allSigns) + count($letterSigns)) . ' signs in ICU\'s data — the ' . count($allSigns) . ' with a symbol, which the converter reads, looked for anywhere; the ' . count($letterSigns) . ' of letters only, which it does not read (D-177), looked for before a figure), as well as for the words',
    $advisedSign === [] && $refusals > 500 && count($allSigns) + count($letterSigns) >= 58, implode(' | ', array_slice($advisedSign, 0, 3)));
check('A10c a currency not counted in hundredths is refused outright, with the reason the organisation page gives (yen, Kuwaiti dinar), in both modes',
    Currency::parseAmount('5', 'JPY') === Currency::whyNotChoosable('JPY') && Currency::parseAmount('5', 'KWD') === Currency::whyNotChoosable('KWD')
    && Currency::parseAmount('5', 'JPY', fromFile: true) === Currency::whyNotChoosable('JPY'));
check('A10d the two "not figures" sentences: a typed box asks for figures only and names commas, spaces, signs, letters and other characters (D-152); a file says what the cell should look like',
    Currency::parseAmount('abc', 'GBP') === 'Type the amount in figures only, for example 1234.50: no commas, spaces, currency signs, letters or other characters.'
    && Currency::parseAmount('abc', 'GBP', fromFile: true) === unreadable('GBP'));

// A11 — anything else that is not a figure.
$odd = ['abc', '0x10', '１２', '١٢', '12abc', 'NaN', 'INF', '1/2', '½', '12 50', "12\x0050"];
$bad = [];
foreach ($modes as $modeName => $fromFile) {
    foreach ($odd as $t) {
        if (is_int(Currency::parseAmount($t, 'GBP', fromFile: $fromFile)) === true) {
            $bad[] = $modeName . ' ' . json_encode($t);
        }
    }
}
check('A11 nothing else is a number, in either mode: letters, hex, full-width and Arabic-Indic figures, NaN, INF, fractions, an inner space or NUL', $bad === [], implode(', ', $bad));

// A12 — exactness: every two-decimal amount 0.00 to 99.99 (the range where a
// float most often lands a hair below the hundredth, e.g. 19.99 * 100).
$inexact = 0;
for ($pence = 0; $pence <= 9999; $pence++) {
    $text = intdiv($pence, 100) . '.' . str_pad((string) ($pence % 100), 2, '0', STR_PAD_LEFT);
    if (Currency::parseAmount($text, '') !== $pence || Currency::parseAmount($text, '', fromFile: true) !== $pence) {
        $inexact++;
    }
}
check('A12 every two-decimal amount from 0.00 to 99.99 (10,000 of them) comes back as exactly its number of hundredths, in both modes', $inexact === 0, $inexact . ' wrong');

// A13 — the API's pence fields.
check('A13 parseHundredths() (the asset API): 89999 and "89999" are 89999, "007" is 7, 0 is 0, 2147483647 fits; 2147483648, "18446744073709555712", 899.5, 1e3 (a float), -1, "-1", "1e3", true, null and a list are refused',
    Currency::parseHundredths(89999) === 89999 && Currency::parseHundredths('89999') === 89999 && Currency::parseHundredths('007') === 7
    && Currency::parseHundredths(0) === 0 && Currency::parseHundredths(2147483647) === 2147483647
    && is_string(Currency::parseHundredths(2147483648)) && is_string(Currency::parseHundredths('18446744073709555712'))
    && is_string(Currency::parseHundredths(899.5)) && is_string(Currency::parseHundredths(1e3)) && is_string(Currency::parseHundredths(-1))
    && is_string(Currency::parseHundredths('-1')) && is_string(Currency::parseHundredths('1e3')) && is_string(Currency::parseHundredths(true))
    && is_string(Currency::parseHundredths(null)) && is_string(Currency::parseHundredths([1])));

// A14 — stored DECIMAL text and back.
$threw = false;
try {
    Currency::storedDecimalToHundredths('not money');
} catch (\UnexpectedValueException $e) {
    $threw = true;
}
check('A14 a stored DECIMAL reads back exactly ("123.45" → 12345, "-0.50" → -50, "99999999.99" → 9999999999, null → 0) and anything else THROWS rather than inventing a number',
    Currency::storedDecimalToHundredths('123.45') === 12345 && Currency::storedDecimalToHundredths('-0.50') === -50
    && Currency::storedDecimalToHundredths('99999999.99') === 9999999999 && Currency::storedDecimalToHundredths(null) === 0 && $threw === true);
check('A15 hundredthsToDecimal() writes the text a DECIMAL column takes: 123450 → "1234.50", 5 → "0.05", -5 → "-0.05", 0 → "0.00", 9999999999 → "99999999.99"',
    Currency::hundredthsToDecimal(123450) === '1234.50' && Currency::hundredthsToDecimal(5) === '0.05' && Currency::hundredthsToDecimal(-5) === '-0.05'
    && Currency::hundredthsToDecimal(0) === '0.00' && Currency::hundredthsToDecimal(9999999999) === '99999999.99');

// A16 — the counts this table rests on are pinned, so a shrunken table goes red.
check('A16 the tables are the size they say: 800 cells in A1, 45 in A1s and 10,000 amounts in A12 (a loop narrowed by one edit would otherwise pass quietly)',
    $cells === 800 && $grouped === 45 && $pence === 10000);

if (getenv('MONEY_SELFTEST_UNIT_ONLY') === '1') {
    echo "\nMONEY_SELFTEST_UNIT_ONLY=1: section B (over HTTP) was NOT run.\n";
    echo "{$passed} passed, {$failed} failed.\n";
    exit($failed === 0 ? 0 : 1);
}

// =============================================================================
// What section B needs
// =============================================================================
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (preg_match('/^selftest_[A-Za-z0-9_]+$/', $dbName) !== 1) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this test needs {$needed}(), which this PHP does not have");
    }
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$db->set_charset('utf8mb4');

/** @param list<mixed> $params @return list<array<string,mixed>> */
function rows(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $out    = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $out;
}

/** @param list<mixed> $params */
function run(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();

    return $n;
}

$tables = (int) (rows($db, 'SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()')[0]['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

// ── The test server: a copy of web/, as the other page tests do ─────────────
$child = sys_get_temp_dir() . $sep . 'money-amount-selftest-' . bin2hex(random_bytes(6));
$state['child'] = $child;
register_shutdown_function(static function () use (&$state): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_file($state['child'] . DIRECTORY_SEPARATOR . 'money-amount-selftest.marker') === true) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($state['child'], FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($state['child']);
    }
});
mkdir($child, 0700, true);
file_put_contents($child . $sep . 'money-amount-selftest.marker', 'made by tools/money-amount-selftest.php');
$copied = 0;
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walk as $entry) {
    $relative = substr($entry->getPathname(), strlen($repo . $sep . 'web'));
    if (str_starts_with($relative, $sep . '_auth_keys') === true || str_starts_with($relative, $sep . '_uploads') === true) {
        continue;
    }
    $to = $child . $sep . 'web' . $relative;
    if ($entry->isDir() === true) {
        @mkdir($to, 0700, true);
        continue;
    }
    copy($entry->getPathname(), $to);
    $copied++;
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}
@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
@mkdir($child . $sep . 'web' . $sep . '_uploads', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/money-amount-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/money-amount-selftest.php for ONE run. Imitates web/public_html/.htaccess.
declare(strict_types=1);
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
$target = $root . '/index.php';
$real   = ($uri === '/' || preg_match('#^/assets/?$#', $uri) === 1) ? false : realpath($root . $uri);
if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) === true && is_file($real) === true) {
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php') { $target = $real; } else { readfile($real); return true; }
}
$_SERVER['SCRIPT_NAME']     = substr($target, strlen($root));
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
return true;
ROUTER);

$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9533;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}
$probe = @fsockopen('127.0.0.1', $port, $errNo, $errText, 0.5);
if (is_resource($probe) === true) {
    fclose($probe);
    refuse("something already answers on port {$port}; choose another with SELFTEST_WEB_PORT");
}
$jar = $child . $sep . 'cookies.txt';
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc'] = proc_open(
    [
        PHP_BINARY,
        '-d', 'session.save_path=' . $child . $sep . 'sessions',
        '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'max_execution_time=120',
        '-S', '127.0.0.1:' . $port,
        '-t', $child . $sep . 'web' . $sep . 'public_html',
        $child . $sep . 'router.php',
    ],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server.log', 'a'], 2 => ['file', $child . $sep . 'server.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('could not start PHP\'s web server');
}

/** One request: status, Location and body. Redirects are NOT followed. */
$http = static function (string $method, string $path, array $post = []) use ($port, $jar): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', substr((string) $raw, 0, $headSize), $mm) === 1 ? $mm[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
};
$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $mm) === 1) {
        return html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $mm) === 1) {
        return html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
};
$answered = false;
for ($i = 0; $i < 80; $i++) {
    if ($http('GET', '/login')['status'] > 0) {
        $answered = true;
        break;
    }
    usleep(250000);
}
if ($answered === false) {
    refuse('the test server never answered');
}

$site = \Portal\Core\Site::preDetect($db);
if (\Portal\Core\Site::detectionFailed() === true) {
    refuse('the portal could not say which organisation it serves (Site::preDetect() failed)');
}

// ── Rows this run makes, removed at the end ─────────────────────────────────
$made = ['venues' => []];
$removeAll = static function () use ($db, &$made, $site): void {
    foreach ($made['venues'] as $id) {
        foreach (rows($db, 'SELECT `invoiceID` FROM `tblVenueInvoices` WHERE `venueID` = ?', 'i', [$id]) as $r) {
            run($db, 'DELETE FROM `tblVenueInvoicePayments` WHERE `invoiceID` = ?', 'i', [(int) $r['invoiceID']]);
            run($db, 'DELETE FROM `tblVenueInvoiceLines` WHERE `invoiceID` = ?', 'i', [(int) $r['invoiceID']]);
        }
        run($db, 'DELETE FROM `tblVenueInvoices` WHERE `venueID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblVenueBookings` WHERE `venueID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblVenueAgreements` WHERE `venueID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblVenueUsageTypes` WHERE `venueID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblVenues` WHERE `venueID` = ?', 'i', [$id]);
    }
    foreach (rows($db, "SELECT `venueID` FROM `tblVenues` WHERE `venueName` = 'Money selftest hall'") as $r) {
        $made['venues'][] = (int) $r['venueID'];
    }
    run($db, "DELETE FROM `tblVenueStatuses` WHERE `siteID` = ? AND `statusName` = 'Money selftest booked'", 'i', [$site]);
    run($db, 'DELETE FROM `tblLocalAccounts` WHERE `userID` = ?', 'i', [MA_ADMIN]);
    run($db, 'DELETE FROM `tblUserSites` WHERE `userID` = ?', 'i', [MA_ADMIN]);
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [MA_ADMIN]);
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
};
register_shutdown_function(static function () use ($removeAll): void {
    try {
        $removeAll();
        $removeAll();
    } catch (Throwable $e) {
        echo "  ! the clean-up could not finish ({$e->getMessage()}).\n";
    }
});
$removeAll();
$made['venues'] = [];

$password = 'Ma' . bin2hex(random_bytes(8)) . '!9x';
run($db, 'INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, ?, 1, 1, 1, NOW())',
    'iss', [MA_ADMIN, 'Selftest money', 'money@selftest.invalid']);
run($db, 'INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES (?, ?, 1)', 'ii', [$site, MA_ADMIN]);
run($db, 'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)',
    'iss', [MA_ADMIN, 'selftest-money', password_hash($password, PASSWORD_DEFAULT)]);
$in = $http('POST', '/login', ['csrf_token' => $tokenOn($http('GET', '/login')['body']), 'username' => 'selftest-money', 'password' => $password]);
if ($in['status'] !== 302 && $in['status'] !== 303) {
    refuse("signing in did not answer a redirect (HTTP {$in['status']})");
}
$freshToken = static fn (string $path): string => $tokenOn($http('GET', $path)['body']);

// ── The venue world: a hall, a booking, an agreement and an invoice, in pounds ──
run($db, "INSERT INTO `tblVenues` (`siteID`, `venueName`, `countryCode`, `timezone`) VALUES (?, 'Money selftest hall', 'GB', 'Europe/London')", 'i', [$site]);
$venue = (int) $db->insert_id;
$made['venues'][] = $venue;
run($db, "INSERT INTO `tblVenueStatuses` (`siteID`, `statusName`, `statusCategory`, `countsAsConfirmed`) VALUES (?, 'Money selftest booked', 'agreed', 1)", 'i', [$site]);
$status = (int) $db->insert_id;
run($db, "INSERT INTO `tblVenueUsageTypes` (`siteID`, `venueID`, `typeName`) VALUES (?, ?, 'Money selftest hire')", 'ii', [$site, $venue]);
$usage = (int) $db->insert_id;
run($db, "INSERT INTO `tblVenueBookings` (`siteID`, `venueID`, `bookingDate`, `usageTypeID`, `statusID`, `costPence`, `currency`, `notes`) VALUES (?, ?, CURDATE(), ?, ?, 2500, 'GBP', 'before')",
    'iiii', [$site, $venue, $usage, $status]);
$booking = (int) $db->insert_id;
run($db, "INSERT INTO `tblVenueAgreements` (`siteID`, `venueID`, `title`, `rateAmountPence`, `currency`, `status`) VALUES (?, ?, 'Money selftest terms', 10000, 'GBP', 'active')", 'ii', [$site, $venue]);
$agreement = (int) $db->insert_id;
run($db, "INSERT INTO `tblVenueInvoices` (`siteID`, `venueID`, `invoiceRef`, `issueDate`, `amountPence`, `currency`, `status`) VALUES (?, ?, 'MONEY-OLD', CURDATE(), 40000, 'GBP', 'pending')", 'ii', [$site, $venue]);
$invoice = (int) $db->insert_id;

/** What the page a handler redirected to says (its flash), decoded. */
$flashAfter = static function (array $answer) use ($http, $port): string {
    $to = (string) $answer['location'];
    if ($to === '') {
        return '(no redirect: HTTP ' . $answer['status'] . ')';
    }
    $path = preg_replace('#^https?://[^/]+#', '', $to) ?? $to;

    return html_entity_decode($http('GET', $path)['body'], ENT_QUOTES, 'UTF-8');
};

$snapshot = static fn (): array => [
    'invoices' => rows($db, 'SELECT `invoiceID`, `invoiceRef`, `amountPence` FROM `tblVenueInvoices` WHERE `venueID` = ? ORDER BY `invoiceID`', 'i', [$venue]),
    'lines'    => rows($db, 'SELECT `lineID`, `amountPence` FROM `tblVenueInvoiceLines` WHERE `invoiceID` = ?', 'i', [$invoice]),
    'payments' => rows($db, 'SELECT `payID`, `amountPence` FROM `tblVenueInvoicePayments` WHERE `invoiceID` = ?', 'i', [$invoice]),
    'agreements' => rows($db, 'SELECT `agreementID`, `title`, `rateAmountPence` FROM `tblVenueAgreements` WHERE `venueID` = ? ORDER BY `agreementID`', 'i', [$venue]),
    'bookings' => rows($db, 'SELECT `bookingID`, `notes`, `costPence` FROM `tblVenueBookings` WHERE `venueID` = ? ORDER BY `bookingID`', 'i', [$venue]),
];

/** Every venue post this test makes, by name: the issue's four handlers, five ways in. */
$posts = [
    'a NEW invoice (venues/invoice-save, save)' => static fn (string $amount): array => ['/venues/invoice-save', ['csrf_token' => $freshToken('/venues/invoice?venue=' . $venue),
        'action' => 'save', 'invoiceID' => '0', 'venueID' => (string) $venue, 'invoiceRef' => 'MONEY-NEW', 'issueDate' => date('Y-m-d'), 'amount' => $amount, 'currency' => 'GBP']],
    'an EDITED invoice (venues/invoice-save, save)' => static fn (string $amount): array => ['/venues/invoice-save', ['csrf_token' => $freshToken('/venues/invoice?id=' . $invoice),
        'action' => 'save', 'invoiceID' => (string) $invoice, 'venueID' => (string) $venue, 'invoiceRef' => 'MONEY-EDITED', 'issueDate' => date('Y-m-d'), 'amount' => $amount]],
    'an allocation (venues/invoice-save, allocate)' => static fn (string $amount): array => ['/venues/invoice-save', ['csrf_token' => $freshToken('/venues/invoice?id=' . $invoice),
        'action' => 'allocate', 'invoiceID' => (string) $invoice, 'bookingID' => (string) $booking, 'amount' => $amount]],
    'a payment (venues/invoice-payment-save)' => static fn (string $amount): array => ['/venues/invoice-payment-save', ['csrf_token' => $freshToken('/venues/invoice?id=' . $invoice),
        'action' => 'add', 'invoiceID' => (string) $invoice, 'amount' => $amount, 'paidDate' => date('Y-m-d'), 'method' => 'bank-transfer']],
    'an EDITED agreement\'s rate (venues/agreement-save)' => static fn (string $amount): array => ['/venues/agreement-save', ['csrf_token' => $freshToken('/venues/agreements?edit=' . $agreement),
        'action' => 'save', 'agreementID' => (string) $agreement, 'venueID' => (string) $venue, 'agreementType' => 'standing', 'title' => 'Money selftest terms, renamed',
        'rateAmount' => $amount, 'status' => 'active']],
    'a NEW agreement\'s rate (venues/agreement-save)' => static fn (string $amount): array => ['/venues/agreement-save', ['csrf_token' => $freshToken('/venues/agreements?edit=0'),
        'action' => 'save', 'agreementID' => '0', 'venueID' => (string) $venue, 'agreementType' => 'standing', 'title' => 'Money selftest new terms',
        'rateAmount' => $amount, 'currency' => 'GBP', 'status' => 'draft']],
    'an EDITED booking\'s cost (venues/booking-save)' => static fn (string $amount): array => ['/venues/booking-save', ['csrf_token' => $freshToken('/venues/booking?id=' . $booking),
        'action' => 'save', 'bookingID' => (string) $booking, 'venueID' => (string) $venue, 'bookingDate' => date('Y-m-d'), 'statusID' => (string) $status,
        'usageTypeID' => (string) $usage, 'costPence' => $amount, 'notes' => 'changed by a refused post']],
];

// =============================================================================
// B. The issue's three values, over HTTP, against every venue money box
// =============================================================================
head('Section B — the venue handlers refuse the issue\'s three values, with a true message, and store nothing');
$issueValues = [
    '184467440737095557.12' => TOO_LARGE_INT,
    '1e17'                  => POWER_OF_TEN,
    '-1e30'                 => POWER_OF_TEN,
];
$n = 0;
foreach ($issueValues as $value => $sentence) {
    foreach ($posts as $what => $build) {
        $n++;
        $before = $snapshot();
        [$path, $fields] = $build($value);
        $answer = $http('POST', $path, $fields);
        $after  = $snapshot();
        $page   = $flashAfter($answer);
        $lie    = str_contains($page, 'cannot be negative') === true || str_contains($page, 'Cost cannot be negative') === true;
        check(sprintf('B%02d %s: "%s" is refused with "%s", and nothing changed in the database', $n, $what, $value, $sentence),
            $answer['status'] === 302 && str_contains($page, $sentence) === true && $lie === false && $after === $before,
            'HTTP ' . $answer['status'] . '; message found: ' . var_export(str_contains($page, $sentence), true)
            . '; database changed: ' . var_export($after !== $before, true)
            . ($after !== $before ? ' ' . json_encode(['before' => $before, 'after' => $after]) : '')
            . (preg_match('/alert-danger[^>]*>\s*([^<]*)/', $page, $mm) === 1 ? '; said: ' . trim($mm[1]) : ''));
    }
}

// =============================================================================
// C. The same boxes with an ordinary figure ARE stored — the fixture is live
// =============================================================================
head('Section C — the same posts with ordinary figures are stored exactly (so B\'s "nothing changed" is a refusal, not a dead fixture)');
$http('POST', ...$posts['an EDITED invoice (venues/invoice-save, save)']('412.50'));
$inv = rows($db, 'SELECT `amountPence`, `invoiceRef` FROM `tblVenueInvoices` WHERE `invoiceID` = ?', 'i', [$invoice])[0] ?? [];
check('C1 an edited invoice of "412.50" is stored as 41250', (int) ($inv['amountPence'] ?? 0) === 41250 && ($inv['invoiceRef'] ?? '') === 'MONEY-EDITED', json_encode($inv));
$http('POST', ...$posts['a NEW invoice (venues/invoice-save, save)']('1234.56'));
$new = rows($db, "SELECT `amountPence` FROM `tblVenueInvoices` WHERE `venueID` = ? AND `invoiceRef` = 'MONEY-NEW'", 'i', [$venue]);
check('C2 a new invoice of "1234.56" is stored once, as 123456', count($new) === 1 && (int) $new[0]['amountPence'] === 123456, json_encode($new));
$http('POST', ...$posts['an allocation (venues/invoice-save, allocate)']('10.00'));
$lines = rows($db, 'SELECT `amountPence` FROM `tblVenueInvoiceLines` WHERE `invoiceID` = ?', 'i', [$invoice]);
check('C3 an allocation of "10.00" is stored as 1000', count($lines) === 1 && (int) $lines[0]['amountPence'] === 1000, json_encode($lines));
$http('POST', ...$posts['a payment (venues/invoice-payment-save)']('5.05'));
$pays = rows($db, 'SELECT `amountPence` FROM `tblVenueInvoicePayments` WHERE `invoiceID` = ?', 'i', [$invoice]);
check('C4 a payment of "5.05" is stored as 505', count($pays) === 1 && (int) $pays[0]['amountPence'] === 505, json_encode($pays));
$http('POST', ...$posts['an EDITED agreement\'s rate (venues/agreement-save)']('100.50'));
$agr = rows($db, 'SELECT `title`, `rateAmountPence` FROM `tblVenueAgreements` WHERE `agreementID` = ?', 'i', [$agreement])[0] ?? [];
check('C5 an edited agreement rate of "100.50" is stored as 10050', (int) ($agr['rateAmountPence'] ?? 0) === 10050 && ($agr['title'] ?? '') === 'Money selftest terms, renamed', json_encode($agr));
$http('POST', ...$posts['a NEW agreement\'s rate (venues/agreement-save)']('19.99'));
$agrNew = rows($db, "SELECT `rateAmountPence` FROM `tblVenueAgreements` WHERE `venueID` = ? AND `title` = 'Money selftest new terms'", 'i', [$venue]);
check('C6 a new agreement rate of "19.99" is stored as 1999 (the float path\'s classic 1998.9999…)', count($agrNew) === 1 && (int) $agrNew[0]['rateAmountPence'] === 1999, json_encode($agrNew));
$http('POST', ...$posts['an EDITED booking\'s cost (venues/booking-save)']('25.75'));
$bk = rows($db, 'SELECT `costPence`, `notes` FROM `tblVenueBookings` WHERE `bookingID` = ?', 'i', [$booking])[0] ?? [];
check('C7 an edited booking cost of "25.75" is stored as 2575', (int) ($bk['costPence'] ?? 0) === 2575, json_encode($bk));

// D — two more refusals the old code got silently wrong, over HTTP.
head('Section D — two silent faults of the old code, refused now');
$before = $snapshot();
$page   = $flashAfter($http('POST', ...$posts['an allocation (venues/invoice-save, allocate)']('abc')));
check('D1 an allocation amount that is not a figure ("abc") is refused, where it used to become "no amount" without a word',
    str_contains($page, FIGURES_ONLY) === true && $snapshot() === $before);
$before = $snapshot();
$page   = $flashAfter($http('POST', ...$posts['an EDITED agreement\'s rate (venues/agreement-save)']('12,50')));
check('D2 an agreement rate written with a decimal comma ("12,50") is refused, asking for figures only, where the old code CLEARED the stored rate without a word',
    str_contains($page, 'Rate: ' . FIGURES_ONLY) === true && $snapshot() === $before);
foreach (['D3' => '1,234.50', 'D3b' => '£12.50', 'D3c' => 'GBP 12.50'] as $label => $typed) {
    $before = $snapshot();
    $page   = $flashAfter($http('POST', ...$posts['a payment (venues/invoice-payment-save)']($typed)));
    check($label . ' a payment typed as "' . $typed . '" is refused with the figures-only sentence (D-152), and nothing is stored',
        str_contains($page, FIGURES_ONLY) === true && $snapshot() === $before);
}

// =============================================================================
// E. Four of the other boxes moved onto the converter, over HTTP
// =============================================================================
// The issue named the venue handlers; the same `(float) … * 100` was in giving,
// projects, payments, assets, resources and the AI cap. These four are driven
// here, each refused once and then stored once (the same live-fixture rule as C).
head('Section E — other money boxes moved onto the converter: projects, giving, a giving campaign, the AI cap');
$setting = static function (string $key, string $value) use ($db): void {
    run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
    run($db, "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, '', 0)", 'ss', [$key, $value]);
};
$touched = ['org.currency', 'giving.enabled', 'giving.countRequiresTwoCounters', 'ai_assist.enabled', 'ai_assist.monthCapPence', 'ai_assist.provider', 'ai_assist.userDailyCap', 'ai_assist.audience',
    'ai_assist.anthropic.model', 'ai_assist.openai.model', 'ai_assist.local.baseUrl', 'ai_assist.local.model'];
$marks = implode(',', array_fill(0, count($touched), '?'));
$settingsBefore = rows($db, "SELECT * FROM `tblSettings` WHERE `siteID` IS NULL AND `settingKey` IN ({$marks})", str_repeat('s', count($touched)), $touched);
register_shutdown_function(static function () use ($db, $settingsBefore, $touched, $marks, $site): void {
    try {
        run($db, "DELETE FROM `tblSettings` WHERE `siteID` IS NULL AND `settingKey` IN ({$marks})", str_repeat('s', count($touched)), $touched);
        foreach ($settingsBefore as $row) {
            run($db, 'INSERT INTO `tblSettings` (`settingID`, `siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, NULL, ?, ?, ?, ?)',
                'isssi', [(int) $row['settingID'], $row['settingKey'], $row['settingValue'], $row['defaultValue'], (int) $row['isSensitive']]);
        }
        foreach (rows($db, "SELECT `projectID` FROM `tblProject` WHERE `siteID` = ? AND `title` = 'Money selftest roof'", 'i', [$site]) as $r) {
            run($db, 'DELETE FROM `tblProject` WHERE `projectID` = ?', 'i', [(int) $r['projectID']]);
        }
        run($db, "DELETE FROM `tblPledgeCampaigns` WHERE `siteID` = ? AND `name` = 'Money selftest fund'", 'i', [$site]);
        foreach (rows($db, "SELECT `assetID` FROM `tblAssets` WHERE `siteID` = ? AND `name` LIKE 'Money selftest asset%'", 'i', [$site]) as $r) {
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['tblAssetAudit', 'tblAssetValueHistory', 'tblAssetIdentifiers', 'tblAssetOwners', 'tblAssetScanLog'] as $child) {
                run($db, 'DELETE FROM `' . $child . '` WHERE `assetID` = ?', 'i', [(int) $r['assetID']]);
            }
            run($db, 'DELETE FROM `tblAssets` WHERE `assetID` = ?', 'i', [(int) $r['assetID']]);
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        foreach (rows($db, "SELECT `importID` FROM `tblBankImports` WHERE `siteID` = ? AND `filename` LIKE 'money-selftest-%'", 'i', [$site]) as $r) {
            run($db, 'DELETE FROM `tblBankTxns` WHERE `importID` = ?', 'i', [(int) $r['importID']]);
            run($db, 'DELETE FROM `tblBankImports` WHERE `importID` = ?', 'i', [(int) $r['importID']]);
        }
        foreach (rows($db, "SELECT `categoryID` FROM `tblGivingCategory` WHERE `siteID` = ? AND `name` = 'Money selftest category'", 'i', [$site]) as $r) {
            run($db, 'DELETE FROM `tblGivingEntry` WHERE `categoryID` = ?', 'i', [(int) $r['categoryID']]);
            run($db, 'DELETE FROM `tblCountSessions` WHERE `categoryID` = ?', 'i', [(int) $r['categoryID']]);
            run($db, 'DELETE FROM `tblGivingCategory` WHERE `categoryID` = ?', 'i', [(int) $r['categoryID']]);
        }
    } catch (Throwable $e) {
        echo "  ! the settings clean-up could not finish ({$e->getMessage()}).\n";
    }
});
$setting('org.currency', 'GBP');
$setting('giving.enabled', '1');
$setting('ai_assist.enabled', '1');
$setting('ai_assist.monthCapPence', '5000');
run($db, "INSERT INTO `tblGivingCategory` (`siteID`, `name`, `isActive`) VALUES (?, 'Money selftest category', 1)", 'i', [$site]);
$category = (int) $db->insert_id;

$projects = static fn (): array => rows($db, "SELECT `targetAmountPence` FROM `tblProject` WHERE `siteID` = ? AND `title` = 'Money selftest roof'", 'i', [$site]);
$project  = static fn (string $amount): array => $http('POST', '/projects/manage-save', ['csrf_token' => $freshToken('/projects/manage'),
    'title' => 'Money selftest roof', 'targetAmount' => $amount, 'status' => 'active', 'isPublic' => '1', 'description' => 'x']);
$page = $flashAfter($project('1e17'));
check('E1 a project target of "1e17" is refused with the power-of-ten sentence, and no project is made',
    str_contains($page, 'Target amount: ' . POWER_OF_TEN) === true && $projects() === [], json_encode($projects()));
$page = $flashAfter($project('1,500.00'));
check('E1b a project target of "1,500.00" (a thousands comma) is refused with the figures-only sentence (D-152), and no project is made',
    str_contains($page, 'Target amount: ' . FIGURES_ONLY) === true && $projects() === [], json_encode($projects()));
$project('1500.00');
check('E1c a project target of "1500.00" is stored as 150000', array_map('intval', array_column($projects(), 'targetAmountPence')) === [150000], json_encode($projects()));

$gifts = static fn (): array => rows($db, 'SELECT `amountPence` FROM `tblGivingEntry` WHERE `categoryID` = ?', 'i', [$category]);
$gift  = static fn (string $amount): array => $http('POST', '/giving/entry-save', ['csrf_token' => $freshToken('/giving/manage'),
    'donorName' => '', 'categoryID' => (string) $category, 'amount' => $amount, 'donatedAt' => date('Y-m-d'), 'method' => 'cash']);
$page = $flashAfter($gift('184467440737095557.12'));
check('E2 a gift of "184467440737095557.12" is refused as too large (in pounds: £21,474,836.47), and no gift is stored — the old code stored 40.96',
    str_contains($page, 'Amount: That amount is too large: the most that can be stored here is £21,474,836.47.') === true && $gifts() === [], json_encode($gifts()));
$page = $flashAfter($gift('£12.50'));
check('E2b a gift typed with the organisation\'s own sign, "£12.50", is refused with the figures-only sentence (D-152), and no gift is stored',
    str_contains($page, 'Amount: ' . FIGURES_ONLY) === true && $gifts() === [], json_encode($gifts()));
$gift('12.50');
check('E2c a gift of "12.50" is stored as 1250', array_map('intval', array_column($gifts(), 'amountPence')) === [1250], json_encode($gifts()));

$campaigns = static fn (): array => rows($db, "SELECT `goalAmountPence` FROM `tblPledgeCampaigns` WHERE `siteID` = ? AND `name` = 'Money selftest fund'", 'i', [$site]);
$campaign  = static fn (string $amount): array => $http('POST', '/giving/campaign-save', ['csrf_token' => $freshToken('/giving/campaigns'),
    'campaignID' => '0', 'name' => 'Money selftest fund', 'goalAmount' => $amount, 'startDate' => date('Y-m-d'), 'isActive' => '1']);
$page = $flashAfter($campaign('-1e30'));
check('E3 a campaign goal of "-1e30" is refused with the power-of-ten sentence, and no campaign is made',
    str_contains($page, 'Goal amount: ' . POWER_OF_TEN) === true && $campaigns() === [], json_encode($campaigns()));
$page = $flashAfter($campaign('12,50'));
check('E3b a campaign goal with a decimal comma, "12,50", is refused with the figures-only sentence — the old code made a goal of 1,250.00',
    str_contains($page, 'Goal amount: ' . FIGURES_ONLY) === true && $campaigns() === [], json_encode($campaigns()));
$campaign('5000');
check('E3c a campaign goal of "5000" is stored as 500000', array_map('intval', array_column($campaigns(), 'goalAmountPence')) === [500000], json_encode($campaigns()));

$cap = static fn (): string => (string) (rows($db, "SELECT `settingValue` FROM `tblSettings` WHERE `siteID` IS NULL AND `settingKey` = 'ai_assist.monthCapPence'")[0]['settingValue'] ?? '');
$ai  = static fn (string $amount): array => $http('POST', '/admin/ai-assist/save', ['csrf_token' => $freshToken('/admin/ai-assist'),
    'provider' => 'anthropic', 'monthCap' => $amount, 'userDailyCap' => '20', 'audience' => 'congregation', 'enabled' => '1']);
$page = $flashAfter($ai('1e17'));
check('E4 an AI monthly cap of "1e17" is refused with the power-of-ten sentence and the stored cap stays 5000',
    str_contains($page, 'Monthly cap: ' . POWER_OF_TEN) === true && $cap() === '5000', 'stored ' . $cap());
$page = $flashAfter($ai(''));
check('E4b an EMPTY AI cap is refused ("No amount was entered."), where the old code stored 0, which stops every request',
    str_contains($page, 'Monthly cap: No amount was entered.') === true && $cap() === '5000', 'stored ' . $cap());
$ai('12.34');
check('E4c an AI cap of "12.34" is stored as 1234', $cap() === '1234', 'stored ' . $cap());

/**
 * The fields a page's own form would post, read with the browser's parser: the
 * first form whose action ends with $actionEndsWith, holding a field named
 * $mustHave, and whose hidden fields match $where. Inputs (checkboxes only
 * when checked), the selected option of each select, and text areas.
 *
 * @param array<string,string> $where
 * @return array<string,string> [] when there is no such form
 */
function formFields(string $html, string $actionEndsWith, string $mustHave, array $where = []): array
{
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    foreach ($doc->getElementsByTagName('form') as $form) {
        if (str_ends_with((string) $form->getAttribute('action'), $actionEndsWith) === false) {
            continue;
        }
        $fields = [];
        foreach ((new DOMXPath($doc))->query('.//input|.//select|.//textarea', $form) as $el) {
            $name = (string) $el->getAttribute('name');
            if ($name === '' || $el->hasAttribute('disabled') === true) {
                continue;
            }
            $type = strtolower((string) $el->getAttribute('type'));
            if ($el->nodeName === 'input' && in_array($type, ['checkbox', 'radio'], true) === true && $el->hasAttribute('checked') === false) {
                continue;
            }
            if ($el->nodeName === 'input' && $type === 'file') {
                continue;
            }
            $value = (string) $el->getAttribute('value');
            if ($el->nodeName === 'textarea') {
                $value = $el->textContent;
            } elseif ($el->nodeName === 'select') {
                $value = '';
                foreach ($el->getElementsByTagName('option') as $i => $opt) {
                    if ($i === 0 || $opt->hasAttribute('selected') === true) {
                        $value = (string) $opt->getAttribute('value');
                    }
                }
            }
            $fields[$name] = $value;
        }
        if (array_key_exists($mustHave, $fields) === false) {
            continue;
        }
        foreach ($where as $k => $v) {
            if (($fields[$k] ?? null) !== $v) {
                continue 2;
            }
        }
        return $fields;
    }
    return [];
}

// =============================================================================
// F. An offering count: a figure it can save, it can also CLOSE
// =============================================================================
// Closing a count writes each bucket and each envelope WHOLE into
// tblGivingEntry.amountPence, a signed INT. Round 1 capped the count boxes at
// their own DECIMAL(10,2) column (99,999,999.99), so a figure above
// 21,474,836.47 saved and then made the count impossible to close.
head('Section F — an offering count refuses a figure it could never close, and closes one at the limit');
$setting('giving.countRequiresTwoCounters', 'false');
run($db, "INSERT INTO `tblCountSessions` (`siteID`, `serviceDate`, `categoryID`, `status`, `currency`) VALUES (?, CURDATE(), ?, 'counting', 'GBP')", 'ii', [$site, $category]);
$count = (int) $db->insert_id;
$countRow = static fn (): array => rows($db, 'SELECT `cashTotal1`, `cashTotal`, `status` FROM `tblCountSessions` WHERE `countSessionID` = ?', 'i', [$count])[0] ?? [];
$countPost = static fn (string $cash): array => $http('POST', '/giving/count/save', ['csrf_token' => $freshToken('/giving/count/session?id=' . $count),
    'action' => 'count', 'countSessionID' => (string) $count, 'slot' => '1', 'cashAmount' => $cash, 'chequeAmount' => '0', 'envelopeAmount' => '0']);
foreach (['F1' => '21474836.48', 'F1b' => '99999999.99'] as $label => $typed) {
    $page = $flashAfter($countPost($typed));
    check($label . ' a cash total of "' . $typed . '" (it fits the DECIMAL(10,2) column but not the gift log it is closed into) is refused, saying the most is £21,474,836.47, and nothing is saved',
        str_contains($page, 'Cash total: That amount is too large: the most that can be stored here is £21,474,836.47.') === true
        && array_key_exists('cashTotal1', $countRow()) === true && $countRow()['cashTotal1'] === null,
        json_encode($countRow()) . (preg_match('/alert-[a-z]+">([^<]*)/', $page, $mm) === 1 ? '; said: ' . $mm[1] : '; no alert'));
}
$countPost('21474836.47');
check('F2 a cash total of exactly "21474836.47" is saved and agreed (single-counter mode)',
    ($countRow()['cashTotal1'] ?? '') === '21474836.47' && ($countRow()['cashTotal'] ?? '') === '21474836.47', json_encode($countRow()));
// F2b — the counter's form is prefilled with what was saved; posted back
// UNCHANGED (a counter re-entering after a discrepancy) it must be accepted.
// Round 2 prefilled number_format()'s "21,474,836.47", which the figures-only
// box then refused about a figure nobody typed.
$countForm = formFields($http('GET', '/giving/count/session?id=' . $count)['body'], 'giving/count/save', 'cashAmount', ['action' => 'count', 'slot' => '1']);
$countBack = $flashAfter($http('POST', '/giving/count/save', $countForm));
check('F2b the counter\'s own form, read off the real page and posted back unchanged, is prefilled with PLAIN figures ("21474836.47") and is accepted; the saved total is unchanged',
    ($countForm['cashAmount'] ?? '') === '21474836.47' && str_contains($countBack, 'Counter 1 totals saved') === true
    && str_contains($countBack, FIGURES_ONLY) === false && ($countRow()['cashTotal1'] ?? '') === '21474836.47',
    'prefilled ' . var_export($countForm['cashAmount'] ?? null, true) . '; ' . json_encode($countRow()));
$closed = $flashAfter($http('POST', '/giving/count/close', ['csrf_token' => $freshToken('/giving/count/session?id=' . $count), 'countSessionID' => (string) $count]));
$logged = array_map('intval', array_column(rows($db, "SELECT `amountPence` FROM `tblGivingEntry` WHERE `reference` = CONCAT('Count #', ?) ORDER BY `entryID`", 'i', [$count]), 'amountPence'));
check('F3 and that count CAN be closed: it is closed and the gift log holds 2147483647, the INT column\'s most',
    ($countRow()['status'] ?? '') === 'closed' && in_array(2147483647, $logged, true) === true && str_contains($closed, 'Count session closed') === true,
    json_encode([$countRow(), $logged]));

// =============================================================================
// G. The bank-statement import reads a statement's own number format (D-152)
// =============================================================================
head('Section G — the bank-statement import: commas and its own sign read, a huge debit skipped and named, a bad cell refused with its reason');
$upload = static function (string $name, string $csv) use ($port, $jar, $child, $freshToken): array {
    $file = $child . DIRECTORY_SEPARATOR . $name;
    file_put_contents($file, $csv);
    $handle = curl_init('http://127.0.0.1:' . $port . '/giving/reconcile/import');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['csrf_token' => $freshToken('/giving/reconcile/import'), 'action' => 'upload', 'csv_file' => new CURLFile($file, 'text/csv', $name)],
    ]);
    $raw  = (string) curl_exec($handle);
    $head = substr($raw, 0, (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE));

    return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $mm) === 1 ? $mm[1] : ''];
};
$statement = "Date,Description,Amount\n"
    . "01/11/2026,Money selftest gift A,\"1,234.50\"\n"
    . "02/11/2026,Money selftest gift B,£12.50\n"
    . "03/11/2026,Money selftest rent,-99999999999.00\n"
    . "04/11/2026,Money selftest small debit,-5.00\n"
    . "05/11/2026,Money selftest gift C,\"-£0.00\"\n";
$preview = $flashAfter($upload('money-selftest-' . bin2hex(random_bytes(4)) . '.csv', $statement));
check('G1 a statement with "1,234.50" and "£12.50" credits previews TWO credits worth £1,247.00 — the statement\'s own commas and the import\'s own sign are read (D-152 leaves a file\'s format alone)',
    preg_match('/<strong>2<\/strong>\s*credits,\s*£1,247\.00/', $preview) === 1, mb_substr(preg_replace('/\s+/', ' ', strip_tags($preview)) ?? '', 0, 600));
check('G2 a debit too large to store ("-99999999999.00") does NOT stop the import: it is skipped with the other debits (3 skipped) and NAMED, as round 1 would have refused the whole file over it',
    str_contains($preview, '3 skipped') === true
    && str_contains($preview, 'Row 4: a debit of "-99999999999.00" is larger than the portal can store; it was skipped, as every debit is.') === true);
$confirm = $http('POST', '/giving/reconcile/import', ['csrf_token' => $freshToken('/giving/reconcile/import'), 'action' => 'confirm']);
$importId = (int) (rows($db, "SELECT MAX(`importID`) AS `m` FROM `tblBankImports` WHERE `siteID` = ? AND `filename` LIKE 'money-selftest-%'", 'i', [$site])[0]['m'] ?? 0);
$txns = array_map('intval', array_column(rows($db, 'SELECT `amountPence` FROM `tblBankTxns` WHERE `importID` = ? ORDER BY `txnID`', 'i', [$importId]), 'amountPence'));
check('G3 confirmed, the two credits are stored exactly: 123450 and 1250', $txns === [123450, 1250], 'HTTP ' . $confirm['status'] . '; ' . json_encode($txns));
foreach (['G4' => ['0,500', 'amount "0,500": ' . BAD_COMMAS],
          'G4b' => ['$5.00', 'amount "$5.00": ' . otherCur('GBP')],
          'G4d' => ['€5.00', 'amount "€5.00": ' . otherCur('GBP')],
          'G4c' => ['1e17', 'amount "1e17": ' . unreadable('GBP', true)]] as $label => [$cell, $said]) {
    $before = rows($db, "SELECT COUNT(*) AS `n` FROM `tblBankImports` WHERE `siteID` = ? AND `filename` LIKE 'money-selftest-%'", 'i', [$site])[0]['n'];
    $page   = $flashAfter($upload('money-selftest-' . bin2hex(random_bytes(4)) . '.csv', "Date,Description,Amount\n01/11/2026,Money selftest,\"{$cell}\"\n"));
    $http('POST', '/giving/reconcile/import', ['csrf_token' => $freshToken('/giving/reconcile/import'), 'action' => 'cancel']);
    $after  = rows($db, "SELECT COUNT(*) AS `n` FROM `tblBankImports` WHERE `siteID` = ? AND `filename` LIKE 'money-selftest-%'", 'i', [$site])[0]['n'];
    check($label . ' a credit cell "' . $cell . '" stops the file with the CONVERTER\'s own sentence — "Row 2, ' . $said . '" — and nothing is imported',
        str_contains($page, 'Row 2, ' . $said) === true && $after === $before, mb_substr(preg_replace('/\s+/', ' ', strip_tags($page)) ?? '', 0, 600));
}

// G5 — a statement with a currency sign while the organisation has NO currency
// chosen: refused (D-139: the money of an amount is never guessed), saying so
// and what to do. Round 2 said "it should be figures", which was untrue.
$setting('org.currency', '');
$page = $flashAfter($upload('money-selftest-' . bin2hex(random_bytes(4)) . '.csv', "Date,Description,Amount\n01/11/2026,Money selftest,£12.50\n"));
$http('POST', '/giving/reconcile/import', ['csrf_token' => $freshToken('/giving/reconcile/import'), 'action' => 'cancel']);
check('G5 with NO currency chosen, a statement cell "£12.50" stops the file saying the true reason and what to do (choose the organisation\'s currency), and nothing is imported',
    str_contains($page, 'Row 2, amount "£12.50": ' . NO_CURRENCY_START . '£' . NO_CURRENCY_END) === true
    && (int) rows($db, "SELECT COUNT(*) AS `n` FROM `tblBankImports` WHERE `siteID` = ? AND `filename` LIKE 'money-selftest-%'", 'i', [$site])[0]['n'] === 1,
    mb_substr(preg_replace('/\s+/', ' ', strip_tags($page)) ?? '', 0, 600));
$setting('org.currency', 'GBP');

// =============================================================================
// H. Every money edit form, read off its real page and posted back UNCHANGED
// =============================================================================
// A figures-only box (D-152) must be prefilled with figures only, or the next
// plain Save is refused over a figure nobody typed. Each amount is set above
// 1,000 first, because number_format()'s comma only appears from there.
head('Section H — every money edit form round-trips its own prefilled value (above 1,000)');
run($db, 'UPDATE `tblVenueInvoices` SET `amountPence` = 123456 WHERE `invoiceID` = ?', 'i', [$invoice]);
run($db, 'UPDATE `tblVenueAgreements` SET `rateAmountPence` = 123456 WHERE `agreementID` = ?', 'i', [$agreement]);
run($db, 'UPDATE `tblVenueBookings` SET `costPence` = 123456 WHERE `bookingID` = ?', 'i', [$booking]);
$setting('ai_assist.monthCapPence', '123456');
$projectRow  = rows($db, "SELECT `slug` FROM `tblProject` WHERE `siteID` = ? AND `title` = 'Money selftest roof'", 'i', [$site])[0] ?? ['slug' => ''];
$campaignRow = rows($db, "SELECT `campaignID` FROM `tblPledgeCampaigns` WHERE `siteID` = ? AND `name` = 'Money selftest fund'", 'i', [$site])[0] ?? ['campaignID' => 0];
$roundTrips = [
    'H1 a venue invoice'  => ['/venues/invoice?id=' . $invoice, 'venues/invoice-save', 'amount', ['action' => 'save'], '1234.56',
        static fn (): string => (string) (rows($db, 'SELECT `amountPence` FROM `tblVenueInvoices` WHERE `invoiceID` = ?', 'i', [$invoice])[0]['amountPence'] ?? ''), '123456', 'Invoice saved.'],
    'H2 a venue agreement' => ['/venues/agreements?edit=' . $agreement, 'venues/agreement-save', 'rateAmount', ['action' => 'save'], '1234.56',
        static fn (): string => (string) (rows($db, 'SELECT `rateAmountPence` FROM `tblVenueAgreements` WHERE `agreementID` = ?', 'i', [$agreement])[0]['rateAmountPence'] ?? ''), '123456', 'Agreement saved.'],
    'H3 a venue booking'  => ['/venues/booking?id=' . $booking, 'venues/booking-save', 'costPence', ['action' => 'save'], '1234.56',
        static fn (): string => (string) (rows($db, 'SELECT `costPence` FROM `tblVenueBookings` WHERE `bookingID` = ?', 'i', [$booking])[0]['costPence'] ?? ''), '123456', 'redirect:/venues?venue='],
    'H4 a project'        => ['/projects/manage?slug=' . rawurlencode((string) $projectRow['slug']), 'projects/manage-save', 'targetAmount', [], '1500.00',
        static fn (): string => (string) (rows($db, "SELECT `targetAmountPence` FROM `tblProject` WHERE `siteID` = ? AND `title` = 'Money selftest roof'", 'i', [$site])[0]['targetAmountPence'] ?? ''), '150000', 'Project updated.'],
    'H5 a giving campaign' => ['/giving/campaign?id=' . (int) $campaignRow['campaignID'], 'giving/campaign-save', 'goalAmount', [], '5000.00',
        static fn (): string => (string) (rows($db, "SELECT `goalAmountPence` FROM `tblPledgeCampaigns` WHERE `siteID` = ? AND `name` = 'Money selftest fund'", 'i', [$site])[0]['goalAmountPence'] ?? ''), '500000', 'Campaign updated.'],
    'H6 the AI cap'       => ['/admin/ai-assist', 'admin/ai-assist/save', 'monthCap', [], '1234.56', $cap, '123456', 'AI Assist settings saved.'],
];
foreach ($roundTrips as $label => [$pagePath, $action, $field, $where, $prefill, $stored, $want, $saved]) {
    $fields = formFields($http('GET', $pagePath)['body'], $action, $field, $where);
    // A booking that saves goes to the schedule, which prints no message
    // (found here: venues/index.php shows no flash), so its success is read
    // from WHERE it was sent; a refusal goes back to the booking form.
    $posted = $http('POST', '/' . $action, $fields);
    $answer = $flashAfter($posted);
    $didSave = str_starts_with($saved, 'redirect:') === true
        ? str_contains((string) $posted['location'], substr($saved, strlen('redirect:'))) === true
        : str_contains($answer, $saved) === true;
    check($label . '\'s edit form, posted back unchanged, is prefilled with plain figures ("' . $prefill . '") and SAVES (' . $saved . '); the stored amount is still ' . $want,
        ($fields[$field] ?? null) === $prefill && $didSave === true && str_contains($answer, FIGURES_ONLY) === false && $stored() === $want,
        'prefilled ' . var_export($fields[$field] ?? null, true) . '; stored ' . $stored()
        . (preg_match('/alert-[a-z]+">([^<]*)/', $answer, $mm) === 1 ? '; said: ' . $mm[1] : ''));
}

// =============================================================================
// I. The asset import reads a spreadsheet's amounts the way the bank import does (D-153)
// =============================================================================
head('Section I — the asset import: "£1,234.56" and "1,000" read; a sign not read as GBP here refused saying so (convert, do not relabel)');
$assetUpload = static function (string $name, string $csv) use ($port, $jar, $child, $freshToken): array {
    $file = $child . DIRECTORY_SEPARATOR . $name;
    file_put_contents($file, $csv);
    $handle = curl_init('http://127.0.0.1:' . $port . '/assets/import');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['csrf_token' => $freshToken('/assets/import'), 'action' => 'upload', 'csv_file' => new CURLFile($file, 'text/csv', $name)],
    ]);
    $raw  = (string) curl_exec($handle);
    $head = substr($raw, 0, (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE));

    return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $mm) === 1 ? $mm[1] : ''];
};
$assetCosts = static fn (): array => array_map('intval', array_column(rows($db, "SELECT `purchaseCostPence` FROM `tblAssets` WHERE `siteID` = ? AND `name` LIKE 'Money selftest asset%' ORDER BY `name`", 'i', [$site]), 'purchaseCostPence'));
$assetUpload('money-selftest-assets.csv', "Name,Purchase Cost\nMoney selftest asset A,\"£1,234.56\"\nMoney selftest asset B,\"1,000\"\nMoney selftest asset C,GBP 7.50\n");
$assetConfirm = $http('POST', '/assets/import', ['csrf_token' => $freshToken('/assets/import'), 'action' => 'confirm']);
check('I1 a spreadsheet with "£1,234.56", "1,000" and "GBP 7.50" imports three assets costing 123456, 100000 and 750 — the asset\'s own currency sign and code and thousands commas are read (D-153)',
    $assetCosts() === [123456, 100000, 750], 'HTTP ' . $assetConfirm['status'] . '; ' . json_encode($assetCosts()));
$preview = $flashAfter($assetUpload('money-selftest-assets-2.csv', "Name,Purchase Cost\nMoney selftest asset D,\"€5.00\"\nMoney selftest asset E,\"0,500\"\nMoney selftest asset F,\"$5.00\"\n"));
$http('POST', '/assets/import', ['csrf_token' => $freshToken('/assets/import'), 'action' => 'cancel']);
check('I2 signs that are not read as GBP here ("€5.00", "$5.00", D-165) are refused as signs not read as GBP here, told to convert rather than relabel (rounds 10-11), and a misplaced comma ("0,500") with its reason; no asset is made',
    str_contains($preview, 'Purchase Cost "€5.00": ' . otherCur('GBP')) === true
    && str_contains($preview, 'Purchase Cost "$5.00": ' . otherCur('GBP')) === true
    && str_contains($preview, 'Purchase Cost "0,500": ' . BAD_COMMAS) === true && count($assetCosts()) === 3,
    mb_substr(preg_replace('/\s+/', ' ', strip_tags($preview)) ?? '', 0, 600));

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
