<?php
// Path: tools/upgrade-pages-selftest.php
/**
 * -----------------------------------------------------------------------------
 * When an upgrade closes the portal, on the REAL pages — self-test 🚧🔢 (#499)
 * -----------------------------------------------------------------------------
 * THE RULES THIS HOLDS (Portal\Core\Maintenance, 13 October 2026)
 *   1. The portal is closed for an upgrade only while a database upgrade is
 *      actually waiting. (It used to be "closed whenever the recorded version
 *      is behind the files'", and a newer package with no database upgrade
 *      in it left every member on the holding page for ever.)
 *   2. THE PORTAL NEVER RECORDS THE VERSION BY ITSELF. Only the Upgrade
 *      page's button does. (For a few hours it recorded the version on the
 *      first request that found nothing waiting. A request that arrived
 *      while a newer version was half uploaded then recorded it, and the
 *      upgrade file that landed a moment later never closed the portal.)
 *   3. While the version is behind, every request asks whether an upgrade is
 *      waiting, once. While the versions are equal, nothing is asked at all.
 * Nothing tested any of them before this file.
 *
 * WHAT IT DOES
 * It copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file, starts two of PHP's built-in web servers on it, signs a
 * made-up global administrator and a made-up member in through the real
 * sign-in page, and sends real requests. It checks:
 *
 *   A. Versions equal (the ordinary day): the portal answers, and the gate
 *      asks the database nothing about upgrades.
 *   B. Files newer, NOTHING waiting: ten requests, by a stranger, a member
 *      and the health address, on both servers. Open every time; the version
 *      is still not recorded; each request asked exactly once. The health
 *      page and the Upgrade page tell an administrator what to do, and
 *      opening them records nothing either. Admin, Migrations is a signpost.
 *   C. THEN THE UPGRADE FILE ARRIVES (the half-uploaded version, completed):
 *      closed at once for a stranger and a member; nothing recorded; the
 *      Upgrade page offers its button; Admin, Migrations runs nothing, by
 *      GET or by POST; the button runs the upgrade and records the version
 *      ONCE; the portal is open again, and nothing is asked any more.
 *   D. Files newer, nothing waiting, the button "Finish the upgrade": it
 *      takes NO snapshot, puts a missing "refuse everything" file back,
 *      checks the stored secrets and records the version once. The same with
 *      the portal closed by its own switch: the button opens it.
 *   E. The portal cannot tell (the `_sql` folder unreadable; the table of
 *      applied upgrades missing): closed; nothing recorded; the Upgrade page
 *      and the health page say why; a form sent with nothing listed runs
 *      nothing. With both back, the portal is open and STILL records nothing.
 *
 * ADDED AFTER THE SECOND CHECK OF THE GATE (13 October 2026), which planted
 * ten faults of its own and found that six of them left this file passing:
 *
 *   F. A database upgrade that FAILS half way: nothing recorded, still
 *      closed, the reason shown, the button offered again and safe to press
 *      again, the words "Applying database migrations" taken away; then the
 *      file mended and the upgrade finished.
 *   G. A `_sql` folder that holds no numbered file at all: closed, and the
 *      page says so (an unfinished upload must not read as "nothing waiting").
 *   H. A name the database has recorded that no file has, beside a file that
 *      IS waiting: closed. "Is anything waiting" goes by names, not by counts.
 *   I. A recorded version AHEAD of the files', and one never recorded: open,
 *      nothing asked, no button.
 *   J. The table of applied upgrades THERE but unreadable: the Upgrade page
 *      is drawn (it was "Server Error"), says why, gives the advice for THAT
 *      cause, and offers no button. A table that is missing gets its own
 *      advice. Each cause has its own.
 *   K. An upgrade file that records itself and then fails: the "Finish the
 *      upgrade" card does not print a sentence kept from before the run.
 *   L. Admin, Migrations and an administrator of ONE organisation: refused
 *      in words and written down, with the versions equal and a file
 *      waiting; a member too; a global administrator still gets the page.
 *   M. One organisation's own row for the recorded version does not count,
 *      and the Settings page refuses to change, add or delete the setting.
 *
 * HOW "ASKS NOTHING", "ASKS ONCE" AND "RECORDED ONCE" ARE COUNTED. The
 * database server's own log of every statement is switched on (into a table)
 * for the run, and the test counts how often the gate's question and the
 * recording statement were run. That needs a database user allowed to change
 * the server's settings (root on a throwaway server). The log is switched
 * back at the end.
 *
 * SAFETY
 * - It refuses unless the database's name starts with `selftest_`. Its two
 *   accounts (919001 to 919003), the settings it changes, its activity lines
 *   and error rows are removed or put back at the start and at the end, and
 *   so is everything sections F to M make (rows, a renamed column, files).
 * - Do NOT run it at the same moment as another self-test on the same
 *   database SERVER: it counts statements server-wide.
 * - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *   temporary folder), deleted at the end only if it still holds this run's
 *   marker. `web/_auth_keys` is never read or copied.
 * - Each server is started with its command as a list (no shell) and is one
 *   process, stopped by its own number. Every answer must carry this run's
 *   header.
 *
 * WHAT THIS CANNOT PROVE
 * - Apache: the router only imitates `.htaccess`.
 * - What the question costs in time. It counts that it is asked once a
 *   request; the time (about half a millisecond) was measured by hand.
 * - Two presses of the Upgrade page's button at the same moment, or a
 *   second apart.
 * - An upgrade that really changes the database: the waiting files here do
 *   nothing, or write one made-up setting and then fail on purpose.
 *   tools/e2e-migrations/ runs the real ones.
 * - Every way a table can be unreadable. Section J renames its column. A
 *   database user whose right to read the table was taken away gets the
 *   same answer from Migrator::waiting() by a different error; that was
 *   tried once by hand on 13 October 2026, on the question alone and not
 *   through the pages, and is not repeated here.
 * - That the versions EQUAL with an upgrade file waiting is closed. It is
 *   open, as it was before the gate asked anything; sections J and L only
 *   use that state.
 * - MariaDB.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_USER=root \
 *         SELFTEST_DB_PASS=… SELFTEST_WEB_PORT=9850 [SELFTEST_WEB_PORT_2=9851] \
 *         php tools/upgrade-pages-selftest.php
 * Exit:   0 only if every check passed and the run reached its end (it prints
 *         "REACHED THE END" last); 1 if a check failed; 2 if it could not run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/499
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// Loaded for its plain helpers (brs_env, brs_connect, brs_check, brs_waitFor,
// brs_removeFolder). It returns before its own run when it is not the script
// being run.
require __DIR__ . '/backup-restore-selftest.php';

const UPS_ROOT      = 919001;   // a global administrator
const UPS_MEMBER    = 919002;
const UPS_ADMIN     = 919003;   // an administrator of ONE organisation, not a global one
const UPS_MIGRATION = '998_upgrade_pages_selftest.sql';
/** Files sections F to M put under _sql for a moment, and names they record. Each does nothing, or fails on purpose. */
const UPS_FAILS     = '997_upgrade_pages_selftest_fails.sql';
const UPS_PHANTOM   = '996_upgrade_pages_selftest_recorded_but_no_file.sql';
const UPS_WAITS     = '995_upgrade_pages_selftest_waits.sql';
const UPS_SELF      = '994_upgrade_pages_selftest_records_itself.sql';
const UPS_EQUAL     = '993_upgrade_pages_selftest_versions_equal.sql';
/** A table that does not exist: asking for it is how a file is made to fail. */
const UPS_NO_TABLE  = 'tblZzUpsNoSuchTable';
/** A setting the failing file's FIRST statement writes, to show the half that ran. */
const UPS_HALF      = 'selftest.upgradePages.firstStatementRan';
/** An ordinary setting, added, changed and deleted on the Settings page as a control. */
const UPS_CONTROL   = 'selftest.upgradePages.ordinarySetting';
const UPS_OLD       = '0.0.1';  // a version older than any the portal has had
/** A page a stranger is sent on to the sign-in page from (302) when the portal is open, and held back from (503) when it is closed. */
const UPS_PAGE      = '/dashboard';
/** The gate's two statements, as the server's log shows them being run. */
const UPS_ASKS    = 'SELECT `filename` FROM `tblMigrations`%';
const UPS_RECORDS = 'INSERT INTO `tblSettings`%portal.installed_version%';

$ups = ['procs' => [], 'child' => '', 'token' => bin2hex(random_bytes(16)), 'ports' => [], 'logMark' => 0, 'errMark' => null,
    'put_back' => [], 'log_was' => null, 'cleaned' => false];

function ups_refuse(string $why): never
{
    echo 'REFUSED. ' . $why . "\nNothing was run, and nothing is proved.\n";
    exit(2);
}

