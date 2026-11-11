<?php
// Path: tools/backup-page-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The backup pages, on the REAL pages — self-test 📦🚪 (#577, #578, #579)
 * -----------------------------------------------------------------------------
 * `tools/backup-restore-selftest.php` calls the backup class directly. It
 * cannot see a button that posts to the wrong place, a page that forgets its
 * form token, or an upgrade page that still refuses to run. This file runs
 * the portal and presses the buttons.
 *
 * WHAT IT DOES
 * It copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file, starts PHP's built-in web server on it behind a small
 * router that imitates `.htaccess`, signs three made-up accounts in through
 * the real sign-in page (a global administrator, an administrator of one
 * organisation, a member), and sends real requests. Then it checks:
 *
 *   A. "Run snapshot now" takes a finished snapshot.
 *   B. "Check this snapshot" (the rehearsal): a good snapshot is answered
 *      "can be restored" and the database is identical afterwards; a snapshot
 *      holding a row the database must refuse is answered "cannot be
 *      restored", with the reason, and the database is identical afterwards;
 *      each press leaves one line in the activity log; and the portal is
 *      open again afterwards.
 *   C. Who may press it: an organisation's administrator and a member are
 *      refused, and a press with no form token or a wrong one does nothing.
 *   D. "Restore ALL tables" puts a damaged database back and logs it.
 *   D2. A portal an administrator had ALREADY closed is still closed, with
 *      their own message, after a check, a whole restore and a one-table
 *      restore (a whole restore puts the settings table back, so the page has
 *      to close the portal again afterwards).
 *   E. A folder left by a failed snapshot is labelled unfinished on the
 *      backup page and the freshness page, and both the freshness page and
 *      the health page say a snapshot failed instead of calling it fresh.
 *   F. The upgrade page. A press with a wrong form token, and a press when
 *      the snapshot cannot be taken, each SHOW their message (it used to be
 *      stored and never shown), run no migration and leave the portal open.
 *      Then, with an imported calendar event in the database and one
 *      migration waiting: it takes its snapshot and runs the migration.
 *      (Before #577 was fixed the snapshot failed and the migration was not
 *      run.)
 *   G. Names sent from a browser: seventeen odd names (dots, paths, links, a
 *      zero byte, a line break, a list) are refused by every action that
 *      takes a name, with nothing deleted, nothing changed in the database
 *      and the portal left open; Delete removes a link as a link; and
 *      Inspect refuses a link that leads out of the backups folder, in words.
 *   H. The one case where the backup class has to CLOSE the database
 *      connection (the database does not confirm that a rehearsal was
 *      undone): the page shows the class's words on a bare page, says the
 *      portal is still closed and how to reopen it, and the database is
 *      unchanged. The database cannot be made to do this on demand, so the
 *      fault is planted in this run's SCRATCH copy of the class, for that one
 *      press, and taken out again.
 *   H2. The same for a connection whose own settings cannot be put back
 *      after a check, and after a restore that WORKED: the bare page says what
 *      was done as well as why the connection was closed, and after the
 *      restore it says the portal's open-or-closed setting is now as the
 *      snapshot held it.
 *   I. A value that is not on its column's list of choices (stored only by a
 *      database set to accept anything; a restore refuses it). The upgrade
 *      page STOPS before migrating and says which table and column, how many
 *      and what to do, and offers a second button to go on deliberately; the
 *      backup page (its list, the snapshot's own page, "Run snapshot now")
 *      and the health page say the same. Pressing the second button runs the
 *      migration, says it ran without a way back and records that. With the
 *      rows mended, nothing is said any more.
 *   J. The tables a ONE-table restore refuses by name (they only make
 *      sense put back beside the rest of the database): the snapshot's page
 *      still lists them but offers no Restore button for them, says above the
 *      list which button does put them back, and a request posted past the
 *      missing button is refused in words WITHOUT closing the portal.
 *   O. Off-site backup: the page says which of three things is true of the
 *      script sitting on this server — the settings are in force, a script is
 *      there that ignores them, or there is no script to obey them — judged
 *      against the REAL shipped sync-offsite.sh.example as well as a stand-in,
 *      and it catches a script whose call to the reader has been COMMENTED
 *      OUT, which a plain text search for either name passes as obedient. Then
 *      the four other ways this page can mislead: a missing RECORDER (copies
 *      going out with nothing able to write them down), a run's own WARNING
 *      lines in both places they are shown, a stored value the portal will not
 *      use, a stored "copies to keep" figure outside the boxes' own range, and
 *      "Run now" answering green for a run that sent nothing (#671).
 *
 * "IDENTICAL AFTERWARDS" IS CHECKED BY AN INDEPENDENT READER. The comparison
 * is `brs_fingerprint()` from tools/backup-restore-selftest.php: every value
 * of every table, read over its own connection, sharing no code with the
 * class. A page legitimately writes two things of its own on every press: its
 * line in the activity log, and the message it shows while the portal is
 * closed. `bps_settle()` checks those are the ONLY new activity lines, takes
 * them out and blanks the message, so that what is compared is everything
 * else.
 *
 * SAFETY
 * - It refuses unless the database's name starts with `selftest_`. The rows it
 *   makes (three accounts numbered 918001 to 918003, and the rows of the other
 *   self-test) are removed at the start and at the end, the two settings
 *   and one migration record the upgrade page writes are put back, and the
 *   error-table rows the portal records during the run are removed.
 * - Do NOT run it at the same moment as another page-driving self-test on the
 *   same database.
 * - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *   temporary folder), deleted at the end only if it still holds this run's
 *   marker. `web/_auth_keys` is never read or copied.
 * - The server is started with its command as a list (no shell), so the
 *   process number is the server's own, and it is stopped by that number. It
 *   refuses to start if anything already answers on its port. Every answer
 *   must carry this run's header.
 *
 * WHAT THIS CANNOT PROVE
 * - Apache: the router only imitates `.htaccess`.
 * - How a page looks in a browser, or that the confirmation box appears: it
 *   is drawn by JavaScript, which nothing here runs. The words of the box are
 *   checked in the page's HTML.
 * - What happens when a HOST ends a long request. That cannot be imitated
 *   here; the class-level test kills a restore half way instead.
 * - MariaDB.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_USER=… \
 *         SELFTEST_DB_PASS=… SELFTEST_WEB_PORT=9480 php tools/backup-page-selftest.php
 * Exit:   0 only if every check passed and the run reached its end (it prints
 *         "REACHED THE END" last); 1 if a check failed; 2 if it could not run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.3.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/578
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// Loaded for its functions (the independent reader, the made-up rows, the
// planted bad row). It returns before its own run when it is not the script
// being run.
require __DIR__ . '/backup-restore-selftest.php';

// 📦 `BackupArchive` is loaded explicitly, the same way
//    `backup-restore-selftest.php` loads `DbBackup`: this file runs OUTSIDE the
//    portal, so nothing autoloads a portal class for it. Without this, the
//    sections below die with "Class not found" — which is what happened on the
//    first run, and is the same shape as the project's own rule that a class
//    used by its short name needs its `use` line in that file.
require __DIR__ . '/../web/_core/BackupArchive.php';

const BPS_ROOT   = 918001;   // a global administrator
const BPS_ADMIN  = 918002;   // an administrator of one organisation only
const BPS_MEMBER = 918003;
const BPS_MIGRATION = '999_backup_page_selftest.sql';
/** The activity-log lines the backup pages write themselves. */
const BPS_OWN_LINES = ['BackupSnapshotChecked', 'BackupRestored', 'BackupRestoreFailed', 'BackupTableRestored', 'BackupActionRefused',
    // The FOUR #694 actions. bps_settle() REFUSES a line that is not on this
    // list, so a new action the page records has to be added here on purpose.
    'BackupHeldRowsPutBack', 'BackupHeldRowsPutBackFailed', 'BackupHeldRowsDiscarded', 'BackupHeldRowsDiscardFailed'];

$bps = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'port' => 0, 'logMark' => 0, 'errMark' => null, 'put_back' => [], 'cleaned' => false];

function bps_refuse(string $why): never
{
    echo 'REFUSED. ' . $why . "\nNothing was run, and nothing is proved.\n";
    exit(2);
}

/** Remove this test's own accounts and put back what the upgrade page wrote. Safe to run twice. */
function bps_tidyDatabase(): void
{
    global $bps;
    $w = Brs::$writer;
    $w->query('SET SESSION foreign_key_checks = 0');
    foreach (['tblLocalAccounts', 'tblUserSites', 'tblUsers'] as $table) {
        $w->query('DELETE FROM `' . $table . '` WHERE userID BETWEEN ' . BPS_ROOT . ' AND ' . BPS_MEMBER);
    }
    $w->query('DELETE FROM tblActivityLogs WHERE userID BETWEEN ' . BPS_ROOT . ' AND ' . BPS_MEMBER);
    $w->query("DELETE FROM tblMigrations WHERE filename = '" . BPS_MIGRATION . "'");
    // Section F makes a snapshot fail on purpose (a folder that cannot be
    // written to). PHP warns, and the portal records the warning in its error
    // table, as it should. That row is this run's own, so it goes too. (The
    // first version left it behind, and the next test to use the database
    // found a table that differed before it had done anything.) Rows that
    // were there before the run are left alone.
    if ($bps['errMark'] !== null) {
        $w->query('DELETE FROM tblErrors WHERE errorID > ' . (int) $bps['errMark']);
    }
    $w->query('SET SESSION foreign_key_checks = 1');
    foreach ($bps['put_back'] as $key => $value) {
        $statement = $w->prepare('UPDATE tblSettings SET settingValue = ? WHERE settingKey = ? AND siteID IS NULL');
        $statement->execute([$value, $key]);
    }
    brs_tidy();
}

/** Stop the server, delete the scratch folder, tidy the database. Also runs if the script dies. */
function bps_cleanup(): void
{
    global $bps;
    if ($bps['cleaned'] === true) {
        return;
    }
    $bps['cleaned'] = true;
    if (is_resource($bps['proc']) === true) {
        proc_terminate($bps['proc']);   // by its own process number
        proc_close($bps['proc']);
    }
    try {
        bps_tidyDatabase();
    } catch (Throwable $e) {
        echo 'The database could not be tidied: ' . $e->getMessage() . "\n";
    }
    // Only a folder that still holds this run's marker.
    if ($bps['child'] !== '' && @file_get_contents($bps['child'] . '/.backup-page-selftest-owner') === $bps['token']) {
        brs_removeFolder($bps['child']);
    }
}

function bps_portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);
        return true;
    }
    return false;
}

/** Send one request as one signed-in person (the cookie jar says who). */
function bps_request(string $jar, string $method, string $path, ?array $post = null): array
{
    global $bps;
    $handle = curl_init('http://127.0.0.1:' . $bps['port'] . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 900,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:'],
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Backup-Page-Selftest:\s*' . $bps['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start'];
    }
    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $found) === 1 ? $found[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
}

function bps_token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $found) === 1 ? $found[1] : '';
}

function bps_text(string $html): string
{
    return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
}

/** The message a page shows in its coloured box: "[colour] words". */
function bps_message(string $html): string
{
    return preg_match('/<div class="alert alert-(\w+)">(.*?)<\/div>/s', $html, $found) === 1
        ? '[' . $found[1] . '] ' . trim(bps_text($found[2])) : '(no message shown)';
}

/** Press a button on the backup page, with a fresh form token, and return the answer. */
function bps_press(string $jar, array $fields, ?string $tokenInstead = null): array
{
    $page  = bps_request($jar, 'GET', '/admin/maintenance/backup');
    $token = $tokenInstead ?? bps_token($page['body']);
    return bps_request($jar, 'POST', '/admin/maintenance/backup', array_merge(['csrf_token' => $token], $fields));
}

/** The types of the activity-log lines written since the mark, oldest first. */
function bps_newLines(): array
{
    global $bps;
    return array_column(Brs::$writer->query(
        'SELECT activityType, activityDescription FROM tblActivityLogs WHERE logID > ' . (int) $bps['logMark'] . ' ORDER BY logID'
    )->fetch_all(), 1, 0);
}

/**
 * Take out what a backup page legitimately writes for itself, so that
 * everything ELSE can be compared: its own activity-log lines (checked to be
 * the only new ones) and the message shown while the portal is closed.
 *
 * @return bool False if a new activity line was NOT one of the page's own.
 */
function bps_settle(): bool
{
    global $bps;
    $onlyOwn = array_diff(array_keys(bps_newLines()), BPS_OWN_LINES) === [];
    Brs::$writer->query('DELETE FROM tblActivityLogs WHERE logID > ' . (int) $bps['logMark']);
    Brs::$writer->query("UPDATE tblSettings SET settingValue = '' WHERE settingKey = 'portal.maintenance.message' AND siteID IS NULL");
    return $onlyOwn;
}

/** Every message a page shows in a coloured box, as "[colour] words". */
function bps_messages(string $html): array
{
    preg_match_all('/<div class="alert alert-(\w+)">(.*?)<\/div>/s', $html, $found, PREG_SET_ORDER);
    return array_map(static fn (array $one): string => '[' . $one[1] . '] ' . trim(bps_text($one[2])), $found);
}

/** One portal-wide setting, read straight from the database. */
function bps_setting(string $key): string
{
    $statement = Brs::$writer->prepare('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL');
    $statement->execute([$key]);
    return (string) ($statement->get_result()->fetch_row()[0] ?? '');
}

/** Close or open the portal the way an administrator's own setting does, with a message. */
function bps_setPortal(bool $closed, string $message): void
{
    foreach (['portal.maintenance.active' => $closed === true ? '1' : '0', 'portal.maintenance.message' => $message] as $key => $value) {
        Brs::$writer->prepare('UPDATE tblSettings SET settingValue = ? WHERE settingKey = ? AND siteID IS NULL')->execute([$value, $key]);
    }
}

function bps_portalIsOpen(): bool
{
    return (string) (Brs::$writer->query("SELECT settingValue FROM tblSettings WHERE settingKey = 'portal.maintenance.active' AND siteID IS NULL")->fetch_row()[0] ?? '0') === '0';
}

// =============================================================================
// 🚦 Setting up
// =============================================================================

if (str_starts_with(brs_env('SELFTEST_DB_NAME', ''), 'selftest_') === false) {
    bps_refuse("Set SELFTEST_DB_NAME to a throwaway database whose name starts with selftest_ (got '" . brs_env('SELFTEST_DB_NAME', '') . "').");
}
$bps['port'] = (int) brs_env('SELFTEST_WEB_PORT', '0');
if ($bps['port'] < 1024) {
    bps_refuse('Set SELFTEST_WEB_PORT to a free port for the test web server.');
}
if (bps_portAnswers($bps['port']) === true) {
    bps_refuse('Something already answers on port ' . $bps['port'] . '.');
}
if (function_exists('curl_init') === false) {
    bps_refuse('PHP\'s curl extension is needed to send the requests.');
}
try {
    Brs::$writer = brs_connect();
    Brs::$reader = brs_connect('+00:00');
} catch (Throwable $e) {
    bps_refuse('Could not connect to the test database: ' . $e->getMessage());
}

$webSource = (string) realpath(__DIR__ . '/../web');
$parent    = (string) realpath(brs_env('SELFTEST_WEB_DIR', sys_get_temp_dir()));
if ($parent === '' || str_starts_with($parent . '/', dirname($webSource) . '/') === true || str_starts_with($webSource . '/', $parent . '/') === true) {
    bps_refuse('The scratch folder must be outside the repository.');
}

register_shutdown_function('bps_cleanup');
foreach (['portal.installed_version', 'portal.maintenance.active', 'portal.maintenance.message'] as $key) {
    $row = Brs::$writer->query("SELECT settingValue FROM tblSettings WHERE settingKey = '" . $key . "' AND siteID IS NULL")->fetch_row();
    if ($row !== null) {
        $bps['put_back'][$key] = (string) $row[0];
    }
}
bps_tidyDatabase();
$bps['errMark'] = (int) Brs::$writer->query('SELECT COALESCE(MAX(errorID), 0) FROM tblErrors')->fetch_row()[0];
Brs::$sessionLinkAtStart = brs_sessionLink();

// 👥 Three accounts, signed in with a password made for this run.
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
$people   = [BPS_ROOT => ['root', 'Rae Root', 1, 0], BPS_ADMIN => ['admin', 'Ada Admin', 0, 1], BPS_MEMBER => ['member', 'Mel Member', 0, 0]];
foreach ($people as $id => [$who, $name, $isRoot, $isSiteAdmin]) {
    Brs::$writer->prepare('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, ?)')
        ->execute([$id, $name, 'u' . $id . '.backup@selftest.invalid', $isRoot]);
    Brs::$writer->prepare('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, ?, 0)')
        ->execute([$id, $isSiteAdmin]);
    Brs::$writer->prepare('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)')
        ->execute([$id, 'selftest-backup-' . $who, $hash]);
}

// 📁 The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . '/backup-page-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    bps_refuse("Could not make a NEW scratch folder '{$child}'.");
}
$bps['child'] = $child;
Brs::$root    = $child;   // the only folder brs_removeFolder() will touch
file_put_contents($child . '/.backup-page-selftest-owner', $bps['token']);   // the marker FIRST
mkdir($child . '/web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSource, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $path => $info) {
    $relative = substr((string) $path, strlen($webSource) + 1);
    if (in_array(explode('/', $relative)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        bps_refuse('web/ holds a link (' . $relative . '); refusing to copy it.');
    }
    if ($info->isDir() === true) {
        mkdir($child . '/web/' . $relative, 0700);
    } else {
        copy((string) $path, $child . '/web/' . $relative);
        $copied++;
    }
}
if ($copied < 100) {
    bps_refuse("The copy of web/ holds only {$copied} files.");
}
mkdir($child . '/web/_auth_keys', 0700);
mkdir($child . '/web/_backups', 0750);
mkdir($child . '/sessions', 0700);
$backups = $child . '/web/_backups';
file_put_contents($child . '/web/_auth_keys/auth_creds.php', "<?php\n// Written by tools/backup-page-selftest.php for one run; deleted with its folder.\nreturn " . var_export([
    'db_host' => brs_env('SELFTEST_DB_HOST', '127.0.0.1'), 'db_user' => brs_env('SELFTEST_DB_USER', 'root'),
    'db_pass' => brs_env('SELFTEST_DB_PASS', ''), 'db_name' => brs_env('SELFTEST_DB_NAME', ''), 'db_port' => (int) brs_env('SELFTEST_DB_PORT', '3306'),
], true) . ";\n");
file_put_contents($child . '/web/_auth_keys/enc.key', bin2hex(random_bytes(32)));
// One made-up migration that does nothing, so the upgrade page has something waiting (section F).
file_put_contents($child . '/web/_sql/' . BPS_MIGRATION, "-- Written by tools/backup-page-selftest.php for one run. Does nothing.\nSELECT 1;\n");

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/backup-page-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Backup-Page-Selftest: __TOKEN__');
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
file_put_contents($child . '/router.php', str_replace('__TOKEN__', $bps['token'], $router));

// The server: its command as a LIST (no shell in between) and ONE process, so
// nothing can outlive it.
$environment = getenv();
unset($environment['PHP_CLI_SERVER_WORKERS']);
$environment['PORTAL_ENV'] = 'prod';
$bps['proc'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . '/sessions', '-d', 'error_log=' . $child . '/php-errors.log', '-d', 'log_errors=1',
        // No opcache: section H edits the scratch copy of one class for one
        // press, and a cached copy would be served for a couple of seconds.
        '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $bps['port'], '-t', $child . '/web/public_html', $child . '/router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . '/server-output.log', 'a'], 2 => ['file', $child . '/server-output.log', 'a']],
    $pipes,
    $child,
    $environment
);
if (is_resource($bps['proc']) === false) {
    bps_refuse('The web server could not be started.');
}
brs_waitFor(static fn (): bool => bps_portAnswers($bps['port']), 15);
if (bps_portAnswers($bps['port']) === false) {
    bps_refuse('The web server never answered on port ' . $bps['port'] . '.');
}

