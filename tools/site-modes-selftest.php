<?php
// Path: tools/site-modes-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Which organisation, on the REAL pages — self-test 🌐🚪 (#502, #511)
 * -----------------------------------------------------------------------------
 * One installation can serve several organisations and tells them apart in
 * ONE of three ways: by the sign-in session, by each organisation's own
 * address, or by the first part of the address. This file runs the portal
 * and asks it, once in each of the three ways and once with one organisation
 * only, with real sign-ins and real cookies.
 *
 * It uses the page-driving harness of `tools/access-pages-selftest.php`: it
 * copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file, starts PHP's built-in web server on it behind a small
 * router that imitates `.htaccess`, signs real accounts in through the real
 * sign-in page, and sends real requests.
 *
 * WHAT IT CHECKS
 * --------------
 *   S. Sign-in session (#502). After signing in a person is in one of their
 *      OWN organisations, never the default one by accident; a person with
 *      several is sent to the chooser and sees nothing else until they
 *      choose; switching is honoured on the next request and only a real
 *      switch is logged; nobody can switch into an organisation that is not
 *      theirs; somebody taken out of an organisation is out of it on their
 *      next click; a global administrator is offered every organisation; an
 *      account in no organisation is shown nothing; the chooser works on a
 *      database that has not run migration 212 yet, and a global
 *      administrator can reach the upgrade page before choosing; a
 *      switched-off account's session is ended (the access package's part K, in this way too); the
 *      set-up page and its refusals, including "first part of the address"
 *      marked not ready, in BOTH states of Site::FIRST_PART_MODE_READY; the
 *      organisation save handler's address rules; the general settings
 *      editor's lock, on changing, adding AND deleting; and a command-line
 *      tool starts no session.
 *      Added after the independent check of 12 October 2026, which removed
 *      seven safety rules one at a time and found every check still passed:
 *      a membership row that is SWITCHED OFF (not deleted) no longer counts;
 *      a switched-off organisation is not offered and does not answer at its
 *      address; an organisation left in the session by whoever used the
 *      browser before is dropped at sign-in; the portal's start-up alone
 *      gives no session to a request that sent no cookie; a session that
 *      changes between the early look and the real start is refused; a
 *      look-up that fails shows "try again" (and, in this way, writes one
 *      line to PHP's error log). And the save handler's two new refusals:
 *      the last active organisation cannot be switched off, and an active
 *      organisation cannot be left with no address while own addresses are
 *      in use.
 *   E. The web server's own error page (/error.php), which is reached
 *      without the gate: a member sees the usual page, and anybody the gate
 *      would stop sees a plain page with no menu and no organisation's name.
 *   H. Own address each. A port, capitals and a trailing dot still match;
 *      the switcher and the chooser link to the other organisation's address.
 *   P. First part of the address. The switcher, the chooser and every
 *      redirect of the switch handler keep the organisation's part.
 *   G. The members-only gate (#511), in H and P and in S: a signed-in person
 *      who is not a member of the organisation the address opens gets the
 *      refusal page on EVERY address of it, the data addresses under /api/
 *      included, with none of that organisation's words or numbers in the
 *      answer. Three cases are named: a member of A asking B's
 *      `/api/attendance/list`, B's `/attendance`, and an internal event of B
 *      through `/api/events/detail` (the case #511 proved). Each has a
 *      control beside it: B's own member gets those words, so "none of B's
 *      words" cannot pass on an empty page. Members, a global administrator,
 *      an API key and signed-out visitors are unchanged; an account in no
 *      organisation is refused everywhere and told to ask an administrator.
 *   O. One organisation only (the ordinary installation): no chooser, no
 *      switcher, and the gate does nothing.
 *   L. The language a page is shown in is the same in all three ways.
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Every row it makes is numbered 917000 to 917999 or belongs
 *   to one of its three made-up organisations; they are removed at the START
 *   (in case an earlier run was interrupted) and at the END.
 * - It changes the two portal-wide settings `multisite.enabled` and
 *   `multisite.detectionMode` many times, and puts them back. So do NOT run
 *   it at the same moment as another self-test on the same database.
 * - For one check it gives the default organisation (number 1) an address,
 *   and takes it away again; for another it takes the chooser's row out of
 *   the address table and puts it back. The clean-up does both too if the
 *   run stops early.
 * - In its SCRATCH COPY only, it switches Site::FIRST_PART_MODE_READY on for
 *   a few checks and off again. The repository's file is never written.
 * - It RENAMES TWO TABLES AWAY, each for one request, to make a look-up fail
 *   (`tblUserSites` and `tblSites`, to the same name with `_selftest_away`
 *   on the end), and renames them straight back. While `tblSites` is away a
 *   VIEW of that name stands in for it, which answers for every organisation
 *   but one of the test's own. It switches its own three
 *   organisations off and on. It asks the portal to switch the DEFAULT
 *   organisation off and to delete the two portal-wide settings, expecting
 *   a refusal each time; if the portal wrongly agrees, the test puts them
 *   back at once. The clean-up does all of this too, and the renamed tables
 *   are also put back at the very START of a run.
 * - It writes one session file by hand, in its own scratch folder, and adds
 *   two test-only extras to its router (see the router's own comments).
 *   Neither exists in the portal.
 * - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *   temporary folder). It refuses if that folder contains the repository or
 *   is inside it. The child is deleted at the end only if it still holds
 *   this run's marker. `web/_auth_keys` is never read or copied.
 * - The server is started with its command as a list (no shell), so the
 *   process number is the server's own, and it is stopped by that number,
 *   never by a name. It refuses to start if anything already answers on its
 *   port. Every answer must carry this run's header.
 * - The clean-up also runs from a shutdown function, so a crash part way
 *   still puts things back. WHAT IT CANNOT SURVIVE: being killed outright.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - Apache: the router only imitates `.htaccess`.
 * - A real browser. In "own address each" a browser keeps each address's
 *   cookies apart; this test sends one cookie to two host names on purpose,
 *   which is the harder case. It cannot show what a browser displays, or
 *   how a page looks at any screen width.
 * - Sign-in through Microsoft, Google or a passkey: they need outside
 *   servers. All of them end in the same Auth::setSessionSiteId().
 * - A real race between two requests, or a real database fault. A session
 *   that changes between the early look and the real start is imitated by
 *   this test's own router (S61); a failed look-up by renaming a table away
 *   or standing a view in for it (S64, H10). And the real way an organisation
 *   is left in a session, somebody giving up half way through two-step
 *   sign-in, needs an authenticator: S60 writes the left-over itself.
 * - That a switched-off organisation stops answering at its PREFIX, in
 *   "first part of the address". H07 checks its own address only.
 * - Two organisations that already share an address: the database's own
 *   rule forbids making that state here. Migration 212 and the set-up
 *   page's refusal for it are checked by hand on a database built for it.
 * - That the installation is byte for byte unchanged with one organisation:
 *   that needs the code from BEFORE the change beside it, so it is a
 *   separate comparison, not part of this file.
 * - MariaDB.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/site-modes-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9461). The database must already
 *         hold `web/_sql/full_schema.sql`. Needs PHP's curl extension and
 *         `proc_open()`.
 * Exit:   0 only when every check ran and passed; 1 otherwise, including
 *         every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/502
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/511
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 136;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
$repo      = (string) realpath(dirname(__DIR__));
$webSrc    = $repo . DIRECTORY_SEPARATOR . 'web';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR) === true
    || str_starts_with($parent . DIRECTORY_SEPARATOR, $repo . DIRECTORY_SEPARATOR) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9461 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$db = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
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
const ORG_A  = 917001;
const ORG_B  = 917002;
const ORG_C  = 917003;
const KEY_A  = 'selftest-modes-a';
const KEY_B  = 'selftest-modes-b';
const KEY_C  = 'selftest-modes-c';
const HOST_A = 'a.modes.selftest.invalid';
const HOST_B = 'b.modes.selftest.invalid';
const HOST_C = 'c.modes.selftest.invalid';
const HOST_1 = 'one.modes.selftest.invalid'; // lent to the default organisation for one check
const NAME_A = 'Modes Selftest Alpha';
const NAME_B = 'Modes Selftest Bravo';
const NAME_C = 'Modes Selftest Charlie';
/** The people. Nobody but U1 belongs to the default organisation (number 1). */
const P  = 917011; // member of A and B
const Q  = 917012; // member of A only
const R  = 917013; // a site administrator of B only: the control who MAY see B's data
const G  = 917014; // a global administrator with no membership row anywhere
const N  = 917015; // an active account that belongs to no organisation
const X  = 917016; // member of A; switched off while signed in (its next request is a GET)
const X2 = 917017; // member of A; switched off while signed in (its next request is a POST)
const U1 = 917018; // member of the default organisation only
const PX = 917019; // member of A and B; taken out of B while signed in
const P3 = 917020; // member of A, B and C; taken out of B while signed in
const L  = 917021; // member of A whose saved language is Welsh
/** B's records. Every text carries MODESB, and the head count is a number nothing else prints. */
const EV_B        = 917401; // B: an INTERNAL, published event (the case #511 proved)
const SERVICE_B   = 917601;
const SESSION_B   = 917611;
const B_HEADCOUNT = 7919;

/**
 * Does an answer hold anything of organisation B's? Its name, a record's text, or its head count.
 *
 * The head count must stand as a number of its own: not inside a longer run
 * of letters and digits. It used to be looked for anywhere in the page, and
 * every page carries a form token of 64 random letters and digits, so about
 * one page in a thousand held "7919" by chance. With some fifty pages
 * searched, a run on unchanged, correct code failed now and then (seen once
 * in five runs on 12 October 2026, on check GS01, with nothing of B's on the
 * page). A head count printed in a page or in JSON is never glued to other
 * letters or digits, so nothing real is missed.
 */
function bWords(string $body): string
{
    $found = [];
    foreach (['MODESB', NAME_B] as $word) {
        if (str_contains($body, $word) === true) {
            $found[] = $word;
        }
    }
    foreach ([(string) B_HEADCOUNT, number_format(B_HEADCOUNT)] as $number) {
        if (preg_match('/(?<![0-9A-Za-z])' . preg_quote($number, '/') . '(?![0-9A-Za-z])/', $body) === 1) {
            $found[] = $number;
        }
    }

    return implode(', ', $found);
}

/** The name a table is given while it is renamed away for one request. */
function awayName(string $table): string
{
    return $table . '_selftest_away';
}

/**
 * Put back a table this test renamed away, if a run stopped in that moment.
 * It renames only a table carrying this test's own suffix, and only when no
 * real table has its name (a stand-in VIEW of that name is dropped first),
 * so it cannot touch anything else.
 */
function putTablesBack(): void
{
    foreach (['tblUserSites', 'tblSites'] as $table) {
        $here = array_column(q('SELECT TABLE_NAME AS t, TABLE_TYPE AS kind FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)', 'ss', [$table, awayName($table)]), 'kind', 't');
        if (isset($here[awayName($table)]) === false) {
            continue;
        }
        if (($here[$table] ?? '') === 'VIEW') {
            q('DROP VIEW ' . $table);
            unset($here[$table]);
        }
        if (isset($here[$table]) === false) {
            q('RENAME TABLE ' . awayName($table) . ' TO ' . $table);
        }
    }
}

/**
 * Send one request while a look-up cannot be made. Everything is back as it
 * was before this returns, whatever happens.
 *
 * `$what` is which look-up:
 *   'memberships'  `tblUserSites` is renamed away, so "which organisations
 *                  is this person in?" fails.
 *   'address'      "which organisation holds this address?" fails, and
 *                  nothing else does. `tblSites` cannot simply be renamed
 *                  away for that: start-up reads the default organisation's
 *                  row from it a moment later and would stop with a server
 *                  error before the gate was ever asked. So a VIEW stands in
 *                  for the table, the same in every column except that
 *                  reading organisation C's ADDRESS raises a database error.
 *                  A search of every address trips over it; reading any one
 *                  other organisation's row does not. That is the shape of a
 *                  real passing fault: one query fails, the next works.
 *
 * Returns the answer, and what the portal wrote to PHP's error log meanwhile.
 * That text is then taken out of the log again, and the error rows written
 * meanwhile are deleted, so the last check of the run ("the portal logged no
 * PHP warning or error") still means what it says for every other request.
 *
 * @return array{answer:array{status:int, location:string, body:string, head:string}, logged:string}
 */
function reqWhileFailing(string $what, string $jar, string $path, array $o = []): array
{
    global $child, $sep;
    $logFile = $child . $sep . 'php-errors.log';
    clearstatcache();
    $logSize = is_file($logFile) === true ? (int) filesize($logFile) : 0;
    $lastRow = (int) (one('SELECT COALESCE(MAX(errorID), 0) AS n FROM tblErrors')['n'] ?? 0);
    $table   = $what === 'address' ? 'tblSites' : 'tblUserSites';
    $columns = array_column(q('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', 's', [$table]), 'c');
    try {
        q('RENAME TABLE ' . $table . ' TO ' . awayName($table));
        if ($what === 'address') {
            // JSON_EXTRACT() of a text that is not JSON (the organisation's key) is an error, not an empty answer.
            $list = array_map(static fn (string $c): string => $c === 'hostPattern' ? 'IF(siteID = ' . ORG_C . ", JSON_UNQUOTE(JSON_EXTRACT(siteKey, '$')), hostPattern) AS hostPattern" : $c, $columns);
            q('CREATE VIEW ' . $table . ' AS SELECT ' . implode(', ', $list) . ' FROM ' . awayName($table));
        }
        $answer = req($jar, 'GET', $path, null, $o);
    } finally {
        putTablesBack();
    }
    clearstatcache();
    $logged = is_file($logFile) === true ? (string) substr((string) file_get_contents($logFile), $logSize) : '';
    if (is_file($logFile) === true) {
        $handle = fopen($logFile, 'r+');
        ftruncate($handle, $logSize);
        fclose($handle);
    }
    q('DELETE FROM tblErrors WHERE errorID > ?', 'i', [$lastRow]);

    return ['answer' => $answer, 'logged' => $logged];
}

/** Is this the PLAIN error page of web/public_html/error.php: no menu, and its own small card? */
function plainErrorPage(array $answer): bool
{
    return str_contains($answer['body'], 'portal-navbar') === false && str_contains($answer['body'], '<div class="card"><div class="code">') === true;
}

function removeWorld(): void
{
    putTablesBack();
    q('DELETE c FROM tblAttendanceCounts c JOIN tblAttendanceSessions s ON s.sessionID = c.sessionID WHERE s.siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
    q('DELETE FROM tblAttendanceSessions WHERE siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
    q('DELETE FROM tblAttendanceServiceTypes WHERE siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
    q('DELETE FROM tblEvents WHERE siteID IN (?, ?, ?) OR eventID BETWEEN 917000 AND 917999', 'iii', [ORG_A, ORG_B, ORG_C]);
    q('DELETE FROM tblApiKeys WHERE siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
    q('DELETE FROM tblUserRoles WHERE siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
    q('DELETE FROM tblRoles WHERE siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
    q('DELETE FROM tblActivityLogs WHERE userID BETWEEN 917000 AND 917999 OR siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
    q('DELETE FROM tblErrors WHERE userID BETWEEN 917000 AND 917999 OR siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
    q('DELETE FROM tblUserSites WHERE siteID IN (?, ?, ?) OR userID BETWEEN 917000 AND 917999', 'iii', [ORG_A, ORG_B, ORG_C]);
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 917000 AND 917999');
    q('DELETE FROM tblUsers WHERE userID BETWEEN 917000 AND 917999');
    q('DELETE FROM tblSettings WHERE siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
    q("DELETE FROM tblSettings WHERE settingKey = 'selftest.modes.deleteme'");
    q('DELETE FROM tblSites WHERE siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
    // The default organisation, if the portal wrongly agreed to switch it off (check S61) and the run stopped there.
    q('UPDATE tblSites SET isActive = 1 WHERE siteID = 1 AND isActive = 0');
    q("DELETE FROM tblOffsiteSyncLog WHERE destination = 'selftest-modes'");
    // The chooser's address row, if a run stopped in the moment it was taken away (check S56).
    q("INSERT INTO tblRoutes (routeKey, targetFile, isProtected) VALUES ('site/choose', 'site/choose.php', 1) ON DUPLICATE KEY UPDATE targetFile = VALUES(targetFile)");
    // The address lent to the default organisation, if a run stopped while it was lent.
    q('UPDATE tblSites SET hostPattern = NULL WHERE siteID = 1 AND hostPattern = ?', 's', [HOST_1]);
}

/** One organisation's own value for a setting (replacing any it had). */
function siteSetting(int $site, string $key, string $value): void
{
    q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [$site, $key]);
    q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss', [$site, $key, $value, $value]);
}

/** Set how organisations are told apart, straight in the two portal-wide rows. */
function setWay(bool $on, string $way): void
{
    q("UPDATE tblSettings SET settingValue = ? WHERE settingKey = 'multisite.enabled' AND siteID IS NULL", 's', [$on === true ? 'true' : 'false']);
    q("UPDATE tblSettings SET settingValue = ? WHERE settingKey = 'multisite.detectionMode' AND siteID IS NULL", 's', [$way]);
}

/** The way as stored now: "off", or the stored way. */
function storedWay(): string
{
    $on  = (string) (one("SELECT settingValue FROM tblSettings WHERE settingKey = 'multisite.enabled' AND siteID IS NULL")['settingValue'] ?? '');
    $way = (string) (one("SELECT settingValue FROM tblSettings WHERE settingKey = 'multisite.detectionMode' AND siteID IS NULL")['settingValue'] ?? '');

    return ($on === 'true' ? 'on' : 'off') . '/' . $way;
}

/** How many activity-log rows of one kind an account has. */
function logged(int $user, string $kind): int
{
    return (int) (one('SELECT COUNT(*) AS n FROM tblActivityLogs WHERE userID = ? AND activityType = ?', 'is', [$user, $kind])['n'] ?? -1);
}

// =============================================================================
// 🧰 The server, and one request
// =============================================================================
$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'started' => false, 'cleaned' => false];

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Put back everything this run changed, ONCE. Also registered as a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    // The server, by the process proc_open() gave — never by a name.
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        $until = microtime(true) + 5;
        while (proc_get_status($state['proc'])['running'] === true && microtime(true) < $until) {
            usleep(50000);
        }
        if (proc_get_status($state['proc'])['running'] === true) {
            proc_terminate($state['proc'], 9);
        }
        proc_close($state['proc']);
        $state['proc'] = null;
    }
    try {
        foreach ($state['settings'] as $key => $before) {
            if ($before === null) {
                q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]);
            } else {
                q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$before['settingValue'], (int) $before['settingID']]);
            }
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    // The scratch child: only the one this run made, and only while it still holds this run's marker.
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.site-modes-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else
 * and is returned as status -1, which no check expects.
 *
 * `$o['host']` sends that host name instead of the server's own (how a
 * request to an organisation's own address arrives); `$o['bearer']` sends an
 * API key. Every request says it prefers English, so the language check has
 * something fixed to compare a saved language with.
 *
 * `$o['cookie']` sends that cookie text and no jar. It exists for one case:
 * a sign-in made at organisation A's own address, presented at B's. curl,
 * like a browser, will not send a jar's cookie to a different host name, so
 * the test has to hand it over itself. That is the harder case for the gate.
 *
 * `$o['secondRead']` asks the router for its test-only extra 1 (see the
 * router): the session id whose stored data PHP is handed on its second read
 * of the session, or "nobody" for an empty one.
 *
 * @param array<string,mixed>|null $post
 * @param array{host?:string, bearer?:string, cookie?:string, secondRead?:string} $o
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null, array $o = []): array
{
    global $port, $state;
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Expect:', 'Accept-Language: en'];
    if (($o['host'] ?? '') !== '') {
        $headers[] = 'Host: ' . $o['host'];
    }
    if (($o['bearer'] ?? '') !== '') {
        $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    }
    if (($o['cookie'] ?? '') !== '') {
        $headers[] = 'Cookie: ' . $o['cookie'];
    }
    if (($o['secondRead'] ?? '') !== '') {
        $headers[] = 'X-Site-Modes-Second-Read: ' . $state['token'] . ' ' . $o['secondRead'];
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
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
    if (preg_match('/^X-Site-Modes-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
    ];
}

/** The session cookie a jar holds, as "name=value", to be sent by hand to another host name (see req()). */
function cookieOf(string $jar): string
{
    foreach (file($jar, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $parts = explode("\t", $line);
        if (count($parts) === 7 && $parts[5] === session_name()) {
            return $parts[5] . '=' . $parts[6];
        }
    }

    return '';
}

/** The session id a jar holds ('' when it holds none). */
function sessionIdOf(string $jar): string
{
    return (string) substr(cookieOf($jar), strlen(session_name()) + 1);
}

/** How many session files the server holds. */
function sessionFiles(): int
{
    global $child, $sep;

    return count(glob($child . $sep . 'sessions' . $sep . 'sess_*') ?: []);
}

/**
 * Post an organisation's form back to the save handler exactly as it is
 * stored, with `$change` laid over it. `isActive` in `$change` is true or
 * false; the form sends that box only when it is ticked.
 *
 * @param array<string,mixed> $change
 */
function saveOrganisation(string $jar, int $site, array $change, array $o = []): array
{
    $row    = one('SELECT siteName, siteKey, hostPattern, logoPath, faviconPath, primaryColor, copyrightOrg, timezone, isActive FROM tblSites WHERE siteID = ?', 'i', [$site]) ?? [];
    $active = array_key_exists('isActive', $change) === true ? $change['isActive'] === true : ((int) ($row['isActive'] ?? 0) === 1);
    unset($row['isActive'], $change['isActive']);
    $fields = array_merge(['siteID' => $site], array_map(static fn ($value): string => (string) $value, $row), $change);
    if ($active === true) {
        $fields['isActive'] = '1';
    }

    return post($jar, '/admin/sites', '/admin/sites/save', $fields, $o);
}

/** An organisation's row as stored, for "nothing was changed" comparisons. */
function organisationRow(int $site): ?array
{
    return one('SELECT siteName, siteKey, hostPattern, logoPath, faviconPath, primaryColor, copyrightOrg, timezone, isActive FROM tblSites WHERE siteID = ?', 'i', [$site]);
}

/** The form token on a page. */
function token(string $html): string
{
    if (preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** POST a form with a fresh token read from `$formPage`, as a browser would. */
function post(string $jar, string $formPage, string $to, array $fields, array $o = []): array
{
    $fields['csrf_token'] = token(req($jar, 'GET', $formPage, null, $o)['body']);

    return req($jar, 'POST', $to, $fields, $o);
}

/** "status location" of an answer, for failure messages. */
function said(array $answer): string
{
    return $answer['status'] . ($answer['location'] === '' ? '' : ' -> ' . $answer['location']);
}

/** How many top headings a page has, and its title. */
function shape(array $answer): string
{
    return preg_match_all('#<h1[\s>]#', $answer['body']) . ' h1, title "' . (preg_match('#<title>(.*?)</title>#s', $answer['body'], $t) === 1 ? trim($t[1]) : '') . '"';
}

/**
 * Which organisation a request is in, read from /health: its number when the
 * portal answered, or minus the HTTP status when it did not (so -302 is "sent
 * somewhere else" and -403 is "refused").
 */
function orgSeen(string $jar, string $prefix = '', string $host = ''): int
{
    $answer = req($jar, 'GET', $prefix . '/health', null, ['host' => $host]);
    $json   = json_decode($answer['body'], true);

    return ($answer['status'] === 200 && is_array($json) === true) ? (int) ($json['siteID'] ?? 0) : -$answer['status'];
}

/** The language a page says it is written in. */
function pageLanguage(array $answer): string
{
    return preg_match('/<html lang="([^"]*)"/', $answer['body'], $m) === 1 ? $m[1] : '(none)';
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}; this test never drives a server it did not start");
}
putTablesBack(); // before the first read of tblSites, in case an earlier run was killed while a table was away
$defaultOrg = one('SELECT siteName, hostPattern, isActive FROM tblSites WHERE siteID = 1');
if ($defaultOrg === null || (int) $defaultOrg['isActive'] !== 1 || ($defaultOrg['hostPattern'] !== null && $defaultOrg['hostPattern'] !== HOST_1)) {
    refuse('the database must hold an active default organisation (number 1) with no address of its own, as a new install does');
}
$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();

foreach ([[ORG_A, KEY_A, NAME_A, HOST_A], [ORG_B, KEY_B, NAME_B, HOST_B], [ORG_C, KEY_C, NAME_C, HOST_C]] as [$id, $key, $name, $host]) {
    q('INSERT INTO tblSites (siteID, siteKey, siteName, hostPattern, isActive) VALUES (?, ?, ?, ?, 1)', 'isss', [$id, $key, $name, $host]);
}
$people = [
    'p' => [P, 'P two orgs'], 'q' => [Q, 'Q one org'], 'r' => [R, 'R of Bee'], 'g' => [G, 'G global'], 'n' => [N, 'N nowhere'],
    'x' => [X, 'X paused'], 'x2' => [X2, 'X2 paused'], 'u1' => [U1, 'U1 default'], 'px' => [PX, 'PX removed'], 'p3' => [P3, 'P3 three orgs'], 'l' => [L, 'L Welsh'],
];
foreach ($people as $who => [$id, $name]) {
    // No name here may contain an organisation's name or the word MODESB: whole answers are searched for those.
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, ?)', 'issi', [$id, 'Selftest ' . $name, $who . '.modes@selftest.invalid', $id === G ? 1 : 0]);
}
q("UPDATE tblUsers SET locale = 'cy' WHERE userID = ?", 'i', [L]);
foreach ([[P, ORG_A, 0], [P, ORG_B, 0], [Q, ORG_A, 0], [R, ORG_B, 1], [X, ORG_A, 0], [X2, ORG_A, 0], [U1, 1, 0], [PX, ORG_A, 0], [PX, ORG_B, 0],
    [P3, ORG_A, 0], [P3, ORG_B, 0], [P3, ORG_C, 0], [L, ORG_A, 0]] as [$user, $site, $admin]) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, 0)', 'iii', [$user, $site, $admin]);
}
// B's records, which nobody outside B may be shown. Dates are worked out from today.
$today    = date('Y-m-d');
$eventDay = date('Y-m-d', strtotime('+9 days'));
q(
    'INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, eventTimezone, status, isPublic, isDeleted) '
    . "VALUES (?, ?, 'modesb-internal', 'MODESB internal event', 'MODESB-EVENT-DESCRIPTION', ?, ?, 'UTC', 'UTC', 'published', 0, 0)",
    'iiss', [EV_B, ORG_B, $eventDay . ' 10:00:00', $eventDay . ' 11:00:00']
);
q("INSERT INTO tblAttendanceServiceTypes (serviceTypeID, siteID, typeName, typeSlug, isActive) VALUES (?, ?, 'MODESB service', 'modesb-service', 1)", 'ii', [SERVICE_B, ORG_B]);
q("INSERT INTO tblAttendanceSessions (sessionID, siteID, serviceTypeID, sessionDate, notes) VALUES (?, ?, ?, ?, 'MODESB-ATT-NOTE')", 'iiis', [SESSION_B, ORG_B, SERVICE_B, $today]);
q("INSERT INTO tblAttendanceCounts (sessionID, groupLabel, headcount, sortOrder) VALUES (?, 'MODESB-GROUP', ?, 0)", 'ii', [SESSION_B, B_HEADCOUNT]);
foreach ([ORG_A, ORG_B, ORG_C] as $site) {
    siteSetting($site, 'attendance.enabled', 'true');
    siteSetting($site, 'calendar.enabled', 'true');
}
// One API key belonging to B (the length ApiKey::findByPlaintext() accepts).
$keyB = 'wbms_' . bin2hex(random_bytes(16));
q("INSERT INTO tblApiKeys (siteID, name, keyHash, keyPrefix, scopes, isActive, createdByID) VALUES (?, 'Selftest modes key', ?, ?, 'attendance:read', 1, NULL)", 'iss', [ORG_B, hash('sha256', $keyB), substr($keyB, 0, 12)]);

// The two portal-wide settings: recorded first, put back by the clean-up.
foreach (['multisite.enabled' => 'true', 'multisite.detectionMode' => 'session'] as $key => $value) {
    $row                     = one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    $state['settings'][$key] = $row;
    if ($row === null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, 0)', 'sss', [$key, $value, $value]);
    }
}
$before = $state['settings'];

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach ($people as $who => [$id]) {
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-modes-' . $who, $hash]);
}

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'site-modes-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.site-modes-selftest-owner', $state['token']); // the marker FIRST
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
mkdir($child . $sep . 'sessions-cli', 0700);
$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/site-modes-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
// The command-line tool, beside the copy of web/ exactly as it sits in the repository.
mkdir($child . $sep . 'tools' . $sep . 'offsite-backup', 0700, true);
copy($repo . $sep . 'tools' . $sep . 'offsite-backup' . $sep . 'log-offsite-result.php', $child . $sep . 'tools' . $sep . 'offsite-backup' . $sep . 'log-offsite-result.php');

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/site-modes-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Site-Modes-Selftest: __TOKEN__');
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// TEST-ONLY EXTRA 1: "the session changed between the portal's early look and
// its real start". That takes a race in real life (a sign-out in another tab
// in the same instant), so it is imitated: for a request carrying this run's
// token in the header below, the SECOND time PHP reads the session it is
// handed another session's stored data ("nobody" = an empty session). The
// first read, which is the portal's early look, is the real one. Nothing in
// the portal knows about this.
$swap = explode(' ', (string) ($_SERVER['HTTP_X_SITE_MODES_SECOND_READ'] ?? ''), 2);
if (count($swap) === 2 && $swap[0] === '__TOKEN__') {
    session_set_save_handler(new class($swap[1]) extends SessionHandler {
        private int $reads = 0;
        public function __construct(private string $other) {}
        public function read(string $id): string|false
        {
            $real = parent::read($id);
            $this->reads++;
            if ($this->reads < 2) { return $real; }
            $file = session_save_path() . '/sess_' . $this->other;
            if ($this->other === 'nobody' || preg_match('/^[a-zA-Z0-9,-]{1,256}$/', $this->other) !== 1 || is_file($file) === false) { return ''; }
            return (string) file_get_contents($file);
        }
    }, true);
}

// TEST-ONLY EXTRA 2: the portal's START-UP ALONE, without the front
// controller that starts the session afterwards. It answers with what
// start-up left behind, so a check can see whether the early look gave a
// session to a request that sent no cookie. (web/public_html/error.php and
// /api-docs/ load start-up in just this way.) Required at the top level,
// like the front controller below.
if ($uri === '/__start-up-only') {
    require __DIR__ . '/web/_core/bootstrap.php';
    header('Content-Type: application/json');
    echo json_encode(['sessionOpen' => session_status() === PHP_SESSION_ACTIVE, 'state' => \Portal\Core\Site::sessionState(),
        'workedOutFor' => \Portal\Core\Site::resolvedUserId(), 'failed' => \Portal\Core\Site::detectionFailed()]);
    return true;
}
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

// The server: its command as a LIST (no shell in between), ONE process (no
// PHP_CLI_SERVER_WORKERS), so nothing can outlive it. The code cache is off,
// because one block below edits a file in the scratch copy between requests.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc']     = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('the web server could not be started');
}
$deadline = microtime(true) + 10;
while (portAnswers($port) === false && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false) {
    refuse('the web server never answered on port ' . $port);
}

echo "Which organisation, on the real pages — self-test (#502, #511)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

/**
 * Sign one account in through the real sign-in page; returns its cookie jar.
 * `$prefix` is an organisation's first part of the address ("/key") and
 * `$host` an organisation's own address, for the two address ways.
 */
function signIn(string $who, string $prefix = '', string $host = ''): string
{
    global $child, $sep, $password;
    $file = $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
    $tok  = token(req($file, 'GET', $prefix . '/login', null, ['host' => $host])['body']);
    $in   = req($file, 'POST', $prefix . '/login', ['csrf_token' => $tok, 'username' => 'selftest-modes-' . $who, 'password' => $password], ['host' => $host]);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-modes-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }

    return $file;
}

/** Choose or switch organisation by posting to the switch handler, with a token from `$tokenPage`. */
function switchTo(string $jar, int $site, string $tokenPage = '/site/choose', string $prefix = '', string $host = ''): array
{
    return post($jar, $prefix . $tokenPage, $prefix . '/site/switch', ['site_id' => $site], ['host' => $host]);
}

$nobody = $child . $sep . 'jar-nobody.txt';
$lang   = [];

// =============================================================================
// S. SIGN-IN SESSION
// =============================================================================
head('S. Organisations told apart by the sign-in session (#502)');
setWay(true, 'session');

ok('S01 control: a signed-out visitor is in the default organisation', orgSeen($nobody) === 1, 'got ' . orgSeen($nobody));

$jarQ = signIn('q');
$seen = orgSeen($jarQ);
ok('S02 after signing in, a person with ONE organisation is in it, not in the default one', $seen === ORG_A, 'Q belongs to A (' . ORG_A . ') only; the portal says ' . $seen);
$page = req($jarQ, 'GET', '/');
ok('S03 that person never sees the chooser: the front page answers, and it is their organisation\'s', $page['status'] === 200 && str_contains($page['body'], NAME_A) === true, said($page));

$jarP = signIn('p');
$page = req($jarP, 'GET', '/');
ok('S04 a person with SEVERAL organisations is sent to the chooser after signing in', $page['status'] === 302 && $page['location'] === '/site/choose', said($page));
$wrong = [];
foreach (['/account', '/attendance', '/health', '/admin', '/settings', '/calendar'] as $address) {
    $answer = req($jarP, 'GET', $address);
    if ($answer['status'] !== 302 || $answer['location'] !== '/site/choose' || str_contains($answer['body'], NAME_A) === true || bWords($answer['body']) !== '') {
        $wrong[] = $address . ' gave ' . said($answer);
    }
}
ok('S05 until they choose, every other address sends them back to the chooser and shows nothing of any organisation', $wrong === [], implode('; ', $wrong));
$answer = req($jarP, 'GET', '/api/attendance/list');
ok('S06 until they choose, a data address is refused in JSON (403), with no organisation\'s data', $answer['status'] === 403 && is_array(json_decode($answer['body'], true)) === true && bWords($answer['body']) === '', said($answer) . ' ' . substr($answer['body'], 0, 120));
$answer = req($jarP, 'POST', '/account/save', ['fullName' => 'x']);
ok('S07 until they choose, a form posted anywhere else is not handled: it goes to the chooser', $answer['status'] === 302 && $answer['location'] === '/site/choose', said($answer));

$chooser = req($jarP, 'GET', '/site/choose');
ok('S08 the chooser answers, with a title and exactly one top heading', $chooser['status'] === 200 && preg_match_all('#<h1[\s>]#', $chooser['body']) === 1 && str_contains($chooser['body'], '<title>Choose an organisation - ') === true, said($chooser) . ' ' . shape($chooser));
ok('S09 the chooser lists the person\'s own organisations and no other', str_contains($chooser['body'], NAME_A) === true && str_contains($chooser['body'], NAME_B) === true && str_contains($chooser['body'], NAME_C) === false
    && preg_match_all('/name="site_id" value="(\d+)"/', $chooser['body'], $ids) === 2 && $ids[1] === [(string) ORG_A, (string) ORG_B], 'organisation numbers offered: ' . implode(', ', $ids[1] ?? []));
ok('S10 the chooser says who is signed in and offers a way to sign out', str_contains($chooser['body'], 'p.modes@selftest.invalid') === true && str_contains($chooser['body'], 'href="/logout"') === true);

// A database that has not run migration 212 yet has no address row for the chooser. The gate sends people
// there, so it must still be shown: otherwise everybody with several organisations is sent round to
// "page not found" between uploading the new code and running the upgrade.
q("DELETE FROM tblRoutes WHERE routeKey = 'site/choose'");
$chooser = req($jarP, 'GET', '/site/choose');
q("INSERT INTO tblRoutes (routeKey, targetFile, isProtected) VALUES ('site/choose', 'site/choose.php', 1) ON DUPLICATE KEY UPDATE targetFile = VALUES(targetFile)");
ok('S56 the chooser still answers on a database that does not hold its address yet (one that has not run migration 212)', $chooser['status'] === 200 && str_contains($chooser['body'], 'name="site_id"') === true, said($chooser));
$answer = req($jarP, 'GET', '/admin/upgrade');
ok('S57 somebody who has not chosen, and is not a global administrator, cannot open the database upgrade page either', $answer['status'] === 302 && $answer['location'] === '/site/choose', said($answer));

$answer = switchTo($jarP, ORG_B);
$seen   = orgSeen($jarP);
ok('S11 choosing an organisation is honoured on the next request', $answer['status'] === 302 && $answer['location'] === '/' && $seen === ORG_B, 'the switch answered ' . said($answer) . '; the next request is in ' . $seen . ' (B is ' . ORG_B . ')');
$page = req($jarP, 'GET', '/');
ok('S12 the menu shows which organisation the person is in at every screen width, and a switcher that posts to the switch handler',
    $page['status'] === 200 && preg_match('#aria-label="Switch organisation">.*?<span class="([^"]*)"[^>]*>' . preg_quote(NAME_B, '#') . '</span>#s', $page['body'], $m) === 1 && str_contains($m[1], 'd-none') === false
    && str_contains($page['body'], 'action="/site/switch"') === true, said($page) . ' name\'s classes: ' . ($m[1] ?? '(switcher not found)'));
$answer = switchTo($jarP, ORG_A, '/account');
$seen   = orgSeen($jarP);
ok('S13 switching from the menu is honoured on the next request', $answer['status'] === 302 && $seen === ORG_A, said($answer) . '; next request in ' . $seen);
$switches = logged(P, 'SiteSwitch');
switchTo($jarP, ORG_A, '/account');
ok('S14 only a real switch is written to the activity log', $switches === 2 && logged(P, 'SiteSwitch') === 2, 'after choosing B then switching to A: ' . $switches . ' rows (expected 2); after "switching" to A again: ' . logged(P, 'SiteSwitch'));

$wrong = [];
foreach ([ORG_B => 'B', ORG_C => 'C', 917999 => 'an organisation that does not exist'] as $target => $what) {
    $answer = switchTo($jarQ, $target, '/account');
    $seen   = orgSeen($jarQ);
    if ($answer['status'] !== 302 || $answer['location'] !== '/site/choose' || $seen !== ORG_A) {
        $wrong[] = 'to ' . $what . ': ' . said($answer) . ', next request in ' . $seen;
    }
}
ok('S15 nobody can switch into an organisation that is not theirs: they stay where they were', $wrong === [], implode('; ', $wrong));
ok('S16 a refused switch is logged as refused, never as a switch', logged(Q, 'SiteSwitchDenied') === 3 && logged(Q, 'SiteSwitch') === 0, 'refused rows: ' . logged(Q, 'SiteSwitchDenied') . ', switch rows: ' . logged(Q, 'SiteSwitch'));
$chooser = req($jarQ, 'GET', '/site/choose');
ok('S17 the chooser then says, once, that the organisation is not one of theirs', str_contains($chooser['body'], 'not one of yours') === true && str_contains(req($jarQ, 'GET', '/site/choose')['body'], 'not one of yours') === false);

$jarPX = signIn('px');
switchTo($jarPX, ORG_B);
$inB = orgSeen($jarPX);
q('DELETE FROM tblUserSites WHERE userID = ? AND siteID = ?', 'ii', [PX, ORG_B]);
$seen = orgSeen($jarPX);
ok('S18 somebody taken out of an organisation while signed in is out of it on their next click, and in their remaining one', $inB === ORG_B && $seen === ORG_A, 'before: ' . $inB . ' (B is ' . ORG_B . '); after being taken out of B: ' . $seen . ' (A is ' . ORG_A . ')');
$jarP3 = signIn('p3');
switchTo($jarP3, ORG_B);
$inB = orgSeen($jarP3);
q('DELETE FROM tblUserSites WHERE userID = ? AND siteID = ?', 'ii', [P3, ORG_B]);
$answer = req($jarP3, 'GET', '/attendance');
ok('S19 taken out of one of THREE: they are asked to choose again, not put somewhere quietly', $inB === ORG_B && $answer['status'] === 302 && $answer['location'] === '/site/choose', 'before: ' . $inB . '; after: ' . said($answer));

$jarG    = signIn('g');
$page    = req($jarG, 'GET', '/');
$chooser = req($jarG, 'GET', '/site/choose');
preg_match_all('/name="site_id" value="(\d+)"/', $chooser['body'], $ids);
$offered = array_map('intval', $ids[1]);
sort($offered);
ok('S20 a global administrator with no membership of their own is sent to the chooser, which lists EVERY active organisation',
    $page['status'] === 302 && $page['location'] === '/site/choose' && $offered === [1, ORG_A, ORG_B, ORG_C], said($page) . '; offered: ' . implode(', ', $offered));
$answer = req($jarG, 'GET', '/admin/upgrade');
ok('S58 a global administrator who has not chosen yet can still open the database upgrade page (it is the page that runs a pending migration)', $answer['status'] === 200 && stripos($answer['body'], 'upgrade') !== false, said($answer));
switchTo($jarG, ORG_B);
$page = req($jarG, 'GET', '/');
ok('S21 the global administrator can go into any of them', orgSeen($jarG) === ORG_B && $page['status'] === 200 && str_contains($page['body'], NAME_B) === true, 'in ' . orgSeen($jarG) . '; front page ' . said($page));

$jarN  = signIn('n');
$wrong = [];
foreach (['/', '/account', '/attendance', '/health', '/admin'] as $address) {
    $answer = req($jarN, 'GET', $address);
    $refused = ($answer['status'] === 403 && str_contains($answer['body'], 'Ask an administrator') === true);
    if ($refused === false || str_contains($answer['body'], NAME_A) === true || bWords($answer['body']) !== '') {
        $wrong[] = $address . ' gave ' . said($answer);
    }
}
ok('S22 an account that belongs to NO organisation is shown no page of any organisation, the default one included', $wrong === [], implode('; ', $wrong));
$answer = req($jarN, 'GET', '/api/attendance/list');
ok('S23 and no data address answers it', $answer['status'] === 403 && bWords($answer['body']) === '', said($answer));
$chooser = req($jarN, 'GET', '/site/choose');
ok('S24 its chooser is an empty state that says what to do: ask an administrator, or sign out', $chooser['status'] === 200 && str_contains($chooser['body'], 'has not been added to any organisation') === true
    && str_contains($chooser['body'], 'Ask an administrator') === true && str_contains($chooser['body'], 'name="site_id"') === false && str_contains($chooser['body'], 'href="/logout"') === true
    && preg_match_all('#<h1[\s>]#', $chooser['body']) === 1, said($chooser) . ' ' . shape($chooser));
$answer = req($jarN, 'GET', '/logout');
ok('S25 it can still sign out', $answer['status'] !== 403 && orgSeen($jarN) === 1, said($answer) . '; afterwards in ' . orgSeen($jarN));

$jarU1 = signIn('u1');
$page  = req($jarU1, 'GET', '/');
ok('S26 control: a member of the default organisation only is in it, with no chooser', orgSeen($jarU1) === 1 && $page['status'] === 200, 'in ' . orgSeen($jarU1) . '; front page ' . said($page));

// The access package's protection (its own test's part K), in this way too.
$jarX = signIn('x');
$inA  = orgSeen($jarX);
q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [X]);
$answer = req($jarX, 'GET', '/account');
ok('S27 a switched-off account\'s session is ended on its next request (a GET), in this way too', $inA === ORG_A && $answer['status'] === 302 && str_contains($answer['location'], 'login') === true
    && logged(X, 'SessionEndedInactiveAccount') === 1, 'before: in ' . $inA . '; after: ' . said($answer) . '; "session ended" rows: ' . logged(X, 'SessionEndedInactiveAccount'));
ok('S28 and what is left is an ordinary signed-out visitor, in the default organisation', orgSeen($jarX) === 1, 'in ' . orgSeen($jarX));
q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [X]);
$answer = req($jarX, 'GET', '/account');
ok('S29 switching the account back on does not bring the old session back', $answer['status'] === 302 && str_contains($answer['location'], 'login') === true, said($answer));
$jarX2  = signIn('x2');
$tokX2  = token(req($jarX2, 'GET', '/account')['body']);
q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [X2]);
$answer = req($jarX2, 'POST', '/site/switch', ['csrf_token' => $tokX2, 'site_id' => ORG_A]);
ok('S30 the same when its next request is a POST', $answer['status'] === 302 && str_contains($answer['location'], 'login') === true && logged(X2, 'SessionEndedInactiveAccount') === 1, said($answer) . '; rows: ' . logged(X2, 'SessionEndedInactiveAccount'));