/** Remove this test's own rows and put back what it changed. Safe to run twice. */
function ups_tidyDatabase(): void
{
    global $ups;
    $w = Brs::$writer;
    $w->query('SET SESSION foreign_key_checks = 0');
    foreach (['tblLocalAccounts', 'tblUserSites', 'tblUsers', 'tblActivityLogs'] as $table) {
        $w->query('DELETE FROM `' . $table . '` WHERE userID BETWEEN ' . UPS_ROOT . ' AND ' . UPS_ADMIN);
    }
    if ($ups['logMark'] > 0) {
        $w->query("DELETE FROM tblActivityLogs WHERE logID > " . (int) $ups['logMark'] . " AND activityType IN ('PortalVersionRecorded', 'SecretSettingsRepaired')");
    }
    if ($ups['errMark'] !== null) {
        $w->query('DELETE FROM tblErrors WHERE errorID > ' . (int) $ups['errMark']);
    }
    $w->query('SET SESSION foreign_key_checks = 1');
    // Section E sets the table of applied upgrades aside. If the run died
    // there, put the real one back (the Upgrade page makes an empty one).
    if ((int) $w->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tblZzUpsSetAside'")->fetch_row()[0] === 1) {
        $w->query('DROP TABLE IF EXISTS tblMigrations');
        $w->query('RENAME TABLE tblZzUpsSetAside TO tblMigrations');
    }
    // Section J renames the table's one column for a moment.
    if ((int) $w->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tblMigrations' AND column_name = 'filenameSetAside'")->fetch_row()[0] === 1) {
        $w->query('ALTER TABLE tblMigrations RENAME COLUMN filenameSetAside TO filename');
    }
    $w->query("DELETE FROM tblMigrations WHERE filename IN ('" . implode("', '", [UPS_MIGRATION, UPS_FAILS, UPS_PHANTOM, UPS_WAITS, UPS_SELF, UPS_EQUAL]) . "')");
    $w->query("DELETE FROM tblSettings WHERE settingKey IN ('" . UPS_HALF . "', '" . UPS_CONTROL . "')");
    // Section M makes one organisation's own row for the recorded version.
    $w->query("DELETE FROM tblSettings WHERE settingKey = 'portal.installed_version' AND siteID IS NOT NULL");
    foreach ($ups['put_back'] as $key => $value) {
        $w->prepare('UPDATE tblSettings SET settingValue = ? WHERE settingKey = ? AND siteID IS NULL')->execute([$value, $key]);
        // Section M asks the Settings page to delete the recorded version. If
        // a faulty page did, put the row back: the next run needs it.
        if ((int) $w->query("SELECT COUNT(*) FROM tblSettings WHERE settingKey = '" . $key . "' AND siteID IS NULL")->fetch_row()[0] === 0) {
            $w->prepare('INSERT INTO tblSettings (siteID, settingKey, settingValue) VALUES (NULL, ?, ?)')->execute([$key, $value]);
        }
    }
}

/** Stop the servers, put the server's log setting back, delete the scratch folder, tidy the database. Also runs if the script dies. */
function ups_cleanup(): void
{
    global $ups;
    if ($ups['cleaned'] === true) {
        return;
    }
    $ups['cleaned'] = true;
    foreach ($ups['procs'] as $proc) {
        if (is_resource($proc) === true) {
            proc_terminate($proc);   // by its own process number
            proc_close($proc);
        }
    }
    try {
        if ($ups['log_was'] !== null) {
            Brs::$writer->query("SET GLOBAL general_log = '" . ($ups['log_was'][0] === '1' ? 'ON' : 'OFF') . "'");
            Brs::$writer->query("SET GLOBAL log_output = '" . Brs::$writer->real_escape_string($ups['log_was'][1]) . "'");
        }
        ups_tidyDatabase();
    } catch (Throwable $e) {
        echo 'The database could not be tidied: ' . $e->getMessage() . "\n";
    }
    if ($ups['child'] !== '' && @file_get_contents($ups['child'] . '/.upgrade-pages-selftest-owner') === $ups['token']) {
        @chmod($ups['child'] . '/web/_sql', 0700);   // section E takes this away for a moment
        brs_removeFolder($ups['child']);
    }
}

function ups_portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);
        return true;
    }
    return false;
}

/** A request, not yet sent. $jar says who is asking ('' for a stranger); $server is 0 or 1. */
function ups_handle(string $jar, string $method, string $path, ?array $post = null, int $server = 0): CurlHandle
{
    global $ups;
    $handle = curl_init('http://127.0.0.1:' . $ups['ports'][$server] . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 600,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:'],
    ]);
    if ($jar !== '') {
        curl_setopt_array($handle, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    }
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    return $handle;
}

/** Read what a sent request was answered with. */
function ups_answer(CurlHandle $handle, string|false $raw): array
{
    global $ups;
    if ($raw === false || $raw === '') {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr($raw, 0, $headSize);
    if (preg_match('/^X-Upgrade-Pages-Selftest:\s*' . $ups['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start'];
    }
    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $found) === 1 ? $found[1] : '',
        'body'     => substr($raw, $headSize),
    ];
}

function ups_request(string $jar, string $method, string $path, ?array $post = null): array
{
    $handle = ups_handle($jar, $method, $path, $post);
    return ups_answer($handle, curl_exec($handle));
}

function ups_token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $found) === 1 ? $found[1] : '';
}

function ups_text(string $html): string
{
    return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
}

/** Is this answer the holding page? */
function ups_isHoldingPage(array $answer): bool
{
    return $answer['status'] === 503 && str_contains($answer['body'], '<h1>Portal Maintenance</h1>') === true;
}

/** The Upgrade page's "could not tell" box: its cause, its words, and its advice. */
function ups_cannotTellBox(string $html): array
{
    if (preg_match('/<div class="alert alert-warning" id="upgrade-cannot-tell" data-cause="([a-z-]*)">(.*?)<\/div>/s', $html, $found) !== 1) {
        return ['cause' => '(no such box)', 'words' => '', 'advice' => ''];
    }
    return [
        'cause'  => $found[1],
        'words'  => ups_text($found[2]),
        'advice' => preg_match('/<span id="upgrade-advice">(.*?)<\/span>/s', $found[2], $advice) === 1 ? ups_text($advice[1]) : '',
    ];
}

/** Does the Upgrade page offer anything to press? (Either button, or the "go on anyway" one.) */
function ups_offersAButton(string $html): bool
{
    return str_contains($html, 'id="upgrade-finish"') === true || str_contains($html, 'Pending Migration') === true
        || str_contains($html, 'go_on_without_way_back') === true;
}

/** How many activity-log lines of one kind were written since the mark: for one account, or (0) for anybody. */
function ups_lines(string $type, int $userId = 0): int
{
    global $ups;
    $statement = Brs::$writer->prepare('SELECT COUNT(*) FROM tblActivityLogs WHERE logID > ? AND activityType = ? AND (? = 0 OR userID = ?)');
    $statement->execute([(int) $ups['logMark'], $type, $userId, $userId]);
    return (int) $statement->get_result()->fetch_row()[0];
}

/** One portal-wide setting, read straight from the database. */
function ups_setting(string $key): string
{
    $statement = Brs::$writer->prepare('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL');
    $statement->execute([$key]);
    return (string) ($statement->get_result()->fetch_row()[0] ?? '');
}

function ups_set(string $key, string $value): void
{
    Brs::$writer->prepare('UPDATE tblSettings SET settingValue = ? WHERE settingKey = ? AND siteID IS NULL')->execute([$value, $key]);
}

/**
 * How often the question "is an upgrade waiting?" has been asked, and the
 * recording statement run, by the server's own log. A statement sent as it
 * is appears there as "Query"; a prepared one as "Execute". Both are counted,
 * so that the count does not depend on which way the portal sends it.
 */
function ups_counts(): array
{
    $count = static fn (string $like): int => (int) Brs::$writer->query(
        "SELECT COUNT(*) FROM mysql.general_log WHERE command_type IN ('Query', 'Execute') AND CONVERT(argument USING utf8mb4) LIKE '" . $like . "'"
    )->fetch_row()[0];
    return ['asks' => $count(UPS_ASKS), 'records' => $count(UPS_RECORDS)];
}

/** The health page's "Migrations" line, as words. */
function ups_healthLine(string $jar): string
{
    $page = ups_request($jar, 'GET', '/admin/maintenance/health');
    return preg_match('/Migrations.{0,400}?DB [^<]{0,900}/s', ups_text($page['body']), $found) === 1 ? $found[0] : '(no such line; HTTP ' . $page['status'] . ')';
}

/** The activity-log lines that say the version was recorded, written since the mark. */
function ups_recordedLines(): array
{
    global $ups;
    return array_column(Brs::$writer->query(
        "SELECT activityDescription FROM tblActivityLogs WHERE logID > " . (int) $ups['logMark'] . " AND activityType = 'PortalVersionRecorded' ORDER BY logID"
    )->fetch_all(), 0);
}

/** A request, by somebody, on one of the two servers. */
function ups_on(int $server, string $jar, string $path): array
{
    $handle = ups_handle($jar, 'GET', $path, null, $server);
    return ups_answer($handle, curl_exec($handle));
}

/** Start again from "recorded version behind the files'", with no line in the log yet. */
function ups_setBehind(): void
{
    global $ups;
    ups_set('portal.installed_version', UPS_OLD);
    Brs::$writer->query("DELETE FROM tblActivityLogs WHERE logID > " . (int) $ups['logMark'] . " AND activityType = 'PortalVersionRecorded'");
}

// =============================================================================
// 🚦 Setting up
// =============================================================================

