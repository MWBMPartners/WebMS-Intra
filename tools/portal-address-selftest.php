<?php
// Path: tools/portal-address-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The portal's own web address, and every link it sends out — self-test 🌐✉️ (#500)
 * -----------------------------------------------------------------------------
 * WHAT THIS HOLDS IN PLACE
 * ------------------------
 * No web address is built into the product, and the address in a link the
 * portal sends out is decided in ONE place, web/_core/PortalAddress.php: the
 * address an administrator set (the `site.url` setting), else the address of
 * the request, else nothing. Until that existed, "Forgot password" sent with a
 * made-up host name mailed a working reset link pointing at the made-up host,
 * and a reminder for one organisation went out with another's address. Neither
 * fault makes any page fail, so this file runs the portal and reads the mail.
 *
 * WHAT IT DOES
 * ------------
 * It copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file and encryption key, starts PHP's built-in web server on it
 * behind a small router that imitates `.htaccess`, and starts ONE stand-in
 * mail server (`tools/maillib/tests/smtp-standin.php`, unchanged) that records
 * what it is sent and sends nothing anywhere. No file of the portal is changed
 * in the copy. The scaffolding is the mail pages self-test's, cut down.
 *
 *   A   The rules for an address somebody types (PortalAddress::check()),
 *       with that one file loaded ALONE, as the installer will load it.
 *   D   The check that keeps our own domain out (check_no_hardcoded_domains.py):
 *       clean on this repository, and on small made-up repositories it fires
 *       on a planted line, honours its pending list, and fails on a stale one.
 *   B   The database: what a fresh install holds, and what migration 215 does
 *       to an older one, run against COPIES of the two tables it touches.
 *   F   PortalAddress itself, in a PHP process of its own with a made-up
 *       request: set, not set, unusable, unreadable; one organisation, and
 *       several told apart in each of the three ways; an organisation that
 *       cannot be found, and one that is switched off; and the question the
 *       dashboard asks ("can a visitor choose the host name?") held to what
 *       the link builder really does.
 *   M   The mail, on the real pages: an invitation, a password reset and an
 *       erasure confirmation each asked for with a made-up host name, the
 *       reminder job for two organisations in "own address each" and "first
 *       part of the address" modes, an expense e-mail, and a newsletter
 *       with no address to give and then with one.
 *   C   The "Web addresses" card: what it refuses, what it saves, who may save.
 *   N   The two notices on the admin dashboard: what the first says in each
 *       state it can be in, and its "Use this address" button (who is shown
 *       it, what pressing it saves, and what the save refuses).
 *   H   The Help page, with and without a support address, and what the
 *       administrator's guide says about the portal's address.
 *   Q   The QR pictures on the three pages that show one: their address is
 *       the registered one and answers with a picture.
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Every account it makes is numbered 941000 to 941999, its two
 *   organisations 941901 and 941902, its newsletter, form and order of
 *   service 941801, 941811 and 941821, and every record it causes carries a
 *   192.0.2.x address (kept for examples, RFC 5737), one of those numbers, or
 *   an address ending `f4.selftest.invalid`. They are removed at the START (in
 *   case an earlier run was interrupted) and at the END.
 * - Part B runs migration 215 against COPIES of tblSettings and tblMigrations,
 *   made for the run and dropped afterwards, never against the real tables.
 * - It changes installation-wide settings for the run, lends the default
 *   organisation an address, and puts each back exactly. So do not run it at
 *   the same moment as another test on the same database.
 * - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *   temporary folder). It refuses if that folder contains the repository or is
 *   inside it. The child is deleted at the end only if it still holds this
 *   run's marker. `web/_auth_keys` is never read or copied.
 * - Both servers are started with their commands as lists (no shell), and each
 *   is stopped by its own process number, never by a name. It refuses to start
 *   if anything already answers on its web port. Every answer must carry this
 *   run's header.
 * - The clean-up also runs from a shutdown function, so a crash part way still
 *   puts things back. WHAT IT CANNOT SURVIVE: being killed outright.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - That a real mail server accepts a message, or that one arrives. Nothing
 *   here touches a real mail server; no e-mail leaves this machine.
 * - That an address really reaches the portal. Every name here is made up.
 * - The other e-mails that carry a link (event invitations, the prayer chain,
 *   the venue and asset reminders, approvals, giving statements, the
 *   found-item report, the backup alert). They call the same function; only
 *   the six named under M are sent here.
 * - That the newsletter's e-mail arrives looking right: it is sent with no
 *   content blocks, and only its unsubscribe link is read.
 * - The link shown for copying on the forms and order-of-service pages, or
 *   the QR sample page. Part Q opens the first two for their QR picture
 *   only, and it does not read what the picture encodes.
 * - The dashboard's button in a real browser. Part N sends exactly the
 *   fields the page wrote; it does not click anything.
 * - A page at phone width, or how anything looks. Those need a browser.
 * - Apache, a real proxy or https: the router only imitates `.htaccess`, on
 *   plain http on 127.0.0.1.
 * - MariaDB. It has been run on MySQL 8.0.36 and 8.4.11.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/portal-address-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9660). The database must already
 *         hold `web/_sql/full_schema.sql`. Needs PHP's curl, openssl and
 *         sodium extensions, `proc_open()`, and `python3` for part D.
 * Exit:   0 only when every check ran and passed and the last line says the
 *         run reached its end; 1 otherwise, including every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/500
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 93;

/** What a child process prints before its findings, so they can be found in whatever else it printed. */
const RESULT_MARK = '@@portal-address-selftest@@';

// =============================================================================
// 🧒 THE CHILD PROCESS
// =============================================================================
// This same file is run again, as `php portal-address-selftest.php --child
// <scratch web folder>`, to call PortalAddress with a request this test makes
// up: a host name, http or https, an address, or no host at all. A page cannot
// show what base() returned. It runs at the TOP LEVEL, not inside a function,
// because the portal's start-up file sets $mysqli and $SETTINGS as globals.
if (($argv[1] ?? '') === '--child') {
    $job = (array) json_decode((string) getenv('SELFTEST_JOB'), true);
    unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
    if (isset($job['host']) === true) {
        $_SERVER['HTTP_HOST'] = (string) $job['host'];
    }
    if (($job['https'] ?? false) === true) {
        $_SERVER['HTTPS'] = 'on';
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = (string) ($job['uri'] ?? '/');
    require (string) ($argv[2] ?? '') . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    $out = [];
    foreach ((array) ($job['steps'] ?? []) as $step) {
        $args = array_slice((array) $step, 1);
        try {
            $out[] = match ((string) $step[0]) {
                'siteId'      => \Portal\Core\Site::id(),
                'state'       => \Portal\Core\PortalAddress::state(),
                'saved'       => \Portal\Core\PortalAddress::saved(),
                'fromRequest' => \Portal\Core\PortalAddress::fromRequest(),
                'canChoose'   => \Portal\Core\PortalAddress::visitorCanChooseHost(),
                'base'        => \Portal\Core\PortalAddress::base(...$args),
                'absolute'    => \Portal\Core\PortalAddress::absolute(...$args),
                'linkHtml'    => \Portal\Core\PortalAddress::linkHtml(...$args),
                'forPage'     => \Portal\Core\PortalAddress::forPage(...$args),
                // The host name of the request, changed part way: fromRequest() reads it afresh each time.
                'host'        => $_SERVER['HTTP_HOST'] = (string) $args[0],
                // What a job on a timer does for each organisation in turn.
                'force'       => (static function (int $siteId): string {
                    \Portal\Core\Site::forceContext($siteId);

                    return 'forced';
                })(...$args),
                // A database that cannot be asked: the connection is closed and what was read is forgotten.
                'closeDb'     => (static function (): string {
                    \Portal\Core\App::db()->close();
                    \Portal\Core\PortalAddress::forget();

                    return 'closed';
                })(),
                'expense'     => (static function (int $claimId, string $event): string {
                    \Portal\Core\ExpenseMailer::notify($claimId, $event, ['approverName' => 'Selftest Approver']);

                    return 'notified';
                })(...$args),
                // The name the portal gives a mail server. Private, so it is reached the way a test may.
                'clientName'  => (new ReflectionMethod(\Portal\Core\MailRoutes::class, 'clientName'))->invoke(null, ...$args),
                default       => 'UNKNOWN STEP',
            };
        } catch (Throwable $thrown) {
            $out[] = 'THREW ' . get_class($thrown) . ': ' . $thrown->getMessage();
        }
    }
    echo "\n" . RESULT_MARK . json_encode($out) . "\n";
    exit(0);
}

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open', 'openssl_pkey_new', 'sodium_crypto_secretbox'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
$repo      = (string) realpath(dirname(__DIR__));
$sep       = DIRECTORY_SEPARATOR;
$webSrc    = $repo . $sep . 'web';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . $sep, $parent . $sep) === true || str_starts_with($parent . $sep, $repo . $sep) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9660 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}

$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$db = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $creds['db_name'], $creds['db_port']);
$db->set_charset('utf8mb4');

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

/** @param list<mixed> $params @return list<array<string,mixed>> */
function q(string $sql, string $types = '', array $params = []): array
{
    global $db;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** @param list<mixed> $params */
function one(string $sql, string $types = '', array $params = []): ?array
{
    return q($sql, $types, $params)[0] ?? null;
}

// =============================================================================
// 🗺️ The made-up world
// =============================================================================
/** The accounts. */
/** The only organisation in this test's made-up world. Named rather than written as 1 in six places,
 *  because since #677 some settings this test checks belong to an organisation and not to the
 *  installation, and a bare number in an assertion gives a reader nothing to go on. */
const ONLY_SITE = 1;

const PEOPLE = [
    'root'     => 941001, // a global administrator
    'orgadmin' => 941002, // an administrator of the default organisation, and nothing more
    'member'   => 941011, // an ordinary member of the default organisation
    'memberb'  => 941012, // an ordinary member of organisation B
    'leaver'   => 941013, // a member of the default organisation who asks for their data to be erased
];
/** Organisation B has an address and a key of its own; C has a key and NO address. */
const ORG_B  = 941901;
const ORG_C  = 941902;
const KEY_B  = 'f4b';
const KEY_C  = 'f4c';
const HOST_A = 'a.f4.selftest.invalid'; // lent to the default organisation for the "own address each" parts
const HOST_B = 'b.f4.selftest.invalid';
/** The address an administrator SETS, a host name used honestly, and one that is made up. `.invalid` can never be anybody's. */
const SET  = 'https://portal.f4.selftest.invalid';
const USED = 'used.f4.selftest.invalid';
const EVIL = 'evil.f4.selftest.invalid';
/** A Host header that reaches PHP but is not a host name: an underscore is not allowed in one. */
const BAD_HOST = 'bad_host.f4.selftest.invalid';
/** One task due for a reminder in each organisation, a department and an expense claim. */
const TASK_1 = 941501;
const TASK_B = 941502;
const DEPT   = 941701;
const CLAIM  = 941601;
/** A newsletter still in draft, a published public form and a shared order of service, all in the default organisation. */
const NEWS       = 941801;
const FORM       = 941811;
const PLAN       = 941821;
const FORM_TOKEN = 'f4f4f4f4f4f4f4f4f4f4f4f4f4f40001';
const PLAN_TOKEN = 'f4f4f4f4f4f4f4f4f4f4f4f4f4f40002';
/** How this test names itself to the portal, so the records it causes can be found and removed. */
const AGENT        = 'portal-address-selftest';
const SENDER       = 'office@mail.f4.selftest.invalid';
const MAILBOX_USER = 'selftest-f4-mailbox';
/** The mail server's name. The stand-in's certificate is made for this name. */
const MAIL_HOST = 'localhost';
/** The two tables migration 215 touches, and the COPY of each that part B runs it against. */
const COPIES = ['tblSettings' => 'selftestF4Settings', 'tblMigrations' => 'selftestF4Migrations'];

/** A made-up visitor address. 192.0.2.0/24 is kept for examples (RFC 5737), so it is nobody's. */
function ip(int $last): string
{
    return '192.0.2.' . $last;
}

function login(string $who): string
{
    return 'f4-' . $who;
}

function mailOf(string $who): string
{
    return $who . '@f4.selftest.invalid';
}

/** Our own domain's name, decoded here so that the plain name is nowhere in this file (see the check's own header). */
function ownName(): string
{
    return (string) base64_decode('bWlsbHJkc2RhY2FtYnJpZGdl');
}

function dropCopies(): void
{
    global $db;
    foreach (COPIES as $copy) {
        $db->query('DROP TABLE IF EXISTS `' . $copy . '`');
    }
}

function removeWorld(): void
{
    q('DELETE FROM tblPasswordResets WHERE userID BETWEEN 941000 AND 941999');
    q('DELETE FROM tblErasureRequest WHERE userID BETWEEN 941000 AND 941999');
    // The newsletter's recipient rows go with it (the database removes them).
    q('DELETE FROM tblNewsletter WHERE newsletterID = ?', 'i', [NEWS]);
    q('DELETE FROM tblForms WHERE formID = ?', 'i', [FORM]);
    q('DELETE FROM tblServicePlan WHERE planID = ?', 'i', [PLAN]);
    q("DELETE FROM tblInvitation WHERE email LIKE '%f4.selftest.invalid'");
    q("DELETE FROM tblEmailLog WHERE toRecipients LIKE '%f4.selftest.invalid%'");
    q("DELETE FROM tblActivityLogs WHERE userID BETWEEN 941000 AND 941999 OR visitorIP LIKE '192.0.2.%' OR userAgent = ? OR siteID IN (?, ?)", 'sii', [AGENT, ORG_B, ORG_C]);
    q("DELETE FROM tblErrors WHERE userID BETWEEN 941000 AND 941999 OR visitorIP LIKE '192.0.2.%' OR siteID IN (?, ?) OR errorCode = 'RESET_NO_ADDRESS'", 'ii', [ORG_B, ORG_C]);
    for ($last = 1; $last <= 254; $last++) {
        q('DELETE FROM tblApiRateLimits WHERE bucket = ?', 's', ['pwreset:ask:' . hash('sha256', ip($last))]);
    }
    q('DELETE FROM tblTasks WHERE taskID IN (?, ?)', 'ii', [TASK_1, TASK_B]);
    q('DELETE FROM tblExpenseClaims WHERE claimID = ?', 'i', [CLAIM]);
    q('DELETE FROM tblDepts WHERE deptID = ?', 'i', [DEPT]);
    q('DELETE FROM tblSettings WHERE siteID IN (?, ?)', 'ii', [ORG_B, ORG_C]);
    // The one organisation's own `site.url` row that part F plants. A real database has none.
    q("DELETE FROM tblSettings WHERE siteID = 1 AND settingKey = 'site.url'");
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 941000 AND 941999');
    q('DELETE FROM tblUserSites WHERE userID BETWEEN 941000 AND 941999 OR siteID IN (?, ?)', 'ii', [ORG_B, ORG_C]);
    q('DELETE FROM tblUsers WHERE userID BETWEEN 941000 AND 941999');
    q('DELETE FROM tblSites WHERE siteID IN (?, ?)', 'ii', [ORG_B, ORG_C]);
    // The address lent to the default organisation, if a run stopped while it was lent.
    q('UPDATE tblSites SET hostPattern = NULL WHERE siteID = 1 AND hostPattern = ?', 's', [HOST_A]);
    dropCopies();
}

// =============================================================================
// 🧰 The servers, and one request
// =============================================================================
$state = [
    'proc' => null, 'mailProc' => null, 'mailPipe' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [],
    'started' => false, 'cleaned' => false, 'read' => 0, 'mailPort' => 0,
];

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Stop one server by the process proc_open() gave — never by a name. */
function stopServer(mixed &$proc): void
{
    if (is_resource($proc) === false) {
        return;
    }
    $until = microtime(true) + 5;
    while (proc_get_status($proc)['running'] === true && microtime(true) < $until) {
        proc_terminate($proc);
        usleep(100000);
    }
    if (proc_get_status($proc)['running'] === true) {
        proc_terminate($proc, 9);
    }
    proc_close($proc);
    $proc = null;
}

/** Put back everything this run changed, ONCE. Also registered as a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    stopServer($state['proc']);
    // The stand-in mail server stops itself when this file appears.
    $child = (string) $state['child'];
    if ($child !== '' && is_dir($child . DIRECTORY_SEPARATOR . 'standin') === true) {
        @touch($child . DIRECTORY_SEPARATOR . 'standin' . DIRECTORY_SEPARATOR . 'stop');
        $until = microtime(true) + 5;
        while (is_resource($state['mailProc']) === true && proc_get_status($state['mailProc'])['running'] === true && microtime(true) < $until) {
            usleep(100000);
        }
    }
    if (is_resource($state['mailPipe']) === true) {
        fclose($state['mailPipe']);
    }
    stopServer($state['mailProc']);
    try {
        foreach ($state['settings'] as $key => $before) {
            if ($before === null) {
                q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]);
            } else {
                q('UPDATE tblSettings SET settingValue = ?, isSensitive = ? WHERE settingID = ?', 'sii', [$before['settingValue'], (int) $before['isSensitive'], (int) $before['settingID']]);
            }
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    // The scratch child: only the one this run made, and only while it still holds this run's marker.
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.portal-address-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One installation-wide setting for the run, written straight into the
 * database. The value it had, and whether it was marked as a secret, are
 * remembered the first time and put back by the clean-up.
 */
function setting(string $key, string $value, int $secret = 0): void
{
    global $state;
    $row = one('SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    if (array_key_exists($key, $state['settings']) === false) {
        $state['settings'][$key] = $row;
    }
    if ($row === null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, ?)', 'sssi', [$key, $value, $value, $secret]);
    } else {
        q('UPDATE tblSettings SET settingValue = ?, isSensitive = ? WHERE settingID = ?', 'sii', [$value, $secret, (int) $row['settingID']]);
    }
}

