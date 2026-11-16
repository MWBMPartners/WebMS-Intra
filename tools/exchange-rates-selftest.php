<?php
// Path: tools/exchange-rates-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test for exchange rates 💱 (#993, package A; D-173, D-192)
 * -----------------------------------------------------------------------------
 * Portal\Core\ExchangeRates fetches the European Central Bank's daily file,
 * keeps every rate as text, chooses a rate (fetched, then typed) and converts
 * with the database's exact arithmetic. A fault in any of that is quiet: the
 * figure just comes out a little wrong, or a limit that should bite does not.
 * So every behaviour is exercised here and each check was proved able to go
 * red by planting the fault it names (the list is in the commit message).
 *
 * WHAT IT RUNS
 *   A. Reading the bank's file, no database: the real file committed in
 *      tools/fixtures, a rate with ten decimals surviving digit for digit,
 *      and every shape that must be refused (comma, minus, zero, exponent,
 *      lower-case code, impossible date, no rate lines, not XML, a DOCTYPE).
 *   B. Freshness, with the dates and the answers written out by hand.
 *   C. Choosing a rate: fetched beats typed, a stale fetched rate is ignored,
 *      the installation's check ignores an organisation's typed rate, a pair
 *      is never silently swapped.
 *   D. Converting, with the expected figures worked out outside this code.
 *   E. Fetching from a small stub server this script starts: what is stored,
 *      an older file never replacing a newer day, a missing currency keeping
 *      its old row, a 500, a reply over the size cap, a refused address, the
 *      switch, and when a fetch is due.
 *   F. The page over real HTTP: who may do what, and that a refusal is recorded.
 *   G. The scheduled job: the rates step's own numbers, and that a fault in
 *      it cannot change the mail step's answer.
 *   H. Nothing under web/ reaches past the class's front door.
 *
 * WHAT IT CANNOT PROVE
 *   - Nothing here reaches the real bank. The stub serves the real file, saved.
 *     Whether www.ecb.europa.eu still publishes that shape is checked only by
 *     whoever last fetched it (the fixture is a real reply from 16 November 2026).
 *   - The fetch inside the scheduled job is not driven over HTTP: the server
 *     process cannot take this test's address override, and it would reach the
 *     real internet. Part E drives the same method in this process and part G
 *     drives the job with the switch off and with its own records broken.
 *   - The page's script-free forms are read as text; no browser is driven.
 *
 * NEEDS: a database named selftest_* with web/_sql/full_schema.sql loaded
 * (SELFTEST_DB_NAME, and SELFTEST_DB_HOST/PORT/USER/PASS), curl and proc_open.
 * It REFUSES (exit 2) rather than skipping when something is missing.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/993
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** The made-up global administrator. Nothing else in tools/ uses 9931xx. */
const ER_ADMIN = 993101;
/** An administrator of the test's organisation ONLY. */
const ER_ORGADMIN = 993102;
/** An ordinary member. */
const ER_MEMBER = 993103;

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'stub' => null, 'child' => null];

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

use Portal\Core\ExchangeRates;
use Portal\Core\SafeFetch;

$fixturePath = $repo . $sep . 'tools' . $sep . 'fixtures' . $sep . 'ecb-eurofxref-2026-11-13.xml';
$fixture     = is_file($fixturePath) === true ? (string) file_get_contents($fixturePath) : '';
if ($fixture === '') {
    refuse('the committed bank file tools/fixtures/ecb-eurofxref-2026-11-13.xml is missing');
}
if (function_exists('simplexml_load_string') === false) {
    refuse('this PHP has no XML reader (simplexml), which the class needs and the portal reports as "no-xml"');
}

// =============================================================================
// A. Reading the bank's file
// =============================================================================
head('Section A — reading the European Central Bank\'s file (no database)');
$parsed = ExchangeRates::parseEcb($fixture);
check('A1 the real file gives its date and 29 rates',
    is_array($parsed) === true && $parsed['date'] === '2026-11-13' && count($parsed['perEuro']) === 29,
    is_array($parsed) === true ? $parsed['date'] . ' / ' . count($parsed['perEuro']) : (string) $parsed);
check('A2 a rate is TEXT exactly as written, trailing zero included: GBP 0.83559, HUF 365.60, IDR 20163.71',
    is_array($parsed) === true && $parsed['perEuro']['GBP'] === '0.83559' && $parsed['perEuro']['HUF'] === '365.60' && $parsed['perEuro']['IDR'] === '20163.71');
check('A3 every rate in the real file is a string (a float anywhere would be the fault)',
    is_array($parsed) === true && array_filter($parsed['perEuro'], static fn ($r): bool => is_string($r) === false) === []);

/** A one-line file in the bank's shape, with one rate line. */
$oneRate = static fn (string $line, string $day = '2026-11-13'): string =>
    "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<gesmes:Envelope xmlns:gesmes=\"http://www.gesmes.org/xml/2002-08-01\" "
    . "xmlns=\"http://www.ecb.int/vocabulary/2002-08-01/eurofxref\"><Cube><Cube time='{$day}'>{$line}</Cube></Cube></gesmes:Envelope>";
$long = ExchangeRates::parseEcb($oneRate("<Cube currency='USD' rate='12345678.1234567891'/>"));
check('A4 a rate with eight figures and ten decimals survives digit for digit',
    is_array($long) === true && ($long['perEuro']['USD'] ?? '') === '12345678.1234567891', is_array($long) === true ? json_encode($long) : (string) $long);
foreach ([
    'a comma'            => "1,1052",
    'a minus sign'       => '-1',
    'all zeros'          => '0.0',
    'an exponent'        => '1e3',
    'a plus sign'        => '+1.5',
    'nine whole figures' => '123456789.5',
    'eleven decimals'    => '1.12345678901',
    'empty'              => '',
] as $label => $bad) {
    $p = ExchangeRates::parseEcb($oneRate("<Cube currency='USD' rate='{$bad}'/>"));
    check("A5 {$label} is refused (and with no other good line, so is the file)", $p === 'unreadable', is_array($p) === true ? json_encode($p) : (string) $p);
}
$mixed = ExchangeRates::parseEcb($oneRate("<Cube currency='usd' rate='1.1'/><Cube currency='GBP' rate='0.8'/><Cube currency='JPY' rate='1,5'/>"));
check('A6 a lower-case code and a bad rate are skipped; the good line in the same file is kept',
    is_array($mixed) === true && array_keys($mixed['perEuro']) === ['GBP'], is_array($mixed) === true ? json_encode($mixed) : (string) $mixed);
check('A6b a currency written twice in one file refuses the whole file (a duplicate is a malformed file, not a choice of the last)',
    ExchangeRates::parseEcb($oneRate("<Cube currency='USD' rate='1.1'/><Cube currency='GBP' rate='0.8'/><Cube currency='USD' rate='9.9'/>")) === 'unreadable');
check('A7 an impossible date (2026-02-30) refuses the whole file',
    ExchangeRates::parseEcb($oneRate("<Cube currency='USD' rate='1.1'/>", '2026-02-30')) === 'unreadable');
check('A8 a date that is not a date refuses the whole file',
    ExchangeRates::parseEcb($oneRate("<Cube currency='USD' rate='1.1'/>", 'yesterday')) === 'unreadable');
check('A9 a file with no rate lines is refused',
    ExchangeRates::parseEcb($oneRate('')) === 'unreadable');
check('A10 something that is not XML is refused, and so is an empty reply',
    ExchangeRates::parseEcb('this is not xml') === 'unreadable' && ExchangeRates::parseEcb('') === 'unreadable');
check('A11 rates written in some OTHER namespace are not read as the bank\'s',
    ExchangeRates::parseEcb(str_replace('http://www.ecb.int/vocabulary/2002-08-01/eurofxref', 'http://example.invalid/ns', $fixture)) === 'unreadable');
$xxe = "<?xml version=\"1.0\"?>\n<!DOCTYPE x [<!ENTITY e SYSTEM \"http://rates.test/entity\">]>\n" . substr($fixture, (int) strpos($fixture, '<gesmes:Envelope'));
check('A12 a file with a DOCTYPE and an external entity is refused outright', ExchangeRates::parseEcb($xxe) === 'unreadable');

// =============================================================================
// B. Freshness, written out by hand
// =============================================================================
head('Section B — when a fetched rate is still usable (pinned by hand, never derived from the constant)');
check('B1 today is fresh', ExchangeRates::isFresh('2026-11-16', '2026-11-16') === true);
check('B2 seven days old is fresh (the bank\'s longest gap, Easter, is about five)', ExchangeRates::isFresh('2026-11-09', '2026-11-16') === true);
check('B3 eight days old is NOT fresh', ExchangeRates::isFresh('2026-11-08', '2026-11-16') === false);
check('B4 a day ahead is fresh (a time zone ahead of UTC); two days ahead is not (a clock that far out cannot be trusted)',
    ExchangeRates::isFresh('2026-11-17', '2026-11-16') === true && ExchangeRates::isFresh('2026-11-18', '2026-11-16') === false);
check('B5 it works across a month and a year end, and a bad date is never fresh',
    ExchangeRates::isFresh('2026-12-30', '2027-01-03') === true && ExchangeRates::isFresh('2026-12-25', '2027-01-03') === false
    && ExchangeRates::isFresh('not-a-date', '2026-11-16') === false);