if (str_starts_with(brs_env('SELFTEST_DB_NAME', ''), 'selftest_') === false) {
    ups_refuse("Set SELFTEST_DB_NAME to a throwaway database whose name starts with selftest_ (got '" . brs_env('SELFTEST_DB_NAME', '') . "').");
}
$ups['ports'] = [(int) brs_env('SELFTEST_WEB_PORT', '0')];
$ups['ports'][] = (int) brs_env('SELFTEST_WEB_PORT_2', (string) ($ups['ports'][0] + 1));
foreach ($ups['ports'] as $port) {
    if ($port < 1024) {
        ups_refuse('Set SELFTEST_WEB_PORT to a free port for the test web server (the one after it is used too, or SELFTEST_WEB_PORT_2).');
    }
    if (ups_portAnswers($port) === true) {
        ups_refuse('Something already answers on port ' . $port . '.');
    }
}
if (function_exists('curl_init') === false) {
    ups_refuse('PHP\'s curl extension is needed to send the requests.');
}
try {
    Brs::$writer = brs_connect();
} catch (Throwable $e) {
    ups_refuse('Could not connect to the test database: ' . $e->getMessage());
}

$webSource = (string) realpath(__DIR__ . '/../web');
$parent    = (string) realpath(brs_env('SELFTEST_WEB_DIR', sys_get_temp_dir()));
if ($parent === '' || str_starts_with($parent . '/', dirname($webSource) . '/') === true || str_starts_with($webSource . '/', $parent . '/') === true) {
    ups_refuse('The scratch folder must be outside the repository.');
}
$filesVersion = (string) (require $webSource . '/_core/version.php');
if (version_compare(UPS_OLD, $filesVersion, '<') === false) {
    ups_refuse("The files' version ({$filesVersion}) is not newer than the old version this test records (" . UPS_OLD . ').');
}

register_shutdown_function('ups_cleanup');
foreach (['portal.installed_version', 'portal.maintenance.active', 'portal.maintenance.message'] as $key) {
    $row = Brs::$writer->query("SELECT settingValue FROM tblSettings WHERE settingKey = '" . $key . "' AND siteID IS NULL")->fetch_row();
    if ($row === null) {
        ups_refuse("The database has no portal-wide setting '{$key}'. Load web/_sql/full_schema.sql into it first.");
    }
    $ups['put_back'][$key] = (string) $row[0];
}
ups_tidyDatabase();
$ups['errMark'] = (int) Brs::$writer->query('SELECT COALESCE(MAX(errorID), 0) FROM tblErrors')->fetch_row()[0];
if ((int) Brs::$writer->query("SELECT COUNT(*) FROM tblMigrations")->fetch_row()[0] === 0) {
    ups_refuse('The database records no applied upgrade at all. Load web/_sql/full_schema.sql into it first.');
}

// 🧾 The server's own log of statements, into a table, for this run.
try {
    $ups['log_was'] = Brs::$writer->query('SELECT @@global.general_log, @@global.log_output')->fetch_row();
    Brs::$writer->query("SET GLOBAL log_output = 'TABLE'");
    Brs::$writer->query("SET GLOBAL general_log = 'ON'");
    Brs::$writer->query('SELECT COUNT(*) FROM mysql.general_log')->fetch_row();
} catch (Throwable $e) {
    ups_refuse('This test counts statements in the database server\'s own log, and could not switch that log on: ' . $e->getMessage()
        . ' Run it as a user allowed to change the server\'s settings (root, on a throwaway server).');
}

// 👥 Three accounts, signed in with a password made for this run: a global
//    administrator, a member, and an administrator of ONE organisation.
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
$people   = [UPS_ROOT => ['root', 'Rae Root', 1, 0], UPS_MEMBER => ['member', 'Mel Member', 0, 0], UPS_ADMIN => ['admin', 'Ada Admin', 0, 1]];
foreach ($people as $id => [$who, $name, $isRoot, $isSiteAdmin]) {
    Brs::$writer->prepare('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, ?)')
        ->execute([$id, $name, 'u' . $id . '.upgrade@selftest.invalid', $isRoot]);
    Brs::$writer->prepare('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, ?, 0)')->execute([$id, $isSiteAdmin]);
    Brs::$writer->prepare('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)')
        ->execute([$id, 'selftest-upgrade-' . $who, $hash]);
}

// 📁 The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . '/upgrade-pages-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    ups_refuse("Could not make a NEW scratch folder '{$child}'.");
}
$ups['child'] = $child;
Brs::$root    = $child;   // the only folder brs_removeFolder() will touch
file_put_contents($child . '/.upgrade-pages-selftest-owner', $ups['token']);   // the marker FIRST
mkdir($child . '/web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSource, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $path => $info) {
    $relative = substr((string) $path, strlen($webSource) + 1);
    if (in_array(explode('/', $relative)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        ups_refuse('web/ holds a link (' . $relative . '); refusing to copy it.');
    }
    if ($info->isDir() === true) {
        mkdir($child . '/web/' . $relative, 0700);
    } else {
        copy((string) $path, $child . '/web/' . $relative);
        $copied++;
    }
}
if ($copied < 100) {
    ups_refuse("The copy of web/ holds only {$copied} files.");
}
mkdir($child . '/web/_auth_keys', 0700);
mkdir($child . '/web/_backups', 0750);
mkdir($child . '/sessions', 0700);
$backups = $child . '/web/_backups';
$sqlDir  = $child . '/web/_sql';
file_put_contents($child . '/web/_auth_keys/auth_creds.php', "<?php\n// Written by tools/upgrade-pages-selftest.php for one run; deleted with its folder.\nreturn " . var_export([
    'db_host' => brs_env('SELFTEST_DB_HOST', '127.0.0.1'), 'db_user' => brs_env('SELFTEST_DB_USER', 'root'),
    'db_pass' => brs_env('SELFTEST_DB_PASS', ''), 'db_name' => brs_env('SELFTEST_DB_NAME', ''), 'db_port' => (int) brs_env('SELFTEST_DB_PORT', '3306'),
], true) . ";\n");
file_put_contents($child . '/web/_auth_keys/enc.key', bin2hex(random_bytes(32)));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/upgrade-pages-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Upgrade-Pages-Selftest: __TOKEN__');
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
file_put_contents($child . '/router.php', str_replace('__TOKEN__', $ups['token'], $router));

// The two servers: each its command as a LIST (no shell in between) and ONE
// process, so nothing can outlive it.
$environment = getenv();
unset($environment['PHP_CLI_SERVER_WORKERS']);
$environment['PORTAL_ENV'] = 'prod';
foreach ($ups['ports'] as $number => $port) {
    $ups['procs'][$number] = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $child . '/sessions', '-d', 'error_log=' . $child . '/php-errors.log', '-d', 'log_errors=1',
            // No opcache: sections E and G take a folder away for a moment. A longer
            // time limit, because the machine this runs on may be busy.
            '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-d', 'max_execution_time=300',
            '-S', '127.0.0.1:' . $port, '-t', $child . '/web/public_html', $child . '/router.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . '/server-output.log', 'a'], 2 => ['file', $child . '/server-output.log', 'a']],
        $pipes,
        $child,
        $environment
    );
    if (is_resource($ups['procs'][$number]) === false) {
        ups_refuse('The web server could not be started.');
    }
    brs_waitFor(static fn (): bool => ups_portAnswers($port), 15);
    if (ups_portAnswers($port) === false) {
        ups_refuse('The web server never answered on port ' . $port . '.');
    }
}

echo "When an upgrade closes the portal, on the real pages — self-test\n";
echo str_repeat('=', 78) . "\n";
printf(
    "Server processes %d and %d, ports %d and %d, PHP %s, database server %s, files' version %s.\n",
    (int) proc_get_status($ups['procs'][0])['pid'], (int) proc_get_status($ups['procs'][1])['pid'],
    $ups['ports'][0], $ups['ports'][1], PHP_VERSION, Brs::$writer->server_info, $filesVersion
);

// Signed in while the versions are equal, so that signing in is not what is measured.
ups_set('portal.installed_version', $filesVersion);
ups_set('portal.maintenance.active', '0');
$jar = [];
foreach ($people as [$who]) {
    $jar[$who] = $child . '/jar-' . $who . '.txt';
    $token     = ups_token(ups_request($jar[$who], 'GET', '/login')['body']);
    $in        = ups_request($jar[$who], 'POST', '/login', ['csrf_token' => $token, 'username' => 'selftest-upgrade-' . $who, 'password' => $password]);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        ups_refuse("Signing in as selftest-upgrade-{$who} did not work (HTTP {$in['status']} {$in['location']}).");
    }
}
$ups['logMark'] = (int) Brs::$writer->query('SELECT COALESCE(MAX(logID), 0) FROM tblActivityLogs')->fetch_row()[0];

// =============================================================================
// 🧪 The checks
// =============================================================================

echo "\nA. Versions equal: the ordinary day\n";
$before   = ups_counts();
$stranger = ups_request('', 'GET', '/login');
$member   = ups_request($jar['member'], 'GET', '/dashboard');
$health   = ups_request('', 'GET', '/health');
$after    = ups_counts();
brs_check($stranger['status'] === 200 && $member['status'] === 200 && $health['status'] === 200, 'a stranger gets the sign-in page, a member the dashboard, and /health answers', "HTTP {$stranger['status']}, {$member['status']}, {$health['status']}");
brs_check($after === $before, 'and the gate asked the database nothing about upgrades and recorded nothing (three requests, no statement of either kind)', json_encode($before) . ' then ' . json_encode($after));
brs_check(Brs::$writer->query("SELECT COUNT(*) FROM mysql.general_log WHERE command_type = 'Execute'")->fetch_row()[0] > 0, 'CONTROL: the server\'s log does show the statements the portal runs');

