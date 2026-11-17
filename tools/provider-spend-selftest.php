<?php
// Path: tools/provider-spend-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test for spending with outside companies, in their own currency 💱
 * -----------------------------------------------------------------------------
 * (#993, package B; D-173, D-192, D-194.) Portal\Core\ProviderSpend prices every
 * charge from an AI, translation, transcription or text-message company in the
 * currency that company charges in, adds it to a monthly record, and decides
 * whether a coming call is stopped by a spending limit, comparing the
 * installation's figure in the installation's currency and an organisation's own
 * figure in its own. A fault in any of that is QUIET: a figure a little wrong,
 * or a limit that does not bite. So every behaviour is exercised here with the
 * answers worked out OUTSIDE the code (by hand and with `bc`), and each check was
 * proved able to go red by planting the fault it names (the list is in the
 * commit message).
 *
 * WHAT IT RUNS
 *   P. Prices: every price entry, with figures written out by hand, the ceiling,
 *      model matching, and "no price" for a free route.
 *   R. Recording: exact addition, currencies kept apart, nothing for no cost,
 *      an erasure not lowering the month, a failure not throwing.
 *   W. The limit, `whyRefused()`: each level in its own currency, an
 *      organisation's typed rate never loosening the installation's check, zero
 *      meaning "no limit" for translation and "nothing" for AI and transcription,
 *      no rate or no currency refusing, an unreadable record refusing, the hard
 *      maximum, the merged settings never being the source.
 *   S. The services: AI drafting, translation and texts through the real classes,
 *      so a service that stopped asking `ProviderSpend` goes red here.
 *   T. The words for a page.
 *   M. Migration 302 run over a database that still holds the old columns and a
 *      row that must survive, and run a second time.
 *
 * WHAT IT CANNOT PROVE
 *   - Nothing here reaches a paid company, so whether a company's real price is
 *     what `ProviderSpend::PRICES` says is NOT tested (see that constant for
 *     which figures were checked against the company's page and when).
 *   - Money limits through the HTTP pages are in the page tests
 *     (content-translation-selftest.php, transcription-pages-selftest.php,
 *     held-texts-selftest.php); the restore carry is proof 30 of
 *     backup-restore-selftest.php.
 *   - Two simultaneous charges are not tried (one process).
 *
 * NEEDS: a database named selftest_* with web/_sql/full_schema.sql loaded
 * (SELFTEST_DB_NAME, and SELFTEST_DB_HOST/PORT/USER/PASS), proc_open and curl.
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

use Portal\Core\AiAssistant;
use Portal\Core\App;
use Portal\Core\Currency;
use Portal\Core\ProviderSpend;
use Portal\Core\Translation;

// =============================================================================
// P. Prices — no database, every figure written out by hand
// =============================================================================
head('Section P — what a charge costs (whole millionths; every figure worked out by hand)');
$c = static fn (string $service, string $provider, ?string $model, array $use): ?array => ProviderSpend::cost($service, $provider, $model, $use);

check('P1 Claude Haiku 4.5, 300 tokens in and 250 out: 300 x 1,000,000 + 250 x 5,000,000 = 1,550,000,000 over a million = 1,550 millionths of a dollar',
    $c('ai', 'anthropic', 'claude-haiku-4-5-20251001', ['in' => 300, 'out' => 250]) === ['currency' => 'USD', 'millionths' => 1550],
    json_encode($c('ai', 'anthropic', 'claude-haiku-4-5-20251001', ['in' => 300, 'out' => 250])));
check('P1b ...and the old formula called the same call 13 pence, about 100 times too much: 1,550 millionths of a dollar is 0.155 of a CENT',
    ($c('translation', 'anthropic', 'claude-haiku-4-5', ['in' => 300, 'out' => 250])['millionths'] ?? 0) === 1550);
check('P2 an Anthropic model the list does not know is charged at the dearest listed (Opus 4.1: $15 in, $75 out): 300/250 is 23,250',
    ($c('ai', 'anthropic', 'claude-some-future-model', ['in' => 300, 'out' => 250])['millionths'] ?? 0) === 23250);
check('P2b ...and so is a call with no model name at all',
    ($c('ai', 'anthropic', null, ['in' => 300, 'out' => 250])['millionths'] ?? 0) === 23250);
check('P3 gpt-4o-mini, 1,000 tokens each way: 150,000,000 + 600,000,000 = 750,000,000 over a million = 750',
    ($c('ai', 'openai', 'gpt-4o-mini', ['in' => 1000, 'out' => 1000])['millionths'] ?? 0) === 750);
check('P3b a dated gpt-4o-mini name is matched by its prefix, and the SMALLER model is matched before gpt-4o: 750, not 12,500',
    ($c('translation', 'openai', 'gpt-4o-mini-2024-07-18', ['in' => 1000, 'out' => 1000])['millionths'] ?? 0) === 750
    && ($c('translation', 'openai', 'gpt-4o-mini', ['in' => 1000, 'out' => 1000])['millionths'] ?? 0) === 750);
check('P3c gpt-4o (EXACT name) is 2,500,000 + 10,000,000 per million: 1,000 each way is 12,500; any other OpenAI name is charged at the dearest OpenAI price known (150,000,000 + 600,000,000: 750,000)',
    ($c('ai', 'openai', 'gpt-4o', ['in' => 1000, 'out' => 1000])['millionths'] ?? 0) === 12500
    && ($c('ai', 'openai', 'gpt-9', ['in' => 1000, 'out' => 1000])['millionths'] ?? 0) === 750000);
// A typed model is free text. Each of these really costs LESS than the dearest known price; the
// check is that none is priced below what it really costs (the loose prefix used to price o1-pro
// 60x under, o1 6x, gpt-4 7.2x, gpt-5-pro 10.8x, gpt-4o-2024-05-13 1.6x). Real prices are the
// recalled list prices per million tokens, in millionths for 1,000,000 in and 1,000,000 out.
foreach (['o1' => 75000000, 'gpt-4' => 90000000, 'gpt-5-pro' => 135000000, 'o1-pro' => 750000000, 'gpt-4o-2024-05-13' => 20000000,
          'gpt-4o-mini-evil' => 750000, 'gpt-4oX' => 12500000, 'GPT-4O-MINI' => 750000] as $typed => $reallyCosts) {
    foreach (['ai', 'translation'] as $svc) {
        $charged = (int) ($c($svc, 'openai', $typed, ['in' => 1000000, 'out' => 1000000])['millionths'] ?? 0);
        check("P3d {$svc}: a typed OpenAI model \"{$typed}\" is charged " . number_format($charged) . ' millionths for 1M in and 1M out, never LESS than its real ' . number_format($reallyCosts),
            $charged >= $reallyCosts && $charged === 750000000, (string) $charged);
    }
}
check('P3e the dated names of the two models whose price does not change by date are still matched: gpt-4o-mini-2024-07-18 is 750 per 1,000 each way; claude-haiku-4-5-20251001 is 1,550 for 300/250; but "gpt-4o-2024-08-06" (not the bare name) is the dearest, not 12,500',
    ($c('ai', 'openai', 'gpt-4o-mini-2024-07-18', ['in' => 1000, 'out' => 1000])['millionths'] ?? 0) === 750
    && ($c('ai', 'anthropic', 'claude-haiku-4-5-20251001', ['in' => 300, 'out' => 250])['millionths'] ?? 0) === 1550
    && ($c('ai', 'openai', 'gpt-4o-2024-08-06', ['in' => 1000, 'out' => 1000])['millionths'] ?? 0) === 750000);
check('P4 Google Translation: 1,000 characters at $20 per million characters is 20,000 millionths of a dollar',
    $c('translation', 'google', null, ['chars' => 1000]) === ['currency' => 'USD', 'millionths' => 20000]);
check('P4b DeepL is in EUROS: 1,000 characters is 20,000 millionths of a euro',
    $c('translation', 'deepl', null, ['chars' => 1000]) === ['currency' => 'EUR', 'millionths' => 20000]);
check('P5 Whisper at $0.36 an hour: 600 seconds is 60,000 millionths of a dollar, and a single second is 100',
    ($c('transcription', 'openai', null, ['seconds' => 600])['millionths'] ?? 0) === 60000
    && ($c('transcription', 'openai', null, ['seconds' => 1])['millionths'] ?? 0) === 100);
check('P5b AssemblyAI at $0.37 an hour: an hour is 370,000; ONE second is 102.78, rounded UP to 103 (never down to 102)',
    ($c('transcription', 'assemblyai', null, ['seconds' => 3600])['millionths'] ?? 0) === 370000
    && ($c('transcription', 'assemblyai', null, ['seconds' => 1])['millionths'] ?? 0) === 103);
check('P6 one text: Twilio 52,907 (dollars), MessageBird 59,838 (euros), AWS 79,360 (dollars) — the old 4p, 5p and 6p carried over at the 13 November 2026 bank rate',
    $c('sms', 'twilio', null, ['texts' => 1]) === ['currency' => 'USD', 'millionths' => 52907]
    && $c('sms', 'messagebird', null, ['texts' => 1]) === ['currency' => 'EUR', 'millionths' => 59838]
    && $c('sms', 'aws', null, ['texts' => 1]) === ['currency' => 'USD', 'millionths' => 79360]);
check('P6b three texts are exactly three times one (an integer, not a rounded multiple)',
    ($c('sms', 'twilio', null, ['texts' => 3])['millionths'] ?? 0) === 158721);
check('P7 a free route reports NO cost (null, never 0): the local assistant, the local transcriber, LibreTranslate, an unknown company',
    $c('ai', 'local', null, ['in' => 5, 'out' => 5]) === null && $c('transcription', 'local', null, ['seconds' => 60]) === null
    && $c('translation', 'libre', null, ['chars' => 100]) === null && $c('sms', 'carrier-pigeon', null, ['texts' => 1]) === null);
check('P8 the ceiling at the smallest sizes: one input token at Haiku is exactly 1, one output token is 5',
    ($c('ai', 'anthropic', 'claude-haiku-4-5', ['in' => 1, 'out' => 0])['millionths'] ?? 0) === 1
    && ($c('ai', 'anthropic', 'claude-haiku-4-5', ['in' => 0, 'out' => 1])['millionths'] ?? 0) === 5);
check('P9 nothing used costs 0 (an array, so a caller can tell "free of charge" from "no price"), and a negative or missing figure counts as 0, never as a refund',
    $c('ai', 'anthropic', 'claude-haiku-4-5', ['in' => 0, 'out' => 0]) === ['currency' => 'USD', 'millionths' => 0]
    && ($c('ai', 'anthropic', 'claude-haiku-4-5', ['in' => -500, 'out' => 10])['millionths'] ?? -1) === 50);
check('P10 a currency each company charges in is known by currencyOf(), and billingCurrencies() is exactly the set the price list uses (EUR, USD)',
    ProviderSpend::currencyOf('translation', 'deepl') === 'EUR' && ProviderSpend::currencyOf('ai', 'anthropic') === 'USD'
    && ProviderSpend::currencyOf('ai', 'local') === null && ProviderSpend::billingCurrencies() === ['EUR', 'USD']);
check('P11 no price in the list is a floating-point number (every figure is a whole number of millionths)',
    (static function (): bool {
        $walk = static function (mixed $v) use (&$walk): bool {
            if (is_float($v) === true) {
                return false;
            }
            if (is_array($v) === true) {
                foreach ($v as $item) {
                    if ($walk($item) === false) {
                        return false;
                    }
                }
            }
            return true;
        };
        return $walk(ProviderSpend::PRICES);
    })() === true);

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
foreach (['tblProviderSpend', 'tblExchangeRate', 'tblExchangeRateEntered', 'tblAiUsage', 'tblSmsMessage'] as $t) {
    if (rows($db, 'SELECT 1 FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?', 's', [$t]) === []) {
        refuse("the table {$t} is missing — load web/_sql/full_schema.sql from this branch");
    }
}

// Made-up organisations. Rows only: tblSettings points at tblSites, so each gets a real row.
const PS_SITE_A = 993301;   // an organisation with no limit row of its own
const PS_SITE_B = 993302;   // an organisation that holds ITSELF to a figure
foreach ([PS_SITE_A, PS_SITE_B] as $sid) {
    run($db, "INSERT INTO `tblSites` (`siteID`, `siteKey`, `siteName`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `siteName` = VALUES(`siteName`)",
        'iss', [$sid, 'ps' . $sid, 'Provider spend selftest ' . $sid]);
}
$MONTH = ProviderSpend::currentMonth();
// One person, so a usage row can name somebody (tblAiUsage points at tblUsers).
const PS_USER = 993350;
run($db, "INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, 'Provider spend selftest', 'ps-selftest@example.invalid', 1, 0, 0, NOW()) "
    . 'ON DUPLICATE KEY UPDATE `fullName` = VALUES(`fullName`)', 'i', [PS_USER]);

// A made-up PHP-side settings array, as the portal's start-up would hold it. ProviderSpend
// must NEVER read a limit from here: that is what check W16 proves.
App::init($db, ['ai_assist' => ['monthCapPence' => '999999999']]);

/** Set or clear ONE settings row (installation-wide when `$site` is null). */
$setting = static function (string $key, ?string $value, ?int $site = null) use ($db): void {
    if ($site === null) {
        run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
    } else {
        run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?', 'si', [$key, $site]);
    }
    if ($value !== null) {
        run($db, 'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, ?, 0)',
            'isss', [$site, $key, $value, $value]);
    }
};
$putRate = static function (string $code, string $rate, string $day) use ($db): void {
    run($db, 'INSERT INTO `tblExchangeRate` (`rateCurrency`, `perEuro`, `rateDate`, `fetchedAtUtc`) VALUES (?, ?, ?, UTC_TIMESTAMP()) '
        . 'ON DUPLICATE KEY UPDATE `perEuro` = VALUES(`perEuro`), `rateDate` = VALUES(`rateDate`)', 'sss', [$code, $rate, $day]);
};
$putTyped = static function (?int $site, string $from, string $to, string $rate) use ($db): void {
    run($db, 'INSERT INTO `tblExchangeRateEntered` (`siteID`, `fromCurrency`, `toCurrency`, `rate`, `asOf`, `enteredAtUtc`) VALUES (?, ?, ?, ?, UTC_DATE(), UTC_TIMESTAMP()) '
        . 'ON DUPLICATE KEY UPDATE `rate` = VALUES(`rate`)', 'isss', [$site, $from, $to, $rate]);
};
/** Everything this test changes goes back to how a fresh install has it. */
$reset = static function () use ($db, $setting): void {
    run($db, 'DELETE FROM `tblProviderSpend` WHERE `siteID` IN (?, ?, 1)', 'ii', [PS_SITE_A, PS_SITE_B]);
    run($db, 'DELETE FROM `tblExchangeRate`');
    run($db, 'DELETE FROM `tblExchangeRateEntered`');
    foreach (['ai_assist.monthCapPence', 'translation.monthCapPence', 'transcription.monthCapPence'] as $k) {
        $setting($k, '5000');
        $setting($k, null, PS_SITE_A);
        $setting($k, null, PS_SITE_B);
    }
    $setting('org.currency', '');
    $setting('org.currency', null, PS_SITE_A);
    $setting('org.currency', null, PS_SITE_B);
};
$today = gmdate('Y-m-d');
$old   = gmdate('Y-m-d', time() - 30 * 86400);
$reset();
$fresh = static function () use ($putRate, $today): void {
    // The bank's own figures for 13 November 2026: 1 euro = 1.1052 dollars = 0.83559 pounds.
    $putRate('USD', '1.1052', $today);
    $putRate('GBP', '0.83559', $today);
};
$why = static fn (int $site, string $service, ?string $cur): string => ProviderSpend::whyRefused($db, $site, $service, $cur);
/** Dollars as millionths. */
$usd = static fn (int $dollars): int => $dollars * 1000000;

// =============================================================================
// R. Recording
// =============================================================================
head('Section R — recording a charge, and what must NOT change it');
ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'USD', 'millionths' => 1550]);
ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'USD', 'millionths' => 1550]);
check('R1 two charges add up exactly: 1,550 + 1,550 = 3,100 millionths of a dollar, in ONE row',
    ProviderSpend::monthTotals($db, PS_SITE_A, 'translation') === ['USD' => 3100]
    && (int) rows($db, 'SELECT COUNT(*) AS `n` FROM `tblProviderSpend` WHERE `siteID` = ?', 'i', [PS_SITE_A])[0]['n'] === 1);
ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'EUR', 'millionths' => 20000]);
$twoCurrencies = ProviderSpend::monthTotals($db, PS_SITE_A, 'translation');
check('R2 two currencies stay APART, never added together: dollars 3,100 and euros 20,000',
    is_array($twoCurrencies) === true && count($twoCurrencies) === 2 && ($twoCurrencies['USD'] ?? 0) === 3100 && ($twoCurrencies['EUR'] ?? 0) === 20000);
