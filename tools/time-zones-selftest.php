<?php
// Path: tools/time-zones-selftest.php
/**
 * -----------------------------------------------------------------------------
 * One answer to "which time zone?", on the REAL portal — self-test 🕰️ (#604)
 * -----------------------------------------------------------------------------
 * WHAT THIS HOLDS IN PLACE
 * ------------------------
 * The portal is a diary. An hour wrong is a congregation at a locked building.
 * Two facts used to be kept in two places each, and the two could differ:
 *
 *   - an EVENT's zone (`tblEvents.timezone`, and a second column,
 *     `eventTimezone`, that held "Europe/London" for every event made in the
 *     portal);
 *   - an ORGANISATION's zone (`tblSites.timezone`, and a `site.timezone`
 *     setting; run on 13 October 2026, one installation gave two answers in 9
 *     of 13 combinations of the two).
 *
 * Now each has ONE place and ONE answer. None of that shows as a failing
 * page when it goes wrong: a time is simply told on the wrong clock. So this
 * file runs the portal and looks.
 *
 * WHAT IT DOES
 * ------------
 * It copies `web/` into a NEW scratch folder with a throwaway connection
 * file and key, starts PHP's built-in web server on it behind a small router
 * that imitates `.htaccess`, and starts a second, tiny server that hands out
 * one calendar file (the "outside calendar" the importer reads). NO FILE OF
 * THE PORTAL IS CHANGED IN THE COPY. It also runs this same file again as a
 * CHILD process on the copy, to call the portal's own code directly: the
 * reminder job, the calendar importer and the venue code. A child can be
 * given a CLOCK: the database connection's time zone and its "now". That is
 * how the two nights a year the clocks change are run on a made-up date.
 *
 * The parts:
 *
 *   M  Migration 217 on real rows: an older database (the removed column put
 *      back, with its default) holding events whose two columns agree,
 *      disagree, hold text that is not a zone, and hold nothing in the one
 *      that stays. Run once, then again.
 *   O  An organisation's zone, in the 13 combinations of the two old places.
 *      Before the migration and after it, the four places an administrator
 *      can read the zone from (Server Information, the admin dashboard, the
 *      e-mail page, the health report) all say the zone the UNCHANGED portal's
 *      start-up answered, which this file works out by the old rule, written
 *      out again here. So nothing an ordinary page shows moves.
 *   W  Every writer of an event stores a real zone: the event form, a
 *      member's suggestion, the file import, the events API and the calendar
 *      importer, in an organisation whose zone is not London's. A zone that
 *      is not a zone name is refused by the form and by the API.
 *   R  Every reader gives that one zone back: the event page, the calendar
 *      feed, the export, the events API and the venue code. The feed and the
 *      export are also held to the TIME: the stored clock time as it stands
 *      (they used to move it by the zone's distance from UTC), and an
 *      all-day event's own date (it used to go out a day early east of
 *      Greenwich).
 *   U  What is NOT a zone is found and listed, never changed: events and
 *      organisations. The importer refuses a calendar with no zone of its
 *      own where the organisation's zone is in doubt. The Organisations form
 *      and the general Settings page refuse what they must.
 *   T  The reminder job decides "is it time?" from MOMENTS: both change
 *      nights, in a zone north of the equator and one south of it, with the
 *      database's own clock in a third zone. Each reminder is made once: not
 *      twice in the hour that is repeated, and not skipped in the one that is
 *      missing. And the job is run on EVERY HALF HOUR across both change
 *      nights and across an ordinary week in ten zones, for a 23:55 start:
 *      a call that lands just after midnight on the event's own day still
 *      sends, and only the day the clocks go back holds one back. (That is
 *      about 550 runs of the job, and most of the minute this file takes.)
 *   P  No PLACE is built in either (#935): the Sabbath place ships empty and
 *      sunset follows the organisation's map position, or the fixed times
 *      when there is none, and a venue's country follows the organisation's
 *      or is refused. And the Sabbath page (#936) fills its boxes from the
 *      installation's row, so Save pressed untouched inside an organisation
 *      with rows of its own changes nothing, and a field not SENT is left
 *      alone, one field at a time.
 *
 * DATES. Nothing here depends on today's date. Every date is counted from
 * "now", both change nights are worked out from each zone's own rules (the
 * next change each way after now), and the ordinary week is searched for.
 * Where a moment is written out (the giving-retention checks), it is GIVEN
 * to the code as "now" and no clock is read.
 *
 * WHAT IT CANNOT SHOW
 * -------------------
 *   - Anything in a browser: pages are read as text.
 *   - A real mail server. No message is sent by anything here; the reminder
 *     part looks at WHEN a reminder is recorded as made, and at the subject
 *     of each one the job TRIED to send to the one member left switched on
 *     (the mail route is one that is not set up, so nothing leaves). The
 *     use-by moment a kept reminder carries is tools/mail-queue-selftest.php's
 *     to hold.
 *   - A calendar program reading the file. The feed and the export are
 *     read as text: the zone named beside each time, and the time itself.
 *   - MariaDB, which no test in this repository covers.
 *
 * HOW TO RUN IT
 * -------------
 *   SELFTEST_DB_NAME=selftest_zones SELFTEST_DB_PORT=3306 SELFTEST_DB_PASS=… \
 *       php tools/time-zones-selftest.php
 *
 *   SELFTEST_DB_HOST (default 127.0.0.1)   SELFTEST_DB_USER (default root)
 *   SELFTEST_DB_NAME (required; must start with selftest_; a database built
 *                     from web/_sql/full_schema.sql that nothing else uses)
 *   SELFTEST_WEB_DIR (default the system's temporary folder)
 *   SELFTEST_WEB_PORT (default 9861; the calendar server takes the next one)
 *
 * It ends with "REACHED THE END" and the number of checks, and exits 0 only
 * when every check passed AND the number is the one expected. A run that
 * stops early therefore cannot look like a pass.
 *
 * @package   WebMS-Intra (development tooling — never deployed; only web/ is)
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/604
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

const RESULT_MARK     = '@@time-zones-selftest-result@@';
const EXPECTED_CHECKS = 282;  // + six in part P (#945: P17b-P17g) on the 276 before: + twenty-three in part Q (#942: Q00-Q08, Q09a, Q09, Q10, Q10b, Q10c, Q11-Q19) on the 253 before: + two in part N (#937: N10b, N10c) on the 251 before: + forty-seven in part P (#935, #936: P00-P07, six P08, P09-P41) on the 204 before: + eighteen in part N (#782: N00-N17) on the 186 that follow: 134 + six (r7) + three (r8) + two (r9) + three W09i-W09k (r10) + thirteen in part X (#777 part two, #783) + fourteen in part X (#804, #805, #806: X03b-X03e, X10-X19) + eleven in part X (#812, #819: X18b-X18l)

// =============================================================================
// 🧒 THE CHILD PROCESS
// =============================================================================
// This same file, run as `php time-zones-selftest.php --child <scratch web
// folder>`, to call the portal's own code directly. It runs at the TOP LEVEL,
// not inside a function, because the portal's start-up file sets $mysqli and
// $SETTINGS as globals, and the reminder job reads $mysqli. What to do
// arrives in the SELFTEST_JOB variable.
if (($argv[1] ?? '') === '--child') {
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/';
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $childWeb = (string) ($argv[2] ?? '');
    $job      = (array) json_decode((string) getenv('SELFTEST_JOB'), true);
    $out      = ['threw' => ''];
    require $childWeb . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    try {
        // 🕰️ A made-up clock for the DATABASE: the connection is put in a
        //    zone of its own and its "now" is fixed. PHP's clock is left
        //    alone on purpose; nothing under test may depend on it.
        if (isset($job['sessionZone']) === true) {
            $zoneStmt = $mysqli->prepare('SET time_zone = ?');
            $zoneStmt->bind_param('s', $job['sessionZone']);
            $zoneStmt->execute();
            $zoneStmt->close();
        }
        if (isset($job['timestamp']) === true) {
            $mysqli->query('SET timestamp = ' . (int) $job['timestamp']);
        }
        switch ((string) $job['task']) {
            case 'facts': // what the one function answers, and what start-up made of it
                $out['php'] = date_default_timezone_get();
                foreach ((array) ($job['sites'] ?? []) as $site) {
                    $out['facts'][(string) $site] = \Portal\Core\Site::timezoneFacts((int) $site);
                    $out['zone'][(string) $site]  = \Portal\Core\Site::timezone((int) $site);
                    $out['giving'][(string) $site] = (string) \Portal\Core\GivingRetention::ruleForSite((int) $site)['timezone'];
                    $out['feeds'][(string) $site]  = \Portal\Core\FeedResolver::organisationZone((int) $site)->getName();
                }
                break;
            case 'giving': // what giving retention takes as the zone, and as "today", at given moments
                $rule         = \Portal\Core\GivingRetention::ruleForSite((int) $job['site']);
                $out['zone']  = (string) $rule['timezone'];
                $out['php']   = date_default_timezone_get();
                foreach ((array) $job['moments'] as $moment) {
                    $out['today'][(string) $moment] = \Portal\Core\GivingRetention::today((string) $rule['timezone'], new DateTimeImmutable((string) $moment, new DateTimeZone('UTC')));
                }
                break;
            case 'confirm': // the giving-records settings saved with the "confirmed" tick, at a given moment
                $at     = new DateTimeImmutable((string) $job['moment'], new DateTimeZone('UTC'));
                $posted = ['countryCode' => '', 'scope' => 'receipted', 'years' => '6', 'confirmed' => '', 'yearEndMonth' => '', 'yearEndDay' => '', 'hold' => '', 'understood' => ''];
                // Saved WITHOUT the tick first, so that the tick below is a new confirmation and stores a new date.
                \Portal\Core\GivingRetention::saveForSite($mysqli, (int) $job['site'], $posted, null, null, $at);
                $out['saved']    = \Portal\Core\GivingRetention::saveForSite($mysqli, (int) $job['site'], ['confirmed' => '1'] + $posted, null, null, $at)['saved'];
                $out['deciding'] = \Portal\Core\GivingRetention::today((string) \Portal\Core\GivingRetention::ruleForSite((int) $job['site'])['timezone'], $at);
                break;
            case 'reminders': // ONE run of the real reminder job, as its page makes it
                $_GET['key'] = (string) $job['key'];
                ob_start();
                require $childWeb . DIRECTORY_SEPARATOR . '_apps' . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'event-reminders.php';
                $out['said'] = (string) ob_get_clean();
                break;
            case 'import': // one refresh of an outside calendar, from this run's stand-in server
                \Portal\Core\SafeFetch::$testResolverOverride = ['calendar.test' => ['ips' => ['127.0.0.1'], 'port' => (int) $job['port']]];
                $out['result'] = \Portal\Core\FeedImporter::refresh($mysqli, (int) $job['feedId'], 'schedule', null);
                break;
            case 'venue': // the venue code's reading of one stored event
                $stmt = $mysqli->prepare('SELECT startDateTime, endDateTime, timezone FROM tblEvents WHERE eventID = ?');
                $id   = (int) $job['eventId'];
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $event = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $cover        = \Portal\Core\Venues::classifyEventCoverage((array) $event, (int) $job['venueId']);
                $out['class'] = (string) $cover['classification'];
                $out['days']  = array_map(static fn (array $d): string => (string) $d['date'], $cover['perDay']);
                break;
            case 'livestreamNext': // #782: the next slot of organisation 1, after a given moment
                $next          = \Portal\Core\Livestream::nextScheduled(1, new DateTimeImmutable((string) $job['after'], new DateTimeZone('UTC')));
                $out['nextAt'] = $next === null ? null : (string) $next['nextAt'];
                break;
            case 'sabbath': // #782: the quiet window at a given moment, shown on a given clock
                [$winStart]    = \Portal\Core\Sabbath::computeWindow((int) $job['at']);
                $out['start']  = (new DateTimeImmutable('@' . $winStart))->setTimezone(new DateTimeZone((string) $job['showIn']))->format('D Y-m-d H:i');
                $out['startTs'] = $winStart;
                $out['place']   = \Portal\Core\Sabbath::place(); // #935: where sunset is worked out, or null
                break;
            case 'sunsetWindows': // #935 round 3: windows at given places and clocks, with PHP's default zone set to a given one
                $errorsBefore = (int) $mysqli->query('SELECT COUNT(*) AS n FROM `tblErrors`')->fetch_assoc()['n'];
                date_default_timezone_set((string) $job['phpZone']);
                $out['zoneBefore'] = date_default_timezone_get();
                foreach ((array) $job['cases'] as $name => [$zone, $lat, $lng, $at]) {
                    $details = \Portal\Core\Sabbath::windowDetails((int) $at, null, [
                        'method' => 'sunset_calc', 'location_lat' => (string) $lat, 'location_lng' => (string) $lng,
                        'timezone' => (string) $zone, 'start_offset_minutes' => '0', 'end_offset_minutes' => '0',
                    ]);
                    $clock = new DateTimeZone((string) $zone);
                    $out['windows'][$name] = (new DateTimeImmutable('@' . $details['start']))->setTimezone($clock)->format('D Y-m-d H:i')
                        . ' / ' . (new DateTimeImmutable('@' . $details['end']))->setTimezone($clock)->format('D Y-m-d H:i');
                    $out['noSunset'][$name] = $details['noSunset'];
                }
                // #935 round 5: an 18:00 that is NOT "the sun does not set" — the fixed
                // method, and sunset with no place known — must never be marked as one.
                $firstCase = (array) reset($job['cases']);
                $out['noSunsetFixed'] = \Portal\Core\Sabbath::windowDetails((int) $firstCase[3], null, [
                    'method' => 'fixed', 'location_lat' => '', 'location_lng' => '', 'timezone' => (string) $firstCase[0],
                ])['noSunset'];
                $noPlace = ['method' => 'sunset_calc', 'location_lat' => '', 'location_lng' => '', 'timezone' => (string) $firstCase[0]];
                $out['placeNoPlace']    = \Portal\Core\Sabbath::place(null, $noPlace);
                $out['noSunsetNoPlace'] = \Portal\Core\Sabbath::windowDetails((int) $firstCase[3], null, $noPlace)['noSunset'];
                // And a window worked out for ANOTHER organisation, as the held-texts job does.
                \Portal\Core\Sabbath::computeWindow((int) $job['otherAt'], (int) $job['otherSite']);
                $out['zoneAfter']   = date_default_timezone_get();
                $out['errorsAdded'] = (int) $mysqli->query('SELECT COUNT(*) AS n FROM `tblErrors`')->fetch_assoc()['n'] - $errorsBefore;
                break;
            case 'noSunsetNow': // #935 round 5: is THIS week's window, as the page draws it now, without a sunset at each place given?
                foreach ((array) $job['places'] as $name => [$lat, $lng]) {
                    $out['noSunset'][$name] = \Portal\Core\Sabbath::windowDetails(time(), null, [
                        'method' => 'sunset_calc', 'location_lat' => (string) $lat, 'location_lng' => (string) $lng, 'timezone' => '',
                    ])['noSunset'];
                }
                break;
            case 'sabbathOther': // #935 round 3: another organisation's place and quiet answer WITHOUT forcing the context (the held-texts job)
                $out['siteNow'] = \Portal\Core\Site::id();
                $out['place']   = \Portal\Core\Sabbath::place((int) $job['site']);
                foreach ((array) $job['moments'] as $moment) {
                    $out['quiet'][] = \Portal\Core\Sabbath::isQuietNow(null, (int) $moment, (int) $job['site']);
                }
                break;
            case 'sabbathForced': // #935 round 2: another organisation forced in AFTER start-up, as ApiAuth does
                \Portal\Core\Site::forceContext((int) $job['site']);
                $out['siteNow'] = \Portal\Core\Site::id();
                $out['place']   = \Portal\Core\Sabbath::place((int) $job['site']);
                foreach ((array) $job['moments'] as $moment) {
                    $out['quiet'][] = \Portal\Core\Sabbath::isQuietNow(null, (int) $moment, (int) $job['site']);
                }
                // #935 round 4: with NO organisation given, after the force: the forced one.
                $out['placeNull'] = \Portal\Core\Sabbath::place(null);
                foreach ((array) $job['moments'] as $moment) {
                    $out['quietNull'][] = \Portal\Core\Sabbath::isQuietNow(null, (int) $moment, null);
                }
                break;
            default:
                $out['threw'] = 'the child was given a task it does not know';
        }
    } catch (Throwable $thrown) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $out['threw'] = get_class($thrown) . ': ' . $thrown->getMessage() . ' at ' . basename($thrown->getFile()) . ':' . $thrown->getLine();
    }
    echo "\n" . RESULT_MARK . json_encode($out) . "\n";
    exit(0);
}

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

// =============================================================================
// ⚙️ What this run was given
// =============================================================================
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows, changes a table and runs a migration");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
$sep       = DIRECTORY_SEPARATOR;
$repo      = (string) realpath(dirname(__DIR__));
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
$port    = ($portRaw === false || $portRaw === '') ? 9861 : (int) $portRaw;
if ($port < 1024 || $port > 65534) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65534');
}
$calendarPort = $port + 1;

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
$db->query("SET time_zone = '+00:00'");

$pass  = 0;
$fail  = 0;
$state = ['token' => bin2hex(random_bytes(16)), 'procs' => [], 'child' => '', 'done' => false];

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

/**
 * 🔍 A PATTERN SEARCH THAT CANNOT BE MISTAKEN FOR "NOT THERE" (#829).
 *
 * `preg_match()` and `preg_match_all()` answer `false` when the search could
 * not RUN at all — PCRE's JIT stack ran out, or the backtracking limit was
 * reached — and every search in this file was read with `=== 1`, `!== 1`,
 * `> 0` or by looking at the matches it filled in. So a search that never ran
 * gave exactly the answer of a search that found nothing, and a check asking
 * for ABSENCE ("the page does NOT show…") passed on a page it never read.
 * That is not hypothetical: `tools/db-strict-mode-selftest.php` hit the JIT
 * limit on the admin dashboard once that page grew past roughly 20-30 KB, and
 * its "shows NO alert" check went on passing whatever the page drew (#792).
 *
 * These two answer EXACTLY what PHP's functions answer whenever the search
 * runs, so no check here asks anything different. When it cannot run they
 * record a RED check naming the pattern, how much text it was searching and
 * PHP's own reason, and then THROW, so no caller is ever handed an answer it
 * could read as "absent" or as "present". The red line comes first on
 * purpose: a bare uncaught exception is a crash with a label, not a check
 * that reports.
 *
 * Deliberately NOT used inside the router this file writes out for its test
 * web server (that code runs in another process, where these functions do
 * not exist, and it searches only a short request path).
 *
 * @param mixed $found Filled in exactly as `preg_match()` fills its third argument.
 */
function tz_find(string $pattern, string $subject, mixed &$found = null, int $flags = 0, int $offset = 0): int
{
    $answer = preg_match($pattern, $subject, $found, $flags, $offset);
    if ($answer === false) {
        tz_searchCouldNotRun($pattern, $subject);
    }

    return $answer;
}

/**
 * The same, for `preg_match_all()`: the number of matches when the search
 * ran, and a red check plus a thrown error when it could not.
 *
 * @param mixed $found Filled in exactly as `preg_match_all()` fills its third argument.
 */
function tz_findAll(string $pattern, string $subject, mixed &$found = null, int $flags = 0, int $offset = 0): int
{
    $answer = preg_match_all($pattern, $subject, $found, $flags, $offset);
    if ($answer === false) {
        tz_searchCouldNotRun($pattern, $subject);
    }

    return $answer;
}

/**
 * The same again for `preg_replace()` and `preg_split()`, because they are
 * the same fault in another form (#829): `preg_replace()` answers `null` when
 * it cannot run and `preg_split()` answers `false`, and this file turned both
 * into "nothing" with `(string)`, `(array)` or `?: []`. Text that has been
 * tidied to an EMPTY string contains nothing, so a check that then asks the
 * tidied text for ABSENCE ("the statement does not select `moderatorID`")
 * passed without ever having read it.
 */
function tz_replace(string|array $pattern, string|array $replacement, string $subject, int $limit = -1): string
{
    $answer = preg_replace($pattern, $replacement, $subject, $limit);
    if ($answer === null) {
        tz_searchCouldNotRun(is_array($pattern) === true ? implode(' ', $pattern) : $pattern, $subject);
    }

    return $answer;
}

/** `preg_split()`, with the same refusal to read "could not run" as "nothing". @return list<string> */
function tz_split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array
{
    $answer = preg_split($pattern, $subject, $limit, $flags);
    if ($answer === false) {
        tz_searchCouldNotRun($pattern, $subject);
    }

    return $answer;
}

/** Record the red check, then stop the caller reading anything into the failure. */
function tz_searchCouldNotRun(string $pattern, string $subject): never
{
    $why = preg_last_error_msg();
    ok('a pattern search COULD NOT RUN, so its check was not answered (#829)', false, 'pattern ' . $pattern . ' over ' . strlen($subject) . ' bytes: ' . $why . ' — this is NOT "not found"');
    throw new RuntimeException('A pattern search could not run (' . $why . '), so the check that asked it has no answer: ' . $pattern);
}

function head(string $text): void
{
    echo "\n=== " . $text . " ===\n";
}

function note(string $text): void
{
    echo 'NOTE — ' . $text . "\n";
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

/** Run migration 217 exactly as the file is written. */
function runMigration217(): void
{
    global $webSrc, $sep;
    runSql((string) file_get_contents($webSrc . $sep . '_sql' . $sep . '217_one_time_zone_answer.sql'));
}

// =============================================================================
// 🗺️ The made-up world. Numbers in a range nothing else uses.
// =============================================================================
const ADMIN    = 960001; // a global administrator
const MEMBER   = 960002; // an ordinary member of the default organisation
const OTHER    = 960003; // a member of the SECOND organisation only
const ORG_TWO  = 960101; // a second organisation, for "another organisation's zone"
const VENUE    = 960201; // a hired hall, on a clock of its own
const FEED     = 960301; // the outside calendar with no zone of its own
const FEED_OWN = 960302; // one that names its own zone
/** The default organisation's zone for most of this file: far from London's, and it changes its clocks. */
const ORG_ZONE   = 'Pacific/Auckland';
/** A zone chosen for single events: neither London's nor the organisation's. */
const EVENT_ZONE = 'America/New_York';
/** The hired hall's zone. */
const VENUE_ZONE = 'Asia/Tokyo';
/** The database connection's own zone while the reminder job runs: a third one, with no clock changes. */
const DB_ZONE    = '+05:30';

/** A clock time `$days` days from now, as the database stores one. Counted from now, never a fixed date. */
function clockIn(int $days, string $time): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d') . ' ' . $time;
}

/** The moment a clock time means in a zone, as seconds since 1970. */
function momentOf(string $clock, string $zone): int
{
    return (new DateTimeImmutable($clock, new DateTimeZone($zone)))->getTimestamp();
}

/** A moment as the portal shows it in a zone ("13 Oct 2026, 09:12 EDT"), worked out here and not by the portal. */
function shownIn(int $moment, string $zone): string
{
    return (new DateTimeImmutable('@' . $moment))->setTimezone(new DateTimeZone($zone))->format('j M Y, H:i T');
}

/** The installation-wide setting `$key`, set to `$value` (added when it is not there). */
function setting(string $key, string $value): void
{
    if (one('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]) === null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, 0)', 'sss', [$key, $value, $value]);

        return;
    }
    q('UPDATE tblSettings SET settingValue = ? WHERE settingKey = ? AND siteID IS NULL', 'ss', [$value, $key]);
}

/** The zone as stored for an event. */
function zoneOfEvent(int $eventId): string
{
    return (string) (one('SELECT timezone FROM tblEvents WHERE eventID = ?', 'i', [$eventId])['timezone'] ?? '(no such event)');
}

/** The zone as stored for an organisation. */
function zoneOfOrg(int $site): string
{
    $row = one('SELECT timezone FROM tblSites WHERE siteID = ?', 'i', [$site]);

    return $row === null ? '(no such organisation)' : ($row['timezone'] === null ? '(empty)' : (string) $row['timezone']);
}

/** Put the organisation's zone straight into its row, as the Organisations form does for a real name. */
function orgZone(int $site, ?string $zone): void
{
    q('UPDATE tblSites SET timezone = ? WHERE siteID = ?', 'si', [$zone, $site]);
}

/** Is migration 217's mark there? */
function markIsThere(): bool
{
    return one("SELECT settingID FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt' AND siteID IS NULL AND settingValue = 'organisation'") !== null;
}

// =============================================================================
// 🌐 Asking the scratch copy of the portal
// =============================================================================
function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Put back and remove everything this run made outside the database, ONCE. Also registered as a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['done'] === true) {
        return;
    }
    $state['done'] = true;
    foreach ($state['procs'] as $proc) {
        if (is_resource($proc) === true) {
            // Stopped by its own process handle, never by a pattern of names.
            proc_terminate($proc);
            proc_close($proc);
        }
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_file($child . DIRECTORY_SEPARATOR . '.time-zones-selftest-owner') === true
        && trim((string) file_get_contents($child . DIRECTORY_SEPARATOR . '.time-zones-selftest-owner')) === $state['token']) {
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($tree as $path => $info) {
            $info->isDir() === true && $info->isLink() === false ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One request to the scratch portal.
 *
 * @param array<string,mixed>|string|null $post Form fields, or a ready-made body (JSON).
 * @param array<string,mixed>             $o    `bearer`, `json` (true: the body is JSON), `files` (name => path).
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, array|string|null $post = null, array $o = []): array
{
    global $port, $state;
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Expect:', 'Accept-Language: en'];
    if (($o['bearer'] ?? '') !== '') {
        $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    }
    if (($o['json'] ?? false) === true) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($jar !== '') {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        if (is_array($post) === true && isset($o['files']) === true) {
            foreach ((array) $o['files'] as $name => $file) {
                $post[$name] = new CURLFile((string) $file, 'text/csv', basename((string) $file));
            }
            curl_setopt($handle, CURLOPT_POSTFIELDS, $post);
        } else {
            curl_setopt($handle, CURLOPT_POSTFIELDS, is_array($post) === true ? http_build_query($post) : (string) $post);
        }
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle), 'head' => ''];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (tz_find('/^X-Time-Zones-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => tz_find('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
    ];
}

function token(string $html): string
{
    if (tz_find('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return tz_find('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** POST a form with a fresh token read from `$formPage`, as a browser would. */
function post(string $jar, string $formPage, string $to, array $fields, array $o = []): array
{
    $fields['csrf_token'] = token(req($jar, 'GET', $formPage)['body']);

    return req($jar, 'POST', $to, $fields, $o);
}

/** "status location" of an answer, for failure messages. */
function said(array $answer): string
{
    return 'HTTP ' . $answer['status'] . ($answer['location'] === '' ? '' : ' to ' . $answer['location']);
}

/** A page's words, without its tags or its scripts, on one line. */
function words(string $html): string
{
    $html = (string) tz_replace('#<(script|style)\b.*?</\1>#si', ' ', $html);

    return trim((string) tz_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** The message the next page shows after a form was sent (the flash), as words. */
function flashAfter(string $jar, string $page): string
{
    $html = req($jar, 'GET', $page)['body'];
    tz_findAll('#<div class="alert alert-(?:danger|success|warning|info)[^"]*"[^>]*>(.*?)</div>#s', $html, $m);

    return words(implode(' | ', $m[1]));
}

/**
 * Run this file again as a child on the scratch copy.
 *
 * @param array<string,mixed> $job
 *
 * @return array<string,mixed> What the child answered; `threw` says why when it did not finish.
 */
function child(array $job): array
{
    global $state, $sep;
    $env                 = getenv();
    $env['PORTAL_ENV']   = 'prod';
    $env['SELFTEST_JOB'] = (string) json_encode($job);
    $proc = proc_open(
        [PHP_BINARY, '-d', 'error_log=' . $state['child'] . $sep . 'php-errors.log', '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'max_execution_time=300',
            __FILE__, '--child', $state['child'] . $sep . 'web'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $state['child'] . $sep . 'child-errors.log', 'a']],
        $pipes,
        $state['child'],
        $env
    );
    if (is_resource($proc) === false) {
        return ['threw' => 'the child could not be started'];
    }
    $text = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);
    $at = strrpos($text, RESULT_MARK);
    if ($at === false) {
        return ['threw' => 'the child ended without an answer; it printed: ' . substr(trim($text), 0, 400)];
    }
    $out = json_decode(trim(substr($text, $at + strlen(RESULT_MARK))), true);

    return is_array($out) === true ? $out : ['threw' => 'the child\'s answer could not be read'];
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
if (portAnswers($port) === true || portAnswers($calendarPort) === true) {
    refuse("something already answers on port {$port} or {$calendarPort}; this test never drives a server it did not start");
}
if (one('SELECT siteID FROM tblSites WHERE siteID = 1 AND isActive = 1') === null) {
    refuse('the database must hold an active default organisation (number 1), as a new install does');
}
if (one('SELECT userID FROM tblUsers WHERE userID IN (?, ?, ?)', 'iii', [ADMIN, MEMBER, OTHER]) !== null) {
    refuse('this database already holds this test\'s accounts: give each run a database of its own, built from web/_sql/full_schema.sql');
}
if (markIsThere() === false) {
    refuse('the database does not hold migration 217\'s mark: build it from web/_sql/full_schema.sql');
}
register_shutdown_function('cleanup');

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach ([[ADMIN, 'Selftest Zones Admin', 'admin', 1], [MEMBER, 'Selftest Zones Member', 'member', 0]] as [$id, $name, $who, $isAdmin]) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, ?, ?)', 'issii', [$id, $name, $who . '.zones@selftest.invalid', $isAdmin, $isAdmin]);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, ?, ?)', 'iii', [$id, $isAdmin, $isAdmin]);
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-zones-' . $who, $hash]);
}
// The personal calendar feed's token, for the administrator (the feed reads its SHA-256).
$feedToken = bin2hex(random_bytes(32));
q('UPDATE tblUsers SET calendarToken = ? WHERE userID = ?', 'si', [hash('sha256', $feedToken), ADMIN]);
// One API key for the default organisation, allowed to read and write events.
$apiKey = 'wbms_' . bin2hex(random_bytes(16));
q("INSERT INTO tblApiKeys (siteID, name, keyHash, keyPrefix, scopes, isActive, createdByID) VALUES (1, 'Selftest zones key', ?, ?, 'events:read,events:write', 1, NULL)", 'ss', [hash('sha256', $apiKey), substr($apiKey, 0, 12)]);
// Tokens for the two scheduled jobs this file calls, and the things they must find switched on.
$jobToken    = bin2hex(random_bytes(16));
$healthToken = bin2hex(random_bytes(16));
foreach (['reminders.cron_token' => $jobToken, 'reminders.enabled' => '1', 'maintenance.cronToken' => $healthToken, 'calendar.enabled' => 'true',
    'calendar.publicSubmit.enabled' => 'true', 'venues.enabled' => '1'] as $key => $value) {
    setting($key, $value);
}
// A hired hall on a clock of its own, and a second organisation.
q("INSERT INTO tblVenues (venueID, siteID, venueName, countryCode, timezone, createdByID) VALUES (?, 1, ?, 'GB', ?, ?)", 'issi', [VENUE, 'Selftest Zones Hall', VENUE_ZONE, ADMIN]);
q("INSERT INTO tblSites (siteID, siteKey, siteName, timezone, isActive) VALUES (?, 'selftest-zones-two', 'Selftest Zones Two', 'America/Santiago', 1)", 'i', [ORG_TWO]);

// The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . $sep . 'time-zones-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.time-zones-selftest-owner', $state['token']); // the marker FIRST
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
mkdir($child . $sep . 'calendars', 0700);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/time-zones-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/time-zones-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Time-Zones-Selftest: __TOKEN__');
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

// The two servers: each command as a LIST (no shell in between), ONE process
// each, so nothing can outlive them. The code cache is off.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['procs'][]  = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-d', 'max_execution_time=120', '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']],
    $pipes,
    $child,
    $env
);
// The outside calendar: plain files from one folder, on 127.0.0.1 only.
$state['procs'][] = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $calendarPort, '-t', $child . $sep . 'calendars'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'calendar-output.log', 'a'], 2 => ['file', $child . $sep . 'calendar-output.log', 'a']],
    $pipes2,
    $child,
    $env
);
foreach ($state['procs'] as $proc) {
    if (is_resource($proc) === false) {
        refuse('a server could not be started');
    }
}
$deadline = microtime(true) + 15;
while ((portAnswers($port) === false || portAnswers($calendarPort) === false) && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false || portAnswers($calendarPort) === false) {
    refuse('a server never answered on port ' . $port . ' or ' . $calendarPort);
}

echo "One answer to \"which time zone?\", on the real pages and the real job — self-test (#604)\n";
echo 'web server process ' . (int) proc_get_status($state['procs'][0])['pid'] . ' on port ' . $port . ', calendar server process ' . (int) proc_get_status($state['procs'][1])['pid']
    . ' on port ' . $calendarPort . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

/** Sign one account in through the real sign-in page; returns its cookie jar. */
function signIn(string $who): string
{
    global $child, $sep, $password;
    $file = $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
    $tok  = token(req($file, 'GET', '/login')['body']);
    $in   = req($file, 'POST', '/login', ['csrf_token' => $tok, 'username' => 'selftest-zones-' . $who, 'password' => $password]);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-zones-{$who} did not work (" . said($in) . ')');
    }

    return $file;
}

$adminJar  = signIn('admin');
$memberJar = signIn('member');

// =============================================================================
// M. MIGRATION 217 ON REAL ROWS
// =============================================================================
head('M. Migration 217 on an older database\'s events');
// An older database: the removed column back, with the default it had, and
// the column that stays given its old default too. The record of 217 is
// taken out, as on a database that has never run it.
runSql(
    "ALTER TABLE `tblEvents` ADD COLUMN `eventTimezone` VARCHAR(64) NOT NULL DEFAULT 'Europe/London' COMMENT 'IANA timezone for event display (#238)';"
    . "ALTER TABLE `tblEvents` MODIFY COLUMN `timezone` VARCHAR(50) NOT NULL DEFAULT 'Europe/London';"
    . "DELETE FROM `tblMigrations` WHERE `filename` = '217_one_time_zone_answer.sql';"
);
/** eventID => [what it stands for, timezone before, eventTimezone before, timezone expected after] */
$olderEvents = [
    960401 => ['made by the importer: the two columns agree', 'America/New_York', 'America/New_York', 'America/New_York'],
    960402 => ['made in the portal: the form\'s zone, and the old column at its default', 'Pacific/Auckland', 'Europe/London', 'Pacific/Auckland'],
    960403 => ['text that is not a zone, typed into the old free-text box', 'London', 'Europe/London', 'London'],
    // The form, saved with its zone box emptied, stored nothing; the old column then held only its DEFAULT.
    // Nothing is copied out of it: such an event stays as it is, to be listed for an administrator.
    960404 => ['the zone box emptied on the old form: nothing in the column that stays, the default in the one that goes', '', 'Europe/London', ''],
    960405 => ['only spaces in the column that stays', '   ', 'Europe/London', '   '],
    960406 => ['an old name PHP still opens', 'US/Eastern', 'Europe/London', 'US/Eastern'],
];
foreach ($olderEvents as $id => [, $zone, $old]) {
    q(
        "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, eventTimezone, status, isPublic, isDeleted) VALUES (?, 1, ?, ?, ?, ?, ?, ?, 'published', 1, 0)",
        'issssss', [$id, 'selftest-zones-older-' . $id, 'Selftest older event ' . $id, clockIn(60, '10:00:00'), clockIn(60, '11:00:00'), $zone, $old]
    );
}
$eventsBefore = (int) one('SELECT COUNT(*) AS n FROM tblEvents')['n'];
runMigration217();
$after = [];
foreach ($olderEvents as $id => [$what, , , $expected]) {
    $after[$id] = zoneOfEvent($id);
}
$wrong = array_filter(array_keys($olderEvents), static fn (int $id): bool => $after[$id] !== $olderEvents[$id][3]);
ok(
    'M01 every event keeps exactly what its `timezone` held: a real zone, an old name, text that is no zone at all, and NOTHING where the form\'s box had been emptied (the removed column\'s default, "Europe/London", is not copied into it)',
    $wrong === [],
    'wrong after the migration: ' . json_encode(array_map(static fn (int $id): array => [$olderEvents[$id][0], 'expected ' . $olderEvents[$id][3], 'got ' . $after[$id]], array_values($wrong)))
);
$column = one("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEvents' AND COLUMN_NAME = 'eventTimezone'");
$stays  = one("SELECT COLUMN_DEFAULT, IS_NULLABLE, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEvents' AND COLUMN_NAME = 'timezone'");
ok(
    'M02 the second column is gone, and the one that stays says what it is and has no default',
    (int) $column['n'] === 0 && $stays['COLUMN_DEFAULT'] === null && $stays['IS_NULLABLE'] === 'NO' && str_contains((string) $stays['COLUMN_COMMENT'], 'The ONE column an event keeps its zone in') === true,
    'eventTimezone columns: ' . $column['n'] . '; timezone: ' . json_encode($stays)
);
$refused = '';
try {
    q("INSERT INTO tblEvents (siteID, eventSlug, eventName, startDateTime) VALUES (1, 'selftest-zones-no-zone', 'Selftest no zone', ?)", 's', [clockIn(61, '10:00:00')]);
} catch (mysqli_sql_exception $e) {
    $refused = $e->getCode() . ': ' . $e->getMessage();
}
ok(
    'M03 a writer that names no zone is REFUSED by the database (it used to be given "Europe/London" without a word)',
    str_starts_with($refused, '1364') === true && one("SELECT eventID FROM tblEvents WHERE eventSlug = 'selftest-zones-no-zone'") === null,
    $refused === '' ? 'the row went in, with the zone ' . json_encode(one("SELECT timezone FROM tblEvents WHERE eventSlug = 'selftest-zones-no-zone'")) : $refused
);
$snapshot = static fn (): string => hash('sha256', json_encode([
    q('SELECT eventID, timezone FROM tblEvents ORDER BY eventID'), q('SELECT siteID, timezone FROM tblSites ORDER BY siteID'),
    q('SELECT siteID, settingKey, settingValue FROM tblSettings ORDER BY settingKey, siteID'), q('SELECT filename FROM tblMigrations ORDER BY filename'),
]));
$onceHash = $snapshot();
runMigration217();
ok(
    'M04 run a second time, the migration changes nothing: the same events, organisations, settings and records, and the same number of rows',
    $snapshot() === $onceHash && (int) one('SELECT COUNT(*) AS n FROM tblEvents')['n'] === $eventsBefore
        && (int) one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt'")['n'] === 1
        && (int) one("SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = '217_one_time_zone_answer.sql'")['n'] === 1,
    'events ' . one('SELECT COUNT(*) AS n FROM tblEvents')['n'] . ' (were ' . $eventsBefore . '); mark rows ' . one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt'")['n']
);