$jarL            = signIn('l');
$lang['session'] = pageLanguage(req($jarL, 'GET', '/'));

$answer = req('', 'GET', '/api/attendance/list', null, ['bearer' => $keyB]);
ok('S31 control: an API key is answered with its own organisation\'s data, with no session at all', $answer['status'] === 200 && str_contains($answer['body'], 'MODESB-ATT-NOTE') === true, said($answer) . ' ' . substr($answer['body'], 0, 160));
$answer = req('', 'GET', '/api/attendance/list', null, ['bearer' => 'wbms_' . str_repeat('0', 32)]);
ok('S32 control: a made-up key is still refused', in_array($answer['status'], [401, 403], true) === true && bWords($answer['body']) === '', said($answer));

// A command-line tool: it loads the portal's start-up, and must be given no session.
$cli = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions-cli', $child . $sep . 'tools' . $sep . 'offsite-backup' . $sep . 'log-offsite-result.php', '--status=success', '--destination=selftest-modes'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $cliPipes, $child, $env
);
$cliOut  = is_resource($cli) === true ? stream_get_contents($cliPipes[1]) . stream_get_contents($cliPipes[2]) : 'could not start';
$cliCode = is_resource($cli) === true ? proc_close($cli) : -1;
$cliRows = (int) (one("SELECT COUNT(*) AS n FROM tblOffsiteSyncLog WHERE destination = 'selftest-modes'")['n'] ?? -1);
ok('S33 control: a command-line tool still runs, and is given no session', $cliCode === 0 && $cliRows === 1 && (glob($child . $sep . 'sessions-cli' . $sep . '*') ?: []) === [],
    'exit ' . $cliCode . '; rows written: ' . $cliRows . '; session files: ' . count(glob($child . $sep . 'sessions-cli' . $sep . '*') ?: []) . '; said: ' . trim((string) $cliOut));