ProviderSpend::record($db, PS_SITE_A, 'ai', ['currency' => 'USD', 'millionths' => 7]);
check('R3 a service never sees another service\'s money, nor another organisation\'s',
    ProviderSpend::monthTotals($db, PS_SITE_A, 'ai') === ['USD' => 7] && ProviderSpend::monthTotals($db, PS_SITE_B, 'ai') === []
    && ProviderSpend::monthTotals($db, PS_SITE_A, 'sms') === []);
$before = rows($db, 'SELECT COUNT(*) AS `n` FROM `tblProviderSpend`')[0]['n'];
ProviderSpend::record($db, PS_SITE_A, 'sms', null);
ProviderSpend::record($db, PS_SITE_A, 'sms', ['currency' => 'USD', 'millionths' => 0]);
ProviderSpend::record($db, PS_SITE_A, 'sms', ['currency' => 'USD', 'millionths' => -5]);
ProviderSpend::record($db, PS_SITE_A, 'carrier-pigeon', ['currency' => 'USD', 'millionths' => 5]);
check('R4 no cost, a cost of 0, a negative cost and an unknown service each write NOTHING',
    rows($db, 'SELECT COUNT(*) AS `n` FROM `tblProviderSpend`')[0]['n'] === $before);
ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'USD', 'millionths' => 5], '2020-01-01');
check('R5 last year\'s record is not this month\'s: a charge written for January 2020 is not in the current totals, and is in its own month',
    (ProviderSpend::monthTotals($db, PS_SITE_A, 'translation') === ['EUR' => 20000, 'USD' => 3100]
        || ProviderSpend::monthTotals($db, PS_SITE_A, 'translation') === ['USD' => 3100, 'EUR' => 20000])
    && ProviderSpend::monthTotals($db, PS_SITE_A, 'translation', '2020-01-01') === ['USD' => 5]);