echo "The backup pages, on the real pages — self-test\n";
echo str_repeat('=', 78) . "\n";
printf("Server process %d, port %d, PHP %s, database server %s.\n", (int) proc_get_status($bps['proc'])['pid'], $bps['port'], PHP_VERSION, Brs::$writer->server_info);

$jar = [];
foreach ($people as [$who]) {
    $jar[$who] = $child . '/jar-' . $who . '.txt';
    $token     = bps_token(bps_request($jar[$who], 'GET', '/login')['body']);
    $in        = bps_request($jar[$who], 'POST', '/login', ['csrf_token' => $token, 'username' => 'selftest-backup-' . $who, 'password' => $password]);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        bps_refuse("Signing in as selftest-backup-{$who} did not work (HTTP {$in['status']} {$in['location']}).");
    }
}
// Open the page once as each person before anything is measured, so first-visit
// housekeeping (if any) is behind us.
bps_request($jar['root'], 'GET', '/admin/maintenance/backup');

brs_addSelfPointingRows();
$bps['logMark'] = (int) Brs::$writer->query('SELECT COALESCE(MAX(logID), 0) FROM tblActivityLogs')->fetch_row()[0];

// =============================================================================
// 🧪 The checks
// =============================================================================

echo "\nA. Run snapshot now\n";
$answer    = bps_press($jar['root'], ['action' => 'snapshot_now']);
$snapshots = array_map('basename', glob($backups . '/upgrade-*', GLOB_ONLYDIR) ?: []);
brs_check(str_starts_with(bps_message($answer['body']), '[success] Snapshot created:'), 'the page says a snapshot was created', bps_message($answer['body']));
brs_check(count($snapshots) === 1 && is_file($backups . '/' . ($snapshots[0] ?? '') . '/_manifest.json') === true, 'and one finished snapshot folder exists', implode(', ', $snapshots));
$good = (string) ($snapshots[0] ?? '');
bps_settle();
$before = brs_fingerprint();

echo "\nB. Check this snapshot (the rehearsal)\n";
$inspect = bps_request($jar['root'], 'GET', '/admin/maintenance/backup?inspect=' . $good)['body'];
brs_check(
    str_contains($inspect, 'name="action" value="check_snapshot"') === true && str_contains(bps_text($inspect), 'Check this snapshot') === true,
    'the snapshot\'s page offers a "Check this snapshot" button'
);
brs_check(
    preg_match('/data-confirm="([^"]*takes as long as a real restore[^"]*portal is closed[^"]*)"/', $inspect) === 1,
    'its confirmation box says it takes as long as a restore and that the portal is closed while it runs'
);
brs_check(preg_match('/TRUNCATE/i', $inspect) !== 1, 'no confirmation box on the page still says TRUNCATE');

brs_damageSelfPointingRows();
$damaged = brs_fingerprint();
$answer  = bps_press($jar['root'], ['action' => 'check_snapshot', 'name' => $good]);
$said    = bps_message($answer['body']);
brs_check(
    str_starts_with($said, '[success] This snapshot can be restored.') === true && str_contains($said, 'Nothing was changed.') === true,
    'a good snapshot is answered "This snapshot can be restored ... Nothing was changed."',
    $said
);
$lines = bps_newLines();
brs_check(
    array_keys($lines) === ['BackupSnapshotChecked'] && str_contains((string) reset($lines), 'it can be restored') === true,
    'one line in the activity log says who checked it and that it can be restored',
    brs_say($lines)
);
brs_check(bps_portalIsOpen() === true, 'the portal is open again afterwards');
brs_check(bps_settle() === true, 'the page wrote nothing else of its own');
brs_checkSame($damaged, 'THE DATABASE IS IDENTICAL after the check: it is still exactly in its damaged state');

$bad = 'upgrade-20200101-000000';
brs_plantBadRow($backups . '/' . $good, $backups . '/' . $bad);
$answer = bps_press($jar['root'], ['action' => 'check_snapshot', 'name' => $bad]);
$said   = bps_message($answer['body']);
brs_check(
    str_starts_with($said, '[danger] This snapshot cannot be restored.') === true
    && str_contains($said, 'tblVenueAgreements') === true && str_contains($said, BRS_NO_SUCH_VENUE) === true
    && str_contains($said, 'Nothing in the database has changed') === true,
    'a snapshot holding a row the database must refuse is answered "cannot be restored", with the reason, and that nothing changed',
    $said
);
$lines = bps_newLines();
brs_check(
    array_keys($lines) === ['BackupSnapshotChecked'] && str_contains((string) reset($lines), 'it cannot be restored') === true,
    'and the activity log says it cannot be restored',
    brs_say($lines)
);
brs_check(bps_portalIsOpen() === true, 'the portal is open again afterwards');
bps_settle();
brs_checkSame($damaged, 'THE DATABASE IS IDENTICAL after the refused check');

echo "\nC. Who may press it\n";
foreach (['admin' => 'an administrator of one organisation', 'member' => 'a member'] as $who => $words) {
    $page   = bps_request($jar[$who], 'GET', '/admin/maintenance/backup');
    $answer = bps_request($jar[$who], 'POST', '/admin/maintenance/backup', ['csrf_token' => bps_token($page['body']), 'action' => 'check_snapshot', 'name' => $good]);
    brs_check($answer['status'] === 403, $words . ' pressing it is refused (HTTP 403)', 'HTTP ' . $answer['status'] . ' ' . substr(bps_text($answer['body']), 0, 200));
    brs_check(isset(bps_newLines()['BackupSnapshotChecked']) === false, 'and no check was run for them', brs_say(bps_newLines()));
}
foreach (['no form token' => '', 'a wrong form token' => str_repeat('0', 64)] as $words => $token) {
    $answer = bps_press($jar['root'], ['action' => 'check_snapshot', 'name' => $good], $token);
    brs_check(
        str_contains(bps_message($answer['body']), 'Invalid CSRF token') === true && isset(bps_newLines()['BackupSnapshotChecked']) === false,
        'a global administrator\'s press with ' . $words . ' does nothing',
        bps_message($answer['body']) . ' ' . brs_say(bps_newLines())
    );
}
bps_settle();
brs_checkSame($damaged, 'the database is identical after all four refusals');

echo "\nD. Restore ALL tables\n";
$answer = bps_press($jar['root'], ['action' => 'restore_full', 'name' => $good]);
$said   = bps_message($answer['body']);
brs_check(str_starts_with($said, '[success] Snapshot put back in full:') === true, 'the page says the snapshot was put back in full', $said);
$lines = bps_newLines();
brs_check(isset($lines['BackupRestored']) === true, 'the restore left its line in the activity log, written after the restore so that it survives it', brs_say($lines));
brs_check(bps_portalIsOpen() === true, 'the portal is open again afterwards');
bps_settle();
brs_checkSame($before, 'every value of every table is as it was when the snapshot was taken');

echo "\nD2. A portal an administrator had already closed\n";
$theirs  = 'Closed by an administrator before the self-test pressed anything.';
$presses = [
    'a check'             => [['action' => 'check_snapshot', 'name' => $good], '[success] This snapshot can be restored.'],
    'a whole restore'     => [['action' => 'restore_full', 'name' => $good], '[success] Snapshot put back in full:'],
    'a one-table restore' => [['action' => 'restore_table', 'name' => $good, 'table' => 'tblTasks'], '[success] Restored tblTasks'],
];
foreach ($presses as $what => [$fields, $wanted]) {
    bps_setPortal(true, $theirs);
    $said = bps_message(bps_press($jar['root'], $fields)['body']);
    brs_check(str_starts_with($said, $wanted) === true, $what . ' runs while the portal is closed', $said);
    brs_check(
        bps_portalIsOpen() === false && bps_setting('portal.maintenance.message') === $theirs,
        'and afterwards the portal is STILL closed, with the administrator\'s own message unchanged',
        'active=' . bps_setting('portal.maintenance.active') . ' message="' . bps_setting('portal.maintenance.message') . '"'
    );
}
bps_setPortal(false, '');
bps_settle();
brs_checkSame($before, 'and with the portal opened again by hand, every value of every table is as it was');

echo "\nE. A folder left by a failed snapshot\n";
$unfinished = 'upgrade-20991231-235959';
mkdir($backups . '/' . $unfinished, 0750);
file_put_contents($backups . '/' . $unfinished . '/tblUsers.jsonl', '{"format":2,"table":"tblUsers","columns":["userID"],"base64":[]}' . "\n");
$list = bps_request($jar['root'], 'GET', '/admin/maintenance/backup')['body'];
brs_check(
    preg_match('/' . $unfinished . '.*?Unfinished.*?not a backup/s', $list) === 1,
    'the backup page labels it "Unfinished ... not a backup"'
);
brs_check(str_contains($list, '?inspect=' . $unfinished) === false, 'and offers no Inspect (so no Restore) for it');
$fresh = bps_request($jar['root'], 'GET', '/admin/maintenance/backup-check')['body'];
brs_check(
    str_contains(bps_text($fresh), 'OK — Most recent backup is 0 hours old') === true && str_contains(bps_text($fresh), '1 unfinished snapshot folder was found') === true,
    'the freshness page still dates the newest FINISHED snapshot, and says one unfinished folder was found',
    bps_message($fresh)
);
$health = bps_text(bps_request($jar['root'], 'GET', '/admin/maintenance/health')['body']);
brs_check(
    str_contains($health, 'Fresh, but a snapshot failed') === true && str_contains($health, '1 unfinished') === true,
    'the health page says "Fresh, but a snapshot failed"',
    substr($health, (int) strpos($health, 'Backups'), 200)
);
// With ONLY the unfinished folder left, nothing may call that a backup.
rename($backups . '/' . $good, $child . '/set-aside-good');
rename($backups . '/' . $bad, $child . '/set-aside-bad');
$fresh = bps_request($jar['root'], 'GET', '/admin/maintenance/backup-check')['body'];
brs_check(
    str_contains(bps_text($fresh), 'CRITICAL — No finished snapshot found') === true,
    'with only an unfinished folder left, the freshness page says there is NO finished snapshot (it used to say "0 hours old")',
    bps_message($fresh)
);
$health = bps_text(bps_request($jar['root'], 'GET', '/admin/maintenance/health')['body']);
brs_check(str_contains($health, 'No finished snapshot in _backups/') === true, 'and so does the health page', substr($health, (int) strpos($health, 'Backups'), 200));
brs_removeFolder($backups . '/' . $unfinished);
bps_settle();

echo "\nG. Names sent from a browser\n";
rename($child . '/set-aside-good', $backups . '/' . $good);
// Things OUTSIDE the backups folder that must survive, and things inside it
// that are not snapshots.
mkdir($child . '/outside', 0750);
file_put_contents($child . '/outside/precious.txt', 'must survive');
brs_copyFolder($backups . '/' . $good, $child . '/outside/snap');
symlink($child . '/outside', $backups . '/link-to-outside');
symlink($child . '/outside/snap', $backups . '/link-to-outside-snapshot');
file_put_contents($backups . '/.alert-state', 'a file the logger keeps here');
file_put_contents($backups . '/aaa-note.txt', 'a person\'s note');
$filesNow = static function () use ($child, $backups): string {
    $all = array_values(array_diff((array) scandir($backups), ['.', '..']));
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child . '/outside', FilesystemIterator::SKIP_DOTS)) as $file) {
        $all[] = substr((string) $file, strlen($child));
    }
    sort($all);
    return implode(', ', $all);
};
$filesBefore = $filesNow();
$state       = brs_fingerprint();
$oddNames    = [
    'two dots'                         => '..',
    'one dot (the backups folder itself)' => '.',
    'dots and a slash'                 => '../',
    'up and into the copy outside'     => '../../outside/snap',
    'an absolute path'                 => $child . '/outside/snap',
    'through the snapshot and back up' => $good . '/../../../outside/snap',
    'percent-coded dots'               => '%2e%2e%2foutside%2fsnap',
    'a backslash path'                 => '..\\..\\outside\\snap',
    'a link, then into it'             => 'link-to-outside/snap',
    'a zero byte'                      => $good . "\0x",
    'a line break at the end'          => $good . "\n",
    'a space at the end'               => $good . ' ',
    'a file, not a folder'             => 'aaa-note.txt',
    'an empty name'                    => '',
    'a list instead of text'           => ['x' => 'y'],
    'a list of one name'               => [$good],
    'a name with a dot in it'          => $good . '.bak',
];
foreach (['check_snapshot', 'restore_full', 'restore_table', 'delete'] as $action) {
    $wrong = [];
    foreach ($oddNames as $words => $name) {
        $said = bps_message(bps_press($jar['root'], ['action' => $action, 'name' => $name, 'table' => 'tblTasks'])['body']);
        if (str_starts_with($said, '[danger] Missing or invalid snapshot name.') === false || bps_portalIsOpen() === false || $filesNow() !== $filesBefore) {
            $wrong[] = $words . ': ' . substr($said, 0, 120) . (bps_portalIsOpen() === false ? ' -- PORTAL LEFT CLOSED' : '')
                     . ($filesNow() !== $filesBefore ? ' -- FILES CHANGED, now: ' . $filesNow() : '');
            bps_setPortal(false, '');
        }
    }
    brs_check($wrong === [], $action . ': all ' . count($oddNames) . ' odd names are refused, no file is removed inside or outside the backups folder, and the portal is left open', implode("\n", $wrong));
    brs_check(bps_newLines() === [], $action . ': and none of them left a line in the activity log (nothing ran)', brs_say(bps_newLines()));
    brs_checkSame($state, $action . ': and the database is identical (no error was logged either)');
}
$wrong = [];
foreach ($oddNames as $words => $name) {
    $query = is_array($name) === true ? http_build_query(['inspect' => $name]) : 'inspect=' . rawurlencode($name);
    $page  = bps_request($jar['root'], 'GET', '/admin/maintenance/backup?' . $query);
    if ($page['status'] !== 200 || str_contains($page['body'], 'Inspecting:') === true) {
        $wrong[] = $words . ': HTTP ' . $page['status'];
    }
}
brs_check($wrong === [], 'Inspect: the same names open nothing, and the page still draws', implode(', ', $wrong));
brs_checkSame($state, 'Inspect: and the database is identical (no error was logged)');

// A link is a real entry of the backups folder, with a name that passes the
// test. The actions that READ it refuse, because it leads outside; Delete
// removes the link itself and nothing it points at.
foreach (['link-to-outside-snapshot' => 'a link to a snapshot outside', 'link-to-outside' => 'a link to a folder outside'] as $link => $words) {
    foreach (['check_snapshot' => 'This snapshot cannot be restored. That snapshot is not in the backups folder', 'restore_full' => 'That snapshot is not in the backups folder'] as $action => $wanted) {
        $said = bps_message(bps_press($jar['root'], ['action' => $action, 'name' => $link])['body']);
        brs_check(str_starts_with($said, '[danger] ' . $wanted) === true && bps_portalIsOpen() === true, $action . ' of ' . $words . ' is refused, and the portal is open again', $said);
    }
}
bps_settle();
brs_checkSame($state, 'and the database is identical after those four refusals');
// Inspect is an action that READS too. It used to open such a link: it showed
// the outside folder's manifest and offered its Restore buttons.
foreach (['link-to-outside-snapshot' => 'a link to a snapshot outside', 'link-to-outside' => 'a link to a folder outside'] as $link => $words) {
    $page = bps_request($jar['root'], 'GET', '/admin/maintenance/backup?inspect=' . $link);
    brs_check(
        $page['status'] === 200 && str_contains($page['body'], 'Inspecting:') === false && str_contains($page['body'], 'name="action" value="restore_full"') === false
        && str_starts_with(bps_message($page['body']), '[danger] ' . $link . ' was not opened. That snapshot is not in the backups folder') === true,
        'Inspect of ' . $words . ' is refused in words: no manifest is shown and no Restore button is offered',
        'HTTP ' . $page['status'] . ' ' . bps_message($page['body'])
    );
}
$page = bps_request($jar['root'], 'GET', '/admin/maintenance/backup?inspect=' . $good);
brs_check(str_contains($page['body'], 'Inspecting:') === true && str_contains($page['body'], 'id="snapshot-unlisted-warning"') === false, 'Inspect of the real snapshot still opens it, with no warning (it holds nothing to warn about)');
brs_checkSame($state, 'and the database is identical after those three');
$said = bps_message(bps_press($jar['root'], ['action' => 'delete', 'name' => 'link-to-outside'])['body']);
brs_check(
    $said === '[success] Snapshot link-to-outside deleted.' && is_link($backups . '/link-to-outside') === false
    && is_file($child . '/outside/precious.txt') === true && is_file($child . '/outside/snap/_manifest.json') === true,
    'Delete of a link removes the link, and every file in the folder it pointed at is still there',
    $said . ' | now: ' . $filesNow()
);
brs_check(is_file($backups . '/.alert-state') === true && is_file($backups . '/aaa-note.txt') === true, 'the loose files in the backups folder are still there');
unlink($backups . '/link-to-outside-snapshot');
bps_settle();

echo "\nH. The database does not confirm that a rehearsal was undone\n";
$settingsPage = bps_request($jar['root'], 'GET', '/admin/settings/maintenance');
brs_check(
    $settingsPage['status'] === 200 && str_contains(bps_text($settingsPage['body']), 'Maintenance mode active') === true,
    'the page the message will point at exists: /admin/settings/maintenance offers "Maintenance mode active"',
    'HTTP ' . $settingsPage['status']
);
$classFile = $child . '/web/_core/DbBackup.php';
$original  = (string) file_get_contents($classFile);
$planted   = str_replace('$undone = $this->db->rollback();', '$undone = false;', $original, $count);
if (brs_check($count === 1, 'the fault is planted in the scratch copy of the class: the undo is never confirmed') === true) {
    $state = brs_fingerprint();
    file_put_contents($classFile, $planted);
    $answer = bps_press($jar['root'], ['action' => 'check_snapshot', 'name' => $good]);
    file_put_contents($classFile, $original);
    $words = bps_text($answer['body']);
    brs_check(
        str_contains($words, 'did not confirm that it was undone') === true && str_contains($words, 'Nothing has been changed') === true,
        'the page shows the class\'s own words ("...did not confirm that it was undone... Nothing has been changed...")',
        'HTTP ' . $answer['status'] . ': ' . substr(trim($words), 0, 300)
    );
    brs_check(
        str_contains($words, 'The portal is STILL CLOSED to everyone else') === true && str_contains($answer['body'], 'href="/admin/settings/maintenance"') === true,
        'it says plainly that the portal is still closed, and how to reopen it',
        substr(trim($words), 0, 500)
    );
    brs_check(str_contains($answer['body'], '<nav') === false && $answer['status'] === 500, 'it is a bare page (HTTP 500, none of the portal\'s usual header, which reads the database)', 'HTTP ' . $answer['status']);
    brs_check(bps_portalIsOpen() === false, 'and the portal IS still closed, as the page says: nothing touched the database again');
    brs_check(bps_newLines() === [], 'no line was written to the activity log on the closed connection', brs_say(bps_newLines()));
    bps_setPortal(false, '');
    $same = brs_waitFor(static fn (): bool => brs_differences($state, brs_fingerprint()) === '', 30);
    brs_check($same === true, 'the database is identical: closing the connection undid every row the rehearsal wrote', brs_differences($state, brs_fingerprint()));
}

