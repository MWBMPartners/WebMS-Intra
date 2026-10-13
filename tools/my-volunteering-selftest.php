<?php
// Path: tools/my-volunteering-selftest.php
/**
 * -----------------------------------------------------------------------------
 * "My volunteering" self-test, against a real database 🤝🧪 (#547)
 * -----------------------------------------------------------------------------
 * Proves that `Portal\Core\Events::volunteeringFor()` — the query behind the
 * /account/my-volunteering page — runs on MySQL 8's default strict grouping
 * mode and gives exactly the right events, roles and coordinator flags.
 *
 * WHY IT EXISTS
 * -------------------------------
 * The page's query grouped by event but also picked a column that was neither
 * grouped nor wrapped in a function. MySQL 8 refuses that by default
 * (ONLY_FULL_GROUP_BY, error 1055), so the page crashed for everybody who
 * opened it. Nothing caught it because the query sat inside the page, and a
 * page cannot be run without a browser. The query now lives in a method, which
 * this file runs.
 *
 * A TEST THAT CANNOT FAIL PROVES NOTHING, so it first checks two things:
 *   1. The connection's sql_mode really contains ONLY_FULL_GROUP_BY. A lenient
 *      server would let the old fault through and the test would pass on
 *      broken code.
 *   2. The OLD query (copied word for word below) really is refused with error
 *      1055 on this server. That shows this setup is able to catch the fault.
 *
 * THE MADE-UP WORLD (every row has a number of 900000 or more)
 * ------------------------------------------------------------
 *   UA  has a role on event 1, on event 9 (sooner than 1, to test the order),
 *       and also on four events that must NOT appear: another organisation's
 *       (E5), a copied-in calendar event (E6), a deleted one (E7), and one that
 *       ended more than a day ago (E8).
 *   UB  coordinates event 2 and has no role anywhere.
 *   UC  has two roles AND coordinates event 3 (the case that made the old
 *       query pick between two rows). UC's coordinator row on event 4 is
 *       revoked, so event 4 must not appear. UC is also a coordinator of the
 *       copied-in event E6, which must not appear either.
 *   UD  has nothing at all: an empty list, not an error.
 *   UE  has a role on 32 events: only the first 30 come back, soonest first.
 *
 * PART 2 runs the real page file (web/_apps/account/my-volunteering.php) once
 * for each kind of member in a separate PHP process, with the page header and
 * footer replaced by empty stand-ins and the sign-in check replaced by a
 * stand-in that says "this person is signed in". So it proves the page's own
 * code works with the new method and shows the events. WHAT IT CANNOT PROVE:
 * that the router reaches the page, or that the real header and footer draw
 * correctly. No error row is written in either case because this process does
 * not install the portal's error handler; instead it makes the database
 * connection throw on any SQL error, so a 1055 would end the page loudly.
 *
 * SAFETY
 * ------
 * It writes to the database it is given, so it REFUSES to run unless the
 * database's name starts with `selftest_`. It removes its rows at the end. It
 * never reads anything under web/_auth_keys. Connection variables:
 *
 *   SELFTEST_DB_HOST (default 127.0.0.1)   SELFTEST_DB_PORT (default 3306)
 *   SELFTEST_DB_USER (default root)        SELFTEST_DB_PASS (default empty)
 *   SELFTEST_DB_NAME (required; must start with selftest_)
 *
 * Optional: SELFTEST_VOLUNTEERING_CORE=<folder> runs against another copy of
 * web/_core (used to show the test FAILS on a planted fault).
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x php tools/my-volunteering-selftest.php
 * Exit:   0 when every check passes, 1 otherwise (including when it refuses,
 *         or cannot reach the database: a test that did not run proved nothing).
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/547
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// Anything thrown outside the checks (database unreachable, a missing table)
// would end PHP with code 255 and a stack trace; the header promises 0 or 1.
set_exception_handler(static function (Throwable $e): void {
    echo 'FAIL — the self-test stopped early: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
});

$repo = dirname(__DIR__);
$core = getenv('SELFTEST_VOLUNTEERING_CORE') !== false && getenv('SELFTEST_VOLUNTEERING_CORE') !== ''
    ? (string) getenv('SELFTEST_VOLUNTEERING_CORE')
    : $repo . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core';

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    echo "This test writes rows, so it only ever runs on a throwaway database. Nothing was checked.\n";
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$db->set_charset('utf8mb4');

// The class under test. A missing file is a FAIL (via the handler above).
require $core . DIRECTORY_SEPARATOR . 'Events.php';

use Portal\Core\Events;

$failures = 0;

/** Record one check. */
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok === true) {
        echo 'PASS — ' . $label . "\n";
        return;
    }
    $failures++;
    echo 'FAIL — ' . $label . ($detail !== '' ? ' (' . $detail . ')' : '') . "\n";
}