echo "\nB. Files newer, nothing waiting: ten requests\n";
ups_setBehind();
$before = ups_counts();
// Ten requests: a stranger, a member and the health address, on both servers.
$ten = [
    [0, '', UPS_PAGE], [1, '', UPS_PAGE], [0, $jar['member'], '/dashboard'], [1, '', '/health'], [0, '', UPS_PAGE],
    [1, $jar['member'], '/dashboard'], [0, '', '/health'], [1, '', UPS_PAGE], [0, $jar['member'], '/dashboard'], [1, '', '/health'],
];
$notOpen = [];
foreach ($ten as $number => [$server, $who, $path]) {
    $answer = ups_on($server, $who, $path);
    $open   = $who === '' && $path === UPS_PAGE
        ? ($answer['status'] === 302 && str_contains($answer['location'], 'login') === true)
        : $answer['status'] === 200;
    if ($open === false || ups_isHoldingPage($answer) === true) {
        $notOpen[] = 'request ' . ($number + 1) . ' (' . ($who === '' ? 'a stranger' : 'a member') . ', ' . $path . '): HTTP ' . $answer['status'];
    }
}
$after = ups_counts();
brs_check($notOpen === [], 'OPEN EVERY TIME: a stranger is sent to sign in, a member gets the dashboard, /health answers; nobody gets the holding page', implode('; ', $notOpen));
brs_check(ups_setting('portal.installed_version') === UPS_OLD, 'THE VERSION IS STILL NOT RECORDED after ten requests', 'recorded: ' . ups_setting('portal.installed_version'));
brs_check($after['records'] === $before['records'] && ups_recordedLines() === [], 'the recording statement was never run, and no line in the activity log says a version was recorded', json_encode($before) . ' then ' . json_encode($after));
brs_check($after['asks'] - $before['asks'] === 10, 'each of the ten requests asked ONCE whether an upgrade is waiting: ten questions, not more', 'questions asked: ' . ($after['asks'] - $before['asks']));
$line = ups_healthLine($jar['root']);
brs_check(
    str_contains($line, 'DB ' . UPS_OLD . ' / code ' . $filesVersion) === true && str_contains($line, 'No database upgrade is waiting, so this does not close the portal.') === true
    && str_contains($line, 'every request asks the database whether an upgrade is waiting') === true
    && str_contains($line, 'Open the Upgrade page (/admin/upgrade) and press "Finish the upgrade".') === true,
    'the health page\'s Migrations line tells an administrator: nothing is waiting, every request is asking, and which button to press',
    $line
);
$page   = ups_request($jar['root'], 'GET', '/admin/upgrade');
$notice = preg_match('/<p id="upgrade-notice">(.*?)<\/p>/s', $page['body'], $found) === 1 ? ups_text($found[1]) : '';
brs_check(
    $page['status'] === 200 && str_contains($page['body'], 'id="upgrade-finish"') === true && preg_match('/<button type="submit" class="btn btn-warning">Finish the upgrade<\/button>/', $page['body']) === 1
    && str_contains($page['body'], 'None — schema is up to date') === true,
    'the Upgrade page says nothing is waiting AND offers "Finish the upgrade"',
    'HTTP ' . $page['status']
);
brs_check(
    str_contains($notice, 'newer than the version the database is recorded at (' . UPS_OLD . ')') === true
    && str_contains($notice, 'every request asks the database whether an upgrade is waiting') === true && str_contains($notice, 'Open the Upgrade page') === false,
    'and says the same sentence, without telling the reader to open the page they are on',
    $notice === '' ? 'no such sentence' : $notice
);
$signpost = ups_request($jar['root'], 'GET', '/admin/migrations');
brs_check(str_contains($signpost['body'], 'id="migrations-use-upgrade"') === true && str_contains($signpost['body'], 'name="run"') === false, 'Admin, Migrations points to the Upgrade page and offers nothing to run');
// The Admin dashboard. These lines were placed when the package was joined to
// the working branch (13 October 2026), after the package's own checks, so
// they are held here. WHY IT MATTERS: with nothing waiting the portal is open,
// and the dashboard is where an administrator would otherwise never learn that
// there is a button to press. An administrator of ONE organisation cannot open
// the Upgrade page or Migrations, so is shown neither the notice nor a link.
$dash      = ups_request($jar['root'], 'GET', '/admin');
$dashAdmin = ups_request($jar['admin'], 'GET', '/admin');
$dashNote  = preg_match('/<div class="alert alert-warning" role="alert" id="admin-upgrade-notice">(.*?)<\/div>/s', $dash['body'], $found) === 1 ? ups_text($found[1]) : '';
brs_check(
    $dash['status'] === 200 && str_contains($dashNote, 'No database upgrade is waiting, so this does not close the portal.') === true
    && str_contains($dashNote, 'Open the Upgrade page (/admin/upgrade) and press "Finish the upgrade".') === true
    && str_contains($dash['body'], 'Pending Migrations') === true && str_contains($dash['body'], 'admin/migrations') === true,
    'the Admin dashboard tells a GLOBAL administrator the same sentence, and shows them the way to Migrations',
    'HTTP ' . $dash['status'] . '; notice: ' . ($dashNote === '' ? 'none' : $dashNote)
);
brs_check(
    $dashAdmin['status'] === 200 && str_contains($dashAdmin['body'], 'Admin Dashboard') === true
    && str_contains($dashAdmin['body'], 'id="admin-upgrade-notice"') === false
    && str_contains($dashAdmin['body'], 'Pending Migrations') === false && str_contains($dashAdmin['body'], 'admin/migrations') === false,
    'an administrator of ONE organisation gets the dashboard with no such notice and no link to Migrations (card, button or menu): both pages would refuse them',
    'HTTP ' . $dashAdmin['status'] . '; notice there: ' . (str_contains($dashAdmin['body'], 'id="admin-upgrade-notice"') === true ? 'yes' : 'no')
        . '; a link to Migrations: ' . (str_contains($dashAdmin['body'], 'admin/migrations') === true ? 'yes' : 'no')
);
brs_check(ups_setting('portal.installed_version') === UPS_OLD && ups_counts()['records'] === $before['records'], 'opening those three pages recorded nothing: only the button does', 'recorded: ' . ups_setting('portal.installed_version'));

echo "\nC. Then the upgrade file arrives\n";
file_put_contents($sqlDir . '/' . UPS_MIGRATION, "-- Written by tools/upgrade-pages-selftest.php for one run. Does nothing.\nSELECT 1;\n");
$stranger = ups_request('', 'GET', UPS_PAGE);
$member   = ups_request($jar['member'], 'GET', '/dashboard');
brs_check(ups_isHoldingPage($stranger) === true && ups_isHoldingPage($member) === true, 'CLOSED AT ONCE: the very next requests, by a stranger and by a member, get the holding page (503)', "HTTP {$stranger['status']} and {$member['status']}");
brs_check(ups_setting('portal.installed_version') === UPS_OLD && ups_recordedLines() === [], 'and nothing was recorded: the version is still the old one');
$health = ups_request('', 'GET', '/health');
$signIn = ups_request('', 'GET', '/login');
brs_check($health['status'] === 200 && $signIn['status'] === 200, 'CONTROL: /health and the sign-in page still answer while it is closed', "HTTP {$health['status']} and {$signIn['status']}");
$line = ups_healthLine($jar['root']);
brs_check(str_contains($line, 'One database upgrade is waiting, so the portal is closed to everybody except a global administrator.') === true && str_contains($line, 'press its button') === true, 'the health page\'s Migrations line says one upgrade is waiting, that the portal is closed, and what to do', $line);

$page = ups_request($jar['root'], 'GET', '/admin/upgrade');
brs_check(
    $page['status'] === 200 && str_contains($page['body'], UPS_MIGRATION) === true && preg_match('/Run 1 Pending Migration\s*</', $page['body']) === 1
    && str_contains($page['body'], 'id="upgrade-finish"') === false,
    'the Upgrade page is open to a global administrator, lists the waiting upgrade and offers its button',
    'HTTP ' . $page['status']
);
$ranCount = static fn (): int => (int) Brs::$writer->query("SELECT COUNT(*) FROM tblMigrations WHERE filename = '" . UPS_MIGRATION . "'")->fetch_row()[0];
$signpost = ups_request($jar['root'], 'GET', '/admin/migrations');
brs_check(
    $signpost['status'] === 200 && str_contains($signpost['body'], 'id="migrations-use-upgrade"') === true
    && preg_match('#<a class="btn btn-warning" href="/admin/upgrade">Open the Upgrade page</a>#', $signpost['body']) === 1,
    'Admin, Migrations is a signpost: it says why, and links to the Upgrade page',
    'HTTP ' . $signpost['status'] . ' ' . substr(ups_text($signpost['body']), 0, 300)
);
brs_check(
    str_contains($signpost['body'], 'name="run"') === false && str_contains($signpost['body'], UPS_MIGRATION) === false,
    'and offers nothing to run: no "Run" form and no list of files'
);
$formToken = ups_token($page['body']);
$forced    = ups_request($jar['root'], 'POST', '/admin/migrations', ['csrf_token' => $formToken, 'run' => 'all']);
$forcedOne = ups_request($jar['root'], 'POST', '/admin/migrations', ['csrf_token' => $formToken, 'run' => 'single', 'filename' => UPS_MIGRATION]);
brs_check(
    str_contains($forced['body'], 'id="migrations-use-upgrade"') === true && str_contains($forcedOne['body'], 'id="migrations-use-upgrade"') === true
    && $ranCount() === 0 && ups_setting('portal.installed_version') === UPS_OLD,
    'a form SENT to it ("run all", and "run this one") gets the signpost too, and NOTHING was run or recorded',
    "HTTP {$forced['status']} and {$forcedOne['status']}; upgrades run: " . $ranCount()
);
brs_check(ups_isHoldingPage(ups_request('', 'GET', UPS_PAGE)) === true, 'the portal is still closed after that');

