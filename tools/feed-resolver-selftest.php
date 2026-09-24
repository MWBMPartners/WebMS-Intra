<?php
// Path: tools/feed-resolver-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test for who may see a copied-in event 👁️🧪 (#514, part P7)
 * -----------------------------------------------------------------------------
 * `Portal\Core\FeedResolver` decides, for every event copied in from an
 * outside calendar, who may see it: the calendar's own setting, an
 * administrator's choice for one date or a whole repeating event, rules that
 * match events by their title, location or category, and the approvals a
 * WIDER answer has to wait for. It also decides whether an API key may
 * receive the event ("Don't show via API"). Every one of those decisions is
 * written onto the event and read by `Portal\Core\EventVisibility`.
 *
 * Almost every mistake here is silent. A precedence the wrong way round, a
 * date window an hour out on the night the clocks change, an approval that
 * carries to a date nobody saw — each looks exactly like the portal working.
 * So this script builds a small made-up world in a throwaway database with
 * plain SQL, calls the REAL class, and checks exactly what it stored.
 *
 * WHY IT IS ITS OWN FILE, SEPARATE FROM THE IMPORTER'S TEST
 * --------------------------------------------------------
 * These proofs need only a database. The importer's test
 * (`tools/feed-importer-selftest.php`) refuses to run without a test calendar
 * server as well, for good reason — and putting thirty resolver proofs behind
 * that refusal would stop them running for a reason that has nothing to do
 * with them. The few proofs that need a REAL refresh or the real scheduled
 * job (#514 plan E17-E20 and E30) live in that file's part L instead.
 *
 * THE DATABASE CLOCK IS ALWAYS PINNED — AND WHAT THAT REALLY MEANS
 * ----------------------------------------------------------------
 * `SET timestamp = <a Unix second>` makes `UTC_TIMESTAMP()` on this
 * connection return that second — and KEEP returning it. It FREEZES the
 * clock; it does not set it running from there (measured on MySQL 8.0.36 by
 * part 6 and again by part 7's challenge: a real `SLEEP()` changes nothing).
 * This file needs no clock that moves: every proof uses ONE moment. So it
 * always pins — to the real moment on an ordinary run, or to
 * `SELFTEST_SIMULATED_UTC` (a test-only input, `YYYY-MM-DDTHH:MM:SSZ`, read
 * only here) to run every proof "as if it were" another day — and it REFUSES
 * unless the pin reads back to the second. A pin that did not take would
 * test the real day while claiming to test another, and look green.
 *
 * A proof that needs "another time" (a window opening in four days, a week
 * later) goes through `fr_resolveAt()`, which moves the pin, resolves, runs
 * that step's own checks WHILE the pin is there, and moves it back. So the
 * resolver's "now", `decideApproval()`'s `UTC_TIMESTAMP()` and the visibility
 * rule's freshness test always read one clock. `resolveFeed()` is called in
 * ONE place in this file (`fr_resolve()`), always with the database's own
 * pinned clock.
 *
 * EVERY DATE IS WORKED OUT FROM ONE READING OF "NOW" — AND CHECK 0 REFUSES
 * -----------------------------------------------------------------------
 * Part 6 learned this the hard way: a test written with fixed dates failed on
 * correct code from 1 November 2026. Here every date is an offset from
 * `fr_nowUtc()`, read once. The window dates are all DECLARED up front in
 * `fr_datePlan()`, each with its intent ("past" or "future"), and check 0
 * refuses the whole run unless every one really is at least a day on the side
 * it claims — so a fixed date added later is named the first run it goes
 * stale. The clock-change nights are found in PHP's own time-zone data.
 *
 * SAFETY
 * ------
 * It writes to the database it is given, so it REFUSES unless the database's
 * name starts with `selftest_`. Every row it makes itself has a number of
 * 900000 or more (approval rows are numbered by the database, and belong to
 * calendars numbered 900000 or more); all of them are removed at the end.
 * It never reads anything under `web/_auth_keys` and never loads
 * `bootstrap.php`: the connection comes only from these variables —
 *
 *   SELFTEST_DB_HOST (default 127.0.0.1)   SELFTEST_DB_PORT (default 3306)
 *   SELFTEST_DB_USER (default root)        SELFTEST_DB_PASS (default empty)
 *   SELFTEST_DB_NAME (required; must start with selftest_)
 *   SELFTEST_SIMULATED_UTC (optional; run as if it were that moment)
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - Anything about a real Google or Microsoft 365 calendar: acceptance
 *   criterion 3 of #514 is NOT PROVEN, here or anywhere (no captured export
 *   exists; the owner's decision of 21 September 2026).
 * - The administrator pages that make choices and rules (part P8).
 * - MariaDB: only MySQL 8.0 has been used.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_p7 php tools/feed-resolver-selftest.php
 * Exit:   0 when every check passed; 1 otherwise — including every refusal,
 *         because a test that did not run has proved nothing.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🧯 Anything thrown outside the checks — a database that cannot be reached,
//    a missing table, a class file that fails to load — becomes a plain FAIL
//    line and exit 1, not PHP's exit code 255 and a stack trace. Registered
//    BEFORE the class files load, because a syntax error in one of them is
//    thrown by the `require` below. It cannot catch a syntax error in THIS
//    file, or running out of memory.
set_exception_handler(static function (Throwable $e): void {
    echo 'FAIL — the self-test stopped early: ' . get_class($e) . ': ' . $e->getMessage()
        . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    exit(1);
});

// =============================================================================
// 🛑 Refuse anything but a throwaway database — BEFORE any class is loaded
// =============================================================================
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    echo "This test writes rows, so it only ever runs on a throwaway database. Nothing was checked.\n";
    exit(1);
}

// The classes, one by one, never through `bootstrap.php` (which reads the real
// `web/_auth_keys/` and would connect to whatever database the developer's own
// portal points at). `App` is here because `FeedResolver::organisationZone()`
// reads a setting through it; `Logger` and `ErrorMonitor` because the importer
// class, loaded for its `zoneFor()`, names them.
$coreDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR;
foreach (['App.php', 'ErrorMonitor.php', 'Logger.php', 'EventVisibility.php', 'FeedResolver.php', 'FeedImporter.php',
    'GdprEraser.php'] as $classFile) {
    require_once $coreDir . $classFile;
}

use Portal\Core\EventVisibility;
use Portal\Core\FeedImporter;
use Portal\Core\FeedResolver;
use Portal\Core\GdprEraser;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// `$mysqli` is a global on purpose: `App::db()` falls back to it, which is how
// `organisationZone()` reaches the settings. ONE connection for everything, so
// the pinned clock is the only clock.
$mysqli = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$mysqli->set_charset('utf8mb4');

// =============================================================================
// 🗺️ The made-up world (every number 900000 or more)
// =============================================================================
const ORG_A = 900001;            // Europe/London
const ORG_B = 900002;            // America/Santiago
const U1 = 900011;               // an ordinary member of A
const U6 = 900016;               // a site administrator of A
const U8 = 900018;               // a global administrator
const UX = 900019;               // the person who asks to be forgotten (E25)
const G1 = 900401;               // small groups of A
const G2 = 900402;
const F  = 900201;               // A, members
const FA = 900202;               // A, members, "Don't show via API" ticked
const FP = 900203;               // A, public, website box clear
const FG = 900204;               // A, groups, list {G1}
const FB = 900205;               // B, public
const SITE_ZONE   = 'Europe/London';     // a test constant, never a portal one
const SITE_ZONE_B = 'America/Santiago';  // a zone whose clocks change AT midnight

$GLOBALS['fr_pass']   = 0;
$GLOBALS['fr_fail']   = 0;
$GLOBALS['fr_nextId'] = ['event' => 900300, 'choice' => 900500, 'rule' => 900600, 'cond' => 900700, 'feed' => 900210];

// =============================================================================
// 🛠️ Small helpers
// =============================================================================

/** Record one check. */
function fr_ok(string $label, bool $passed, string $detail = ''): void
{
    if ($passed === true) {
        $GLOBALS['fr_pass']++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $GLOBALS['fr_fail']++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
}

function fr_heading(string $text): void
{
    echo "\n=== " . $text . " ===\n";
}

/**
 * Run one statement with bound values and give back every row.
 *
 * @param list<mixed> $params
 *
 * @return list<array<string,mixed>>
 */
function fr_q(string $sql, string $types = '', array $params = []): array
{
    global $mysqli;
    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = ($result === false) ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** The first row, or null. */
function fr_one(string $sql, string $types = '', array $params = []): ?array
{
    $rows = fr_q($sql, $types, $params);

    return $rows === [] ? null : $rows[0];
}

/** Print the totals and end with the promised exit code. */
function fr_finish(): never
{
    echo "\n" . $GLOBALS['fr_pass'] . ' passed, ' . $GLOBALS['fr_fail'] . " failed.\n";
    if ($GLOBALS['fr_fail'] > 0 || $GLOBALS['fr_pass'] === 0) {
        echo 'FAIL — ' . $GLOBALS['fr_fail'] . " check(s) failed" . ($GLOBALS['fr_pass'] === 0 ? ' (and none passed)' : '') . ".\n";
        exit(1);
    }
    echo 'PASS — all ' . $GLOBALS['fr_pass'] . " checks passed.\n";
    exit(0);
}

// =============================================================================
// 🕰️ The one reading of "now", and the pinned clock
// =============================================================================

/**
 * Pin THIS connection's clock to a moment, and refuse unless it reads back
 * to the second. See the header for why it is a freeze, not a start.
 */
function fr_pin(DateTimeImmutable $at): void
{
    global $mysqli;
    $mysqli->query('SET timestamp = ' . (int) $at->getTimestamp());
    $back = (string) (fr_one('SELECT UTC_TIMESTAMP() AS t')['t'] ?? '');
    $want = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    if ($back !== $want) {
        throw new RuntimeException('the database clock pin did not take: asked for ' . $want . ', read back ' . $back);
    }
}

/**
 * "Now" for the whole run, read ONCE: the moment the database clock is
 * pinned to. On an ordinary run that is the database's real UTC moment at
 * the start; on a simulated run it is SELFTEST_SIMULATED_UTC.
 */
function fr_nowUtc(): DateTimeImmutable
{
    static $now = null;
    if ($now === null) {
        $simulated = (string) getenv('SELFTEST_SIMULATED_UTC');
        if ($simulated !== '') {
            $now = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $simulated, new DateTimeZone('UTC'));
            if ($now === false) {
                throw new RuntimeException('SELFTEST_SIMULATED_UTC must look like 2027-02-01T12:00:00Z (got ' . $simulated . ')');
            }
        } else {
            $row = fr_one('SELECT UTC_TIMESTAMP() AS t');
            $now = new DateTimeImmutable((string) $row['t'], new DateTimeZone('UTC'));
        }
    }

    return $now;
}

/** Today's date on organisation A's own clock, as the portal counts it. */
function fr_todayLocal(): DateTimeImmutable
{
    return fr_nowUtc()->setTimezone(new DateTimeZone(SITE_ZONE))->setTime(0, 0, 0);
}

/**
 * The first moment, between two moments, that the clocks of a zone go BACK
 * (`$goingBack` true) or FORWARD, found in PHP's own zone data — or null.
 *
 * Entry 0 of `getTransitions()` is never a change: it describes the state the
 * zone is ALREADY in at the start of the range (part 6 measured it on PHP
 * 8.5.10; `tools/feed-importer-selftest.php`, `fi_clockChange()`, records the
 * fault that caused). So it is skipped, and a later entry counts only when it
 * is the far side of a real change. The offset either side of the moment is
 * then checked as a second guard, so an ordinary day can never be handed back
 * as a clock change.
 */
function fr_clockChange(string $zoneName, DateTimeImmutable $from, DateTimeImmutable $to, bool $goingBack): ?DateTimeImmutable
{
    $zone  = new DateTimeZone($zoneName);
    $spans = $zone->getTransitions($from->getTimestamp(), $to->getTimestamp());
    if ($spans === false) {
        return null;
    }
    $wasSummer = false;
    foreach ($spans as $i => $span) {
        if ($i === 0) {
            $wasSummer = ($span['isdst'] === true);
            continue;
        }
        $isSummer = ($span['isdst'] === true);
        $wanted   = ($goingBack === true) ? ($isSummer === false && $wasSummer === true) : ($isSummer === true && $wasSummer === false);
        if ($wanted === true) {
            $moment = (new DateTimeImmutable('@' . $span['ts']))->setTimezone(new DateTimeZone('UTC'));
            $before = $zone->getOffset($moment->modify('-1 minute'));
            $after  = $zone->getOffset($moment->modify('+1 minute'));
            $ok     = ($goingBack === true) ? ($after < $before) : ($after > $before);

            return ($ok === true) ? $moment : null;
        }
        $wasSummer = $isSummer;
    }

    return null;
}

/**
 * The clock-change nights this run is built on, found once: the next time
 * London's clocks go back and forward, and Santiago's, within 13 months.
 *
 * @return array{londonBack:?DateTimeImmutable, londonForward:?DateTimeImmutable,
 *               santiagoBack:?DateTimeImmutable, santiagoForward:?DateTimeImmutable}
 */
function fr_nights(): array
{
    static $nights = null;
    if ($nights === null) {
        $from = fr_nowUtc();
        $to   = fr_nowUtc()->modify('+13 months');
        $nights = [
            'londonBack'      => fr_clockChange(SITE_ZONE, $from, $to, true),
            'londonForward'   => fr_clockChange(SITE_ZONE, $from, $to, false),
            'santiagoBack'    => fr_clockChange(SITE_ZONE_B, $from, $to, true),
            'santiagoForward' => fr_clockChange(SITE_ZONE_B, $from, $to, false),
        ];
    }

    return $nights;
}

/**
 * EVERY window date the proofs use, declared once with its intent. A proof
 * asks for a date by name (`fr_d()`); a name not declared here throws, so no
 * proof can quietly write a date check 0 has not seen.
 *
 * "past" dates must be at least one day before today, and "future" ones at
 * least one day after it, on organisation A's own calendar; check 0 refuses
 * the run otherwise.
 *
 * @return array<string, array{date:string, intent:string}>
 */
function fr_datePlan(): array
{
    static $plan = null;
    if ($plan === null) {
        $today = fr_todayLocal();
        $at    = static fn (int $days): string => $today->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
        $plan  = [
            'yesterday'   => ['date' => $at(-1), 'intent' => 'past'],
            'tenDaysAgo'  => ['date' => $at(-10), 'intent' => 'past'],
            'plus4'       => ['date' => $at(4), 'intent' => 'future'],
            'plus20'      => ['date' => $at(20), 'intent' => 'future'],
            'plus40'      => ['date' => $at(40), 'intent' => 'future'],
        ];
    }

    return $plan;
}

/** One declared date. */
function fr_d(string $name): string
{
    $plan = fr_datePlan();
    if (isset($plan[$name]) === false) {
        throw new LogicException('fr_d(): the date "' . $name . '" is not declared in fr_datePlan(), so check 0 has not seen it');
    }

    return $plan[$name]['date'];
}

/** The first moment of a local date in a zone, as a UTC moment — worked out here, independently of the portal. */
function fr_dayStart(string $ymd, string $zone = SITE_ZONE): DateTimeImmutable
{
    return (new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone($zone)))->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'));
}

/** A moment as the database writes it. */
function fr_text(DateTimeImmutable $moment): string
{
    return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

// =============================================================================
// 🏗️ Building the world
// =============================================================================

/** Remove everything this test ever makes. Safe when there is nothing. */
function fr_removeWorld(): void
{
    fr_q('DELETE FROM tblErasureAudit WHERE requestID BETWEEN 900000 AND 999999');
    fr_q('DELETE FROM tblErasureRequest WHERE requestID BETWEEN 900000 AND 999999');
    fr_q('DELETE FROM tblExternalEventApprovals WHERE feedID BETWEEN 900000 AND 999999');
    fr_q('DELETE c FROM tblExternalRuleConditions c JOIN tblExternalFeedRules r ON r.ruleID = c.ruleID WHERE r.feedID BETWEEN 900000 AND 999999');
    fr_q('DELETE FROM tblExternalFeedRules WHERE feedID BETWEEN 900000 AND 999999');
    fr_q('DELETE FROM tblExternalEventChoices WHERE feedID BETWEEN 900000 AND 999999');
    fr_q('DELETE FROM tblExternalAudienceMembers WHERE feedID BETWEEN 900000 AND 999999');
    fr_q('DELETE t FROM tblExternalEventTags t JOIN tblEvents e ON e.eventID = t.eventID WHERE e.eventID BETWEEN 900000 AND 999999');
    fr_q('DELETE FROM tblExternalCategoryMap WHERE feedID BETWEEN 900000 AND 999999');
    fr_q('DELETE FROM tblEvents WHERE eventID BETWEEN 900000 AND 999999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    fr_q('DELETE FROM tblExternalFeeds WHERE feedID BETWEEN 900000 AND 999999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    fr_q('DELETE FROM tblSmallGroups WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    fr_q('DELETE FROM tblUserSites WHERE siteID IN (?, ?) OR userID BETWEEN 900000 AND 900099', 'ii', [ORG_A, ORG_B]);
    fr_q('DELETE FROM tblUsers WHERE userID BETWEEN 900000 AND 900099');
    fr_q("DELETE FROM tblSettings WHERE settingKey = 'site.timezone' AND siteID IN (?, ?)", 'ii', [ORG_A, ORG_B]);
    fr_q('DELETE FROM tblSites WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
}

/** A fresh world: two organisations, four people, two groups, five calendars. */
function fr_resetWorld(): void
{
    fr_removeWorld();
    fr_q("INSERT INTO tblSites (siteID, siteKey, siteName, isActive) VALUES (?, 'selftest-p7-a', 'Selftest A', 1), (?, 'selftest-p7-b', 'Selftest B', 1)", 'ii', [ORG_A, ORG_B]);
    fr_q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, 'site.timezone', ?, 'UTC', 0), (?, 'site.timezone', ?, 'UTC', 0)",
        'isis', [ORG_A, SITE_ZONE, ORG_B, SITE_ZONE_B]);
    fr_q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES '
        . "(?, 'Selftest U1', 'u1.p7@selftest.invalid', 1, 0, 0), (?, 'Selftest U6', 'u6.p7@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest U8', 'u8.p7@selftest.invalid', 1, 0, 1), (?, 'Selftest UX', 'ux.p7@selftest.invalid', 1, 0, 0)",
        'iiii', [U1, U6, U8, UX]);
    fr_q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0), (?, ?, 1, 1, 0)',
        'iiii', [U1, ORG_A, U6, ORG_A]);
    fr_q("INSERT INTO tblSmallGroups (groupID, siteID, groupName, groupSlug, isActive) VALUES (?, ?, 'Selftest G1', 'selftest-p7-g1', 1), (?, ?, 'Selftest G2', 'selftest-p7-g2', 1)",
        'iiii', [G1, ORG_A, G2, ORG_A]);
    fr_feed(F, ORG_A, 'members');
    fr_feed(FA, ORG_A, 'members', ['apiOptOut' => 1]);
    fr_feed(FP, ORG_A, 'public');
    fr_feed(FG, ORG_A, 'groups');
    fr_list(FG, 'feed', FG, [['small_group', G1]]);
    fr_feed(FB, ORG_B, 'public');
}

/**
 * Add a calendar. `$opts`: website, apiOptOut, active.
 *
 * @param array<string,int> $opts
 */
function fr_feed(int $feedId, int $orgId, string $level, array $opts = []): int
{
    fr_q('INSERT INTO tblExternalFeeds (feedID, siteID, name, url, isActive, audienceLevel, websiteOptIn, apiOptOut) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        'iissisii', [$feedId, $orgId, 'Selftest calendar ' . $feedId, 'https://example.invalid/' . $feedId . '.ics',
            $opts['active'] ?? 1, $level, $opts['website'] ?? 0, $opts['apiOptOut'] ?? 0]);

    return $feedId;
}