// NOTE ON WHAT THIS CAN AND CANNOT SEE: the month is written with gmdate(). On this machine PHP's own zone is
// UTC, so a plant that wrote date('Y-m-01') instead would give the SAME answer and this check could not tell;
// the two only differ near a month end in a zone that is not UTC. It pins the SHAPE (first day, this month).
check('R5b ...and the month is the first day of the current month (the SHAPE only: it cannot see UTC against local time while this machine runs in UTC)',
    $MONTH === gmdate('Y') . '-' . gmdate('m') . '-01' && preg_match('/^\d{4}-\d{2}-01$/', $MONTH) === 1);

// An erasure DELETES the rows of tblAiUsage and tblSmsMessage; the month's money must not fall.
$reset();
$recordUsage = new ReflectionMethod(AiAssistant::class, 'recordUsage');
$recordUsage->invoke(null, 1, PS_USER, 'announcement', 'anthropic', 300, 250, ['currency' => 'USD', 'millionths' => 1550], 'in', 'out');
$aiRow = rows($db, 'SELECT `costCurrency`, `costMillionths` FROM `tblAiUsage` WHERE `siteID` = 1 ORDER BY `usageID` DESC LIMIT 1');
check('R6 an AI call leaves a row carrying its cost and its currency (USD, 1,550) AND the month\'s record (USD, 1,550)',
    ($aiRow[0]['costCurrency'] ?? '') === 'USD' && (int) ($aiRow[0]['costMillionths'] ?? 0) === 1550
    && ProviderSpend::monthTotals($db, 1, 'ai') === ['USD' => 1550]);
run($db, 'DELETE FROM `tblAiUsage` WHERE `siteID` = 1');
check('R7 AN ERASURE DOES NOT LOWER THE MONTH: after the usage rows are deleted (as an erasure does) the month still says 1,550 — the old figure was a SUM over those rows and fell',
    rows($db, 'SELECT COUNT(*) AS `n` FROM `tblAiUsage` WHERE `siteID` = 1')[0]['n'] === 0 && ProviderSpend::monthTotals($db, 1, 'ai') === ['USD' => 1550]);

// A broken record is never "nothing spent", and recording never throws into the caller.
run($db, 'RENAME TABLE `tblProviderSpend` TO `tblProviderSpend_ps_hidden`');
$threw = false;
try {
    $logOff = ini_set('error_log', '/dev/null');
    ProviderSpend::record($db, PS_SITE_A, 'ai', ['currency' => 'USD', 'millionths' => 5]);
    if ($logOff !== false) {
        ini_set('error_log', (string) $logOff);
    }
} catch (Throwable $e) {
    $threw = true;
}
$totalsBroken = ProviderSpend::monthTotals($db, PS_SITE_A, 'ai');
$whyBroken    = $why(PS_SITE_A, 'ai', 'USD');
run($db, 'RENAME TABLE `tblProviderSpend_ps_hidden` TO `tblProviderSpend`');
check('R8 with the record missing: recording does not throw (the provider has already been paid), reading answers null (never "nothing"), and a check REFUSES as unreadable',
    $threw === false && $totalsBroken === null && $whyBroken === 'unreadable', json_encode([$threw, $totalsBroken, $whyBroken]));

// =============================================================================
// W. The limit
// =============================================================================
head('Section W — whyRefused(): each level in its own currency (rates and figures written out by hand)');
$reset();
$setting('org.currency', 'GBP');
$fresh();
// 1,000,000 millionths of a dollar ($1) in pounds: 1,000,000 x 0.83559 / (1.1052 x 10,000) = 75.6053, rounded UP to 76 hundredths.
ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'USD', 'millionths' => $usd(1)]);
check('W1 $1 spent, limit £50.00 (5000 hundredths) in the installation\'s pounds: $1 is 76 hundredths, so allowed',
    $why(PS_SITE_A, 'translation', 'USD') === '');
ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'USD', 'millionths' => $usd(65)]);
// $66 = 66,000,000 -> 66 x 75.6053 = 4,989.9, so 4,990: still under 5,000.
check('W2 $66 in all is 4,990 hundredths: still under £50.00',
    $why(PS_SITE_A, 'translation', 'USD') === '');
ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'USD', 'millionths' => $usd(1)]);
// $67 -> 67 x 75.6053 = 5,065.6, so 5,066: at or over.
check('W3 $67 in all is 5,066 hundredths: over £50.00 — refused as the INSTALLATION\'s limit (the figure is compared in the installation\'s currency, not the dollar)',
    $why(PS_SITE_A, 'translation', 'USD') === 'installation-limit');
check('W3b ...and that is "the money limit is reached", not a missing rate: nothing in the word suggests a rate problem',
    $why(PS_SITE_A, 'translation', null) === 'installation-limit');