// ---- rules the independent check could remove without any check failing ------
// A membership row that is SWITCHED OFF, not deleted: that is what offboarding does. (S18 deletes the row.)
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [PX, ORG_B]);
switchTo($jarPX, ORG_B, '/account');
$inB = orgSeen($jarPX);
q('UPDATE tblUserSites SET isActive = 0 WHERE userID = ? AND siteID = ?', 'ii', [PX, ORG_B]);
$seen    = orgSeen($jarPX);
$chooser = req($jarPX, 'GET', '/site/choose');
ok('S59 somebody whose membership row is SWITCHED OFF (not deleted) is out of that organisation on their next click, and is no longer offered it',
    $inB === ORG_B && $seen === ORG_A && str_contains($chooser['body'], NAME_B) === false, 'before: ' . $inB . ' (B is ' . ORG_B . '); after the row was switched off: ' . $seen . ' (A is ' . ORG_A . '); chooser ' . said($chooser));

// An organisation LEFT IN THE SESSION by whoever used this browser before. The real way there is somebody
// who gives up half way through two-step sign-in, which needs an authenticator; so the test writes the
// left-over into the session file itself, between opening the sign-in page and signing in.
$jarLeft   = $child . $sep . 'jar-left-over.txt';
$tokLeft   = token(req($jarLeft, 'GET', '/login')['body']);
$fileLeft  = $child . $sep . 'sessions' . $sep . 'sess_' . sessionIdOf($jarLeft);
$written   = (preg_match('/^[a-zA-Z0-9,-]+$/', sessionIdOf($jarLeft)) === 1 && is_file($fileLeft) === true)
    ? file_put_contents($fileLeft, 'active_site_id|i:' . ORG_B . ';', FILE_APPEND) : false;