echo "\nH2. The connection's own settings cannot be put back after a check or a restore\n";
// Planted the same way: in the scratch copy of the class, for one press each.
$planted = str_replace(
    '$back = $this->db->query("SET SESSION sql_mode = \'" . $was[\'mode\'] . "\', sql_notes = " . $was[\'notes\']) !== false',
    '$back = false',
    $original,
    $count
);
if (brs_check($count === 1, 'the fault is planted in the scratch copy of the class: the settings are never put back') === true) {
    $state = brs_fingerprint();
    foreach (['a check' => ['check_snapshot', 'This snapshot can be restored.', 'The rehearsal itself finished, and it changed nothing.', 'The portal is STILL CLOSED to everyone else'],
              'a restore that WORKED' => ['restore_full', 'Snapshot put back in full:', 'The restore itself FINISHED and is permanent.', 'is now as the snapshot held it']] as $what => [$action, $done, $how, $portalWords]) {
        file_put_contents($classFile, $planted);
        $answer = bps_press($jar['root'], ['action' => $action, 'name' => $good]);
        file_put_contents($classFile, $original);
        $words = bps_text($answer['body']);
        brs_check(
            $answer['status'] === 500 && str_contains($answer['body'], '<nav') === false && str_contains($words, $done) === true
            && str_contains($words, 'could not be put back as they were, so the connection was closed') === true && str_contains($words, $how) === true,
            $what . ': a bare page says what was done ("' . $done . '"), that the connection\'s settings could not be put back, and that the connection was closed',
            'HTTP ' . $answer['status'] . ': ' . substr(trim($words), 0, 600)
        );
        brs_check(str_contains($words, $portalWords) === true, 'and what it says about the portal is true for this case ("...' . $portalWords . '...")', substr(trim($words), 0, 900));
        if ($action === 'check_snapshot') {
            brs_check(bps_portalIsOpen() === false, 'after the check the portal IS still closed, as the page says');
        } else {
            // The snapshot was taken with the portal open, and the restore put the settings table back.
            brs_check(bps_portalIsOpen() === true, 'after the restore the portal\'s setting is what the snapshot held (open), as the page says');
        }
        brs_check(bps_newLines() === [], 'no line was written to the activity log on the closed connection', brs_say(bps_newLines()));
        bps_setPortal(false, '');
        bps_settle();
        $same = brs_waitFor(static fn (): bool => brs_differences($state, brs_fingerprint()) === '', 30);
        brs_check($same === true, 'the database is as it should be: ' . ($action === 'check_snapshot' ? 'identical' : 'every value as the snapshot held it, which is how it stood'), brs_differences($state, brs_fingerprint()));
    }
}
rename($backups . '/' . $good, $child . '/set-aside-good');

echo "\nF. The upgrade page\n";
brs_addByteRows();
// A press with a wrong form token: refused, and SAID to be.
bps_request($jar['root'], 'GET', '/admin/upgrade');
$answer = bps_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => str_repeat('0', 64)]);
$shown  = bps_messages(bps_request($jar['root'], 'GET', '/admin/upgrade')['body']);
brs_check(
    $answer['status'] === 302 && in_array('[danger] Invalid or expired form token. Please try again.', $shown, true) === true,
    'a press with a wrong form token is sent back to the page, which SHOWS "Invalid or expired form token"',
    'HTTP ' . $answer['status'] . '; shown: ' . brs_say($shown)
);
$again = bps_messages(bps_request($jar['root'], 'GET', '/admin/upgrade')['body']);
brs_check(in_array('[danger] Invalid or expired form token. Please try again.', $again, true) === false, 'and shows it once: it is gone when the page is opened again', brs_say($again));
// A press when the snapshot cannot be taken (the backups folder cannot be written to).
chmod($backups, 0500);
$page   = bps_request($jar['root'], 'GET', '/admin/upgrade');
$answer = bps_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => bps_token($page['body'])]);
$shown  = implode(' | ', bps_messages(bps_request($jar['root'], 'GET', '/admin/upgrade')['body']));
chmod($backups, 0750);
brs_check(
    str_contains($shown, '[danger] Backup failed, so the upgrade was not started. Nothing has been changed. The reason: Could not create the snapshot folder') === true
    && str_contains($shown, 'What to do:') === true,
    'when the snapshot cannot be taken, the page SHOWS "Backup failed", the reason, and what to do about it',
    'HTTP ' . $answer['status'] . '; shown: ' . $shown
);
brs_check(
    str_contains($shown, 'portal.upgrade.backup.enabled') === false && stripos($shown, 'without a backup') === false,
    'and does NOT tell the reader which setting skips the backup',
    $shown
);
$ran = (int) Brs::$writer->query("SELECT COUNT(*) FROM tblMigrations WHERE filename = '" . BPS_MIGRATION . "'")->fetch_row()[0];
brs_check($ran === 0 && bps_portalIsOpen() === true, 'no migration was run, and the portal is open again');
brs_check((glob($backups . '/upgrade-*') ?: []) === [], 'and no snapshot folder was left behind', implode(', ', glob($backups . '/*') ?: []));

$page = bps_request($jar['root'], 'GET', '/admin/upgrade');
brs_check(str_contains($page['body'], BPS_MIGRATION) === true, 'the upgrade page shows one migration waiting', 'HTTP ' . $page['status']);
$answer = bps_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => bps_token($page['body'])]);
if ($answer['status'] === 302) {
    $answer = bps_request($jar['root'], 'GET', '/admin/upgrade');
}
$taken = glob($backups . '/upgrade-*/_manifest.json') ?: [];
brs_check(
    count($taken) === 1 && str_starts_with((string) (json_decode((string) file_get_contents($taken[0]), true)['reason'] ?? ''), 'pre-upgrade-') === true,
    'it took its snapshot first, and the snapshot is finished',
    implode(', ', glob($backups . '/*') ?: [])
);
$ran = (int) Brs::$writer->query("SELECT COUNT(*) FROM tblMigrations WHERE filename = '" . BPS_MIGRATION . "'")->fetch_row()[0];
brs_check($ran === 1, 'and then ran the waiting migration');
brs_check(bps_portalIsOpen() === true, 'the portal is open again afterwards');
if ($taken !== []) {
    $events = (string) file_get_contents(dirname($taken[0]) . '/' . (is_file(dirname($taken[0]) . '/tblEvents.jsonl') === true ? 'tblEvents.jsonl' : 'tblEvents.json'));
    brs_check(str_contains($events, 'Imported, awkward bytes') === true, 'the snapshot holds the imported events');
}

echo "\nI. A value that is not on its column's list of choices\n";
// Saved the only way it can be: by a connection set to accept anything.
brs_sql(
    "CREATE TABLE tblZzBrsChoice (id INT PRIMARY KEY, e ENUM('a','b') NULL) ENGINE=InnoDB",
    "CREATE TABLE tblZzBrsChoiceB (id INT PRIMARY KEY, kind ENUM('one','two') NOT NULL DEFAULT 'one') ENGINE=InnoDB"
);
$modeWas = (string) Brs::$writer->query('SELECT @@session.sql_mode')->fetch_row()[0];
brs_sql(
    "SET SESSION sql_mode = ''",
    "INSERT INTO tblZzBrsChoice VALUES (1, 'a'), (2, 'not on the list'), (3, 'nor is this')",
    "INSERT INTO tblZzBrsChoiceB VALUES (1, 'one'), (2, 'three')",
    "SET SESSION sql_mode = '" . $modeWas . "'"
);
// One migration waiting again, and no snapshot in the folder yet.
Brs::$writer->query("DELETE FROM tblMigrations WHERE filename = '" . BPS_MIGRATION . "'");
foreach (glob($backups . '/upgrade-*', GLOB_ONLYDIR) ?: [] as $old) {
    brs_removeFolder($old);
}
$names = 'tblZzBrsChoice.e (2), tblZzBrsChoiceB.kind (1)';
$ranCount = static fn (): int => (int) Brs::$writer->query("SELECT COUNT(*) FROM tblMigrations WHERE filename = '" . BPS_MIGRATION . "'")->fetch_row()[0];

$page   = bps_request($jar['root'], 'GET', '/admin/upgrade');
brs_check(str_contains($page['body'], BPS_MIGRATION) === true && str_contains($page['body'], 'go_on_without_way_back') === false, 'the upgrade page shows one migration waiting, and at first offers only its ordinary button');
$answer = bps_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => bps_token($page['body'])]);
$page   = bps_request($jar['root'], 'GET', '/admin/upgrade');
$shown  = implode(' | ', bps_messages($page['body']));
brs_check(
    $answer['status'] === 302 && str_contains($shown, '[danger] The upgrade was NOT started, and nothing has been changed.') === true
    && str_contains($shown, 'CANNOT BE PUT BACK WHOLE') === true && str_contains($shown, 'holds 3 value(s)') === true && str_contains($shown, $names) === true
    && str_contains($shown, 'mend those rows in the live database') === true && str_contains($shown, 'take a NEW snapshot') === true,
    'pressing it: the upgrade page STOPS and says the snapshot cannot be put back whole, which table and column, how many, and what to do',
    'HTTP ' . $answer['status'] . '; shown: ' . $shown
);
brs_check($ranCount() === 0 && bps_portalIsOpen() === true, 'no migration was run, and the portal is open again');
$kept = glob($backups . '/upgrade-*/_manifest.json') ?: [];
brs_check(count($kept) === 1, 'the snapshot it took is KEPT (it is still good for looking at and for single tables)', implode(', ', glob($backups . '/*') ?: []));
brs_check(
    str_contains($page['body'], 'name="go_on_without_way_back" value="1"') === true && str_contains(bps_text($page['body']), 'Upgrade anyway, without a way back') === true
    && preg_match('/data-confirm="[^"]*WITHOUT a way back[^"]*another backup[^"]*"/', $page['body']) === 1,
    'and the page now offers a second button, "Upgrade anyway, without a way back", whose confirmation box says what that means'
);
$again = bps_request($jar['root'], 'GET', '/admin/upgrade')['body'];
brs_check(
    str_contains($again, 'go_on_without_way_back') === false && str_contains(implode(' ', bps_messages($again)), 'NOT started') === false,
    'both are shown once: opened again, the page has only its ordinary button and no message'
);

// The same snapshot, as the other pages show it.
$stopped = basename(dirname((string) ($kept[0] ?? '')));
$list    = bps_request($jar['root'], 'GET', '/admin/maintenance/backup')['body'];
brs_check(preg_match('/' . preg_quote($stopped, '/') . '.*?Cannot be put back whole/s', $list) === 1, 'the backup page\'s list marks that snapshot "Cannot be put back whole"');
$inspect = bps_request($jar['root'], 'GET', '/admin/maintenance/backup?inspect=' . $stopped)['body'];
$box     = preg_match('/<div class="alert alert-warning" id="snapshot-unlisted-warning">(.*?)<\/div>/s', $inspect, $found) === 1 ? bps_text($found[1]) : '';
brs_check(
    str_contains($box, 'CANNOT BE PUT BACK WHOLE') === true && str_contains($box, $names) === true && str_contains($box, 'take a NEW snapshot') === true
    && strpos($inspect, 'id="snapshot-unlisted-warning"') < strpos($inspect, 'name="action" value="restore_full"'),
    'the snapshot\'s own page says so in a yellow box ABOVE its Restore button, with the table, the column, the count and what to do',
    $box === '' ? 'no such box' : $box
);
$health = bps_text(bps_request($jar['root'], 'GET', '/admin/maintenance/health')['body']);
brs_check(
    str_contains($health, 'Fresh, but cannot be put back whole') === true && str_contains($health, $names) === true && str_contains($health, 'take a NEW snapshot') === true,
    'the health page says "Fresh, but cannot be put back whole", with the same table, column, count and what to do',
    substr($health, (int) strpos($health, 'Backups'), 900)
);
sleep(1);   // a new second, so "Run snapshot now" gets a folder name of its own at once
$said = bps_message(bps_press($jar['root'], ['action' => 'snapshot_now'])['body']);
brs_check(
    str_starts_with($said, '[warning] Snapshot created:') === true && str_contains($said, 'CANNOT BE PUT BACK WHOLE') === true && str_contains($said, $names) === true,
    '"Run snapshot now" takes the snapshot and says the same at once, in yellow',
    $said
);

// Going on deliberately: the second button. (A new refusal first, to be offered it.)
$page   = bps_request($jar['root'], 'GET', '/admin/upgrade');
bps_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => bps_token($page['body'])]);
$page   = bps_request($jar['root'], 'GET', '/admin/upgrade');
$snapshotsBefore = count(glob($backups . '/upgrade-*/_manifest.json') ?: []);
$answer = bps_request($jar['root'], 'POST', '/admin/upgrade', ['csrf_token' => bps_token($page['body']), 'go_on_without_way_back' => '1']);
$words  = bps_text($answer['body']);
brs_check(
    $answer['status'] === 200 && $ranCount() === 1 && str_contains($words, 'This upgrade was run WITHOUT a way back, because a global administrator chose to go on.') === true
    && str_contains($words, $names) === true,
    'pressing the second button runs the migration, and the page says it ran WITHOUT a way back and why',
    'HTTP ' . $answer['status'] . ', migrations run: ' . $ranCount() . '; ' . substr($words, (int) strpos($words, 'Upgrade Portal'), 500)
);
$lines = bps_newLines();
brs_check(
    isset($lines['UpgradeWithoutWayBack']) === true && str_contains((string) $lines['UpgradeWithoutWayBack'], $names) === true,
    'and one line in the activity log records that it was run without a way back',
    brs_say(array_keys($lines))
);
brs_check(count(glob($backups . '/upgrade-*/_manifest.json') ?: []) === $snapshotsBefore + 1, 'it still took its snapshot first, and removed no older one to make room for it');
brs_check(bps_portalIsOpen() === true, 'the portal is open again afterwards');
Brs::$writer->query('DELETE FROM tblActivityLogs WHERE logID > ' . (int) $bps['logMark']);

// What the warning says to do: mend the rows, take a new snapshot.
brs_sql('UPDATE tblZzBrsChoice SET e = NULL WHERE e = 0', "UPDATE tblZzBrsChoiceB SET kind = 'one' WHERE kind = 0");
sleep(1);
$said = bps_message(bps_press($jar['root'], ['action' => 'snapshot_now'])['body']);
brs_check(str_starts_with($said, '[success] Snapshot created:') === true && str_contains($said, 'CANNOT') === false, 'with the rows mended, "Run snapshot now" says nothing of the kind', $said);
$health = bps_text(bps_request($jar['root'], 'GET', '/admin/maintenance/health')['body']);
brs_check(str_contains($health, 'cannot be put back whole') === false, 'and neither does the health page: the newest snapshot is one that can be put back', substr($health, (int) strpos($health, 'Backups'), 300));

// =============================================================================
// J. The tables the one-table restore refuses, on the real page
// =============================================================================
// The class refuses them (proof 26 of tools/backup-restore-selftest.php). This
// is about the PAGE: that it does not offer a button that is going to be
// refused, that it still shows every one of them as part of the snapshot, and
// that a request posted past the missing button is turned away WITHOUT closing
// the portal to everybody for an action that was never going to run.
echo "\nJ. Tables that only a whole-snapshot restore may put back\n";
$newest = (string) basename(dirname((string) (array_slice(glob($backups . '/upgrade-*/_manifest.json') ?: [], -1)[0] ?? '')));
if (brs_check($newest !== '', 'there is a finished snapshot to inspect') === true) {
    $page  = bps_request($jar['root'], 'GET', '/admin/maintenance/backup?inspect=' . $newest)['body'];
    $words = bps_text($page);
    // The WHOLE name of the class on purpose: this file has no `use` line for
    // it, and a short name would only fail when this line ran.
    $only  = array_keys(\Portal\Core\DbBackup::WHOLE_DATABASE_ONLY);
    foreach ($only as $table) {
        brs_check(
            str_contains($page, '<code>' . $table . '</code>') === true,
            'the snapshot\'s page still LISTS ' . $table . ' (it is in the snapshot; hiding the row would read as "not saved")'
        );
        brs_check(
            str_contains($page, 'name="table" value="' . $table . '"') === false,
            'and offers no Restore button for ' . $table
        );
    }
    brs_check(
        str_contains($page, 'name="table" value="tblTasks"') === true,
        'while an ordinary table still has its Restore button'
    );
    brs_check(
        str_contains($words, implode(', ', $only)) === true && str_contains($words, 'Restore ALL tables') === true,
        'and one line above the list names them all and says which button does put them back',
        substr($words, max(0, (int) strpos($words, 'Per-table') - 40), 420)
    );

    // A request posted past the missing button. The log is settled first, so
    // "nothing was written to the activity log" is about these presses only.
    bps_settle();
    $stateBefore = brs_fingerprint();
    foreach ($only as $table) {
        $said = bps_message(bps_press($jar['root'], ['action' => 'restore_table', 'name' => $newest, 'table' => $table])['body']);
        brs_check(
            str_starts_with($said, '[danger] ' . $table . ' cannot be put back on its own.') === true
            && str_contains($said, 'Restore ALL tables') === true,
            'a posted request for ' . $table . ' is refused in words that name it and say what to press instead',
            $said
        );
        // WHAT THIS CHECK CAN AND CANNOT SEE. Asking whether the portal is
        // open AFTERWARDS does not prove it was never closed: a close
        // followed by a reopen leaves nothing behind (the helper puts the
        // setting back, tblSettings.updatedAt has no ON UPDATE, and settling
        // wipes the maintenance message). Its words used to claim it proved
        // the ordering, and the independent check showed it passing with the
        // refusal moved to AFTER the close. So the words now say only what
        // is seen, and the check below proves the ordering a different way.
        brs_check(
            bps_portalIsOpen() === true,
            'and the portal is open afterwards (this alone does not prove it was never closed -- see the next check)',
            'portal.maintenance.active = ' . bps_setting('portal.maintenance.active')
        );
        // THE ORDERING, PROVED: name a snapshot that does not exist. If the
        // guard ran after the page had taken hold of the snapshot, the answer
        // would complain about the snapshot; it names the TABLE instead, so
        // the refusal comes before anything that touches a snapshot -- and
        // before the portal is closed, which happens later still.
        $saidNoSnapshot = bps_message(bps_press($jar['root'], ['action' => 'restore_table', 'name' => 'there-is-no-such-snapshot', 'table' => $table])['body']);
        brs_check(
            str_starts_with($saidNoSnapshot, '[danger] ' . $table . ' cannot be put back on its own.') === true,
            'and the refusal comes before the snapshot is touched at all: asked with a snapshot name that does not exist, the answer still names the table',
            $saidNoSnapshot
        );
        brs_check(bps_newLines() === [], 'and nothing was written to the activity log, as for any other refused request', brs_say(array_keys(bps_newLines())));
    }
    bps_settle();
    brs_checkSame($stateBefore, 'the database is identical after all three refusals');
}