/** One installation-wide setting as it is stored now; '(no row)' when there is none. */
function stored(string $key): string
{
    $row = one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);

    return $row === null ? '(no row)' : (string) $row['settingValue'];
}

/**
 * One setting as ONE ORGANISATION has it, '(no row)' when it has none.
 *
 * WHY THIS EXISTS (#677, 30 October 2026). `portal.support.email` used to be
 * written installation-wide by the Web addresses card, so `stored()` above was
 * the right reader for it. It is now the ORGANISATION's, falling back to the
 * installation's where an organisation has set none — so reading
 * `siteID IS NULL` answers the fallback and says nothing about what the card
 * just saved. Four assertions in part C read the wrong level for a whole
 * afternoon before anybody ran this test, which is the useful part of the
 * story: the page's own new test passed throughout.
 *
 * THE DIFFERENCE BETWEEN '' AND NO ROW IS LOAD-BEARING HERE. The merge only
 * falls back when there is NO row; a stored empty string wins and masks the
 * installation's address for ever. So this returns '(no row)' rather than ''
 * for a missing row, and part C checks for it by name.
 */
function storedForSite(string $key, int $siteId): string
{
    $row = one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = ?', 'si', [$key, $siteId]);

    return $row === null ? '(no row)' : (string) $row['settingValue'];
}

/** How many rows one organisation has for a setting. More than one is the settings-table trap. */
function rowsOfForSite(string $key, int $siteId): int
{
    return (int) one('SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey = ? AND siteID = ?', 'si', [$key, $siteId])['n'];
}

/** How many installation-wide rows a setting has. More than one is the settings-table trap. */
function rowsOf(string $key): int
{
    return (int) one('SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key])['n'];
}

/** Set how organisations are told apart, straight in the two installation-wide rows. */
function setWay(bool $on, string $way): void
{
    setting('multisite.enabled', $on === true ? 'true' : 'false');
    setting('multisite.detectionMode', $way);
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else and
 * is returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 * @param array{ip?: string, host?: string} $o `ip` is the visitor's address, sent as a proxy would send it;
 *                                             `host` is the Host header to send in place of the true one.
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null, array $o = []): array
{
    global $port, $state;
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Expect:', 'User-Agent: ' . AGENT];
    if (($o['ip'] ?? '') !== '') {
        $headers[] = 'X-Forwarded-For: ' . $o['ip'];
    }
    if (($o['host'] ?? '') !== '') {
        $headers[] = 'Host: ' . $o['host'];
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($jar !== '') {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle), 'head' => ''];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Portal-Address-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
    ];
}

/** The form token on a page. */
function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** A new, empty cookie jar: a browser that has never been here. */
function newJar(): string
{
    global $state;

    return $state['child'] . DIRECTORY_SEPARATOR . 'jar-' . bin2hex(random_bytes(6)) . '.txt';
}

