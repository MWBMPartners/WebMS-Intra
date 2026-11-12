<?php
// Path: tools/currency-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test: no currency is built in (#966, D-133) 💷
 * -----------------------------------------------------------------------------
 * The portal used to assume pounds sterling everywhere: four per-app settings
 * seeded GBP, nine money columns defaulting to GBP, and "£" printed before
 * amounts in 48 files. D-133 says a value that follows from a country,
 * currency named, is pre-selected from the chosen country and stays
 * changeable; nothing is built in. This test proves, over real HTTP against a
 * real database:
 *
 *   A  the rules in Portal\Core\Currency (no database): which currency a
 *      country suggests, which can be chosen (only those counted in
 *      hundredths), how an amount is written (never "£" by default);
 *   B  the schema: no currency column defaults to a real code, the four old
 *      settings gone, org.currency seeded empty, and EVERY table holding
 *      money keeps its own currency beside the amount (D-139): the tables
 *      with a `currency` column are exactly Currency::OWN_TABLES and
 *      Currency::CHILD_TABLES — FAILING BOTH WAYS, because a money table
 *      with no currency of its own would be read in today's currency, which
 *      may not be the one it was recorded in;
 *   C  an organisation in EUROS: the organisation page pre-selects the
 *      currency from the country and saves it; a project made over HTTP is
 *      stored in EUR; every money page answers in full with no pound sign —
 *      looked for in its escape forms too (\u00a3, &#163;, &#xa3;), since
 *      a script writes it that way — with a gift, a claim, a venue invoice
 *      and an asset in the world so that those money blocks really draw;
 *   D  the currency CAN change (D-139 removed the lock): what is recorded
 *      keeps its own currency, a total over two currencies is two lines, and
 *      rows recorded with no currency can be labelled by an administrator;
 *   G  an expense line quoted in another currency: the claimant types what
 *      it cost in the claim's currency, both figures are kept, the claim
 *      page shows the rate worked out from them, and a line with no
 *      converted figure is refused with nothing written;
 *   E  a payment request: checkout hands the organisation's currency to the
 *      provider step, recorded on the pending payment row; a currency no
 *      provider is sent, and no currency at all, are refused before any row;
 *   F  no currency at all: amounts are plain numbers, still never "£".
 *   N  the INSTALLATION's own country and currency (#967, #991): a global
 *      administrator changes both on the organisation page's installation
 *      card, the installation's rows change and no money row is relabelled;
 *      an older page saved untouched puts nothing back; a country or currency
 *      the portal cannot use is refused whole; text stored before (#967's
 *      "GBR") is shown and kept; the general Settings editor refuses the
 *      country; and an administrator of one organisation is neither shown
 *      the card nor allowed to post it.
 *
 * WHAT IT CANNOT SHOW, said plainly:
 *   - no request reaches Stripe or PayPal. Their addresses are written into
 *     Payments.php and there are no keys here, so E stops at the boundary:
 *     the provider is set to "gocardless", which this release refuses after
 *     the pending row is written, so the row records exactly the currency the
 *     Stripe and PayPal builders would have been handed;
 *   - the approval and treasury lists' "quoted" note over HTTP: nobody
 *     decides their own claim, so the one administrator here never sees its
 *     own claim on those lists. The claim page and the database are checked
 *     instead, and the lists' words come from one method
 *     (ExpenseRules::quotedElsewhere()), checked directly in G;
 *   - every page: it loads the main money pages, not every one of the 48.
 *
 * Run it on a THROWAWAY database built from web/_sql/full_schema.sql:
 *     SELFTEST_DB_NAME=selftest_currency [SELFTEST_DB_HOST=… SELFTEST_DB_PORT=…
 *     SELFTEST_DB_USER=… SELFTEST_DB_PASS=…] [SELFTEST_WEB_PORT=9966]
 *         php tools/currency-selftest.php
 * It needs curl and proc_open, and REFUSES rather than skipping without them.
 * It also needs performance_schema ON (`@@performance_schema = 1`, OFF by
 * default on MariaDB) and readable by SELFTEST_DB_USER: every race asks
 * performance_schema.data_lock_waits, data_locks and threads which row a
 * request is waiting on. Without it the test REFUSES before section T,
 * rather than letting each race wait out its ten seconds and report the
 * portal as at fault.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/966
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** The made-up global administrator. Nothing else in tools/ uses 9669xx. */
const CU_ADMIN = 966901;
/** A second made-up member (section L), removed at the end with the first. */
const CU_HELPER = 966902;
/** An administrator of the test's organisation ONLY (section N), removed at the end with the others. */
const CU_ORGADMIN = 966903;

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

// ── The repository's own classes ────────────────────────────────────────────
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

// =============================================================================
// A. The rules, with no database
// =============================================================================
head('Section A — what Portal\\Core\\Currency answers (no database)');
check('A1 a country suggests its own currency: the United Kingdom GBP, France EUR, the United States USD, Switzerland CHF',
    Currency::suggestFor('GB') === 'GBP' && Currency::suggestFor('FR') === 'EUR' && Currency::suggestFor('US') === 'USD' && Currency::suggestFor('CH') === 'CHF');
check('A2 a country whose currency is not counted in hundredths suggests NOTHING (Japan, Kuwait), and Antarctica, which has none, nothing',
    Currency::ofCountry('JP') === 'JPY' && Currency::suggestFor('JP') === '' && Currency::suggestFor('KW') === '' && Currency::suggestFor('AQ') === '');
check('A3 every one of the 247 countries the portal offers has an entry, and no entry suggests a currency that cannot be chosen',
    count(Currency::countryMap()) === 247 && array_filter(Currency::countryMap(), static fn (string $c): bool => $c !== '' && Currency::isChoosable($c) === false) === []
    && array_keys(Currency::countryMap()) === array_keys((new ReflectionClassConstant(\Portal\Core\Countries::class, 'NAMES'))->getValue()),
    count(Currency::countryMap()) . ' entries');
check('A4 the yen, the Kuwaiti dinar and a made-up code cannot be chosen; the euro, the pound and the Swiss franc can',
    Currency::isChoosable('JPY') === false && Currency::isChoosable('KWD') === false && Currency::isChoosable('XYZ') === false
    && Currency::isChoosable('EUR') === true && Currency::isChoosable('GBP') === true && Currency::isChoosable('CHF') === true);
check('A5 an amount is written in its own currency: £1,234.50, -€3.00 (minus first), CHF 12.00 (code, no symbol of its own)',
    Currency::format(123450, 'GBP') === '£1,234.50' && Currency::format(-300, 'EUR') === '-€3.00' && Currency::format(1200, 'CHF') === 'CHF 12.00',
    Currency::format(123450, 'GBP') . ' | ' . Currency::format(-300, 'EUR') . ' | ' . Currency::format(1200, 'CHF'));
check('A6 with NO currency known an amount is a plain number — never a pound sign — and the prefix and label are empty',
    Currency::format(1200, '') === '12.00' && Currency::prefix('') === '' && Currency::label('') === '' && Currency::formatMajor('7.5', '') === '7.50');
check('A7 a posted currency: "eur" is tidied to EUR, an empty box stays empty, the yen and a list are refused (null)',
    Currency::fromPosted('eur') === 'EUR' && Currency::fromPosted('') === '' && Currency::fromPosted('JPY') === null && Currency::fromPosted(['EUR']) === null);
check('A8 every currency a payment may be taken in can be chosen, and the list holds neither the yen nor the forint (whose decimals the providers handle differently)',
    array_filter(Currency::PAYMENT_CURRENCIES, static fn (string $c): bool => Currency::isChoosable($c) === false) === []
    && in_array('JPY', Currency::PAYMENT_CURRENCIES, true) === false && in_array('HUF', Currency::PAYMENT_CURRENCIES, true) === false);
check('A9 the general Settings editor refuses the currency key, in any capitals, and nothing else',
    Currency::lockedInGeneralEditor('org.currency') === true && Currency::lockedInGeneralEditor('ORG.Currency') === true
    && Currency::lockedInGeneralEditor('org.address.countryCode') === false);

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

// =============================================================================
// B. The schema
// =============================================================================
head('Section B — the database, as the install script built it');
$curCols = rows($db, "SELECT `TABLE_NAME` AS `t`, `COLUMN_NAME` AS `c`, `IS_NULLABLE` AS `n`, `COLUMN_DEFAULT` AS `d` FROM `information_schema`.`COLUMNS` "
    . "WHERE `TABLE_SCHEMA` = DATABASE() AND `COLUMN_NAME` IN ('currency', 'quotedCurrency')");
// '' is "not recorded" (an older row, which an administrator can label); MariaDB
// reports that default with its quotes, as "''". Anything else would be a
// currency built in.
$builtIn = array_values(array_filter($curCols, static fn (array $r): bool
    => $r['n'] !== 'NO' || ($r['d'] !== null && $r['d'] !== '' && $r['d'] !== "''")));
check('B1 no currency column defaults to a real currency: every `currency` and `quotedCurrency` column (' . count($curCols) . ') is NOT NULL with no default or the empty "not recorded" one, so a writer that forgets the currency never stores pounds',
    $builtIn === [] && count($curCols) > 9,
    'built in: ' . json_encode($builtIn));
$oldKeys = rows($db, "SELECT `settingKey` FROM `tblSettings` WHERE `settingKey` IN ('giving.currency', 'projects.currency', 'payments.currency', 'venues.currency', 'site.currency')");
$orgRow  = rows($db, "SELECT `siteID`, `settingValue`, `defaultValue` FROM `tblSettings` WHERE `settingKey` = 'org.currency'");
check('B2 the four per-app currency settings are not seeded, and org.currency is seeded once, installation-wide, EMPTY',
    $oldKeys === [] && count($orgRow) === 1 && $orgRow[0]['siteID'] === null && $orgRow[0]['settingValue'] === '' && $orgRow[0]['defaultValue'] === '',
    json_encode([$oldKeys, $orgRow]));

// Every table holding money keeps its own currency beside the amount (D-139).
// A table is money-shaped when a whole-number or decimal column's name says
// money. Each must have a `currency` column, or be named below with the reason
// it does not; and the tables with one must be exactly Currency::OWN_TABLES
// and Currency::CHILD_TABLES, which the labelling of old rows walks.
$notOrganisationMoney = [
    'tblAiUsage'            => 'the INSTALLATION\'s spending with an AI provider (one key pays for every organisation; its currency is a separate question, raised as an issue)',
    'tblContentTranslation' => 'a cache of translations; its cost is the installation\'s spending with a provider',
    'tblTranslationUsage'   => 'the installation\'s spending with a translation provider',
    'tblSmsMessage'         => 'the installation\'s spending with a text-message provider',
    'tblTranscript'         => 'the installation\'s spending with a transcription provider',
];
$moneyShaped = [];
foreach (rows($db, "SELECT `TABLE_NAME` AS `t`, `COLUMN_NAME` AS `c`, `DATA_TYPE` AS `d` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE()") as $col) {
    if (in_array($col['d'], ['int', 'bigint', 'decimal', 'mediumint', 'smallint'], true) === true
        && preg_match('/(Pence|Amount|^amount$|^cashTotal|^chequeTotal|^envelopeTotal|^payAmount|^unitCost|^lineTotal)/', (string) $col['c']) === 1) {
        $moneyShaped[(string) $col['t']] = true;
    }
}
$withCurrency = [];
foreach (rows($db, "SELECT `TABLE_NAME` AS `t` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `COLUMN_NAME` = 'currency'") as $r) {
    $withCurrency[(string) $r['t']] = true;
}
$listed = array_merge(Currency::OWN_TABLES, array_keys(Currency::CHILD_TABLES));
sort($listed);
$withCurrencyNames = array_keys($withCurrency);
sort($withCurrencyNames);
$noCurrency = array_values(array_filter(array_keys($moneyShaped), static fn (string $t): bool
    => isset($withCurrency[$t]) === false && isset($notOrganisationMoney[$t]) === false));
$staleReasons = array_values(array_filter(array_keys($notOrganisationMoney), static fn (string $t): bool
    => isset($moneyShaped[$t]) === false || isset($withCurrency[$t]) === true));
check('B3 every table holding money keeps its own currency: each money-shaped table has a `currency` column or is named here with the reason it does not, and each reason is still true (fails BOTH ways)',
    $noCurrency === [] && $staleReasons === [], 'no currency column: ' . json_encode($noCurrency) . '; reason no longer true: ' . json_encode($staleReasons));
check('B4 the tables with a `currency` column are EXACTLY Currency::OWN_TABLES and Currency::CHILD_TABLES (fails BOTH ways: a table missing there would keep rows nobody can label)',
    $listed === $withCurrencyNames,
    'with a column, not listed: ' . json_encode(array_values(array_diff($withCurrencyNames, $listed))) . '; listed, no column: ' . json_encode(array_values(array_diff($listed, $withCurrencyNames))));
$badShape = [];
foreach (Currency::OWN_TABLES as $t) {
    try {
        rows($db, 'SELECT 1 FROM `' . $t . "` WHERE `siteID` = 0 AND `currency` = '' LIMIT 1");
    } catch (Throwable $e) {
        $badShape[] = $t . ': ' . $e->getMessage();
    }
}
foreach (Currency::CHILD_TABLES as $child => [$parent, $column, $key]) {
    try {
        rows($db, 'SELECT 1 FROM `' . $child . '` `c` JOIN `' . $parent . '` `p` ON `p`.`' . $key . '` = `c`.`' . $column . "` WHERE `p`.`siteID` = 0 AND `c`.`currency` = '' LIMIT 1");
    } catch (Throwable $e) {
        $badShape[] = $child . ': ' . $e->getMessage();
    }
}
check('B5 every table in those two lists can really be walked: an own table has a siteID, and a child reaches its parent through the named column (a broken entry would make labelling fail)',
    $badShape === [], implode(' | ', $badShape));
$itemCols = array_column(rows($db, "SELECT `COLUMN_NAME` AS `c` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblExpenseClaimItems'"), 'c');
check('B6 an expense line keeps BOTH figures: its quoted currency and quoted amount beside the converted amount in the claim\'s currency',
    count(array_intersect(['currency', 'quotedCurrency', 'quotedLineTotal', 'lineTotal'], $itemCols)) === 4);

// =============================================================================
// The test server: a copy of web/, as the other page tests do
// =============================================================================
$child = sys_get_temp_dir() . $sep . 'currency-selftest-' . bin2hex(random_bytes(6));
$state['child'] = $child;
register_shutdown_function(static function () use (&$state): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_file($state['child'] . DIRECTORY_SEPARATOR . 'currency-selftest.marker') === true) {
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
file_put_contents($child . $sep . 'currency-selftest.marker', 'made by tools/currency-selftest.php');
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
    "<?php\n// Written by tools/currency-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/currency-selftest.php for ONE run. Imitates web/public_html/.htaccess.
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

$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9966;
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
    // CU_DUMP_DIR: keep each answer's body, for reading a failure by eye.
    if (getenv('CU_DUMP_DIR') !== false && is_dir((string) getenv('CU_DUMP_DIR')) === true) {
        file_put_contents((string) getenv('CU_DUMP_DIR') . '/' . $method . preg_replace('/[^A-Za-z0-9]+/', '_', $path) . '.html', substr((string) $raw, $headSize));
    }

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
$made = ['settings' => [], 'gifts' => [], 'projects' => [], 'payments' => [], 'categories' => [], 'claims' => [], 'depts' => [],
    'invoices' => [], 'venues' => [], 'assets' => [], 'campaigns' => [], 'imports' => [], 'counts' => []];
$removeAll = static function () use ($db, &$made, $site): void {
    foreach ($made['imports'] as $id) {
        run($db, 'DELETE FROM `tblBankTxns` WHERE `importID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblBankImports` WHERE `importID` = ?', 'i', [$id]);
    }
    foreach ($made['counts'] as $id) {
        run($db, "DELETE FROM `tblGivingEntry` WHERE `reference` = CONCAT('Count #', ?)", 'i', [$id]);
        run($db, 'DELETE FROM `tblCountSessions` WHERE `countSessionID` = ?', 'i', [$id]);
    }
    foreach ($made['campaigns'] as $id) {
        run($db, 'DELETE FROM `tblPledgeCampaigns` WHERE `campaignID` = ?', 'i', [$id]);
    }
    run($db, 'DELETE FROM `tblGiftAidDeclaration` WHERE `donorID` = ?', 'i', [CU_ADMIN]);
    run($db, 'DELETE FROM `tblGivingStatementLog` WHERE `donorID` = ?', 'i', [CU_ADMIN]);
    foreach ($made['invoices'] as $id) {
        run($db, 'DELETE FROM `tblVenueInvoicePayments` WHERE `invoiceID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblVenueInvoiceLines` WHERE `invoiceID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblVenueInvoices` WHERE `invoiceID` = ?', 'i', [$id]);
    }
    foreach ($made['venues'] as $id) {
        run($db, 'DELETE FROM `tblVenueBookings` WHERE `venueID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblVenueAgreements` WHERE `venueID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblVenueUsageTypes` WHERE `venueID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblVenues` WHERE `venueID` = ?', 'i', [$id]);
    }
    run($db, "DELETE FROM `tblVenueStatuses` WHERE `siteID` = ? AND `statusName` = 'Currency selftest booked'", 'i', [$site]);
    foreach ($made['assets'] as $id) {
        run($db, 'DELETE FROM `tblAssetMaintenance` WHERE `assetID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblAssets` WHERE `assetID` = ?', 'i', [$id]);
    }
    foreach (rows($db, "SELECT `assetID` FROM `tblAssets` WHERE `siteID` = ? AND `name` LIKE 'Currency selftest%'", 'i', [$site]) as $r) {
        run($db, 'DELETE FROM `tblAssetMaintenance` WHERE `assetID` = ?', 'i', [(int) $r['assetID']]);
        run($db, 'DELETE FROM `tblAssets` WHERE `assetID` = ?', 'i', [(int) $r['assetID']]);
    }
    // Claims this run made over HTTP are found by their title.
    foreach (rows($db, "SELECT `claimID` FROM `tblExpenseClaims` WHERE `userID` = ? AND `claimTitle` LIKE 'Currency selftest%'", 'i', [CU_ADMIN]) as $r) {
        $made['claims'][] = (int) $r['claimID'];
    }
    foreach ($made['payments'] as $id) {
        run($db, 'DELETE FROM `tblPayment` WHERE `paymentID` = ?', 'i', [$id]);
    }
    foreach ($made['gifts'] as $id) {
        run($db, 'DELETE FROM `tblGivingEntry` WHERE `entryID` = ?', 'i', [$id]);
    }
    foreach ($made['projects'] as $id) {
        run($db, 'DELETE FROM `tblProjectPledge` WHERE `projectID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblProject` WHERE `projectID` = ?', 'i', [$id]);
    }
    foreach (array_unique($made['claims']) as $id) {
        run($db, 'DELETE FROM `tblExpenseClaimItems` WHERE `claimID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblExpenseClaims` WHERE `claimID` = ?', 'i', [$id]);
    }
    $made['claims'] = [];
    foreach ($made['depts'] as $id) {
        run($db, 'DELETE FROM `tblDepts` WHERE `deptID` = ?', 'i', [$id]);
    }
    foreach ($made['categories'] as $id) {
        run($db, 'DELETE FROM `tblGivingCategory` WHERE `categoryID` = ?', 'i', [$id]);
    }
    run($db, "DELETE FROM `tblPayment` WHERE `userID` = ?", 'i', [CU_ADMIN]);
    run($db, "DELETE FROM `tblGivingEntry` WHERE `donorID` = ?", 'i', [CU_ADMIN]);
    run($db, 'DELETE FROM `tblLocalAccounts` WHERE `userID` = ?', 'i', [CU_ADMIN]);
    run($db, 'DELETE FROM `tblUserSites` WHERE `userID` = ?', 'i', [CU_ADMIN]);
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [CU_ADMIN]);
    run($db, 'DELETE FROM `tblAssetMaintenance` WHERE `performedByUserID` = ?', 'i', [CU_HELPER]);
    run($db, 'DELETE FROM `tblUserSites` WHERE `userID` = ?', 'i', [CU_HELPER]);
    run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [CU_HELPER]);
    run($db, 'DELETE FROM `tblLocalAccounts` WHERE `userID` = ?', 'i', [CU_ORGADMIN]);
    run($db, 'DELETE FROM `tblUserSites` WHERE `userID` = ?', 'i', [CU_ORGADMIN]);
    run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [CU_ORGADMIN]);
    run($db, "DELETE FROM `tblErrors` WHERE `errorTitle` = 'Currency selftest marker'");
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
};
$touched = "'org.currency', 'org.address.countryCode', 'giving.enabled', 'payments.enabled', 'payments.provider', 'translation.enabled', 'transcription.enabled', 'sms.enabled', 'ai_assist.enabled'";
$settingsBefore = rows($db, "SELECT * FROM `tblSettings` WHERE `settingKey` IN ({$touched})");
register_shutdown_function(static function () use ($db, $removeAll, $settingsBefore, $touched): void {
    try {
        $removeAll();
        run($db, "DELETE FROM `tblSettings` WHERE `settingKey` IN ({$touched})");
        foreach ($settingsBefore as $row) {
            run($db, 'INSERT INTO `tblSettings` (`settingID`, `siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, ?, ?, ?)',
                'iisssi', [(int) $row['settingID'], $row['siteID'], $row['settingKey'], $row['settingValue'], $row['defaultValue'], (int) $row['isSensitive']]);
        }
    } catch (Throwable $e) {
        echo "  ! the clean-up could not finish ({$e->getMessage()}).\n";
    }
});