// =============================================================================
// O. AN ORGANISATION'S ZONE, IN THE 13 COMBINATIONS
// =============================================================================
head('O. An organisation\'s zone: before the migration and after it, nothing an ordinary page shows moves');
// Certificate checking "off", with a moment beside it: the admin dashboard,
// the e-mail page and the health report then each show that moment in the
// organisation's zone. Nothing is sent by this.
$mailMoment = time() - 86400 * 3 - 4000;
setting('mail.smtp.certCheck', 'off');
setting('mail.smtp.certCheckOffAt', gmdate('Y-m-d H:i:s', $mailMoment));

/**
 * The zone the UNCHANGED portal's start-up answered, by the rule it used
 * (bootstrap.php at commit 20f1303), written out again here and NOT taken
 * from the portal: the organisation's own setting row when there is one,
 * else the installation's; and that text only when it is an exact name from
 * PHP's main list, else UTC.
 */
function oldStartUpZone(?string $installation, ?string $own): string
{
    $text = $own ?? $installation;

    return ($text !== null && $text !== '' && in_array($text, timezone_identifiers_list(), true) === true) ? $text : 'UTC';
}

/** What migration 217 must leave in the organisation's row for that combination. */
function expectedRowText(?string $installation, ?string $own): string
{
    $text = $own ?? $installation;

    return ($text === null || trim($text, ' ') === '') ? 'UTC' : $text;
}

/** The four places an administrator can read the organisation's zone from, each as the zone it is using. */
function zonesOnPages(string $jar, int $moment, string $healthToken): array
{
    $info = req($jar, 'GET', '/admin/system-info');
    tz_find('/Default time zone\s+(\S+)/', words($info['body']), $php);
    $seen = ['Server Information (PHP\'s zone, set by start-up)' => $php[1] ?? '(not found: ' . said($info) . ')'];

    $health = json_decode(req('', 'GET', '/cron/health?token=' . $healthToken)['body'], true);
    $pages  = [
        'the admin dashboard' => words(req($jar, 'GET', '/admin')['body']),
        'the e-mail page'     => words(req($jar, 'GET', '/admin/integrations/email')['body']),
        'the health report'   => (string) ($health['probes']['Mail queue']['detail'] ?? '(no mail queue line)'),
    ];
    foreach ($pages as $name => $text) {
        // The moment is written "13 Oct 2026, 09:12 EDT". Which zone tells it that way?
        $seen[$name] = '(the moment is not on it)';
        foreach (array_unique(['UTC', ORG_ZONE, EVENT_ZONE, 'America/Santiago', 'Asia/Tokyo']) as $zone) {
            if (str_contains($text, 'set on ' . shownIn($moment, $zone)) === true) {
                $seen[$name] = $zone;
                break;
            }
        }
    }

    return $seen;
}

/** letter => [what it is, the organisation's row, the installation's setting, the organisation's own setting] (null = no such row) */
$combinations = [
    'a' => ['a fresh install: nobody has set either', 'UTC', 'UTC', null],
    'b' => ['only the setting was set', 'UTC', ORG_ZONE, null],
    'c' => ['only Admin, Organisations was set', EVENT_ZONE, 'UTC', null],
    'd' => ['both set, to different zones', EVENT_ZONE, ORG_ZONE, null],
    'e' => ['both set, to the same zone', ORG_ZONE, ORG_ZONE, null],
    'f' => ['the organisation\'s own setting row differs from the installation\'s', 'UTC', EVENT_ZONE, ORG_ZONE],
    'g' => ['the organisation\'s own setting row is there but empty', 'UTC', ORG_ZONE, ''],
    'h' => ['the setting holds an old name for a zone', 'UTC', 'US/Eastern', null],
    'i' => ['the setting is in other capitals', 'UTC', 'pacific/auckland', null],
    'j' => ['the setting has a space in front', 'UTC', ' ' . ORG_ZONE, null],
    'k' => ['the setting is not a zone at all', 'UTC', 'Auckland', null],
    'l' => ['there is no setting row; Organisations was set', EVENT_ZONE, null, null],
    'm' => ['the organisation\'s row is empty; the setting was set', null, ORG_ZONE, null],
];
$settingsRowsAfter = [];
foreach ($combinations as $letter => [$what, $row, $installation, $own]) {
    // BEFORE: a database that has not been through migration 217.
    q("DELETE FROM tblSettings WHERE settingKey IN ('site.timezone', 'site.timezoneKeptAt')");
    orgZone(1, $row);
    foreach ([[null, $installation], [1, $own]] as [$site, $value]) {
        if ($value !== null) {
            q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, 'site.timezone', ?, 'UTC', 0)", 'is', [$site, $value]);
        }
    }
    $expected = oldStartUpZone($installation, $own);
    $before   = zonesOnPages($adminJar, $mailMoment, $healthToken);
    ok(
        'O-' . $letter . '1 ' . $what . ': with the new files in place and the migration NOT yet run, all four places still say ' . $expected . ', the zone the unchanged portal\'s start-up answered',
        array_values(array_unique($before)) === [$expected],
        json_encode($before, JSON_UNESCAPED_SLASHES)
    );

    runMigration217();
    $afterPages = zonesOnPages($adminJar, $mailMoment, $healthToken);
    $rowText    = zoneOfOrg(1);
    $oldRows    = (int) one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey = 'site.timezone'")['n'];
    ok(
        'O-' . $letter . '2 after the migration the four places say ' . $expected . ' still; the organisation\'s row holds ' . json_encode(expectedRowText($installation, $own))
            . ', the mark is there and the old setting is gone',
        array_values(array_unique($afterPages)) === [$expected] && $rowText === expectedRowText($installation, $own) && markIsThere() === true && $oldRows === 0,
        'pages ' . json_encode($afterPages, JSON_UNESCAPED_SLASHES) . '; row ' . json_encode($rowText) . '; mark ' . json_encode(markIsThere()) . '; old setting rows ' . $oldRows
    );
    $settingsRowsAfter[$letter] = (int) one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey LIKE 'site.timezone%'")['n'];
}
ok(
    'O14 after each of the 13 runs there is exactly ONE row left of the two settings (the mark), never two: the row for the whole installation has nothing in its organisation column, which is where a second copy used to slip in',
    array_values(array_unique($settingsRowsAfter)) === [1],
    json_encode($settingsRowsAfter)
);

// An administrator now chooses a zone at Admin, Organisations. A second run of
// the migration must not put the old answer back over it.
orgZone(1, 'Asia/Tokyo');
runMigration217();
ok(
    'O15 a zone chosen at Admin, Organisations AFTER the migration survives a second run of it (the second run finds the mark and leaves every organisation alone; without the mark it would find no setting and write UTC)',
    zoneOfOrg(1) === 'Asia/Tokyo' && zonesOnPages($adminJar, $mailMoment, $healthToken)['Server Information (PHP\'s zone, set by start-up)'] === 'Asia/Tokyo',
    'the row holds ' . json_encode(zoneOfOrg(1))
);

// The old setting, put back by hand after the migration, is not read.
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'site.timezone', ?, 'UTC', 0)", 's', [EVENT_ZONE]);
$withOldSetting = zonesOnPages($adminJar, $mailMoment, $healthToken);
ok(
    'O16 once the migration has run the old "site.timezone" setting is NOT read: put back by hand with another zone, it changes nothing on any of the four places',
    array_values(array_unique($withOldSetting)) === ['Asia/Tokyo'],
    json_encode($withOldSetting, JSON_UNESCAPED_SLASHES)
);
q("DELETE FROM tblSettings WHERE settingKey = 'site.timezone'");

// The one function, asked for the current organisation and for another one, in both states.
orgZone(1, ORG_ZONE);
orgZone(ORG_TWO, 'America/Santiago'); // each of the 13 runs above brought it into line too, with the installation's setting
$inRow = child(['task' => 'facts', 'sites' => [1, ORG_TWO]]);
ok(
    'O17 the ONE function answers for the organisation of the request and for another one, each from its own row; PHP\'s zone and the calendar importer use the same answer (giving retention is told "not known" whatever is stored: see O23)',
    $inRow['threw'] === '' && $inRow['php'] === ORG_ZONE && $inRow['zone']['1'] === ORG_ZONE && $inRow['zone'][(string) ORG_TWO] === 'America/Santiago'
        && $inRow['giving']['1'] === '' && $inRow['giving'][(string) ORG_TWO] === '' && $inRow['feeds']['1'] === ORG_ZONE
        && $inRow['feeds'][(string) ORG_TWO] === 'America/Santiago' && $inRow['facts']['1']['inRow'] === true,
    json_encode($inRow, JSON_UNESCAPED_SLASHES)
);
q("DELETE FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt'");
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'site.timezone', 'Asia/Tokyo', 'UTC', 0), (?, 'site.timezone', ?, 'UTC', 0)", 'is', [ORG_TWO, EVENT_ZONE]);
$inSetting = child(['task' => 'facts', 'sites' => [1, ORG_TWO]]);
ok(
    'O18 and on a database that has NOT run the migration it answers from the old setting for both: the installation\'s row for one, its own row for the other, and never from rows that are not yet in line',
    $inSetting['threw'] === '' && $inSetting['php'] === 'Asia/Tokyo' && $inSetting['zone']['1'] === 'Asia/Tokyo' && $inSetting['zone'][(string) ORG_TWO] === EVENT_ZONE
        && $inSetting['facts']['1']['inRow'] === false,
    json_encode($inSetting, JSON_UNESCAPED_SLASHES)
);
$healthPending = json_decode(req('', 'GET', '/cron/health?token=' . $healthToken)['body'], true);
ok(
    'O19 while the migration has not run, the health report says so in plain words',
    ($healthPending['probes']['Time zones']['state'] ?? '') === 'warn' && str_contains((string) ($healthPending['probes']['Time zones']['detail'] ?? ''), 'has not been through the time-zone upgrade') === true,
    json_encode($healthPending['probes']['Time zones'] ?? '(no such line)')
);
runMigration217();
ok(
    'O20 that migration run brings BOTH organisations into line at once, each to what its own pages were showing',
    zoneOfOrg(1) === 'Asia/Tokyo' && zoneOfOrg(ORG_TWO) === EVENT_ZONE && markIsThere() === true,
    'organisation 1: ' . zoneOfOrg(1) . '; the second: ' . zoneOfOrg(ORG_TWO)
);
orgZone(ORG_TWO, 'Auckland');
$cautious = child(['task' => 'facts', 'sites' => [ORG_TWO]]);
ok(
    'O21 for stored text that is not a zone name the answer is UTC, it is not "in doubt" (PHP cannot open it either), and giving retention is told "not known"',
    $cautious['threw'] === '' && $cautious['zone'][(string) ORG_TWO] === 'UTC' && $cautious['giving'][(string) ORG_TWO] === '' && $cautious['facts'][(string) ORG_TWO]['inDoubt'] === false,
    json_encode($cautious, JSON_UNESCAPED_SLASHES)
);
orgZone(ORG_TWO, 'America/Santiago');

// ---- the installer's "carry on with what is there": the install script over an OLDER database ----
// That choice loads today's install script over the database, then every migration. The install script must
// not write the mark into a database that held the old arrangement, or migration 217 takes every
// organisation for already brought into line, copies nothing and deletes the old setting.
$installScript = (string) file_get_contents($webSrc . $sep . '_sql' . $sep . 'full_schema.sql');
$carryOn       = [];
// (i) an older database told by its SETTING: the row says UTC, the pages were on another zone.
q("DELETE FROM tblSettings WHERE settingKey IN ('site.timezone', 'site.timezoneKeptAt')");
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'site.timezone', ?, 'UTC', 0), (?, 'site.timezone', 'Europe/Paris', 'UTC', 0)", 'si', [ORG_ZONE, ORG_TWO]);
orgZone(1, 'UTC');
orgZone(ORG_TWO, EVENT_ZONE);
runSql($installScript);
$carryOn['setting: mark after the install script'] = markIsThere();
runMigration217();
$carryOn['setting: organisations after migration 217'] = [zoneOfOrg(1), zoneOfOrg(ORG_TWO), markIsThere()];
// (ii) an older database told only by its COLUMN: somebody had deleted the setting, so the pages were on UTC.
q("DELETE FROM tblSettings WHERE settingKey IN ('site.timezone', 'site.timezoneKeptAt')");
runSql("ALTER TABLE `tblEvents` ADD COLUMN `eventTimezone` VARCHAR(64) NOT NULL DEFAULT 'Europe/London'");
orgZone(1, EVENT_ZONE);
runSql($installScript);
$carryOn['column: mark after the install script'] = markIsThere();
runMigration217();
$carryOn['column: organisation after migration 217'] = [zoneOfOrg(1), markIsThere()];
// (iii) a database that never held either: the install script writes the mark itself.
q("DELETE FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt'");
runSql($installScript);
$carryOn['neither: mark after the install script'] = markIsThere();
ok(
    'O22 the installer\'s "carry on" path: the install script writes NO mark into a database that still has the old setting or the old column, so migration 217 then brings each organisation to the zone its pages showed (it used to find the mark, copy nothing and delete the setting: pages went from Auckland to UTC); a database with neither gets the mark from the install script',
    $carryOn === [
        'setting: mark after the install script' => false, 'setting: organisations after migration 217' => [ORG_ZONE, 'Europe/Paris', true],
        'column: mark after the install script' => false, 'column: organisation after migration 217' => ['UTC', true],
        'neither: mark after the install script' => true,
    ],
    json_encode($carryOn, JSON_UNESCAPED_SLASHES)
);
orgZone(ORG_TWO, 'America/Santiago');

// ---- giving retention: a name is never cleared SOONER than on the unchanged portal ----
// The independent check's seven pairs of stored zones and three moments. "Today" on the unchanged portal was the
// date in the organisation's ROW zone (worked out here by that rule, not taken from the portal). A LATER date
// means clearing sooner. Now the date is the last place on Earth's, in the gap and after the migration alike.
$givingPairs = [
    'b: row UTC, setting Pacific/Auckland' => ['UTC', 'Pacific/Auckland'], 'c: row America/New_York, setting UTC' => ['America/New_York', 'UTC'],
    'd: row America/Los_Angeles, setting Asia/Tokyo' => ['America/Los_Angeles', 'Asia/Tokyo'], 'e: both Europe/Berlin' => ['Europe/Berlin', 'Europe/Berlin'],
    'h: row UTC, setting US/Eastern (an old name)' => ['UTC', 'US/Eastern'], 'x: row US/Eastern (an old name), setting UTC' => ['US/Eastern', 'UTC'],
    'y: row Asia/Tokyo, setting "Tokyo" (not a zone)' => ['Asia/Tokyo', 'Tokyo'],
];
$givingMoments = ['2027-03-31 11:30:00', '2027-03-31 23:30:00', '2027-04-01 03:30:00']; // given to the function as "now": no clock is read
$oldNames      = array_flip(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC));
$dateIn        = static fn (string $moment, string $zone): string => (new DateTimeImmutable($moment, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone))->format('Y-m-d');
$sooner        = [];
$notLastPlace  = [];
$compared      = 0;
foreach ($givingPairs as $pair => [$row, $setting]) {
    q("DELETE FROM tblSettings WHERE settingKey IN ('site.timezone', 'site.timezoneKeptAt')");
    q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'site.timezone', ?, 'UTC', 0)", 's', [$setting]);
    orgZone(1, $row);
    $states = ['in the gap' => child(['task' => 'giving', 'site' => 1, 'moments' => $givingMoments])];
    runMigration217();
    $states['after the migration'] = child(['task' => 'giving', 'site' => 1, 'moments' => $givingMoments]);
    foreach ($states as $when => $got) {
        foreach ($givingMoments as $moment) {
            $before = $dateIn($moment, isset($oldNames[$row]) === true ? $row : 'Etc/GMT+12');
            $now    = (string) ($got['today'][$moment] ?? 'no answer: ' . ($got['threw'] ?? ''));
            $compared++;
            if ($now > $before) {
                $sooner[] = $pair . ', ' . $when . ', at ' . $moment . ' UTC: ' . $now . ' (was ' . $before . ')';
            }
            if (($got['zone'] ?? null) !== '' || $now !== $dateIn($moment, 'Etc/GMT+12')) {
                $notLastPlace[] = $pair . ', ' . $when . ', at ' . $moment . ' UTC: zone ' . json_encode($got['zone'] ?? null) . ', today ' . $now;
            }
        }
    }
}
ok(
    'O23 giving retention\'s "today" never arrives SOONER than on the unchanged portal, in the gap or after the migration: 7 pairs of stored zones at 3 moments (' . $compared . ' comparisons). It is the date at the last place on Earth to reach one, whatever is stored, so a donor\'s name is cleared late (by up to 26 hours, in the zones furthest ahead of UTC) and never early',
    $compared === 42 && $sooner === [] && $notLastPlace === [],
    count($sooner) . ' sooner: ' . json_encode(array_slice($sooner, 0, 4)) . '; ' . count($notLastPlace) . ' not the last place: ' . json_encode(array_slice($notLastPlace, 0, 3))
);

// ---- the gap: new files in place, migration 217 not yet run ----
q("DELETE FROM tblSettings WHERE settingKey IN ('site.timezone', 'site.timezoneKeptAt')");
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'site.timezone', ?, 'UTC', 0)", 's', [EVENT_ZONE]);
orgZone(1, 'UTC');
$gapPage  = req($adminJar, 'GET', '/admin/sites')['body'];
$orgOne   = one('SELECT siteName, siteKey, hostPattern, logoPath, faviconPath, primaryColor, copyrightOrg FROM tblSites WHERE siteID = 1') ?? [];
$gapSave  = post($adminJar, '/admin/sites', '/admin/sites/save', array_merge(array_map(static fn ($v): string => (string) $v, $orgOne), ['siteID' => 1, 'siteName' => 'Selftest zones renamed in the gap', 'timezone' => 'Europe/Paris', 'isActive' => '1']));
$gapSaid  = flashAfter($adminJar, '/admin/sites');
$gapRow   = [zoneOfOrg(1), (string) one('SELECT siteName FROM tblSites WHERE siteID = 1')['siteName']];
$oldRowId = (int) one("SELECT settingID FROM tblSettings WHERE settingKey = 'site.timezone' AND siteID IS NULL")['settingID'];
$gapList  = req($adminJar, 'GET', '/settings')['body'];
post($adminJar, '/settings', '/settings/save', ['settingID' => $oldRowId, 'settingKey' => 'site.timezone', 'settingValue' => 'Asia/Tokyo']);
$gapRefusal = flashAfter($adminJar, '/settings');
$gapSetting = (string) one("SELECT settingValue FROM tblSettings WHERE settingKey = 'site.timezone' AND siteID IS NULL")['settingValue'];
runMigration217();
q('UPDATE tblSites SET siteName = ? WHERE siteID = 1', 's', [(string) ($orgOne['siteName'] ?? 'Portal')]);
$afterPage = req($adminJar, 'GET', '/admin/sites')['body'];
ok(
    'O24 in the gap, Admin, Organisations does NOT take a time zone: it says to run the upgrade first, with a link to it, shows the zone the portal is really using and disables the box; a zone posted anyway is not stored (the name posted with it is), and the message says so. The Settings page\'s refusal says the same, which is true in that state. After the migration both pages are back to normal',
    tz_find('#id="zones-wait-for-upgrade">.*?href="/admin/upgrade"#s', $gapPage) === 1 && tz_find('#<select[^>]*id="formTimezone"[^>]*disabled#', $gapPage) === 1
        && tz_find('#<dt class="col-5">Time zone</dt>\s*<dd class="col-7">\s*' . preg_quote(EVENT_ZONE, '#') . '\s*</dd>#', $gapPage) === 1
        && $gapRow === ['UTC', 'Selftest zones renamed in the gap'] && str_contains($gapSaid, 'Its time zone was NOT changed') === true && str_contains($gapSaid, 'Run it at Admin, Upgrade') === true
        && $gapSetting === EVENT_ZONE && str_contains($gapRefusal, 'has not yet run their database upgrade: run it at Admin, Upgrade') === true && str_contains($gapRefusal, 'not a setting any more') === false
        && str_contains($gapList, 'Run the upgrade first (Admin, Upgrade)') === true
        && zoneOfOrg(1) === EVENT_ZONE && str_contains($afterPage, 'zones-wait-for-upgrade') === false && tz_find('#<select[^>]*id="formTimezone"[^>]*required#', $afterPage) === 1,
    'the notice and its link: ' . json_encode(tz_find('#id="zones-wait-for-upgrade">.*?href="/admin/upgrade"#s', $gapPage) === 1) . '; the box disabled: ' . json_encode(tz_find('#<select[^>]*id="formTimezone"[^>]*disabled#', $gapPage) === 1)
        . '; ' . said($gapSave) . ' "' . $gapSaid . '"; row and name ' . json_encode($gapRow) . '; the old setting ' . json_encode($gapSetting) . '; the refusal: "' . $gapRefusal . '"; after: row ' . json_encode(zoneOfOrg(1))
);
setting('mail.smtp.certCheck', 'on');
setting('mail.smtp.certCheckOffAt', '');

// ---- giving retention: the "Confirmed on" date is the organisation's OWN date ----
// It is a date people read (the settings page, and the audit line written when a name is cleared), so it is
// today where the organisation IS. The date that DECIDES a clearing stays the last place on Earth's. The second
// check found the first taken from the second: a day early in Auckland and Kiritimati at almost every hour,
// and in London from midnight until 12:00 UTC. Each moment is GIVEN to the save as "now" (no clock is read),
// and both dates beside it were worked out by hand, not by the portal.
$confirmCases = [ // zone, the moment in UTC, the organisation's own date then, the last place on Earth's date then
    ['Pacific/Kiritimati',  '2027-01-13 13:04:00', '2027-01-14', '2027-01-13'], // 03:04 on the 14th there
    ['Pacific/Kiritimati',  '2027-01-13 10:30:00', '2027-01-14', '2027-01-12'], // 00:30 on the 14th there: two dates apart
    ['Pacific/Kiritimati',  '2027-01-13 09:00:00', '2027-01-13', '2027-01-12'], // 23:00 on the 13th there
    ['Pacific/Auckland',    '2027-01-13 13:04:00', '2027-01-14', '2027-01-13'], // 02:04 on the 14th there
    ['Europe/London',       '2027-01-13 06:41:00', '2027-01-13', '2027-01-12'], // before 12:00 UTC
    ['Europe/London',       '2027-01-13 11:59:00', '2027-01-13', '2027-01-12'], // the last minute before
    ['Europe/London',       '2027-01-13 12:01:00', '2027-01-13', '2027-01-13'], // after 12:00 UTC the two agree
    ['Europe/London',       '2027-07-13 23:30:00', '2027-07-14', '2027-07-13'], // summer: 00:30 on the 14th in London
    ['America/Los_Angeles', '2027-01-13 06:41:00', '2027-01-12', '2027-01-12'], // behind UTC: 22:41 on the 12th there
];
$confirmWrong = [];
$confirmApart = 0;
foreach ($confirmCases as [$zoneName, $moment, $ownDate, $lastPlaceDate]) {
    orgZone(ORG_TWO, $zoneName);
    $ticked = child(['task' => 'confirm', 'site' => ORG_TWO, 'moment' => $moment]);
    $stored = (string) (one("SELECT settingValue FROM tblSettings WHERE settingKey = 'giving.retention.confirmedOn' AND siteID = ?", 'i', [ORG_TWO])['settingValue'] ?? '(no row)');
    $confirmApart += $ownDate !== $lastPlaceDate ? 1 : 0;
    if ($ticked['threw'] !== '' || ($ticked['saved'] ?? null) !== true || $stored !== $ownDate || ($ticked['deciding'] ?? '') !== $lastPlaceDate) {
        $confirmWrong[] = $zoneName . ' at ' . $moment . ' UTC: stored ' . json_encode($stored) . ' (its own date is ' . $ownDate . '); the deciding date ' . json_encode($ticked['deciding'] ?? null)
            . ' (the last place\'s is ' . $lastPlaceDate . ')' . ($ticked['threw'] !== '' ? '; ' . $ticked['threw'] : '');
    }
}
q("DELETE FROM tblSettings WHERE settingKey LIKE 'giving.retention.%' AND siteID = ?", 'i', [ORG_TWO]);
ok(
    'O25 the "Confirmed on" date an administrator\'s tick stores is the organisation\'s OWN date: for one far ahead of UTC (Kiritimati, Auckland), for London before 12:00 UTC and just after midnight in summer, and for one behind UTC (' . count($confirmCases)
        . ' moments, ' . $confirmApart . ' of them with the two dates apart). The date that DECIDES a clearing is still the last place on Earth\'s at every one',
    $confirmWrong === [] && $confirmApart >= 6,
    count($confirmWrong) . ' wrong: ' . json_encode(array_slice($confirmWrong, 0, 4))
);

// From here on: the default organisation is in a zone that is not London's.
orgZone(1, ORG_ZONE);
orgZone(ORG_TWO, 'America/Santiago');

// =============================================================================
// W. EVERY WRITER STORES A REAL ZONE
// =============================================================================
head('W. Every writer of an event stores a real zone (the organisation is in ' . ORG_ZONE . ')');
/** label => eventID, filled as each writer makes its event; read back in part R. */
$made = [];
$newest = static fn (string $nameLike): int => (int) (one('SELECT eventID FROM tblEvents WHERE eventName LIKE ? ORDER BY eventID DESC LIMIT 1', 's', [$nameLike])['eventID'] ?? 0);

// ---- the event form ---------------------------------------------------------
$formPage = req($adminJar, 'GET', '/calendar/manage')['body'];
tz_find('#<select[^>]*name="timezone"[^>]*id="eventTimezone-new"[^>]*>(.*?)</select>#s', $formPage, $zoneBox);
ok(
    'W01 the event form offers a LIST of real zone names where there used to be a free-text box, and a new event starts on the organisation\'s own zone',
    isset($zoneBox[1]) === true && substr_count($zoneBox[1], '<option') >= 400 && str_contains($zoneBox[1], '<option value="' . ORG_ZONE . '" selected>') === true
        && tz_find('#<input[^>]*name="timezone"#', $formPage) === 0,
    isset($zoneBox[1]) === true ? substr_count($zoneBox[1], '<option') . ' choices; the organisation\'s zone selected: ' . json_encode(str_contains($zoneBox[1], '<option value="' . ORG_ZONE . '" selected>')) : 'no list of zones on the form'
);
$formFields = static fn (string $name, int $day, array $more = []): array => array_merge([
    'action' => 'create', 'eventName' => $name, 'description' => '', 'startDateTime' => str_replace(' ', 'T', substr(clockIn($day, '19:00:00'), 0, 16)),
    'endDateTime' => str_replace(' ', 'T', substr(clockIn($day, '20:30:00'), 0, 16)), 'status' => 'published', 'isPublic' => '1',
], $more);

$answer = post($adminJar, '/calendar/manage', '/calendar/manage/save', $formFields('Selftest zones form chosen', 21, ['timezone' => EVENT_ZONE]));
$made['the event form, with a zone chosen'] = $newest('Selftest zones form chosen');
ok(
    'W02 the form: an event saved with a zone chosen is stored in THAT zone',
    $made['the event form, with a zone chosen'] > 0 && zoneOfEvent($made['the event form, with a zone chosen']) === EVENT_ZONE,
    said($answer) . '; stored ' . json_encode(zoneOfEvent($made['the event form, with a zone chosen']))
);
$answer = post($adminJar, '/calendar/manage', '/calendar/manage/save', $formFields('Selftest zones form none', 22));
$made['the event form, with no zone given'] = $newest('Selftest zones form none');
ok(
    'W03 the form: with no zone given, a new event takes its ORGANISATION\'s zone (it used to take "Europe/London")',
    $made['the event form, with no zone given'] > 0 && zoneOfEvent($made['the event form, with no zone given']) === ORG_ZONE,
    said($answer) . '; stored ' . json_encode(zoneOfEvent($made['the event form, with no zone given']))
);
$answer   = post($adminJar, '/calendar/manage', '/calendar/manage/save', $formFields('Selftest zones form refused', 23, ['timezone' => 'London']));
$flash    = flashAfter($adminJar, '/calendar/manage');
ok(
    'W04 the form REFUSES a zone that is not a zone name, in words that name the box, and saves nothing',
    $newest('Selftest zones form refused') === 0 && str_contains($flash, 'The "Time zone" box holds "London"') === true && str_contains($flash, 'Nothing was saved') === true,
    said($answer) . '; the page said: ' . $flash
);
// An existing event with an old name PHP still opens: opening and saving its form changes nothing.
q('UPDATE tblEvents SET timezone = ? WHERE eventID = ?', 'si', ['US/Eastern', 960406]);
$editPage = req($adminJar, 'GET', '/calendar/manage?edit=960406')['body'];
$update   = static fn (int $id, array $more): array => array_merge([
    'action' => 'update', 'eventID' => $id, 'eventName' => 'Selftest older event ' . $id, 'description' => '', 'startDateTime' => str_replace(' ', 'T', substr(clockIn(60, '10:00:00'), 0, 16)),
    'endDateTime' => str_replace(' ', 'T', substr(clockIn(60, '11:00:00'), 0, 16)), 'status' => 'published', 'isPublic' => '1',
], $more);
post($adminJar, '/calendar/manage?edit=960406', '/calendar/manage/save', $update(960406, ['timezone' => 'US/Eastern']));
$kept = zoneOfEvent(960406);
post($adminJar, '/calendar/manage?edit=960406', '/calendar/manage/save', $update(960406, ['timezone' => 'Narnia/Lamp-post']));
ok(
    'W05 an event that already holds an OLD name PHP still opens ("US/Eastern") is offered it "as saved" and keeps it on Save; a zone that is no zone is refused for an existing event too, and its stored zone is left alone',
    str_contains($editPage, '<option value="US/Eastern" selected>US/Eastern (as saved)</option>') === true && $kept === 'US/Eastern' && zoneOfEvent(960406) === 'US/Eastern'
        && str_contains(flashAfter($adminJar, '/calendar/manage'), 'not a time zone name the portal knows') === true,
    'offered as saved: ' . json_encode(str_contains($editPage, '(as saved)')) . '; after the first save ' . json_encode($kept) . '; after the refused one ' . json_encode(zoneOfEvent(960406))
);

// An event at a time the ORGANISATION's clock skips, on the night its clocks go forward, in a zone where that
// time exists. Its form must show the stored time, not the time an hour later that the organisation's clock
// would read it as: pressing Save would then store the wrong hour.
$orgSteps  = (new DateTimeZone(ORG_ZONE))->getTransitions(time(), time() + 500 * 86400); // a horizon to search in
$skipped   = '';
for ($i = 1; $i < count($orgSteps) && $skipped === ''; $i++) {
    if ($orgSteps[$i]['offset'] > $orgSteps[$i - 1]['offset']) {
        $skipped = gmdate('Y-m-d H:i:s', $orgSteps[$i]['ts'] + $orgSteps[$i - 1]['offset'] + 1800);
    }
}
if ($skipped === '') {
    refuse('the organisation\'s zone, ' . ORG_ZONE . ', does not put its clocks forward in the next 500 days');
}
q(
    "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) VALUES (960460, 1, 'selftest-zones-skipped-hour', 'Selftest zones skipped hour', ?, ?, ?, 'draft', 0, 0)",
    'sss', [$skipped, $skipped, EVENT_ZONE]
);
$skippedForm = req($adminJar, 'GET', '/calendar/manage?edit=960460')['body'];
tz_find('#name="startDateTime"[^>]*value="([^"]*)"#s', $skippedForm, $shownStart);
tz_find('#name="endDateTime"[^>]*value="([^"]*)"#s', $skippedForm, $shownEnd);
ok(
    'W05b the edit form shows a stored time AS STORED, for a start and an end at ' . substr($skipped, 11, 5) . ' on the night the organisation\'s own clock skips that hour (read through that clock it came out an hour later, and Save stored it)',
    ($shownStart[1] ?? '') === str_replace(' ', 'T', substr($skipped, 0, 16)) && ($shownEnd[1] ?? '') === str_replace(' ', 'T', substr($skipped, 0, 16))
        && (new DateTimeImmutable($skipped, new DateTimeZone(ORG_ZONE)))->format('Y-m-d H:i:s') !== $skipped,
    'stored ' . $skipped . '; the form shows start ' . json_encode($shownStart[1] ?? null) . ', end ' . json_encode($shownEnd[1] ?? null)
);

// ---- a member's suggestion ----------------------------------------------------
$answer = post($memberJar, '/calendar/submit', '/calendar/submit-save', [
    'eventName' => 'Selftest zones suggestion', 'startDateTime' => str_replace(' ', 'T', substr(clockIn(24, '19:00:00'), 0, 16)),
    'endDateTime' => str_replace(' ', 'T', substr(clockIn(24, '20:30:00'), 0, 16)), 'locationName' => '', 'description' => '',
]);
$made['a member\'s suggestion'] = $newest('Selftest zones suggestion');
ok(
    'W06 a member\'s suggested event is stored in the ORGANISATION\'s zone (it used to be stored as "Europe/London", written into the statement)',
    $made['a member\'s suggestion'] > 0 && zoneOfEvent($made['a member\'s suggestion']) === ORG_ZONE
        && (string) one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$made['a member\'s suggestion']])['startDateTime'] === clockIn(24, '19:00:00'),
    said($answer) . '; stored ' . json_encode(one('SELECT timezone, startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$made['a member\'s suggestion']]))
);

// ---- the file import ------------------------------------------------------------
$csv = $child . $sep . 'events.csv';
// 🔢 ⚠️ THIS FILE DEPENDS ON AUTO-INCREMENT LANDING ON PARTICULAR IDs, and
//    that is not obvious anywhere else in it. Later blocks INSERT with explicit
//    `eventID` values (960470 upwards), while the blocks above let MySQL choose
//    — so every row an earlier check creates shifts what comes next, and when
//    the two meet the run dies with "Duplicate entry '960470' for key
//    'tblEvents.PRIMARY'".
//
//    Round 7's six checks happened to consume the last two spare slots.
//    Round 8's three took one more and the file stopped running — a real fault
//    in the test, found by adding to it rather than by reading it.
//
//    `withoutConsumingIds()` runs a block and puts the counter back, so a
//    check that creates and deletes its own rows is ID-NEUTRAL. Use it for any
//    new block that makes events, or the next person hits the same wall.
$withoutConsumingIds = static function (callable $block): void {
    $was = (int) (one(
        "SELECT AUTO_INCREMENT AS n FROM information_schema.tables "
        . "WHERE table_schema = DATABASE() AND table_name = 'tblEvents'"
    )['n'] ?? 0);
    $block();
    if ($was > 0) {
        q('ALTER TABLE tblEvents AUTO_INCREMENT = ' . $was);
    }
};

file_put_contents($csv, "eventName,startDateTime,endDateTime,status\n\"Selftest zones file import\"," . clockIn(25, '19:00:00') . ',' . clockIn(25, '20:30:00') . ",published\n");
$importPage = req($adminJar, 'GET', '/calendar/manage/import')['body'];
$preview    = req($adminJar, 'POST', '/calendar/manage/import', ['csrf_token' => token($importPage), 'action' => 'preview'], ['files' => ['csv_file' => $csv]]);
$confirm    = req($adminJar, 'POST', '/calendar/manage/import', ['csrf_token' => token($preview['body']) !== '' ? token($preview['body']) : token($importPage), 'action' => 'confirm']);
$made['the file import'] = $newest('Selftest zones file import');
ok(
    'W07 an event made by file import is stored in the ORGANISATION\'s zone (it used to take the column\'s default, "Europe/London")',
    $made['the file import'] > 0 && zoneOfEvent($made['the file import']) === ORG_ZONE,
    'preview ' . said($preview) . ', confirm ' . said($confirm) . '; stored ' . json_encode(zoneOfEvent($made['the file import']))
);