$in        = req($jarLeft, 'POST', '/login', ['csrf_token' => $tokLeft, 'username' => 'selftest-modes-p', 'password' => $password]);
$page      = req($jarLeft, 'GET', '/');
ok('S60 an organisation left in the session by whoever used the browser before is dropped at sign-in: a person with several organisations is still asked to choose',
    $written !== false && $in['status'] === 302 && str_contains($in['location'], 'login') === false && $page['status'] === 302 && $page['location'] === '/site/choose',
    'left-over written: ' . ($written === false ? 'NO' : 'yes') . '; sign-in ' . said($in) . '; the front page then gave ' . said($page) . ' (in ' . orgSeen($jarLeft) . ')');

// The session changes between the portal's early look and its real start (the router's test-only extra 1).
// The organisation was worked out for the person the LOOK saw, so nobody else may be answered with it.
$jarP2 = signIn('p');
switchTo($jarP2, ORG_B);
$wrong  = [];
$answer = req(signIn('q'), 'GET', '/account', null, ['secondRead' => sessionIdOf($jarP2)]);
if ($answer['status'] !== 503 || str_contains($answer['body'], 'Please try again') === false || str_contains($answer['body'], NAME_A) === true || bWords($answer['body']) !== '') {
    $wrong[] = 'another person signed in by the real start: ' . said($answer);
}
$answer = req(signIn('q'), 'GET', '/login', null, ['secondRead' => 'nobody']);
if ($answer['status'] !== 503 || str_contains($answer['body'], 'Please try again') === false || str_contains($answer['body'], NAME_A) === true) {
    $wrong[] = 'signed out by the real start: ' . said($answer);
}
ok('S61 an organisation worked out for one person is never used to answer another: if the session changes between the early look and the real start, the answer is "try again" (503)',
    orgSeen($jarP2) === ORG_B && $wrong === [], 'control, P is in ' . orgSeen($jarP2) . ' (B is ' . ORG_B . '); ' . implode('; ', $wrong));