// An organisation's own limit, in ITS OWN currency, while the installation's is far away.
$reset();
$setting('org.currency', 'GBP');
$setting('org.currency', 'USD', PS_SITE_B);
$setting('translation.monthCapPence', '100', PS_SITE_B);     // its own: $1.00, in its own dollars
$fresh();
ProviderSpend::record($db, PS_SITE_B, 'translation', ['currency' => 'USD', 'millionths' => 1500000]);   // $1.50
check('W4 the organisation holds itself to $1.00 (its own row, in its OWN currency, dollars): $1.50 reaches that while the installation\'s £50.00 is far off — refused as the ORGANISATION\'s limit',
    $why(PS_SITE_B, 'translation', 'USD') === 'organisation-limit');
check('W4b ...and the same spend by an organisation with NO row of its own is allowed: the organisation\'s figure applies only to it',
    (function () use ($db, $why, $usd): string {
        ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'USD', 'millionths' => 1500000]);
        return $why(PS_SITE_A, 'translation', 'USD');
    })() === '');
$setting('translation.monthCapPence', '0', PS_SITE_B);
check('W4c an organisation row of 0 means NO limit at that level for translation: it is then stopped by nothing of its own (the installation still binds)',
    $why(PS_SITE_B, 'translation', 'USD') === '');
$setting('translation.monthCapPence', '100', PS_SITE_B);
$setting('translation.monthCapPence', '1', null);
check('W4d the installation\'s own figure binds the organisation too: with the installation at £0.01, an organisation holding itself to a bigger figure is still stopped (refused as the installation\'s)',
    $why(PS_SITE_B, 'translation', 'USD') === 'installation-limit');
$setting('translation.monthCapPence', '5000');

// An organisation's typed rate can NEVER loosen the installation's check.
$reset();
$setting('org.currency', 'GBP');
$setting('org.currency', 'USD', PS_SITE_B);
$setting('translation.monthCapPence', '10000', PS_SITE_B);   // an own figure well clear of the spend, so only the installation's can refuse
$putTyped(PS_SITE_B, 'USD', 'GBP', '0.0001');                // the organisation types an absurdly low rate
ProviderSpend::record($db, PS_SITE_B, 'translation', ['currency' => 'USD', 'millionths' => $usd(100)]);
check('W5 with NO fetched rate and only the ORGANISATION\'s typed rate, the installation\'s check has no rate and REFUSES: no-rate (it is never allowed to use the organisation\'s figure)',
    $why(PS_SITE_B, 'translation', 'USD') === 'no-rate');
$putTyped(null, 'USD', 'GBP', '0.75');
// Installation's rate 0.75: $100 = 100,000,000 x 0.75 / 10,000 = 7,500 hundredths, over 5,000. The organisation's 0.0001 would have said 1.
check('W5b once the INSTALLATION types a rate (0.75) the installation\'s limit bites ($100 is 7,500 hundredths, over 5,000), although the organisation\'s own typed rate would have made it 1',
    $why(PS_SITE_B, 'translation', 'USD') === 'installation-limit');
// The organisation's OWN check does use its own typed rate (where its currency needs one).
$setting('org.currency', 'GBP', PS_SITE_B);
$setting('translation.monthCapPence', '5', PS_SITE_B);   // £0.05 in its own pounds
run($db, 'DELETE FROM `tblExchangeRateEntered`');
$putTyped(PS_SITE_B, 'USD', 'GBP', '0.0001');            // $100 at 0.0001 is 100 x 0.0001 = £0.01 = 1 hundredth: UNDER 5
$putTyped(null, 'USD', 'GBP', '0.0001');                 // so the installation's check is also under its £50
check('W5c the organisation\'s OWN check uses its own typed rate: $100 at its typed 0.0001 is 1 hundredth of a pound, under its own £0.05 figure, so allowed; at its typed 0.9 it is 9,000 hundredths and the ORGANISATION\'s limit refuses',
    $why(PS_SITE_B, 'translation', 'USD') === ''
    && (function () use ($putTyped, $why): string {
        $putTyped(PS_SITE_B, 'USD', 'GBP', '0.9');
        return $why(PS_SITE_B, 'translation', 'USD');
    })() === 'organisation-limit');

// No currency and no rate.
$reset();
$fresh();
check('W6 NO currency chosen for the installation and a limit to compare: refused as no-currency (nothing is guessed)',
    $why(PS_SITE_A, 'translation', 'USD') === 'no-currency');
$setting('org.currency', 'GBP');
$setting('translation.monthCapPence', '0');
check('W7 translation with the limit at 0 (= no limit) needs neither a rate nor a currency: allowed, even with NO rate held',
    (function () use ($db, $reset, $setting, $why): string {
        run($db, 'DELETE FROM `tblExchangeRate`');
        return $why(PS_SITE_A, 'translation', 'USD');
    })() === '');
$setting('translation.monthCapPence', '5000');
check('W8 a limit in force and NO rate for the company\'s currency: refused as no-rate BEFORE the first call, at zero spend (D-192)',
    $why(PS_SITE_A, 'translation', 'USD') === 'no-rate');
$fresh();
check('W8b ...and with a fresh rate it is allowed',
    $why(PS_SITE_A, 'translation', 'USD') === '');
check('W8c a free route (no currency of its own) needs no rate even with nothing held, so long as the spend so far is under the figure',
    (function () use ($db, $why): string {
        run($db, 'DELETE FROM `tblExchangeRate`');
        return $why(PS_SITE_A, 'translation', null);
    })() === '');
$fresh();
check('W8d a currency ALREADY spent this month needs a rate too, whatever the coming call is charged in: dollars were spent, the pound rate is gone, so even a free call is refused',
    (function () use ($db, $why, $putRate, $today): string {
        run($db, 'DELETE FROM `tblExchangeRate` WHERE `rateCurrency` = ?', 's', ['GBP']);
        ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'USD', 'millionths' => 5]);
        $answer = $why(PS_SITE_A, 'translation', null);
        run($db, 'DELETE FROM `tblProviderSpend` WHERE `siteID` = ?', 'i', [PS_SITE_A]);
        $putRate('GBP', '0.83559', $today);
        return $answer;
    })() === 'no-rate');
check('W9 a STALE fetched rate (30 days old) is not used: with nothing typed, no-rate; with the installation\'s typed rate present, THAT is used',
    (function () use ($db, $why, $putRate, $putTyped, $old, $today): string {
        $putRate('USD', '1.1052', $old);
        $putRate('GBP', '0.83559', $old);
        $stale = $why(PS_SITE_A, 'translation', 'USD');
        $putTyped(null, 'USD', 'GBP', '0.75');
        $typed = $why(PS_SITE_A, 'translation', 'USD');
        $putRate('USD', '1.1052', $today);
        $putRate('GBP', '0.83559', $today);
        return $stale . '|' . $typed;
    })() === 'no-rate|');
run($db, 'DELETE FROM `tblExchangeRateEntered`');

// Zero means different things per service.
$setting('ai_assist.monthCapPence', '0');
$setting('transcription.monthCapPence', '0');
check('W10 a limit of 0 for AI means NOTHING may be spent (no-budget), and for transcription the same — only translation reads 0 as "no limit"',
    $why(PS_SITE_A, 'ai', null) === 'no-budget' && $why(PS_SITE_A, 'transcription', null) === 'no-budget'
    && (function () use ($setting, $why): string {
        $setting('translation.monthCapPence', '0');
        $answer = $why(PS_SITE_A, 'translation', null);
        $setting('translation.monthCapPence', '5000');
        return $answer;
    })() === '');
$setting('ai_assist.monthCapPence', '5000');
$setting('transcription.monthCapPence', '5000');
check('W10b texts have no limit key at all, so they are never refused here, however much has been spent',
    (function () use ($why): string {
        return $why(PS_SITE_A, 'sms', 'USD');
    })() === '');

// Hard maximum and unusable figures.
$reset();
$setting('org.currency', 'GBP');
$fresh();
$setting('translation.monthCapPence', '999999999');
ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'GBP', 'millionths' => 1000 * 1000000]);   // £1,000.00
check('W11 a stored figure above the hard maximum is cut to it (£1,000.00): spending £1,000.00 is refused although £9,999,999.99 was stored',
    $why(PS_SITE_A, 'translation', 'GBP') === 'installation-limit' && ProviderSpend::installationFigure($db, 'translation') === 100000);
