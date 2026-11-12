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
 * is now the ONE converter, and this test proves it two ways:
 *
 *   A  the converter itself (no database), over a TABLE OF DIMENSIONS rather
 *      than a few examples: every count of figures before the point (1 to 20)
 *      against every count after it (0 to 4), each sign, each kind of
 *      exponent, each placement of commas, whitespace at each end and inside,
 *      the empty box, each maximum (an INT column, a DECIMAL(10,2) column, a
 *      caller's own) at exactly the limit and one hundredth over, the
 *      currency's own symbol and code against another's, non-ASCII figures,
 *      and every two-decimal amount from 0.00 to 99.99 for exactness; then
 *      parseHundredths() (the asset API's pence fields),
 *      storedDecimalToHundredths() and hundredthsToDecimal();
 *   B  over real HTTP, the FOUR venue handlers the issue names — invoice save
 *      (new and edited), allocation, payment, agreement and booking — each
 *      posted the issue's three values. Each is refused with a TRUE message
 *      read back off the page, and the database is read to prove nothing was
 *      stored. Each handler is then posted an ordinary figure, which IS
 *      stored exactly: without that, "nothing stored" could mean a dead
 *      fixture rather than a refusal (#645's lesson).
 *
 * WHAT IT CANNOT SHOW, said plainly:
 *   - the other handlers moved onto the converter (giving, projects, payments,
 *     assets, resources, the AI and translation caps) are not driven here; the
 *     converter they call is the one section A tests, and
 *     tools/currency-selftest.php and tools/content-translation-selftest.php
 *     drive several of them over HTTP with ordinary figures;
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
const NOT_AN_AMOUNT = 'That is not an amount: type it in figures, with at most one decimal point, for example 12.50.';
const BAD_COMMAS    = 'The commas in that amount are in the wrong places: use a point for the decimals (12.50), and a comma only between thousands (1,234.50).';
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
head('Section A — Currency::parseAmount() over a table of dimensions (no database)');

// A1 — figures before the point (1-20) × figures after it (0-4) × sign, against
// the INT maximum. Each figure is a 7, so 7777…, which crosses the INT limit at
// ten figures before the point minus two: the table covers both sides of it.
$mismatches = [];
$cells      = 0;
foreach ([false, true] as $negative) {
    foreach ([false, true] as $allowNegative) {
        for ($w = 1; $w <= 20; $w++) {
            for ($f = 0; $f <= 4; $f++) {
                $whole    = str_repeat('7', $w);
                $fraction = str_repeat('3', $f);
                $text     = ($negative === true ? '-' : '') . $whole . ($f > 0 ? '.' . $fraction : '');
                $want     = expectedFor($whole, $fraction, $negative, Currency::INT_COLUMN_MAX, $allowNegative);
                $got      = Currency::parseAmount($text, '', Currency::INT_COLUMN_MAX, $allowNegative);
                $same     = $want === 'too large' ? (is_string($got) === true && str_starts_with($got, 'That amount is too large')) : $got === $want;
                $cells++;
                if ($same === false) {
                    $mismatches[] = $text . ($allowNegative === true ? ' (negatives allowed)' : '') . ' → ' . var_export($got, true) . ', wanted ' . var_export($want, true);
                }
            }
        }
    }
}
check('A1 every count of figures before the point (1 to 20) against every count after it (0 to 4), with and without a minus, negatives allowed or not (' . $cells . ' cells): stored exactly when it fits, refused with the right reason when it does not',
    $mismatches === [] && $cells === 400, implode(' | ', array_slice($mismatches, 0, 5)));

// A2 — the same table against the DECIMAL(10,2) maximum and a caller's own maximum.
$mismatches = [];
foreach ([Currency::DECIMAL_10_2_MAX, 100000, 0] as $max) {
    for ($w = 1; $w <= 12; $w++) {
        for ($f = 0; $f <= 2; $f++) {
            $whole = str_repeat('9', $w);
            $text  = $whole . ($f > 0 ? '.' . str_repeat('9', $f) : '');
            $want  = expectedFor($whole, str_repeat('9', $f), false, $max, false);
            $got   = Currency::parseAmount($text, '', $max);
            $same  = $want === 'too large' ? (is_string($got) === true && str_starts_with($got, 'That amount is too large')) : $got === $want;
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

// A4 — the issue's three values, each with its TRUE reason.
check('A4 the issue\'s three values: "184467440737095557.12" is TOO LARGE (it used to be stored as 40.96), "1e17" and "-1e30" are refused as powers of ten — never "cannot be negative" and never a number',
    Currency::parseAmount('184467440737095557.12', '') === TOO_LARGE_INT
    && Currency::parseAmount('1e17', '') === POWER_OF_TEN && Currency::parseAmount('-1e30', '') === POWER_OF_TEN
    && Currency::parseAmount('-1e30', '', allowNegative: true) === POWER_OF_TEN,
    var_export([Currency::parseAmount('184467440737095557.12', ''), Currency::parseAmount('1e17', ''), Currency::parseAmount('-1e30', '')], true));

// A5 — exponents, every spelling.
$exps = ['1e17', '1E17', '1e+17', '1e-2', '1.5e2', '.5e1', '1,000e3', '-1e30', '9e999'];
$bad  = array_values(array_filter($exps, static fn (string $t): bool => Currency::parseAmount($t, '', allowNegative: true) !== POWER_OF_TEN));
check('A5 a power of ten is refused in every spelling (' . implode(', ', $exps) . '), with the sentence that says so', $bad === [], implode(', ', $bad));

// A6 — signs.
check('A6 signs: "-3" is refused where negatives are not allowed ("cannot be negative") and is -300 where they are; "-0" and "-0.00" are 0 either way; "+5", "--5", "5-" and "- 5" are not amounts',
    Currency::parseAmount('-3', '') === NEGATIVE && Currency::parseAmount('-3', '', allowNegative: true) === -300
    && Currency::parseAmount('-0', '') === 0 && Currency::parseAmount('-0.00', '') === 0
    && Currency::parseAmount('+5', '', allowNegative: true) === NOT_AN_AMOUNT && Currency::parseAmount('--5', '', allowNegative: true) === NOT_AN_AMOUNT
    && Currency::parseAmount('5-', '', allowNegative: true) === NOT_AN_AMOUNT && Currency::parseAmount('- 5', '', allowNegative: true) === NOT_AN_AMOUNT);

// A7 — the decimal point.
check('A7 the point: "12." is 1200, ".5" is 50, ".05" is 5, "007.50" is 750; "." alone, "1.2.3", "12.345" and "0.001" are refused, the last two as too many decimals',
    Currency::parseAmount('12.', '') === 1200 && Currency::parseAmount('.5', '') === 50 && Currency::parseAmount('.05', '') === 5
    && Currency::parseAmount('007.50', '') === 750 && Currency::parseAmount('.', '') === NOT_AN_AMOUNT && Currency::parseAmount('1.2.3', '') === NOT_AN_AMOUNT
    && Currency::parseAmount('12.345', '') === TOO_PRECISE && Currency::parseAmount('0.001', '') === TOO_PRECISE);

// A8 — thousands separators, every placement.
$commasOk  = ['1,234' => 123400, '1,234.50' => 123450, '12,345,678.9' => 1234567890, '999,999' => 99999900];
$commasBad = ['12,50', '1,23', '1,2345', ',123', '1,,234', '1234,567', '1,234,56', '12,345,6789'];
$okWrong   = array_keys(array_filter($commasOk, static fn (int $want, string $t): bool => Currency::parseAmount($t, '') !== $want, ARRAY_FILTER_USE_BOTH));
$badWrong  = array_values(array_filter($commasBad, static fn (string $t): bool => Currency::parseAmount($t, '') !== BAD_COMMAS));
check('A8 commas: accepted only between thousands (' . implode(', ', array_keys($commasOk)) . '); refused anywhere else, a decimal comma "12,50" included, which the old code stored as 1,250.00',
    $okWrong === [] && $badWrong === [], json_encode([$okWrong, $badWrong]));
check('A8b other separators are not amounts: "1 234", "1.234,50", "1_000", "1\'000"',
    Currency::parseAmount('1 234', '') === NOT_AN_AMOUNT && Currency::parseAmount('1.234,50', '') === NOT_AN_AMOUNT
    && Currency::parseAmount('1_000', '') === NOT_AN_AMOUNT && Currency::parseAmount("1'000", '') === NOT_AN_AMOUNT);

// A9 — whitespace and the empty box.
check('A9 spaces, tabs and line breaks at either end are ignored ("  12.50 ", "\t7\n"); an empty or blank box answers "No amount was entered."',
    Currency::parseAmount('  12.50 ', '') === 1250 && Currency::parseAmount("\t7\n", '') === 700
    && Currency::parseAmount('', '') === Currency::REFUSED_EMPTY && Currency::parseAmount("  \t ", '') === Currency::REFUSED_EMPTY
    && Currency::REFUSED_EMPTY === 'No amount was entered.');

// A10 — the currency's own symbol and code, and another's.
check('A10 the currency\'s OWN symbol or code is accepted in front, with the minus either side: "£12.50", "GBP 12.50", "-£3.00", "£-3.00" (negatives allowed), "CHF 3", "CA$5"',
    Currency::parseAmount('£12.50', 'GBP') === 1250 && Currency::parseAmount('GBP 12.50', 'GBP') === 1250
    && Currency::parseAmount('-£3.00', 'GBP', allowNegative: true) === -300 && Currency::parseAmount('£-3.00', 'GBP', allowNegative: true) === -300
    && Currency::parseAmount('CHF 3', 'CHF') === 300 && Currency::parseAmount('CA$5', 'CAD') === 500);
check('A10b ANOTHER currency\'s symbol is refused ("$12.50" for pounds, "£12.50" for euros), and with no currency known no symbol is accepted at all — the old code took "$12" as pounds',
    is_string(Currency::parseAmount('$12.50', 'GBP')) === true && is_string(Currency::parseAmount('£12.50', 'EUR')) === true
    && is_string(Currency::parseAmount('£12.50', '')) === true && is_string(Currency::parseAmount('$5', 'CAD')) === true);
check('A10c a currency not counted in hundredths is refused outright, with the reason the organisation page gives (yen, Kuwaiti dinar)',
    Currency::parseAmount('5', 'JPY') === Currency::whyNotChoosable('JPY') && Currency::parseAmount('5', 'KWD') === Currency::whyNotChoosable('KWD'));
check('A10d the not-an-amount sentence offers the currency\'s own way of writing it when there is one ("for example 12.50 or £12.50.")',
    Currency::parseAmount('abc', 'GBP') === 'That is not an amount: type it in figures, with at most one decimal point, for example 12.50 or £12.50.');

// A11 — anything else that is not a figure.
$odd = ['abc', '0x10', '１２', '١٢', '12abc', 'NaN', 'INF', '1/2', '½', '12 50', "12\x0050"];
$bad = array_values(array_filter($odd, static fn (string $t): bool => is_int(Currency::parseAmount($t, ''))));
check('A11 nothing else is a number: letters, hex, full-width and Arabic-Indic figures, NaN, INF, fractions, an inner space or NUL', $bad === [], json_encode($bad));

// A12 — exactness: every two-decimal amount 0.00 to 99.99 (the range where a
// float most often lands a hair below the hundredth, e.g. 19.99 * 100).
$inexact = 0;
for ($pence = 0; $pence <= 9999; $pence++) {
    $text = intdiv($pence, 100) . '.' . str_pad((string) ($pence % 100), 2, '0', STR_PAD_LEFT);
    if (Currency::parseAmount($text, '') !== $pence) {
        $inexact++;
    }
}
check('A12 every two-decimal amount from 0.00 to 99.99 (10,000 of them) comes back as exactly its number of hundredths', $inexact === 0, $inexact . ' wrong');

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

// A16 — the two counts this table rests on are pinned, so a shrunken table goes red.
check('A16 the table is the size it says: 400 cells in A1 and 10,000 amounts in A12 (a loop narrowed by one edit would otherwise pass quietly)',
    $cells === 400 && $pence === 10000);

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
$http('POST', ...$posts['a NEW invoice (venues/invoice-save, save)']('1,234.56'));
$new = rows($db, "SELECT `amountPence` FROM `tblVenueInvoices` WHERE `venueID` = ? AND `invoiceRef` = 'MONEY-NEW'", 'i', [$venue]);
check('C2 a new invoice of "1,234.56" (thousands comma) is stored once, as 123456', count($new) === 1 && (int) $new[0]['amountPence'] === 123456, json_encode($new));
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
    str_contains($page, 'That is not an amount') === true && $snapshot() === $before);
$before = $snapshot();
$page   = $flashAfter($http('POST', ...$posts['an EDITED agreement\'s rate (venues/agreement-save)']('12,50')));
check('D2 an agreement rate written with a decimal comma ("12,50") is refused with the reason, where the old code CLEARED the stored rate without a word',
    str_contains($page, BAD_COMMAS) === true && $snapshot() === $before);

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
$touched = ['org.currency', 'giving.enabled', 'ai_assist.enabled', 'ai_assist.monthCapPence', 'ai_assist.provider', 'ai_assist.userDailyCap', 'ai_assist.audience',
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
        foreach (rows($db, "SELECT `categoryID` FROM `tblGivingCategory` WHERE `siteID` = ? AND `name` = 'Money selftest category'", 'i', [$site]) as $r) {
            run($db, 'DELETE FROM `tblGivingEntry` WHERE `categoryID` = ?', 'i', [(int) $r['categoryID']]);
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
$project('1,500.00');
check('E1b a project target of "1,500.00" is stored as 150000', array_map('intval', array_column($projects(), 'targetAmountPence')) === [150000], json_encode($projects()));

$gifts = static fn (): array => rows($db, 'SELECT `amountPence` FROM `tblGivingEntry` WHERE `categoryID` = ?', 'i', [$category]);
$gift  = static fn (string $amount): array => $http('POST', '/giving/entry-save', ['csrf_token' => $freshToken('/giving/manage'),
    'donorName' => '', 'categoryID' => (string) $category, 'amount' => $amount, 'donatedAt' => date('Y-m-d'), 'method' => 'cash']);
$page = $flashAfter($gift('184467440737095557.12'));
check('E2 a gift of "184467440737095557.12" is refused as too large (in pounds: £21,474,836.47), and no gift is stored — the old code stored 40.96',
    str_contains($page, 'Amount: That amount is too large: the most that can be stored here is £21,474,836.47.') === true && $gifts() === [], json_encode($gifts()));
$gift('£12.50');
check('E2b a gift typed with the organisation\'s own symbol, "£12.50", is stored as 1250', array_map('intval', array_column($gifts(), 'amountPence')) === [1250], json_encode($gifts()));

$campaigns = static fn (): array => rows($db, "SELECT `goalAmountPence` FROM `tblPledgeCampaigns` WHERE `siteID` = ? AND `name` = 'Money selftest fund'", 'i', [$site]);
$campaign  = static fn (string $amount): array => $http('POST', '/giving/campaign-save', ['csrf_token' => $freshToken('/giving/campaigns'),
    'campaignID' => '0', 'name' => 'Money selftest fund', 'goalAmount' => $amount, 'startDate' => date('Y-m-d'), 'isActive' => '1']);
$page = $flashAfter($campaign('-1e30'));
check('E3 a campaign goal of "-1e30" is refused with the power-of-ten sentence, and no campaign is made',
    str_contains($page, 'Goal amount: ' . POWER_OF_TEN) === true && $campaigns() === [], json_encode($campaigns()));
$page = $flashAfter($campaign('12,50'));
check('E3b a campaign goal with a decimal comma, "12,50", is refused with the comma sentence — the old code made a goal of 1,250.00',
    str_contains($page, 'Goal amount: ' . BAD_COMMAS) === true && $campaigns() === [], json_encode($campaigns()));
$campaign('5,000');
check('E3c a campaign goal of "5,000" is stored as 500000', array_map('intval', array_column($campaigns(), 'goalAmountPence')) === [500000], json_encode($campaigns()));

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

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