check('B6 the constant is what these pins say (7 days), so the pins and the code cannot drift apart unseen',
    ExchangeRates::FRESH_DAYS === 7);

// ── What it needs from here on ──────────────────────────────────────────────
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
foreach (['tblExchangeRate', 'tblExchangeRateFetch', 'tblExchangeRateEntered'] as $t) {
    if (rows($db, 'SELECT 1 FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?', 's', [$t]) === []) {
        refuse("the table {$t} is missing — load web/_sql/full_schema.sql from this branch");
    }
}

/** Empty the three tables and put the fetch row back as a fresh install has it. */
$wipe = static function () use ($db): void {
    run($db, 'DELETE FROM `tblExchangeRate`');
    run($db, 'DELETE FROM `tblExchangeRateEntered`');
    run($db, "INSERT INTO `tblExchangeRateFetch` (`fetchID`, `lastAttemptUtc`, `lastSuccessUtc`, `lastProblem`) VALUES (1, NULL, NULL, '') "
        . "ON DUPLICATE KEY UPDATE `lastAttemptUtc` = NULL, `lastSuccessUtc` = NULL, `lastProblem` = ''");
};
/** Put one fetched rate in directly. */
$putRate = static function (string $code, string $rate, string $day) use ($db): void {
    run($db, 'INSERT INTO `tblExchangeRate` (`rateCurrency`, `perEuro`, `rateDate`, `fetchedAtUtc`) VALUES (?, ?, ?, UTC_TIMESTAMP()) '
        . 'ON DUPLICATE KEY UPDATE `perEuro` = VALUES(`perEuro`), `rateDate` = VALUES(`rateDate`)', 'sss', [$code, $rate, $day]);
};
/** Put one typed rate in directly (null = the installation's). */
$putTyped = static function (?int $site, string $from, string $to, string $rate) use ($db): void {
    run($db, 'INSERT INTO `tblExchangeRateEntered` (`siteID`, `fromCurrency`, `toCurrency`, `rate`, `asOf`, `enteredAtUtc`) VALUES (?, ?, ?, ?, UTC_DATE(), UTC_TIMESTAMP()) '
        . 'ON DUPLICATE KEY UPDATE `rate` = VALUES(`rate`)', 'isss', [$site, $from, $to, $rate]);
};
/** A rate as a plain number: the database gives DECIMAL(20,10), so '0.8355900000'. */
$plain = static fn (string $r): string => str_contains($r, '.') === true ? rtrim(rtrim($r, '0'), '.') : $r;

$today = gmdate('Y-m-d');
$old   = gmdate('Y-m-d', time() - 30 * 86400);
$wipe();

// =============================================================================
// C. Choosing a rate
// =============================================================================
head('Section C — which rate is used');
$SITE_A = 993201;   // made-up organisation numbers: only rows, no organisation exists
$SITE_B = 993202;

$r = ExchangeRates::rate($db, 'GBP', 'GBP', null);
check('C1 the same currency is exactly 1, with no rate looked up', $r !== null && $r['kind'] === 'same' && $r['a'] === '1' && $r['b'] === '1' && $r['source'] === 'same');
check('C2 with nothing held, a pair has NO rate (null: the caller refuses)', ExchangeRates::rate($db, 'USD', 'GBP', null) === null && ExchangeRates::rate($db, 'USD', 'GBP', $SITE_A) === null);

$putRate('USD', '1.1052', $today);
$putRate('GBP', '0.83559', $today);
$r = ExchangeRates::rate($db, 'USD', 'GBP', null);
check('C3 two fresh fetched rates: USD to GBP is perEuro(GBP) over perEuro(USD), "to" on top (not swapped)',
    $r !== null && $r['kind'] === 'cross' && $plain($r['a']) === '0.83559' && $plain($r['b']) === '1.1052' && $r['source'] === 'fetched',
    json_encode($r));
$r = ExchangeRates::rate($db, 'EUR', 'USD', null);
check('C4 the euro counts as 1: EUR to USD is 1.1052 over 1', $r !== null && $plain($r['a']) === '1.1052' && $r['b'] === '1' && $r['source'] === 'fetched', json_encode($r));
$r = ExchangeRates::rate($db, 'USD', 'EUR', null);
check('C5 ...and USD to EUR is 1 over 1.1052', $r !== null && $r['a'] === '1' && $plain($r['b']) === '1.1052', json_encode($r));

$putTyped($SITE_A, 'USD', 'GBP', '0.5');
$putTyped(null, 'USD', 'GBP', '0.6');
$r = ExchangeRates::rate($db, 'USD', 'GBP', $SITE_A);
check('C6 a fresh FETCHED rate beats a typed one, for an organisation and for the installation',
    $r !== null && $r['source'] === 'fetched' && (ExchangeRates::rate($db, 'USD', 'GBP', null)['source'] ?? '') === 'fetched');

// The bank's rates go stale: now the typed ones are used.
$putRate('USD', '1.1052', $old);
$r = ExchangeRates::rate($db, 'USD', 'GBP', $SITE_A);
check('C7 a STALE fetched rate is not used: the organisation\'s own typed rate is, for its own limit',
    $r !== null && $r['source'] === 'entered-organisation' && $r['kind'] === 'direct' && $plain($r['a']) === '0.5', json_encode($r));
$r = ExchangeRates::rate($db, 'USD', 'GBP', null);
check('C8 ...and for the INSTALLATION\'s limit the organisation\'s typed rate is NEVER used: the installation\'s own is',
    $r !== null && $r['source'] === 'entered-installation' && $plain($r['a']) === '0.6', json_encode($r));
$r = ExchangeRates::rate($db, 'USD', 'GBP', $SITE_B);
check('C9 an organisation with no typed rate of its own falls back to the installation\'s (and never to another organisation\'s)',
    $r !== null && $r['source'] === 'entered-installation' && $plain($r['a']) === '0.6', json_encode($r));
run($db, 'DELETE FROM `tblExchangeRateEntered` WHERE `siteID` IS NULL');
check('C10 with only ONE organisation\'s typed rate left, the installation\'s check has NO rate at all (an organisation cannot supply it)',
    ExchangeRates::rate($db, 'USD', 'GBP', null) === null && (ExchangeRates::rate($db, 'USD', 'GBP', $SITE_A)['source'] ?? '') === 'entered-organisation'
    && ExchangeRates::rate($db, 'USD', 'GBP', $SITE_B) === null);

$wipe();
$putTyped($SITE_A, 'GBP', 'NGN', '2000');
$r = ExchangeRates::rate($db, 'GBP', 'NGN', $SITE_A);
check('C11 a typed rate asked the way it was typed is used as it is, not inverted: 1 GBP = 2000 NGN',
    $r !== null && $r['kind'] === 'direct' && $plain($r['a']) === '2000', json_encode($r));
$r = ExchangeRates::rate($db, 'NGN', 'GBP', $SITE_A);
check('C12 asked the other way round, it is turned over (a is 1, b is 2000), never used as if it were the same way',
    $r !== null && $r['kind'] === 'cross' && $r['a'] === '1' && $plain($r['b']) === '2000', json_encode($r));
$putTyped($SITE_A, 'NGN', 'GBP', '0.001');
$r = ExchangeRates::rate($db, 'NGN', 'GBP', $SITE_A);
check('C13 when both ways are typed, the way asked wins', $r !== null && $r['kind'] === 'direct' && $plain($r['a']) === '0.001', json_encode($r));
check('C14 text that is not a currency code gets no rate', ExchangeRates::rate($db, 'gbp', 'USD', null) === null && ExchangeRates::rate($db, 'GBP', "US'", null) === null);
// One fetched rate is not enough: both sides must be there.
$wipe();
$putRate('USD', '1.1052', $today);
check('C15 a fetched rate for ONE side only gives no rate for the pair', ExchangeRates::rate($db, 'USD', 'NGN', null) === null);

// =============================================================================
// D. Converting
// =============================================================================
head('Section D — converting, with the figures worked out outside this code');
$cross = ['kind' => 'cross', 'a' => '0.83559', 'b' => '1.1052', 'source' => 'fetched', 'asOf' => '2026-11-13'];
check('D1 1,550 millionths of a dollar is 1 hundredth of a pound at the bank\'s rate (0.1172 of a hundredth, rounded UP)',
    ExchangeRates::toHundredths($db, 1550, $cross) === 1);
$idr = ['kind' => 'cross', 'a' => '20163.71', 'b' => '1.1052', 'source' => 'fetched', 'asOf' => '2026-11-13'];
check('D2 a million dollars in rupiah is exactly 1,824,439,920,377 hundredths (worked out with exact decimals, then rounded up)',
    ExchangeRates::toHundredths($db, 1000000000000, $idr) === 1824439920377, (string) ExchangeRates::toHundredths($db, 1000000000000, $idr));
$eurToUsd = ['kind' => 'cross', 'a' => '1.1052', 'b' => '1', 'source' => 'fetched', 'asOf' => '2026-11-13'];
check('D3 5,000,000 millionths of a euro is 553 hundredths of a dollar (552.6, rounded UP, never down to 552)',
    ExchangeRates::toHundredths($db, 5000000, $eurToUsd) === 553);
