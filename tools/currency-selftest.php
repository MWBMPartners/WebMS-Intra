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
 *   B  the schema: no GBP default, the four old settings gone, org.currency
 *      seeded empty, and Currency::MONEY_TABLES matching every table that
 *      holds an organisation's money — FAILING BOTH WAYS, because a table
 *      missing from that list would let the currency change under money it
 *      holds;
 *   C  an organisation in EUROS: the organisation page pre-selects the
 *      currency from the country and saves it; a project made over HTTP is
 *      stored in EUR (it used to be stored as GBP whatever the setting said);
 *      and every money page answers in full with no pound sign, showing "€"
 *      where it shows an amount;
 *   D  the lock: once money is recorded the page shows the currency disabled
 *      with the reason, and a posted change is refused with nothing written;
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
 *   - two administrators racing (one recording money while another changes
 *     the currency): PHP's built-in server runs one request at a time here.
 *     The lock is a locking read inside the save's transaction; that is read,
 *     not driven;
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
$gbpDefaults = rows($db, "SELECT `TABLE_NAME` AS `t` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() "
    . "AND `COLUMN_NAME` = 'currency' AND `COLUMN_DEFAULT` IS NOT NULL");
$curColumns  = rows($db, "SELECT `TABLE_NAME` AS `t`, `IS_NULLABLE` AS `n` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `COLUMN_NAME` = 'currency'");
check('B1 no money column has a default currency: all nine `currency` columns are NOT NULL with NO default, so a writer that forgets the currency fails instead of storing pounds',
    $gbpDefaults === [] && count($curColumns) === 9 && array_filter($curColumns, static fn (array $r): bool => $r['n'] !== 'NO') === [],
    'with a default: ' . json_encode(array_column($gbpDefaults, 't')) . '; columns: ' . count($curColumns));
$oldKeys = rows($db, "SELECT `settingKey` FROM `tblSettings` WHERE `settingKey` IN ('giving.currency', 'projects.currency', 'payments.currency', 'venues.currency', 'site.currency')");
$orgRow  = rows($db, "SELECT `siteID`, `settingValue`, `defaultValue` FROM `tblSettings` WHERE `settingKey` = 'org.currency'");
check('B2 the four per-app currency settings are not seeded, and org.currency is seeded once, installation-wide, EMPTY',
    $oldKeys === [] && count($orgRow) === 1 && $orgRow[0]['siteID'] === null && $orgRow[0]['settingValue'] === '' && $orgRow[0]['defaultValue'] === '',
    json_encode([$oldKeys, $orgRow]));

// Every table holding an organisation's money: it has a siteID and either a
// `currency` column or a whole-number/decimal column whose name says money.
// Each must be in Currency::MONEY_TABLES, or below with the reason it is not.
// (A table with no siteID — a claim's lines, a count's envelopes — is reached
// through its parent, which IS in the list, and is not judged here.)
$notOrganisationMoney = [
    'tblAiUsage'               => 'the INSTALLATION\'s spending with an AI provider (one key pays for every organisation)',
    'tblContentTranslation'    => 'a cache of translations; its cost is the installation\'s spending with a provider',
    'tblTranslationUsage'      => 'the installation\'s spending with a translation provider',
    'tblSmsMessage'            => 'the installation\'s spending with a text-message provider',
    'tblAssetValueHistory'     => 'an earlier valuation of an asset; a valuation also sets the asset\'s current value (tblAssets, in the list)',
    'tblVenueInvoiceLines'     => 'a line of a venue invoice (tblVenueInvoices), which is in the list',
    'tblVenueInvoicePayments'  => 'a payment of a venue invoice (tblVenueInvoices), which is in the list',
];
$moneyShaped = [];
foreach (rows($db, "SELECT c.`TABLE_NAME` AS `t`, c.`COLUMN_NAME` AS `c`, c.`DATA_TYPE` AS `d` FROM `information_schema`.`COLUMNS` c "
    . "WHERE c.`TABLE_SCHEMA` = DATABASE() AND EXISTS (SELECT 1 FROM `information_schema`.`COLUMNS` s WHERE s.`TABLE_SCHEMA` = c.`TABLE_SCHEMA` "
    . "AND s.`TABLE_NAME` = c.`TABLE_NAME` AND s.`COLUMN_NAME` = 'siteID')") as $col) {
    $isMoney = $col['c'] === 'currency'
        || (in_array($col['d'], ['int', 'bigint', 'decimal', 'mediumint', 'smallint'], true) === true
            && preg_match('/(Pence|Amount|^amount|^cashTotal|^chequeTotal|^envelopeTotal|^totalAmount|^payAmount|^unitCost|^lineTotal)/', (string) $col['c']) === 1);
    if ($isMoney === true) {
        $moneyShaped[(string) $col['t']] = true;
    }
}
$moneyShaped = array_keys($moneyShaped);
sort($moneyShaped);
$unlisted = array_values(array_filter($moneyShaped, static fn (string $t): bool
    => isset(Currency::MONEY_TABLES[$t]) === false && isset($notOrganisationMoney[$t]) === false));