// ══════════════════════════════════════════════════════════════════════════
// W07b-W07d — THE CSV IMPORT IS THE FOURTH WRITE PATH (#777 round 8)
// ══════════════════════════════════════════════════════════════════════════
// 🚨 W07 ABOVE ASSERTS ONLY THE ZONE, so the whole of the import's #777 fix
//    could be reverted in ONE LINE with every guard green -- measured in round
//    8: `$clock = null;` inside `$fmtClock` restores the pre-round-6 round trip
//    and leaves 65 audit scripts, 81 clock checks AND this file's 140 all
//    green. **Round 7 closed the three write paths it was handed and left the
//    fourth**, which is the same shape as its own finding 1.
//
//    This import stores the ORGANISATION's zone, so the skipped hour is
//    Pacific/Auckland's — 2028-09-24 02:30, the same one W09h0 proves below.
$withoutConsumingIds(static function () use ($adminJar, $newest): void {
    $csvSkipStart = '2028-09-24 02:30:00';
    $csvSkipEnd   = '2028-09-24 03:45:00';
    $csvSkip      = tempnam(sys_get_temp_dir(), 'tzskip') . '.csv';
    file_put_contents($csvSkip, "eventName,startDateTime,endDateTime,status\n\"Selftest zones import skipped\"," . $csvSkipStart . ',' . $csvSkipEnd . ",published\n");
    $importPage2 = req($adminJar, 'GET', '/calendar/manage/import')['body'];
    $preview2    = req($adminJar, 'POST', '/calendar/manage/import', ['csrf_token' => token($importPage2), 'action' => 'preview'], ['files' => ['csv_file' => $csvSkip]]);
    $confirm2    = req($adminJar, 'POST', '/calendar/manage/import', ['csrf_token' => token($preview2['body']) !== '' ? token($preview2['body']) : token($importPage2), 'action' => 'confirm']);
    $skipImportId = $newest('Selftest zones import skipped');
    $skipImported = one('SELECT startDateTime, endDateTime FROM tblEvents WHERE eventID = ?', 'i', [$skipImportId]);
    ok(
        'W07b the CSV import stores a reading inside the hour that does not exist AS IT STANDS',
        $skipImportId > 0 && $skipImported === ['startDateTime' => $csvSkipStart, 'endDateTime' => $csvSkipEnd],
        'preview ' . said($preview2) . ', confirm ' . said($confirm2) . '; stored ' . json_encode($skipImported) . ', looked for ' . $csvSkipStart
    );
    q('DELETE FROM tblEvents WHERE eventID = ?', 'i', [$skipImportId]);
    @unlink($csvSkip);

    // An ordinary 45-minute event ACROSS the change, which the row validation used
    // to refuse because it compared two moments: the start's moment is 03:30 and
    // the end's is 03:15, so the end looked earlier than the start.
    $csvAcross = tempnam(sys_get_temp_dir(), 'tzacross') . '.csv';
    file_put_contents($csvAcross, "eventName,startDateTime,endDateTime,status\n\"Selftest zones import across\",2028-09-24 02:30:00,2028-09-24 03:15:00,published\n");
    $importPage3 = req($adminJar, 'GET', '/calendar/manage/import')['body'];
    $preview3    = req($adminJar, 'POST', '/calendar/manage/import', ['csrf_token' => token($importPage3), 'action' => 'preview'], ['files' => ['csv_file' => $csvAcross]]);
    $confirm3    = req($adminJar, 'POST', '/calendar/manage/import', ['csrf_token' => token($preview3['body']) !== '' ? token($preview3['body']) : token($importPage3), 'action' => 'confirm']);
    $acrossId    = $newest('Selftest zones import across');
    $acrossRow   = one('SELECT startDateTime, endDateTime FROM tblEvents WHERE eventID = ?', 'i', [$acrossId]);
    ok(
        'W07c an ordinary event spanning the change is IMPORTED, not refused as "end before start" — the validation compares readings as text now, not two moments',
        $acrossId > 0 && $acrossRow === ['startDateTime' => '2028-09-24 02:30:00', 'endDateTime' => '2028-09-24 03:15:00'],
        'preview ' . said($preview3) . ', confirm ' . said($confirm3) . '; stored ' . json_encode($acrossRow)
    );
    q('DELETE FROM tblEvents WHERE eventID = ?', 'i', [$acrossId]);
    @unlink($csvAcross);

    // And a genuinely backwards pair must STILL be refused, or W07c has simply
    // switched the check off.
    $csvBack = tempnam(sys_get_temp_dir(), 'tzback') . '.csv';
    file_put_contents($csvBack, "eventName,startDateTime,endDateTime,status\n\"Selftest zones import backwards\",2028-06-01 19:00:00,2028-06-01 18:00:00,published\n");
    $importPage4 = req($adminJar, 'GET', '/calendar/manage/import')['body'];
    $preview4    = req($adminJar, 'POST', '/calendar/manage/import', ['csrf_token' => token($importPage4), 'action' => 'preview'], ['files' => ['csv_file' => $csvBack]]);
    ok(
        'W07d a genuinely backwards pair is still REFUSED, so W07c did not just switch the check off',
        $newest('Selftest zones import backwards') === 0 && str_contains($preview4['body'], 'before startDateTime') === true,
        'preview ' . said($preview4) . '; made ' . $newest('Selftest zones import backwards')
    );
    @unlink($csvBack);

    // 🚨 W07d's FIXTURE IS TWO CLEAN ISO VALUES, so it only ever exercised one
    //    reading of the comparison. Round 9 measured the cost: with the loose
    //    half of the old two-branch check disabled in one word, all 143 checks
    //    here stayed green while a backwards loose pair imported. **A fixture
    //    that cannot reach a branch is not testing it.**
    //
    //    The branch is gone now -- the check asks `$fmtClock()` for both
    //    values, which is what storage uses -- so these two cases are what
    //    prove the single comparison works in BOTH formats. They were real
    //    faults until round 9: the first was REFUSED although it is a
    //    legitimate 45-minute event, and the second was ACCEPTED and stored
    //    with its end before its start.
    $csvMixed = tempnam(sys_get_temp_dir(), 'tzmixed') . '.csv';
    file_put_contents($csvMixed, "eventName,startDateTime,endDateTime,status\n\"Selftest zones import mixed\",2028-09-24 02:30:00,\"24 September 2028 3:15am\",published\n");
    $importPage5 = req($adminJar, 'GET', '/calendar/manage/import')['body'];
    $preview5    = req($adminJar, 'POST', '/calendar/manage/import', ['csrf_token' => token($importPage5), 'action' => 'preview'], ['files' => ['csv_file' => $csvMixed]]);
    $confirm5    = req($adminJar, 'POST', '/calendar/manage/import', ['csrf_token' => token($preview5['body']) !== '' ? token($preview5['body']) : token($importPage5), 'action' => 'confirm']);
    $mixedId     = $newest('Selftest zones import mixed');
    $mixedRow    = one('SELECT startDateTime, endDateTime FROM tblEvents WHERE eventID = ?', 'i', [$mixedId]);
    ok(
        'W07e one ISO cell and one LOOSE cell, forward: imported, not refused as "end before start"',
        $mixedId > 0 && $mixedRow === ['startDateTime' => '2028-09-24 02:30:00', 'endDateTime' => '2028-09-24 03:15:00'],
        'preview ' . said($preview5) . ', confirm ' . said($confirm5) . '; stored ' . json_encode($mixedRow)
    );
    q('DELETE FROM tblEvents WHERE eventID = ?', 'i', [$mixedId]);
    @unlink($csvMixed);

    $csvMixedBack = tempnam(sys_get_temp_dir(), 'tzmixedback') . '.csv';
    file_put_contents($csvMixedBack, "eventName,startDateTime,endDateTime,status\n\"Selftest zones import mixed backwards\",\"24 September 2028 3:15am\",2028-09-24 02:30:00,published\n");
    $importPage6 = req($adminJar, 'GET', '/calendar/manage/import')['body'];
    $preview6    = req($adminJar, 'POST', '/calendar/manage/import', ['csrf_token' => token($importPage6), 'action' => 'preview'], ['files' => ['csv_file' => $csvMixedBack]]);
    ok(
        'W07f one LOOSE cell and one ISO cell, BACKWARDS: refused, where it used to import and store an end before its start',
        $newest('Selftest zones import mixed backwards') === 0 && str_contains($preview6['body'], 'before startDateTime') === true,
        'preview ' . said($preview6) . '; made ' . $newest('Selftest zones import mixed backwards')
    );
    @unlink($csvMixedBack);
});

// ---- the events API ---------------------------------------------------------------
$api = static fn (array $body): array => req('', 'POST', '/api/events/create', (string) json_encode($body), ['bearer' => $apiKey, 'json' => true]);
$answer = $api(['eventName' => 'Selftest zones API none', 'startDateTime' => str_replace(' ', 'T', clockIn(26, '19:00:00')), 'endDateTime' => str_replace(' ', 'T', clockIn(26, '20:30:00')), 'status' => 'published']);
$made['the events API, with no zone given'] = $newest('Selftest zones API none');
ok(
    'W08 the events API: with no zone given the event takes its ORGANISATION\'s zone, and the time given is stored as that clock\'s reading',
    $made['the events API, with no zone given'] > 0 && zoneOfEvent($made['the events API, with no zone given']) === ORG_ZONE
        && (string) one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$made['the events API, with no zone given']])['startDateTime'] === clockIn(26, '19:00:00'),
    said($answer) . ' ' . substr($answer['body'], 0, 200) . '; stored ' . json_encode(one('SELECT timezone, startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$made['the events API, with no zone given']]))
);
// A time given WITH an offset is a moment: it is stored as the event's clock reads at that moment.
$givenMoment = momentOf(clockIn(27, '19:00:00'), EVENT_ZONE);
$answer      = $api(['eventName' => 'Selftest zones API chosen', 'startDateTime' => gmdate('Y-m-d\TH:i:s\Z', $givenMoment), 'endDateTime' => gmdate('Y-m-d\TH:i:s\Z', $givenMoment + 5400), 'timezone' => EVENT_ZONE, 'status' => 'published']);
$made['the events API, with a zone chosen'] = $newest('Selftest zones API chosen');
ok(
    'W09 the events API: a zone given is stored, and a start and an end given as moments (with "Z") are written as the readings of the EVENT\'s clock at those moments',
    $made['the events API, with a zone chosen'] > 0 && zoneOfEvent($made['the events API, with a zone chosen']) === EVENT_ZONE
        && one('SELECT startDateTime, endDateTime FROM tblEvents WHERE eventID = ?', 'i', [$made['the events API, with a zone chosen']]) === ['startDateTime' => clockIn(27, '19:00:00'), 'endDateTime' => clockIn(27, '20:30:00')],
    said($answer) . ' ' . substr($answer['body'], 0, 200) . '; stored ' . json_encode(one('SELECT timezone, startDateTime, endDateTime FROM tblEvents WHERE eventID = ?', 'i', [$made['the events API, with a zone chosen']]))
);
// A PLAIN time (no offset) with a zone that is not the organisation's: it is that zone's clock time, as it stands.
$answer  = $api(['eventName' => 'Selftest zones API plain', 'startDateTime' => str_replace(' ', 'T', clockIn(30, '19:00:00')), 'endDateTime' => str_replace(' ', 'T', clockIn(30, '20:30:00')), 'timezone' => EVENT_ZONE, 'status' => 'published']);
$plainId = $newest('Selftest zones API plain');
ok(
    'W09c the events API: a PLAIN time given with a zone that is not the organisation\'s is stored as it stands, on that zone\'s clock (it is not first read on the organisation\'s clock and then moved)',
    $plainId > 0 && one('SELECT timezone, startDateTime, endDateTime FROM tblEvents WHERE eventID = ?', 'i', [$plainId]) === ['timezone' => EVENT_ZONE, 'startDateTime' => clockIn(30, '19:00:00'), 'endDateTime' => clockIn(30, '20:30:00')],
    said($answer) . ' ' . substr($answer['body'], 0, 200) . '; stored ' . json_encode(one('SELECT timezone, startDateTime, endDateTime FROM tblEvents WHERE eventID = ?', 'i', [$plainId]))
);
// Changing an event's start through the API: the new time is written on the EVENT's clock, not on PHP's (the organisation's).
// 📌 THE END MOVES WITH THE START. Round 10 added the #786 check that refuses
//    a start moved past its own end, and it IMMEDIATELY caught this fixture
//    and W09g below: both pushed the start to 21:15 while the stored end was
//    20:30, so **the test had been storing events that end before they
//    begin** -- invisibly, because nothing compared them. Moving the end too
//    is what a real client does, and it leaves the question this check is
//    about (is the new start written on the EVENT's clock?) exactly as it was.
$movedTo    = momentOf(clockIn(27, '21:15:00'), EVENT_ZONE);
$movedEndTo = momentOf(clockIn(27, '22:45:00'), EVENT_ZONE);
$answer  = req('', 'POST', '/api/events/update?id=' . $made['the events API, with a zone chosen'], (string) json_encode(['id' => $made['the events API, with a zone chosen'], 'eventID' => $made['the events API, with a zone chosen'], 'startDateTime' => gmdate('Y-m-d\TH:i:s\Z', $movedTo), 'endDateTime' => gmdate('Y-m-d\TH:i:s\Z', $movedEndTo)]), ['bearer' => $apiKey, 'json' => true]);
$nowAt   = (string) (one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$made['the events API, with a zone chosen']])['startDateTime'] ?? '');
ok(
    'W09b the events API\'s update: a new start given as a moment is written as the reading of the EVENT\'s clock (' . EVENT_ZONE . '), not of the organisation\'s; the event keeps its zone',
    $nowAt === clockIn(27, '21:15:00') && zoneOfEvent($made['the events API, with a zone chosen']) === EVENT_ZONE,
    said($answer) . ' ' . substr($answer['body'], 0, 200) . '; stored ' . json_encode($nowAt) . ', looked for ' . clockIn(27, '21:15:00')
);
q('UPDATE tblEvents SET startDateTime = ? WHERE eventID = ?', 'si', [clockIn(27, '19:00:00'), $made['the events API, with a zone chosen']]); // back, for the readers below
// ══════════════════════════════════════════════════════════════════════════
// W09d-W09h — THE HOUR THAT DOES NOT EXIST, over the real endpoints (#777)
// ══════════════════════════════════════════════════════════════════════════
// 🚨 WHY THESE EXIST: EVERY CHECK ABOVE PASSES ON THE BROKEN CODE. They are
//    all built from `clockIn(25..30, '19:00:00')` -- an ordinary evening, 25
//    to 30 days from whenever the test is run -- and on an ordinary day the
//    old round trip and the new one agree exactly. Measured in round 7 of
//    #777: reverting all three write paths at once left `php -l` clean, all 65
//    audit scripts green, `clock-change-selftest.php` at 71 of 71 AND this
//    whole file green.
//
//    **#549's own trap, which this file was written to close, reappearing
//    inside it:** a test written on an ordinary day passes whatever the code
//    does. Round 6 recorded the same shape one level down (its section I pins
//    `I18n`, and nothing pinned the CALLERS) and did not close it.
//
//    So these drive the real endpoints with a reading inside an hour that does
//    not exist. 2028-03-12 02:30 is skipped in America/New_York (EVENT_ZONE);
//    a FIXED date, so this cannot rot, and far enough ahead to stay a future
//    event for years. The organisation's zone is Pacific/Auckland, whose own
//    transition is in September, so the two cannot mask each other.
$skippedClock = '2028-03-12 02:30:00';
$skippedEnd   = '2028-03-12 03:45:00';
$skippedPost  = str_replace(' ', 'T', $skippedClock);

// First, prove the hour really is skipped in that zone — or every check below
// is vacuous and would pass on anything.
$movedTo  = (new DateTimeImmutable($skippedClock, new DateTimeZone(EVENT_ZONE)))->format('Y-m-d H:i:s');
ok(
    'W09d the hour these checks use really does NOT exist in ' . EVENT_ZONE . ', so they are not vacuous',
    $movedTo === '2028-03-12 03:30:00',
    'building ' . $skippedClock . ' as a moment in ' . EVENT_ZONE . ' gives ' . $movedTo . ', looked for 2028-03-12 03:30:00'
);

$answer  = $api(['eventName' => 'Selftest zones API skipped', 'startDateTime' => $skippedPost, 'endDateTime' => str_replace(' ', 'T', $skippedEnd), 'timezone' => EVENT_ZONE, 'status' => 'published']);
$skipId  = $newest('Selftest zones API skipped');
$storedS = one('SELECT startDateTime, endDateTime FROM tblEvents WHERE eventID = ?', 'i', [$skipId]);
ok(
    'W09e the events API stores a reading inside the hour that does not exist AS IT STANDS — passing the correct zone does not save it, because building a moment at all is the fault',
    $skipId > 0 && $storedS === ['startDateTime' => $skippedClock, 'endDateTime' => $skippedEnd],
    said($answer) . ' ' . substr($answer['body'], 0, 200) . '; stored ' . json_encode($storedS) . ', looked for ' . $skippedClock
);

// The UPDATE handler, same question. A plain reading with no offset.
$answer  = req('', 'POST', '/api/events/update?id=' . $skipId, (string) json_encode(['id' => $skipId, 'eventID' => $skipId, 'startDateTime' => str_replace(' ', 'T', '2028-03-12 02:15:00')]), ['bearer' => $apiKey, 'json' => true]);
$nowAt   = (string) (one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$skipId])['startDateTime'] ?? '');
ok(
    'W09f the events API\'s UPDATE stores a reading inside that hour as it stands too',
    $nowAt === '2028-03-12 02:15:00',
    said($answer) . ' ' . substr($answer['body'], 0, 200) . '; stored ' . json_encode($nowAt) . ', looked for 2028-03-12 02:15:00'
);

// An OFFSET in the value IS a zone, so such a value really is a moment and
// must still be converted. Getting this wrong in the safe-looking direction
// would break every integration that sends an instant.
// 📌 The end moves with the start, for the same reason as W09b above.
$instant    = gmdate('Y-m-d\TH:i:s\Z', (new DateTimeImmutable('2028-07-01 12:00:00', new DateTimeZone('UTC')))->getTimestamp());
$instantEnd = gmdate('Y-m-d\TH:i:s\Z', (new DateTimeImmutable('2028-07-01 13:30:00', new DateTimeZone('UTC')))->getTimestamp());
$answer  = req('', 'POST', '/api/events/update?id=' . $skipId, (string) json_encode(['id' => $skipId, 'eventID' => $skipId, 'startDateTime' => $instant, 'endDateTime' => $instantEnd]), ['bearer' => $apiKey, 'json' => true]);
$nowAt   = (string) (one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$skipId])['startDateTime'] ?? '');
$wantAt  = (new DateTimeImmutable('2028-07-01 12:00:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(EVENT_ZONE))->format('Y-m-d H:i:s');
ok(
    'W09g a value carrying its OWN offset is still converted onto the event\'s clock — the #777 fix must not break an integration sending an instant',
    $nowAt === $wantAt,
    said($answer) . ' ' . substr($answer['body'], 0, 200) . '; stored ' . json_encode($nowAt) . ', looked for ' . $wantAt
);
q('DELETE FROM tblEvents WHERE eventID = ?', 'i', [$skipId]);

// And the PUBLIC submission form, which is the third write path.
//
// ⚠️ IT NEEDS A DIFFERENT HOUR, AND GETTING THIS WRONG WOULD HAVE MADE THE
//    CHECK VACUOUS. This form stores the ORGANISATION's zone (W06 above), and
//    the old code read the posted value through PHP's default zone, which
//    start-up sets to that same organisation's. So the skipped hour has to be
//    Pacific/Auckland's, not America/New_York's -- and 2028-03-12 02:30 is an
//    ordinary time in Auckland, so using it here would have passed on the
//    broken code. Auckland's own transition is in late September:
//    2028-09-24 02:30 local does not exist there (it becomes 03:30), measured
//    below before anything else is asserted.
$orgSkipped     = '2028-09-24 02:30:00';
$orgSkippedEnd  = '2028-09-24 03:45:00';
$orgMovedTo     = (new DateTimeImmutable($orgSkipped, new DateTimeZone(ORG_ZONE)))->format('Y-m-d H:i:s');
ok(
    'W09h0 the hour the submission check uses really does NOT exist in ' . ORG_ZONE . ' either',
    $orgMovedTo === '2028-09-24 03:30:00',
    'building ' . $orgSkipped . ' as a moment in ' . ORG_ZONE . ' gives ' . $orgMovedTo
);
// 📌 `post()` fetches the form for its token first, as W06 above does -- a
//    hand-built request would be refused by `Auth::verifyCsrf()`.
$answer = post($memberJar, '/calendar/submit', '/calendar/submit-save', [
    'eventName' => 'Selftest zones submit skipped',
    'startDateTime' => str_replace(' ', 'T', $orgSkipped),
    'endDateTime' => str_replace(' ', 'T', $orgSkippedEnd),
    'locationName' => 'Selftest Hall', 'description' => '',
]);
$subId  = $newest('Selftest zones submit skipped');
$subRow = one('SELECT startDateTime, locationName FROM tblEvents WHERE eventID = ?', 'i', [$subId]);
ok(
    'W09h the public submission form stores a reading inside that hour as it stands, and keeps the location (the bind_param fault of round 6)',
    $subId > 0 && $subRow === ['startDateTime' => $orgSkipped, 'locationName' => 'Selftest Hall'],
    said($answer) . '; stored ' . json_encode($subRow) . ', looked for ' . $orgSkipped . ' / Selftest Hall'
);
q('DELETE FROM tblEvents WHERE eventID = ?', 'i', [$subId]);

// ══════════════════════════════════════════════════════════════════════════
// W09i-W09k — the UPDATE endpoint refuses an end before its start (#786)
// ══════════════════════════════════════════════════════════════════════════
// 🚨 IT HAD NO COMPARISON AT ALL — not a weakened one, none — while
//    `api-spec.json` documents its body as a subset of create's and create's
//    own description promises the rule. Found in round 10 of #777.
$withoutConsumingIds(static function () use ($apiKey, $newest): void {
    $mk = static fn (array $b): array => req('', 'POST', '/api/events/create', (string) json_encode($b), ['bearer' => $apiKey, 'json' => true]);
    $mk(['eventName' => 'Selftest zones order', 'startDateTime' => '2028-07-01T10:00:00', 'endDateTime' => '2028-07-01T11:00:00', 'status' => 'published']);
    $id = $newest('Selftest zones order');

    // Changing the END to before the stored start.
    $bad = req('', 'POST', '/api/events/update?id=' . $id, (string) json_encode(['id' => $id, 'eventID' => $id, 'endDateTime' => '2028-07-01T09:00:00']), ['bearer' => $apiKey, 'json' => true]);
    $after = (string) (one('SELECT endDateTime FROM tblEvents WHERE eventID = ?', 'i', [$id])['endDateTime'] ?? '');
    ok(
        'W09i the UPDATE endpoint REFUSES an end earlier than the STORED start, and changes nothing',
        $bad['status'] === 400 && $after === '2028-07-01 11:00:00',
        said($bad) . ' ' . substr($bad['body'], 0, 160) . '; stored ' . json_encode($after)
    );

    // Changing only the START, past the stored end.
    $bad2 = req('', 'POST', '/api/events/update?id=' . $id, (string) json_encode(['id' => $id, 'eventID' => $id, 'startDateTime' => '2028-07-01T12:00:00']), ['bearer' => $apiKey, 'json' => true]);
    $after2 = (string) (one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$id])['startDateTime'] ?? '');
    ok(
        'W09j and it refuses a START moved past the STORED end — the side that is absent is read from the row',
        $bad2['status'] === 400 && $after2 === '2028-07-01 10:00:00',
        said($bad2) . ' ' . substr($bad2['body'], 0, 160) . '; stored ' . json_encode($after2)
    );

    // An EQUAL start and end is allowed, which three places document.
    $okq = req('', 'POST', '/api/events/update?id=' . $id, (string) json_encode(['id' => $id, 'eventID' => $id, 'endDateTime' => '2028-07-01T10:00:00']), ['bearer' => $apiKey, 'json' => true]);
    $after3 = (string) (one('SELECT endDateTime FROM tblEvents WHERE eventID = ?', 'i', [$id])['endDateTime'] ?? '');
    ok(
        'W09k but an EQUAL start and end is ACCEPTED, as create.php and manage/save.php both document',
        $okq['status'] === 200 && $after3 === '2028-07-01 10:00:00',
        said($okq) . ' ' . substr($okq['body'], 0, 160) . '; stored ' . json_encode($after3)
    );
    q('DELETE FROM tblEvents WHERE eventID = ?', 'i', [$id]);
});

$answer = $api(['eventName' => 'Selftest zones API refused', 'startDateTime' => str_replace(' ', 'T', clockIn(28, '19:00:00')), 'timezone' => 'GMT+1']);
ok(
    'W10 the events API REFUSES a zone that is not a zone name (422), in words that name the field, and creates nothing',
    $answer['status'] === 422 && str_contains($answer['body'], 'timezone is not a time zone name the portal knows') === true && $newest('Selftest zones API refused') === 0,
    said($answer) . ' ' . substr($answer['body'], 0, 300)
);

// ---- the calendar importer ---------------------------------------------------------
$stamp = static fn (string $clock): string => str_replace(['-', ':', ' '], ['', '', 'T'], $clock);
$ics   = implode("\r\n", [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//time-zones-selftest//EN', 'BEGIN:VEVENT', 'UID:selftest-zones-floating@selftest.invalid',
    'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'DTSTART:' . $stamp(clockIn(29, '19:00:00')), 'DTEND:' . $stamp(clockIn(29, '20:30:00')),
    'SUMMARY:Selftest zones outside calendar', 'END:VEVENT', 'END:VCALENDAR', '',
]);
file_put_contents($child . $sep . 'calendars' . $sep . 'floating.ics', $ics);
foreach ([[FEED, 'Selftest zones calendar', null], [FEED_OWN, 'Selftest zones calendar with its own zone', VENUE_ZONE]] as [$feedId, $feedName, $feedZone]) {
    q(
        "INSERT INTO tblExternalFeeds (feedID, siteID, name, url, fetchEveryMins, timezone, audienceLevel, isActive, nextFetchAt, createdAt) VALUES (?, 1, ?, 'http://calendar.test/floating.ics', 360, ?, 'public', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        'iss', [$feedId, $feedName, $feedZone]
    );
}
$import = child(['task' => 'import', 'feedId' => FEED, 'port' => $calendarPort]);
$made['the calendar importer'] = (int) (one('SELECT eventID FROM tblEvents WHERE externalFeedID = ? ORDER BY eventID DESC LIMIT 1', 'i', [FEED])['eventID'] ?? 0);
ok(
    'W11 the calendar importer: an event from a calendar with no zone of its own is stored in the ORGANISATION\'s zone, at the clock time the file gives',
    $import['threw'] === '' && $made['the calendar importer'] > 0 && zoneOfEvent($made['the calendar importer']) === ORG_ZONE
        && (string) one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$made['the calendar importer']])['startDateTime'] === clockIn(29, '19:00:00'),
    json_encode($import) . '; stored ' . json_encode(one('SELECT timezone, startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$made['the calendar importer']]))
);
$noLondon = q("SELECT eventID, eventName, timezone FROM tblEvents WHERE eventID IN (" . implode(',', array_map('intval', array_filter($made))) . ") AND timezone = 'Europe/London'");
ok(
    'W12 not one of the ' . count(array_filter($made)) . ' events made by the writers above is stored as "Europe/London"',
    count(array_filter($made)) === count($made) && $noLondon === [],
    'made: ' . json_encode($made) . '; stored as London: ' . json_encode($noLondon)
);

// =============================================================================
// R. EVERY READER GIVES THAT ONE ZONE BACK
// =============================================================================
head('R. Every reader gives the stored zone back: the event page, the calendar feed, the export, the events API, the venue code');
// Each event is made visible to its readers: the question here is which zone
// it is read in, not who may see it. Since D-089 (#850) the event page's
// search-engine markup — where the published start below is read — goes out
// only for an event on the public website, so each is ticked for it too,
// with no website dates (no limit). Without that the page carries no markup
// and the check below cannot find the start (it went red on exactly that).
foreach ($made as $eventId) {
    q("UPDATE tblEvents SET status = 'published', isPublic = 1, showOnWebsite = 1, websiteFrom = NULL, websiteUntil = NULL, "
        . "submissionStatus = IF(submissionStatus = 'pending', 'approved', submissionStatus) WHERE eventID = ?", 'i', [$eventId]);
}
q("UPDATE tblEvents SET importLevel = 'public', importDetail = 'full', importApiOptOut = 0 WHERE externalFeedID = ?", 'i', [FEED]);
$feedText = str_replace("\r\n ", '', req('', 'GET', '/calendar.ics?token=' . $feedToken . '&days=120')['body']);
foreach ($made as $label => $eventId) {
    $row   = one('SELECT eventSlug, eventName, startDateTime, endDateTime, timezone FROM tblEvents WHERE eventID = ?', 'i', [$eventId]) ?? ['eventSlug' => '', 'eventName' => '', 'startDateTime' => clockIn(0, '00:00:00'), 'endDateTime' => null, 'timezone' => 'UTC'];
    $zone  = (string) $row['timezone'];
    $clock = (string) $row['startDateTime'];
    $local = $stamp($clock);
    $want  = in_array($label, ['the event form, with a zone chosen', 'the events API, with a zone chosen'], true) === true ? EVENT_ZONE : ORG_ZONE;
    // Every one of these events has an END. A calendar file must give it as it gives the start: the stored time,
    // in the event's own zone. (These two checks used to read the start only; a fault left in the end went unseen.)
    $wantEnd = $row['endDateTime'] === null ? '(this event has no end, which is itself a fault in this test)' : 'DTEND;TZID=' . $want . ':' . $stamp((string) $row['endDateTime']);

    // The event page: its "Timezone:" line, and the start it publishes for search engines, with the zone's own offset on that date.
    $page   = req($adminJar, 'GET', '/calendar/event?slug=' . rawurlencode((string) $row['eventSlug']));
    $offset = (new DateTimeImmutable($clock, new DateTimeZone($want)))->format('Y-m-d\TH:i:sP');
    // An event copied in from an outside calendar publishes no start for search engines (that is another rule's choice), so only its own line is looked at.
    $published = $label === 'the calendar importer' ? true : str_contains($page['body'], $offset);
    ok(
        'R the event page, for an event made by ' . $label . ': "Timezone: ' . $want . '"' . ($label === 'the calendar importer' ? '' : ', and the published start carries that zone\'s offset (' . substr($offset, -6) . ')'),
        $zone === $want && str_contains(words($page['body']), 'Timezone: ' . $want) === true && $published === true,
        said($page) . '; stored ' . $zone . '; "Timezone:" line: ' . (tz_find('/Timezone: \S+/', words($page['body']), $t) === 1 ? $t[0] : '(none)') . '; looked for ' . $offset
    );
    // The calendar feed (a personal subscription).
    $inFeed = tz_find('/BEGIN:VEVENT(?:(?!END:VEVENT).)*SUMMARY:' . preg_quote((string) $row['eventName'], '/') . '(?:(?!END:VEVENT).)*END:VEVENT/s', $feedText, $block) === 1 ? $block[0] : '';
    ok(
        'R the calendar feed, for an event made by ' . $label . ': DTSTART;TZID=' . $want . ':' . $local . ' and ' . $wantEnd,
        str_contains($inFeed, 'DTSTART;TZID=' . $want . ':' . $local) === true && str_contains($inFeed, $wantEnd) === true,
        $inFeed === '' ? 'the event is not in the feed (' . strlen($feedText) . ' bytes)' : (tz_findAll('/D(?:TSTART|TEND)[^\r\n]*/', $inFeed, $d) > 0 ? implode(' ', $d[0]) : 'no DTSTART')
            . '; the file declares ' . (tz_find('/X-WR-TIMEZONE:[^\r\n]*/', $feedText, $x) === 1 ? $x[0] : 'no zone')
    );
    // The export of the one event.
    $export = str_replace("\r\n ", '', req($adminJar, 'GET', '/calendar/export?id=' . $eventId)['body']);
    ok(
        'R the export, for an event made by ' . $label . ': DTSTART;TZID=' . $want . ':' . $local . ' and ' . $wantEnd,
        str_contains($export, 'DTSTART;TZID=' . $want . ':' . $local) === true && str_contains($export, $wantEnd) === true,
        tz_find('/BEGIN:VEVENT.*?(DTSTART[^\r\n]*).*?(DTEND[^\r\n]*)/s', $export, $d) === 1 ? $d[1] . ' ' . $d[2] : 'no event with a start and an end in ' . strlen($export) . ' bytes'
    );
    // The events API's detail answer: one zone field, the right one, and no second one.
    $detail = json_decode(req('', 'GET', '/api/events/detail?id=' . $eventId, null, ['bearer' => $apiKey])['body'], true);
    $event  = (array) ($detail['data']['event'] ?? $detail['event'] ?? []);
    ok(
        'R the events API, for an event made by ' . $label . ': "timezone" is ' . $want . ', and there is no second zone field',
        ($event['timezone'] ?? null) === $want && array_key_exists('eventTimezone', $event) === false,
        'timezone ' . json_encode($event['timezone'] ?? null) . '; eventTimezone present: ' . json_encode(array_key_exists('eventTimezone', $event)) . '; ' . substr(json_encode($detail), 0, 200)
    );
    // The venue code: the day the event falls on by the hired hall's clock.
    $hallDay = (new DateTimeImmutable($clock, new DateTimeZone($want)))->setTimezone(new DateTimeZone(VENUE_ZONE))->format('Y-m-d');
    $venue   = child(['task' => 'venue', 'eventId' => $eventId, 'venueId' => VENUE]);
    ok(
        'R the venue code, for an event made by ' . $label . ': by the hall\'s clock (' . VENUE_ZONE . ') it starts on ' . $hallDay . ', which is only right when the event is read in ' . $want,
        $venue['threw'] === '' && ($venue['days'][0] ?? '') === $hallDay,
        json_encode($venue)
    );
}

// An all-day event keeps its DATE. The organisation is east of Greenwich, where the date used to go out a day early.
$allDayId = 960450;
$day      = substr(clockIn(31, '00:00:00'), 0, 10);
q(
    "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, isAllDay, status, isPublic, isDeleted) VALUES (?, 1, 'selftest-zones-all-day', 'Selftest zones all day', ?, ?, ?, 1, 'published', 1, 0)",
    'isss', [$allDayId, $day . ' 00:00:00', $day . ' 23:59:59', ORG_ZONE]
);
// A second one, as a one-day event is often stored: its end at the very START of its day. The file must still
// say it ends before the NEXT day. (With an end late in the day, the old sum happened to land on the right
// date east of Greenwich, and a planted fault in it went unnoticed: this one closes that.)
$oneDay = substr(clockIn(33, '00:00:00'), 0, 10);
q(
    "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, isAllDay, status, isPublic, isDeleted) VALUES (960451, 1, 'selftest-zones-one-day', 'Selftest zones one day', ?, ?, ?, 1, 'published', 1, 0)",
    'sss', [$oneDay . ' 00:00:00', $oneDay . ' 00:00:00', ORG_ZONE]
);
$oneDayAfter = (new DateTimeImmutable($oneDay, new DateTimeZone('UTC')))->modify('+1 day')->format('Ymd');
$dayAfter   = (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('+1 day')->format('Ymd');
$feedAgain  = str_replace("\r\n ", '', req('', 'GET', '/calendar.ics?token=' . $feedToken . '&days=120')['body']);
$exportDay  = str_replace("\r\n ", '', req($adminJar, 'GET', '/calendar/export?id=' . $allDayId)['body']);
$feedBlock  = tz_find('/BEGIN:VEVENT(?:(?!END:VEVENT).)*SUMMARY:Selftest zones all day(?:(?!END:VEVENT).)*END:VEVENT/s', $feedAgain, $block) === 1 ? $block[0] : '';
$wantStart  = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $day);
$wantEnd    = 'DTEND;VALUE=DATE:' . $dayAfter;
$oneDayFeed   = tz_find('/BEGIN:VEVENT(?:(?!END:VEVENT).)*SUMMARY:Selftest zones one day(?:(?!END:VEVENT).)*END:VEVENT/s', $feedAgain, $block) === 1 ? $block[0] : '';
$oneDayExport = str_replace("\r\n ", '', req($adminJar, 'GET', '/calendar/export?id=960451')['body']);
$oneDayRight  = static fn (string $text): bool => str_contains($text, 'DTSTART;VALUE=DATE:' . str_replace('-', '', $oneDay)) === true && str_contains($text, 'DTEND;VALUE=DATE:' . $oneDayAfter) === true;
ok(
    'R an ALL-DAY event keeps its own date in the calendar feed and in the export: it starts on ' . $day . ' and ends before the day after (east of Greenwich, and in London in summer, it used to go out a day early); and so does one whose end is stored at the start of its own day',
    str_contains($feedBlock, $wantStart) === true && str_contains($feedBlock, $wantEnd) === true && str_contains($exportDay, $wantStart) === true && str_contains($exportDay, $wantEnd) === true
        && $oneDayRight($oneDayFeed) === true && $oneDayRight($oneDayExport) === true,
    'the feed: ' . (tz_findAll('/D(?:TSTART|TEND)[^\r\n]*/', $feedBlock, $d) > 0 ? implode(' ', $d[0]) : 'the event is not in it')
        . '; the export: ' . (tz_find('/BEGIN:VEVENT.*?(DTSTART[^\r\n]*).*?(DTEND[^\r\n]*)/s', $exportDay, $x) === 1 ? $x[1] . ' ' . $x[2] : 'no event')
        . '; the one-day event, feed: ' . (tz_findAll('/D(?:TSTART|TEND)[^\r\n]*/', $oneDayFeed, $d) > 0 ? implode(' ', $d[0]) : 'not in it')
        . ', export: ' . (tz_find('/BEGIN:VEVENT.*?(DTSTART[^\r\n]*).*?(DTEND[^\r\n]*)/s', $oneDayExport, $x) === 1 ? $x[1] . ' ' . $x[2] : 'no event') . '; looked for the end ' . $oneDayAfter
);
ok(
    'R the calendar feed and the export each declare the ORGANISATION\'s zone as the file\'s own (it was "Europe/London" for every customer, written into the code)',
    str_contains($feedAgain, 'X-WR-TIMEZONE:' . ORG_ZONE) === true && str_contains($feedAgain, 'TZID:' . ORG_ZONE) === true
        && str_contains($exportDay, 'X-WR-TIMEZONE:' . ORG_ZONE) === true && str_contains($feedAgain . $exportDay, 'Europe/London') === false,
    'the feed: ' . (tz_find('/X-WR-TIMEZONE:[^\r\n]*/', $feedAgain, $x) === 1 ? $x[0] : 'none') . '; the export: ' . (tz_find('/X-WR-TIMEZONE:[^\r\n]*/', $exportDay, $x) === 1 ? $x[0] : 'none')
);

// A member of the SECOND organisation only. A feed is asked for with a token and no sign-in, so the request
// itself belongs to the default organisation; the file must be on the HOLDER's organisation's clock all the same.
// Their rota duty names no zone of its own, so it goes out in the file's.
$otherToken = bin2hex(random_bytes(32));
q("INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin, calendarToken) VALUES (?, 'Selftest Zones Other', 'other.zones@selftest.invalid', 1, 0, 0, ?)", 'is', [OTHER, hash('sha256', $otherToken)]);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [OTHER, ORG_TWO]);
q("INSERT INTO tblRotaRoleType (roleTypeID, siteID, name) VALUES (960601, ?, 'Selftest zones welcome team')", 'i', [ORG_TWO]);
$dutyDay = substr(clockIn(12, '00:00:00'), 0, 10);
q("INSERT INTO tblRotaSlot (slotID, siteID, roleTypeID, slotDate, startTime, endTime, assignedToID) VALUES (960602, ?, 960601, ?, '09:30:00', '11:00:00', ?)", 'isi', [ORG_TWO, $dutyDay, OTHER]);
$otherFeed = str_replace("\r\n ", '', req('', 'GET', '/calendar.ics?token=' . $otherToken . '&days=120')['body']);
ok(
    'R the calendar feed of a member of a SECOND organisation is on THAT organisation\'s clock (' . zoneOfOrg(ORG_TWO) . '), not the default organisation\'s: the file declares it, and their 09:30 rota duty goes out as 09:30 there',
    zoneOfOrg(ORG_TWO) === 'America/Santiago' && str_contains($otherFeed, 'X-WR-TIMEZONE:America/Santiago') === true
        && str_contains($otherFeed, 'DTSTART;TZID=America/Santiago:' . str_replace('-', '', $dutyDay) . 'T093000') === true && str_contains($otherFeed, ORG_ZONE) === false,
    (tz_find('/X-WR-TIMEZONE:[^\r\n]*/', $otherFeed, $x) === 1 ? $x[0] : 'the file declares no zone') . '; the duty: '
        . (tz_find('/BEGIN:VEVENT(?:(?!END:VEVENT).)*SUMMARY:Duty(?:(?!END:VEVENT).)*END:VEVENT/s', $otherFeed, $b) === 1 && tz_find('/DTSTART[^\r\n]*/', $b[0], $d) === 1 ? $d[0] : 'not in the file (' . strlen($otherFeed) . ' bytes)')
);