/** A page as the words a person reads: tags out, entities turned back, spaces tidied. */
function words(string $html): string
{
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** Sign one of the people in through the real page. Returns their cookie jar. */
function signIn(string $who, string $password, array $o): string
{
    $jar    = newJar();
    $form   = req($jar, 'GET', '/login', null, $o);
    $answer = req($jar, 'POST', '/login', ['csrf_token' => token($form['body']), 'username' => login($who), 'password' => $password], $o);
    if ($answer['status'] !== 302 || $answer['location'] !== '/') {
        refuse($who . ' could not sign in (HTTP ' . $answer['status'] . ' to ' . $answer['location'] . ')');
    }

    return $jar;
}

/**
 * Wait until the portal server has finished what it is doing. "Forgot
 * password" answers the browser first and only then sends the e-mail. The
 * server is ONE process serving one request at a time, so this second
 * request (an address beginning with a dot, refused by the router before any
 * portal code runs) is not served until the first script has ended.
 */
function settle(): void
{
    req('', 'GET', '/.selftest-settle');
}

/** Ask for a reset link through the real page. */
function askForLink(string $email, array $o): array
{
    $jar  = newJar();
    $form = req($jar, 'GET', '/forgot-password', null, $o);
    $sent = req($jar, 'POST', '/forgot-password/save', ['csrf_token' => token($form['body']), 'email' => $email], $o);
    settle();

    return $sent;
}

/**
 * The messages the stand-in mail server has taken SINCE THE LAST CALL, once
 * every connection to it has ended.
 *
 * @return list<array{to: list<string>, html: string, text: string, raw: string}>
 */
function mailSince(): array
{
    global $state;
    $dir    = $state['child'] . DIRECTORY_SEPARATOR . 'standin';
    $file   = $dir . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'accept.log';
    $events = [];
    for ($tries = 0; $tries < 150; $tries++) {
        $events = [];
        $open   = 0;
        foreach (is_file($file) === true ? (array) file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $parts    = explode(' ', (string) $line, 4);
            $events[] = [(int) $parts[0], $parts[2] ?? '', stripcslashes($parts[3] ?? '')];
            $open    += ($parts[2] ?? '') === 'connect' ? 1 : (($parts[2] ?? '') === 'close' ? -1 : 0);
        }
        if ($open <= 0) {
            break;
        }
        usleep(100000);
    }
    $new           = array_slice($events, (int) $state['read']);
    $state['read'] = count($events);

    $messages = [];
    $to       = [];
    foreach ($new as [$connection, $kind, $rest]) {
        if ($kind === 'line' && stripos($rest, 'MAIL FROM:<') === 0) {
            $to[$connection] = [];
        } elseif ($kind === 'line' && stripos($rest, 'RCPT TO:<') === 0) {
            $to[$connection][] = substr($rest, 9, (int) strpos($rest, '>') - 9);
        } elseif ($kind === 'body' && preg_match('/complete=1 file=(\S+)/', $rest, $m) === 1) {
            $raw   = (string) file_get_contents($dir . DIRECTORY_SEPARATOR . $m[1]);
            $parts = [];
            // Each part of the message is base64 under its own small heading.
            foreach (['text/plain' => 'text', 'text/html' => 'html'] as $type => $name) {
                $parts[$name] = preg_match('#Content-Type: ' . preg_quote($type, '#') . '[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n--#s', $raw, $p) === 1
                    ? (string) base64_decode((string) preg_replace('/\s+/', '', $p[1]))
                    : '';
            }
            $messages[] = ['to' => $to[$connection] ?? [], 'html' => $parts['html'], 'text' => $parts['text'], 'raw' => $raw];
        }
    }

    return $messages;
}

/** The one message for one person among those just taken, or null. */
function mailFor(array $messages, string $address): ?array
{
    foreach ($messages as $message) {
        if (in_array($address, $message['to'], true) === true) {
            return $message;
        }
    }

    return null;
}

/** Every address a message's links point at (its href values), in order. */
function hrefs(?array $message): array
{
    preg_match_all('/href="([^"]*)"/', (string) ($message['html'] ?? ''), $found);

    return array_map(static fn (string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $found[1]);
}

/**
 * Run PortalAddress in a PHP process of its own, on the scratch copy, with a
 * made-up request. Returns one answer per step, or one line saying why not.
 *
 * @param array{host?: string, https?: bool, uri?: string, steps: list<list<mixed>>} $job
 *
 * @return list<mixed>
 */
function child(array $job): array
{
    global $state;
    $folder = (string) $state['child'];
    $sep    = DIRECTORY_SEPARATOR;
    $env    = getenv();
    $env['PORTAL_ENV']   = 'prod';
    $env['SELFTEST_JOB'] = (string) json_encode($job);
    $process = proc_open(
        [PHP_BINARY, '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log=' . $folder . $sep . 'child-errors.log',
            '-d', 'openssl.cafile=' . $folder . $sep . 'standin' . $sep . 'certs' . $sep . 'ca.pem',
            '-d', 'session.save_path=' . $folder . $sep . 'sessions', __FILE__, '--child', $folder . $sep . 'web'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $folder,
        $env
    );
    if (is_resource($process) === false) {
        return ['the child process could not be started'];
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
    $at    = strrpos($output, RESULT_MARK);
    $found = $at === false ? null : json_decode((string) strtok(substr($output, $at + strlen(RESULT_MARK)), "\n"), true);

    return is_array($found) === true ? $found : ['the child printed no result: ' . mb_substr(trim($output), 0, 300)];
}

/** Encrypt the way the portal's encrypt_setting() does, with the scratch copy's key. */
function sealed(string $plain, string $keyText): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, sodium_hex2bin(hash('sha256', $keyText))));
}

/** Run several SQL statements, as a .sql file holds them, on the test's own connection. Throws on the first error. */
function runSql(string $sql): void
{
    global $db;
    $db->multi_query($sql);
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($db->more_results() === true && $db->next_result() === true);
}

/** Run a command given as a list (no shell). Returns [exit code, everything it printed]. */
function run(array $command): array
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (is_resource($process) === false) {
        return [-1, 'could not be started'];
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

// =============================================================================
head('A. The rules for an address somebody types, with PortalAddress.php loaded alone');
// =============================================================================
// No autoloader is registered in this process and no other file of the portal
// has been loaded yet. If check() reached for any other class, PHP would stop
// here with "class not found". That is the proof that the installer, which
// cannot load the portal, can load this one file.
require $webSrc . $sep . '_core' . $sep . 'PortalAddress.php';
$check   = static fn (string $typed): array => \Portal\Core\PortalAddress::check($typed);
$refused = static function (array $typed, string $mustSay) use ($check): string {
    $wrong = [];
    foreach ($typed as $one) {
        $got = $check($one);
        if ($got['address'] !== '' || $got['problem'] === '' || ($mustSay !== '' && str_contains($got['problem'], $mustSay) === false)) {
            $wrong[] = json_encode($one) . ' gave ' . json_encode($got);
        }
    }

    return implode('; ', $wrong);
};
ok('A1 the file loads and checks an address with nothing else of the portal loaded', $check('https://portal.example.org') === ['address' => 'https://portal.example.org', 'problem' => ''] && class_exists('Portal\\Core\\Site', false) === false);

$accepted = [
    'https://portal.example.org'      => 'https://portal.example.org',
    'HTTPS://Portal.Example.ORG/'     => 'https://portal.example.org',
    'portal.example.org'              => 'https://portal.example.org',
    'http://localhost:8080'           => 'http://localhost:8080',
    'https://portal.example.org:443'  => 'https://portal.example.org',
    'http://portal.example.org:80/'   => 'http://portal.example.org',
    'https://portal.example.org:8443' => 'https://portal.example.org:8443',
    'https://portal.example.org.'     => 'https://portal.example.org',
    "  https://portal.example.org\n"  => 'https://portal.example.org',
    'https://xn--bcher-kva.example'   => 'https://xn--bcher-kva.example',
    ''                                => '',
    "  \t "                           => '',
];
$wrong = [];
foreach ($accepted as $typed => $expected) {
    $got = $check((string) $typed);
    if ($got !== ['address' => $expected, 'problem' => '']) {
        $wrong[] = json_encode($typed) . ' gave ' . json_encode($got);
    }
}
ok('A2 ' . count($accepted) . ' usable forms are accepted and tidied: capitals, a closing slash or dot, the usual port, a bare host name, spaces at the ends; nothing typed is "not set"', $wrong === [], implode('; ', $wrong));
ok('A3 an address with a PATH, a question mark or a "#" is refused, and told that the portal must be at the top of its address', $refused(
    ['https://example.org/portal', 'https://portal.example.org/index.php', 'https://portal.example.org/?a=1', 'https://portal.example.org?a=1', 'https://portal.example.org#x', 'portal.example.org/portal', 'https://portal.example.org//'],
    'folder'
) === '', $refused(['https://example.org/portal', 'https://portal.example.org/index.php', 'https://portal.example.org/?a=1', 'https://portal.example.org?a=1', 'https://portal.example.org#x', 'portal.example.org/portal', 'https://portal.example.org//'], 'folder'));
$lineBreaks = ["https://portal.example.org\r\nBcc: stranger@evil.example", "https://portal.exa\nmple.org", "https://portal.example.org\tx", 'https://portal example.org', "https://portal.example.org\0x", "https://portal.example.org\x7F"];
ok('A4 an address with a LINE BREAK, a space or a control character inside it is refused', $refused($lineBreaks, 'line break') === '', $refused($lineBreaks, 'line break'));
$userNames = ['https://admin@portal.example.org', 'https://admin:secret@portal.example.org', 'https://portal.example.org@evil.example', 'admin@portal.example.org', 'https://portal.example.org\\@evil.example'];
ok('A5 an address with a USER NAME or password (anything before an "@") is refused', $refused($userNames, 'user name') === '', $refused($userNames, 'user name'));
$schemes = ['ftp://portal.example.org', 'javascript://portal.example.org', 'file:///etc/passwd', 'gopher://portal.example.org', 'ws://portal.example.org', 'htps://portal.example.org'];
ok('A6 an address that begins with ANOTHER SCHEME is refused, and told that only https:// and http:// can be used', $refused($schemes, 'https:// or http://') === '', $refused($schemes, 'https:// or http://'));
$notNames = ['https://', 'javascript:alert(1)', 'data:text/html,x', 'mailto:someone', '//portal.example.org', 'https://-bad.example.org', 'https://bad_host.example.org', 'https://*.example.org', 'https://bücher.example',
    'https://[::1]:8080', 'https://exa..mple.org', 'https://portal.example.org:0', 'https://portal.example.org:65536', 'https://portal.example.org:port', 'https://' . str_repeat('a', 64) . '.example.org', 'https://' . str_repeat('a.', 140) . 'example.org'];
ok('A7 ' . count($notNames) . ' things that are not a host name with an optional port are refused: an empty name, a script, a wildcard, an underscore, letters outside a to z, a port of 0 or 65536, a part over 63 characters, a name over 255', $refused($notNames, '') === '', $refused($notNames, ''));

// The host-name rule is written out in PortalAddress so that file can load
// alone. Site::cleanHostName() holds the same rule for an organisation's own
// address. Loaded only now, after A1, and held to the same answers.
require $webSrc . $sep . '_core' . $sep . 'Site.php';
$disagree = [];
foreach (['portal.example.org', 'LOCALHOST', 'a-b.example', 'a.b.c.d.example', '-a.example', 'a-.example', 'a_b.example', 'a..b', '*.example', 'a b.example', 'bücher.example', str_repeat('a', 63) . '.example', str_repeat('a', 64) . '.example', '127.0.0.1'] as $name) {
    $viaSite    = \Portal\Core\Site::cleanHostName($name);
    $viaAddress = \Portal\Core\PortalAddress::clean('https://' . $name);
    if (($viaSite === null) !== ($viaAddress === null) || ($viaSite !== null && $viaAddress !== 'https://' . $viaSite)) {
        $disagree[] = $name . ': Site says ' . json_encode($viaSite) . ', PortalAddress says ' . json_encode($viaAddress);
    }
}
ok('A8 PortalAddress and Site::cleanHostName() give the same answer for 14 host names, good and bad (the rule is written in both)', $disagree === [], implode('; ', $disagree));

// =============================================================================
head('D. The check that keeps our own domain out of the product');
// =============================================================================
[$status, $said] = run(['python3', '--version']);
if ($status !== 0) {
    refuse('python3 is needed for part D and could not be run');
}
$checker  = $repo . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'check_no_hardcoded_domains.py';
$pendingF = $repo . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'check_no_hardcoded_domains.pending.txt';
[$status, $said] = run(['python3', $checker]);
$pendingNow      = preg_match('/^Pending \(known, waiting for a change that needs approval\): (\d+)$/m', $said, $m) === 1 ? (int) $m[1] : -1;
ok(
    'D1 on THIS repository it passes: no line names a domain of ours outside the pending list, no pending entry is stale, and it says it reached its end',
    $status === 0 && str_contains($said, 'Lines naming one of our own domains where none may be: 0') === true && str_contains($said, 'Stale pending entries (their line is gone): 0') === true
        && $pendingNow >= 0 && str_contains($said, 'The check reached its end.') === true,
    'exit ' . $status . "\n" . $said
);
[$strictStatus] = run(['python3', $checker, '--strict']);
ok('D2 with --strict it fails for exactly as long as something is pending (pending now: ' . $pendingNow . ')', $strictStatus === ($pendingNow > 0 ? 1 : 0), 'exit ' . $strictStatus . ' with ' . $pendingNow . ' pending');
[$status, $said] = run(['python3', $checker, '--print-own-domains']);
ok('D3 neither the check nor its pending list spells the name out, and --print-own-domains shows it', $status === 0 && trim($said) === ownName()
    && stripos((string) file_get_contents($checker), ownName()) === false && stripos((string) file_get_contents($pendingF), ownName()) === false, trim($said));

// Small made-up repositories, each a folder holding a COPY of the check where
// the real one sits (tools/audit-checks/), so the copy treats that folder as
// the repository. The real check and its pending list are never changed.
$mini = static function (array $files, string $pending) use ($parent, $sep, $checker): array {
    $root = $parent . $sep . 'portal-address-selftest-mini-' . getmypid() . '-' . bin2hex(random_bytes(6));
    mkdir($root . $sep . 'tools' . $sep . 'audit-checks', 0700, true);
    copy($checker, $root . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'check_no_hardcoded_domains.py');
    file_put_contents($root . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'check_no_hardcoded_domains.pending.txt', $pending);
    foreach ($files as $path => $text) {
        @mkdir(dirname($root . $sep . $path), 0700, true);
        file_put_contents($root . $sep . $path, $text);
    }
    $default = run(['python3', $root . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'check_no_hardcoded_domains.py']);
    $strict  = run(['python3', $root . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'check_no_hardcoded_domains.py', '--strict']);
    $staged  = run(['python3', $root . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'check_no_hardcoded_domains.py', '--root', $root . $sep . 'web']);
    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($tree as $path => $info) {
        $info->isDir() === true ? rmdir((string) $path) : unlink((string) $path);
    }
    rmdir($root);

    return ['default' => $default, 'strict' => $strict, 'staged' => $staged];
};
$planted = '$support = "portal-support@' . ownName() . '.uk";';
$print   = hash('sha256', trim($planted));
$clean   = ['web/_apps/page.php' => "<?php\n// nothing of ours here\n", 'README.md' => "# A product\n"];

$got = $mini($clean, '');
ok('D4 a clean made-up repository passes, plainly and with --strict', $got['default'][0] === 0 && $got['strict'][0] === 0, 'exits ' . $got['default'][0] . ' and ' . $got['strict'][0]);
$got = $mini($clean + ['web/_apps/help.php' => "<?php\n" . $planted . "\n"], '');
ok('D5 a planted line in web/ FAILS it and is named with its line number', $got['default'][0] === 1 && str_contains($got['default'][1], 'web/_apps/help.php:2') === true, 'exit ' . $got['default'][0] . "\n" . $got['default'][1]);
// The planted files come FIRST: PHP's "+" keeps the left-hand value of a name both lists hold, and README.md is in both.
$got = $mini(['tools/x.sh' => 'curl https://PORTAL.' . strtoupper(ownName()) . ".org/health\n", '.github/workflows/y.yml' => '# e.g. portal.' . ownName() . ".uk\n", 'README.md' => 'See portal.' . ownName() . ".uk\n"] + $clean, '');
ok(
    'D6 it is found in CAPITALS and under another ending, and in each place that must be clean: tools/, .github/ and README.md',
    $got['default'][0] === 1 && str_contains($got['default'][1], 'tools/x.sh:1') === true && str_contains($got['default'][1], '.github/workflows/y.yml:1') === true && str_contains($got['default'][1], 'README.md:1') === true,
    $got['default'][1]
);
$got = $mini($clean + ['DEV_NOTES.md' => 'Our own installation, for example: portal.' . ownName() . ".uk\n", '.claude/notes.md' => ownName() . "\n", '.claude-work/x.md' => ownName() . "\n"], '');
ok(
    'D7 in a document outside those places it is LISTED and does not fail; a folder that is never read is not listed',
    $got['default'][0] === 0 && str_contains($got['default'][1], 'DEV_NOTES.md (1)') === true && str_contains($got['default'][1], '.claude/notes.md (1)') === true && str_contains($got['default'][1], '.claude-work') === false,
    'exit ' . $got['default'][0] . "\n" . $got['default'][1]
);
$got = $mini($clean + ['web/_apps/help.php' => "<?php\n" . $planted . "\n"], "# waiting\nweb/_apps/help.php  " . $print . "  waits for somebody's yes\n");
ok(
    'D8 a line on the pending list is counted as pending and does not fail; --strict fails on it; and the list does NOT apply to a staged package (--root)',
    $got['default'][0] === 0 && str_contains($got['default'][1], 'needs approval): 1') === true && $got['strict'][0] === 1 && $got['staged'][0] === 1 && str_contains($got['staged'][1], '_apps/help.php:2') === true,
    'exits ' . $got['default'][0] . ', ' . $got['strict'][0] . ', ' . $got['staged'][0] . "\n" . $got['default'][1]
);
$got = $mini($clean, "web/_apps/help.php  " . $print . "  the line has gone\n");
ok('D9 a pending entry whose line is no longer there FAILS it, so the list cannot rot', $got['default'][0] === 1 && str_contains($got['default'][1], 'Stale pending entries (their line is gone): 1') === true, 'exit ' . $got['default'][0] . "\n" . $got['default'][1]);
$got = $mini($clean + ['web/_apps/help.php' => "<?php\n" . $planted . "\n" . $planted . "\n"], "web/_apps/help.php  " . $print . "  one of the two\n");
ok('D10 an entry covers ONE line: the same text written twice fails for the second', $got['default'][0] === 1 && str_contains($got['default'][1], 'none may be: 1') === true && str_contains($got['default'][1], 'needs approval): 1') === true, $got['default'][1]);
$got = $mini($clean, "this line is not an entry\n");
ok('D11 a pending list that cannot be read as entries fails it rather than being skipped', $got['default'][0] === 1, 'exit ' . $got['default'][0]);

// =============================================================================
head('B. The database: a fresh install, and migration 215 on an older one');
// =============================================================================
// Read FIRST, before this test writes any setting, so that what is read here
// is what web/_sql/full_schema.sql put there.
$fresh = q("SELECT settingKey, settingValue, defaultValue, isSensitive FROM tblSettings WHERE siteID IS NULL AND settingKey IN ('site.url', 'portal.support.email') ORDER BY settingKey");
ok(
    'B1 the install script seeds ONE installation-wide `site.url` row and ONE support-address row, both empty with an empty default, and records migration 215',
    $fresh === [
        ['settingKey' => 'portal.support.email', 'settingValue' => '', 'defaultValue' => '', 'isSensitive' => 0],
        ['settingKey' => 'site.url', 'settingValue' => '', 'defaultValue' => '', 'isSensitive' => 0],
    ] && (int) one("SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = '215_portal_address.sql'")['n'] === 1,
    (string) json_encode($fresh)
);

// An OLDER database, made here from copies of the two tables the migration
// touches. The migration's own text is run with the two table names changed
// to the copies' names, and nothing else changed.
dropCopies();
foreach (COPIES as $real => $copy) {
    $db->query('CREATE TABLE `' . $copy . '` LIKE `' . $real . '`');
}
$cSettings  = '`' . COPIES['tblSettings'] . '`';
$oldSeed    = 'portal-support@' . ownName() . '.uk';
$migration  = strtr((string) file_get_contents($webSrc . $sep . '_sql' . $sep . '215_portal_address.sql'), COPIES);
$copyRow    = static function (string $key) use ($cSettings): array {
    return array_map(static fn (array $row): array => [(string) $row['settingValue'], $row['defaultValue'] === null ? null : (string) $row['defaultValue']],
        q('SELECT settingValue, defaultValue FROM ' . $cSettings . ' WHERE settingKey = ? AND siteID IS NULL ORDER BY settingID', 's', [$key]));
};
$migrate = static function () use ($migration): string {
    try {
        runSql($migration);
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return '';
};
// As an installation was before this migration: no `site.url` row, and the
// support address still the old seed in both columns.
q('INSERT INTO ' . $cSettings . ' (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, \'portal.support.email\', ?, ?, 0)', 'ss', [$oldSeed, $oldSeed]);
$error  = $migrate();
$after1 = [$copyRow('site.url'), $copyRow('portal.support.email'), (int) one('SELECT COUNT(*) AS n FROM `' . COPIES['tblMigrations'] . "` WHERE filename = '215_portal_address.sql'")['n']];
ok(
    'B2 migration 215 on an older database: it adds the `site.url` row, empty; blanks the first customer\'s support address in the value AND the default; and records itself',
    $error === '' && $after1 === [[['', '']], [['', '']], 1],
    ($error === '' ? '' : 'error: ' . $error . '; ') . json_encode($after1)
);
$error = $migrate();
ok('B3 a second run: no error, nothing changes, and there is still exactly ONE `site.url` row', $error === '' && [$copyRow('site.url'), $copyRow('portal.support.email'), (int) one('SELECT COUNT(*) AS n FROM `' . COPIES['tblMigrations'] . "` WHERE filename = '215_portal_address.sql'")['n']] === $after1,
    ($error === '' ? '' : 'error: ' . $error . '; ') . json_encode([$copyRow('site.url'), $copyRow('portal.support.email')]));
// An administrator has saved an address, and their own support address over the old default.
q('UPDATE ' . $cSettings . " SET settingValue = 'https://saved.f4.selftest.invalid' WHERE settingKey = 'site.url'");
q('UPDATE ' . $cSettings . " SET settingValue = 'help@f4.selftest.invalid', defaultValue = ? WHERE settingKey = 'portal.support.email'", 's', [$oldSeed]);
$error = $migrate();
ok(
    'B4 values an administrator SAVED are kept: the portal\'s address, and their own support address (only the old default beside it is blanked)',
    $error === '' && $copyRow('site.url') === [['https://saved.f4.selftest.invalid', '']] && $copyRow('portal.support.email') === [['help@f4.selftest.invalid', '']],
    ($error === '' ? '' : 'error: ' . $error . '; ') . json_encode([$copyRow('site.url'), $copyRow('portal.support.email')])
);
// The case the first plan's rule ("blank it where the value equals its default") would have got wrong.
q('UPDATE ' . $cSettings . " SET settingValue = 'help@f4.selftest.invalid', defaultValue = 'help@f4.selftest.invalid' WHERE settingKey = 'portal.support.email'");
$error = $migrate();
ok('B5 a customer\'s OWN support address is kept even when it is in both the value and the default', $error === '' && $copyRow('portal.support.email') === [['help@f4.selftest.invalid', 'help@f4.selftest.invalid']], json_encode($copyRow('portal.support.email')));
q('UPDATE ' . $cSettings . " SET settingValue = CONCAT('  ', UPPER(?), ' '), defaultValue = '' WHERE settingKey = 'portal.support.email'", 's', [$oldSeed]);
$error = $migrate();
ok('B6 the old seed is still found written in CAPITALS with spaces round it', $error === '' && $copyRow('portal.support.email') === [['', '']], json_encode($copyRow('portal.support.email')));
// The install script's own two seeds, run over a database that already holds
// saved values: what the installer's "carry on with the existing database"
// choice does. Taken from the file by their text, so an edit to either is run.
$schema   = (string) file_get_contents($webSrc . $sep . '_sql' . $sep . 'full_schema.sql');
$seedUrl  = preg_match("/INSERT INTO `tblSettings` \([^)]*\) VALUES\s*\(NULL, 'site\.url',[^;]*;/", $schema, $m) === 1 ? $m[0] : '';
$seedHelp = preg_match("/INSERT INTO `tblSettings` \([^)]*\) VALUES\s*\(NULL, 'portal\.support\.email',[^;]*;/", $schema, $m) === 1 ? $m[0] : '';
q('UPDATE ' . $cSettings . " SET settingValue = 'help@f4.selftest.invalid', defaultValue = 'kept-default' WHERE settingKey = 'portal.support.email'");
$error = '';
try {
    runSql(strtr($seedUrl . "\n" . $seedHelp . "\n" . $seedUrl . "\n" . $seedHelp, COPIES));
} catch (Throwable $e) {
    $error = $e->getMessage();
}
ok(
    'B7 the install script\'s two seeds, each run twice over saved values, add no row and change nothing (not the value, not the default)',
    $seedUrl !== '' && $seedHelp !== '' && $error === '' && $copyRow('site.url') === [['https://saved.f4.selftest.invalid', '']] && $copyRow('portal.support.email') === [['help@f4.selftest.invalid', 'kept-default']],
    ($error === '' ? '' : 'error: ' . $error . '; ') . 'seeds found: ' . ($seedUrl !== '' ? 'yes' : 'NO') . '/' . ($seedHelp !== '' ? 'yes' : 'NO') . '; ' . json_encode([$copyRow('site.url'), $copyRow('portal.support.email')])
);
dropCopies();

// =============================================================================
// 🏗️ Set-up: the world, the scratch copy, the two servers
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}; this test never drives a server it did not start");
}
$defaultOrg = one('SELECT siteKey, hostPattern, isActive FROM tblSites WHERE siteID = 1');
if ($defaultOrg === null || (int) $defaultOrg['isActive'] !== 1 || ($defaultOrg['hostPattern'] !== null && $defaultOrg['hostPattern'] !== HOST_A)) {
    refuse('the default organisation (number 1) is missing, switched off, or already has an address of its own; this test lends it one and takes it back');
}
$key1 = (string) $defaultOrg['siteKey'];
$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
q('INSERT INTO tblSites (siteID, siteKey, siteName, hostPattern, isActive) VALUES (?, ?, ?, ?, 1)', 'isss', [ORG_B, KEY_B, 'Selftest F4 organisation B', HOST_B]);
q('INSERT INTO tblSites (siteID, siteKey, siteName, hostPattern, isActive) VALUES (?, ?, ?, NULL, 1)', 'iss', [ORG_C, KEY_C, 'Selftest F4 organisation C']);
foreach (PEOPLE as $who => $id) {
    $global = $who === 'root' ? 1 : 0;
    $admin  = ($who === 'root' || $who === 'orgadmin') ? 1 : 0;
    $org    = $who === 'memberb' ? ORG_B : 1;
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, ?, ?)', 'issii', [$id, 'Selftest F4 ' . $who, mailOf($who), $global, $global]);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, ?)', 'iiii', [$id, $org, $admin, $admin]);
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, login($who), $hash]);
}
// One task in each organisation whose reminder fell due a few minutes ago.
// The moment is worked out by the database from NOW, so this never goes stale.
foreach ([[TASK_1, 1, 'member'], [TASK_B, ORG_B, 'memberb']] as [$taskId, $org, $who]) {
    q(
        "INSERT INTO tblTasks (taskID, siteID, title, assignedToID, createdByID, priority, status, reminderDate, reminderSent) VALUES (?, ?, ?, ?, ?, 'normal', 'pending', DATE_SUB(NOW(), INTERVAL 5 MINUTE), 0)",
        'iisii',
        [$taskId, $org, 'Selftest F4 task', PEOPLE[$who], PEOPLE[$who]]
    );
}
q("INSERT INTO tblDepts (deptID, siteID, deptName, deptCode, isActive) VALUES (?, 1, 'Selftest F4 department', 'F4', 1)", 'i', [DEPT]);
q("INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status) VALUES (?, 1, ?, ?, 'Selftest F4 claim', CURDATE(), 12.50, 'Approved')", 'iii', [CLAIM, PEOPLE['member'], DEPT]);
q("INSERT INTO tblNewsletter (newsletterID, siteID, title, subject, status, createdByID) VALUES (?, 1, 'Selftest F4 newsletter', 'Selftest F4 newsletter', 'draft', ?)", 'ii', [NEWS, PEOPLE['root']]);
q("INSERT INTO tblForms (formID, siteID, title, status, audience, publicToken, createdByID) VALUES (?, 1, 'Selftest F4 form', 'published', 'public', ?, ?)", 'isi', [FORM, FORM_TOKEN, PEOPLE['root']]);
q("INSERT INTO tblServicePlan (planID, siteID, title, serviceDate, status, publicToken, isPublicShared) VALUES (?, 1, 'Selftest F4 order of service', CURDATE(), 'draft', ?, 1)", 'is', [PLAN, PLAN_TOKEN]);