$direct = ['kind' => 'direct', 'a' => '2000', 'b' => '1', 'source' => 'entered-organisation', 'asOf' => $today];
check('D4 a typed rate of 2000: one pound (1,000,000 millionths) is 200,000 hundredths',
    ExchangeRates::toHundredths($db, 1000000, $direct) === 200000);
$point6 = ['kind' => 'direct', 'a' => '0.6', 'b' => '1', 'source' => 'entered-organisation', 'asOf' => $today];
check('D4b a typed rate with a fractional answer rounds UP: 1,550 millionths at 0.6 is 0.093 of a hundredth, so 1 (never 0)',
    ExchangeRates::toHundredths($db, 1550, $point6) === 1, (string) ExchangeRates::toHundredths($db, 1550, $point6));
check('D4c ...and just above a whole number rounds up to the next: 16,666 millionths at 0.6 is 0.99996 so 1, 16,667 is 1.00002 so 2',
    ExchangeRates::toHundredths($db, 16666, $point6) === 1 && ExchangeRates::toHundredths($db, 16667, $point6) === 2);
$inverse = ['kind' => 'cross', 'a' => '1', 'b' => '2000', 'source' => 'entered-organisation', 'asOf' => $today];
check('D5 turned over: one naira (1,000,000 millionths) is 0.05 of a hundredth, rounded UP to 1', ExchangeRates::toHundredths($db, 1000000, $inverse) === 1);
$same = ['kind' => 'same', 'a' => '1', 'b' => '1', 'source' => 'same', 'asOf' => $today];
check('D6 the same currency: 0 is 0, 1 is 1 (a ceiling), 10,000 is 1, 10,001 is 2',
    ExchangeRates::toHundredths($db, 0, $same) === 0 && ExchangeRates::toHundredths($db, 1, $same) === 1
    && ExchangeRates::toHundredths($db, 10000, $same) === 1 && ExchangeRates::toHundredths($db, 10001, $same) === 2);
check('D7 an answer too big to be a sane figure is null, which a caller treats as over every limit',
    ExchangeRates::toHundredths($db, 1000000000000000000, ['kind' => 'cross', 'a' => '9999999999', 'b' => '0.0000000001', 'source' => 'x', 'asOf' => $today]) === null);
check('D8 a negative amount, an unreadable rate and an unknown kind are null, never 0',
    ExchangeRates::toHundredths($db, -1, $cross) === null
    && ExchangeRates::toHundredths($db, 100, ['kind' => 'cross', 'a' => '1e3', 'b' => '1', 'source' => 'x', 'asOf' => $today]) === null
    && ExchangeRates::toHundredths($db, 100, ['kind' => 'cross', 'a' => '1', 'b' => '0', 'source' => 'x', 'asOf' => $today]) === null
    && ExchangeRates::toHundredths($db, 100, ['kind' => 'wat', 'a' => '1', 'b' => '1', 'source' => 'x', 'asOf' => $today]) === null);
check('D9 the rate written for a person: 1 USD = 0.756053 GBP, and "1 X = 1 X" for the same currency',
    ExchangeRates::crossRateText($db, 'USD', 'GBP', $cross) === '1 USD = 0.756053 GBP'
    && ExchangeRates::crossRateText($db, 'GBP', 'GBP', $same) === '1 GBP = 1 GBP'
    && ExchangeRates::crossRateText($db, 'GBP', 'NGN', $direct) === '1 GBP = 2000.000000 NGN',
    (string) ExchangeRates::crossRateText($db, 'USD', 'GBP', $cross));

// =============================================================================
// E. Fetching, from a stub server
// =============================================================================
head('Section E — fetching (a stub server serves the real file; nothing reaches the internet)');
$work = sys_get_temp_dir() . $sep . 'exrates-selftest-' . bin2hex(random_bytes(6));
$state['child'] = $work;
mkdir($work, 0700, true);
file_put_contents($work . $sep . 'exrates-selftest.marker', 'made by tools/exchange-rates-selftest.php');
file_put_contents($work . $sep . 'fixture.xml', $fixture);
file_put_contents($work . $sep . 'count.txt', '0');
file_put_contents($work . $sep . 'stub-router.php', <<<'STUB'
<?php
// Written by tools/exchange-rates-selftest.php for ONE run: the stand-in for the bank.
declare(strict_types=1);
$dir  = __DIR__;
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$real = (string) file_get_contents($dir . '/fixture.xml');
if ($uri === '/count') { echo (string) file_get_contents($dir . '/count.txt'); return true; }
file_put_contents($dir . '/count.txt', (string) ((int) file_get_contents($dir . '/count.txt') + 1));
header('Content-Type: text/xml');
switch ($uri) {
    case '/ok': echo $real; break;
    case '/older':  // the day BEFORE, with a different dollar rate
        echo str_replace(["time='2026-11-13'", "currency='USD' rate='1.1052'"], ["time='2026-11-12'", "currency='USD' rate='9.9999'"], $real); break;
    case '/newer':  // the day AFTER, with a different dollar rate
        echo str_replace(["time='2026-11-13'", "currency='USD' rate='1.1052'"], ["time='2026-11-14'", "currency='USD' rate='1.2'"], $real); break;
    case '/nousd':  // a file that has lost the dollar line, same day
        echo preg_replace("#<Cube currency='USD' rate='[^']*'/>#", '', $real); break;
    case '/tomorrow':  // dated tomorrow, UTC: the furthest ahead a file may be
        echo str_replace("time='2026-11-13'", "time='" . gmdate('Y-m-d', time() + 86400) . "'", $real); break;
    case '/dayafter':  // dated two days ahead: one day too far
        echo str_replace("time='2026-11-13'", "time='" . gmdate('Y-m-d', time() + 172800) . "'", $real); break;
    case '/future':  // a file dated in 2099
        echo str_replace("time='2026-11-13'", "time='2099-01-01'", $real); break;
    case '/500': http_response_code(500); echo 'boom'; break;
    case '/big': echo str_repeat('x', 200000); break;
    case '/junk': echo 'this is not xml'; break;
    case '/doctype':
        echo "<?xml version=\"1.0\"?>\n<!DOCTYPE x [<!ENTITY e SYSTEM \"http://rates.test/entity\">]>\n<a>&e;</a>"; break;
    default: http_response_code(404); echo 'no';
}
return true;
STUB);
$stubPort = (static function (): int {
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errNo, $errStr);
    if ($sock === false) {
        return 0;
    }
    $name = (string) stream_socket_get_name($sock, false);
    fclose($sock);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
})();
if ($stubPort < 1024) {
    refuse('could not find a free port for the stub server');
}
$state['stub'] = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $stubPort, $work . $sep . 'stub-router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $work . $sep . 'stub.log', 'a'], 2 => ['file', $work . $sep . 'stub.log', 'a']],
    $stubPipes,
    $work
);
register_shutdown_function(static function () use (&$state): void {
    foreach (['proc', 'stub'] as $which) {
        if (is_resource($state[$which]) === true) {
            proc_terminate($state[$which]);
            proc_close($state[$which]);
        }
    }
    $dir = (string) $state['child'];
    if ($dir !== '' && is_file($dir . DIRECTORY_SEPARATOR . 'exrates-selftest.marker') === true) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
});
$stubUp = false;
for ($i = 0; $i < 40; $i++) {
    $probe = @fsockopen('127.0.0.1', $stubPort, $errNo, $errStr, 0.25);
    if (is_resource($probe) === true) {
        fclose($probe);
        $stubUp = true;
        break;
    }
    usleep(150000);
}
if ($stubUp === false) {
    refuse('the stub server never answered');
}
// The name rates.test is at 127.0.0.1, port N: the only way SafeFetch will talk to this machine.
SafeFetch::$testResolverOverride = ['rates.test' => ['ips' => ['127.0.0.1'], 'port' => $stubPort], 'closed.test' => ['ips' => ['127.0.0.1'], 'port' => 9]];
$stubCount = static fn (): int => (int) file_get_contents($work . '/count.txt');
$url       = static fn (string $path): string => 'http://rates.test' . $path;

$wipe();
$before = $stubCount();
$problem = ExchangeRates::refreshFrom($db, $url('/ok'), false);
$held    = rows($db, 'SELECT `rateCurrency`, `perEuro`, `rateDate` FROM `tblExchangeRate` ORDER BY `rateCurrency`');
$byCode  = array_column($held, 'perEuro', 'rateCurrency');
$fetchRow = rows($db, 'SELECT * FROM `tblExchangeRateFetch` WHERE `fetchID` = 1')[0] ?? [];
check('E1 the real file is fetched through SafeFetch (one request), and 29 rates are stored', $problem === '' && $stubCount() === $before + 1 && count($held) === 29,
    $problem . ' / ' . count($held));
check('E2 GBP is stored as exactly 0.83559 and IDR as 20163.71 (no float ever touched them), dated 2026-11-13',
    $plain($byCode['GBP'] ?? '') === '0.83559' && $plain($byCode['IDR'] ?? '') === '20163.71' && ($held[0]['rateDate'] ?? '') === '2026-11-13');