$setting('translation.monthCapPence', 'banana');
check('W11b a stored figure that is not a number falls back to the default (5000), never to "no limit": £1,000.00 spent is refused',
    $why(PS_SITE_A, 'translation', 'GBP') === 'installation-limit' && ProviderSpend::installationFigure($db, 'translation') === 5000);
$setting('translation.monthCapPence', '-3');
check('W11c a negative figure falls back to the default too', ProviderSpend::installationFigure($db, 'translation') === 5000);
$setting('translation.monthCapPence', null);
check('W11d no row at all is the default (5000), so a server whose migration has not run still has a limit',
    ProviderSpend::installationFigure($db, 'translation') === 5000);
$setting('translation.monthCapPence', '5000');

// The merged settings are never the source (D-013): AI used to read them.
$reset();
$setting('org.currency', 'GBP');
$fresh();
$setting('ai_assist.monthCapPence', '100');   // £1.00 stored for the installation
ProviderSpend::record($db, 1, 'ai', ['currency' => 'GBP', 'millionths' => 2000000]);   // £2.00
App::init($db, ['ai_assist' => ['monthCapPence' => '999999999']]);   // what the merged array would say
check('W12 THE MERGED SETTINGS ARE NEVER THE SOURCE: the merged array says 999,999,999 but the installation\'s own row says £1.00, and £2.00 is spent — refused (the old AI check read the merged value and allowed it)',
    $why(1, 'ai', null) === 'installation-limit');

// Changing currency.
$reset();
$setting('org.currency', 'GBP');
$setting('org.currency', 'GBP', PS_SITE_B);
$setting('translation.monthCapPence', '5000', PS_SITE_B);   // 50.00 in its own currency, at present pounds
$fresh();
ProviderSpend::record($db, PS_SITE_B, 'translation', ['currency' => 'USD', 'millionths' => $usd(50)]);
// $50 in pounds = 50 x 75.6053 = 3,780.3 -> 3,781 hundredths of a pound: under 5000.
check('W13 in pounds the organisation\'s £50.00 is not reached by $50 (3,781 hundredths)', $why(PS_SITE_B, 'translation', 'USD') === '');
$setting('org.currency', 'USD', PS_SITE_B);
// Its figure 5000 is now read as $50.00 (5000 hundredths of a dollar): $50 spent is 5000 hundredths of a dollar -> reached.
check('W13b the organisation then changes to DOLLARS: its saved 5000 is now read as $50.00 (not converted), and $50 spent reaches it — and the installation\'s check still holds on its own terms',
    $why(PS_SITE_B, 'translation', 'USD') === 'organisation-limit');

// Two currencies in one month, both in the installation's.
$reset();
$setting('org.currency', 'GBP');
$fresh();
ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'USD', 'millionths' => $usd(30)]);   // 30 x 75.6053 = 2,268.2 -> 2,269
ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'EUR', 'millionths' => $usd(30)]);   // EUR 30 -> GBP 25.0677 -> 2,507
// 2,269 + 2,507 = 4,776 < 5,000
check('W14 two currencies are each converted and then added: $30 (2,269) + EUR 30 (2,507) = 4,776 hundredths, under 5,000',
    $why(PS_SITE_A, 'translation', null) === '');
ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'EUR', 'millionths' => $usd(5)]);   // EUR 35 -> 29.2447 -> 2,925; 2,269 + 2,925 = 5,194
check('W14b ...and EUR 35 instead brings the total to 5,194, over 5,000: refused',
    $why(PS_SITE_A, 'translation', null) === 'installation-limit');

// =============================================================================
// S. The services, through the real classes
// =============================================================================
head('Section S — AI drafting, translation and texts really ask ProviderSpend');
$work = sys_get_temp_dir() . $sep . 'provspend-selftest-' . bin2hex(random_bytes(6));
$state['child'] = $work;
mkdir($work, 0700, true);
file_put_contents($work . $sep . 'provspend-selftest.marker', 'made by tools/provider-spend-selftest.php');
file_put_contents($work . $sep . 'count.txt', '0');
file_put_contents($work . $sep . 'stub-router.php', <<<'STUB'
<?php
// Written by tools/provider-spend-selftest.php for ONE run: a stand-in for a local
// OpenAI-compatible server (the local AI route) and for LibreTranslate. It counts
// every request, so a test can prove a refused call never reached it.
declare(strict_types=1);
$dir = __DIR__;
$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($uri === '/count') { echo (string) file_get_contents($dir . '/count.txt'); return true; }
file_put_contents($dir . '/count.txt', (string) ((int) file_get_contents($dir . '/count.txt') + 1));
header('Content-Type: application/json');
if ($uri === '/v1/chat/completions') { echo json_encode(['choices' => [['message' => ['content' => 'A polished draft.']]]]); return true; }
if ($uri === '/translate') { echo json_encode(['translatedText' => 'Gair wedi ei gyfieithu']); return true; }
http_response_code(404); echo '{}';
return true;
STUB);
$stubPort = (static function (): int {
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errNo, $errStr);
    if ($sock === false) {
        refuse('could not find a free port for the stub server');
    }
    $name = (string) stream_socket_get_name($sock, false);
    fclose($sock);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
})();
$state['proc'] = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $stubPort, $work . $sep . 'stub-router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    $work
);
register_shutdown_function(static function () use (&$state, $sep): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_file($state['child'] . $sep . 'provspend-selftest.marker') === true) {
        foreach (glob($state['child'] . $sep . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($state['child']);
    }
});
$base = 'http://127.0.0.1:' . $stubPort;
$up = false;
for ($i = 0; $i < 50 && $up === false; $i++) {
    $h = @fopen($base . '/count', 'r');
    if ($h !== false) {
        fclose($h);
        $up = true;
    } else {
        usleep(100000);
    }
}
if ($up === false) {
    refuse('the stub server did not start');
}
$requests = static fn (): int => (int) trim((string) @file_get_contents($base . '/count'));
// The probe itself counted one request: take a baseline.
$zero = $requests();

// ── AI drafting ─────────────────────────────────────────────────────────────
$reset();
$setting('org.currency', 'GBP');
$fresh();
$aiSettings = ['ai_assist' => ['enabled' => '1', 'provider' => 'local', 'local' => ['baseUrl' => $base, 'model' => 'llama'], 'userDailyCap' => '20', 'monthCapPence' => '999999999']];
App::init($db, $aiSettings);
run($db, 'DELETE FROM `tblAiUsage` WHERE `siteID` = 1');
$out = AiAssistant::improve(1, PS_USER, 'announcement', 'Please come on Sunday');
check('S1 AI drafting with the free local route and nothing spent: a draft comes back, the stub was asked once, and the row records NO cost (empty, not zero)',
    $out === 'A polished draft.' && ($requests() - $zero) === 1
    && ($aiRow = rows($db, 'SELECT `costCurrency`, `costMillionths` FROM `tblAiUsage` WHERE `siteID` = 1'))[0]['costCurrency'] === null
    && $aiRow[0]['costMillionths'] === null && AiAssistant::lastRefusal() === '');
$reqs = $requests();
$setting('ai_assist.monthCapPence', '0');
$out = AiAssistant::improve(1, PS_USER, 'announcement', 'Another draft');
check('S2 a monthly figure of 0 means nothing may be spent: no draft, lastRefusal "no-budget" (a budget of nothing, NOT "reached"), and the stub was NOT asked',
    $out === null && AiAssistant::lastRefusal() === 'no-budget' && $requests() === $reqs);
$setting('ai_assist.monthCapPence', '100');
ProviderSpend::record($db, 1, 'ai', ['currency' => 'GBP', 'millionths' => 2000000]);   // £2.00 against £1.00
$out = AiAssistant::improve(1, PS_USER, 'announcement', 'A third draft');
check('S3 £2.00 spent against the installation\'s £1.00, while the MERGED settings say 999,999,999: refused — the installation\'s own row caps the organisation (D-013)',
    $out === null && AiAssistant::lastRefusal() === 'money' && $requests() === $reqs);