/** A brand-new calendar numbered from the test's own counter. */
function fr_newFeed(int $orgId, string $level, array $opts = []): int
{
    return fr_feed($GLOBALS['fr_nextId']['feed']++, $orgId, $level, $opts);
}

/**
 * Put rows on a "who may see it" list.
 *
 * @param list<array{0:string,1:int}> $members [kind, refID]
 */
function fr_list(int $feedId, string $ownerType, int $ownerId, array $members): void
{
    $org = (int) fr_one('SELECT siteID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$feedId])['siteID'];
    foreach ($members as [$kind, $refId]) {
        $userId = ($kind === 'person') ? $refId : null;
        fr_q('INSERT INTO tblExternalAudienceMembers (siteID, feedID, ownerType, ownerID, kind, refID, userID, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            'iisisii', [$org, $feedId, $ownerType, $ownerId, $kind, $refId, $userId]);
    }
}

/**
 * Add one copied-in event the way the importer would leave it BEFORE the
 * resolver runs: hidden, title only, not for API keys, `isPublic` 0. The
 * identity is `UNHEX(SHA2(uid, 256))`, exactly the importer's.
 *
 * `$opts`: key, day (days after today, default 7), clock (default 19:00:00),
 * description, location, url, tags (list), private, duplicate, id (a chosen
 * event number), deleted.
 *
 * @param array<string,mixed> $opts
 */
function fr_event(int $feedId, string $uid, string $title, array $opts = []): int
{
    $id  = (int) ($opts['id'] ?? $GLOBALS['fr_nextId']['event']++);
    $org = (int) fr_one('SELECT siteID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$feedId])['siteID'];
    $zone = ($org === ORG_B) ? SITE_ZONE_B : SITE_ZONE;
    $day  = fr_todayLocal()->modify('+' . (int) ($opts['day'] ?? 7) . ' days')->format('Y-m-d');
    $start = $day . ' ' . ($opts['clock'] ?? '19:00:00');
    $end   = $day . ' 21:00:00';
    fr_q(
        'INSERT INTO tblEvents (eventID, siteID, externalFeedID, externalUid, externalUidHash, externalRecurrenceKey, eventSlug, eventName, '
        . 'description, locationName, externalUrl, startDateTime, endDateTime, timezone, eventTimezone, status, isPublic, isDeleted, '
        . "importLevel, importDetail, importApiOptOut, externalPrivate, externalDuplicate, externalLastSeenAt) VALUES "
        . "(?, ?, ?, ?, UNHEX(SHA2(?, 256)), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', 0, ?, 'hidden', 'basic', 1, ?, ?, UTC_TIMESTAMP())",
        'iiissssssssssssiii',
        [$id, $org, $feedId, $uid, $uid, (string) ($opts['key'] ?? ''), 'imp-selftest-p7-' . $id, $title,
            $opts['description'] ?? 'A description', $opts['location'] ?? 'A hall', $opts['url'] ?? 'https://example.invalid/e',
            $start, $end, $zone, $zone, (int) ($opts['deleted'] ?? 0), (int) ($opts['private'] ?? 0), (int) ($opts['duplicate'] ?? 0)]
    );
    foreach ((array) ($opts['tags'] ?? []) as $tag) {
        fr_q('INSERT INTO tblExternalEventTags (eventID, tag) VALUES (?, ?)', 'is', [$id, (string) $tag]);
    }

    return $id;
}

/**
 * Add a choice. `$opts`: key (single date), detail, website, apiOptOut,
 * from, to (Y-m-d), override, by (who made it), updatedBy, id (a chosen
 * choice number — round-2 check gap 1: on a real installation
 * `tblExternalEventChoices.choiceID` and `tblExternalFeedRules.ruleID` are
 * separate AUTO_INCREMENT columns that both start at 1, so a rule and a
 * choice sharing the same number is the ordinary case, not a contrived
 * one — mirrors `fr_event()`'s own `id` override).
 *
 * @param array<string,mixed> $opts
 */
function fr_choice(int $feedId, string $scope, string $uid, string $level, array $opts = []): int
{
    $id  = (int) ($opts['id'] ?? $GLOBALS['fr_nextId']['choice']++);
    $org = (int) fr_one('SELECT siteID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$feedId])['siteID'];
    fr_q(
        'INSERT INTO tblExternalEventChoices (choiceID, siteID, feedID, scope, externalUidHash, externalRecurrenceKey, audienceLevel, detailLevel, '
        . 'websiteOptIn, apiOptOut, fromDate, toDate, overridesPrivateMark, createdByID, createdAt, updatedByID, updatedAt) '
        . 'VALUES (?, ?, ?, ?, UNHEX(SHA2(?, 256)), ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, NULL)',
        'iiisssssiissiii',
        [$id, $org, $feedId, $scope, $uid, ($scope === 'date') ? (string) ($opts['key'] ?? '') : '', $level, (string) ($opts['detail'] ?? 'basic'),
            (int) ($opts['website'] ?? 0), (int) ($opts['apiOptOut'] ?? 0), $opts['from'] ?? null, $opts['to'] ?? null,
            (int) ($opts['override'] ?? 0), $opts['by'] ?? U6, $opts['updatedBy'] ?? null]
    );

    return $id;
}

/**
 * Add a rule and its conditions: each condition is [field, type, value] or
 * [field, type, value, true] for an exception. `$opts`: detail, website,
 * apiOptOut, from, to, active, by, updatedBy.
 *
 * @param list<array> $conditions
 * @param array<string,mixed> $opts
 */
function fr_rule(int $feedId, string $level, array $conditions, array $opts = []): int
{
    $id  = $GLOBALS['fr_nextId']['rule']++;
    $org = (int) fr_one('SELECT siteID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$feedId])['siteID'];
    fr_q(
        'INSERT INTO tblExternalFeedRules (ruleID, siteID, feedID, name, isActive, audienceLevel, detailLevel, websiteOptIn, apiOptOut, '
        . 'fromDate, toDate, createdByID, createdAt, updatedByID, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, NULL)',
        'iiisissiissii',
        [$id, $org, $feedId, 'Selftest rule ' . $id, (int) ($opts['active'] ?? 1), $level, (string) ($opts['detail'] ?? 'basic'),
            (int) ($opts['website'] ?? 0), (int) ($opts['apiOptOut'] ?? 0), $opts['from'] ?? null, $opts['to'] ?? null,
            $opts['by'] ?? U6, $opts['updatedBy'] ?? null]
    );
    foreach ($conditions as $c) {
        fr_q('INSERT INTO tblExternalRuleConditions (conditionID, ruleID, isException, matchField, matchType, matchValue) VALUES (?, ?, ?, ?, ?, ?)',
            'iiisss', [$GLOBALS['fr_nextId']['cond']++, $id, (int) (($c[3] ?? false) === true), (string) $c[0], (string) $c[1], (string) $c[2]]);
    }

    return $id;
}

// =============================================================================
// 🔄 Resolving, the way every caller must
// =============================================================================

/**
 * Resolve one calendar the way every caller must: a transaction, the
 * calendar's own lock, `resolveFeed()` with the DATABASE's clock (which is
 * the pinned one), commit. THE ONLY `resolveFeed(` CALL IN THIS FILE.
 *
 * @return array{changed:int, newPending:int, pendingTotal:int, approvedBySave:int}
 */
function fr_resolve(int $feedId, ?array $saved = null): array
{
    global $mysqli;
    $mysqli->begin_transaction();
    try {
        fr_q('SELECT feedID FROM tblExternalFeeds WHERE feedID = ? FOR UPDATE', 'i', [$feedId]);
        $result = FeedResolver::resolveFeed($mysqli, $feedId, FeedResolver::databaseNowUtc($mysqli), $saved);
        $mysqli->commit();

        return $result;
    } catch (Throwable $e) {
        $mysqli->rollback();
        throw $e;
    }
}

/**
 * Resolve "at another time": move the pin to `$at`, resolve, run this step's
 * own checks WHILE the pin is there (decisions, the visibility rule), then
 * move it back to `fr_nowUtc()` whatever happens. So every clock in the step
 * reads the same moment (plan D2).
 *
 * @return array{changed:int, newPending:int, pendingTotal:int, approvedBySave:int}
 */
function fr_resolveAt(DateTimeImmutable $at, int $feedId, ?array $saved = null, ?callable $checks = null): array
{
    fr_pin($at);
    try {
        $result = fr_resolve($feedId, $saved);
        if ($checks !== null) {
            $checks($result);
        }

        return $result;
    } finally {
        fr_pin(fr_nowUtc());
    }
}

/**
 * The way the choice page (part P8) will save a choice: `seen` holds every
 * listed date with its CURRENT full-detail fingerprint, taken with
 * `FeedResolver::fullContentHash()` from the stored row.
 *
 * @param list<int> $eventIds
 *
 * @return array<int,string>
 */
function fr_seen(array $eventIds): array
{
    $seen = [];
    foreach ($eventIds as $eventId) {
        $row = fr_one('SELECT eventName, description, locationName, externalUrl, categoryID FROM tblEvents WHERE eventID = ?', 'i', [$eventId]);
        $seen[$eventId] = FeedResolver::fullContentHash($row, $row['categoryID'] === null ? null : (int) $row['categoryID']);
    }

    return $seen;
}

/** @return array<string,mixed> One event's stored answer. */
function fr_ev(int $eventId): array
{
    return fr_one('SELECT eventID, importLevel, importDetail, importWebsite, importApiOptOut, importAudienceType, importAudienceID, '
        . 'importSource, importSourceID, importRecheckAt, categoryID FROM tblEvents WHERE eventID = ?', 'i', [$eventId]) ?? [];
}

/** @return list<array<string,mixed>> One event's approval rows, oldest first. */
function fr_appr(int $eventId): array
{
    return fr_q('SELECT approvalID, status, reason, origin, originID, requestHash, contentHash, decidedByID, decidedAt, decisionNote '
        . 'FROM tblExternalEventApprovals WHERE eventID = ? ORDER BY approvalID', 'i', [$eventId]);
}

/** The approval rows of one event with a given status. */
function fr_apprWith(int $eventId, string $status): array
{
    return array_values(array_filter(fr_appr($eventId), static fn (array $r): bool => $r['status'] === $status));
}

/** "level/source" of one event, for short checks. */
function fr_ls(int $eventId): string
{
    $e = fr_ev($eventId);

    return $e['importLevel'] . '/' . $e['importSource'];
}

/** How many approval rows a calendar has, optionally only one status. */
function fr_apprCount(int $feedId, ?string $status = null): int
{
    if ($status === null) {
        return (int) fr_one('SELECT COUNT(*) AS n FROM tblExternalEventApprovals WHERE feedID = ?', 'i', [$feedId])['n'];
    }

    return (int) fr_one('SELECT COUNT(*) AS n FROM tblExternalEventApprovals WHERE feedID = ? AND status = ?', 'is', [$feedId, $status])['n'];
}

/** Approve or decline a waiting row the way the part P8 handler will. */
function fr_decide(int $approvalId, string $decision, int $siteId = ORG_A, int $by = U6): bool
{
    global $mysqli;
    $row = fr_one('SELECT requestHash, contentHash FROM tblExternalEventApprovals WHERE approvalID = ?', 'i', [$approvalId]);

    return FeedResolver::decideApproval($mysqli, $approvalId, $siteId, $decision, $by, 'selftest', (string) $row['requestHash'], (string) $row['contentHash']);
}

/** A private method of FeedResolver, reached the way the part-6 test reaches `windowFor()`. */
function fr_private(string $method): ReflectionMethod
{
    return new ReflectionMethod(FeedResolver::class, $method);
}

/** The organisation-zone object used by the proofs. */
function fr_zone(string $name = SITE_ZONE): DateTimeZone
{
    return new DateTimeZone($name);
}

// =============================================================================
// 0️⃣ Check 0 — refuse unless every precondition holds
// =============================================================================
echo '#514 part P7 — resolver self-test — ' . date('c') . "\n";
echo 'PHP ' . PHP_VERSION . ', database ' . (string) (fr_one('SELECT VERSION() AS v')['v'] ?? '?') . "\n";

$refusals = [];

// 🕰️ Pin the clock FIRST, and refuse unless it reads back. Everything below
//    — including check 0's own date sums — then uses the one pinned moment.
try {
    fr_pin(fr_nowUtc());
} catch (Throwable $e) {
    $refusals[] = $e->getMessage();
}
$simulatedText = (string) getenv('SELFTEST_SIMULATED_UTC');
echo 'now, pinned: ' . fr_text(fr_nowUtc()) . ' UTC (' . ($simulatedText === '' ? 'the real clock' : 'SIMULATED') . '); '
    . "organisation A's today: " . fr_todayLocal()->format('Y-m-d') . ' in ' . SITE_ZONE . "\n";

$collations = fr_q("SELECT TABLE_NAME AS t, TABLE_COLLATION AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() "
    . "AND TABLE_NAME IN ('tblExternalEventChoices','tblExternalFeedRules','tblExternalRuleConditions','tblExternalEventApprovals','tblExternalAudienceMembers')");
$byTable = array_column($collations, 'c', 't');
foreach (['tblExternalEventChoices', 'tblExternalFeedRules', 'tblExternalRuleConditions', 'tblExternalEventApprovals', 'tblExternalAudienceMembers'] as $table) {
    $c = $byTable[$table] ?? '(missing)';
    echo str_pad($table . ' collation:', 44) . $c . "\n";
    if ($c !== 'utf8mb4_general_ci') {
        $refusals[] = $table . ' is ' . $c . ', not utf8mb4_general_ci — migration 206 or full_schema.sql lost its COLLATE clause (or 206 has not run)';
    }
}

$nights = fr_nights();
foreach ($nights as $name => $moment) {
    echo str_pad($name . ':', 18) . ($moment === null ? '(not found)' : fr_text($moment) . ' UTC') . "\n";
}
if ($nights['londonBack'] === null || $nights['londonForward'] === null) {
    $refusals[] = "London's next clock changes, both directions, were not found in PHP's zone data within 13 months";
}
if ($nights['santiagoBack'] === null || $nights['santiagoForward'] === null) {
    $refusals[] = "Santiago's next clock changes, both directions, were not found in PHP's zone data within 13 months";
}

$today = fr_todayLocal();
foreach (fr_datePlan() as $name => ['date' => $date, 'intent' => $intent]) {
    $days = (int) round(((new DateTimeImmutable($date, fr_zone()))->getTimestamp() - $today->getTimestamp()) / 86400);
    // Rounded day count, used ONLY to judge "at least a day away": a day
    // here may be 23 or 25 hours long, and rounding absorbs that.
    $ok = ($intent === 'past') ? ($days <= -1) : ($days >= 1);
    if ($ok === false) {
        $refusals[] = 'the date "' . $name . '" (' . $date . ') is meant to be in the ' . $intent . ' by at least a day, and is not';
    }
}

if ($refusals !== []) {
    echo "\nREFUSED — check 0 did not pass, so nothing else was checked:\n";
    foreach ($refusals as $why) {
        echo '  - ' . $why . "\n";
    }
    try {
        $mysqli->query('SET timestamp = DEFAULT');
    } catch (Throwable $ignored) {
    }
    exit(1);
}
fr_ok('check 0: the clock is pinned and reads back; the five #514 tables are utf8mb4_general_ci; both London and both '
    . 'Santiago clock changes were found; every declared date is a day or more on its declared side of today', true);

// =============================================================================
// The proofs
// =============================================================================
try {
    // -------------------------------------------------------------------------
    fr_heading('E1. Precedence: a single-date choice beats the series choice, which beats the rules');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $d1 = fr_event(F, 'weekly-e1@p7', 'Weekly meeting', ['key' => 'k1', 'day' => 7]);
    $d2 = fr_event(F, 'weekly-e1@p7', 'Weekly meeting', ['key' => 'k2', 'day' => 14]);
    $d3 = fr_event(F, 'weekly-e1@p7', 'Weekly meeting', ['key' => 'k3', 'day' => 21]);
    $dateChoice   = fr_choice(F, 'date', 'weekly-e1@p7', 'public', ['key' => 'k2']);
    $seriesChoice = fr_choice(F, 'series', 'weekly-e1@p7', 'hidden');
    fr_rule(F, 'public', [['title', 'contains', 'weekly']]);
    fr_resolve(F, ['choiceID' => $dateChoice, 'byUserId' => U6, 'seen' => fr_seen([$d2])]);
    $e2 = fr_ev($d2);
    fr_ok('date 2 is public, source date, naming the single-date choice',
        $e2['importLevel'] === 'public' && $e2['importSource'] === 'date' && (int) $e2['importSourceID'] === $dateChoice, json_encode($e2));
    fr_ok('dates 1 and 3 are hidden, source series', fr_ls($d1) === 'hidden/series' && fr_ls($d3) === 'hidden/series', fr_ls($d1) . ' ' . fr_ls($d3));
    fr_ok('no approval row was made for the rule (a choice decided every date)',
        array_filter(fr_q('SELECT origin FROM tblExternalEventApprovals WHERE feedID = ?', 'i', [F]), static fn (array $r): bool => $r['origin'] === 'rule') === []);
    fr_q('DELETE FROM tblExternalEventChoices WHERE choiceID = ?', 'i', [$dateChoice]);
    fr_resolve(F);
    fr_ok('KEEP-WORKING: with the date choice removed, date 2 is hidden, source series — the series choice still works', fr_ls($d2) === 'hidden/series', fr_ls($d2));

    // -------------------------------------------------------------------------
    fr_heading('E2. A changed date of a repeating event is covered by the series choice');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    // The RECURRENCE-ID row: same UID, its ORIGINAL start as the key, a new start time.
    $moved = fr_event(F, 'series-e2@p7', 'Moved meeting', ['key' => '20270101T190000Z', 'day' => 9, 'clock' => '20:30:00']);
    fr_choice(F, 'series', 'series-e2@p7', 'hidden');
    fr_resolve(F);
    fr_ok('the moved date takes the series choice (hidden, source series)', fr_ls($moved) === 'hidden/series', fr_ls($moved));
    $byOriginal = fr_choice(F, 'date', 'series-e2@p7', 'groups', ['key' => '20270101T190000Z']);
    fr_list(F, 'choice', $byOriginal, [['small_group', G1]]);
    fr_resolve(F);
    fr_ok('KEEP-WORKING: a single-date choice keyed on the ORIGINAL key applies to the moved date (groups, source date)',
        fr_ls($moved) === 'groups/date', fr_ls($moved));

    // -------------------------------------------------------------------------
    fr_heading('E3. A new UID matches no choice (the event was deleted and re-created at the source)');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $old = fr_event(F, 'old-uid@p7', 'Same title');
    $new = fr_event(F, 'new-uid@p7', 'Same title');
    fr_choice(F, 'series', 'old-uid@p7', 'hidden');
    fr_resolve(F);
    fr_ok('the new UID gets the base: members, source calendar', fr_ls($new) === 'members/calendar', fr_ls($new));
    fr_ok('KEEP-WORKING: the old UID keeps its choice', fr_ls($old) === 'hidden/series', fr_ls($old));

    // -------------------------------------------------------------------------
    fr_heading('E4. Rules combine safely');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $e = fr_event(F, 'e4-a@p7', 'Combined event');
    fr_rule(F, 'public', [['title', 'contains', 'combined']], ['detail' => 'basic', 'website' => 1]);
    fr_rule(F, 'members', [['title', 'contains', 'combined']], ['detail' => 'full']);
    $r = fr_resolve(F);
    $ev = fr_ev($e);
    fr_ok('public/basic/website + members/full on F → members, basic, website 0, applied now, no approval row',
        $ev['importLevel'] === 'members' && $ev['importDetail'] === 'basic' && (int) $ev['importWebsite'] === 0
        && $ev['importSource'] === 'rule' && fr_apprCount(F) === 0, json_encode($ev));
    $fg = fr_newFeed(ORG_A, 'members');
    $conflicted = fr_event($fg, 'e4-b@p7', 'Group event');
    $rg1 = fr_rule($fg, 'groups', [['title', 'contains', 'group']]);
    fr_list($fg, 'rule', $rg1, [['small_group', G1]]);
    $rg2 = fr_rule($fg, 'groups', [['title', 'contains', 'group']]);
    fr_list($fg, 'rule', $rg2, [['small_group', G2]]);
    fr_resolve($fg);
    $ev = fr_ev($conflicted);
    fr_ok('two groups rules {G1} and {G2} → hidden, source conflict, sourceID null',
        $ev['importLevel'] === 'hidden' && $ev['importSource'] === 'conflict' && $ev['importSourceID'] === null, json_encode($ev));
    $fs = fr_newFeed(ORG_A, 'members');
    $same = fr_event($fs, 'e4-c@p7', 'Group event');
    $rs1 = fr_rule($fs, 'groups', [['title', 'contains', 'group']]);
    fr_list($fs, 'rule', $rs1, [['small_group', G1]]);
    $rs2 = fr_rule($fs, 'groups', [['title', 'contains', 'group']]);
    fr_list($fs, 'rule', $rs2, [['small_group', G1]]);
    fr_resolve($fs);
    $ev = fr_ev($same);
    fr_ok('KEEP-WORKING: two groups rules with the SAME {G1} → groups, source rule, sourceID the lower rule number, not a conflict',
        $ev['importLevel'] === 'groups' && $ev['importSource'] === 'rule' && (int) $ev['importSourceID'] === min($rs1, $rs2)
        && $ev['importAudienceType'] === 'rule' && (int) $ev['importAudienceID'] === min($rs1, $rs2), json_encode($ev));
    $onFp = fr_event(FP, 'e4-d@p7', 'Website event');
    fr_rule(FP, 'public', [['title', 'contains', 'website']], ['website' => 1]);
    fr_rule(FP, 'public', [['title', 'contains', 'website']], ['website' => 0]);
    fr_resolve(FP);
    $ev = fr_ev($onFp);
    fr_ok('on FP, public+website and public (no website) → public, website 0, applied now (no waiting row)',
        $ev['importLevel'] === 'public' && (int) $ev['importWebsite'] === 0 && $ev['importSource'] === 'rule'
        && fr_apprCount(FP, 'pending') === 0, json_encode($ev) . ' pending=' . fr_apprCount(FP, 'pending'));

    // -------------------------------------------------------------------------
    fr_heading('E5. The D12b rule and every match type (plan proofs 6 and 6b)');
    // -------------------------------------------------------------------------
    // Each rule below widens members → public at basic detail on its own
    // members calendar. "Matches" means a waiting approval row (and the
    // resolve's newPending counts it); "no match" means no row and the base.
    fr_resetWorld();
    $cases = [
        'D12b: category Outreach AND title contains "Open" EXCEPT title contains "planning"' => [
            [['category', 'equals', 'Outreach'], ['title', 'contains', 'Open'], ['title', 'contains', 'planning', true]],
            [['Open Day', null, ['outreach'], true], ['Open Day planning', null, ['outreach'], false], ['Open Day', null, ['other'], false]],
        ],
        'location contains "church hall"' => [
            [['location', 'contains', 'church hall']],
            [['Event A', "St Mark's Church Hall, Cambridge", [], true], ['Event B', 'Church Street Hall', [], false]],
        ],
        'location word "hall"' => [
            [['location', 'word', 'hall']],
            [['Event C', 'Church Hall', [], true], ['Event D', 'Hallway, Church Street', [], false]],
        ],
        'location equals "the annexe"' => [
            [['location', 'equals', 'the annexe']],
            [['Event E', '  The   Annexe ', [], true], ['Event F', 'The Annexe, rear', [], false]],
        ],
        'title equals "open day"' => [
            [['title', 'equals', 'open day']],
            [['Open Day', null, [], true], ['Open Day 2', null, [], false]],
        ],
        'title word "open"' => [
            [['title', 'word', 'open']],
            [['Open Day', null, [], true], ['Day (open)', null, [], true], ['Reopening', null, [], false]],
        ],
        'title contains "open"' => [
            [['title', 'contains', 'open']],
            [['Reopening', null, [], true]],
        ],
        // The plan's own case: "Cafe", a zero-width space, a space, "night".
        // It matches whether or not invisible characters are removed (the
        // zero-width space is not a letter, so "cafe" is still a whole word
        // before it), so it cannot catch that fault on its own. The second
        // case puts the zero-width space INSIDE the word, which only matches
        // when invisible characters really are removed. Added for that reason.
        'title word "cafe" (invisible formatting characters are removed)' => [
            [['title', 'word', 'cafe']],
            [["Cafe\u{200B} night", null, [], true], ["Caf\u{200B}e night", null, [], true]],
        ],
        'category equals "outreach" (whole words only)' => [
            [['category', 'equals', 'outreach']],
            [['Tagged one', null, ['Outreach'], true], ['Tagged two', null, ['Outreach team'], false]],
        ],
        'an exception and no ordinary condition matches NOTHING' => [
            [['title', 'contains', 'planning', true]],
            [['Open Day', null, [], false], ['Open Day planning', null, [], false]],
        ],
    ];
    foreach ($cases as $label => [$conditions, $events]) {
        $feed = fr_newFeed(ORG_A, 'members');
        fr_rule($feed, 'public', $conditions);
        $ids = [];
        foreach ($events as $i => [$title, $location, $tags, $expect]) {
            $ids[$i] = fr_event($feed, 'e5-' . $feed . '-' . $i . '@p7', $title, ['location' => $location ?? 'Somewhere', 'tags' => $tags]);
        }
        $result = fr_resolve($feed);
        $matched = 0;
        foreach ($events as $i => [$title, $location, $tags, $expect]) {
            $pending = count(fr_apprWith($ids[$i], 'pending'));
            $got     = ($pending === 1 && fr_ls($ids[$i]) === 'members/waiting');
            $none    = (fr_appr($ids[$i]) === [] && fr_ls($ids[$i]) === 'members/calendar');
            $matched += ($expect === true) ? 1 : 0;
            fr_ok($label . ': "' . $title . '"' . ($location !== null ? ' at "' . $location . '"' : '') . ($tags !== [] ? ' tagged ' . implode(',', $tags) : '')
                . ' → ' . ($expect === true ? 'matches (waiting row; members, source waiting)' : 'no match (no row; members, source calendar)'),
                ($expect === true) ? $got : $none, fr_ls($ids[$i]) . ' rows=' . json_encode(fr_appr($ids[$i])));
        }
        fr_ok($label . ': the resolve counts ' . $matched . ' new waiting row(s)', $result['newPending'] === $matched && $result['pendingTotal'] === $matched, json_encode($result));
    }

    // -------------------------------------------------------------------------
    fr_heading('E6. The approval lifecycle (plan proof 7), and decideApproval()');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $x = fr_event(F, 'e6-x@p7', 'Lifecycle event');
    $rule = fr_rule(F, 'public', [['title', 'contains', 'lifecycle']], ['detail' => 'basic']);
    fr_resolve(F);
    $row = fr_apprWith($x, 'pending')[0] ?? null;
    fr_ok('a widening rule makes one waiting row', $row !== null && fr_ls($x) === 'members/waiting', json_encode(fr_appr($x)));
    // KEEP-WORKING first: a stale content fingerprint changes nothing.
    $staleOk = FeedResolver::decideApproval($mysqli, (int) $row['approvalID'], ORG_A, 'approved', U6, '', (string) $row['requestHash'], str_repeat('0', 64));
    fr_ok('KEEP-WORKING: decideApproval() with a stale content fingerprint returns false and changes nothing',
        $staleOk === false && fr_apprWith($x, 'pending') !== [] && count(fr_appr($x)) === 1, json_encode(fr_appr($x)));
    fr_ok('KEEP-WORKING: with the right fingerprints it returns true', fr_decide((int) $row['approvalID'], 'approved') === true);
    fr_resolve(F);
    $ev = fr_ev($x);
    fr_ok('approved → public, basic, source rule', $ev['importLevel'] === 'public' && $ev['importDetail'] === 'basic' && $ev['importSource'] === 'rule', json_encode($ev));
    fr_q("UPDATE tblEvents SET startDateTime = startDateTime + INTERVAL 1 HOUR, endDateTime = endDateTime + INTERVAL 1 HOUR WHERE eventID = ?", 'i', [$x]);
    fr_resolve(F);
    fr_ok('only the start time changed → still public (dates and times are not in the content fingerprint, D14)', fr_ls($x) === 'public/rule', fr_ls($x));
    fr_q("UPDATE tblEvents SET locationName = 'A different hall' WHERE eventID = ?", 'i', [$x]);
    fr_resolve(F);
    fr_ok('at basic detail, a location change → still public (basic viewers never see the location)', fr_ls($x) === 'public/rule', fr_ls($x));
    fr_q("UPDATE tblEvents SET eventName = 'Lifecycle event, renamed' WHERE eventID = ?", 'i', [$x]);
    fr_resolve(F);
    fr_ok('a title change → the approved row superseded, one new waiting row with reason content_changed, members',
        count(fr_apprWith($x, 'superseded')) === 1 && count(fr_apprWith($x, 'pending')) === 1
        && fr_apprWith($x, 'pending')[0]['reason'] === 'content_changed' && fr_ls($x) === 'members/waiting', json_encode(fr_appr($x)));

    // At FULL detail, a location change asks again.
    $full = fr_newFeed(ORG_A, 'members');
    $y = fr_event($full, 'e6-y@p7', 'Full detail event');
    fr_rule($full, 'public', [['title', 'contains', 'full detail']], ['detail' => 'full']);
    fr_resolve($full);
    fr_decide((int) fr_apprWith($y, 'pending')[0]['approvalID'], 'approved');
    fr_resolve($full);
    fr_ok('at full detail, approved → public', fr_ls($y) === 'public/rule', fr_ls($y));
    fr_q("UPDATE tblEvents SET locationName = 'Somewhere else' WHERE eventID = ?", 'i', [$y]);
    fr_resolve($full);
    fr_ok('at full detail, a location change → the approved row superseded, a new waiting row content_changed, members',
        count(fr_apprWith($y, 'superseded')) === 1 && (fr_apprWith($y, 'pending')[0]['reason'] ?? '') === 'content_changed'
        && fr_ls($y) === 'members/waiting', json_encode(fr_appr($y)));

    // Two live rows for one event and one request, put in by hand: a declined
    // one and a NEWER approved one. Neither may be trusted; the event waits.
    $dup = fr_newFeed(ORG_A, 'members');
    $z = fr_event($dup, 'e6-z@p7', 'Two rows event');
    fr_rule($dup, 'public', [['title', 'contains', 'two rows']]);
    fr_resolve($dup);
    $p = fr_apprWith($z, 'pending')[0];
    $older = fr_text(fr_nowUtc()->modify('-2 hours'));
    $newer = fr_text(fr_nowUtc()->modify('-1 hour'));
    fr_q("UPDATE tblExternalEventApprovals SET status = 'declined', decidedByID = ?, decidedAt = ? WHERE approvalID = ?", 'isi', [U6, $older, (int) $p['approvalID']]);
    fr_q('INSERT INTO tblExternalEventApprovals (siteID, feedID, eventID, origin, originID, requestHash, contentHash, status, reason, requestedLevel, '
        . 'requestedDetail, requestedWebsite, requestedAudienceSummary, snapTitle, snapStart, snapTimezone, decidedByID, decidedAt, createdAt) '
        . "SELECT siteID, feedID, eventID, origin, originID, requestHash, contentHash, 'approved', reason, requestedLevel, requestedDetail, "
        . 'requestedWebsite, requestedAudienceSummary, snapTitle, snapStart, snapTimezone, ?, ?, createdAt FROM tblExternalEventApprovals WHERE approvalID = ?',
        'isi', [U6, $newer, (int) $p['approvalID']]);
    fr_resolve($dup);
    fr_ok('two live rows for one request (declined, then a NEWER approved) → BOTH superseded, one new waiting row, members',
        count(fr_apprWith($z, 'superseded')) === 2 && count(fr_apprWith($z, 'pending')) === 1 && fr_ls($z) === 'members/waiting',
        json_encode(fr_appr($z)));

    // A decision word other than approved/declined is refused BEFORE any SQL:
    // proved by handing it a connection that has already been closed, which
    // would raise a database error — a different kind of fault — if any SQL
    // were attempted first.
    $closed = new mysqli(
        getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
        getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
        getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
        $dbName,
        getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
    );
    $closed->close();
    $threw = 'nothing';
    try {
        FeedResolver::decideApproval($closed, 1, ORG_A, 'maybe', U6, '', str_repeat('a', 64), str_repeat('b', 64));
    } catch (InvalidArgumentException $expected) {
        $threw = 'InvalidArgumentException';
    } catch (Throwable $other) {
        $threw = get_class($other);
    }
    fr_ok('decideApproval() with the word "maybe" throws InvalidArgumentException before any SQL (a closed connection was never touched)',
        $threw === 'InvalidArgumentException', 'threw ' . $threw);

    // Another organisation's waiting row cannot be decided with A's number.
    $bRow = fr_event(FB, 'e6-b@p7', 'Organisation B event');
    fr_rule(FB, 'public', [['title', 'contains', 'organisation b']], ['website' => 1]);
    fr_resolve(FB);
    $bPending = fr_apprWith($bRow, 'pending')[0] ?? null;
    $crossed = ($bPending === null) ? null : FeedResolver::decideApproval($mysqli, (int) $bPending['approvalID'], ORG_A, 'approved', U6, '',
        (string) $bPending['requestHash'], (string) $bPending['contentHash']);
    fr_ok("organisation B's waiting row decided with A's organisation number → false, and the row is unchanged",
        $bPending !== null && $crossed === false && fr_apprWith($bRow, 'pending') !== [] && count(fr_appr($bRow)) === 1, json_encode(fr_appr($bRow)));

    // -------------------------------------------------------------------------
    // Round-1 check, finding i: decideApproval()'s claim must require
    // status = 'pending' (:482) — otherwise a stale page's fingerprints can
    // overturn a decision a colleague already made, which the method's own
    // comment promises cannot happen ("two administrators deciding at once").
    // -------------------------------------------------------------------------
    $rcICal = fr_newFeed(ORG_A, 'members');
    $rcIEv1 = fr_event($rcICal, 'rc-i-alpha@p7', 'Round-1 check i alpha');
    fr_rule($rcICal, 'public', [['title', 'contains', 'check i alpha']]);
    $rcIEv2 = fr_event($rcICal, 'rc-i-beta@p7', 'Round-1 check i beta');
    fr_rule($rcICal, 'public', [['title', 'contains', 'check i beta']]);
    fr_resolve($rcICal);
    $rcIRow1 = fr_apprWith($rcIEv1, 'pending')[0];
    fr_ok('round-1 check i, KEEP-WORKING: approving a still-pending row with the right fingerprints succeeds',
        fr_decide((int) $rcIRow1['approvalID'], 'approved') === true);
    fr_resolve($rcICal);
    fr_ok('round-1 check i, KEEP-WORKING: ...and it is now public', fr_ls($rcIEv1) === 'public/rule', fr_ls($rcIEv1));
    $rcIRow2 = fr_apprWith($rcIEv2, 'pending')[0];
    $rcIReq2 = (string) $rcIRow2['requestHash'];
    $rcICon2 = (string) $rcIRow2['contentHash'];
    fr_decide((int) $rcIRow2['approvalID'], 'declined');
    fr_resolve($rcICal);
    $rcIStale = FeedResolver::decideApproval($mysqli, (int) $rcIRow2['approvalID'], ORG_A, 'approved', U6, '', $rcIReq2, $rcICon2);
    fr_resolve($rcICal);
    fr_ok('round-1 check i: a stale approve with the ORIGINAL fingerprints, after the row was already declined → refused; the decline stands',
        $rcIStale === false && fr_ls($rcIEv2) === 'members/calendar' && fr_apprWith($rcIEv2, 'declined') !== [] && fr_apprWith($rcIEv2, 'approved') === [],
        'stale approve returned ' . var_export($rcIStale, true) . '; ' . fr_ls($rcIEv2) . ' ' . json_encode(fr_appr($rcIEv2)));

    // -------------------------------------------------------------------------
    fr_heading('E7. Decline (plan proof 8, A15)');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $x = fr_event(F, 'e7@p7', 'Declined event');
    fr_rule(F, 'public', [['title', 'contains', 'declined']]);
    fr_resolve(F);
    fr_decide((int) fr_apprWith($x, 'pending')[0]['approvalID'], 'declined');
    fr_resolve(F);
    fr_ok('declined → members, source calendar', fr_ls($x) === 'members/calendar', fr_ls($x));
    $before = count(fr_appr($x));
    $r = fr_resolve(F);
    fr_ok('the next resolve with the same content adds NO row (leak-hunt finding 25)', count(fr_appr($x)) === $before && $r['newPending'] === 0, json_encode(fr_appr($x)));
    fr_q("UPDATE tblEvents SET eventName = 'Declined event, renamed' WHERE eventID = ?", 'i', [$x]);
    $r = fr_resolve(F);
    fr_ok('KEEP-WORKING: a title change → the declined row superseded and ONE new waiting row, content_changed',
        count(fr_apprWith($x, 'superseded')) === 1 && count(fr_apprWith($x, 'pending')) === 1
        && fr_apprWith($x, 'pending')[0]['reason'] === 'content_changed' && $r['newPending'] === 1, json_encode(fr_appr($x)));

    // -------------------------------------------------------------------------
    fr_heading('E8. The request changes, and removal by origin (plan proof 9)');
    // -------------------------------------------------------------------------
    // A groups calendar whose own list is one named person, so that both
    // {G1} and {G2} are WIDER than it and need approval. (On a Members
    // calendar a groups rule is narrower and would apply without asking,
    // which would prove nothing about approvals.)
    fr_resetWorld();
    $gcal = fr_newFeed(ORG_A, 'groups');
    fr_list($gcal, 'feed', $gcal, [['person', U1]]);
    $x = fr_event($gcal, 'e8@p7', 'Group rule event');
    $gr = fr_rule($gcal, 'groups', [['title', 'contains', 'group rule']]);
    fr_list($gcal, 'rule', $gr, [['small_group', G1]]);
    fr_resolve($gcal);
    $g1Row = fr_apprWith($x, 'pending')[0];
    fr_decide((int) $g1Row['approvalID'], 'approved');
    fr_resolve($gcal);
    fr_ok('the {G1} rule, approved → groups, source rule', fr_ls($x) === 'groups/rule', fr_ls($x));
    fr_q("DELETE FROM tblExternalAudienceMembers WHERE ownerType = 'rule' AND ownerID = ?", 'i', [$gr]);
    fr_list($gcal, 'rule', $gr, [['small_group', G2]]);
    fr_resolve($gcal);
    $approvedRows = fr_apprWith($x, 'approved');
    fr_ok('list edited to {G2} → groups (the calendar\'s own), source waiting, a new waiting row; the {G1} approved row is still approved (history) and not applied',
        fr_ls($x) === 'groups/waiting' && count(fr_apprWith($x, 'pending')) === 1 && count($approvedRows) === 1
        && (int) $approvedRows[0]['approvalID'] === (int) $g1Row['approvalID'], json_encode(fr_appr($x)));
    fr_q('UPDATE tblExternalFeedRules SET isActive = 0 WHERE ruleID = ?', 'i', [$gr]);
    fr_resolve($gcal);
    fr_ok('KEEP-WORKING: rule switched off → the waiting row withdrawn; the calendar\'s own answer, source calendar',
        count(fr_apprWith($x, 'withdrawn')) === 1 && fr_apprWith($x, 'pending') === [] && fr_ls($x) === 'groups/calendar', json_encode(fr_appr($x)));
    fr_q("DELETE FROM tblExternalAudienceMembers WHERE ownerType = 'rule' AND ownerID = ?", 'i', [$gr]);
    fr_list($gcal, 'rule', $gr, [['small_group', G1]]);
    fr_q('UPDATE tblExternalFeedRules SET isActive = 1 WHERE ruleID = ?', 'i', [$gr]);
    fr_resolve($gcal);
    fr_ok('switched back on with {G1}: the old approved row applies at once (the same request and content an administrator already approved)',
        fr_ls($x) === 'groups/rule' && fr_apprWith($x, 'pending') === [], fr_ls($x) . ' ' . json_encode(fr_appr($x)));

    // withdrawForOrigin(): only the rows FILED UNDER that rule.
    $two = fr_newFeed(ORG_A, 'members');
    $y = fr_event($two, 'e8-two@p7', 'Two rules event');
    $ruleA = fr_rule($two, 'public', [['title', 'contains', 'two rules']]);
    $ruleB = fr_rule($two, 'public', [['title', 'contains', 'rules event']]);
    fr_resolve($two);
    $filed = fr_apprWith($y, 'pending')[0] ?? ['originID' => 0];
    fr_ok('two widening rules on one event → one waiting row, filed under the lower-numbered rule (rule A)',
        (int) $filed['originID'] === min($ruleA, $ruleB), json_encode(fr_appr($y)));
    $n = FeedResolver::withdrawForOrigin($mysqli, $two, 'rule', max($ruleA, $ruleB));
    fr_ok('withdrawForOrigin(rule B) → 0, and the row is still waiting', $n === 0 && count(fr_apprWith($y, 'pending')) === 1, (string) $n);
    $n = FeedResolver::withdrawForOrigin($mysqli, $two, 'rule', min($ruleA, $ruleB));
    fr_ok('KEEP-WORKING: withdrawForOrigin(rule A) → 1, withdrawn', $n === 1 && count(fr_apprWith($y, 'withdrawn')) === 1, (string) $n);
    fr_q('DELETE FROM tblExternalFeedRules WHERE ruleID = ?', 'i', [min($ruleA, $ruleB)]);
    fr_resolve($two);
    $pend = fr_apprWith($y, 'pending');
    fr_ok('after deleting rule A and resolving, the new request (rule B alone) has its own waiting row, and no row of the old request is waiting',
        count($pend) === 1 && (int) $pend[0]['originID'] === max($ruleA, $ruleB) && $pend[0]['requestHash'] !== $filed['requestHash'],
        json_encode(fr_appr($y)));

    // -------------------------------------------------------------------------
    // Round-1 check, finding a: isWider() must compare the CANDIDATE's list
    // against the BASE's list, not the other way round (:1642,
    // array_diff($candidate['list'], $base['list'])). A groups calendar
    // whose own list is {G1}; a rule listing {G1,G2} adds a group the
    // calendar itself never named, so it is WIDER and must wait.
    // -------------------------------------------------------------------------
    $rcACal  = fr_newFeed(ORG_A, 'groups');
    fr_list($rcACal, 'feed', $rcACal, [['small_group', G1]]);
    $rcAEv   = fr_event($rcACal, 'rc-a@p7', 'Round-1 check a');
    $rcARule = fr_rule($rcACal, 'groups', [['title', 'contains', 'round-1 check a']]);
    fr_list($rcACal, 'rule', $rcARule, [['small_group', G1], ['small_group', G2]]);
    fr_resolve($rcACal);
    fr_ok('round-1 check a: a rule listing {G1,G2} on a {G1} groups calendar → waits (G2 is new to the calendar)',
        fr_ls($rcAEv) === 'groups/waiting' && count(fr_apprWith($rcAEv, 'pending')) === 1, fr_ls($rcAEv) . ' ' . json_encode(fr_appr($rcAEv)));
    $rcACal2  = fr_newFeed(ORG_A, 'groups');
    fr_list($rcACal2, 'feed', $rcACal2, [['small_group', G1]]);
    $rcAEv2   = fr_event($rcACal2, 'rc-a2@p7', 'Round-1 check a control');
    $rcARule2 = fr_rule($rcACal2, 'groups', [['title', 'contains', 'round-1 check a control']]);
    fr_list($rcACal2, 'rule', $rcARule2, [['small_group', G1]]);
    fr_resolve($rcACal2);
    fr_ok('round-1 check a, KEEP-WORKING: the SAME shape of rule listing only {G1} (the calendar\'s own list) → NOT wider, applies at once',
        fr_ls($rcAEv2) === 'groups/rule' && fr_appr($rcAEv2) === [], fr_ls($rcAEv2));

    // -------------------------------------------------------------------------
    // Round-1 check, finding h: choiceCandidate() must use the CHOICE's own
    // groups list, never an empty one (:1534). A {G1} groups calendar; a
    // date choice listing only {G2} is WIDER (G2 is new) and must wait.
    // -------------------------------------------------------------------------
    $rcHCal    = fr_newFeed(ORG_A, 'groups');
    fr_list($rcHCal, 'feed', $rcHCal, [['small_group', G1]]);
    $rcHEv     = fr_event($rcHCal, 'rc-h@p7', 'Round-1 check h', ['key' => 'k1']);
    $rcHChoice = fr_choice($rcHCal, 'date', 'rc-h@p7', 'groups', ['key' => 'k1']);
    fr_list($rcHCal, 'choice', $rcHChoice, [['small_group', G2]]);
    fr_resolve($rcHCal);
    fr_ok('round-1 check h: a date choice listing only {G2} on a {G1} groups calendar → waits (G2 is new to the calendar)',
        fr_ls($rcHEv) === 'groups/waiting' && count(fr_apprWith($rcHEv, 'pending')) === 1, fr_ls($rcHEv) . ' ' . json_encode(fr_appr($rcHEv)));
    $rcHCal2    = fr_newFeed(ORG_A, 'groups');
    fr_list($rcHCal2, 'feed', $rcHCal2, [['small_group', G1]]);
    $rcHEv2     = fr_event($rcHCal2, 'rc-h2@p7', 'Round-1 check h control', ['key' => 'k1']);
    $rcHChoice2 = fr_choice($rcHCal2, 'date', 'rc-h2@p7', 'groups', ['key' => 'k1']);
    fr_list($rcHCal2, 'choice', $rcHChoice2, [['small_group', G1]]);
    fr_resolve($rcHCal2);
    fr_ok('round-1 check h, KEEP-WORKING: the SAME shape of date choice listing only {G1} (the calendar\'s own list) → NOT wider, applies at once',
        fr_ls($rcHEv2) === 'groups/date' && fr_appr($rcHEv2) === [], fr_ls($rcHEv2));

    // -------------------------------------------------------------------------
    fr_heading('E9. The website box widens (plan proof 10, leak-hunt finding 10)');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $x = fr_event(FP, 'e9-a@p7', 'Website wanted');
    $y = fr_event(FP, 'e9-b@p7', 'Website not wanted');
    fr_rule(FP, 'public', [['title', 'equals', 'website wanted']], ['website' => 1]);
    fr_rule(FP, 'public', [['title', 'equals', 'website not wanted']], ['website' => 0]);
    fr_resolve(FP);
    fr_ok('on FP (public, website box clear), a rule "public + website" → waiting', fr_ls($x) === 'public/waiting' && count(fr_apprWith($x, 'pending')) === 1, fr_ls($x));
    fr_ok('KEEP-WORKING: a rule "public" without the box → applied now', fr_ls($y) === 'public/rule' && fr_appr($y) === [], fr_ls($y));

    // -------------------------------------------------------------------------
    fr_heading('E10. Private marks (plan proof 11)');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $x = fr_event(F, 'e10-rule@p7', 'Private rule event', ['private' => 1]);
    fr_rule(F, 'public', [['title', 'contains', 'private rule']]);
    fr_resolve(F);
    fr_ok('a public rule on a private-marked event → hidden, source private, NO approval row', fr_ls($x) === 'hidden/private' && fr_appr($x) === [], fr_ls($x));
    $pc = fr_newFeed(ORG_A, 'members');
    $y = fr_event($pc, 'e10-choice@p7', 'Private choice event', ['private' => 1]);
    $noOverride = fr_choice($pc, 'series', 'e10-choice@p7', 'public');
    fr_resolve($pc, ['choiceID' => $noOverride, 'byUserId' => U6, 'seen' => fr_seen([$y])]);
    fr_ok('a public choice without the override → hidden, source private', fr_ls($y) === 'hidden/private' && fr_appr($y) === [], fr_ls($y));

    $oc = fr_newFeed(ORG_A, 'members');
    $s1 = fr_event($oc, 'e10-series@p7', 'Pastoral visit', ['private' => 1, 'key' => 'k1', 'day' => 7]);
    $s2 = fr_event($oc, 'e10-series@p7', 'Pastoral visit (changed)', ['private' => 1, 'key' => 'k2', 'day' => 14]);
    $s3 = fr_event($oc, 'e10-series@p7', 'Pastoral visit', ['private' => 1, 'key' => 'k3', 'day' => 21]);
    $override = fr_choice($oc, 'series', 'e10-series@p7', 'public', ['override' => 1, 'detail' => 'basic']);
    fr_resolve($oc, ['choiceID' => $override, 'byUserId' => U6, 'seen' => fr_seen([$s1])]);
    $seenRow = fr_apprWith($s1, 'approved')[0] ?? null;
    fr_ok('with the override, saved with the date shown → public, approved, reason choice, decided by U6',
        fr_ls($s1) === 'public/series' && $seenRow !== null && $seenRow['reason'] === 'choice' && (int) $seenRow['decidedByID'] === U6,
        fr_ls($s1) . ' ' . json_encode(fr_appr($s1)));
    fr_ok('a later date NOT shown, with a different title → waiting, new_match', fr_ls($s2) === 'hidden/waiting'
        && (fr_apprWith($s2, 'pending')[0]['reason'] ?? '') === 'new_match', fr_ls($s2) . ' ' . json_encode(fr_appr($s2)));
    $twin = fr_apprWith($s3, 'approved')[0] ?? null;
    fr_ok('KEEP-WORKING (the twin): a later date NOT shown, identical to the shown one → approved, series_match, its note naming the shown date\'s approval',
        fr_ls($s3) === 'public/series' && $twin !== null && $twin['reason'] === 'series_match' && $seenRow !== null
        && str_contains((string) $twin['decisionNote'], '#' . (int) $seenRow['approvalID'] . ')'), fr_ls($s3) . ' ' . json_encode(fr_appr($s3)));
    $f = EventVisibility::fullDetailSelect('e', EventVisibility::MODE_SESSION, U1, fr_todayLocal()->format('Y-m-d'));
    $canSee = (int) (fr_one('SELECT ' . $f['sql'] . ' FROM tblEvents e WHERE e.eventID = ?', $f['types'] . 'i', array_merge($f['params'], [$s1]))['canSeeFull'] ?? -1);
    fr_ok('canSeeFull (session mode) for U1, a member, on the private-marked event shown at basic detail → 0', $canSee === 0, (string) $canSee);

    // -------------------------------------------------------------------------
    fr_heading('E11. Date windows (plan proofs 12 and 19, every date from now)');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $london = fr_zone();
    $ended = fr_newFeed(ORG_A, 'members');
    $x = fr_event($ended, 'e11-ended@p7', 'Ended choice event');
    fr_choice($ended, 'series', 'e11-ended@p7', 'public', ['to' => fr_d('yesterday')]);
    fr_resolve($ended);
    $ev = fr_ev($x);
    fr_ok('a public choice that ended yesterday → the base (members, calendar), and no re-check moment (no boundary ahead)',
        fr_ls($x) === 'members/calendar' && $ev['importRecheckAt'] === null, json_encode($ev));

    // A choice from four days ahead, saved NOW with its first date shown.
    $future = fr_newFeed(ORG_A, 'members');
    $w1 = fr_event($future, 'e11-future@p7', 'Harvest supper', ['key' => 'k1', 'day' => 10]);
    $w2 = fr_event($future, 'e11-future@p7', 'Harvest supper (moved indoors)', ['key' => 'k2', 'day' => 17]);
    $w3 = fr_event($future, 'e11-future@p7', 'Harvest supper', ['key' => 'k3', 'day' => 24]);
    $fromChoice = fr_choice($future, 'series', 'e11-future@p7', 'public', ['from' => fr_d('plus4')]);
    $start = fr_dayStart(fr_d('plus4'));
    $r = fr_resolve($future, ['choiceID' => $fromChoice, 'byUserId' => U6, 'seen' => fr_seen([$w1])]);
    $ev = fr_ev($w1);
    fr_ok('from ' . fr_d('plus4') . ', saved now with the first date shown → the base now, re-check at that day\'s first moment (' . fr_text($start) . ' UTC)',
        fr_ls($w1) === 'members/calendar' && $ev['importRecheckAt'] === fr_text($start), json_encode($ev));
    fr_ok('...and the approval of the shown date is recorded at save time (A26): approved, reason choice',
        count(fr_apprWith($w1, 'approved')) === 1 && fr_apprWith($w1, 'approved')[0]['reason'] === 'choice' && $r['approvedBySave'] === 0,
        json_encode(fr_appr($w1)) . ' ' . json_encode($r));
    fr_resolveAt($start->modify('-1 second'), $future, null, static function () use ($w1): void {
        fr_ok('KEEP-WORKING: one second before that moment → still the base', fr_ls($w1) === 'members/calendar', fr_ls($w1));
    });
    fr_resolveAt($start, $future, null, static function () use ($w1, $w2, $w3): void {
        fr_ok('at exactly that moment → public, source series, through the approval recorded when it was saved', fr_ls($w1) === 'public/series', fr_ls($w1));
        fr_ok('...a date NOT shown, with a different title → waiting, new_match',
            fr_ls($w2) === 'members/waiting' && (fr_apprWith($w2, 'pending')[0]['reason'] ?? '') === 'new_match', fr_ls($w2) . ' ' . json_encode(fr_appr($w2)));
        fr_ok('KEEP-WORKING (the twin): a date NOT shown, identical to the shown one → approved, series_match',
            fr_ls($w3) === 'public/series' && (fr_apprWith($w3, 'approved')[0]['reason'] ?? '') === 'series_match', fr_ls($w3) . ' ' . json_encode(fr_appr($w3)));
    });

    // A single-date hidden choice that ended yesterday, and a series public
    // choice saved now with the date shown.
    $pair = fr_newFeed(ORG_A, 'members');
    $d = fr_event($pair, 'e11-pair@p7', 'Pair event', ['key' => 'k1']);
    fr_choice($pair, 'date', 'e11-pair@p7', 'hidden', ['key' => 'k1', 'to' => fr_d('yesterday')]);
    $pairSeries = fr_choice($pair, 'series', 'e11-pair@p7', 'public');
    fr_resolve($pair, ['choiceID' => $pairSeries, 'byUserId' => U6, 'seen' => fr_seen([$d])]);
    fr_ok('the single-date choice has ended, so the series choice decides → public, source series', fr_ls($d) === 'public/series', fr_ls($d));
    $yesterdayNoon = (new DateTimeImmutable(fr_d('yesterday') . ' 12:00:00', $london))->setTimezone(new DateTimeZone('UTC'));
    fr_resolveAt($yesterdayNoon, $pair, null, static function () use ($d): void {
        $ev = fr_ev($d);
        fr_ok('resolved at yesterday 12:00 (London) → hidden, source date, re-check at the start of today (' . fr_text(fr_dayStart(fr_todayLocal()->format('Y-m-d'))) . ')',
            fr_ls($d) === 'hidden/date' && $ev['importRecheckAt'] === fr_text(fr_dayStart(fr_todayLocal()->format('Y-m-d'))), json_encode($ev));
    });

    // The reverse order (challenge finding 6): the series choice saved AT
    // yesterday 12:00, while the single-date choice still decides the date.
    $rev = fr_newFeed(ORG_A, 'members');
    $d3 = fr_event($rev, 'e11-rev@p7', 'Reverse event', ['key' => 'k3']);
    fr_choice($rev, 'date', 'e11-rev@p7', 'hidden', ['key' => 'k3', 'to' => fr_d('yesterday')]);
    $revSeries = fr_choice($rev, 'series', 'e11-rev@p7', 'public');
    fr_resolveAt($yesterdayNoon, $rev, ['choiceID' => $revSeries, 'byUserId' => U6, 'seen' => fr_seen([$d3])], static function () use ($d3): void {
        fr_ok('saved at yesterday 12:00 while the single-date choice decides: hidden, source date — and an approved row for the series request is recorded (11b)',
            fr_ls($d3) === 'hidden/date' && count(fr_apprWith($d3, 'approved')) === 1 && fr_apprWith($d3, 'approved')[0]['reason'] === 'choice',
            fr_ls($d3) . ' ' . json_encode(fr_appr($d3)));
    });
    $r = fr_resolve($rev);
    fr_ok('resolved now, after the single-date choice has ended → public, source series, and NO new waiting row',
        fr_ls($d3) === 'public/series' && fr_apprWith($d3, 'pending') === [] && $r['newPending'] === 0, fr_ls($d3) . ' ' . json_encode(fr_appr($d3)));

    // An ended series choice lets a matching widening rule apply.
    $ruled = fr_newFeed(ORG_A, 'members');
    $q = fr_event($ruled, 'e11-rule@p7', 'Ruled event');
    fr_choice($ruled, 'series', 'e11-rule@p7', 'public', ['to' => fr_d('yesterday')]);
    fr_rule($ruled, 'public', [['title', 'contains', 'ruled']]);
    fr_resolve($ruled);
    fr_ok('a series choice that ended yesterday, and a matching widening rule → the rule waits: members, source waiting',
        fr_ls($q) === 'members/waiting' && count(fr_apprWith($q, 'pending')) === 1 && fr_apprWith($q, 'pending')[0]['origin'] === 'rule', fr_ls($q));

    // -------------------------------------------------------------------------
    // Round-1 check, finding e: "seen" must also be checked against the
    // ORIGIN (:1188-1189, not only which choice) — a RULE's widening must
    // never be approved by saving an unrelated CHOICE that lists the date.
    // -------------------------------------------------------------------------
    $rcECal = fr_newFeed(ORG_A, 'members');
    $rcEK1  = fr_event($rcECal, 'rc-e@p7', 'Round-1 check e choir');
    fr_rule($rcECal, 'public', [['title', 'contains', 'round-1 check e choir']]);
    $rcES   = fr_choice($rcECal, 'series', 'rc-e@p7', 'public', ['from' => fr_d('plus4')]);
    fr_resolve($rcECal);
    fr_ok('round-1 check e, KEEP-WORKING (different numbers — the rule and the choice never share a number here): before saving the future choice, the rule\'s widening is waiting',
        fr_ls($rcEK1) === 'members/waiting', fr_ls($rcEK1));
    fr_resolve($rcECal, ['choiceID' => $rcES, 'byUserId' => U6, 'seen' => fr_seen([$rcEK1])]);
    fr_ok('round-1 check e, KEEP-WORKING (different numbers): saving the future series choice (not yet active) must NOT approve the RULE\'s widening',
        fr_ls($rcEK1) === 'members/waiting', fr_ls($rcEK1) . ' ' . json_encode(fr_appr($rcEK1)));

    // -------------------------------------------------------------------------
    // Round-2 check gap 1 (24 September 2026): the check above proved
    // nothing, because it numbers rules from 900600 and choices from 900500,
    // so a rule and a choice never share a number in THIS test. On a real
    // installation they will — `tblExternalFeedRules.ruleID` and
    // `tblExternalEventChoices.choiceID` are separate AUTO_INCREMENT
    // columns that both start at 1 (migration 206, `:217` and `:248`), so
    // the first rule and the first choice are both #1. With only the
    // NUMBER half of the ":1226-1227" check (`$candidate['originIds'] ===
    // [$saved['choiceID']]`) and the ORIGIN half (`$candidate['origin'] ===
    // 'choice'`) removed, a rule whose number happens to match a saved
    // choice's number was wrongly treated as "seen" by that save, and its
    // widening request — here public + the website box — was approved and
    // published without anyone deciding it. This scenario forces that
    // number collision on purpose, using `fr_choice()`'s `id` override, so
    // it cannot pass for the wrong reason the way the check above did.
    // -------------------------------------------------------------------------
    $rcE2Cal   = fr_newFeed(ORG_A, 'members');
    $rcE2K1    = fr_event($rcE2Cal, 'rc-e2@p7', 'Round-2 check e2 choir');
    $rcE2Rule  = fr_rule($rcE2Cal, 'public', [['title', 'contains', 'round-2 check e2 choir']], ['website' => 1]);
    $rcE2S     = fr_choice($rcE2Cal, 'series', 'rc-e2@p7', 'public', ['from' => fr_d('plus4'), 'id' => $rcE2Rule]);
    fr_ok('round-2 check gap 1, set-up: the future choice was forced to share the rule\'s OWN number',
        $rcE2S === $rcE2Rule, 'rule #' . $rcE2Rule . ' choice #' . $rcE2S);
    fr_resolve($rcE2Cal);
    fr_ok('round-2 check gap 1, KEEP-WORKING: before saving the future choice, the rule\'s widening (public + website) is waiting',
        fr_ls($rcE2K1) === 'members/waiting', fr_ls($rcE2K1));
    fr_resolve($rcE2Cal, ['choiceID' => $rcE2S, 'byUserId' => U6, 'seen' => fr_seen([$rcE2K1])]);
    fr_ok('round-2 check gap 1: saving a CHOICE that happens to share the RULE\'S OWN NUMBER must NOT approve the rule\'s widening — "seen" must be checked against the ORIGIN, not the number alone',
        fr_ls($rcE2K1) === 'members/waiting', fr_ls($rcE2K1) . ' ' . json_encode(fr_appr($rcE2K1)));

    // -------------------------------------------------------------------------
    // Round-1 check, finding f: 11b's own content fingerprint check (:1495)
    // must run — a future choice saved while the title changed (the page
    // showed the OLD title) must wait at its start, not go public on a
    // title nobody saw.
    // -------------------------------------------------------------------------
    $rcFCal       = fr_newFeed(ORG_A, 'members');
    $rcFK1        = fr_event($rcFCal, 'rc-f-stale@p7', 'Round-1 check f lunch');
    $rcFK2        = fr_event($rcFCal, 'rc-f-fresh@p7', 'Round-1 check f lunch fresh');
    $rcFS1        = fr_choice($rcFCal, 'series', 'rc-f-stale@p7', 'public', ['from' => fr_d('plus4')]);
    $rcFS2        = fr_choice($rcFCal, 'series', 'rc-f-fresh@p7', 'public', ['from' => fr_d('plus4')]);
    $rcFSeenStale = fr_seen([$rcFK1]);
    $rcFSeenFresh = fr_seen([$rcFK2]);
    fr_q("UPDATE tblEvents SET eventName = 'Round-1 check f lunch, moved' WHERE eventID = ?", 'i', [$rcFK1]);
    fr_resolve($rcFCal, ['choiceID' => $rcFS1, 'byUserId' => U6, 'seen' => $rcFSeenStale]);
    fr_resolve($rcFCal, ['choiceID' => $rcFS2, 'byUserId' => U6, 'seen' => $rcFSeenFresh]);
    fr_resolveAt(fr_dayStart(fr_d('plus4')), $rcFCal, null, static function () use ($rcFK1, $rcFK2): void {
        fr_ok('round-1 check f: a future choice saved with a STALE fingerprint (the title changed while the page was open) must WAIT at the start',
            fr_ls($rcFK1) === 'members/waiting', fr_ls($rcFK1) . ' ' . json_encode(fr_appr($rcFK1)));
        fr_ok('round-1 check f, KEEP-WORKING: the twin, saved with a FRESH fingerprint (nothing changed), applies at the start',
            fr_ls($rcFK2) === 'public/series', fr_ls($rcFK2) . ' ' . json_encode(fr_appr($rcFK2)));
    });

    // -------------------------------------------------------------------------
    // Round-1 check, finding g: 11b's own private-mark check (:1486) must
    // run — a private date shown on a future choice WITHOUT the override
    // must wait at the start even after the source removes the private
    // mark, never go public on the strength of a save made while it was
    // private.
    // -------------------------------------------------------------------------
    $rcGCal = fr_newFeed(ORG_A, 'members');
    $rcGK1  = fr_event($rcGCal, 'rc-g-private@p7', 'Round-1 check g private', ['private' => 1]);
    $rcGK2  = fr_event($rcGCal, 'rc-g-open@p7', 'Round-1 check g open');
    $rcGS1  = fr_choice($rcGCal, 'series', 'rc-g-private@p7', 'public', ['from' => fr_d('plus4')]);
    $rcGS2  = fr_choice($rcGCal, 'series', 'rc-g-open@p7', 'public', ['from' => fr_d('plus4')]);
    fr_resolve($rcGCal);
    fr_resolve($rcGCal, ['choiceID' => $rcGS1, 'byUserId' => U6, 'seen' => fr_seen([$rcGK1])]);
    fr_ok('round-1 check g: saving the future choice while the date is PRIVATE (no override) records NO approval row',
        fr_appr($rcGK1) === [], json_encode(fr_appr($rcGK1)));
    fr_resolve($rcGCal, ['choiceID' => $rcGS2, 'byUserId' => U6, 'seen' => fr_seen([$rcGK2])]);
    fr_q('UPDATE tblEvents SET externalPrivate = 0 WHERE eventID = ?', 'i', [$rcGK1]);
    fr_resolveAt(fr_dayStart(fr_d('plus4')), $rcGCal, null, static function () use ($rcGK1, $rcGK2): void {
        fr_ok('round-1 check g: the source un-marks it private AFTER the save — at the start it must still WAIT, not go public',
            fr_ls($rcGK1) === 'members/waiting', fr_ls($rcGK1) . ' ' . json_encode(fr_appr($rcGK1)));
        fr_ok('round-1 check g, KEEP-WORKING: the twin (never private) saved the same way → applies at the start',
            fr_ls($rcGK2) === 'public/series', fr_ls($rcGK2) . ' ' . json_encode(fr_appr($rcGK2)));
    });

    // -------------------------------------------------------------------------
    // Round-1 check, tenth finding (C7, optional but cheap): 11b must still
    // skip an ENDED choice (:1483) even when another, currently-active
    // choice decides the date — otherwise re-saving an expired choice adds a
    // spurious approval row for a request nothing will ever use again.
    // -------------------------------------------------------------------------
    $rcJCal = fr_newFeed(ORG_A, 'members');
    $rcJK1  = fr_event($rcJCal, 'rc-j@p7', 'Round-1 check tenth', ['key' => 'k1']);
    fr_choice($rcJCal, 'date', 'rc-j@p7', 'public', ['key' => 'k1']);
    $rcJS   = fr_choice($rcJCal, 'series', 'rc-j@p7', 'public', ['to' => fr_d('yesterday')]);
    fr_resolve($rcJCal);
    $rcJRowsBefore = count(fr_appr($rcJK1));
    fr_ok('round-1 check tenth, KEEP-WORKING: before saving the ended series choice, the active date choice X is the only source with a row',
        $rcJRowsBefore === 1, (string) $rcJRowsBefore);
    fr_resolve($rcJCal, ['choiceID' => $rcJS, 'byUserId' => U6, 'seen' => fr_seen([$rcJK1])]);
    fr_ok('round-1 check tenth: re-saving the ALREADY-ENDED series choice (while X still decides the date) must add NO row',
        count(fr_appr($rcJK1)) === $rcJRowsBefore, 'before ' . $rcJRowsBefore . ', after ' . count(fr_appr($rcJK1)) . ' | ' . json_encode(fr_appr($rcJK1)));

    // -------------------------------------------------------------------------
    fr_heading('E12. Both clock-change nights, and the two midnight changes');
    // -------------------------------------------------------------------------
    $dayStart = fr_private('dayStartUtc');
    $moments  = fr_private('windowMoments');
    $active   = fr_private('windowActive');
    $utc      = new DateTimeZone('UTC');
    $santiago = fr_zone(SITE_ZONE_B);
    $n = fr_nights();
    $lf = $n['londonForward'];
    $lb = $n['londonBack'];
    $sf = $n['santiagoForward'];
    $sb = $n['santiagoBack'];
    $sundayF = $lf->setTimezone($london)->format('Y-m-d');
    $sundayB = $lb->setTimezone($london)->format('Y-m-d');
    $hours = static fn (array $w): float => ($w[1]->getTimestamp() - $w[0]->getTimestamp()) / 3600;
    fr_ok('London, clocks forward on ' . $sundayF . ': the day starts at 00:00 UTC',
        fr_text($dayStart->invoke(null, $sundayF, $london)) === $sundayF . ' 00:00:00', fr_text($dayStart->invoke(null, $sundayF, $london)));
    $saturdayB = (new DateTimeImmutable($sundayB, $utc))->modify('-1 day')->format('Y-m-d');
    fr_ok('London, clocks back on ' . $sundayB . ': the day starts at 23:00 UTC on the Saturday',
        fr_text($dayStart->invoke(null, $sundayB, $london)) === $saturdayB . ' 23:00:00', fr_text($dayStart->invoke(null, $sundayB, $london)));
    $wf = $moments->invoke(null, ['fromDate' => $sundayF, 'toDate' => $sundayF], $london);
    fr_ok('London, a window of the clocks-forward Sunday only: Sunday 00:00 to Sunday 23:00 UTC, 23 hours',
        fr_text($wf[0]) === $sundayF . ' 00:00:00' && fr_text($wf[1]) === $sundayF . ' 23:00:00' && $hours($wf) === 23.0, fr_text($wf[0]) . ' → ' . fr_text($wf[1]));
    $wb = $moments->invoke(null, ['fromDate' => $sundayB, 'toDate' => $sundayB], $london);
    $mondayB = (new DateTimeImmutable($sundayB, $utc))->modify('+1 day')->format('Y-m-d');
    fr_ok('London, a window of the clocks-back Sunday only: Saturday 23:00 to Monday 00:00 UTC, 25 hours',
        fr_text($wb[0]) === $saturdayB . ' 23:00:00' && fr_text($wb[1]) === $mondayB . ' 00:00:00' && $hours($wb) === 25.0, fr_text($wb[0]) . ' → ' . fr_text($wb[1]));
    $in1 = $active->invoke(null, ['fromDate' => $sundayB, 'toDate' => $sundayB], new DateTimeImmutable($sundayB . ' 00:30:00', $utc), $london);
    $in2 = $active->invoke(null, ['fromDate' => $sundayB, 'toDate' => $sundayB], new DateTimeImmutable($sundayB . ' 01:30:00', $utc), $london);
    fr_ok('the hour that happens twice (00:30 and 01:30 UTC both read 01:xx in London) is inside that Sunday\'s window both times', $in1 === true && $in2 === true);
    $sfSunday = $sf->setTimezone($santiago)->format('Y-m-d');
    $wsf = $moments->invoke(null, ['fromDate' => $sfSunday, 'toDate' => $sfSunday], $santiago);
    fr_ok('Santiago, clocks forward AT midnight on ' . $sfSunday . ': the day starts at the moment of the change (' . fr_text($sf) . '), and is 23 hours long',
        fr_text($dayStart->invoke(null, $sfSunday, $santiago)) === fr_text($sf) && $hours($wsf) === 23.0, fr_text($dayStart->invoke(null, $sfSunday, $santiago)) . ' ' . $hours($wsf));
    // In April the clocks go back at midnight: the hour BEFORE midnight
    // repeats, the Saturday is 25 hours long, and the Sunday's midnight
    // happens once, one hour after the change (challenge finding 7).
    $sbSunday   = $sb->modify('+1 hour')->setTimezone($santiago)->format('Y-m-d');
    $sbSaturday = (new DateTimeImmutable($sbSunday, $utc))->modify('-1 day')->format('Y-m-d');
    $wsb = $moments->invoke(null, ['fromDate' => $sbSaturday, 'toDate' => $sbSaturday], $santiago);
    fr_ok('Santiago, clocks back at midnight on ' . $sbSunday . ': that Sunday starts one hour after the change (' . fr_text($sb->modify('+1 hour')) . '), and the Saturday is exactly 25 hours',
        fr_text($dayStart->invoke(null, $sbSunday, $santiago)) === fr_text($sb->modify('+1 hour')) && $hours($wsb) === 25.0,
        fr_text($dayStart->invoke(null, $sbSunday, $santiago)) . ' ' . $hours($wsb));
    $tuesday = fr_todayLocal()->modify('next tuesday')->format('Y-m-d');
    while ((int) (new DateTimeImmutable($tuesday . ' 12:00:00', $london))->format('I') !== (int) (new DateTimeImmutable($tuesday . ' 12:00:00', $london))->modify('+1 day')->format('I')) {
        $tuesday = (new DateTimeImmutable($tuesday, $utc))->modify('+7 days')->format('Y-m-d');
    }
    fr_ok('KEEP-WORKING: an ordinary Tuesday\'s window (' . $tuesday . ') is exactly 24 hours',
        $hours($moments->invoke(null, ['fromDate' => $tuesday, 'toDate' => $tuesday], $london)) === 24.0);

    // End to end, through the real resolver: a hidden choice for London's
    // clocks-back Sunday on FP, and one for Santiago's clocks-forward Sunday
    // on FB.
    fr_resetWorld();
    $lx = fr_event(FP, 'e12-london@p7', 'London clock event');
    fr_choice(FP, 'series', 'e12-london@p7', 'hidden', ['from' => $sundayB, 'to' => $sundayB]);
    $bStart = new DateTimeImmutable($saturdayB . ' 23:00:00', $utc);
    fr_resolveAt($bStart->modify('-1 second'), FP, null, static function () use ($lx, $bStart): void {
        fr_ok('end to end, London: one second before Saturday 23:00 UTC → public, re-check at 23:00',
            fr_ls($lx) === 'public/calendar' && fr_ev($lx)['importRecheckAt'] === fr_text($bStart), json_encode(fr_ev($lx)));
    });
    fr_resolveAt(new DateTimeImmutable($sundayB . ' 01:30:00', $utc), FP, null, static function () use ($lx, $mondayB): void {
        fr_ok('end to end, London: in the repeated hour → hidden, source series, re-check at Monday 00:00 UTC',
            fr_ls($lx) === 'hidden/series' && fr_ev($lx)['importRecheckAt'] === $mondayB . ' 00:00:00', json_encode(fr_ev($lx)));
    });
    $sx = fr_event(FB, 'e12-santiago@p7', 'Santiago clock event');
    fr_choice(FB, 'series', 'e12-santiago@p7', 'hidden', ['from' => $sfSunday, 'to' => $sfSunday]);
    fr_resolveAt($sf->modify('-1 second'), FB, null, static function () use ($sx, $sf): void {
        fr_ok('end to end, Santiago: one second before the change → public, re-check at the change',
            fr_ls($sx) === 'public/calendar' && fr_ev($sx)['importRecheckAt'] === fr_text($sf), json_encode(fr_ev($sx)));
    });
    fr_resolveAt($sf, FB, null, static function () use ($sx): void {
        fr_ok('end to end, Santiago: at the change → hidden, source series', fr_ls($sx) === 'hidden/series', json_encode(fr_ev($sx)));
    });

    // -------------------------------------------------------------------------
    fr_heading('E13. Dates seen and not seen on the choice page (plan proof 13), and A25');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $c1 = fr_newFeed(ORG_A, 'members');
    $s = [];
    for ($i = 1; $i <= 4; $i++) {
        $s[$i] = fr_event($c1, 'e13@p7', 'Weekly gathering', ['key' => 'k' . $i, 'day' => 7 * $i]);
    }
    $choice = fr_choice($c1, 'series', 'e13@p7', 'public', ['detail' => 'basic']);
    $seen = fr_seen([$s[1], $s[2], $s[3]]);
    fr_q("UPDATE tblEvents SET eventName = 'Weekly gathering (cancelled)' WHERE eventID = ?", 'i', [$s[2]]); // changed while the page was open
    $r = fr_resolve($c1, ['choiceID' => $choice, 'byUserId' => U6, 'seen' => $seen]);
    foreach ([1, 3] as $i) {
        $a = fr_apprWith($s[$i], 'approved')[0] ?? null;
        fr_ok("D{$i} (shown, unchanged) → approved, reason choice, decided by the saver; public",
            $a !== null && $a['reason'] === 'choice' && (int) $a['decidedByID'] === U6 && fr_ls($s[$i]) === 'public/series', fr_ls($s[$i]) . ' ' . json_encode(fr_appr($s[$i])));
    }
    fr_ok('D2 (its title changed while the page was open) → waiting, content_changed; members, source waiting',
        (fr_apprWith($s[2], 'pending')[0]['reason'] ?? '') === 'content_changed' && fr_ls($s[2]) === 'members/waiting', fr_ls($s[2]) . ' ' . json_encode(fr_appr($s[2])));
    fr_ok('D4 (not on the page), the same as D1 → approved, series_match; public',
        (fr_apprWith($s[4], 'approved')[0]['reason'] ?? '') === 'series_match' && fr_ls($s[4]) === 'public/series', fr_ls($s[4]) . ' ' . json_encode(fr_appr($s[4])));
    $s5 = fr_event($c1, 'e13@p7', 'Weekly gathering', ['key' => 'k5', 'day' => 35]);
    $s6 = fr_event($c1, 'e13@p7', 'Weekly gathering (outdoors)', ['key' => 'k6', 'day' => 42]);
    $r = fr_resolve($c1);
    fr_ok('a date added later with the same title → approved, series_match; with a different title → waiting, new_match',
        (fr_apprWith($s5, 'approved')[0]['reason'] ?? '') === 'series_match' && fr_ls($s5) === 'public/series'
        && (fr_apprWith($s6, 'pending')[0]['reason'] ?? '') === 'new_match' && fr_ls($s6) === 'members/waiting',
        fr_ls($s5) . ' ' . fr_ls($s6));
    fr_ok('KEEP-WORKING: approvedBySave is 0 on an ordinary refresh', $r['approvedBySave'] === 0, json_encode($r));
    fr_q("UPDATE tblEvents SET eventName = 'Weekly gathering, renamed' WHERE eventID = ?", 'i', [$s[1]]);
    fr_resolve($c1);
    fr_ok('a later title change on a shown date → waiting, base (owner answer 2)', fr_ls($s[1]) === 'members/waiting', fr_ls($s[1]));
    fr_q("UPDATE tblExternalEventChoices SET detailLevel = 'full' WHERE choiceID = ?", 'i', [$choice]);
    fr_resolve($c1, ['choiceID' => $choice, 'byUserId' => U6, 'seen' => fr_seen([$s[1]])]);
    fr_ok('changing the choice (a new request) and saving it with the date shown → a new approved row, reason choice, automatically',
        fr_ls($s[1]) === 'public/series' && count(array_filter(fr_apprWith($s[1], 'approved'), static fn (array $a): bool => $a['reason'] === 'choice')) === 1,
        json_encode(fr_appr($s[1])));

    // With the choice at FULL detail, a later date with the same title but a
    // different location waits.
    $c2 = fr_newFeed(ORG_A, 'members');
    $f1 = fr_event($c2, 'e13-full@p7', 'Full gathering', ['key' => 'k1']);
    $fullChoice = fr_choice($c2, 'series', 'e13-full@p7', 'public', ['detail' => 'full']);
    fr_resolve($c2, ['choiceID' => $fullChoice, 'byUserId' => U6, 'seen' => fr_seen([$f1])]);
    $f5 = fr_event($c2, 'e13-full@p7', 'Full gathering', ['key' => 'k5', 'location' => 'Another building']);
    fr_resolve($c2);
    fr_ok('at full detail, a later date with the same title but a different location → waiting, new_match',
        (fr_apprWith($f5, 'pending')[0]['reason'] ?? '') === 'new_match' && fr_ls($f5) === 'members/waiting', fr_ls($f5) . ' ' . json_encode(fr_appr($f5)));

    // A25: a waiting date and a declined date, shown on the page and saved.
    $c3 = fr_newFeed(ORG_A, 'members');
    $a1 = fr_event($c3, 'e13-a25@p7', 'Choir practice', ['key' => 'k1']);
    $a6 = fr_event($c3, 'e13-a25@p7', 'Choir practice (special)', ['key' => 'k6', 'day' => 14]);
    $a7 = fr_event($c3, 'e13-a25@p7', 'Choir practice (joint)', ['key' => 'k7', 'day' => 21]);
    $a25 = fr_choice($c3, 'series', 'e13-a25@p7', 'public');
    fr_resolve($c3, ['choiceID' => $a25, 'byUserId' => U6, 'seen' => fr_seen([$a1])]);
    fr_decide((int) fr_apprWith($a7, 'pending')[0]['approvalID'], 'declined', ORG_A, U8);
    fr_resolve($c3);
    fr_ok('set-up: D6 waiting and D7 declined', fr_ls($a6) === 'members/waiting' && count(fr_apprWith($a7, 'declined')) === 1, fr_ls($a6) . ' ' . fr_ls($a7));
    $r = fr_resolve($c3, ['choiceID' => $a25, 'byUserId' => U6, 'seen' => fr_seen([$a1, $a6, $a7])]);
    $a6row = fr_apprWith($a6, 'approved')[0] ?? null;
    fr_ok('A25: D6, waiting and shown → its waiting row becomes approved, decided by the saver; public',
        $a6row !== null && (int) $a6row['decidedByID'] === U6 && count(fr_appr($a6)) === 1 && fr_ls($a6) === 'public/series', json_encode(fr_appr($a6)));
    fr_ok('A25: D7, declined and shown → the declined row superseded, a new approved row reason choice; public',
        count(fr_apprWith($a7, 'superseded')) === 1 && (fr_apprWith($a7, 'approved')[0]['reason'] ?? '') === 'choice' && fr_ls($a7) === 'public/series',
        json_encode(fr_appr($a7)));
    fr_ok('A25: approvedBySave = 2, so the page can say how many waiting or declined dates are now shown', $r['approvedBySave'] === 2, json_encode($r));

    // A location change while the page was open: at BASIC detail the date
    // follows its identical-looking twin in the same save; at FULL it waits.
    foreach (['basic' => 'approved', 'full' => 'pending'] as $detail => $expect) {
        $c = fr_newFeed(ORG_A, 'members');
        $b1 = fr_event($c, 'e13-loc-' . $detail . '@p7', 'Loc gathering', ['key' => 'k1']);
        $b2 = fr_event($c, 'e13-loc-' . $detail . '@p7', 'Loc gathering', ['key' => 'k2', 'day' => 14]);
        $lc = fr_choice($c, 'series', 'e13-loc-' . $detail . '@p7', 'public', ['detail' => $detail]);
        $seen = fr_seen([$b1, $b2]);
        fr_q("UPDATE tblEvents SET locationName = 'Moved to the annexe' WHERE eventID = ?", 'i', [$b2]);
        fr_resolve($c, ['choiceID' => $lc, 'byUserId' => U6, 'seen' => $seen]);
        $rows = fr_appr($b2);
        $want = ($expect === 'approved')
            ? (count($rows) === 1 && $rows[0]['status'] === 'approved' && $rows[0]['reason'] === 'series_match')
            : (count($rows) === 1 && $rows[0]['status'] === 'pending' && $rows[0]['reason'] === 'content_changed');
        fr_ok(($detail === 'basic' ? '' : 'KEEP-WORKING: ') . 'at ' . $detail . ' detail, D2\'s location changed while the page was open → '
            . ($expect === 'approved' ? 'approved, series_match, in the same save (its visible content matches D1\'s)' : 'waiting, content_changed'),
            $want, json_encode($rows));
    }

    // -------------------------------------------------------------------------
    // Round-1 check, finding d: "seen" must be checked against the choice
    // that actually DECIDES this date, not any choice that was saved
    // (:1189). k3 has its own date choice X (public + website); saving the
    // SERIES choice S with k3 shown must not approve X's request.
    // -------------------------------------------------------------------------
    $rcDCal = fr_newFeed(ORG_A, 'members');
    $rcDK1  = fr_event($rcDCal, 'rc-d@p7', 'Round-1 check d coffee', ['key' => 'k1']);
    $rcDK3  = fr_event($rcDCal, 'rc-d@p7', 'Round-1 check d coffee', ['key' => 'k3', 'day' => 21]);
    fr_choice($rcDCal, 'date', 'rc-d@p7', 'public', ['key' => 'k3', 'website' => 1]);
    $rcDS   = fr_choice($rcDCal, 'series', 'rc-d@p7', 'public');
    fr_resolve($rcDCal);
    fr_ok('round-1 check d, KEEP-WORKING: before saving S, k3 is waiting on X\'s own request (public + website)',
        fr_ls($rcDK3) === 'members/waiting', fr_ls($rcDK3));
    $rcDRes = fr_resolve($rcDCal, ['choiceID' => $rcDS, 'byUserId' => U6, 'seen' => fr_seen([$rcDK1, $rcDK3])]);
    fr_ok('round-1 check d: saving S (which shows k3 but does not decide it — X does) must NOT approve X\'s public+website request',
        fr_ls($rcDK3) === 'members/waiting' && (int) fr_ev($rcDK3)['importWebsite'] === 0,
        fr_ls($rcDK3) . ' ' . json_encode(fr_appr($rcDK3)) . ' approvedBySave=' . $rcDRes['approvedBySave']);

    // -------------------------------------------------------------------------
    fr_heading('E14. The calendar\'s address changed (plan proof 14, A16)');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $ac = fr_newFeed(ORG_A, 'members');
    $l1 = fr_event($ac, 'e14@p7', 'Address gathering', ['key' => 'k1']);
    $l2 = fr_event($ac, 'e14@p7', 'Address gathering', ['key' => 'k2', 'day' => 14]);
    $gone = fr_event($ac, 'e14@p7', 'Address gathering', ['key' => 'k0', 'day' => 3]);
    $l4 = fr_event($ac, 'e14@p7', 'Address gathering (evening)', ['key' => 'k4', 'day' => 28]);
    $l3 = fr_event($ac, 'e14-other@p7', 'Address other');
    $ar = fr_rule($ac, 'public', [['title', 'contains', 'address']], ['detail' => 'basic']);
    fr_resolve($ac);
    foreach ([$l1, $l2, $gone, $l3] as $id) {
        fr_decide((int) fr_apprWith($id, 'pending')[0]['approvalID'], 'approved');
    }
    fr_decide((int) fr_apprWith($l4, 'pending')[0]['approvalID'], 'declined');
    // Every approval so far becomes OLD history: the rule's detail changes (a
    // new request), so each event gets a new waiting row and its old approved
    // row stays approved but is no longer current. The other event (l3) is
    // left like that — an old request, on a LIVE event.
    $l3Old = (int) fr_apprWith($l3, 'approved')[0]['approvalID'];
    fr_q("UPDATE tblExternalFeedRules SET detailLevel = 'full' WHERE ruleID = ?", 'i', [$ar]);
    fr_resolve($ac);
    // The three same-series dates are approved again under the NEW request,
    // so their approvals are current. Then one of them is removed at the
    // source (soft-deleted): its approval is current but its event is not
    // live, so only the "live" test can leave it out.
    foreach ([$l1, $l2, $gone] as $id) {
        fr_decide((int) fr_apprWith($id, 'pending')[0]['approvalID'], 'approved');
    }
    fr_resolve($ac);
    fr_q('UPDATE tblEvents SET isDeleted = 1 WHERE eventID = ?', 'i', [$gone]);
    $beforeApproved = fr_q("SELECT approvalID, decidedByID, decidedAt FROM tblExternalEventApprovals WHERE feedID = ? AND status = 'approved' ORDER BY approvalID", 'i', [$ac]);
    $liveCurrent = 2; // l1 and l2 under the current request
    global $mysqli;
    $mysqli->begin_transaction();
    fr_q('SELECT feedID FROM tblExternalFeeds WHERE feedID = ? FOR UPDATE', 'i', [$ac]);
    $copies = FeedResolver::onAddressChanged($mysqli, $ac, FeedResolver::databaseNowUtc($mysqli));
    $mysqli->commit();
    $pendingCopies = fr_q("SELECT eventID FROM tblExternalEventApprovals WHERE feedID = ? AND reason = 'address_changed' AND status = 'pending' ORDER BY eventID", 'i', [$ac]);
    fr_ok('KEEP-WORKING: the count equals the live, current-request approved rows (' . $liveCurrent . ')', $copies === $liveCurrent, (string) $copies);
    fr_ok('only those are copied, one waiting row each with reason address_changed (not the removed date\'s, not the old request\'s)',
        array_map('intval', array_column($pendingCopies, 'eventID')) === [$l1, $l2], json_encode($pendingCopies));
    $afterRows = fr_q("SELECT approvalID, status, decidedByID, decidedAt FROM tblExternalEventApprovals WHERE approvalID IN ("
        . implode(',', array_map('intval', array_column($beforeApproved, 'approvalID'))) . ') ORDER BY approvalID');
    $intact = count($afterRows) === count($beforeApproved);
    foreach ($afterRows as $i => $row) {
        $intact = $intact && $row['status'] === 'superseded' && (int) $row['decidedByID'] === (int) $beforeApproved[$i]['decidedByID']
            && $row['decidedAt'] === $beforeApproved[$i]['decidedAt'];
    }
    fr_ok('every approved row (' . count($beforeApproved) . ', the removed date\'s and the old request\'s included) is superseded, with who decided and when intact',
        $intact === true && in_array($l3Old, array_map('intval', array_column($afterRows, 'approvalID')), true), json_encode($afterRows));
    fr_ok('every declined row is superseded', fr_apprWith($l4, 'declined') === [] && count(fr_apprWith($l4, 'superseded')) >= 1, json_encode(fr_appr($l4)));
    fr_resolve($ac);
    fr_ok('the widened events drop to the base on the next resolve (members, waiting)',
        fr_ls($l1) === 'members/waiting' && fr_ls($l2) === 'members/waiting' && fr_ls($l4) === 'members/waiting', fr_ls($l1) . ' ' . fr_ls($l2) . ' ' . fr_ls($l4));
    fr_ok('...and no date followed a decision made before the change (no series_match row)',
        (int) fr_one("SELECT COUNT(*) AS n FROM tblExternalEventApprovals WHERE feedID = ? AND reason = 'series_match'", 'i', [$ac])['n'] === 0);

    // -------------------------------------------------------------------------
    fr_heading('E15. The rule preview writes nothing, and agrees with the real thing (plan proof 15)');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $pc = fr_newFeed(ORG_A, 'members');
    $open  = fr_event($pc, 'e15-a@p7', 'Open Day', ['tags' => ['outreach']]);
    fr_event($pc, 'e15-b@p7', 'Open Day planning', ['tags' => ['outreach']]);
    fr_event($pc, 'e15-c@p7', 'Open Day', ['tags' => ['other']]);
    $d12b = [
        'ruleID' => 0, 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'websiteOptIn' => 0, 'apiOptOut' => 0,
        'conditions' => [
            ['isException' => 0, 'matchField' => 'category', 'matchType' => 'equals', 'matchValue' => 'Outreach'],
            ['isException' => 0, 'matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'Open'],
            ['isException' => 1, 'matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'planning'],
        ],
    ];
    fr_resolve($pc);
    $fingerprint = static function (int $feedId): string {
        $parts = [];
        $parts[] = fr_q('SELECT eventID, importLevel, importDetail, importWebsite, importApiOptOut, importAudienceType, importAudienceID, importSource, '
            . 'importSourceID, importRecheckAt, categoryID FROM tblEvents WHERE externalFeedID = ? ORDER BY eventID', 'i', [$feedId]);
        $parts[] = fr_q('SELECT * FROM tblExternalEventApprovals WHERE feedID = ? ORDER BY approvalID', 'i', [$feedId]);
        $parts[] = fr_q('SELECT choiceID, siteID, feedID, scope, HEX(externalUidHash) AS h, externalRecurrenceKey, audienceLevel, detailLevel, websiteOptIn, '
            . 'apiOptOut, fromDate, toDate, overridesPrivateMark, note, createdByID, createdAt, updatedByID, updatedAt FROM tblExternalEventChoices WHERE feedID = ? ORDER BY choiceID', 'i', [$feedId]);
        $parts[] = fr_q('SELECT * FROM tblExternalFeedRules WHERE feedID = ? ORDER BY ruleID', 'i', [$feedId]);
        $parts[] = fr_q('SELECT c.* FROM tblExternalRuleConditions c JOIN tblExternalFeedRules r ON r.ruleID = c.ruleID WHERE r.feedID = ? ORDER BY c.conditionID', 'i', [$feedId]);
        $parts[] = fr_q('SELECT * FROM tblExternalAudienceMembers WHERE feedID = ? ORDER BY audienceMemberID', 'i', [$feedId]);

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    };
    $before = $fingerprint($pc);
    $lines = FeedResolver::previewRule($mysqli, $pc, $d12b, FeedResolver::databaseNowUtc($mysqli));
    $after = $fingerprint($pc);
    fr_ok('the preview of the D12b rule lists exactly "Open Day", waits_for_approval',
        count($lines) === 1 && (int) $lines[0]['eventID'] === $open && $lines[0]['outcome'] === 'waits_for_approval', json_encode($lines));
    fr_ok('...and writes nothing: a fingerprint of every column of every row of the six #514 tables for this calendar is identical', $before === $after, $before . ' vs ' . $after);

    // Six drafts, one per outcome (round-2 check gap 3 adds the sixth); each
    // then saved for real and resolved. This used to be an associative
    // array keyed by outcome, which could only ever hold ONE draft per
    // outcome string — the sixth draft below shares its outcome
    // ('applies_now') with the first, so the table is now a plain LIST of
    // [outcome, eventId, draft, stored] instead.
    $agree = fr_newFeed(ORG_A, 'members');
    $ev1 = fr_event($agree, 'e15-1@p7', 'Agree applies');
    $ev2 = fr_event($agree, 'e15-2@p7', 'Agree waits');
    $ev3 = fr_event($agree, 'e15-3@p7', 'Agree chosen');
    fr_choice($agree, 'series', 'e15-3@p7', 'hidden');
    $ev4 = fr_event($agree, 'e15-4@p7', 'Agree twice', ['duplicate' => 1]);
    $ev5 = fr_event($agree, 'e15-5@p7', 'Agree declined');
    $declinedRule = fr_rule($agree, 'public', [['title', 'contains', 'agree declined']]);
    // Round-2 check gap 3: a DUPLICATE event whose draft NARROWS the
    // calendar's own 'members' setting to 'hidden'. `plan()` applies a
    // narrowing candidate on a duplicate at once (FIX B), so the real save
    // stores 'hidden/rule' — but reverting the ":310" state check
    // (`&& $why['state'] !== 'applied'`) makes the preview call this
    // 'duplicate_not_applied' regardless, disagreeing with what saving it
    // for real actually does. This is exactly the disagreement the class
    // header promises can never happen.
    $ev6 = fr_event($agree, 'e15-6@p7', 'Agree hidden duplicate', ['duplicate' => 1]);
    fr_resolve($agree);
    fr_decide((int) fr_apprWith($ev5, 'pending')[0]['approvalID'], 'declined');
    fr_resolve($agree);
    $drafts = [
        ['applies_now', $ev1, ['ruleID' => 0, 'audienceLevel' => 'members', 'detailLevel' => 'basic', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'agree applies']]], 'members/rule'],
        ['waits_for_approval', $ev2, ['ruleID' => 0, 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'agree waits']]], 'members/waiting'],
        ['covered_by_choice', $ev3, ['ruleID' => 0, 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'agree chosen']]], 'hidden/series'],
        ['duplicate_not_applied', $ev4, ['ruleID' => 0, 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'agree twice']]], 'members/duplicate'],
        ['declined_before', $ev5, ['ruleID' => $declinedRule, 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'agree declined']]], 'members/calendar'],
        ['applies_now', $ev6, ['ruleID' => 0, 'audienceLevel' => 'hidden', 'detailLevel' => 'basic', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'agree hidden duplicate']]], 'hidden/rule'],
    ];
    foreach ($drafts as [$outcome, $eventId, $draft, $stored]) {
        $lines = FeedResolver::previewRule($mysqli, $agree, $draft, FeedResolver::databaseNowUtc($mysqli));
        $mine = array_values(array_filter($lines, static fn (array $l): bool => (int) $l['eventID'] === $eventId));
        if ((int) $draft['ruleID'] === 0) {
            $cond = $draft['conditions'][0];
            fr_rule($agree, (string) $draft['audienceLevel'], [[$cond['matchField'], $cond['matchType'], $cond['matchValue']]], ['detail' => (string) $draft['detailLevel']]);
        }
        fr_resolve($agree);
        fr_ok('preview says ' . $outcome . ' for event ' . $eventId . ', and the rule saved for real and resolved stores ' . $stored,
            count($mine) === 1 && $mine[0]['outcome'] === $outcome && fr_ls($eventId) === $stored, json_encode($mine) . ' stored ' . fr_ls($eventId));
    }

    // -------------------------------------------------------------------------
    fr_heading('E16. Duplicates (plan proof 16)');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $dupFeed = fr_newFeed(ORG_A, 'members');
    $dx = fr_event($dupFeed, 'e16@p7', 'Duplicate event', ['duplicate' => 1]);
    fr_rule($dupFeed, 'public', [['title', 'contains', 'duplicate']], ['apiOptOut' => 1]);
    fr_resolve($dupFeed);
    $ev = fr_ev($dx);
    fr_ok('a duplicate with a matching widening rule → the base, source duplicate, no approval rows',
        fr_ls($dx) === 'members/duplicate' && fr_appr($dx) === [], json_encode($ev));
    fr_ok('KEEP-WORKING: the matching rule\'s "Don\'t show via API" box still counts (importApiOptOut = 1)', (int) $ev['importApiOptOut'] === 1, json_encode($ev));

    // -------------------------------------------------------------------------
    // FIX B (round-1 check, 24 September 2026): for a duplicate, work out the
    // candidate as usual (:1124) — apply it when it is NOT wider (narrowing
    // must always win, D11); ignore it, keeping the base, only when it would
    // widen (a duplicate never gets an approval row). Before this fix every
    // duplicate reached the base regardless of direction, so an
    // administrator's own hide could be undone by the source listing the
    // same date twice.
    // -------------------------------------------------------------------------
    $fixBCal1 = fr_newFeed(ORG_A, 'public'); // base 'public', so a 'hidden' choice narrows
    $fixBEv1  = fr_event($fixBCal1, 'fixb-choice@p7', 'Fix B narrowed by choice', ['key' => 'k1']);
    fr_choice($fixBCal1, 'date', 'fixb-choice@p7', 'hidden', ['key' => 'k1']);
    fr_resolve($fixBCal1);
    fr_ok('FIX B, KEEP-WORKING: a hiding date choice narrows an ORDINARY (non-duplicate) date → applied at once, hidden',
        fr_ls($fixBEv1) === 'hidden/date', fr_ls($fixBEv1));
    fr_q('UPDATE tblEvents SET externalDuplicate = 1 WHERE eventID = ?', 'i', [$fixBEv1]);
    fr_resolve($fixBCal1);
    fr_ok('FIX B: the SAME hiding choice, once the source lists the date twice → STILL hidden (narrowing always wins, even for a duplicate)',
        fr_ls($fixBEv1) === 'hidden/date', fr_ls($fixBEv1));

    $fixBCal2 = fr_newFeed(ORG_A, 'public');
    $fixBEv2  = fr_event($fixBCal2, 'fixb-rule@p7', 'Fix B narrowed by rule');
    fr_rule($fixBCal2, 'hidden', [['title', 'contains', 'fix b narrowed by rule']]);
    fr_resolve($fixBCal2);
    fr_ok('FIX B, KEEP-WORKING: a hiding rule narrows an ORDINARY (non-duplicate) event → applied at once, hidden',
        fr_ls($fixBEv2) === 'hidden/rule', fr_ls($fixBEv2));
    fr_q('UPDATE tblEvents SET externalDuplicate = 1 WHERE eventID = ?', 'i', [$fixBEv2]);
    fr_resolve($fixBCal2);
    fr_ok('FIX B: the SAME hiding rule, once the source lists the event twice → STILL hidden',
        fr_ls($fixBEv2) === 'hidden/rule', fr_ls($fixBEv2));

    $fixBCal3     = fr_newFeed(ORG_A, 'members');
    $fixBWideDup  = fr_event($fixBCal3, 'fixb-wide-dup@p7', 'Fix B widened dup', ['key' => 'k1', 'duplicate' => 1]);
    $fixBWideTwin = fr_event($fixBCal3, 'fixb-wide-twin@p7', 'Fix B widened twin', ['key' => 'k1']);
    fr_choice($fixBCal3, 'date', 'fixb-wide-dup@p7', 'public', ['key' => 'k1']);
    fr_choice($fixBCal3, 'date', 'fixb-wide-twin@p7', 'public', ['key' => 'k1']);
    fr_resolve($fixBCal3);
    fr_ok('FIX B: a WIDENING choice on a duplicate → still ignored, the base stands (source duplicate), no approval row',
        fr_ls($fixBWideDup) === 'members/duplicate' && fr_appr($fixBWideDup) === [], fr_ls($fixBWideDup) . ' ' . json_encode(fr_appr($fixBWideDup)));
    fr_ok('FIX B, KEEP-WORKING: the SAME widening choice on the NON-duplicate twin → waits for approval as normal (the duplicate branch, not the choice, is what suppressed it)',
        fr_ls($fixBWideTwin) === 'members/waiting' && count(fr_apprWith($fixBWideTwin, 'pending')) === 1, fr_ls($fixBWideTwin) . ' ' . json_encode(fr_appr($fixBWideTwin)));

    // -------------------------------------------------------------------------
    // Round-2 check gap 2 (24 September 2026): the two FIX B widening proofs
    // above judge a duplicate by LEVEL only (members → public). `isWider()`
    // also widens on the website box alone (same level) and on a groups list
    // gaining a member (same level), and neither of those routes had a proof
    // for a duplicate — a fault that broke ONLY the duplicate branch for
    // those two routes, leaving the level route caught, would have passed
    // every proof committed so far.
    // -------------------------------------------------------------------------
    $fixBCal4    = fr_newFeed(ORG_A, 'public'); // base 'public', website box CLEAR
    $fixBWebDup  = fr_event($fixBCal4, 'fixb-web-dup@p7', 'Fix B website dup', ['key' => 'k1', 'duplicate' => 1]);
    $fixBWebTwin = fr_event($fixBCal4, 'fixb-web-twin@p7', 'Fix B website twin', ['key' => 'k1']);
    fr_choice($fixBCal4, 'date', 'fixb-web-dup@p7', 'public', ['key' => 'k1', 'website' => 1]);
    fr_choice($fixBCal4, 'date', 'fixb-web-twin@p7', 'public', ['key' => 'k1', 'website' => 1]);
    fr_resolve($fixBCal4);
    fr_ok('round-2 check gap 2 (FIX B): a WEBSITE-BOX-ONLY widening choice on a duplicate (same level, only the box changes) → still ignored, the base stands (source duplicate), website box off, no approval row',
        fr_ls($fixBWebDup) === 'public/duplicate' && (int) fr_ev($fixBWebDup)['importWebsite'] === 0 && fr_appr($fixBWebDup) === [],
        fr_ls($fixBWebDup) . ' website=' . (fr_ev($fixBWebDup)['importWebsite'] ?? 'null') . ' ' . json_encode(fr_appr($fixBWebDup)));
    fr_ok('round-2 check gap 2, KEEP-WORKING: the SAME website-box widening choice on the NON-duplicate twin → waits for approval as normal',
        fr_ls($fixBWebTwin) === 'public/waiting' && count(fr_apprWith($fixBWebTwin, 'pending')) === 1,
        fr_ls($fixBWebTwin) . ' ' . json_encode(fr_apprWith($fixBWebTwin, 'pending')));

    $fixBCal5     = fr_newFeed(ORG_A, 'groups');
    fr_list($fixBCal5, 'feed', $fixBCal5, [['small_group', G1]]); // the calendar's own list: {G1}
    $fixBGrpDup   = fr_event($fixBCal5, 'fixb-grp-dup@p7', 'Fix B groups dup', ['key' => 'k1', 'duplicate' => 1]);
    $fixBGrpTwin  = fr_event($fixBCal5, 'fixb-grp-twin@p7', 'Fix B groups twin', ['key' => 'k1']);
    $fixBGrpCDup  = fr_choice($fixBCal5, 'date', 'fixb-grp-dup@p7', 'groups', ['key' => 'k1']);
    fr_list($fixBCal5, 'choice', $fixBGrpCDup, [['small_group', G1], ['small_group', G2]]); // widens {G1} to {G1,G2}
    $fixBGrpCTwin = fr_choice($fixBCal5, 'date', 'fixb-grp-twin@p7', 'groups', ['key' => 'k1']);
    fr_list($fixBCal5, 'choice', $fixBGrpCTwin, [['small_group', G1], ['small_group', G2]]);
    fr_resolve($fixBCal5);
    // The two audience checks on the end were added after round 3 of the independent
    // check. Without them this test passed on a planted fault that kept the calendar's
    // own level and source but pointed the stored answer at the CHOICE's group list —
    // which lets a member of G2 alone see the duplicate. Checking the level and source
    // alone cannot tell those two apart, because both lists sit at level "groups".
    fr_ok('round-2 check gap 2 (FIX B): a GROUP-LIST widening choice on a duplicate (same level "groups", only the list grows) → still ignored, the calendar\'s OWN list {G1} stands (the stored answer points at the calendar\'s list, not the choice\'s), no approval row',
        fr_ls($fixBGrpDup) === 'groups/duplicate' && fr_appr($fixBGrpDup) === []
            && fr_ev($fixBGrpDup)['importAudienceType'] === 'feed' && (int) fr_ev($fixBGrpDup)['importAudienceID'] === $fixBCal5,
        fr_ls($fixBGrpDup) . ' audience=' . (fr_ev($fixBGrpDup)['importAudienceType'] ?? 'null') . '/' . (fr_ev($fixBGrpDup)['importAudienceID'] ?? 'null') . ' ' . json_encode(fr_appr($fixBGrpDup)));
    fr_ok('round-2 check gap 2, KEEP-WORKING: the SAME group-list widening choice on the NON-duplicate twin → waits for approval as normal',
        fr_ls($fixBGrpTwin) === 'groups/waiting' && count(fr_apprWith($fixBGrpTwin, 'pending')) === 1,
        fr_ls($fixBGrpTwin) . ' ' . json_encode(fr_apprWith($fixBGrpTwin, 'pending')));

    // -------------------------------------------------------------------------
    fr_heading('E21. The sibling decision (plan proof 18), whatever order the dates are read in');
    // -------------------------------------------------------------------------
    // Build a weekly "Youth night" (three dates) and a one-off "Youth quiz",
    // matched by one widening rule, with event numbers RISING with the dates
    // or FALLING. The loader reads by event number, so the two builds read
    // the dates in opposite orders.
    $build = static function (bool $rising): array {
        $cal  = fr_newFeed(ORG_A, 'members');
        $base = $GLOBALS['fr_nextId']['event'];
        $GLOBALS['fr_nextId']['event'] += 10;
        $w = [];
        for ($i = 1; $i <= 3; $i++) {
            $w[$i] = fr_event($cal, 'youth-night-' . $cal . '@p7', 'Youth night', ['key' => 'k' . $i, 'day' => 7 * $i, 'id' => $rising ? $base + $i : $base + 4 - $i]);
        }
        $quiz = fr_event($cal, 'youth-quiz-' . $cal . '@p7', 'Youth quiz', ['id' => $base + 8]);
        fr_rule($cal, 'public', [['title', 'contains', 'youth']], ['detail' => 'basic']);

        return [$cal, $w, $quiz];
    };
    $summary = static function (array $w): array {
        $out = [];
        foreach ($w as $i => $id) {
            $rows = array_map(static fn (array $r): string => $r['status'] . ':' . $r['reason'], fr_appr($id));
            sort($rows);
            $out['W' . $i] = fr_ls($id) . ' ' . implode(',', $rows);
        }

        return $out;
    };
    $orders = [];
    foreach (['rising' => true, 'falling' => false] as $name => $rising) {
        fr_resetWorld();
        [$cal, $w, $quiz] = $build($rising);
        $r1 = fr_resolve($cal);
        $pendingW = 0;
        foreach ($w as $id) {
            $pendingW += count(fr_apprWith($id, 'pending'));
        }
        fr_ok($name . ': first resolve → three waiting rows for "Youth night" (and one for the quiz), newPending 4',
            $pendingW === 3 && $r1['newPending'] === 4, json_encode($r1));
        $w1Row = fr_apprWith($w[1], 'pending')[0];
        fr_decide((int) $w1Row['approvalID'], 'approved');
        $r2 = fr_resolve($cal);
        $ok = true;
        foreach ([2, 3] as $i) {
            $a = fr_apprWith($w[$i], 'approved')[0] ?? null;
            $ok = $ok && $a !== null && $a['reason'] === 'new_match'
                && str_contains((string) $a['decisionNote'], '(approval #' . (int) $w1Row['approvalID'] . ')') && fr_ls($w[$i]) === 'public/rule';
        }
        fr_ok($name . ': W1 approved → W2 and W3 approved, their notes naming W1\'s approval, reason still new_match; all three public; newPending 0',
            $ok && fr_ls($w[1]) === 'public/rule' && $r2['newPending'] === 0, json_encode($summary($w)) . ' ' . json_encode($r2));
        $orders[$name] = $summary($w);
        fr_ok($name . ': KEEP-WORKING: the one-off "Youth quiz" is untouched (still waiting)', fr_ls($quiz) === 'members/waiting', fr_ls($quiz));

        // A decision made in THIS resolve, read in either order: a series
        // choice saved with only the first date shown; the other two are
        // identical and not shown.
        $cal2 = fr_newFeed(ORG_A, 'members');
        $base = $GLOBALS['fr_nextId']['event'];
        $GLOBALS['fr_nextId']['event'] += 10;
        $v = [];
        for ($i = 1; $i <= 3; $i++) {
            $v[$i] = fr_event($cal2, 'youth-choice-' . $cal2 . '@p7', 'Youth camp', ['key' => 'k' . $i, 'day' => 7 * $i, 'id' => $rising ? $base + $i : $base + 4 - $i]);
        }
        $vc = fr_choice($cal2, 'series', 'youth-choice-' . $cal2 . '@p7', 'public');
        fr_resolve($cal2, ['choiceID' => $vc, 'byUserId' => U6, 'seen' => fr_seen([$v[1]])]);
        $orders[$name . '-sameResolve'] = $summary($v);
    }
    fr_ok('reading order does not matter: the rising and falling builds store identical results (' . json_encode($orders['rising']) . ')',
        $orders['rising'] === $orders['falling'], json_encode($orders));
    fr_ok('...including a decision made in the same resolve (the shown date approved, its two unseen twins follow it)',
        $orders['rising-sameResolve'] === $orders['falling-sameResolve']
        && str_contains(json_encode($orders['rising-sameResolve']['W3']), 'series_match'), json_encode([$orders['rising-sameResolve'], $orders['falling-sameResolve']]));

    fr_resetWorld();
    [$cal, $w, $quiz] = $build(true);
    fr_resolve($cal);
    fr_decide((int) fr_apprWith($w[1], 'pending')[0]['approvalID'], 'approved');
    fr_resolve($cal);
    $w[4] = fr_event($cal, 'youth-night-' . $cal . '@p7', 'Youth night', ['key' => 'k4', 'day' => 28]);
    fr_resolveAt(fr_nowUtc()->modify('+7 days'), $cal, null, static function (array $r) use ($w): void {
        fr_ok('a week on, a new date W4 enters → approved, series_match; public; newPending 0',
            (fr_apprWith($w[4], 'approved')[0]['reason'] ?? '') === 'series_match' && fr_ls($w[4]) === 'public/rule' && $r['newPending'] === 0,
            fr_ls($w[4]) . ' ' . json_encode($r));
    });
    fr_q('UPDATE tblEvents SET isDeleted = 1 WHERE eventID IN (?, ?, ?, ?)', 'iiii', [$w[1], $w[2], $w[3], $w[4]]);
    $moved = [];
    for ($i = 1; $i <= 4; $i++) {
        $moved[$i] = fr_event($cal, 'youth-night-' . $cal . '@p7', 'Youth night', ['key' => 'm' . $i, 'day' => 7 * $i, 'clock' => '20:00:00']);
    }
    $r = fr_resolve($cal);
    $allFollow = true;
    foreach ($moved as $id) {
        $allFollow = $allFollow && (fr_apprWith($id, 'approved')[0]['reason'] ?? '') === 'series_match' && fr_ls($id) === 'public/rule';
    }
    fr_ok('the whole series moved an hour at the source (every key changes; the old rows removed) → every new date approved, series_match; public; newPending 0',
        $allFollow && $r['newPending'] === 0, json_encode($summary($moved)) . ' ' . json_encode($r));
    fr_q("UPDATE tblEvents SET eventName = 'Youth night (at the church)' WHERE eventID = ?", 'i', [$moved[2]]);
    $r = fr_resolve($cal);
    fr_ok('one date renamed at the source → its approved row superseded, a waiting row content_changed; members; newPending 1',
        count(fr_apprWith($moved[2], 'superseded')) === 1 && (fr_apprWith($moved[2], 'pending')[0]['reason'] ?? '') === 'content_changed'
        && fr_ls($moved[2]) === 'members/waiting' && $r['newPending'] === 1, json_encode(fr_appr($moved[2])) . ' ' . json_encode($r));
    fr_ok('KEEP-WORKING: the one-off "Youth quiz" was never affected by the decisions on "Youth night"',
        fr_ls($quiz) === 'members/waiting' && fr_apprWith($quiz, 'approved') === [], fr_ls($quiz));

    fr_resetWorld();
    [$cal, $w, $quiz] = $build(true);
    fr_resolve($cal);
    fr_decide((int) fr_apprWith($w[1], 'pending')[0]['approvalID'], 'declined');
    $r = fr_resolve($cal);
    $w4 = fr_event($cal, 'youth-night-' . $cal . '@p7', 'Youth night', ['key' => 'k4', 'day' => 28]);
    $r4 = fr_resolve($cal);
    fr_ok('declined instead: W2 and W3 become declined; W4 arriving later gets a declined row, series_match; all members; newPending 0',
        count(fr_apprWith($w[2], 'declined')) === 1 && count(fr_apprWith($w[3], 'declined')) === 1
        && (fr_apprWith($w4, 'declined')[0]['reason'] ?? '') === 'series_match'
        && fr_ls($w[1]) === 'members/calendar' && fr_ls($w[2]) === 'members/calendar' && fr_ls($w4) === 'members/calendar'
        && $r['newPending'] === 0 && $r4['newPending'] === 0, json_encode($summary([$w[1], $w[2], $w[3], $w4])));

    fr_resetWorld();
    [$cal, $w, $quiz] = $build(true);
    fr_resolve($cal);
    fr_q("UPDATE tblExternalEventApprovals SET status = 'approved', decidedByID = ?, decidedAt = ? WHERE eventID = ? AND status = 'pending'",
        'isi', [U6, fr_text(fr_nowUtc()->modify('-2 hours')), $w[1]]);
    fr_q("UPDATE tblExternalEventApprovals SET status = 'declined', decidedByID = ?, decidedAt = ? WHERE eventID = ? AND status = 'pending'",
        'isi', [U8, fr_text(fr_nowUtc()->modify('-1 hour')), $w[2]]);
    fr_resolve($cal);
    fr_ok('the newest decision wins: W1 approved two hours ago, W2 declined one hour ago → W3 declined; W1 public, W2 and W3 members',
        count(fr_apprWith($w[3], 'declined')) === 1 && fr_ls($w[1]) === 'public/rule' && fr_ls($w[2]) === 'members/calendar'
        && fr_ls($w[3]) === 'members/calendar', json_encode($summary($w)));

    // -------------------------------------------------------------------------
    // Round-1 check, finding b: the pass-2 sibling bucket must include the
    // REQUEST (:1284, :1296) — otherwise ticking a rule's website box (a
    // NEW request) lets two already-approved dates follow their OLD
    // request's approval straight onto the website, with no new approval.
    // -------------------------------------------------------------------------
    $rcBCal  = fr_newFeed(ORG_A, 'members');
    $rcBEv1  = fr_event($rcBCal, 'rc-b@p7', 'Round-1 check b night', ['key' => 'k1']);
    $rcBEv2  = fr_event($rcBCal, 'rc-b@p7', 'Round-1 check b night', ['key' => 'k2']);
    $rcBRule = fr_rule($rcBCal, 'public', [['title', 'contains', 'round-1 check b night']]);
    fr_resolve($rcBCal);
    fr_decide((int) fr_apprWith($rcBEv1, 'pending')[0]['approvalID'], 'approved');
    fr_decide((int) fr_apprWith($rcBEv2, 'pending')[0]['approvalID'], 'approved');
    fr_resolve($rcBCal);
    fr_ok('round-1 check b, KEEP-WORKING: both dates approved under the rule → public, no website',
        fr_ls($rcBEv1) === 'public/rule' && fr_ls($rcBEv2) === 'public/rule'
        && (int) fr_ev($rcBEv1)['importWebsite'] === 0 && (int) fr_ev($rcBEv2)['importWebsite'] === 0,
        fr_ls($rcBEv1) . ' ' . fr_ls($rcBEv2));
    fr_q('UPDATE tblExternalFeedRules SET websiteOptIn = 1 WHERE ruleID = ?', 'i', [$rcBRule]);
    fr_resolve($rcBCal);
    fr_ok('round-1 check b: the rule then ticks the website box (a NEW request) → BOTH dates must wait again, not follow the OLD request\'s approval onto the website',
        fr_ls($rcBEv1) === 'members/waiting' && fr_ls($rcBEv2) === 'members/waiting',
        fr_ls($rcBEv1) . ' ' . fr_ls($rcBEv2) . ' | ' . json_encode(fr_appr($rcBEv2)));

    // -------------------------------------------------------------------------
    // Round-1 check, finding c: the pass-2 sibling bucket must include the
    // event's own IDENTITY (:1284, :1296) — otherwise approving one event
    // lets a completely DIFFERENT event with the same title follow it.
    // -------------------------------------------------------------------------
    $rcCCal = fr_newFeed(ORG_A, 'members');
    $rcCEvA = fr_event($rcCCal, 'rc-c-a@p7', 'Round-1 check c open day');
    $rcCEvB = fr_event($rcCCal, 'rc-c-b@p7', 'Round-1 check c open day');
    fr_rule($rcCCal, 'public', [['title', 'contains', 'round-1 check c open day']]);
    fr_resolve($rcCCal);
    fr_decide((int) fr_apprWith($rcCEvA, 'pending')[0]['approvalID'], 'approved');
    fr_resolve($rcCCal);
    fr_ok('round-1 check c, KEEP-WORKING: event A itself is approved and public', fr_ls($rcCEvA) === 'public/rule', fr_ls($rcCEvA));
    fr_ok('round-1 check c: event B, a DIFFERENT identity with only the same title, must still WAIT (the sibling bucket must include the identity)',
        fr_ls($rcCEvB) === 'members/waiting' && count(fr_apprWith($rcCEvB, 'pending')) === 1, fr_ls($rcCEvB) . ' ' . json_encode(fr_appr($rcCEvB)));

    // -------------------------------------------------------------------------
    fr_heading('E22. "Don\'t show via API": any applying source is enough (B2)');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $api = [];
    $api['calendar box only']      = fr_event(FA, 'e22-1@p7', 'Api one');
    $api['date choice only']       = fr_event(F, 'e22-2@p7', 'Api two', ['key' => 'k2']);
    fr_choice(F, 'date', 'e22-2@p7', 'members', ['key' => 'k2', 'apiOptOut' => 1]);
    $api['series choice only']     = fr_event(F, 'e22-3@p7', 'Api three');
    fr_choice(F, 'series', 'e22-3@p7', 'members', ['apiOptOut' => 1]);
    $api['one matching rule only'] = fr_event(F, 'e22-4@p7', 'Api four');
    fr_rule(F, 'members', [['title', 'equals', 'api four']], ['apiOptOut' => 1]);
    $api['none']                   = fr_event(F, 'e22-5@p7', 'Api five');
    $api['calendar ticked, a choice with the box clear'] = fr_event(FA, 'e22-6@p7', 'Api six');
    fr_choice(FA, 'series', 'e22-6@p7', 'members', ['apiOptOut' => 0]);
    $api['a choice outside its dates, box ticked'] = fr_event(F, 'e22-7@p7', 'Api seven');
    fr_choice(F, 'series', 'e22-7@p7', 'members', ['apiOptOut' => 1, 'from' => fr_d('plus4')]);
    $api['a rule waiting for approval, box ticked'] = fr_event(F, 'e22-8@p7', 'Api eight');
    fr_rule(F, 'public', [['title', 'equals', 'api eight']], ['apiOptOut' => 1]);
    $api['a rule that was declined, box ticked'] = fr_event(F, 'e22-9@p7', 'Api nine');
    fr_rule(F, 'public', [['title', 'equals', 'api nine']], ['apiOptOut' => 1]);
    $api['a private event, a rule with the box ticked'] = fr_event(F, 'e22-10@p7', 'Api ten', ['private' => 1]);
    fr_rule(F, 'public', [['title', 'equals', 'api ten']], ['apiOptOut' => 1]);
    $api['an approved rule, then its box ticked'] = fr_event(F, 'e22-11@p7', 'Api eleven');
    $approvedRule = fr_rule(F, 'public', [['title', 'equals', 'api eleven']], ['apiOptOut' => 0]);
    fr_resolve(F);
    fr_resolve(FA);
    fr_decide((int) fr_apprWith($api['a rule that was declined, box ticked'], 'pending')[0]['approvalID'], 'declined');
    fr_decide((int) fr_apprWith($api['an approved rule, then its box ticked'], 'pending')[0]['approvalID'], 'approved');
    fr_resolve(F);
    $elevenRowsBefore = count(fr_appr($api['an approved rule, then its box ticked']));
    fr_q('UPDATE tblExternalFeedRules SET apiOptOut = 1 WHERE ruleID = ?', 'i', [$approvedRule]);
    fr_resolve(F);
    $want = [
        'calendar box only' => 1, 'date choice only' => 1, 'series choice only' => 1, 'one matching rule only' => 1, 'none' => 0,
        'calendar ticked, a choice with the box clear' => 1, 'a choice outside its dates, box ticked' => 0,
        'a rule waiting for approval, box ticked' => 1, 'a rule that was declined, box ticked' => 1,
        'a private event, a rule with the box ticked' => 1, 'an approved rule, then its box ticked' => 1,
    ];
    $levels = [];
    foreach ($api as $label => $id) {
        $ev = fr_ev($id);
        $levels[$label] = [$ev['importLevel'], (int) $ev['importWebsite']];
        fr_ok(($label === 'none' ? 'KEEP-WORKING: ' : '') . $label . ' → importApiOptOut ' . $want[$label], (int) $ev['importApiOptOut'] === $want[$label], json_encode($ev));
    }
    fr_ok('...the choice outside its dates starts counting on time: its re-check moment is its first day\'s first moment',
        fr_ev($api['a choice outside its dates, box ticked'])['importRecheckAt'] === fr_text(fr_dayStart(fr_d('plus4'))),
        json_encode(fr_ev($api['a choice outside its dates, box ticked'])));
    fr_ok('...ticking the box on an APPROVED rule leaves the approval applying: still public, no new waiting row (the box is not part of the request)',
        fr_ls($api['an approved rule, then its box ticked']) === 'public/rule' && count(fr_appr($api['an approved rule, then its box ticked'])) === $elevenRowsBefore,
        json_encode(fr_appr($api['an approved rule, then its box ticked'])));
    fr_q('UPDATE tblExternalEventChoices SET apiOptOut = 0 WHERE feedID IN (?, ?)', 'ii', [F, FA]);
    fr_q('UPDATE tblExternalFeedRules SET apiOptOut = 0 WHERE feedID IN (?, ?)', 'ii', [F, FA]);
    fr_q('UPDATE tblExternalFeeds SET apiOptOut = 0 WHERE feedID = ?', 'i', [FA]);
    fr_resolve(F);
    fr_resolve(FA);
    $same = true;
    $allClear = true;
    foreach ($api as $label => $id) {
        $ev = fr_ev($id);
        $same = $same && $levels[$label] === [$ev['importLevel'], (int) $ev['importWebsite']];
        $allClear = $allClear && (int) $ev['importApiOptOut'] === 0;
    }
    fr_ok('in every case, importLevel and importWebsite are exactly what they are with every box clear (and with every box clear, nothing is opted out)',
        $same && $allClear, json_encode($levels));
    $fw = fr_newFeed(ORG_A, 'public', ['website' => 1, 'apiOptOut' => 1]);
    $webEvent = fr_event($fw, 'e22-web@p7', 'Website but not API');
    fr_resolve($fw);
    $today = fr_todayLocal()->format('Y-m-d');
    $inMode = static function (string $mode) use ($webEvent, $today): bool {
        $w = EventVisibility::where('e', $mode, 0, $today);
        $types = 'i' . $w['types'];

        return fr_q('SELECT e.eventID FROM tblEvents e WHERE e.eventID = ?' . $w['sql'], $types, array_merge([$webEvent], $w['params'])) !== [];
    };
    fr_ok('KEEP-WORKING: website mode still returns a public, website-ticked event whose calendar is "Don\'t show via API"; key mode does not',
        $inMode(EventVisibility::MODE_WEBSITE) === true && $inMode(EventVisibility::MODE_KEY) === false, json_encode(fr_ev($webEvent)));

    // -------------------------------------------------------------------------
    fr_heading('E25. Personal data: "delete my data" reaches every new person column (C7)');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $gd = fr_newFeed(ORG_A, 'members');
    $ge = fr_event($gd, 'e25@p7', 'Personal data event');
    // The two choices are for ANOTHER event, so the rule is what decides this
    // one and there is a widening for the person to approve.
    $made    = fr_choice($gd, 'series', 'e25-other@p7', 'members', ['by' => UX]);
    $changed = fr_choice($gd, 'date', 'e25-other@p7', 'members', ['by' => U6, 'updatedBy' => UX]);
    $ruleX   = fr_rule($gd, 'public', [['title', 'contains', 'personal data']], ['by' => UX, 'updatedBy' => U6]);
    fr_resolve($gd);
    fr_decide((int) fr_apprWith($ge, 'pending')[0]['approvalID'], 'approved', ORG_A, UX);
    fr_q("INSERT INTO tblErasureRequest (requestID, siteID, userID, subjectEmail, status, dueBy) VALUES (900901, ?, ?, 'ux.p7@selftest.invalid', 'processing', UTC_TIMESTAMP())",
        'ii', [ORG_A, UX]);
    $entries = array_values(array_filter(GdprEraser::catalogue(), static fn (array $e): bool => in_array($e['table'],
        ['tblExternalEventChoices', 'tblExternalFeedRules', 'tblExternalEventApprovals', 'tblExternalRuleConditions'], true)));
    $process = new ReflectionMethod(GdprEraser::class, 'processEntry');
    foreach ($entries as $entry) {
        $process->invoke(null, $mysqli, 900901, UX, $entry);
    }
    $choiceRows = fr_q('SELECT choiceID, createdByID, updatedByID FROM tblExternalEventChoices WHERE feedID = ? ORDER BY choiceID', 'i', [$gd]);
    $ruleRow    = fr_one('SELECT createdByID, updatedByID FROM tblExternalFeedRules WHERE ruleID = ?', 'i', [$ruleX]);
    $apprRows   = fr_q('SELECT decidedByID FROM tblExternalEventApprovals WHERE feedID = ?', 'i', [$gd]);
    $noUx = true;
    foreach (array_merge($choiceRows, [$ruleRow], $apprRows) as $row) {
        foreach ($row as $col => $value) {
            if ($col !== 'choiceID' && $value !== null && (int) $value === UX) {
                $noUx = false;
            }
        }
    }
    fr_ok('the erasure step reaches all three tables: every createdByID, updatedByID and decidedByID that was the person is now empty ('
        . count($entries) . ' instructions ran)', $noUx === true && count($entries) === 5, json_encode([$choiceRows, $ruleRow, $apprRows]));
    fr_ok('...and every row is still there (the settings and decisions are the organisation\'s)',
        count($choiceRows) === 2 && $ruleRow !== null && count($apprRows) >= 1, json_encode([$choiceRows, $ruleRow, $apprRows]));
    $byId = array_column($choiceRows, null, 'choiceID');
    fr_ok('KEEP-WORKING: another person\'s numbers are untouched (U6 still made one choice and last changed the rule)',
        (int) ($byId[$changed]['createdByID'] ?? 0) === U6 && (int) $ruleRow['updatedByID'] === U6, json_encode([$choiceRows, $ruleRow]));

    // -------------------------------------------------------------------------
    fr_heading('E28. The zone a calendar\'s events are stored in: zoneFor()\'s three cases');
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $zoneFor = new ReflectionMethod(FeedImporter::class, 'zoneFor');
    $name = static fn (array $feed): string => $zoneFor->invoke(null, $mysqli, $feed)->getName();
    fr_ok('a calendar with its own valid zone → that zone (America/New_York)', $name(['timezone' => 'America/New_York', 'siteID' => ORG_A]) === 'America/New_York');
    fr_ok('a calendar zone PHP does not recognise → UTC, NOT the organisation\'s zone', $name(['timezone' => 'Mars/Olympus', 'siteID' => ORG_A]) === 'UTC',
        $name(['timezone' => 'Mars/Olympus', 'siteID' => ORG_A]));
    fr_ok('KEEP-WORKING: no zone of its own → the organisation\'s (Europe/London)', $name(['timezone' => '', 'siteID' => ORG_A]) === SITE_ZONE,
        $name(['timezone' => '', 'siteID' => ORG_A]));
    fr_ok('organisationZone() for A → Europe/London', FeedResolver::organisationZone(ORG_A)->getName() === SITE_ZONE);
    fr_q("DELETE FROM tblSettings WHERE settingKey = 'site.timezone' AND siteID = ?", 'i', [ORG_A]);
    $fallback = (string) (fr_one("SELECT settingValue FROM tblSettings WHERE settingKey = 'site.timezone' AND siteID IS NULL")['settingValue'] ?? '');
    $expectFallback = ($fallback === '') ? 'UTC' : $fallback;
    fr_ok('with A\'s own setting removed → the portal-wide setting, or UTC when there is none (here: ' . $expectFallback . ')',
        $name(['timezone' => '', 'siteID' => ORG_A]) === $expectFallback && FeedResolver::organisationZone(ORG_A)->getName() === $expectFallback,
        $name(['timezone' => '', 'siteID' => ORG_A]));

    // -------------------------------------------------------------------------
    fr_heading('E29. Text that is not valid text fails closed in every direction (challenge finding 8)');
    // -------------------------------------------------------------------------
    // A narrowing rule: hidden, and "Don't show via API". The event's title is
    // the bytes "Caf" + 0xC3 — broken UTF-8, which the database would refuse
    // to store, so it is put into the loaded state directly and `plan()` is
    // run through reflection.
    fr_resetWorld();
    $ue = fr_event(F, 'e29@p7', 'Café');
    fr_rule(F, 'hidden', [['title', 'word', 'café']], ['apiOptOut' => 1]);
    $load = fr_private('loadFeedState');
    $plan = fr_private('plan');
    $state = $load->invoke(null, $mysqli, F);
    $good = $plan->invoke(null, $state, fr_nowUtc(), fr_zone(), null);
    $a = $good['answers'][$ue];
    fr_ok('KEEP-WORKING: titled "Café" (valid) → hidden, source rule, importApiOptOut 1',
        $a['importLevel'] === 'hidden' && $a['importSource'] === 'rule' && (int) $a['importApiOptOut'] === 1, json_encode($a));
    foreach ($state['events'] as $i => $event) {
        if ((int) $event['eventID'] === $ue) {
            $state['events'][$i]['eventName'] = "Caf\xC3";
        }
    }
    $bad = $plan->invoke(null, $state, fr_nowUtc(), fr_zone(), null);
    $a = $bad['answers'][$ue];
    $newRows = array_filter($bad['rows'], static fn (array $r): bool => $r['isNew'] === true);
    fr_ok('titled with broken bytes → hidden, source conflict, importApiOptOut 1, and no approval row',
        $a['importLevel'] === 'hidden' && $a['importSource'] === 'conflict' && (int) $a['importApiOptOut'] === 1 && $newRows === [], json_encode($a));

    // -------------------------------------------------------------------------
    // Round-1 check FIX C: a live imported row with NO identity hash (a
    // legacy #327 row; migration 204 could leave externalUidHash NULL) must
    // not crash the resolve. snapshot()'s $uidHash is now nullable (:1716);
    // such a row still waits for approval like any other — only pass 2 (the
    // sibling decision) can never match it, since a null identity is
    // skipped from that bucket on purpose (:1281).
    // -------------------------------------------------------------------------
    fr_resetWorld();
    $rcCLegacy = fr_event(F, 'rc-fixc@p7', 'Round-1 check FIX C legacy');
    fr_q('UPDATE tblEvents SET externalUidHash = NULL WHERE eventID = ?', 'i', [$rcCLegacy]);
    // Round-2 check gap 4: a SECOND row, a DIFFERENT identity before it was
    // nulled out, sharing the FIRST row's exact title (so its content
    // fingerprint matches too, once approved). Nothing proved that the null
    // exclusion at ":1322"/":1336" keeps two such rows OUT of pass 2's
    // sibling bucket — without it they would collapse onto the SAME bucket
    // key (an empty identity plus a matching request and content), and
    // approving one would silently approve the other, which nobody decided.
    $rcCLegacy2 = fr_event(F, 'rc-fixc-2@p7', 'Round-1 check FIX C legacy');
    fr_q('UPDATE tblEvents SET externalUidHash = NULL WHERE eventID = ?', 'i', [$rcCLegacy2]);
    fr_rule(F, 'public', [['title', 'contains', 'round-1 check fix c legacy']]);
    $rcCThrew = null;
    try {
        fr_resolve(F);
    } catch (Throwable $rcCEx) {
        $rcCThrew = get_class($rcCEx) . ': ' . $rcCEx->getMessage();
    }
    fr_ok('round-1 check FIX C: a widening rule matching a row with NO identity hash resolves without throwing, and the date waits for approval',
        $rcCThrew === null && fr_ls($rcCLegacy) === 'members/waiting' && count(fr_apprWith($rcCLegacy, 'pending')) === 1,
        'threw: ' . ($rcCThrew ?? 'nothing') . '; ' . fr_ls($rcCLegacy) . ' ' . json_encode(fr_appr($rcCLegacy)));
    fr_ok('round-2 check gap 4, set-up: the SECOND no-identity row (same title) also waits, independently',
        fr_ls($rcCLegacy2) === 'members/waiting' && count(fr_apprWith($rcCLegacy2, 'pending')) === 1, fr_ls($rcCLegacy2));
    $rcCNormal = fr_event(F, 'rc-fixc-normal@p7', 'Round-1 check FIX C legacy normal');
    fr_resolve(F);
    fr_ok('round-1 check FIX C, KEEP-WORKING: an ORDINARY row (a real identity hash), matched by the same rule, waits exactly the same way',
        fr_ls($rcCNormal) === 'members/waiting' && count(fr_apprWith($rcCNormal, 'pending')) === 1, fr_ls($rcCNormal));

    // Approve the FIRST no-identity row directly (the way the part P8 page
    // will), then resolve again. If the two null identities were ever
    // allowed to share pass 2's sibling bucket, this second no-identity
    // row's OWN matching request+content would ride along and be silently
    // approved too — the leak the checker proved on a reverted null guard.
    fr_decide((int) fr_apprWith($rcCLegacy, 'pending')[0]['approvalID'], 'approved');
    fr_resolve(F);
    fr_ok('round-2 check gap 4 (FIX D): approving the FIRST no-identity row must NOT approve the SECOND — two different rows with a null identity must never be treated as the same event',
        fr_ls($rcCLegacy2) === 'members/waiting' && fr_apprWith($rcCLegacy2, 'approved') === [],
        fr_ls($rcCLegacy2) . ' ' . json_encode(fr_appr($rcCLegacy2)));

} catch (Throwable $e) {
    fr_ok('the self-test ran to the end', false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    // 🧹 Never throws from here: a failed clean-up is one more FAIL line, and
    //    the summary still runs. The clock is put back last of all.
    try {
        fr_removeWorld();
        echo "\nThe made-up world was removed.\n";
    } catch (Throwable $e) {
        fr_ok('the made-up world was removed afterwards', false, get_class($e) . ': ' . $e->getMessage());
    }
    try {
        $mysqli->query('SET timestamp = DEFAULT');
    } catch (Throwable $e) {
        fr_ok('the database clock was unpinned afterwards', false, get_class($e) . ': ' . $e->getMessage());
    }
}

fr_finish();
