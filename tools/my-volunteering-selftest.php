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
 *   UF  (#1025) has a role on an event in Pago Pago (UTC-11) that began 23
 *       hours ago on its own clock, and one in Kiritimati (UTC+14) that began
 *       25 hours ago: the day of grace is a day on EACH EVENT's clock, so the
 *       first stays and the second goes. The old bound, the database's NOW()
 *       less a day, did the reverse (checked as a premise first).
 *   UG  (#1025) has a role on 30 events on UTC and one in Auckland at 20:00
 *       the same date: soonest in real time, latest by reading. The 30 that
 *       come back are Auckland and the first 29 UTC ones, in that order.
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
// #1025: volunteeringFor() now asks each event's own clock
// (Events::nowOnItsOwnClock()) and orders by the real moment
// (Events::startAsUtcMoment()). Both lean on I18n, which stands alone, and on
// two questions to Site: the organisation's zone and "which clock is this
// stored zone text". Site cannot be loaded without the portal's start-up, so
// a STAND-IN answers those two, written to a scratch file shared with the
// page runner below. It is not under test: it reads the zone the fixture
// stored for the organisation, and opens a stored zone text the way
// Site::zoneFor() does (the organisation's zone when PHP cannot open it).
require $core . DIRECTORY_SEPARATOR . 'I18n.php';
$siteStandIn = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mv1025-site-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($siteStandIn, <<<'PHP'
<?php
declare(strict_types=1);
namespace Portal\Core;
class Site {
    public static ?\mysqli $db = null;
    public static function id(): int { return (int) getenv('MV_SITE'); }
    public static function url(string $routeKey): string { return '/' . ltrim($routeKey, '/'); }
    public static function timezone(?int $siteId = null): string
    {
        $siteId = $siteId ?? self::id();
        $stmt = self::$db->prepare('SELECT `timezone` FROM `tblSites` WHERE `siteID` = ?');
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $zone = (string) ($stmt->get_result()->fetch_assoc()['timezone'] ?? '');
        $stmt->close();
        return in_array($zone, \DateTimeZone::listIdentifiers(), true) === true ? $zone : 'UTC';
    }
    public static function zoneFor(string $stored, ?int $siteId = null): \DateTimeZone
    {
        try {
            return trim($stored) === '' ? new \DateTimeZone(self::timezone($siteId)) : new \DateTimeZone(trim($stored));
        } catch (\Throwable $e) {
            return new \DateTimeZone(self::timezone($siteId));
        }
    }
}
PHP);
require $siteStandIn;
\Portal\Core\Site::$db = $db;

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
// 🕰️ Part 1b (#1025): the day of grace and the order are on EACH EVENT'S clock
// =============================================================================
// The method used to bound the list with the DATABASE SERVER's NOW() less a
// day and order it by the stored READING. Readings are on each event's own
// clock, so both were wrong for events on clocks far from the database's.
// This server's NOW() is asked below and every premise is checked first, so
// none of these checks can pass by accident on the old query.

/** Insert an event at a given READING on a given clock. */
function addEventAt(mysqli $db, int $id, int $site, string $reading, string $zone): void
{
    run(
        $db,
        'INSERT INTO `tblEvents` (`eventID`, `siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`, `isDeleted`) '
        . "VALUES (?, ?, ?, ?, ?, ?, 'published', 0)",
        'iissss',
        [$id, $site, 'Selftest event ' . $id, 'selftest-' . $id, $reading, $zone]
    );
}

const UF = 900016; // the day of grace
const UG = 900017; // the order, and the 30-row cut
const E_PAGO = 900301; // Pago Pago (UTC-11), began 23 hours ago on its own clock: still in its day of grace
const E_KIRI = 900302; // Kiritimati (UTC+14), began 25 hours ago on its own clock: past its day of grace
const E_AKL  = 900303; // Auckland, 20:00 on a date: the soonest real moment of UG's 31 events
const E_UTC  = 900310; // 900310 .. 900339: thirty events on UTC, 09:00 onwards that same date

run($db, 'UPDATE `tblSites` SET `timezone` = ? WHERE `siteID` = ?', 'si', ['UTC', ORG]);
foreach ([UF => 'f', UG => 'g'] as $id => $letter) {
    run($db, 'INSERT INTO tblUsers (userID, fullName, emailAddress) VALUES (?, ?, ?)', 'iss', [$id, 'Selftest volunteer ' . $letter, 'selftest-547-' . $letter . '@invalid.test']);
}

$readingOn = static fn (string $zone, string $shift): string
    => (new DateTimeImmutable('now', new DateTimeZone($zone)))->modify($shift)->format('Y-m-d H:i:s');
$pagoStart = $readingOn('Pacific/Pago_Pago', '-23 hours');
$kiriStart = $readingOn('Pacific/Kiritimati', '-25 hours');
$dbDayAgo  = (string) run($db, 'SELECT DATE_SUB(NOW(), INTERVAL 1 DAY) AS d')[0]['d'];
check(
    '#1025 premise: the Pago Pago reading is BEFORE the database\'s NOW() less a day, and the Kiritimati one AFTER it -- so the old bound answers both the wrong way',
    strcmp($pagoStart, $dbDayAgo) < 0 && strcmp($kiriStart, $dbDayAgo) > 0,
    'Pago Pago ' . $pagoStart . ', Kiritimati ' . $kiriStart . ', database NOW() less a day ' . $dbDayAgo
);
addEventAt($db, E_PAGO, ORG, $pagoStart, 'Pacific/Pago_Pago');
addEventAt($db, E_KIRI, ORG, $kiriStart, 'Pacific/Kiritimati');
addRole($db, E_PAGO, UF, 'host');
addRole($db, E_KIRI, UF, 'host');
$uf = array_map('intval', array_column(Events::volunteeringFor($db, UF, ORG), 'eventID'));
check(
    '#1025 the day of grace is a day on the EVENT\'s clock: the Pago Pago event that began 23 hours ago is kept, the Kiritimati one that began 25 hours ago is not',
    $uf === [E_PAGO],
    json_encode($uf)
);

// The order. A date forty days on; Auckland 20:00 that day is earlier in real
// time than 09:00 on UTC the same day (Auckland is twelve or thirteen hours
// ahead), so it is the SOONEST of the 31 -- and by reading it is the LATEST,
// so the old ORDER BY put it 31st and LIMIT 30 cut it off.
$day      = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+40 days')->format('Y-m-d');
$aklStart = $day . ' 20:00:00';
addEventAt($db, E_AKL, ORG, $aklStart, 'Pacific/Auckland');
addRole($db, E_AKL, UG, 'host');
for ($i = 0; $i < 30; $i++) {
    addEventAt($db, E_UTC + $i, ORG, (new DateTimeImmutable($day . ' 09:00:00', new DateTimeZone('UTC')))->modify('+' . (10 * $i) . ' minutes')->format('Y-m-d H:i:s'), 'UTC');
    addRole($db, E_UTC + $i, UG, 'helper');
}
$aklMoment = (new DateTimeImmutable($aklStart, new DateTimeZone('Pacific/Auckland')))->getTimestamp();
check(
    '#1025 premise: the Auckland event really starts BEFORE 09:00 UTC that day, though its reading sorts after every UTC one',
    $aklMoment < (new DateTimeImmutable($day . ' 09:00:00', new DateTimeZone('UTC')))->getTimestamp() && strcmp($aklStart, $day . ' 13:50:00') > 0,
    'Auckland ' . gmdate('Y-m-d H:i', $aklMoment) . ' UTC'
);
$ug = array_map('intval', array_column(Events::volunteeringFor($db, UG, ORG), 'eventID'));
check(
    '#1025 the thirty soonest by REAL moment come back in that order: Auckland first, then the first 29 UTC events (the 30th UTC one is the one cut)',
    $ug === array_merge([E_AKL], range(E_UTC, E_UTC + 28)),
    'first three ' . json_encode(array_slice($ug, 0, 3)) . ', count ' . count($ug) . ', last ' . json_encode(end($ug))
);

// =============================================================================
// 🕰️ Part 1c (#1025, round 2 of its check): across CLOCK CHANGES, and with
//    dates no calendar should hold
// =============================================================================
// Round 2 found two things nothing pinned. (1) The order across a clock
// change: every fixture above sits weeks away with no change between, so
// switching off the clock-change half of Events::startAsUtcMoment() left
// every check green. (2) One event dated 9999-12-31 or 0001-01-01 -- even a
// DELETED one -- made the whole list throw, and one in 9990 built a statement
// MySQL refused. Both are fixtures here now.
//
// The clock-change fixtures: in London and in Auckland (opposite
// hemispheres), the next change each way, at least five days on. For each
// change, an event at 12:00 two days BEFORE it and one two days AFTER it, and
// beside each a UTC event at the moment exactly HALF WAY between that event's
// real instant and the instant it would have on the OTHER side's offset. So
// an event given the wrong offset swaps places with its UTC partner, whichever
// side of the change it is on.

const UH     = 900018;
const E_CLK  = 900400; // 900400 .. the clock-change events
const E_FAR  = 900500; // 900500 .. the far-off dates

/**
 * The clock-change fixture: [reading, zone] pairs in insertion order, and
 * their ids in REAL order (instant, then reading, then id -- the house tie
 * rule) and in READING order.
 *
 * @return array{rows: list<array{0:string,1:string}>, real: list<int>, reading: list<int>}
 */
function clockChangeWorld(int $firstId, array $zones, int $changesPerZone): array
{
    $rows = [];
    $now  = time();
    foreach ($zones as $zoneName) {
        $zone   = new DateTimeZone($zoneName);
        $states = $zone->getTransitions($now + 5 * 86400, $now + 800 * 86400);
        for ($i = 1; $i < count($states) && $i <= $changesPerZone; $i++) {
            $old = (int) $states[$i - 1]['offset'];
            $new = (int) $states[$i]['offset'];
            $day = gmdate('Y-m-d', (int) $states[$i]['ts'] + $new);
            foreach ([['-2 days', $new], ['+2 days', $old]] as [$shift, $wrongOffset]) {
                $reading = (new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('UTC')))->modify($shift)->format('Y-m-d H:i:s');
                $real    = (new DateTimeImmutable($reading, $zone))->getTimestamp();
                $asIf    = (new DateTimeImmutable($reading, new DateTimeZone('UTC')))->getTimestamp() - $wrongOffset;
                $rows[]  = [$reading, $zoneName];
                $rows[]  = [gmdate('Y-m-d H:i:s', intdiv($real + $asIf, 2)), 'UTC'];
            }
        }
    }
    $keyed = [];
    foreach ($rows as $n => [$reading, $zoneName]) {
        $keyed[] = [(new DateTimeImmutable($reading, new DateTimeZone($zoneName)))->getTimestamp(), $reading, $firstId + $n];
    }
    $real = $keyed;
    usort($real, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
    $byReading = $keyed;
    usort($byReading, static fn (array $a, array $b): int => [$a[1], $a[2]] <=> [$b[1], $b[2]]);

    return ['rows' => $rows, 'real' => array_column($real, 2), 'reading' => array_column($byReading, 2)];
}

run($db, 'INSERT INTO tblUsers (userID, fullName, emailAddress) VALUES (?, ?, ?)', 'iss', [UH, 'Selftest volunteer h', 'selftest-547-h@invalid.test']);
$clk = clockChangeWorld(E_CLK, ['Europe/London', 'Pacific/Auckland'], 2);
foreach ($clk['rows'] as $n => [$reading, $zone]) {
    addEventAt($db, E_CLK + $n, ORG, $reading, $zone);
    addRole($db, E_CLK + $n, UH, 'helper');
}
check(
    '#1025 round 2 premise: ' . count($clk['rows']) . ' events straddle two clock changes in London and two in Auckland, and their real order is NOT their reading order',
    count($clk['rows']) === 16 && $clk['real'] !== $clk['reading'],
    'rows ' . count($clk['rows'])
);

// Far-off dates: a DELETED event at the very end of the calendar and one at
// its very start, and a live one in 9990 on a fifth clock. None has a role
// for UH, so the list below is unchanged by them -- but every statement reads
// the organisation's span of dates, and these used to break it.
addEventAt($db, E_FAR, ORG, '9999-12-31 23:30:00', 'Europe/London');
addEventAt($db, E_FAR + 1, ORG, '0001-01-01 00:30:00', 'Pacific/Auckland');
run($db, 'UPDATE `tblEvents` SET `isDeleted` = 1 WHERE `eventID` IN (?, ?)', 'ii', [E_FAR, E_FAR + 1]);
addEventAt($db, E_FAR + 2, ORG, '9990-06-15 12:00:00', 'America/New_York');

// Round 3: a PUBLISHED event at the last second of 9999 in Los Angeles. Its
// offset taken off the reading went past the last date MySQL holds, gave
// NULL, and NULL sorts FIRST -- so it took one of the thirty places and a
// real duty was dropped. UH and UG are both on its team: for UH it must come
// LAST; for UG (thirty-one real duties) it must not take a place at all.
addEventAt($db, E_FAR + 3, ORG, '9999-12-31 23:59:59', 'America/Los_Angeles');
addRole($db, E_FAR + 3, UH, 'helper');
addRole($db, E_FAR + 3, UG, 'helper');

// Round 3: a LIVE event in year 0, which MySQL stores and PHP's reader
// refuses. As the organisation's lowest date it used to collapse the
// window to "now" plus or minus two days, so every clock change above lost
// its offset. The span is held inside 1900-2200 in SQL now.
addEventAt($db, E_FAR + 4, ORG, '0000-06-15 12:00:00', 'Europe/London');

// Round 3: enough summer-time zones in use (live events in 2190, no team)
// that, between now and 2200, the clocks change more than
// MOMENT_CHANGE_BUDGET times -- so the size check below holds only if the
// window is really narrowed.
foreach (['America/Chicago', 'America/Denver', 'America/Santiago', 'Australia/Sydney', 'America/Halifax', 'Europe/Paris'] as $n => $zoneName) {
    addEventAt($db, E_FAR + 10 + $n, ORG, '2190-06-15 12:00:00', $zoneName);
}

// Round 3: 33,000 DELETED events, each with its own made-up zone text. Every
// text used to cost the statements bound values, and this many made both
// pages fail (more than MySQL's 65,535 placeholders). Deleted rows are left
// out of the ordering expression's zones, and every builder stops at
// Events::ZONE_TEXT_LIMIT texts.
$db->query('SET SESSION `cte_max_recursion_depth` = 40000');
run(
    $db,
    'INSERT INTO `tblEvents` (`eventID`, `siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`, `isDeleted`) '
    . 'WITH RECURSIVE `n` (`i`) AS (SELECT 0 UNION ALL SELECT `i` + 1 FROM `n` WHERE `i` < 32999) '
    . "SELECT 950000 + `i`, ?, CONCAT('Selftest junk ', `i`), CONCAT('selftest-junk-', `i`), '2027-01-01 10:00:00', CONCAT('Junk/Zone', `i`), 'draft', 1 FROM `n`",
    'i',
    [ORG]
);

$uh = null;
$uhError = '';
try {
    $uh = array_map('intval', array_column(Events::volunteeringFor($db, UH, ORG), 'eventID'));
} catch (Throwable $e) {
    $uhError = get_class($e) . ': ' . $e->getMessage();
}
check(
    '#1025 round 2: with deleted events dated 9999-12-31 and 0001-01-01, live ones in year 0, in 9990 and at 9999-12-31 23:59:59 in Los Angeles, and 33,000 deleted made-up zones in the organisation, the list still answers -- the events either side of each clock change come back in REAL order, and the 9999 one comes LAST (round 3: it came first)',
    $uh === array_merge($clk['real'], [E_FAR + 3]),
    $uhError !== '' ? 'THREW ' . $uhError : 'got ' . json_encode($uh) . ' wanted ' . json_encode(array_merge($clk['real'], [E_FAR + 3]))
);
$ug2 = array_map('intval', array_column(Events::volunteeringFor($db, UG, ORG), 'eventID'));
check(
    '#1025 round 3: UG is ALSO on the team of the 9999-12-31 23:59:59 Los Angeles event, and the thirty places still go to Auckland and the first 29 UTC duties -- no real duty is dropped for it',
    $ug2 === array_merge([E_AKL], range(E_UTC, E_UTC + 28)),
    'first ' . json_encode(array_slice($ug2, 0, 2)) . ', count ' . count($ug2) . ', 9999 event among them: ' . json_encode(in_array(E_FAR + 3, $ug2, true))
);
// Round 4: "texts of events that are NOT deleted come first" in
// Events::zonesInUse() was pinned by nothing. One live event in
// Pacific/Tahiti (UTC-10, its zone used by no other event) sorts AFTER every
// one of the 33,000 deleted "Junk/Zone..." texts by name and by count, so
// without that ordering the cap leaves Tahiti out and
// Events::nowOnItsOwnClock() judges it on the organisation's clock (UTC
// here). It began 23 hours ago on its OWN clock, so it is inside My
// volunteering's day of grace only when Tahiti's clock is used: on UTC's it
// began 33 hours ago and is dropped.
const UI      = 900019;
const E_TAHITI = 900520;
run($db, 'INSERT INTO tblUsers (userID, fullName, emailAddress) VALUES (?, ?, ?)', 'iss', [UI, 'Selftest volunteer i', 'selftest-547-i@invalid.test']);
addEventAt($db, E_TAHITI, ORG, $readingOn('Pacific/Tahiti', '-23 hours'), 'Pacific/Tahiti');
addRole($db, E_TAHITI, UI, 'host');
$nowParams = Events::nowOnItsOwnClock($db, 'e', ORG)['params'];
check(
    '#1025 round 4: with 33,000 deleted made-up zone texts, the ONE live event\'s zone (Pacific/Tahiti, sorting after all of them) still has its own WHEN in nowOnItsOwnClock() -- live texts come first under the cap',
    in_array('Pacific/Tahiti', $nowParams, true) === true && count($nowParams) <= 2 * Events::ZONE_TEXT_LIMIT + 1,
    count($nowParams) . ' values; Tahiti among them: ' . json_encode(in_array('Pacific/Tahiti', $nowParams, true))
);
$ui = array_map('intval', array_column(Events::volunteeringFor($db, UI, ORG), 'eventID'));
check(
    '#1025 round 4: so My volunteering keeps the Tahiti event that began 23 hours ago on ITS clock (on the organisation\'s UTC clock it began 33 hours ago and would be dropped)',
    $ui === [E_TAHITI],
    json_encode($ui)
);

$moment = Events::startAsUtcMoment($db, 'e', ORG);
check(
    '#1025 round 2: the ordering expression stays bounded with events from year 0 to 9999 and ten summer-time zones in use (' . count($moment['params']) . ' values, ' . strlen($moment['sql']) . ' characters)',
    count($moment['params']) <= 2100 && strlen($moment['sql']) <= 200000,
    count($moment['params']) . ' values, ' . strlen($moment['sql']) . ' characters'
);

// Round 3: the narrowing itself, driven directly. A span of 1900-2200 with
// ten summer-time zones is far more clock changes than the budget allows;
// the window must come back containing now, narrower than the span, and with
// the clocks changing no more than the budget in it unless it has reached
// its 732-day floor. And with now BEFORE a span of 2150-2200, it sits at the
// span's START (the end nearer now), not round now.
$tenZones = array_map(static fn (string $z): DateTimeZone => new DateTimeZone($z), [
    'Europe/London', 'Pacific/Auckland', 'America/New_York', 'America/Los_Angeles', 'America/Chicago',
    'America/Denver', 'America/Santiago', 'Australia/Sydney', 'America/Halifax', 'Europe/Paris',
]);
$countChanges = static function (int $from, int $until) use ($tenZones): int {
    $n = 0;
    foreach ($tenZones as $z) {
        $n += max(0, count((array) $z->getTransitions($from - 2 * 86400, $until + 2 * 86400)) - 1);
    }
    return $n;
};
$readingNow = gmmktime((int) gmdate('H'), (int) gmdate('i'), 0, (int) gmdate('n'), (int) gmdate('j'), (int) gmdate('Y'));
[$wFrom, $wUntil] = Events::momentWindow([gmmktime(0, 0, 0, 1, 3, 1900), gmmktime(0, 0, 0, 12, 29, 2200)], $tenZones, time());
check(
    '#1025 round 3: momentWindow() narrows a 1900-2200 span round now until the ten zones change their clocks at most 2,000 times (got ' . gmdate('Y-m-d', $wFrom) . ' .. ' . gmdate('Y-m-d', $wUntil) . ', ' . $countChanges($wFrom, $wUntil) . ' changes)',
    $wFrom <= $readingNow && $wUntil >= $readingNow && ($countChanges($wFrom, $wUntil) <= 2000 || $wUntil - $wFrom <= 732 * 86400)
        && $wUntil - $wFrom < (gmmktime(0, 0, 0, 12, 29, 2200) - gmmktime(0, 0, 0, 1, 3, 1900)),
    gmdate('Y-m-d', $wFrom) . ' .. ' . gmdate('Y-m-d', $wUntil)
);
[$aFrom, $aUntil] = Events::momentWindow([gmmktime(0, 0, 0, 1, 1, 2150), gmmktime(0, 0, 0, 12, 29, 2200)], array_merge($tenZones, $tenZones, $tenZones, $tenZones), time());
check(
    '#1025 round 3: with now BEFORE the span (2150-2200) and too many changes, the narrowed window sits against the span\'s START, the end nearer now (got ' . gmdate('Y-m-d', $aFrom) . ' .. ' . gmdate('Y-m-d', $aUntil) . ')',
    $aFrom === gmmktime(0, 0, 0, 1, 1, 2150) - 2 * 86400 && $aUntil < gmmktime(0, 0, 0, 12, 29, 2200),
    gmdate('Y-m-d', $aFrom) . ' .. ' . gmdate('Y-m-d', $aUntil)
);

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
    // Stand-ins: the page only asks these two classes four questions. (Site::url() was added on
    // 13 October 2026: the link sweep made the page build its "Account" link with it, and this test
    // then failed with "Call to undefined method" although the page itself was right.)
    class Auth { public static function ensureSession(): void {} public static function requireLogin(): void {} }
    // Site's stand-in is the shared scratch file (argument 9) since #1025,
    // because Events now also asks it for the organisation's zone.
}
namespace {
    [, $core, $page, $host, $user, $pass, $name, $port] = $argv;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // a SQL error ends the page loudly
    define('PORTAL_CORE', $argv[8]);                            // stand-in templates folder
    require $core . DIRECTORY_SEPARATOR . 'Events.php';
    // #777 part two made the page put each start into words through
    // I18n::formatClockDateTime(), and this runner has no autoloader, so the
    // page died with "Class I18n not found" on every case from 3f29995 on.
    // I18n stands alone (it needs no other portal class for that call).
    require $core . DIRECTORY_SEPARATOR . 'I18n.php';
    $mysqli = new mysqli($host, $user, $pass, $name, (int) $port);
    $mysqli->set_charset('utf8mb4');
    require $argv[9];
    \Portal\Core\Site::$db = $mysqli;
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
    // #1025: the page shows the Auckland event (soonest in real time, last by
    // reading) and not the 30th UTC one, which the LIMIT now cuts instead.
    'events on two clocks (UG, #1025)'      => [UG, ['Selftest event ' . E_AKL, 'Selftest event ' . E_UTC], ['Selftest event ' . (E_UTC + 29)]],
    // #1025 round 2: across clock changes, with the far-off dates in place,
    // the page shows every event and in REAL order (the fourth element asks
    // for the order of the names on the page, not just their presence).
    'a live zone under the cap (UI, #1025 round 4)' => [UI, ['Selftest event ' . E_TAHITI], []],
    'across clock changes, in real order (UH, #1025)' => [UH, array_map(static fn (int $id): string => 'Selftest event ' . $id, array_merge($clk['real'], [E_FAR + 3])), [], true],
];
foreach ($cases as $label => $case) {
    [$user, $mustShow, $mustNotShow] = $case;
    $inOrder = ($case[3] ?? false) === true;
    $out = [];
    $exit = 0;
    $cmd = [PHP_BINARY, $runner, $core, $page, $dbHost, $dbUser, $dbPass, $dbName, (string) $dbPort, $scratch, $siteStandIn];
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
    $lastAt = -1;
    foreach ($mustShow as $needle) {
        $at = strpos($stdout, $needle);
        $ok = $ok && $at !== false && ($inOrder === false || $at > $lastAt);
        $lastAt = $at === false ? $lastAt : $at;
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
@unlink($siteStandIn);
wipe($db);
$left = (int) run($db, 'SELECT (SELECT COUNT(*) FROM tblEvents WHERE eventID >= 900000) + (SELECT COUNT(*) FROM tblUsers WHERE userID >= 900000) + (SELECT COUNT(*) FROM tblSites WHERE siteID >= 900000) AS c')[0]['c'];
check('the made-up rows were all removed', $left === 0, $left . ' left');

echo $failures === 0 ? "PASS — my-volunteering self-test: every check passed and the run reached its end.\n" : "FAIL — {$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