run($db, 'DELETE FROM `tblProviderSpend` WHERE `siteID` = 1');
$setting('org.currency', '');
$out = AiAssistant::improve(1, PS_USER, 'announcement', 'A fourth draft');
check('S4 no installation currency chosen and a figure to compare: refused with "no-currency" (an exchange rate would fix nothing, so it is NOT called no-rate), never allowed and never sent',
    $out === null && AiAssistant::lastRefusal() === 'no-currency' && $requests() === $reqs);
$setting('org.currency', 'GBP');
$setting('ai_assist.monthCapPence', '5000');
// 💱 D-192, BEFORE THE FIRST PAID CALL: pounds chosen, NO rate fetched yet (a new installation until the job
//    first runs), nothing spent, a PAID company with a key. The request must be refused for want of a rate
//    and never reach the company. (With the fault where the request asks without the company's currency, the
//    free-route check passes, the call goes out and the answer is 'provider'.)
run($db, 'DELETE FROM `tblExchangeRate`');
run($db, 'DELETE FROM `tblExchangeRateEntered`');
App::init($db, ['ai_assist' => ['enabled' => '1', 'provider' => 'anthropic', 'anthropic' => ['apiKey' => 'selftest-not-a-real-key', 'model' => 'claude-haiku-4-5'], 'userDailyCap' => '20']]);
$out = AiAssistant::improve(1, PS_USER, 'announcement', 'A paid draft');
check('S4b a PAID AI company, pounds chosen, no exchange rate yet, nothing spent: refused "no-rate" BEFORE the first paid call (D-192) — not "provider", which would mean a call was made',
    $out === null && AiAssistant::lastRefusal() === 'no-rate', 'lastRefusal ' . AiAssistant::lastRefusal());
App::init($db, $aiSettings);
$recordUsage->invoke(null, 1, PS_USER, 'announcement', 'openai', 1000, 1000, ProviderSpend::cost('ai', 'openai', 'gpt-4o-mini', ['in' => 1000, 'out' => 1000]), 'i', 'o');
check('S5 a paid call\'s usage row and the month are written from ONE cost: gpt-4o-mini 1,000/1,000 is 750 millionths of a dollar in both',
    ProviderSpend::monthTotals($db, 1, 'ai') === ['USD' => 750]
    && (int) rows($db, 'SELECT `costMillionths` AS `m` FROM `tblAiUsage` WHERE `siteID` = 1 ORDER BY `usageID` DESC LIMIT 1')[0]['m'] === 750);
run($db, 'DELETE FROM `tblAiUsage` WHERE `siteID` = 1');

// ── Translation ─────────────────────────────────────────────────────────────
$reset();
$setting('org.currency', 'GBP');
$fresh();
run($db, 'DELETE FROM `tblContentTranslation` WHERE `sourceTable` = ?', 's', ['provspend_selftest']);
run($db, 'DELETE FROM `tblTranslationUsage` WHERE `siteID` = 1');
$trSettings = ['translation' => ['enabled' => '1', 'provider' => 'libre', 'libre' => ['baseUrl' => $base, 'apiKey' => '']]];
App::init($db, $trSettings);
$reqs = $requests();
$out = Translation::translate('provspend_selftest', 1, 'body', 'en', 'cy', 'Hello everybody');
check('S6 translation through LibreTranslate (reports no cost) with room under the limit: translated, one request counted, NO money recorded, and the cached row has no cost',
    $out === 'Gair wedi ei gyfieithu' && ($requests() - $reqs) === 1 && ProviderSpend::monthTotals($db, 1, 'translation') === []
    && rows($db, 'SELECT `costCurrency` FROM `tblContentTranslation` WHERE `sourceTable` = ?', 's', ['provspend_selftest'])[0]['costCurrency'] === null);
$reqs = $requests();
$setting('translation.monthCapPence', '100');
ProviderSpend::record($db, 1, 'translation', ['currency' => 'GBP', 'millionths' => 1000000]);   // £1.00 against £1.00
$out = Translation::translate('provspend_selftest', 2, 'body', 'en', 'cy', 'Another piece');
check('S7 the month at £1.00 against a limit of £1.00 ("reached AT the figure"): refused, lastRefusal "money", and the translation company was NOT asked',
    $out === false && Translation::lastRefusal() === 'money' && $requests() === $reqs);
$setting('translation.monthCapPence', '5000');
run($db, 'DELETE FROM `tblProviderSpend` WHERE `siteID` = 1');
$setting('org.currency', '');
$out = Translation::translate('provspend_selftest', 3, 'body', 'en', 'cy', 'A third piece');
check('S8 no currency for the installation and a limit in force: refused as "no-currency" (not "no-rate": a rate would fix nothing) and nothing is sent',
    $out === false && Translation::lastRefusal() === 'no-currency' && $requests() === $reqs);
$setting('org.currency', 'GBP');
$setting('translation.monthCapPence', '0');
$out = Translation::translate('provspend_selftest', 4, 'body', 'en', 'cy', 'A fourth piece');
check('S9 a limit of 0 for translation means NO limit: translated although no currency or rate would be needed',
    $out === 'Gair wedi ei gyfieithu' && Translation::lastRefusal() === '');
$setting('translation.monthCapPence', '5000');
// settle() is the one place a paid answer is recorded: drive it with a real cost.
$settle = new ReflectionMethod(Translation::class, 'settle');
$settle->invoke(null, 1, $MONTH, ['text' => 'probe words', 'cost' => ['currency' => 'USD', 'millionths' => 1550]], 'provspend_selftest', 9, 'body', 'en', 'cy', 'anthropic', str_repeat('a', 64));
check('S10 the one place a paid translation is settled records its cost in the month (USD, 1,550) AND on the cached row',
    ProviderSpend::monthTotals($db, 1, 'translation') === ['USD' => 1550]
    && rows($db, 'SELECT `costCurrency`, `costMillionths` FROM `tblContentTranslation` WHERE `sourceTable` = ? AND `sourceID` = 9', 's', ['provspend_selftest'])[0]['costMillionths'] === 1550);
run($db, 'DELETE FROM `tblContentTranslation` WHERE `sourceTable` = ?', 's', ['provspend_selftest']);

// ── Texts ───────────────────────────────────────────────────────────────────
$reset();
$sendTwilio = new ReflectionMethod(\Portal\Core\Sms::class, 'sendTwilio');
check('S11 a text provider answers its cost as ProviderSpend\'s figure and nothing else (no sendTwilio, sendMessageBird or sendAwsSns builds a number of its own): the source carries no "costPence"',
    str_contains((string) file_get_contents($repo . $sep . 'web' . $sep . '_core' . $sep . 'Sms.php'), 'costPence') === false
    && $sendTwilio->isPrivate() === true);

// ── Transcription: a stand-in provider that REPORTS a cost ──────────────────
// (Nothing else drives processQueue() over a company that costs money: the page test's
// stand-in is the free local route, so deleting the recording of a transcription's cost
// left every other test green.) Transcription::providerTranscribe() is a protected seam
// for exactly this, as Sms::providerSend() is.
$reset();
$setting('org.currency', 'USD');
$setting('transcription.monthCapPence', '50');   // $0.50 a month
define('PORTAL_ROOT', $work . $sep . 'root');
@mkdir(PORTAL_ROOT . $sep . '_uploads' . $sep . 'recordings', 0700, true);
foreach ([993701, 993702, 993703] as $recId) {
    file_put_contents(PORTAL_ROOT . $sep . '_uploads' . $sep . 'recordings' . $sep . 'ps-' . $recId . '.mp3', 'audio');
    run($db, "INSERT INTO `tblRecording` (`recordingID`, `siteID`, `title`, `kind`, `filePath`, `mimeType`, `externalUrl`, `isPublished`, `recordedAt`) VALUES (?, 1, 'ps talk', 'sermon', ?, 'audio/mpeg', NULL, 1, '2026-10-04') "
        . 'ON DUPLICATE KEY UPDATE `filePath` = VALUES(`filePath`)', 'is', [$recId, 'ps-' . $recId . '.mp3']);
    run($db, 'DELETE FROM `tblTranscript` WHERE `recordingID` = ?', 'i', [$recId]);
    run($db, "INSERT INTO `tblTranscript` (`recordingID`, `provider`, `status`) VALUES (?, 'openai', 'queued')", 'i', [$recId]);
}
final class PsTranscriber extends \Portal\Core\Transcription
{
    /** How many times the stand-in company was really asked. */
    public static int $asked = 0;