check('E3 the fetch row records the success and no problem',
    ($fetchRow['lastSuccessUtc'] ?? null) !== null && ($fetchRow['lastAttemptUtc'] ?? null) !== null && ($fetchRow['lastProblem'] ?? 'x') === '');

// An older file never replaces a newer day.
$problem = ExchangeRates::refreshFrom($db, $url('/older'), false);
$usd = rows($db, "SELECT `perEuro`, `rateDate` FROM `tblExchangeRate` WHERE `rateCurrency` = 'USD'")[0];
check('E4 a file from the day BEFORE does not replace a newer rate (USD stays 1.1052 and 2026-11-13)',
    $problem === '' && $plain((string) $usd['perEuro']) === '1.1052' && $usd['rateDate'] === '2026-11-13', json_encode($usd));
$problem = ExchangeRates::refreshFrom($db, $url('/newer'), false);
$usd = rows($db, "SELECT `perEuro`, `rateDate` FROM `tblExchangeRate` WHERE `rateCurrency` = 'USD'")[0];
check('E5 a file from the day AFTER does replace it (USD 1.2, 2026-11-14), while a currency it also holds unchanged is kept',
    $problem === '' && $plain((string) $usd['perEuro']) === '1.2' && $usd['rateDate'] === '2026-11-14'
    && $plain((string) rows($db, "SELECT `perEuro` FROM `tblExchangeRate` WHERE `rateCurrency` = 'GBP'")[0]['perEuro']) === '0.83559');
// A file missing a currency leaves its old row.
$problem = ExchangeRates::refreshFrom($db, $url('/nousd'), false);
$usd = rows($db, "SELECT `perEuro`, `rateDate` FROM `tblExchangeRate` WHERE `rateCurrency` = 'USD'")[0];
check('E6 a file that has lost a currency leaves that currency\'s old row alone (USD still 1.2)', $problem === '' && $plain((string) $usd['perEuro']) === '1.2');

// A file dated far in the future is refused AND recorded as a problem, never stored.
$snapshotBefore = rows($db, 'SELECT * FROM `tblExchangeRate` ORDER BY `rateCurrency`');
$successBefore  = rows($db, 'SELECT `lastSuccessUtc` FROM `tblExchangeRateFetch` WHERE `fetchID` = 1')[0]['lastSuccessUtc'];
$got = ExchangeRates::refreshFrom($db, $url('/future'), false);
$row = rows($db, 'SELECT `lastProblem`, `lastSuccessUtc` FROM `tblExchangeRateFetch` WHERE `fetchID` = 1')[0];
check('E6b a file dated 2099-01-01 is REFUSED ("future-date"), recorded as the last problem, the last success is NOT moved, and every rate is untouched',
    $got === 'future-date' && $row['lastProblem'] === 'future-date' && $row['lastSuccessUtc'] === $successBefore
    && rows($db, 'SELECT * FROM `tblExchangeRate` ORDER BY `rateCurrency`') === $snapshotBefore, $got);
// A date that DID get in some other way (a hand-typed row) must not lock rates out for good.
run($db, "UPDATE `tblExchangeRate` SET `rateDate` = '2099-01-01', `perEuro` = 9.9 WHERE `rateCurrency` = 'USD'");
$got = ExchangeRates::refreshFrom($db, $url('/ok'), false);
$usd = rows($db, "SELECT `perEuro`, `rateDate` FROM `tblExchangeRate` WHERE `rateCurrency` = 'USD'")[0];
check('E6c a rate held with a date in 2099 is REPLACED by the next real file (USD back to 1.1052, 2026-11-13): a wrong date cannot lock rates out for good',
    $got === '' && $plain((string) $usd['perEuro']) === '1.1052' && $usd['rateDate'] === '2026-11-13', json_encode($usd));
/** Hold USD at one day with a marker rate, fetch the older real file, answer the rate that is held after. */
$heldAfterOlderFile = static function (string $day) use ($db, $url, $plain): string {
    run($db, "UPDATE `tblExchangeRate` SET `rateDate` = ?, `perEuro` = 7.5 WHERE `rateCurrency` = 'USD'", 's', [$day]);
    ExchangeRates::refreshFrom($db, $url('/ok'), false);
    $u = rows($db, "SELECT `perEuro` FROM `tblExchangeRate` WHERE `rateCurrency` = 'USD'")[0];
    run($db, "UPDATE `tblExchangeRate` SET `rateDate` = '2026-11-13', `perEuro` = 1.1052 WHERE `rateCurrency` = 'USD'");

    return $plain((string) $u['perEuro']);
};
$todayUtc    = gmdate('Y-m-d');
$tomorrowUtc = gmdate('Y-m-d', time() + 86400);
$dayAfterUtc = gmdate('Y-m-d', time() + 172800);
check('E6d a held date of TODAY is not treated as wrong: an older file does not replace it (USD stays 7.5)', $heldAfterOlderFile($todayUtc) === '7.5');
check('E6d2 a held date of exactly TOMORROW (UTC) is still not wrong: the older file does not replace it (the allowance is one day, and this is the edge)',
    $heldAfterOlderFile($tomorrowUtc) === '7.5');
check('E6d3 a held date TWO days ahead is wrong: the older file replaces it (USD back to 1.1052)', $heldAfterOlderFile($dayAfterUtc) === '1.1052');
$gotTomorrow = ExchangeRates::refreshFrom($db, $url('/tomorrow'), false);
check('E6d4 a FILE dated exactly tomorrow (UTC) is accepted (a time zone ahead of UTC is already there), and stored with that date',
    $gotTomorrow === '' && rows($db, "SELECT `rateDate` FROM `tblExchangeRate` WHERE `rateCurrency` = 'USD'")[0]['rateDate'] === $tomorrowUtc, $gotTomorrow);
$gotDayAfter = ExchangeRates::refreshFrom($db, $url('/dayafter'), false);
check('E6d5 a file dated two days ahead is REFUSED (future-date): one day too far', $gotDayAfter === 'future-date', $gotDayAfter);
// Back to the real file's day for what follows.
run($db, "UPDATE `tblExchangeRate` SET `rateDate` = '2026-11-13'");
run($db, "UPDATE `tblExchangeRate` SET `perEuro` = 1.1052 WHERE `rateCurrency` = 'USD'");
// status() with ONE hand-made future-dated row among good ones.
run($db, 'UPDATE `tblExchangeRate` SET `rateDate` = ?', 's', [$todayUtc]);
run($db, "UPDATE `tblExchangeRate` SET `rateDate` = '2099-01-01' WHERE `rateCurrency` = 'JPY'");
$st = ExchangeRates::status($db);
check('E6d6 one rate dated 2099 does not make every rate read "too old": the newest SANE day is shown, fresh, and the one future row is counted (not used)',
    is_array($st) === true && $st['rateDate'] === $todayUtc && $st['fresh'] === true && $st['futureRows'] === 1 && $st['count'] === 29, json_encode($st));
run($db, "UPDATE `tblExchangeRate` SET `rateDate` = '2026-11-13'");

// The net under the tests: the REAL address is never fetched from a selftest_ database.
$before = $stubCount();
$blocked = ExchangeRates::refresh($db);
check('E6e refresh() against a selftest_ database with no stub standing in for the bank refuses ("test-blocked") and makes no request',
    $blocked === 'test-blocked' && $stubCount() === $before && rows($db, 'SELECT `lastProblem` FROM `tblExchangeRateFetch` WHERE `fetchID` = 1')[0]['lastProblem'] === 'test-blocked', $blocked);
SafeFetch::$testResolverOverride['www.ecb.europa.eu'] = ['ips' => ['127.0.0.1'], 'port' => 9];
$notBlocked = ExchangeRates::refresh($db);
unset(SafeFetch::$testResolverOverride['www.ecb.europa.eu']);
check('E6f ...but when the test points the bank\'s name at its own stand-in, the guard steps aside (the stand-in here is a closed port, so the answer is "unreachable", not "test-blocked")',
    $notBlocked === 'unreachable', $notBlocked);

// Failures leave every rate untouched and say why in one fixed word.
$snapshot = rows($db, 'SELECT * FROM `tblExchangeRate` ORDER BY `rateCurrency`');
$cases = ['/500' => 'http', '/big' => 'capped', '/junk' => 'unreadable', '/doctype' => 'unreadable'];
foreach ($cases as $path => $word) {
    $got      = ExchangeRates::refreshFrom($db, $url($path), false);
    $row      = rows($db, 'SELECT `lastProblem`, `lastSuccessUtc` FROM `tblExchangeRateFetch` WHERE `fetchID` = 1')[0];
    check("E7 {$path}: the word is '{$word}', stored as the last problem, and every rate is untouched",
        $got === $word && $row['lastProblem'] === $word && rows($db, 'SELECT * FROM `tblExchangeRate` ORDER BY `rateCurrency`') === $snapshot,
        "got '{$got}', stored '{$row['lastProblem']}'");
}
$afterSuccess = rows($db, 'SELECT `lastSuccessUtc` FROM `tblExchangeRateFetch` WHERE `fetchID` = 1')[0]['lastSuccessUtc'];
check('E8 a failure does not touch the last-success moment (it still records the earlier success)', $afterSuccess !== null);
$got = ExchangeRates::refreshFrom($db, 'http://127.0.0.1:' . $stubPort . '/ok', false);
check('E9 an address that points at this machine is REFUSED by SafeFetch (word "refused"), not fetched', $got === 'refused', $got);
$got = ExchangeRates::refreshFrom($db, 'http://closed.test/ok', false);
check('E10 a place that cannot be reached is "unreachable"', $got === 'unreachable', $got);
$got = ExchangeRates::refreshFrom($db, 'http://rates.test/ok', true);
check('E11 the real fetch path is https only: plain http is refused when httpsOnly is true, and the rates are untouched',
    $got !== '' && rows($db, 'SELECT * FROM `tblExchangeRate` ORDER BY `rateCurrency`') === $snapshot, $got);