// =============================================================================
// K. Backups taken on a schedule, set from the page
// =============================================================================
// Nothing in the portal ever took a backup by itself before #569: the freshness
// alert reported a staleness that nothing was preventing. These two settings
// decide how often and how many to keep, and `cron/backup-run` is the ONE place
// the word becomes a period.
echo "\nK. Backups taken on a schedule\n";

// ⏰ FIRST: does the page say whether anything is ACTUALLY calling the job?
//    (#581) Until 3 November 2026 it did not, and could not: the only thing it
//    said was the conditional "this only happens if your hosting company's
//    scheduler is calling the portal". The setting ships as `daily`, so every
//    new installation showed "Every day" chosen in a box while, on most of
//    them, nothing was calling the job at all. A SCHEDULE NOBODY SET UP MUST
//    NOT LOOK LIKE A SCHEDULE THAT IS RUNNING.
//
//    Checked here, at the top of this section, because nothing has called the
//    job yet in this run — section N is what calls it. The note is removed
//    again at the end of this block so that section N still starts from
//    "nothing has ever called it".
$note = $backups . '/.backup-run-last';
brs_check(is_file($note) === false, 'nothing has called the scheduled job yet in this run, so the page is answering from no evidence');
$page = bps_request($jar['root'], 'GET', '/admin/maintenance/backup')['body'];
brs_check(
    str_contains(bps_text($page), 'NOTHING IS CALLING THE PORTAL') === true,
    'with nothing calling it, the page says so plainly rather than only "this happens if your scheduler calls"',
    substr(bps_text($page), max(0, (int) strpos(bps_text($page), 'automatically')), 400)
);
brs_check(
    str_contains($page, 'alert-danger') === true,
    'and it is drawn as a problem, not as information'
);

file_put_contents($note, (string) (time() - 3600));
$page = bps_request($jar['root'], 'GET', '/admin/maintenance/backup')['body'];
brs_check(
    str_contains(bps_text($page), 'scheduler is calling the portal') === true
    && str_contains(bps_text($page), 'NOTHING IS CALLING') === false,
    'with a call an hour ago it says the scheduler IS calling, and stops saying nothing is',
    substr(bps_text($page), max(0, (int) strpos(bps_text($page), 'automatically')), 400)
);
brs_check(
    str_contains($page, 'alert-success') === true,
    'and it is drawn as working'
);

file_put_contents($note, (string) (time() - (5 * 86400)));
$page = bps_request($jar['root'], 'GET', '/admin/maintenance/backup')['body'];
brs_check(
    str_contains(bps_text($page), 'HAS STOPPED') === true,
    'a scheduler that was calling and has stopped is NOT reported as one that is calling',
    substr(bps_text($page), max(0, (int) strpos(bps_text($page), 'automatically')), 400)
);
unlink($note);

brs_check(
    str_contains($page, 'name="action" value="save_schedule"') === true,
    'the Backup page offers the schedule'
);
brs_check(
    str_contains(bps_text($page), 'once a day') === true
    && str_contains($page, '/cron/backup-run') === true,
    'and SAYS the hosting company\'s scheduler has to call it, once a day, with the address'
);

$answer = bps_press($jar['root'], ['action' => 'save_schedule', 'frequency' => 'weekly', 'keep' => '4']);
brs_check(
    bps_setting('backup.schedule.frequency') === 'weekly' && bps_setting('backup.schedule.keep') === '4',
    'saving the schedule writes both settings',
    bps_setting('backup.schedule.frequency') . ' / ' . bps_setting('backup.schedule.keep')
);

// 🚫 Refused, not quietly corrected: somebody who typed 0 meant something, and
//    keeping none would delete the snapshot that had just been taken.
$answer = bps_press($jar['root'], ['action' => 'save_schedule', 'frequency' => 'weekly', 'keep' => '0']);
brs_check(
    bps_setting('backup.schedule.keep') === '4',
    'keeping zero is refused and the old value stands',
    bps_setting('backup.schedule.keep')
);
brs_check(
    str_contains(bps_message($answer['body'] ?? ''), 'between 1 and 365') === true
        || str_contains(bps_text(bps_request($jar['root'], 'GET', '/admin/maintenance/backup')['body']), 'between 1 and 365') === true,
    'and the refusal says what the range is'
);

$answer = bps_press($jar['root'], ['action' => 'save_schedule', 'frequency' => 'hourly', 'keep' => '4']);
brs_check(
    bps_setting('backup.schedule.frequency') === 'weekly',
    'a frequency that is not one of the choices is refused',
    bps_setting('backup.schedule.frequency')
);

// =============================================================================
// L. A download somebody can keep, and the same file brought back
// =============================================================================
// This is the one moment every member's personal details leave the server in
// one piece, so the file is always encrypted and the key is never stored. The
// round trip is what proves the two halves agree: a file that packs and cannot
// be opened is worse than no download at all.
echo "\nL. Download a copy, and import it back\n";

$page = bps_request($jar['root'], 'GET', '/admin/maintenance/backup')['body'];
brs_check(
    str_contains($page, '/admin/maintenance/backup/download') === true
    && str_contains($page, 'name="passphraseAgain"') === true,
    'the Backup page offers a download, and asks for the key twice'
);
brs_check(
    str_contains(bps_text($page), 'is not stored anywhere') === true
    && str_contains(bps_text($page), 'cannot be opened') === true,
    'and says plainly that the key is not stored and a lost key means a file nobody can open'
);

// A finished snapshot to download. Taken through the page, like everything else
// here.
bps_press($jar['root'], ['action' => 'snapshot_now']);
$finished = array_map(
    static fn (string $path): string => basename(dirname($path)),
    glob($backups . '/upgrade-*/_manifest.json') ?: []
);
sort($finished);
$toDownload = (string) (array_slice($finished, -1)[0] ?? '');

const BPS_KEY = 'a-long-enough-key-for-this-test';

$downloaded = '';
if (brs_check($toDownload !== '', 'there is a finished snapshot to download') === true) {
    $form = bps_request($jar['root'], 'GET', '/admin/maintenance/backup');
    $got  = bps_request($jar['root'], 'POST', '/admin/maintenance/backup/download', [
        'csrf_token'      => bps_token($form['body']),
        'name'            => $toDownload,
        'keyMode'         => \Portal\Core\BackupArchive::KEY_PASSPHRASE,
        'passphrase'      => BPS_KEY,
        'passphraseAgain' => BPS_KEY,
    ]);
    brs_check($got['status'] === 200, 'the download answers with the file itself, not a redirect', 'HTTP ' . $got['status']);
    brs_check(strlen($got['body']) > 1000, 'and the file has some size to it', strlen($got['body']) . ' bytes');

    // 🔐 THE FILE MUST NOT CARRY THE DATA IN THE CLEAR. Checked on what the
    //    browser really received, not on what the class says it wrote.
    brs_check(
        str_contains($got['body'], 'tblUsers') === false
        && str_contains($got['body'], 'Rae Root') === false
        && str_contains($got['body'], BPS_KEY) === false,
        'the downloaded file holds no table name, no member name and no key in the clear'
    );

    // 🧹 The temporary file packed on the way out must be gone: it holds
    //    everybody's details and would quietly double where that data sits.
    $leftBehind = glob($backups . '/*-download-*') ?: [];
    brs_check($leftBehind === [], 'and no packed copy is left in the backups folder', implode(', ', $leftBehind));

    // Save what the browser received, and open it the way an import will.
    $downloaded = $child . '/kept.' . \Portal\Core\BackupArchive::EXTENSION;
    file_put_contents($downloaded, $got['body']);

    try {
        $envelope = \Portal\Core\BackupArchive::envelopeOf($downloaded);
        brs_check(
            (string) ($envelope['snapshot'] ?? '') === $toDownload,
            'the file says which snapshot it was made from',
            brs_say($envelope)
        );
    } catch (\Throwable $e) {
        brs_check(false, 'the downloaded file can be read as a portal backup', $e->getMessage());
    }
}

// 🚫 The key is typed twice, and a mismatch must not produce a file.
$form = bps_request($jar['root'], 'GET', '/admin/maintenance/backup');
$got  = bps_request($jar['root'], 'POST', '/admin/maintenance/backup/download', [
    'csrf_token'      => bps_token($form['body']),
    'name'            => $toDownload,
    'keyMode'         => \Portal\Core\BackupArchive::KEY_PASSPHRASE,
    'passphrase'      => BPS_KEY,
    'passphraseAgain' => BPS_KEY . '-different',
]);
brs_check(
    $got['status'] === 302,
    'two keys that do not match give no file at all',
    'HTTP ' . $got['status'] . ', ' . strlen($got['body']) . ' bytes'
);

// 🚫 A key shorter than the minimum.
$form = bps_request($jar['root'], 'GET', '/admin/maintenance/backup');
$got  = bps_request($jar['root'], 'POST', '/admin/maintenance/backup/download', [
    'csrf_token'      => bps_token($form['body']),
    'name'            => $toDownload,
    'keyMode'         => \Portal\Core\BackupArchive::KEY_PASSPHRASE,
    'passphrase'      => 'short',
    'passphraseAgain' => 'short',
]);
brs_check($got['status'] === 302, 'a key shorter than the minimum gives no file', 'HTTP ' . $got['status']);

// 🧹 WHAT IS AND IS NOT PROVED ABOUT THE TEMPORARY FILE.
//
// The check above ("no packed copy is left in the backups folder") covers the
// ORDINARY path: a download that finishes removes the file it packed.
//
// THE CANCELLED PATH IS NOT PROVED HERE, and that is worth stating rather than
// implying. A check was written for it — a download with a 150 ms curl timeout,
// then a look for `*-download-*` — and it PASSED with the clean-up removed
// entirely, twice: with `ignore_user_abort(true)` planted away, and with the
// shutdown function planted away as well. The reason is that this test's
// snapshot is small, so the server finishes writing the whole answer long
// before curl gives up; no cancellation is ever staged, and the check was
// measuring the ordinary path a second time.
//
// Staging it honestly would need a snapshot big enough that the server is still
// streaming after the timeout — several megabytes of rows inserted first, which
// is minutes of runtime on every pull request for one check.
//
// So `backup-download.php` guards that path two ways (`ignore_user_abort(true)`
// and a `register_shutdown_function` registered before the file exists), both
// reasoned from PHP's documented behaviour and from the independent check's own
// measurement of the leak with a crafted loop — NOT from a check in this file.
// If that page's clean-up is ever changed, nothing here will notice.

// 🚧 AN ADMINISTRATOR OF ONE ORGANISATION MUST NOT BE ABLE TO DOWNLOAD ONE.
//    A snapshot holds every organisation on the installation, so this is the
//    gate that matters most here, and it is in the handler rather than only in
//    what is drawn.
//
//    ⚠️ THE TOKEN HAS TO COME FROM A PAGE THEY CAN ACTUALLY LOAD. The Backup
//       page refuses a non-global administrator before it renders anything, so
//       it carries no form token for them — and the first version of this check
//       took the token from there, got an empty string, and was refused by the
//       FORM TOKEN check rather than by the gate it exists to test. Planting
//       the gate away left all 174 checks passing. The token is session-wide,
//       so any page with a form will do; `$orgToken` is asserted non-empty
//       below so this cannot quietly go hollow again.
$orgToken = '';
foreach (['/account', '/', '/calendar'] as $wherever) {
    $orgToken = bps_token(bps_request($jar['admin'], 'GET', $wherever)['body']);
    if ($orgToken !== '') {
        break;
    }
}
brs_check(
    $orgToken !== '',
    'the organisation administrator has a usable form token, so the next check tests the gate and not the token'
);

$got = bps_request($jar['admin'], 'POST', '/admin/maintenance/backup/download', [
    'csrf_token'      => $orgToken,
    'name'            => $toDownload,
    'keyMode'         => \Portal\Core\BackupArchive::KEY_PASSPHRASE,
    'passphrase'      => BPS_KEY,
    'passphraseAgain' => BPS_KEY,
]);
brs_check(
    $got['status'] !== 200 || strlen($got['body']) < 1000,
    'an administrator of ONE organisation cannot download a backup of the whole installation',
    'HTTP ' . $got['status'] . ', ' . strlen($got['body']) . ' bytes'
);
brs_check(
    (int) (Brs::$writer->query(
        "SELECT COUNT(*) FROM tblActivityLogs WHERE activityType = 'BackupDownloadRefused'"
    )->fetch_row()[0] ?? 0) >= 1,
    'and the refusal is recorded, so a global administrator can see it was tried'
);

// 🚫 And a member cannot reach the page at all.
$got = bps_request($jar['member'], 'GET', '/admin/maintenance/backup/import');
brs_check($got['status'] !== 200, 'a member cannot open the import page', 'HTTP ' . $got['status']);

// =============================================================================
// M. Importing unpacks, and does NOT restore
// =============================================================================
// Two steps on purpose. The import produces a snapshot folder; putting it back
// is the existing Restore, with every guard it already has. One press would
// mean a second path writing over a live database, and that path would have to
// agree with the first about every guard for ever.
echo "\nM. Importing a backup file\n";

$page = bps_request($jar['root'], 'GET', '/admin/maintenance/backup/import');
brs_check($page['status'] === 200, 'the import page opens for a global administrator', 'HTTP ' . $page['status']);
brs_check(
    str_contains(bps_text($page['body']), 'does not restore anything') === true,
    'and says plainly that importing does not restore anything'
);
brs_check(
    str_contains(bps_text($page['body']), 'upload_max_filesize') === true
    || str_contains(bps_text($page['body']), 'does not say what size') === true,
    'and states what size of upload this server will actually take, rather than letting somebody find out'
);

if ($downloaded !== '' && is_file($downloaded) === true) {
    // Put the file where a customer's hosting panel would put it.
    @mkdir($backups . '/incoming', 0700, true);
    copy($downloaded, $backups . '/incoming/kept.' . \Portal\Core\BackupArchive::EXTENSION);

    $page = bps_request($jar['root'], 'GET', '/admin/maintenance/backup/import');
    brs_check(
        str_contains($page['body'], 'kept.' . \Portal\Core\BackupArchive::EXTENSION) === true,
        'a file put in the incoming folder appears on the page'
    );
    brs_check(
        str_contains($page['body'], 'value="' . $toDownload . '"') === true
            || str_contains(bps_text($page['body']), $toDownload) === true,
        'and the page says which snapshot it was made from'
    );

    $beforeFolders = glob($backups . '/imported-*') ?: [];

    // 🚫 The wrong key must not unpack anything.
    $got = bps_request($jar['root'], 'POST', '/admin/maintenance/backup/import', [
        'csrf_token' => bps_token($page['body']),
        'action'     => 'import_incoming',
        'name'       => 'kept.' . \Portal\Core\BackupArchive::EXTENSION,
        'passphrase' => 'the-wrong-key-entirely',
    ]);
    brs_check(
        (glob($backups . '/imported-*') ?: []) === $beforeFolders,
        'the wrong key leaves no half-unpacked snapshot behind'
    );

    // ✅ And the right one does unpack.
    $page = bps_request($jar['root'], 'GET', '/admin/maintenance/backup/import');
    $got  = bps_request($jar['root'], 'POST', '/admin/maintenance/backup/import', [
        'csrf_token' => bps_token($page['body']),
        'action'     => 'import_incoming',
        'name'       => 'kept.' . \Portal\Core\BackupArchive::EXTENSION,
        'passphrase' => BPS_KEY,
    ]);
    $afterFolders = glob($backups . '/imported-*') ?: [];
    brs_check(count($afterFolders) === count($beforeFolders) + 1, 'the right key unpacks it as one new snapshot',
        implode(', ', array_map('basename', $afterFolders)));

    $importedName = basename((string) (array_slice($afterFolders, -1)[0] ?? ''));
    brs_check(
        $importedName !== '' && is_file($backups . '/' . $importedName . '/_manifest.json') === true,
        'the unpacked snapshot has its manifest, so DbBackup sees it as finished',
        $importedName
    );

    // 🏷️ Named for the moment of import, NOT the name inside the file: it
    //    cannot collide with what is already there, it is obvious which came
    //    from outside, and prune() only removes `upgrade-…` so an imported
    //    backup is never tidied away by the scheduled job.
    brs_check(
        str_starts_with($importedName, 'imported-') === true,
        'and is named for the import rather than overwriting the original snapshot',
        $importedName
    );
    brs_check(
        is_dir($backups . '/' . $toDownload) === true,
        'the snapshot it was originally made from is untouched'
    );

    // 📋 It appears on the Backup page like any other snapshot, which is what
    //    makes the existing Rehearse and Restore work on it.
    $page = bps_request($jar['root'], 'GET', '/admin/maintenance/backup')['body'];
    brs_check(
        str_contains($page, '<code>' . $importedName . '</code>') === true,
        'the imported snapshot is listed on the Backup page'
    );
    brs_check(
        str_contains($page, 'name="name" value="' . $importedName . '"') === true,
        'with the existing buttons working on it'
    );

    // 🔁 AND IT REALLY IS THE SAME SNAPSHOT. The strongest check here: the
    //    rehearsal reads the imported folder and answers that it can be put
    //    back, which is a far stronger statement than "the files unpacked".
    $answer = bps_press($jar['root'], ['action' => 'check_snapshot', 'name' => $importedName]);
    brs_check(
        str_contains(bps_text($answer['body']), 'can be restored') === true
            || str_contains(bps_text(bps_request($jar['root'], 'GET', '/admin/maintenance/backup')['body']), 'can be restored') === true,
        'and rehearsing a restore of the IMPORTED snapshot says it can be put back',
        bps_message($answer['body'])
    );

    // 🚧 An administrator of one organisation must not be able to import
    //    either — with a REAL token, for the same reason as the download check
    //    above. The import page does render a token for them (it shows itself
    //    read-only rather than refusing outright), but taking it from a page
    //    they can certainly load keeps the two checks alike and cannot go
    //    hollow if that page's behaviour changes.
    $before   = glob($backups . '/imported-*') ?: [];
    $orgToken = '';
    foreach (['/admin/maintenance/backup/import', '/account', '/'] as $wherever) {
        $orgToken = bps_token(bps_request($jar['admin'], 'GET', $wherever)['body']);
        if ($orgToken !== '') {
            break;
        }
    }
    brs_check($orgToken !== '', 'the organisation administrator has a usable form token for the import check');
    bps_request($jar['admin'], 'POST', '/admin/maintenance/backup/import', [
        'csrf_token' => $orgToken,
        'action'     => 'import_incoming',
        'name'       => 'kept.' . \Portal\Core\BackupArchive::EXTENSION,
        'passphrase' => BPS_KEY,
    ]);
    brs_check(
        (glob($backups . '/imported-*') ?: []) === $before,
        'an administrator of ONE organisation cannot import a backup of the whole installation'
    );
    brs_check(
        (int) (Brs::$writer->query(
            "SELECT COUNT(*) FROM tblActivityLogs WHERE activityType = 'BackupImportRefused'"
        )->fetch_row()[0] ?? 0) >= 1,
        'and that refusal is recorded too'
    );

    // 🧹 The file the administrator put in the incoming folder is THEIRS and is
    //    left alone. Only an uploaded one is removed.
    brs_check(
        is_file($backups . '/incoming/kept.' . \Portal\Core\BackupArchive::EXTENSION) === true,
        'a file the administrator put in the incoming folder is left where they put it'
    );

    // 🛑 THE INCOMING FOLDER MUST NOT LOOK LIKE A SNAPSHOT, and this was a
    //    real fault with a real cost, found by the independent check: this
    //    class lists EVERY folder in `_backups/`, so `incoming` appeared on the
    //    Backup page as a row reading "Unfinished — not a backup" with a DELETE
    //    button beside it. `isSnapshotName('incoming')` was true, so Delete
    //    accepted it and removed the folder and everything in it — which may be
    //    the only copy of the database somebody has just brought back after
    //    losing their server. The row actively invited the press.
    $page = bps_request($jar['root'], 'GET', '/admin/maintenance/backup')['body'];
    brs_check(
        str_contains($page, '<code>incoming</code>') === false,
        'the incoming folder is NOT listed on the Backup page as a snapshot'
    );
    brs_check(
        str_contains($page, 'name="name" value="incoming"') === false,
        'and no button on that page acts on it'
    );

    // And a Delete posted past the missing button must still be refused, because
    // hiding a control is a courtesy and the handler is the control.
    bps_press($jar['root'], ['action' => 'delete', 'name' => 'incoming']);
    brs_check(
        is_dir($backups . '/incoming') === true
        && is_file($backups . '/incoming/kept.' . \Portal\Core\BackupArchive::EXTENSION) === true,
        'a Delete posted for "incoming" is refused, and the files in it survive'
    );
}