    protected static function providerTranscribe(string $provider, array $settings, string $path, string $mime): array
    {
        self::$asked++;
        // One hour of audio from a company that charges $0.36 an hour: $0.36 a job.
        return ['ok' => true, 'text' => 'ps words', 'segments' => null, 'language' => 'en', 'duration' => 3600];
    }
}
App::init($db, ['transcription' => ['provider' => 'openai']]);
$ran = PsTranscriber::processQueue(1, 1);
$afterOne = ProviderSpend::monthTotals($db, 1, 'transcription');
check('S12 a transcription from a company that reports its audio length is PRICED and RECORDED: one hour at $0.36 an hour is 360,000 millionths in the month, and on the transcript row',
    $ran['done'] === 1 && $afterOne === ['USD' => 360000]
    && (int) rows($db, 'SELECT `costMillionths` AS `m` FROM `tblTranscript` WHERE `recordingID` = 993701')[0]['m'] === 360000
    && rows($db, 'SELECT `costCurrency` AS `c` FROM `tblTranscript` WHERE `recordingID` = 993701')[0]['c'] === 'USD', json_encode([$ran, $afterOne]));
$ran = PsTranscriber::processQueue(1, 1);
check('S13 the second job runs ($0.36 spent, under the $0.50 limit) and brings the month to 720,000',
    $ran['done'] === 1 && ProviderSpend::monthTotals($db, 1, 'transcription') === ['USD' => 720000], json_encode($ran));
$ran = PsTranscriber::processQueue(1, 1);
check('S14 the third is REFUSED at the limit ($0.72 spent against $0.50): nothing runs, it stays queued, and the answer says why (installation-limit)',
    $ran['done'] === 0 && $ran['stoppedByCap'] === true && $ran['stoppedWhy'] === 'installation-limit'
    && rows($db, "SELECT `status` AS `s` FROM `tblTranscript` WHERE `recordingID` = 993703")[0]['s'] === 'queued'
    && ProviderSpend::monthTotals($db, 1, 'transcription') === ['USD' => 720000], json_encode($ran));
// D-192 again, for a transcription job: pounds chosen, NO rate held, a paid company, a job waiting.
$reset();
$setting('org.currency', 'GBP');
$setting('transcription.monthCapPence', '5000');
$asked = PsTranscriber::$asked;
$ran = PsTranscriber::processQueue(1, 1);
check('S15 a transcription job for a PAID company with pounds chosen and no exchange rate yet is REFUSED for want of a rate before the company is asked: stoppedWhy "no-rate", the stand-in never called, the job still queued',
    $ran['done'] === 0 && $ran['stoppedWhy'] === 'no-rate' && PsTranscriber::$asked === $asked
    && rows($db, 'SELECT `status` AS `s` FROM `tblTranscript` WHERE `recordingID` = 993703')[0]['s'] === 'queued', json_encode($ran));
run($db, 'DELETE FROM `tblTranscript` WHERE `recordingID` BETWEEN 993701 AND 993703');
run($db, 'DELETE FROM `tblTranscriptSearch` WHERE `recordingID` BETWEEN 993701 AND 993703');
run($db, 'DELETE FROM `tblRecording` WHERE `recordingID` BETWEEN 993701 AND 993703');

// =============================================================================
// T. Words for a page
// =============================================================================
head('Section T — the words a page prints');
check('T1 spendWords: nothing is a dash, under a hundredth says so, a figure rounds UP to the hundredth',
    ProviderSpend::spendWords('USD', null) === '—' && ProviderSpend::spendWords('USD', 0) === '—' && ProviderSpend::spendWords(null, 500) === '—'
    && ProviderSpend::spendWords('USD', 5000) === 'less than ' . Currency::format(1, 'USD')
    && ProviderSpend::spendWords('USD', 10000) === Currency::format(1, 'USD')
    && ProviderSpend::spendWords('USD', 10001) === Currency::format(2, 'USD')
    && ProviderSpend::spendWords('USD', 1550000) === Currency::format(155, 'USD'));
$reset();
$setting('org.currency', 'GBP');
$fresh();
ProviderSpend::record($db, PS_SITE_A, 'translation', ['currency' => 'USD', 'millionths' => $usd(1)]);
$lines = ProviderSpend::pageLines(ProviderSpend::monthSummary($db, PS_SITE_A, 'translation'));
check('T2 the page lines say the spend in the company\'s own currency, the installation\'s limit in ITS currency, and what the spend counts as against it ($1.00 spent, 76 hundredths of a pound)',
    $lines['spent'] === Currency::format(100, 'USD') && count($lines['limits']) === 1
    && str_contains($lines['limits'][0], Currency::format(5000, 'GBP')) && str_contains($lines['limits'][0], Currency::format(76, 'GBP'))
    && $lines['refusal'] === '', json_encode($lines));
$setting('org.currency', '');
$lines = ProviderSpend::pageLines(ProviderSpend::monthSummary($db, PS_SITE_A, 'translation'));
check('T3 with no currency the page says so, names the fix (Admin, Settings, Organisation), and shows the figure as 50.00 and NOT as the raw 5000 hundredths',
    str_contains($lines['limits'][0], 'no currency has been chosen') === true && str_contains($lines['refusal'], 'Admin, Settings, Organisation') === true
    && str_contains($lines['limits'][0], '50.00') === true && str_contains($lines['limits'][0], '5000') === false, json_encode($lines));
$setting('org.currency', 'GBP');
run($db, 'DELETE FROM `tblExchangeRate`');
$lines = ProviderSpend::pageLines(ProviderSpend::monthSummary($db, PS_SITE_A, 'translation'));
check('T4 with no rate the page says what is stopping spending and points at Admin, Exchange rates',
    str_contains($lines['refusal'], 'Admin, Exchange rates') === true && str_contains($lines['limits'][0], 'no exchange rate') === true, json_encode($lines));
// A NEW installation: pounds chosen, no rates fetched yet, nothing spent. A real request is refused for want of a
// rate, and the page must say so too (it used to ask with no currency and show nothing wrong).
$reset();
$setting('org.currency', 'GBP');
$asked  = $why(1, 'ai', 'USD');
$pageAi = ProviderSpend::pageLines(ProviderSpend::monthSummary($db, 1, 'ai', 'anthropic'));
$pageTr = ProviderSpend::pageLines(ProviderSpend::monthSummary($db, 1, 'transcription', 'openai'));
$pageFree = ProviderSpend::pageLines(ProviderSpend::monthSummary($db, 1, 'ai', 'local'));
check('T6 a request is refused no-rate on a new installation (pounds, no rates, nothing spent), AND the AI and transcription pages say exactly that (the same question is asked), while a free route shows nothing wrong',
    $asked === 'no-rate' && $pageAi['refusal'] === ProviderSpend::refusalWords('no-rate') && $pageTr['refusal'] === ProviderSpend::refusalWords('no-rate')
    && $pageFree['refusal'] === '' && ProviderSpend::whyRefusedFor($db, 1, 'ai', 'anthropic') === $asked, json_encode([$asked, $pageAi, $pageTr]));