// The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . $sep . 'portal-address-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.portal-address-selftest-owner', $state['token']); // the marker FIRST
mkdir($child . $sep . 'web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $path => $info) {
    $rel = substr((string) $path, strlen($webSrc) + 1);
    if (in_array(explode($sep, $rel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        refuse('web/ holds a link (' . $rel . '); refusing to copy it');
    }
    if ($info->isDir() === true) {
        mkdir($child . $sep . 'web' . $sep . $rel, 0700);
    } else {
        copy((string) $path, $child . $sep . 'web' . $sep . $rel);
        $copied++;
    }
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}
mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/portal-address-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
$keyText = bin2hex(random_bytes(32));
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', $keyText);

// 🪪 The stand-in mail server's certificates, made for this run by the mail
//    library's own test's maker, lifted as text exactly as the mail pages
//    self-test lifts it. If its shape ever changes, this stops here and says so.
$standinDir = $child . $sep . 'standin';
foreach (['', $sep . 'certs', $sep . 'log', $sep . 'bodies'] as $sub) {
    mkdir($standinDir . $sub, 0700);
}
$libraryTest = (string) file_get_contents($repo . $sep . 'tools' . $sep . 'maillib' . $sep . 'tests' . $sep . 'run.php');
$from        = strpos($libraryTest, "// 🪪 The test's own certificates, written byte by byte");
$to          = strpos($libraryTest, '// 🎭 What each stand-in server does');
if ($from === false || $to === false || $to <= $from) {
    refuse('the certificate maker could not be found in tools/maillib/tests/run.php, where this test lifts it from');
}
file_put_contents($child . $sep . 'certificate-maker.php', "<?php\n// Lifted from tools/maillib/tests/run.php by tools/portal-address-selftest.php for ONE run. Test only.\ndeclare(strict_types=1);\n" . substr($libraryTest, $from, $to - $from));
require $child . $sep . 'certificate-maker.php';
if (function_exists('makeCertificates') === false) {
    refuse('the text lifted from tools/maillib/tests/run.php did not define makeCertificates()');
}
makeCertificates($standinDir . $sep . 'certs');
if (is_file($standinDir . $sep . 'certs' . $sep . 'ca.pem') === false || is_file($standinDir . $sep . 'certs' . $sep . 'good.pem') === false) {
    refuse('the certificate maker did not make ca.pem and good.pem');
}
$mailPassword = 'Mp' . bin2hex(random_bytes(10)) . '!';
file_put_contents($standinDir . $sep . 'scenarios.ser', serialize(['accept' => ['user' => MAILBOX_USER, 'pass' => $mailPassword]]));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/portal-address-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Portal-Address-Selftest: __TOKEN__');
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
// Required at the TOP LEVEL, never inside a function: the portal's start-up
// sets $mysqli and $SETTINGS as globals.
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
ROUTER;
file_put_contents($child . $sep . 'router.php', str_replace('__TOKEN__', $state['token'], $router));

// The servers: each command as a LIST (no shell in between), ONE process each.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$quiet             = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']];
// 🎭 `--watch-parent`: the pipe to the stand-in's standard input stays open for
//    as long as this file lives. If this file is killed, the stand-in sees the
//    pipe close, deletes its keys and folder, and stops.
$state['mailProc'] = proc_open(
    [PHP_BINARY, $repo . $sep . 'tools' . $sep . 'maillib' . $sep . 'tests' . $sep . 'smtp-standin.php', $standinDir, '--watch-parent'],
    [0 => ['pipe', 'r'], 1 => ['file', $standinDir . $sep . 'standin.out', 'w'], 2 => ['file', $standinDir . $sep . 'standin.err', 'w']],
    $mailPipes
);
$state['mailPipe'] = $mailPipes[0] ?? null;
// 🌐 The portal. `openssl.cafile` is how this run's made-up certificate
//    authority is trusted without one line of the portal being changed.
//    `max_execution_time` is raised because a busy machine can make a page
//    slow, and a limit meant for a real web server must not decide a test.
$state['proc'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'max_execution_time=180', '-d', 'openssl.cafile=' . $standinDir . $sep . 'certs' . $sep . 'ca.pem',
        '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    $quiet,
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false || is_resource($state['mailProc']) === false) {
    refuse('a server could not be started');
}
$deadline = microtime(true) + 30;
while ((portAnswers($port) === false || is_file($standinDir . $sep . 'ports.ser') === false) && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false || is_file($standinDir . $sep . 'ports.ser') === false) {
    refuse("a server never answered (the portal on port {$port}; the stand-in mail server said: " . trim((string) @file_get_contents($standinDir . $sep . 'standin.err')) . ')');
}
$state['mailPort'] = (int) (((array) unserialize((string) file_get_contents($standinDir . $sep . 'ports.ser'), ['allowed_classes' => false]))['accept'] ?? 0);
if ($state['mailPort'] === 0) {
    refuse('the stand-in mail server did not say which port it is on');
}

echo "\nportal server process " . (int) proc_get_status($state['proc'])['pid'] . ' on port ' . $port . ', stand-in mail server process ' . (int) proc_get_status($state['mailProc'])['pid']
    . ' on port ' . $state['mailPort'] . ' (127.0.0.1 only), PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

// The settings every part below starts from.
// Believe the "X-Forwarded-For" header from 127.0.0.1, so each part has a visitor address of its own.
setting('portal.trustedProxies', '127.0.0.1');
setting('portal.trustedProxyHeader', 'x-forwarded-for');
setting('site.name', 'Selftest Portal');
setting('invites.enabled', '1');
setting('expenses.emailNotifications', 'true');
// The newsletter and order-of-service apps are off on a new installation, and so is sharing a form or an order of service.
setting('newsletter.enabled', '1');
setting('service_plans.enabled', '1');
setting('service_plans.public_share.enabled', 'true');
setting('forms.allowPublic', 'true');
$cronToken = bin2hex(random_bytes(16));
setting('user_reminders.cron_token', $cronToken);
setting('user_reminders.enabled', '1');
// Mail goes through the stand-in server, and nowhere else.
foreach (['mail.fallbackProvider', 'mail.defaultReplyTo', 'mail.limit.perHour'] as $key) {
    setting($key, '');
}
setting('mail.provider', 'smtp');
setting('mail.smtp.host', MAIL_HOST);
setting('mail.smtp.port', (string) $state['mailPort']);
setting('mail.smtp.security', 'starttls');
setting('mail.smtp.username', MAILBOX_USER);
setting('mail.smtp.password', sealed($mailPassword, $keyText), 1);
setting('mail.defaultFromAddress', SENDER);
setting('mail.defaultFromName', 'Selftest Office');
setting('portal.support.email', '');
setting('site.url', '');
setWay(false, 'session');

$here = 'http://127.0.0.1:' . $port; // the address this test really uses the portal at

// =============================================================================
head('F. PortalAddress itself, with a made-up request (one organisation)');
// =============================================================================
$got = child(['host' => USED . ':8443', 'https' => true, 'steps' => [['state'], ['saved'], ['fromRequest'], ['base'], ['absolute', 'reset-password?token=abc'], ['absolute', '']]]);
ok(
    'F1 NOT SET: the address is the one the request arrived at, with its https and its port, and a link is that address plus the page',
    $got === ['not-set', '', 'https://' . USED . ':8443', 'https://' . USED . ':8443', 'https://' . USED . ':8443/reset-password?token=abc', 'https://' . USED . ':8443/'],
    (string) json_encode($got)
);
setting('site.url', SET);
$got = child(['host' => EVIL, 'steps' => [['state'], ['saved'], ['fromRequest'], ['base'], ['absolute', 'reset-password?token=abc'], ['linkHtml', 'tasks?edit=5', 'View <this>'], ['forPage', 'f/abc']]]);
ok(
    'F2 SET: every link begins with the set address, whatever host name the request names; the request\'s own is still reported as what it is',
    $got === ['set', SET, 'http://' . EVIL, SET, SET . '/reset-password?token=abc', '<p><a href="' . SET . '/tasks?edit=5">View &lt;this&gt;</a></p>', SET . '/f/abc'],
    (string) json_encode($got)
);
setting('site.url', '');
$got = child(['steps' => [['fromRequest'], ['base'], ['absolute', 'tasks'], ['linkHtml', 'tasks', 'View'], ['forPage', 'f/abc']]]);
ok('F3 NOT SET and no host at all (a command line): no address, no link, no link line; only a link shown ON A PAGE falls back to the address without a host name', $got === ['', '', '', '', '/f/abc'], (string) json_encode($got));
$got = child(['host' => 'evil.example/portal', 'steps' => [['fromRequest'], ['absolute', 'x'], ['host', 'user@evil.example'], ['fromRequest'], ['host', BAD_HOST], ['fromRequest'], ['host', "evil.example\r\nX-Added: 1"], ['fromRequest'], ['absolute', 'x']]]);
ok('F4 NOT SET and a Host header that is not a host name (a path, an "@", an underscore, a line break): it is never passed on, and there is no link', array_values(array_filter($got, static fn (mixed $v): bool => $v === '')) === ['', '', '', '', '', ''] && count($got) === 9, (string) json_encode($got));
$unusable = [];
foreach (['https://portal.f4.selftest.invalid/portal', 'javascript:alert(1)', "https://portal.f4.selftest.invalid\r\nBcc: x@evil.example"] as $junk) {
    setting('site.url', $junk);
    $unusable[] = child(['host' => USED, 'steps' => [['state'], ['saved'], ['base'], ['absolute', 'x']]]);
}
ok(
    'F5 a stored value that is not a usable address (a path, a script, a line break) is IGNORED, not used: the state says so and links go by the request',
    $unusable === array_fill(0, 3, ['unusable', '', 'http://' . USED, 'http://' . USED . '/x']),
    (string) json_encode($unusable)
);
// An organisation's OWN row. An administrator of one organisation can add rows for it in the general settings editor.
setting('site.url', '');
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (1, 'site.url', ?, '', 0)", 's', ['https://' . EVIL]);
$got = [child(['host' => USED, 'steps' => [['state'], ['base']]])];
setting('site.url', SET);
$got[] = child(['host' => USED, 'steps' => [['state'], ['base']]]);
q("DELETE FROM tblSettings WHERE siteID = 1 AND settingKey = 'site.url'");
ok('F6 an ORGANISATION\'s own `site.url` row is never believed: with it in place the address is still the request\'s, or the installation-wide one', $got === [['not-set', 'http://' . USED], ['set', SET]], (string) json_encode($got));
$kept = one("SELECT settingID, settingValue, defaultValue, isSensitive FROM tblSettings WHERE settingKey = 'site.url' AND siteID IS NULL");
q("DELETE FROM tblSettings WHERE settingKey = 'site.url' AND siteID IS NULL");
$got = child(['host' => USED, 'steps' => [['state'], ['base']]]);
q("INSERT INTO tblSettings (settingID, siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, NULL, 'site.url', '', ?, ?)", 'isi', [(int) $kept['settingID'], (string) $kept['defaultValue'], (int) $kept['isSensitive']]);
ok('F7 no row at all (a database not yet upgraded) is "not set", not an error', $got === ['not-set', 'http://' . USED], (string) json_encode($got));
$got = child(['host' => USED, 'steps' => [['base'], ['closeDb'], ['state'], ['base'], ['absolute', 'x'], ['forPage', 'x']]]);
ok(
    'F8 when the setting CANNOT BE READ, no link is built, not even from the request: a database fault must not let a made-up host name back in',
    $got === ['http://' . USED, 'closed', 'unreadable', '', '', '/x'],
    (string) json_encode($got)
);
$got = [child(['host' => EVIL, 'steps' => [['clientName', SENDER]]])];
setting('site.url', SET);
$got[] = child(['host' => EVIL, 'steps' => [['clientName', SENDER]]]);
ok('F9 the name the portal gives a mail server comes from the SET address, else the "from" address, and never from the request', $got === [['mail.f4.selftest.invalid'], ['portal.f4.selftest.invalid']], (string) json_encode($got));

// =============================================================================
head('F. PortalAddress with several organisations, in each of the three ways');
// =============================================================================
q('UPDATE tblSites SET hostPattern = ? WHERE siteID = 1', 's', [HOST_A]);
setWay(true, 'subdomain');
setting('site.url', '');
$got = child(['host' => HOST_B . ':9661', 'steps' => [['siteId'], ['base'], ['base', 1], ['absolute', 'tasks?edit=5', ORG_B], ['absolute', 'tasks?edit=5']]]);
ok(
    'F10 OWN ADDRESS EACH, not set: each organisation\'s link carries its OWN host name, with the request\'s http and port',
    $got === [ORG_B, 'http://' . HOST_B . ':9661', 'http://' . HOST_A . ':9661', 'http://' . HOST_B . ':9661/tasks?edit=5', 'http://' . HOST_B . ':9661/tasks?edit=5'],
    (string) json_encode($got)
);
setting('site.url', SET);
$got = child(['host' => EVIL, 'steps' => [['siteId'], ['base', ORG_B], ['base', 1], ['absolute', 'rota', ORG_B], ['linkHtml', 'rota', 'Rota', ORG_B]]]);
ok(
    'F11 OWN ADDRESS EACH, set: organisation B\'s link is B\'s own host name with the set address\'s https, for a request that named a made-up host',
    $got === [1, 'https://' . HOST_B, 'https://' . HOST_A, 'https://' . HOST_B . '/rota', '<p><a href="https://' . HOST_B . '/rota">Rota</a></p>'],
    (string) json_encode($got)
);
setting('site.url', '');
$got = child(['host' => EVIL . ':9999', 'steps' => [['base', 1], ['base', ORG_B]]]);
ok('F12 OWN ADDRESS EACH, not set, a made-up host: the made-up NAME reaches no link (its port does, which the file says it cannot prevent)', $got === ['http://' . HOST_A . ':9999', 'http://' . HOST_B . ':9999'], (string) json_encode($got));
setting('site.url', SET . ':8443');
$got = child(['host' => EVIL, 'steps' => [['base', ORG_B]]]);
ok('F13 OWN ADDRESS EACH: a port in the set address is carried to every organisation\'s link', $got === ['https://' . HOST_B . ':8443'], (string) json_encode($got));
setting('site.url', SET);
$got = child(['host' => USED, 'steps' => [['base', ORG_C], ['absolute', 'tasks', ORG_C], ['linkHtml', 'tasks', 'View', ORG_C], ['base', 99999999]]]);
q('UPDATE tblSites SET hostPattern = NULL WHERE siteID = 1');
$got[] = child(['host' => USED, 'steps' => [['base', 1]]])[0] ?? null;
q('UPDATE tblSites SET hostPattern = ? WHERE siteID = 1', 's', [HOST_A]);
ok(
    'F14 OWN ADDRESS EACH: an organisation with NO address of its own, and one that does not exist, get no link at all; the DEFAULT organisation with none gets the portal\'s address',
    $got === ['', '', '', '', SET],
    (string) json_encode($got)
);

setWay(true, 'path');
$got = child(['host' => USED, 'uri' => '/cron/user-reminders', 'steps' => [['siteId'], ['absolute', 'tasks?edit=5', ORG_B], ['absolute', 'tasks?edit=5', 1], ['absolute', 'tasks', ORG_C]]]);
ok(
    'F15 FIRST PART OF THE ADDRESS, a request with no organisation in its address (a job on a timer): each organisation\'s link begins with ITS OWN key',
    $got === [1, SET . '/' . KEY_B . '/tasks?edit=5', SET . '/' . $key1 . '/tasks?edit=5', SET . '/' . KEY_C . '/tasks'],
    (string) json_encode($got)
);
$got = child(['host' => USED, 'uri' => '/' . KEY_B . '/tasks', 'steps' => [['siteId'], ['absolute', 'rota'], ['absolute', 'rota', 1]]]);
ok('F16 FIRST PART OF THE ADDRESS, a request inside organisation B: its own links keep B\'s key, and a link for the default organisation does NOT carry B\'s', $got === [ORG_B, SET . '/' . KEY_B . '/rota', SET . '/' . $key1 . '/rota'], (string) json_encode($got));
$got = child(['host' => USED, 'uri' => '/' . $key1 . '/tasks', 'steps' => [['siteId'], ['force', ORG_B], ['siteId'], ['absolute', 'rota'], ['linkHtml', 'rota', 'Rota']]]);
ok(
    'F17 FIRST PART OF THE ADDRESS: a job that arrived under ONE organisation\'s key and then turned to organisation B gives B\'s key, not the one it arrived with',
    $got === [1, 'forced', ORG_B, SET . '/' . KEY_B . '/rota', '<p><a href="' . SET . '/' . KEY_B . '/rota">Rota</a></p>'],
    (string) json_encode($got)
);
$got = child(['host' => USED, 'uri' => '/cron/user-reminders', 'steps' => [['absolute', 'tasks', 99999999], ['linkHtml', 'tasks', 'View', 99999999]]]);
ok('F19 FIRST PART OF THE ADDRESS: an organisation that cannot be found gets NO link, never one with no key in it (that would open the default organisation)', $got === ['', ''], (string) json_encode($got));
// Organisation B switched off, asked for in both of the ways that put an organisation into an address.
q('UPDATE tblSites SET isActive = 0 WHERE siteID = ?', 'i', [ORG_B]);
$got = child(['host' => USED, 'uri' => '/cron/user-reminders', 'steps' => [['absolute', 'tasks', ORG_B], ['linkHtml', 'tasks', 'View', ORG_B]]]);
setWay(true, 'subdomain');
$got = array_merge($got, child(['host' => USED, 'steps' => [['base', ORG_B], ['absolute', 'tasks', ORG_B]]]));
q('UPDATE tblSites SET isActive = 1 WHERE siteID = ?', 'i', [ORG_B]);
ok('F20 a SWITCHED-OFF organisation gets no link, neither by its key nor by its own address', $got === ['', '', '', ''], (string) json_encode($got));
// Still "own address each". The question the dashboard asks, held to what base() really gives the DEFAULT organisation
// for a request that names a made-up host: with an address of its own lent to it, with none, and with the address set.
setting('site.url', '');
$got = [child(['host' => EVIL, 'steps' => [['siteId'], ['canChoose'], ['base', 1]]])];
q('UPDATE tblSites SET hostPattern = NULL WHERE siteID = 1');
$got[] = child(['host' => EVIL, 'steps' => [['siteId'], ['canChoose'], ['base', 1], ['absolute', 'reset-password?token=abc']]]);
setting('site.url', SET);
$got[] = child(['host' => EVIL, 'steps' => [['canChoose'], ['base', 1]]]);
q('UPDATE tblSites SET hostPattern = ? WHERE siteID = 1', 's', [HOST_A]);
setting('site.url', '');
setWay(true, 'path');
$got[] = child(['host' => EVIL, 'steps' => [['canChoose']]]);
setting('site.url', SET);
ok(
    'F21 OWN ADDRESS EACH, not set: a made-up host name reaches the default organisation\'s link exactly when that organisation has NO address of its own, and visitorCanChooseHost() says so; it says no once an address is set, and yes in the other ways while none is',
    $got === [[1, false, 'http://' . HOST_A], [1, true, 'http://' . EVIL, 'http://' . EVIL . '/reset-password?token=abc'], [false, SET], [true]],
    (string) json_encode($got)
);

setWay(true, 'session');
$got = child(['host' => USED, 'steps' => [['absolute', 'tasks', ORG_B], ['base', ORG_B]]]);
ok('F18 SIGN-IN SESSION: an address cannot say which organisation is meant, so nothing is added (the file says so)', $got === [SET . '/tasks', SET], (string) json_encode($got));
setWay(false, 'session');
q('UPDATE tblSites SET hostPattern = NULL WHERE siteID = 1');

// =============================================================================
head('M. The mail, on the real pages: an invitation');
// =============================================================================
$rootJar = signIn('root', $password, ['ip' => ip(1)]);
$orgJar  = signIn('orgadmin', $password, ['ip' => ip(2)]);
$invite  = static function (string $jar, string $to, array $o): array {
    $form = req($jar, 'GET', '/invites/new', null, $o);

    return req($jar, 'POST', '/invites/save', ['csrf_token' => token($form['body']), 'emails' => $to, 'role' => 'user', 'expiryDays' => '7', 'welcomeMessage' => ''], $o);
};
setting('site.url', '');
mailSince();
$answer = $invite($rootJar, 'invited-one@f4.selftest.invalid', ['ip' => ip(1)]);
$mail   = mailFor(mailSince(), 'invited-one@f4.selftest.invalid');
$link   = hrefs($mail)[0] ?? '';
ok(
    'M1 NOT SET: an invitation\'s link begins with the address the administrator was using, and holds a whole link',
    $answer['status'] === 302 && preg_match('#^' . preg_quote($here, '#') . '/auth/invite\?token=[0-9a-f]{64}$#', $link) === 1,
    'the save answered HTTP ' . $answer['status'] . '; link: ' . ($link === '' ? '(no message, or no link in it)' : $link)
);
$opened = req(newJar(), 'GET', (string) substr($link, strlen($here)), null, ['ip' => ip(3)]);
ok('M2 and that link opens the page for accepting the invitation', $link !== '' && $opened['status'] === 200, 'HTTP ' . $opened['status']);
setting('site.url', SET);
$answer = $invite($rootJar, 'invited-two@f4.selftest.invalid', ['ip' => ip(1)]);
$mail   = mailFor(mailSince(), 'invited-two@f4.selftest.invalid');
$links  = hrefs($mail);
ok(
    'M3 SET: the invitation\'s link begins with the SET address, so does the "Visit the portal" link at the foot, and the address the administrator was using is nowhere in the message',
    preg_match('#^' . preg_quote(SET, '#') . '/auth/invite\?token=[0-9a-f]{64}$#', $links[0] ?? '') === 1 && in_array(SET . '/', $links, true) === true
        && str_contains((string) ($mail['html'] ?? '') . (string) ($mail['text'] ?? ''), '127.0.0.1') === false,
    'links: ' . json_encode($links)
);
// No address to give: nothing set, and a Host header that is not a host name.
setting('site.url', '');
$badJar = signIn('root', $password, ['ip' => ip(4), 'host' => BAD_HOST]);
$page   = req($badJar, 'GET', '/invites/new', null, ['ip' => ip(4), 'host' => BAD_HOST]);
$answer = $invite($badJar, 'invited-three@f4.selftest.invalid', ['ip' => ip(4), 'host' => BAD_HOST]);
$issued = (int) one("SELECT COUNT(*) AS n FROM tblInvitation WHERE email = 'invited-three@f4.selftest.invalid'")['n'];
ok(
    'M4 NO ADDRESS TO GIVE: the form says invitations cannot be sent and why, its button is switched off, and a request sent anyway issues nothing and sends nothing',
    str_contains(words($page['body']), 'Invitations cannot be sent yet.') === true && preg_match('/<button type="submit"[^>]* disabled>/', $page['body']) === 1
        && $answer['status'] === 302 && $answer['location'] === '/invites/new' && $issued === 0 && mailSince() === [],
    'page says it: ' . (str_contains(words($page['body']), 'Invitations cannot be sent yet.') === true ? 'yes' : 'no') . '; save answered HTTP ' . $answer['status'] . ' to ' . $answer['location'] . '; rows issued: ' . $issued
);

// =============================================================================
head('M. The mail, on the real pages: a password reset sent with a made-up host name');
// =============================================================================
setting('site.url', SET);
mailSince();
$answer = askForLink(mailOf('member'), ['ip' => ip(11), 'host' => EVIL]);
$mail   = mailFor(mailSince(), mailOf('member'));
$link   = hrefs($mail)[0] ?? '';
ok(
    'M5 SET: a reset asked for with a MADE-UP host name mails a link that begins with the SET address, and the made-up name is nowhere in the message',
    $answer['status'] === 302 && preg_match('#^' . preg_quote(SET, '#') . '/reset-password\?token=[0-9a-f]{64}$#', $link) === 1 && stripos((string) ($mail['html'] ?? '') . (string) ($mail['text'] ?? ''), EVIL) === false,
    'the page answered HTTP ' . $answer['status'] . '; link: ' . ($link === '' ? '(no message, or no link in it)' : $link)
);
$opened = req(newJar(), 'GET', (string) substr($link, strlen(SET)), null, ['ip' => ip(12)]);
ok('M6 and the link in that message is a working one: it opens the page for choosing a new password', $link !== '' && $opened['status'] === 200 && str_contains($opened['body'], 'name="password_confirm"') === true, 'HTTP ' . $opened['status']);
setting('site.url', '');
$answer = askForLink(mailOf('member'), ['ip' => ip(13), 'host' => EVIL]);
$mail   = mailFor(mailSince(), mailOf('member'));
$link   = hrefs($mail)[0] ?? '';
ok(
    'M7 NOT SET (said plainly, so nobody is surprised): the same request mails a link that begins with the MADE-UP host name. Setting the address is what closes this',
    preg_match('#^http://' . preg_quote(EVIL, '#') . '/reset-password\?token=[0-9a-f]{64}$#', $link) === 1,
    'link: ' . ($link === '' ? '(no message, or no link in it)' : $link)
);
$before = (int) one("SELECT COUNT(*) AS n FROM tblErrors WHERE errorCode = 'RESET_NO_ADDRESS'")['n'];
$answer = askForLink(mailOf('member'), ['ip' => ip(14), 'host' => BAD_HOST]);
$after  = (int) one("SELECT COUNT(*) AS n FROM tblErrors WHERE errorCode = 'RESET_NO_ADDRESS'")['n'];
ok(
    'M8 NO ADDRESS TO GIVE: the person is answered exactly as always, no message is sent, and the administrator\'s error list says why',
    $answer['status'] === 302 && str_ends_with($answer['location'], '/forgot-password?sent=1') === true && mailSince() === [] && $after === $before + 1,
    'HTTP ' . $answer['status'] . ' to ' . $answer['location'] . '; RESET_NO_ADDRESS records: ' . $before . ' then ' . $after
);

// =============================================================================
head('M. The mail, on the real pages: an erasure confirmation asked for with a made-up host name');
// =============================================================================
// This link carries a secret too: whoever opens it confirms the request. The
// person is signed in at the made-up host from the start, so the request that
// files the erasure names it.
setting('site.url', SET);
mailSince();
$viaEvil   = ['ip' => ip(41), 'host' => EVIL];
$leaverJar = signIn('leaver', $password, $viaEvil);
$form      = req($leaverJar, 'GET', '/account/erasure-request', null, $viaEvil);
$answer    = req($leaverJar, 'POST', '/account/erasure-request', ['csrf_token' => token($form['body'])], $viaEvil);
$mail      = mailFor(mailSince(), mailOf('leaver'));
$link      = array_values(array_filter(hrefs($mail), static fn (string $href): bool => str_contains($href, 'erasure-confirm') === true))[0] ?? '';
$filed     = (int) one("SELECT COUNT(*) AS n FROM tblErasureRequest WHERE userID = ? AND status = 'pending_confirmation'", 'i', [PEOPLE['leaver']])['n'];
ok(
    'M14 SET: an erasure request made with a MADE-UP host name mails a confirmation link that begins with the SET address, and the made-up name is nowhere in the message',
    $form['status'] === 200 && $answer['status'] === 302 && $filed === 1 && preg_match('#^' . preg_quote(SET, '#') . '/account/erasure-confirm\?token=[0-9a-f]{64}$#', $link) === 1
        && stripos((string) ($mail['html'] ?? '') . (string) ($mail['text'] ?? ''), EVIL) === false,
    'the page answered HTTP ' . $form['status'] . ', the request HTTP ' . $answer['status'] . '; requests filed: ' . $filed . '; link: ' . ($link === '' ? '(no message, or no such link in it)' : $link)
);

// =============================================================================
head('M. The mail, on the real pages: a newsletter, with no address to give and then with one');
// =============================================================================
// The same browser as M4: a global administrator at a Host that is not a host
// name, so with nothing set there is no address to write an unsubscribe link
// with. The newsletter is a draft with nobody locked in.
setting('site.url', '');
mailSince();
$viaBad    = ['ip' => ip(4), 'host' => BAD_HOST];
$sendNews  = static function () use ($badJar, $viaBad): array {
    // The form token comes from the page that holds the "Send now" button.
    $page   = req($badJar, 'GET', '/newsletter/edit?id=' . NEWS, null, $viaBad);
    $answer = req($badJar, 'POST', '/newsletter/send', ['csrf_token' => token($page['body']), 'newsletterID' => (string) NEWS], $viaBad);
    $after  = req($badJar, 'GET', '/newsletter/recipients?id=' . NEWS, null, $viaBad);

    return [
        'status' => $answer['status'],
        'to'     => $answer['location'],
        'kind'   => preg_match('#<div class="alert alert-(\w+)" role="alert" id="newsletter-send-result">(.*?)</div>#s', $after['body'], $m) === 1 ? $m[1] : '',
        'said'   => isset($m[2]) === true ? words($m[2]) : '',
        'offers' => str_contains($after['body'], 'Continue sending') === true,
        'row'    => one('SELECT status, sentCount FROM tblNewsletter WHERE newsletterID = ?', 'i', [NEWS]),
        'locked' => (int) one('SELECT COUNT(*) AS n FROM tblNewsletterRecipient WHERE newsletterID = ?', 'i', [NEWS])['n'],
    ];
};
$before = (int) one("SELECT COUNT(*) AS n FROM tblErrors WHERE errorCode = 'NO_PORTAL_ADDRESS'")['n'];
$got    = $sendNews();
$after  = (int) one("SELECT COUNT(*) AS n FROM tblErrors WHERE errorCode = 'NO_PORTAL_ADDRESS'")['n'];
ok(
    'M15 NO ADDRESS TO GIVE: a newsletter is not sent; the page says so, why, and where the address is set; the newsletter is NOT left marked as sending (still a draft, nobody locked in, no "Continue sending" button); and the administrators\' error list records it once',
    $got['status'] === 302 && str_ends_with($got['to'], '/newsletter/recipients?id=' . NEWS) === true && $got['kind'] === 'danger' && str_contains($got['said'], 'This newsletter was not sent') === true
        && str_contains($got['said'], 'does not know its own web address') === true && str_contains($got['said'], 'Admin, Settings, Organisation') === true
        && $got['row'] === ['status' => 'draft', 'sentCount' => 0] && $got['locked'] === 0 && $got['offers'] === false && mailSince() === [] && $after === $before + 1,
    json_encode($got) . '; NO_PORTAL_ADDRESS records: ' . $before . ' then ' . $after
);
setting('site.url', SET);
$got   = $sendNews();
$mail  = mailFor(mailSince(), mailOf('member'));
$links = hrefs($mail);
ok(
    'M16 and once the address is SET the same newsletter goes: the member\'s copy carries an unsubscribe link that begins with the set address, the newsletter is marked sent, and the page says how many were sent',
    $got['row'] !== null && $got['row']['status'] === 'sent' && (int) $got['row']['sentCount'] === $got['locked'] && $got['locked'] >= 4 && $got['kind'] === 'success'
        && $got['said'] === 'Sent ' . $got['locked'] . ', failed 0. Done.' && $got['offers'] === false
        && $links !== [] && $links === array_values(array_filter($links, static fn (string $href): bool => preg_match('#^' . preg_quote(SET, '#') . '/unsubscribe\?token=[0-9a-f]{40}$#', $href) === 1)),
    json_encode($got) . '; links in the member\'s copy: ' . json_encode($links)
);

// =============================================================================
head('M. The mail, on the real pages: the reminder job, for two organisations');
// =============================================================================
/** Run the reminder job once, as the timer would, and return what each of the two members was sent. */
$remind = static function (string $host) use ($cronToken): array {
    q('UPDATE tblTasks SET reminderSent = 0, reminderDate = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE taskID IN (?, ?)', 'ii', [TASK_1, TASK_B]);
    mailSince();
    $answer   = req('', 'GET', '/cron/user-reminders?key=' . $cronToken, null, ['ip' => ip(21), 'host' => $host]);
    $messages = mailSince();

    return [
        'status' => $answer['status'],
        'one'    => hrefs(mailFor($messages, mailOf('member'))),
        'b'      => hrefs(mailFor($messages, mailOf('memberb'))),
        'said'   => mb_substr(trim($answer['body']), 0, 200),
    ];
};
q('UPDATE tblSites SET hostPattern = ? WHERE siteID = 1', 's', [HOST_A]);
setWay(true, 'subdomain');
setting('site.url', '');
$got = $remind(EVIL);
ok(
    'M9 OWN ADDRESS EACH, not set, started by a request naming a made-up host: organisation B\'s reminder carries B\'s own address and the default organisation\'s carries its own',
    $got['status'] === 200 && $got['b'] === ['http://' . HOST_B . '/tasks?edit=' . TASK_B] && $got['one'] === ['http://' . HOST_A . '/tasks?edit=' . TASK_1],
    (string) json_encode($got)
);
setting('site.url', SET);
$got = $remind(EVIL);
ok(
    'M10 OWN ADDRESS EACH, set: the same, with the set address\'s https',
    $got['b'] === ['https://' . HOST_B . '/tasks?edit=' . TASK_B] && $got['one'] === ['https://' . HOST_A . '/tasks?edit=' . TASK_1],
    (string) json_encode($got)
);
setWay(true, 'path');
$got = $remind(EVIL);
ok(
    'M11 FIRST PART OF THE ADDRESS: organisation B\'s reminder carries the set address and then B\'s key; the default organisation\'s carries its own key',
    $got['status'] === 200 && $got['b'] === [SET . '/' . KEY_B . '/tasks?edit=' . TASK_B] && $got['one'] === [SET . '/' . $key1 . '/tasks?edit=' . TASK_1],
    (string) json_encode($got)
);
setWay(false, 'session');
q('UPDATE tblSites SET hostPattern = NULL WHERE siteID = 1');

// =============================================================================
head('M. The mail: an expense e-mail');
// =============================================================================
mailSince();
$ran   = child(['host' => EVIL, 'steps' => [['expense', CLAIM, 'approved']]]);
$mail  = mailFor(mailSince(), mailOf('member'));
$links = hrefs($mail);
ok(
    'M12 SET: the "View Claim" button of an expense e-mail is a WHOLE link that begins with the set address (it used to be a bare "/expenses/view…", which opens nothing from a mail program)',
    $ran === ['notified'] && $links === [SET . '/expenses/view?id=' . CLAIM] && str_contains((string) ($mail['html'] ?? ''), 'href="/') === false,
    json_encode($ran) . '; links: ' . json_encode($links)
);
setting('site.url', '');
$ran  = child(['steps' => [['expense', CLAIM, 'approved']]]);
$mail = mailFor(mailSince(), mailOf('member'));
ok(
    'M13 NO ADDRESS TO GIVE: the expense e-mail still goes, with the button left out; it never carries a link without a host name',
    $ran === ['notified'] && $mail !== null && hrefs($mail) === [] && str_contains((string) $mail['html'], 'View Claim') === false && str_contains((string) $mail['html'], 'has been') === true,
    json_encode($ran) . '; message: ' . ($mail === null ? 'none' : 'links ' . json_encode(hrefs($mail)))
);

// =============================================================================
head('C. The "Web addresses" card');
// =============================================================================
$cardPage = '/admin/settings/organisation';
/** Send the card's form as the browser in `$jar`. */
$saveCard = static function (string $jar, int $ipLast, array $fields, bool $withToken = true) use ($cardPage): array {
    $form = req($jar, 'GET', $cardPage, null, ['ip' => ip($ipLast)]);

    return req($jar, 'POST', $cardPage . '/save', ($withToken === true ? ['csrf_token' => token($form['body'])] : []) + ['form' => 'web-addresses'] + $fields, ['ip' => ip($ipLast)]);
};
/** The message the page shows after a save, as words. */
$flash = static function (string $jar, int $ipLast) use ($cardPage): string {
    $page = req($jar, 'GET', $cardPage, null, ['ip' => ip($ipLast)]);

    return preg_match('#<div class="alert alert-(\w+)">(.*?)</div>#s', $page['body'], $m) === 1 ? $m[1] . ': ' . words($m[2]) : '';
};
setting('site.url', '');
setting('portal.support.email', '');
setting('org.address.line1', 'Selftest Street 1');
$page = req($rootJar, 'GET', $cardPage, null, ['ip' => ip(1)]);
$card = preg_match('#<div class="card mb-4" id="web-addresses">(.*?)</form>#s', $page['body'], $m) === 1 ? $m[1] : '';
ok(
    'C1 the card is on the Organisation settings page: it says the address is NOT set and what that means, shows the address in use, and gives a global administrator a Save button',
    $page['status'] === 200 && str_contains(words($card), 'It is not set.') === true && str_contains(words($card), 'You are using the portal at ' . $here . ' right now.') === true
        && str_contains($card, 'Save web addresses') === true && substr_count($page['body'], '<h1') === 1,
    'HTTP ' . $page['status'] . '; card ' . ($card === '' ? 'NOT found' : 'found: ' . mb_substr(words($card), 0, 300))
);
$answer = $saveCard($rootJar, 1, ['portalAddress' => 'https://example.org/portal', 'supportEmail' => 'help@f4.selftest.invalid']);
$said   = $flash($rootJar, 1);
ok(
    'C2 an address with a PATH is refused: nothing is stored (not the support address either), and the page says why and what to type',
    $answer['status'] === 302 && stored('site.url') === '' && stored('portal.support.email') === '' && str_starts_with($said, 'danger: Nothing was saved.') === true && str_contains($said, 'folder') === true,
    'HTTP ' . $answer['status'] . '; stored "' . stored('site.url') . '" / "' . stored('portal.support.email') . '"; the page said: ' . $said
);
$results = [];
foreach (["https://portal.f4.selftest.invalid\r\nBcc: x@evil.example" => 'line break', 'https://admin:pw@portal.f4.selftest.invalid' => 'user name', 'ftp://portal.f4.selftest.invalid' => 'https:// or http://'] as $typed => $mustSay) {
    $saveCard($rootJar, 1, ['portalAddress' => (string) $typed, 'supportEmail' => '']);
    $results[] = stored('site.url') === '' && str_contains($flash($rootJar, 1), $mustSay) === true;
}
ok('C3 an address with a line break inside it, a user name, or another scheme is refused on the card too, each with its own reason, and nothing is stored', $results === [true, true, true], (string) json_encode($results));
$answer = $saveCard($rootJar, 1, ['portalAddress' => ' HTTPS://Portal.F4.Selftest.Invalid/ ', 'supportEmail' => ' help@f4.selftest.invalid ']);
$said   = $flash($rootJar, 1);
$record = one("SELECT activityDescription FROM tblActivityLogs WHERE activityType = 'PortalAddressSaved' AND userID = ? ORDER BY 1 DESC LIMIT 1", 'i', [PEOPLE['root']]);
ok(
    'C4 a usable address is saved TIDIED installation-wide, the support address against THIS ORGANISATION, one row each; the postal address on the same page is not touched; and the activity log records it without the support address',
    $answer['status'] === 302 && str_ends_with($answer['location'], '#web-addresses') === true && stored('site.url') === SET && rowsOf('site.url') === 1
        && storedForSite('portal.support.email', ONLY_SITE) === 'help@f4.selftest.invalid' && rowsOfForSite('portal.support.email', ONLY_SITE) === 1
        && stored('org.address.line1') === 'Selftest Street 1' && $said === 'success: Web addresses saved.'
        && $record !== null && str_contains((string) $record['activityDescription'], SET) === true && str_contains((string) $record['activityDescription'], 'help@') === false,
    'HTTP ' . $answer['status'] . ' to ' . $answer['location'] . '; stored "' . stored('site.url') . '" (' . rowsOf('site.url') . ' row) / organisation "' . storedForSite('portal.support.email', ONLY_SITE) . '"; postal line "' . stored('org.address.line1') . '"; said: ' . $said . '; record: ' . json_encode($record)
);
$page = req($rootJar, 'GET', $cardPage, null, ['ip' => ip(1)]);
ok(
    'C5 the card then shows the saved address, and warns that it is not the address being used right now, naming both',
    str_contains($page['body'], 'name="portalAddress"') === true && preg_match('/id="portalAddress"[^>]*value="' . preg_quote(SET, '/') . '"/s', $page['body']) === 1
        && str_contains(words($page['body']), 'Links in e-mails begin with ' . SET . ', but you are using the portal at ' . $here . ' right now.') === true,
    mb_substr(words($page['body']), 0, 400)
);
$answer = $saveCard($rootJar, 1, ['portalAddress' => 'https://other.f4.selftest.invalid', 'supportEmail' => 'not an address']);
$said   = $flash($rootJar, 1);
ok('C6 a support address that is not an e-mail address is refused, and the portal\'s address typed beside it is NOT saved either', stored('site.url') === SET && storedForSite('portal.support.email', ONLY_SITE) === 'help@f4.selftest.invalid' && str_contains($said, 'not an e-mail address') === true,
    'stored "' . stored('site.url') . '" / organisation "' . storedForSite('portal.support.email', ONLY_SITE) . '"; said: ' . $said);
$answer = $saveCard($orgJar, 2, ['portalAddress' => 'https://' . EVIL, 'supportEmail' => 'thief@f4.selftest.invalid']);
$said   = $flash($orgJar, 2);
$page   = req($orgJar, 'GET', $cardPage, null, ['ip' => ip(2)]);
$card   = preg_match('#<div class="card mb-4" id="web-addresses">(.*?)</form>#s', $page['body'], $m) === 1 ? $m[1] : '';
ok(
    'C7 an administrator of ONE organisation cannot change the PORTAL\'S address: refused with the reason, nothing changed; their card now HAS a Save button and ONE switched-off box, because the support address on it is their own (#677)',
    stored('site.url') === SET && storedForSite('portal.support.email', ONLY_SITE) === 'help@f4.selftest.invalid'
        && str_contains($said, 'only a global administrator can change it') === true
        // 🔁 WHAT THIS ASSERTION USED TO SAY, because the change was deliberate
        //    and somebody will wonder. It required NO Save button, TWO
        //    ` disabled>` boxes, and the words "Only a global administrator can
        //    change these". All three were right while every setting on the card
        //    was installation-wide. Since #677 the support address belongs to the
        //    organisation, so an ordinary administrator must be able to save it:
        //    the button is there, exactly ONE box (the portal's address) is
        //    switched off, and the note says which is which. A `disabled` control
        //    is not submitted, which is what keeps the other setting safe.
        && $card !== '' && str_contains($card, 'Save web addresses') === true && substr_count($card, ' disabled>') === 1
        && str_contains(words($card), 'The support e-mail address is this organisation\'s own, so you can change it here.') === true
        && str_contains(words($card), 'only a global administrator can change that one') === true,
    'stored "' . stored('site.url') . '"; said: ' . $said . '; card: ' . mb_substr(words($card), -260)
);
$answer = $saveCard($rootJar, 1, ['portalAddress' => 'https://' . EVIL, 'supportEmail' => ''], false);
ok('C8 a global administrator\'s own request with no form token is refused and changes nothing', $answer['status'] === 400 && stored('site.url') === SET && storedForSite('portal.support.email', ONLY_SITE) === 'help@f4.selftest.invalid', 'HTTP ' . $answer['status'] . '; stored "' . stored('site.url') . '"');
$saveCard($rootJar, 1, ['portalAddress' => 'http://plain.f4.selftest.invalid', 'supportEmail' => 'help@f4.selftest.invalid']);
$saidHttp = $flash($rootJar, 1);
$httpWas  = stored('site.url');
$saveCard($rootJar, 1, ['portalAddress' => '', 'supportEmail' => '']);
$saidNone = $flash($rootJar, 1);
ok(
    'C9 an http address is saved with a warning that it is not https; clearing the portal\'s address stores an empty value (still one row) while clearing the support box REMOVES the organisation\'s row, so it inherits again (#677)',
    $httpWas === 'http://plain.f4.selftest.invalid' && str_starts_with($saidHttp, 'warning:') === true && str_contains($saidHttp, 'not https') === true
        && stored('site.url') === '' && rowsOf('site.url') === 1
        // 🧹 '(no row)', not ''. This used to assert an empty VALUE, and it passed
        //    for the wrong reason after #677 — it was reading the installation's
        //    row, which the card no longer writes. The difference matters: the
        //    merge falls back to the installation only when there is NO row, so a
        //    stored empty string would mask the installation's support address
        //    for ever with no way back from the page. Clearing the box therefore
        //    deletes the row.
        && storedForSite('portal.support.email', ONLY_SITE) === '(no row)'
        && str_starts_with($saidNone, 'warning:') === true && str_contains($saidNone, 'is not set') === true,
    'http: "' . $httpWas . '" said ' . $saidHttp . '; cleared: "' . stored('site.url') . '" / organisation support: "' . storedForSite('portal.support.email', ONLY_SITE) . '" said ' . $saidNone
);

// =============================================================================
head('N. The two notices on the admin dashboard');
// =============================================================================
/**
 * What the dashboard shows about the address: [HTTP status, one entry for each
 * notice shown (which notice, its words, whether it links to the card, its
 * own HTML), where the answer sent the browser if anywhere]. `$host` is the
 * Host header to send in place of the true one.
 */
$notice = static function (string $jar, int $ipLast, string $host = ''): array {
    $page = req($jar, 'GET', '/admin', null, ['ip' => ip($ipLast), 'host' => $host]);
    $out  = [];
    foreach (['portal-address-not-set', 'portal-address-differs'] as $id) {
        if (preg_match('#<div class="alert[^"]*" role="alert" id="' . $id . '">(.*?)</div>#s', $page['body'], $m) === 1) {
            $out[] = [$id, words($m[1]), str_contains($m[1], 'href="/admin/settings/organisation#web-addresses"'), $m[1]];
        }
    }

    return [$page['status'], $out, $page['location']];
};
/**
 * The "Use this address" button in the first notice shown, as a browser
 * would send it: [where its form posts, the fields the page wrote into it].
 * Null when that notice holds no such form.
 */
$useThis = static function (array $shown): ?array {
    if (preg_match('#<form method="post" action="([^"]*)"[^>]*id="portal-address-use-this">(.*?)</form>#s', (string) ($shown[0][3] ?? ''), $form) !== 1
        || preg_match('#<button type="submit"[^>]*>.*?Use this address</button>#s', $form[2]) !== 1) {
        return null;
    }
    preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $form[2], $fields, PREG_SET_ORDER);
    $post = [];
    foreach ($fields as $field) {
        $post[$field[1]] = html_entity_decode($field[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return [html_entity_decode($form[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $post];
};
/** The words of the first notice shown, or '' when none is. */
$wordsOf = static fn (array $shown): string => (string) ($shown[0][1] ?? '');
setting('site.url', '');
[$status, $shown] = $notice($rootJar, 1);
[, $shownOrg]     = $notice($orgJar, 2);
ok(
    'N1 NOT SET: the dashboard says so, says a visitor can choose the address a link is built from, and sends a global administrator to the card; an administrator of one organisation is told whom to ask',
    $status === 200 && count($shown) === 1 && $shown[0][0] === 'portal-address-not-set' && str_contains($shown[0][1], 'has not been set') === true && str_contains($shown[0][1], 'a visitor can choose that') === true && $shown[0][2] === true
        && count($shownOrg) === 1 && str_contains($shownOrg[0][1], 'Ask a global administrator to set it') === true && $shownOrg[0][2] === false,
    'HTTP ' . $status . '; ' . json_encode([$shown, $shownOrg])
);
setting('site.url', SET);
[, $shown] = $notice($rootJar, 1);
ok(
    'N2 SET, BUT NOT WHERE YOU ARE: the dashboard names the address links begin with and the address the page was reached at',
    count($shown) === 1 && $shown[0][0] === 'portal-address-differs' && str_contains($shown[0][1], 'Links in e-mails begin with ' . SET) === true && str_contains($shown[0][1], 'you reached this page at ' . $here) === true,
    (string) json_encode($shown)
);
setting('site.url', $here);
[, $shown] = $notice($rootJar, 1);
ok('N3 SET to the address in use: neither notice is shown', $shown === [], (string) json_encode($shown));
setting('site.url', 'https://portal.f4.selftest.invalid/portal');
[, $shown] = $notice($rootJar, 1);
ok('N4 stored as something that is not a usable address: the dashboard says it is being ignored', count($shown) === 1 && $shown[0][0] === 'portal-address-not-set' && str_contains($shown[0][1], 'is not a usable address, so it is being ignored') === true, (string) json_encode($shown));

// ---- The "Use this address" button, and the wording in each state. ----
$junk      = 'https://portal.f4.selftest.invalid/portal'; // stored, and not a usable address
$canChoose = 'a visitor can choose that';                 // the ordinary sentence ends with this
$ownEach   = 'links in e-mails carry each organisation\'s own address'; // the softer sentence holds this
setting('site.url', '');
setting('portal.support.email', 'help@f4.selftest.invalid');
[, $shown]    = $notice($rootJar, 1);
[, $shownOrg] = $notice($orgJar, 2);
$press        = $useThis($shown);
ok(
    'N5 NOT SET: a GLOBAL administrator is shown the address in use and a "Use this address" button that posts that address, with a form token, to the card\'s own save; an administrator of ONE organisation is shown no button and no form',
    $press !== null && $press[0] === $cardPage . '/save' && array_keys($press[1]) === ['csrf_token', 'form', 'portalAddress'] && preg_match('/^[0-9a-f]{64}$/', $press[1]['csrf_token']) === 1
        && $press[1]['form'] === 'web-addresses' && $press[1]['portalAddress'] === $here && str_contains($wordsOf($shown), 'You reached this page at ' . $here . '.') === true
        && count($shownOrg) === 1 && $useThis($shownOrg) === null && str_contains($shownOrg[0][3], '<form') === false && str_contains($wordsOf($shownOrg), 'Use this address') === false,
    'the button: ' . json_encode($press === null ? null : [$press[0], array_keys($press[1]), $press[1]['portalAddress'] ?? null]) . '; the organisation administrator sees: ' . $wordsOf($shownOrg)
);
setting('site.url', $junk);
[, $shown]    = $notice($rootJar, 1);
[, $shownOrg] = $notice($orgJar, 2);
$press        = $useThis($shown);
ok(
    'N6 NOT USABLE: the notice reads "…is being ignored. Until it is corrected, a link…" (not "Until it is set", the other notice\'s words), still says a visitor can choose the address, offers a global administrator the same button, and tells anybody else to ask for it to be corrected',
    str_contains($wordsOf($shown), 'so it is being ignored. Until it is corrected, a link in an e-mail') === true && str_contains($wordsOf($shown), $canChoose) === true && str_contains($wordsOf($shown), 'Until it is set') === false
        && str_contains($wordsOf($shown), 'has not been set') === false && $press !== null && $press[1]['portalAddress'] === $here
        && str_contains($wordsOf($shownOrg), 'Until it is corrected') === true && str_contains($wordsOf($shownOrg), 'Ask a global administrator to correct it at Settings, Organisation.') === true && $useThis($shownOrg) === null,
    json_encode([$wordsOf($shown), $wordsOf($shownOrg)])
);
// Three presses that must change nothing, each made in the "not usable" state so that a change would show:
// the stored text must still be the junk afterwards, and the support address untouched.
$fields    = $press[1] ?? [];
$noToken   = req($rootJar, 'POST', $cardPage . '/save', array_diff_key($fields, ['csrf_token' => '']), ['ip' => ip(1)]);
$keptOne   = stored('site.url');
$refused   = req($rootJar, 'POST', $cardPage . '/save', ['portalAddress' => 'https://example.org/portal'] + $fields, ['ip' => ip(1)]);
$saidWhy   = $flash($rootJar, 1);
$keptTwo   = stored('site.url');
$orgToken  = token(req($orgJar, 'GET', $cardPage, null, ['ip' => ip(2)])['body']);
$byOrg     = req($orgJar, 'POST', $cardPage . '/save', ['csrf_token' => $orgToken] + $fields, ['ip' => ip(2)]);
$saidOrg   = $flash($orgJar, 2);
ok(
    'N7 the button\'s request changes NOTHING when it comes without its form token (HTTP 400), with an address the check refuses in place of the one offered ("Nothing was saved", with the reason), or from an administrator of one organisation (refused, with the reason)',
    $fields !== [] && $noToken['status'] === 400 && $keptOne === $junk && $refused['status'] === 302 && $keptTwo === $junk && str_starts_with($saidWhy, 'danger: Nothing was saved.') === true && str_contains($saidWhy, 'folder') === true
        // 📍 stored(), the INSTALLATION's row — not storedForSite(), which part C
        //    uses. Both are right in their own place and the difference caught a
        //    mistake of mine: part N sets this value with the `setting()` helper,
        //    which writes installation-wide, and the button sends no support
        //    field at all, so what must be unchanged here is the installation's
        //    row. Changing this to the organisation's level made it ask for a
        //    value part C had already cleared, and the test said so.
        && $byOrg['status'] === 302 && stored('site.url') === $junk && str_contains($saidOrg, 'only a global administrator can change it') === true && stored('portal.support.email') === 'help@f4.selftest.invalid',
    'no token: HTTP ' . $noToken['status'] . ', stored "' . $keptOne . '"; refused address: HTTP ' . $refused['status'] . ', stored "' . $keptTwo . '", said: ' . $saidWhy . '; organisation administrator: HTTP ' . $byOrg['status'] . ', stored "' . stored('site.url') . '", said: ' . $saidOrg
);
// The press itself: exactly the fields the dashboard wrote, sent where its form points. The dashboard is read
// again first, as a browser would have to: a form token works once, and N7 has just used the one read for N6.
[, $shown] = $notice($rootJar, 1);
$press     = $useThis($shown);
$answer  = $press === null ? ['status' => 0, 'location' => ''] : req($rootJar, 'POST', $press[0], $press[1], ['ip' => ip(1)]);
$saidNow = $flash($rootJar, 1);
// The NEWEST record: by now this account has saved the card several times (part C).
$record  = one("SELECT activityDescription FROM tblActivityLogs WHERE activityType = 'PortalAddressSaved' AND userID = ? ORDER BY logID DESC LIMIT 1", 'i', [PEOPLE['root']]);
[, $shown] = $notice($rootJar, 1);
ok(
    'N8 pressing "Use this address" saves the address in use over the unusable text, in ONE row; it does NOT touch the support e-mail address (the button sends none); the activity log records who and what; the administrator lands on the card; and the dashboard then shows neither notice',
    $answer['status'] === 302 && str_ends_with($answer['location'], $cardPage . '#web-addresses') === true && stored('site.url') === $here && rowsOf('site.url') === 1 && stored('portal.support.email') === 'help@f4.selftest.invalid'
        && rowsOf('portal.support.email') === 1 && str_starts_with($saidNow, 'warning: Web addresses saved.') === true && $record !== null && str_contains((string) $record['activityDescription'], $here) === true
        && str_contains((string) $record['activityDescription'], 'The support e-mail address: not changed.') === true && $shown === [],
    'HTTP ' . $answer['status'] . ' to ' . $answer['location'] . '; stored "' . stored('site.url') . '" (' . rowsOf('site.url') . ' row) / "' . stored('portal.support.email') . '"; said: ' . $saidNow . '; record: ' . json_encode($record) . '; dashboard: ' . json_encode($shown)
);
// The same global administrator at a Host that is not a host name (the browser of M4): nothing could be saved, so no button.
setting('site.url', '');
[$status, $shown] = $notice($badJar, 4, BAD_HOST);
ok(
    'N9 when the address the page was reached at is NOT one the portal accepts, no button is shown, the notice says why, and it still sends the administrator to the card',
    $status === 200 && count($shown) === 1 && $useThis($shown) === null && str_contains($shown[0][3], '<form') === false && str_contains($wordsOf($shown), $canChoose) === true
        && str_contains($wordsOf($shown), 'It cannot be set in one press from here, because the address you reached this page at is not a plain host name the portal can store.') === true && $shown[0][2] === true,
    'HTTP ' . $status . '; ' . $wordsOf($shown)
);

// ---- "Own address each": what the notice says depends on the DEFAULT organisation's own address. ----
// The default organisation has none here (the Organisations page allows that on purpose), so a request naming
// a made-up host opens it and its link carries the made-up name (F21). The notice must say a visitor can choose.
setWay(true, 'subdomain');
$states = [];
foreach (['' => 'Until it is set', $junk => 'Until it is corrected'] as $storedText => $until) {
    setting('site.url', (string) $storedText);
    [, $shown] = $notice($rootJar, 1);
    $states[]  = count($shown) === 1 && $shown[0][0] === 'portal-address-not-set' && str_contains($wordsOf($shown), $until . ', a link in an e-mail') === true && str_contains($wordsOf($shown), $canChoose) === true
        && str_contains($wordsOf($shown), $ownEach) === false && ($useThis($shown)[1]['portalAddress'] ?? '') === $here;
}
ok(
    'N10 OWN ADDRESS EACH, the default organisation with NO address of its own: not set or not usable, the notice says a visitor can choose the address a link is built from (it used to say only the http part and the port follow the request), and the button is offered',
    $states === [true, true],
    json_encode($states) . '; last: ' . $wordsOf($shown)
);
q('UPDATE tblSites SET hostPattern = ? WHERE siteID = 1', 's', [HOST_A]);
$states = [];
foreach (['' => 'Until it is set', $junk => 'Until it is corrected'] as $storedText => $until) {
    setting('site.url', (string) $storedText);
    [, $shown] = $notice($rootJar, 1);
    $states[]  = count($shown) === 1 && $shown[0][0] === 'portal-address-not-set' && str_contains($wordsOf($shown), $until . ', ' . $ownEach . ', but whether they begin with http or https, and any port, comes from whatever request caused the e-mail.') === true
        && str_contains($wordsOf($shown), $canChoose) === false;
}
ok(
    'N11 OWN ADDRESS EACH, the default organisation WITH an address of its own: the notice says links carry each organisation\'s own address and only the http part and the port follow the request, and does not say a visitor can choose',
    $states === [true, true],
    json_encode($states) . '; last: ' . $wordsOf($shown)
);
q('UPDATE tblSites SET hostPattern = NULL WHERE siteID = 1');
// Reached at organisation B's OWN address while the default organisation has none: saving this address as the
// portal's would point the default organisation's links at organisation B, so there is no button.
setting('site.url', '');
// Signed in AT organisation B's address: a browser keeps its sign-in for the host name it was made at.
$viaB = signIn('root', $password, ['ip' => ip(51), 'host' => HOST_B]);
[$status, $shown, $sentTo] = $notice($viaB, 51, HOST_B);
ok(
    'N12 OWN ADDRESS EACH, reached at ANOTHER organisation\'s own address while the default organisation has none: no button is shown, and the notice says why',
    $status === 200 && count($shown) === 1 && $useThis($shown) === null && str_contains($shown[0][3], '<form') === false && str_contains($wordsOf($shown), $canChoose) === true
        && str_contains($wordsOf($shown), 'You reached this page at http://' . HOST_B . ', which is this organisation\'s own address and not the portal\'s.') === true && $shown[0][2] === true,
    'HTTP ' . $status . ($sentTo === '' ? '' : ' to ' . $sentTo) . '; ' . $wordsOf($shown)
);
setWay(false, 'session');
setting('site.url', '');

// =============================================================================
head('H. The Help page, with and without a support address');
// =============================================================================
$memberJar = signIn('member', $password, ['ip' => ip(31)]);
setting('portal.support.email', '');
$page = req($memberJar, 'GET', '/help/support', null, ['ip' => ip(31)]);
ok(
    'H1 with NO support address set, the Help page says so and says who can set one; it shows no e-mail link and no address of ours',
    $page['status'] === 200 && str_contains($page['body'], 'id="no-support-address"') === true && str_contains(words($page['body']), 'No support e-mail address has been set up for this portal yet') === true
        && str_contains($page['body'], 'mailto:') === false && stripos($page['body'], ownName()) === false,
    'HTTP ' . $page['status']
);
setting('portal.support.email', 'help@f4.selftest.invalid');
$page = req($memberJar, 'GET', '/help/support', null, ['ip' => ip(31)]);
ok('H2 with one set, the page gives it as an e-mail link', str_contains($page['body'], 'href="mailto:help@f4.selftest.invalid"') === true && str_contains($page['body'], 'id="no-support-address"') === false, 'HTTP ' . $page['status']);
$page = req($rootJar, 'GET', '/help/admin', null, ['ip' => ip(1)]);
$text = words((string) (preg_match('#<div class="portal-card p-4 mb-4" id="web-addresses">(.*?)\n</div>#s', $page['body'], $m) === 1 ? $m[1] : ''));
ok(
    'H3 the administrator\'s guide has a section on the portal\'s web address: where it is set and by whom, the dashboard\'s one-press button, what happens until it is set, and that it does not check the address works',
    $page['status'] === 200 && str_contains($page['body'], 'href="#web-addresses"') === true && str_contains($text, 'Only a global administrator can save it.') === true
        && str_contains($text, 'a global administrator sees in it the address they reached the dashboard at and a Use this address button') === true
        && str_contains($text, 'Until it is set, each link is built from the address of whatever request caused the e-mail.') === true && str_contains($text, 'It does not check that the address works.') === true,
    'HTTP ' . $page['status'] . '; section: ' . mb_substr($text, 0, 200)
);

// =============================================================================
head('Q. The QR pictures on the three pages that show one');
// =============================================================================
// Each page showed its picture from "/qr" followed by ".php", and every
// address ending in .php answers "page not found" (the router above imitates
// that rule of .htaccess), so all three pictures were broken. The registered
// address is /qr. tools/audit-checks/check_no_php_in_urls.py does not read
// the address of a picture, which is why nothing had noticed.
$pictures = [];
$wrong    = [];
foreach (['the forms list' => '/forms/manage', 'one form' => '/forms/edit?id=' . FORM, 'an order of service' => '/service-plans/edit?id=' . PLAN] as $what => $path) {
    $page = req($rootJar, 'GET', $path, null, ['ip' => ip(1)]);
    preg_match_all('/<img src="([^"]*)"[^>]*alt="QR code for [^"]*"/', $page['body'], $found);
    $src = count($found[1]) === 1 ? html_entity_decode($found[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    if ($page['status'] !== 200 || str_starts_with($src, '/qr?content=') === false || stripos($src, '.php') !== false) {
        $wrong[] = $what . ' (HTTP ' . $page['status'] . ', ' . count($found[1]) . ' QR picture(s)): ' . ($src === '' ? 'no address' : $src);
    }
    $pictures[$what] = $src;
}
ok('Q1 each of the three pages that show a QR picture (the forms list, one form, an order of service) shows exactly one, at the registered address /qr with no ".php" in it', $wrong === [] && count($pictures) === 3, implode(' | ', $wrong));
$wrong = [];
foreach ($pictures as $what => $src) {
    $picture = $src === '' ? ['status' => 0, 'head' => '', 'body' => ''] : req($rootJar, 'GET', $src, null, ['ip' => ip(1)]);
    if ($picture['status'] !== 200 || preg_match('#^Content-Type:\s*image/svg\+xml#mi', $picture['head']) !== 1 || str_contains($picture['body'], '<svg') === false) {
        $wrong[] = $what . ': HTTP ' . $picture['status'] . ', ' . (preg_match('/^Content-Type:[^\r\n]*/mi', $picture['head'], $m) === 1 ? $m[0] : 'no content type');
    }
}
$old = req($rootJar, 'GET', '/qr.php?content=' . urlencode($here . '/f/' . FORM_TOKEN) . '&size=64', null, ['ip' => ip(1)]);
ok('Q2 each of those picture addresses answers 200 with a picture (an SVG image) for the signed-in person; the address as it used to be written, ending ".php", answers "not found"', $wrong === [] && $old['status'] === 404, implode(' | ', $wrong) . '; the old address: HTTP ' . $old['status']);

// =============================================================================
head('Last. Nothing went wrong behind the pages, and everything is put back');
// =============================================================================
$log = (string) @file_get_contents($child . $sep . 'php-errors.log') . "\n" . (string) @file_get_contents($child . $sep . 'child-errors.log');
// Lines this test causes ON PURPOSE are taken out first: the two that
// PortalAddress writes when the stored value is unusable or cannot be read,
// and what PHP says about the connection the "cannot be read" part closed.
$bad = array_values(array_filter(
    (array) preg_split('/\R/', $log),
    static fn (string $line): bool => preg_match('/PHP (Fatal|Parse|Warning|Deprecated|Notice)|Uncaught/', $line) === 1 && preg_match('/already closed|Couldn\'t fetch mysqli/', $line) !== 1
));
ok(
    'Z1 the PHP error logs hold no warning, notice or fatal error, and DO hold the two lines PortalAddress writes when the stored address is unusable or cannot be read',
    $bad === [] && str_contains($log, 'is not a usable web address, so it is being ignored') === true && str_contains($log, 'PortalAddress could not read the site.url setting') === true,
    count($bad) . ' found: ' . implode(' | ', array_map(static fn (string $line): string => mb_substr($line, 0, 220), array_slice($bad, 0, 4)))
);
$standinSaid = trim((string) @file_get_contents($standinDir . $sep . 'standin.err'));
ok('Z2 the stand-in mail server reported no fault of its own', $standinSaid === '', $standinSaid);

$before   = $state['settings'];
$mailPid  = (int) proc_get_status($state['mailProc'])['pid'];
$mailPort = (int) $state['mailPort'];
cleanup();
$left = (int) one(
    'SELECT (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 941000 AND 941999) + (SELECT COUNT(*) FROM tblLocalAccounts WHERE userID BETWEEN 941000 AND 941999) '
    . "+ (SELECT COUNT(*) FROM tblActivityLogs WHERE visitorIP LIKE '192.0.2.%' OR userID BETWEEN 941000 AND 941999) + (SELECT COUNT(*) FROM tblPasswordResets WHERE userID BETWEEN 941000 AND 941999) "
    . "+ (SELECT COUNT(*) FROM tblEmailLog WHERE toRecipients LIKE '%f4.selftest.invalid%') + (SELECT COUNT(*) FROM tblInvitation WHERE email LIKE '%f4.selftest.invalid') "
    . '+ (SELECT COUNT(*) FROM tblSites WHERE siteID IN (' . ORG_B . ', ' . ORG_C . ')) + (SELECT COUNT(*) FROM tblTasks WHERE taskID IN (' . TASK_1 . ', ' . TASK_B . ')) '
    . '+ (SELECT COUNT(*) FROM tblExpenseClaims WHERE claimID = ' . CLAIM . ") + (SELECT COUNT(*) FROM tblSettings WHERE settingKey = 'site.url' AND siteID IS NOT NULL) "
    . '+ (SELECT COUNT(*) FROM tblErasureRequest WHERE userID BETWEEN 941000 AND 941999) + (SELECT COUNT(*) FROM tblNewsletter WHERE newsletterID = ' . NEWS . ') '
    . '+ (SELECT COUNT(*) FROM tblNewsletterRecipient WHERE newsletterID = ' . NEWS . ') + (SELECT COUNT(*) FROM tblForms WHERE formID = ' . FORM . ') + (SELECT COUNT(*) FROM tblServicePlan WHERE planID = ' . PLAN . ') '
    . "+ (SELECT COUNT(*) FROM tblSites WHERE siteID = 1 AND hostPattern IS NOT NULL) + (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'selftestF4%') AS n"
)['n'];
$settingsBack = true;
foreach ($before as $key => $row) {
    $settingsBack = $settingsBack && (one('SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]) === $row);
}
ok('Z3 clean-up: the made-up accounts, organisations and records are gone, the default organisation has no address again, and every setting is as it was (' . count($before) . ' settings)', $left === 0 && $settingsBack === true && rowsOf('site.url') === 1,
    'rows left: ' . $left . '; settings back: ' . ($settingsBack === true ? 'yes' : 'no') . '; site.url rows: ' . rowsOf('site.url'));
ok('Z4 clean-up: the portal server and the stand-in mail server (process ' . $mailPid . ') are stopped, nothing is left listening, and the scratch folder is deleted', portAnswers($port) === false && portAnswers($mailPort) === false && file_exists($child) === false,
    'portal port answers: ' . (portAnswers($port) === true ? 'yes' : 'no') . '; mail port answers: ' . (portAnswers($mailPort) === true ? 'yes' : 'no') . '; folder exists: ' . (file_exists($child) === true ? 'yes' : 'no'));

echo "\n{$pass} passed, {$fail} failed, " . ($pass + $fail) . ' ran of ' . EXPECTED_CHECKS . " expected.\n";
if (($pass + $fail) !== EXPECTED_CHECKS) {
    echo "FAIL — the number of checks that ran is not the number this file expects. A block was skipped or ran twice,\n";
    echo "or a check was added without updating the number at the top.\n";
    exit(1);
}
if ($fail > 0) {
    echo "FAIL — {$fail} check(s) failed. Read the FAIL lines above before releasing.\n";
    exit(1);
}
echo "PASS — all {$pass} checks passed.\n";
echo "REACHED THE END — the portal address self-test ran every part.\n";
exit(0);