/** Set a setting at one level (null = the installation's), replacing what is there. */
$setting = static function (string $key, string $value, ?int $level) use ($db): void {
    if ($level === null) {
        run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
    } else {
        run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?', 'si', [$key, $level]);
    }
    run($db, "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, '', 0)", 'iss', [$level, $key, $value]);
};
$ownCurrency = static function () use ($db, $site): ?string {
    $r = rows($db, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'org.currency' AND `siteID` = ?", 'i', [$site]);

    return $r === [] ? null : (string) $r[0]['settingValue'];
};

$removeAll();
$password = 'Cu' . bin2hex(random_bytes(8)) . '!9x';
run($db, 'INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, ?, 1, 1, 1, NOW())',
    'iss', [CU_ADMIN, 'Selftest currency', 'currency@selftest.invalid']);
run($db, 'INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES (?, ?, 1)', 'ii', [$site, CU_ADMIN]);
run($db, 'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)',
    'iss', [CU_ADMIN, 'selftest-currency', password_hash($password, PASSWORD_DEFAULT)]);
$token = $tokenOn($http('GET', '/login')['body']);
$in    = $http('POST', '/login', ['csrf_token' => $token, 'username' => 'selftest-currency', 'password' => $password]);
if ($in['status'] !== 302 && $in['status'] !== 303) {
    refuse("signing in did not answer a redirect (HTTP {$in['status']})");
}
$freshToken = static function (string $path) use ($http, $tokenOn): string {
    return $tokenOn($http('GET', $path)['body']);
};

// Giving and Payments are switched off on a new installation; the pages below need them on.
$setting('giving.enabled', '1', null);
$setting('payments.enabled', '1', null);
// And the four that show the installation's spending with a provider.
foreach (['translation.enabled', 'transcription.enabled', 'sms.enabled', 'ai_assist.enabled'] as $switch) {
    $setting($switch, '1', null);
}

// =============================================================================
// C. An organisation in euros
// =============================================================================
head('Section C — an organisation in France, choosing euros');
$setting('org.address.countryCode', 'FR', null);
$orgPage = $http('GET', '/admin/settings/organisation');
$select  = preg_match('/<select[^>]*id="currency"[^>]*>(.*?)<\/select>/s', $orgPage['body'], $m) === 1 ? $m[0] : '';
check('C1 the organisation page offers a currency box, and with NO currency in force pre-selects the one France suggests, EUR, and says it was suggested (D-133)',
    $orgPage['status'] === 200 && str_contains($select, '<option value="EUR" selected>') === true && substr_count($select, ' selected') === 1
    && str_contains($select, ' disabled') === false && str_contains($orgPage['body'], 'Suggested from the country') === true,
    'HTTP ' . $orgPage['status'] . '; ' . mb_substr($select, 0, 200));
$saved = $http('POST', '/admin/settings/organisation/save', ['csrf_token' => $tokenOn($orgPage['body']), 'currency' => 'EUR', 'countryCode' => '']);
check('C2 saving it stores EUR as the organisation\'s OWN currency (the installation\'s stays empty)',
    $saved['status'] === 302 && $ownCurrency() === 'EUR' && Currency::ofInstallation() === '',
    'HTTP ' . $saved['status'] . '; own row: ' . var_export($ownCurrency(), true));
$advice = $http('GET', '/admin/settings/organisation')['body'];
check('C3 with EUR in force the page now prefills the organisation\'s own row and pre-selects nothing else; a France/EUR organisation gets no "disagrees" advice',
    preg_match('/<select[^>]*id="currency"[^>]*>.*?<option value="EUR" selected>/s', $advice) === 1 && str_contains($advice, 'data-currency-advice') === false);

// A project made over HTTP: its INSERT used to name no currency, so it was GBP by the column default.
$projToken = $freshToken('/projects/manage');
$made_before = (int) (rows($db, 'SELECT COALESCE(MAX(`projectID`), 0) AS `m` FROM `tblProject`')[0]['m']);
$projSave = $http('POST', '/projects/manage-save', ['csrf_token' => $projToken, 'title' => 'Currency selftest roof', 'targetAmount' => '1500.00', 'status' => 'active', 'isPublic' => '1', 'description' => 'x']);
$proj = rows($db, 'SELECT `projectID`, `slug`, `currency` FROM `tblProject` WHERE `projectID` > ? AND `siteID` = ?', 'ii', [$made_before, $site]);
foreach ($proj as $p) {
    $made['projects'][] = (int) $p['projectID'];
}
check('C4 a project made over HTTP is stored in the organisation\'s currency, EUR (it used to be GBP by the column default, whatever the setting said)',
    count($proj) === 1 && $proj[0]['currency'] === 'EUR', 'HTTP ' . $projSave['status'] . '; rows: ' . json_encode($proj));

// Money recorded by hand: a gift in EUR, so the pages have an amount to show.
run($db, "INSERT INTO `tblGivingCategory` (`siteID`, `name`, `isActive`) VALUES (?, 'Currency selftest fund', 1)", 'i', [$site]);
$category = (int) $db->insert_id;
$made['categories'][] = $category;
run($db, "INSERT INTO `tblGivingEntry` (`siteID`, `donorID`, `categoryID`, `amountPence`, `currency`, `donatedAt`, `method`) VALUES (?, ?, ?, 123450, 'EUR', CURDATE(), 'cash')",
    'iii', [$site, CU_ADMIN, $category]);
$made['gifts'][] = (int) $db->insert_id;
// And an expense claim, in EUR. Without one, Admin, Reports draws no total, and a pound
// sign put back there passed every check (planted, 11 November 2026).
// A NAMED department: the claim's PDF prints the name, and a claim filed over HTTP in G
// is refused whole when that print fails on an empty name.
run($db, "INSERT INTO `tblDepts` (`siteID`, `deptName`) VALUES (?, 'Currency selftest department')", 'i', [$site]);
$dept = (int) $db->insert_id;
$made['depts'][] = $dept;
run($db, "INSERT INTO `tblExpenseClaims` (`siteID`, `userID`, `deptID`, `claimTitle`, `claimDate`, `totalAmount`, `status`, `currency`) VALUES (?, ?, ?, 'Currency selftest claim', CURDATE(), 250.00, 'Pending', 'EUR')",
    'iii', [$site, CU_ADMIN, $dept]);
$made['claims'][] = (int) $db->insert_id;
// A pledge campaign in EUR. Section D edits it after the organisation moves to
// dollars: an edit must not relabel it (round 3 of #966's check).
run($db, "INSERT INTO `tblPledgeCampaigns` (`siteID`, `name`, `goalAmountPence`, `currency`, `startDate`) VALUES (?, 'Currency selftest roof fund', 500000, 'EUR', CURDATE())", 'i', [$site]);
$campaign = (int) $db->insert_id;
$made['campaigns'][] = $campaign;
// A venue invoice (part paid) and an asset, both in EUR. Without them, Venues, invoices
// and the asset value report draw no amount at all, so a pound sign put back in their
// money blocks could not be seen (the round-1 check found both).
run($db, "INSERT INTO `tblVenues` (`siteID`, `venueName`, `countryCode`, `timezone`) VALUES (?, 'Currency selftest hall', 'FR', 'Europe/Paris')", 'i', [$site]);
$venue = (int) $db->insert_id;
$made['venues'][] = $venue;
run($db, "INSERT INTO `tblVenueInvoices` (`siteID`, `venueID`, `issueDate`, `amountPence`, `currency`, `status`) VALUES (?, ?, CURDATE(), 40000, 'EUR', 'part-paid')", 'ii', [$site, $venue]);
$invoice = (int) $db->insert_id;
$made['invoices'][] = $invoice;
run($db, "INSERT INTO `tblVenueInvoicePayments` (`siteID`, `invoiceID`, `paidDate`, `amountPence`, `currency`) VALUES (?, ?, CURDATE(), 10000, 'EUR')", 'ii', [$site, $invoice]);
run($db, "INSERT INTO `tblAssets` (`siteID`, `name`, `currency`, `publicToken`, `purchaseCostPence`, `currentValuePence`) VALUES (?, 'Currency selftest piano', 'EUR', ?, 89999, 89999)",
    'is', [$site, bin2hex(random_bytes(16))]);
$made['assets'][] = (int) $db->insert_id;
// And a pledge to the project, not yet paid: Projects, manage lists it, in the PROJECT's
// currency (that list spans every project, so there is no one project to ask).
if (isset($proj[0]['projectID']) === true) {
    run($db, "INSERT INTO `tblProjectPledge` (`projectID`, `donorID`, `amountPence`, `currency`) VALUES (?, ?, 7700, 'EUR')", 'ii', [(int) $proj[0]['projectID'], CU_ADMIN]);
}

$pages = [
    '/giving', '/giving/manage', '/giving/reports', '/giving/campaigns', '/giving/categories', '/giving/statements',
    '/projects', '/projects/view?slug=' . urlencode((string) ($proj[0]['slug'] ?? '')), '/projects/manage',
    '/payments', '/expenses/submit', '/expenses/approve', '/expenses/treasury',
    '/venues/invoices', '/venues/agreements', '/resources', '/resources/manage',
    '/assets/value-report', '/admin/reports', '/admin/translation', '/admin/transcription', '/admin/ai-assist', '/admin/sms',
    '/account/recurring', '/projects/my-pledges', '/giving/give',
];
/** Does a page show a pound sign, written any way a page or its script could write it? */
$hasPound = static function (string $body): bool {
    return str_contains($body, '£') === true
        || preg_match('/&pound;|&#163;|&#x0*a3;|\\\\u00a3|\\\\x\\{?a3\\}?/i', $body) === 1;
};
$pound = [];
$broken = [];
foreach ($pages as $path) {
    $answer = $http('GET', $path);
    if ($answer['status'] !== 200 || str_contains($answer['body'], '</html>') === false) {
        $broken[] = $path . ' (HTTP ' . $answer['status'] . ')';
        continue;
    }
    if ($hasPound($answer['body']) === true) {
        // Gift Aid is a UK tax scheme, stated in pounds by law, and only its own page says so.
        $pound[] = $path;
    }
}
check('C5 every money page loaded answers in full (HTTP 200, the page finished), as a global administrator', $broken === [], 'not whole: ' . implode(', ', $broken));
check('C6 and NOT ONE of them prints a pound sign for an organisation in euros, as the sign, an entity or a script escape (' . count($pages) . ' pages)', $pound === [], 'a pound sign on: ' . implode(', ', $pound));
$invoicesPage = $http('GET', '/venues/invoices')['body'];
$valuePage    = $http('GET', '/assets/value-report')['body'];
check('C10 the money blocks C6 searched really drew: Venues, invoices bills €400.00 with €100.00 paid and €300.00 outstanding, and the asset value report shows the piano\'s EUR 899.99 (that report writes the code, as the asset pages do)',
    preg_match('/data-total="billed">€400\\.00</', $invoicesPage) === 1 && preg_match('/data-total="paid">€100\\.00</', $invoicesPage) === 1
    && preg_match('/data-total="outstanding">€300\\.00</', $invoicesPage) === 1 && str_contains($valuePage, 'EUR 899.99') === true,
    'invoices: ' . (preg_match_all('/data-total="[a-z]+">[^<]*</', $invoicesPage, $mm) > 0 ? implode(' ', $mm[0]) : '(no totals)') . '; value report has EUR 899.99: ' . var_export(str_contains($valuePage, 'EUR 899.99'), true));
$manage   = $http('GET', '/giving/manage')['body'];
$projects = $http('GET', '/projects')['body'];
check('C7 where an amount is shown it is in euros: the gift as €1,234.50 on Giving, manage, and the project\'s target in € on Projects',
    str_contains($manage, '€1,234.50') === true && preg_match('/€1,500\.00/', $projects) === 1,
    'manage has €1,234.50: ' . var_export(str_contains($manage, '€1,234.50'), true) . '; projects has €1,500.00: ' . var_export(preg_match('/€1,500\.00/', $projects) === 1, true));

$pledges = $http('GET', '/projects/manage')['body'];
check('C9 the pledge list on Projects, manage shows the unpaid pledge in its own currency, €77.00',
    str_contains($pledges, '€77.00') === true && str_contains($pledges, '£') === false);
$reports  = $http('GET', '/admin/reports')['body'];
$ownList  = $http('GET', '/expenses/submit')['body'];
check('C8 an expense claim is shown in its own currency: Reports\' total value is €250.00, and so is the claim on its claimant\'s own list',
    str_contains($reports, 'Total value: <strong>€250.00</strong>') === true && str_contains($ownList, '€250.00') === true,
    'reports: ' . (preg_match('/Total value: <strong>[^<]*<\/strong>/', $reports, $mm) === 1 ? $mm[0] : '(no total)') . '; own list has €250.00: ' . var_export(str_contains($ownList, '€250.00'), true));

// =============================================================================
// D. The currency can change; what is recorded keeps its own (D-139)
// =============================================================================
head('Section D — the currency can change, and every record keeps the currency it was made in');
$open = $http('GET', '/admin/settings/organisation');
check('D1 with money recorded the currency box is still OPEN, and says a change affects new records only (D-139 removed the lock)',
    preg_match('/<select[^>]*id="currency"[^>]*>/', $open['body'], $mm) === 1 && str_contains($mm[0], 'disabled') === false
    && str_contains($open['body'], 'Changing it affects new records only') === true && str_contains($open['body'], 'cannot be changed') === false);
$changed = $http('POST', '/admin/settings/organisation/save', ['csrf_token' => $tokenOn($open['body']), 'currency' => 'USD', 'countryCode' => '']);
check('D2 a posted change to USD is saved', $changed['status'] === 302 && $ownCurrency() === 'USD', 'own row: ' . var_export($ownCurrency(), true));
$manageUsd = $http('GET', '/giving/manage')['body'];
$ownUsd    = $http('GET', '/expenses/submit')['body'];
$valueUsd  = $http('GET', '/assets/value-report')['body'];
check('D3 and every record made before keeps its own currency: the gift is still €1,234.50, the claim still €250.00, the invoice still bills €400.00, and the value report (which shows one currency at a time) still finds the piano in EUR',
    str_contains($manageUsd, '€1,234.50') === true && str_contains($ownUsd, '€250.00') === true
    && preg_match('/data-total="billed">€400\.00</', $http('GET', '/venues/invoices')['body']) === 1 && str_contains($valueUsd, 'EUR 899.99') === true);
$campaignEdit = $http('POST', '/giving/campaign-save', ['csrf_token' => $freshToken('/giving/campaigns'), 'campaignID' => (string) $campaign,
    'name' => 'Currency selftest roof fund, renamed', 'goalAmount' => '6000.00', 'startDate' => date('Y-m-d'), 'isActive' => '1']);
$keptRows = rows($db, "SELECT 'gift' AS `what`, `currency` FROM `tblGivingEntry` WHERE `entryID` = ? "
    . "UNION ALL SELECT 'campaign', `currency` FROM `tblPledgeCampaigns` WHERE `campaignID` = ? "
    . "UNION ALL SELECT 'claim', `currency` FROM `tblExpenseClaims` WHERE `claimTitle` = 'Currency selftest claim'", 'ii', [$made['gifts'][0], $campaign]);
$campaignName = rows($db, 'SELECT `name`, `goalAmountPence` FROM `tblPledgeCampaigns` WHERE `campaignID` = ?', 'i', [$campaign]);
check('D3b a pledge campaign EDITED OVER HTTP after the change (its name and goal really saved) is still EUR, and so are the gift and claim rows read straight from the database (round 3 of the check: the campaign used to be rewritten in today\'s currency)',
    $campaignEdit['status'] === 302 && ($campaignName[0]['goalAmountPence'] ?? 0) === 600000
    && array_column($keptRows, 'currency', 'what') === ['gift' => 'EUR', 'campaign' => 'EUR', 'claim' => 'EUR'],
    json_encode([$keptRows, $campaignName]));
// D3c: the project and the expense claim made in EUR, each EDITED over HTTP while the
// organisation is in USD. Round 4 of the check planted today's currency into both
// edits and 63 of 63 stayed green, because nothing here edited either.
$projEdit = $http('POST', '/projects/manage-save', ['csrf_token' => $freshToken('/projects/manage'), 'projectID' => (string) $proj[0]['projectID'],
    'title' => 'Currency selftest roof, edited', 'targetAmount' => '1500.00', 'status' => 'active', 'isPublic' => '1', 'description' => 'x']);
$projAfter = rows($db, 'SELECT `title`, `currency` FROM `tblProject` WHERE `projectID` = ?', 'i', [(int) $proj[0]['projectID']]);
$eurClaim = (int) (rows($db, "SELECT `claimID` FROM `tblExpenseClaims` WHERE `claimTitle` = 'Currency selftest claim'")[0]['claimID'] ?? 0);
$claimEdit = $http('POST', '/expenses/edit/save', ['csrf_token' => $freshToken('/expenses/edit?id=' . $eurClaim), 'claimID' => (string) $eurClaim,
    'claimTitle' => 'Currency selftest claim, edited', 'deptID' => (string) $dept,
    'itemDesc' => ['Hymn books'], 'itemQty' => ['1'], 'itemUnit' => ['250.00'], 'itemCurrency' => ['EUR'], 'itemConverted' => ['']]);
$claimAfter = rows($db, 'SELECT `claimTitle`, `currency`, `totalAmount` FROM `tblExpenseClaims` WHERE `claimID` = ?', 'i', [$eurClaim]);
$claimLinesAfter = rows($db, 'SELECT `currency`, `quotedCurrency`, `lineTotal` FROM `tblExpenseClaimItems` WHERE `claimID` = ?', 'i', [$eurClaim]);
check('D3c a project and an expense claim, each EDITED OVER HTTP after the change (title saved), are still EUR — and so is the edited claim\'s line',
    ($projAfter[0]['title'] ?? '') === 'Currency selftest roof, edited' && ($projAfter[0]['currency'] ?? '') === 'EUR'
    && ($claimAfter[0]['claimTitle'] ?? '') === 'Currency selftest claim, edited' && ($claimAfter[0]['currency'] ?? '') === 'EUR'
    && (float) ($claimAfter[0]['totalAmount'] ?? 0) === 250.00
    && count($claimLinesAfter) === 1 && $claimLinesAfter[0]['currency'] === 'EUR' && $claimLinesAfter[0]['quotedCurrency'] === 'EUR',
    'HTTP ' . $projEdit['status'] . '/' . $claimEdit['status'] . '; ' . json_encode([$projAfter, $claimAfter, $claimLinesAfter]));
$madeBefore = (int) (rows($db, 'SELECT COALESCE(MAX(`projectID`), 0) AS `m` FROM `tblProject`')[0]['m']);
$http('POST', '/projects/manage-save', ['csrf_token' => $freshToken('/projects/manage'), 'title' => 'Currency selftest organ', 'targetAmount' => '900.00', 'status' => 'active', 'isPublic' => '1', 'description' => 'x']);
$projUsd = rows($db, 'SELECT `projectID`, `currency` FROM `tblProject` WHERE `projectID` > ? AND `siteID` = ?', 'ii', [$madeBefore, $site]);
foreach ($projUsd as $p) {
    $made['projects'][] = (int) $p['projectID'];
}
check('D4 a project made AFTER the change is stored in USD', count($projUsd) === 1 && $projUsd[0]['currency'] === 'USD', json_encode($projUsd));

// Money in two currencies: a second claim, gift and invoice in USD (the gift was
// promised by this comment in round 2 and not made; round 3 makes it).
run($db, "INSERT INTO `tblGivingEntry` (`siteID`, `donorID`, `categoryID`, `amountPence`, `currency`, `donatedAt`, `method`) VALUES (?, ?, ?, 5000, 'USD', CURDATE(), 'cash')",
    'iii', [$site, CU_ADMIN, $category]);
$made['gifts'][] = (int) $db->insert_id;
run($db, "INSERT INTO `tblExpenseClaims` (`siteID`, `userID`, `deptID`, `claimTitle`, `claimDate`, `totalAmount`, `status`, `currency`) VALUES (?, ?, ?, 'Currency selftest claim in dollars', CURDATE(), 40.00, 'Pending', 'USD')",
    'iii', [$site, CU_ADMIN, $dept]);
$made['claims'][] = (int) $db->insert_id;
run($db, "INSERT INTO `tblVenueInvoices` (`siteID`, `venueID`, `issueDate`, `amountPence`, `currency`, `status`) VALUES (?, ?, CURDATE(), 5000, 'USD', 'pending')", 'ii', [$site, $venue]);
$made['invoices'][] = (int) $db->insert_id;
$reportsTwo  = $http('GET', '/admin/reports')['body'];
$invoicesTwo = $http('GET', '/venues/invoices')['body'];
check('D5 a total over two currencies is TWO lines, never one sum: Reports\' total value is €250.00 and $40.00, and Venues, invoices bills €400.00 and $50.00',
    preg_match('/Total value: <strong>€250\.00<br>\$40\.00<\/strong>/', $reportsTwo) === 1
    && preg_match('/data-total="billed">€400\.00<br>\$50\.00</', $invoicesTwo) === 1,
    'reports: ' . (preg_match('/Total value: <strong>.*?<\/strong>/', $reportsTwo, $mm) === 1 ? $mm[0] : '(none)')
    . '; invoices: ' . (preg_match('/data-total="billed">[^<]*(<br>[^<]*)?</', $invoicesTwo, $mm) === 1 ? $mm[0] : '(none)'));
$givingReportsTwo = $http('GET', '/giving/reports')['body'];
$manageTwo        = $http('GET', '/giving/manage')['body'];
check('D5b and the giving pages too: this month on Giving, reports is €1,234.50 and $50.00 (two lines), and the Manage total says €1,234.50 and $50.00',
    preg_match('/' . preg_quote(date('Y-m'), '/') . '<\/div>\s*<div[^>]*>\d+<\/div>\s*<div[^>]*><strong>€1,234\.50<br>\$50\.00<\/strong>/', $givingReportsTwo) === 1
    && str_contains($manageTwo, 'Total: €1,234.50 and $50.00') === true,
    'reports month row: ' . (preg_match('/' . preg_quote(date('Y-m'), '/') . '<\/div>.*?<\/strong>/s', $givingReportsTwo, $mm) === 1 ? preg_replace('/\s+/', ' ', $mm[0]) : '(none)')
    . '; manage: ' . (preg_match('/Total: [^<]*/', $manageTwo, $mm) === 1 ? $mm[0] : '(none)'));

// A record with NO currency (as an older database may hold): it can be labelled.
run($db, "INSERT INTO `tblGivingEntry` (`siteID`, `donorID`, `categoryID`, `amountPence`, `currency`, `donatedAt`, `method`) VALUES (?, ?, ?, 2000, '', CURDATE(), 'cash')",
    'iii', [$site, CU_ADMIN, $category]);
$unlabelledGift = (int) $db->insert_id;
$made['gifts'][] = $unlabelledGift;
$labelPage = $http('GET', '/admin/settings/organisation');
check('D6 the organisation page says how many amounts have no recorded currency (1) and offers to label them',
    preg_match('/data-currency-unlabelled="1"/', $labelPage['body']) === 1 && str_contains($labelPage['body'], 'name="labelCurrency"') === true
    && Currency::unlabelledCount($db, $site) === 1);
$labelled = $http('POST', '/admin/settings/organisation/save', ['csrf_token' => $tokenOn($labelPage['body']), 'form' => 'label-currency', 'labelCurrency' => 'EUR']);
$afterLabel = rows($db, 'SELECT `currency` FROM `tblGivingEntry` WHERE `entryID` = ?', 'i', [$unlabelledGift]);
// The rows recorded in DOLLARS are the ones that show whether labelling touched
// anything but the unlabelled: a row already in EUR would read the same either way.
$dollarsAfter = array_column(rows($db, "SELECT `currency` FROM `tblExpenseClaims` WHERE `claimTitle` = 'Currency selftest claim in dollars' "
    . "UNION ALL SELECT `currency` FROM `tblVenueInvoices` WHERE `invoiceID` = ?", 'i', [end($made['invoices'])]), 'currency');
check('D7 labelling them EUR writes EUR on that row ONLY: nothing is left unlabelled, the claim and the invoice recorded in dollars are still USD, and the organisation\'s own currency is still USD',
    $labelled['status'] === 302 && ($afterLabel[0]['currency'] ?? '') === 'EUR' && Currency::unlabelledCount($db, $site) === 0 && $ownCurrency() === 'USD'
    && $dollarsAfter === ['USD', 'USD']
    && str_contains($http('GET', '/admin/settings/organisation')['body'], 'data-currency-unlabelled') === false,
    json_encode([$afterLabel, $dollarsAfter]));
$badLabel = $http('POST', '/admin/settings/organisation/save', ['csrf_token' => $freshToken('/admin/settings/organisation'), 'form' => 'label-currency', 'labelCurrency' => 'JPY']);
check('D8 a currency the portal cannot use (the yen) is refused as a label', $badLabel['status'] === 302 && Currency::labelUnlabelled($db, $site, 'JPY') === null);
// Back to EUR, which E below relies on.
$back = $http('POST', '/admin/settings/organisation/save', ['csrf_token' => $freshToken('/admin/settings/organisation'), 'currency' => 'EUR', 'countryCode' => '']);
check('D9 and it can change back to EUR', $back['status'] === 302 && $ownCurrency() === 'EUR');

// =============================================================================
// G. An expense line quoted in another currency (D-139)
// =============================================================================
head('Section G — a claim with a line bought in dollars');
$claimForm = $http('GET', '/expenses/submit');
check('G1 the claim form asks which currency each line is quoted in, and offers the box for the cost in EUR from the statement',
    str_contains($claimForm['body'], 'name="itemCurrency[]"') === true && str_contains($claimForm['body'], 'name="itemConverted[]"') === true);
$claimPost = static fn (string $title, string $converted): array => [
    'csrf_token' => $tokenOn($http('GET', '/expenses/submit')['body']), 'deptID' => (string) $dept, 'claimTitle' => $title,
    'itemDesc' => ['Train ticket', 'Hotel in New York'], 'itemQty' => ['1', '2'], 'itemUnit' => ['10.00', '60.00'],
    'itemCurrency' => ['EUR', 'USD'], 'itemConverted' => ['', $converted],
];
$http('POST', '/expenses/submit/save', $claimPost('Currency selftest no conversion', ''));
$noConversion = rows($db, "SELECT 1 FROM `tblExpenseClaims` WHERE `claimTitle` = 'Currency selftest no conversion'");
$refusedPage  = $http('GET', '/expenses/submit')['body'];
check('G2 a dollar line with no cost in EUR is REFUSED, nothing is written, and the claimant is told what to type',
    $noConversion === [] && str_contains($refusedPage, 'type what it cost in EUR') === true);
$http('POST', '/expenses/submit/save', $claimPost('Currency selftest abroad', '101.10'));
$abroad = rows($db, "SELECT `claimID`, `currency`, `totalAmount` FROM `tblExpenseClaims` WHERE `claimTitle` = 'Currency selftest abroad'");
$lines  = $abroad === [] ? [] : rows($db, 'SELECT `itemName`, `unitCost`, `lineTotal`, `currency`, `quotedCurrency`, `quotedLineTotal` FROM `tblExpenseClaimItems` WHERE `claimID` = ? ORDER BY `itemID`', 'i', [(int) $abroad[0]['claimID']]);
check('G3 the claim is stored in EUR with the CONVERTED total, 111.10 (10.00 + 101.10), never the quoted 130',
    count($abroad) === 1 && $abroad[0]['currency'] === 'EUR' && (float) $abroad[0]['totalAmount'] === 111.10, json_encode($abroad));
check('G4 the dollar line keeps BOTH figures: quoted $120.00 (2 x 60.00) in USD, and 101.10 in EUR; the euro line is quoted and converted alike',
    count($lines) === 2
    && $lines[0]['quotedCurrency'] === 'EUR' && (float) $lines[0]['quotedLineTotal'] === 10.00 && (float) $lines[0]['lineTotal'] === 10.00 && $lines[0]['currency'] === 'EUR'
    && $lines[1]['quotedCurrency'] === 'USD' && (float) $lines[1]['quotedLineTotal'] === 120.00 && (float) $lines[1]['lineTotal'] === 101.10 && $lines[1]['currency'] === 'EUR',
    json_encode($lines));
$claimPage = $abroad === [] ? '' : $http('GET', '/expenses/view?id=' . (int) $abroad[0]['claimID'])['body'];
check('G5 the claim page shows both figures and the rate worked out from them: "$120.00 quoted; 1 USD = 0.8425 EUR", with the total €111.10',
    str_contains($claimPage, '$120.00 quoted;') === true && str_contains($claimPage, '1 USD = 0.8425 EUR') === true && str_contains($claimPage, '€111.10') === true,
    'quoted note: ' . (preg_match('/data-line-quoted>\s*(.*?)<\/small>/s', $claimPage, $mm) === 1 ? trim(preg_replace('/\s+/', ' ', $mm[1])) : '(none)'));
$quotedWords = $abroad === [] ? [] : \Portal\Core\ExpenseRules::quotedElsewhere($db, [(int) $abroad[0]['claimID']]);
check('G6 the approval and treasury lists\' note for it (ExpenseRules::quotedElsewhere()) reads "$120.00 quoted", and names no euro line',
    $abroad !== [] && ($quotedWords[(int) $abroad[0]['claimID']] ?? []) === ['$120.00 quoted'], json_encode($quotedWords));

// =============================================================================
// E. A payment request carries the currency
// =============================================================================
head('Section E — a payment is taken in the organisation\'s currency');
$setting('payments.provider', 'gocardless', null);
$before = (int) (rows($db, 'SELECT COALESCE(MAX(`paymentID`), 0) AS `m` FROM `tblPayment`')[0]['m']);
$pay = $http('POST', '/payments/checkout', ['csrf_token' => $freshToken('/giving/give'), 'amount' => '5.00', 'purpose' => 'other', 'return_to' => '/giving/give']);
$payRows = rows($db, 'SELECT `paymentID`, `currency`, `amountPence`, `status`, `errorMsg` FROM `tblPayment` WHERE `paymentID` > ?', 'i', [$before]);
foreach ($payRows as $r) {
    $made['payments'][] = (int) $r['paymentID'];
}
check('E1 checkout hands the provider step EUR: the pending payment row, written just before Stripe or PayPal would be called, says EUR for 500 (no request leaves this machine: the provider here is one this release refuses)',
    count($payRows) === 1 && $payRows[0]['currency'] === 'EUR' && (int) $payRows[0]['amountPence'] === 500 && $payRows[0]['errorMsg'] === 'gocardless-not-implemented',
    'HTTP ' . $pay['status'] . '; ' . json_encode($payRows));
// A pledge to the dollar project from D4, while the organisation is in euros: it is
// charged in the PLEDGE's own currency (round 3 of the check: this was not driven).
run($db, "INSERT INTO `tblProjectPledge` (`projectID`, `donorID`, `amountPence`, `currency`) VALUES (?, ?, 900, 'USD')", 'ii', [(int) ($projUsd[0]['projectID'] ?? 0), CU_ADMIN]);
$usdPledge = (int) $db->insert_id;
$before = (int) (rows($db, 'SELECT COALESCE(MAX(`paymentID`), 0) AS `m` FROM `tblPayment`')[0]['m']);
$pledgePay = $http('POST', '/payments/checkout', ['csrf_token' => $freshToken('/projects/my-pledges'), 'amount' => '1.00', 'purpose' => 'pledge', 'purposeRef' => (string) $usdPledge, 'return_to' => '/projects/my-pledges']);
$pledgeRows = rows($db, 'SELECT `paymentID`, `currency`, `amountPence` FROM `tblPayment` WHERE `paymentID` > ?', 'i', [$before]);
foreach ($pledgeRows as $r) {
    $made['payments'][] = (int) $r['paymentID'];
}
check('E1b a pledge recorded in dollars is charged in DOLLARS, for its own 900, although the organisation is in euros (the posted amount is ignored)',
    count($pledgeRows) === 1 && $pledgeRows[0]['currency'] === 'USD' && (int) $pledgeRows[0]['amountPence'] === 900,
    'HTTP ' . $pledgePay['status'] . '; ' . json_encode($pledgeRows));
// A currency the portal may record but does not send to a provider (made by hand: the page would refuse the change now).
$setting('org.currency', 'NGN', $site);
$before = (int) (rows($db, 'SELECT COALESCE(MAX(`paymentID`), 0) AS `m` FROM `tblPayment`')[0]['m']);
$ngn = $http('POST', '/payments/checkout', ['csrf_token' => $freshToken('/giving/give'), 'amount' => '5.00', 'purpose' => 'other', 'return_to' => '/giving/give']);
$ngnFlash = $http('GET', '/giving/give')['body'];
check('E2 a currency outside the providers\' list (Nigerian naira) is refused BEFORE any payment row is written, in words',
    rows($db, 'SELECT 1 FROM `tblPayment` WHERE `paymentID` > ?', 'i', [$before]) === [] && str_contains($ngnFlash, 'Payments cannot be taken in NGN online.') === true,
    'HTTP ' . $ngn['status']);
check('E3 and the payments page says so beside the currency it names',
    str_contains($http('GET', '/payments')['body'], 'Online payments cannot be taken in NGN') === true);

// =============================================================================
// F. No currency at all
// =============================================================================
head('Section F — an organisation with no currency chosen');
run($db, "DELETE FROM `tblSettings` WHERE `settingKey` = 'org.currency' AND `siteID` = ?", 'i', [$site]);
$before  = (int) (rows($db, 'SELECT COALESCE(MAX(`paymentID`), 0) AS `m` FROM `tblPayment`')[0]['m']);
$none    = $http('POST', '/payments/checkout', ['csrf_token' => $freshToken('/giving/give'), 'amount' => '5.00', 'purpose' => 'other', 'return_to' => '/giving/give']);
$noneMsg = $http('GET', '/giving/give')['body'];
check('F1 with no currency chosen a payment is refused before any row is written (it used to be charged in pounds)',
    rows($db, 'SELECT 1 FROM `tblPayment` WHERE `paymentID` > ?', 'i', [$before]) === [] && str_contains($noneMsg, 'no currency is chosen for this organisation') === true,
    'HTTP ' . $none['status']);
$plainPound = [];
foreach (['/giving/manage', '/expenses/submit', '/resources/manage', '/admin/reports', '/payments'] as $path) {
    $body = $http('GET', $path)['body'];
    if (str_contains($body, '£') === true || str_contains($body, '&pound;') === true) {
        $plainPound[] = $path;
    }
}
$manageNone = $http('GET', '/giving/manage')['body'];
check('F2 a gift that stored its OWN currency is still shown in it (€1,234.50): a record keeps the currency it was made in',
    str_contains($manageNone, '€1,234.50') === true);
check('F3 and with no currency known, no page prints a pound sign', $plainPound === [], implode(', ', $plainPound));
$noneOrg = $http('GET', '/admin/settings/organisation')['body'];
check('F4 the organisation page says no currency is chosen: the box is offered again (France would suggest EUR), since none is in force to relabel',
    preg_match('/<select[^>]*id="currency"[^>]*>/', $noneOrg, $mm) === 1 && str_contains($mm[0], 'disabled') === false
    && str_contains($noneOrg, '<option value="EUR" selected>') === true);

// =============================================================================
// H. Round 3 of the check: Gift Aid, reconciliation, and a parent's currency
// =============================================================================
head('Section H — Gift Aid in pounds only, a count matched only in its own currency, a parent keeps its currency');
$setting('org.currency', 'EUR', $site);
// Gift Aid: the admin is a declared donor, with gifts in EUR and USD above and one in pounds now.
run($db, "INSERT INTO `tblGiftAidDeclaration` (`siteID`, `donorID`, `status`, `validFrom`, `address`, `postcode`) VALUES (?, ?, 'active', DATE_SUB(CURDATE(), INTERVAL 1 YEAR), '7 Selftest Road', 'AB1 2CD')",
    'ii', [$site, CU_ADMIN]);
run($db, "INSERT INTO `tblGivingEntry` (`siteID`, `donorID`, `categoryID`, `amountPence`, `currency`, `donatedAt`, `method`) VALUES (?, ?, ?, 3000, 'GBP', CURDATE(), 'cash')",
    'iii', [$site, CU_ADMIN, $category]);
$made['gifts'][] = (int) $db->insert_id;
// And one with NO recorded currency: it must be left out of the claim (it is not
// known to be pounds) and COUNTED in the note (round 4 of the check).
run($db, "INSERT INTO `tblGivingEntry` (`siteID`, `donorID`, `categoryID`, `amountPence`, `currency`, `donatedAt`, `method`) VALUES (?, ?, ?, 4100, '', CURDATE(), 'cash')",
    'iii', [$site, CU_ADMIN, $category]);
$made['gifts'][] = (int) $db->insert_id;
$heldByAdmin = rows($db, "SELECT `currency`, COUNT(*) AS `n` FROM `tblGivingEntry` WHERE `siteID` = ? AND `donorID` = ? GROUP BY `currency`", 'ii', [$site, CU_ADMIN]);
$notPounds   = array_sum(array_map(static fn (array $r): int => $r['currency'] === 'GBP' ? 0 : (int) $r['n'], $heldByAdmin));
$claimFile = $http('GET', '/giving/hmrc-export?from=' . date('Y-01-01') . '&to=' . date('Y-12-31'));
$claimRows = array_values(array_filter(explode("\n", trim($claimFile['body'])), static fn (string $l): bool => str_contains($l, '7 Selftest Road')));
check('H1 the HMRC claim file holds the gift in POUNDS (30.00) and none of this donor\'s gifts in euros, dollars or with NO recorded currency (' . $notPounds . ' of them; the 41.00 with none is not taken as pounds)',
    $claimFile['status'] === 200 && count($claimRows) === 1 && str_ends_with(trim($claimRows[0]), ',30.00') === true && $notPounds >= 3
    && str_contains($claimFile['body'], '41.00') === false,
    'HTTP ' . $claimFile['status'] . '; rows: ' . json_encode($claimRows));
$leftOutPage = $http('GET', '/giving/manage?from=' . date('Y-01-01') . '&to=' . date('Y-12-31'))['body'];
check('H2 and Manage giving says how many it left out — the unrecorded one included — and why, naming both kinds',
    preg_match('/data-hmrc-left-out="(\d+)"/', $leftOutPage, $mm) === 1 && (int) $mm[1] === $notPounds
    && str_contains(preg_replace('/\s+/', ' ', $leftOutPage), 'recorded in another currency than pounds, or with no currency recorded. Gift Aid is a UK scheme claimed in pounds') === true,
    'note: ' . ($mm[1] ?? '(none)') . ', expected ' . $notPounds);

// ── #999: two overlapping declarations, and a statement's Gift Aid figure ──
// ⚠️ ONE GIFT, ONE ROW. hmrcRows() joined EVERY active declaration covering a
//    gift's date, so a donor with two overlapping declarations put the same
//    gift in the claim file twice — claimed twice from HMRC — and Manage
//    giving's "left out" count counted their other-currency gifts twice. A
//    second declaration, started more recently and still open, overlaps the
//    first for every gift above. The claim file must hold the pounds gift
//    ONCE, under the NEWER declaration's address (the one the donor most
//    recently gave), and the left-out count must not move.
run($db, "INSERT INTO `tblGiftAidDeclaration` (`siteID`, `donorID`, `status`, `validFrom`, `address`, `postcode`) VALUES (?, ?, 'active', DATE_SUB(CURDATE(), INTERVAL 6 MONTH), '9 Newer Lane', 'AB1 9ZZ')",
    'ii', [$site, CU_ADMIN]);
$claimFile2 = $http('GET', '/giving/hmrc-export?from=' . date('Y-01-01') . '&to=' . date('Y-12-31'));
$donorRows  = array_values(array_filter(explode("\n", trim($claimFile2['body'])),
    static fn (string $l): bool => str_contains($l, '7 Selftest Road') === true || str_contains($l, '9 Newer Lane') === true));
check('H2b with TWO overlapping active declarations the claim file still holds the pounds gift ONCE (#999), under the newer declaration\'s address',
    $claimFile2['status'] === 200 && count($donorRows) === 1 && str_contains($donorRows[0], '9 Newer Lane') === true
    && str_ends_with(trim($donorRows[0]), ',30.00') === true,
    'HTTP ' . $claimFile2['status'] . '; rows: ' . json_encode($donorRows));
$leftOutPage2 = $http('GET', '/giving/manage?from=' . date('Y-01-01') . '&to=' . date('Y-12-31'))['body'];
check('H2c and Manage giving\'s "left out" count is still one per gift (' . $notPounds . '), not one per declaration',
    preg_match('/data-hmrc-left-out="(\d+)"/', $leftOutPage2, $mm) === 1 && (int) $mm[1] === $notPounds,
    'note: ' . ($mm[1] ?? '(none)') . ', expected ' . $notPounds);
// ⚠️ A STATEMENT'S "GIFT AID ELIGIBLE" IS POUNDS ONLY (#999). It used to add
//    every currency's gifts covered by a declaration, so a donor who gave in
//    euros was told part of it was Gift Aid eligible — money the claim file
//    (pounds only) can never claim. The statements page shows each donor's
//    eligible figure from the same rule the statement document and its log
//    row use (Giving::giftAidEligibleSql()); this donor gave in euros, dollars,
//    pounds and none, so only the 30.00 in pounds may appear there.
$statementsPage = html_entity_decode($http('GET', '/giving/statements?from=' . date('Y-01-01') . '&to=' . date('Y-12-31'))['body'], ENT_QUOTES, 'UTF-8');
preg_match_all('/Gift Aid: ([^<]*)</u', $statementsPage, $gaFigures);
$gaFigures = array_map('trim', $gaFigures[1] ?? []);
check('H2d the statements page gives this donor a Gift Aid figure of £30.00 — the pounds gift only — and no figure anywhere in another currency',
    in_array('£30.00', $gaFigures, true) === true
    && array_filter($gaFigures, static fn (string $f): bool => preg_match('/€|\$|[A-Z]{3} /u', $f) === 1) === [],
    json_encode($gaFigures));
// The statement DOCUMENT is a PDF made by dompdf, which this test copy does not
// ship, so its words are read from the method that writes them (private, so by
// reflection: the real method, never a copy of it — #647).
$totalsHtml = new ReflectionMethod(\Portal\Core\Giving::class, 'statementTotalsHtml');
$docWords   = (string) $totalsHtml->invoke(null, ['EUR' => 12000, 'GBP' => 3000, '' => 4100], ['GBP' => 3000], 'EUR',
    static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
$docWords = html_entity_decode(strip_tags(str_replace('</p>', "\n", $docWords)), ENT_QUOTES, 'UTF-8');
check('H2e the statement document names a Gift Aid figure only beside the POUNDS total, and says beside the euro total, and beside gifts with NO currency recorded, that Gift Aid applies only to gifts recorded in pounds sterling',
    str_contains($docWords, 'Total given: £30.00 · of which Gift Aid eligible: £30.00') === true
    && str_contains($docWords, 'Total given: €120.00 · Gift Aid applies only to gifts recorded in pounds sterling') === true
    && str_contains($docWords, 'Total given: 41.00 · Gift Aid applies only to gifts recorded in pounds sterling') === true
    && preg_match('/eligible: (€|41\.00)/u', $docWords) !== 1,
    $docWords);
// The ONE eligibility rule itself (Giving::giftAidEligibleSql(), private, so by
// reflection), asked of the real database over this donor's real gifts: it
// decides the statement document's per-gift "Yes", its eligible total, and the
// statements preview and log's `giftAidPence`. A planted rule that dropped the
// pounds condition left H2d green, because the preview also keeps only a
// pounds total for display — so the rule is asked here directly, and must
// count the 30.00 in pounds and nothing in euros, dollars or none.
$eligibleSql = new ReflectionMethod(\Portal\Core\Giving::class, 'giftAidEligibleSql');
$eligible    = rows($db, 'SELECT COALESCE(SUM(CASE WHEN ' . (string) $eligibleSql->invoke(null, '`e`') . ' THEN `e`.`amountPence` ELSE 0 END), 0) AS `p`, '
    . 'COUNT(*) AS `n` FROM `tblGivingEntry` `e` WHERE `e`.`siteID` = ? AND `e`.`donorID` = ?', 'ii', [$site, CU_ADMIN]);
check('H2f the one eligibility rule counts only this donor\'s gift in POUNDS (3000 hundredths) of their ' . (int) ($eligible[0]['n'] ?? 0) . ' gifts, though two declarations cover them all',
    (int) ($eligible[0]['p'] ?? -1) === 3000 && (int) ($eligible[0]['n'] ?? 0) >= 4, json_encode($eligible));
// ⚠️ H2f proves the RULE; nothing proved its two USERS ask it. Round 2 of #999's
//    check put the statement's per-gift query and the preview's SUM(CASE …)
//    back to the old EXISTS with no pounds condition, one at a time, and every
//    check above stayed green while a euro gift showed "Yes" on the statement
//    and a euro-only donor's giftAidPence was stored non-zero. So the CALLS are
//    pinned here (a presence pin, which round 3 showed a dead call fools — H2i
//    drives the statement), counted with PHP's own tokeniser over each function's body
//    (comments and strings are their own tokens, so a comment naming the rule
//    cannot stand in for calling it), and H11b drives the preview's stored
//    figure for a euro-only donor.
$callsIn = static function (string $file, string $function, string $callee): int {
    $tokens = array_values(array_filter(token_get_all((string) file_get_contents($file)),
        static fn ($t): bool => is_array($t) === false || in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false));
    $count = 0;
    for ($i = 0; $i < count($tokens) - 2; $i++) {
        if (is_array($tokens[$i]) === false || $tokens[$i][0] !== T_FUNCTION
            || is_array($tokens[$i + 1]) === false || $tokens[$i + 1][1] !== $function) {
            continue;
        }
        $depth = 0;
        $open  = false;
        for ($j = $i + 2; $j < count($tokens); $j++) {
            $t = $tokens[$j];
            $text = is_array($t) === true ? $t[1] : $t;
            if ($text === '{' || (is_array($t) === true && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) === true)) {
                $depth++;
                $open = true;
            } elseif ($text === '}') {
                $depth--;
                if ($open === true && $depth === 0) {
                    break;
                }
            }
            if ($open === true && is_array($t) === true && $t[0] === T_STRING && $t[1] === 'self'
                && ($tokens[$j + 1][0] ?? null) === T_DOUBLE_COLON
                && is_array($tokens[$j + 2] ?? null) === true && $tokens[$j + 2][1] === $callee
                && ($tokens[$j + 3] ?? null) === '(') {
                $count++;
            }
        }
    }

    return $count;
};
// The claim file's PHP filter must answer as the SQL rule does: the table's
// collation compares `currency = 'GBP'` without regard to capitals, and a CHAR
// column ignores trailing spaces, so "gbp" and "GBP " count there (round 2).
$isGbp = new ReflectionMethod(\Portal\Core\Giving::class, 'isGiftAidCurrency');
check('H2h the claim file\'s pounds test counts "GBP", "gbp" and "GBP " as pounds, as the SQL rule does, and "EUR" and "" as not',
    [$isGbp->invoke(null, 'GBP'), $isGbp->invoke(null, 'gbp'), $isGbp->invoke(null, 'GBP '), $isGbp->invoke(null, 'EUR'), $isGbp->invoke(null, '')]
        === [true, true, true, false, false]);
// ⚠️ H2g COUNTS CALLS, AND A DEAD CALL FOOLS IT (round 3 of #999's check):
//    the old EXISTS put back in the statement's query beside
//    `if (false) { self::giftAidEligibleSql('e'); }` left every check here
//    green while a euro gift showed "Yes" again. D-065: where the property is
//    "this code RUNS", drive it. H2i calls the REAL statement builder,
//    Giving::statementHtml() — the text renderStatementPdf() turns into the
//    PDF, split out so it can be read without dompdf — for this donor, who
//    has active declarations and gifts in pounds, euros, dollars and none, and
//    reads the "Gift Aid" cell of every line. The class reaches the database
//    through App::db(), which this test sets for the one call and puts back.
$appDb = new ReflectionProperty(\Portal\Core\App::class, 'db');
$appDbBefore = $appDb->getValue();
$appDb->setValue(null, $db);
try {
    $statementHtml = \Portal\Core\Giving::statementHtml($site, CU_ADMIN, date('Y-01-01'), date('Y-12-31'), 'H2i');
} finally {
    $appDb->setValue(null, $appDbBefore);
}
$giftAidCells = [];
if (is_string($statementHtml) === true
    && preg_match_all('#<td class="center">([^<]*)</td><td class="right">([^<]*)</td>#u', $statementHtml, $cells, PREG_SET_ORDER) > 0) {
    foreach ($cells as $cell) {
        $giftAidCells[] = [html_entity_decode($cell[2], ENT_QUOTES, 'UTF-8'), $cell[1]];
    }
}
$poundsLines = array_values(array_filter($giftAidCells, static fn (array $c): bool => str_starts_with($c[0], '£')));
$otherLines  = array_values(array_filter($giftAidCells, static fn (array $c): bool => str_starts_with($c[0], '£') === false));
check('H2i the REAL statement (Giving::statementHtml()) marks this donor\'s pounds gift "Yes" for Gift Aid and every gift in euros, dollars or with no currency "–", though declarations cover them all',
    $poundsLines !== [] && $otherLines !== [] && count(array_filter($otherLines, static fn (array $c): bool => str_contains($c[0], '€'))) > 0
    && array_filter($poundsLines, static fn (array $c): bool => $c[1] !== 'Yes') === []
    && array_filter($otherLines, static fn (array $c): bool => $c[1] !== '–') === [],
    json_encode($giftAidCells, JSON_UNESCAPED_UNICODE));
// ⚠️ NOTHING HERE CAN RUN renderStatementPdf() (it ends in dompdf, which no test
//    copy ships), so nothing proved it hands Pdf::create() the text
//    statementHtml() built. Round 3 of #999's check kept
//    `$html = self::statementHtml(...)` and then overwrote $html with a private
//    copy using the old EXISTS: currency, gdpr-coverage and the eraser test all
//    stayed green. Pinning a pattern would be the cleverer-pattern trap this
//    repository has paid for (#485, #763); the method is small, so its WHOLE
//    body is pinned as text — PHP's tokeniser, comments dropped, every run of
//    whitespace one space. ANY change to it fails here on purpose: change the
//    method, check it still makes the PDF from statementHtml()'s text and
//    nothing else, then update the pinned text below.
$wholeBody = static function (string $file, string $function): string {
    $tokens = token_get_all((string) file_get_contents($file));
    $out = '';
    for ($i = 0; $i < count($tokens); $i++) {
        if (is_array($tokens[$i]) === false || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $k = $i + 1;
        while (is_array($tokens[$k]) === true && $tokens[$k][0] === T_WHITESPACE) {
            $k++;
        }
        if (is_array($tokens[$k]) === false || $tokens[$k][1] !== $function) {
            continue;
        }
        $depth = 0;
        for ($j = $k; $j < count($tokens); $j++) {
            $t = $tokens[$j];
            if (is_array($t) === true && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
                continue;
            }
            $text = is_array($t) === true ? $t[1] : $t;
            $out .= (is_array($t) === true && $t[0] === T_WHITESPACE) ? ' ' : $text;
            if ($text === '{' || (is_array($t) === true && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) === true)) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        break;
    }

    return trim((string) preg_replace('/\s+/', ' ', $out));
};
$pinnedRender = 'renderStatementPdf(int $siteId, int $donorId, string $fromDate, string $toDate, string $periodLabel): string|false { '
    . '$html = self::statementHtml($siteId, $donorId, $fromDate, $toDate, $periodLabel); if ($html === false) { return false; } '
    . '$periodKey = self::statementPeriodKey($fromDate, $toDate); '
    . "\$dir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving' . DIRECTORY_SEPARATOR . 'statements' . DIRECTORY_SEPARATOR . (string) \$siteId; "
    . 'if (is_dir($dir) === false) { mkdir($dir, 0755, true); } '
    . "\$path = \$dir . DIRECTORY_SEPARATOR . 'statement-' . \$donorId . '-' . \$periodKey . '.pdf'; "
    . 'return Pdf::create($html, $path); }';
$renderNow = $wholeBody($repo . $sep . 'web' . $sep . '_core' . $sep . 'Giving.php', 'renderStatementPdf');
check('H2k renderStatementPdf() is EXACTLY the pinned text: it makes the PDF from statementHtml()\'s text and nothing else (nothing here can run it — no dompdf — so its whole body is pinned; any change fails here on purpose: re-check it, then update the pin)',
    $renderNow === $pinnedRender, $renderNow);
// The claim file and the left-out count must treat a gift stored as "gbp" as
// pounds, as the SQL rule does (round 2's isGiftAidCurrency()). Nothing the
// portal writes stores lower case, so the row is written straight to the
// table; it is removed again at once.
run($db, "INSERT INTO `tblGivingEntry` (`siteID`, `donorID`, `categoryID`, `amountPence`, `currency`, `donatedAt`, `method`) VALUES (?, ?, ?, 777, 'gbp', CURDATE(), 'cash')",
    'iii', [$site, CU_ADMIN, $category]);
$lowerGift = (int) $db->insert_id;
$made['gifts'][] = $lowerGift;
$claimFile3 = $http('GET', '/giving/hmrc-export?from=' . date('Y-01-01') . '&to=' . date('Y-12-31'));
$leftOutPage3 = $http('GET', '/giving/manage?from=' . date('Y-01-01') . '&to=' . date('Y-12-31'))['body'];
run($db, 'DELETE FROM `tblGivingEntry` WHERE `entryID` = ?', 'i', [$lowerGift]);
check('H2j a gift stored as "gbp" IS in the claim file (7.77) and is NOT counted as left out (' . $notPounds . ' still) — the claim file and the count ask the same pounds test as the SQL',
    $claimFile3['status'] === 200 && preg_match('/,7\.77\s*$/m', $claimFile3['body']) === 1
    && preg_match('/data-hmrc-left-out="(\d+)"/', $leftOutPage3, $mm) === 1 && (int) $mm[1] === $notPounds,
    'HTTP ' . $claimFile3['status'] . '; left out: ' . ($mm[1] ?? '(none)'));
$givingFile = $repo . $sep . 'web' . $sep . '_core' . $sep . 'Giving.php';
// A presence pin only: a dead call satisfies it (round 3). The guards are H2i
// (the statement, driven) and H11b (the preview, driven).
check('H2g the statement builder (statementHtml) and the statements preview (statementsPreview) each contain one call to Giving::giftAidEligibleSql() — presence only; H2i and H11b drive them',
    [$callsIn($givingFile, 'statementHtml', 'giftAidEligibleSql'), $callsIn($givingFile, 'statementsPreview', 'giftAidEligibleSql')] === [1, 1],
    json_encode([$callsIn($givingFile, 'statementHtml', 'giftAidEligibleSql'), $callsIn($givingFile, 'statementsPreview', 'giftAidEligibleSql')]));

// Reconciliation: a statement in EUR, a closed count in USD and one in EUR.
run($db, "INSERT INTO `tblBankImports` (`siteID`, `filename`, `currency`, `fileHash`) VALUES (?, 'selftest.csv', 'EUR', ?)", 'is', [$site, bin2hex(random_bytes(32))]);
$import = (int) $db->insert_id;
$made['imports'][] = $import;
run($db, "INSERT INTO `tblBankTxns` (`importID`, `siteID`, `txnDate`, `amountPence`, `currency`, `description`) VALUES (?, ?, CURDATE(), 1000, 'EUR', 'Currency selftest deposit')", 'ii', [$import, $site]);
$txn = (int) $db->insert_id;
$countIn = static function (string $code) use ($db, $site, $category, &$made): int {
    run($db, "INSERT INTO `tblCountSessions` (`siteID`, `serviceDate`, `categoryID`, `currency`, `status`) VALUES (?, CURDATE(), ?, ?, 'closed')", 'iis', [$site, $category, $code]);
    $id = (int) $db->insert_id;
    $made['counts'][] = $id;
    return $id;
};
$usdCount = $countIn('USD');
$eurCount = $countIn('EUR');
$http('POST', '/giving/reconcile/match', ['csrf_token' => $freshToken('/giving/reconcile/view?id=' . $import), 'action' => 'match-session', 'txnID' => (string) $txn, 'countSessionID' => (string) $usdCount]);
$afterUsd = rows($db, 'SELECT `matchStatus`, `matchedCountSessionID` FROM `tblBankTxns` WHERE `txnID` = ?', 'i', [$txn]);
check('H3 a posted match of a EURO bank line to a DOLLAR count is REFUSED by the server: the line stays unmatched',
    ($afterUsd[0]['matchStatus'] ?? '') === 'unmatched' && $afterUsd[0]['matchedCountSessionID'] === null, json_encode($afterUsd));
$http('POST', '/giving/reconcile/match', ['csrf_token' => $freshToken('/giving/reconcile/view?id=' . $import), 'action' => 'match-session', 'txnID' => (string) $txn, 'countSessionID' => (string) $eurCount]);
$afterEur = rows($db, 'SELECT `matchStatus`, `matchedCountSessionID` FROM `tblBankTxns` WHERE `txnID` = ?', 'i', [$txn]);
check('H4 ...while a count in euros matches it (so H3 refused for the currency, not for another reason)',
    ($afterEur[0]['matchStatus'] ?? '') === 'matched' && (int) $afterEur[0]['matchedCountSessionID'] === $eurCount, json_encode($afterEur));

// The manual GIFT match: a euro bank line may be matched to a euro gift only.
run($db, "INSERT INTO `tblBankTxns` (`importID`, `siteID`, `txnDate`, `amountPence`, `currency`, `description`) VALUES (?, ?, CURDATE(), 7777, 'EUR', 'Currency selftest gift line')", 'ii', [$import, $site]);
$giftTxn = (int) $db->insert_id;
$usdGift = (int) (rows($db, "SELECT `entryID` FROM `tblGivingEntry` WHERE `siteID` = ? AND `donorID` = ? AND `currency` = 'USD' LIMIT 1", 'ii', [$site, CU_ADMIN])[0]['entryID'] ?? 0);
$http('POST', '/giving/reconcile/match', ['csrf_token' => $freshToken('/giving/reconcile/view?id=' . $import), 'action' => 'match-entry', 'txnID' => (string) $giftTxn, 'entryID' => (string) $usdGift]);
$giftAfterUsd = rows($db, 'SELECT `matchStatus`, `matchedEntryID` FROM `tblBankTxns` WHERE `txnID` = ?', 'i', [$giftTxn]);
$http('POST', '/giving/reconcile/match', ['csrf_token' => $freshToken('/giving/reconcile/view?id=' . $import), 'action' => 'match-entry', 'txnID' => (string) $giftTxn, 'entryID' => (string) $made['gifts'][0]]);
$giftAfterEur = rows($db, 'SELECT `matchStatus`, `matchedEntryID` FROM `tblBankTxns` WHERE `txnID` = ?', 'i', [$giftTxn]);
check('H4b a posted match of a EURO bank line to a DOLLAR gift is refused by the server, while the euro gift then matches it',
    $usdGift > 0 && ($giftAfterUsd[0]['matchStatus'] ?? '') === 'unmatched' && ($giftAfterEur[0]['matchStatus'] ?? '') === 'matched'
    && (int) $giftAfterEur[0]['matchedEntryID'] === $made['gifts'][0], json_encode([$giftAfterUsd, $giftAfterEur]));
// The AUTOMATIC match (the "Re-run" button): a line of 33.33 has only a DOLLAR count of
// that amount, a line of 44.44 a EURO one; only the second may match.
$countOf = static function (string $code, string $cash) use ($db, $site, $category, &$made): int {
    run($db, "INSERT INTO `tblCountSessions` (`siteID`, `serviceDate`, `categoryID`, `currency`, `status`, `cashTotal`, `chequeTotal`, `envelopeTotal`) VALUES (?, CURDATE(), ?, ?, 'closed', ?, 0, 0)",
        'iiss', [$site, $category, $code, $cash]);
    $id = (int) $db->insert_id;
    $made['counts'][] = $id;
    return $id;
};
run($db, "INSERT INTO `tblBankTxns` (`importID`, `siteID`, `txnDate`, `amountPence`, `currency`, `description`) VALUES (?, ?, CURDATE(), 3333, 'EUR', 'Currency selftest auto one')", 'ii', [$import, $site]);
$autoOne = (int) $db->insert_id;
run($db, "INSERT INTO `tblBankTxns` (`importID`, `siteID`, `txnDate`, `amountPence`, `currency`, `description`) VALUES (?, ?, CURDATE(), 4444, 'EUR', 'Currency selftest auto two')", 'ii', [$import, $site]);
$autoTwo = (int) $db->insert_id;
$countOf('USD', '33.33');
$eurAuto = $countOf('EUR', '44.44');
$http('POST', '/giving/reconcile/match', ['csrf_token' => $freshToken('/giving/reconcile/view?id=' . $import), 'action' => 'rematch', 'importID' => (string) $import]);
$auto = rows($db, 'SELECT `txnID`, `matchStatus`, `matchedCountSessionID` FROM `tblBankTxns` WHERE `txnID` IN (?, ?) ORDER BY `txnID`', 'ii', [$autoOne, $autoTwo]);
check('H4c the automatic match leaves a euro line whose only same-amount count is in DOLLARS unmatched, and matches the one with a EURO count',
    count($auto) === 2 && $auto[0]['matchStatus'] === 'unmatched' && $auto[1]['matchStatus'] === 'matched' && (int) $auto[1]['matchedCountSessionID'] === $eurAuto,
    json_encode($auto));

// An asset: an unusable currency is refused; a change is refused once a repair is recorded.
$piano = (int) $made['assets'][0];
$assetPost = static fn (int $id, string $name, string $code): array => ['csrf_token' => $freshToken('/assets/edit?id=' . $id), 'assetID' => (string) $id, 'name' => $name, 'currency' => $code];
$assetCurrency = static fn (int $id): string => (string) (rows($db, 'SELECT `currency` FROM `tblAssets` WHERE `assetID` = ?', 'i', [$id])[0]['currency'] ?? '');
$http('POST', '/assets/save', $assetPost($piano, 'Currency selftest piano', 'JPY'));
$jpyFlash = $http('GET', '/assets/edit?id=' . $piano)['body'];
check('H5 an asset posted with a currency the portal cannot use (the yen) is REFUSED, not quietly given the organisation\'s: still EUR, and the page says so',
    $assetCurrency($piano) === 'EUR' && str_contains($jpyFlash, 'The currency is not one the portal can use. Nothing was saved.') === true);
run($db, "INSERT INTO `tblAssetMaintenance` (`siteID`, `assetID`, `title`, `costPence`, `currency`) VALUES (?, ?, 'Currency selftest tuning', 4500, 'EUR')", 'ii', [$site, $piano]);
$http('POST', '/assets/save', $assetPost($piano, 'Currency selftest piano', 'USD'));
$usdFlash = $http('GET', '/assets/edit?id=' . $piano)['body'];
check('H6 with a repair recorded in EUR the piano\'s currency cannot change to USD: still EUR, and the page says why',
    $assetCurrency($piano) === 'EUR' && str_contains($usdFlash, 'repair or valuation record(s) in another currency than USD') === true);
run($db, "INSERT INTO `tblAssets` (`siteID`, `name`, `currency`, `publicToken`, `purchaseCostPence`) VALUES (?, 'Currency selftest chair', 'EUR', ?, 1000)", 'is', [$site, bin2hex(random_bytes(16))]);
$chair = (int) $db->insert_id;
$made['assets'][] = $chair;
$http('POST', '/assets/save', $assetPost($chair, 'Currency selftest chair', 'USD'));
check('H7 ...while an asset with no repairs or valuations CAN change (so H6 refused for the repair, not for another reason)', $assetCurrency($chair) === 'USD', $assetCurrency($chair));
// The parent rule counts a child in ANY other currency, an UNRECORDED one included
// (round 5 of the check: reverting the rule to round 3's "children in the CURRENT
// currency" left 68 of 68 green, because every child above was in EUR).
$assetWith = static function (string $name, string $own, string $childCurrency) use ($db, $site, &$made): int {
    run($db, "INSERT INTO `tblAssets` (`siteID`, `name`, `currency`, `publicToken`) VALUES (?, ?, ?, ?)", 'isss', [$site, $name, $own, bin2hex(random_bytes(16))]);
    $id = (int) $db->insert_id;
    $made['assets'][] = $id;
    run($db, "INSERT INTO `tblAssetMaintenance` (`siteID`, `assetID`, `title`, `costPence`, `currency`) VALUES (?, ?, 'Currency selftest service', 1500, ?)", 'iis', [$site, $id, $childCurrency]);
    return $id;
};
$recordedOverUnrecorded = $assetWith('Currency selftest lectern', 'EUR', '');
$http('POST', '/assets/save', $assetPost($recordedOverUnrecorded, 'Currency selftest lectern', 'USD'));
$unrecordedOverUnrecorded = $assetWith('Currency selftest hymn board', '', '');
$http('POST', '/assets/save', $assetPost($unrecordedOverUnrecorded, 'Currency selftest hymn board', 'EUR'));
check('H7b an asset in EUR with an UNRECORDED repair cannot change to USD, and an UNRECORDED asset with an unrecorded repair cannot be given EUR: in both, a child would disagree with its parent',
    $assetCurrency($recordedOverUnrecorded) === 'EUR' && $assetCurrency($unrecordedOverUnrecorded) === '',
    var_export([$assetCurrency($recordedOverUnrecorded), $assetCurrency($unrecordedOverUnrecorded)], true));

// A venue invoice: its currency cannot change once a payment is recorded in it.
$paidInvoice = (int) $made['invoices'][0];
$invoicePost = static fn (int $id, string $code): array => ['csrf_token' => $freshToken('/venues/invoice?id=' . $id), 'invoiceID' => (string) $id,
    'venueID' => (string) $venue, 'issueDate' => date('Y-m-d'), 'amount' => '400.00', 'currency' => $code];
$invoiceCurrency = static fn (int $id): string => (string) (rows($db, 'SELECT `currency` FROM `tblVenueInvoices` WHERE `invoiceID` = ?', 'i', [$id])[0]['currency'] ?? '');
$http('POST', '/venues/invoice-save', $invoicePost($paidInvoice, 'USD'));
check('H8 an invoice with a payment recorded in EUR cannot change to USD', $invoiceCurrency($paidInvoice) === 'EUR', $invoiceCurrency($paidInvoice));
$unpaidInvoice = (int) $made['invoices'][1];
$http('POST', '/venues/invoice-save', $invoicePost($unpaidInvoice, 'EUR'));
check('H9 ...while an invoice with no lines or payments CAN (so H8 refused for the payment)', $invoiceCurrency($unpaidInvoice) === 'EUR', $invoiceCurrency($unpaidInvoice));
run($db, "INSERT INTO `tblVenueInvoices` (`siteID`, `venueID`, `issueDate`, `amountPence`, `currency`, `status`) VALUES (?, ?, CURDATE(), 3000, 'EUR', 'part-paid')", 'ii', [$site, $venue]);
$eurOverUnrecorded = (int) $db->insert_id;
$made['invoices'][] = $eurOverUnrecorded;
run($db, "INSERT INTO `tblVenueInvoicePayments` (`siteID`, `invoiceID`, `paidDate`, `amountPence`, `currency`) VALUES (?, ?, CURDATE(), 500, '')", 'ii', [$site, $eurOverUnrecorded]);
$http('POST', '/venues/invoice-save', array_merge($invoicePost($eurOverUnrecorded, 'USD'), ['amount' => '30.00']));
check('H9b an invoice in EUR with an UNRECORDED payment cannot change to USD (round 3 counted only payments in EUR and let it)',
    $invoiceCurrency($eurOverUnrecorded) === 'EUR', $invoiceCurrency($eurOverUnrecorded));
// The organisation moves to dollars first, so "keep" and "take today's" give different answers.
$setting('org.currency', 'USD', $site);
// The UNPAID one, now EUR: a paid invoice would be held by H8's refusal anyway,
// so it could not show whether "keep" itself works (planted, it did not).
$http('POST', '/venues/invoice-save', array_diff_key($invoicePost($unpaidInvoice, ''), ['currency' => true]));
check('H10 and an edit that posts NO currency keeps the invoice\'s own (EUR), not the organisation\'s (now USD)', $invoiceCurrency($unpaidInvoice) === 'EUR', $invoiceCurrency($unpaidInvoice));
// An UNRECORDED invoice with an unrecorded payment (as an older database holds),
// edited with no currency: both must stay unrecorded and agree. Round 3 stamped the
// invoice with today's currency (USD) while its payment stayed '' (measured by the
// round-4 check); Currency::onEdit() keeps ''.
run($db, "INSERT INTO `tblVenueInvoices` (`siteID`, `venueID`, `issueDate`, `amountPence`, `currency`, `status`) VALUES (?, ?, CURDATE(), 6000, '', 'part-paid')", 'ii', [$site, $venue]);
$oldInvoice = (int) $db->insert_id;
$made['invoices'][] = $oldInvoice;
run($db, "INSERT INTO `tblVenueInvoicePayments` (`siteID`, `invoiceID`, `paidDate`, `amountPence`, `currency`) VALUES (?, ?, CURDATE(), 1000, '')", 'ii', [$site, $oldInvoice]);
$http('POST', '/venues/invoice-save', ['csrf_token' => $freshToken('/venues/invoice?id=' . $oldInvoice), 'invoiceID' => (string) $oldInvoice,
    'venueID' => (string) $venue, 'issueDate' => date('Y-m-d'), 'amount' => '60.00', 'invoiceRef' => 'SELFTEST-OLD']);
$oldAfter = rows($db, "SELECT `i`.`invoiceRef`, `i`.`currency` AS `inv`, `p`.`currency` AS `pay` FROM `tblVenueInvoices` `i` JOIN `tblVenueInvoicePayments` `p` ON `p`.`invoiceID` = `i`.`invoiceID` WHERE `i`.`invoiceID` = ?", 'i', [$oldInvoice]);
check('H10b an UNRECORDED invoice edited with no currency is saved (reference set) and STAYS unrecorded, agreeing with its unrecorded payment — never today\'s USD',
    ($oldAfter[0]['invoiceRef'] ?? '') === 'SELFTEST-OLD' && ($oldAfter[0]['inv'] ?? 'x') === '' && ($oldAfter[0]['pay'] ?? 'x') === '', json_encode($oldAfter));
$http('POST', '/venues/invoice-save', ['csrf_token' => $freshToken('/venues/invoice?id=' . $oldInvoice), 'invoiceID' => (string) $oldInvoice,
    'venueID' => (string) $venue, 'issueDate' => date('Y-m-d'), 'amount' => '60.00', 'currency' => 'EUR']);
check('H10c and that unrecorded invoice, POSTED a currency (EUR), is refused: its unrecorded payment would then disagree with it (round 3 let an unrecorded invoice change freely)',
    $invoiceCurrency($oldInvoice) === '', var_export($invoiceCurrency($oldInvoice), true));

// A venue booking and agreement recorded in EUR, edited while the organisation is in
// USD: each must keep EUR. The booking form DOES carry a currency box (booking.php,
// prefilled with the booking's own); round 3's note here said it had none, which was
// untrue. So H12 posts what the REAL form sends, read out of the page, and H12b posts
// a crafted edit with no currency at all (the API-shaped case).
run($db, "INSERT INTO `tblVenueStatuses` (`siteID`, `statusName`, `statusCategory`, `countsAsConfirmed`) VALUES (?, 'Currency selftest booked', 'agreed', 1)", 'i', [$site]);
$bookStatus = (int) $db->insert_id;
run($db, "INSERT INTO `tblVenueUsageTypes` (`siteID`, `venueID`, `typeName`) VALUES (?, ?, 'Currency selftest hire')", 'ii', [$site, $venue]);
$bookUsage = (int) $db->insert_id;
run($db, "INSERT INTO `tblVenueBookings` (`siteID`, `venueID`, `bookingDate`, `usageTypeID`, `statusID`, `costPence`, `currency`, `notes`) VALUES (?, ?, CURDATE(), ?, ?, 2500, 'EUR', 'before')",
    'iiii', [$site, $venue, $bookUsage, $bookStatus]);
$booking = (int) $db->insert_id;
/** The fields the page's own booking form would post: its inputs, selected options and text areas. */
$formPost = static function (string $html, string $actionEndsWith): array {
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
        if (($fields['action'] ?? '') === 'save') {
            return $fields;
        }
    }
    return [];
};
$bookingForm = $formPost($http('GET', '/venues/booking?id=' . $booking)['body'], 'venues/booking-save');
$bookingForm['notes'] = 'edited through the real form';
$http('POST', '/venues/booking-save', $bookingForm);
$bookingAfter = rows($db, 'SELECT `notes`, `costPence`, `currency` FROM `tblVenueBookings` WHERE `bookingID` = ?', 'i', [$booking]);
check('H12 a venue booking EDITED THROUGH ITS REAL FORM after the organisation moved to USD — the form posts its own currency box, prefilled with the booking\'s EUR (' . var_export($bookingForm['currency'] ?? null, true) . ') — is saved (notes changed) and is STILL EUR',
    ($bookingForm['currency'] ?? null) === 'EUR' && ($bookingAfter[0]['notes'] ?? '') === 'edited through the real form' && (int) ($bookingAfter[0]['costPence'] ?? 0) === 2500 && ($bookingAfter[0]['currency'] ?? '') === 'EUR',
    json_encode($bookingAfter) . '; flash: ' . (preg_match('/alert-danger[^>]*>([^<]*)/', $http('GET', '/venues/booking?id=' . $booking)['body'], $mm) === 1 ? $mm[1] : '(none)'));
$http('POST', '/venues/booking-save', ['csrf_token' => $freshToken('/venues/booking?id=' . $booking), 'action' => 'save', 'bookingID' => (string) $booking,
    'venueID' => (string) $venue, 'bookingDate' => date('Y-m-d'), 'statusID' => (string) $bookStatus, 'usageTypeID' => (string) $bookUsage,
    'costPence' => '25.00', 'notes' => 'edited after the change']);
$bookingAfter = rows($db, 'SELECT `notes`, `costPence`, `currency` FROM `tblVenueBookings` WHERE `bookingID` = ?', 'i', [$booking]);
check('H12b and a CRAFTED edit posting no currency at all is saved too (notes changed) and is STILL EUR',
    ($bookingAfter[0]['notes'] ?? '') === 'edited after the change' && (int) ($bookingAfter[0]['costPence'] ?? 0) === 2500 && ($bookingAfter[0]['currency'] ?? '') === 'EUR',
    json_encode($bookingAfter) . '; flash: ' . (preg_match('/alert-danger[^>]*>([^<]*)/', $http('GET', '/venues/booking?id=' . $booking)['body'], $mm) === 1 ? $mm[1] : '(none)'));
run($db, "INSERT INTO `tblVenueAgreements` (`siteID`, `venueID`, `title`, `rateAmountPence`, `currency`, `status`) VALUES (?, ?, 'Currency selftest hire terms', 10000, 'EUR', 'active')", 'ii', [$site, $venue]);
$agreement = (int) $db->insert_id;
$http('POST', '/venues/agreement-save', ['csrf_token' => $freshToken('/venues/agreements?edit=' . $agreement), 'action' => 'save', 'agreementID' => (string) $agreement,
    'venueID' => (string) $venue, 'agreementType' => 'standing', 'title' => 'Currency selftest hire terms, renamed', 'rateAmount' => '100.00', 'status' => 'active']);
$agreementAfter = rows($db, 'SELECT `title`, `rateAmountPence`, `currency` FROM `tblVenueAgreements` WHERE `agreementID` = ?', 'i', [$agreement]);
check('H13 a venue agreement EDITED the same way (no currency posted) is saved — renamed — and is STILL EUR',
    ($agreementAfter[0]['title'] ?? '') === 'Currency selftest hire terms, renamed' && (int) ($agreementAfter[0]['rateAmountPence'] ?? 0) === 10000 && ($agreementAfter[0]['currency'] ?? '') === 'EUR',
    json_encode($agreementAfter));

// UNRECORDED records stay unrecorded on edit (round 5: "stored ?: today" in the
// booking, agreement and asset edits stayed green, because every record above had a
// currency). The organisation is in USD here.
run($db, "INSERT INTO `tblVenueBookings` (`siteID`, `venueID`, `bookingDate`, `usageTypeID`, `statusID`, `costPence`, `currency`, `notes`) VALUES (?, ?, CURDATE(), ?, ?, 900, '', 'old')",
    'iiii', [$site, $venue, $bookUsage, $bookStatus]);
$oldBooking = (int) $db->insert_id;
$oldBookingForm = $formPost($http('GET', '/venues/booking?id=' . $oldBooking)['body'], 'venues/booking-save');
$oldBookingForm['notes'] = 'old, edited through the real form';
$http('POST', '/venues/booking-save', $oldBookingForm);
$http('POST', '/venues/booking-save', ['csrf_token' => $freshToken('/venues/booking?id=' . $oldBooking), 'action' => 'save', 'bookingID' => (string) $oldBooking,
    'venueID' => (string) $venue, 'bookingDate' => date('Y-m-d'), 'statusID' => (string) $bookStatus, 'usageTypeID' => (string) $bookUsage,
    'costPence' => '9.00', 'notes' => 'old, edited by hand']);
$oldBookingAfter = rows($db, 'SELECT `notes`, `currency` FROM `tblVenueBookings` WHERE `bookingID` = ?', 'i', [$oldBooking]);
run($db, "INSERT INTO `tblVenueAgreements` (`siteID`, `venueID`, `title`, `rateAmountPence`, `currency`, `status`) VALUES (?, ?, 'Currency selftest old terms', 5000, '', 'active')", 'ii', [$site, $venue]);
$oldAgreement = (int) $db->insert_id;
$http('POST', '/venues/agreement-save', ['csrf_token' => $freshToken('/venues/agreements?edit=' . $oldAgreement), 'action' => 'save', 'agreementID' => (string) $oldAgreement,
    'venueID' => (string) $venue, 'agreementType' => 'standing', 'title' => 'Currency selftest old terms, renamed', 'rateAmount' => '50.00', 'status' => 'active']);
$oldAgreementAfter = rows($db, 'SELECT `title`, `currency` FROM `tblVenueAgreements` WHERE `agreementID` = ?', 'i', [$oldAgreement]);
run($db, "INSERT INTO `tblAssets` (`siteID`, `name`, `currency`, `publicToken`) VALUES (?, 'Currency selftest old organ', '', ?)", 'is', [$site, bin2hex(random_bytes(16))]);
$oldAsset = (int) $db->insert_id;
$made['assets'][] = $oldAsset;
$http('POST', '/assets/save', $assetPost($oldAsset, 'Currency selftest old organ, renamed', ''));
$oldAssetAfter = rows($db, 'SELECT `name`, `currency` FROM `tblAssets` WHERE `assetID` = ?', 'i', [$oldAsset]);
check('H13b an UNRECORDED booking (through its real form, which carries its empty currency box, and by hand), agreement and asset, each EDITED and saved while the organisation is in USD, all STAY unrecorded — never today\'s USD',
    ($oldBookingForm['currency'] ?? null) === '' && ($oldBookingAfter[0]['notes'] ?? '') === 'old, edited by hand' && ($oldBookingAfter[0]['currency'] ?? 'x') === ''
    && ($oldAgreementAfter[0]['title'] ?? '') === 'Currency selftest old terms, renamed' && ($oldAgreementAfter[0]['currency'] ?? 'x') === ''
    && ($oldAssetAfter[0]['name'] ?? '') === 'Currency selftest old organ, renamed' && ($oldAssetAfter[0]['currency'] ?? 'x') === '',
    json_encode([$oldBookingForm['currency'] ?? null, $oldBookingAfter, $oldAgreementAfter, $oldAssetAfter]));

// A count with NO recorded currency, CLOSED over HTTP: its gifts are written with no
// currency either, agreeing with the count (round 4 changed this; round 5 found
// nothing drove it).
run($db, "INSERT INTO `tblCountSessions` (`siteID`, `serviceDate`, `categoryID`, `currency`, `status`, `cashTotal`, `chequeTotal`, `envelopeTotal`) VALUES (?, CURDATE(), ?, '', 'counting', 12.34, 0, 0)",
    'ii', [$site, $category]);
$oldCount = (int) $db->insert_id;
$made['counts'][] = $oldCount;
$http('POST', '/giving/count/close', ['csrf_token' => $freshToken('/giving/count/session?id=' . $oldCount), 'countSessionID' => (string) $oldCount]);
$countGifts = rows($db, "SELECT `amountPence`, `currency` FROM `tblGivingEntry` WHERE `reference` = ?", 's', ['Count #' . $oldCount]);
$countStatus = rows($db, 'SELECT `status` FROM `tblCountSessions` WHERE `countSessionID` = ?', 'i', [$oldCount]);
check('H13c an UNRECORDED count closed over HTTP writes its gifts (12.34) with NO currency, agreeing with the count — never today\'s USD',
    ($countStatus[0]['status'] ?? '') === 'closed' && $countGifts !== [] && array_unique(array_column($countGifts, 'currency')) === ['']
    && array_sum(array_map('intval', array_column($countGifts, 'amountPence'))) === 1234,
    json_encode([$countStatus, $countGifts]));

// A bank statement with NO recorded currency: its page says no count can be matched
// to it (round 4 made that message reachable; nothing showed it).
run($db, "INSERT INTO `tblBankImports` (`siteID`, `filename`, `currency`, `fileHash`) VALUES (?, 'selftest-old.csv', '', ?)", 'is', [$site, bin2hex(random_bytes(32))]);
$oldImport = (int) $db->insert_id;
$made['imports'][] = $oldImport;
run($db, "INSERT INTO `tblBankTxns` (`importID`, `siteID`, `txnDate`, `amountPence`, `currency`, `description`) VALUES (?, ?, CURDATE(), 1234, '', 'Currency selftest old line')", 'ii', [$oldImport, $site]);
$oldTxn = (int) $db->insert_id;
$oldImportPage = $http('GET', '/giving/reconcile/view?id=' . $oldImport)['body'];
$http('POST', '/giving/reconcile/match', ['csrf_token' => $freshToken('/giving/reconcile/view?id=' . $oldImport), 'action' => 'match-session', 'txnID' => (string) $oldTxn, 'countSessionID' => (string) $oldCount]);
$oldTxnAfter = rows($db, 'SELECT `matchStatus` FROM `tblBankTxns` WHERE `txnID` = ?', 'i', [$oldTxn]);
check('H13d a statement with NO recorded currency says on its page that no count can be matched to it, and a posted match to an unrecorded count is REFUSED',
    str_contains($oldImportPage, 'this statement has no currency recorded, so no count can be matched to it') === true && ($oldTxnAfter[0]['matchStatus'] ?? '') === 'unmatched',
    json_encode($oldTxnAfter));

// The refusal an UNRECORDED claim's claimant meets names the CLAIM, not the
// organisation (which has a currency, USD, here).
run($db, "INSERT INTO `tblExpenseClaims` (`siteID`, `userID`, `deptID`, `claimTitle`, `claimDate`, `totalAmount`, `status`, `currency`) VALUES (?, ?, ?, 'Currency selftest old claim', CURDATE(), 10.00, 'Pending', '')",
    'iii', [$site, CU_ADMIN, $dept]);
$oldClaim = (int) $db->insert_id;
$http('POST', '/expenses/edit/save', ['csrf_token' => $freshToken('/expenses/edit?id=' . $oldClaim), 'claimID' => (string) $oldClaim,
    'claimTitle' => 'Currency selftest old claim', 'deptID' => (string) $dept,
    'itemDesc' => ['Taxi in Boston'], 'itemQty' => ['1'], 'itemUnit' => ['10.00'], 'itemCurrency' => ['USD'], 'itemConverted' => ['8.00']]);
$oldClaimPage = html_entity_decode($http('GET', '/expenses/edit?id=' . $oldClaim)['body'], ENT_QUOTES, 'UTF-8');
check('H13e editing an UNRECORDED claim with a line in dollars is refused in words that name the CLAIM ("this claim has no currency recorded"), not the organisation, which has one',
    str_contains($oldClaimPage, 'this claim has no currency recorded') === true && str_contains($oldClaimPage, 'this organisation has no currency chosen') === false);

// 🔎 What every race below asks of the database, checked ONCE before the first:
//    performance_schema must be ON and its three tables readable by this user.
//    Otherwise $waitingOnRow() could only ever answer "not waiting", each race
//    would wait out its ten seconds, and seven premise checks would go red
//    blaming the portal (measured: MySQL 8.0.36 with --performance-schema=OFF
//    gave 93 of 100, MariaDB 11.4.13 92 of 100). A refusal names the cause.
$psOn = (string) (rows($db, 'SELECT @@performance_schema AS `on`')[0]['on'] ?? '0');
if ($psOn !== '1') {
    refuse('the race checks (sections T, X and Z) need performance_schema ON, and this server has @@performance_schema = ' . $psOn
        . ' (it is OFF by default on MariaDB; start the server with --performance-schema=ON)');
}
foreach (['data_lock_waits', 'data_locks', 'threads'] as $psTable) {
    try {
        rows($db, 'SELECT 1 FROM `performance_schema`.`' . $psTable . '` LIMIT 1');
    } catch (Throwable $e) {
        refuse('the race checks need to read performance_schema.' . $psTable . ', and ' . $dbUser . ' cannot (' . $e->getMessage() . ')');
    }
}

/**
 * 🔎 THE PREMISE OF EVERY RACE (round 8): is some transaction WAITING on a lock that
 * $holder's own thread holds on the row of $table whose primary key is $id? Asked of
 * performance_schema.data_lock_waits joined to data_locks, so it names the EXACT row
 * and the EXACT holder. (Rounds 5-7 asked information_schema.INNODB_TRX for "a waiting
 * statement mentioning the table". That view is a cache refreshed at most every 0.1
 * seconds, so a race run straight after another on the same table could read the
 * PREVIOUS race's waiter and go on before its own request had even started — which is
 * how round 7's race on the assets API passed with the fix reverted.)
 */
$waitingOnRow = static function (string $table, int $id, mysqli $holder) use ($db): bool {
    $holderThread = (int) (rows($db, 'SELECT `THREAD_ID` AS `t` FROM `performance_schema`.`threads` WHERE `PROCESSLIST_ID` = ?', 'i', [$holder->thread_id])[0]['t'] ?? 0);
    if ($holderThread === 0) {
        return false;
    }

    return (int) (rows($db, 'SELECT COUNT(*) AS `n` FROM `performance_schema`.`data_lock_waits` `w` '
        . 'JOIN `performance_schema`.`data_locks` `l` ON `l`.`ENGINE_LOCK_ID` = `w`.`BLOCKING_ENGINE_LOCK_ID` '
        . "WHERE `l`.`OBJECT_SCHEMA` = DATABASE() AND `l`.`OBJECT_NAME` = ? AND `l`.`INDEX_NAME` = 'PRIMARY' AND `l`.`LOCK_DATA` = ? "
        . 'AND `w`.`BLOCKING_THREAD_ID` = ?', 'ssi', [$table, (string) $id, $holderThread])[0]['n'] ?? 0) > 0;
};

// 🔒 THE LOCK (#647's rule for money, round 5): this test HOLDS the asset row's lock
// in its own connection while the real edit (EUR -> USD) runs in the web server, waits
// until the database reports that edit WAITING on the lock (asserted, not slept on),
// then records a repair in EUR and lets go. The edit must then see the repair under
// its own lock and refuse. Without the lock and the re-read under it, the edit's own
// early check saw no repair and its UPDATE simply waited, then wrote USD.
run($db, "INSERT INTO `tblAssets` (`siteID`, `name`, `currency`, `publicToken`) VALUES (?, 'Currency selftest stand', 'EUR', ?)", 'is', [$site, bin2hex(random_bytes(16))]);
$stand = (int) $db->insert_id;
$made['assets'][] = $stand;
$standPost = $assetPost($stand, 'Currency selftest stand', 'USD');
$holder = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$holder->set_charset('utf8mb4');
$holder->begin_transaction();
$hold = $holder->prepare('SELECT `currency` FROM `tblAssets` WHERE `assetID` = ? FOR UPDATE');
$hold->bind_param('i', $stand);
$hold->execute();
$hold->get_result()->fetch_all();
$hold->close();
$multi = curl_multi_init();
$edit = curl_init('http://127.0.0.1:' . $port . '/assets/save');
curl_setopt_array($edit, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($standPost),
    CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
curl_multi_add_handle($multi, $edit);
$waiting = false;
$running = 0;
for ($i = 0; $i < 100 && $waiting === false; $i++) {
    curl_multi_exec($multi, $running);
    $waiting = $waitingOnRow('tblAssets', $stand, $holder);
    if ($waiting === false) {
        usleep(100000);
    }
}
check('T1 the premise: the real edit is WAITING on the asset row this test holds (the database says so), and has not finished',
    $waiting === true && $running > 0, 'waiting ' . var_export($waiting, true) . ', running ' . $running);
$raceRepair = $holder->prepare("INSERT INTO `tblAssetMaintenance` (`siteID`, `assetID`, `title`, `costPence`, `currency`) VALUES (?, ?, 'Currency selftest race repair', 700, 'EUR')");
$raceRepair->bind_param('ii', $site, $stand);
$raceRepair->execute();
$raceRepair->close();
$holder->commit();
$holder->close();
do {
    curl_multi_exec($multi, $running);
    if ($running > 0) {
        curl_multi_select($multi, 1.0);
    }
} while ($running > 0);
curl_multi_remove_handle($multi, $edit);
curl_multi_close($multi);
check('T2 a repair in EUR recorded WHILE the edit waited makes the edit refuse once it gets the lock: the asset is still EUR, agreeing with its repair',
    $assetCurrency($stand) === 'EUR', var_export($assetCurrency($stand), true));
// T2b: the WORDS of a refusal under the asset's lock (round 7). The page used to
// blame "the asset tag code" for every failed update; a currency refusal found under
// the lock is now said as such. T2's race left a message for the asset page; read it
// now, before another page shows (and so uses up) the message.
$standFlash = html_entity_decode($http('GET', '/assets/edit?id=' . $stand)['body'], ENT_QUOTES, 'UTF-8');
// Only the MESSAGE is judged: the page's own form has an "Asset tag code" field.
$standMessage = preg_match('/<div class="alert alert-danger">(.*?)<\/div>/s', $standFlash, $mm) === 1 ? trim($mm[1]) : '';
check('T2b the asset page says WHY the raced edit was refused — a record in another currency than USD — and does not blame the asset tag code',
    str_contains($standMessage, 'in another currency than USD') === true && str_contains($standMessage, 'tag code') === false, $standMessage);

/**
 * Run one real request in the web server while this test holds a row's lock in its
 * own connection; wait until performance_schema reports that request WAITING on the
 * lock $holder holds on row $waitsOnId of $waitsOnTable ($waitingOnRow — asserted,
 * never slept on); run $meanwhile (which may use the holding connection); then let go
 * and wait for the request to finish. Answers whether the request was seen waiting.
 * $as: 'form' (the default), 'json' (an API body, its token in the header too) or
 * 'multipart' (a form carrying a CURLFile).
 */
$raceLast = ['status' => 0, 'body' => ''];
$raceRequest = static function (string $path, array $post, mysqli $holder, string $waitsOnTable, int $waitsOnId, callable $meanwhile, string $as = 'form') use ($port, $jar, $waitingOnRow, &$raceLast): bool {
    $multi = curl_multi_init();
    $req = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($req, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $as === 'json' ? json_encode($post) : ($as === 'multipart' ? $post : http_build_query($post)),
        CURLOPT_HTTPHEADER => $as === 'json' ? ['Content-Type: application/json', 'X-CSRF-TOKEN: ' . (string) ($post['csrf_token'] ?? '')] : [],
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    curl_multi_add_handle($multi, $req);
    $seen = false;
    $running = 0;
    for ($i = 0; $i < 100 && $seen === false; $i++) {
        curl_multi_exec($multi, $running);
        $seen = $waitingOnRow($waitsOnTable, $waitsOnId, $holder);
        if ($seen === false) {
            usleep(100000);
        }
    }
    $meanwhile($seen && $running > 0);
    do {
        curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0);
    $raceLast = ['status' => (int) curl_getinfo($req, CURLINFO_HTTP_CODE), 'body' => (string) curl_multi_getcontent($req)];
    curl_multi_remove_handle($multi, $req);
    curl_multi_close($multi);
    return $seen;
};
$holdRow = static function (string $table, string $key, int $id) use ($dbHost, $dbUser, $dbPass, $dbName, $dbPort): mysqli {
    $h = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $h->set_charset('utf8mb4');
    $h->begin_transaction();
    $st = $h->prepare('SELECT 1 FROM `' . $table . '` WHERE `' . $key . '` = ? FOR UPDATE');
    $st->bind_param('i', $id);
    $st->execute();
    $st->get_result()->fetch_all();
    $st->close();
    return $h;
};

// T3/T4: the INVOICE edit's re-check UNDER the lock (round 6: replacing it with
// if (false) must go red). An unpaid EUR invoice is edited to USD while this test
// holds the invoice row; meanwhile a payment in EUR is recorded. The edit's early
// check saw no payment; only the re-check under the lock can see it.
run($db, "INSERT INTO `tblVenueInvoices` (`siteID`, `venueID`, `issueDate`, `amountPence`, `currency`, `status`) VALUES (?, ?, CURDATE(), 2000, 'EUR', 'pending')", 'ii', [$site, $venue]);
$raceInvoice = (int) $db->insert_id;
$made['invoices'][] = $raceInvoice;
$invoiceHolder = $holdRow('tblVenueInvoices', 'invoiceID', $raceInvoice);
$invoiceWaited = false;
$raceRequest('/venues/invoice-save', array_merge($invoicePost($raceInvoice, 'USD'), ['amount' => '20.00']), $invoiceHolder, 'tblVenueInvoices', $raceInvoice,
    static function (bool $waiting) use ($invoiceHolder, $site, $raceInvoice, &$invoiceWaited): void {
        $invoiceWaited = $waiting;
        $p = $invoiceHolder->prepare("INSERT INTO `tblVenueInvoicePayments` (`siteID`, `invoiceID`, `paidDate`, `amountPence`, `currency`) VALUES (?, ?, CURDATE(), 500, 'EUR')");
        $p->bind_param('ii', $site, $raceInvoice);
        $p->execute();
        $p->close();
        $invoiceHolder->commit();
        $invoiceHolder->close();
    });
check('T3 the premise: the invoice edit was seen WAITING on the invoice row this test held', $invoiceWaited === true);
check('T4 a payment in EUR recorded while it waited makes the invoice edit refuse UNDER the lock: still EUR, agreeing with its payment',
    $invoiceCurrency($raceInvoice) === 'EUR', var_export($invoiceCurrency($raceInvoice), true));

// T5/T6: the EXPENSE edit's refusal when the claim's currency changed under it.
// An UNRECORDED claim is edited while this test holds its row; meanwhile it is
// labelled EUR. The lines were judged with no currency, so the edit must refuse.
run($db, "INSERT INTO `tblExpenseClaims` (`siteID`, `userID`, `deptID`, `claimTitle`, `claimDate`, `totalAmount`, `status`, `currency`) VALUES (?, ?, ?, 'Currency selftest raced claim', CURDATE(), 5.00, 'Pending', '')",
    'iii', [$site, CU_ADMIN, $dept]);
$raceClaim = (int) $db->insert_id;
$claimHolder = $holdRow('tblExpenseClaims', 'claimID', $raceClaim);
$claimWaited = false;
$raceRequest('/expenses/edit/save', ['csrf_token' => $freshToken('/expenses/edit?id=' . $raceClaim), 'claimID' => (string) $raceClaim,
    'claimTitle' => 'Currency selftest raced claim, edited', 'deptID' => (string) $dept,
    'itemDesc' => ['Stamps'], 'itemQty' => ['1'], 'itemUnit' => ['5.00'], 'itemCurrency' => [''], 'itemConverted' => ['']], $claimHolder, 'tblExpenseClaims', $raceClaim,
    static function (bool $waiting) use ($claimHolder, $raceClaim, &$claimWaited): void {
        $claimWaited = $waiting;
        $u = $claimHolder->prepare("UPDATE `tblExpenseClaims` SET `currency` = 'EUR' WHERE `claimID` = ?");
        $u->bind_param('i', $raceClaim);
        $u->execute();
        $u->close();
        $claimHolder->commit();
        $claimHolder->close();
    });
$raceClaimAfter = rows($db, 'SELECT `claimTitle`, `currency` FROM `tblExpenseClaims` WHERE `claimID` = ?', 'i', [$raceClaim]);
$raceLines = rows($db, 'SELECT COUNT(*) AS `n` FROM `tblExpenseClaimItems` WHERE `claimID` = ?', 'i', [$raceClaim]);
check('T5 the premise: the claim edit was seen WAITING on the claim row this test held', $claimWaited === true);
check('T6 the claim was given a currency while the edit waited, so the edit REFUSED: the title is unchanged and no line was written in the old answer',
    ($raceClaimAfter[0]['claimTitle'] ?? '') === 'Currency selftest raced claim' && ($raceClaimAfter[0]['currency'] ?? '') === 'EUR' && (int) ($raceLines[0]['n'] ?? -1) === 0,
    json_encode([$raceClaimAfter, $raceLines]));

// T2c: the API's words for a refusal under the asset's lock (round 7; T2b, the
// page's, runs straight after T2, before any other page uses up its message).
run($db, "INSERT INTO `tblAssets` (`siteID`, `name`, `currency`, `publicToken`) VALUES (?, 'Currency selftest stool', 'EUR', ?)", 'is', [$site, bin2hex(random_bytes(16))]);
$stool = (int) $db->insert_id;
$made['assets'][] = $stool;
$stoolHolder = $holdRow('tblAssets', 'assetID', $stool);
$stoolWaited = $raceRequest('/api/assets/update', ['assetID' => $stool, 'currency' => 'USD', 'csrf_token' => $freshToken('/assets/edit?id=' . $stool)],
    $stoolHolder, 'tblAssets', $stool,
    static function (bool $waiting) use ($stoolHolder, $site, $stool): void {
        $r = $stoolHolder->prepare("INSERT INTO `tblAssetMaintenance` (`siteID`, `assetID`, `title`, `costPence`, `currency`) VALUES (?, ?, 'Currency selftest stool repair', 300, 'EUR')");
        $r->bind_param('ii', $site, $stool);
        $r->execute();
        $r->close();
        $stoolHolder->commit();
        $stoolHolder->close();
    }, 'json');
$stoolAnswer = json_decode($raceLast['body'], true);
check('T2c the API, raced the same way, answers 409 with the currency reason (not the tag code), and the asset is still EUR',
    $stoolWaited === true && $raceLast['status'] === 409 && str_contains((string) json_encode($stoolAnswer), 'in another currency than USD') === true
    && str_contains($raceLast['body'], 'tag code') === false && $assetCurrency($stool) === 'EUR',
    'waited ' . var_export($stoolWaited, true) . '; HTTP ' . $raceLast['status'] . ' ' . mb_substr($raceLast['body'], 0, 200));

// X1-X3: the invoice BALANCE is checked under the invoice's lock (round 6 moved it
// there; round 7 of the check found nothing tested it: `false &&` left 84/84).
run($db, "INSERT INTO `tblVenueInvoices` (`siteID`, `venueID`, `issueDate`, `amountPence`, `currency`, `status`) VALUES (?, ?, CURDATE(), 2000, 'EUR', 'pending')", 'ii', [$site, $venue]);
$balanceInvoice = (int) $db->insert_id;
$made['invoices'][] = $balanceInvoice;
$payPost = static fn (int $id, string $amount): array => ['csrf_token' => $freshToken('/venues/invoice?id=' . $id), 'action' => 'add', 'invoiceID' => (string) $id,
    'amount' => $amount, 'paidDate' => date('Y-m-d'), 'method' => 'bank-transfer'];
$paidOn = static fn (int $id): array => array_map('intval', array_column(rows($db, 'SELECT `amountPence` FROM `tblVenueInvoicePayments` WHERE `invoiceID` = ? ORDER BY `payID`', 'i', [$id]), 'amountPence'));
$balanceHolder = $holdRow('tblVenueInvoices', 'invoiceID', $balanceInvoice);
$payWaited = $raceRequest('/venues/invoice-payment-save', $payPost($balanceInvoice, '20.00'), $balanceHolder, 'tblVenueInvoices', $balanceInvoice,
    static function (bool $waiting) use ($balanceHolder, $site, $balanceInvoice): void {
        $p = $balanceHolder->prepare("INSERT INTO `tblVenueInvoicePayments` (`siteID`, `invoiceID`, `paidDate`, `amountPence`, `currency`) VALUES (?, ?, CURDATE(), 2000, 'EUR')");
        $p->bind_param('ii', $site, $balanceInvoice);
        $p->execute();
        $p->close();
        $balanceHolder->commit();
        $balanceHolder->close();
    });
check('X1 the premise: the payment request was seen WAITING on the invoice row this test held', $payWaited === true);
check('X2 a full payment recorded while it waited makes the waiting full payment REFUSED under the lock: one payment, and the total paid stays the invoice\'s 20.00',
    $paidOn($balanceInvoice) === [2000], json_encode($paidOn($balanceInvoice)));
run($db, "INSERT INTO `tblVenueInvoices` (`siteID`, `venueID`, `issueDate`, `amountPence`, `currency`, `status`) VALUES (?, ?, CURDATE(), 2000, 'EUR', 'pending')", 'ii', [$site, $venue]);
$stepInvoice = (int) $db->insert_id;
$made['invoices'][] = $stepInvoice;
$http('POST', '/venues/invoice-payment-save', $payPost($stepInvoice, '15.00'));
$http('POST', '/venues/invoice-payment-save', $payPost($stepInvoice, '6.00'));
$http('POST', '/venues/invoice-payment-save', $payPost($stepInvoice, '5.00'));
check('X3 on an invoice of 20.00: 15.00 is accepted, 6.00 (over by 1.00) is refused, 5.00 is accepted — two payments, 15.00 and 5.00',
    $paidOn($stepInvoice) === [1500, 500], json_encode($paidOn($stepInvoice)));

// A1/A2: an allocation that fails says WHY truly (round 7): the same booking twice is
// "already allocated"; any other failure is not. Since #1004 an amount too large for
// the column never reaches the database: Currency::parseAmount() refuses it in the
// handler, saying what the column can hold, so A1 now reads that sentence.
run($db, "INSERT INTO `tblVenueInvoices` (`siteID`, `venueID`, `issueDate`, `amountPence`, `currency`, `status`) VALUES (?, ?, CURDATE(), 2000, 'EUR', 'pending')", 'ii', [$site, $venue]);
$allocInvoice = (int) $db->insert_id;
$made['invoices'][] = $allocInvoice;
$allocate = static fn (string $amount): array => $http('POST', '/venues/invoice-save', ['csrf_token' => $freshToken('/venues/invoice?id=' . $allocInvoice), 'action' => 'allocate',
    'invoiceID' => (string) $allocInvoice, 'bookingID' => (string) $booking, 'amount' => $amount]);
$allocate('99999999999.00');
$tooBig = html_entity_decode($http('GET', '/venues/invoice?id=' . $allocInvoice)['body'], ENT_QUOTES, 'UTF-8');
$allocate('10.00');
$allocate('10.00');
$twice = html_entity_decode($http('GET', '/venues/invoice?id=' . $allocInvoice)['body'], ENT_QUOTES, 'UTF-8');
check('A1 an allocation refused for another reason (an amount too large for the column, refused by the converter before the database, #1004) says so — not "already allocated", and not "try again", which would fail the same way',
    str_contains($tooBig, 'That amount is too large: the most that can be stored here is 21,474,836.47. Nothing was allocated.') === true && str_contains($tooBig, 'already allocated') === false
    && str_contains($tooBig, 'try again') === false);
check('A2 allocating the same booking twice IS reported as "already allocated", and only one line exists',
    str_contains($twice, 'already allocated to this invoice') === true
    && (int) (rows($db, 'SELECT COUNT(*) AS `n` FROM `tblVenueInvoiceLines` WHERE `invoiceID` = ?', 'i', [$allocInvoice])[0]['n'] ?? 0) === 1);

// F1-F3: an invoice's FILE (round 7 of the check: attaching the FIRST file to an
// invoice that had none took the no-file UPDATE, said "Invoice saved." and left the
// stored PDF named by no row). Driven with real multipart uploads; the row and the
// disk are read back.
$uploadDir = $state['child'] . $sep . 'web' . $sep . '_uploads' . $sep . 'venues' . $sep . 'invoices' . $sep;
$pdfOf = static function (string $label) use ($sep): string {
    $f = sys_get_temp_dir() . $sep . 'currency-selftest-' . bin2hex(random_bytes(4)) . '.pdf';
    file_put_contents($f, "%PDF-1.4\n% " . $label . "\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    return $f;
};
$saveWithFile = static function (int $id, ?string $file) use ($port, $jar, $freshToken, $venue): int {
    $fields = ['csrf_token' => $freshToken('/venues/invoice?id=' . $id), 'action' => 'save', 'invoiceID' => (string) $id,
        'venueID' => (string) $venue, 'issueDate' => date('Y-m-d'), 'amount' => '20.00', 'currency' => 'EUR'];
    if ($file !== null) {
        $fields['file'] = new CURLFile($file, 'application/pdf', basename($file));
    }
    $h = curl_init('http://127.0.0.1:' . $port . '/venues/invoice-save');
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    curl_exec($h);
    return (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
};
$fileOf = static fn (int $id): ?string => rows($db, 'SELECT `filePath` FROM `tblVenueInvoices` WHERE `invoiceID` = ?', 'i', [$id])[0]['filePath'] ?? null;
run($db, "INSERT INTO `tblVenueInvoices` (`siteID`, `venueID`, `issueDate`, `amountPence`, `currency`, `status`) VALUES (?, ?, CURDATE(), 2000, 'EUR', 'pending')", 'ii', [$site, $venue]);
$fileInvoice = (int) $db->insert_id;
$made['invoices'][] = $fileInvoice;
$firstPdf = $pdfOf('first');
$firstStatus = $saveWithFile($fileInvoice, $firstPdf);
$firstPath = $fileOf($fileInvoice);
check('F1 attaching the FIRST file to an invoice that had none stores it: the row names it, and the file is on disk',
    $firstPath !== null && is_file($uploadDir . $firstPath) === true, 'HTTP ' . $firstStatus . '; filePath ' . var_export($firstPath, true));
$secondPdf = $pdfOf('second');
$saveWithFile($fileInvoice, $secondPdf);
$secondPath = $fileOf($fileInvoice);
check('F2 replacing it stores the new file and DELETES the old one from disk',
    $secondPath !== null && $secondPath !== $firstPath && is_file($uploadDir . $secondPath) === true
    && ($firstPath === null || is_file($uploadDir . $firstPath) === false), var_export([$firstPath, $secondPath], true));
$saveWithFile($fileInvoice, null);
check('F3 an edit WITHOUT a file keeps the file it has (row and disk)', $fileOf($fileInvoice) === $secondPath && is_file($uploadDir . (string) $secondPath) === true);
@unlink($firstPdf);
@unlink($secondPdf);

// Z0-Z5 (round 8; written from the round-7 checker's tested template): the assets API
// when a request leaves the currency OUT, and an invoice save with a new file refused
// under the lock. Round 7 fixed _coerce.php to pass NO currency when none is posted
// (it used to fill in the value read before the asset's lock, which updateAsset()
// then treated as a POSTED currency) and nothing tested it.
$apiPost = static function (array $body) use ($port, $jar): array {
    $h = curl_init('http://127.0.0.1:' . $port . '/api/assets/update');
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-TOKEN: ' . (string) ($body['csrf_token'] ?? '')],
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    $answer = (string) curl_exec($h);
    return ['status' => (int) curl_getinfo($h, CURLINFO_HTTP_CODE), 'body' => $answer];
};
// Z0/Z1: a request that leaves the currency OUT keeps the currency the LOCK read,
// even when somebody changed it (EUR -> USD) while the request waited for the lock.
run($db, "INSERT INTO `tblAssets` (`siteID`, `name`, `currency`, `publicToken`) VALUES (?, 'Currency selftest lamp', 'EUR', ?)", 'is', [$site, bin2hex(random_bytes(16))]);
$lamp = (int) $db->insert_id;
$made['assets'][] = $lamp;
$lampToken = $freshToken('/assets/edit?id=' . $lamp);
$lampHolder = $holdRow('tblAssets', 'assetID', $lamp);
$lampSeen = $raceRequest('/api/assets/update', ['assetID' => $lamp, 'name' => 'Currency selftest lamp renamed', 'csrf_token' => $lampToken],
    $lampHolder, 'tblAssets', $lamp,
    static function (bool $waiting) use ($lampHolder, $lamp): void {
        $u = $lampHolder->prepare("UPDATE `tblAssets` SET `currency` = 'USD' WHERE `assetID` = ?");
        $u->bind_param('i', $lamp);
        $u->execute();
        $u->close();
        $lampHolder->commit();
        $lampHolder->close();
    }, 'json');
$lampRow = rows($db, 'SELECT `name`, `currency` FROM `tblAssets` WHERE `assetID` = ?', 'i', [$lamp])[0] ?? [];
check('Z0 the premise: the API request was seen waiting on the lock THIS test holds on THIS asset row', $lampSeen === true);
check('Z1 an API update that leaves the currency OUT, made while the currency changed EUR -> USD, renames the asset and keeps USD (the currency the lock read)',
    $raceLast['status'] === 200 && ($lampRow['name'] ?? '') === 'Currency selftest lamp renamed' && ($lampRow['currency'] ?? '') === 'USD',
    'HTTP ' . $raceLast['status'] . ' ' . json_encode($lampRow) . ' ' . mb_substr($raceLast['body'], 0, 200));
// Z2: an UNRECORDED asset updated through the API without a currency stays unrecorded.
run($db, "INSERT INTO `tblAssets` (`siteID`, `name`, `currency`, `publicToken`) VALUES (?, 'Currency selftest unrecorded', '', ?)", 'is', [$site, bin2hex(random_bytes(16))]);
$unrecorded = (int) $db->insert_id;
$made['assets'][] = $unrecorded;
$z2 = $apiPost(['assetID' => $unrecorded, 'status' => 'in-repair', 'csrf_token' => $freshToken('/assets/edit?id=' . $unrecorded)]);
$z2Row = rows($db, 'SELECT `status`, `currency` FROM `tblAssets` WHERE `assetID` = ?', 'i', [$unrecorded])[0] ?? [];
check('Z2 an UNRECORDED asset updated through the API without a currency: 200, status changed, currency still unrecorded',
    $z2['status'] === 200 && ($z2Row['status'] ?? '') === 'in-repair' && ($z2Row['currency'] ?? 'x') === '', 'HTTP ' . $z2['status'] . ' ' . json_encode($z2Row) . ' ' . mb_substr($z2['body'], 0, 200));
// Z3: an asset WITH a repair in its own currency, updated without a currency: allowed.
run($db, "INSERT INTO `tblAssets` (`siteID`, `name`, `currency`, `publicToken`) VALUES (?, 'Currency selftest desk', 'EUR', ?)", 'is', [$site, bin2hex(random_bytes(16))]);
$desk = (int) $db->insert_id;
$made['assets'][] = $desk;
run($db, "INSERT INTO `tblAssetMaintenance` (`siteID`, `assetID`, `title`, `costPence`, `currency`) VALUES (?, ?, 'Currency selftest desk repair', 300, 'EUR')", 'ii', [$site, $desk]);
$z3 = $apiPost(['assetID' => $desk, 'name' => 'Currency selftest desk renamed', 'csrf_token' => $freshToken('/assets/edit?id=' . $desk)]);
$z3Row = rows($db, 'SELECT `name`, `currency` FROM `tblAssets` WHERE `assetID` = ?', 'i', [$desk])[0] ?? [];
check('Z3 an asset with a repair, updated through the API without a currency: 200, renamed, still EUR',
    $z3['status'] === 200 && ($z3Row['name'] ?? '') === 'Currency selftest desk renamed' && ($z3Row['currency'] ?? '') === 'EUR', 'HTTP ' . $z3['status'] . ' ' . json_encode($z3Row) . ' ' . mb_substr($z3['body'], 0, 200));
// Z4/Z5: a save carrying a NEW file that is refused UNDER the invoice's lock (a payment
// in EUR recorded while it waited; the save asks to change to USD) keeps the OLD file
// on disk and in the row, and leaves the new file nowhere.
run($db, "INSERT INTO `tblVenueInvoices` (`siteID`, `venueID`, `issueDate`, `amountPence`, `currency`, `status`) VALUES (?, ?, CURDATE(), 2000, 'EUR', 'pending')", 'ii', [$site, $venue]);
$zInvoice = (int) $db->insert_id;
$made['invoices'][] = $zInvoice;
$zOld = $pdfOf('old');
$saveWithFile($zInvoice, $zOld);
$zOldPath = $fileOf($zInvoice);
$filesBefore = glob($uploadDir . '*');
$zNew = $pdfOf('new');
$zFields = ['csrf_token' => $freshToken('/venues/invoice?id=' . $zInvoice), 'action' => 'save', 'invoiceID' => (string) $zInvoice, 'venueID' => (string) $venue,
    'issueDate' => date('Y-m-d'), 'amount' => '20.00', 'currency' => 'USD', 'file' => new CURLFile($zNew, 'application/pdf', basename($zNew))];
$zHolder = $holdRow('tblVenueInvoices', 'invoiceID', $zInvoice);
$zSeen = $raceRequest('/venues/invoice-save', $zFields, $zHolder, 'tblVenueInvoices', $zInvoice,
    static function (bool $waiting) use ($zHolder, $site, $zInvoice): void {
        $p = $zHolder->prepare("INSERT INTO `tblVenueInvoicePayments` (`siteID`, `invoiceID`, `paidDate`, `amountPence`, `currency`) VALUES (?, ?, CURDATE(), 500, 'EUR')");
        $p->bind_param('ii', $site, $zInvoice);
        $p->execute();
        $p->close();
        $zHolder->commit();
        $zHolder->close();
    }, 'multipart');
$filesAfter = glob($uploadDir . '*');
$zRow = rows($db, 'SELECT `filePath`, `currency` FROM `tblVenueInvoices` WHERE `invoiceID` = ?', 'i', [$zInvoice])[0] ?? [];
check('Z4 the premise: the file save was seen waiting on the lock THIS test holds on THIS invoice row', $zSeen === true);
check('Z5 refused under the lock: the row still names the OLD file and is still EUR, the old file is on disk, and no new file was left on disk',
    ($zRow['filePath'] ?? null) === $zOldPath && ($zRow['currency'] ?? '') === 'EUR' && $zOldPath !== null && is_file($uploadDir . $zOldPath) === true
    && count($filesAfter) === count($filesBefore), json_encode([$zRow, $zOldPath, count($filesBefore), count($filesAfter)]));
@unlink($zOld);
@unlink($zNew);

// =============================================================================
// L. The ONE locking helper, Currency::withParentLocked() (round 6)
// =============================================================================
head('Section L — the locking helper: the transaction is open before the lock, and a throw leaves nothing open');
// L1/L2: the transaction must begin BEFORE the parent lock (#691's method), proved
// on the HELPER itself. A second PHP process calls Currency::withParentLocked() on an
// invoice; its work waits on a NAMED lock (GET_LOCK) this test holds — a wait that
// touches no row, so nothing but the helper's own transaction can still be holding
// the invoice row. While it waits, this test asks for the invoice row FOR UPDATE
// NOWAIT: with the transaction open that fails with 3572; with begin_transaction()
// removed the helper's lock was released at the end of its own statement and the
// request succeeds. (T1-T6 cannot tell: a locking read waits even in autocommit.
// Three earlier versions drove a real repair or line and passed with the
// transaction removed, because a waiting child INSERT holds a lock on its parent row
// of its own — through its foreign key, or through the subquery reading the parent's
// currency. Each was planted and caught.)
run($db, 'INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `createdAt`) VALUES (?, ?, ?, 1, NOW())', 'iss', [CU_HELPER, 'Selftest currency helper', 'currency-helper@selftest.invalid']);
run($db, "INSERT INTO `tblAssets` (`siteID`, `name`, `currency`, `publicToken`) VALUES (?, 'Currency selftest font', 'EUR', ?)", 'is', [$site, bin2hex(random_bytes(16))]);
$font = (int) $db->insert_id;
$made['assets'][] = $font;
run($db, "INSERT INTO `tblVenueInvoices` (`siteID`, `venueID`, `issueDate`, `amountPence`, `currency`, `status`) VALUES (?, ?, CURDATE(), 2500, 'EUR', 'pending')", 'ii', [$site, $venue]);
$lockInvoice = (int) $db->insert_id;
$made['invoices'][] = $lockInvoice;
$lockName = 'currency-selftest-' . bin2hex(random_bytes(4));
$gate = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$gate->query("SELECT GET_LOCK('" . $lockName . "', 0)");
$worker = $state['child'] . $sep . 'helper-worker.php';
file_put_contents($worker, '<?php declare(strict_types=1);' . "\n"
    . 'require ' . var_export($repo . $sep . 'web' . $sep . '_core' . $sep . 'Currency.php', true) . ";\n"
    . 'mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);' . "\n"
    . '$db = new mysqli(' . implode(', ', array_map(static fn ($v): string => var_export($v, true), [$dbHost, $dbUser, $dbPass, $dbName, $dbPort])) . ');' . "\n"
    . 'echo \Portal\Core\Currency::withParentLocked($db, "tblVenueInvoices", ' . $lockInvoice . ', ' . $site . ', static function (?string $c) use ($db): string {' . "\n"
    . '    $db->query("SELECT GET_LOCK(' . var_export($lockName, true) . ', 30)");' . "\n"
    . '    return "worked in " . (string) $c;' . "\n"
    . '});' . "\n");
$proc = proc_open([PHP_BINARY, $worker], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$waitingOnName = false;
for ($i = 0; $i < 100 && $waitingOnName === false; $i++) {
    $waitingOnName = (int) (rows($db, "SELECT COUNT(*) AS `n` FROM `information_schema`.`PROCESSLIST` WHERE `INFO` LIKE ? AND `STATE` LIKE '%lock%'", 's', ['%GET_LOCK(\'' . $lockName . '\', 30)%'])[0]['n'] ?? 0) > 0;
    if ($waitingOnName === false) {
        usleep(100000);
    }
}
$nowait = 'not tried';
$probe = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$probe->begin_transaction();
try {
    $q = $probe->prepare('SELECT `currency` FROM `tblVenueInvoices` WHERE `invoiceID` = ? FOR UPDATE NOWAIT');
    $q->bind_param('i', $lockInvoice);
    $q->execute();
    $q->get_result()->fetch_all();
    $q->close();
    $nowait = 'got the lock';
} catch (mysqli_sql_exception $e) {
    $nowait = (string) $e->getCode();
}
$probe->rollback();
$probe->close();
$gate->query("SELECT RELEASE_LOCK('" . $lockName . "')");
$gate->close();
$workerSaid = stream_get_contents($pipes[1]);
$workerErr  = stream_get_contents($pipes[2]);
proc_close($proc);
check('L1 the premise: the helper\'s work, in a second process, was seen WAITING on the named lock this test held (it had already locked the invoice)', $waitingOnName === true);
check('L2 while it waited, the invoice row was STILL LOCKED by the helper\'s open transaction: a NOWAIT request for it failed with 3572 (in autocommit it would have succeeded) — and the work then finished, given the invoice\'s EUR',
    $nowait === '3572' && trim((string) $workerSaid) === 'worked in EUR', 'NOWAIT: ' . $nowait . '; worker: ' . trim((string) $workerSaid) . ' ' . trim((string) $workerErr));

// L3: a THROW inside the work leaves no transaction open: what the work wrote is
// gone, and the next row written (an error row, as a caller would write) is
// committed and seen from another connection.
$threw = false;
try {
    Currency::withParentLocked($db, 'tblAssets', $font, $site, static function (?string $c) use ($db, $site, $font): void {
        run($db, "INSERT INTO `tblAssetMaintenance` (`siteID`, `assetID`, `title`, `costPenc`, `currency`) VALUES (?, ?, 'x', 1, 'EUR')", 'ii', [$site, $font]);
    });
} catch (Throwable $e) {
    $threw = true;
}
$writtenBefore = static fn (): int => (int) (rows($db, "SELECT COUNT(*) AS `n` FROM `tblAssetMaintenance` WHERE `assetID` = ? AND `title` = 'Currency selftest rolled back'", 'i', [$font])[0]['n'] ?? 0);
$threwAfterWrite = false;
try {
    Currency::withParentLocked($db, 'tblAssets', $font, $site, static function (?string $c) use ($db, $site, $font): void {
        run($db, "INSERT INTO `tblAssetMaintenance` (`siteID`, `assetID`, `title`, `costPence`, `currency`) VALUES (?, ?, 'Currency selftest rolled back', 1, 'EUR')", 'ii', [$site, $font]);
        throw new RuntimeException('planted failure after a write');
    });
} catch (RuntimeException $e) {
    $threwAfterWrite = $e->getMessage() === 'planted failure after a write';
}
$openHere = (int) (rows($db, 'SELECT COUNT(*) AS `n` FROM `information_schema`.`INNODB_TRX` WHERE `trx_mysql_thread_id` = CONNECTION_ID()')[0]['n'] ?? -1);
run($db, "INSERT INTO `tblErrors` (`siteID`, `errorTitle`) VALUES (?, 'Currency selftest marker')", 'i', [$site]);
$observer = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$markerSeen = (int) ($observer->query("SELECT COUNT(*) AS `n` FROM `tblErrors` WHERE `errorTitle` = 'Currency selftest marker'")->fetch_assoc()['n'] ?? 0);
$observer->close();
check('L3 a THROW inside the work — a failing statement, then a plain exception after a write — reaches the caller, leaves NO transaction open on the connection, undoes the write, and the error row written next is committed (seen from another connection)',
    $threw === true && $threwAfterWrite === true && $openHere === 0 && $writtenBefore() === 0 && $markerSeen === 1,
    json_encode(['threw' => $threw, 'threwAfterWrite' => $threwAfterWrite, 'open' => $openHere, 'kept' => $writtenBefore(), 'marker' => $markerSeen]));

// A giving statement for a donor who gave in several currencies (EUR, USD and GBP here,
// and one gift with none). The unrecorded GIFT is unlabelled; the statement must not be.
// #999 round 2: a donor who gave ONLY in euros, with an active Gift Aid
// declaration covering the gift. The statements preview's SUM(CASE …) writes
// `giftAidPence` onto their statement's log row; it must be 0 (H11b).
run($db, "INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`) VALUES ('Currency selftest euro donor', 'cu-euro-donor-999@selftest.invalid', 1)");
$euroDonor = (int) $db->insert_id;
register_shutdown_function(static function () use ($db, $euroDonor): void {
    try {
        run($db, 'DELETE FROM `tblGivingStatementLog` WHERE `donorID` = ?', 'i', [$euroDonor]);
        run($db, 'DELETE FROM `tblGivingEntry` WHERE `donorID` = ?', 'i', [$euroDonor]);
        run($db, 'DELETE FROM `tblGiftAidDeclaration` WHERE `donorID` = ?', 'i', [$euroDonor]);
        run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [$euroDonor]);
    } catch (Throwable $e) {
        echo "  ! the euro donor clean-up could not finish ({$e->getMessage()}).\n";
    }
});
run($db, "INSERT INTO `tblGiftAidDeclaration` (`siteID`, `donorID`, `status`, `validFrom`, `address`, `postcode`) VALUES (?, ?, 'active', DATE_SUB(CURDATE(), INTERVAL 1 YEAR), '5 Euro Way', 'AB5 5EU')",
    'ii', [$site, $euroDonor]);
run($db, "INSERT INTO `tblGivingEntry` (`siteID`, `donorID`, `categoryID`, `amountPence`, `currency`, `donatedAt`, `method`) VALUES (?, ?, ?, 5000, 'EUR', CURDATE(), 'cash')",
    'iii', [$site, $euroDonor, $category]);
$unlabelledBefore = Currency::unlabelledCount($db, $site);
$http('POST', '/giving/statements-generate', ['csrf_token' => $freshToken('/giving/statements'), 'from' => date('Y-01-01'), 'to' => date('Y-12-31')]);
$logRow = rows($db, 'SELECT `currency`, `totalPence`, `giftAidPence` FROM `tblGivingStatementLog` WHERE `siteID` = ? AND `donorID` = ?', 'ii', [$site, CU_ADMIN]);
check('H11 a statement covering several currencies is logged as "*" with totals 0 — never \'\' ("not recorded") — so the organisation page does not count it as unlabelled and labelling cannot stamp one currency on it',
    count($logRow) === 1 && $logRow[0]['currency'] === '*' && (int) $logRow[0]['totalPence'] === 0 && Currency::unlabelledCount($db, $site) === $unlabelledBefore
    && Currency::labelUnlabelled($db, $site, 'EUR') === $unlabelledBefore && rows($db, 'SELECT `currency` FROM `tblGivingStatementLog` WHERE `siteID` = ? AND `donorID` = ?', 'ii', [$site, CU_ADMIN])[0]['currency'] === '*',
    json_encode($logRow) . '; unlabelled before ' . var_export($unlabelledBefore, true) . ', now ' . var_export(Currency::unlabelledCount($db, $site), true));
$euroLog = rows($db, 'SELECT `currency`, `totalPence`, `giftAidPence` FROM `tblGivingStatementLog` WHERE `siteID` = ? AND `donorID` = ?', 'ii', [$site, $euroDonor]);
check('H11b a donor who gave ONLY in euros, with an active Gift Aid declaration, has a statement logged in EUR for 50.00 with a Gift Aid figure of 0 (#999: Gift Aid is claimed in pounds)',
    count($euroLog) === 1 && $euroLog[0]['currency'] === 'EUR' && (int) $euroLog[0]['totalPence'] === 5000 && (int) $euroLog[0]['giftAidPence'] === 0,
    json_encode($euroLog));
// Removed now, not only at shutdown: the main clean-up removes the giving
// category first, and this donor's gift points at it (a foreign key), so a
// shutdown-only removal ran too late and stopped the main clean-up part way.
foreach (['tblGivingStatementLog', 'tblGivingEntry', 'tblGiftAidDeclaration'] as $euroTable) {
    run($db, 'DELETE FROM `' . $euroTable . '` WHERE `donorID` = ?', 'i', [$euroDonor]);
}
run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [$euroDonor]);
run($db, 'DELETE FROM `tblGivingStatementLog` WHERE `siteID` = ? AND `donorID` = ?', 'ii', [$site, CU_ADMIN]);

// =============================================================================
// N. The installation's OWN country and currency (#967, #991)
// =============================================================================
// Before #967/#991 no checked page could change either after installation: the
// organisation page wrote only the open organisation's own rows, and the general
// Settings editor refused the currency and took the country as free text. The
// organisation page now has a card, for a global administrator only, that
// changes the installation's two rows (`siteID IS NULL`) with the same rules as
// an organisation's: a known country, a usable currency, "none" allowed, and a
// currency change relabelling nothing (D-139).
head('Section N — the installation\'s own country and currency (#967, #991)');
$installRow = static function (string $key) use ($db): ?string {
    $r = rows($db, 'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);

    return $r === [] ? null : (string) $r[0]['settingValue'];
};
$ownRow = static function (string $key) use ($db, $site): ?string {
    $r = rows($db, 'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?', 'si', [$key, $site]);

    return $r === [] ? null : (string) $r[0]['settingValue'];
};
/** Every money row's currency, table by table, so "nothing was relabelled" is checked over ALL of them. */
$moneyLabels = static function () use ($db): string {
    $out = [];
    foreach (array_merge(Currency::OWN_TABLES, array_keys(Currency::CHILD_TABLES)) as $table) {
        foreach (rows($db, 'SELECT `currency`, COUNT(*) AS `n` FROM `' . $table . '` GROUP BY `currency` ORDER BY `currency`') as $r) {
            $out[] = $table . ':' . $r['currency'] . '=' . $r['n'];
        }
    }

    return implode(' ', $out);
};
/** The value of one hidden or selected control in a form, as drawn. */
$selectedOn = static function (string $html, string $name): string {
    if (preg_match('#<select[^>]*name="' . preg_quote($name, '#') . '"[^>]*>(.*?)</select>#s', $html, $m) !== 1) {
        return '(no such select)';
    }

    return preg_match('#<option value="([^"]*)"[^>]*\bselected\b#', $m[1], $o) === 1 ? html_entity_decode($o[1], ENT_QUOTES, 'UTF-8') : '(none selected)';
};
$hiddenOn = static function (string $html, string $name): ?string {
    return preg_match('#<input type="hidden" name="' . preg_quote($name, '#') . '" value="([^"]*)">#', $html, $m) === 1
        ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
};
/** The installation card's fields exactly as a browser would send them untouched. */
$cardAsDrawn = static function (string $html) use ($tokenOn, $selectedOn, $hiddenOn): array {
    return [
        'csrf_token'                   => $tokenOn($html),
        'form'                         => 'installation',
        'installationCountryCode'      => $selectedOn($html, 'installationCountryCode'),
        'installationCountryCodeDrawn' => (string) $hiddenOn($html, 'installationCountryCodeDrawn'),
        'installationCurrency'         => $selectedOn($html, 'installationCurrency'),
        'installationCurrencyDrawn'    => (string) $hiddenOn($html, 'installationCurrencyDrawn'),
    ];
};

// The organisation FOLLOWS the installation for both, so a change reaches it.
$setting('org.address.countryCode', 'FR', null);
$setting('org.currency', 'EUR', null);
run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` IN (\'org.address.countryCode\', \'org.currency\') AND `siteID` = ?', 'i', [$site]);
$cardPage = $http('GET', '/admin/settings/organisation');
check('N1 a global administrator is shown the "whole installation" card, its boxes holding the INSTALLATION\'s country and currency (France, EUR), each with the value it was drawn with beside it',
    $cardPage['status'] === 200 && str_contains($cardPage['body'], 'id="installation"') === true
    && $selectedOn($cardPage['body'], 'installationCountryCode') === 'FR' && $selectedOn($cardPage['body'], 'installationCurrency') === 'EUR'
    && $hiddenOn($cardPage['body'], 'installationCountryCodeDrawn') === 'FR' && $hiddenOn($cardPage['body'], 'installationCurrencyDrawn') === 'EUR',
    'HTTP ' . $cardPage['status'] . '; country ' . $selectedOn($cardPage['body'], 'installationCountryCode') . ', currency ' . $selectedOn($cardPage['body'], 'installationCurrency'));

$labelsBefore = $moneyLabels();
$placeLogsSql    = "SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `activityType` = 'InstallationPlaceSaved' AND `activityDescription` LIKE '%country FR to DE%currency EUR to CHF%'";
$placeLogsBefore = (int) (rows($db, $placeLogsSql)[0]['n'] ?? 0);
$changedCard = $http('POST', '/admin/settings/organisation/save',
    ['installationCountryCode' => 'DE', 'installationCurrency' => 'CHF'] + $cardAsDrawn($cardPage['body']));
check('N2 changing them there stores Germany and CHF in the INSTALLATION\'s rows, and gives the organisation no row of its own',
    $changedCard['status'] === 302 && $installRow('org.address.countryCode') === 'DE' && $installRow('org.currency') === 'CHF'
    && $ownRow('org.address.countryCode') === null && $ownRow('org.currency') === null,
    'HTTP ' . $changedCard['status'] . '; installation ' . var_export($installRow('org.address.countryCode'), true) . '/' . var_export($installRow('org.currency'), true)
    . '; own ' . var_export($ownRow('org.address.countryCode'), true) . '/' . var_export($ownRow('org.currency'), true));
// Asked of the PAGE, not of the classes: in this process the classes have no
// connection (App::db() has none to give, and their reads then answer '' —
// which is also why C2's "Currency::ofInstallation() === ''" cannot fail).
// (The same page answer carries the save's message, read by N5: a page is drawn
// with the message once, so N3 and N5 share this one GET.)
$followPage = $http('GET', '/admin/settings/organisation')['body'];
check('N3 ...so the organisation, which follows the installation, now reads Germany and CHF as "Same as the installation"',
    str_contains($followPage, 'Same as the installation: Germany') === true && str_contains($followPage, 'Same as the installation: CHF') === true);
check('N4 ...and NOT ONE money row anywhere was relabelled: every table\'s currencies are exactly as before (D-139)',
    $moneyLabels() === $labelsBefore, "before: {$labelsBefore}\nafter:  " . $moneyLabels());
$saidAfter = $followPage;
check('N5 ...the administrator is told the new currency applies to new records only, and that outside-provider spending is not converted',
    str_contains($saidAfter, 'The installation&#039;s currency is now CHF. Every amount already recorded keeps the currency it was recorded in') === true
    && str_contains($saidAfter, 'are not converted') === true);
check('N6 ...and the change is in the activity log, old and new', (int) (rows($db, $placeLogsSql)[0]['n'] ?? 0) === $placeLogsBefore + 1);
$before_n7 = (int) (rows($db, 'SELECT COALESCE(MAX(`projectID`), 0) AS `m` FROM `tblProject`')[0]['m']);
$http('POST', '/projects/manage-save', ['csrf_token' => $freshToken('/projects/manage'), 'title' => 'Currency selftest installation roof', 'targetAmount' => '10.00', 'status' => 'active', 'isPublic' => '0', 'description' => 'x']);
$newProj = rows($db, 'SELECT `projectID`, `currency` FROM `tblProject` WHERE `projectID` > ? AND `siteID` = ?', 'ii', [$before_n7, $site]);
foreach ($newProj as $p) {
    $made['projects'][] = (int) $p['projectID'];
}
check('N7 a NEW record made after the change is in the installation\'s new currency, CHF (the organisation follows it)',
    count($newProj) === 1 && $newProj[0]['currency'] === 'CHF', json_encode($newProj));

// An untouched Save from a page opened BEFORE another change writes nothing (#875's shape).
$olderCard = $http('GET', '/admin/settings/organisation')['body'];
$setting('org.address.countryCode', 'IT', null);   // another global administrator, meanwhile
$untouched = $http('POST', '/admin/settings/organisation/save', $cardAsDrawn($olderCard));
check('N8 a page opened before another change, saved UNTOUCHED, does not put its old value back: the country stays Italy, the currency CHF',
    $untouched['status'] === 302 && $installRow('org.address.countryCode') === 'IT' && $installRow('org.currency') === 'CHF',
    var_export($installRow('org.address.countryCode'), true) . '/' . var_export($installRow('org.currency'), true));
check('N9 ...and says the country was changed after the page was opened, naming what is in force',
    str_contains($http('GET', '/admin/settings/organisation')['body'], 'The installation&#039;s country was changed after this page was opened, so it was left as it is now: Italy.') === true);

// A country that is not on the list is refused, and so is the WHOLE save.
$refusedPage = $http('GET', '/admin/settings/organisation')['body'];
$refusedSave = $http('POST', '/admin/settings/organisation/save',
    ['installationCountryCode' => 'GBR', 'installationCurrency' => 'USD'] + $cardAsDrawn($refusedPage));
check('N10 a country that is not on the portal\'s list ("GBR") is refused, and the currency posted beside it is NOT saved either',
    $installRow('org.address.countryCode') === 'IT' && $installRow('org.currency') === 'CHF'
    && str_contains($http('GET', '/admin/settings/organisation')['body'], 'country chosen is not one the portal knows') === true);
$refusedSave = $http('POST', '/admin/settings/organisation/save',
    ['installationCurrency' => 'JPY'] + $cardAsDrawn($http('GET', '/admin/settings/organisation')['body']));
check('N11 a currency the portal cannot use (JPY, not counted in hundredths) is refused too', $installRow('org.currency') === 'CHF');

// Text stored before #967 that is not a country is SHOWN, and an untouched Save keeps it rather than failing.
$setting('org.address.countryCode', 'GBR', null);
$gbrPage = $http('GET', '/admin/settings/organisation')['body'];
check('N12 a stored "GBR" is shown selected, with a warning that the portal ignores it (it used to be invisible)',
    $selectedOn($gbrPage, 'installationCountryCode') === 'GBR' && str_contains($gbrPage, 'data-installation-country-unusable') === true
    && str_contains($gbrPage, 'Same as the installation (none chosen yet)') === true);
$http('POST', '/admin/settings/organisation/save', $cardAsDrawn($gbrPage));
check('N13 ...and an untouched Save keeps it as it is: not refused, not cleared', $installRow('org.address.countryCode') === 'GBR'
    && str_contains($http('GET', '/admin/settings/organisation')['body'], 'Nothing was different') === true);
$http('POST', '/admin/settings/organisation/save', ['installationCountryCode' => ''] + $cardAsDrawn($http('GET', '/admin/settings/organisation')['body']));
check('N14 "No country chosen" is allowed, as the installer allows it: the row is emptied, as the install script seeds it', $installRow('org.address.countryCode') === '');

// The general Settings editor now refuses the country, at the installation's level.
$countryRowId = (int) (rows($db, "SELECT `settingID` FROM `tblSettings` WHERE `settingKey` = 'org.address.countryCode' AND `siteID` IS NULL")[0]['settingID'] ?? 0);
$http('POST', '/settings/save', ['csrf_token' => $freshToken('/settings'), 'settingID' => (string) $countryRowId, 'settingKey' => 'org.address.countryCode', 'settingValue' => 'GBR']);
check('N15 the general Settings editor refuses to change the installation\'s country (it used to store "GBR" there, which then meant no country)',
    $installRow('org.address.countryCode') === '');

// An administrator of THIS organisation only is not shown the card and cannot post it.
$orgPassword = 'Cu' . bin2hex(random_bytes(8)) . '!9y';
run($db, 'INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, ?, 1, 0, 0, NOW())',
    'iss', [CU_ORGADMIN, 'Selftest currency organisation administrator', 'currency-orgadmin@selftest.invalid']);
run($db, 'INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`) VALUES (?, ?, 1, 1)', 'ii', [$site, CU_ORGADMIN]);
run($db, 'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)',
    'iss', [CU_ORGADMIN, 'selftest-currency-orgadmin', password_hash($orgPassword, PASSWORD_DEFAULT)]);
$orgJar  = $child . $sep . 'cookies-orgadmin.txt';
$httpOrg = static function (string $method, string $path, array $post = []) use ($port, $orgJar): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $orgJar, CURLOPT_COOKIEFILE => $orgJar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw      = (string) curl_exec($handle);
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => substr($raw, $headSize)];
};
$httpOrg('POST', '/login', ['csrf_token' => $tokenOn($httpOrg('GET', '/login')['body']), 'username' => 'selftest-currency-orgadmin', 'password' => $orgPassword]);
$orgView = $httpOrg('GET', '/admin/settings/organisation');
check('N16 an administrator of this organisation only opens the page (premise) and is NOT shown the installation card',
    $orgView['status'] === 200 && str_contains($orgView['body'], 'id="currency"') === true && str_contains($orgView['body'], 'id="installation"') === false,
    'HTTP ' . $orgView['status']);
$refusedBefore = (int) (rows($db, "SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` = 'SettingsGroupSaveRefused'", 'i', [CU_ORGADMIN])[0]['n'] ?? 0);
$httpOrg('POST', '/admin/settings/organisation/save', ['csrf_token' => $tokenOn($orgView['body']), 'form' => 'installation',
    'installationCountryCode' => 'ES', 'installationCurrency' => 'USD']);
check('N17 ...and a post of it is REFUSED: the installation\'s rows are unchanged, and the refusal is recorded',
    $installRow('org.address.countryCode') === '' && $installRow('org.currency') === 'CHF'
    && (int) (rows($db, "SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` = 'SettingsGroupSaveRefused'", 'i', [CU_ORGADMIN])[0]['n'] ?? 0) === $refusedBefore + 1,
    var_export($installRow('org.address.countryCode'), true) . '/' . var_export($installRow('org.currency'), true));
check('N18 ...with words that say why', str_contains($httpOrg('GET', '/admin/settings/organisation')['body'], 'only a global administrator can change them') === true);

echo "\n" . ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