check('E12 the page words for each problem word are the fixed ones, and an unknown word is still words (not blank)',
    ExchangeRates::problemWords('http') !== '' && ExchangeRates::problemWords('capped') !== '' && ExchangeRates::problemWords('zzz') === 'an unknown problem'
    && ExchangeRates::problemWords('') === '');

// The switch and when a fetch is due.
$setSwitch = static function (?string $value) use ($db): void {
    run($db, "DELETE FROM `tblSettings` WHERE `settingKey` = 'exchangeRates.fetch' AND `siteID` IS NULL");
    if ($value !== null) {
        run($db, "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, 'exchangeRates.fetch', ?, '1', 0)", 's', [$value]);
    }
};
$settingBefore = rows($db, "SELECT * FROM `tblSettings` WHERE `settingKey` = 'exchangeRates.fetch'");
register_shutdown_function(static function () use ($db, $settingBefore): void {
    try {
        run($db, "DELETE FROM `tblSettings` WHERE `settingKey` = 'exchangeRates.fetch'");
        foreach ($settingBefore as $row) {
            run($db, 'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, ?, ?)',
                'isssi', [$row['siteID'], $row['settingKey'], $row['settingValue'], $row['defaultValue'], (int) $row['isSensitive']]);
        }
    } catch (Throwable $e) {
        echo "  ! the clean-up of the switch could not finish ({$e->getMessage()}).\n";
    }
});
$setFetchTimes = static function (?string $successAgo, ?string $attemptAgo) use ($db): void {
    // e.g. '19 HOUR'. The database's own clock, as the class reads it.
    $s = $successAgo === null ? 'NULL' : "UTC_TIMESTAMP() - INTERVAL {$successAgo}";
    $a = $attemptAgo === null ? 'NULL' : "UTC_TIMESTAMP() - INTERVAL {$attemptAgo}";
    $db->query("UPDATE `tblExchangeRateFetch` SET `lastSuccessUtc` = {$s}, `lastAttemptUtc` = {$a}, `lastProblem` = '' WHERE `fetchID` = 1");
};

$setSwitch('0');
$setFetchTimes(null, null);
$before = $stubCount();
$out    = ExchangeRates::refreshIfDue($db, $url('/ok'));
check('E13 with the switch OFF no request is made at all (the stub counter does not move) and the step says skipped',
    $out === ['ran' => 0, 'stored' => 0, 'error' => 0, 'skipped' => 1] && $stubCount() === $before, json_encode($out));
$setSwitch(null);
$out = ExchangeRates::refreshIfDue($db, $url('/ok'));
check('E14 with NO switch row (an installation whose upgrade has not run) it is off too: nothing is fetched', $out['skipped'] === 1 && $stubCount() === $before, json_encode($out));
$setSwitch('1');
$setFetchTimes('19 HOUR', '19 HOUR');
$out = ExchangeRates::refreshIfDue($db, $url('/ok'));
check('E15 switched on, 19 hours after a success: NOT due, no request', $out['skipped'] === 1 && $out['ran'] === 0 && $stubCount() === $before, json_encode($out));
$setFetchTimes('21 HOUR', '21 HOUR');
$putRate('ZZZ', '1.5', $today);   // a row the file does not hold: the table is 30 rows, the file 29
$out = ExchangeRates::refreshIfDue($db, $url('/ok'));
run($db, "DELETE FROM `tblExchangeRate` WHERE `rateCurrency` = 'ZZZ'");
check('E16 21 hours after a success: due, exactly one request, and 29 reported: the number in the FILE, not the size of the table (30 with a stray row)',
    $out['ran'] === 1 && $out['error'] === 0 && $out['stored'] === 29 && $stubCount() === $before + 1, json_encode($out));
$setFetchTimes(null, null);
$out = ExchangeRates::refreshIfDue($db, $url('/ok'));
check('E17 never fetched before: due', $out['ran'] === 1 && $out['error'] === 0, json_encode($out));
$before = $stubCount();
$setFetchTimes('25 HOUR', '30 MINUTE');
$out = ExchangeRates::refreshIfDue($db, $url('/ok'));
check('E18 a failure 30 minutes ago (the last success 25 hours ago): NOT retried yet, no request', $out['skipped'] === 1 && $stubCount() === $before, json_encode($out));
$setFetchTimes('25 HOUR', '2 HOUR');
$out = ExchangeRates::refreshIfDue($db, $url('/500'));
check('E19 a failure two hours ago: retried, and a failing fetch reports error 1 and stores nothing',
    $out['ran'] === 1 && $out['error'] === 1 && $out['stored'] === 0 && $stubCount() === $before + 1, json_encode($out));
$setFetchTimes('25 HOUR', '30 MINUTE');
$run = ExchangeRates::refreshIfDue($db, $url('/ok'));
check('E20 the hour is counted from the LAST ATTEMPT, so one that has just failed is not hammered', $run['skipped'] === 1);
// The page's status. The days are set by hand, relative to today, so this holds on any day.
run($db, 'UPDATE `tblExchangeRate` SET `rateDate` = ?', 's', [$old]);
$status = ExchangeRates::status($db);
check('E21 the page\'s status reads the facts back: a count of 29, the bank\'s date, and "not fresh" for a list 30 days old',
    is_array($status) === true && $status['count'] === 29 && $status['fresh'] === false && $status['rateDate'] === $old, json_encode($status));
run($db, 'UPDATE `tblExchangeRate` SET `rateDate` = ?', 's', [$today]);
$status = ExchangeRates::status($db);
check('E21b ...and "fresh" for a list dated today', is_array($status) === true && $status['fresh'] === true && $status['rateDate'] === $today, json_encode($status));

// Typed rates: judged before they are stored.
$wipe();
$refusals = [
    'an unknown currency'      => ['XXQ', 'GBP', '1.5', $today],
    'the same currency twice'  => ['GBP', 'GBP', '1.5', $today],
    'a comma in the rate'      => ['USD', 'GBP', '1,5', $today],
    'an exponent'              => ['USD', 'GBP', '1e3', $today],
    'zero'                     => ['USD', 'GBP', '0', $today],
    'zero with decimals'       => ['USD', 'GBP', '0.0000', $today],
    'a minus sign'             => ['USD', 'GBP', '-1', $today],
    'nine whole figures'       => ['USD', 'GBP', '123456789', $today],
    'an impossible date'       => ['USD', 'GBP', '1.5', '2026-02-30'],
    'a date in 2099'           => ['USD', 'GBP', '1.5', '2099-01-01'],
    'a date that is not one'   => ['USD', 'GBP', '1.5', 'today'],
];
foreach ($refusals as $label => [$f, $t, $rate, $day]) {
    $said = ExchangeRates::saveEntered($db, $SITE_A, $f, $t, $rate, $day);
    check("E22 typing {$label} is refused with words and stores nothing", $said !== '' && (int) rows($db, 'SELECT COUNT(*) AS `n` FROM `tblExchangeRateEntered`')[0]['n'] === 0, $said);
}
check('E23 a good rate is saved, lower-case codes are tidied, and typing the pair again REPLACES it (one row, not two)',
    ExchangeRates::saveEntered($db, $SITE_A, ' usd ', 'gbp', '0.7560', $today) === ''
    && ExchangeRates::saveEntered($db, $SITE_A, 'USD', 'GBP', '0.7561', $today) === ''
    && count(ExchangeRates::enteredFor($db, $SITE_A) ?? []) === 1 && $plain(ExchangeRates::enteredFor($db, $SITE_A)[0]['rate']) === '0.7561');
check('E24 the installation\'s row for the same pair is a SEPARATE row (the key binds installation rows too, #487), and the two levels do not see each other',
    ExchangeRates::saveEntered($db, null, 'USD', 'GBP', '0.9', $today) === ''
    && ExchangeRates::saveEntered($db, null, 'USD', 'GBP', '0.95', $today) === ''
    && count(ExchangeRates::enteredFor($db, null) ?? []) === 1 && count(ExchangeRates::enteredFor($db, $SITE_A) ?? []) === 1
    && count(ExchangeRates::enteredFor($db, $SITE_B) ?? []) === 0);
$mine = ExchangeRates::enteredFor($db, $SITE_A)[0]['enteredID'];
check('E25 an organisation cannot remove the installation\'s rate by its number, nor another organisation\'s, but can remove its own',
    ExchangeRates::removeEntered($db, $SITE_B, $mine) === 0 && ExchangeRates::removeEntered($db, null, $mine) === 0
    && ExchangeRates::removeEntered($db, $SITE_A, $mine) === 1 && ExchangeRates::enteredFor($db, $SITE_A) === []);