$stale = [];
foreach (array_merge(array_keys(Currency::MONEY_TABLES), array_keys($notOrganisationMoney)) as $t) {
    if (in_array($t, $moneyShaped, true) === false) {
        $stale[] = $t;
    }
}
check('B3 every table holding an organisation\'s money is in Currency::MONEY_TABLES or named here with the reason it is not, and every name in either list is a real money table (fails BOTH ways)',
    $unlisted === [] && $stale === [], 'not listed: ' . json_encode($unlisted) . '; listed but not a money table: ' . json_encode($stale));
$badCondition = [];
foreach (Currency::MONEY_TABLES as $t => $condition) {
    try {
        rows($db, 'SELECT 1 FROM `' . $t . '` WHERE `siteID` = 0' . ($condition !== '' ? ' AND ' . $condition : '') . ' LIMIT 1');
    } catch (Throwable $e) {
        $badCondition[] = $t . ': ' . $e->getMessage();
    }
}
check('B4 every table and condition in Currency::MONEY_TABLES runs against this schema (a broken one would read as "could not check", which locks the currency for ever)',
    $badCondition === [], implode(' | ', $badCondition));

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
$made = ['settings' => [], 'gifts' => [], 'projects' => [], 'payments' => [], 'categories' => [], 'claims' => [], 'depts' => []];
$removeAll = static function () use ($db, &$made): void {
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
    foreach ($made['claims'] as $id) {
        run($db, 'DELETE FROM `tblExpenseClaims` WHERE `claimID` = ?', 'i', [$id]);
    }
    foreach ($made['depts'] as $id) {
        run($db, 'DELETE FROM `tblDepts` WHERE `deptID` = ?', 'i', [$id]);
    }
    foreach ($made['categories'] as $id) {
        run($db, 'DELETE FROM `tblGivingCategory` WHERE `categoryID` = ?', 'i', [$id]);
    }
    run($db, "DELETE FROM `tblPayment` WHERE `userID` = ?", 'i', [CU_ADMIN]);
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

// No organisation money of this site may exist yet, or C's "no money recorded" premise is false.
$premise = Currency::whyCannotChange($db, $site, 'GBP', 'EUR', false);
if ($premise !== '') {
    refuse('the database already holds money for organisation ' . $site . ', so the first-choice checks cannot be made: ' . $premise);
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
// And an expense claim, which keeps only a NUMBER: it is in the organisation's currency.
// Without one, Admin, Reports draws no total, and a pound sign put back there passed every
// check (planted, 11 November 2026).
run($db, 'INSERT INTO `tblDepts` (`siteID`) VALUES (?)', 'i', [$site]);
$dept = (int) $db->insert_id;
$made['depts'][] = $dept;
run($db, "INSERT INTO `tblExpenseClaims` (`siteID`, `userID`, `deptID`, `claimTitle`, `claimDate`, `totalAmount`, `status`) VALUES (?, ?, ?, 'Currency selftest claim', CURDATE(), 250.00, 'Pending')",
    'iii', [$site, CU_ADMIN, $dept]);
$made['claims'][] = (int) $db->insert_id;
// And a pledge to the project, not yet paid: Projects, manage lists it, in the PROJECT's
// currency (that list spans every project, so there is no one project to ask).
if (isset($proj[0]['projectID']) === true) {
    run($db, "INSERT INTO `tblProjectPledge` (`projectID`, `donorID`, `amountPence`) VALUES (?, ?, 7700)", 'ii', [(int) $proj[0]['projectID'], CU_ADMIN]);
}

$pages = [
    '/giving', '/giving/manage', '/giving/reports', '/giving/campaigns', '/giving/categories', '/giving/statements',
    '/projects', '/projects/view?slug=' . urlencode((string) ($proj[0]['slug'] ?? '')), '/projects/manage',
    '/payments', '/expenses/submit', '/expenses/approve', '/expenses/treasury',
    '/venues/invoices', '/venues/agreements', '/resources', '/resources/manage',
    '/assets/value-report', '/admin/reports', '/admin/translation', '/admin/transcription', '/admin/ai-assist', '/admin/sms',
    '/account/recurring', '/projects/my-pledges', '/giving/give',
];
$pound = [];
$broken = [];
foreach ($pages as $path) {
    $answer = $http('GET', $path);
    if ($answer['status'] !== 200 || str_contains($answer['body'], '</html>') === false) {
        $broken[] = $path . ' (HTTP ' . $answer['status'] . ')';
        continue;
    }
    if (str_contains($answer['body'], '£') === true || str_contains($answer['body'], '&pound;') === true) {
        // Gift Aid is a UK tax scheme, stated in pounds by law, and only its own page says so.
        $pound[] = $path;
    }
}
check('C5 every money page loaded answers in full (HTTP 200, the page finished), as a global administrator', $broken === [], 'not whole: ' . implode(', ', $broken));
check('C6 and NOT ONE of them prints a pound sign for an organisation in euros (' . count($pages) . ' pages)', $pound === [], 'a pound sign on: ' . implode(', ', $pound));
$manage   = $http('GET', '/giving/manage')['body'];
$projects = $http('GET', '/projects')['body'];
check('C7 where an amount is shown it is in euros: the gift as €1,234.50 on Giving, manage, and the project\'s target in € on Projects',
    str_contains($manage, '€1,234.50') === true && preg_match('/€1,500\.00/', $projects) === 1,
    'manage has €1,234.50: ' . var_export(str_contains($manage, '€1,234.50'), true) . '; projects has €1,500.00: ' . var_export(preg_match('/€1,500\.00/', $projects) === 1, true));

$pledges = $http('GET', '/projects/manage')['body'];
check('C9 the pledge list on Projects, manage shows the unpaid pledge in its project\'s currency, €77.00',
    str_contains($pledges, '€77.00') === true && str_contains($pledges, '£') === false);
$reports  = $http('GET', '/admin/reports')['body'];
$ownList  = $http('GET', '/expenses/submit')['body'];
check('C8 an expense claim, which stores only a number, is shown in the organisation\'s currency: Reports\' total value is €250.00, and so is the claim on its claimant\'s own list',
    str_contains($reports, 'Total value: <strong>€250.00</strong>') === true && str_contains($ownList, '€250.00') === true,
    'reports: ' . (preg_match('/Total value: <strong>[^<]*<\/strong>/', $reports, $mm) === 1 ? $mm[0] : '(no total)') . '; own list has €250.00: ' . var_export(str_contains($ownList, '€250.00'), true));

// =============================================================================
// D. The lock: money is recorded now
// =============================================================================
head('Section D — once money is recorded, the currency cannot change');
$locked = $http('GET', '/admin/settings/organisation');
check('D1 the page shows the currency box DISABLED and says why (money is recorded)',
    preg_match('/<select[^>]*id="currency"[^>]*disabled[^>]*>/', $locked['body']) === 1 && str_contains($locked['body'], 'data-currency-locked-reason') === true
    && str_contains($locked['body'], 'money is already recorded in it') === true);
$refused = $http('POST', '/admin/settings/organisation/save', ['csrf_token' => $tokenOn($locked['body']), 'currency' => 'USD', 'countryCode' => '']);
$flash   = $http('GET', '/admin/settings/organisation')['body'];
check('D2 a posted change to USD is REFUSED, "Nothing was saved", and the organisation\'s currency is still EUR',
    $refused['status'] === 302 && $ownCurrency() === 'EUR' && str_contains($flash, 'Nothing was saved. This organisation&#039;s currency is EUR and cannot be changed') === true,
    'own row: ' . var_export($ownCurrency(), true));
$sameAs = $http('POST', '/admin/settings/organisation/save', ['csrf_token' => $tokenOn($flash), 'currency' => '', 'countryCode' => '']);
check('D3 so is "Same as the installation" (which would switch it to the installation\'s: none here), and the own row stays',
    $sameAs['status'] === 302 && $ownCurrency() === 'EUR');
$untouched = $http('POST', '/admin/settings/organisation/save', ['csrf_token' => $freshToken('/admin/settings/organisation'), 'countryCode' => '']);
check('D4 a Save with the currency box absent (as the disabled box sends it) is accepted and leaves the currency alone (#677: ask whether the field ARRIVED)',
    $untouched['status'] === 302 && $ownCurrency() === 'EUR' && str_contains($http('GET', '/admin/settings/organisation')['body'], 'Nothing was saved') === false);

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

echo "\n" . ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