// =============================================================================
// N. The scheduled job itself
// =============================================================================
// Called by the hosting company's scheduler, with a token and no session. The
// address is seeded unprotected for exactly that reason; the token is the gate.
echo "\nN. The scheduled backup job\n";

// ⚠️ THE TOKEN IS WRITTEN FRESH EVERY RUN, AS A PLAIN VALUE.
//
//    `maintenance.cronToken` is a SENSITIVE setting, so the portal stores it
//    encrypted and `bps_setting()` reads the column raw. The first version of
//    this read that raw value and used it as the token: on a FRESH database the
//    row is empty so a plaintext token was written and section N passed, but on
//    a SECOND run against the same database it read the encrypted string and
//    six checks failed with `{"error":"invalid_token"}` — a job that looked
//    broken because the fixture was. Fine in CI, where the database is new each
//    time, and thoroughly misleading locally.
//
//    `isSensitive = 0` is set as well as the value, so the portal reads back
//    exactly what is written here however the row got there.
$cronToken = 'selftest-cron-token-' . bin2hex(random_bytes(6));
Brs::$writer->prepare(
    'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) '
    . 'VALUES (NULL, ?, ?, ?, 0) '
    . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), isSensitive = 0'
)->execute(['maintenance.cronToken', $cronToken, $cronToken]);

$noJar = $child . '/jar-nobody.txt';

$note = $backups . '/.backup-run-last';
@unlink($note);

$got = bps_request($noJar, 'GET', '/cron/backup-run');
brs_check($got['status'] === 403, 'the job refuses a call with no token', 'HTTP ' . $got['status']);

$got = bps_request($noJar, 'GET', '/cron/backup-run?token=' . urlencode($cronToken . '-wrong'));
brs_check($got['status'] === 403, 'and a wrong token', 'HTTP ' . $got['status']);

// ⏰ A REFUSED CALL IS NOT A CALL (#581). Recording one would let a stranger
//    who guessed the address make the Backup page claim the scheduler is
//    working — the page's answer is about backups, so a false reassurance
//    there is the worst direction for it to be wrong in.
brs_check(
    is_file($note) === false,
    'neither refused call is written down as the scheduler calling, so a stranger cannot make the page '
    . 'claim backups are being taken'
);

// Switched off: answers plainly rather than as an error, so a daily call does
// not fill the hosting company's log with failures.
bps_press($jar['root'], ['action' => 'save_schedule', 'frequency' => 'off', 'keep' => '7']);
$got    = bps_request($noJar, 'GET', '/cron/backup-run?token=' . urlencode($cronToken));
$answer = json_decode($got['body'], true);
brs_check(
    $got['status'] === 200 && is_array($answer) === true && ($answer['action'] ?? '') === 'none',
    'with the schedule off it answers "nothing to do" rather than an error',
    mb_substr($got['body'], 0, 200)
);
// ⏰ AND IT STILL COUNTS AS A CALL (#581). This is the branch that matters
//    most: an installation that has chosen "off" must still be able to find
//    out whether its scheduler is calling, or the only way to find out would
//    be to switch backups on.
$noteAt = is_file($note) === true ? (int) trim((string) file_get_contents($note)) : null;
brs_check(
    $noteAt !== null && abs($noteAt - time()) <= 60,
    'a call that answers "switched off" is still written down as a call',
    var_export($noteAt, true) . ' against ' . time()
);
$pageNow = bps_request($jar['root'], 'GET', '/admin/maintenance/backup')['body'];
brs_check(
    str_contains(bps_text($pageNow), 'IS calling the portal') === true
    && str_contains(bps_text($pageNow), 'switched off') === true,
    'and the page then says the scheduler IS calling AND that backups are switched off here — two facts, '
    . 'not one',
    substr(bps_text($pageNow), max(0, (int) strpos(bps_text($pageNow), 'automatically')), 400)
);

// Daily, with a snapshot taken moments ago: not due.
bps_press($jar['root'], ['action' => 'save_schedule', 'frequency' => 'daily', 'keep' => '7']);
$got    = bps_request($noJar, 'GET', '/cron/backup-run?token=' . urlencode($cronToken));
$answer = json_decode($got['body'], true);
brs_check(
    is_array($answer) === true && ($answer['action'] ?? '') === 'none'
    && str_contains((string) ($answer['reason'] ?? ''), 'not old enough') === true,
    'a backup taken moments ago means today\'s call takes no second one',
    mb_substr($got['body'], 0, 200)
);
// ⏰ A "not due" call is a call too (#581): being CALLED and TAKING a backup
//    are different things, and the page says so in separate sentences.
@unlink($note);
bps_request($noJar, 'GET', '/cron/backup-run?token=' . urlencode($cronToken));
brs_check(
    is_file($note) === true,
    'a call that decides no backup is due yet is still written down as a call'
);

// 📅 MAKE EVERY FINISHED SNAPSHOT LOOK OLDER.
//
//    `touch()` is NOT enough and that was the first attempt: for a FINISHED
//    snapshot `listSnapshots()` reads `created_at` from INSIDE `_manifest.json`
//    (only an unfinished one falls back to the folder's timestamp). So the four
//    checks below all read an age of 0 and reported the job as broken when the
//    fixture was wrong. The manifest's own field is what has to move.
$bps_age = static function (int $hoursAgo) use ($backups): int {
    $moved = 0;
    foreach (glob($backups . '/upgrade-*/_manifest.json') ?: [] as $manifestPath) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (is_array($manifest) === false) {
            continue;
        }
        $manifest['created_at'] = date('c', time() - ($hoursAgo * 3600));
        file_put_contents($manifestPath, (string) json_encode($manifest));
        touch($manifestPath, time() - ($hoursAgo * 3600));
        touch(dirname($manifestPath), time() - ($hoursAgo * 3600));
        $moved++;
    }

    return $moved;
};

// 📅 DUE. The age is worked out from the newest FINISHED snapshot, so making
//    that older is all it takes — there is no stored "last run" to fake, which
//    is the point of reading it from the snapshots themselves.
$aged = $bps_age(48);
brs_check($aged > 0, 'there is a finished snapshot to make older', (string) $aged);
$beforeCount = count(glob($backups . '/upgrade-*/_manifest.json') ?: []);
$got         = bps_request($noJar, 'GET', '/cron/backup-run?token=' . urlencode($cronToken));
$answer      = json_decode($got['body'], true);
brs_check(
    is_array($answer) === true && ($answer['action'] ?? '') === 'snapshot' && ($answer['ok'] ?? false) === true,
    'with the newest backup two days old, the daily call takes one',
    mb_substr($got['body'], 0, 300)
);
brs_check(
    (string) ($answer['snapshot'] ?? '') !== '',
    'and says which snapshot it made',
    brs_say($answer)
);
brs_check(
    count(glob($backups . '/upgrade-*/_manifest.json') ?: []) > $beforeCount,
    'which really is a new finished snapshot on disk'
);

// 🧹 Keeping only one must remove the older ones — and must NOT touch an
//    imported snapshot, which prune() leaves alone because it only removes
//    folders named `upgrade-…`.
$importedBefore = count(glob($backups . '/imported-*') ?: []);
$bps_age(48);
bps_press($jar['root'], ['action' => 'save_schedule', 'frequency' => 'daily', 'keep' => '1']);
$got    = bps_request($noJar, 'GET', '/cron/backup-run?token=' . urlencode($cronToken));
$answer = json_decode($got['body'], true);
brs_check(
    is_array($answer) === true && (int) ($answer['removed'] ?? 0) >= 1,
    'keeping one removes the older snapshots',
    brs_say($answer)
);
brs_check(
    count(glob($backups . '/imported-*') ?: []) === $importedBefore,
    'and an IMPORTED snapshot is never tidied away by the scheduled job'
);

// =============================================================================
// O. Off-site backup: does the script on THIS server obey the page? (#671)
// =============================================================================
// Until 30 October 2026 the six `backup.offsite.*` settings were read by
// NOTHING: the sync script used the variables written into its own copy. An
// administrator could set a destination and an alert address, be told "saved",
// and nothing anywhere acted on it. The settings are real now, but the script
// is copied to the server BY HAND and is never deployed, so the portal cannot
// assume the copy sitting there is a current one — and telling somebody their
// settings are in force when the script on their own server ignores them is
// the same fault one step along.
//
// So the page works out, from the files on disk, which of three things is true:
// the settings are in force, a script is there that ignores them, or there is
// no script to obey them. This section drives the real page over HTTP through
// all three, and through the two reasons a script can ignore them. It then
// goes on to the four other ways this page can mislead somebody: a missing
// RECORDER (O7), a run's own WARNING lines (O8), a stored value the portal
// will not use (O9), a stored "copies to keep" figure outside the boxes'
// own range (O10), and "Run now" answering green for a run that sent nothing
// (O11).
//
// TWO CHECKS HERE MATTER MORE THAN THE REST.
//
// O2 copies the REAL shipped `tools/offsite-backup/sync-offsite.sh.example`
// into the scratch portal's `_backups` folder, as a customer is told to, and
// requires the page to say the settings are in force. Without that, every
// other check in this section is about a stand-in script written a few lines
// below — so an edit to the shipped script that the page's detection no longer
// recognises would leave this whole section green while the page told EVERY
// real installation that its script disobeys the page. That gap was real until
// 31 October 2026 and was found by an independent review, not by a failing
// test.
//
// O4 is the planted one. The shipped script names `read-offsite-settings.php`
// and `READ_PHP` several times in its own comments, explaining what they do.
// So a plain text search for either name is satisfied by a script whose CALL
// has been commented out — which is exactly the state the page exists to
// catch, and is why it strips comment lines first. O4 plants that state and
// requires the page to catch it, and asserts separately that a search without
// the comment-stripping would NOT have.
//
// WHAT THIS SECTION CANNOT PROVE, said plainly:
// - That the script, once it reads the settings, uses them. Nothing here runs
//   the shipped script: it needs a command line and a program to send with.
//   O11 runs a stand-in that only records a result.
// - That a "#" part way along a line is handled, because the page deliberately
//   does not treat one as a comment, and nothing in the shipped script needs
//   it to.
// - That the reader itself is a matching version. Only the format number on
//   its first line tells the script that, at run time.
// - A call that is there but switched off by its own shell condition
//   (`false && php "${READ_PHP}"`). The page says in its own comments that it
//   cannot see that, and judging it needs a shell rather than a pattern.
// - Whether a WARNING line a run printed is TRUE. O8 proves the page shows the
//   lines a run recorded; whether the run was right to print one is the sync
//   script's business.
echo "\nO. Off-site backup: are the settings in force, ignored, or unobeyed?\n";

$osPage = static function (string $who) use ($jar): string {
    return bps_request($jar[$who], 'GET', '/admin/maintenance/offsite-backup')['body'];
};

// The four files the page looks for, at the paths the page looks in. The
// scratch portal's PORTAL_ROOT is $child/web, so `_backups` here is the same
// folder every other section in this file works in.
$osScript   = $backups . DIRECTORY_SEPARATOR . 'sync-offsite.sh';
$osReader   = $backups . DIRECTORY_SEPARATOR . 'read-offsite-settings.php';
$osRecorder = $backups . DIRECTORY_SEPARATOR . 'log-offsite-result.php';
$osKey      = $child . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'offsite.key';

// The three files in the repository a customer is told to copy. Read from
// their real paths, not stand-ins: if one is ever renamed or moved, the check
// below fails and says which.
$osScriptSource   = __DIR__ . DIRECTORY_SEPARATOR . 'offsite-backup' . DIRECTORY_SEPARATOR . 'sync-offsite.sh.example';
$osReaderSource   = __DIR__ . DIRECTORY_SEPARATOR . 'offsite-backup' . DIRECTORY_SEPARATOR . 'read-offsite-settings.php';
$osRecorderSource = __DIR__ . DIRECTORY_SEPARATOR . 'offsite-backup' . DIRECTORY_SEPARATOR . 'log-offsite-result.php';

// 🧹 All six settings are put back at the end of the section: O5, O9 and O10
//    change them, and the next test to use this database should find them as
//    they were. They are all seeded installation-wide by full_schema.sql, so
//    this is an update either way and no row is created or left behind.
$osKeys   = ['backup.offsite.enabled', 'backup.offsite.destination', 'backup.offsite.rcloneRemote',
    'backup.offsite.keepWeekly', 'backup.offsite.keepMonthly', 'backup.offsite.alertEmail'];
$osBefore = [];
foreach ($osKeys as $osSettingKey) {
    $osBefore[$osSettingKey] = bps_setting($osSettingKey);
}
$osSet = static function (string $key, string $value): void {
    Brs::$writer->prepare(
        'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) '
        . 'VALUES (NULL, ?, ?, ?, 0) '
        . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)'
    )->execute([$key, $value, '']);
};

// 🧹 The run rows this section writes, so only this section's are removed.
$osLogMark  = (int) Brs::$writer->query('SELECT COALESCE(MAX(logID), 0) FROM tblOffsiteSyncLog')->fetch_row()[0];
$osRunCount = static fn (): int => (int) Brs::$writer->query('SELECT COUNT(*) FROM tblOffsiteSyncLog')->fetch_row()[0];

/**
 * Save the settings form as the global administrator and come back with the
 * page that shows the result.
 *
 * Two requests either side of the POST on purpose: the form token is replaced
 * on every acceptance (Auth::verifyCsrf), so the token has to be read fresh,
 * and the handler answers with a redirect this harness does not follow, so the
 * message it stored is only visible on the next GET.
 *
 * @return array{said: string, body: string, status: int}
 */
$osSave = static function (array $fields) use ($jar): array {
    $token  = bps_token(bps_request($jar['root'], 'GET', '/admin/maintenance/offsite-backup')['body']);
    $posted = bps_request($jar['root'], 'POST', '/admin/maintenance/offsite-backup', array_merge(
        ['csrf_token' => $token, 'action' => 'save'],
        $fields
    ));
    $after = bps_request($jar['root'], 'GET', '/admin/maintenance/offsite-backup');
    return ['said' => bps_message($after['body']), 'body' => $after['body'], 'status' => $posted['status']];
};

/**
 * Press "Run now" as the global administrator and come back with the page
 * that shows the result. Same two-requests-either-side reason as $osSave.
 *
 * @return array{said: string, body: string, status: int}
 */
$osRunNow = static function () use ($jar): array {
    $token  = bps_token(bps_request($jar['root'], 'GET', '/admin/maintenance/offsite-backup')['body']);
    $posted = bps_request($jar['root'], 'POST', '/admin/maintenance/offsite-backup/run', ['csrf_token' => $token]);
    $after  = bps_request($jar['root'], 'GET', '/admin/maintenance/offsite-backup');
    return ['said' => bps_message($after['body']), 'body' => $after['body'], 'status' => $posted['status']];
};

/**
 * The WARNING lines the page printed BESIDE A RUN in the Recent runs table,
 * which is a different place from the notice at the top of the page.
 *
 * Read from the one class the page puts on them. This is not a copy of the
 * page's own filtering — it asks what reached the HTML, so a page that stopped
 * printing them, or printed every line rather than the WARNING ones, fails.
 *
 * @return string[]
 */
$osTableWarnings = static function (string $html): array {
    preg_match_all('/<div class="small text-warning-emphasis">(.*?)<\/div>/s', $html, $found, PREG_SET_ORDER);
    return array_map(static fn (array $one): string => trim(bps_text($one[1])), $found);
};

/**
 * The NOTICE AT THE TOP of the page, on its own — empty when it is not shown.
 *
 * Scoped to that one block deliberately, and this was measured rather than
 * assumed. Asking whether the warning line appeared ANYWHERE on the page was
 * satisfied by the table's own copy of it further down: planting an empty
 * $newestWarnings on the page left that check green while the notice had lost
 * its quoted line altogether. The two places print the same text for different
 * reasons, so a check about one of them has to read only that one.
 *
 * The block holds no nested <div>, so the first closing tag is its own.
 */
$osTopNotice = static function (string $html): string {
    return preg_match('/<strong>The last run did not use everything set on this page\.<\/strong>(.*?)<\/div>/s', $html, $found) === 1
        ? trim(bps_text($found[1])) : '';
};

// A stand-in for the shipped script. It NAMES both the reader and READ_PHP in
// its comments on purpose, exactly as the real one does: that is what makes
// the planted fault in O4 a real test rather than a trivial one. The real
// shipped script is used in O2; this one exists because the plant needs ONE
// known call line to comment out, and the shipped file's call sits inside an
// `elif` whose other branches would have to be unpicked with it.
$osWorkingScript = <<<'OS_SCRIPT'
#!/usr/bin/env bash
# Written by tools/backup-page-selftest.php for ONE run (deleted with the
# scratch folder). Test only — it sends nothing anywhere.
#
# These comment lines name read-offsite-settings.php and READ_PHP deliberately,
# the way the shipped tools/offsite-backup/sync-offsite.sh.example does when it
# explains what the reader is for and what it cannot do.
set -euo pipefail
BACKUPS_DIR="$(cd "$(dirname "$0")" && pwd)"
READ_PHP="${BACKUPS_DIR}/read-offsite-settings.php"
SETTINGS_OUT="$(php "${READ_PHP}")"
echo "${SETTINGS_OUT}"
OS_SCRIPT;