// =============================================================================
// U. WHAT IS NOT A ZONE IS FOUND AND LISTED, NEVER CHANGED
// =============================================================================
head('U. Text that is not a zone: found, counted and listed for an administrator, and refused where it could be stored');
// ---- events -------------------------------------------------------------------
$listPage = req($adminJar, 'GET', '/calendar/manage')['body'];
tz_find('#<div class="alert alert-warning" role="alert" id="events-unknown-zone" data-count="(\d+)">(.*?)</div>#s', $listPage, $listed);
ok(
    'U01 Manage Events lists the event stored with text that is not a zone ("London") and the two whose zone box was emptied on the old form, says how many there are (3), and does NOT list one stored with an old name PHP still opens ("US/Eastern")',
    ($listed[1] ?? '') === '3' && str_contains($listed[2] ?? '', 'Selftest older event 960403') === true && str_contains($listed[2] ?? '', 'saved as "London"') === true
        && str_contains($listed[2] ?? '', 'Selftest older event 960404') === true && str_contains($listed[2] ?? '', 'Selftest older event 960405') === true && str_contains($listed[2] ?? '', 'saved as ""') === true
        && str_contains($listed[2] ?? '', '960406') === false,
    isset($listed[1]) === true ? 'count ' . $listed[1] . ': ' . words($listed[2]) : 'no such notice on the page'
);
$editUnknown = req($adminJar, 'GET', '/calendar/manage?edit=960403')['body'];
post($adminJar, '/calendar/manage?edit=960403', '/calendar/manage/save', $update(960403, ['timezone' => '']));
$stillLondon = zoneOfEvent(960403);
$refusedSay  = flashAfter($adminJar, '/calendar/manage');
ok(
    'U02 nothing changed it: the stored text is still "London". Its form opens on "Choose a time zone…", and saving without choosing is refused',
    $stillLondon === 'London' && str_contains($editUnknown, '<option value="" selected>Choose a time zone…</option>') === true && str_contains($refusedSay, 'Choose a time zone for the event') === true,
    'stored ' . json_encode($stillLondon) . '; the page said: ' . $refusedSay
);
post($adminJar, '/calendar/manage?edit=960403', '/calendar/manage/save', $update(960403, ['timezone' => 'Europe/Paris']));
$listAfter = tz_find('#id="events-unknown-zone" data-count="(\d+)">(.*?)</div>#s', req($adminJar, 'GET', '/calendar/manage')['body'], $left) === 1 ? $left : ['', '', ''];
ok(
    'U03 once an administrator chooses a real zone it is saved, and that event leaves the list (the other two stay on it)',
    zoneOfEvent(960403) === 'Europe/Paris' && $listAfter[1] === '2' && str_contains($listAfter[2], '960403') === false && str_contains($listAfter[2], '960404') === true,
    'stored ' . json_encode(zoneOfEvent(960403)) . '; the list now counts ' . json_encode($listAfter[1])
);
q("UPDATE tblEvents SET timezone = 'London' WHERE eventID = 960403"); // back, for the health line and the reminder part

// ---- organisations ----------------------------------------------------------------
orgZone(ORG_TWO, 'US/Eastern');
$orgs = req($adminJar, 'GET', '/admin/sites')['body'];
ok(
    'U04 Admin, Organisations says so on the card of an organisation whose stored zone is not a zone name: what is stored, that UTC is used, and (because PHP could still open it) that its zone-less outside calendars are not imported',
    tz_find('#data-zone-problem="in-doubt">(.*?)</span>#s', $orgs, $card) === 1 && str_contains(words($card[1]), 'Stored as "US/Eastern", which is not a time zone name the portal knows. UTC is used.') === true
        && str_contains(words($card[1]), 'outside calendars with no time zone of their own are not imported') === true,
    isset($card[1]) === true ? words($card[1]) : 'no such warning on the page'
);
$orgFields = static fn (array $change): array => array_merge([
    'siteID' => ORG_TWO, 'siteName' => 'Selftest Zones Two', 'siteKey' => 'selftest-zones-two', 'hostPattern' => '', 'logoPath' => '/assets/images/logo.svg',
    'faviconPath' => '', 'primaryColor' => '#5e6ad2', 'copyrightOrg' => '', 'isActive' => '1',
], $change);
post($adminJar, '/admin/sites', '/admin/sites/save', $orgFields(['siteName' => 'Selftest Zones Two renamed', 'timezone' => '']));
$afterEmpty = [zoneOfOrg(ORG_TWO), (string) one('SELECT siteName FROM tblSites WHERE siteID = ?', 'i', [ORG_TWO])['siteName'], flashAfter($adminJar, '/admin/sites')];
post($adminJar, '/admin/sites', '/admin/sites/save', $orgFields(['timezone' => 'Eastern']));
$afterWrong = [zoneOfOrg(ORG_TWO), flashAfter($adminJar, '/admin/sites')];
ok(
    'U05 the Organisations form REFUSES to save with no zone chosen, or with text that is not a zone name, naming the box; the stored zone is NOT quietly replaced by UTC (it used to be), and nothing else is saved either',
    $afterEmpty[0] === 'US/Eastern' && $afterEmpty[1] === 'Selftest Zones Two' && str_contains($afterEmpty[2], 'Choose the organisation\'s time zone in the "Time zone" box') === true
        && $afterWrong[0] === 'US/Eastern' && str_contains($afterWrong[1], 'The "Time zone" box holds "Eastern"') === true,
    json_encode([$afterEmpty, $afterWrong])
);

// ---- the calendar importer refuses where the zone is in doubt ----------------------
q('UPDATE tblExternalFeeds SET siteID = ? WHERE feedID IN (?, ?)', 'iii', [ORG_TWO, FEED, FEED_OWN]);
q('UPDATE tblEvents SET siteID = ? WHERE externalFeedID = ?', 'ii', [ORG_TWO, FEED]);
$importedBefore = one('SELECT COUNT(*) AS n, MAX(timezone) AS zone, MAX(startDateTime) AS start FROM tblEvents WHERE externalFeedID = ?', 'i', [FEED]);
// The file changes, so that "nothing has changed" is not what stops the import.
$changeFile = static function () use ($child, $sep, $ics): void {
    file_put_contents($child . $sep . 'calendars' . $sep . 'floating.ics', str_replace('SUMMARY:Selftest zones outside calendar', 'SUMMARY:Selftest zones outside calendar ' . bin2hex(random_bytes(3)), $ics));
};
$refusals = [];
foreach (['h' => 'US/Eastern', 'i' => 'pacific/auckland', 'j' => ' ' . ORG_ZONE] as $letter => $stored) {
    orgZone(ORG_TWO, $stored);
    $changeFile();
    q('UPDATE tblExternalFeeds SET nextFetchAt = UTC_TIMESTAMP(), consecutiveFailures = 0 WHERE feedID = ?', 'i', [FEED]);
    $result              = child(['task' => 'import', 'feedId' => FEED, 'port' => $calendarPort]);
    $refusals[$letter]   = [$result['threw'], $result['result']['outcome'] ?? '', $result['result']['message'] ?? ''];
}
$importedAfter = one('SELECT COUNT(*) AS n, MAX(timezone) AS zone, MAX(startDateTime) AS start FROM tblEvents WHERE externalFeedID = ?', 'i', [FEED]);
$allRefused    = array_filter($refusals, static fn (array $r): bool => $r[0] === '' && $r[1] === 'failed' && str_contains($r[2], 'Choose the organisation\'s time zone at Admin, Organisations') === true) === $refusals;
ok(
    'U06 where the organisation\'s stored zone is in doubt (an old name, other capitals, a space in front: h, i, j) the importer REFUSES a calendar with no zone of its own, saying to choose the organisation\'s zone first; nothing already imported is changed',
    $allRefused === true && $importedAfter === $importedBefore && str_contains((string) one('SELECT lastFetchMessage FROM tblExternalFeeds WHERE feedID = ?', 'i', [FEED])['lastFetchMessage'], 'Not imported, and nothing was changed') === true,
    json_encode($refusals) . '; imported rows before ' . json_encode($importedBefore) . ', after ' . json_encode($importedAfter)
);
$ownZone = child(['task' => 'import', 'feedId' => FEED_OWN, 'port' => $calendarPort]);
ok(
    'U07 in the same organisation a calendar that names ITS OWN zone is imported as before, in that zone: the organisation\'s is not used for it',
    $ownZone['threw'] === '' && ($ownZone['result']['outcome'] ?? '') !== 'failed' && (string) (one('SELECT MAX(timezone) AS zone FROM tblEvents WHERE externalFeedID = ?', 'i', [FEED_OWN])['zone'] ?? '') === VENUE_ZONE,
    json_encode($ownZone) . '; stored ' . json_encode(one('SELECT MAX(timezone) AS zone FROM tblEvents WHERE externalFeedID = ?', 'i', [FEED_OWN]))
);
orgZone(ORG_TWO, 'Auckland');
$changeFile();
q('UPDATE tblExternalFeeds SET nextFetchAt = UTC_TIMESTAMP(), consecutiveFailures = 0 WHERE feedID = ?', 'i', [FEED]);
$notAZone = child(['task' => 'import', 'feedId' => FEED, 'port' => $calendarPort]);
ok(
    'U08 text PHP cannot open at all ("Auckland": k) is NOT refused: the pages and the importer both answered UTC for it before, and both do now',
    $notAZone['threw'] === '' && ($notAZone['result']['outcome'] ?? '') !== 'failed' && (string) (one('SELECT MAX(timezone) AS zone FROM tblEvents WHERE externalFeedID = ? AND isDeleted = 0', 'i', [FEED])['zone'] ?? '') === 'UTC',
    json_encode($notAZone) . '; stored ' . json_encode(one('SELECT MAX(timezone) AS zone FROM tblEvents WHERE externalFeedID = ? AND isDeleted = 0', 'i', [FEED]))
);
orgZone(ORG_TWO, 'US/Eastern');
$health = json_decode(req('', 'GET', '/cron/health?token=' . $healthToken)['body'], true);
$line   = (string) ($health['probes']['Time zones']['detail'] ?? '');
ok(
    'U09 the health report counts both: the organisation whose zone is not a zone name (and that its calendars wait), and the three events stored with text that is not a zone; it names neither',
    ($health['probes']['Time zones']['state'] ?? '') === 'warn' && str_contains($line, '1 organisation has a stored time zone that is not a time zone name') === true
        && str_contains($line, 'not imported for 1 of them') === true && str_contains($line, '3 events are saved with a time zone the portal does not know') === true
        && str_contains($line, 'Selftest') === false,
    json_encode($health['probes']['Time zones'] ?? '(no such line)')
);
post($adminJar, '/admin/sites', '/admin/sites/save', $orgFields(['timezone' => 'America/Santiago']));
$changeFile();
q('UPDATE tblExternalFeeds SET nextFetchAt = UTC_TIMESTAMP(), consecutiveFailures = 0 WHERE feedID = ?', 'i', [FEED]);
$chosen = child(['task' => 'import', 'feedId' => FEED, 'port' => $calendarPort]);
ok(
    'U10 once an administrator chooses a real zone on the Organisations form it is saved, and the waiting calendar is imported in it',
    zoneOfOrg(ORG_TWO) === 'America/Santiago' && $chosen['threw'] === '' && ($chosen['result']['outcome'] ?? '') !== 'failed'
        && (string) (one('SELECT MAX(timezone) AS zone FROM tblEvents WHERE externalFeedID = ? AND isDeleted = 0', 'i', [FEED])['zone'] ?? '') === 'America/Santiago',
    'the row holds ' . json_encode(zoneOfOrg(ORG_TWO)) . '; ' . json_encode($chosen)
);

// ---- the general Settings page -----------------------------------------------------
$markRow  = one("SELECT settingID, settingValue FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt' AND siteID IS NULL");
$settings = req($adminJar, 'GET', '/settings')['body'];
$change   = post($adminJar, '/settings', '/settings/save', ['settingID' => (int) $markRow['settingID'], 'settingKey' => 'site.timezoneKeptAt', 'settingValue' => 'nowhere']);
$saidOne  = flashAfter($adminJar, '/settings');
$add      = post($adminJar, '/settings', '/settings/save', ['settingID' => 0, 'settingKey' => 'site.timezone', 'settingValue' => EVENT_ZONE]);
$saidTwo  = flashAfter($adminJar, '/settings');
$delete   = post($adminJar, '/settings', '/settings', ['action' => 'delete', 'settingID' => (int) $markRow['settingID']]);
$saidThree = flashAfter($adminJar, '/settings');
ok(
    'U11 the general Settings page REFUSES all three: changing the migration\'s mark, adding the old "site.timezone" setting, and deleting the mark; each time it says where a time zone IS chosen, and its row shows that link in place of Edit and Delete',
    (string) one("SELECT settingValue FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt' AND siteID IS NULL")['settingValue'] === 'organisation'
        && one("SELECT settingID FROM tblSettings WHERE settingKey = 'site.timezone'") === null
        && str_contains($saidOne, 'It is chosen at Admin, Organisations') === true && str_contains($saidTwo, 'It is chosen at Admin, Organisations') === true
        && str_contains($saidThree, 'It is chosen at Admin, Organisations') === true && str_contains($settings, 'Time zones are chosen at Admin, Organisations') === true,
    'change: ' . said($change) . ' "' . $saidOne . '"; add: ' . said($add) . ' "' . $saidTwo . '"; delete: ' . said($delete) . ' "' . $saidThree . '"; mark now '
        . json_encode(one("SELECT settingValue FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt'")) . '; old setting rows ' . one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey = 'site.timezone'")['n']
);

// ---- events made before the upgrade and still marked "Europe/London" ------------------
// The old form filled its zone box with "Europe/London" for every organisation, and the file import, the API and
// suggestions stored it whatever the organisation. Nothing can tell those from events truly in London, so they
// are FOUND and listed, with one button. "Before the upgrade" is before the moment in migration 217's mark.
$markAt = (string) one("SELECT updatedAt FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt' AND siteID IS NULL")['updatedAt'];
/** [id, name, days from now, made before (true) or after the mark, copied in from an outside calendar, the organisation] */
$londonEvents = [
    [960470, 'Selftest London coming', 40, true, false, 1], [960471, 'Selftest London past', -10, true, false, 1],
    [960472, 'Selftest London chosen after the upgrade', 41, false, false, 1], [960473, 'Selftest London from an outside calendar', 42, true, true, 1],
    // ANOTHER organisation's, made before the upgrade and marked London like the first two. It is not this
    // organisation's to count or to change. (Added after the second check: it made the finder and the button
    // forget the organisation, and this test passed, because every one of its events belonged to organisation 1.)
    [960474, 'Selftest London coming, of the second organisation', 40, true, false, ORG_TWO],
];
foreach ($londonEvents as [$id, $name, $days, $before, $imported, $ofSite]) {
    q(
        "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, timezone, status, isPublic, isDeleted, externalFeedID, createdAt) "
        . "VALUES (?, ?, ?, ?, ?, 'Europe/London', 'published', 1, 0, ?, ? " . ($before === true ? '- INTERVAL 1 DAY' : '+ INTERVAL 1 MINUTE') . ')',
        'iisssis', [$id, $ofSite, 'selftest-zones-london-' . $id, $name, clockIn($days, '19:00:00'), $imported === true ? FEED_OWN : null, $markAt]
    );
}
$londonNotice = static function () use ($adminJar): array {
    $html = req($adminJar, 'GET', '/calendar/manage')['body'];

    return tz_find('#id="events-marked-london" data-count="(\d+)" data-coming="(\d+)">(.*?)</div>#s', $html, $m) === 1 ? [$m[1], $m[2], $m[3]] : ['none', 'none', ''];
};
$notice = $londonNotice();
ok(
    'U12 Manage Events finds the events made before the upgrade and still marked "Europe/London": it says how many (2), how many are still to come (1), lists the coming one with a link to edit it, names this organisation\'s zone and says why. One chosen as London AFTER the upgrade, one copied in from an outside calendar, and one that is ANOTHER organisation\'s are not counted',
    $notice[0] === '2' && $notice[1] === '1' && str_contains($notice[2], '?edit=960470') === true && str_contains($notice[2], 'Selftest London coming') === true
        && str_contains($notice[2], 'Selftest London past') === false && str_contains($notice[2], '960472') === false && str_contains($notice[2], '960473') === false
        && str_contains($notice[2], '960474') === false && str_contains($notice[2], 'of the second organisation') === false
        && str_contains(words($notice[2]), 'this organisation\'s time zone is ' . ORG_ZONE) === true
        && str_contains(words($notice[2]), 'the event form filled its time zone box with Europe/London for every organisation') === true
        && str_contains(words($notice[2]), 'Reminders, the calendar feed and the calendar download go by the event\'s own time zone') === true
        && str_contains(words($notice[2]), 'If one of these events really is in London, do not press it') === true
        && str_contains($notice[2], 'name="action" value="zones-to-organisation"') === true,
    'count ' . $notice[0] . ', coming ' . $notice[1] . ': ' . substr(words($notice[2]), 0, 500)
);
// An organisation whose clock agrees with London's is not bothered: Dublin, and London itself.
$quiet = [];
foreach (['Europe/Dublin', 'Europe/London', 'Europe/Lisbon'] as $sameClock) {
    orgZone(1, $sameClock);
    $quiet[$sameClock] = $londonNotice()[0];
}
// And one whose clock DOES differ at the event's own start is told. (Paris is an hour ahead all year.)
orgZone(1, 'Europe/Paris');
$quiet['Europe/Paris'] = $londonNotice()[0];
orgZone(1, ORG_ZONE);
ok(
    'U13 an organisation whose clock tells the same time as London\'s for those events is NOT shown the notice (Dublin, Lisbon, London itself); one an hour away (Paris) is',
    $quiet === ['Europe/Dublin' => 'none', 'Europe/London' => 'none', 'Europe/Lisbon' => 'none', 'Europe/Paris' => '2'],
    json_encode($quiet)
);
// The one button. Not for a member; not without a form token; for whoever may manage events, all of them at once.
$zonesNow    = static fn (): array => array_column(q('SELECT eventID, timezone FROM tblEvents WHERE eventID BETWEEN 960470 AND 960474 ORDER BY eventID'), 'timezone', 'eventID');
$startsNow   = static fn (): array => array_column(q('SELECT eventID, startDateTime FROM tblEvents WHERE eventID BETWEEN 960470 AND 960474 ORDER BY eventID'), 'startDateTime', 'eventID');
$startsWere  = $startsNow();
$logLines    = static fn (): array => q("SELECT userID, activityDescription FROM tblActivityLogs WHERE activityType = 'EventZonesSetToOrganisation'");
$byMember    = post($memberJar, '/calendar', '/calendar/manage/save', ['action' => 'zones-to-organisation']);
$afterMember = $zonesNow();
$noToken     = req($adminJar, 'POST', '/calendar/manage/save', ['action' => 'zones-to-organisation']);
$afterNoToken = $zonesNow();
$pressed     = post($adminJar, '/calendar/manage', '/calendar/manage/save', ['action' => 'zones-to-organisation']);
$pressedSaid = flashAfter($adminJar, '/calendar/manage');
$allLondon   = [960470 => 'Europe/London', 960471 => 'Europe/London', 960472 => 'Europe/London', 960473 => 'Europe/London', 960474 => 'Europe/London'];
$lines       = $logLines();
ok(
    'U14 the one button sets ALL of them, past and coming, to the organisation\'s zone and changes no stored time; the two that were not counted stay on London, and so does the SECOND organisation\'s event, which is not this organisation\'s to change; one line in the activity log says how many. A member cannot do it, and nothing happens without a form token',
    $byMember['status'] === 403 && $afterMember === $allLondon && $afterNoToken === $allLondon
        && $zonesNow() === [960470 => ORG_ZONE, 960471 => ORG_ZONE, 960472 => 'Europe/London', 960473 => 'Europe/London', 960474 => 'Europe/London'] && $startsNow() === $startsWere
        && count($lines) === 1 && (int) $lines[0]['userID'] === ADMIN && str_contains((string) $lines[0]['activityDescription'], 'of 2 event(s)') === true
        && str_contains($pressedSaid, '2 events are now on this organisation\'s time zone (' . ORG_ZONE . ')') === true && $londonNotice()[0] === 'none',
    'a member: ' . said($byMember) . ' ' . json_encode($afterMember) . '; no token: ' . said($noToken) . ' ' . json_encode($afterNoToken) . '; pressed: ' . said($pressed) . ' "' . substr($pressedSaid, 0, 200)
        . '"; zones ' . json_encode($zonesNow()) . '; times unchanged: ' . json_encode($startsNow() === $startsWere) . '; log lines ' . json_encode($lines)
);

// =============================================================================
// T. THE REMINDER JOB DECIDES FROM MOMENTS
// =============================================================================
head('T. The reminder job on both change nights, north and south of the equator, with the database\'s clock in a third zone (' . DB_ZONE . ')');
// Nobody to send to: the block below switches every account but ONE off, so for MOST of this part the job
// records WHEN each reminder is made and no message is attempted. The one member left is going only to the
// events about the day-before reminder's WORDS (further down). The mail route for the run is the SMTP mailbox
// with nothing filled in: an attempt then answers "not set up" and is written to the e-mail log with its
// subject, which is how those checks read what would have been sent. No connection is made, to anything.
//
// 🚪 TWO BLOCKS NEAR THE END DELIBERATELY BREAK THAT, and each says so where it happens: the #666 checks give
//    the one member RSVPs of their own and change their stored preference between runs, and the #616 check adds
//    a second organisation's coordinator and three more accounts (left active to the end) and switches the
//    administrator back on for two runs before putting them back. Read those before assuming a new fixture
//    added here has nobody to send to — or that it has, which is the mistake that made the first version of
//    the #616 check pass on the code it was written to catch.
$switchedOff = array_map(static fn (array $r): int => (int) $r['userID'], q('SELECT userID FROM tblUsers WHERE isActive = 1'));
q('UPDATE tblUsers SET isActive = 0');
q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [MEMBER]);
$routeWas = (string) (one("SELECT settingValue FROM tblSettings WHERE settingKey = 'mail.provider' AND siteID IS NULL")['settingValue'] ?? 'ms365');
setting('mail.provider', 'smtp');
setting('mail.smtp.host', '');
/** The subjects of the day-before reminders the job TRIED to send for one event (each attempt is in the e-mail log). */
function reminderSubjects(string $eventName): array
{
    return array_column(q("SELECT subject FROM tblEmailLog WHERE subject LIKE CONCAT('Reminder: ', ?, '%') ORDER BY emailLogID", 's', [$eventName]), 'subject');
}

/**
 * Every address the mail log holds a message for whose subject begins with `$prefix`, in the order the
 * messages were written. One row per address: both reminder paths send one message per person.
 *
 * WHY THE ADDRESSES AND NOT A COUNT. `tblEventReminderLog.recipientCount` counts only messages a mail
 * service really TOOK (`Mailer::lastOutcome() === OUTCOME_SENT`), and in this made-up world no mail
 * service takes anything, so that column is 0 for every message that went out perfectly well. Asserting
 * on it reads as a strict check and is in fact a check of the harness's own lack of a mail server. The
 * addresses say what the assertions here are really about: WHO was written to, and who was not.
 *
 * @return list<string>
 */
function mailTo(string $prefix): array
{
    return array_column(q("SELECT toRecipients FROM tblEmailLog WHERE subject LIKE CONCAT(?, '%') ORDER BY emailLogID", 's', [$prefix]), 'toRecipients');
}

/**
 * The next time the clocks change each way in a zone, from the zone's own
 * rules, counted from now: [the moment, seconds from UTC before, after].
 *
 * @return array{forward: array{0:int,1:int,2:int}, back: array{0:int,1:int,2:int}}
 */
function changeNights(string $zoneName): array
{
    $now   = time();
    $steps = (new DateTimeZone($zoneName))->getTransitions($now, $now + 500 * 86400); // a horizon to search in, not a length of anything
    $found = [];
    for ($i = 1; $i < count($steps); $i++) {
        $kind = $steps[$i]['offset'] > $steps[$i - 1]['offset'] ? 'forward' : 'back';
        if (isset($found[$kind]) === false && $steps[$i]['ts'] > $now + 3 * 86400) {
            $found[$kind] = [(int) $steps[$i]['ts'], (int) $steps[$i - 1]['offset'], (int) $steps[$i]['offset']];
        }
    }
    if (isset($found['forward'], $found['back']) === false) {
        refuse("the zone {$zoneName} does not change its clocks both ways in the next 500 days");
    }

    return $found;
}

/** How many reminders of one kind are recorded as made for an event. */
function reminders(int $eventId, string $kind): int
{
    return (int) one('SELECT COUNT(*) AS n FROM tblEventReminderLog WHERE eventID = ? AND reminderType = ?', 'is', [$eventId, $kind])['n'];
}

/** One run of the real job with the database's clock at `$moment`. Returns what the job said, or why it did not run. */
function runJobAt(int $moment): string
{
    global $jobToken;
    $ran = child(['task' => 'reminders', 'key' => $jobToken, 'sessionZone' => DB_ZONE, 'timestamp' => $moment]);

    return $ran['threw'] !== '' ? 'THREW ' . $ran['threw'] : (string) ($ran['said'] ?? '');
}

/** Make one published event of the default organisation's, to be reminded about. */
function reminderEvent(int $id, string $name, string $clock, string $zone): void
{
    q(
        "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) VALUES (?, 1, ?, ?, ?, NULL, ?, 'published', 1, 0)",
        'issss', [$id, 'selftest-zones-reminder-' . $id, $name, $clock, $zone]
    );
}

/**
 * The real job, run at every half hour of the database's UTC clock (:00 and :30) from `$from` to `$to`. The page
 * says to call it every 15 minutes; every 30 is what one missed call makes of that.
 *
 * @return array{0: int, 1: list<string>} How many runs, and each one that did not end "OK".
 */
function runJobEveryHalfHour(int $from, int $to): array
{
    $runs  = 0;
    $notOk = [];
    for ($at = (int) (ceil($from / 1800) * 1800); $at <= $to; $at += 1800) { // 1,800 seconds: half an hour as a length of time
        $said = runJobAt($at);
        $runs++;
        if (str_starts_with($said, 'OK ') === false) {
            $notOk[] = gmdate('Y-m-d H:i', $at) . ' UTC: ' . substr($said, 0, 150);
        }
    }

    return [$runs, $notOk];
}

/**
 * What must become of the day-before reminder for one start when the job is called on every half hour. Worked out
 * HERE from what was decided (the coordinator, 13 October 2026), not asked of the portal:
 *   'held'      the moment exactly 24 hours before the start is already the event's own day: nothing is sent;
 *   'tomorrow'  the first call inside the window falls on the day before;
 *   'named'     it falls on any other day (a late call on the event's own day, or two days before): the day is named.
 */