// The portal's start-up ALONE (the router's test-only extra 2): what the early look leaves behind.
$filesBefore = sessionFiles();
$answer      = req('', 'GET', '/__start-up-only');
$alone       = json_decode($answer['body'], true);
ok('S62 start-up alone gives NO session to a request that sent no cookie: no session cookie in the answer, no session stored, none left open',
    $answer['status'] === 200 && is_array($alone) === true && $alone['state'] === 'signed-out' && $alone['sessionOpen'] === false
    && stripos($answer['head'], 'Set-Cookie: ' . session_name() . '=') === false && sessionFiles() === $filesBefore,
    said($answer) . ' ' . substr($answer['body'], 0, 160) . '; session files ' . $filesBefore . ' -> ' . sessionFiles() . '; cookie sent: ' . (stripos($answer['head'], 'Set-Cookie: ' . session_name() . '=') === false ? 'no' : 'YES'));
$answer = req($jarQ, 'GET', '/__start-up-only');
$alone  = json_decode($answer['body'], true);
ok('S63 control: with a signed-in person\'s cookie the same look does read who it is, and still leaves no session open',
    is_array($alone) === true && $alone['state'] === 'ok' && $alone['workedOutFor'] === Q && $alone['sessionOpen'] === false && $alone['failed'] === false, substr($answer['body'], 0, 160));

// A look-up that FAILS: the membership table is renamed away for one request.
$failed = reqWhileFailing('memberships', $jarQ, '/account');
ok('S64 when the look-up of a signed-in person\'s organisations fails, they are shown "try again" (503) and nothing of any organisation, not the default one',
    $failed['answer']['status'] === 503 && str_contains($failed['answer']['body'], 'Please try again') === true && str_contains($failed['answer']['body'], NAME_A) === false
    && str_contains($failed['answer']['head'], 'Retry-After: 30') === true, said($failed['answer']));
ok('S65 and that failure writes one line to PHP\'s error log, so an administrator has something to read',
    substr_count($failed['logged'], 'Site::preDetect could not read the organisations of account ' . Q) === 1, 'the log gained: ' . substr(trim($failed['logged']), 0, 300));
ok('S66 control: with the table back, the same person is in their organisation again', orgSeen($jarQ) === ORG_A, 'in ' . orgSeen($jarQ));

// One organisation's own row for either setting is not read. Such a row used to
// win over the portal-wide one when the page was labelled, so one
// organisation's administrator could switch multi-organisation "off" for
// their own pages while the rest of the portal still had it on.
siteSetting(ORG_A, 'multisite.enabled', 'false');
siteSetting(ORG_A, 'multisite.detectionMode', 'path');
$page = req($jarP, 'GET', '/');
ok('S55 a row for ONE organisation saying "multi-organisation is off" changes nothing: that organisation\'s pages are still its own, switcher and all',
    orgSeen($jarP) === ORG_A && $page['status'] === 200 && str_contains($page['body'], 'Switch organisation') === true && str_contains($page['body'], 'action="/site/switch"') === true,
    'in ' . orgSeen($jarP) . '; ' . said($page));
q("DELETE FROM tblSettings WHERE siteID = ? AND settingKey LIKE 'multisite.%'", 'i', [ORG_A]);

// ---- the general settings editor's lock -------------------------------------
$modeRow = one("SELECT settingID FROM tblSettings WHERE settingKey = 'multisite.detectionMode' AND siteID IS NULL");
$answer  = post($jarG, '/settings', '/settings/save', ['settingID' => (int) $modeRow['settingID'], 'settingKey' => 'multisite.detectionMode', 'settingValue' => 'path']);
ok('S34 the general settings editor refuses to change how organisations are told apart, even for a global administrator', storedWay() === 'on/session' && str_contains(req($jarG, 'GET', '/settings')['body'], 'How organisations are told apart') === true, 'stored: ' . storedWay() . '; the save answered ' . said($answer));
$answer = post($jarG, '/settings', '/settings/save', ['settingKey' => 'multisite.enabled', 'settingValue' => 'false']);
ok('S35 nor may either setting be added there as one organisation\'s own row', one("SELECT settingID FROM tblSettings WHERE settingKey LIKE 'multisite.%' AND siteID IS NOT NULL") === null, 'the save answered ' . said($answer));
$wrong = [];
foreach (['multisite.enabled', 'multisite.detectionMode'] as $key) {
    $row    = one('SELECT settingID, settingValue, defaultValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    $answer = post($jarG, '/settings', '/settings', ['action' => 'delete', 'settingID' => (int) $row['settingID']]);
    $shown  = req($jarG, 'GET', '/settings')['body'];
    if (one('SELECT settingID FROM tblSettings WHERE settingID = ?', 'i', [(int) $row['settingID']]) === null) {
        $wrong[] = $key . ' WAS DELETED (' . said($answer) . ')';
        // Put it back at once, under its own number, so the rest of the run and the clean-up see the row they expect.
        q('INSERT INTO tblSettings (settingID, siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, NULL, ?, ?, ?, 0)', 'isss', [(int) $row['settingID'], $key, $row['settingValue'], $row['defaultValue']]);
    } elseif (str_contains($shown, 'How organisations are told apart') === false || str_contains($shown, 'Setting deleted.') === true) {
        $wrong[] = $key . ' was kept, but the page did not say why (' . said($answer) . ')';
    }
}
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'selftest.modes.deleteme', 'x', 'x', 0)");
$ordinary = one("SELECT settingID FROM tblSettings WHERE settingKey = 'selftest.modes.deleteme' AND siteID IS NULL");
post($jarG, '/settings', '/settings', ['action' => 'delete', 'settingID' => (int) $ordinary['settingID']]);
ok('S67 the general settings editor refuses to DELETE either of them too, even for a global administrator, and says where they are changed; an ordinary setting is still deleted (control)',
    $wrong === [] && storedWay() === 'on/session' && one("SELECT settingID FROM tblSettings WHERE settingKey = 'selftest.modes.deleteme'") === null && logged(G, 'SettingsDeleteRefused') === 2,
    implode('; ', $wrong) . ' stored: ' . storedWay() . '; ordinary setting ' . (one("SELECT settingID FROM tblSettings WHERE settingKey = 'selftest.modes.deleteme'") === null ? 'deleted' : 'NOT deleted') . '; refusals logged: ' . logged(G, 'SettingsDeleteRefused'));
$rowsShown = explode('<div class="portal-data-row portal-setting-row"', req($jarG, 'GET', '/settings')['body']);
$locked    = 0;
$withEdit  = 0;
$wrong     = [];
foreach (array_slice($rowsShown, 1) as $shownRow) {
    $hasButton = (str_contains($shownRow, 'data-bs-target="#editModal"') === true || str_contains($shownRow, 'name="action" value="delete"') === true);
    if (preg_match('/^\s*data-setting-key="multisite\.(enabled|detectionMode)"/i', $shownRow) === 1) {
        $locked++;
        if ($hasButton === true || str_contains($shownRow, 'Changed at Admin, Organisations') === false) {
            $wrong[] = substr(trim($shownRow), 0, 60);
        }
    } elseif ($hasButton === true) {
        $withEdit++;
    }
}
ok('S68 the settings list shows no Edit and no Delete button for those two rows, and a link to the page that changes them; other rows keep their buttons (control)',
    $locked === 2 && $wrong === [] && $withEdit > 10, 'locked rows found: ' . $locked . '; with a button: ' . implode(' | ', $wrong) . '; other rows with buttons: ' . $withEdit);

// ---- the set-up page ---------------------------------------------------------
$setup = req($jarG, 'GET', '/admin/sites/setup');
ok('S36 the set-up page answers for a global administrator, with a title and exactly one top heading', $setup['status'] === 200 && preg_match_all('#<h1[\s>]#', $setup['body']) === 1
    && str_contains($setup['body'], '<title>How organisations are told apart - ') === true, said($setup) . ' ' . shape($setup));
ok('S37 it says plainly what the sign-in session cannot do, and what own addresses cannot do', str_contains($setup['body'], 'A signed-out visitor always sees') === true && str_contains($setup['body'], 'cannot share a sign-in between addresses') === true);
ok('S38 it marks "first part of the address" as not ready, and that choice cannot be ticked', str_contains($setup['body'], 'Not ready') === true
    && preg_match('#<input[^>]*id="modePath"[^>]*value="path"[^>]*\sdisabled>#s', $setup['body']) === 1 && str_contains($setup['body'], 'This way is not ready yet') === true);
$answer = req($jarQ, 'GET', '/admin/sites/setup');
ok('S39 it is refused to anybody who is not a global administrator', $answer['status'] === 403 && str_contains($answer['body'], 'Not ready') === false, said($answer));
$answer = post($jarG, '/admin/sites/setup', '/admin/sites/setup/save', ['mode' => 'path']);
ok('S40 the save handler refuses "first part of the address" while it is not ready, and changes nothing', storedWay() === 'on/session' && $answer['status'] === 302
    && str_contains(req($jarG, 'GET', '/admin/sites/setup')['body'], 'cannot be chosen yet, so nothing was changed') === true, 'stored: ' . storedWay() . '; ' . said($answer));
$answer = post($jarG, '/admin/sites/setup', '/admin/sites/setup/save', ['mode' => 'subdomain']);
$shown  = req($jarG, 'GET', '/admin/sites/setup')['body'];
ok('S41 it refuses "own address each" while an active organisation has no address, names that organisation, and changes nothing', storedWay() === 'on/session'
    && str_contains($shown, 'cannot be chosen yet, so nothing was changed') === true && str_contains($shown, htmlspecialchars('"' . $defaultOrg['siteName'] . '" has no address yet', ENT_QUOTES, 'UTF-8')) === true, 'stored: ' . storedWay() . '; ' . said($answer));
$answer = post($jarG, '/admin/sites/setup', '/admin/sites/setup/save', ['mode' => 'everything']);
ok('S42 it refuses a choice that is not one of the four', storedWay() === 'on/session', 'stored: ' . storedWay() . '; ' . said($answer));
$answer = post($jarQ, '/account', '/admin/sites/setup/save', ['mode' => 'off']);
ok('S43 it refuses anybody who is not a global administrator, and changes nothing', $answer['status'] === 403 && storedWay() === 'on/session', said($answer) . '; stored: ' . storedWay());
$answer = req($jarG, 'POST', '/admin/sites/setup/save', ['mode' => 'off', 'csrf_token' => str_repeat('0', 64)]);
ok('S44 it refuses a form whose token is wrong, and changes nothing', storedWay() === 'on/session', said($answer) . '; stored: ' . storedWay());