// The one line the plant comments out. Named once so O4 cannot quietly comment
// out a line that was never there.
$osCallLine = 'SETTINGS_OUT="$(php "${READ_PHP}")"';

$osInForceBadge  = '<span class="badge bg-success">in force</span>';
$osIgnoredBadge  = '<span class="badge bg-danger">ignored by the script</span>';
$osNoScriptBadge = '<span class="badge bg-secondary">no script to obey them</span>';
$osIgnoresAlert  = 'The script on this server ignores the settings below.';
$osCommandLine   = 'This needs a command line on the server, and it is not set up for you.';
$osRecorderBadge = 'log-offsite-result.php: <span class="badge bg-warning">missing</span>';
$osCannotRecord  = 'Nothing recorded, and nothing here can record it.';
$osNoRunsYet     = 'No runs recorded yet.';

// -----------------------------------------------------------------------------
// O1. Nothing copied to the server at all.
// -----------------------------------------------------------------------------
@unlink($osScript);
@unlink($osReader);
@unlink($osRecorder);
@unlink($osKey);
brs_check($osRunCount() === 0, 'no off-site run is recorded yet, so the page\'s empty-list wording is what is being read', 'rows: ' . $osRunCount());
$page = $osPage('root');
brs_check($page !== '' && str_contains($page, 'Off-site') === true, 'the off-site backup page opens for a global administrator');
brs_check(
    str_contains($page, $osNoScriptBadge) === true,
    'with no sync-offsite.sh on the server the page says there is no script to obey the settings'
);
brs_check(
    str_contains($page, 'Setup required</h5>') === true,
    'and shows the "Setup required" card'
);
// 📖 "No script" must NOT be reported as "a script that ignores them": one is
//    a job nobody has started, the other is a job somebody believes is done.
brs_check(
    str_contains(bps_text($page), $osIgnoresAlert) === false,
    'and does NOT say a script is ignoring them, because there is no script'
);
brs_check(
    str_contains(bps_text($page), $osCommandLine) === true,
    'the standing warning that this needs a command line on the server is shown'
);
// 📝 THE RECORDER IS A FOURTH FILE AND A SEPARATE QUESTION. Nothing but
//    log-offsite-result.php writes to tblOffsiteSyncLog, so without it copies
//    can be going out every week and this page shows "never" — which reads
//    exactly like "nothing has been sent". So it must not say "no runs yet".
brs_check(
    str_contains($page, $osRecorderBadge) === true,
    'the status card marks log-offsite-result.php missing, because it is a fourth checked file'
);
brs_check(
    str_contains(bps_text($page), $osCannotRecord) === true,
    'and the empty list says "Nothing recorded, and nothing here can record it." rather than "no runs yet"',
    substr(bps_text($page), max(0, (int) strpos(bps_text($page), 'Recent runs')), 320)
);
brs_check(
    str_contains(bps_text($page), $osNoRunsYet) === false,
    'and does NOT also say "No runs recorded yet.", which would be claiming something it cannot know'
);

// -----------------------------------------------------------------------------
// O2. THE REAL SHIPPED FILES, copied as a customer is told to copy them.
// -----------------------------------------------------------------------------
// This is the check that ties the page's detection to the file customers
// actually use. Everything else in this section runs against the stand-in
// script above; if the shipped script were edited into a shape the page no
// longer recognises, only this check would notice, and the page would be
// telling every real installation that its script disobeys it.
foreach (['sync-offsite.sh.example' => $osScriptSource, 'read-offsite-settings.php' => $osReaderSource,
    'log-offsite-result.php' => $osRecorderSource] as $osName => $osFrom) {
    brs_check(is_file($osFrom) === true, 'tools/offsite-backup/' . $osName . ' is where the page expects customers to copy it from', $osFrom);
}
copy($osScriptSource, $osScript);
chmod($osScript, 0750);
copy($osReaderSource, $osReader);
copy($osRecorderSource, $osRecorder);
file_put_contents($osKey, bin2hex(random_bytes(32)));
brs_check(
    md5_file($osScriptSource) === md5_file($osScript),
    'the copy in _backups really is the shipped script, byte for byte',
    'source ' . (string) md5_file($osScriptSource) . ' copy ' . (string) md5_file($osScript)
);
$page = $osPage('root');
brs_check(
    str_contains($page, $osInForceBadge) === true,
    'THE REAL SHIPPED sync-offsite.sh.example makes the page say the settings are in force'
);
brs_check(
    str_contains(bps_text($page), $osIgnoresAlert) === false,
    'and the real shipped script is never reported as ignoring the settings'
);
brs_check(
    str_contains($page, 'Setup required</h5>') === false,
    'and the "Setup required" card is gone, now all four files are there'
);
brs_check(
    str_contains(bps_text($page), $osNoRunsYet) === true
    && str_contains(bps_text($page), $osCannotRecord) === false,
    'with the recorder in place and no runs, the empty list says "No runs recorded yet." instead',
    substr(bps_text($page), max(0, (int) strpos(bps_text($page), 'Recent runs')), 320)
);

// -----------------------------------------------------------------------------
// O3. The stand-in script, which is the baseline the plant in O4 is measured
//     against.
// -----------------------------------------------------------------------------
file_put_contents($osScript, $osWorkingScript);
$page = $osPage('root');
brs_check(
    str_contains($page, $osInForceBadge) === true,
    'the stand-in script, which calls the reader the same way, is also answered "in force"'
);

// -----------------------------------------------------------------------------
// O4. THE PLANTED FAULT: the call commented out, every mention left behind.
// -----------------------------------------------------------------------------
$osPlanted = str_replace($osCallLine, '# ' . $osCallLine, $osWorkingScript);
brs_check(
    $osPlanted !== $osWorkingScript,
    'the plant really changed the script: the line it comments out was there to comment out'
);
file_put_contents($osScript, $osPlanted);

// 🔎 THE PLANT IS NOT A TRIVIAL ONE, AND THIS IS WHAT PROVES IT. Both names
//    are still in the file, and a search that does NOT strip comment lines
//    still finds the call — so anything reading the file as plain text would
//    call this script obedient. This is deliberately the NAIVE search, not a
//    copy of the page's own test: it is here to measure the plant, not to
//    second-guess the answer. (A test holding its own copy of the logic it
//    tests proves nothing; this one holds the logic the page REJECTED.)
brs_check(
    str_contains($osPlanted, 'read-offsite-settings.php') === true
    && str_contains($osPlanted, 'READ_PHP') === true,
    'the planted script still names read-offsite-settings.php and READ_PHP'
);
brs_check(
    preg_match('/READ_PHP\s*=/', $osPlanted) === 1
    && preg_match('/php\s+"?\$\{READ_PHP\}?"?/', $osPlanted) === 1,
    'and a search WITHOUT the comment-stripping still finds both halves, so it would pass this script as obedient'
);

$page = $osPage('root');
brs_check(
    str_contains($page, $osIgnoredBadge) === true,
    'but the page catches it: with the call commented out it says the script ignores the settings'
);
brs_check(
    str_contains(bps_text($page), $osIgnoresAlert) === true,
    'and shows the danger warning saying so'
);
brs_check(
    str_contains(bps_text($page), 'does not call') === true,
    'and gives the right reason of the two: the script is there and does not call the reader',
    substr(bps_text($page), max(0, (int) strpos(bps_text($page), $osIgnoresAlert)), 320)
);
brs_check(
    str_contains($page, $osInForceBadge) === false,
    'and nothing anywhere on the page still says the settings are in force'
);

// -----------------------------------------------------------------------------
// O5. The other reason: a script that calls a reader which is not there.
// -----------------------------------------------------------------------------
file_put_contents($osScript, $osWorkingScript);
unlink($osReader);
$page = $osPage('root');
brs_check(
    str_contains($page, $osIgnoredBadge) === true,
    'a script that calls a reader which is not on the server also means the settings are ignored'
);
brs_check(
    str_contains(bps_text($page), 'read-offsite-settings.php is not in the portal\'s _backups folder') === true,
    'and the page names the missing file rather than blaming the script',
    substr(bps_text($page), max(0, (int) strpos(bps_text($page), $osIgnoresAlert)), 320)
);
brs_check(
    str_contains($page, 'read-offsite-settings.php: <span class="badge bg-warning">missing</span>') === true,
    'and the status card marks that file missing'
);

// -----------------------------------------------------------------------------
// O6. Switched off, with a script that would otherwise obey the page.
// -----------------------------------------------------------------------------
// Only said once the script really does read the settings: on an older copy the
// tick box decides nothing, and saying a scheduled run will skip would be
// describing a choice the server is not making.
copy($osReaderSource, $osReader);
$osSet('backup.offsite.enabled', '0');
$page = $osPage('root');
brs_check(
    str_contains($page, $osInForceBadge) === true,
    'with the working pair back, the settings are in force again'
);
brs_check(
    str_contains(bps_text($page), 'Switched off.') === true
    && str_contains(bps_text($page), 'records itself as skipped and sends nothing') === true,
    'and switched off, the page says a scheduled run records itself as skipped and sends nothing',
    substr(bps_text($page), max(0, (int) strpos(bps_text($page), 'Switched off')), 320)
);
$osSet('backup.offsite.enabled', '1');
$page = $osPage('root');
brs_check(
    str_contains(bps_text($page), 'records itself as skipped and sends nothing') === false,
    'switched on, that notice is gone'
);
brs_check(
    str_contains($page, '<span class="badge bg-success">enabled</span>') === true,
    'and the page says it is enabled'
);

// -----------------------------------------------------------------------------
// O7. The recorder missing on its own, with everything else in place.
// -----------------------------------------------------------------------------
// O1 had nothing at all, which is the easy case. This is the dangerous one: a
// script that runs, a reader it obeys, a key — and nothing able to write down
// what happened. Copies really are being sent and the page can see none of
// them, so it has to say so rather than say "no runs yet".
unlink($osRecorder);
$page = $osPage('root');
brs_check(
    str_contains($page, $osRecorderBadge) === true,
    'with only the recorder missing, the status card still marks it missing'
);
brs_check(
    str_contains($page, 'Setup required</h5>') === true,
    'and the "Setup required" card comes back for the recorder alone'
);
brs_check(
    str_contains($page, $osInForceBadge) === true,
    'while the settings are still in force, because obeying the page and recording a run are different jobs'
);
brs_check(
    str_contains(bps_text($page), $osCannotRecord) === true,
    'and the empty list says nothing here can record a run'
);
copy($osRecorderSource, $osRecorder);
$page = $osPage('root');
brs_check(
    str_contains($page, 'log-offsite-result.php: <span class="badge bg-success">present</span>') === true
    && str_contains(bps_text($page), $osNoRunsYet) === true,
    'with it copied back, the card says present and the list says "No runs recorded yet."'
);

// -----------------------------------------------------------------------------
// O8. A run's own WARNING lines, in both the places they are shown.
// -----------------------------------------------------------------------------
// A WARNING line is the ONLY record that a stored setting was held back: the
// settings bridge refuses a value it will not put on a command line rather
// than escaping it, and says so in a line of the run's output. The page did
// not read `output` at all at first, so a mistyped destination left no trace
// anywhere an administrator could reach while the card said "in force".
//
// The lines are shown TWICE on purpose, and the two have different jobs: once
// at the top of the page for the NEWEST run, because that is the one that
// describes what is happening now, and once beside each run in the table,
// because that is the history. An old warning that has since been put right
// must not raise the notice at the top — so the third part of this check is
// the one that would catch a page reading "any run" where it means "the
// newest".
$osWarnLine  = 'WARNING: the alert address on /admin/maintenance/offsite-backup was not used because it is not'
    . ' a plain e-mail address this portal can pass to the server\'s mail program.';
$osPlainLine = 'Step 3 of 6: packing the newest snapshot.';
$osInsertRun = static function (string $runAt, string $status, string $output): void {
    Brs::$writer->prepare(
        'INSERT INTO tblOffsiteSyncLog (runAt, triggeredBy, destination, snapshotName, bundleSize, durationSec, status, errorMsg, output) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)'
    )->execute([$runAt, 'cron', 'rclone', 'upgrade-20261030-000000', 4096, 7, $status, $output]);
};
// A multi-line value, because that is what a run's output really is — and a
// single-line one would have hidden the fault in log-offsite-result.php that
// stored NULL for every multi-line `--output`.
$osInsertRun('2026-01-01 01:00:00', 'success', $osPlainLine . "\n" . $osWarnLine . "\nStep 4 of 6: sending it.");
$page = $osPage('root');
brs_check(
    $osTopNotice($page) !== '',
    'a run whose output holds a WARNING line raises the notice at the top of the page'
);
brs_check(
    str_contains($osTopNotice($page), $osWarnLine) === true,
    'and the notice ITSELF quotes the warning, not just the fact that there was one',
    'the notice said: ' . $osTopNotice($page)
);
brs_check(
    in_array($osWarnLine, $osTableWarnings($page), true) === true,
    'and the same line is shown again beside that run in the Recent runs table',
    brs_say($osTableWarnings($page))
);
// 📖 Only the WARNING lines. A page printing the whole of `output` would pass
//    both checks above and bury the one line that matters in a run log.
brs_check(
    str_contains(bps_text($page), $osPlainLine) === false,
    'but an ordinary line of the run\'s output is NOT printed: only the warnings are'
);

// Now a newer run with nothing wrong. The old warning is history and must stay
// in the table while the notice at the top goes.
$osInsertRun('2026-01-02 01:00:00', 'success', "Step 3 of 6: packing the newest snapshot.\nStep 6 of 6: done.");
$page = $osPage('root');
brs_check(
    $osTopNotice($page) === '',
    'an old warning on a run that is NO LONGER the newest does not raise the notice at the top',
    'the notice said: ' . $osTopNotice($page)
);
brs_check(
    in_array($osWarnLine, $osTableWarnings($page), true) === true,
    'but it is still shown beside the run it happened on, because the table is the history',
    brs_say($osTableWarnings($page))
);
brs_check(
    count($osTableWarnings($page)) === 1,
    'and the clean newer run adds no warning of its own',
    brs_say($osTableWarnings($page))
);
Brs::$writer->query('DELETE FROM tblOffsiteSyncLog WHERE logID > ' . $osLogMark);

// -----------------------------------------------------------------------------
// O9. Saving a value this portal will not use.
// -----------------------------------------------------------------------------
// `My Remote:backups` is an ordinary typo — a space and two capitals. It used
// to be stored, answered with a plain green "Off-site backup settings saved.",
// shown back in the box and badged "in force", while every run quietly used the
// remote written inside the script file. Where the customer had set that
// fallback to a real remote — and the setup guide tells them to — the copy went
// somewhere the administrator had not chosen and nothing in the portal said so.
//
// The value is still SAVED, deliberately: refusing it outright would lose the
// other five settings on the form, and somebody half way through setting this
// up may store a draft. What matters is that they are TOLD it will not be used.
$osGoodFields = ['destination' => 'rclone', 'rcloneRemote' => 'b2:portal-backups', 'keepWeekly' => '8',
    'keepMonthly' => '12', 'alertEmail' => 'backups@selftest.invalid', 'enabled' => '1'];
$osSaved = $osSave(array_merge($osGoodFields, ['rcloneRemote' => 'My Remote:backups', 'alertEmail' => 'not an address']));
brs_check(
    str_starts_with($osSaved['said'], '[warning]') === true,
    'saving a remote and an address this portal will not use answers with a WARNING, not a green "saved"',
    $osSaved['said']
);
brs_check(
    str_contains($osSaved['said'], 'The rclone remote will not be used because') === true
    && str_contains($osSaved['said'], 'a space or a capital letter in the wrong place') === true,
    'and says which setting and why, in the words of the one class that decides it',
    $osSaved['said']
);
brs_check(
    str_contains($osSaved['said'], 'The alert address will not be used because') === true,
    'and names the alert address separately, because two bad values are two problems',
    $osSaved['said']
);
brs_check(
    str_contains($osSaved['said'], 'fall back to the value written inside the script file') === true,
    'and says what a run will use instead, which is the part that makes it actionable',
    $osSaved['said']
);
brs_check(
    bps_setting('backup.offsite.rcloneRemote') === 'My Remote:backups'
    && bps_setting('backup.offsite.alertEmail') === 'not an address',
    'the values are still STORED: a warning is not a refusal, so the rest of the form is not lost',
    'remote "' . bps_setting('backup.offsite.rcloneRemote') . '" alert "' . bps_setting('backup.offsite.alertEmail') . '"'
);
// 🚫 And the page says it again every time it is opened, not only after a save:
//    a value can also arrive from a restored database or from SQL by hand.
brs_check(
    str_contains(bps_text($osSaved['body']), 'This will not be used, because it is not of the form name:path') === true,
    'the box itself carries a red "This will not be used, because ..." line under the stored remote',
    substr(bps_text($osSaved['body']), max(0, (int) strpos(bps_text($osSaved['body']), 'rclone remote')), 420)
);
brs_check(
    str_contains(bps_text($osSaved['body']), 'This will not be used, because it is not a plain e-mail address') === true,
    'and under the stored alert address'
);

// A remote starting with a dash is refused for its own reason, not a generic
// one: rclone would read it as an option rather than a destination.
$osSaved = $osSave(array_merge($osGoodFields, ['rcloneRemote' => '-X b2:elsewhere']));
brs_check(
    str_starts_with($osSaved['said'], '[warning]') === true
    && str_contains($osSaved['said'], 'starts with a dash') === true,
    'a remote starting with a dash is warned about for that reason, not the general one',
    $osSaved['said']
);

// And a good pair gets the plain success message, so the warning above is not
// simply what this form always says.
$osSaved = $osSave($osGoodFields);
brs_check(
    $osSaved['said'] === '[success] Off-site backup settings saved.',
    'a remote and an address this portal can use get the plain success message',
    $osSaved['said']
);
brs_check(
    str_contains(bps_text($osSaved['body']), 'This will not be used, because') === false,
    'and no red "will not be used" line is left anywhere on the page'
);