function dayBeforeKind(string $clock, string $zoneName): string
{
    $zone  = new DateTimeZone($zoneName);
    $start = momentOf($clock, $zoneName);
    $day   = substr($clock, 0, 10);
    $dayOf = static fn (int $moment): string => (new DateTimeImmutable('@' . $moment))->setTimezone($zone)->format('Y-m-d');
    if ($dayOf($start - 86400) === $day) {
        return 'held';
    }
    // The window opens 24 hours and 15 minutes before the start; the first half-hour mark from then on is the call.
    $call = (int) (ceil(($start - 86400 - 900) / 1800) * 1800);

    return (new DateTimeImmutable($dayOf($call), new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d') === $day ? 'tomorrow' : 'named';
}

/** The subjects the job must have tried to send for a day-before reminder of that kind (none when it is held back). */
function dayBeforeSubjects(string $name, string $clock, string $kind): array
{
    return $kind === 'held' ? [] : ['Reminder: ' . $name . ($kind === 'tomorrow' ? ' tomorrow' : ' on ' . (new DateTimeImmutable(substr($clock, 0, 10), new DateTimeZone('UTC')))->format('l j M'))];
}

/** What the job did about one event's day-before reminder: the subjects it tried, and its "not sent" lines in the activity log. */
function dayBeforeDone(int $eventId, string $name): array
{
    $lines = q("SELECT activityDescription FROM tblActivityLogs WHERE activityType = 'EventReminderNotSent' AND activityDescription LIKE ? ORDER BY logID", 's', ['Event #' . $eventId . ' type=24h%']);

    return ['subjects' => reminderSubjects($name), 'notSent' => array_column($lines, 'activityDescription'), 'recorded' => reminders($eventId, '24h')];
}

$nextId = 960500;
foreach (['north of the equator' => 'America/New_York', 'south of the equator' => 'Australia/Sydney'] as $where => $zoneName) {
    $nights = changeNights($zoneName);
    $zone   = new DateTimeZone($zoneName);
    $clockAt = static fn (int $moment): string => (new DateTimeImmutable('@' . $moment))->setTimezone($zone)->format('D j M Y H:i T');

    foreach (['forward' => 'go forward (a 23-hour day)', 'back' => 'go back (a 25-hour day)'] as $kind => $kindWords) {
        [$change, $offsetBefore, $offsetAfter] = $nights[$kind];
        $changeDay = (new DateTimeImmutable('@' . $change))->setTimezone($zone)->format('Y-m-d');

        // ---- "is tomorrow": 24 hours before a 10:00 start on the day of the change ----
        $id = $nextId++;
        reminderEvent($id, 'Selftest zones ' . $kind . ' ten', $changeDay . ' 10:00:00', $zoneName);
        $start   = momentOf($changeDay . ' 10:00:00', $zoneName);
        $early   = runJobAt($start - 86400 - 1200);   // 24 hours and 20 minutes before: seconds between moments, which is what "24 hours" means
        $tooSoon = reminders($id, '24h');
        $onTime  = runJobAt($start - 86400);
        $made    = reminders($id, '24h');
        $again   = runJobAt($start - 86400 + 600);
        ok(
            'T ' . $zoneName . ' (' . $where . '), the night the clocks ' . $kindWords . ': the "is tomorrow" reminder for a 10:00 start is made exactly 24 hours before the START ('
                . $clockAt($start - 86400) . ', not 10:00 by the clock), not 20 minutes too soon, and ONCE',
            $tooSoon === 0 && $made === 1 && reminders($id, '24h') === 1 && str_starts_with($onTime, 'OK ') === true && str_starts_with($early, 'OK ') === true && str_starts_with($again, 'OK ') === true,
            'made after the early run: ' . $tooSoon . '; after the run at 24 hours: ' . $made . '; after a third run: ' . reminders($id, '24h') . '; the job said: ' . $early . ' | ' . $onTime . ' | ' . $again
        );
        $byTheClock = momentOf((new DateTimeImmutable($changeDay . ' 10:00:00', $zone))->modify('-1 day')->format('Y-m-d H:i:s'), $zoneName);
        note($zoneName . ', ' . $kind . ': the start is ' . $clockAt($start) . '; 24 hours before is ' . $clockAt($start - 86400) . '; "the same time the day before" would have been '
            . (($start - $byTheClock) / 3600) . ' hours before.');

        if ($kind === 'forward') {
            // ---- a start in the hour that does not exist: its reminder is not skipped ----
            $id      = $nextId++;
            $missing = gmdate('Y-m-d H:i:s', $change + $offsetBefore + 1800);
            reminderEvent($id, 'Selftest zones missing hour', $missing, $zoneName);
            $start   = momentOf($missing, $zoneName);
            $early   = runJobAt($start - 3600 - 2400);
            $tooSoon = reminders($id, '1h');
            $onTime  = runJobAt($start - 3600);
            // ---- 00:20 on the day AFTER the 23-hour day: 24 hours before is two days before on the calendar ----
            $earlyId   = $nextId++;
            $earlyName = 'Selftest zones early after the short day ' . $earlyId;
            $nextDay   = (new DateTimeImmutable($changeDay, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
            reminderEvent($earlyId, $earlyName, $nextDay . ' 00:20:00', $zoneName);
            q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (?, ?, 1, 'going', 'confirmed')", 'ii', [$earlyId, MEMBER]);
            $earlyStart = momentOf($nextDay . ' 00:20:00', $zoneName);
            $earlyRan   = runJobAt($earlyStart - 86400);
            $namedDay   = 'Reminder: ' . $earlyName . ' on ' . (new DateTimeImmutable($nextDay, new DateTimeZone('UTC')))->format('l j M');
            ok(
                'T ' . $zoneName . ': a 00:20 start on the day after the 23-hour day. 24 hours before it is ' . $clockAt($earlyStart - 86400) . ', two days before on the calendar, so the reminder NAMES THE DAY and does not say "tomorrow"',
                (new DateTimeImmutable('@' . ($earlyStart - 86400)))->setTimezone($zone)->modify('+2 days')->format('Y-m-d') === $nextDay
                    && reminderSubjects($earlyName) === [$namedDay] && reminders($earlyId, '24h') === 1 && str_starts_with($earlyRan, 'OK ') === true,
                'subjects tried: ' . json_encode(reminderSubjects($earlyName)) . '; looked for ' . json_encode($namedDay) . '; recorded: ' . reminders($earlyId, '24h') . '; the job said: ' . $earlyRan
            );
            ok(
                'T ' . $zoneName . ': a start at ' . substr($missing, 11, 5) . ' on the night that hour is MISSING is not skipped: its one-hour reminder is made once, an hour before the moment the clock really reaches ('
                    . $clockAt($start) . ')',
                $tooSoon === 0 && reminders($id, '1h') === 1 && str_starts_with($onTime, 'OK ') === true,
                'after the early run: ' . $tooSoon . '; after the run an hour before: ' . reminders($id, '1h') . '; the job said: ' . $early . ' | ' . $onTime
            );
        } else {
            // ---- a start in the hour that happens twice: one reminder, not two ----
            $id       = $nextId++;
            $repeated = gmdate('Y-m-d H:i:s', $change + $offsetAfter + 1800);
            reminderEvent($id, 'Selftest zones repeated hour', $repeated, $zoneName);
            $first   = $change - ($offsetBefore - $offsetAfter) + 1800; // the first time the clock shows it
            $second  = $change + 1800;                                  // and the second
            $start   = momentOf($repeated, $zoneName);                  // the one PHP takes it to mean
            $runOne  = runJobAt($first - 3600);
            $afterOne = reminders($id, '1h');
            $runTwo  = runJobAt($second - 3600);
            ok(
                'T ' . $zoneName . ': a start at ' . substr($repeated, 11, 5) . ' on the night that hour happens TWICE gets ONE one-hour reminder, not two: made for the happening the portal takes it to mean (the '
                    . ($start === $first ? 'first' : 'second') . '), and not again an hour before the other',
                in_array($start, [$first, $second], true) === true && $afterOne === ($start === $first ? 1 : 0) && reminders($id, '1h') === 1
                    && str_starts_with($runOne, 'OK ') === true && str_starts_with($runTwo, 'OK ') === true,
                'after the run an hour before the first happening: ' . $afterOne . '; after the run an hour before the second: ' . reminders($id, '1h') . '; the job said: ' . $runOne . ' | ' . $runTwo
            );

            // ---- the day-of summary: between six and nine on the EVENT's own clock ----
            $id = $nextId++;
            reminderEvent($id, 'Selftest zones day of', $changeDay . ' 10:00:00', $zoneName);
            // The morning BEFORE: it is seven o'clock on the event's clock, but not the event's day. Nothing may be made.
            $dayBefore = (new DateTimeImmutable($changeDay, new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
            $wrongDay  = runJobAt(momentOf($dayBefore . ' 07:00:00', $zoneName));
            $onWrongDay = reminders($id, 'day');
            $tooEarly = runJobAt(momentOf($changeDay . ' 05:30:00', $zoneName));
            $before   = reminders($id, 'day');
            $morning  = runJobAt(momentOf($changeDay . ' 07:00:00', $zoneName));
            $made     = reminders($id, 'day');
            $later    = runJobAt(momentOf($changeDay . ' 08:15:00', $zoneName));
            ok(
                'T ' . $zoneName . ': the "today" summary for an event on the 25-hour day is made at 07:00 on the EVENT\'s clock ON ITS OWN DAY: not at 07:00 the morning before, not at 05:30, and once',
                $onWrongDay === 0 && $before === 0 && $made === 1 && reminders($id, 'day') === 1 && str_starts_with($morning, 'OK ') === true && str_starts_with($wrongDay, 'OK ') === true,
                'after 07:00 the day before: ' . $onWrongDay . '; after 05:30: ' . $before . '; after 07:00: ' . $made . '; after 08:15: ' . reminders($id, 'day') . '; the job said: ' . $wrongDay . ' | ' . $tooEarly . ' | ' . $morning . ' | ' . $later
            );

            // ---- 24 hours before a start late on the 25-hour day is ALREADY that day: nothing is sent ----
            $id   = $nextId++;
            $name = 'Selftest zones late on the long day ' . $id;
            reminderEvent($id, $name, $changeDay . ' 23:30:00', $zoneName);
            q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (?, ?, 1, 'going', 'confirmed')", 'ii', [$id, MEMBER]);
            $start   = momentOf($changeDay . ' 23:30:00', $zoneName);
            $ran     = runJobAt($start - 86400);
            $logRow  = one("SELECT recipientCount FROM tblEventReminderLog WHERE eventID = ? AND reminderType = '24h'", 'i', [$id]);
            $told    = (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityType = 'EventReminderNotSent' AND activityDescription LIKE ?", 's', ['Event #' . $id . ' type=24h%'])['n'];
            $again   = runJobAt($start - 86400 + 600);
            ok(
                'T ' . $zoneName . ': a 23:30 start on the 25-hour day. 24 hours before it is ' . $clockAt($start - 86400) . ', already the event\'s own day, so "is tomorrow" would be untrue: the reminder is recorded as made, NOTHING is sent to the member who is going, the activity log says why, and a later run does not try again',
                (new DateTimeImmutable('@' . ($start - 86400)))->setTimezone($zone)->format('Y-m-d') === $changeDay && $logRow !== null && (int) $logRow['recipientCount'] === 0
                    && reminderSubjects($name) === [] && $told === 1 && reminders($id, '24h') === 1 && str_starts_with($ran, 'OK ') === true && str_starts_with($again, 'OK ') === true,
                'recorded: ' . json_encode($logRow) . '; subjects tried: ' . json_encode(reminderSubjects($name)) . '; "not sent" lines in the activity log: ' . $told . '; the job said: ' . $ran . ' | ' . $again
            );
        }

        // ---- the job called every 30 minutes across this change night: a 23:55 start on the day of the change, and one on the day after ----
        // With the job on the hour and half hour, the first call inside a 23:55 start's window is at midnight, on
        // the event's OWN day: a late call. It must SEND, with the day named. Only when exactly 24 hours before
        // the start is itself the event's own day (the 25-hour day) is nothing sent.
        $dayAfter = (new DateTimeImmutable($changeDay, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $pair     = [];
        foreach (['on the day of the change' => $changeDay, 'on the day after' => $dayAfter] as $which => $day) {
            $id = $nextId++;
            reminderEvent($id, 'Selftest zones half-hourly ' . $id, $day . ' 23:55:00', $zoneName);
            q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (?, ?, 1, 'going', 'confirmed')", 'ii', [$id, MEMBER]);
            $pair[$which] = [$id, 'Selftest zones half-hourly ' . $id, $day . ' 23:55:00'];
        }
        [$runs, $notOk] = runJobEveryHalfHour(momentOf($changeDay . ' 23:55:00', $zoneName) - 86400 - 3600, momentOf($dayAfter . ' 23:55:00', $zoneName) - 86400 + 3600);
        $wantKinds = $kind === 'back' ? ['on the day of the change' => 'held', 'on the day after' => 'named'] : ['on the day of the change' => 'tomorrow', 'on the day after' => 'named'];
        $pairWrong = [];
        foreach ($pair as $which => [$id, $name, $clock]) {
            $kindHere = dayBeforeKind($clock, $zoneName);
            $done     = dayBeforeDone($id, $name);
            $heldLine = $kindHere === 'held' ? (count($done['notSent']) === 1 && str_contains($done['notSent'][0], 'exactly 24 hours before its start') === true
                && str_contains($done['notSent'][0], 'longer than 24 hours') === true && str_contains($done['notSent'][0], '25-hour') === false) : $done['notSent'] === [];
            if ($kindHere !== $wantKinds[$which] || $done['subjects'] !== dayBeforeSubjects($name, $clock, $kindHere) || $done['recorded'] !== 1 || $heldLine === false) {
                $pairWrong[] = '23:55 ' . $which . ': worked out as "' . $kindHere . '" (must be "' . $wantKinds[$which] . '"); subjects tried ' . json_encode($done['subjects']) . '; recorded ' . $done['recorded']
                    . '; "not sent" lines ' . json_encode($done['notSent']);
            }
        }
        ok(
            'T ' . $zoneName . ', the night the clocks ' . $kindWords . ', the job called every 30 minutes (' . $runs . ' runs): a 23:55 start on the day of the change '
                . ($kind === 'back' ? 'gets NO day-before reminder (exactly 24 hours before it is already its own day, and the log line says that and nothing else)' : 'gets its reminder saying "tomorrow"')
                . '; a 23:55 start on the day AFTER gets its reminder although the first call in its window is at midnight on its own day: it is SENT, with the day named',
            $pairWrong === [] && $notOk === [] && $runs >= 50,
            json_encode($pairWrong) . '; runs that did not end OK: ' . json_encode(array_slice($notOk, 0, 3))
        );
    }
}

// ---- A CALL THAT IS MERELY LATE, on an ORDINARY week (the second check's problem 4) ----
// The job is called on every half hour for a week in which no clock changes in any of these zones. Each zone has
// one event that starts at 23:55 on its own clock. The window for its day-before reminder is half an hour wide
// and straddles midnight, so in every zone a whole or a half hour from UTC the first call inside it is at 00:00
// on the event's OWN day. The first fix round held every one of those back and blamed "a 25-hour day". Each must
// get its reminder: saying "tomorrow" where the call falls on the day before, and naming the day where it is late.
$weekZones = ['Pacific/Honolulu', 'America/Los_Angeles', 'America/New_York', 'America/St_Johns', 'Europe/London', 'Asia/Kolkata', 'Asia/Kathmandu',
    'Australia/Sydney', 'Pacific/Auckland', 'Pacific/Kiritimati'];
// The week is SEARCHED FOR, from 60 days ahead, so that no date is written into this test: the first midnight on
// UTC's clock from which none of the zones changes its clocks for ten days (nor in the two days before it).
$weekStart = 0;
for ($ahead = 60; $ahead < 300 && $weekStart === 0; $ahead++) {
    $from  = intdiv(time(), 86400) * 86400 + $ahead * 86400; // UTC has no clock changes: its midnights are 86,400 seconds apart
    $quiet = true;
    foreach ($weekZones as $zoneName) {
        // getTransitions() answers the state at the first moment and then each change after it: a second entry is a change inside the span.
        $quiet = $quiet && count((new DateTimeZone($zoneName))->getTransitions($from - 2 * 86400, $from + 10 * 86400)) === 1;
    }
    $weekStart = $quiet === true ? $from : 0;
}
if ($weekStart === 0) {
    refuse('no week without a clock change was found for the zones of the "late call" check');
}
$weekEvents = [];
foreach ($weekZones as $i => $zoneName) {
    $id    = $nextId++;
    $clock = gmdate('Y-m-d', $weekStart + (2 + $i % 4) * 86400) . ' 23:55:00'; // days 2 to 5 of the week, as a date on the zone's own calendar
    reminderEvent($id, 'Selftest zones ordinary week ' . $id, $clock, $zoneName);
    q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (?, ?, 1, 'going', 'confirmed')", 'ii', [$id, MEMBER]);
    $weekEvents[$zoneName] = [$id, 'Selftest zones ordinary week ' . $id, $clock];
}
[$weekRuns, $weekNotOk] = runJobEveryHalfHour($weekStart, $weekStart + 7 * 86400);
$weekWrong = [];
$weekKinds = ['held' => 0, 'tomorrow' => 0, 'named' => 0];
foreach ($weekEvents as $zoneName => [$id, $name, $clock]) {
    $kindHere = dayBeforeKind($clock, $zoneName);
    $done     = dayBeforeDone($id, $name);
    $weekKinds[$kindHere]++;
    if ($done['subjects'] !== dayBeforeSubjects($name, $clock, $kindHere) || count($done['subjects']) !== 1 || $done['recorded'] !== 1 || $done['notSent'] !== [] || reminders($id, '1h') !== 1) {
        $weekWrong[] = $zoneName . ' (' . $clock . ', "' . $kindHere . '"): subjects tried ' . json_encode($done['subjects']) . '; recorded ' . $done['recorded'] . '; "not sent" lines ' . json_encode($done['notSent'])
            . '; one-hour reminders ' . reminders($id, '1h');
    }
}
ok(
    'T an ORDINARY week (from ' . gmdate('j M Y', $weekStart) . '), the job called every 30 minutes (' . $weekRuns . ' runs): a 23:55 start gets its day-before reminder in every one of ' . count($weekZones)
        . ' zones, from Honolulu to Kiritimati, ONCE. In ' . $weekKinds['named'] . ' of them the first call in its window is at midnight on the event\'s own day: it is SENT, with the day named, and not held back. In '
        . $weekKinds['tomorrow'] . ' it falls on the day before and says "tomorrow". None is held back, and each gets its one-hour reminder too',
    $weekWrong === [] && $weekNotOk === [] && $weekRuns === 337 && $weekKinds['held'] === 0 && $weekKinds['named'] >= 6 && $weekKinds['tomorrow'] >= 1,
    count($weekWrong) . ' wrong: ' . json_encode(array_slice($weekWrong, 0, 4)) . '; kinds ' . json_encode($weekKinds) . '; runs that did not end OK: ' . json_encode(array_slice($weekNotOk, 0, 3))
);
// ─────────────────────────────────────────────────────────────────────
// The member's own choice, and whose morning summary it is (#666, #616)
// ─────────────────────────────────────────────────────────────────────
// These two are not about time zones, but they are about the SAME JOB, and this
// is the harness that runs it at MANY clock moments against a real database,
// which is what these checks need. Writing a third made-up world would be one
// more to keep in step. Note it is NOT the only harness that runs this job:
// `tools/mail-queue-selftest.php` calls `/cron/event-reminders` over real HTTP
// and asserts on the day-of summary's recipients, so a change to EITHER
// recipient query must re-run that one too. Its own day-of check is satisfied
// by the portal-wide `isAdmin` clause alone, so it would not notice a
// per-organisation flag being dropped. A fixed July date is used for both, in a zone with no clock change that
// month, so nothing here depends on when the suite is run.
$prefClock = '2027-07-14 19:00:00';
$prefZone  = 'Europe/London';
$prefStart = momentOf($prefClock, $prefZone);

// ---- switched OFF: the member who said "going" is not e-mailed ----
$offId   = $nextId++;
$offName = 'Selftest zones reminders off ' . $offId;
reminderEvent($offId, $offName, $prefClock, $prefZone);
q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (?, ?, 1, 'going', 'confirmed')", 'ii', [$offId, MEMBER]);
q("UPDATE tblUsers SET notifyPrefs = ? WHERE userID = ?", 'si', ['{"eventReminders":false}', MEMBER]);
$offRan   = runJobAt($prefStart - 86400);
$offLine  = (string) (one("SELECT activityDescription AS d FROM tblActivityLogs WHERE activityType = 'EventReminderSent' AND activityDescription LIKE ? ORDER BY logID DESC LIMIT 1", 's', ['Event #' . $offId . ' type=24h%'])['d'] ?? '');
// `tblEventReminderLog.recipientCount` is deliberately NOT asked here. It
// counts only a message a mail service really TOOK, and this part points the
// portal at an SMTP mailbox with nothing in it, so it is 0 for a message that
// went out perfectly well AND 0 for one that was never attempted: a clause
// requiring it to be 0 cannot fail, which makes the check look stricter than
// it is. `reminders()` below proves the reminder was recorded as made.
ok(
    'T #666 the member had switched "Event reminders" off: they answered "going" and NOTHING is written to them. The reminder is still recorded as made, so the job does not try again, and the log line says how many had switched it off',
    mailTo('Reminder: ' . $offName) === []
        && str_contains($offLine, 'off=1') === true && reminders($offId, '24h') === 1 && str_starts_with($offRan, 'OK ') === true,
    'written to: ' . json_encode(mailTo('Reminder: ' . $offName)) . '; recorded as made ' . reminders($offId, '24h') . ' time(s); log line: "' . $offLine . '"; the job said: ' . $offRan
);

// ---- switched ON (and never touched): unchanged from before #666 ----
// Two cases in one check, because "the gate works" and "the gate does not
// over-reach" fail in opposite directions and a test of only the first would
// pass on a job that e-mails nobody at all.
$onId   = $nextId++;
$onName = 'Selftest zones reminders on ' . $onId;
reminderEvent($onId, $onName, $prefClock, $prefZone);
q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (?, ?, 1, 'going', 'confirmed')", 'ii', [$onId, MEMBER]);
q("UPDATE tblUsers SET notifyPrefs = ? WHERE userID = ?", 'si', ['{"eventReminders":true}', MEMBER]);
$onRan  = runJobAt($prefStart - 86400);
$onRow  = one("SELECT recipientCount FROM tblEventReminderLog WHERE eventID = ? AND reminderType = '24h'", 'i', [$onId]);
// And once more with NO preference stored at all, which is what somebody who
// has never opened the page has: a missing key MUST still mean yes.
$noneId   = $nextId++;
$noneName = 'Selftest zones reminders unset ' . $noneId;
reminderEvent($noneId, $noneName, $prefClock, $prefZone);
q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (?, ?, 1, 'going', 'confirmed')", 'ii', [$noneId, MEMBER]);
q("UPDATE tblUsers SET notifyPrefs = NULL WHERE userID = ?", 'i', [MEMBER]);
$noneRan = runJobAt($prefStart - 86400);
$noneRow = one("SELECT recipientCount FROM tblEventReminderLog WHERE eventID = ? AND reminderType = '24h'", 'i', [$noneId]);
ok(
    'T #666 the switch left ON, and never touched at all: both are e-mailed exactly as before, at the member\'s own address. A missing preference means yes, so nobody who has not opened the page loses a reminder',
    mailTo('Reminder: ' . $onName) === ['member.zones@selftest.invalid']
        && mailTo('Reminder: ' . $noneName) === ['member.zones@selftest.invalid']
        && reminders($onId, '24h') === 1 && reminders($noneId, '24h') === 1
        && str_starts_with($onRan, 'OK ') === true && str_starts_with($noneRan, 'OK ') === true,
    'switched on: written to ' . json_encode(mailTo('Reminder: ' . $onName)) . ', recorded as made ' . reminders($onId, '24h') . ' time(s)'
        . '; never set: written to ' . json_encode(mailTo('Reminder: ' . $noneName)) . ', recorded as made ' . reminders($noneId, '24h') . ' time(s)'
        . '; the job said: ' . $onRan . ' | ' . $noneRan
);

// ---- the morning summary belongs to ONE organisation (#616) ----
// The event is the SECOND organisation's. Its own coordinator must be told; the
// installation's global administrator — who holds the older portal-wide
// `isAdmin` flag and is a member of organisation 1 ONLY — must not, because the
// two levels may be different parties.
// The ADDRESSES are the evidence, and the check names the one that must be
// there as well as refusing the one that must not: a check that only asserted
// "the administrator was not told" would also pass on a job that had stopped
// telling anybody. A count cannot do that job here either way — see mailTo()
// for why tblEventReminderLog.recipientCount is 0 in this made-up world even
// for a message that went out exactly as it should.
const ORG_TWO_COORD = 960004;
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [ORG_TWO_COORD, 'Selftest Zones Two Coordinator', 'coord.two.zones@selftest.invalid']);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [ORG_TWO_COORD, ORG_TWO]);
// 🧍 SEVEN PEOPLE: ONE PER GROUND THAT ADMITS, ONE PER CONDITION THAT REFUSES.
//    The summary's query admits somebody on any of FOUR grounds: they
//    coordinate this event, or they hold `tblUserSites.isSiteAdmin`, or
//    `isSiteRootAdmin`, or the portal-wide `tblUsers.isAdmin`. The
//    administrator this part already has holds all THREE ADMINISTRATOR
//    grounds at once (the loop near the top of the file passes its `$isAdmin`
//    into `isAdmin`, `isRootAdmin`, `isSiteAdmin` and `isSiteRootAdmin` alike,
//    though the query never reads `isRootAdmin`), so with that account alone
//    **each of those three clauses could be deleted on its own and this check
//    stayed green** — measured, each in turn.
//
//    BE EXACT ABOUT WHICH THREE, because the first version of this comment
//    said all FOUR and that was an overstatement of its own measurement,
//    caught by the independent check. The COORDINATOR clause was never
//    deletable-green: that administrator coordinates nothing, so deleting it
//    drops the coordinator from the list and the exact-list assertion fails.
//    Claiming a measurement that was never made is worse than claiming none,
//    because the next reader has no reason to re-run it.
//
//    One account holding several flags cannot tell them apart, which is the
//    same trap as a fixture whose two rows differ in more than one way. So
//    each ground gets a person holding it and nothing else.
//
//    Then three more who must be told NOTHING, one per refusing condition,
//    because a condition no fixture can trip is not a condition. Their own
//    comments below say which each one pins. Without them `AND us.isActive = 1`,
//    `WHERE u.isActive = 1` and `AND revokedAt IS NULL` could each change
//    an answer with every check green — measured for all three.
//
//    All of them are inserted AFTER part T's blanket `UPDATE tblUsers SET
//    isActive = 0` for the same reason the coordinator is: before it they
//    would be switched straight off again and could receive nothing.

const ORG_TWO_SITE_ADMIN = 960005;
const ORG_TWO_ROOT_ADMIN = 960006;
const ORG_TWO_EX_MEMBER  = 960007;
const ORG_TWO_GLOBAL     = 960008;
const ORG_TWO_LEFT       = 960009;
const ORG_TWO_EX_COORD   = 960010;
foreach (
    [
        // [userID, name, address, the ACCOUNT's isActive, portal-wide isAdmin, the MEMBERSHIP's isActive, isSiteAdmin, isSiteRootAdmin]
        [ORG_TWO_SITE_ADMIN, 'Selftest Zones Two Site Admin',   'siteadmin.two.zones@selftest.invalid',   1, 0, 1, 1, 0],
        [ORG_TWO_ROOT_ADMIN, 'Selftest Zones Two Root Admin',   'rootadmin.two.zones@selftest.invalid',   1, 0, 1, 0, 1],
        // The portal-wide flag's own person: a global administrator who IS an
        // active member of this organisation, with neither per-organisation
        // flag. They SHOULD be told, and they are the only one who proves that
        // clause still admits anybody — the administrator at the top of this
        // file holds all three ADMINISTRATOR grounds, so dropping
        // `u.isAdmin = 1` left every check green until this row existed
        // (measured).
        [ORG_TWO_GLOBAL,     'Selftest Zones Two Global Admin', 'globaladmin.two.zones@selftest.invalid', 1, 1, 1, 0, 0],
        // 🚫 THE THREE WHO MUST BE TOLD NOTHING, one per refusing condition.
        //    Each would be admitted by a qualifying ground if that condition
        //    stopped being applied, so each makes one condition load-bearing.
        // Membership of this organisation switched off, account live: this is
        // what being removed from an organisation looks like. Pins
        // `AND us.isActive = 1` on the JOIN.
        [ORG_TWO_EX_MEMBER,  'Selftest Zones Two Ex Member',    'exmember.two.zones@selftest.invalid',    1, 0, 0, 1, 1],
        // The other way round: the ACCOUNT is switched off and the membership
        // is live. They hold EVERY qualifying flag on purpose, so the only thing
        // keeping them out is the account test — which both sharpens the pin and
        // closes a hole the independent check found by moving two brackets:
        // `… isSiteRootAdmin = 1) OR (u.isAdmin = 1` keeps the parentheses
        // balanced, passes `php -l`, and lifts the whole WHERE off anybody
        // holding the portal-wide flag, account test included. Pins
        // `WHERE u.isActive = 1`, which was deletable with all
        // 134 checks green until this row existed — found by the independent
        // check, which planted it and measured it. A person whose account has
        // been closed went on being sent the organisation's morning diary.
        [ORG_TWO_LEFT,       'Selftest Zones Two Closed Account', 'closed.two.zones@selftest.invalid',    0, 1, 1, 1, 1],
        // An active member with no administrator flag at all, who is a
        // coordinator of the event whose appointment has been REVOKED. Pins
        // `AND revokedAt IS NULL` inside the coordinator sub-select: with only
        // an unrevoked coordinator in this world that condition could be
        // deleted and nothing moved, so a coordinator who had been stood down
        // kept receiving the diary.
        [ORG_TWO_EX_COORD,   'Selftest Zones Two Ex Coordinator', 'excoord.two.zones@selftest.invalid',   1, 0, 1, 0, 0],
    ] as [$uid, $uname, $umail, $accountActive, $isPortalAdmin, $memberActive, $isSiteAdmin, $isSiteRootAdmin]
) {
    // isAdmin is 0 for the two per-organisation people on purpose: the
    // portal-wide flag is the one clause THEY must not be let in by, or they
    // prove nothing about the flags this package added.
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, ?, ?, 0)', 'issii', [$uid, $uname, $umail, $accountActive, $isPortalAdmin]);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, ?, ?, ?)', 'iiiii', [$uid, ORG_TWO, $memberActive, $isSiteAdmin, $isSiteRootAdmin]);
}
// 🔌 THE ADMINISTRATOR HAS TO BE SWITCHED BACK ON, AND THIS IS THE WHOLE
//    POINT OF THE CHECK BELOW. Part T switches every account but the one
//    member off (see the note at its head), and the morning summary's query
//    — unlike the day-before reminder's — asks for `u.isActive = 1`. So a
//    first version of this check PASSED ON THE CODE AS IT STOOD BEFORE THIS
//    PACKAGE, measured by planting `git show HEAD:web/_apps/cron/event-reminders.php`'s
//    own query: the global administrator was never written to, not because
//    the organisation was judged, but because the administrator was switched
//    off and could not have been written to about ANYTHING. A fixture that
//    cannot reach the branch tests nothing.
$adminWasActive = (int) (one('SELECT isActive FROM tblUsers WHERE userID = ?', 'i', [ADMIN])['isActive'] ?? 0);
q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [ADMIN]);
$dayId    = $nextId++;
$dayName  = 'Selftest zones morning summary ' . $dayId;
$dayClock = '2027-07-15 10:00:00';
q(
    "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) VALUES (?, ?, ?, ?, ?, NULL, ?, 'published', 1, 0)",
    'iissss', [$dayId, ORG_TWO, 'selftest-zones-day-' . $dayId, $dayName, $dayClock, $prefZone]
);
q('INSERT INTO tblEventCoordinators (eventID, userID, grantedByID) VALUES (?, ?, NULL)', 'ii', [$dayId, ORG_TWO_COORD]);
// The coordinator who was stood down. `revokedAt` is the only thing that
// separates them from the one above, so they are what makes the sub-select's
// `AND revokedAt IS NULL` mean something.
q('INSERT INTO tblEventCoordinators (eventID, userID, grantedByID, revokedAt) VALUES (?, ?, NULL, NOW())', 'ii', [$dayId, ORG_TWO_EX_COORD]);
$dayRan = runJobAt(momentOf('2027-07-15 07:00:00', $prefZone));
// And the OTHER half, the day after: an event of organisation 1, which the
// administrator IS a member of. They must be told about that one. Without
// this half, "the administrator was not told" could still mean "the
// administrator can never be told", which is exactly the fault above.
$ownId    = $nextId++;
$ownName  = 'Selftest zones own summary ' . $ownId;
reminderEvent($ownId, $ownName, '2027-07-16 10:00:00', $prefZone);
$ownRan   = runJobAt(momentOf('2027-07-16 07:00:00', $prefZone));
q('UPDATE tblUsers SET isActive = ? WHERE userID = ?', 'ii', [$adminWasActive, ADMIN]);
// Sorted, because the query has no ORDER BY and one message is written per
// address: the ORDER the three come out in is not something to pin.
$twoGot = mailTo('📋 Today: ' . $dayName);
$ownGot = mailTo('📋 Today: ' . $ownName);
sort($twoGot);
ok(
    'T #616 the morning summary for the SECOND organisation\'s event goes to each of the FOUR kinds of person the query admits, one person per clause: its coordinator, the holder of `isSiteAdmin`, the holder of `isSiteRootAdmin`, and a portal-wide administrator who IS one of its members. It goes to NONE of the four people who must be refused: the installation\'s global administrator, who belongs to organisation 1 only; somebody whose membership of this organisation has been switched off; somebody whose ACCOUNT has been switched off; and a coordinator whose appointment was revoked. The SAME global administrator IS told about organisation 1\'s own event the next morning, so none of those absences can be "unreachable"',
    $twoGot === ['coord.two.zones@selftest.invalid', 'globaladmin.two.zones@selftest.invalid', 'rootadmin.two.zones@selftest.invalid', 'siteadmin.two.zones@selftest.invalid']
        && $ownGot === ['admin.zones@selftest.invalid']
        && reminders($dayId, 'day') === 1 && reminders($ownId, 'day') === 1
        && str_starts_with($dayRan, 'OK ') === true && str_starts_with($ownRan, 'OK ') === true,
    'the other organisation\'s event, written to ' . json_encode($twoGot)
        . ' (expected exactly its coordinator, its isSiteAdmin, its isSiteRootAdmin and the global administrator who IS one of its members, sorted.'
        . ' admin.zones@selftest.invalid among them is #616 itself — the installation\'s global administrator, a member of organisation 1 only.'
        . ' exmember.two.zones@selftest.invalid among them means `AND us.isActive = 1` stopped mattering, so somebody removed from an organisation still gets its diary.'
        . ' closed.two.zones@selftest.invalid among them means `WHERE u.isActive = 1` stopped mattering, so a closed account still gets it.'
        . ' excoord.two.zones@selftest.invalid among them means `AND revokedAt IS NULL` stopped mattering, so a coordinator who was stood down still gets it.'
        . ' A SHORTER list means one of the FOUR admitting grounds — the coordinator sub-select,'
        . ' `us.isSiteAdmin`, `us.isSiteRootAdmin` or `u.isAdmin` — has stopped admitting the person it is for);'
        . ' organisation 1\'s own event, written to ' . json_encode($ownGot)
        . ' (an empty list here means the administrator was unreachable all along, so every absence above proved nothing);'
        . ' recorded as made ' . reminders($dayId, 'day') . ' and ' . reminders($ownId, 'day') . ' time(s); the job said: ' . $dayRan . ' | ' . $ownRan
);

// An event stored with text that is not a zone: worked out on the ORGANISATION's clock, not London's and not the server's.
$id    = $nextId++;
$clock = clockIn(45, '10:00:00');
reminderEvent($id, 'Selftest zones no zone', $clock, 'London');
$onOrgClock = momentOf($clock, ORG_ZONE);
$asLondon   = runJobAt(momentOf($clock, 'Europe/London') - 3600);
$wrongly    = reminders($id, '1h');
$said       = runJobAt($onOrgClock - 3600);
ok(
    'T an event stored with text that is not a zone ("London") is reminded about on its ORGANISATION\'s clock (' . ORG_ZONE . '): an hour before 10:00 there, and not an hour before 10:00 in London',
    $wrongly === 0 && reminders($id, '1h') === 1 && str_starts_with($said, 'OK ') === true,
    'after the run an hour before 10:00 London time: ' . $wrongly . '; after the run an hour before 10:00 in ' . ORG_ZONE . ': ' . reminders($id, '1h') . '; the job said: ' . $asLondon . ' | ' . $said
);
// The same for the two other readers of an event's zone that fall back for themselves: the venue code and the
// morning summary. Each is given a time at which the organisation's clock and UTC's give DIFFERENT answers.
$hallId    = $nextId++;
$hallClock = clockIn(46, '01:00:00');
reminderEvent($hallId, 'Selftest zones no zone, hall', $hallClock, 'London');
$hallDay   = (new DateTimeImmutable($hallClock, new DateTimeZone(ORG_ZONE)))->setTimezone(new DateTimeZone(VENUE_ZONE))->format('Y-m-d');
$hallIfUtc = (new DateTimeImmutable($hallClock, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(VENUE_ZONE))->format('Y-m-d');
$hall      = child(['task' => 'venue', 'eventId' => $hallId, 'venueId' => VENUE]);
$mornId    = $nextId++;
$mornDay   = substr(clockIn(47, '00:00:00'), 0, 10);
reminderEvent($mornId, 'Selftest zones no zone, morning', $mornDay . ' 10:00:00', 'London');
$mornSaid  = runJobAt(momentOf($mornDay . ' 07:00:00', ORG_ZONE));
ok(
    'T an event stored with text that is not a zone is read on its ORGANISATION\'s clock by the venue code (by the hall\'s clock it starts on ' . $hallDay . ', not ' . $hallIfUtc . ' as it would read in UTC) and by the morning summary (made at 07:00 in ' . ORG_ZONE . ' on its day)',
    $hallDay !== $hallIfUtc && $hall['threw'] === '' && ($hall['days'][0] ?? '') === $hallDay && reminders($mornId, 'day') === 1 && str_starts_with($mornSaid, 'OK ') === true,
    'the venue code: ' . json_encode($hall) . '; the morning summary made: ' . reminders($mornId, 'day') . '; the job said: ' . $mornSaid
);
setting('mail.provider', $routeWas);
if ($switchedOff !== []) {
    q('UPDATE tblUsers SET isActive = 1 WHERE userID IN (' . implode(',', $switchedOff) . ')');
}

// =============================================================================
// X. #777 PART TWO AND #783 — EVENT PAGES ON THE MORNING THE CLOCKS GO FORWARD,
//    AND "STILL TO COME" ON THE ORGANISATION'S CLOCK
// =============================================================================
// 🚨 #549's TRAP, which is why every reading here is FIXED: the old pages and
//    the new agree on every ordinary day. An event at 02:30 on
//    2028-09-24 in Pacific/Auckland is inside the hour that day skips, so the
//    old `strtotime()`/`new DateTime()` printed it as 03:30 -- the premise is
//    asserted first, so nothing below can pass vacuously.
//
//    #783 is different in kind: the old queries compared a wall-clock booking
//    with the DATABASE's `NOW()`. The premise there is that the database's
//    clock really is hours away from the organisation's -- asserted, from a
//    fresh connection like the pages make -- and the check runs in TWO zones,
//    one ahead of UTC and one behind, because each direction hid a different
//    fault: ahead kept finished bookings, behind lost bookings still to come.
head('X. #777 part two and #783: event pages on the morning the clocks go forward, and "still to come" on the organisation\'s clock');
$xOrgZoneWas = one('SELECT timezone FROM tblSites WHERE siteID = 1')['timezone'] ?? null;
$xMemberJar  = signIn('member');
$withoutConsumingIds(static function () use ($adminJar, $xMemberJar, $creds): void {
    // ---- the event pages ---------------------------------------------------
    orgZone(1, 'Pacific/Auckland');
    $skip      = '2028-09-24 02:30:00';
    $skipName  = 'Selftest zones skipped hour 960901';
    $skipSlug  = 'selftest-zones-skipped-960901';
    $landName  = 'Selftest zones landing 960902';
    $landSlug  = 'selftest-zones-landing-960902';
    q(
        "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) VALUES (960901, 1, ?, ?, ?, '2028-09-24 04:00:00', 'Pacific/Auckland', 'published', 1, 0)",
        'sss', [$skipSlug, $skipName, $skip]
    );
    // A public event on a DIFFERENT clock from the organisation's, at a reading
    // New York skips: its countdown must aim at New York's instant.
    q(
        "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) VALUES (960902, 1, ?, ?, '2028-03-12 02:30:00', NULL, 'America/New_York', 'published', 1, 0)",
        'ss', [$landSlug, $landName]
    );
    // #805: an EVENING event on the same day. X03 used to look only at the
    // 02:30 one, and 02:30 falls inside its own day whichever clock the day
    // columns are built on -- a check that could not fail. 23:30 cannot: on a
    // column built on PHP's (Auckland's) zone it lands in the NEXT day.
    $eveName = 'Selftest zones evening 960903';
    q(
        "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) VALUES (960903, 1, 'selftest-zones-evening-960903', ?, '2028-09-24 23:30:00', '2028-09-24 23:50:00', 'Pacific/Auckland', 'published', 1, 0)",
        's', [$eveName]
    );
    // #806 (b): a multi-day event that ENDS EARLIER IN THE DAY than it began.
    // The month and year grids walked from the start reading while it was
    // still `<=` the end reading, so 09-27 10:00 was "after" 09-27 09:00 and
    // the last day was left out.
    $spanName = 'Selftest zones three days 960904';
    q(
        "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) VALUES (960904, 1, 'selftest-zones-three-days-960904', ?, '2028-09-25 10:00:00', '2028-09-27 09:00:00', 'Pacific/Auckland', 'published', 1, 0)",
        's', [$spanName]
    );
    // #806 (a): Nuuk's clocks go forward at 23:00, so 23:30 on 2027-03-27
    // does not exist there and a MOMENT made of it is on the 28th.
    q(
        "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) VALUES (960905, 1, 'selftest-zones-nuuk-960905', 'Selftest zones Nuuk 960905', '2027-03-27 23:30:00', '2027-03-27 23:55:00', 'America/Nuuk', 'published', 1, 0)"
    );
    $moved = (new DateTimeImmutable($skip, new DateTimeZone('Pacific/Auckland')))->format('Y-m-d H:i:s');
    ok('X00 premise: ' . $skip . ' really does not exist in Pacific/Auckland (PHP moves it to ' . $moved . ')', $moved !== $skip);

    $eventPage = req($adminJar, 'GET', '/calendar/event?slug=' . $skipSlug);
    $eventText = words($eventPage['body']);
    ok(
        'X01 the event page prints the stored 2:30 AM, not the 3:30 AM the old new DateTime() made of it',
        $eventPage['status'] === 200 && str_contains($eventText, '2:30 AM') === true && str_contains($eventText, '3:30 AM') === false
            && str_contains($eventText, 'Sunday, 24 September 2028') === true,
        said($eventPage) . '; ' . substr($eventText, max(0, (int) strpos($eventText, 'When') - 10), 220)
    );
    $month = req($adminJar, 'GET', '/calendar?view=month&date=2028-09');
    ok(
        'X02 the month grid puts it in the 24th\'s cell at 02:30',
        $month['status'] === 200
            && tz_find('#data-date|portal-cal-month-cell#', $month['body']) === 1
            && tz_find('#pill-time">02:30</span>\s*<span class="portal-cal-month-pill-name">\s*' . preg_quote($skipName, '#') . '#', $month['body']) === 1,
        said($month)
    );
    $day = req($adminJar, 'GET', '/calendar?view=day&date=2028-09-24');
    ok(
        'X03 the day column draws the skipped-hour event from 02:30 to 04:00 AND the 23:30 event in the 24th\'s own column (#805: 23:30 lands in the NEXT day on columns built on PHP\'s zone; 02:30 alone could not fail)',
        $day['status'] === 200 && str_contains($day['body'], 'title="02:30 – 04:00 · ' . $skipName . '"') === true
            && str_contains($day['body'], 'title="23:30 – 23:50 · ' . $eveName . '"') === true,
        said($day)
    );

    // ---- #806 (b): the last day of a multi-day event, in the month AND year grids ----
    // A cell is the text from its own day link up to the next cell's.
    $cellOf = static function (string $html, string $date, string $nextCell): string {
        $at = strpos($html, 'date=' . $date . '"');
        if ($at === false) {
            return '';
        }
        $next = strpos($html, $nextCell, $at + 1);

        return substr($html, $at, $next === false ? 6000 : $next - $at);
    };
    $monthAgain = req($adminJar, 'GET', '/calendar?view=month&date=2028-09');
    $m25 = $cellOf($monthAgain['body'], '2028-09-25', 'class="portal-cal-month-cell-date"');
    $m27 = $cellOf($monthAgain['body'], '2028-09-27', 'class="portal-cal-month-cell-date"');
    $m28 = $cellOf($monthAgain['body'], '2028-09-28', 'class="portal-cal-month-cell-date"');
    ok(
        'X03b the month grid shows the 25th-10:00-to-27th-09:00 event on its LAST day, the 27th (and on the 25th, and not on the 28th)',
        $monthAgain['status'] === 200 && str_contains($m25, $spanName) === true && str_contains($m27, $spanName) === true && str_contains($m28, $spanName) === false,
        said($monthAgain) . '; 25th has it: ' . json_encode(str_contains($m25, $spanName)) . ', 27th: ' . json_encode(str_contains($m27, $spanName)) . ', 28th: ' . json_encode(str_contains($m28, $spanName))
    );
    $yearPage = req($adminJar, 'GET', '/calendar?view=year&date=2028-09-24');
    $y27 = $cellOf($yearPage['body'], '2028-09-27', '<a class="portal-cal-yearplan-num');
    $y28 = $cellOf($yearPage['body'], '2028-09-28', '<a class="portal-cal-yearplan-num');
    ok(
        'X03c the year grid shows it on the 27th too, and not on the 28th',
        $yearPage['status'] === 200 && str_contains($y27, $spanName) === true && str_contains($y28, $spanName) === false,
        said($yearPage) . '; 27th has it: ' . json_encode(str_contains($y27, $spanName)) . ', 28th: ' . json_encode(str_contains($y28, $spanName))
    );

    // ---- #806 (a): the attendance register offers the day the event is ON ----
    ok(
        'X03d premise: 2027-03-27 23:30 does not exist in America/Nuuk, and a moment made of it is on the 28th',
        (new DateTimeImmutable('2027-03-27 23:30:00', new DateTimeZone('America/Nuuk')))->format('Y-m-d') === '2027-03-28'
    );
    $register = req($adminJar, 'GET', '/calendar/event/attendance?eventID=960905');
    ok(
        'X03e the attendance register offers 27 March for the Nuuk event at 23:30, and not the 28th the old moment-then-midnight gave',
        $register['status'] === 200 && str_contains($register['body'], 'option value="2027-03-27"') === true
            && str_contains($register['body'], 'option value="2027-03-28"') === false,
        said($register) . '; ' . (tz_findAll('#option value="(\d{4}-\d{2}-\d{2})"#', $register['body'], $offered) > 0 ? implode(', ', $offered[1]) : 'no day offered')
    );
    $list     = req($adminJar, 'GET', '/calendar?view=list&q=' . rawurlencode($skipName));
    $listText = words($list['body']);
    ok(
        'X04 the list view prints 2:30 AM and the right date',
        $list['status'] === 200 && str_contains($listText, $skipName) === true && str_contains($listText, '2:30 AM') === true
            && str_contains($listText, '3:30 AM') === false && str_contains($listText, 'Sunday, 24 September 2028') === true,
        said($list) . '; ' . substr($listText, max(0, (int) strpos($listText, $skipName) - 10), 200)
    );
    $landing = req('', 'GET', '/e/' . $landSlug);
    tz_find('#data-target="([^"]*)"#', $landing['body'], $target);
    ok(
        'X05 the public landing page counts down to NEW YORK\'s instant (2028-03-12T03:30:00-04:00, the skipped 02:30 resolved forward), not Auckland\'s',
        $landing['status'] === 200 && ($target[1] ?? '') === '2028-03-12T03:30:00-04:00'
            && str_contains(words($landing['body']), 'Sunday 12 March 2028, 02:30') === true,
        said($landing) . '; data-target ' . json_encode($target[1] ?? null)
    );
    q('DELETE FROM tblEvents WHERE eventID IN (960901, 960902, 960903, 960904, 960905)');

    // ---- #804 / #805: "still to come", "past" and "today" on the EVENT's clock ----
    // Two events, each on a clock far from the database's and from the
    // organisation's (Auckland, which is PHP's zone on every page):
    //   * one in NEW YORK starting in half an hour -- still to come on its own
    //     clock, but its reading is hours BEHIND the database's NOW() (so the
    //     old `>= NOW()` dropped it) and hours behind Auckland's clock (so a
    //     page judging "past" on PHP's zone greys it out);
    //   * one in AUCKLAND that began an hour ago -- its reading is hours AHEAD
    //     of the database's NOW(), so the old `>= NOW()` kept it, and the
    //     countdown feed published it as "next".
    $nyZone   = new DateTimeZone('America/New_York');
    $aklZone  = new DateTimeZone('Pacific/Auckland');
    $nySoon   = (new DateTimeImmutable('now', $nyZone))->modify('+30 minutes')->format('Y-m-d H:i:00');
    $aklBegun = (new DateTimeImmutable('now', $aklZone))->modify('-60 minutes')->format('Y-m-d H:i:00');
    // 🌐 BOTH are ticked "Show on the public website" (#493 P1): since then the
    //    countdown feed (X14) shows nothing that is not, so without the tick
    //    X14 would test the switch rather than the clock. Ticking the Auckland
    //    one too keeps "NOT the Auckland one" a real refusal by the clock.
    $nyName   = 'Selftest zones list 960906 New York soon';
    $aklName  = 'Selftest zones list 960907 Auckland begun';
    q(
        "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, status, isPublic, `showOnWebsite`, isDeleted) VALUES (960906, 1, 'selftest-zones-list-960906', ?, ?, DATE_ADD(?, INTERVAL 1 HOUR), 'America/New_York', 'published', 1, 1, 0)",
        'sss', [$nyName, $nySoon, $nySoon]
    );
    q(
        "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, status, isPublic, `showOnWebsite`, isDeleted) VALUES (960907, 1, 'selftest-zones-list-960907', ?, ?, DATE_ADD(?, INTERVAL 2 HOUR), 'Pacific/Auckland', 'published', 1, 1, 0)",
        'sss', [$aklName, $aklBegun, $aklBegun]
    );
    $freshX = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $creds['db_name'], $creds['db_port']);
    $dbNowX = (string) $freshX->query('SELECT NOW() AS n')->fetch_assoc()['n'];
    $freshX->close();
    $aklNow = (new DateTimeImmutable('now', $aklZone))->format('Y-m-d H:i:s');
    ok(
        'X10 premise: the New York reading is BEFORE the database\'s NOW() and before Auckland\'s clock, and the Auckland one is AFTER the database\'s NOW() -- so the old comparisons answer the opposite way and nothing below is vacuous',
        strcmp($nySoon, $dbNowX) < 0 && strcmp($nySoon, $aklNow) < 0 && strcmp($aklBegun, $dbNowX) > 0,
        'New York start ' . $nySoon . ', Auckland start ' . $aklBegun . ', database NOW() ' . $dbNowX . ', Auckland now ' . $aklNow
    );

    $upcoming     = req($adminJar, 'GET', '/calendar?view=list&q=' . rawurlencode('Selftest zones list 96090'));
    $upcomingText = words($upcoming['body']);
    ok(
        'X11 the list\'s "upcoming" keeps the New York event still to come on ITS clock and drops the Auckland one that began an hour ago on ITS clock (#804: the old NOW() did the reverse)',
        $upcoming['status'] === 200 && str_contains($upcomingText, $nyName) === true && str_contains($upcomingText, $aklName) === false,
        said($upcoming) . '; New York listed: ' . json_encode(str_contains($upcomingText, $nyName)) . ', Auckland listed: ' . json_encode(str_contains($upcomingText, $aklName))
    );

    // A card is the text from its own `card h-100` opening up to its event's
    // name; "Today" is the badge between the name and the next card.
    $cardBefore = static function (string $html, string $name): string {
        $at = strpos($html, $name);
        if ($at === false) {
            return '';
        }
        $open = strrpos(substr($html, 0, $at), '<div class="card h-100');

        return $open === false ? '' : substr($html, $open, $at - $open);
    };
    $badgeAfter = static function (string $html, string $name): string {
        $at = strpos($html, $name);
        if ($at === false) {
            return '';
        }
        $next = strpos($html, '<div class="card h-100', $at);

        return substr($html, $at, $next === false ? 3000 : $next - $at);
    };
    $pastView = req($adminJar, 'GET', '/calendar?view=list&past=1&q=' . rawurlencode('Selftest zones list 96090'));
    $nyCard   = $cardBefore($pastView['body'], $nyName);
    $aklCard  = $cardBefore($pastView['body'], $aklName);
    ok(
        'X12 the list does NOT grey out the New York event as past (it is still to come on its own clock, though past on Auckland\'s) -- and DOES grey out the Auckland one that has begun',
        $pastView['status'] === 200 && $nyCard !== '' && $aklCard !== ''
            && str_contains($nyCard, 'opacity-75') === false && str_contains($aklCard, 'opacity-75') === true,
        said($pastView) . '; New York card greyed: ' . json_encode(str_contains($nyCard, 'opacity-75')) . ', Auckland card greyed: ' . json_encode(str_contains($aklCard, 'opacity-75'))
    );
    $nyToday = substr($nySoon, 0, 10) === (new DateTimeImmutable('now', $nyZone))->format('Y-m-d');
    ok(
        'X13 "Today" on the New York card follows New York\'s date (' . ($nyToday === true ? 'today there' : 'not today there') . ')',
        str_contains($badgeAfter($pastView['body'], $nyName), '>Today</span>') === $nyToday,
        'badge shown: ' . json_encode(str_contains($badgeAfter($pastView['body'], $nyName), '>Today</span>'))
    );

    // The public feed of events (#805, #804). Until #493 P4b this read the
    // countdown widget's feed, /widget/countdown.json, which P4b deleted with
    // the widget (D-084). The public door's events feed replaced it: it lists
    // every ticked event that has not FINISHED on its own clock, each with its
    // start as an instant on its own clock, and the door's script picks the
    // next one by that instant (its "next" mode, driven in a real browser by
    // tools/public-door-script-selftest.mjs). So what is pinned here is that
    // the feed tells the truth about BOTH instants: New York's still to come,
    // Auckland's already past — the fault #804 fixed was the old feed calling
    // the Auckland one "next". The door is opened for this one request (a name
    // row and the portal's own address, which the /w/ form needs) and put
    // back exactly as it was.
    $xDoorName = bin2hex(random_bytes(12));
    $xUrlWas   = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'site.url' AND siteID IS NULL");
    $xDoorWas  = one('SELECT publicName, changedAtUtc FROM tblPublicDoorNames WHERE siteID = 1');
    q('DELETE FROM tblPublicDoorNames WHERE siteID = 1');
    q('INSERT INTO tblPublicDoorNames (siteID, publicName, changedAtUtc) VALUES (1, ?, UTC_TIMESTAMP())', 's', [$xDoorName]);
    q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'site.url', ?, '', 0) "
        . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)', 's', ['http://127.0.0.1:' . $GLOBALS['port']]);
    try {
        $feed = req('', 'GET', '/w/' . $xDoorName . '/events.json?limit=50');
    } finally {
        q('DELETE FROM tblPublicDoorNames WHERE siteID = 1');
        if ($xDoorWas !== null) {
            q('INSERT INTO tblPublicDoorNames (siteID, publicName, changedAtUtc) VALUES (1, ?, ?)', 'ss', [$xDoorWas['publicName'], $xDoorWas['changedAtUtc']]);
        }
        if ($xUrlWas === null) {
            q("DELETE FROM tblSettings WHERE settingKey = 'site.url' AND siteID IS NULL");
        } else {
            q("UPDATE tblSettings SET settingValue = ? WHERE settingKey = 'site.url' AND siteID IS NULL", 's', [(string) $xUrlWas['settingValue']]);
        }
    }
    $listed = [];
    foreach ((array) (json_decode($feed['body'], true)['events'] ?? []) as $one) {
        $listed[(string) ($one['id'] ?? '')] = (string) ($one['startsAt'] ?? '');
    }
    $want    = (new DateTimeImmutable($nySoon, $nyZone))->format('c');
    $aklWant = (new DateTimeImmutable($aklBegun, $aklZone))->format('c');
    ok(
        'X14 the public door\'s events feed gives the New York event at New York\'s instant (' . $want . '), still to come, and the Auckland one that has begun at Auckland\'s instant (' . $aklWant . '), already past — so the next one by instant is New York\'s (#804, #805)',
        $feed['status'] === 200 && ($listed['selftest-zones-list-960906'] ?? null) === $want
            && ($listed['selftest-zones-list-960907'] ?? null) === $aklWant
            && strtotime($want) > time() && strtotime($aklWant) < time(),
        said($feed) . '; ' . substr($feed['body'], 0, 300)
    );

    // ---- #804: the other places that decided "still to come", "today" or "begun" ----
    // Each of these was a NOW()/CURDATE()/UTC_TIMESTAMP() comparison that no
    // test reached. The same two events, so X10's premise holds for each.
    $remind     = req($adminJar, 'GET', '/admin/calendar/reminders');
    $remindText = words($remind['body']);
    ok(
        'X15 the reminder preview\'s "next 48 hours" lists the New York event and not the Auckland one that has begun (#804: the old NOW() did the reverse)',
        $remind['status'] === 200 && str_contains($remindText, $nyName) === true && str_contains($remindText, $aklName) === false,
        said($remind) . '; New York listed: ' . json_encode(str_contains($remindText, $nyName)) . ', Auckland listed: ' . json_encode(str_contains($remindText, $aklName))
    );

    // "Today" on each event's own calendar. Whether each is today THERE depends
    // on the hour this runs, so the expectation is worked out from the event's
    // own clock rather than assumed.
    $console     = req($adminJar, 'GET', '/admin/host-console');
    $consoleText = words($console['body']);
    $nyIsToday   = substr($nySoon, 0, 10) === (new DateTimeImmutable('now', $nyZone))->format('Y-m-d');
    $aklIsToday  = substr($aklBegun, 0, 10) === (new DateTimeImmutable('now', $aklZone))->format('Y-m-d');
    ok(
        'X16 the Host Console\'s "starting today" follows each event\'s own date (New York ' . ($nyIsToday === true ? 'today' : 'not today') . ' there, Auckland ' . ($aklIsToday === true ? 'today' : 'not today') . ' there)',
        $console['status'] === 200 && str_contains($consoleText, $nyName) === $nyIsToday && str_contains($consoleText, $aklName) === $aklIsToday,
        said($console) . '; New York listed: ' . json_encode(str_contains($consoleText, $nyName)) . ', Auckland listed: ' . json_encode(str_contains($consoleText, $aklName))
    );

    // The newsletter's "Upcoming events" block, through the real preview page.
    $newsletterWas = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'newsletter.enabled' AND siteID IS NULL")['settingValue'] ?? '0';
    setting('newsletter.enabled', '1');
    q("INSERT INTO tblNewsletter (siteID, title, subject, status) VALUES (1, 'Selftest zones newsletter 804', 'Selftest zones newsletter 804', 'draft')");
    $newsId = (int) one("SELECT newsletterID FROM tblNewsletter WHERE title = 'Selftest zones newsletter 804'")['newsletterID'];
    q("INSERT INTO tblNewsletterContent (newsletterID, blockType, position, payload) VALUES (?, 'events', 0, '{\"days\":7}')", 'i', [$newsId]);
    $letter     = req($adminJar, 'GET', '/newsletter/preview?id=' . $newsId);
    $letterText = words($letter['body']);
    ok(
        'X17 the newsletter\'s "Upcoming events" lists the New York event and not the Auckland one that has begun (#804)',
        $letter['status'] === 200 && str_contains($letterText, $nyName) === true && str_contains($letterText, $aklName) === false,
        said($letter) . '; New York listed: ' . json_encode(str_contains($letterText, $nyName)) . ', Auckland listed: ' . json_encode(str_contains($letterText, $aklName))
    );
    q('DELETE FROM tblNewsletter WHERE newsletterID = ?', 'i', [$newsId]);
    setting('newsletter.enabled', (string) $newsletterWas);

    // The discipleship sweep: a pathway step "RSVP'd going to this event" is
    // completed only once the event has BEGUN -- and that row is permanent.
    // Asked against UTC_TIMESTAMP(), the New York step completed before its
    // event began and the Auckland one hours after.
    q("INSERT INTO tblPathways (siteID, name, isActive) VALUES (1, 'Selftest zones pathway 804', 1)");
    $pathId = (int) one("SELECT pathwayID FROM tblPathways WHERE name = 'Selftest zones pathway 804'")['pathwayID'];
    q("INSERT INTO tblPathwaySteps (pathwayID, sortOrder, name, autoRule, autoRefID) VALUES (?, 1, 'Selftest 804 New York', 'rsvpd_event', 960906), (?, 2, 'Selftest 804 Auckland', 'rsvpd_event', 960907)", 'ii', [$pathId, $pathId]);
    q("INSERT INTO tblPathwayEnrolments (siteID, pathwayID, userID, status) VALUES (1, ?, ?, 'active')", 'ii', [$pathId, MEMBER]);
    q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (960906, ?, 1, 'going', 'confirmed'), (960907, ?, 1, 'going', 'confirmed')", 'ii', [MEMBER, MEMBER]);
    $sweepPage = req($xMemberJar, 'GET', '/discipleship');
    $doneSteps = array_column(q(
        'SELECT s.name FROM tblPathwayProgress pr JOIN tblPathwaySteps s ON s.stepID = pr.stepID WHERE s.pathwayID = ? AND pr.userID = ? ORDER BY s.name',
        'ii', [$pathId, MEMBER]
    ), 'name');
    ok(
        'X18 the discipleship sweep completes the step for the Auckland event that has BEGUN on its own clock, and NOT the one for the New York event still to come (#804: UTC_TIMESTAMP() did the reverse, and the row it writes is permanent)',
        $sweepPage['status'] === 200 && $doneSteps === ['Selftest 804 Auckland'],
        said($sweepPage) . '; completed: ' . json_encode($doneSteps)
    );
    q('DELETE FROM tblPathwayProgress WHERE stepID IN (SELECT stepID FROM tblPathwaySteps WHERE pathwayID = ?)', 'i', [$pathId]);
    q('DELETE FROM tblPathwayEnrolments WHERE pathwayID = ?', 'i', [$pathId]);
    q('DELETE FROM tblPathwaySteps WHERE pathwayID = ?', 'i', [$pathId]);
    q('DELETE FROM tblPathways WHERE pathwayID = ?', 'i', [$pathId]);
    q('DELETE FROM tblEventRSVPs WHERE eventID IN (960906, 960907)');

    // ---- #812: rule 3 counted an event of ANOTHER organisation -------------
    // Rules 1 and 2 of the sweep require the event to belong to the pathway's
    // organisation; rule 3 ("RSVP'd going to this event") did not. Its only
    // organisation test was the RSVP row's own `siteID`, a COPY of the event's
    // organisation made once by `calendar/rsvp.php`. So a step of organisation
    // 1's pathway naming organisation two's event, with an RSVP row stored
    // under organisation 1, was completed -- a permanent progress row
    // organisation 1's leaders see, from an event that was never theirs.
    //
    // NEITHER HALF IS WRITTEN BY ANY PAGE, and that is said rather than
    // hidden: `admin/discipleship/step-save.php` refuses a step naming another
    // organisation's event, and `calendar/rsvp.php` stores an RSVP under the
    // event's own organisation. Both rows are therefore written by hand here,
    // as a restore or a hand-written row would leave them. The person is a
    // member of BOTH organisations, the ordinary case for somebody who can
    // RSVP to either.
    //
    // The same pathway holds a step naming organisation 1's OWN event that has
    // begun (960907, X18's), so the check cannot pass by the sweep simply not
    // running: it must complete that one and not the other.
    $ev812Zone  = 'America/Santiago';
    $ev812Start = (new DateTimeImmutable('now', new DateTimeZone($ev812Zone)))->modify('-6 hours')->format('Y-m-d H:i:00');
    q(
        "INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) VALUES (960908, ?, 'selftest-zones-812-960908', 'Selftest zones 812 other organisation', ?, DATE_ADD(?, INTERVAL 1 HOUR), ?, 'published', 1, 0)",
        'isss', [ORG_TWO, $ev812Start, $ev812Start, $ev812Zone]
    );
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [MEMBER, ORG_TWO]);
    q("INSERT INTO tblPathways (siteID, name, isActive) VALUES (1, 'Selftest zones pathway 812 one', 1)");
    $path812One = (int) one("SELECT pathwayID FROM tblPathways WHERE name = 'Selftest zones pathway 812 one'")['pathwayID'];
    q("INSERT INTO tblPathwaySteps (pathwayID, sortOrder, name, autoRule, autoRefID) VALUES (?, 1, 'Selftest 812 own organisation', 'rsvpd_event', 960907), (?, 2, 'Selftest 812 other organisation', 'rsvpd_event', 960908)", 'ii', [$path812One, $path812One]);
    q("INSERT INTO tblPathwayEnrolments (siteID, pathwayID, userID, status) VALUES (1, ?, ?, 'active')", 'ii', [$path812One, MEMBER]);
    q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (960907, ?, 1, 'going', 'confirmed'), (960908, ?, 1, 'going', 'confirmed')", 'ii', [MEMBER, MEMBER]);

    $orgOneZone812 = (string) (one('SELECT timezone FROM tblSites WHERE siteID = 1')['timezone'] ?? 'UTC');
    $nowThere      = static fn (string $zone): string => (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('Y-m-d H:i:s');
    $ev812Row      = one('SELECT siteID FROM tblEvents WHERE eventID = 960908');
    $rsvp812Row    = one('SELECT siteID FROM tblEventRSVPs WHERE eventID = 960908 AND userID = ?', 'i', [MEMBER]);
    $memberOfBoth  = (int) (one('SELECT COUNT(*) AS n FROM tblUserSites WHERE userID = ? AND siteID IN (1, ?) AND isActive = 1', 'ii', [MEMBER, ORG_TWO])['n'] ?? 0);
    ok(
        'X18b premise (#812): event 960908 is organisation two\'s, it has begun on its own clock AND on organisation 1\'s (the clock the old rule judged it on), its RSVP row is stored under organisation 1, and the person is a member of both -- so the old rule 3, which asked nothing about the EVENT\'s organisation, completes the step and the check below is not vacuous',
        (int) ($ev812Row['siteID'] ?? 0) === ORG_TWO && (int) ($rsvp812Row['siteID'] ?? 0) === 1 && $memberOfBoth === 2
            && strcmp($ev812Start, $nowThere($ev812Zone)) < 0 && strcmp($ev812Start, $nowThere($orgOneZone812)) < 0 && strcmp($ev812Start, $nowThere('UTC')) < 0,
        'event organisation ' . json_encode($ev812Row['siteID'] ?? null) . ', RSVP organisation ' . json_encode($rsvp812Row['siteID'] ?? null)
            . ', memberships ' . $memberOfBoth . ', start ' . $ev812Start . ' against now ' . $nowThere($ev812Zone) . ' (' . $ev812Zone . '), '
            . $nowThere($orgOneZone812) . ' (' . $orgOneZone812 . '), ' . $nowThere('UTC') . ' (UTC)'
    );
    $doneIn = static fn (int $pathwayId, int $userId): array => array_column(q(
        'SELECT s.name FROM tblPathwayProgress pr JOIN tblPathwaySteps s ON s.stepID = pr.stepID WHERE s.pathwayID = ? AND pr.userID = ? ORDER BY s.name',
        'ii', [$pathwayId, $userId]
    ), 'name');
    $sweep812 = req($xMemberJar, 'GET', '/discipleship');
    $done812  = $doneIn($path812One, MEMBER);
    ok(
        'X18c the discipleship sweep completes organisation 1\'s step for organisation 1\'s OWN event, and NOT the step naming organisation two\'s event the person RSVP\'d to (#812: rule 3 completed both, and the row is permanent)',
        $sweep812['status'] === 200 && $done812 === ['Selftest 812 own organisation'],
        said($sweep812) . '; completed: ' . json_encode($done812) . ' (the old rule 3 gives ["Selftest 812 other organisation","Selftest 812 own organisation"])'
    );

    // ---- #819: the scheduled sweep decides PER ORGANISATION ----------------
    // `cron/discipleship-sweep` used to ask once, for the organisation the
    // scheduler's request lands on (organisation 1 here: the request names
    // none), and sweep every organisation on that answer. The first two
    // arrangements below are the ones the old page gets WRONG, one in each
    // direction:
    //   (a) on for organisation 1 alone -- the old page swept organisation two
    //       as well, which had it off;
    //   (b) on for organisation two alone -- the old page refused, because
    //       organisation 1 had it off, and organisation two got nothing.
    // The rest, (c)-(i), pin what the NEW page must keep doing -- the
    // ordinary installation-wide state, an organisation's own "no", which
    // values count as on, a missing switch, an inactive organisation, no
    // pathway at all, and the outcome every run records -- several of which
    // the old page also got right.
    // Organisation two has a pathway of its own, with a step for its OWN event
    // and an RSVP stored the way `calendar/rsvp.php` stores one, held by a
    // member of organisation two only.
    q("INSERT INTO tblPathways (siteID, name, isActive) VALUES (?, 'Selftest zones pathway 812 two', 1)", 'i', [ORG_TWO]);
    $path812Two = (int) one("SELECT pathwayID FROM tblPathways WHERE name = 'Selftest zones pathway 812 two'")['pathwayID'];
    q("INSERT INTO tblPathwaySteps (pathwayID, sortOrder, name, autoRule, autoRefID) VALUES (?, 1, 'Selftest 819 organisation two', 'rsvpd_event', 960908)", 'i', [$path812Two]);
    q("INSERT INTO tblPathwayEnrolments (siteID, pathwayID, userID, status) VALUES (?, ?, ?, 'active')", 'iii', [ORG_TWO, $path812Two, OTHER]);
    q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (960908, ?, ?, 'going', 'confirmed')", 'ii', [OTHER, ORG_TWO]);
    // Clearing the progress is not enough on its own: a sweep that completes
    // EVERY step of a pathway also marks the enrolment `completed`, and the
    // sweep only looks at `active` enrolments -- so after a run that wrongly
    // completed both of organisation 1's steps, the next arrangement would see
    // nothing to sweep and fail for the wrong reason (found by planting #812's
    // fault back: X18d went red on a fixture left behind by X18c).
    $clearProgress = static function () use ($path812One, $path812Two): void {
        q('DELETE FROM tblPathwayProgress WHERE stepID IN (SELECT stepID FROM tblPathwaySteps WHERE pathwayID IN (?, ?))', 'ii', [$path812One, $path812Two]);
        q("UPDATE tblPathwayEnrolments SET status = 'active', completedAt = NULL WHERE pathwayID IN (?, ?)", 'ii', [$path812One, $path812Two]);
    };
    $ownSwitch = static function (?int $site): void {
        q("DELETE FROM tblSettings WHERE settingKey = 'discipleship.enabled' AND siteID IS NOT NULL");
        if ($site !== null) {
            q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, 'discipleship.enabled', 'true', 'true', 0)", 'i', [$site]);
        }
    };
    $sweepTokenWas = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'discipleship.cron_token' AND siteID IS NULL")['settingValue'] ?? '';
    $enabledWas    = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'discipleship.enabled' AND siteID IS NULL")['settingValue'] ?? 'true';
    $ownRowsBefore = (int) (one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey = 'discipleship.enabled' AND siteID IS NOT NULL")['n'] ?? -1);
    $orgTwoActiveWas = (int) (one('SELECT isActive FROM tblSites WHERE siteID = ?', 'i', [ORG_TWO])['isActive'] ?? 1);
    $sweepToken    = bin2hex(random_bytes(16));
    setting('discipleship.cron_token', $sweepToken);
    $otherIsTwos = one('SELECT userID FROM tblUserSites WHERE userID = ? AND siteID = ? AND isActive = 1', 'ii', [OTHER, ORG_TWO]) !== null
        && one('SELECT userID FROM tblUserSites WHERE userID = ? AND siteID = 1', 'i', [OTHER]) === null;

    // One run of the real job, from a clean slate: progress and enrolments put
    // back, and the job's own record DELETED first, so the outcome read
    // afterwards can only have been written by THIS run (#819 round 1: the
    // outcome the "Check this portal" page reads was pinned by nothing).
    $sweepOnce = static function () use ($clearProgress, $sweepToken, $doneIn, $path812One, $path812Two): array {
        $clearProgress();
        q("DELETE FROM tblScheduledJobRun WHERE jobKey = 'cron/discipleship-sweep'");
        $answer = req('', 'GET', '/cron/discipleship-sweep?key=' . $sweepToken);
        $record = one("SELECT lastOutcome, finishedAtUtc FROM tblScheduledJobRun WHERE jobKey = 'cron/discipleship-sweep'");

        return [
            'answer'  => $answer,
            'said'    => trim($answer['body']),
            'outcome' => $record === null ? '(no record)' : ($record['finishedAtUtc'] === null ? '(no finish)' : (string) $record['lastOutcome']),
            'one'     => $doneIn($path812One, MEMBER),
            'two'     => $doneIn($path812Two, OTHER),
        ];
    };
    $bothSwept   = static fn (array $run): bool => $run['one'] === ['Selftest 812 own organisation'] && $run['two'] === ['Selftest 819 organisation two'];
    $refusedRun  = static fn (array $run): bool => $run['answer']['status'] === 200
        && $run['said'] === 'Discipleship is switched off for every active organisation that has an active pathway, so nothing was swept'
        && $run['outcome'] === 'refused' && $run['one'] === [] && $run['two'] === [];
    // The two counts the job prints, so a run that swept one organisation
    // cannot report two (#819 round 2: `count($siteIds)` in place of
    // `count($stats)` printed "sitesSwept 2" for a one-of-two run with every
    // check green). [-1, -1] when the answer is not an OK line.
    $counts      = static function (array $run): array {
        $said = str_starts_with($run['said'], 'OK ') === true ? json_decode(substr($run['said'], 3), true) : null;

        return is_array($said) === true ? [$said['sitesSwept'] ?? -1, $said['sitesSwitchedOff'] ?? -1] : [-1, -1];
    };
    $tell        = static fn (array $run): string => said($run['answer']) . ' "' . substr($run['said'], 0, 160) . '", recorded ' . $run['outcome']
        . '; organisation 1 completed ' . json_encode($run['one']) . ', organisation two completed ' . json_encode($run['two']);
    $okRun       = static fn (array $run): bool => $run['answer']['status'] === 200 && str_starts_with($run['said'], 'OK ') === true && $run['outcome'] === 'ok';

    // ---- (a) and (b): on for ONE organisation through its own row ----------
    setting('discipleship.enabled', 'false');
    $ownSwitch(1);
    $runA = $sweepOnce();
    ok(
        'X18d (#819) with Discipleship on for organisation 1 ALONE (its own row) and off installation-wide, the scheduled sweep sweeps organisation 1 and NOT organisation two, and says so (swept 1, switched off 1) (the old page asked organisation 1 once and swept both)',
        $ownRowsBefore === 0 && $otherIsTwos === true && $okRun($runA) === true && $counts($runA) === [1, 1]
            && $runA['one'] === ['Selftest 812 own organisation'] && $runA['two'] === [],
        'own rows before ' . $ownRowsBefore . ', the second person in organisation two only: ' . json_encode($otherIsTwos) . '; ' . $tell($runA)
    );
    $ownSwitch(ORG_TWO);
    $runB = $sweepOnce();
    ok(
        'X18e (#819) with Discipleship on for organisation two ALONE, the scheduled sweep sweeps organisation two and NOT organisation 1, and says so (swept 1, switched off 1) (the old page answered "Discipleship disabled", because the request landed on organisation 1, and swept nobody)',
        $okRun($runB) === true && $counts($runB) === [1, 1] && $runB['one'] === [] && $runB['two'] === ['Selftest 819 organisation two'],
        $tell($runB)
    );

    // ---- (c) off everywhere: the words AND the recorded outcome ------------
    $ownSwitch(null);
    $runC = $sweepOnce();
    ok(
        'X18f (#819) with Discipleship off for every organisation, the scheduled sweep says so, completes nothing, and RECORDS the run as refused (what the "Check this portal" page reads)',
        $refusedRun($runC) === true,
        $tell($runC)
    );

    // ---- (d) THE ORDINARY STATE: installation-wide on, no own rows ---------
    // Admin, Apps and the install script write ONLY the installation-wide row
    // (no page can write an organisation's own `discipleship.enabled`), so
    // this is the state of every real installation. (a)-(c) never drove it,
    // and a switch that ignored the installation-wide row passed all three
    // (#819 round 1) while skipping every organisation on every portal.
    setting('discipleship.enabled', 'true');
    $runD = $sweepOnce();
    ok(
        'X18g (#819) in the ORDINARY state -- Discipleship on installation-wide, no organisation with a row of its own -- the scheduled sweep sweeps BOTH organisations, says so (swept 2, switched off 0) and records ok',
        $okRun($runD) === true && $counts($runD) === [2, 0] && $bothSwept($runD) === true,
        $tell($runD)
    );

    // ---- (e) an organisation's own "no" beats the installation's "yes" -----
    q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (1, 'discipleship.enabled', '0', '0', 0)");
    $runE = $sweepOnce();
    $ownSwitch(null);
    ok(
        'X18h (#819) with Discipleship on installation-wide and an own row of "0" for organisation 1, the scheduled sweep skips organisation 1 and sweeps organisation two, and says so (swept 1, switched off 1)',
        $okRun($runE) === true && $counts($runE) === [1, 1] && $runE['one'] === [] && $runE['two'] === ['Selftest 819 organisation two'],
        $tell($runE)
    );

    // ---- (f) which values count as ON, installation-wide -------------------
    // Exactly '1' and 'true', as Admin, Apps and every page read it
    // (`AppRegistry::isEnabledForSite()`): anything else is off, capitals and
    // spaces included. Each value is one real run of the job.
    $valueTable = [];
    $valuesRight = true;
    foreach (['1' => true, 'true' => true, '0' => false, 'false' => false, '' => false, 'TRUE' => false, 'yes' => false, 'on' => false, ' 1' => false, '1 ' => false] as $value => $isOn) {
        setting('discipleship.enabled', (string) $value);
        $run = $sweepOnce();
        $right = $isOn === true ? ($okRun($run) === true && $bothSwept($run) === true) : $refusedRun($run);
        $valueTable[] = json_encode((string) $value) . ' => ' . ($isOn === true ? 'on' : 'off') . ($right === true ? ' ok' : ' WRONG: ' . $tell($run));
        $valuesRight = $valuesRight && $right;
    }
    ok(
        'X18i (#819) installation-wide, the scheduled sweep counts exactly "1" and "true" as on, and every other value ("0", "false", "", "TRUE", "yes", "on", " 1", "1 ") as off',
        $valuesRight === true,
        implode('; ', $valueTable)
    );

    // ---- (g) NO switch at all counts as OFF --------------------------------
    // The installation-wide row is moved aside under another name (and moved
    // back), so the row itself -- its default and every other column -- is
    // kept exactly, rather than deleted and remade. The name it is moved to
    // must NOT begin `discipleship.enabled.`: setting names are a dotted tree,
    // so that would turn `discipleship.enabled` into a BRANCH holding a child
    // rather than a missing value (planting the old page found it: its
    // `isEnabled()` warned "Array to string conversion").
    q("UPDATE tblSettings SET settingKey = 'held812.discipleship.enabled' WHERE settingKey = 'discipleship.enabled' AND siteID IS NULL");
    $noRows = (int) (one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey = 'discipleship.enabled'")['n'] ?? -1);
    $runG   = $sweepOnce();
    q("UPDATE tblSettings SET settingKey = 'discipleship.enabled' WHERE settingKey = 'held812.discipleship.enabled' AND siteID IS NULL");
    ok(
        'X18j (#819) with NO discipleship.enabled row anywhere, the scheduled sweep counts the switch as OFF: nothing swept, recorded refused',
        $noRows === 0 && $refusedRun($runG) === true,
        'rows left with that name: ' . $noRows . '; ' . $tell($runG)
    );

    // ---- (h) an organisation that is switched off itself is not swept ------
    setting('discipleship.enabled', 'true');
    q('UPDATE tblSites SET isActive = 0 WHERE siteID = ?', 'i', [ORG_TWO]);
    $runH = $sweepOnce();
    q('UPDATE tblSites SET isActive = ? WHERE siteID = ?', 'ii', [$orgTwoActiveWas, ORG_TWO]);
    ok(
        'X18k (#819) with organisation two itself switched off (tblSites.isActive = 0) and Discipleship on, the scheduled sweep sweeps organisation 1 and NOT organisation two',
        $orgTwoActiveWas === 1 && $okRun($runH) === true && $runH['one'] === ['Selftest 812 own organisation'] && $runH['two'] === [],
        'organisation two was active: ' . $orgTwoActiveWas . '; ' . $tell($runH)
    );

    // ---- (i) no active pathway anywhere: nothing to do, which is NOT "off" --
    q('UPDATE tblPathways SET isActive = 0 WHERE pathwayID IN (?, ?)', 'ii', [$path812One, $path812Two]);
    $activeElsewhere = (int) (one('SELECT COUNT(*) AS n FROM tblPathways WHERE isActive = 1')['n'] ?? -1);
    $runI = $sweepOnce();
    q('UPDATE tblPathways SET isActive = 1 WHERE pathwayID IN (?, ?)', 'ii', [$path812One, $path812Two]);
    $sweptI = json_decode(substr($runI['said'], 3), true);
    ok(
        'X18l (#819) with no active pathway anywhere, the scheduled sweep finishes normally -- OK, swept 0, recorded ok -- rather than calling itself switched off',
        $activeElsewhere === 0 && $okRun($runI) === true && is_array($sweptI) === true && ($sweptI['sitesSwept'] ?? null) === 0,
        'other active pathways: ' . $activeElsewhere . '; ' . $tell($runI)
    );
    q("DELETE FROM tblScheduledJobRun WHERE jobKey = 'cron/discipleship-sweep'");

    // Put everything back. Nothing below reads these rows; event 960908 goes
    // so organisation two's calendar is as it was.
    setting('discipleship.enabled', (string) $enabledWas);
    setting('discipleship.cron_token', (string) $sweepTokenWas);
    $clearProgress();
    q('DELETE FROM tblPathwayEnrolments WHERE pathwayID IN (?, ?)', 'ii', [$path812One, $path812Two]);
    q('DELETE FROM tblPathwaySteps WHERE pathwayID IN (?, ?)', 'ii', [$path812One, $path812Two]);
    q('DELETE FROM tblPathways WHERE pathwayID IN (?, ?)', 'ii', [$path812One, $path812Two]);
    q('DELETE FROM tblEventRSVPs WHERE eventID IN (960907, 960908)');
    q('DELETE FROM tblUserSites WHERE userID = ? AND siteID = ?', 'ii', [MEMBER, ORG_TWO]);
    q('DELETE FROM tblEvents WHERE eventID = 960908');

    // The dashboard's "Events This Week" -- loaded as a page, because its count
    // is the statement that answered HTTP 500 for every organisation with no
    // events yet while this was being built (a `CASE` with no `WHEN`). Other
    // events in this run share the count, so it is MEASURED three times: with
    // both events, without the Auckland one, without either. Only the New York
    // event (still to come this week on its own clock) may move the number.
    $weekCount = static function () use ($adminJar): ?int {
        $page = req($adminJar, 'GET', '/dashboard');
        if ($page['status'] !== 200
            || tz_find('#fw-bold">(\d+)</div>\s*<small class="text-muted">Events This Week</small>#', $page['body'], $m) !== 1
        ) {
            return null;
        }

        return (int) $m[1];
    };
    $withBoth = $weekCount();
    q('DELETE FROM tblEvents WHERE eventID = 960907');
    $withNy = $weekCount();
    q('DELETE FROM tblEvents WHERE eventID = 960906');
    $withNone = $weekCount();
    ok(
        'X19 the dashboard\'s "Events This Week" counts the New York event still to come on its own clock and NOT the Auckland one that has begun (#804: the old NOW() did the reverse)',
        $withBoth !== null && $withNy !== null && $withNone !== null && $withBoth === $withNy && $withNy - $withNone === 1,
        'count with both ' . json_encode($withBoth) . ', without Auckland ' . json_encode($withNy) . ', without either ' . json_encode($withNone)
    );
    q('DELETE FROM tblEvents WHERE eventID IN (960906, 960907)');

    // ---- #783: "still to come" on the organisation's clock ------------------
    $fresh = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $creds['db_name'], $creds['db_port']);
    $dbNow = (string) $fresh->query('SELECT NOW() AS n')->fetch_assoc()['n'];
    $fresh->close();
    $clockNow = static fn (string $zone): string => (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('Y-m-d H:i:s');
    $apart    = static fn (string $a, string $b): int => abs((int) strtotime($a . ' UTC') - (int) strtotime($b . ' UTC'));
    ok(
        'X06 premise: the database\'s NOW() is at least three hours from BOTH Auckland\'s and New York\'s clocks, so the old NOW() comparison can be told apart',
        $apart($dbNow, $clockNow('Pacific/Auckland')) >= 3 * 3600 && $apart($dbNow, $clockNow('America/New_York')) >= 3 * 3600,
        'database NOW() ' . $dbNow . ', Auckland ' . $clockNow('Pacific/Auckland') . ', New York ' . $clockNow('America/New_York')
    );
    q("INSERT INTO tblResource (siteID, name, category, isActive) VALUES (1, 'Selftest zones room 783', 'room', 1)");
    $room = (int) one("SELECT resourceID FROM tblResource WHERE name = 'Selftest zones room 783'")['resourceID'];
    $book = static function (string $zone, int $fromHours, int $toHours, string $purpose) use ($room): void {
        $now = new DateTimeImmutable('now', new DateTimeZone($zone));
        q(
            "INSERT INTO tblResourceBooking (resourceID, bookedByID, startAt, endAt, purpose, status) VALUES (?, ?, ?, ?, ?, 'approved')",
            'iisss',
            [$room, MEMBER, $now->modify(($fromHours >= 0 ? '+' : '') . $fromHours . ' hours')->format('Y-m-d H:i:s'),
                $now->modify(($toHours >= 0 ? '+' : '') . $toHours . ' hours')->format('Y-m-d H:i:s'), $purpose]
        );
    };
    foreach (['Pacific/Auckland' => 'AKL', 'America/New_York' => 'NYC'] as $zone => $tag) {
        orgZone(1, $zone);
        q('DELETE FROM tblResourceBooking WHERE resourceID = ?', 'i', [$room]);
        $book($zone, -3, -2, 'Selftest 783 ' . $tag . ' finished');
        $book($zone, 2, 3, 'Selftest 783 ' . $tag . ' to come');
        $mine  = words(req($xMemberJar, 'GET', '/resources/my-bookings')['body']);
        $there = words(req($xMemberJar, 'GET', '/resources/resource?id=' . $room)['body']);
        $old   = $tag === 'AKL' ? 'the old NOW() kept it, hours after it ended' : 'the old NOW() would have dropped the other one';
        ok(
            'X07 ' . $tag . ': My Bookings lists the booking still to come on ' . $zone . '\'s clock' . ($tag === 'NYC' ? ' (the old NOW() hid it)' : ''),
            str_contains($mine, 'Selftest 783 ' . $tag . ' to come') === true,
            substr($mine, max(0, (int) strpos($mine, 'My Bookings')), 400)
        );
        ok(
            'X08 ' . $tag . ': My Bookings leaves out the booking that finished two hours ago on ' . $zone . '\'s clock (' . $old . ')',
            str_contains($mine, 'Selftest 783 ' . $tag . ' finished') === false && str_contains($mine, 'My Bookings') === true,
            substr($mine, max(0, (int) strpos($mine, 'My Bookings')), 400)
        );
        ok(
            'X09 ' . $tag . ': the resource page lists the booking still to come and leaves out the one that has begun, on ' . $zone . '\'s clock',
            str_contains($there, 'Selftest 783 ' . $tag . ' to come') === true && str_contains($there, 'Selftest 783 ' . $tag . ' finished') === false,
            substr($there, max(0, (int) strpos($there, 'Selftest zones room 783')), 400)
        );
    }
    q('DELETE FROM tblResourceBooking WHERE resourceID = ?', 'i', [$room]);
    q('DELETE FROM tblResource WHERE resourceID = ?', 'i', [$room]);
});
orgZone(1, $xOrgZoneWas === null ? null : (string) $xOrgZoneWas);

// =============================================================================
// N. NO ZONE IS BUILT IN (#782)
// =============================================================================
// "Never hard-code a zone." Until #782 the livestream schedule, venues, the
// venue check API and the Sabbath quiet window all answered "Europe/London"
// when nobody had chosen, so an organisation anywhere else had its times told
// on London's clock. Each check below is run in an organisation on
// Australia/Sydney, where London's answer and the organisation's differ by
// nine to eleven hours, and each says what the OLD code gave. The premise is
// asserted first (N00), because in an organisation on London's own zone every
// check here would pass on the old code.
head('N. #782: no zone is built in — livestream, venues and the Sabbath window follow the organisation');
$nOrgZoneWas  = one('SELECT timezone FROM tblSites WHERE siteID = 1')['timezone'] ?? null;
$nSabbathWas  = q("SELECT settingKey, settingValue FROM tblSettings WHERE settingKey LIKE 'portal.sabbath.%' AND siteID IS NULL");
$nLiveWas     = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'livestream.enabled' AND siteID IS NULL")['settingValue'] ?? null;
const N_ZONE    = 'Australia/Sydney';
const N_CHANNEL = 960781;
$withoutConsumingIds(static function () use ($adminJar): void {
    orgZone(1, N_ZONE);
    setting('livestream.enabled', '1');
    $facts = child(['task' => 'facts', 'sites' => [1]]);
    ok(
        'N00 premise: the organisation\'s zone is ' . N_ZONE . ' by Site::timezone(), so London\'s answer and the organisation\'s differ',
        $facts['threw'] === '' && ($facts['zone']['1'] ?? '') === N_ZONE,
        json_encode($facts)
    );

    // ---- the livestream schedule -------------------------------------------
    q("INSERT INTO tblLivestreamChannel (channelID, siteID, name, platform) VALUES (?, 1, 'Selftest zones channel 782', 'youtube')", 'i', [N_CHANNEL]);
    $slots = static fn (): array => q('SELECT timezone FROM tblLivestreamSchedule WHERE channelID = ? ORDER BY scheduleID', 'i', [N_CHANNEL]);
    $page  = req($adminJar, 'GET', '/admin/livestream/channels');
    // The box's OWN value is read: the page also carries a list of every zone
    // name for the box to suggest from, London among them, so "the page does
    // not contain Europe/London" would be untrue of a correct page.
    $slotBox = tz_find('#name="timezone"\s+list="ls-zone-names"[^>]*\svalue="([^"]*)"#', $page['body'], $box) === 1 ? $box[1] : '(no slot box found)';
    ok(
        'N01 the livestream page\'s new-slot box starts on the organisation\'s zone, ' . N_ZONE . ' (it started on "Europe/London" for everybody)',
        $page['status'] === 200 && $slotBox === N_ZONE,
        said($page) . '; the box holds ' . json_encode($slotBox)
    );
    $slotFields = ['action' => 'add_schedule', 'channelID' => N_CHANNEL, 'dayOfWeek' => 6, 'startTime' => '10:00', 'endTime' => '12:00'];
    post($adminJar, '/admin/livestream/channels', '/admin/livestream/channels', $slotFields + ['timezone' => '']);
    ok(
        'N02 a slot added with the zone box left EMPTY is stored on the organisation\'s zone, ' . N_ZONE . ' (it was stored as "Europe/London")',
        array_column($slots(), 'timezone') === [N_ZONE],
        json_encode($slots())
    );
    post($adminJar, '/admin/livestream/channels', '/admin/livestream/channels', $slotFields + ['timezone' => 'Austrlia/Sydney']);
    $said = flashAfter($adminJar, '/admin/livestream/channels');
    ok(
        'N03 a slot whose zone is not one PHP knows ("Austrlia/Sydney") is REFUSED, said, and nothing is stored (any text used to be stored)',
        array_column($slots(), 'timezone') === [N_ZONE] && str_contains($said, 'not one this portal knows') === true,
        'rows ' . json_encode($slots()) . '; said: ' . $said
    );
    // A slot an older version stored with text PHP cannot open (it accepted any text).
    q('DELETE FROM tblLivestreamSchedule WHERE channelID = ?', 'i', [N_CHANNEL]);
    q("INSERT INTO tblLivestreamSchedule (channelID, dayOfWeek, startTime, endTime, timezone, isActive) VALUES (?, 6, '10:00:00', '12:00:00', 'Atlantis/Nowhere', 1)", 'i', [N_CHANNEL]);
    $next = child(['task' => 'livestreamNext', 'after' => '2028-06-14 00:00:00']);
    ok(
        'N04 a slot whose stored zone cannot be read is counted down on the organisation\'s clock: Saturday 10:00 in ' . N_ZONE . ' (the old reader used UTC, ten hours out)',
        $next['threw'] === '' && ($next['nextAt'] ?? '') === '2028-06-17T10:00:00+10:00',
        json_encode($next)
    );
    q('DELETE FROM tblLivestreamChannel WHERE channelID = ?', 'i', [N_CHANNEL]);

    // ---- venues -------------------------------------------------------------
    $manage = req($adminJar, 'GET', '/venues/manage');
    ok(
        'N05 the new-venue form\'s zone box starts on the organisation\'s zone, ' . N_ZONE . ' (it started on "Europe/London")',
        $manage['status'] === 200
            && tz_find('#id="timezone" name="timezone"[^>]*value="' . preg_quote(N_ZONE, '#') . '"#s', $manage['body']) === 1,
        said($manage) . '; ' . (tz_find('#id="timezone" name="timezone"[^>]*>#s', $manage['body'], $box) === 1 ? $box[0] : 'no zone box found')
    );
    $venueZone = static fn (string $name): ?string => one('SELECT timezone FROM tblVenues WHERE venueName = ? AND siteID = 1', 's', [$name])['timezone'] ?? null;
    $venueSave = ['action' => 'save', 'venueID' => 0, 'countryCode' => 'AU'];
    post($adminJar, '/venues/manage', '/venues/venue-save', $venueSave + ['venueName' => 'Selftest zones hall 782 empty', 'timezone' => '']);
    ok(
        'N06 a venue saved with the zone box left EMPTY is stored on the organisation\'s zone, ' . N_ZONE . ' (it was stored as "Europe/London")',
        $venueZone('Selftest zones hall 782 empty') === N_ZONE,
        json_encode($venueZone('Selftest zones hall 782 empty'))
    );
    post($adminJar, '/venues/manage', '/venues/venue-save', $venueSave + ['venueName' => 'Selftest zones hall 782 typo', 'timezone' => 'Austrlia/Sydney']);
    $said = flashAfter($adminJar, '/venues/manage');
    ok(
        'N07 a venue whose zone is not one PHP knows is REFUSED and said, and NO venue is made (it used to be saved as "Europe/London" without a word)',
        $venueZone('Selftest zones hall 782 typo') === null && str_contains($said, 'not one this portal knows') === true,
        'stored ' . json_encode($venueZone('Selftest zones hall 782 typo')) . '; said: ' . $said
    );
    // Over-refusal guard: a venue saved long ago with an old zone name must still
    // save when its zone is left as it is. This passes on the old code too, on
    // purpose: it is here so the new refusal cannot grow into refusing this.
    q("INSERT INTO tblVenues (venueID, siteID, venueName, countryCode, timezone) VALUES (960782, 1, 'Selftest zones hall 782 legacy', 'US', 'US/Eastern')");
    post($adminJar, '/venues/manage?edit=960782', '/venues/venue-save', ['venueID' => 960782] + $venueSave + ['venueName' => 'Selftest zones hall 782 legacy renamed', 'timezone' => 'US/Eastern']);
    ok(
        'N08 a venue still holding an OLD zone name ("US/Eastern") saves when that zone is left unchanged (asks whether the field CHANGED, #667)',
        $venueZone('Selftest zones hall 782 legacy renamed') === 'US/Eastern',
        json_encode(one('SELECT venueName, timezone FROM tblVenues WHERE venueID = 960782'))
    );
    // The venue code's reading: a venue whose stored zone cannot be read, and an
    // event at 08:00 in Sydney. On Sydney's clock that is 15 June; on London's
    // (the old fallback) it is 23:00 on 14 June.
    q("INSERT INTO tblVenues (venueID, siteID, venueName, countryCode, timezone) VALUES (960783, 1, 'Selftest zones hall 782 unreadable', 'GB', 'Atlantis/Nowhere')");
    q("INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) VALUES (960781, 1, 'selftest-zones-782', 'Selftest zones 782', '2028-06-15 08:00:00', '2028-06-15 09:00:00', ?, 'published', 1, 0)", 's', [N_ZONE]);
    $hall = child(['task' => 'venue', 'eventId' => 960781, 'venueId' => 960783]);
    ok(
        'N09 a venue whose stored zone cannot be read is read on the organisation\'s clock: an 08:00 Sydney event falls on 2028-06-15 there (the old London fallback said 2028-06-14)',
        $hall['threw'] === '' && ($hall['days'][0] ?? '') === '2028-06-15',
        json_encode($hall)
    );
    // The check API with no `tz`: a Sydney venue, a 20:00 reading. On the
    // organisation's clock it is 15 June; read as London (the old default) it is
    // 05:00 on 16 June in Sydney.
    q("INSERT INTO tblVenues (venueID, siteID, venueName, countryCode, timezone) VALUES (960784, 1, 'Selftest zones hall 782 sydney', 'AU', ?)", 's', [N_ZONE]);
    $check = req($adminJar, 'GET', '/api/venues/check?start=2028-06-15T20:00&venueID=960784');
    $answer = json_decode($check['body'], true);
    $day    = (string) ($answer['data']['perDay'][0]['date'] ?? $answer['perDay'][0]['date'] ?? '');
    ok(
        'N10 /api/venues/check with NO zone reads the times on the organisation\'s clock: 20:00 on 2028-06-15 stays 2028-06-15 (the old London default made it 2028-06-16)',
        $check['status'] === 200 && $day === '2028-06-15',
        said($check) . '; ' . substr($check['body'], 0, 300)
    );
    // #937: A REQUEST CHOOSES THE TYPE AS WELL AS THE VALUE (the 27 October
    // lesson). `start[]=x` arrives as a LIST, and the endpoint used to cast it
    // with `(string)`, which raises PHP's "Array to string conversion" warning:
    // the portal's error handler writes that into `tblErrors` and lets PHP's own
    // handler run too, so the request still ended in a 400 but left a fault in
    // the error log every time anybody sent one. `tz` already treated a list as
    // absent; `start` and `end` now REFUSE one, before any cast. Both halves are
    // asked because they are two separate reads, and either could go back alone.
    // "No new error row" is counted against the highest row number from before
    // the request, so it fails on ANY row the request wrote, whatever it says.
    foreach (['N10b' => 'start[]=2028-06-15T20:00&end=2028-06-15T21:00', 'N10c' => 'start=2028-06-15T20:00&end[]=2028-06-15T21:00'] as $label => $query) {
        $errorsBefore = (int) (one('SELECT COALESCE(MAX(errorID), 0) AS n FROM tblErrors')['n'] ?? 0);
        $listed       = req($adminJar, 'GET', '/api/venues/check?' . $query . '&venueID=960784');
        $errorsAfter  = q('SELECT errorID, errorTitle FROM tblErrors WHERE errorID > ?', 'i', [$errorsBefore]);
        ok(
            $label . ' /api/venues/check with ' . ($label === 'N10b' ? '`start`' : '`end`') . ' sent as a LIST is refused cleanly: HTTP 400 "invalid-range" in valid JSON, and NOTHING written to the error log (it used to raise "Array to string conversion")',
            $listed['status'] === 400
                && is_array(json_decode($listed['body'], true)) === true
                && str_contains($listed['body'], 'invalid-range') === true
                && $errorsAfter === [],
            said($listed) . '; new error rows: ' . json_encode($errorsAfter) . '; ' . substr($listed['body'], 0, 300)
        );
    }
    q('DELETE FROM tblEvents WHERE eventID = 960781');
    q("DELETE FROM tblVenueUsageTypes WHERE venueID IN (SELECT venueID FROM tblVenues WHERE venueName LIKE 'Selftest zones hall 782%')");
    q("DELETE FROM tblVenues WHERE venueName LIKE 'Selftest zones hall 782%'");

    // ---- the Sabbath quiet window -------------------------------------------
    $sabbathZone = static fn (): ?string => one("SELECT settingValue FROM tblSettings WHERE settingKey = 'portal.sabbath.timezone' AND siteID IS NULL")['settingValue'] ?? null;
    ok(
        'N11 a new installation ships portal.sabbath.timezone EMPTY, meaning each organisation\'s own zone (it shipped as "Europe/London")',
        $sabbathZone() === '',
        json_encode($sabbathZone())
    );
    setting('portal.sabbath.method', 'fixed');
    // Saturday 17 June 2028, 12:00 in Sydney: the window began the evening before.
    $at   = (new DateTimeImmutable('2028-06-17 12:00:00', new DateTimeZone(N_ZONE)))->getTimestamp();
    $win  = child(['task' => 'sabbath', 'at' => $at, 'showIn' => N_ZONE]);
    ok(
        'N12 with the setting empty, the quiet window begins Friday 18:00 on the organisation\'s clock (the old code read empty as UTC: 04:00 Saturday in Sydney)',
        $win['threw'] === '' && ($win['start'] ?? '') === 'Fri 2028-06-16 18:00',
        json_encode($win)
    );
    // A zone CHOSEN on the page wins over the organisation's: an installation
    // upgraded from before #782 keeps the London it holds, and that is only
    // true if a stored zone is really read. N12 drives only an EMPTY setting,
    // so without this a Sabbath::zone() that ignored the setting altogether
    // passed every check here (the independent check planted exactly that).
    setting('portal.sabbath.timezone', 'Europe/London');
    $chosen = child(['task' => 'sabbath', 'at' => $at, 'showIn' => 'Europe/London']);
    setting('portal.sabbath.timezone', '');
    ok(
        'N16 a zone CHOSEN for quiet hours wins over the organisation\'s: set to Europe/London while the organisation is on ' . N_ZONE . ', the window begins Friday 18:00 on LONDON\'s clock',
        $chosen['threw'] === '' && ($chosen['start'] ?? '') === 'Fri 2028-06-16 18:00',
        json_encode($chosen)
    );
    $sabbathPage = req($adminJar, 'GET', '/admin/settings/sabbath');
    $selected    = tz_findAll('#<option value="([^"]*)"\s+selected\s*>#', $sabbathPage['body'], $sel) > 0 ? $sel[1] : [];
    ok(
        'N13 the Sabbath page offers "Each organisation\'s own time zone (here: ' . N_ZONE . ')" and it is the ONE option selected',
        $sabbathPage['status'] === 200 && $selected === ['']
            && str_contains(words($sabbathPage['body']), 'Each organisation\'s own time zone (here: ' . N_ZONE . ')') === true,
        said($sabbathPage) . '; selected ' . json_encode($selected)
    );
    $sabbathPost = ['method' => 'fixed', 'location_lat' => '-33.87', 'location_lng' => '151.21', 'start_offset_minutes' => '0', 'end_offset_minutes' => '0'];
    post($adminJar, '/admin/settings/sabbath', '/admin/settings/sabbath', $sabbathPost + ['timezone' => 'America/New_York']);
    $chose = $sabbathZone();
    post($adminJar, '/admin/settings/sabbath', '/admin/settings/sabbath', $sabbathPost + ['timezone' => '']);
    ok(
        'N14 the Sabbath page stores a chosen zone, and stores EMPTY when "each organisation\'s own" is chosen again',
        $chose === 'America/New_York' && $sabbathZone() === '',
        'after choosing New York ' . json_encode($chose) . ', after choosing own ' . json_encode($sabbathZone())
    );
    $refused = post($adminJar, '/admin/settings/sabbath', '/admin/settings/sabbath', $sabbathPost + ['timezone' => 'Austrlia/Sydney', 'location_lat' => '1.5']);
    $latNow  = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'portal.sabbath.location_lat' AND siteID IS NULL")['settingValue'] ?? null;
    ok(
        'N15 a Sabbath zone PHP does not know is REFUSED with a reason and NOTHING on the page is saved (it was silently replaced with "Europe/London")',
        $sabbathZone() === '' && $latNow === '-33.87' && str_contains(words($refused['body']), 'not one this portal knows') === true,
        'zone ' . json_encode($sabbathZone()) . ', latitude ' . json_encode($latNow) . '; ' . said($refused)
    );
    // #677's shape: empty is an instruction on this page ("each organisation's
    // own"), so a save that does not SEND the field must leave the zone alone.
    setting('portal.sabbath.timezone', 'Europe/London');
    $noField = post($adminJar, '/admin/settings/sabbath', '/admin/settings/sabbath', $sabbathPost);
    $kept    = $sabbathZone();
    setting('portal.sabbath.timezone', '');
    ok(
        'N17 a Sabbath save that does not SEND the zone field leaves a chosen zone (Europe/London) exactly as it is, and the rest of the save still happens',
        $kept === 'Europe/London' && str_contains(words($noField['body']), 'Sabbath settings saved.') === true,
        'zone after ' . json_encode($kept) . '; ' . said($noField)
    );
});
orgZone(1, $nOrgZoneWas === null ? null : (string) $nOrgZoneWas);
foreach ($nSabbathWas as $row) {
    setting((string) $row['settingKey'], (string) $row['settingValue']);
}
setting('livestream.enabled', $nLiveWas === null ? '0' : (string) $nLiveWas);

// =============================================================================
// P. NO PLACE IS BUILT IN, AND THE SABBATH PAGE SAVES ONLY WHAT IT WAS SENT (#935, #936)
// =============================================================================
// #935: the Sabbath place shipped as Cambridge's coordinates and fell back to
// them, and a venue's country defaulted to 'GB', for every customer anywhere.
// #936: the Sabbath page filled its boxes from the MERGED settings while saving
// the installation's row (#674's shape), and a save that did not send a field
// stored 0 or "fixed" (#677's shape). Each check says what the OLD code gave.
// Run in an organisation on Australia/Sydney, where Cambridge's sunset and the
// organisation's own clock are hours apart (the same premise as part N).
head('P. #935, #936: no place is built in, and the Sabbath page saves only what it was sent');
const P_KEYS = "('portal.sabbath.enabled','portal.sabbath.method','portal.sabbath.timezone','portal.sabbath.location_lat','portal.sabbath.location_lng',"
    . "'portal.sabbath.start_offset_minutes','portal.sabbath.end_offset_minutes','portal.sabbath.bypass_critical','org.latitude','org.longitude','org.address.countryCode',"
    . "'multisite.enabled','org.address.line1','org.address.line2','org.address.city','org.address.region','org.address.postcode','org.what3words')";
$pWas      = q('SELECT siteID, settingKey, settingValue FROM tblSettings WHERE settingKey IN ' . P_KEYS . ' AND (siteID IS NULL OR siteID IN (1, ' . ORG_TWO . '))');
$pOrgZone  = one('SELECT timezone FROM tblSites WHERE siteID = 1')['timezone'] ?? null;
/** One organisation's own row (organisation 1 unless told): written, or removed when $value is null. */
$siteRow = static function (string $key, ?string $value, int $site = 1): void {
    q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID = ?', 'si', [$key, $site]);
    if ($value !== null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss', [$site, $key, $value, $value]);
    }
};
/** Every installation-wide Sabbath row, key => value. */
$installationSabbath = static function (): array {
    $out = [];
    foreach (q("SELECT settingKey, settingValue FROM tblSettings WHERE settingKey LIKE 'portal.sabbath.%' AND siteID IS NULL ORDER BY settingKey") as $row) {
        $out[(string) $row['settingKey']] = (string) $row['settingValue'];
    }

    return $out;
};
/** What a browser would send for the Sabbath form exactly as drawn: "press Save untouched". */
$drawnForm = static function (string $html): array {
    $fields = [];
    foreach (['location_lat', 'location_lng', 'start_offset_minutes', 'end_offset_minutes', 'switches_shown'] as $name) {
        if (tz_find('#<input[^>]*\bname="' . $name . '"[^>]*\bvalue="([^"]*)"#s', $html, $m) === 1) {
            $fields[$name] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
    }
    if (tz_find('#name="method" value="([^"]*)"\s+checked#', $html, $m) === 1) {
        $fields['method'] = $m[1];
    }
    foreach (['enabled', 'bypass_critical'] as $box) {
        if (tz_find('#name="' . $box . '"\s+checked#', $html) === 1) {
            $fields[$box] = 'on';
        }
    }
    if (tz_find('#<option value="([^"]*)"\s+selected\s*>#', $html, $m) === 1) {
        $fields['timezone'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    return $fields;
};
orgZone(1, N_ZONE);
$siteRow('org.latitude', '');
$siteRow('org.longitude', '');

// ---- the Sabbath place --------------------------------------------------------
$placeNow = static fn (string $key): ?string => one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key])['settingValue'] ?? null;
ok(
    'P00 a new installation ships the Sabbath place EMPTY, meaning each organisation\'s own map position (it shipped as Cambridge, 52.205 / 0.119)',
    $placeNow('portal.sabbath.location_lat') === '' && $placeNow('portal.sabbath.location_lng') === '',
    json_encode([$placeNow('portal.sabbath.location_lat'), $placeNow('portal.sabbath.location_lng')])
);
setting('portal.sabbath.method', 'sunset_calc');
setting('portal.sabbath.location_lat', '');
setting('portal.sabbath.location_lng', '');
setting('portal.sabbath.timezone', '');
setting('portal.sabbath.start_offset_minutes', '0');
setting('portal.sabbath.end_offset_minutes', '0');
$pAt   = (new DateTimeImmutable('2028-06-17 12:00:00', new DateTimeZone(N_ZONE)))->getTimestamp();
$noWhere = child(['task' => 'sabbath', 'at' => $pAt, 'showIn' => N_ZONE]);
ok(
    'P01 sunset with NO place known uses the fixed times: the window begins Friday 18:00 on the organisation\'s clock (the old code used Cambridge\'s sunset: about 06:20 on Saturday in Sydney)',
    $noWhere['threw'] === '' && ($noWhere['start'] ?? '') === 'Fri 2028-06-16 18:00'
        && array_key_exists('place', $noWhere) === true && $noWhere['place'] === null,
    json_encode($noWhere)
);
$pPage = req($adminJar, 'GET', '/admin/settings/sabbath');
ok(
    'P02 the Sabbath page SAYS that sunset has no place and that the fixed times are used instead',
    $pPage['status'] === 200 && str_contains($pPage['body'], 'data-sabbath-no-place') === true
        && str_contains(words($pPage['body']), 'no usable place is known for this organisation: no latitude and longitude are given here') === true
        && str_contains(words($pPage['body']), 'quiet hours use the fixed times instead') === true,
    said($pPage)
);
// The organisation's own map position (Admin, Settings, Organisation).
$siteRow('org.latitude', '-33.87');
$siteRow('org.longitude', '151.21');
// Sunset on Friday 16 June 2028 as the window's own clock reads that date.
// date_sun_info() picks the DAY by PHP's default zone (measured, round 2 of the
// independent check), so the expected value is worked out with the default zone
// set to the zone the date is read on, exactly as Sabbath::sunsetAt() now does.
$sunsetFriday = static function (string $zone, float $lat, float $lng): int {
    $was = date_default_timezone_get();
    date_default_timezone_set($zone);
    $at = (int) date_sun_info((new DateTimeImmutable('2028-06-16 12:00:00', new DateTimeZone($zone)))->getTimestamp(), $lat, $lng)['sunset'];
    date_default_timezone_set($was);

    return $at;
};
$sunsetOn = static function (float $lat, float $lng) use ($sunsetFriday): string {
    return (new DateTimeImmutable('@' . $sunsetFriday(N_ZONE, $lat, $lng)))->setTimezone(new DateTimeZone(N_ZONE))->format('D Y-m-d H:i');
};
$atOrg = child(['task' => 'sabbath', 'at' => $pAt, 'showIn' => N_ZONE]);
ok(
    'P03 with the Sabbath place empty, sunset is worked out at the ORGANISATION\'s own map position: Sydney\'s sunset, ' . $sunsetOn(-33.87, 151.21),
    $atOrg['threw'] === '' && ($atOrg['start'] ?? '') === $sunsetOn(-33.87, 151.21) && ($atOrg['place']['from'] ?? '') === 'organisation',
    json_encode($atOrg)
);
$pPage = req($adminJar, 'GET', '/admin/settings/sabbath');
ok(
    'P04 the Sabbath page names where sunset is worked out: this organisation\'s own map position',
    str_contains($pPage['body'], 'data-sabbath-place-from="organisation"') === true && str_contains($pPage['body'], 'data-sabbath-no-place') === false,
    said($pPage)
);
setting('portal.sabbath.location_lat', '51.5');
setting('portal.sabbath.location_lng', '-0.12');
$typed = child(['task' => 'sabbath', 'at' => $pAt, 'showIn' => N_ZONE]);
ok(
    'P05 a place TYPED on the Sabbath page wins over the organisation\'s map position (sunset at 51.5, -0.12: ' . $sunsetOn(51.5, -0.12) . ')',
    $typed['threw'] === '' && ($typed['start'] ?? '') === $sunsetOn(51.5, -0.12) && ($typed['place']['from'] ?? '') === 'setting',
    json_encode($typed)
);

// ---- #936: the boxes show the installation's row ---------------------------------
setting('portal.sabbath.method', 'fixed');
setting('portal.sabbath.location_lat', '');
setting('portal.sabbath.location_lng', '');
setting('portal.sabbath.enabled', '0');
setting('portal.sabbath.bypass_critical', '1');
// Organisation 1 holds rows of its OWN, which differ from every installation value.
foreach (['portal.sabbath.method' => 'sunset_calc', 'portal.sabbath.location_lat' => '10.5', 'portal.sabbath.location_lng' => '20.5',
    'portal.sabbath.start_offset_minutes' => '15', 'portal.sabbath.enabled' => '1'] as $key => $value) {
    $siteRow($key, $value);
}
$before = $installationSabbath();
$pPage  = req($adminJar, 'GET', '/admin/settings/sabbath');
$sent   = $drawnForm($pPage['body']);
$saved  = post($adminJar, '/admin/settings/sabbath', '/admin/settings/sabbath', $sent);
$after  = $installationSabbath();
ok(
    'P06 inside an organisation with Sabbath rows of its own, pressing Save UNTOUCHED leaves every installation-wide row exactly as it was (#674; the old page made that organisation\'s 10.5 / 20.5 / sunset everyone\'s)',
    $after === $before && str_contains(words($saved['body']), 'Sabbath settings saved.') === true && ($sent['location_lat'] ?? 'missing') === '',
    'sent ' . json_encode($sent) . '; before ' . json_encode($before) . '; after ' . json_encode($after) . '; ' . said($saved)
);
ok(
    'P07 the page SAYS this organisation has Sabbath settings of its own that the boxes do not show',
    str_contains($pPage['body'], 'data-sabbath-own-rows') === true,
    said($pPage)
);
foreach (['portal.sabbath.method', 'portal.sabbath.location_lat', 'portal.sabbath.location_lng', 'portal.sabbath.start_offset_minutes', 'portal.sabbath.enabled'] as $key) {
    $siteRow($key, null);
}

// ---- #936: a field that was not SENT is left alone (#677) --------------------------
$pStored = ['portal.sabbath.method' => 'sunset_calc', 'portal.sabbath.location_lat' => '12.5', 'portal.sabbath.location_lng' => '45.5',
    'portal.sabbath.start_offset_minutes' => '-10', 'portal.sabbath.end_offset_minutes' => '20', 'portal.sabbath.enabled' => '1', 'portal.sabbath.bypass_critical' => '0'];
$pNew = ['method' => 'fixed', 'location_lat' => '13.5', 'location_lng' => '46.5', 'start_offset_minutes' => '-11', 'end_offset_minutes' => '21',
    'switches_shown' => '1', 'bypass_critical' => 'on', 'timezone' => ''];
// Each field, left out on its own while every other field carries a NEW value: the
// one left out must keep its stored value, and the others must really change (so
// "nothing was saved" cannot pass for "the missing field was left alone").
foreach (['method' => 'portal.sabbath.method', 'location_lat' => 'portal.sabbath.location_lat', 'location_lng' => 'portal.sabbath.location_lng',
    'start_offset_minutes' => 'portal.sabbath.start_offset_minutes', 'end_offset_minutes' => 'portal.sabbath.end_offset_minutes',
    'switches_shown' => 'portal.sabbath.enabled'] as $field => $key) {
    foreach ($pStored as $k => $v) {
        setting($k, $v);
    }
    $send = $pNew;
    unset($send[$field]);
    $answer = post($adminJar, '/admin/settings/sabbath', '/admin/settings/sabbath', $send);
    $now    = $installationSabbath();
    $others = $field === 'start_offset_minutes' ? 'portal.sabbath.end_offset_minutes' : 'portal.sabbath.start_offset_minutes';
    ok(
        'P08 a Sabbath save that does not SEND `' . $field . '` leaves ' . $key . ' exactly as stored (' . $pStored[$key] . '), and the rest of the save still happens',
        ($now[$key] ?? null) === $pStored[$key] && ($now[$others] ?? null) !== $pStored[$others]
            && str_contains(words($answer['body']), 'Sabbath settings saved.') === true,
        $key . ' now ' . json_encode($now[$key] ?? null) . ', ' . $others . ' now ' . json_encode($now[$others] ?? null) . '; ' . said($answer)
    );
}
foreach ($pStored as $k => $v) {
    setting($k, $v);
}
$badLat  = post($adminJar, '/admin/settings/sabbath', '/admin/settings/sabbath', ['location_lat' => '95'] + $pNew);
$afterBad = $installationSabbath();
$halfOne = post($adminJar, '/admin/settings/sabbath', '/admin/settings/sabbath', ['location_lat' => ''] + $pNew);
$afterHalf = $installationSabbath();
ok(
    'P09 a latitude past 90, and a place with only ONE of its two numbers, are REFUSED with a reason and NOTHING is saved (the old page stored any number, and 0 for a word)',
    $afterBad['portal.sabbath.method'] === 'sunset_calc' && $afterBad['portal.sabbath.location_lat'] === '12.5'
        && str_contains(words($badLat['body']), 'latitude must be a number from -90 to 90') === true
        && $afterHalf['portal.sabbath.location_lng'] === '45.5'
        && str_contains(words($halfOne['body']), 'give both the latitude and the longitude, or leave both empty') === true,
    said($badLat) . ' / ' . said($halfOne) . '; ' . json_encode($afterHalf)
);

// ---- #935: a venue's country --------------------------------------------------------
$colDefault = one("SELECT COLUMN_DEFAULT AS d, IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblVenues' AND COLUMN_NAME = 'countryCode'");
ok(
    'P10 tblVenues.countryCode has NO default and may not be empty, so a writer that names no country is refused by the database (it defaulted to \'GB\')',
    $colDefault !== null && $colDefault['d'] === null && $colDefault['n'] === 'NO',
    json_encode($colDefault)
);
$siteRow('org.address.countryCode', 'AU');
$pManage = req($adminJar, 'GET', '/venues/manage');
ok(
    'P11 the new-venue form\'s country box starts on the ORGANISATION\'s own country, AU (it started on \'GB\')',
    $pManage['status'] === 200 && tz_find('#id="countryCode" name="countryCode"[^>]*value="AU"#s', $pManage['body']) === 1,
    said($pManage) . '; ' . (tz_find('#id="countryCode" name="countryCode"[^>]*>#s', $pManage['body'], $box) === 1 ? $box[0] : 'no country box found')
);
$venueCountry = static fn (string $name): ?string => one('SELECT countryCode FROM tblVenues WHERE venueName = ? AND siteID = 1', 's', [$name])['countryCode'] ?? null;
$pVenue = ['action' => 'save', 'venueID' => 0, 'timezone' => ''];
post($adminJar, '/venues/manage', '/venues/venue-save', $pVenue + ['venueName' => 'Selftest place 935 empty', 'countryCode' => '']);
ok(
    'P12 a venue saved with the country box EMPTY takes the organisation\'s own country, AU (it was stored as \'GB\')',
    $venueCountry('Selftest place 935 empty') === 'AU',
    json_encode($venueCountry('Selftest place 935 empty'))
);
$siteRow('org.address.countryCode', '');
post($adminJar, '/venues/manage', '/venues/venue-save', $pVenue + ['venueName' => 'Selftest place 935 nowhere', 'countryCode' => '']);
$pSaid = flashAfter($adminJar, '/venues/manage');
ok(
    'P13 with no country typed and NONE set for the organisation, the venue is REFUSED and that is said; no venue is made (it was saved as \'GB\' without a word)',
    $venueCountry('Selftest place 935 nowhere') === null && str_contains($pSaid, 'none is set at Admin, Settings, Organisation') === true,
    'stored ' . json_encode($venueCountry('Selftest place 935 nowhere')) . '; said: ' . $pSaid
);
q("INSERT INTO tblVenues (venueID, siteID, venueName, countryCode, timezone) VALUES (960935, 1, 'Selftest place 935 kept', 'US', 'America/New_York')");
post($adminJar, '/venues/manage?edit=960935', '/venues/venue-save', ['action' => 'save', 'venueID' => 960935, 'venueName' => 'Selftest place 935 kept renamed', 'timezone' => 'America/New_York']);
ok(
    'P14 an edit that does not SEND the country leaves the venue\'s own (US) exactly as it is (#677; it became \'GB\')',
    $venueCountry('Selftest place 935 kept renamed') === 'US',
    json_encode(one('SELECT venueName, countryCode FROM tblVenues WHERE venueID = 960935'))
);
q("DELETE FROM tblVenueUsageTypes WHERE venueID IN (SELECT venueID FROM tblVenues WHERE venueName LIKE 'Selftest place 935%')");
q("DELETE FROM tblVenues WHERE venueName LIKE 'Selftest place 935%'");

// ---- round 2 of the independent check (#935) ------------------------------------------
// P15/P16: ANOTHER organisation forced in after start-up (Site::forceContext(), as
// ApiAuth does for an API key of another organisation). The settings were merged at
// start-up for organisation 1; the place must still be organisation TWO's. Organisation
// 1 is given London's position and organisation two Santiago's, on Santiago's clock.
$pMultisiteWas = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'multisite.enabled' AND siteID IS NULL")['settingValue'] ?? null;
setting('multisite.enabled', 'true'); // Site::forceContext() refuses to switch otherwise; put back straight after P16
setting('portal.sabbath.enabled', '1');
setting('portal.sabbath.method', 'sunset_calc');
setting('portal.sabbath.location_lat', '');
setting('portal.sabbath.location_lng', '');
setting('portal.sabbath.timezone', '');
setting('portal.sabbath.start_offset_minutes', '0');
setting('portal.sabbath.end_offset_minutes', '0');
$siteRow('org.latitude', '51.5');
$siteRow('org.longitude', '-0.12');
$siteRow('org.latitude', '-33.45', ORG_TWO);
$siteRow('org.longitude', '-70.66', ORG_TWO);
// The child's PHP default zone is organisation 1's (Sydney, set by start-up), where
// Santiago's Friday noon is already SATURDAY: P16 is what found that date_sun_info()
// read the day on that clock and opened the window a day late.
$santiagoSunset = $sunsetFriday('America/Santiago', -33.45, -70.66);
$forced = child(['task' => 'sabbathForced', 'site' => ORG_TWO, 'moments' => [$santiagoSunset - 1800, $santiagoSunset + 600]]);
ok(
    'P15 with another organisation forced in after start-up, its OWN map position is used (-33.45, -70.66), not the start-up organisation\'s (51.5, -0.12)',
    $forced['threw'] === '' && ($forced['siteNow'] ?? 0) === ORG_TWO
        && abs((float) ($forced['place']['lat'] ?? 0) - -33.45) < 0.0001 && ($forced['place']['from'] ?? '') === 'organisation',
    json_encode($forced)
);
ok(
    'P16 …so its quiet hours begin at ITS sunset on its clock: quiet 10 minutes after Santiago\'s Friday sunset, not 30 minutes before (London\'s sunset made it quiet over an hour early)',
    $forced['threw'] === '' && ($forced['quiet'] ?? null) === [false, true],
    json_encode($forced['quiet'] ?? null)
);
ok(
    'P38 after the force, NO organisation given means the FORCED one for the place as well as the clock: Santiago\'s position and quiet hours from place(null) and isQuietNow(…, null)',
    $forced['threw'] === '' && abs((float) ($forced['placeNull']['lat'] ?? 0) - -33.45) < 0.0001 && ($forced['quietNull'] ?? null) === [false, true],
    json_encode([$forced['placeNull'] ?? null, $forced['quietNull'] ?? null])
);
// P22: the same two organisations WITHOUT forcing the context, which is what the
// held-texts job does: it passes each text's own organisation while Site::id()
// stays the job's. P15/P16 cannot see a place() that asks about Site::id() rather
// than the organisation given, because there the two are the same (round 2, M3).
$other = child(['task' => 'sabbathOther', 'site' => ORG_TWO, 'moments' => [$santiagoSunset - 1800, $santiagoSunset + 600]]);
ok(
    'P22 WITHOUT forcing the context (the held-texts job), the organisation GIVEN decides: Santiago\'s position and Santiago\'s sunset, while the request is still organisation 1\'s',
    $other['threw'] === '' && ($other['siteNow'] ?? 0) === 1
        && abs((float) ($other['place']['lat'] ?? 0) - -33.45) < 0.0001 && ($other['quiet'] ?? null) === [false, true],
    json_encode($other)
);
$siteRow('org.latitude', null, ORG_TWO);
$siteRow('org.longitude', null, ORG_TWO);
if ($pMultisiteWas === null) {
    q("DELETE FROM tblSettings WHERE settingKey = 'multisite.enabled' AND siteID IS NULL");
} else {
    setting('multisite.enabled', (string) $pMultisiteWas);
}

// P17/P18: the organisation page's own format for a coordinate near zero, and exactly zero.
$orgSaved = post($adminJar, '/admin/settings/organisation', '/admin/settings/organisation/save',
    ['addressLine1' => 'Selftest 935', 'countryCode' => 'GB', 'latitude' => '0.00004', 'longitude' => '0']);
$orgLat = one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = 1', 's', ['org.latitude'])['settingValue'] ?? null;
$orgLng = one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = 1', 's', ['org.longitude'])['settingValue'] ?? null;
ok(
    'P17 the organisation page stores a near-zero coordinate as a PLAIN decimal (0.00004, and 0), never PHP\'s exponent ("4.0E-5")',
    $orgLat === '0.00004' && $orgLng === '0',
    said($orgSaved) . '; stored ' . json_encode([$orgLat, $orgLng])
);
$nearZero = child(['task' => 'sabbath', 'at' => $pAt, 'showIn' => N_ZONE]);
ok(
    'P18 …and the Sabbath window uses it: a place at latitude 0.00004 and longitude exactly 0 is a real place, from the organisation (it used to fall back to the fixed times)',
    $nearZero['threw'] === '' && ($nearZero['place']['from'] ?? '') === 'organisation'
        && (float) ($nearZero['place']['lat'] ?? -1) === 4.0E-5 && is_numeric($nearZero['place']['lng'] ?? null) === true
        && (float) $nearZero['place']['lng'] === 0.0, // JSON carries 0.0 as 0, so compared as a number
    json_encode($nearZero)
);
// P17b-P17g (#945): a coordinate that is not a NUMBER is refused, never turned
// into 0. GeoLocation::validateCoords() used `(float)`, which makes any text a
// number: "north" became 0, a real place on the equator, and was saved. The
// stored position at this point is P17's (0.00004, 0), so a refusal is proved
// by it being UNCHANGED as well as by the words shown. Each post sends one bad
// box beside a good one, so neither a whole-pair test nor a one-box test alone
// can pass it.
$orgPos = static fn (): array => [
    one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = 1', 's', ['org.latitude'])['settingValue'] ?? null,
    one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = 1', 's', ['org.longitude'])['settingValue'] ?? null,
];
$orgRefusalWords = 'Nothing was saved. The map position must be two numbers';
foreach ([
    'P17b a WORD in the latitude box' => ['latitude' => 'north', 'longitude' => '-0.1278'],
    'P17c a WORD in the longitude box' => ['latitude' => '51.5074', 'longitude' => 'west'],
    'P17d a decimal COMMA ("51,5" used to be saved as 51)' => ['latitude' => '51,5', 'longitude' => '-0.1278'],
    'P17e one box filled and the other left empty' => ['latitude' => '51.5074', 'longitude' => ''],
] as $what => $boxes) {
    $sent  = post($adminJar, '/admin/settings/organisation', '/admin/settings/organisation/save', ['addressLine1' => 'Selftest 935', 'countryCode' => 'GB'] + $boxes);
    $shown = flashAfter($adminJar, '/admin/settings/organisation');
    ok(
        $what . ' is REFUSED with the reason, and the stored map position is left as it was (0.00004, 0) -- never saved as 0, never cleared',
        $sent['status'] === 302 && str_contains($shown, $orgRefusalWords) === true && $orgPos() === ['0.00004', '0'],
        said($sent) . '; shown "' . $shown . '"; stored ' . json_encode($orgPos())
    );
}
// The over-reach direction: what IS a number still saves -- the exponent form
// the page used to write (#935), and exactly 0 in both boxes, which is a real
// place and must never be mistaken for "nothing typed".
$sent = post($adminJar, '/admin/settings/organisation', '/admin/settings/organisation/save',
    ['addressLine1' => 'Selftest 935', 'countryCode' => 'GB', 'latitude' => ' 4.0E-5 ', 'longitude' => '-1.5e1']);
ok(
    'P17f a number in EXPONENT form (with spaces round it) is still accepted, and stored as a plain decimal (0.00004, -15)',
    $sent['status'] === 302 && $orgPos() === ['0.00004', '-15'],
    said($sent) . '; shown "' . flashAfter($adminJar, '/admin/settings/organisation') . '"; stored ' . json_encode($orgPos())
);
$sent = post($adminJar, '/admin/settings/organisation', '/admin/settings/organisation/save',
    ['addressLine1' => 'Selftest 935', 'countryCode' => 'GB', 'latitude' => '0', 'longitude' => '0']);
ok(
    'P17g exactly 0 in both boxes is still a real place and is saved (0, 0)',
    $sent['status'] === 302 && $orgPos() === ['0', '0'],
    said($sent) . '; stored ' . json_encode($orgPos())
);
// Back to P17's position, which P18 and the checks after it expect.
post($adminJar, '/admin/settings/organisation', '/admin/settings/organisation/save',
    ['addressLine1' => 'Selftest 935', 'countryCode' => 'GB', 'latitude' => '0.00004', 'longitude' => '0']);
// P19: what the organisation page stored BEFORE round 2 is still read.
$siteRow('org.latitude', '4.0E-5');
$exponent = child(['task' => 'sabbath', 'at' => $pAt, 'showIn' => N_ZONE]);
ok(
    'P19 a coordinate already stored with an exponent ("4.0E-5", as the organisation page used to write it) is read as a place, not as "no place"',
    $exponent['threw'] === '' && ($exponent['place']['from'] ?? '') === 'organisation' && ($exponent['place']['lat'] ?? null) === 4.0E-5,
    json_encode($exponent)
);
// P20: 0 and 0 typed on the Sabbath page is saved and used.
$siteRow('org.latitude', '');
$siteRow('org.longitude', '');
post($adminJar, '/admin/settings/sabbath', '/admin/settings/sabbath', ['location_lat' => '0', 'location_lng' => '0'] + $pNew);
$zeroes = child(['task' => 'sabbath', 'at' => $pAt, 'showIn' => N_ZONE]);
ok(
    'P20 a place of exactly 0, 0 typed on the Sabbath page is stored and used as a place (a real point, not "no place")',
    $placeNow('portal.sabbath.location_lat') === '0' && $placeNow('portal.sabbath.location_lng') === '0'
        && $zeroes['threw'] === '' && ($zeroes['place']['from'] ?? '') === 'setting',
    json_encode([$placeNow('portal.sabbath.location_lat'), $placeNow('portal.sabbath.location_lng'), $zeroes])
);
// P21: the preview on the answer to a save shows what was JUST saved.
setting('portal.sabbath.method', 'fixed');
setting('portal.sabbath.start_offset_minutes', '0');
$previewed = post($adminJar, '/admin/settings/sabbath', '/admin/settings/sabbath',
    ['method' => 'fixed', 'start_offset_minutes' => '30', 'end_offset_minutes' => '0', 'switches_shown' => '1', 'enabled' => 'on',
     'location_lat' => '', 'location_lng' => '', 'timezone' => '']);
ok(
    'P21 the answer to a save previews the window JUST saved: a start offset of 30 minutes shows the window beginning at 18:30 (start-up had read 0, so it showed 18:00)',
    str_contains(words($previewed['body']), 'Sabbath settings saved.') === true && tz_find('#Current window \(your settings\):\s*\S+ \d+ \S+ 18:30 →#', words($previewed['body'])) === 1,
    said($previewed) . '; ' . (tz_find('#Current window \(your settings\):[^(]*#', words($previewed['body']), $pv) === 1 ? $pv[0] : 'no preview found')
);

// ---- round 3: the right DAY's sunset, everywhere (#935) ---------------------------------
// date_sun_info() answers for a day chosen by PHP's DEFAULT zone, and near the date
// line for the wrong day even on the place's own zone. Each window below must open on
// that week's FRIDAY evening and close on SATURDAY evening on the place's own clock,
// with PHP's default zone set three different ways, two of them far from the place.
$satNoon = static fn (string $zone, string $saturday): int => (new DateTimeImmutable($saturday . ' 12:00:00', new DateTimeZone($zone)))->getTimestamp();
$places935 = [
    'Tongatapu'  => ['Pacific/Tongatapu', -21.14, -175.2, '2026-11-13'],
    'Apia'       => ['Pacific/Apia', -13.83, -171.76, '2026-11-13'],
    'Chatham'    => ['Pacific/Chatham', -43.95, -176.56, '2026-11-13'],
    'Adak'       => ['America/Adak', 51.88, -176.66, '2026-11-13'],
    'Auckland'   => ['Pacific/Auckland', -36.85, 174.76, '2027-01-15'],
    'Kiritimati' => ['Pacific/Kiritimati', 1.87, -157.4, '2026-11-13'],
    'Fiji'       => ['Pacific/Fiji', -18.14, 178.44, '2026-11-13'],
    '+10:00'     => ['+10:00', -27.47, 153.03, '2026-11-13'],
    'Anadyr'     => ['Asia/Anadyr', 64.73, 177.5, '2027-10-01'], // PHP's calculation SKIPS this Friday: the halfway rule
];
$cases935 = [];
foreach ($places935 as $name => [$zone, $lat, $lng, $friday]) {
    $cases935[$name] = [$zone, $lat, $lng, $satNoon($zone, (new DateTimeImmutable($friday))->modify('+1 day')->format('Y-m-d'))];
}
// Polar night: the sun does not rise at Longyearbyen in mid-December, so there is no
// sunset and the window falls back to 18:00 on its own clock, as the docblock says.
$cases935['Longyearbyen'] = ['Arctic/Longyearbyen', 78.22, 15.65, $satNoon('Arctic/Longyearbyen', '2026-12-19')];
// Arctic towns whose sun sets BEFORE local noon around the start of polar night.
$cases935['Vardo']    = ['Europe/Oslo', 70.37, 31.11, $satNoon('Europe/Oslo', '2026-11-21')];
$cases935['Tromso']   = ['Europe/Oslo', 69.65, 18.96, $satNoon('Europe/Oslo', '2026-11-28')];
$cases935['Kirkenes'] = ['Europe/Oslo', 69.73, 30.05, $satNoon('Europe/Oslo', '2027-11-27')];
setting('portal.sabbath.method', 'sunset_calc');
setting('portal.sabbath.location_lat', '-33.45');
setting('portal.sabbath.location_lng', '-70.66');
$runs935 = [];
foreach (['UTC', 'Pacific/Kiritimati', 'Pacific/Pago_Pago'] as $phpZone) {
    $runs935[$phpZone] = child(['task' => 'sunsetWindows', 'phpZone' => $phpZone, 'cases' => $cases935,
        'otherSite' => ORG_TWO, 'otherAt' => $satNoon('America/Santiago', '2028-06-17')]);
}
$pNo = 23;
foreach ($places935 as $name => [$zone, $lat, $lng, $friday]) {
    $saturday = (new DateTimeImmutable($friday))->modify('+1 day')->format('Y-m-d');
    $seen = [];
    $right = true;
    foreach ($runs935 as $phpZone => $run) {
        $window = (string) ($run['windows'][$name] ?? '');
        $seen[$phpZone] = $window;
        // Friday afternoon or evening to Saturday afternoon or evening, and NOT 18:00
        // on the dot: that is the polar-night fallback, and a first version of this
        // check passed a sunsetAt() that found no sunset and fell back every time
        // (planted). None of these places has a sunset at 18:00 in these weeks: the
        // windows seen are printed under each check, pass or fail, so that can be read.
        $right = $right && $run['threw'] === ''
            && tz_find('#^Fri ' . $friday . ' (?!18:00)(1[2-9]|2[0-3]):\d\d / Sat ' . $saturday . ' (?!18:00)(1[2-9]|2[0-3]):\d\d$#', $window) === 1;
    }
    ok(
        'P' . $pNo++ . ' the sunset window at ' . $name . ' (' . $zone . ') opens on FRIDAY ' . $friday . ' evening and closes on SATURDAY evening, on its own clock, whatever PHP\'s default zone',
        $right,
        json_encode($seen)
    );
    if ($right === true) {
        echo '        ' . json_encode($seen) . "\n";
    }
}
ok(
    'P' . $pNo++ . ' working out windows, one of them for ANOTHER organisation, leaves PHP\'s default time zone exactly as it found it',
    array_filter($runs935, static fn (array $run): bool => ($run['zoneBefore'] ?? '') === '' || ($run['zoneAfter'] ?? '') !== $run['zoneBefore']) === [],
    json_encode(array_map(static fn (array $run): array => [$run['zoneBefore'] ?? null, $run['zoneAfter'] ?? null], $runs935))
);
ok(
    'P' . $pNo++ . ' and writes NOTHING to the error log (an offset zone such as +10:00 used to log a notice for every window)',
    array_filter($runs935, static fn (array $run): bool => ($run['errorsAdded'] ?? -1) !== 0) === [],
    json_encode(array_map(static fn (array $run): mixed => $run['errorsAdded'] ?? null, $runs935))
);
$polar = array_map(static fn (array $run): string => (string) ($run['windows']['Longyearbyen'] ?? ''), $runs935);
$polarSaid = array_map(static fn (array $run): mixed => $run['noSunset']['Longyearbyen'] ?? null, $runs935);
$otherSaid = array_map(static fn (array $run): mixed => $run['noSunset']['Tongatapu'] ?? null, $runs935);
ok(
    'P' . $pNo++ . ' in POLAR NIGHT (Longyearbyen, December) there is no sunset, and the window says so: 18:00 Friday to 18:00 Saturday on its own clock, marked as "no sunset" (and Tongatapu, which has one, is not)',
    array_unique($polar) === ['UTC' => 'Fri 2026-12-18 18:00 / Sat 2026-12-19 18:00']
        && array_unique($polarSaid) === ['UTC' => true] && array_unique($otherSaid) === ['UTC' => false],
    json_encode([$polar, $polarSaid, $otherSaid])
);
// ARCTIC towns where the sun sets BEFORE local noon for a few days around the start of
// polar night (#935 round 3: round 3's "between noon and noon" rule took the NEXT day's
// sunset, or none). Each Friday window must open on that Friday, within ten minutes of
// the sunset the independent check calculated.
foreach (['Vardo' => ['2026-11-20', '11:47'], 'Tromso' => ['2026-11-27', '11:46'], 'Kirkenes' => ['2027-11-26', '11:20']] as $name => [$friday, $expected]) {
    $zone = $cases935[$name][0];
    $want = (new DateTimeImmutable($friday . ' ' . $expected . ':00', new DateTimeZone($zone)))->getTimestamp();
    $seen = [];
    $right = true;
    foreach ($runs935 as $phpZone => $run) {
        $window = (string) ($run['windows'][$name] ?? '');
        $seen[$phpZone] = $window;
        $opens = DateTimeImmutable::createFromFormat('D Y-m-d H:i', substr($window, 0, 20), new DateTimeZone($zone));
        // Only the FRIDAY edge is held here: at Tromsø the Saturday is the first day of
        // polar night, so that edge is rightly 18:00 and marked "no sunset".
        $right = $right && $run['threw'] === '' && $opens !== false && abs($opens->getTimestamp() - $want) <= 600;
    }
    ok(
        'P' . $pNo++ . ' in the ARCTIC, where the sun sets before noon, ' . $name . '\'s window opens on FRIDAY ' . $friday . ' at about ' . $expected . ' (round 3 gave the next day\'s sunset, or 18:00)',
        $right,
        json_encode($seen)
    );
}

// ---- round 5: "the sun does not set" is said ONLY when it is true (#935) ----------------
// P34 held the mark TRUE for polar night and FALSE for a place with sunsets, but
// nothing held it false for the other two ways an edge is 18:00 — so a mark set on
// EVERY 18:00 edge passed all 248 checks, and the page would have told every
// installation on the fixed method that the sun does not set (round 4's check, M3).
ok(
    'P39 an 18:00 that is NOT a missing sunset is never marked as one: the fixed method, and sunset with no place known, both say "noSunset" false',
    array_filter($runs935, static fn (array $run): bool => ($run['noSunsetFixed'] ?? null) !== false
        || ($run['noSunsetNoPlace'] ?? null) !== false || array_key_exists('placeNoPlace', $run) === false || $run['placeNoPlace'] !== null) === [],
    json_encode(array_map(static fn (array $run): array => [$run['noSunsetFixed'] ?? 'missing', $run['noSunsetNoPlace'] ?? 'missing', $run['placeNoPlace'] ?? 'missing'], $runs935))
);
// The PAGE's note, read from the drawn page. The preview is this week's window, from
// time(), so a place without a sunset NOW is chosen at run time: 89.9° north or south,
// where the sun is either up all day or down all day except for a few hours around an
// equinox, and never at both poles at once. The child says which, by asking the same
// method the page asks; a run where it says neither fails rather than passing empty.
setting('portal.sabbath.enabled', '1');
setting('portal.sabbath.timezone', '');
setting('portal.sabbath.method', 'sunset_calc');
setting('portal.sabbath.location_lat', '-33.87');
setting('portal.sabbath.location_lng', '151.21');
$withSunsets = req($adminJar, 'GET', '/admin/settings/sabbath');
setting('portal.sabbath.method', 'fixed');
$fixedPage = req($adminJar, 'GET', '/admin/settings/sabbath');
ok(
    'P40 the Sabbath page does NOT say "the sun does not set" when the window has real sunsets (Sydney), nor on the fixed method',
    str_contains($withSunsets['body'], 'Current window (your settings)') === true && str_contains($withSunsets['body'], 'data-sabbath-no-sunset') === false
        && str_contains($fixedPage['body'], 'Current window (your settings)') === true && str_contains($fixedPage['body'], 'data-sabbath-no-sunset') === false,
    said($withSunsets) . ' / ' . said($fixedPage)
);
$poles = child(['task' => 'noSunsetNow', 'places' => ['north' => [89.9, 0.0], 'south' => [-89.9, 0.0]]]);
$pole  = ($poles['noSunset']['north'] ?? false) === true ? 'north' : (($poles['noSunset']['south'] ?? false) === true ? 'south' : '');
setting('portal.sabbath.method', 'sunset_calc');
setting('portal.sabbath.location_lat', $pole === 'south' ? '-89.9' : '89.9');
setting('portal.sabbath.location_lng', '0');
$polarPage = req($adminJar, 'GET', '/admin/settings/sabbath');
ok(
    'P41 the Sabbath page DOES say "the sun does not set" under the preview at a place where it really does not this week (' . ($pole === '' ? 'neither pole, which should not happen' : 'the ' . $pole . ' pole') . ')',
    $pole !== '' && str_contains($polarPage['body'], 'data-sabbath-no-sunset') === true
        && str_contains(words($polarPage['body']), 'The sun does not set that Friday or Saturday at this place, so 18:00 is used') === true,
    json_encode($poles) . '; ' . said($polarPage)
);

// ---- put everything back as part P found it ------------------------------------------
q('DELETE FROM tblSettings WHERE settingKey IN ' . P_KEYS . ' AND siteID IN (1, ' . ORG_TWO . ')');
foreach ($pWas as $row) {
    if ($row['siteID'] === null) {
        setting((string) $row['settingKey'], (string) $row['settingValue']);
    } else {
        $siteRow((string) $row['settingKey'], (string) $row['settingValue'], (int) $row['siteID']);
    }
}
if (array_filter($pWas, static fn (array $row): bool => $row['siteID'] === null && $row['settingKey'] === 'multisite.enabled') === []) {
    q("DELETE FROM tblSettings WHERE settingKey = 'multisite.enabled' AND siteID IS NULL");
}
orgZone(1, $pOrgZone === null ? null : (string) $pOrgZone);

// =============================================================================
// Q. NO COUNTRY IS BUILT IN (#942, D-133)
// =============================================================================
// The installation's country was seeded 'GB'; the shared address form filled an
// EMPTY country box with 'GB'; GeoLocation::normaliseAddress() turned a missing
// country into 'GB'; and a small group's country defaulted to 'GB' and fell back
// to it in two places. So on an installation where nobody had chosen, every new
// group, meeting place and organisation address came out British. Now the
// installer asks (proved in installer-selftest.php, N01-N07), each organisation
// chooses its own starting from the installation's, and nothing makes one up.
// Each check says what the OLD code gave.
head('Q. #942: no country is built in');
$qOrgWas     = q("SELECT siteID, settingKey, settingValue, defaultValue FROM tblSettings WHERE settingKey LIKE 'org.%' AND (siteID IS NULL OR siteID = 1)");
$qGroupsWas  = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'small-groups.enabled' AND siteID IS NULL");
$qZoneWas    = one('SELECT timezone FROM tblSites WHERE siteID = 1')['timezone'] ?? null;
$qRepo       = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR;
require_once $qRepo . 'GeoLocation.php';
require_once $qRepo . 'Countries.php';
/** Which option of the select with this id carries `selected`, or null when none does (a vote for the first). */
$qSelected = static function (string $html, string $id): ?string {
    if (tz_find('#<select[^>]*\bid="' . $id . '"[^>]*>(.*?)</select>#s', $html, $sel) !== 1) {
        return '(no such list)';
    }
    $n = tz_findAll('#<option value="([^"]*)"\s*selected#', $sel[1], $opts);

    return $n === 1 ? html_entity_decode($opts[1][0], ENT_QUOTES, 'UTF-8') : ($n === 0 ? null : '(several selected)');
};
/** The organisation page's address form, as a browser would send it untouched (the country as drawn). */
$qOrgForm = static function (string $html) use ($qSelected): array {
    $fields = [];
    foreach (['addressLine1', 'addressLine2', 'city', 'region', 'postcode', 'latitude', 'longitude', 'what3words'] as $name) {
        if (tz_find('#<input[^>]*\bname="' . $name . '"[^>]*\bvalue="([^"]*)"#s', $html, $m) === 1) {
            $fields[$name] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
    }
    $fields['countryCode'] = (string) $qSelected($html, 'countryCode');

    return $fields;
};
$qOwnRow    = static fn (int $site = 1): ?string => one("SELECT settingValue FROM tblSettings WHERE settingKey = 'org.address.countryCode' AND siteID = ?", 'i', [$site])['settingValue'] ?? null;
$qInstall   = static fn (): ?string => one("SELECT settingValue FROM tblSettings WHERE settingKey = 'org.address.countryCode' AND siteID IS NULL")['settingValue'] ?? null;
$qSaveOrg   = static function (array $change) use ($adminJar, $qOrgForm): array {
    $page = req($adminJar, 'GET', '/admin/settings/organisation');
    $fields = array_merge($qOrgForm($page['body']), $change);
    $fields['csrf_token'] = token($page['body']);

    return req($adminJar, 'POST', '/admin/settings/organisation/save', $fields);
};
$qNewVenueBox = static function () use ($adminJar): string {
    $page = req($adminJar, 'GET', '/venues/manage');

    return tz_find('#id="countryCode" name="countryCode"[^>]*value="([^"]*)"#s', $page['body'], $m) === 1 ? $m[1] : '(no country box)';
};

// Start where a fresh installation whose installer was left on "Not chosen yet" starts.
q("DELETE FROM tblSettings WHERE settingKey = 'org.address.countryCode' AND siteID IS NOT NULL");
setting('org.address.countryCode', '');
orgZone(1, 'Pacific/Auckland');

$qSeed = one("SELECT `settingValue`, `defaultValue` FROM `tblSettings` WHERE `settingKey` = 'org.address.countryCode' AND `siteID` IS NULL");
$qCol  = one("SELECT COLUMN_DEFAULT AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSmallGroups' AND COLUMN_NAME = 'countryCode'");
ok(
    'Q00 the install script names NO country: the installation\'s country is seeded empty, with an empty default, and a small group\'s country defaults to empty (all three were \'GB\')',
    ($qSeed['defaultValue'] ?? null) === '' && $qCol !== null && in_array($qCol['d'], ['', "''"], true) === true,
    json_encode([$qSeed, $qCol])
);

$qNorm = [
    'missing'      => \Portal\Core\GeoLocation::normaliseAddress(['line1' => 'x'])['countryCode'],
    'empty'        => \Portal\Core\GeoLocation::normaliseAddress(['countryCode' => ''])['countryCode'],
    'three letters'=> \Portal\Core\GeoLocation::normaliseAddress(['countryCode' => 'GBR'])['countryCode'],
    'a list'       => \Portal\Core\GeoLocation::normaliseAddress(['countryCode' => ['GB']])['countryCode'],
    'typed untidy' => \Portal\Core\GeoLocation::normaliseAddress(['countryCode' => ' fr '])['countryCode'],
    'over the wire'=> \Portal\Core\GeoLocation::fromLocationObject(['address' => ['line1' => '1 Rue X']])['address']['countryCode'],
];
ok(
    'Q01 GeoLocation::normaliseAddress() keeps a missing country MISSING: none, empty, not two letters, or not text gives null; " fr " gives FR; and an address arriving over the wire with no country has none (each of the four used to be \'GB\')',
    $qNorm === ['missing' => null, 'empty' => null, 'three letters' => null, 'a list' => null, 'typed untidy' => 'FR', 'over the wire' => null],
    json_encode($qNorm)
);

$qPage = req($adminJar, 'GET', '/admin/settings/organisation');
ok(
    'Q02 with no country chosen anywhere, the organisation page offers "Same as the installation (none chosen yet)" as the ONE selected choice, and no box anywhere on it holds GB (it showed GB)',
    $qPage['status'] === 200 && $qSelected($qPage['body'], 'countryCode') === '' && str_contains(words($qPage['body']), 'Same as the installation (none chosen yet)') === true
        && tz_find('#value="GB"\s*selected|name="countryCode"[^>]*value="GB"#', $qPage['body']) === 0,
    said($qPage) . '; selected ' . json_encode($qSelected($qPage['body'], 'countryCode'))
);
$qSaved = $qSaveOrg(['addressLine1' => 'Selftest 942 Lane']);
ok(
    'Q03 pressing Save with the country left as drawn stores NO country for the organisation and leaves the installation\'s alone (it stored GB as the organisation\'s own)',
    $qOwnRow() === null && $qInstall() === '' && one("SELECT 1 AS x FROM tblSettings WHERE settingKey = 'org.address.line1' AND siteID = 1 AND settingValue = 'Selftest 942 Lane'") !== null,
    said($qSaved) . '; own ' . json_encode($qOwnRow()) . ', installation ' . json_encode($qInstall())
);

setting('org.address.countryCode', 'NZ');
$qPage = req($adminJar, 'GET', '/admin/settings/organisation');
$qSaved = $qSaveOrg([]);
ok(
    'Q04 the installation\'s country, NZ, is offered as "Same as the installation: New Zealand", selected; Save untouched does NOT copy it into the organisation\'s own row (#674), so a later change to the installation\'s still reaches it',
    $qSelected($qPage['body'], 'countryCode') === '' && str_contains(words($qPage['body']), 'Same as the installation: New Zealand') === true && $qOwnRow() === null,
    said($qSaved) . '; own ' . json_encode($qOwnRow())
);
ok('Q05 a new venue in that organisation starts on the installation\'s country, NZ, which the organisation follows', $qNewVenueBox() === 'NZ', $qNewVenueBox());

$qSaved = $qSaveOrg(['countryCode' => 'fr']);
$qPage  = req($adminJar, 'GET', '/admin/settings/organisation');
ok(
    'Q06 an organisation may choose ITS OWN country: FR is stored on the organisation\'s own row, the installation keeps NZ, the second organisation is untouched, the page shows FR chosen, and a new venue starts on FR',
    $qOwnRow() === 'FR' && $qInstall() === 'NZ' && $qOwnRow(ORG_TWO) === null && $qSelected($qPage['body'], 'countryCode') === 'FR' && $qNewVenueBox() === 'FR',
    said($qSaved) . '; own ' . json_encode($qOwnRow()) . ', installation ' . json_encode($qInstall()) . ', venue box ' . $qNewVenueBox()
);
ok(
    'Q07 the page SAYS the organisation\'s time zone (Pacific/Auckland) is not in France and which zone France uses; it suggests, it does not change the zone',
    str_contains(words($qPage['body']), 'This organisation\'s time zone is Pacific/Auckland, which is not in France. France uses Europe/Paris.') === true
        && zoneOfOrg(1) === 'Pacific/Auckland',
    zoneOfOrg(1)
);

$qRefused = $qSaveOrg(['countryCode' => 'ZZ', 'addressLine1' => 'Selftest 942 Refused']);
ok(
    'Q08 a country that is not on the list is REFUSED with the reason and NOTHING is saved: the country stays FR and the address line is unchanged',
    $qOwnRow() === 'FR' && one("SELECT 1 AS x FROM tblSettings WHERE settingKey = 'org.address.line1' AND siteID = 1 AND settingValue = 'Selftest 942 Refused'") === null
        && str_contains(flashAfter($adminJar, '/admin/settings/organisation'), 'The country chosen is not one the portal knows') === true,
    said($qRefused) . '; own ' . json_encode($qOwnRow())
);

$qLine1Is = static fn (string $text): bool => one('SELECT 1 AS x FROM tblSettings WHERE settingKey = ? AND siteID = 1 AND settingValue = ?', 'ss', ['org.address.line1', $text]) !== null;
$qPage    = req($adminJar, 'GET', '/admin/settings/organisation');
$qFields  = $qOrgForm($qPage['body']);
unset($qFields['countryCode']);
$qNoField = req($adminJar, 'POST', '/admin/settings/organisation/save', ['csrf_token' => token($qPage['body']), 'addressLine1' => 'Selftest 942 No country field'] + $qFields);
ok(
    'Q09a a Save that does not SEND the country field leaves the organisation\'s own FR exactly as it is, and the rest of the save still happens (#677: ask whether a field ARRIVED; read as empty it would delete the row)',
    $qOwnRow() === 'FR' && $qLine1Is('Selftest 942 No country field') === true,
    said($qNoField) . '; own ' . json_encode($qOwnRow())
);

// "Same as the installation" must remove THIS organisation's row and nobody else's.
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, 'org.address.countryCode', 'DE', '', 0)", 'i', [ORG_TWO]);
$qSaved = $qSaveOrg(['countryCode' => '']);
ok(
    'Q09 choosing "Same as the installation" REMOVES the organisation\'s own row (an empty row would hide the installation\'s), so it follows NZ again; ANOTHER organisation\'s own country, DE, is left exactly as it was',
    $qOwnRow() === null && $qNewVenueBox() === 'NZ' && $qOwnRow(ORG_TWO) === 'DE',
    said($qSaved) . '; own ' . json_encode($qOwnRow()) . ', the second organisation ' . json_encode($qOwnRow(ORG_TWO))
);
q("DELETE FROM tblSettings WHERE settingKey = 'org.address.countryCode' AND siteID = ?", 'i', [ORG_TWO]);

// A two-letter code the portal does not list, put there by hand (only SQL can): offered, chosen, and kept by a Save.
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (1, 'org.address.countryCode', 'QQ', '', 0)");
$qPage  = req($adminJar, 'GET', '/admin/settings/organisation');
$qSaved = $qSaveOrg(['addressLine1' => 'Selftest 942 Kept QQ']);
$qSaid  = flashAfter($adminJar, '/admin/settings/organisation');
ok(
    'Q10 an organisation\'s own code that is NOT on the list is offered and selected, and a Save with it unchanged SUCCEEDS and keeps it: the success message, the address line posted is stored, and QQ is still there (with no option selected the browser would send "Same as the installation" and quietly drop it; and refusing every such Save would also "keep" it)',
    $qSelected($qPage['body'], 'countryCode') === 'QQ' && $qOwnRow() === 'QQ' && $qLine1Is('Selftest 942 Kept QQ') === true
        && str_contains($qSaid, 'Organisation address saved.') === true,
    said($qSaved) . '; selected ' . json_encode($qSelected($qPage['body'], 'countryCode')) . '; own ' . json_encode($qOwnRow()) . '; said: ' . $qSaid
);
// Text that is not a country code at all ("GBR", as the general Settings editor can store): SAID, offered, kept.
q("UPDATE tblSettings SET settingValue = 'GBR' WHERE settingKey = 'org.address.countryCode' AND siteID = 1");
$qPage  = req($adminJar, 'GET', '/admin/settings/organisation');
$qSaved = $qSaveOrg(['addressLine1' => 'Selftest 942 Kept GBR']);
$qSaid  = flashAfter($adminJar, '/admin/settings/organisation');
ok(
    'Q10b an organisation row holding "GBR" (not a two-letter code) is SAID on the page (ignored, the installation\'s country, New Zealand, used instead), offered back selected, and a Save with it unchanged succeeds and keeps it (it showed "Same as the installation", and the next Save deleted the row with nobody told)',
    tz_find('#data-country-unusable[^>]*>[^<]*stored as &quot;GBR&quot;[^<]*New Zealand#', $qPage['body']) === 1
        && $qSelected($qPage['body'], 'countryCode') === 'GBR' && one("SELECT settingValue FROM tblSettings WHERE settingKey = 'org.address.countryCode' AND siteID = 1")['settingValue'] === 'GBR'
        && $qLine1Is('Selftest 942 Kept GBR') === true && str_contains($qSaid, 'Organisation address saved.') === true,
    said($qSaved) . '; selected ' . json_encode($qSelected($qPage['body'], 'countryCode')) . '; said: ' . $qSaid
);
// A code in small letters ("fr", as the general Settings editor can store) IS France to every other reader, so the
// page must say France, not "fr (not on the portal's list)", and give no warning about it.
q("UPDATE tblSettings SET settingValue = 'fr' WHERE settingKey = 'org.address.countryCode' AND siteID = 1");
$qPage = req($adminJar, 'GET', '/admin/settings/organisation');
ok(
    'Q10c an organisation row holding "fr" in small letters is shown as France, selected, with no "not on the portal\'s list" label and no warning, because the portal uses France for it (round 3 of the #942 check found it labelled as not on the list)',
    $qSelected($qPage['body'], 'countryCode') === 'FR' && str_contains($qPage['body'], 'not on the portal&#039;s list') === false
        && str_contains($qPage['body'], 'data-country-unusable') === false && tz_find('#<option value="FR" selected>France</option>#', $qPage['body']) === 1,
    'selected ' . json_encode($qSelected($qPage['body'], 'countryCode'))
);
q("DELETE FROM tblSettings WHERE settingKey = 'org.address.countryCode' AND siteID = 1");

// ---- small groups --------------------------------------------------------------------------
setting('small-groups.enabled', '1');
setting('org.address.countryCode', '');
$qGroupBox = static function (string $path) use ($adminJar): string {
    $page = req($adminJar, 'GET', $path);

    return tz_find('#id="countryCode" name="countryCode" maxlength="2"[^>]*value="([^"]*)"#s', $page['body'], $m) === 1 ? $m[1] : '(no country box: HTTP ' . $page['status'] . ')';
};
$qGroupCountry = static fn (string $name): ?string => one('SELECT countryCode FROM tblSmallGroups WHERE groupName = ? AND siteID = 1', 's', [$name])['countryCode'] ?? null;
$qGroupId      = static fn (string $name): int => (int) (one('SELECT groupID FROM tblSmallGroups WHERE groupName = ? AND siteID = 1', 's', [$name])['groupID'] ?? 0);
ok('Q11 with no country chosen anywhere, the new-group form\'s country box is EMPTY (the shared form filled it with GB)', $qGroupBox('/small-groups/manage') === '', $qGroupBox('/small-groups/manage'));
post($adminJar, '/small-groups/manage', '/small-groups/save', ['action' => 'create', 'groupName' => 'Selftest 942 nowhere', 'countryCode' => '']);
ok('Q12 a small group saved with the country box EMPTY is stored with NO country (it was stored as GB)', $qGroupCountry('Selftest 942 nowhere') === '', json_encode($qGroupCountry('Selftest 942 nowhere')));
$qNowhere = $qGroupId('Selftest 942 nowhere');
ok('Q13 and its edit form shows the box empty, not filled with any country, so correcting its name does not give it one', $qNowhere > 0 && $qGroupBox('/small-groups/manage?id=' . $qNowhere) === '', $qGroupBox('/small-groups/manage?id=' . $qNowhere));
setting('org.address.countryCode', 'IE');
ok('Q14 with the installation in Ireland, a NEW group\'s box starts on IE (the organisation follows the installation\'s country)', $qGroupBox('/small-groups/manage') === 'IE', $qGroupBox('/small-groups/manage'));
ok('Q15 …while the existing group with no country still shows none', $qGroupBox('/small-groups/manage?id=' . $qNowhere) === '', $qGroupBox('/small-groups/manage?id=' . $qNowhere));
post($adminJar, '/small-groups/manage', '/small-groups/save', ['action' => 'create', 'groupName' => 'Selftest 942 bad', 'countryCode' => 'Ireland']);
ok('Q16 a country that is not two letters is REFUSED and no group is made (it was silently stored as GB)', $qGroupCountry('Selftest 942 bad') === null
    && str_contains(flashAfter($adminJar, '/small-groups/manage'), 'The country must be a two-letter code') === true, json_encode($qGroupCountry('Selftest 942 bad')));
post($adminJar, '/small-groups/manage', '/small-groups/save', ['action' => 'create', 'groupName' => 'Selftest 942 Spain', 'countryCode' => 'es']);
$qSpain = $qGroupId('Selftest 942 Spain');
post($adminJar, '/small-groups/manage?id=' . $qSpain, '/small-groups/save', ['action' => 'update', 'groupID' => $qSpain, 'groupName' => 'Selftest 942 Spain']);
ok('Q17 a group saved as ES keeps ES when an edit does not SEND the country (#677; it became GB)', $qGroupCountry('Selftest 942 Spain') === 'ES', json_encode($qGroupCountry('Selftest 942 Spain')));
ok('Q18 a NEW home on a group\'s list starts on the organisation\'s country, IE (the shared form used to fill GB)', $qGroupBox('/small-groups/place?id=' . $qSpain) === 'IE', $qGroupBox('/small-groups/place?id=' . $qSpain));
$qHomes = static fn (int $group): int => (int) (one('SELECT COUNT(*) AS n FROM tblSmallGroupPlaces WHERE groupID = ?', 'i', [$group])['n'] ?? -1);
$qHomesBefore = $qHomes($qSpain);
post($adminJar, '/small-groups/place?id=' . $qSpain, '/small-groups/place-save', ['action' => 'create', 'groupID' => $qSpain, 'addressLine1' => '1 Selftest 942 Road', 'countryCode' => 'Ireland']);
ok('Q19 a home whose country is not two letters is REFUSED with the reason and no home is added, the rule of the usual place (it was stored as no country without a word)',
    $qHomes($qSpain) === $qHomesBefore && str_contains(flashAfter($adminJar, '/small-groups/place?id=' . $qSpain), 'The country must be a two-letter code') === true,
    'homes ' . $qHomesBefore . ' then ' . $qHomes($qSpain));

// ---- put everything back as part Q found it ------------------------------------------
q("DELETE FROM tblSmallGroupPlaces WHERE groupID IN (SELECT groupID FROM tblSmallGroups WHERE groupName LIKE 'Selftest 942%' AND siteID = 1)");
q("DELETE FROM tblSmallGroups WHERE groupName LIKE 'Selftest 942%' AND siteID = 1");
q("DELETE FROM tblSettings WHERE settingKey LIKE 'org.%' AND (siteID IS NULL OR siteID = 1)");
foreach ($qOrgWas as $row) {
    q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss',
        [$row['siteID'] === null ? null : (int) $row['siteID'], (string) $row['settingKey'], (string) $row['settingValue'], (string) ($row['defaultValue'] ?? '')]);
}
if ($qGroupsWas === null) {
    q("DELETE FROM tblSettings WHERE settingKey = 'small-groups.enabled' AND siteID IS NULL");
} else {
    setting('small-groups.enabled', (string) $qGroupsWas['settingValue']);
}
orgZone(1, $qZoneWas === null ? null : (string) $qZoneWas);

// =============================================================================
// 🏁 The end
// =============================================================================
$errors = is_file($child . $sep . 'php-errors.log') === true ? trim((string) file_get_contents($child . $sep . 'php-errors.log')) : '';
$fatal  = tz_findAll('/PHP (Fatal error|Parse error|Warning|Deprecated).*$/m', $errors, $lines);
ok(
    'Z01 nothing the portal did in this run wrote a PHP error, warning or deprecation to its log',
    $fatal === 0,
    $fatal . ' line(s); the first: ' . substr((string) ($lines[0][0] ?? ''), 0, 400)
);

$total = $pass + $fail;
echo "\nREACHED THE END: {$total} checks, {$pass} passed, {$fail} failed (expected " . EXPECTED_CHECKS . " checks).\n";
cleanup();
if ($fail > 0 || $total !== EXPECTED_CHECKS) {
    echo $total !== EXPECTED_CHECKS ? 'FAILED: the number of checks is not the number expected, so part of this file did not run or the count was not kept up.' . "\n" : "FAILED.\n";
    exit(1);
}
echo "ALL PASSED.\n";
exit(0);