$before  = ups_counts();
$pressed = ups_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => $formToken]);
brs_check(
    $pressed['status'] === 200 && $ranCount() === 1 && ups_setting('portal.installed_version') === $filesVersion
    && str_contains($pressed['body'], 'id="upgrade-version-recorded"') === true,
    'THE BUTTON runs the waiting upgrade, records the version and says the upgrade is finished',
    'HTTP ' . $pressed['status'] . '; upgrades run: ' . $ranCount() . '; recorded: ' . ups_setting('portal.installed_version')
);
brs_check(count(glob($backups . '/upgrade-*/_manifest.json') ?: []) === 1, 'it took its snapshot first', implode(', ', glob($backups . '/*') ?: []));
brs_check(
    ups_setting('portal.maintenance.message') === '' && ups_setting('portal.maintenance.active') === '0',
    'and the words "Applying database migrations" were taken away when the run ended: they are not shown the next time the portal closes',
    'stored words: "' . ups_setting('portal.maintenance.message') . '"'
);
$lines = ups_recordedLines();
brs_check(
    ups_counts()['records'] - $before['records'] === 1 && count($lines) === 1 && str_contains((string) ($lines[0] ?? ''), 'version ' . $filesVersion . ', by the Upgrade page') === true,
    'RECORDED ONCE: the recording statement ran once, and one line in the activity log says the Upgrade page recorded it',
    'statement run: ' . (ups_counts()['records'] - $before['records']) . '; lines: ' . brs_say($lines)
);
$before   = ups_counts();
$stranger = ups_request('', 'GET', UPS_PAGE);
$member   = ups_request($jar['member'], 'GET', '/dashboard');
$health   = ups_request('', 'GET', '/health');
brs_check(ups_isHoldingPage($stranger) === false && $stranger['status'] === 302 && $member['status'] === 200, 'OPEN AGAIN for a stranger and a member', "HTTP {$stranger['status']} and {$member['status']}");
brs_check(ups_counts() === $before, 'and with the versions equal again nothing is asked any more (three requests, no question)', json_encode($before) . ' then ' . json_encode(ups_counts()));
$usual = ups_request($jar['root'], 'GET', '/admin/migrations');
brs_check(str_contains($usual['body'], 'id="migrations-use-upgrade"') === false && str_contains($usual['body'], UPS_MIGRATION) === true, 'CONTROL: with the versions equal again, Admin, Migrations is its ordinary self');
brs_check(str_contains(ups_healthLine($jar['root']), 'every request asks') === false, 'and the health page\'s Migrations line says nothing more about an upgrade');
$dash = ups_request($jar['root'], 'GET', '/admin');
brs_check(
    $dash['status'] === 200 && str_contains($dash['body'], 'Admin Dashboard') === true && str_contains($dash['body'], 'id="admin-upgrade-notice"') === false,
    'nor does the Admin dashboard: its notice is gone once the version is recorded',
    'HTTP ' . $dash['status']
);

echo "\nD. Files newer, nothing waiting: the button \"Finish the upgrade\"\n";
ups_setBehind();
$snapshots = count(glob($backups . '/upgrade-*', GLOB_ONLYDIR) ?: []);
$page      = ups_request($jar['root'], 'GET', '/admin/upgrade');
brs_check(str_contains($page['body'], 'id="upgrade-finish"') === true && ups_isHoldingPage(ups_request('', 'GET', UPS_PAGE)) === false, 'the portal is open and the Upgrade page offers the button');
unlink($backups . '/.htaccess');
$before  = ups_counts();
$pressed = ups_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => ups_token($page['body'])]);
$lines   = ups_recordedLines();
brs_check(
    $pressed['status'] === 200 && ups_setting('portal.installed_version') === $filesVersion
    && str_contains($pressed['body'], 'id="upgrade-version-recorded"') === true && str_contains($pressed['body'], 'id="upgrade-finish"') === false,
    'pressing it records the version and says the upgrade is finished',
    'HTTP ' . $pressed['status'] . '; recorded: ' . ups_setting('portal.installed_version')
);
brs_check(ups_counts()['records'] - $before['records'] === 1 && count($lines) === 1, 'once: the recording statement ran once, with one line in the activity log', 'statement run: ' . (ups_counts()['records'] - $before['records']) . '; lines: ' . count($lines));
brs_check(count(glob($backups . '/upgrade-*', GLOB_ONLYDIR) ?: []) === $snapshots, 'it took NO snapshot: nothing was about to change', implode(', ', glob($backups . '/*') ?: []));
brs_check(
    str_contains($pressed['body'], 'id="upgrade-secrets"') === true && is_file($backups . '/.htaccess') === true && str_contains($pressed['body'], 'id="upgrade-private-folders"') === true,
    'it still checked the stored secrets, and put back the "refuse everything" file that was missing, and says so'
);
$before = ups_counts();
ups_request('', 'GET', UPS_PAGE);
ups_request($jar['member'], 'GET', '/dashboard');
brs_check(ups_counts() === $before, 'and that stopped the asking: two more requests, no question');
// Pressed AGAIN, with nothing left to record (the form sent by hand: the page offers none now).
$again = ups_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => ups_token(ups_request($jar['root'], 'GET', '/admin/maintenance/backup')['body'])]);
brs_check(
    $again['status'] === 200 && ups_setting('portal.installed_version') === $filesVersion && count(ups_recordedLines()) === 1,
    'pressed again with nothing left to record: the version is as it was, and NO second line is written to the activity log',
    'HTTP ' . $again['status'] . '; lines: ' . count(ups_recordedLines())
);

// The same with the portal closed by its own switch, for some other reason.
ups_setBehind();
ups_set('portal.maintenance.active', '1');
$stranger = ups_request('', 'GET', UPS_PAGE);
$page     = ups_request($jar['root'], 'GET', '/admin/upgrade');
brs_check(ups_isHoldingPage($stranger) === true && str_contains($page['body'], 'id="upgrade-finish"') === true, 'with the portal closed by its own switch: closed, and the Upgrade page still offers "Finish the upgrade"', 'HTTP ' . $stranger['status']);
$pressed = ups_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => ups_token($page['body'])]);
brs_check(
    ups_setting('portal.installed_version') === $filesVersion && ups_setting('portal.maintenance.active') === '0' && ups_isHoldingPage(ups_request('', 'GET', UPS_PAGE)) === false,
    'pressing it records the version, switches the portal on again, and a stranger gets the portal',
    'recorded: ' . ups_setting('portal.installed_version') . '; switch: ' . ups_setting('portal.maintenance.active')
);

echo "\nE. The portal cannot tell whether an upgrade is waiting\n";
$otherToken = ups_token(ups_request($jar['root'], 'GET', '/admin/maintenance/backup')['body']);
ups_setBehind();
chmod($sqlDir, 0000);
clearstatcache();
$stranger = ups_request('', 'GET', UPS_PAGE);
$member   = ups_request($jar['member'], 'GET', '/dashboard');
$page     = ups_request($jar['root'], 'GET', '/admin/upgrade');
$line     = ups_healthLine($jar['root']);
$sent     = ups_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => $otherToken]);
chmod($sqlDir, 0700);
clearstatcache();
brs_check(ups_isHoldingPage($stranger) === true && ups_isHoldingPage($member) === true, 'with the _sql folder unreadable: closed for a stranger and a member', "HTTP {$stranger['status']} and {$member['status']}");
$box = ups_cannotTellBox($page['body']);
brs_check(
    $box['cause'] === 'folder' && str_contains($box['words'], 'The portal is closed to everybody else, because it could not tell whether a database upgrade is waiting') === true
    && str_contains($box['words'], '_sql, beside _core') === true && str_contains($box['words'], 'is missing or cannot be read') === true
    && str_contains($box['advice'], 'check that the folder _sql is beside _core') === true,
    'the Upgrade page says it could not tell, why, and what to do about the FOLDER',
    json_encode($box)
);
brs_check(str_contains($line, 'could not tell whether a database upgrade is waiting, so it is closed') === true, 'and so does the health page\'s Migrations line', $line);
brs_check(ups_offersAButton($page['body']) === false, 'the Upgrade page offers no button: there is nothing it could safely run');
brs_check(
    $sent['status'] === 302 && ups_setting('portal.installed_version') === UPS_OLD && ups_recordedLines() === [],
    'a form SENT to it all the same runs nothing: it is sent back, and the version is still the old one',
    'HTTP ' . $sent['status'] . '; recorded: ' . ups_setting('portal.installed_version')
);