check('T7 the member-facing words: no-currency says to CHOOSE a currency (never "add an exchange rate"), no-rate says to add a rate, and a budget of nothing is never "reached", "used up" or "try again next month"',
    (static function () use ($repo, $sep): bool {
        $ai   = (string) file_get_contents($repo . $sep . 'web' . $sep . '_apps' . $sep . 'api' . $sep . 'ai-improve.php');
        // The arm is the one source LINE that starts with the word (each arm is one line).
        $lineOf = static function (string $word) use ($ai): string {
            foreach (explode("\n", $ai) as $line) {
                if (str_contains($line, "'" . $word . "'") === true && str_contains($line, '=>') === true) {
                    return $line;
                }
            }
            return '';
        };
        $armRate     = $lineOf('no-rate');
        $armCurrency = $lineOf('no-currency');
        $armBudget   = $lineOf('no-budget');
        $tr = \Portal\Core\ContentTranslation::refusalWords('no-currency');
        return $armRate !== '' && str_contains($armRate, 'no exchange rate') === true && str_contains($armRate, 'Admin, Exchange rates') === true
            && str_contains($armRate, 'choose') === false
            && $armCurrency !== '' && str_contains($armCurrency, 'Settings, Organisation') === true && str_contains($armCurrency, 'Exchange rates') === false
            && $armBudget !== '' && str_contains($armBudget, 'next month') === false && str_contains($armBudget, 'reached') === false && str_contains($armBudget, 'nothing') === true
            && str_contains($tr, 'Settings, Organisation') === true && str_contains($tr, 'Exchange rates') === false
            && str_contains(\Portal\Core\ContentTranslation::refusalWords('no-rate'), 'Exchange rates') === true;
    })() === true);
check('T5 a refusal word has a sentence for every word the class can answer, and "used up" is never said of a limit of nothing',
    ProviderSpend::refusalWords('') === '' && ProviderSpend::refusalWords('no-budget') !== '' && str_contains(ProviderSpend::refusalWords('no-budget'), 'used up') === false
    && ProviderSpend::refusalWords('installation-limit') !== '' && ProviderSpend::refusalWords('organisation-limit') !== ''
    && ProviderSpend::refusalWords('no-rate') !== '' && ProviderSpend::refusalWords('no-currency') !== '' && ProviderSpend::refusalWords('unreadable') !== '');

// =============================================================================
// M. Migration 302 on a database that really holds the old columns
// =============================================================================
head('Section M — migration 302 over data (the old costPence columns, a row that must survive)');
$migration = (string) file_get_contents($repo . $sep . 'web' . $sep . '_sql' . $sep . '302_provider_spend_in_its_own_currency.sql');
$runFile = static function (string $sql) use ($db): string {
    try {
        $db->multi_query($sql);
        do {
            $result = $db->store_result();
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        } while ($db->more_results() === true && $db->next_result() === true);
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return '';
};
$columnsOf = static fn (string $table): array => array_column(rows($db, 'SELECT `COLUMN_NAME` AS `c` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? ORDER BY `ORDINAL_POSITION`', 's', [$table]), 'c');
$fiveTables = ['tblAiUsage', 'tblSmsMessage', 'tblTranscript', 'tblContentTranslation', 'tblTranslationUsage'];
$gone = static function () use ($columnsOf, $fiveTables): bool {
    foreach ($fiveTables as $t) {
        if (in_array('costPence', $columnsOf($t), true) === true) {
            return false;
        }
    }
    return true;
};
check('M1 on the database this branch\'s install script made, running migration 302 changes nothing and says nothing wrong (it is safe to run again, as an installer replay does)',
    $runFile($migration) === '' && $gone() === true
    && in_array('costCurrency', $columnsOf('tblAiUsage'), true) === true && in_array('costMillionths', $columnsOf('tblAiUsage'), true) === true);

// Put the OLD shape back on two tables, with a row each carrying an old figure.
run($db, 'ALTER TABLE `tblAiUsage` DROP COLUMN `costCurrency`, DROP COLUMN `costMillionths`, ADD COLUMN `costPence` INT NOT NULL DEFAULT 0 AFTER `outputTokens`');
run($db, 'ALTER TABLE `tblTranslationUsage` ADD COLUMN `costPence` INT NOT NULL DEFAULT 0 AFTER `calls`');
run($db, 'ALTER TABLE `tblTranscript` ADD COLUMN `costPence` INT DEFAULT NULL AFTER `durationSec`');
run($db, 'INSERT INTO `tblAiUsage` (`siteID`, `userID`, `promptKind`, `provider`, `inputTokens`, `outputTokens`, `costPence`) VALUES (1, ?, ?, ?, 300, 250, 13)', 'iss', [PS_USER, 'announcement', 'anthropic']);
run($db, 'INSERT INTO `tblTranslationUsage` (`siteID`, `monthStartUtc`, `calls`, `costPence`) VALUES (?, ?, 7, 99)', 'is', [PS_SITE_A, $MONTH]);
check('M2 premise: the old shape is really back (a costPence column on three of the tables, none of the new ones on tblAiUsage), so M3 is not passing on a table that never had it',
    in_array('costPence', $columnsOf('tblAiUsage'), true) === true && in_array('costCurrency', $columnsOf('tblAiUsage'), true) === false
    && in_array('costPence', $columnsOf('tblTranslationUsage'), true) === true);
$said = $runFile($migration);
check('M3 migration 302 over the old shape: no error, costPence is gone from all five tables, and the new columns are there',
    $said === '' && $gone() === true && in_array('costCurrency', $columnsOf('tblAiUsage'), true) === true
    && in_array('costMillionths', $columnsOf('tblSmsMessage'), true) === true && in_array('costCurrency', $columnsOf('tblContentTranslation'), true) === true, $said);
$afterAi = $columnsOf('tblAiUsage');
$pos = array_search('costCurrency', $afterAi, true);
check('M4 the new columns sit where costPence sat (right after outputTokens, then costMillionths), so a fresh install and an upgraded one have the same shape',
    $pos !== false && ($afterAi[$pos - 1] ?? '') === 'outputTokens' && ($afterAi[$pos + 1] ?? '') === 'costMillionths', json_encode($afterAi));
$survivor = rows($db, 'SELECT `inputTokens`, `outputTokens`, `costCurrency`, `costMillionths` FROM `tblAiUsage` WHERE `siteID` = 1 ORDER BY `usageID` DESC LIMIT 1');
check('M5 the existing usage row survives with its tokens, and records NO cost (the old figure was in no currency and 100 times too large, so it is dropped, not converted)',
    count($survivor) === 1 && (int) $survivor[0]['inputTokens'] === 300 && (int) $survivor[0]['outputTokens'] === 250
    && $survivor[0]['costCurrency'] === null && $survivor[0]['costMillionths'] === null, json_encode($survivor));
check('M6 the month\'s request count survives (7), with the old money column gone',
    (int) rows($db, 'SELECT `calls` AS `c` FROM `tblTranslationUsage` WHERE `siteID` = ? AND `monthStartUtc` = ?', 'is', [PS_SITE_A, $MONTH])[0]['c'] === 7
    && in_array('costPence', $columnsOf('tblTranslationUsage'), true) === false);
check('M7 and a second run changes nothing (still no error)', $runFile($migration) === '' && $gone() === true);
$markers = (int) rows($db, 'SELECT COUNT(*) AS `n` FROM `tblMigrations` WHERE `filename` = ?', 's', ['302_provider_spend_in_its_own_currency.sql'])[0]['n'];
check('M8 the migration records itself, once', $markers === 1);
run($db, 'DELETE FROM `tblAiUsage` WHERE `siteID` = 1');
run($db, 'DELETE FROM `tblTranslationUsage` WHERE `siteID` = ?', 'i', [PS_SITE_A]);

// ── Put everything back ─────────────────────────────────────────────────────
$reset();
run($db, 'DELETE FROM `tblSites` WHERE `siteID` IN (?, ?)', 'ii', [PS_SITE_A, PS_SITE_B]);
run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [PS_USER]);
run($db, 'DELETE FROM `tblProviderSpend` WHERE `siteID` IN (1, ?, ?)', 'ii', [PS_SITE_A, PS_SITE_B]);

echo "\n";
echo $failed === 0 ? "ALL {$passed} CHECKS PASSED\n" : "{$failed} CHECK(S) FAILED ({$passed} passed)\n";
exit($failed === 0 ? 0 : 1);