check('E26 only exchangeRates. keys are locked in the general editor, in any capitals',
    ExchangeRates::lockedInGeneralEditor('exchangeRates.fetch') === true && ExchangeRates::lockedInGeneralEditor('EXCHANGERATES.fetch') === true
    && ExchangeRates::lockedInGeneralEditor('org.currency') === false && ExchangeRates::lockedInGeneralEditor('exchangeRatesX') === false);
// A broken table is "no rate" and "could not read", never a rate or an empty list.
run($db, 'RENAME TABLE `tblExchangeRate` TO `zzExchangeRateAway`');
$brokenRate   = ExchangeRates::rate($db, 'USD', 'GBP', null);
$brokenStatus = ExchangeRates::status($db);
run($db, 'RENAME TABLE `zzExchangeRateAway` TO `tblExchangeRate`');
check('E27 with the rates table unreadable, no rate is given (null) and the status says it could not read: neither is "nothing" or a made-up rate',
    $brokenRate === null && $brokenStatus === null);
$wipe();

// =============================================================================
// F + G. The page and the job, over real HTTP
// =============================================================================
$child = sys_get_temp_dir() . $sep . 'exrates-web-' . bin2hex(random_bytes(6));
$state['child2'] = $child;
register_shutdown_function(static function () use (&$state): void {
    $dir = (string) ($state['child2'] ?? '');
    if ($dir !== '' && is_file($dir . DIRECTORY_SEPARATOR . 'exrates-web.marker') === true) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
});
mkdir($child, 0700, true);
file_put_contents($child . $sep . 'exrates-web.marker', 'made by tools/exchange-rates-selftest.php');
$copied = 0;
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/exchange-rates-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/exchange-rates-selftest.php for ONE run. Imitates web/public_html/.htaccess.
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

$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9993;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}
$probe = @fsockopen('127.0.0.1', $port, $errNo, $errText, 0.5);
if (is_resource($probe) === true) {
    fclose($probe);
    refuse("something already answers on port {$port}; choose another with SELFTEST_WEB_PORT");
}
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log',
     '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'max_execution_time=120', '-d', 'opcache.enable=0',
     '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server.log', 'a'], 2 => ['file', $child . $sep . 'server.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('could not start PHP\'s web server');
}

