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
const EXPECTED_CHECKS = 131;

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
    if (preg_match('/^X-Time-Zones-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
    ];
}

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
    $html = (string) preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html);

    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** The message the next page shows after a form was sent (the flash), as words. */
function flashAfter(string $jar, string $page): string
{
    $html = req($jar, 'GET', $page)['body'];
    preg_match_all('#<div class="alert alert-(?:danger|success|warning|info)[^"]*"[^>]*>(.*?)</div>#s', $html, $m);

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
q('INSERT INTO tblVenues (venueID, siteID, venueName, timezone, createdByID) VALUES (?, 1, ?, ?, ?)', 'issi', [VENUE, 'Selftest Zones Hall', VENUE_ZONE, ADMIN]);
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
    preg_match('/Default time zone\s+(\S+)/', words($info['body']), $php);
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
    preg_match('#id="zones-wait-for-upgrade">.*?href="/admin/upgrade"#s', $gapPage) === 1 && preg_match('#<select[^>]*id="formTimezone"[^>]*disabled#', $gapPage) === 1
        && preg_match('#<dt class="col-5">Time zone</dt>\s*<dd class="col-7">\s*' . preg_quote(EVENT_ZONE, '#') . '\s*</dd>#', $gapPage) === 1
        && $gapRow === ['UTC', 'Selftest zones renamed in the gap'] && str_contains($gapSaid, 'Its time zone was NOT changed') === true && str_contains($gapSaid, 'Run it at Admin, Upgrade') === true
        && $gapSetting === EVENT_ZONE && str_contains($gapRefusal, 'has not yet run their database upgrade: run it at Admin, Upgrade') === true && str_contains($gapRefusal, 'not a setting any more') === false
        && str_contains($gapList, 'Run the upgrade first (Admin, Upgrade)') === true
        && zoneOfOrg(1) === EVENT_ZONE && str_contains($afterPage, 'zones-wait-for-upgrade') === false && preg_match('#<select[^>]*id="formTimezone"[^>]*required#', $afterPage) === 1,
    'the notice and its link: ' . json_encode(preg_match('#id="zones-wait-for-upgrade">.*?href="/admin/upgrade"#s', $gapPage) === 1) . '; the box disabled: ' . json_encode(preg_match('#<select[^>]*id="formTimezone"[^>]*disabled#', $gapPage) === 1)
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
preg_match('#<select[^>]*name="timezone"[^>]*id="eventTimezone-new"[^>]*>(.*?)</select>#s', $formPage, $zoneBox);
ok(
    'W01 the event form offers a LIST of real zone names where there used to be a free-text box, and a new event starts on the organisation\'s own zone',
    isset($zoneBox[1]) === true && substr_count($zoneBox[1], '<option') >= 400 && str_contains($zoneBox[1], '<option value="' . ORG_ZONE . '" selected>') === true
        && preg_match('#<input[^>]*name="timezone"#', $formPage) === 0,
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
preg_match('#name="startDateTime"[^>]*value="([^"]*)"#s', $skippedForm, $shownStart);
preg_match('#name="endDateTime"[^>]*value="([^"]*)"#s', $skippedForm, $shownEnd);
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
$movedTo = momentOf(clockIn(27, '21:15:00'), EVENT_ZONE);
$answer  = req('', 'POST', '/api/events/update?id=' . $made['the events API, with a zone chosen'], (string) json_encode(['id' => $made['the events API, with a zone chosen'], 'eventID' => $made['the events API, with a zone chosen'], 'startDateTime' => gmdate('Y-m-d\TH:i:s\Z', $movedTo)]), ['bearer' => $apiKey, 'json' => true]);
$nowAt   = (string) (one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$made['the events API, with a zone chosen']])['startDateTime'] ?? '');
ok(
    'W09b the events API\'s update: a new start given as a moment is written as the reading of the EVENT\'s clock (' . EVENT_ZONE . '), not of the organisation\'s; the event keeps its zone',
    $nowAt === clockIn(27, '21:15:00') && zoneOfEvent($made['the events API, with a zone chosen']) === EVENT_ZONE,
    said($answer) . ' ' . substr($answer['body'], 0, 200) . '; stored ' . json_encode($nowAt) . ', looked for ' . clockIn(27, '21:15:00')
);
q('UPDATE tblEvents SET startDateTime = ? WHERE eventID = ?', 'si', [clockIn(27, '19:00:00'), $made['the events API, with a zone chosen']]); // back, for the readers below
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
// it is read in, not who may see it.
foreach ($made as $eventId) {
    q("UPDATE tblEvents SET status = 'published', isPublic = 1, submissionStatus = IF(submissionStatus = 'pending', 'approved', submissionStatus) WHERE eventID = ?", 'i', [$eventId]);
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
        said($page) . '; stored ' . $zone . '; "Timezone:" line: ' . (preg_match('/Timezone: \S+/', words($page['body']), $t) === 1 ? $t[0] : '(none)') . '; looked for ' . $offset
    );
    // The calendar feed (a personal subscription).
    $inFeed = preg_match('/BEGIN:VEVENT(?:(?!END:VEVENT).)*SUMMARY:' . preg_quote((string) $row['eventName'], '/') . '(?:(?!END:VEVENT).)*END:VEVENT/s', $feedText, $block) === 1 ? $block[0] : '';
    ok(
        'R the calendar feed, for an event made by ' . $label . ': DTSTART;TZID=' . $want . ':' . $local . ' and ' . $wantEnd,
        str_contains($inFeed, 'DTSTART;TZID=' . $want . ':' . $local) === true && str_contains($inFeed, $wantEnd) === true,
        $inFeed === '' ? 'the event is not in the feed (' . strlen($feedText) . ' bytes)' : (preg_match_all('/D(?:TSTART|TEND)[^\r\n]*/', $inFeed, $d) > 0 ? implode(' ', $d[0]) : 'no DTSTART')
            . '; the file declares ' . (preg_match('/X-WR-TIMEZONE:[^\r\n]*/', $feedText, $x) === 1 ? $x[0] : 'no zone')
    );
    // The export of the one event.
    $export = str_replace("\r\n ", '', req($adminJar, 'GET', '/calendar/export?id=' . $eventId)['body']);
    ok(
        'R the export, for an event made by ' . $label . ': DTSTART;TZID=' . $want . ':' . $local . ' and ' . $wantEnd,
        str_contains($export, 'DTSTART;TZID=' . $want . ':' . $local) === true && str_contains($export, $wantEnd) === true,
        preg_match('/BEGIN:VEVENT.*?(DTSTART[^\r\n]*).*?(DTEND[^\r\n]*)/s', $export, $d) === 1 ? $d[1] . ' ' . $d[2] : 'no event with a start and an end in ' . strlen($export) . ' bytes'
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
$feedBlock  = preg_match('/BEGIN:VEVENT(?:(?!END:VEVENT).)*SUMMARY:Selftest zones all day(?:(?!END:VEVENT).)*END:VEVENT/s', $feedAgain, $block) === 1 ? $block[0] : '';
$wantStart  = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $day);
$wantEnd    = 'DTEND;VALUE=DATE:' . $dayAfter;
$oneDayFeed   = preg_match('/BEGIN:VEVENT(?:(?!END:VEVENT).)*SUMMARY:Selftest zones one day(?:(?!END:VEVENT).)*END:VEVENT/s', $feedAgain, $block) === 1 ? $block[0] : '';
$oneDayExport = str_replace("\r\n ", '', req($adminJar, 'GET', '/calendar/export?id=960451')['body']);
$oneDayRight  = static fn (string $text): bool => str_contains($text, 'DTSTART;VALUE=DATE:' . str_replace('-', '', $oneDay)) === true && str_contains($text, 'DTEND;VALUE=DATE:' . $oneDayAfter) === true;
ok(
    'R an ALL-DAY event keeps its own date in the calendar feed and in the export: it starts on ' . $day . ' and ends before the day after (east of Greenwich, and in London in summer, it used to go out a day early); and so does one whose end is stored at the start of its own day',
    str_contains($feedBlock, $wantStart) === true && str_contains($feedBlock, $wantEnd) === true && str_contains($exportDay, $wantStart) === true && str_contains($exportDay, $wantEnd) === true
        && $oneDayRight($oneDayFeed) === true && $oneDayRight($oneDayExport) === true,
    'the feed: ' . (preg_match_all('/D(?:TSTART|TEND)[^\r\n]*/', $feedBlock, $d) > 0 ? implode(' ', $d[0]) : 'the event is not in it')
        . '; the export: ' . (preg_match('/BEGIN:VEVENT.*?(DTSTART[^\r\n]*).*?(DTEND[^\r\n]*)/s', $exportDay, $x) === 1 ? $x[1] . ' ' . $x[2] : 'no event')
        . '; the one-day event, feed: ' . (preg_match_all('/D(?:TSTART|TEND)[^\r\n]*/', $oneDayFeed, $d) > 0 ? implode(' ', $d[0]) : 'not in it')
        . ', export: ' . (preg_match('/BEGIN:VEVENT.*?(DTSTART[^\r\n]*).*?(DTEND[^\r\n]*)/s', $oneDayExport, $x) === 1 ? $x[1] . ' ' . $x[2] : 'no event') . '; looked for the end ' . $oneDayAfter
);
ok(
    'R the calendar feed and the export each declare the ORGANISATION\'s zone as the file\'s own (it was "Europe/London" for every customer, written into the code)',
    str_contains($feedAgain, 'X-WR-TIMEZONE:' . ORG_ZONE) === true && str_contains($feedAgain, 'TZID:' . ORG_ZONE) === true
        && str_contains($exportDay, 'X-WR-TIMEZONE:' . ORG_ZONE) === true && str_contains($feedAgain . $exportDay, 'Europe/London') === false,
    'the feed: ' . (preg_match('/X-WR-TIMEZONE:[^\r\n]*/', $feedAgain, $x) === 1 ? $x[0] : 'none') . '; the export: ' . (preg_match('/X-WR-TIMEZONE:[^\r\n]*/', $exportDay, $x) === 1 ? $x[0] : 'none')
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
    (preg_match('/X-WR-TIMEZONE:[^\r\n]*/', $otherFeed, $x) === 1 ? $x[0] : 'the file declares no zone') . '; the duty: '
        . (preg_match('/BEGIN:VEVENT(?:(?!END:VEVENT).)*SUMMARY:Duty(?:(?!END:VEVENT).)*END:VEVENT/s', $otherFeed, $b) === 1 && preg_match('/DTSTART[^\r\n]*/', $b[0], $d) === 1 ? $d[0] : 'not in the file (' . strlen($otherFeed) . ' bytes)')
);