// -----------------------------------------------------------------------------
// O10. A stored "copies to keep" figure outside what the boxes allow.
// -----------------------------------------------------------------------------
// Prefilling a value its own input forbids makes the browser refuse to submit
// THE WHOLE FORM, so every other setting on the page becomes unsavable with no
// message saying why (#668). Such a figure can only have come from a restored
// database or from SQL by hand, which is why it is set here the same way.
//
// Both ends are tested in one go: 999 is above the weekly maximum and 0 is
// below the monthly minimum, and a clamp written one-sided would pass on one.
$osSet('backup.offsite.keepWeekly', '999');
$osSet('backup.offsite.keepMonthly', '0');
$page = $osPage('root');
brs_check(
    str_contains($page, 'name="keepWeekly" value="52"') === true,
    'a stored keepWeekly of 999 is shown in the box as 52, the most the box itself allows'
);
brs_check(
    str_contains($page, 'value="999"') === false,
    'and 999 is nowhere in the page, because a browser would refuse to submit the whole form with it there'
);
brs_check(
    str_contains($page, 'name="keepMonthly" value="1"') === true,
    'a stored keepMonthly of 0 is shown as 1, so the clamp works at the bottom end as well as the top'
);
brs_check(
    str_contains(bps_text($page), 'figure is outside what this page allows') === true,
    'and the page says plainly that a stored figure is outside what it allows',
    substr(bps_text($page), max(0, (int) strpos(bps_text($page), 'copies to keep')), 320)
);
// The total under the boxes is worked out from the clamped figures, which is
// what a run would really keep. 999 + 0 would read as 999.
brs_check(
    str_contains(bps_text($page), 'Added together: 53 copies') === true,
    'and the total under the boxes is 53, worked out from the clamped figures rather than the stored ones',
    substr(bps_text($page), max(0, (int) strpos(bps_text($page), 'Added together')), 160)
);
brs_check(
    bps_setting('backup.offsite.keepWeekly') === '999',
    'while the stored row is UNCHANGED: this is a display clamp, and a save-time cap is not a cap (#668)'
);
$osSet('backup.offsite.keepWeekly', '8');
$osSet('backup.offsite.keepMonthly', '12');
$page = $osPage('root');
brs_check(
    str_contains(bps_text($page), 'figure is outside what this page allows') === false
    && str_contains($page, 'name="keepWeekly" value="8"') === true,
    'with both figures back in range the warning is gone and the boxes show what is stored'
);

// -----------------------------------------------------------------------------
// O11. "Run now" no longer reports green success for a run that sent nothing.
// -----------------------------------------------------------------------------
// Exit code 0 stopped meaning "a copy went" the day the script learnt to read
// these settings: a run that is switched off sends nothing, records itself
// `skipped` and ends 0 on purpose, so that a hosting panel does not e-mail a
// failure every week for a feature somebody turned off. The handler read only
// the exit code, so it answered "Off-site sync completed in 1s." in GREEN for
// a run that packed nothing, encrypted nothing and sent nothing — and that is
// the state of EVERY FRESH INSTALLATION, because backup.offsite.enabled is
// seeded '0'. The first thing an administrator does after setting this up is
// press "Run now" to check it.
//
// WHAT THIS CAN AND CANNOT DRIVE, said plainly. The handler runs the real
// script through exec(), so the script here is a stand-in that only RECORDS a
// result — through the real log-offsite-result.php, so the row is written by
// the file customers use and not by this test. What that proves is the
// handler's reading of the row the run left behind, which is the part that was
// wrong. It does NOT prove anything about the shipped script's own decision to
// skip: that needs a command line and a program to send with, and nothing here
// has either. The stand-in deliberately does not call the reader, so the page
// around the flash says "ignored by the script" throughout O11; that is not
// what is being measured here and O2 to O5 cover it.
//
// 🚨 THE FAULT THIS SECTION FOUND, AND THE CHECK THAT NOW PINS IT.
//    The handler used to ask `SELECT status FROM tblOffsiteSyncLog ORDER BY
//    logID DESC LIMIT 1` — the newest row on the whole installation, with
//    nothing tying it to the run just made. So "recorded no run" really meant
//    "the log table is empty", and these checks passed only because this
//    section empties it first. Measured on 31 October 2026 by inserting ONE
//    older `success` row and pressing again with the same record-nothing
//    stand-in: the answer was "[success] Off-site sync completed in 0s." in
//    GREEN — the exact wording #671 set out to stop, for a run that packed
//    nothing and sent nothing. The same reading let a `skipped` or `failed` row
//    from last week decide this week's message.
//
//    It is FIXED: the handler reads MAX(logID) before exec() and asks only
//    about rows after it. O11b below is the check that would catch it coming
//    back, and it is the one check here that an empty table cannot satisfy —
//    which is exactly why the fault survived the first version of this
//    section.
$osStandIn = static function (string $body) use ($osScript): void {
    // PHP's own binary by name, not `php` on the PATH: the server process
    // inherits this run's environment, and a machine where `php` is not on the
    // PATH would make this look like a portal fault rather than a missing
    // program. Test only, and deleted with the scratch folder.
    file_put_contents($osScript, "#!/usr/bin/env bash\n"
        . "# Written by tools/backup-page-selftest.php for ONE run. Test only: it\n"
        . "# sends nothing anywhere and only records a result.\n"
        . "set -uo pipefail\n"
        . 'BACKUPS_DIR="$(cd "$(dirname "$0")" && pwd)"' . "\n"
        . 'PHP_BIN=' . escapeshellarg(PHP_BINARY) . "\n"
        . $body . "\n"
        . "exit 0\n");
    chmod($osScript, 0750);
};
$osRecordCall = static function (string $status): string {
    return '"${PHP_BIN}" "${BACKUPS_DIR}/log-offsite-result.php" --status=' . $status
        . ' --destination=rclone --triggered-by=selftest --snapshot=upgrade-20261030-000000'
        . ' --duration-sec=1 --output="a stand-in run that sent nothing" >/dev/null 2>&1 || true';
};

// 📝 Nothing recorded at all. The handler must not claim either way.
$osStandIn('echo "this run records nothing"');
brs_check($osRunCount() === 0, 'no run is recorded before the first press, so "nothing recorded" is really the state', 'rows: ' . $osRunCount());
$osRan = $osRunNow();
brs_check(
    str_starts_with($osRan['said'], '[warning]') === true,
    'a run that exits 0 but records nothing is answered with a WARNING, not green success',
    $osRan['said']
);
brs_check(
    str_contains($osRan['said'], 'recorded no run, so this page cannot confirm a copy was sent') === true,
    'and says plainly that the page cannot confirm a copy was sent, rather than guessing either way',
    $osRan['said']
);
brs_check(
    str_contains($osRan['said'], 'log-offsite-result.php') === true,
    'and names the file to check, because a missing recorder is the usual cause',
    $osRan['said']
);
brs_check(
    $osRunCount() === 0,
    'and no row was invented to fill the gap: the handler only records a FAILED run it can attribute to a non-zero exit',
    'rows: ' . $osRunCount()
);

// 🔘 A run that recorded itself `skipped`: switched off, so nothing was sent.
$osStandIn($osRecordCall('skipped'));
$osRan = $osRunNow();
brs_check(
    $osRunCount() === 1,
    'the stand-in really wrote its row through the shipped log-offsite-result.php',
    'rows: ' . $osRunCount() . ' (if 0, the recorder could not reach the portal from the command line)'
);
brs_check(
    str_starts_with($osRan['said'], '[warning]') === true
    && str_contains($osRan['said'], 'Nothing was sent: the off-site backup is switched off.') === true,
    'a run recorded as skipped is answered "Nothing was sent: the off-site backup is switched off.", in amber',
    $osRan['said']
);
brs_check(
    str_contains($osRan['said'], 'Off-site sync completed') === false,
    'and never says the sync completed, which is what it used to say for exactly this run',
    $osRan['said']
);
brs_check(
    str_contains($osRan['said'], 'Tick "Enabled"') === true,
    'and says what to do about it',
    $osRan['said']
);

// ✅ And a run that really recorded a success still gets the green message, so
//    the two warnings above are not simply what this button always says now.
Brs::$writer->query('DELETE FROM tblOffsiteSyncLog WHERE logID > ' . $osLogMark);
$osStandIn($osRecordCall('success'));
$osRan = $osRunNow();
brs_check(
    str_starts_with($osRan['said'], '[success] Off-site sync completed in') === true,
    'a run recorded as a success still gets the plain green "Off-site sync completed in Ns."',
    $osRan['said']
);
brs_check(
    $osRunCount() === 1,
    'and one row is on record for it',
    'rows: ' . $osRunCount()
);

// -----------------------------------------------------------------------------
// O11b. An OLDER run's row must never be read as this run's.
// -----------------------------------------------------------------------------
// The one case the checks above cannot reach, because they start from an empty
// table. Here the table is deliberately NOT empty: one older `success` row is
// already on file, exactly as it would be on any installation that has ever
// backed anything up. The stand-in then records nothing at all.
//
// The handler must still answer "cannot confirm", because nothing was recorded
// FOR THIS RUN. Before the fix it answered the older row's status — green,
// "Off-site sync completed" — for a run that sent nothing.
Brs::$writer->query('DELETE FROM tblOffsiteSyncLog WHERE logID > ' . $osLogMark);
$osInsertRun('2026-02-01 01:00:00', 'success', 'an older run, from before the one being pressed now');
brs_check(
    $osRunCount() === 1,
    'O11b one older success row is on file before the press, so "the table is empty" cannot be what makes this pass',
    'rows: ' . $osRunCount()
);
$osStandIn('echo "this run records nothing"');
$osRan = $osRunNow();
brs_check(
    str_contains($osRan['said'], 'Off-site sync completed') === false,
    'O11b an older run\'s success is NOT read as this run\'s: the answer never says the sync completed',
    $osRan['said']
);
brs_check(
    str_starts_with($osRan['said'], '[warning]') === true
    && str_contains($osRan['said'], 'recorded no run, so this page cannot confirm a copy was sent') === true,
    'O11b and it still says plainly that it cannot confirm a copy was sent',
    $osRan['said']
);
brs_check(
    $osRunCount() === 1,
    'O11b and the older row is left exactly as it was — nothing was added or changed',
    'rows: ' . $osRunCount()
);
Brs::$writer->query('DELETE FROM tblOffsiteSyncLog WHERE logID > ' . $osLogMark);

// -----------------------------------------------------------------------------
// O12. The standing warning is for every administrator, not only a global one.
// -----------------------------------------------------------------------------
// An administrator of one organisation can open this page and see the backup
// status; they cannot change the settings. Somebody reading "Last sync: never"
// deserves the same explanation of why, whichever kind of administrator they
// are.
$osAdminAnswer = bps_request($jar['admin'], 'GET', '/admin/maintenance/offsite-backup');
brs_check($osAdminAnswer['status'] === 200, 'an administrator of one organisation can open the off-site backup page', 'HTTP ' . $osAdminAnswer['status']);
brs_check(
    str_contains(bps_text($osAdminAnswer['body']), $osCommandLine) === true,
    'and the standing "needs a command line on the server" warning is shown to them too'
);

// 🧹 This section's own files, rows and settings, put back.
@unlink($osScript);
@unlink($osReader);
@unlink($osRecorder);
@unlink($osKey);
Brs::$writer->query('DELETE FROM tblOffsiteSyncLog WHERE logID > ' . $osLogMark);
foreach ($osBefore as $osSettingKey => $osStoredBefore) {
    $osSet($osSettingKey, $osStoredBefore);
}

// =============================================================================
// P. Rows a restore could not put back: the card, and the two buttons (#694)
// =============================================================================
// The class keeps the rows (proof 27 of tools/backup-restore-selftest.php).
// This is about the PAGE, over real HTTP: that the card appears only when
// something is waiting, that it says what is being kept without naming anybody,
// that both buttons really do what they say, and that the words an
// administrator reads mid-recovery are true.
//
// WHY THE CARD MATTERS AS MUCH AS THE ROWS. The owner decided on 31 October
// 2026 that the kept rows wait until somebody clears them, with no timer. So
// this card is the ONLY thing that stops them being forgotten. A page that kept
// the rows and did not mention them would be a holding table nobody looks in.
echo "\nP. Rows a restore could not put back (#694)\n";

$hrOrg     = 970950;
$hrSetting = 970951;
$hrUserA   = 970950;
$hrUserB   = 970951;
$hrWaiting = static fn (): int => (int) Brs::$writer->query('SELECT COUNT(*) FROM tblRestoreHeldRows WHERE putBackAtUtc IS NULL')->fetch_row()[0];
$hrSettingRows = static fn (): int => (int) Brs::$writer->query('SELECT COUNT(*) FROM tblSettings WHERE settingID = ' . $hrSetting)->fetch_row()[0];
$hrCodeRows    = static fn (): int => (int) Brs::$writer->query('SELECT COUNT(*) FROM tblTotpBackupCodes WHERE codeID >= 970950')->fetch_row()[0];
$hrPage        = static fn (): string => bps_request($jar['root'], 'GET', '/admin/maintenance/backup')['body'];

// P1. Nothing waiting: the page looks exactly as it did.
brs_check($hrWaiting() === 0, 'nothing is being kept to start with', 'waiting: ' . $hrWaiting());
$hrBlank = $hrPage();
brs_check(
    str_contains($hrBlank, 'Rows being kept for you') === false,
    'with nothing waiting the card is not on the page at all — the ordinary case looks untouched'
);

// 🧱 The condition. One made-up organisation with a setting of its own, two
//    made-up accounts with sign-in codes. No real person; the "codes" are not
//    codes and are never hashed, printed or compared.
Brs::$writer->query("INSERT INTO tblSites (siteID, siteName, siteKey, timezone) VALUES ($hrOrg, 'Held rows organisation', 'bps-p694', 'Europe/London')");
Brs::$writer->query(
    "INSERT INTO tblSettings (settingID, siteID, settingKey, settingValue, defaultValue, isSensitive, updatedAt) "
    . "VALUES ($hrSetting, $hrOrg, 'org.address.line1', 'The organisation''s own address', '', 0, '2026-01-02 00:00:00')"
);
Brs::$writer->query("INSERT INTO tblUsers (userID, emailAddress) VALUES ($hrUserA, 'bps-p694-a@example.invalid'), ($hrUserB, 'bps-p694-b@example.invalid')");
Brs::$writer->query(
    "INSERT INTO tblTotpBackupCodes (codeID, userID, codeHash, isUsed) VALUES "
    . "(970950, $hrUserA, 'not-a-real-hash-a1', 0), (970951, $hrUserA, 'not-a-real-hash-a2', 0), (970952, $hrUserB, 'not-a-real-hash-b1', 0)"
);
// 🔢 HOW MANY PER-ORGANISATION SETTINGS ROWS THERE ARE IS ASKED, NEVER TYPED.
//    Migration 231 (#677) used to give a fresh install one (the seeded 'GB'
//    country, copied down), which is the change #690 showed had quietly broken
//    two tests that had hard-coded "none"; since #942 the country is seeded
//    empty and a fresh install has none. The counts come from the database as it
//    stands, so they cannot go stale either way, and `>= 1` holds because this
//    section adds a row of its own just above.
$hrOrgSettings = (int) Brs::$writer->query('SELECT COUNT(*) FROM tblSettings WHERE siteID IS NOT NULL')->fetch_row()[0];
$hrOrgCount    = (int) Brs::$writer->query('SELECT COUNT(DISTINCT siteID) FROM tblSettings WHERE siteID IS NOT NULL')->fetch_row()[0];
// 🩹 A copy of those rows, kept so this section can mend the world it is about
//    to break. P6 DISCARDS them on purpose, which is the point of that check,
//    and brs_reset()-style comparison at the end of the section needs them
//    back. Taken as whole rows and written back column by column, so no value
//    is ever typed into this test: a row named here by hand would go stale the
//    day a migration changed it, which is the #690 shape.
$hrOrgRows = Brs::$writer->query('SELECT * FROM tblSettings WHERE siteID IS NOT NULL')->fetch_all(MYSQLI_ASSOC);
$hrMendOrgRows = static function () use ($hrOrgRows): int {
    $mended = 0;
    foreach ($hrOrgRows as $row) {
        // `siteScope` is worked out by the database and cannot be written.
        unset($row['siteScope']);
        $names  = array_keys($row);
        $insert = Brs::$writer->prepare(
            'INSERT IGNORE INTO tblSettings (`' . implode('`, `', $names) . '`) VALUES ('
            . implode(', ', array_fill(0, count($names), '?')) . ')'
        );
        $values = array_values($row);
        $insert->bind_param(str_repeat('s', count($values)), ...$values);
        $insert->execute();
        $mended += $insert->affected_rows > 0 ? 1 : 0;
        $insert->close();
    }
    return $mended;
};
brs_check(
    $hrOrgSettings >= 1 && $hrOrgCount >= 1,
    'the world holds ' . $hrOrgSettings . ' per-organisation settings row(s) across ' . $hrOrgCount . ' organisation(s), this section having added one of each'
);
// The log is settled first, so the comparison at the end of this section is
// about this section's rows and not about the lines the page writes for itself.
bps_settle();
$hrBefore = brs_fingerprint();

// A snapshot THAT DOES NOT SAY IT COVERS THE WHOLE DATABASE, which is the one
// thing that makes a restore hold the three tables back. Taken by pressing the
// page's own button, so it holds this section's fixture rows, and then its
// manifest's `whole_database` flag is removed — which is exactly the state
// every snapshot taken before 27 October 2026 is in.
//
// WHY NOT brs_legacySnapshot(), WHICH WRITES A REAL OLD-FORM SNAPSHOT. It is a
// frozen copy of the writer as it stood before #577, and that writer cannot
// encode a column holding raw bytes that are not text — which is the fault
// #577 fixed, and which is why the newer form exists. This database holds such
// rows by the time this section runs, so it dies with "Malformed UTF-8". Proof
// 27 of tools/backup-restore-selftest.php uses the real old writer, on a
// database where that is safe. The flag is what the restore reads, so this
// reaches the same code by the same door.
bps_press($jar['root'], ['action' => 'snapshot_now']);
$hrFolders = glob($backups . '/upgrade-*/_manifest.json') ?: [];
usort($hrFolders, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));
$hrSnapshot = (string) basename(dirname((string) (array_slice($hrFolders, -1)[0] ?? '')));
$hrManifestPath = $backups . '/' . $hrSnapshot . '/_manifest.json';
$hrManifest = json_decode((string) @file_get_contents($hrManifestPath), true);
brs_check(
    $hrSnapshot !== '' && is_array($hrManifest) === true && ($hrManifest['whole_database'] ?? null) === true,
    'a snapshot was taken from the page, and it says it covers the whole database',
    $hrSnapshot . ' ' . json_encode($hrManifest['whole_database'] ?? null)
);
unset($hrManifest['whole_database']);
file_put_contents($hrManifestPath, json_encode($hrManifest));
brs_check(
    ($hrManifest['whole_database'] ?? null) === null
    && isset(json_decode((string) file_get_contents($hrManifestPath), true)['whole_database']) === false,
    'and with that flag removed it is in the state every snapshot taken before 27 October 2026 is in'
);