// The other state of the one switch: on, in the SCRATCH COPY only.
$siteFile = $child . $sep . 'web' . $sep . '_core' . $sep . 'Site.php';
$original = (string) file_get_contents($siteFile);
$switched = str_replace('public const FIRST_PART_MODE_READY = false;', 'public const FIRST_PART_MODE_READY = true;', $original, $replaced);
if ($replaced !== 1) {
    ok('S45 the switch Site::FIRST_PART_MODE_READY is one line, written as this test expects', false, 'found ' . $replaced . ' such lines in web/_core/Site.php');
    ok('S46 with the switch on, "first part of the address" can be chosen', false, 'not tried');
    ok('S47 leaving that way sends the administrator to an address that still exists', false, 'not tried');
} else {
    file_put_contents($siteFile, $switched);
    $setup = req($jarG, 'GET', '/admin/sites/setup');
    ok('S45 with the switch on (in the scratch copy), the page no longer says "not ready" and the choice can be ticked', str_contains($setup['body'], 'Not ready') === false
        && preg_match('#<input[^>]*id="modePath"[^>]*value="path"[^>]*\sdisabled>#s', $setup['body']) !== 1, said($setup));
    $answer = post($jarG, '/admin/sites/setup', '/admin/sites/setup/save', ['mode' => 'path']);
    ok('S46 with the switch on, "first part of the address" can be chosen', storedWay() === 'on/path' && $answer['status'] === 302, 'stored: ' . storedWay() . '; ' . said($answer));
    // Now in that way, at one organisation's prefix: going back must not keep the prefix in the redirect.
    $answer = post($jarG, '/' . KEY_A . '/admin/sites/setup', '/' . KEY_A . '/admin/sites/setup/save', ['mode' => 'session']);
    ok('S47 leaving that way sends the administrator to an address that still exists (no organisation key left in it)', storedWay() === 'on/session' && $answer['location'] === '/admin/sites/setup', 'stored: ' . storedWay() . '; ' . said($answer));
    file_put_contents($siteFile, $original);
}
setWay(true, 'session');
ok('S48 the scratch copy\'s switch is off again', str_contains(req($jarG, 'GET', '/admin/sites/setup')['body'], 'Not ready') === true);
ok('S49 every change of the way, and every refusal, is in the activity log', logged(G, 'SiteSetupChange') === ($replaced === 1 ? 2 : 0) && logged(G, 'SiteSetupRefused') === 2,
    'changes: ' . logged(G, 'SiteSetupChange') . '; refusals: ' . logged(G, 'SiteSetupRefused'));

// ---- an organisation's own address, on the organisation save handler -----------
$saveC = static function (string $jar, string $address): array {
    return post($jar, '/admin/sites', '/admin/sites/save', [
        'siteID' => ORG_C, 'siteName' => NAME_C, 'siteKey' => KEY_C, 'hostPattern' => $address, 'logoPath' => '/assets/images/logo.svg',
        'faviconPath' => '', 'primaryColor' => '#5e6ad2', 'copyrightOrg' => '', 'timezone' => 'UTC', 'isActive' => '1',
    ]);
};
$hostOfC = static fn (): string => (string) (one('SELECT hostPattern FROM tblSites WHERE siteID = ?', 'i', [ORG_C])['hostPattern'] ?? '(none)');
$wrong   = [];
foreach (['https://x.modes.selftest.invalid/y', 'x.modes.selftest.invalid/y', 'x.modes.selftest.invalid:8080', 'x modes.selftest.invalid', '*.modes.selftest.invalid'] as $typed) {
    $saveC($jarG, $typed);
    if ($hostOfC() !== HOST_C || str_contains(req($jarG, 'GET', '/admin/sites')['body'], 'must be a plain host name') === false) {
        $wrong[] = '"' . $typed . '" left "' . $hostOfC() . '"';
    }
}
ok('S50 an own address that is not a plain host name is refused and nothing is saved (a web address, a path, a port, a space, a wildcard)', $wrong === [], implode('; ', $wrong));
$saveC($jarG, strtoupper(HOST_A));
ok('S51 an address another organisation holds is refused, by name, and nothing is saved', $hostOfC() === HOST_C && str_contains(req($jarG, 'GET', '/admin/sites')['body'], 'already belongs to the organisation') === true, 'C now has "' . $hostOfC() . '"');
$saveC($jarG, ' C2.Modes.Selftest.Invalid. ');
ok('S52 capitals, spaces round it and a trailing dot are tidied, and the tidy address is saved', $hostOfC() === 'c2.modes.selftest.invalid', 'C now has "' . $hostOfC() . '"');
$saveC($jarG, HOST_C);
ok('S53 an organisation can be saved again with its own address unchanged', $hostOfC() === HOST_C, 'C now has "' . $hostOfC() . '"');

