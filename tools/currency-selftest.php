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
        run($db, 'DELETE FROM `tblCountSessions` WHERE `countSessionID` = ?', 'i', [$id]);
    }
    foreach ($made['campaigns'] as $id) {
        run($db, 'DELETE FROM `tblPledgeCampaigns` WHERE `campaignID` = ?', 'i', [$id]);
    }
    run($db, 'DELETE FROM `tblGiftAidDeclaration` WHERE `donorID` = ?', 'i', [CU_ADMIN]);
    run($db, 'DELETE FROM `tblGivingStatementLog` WHERE `donorID` = ?', 'i', [CU_ADMIN]);
    foreach ($made['invoices'] as $id) {
        run($db, 'DELETE FROM `tblVenueInvoicePayments` WHERE `invoiceID` = ?', 'i', [$id]);
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
    $assetCurrency($piano) === 'EUR' && str_contains($usdFlash, 'repair or valuation record(s) in EUR') === true);
run($db, "INSERT INTO `tblAssets` (`siteID`, `name`, `currency`, `publicToken`, `purchaseCostPence`) VALUES (?, 'Currency selftest chair', 'EUR', ?, 1000)", 'is', [$site, bin2hex(random_bytes(16))]);
$chair = (int) $db->insert_id;
$made['assets'][] = $chair;
$http('POST', '/assets/save', $assetPost($chair, 'Currency selftest chair', 'USD'));
check('H7 ...while an asset with no repairs or valuations CAN change (so H6 refused for the repair, not for another reason)', $assetCurrency($chair) === 'USD', $assetCurrency($chair));

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

// A giving statement for a donor who gave in several currencies (EUR, USD and GBP here,
// and one gift with none). The unrecorded GIFT is unlabelled; the statement must not be.
$unlabelledBefore = Currency::unlabelledCount($db, $site);
$http('POST', '/giving/statements-generate', ['csrf_token' => $freshToken('/giving/statements'), 'from' => date('Y-01-01'), 'to' => date('Y-12-31')]);
$logRow = rows($db, 'SELECT `currency`, `totalPence`, `giftAidPence` FROM `tblGivingStatementLog` WHERE `siteID` = ? AND `donorID` = ?', 'ii', [$site, CU_ADMIN]);
check('H11 a statement covering several currencies is logged as "*" with totals 0 — never \'\' ("not recorded") — so the organisation page does not count it as unlabelled and labelling cannot stamp one currency on it',
    count($logRow) === 1 && $logRow[0]['currency'] === '*' && (int) $logRow[0]['totalPence'] === 0 && Currency::unlabelledCount($db, $site) === $unlabelledBefore
    && Currency::labelUnlabelled($db, $site, 'EUR') === $unlabelledBefore && rows($db, 'SELECT `currency` FROM `tblGivingStatementLog` WHERE `siteID` = ? AND `donorID` = ?', 'ii', [$site, CU_ADMIN])[0]['currency'] === '*',
    json_encode($logRow) . '; unlabelled before ' . var_export($unlabelledBefore, true) . ', now ' . var_export(Currency::unlabelledCount($db, $site), true));
run($db, 'DELETE FROM `tblGivingStatementLog` WHERE `siteID` = ? AND `donorID` = ?', 'ii', [$site, CU_ADMIN]);

echo "\n" . ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
