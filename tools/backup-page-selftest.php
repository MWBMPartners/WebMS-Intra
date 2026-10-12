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
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/578
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// Loaded for its functions (the independent reader, the made-up rows, the
// planted bad row). It returns before its own run when it is not the script
// being run.
require __DIR__ . '/backup-restore-selftest.php';

const BPS_ROOT   = 918001;   // a global administrator
const BPS_ADMIN  = 918002;   // an administrator of one organisation only
const BPS_MEMBER = 918003;
const BPS_MIGRATION = '999_backup_page_selftest.sql';
/** The activity-log lines the backup pages write themselves. */
const BPS_OWN_LINES = ['BackupSnapshotChecked', 'BackupRestored', 'BackupRestoreFailed', 'BackupTableRestored', 'BackupActionRefused'];

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
$failed = Brs::$proofOk === false;
bps_cleanup();
echo "\n" . str_repeat('=', 78) . "\n";
printf("%d check(s) made. %s\n", Brs::$checks, $failed === true ? 'AT LEAST ONE FAILED.' : 'All passed.');
echo "The test's rows, accounts and scratch folder were removed.\n";
echo "REACHED THE END\n";
exit($failed === true ? 1 : 0);