/** A client with its own cookie jar: one per person. */
$client = static function (string $jarName) use ($port, $child, $sep): Closure {
    $jar = $child . $sep . $jarName;

    return static function (string $method, string $path, array $post = [], array $headers = []) use ($port, $jar): array {
        $handle = curl_init('http://127.0.0.1:' . $port . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers,
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
$httpRoot   = $client('cookies-root.txt');
$httpOrg    = $client('cookies-org.txt');
$httpMember = $client('cookies-member.txt');
$httpJob    = $client('cookies-job.txt');
$answered = false;
for ($i = 0; $i < 80; $i++) {
    if ($httpRoot('GET', '/login')['status'] > 0) {
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

// ── People and settings this run makes, removed at the end ──────────────────
$people = [
    ER_ADMIN    => ['root', 'Selftest rates root', 'rates-root@selftest.invalid', 1, 1, 0],
    ER_ORGADMIN => ['org', 'Selftest rates organisation administrator', 'rates-org@selftest.invalid', 0, 0, 1],
    ER_MEMBER   => ['member', 'Selftest rates member', 'rates-member@selftest.invalid', 0, 0, 0],
];
$removePeople = static function () use ($db, $people): void {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_keys($people) as $id) {
        run($db, 'DELETE FROM `tblLocalAccounts` WHERE `userID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblUserSites` WHERE `userID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblActivityLogs` WHERE `userID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [$id]);
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
};
$touched = "'org.currency', 'maintenance.cronToken', 'exchangeRates.fetch'";
$settingsBefore = rows($db, "SELECT * FROM `tblSettings` WHERE `settingKey` IN ({$touched})");
register_shutdown_function(static function () use ($db, $removePeople, $settingsBefore, $touched, $wipe): void {
    try {
        $removePeople();
        $wipe();
        run($db, "DELETE FROM `tblSettings` WHERE `settingKey` IN ({$touched})");
        foreach ($settingsBefore as $row) {
            run($db, 'INSERT INTO `tblSettings` (`settingID`, `siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, ?, ?, ?)',
                'iisssi', [(int) $row['settingID'], $row['siteID'], $row['settingKey'], $row['settingValue'], $row['defaultValue'], (int) $row['isSensitive']]);
        }
        $leftover = rows($db, "SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'zzExchangeRateFetchAway'")[0]['n'] ?? 0;
        if ((int) $leftover > 0) {
            run($db, 'RENAME TABLE `zzExchangeRateFetchAway` TO `tblExchangeRateFetch`');
        }
    } catch (Throwable $e) {
        echo "  ! the clean-up could not finish ({$e->getMessage()}).\n";
    }
});
$removePeople();
$passwords = [];
foreach ($people as $id => [$login, $name, $mail, $isAdmin, $isRoot, $isSiteAdmin]) {
    $passwords[$id] = 'Er' . bin2hex(random_bytes(8)) . '!9x';
    run($db, 'INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, ?, 1, ?, ?, NOW())',
        'issii', [$id, $name, $mail, $isAdmin, $isRoot]);
    run($db, 'INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`) VALUES (?, ?, 1, ?)', 'iii', [$site, $id, $isSiteAdmin]);
    run($db, 'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)',
        'iss', [$id, 'selftest-rates-' . $login, password_hash($passwords[$id], PASSWORD_DEFAULT)]);
}
foreach ([[$httpRoot, ER_ADMIN, 'root'], [$httpOrg, ER_ORGADMIN, 'org'], [$httpMember, ER_MEMBER, 'member']] as [$who, $id, $login]) {
    $in = $who('POST', '/login', ['csrf_token' => $tokenOn($who('GET', '/login')['body']), 'username' => 'selftest-rates-' . $login, 'password' => $passwords[$id]]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse("signing in as the {$login} person did not answer a redirect (HTTP {$in['status']})");
    }
}
/** Count one person's activity records of one type. */
$logged = static fn (int $user, string $type): int => (int) (rows($db, 'SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` = ?', 'is', [$user, $type])[0]['n'] ?? 0);
$setting = static function (string $key, string $value) use ($db): void {
    run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
    run($db, "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, '', 0)", 'ss', [$key, $value]);
};
$switchValue = static fn (): ?string => (rows($db, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'exchangeRates.fetch' AND `siteID` IS NULL")[0]['settingValue'] ?? null);
$typedCount = static fn (): int => (int) rows($db, 'SELECT COUNT(*) AS `n` FROM `tblExchangeRateEntered`')[0]['n'];
$page = static fn (Closure $who): array => $who('GET', '/admin/exchange-rates');
$post = static function (Closure $who, array $fields) use ($tokenOn, $page): array {
    return $who('POST', '/admin/exchange-rates/save', ['csrf_token' => $tokenOn($page($who)['body'])] + $fields);
};

head('Section F — the page, over real HTTP');
$setting('org.currency', 'GBP');
$setting('exchangeRates.fetch', '1');
$wipe();
$memberView = $httpMember('GET', '/admin/exchange-rates');
$translationAsMember = $httpMember('GET', '/admin/translation');
check('F1 an ordinary member gets the same answer as any other admin page, and sees none of it',
    $memberView['status'] === $translationAsMember['status'] && $memberView['status'] >= 400 && str_contains($memberView['body'], 'The daily fetch') === false,
    'HTTP ' . $memberView['status'] . ' vs ' . $translationAsMember['status']);
$rootView = $page($httpRoot);
check('F2 a global administrator opens it and sees the switch (on), Fetch now, and the installation\'s own card',
    $rootView['status'] === 200 && str_contains($rootView['body'], 'data-rates-switch="on"') === true && str_contains($rootView['body'], 'Fetch now') === true
    && str_contains($rootView['body'], 'data-rates-level="installation"') === true && str_contains($rootView['body'], 'name="fetchEnabledShown"') === true,
    'HTTP ' . $rootView['status']);
$orgView = $page($httpOrg);
check('F3 an administrator of one organisation opens it and sees ITS OWN card, but no switch, no Fetch now, no installation card',
    $orgView['status'] === 200 && str_contains($orgView['body'], 'data-rates-level="organisation"') === true
    && str_contains($orgView['body'], 'Fetch now') === false && str_contains($orgView['body'], 'fetchEnabledShown') === false
    && str_contains($orgView['body'], 'data-rates-level="installation"') === false);
check('F4 with nothing fetched the page says "none yet" and, with a GBP limit and no rate, says plainly that spending with a company billing in USD would be refused',
    str_contains($rootView['body'], 'none yet') === true && str_contains($rootView['body'], 'would be refused') === true && str_contains($rootView['body'], 'data-rates-source="none"') === true);

// A global administrator types an installation rate.
$before = $logged(ER_ADMIN, 'ExchangeRateEntered');
$saved  = $post($httpRoot, ['action' => 'save_rate', 'scope' => 'installation', 'fromCurrency' => 'USD', 'toCurrency' => 'GBP', 'rate' => '0.6', 'asOf' => $today]);
$row    = rows($db, "SELECT `siteID`, `rate` FROM `tblExchangeRateEntered` WHERE `fromCurrency` = 'USD' AND `toCurrency` = 'GBP'");
check('F5 a global administrator saves an INSTALLATION rate: one row with no organisation, and a record of it',
    $saved['status'] === 302 && count($row) === 1 && $row[0]['siteID'] === null && $plain((string) $row[0]['rate']) === '0.6' && $logged(ER_ADMIN, 'ExchangeRateEntered') === $before + 1,
    'HTTP ' . $saved['status'] . ' ' . json_encode($row));
$afterPage = $page($httpRoot)['body'];
check('F6 the page now shows that typed rate as the one in force, from "typed for the installation"',
    str_contains($afterPage, '1 USD = 0.600000 GBP') === true && str_contains($afterPage, 'typed for the installation') === true);

// An organisation's administrator.
$before = $logged(ER_ORGADMIN, 'ExchangeRateEntered');
$saved  = $post($httpOrg, ['action' => 'save_rate', 'scope' => 'organisation', 'fromCurrency' => 'USD', 'toCurrency' => 'GBP', 'rate' => '0.5', 'asOf' => $today]);
$row    = rows($db, "SELECT `siteID`, `rate` FROM `tblExchangeRateEntered` WHERE `siteID` IS NOT NULL");
check('F7 an organisation\'s administrator saves a rate for their OWN organisation (and it is that organisation\'s number)',
    $saved['status'] === 302 && count($row) === 1 && (int) $row[0]['siteID'] === $site && $logged(ER_ORGADMIN, 'ExchangeRateEntered') === $before + 1);
$tryInstall = $post($httpOrg, ['action' => 'save_rate', 'scope' => 'installation', 'fromCurrency' => 'EUR', 'toCurrency' => 'GBP', 'rate' => '0.1', 'asOf' => $today]);
$before = $logged(ER_ORGADMIN, 'ExchangeRatesActionRefused');
$post($httpOrg, ['action' => 'save_rate', 'scope' => 'installation', 'fromCurrency' => 'EUR', 'toCurrency' => 'GBP', 'rate' => '0.1', 'asOf' => $today]);
check('F8 the same person is REFUSED an installation rate: no row is written, the refusal is recorded, and the page says why',
    (int) rows($db, "SELECT COUNT(*) AS `n` FROM `tblExchangeRateEntered` WHERE `fromCurrency` = 'EUR'")[0]['n'] === 0
    && $logged(ER_ORGADMIN, 'ExchangeRatesActionRefused') === $before + 1
    && str_contains($page($httpOrg)['body'], 'only a global administrator can do it') === true && $tryInstall['status'] === 302);

// The switch.
$before = $logged(ER_ORGADMIN, 'ExchangeRatesActionRefused');
$post($httpOrg, ['action' => 'fetch_switch', 'fetchEnabledShown' => '1']);
check('F9 an organisation\'s administrator cannot switch the fetch off: the setting is still 1, and the refusal is recorded',
    $switchValue() === '1' && $logged(ER_ORGADMIN, 'ExchangeRatesActionRefused') === $before + 1);
$before = $logged(ER_ADMIN, 'ExchangeRatesFetchSwitched');
$post($httpRoot, ['action' => 'fetch_switch', 'fetchEnabledShown' => '1']);
check('F10 a global administrator unticks it and presses Save: the setting becomes 0, installation-wide, and it is recorded',
    $switchValue() === '0' && $logged(ER_ADMIN, 'ExchangeRatesFetchSwitched') === $before + 1);
$post($httpRoot, ['action' => 'fetch_switch', 'fetchEnabled' => '1']);
check('F11 a post that never DREW the tick-box (no marker) changes nothing, even carrying fetchEnabled=1 (#677: absent is not off, present-without-marker is not on)',
    $switchValue() === '0');
$post($httpRoot, ['action' => 'fetch_switch', 'fetchEnabledShown' => '1', 'fetchEnabled' => '1']);
check('F12 ticking it and saving turns it on again', $switchValue() === '1');
check('F13 no organisation row for the switch was ever written (the fetch reads the installation\'s alone)',
    (int) rows($db, "SELECT COUNT(*) AS `n` FROM `tblSettings` WHERE `settingKey` = 'exchangeRates.fetch' AND `siteID` IS NOT NULL")[0]['n'] === 0);

// Fetch now.
$before  = $logged(ER_ORGADMIN, 'ExchangeRatesActionRefused');
$post($httpOrg, ['action' => 'fetch_now']);
check('F14 an organisation\'s administrator cannot press Fetch now: refused and recorded, and nothing was fetched',
    $logged(ER_ORGADMIN, 'ExchangeRatesActionRefused') === $before + 1 && rows($db, 'SELECT `lastAttemptUtc` FROM `tblExchangeRateFetch`')[0]['lastAttemptUtc'] === null);
$setting('exchangeRates.fetch', '0');
$beforeHand = $logged(ER_ADMIN, 'ExchangeRatesFetchedByHand');
$off = $post($httpRoot, ['action' => 'fetch_now']);
check('F15 with the switch OFF even a global administrator\'s Fetch now does not contact the bank (no attempt is recorded) and says why',
    rows($db, 'SELECT `lastAttemptUtc` FROM `tblExchangeRateFetch`')[0]['lastAttemptUtc'] === null && $logged(ER_ADMIN, 'ExchangeRatesFetchedByHand') === $beforeHand
    && str_contains($page($httpRoot)['body'], 'switched off, so the portal will not contact the European Central Bank') === true && $off['status'] === 302);
$setting('exchangeRates.fetch', '1');

// Figures refused with words.
$typedBefore = $typedCount();
$refusedBefore = $logged(ER_ADMIN, 'ExchangeRateRefused');
foreach (['0' => 'zero', '1e3' => 'an exponent', '1,5' => 'a comma'] as $bad => $label) {
    $post($httpRoot, ['action' => 'save_rate', 'scope' => 'installation', 'fromCurrency' => 'CHF', 'toCurrency' => 'GBP', 'rate' => (string) $bad, 'asOf' => $today]);
}
$post($httpRoot, ['action' => 'save_rate', 'scope' => 'installation', 'fromCurrency' => 'CHF', 'toCurrency' => 'GBP', 'rate' => '0.9', 'asOf' => '2099-01-01']);
check('F16 a rate of 0, 1e3 or 1,5, and a date in 2099, are all refused: nothing saved, each refusal recorded, and the page says what was wrong',
    $typedCount() === $typedBefore && $logged(ER_ADMIN, 'ExchangeRateRefused') === $refusedBefore + 4
    && str_contains($page($httpRoot)['body'], 'cannot be in the future') === true);
$post($httpRoot, ['action' => 'save_rate', 'scope' => ['x'], 'fromCurrency' => ['USD'], 'toCurrency' => 'GBP', 'rate' => ['1'], 'asOf' => $today]);
check('F17 a request that sends a LIST where text belongs is refused without an error (no 500)', $typedCount() === $typedBefore);

// Removing.
$instRow = (int) rows($db, 'SELECT `enteredID` FROM `tblExchangeRateEntered` WHERE `siteID` IS NULL LIMIT 1')[0]['enteredID'];
$post($httpOrg, ['action' => 'remove_rate', 'scope' => 'organisation', 'enteredID' => (string) $instRow]);
check('F18 an organisation\'s administrator cannot remove the installation\'s rate by posting its number (scope "organisation")', $typedCount() === $typedBefore);
$beforeRefused = $logged(ER_ORGADMIN, 'ExchangeRatesActionRefused');
$post($httpOrg, ['action' => 'remove_rate', 'scope' => 'installation', 'enteredID' => (string) $instRow]);
check('F19 ...nor by claiming the installation\'s scope: refused and recorded', $typedCount() === $typedBefore && $logged(ER_ORGADMIN, 'ExchangeRatesActionRefused') === $beforeRefused + 1);
$ownRow = (int) rows($db, 'SELECT `enteredID` FROM `tblExchangeRateEntered` WHERE `siteID` IS NOT NULL LIMIT 1')[0]['enteredID'];
$beforeRemoved = $logged(ER_ORGADMIN, 'ExchangeRateRemoved');
$post($httpOrg, ['action' => 'remove_rate', 'scope' => 'organisation', 'enteredID' => (string) $ownRow]);
check('F20 it can remove its own, and that is recorded', $typedCount() === $typedBefore - 1 && $logged(ER_ORGADMIN, 'ExchangeRateRemoved') === $beforeRemoved + 1);

// The form token and the unknown action.
$noToken = $httpRoot('POST', '/admin/exchange-rates/save', ['action' => 'fetch_switch', 'fetchEnabledShown' => '1']);
check('F21 a post with no form token is refused (400) and changes nothing', $noToken['status'] === 400 && $switchValue() === '1');
$odd = $post($httpRoot, ['action' => 'format_disk']);
check('F22 an unknown action changes nothing and says so', $odd['status'] === 302 && str_contains($page($httpRoot)['body'], 'not understood') === true);

// The fetched rates drive the "in force" lines.
$wipe();
$putRate('USD', '1.1052', $today);
$putRate('GBP', '0.83559', $today);
$view = $page($httpRoot)['body'];
check('F23 with fresh fetched rates the page shows 1 USD = 0.756053 GBP, from the European Central Bank, and calls the list fresh',
    str_contains($view, '1 USD = 0.756053 GBP') === true && str_contains($view, 'fetched from the European Central Bank') === true && str_contains($view, 'data-rates-fresh="fresh"') === true);
$putRate('USD', '1.1052', $old);
$putRate('GBP', '0.83559', $old);
check('F24 once they are too old the page says so ("too old to use") and stops showing them as the rate in force',
    str_contains($page($httpRoot)['body'], 'data-rates-fresh="stale"') === true && str_contains($page($httpRoot)['body'], 'fetched from the European Central Bank') === false);
$wipe();

// The general settings editor.
$rowId = (int) (rows($db, "SELECT `settingID` FROM `tblSettings` WHERE `settingKey` = 'exchangeRates.fetch' AND `siteID` IS NULL")[0]['settingID'] ?? 0);
$refusedBefore = $logged(ER_ADMIN, 'SettingsUpdateRefused');
$httpRoot('POST', '/settings/save', ['csrf_token' => $tokenOn($httpRoot('GET', '/settings')['body']), 'settingID' => (string) $rowId, 'settingKey' => 'exchangeRates.fetch', 'settingValue' => '0']);
check('F25 the general settings editor REFUSES to change the switch (the row is premise-checked first), and records the refusal',
    $rowId > 0 && $switchValue() === '1' && $logged(ER_ADMIN, 'SettingsUpdateRefused') === $refusedBefore + 1, 'row ' . $rowId . ' value ' . var_export($switchValue(), true));
$httpRoot('POST', '/settings/save', ['csrf_token' => $tokenOn($httpRoot('GET', '/settings')['body']), 'settingID' => '0', 'siteID' => (string) $site, 'settingKey' => 'exchangeRates.fetch', 'settingValue' => '0']);
check('F26 ...and to ADD an organisation row for it (which the fetch would ignore)',
    (int) rows($db, "SELECT COUNT(*) AS `n` FROM `tblSettings` WHERE `settingKey` = 'exchangeRates.fetch' AND `siteID` IS NOT NULL")[0]['n'] === 0);

head('Section G — the scheduled job');
$jobToken = 'er' . bin2hex(random_bytes(12));
$setting('maintenance.cronToken', $jobToken);
$callJob = static fn (): array => $httpJob('GET', '/cron/mail-queue', [], ['Authorization: Bearer ' . $jobToken]);
$setting('exchangeRates.fetch', '0');
$wipe();
$job  = $callJob();
$json = json_decode($job['body'], true);
check('G1 with the switch off the job answers ONE JSON document: status ok, ratesSkipped 1, ratesRan 0, ratesStored 0, ratesError 0',
    $job['status'] === 200 && is_array($json) === true && ($json['status'] ?? '') === 'ok' && ($json['ratesSkipped'] ?? null) === 1 && ($json['ratesRan'] ?? null) === 0
    && ($json['ratesStored'] ?? null) === 0 && ($json['ratesError'] ?? null) === 0, $job['status'] . ' ' . mb_substr($job['body'], 0, 300));
$nonNumbers = is_array($json) === true ? array_filter($json, static fn ($v, $k): bool => $k !== 'status' && is_int($v) === false, ARRAY_FILTER_USE_BOTH) : ['?' => '?'];
check('G2 the answer is still NUMBERS ONLY beside the status word (the new fields are plain integers)', $nonNumbers === [], json_encode($nonNumbers));
check('G3 the texts step\'s numbers are still there beside the rates ones', is_array($json) === true && array_key_exists('textsRan', $json) === true && array_key_exists('ratesRan', $json) === true);
check('G4 a switched-off fetch makes no attempt: the fetch row is untouched', rows($db, 'SELECT `lastAttemptUtc` FROM `tblExchangeRateFetch`')[0]['lastAttemptUtc'] === null);
// A fault in the rates step must not change the mail step's answer.
$setting('exchangeRates.fetch', '1');
run($db, 'RENAME TABLE `tblExchangeRateFetch` TO `zzExchangeRateFetchAway`');
$job  = $callJob();
run($db, 'RENAME TABLE `zzExchangeRateFetchAway` TO `tblExchangeRateFetch`');
$json = json_decode($job['body'], true);
check('G5 with the rates step unable to start (its records unreadable) the job STILL answers 200 and status ok, and reports ratesError 1 with ratesRan 0 and no request made',
    $job['status'] === 200 && is_array($json) === true && ($json['status'] ?? '') === 'ok' && ($json['ratesError'] ?? null) === 1 && ($json['ratesRan'] ?? null) === 0,
    $job['status'] . ' ' . mb_substr($job['body'], 0, 300));
check('G6 and the mail step still did its own work: its own numbers are in the same answer', is_array($json) === true && array_key_exists('sent', $json) === true && array_key_exists('waiting', $json) === true);
// The page's OWN catch (for what the class cannot catch, such as a class file lost from a half-finished
// upload): make refreshIfDue() throw out of the class in the test server's copy of web/.
$copyOfClass = $child . $sep . 'web' . $sep . '_core' . $sep . 'ExchangeRates.php';
$classText   = (string) file_get_contents($copyOfClass);
$needle      = "        \$result = ['ran' => 0, 'stored' => 0, 'error' => 0, 'skipped' => 0];\n        try {";
if (str_contains($classText, $needle) === false) {
    refuse('could not find the start of refreshIfDue() in the copy, to make it throw');
}
file_put_contents($copyOfClass, str_replace($needle, "        throw new \\RuntimeException('planted by the self-test');\n" . $needle, $classText));
$setting('exchangeRates.fetch', '1');
$job  = $callJob();
file_put_contents($copyOfClass, $classText);
$json = json_decode($job['body'], true);
check('G5b when the rates step THROWS out of the class, the page\'s own catch holds: HTTP 200, status ok (the mail step\'s), ratesError 1, ratesRan 0, and the mail step\'s numbers are still in the answer',
    $job['status'] === 200 && is_array($json) === true && ($json['status'] ?? '') === 'ok' && ($json['ratesError'] ?? null) === 1 && ($json['ratesRan'] ?? null) === 0
    && array_key_exists('sent', $json) === true, $job['status'] . ' ' . mb_substr($job['body'], 0, 300));
$setting('exchangeRates.fetch', '0');
$wrong = $httpJob('GET', '/cron/mail-queue', [], ['Authorization: Bearer wrong-' . $jobToken]);
check('G7 a wrong token still gets 403 and no rates fields (the step sits after the token gate)',
    $wrong['status'] === 403 && str_contains($wrong['body'], 'rates') === false, $wrong['status'] . ' ' . $wrong['body']);
$setting('exchangeRates.fetch', '0');

// =============================================================================
// H. Nothing reaches past the front door
// =============================================================================
head('Section H — nothing under web/ reaches past the class\'s front door');
$offenders = [];
$scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS));
foreach ($scan as $file) {
    $path = $file->getPathname();
    if (str_ends_with($path, '.php') === false || str_contains($path, $sep . 'ExchangeRates.php') === true) {
        continue;
    }
    $src = (string) file_get_contents($path);
    if (preg_match('/ExchangeRates::refreshFrom\s*\(/', $src) === 1) {
        $offenders[] = substr($path, strlen($repo)) . ' calls refreshFrom()';
    }
    if (preg_match_all('/ExchangeRates::refreshIfDue\s*\(([^()]*(?:\([^()]*\))?[^()]*)\)/', $src, $calls) > 0) {
        foreach ($calls[1] as $args) {
            if (str_contains((string) $args, ',') === true) {
                $offenders[] = substr($path, strlen($repo)) . ' passes a second argument to refreshIfDue()';
            }
        }
    }
}
check('H1 only the class itself calls refreshFrom(), and nobody under web/ passes refreshIfDue() a test address', $offenders === [], implode('; ', $offenders));
$classSource = (string) file_get_contents($repo . $sep . 'web' . $sep . '_core' . $sep . 'ExchangeRates.php');
$classCode = '';
foreach (token_get_all($classSource) as $tok) {
    // Code only: the class's own comments name CURLOPT_ENCODING to say it is never set.
    if (is_array($tok) === false) {
        $classCode .= $tok;
    } elseif ($tok[0] !== T_COMMENT && $tok[0] !== T_DOC_COMMENT) {
        $classCode .= $tok[1];
    }
}
check('H2 the class fetches only through SafeFetch::get(), never curl directly, and sets no CURLOPT_ENCODING (#716)',
    str_contains($classCode, 'SafeFetch::get(') === true && preg_match('/curl_(init|setopt)|CURLOPT_ENCODING|file_get_contents\s*\(\s*self::SOURCE/', $classCode) !== 1);
check('H3 the class has no (float), floatval or "* 100" anywhere in code (a float must never touch a rate or an amount)',
    preg_match('/\(\s*float\s*\)|floatval\s*\(|\*\s*100\b/', $classCode) !== 1);
check('H4 the source address is the bank\'s one fixed https address, and nothing else is fetched',
    ExchangeRates::SOURCE_URL === 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml');

echo "\n";
echo $failed === 0 ? "ALL {$passed} CHECKS PASSED\n" : "{$failed} FAILED, {$passed} passed\n";
exit($failed === 0 ? 0 : 1);