/** Run a statement with bound values; return the rows of a SELECT. */
function run(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

// Fixture numbers. All 900000 or more so they cannot clash with real rows.
const ORG   = 900001;
const OTHER = 900002;
const UA = 900011;
const UB = 900012;
const UC = 900013;
const UD = 900014;
const UE = 900015;
const E1 = 900101; const E2 = 900102; const E3 = 900103; const E4 = 900104; const E5 = 900105;
const E6 = 900106; const E7 = 900107; const E8 = 900108; const E9 = 900109;
const E_MANY = 900200; // 900200 .. 900231, thirty-two events

/** Remove every fixture row. Children (team, coordinators) go with their event. */
function wipe(mysqli $db): void
{
    run($db, 'DELETE FROM tblEvents WHERE eventID >= 900000');
    run($db, 'DELETE FROM tblUsers WHERE userID >= 900000');
    run($db, 'DELETE FROM tblSites WHERE siteID >= 900000');
}

/** Insert an event starting `$days` from now (negative = in the past). */
function addEvent(mysqli $db, int $id, int $site, int $days, ?int $feed = null, int $deleted = 0): void
{
    run(
        $db,
        // The zone is named (#604: an event with none is refused by the database). 'UTC' is a test value.
        'INSERT INTO tblEvents (eventID, siteID, eventName, eventSlug, startDateTime, timezone, status, isDeleted, externalFeedID) '
        . "VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), 'UTC', 'published', ?, ?)",
        'iissiii',
        [$id, $site, 'Selftest event ' . $id, 'selftest-' . $id, $days, $deleted, $feed]
    );
}

/** Put a person on an event's team with one role. */
function addRole(mysqli $db, int $event, int $user, string $role): void
{
    run($db, 'INSERT INTO tblEventPeople (eventID, userID, role) VALUES (?, ?, ?)', 'iis', [$event, $user, $role]);
}

/** Make a person a coordinator of an event (optionally already revoked). */
function addCoordinator(mysqli $db, int $event, int $user, bool $revoked = false): void
{
    run(
        $db,
        'INSERT INTO tblEventCoordinators (eventID, userID, revokedAt) VALUES (?, ?, ' . ($revoked ? 'NOW()' : 'NULL') . ')',
        'ii',
        [$event, $user]
    );
}

// =============================================================================
// 🛡️ Part 0: make sure this setup could catch the fault at all
// =============================================================================

$mode = (string) (run($db, 'SELECT @@SESSION.sql_mode AS m')[0]['m'] ?? '');
echo "Database: {$dbName}; sql_mode: {$mode}\n";
if (str_contains($mode, 'ONLY_FULL_GROUP_BY') === false) {
    echo "REFUSED — this server's sql_mode lacks ONLY_FULL_GROUP_BY, so the old fault would pass unnoticed. Nothing was checked.\n";
    exit(1);
}
check('the connection uses ONLY_FULL_GROUP_BY (MySQL 8 default)', true);

// Start from a clean slate, in case an earlier run was interrupted.
wipe($db);

// The OLD query, word for word as it sat in the page before #547. It must be
// refused here; if it is not, this test could not have seen the fault.
$oldSql = 'SELECT e.eventID, e.eventName, e.eventSlug, e.startDateTime, e.endDateTime, '
    . '       e.locationName, e.status, e.description, '
    . '       GROUP_CONCAT(DISTINCT ep.role ORDER BY ep.role SEPARATOR ", ") AS myRoles, '
    . '       CASE WHEN ec.coordinatorID IS NOT NULL THEN 1 ELSE 0 END AS amCoordinator '
    . 'FROM tblEvents e '
    . 'LEFT JOIN tblEventPeople ep ON ep.eventID = e.eventID AND ep.userID = ? '
    . 'LEFT JOIN tblEventCoordinators ec ON ec.eventID = e.eventID AND ec.userID = ? AND ec.revokedAt IS NULL '
    . 'WHERE e.siteID = ? AND e.isDeleted = 0 AND e.externalFeedID IS NULL '
    . '  AND e.startDateTime >= DATE_SUB(NOW(), INTERVAL 1 DAY) '
    . '  AND (ep.eventPersonID IS NOT NULL OR ec.coordinatorID IS NOT NULL) '
    . 'GROUP BY e.eventID ORDER BY e.startDateTime ASC LIMIT 30';
$code = 0;
try {
    $zero = 0;
    run($db, $oldSql, 'iii', [$zero, $zero, $zero]);
} catch (mysqli_sql_exception $e) {
    $code = $e->getCode();
}
check('the OLD query is refused with error 1055 on this server (so this test can see the fault)', $code === 1055, 'error code was ' . $code);

// =============================================================================
// 🌍 Part 1: the method, on a made-up world
// =============================================================================

run($db, "INSERT INTO tblSites (siteID, siteName, siteKey) VALUES (?, 'Selftest org', 'selftest-547-a'), (?, 'Selftest other org', 'selftest-547-b')", 'ii', [ORG, OTHER]);
foreach ([UA => 'a', UB => 'b', UC => 'c', UD => 'd', UE => 'e'] as $id => $letter) {
    run($db, 'INSERT INTO tblUsers (userID, fullName, emailAddress) VALUES (?, ?, ?)', 'iss', [$id, 'Selftest volunteer ' . $letter, 'selftest-547-' . $letter . '@invalid.test']);
}

addEvent($db, E1, ORG, 5);
addEvent($db, E2, ORG, 6);
addEvent($db, E3, ORG, 7);
addEvent($db, E4, ORG, 8);
addEvent($db, E5, OTHER, 9);
// A copied-in calendar event: any non-empty externalFeedID counts as imported.
// The feed id has no row behind it here: tblEvents has no foreign key on it.
addEvent($db, E6, ORG, 10, 900001);
addEvent($db, E7, ORG, 11, null, 1);
addEvent($db, E8, ORG, -3);
addEvent($db, E9, ORG, 1);

addRole($db, E1, UA, 'host');
addRole($db, E9, UA, 'musician');
foreach ([E5, E6, E7, E8] as $hidden) {
    addRole($db, $hidden, UA, 'host');
}
addCoordinator($db, E2, UB);
addRole($db, E3, UC, 'speaker');
addRole($db, E3, UC, 'host');
addCoordinator($db, E3, UC);
addCoordinator($db, E4, UC, true);   // revoked: must not count
addCoordinator($db, E6, UC);         // imported: must not appear
// Somebody else's role on an event UD can see nothing of.
addRole($db, E1, UB, 'usher');

/** Shorten a result to "eventID:roles:coordinator" strings, in order. */
function shape(array $rows): array
{
    return array_map(static fn (array $r): string => $r['eventID'] . ':' . (string) ($r['myRoles'] ?? '') . ':' . $r['amCoordinator'], $rows);
}

$ua = Events::volunteeringFor($db, UA, ORG);
check('UA (roles only): events 9 then 1, soonest first, not a coordinator', shape($ua) === [E9 . ':musician:0', E1 . ':host:0'], json_encode(shape($ua)));
check('UA: the other organisation, imported, deleted and long-past events stay out',
    array_intersect(array_map('intval', array_column($ua, 'eventID')), [E5, E6, E7, E8]) === []);

$ub = Events::volunteeringFor($db, UB, ORG);
check('UB (coordinator on 2, role on 1): events 1 and 2; only 2 flagged as coordinator',
    shape($ub) === [E1 . ':usher:0', E2 . '::1'], json_encode(shape($ub)));

$uc = Events::volunteeringFor($db, UC, ORG);
check('UC (two roles AND coordinator on 3): event 3 once, both roles, coordinator 1; revoked 4 and imported 6 stay out',
    shape($uc) === [E3 . ':host, speaker:1'], json_encode(shape($uc)));

check('UD (nothing): an empty list, no error', Events::volunteeringFor($db, UD, ORG) === []);
check('UA asked about the other organisation: only that organisation\'s event',
    shape(Events::volunteeringFor($db, UA, OTHER)) === [E5 . ':host:0']);

// Thirty-two events with a role: only thirty, the soonest, in order.
for ($i = 0; $i < 32; $i++) {
    addEvent($db, E_MANY + $i, ORG, 20 + $i);
    addRole($db, E_MANY + $i, UE, 'helper');
}
$ue = Events::volunteeringFor($db, UE, ORG);
$ids = array_map('intval', array_column($ue, 'eventID'));
check('UE (32 events): exactly 30 come back', count($ue) === 30, 'got ' . count($ue));
check('UE: they are the 30 soonest, in order', $ids === range(E_MANY, E_MANY + 29));

// Every column the page reads must be present on a row.
$need = ['eventID', 'eventName', 'eventSlug', 'startDateTime', 'endDateTime', 'locationName', 'status', 'description', 'myRoles', 'amCoordinator'];
check('a row carries every column the page reads', $ua !== [] && array_diff($need, array_keys($ua[0])) === []);

// =============================================================================
// 📄 Part 2: the real page file, once for each kind of member
// =============================================================================

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mv547-' . bin2hex(random_bytes(4));
mkdir($scratch . DIRECTORY_SEPARATOR . 'templates', 0700, true);
file_put_contents($scratch . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php', "<?php // stand-in header\n");
file_put_contents($scratch . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php', "<?php // stand-in footer\n");
$runner = $scratch . DIRECTORY_SEPARATOR . 'run-page.php';
// The runner: stands in for the sign-in check and the organisation lookup, lets
// the real Events class load, and then includes the real page file.
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
namespace Portal\Core {
    // Stand-ins: the page only asks these two classes three questions.
    class Auth { public static function ensureSession(): void {} public static function requireLogin(): void {} }
    class Site { public static function id(): int { return (int) getenv('MV_SITE'); } }
}
namespace {
    [, $core, $page, $host, $user, $pass, $name, $port] = $argv;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // a SQL error ends the page loudly
    define('PORTAL_CORE', $argv[8]);                            // stand-in templates folder
    require $core . DIRECTORY_SEPARATOR . 'Events.php';
    $mysqli = new mysqli($host, $user, $pass, $name, (int) $port);
    $mysqli->set_charset('utf8mb4');
    $_SESSION = ['user_id' => (int) getenv('MV_USER')];
    require $page;
    echo "\nPAGE-REACHED-ITS-END\n";
}
PHP);

$errorsBefore = (int) run($db, 'SELECT COUNT(*) AS c FROM tblErrors')[0]['c'];
$page = $repo . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps' . DIRECTORY_SEPARATOR . 'account' . DIRECTORY_SEPARATOR . 'my-volunteering.php';
$cases = [
    'a member with a role only (UA)'        => [UA, ['Selftest event ' . E9, 'Selftest event ' . E1], ['Coordinator</span>']],
    'a coordinator only (UB, plus a role)'  => [UB, ['Selftest event ' . E2, 'Coordinator'], []],
    'a role AND coordinator (UC)'           => [UC, ['Selftest event ' . E3, 'Coordinator'], []],
    'a member with nothing (UD)'            => [UD, ['not currently rostered'], ['Selftest event']],
];
foreach ($cases as $label => [$user, $mustShow, $mustNotShow]) {
    $out = [];
    $exit = 0;
    $cmd = [PHP_BINARY, $runner, $core, $page, $dbHost, $dbUser, $dbPass, $dbName, (string) $dbPort, $scratch];
    $proc = proc_open(
        $cmd,
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        array_merge(getenv(), ['MV_USER' => (string) $user, 'MV_SITE' => (string) ORG])
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    $ok = $exit === 0 && str_contains($stdout, 'PAGE-REACHED-ITS-END') === true;
    foreach ($mustShow as $needle) {
        $ok = $ok && str_contains($stdout, $needle);
    }
    foreach ($mustNotShow as $needle) {
        $ok = $ok && str_contains($stdout, $needle) === false;
    }
    check('page: ' . $label . ' gets the page', $ok, 'exit ' . $exit . ', ' . substr(trim($stderr . ' ' . strip_tags($stdout)), 0, 200));
}
$errorsAfter = (int) run($db, 'SELECT COUNT(*) AS c FROM tblErrors')[0]['c'];
check('page: the error log (tblErrors) gained no row', $errorsAfter === $errorsBefore, "before {$errorsBefore}, after {$errorsAfter}");

// Remove scratch files and the fixture; a failure to clean up is itself a FAIL.
@unlink($runner);
@unlink($scratch . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php');
@unlink($scratch . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php');
@rmdir($scratch . DIRECTORY_SEPARATOR . 'templates');
@rmdir($scratch);
wipe($db);
$left = (int) run($db, 'SELECT (SELECT COUNT(*) FROM tblEvents WHERE eventID >= 900000) + (SELECT COUNT(*) FROM tblUsers WHERE userID >= 900000) + (SELECT COUNT(*) FROM tblSites WHERE siteID >= 900000) AS c')[0]['c'];
check('the made-up rows were all removed', $left === 0, $left . ' left');

echo $failures === 0 ? "PASS — my-volunteering self-test: every check passed and the run reached its end.\n" : "FAIL — {$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