Brs::$writer->query('RENAME TABLE tblMigrations TO tblZzUpsSetAside');
$stranger = ups_request('', 'GET', UPS_PAGE);
$recorded = ups_setting('portal.installed_version');
$page     = ups_request($jar['root'], 'GET', '/admin/upgrade');
Brs::$writer->query('DROP TABLE IF EXISTS tblMigrations');   // the Upgrade page makes an empty one
Brs::$writer->query('RENAME TABLE tblZzUpsSetAside TO tblMigrations');
brs_check(ups_isHoldingPage($stranger) === true && $recorded === UPS_OLD, 'with the table of applied upgrades missing: closed, and nothing recorded', 'HTTP ' . $stranger['status'] . '; recorded: ' . $recorded);
$box = ups_cannotTellBox($page['body']);
brs_check(
    $box['cause'] === 'table-missing' && str_contains($box['words'], '(the table tblMigrations) is missing') === true,
    'and the Upgrade page says that table is MISSING',
    json_encode($box)
);
brs_check(
    str_contains($box['advice'], 'The table was missing from the database') === true && str_contains($box['advice'], 'Press the button below') === true
    && str_contains($box['advice'], 'unpack the package again') === false && str_contains($box['advice'], '_sql') === false
    && preg_match('/Run \d+ Pending Migrations\s*</', $page['body']) === 1,
    'ITS ADVICE IS FOR THAT CAUSE: the files are fine, press the button below (which is there); it does not send the reader to the _sql folder',
    $box['advice']
);
$stranger = ups_request('', 'GET', UPS_PAGE);
brs_check(
    ups_isHoldingPage($stranger) === false && $stranger['status'] === 302 && ups_setting('portal.installed_version') === UPS_OLD,
    'CONTROL: with both back, the next request gets the portal, and the version is STILL not recorded',
    'HTTP ' . $stranger['status'] . '; recorded: ' . ups_setting('portal.installed_version')
);

// The sections below were added after the second check of the gate. Each one
// starts from "version behind" or says otherwise, and puts back what it makes.
$otherTokenFor = static fn (string $who, string $page = '/admin/maintenance/backup'): string => ups_token(ups_request($jar[$who], 'GET', $page)['body']);
$recordedAs    = static fn (string $name): int => (int) Brs::$writer->query("SELECT COUNT(*) FROM tblMigrations WHERE filename = '" . $name . "'")->fetch_row()[0];

echo "\nF. A database upgrade that fails half way\n";
ups_setBehind();
ups_set('portal.maintenance.active', '0');
$firstStatement = "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, '" . UPS_HALF . "', 'ran', '', 0) ON DUPLICATE KEY UPDATE settingValue = 'ran';\n";
file_put_contents($sqlDir . '/' . UPS_FAILS, "-- Written by tools/upgrade-pages-selftest.php for one run. Its second statement fails on purpose.\n" . $firstStatement . 'SELECT * FROM ' . UPS_NO_TABLE . ";\n");
$page    = ups_request($jar['root'], 'GET', '/admin/upgrade');
$before  = ups_counts();
$pressed = ups_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => ups_token($page['body'])]);
brs_check(
    $pressed['status'] === 200 && str_contains($pressed['body'], '<span class="badge bg-danger">Failed</span>') === true && str_contains($pressed['body'], UPS_NO_TABLE) === true,
    'the button is pressed: the page says the upgrade FAILED, and gives the database\'s reason',
    'HTTP ' . $pressed['status']
);
brs_check(
    ups_setting('portal.installed_version') === UPS_OLD && ups_counts()['records'] === $before['records'] && ups_recordedLines() === []
    && str_contains($pressed['body'], 'id="upgrade-version-recorded"') === false,
    'NOTHING IS RECORDED: the version is still the old one, the recording statement did not run, and the page does not say the upgrade is finished',
    'recorded: ' . ups_setting('portal.installed_version') . '; statement run: ' . (ups_counts()['records'] - $before['records'])
);
$stranger = ups_request('', 'GET', UPS_PAGE);
$member   = ups_request($jar['member'], 'GET', '/dashboard');
brs_check(ups_isHoldingPage($stranger) === true && ups_isHoldingPage($member) === true, 'THE PORTAL IS STILL CLOSED for a stranger and a member', "HTTP {$stranger['status']} and {$member['status']}");
brs_check(
    preg_match('/Run 1 Pending Migration\s*</', $pressed['body']) === 1 && ups_token($pressed['body']) !== '' && $recordedAs(UPS_FAILS) === 0,
    'the button is offered again, and the file is not counted as applied'
);
brs_check(
    ups_setting('portal.maintenance.message') === '' && str_contains($stranger['body'], 'Applying database migrations') === false,
    'the holding page no longer says "Applying database migrations — please wait": nothing is being applied',
    'stored words: "' . ups_setting('portal.maintenance.message') . '"'
);
brs_check(ups_setting(UPS_HALF) === 'ran', 'CONTROL: the file\'s first statement did run, so this really was a failure half way');
$pressed = ups_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => ups_token($pressed['body'])]);
brs_check(
    str_contains($pressed['body'], '<span class="badge bg-danger">Failed</span>') === true && ups_setting('portal.installed_version') === UPS_OLD
    && ups_isHoldingPage(ups_request('', 'GET', UPS_PAGE)) === true,
    'pressed again without mending it: failed again, still nothing recorded, still closed'
);
file_put_contents($sqlDir . '/' . UPS_FAILS, "-- Written by tools/upgrade-pages-selftest.php for one run. Mended.\n" . $firstStatement . "SELECT 1;\n");
$pressed = ups_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => ups_token($pressed['body'])]);
$stranger = ups_request('', 'GET', UPS_PAGE);
brs_check(
    str_contains($pressed['body'], 'id="upgrade-version-recorded"') === true && ups_setting('portal.installed_version') === $filesVersion && $recordedAs(UPS_FAILS) === 1
    && count(ups_recordedLines()) === 1 && ups_isHoldingPage($stranger) === false && ups_setting('portal.maintenance.active') === '0',
    'with the file mended, the button finishes it: recorded once, and the portal is open',
    'recorded: ' . ups_setting('portal.installed_version') . '; lines: ' . count(ups_recordedLines()) . '; HTTP ' . $stranger['status']
);
unlink($sqlDir . '/' . UPS_FAILS);
Brs::$writer->query("DELETE FROM tblMigrations WHERE filename = '" . UPS_FAILS . "'");
Brs::$writer->query("DELETE FROM tblSettings WHERE settingKey = '" . UPS_HALF . "'");

echo "\nG. A _sql folder that holds no numbered file\n";
ups_setBehind();
rename($sqlDir, $child . '/web/_sql-set-aside');
mkdir($sqlDir, 0700);
copy($child . '/web/_sql-set-aside/full_schema.sql', $sqlDir . '/full_schema.sql');   // the folder is there, and not empty
clearstatcache();
$stranger = ups_request('', 'GET', UPS_PAGE);
$member   = ups_request($jar['member'], 'GET', '/dashboard');
$page     = ups_request($jar['root'], 'GET', '/admin/upgrade');
unlink($sqlDir . '/full_schema.sql');
rmdir($sqlDir);
rename($child . '/web/_sql-set-aside', $sqlDir);
clearstatcache();
brs_check(ups_isHoldingPage($stranger) === true && ups_isHoldingPage($member) === true, 'CLOSED for a stranger and a member: an upload that has not finished is not "nothing waiting"', "HTTP {$stranger['status']} and {$member['status']}");
$box = ups_cannotTellBox($page['body']);
brs_check(
    $box['cause'] === 'folder-empty' && str_contains($box['words'], 'holds none, so the upload of the portal\'s files looks unfinished') === true
    && str_contains($box['advice'], 'unpack the package again') === true && ups_offersAButton($page['body']) === false,
    'the Upgrade page says the folder holds none, what to do, and offers no button',
    json_encode($box)
);
brs_check(ups_setting('portal.installed_version') === UPS_OLD, 'and nothing was recorded');