// ---- switching organisations off ------------------------------------------------
// The LAST active organisation cannot be switched off. The test's own three are switched off straight in
// the database, which leaves the default organisation as the only one on; G then works in it.
q('UPDATE tblSites SET isActive = 0 WHERE siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
$rowBefore = organisationRow(1);
$answer    = saveOrganisation($jarG, 1, ['isActive' => false]);
$shown     = req($jarG, 'GET', '/admin/sites')['body'];
$rowAfter  = organisationRow(1);
q('UPDATE tblSites SET isActive = 1 WHERE siteID IN (1, ?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
ok('S69 the LAST organisation that is switched on cannot be switched off: nothing is saved, and the page says why',
    $rowAfter === $rowBefore && (int) $rowAfter['isActive'] === 1 && str_contains($shown, 'At least one organisation must stay switched on') === true,
    'the save answered ' . said($answer) . '; the default organisation is ' . ((int) ($rowAfter['isActive'] ?? -1) === 1 ? 'still on' : 'OFF') . '; row unchanged: ' . ($rowAfter === $rowBefore ? 'yes' : 'NO'));
$answer = saveOrganisation($jarG, ORG_C, ['isActive' => false]);
$cIsOff = ((int) organisationRow(ORG_C)['isActive'] === 0);
ok('S70 control: while another organisation is on, one CAN be switched off', $cIsOff === true, 'the save answered ' . said($answer) . '; C is ' . ($cIsOff === true ? 'off' : 'still on'));
// C is off now (set straight in the database too, so the next check does not rest on the one above).
q('UPDATE tblSites SET isActive = 0 WHERE siteID = ?', 'i', [ORG_C]);
$chooser = req($jarG, 'GET', '/site/choose');
preg_match_all('/name="site_id" value="(\d+)"/', $chooser['body'], $ids);
$offered = array_map('intval', $ids[1]);
sort($offered);
$answer = switchTo($jarG, ORG_C, '/account');
$seen   = orgSeen($jarG);
q('UPDATE tblSites SET isActive = 1 WHERE siteID = ?', 'i', [ORG_C]);
ok('S71 a switched-off organisation is not offered, even to a global administrator, and cannot be switched into',
    $offered === [1, ORG_A, ORG_B] && $answer['location'] === '/site/choose' && $seen === ORG_B, 'offered: ' . implode(', ', $offered) . '; the switch answered ' . said($answer) . '; then in ' . $seen . ' (B is ' . ORG_B . ')');

// With every active organisation given an address, "own address each" can be chosen.
q('UPDATE tblSites SET hostPattern = ? WHERE siteID = 1', 's', [HOST_1]);
$answer = post($jarG, '/admin/sites/setup', '/admin/sites/setup/save', ['mode' => 'subdomain']);
ok('S54 once every active organisation has an address of its own, "own address each" can be chosen', storedWay() === 'on/subdomain' && $answer['status'] === 302, 'stored: ' . storedWay() . '; ' . said($answer));
q('UPDATE tblSites SET hostPattern = NULL WHERE siteID = 1');

// =============================================================================
// H. OWN ADDRESS EACH
// =============================================================================
head('H. Each organisation on its own address');
setWay(true, 'subdomain');
$wrong = [];
foreach ([[HOST_A, ORG_A], [HOST_B, ORG_B], [strtoupper(HOST_A), ORG_A], [HOST_A . ':8080', ORG_A], [HOST_A . '.', ORG_A], [HOST_B . '.:8443', ORG_B], ['nobody.modes.selftest.invalid', 1]] as [$host, $expected]) {
    $seen = orgSeen($nobody, '', $host);
    if ($seen !== $expected) {
        $wrong[] = '"' . $host . '" came out as ' . $seen . ', expected ' . $expected;
    }
}
ok('H01 the address decides the organisation: capitals, a port and a trailing dot still match, and an unknown address is the default organisation', $wrong === [], implode('; ', $wrong));

$jarQH = signIn('q', '', HOST_A);
$page  = req($jarQH, 'GET', '/', null, ['host' => HOST_A]);
ok('H02 a member signs in at their organisation\'s address and is in it', $page['status'] === 200 && str_contains($page['body'], NAME_A) === true && orgSeen($jarQH, '', HOST_A) === ORG_A, said($page));
$jarPH = signIn('p', '', HOST_A);
$page  = req($jarPH, 'GET', '/', null, ['host' => HOST_A]);
ok('H03 the switcher links to the other organisation\'s own address and says the person signs in again there', $page['status'] === 200
    && str_contains($page['body'], 'href="//' . HOST_B . '/"') === true && str_contains($page['body'], 'You will sign in again there') === true && str_contains($page['body'], 'action="/site/switch"') === false, said($page));
$page = req($jarPH, 'GET', '/', null, ['host' => HOST_A . ':' . $port]);
ok('H04 a port in the present address is kept in that link', str_contains($page['body'], 'href="//' . HOST_B . ':' . $port . '/"') === true, said($page));
$answer = switchTo($jarPH, ORG_B, '/account', '', HOST_A);
ok('H05 the switch handler sends the person to the other organisation\'s address, not to the front page they are on', $answer['status'] === 302 && $answer['location'] === '//' . HOST_B . '/', said($answer));
$chooser = req($jarPH, 'GET', '/site/choose', null, ['host' => HOST_A]);
ok('H06 the chooser links to each organisation\'s address and explains the separate sign-in', $chooser['status'] === 200 && str_contains($chooser['body'], 'href="//' . HOST_B . '/"') === true
    && str_contains($chooser['body'], 'asked to sign in again') === true && str_contains($chooser['body'], 'name="site_id"') === false, said($chooser));
$onAtC = orgSeen($nobody, '', HOST_C);
q('UPDATE tblSites SET isActive = 0 WHERE siteID = ?', 'i', [ORG_C]);
$offAtC = orgSeen($nobody, '', HOST_C);
q('UPDATE tblSites SET isActive = 1 WHERE siteID = ?', 'i', [ORG_C]);
ok('H07 a switched-off organisation no longer answers at its own address: that address then opens the default organisation', $onAtC === ORG_C && $offAtC === 1, 'switched on: ' . $onAtC . ' (C is ' . ORG_C . '); switched off: ' . $offAtC);

// An ACTIVE organisation cannot be left with no address in this way: nobody could open it.
$jarGA  = signIn('g', '', HOST_A);
$atA    = ['host' => HOST_A];
$answer = saveOrganisation($jarGA, ORG_C, ['hostPattern' => ''], $atA);
$shown  = req($jarGA, 'GET', '/admin/sites', null, $atA)['body'];
$rowC   = organisationRow(ORG_C);
q('UPDATE tblSites SET hostPattern = ?, isActive = 1 WHERE siteID = ?', 'si', [HOST_C, ORG_C]);
ok('H08 while own addresses are in use, an organisation that is switched on cannot be saved with no address: nothing is saved, and the page says why',
    $rowC['hostPattern'] === HOST_C && (int) $rowC['isActive'] === 1 && str_contains($shown, 'without an address nobody can open it') === true,
    'the save answered ' . said($answer) . '; C then had address "' . ($rowC['hostPattern'] ?? '(none)') . '"');
// What the rule must NOT refuse. The default organisation has no address here, and is not unreachable:
// it answers at every address no other organisation holds (H01). And switching an organisation off
// with no address is always allowed.
$rowBefore = organisationRow(1);
$answer    = saveOrganisation($jarGA, 1, [], $atA);
$shown     = req($jarGA, 'GET', '/admin/sites', null, $atA)['body'];
$oneSaved  = ($rowBefore['hostPattern'] === null && organisationRow(1) === $rowBefore && str_contains($shown, 'updated successfully') === true);
saveOrganisation($jarGA, ORG_C, ['hostPattern' => '', 'isActive' => false], $atA);
$rowC = organisationRow(ORG_C);
q('UPDATE tblSites SET hostPattern = ?, isActive = 1 WHERE siteID = ?', 'si', [HOST_C, ORG_C]);
ok('H09 control: the default organisation, which has no address and answers wherever no other organisation does, can still be saved; and an organisation can be switched off with no address',
    $oneSaved === true && $rowC['hostPattern'] === null && (int) $rowC['isActive'] === 0,
    'default organisation saved unchanged: ' . ($oneSaved === true ? 'yes' : 'NO (' . said($answer) . ')') . '; C switched off with no address: ' . (($rowC['hostPattern'] === null && (int) $rowC['isActive'] === 0) ? 'yes' : 'NO'));

// The address look-up FAILS, for one request (see reqWhileFailing()). Nobody is signed in, so nothing
// but the gate's first rule stands between the visitor and the default organisation's page, shown on a guess.
$failed = reqWhileFailing('address', $nobody, '/login', ['host' => HOST_C]);
ok('H10 when the organisation cannot be worked out from the address, even a signed-out visitor is shown "try again" (503), not the default organisation\'s page on a guess',
    $failed['answer']['status'] === 503 && str_contains($failed['answer']['body'], 'Please try again') === true && str_contains($failed['answer']['body'], 'name="password"') === false
    && str_contains($failed['logged'], 'Site::preDetect failed') === true, said($failed['answer']) . '; the log gained: ' . substr(trim($failed['logged']), 0, 240));
ok('H11 control: with the table back, the same visitor gets that organisation\'s sign-in page', orgSeen($nobody, '', HOST_C) === ORG_C && str_contains(req($nobody, 'GET', '/login', null, ['host' => HOST_C])['body'], 'name="password"') === true, 'in ' . orgSeen($nobody, '', HOST_C));
$lang['own address'] = pageLanguage(req(signIn('l', '', HOST_A), 'GET', '/', null, ['host' => HOST_A]));

head('G/H. Only members get in — own address each (#511)');
$jarRH = signIn('r', '', HOST_B);
$jarNH = signIn('n', '', HOST_A);
$jarGH = signIn('g', '', HOST_A);
$atB   = ['host' => HOST_B];
// Q signed in at A's address. Their cookie is handed to B's address by hand: see req().
$qAtB  = ['host' => HOST_B, 'cookie' => cookieOf($jarQH)];
$page  = req($jarQH, 'GET', '/account', null, $atB);
ok('GH00 control: left to itself, a sign-in at A\'s address is not sent to B\'s at all, so there the person is simply signed out', $qAtB['cookie'] !== '' && $page['status'] === 302 && str_contains($page['location'], 'login') === true, said($page));
$page  = req('', 'GET', '/', null, $qAtB);
ok('GH01 a member of A whose sign-in reaches B\'s address is refused there (403), with nothing of B\'s in the answer', $page['status'] === 403 && bWords($page['body']) === '', said($page) . ' B words found: ' . bWords($page['body']));
foreach ([['GH02', '/attendance', 'B\'s attendance page, with its head counts', number_format(B_HEADCOUNT)],
    ['GH03', '/api/attendance/list', 'B\'s attendance list, a data address', 'MODESB-ATT-NOTE'],
    ['GH04', '/api/events/detail?id=' . EV_B, 'an internal event of B, through the data address (the case #511 proved)', 'MODESB internal event']] as [$id, $address, $what, $word]) {
    $control = req($jarRH, 'GET', $address, null, $atB);
    $answer  = req('', 'GET', $address, null, $qAtB);
    ok($id . ' NAMED CASE: ' . $what . ' — refused to a member of A (403, none of B\'s words), while B\'s own member is shown it',
        $control['status'] === 200 && str_contains($control['body'], $word) === true && $answer['status'] === 403 && bWords($answer['body']) === '',
        'B\'s member: ' . said($control) . (str_contains($control['body'], $word) === true ? ' with' : ' WITHOUT') . ' "' . $word . '"; the member of A: ' . said($answer) . ' B words found: ' . bWords($answer['body']));
}
// bWords() looks for the head count as a number standing on its own (see its note). This holds that
// search to the real page: if the page ever printed the number in a way the search misses, "none of
// B's words" above would pass on a page full of them.
$control = req($jarRH, 'GET', '/attendance', null, $atB);
ok('GH15 control: the search for B\'s words does find B\'s head count and B\'s texts on B\'s own attendance page', str_contains(bWords($control['body']), number_format(B_HEADCOUNT)) === true && str_contains(bWords($control['body']), 'MODESB') === true,
    said($control) . ' found: ' . bWords($control['body']));
$answer = req('', 'GET', '/api/attendance/list', null, $qAtB);
ok('GH05 a refused data address answers in JSON, not with a page', is_array(json_decode($answer['body'], true)) === true && str_contains($answer['head'], 'application/json') === true, substr($answer['body'], 0, 80));
$page = req('', 'GET', '/calendar', null, $qAtB);
ok('GH06 the refusal page has a title and exactly one top heading, and says who is signed in', $page['status'] === 403 && preg_match_all('#<h1[\s>]#', $page['body']) === 1
    && str_contains($page['body'], '<title>Not a member of this organisation - ') === true && str_contains($page['body'], 'q.modes@selftest.invalid') === true, said($page) . ' ' . shape($page));
ok('GH07 it links to the person\'s own organisation and to signing out, and does not name the organisation it refuses', str_contains($page['body'], 'href="//' . HOST_A . '/"') === true
    && str_contains($page['body'], NAME_A) === true && str_contains($page['body'], 'href="/logout"') === true && str_contains($page['body'], NAME_B) === false);
$answer = req('', 'POST', '/account/save', ['fullName' => 'x'], $qAtB);
ok('GH08 a form posted to B by a member of A is refused too', $answer['status'] === 403, said($answer));
$wrong = [];
foreach ([HOST_A, HOST_B, 'nobody.modes.selftest.invalid'] as $host) {
    $answer = req('', 'GET', '/', null, ['host' => $host, 'cookie' => cookieOf($jarNH)]);
    if ($answer['status'] !== 403 || str_contains($answer['body'], 'Ask an administrator') === false || bWords($answer['body']) !== '' || str_contains($answer['body'], NAME_A) === true) {
        $wrong[] = $host . ' gave ' . said($answer);
    }
}
ok('GH09 an account that belongs to no organisation is refused at every address and told to ask an administrator', $wrong === [], implode('; ', $wrong));
$page = req('', 'GET', '/attendance', null, ['host' => HOST_B, 'cookie' => cookieOf($jarGH)]);
ok('GH10 control: a global administrator passes', $page['status'] === 200 && str_contains($page['body'], number_format(B_HEADCOUNT)) === true, said($page));
$answer = req('', 'GET', '/api/attendance/list', null, ['bearer' => $keyB, 'host' => HOST_A]);
ok('GH11 control: an API key is unchanged — its own organisation\'s data, whatever address it is sent to', $answer['status'] === 200 && str_contains($answer['body'], 'MODESB-ATT-NOTE') === true, said($answer));
$page = req($nobody, 'GET', '/login', null, $atB);
ok('GH12 control: a signed-out visitor still gets B\'s sign-in page', $page['status'] === 200, said($page));
$page = req('', 'GET', '/site/choose', null, $qAtB);
ok('GH13 the refused person can open the chooser, which lists their own organisation only', $page['status'] === 200 && str_contains($page['body'], NAME_A) === true && bWords($page['body']) === '', said($page));
$answer = req('', 'GET', '/logout', null, $qAtB);
$after  = req('', 'GET', '/account', null, $qAtB);
ok('GH14 and can sign out: the sign-out page answers, and the same cookie is then signed out', $answer['status'] === 200 && $after['status'] === 302 && str_contains($after['location'], 'login') === true, said($answer) . '; afterwards ' . said($after));

head('E. The web server\'s own error page, which is reached without the gate');
// A fresh sign-in each: GH14 above signed Q's earlier one out.
$qAtB    = ['host' => HOST_B, 'cookie' => cookieOf(signIn('q', '', HOST_A))];
$control = req($jarRH, 'GET', '/error.php?code=404', null, $atB);
$visitor = req($nobody, 'GET', '/error.php?code=404', null, $atB);
ok('E01 control: a member, and a signed-out visitor, get the usual error page of the organisation the address belongs to, menu and all',
    $control['status'] === 404 && str_contains($control['body'], 'portal-navbar') === true && str_contains($control['body'], NAME_B) === true
    && $visitor['status'] === 404 && plainErrorPage($visitor) === false, 'B\'s member: ' . said($control) . ' ' . shape($control) . '; signed-out visitor: ' . said($visitor));
$page = req('', 'GET', '/error.php?code=404', null, $qAtB);
ok('E02 a member of A who meets that page at B\'s address gets the plain one: the same status, no menu, and nothing of B\'s',
    $page['status'] === 404 && plainErrorPage($page) === true && bWords($page['body']) === '' && str_contains($page['body'], 'Page Not Found') === true, said($page) . ' ' . shape($page) . ' B words found: ' . bWords($page['body']));
$page = req('', 'GET', '/error.php?code=403', null, ['host' => HOST_A, 'cookie' => cookieOf($jarNH)]);
ok('E03 so does an account that belongs to no organisation, at any organisation\'s address',
    $page['status'] === 403 && plainErrorPage($page) === true && str_contains($page['body'], NAME_A) === false, said($page) . ' ' . shape($page));

// =============================================================================
// P. FIRST PART OF THE ADDRESS
// =============================================================================
// =============================================================================
// P. FIRST PART OF THE ADDRESS
// =============================================================================
head('P. Organisations told apart by the first part of the address');
setWay(true, 'path');
$pa = '/' . KEY_A;
$pb = '/' . KEY_B;
ok('P01 the first part of the address decides the organisation, and an address without one is the default organisation',
    orgSeen($nobody, $pa) === ORG_A && orgSeen($nobody, $pb) === ORG_B && orgSeen($nobody) === 1, 'A: ' . orgSeen($nobody, $pa) . ', B: ' . orgSeen($nobody, $pb) . ', none: ' . orgSeen($nobody));
$jarQP = signIn('q', $pa);
$jarPP = signIn('p', $pa);
$page  = req($jarPP, 'GET', $pa . '/');
ok('P02 the switcher links to the other organisation\'s own prefix', $page['status'] === 200 && str_contains($page['body'], 'href="' . $pb . '/"') === true && str_contains($page['body'], 'action="/site/switch"') === false, said($page));
$answer = switchTo($jarPP, ORG_B, '/account', $pa);
ok('P03 the switch handler sends the person to the other organisation\'s prefix', $answer['status'] === 302 && $answer['location'] === $pb . '/', said($answer));
$wrong  = [];
$answer = req($jarPP, 'GET', $pa . '/site/switch');
if ($answer['location'] !== $pa . '/site/choose') {
    $wrong[] = 'opened without a form: ' . said($answer);
}
$answer = switchTo($jarPP, ORG_C, '/account', $pa);
if ($answer['location'] !== $pa . '/site/choose') {
    $wrong[] = 'an organisation that is not theirs: ' . said($answer);
}
$answer = req($jarPP, 'POST', $pa . '/site/switch', ['site_id' => ORG_B, 'csrf_token' => str_repeat('0', 64)]);
if ($answer['location'] !== $pa . '/site/choose') {
    $wrong[] = 'a wrong form token: ' . said($answer);
}
ok('P04 every other redirect of the switch handler stays inside the organisation\'s own prefix', $wrong === [], implode('; ', $wrong));
$chooser = req($jarPP, 'GET', $pa . '/site/choose');
ok('P05 the chooser links to each organisation\'s prefix, and its sign-out link keeps the prefix', $chooser['status'] === 200 && str_contains($chooser['body'], 'href="' . $pb . '/"') === true
    && str_contains($chooser['body'], 'href="' . $pa . '/"') === true && str_contains($chooser['body'], 'href="' . $pa . '/logout"') === true, said($chooser));
$lang['first part of the address'] = pageLanguage(req(signIn('l', $pa), 'GET', $pa . '/'));

head('G/P. Only members get in — first part of the address (#511)');
$jarRP = signIn('r', $pb);
$jarNP = signIn('n', $pa);
$jarGP = signIn('g', $pa);
$page  = req($jarQP, 'GET', $pb . '/');
ok('GP01 a member of A who opens B\'s prefix is refused (403), with nothing of B\'s in the answer', $page['status'] === 403 && bWords($page['body']) === '', said($page) . ' B words found: ' . bWords($page['body']));
foreach ([['GP02', '/attendance', 'B\'s attendance page, with its head counts', number_format(B_HEADCOUNT)],
    ['GP03', '/api/attendance/list', 'B\'s attendance list, a data address', 'MODESB-ATT-NOTE'],
    ['GP04', '/api/events/detail?id=' . EV_B, 'an internal event of B, through the data address (the case #511 proved)', 'MODESB internal event']] as [$id, $address, $what, $word]) {
    $control = req($jarRP, 'GET', $pb . $address);
    $answer  = req($jarQP, 'GET', $pb . $address);
    ok($id . ' NAMED CASE: ' . $what . ' — refused to a member of A (403, none of B\'s words), while B\'s own member is shown it',
        $control['status'] === 200 && str_contains($control['body'], $word) === true && $answer['status'] === 403 && bWords($answer['body']) === '',
        'B\'s member: ' . said($control) . (str_contains($control['body'], $word) === true ? ' with' : ' WITHOUT') . ' "' . $word . '"; the member of A: ' . said($answer) . ' B words found: ' . bWords($answer['body']));
}
$page = req($jarQP, 'GET', $pb . '/calendar');
ok('GP05 the refusal page links to the person\'s own organisation and to signing out, inside the right prefixes, and does not name the organisation it refuses',
    $page['status'] === 403 && str_contains($page['body'], 'href="' . $pa . '/"') === true && str_contains($page['body'], 'href="' . $pb . '/logout"') === true
    && str_contains($page['body'], 'q.modes@selftest.invalid') === true && str_contains($page['body'], NAME_B) === false && preg_match_all('#<h1[\s>]#', $page['body']) === 1, said($page) . ' ' . shape($page));
$page = req($jarQP, 'GET', '/account');
ok('GP06 the same person is refused at an address with no prefix: that is the default organisation, and they are not a member of it', $page['status'] === 403, said($page));
$page = req($jarQP, 'GET', $pa . '/account');
ok('GP07 control: in their own organisation nothing changes', $page['status'] === 200 && str_contains($page['body'], NAME_A) === true, said($page));
$answer = req($jarQP, 'POST', $pb . '/account/save', ['fullName' => 'x']);
ok('GP08 a form posted to B by a member of A is refused too', $answer['status'] === 403, said($answer));
$wrong = [];
foreach ([$pa . '/', $pb . '/', '/', $pb . '/api/attendance/list'] as $address) {
    $answer = req($jarNP, 'GET', $address);
    if ($answer['status'] !== 403 || str_contains($answer['body'], 'dministrator') === false || bWords($answer['body']) !== '' || str_contains($answer['body'], NAME_A) === true) {
        $wrong[] = $address . ' gave ' . said($answer);
    }
}
ok('GP09 an account that belongs to no organisation is refused at every address and told to ask an administrator', $wrong === [], implode('; ', $wrong));
$page = req($jarGP, 'GET', $pb . '/attendance');
ok('GP10 control: a global administrator passes', $page['status'] === 200 && str_contains($page['body'], number_format(B_HEADCOUNT)) === true, said($page));
$answer = req('', 'GET', $pa . '/api/attendance/list', null, ['bearer' => $keyB]);
ok('GP11 control: an API key is unchanged — its own organisation\'s data, whatever address it is sent to', $answer['status'] === 200 && str_contains($answer['body'], 'MODESB-ATT-NOTE') === true, said($answer));
// A page takes no notice of a key, so if a key's header alone switched the gate off, this page would be B's.
$answer = req($jarQP, 'GET', $pb . '/attendance', null, ['bearer' => 'wbms_' . str_repeat('0', 32)]);
ok('GP12 adding a made-up key to a signed-in request is no way round the gate', $answer['status'] === 403 && bWords($answer['body']) === '', said($answer) . ' B words found: ' . bWords($answer['body']));
$page = req($nobody, 'GET', $pb . '/login');
ok('GP13 control: a signed-out visitor still gets B\'s sign-in page', $page['status'] === 200, said($page));
$page = req($jarQP, 'GET', $pb . '/site/choose');
ok('GP14 the refused person can open the chooser, which lists their own organisation only', $page['status'] === 200 && str_contains($page['body'], 'href="' . $pa . '/"') === true && bWords($page['body']) === '', said($page));
$answer = req($jarQP, 'GET', $pb . '/logout');
$after  = req($jarQP, 'GET', $pa . '/account');
ok('GP15 and can sign out: the sign-out page answers, and they are then signed out', $answer['status'] === 200 && $after['status'] === 302 && str_contains($after['location'], 'login') === true, said($answer) . '; afterwards ' . said($after));

head('G/S. Only members get in — sign-in session (#511)');
setWay(true, 'session');
$jarQS = signIn('q');
$wrong = [];
foreach (['/attendance', '/api/attendance/list', '/api/events/detail?id=' . EV_B] as $address) {
    $answer = req($jarQS, 'GET', $address);
    if (bWords($answer['body']) !== '') {
        $wrong[] = $address . ' showed ' . bWords($answer['body']);
    }
}
ok('GS01 NAMED CASES in this way: a member of A is never shown B\'s attendance page, its attendance list or its internal event', $wrong === [] && orgSeen($jarQS) === ORG_A, implode('; ', $wrong) . ' in ' . orgSeen($jarQS));
$jarRS   = signIn('r');
$control = req($jarRS, 'GET', '/api/events/detail?id=' . EV_B);
ok('GS02 control: B\'s own member is shown them', orgSeen($jarRS) === ORG_B && $control['status'] === 200 && str_contains($control['body'], 'MODESB internal event') === true, 'in ' . orgSeen($jarRS) . '; ' . said($control));
$jarNS = signIn('n');
$page  = req($jarNS, 'GET', '/');
ok('GS03 an account in no organisation gets the refusal page, which says who is signed in and to ask an administrator, and names no organisation',
    $page['status'] === 403 && str_contains($page['body'], 'n.modes@selftest.invalid') === true && str_contains($page['body'], 'Ask an administrator') === true
    && str_contains($page['body'], $defaultOrg['siteName'] . '</') === false && str_contains($page['body'], 'href="/logout"') === true && preg_match_all('#<h1[\s>]#', $page['body']) === 1, said($page) . ' ' . shape($page));

$page = req(signIn('p'), 'GET', '/error.php?code=404');
ok('E04 in this way, a person with several organisations who has not chosen yet gets the plain error page too, with no organisation\'s name',
    $page['status'] === 404 && plainErrorPage($page) === true && str_contains($page['body'], NAME_A) === false && bWords($page['body']) === '', said($page) . ' ' . shape($page));

// =============================================================================
// L. The language
// =============================================================================
head('L. The language shown is the same in all three ways');
ok('L01 a person whose saved language is not the browser\'s is shown the same language in all three ways', count($lang) === 3 && count(array_unique($lang)) === 1 && in_array('(none)', $lang, true) === false,
    implode('; ', array_map(static fn (string $way, string $l): string => $way . ': ' . $l, array_keys($lang), $lang)));

// =============================================================================
// O. ONE ORGANISATION ONLY
// =============================================================================
head('O. One organisation only (multi-organisation switched off)');
setWay(false, 'session');
$jarU = signIn('u1');
$page = req($jarU, 'GET', '/');
ok('O01 everybody is in the one organisation, and the menu has no switcher', orgSeen($jarU) === 1 && orgSeen($nobody) === 1 && $page['status'] === 200 && str_contains($page['body'], 'Switch organisation') === false, 'in ' . orgSeen($jarU) . '; ' . said($page));
$jarQO = signIn('q');
$jarNO = signIn('n');
$pageQ = req($jarQO, 'GET', '/account');
$pageN = req($jarNO, 'GET', '/account');
ok('O02 the gate does nothing: an account with no membership row for the one organisation is not refused, as before', $pageQ['status'] === 200 && $pageN['status'] === 200 && orgSeen($jarQO) === 1, 'Q: ' . said($pageQ) . '; N: ' . said($pageN));
$answer = req($jarU, 'GET', '/site/choose');
ok('O03 there is nothing to choose: the chooser goes to the front page', $answer['status'] === 302 && $answer['location'] === '/', said($answer));
$page = req($jarQO, 'GET', '/error.php?code=404');
ok('E05 with one organisation the error page is the usual one for everybody, as before, even for an account with no membership row', $page['status'] === 404 && str_contains($page['body'], 'portal-navbar') === true, said($page) . ' ' . shape($page));
$jarGO = signIn('g');
$setup = req($jarGO, 'GET', '/admin/sites/setup');
ok('O04 the set-up page shows "One organisation only" as what is in use', $setup['status'] === 200 && preg_match('#<input[^>]*id="modeOff"[^>]*\schecked>#s', $setup['body']) === 1, said($setup));
$answer = post($jarGO, '/admin/sites/setup', '/admin/sites/setup/save', ['mode' => 'session']);
ok('O05 multi-organisation can be switched on from there, by the sign-in session', storedWay() === 'on/session', 'stored: ' . storedWay() . '; ' . said($answer));
$answer = post($jarGO, '/admin/sites/setup', '/admin/sites/setup/save', ['mode' => 'off']);
ok('O06 and switched off again', storedWay() === 'off/session', 'stored: ' . storedWay() . '; ' . said($answer));

// =============================================================================
// 🧹 Errors, and the clean-up
// =============================================================================
head('Errors and clean-up');
$errorLog = is_file($child . $sep . 'php-errors.log') === true ? trim((string) file_get_contents($child . $sep . 'php-errors.log')) : '';
$errorRows = q('SELECT errorTitle FROM tblErrors WHERE userID BETWEEN 917000 AND 917999 OR siteID IN (?, ?, ?)', 'iii', [ORG_A, ORG_B, ORG_C]);
ok('the portal logged no PHP warning or error during the run', $errorLog === '' && $errorRows === [], 'PHP error log: ' . substr($errorLog, 0, 400) . '; error rows: ' . json_encode(array_column($errorRows, 'errorTitle')));

cleanup();
$left = (int) (one(
    'SELECT (SELECT COUNT(*) FROM tblSites WHERE siteID IN (?, ?, ?)) + (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 917000 AND 917999) '
    . '+ (SELECT COUNT(*) FROM tblUserSites WHERE userID BETWEEN 917000 AND 917999) + (SELECT COUNT(*) FROM tblEvents WHERE eventID BETWEEN 917000 AND 917999) '
    . '+ (SELECT COUNT(*) FROM tblAttendanceServiceTypes WHERE serviceTypeID BETWEEN 917000 AND 917999) + (SELECT COUNT(*) FROM tblActivityLogs WHERE userID BETWEEN 917000 AND 917999) '
    . "+ (SELECT COUNT(*) FROM tblOffsiteSyncLog WHERE destination = 'selftest-modes') + (SELECT COUNT(*) FROM tblSites WHERE siteID = 1 AND hostPattern IS NOT NULL) "
    . "+ (SELECT COUNT(*) FROM tblSettings WHERE settingKey LIKE 'multisite.%' AND siteID IS NOT NULL) AS n",
    'iii', [ORG_A, ORG_B, ORG_C]
)['n'] ?? -1);
$settingsBack = true;
foreach ($before as $key => $row) {
    $settingsBack = $settingsBack && (one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]) === $row);
}
ok('clean-up: the made-up world is gone, the default organisation has no address again, and the two portal-wide settings are as they were', $left === 0 && $settingsBack === true, 'rows left: ' . $left . '; settings back: ' . ($settingsBack === true ? 'yes' : 'NO'));
ok('clean-up: the server is stopped and the scratch folder is deleted', portAnswers($port) === false && file_exists($child) === false);

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
echo "PASS — all {$pass} checks passed. The run reached its end.\n";
exit(0);