// P2. The restore, pressed on the real page.
bps_settle();
$hrRestore = bps_message(bps_press($jar['root'], ['action' => 'restore_full', 'name' => $hrSnapshot])['body']);
brs_check(
    str_starts_with($hrRestore, '[warning] ') === true,
    'pressing Restore all on an old-form snapshot answers as a WARNING, not a plain success: something was lost along the way',
    $hrRestore
);
brs_check(
    str_contains($hrRestore, $hrOrgSettings . ' ' . \Portal\Core\DbBackup::CASCADE_RESCUE['tblSettings']['describe']) === true
    && str_contains($hrRestore, '3 ' . \Portal\Core\DbBackup::CASCADE_RESCUE['tblTotpBackupCodes']['describe']) === true,
    'and the answer names WHAT went, with a count, in words rather than only naming the table',
    $hrRestore
);
// 🧾 AND THE OLDER, WIDER SENTENCE IS STILL THERE. #694 ADDED the one above; it
//    did not replace this one, which counts every table outside the snapshot
//    that changed, including the ones that cannot be rescued. Pinning only one
//    of the two would pass with the other deleted.
brs_check(
    str_contains($hrRestore, 'NOT in this snapshot were changed as well') === true,
    'and the older sentence counting every changed table outside the snapshot is still there too',
    $hrRestore
);
brs_check(
    str_contains($hrRestore, 'being kept for you on the Backup page') === true
    && str_contains($hrRestore, 'Nothing removes') === true
    && str_contains($hrRestore, 'on a timer') === true,
    'and says where they are and that nothing will quietly delete them',
    $hrRestore
);
brs_check(
    $hrSettingRows() === 0 && $hrCodeRows() === 0 && $hrWaiting() === $hrOrgSettings + 3,
    'the cascade really took every one of those rows, and every one is being kept (' . ($hrOrgSettings + 3) . ')',
    'setting: ' . $hrSettingRows() . ', codes: ' . $hrCodeRows() . ', waiting: ' . $hrWaiting()
);
brs_check(bps_portalIsOpen() === true, 'and the portal was reopened', 'portal.maintenance.active = ' . bps_setting('portal.maintenance.active'));

// P3. The card itself.
$hrWithCard = $hrPage();
$hrWords    = bps_text($hrWithCard);
brs_check(
    str_contains($hrWithCard, 'Rows being kept for you') === true,
    'the card is now on the page'
);
// 🚫 AND IT MUST NOT TELL SOMEBODY THEIR SETTINGS ARE NOT IN THEIR BACKUP. The
//    card said "tables the backup does not cover", which for the snapshot this
//    section restores is FALSE — an older backup DOES contain these tables, and
//    the restore leaves them alone because each records what has already
//    happened. Nothing held this wording until the independent check.
// ONE test, not a disjunction. This was written as
//   str_contains($words, 'does not') === false
//   || str_contains($words, 'tables the backup does not cover') === false
// which works — the long phrase CONTAINS "does not", so the only way both
// clauses are false is the phrase being present — but the first clause can
// never be true while the second is false, so it is pure noise that makes the
// check LOOK like it tests two things. Say the one thing it tests.
brs_check(
    str_contains($hrWords, 'tables the backup does not cover') === false,
    'and does not claim the backup lacks those tables — false for the snapshot this section restores, because a table has to be IN a backup to be held back from it'
);
brs_check(
    str_contains($hrWords, 'could not') === true
    && str_contains($hrWords, 'records what has already happened') === true,
    'but says the RESTORE could not put them back, and why, which covers both reasons a table is not put back',
    substr($hrWords, max(0, (int) strpos($hrWords, 'Rows being kept')), 700)
);
brs_check(
    str_contains($hrWords, $hrOrgSettings . ' ' . \Portal\Core\DbBackup::CASCADE_RESCUE['tblSettings']['describe']) === true
    && str_contains($hrWords, '3 ' . \Portal\Core\DbBackup::CASCADE_RESCUE['tblTotpBackupCodes']['describe']) === true,
    'and it says what is being kept, in the same words as the restore\'s own answer, with a count',
    substr($hrWords, max(0, (int) strpos($hrWords, 'Rows being kept')), 900)
);
brs_check(
    str_contains($hrWithCard, '<code>tblSettings</code>') === true
    && str_contains($hrWithCard, '<code>tblTotpBackupCodes</code>') === true,
    'and names the table each group came out of, so somebody can match it against the restore\'s answer'
);
brs_check(
    str_contains($hrWords, 'Nothing removes') === true
    && str_contains($hrWords, 'on a timer') === true,
    'and promises in so many words that nothing will delete them while they wait — the promise the owner chose on 31 October 2026',
    substr($hrWords, max(0, (int) strpos($hrWords, 'Rows being kept')), 700)
);
// 🛟 #697. TWO POSITIVE CLAUSES AND NO ABSENCE, and the absence it used to
//    carry is worth recording rather than deleting silently.
//
//    It was `str_contains($hrWords, 'replaced by this') === false`, labelled
//    "and does not still say the opposite". It COULD NOT FAIL. `$hrWords` is a
//    tag-stripped GET of the backup page; the phrase it forbade came from
//    restoreAllWork()'s warning list, which is shown in the restore RESULT
//    ($hrRestore, a different variable, from a POST). And the card at HEAD said
//    nothing on the subject at all - this package ADDED the paragraph - so there
//    was never an opposite sentence on this page to guard against:
//      git show HEAD:web/_apps/admin/maintenance/backup.php | grep "replaced by this"
//    finds nothing. The fourth-clause-that-cannot-fail shape #694 shipped four
//    times, repeated in the package that wrote the lesson down. Found by the
//    independent check, not by me.
brs_check(
    str_contains($hrWords, 'does not throw these away') === true
    && str_contains($hrWords, 'read before anything is emptied') === true
    // 🏷️ And the ONE case where a row really does stop waiting is named on the
    //    card, because an unqualified promise here was the "check every page
    //    claim against the FALLBACK, not the happy path" rule broken in the
    //    package that cites it. This comment said "the two cases ... are on the
    //    card"; there is one, and the figure 20,000 appears nowhere in the
    //    page's text.
    && str_contains($hrWords, 'honours that backup') === true,
    'and says that putting another backup back will NOT lose them, which is the promise #697 made true',
    substr($hrWords, max(0, (int) strpos($hrWords, 'Rows being kept')), 900)
);
brs_check(
    str_contains($hrWithCard, 'name="action" value="put_held_back"') === true
    && str_contains($hrWithCard, 'name="action" value="discard_held"') === true,
    'and offers both buttons'
);
brs_check(
    str_contains($hrWords, $hrOrgCount . ' organisation(s)') === true
    && str_contains($hrWords, '2 people') === true,
    'and says HOW MANY organisations and people the rows belonged to',
    substr($hrWords, max(0, (int) strpos($hrWords, 'Rows being kept')), 900)
);
// 🔒 THE CHECK THAT MATTERS MOST HERE. A global administrator of the
//    installation may be a different person from the administrators of the
//    organisations on it (the owner, 29 October 2026), and a kept row's JSON
//    holds the WHOLE row — for a settings row that includes `settingValue`,
//    which can be anything the organisation has stored, `portal.support.email`
//    among the keys migration 231 copied down. The card must say what a row IS
//    and never what it says.
//
//    AN EARLIER VERSION OF THIS CHECK ALSO LOOKED FOR THE TWO ACCOUNTS'
//    E-MAIL ADDRESSES, and its comment claimed a kept code row carried the
//    owner's address. IT DOES NOT: `tblTotpBackupCodes` is
//    (codeID, userID, codeHash, isUsed, usedAt, createdAt) and has no e-mail
//    column, so those two clauses could never fail however broken the page was.
//    The independent check caught it. A clause that cannot fail is worse than
//    no clause, because the check LOOKS stronger than it is.
brs_check(
    str_contains($hrWithCard, 'not-a-real-hash') === false
    && str_contains($hrWithCard, 'The organisation&#039;s own address') === false
    && str_contains($hrWithCard, "The organisation's own address") === false,
    'and the card shows NOT ONE value out of the kept rows: no code hash and no stored setting value'
);
// 🏷️ What it DOES show for a settings row: the key. A key names what a setting
//    is without being a value, and an administrator judging what they have lost
//    needs it, because `describe` cannot be exact — the rescue takes every
//    per-organisation row whatever its key.
brs_check(
    str_contains($hrWithCard, 'org.address.line1') === true,
    'but it DOES name the settings keys that went, which is the one thing in such a row that says what it is without saying what it holds',
    substr($hrWords, max(0, (int) strpos($hrWords, 'Rows being kept')), 900)
);
// The second clause looks for the label the page would ACTUALLY use for another
// table ('Which ones: '), not for 'Settings:' followed by a code column — which
// could never match, because a names list for tblTotpBackupCodes would carry the
// other label. A clause that cannot fail for the case its name describes is the
// shape this package has already produced three times.
brs_check(
    str_contains($hrWithCard, 'Settings:') === true
    && str_contains($hrWithCard, 'Which ones: ') === false
    && str_contains($hrWithCard, 'codeHash') === false,
    'and no such list is drawn for the sign-in codes, which have nothing showable in them at all'
);

// P4. Put them back, one table at a time, from the page.
bps_settle();
$hrPut = bps_message(bps_press($jar['root'], ['action' => 'put_held_back', 'table' => 'tblSettings'])['body']);
brs_check(
    str_starts_with($hrPut, '[success] ' . $hrOrgSettings . ' kept row(s) put back into tblSettings.') === true,
    'pressing Put them back for the settings rows says how many went back',
    $hrPut
);
brs_check($hrSettingRows() === 1, 'and this section\'s own row is really in tblSettings again', 'setting: ' . $hrSettingRows());
// 📝 THE ACTIVITY LOG, read HERE rather than at the end of the section: these
//    lines are the ones that press wrote, and bps_settle() clears them, so
//    asking later would be asking about somebody else's press.
$hrLines = implode(' | ', array_values(bps_newLines()));
brs_check(
    str_contains($hrLines, 'Kept rows put back into tblSettings') === true,
    'and the press is recorded in the activity log, saying which table and how many',
    $hrLines
);
brs_check(
    str_contains($hrLines, 'not-a-real-hash') === false
    && str_contains($hrLines, 'The organisation') === false
    && str_contains($hrLines, 'org.address.line1') === false,
    'and nothing it records quotes a value out of a kept row, nor even the key — a record of what happened needs the table and the count, nothing more',
    $hrLines
);
bps_settle();
$hrAfterOne = $hrPage();
brs_check(
    str_contains($hrAfterOne, '<code>tblSettings</code>') === false
    && str_contains($hrAfterOne, '<code>tblTotpBackupCodes</code>') === true,
    'the card no longer offers that table, and still offers the other'
);
$hrPutCodes = bps_message(bps_press($jar['root'], ['action' => 'put_held_back', 'table' => 'tblTotpBackupCodes'])['body']);
bps_settle();
brs_check(
    str_starts_with($hrPutCodes, '[success] 3 kept row(s) put back into tblTotpBackupCodes.') === true,
    'and the three sign-in codes go back too',
    $hrPutCodes
);
brs_check(
    str_contains($hrPage(), 'Rows being kept for you') === false,
    'with nothing left waiting the card disappears again'
);
// 📌 The record of what happened survives the put-back: a row that went back is
//    MARKED, not deleted, so the holding table still says it was kept and
//    returned. Asserted before it is cleared for the comparison below, or
//    clearing it would hide a put-back that had silently deleted the record.
brs_check(
    $hrWaiting() === 0
    && (int) Brs::$writer->query('SELECT COUNT(*) FROM tblRestoreHeldRows WHERE putBackAtUtc IS NOT NULL')->fetch_row()[0] === $hrOrgSettings + 3,
    'every kept row is marked as put back rather than deleted, so the record of what happened survives',
    'waiting: ' . $hrWaiting()
);
brs_clearHeldRows();
// 🎯 Not "a row with the right id is there" but every value of every table as
//    it was before the restore. A row put back with a changed value, a lost
//    column or a re-stamped date fails here and nowhere else — that is how the
//    re-stamped `updatedAt` of #694 was found.
brs_checkSame($hrBefore, 'and every value of every table is exactly as it was before the restore');

// P5. The mixed answer: a row that cannot go back is SAID so, and stays.
bps_settle();
bps_press($jar['root'], ['action' => 'restore_full', 'name' => $hrSnapshot]);
bps_settle();
brs_check($hrWaiting() === $hrOrgSettings + 3, 'a second restore keeps the same rows again', 'waiting: ' . $hrWaiting());
// 🧷 The whole row is kept and put back column by column, because tblUsers has
//    `createdAt DEFAULT CURRENT_TIMESTAMP`: deleting the row and inserting it
//    again by id alone would give it a NEW created date, and the comparison at
//    the end of this section would read that as a change this section had made.
$hrUserRow = Brs::$writer->query('SELECT * FROM tblUsers WHERE userID = ' . $hrUserB)->fetch_assoc();
$hrPutUserBack = static function () use ($hrUserRow): void {
    $names  = array_keys($hrUserRow);
    $insert = Brs::$writer->prepare(
        'INSERT INTO tblUsers (`' . implode('`, `', $names) . '`) VALUES ('
        . implode(', ', array_fill(0, count($names), '?')) . ')'
    );
    $values = array_values($hrUserRow);
    $insert->bind_param(str_repeat('s', count($values)), ...$values);
    $insert->execute();
    $insert->close();
};
Brs::$writer->query('DELETE FROM tblUsers WHERE userID = ' . $hrUserB);
$hrMixed = bps_message(bps_press($jar['root'], ['action' => 'put_held_back', 'table' => 'tblTotpBackupCodes'])['body']);
brs_check(
    str_starts_with($hrMixed, '[warning] 2 kept row(s) put back into tblTotpBackupCodes.') === true
    && str_contains($hrMixed, '1 could not be put back and are still being kept, so nothing has been lost') === true,
    'with one account missing, the page says two went back and one is still being kept — never "done" and never "lost"',
    $hrMixed
);
brs_check(
    str_contains($hrMixed, 'likeliest reason') === true,
    'and it says the likeliest reason rather than asserting one: a row already present fails the same way and the two cannot be told apart from here',
    $hrMixed
);
brs_check(
    str_contains($hrPage(), '<code>tblTotpBackupCodes</code>') === true,
    'and the card still offers that table, because one row is still waiting'
);
$hrPutUserBack();

// P6. Discard: it does NOT put anything back, and says so.
bps_settle();
$hrDiscard = bps_message(bps_press($jar['root'], ['action' => 'discard_held', 'table' => 'tblSettings'])['body']);
brs_check(
    str_contains($hrDiscard, 'kept row(s) for tblSettings discarded') === true
    && str_contains($hrDiscard, 'They cannot be recovered') === true,
    'Discard says what it did and that it cannot be undone',
    $hrDiscard
);
brs_check(
    $hrSettingRows() === 0,
    'and it really did NOT put the row back: Discard is the deliberate "I accept this loss" button',
    'setting: ' . $hrSettingRows()
);

// P7. A table this portal does not keep rows for.
bps_settle();
$hrNotKept = bps_message(bps_press($jar['root'], ['action' => 'put_held_back', 'table' => 'tblEvents'])['body']);
brs_check(
    str_starts_with($hrNotKept, '[danger] This portal does not keep rows for tblEvents.') === true,
    'a posted request naming a table that is not on DbBackup::CASCADE_RESCUE is refused by name — that allow-list is what makes the name safe to put in a statement at all',
    $hrNotKept
);
// 📝 AND THE POSTED NAME IS NOT WRITTEN INTO THE ACTIVITY LOG. It was, under an
//    event type saying a put-back had been attempted and failed, so the log
//    read "Putting kept rows back into <anything you posted> failed". The
//    discard branch was fixed for exactly this and the put-back branch was not.
$hrRefusedLines = implode(' | ', array_keys(bps_newLines())) . ' :: ' . implode(' | ', array_values(bps_newLines()));
// ✅ THE POSITIVE CLAUSE FIRST, because the other two are absences and a check
//    made only of absences passes when the thing stops happening at all:
//    delete the $record() call and "recorded without the name" stayed green.
brs_check(
    str_contains($hrRefusedLines, 'BackupActionRefused') === true
    && str_contains($hrRefusedLines, 'tblEvents') === false
    && str_contains($hrRefusedLines, 'BackupHeldRowsPutBackFailed') === false,
    'and the refusal IS recorded, without the posted name, and not as a failed put-back',
    $hrRefusedLines
);
bps_settle();
$hrDiscardUnknown = bps_message(bps_press($jar['root'], ['action' => 'discard_held', 'table' => 'tblEvents'])['body']);
brs_check(
    str_starts_with($hrDiscardUnknown, '[danger] This portal does not keep rows for tblEvents.') === true,
    'and Discard answers the same way rather than "0 kept row(s) for tblEvents discarded. They cannot be recovered."',
    $hrDiscardUnknown
);
$hrRefusedLines2 = implode(' | ', array_keys(bps_newLines())) . ' :: ' . implode(' | ', array_values(bps_newLines()));
brs_check(
    str_contains($hrRefusedLines2, 'BackupActionRefused') === true
    && str_contains($hrRefusedLines2, 'tblEvents') === false,
    'and that refusal is recorded too, naming no table',
    $hrRefusedLines2
);
bps_settle();
$hrInjection = bps_message(bps_press($jar['root'], ['action' => 'put_held_back', 'table' => 'tblSettings; DROP TABLE tblUsers'])['body']);
brs_check(
    str_contains($hrInjection, 'does not keep rows for') === true
    && (int) Brs::$writer->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tblUsers'")->fetch_row()[0] === 1,
    'and a name with a statement glued on the end is simply not on the list, so it never reaches SQL',
    $hrInjection
);

// P8. No form token, nothing happens.
$hrWaitingNow = $hrWaiting();
$hrNoToken    = bps_message(bps_press($jar['root'], ['action' => 'discard_held', 'table' => 'tblTotpBackupCodes'], 'not-the-token')['body']);
brs_check(
    str_contains($hrNoToken, 'Invalid CSRF token') === true && $hrWaiting() === $hrWaitingNow,
    'a press without the right form token is refused and discards nothing',
    $hrNoToken . ' waiting: ' . $hrWaiting()
);

// 🧹 This section's rows, snapshot and held rows, put back. The settings rows
//    the Discard above threw away are written again from the fingerprint's own
//    world: this section discarded them on purpose and must mend what it broke.
bps_press($jar['root'], ['action' => 'put_held_back', 'table' => 'tblTotpBackupCodes']);
brs_clearHeldRows();
$hrMended = $hrMendOrgRows();
brs_check(
    $hrMended === $hrOrgSettings,
    'the ' . $hrOrgSettings . ' settings row(s) this section DISCARDED on purpose all needed mending by hand (' . $hrMended . ') — if any had not, the Discard above had stopped discarding',
    'mended: ' . $hrMended . ' of ' . $hrOrgSettings
);
bps_settle();
brs_checkSame($hrBefore, 'the database is back to how this section found it, and the holding table is empty');
Brs::$writer->query('DELETE FROM tblTotpBackupCodes WHERE codeID >= 970950');
Brs::$writer->query('DELETE FROM tblUsers WHERE userID >= 970950');
Brs::$writer->query('DELETE FROM tblSettings WHERE settingID = ' . $hrSetting);
Brs::$writer->query('DELETE FROM tblSites WHERE siteID = ' . $hrOrg);
brs_removeFolder($backups . '/' . $hrSnapshot);

// =============================================================================
$failed = Brs::$proofOk === false;
bps_cleanup();
echo "\n" . str_repeat('=', 78) . "\n";
printf("%d check(s) made. %s\n", Brs::$checks, $failed === true ? 'AT LEAST ONE FAILED.' : 'All passed.');
echo "The test's rows, accounts and scratch folder were removed.\n";
echo "REACHED THE END\n";
exit($failed === true ? 1 : 0);