echo "\nH. A recorded name that no file has, beside a file that is waiting\n";
ups_setBehind();
Brs::$writer->query("INSERT INTO tblMigrations (filename) VALUES ('" . UPS_PHANTOM . "')");
file_put_contents($sqlDir . '/' . UPS_WAITS, "-- Written by tools/upgrade-pages-selftest.php for one run. Does nothing.\nSELECT 1;\n");
$files    = count(glob($sqlDir . '/[0-9][0-9][0-9]_*.sql') ?: []);
$recorded = (int) Brs::$writer->query('SELECT COUNT(*) FROM tblMigrations')->fetch_row()[0];
brs_check($files === $recorded, 'CONTROL: there are exactly as many numbered files as recorded names (so counting them would say "nothing waiting")', "files: {$files}; recorded: {$recorded}");
$stranger = ups_request('', 'GET', UPS_PAGE);
$page     = ups_request($jar['root'], 'GET', '/admin/upgrade');
brs_check(ups_isHoldingPage($stranger) === true, 'CLOSED: the waiting file is found by its NAME', 'HTTP ' . $stranger['status']);
brs_check(str_contains($page['body'], UPS_WAITS) === true && preg_match('/Run 1 Pending Migration\s*</', $page['body']) === 1, 'and the Upgrade page lists exactly that one file as waiting');
unlink($sqlDir . '/' . UPS_WAITS);
$stranger = ups_request('', 'GET', UPS_PAGE);
brs_check(ups_isHoldingPage($stranger) === false && $stranger['status'] === 302, 'CONTROL: with the file gone and only the extra name left, the portal is open (a name with no file is nothing to run)', 'HTTP ' . $stranger['status']);
Brs::$writer->query("DELETE FROM tblMigrations WHERE filename = '" . UPS_PHANTOM . "'");

echo "\nI. A recorded version AHEAD of the files', and one never recorded\n";
foreach (['99.0.0' => 'AHEAD of the files\' (an older package put back over a newer database)', '' => 'never recorded (an installation from before versions were recorded)'] as $value => $what) {
    ups_set('portal.installed_version', (string) $value);
    $before   = ups_counts();
    $stranger = ups_request('', 'GET', UPS_PAGE);
    $member   = ups_request($jar['member'], 'GET', '/dashboard');
    $after    = ups_counts();
    $page     = ups_request($jar['root'], 'GET', '/admin/upgrade');
    brs_check(
        $stranger['status'] === 302 && $member['status'] === 200 && $after === $before,
        'recorded version ' . $what . ': open, and NOTHING is asked',
        "HTTP {$stranger['status']} and {$member['status']}; " . json_encode($before) . ' then ' . json_encode($after)
    );
    brs_check(
        ups_offersAButton($page['body']) === false && str_contains(ups_healthLine($jar['root']), 'every request asks') === false
        && ups_setting('portal.installed_version') === (string) $value,
        'and the Upgrade page offers no button, the health page says nothing of an upgrade, and the recorded version is left as it is'
    );
}

echo "\nJ. The table of applied upgrades is there, and cannot be read\n";
ups_setBehind();
Brs::$writer->query('ALTER TABLE tblMigrations RENAME COLUMN filename TO filenameSetAside');
$stranger = ups_request('', 'GET', UPS_PAGE);
$page     = ups_request($jar['root'], 'GET', '/admin/upgrade');
$line     = ups_healthLine($jar['root']);
$sent     = ups_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => $otherTokenFor('root')]);
$box      = ups_cannotTellBox($page['body']);
brs_check(ups_isHoldingPage($stranger) === true, 'closed for a stranger', 'HTTP ' . $stranger['status']);
brs_check(
    $page['status'] === 200 && str_contains($page['body'], '<h1 class="h3 mb-4">Upgrade Portal</h1>') === true,
    'THE UPGRADE PAGE IS DRAWN (it used to be "Server Error", HTTP 500)',
    'HTTP ' . $page['status'] . ' ' . substr(ups_text($page['body']), 0, 160)
);
brs_check(
    $box['cause'] === 'table-unreadable' && str_contains($box['words'], '(the table tblMigrations) is there but could not be read. The database said:') === true
    && str_contains($box['words'], 'filename') === true,
    'it says the table is there but could not be read, with the database\'s own words',
    json_encode($box)
);
brs_check(
    str_contains($box['advice'], 'no button is offered here') === true && str_contains($box['advice'], 'still has full rights to its database') === true
    && str_contains($box['advice'], 'put the table tblMigrations back from a backup') === true && str_contains($box['advice'], 'unpack the package again') === false,
    'ITS ADVICE IS FOR THAT CAUSE: the database user\'s rights, then the table from a backup; not the _sql folder',
    $box['advice']
);
brs_check(ups_offersAButton($page['body']) === false, 'and it offers no button that could make things worse');
brs_check(str_contains($line, 'could not tell whether a database upgrade is waiting, so it is closed') === true, 'the health page, which sends the administrator there, says it could not tell', $line);
brs_check(
    $sent['status'] === 302 && ups_setting('portal.installed_version') === UPS_OLD && ups_recordedLines() === [],
    'a form SENT to the page all the same runs nothing and records nothing',
    'HTTP ' . $sent['status'] . '; recorded: ' . ups_setting('portal.installed_version')
);
// The same table, with the versions EQUAL: the gate has no reason to ask, and the page must still be drawn.
ups_set('portal.installed_version', $filesVersion);
$stranger = ups_request('', 'GET', UPS_PAGE);
$page     = ups_request($jar['root'], 'GET', '/admin/upgrade');
$box      = ups_cannotTellBox($page['body']);
Brs::$writer->query('ALTER TABLE tblMigrations RENAME COLUMN filenameSetAside TO filename');
brs_check(
    $stranger['status'] === 302 && $page['status'] === 200 && $box['cause'] === 'table-unreadable'
    && str_contains($box['words'], 'This page could not tell whether a database upgrade is waiting') === true
    && str_contains($box['words'], 'The portal is closed') === false && ups_offersAButton($page['body']) === false,
    'with the versions EQUAL the portal is open, and the page is still drawn: it says that IT could not tell, does not say the portal is closed, and offers no button',
    'HTTP ' . $stranger['status'] . ' and ' . $page['status'] . '; ' . json_encode($box)
);

echo "\nK. An upgrade file that records itself, and then fails\n";
ups_setBehind();
file_put_contents($sqlDir . '/' . UPS_SELF, "-- Written by tools/upgrade-pages-selftest.php for one run. It records itself, then fails on purpose.\n"
    . "INSERT INTO tblMigrations (filename) VALUES ('" . UPS_SELF . "') ON DUPLICATE KEY UPDATE filename = filename;\nSELECT * FROM " . UPS_NO_TABLE . ";\n");
$page    = ups_request($jar['root'], 'GET', '/admin/upgrade');
$pressed = ups_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => ups_token($page['body'])]);
$notice  = preg_match('/<p id="upgrade-notice">(.*?)<\/p>/s', $pressed['body'], $found) === 1 ? ups_text($found[1]) : '(no such sentence)';
brs_check(
    str_contains($pressed['body'], '<span class="badge bg-danger">Failed</span>') === true && $recordedAs(UPS_SELF) === 1 && ups_setting('portal.installed_version') === UPS_OLD,
    'CONTROL: it failed, nothing was recorded as the version, and the file did record itself (so nothing is listed as waiting any more)'
);
brs_check(
    str_contains($notice, 'No database upgrade is waiting') === true && str_contains($notice, 'One database upgrade is waiting') === false
    && str_contains($notice, 'so the portal is closed') === false,
    'the card under the results says what is true NOW (nothing is waiting), not the sentence from before the run (one is waiting, the portal is closed)',
    $notice
);
unlink($sqlDir . '/' . UPS_SELF);
Brs::$writer->query("DELETE FROM tblMigrations WHERE filename = '" . UPS_SELF . "'");
ups_set('portal.maintenance.active', '0');

echo "\nL. Admin, Migrations, and an administrator of one organisation\n";
ups_set('portal.installed_version', $filesVersion);   // the versions EQUAL: the page's ordinary state
file_put_contents($sqlDir . '/' . UPS_EQUAL, "-- Written by tools/upgrade-pages-selftest.php for one run. Does nothing.\nSELECT 1;\n");
$refusedBefore = ups_lines('MigrationsRefused', UPS_ADMIN);
$adminPage     = ups_request($jar['admin'], 'GET', '/admin');
$asAdmin       = ups_request($jar['admin'], 'GET', '/admin/migrations');
brs_check($adminPage['status'] === 200, 'CONTROL: the administrator of one organisation does get the Admin area', 'HTTP ' . $adminPage['status']);
brs_check(
    $asAdmin['status'] === 403 && str_contains($asAdmin['body'], 'id="migrations-global-only"') === true
    && str_contains(ups_text($asAdmin['body']), 'This page is for a global administrator only.') === true
    && str_contains($asAdmin['body'], 'name="run"') === false && str_contains($asAdmin['body'], UPS_EQUAL) === false,
    'but is REFUSED at Admin, Migrations, in words: 403, "for a global administrator only", nothing to run and no list of files',
    'HTTP ' . $asAdmin['status'] . ' ' . substr(ups_text($asAdmin['body']), 0, 200)
);
// Each form with a token of its own, and a good one: a form token works once,
// so a second form sent with the first one's token would be turned away for
// that, whatever this page did.
$token  = $otherTokenFor('admin', '/settings');
$forced = ups_request($jar['admin'], 'POST', '/admin/migrations', ['csrf_token' => $token, 'run' => 'single', 'filename' => UPS_EQUAL]);
$tokenAll  = $otherTokenFor('admin', '/settings');
$forcedAll = ups_request($jar['admin'], 'POST', '/admin/migrations', ['csrf_token' => $tokenAll, 'run' => 'all']);
brs_check(
    $token !== '' && $tokenAll !== '' && $forced['status'] === 403 && $forcedAll['status'] === 403
    && str_contains($forced['body'], 'id="migrations-global-only"') === true && str_contains($forcedAll['body'], 'id="migrations-global-only"') === true
    && $recordedAs(UPS_EQUAL) === 0,
    'a form SENT by them ("run this one", and "run all") is refused too, and the waiting file was NOT run',
    "HTTP {$forced['status']} and {$forcedAll['status']}; run: " . $recordedAs(UPS_EQUAL)
);
$asMember = ups_request($jar['member'], 'GET', '/admin/migrations');
brs_check($asMember['status'] === 403 && str_contains($asMember['body'], 'name="run"') === false, 'a member is refused as well', 'HTTP ' . $asMember['status']);
brs_check(
    ups_lines('MigrationsRefused', UPS_ADMIN) - $refusedBefore === 3,
    'and each of that administrator\'s three refusals is written to the activity log',
    'lines: ' . (ups_lines('MigrationsRefused', UPS_ADMIN) - $refusedBefore)
);
$asRoot = ups_request($jar['root'], 'GET', '/admin/migrations');
brs_check(
    $asRoot['status'] === 200 && str_contains($asRoot['body'], 'id="migrations-global-only"') === false && str_contains($asRoot['body'], UPS_EQUAL) === true
    && str_contains($asRoot['body'], 'name="run"') === true,
    'CONTROL: a global administrator still gets the page, with the waiting file and its Run button',
    'HTTP ' . $asRoot['status']
);
$ranByRoot = ups_request($jar['root'], 'POST', '/admin/migrations', ['csrf_token' => ups_token($asRoot['body']), 'run' => 'single', 'filename' => UPS_EQUAL]);
brs_check($ranByRoot['status'] === 200 && $recordedAs(UPS_EQUAL) === 1, 'and can run it there, as that page\'s own header says, with the versions equal', 'HTTP ' . $ranByRoot['status'] . '; run: ' . $recordedAs(UPS_EQUAL));
unlink($sqlDir . '/' . UPS_EQUAL);
Brs::$writer->query("DELETE FROM tblMigrations WHERE filename = '" . UPS_EQUAL . "'");