// =============================================================================
// U. WHAT IS NOT A ZONE IS FOUND AND LISTED, NEVER CHANGED
// =============================================================================
head('U. Text that is not a zone: found, counted and listed for an administrator, and refused where it could be stored');
// ---- events -------------------------------------------------------------------
$listPage = req($adminJar, 'GET', '/calendar/manage')['body'];
preg_match('#<div class="alert alert-warning" role="alert" id="events-unknown-zone" data-count="(\d+)">(.*?)</div>#s', $listPage, $listed);
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
$listAfter = preg_match('#id="events-unknown-zone" data-count="(\d+)">(.*?)</div>#s', req($adminJar, 'GET', '/calendar/manage')['body'], $left) === 1 ? $left : ['', '', ''];
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
    preg_match('#data-zone-problem="in-doubt">(.*?)</span>#s', $orgs, $card) === 1 && str_contains(words($card[1]), 'Stored as "US/Eastern", which is not a time zone name the portal knows. UTC is used.') === true
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

    return preg_match('#id="events-marked-london" data-count="(\d+)" data-coming="(\d+)">(.*?)</div>#s', $html, $m) === 1 ? [$m[1], $m[2], $m[3]] : ['none', 'none', ''];
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
// Nobody to send to: every account but ONE is switched off while this part runs, so the job records WHEN each
// reminder is made and no message is attempted. The one member left is going only to the events about the
// day-before reminder's WORDS (further down). The mail route for the run is the SMTP mailbox with nothing
// filled in: an attempt then answers "not set up" and is written to the e-mail log with its subject, which is
// how those checks read what would have been sent. No connection is made, to anything.
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
// 🏁 The end
// =============================================================================
$errors = is_file($child . $sep . 'php-errors.log') === true ? trim((string) file_get_contents($child . $sep . 'php-errors.log')) : '';
$fatal  = preg_match_all('/PHP (Fatal error|Parse error|Warning|Deprecated).*$/m', $errors, $lines);
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