echo "\nM. One organisation's own row for the recorded version, and the Settings page\n";
ups_setBehind();
Brs::$writer->prepare("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (1, 'portal.installed_version', ?, '', 0)")->execute([$filesVersion]);
file_put_contents($sqlDir . '/' . UPS_WAITS, "-- Written by tools/upgrade-pages-selftest.php for one run. Does nothing.\nSELECT 1;\n");
$stranger = ups_request('', 'GET', UPS_PAGE);
$member   = ups_request($jar['member'], 'GET', '/dashboard');
brs_check(
    ups_isHoldingPage($stranger) === true && ups_isHoldingPage($member) === true,
    'the installation\'s row says the old version, ONE ORGANISATION\'S OWN ROW says the new one, and a file is waiting: CLOSED. The organisation\'s row does not count',
    "HTTP {$stranger['status']} and {$member['status']}"
);
unlink($sqlDir . '/' . UPS_WAITS);
$page = ups_request($jar['root'], 'GET', '/admin/upgrade');
brs_check(str_contains($page['body'], 'id="upgrade-finish"') === true, 'and with nothing waiting the Upgrade page still offers "Finish the upgrade": the version is behind by the installation\'s own row');
Brs::$writer->query("DELETE FROM tblSettings WHERE settingKey = 'portal.installed_version' AND siteID IS NOT NULL");

// The Settings page: change, add, delete. Each must be refused, for a global
// administrator too. EVERY FORM IS SENT WITH A TOKEN OF ITS OWN: a form token
// works once. On this section's first run one token was used for all four
// forms, the last three were turned away for an expired token, and "the row
// is unchanged" passed without proving anything. So a refusal is recognised
// here by the words the page then shows, and a control comes first.
$versionRows = static fn (): array => Brs::$writer->query("SELECT settingID, siteID, settingValue FROM tblSettings WHERE settingKey = 'portal.installed_version' ORDER BY settingID")->fetch_all(MYSQLI_ASSOC);
$rowsBefore  = $versionRows();
$rowId       = (int) ($rowsBefore[0]['settingID'] ?? 0);
$settings    = ups_request($jar['root'], 'GET', '/settings');
brs_check(
    count($rowsBefore) === 1 && $settings['status'] === 200 && str_contains($settings['body'], '>Recorded by the Upgrade page</a>') === true
    && preg_match('/data-key="portal\.installed_version"/', $settings['body']) !== 1,
    'the Settings page lists the setting with "Recorded by the Upgrade page" in place of its Edit and Delete buttons',
    'HTTP ' . $settings['status'] . '; rows: ' . count($rowsBefore)
);
/** One form sent to the Settings pages by the global administrator, with a fresh token; and what the page says afterwards. */
$sendToSettings = static function (string $path, array $fields) use ($jar): array {
    $token  = ups_token(ups_request($jar['root'], 'GET', '/settings')['body']);
    $answer = ups_request($jar['root'], 'POST', $path, ['csrf_token' => $token] + $fields);
    return ['status' => $token === '' ? 0 : $answer['status'], 'said' => ups_text(ups_request($jar['root'], 'GET', '/settings')['body'])];
};
$refusedInWords = static fn (array $answer): bool => $answer['status'] === 302
    && str_contains($answer['said'], 'It cannot be added, changed or deleted here.') === true
    && str_contains($answer['said'], 'only the button on the Upgrade page does') === true;

$controlRow = static fn (): ?array => Brs::$writer->query("SELECT settingID, settingValue FROM tblSettings WHERE settingKey = '" . UPS_CONTROL . "'")->fetch_assoc() ?: null;
$sendToSettings('/settings/save', ['settingID' => '0', 'settingKey' => UPS_CONTROL, 'settingValue' => 'one']);
$made = $controlRow();
$sendToSettings('/settings/save', ['settingID' => (string) ($made['settingID'] ?? 0), 'settingKey' => UPS_CONTROL, 'settingValue' => 'two']);
$changedTo = $controlRow()['settingValue'] ?? null;
$sendToSettings('/settings', ['action' => 'delete', 'settingID' => (string) ($made['settingID'] ?? 0)]);
brs_check(
    ($made['settingValue'] ?? null) === 'one' && $changedTo === 'two' && $controlRow() === null,
    'CONTROL: sent in exactly this way, an ordinary setting IS added, changed and deleted',
    'added as: ' . brs_say($made['settingValue'] ?? null) . '; changed to: ' . brs_say($changedTo) . '; still there: ' . ($controlRow() === null ? 'no' : 'yes')
);

$updateRefused = ups_lines('SettingsUpdateRefused', UPS_ROOT);
$deleteRefused = ups_lines('SettingsDeleteRefused', UPS_ROOT);
$changed = $sendToSettings('/settings/save', ['settingID' => (string) $rowId, 'settingKey' => 'portal.installed_version', 'settingValue' => '99.0.0']);
brs_check(
    $refusedInWords($changed) === true && $versionRows() === $rowsBefore,
    'CHANGING it there is refused, for a global administrator too: the row is as it was, and the page says why and points to the Upgrade page',
    'HTTP ' . $changed['status'] . '; rows: ' . json_encode($versionRows())
);
$added         = $sendToSettings('/settings/save', ['settingID' => '0', 'settingKey' => 'portal.installed_version', 'settingValue' => '99.0.0']);
$addedCapitals = $sendToSettings('/settings/save', ['settingID' => '0', 'settingKey' => 'Portal.Installed_Version', 'settingValue' => '99.0.0']);
brs_check(
    $refusedInWords($added) === true && $refusedInWords($addedCapitals) === true && $versionRows() === $rowsBefore,
    'ADDING it (as an organisation\'s own row; and written with capitals) is refused in the same words: there is still one row, unchanged',
    "HTTP {$added['status']} and {$addedCapitals['status']}; rows: " . json_encode($versionRows())
);
$deleted = $sendToSettings('/settings', ['action' => 'delete', 'settingID' => (string) $rowId]);
brs_check(
    $refusedInWords($deleted) === true && $versionRows() === $rowsBefore,
    'DELETING it is refused in the same words: the row is still there',
    'HTTP ' . $deleted['status'] . '; rows: ' . json_encode($versionRows())
);
brs_check(
    ups_lines('SettingsUpdateRefused', UPS_ROOT) - $updateRefused === 3 && ups_lines('SettingsDeleteRefused', UPS_ROOT) - $deleteRefused === 1,
    'and the four refusals are written to the activity log',
    'update refusals: ' . (ups_lines('SettingsUpdateRefused', UPS_ROOT) - $updateRefused) . '; delete refusals: ' . (ups_lines('SettingsDeleteRefused', UPS_ROOT) - $deleteRefused)
);

$errors = trim((string) @file_get_contents($child . '/php-errors.log'));
brs_check($errors === '', 'PHP\'s own error log is empty after all of that', substr($errors, 0, 600));

// =============================================================================
$failed = Brs::$proofOk === false;
ups_cleanup();
echo "\n" . str_repeat('=', 78) . "\n";
printf("%d check(s) made. %s\n", Brs::$checks, $failed === true ? 'AT LEAST ONE FAILED.' : 'All passed.');
echo "The test's rows, accounts and scratch folder were removed, and the server's log setting was put back.\n";
echo "REACHED THE END\n";
exit($failed === true ? 1 : 0);
