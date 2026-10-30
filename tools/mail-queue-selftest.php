<?php
// Path: tools/mail-queue-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The mail queue, on the REAL portal — self-test ✉️⏳ (#592, part 3)
 * -----------------------------------------------------------------------------
 * WHAT THIS HOLDS IN PLACE
 * ------------------------
 * A message the portal cannot send at once is kept, tried again, and in the
 * end sent or marked as failed where an administrator can see it. It must
 * never be sent twice, never be sent late, and never be lost in silence.
 * Every one of those can go wrong without any page failing, so this file runs
 * the portal and looks.
 *
 * WHAT IT DOES
 * ------------
 * The same way as `tools/mail-pages-selftest.php`: it copies `web/` into a
 * NEW scratch folder with a throwaway connection file and encryption key,
 * starts PHP's built-in web server on it behind a small router that imitates
 * `.htaccess`, and starts the mail library's own STAND-IN mail servers
 * (`tools/maillib/tests/smtp-standin.php`), which record what they are sent
 * and send nothing anywhere. NO FILE OF THE PORTAL IS CHANGED IN THE COPY.
 *
 * Three more things it does that the mail pages test does not:
 *   - It runs this same file again as a CHILD process, on the scratch copy,
 *     to call the portal's own code directly: Mailer::send(), one run of the
 *     queue, a claim without the one-run-at-a-time lock. A child can be given
 *     a CLOCK (the database connection's time zone and its "now"), which is
 *     how the two clock-change nights are run on a made-up date.
 *   - It makes time pass by making every stored moment of its own rows OLDER
 *     by so many seconds, which is the same thing as the clock moving on.
 *   - It runs "Forgot password" through `php-cgi`, which is PHP as a web
 *     server runs it when it CANNOT let the browser go before the script
 *     ends. PHP's built-in server can, so it cannot show that case.
 *   - Every part STARTS with the scheduled job "running" (the moment its own
 *     page records is set to now: jobRunning()), because that is the state
 *     the queue was built for. A part about an installation with no job
 *     says so with noJob(). A run made by runQueue() records the job as the
 *     job's page does; later() makes that moment older with everything else.
 *
 * The parts are numbered as in the plan (Q1 to Q13), then five more (X1 to
 * X5) for what was decided after the plan was written:
 *
 *   Q12  The database: what a fresh install holds; migration 216 turns an
 *        older database into the same thing, twice over, adding no row the
 *        second time. (Q12B, later: a backup carries waiting mail, and a
 *        restore HOLDS it, so that what went after the backup is not sent a
 *        second time.)
 *   Q1   The server up: a reset goes at once and NO queue row is written.
 *   Q2   The server down: the reset is kept, urgent, with its link's own
 *        expiry; its stored body cannot be read and holds no part of the
 *        link; the next run delivers it and the link works.
 *   Q13  The person is shown the same thing whether the e-mail went, is
 *        queued, is held, or the address has no account.
 *   Q10  The job: no token, a wrong one, the right one (in the address and in
 *        a header); a second run while one holds the lock is turned away and
 *        says so; the token is in nothing the portal recorded.
 *   Q6   Retries: the stated waits, the stated count, and never for a message
 *        the mail server refused outright.
 *   Q7   A message past its use-by moment is never sent, by any of the three
 *        places that could send it, and its body is removed.
 *   Q8   Attachments: copied when queued, sent from the copy after the
 *        original is deleted, removed with the row; a missing copy fails the
 *        message; a stray file is cleaned up.
 *   Q5   A run killed at three points, and what the next run does.
 *   Q4   Four runs at once, without the lock, deliver 200 messages once each.
 *   Q3   A broadcast to 300 with a limit of 100 an hour.
 *   Q9   Erasure and "a copy of my data".
 *   Q11  Both clock-change nights, in two zones.
 *   X1   The four numbers, per organisation, with the installation's as the
 *        default, and a bad value falling back and saying so.
 *   X2   A refused sign-in across runs of the job.
 *   X3   "Forgot password" on a web server that cannot answer first.
 *   X4   A broadcast pressed twice.
 *   X5   A certificate that fails: mail is held, and goes by itself.
 *   Then nine parts for what the independent check of 13 October 2026 found
 *   (F for "fix"; the letters in brackets are that check's own):
 *   F1   With NO scheduled job the portal does as it did before the queue:
 *        a reset arrives in the request on `php-cgi`, a broadcast to 60
 *        reaches all 60, a page tries the mail server again after a refused
 *        sign-in's wait, the health line is red when mail waits, and the
 *        job's address works on a pre-release copy. Only the job's own page
 *        records that the job ran. (H1, M1, L4)
 *   F2   A record says "waiting", not "sent", for mail that was only kept,
 *        and is corrected when the message goes or fails: a newsletter, a
 *        giving statement, a reminder job's count. (M2)
 *   F3   A use-by moment: an event reminder is never sent after its event
 *        has started, and a time on a clock becomes the right moment in UTC
 *        on both clock-change days. (M3)
 *   F4   Three resets asked for while the mail server is down: one arrives. (L1)
 *   F5   A broadcast whose request is ended part way keeps NOTHING. (L2)
 *   F6   A message for more people than a whole hour allows is refused at
 *        once, and such rows do not block the queue. (L3)
 *   F7   A "no" to the sender does not throw the message away; a use-by
 *        moment that cannot be read stops a message being kept. (L4, L5)
 *   F8   One organisation's own value for the four numbers, through the
 *        Settings page. (M6)
 *   F9   An erasure while a run of the job is working. (M5)
 *   F10  An erasure that cannot have the run lock at all: the person is out
 *        of the queue all the same, and running it again finishes it.
 *   (Since the second check, 13 October 2026: every event is made as the
 *   event form makes it, in a zone that is not London's; F1 holds the 15
 *   minutes themselves, twelve pages at once, and the other scheduled jobs
 *   on a pre-release copy; F3 is about the EVENT's own time zone.)
 *   (M4, a restored backup, is in Q12B; M5's reason text is in Q9.)
 *   Last Nothing the job and the pages printed, logged or stored holds a
 *        message body, a reset link or the mailbox's password.
 *
 * SAFETY
 * ------
 * - It writes rows AND RUNS THE MAIL JOB, which works through the whole
 *   queue, so it REFUSES unless the database's name starts with `selftest_`
 *   and the queue holds no row that is not this test's own.
 * - Every account it makes is numbered 931000 to 931999; every record it
 *   causes carries a 198.51.100.x address, one of those numbers, or an
 *   address at `mailqueue.selftest.invalid`. They are removed at the START
 *   (in case an earlier run was interrupted) and at the END.
 * - Migration 216 is run against COPIES of the tables it touches, made for
 *   the run and dropped afterwards, never against the real ones.
 * - It changes installation-wide settings for the run and puts each back
 *   exactly. Do not run it at the same moment as another test on the same
 *   database.
 * - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *   temporary folder), deleted at the end only if it still holds this run's
 *   marker. `web/_auth_keys` is never read or copied.
 * - Servers are started with their commands as lists (no shell) and each is
 *   stopped by its own process number, never by a name. Every answer must
 *   carry this run's header.
 * - NO E-MAIL LEAVES THIS MACHINE: the only mail servers are the stand-ins,
 *   on 127.0.0.1.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - That a real mail server accepts a message, or that one arrives.
 * - That a real web server ends a request where this test kills a process.
 *   The kills are of a child process running one run of the queue.
 * - The exact moment a kill lands. "Just after the mail server took it" is
 *   made certain by holding the row locked from this side while the run is
 *   stopped; "just before" is taken while a slow stand-in is still talking.
 * - That time passing on a real clock behaves as rows made older do. The
 *   two are the same to every statement the queue runs, because all of them
 *   compare stored moments with the database's clock; nothing here waits a
 *   real hour.
 * - Apache, a real proxy or https; MariaDB (run on MySQL 8.0.36 and 8.4.11).
 * - How anything looks: that needs a browser.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/mail-queue-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9585). SELFTEST_ONLY=Q5,Q4 runs
 *         only those parts, for looking at one thing: such a run never exits
 *         0. The database must already hold `web/_sql/full_schema.sql` and
 *         know time zones by name (Q11). Needs PHP's curl, openssl and sodium
 *         extensions, `proc_open()`, `php-cgi` beside this PHP (X3) and
 *         Python 3 (Q9, to run the coverage check).
 * Exit:   0 only when every check ran and passed and the last line says the
 *         run reached its end; 2 for a run of some parts only that passed;
 *         1 otherwise, including every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/592
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 159;

/** What a child process prints before its findings, so they can be found in whatever else it printed. */
const RESULT_MARK = '@@mail-queue-selftest@@';

// =============================================================================
// 🧒 THE CHILD PROCESS
// =============================================================================
// This same file, run as `php mail-queue-selftest.php --child <scratch web
// folder>`, to call the portal's own code directly. It runs at the TOP LEVEL,
// not inside a function, because the portal's start-up file sets $mysqli and
// $SETTINGS as globals. What to do arrives in the SELFTEST_JOB variable.
if (($argv[1] ?? '') === '--child') {
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/';
    require (string) ($argv[2] ?? '') . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    $job = (array) json_decode((string) getenv('SELFTEST_JOB'), true);
    $db  = \Portal\Core\App::db();
    $out = ['threw' => ''];
    try {
        // 🕰️ A made-up clock: PHP and the database connection are both put in
        //    a zone that changes, and the connection's "now" is fixed.
        if (isset($job['zone']) === true) {
            date_default_timezone_set((string) $job['zone']);
            $zone = $db->prepare('SET time_zone = ?');
            $zone->bind_param('s', $job['zone']);
            $zone->execute();
            $zone->close();
        }
        if (isset($job['timestamp']) === true) {
            $db->query('SET timestamp = ' . (int) $job['timestamp']);
        }
        // 🚦 Wait for the starting signal, so that several children begin together.
        if (isset($job['waitFor']) === true) {
            $until = microtime(true) + 30;
            while (is_file((string) $job['waitFor']) === false && microtime(true) < $until) {
                usleep(1000);
            }
        }
        switch ((string) $job['task']) {
            case 'send': // several messages through Mailer::send(), in ONE process
                foreach ((array) $job['messages'] as $m) {
                    $sent          = \Portal\Core\Mailer::send($m['to'], (string) $m['subject'], (string) $m['body'], (array) ($m['files'] ?? []), (array) ($m['options'] ?? []));
                    $out['each'][] = [$sent, \Portal\Core\Mailer::lastOutcome(), \Portal\Core\Mailer::lastQueueId()];
                }
                break;
            case 'add': // rows straight into the queue
                foreach ((array) $job['messages'] as $m) {
                    $out['ids'][] = \Portal\Core\MailQueue::add($db, $m);
                }
                break;
            case 'run': // one run of the queue, as the job makes it
                // The job's own page records that the job ran, then runs
                // (web/_apps/cron/mail-queue.php). `asPage` true is a run made
                // by a page instead, which must NOT record it.
                if (($job['asPage'] ?? false) !== true) {
                    \Portal\Core\MailRoutes::jobRan($db);
                }
                $out['numbers'] = \Portal\Core\MailQueue::run($db, (int) ($job['max'] ?? 100), (float) ($job['seconds'] ?? 20));
                break;
            case 'newsletter': // one pass of a newsletter, as its page makes it
                $out['result'] = \Portal\Core\Newsletter::dispatch((int) $job['newsletterId'], 1);
                break;
            case 'statement': // one giving statement, as the statements page sends it
                $out['result']  = \Portal\Core\Giving::sendStatementEmail((array) $job['logRow']);
                $out['waiting'] = \Portal\Core\Mailer::lastIsWaiting();
                break;
            case 'claim-loop': // a run WITHOUT the one-run-at-a-time lock
                $out['handled'] = [];
                while (true) {
                    $claim = \Portal\Core\MailQueue::claim($db, 20);
                    if ($claim['rows'] === []) {
                        break;
                    }
                    foreach ($claim['rows'] as $row) {
                        $out['handled'][] = \Portal\Core\MailQueue::handle($db, $row, $claim['token']);
                    }
                }
                break;
            case 'claim-then': // claim, change something, then hand over
                $claim          = \Portal\Core\MailQueue::claim($db, (int) $job['limit']);
                $out['claimed'] = count($claim['rows']);
                foreach ((array) ($job['sqlAfterClaim'] ?? []) as $sql) {
                    $db->query((string) $sql);
                }
                $out['handled'] = [];
                foreach ($claim['rows'] as $row) {
                    $out['handled'][] = \Portal\Core\MailQueue::handle($db, $row, $claim['token']);
                }
                break;
            case 'numbers':
                $out['numbers']  = \Portal\Core\MailQueue::numbers($db, $job['siteId'] === null ? null : (int) $job['siteId']);
                $out['problems'] = \Portal\Core\MailQueue::numberProblems($db);
                break;
            case 'facts': // small things only the portal's own code can say
                $out['canAnswerFirst'] = \Portal\Core\MailQueue::pageCanAnswerFirst();
                $out['shown']          = array_map(static fn (array $m): string => \Portal\Core\MailQueue::shown((string) $m[0], (string) $m[1]), (array) ($job['moments'] ?? []));
                $out['roomUrgent']     = \Portal\Core\MailRoutes::roomLeft($db, true);
                $out['roomOther']      = \Portal\Core\MailRoutes::roomLeft($db, false);
                $out['waits']          = array_map(static fn (array $w): ?int => \Portal\Core\MailQueue::waitAfter((int) $w[0], (int) $w[1]), (array) ($job['waits'] ?? []));
                $out['health']         = \Portal\Core\MailQueue::health($db, (string) ($job['healthZone'] ?? 'UTC'), true);
                $out['useBy']          = array_map(static fn (array $m): string => \Portal\Core\MailQueue::useBy((string) $m[0], (string) $m[1], (string) ($m[2] ?? '')), (array) ($job['useBy'] ?? []));
                $out['jobIsRunning']   = \Portal\Core\MailQueue::jobIsRunning($db);
                break;
            case 'erase':
                $out['result'] = \Portal\Core\GdprEraser::execute((int) $job['requestId'], (int) $job['userId'], (int) $job['adminId']);
                break;
            case 'snapshot':
                $out['result'] = (new \Portal\Core\DbBackup($db))->snapshot('mail queue self-test');
                break;
            case 'restore':
                $out['result'] = (new \Portal\Core\DbBackup($db))->restoreAll((string) $job['directory'], ($job['dryRun'] ?? false) === true);
                break;
            default:
                $out['threw'] = 'the child was given a task it does not know';
        }
    } catch (Throwable $thrown) {
        $out['threw'] = get_class($thrown) . ': ' . $thrown->getMessage();
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
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows and runs the mail job");
}
foreach (['curl_init', 'proc_open', 'openssl_pkey_new', 'sodium_crypto_secretbox'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
$phpCgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi';
if (is_executable($phpCgi) === false) {
    refuse("there is no php-cgi beside this PHP ({$phpCgi}). Part X3 runs \"Forgot password\" the way a web server that cannot answer first does, and cannot be run without it");
}
$python = trim((string) @shell_exec('command -v python3 2>/dev/null'));
if ($python === '') {
    refuse('Python 3 was not found. Part Q9 runs tools/audit-checks/check_personal_data_coverage.py and cannot be run without it');
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
$port    = ($portRaw === false || $portRaw === '') ? 9585 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}
/** The parts to run; empty means all of them. */
$only = array_values(array_filter(array_map('trim', explode(',', strtoupper((string) getenv('SELFTEST_ONLY'))))));

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
// This test's own connection works in UTC, so that a moment it reads back is
// the moment as stored. (The portal's connections are left as the portal
// makes them; a child is given a zone only where a part says so.)
$db->query("SET time_zone = '+00:00'");

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

/** Start a part. False when SELFTEST_ONLY leaves it out. */
function part(string $code, string $text): bool
{
    global $only;
    if ($only !== [] && in_array($code, $only, true) === false) {
        return false;
    }
    echo "\n=== " . $code . '. ' . $text . " ===\n";
    startClean();

    return true;
}

/** A line that tells the reader something and is NOT a check. */
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

// =============================================================================
// 🗺️ The made-up world
// =============================================================================
/** The accounts with names. The 300 people of the big broadcast are numbered from CROWD_FROM. */
const PEOPLE = [
    'root'   => 931001, // a global administrator, who sends the broadcasts
    'member' => 931011, // an ordinary member, who asks for reset links
    'erased' => 931012, // the person who is erased in Q9
    'other'  => 931013, // somebody else, whose mail must be left alone
    'third'  => 931014,
    'fourth' => 931015,
    'raced'    => 931016, // the person erased in F9 while a run of the job is working
    'locked'   => 931018, // the person erased in F10 while a run holds the lock for good
    'orgadmin' => 931017, // an administrator of organisation 1 only, for the Settings page in F8
];
const CROWD_FROM  = 931100;
const CROWD       = 300;
const EVENT_BIG   = 931500; // the event the 300 are going to
const EVENT_SMALL = 931501; // an event three people are going to
const EVENT_SIXTY = 931502; // an event 60 of the crowd are going to (F1, F5)
const EVENT_SOON  = 931503; // an event the reminder job takes to start in an hour, in New York's zone (F3)
const EVENT_TODAY = 931504; // an event "today" by the database's date, for the "day of" summary (F3)
const EVENT_EAST  = 931505; // an event that really starts in an hour, in Sydney's zone (F3)
const EVENT_ODD   = 931506; // an event whose zone box holds something that is not a zone (F3)
const EVENT_TOMORROW = 931507; // an event the reminder job takes to start in 24 hours, in New York's zone (F3)
const EVENT_LATE  = 931508; // an event whose day-before reminder is made by a LATE call, on the event's own day (F3)
const EVENT_CHANGE = 931510; // four events, one on each clock-change day, numbered from here (F3)
const REQUEST_ID  = 931900; // the erasure request of Q9
const REQUEST_RACE = 931901; // the erasure request of F9
const REQUEST_LOCKED = 931902; // the erasure request of F10
const NEWSLETTER  = 931600; // the newsletter of F2
const STATEMENT   = 931700; // the giving statement rows of F2 are numbered from here
/** How this test names itself to the portal, so the records it causes can be found and removed. */
const AGENT = 'mail-queue-selftest';
const DOMAIN = 'mailqueue.selftest.invalid';
const SENDER = 'office@' . DOMAIN;
const MAILBOX_USER = 'selftest-queue-mailbox';
/** The mail server's name. The stand-in's certificates are made for this name. */
const MAIL_HOST = 'localhost';
/** The lengths the queue is meant to wait, in seconds, as the owner decided them. Written out here on purpose: the test must not read them from the code it tests. */
const WAITS_OTHER  = [300, 900, 3600, 10800, 21600, 43200];
const WAITS_URGENT = [60, 300, 900, 1800];

/** A made-up visitor address. 198.51.100.0/24 is kept for examples (RFC 5737), so it is nobody's. */
function ip(int $last): string
{
    return '198.51.100.' . $last;
}

function login(string $who): string
{
    return 'mqs-' . $who;
}

function mailOf(string $who): string
{
    return $who . '@' . DOMAIN;
}

/** The address of one of the 300. */
function crowd(int $n): string
{
    return 'crowd' . $n . '@' . DOMAIN;
}

/** The limiter's bucket for one visitor address's requests for a reset link. */
function bucket(string $kind, string $ip): string
{
    return 'pwreset:' . $kind . ':' . hash('sha256', $ip);
}

/** Everything this test leaves in the queue, the send log and the route's record. */
function removeMail(): void
{
    // By subject too. The reminder job's "day of" summary (F3) goes to every administrator of the installation,
    // who need not be one of this test's people; and an erasure (Q9, F9, F10) turns an address in the send log
    // into "[erased]", and such a line would otherwise be left behind, counted against the hourly limit of
    // whichever part ran next.
    q("DELETE FROM tblMailQueue WHERE toRecipients LIKE '%" . DOMAIN . "%' OR toRecipients = '' OR subject LIKE '%Selftest queue event%'");
    q("DELETE FROM tblEmailLog WHERE toRecipients LIKE '%" . DOMAIN . "%' OR toRecipients = '' OR subject LIKE 'Q9 %' OR subject LIKE 'F9 %' OR subject LIKE 'F10 %' OR subject LIKE '%Selftest queue event%' "
        . "OR subject LIKE '%giving statement%' AND toRecipients LIKE '%[erased]%'");
    q('DELETE FROM tblMailRouteState');
    q('DELETE FROM tblEventBroadcasts WHERE eventID BETWEEN 931500 AND 931599');
    q('DELETE FROM tblEventReminderLog WHERE eventID BETWEEN 931500 AND 931599');
    q('DELETE FROM tblNewsletter WHERE newsletterID = ?', 'i', [NEWSLETTER]); // its recipients go with it
    q('DELETE FROM tblGivingStatementLog WHERE logID BETWEEN ? AND ?', 'ii', [STATEMENT, STATEMENT + 99]);
    q('DELETE FROM tblPasswordResets WHERE userID BETWEEN 931000 AND 931999');
    for ($last = 1; $last <= 254; $last++) {
        q('DELETE FROM tblApiRateLimits WHERE bucket IN (?, ?)', 'ss', [bucket('ask', ip($last)), bucket('use', ip($last))]);
    }
}

function removeWorld(): void
{
    removeMail();
    q('DELETE FROM tblErasureAudit WHERE requestID IN (?, ?, ?)', 'iii', [REQUEST_ID, REQUEST_RACE, REQUEST_LOCKED]);
    q('DELETE FROM tblErasureRequest WHERE requestID IN (?, ?, ?)', 'iii', [REQUEST_ID, REQUEST_RACE, REQUEST_LOCKED]);
    q("DELETE FROM tblActivityLogs WHERE userID BETWEEN 931000 AND 931999 OR visitorIP LIKE '198.51.100.%' OR userAgent = ?", 's', [AGENT]);
    q("DELETE FROM tblErrors WHERE userID BETWEEN 931000 AND 931999 OR visitorIP LIKE '198.51.100.%' OR userAgent = ?", 's', [AGENT]);
    q('DELETE FROM tblEventRSVPs WHERE eventID BETWEEN 931500 AND 931599');
    q('DELETE FROM tblEventRSVPInvites WHERE eventID BETWEEN 931500 AND 931599');
    q('DELETE FROM tblEvents WHERE eventID BETWEEN 931500 AND 931599');
    // One organisation's own rows for the four numbers, which X1 plants.
    q("DELETE FROM tblSettings WHERE siteID = 1 AND settingKey LIKE 'mail.queue.%'");
    // An erased donor who has a giving statement (F9) has their name KEPT for tax reasons, in a table whose
    // link to the account refuses the account's removal. It goes first.
    q('DELETE FROM tblGivingKeptDonors WHERE donorID BETWEEN 931000 AND 931999');
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 931000 AND 931999');
    q('DELETE FROM tblUserSites WHERE userID BETWEEN 931000 AND 931999');
    q('DELETE FROM tblUsers WHERE userID BETWEEN 931000 AND 931999');
    dropCopies();
}

// =============================================================================
// 🧰 The servers, and one request
// =============================================================================
$state = [
    'proc' => null, 'mailProc' => null, 'mailPipe' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [],
    'started' => false, 'cleaned' => false, 'read' => [], 'ports' => [], 'haystack' => '', 'kids' => [], 'baseline' => [],
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

/** Stop one process by the handle proc_open() gave — never by a name. */
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
    global $state, $db;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    foreach ($state['kids'] as &$kid) {
        stopServer($kid);
    }
    unset($kid);
    stopServer($state['proc']);
    // The stand-in mail servers stop themselves when this file appears.
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
        // A lock or a transaction this test still holds would outlive it otherwise.
        $db->query('ROLLBACK');
        $db->query('DO RELEASE_ALL_LOCKS()');
        if (isset($state['siteZone']) === true) {
            q('UPDATE tblSites SET timezone = ? WHERE siteID = 1', 's', [(string) $state['siteZone']]);
        }
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
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.mail-queue-selftest-owner') === $state['token']) {
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

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else and
 * is returned as status -1, which no check expects. Every answer is also
 * added to the text that is searched at the end.
 *
 * @param array<string,mixed>|null $post
 * @param list<string>             $extra More request headers.
 *
 * @return array{status:int, location:string, body:string, head:string, ms:int}
 */
function req(string $jar, string $method, string $path, ?array $post = null, string $ip = '', array $extra = []): array
{
    global $port, $state;
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = array_merge(['Expect:', 'User-Agent: ' . AGENT], $extra);
    if ($ip !== '') {
        $headers[] = 'X-Forwarded-For: ' . $ip;
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 170,
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
    $began = microtime(true);
    $raw   = curl_exec($handle);
    $ms    = (int) round((microtime(true) - $began) * 1000);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle), 'head' => '', 'ms' => $ms];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Mail-Queue-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => '', 'ms' => $ms];
    }
    $state['haystack'] .= "\n" . $raw;

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
        'ms'       => $ms,
    ];
}

/** The form token on a page. */
function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** A form token for a signed-in browser, from whichever of its pages carries one. */
function anyToken(string $jar, string $ip): string
{
    foreach (['/account', '/settings', '/'] as $path) {
        $found = token(req($jar, 'GET', $path, null, $ip)['body']);
        if ($found !== '') {
            return $found;
        }
    }

    return '';
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
function signIn(string $who, string $password, string $ip): string
{
    $jar    = newJar();
    $form   = req($jar, 'GET', '/login', null, $ip);
    $answer = req($jar, 'POST', '/login', ['csrf_token' => token($form['body']), 'username' => login($who), 'password' => $password], $ip);
    if ($answer['status'] !== 302 || $answer['location'] !== '/') {
        refuse($who . ' could not sign in (HTTP ' . $answer['status'] . ' to ' . $answer['location'] . ')');
    }

    return $jar;
}

/**
 * Wait until the portal server has finished what it is doing. "Forgot
 * password" answers first and works afterwards; the server this test starts
 * is ONE process, so a second request is served only once the first script
 * has ended. This sends that second request, which the router refuses before
 * any portal code runs.
 */
function settle(): void
{
    req('', 'GET', '/.selftest-settle');
}

/** Ask for a reset link through the real page, on the built-in server (which answers first). */
function askForLink(string $ip, string $email): array
{
    $jar  = newJar();
    $form = req($jar, 'GET', '/forgot-password', null, $ip);
    $sent = req($jar, 'POST', '/forgot-password/save', ['csrf_token' => token($form['body']), 'email' => $email], $ip);
    settle();

    return $sent + ['jar' => $jar];
}

/** Everything about an answer that a stranger could compare, with the parts that differ on every request taken out. */
/**
 * 🎭 TAKE THE ONE-OFF SCRIPT NUMBER OUT BEFORE COMPARING TWO ANSWERS.
 *
 * Since #625 every answer carries a fresh `nonce` -- 32 hex characters from
 * App::cspNonce() -- in its Content-Security-Policy header and on every script
 * written into the page. It is MEANT to differ on every answer; that is what
 * makes it worth having. So two answers to the same request are never byte for
 * byte alike any more, and the checks that ask "is the person shown the same
 * thing whatever became of the e-mail?" began failing on the difference rather
 * than on anything a person could see. Those checks exist to prove the portal
 * does not reveal whether an address has an account, so a false failure there
 * is worse than noise: it hides the thing being guarded.
 *
 * The pattern is deliberately narrow -- only `nonce-<hex>` and
 * `nonce="<hex>"` -- rather than blanking every 32-character hex string,
 * which would also blank an identifier or a checksum that a check may want to
 * compare.
 */
function withoutTheNonce(string $text): string
{
    $text = (string) preg_replace('/nonce-[0-9a-f]{32}/', 'nonce-(one-off)', $text);

    return (string) preg_replace('/nonce="[0-9a-f]{32}"/', 'nonce="(one-off)"', $text);
}

function shape(array $answer): string
{
    $lines = array_filter((array) preg_split('/\R/', trim($answer['head'])), static fn (string $line): bool => stripos($line, 'Date:') !== 0);
    $lines = array_map(static fn (string $line): string => stripos($line, 'Set-Cookie:') === 0 ? 'Set-Cookie: (a cookie)' : $line, $lines);
    sort($lines);

    return withoutTheNonce(implode("\n", $lines) . "\n\n" . trim($answer['body']));
}

/** The page a person lands on after asking for a link, with its form token and its one-off script number taken out. */
function landing(array $sent, string $ip): string
{
    $page = req((string) $sent['jar'], 'GET', $sent['location'] === '' ? '/forgot-password' : $sent['location'], null, $ip);

    return $page['status'] . "\n" . withoutTheNonce((string) preg_replace('/[0-9a-f]{64}/', '(token)', $page['body']));
}

/** How many records of one kind one visitor address has in the activity log. */
function records(string $ip, string $type): int
{
    return (int) one('SELECT COUNT(*) AS n FROM tblActivityLogs WHERE visitorIP = ? AND activityType = ?', 'ss', [$ip, $type])['n'];
}

/**
 * The same page through `php-cgi`: PHP as a web server runs it when it holds
 * the whole answer back until the script has ended. Returns the answer as the
 * web server would be given it, and how long the whole script took.
 *
 * @param array<string,string> $envMore Settings of the request to change:
 *        `PORTAL_ENV` => 'beta' asks for the page as a pre-release copy serves it.
 *
 * @return array{status:int, location:string, head:string, body:string, ms:int, cookie:string}
 */
function cgi(string $method, string $path, array $post, string $cookie, string $ip, array $envMore = []): array
{
    global $state;
    $began   = microtime(true);
    $started = cgiOpen($method, $path, $post, $cookie, $ip, $envMore);
    if ($started === null) {
        return ['status' => 0, 'location' => '', 'head' => '', 'body' => 'php-cgi could not be started', 'ms' => 0, 'cookie' => ''];
    }
    $raw = (string) stream_get_contents($started['out']);
    fclose($started['out']);
    proc_close($started['proc']);
    $ms = (int) round((microtime(true) - $began) * 1000);
    $state['haystack'] .= "\n" . $raw;
    [$head, $rest] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');

    return [
        'status'   => preg_match('/^Status:\s*(\d+)/mi', $head, $s) === 1 ? (int) $s[1] : 200,
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'head'     => $head,
        'body'     => $rest,
        'ms'       => $ms,
        'cookie'   => preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $head, $c) === 1 ? $c[1] : '',
    ];
}

/**
 * Start `php-cgi` on one request and do NOT wait for it: cgi() waits, and F5
 * ends the process part way, as a web server ends a request that runs too long.
 *
 * @return array{proc: mixed, out: mixed}|null Null when it could not be started.
 */
function cgiOpen(string $method, string $path, array $post, string $cookie, string $ip, array $envMore = []): ?array
{
    global $state, $phpCgi, $port;
    $child = (string) $state['child'];
    $sep   = DIRECTORY_SEPARATOR;
    $body  = $method === 'POST' ? http_build_query($post) : '';
    $env   = $envMore + [
        'REDIRECT_STATUS' => '200', 'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REQUEST_METHOD' => $method,
        'SCRIPT_FILENAME' => $child . $sep . 'web' . $sep . 'public_html' . $sep . 'index.php', 'SCRIPT_NAME' => '/index.php',
        'DOCUMENT_ROOT' => $child . $sep . 'web' . $sep . 'public_html', 'REQUEST_URI' => $path, 'QUERY_STRING' => (string) parse_url($path, PHP_URL_QUERY),
        'SERVER_NAME' => '127.0.0.1', 'SERVER_PORT' => (string) $port, 'HTTP_HOST' => '127.0.0.1:' . $port, 'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => $ip, 'HTTP_USER_AGENT' => AGENT, 'HTTP_COOKIE' => $cookie, 'PORTAL_ENV' => 'prod',
        'CONTENT_TYPE' => $method === 'POST' ? 'application/x-www-form-urlencoded' : '', 'CONTENT_LENGTH' => (string) strlen($body),
        'PATH' => (string) getenv('PATH'),
    ];
    $process = proc_open(
        [$phpCgi, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
            '-d', 'display_errors=0', '-d', 'max_execution_time=180', '-d', 'openssl.cafile=' . $child . $sep . 'standin' . $sep . 'certs' . $sep . 'ca.pem'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $child . $sep . 'server-output.log', 'a']],
        $pipes,
        $child . $sep . 'web' . $sep . 'public_html',
        $env
    );
    if (is_resource($process) === false) {
        return null;
    }
    fwrite($pipes[0], $body);
    fclose($pipes[0]);

    return ['proc' => $process, 'out' => $pipes[1]];
}

// -----------------------------------------------------------------------------
// 🎭 The stand-in mail servers
// -----------------------------------------------------------------------------

/**
 * Everything one stand-in server has seen, once every connection to it has
 * ended: [connection number, 1 if encryption was on, kind, the rest].
 *
 * @return list<array{0:int,1:int,2:string,3:string}>
 */
function saw(string $scenario, bool $waitForClose = true): array
{
    global $state;
    $file   = $state['child'] . DIRECTORY_SEPARATOR . 'standin' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . $scenario . '.log';
    $events = [];
    for ($tries = 0; $tries < 150; $tries++) {
        $events = [];
        $open   = 0;
        foreach (is_file($file) === true ? (array) file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $parts    = explode(' ', (string) $line, 4);
            $events[] = [(int) $parts[0], (int) ($parts[1] ?? 0), $parts[2] ?? '', stripcslashes($parts[3] ?? '')];
            $open    += ($parts[2] ?? '') === 'connect' ? 1 : (($parts[2] ?? '') === 'close' ? -1 : 0);
        }
        if ($open <= 0 || $waitForClose === false) {
            break;
        }
        usleep(100000);
    }

    return $events;
}

/**
 * What one stand-in server has seen SINCE THE LAST CALL for it.
 *
 * @return array{connections:int, signIns:int, messages:list<array{from:string, to:list<string>, id:string, subject:string, head:string, html:string, text:string, raw:string}>}
 */
function mailSince(string $scenario): array
{
    global $state;
    $events = saw($scenario);
    $new    = array_slice($events, $state['read'][$scenario] ?? 0);
    $state['read'][$scenario] = count($events);

    $out  = ['connections' => 0, 'signIns' => 0, 'messages' => []];
    $from = [];
    $to   = [];
    foreach ($new as [$connection, $encrypted, $kind, $rest]) {
        if ($kind === 'connect') {
            $out['connections']++;
        } elseif ($kind === 'auth') {
            $out['signIns']++;
        } elseif ($kind === 'line' && stripos($rest, 'MAIL FROM:<') === 0) {
            $from[$connection] = substr($rest, 11, (int) strpos($rest, '>') - 11);
            $to[$connection]   = [];
        } elseif ($kind === 'line' && stripos($rest, 'RCPT TO:<') === 0) {
            $to[$connection][] = substr($rest, 9, (int) strpos($rest, '>') - 9);
        } elseif ($kind === 'body' && preg_match('/complete=1 file=(\S+)/', $rest, $m) === 1) {
            $raw   = (string) file_get_contents($state['child'] . DIRECTORY_SEPARATOR . 'standin' . DIRECTORY_SEPARATOR . $m[1]);
            $head  = (string) strstr($raw, "\r\n\r\n", true);
            $parts = [];
            foreach (['text/plain' => 'text', 'text/html' => 'html'] as $type => $name) {
                $parts[$name] = preg_match('#Content-Type: ' . preg_quote($type, '#') . '[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n--#s', $raw, $p) === 1
                    ? (string) base64_decode((string) preg_replace('/\s+/', '', $p[1]))
                    : '';
            }
            $out['messages'][] = [
                'from' => $from[$connection] ?? '', 'to' => $to[$connection] ?? [],
                'id' => preg_match('/^Message-ID:\s*(<[^>]+>)/mi', $head, $i) === 1 ? $i[1] : '',
                'subject' => preg_match('/^Subject:\s*(.*)$/mi', $head, $j) === 1 ? trim($j[1]) : '',
                'head' => $head, 'html' => $parts['html'], 'text' => $parts['text'], 'raw' => $raw,
            ];
        }
    }

    return $out;
}

/** Point the portal's SMTP settings at one stand-in server. */
function useServer(string $scenario): void
{
    global $state;
    setting('mail.smtp.port', (string) $state['ports'][$scenario]);
}

/** A port on which nothing is listening: a mail server that is down. */
function closedPort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $free   = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    return $free;
}

/** Point the portal at a mail server that is down. */
function serverDown(): void
{
    setting('mail.smtp.port', (string) closedPort());
}

/**
 * Start this file again as a child, on the scratch copy, and do not wait.
 *
 * @param array<string,mixed> $job See the child at the top of this file.
 *
 * @return array{proc: mixed, pipe: mixed, pid: int}
 */
function startChild(array $job): array
{
    global $state;
    $child = (string) $state['child'];
    $sep   = DIRECTORY_SEPARATOR;
    $env   = getenv();
    $env['PORTAL_ENV']   = 'prod';
    $env['SELFTEST_JOB'] = (string) json_encode($job);
    $process = proc_open(
        [PHP_BINARY, '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'max_execution_time=0',
            '-d', 'openssl.cafile=' . $child . $sep . 'standin' . $sep . 'certs' . $sep . 'ca.pem',
            '-d', 'session.save_path=' . $child . $sep . 'sessions', __FILE__, '--child', $child . $sep . 'web'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $child,
        $env
    );
    if (is_resource($process) === false) {
        refuse('a child process could not be started');
    }
    $state['kids'][] = $process;

    return ['proc' => $process, 'pipe' => $pipes[1], 'pid' => (int) proc_get_status($process)['pid']];
}

/**
 * Wait for a child and read what it found.
 *
 * @return array<string,mixed> Its findings, with `output` (everything it printed). `threw` is '' when nothing was thrown.
 */
function finishChild(array $kid): array
{
    global $state;
    $output = (string) stream_get_contents($kid['pipe']);
    fclose($kid['pipe']);
    proc_close($kid['proc']);
    $state['kids'] = array_values(array_filter($state['kids'], static fn (mixed $p): bool => $p !== $kid['proc']));
    $state['haystack'] .= "\n" . $output;
    $at    = strrpos($output, RESULT_MARK);
    $found = $at === false ? null : json_decode((string) strtok(substr($output, $at + strlen(RESULT_MARK)), "\n"), true);

    return (is_array($found) === true ? $found : ['threw' => 'the child printed no result']) + ['output' => $output];
}

/** Run one child to its end. */
function child(array $job): array
{
    return finishChild(startChild($job));
}

/** One message for the child's `send` task. */
function msg(array|string $to, string $subject, string $body = 'A message.', array $options = [], array $files = []): array
{
    return ['to' => $to, 'subject' => $subject, 'body' => $body, 'options' => $options, 'files' => $files];
}

/** One row for the child's `add` task: straight into the queue. */
function row(array $to, string $subject, array $more = []): array
{
    return $more + [
        'siteId' => 1, 'priority' => 5, 'to' => $to, 'subject' => $subject, 'html' => '<p>' . $subject . '</p>', 'text' => $subject,
        'files' => [], 'expiresAt' => null, 'repeatSafe' => false,
    ];
}

/** One run of the queue, in a child. Its numbers. */
function runQueue(array $more = []): array
{
    $ran = child(['task' => 'run'] + $more);
    if ($ran['threw'] !== '') {
        note('a run of the queue threw: ' . $ran['threw']);
    }

    return (array) ($ran['numbers'] ?? []);
}

/**
 * ⏩ Make time pass: every moment this test's rows hold becomes this many
 * seconds OLDER, which to every statement the queue runs is the same as the
 * clock having moved on by that much.
 */
function later(int $seconds): void
{
    $own = "toRecipients LIKE '%" . DOMAIN . "%'";
    q(
        'UPDATE tblMailQueue SET createdAt = createdAt - INTERVAL ? SECOND, statusAt = statusAt - INTERVAL ? SECOND, nextAttemptAt = nextAttemptAt - INTERVAL ? SECOND, '
        . 'claimedAt = claimedAt - INTERVAL ? SECOND, expiresAt = expiresAt - INTERVAL ? SECOND WHERE ' . $own,
        'iiiii',
        array_fill(0, 5, $seconds)
    );
    q('UPDATE tblEmailLog SET sentAtUtc = sentAtUtc - INTERVAL ? SECOND WHERE ' . $own, 'i', [$seconds]);
    q(
        'UPDATE tblMailRouteState SET problemSince = problemSince - INTERVAL ? SECOND, problemSeenAt = problemSeenAt - INTERVAL ? SECOND, '
        . 'nextTryAt = nextTryAt - INTERVAL ? SECOND, jobLastRanAt = jobLastRanAt - INTERVAL ? SECOND',
        'iiii',
        array_fill(0, 4, $seconds)
    );
    q('UPDATE tblEventBroadcasts SET sentAtUtc = sentAtUtc - INTERVAL ? SECOND WHERE eventID BETWEEN 931500 AND 931599', 'i', [$seconds]);
    q('UPDATE tblPasswordResets SET expiresAt = expiresAt - INTERVAL ? SECOND WHERE userID BETWEEN 931000 AND 931999', 'i', [$seconds]);
}

/** This test's rows in the queue, oldest first, with how long until each is next tried. */
function queued(string $where = '1 = 1'): array
{
    return q(
        'SELECT queueID, siteID, priority, status, stage, holdReason, lastStage, lastError, attempts, expiresAt, toRecipients, recipientCount, attachmentCount, subject, messageID, '
        . 'bodyHtml, bodyText, repeatSafe, origin, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), nextAttemptAt) AS dueIn, TIMESTAMPDIFF(SECOND, statusAt, nextAttemptAt) AS waitGiven '
        . "FROM tblMailQueue WHERE toRecipients LIKE '%" . DOMAIN . "%' AND " . $where . ' ORDER BY queueID'
    );
}

/** The one row with this subject, or null. */
function rowOf(string $subject): ?array
{
    return queued("subject = '" . addslashes($subject) . "'")[0] ?? null;
}

/** The folders of attached files in the scratch copy. */
function storedFolders(): array
{
    global $state;
    $folder = $state['child'] . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'mail-queue';

    return is_dir($folder) === true ? array_values(array_diff((array) scandir($folder), ['.', '..'])) : [];
}

/** Encrypt the way the portal's encrypt_setting() does, with a key of this test's choosing. */
function sealed(string $plain, string $keyText): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, sodium_hex2bin(hash('sha256', $keyText))));
}

/** Decrypt the way the portal's decrypt_setting() does. '' when it cannot be decrypted. */
function unsealed(string $encoded, string $keyText): string
{
    $bin = base64_decode($encoded, true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }
    $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), sodium_hex2bin(hash('sha256', $keyText)));

    return $plain === false ? '' : $plain;
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

/** The tables migration 216 touches, and the COPY of each that Q12 runs it against. The real tables are never changed by Q12. */
const COPIES = [
    'tblMailQueueFiles' => 'selftestMqFiles', 'tblMailQueue' => 'selftestMqQueue', 'tblMailRouteState' => 'selftestMqRouteState',
    'tblEmailLog' => 'selftestMqLog', 'tblEventBroadcasts' => 'selftestMqBroadcasts', 'tblSettings' => 'selftestMqSettings',
    'tblRoutes' => 'selftestMqRoutes', 'tblMigrations' => 'selftestMqMigrations',
];
/** Names of links between tables are unique in a database, so the copies need their own. */
const COPY_LINKS = ['fk_mailqueue_site' => 'fk_selftestmq_site', 'fk_mailqueuefiles_queue' => 'fk_selftestmqfiles_queue'];

function dropCopies(): void
{
    global $db;
    foreach (COPIES as $copy) {
        $db->query('DROP TABLE IF EXISTS `' . $copy . '`');
    }
    // The trigger F5 uses to make keeping a message slow. Dropped here too, in case a run was interrupted.
    $db->query('DROP TRIGGER IF EXISTS `selftest_mq_slow`');
}

/**
 * Organisation 1's own time zone (`tblSites.timezone`), changed for one check
 * and ALWAYS put back: the value it had is remembered the first time, and the
 * clean-up restores it even when the run is interrupted.
 */
function organisationZone(string $zone): void
{
    global $state;
    $state['siteZone'] ??= (string) one('SELECT timezone FROM tblSites WHERE siteID = 1')['timezone'];
    q('UPDATE tblSites SET timezone = ? WHERE siteID = 1', 's', [$zone]);
}

/**
 * ⏰ The scheduled job has just run: what its own page records when the
 * scheduler calls it. Every part STARTS in this state, so that the parts
 * written for the queue as it was built mean what they meant. A part about an
 * installation with no job says so with noJob().
 */
function jobRunning(): void
{
    q("INSERT INTO tblMailRouteState (routeKey, jobLastRanAt) VALUES ('installation', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE jobLastRanAt = UTC_TIMESTAMP()");
}

/** ⏰ No scheduled job has ever run: an installation the morning after it was upgraded. */
function noJob(): void
{
    q('UPDATE tblMailRouteState SET jobLastRanAt = NULL');
}

/**
 * 🗓️ One event of the portal's own, WITH THE COLUMNS THE EVENT FORM WRITES
 * (web/_apps/calendar/manage/save.php): its time zone goes into `timezone`,
 * the one column an event's zone is kept in. Its end is an hour after its
 * start.
 *
 * There used to be a second zone column, `eventTimezone`, which the form
 * never wrote, and this test's events once set BOTH by hand to the same
 * value. That is why it could not see the reminders reading the wrong one.
 * Migration 217 removed the column (#604).
 */
function eventAsTheFormMakesIt(int $eventId, string $slug, string $name, string $start, string $zone): void
{
    q(
        'INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) '
        . "VALUES (?, 1, ?, ?, 'Made by the mail queue self-test', ?, ? + INTERVAL 1 HOUR, ?, 'published', 0, 0)",
        'isssss',
        [$eventId, $slug, $name, $start, $start, $zone]
    );
}

/** A moment, written as the database itself turns a time on a clock in one zone into UTC. The test's own yardstick: it is not PHP's arithmetic. */
function utcOf(string $wallClock, string $zone): string
{
    return (string) one("SELECT DATE_FORMAT(CONVERT_TZ(?, ?, '+00:00'), '%Y-%m-%d %H:%i:%s') AS utc", 'ss', [strlen($wallClock) === 10 ? $wallClock . ' 00:00:00' : $wallClock, $zone])['utc'];
}

/** The health page's "Mail queue" line, as an outside monitor reads it. */
function healthLine(): array
{
    global $jobToken;
    $health = json_decode(req('', 'GET', '/cron/health?token=' . $jobToken, null, ip(2))['body'], true);

    return (array) ($health['probes']['Mail queue'] ?? []);
}

/** The cookies a signed-in browser's jar holds, as a "Cookie" header carries them (for cgi()). */
function cookiesOf(string $jar): string
{
    $pairs = [];
    foreach ((array) @file($jar, FILE_IGNORE_NEW_LINES) as $line) {
        $fields = explode("\t", (string) $line);
        if (count($fields) === 7) {
            $pairs[] = $fields[5] . '=' . $fields[6];
        }
    }

    return implode('; ', $pairs);
}

/** One table as the database describes it, with the copies' names turned back into the real ones and the row counter taken out. */
function described(string $table): string
{
    $text = (string) one('SHOW CREATE TABLE `' . $table . '`')['Create Table'];
    $text = strtr($text, array_flip(COPIES) + array_flip(COPY_LINKS));
    // A copy made with LIKE carries no links to other tables, so those lines
    // are left out of both sides (and then the comma the last of them leaves behind).
    $text = (string) preg_replace('/\n\s+CONSTRAINT [^\n]+/', '', $text);
    $text = (string) preg_replace('/,(\n\))/', '$1', $text);

    return (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $text);
}

/**
 * Put the test back to its starting state between parts: no mail of its own
 * anywhere, nothing on record for the route, and the baseline settings (a
 * mail server that accepts, no hourly limit, the four numbers as seeded).
 */
function startClean(): void
{
    global $state;
    removeMail();
    foreach (storedFolders() as $folder) {
        $path = $state['child'] . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'mail-queue' . DIRECTORY_SEPARATOR . $folder;
        array_map('unlink', (array) glob($path . DIRECTORY_SEPARATOR . '*'));
        @rmdir($path);
    }
    q("DELETE FROM tblSettings WHERE siteID = 1 AND settingKey LIKE 'mail.queue.%'");
    foreach ($state['baseline'] as $key => [$value, $secret]) {
        setting((string) $key, (string) $value, (int) $secret);
    }
    jobRunning();
    if ($state['ports'] !== []) {
        useServer('accept');
        foreach (array_keys($state['ports']) as $scenario) {
            mailSince((string) $scenario);
        }
    }
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}; this test never drives a server it did not start");
}
$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();
$others = (int) one('SELECT COUNT(*) AS n FROM tblMailQueue')['n'];
if ($others > 0) {
    refuse("the mail queue holds {$others} row(s) that are not this test's. The test runs the mail job, which works through every row, so it will not start while another test's mail is waiting");
}
// Everything the portal records from here on is this run's, and is removed at the end.
$errorsFrom   = (int) (one('SELECT COALESCE(MAX(errorID), 0) AS n FROM tblErrors')['n']);
$activityFrom = (int) (one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n']);

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach (PEOPLE as $who => $id) {
    $global    = $who === 'root' ? 1 : 0;
    $siteAdmin = ($who === 'root' || $who === 'orgadmin') ? 1 : 0; // 'orgadmin' is in charge of organisation 1 and of nothing else
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, ?, ?)', 'issii', [$id, 'Selftest queue ' . $who, mailOf($who), $global, $global]);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, ?, ?)', 'iii', [$id, $siteAdmin, $global]);
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, login($who), $hash]);
}
// The 300, in three statements rather than nine hundred.
$users = $memberships = $going = [];
for ($n = 1; $n <= CROWD; $n++) {
    $id            = CROWD_FROM + $n;
    $users[]       = "({$id}, 'Selftest crowd {$n}', '" . crowd($n) . "', 1, 0, 0)";
    $memberships[] = "({$id}, 1, 1, 0, 0)";
    $going[]       = '(' . EVENT_BIG . ", {$id}, 1, 'going', 'confirmed')";
}
// Three events, dated from today so the test does not depend on the day it is run.
$eventDay = date('Y-m-d', strtotime('+9 days'));
foreach ([EVENT_BIG => 'mail-queue-selftest-big', EVENT_SMALL => 'mail-queue-selftest-small', EVENT_SIXTY => 'mail-queue-selftest-sixty'] as $eventId => $slug) {
    eventAsTheFormMakesIt($eventId, $slug, 'Selftest queue event', $eventDay . ' 10:00:00', 'America/New_York');
}
$db->query('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES ' . implode(',', $users));
$db->query('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES ' . implode(',', $memberships));
$db->query('INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES ' . implode(',', $going));
// The first 60 of the crowd are also going to the third event.
$db->query('INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES ' . implode(',', array_map(static fn (int $n): string => '(' . EVENT_SIXTY . ', ' . (CROWD_FROM + $n) . ", 1, 'going', 'confirmed')", range(1, 60))));
foreach (['other', 'third', 'fourth'] as $who) {
    q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (?, ?, 1, 'going', 'confirmed')", 'ii', [EVENT_SMALL, PEOPLE[$who]]);
}

// 🔑 The mailbox's password for this run, long and with characters a page must escape.
$mailPassword  = 'Mq<&' . bin2hex(random_bytes(10)) . '>"q9!';
$otherPassword = 'Other-' . bin2hex(random_bytes(10)) . '-pw';
// 🔎 Words put in message bodies, so that a body turning up anywhere it should not be can be found.
$bodyMark = 'BODYMARK-' . bin2hex(random_bytes(8));
$jobToken = 'JOBTOKEN' . bin2hex(random_bytes(16));

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'mail-queue-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.mail-queue-selftest-owner', $state['token']); // the marker FIRST
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/mail-queue-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
$keyFile = $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key';
$keyText = bin2hex(random_bytes(32));
file_put_contents($keyFile, $keyText);
$uploads = $child . $sep . 'web' . $sep . '_uploads' . $sep . 'mail-queue';

// 🪪 The certificates, made for this run by the mail library's own test's
//    maker, lifted as text (it is written inside `run.php`, which cannot be
//    loaded without running that whole test). If its shape ever changes, this
//    stops here and says so.
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
file_put_contents($child . $sep . 'certificate-maker.php', "<?php\n// Lifted from tools/maillib/tests/run.php by tools/mail-queue-selftest.php for ONE run. Test only.\ndeclare(strict_types=1);\n" . substr($libraryTest, $from, $to - $from));
require $child . $sep . 'certificate-maker.php';
if (function_exists('makeCertificates') === false) {
    refuse('the text lifted from tools/maillib/tests/run.php did not define makeCertificates()');
}
makeCertificates($standinDir . $sep . 'certs');
foreach (['ca', 'good', 'expired'] as $made) {
    if (is_file($standinDir . $sep . 'certs' . $sep . $made . '.pem') === false) {
        refuse("the certificate maker did not make '{$made}.pem'");
    }
}

// 🎭 What each stand-in mail server does (the words are smtp-standin.php's).
$mailbox   = ['user' => MAILBOX_USER, 'pass' => $mailPassword];
$scenarios = [
    'accept'           => $mailbox,
    // Accepts a different password only: to the portal's stored one it says no, until that one is stored.
    'other-password'   => ['user' => MAILBOX_USER, 'pass' => $otherPassword],
    // One second before every reply: a slow mail server, slow enough to stop a run part way through a message.
    'slow'             => $mailbox + ['delay' => 1],
    // Says "later" to every message, after the sign-in.
    'later'            => $mailbox + ['replies' => ['MAIL' => '451 4.3.0 the mailbox is busy, try again later']],
    // Signs in as usual and refuses ONE recipient's address.
    'refuse-recipient' => $mailbox + ['rcpt' => ['nobody@' . DOMAIN => '550 5.1.1 no such mailbox here']],
    // Signs in as usual and says NO (a reply beginning with 5) to the sender, for every message.
    'refuse-sender'    => $mailbox + ['replies' => ['MAIL' => '550 5.7.1 this mailbox has reached its limit for today']],
    // An expired certificate, every time.
    'cert-expired'     => $mailbox + ['certs' => ['expired']],
    // An expired certificate on the first four connections and a good one from the fifth: the certificate
    // being put right. (A checked attempt that fails makes two connections: its own, and one to read the certificate.)
    'cert-heals'       => $mailbox + ['certs' => ['expired', 'expired', 'expired', 'expired', 'good']],
];
file_put_contents($standinDir . $sep . 'scenarios.ser', serialize($scenarios));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/mail-queue-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Mail-Queue-Selftest: __TOKEN__');
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
// 🎭 `--watch-parent`: if this file is killed, the stand-in sees its pipe close, deletes its keys and folder, and stops.
$state['mailProc'] = proc_open(
    [PHP_BINARY, $repo . $sep . 'tools' . $sep . 'maillib' . $sep . 'tests' . $sep . 'smtp-standin.php', $standinDir, '--watch-parent'],
    [0 => ['pipe', 'r'], 1 => ['file', $standinDir . $sep . 'standin.out', 'w'], 2 => ['file', $standinDir . $sep . 'standin.err', 'w']],
    $mailPipes
);
$state['mailPipe'] = $mailPipes[0] ?? null;
// 🌐 The portal. `openssl.cafile` is PHP's own setting for "the authorities
//    this machine trusts": this run's made-up authority is trusted without a
//    line of the portal being changed. `max_execution_time` is raised because
//    a busy machine must not decide a test.
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
    refuse("a server never answered (the portal on port {$port}; the stand-in mail servers said: " . trim((string) @file_get_contents($standinDir . $sep . 'standin.err')) . ')');
}
$state['ports'] = (array) unserialize((string) file_get_contents($standinDir . $sep . 'ports.ser'), ['allowed_classes' => false]);
if (array_keys($state['ports']) !== array_keys($scenarios)) {
    refuse('the stand-in mail servers did not start one server for each scenario');
}

echo "The mail queue, on the real portal — self-test (#592, part 3)\n";
echo 'portal server process ' . (int) proc_get_status($state['proc'])['pid'] . ' on port ' . $port . ', stand-in mail servers process ' . (int) proc_get_status($state['mailProc'])['pid']
    . ' (' . count($state['ports']) . ' servers, 127.0.0.1 only), PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";
if ($only !== []) {
    echo 'ONLY THE PARTS ' . implode(', ', $only) . " ARE RUN. This is not the whole test and cannot end with exit 0.\n";
}

// =============================================================================
if (part('Q12', 'The database: a fresh install, migration 216 on an older one, and a backup carried through') === true) {
// =============================================================================
// Run FIRST, before this test writes any setting, so that what is read here is
// what web/_sql/full_schema.sql put there.
    $columns = [];
    foreach (q("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('tblMailQueue', 'tblMailQueueFiles', 'tblMailRouteState', 'tblEmailLog', 'tblEventBroadcasts')") as $c) {
        $columns[(string) $c['TABLE_NAME']][(string) $c['COLUMN_NAME']] = $c;
    }
    // Every DATETIME of the three new tables must say, in its own description, that it is UTC.
    $notSaid = [];
    foreach (['tblMailQueue', 'tblMailQueueFiles', 'tblMailRouteState'] as $table) {
        foreach ($columns[$table] ?? [] as $name => $c) {
            if ($c['DATA_TYPE'] === 'datetime' && str_contains((string) $c['COLUMN_COMMENT'], 'A MOMENT IN UTC') === false) {
                $notSaid[] = $table . '.' . $name;
            }
        }
    }
    $engines = array_column(q("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('tblMailQueue', 'tblMailQueueFiles', 'tblMailRouteState')"), 'ENGINE', 'TABLE_NAME');
    ok(
        'a fresh install has the three tables, each InnoDB, and every moment in them says in its own description that it is UTC',
        count($columns['tblMailQueue'] ?? []) === 24 && count($columns['tblMailQueueFiles'] ?? []) === 6 && count($columns['tblMailRouteState'] ?? []) === 17
            && $engines === ['tblMailQueue' => 'InnoDB', 'tblMailQueueFiles' => 'InnoDB', 'tblMailRouteState' => 'InnoDB'] && $notSaid === []
            && count(array_filter($columns['tblMailQueue'], static fn (array $c): bool => $c['DATA_TYPE'] === 'datetime')) === 5,
        'columns ' . json_encode(array_map('count', $columns)) . '; engines ' . json_encode($engines) . '; not said to be UTC: ' . json_encode($notSaid)
    );
    ok(
        'the send log\'s status has its three new choices at the END of the list (so no stored row changes meaning), and the broadcast record has a moment in UTC',
        ($columns['tblEmailLog']['status']['COLUMN_TYPE'] ?? '') === "enum('sent','failed','queued','held','expired')"
            && ($columns['tblEventBroadcasts']['sentAtUtc']['DATA_TYPE'] ?? '') === 'datetime' && str_contains((string) ($columns['tblEventBroadcasts']['sentAtUtc']['COLUMN_COMMENT'] ?? ''), 'A MOMENT IN UTC') === true,
        json_encode([$columns['tblEmailLog']['status']['COLUMN_TYPE'] ?? null, $columns['tblEventBroadcasts']['sentAtUtc'] ?? null])
    );
    $seeds = [];
    foreach (q("SELECT settingKey, defaultValue, isSensitive FROM tblSettings WHERE siteID IS NULL AND (settingKey LIKE 'mail.queue.%' OR settingKey LIKE 'mail.smtp.certCheck%') ORDER BY settingKey") as $s) {
        $seeds[(string) $s['settingKey']] = [(string) $s['defaultValue'], (int) $s['isSensitive']];
    }
    $jobRoute = one("SELECT targetFile, isProtected FROM tblRoutes WHERE routeKey = 'cron/mail-queue'");
    ok(
        'the install script seeds the four numbers (24, 48, 7, 7), the two settings for the later certificate page, and the job\'s address, not protected by sign-in and with no ".php" in it',
        $seeds === ['mail.queue.holdDays' => ['7', 0], 'mail.queue.keepFailedDays' => ['7', 0], 'mail.queue.maxAgeHours' => ['48', 0], 'mail.queue.retryHours' => ['24', 0],
            'mail.smtp.certCheck' => ['on', 0], 'mail.smtp.certCheckOffAt' => ['', 0]]
            && $jobRoute !== null && $jobRoute['targetFile'] === 'cron/mail-queue.php' && (int) $jobRoute['isProtected'] === 0,
        json_encode($seeds) . ' / ' . json_encode($jobRoute)
    );

    // An OLDER database, made here from copies: the send log and the broadcast
    // record as they were before migration 216, the six settings and the job's
    // address missing, the three new tables not there at all. The migration's
    // own text is run with the table names changed to the copies' names, and
    // nothing else changed.
    $freshShape = [];
    foreach (['tblMailQueue', 'tblMailQueueFiles', 'tblMailRouteState', 'tblEmailLog', 'tblEventBroadcasts'] as $table) {
        $freshShape[$table] = described($table);
    }
    foreach (['tblEmailLog', 'tblEventBroadcasts', 'tblSettings', 'tblRoutes', 'tblMigrations'] as $real) {
        $db->query('CREATE TABLE `' . COPIES[$real] . '` LIKE `' . $real . '`');
    }
    q('INSERT INTO `' . COPIES['tblSettings'] . "` (siteID, settingKey, settingValue, isSensitive, defaultValue) SELECT siteID, settingKey, settingValue, isSensitive, defaultValue FROM tblSettings WHERE siteID IS NULL AND settingKey LIKE 'mail.%'");
    q('INSERT INTO `' . COPIES['tblEmailLog'] . "` (provider, toRecipients, status) VALUES ('smtp', 'kept@" . DOMAIN . "', 'sent'), ('smtp', 'kept@" . DOMAIN . "', 'failed')");
    $db->query('ALTER TABLE `' . COPIES['tblEmailLog'] . "` MODIFY COLUMN `status` ENUM('sent','failed') NOT NULL");
    $db->query('ALTER TABLE `' . COPIES['tblEventBroadcasts'] . '` DROP COLUMN `sentAtUtc`');
    q('DELETE FROM `' . COPIES['tblSettings'] . "` WHERE settingKey LIKE 'mail.queue.%' OR settingKey LIKE 'mail.smtp.certCheck%'");
    q('INSERT INTO `' . COPIES['tblSettings'] . "` (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'mail.queue.holdDays', '3', '3', 0)"); // a value somebody saved
    $olderLog  = described(COPIES['tblEmailLog']);
    $migration = strtr((string) file_get_contents($webSrc . $sep . '_sql' . $sep . '216_mail_queue.sql'), COPIES + COPY_LINKS);
    // The copy of tblMailQueue points at the real tblSites, as the real one does.
    $counts = static function (): array {
        return [
            'settings'   => (int) one('SELECT COUNT(*) AS n FROM `' . COPIES['tblSettings'] . "` WHERE settingKey LIKE 'mail.queue.%' OR settingKey LIKE 'mail.smtp.certCheck%'")['n'],
            'routes'     => (int) one('SELECT COUNT(*) AS n FROM `' . COPIES['tblRoutes'] . "` WHERE routeKey = 'cron/mail-queue'")['n'],
            'migrations' => (int) one('SELECT COUNT(*) AS n FROM `' . COPIES['tblMigrations'] . "` WHERE filename = '216_mail_queue.sql'")['n'],
            'logRows'    => implode(',', array_column(q('SELECT status FROM `' . COPIES['tblEmailLog'] . '` ORDER BY emailLogID'), 'status')),
            'kept'       => (string) (one('SELECT settingValue FROM `' . COPIES['tblSettings'] . "` WHERE settingKey = 'mail.queue.holdDays' AND siteID IS NULL")['settingValue'] ?? ''),
        ];
    };
    $error = '';
    try {
        runSql($migration);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $afterShape = [];
    foreach (['tblMailQueue', 'tblMailQueueFiles', 'tblMailRouteState', 'tblEmailLog', 'tblEventBroadcasts'] as $table) {
        $afterShape[$table] = $error === '' ? described(COPIES[$table]) : '';
    }
    $afterOnce = $counts();
    $differ    = array_keys(array_filter($freshShape, static fn (string $shape, string $table): bool => $shape !== $afterShape[$table], ARRAY_FILTER_USE_BOTH));
    ok(
        'migration 216 on an older database: all five tables come out exactly as a fresh install has them, the stored send-log rows still say what they said, the saved setting is kept, and the migration records itself',
        $error === '' && $olderLog !== $freshShape['tblEmailLog'] && $differ === [] && $afterOnce === ['settings' => 6, 'routes' => 1, 'migrations' => 1, 'logRows' => 'sent,failed', 'kept' => '3'],
        ($error === '' ? '' : 'error: ' . $error . '; ') . 'tables that differ from a fresh install: ' . json_encode($differ) . '; ' . json_encode($afterOnce)
            . ($differ === [] ? '' : "\n" . $afterShape[$differ[0]] . "\nfresh:\n" . $freshShape[$differ[0]])
    );
    $error = '';
    try {
        runSql($migration);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $twiceShape = [];
    foreach (array_keys($freshShape) as $table) {
        $twiceShape[$table] = $error === '' ? described(COPIES[$table]) : '';
    }
    ok(
        'migration 216 a second time: no error, no table changes, and NOT ONE ROW is added (six settings, one address, one record: counted, because a settings seed can add a row every time it runs)',
        $error === '' && $twiceShape === $afterShape && $counts() === $afterOnce,
        $error === '' ? 'after the second run: ' . json_encode($counts()) : 'error: ' . $error
    );
    dropCopies();
}

// =============================================================================
// The settings every part starts from
// =============================================================================
$cipher            = sealed($mailPassword, $keyText);
$state['baseline'] = [
    // Believe the "X-Forwarded-For" header from 127.0.0.1, so each part has a visitor address of its own.
    'portal.trustedProxies' => ['127.0.0.1', 0], 'portal.trustedProxyHeader' => ['x-forwarded-for', 0],
    'mail.provider' => ['smtp', 0], 'mail.smtp.host' => [MAIL_HOST, 0], 'mail.smtp.security' => ['starttls', 0], 'mail.smtp.username' => [MAILBOX_USER, 0],
    'mail.smtp.password' => [$cipher, 1], 'mail.defaultFromAddress' => [SENDER, 0], 'mail.defaultFromName' => ['Selftest Queue Office', 0], 'mail.defaultReplyTo' => ['', 0],
    'mail.limit.perHour' => ['', 0], 'mail.fallbackProvider' => ['', 0],
    'mail.queue.retryHours' => ['24', 0], 'mail.queue.maxAgeHours' => ['48', 0], 'mail.queue.keepFailedDays' => ['7', 0], 'mail.queue.holdDays' => ['7', 0],
    // The job's token, stored as plain text and NOT marked as a secret, so that it reads the same whether or not the portal's start-up decrypts secret settings.
    'maintenance.cronToken' => [$jobToken, 0],
    // The portal's own address: a link in an e-mail written by a child process (which has no visitor) points at the server this run started.
    'site.url' => ['http://127.0.0.1:' . $port, 0],
    'auth.passwordReset.tokenExpiry' => ['60', 0],
    // The events app, which the broadcast page belongs to, is switched off in a fresh database.
    'calendar.enabled' => ['true', 0],
    // The event reminder job's own token (F2, F3).
    'reminders.cron_token' => [$jobToken, 0], 'reminders.enabled' => ['1', 0],
];
startClean();
$rootJar = signIn('root', $password, ip(1));
/** The job, as a scheduler calls it. */
$job = static function (string $query = '', array $headers = []) use ($jobToken): array {
    $answer = req('', 'GET', '/cron/mail-queue' . ($query === 'right' ? '?token=' . $jobToken : $query), null, ip(2), $headers);

    return $answer + ['json' => json_decode($answer['body'], true)];
};
/** A file to attach, made new each time it is asked for. */
$makeFile = static function (string $name, string $content) use ($child, $sep): string {
    $path = $child . $sep . $name;
    file_put_contents($path, $content);

    return $path;
};

// =============================================================================
if (part('Q1', 'The mail server up: a reset goes at once and nothing is written to the queue') === true) {
// =============================================================================
    $sent = askForLink(ip(11), mailOf('member'));
    $mail = mailSince('accept');
    $log  = q("SELECT status FROM tblEmailLog WHERE toRecipients = ? ORDER BY emailLogID", 's', [mailOf('member')]);
    ok(
        'the reset e-mail is with the mail server by the time the request has ended, the send log says "sent", and the queue holds no row',
        $sent['status'] === 302 && count($mail['messages']) === 1 && $mail['messages'][0]['to'] === [mailOf('member')] && array_column($log, 'status') === ['sent']
            && queued() === [] && records(ip(11), 'PasswordResetEmailed') === 1,
        'HTTP ' . $sent['status'] . '; messages ' . count($mail['messages']) . '; send log ' . json_encode(array_column($log, 'status')) . '; queue rows ' . count(queued())
    );
    // "No row is EVER written", not "no row is left": a message routed through
    // the table and deleted again would leave it empty too. Counted by sending
    // five more and reading the table's own row counter before and after.
    $probe  = child(['task' => 'add', 'messages' => [row([mailOf('other')], 'Q1 the row that shows the counter before')]]);
    $before = (int) ($probe['ids'][0] ?? 0);
    q("DELETE FROM tblMailQueue WHERE subject = 'Q1 the row that shows the counter before'");
    $ran    = child(['task' => 'send', 'messages' => [
        msg(mailOf('other'), 'Q1 one'), msg(mailOf('third'), 'Q1 two'), msg([mailOf('other'), mailOf('third')], 'Q1 three'),
        msg(mailOf('fourth'), 'Q1 four', 'A message.', ['priority' => 'urgent']), msg(mailOf('fourth'), 'Q1 five', 'A message.', ['priority' => 'bulk']),
    ]]);
    $probe = child(['task' => 'add', 'messages' => [row([mailOf('other')], 'Q1 the row that shows the counter after')]]);
    $after = (int) ($probe['ids'][0] ?? 0);
    $mail  = mailSince('accept');
    ok(
        'five more messages of every priority go at once, and the queue\'s own row counter shows that NONE of them passed through the table (the next row made is the very next number)',
        $ran['threw'] === '' && array_column((array) $ran['each'], 1) === array_fill(0, 5, 'sent') && array_column((array) $ran['each'], 2) === array_fill(0, 5, 0)
            && count($mail['messages']) === 5 && $before > 0 && $after === $before + 1,
        json_encode($ran['each'] ?? $ran['threw']) . '; messages ' . count($mail['messages']) . '; the row made before was number ' . $before . ' and the one made after is ' . $after
    );
}

// =============================================================================
if (part('Q2', 'The mail server down: the reset is kept, unreadable where it is stored, and the next run delivers it') === true) {
// =============================================================================
    serverDown();
    $sent = askForLink(ip(12), mailOf('member'));
    $row  = queued()[0] ?? null;
    $link = one('SELECT expiresAt FROM tblPasswordResets WHERE userID = ? AND usedAt IS NULL ORDER BY resetID DESC LIMIT 1', 'i', [PEOPLE['member']]);
    ok(
        'the reset is kept: queued, urgent, safe to repeat, counted as tried once and due again in one minute, and its use-by moment is the very moment its link stops working',
        $sent['status'] === 302 && $row !== null && $row['status'] === 'queued' && (int) $row['priority'] === 1 && (int) $row['repeatSafe'] === 1 && (int) $row['attempts'] === 1
            && (int) $row['waitGiven'] === 60 && $row['toRecipients'] === mailOf('member') && $link !== null && $row['expiresAt'] === $link['expiresAt']
            && records(ip(12), 'PasswordResetMailWaiting') === 1,
        json_encode($row === null ? null : array_diff_key($row, ['bodyHtml' => 1, 'bodyText' => 1])) . ' / the link expires ' . json_encode($link)
    );
    $plain = $row === null ? '' : unsealed((string) $row['bodyHtml'], $keyText);
    $token = preg_match('/reset-password\?token=([0-9a-f]{64})/', $plain, $m) === 1 ? $m[1] : '';
    $whole = $row === null ? '' : (string) json_encode($row);
    ok(
        'the stored body cannot be read in the database and holds no part of the link: not the link, not its secret part, not the words of the message; only the installation\'s key turns it back',
        $row !== null && $token !== '' && str_contains($whole, $token) === false && str_contains($whole, substr($token, 0, 16)) === false && stripos($whole, 'reset-password') === false
            && stripos((string) $row['bodyHtml'] . (string) $row['bodyText'], 'Reset My Password') === false && str_contains(base64_decode((string) $row['bodyHtml'], true) ?: '', 'token=') === false
            && unsealed((string) $row['bodyHtml'], bin2hex(random_bytes(32))) === '',
        $token === '' ? 'the stored body did not decrypt with the installation\'s key, or holds no link' : 'something of the link is readable in the stored row'
    );
    $log = one('SELECT status, errorCode FROM tblEmailLog WHERE toRecipients = ? ORDER BY emailLogID DESC LIMIT 1', 's', [mailOf('member')]);
    ok('the send log says it is queued, and why (the mail server could not be reached)', $log !== null && $log['status'] === 'queued' && $log['errorCode'] === 'cannot-connect', (string) json_encode($log));
    // The server comes back. Not due for a minute yet: a run now leaves it alone.
    useServer('accept');
    $early = runQueue();
    $none  = mailSince('accept');
    later(60);
    $ran  = $job('right');
    $mail = mailSince('accept');
    $got  = $mail['messages'][0] ?? null;
    $page = $got === null || preg_match('#http://127\.0\.0\.1:' . $port . '(/reset-password\?token=[0-9a-f]{64})#', $got['html'], $l) !== 1 ? null : req('', 'GET', $l[1], null, ip(12));
    ok(
        'a run before its minute is up sends nothing; the job\'s next run after it delivers the message once, to the right person, with a link that works, and the row is gone',
        ($early['sent'] ?? -1) === 0 && $none['messages'] === [] && $ran['status'] === 200 && ($ran['json']['sent'] ?? 0) === 1 && count($mail['messages']) === 1 && $got['to'] === [mailOf('member')]
            && $page !== null && $page['status'] === 200 && str_contains($page['body'], 'name="password"') === true && queued() === [],
        'early run sent ' . json_encode($early['sent'] ?? null) . '; the job answered ' . $ran['body'] . '; messages ' . count($mail['messages']) . '; the link\'s page ' . json_encode($page['status'] ?? null) . '; queue rows ' . count(queued())
    );
    $log = q('SELECT status FROM tblEmailLog WHERE toRecipients = ? ORDER BY emailLogID', 's', [mailOf('member')]);
    ok('the send log now reads queued, then sent: the lasting record of what became of it', array_column($log, 'status') === ['queued', 'sent'], (string) json_encode(array_column($log, 'status')));
}

// =============================================================================
if (part('Q13', 'The person is shown the same thing whatever becomes of the e-mail') === true) {
// =============================================================================
    $baseline     = askForLink(ip(21), mailOf('member'));
    $baselineLand = landing($baseline, ip(21));
    $cases        = [
        [22, 'accept', 'nobody-here@' . DOMAIN, 'an address with no account', '', 0],
        [23, 'down', mailOf('member'), 'the mail server down, so the e-mail is QUEUED', 'queued', 1],
        [24, 'later', mailOf('member'), 'a mail server that says "later", so the e-mail is QUEUED', 'queued', 1],
        [25, 'cert-expired', mailOf('member'), 'a mail server whose certificate has expired, so the e-mail is HELD', 'held', 1],
        [26, 'cert-expired', mailOf('member'), 'the same again, with the certificate fault already on record (held without the mail server being contacted)', 'held', 1],
    ];
    foreach ($cases as [$last, $scenario, $address, $what, $expect, $rows]) {
        q("DELETE FROM tblMailQueue WHERE toRecipients LIKE '%" . DOMAIN . "%'");
        $scenario === 'down' ? serverDown() : useServer($scenario);
        $sent = askForLink(ip($last), $address);
        $kept = queued();
        ok(
            $what . ': the same status, address, headers and page as when the e-mail went',
            shape($sent) === shape($baseline) && landing($sent, ip($last)) === $baselineLand && count($kept) === $rows && ($rows === 0 || $kept[0]['status'] === $expect),
            'queue: ' . json_encode(array_column($kept, 'status')) . (shape($sent) === shape($baseline) ? '' : "\nthis:\n" . shape($sent) . "\nwhen sent:\n" . shape($baseline))
        );
    }
}

// =============================================================================
if (part('Q10', 'The job: its token, the lock, and what it prints') === true) {
// =============================================================================
    child(['task' => 'add', 'messages' => [row([mailOf('other')], 'Q10 waiting ' . $bodyMark)]]);
    $refused = [];
    foreach (['no token' => '', 'a wrong token' => '?token=' . strrev($jobToken), 'a token one character short' => '?token=' . substr($jobToken, 0, -1), 'a list where the token should be' => '?token[]=' . $jobToken] as $what => $query) {
        $answer = $job($query);
        if ($answer['status'] !== 403 || $answer['json'] !== ['status' => 'forbidden']) {
            $refused[] = $what . ' got HTTP ' . $answer['status'] . ' ' . $answer['body'];
        }
    }
    $header = $job('', ['Authorization: Bearer ' . strrev($jobToken)]);
    $mail   = mailSince('accept');
    ok(
        'no token, a wrong one, one a character short, a list in its place and a wrong one in the header are each answered 403 "forbidden", and nothing is sent',
        $refused === [] && $header['status'] === 403 && $mail['messages'] === [] && count(queued()) === 1,
        implode('; ', $refused) . ' / header: HTTP ' . $header['status'] . '; messages ' . count($mail['messages'])
    );
    setting('maintenance.cronToken', '');
    $unset = $job('?token=');
    setting('maintenance.cronToken', $jobToken);
    ok('with NO token set in the portal, an empty token is refused too (the job does nothing until an administrator sets one)', $unset['status'] === 403, 'HTTP ' . $unset['status'] . ' ' . $unset['body']);
    // A second run while one holds the lock. This test's own connection takes the very lock a run takes.
    $db->query("SELECT GET_LOCK(CONCAT('portal-mail-queue:', SHA1(DATABASE())), 0)");
    $busy = $job('right');
    $mail = mailSince('accept');
    $db->query("DO RELEASE_LOCK(CONCAT('portal-mail-queue:', SHA1(DATABASE())))");
    ok(
        'a second run while another holds the lock is turned away, says so ("busy"), sends nothing and leaves the message waiting',
        $busy['status'] === 200 && ($busy['json']['status'] ?? '') === 'busy' && ($busy['json']['ran'] ?? -1) === 0 && $mail['messages'] === [] && count(queued("status = 'queued'")) === 1,
        'HTTP ' . $busy['status'] . ' ' . $busy['body'] . '; messages ' . count($mail['messages'])
    );
    $right  = $job('', ['Authorization: Bearer ' . $jobToken]);
    $mail   = mailSince('accept');
    $values = array_diff_key((array) $right['json'], ['status' => 1]);
    ok(
        'the right token, sent in the Authorization header, runs the job: the message goes, and the answer is a status word and NUMBERS ONLY (no address, no subject, nothing of the message)',
        $right['status'] === 200 && ($right['json']['status'] ?? '') === 'ok' && ($right['json']['sent'] ?? 0) === 1 && count($mail['messages']) === 1
            && $values !== [] && array_filter($values, static fn (mixed $v): bool => is_int($v) === false) === []
            && str_contains($right['body'], '@') === false && str_contains($right['body'], 'Q10') === false && str_contains($right['body'], $bodyMark) === false,
        'HTTP ' . $right['status'] . ' ' . $right['body']
    );
    // The token in the ADDRESS, with something that makes the job record an
    // error (a recipient the mail server refuses): the record must not carry it.
    useServer('refuse-recipient');
    child(['task' => 'add', 'messages' => [row(['nobody@' . DOMAIN], 'Q10 refused')]]);
    $address = $job('right');
    $errors  = q('SELECT requestURL, requestHeaders, errorDetail FROM tblErrors WHERE errorID > ?', 'i', [$errorsFrom]);
    $kept    = (string) json_encode([$errors, q('SELECT * FROM tblActivityLogs WHERE logID > ?', 'i', [$activityFrom])]);
    $fromJob = array_values(array_filter($errors, static fn (array $e): bool => str_contains((string) $e['requestURL'], 'cron/mail-queue') === true));
    ok(
        'the right token in the address runs the job too, and an error the job records carries the address WITHOUT the token; the token is in no error or activity record at all',
        $address['status'] === 200 && ($address['json']['failed'] ?? 0) === 1 && $fromJob !== [] && $fromJob[0]['requestURL'] === '/cron/mail-queue' && str_contains($kept, $jobToken) === false,
        'HTTP ' . $address['status'] . ' ' . $address['body'] . '; the job\'s error records carry: ' . json_encode(array_column($fromJob, 'requestURL')) . (str_contains($kept, $jobToken) === true ? '; THE TOKEN IS IN A RECORD' : '')
    );
}

// =============================================================================
if (part('Q6', 'Retries: the stated waits, the stated count, and never for a message refused outright') === true) {
// =============================================================================
    serverDown();
    child(['task' => 'send', 'messages' => [msg(mailOf('other'), 'Q6 ordinary'), msg(mailOf('third'), 'Q6 urgent', 'A message.', ['priority' => 'urgent'])]]);
    $seen  = ['Q6 ordinary' => [], 'Q6 urgent' => []];
    $tried = ['Q6 ordinary' => 0, 'Q6 urgent' => 0];
    $ends  = [];
    for ($round = 1; $round <= 25; $round++) {
        // After each attempt that did not go: the wait the message was given.
        foreach (array_keys($seen) as $subject) {
            $row = rowOf($subject);
            if ($row !== null && $row['status'] === 'queued' && (int) $row['attempts'] > $tried[$subject]) {
                $tried[$subject]  = (int) $row['attempts'];
                $seen[$subject][] = (int) $row['waitGiven'];
            } elseif ($row !== null && $row['status'] !== 'queued' && isset($ends[$subject]) === false) {
                $ends[$subject] = [$row['status'], (int) $row['attempts'], (string) $row['lastError']];
            }
        }
        if (count($ends) === 2) {
            break;
        }
        // On to the moment the next message is due, and then two runs: a run
        // stops at the first message it cannot reach the mail server for, so
        // two messages due together need a run each.
        $due = min(array_map(static fn (array $r): int => (int) $r['dueIn'], queued("status = 'queued'")) ?: [0]);
        later(max(0, $due));
        runQueue();
        runQueue();
    }
    ok(
        'an ordinary message is tried again after exactly 5 and 15 minutes, then 1, 3, 6 and 12 hours, and after the seventh try it is failed, in words',
        $seen['Q6 ordinary'] === WAITS_OTHER && ($ends['Q6 ordinary'][0] ?? '') === 'failed' && ($ends['Q6 ordinary'][1] ?? 0) === 7
            && str_starts_with($ends['Q6 ordinary'][2] ?? '', 'It was tried 7 times and is not being tried again.') === true,
        'waits ' . json_encode($seen['Q6 ordinary']) . '; ended ' . json_encode($ends['Q6 ordinary'] ?? null)
    );
    ok(
        'an urgent message is tried again after exactly 1, 5, 15 and 30 minutes, and after the fifth try it is failed',
        $seen['Q6 urgent'] === WAITS_URGENT && ($ends['Q6 urgent'][0] ?? '') === 'failed' && ($ends['Q6 urgent'][1] ?? 0) === 5,
        'waits ' . json_encode($seen['Q6 urgent']) . '; ended ' . json_encode($ends['Q6 urgent'] ?? null)
    );
    $facts = child(['task' => 'facts', 'waits' => [[1, 5], [6, 5], [7, 5], [20, 5], [1, 1], [4, 1], [5, 1]]]);
    ok(
        'past the end of the list an ordinary message waits 12 hours again (for an installation that sets a longer retry time), and an urgent one has no further wait',
        ($facts['waits'] ?? null) === [300, 43200, 43200, 43200, 60, 1800, null],
        (string) json_encode($facts['waits'] ?? $facts['threw'])
    );
    // A message the mail server refuses outright: never tried again.
    startClean();
    useServer('refuse-recipient');
    child(['task' => 'add', 'messages' => [row(['nobody@' . DOMAIN], 'Q6 refused outright'), row([mailOf('other')], 'Q6 fine')]]);
    $first = runQueue();
    $mail  = mailSince('refuse-recipient');
    later(86400);
    $second = runQueue();
    $again  = mailSince('refuse-recipient');
    $row    = rowOf('Q6 refused outright');
    ok(
        'a message the mail server refuses for its recipient (a reply beginning 5) is failed at once and never tried again, and the message after it still goes',
        ($first['failed'] ?? 0) === 1 && ($first['sent'] ?? 0) === 1 && $row !== null && $row['status'] === 'failed' && (int) $row['attempts'] === 1 && $row['lastStage'] === 'RECIPIENT'
            && count($mail['messages']) === 1 && $again['connections'] === 0 && ($second['sent'] ?? -1) === 0,
        'first run ' . json_encode($first) . '; the row ' . json_encode($row === null ? null : [$row['status'], $row['attempts'], $row['lastStage']]) . '; connections on the later run ' . $again['connections']
    );
    // A "no" to the SENDER is very often about the mailbox, so it is not thrown away on.
    startClean();
    useServer('later');
    child(['task' => 'add', 'messages' => [row([mailOf('other')], 'Q6 later one'), row([mailOf('third')], 'Q6 later two')]]);
    $ran = runQueue();
    ok(
        'a mail server that says "later" to a message: it is kept and due again in five minutes; and because that answer was to the sender, which every message shares, the run stops there and the next message is not counted as tried',
        ($ran['waiting'] ?? 0) === 1 && ($ran['stopped'] ?? 0) === 1 && (int) (rowOf('Q6 later one')['attempts'] ?? -1) === 1 && (int) (rowOf('Q6 later one')['waitGiven'] ?? -1) === 300
            && (int) (rowOf('Q6 later two')['attempts'] ?? -1) === 0 && (rowOf('Q6 later two')['status'] ?? '') === 'queued',
        json_encode($ran) . ' / ' . json_encode([rowOf('Q6 later one')['attempts'] ?? null, rowOf('Q6 later two')['attempts'] ?? null])
    );
}

// =============================================================================
if (part('Q7', 'A message past its use-by moment is never sent') === true) {
// =============================================================================
    serverDown();
    askForLink(ip(31), mailOf('member'));
    $row = queued()[0] ?? null;
    useServer('accept'); // the mail server comes back ...
    later(3600);         // ... one second too late: the link's hour is up
    $ran  = $job('right');
    $mail = mailSince('accept');
    $now  = queued()[0] ?? null;
    $log  = one('SELECT status, errorCode FROM tblEmailLog WHERE toRecipients = ? ORDER BY emailLogID DESC LIMIT 1', 's', [mailOf('member')]);
    ok(
        'a reset whose link has run out is marked expired, its body is removed at once, nothing is sent although the mail server is back, and the send log says so',
        $row !== null && $row['status'] === 'queued' && ($ran['json']['expired'] ?? 0) === 1 && ($ran['json']['sent'] ?? -1) === 0 && $mail['connections'] === 0
            && $now !== null && $now['status'] === 'expired' && $now['bodyHtml'] === null && $now['bodyText'] === null && $log !== null && $log['status'] === 'expired',
        'the job answered ' . $ran['body'] . '; connections ' . $mail['connections'] . '; the row ' . json_encode($now === null ? null : [$now['status'], $now['bodyHtml'], $now['bodyText']]) . '; log ' . json_encode($log)
    );
    // The same, without the sweep that did it there: the CLAIM alone must pass it over ...
    startClean();
    child(['task' => 'add', 'messages' => [row([mailOf('other')], 'Q7 past at the claim', ['expiresAt' => gmdate('Y-m-d H:i:s', time() - 5)]), row([mailOf('third')], 'Q7 wanted')]]);
    $ran  = child(['task' => 'claim-loop']);
    $mail = mailSince('accept');
    ok(
        'the claim itself passes over a message past its use-by moment (the message beside it goes)',
        $ran['threw'] === '' && ($ran['handled'] ?? null) === ['sent'] && count($mail['messages']) === 1 && $mail['messages'][0]['to'] === [mailOf('third')] && (rowOf('Q7 past at the claim')['status'] ?? '') === 'queued',
        json_encode($ran['handled'] ?? $ran['threw']) . '; messages ' . count($mail['messages'])
    );
    // ... and so must the last check, for a message whose moment passes AFTER it was claimed.
    startClean();
    child(['task' => 'add', 'messages' => [row([mailOf('other')], 'Q7 past after the claim', ['expiresAt' => gmdate('Y-m-d H:i:s', time() + 3600)])]]);
    $ran  = child(['task' => 'claim-then', 'limit' => 5, 'sqlAfterClaim' => ["UPDATE tblMailQueue SET expiresAt = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE subject = 'Q7 past after the claim'"]]);
    $mail = mailSince('accept');
    $now  = rowOf('Q7 past after the claim');
    ok(
        'a message whose use-by moment passes between being claimed and being handed over is not sent: the last check before the mail server catches it',
        $ran['threw'] === '' && ($ran['claimed'] ?? 0) === 1 && ($ran['handled'] ?? null) === ['expired'] && $mail['connections'] === 0 && $now !== null && $now['status'] === 'expired' && $now['bodyHtml'] === null,
        json_encode([$ran['claimed'] ?? null, $ran['handled'] ?? $ran['threw']]) . '; connections ' . $mail['connections'] . '; the row ' . json_encode($now['status'] ?? null)
    );
    // Too old, whatever its use-by moment: nothing older than 48 hours is sent.
    startClean();
    child(['task' => 'add', 'messages' => [row([mailOf('other')], 'Q7 too old'), row([mailOf('third')], 'Q7 young enough')]]);
    q("UPDATE tblMailQueue SET createdAt = createdAt - INTERVAL 172801 SECOND WHERE subject = 'Q7 too old'");
    q("UPDATE tblMailQueue SET createdAt = createdAt - INTERVAL 172700 SECOND WHERE subject = 'Q7 young enough'");
    $ran  = child(['task' => 'claim-loop']);
    $mail = mailSince('accept');
    ok(
        'a message 48 hours and one second old is not sent, even by a claim made without the sweep (it is failed, in words); one a hundred seconds younger is',
        ($ran['handled'] ?? null) === ['failed', 'sent'] && count($mail['messages']) === 1 && $mail['messages'][0]['to'] === [mailOf('third')]
            && str_contains((string) (rowOf('Q7 too old')['lastError'] ?? ''), 'waited longer than 48 hours') === true,
        json_encode($ran['handled'] ?? $ran['threw']) . '; ' . json_encode(rowOf('Q7 too old')['lastError'] ?? null)
    );
}

// =============================================================================
if (part('Q8', 'Attachments: a copy is kept, sent after the original has gone, and removed with the row') === true) {
// =============================================================================
    serverDown();
    $content  = "A statement attached by the mail queue self-test.\n" . bin2hex(random_bytes(200)) . "\n";
    $original = $makeFile('q8-statement.txt', $content);
    $ran      = child(['task' => 'send', 'messages' => [msg(mailOf('other'), 'Q8 with a file', 'A message with a statement.', [], [$original])]]);
    $row      = rowOf('Q8 with a file');
    $files    = q('SELECT storedName, realName, sizeBytes FROM tblMailQueueFiles WHERE queueID = ?', 'i', [(int) ($row['queueID'] ?? 0)]);
    $copy     = $files === [] ? '' : $uploads . $sep . $files[0]['storedName'] . $sep . $files[0]['realName'];
    $stored   = (string) json_encode([$row, $files]);
    ok(
        'a message with a file that cannot go is kept WITH A COPY of the file, under a random folder name, and the original\'s path is stored nowhere',
        ($ran['each'][0][1] ?? '') === 'queued' && $row !== null && (int) $row['attachmentCount'] === 1 && count($files) === 1 && preg_match('/\A[0-9a-f]{32}\z/', (string) $files[0]['storedName']) === 1
            && $files[0]['realName'] === 'q8-statement.txt' && (int) $files[0]['sizeBytes'] === strlen($content) && is_file($copy) === true && file_get_contents($copy) === $content
            && str_contains($stored, $child) === false && str_contains($stored, 'q8-statement.txt') === true,
        json_encode($ran['each'] ?? $ran['threw']) . '; files ' . json_encode($files) . '; a copy on disk: ' . json_encode(is_file($copy))
    );
    unlink($original); // the caller's file is gone by the time the job runs
    useServer('accept');
    later(300);
    $done = runQueue();
    $mail = mailSince('accept');
    ok(
        'after the original is deleted the job sends the message from the copy, carrying the file whole under its own name; then the row, the file row and the copy are all gone',
        ($done['sent'] ?? 0) === 1 && count($mail['messages']) === 1 && str_contains($mail['messages'][0]['raw'], 'q8-statement.txt') === true
            && str_contains((string) preg_replace('/\s+/', '', $mail['messages'][0]['raw']), base64_encode($content)) === true
            && queued() === [] && (int) one('SELECT COUNT(*) AS n FROM tblMailQueueFiles')['n'] === 0 && storedFolders() === [],
        json_encode($done) . '; messages ' . count($mail['messages']) . '; folders left ' . json_encode(storedFolders())
    );
    // A copy that has gone missing: failed in words, never sent without it.
    serverDown();
    $original = $makeFile('q8-second.txt', $content);
    child(['task' => 'send', 'messages' => [msg(mailOf('other'), 'Q8 the copy goes missing', 'A message with a statement.', [], [$original])]]);
    $files = q('SELECT storedName, realName FROM tblMailQueueFiles');
    unlink($uploads . $sep . $files[0]['storedName'] . $sep . $files[0]['realName']);
    useServer('accept');
    later(300);
    $done = runQueue();
    $mail = mailSince('accept');
    $row  = rowOf('Q8 the copy goes missing');
    ok(
        'a message whose copy has gone missing is failed, in words, and is NOT sent without its file',
        ($done['failed'] ?? 0) === 1 && $mail['messages'] === [] && $row !== null && $row['status'] === 'failed' && str_contains((string) $row['lastError'], 'a file attached to it is missing') === true,
        json_encode($done) . '; messages ' . count($mail['messages']) . '; ' . json_encode($row['lastError'] ?? null)
    );
    // A file too big, or missing when it is queued: refused, and nothing is left behind.
    $ran = child(['task' => 'send', 'messages' => [msg(mailOf('other'), 'Q8 a file that is not there', 'A message.', ['sendNow' => false], [$child . $sep . 'no-such-file.pdf'])]]);
    ok(
        'a message asked to wait whose file is not there is not kept half-made: it is refused, no row and no folder are left, and the send log says why',
        ($ran['each'][0][0] ?? null) === false && rowOf('Q8 a file that is not there') === null
            && (one("SELECT errorCode FROM tblEmailLog WHERE subject = 'Q8 a file that is not there' ORDER BY emailLogID DESC LIMIT 1")['errorCode'] ?? '') === 'message-refused',
        json_encode($ran['each'] ?? $ran['threw'])
    );
    // Strays: a copy no row points at is removed once it is a day old, and not before.
    startClean();
    foreach (['old' => 90000, 'new' => 60] as $which => $age) {
        $name = str_repeat($which === 'old' ? 'a' : 'b', 32);
        mkdir($uploads . $sep . $name, 0700, true);
        file_put_contents($uploads . $sep . $name . $sep . 'stray.txt', 'left behind');
        touch($uploads . $sep . $name, time() - $age);
    }
    $done = runQueue();
    ok(
        'a copy no row points at is removed once it is a day old; one made a minute ago is left (it may be a message being queued at this moment)',
        ($done['strayFiles'] ?? 0) === 1 && storedFolders() === [str_repeat('b', 32)],
        json_encode($done['strayFiles'] ?? null) . '; folders left ' . json_encode(storedFolders())
    );
}

// =============================================================================
if (part('Q5', 'A run killed at three points, and what the next run does') === true) {
// =============================================================================
    // A slow mail server (one second before every reply), so that a run can be
    // stopped while it is part way through handing one message over.
    useServer('slow');
    child(['task' => 'add', 'messages' => [
        row([mailOf('other')], 'Q5 A handed over when the run died'),
        row([mailOf('third')], 'Q5 B claimed and not reached'),
    ]]);
    $kid   = startChild(['task' => 'run']);
    $until = microtime(true) + 60;
    while ((rowOf('Q5 A handed over when the run died')['stage'] ?? '') !== 'handing-over' && microtime(true) < $until) {
        usleep(20000);
    }
    proc_terminate($kid['proc'], 9); // KILLED: A is being handed over (the server has not been given it yet), B is claimed
    finishChild($kid);
    $mail  = mailSince('slow');
    $a     = rowOf('Q5 A handed over when the run died');
    $b     = rowOf('Q5 B claimed and not reached');
    note('KILL 1 (process ' . $kid['pid'] . ', just BEFORE the message is handed over) left: A ' . json_encode([$a['status'] ?? null, $a['stage'] ?? null, 'attempts' => $a['attempts'] ?? null])
        . ', B ' . json_encode([$b['status'] ?? null, $b['stage'] ?? null, 'attempts' => $b['attempts'] ?? null]) . '; the mail server had received ' . count($mail['messages']) . ' message(s)');
    ok(
        'killed just before handing over: that message is left marked "handing-over" with its attempt already counted, the one after it "claimed" with none, and the mail server has neither',
        $a !== null && $a['status'] === 'sending' && $a['stage'] === 'handing-over' && (int) $a['attempts'] === 1 && $b !== null && $b['status'] === 'sending' && $b['stage'] === 'claimed' && (int) $b['attempts'] === 0
            && $mail['messages'] === [],
        json_encode([$a === null ? null : [$a['status'], $a['stage'], $a['attempts']], $b === null ? null : [$b['status'], $b['stage'], $b['attempts']]]) . '; messages ' . count($mail['messages'])
    );
    useServer('accept');
    $soon = runQueue();
    $mail = mailSince('accept');
    ok(
        'the next run, straight away, touches neither: a claim is not taken for dead until it is ten minutes old',
        ($soon['returned'] ?? -1) === 0 && ($soon['held'] ?? -1) === 0 && ($soon['sent'] ?? -1) === 0 && $mail['messages'] === [] && (rowOf('Q5 B claimed and not reached')['status'] ?? '') === 'sending',
        json_encode($soon)
    );
    // The claim is made exactly 590 seconds old, then 602: read from the
    // database and corrected, so that the seconds this test itself has
    // taken do not decide which side of ten minutes it is on.
    $claimAge = static fn (): int => (int) one("SELECT TIMESTAMPDIFF(SECOND, claimedAt, UTC_TIMESTAMP()) AS age FROM tblMailQueue WHERE subject = 'Q5 B claimed and not reached'")['age'];
    later(590 - $claimAge());
    $almost = runQueue();
    later(602 - $claimAge());
    $after  = runQueue();
    $mail   = mailSince('accept');
    $a      = rowOf('Q5 A handed over when the run died');
    ok(
        'at 9 minutes 50 seconds still nothing; just past ten minutes the claimed message goes back to the queue and is SENT ONCE, and the one that was being handed over is HELD for a person and NOT sent again',
        ($almost['returned'] ?? -1) === 0 && ($after['returned'] ?? 0) === 1 && ($after['held'] ?? 0) === 1 && ($after['sent'] ?? 0) === 1 && count($mail['messages']) === 1 && $mail['messages'][0]['to'] === [mailOf('third')]
            && $a !== null && $a['status'] === 'held' && $a['holdReason'] === 'interrupted' && rowOf('Q5 B claimed and not reached') === null,
        'at 9:50 ' . json_encode($almost) . '; at 10:02 ' . json_encode($after) . '; messages ' . count($mail['messages']) . '; A is ' . json_encode([$a['status'] ?? null, $a['holdReason'] ?? null])
    );
    later(86400);
    runQueue();
    $mail = mailSince('accept');
    ok('a day of runs later the held message has still not been sent, and the send log says why it is held', $mail['messages'] === []
        && (one("SELECT status, errorCode FROM tblEmailLog WHERE subject = 'Q5 A handed over when the run died' ORDER BY emailLogID DESC LIMIT 1") ?? []) === ['status' => 'held', 'errorCode' => 'interrupted'],
        'messages ' . count($mail['messages']));

    // KILL 2: just AFTER the mail server has taken the message. This test holds
    // the row locked, so the run cannot record anything once the server has
    // answered; it is killed there. One message is ordinary, one is marked as
    // safe to repeat.
    foreach (['Q5 C taken by the server, then the run died' => false, 'Q5 D the same, and safe to repeat' => true] as $subject => $repeatSafe) {
        startClean();
        useServer('slow');
        child(['task' => 'add', 'messages' => [row([mailOf('other')], $subject, ['repeatSafe' => $repeatSafe])]]);
        $id     = (int) rowOf($subject)['queueID'];
        $bodies = static fn (): int => count(array_filter(saw('slow', false), static fn (array $e): bool => $e[2] === 'body' && str_contains($e[3], 'complete=1') === true));
        $had    = $bodies();
        $kid    = startChild(['task' => 'run']);
        $until = microtime(true) + 60;
        while ((rowOf($subject)['stage'] ?? '') !== 'handing-over' && microtime(true) < $until) {
            usleep(20000);
        }
        $db->begin_transaction();
        $db->query('SELECT queueID FROM tblMailQueue WHERE queueID = ' . $id . ' FOR UPDATE');
        $until = microtime(true) + 60;
        while ($bodies() === $had && microtime(true) < $until) {
            usleep(50000);
        }
        $taken = $bodies() === $had + 1;
        // The server's "accepted" reaches the run a second later. The run then
        // records "sent" and goes to delete the row, which this test holds: it
        // waits there. Wait until the database shows that statement waiting.
        $until   = microtime(true) + 30;
        $waiting = [];
        while ($waiting === [] && microtime(true) < $until) {
            usleep(100000);
            $waiting = q("SELECT ID FROM information_schema.PROCESSLIST WHERE DB = DATABASE() AND INFO LIKE 'DELETE FROM tblMailQueue%'");
        }
        proc_terminate($kid['proc'], 9);
        finishChild($kid);
        // The run is dead, but the DATABASE would still carry out the
        // statement it was waiting to run the moment the row is let go. A
        // run that dies an instant earlier never sends that statement, and
        // that is the case being made here: so the statement is ended too.
        foreach ($waiting as $statement) {
            $db->query('KILL ' . (int) $statement['ID']);
        }
        usleep(300000);
        $db->rollback();
        $mail  = mailSince('slow');
        $left  = rowOf($subject);
        $first = $mail['messages'][0]['id'] ?? '';
        note('KILL 2 (process ' . $kid['pid'] . ', just AFTER the mail server took "' . $subject . '") left: ' . json_encode([$left['status'] ?? null, $left['stage'] ?? null, 'attempts' => $left['attempts'] ?? null])
            . '; the mail server had received ' . count($mail['messages']) . ' message(s)');
        useServer('accept');
        later(601);
        $after = runQueue();
        $mail  = mailSince('accept');
        $now   = rowOf($subject);
        if ($repeatSafe === false) {
            ok(
                'killed just after the mail server took the message: the row is left "handing-over"; ten minutes on it is HELD, and NO second copy is sent',
                $taken === true && $left !== null && $left['stage'] === 'handing-over' && $first !== '' && ($after['held'] ?? 0) === 1 && $mail['messages'] === [] && $now !== null && $now['status'] === 'held' && $now['holdReason'] === 'interrupted',
                'the server took it: ' . json_encode($taken) . '; left ' . json_encode($left['stage'] ?? null) . '; next run ' . json_encode($after) . '; second copies ' . count($mail['messages'])
            );
        } else {
            ok(
                'the same for a message marked as safe to repeat: it goes back to the queue and IS sent again, under the same Message-ID, so a mail system that has seen it can drop the second copy',
                $taken === true && $first !== '' && ($after['returned'] ?? 0) === 1 && ($after['sent'] ?? 0) === 1 && count($mail['messages']) === 1 && $mail['messages'][0]['id'] === $first && $now === null,
                'next run ' . json_encode($after) . '; first Message-ID ' . $first . '; second ' . json_encode($mail['messages'][0]['id'] ?? null)
            );
        }
    }
}

// =============================================================================
if (part('Q4', 'Four runs at once, WITHOUT the lock, deliver 200 messages exactly once each') === true) {
// =============================================================================
    $rows = [];
    for ($n = 1; $n <= 200; $n++) {
        $rows[] = row([crowd($n)], 'Q4 message ' . $n);
    }
    child(['task' => 'add', 'messages' => $rows]);
    // The four wait for one starting signal, so that their first claims are made at the same instant.
    $go   = $child . $sep . 'go-' . bin2hex(random_bytes(4));
    $kids = [];
    for ($n = 0; $n < 4; $n++) {
        $kids[] = startChild(['task' => 'claim-loop', 'waitFor' => $go]);
    }
    usleep(1500000);
    touch($go);
    $handled = [];
    foreach ($kids as $kid) {
        $done      = finishChild($kid);
        $handled[] = count((array) ($done['handled'] ?? []));
    }
    unlink($go);
    $mail  = mailSince('accept');
    $to    = array_map(static fn (array $m): string => implode(',', $m['to']), $mail['messages']);
    $ids   = array_column($mail['messages'], 'id');
    $twice = array_keys(array_filter(array_count_values($to), static fn (int $n): bool => $n > 1));
    ok(
        'the mail server receives exactly 200 messages: one for each of the 200 people, none twice, 200 different Message-IDs, and the queue is empty',
        count($mail['messages']) === 200 && count(array_unique($to)) === 200 && $twice === [] && count(array_unique($ids)) === 200 && queued() === [] && array_sum($handled) === 200,
        'messages ' . count($mail['messages']) . '; different recipients ' . count(array_unique($to)) . '; sent twice: ' . json_encode(array_slice($twice, 0, 5)) . '; each run handled ' . json_encode($handled)
    );
    ok('all four runs took a share (so the four really did overlap)', count(array_filter($handled, static fn (int $n): bool => $n > 0)) === 4, json_encode($handled));
}

// =============================================================================
if (part('Q3', 'A broadcast to 300 with a limit of 100 an hour') === true) {
// =============================================================================
    setting('mail.limit.perHour', '100');
    $facts = child(['task' => 'facts']);
    ok('with a limit of 100 an hour, ordinary mail has room for 90 and urgent mail for all 100: the last tenth is kept back', ($facts['roomOther'] ?? null) === 90 && ($facts['roomUrgent'] ?? null) === 100, json_encode([$facts['roomOther'] ?? null, $facts['roomUrgent'] ?? null]));
    $press = static fn (int $eventId, string $subject, string $jar): array => req($jar, 'POST', '/calendar/event/broadcast/send', [
        'csrf_token' => anyToken($jar, ip(1)), 'eventID' => (string) $eventId, 'segment' => 'all-rsvps', 'subject' => $subject, 'body' => 'A broadcast from the mail queue self-test. ' . $GLOBALS['bodyMark'],
    ], ip(1));
    $answer = $press(EVENT_BIG, 'Q3 broadcast', $rootJar);
    $page   = words(req($rootJar, 'GET', $answer['location'], null, ip(1))['body']);
    $mail   = mailSince('accept');
    $record = q('SELECT recipientCount, sentAtUtc FROM tblEventBroadcasts WHERE eventID = ?', 'i', [EVENT_BIG]);
    $all    = $mail['messages'];
    ok(
        'the request sends 25 and keeps the other 275, the page says exactly that, and the broadcast is on record once, for 300 people, with its moment in UTC',
        $answer['status'] === 302 && count($mail['messages']) === 25 && count(queued("status = 'queued'")) === 275 && str_contains($page, 'Sent to 25 of 300 recipients.') === true
            && str_contains($page, '275 are queued') === true && count($record) === 1 && (int) $record[0]['recipientCount'] === 300 && $record[0]['sentAtUtc'] !== null,
        'HTTP ' . $answer['status'] . ' ' . mb_substr(words($answer['body']), 0, 400) . '; messages ' . count($mail['messages']) . '; queued ' . count(queued("status = 'queued'")) . '; the page said: ' . (preg_match('/Sent to [^.]*\.[^.]*\./', $page, $m) === 1 ? $m[0] : '(nothing)')
    );
    $priorities = array_unique(array_column(queued(), 'priority'));
    ok('every kept message is bulk, so none of them can sit in front of somebody\'s reset link', $priorities === ['9'] || $priorities === [9], json_encode($priorities));
    // The first hour: the job may send 65 more (90 for ordinary mail, less the 25 that have gone).
    $ran  = $job('right');
    $ran2 = $job('right');
    $mail = mailSince('accept');
    $all  = array_merge($all, $mail['messages']);
    ok(
        'in the first hour the job sends 65 more and stops: 90 in all, with the last 10 of the 100 kept back. A second run in the same hour sends nothing',
        ($ran['json']['sent'] ?? 0) === 65 && ($ran2['json']['sent'] ?? -1) === 0 && count($mail['messages']) === 65 && count(queued("status = 'queued'")) === 210,
        'first run ' . $ran['body'] . '; second ' . $ran2['body']
    );
    // A reset asked for in the middle of it all goes AT ONCE, from the part kept back.
    $reset = askForLink(ip(41), mailOf('member'));
    $mail  = mailSince('accept');
    ok(
        'a password reset asked for in the middle goes at once, in its own request, although ordinary mail has no room left',
        count($mail['messages']) === 1 && $mail['messages'][0]['to'] === [mailOf('member')] && records(ip(41), 'PasswordResetEmailed') === 1 && count(queued("status = 'queued'")) === 210,
        'messages ' . count($mail['messages']) . '; queued ' . count(queued("status = 'queued'"))
    );
    $resetSeen = $mail['messages'];
    $perHour   = [];
    for ($hour = 1; $hour <= 3; $hour++) {
        later(3600);
        $n = 0;
        for ($run = 0; $run < 3; $run++) {
            $n += (int) ($job('right')['json']['sent'] ?? 0);
        }
        $perHour[] = $n;
        $all       = array_merge($all, mailSince('accept')['messages']);
    }
    $to    = array_map(static fn (array $m): string => implode(',', $m['to']), $all);
    $twice = array_keys(array_filter(array_count_values($to), static fn (int $n): bool => $n > 1));
    ok(
        'over the next three hours the job sends 90, 90 and the last 30: all 300 have arrived, each person exactly once, and the queue is empty',
        $perHour === [90, 90, 30] && count($all) === 300 && count(array_unique($to)) === 300 && $twice === [] && queued() === [],
        'sent in each hour ' . json_encode($perHour) . '; messages ' . count($all) . '; different people ' . count(array_unique($to)) . '; twice: ' . json_encode(array_slice($twice, 0, 5)) . '; left ' . count(queued())
    );
    ok('each person got a message for them alone (nobody sees anybody else\'s address)', array_filter($all, static fn (array $m): bool => count($m['to']) !== 1) === [] && count($resetSeen) === 1, 'a message had more than one recipient');
}

// =============================================================================
if (part('X4', 'A broadcast pressed twice') === true) {
// =============================================================================
    $press = static fn (string $subject): array => req($rootJar, 'POST', '/calendar/event/broadcast/send', [
        'csrf_token' => anyToken($rootJar, ip(1)), 'eventID' => (string) EVENT_SMALL, 'segment' => 'all-rsvps', 'subject' => $subject, 'body' => 'A broadcast to three people.',
    ], ip(1));
    $first  = $press('X4 broadcast');
    $one    = mailSince('accept');
    $second = $press('X4 broadcast');
    $page   = words(req($rootJar, 'GET', $second['location'], null, ip(1))['body']);
    $two    = mailSince('accept');
    ok(
        'the first press sends to all three and records the broadcast; the second press sends NOTHING, keeps nothing, leaves ONE record, and tells the person why and what to do',
        count($one['messages']) === 3 && $second['status'] === 302 && $two['messages'] === [] && queued() === []
            && (int) one('SELECT COUNT(*) AS n FROM tblEventBroadcasts WHERE eventID = ?', 'i', [EVENT_SMALL])['n'] === 1
            && str_contains($page, 'already sent to this group less than ten minutes ago, so it has not been sent again') === true,
        'first press: ' . count($one['messages']) . ' messages; second: ' . count($two['messages']) . '; records ' . json_encode(one('SELECT COUNT(*) AS n FROM tblEventBroadcasts WHERE eventID = ?', 'i', [EVENT_SMALL]))
    );
    $other = $press('X4 broadcast, other words');
    $three = mailSince('accept');
    ok('a broadcast with different words is not taken for a second press: it goes', count($three['messages']) === 3, 'messages ' . count($three['messages']));
    // The first press is made exactly 580 seconds old, then 603 (see Q5 for why the age is read back).
    $pressAge = static fn (): int => (int) one("SELECT TIMESTAMPDIFF(SECOND, MIN(sentAtUtc), UTC_TIMESTAMP()) AS age FROM tblEventBroadcasts WHERE subject = 'X4 broadcast'")['age'];
    later(580 - $pressAge());
    $early = $press('X4 broadcast');
    $none  = mailSince('accept');
    later(603 - $pressAge());
    $again = $press('X4 broadcast');
    $four  = mailSince('accept');
    ok(
        'nine minutes 40 seconds after the first press the same words are still refused; just past ten minutes they go again (somebody who means to repeat a message can)',
        $none['messages'] === [] && count($four['messages']) === 3,
        'at 9:40 ' . count($none['messages']) . ' messages; at 10:03 ' . count($four['messages'])
    );
    // With the mail server down the broadcast is still recorded once, and every message is kept.
    startClean();
    serverDown();
    $down = $press('X4 broadcast with the server down');
    $page = words(req($rootJar, 'GET', $down['location'], null, ip(1))['body']);
    $twice = $press('X4 broadcast with the server down');
    ok(
        'with the mail server down the broadcast is recorded once and all three messages are kept; the page says none has gone and that they are queued; pressing again adds nothing',
        count(queued("status = 'queued'")) === 3 && $twice['status'] === 302 && str_contains($page, 'Sent to 0 of 3 recipients.') === true && str_contains($page, '3 are queued') === true
            && (int) one("SELECT COUNT(*) AS n FROM tblEventBroadcasts WHERE subject = 'X4 broadcast with the server down'")['n'] === 1,
        'queued ' . count(queued("status = 'queued'")) . '; the page said: ' . (preg_match('/Sent to [^.]*\.[^.]*\./', $page, $m) === 1 ? $m[0] : '(nothing)')
    );
}

// =============================================================================
if (part('Q12B', 'A backup carries waiting mail, and a restore HOLDS it (three copies, not six); beside another key it is failed, in words') === true) {
// =============================================================================
// (M4.) Three messages are waiting when a backup is taken. They then GO. The
// backup is put back, which brings their rows back, and the job runs. This
// used to send all three a second time. Now a restore holds them.
    serverDown();
    $content  = "Carried through a backup by the mail queue self-test.\n";
    $original = $makeFile('q12-carried.txt', $content);
    child(['task' => 'send', 'messages' => [
        msg(mailOf('other'), 'Q12 carried through a backup', 'A message that waits. ' . $bodyMark, [], [$original]),
        msg(mailOf('third'), 'Q12 the second waiting message'),
        msg(mailOf('fourth'), 'Q12 the third, safe to repeat', 'A message.', ['repeatSafe' => true]),
    ]]);
    // And a fourth that is HELD FOR THE CERTIFICATE when the backup is taken.
    // Mail held for that reason is released by itself when the certificate
    // passes, so after a restore it could go a second time like the rest: it
    // has to come back held as "restored", which nothing releases.
    child(['task' => 'add', 'messages' => [row([mailOf('other')], 'Q12 the fourth, held for the certificate', ['status' => 'held', 'holdReason' => 'certificate'])]]);
    /** Every row of the queue as it stands, without the two numbers that change as the clock moves. */
    $still = static fn (): array => array_map(static fn (array $r): array => array_diff_key($r, ['dueIn' => 1, 'waitGiven' => 1]), queued());
    // What a restore must put back exactly. Its state is left out: that is what a restore now changes, and it is checked by name below.
    $keep   = static fn (?array $r): ?array => $r === null ? null : array_diff_key($r, ['dueIn' => 1, 'waitGiven' => 1, 'status' => 1, 'holdReason' => 1, 'lastError' => 1]);
    $before = $keep(rowOf('Q12 carried through a backup'));
    $files  = q('SELECT storedName, realName, contentType, sizeBytes FROM tblMailQueueFiles');
    $snap   = child(['task' => 'snapshot']);
    $taken  = ($snap['result']['success'] ?? false) === true;
    // AFTER the backup, the mail server comes back and all three go.
    useServer('accept');
    later(300);
    $went  = runQueue();
    $first = mailSince('accept');
    $left  = $still();
    $gone  = array_column($left, 'subject') === ['Q12 the fourth, held for the certificate'] && (int) one('SELECT COUNT(*) AS n FROM tblMailQueueFiles')['n'] === 0;
    // A rehearsal ("check only") first: it must change nothing.
    $try      = $taken === true ? child(['task' => 'restore', 'directory' => (string) $snap['result']['directory'], 'dryRun' => true]) : ['threw' => 'no snapshot to rehearse'];
    $afterTry = $still();
    $tryWords = implode(' ', (array) ($try['result']['warnings'] ?? []));
    // The real restore.
    $back  = $taken === true ? child(['task' => 'restore', 'directory' => (string) $snap['result']['directory']]) : ['threw' => 'no snapshot to restore'];
    $after = $keep(rowOf('Q12 carried through a backup'));
    $rows  = queued();
    $said  = implode(' ', (array) ($back['result']['warnings'] ?? []));
    ok(
        'a snapshot holds the waiting message and its file row, and a restore puts both back exactly: the same encrypted body, the same use-by moment, the same identifier',
        $taken === true && ($went['sent'] ?? 0) === 3 && count($first['messages']) === 3 && $gone === true && $back['threw'] === '' && ($back['result']['success'] ?? false) === true
            && $before !== null && $after === $before && q('SELECT storedName, realName, contentType, sizeBytes FROM tblMailQueueFiles') === $files && count($files) === 1,
        'snapshot ' . json_encode($snap['result']['error'] ?? $snap['threw']) . '; sent before the restore ' . json_encode($went['sent'] ?? null) . '; restore ' . json_encode($back['result']['error'] ?? $back['threw']) . '; the same row: ' . json_encode($after === $before)
    );
    ok(
        'a rehearsal ("check only") of that restore changes NOTHING in the queue (the one row left in it is the same in every column), and says that 4 messages WOULD be held',
        ($try['result']['success'] ?? false) === true && count($left) === 1 && $afterTry === $left && str_contains($tryWords, '4 messages') === true && str_contains($tryWords, 'would be HELD') === true,
        'rows after the rehearsal ' . count($afterTry) . ', the same as before it: ' . json_encode($afterTry === $left) . '; it said: ' . $tryWords
    );
    ok(
        'after the real restore all four are HELD AS RESTORED (the one marked safe to repeat, and the one that was held for the certificate, which would otherwise be released by itself), none is waiting, each says in plain words that it came back with a restored backup and may already have been sent, and the restore itself says so',
        array_column($rows, 'status') === array_fill(0, 4, 'held') && array_column($rows, 'holdReason') === array_fill(0, 4, 'restored')
            && array_filter($rows, static fn (array $r): bool => str_contains((string) $r['lastError'], 'came back with a restored backup') === false || str_contains((string) $r['lastError'], 'may already have been sent') === false) === []
            && str_contains($said, '4 messages') === true && str_contains($said, 'have been HELD') === true,
        json_encode(array_map(static fn (array $r): array => [$r['status'], $r['holdReason']], $rows)) . '; the restore said: ' . $said
    );
    // The job runs, then again an hour later. (The restore put the SETTINGS
    // back too, so the portal is pointed at the working mail server again;
    // and an hour is let pass, because the restored rows were not due yet.
    // Without both, "nothing was sent" would prove nothing.)
    useServer('accept');
    $one = runQueue();
    later(3600);
    $two    = runQueue();
    $second = mailSince('accept');
    $line   = healthLine();
    ok(
        'the job then sends NONE of them a second time: three copies went in all, not six. All four stay held, and the health page counts them',
        count($first['messages']) === 3 && $second['messages'] === [] && ($one['sent'] ?? -1) === 0 && ($two['sent'] ?? -1) === 0 && array_column(queued(), 'status') === array_fill(0, 4, 'held')
            && str_contains((string) ($line['detail'] ?? ''), '4 held') === true,
        'copies after the restore ' . count($second['messages']) . '; runs ' . json_encode([$one['sent'] ?? null, $two['sent'] ?? null]) . '; rows ' . json_encode(array_column(queued(), 'status')) . '; health ' . json_encode($line['detail'] ?? null)
    );
    startClean();
    // Restored beside ANOTHER key: what a database put back on a new installation holds.
    serverDown();
    child(['task' => 'send', 'messages' => [msg(mailOf('other'), 'Q12 stored with another key', 'A message nobody here can read. ' . $bodyMark)]]);
    $newKey = bin2hex(random_bytes(32));
    file_put_contents($keyFile, $newKey);
    setting('mail.smtp.password', sealed($mailPassword, $newKey), 1); // the administrator has typed the mailbox's password in again
    useServer('accept');
    later(300);
    $done = runQueue();
    $mail = mailSince('accept');
    $row  = rowOf('Q12 stored with another key');
    file_put_contents($keyFile, $keyText);
    setting('mail.smtp.password', $cipher, 1);
    ok(
        'a waiting message stored with another installation\'s key is failed, in words, and NOT sent as a scramble: the mail server is given nothing',
        ($done['failed'] ?? 0) === 1 && $mail['messages'] === [] && $row !== null && $row['status'] === 'failed' && str_contains((string) $row['lastError'], 'cannot be read on this installation') === true,
        json_encode($done) . '; messages ' . count($mail['messages']) . '; ' . json_encode($row['lastError'] ?? null)
    );
}

// =============================================================================
if (part('Q9', 'Erasure, and "a copy of my data"') === true) {
// =============================================================================
    serverDown();
    $original = $makeFile('q9-statement.txt', "A statement for one person.\n");
    $longer   = 'x' . mailOf('erased'); // somebody ELSE, whose address merely contains the erased person's
    child(['task' => 'send', 'messages' => [
        msg(mailOf('erased'), 'Q9 for the person alone', 'A message. ' . $bodyMark, [], [$original]),
        msg([mailOf('erased'), mailOf('other')], 'Q9 for two people'),
        msg($longer, 'Q9 for a longer address that contains theirs'),
        msg(mailOf('other'), 'Q9 for somebody else'),
    ]]);
    q("UPDATE tblMailQueue SET lastError = ? WHERE subject = 'Q9 for two people'", 's', ['Not sent. The mail server said: 450 <' . mailOf('erased') . '> mailbox busy']);
    $aloneFolder = (string) (one('SELECT storedName FROM tblMailQueueFiles')['storedName'] ?? '');
    // "A copy of my data", as the person themselves, BEFORE they are erased.
    $jar    = signIn('erased', $password, ip(61));
    $export = req($jar, 'GET', '/account/data-export', null, ip(61));
    $data   = (array) (json_decode($export['body'], true)['data'] ?? []);
    $wait   = (array) ($data['mailWaitingToBeSent'] ?? []);
    ok(
        '"a copy of my data" lists the two messages waiting for the person: the subject, when it was written, its state and the name of the attached file; not its text, not the other recipient\'s address, and not the message for the longer address',
        $export['status'] === 200 && array_column($wait, 'subject') === ['Q9 for the person alone', 'Q9 for two people'] && ($wait[0]['attachedFiles'] ?? null) === ['q9-statement.txt']
            && ($wait[0]['state'] ?? '') === 'queued' && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', (string) ($wait[0]['writtenAtUtc'] ?? '')) === 1
            && str_contains((string) json_encode($wait), mailOf('other')) === false && str_contains($export['body'], $bodyMark) === false && str_contains($export['body'], 'longer address') === false,
        'HTTP ' . $export['status'] . '; waiting: ' . json_encode($wait)
    );
    $logged = (array) ($data['emailSendLog'] ?? []);
    ok(
        'and the portal\'s record of mail sent or tried to their address: two lines, each with its subject and what became of it, and nobody else\'s address',
        count($logged) === 2 && array_values(array_unique(array_column($logged, 'whatBecameOfIt'))) === ['queued'] && str_contains((string) json_encode($logged), '@') === false,
        json_encode($logged)
    );
    // (M5.) Lines of the kind a refused message leaves behind: the mail
    // server's own words, which name the address, in the send log's reason
    // text and on the Errors page. And the same about SOMEBODY ELSE, which
    // must be left alone.
    $refusal = static fn (string $address): string => 'Not sent. The mail server refused a recipient\'s address. The mail server said: 550 5.1.1 <' . $address . '>: no such mailbox here';
    q(
        "INSERT INTO tblEmailLog (provider, toRecipients, subject, status, errorCode, errorDetail) VALUES ('smtp', ?, 'Q9 log, to them alone', 'failed', 'message-refused', ?), "
        . "('smtp', ?, 'Q9 log, to two people', 'failed', 'message-refused', ?), ('smtp', ?, 'Q9 log, to somebody else', 'failed', 'message-refused', ?)",
        'ssssss',
        [mailOf('erased'), $refusal(mailOf('erased')), mailOf('other') . ', ' . mailOf('erased'), $refusal(mailOf('erased')), mailOf('other'), $refusal(mailOf('other'))]
    );
    // Five error lines, in this order: SMTP naming the person; SMTP naming
    // somebody else; Microsoft 365 ("Graph") and Google ("Gmail") naming the
    // person (an erasure must clean every route's lines, not SMTP's alone);
    // and SMTP naming the LONGER address, which is somebody else's line and
    // must be left as it is.
    $errorLine = "(1, ?, 'Error', 'q9-made-up', 'Mail send fail', ?, ?)";
    q(
        'INSERT INTO tblErrors (siteID, errorPlatform, errorSeverity, errorCode, errorTitle, errorDetail, userAgent) VALUES ' . implode(', ', array_fill(0, 5, $errorLine)),
        str_repeat('sss', 5),
        ['SMTP', $refusal(mailOf('erased')), AGENT, 'SMTP', $refusal(mailOf('other')), AGENT, 'Graph', 'ErrorInvalidRecipients: ' . mailOf('erased') . ' could not be found', AGENT,
            'Gmail', '{"error":{"message":"Invalid To header: ' . mailOf('erased') . '"}}', AGENT, 'SMTP', $refusal($longer), AGENT]
    );
    // The real erasure, run by the erasure engine itself.
    q(
        "INSERT INTO tblErasureRequest (requestID, siteID, userID, subjectEmail, subjectName, confirmToken, status, dueBy) VALUES (?, 1, ?, ?, 'Selftest queue erased', NULL, 'processing', DATE_ADD(NOW(), INTERVAL 1 MONTH))",
        'iis',
        [REQUEST_ID, PEOPLE['erased'], mailOf('erased')]
    );
    $ran    = child(['task' => 'erase', 'requestId' => REQUEST_ID, 'userId' => PEOPLE['erased'], 'adminId' => PEOPLE['root']]);
    $audit  = one("SELECT action, recordKey FROM tblErasureAudit WHERE requestID = ? AND tableName = 'tblMailQueue'", 'i', [REQUEST_ID]);
    $shared = rowOf('Q9 for two people');
    ok(
        'erasing the person deletes the message that was for them alone, with its file row and the stored copy, and the erasure\'s own record says so',
        $ran['threw'] === '' && ($ran['result']['failed'] ?? -1) === 0 && rowOf('Q9 for the person alone') === null && (int) one('SELECT COUNT(*) AS n FROM tblMailQueueFiles')['n'] === 0
            && $aloneFolder !== '' && in_array($aloneFolder, storedFolders(), true) === false && $audit !== null && $audit['action'] === 'delete' && $audit['recordKey'] === '1 deleted, 1 changed',
        json_encode($ran['result'] ?? $ran['threw']) . '; audit ' . json_encode($audit) . '; folders ' . json_encode(storedFolders())
    );
    $whole = (string) json_encode(q('SELECT * FROM tblMailQueue'));
    ok(
        'in the message that was also for somebody else, their address is taken off the list and out of the last error; the message for the LONGER address, and somebody else\'s, are untouched',
        $shared !== null && $shared['toRecipients'] === mailOf('other') && (int) $shared['recipientCount'] === 1 && $shared['lastError'] === ''
            && (rowOf('Q9 for a longer address that contains theirs')['toRecipients'] ?? '') === $longer && (rowOf('Q9 for somebody else')['toRecipients'] ?? '') === mailOf('other')
            && preg_match('/(?<![a-z])' . preg_quote(mailOf('erased'), '/') . '/', $whole) !== 1,
        json_encode($shared === null ? null : [$shared['toRecipients'], $shared['recipientCount'], $shared['lastError']])
    );
    $logRows   = array_column(q("SELECT subject, toRecipients, errorDetail FROM tblEmailLog WHERE subject LIKE 'Q9 log,%'"), null, 'subject');
    $errorRows = array_column(q("SELECT errorDetail FROM tblErrors WHERE errorCode = 'q9-made-up' AND errorID > ? ORDER BY errorID", 'i', [$errorsFrom]), 'errorDetail');
    $kept      = (string) json_encode([q('SELECT toRecipients, errorDetail FROM tblEmailLog'), q('SELECT errorDetail FROM tblErrors WHERE errorID > ?', 'i', [$errorsFrom])]);
    $logAudit  = one("SELECT action, recordKey, details FROM tblErasureAudit WHERE requestID = ? AND tableName = 'tblEmailLog'", 'i', [REQUEST_ID]);
    ok(
        'the address is gone from the REASON TEXT as well: every send-log line whose recipients held it has its reason emptied; the three mail error lines that named it (SMTP, Microsoft 365 and Google) have their detail emptied; the line about somebody else, and the line naming a LONGER address that merely contains theirs, are untouched; and the erasure\'s own record counts exactly the three',
        preg_match('/(?<![a-z])' . preg_quote(mailOf('erased'), '/') . '/', $kept) !== 1
            && ($logRows['Q9 log, to them alone']['errorDetail'] ?? 'x') === '' && ($logRows['Q9 log, to two people']['errorDetail'] ?? 'x') === ''
            && ($logRows['Q9 log, to two people']['toRecipients'] ?? '') === mailOf('other') . ', [erased]'
            && ($logRows['Q9 log, to somebody else']['errorDetail'] ?? '') === $refusal(mailOf('other')) && $errorRows === ['', $refusal(mailOf('other')), '', '', $refusal($longer)]
            && $logAudit !== null && $logAudit['action'] === 'anonymise' && preg_match('/\A\d+ rows, 3 error lines\z/', (string) $logAudit['recordKey']) === 1
            && str_contains((string) $logAudit['details'], 'reason text of those rows emptied') === true,
        'send log ' . json_encode($logRows) . '; error lines ' . json_encode($errorRows) . '; audit ' . json_encode($logAudit)
    );
    useServer('accept');
    later(900);
    runQueue();
    runQueue();
    $mail = mailSince('accept');
    $to   = array_merge(...array_column($mail['messages'], 'to'));
    sort($to);
    ok(
        'when the mail server is back, the three messages that are left go, and NOTHING goes to the erased person',
        count($mail['messages']) === 3 && $to === [mailOf('other'), mailOf('other'), $longer] && in_array(mailOf('erased'), $to, true) === false,
        'sent to: ' . json_encode($to)
    );
    // The coverage check must FAIL when an entry is taken out of the written list.
    $cov = $child . $sep . 'coverage';
    foreach (['tools' . $sep . 'audit-checks', 'web' . $sep . '_sql', 'web' . $sep . '_core'] as $folder) {
        mkdir($cov . $sep . $folder, 0700, true);
    }
    copy($repo . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'check_personal_data_coverage.py', $cov . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'check_personal_data_coverage.py');
    copy($webSrc . $sep . '_sql' . $sep . 'full_schema.sql', $cov . $sep . 'web' . $sep . '_sql' . $sep . 'full_schema.sql');
    $list     = (string) file_get_contents($webSrc . $sep . '_core' . $sep . 'personal-data-catalogue.php');
    $coverage = static function (string $catalogue) use ($cov, $sep, $python): array {
        file_put_contents($cov . $sep . 'web' . $sep . '_core' . $sep . 'personal-data-catalogue.php', $catalogue);
        exec(escapeshellarg($python) . ' ' . escapeshellarg($cov . $sep . 'tools' . $sep . 'audit-checks' . $sep . 'check_personal_data_coverage.py') . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    };
    $whole   = $coverage($list);
    $missed  = [];
    foreach (['tblMailQueue', 'tblMailQueueFiles', 'tblEmailLog'] as $table) {
        $without = (string) preg_replace("/    '" . $table . "' => \\[.*?\\n    \\],\\n/s", '', $list, 1, $removed);
        $result  = $coverage($without);
        if ($removed !== 1 || $result[0] !== 1 || preg_match('/• ' . $table . '\b/u', $result[1]) !== 1) {
            $missed[] = $table . ' (removed ' . $removed . ', exit ' . $result[0] . ')';
        }
    }
    ok(
        'the coverage check passes on the written list as it is, and FAILS, naming the table, when the entry for the mail queue, for its files or for the send log is taken out',
        $whole[0] === 0 && $missed === [],
        'as it is: exit ' . $whole[0] . '; not caught: ' . json_encode($missed)
    );
}

// =============================================================================
if (part('Q11', 'Both clock-change nights, in two zones, with the database and PHP both in the changing zone') === true) {
// =============================================================================
    // The NEXT change in each direction after today, found from the zone's own
    // rules, so that this part does not depend on the day it is run. Every
    // moment below is then made on a made-up clock set around that change.
    foreach (['Europe/London', 'America/New_York'] as $zone) {
        $changes = array_slice((new DateTimeZone($zone))->getTransitions(time(), time() + 400 * 86400), 1);
        $picked  = [];
        foreach ($changes as $change) {
            $picked[$change['isdst'] === true ? 'forward' : 'back'] ??= (int) $change['ts'];
        }
        foreach ($picked as $way => $at) {
            $what  = $zone . ', the clocks going ' . $way . ' (' . gmdate('j M Y H:i', $at) . ' UTC)';
            $clock = static fn (int $moment): array => ['zone' => $zone, 'timestamp' => $moment];
            $utc   = static fn (int $moment): string => gmdate('Y-m-d H:i:s', $moment);

            // (a) A retry worked out half an hour BEFORE the change, for an hour later: half an hour after it.
            startClean();
            serverDown();
            child(['task' => 'add', 'messages' => [row([mailOf('other')], 'Q11 retry', ['attempts' => 2])]] + $clock($at - 1810));
            runQueue($clock($at - 1800));
            $row   = one("SELECT attempts, nextAttemptAt FROM tblMailQueue WHERE subject = 'Q11 retry'");
            runQueue($clock($at + 1799));
            $early = (int) one("SELECT attempts FROM tblMailQueue WHERE subject = 'Q11 retry'")['attempts'];
            runQueue($clock($at + 1800));
            $onTime = (int) one("SELECT attempts FROM tblMailQueue WHERE subject = 'Q11 retry'")['attempts'];
            ok(
                $what . ': a third try that fails 30 minutes before the change is due exactly 3,600 seconds later, 30 minutes after it; it is not tried one second early, and is tried on the second',
                $row !== null && (int) $row['attempts'] === 3 && $row['nextAttemptAt'] === $utc($at + 1800) && $early === 3 && $onTime === 4,
                'stored ' . json_encode($row) . ', expected ' . $utc($at + 1800) . '; attempts one second early ' . $early . ', on the second ' . $onTime
            );

            // (b) The hour's window across the change: 4 recipients 50 minutes before it count at 5 minutes after it; 3 sent 56 minutes 40 seconds before do not.
            startClean();
            setting('mail.limit.perHour', '20'); // ordinary mail may use 15
            // `sentAt` (the server's own clock) is given a moment of that night too. Left at today, the send log's own
            // tidying, which now and then removes lines more than 90 days old, takes these two for old lines when the
            // made-up night is more than 90 days away, and the count then comes out as nothing. (It did, one run in three.)
            q(
                "INSERT INTO tblEmailLog (provider, toRecipients, status, sentAt, sentAtUtc, recipientCount) VALUES ('smtp', ?, 'sent', ?, ?, 4), ('smtp', ?, 'sent', ?, ?, 3)",
                'ssssss',
                [mailOf('third'), $utc($at - 3000), $utc($at - 3000), mailOf('fourth'), $utc($at - 3400), $utc($at - 3400)]
            );
            $rows = [];
            for ($n = 1; $n <= 15; $n++) {
                $rows[] = row([crowd($n)], 'Q11 window ' . $n);
            }
            child(['task' => 'add', 'messages' => $rows] + $clock($at + 290));
            $ran  = runQueue($clock($at + 300));
            $mail = mailSince('accept');
            ok(
                $what . ': five minutes after the change the hour holds the 4 sent 50 minutes before it and not the 3 sent 57 minutes before, so the job sends exactly 11 of 15',
                ($ran['sent'] ?? -1) === 11 && count($mail['messages']) === 11 && count(queued("status = 'queued'")) === 4,
                json_encode($ran) . '; messages ' . count($mail['messages'])
            );

            // (c) A use-by moment ten minutes after the change: sent one second before it, never at it.
            startClean();
            child(['task' => 'add', 'messages' => [
                row([mailOf('other')], 'Q11 use-by one', ['expiresAt' => $utc($at + 600)]), row([mailOf('third')], 'Q11 use-by two', ['expiresAt' => $utc($at + 600)]),
            ]] + $clock($at + 500));
            $before = runQueue(['max' => 1] + $clock($at + 599));
            $onIt   = runQueue($clock($at + 600));
            $mail   = mailSince('accept');
            ok(
                $what . ': a message whose use-by moment is ten minutes after the change is sent one second before that moment, and at the moment itself the other is expired and not sent',
                ($before['sent'] ?? -1) === 1 && ($onIt['sent'] ?? -1) === 0 && ($onIt['expired'] ?? -1) === 1 && count($mail['messages']) === 1,
                'one second before ' . json_encode($before) . '; at the moment ' . json_encode($onIt)
            );

            // (d) Shown to a person: in the zone, with the zone's short name, which is what tells the two sides of the change apart.
            $facts = child(['task' => 'facts', 'moments' => [[$utc($at - 1800), $zone], [$utc($at + 1800), $zone]], 'healthZone' => $zone] + $clock($at + 600));
            $shown = (array) ($facts['shown'] ?? []);
            preg_match('/(\d\d):(\d\d) ([A-Z]{3,4})\z/', (string) ($shown[0] ?? ''), $one);
            preg_match('/(\d\d):(\d\d) ([A-Z]{3,4})\z/', (string) ($shown[1] ?? ''), $two);
            // A fact about every clock change, whatever the zone: half an hour
            // either side of the clocks going BACK reads as the same time of
            // day; either side of their going FORWARD, two hours apart.
            $apart = (count($one) === 4 && count($two) === 4) ? (((int) $two[1] * 60 + (int) $two[2]) - ((int) $one[1] * 60 + (int) $one[2]) + 1440) % 1440 : -1;
            ok(
                $what . ': a moment is shown in that zone with the zone\'s short name beside it; the two sides of the change read ' . ($way === 'back' ? 'as the SAME time of day' : 'two hours apart') . ' and are told apart by that name, and the health line names it too',
                $apart === ($way === 'back' ? 0 : 120) && $one[3] !== $two[3] && $one[3] !== 'UTC' && $two[3] !== 'UTC'
                    && preg_match('/last ran at [^.]* ' . preg_quote((string) $two[3], '/') . '\./', (string) ($facts['health']['detail'] ?? '')) === 1,
                json_encode($shown) . '; health: ' . json_encode($facts['health']['detail'] ?? $facts['threw'])
            );
        }
        if (count($picked) !== 2) {
            ok($zone . ': a change in each direction was found in the next 400 days', false, json_encode($picked));
        }
    }
}

// =============================================================================
if (part('X1', 'The four numbers: per organisation, the installation\'s as the default, and a bad value falling back') === true) {
// =============================================================================
    $own = static function (string $name, string $value): void {
        q("DELETE FROM tblSettings WHERE siteID = 1 AND settingKey = ?", 's', ['mail.queue.' . $name]);
        q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (1, ?, ?, '', 0)", 'ss', ['mail.queue.' . $name, $value]);
    };
    setting('mail.queue.retryHours', '30');
    $own('holdDays', '3');
    $forOrg  = child(['task' => 'numbers', 'siteId' => 1]);
    $forNone = child(['task' => 'numbers', 'siteId' => null]);
    ok(
        'each number is the organisation\'s own where it has one, and the installation\'s otherwise; an organisation\'s value does not reach mail that belongs to no organisation',
        ($forOrg['numbers'] ?? null) === ['retryHours' => 30, 'maxAgeHours' => 48, 'keepFailedDays' => 7, 'holdDays' => 3]
            && ($forNone['numbers'] ?? null) === ['retryHours' => 30, 'maxAgeHours' => 48, 'keepFailedDays' => 7, 'holdDays' => 7] && ($forOrg['problems'] ?? null) === [],
        json_encode([$forOrg['numbers'] ?? $forOrg['threw'], $forNone['numbers'] ?? null, $forOrg['problems'] ?? null])
    );
    // Values that are not sensible numbers, in both places.
    setting('mail.queue.keepFailedDays', '9');
    $own('keepFailedDays', 'three days');
    $own('maxAgeHours', '0');
    $own('holdDays', '');
    setting('mail.queue.retryHours', 'lots');
    setting('mail.queue.maxAgeHours', '9999');
    $bad = child(['task' => 'numbers', 'siteId' => 1]);
    $said = implode(' | ', (array) ($bad['problems'] ?? []));
    ok(
        'a value that is not a whole number in range is NOT used: the organisation\'s falls back to the installation\'s, the installation\'s to the built-in default, an empty one is simply "not set", and each one passed over is said in a sentence',
        ($bad['numbers'] ?? null) === ['retryHours' => 24, 'maxAgeHours' => 48, 'keepFailedDays' => 9, 'holdDays' => 7] && count((array) ($bad['problems'] ?? [])) === 4
            && str_contains($said, "mail.queue.keepFailedDays (how many days a failed message is kept) of organisation number 1 is not a whole number from 1 to 365, so the installation's value of 9 is used") === true
            && str_contains($said, 'mail.queue.retryHours (how many hours a message is tried for) for the whole installation is not a whole number from 1 to 720, so the default of 24 is used') === true
            && str_contains($said, 'mail.queue.maxAgeHours') === true && str_contains($said, 'mail.queue.holdDays') === false,
        json_encode($bad['numbers'] ?? $bad['threw']) . ' / ' . $said
    );
    $health = json_decode(req('', 'GET', '/cron/health?token=' . $jobToken, null, ip(2))['body'], true);
    $line   = (array) ($health['probes']['Mail queue'] ?? []);
    ok(
        'the health page says so, as a warning, naming each setting and what is used in its place',
        ($line['state'] ?? '') === 'warn' && str_contains((string) ($line['detail'] ?? ''), 'mail.queue.retryHours') === true && str_contains((string) $line['detail'], 'so the default of 24 is used') === true,
        json_encode($line)
    );
    // And each number is USED: the organisation's for its own mail, the installation's for the rest.
    startClean();
    foreach (['retryHours' => '1', 'maxAgeHours' => '1', 'keepFailedDays' => '1', 'holdDays' => '1'] as $name => $value) {
        $own($name, $value);
    }
    child(['task' => 'add', 'messages' => [
        row([mailOf('other')], 'X1 too old, organisation'), row([mailOf('other')], 'X1 too old, none', ['siteId' => null]),
        row([mailOf('other')], 'X1 failed, organisation'), row([mailOf('other')], 'X1 failed, none', ['siteId' => null]),
        row([mailOf('other')], 'X1 held, organisation', ['status' => 'held', 'holdReason' => 'unknown-if-sent']),
        row([mailOf('other')], 'X1 held, none', ['siteId' => null, 'status' => 'held', 'holdReason' => 'unknown-if-sent']),
    ]]);
    q("UPDATE tblMailQueue SET createdAt = createdAt - INTERVAL 3700 SECOND WHERE subject LIKE 'X1 too old%'");
    q("UPDATE tblMailQueue SET status = 'failed', statusAt = statusAt - INTERVAL 90000 SECOND WHERE subject LIKE 'X1 failed%'");
    q("UPDATE tblMailQueue SET statusAt = statusAt - INTERVAL 90000 SECOND WHERE subject LIKE 'X1 held%'");
    $ran  = runQueue();
    $mail = mailSince('accept');
    ok(
        'with the organisation\'s numbers all set to 1: its message an hour old is failed as too old, and the same message belonging to no organisation is sent (48 hours)',
        (rowOf('X1 too old, organisation')['status'] ?? '') === 'failed' && str_contains((string) rowOf('X1 too old, organisation')['lastError'], 'waited longer than 1 hours') === true
            && rowOf('X1 too old, none') === null && count($mail['messages']) === 1,
        json_encode($ran) . '; ' . json_encode(rowOf('X1 too old, organisation')['lastError'] ?? null) . '; messages ' . count($mail['messages'])
    );
    ok(
        'its failed message a day old is deleted, and the other is kept (7 days); its held message a day old is failed, and the other stays held (7 days)',
        rowOf('X1 failed, organisation') === null && (rowOf('X1 failed, none')['status'] ?? '') === 'failed'
            && (rowOf('X1 held, organisation')['status'] ?? '') === 'failed' && str_contains((string) rowOf('X1 held, organisation')['lastError'], 'held for 1 days') === true && (rowOf('X1 held, none')['status'] ?? '') === 'held',
        json_encode(array_column(queued(), 'status', 'subject'))
    );
    startClean();
    $own('retryHours', '1');
    serverDown();
    child(['task' => 'add', 'messages' => [row([mailOf('other')], 'X1 retry, organisation', ['attempts' => 2]), row([mailOf('other')], 'X1 retry, none', ['siteId' => null, 'attempts' => 2])]]);
    later(1000);
    runQueue();
    runQueue();
    ok(
        'a third try that fails 1,000 seconds in: the organisation\'s message, which may be tried for one hour, is failed (its next try would be past the hour); the other waits its hour and is tried again',
        (rowOf('X1 retry, organisation')['status'] ?? '') === 'failed' && (int) rowOf('X1 retry, organisation')['attempts'] === 3
            && (rowOf('X1 retry, none')['status'] ?? '') === 'queued' && (int) rowOf('X1 retry, none')['waitGiven'] === 3600,
        json_encode(array_map(static fn (array $r): array => [$r['subject'], $r['status'], $r['attempts']], queued()))
    );
}

// =============================================================================
if (part('X2', 'A refused sign-in, across runs of the job') === true) {
// =============================================================================
    useServer('other-password'); // this mailbox wants another password than the one the portal has stored
    child(['task' => 'add', 'messages' => [row([mailOf('other')], 'X2 one'), row([mailOf('third')], 'X2 two'), row([mailOf('fourth')], 'X2 three')]]);
    $first = runQueue();
    $seen1 = mailSince('other-password');
    $state1 = one('SELECT problem, tries, TIMESTAMPDIFF(SECOND, problemSeenAt, nextTryAt) AS wait FROM tblMailRouteState');
    ok(
        'the first run signs in ONCE, is refused, and stops: all three messages wait with no attempt counted against any of them, and the refusal is on record with a wait of 15 minutes',
        $seen1['signIns'] === 1 && $seen1['connections'] === 1 && $seen1['messages'] === [] && ($first['stopped'] ?? 0) === 1 && ($first['failed'] ?? -1) === 0
            && array_column(queued(), 'status') === ['queued', 'queued', 'queued'] && array_sum(array_column(queued(), 'attempts')) === 0
            && $state1 !== null && $state1['problem'] === 'sign-in-refused' && (int) $state1['tries'] === 1 && (int) $state1['wait'] === 900,
        'sign-ins ' . $seen1['signIns'] . '; ' . json_encode($first) . '; on record ' . json_encode($state1)
    );
    $second = runQueue();
    $sent   = child(['task' => 'send', 'messages' => [msg(mailOf('other'), 'X2 sent from a page meanwhile')]]);
    $seen2  = mailSince('other-password');
    $log    = one("SELECT status, errorCode FROM tblEmailLog WHERE subject = 'X2 sent from a page meanwhile'");
    ok(
        'the next run, and a page that sends meanwhile, do NOT contact the mail server at all: the page\'s message is kept ("queued") and the send log says why',
        $seen2['connections'] === 0 && ($second['stopped'] ?? 0) === 1 && ($sent['each'][0] ?? null) !== null && $sent['each'][0][1] === 'queued' && $log === ['status' => 'queued', 'errorCode' => 'sign-in-not-repeated'],
        'connections ' . $seen2['connections'] . '; the page\'s message ' . json_encode($sent['each'] ?? $sent['threw']) . '; log ' . json_encode($log)
    );
    $health = json_decode(req('', 'GET', '/cron/health?token=' . $jobToken, null, ip(2))['body'], true);
    $line   = (array) ($health['probes']['Mail queue'] ?? []);
    ok(
        'administrators are told: the health page\'s mail queue line is CRITICAL, says the sign-in was refused, how much is waiting and what to do, and holds no address',
        ($line['state'] ?? '') === 'crit' && ($health['overall'] ?? '') === 'crit' && str_contains((string) ($line['detail'] ?? ''), 'refused the mailbox\'s user name and password') === true
            && str_contains((string) $line['detail'], '4 waiting') === true && str_contains((string) $line['detail'], 'Admin → Integrations → Email') === true && str_contains((string) $line['detail'], '@') === false,
        json_encode($line)
    );
    // The waits: 15 minutes, then an hour. (The ages are set a little inside each wait and then past it: see Q5.)
    $signIns = [];
    foreach ([880, 25, 3560, 50] as $step) {
        later($step);
        runQueue();
        $signIns[] = mailSince('other-password')['signIns'];
    }
    $state2 = one('SELECT tries, TIMESTAMPDIFF(SECOND, problemSeenAt, nextTryAt) AS wait FROM tblMailRouteState');
    ok(
        'the job signs in again only when the wait is over, and the wait grows: nothing at 14 minutes 40 seconds, ONE sign-in just past 15 minutes; then nothing for most of an hour, and ONE just past it. Three sign-ins in all, where there used to be one for every message of every run',
        $signIns === [0, 1, 0, 1] && $state2 !== null && (int) $state2['tries'] === 3 && (int) $state2['wait'] === 10800 && count(queued("status = 'queued'")) === 4,
        'sign-ins after each step ' . json_encode($signIns) . '; on record ' . json_encode($state2)
    );
    // The administrator stores the password the mailbox wants. Nothing else is pressed.
    setting('mail.smtp.password', sealed($otherPassword, $keyText), 1);
    $fixed = runQueue();
    $mail  = mailSince('other-password');
    $health = json_decode(req('', 'GET', '/cron/health?token=' . $jobToken, null, ip(2))['body'], true);
    ok(
        'once the right password is stored the very next run sends all four waiting messages, the refusal is off the record, and the health line is no longer critical',
        ($fixed['sent'] ?? 0) === 4 && count($mail['messages']) === 4 && queued() === [] && (one('SELECT problem FROM tblMailRouteState')['problem'] ?? 'x') === ''
            && in_array($health['probes']['Mail queue']['state'] ?? '', ['ok', 'warn'], true) === true,
        json_encode($fixed) . '; messages ' . count($mail['messages']) . '; health ' . json_encode($health['probes']['Mail queue']['state'] ?? null)
    );
}

// =============================================================================
if (part('X3', '"Forgot password" on a web server that cannot answer before it sends') === true) {
// =============================================================================
    // `php-cgi` hands its whole answer over when the script ends, as PHP run by
    // a web server through CGI does. The mail server is UP throughout: if the
    // page sent in the request, the message would be there when it ends.
    $ask = static function (int $last, string $email): array {
        $form   = cgi('GET', '/forgot-password', [], '', ip($last));
        $answer = cgi('POST', '/forgot-password/save', ['csrf_token' => token($form['body']), 'email' => $email], $form['cookie'], ip($last));

        return $answer + ['formStatus' => $form['status']];
    };
    $known = $ask(71, mailOf('member'));
    $mail  = mailSince('accept');
    $row   = queued()[0] ?? null;
    $link  = one('SELECT expiresAt FROM tblPasswordResets WHERE userID = ? AND usedAt IS NULL ORDER BY resetID DESC LIMIT 1', 'i', [PEOPLE['member']]);
    ok(
        'for an address with an account the page answers as always and sends NOTHING in the request: the mail server is not contacted, and the reset is in the queue, urgent, not yet tried, with its link\'s own expiry',
        $known['formStatus'] === 200 && $known['status'] === 302 && str_ends_with($known['location'], '/forgot-password?sent=1') === true && $mail['connections'] === 0
            && $row !== null && $row['status'] === 'queued' && (int) $row['priority'] === 1 && (int) $row['attempts'] === 0 && (int) $row['repeatSafe'] === 1
            && $link !== null && $row['expiresAt'] === $link['expiresAt'] && records(ip(71), 'PasswordResetMailWaiting') === 1,
        'HTTP ' . $known['status'] . ' to ' . $known['location'] . '; connections ' . $mail['connections'] . '; the row ' . json_encode($row === null ? null : [$row['status'], $row['priority'], $row['attempts'], $row['expiresAt']])
    );
    $unknown = $ask(72, 'nobody-here@' . DOMAIN);
    $same    = static fn (array $a): string => $a['status'] . "\n" . preg_replace('/^(Set-Cookie|Date):.*$/mi', '', $a['head']) . "\n" . $a['body'];
    ok(
        'an address with no account gets exactly the same answer: the same status, address, headers and body',
        $same($unknown) === $same($known) && count(queued()) === 1,
        "known:\n" . $same($known) . "\nunknown:\n" . $same($unknown)
    );
    $ran  = $job('right');
    $mail = mailSince('accept');
    $got  = $mail['messages'][0] ?? null;
    $page = $got === null || preg_match('#http://127\.0\.0\.1:' . $port . '(/reset-password\?token=[0-9a-f]{64})#', $got['html'], $l) !== 1 ? null : req('', 'GET', $l[1], null, ip(71));
    ok(
        'the job\'s next run delivers it, to the right person, with a link that works',
        ($ran['json']['sent'] ?? 0) === 1 && $got !== null && $got['to'] === [mailOf('member')] && $page !== null && $page['status'] === 200 && str_contains($page['body'], 'name="password"') === true,
        'the job answered ' . $ran['body'] . '; the link\'s page ' . json_encode($page['status'] ?? null)
    );
    // How long the whole script took, for the two kinds of address. NOT a
    // check: timing on a busy machine is not a proof. It is printed so that
    // what is left of the difference can be seen.
    $times = ['known' => [], 'unknown' => []];
    for ($n = 0; $n < 5; $n++) {
        $times['known'][]   = $ask(73 + $n, mailOf('member'))['ms'];
        $times['unknown'][] = $ask(83 + $n, 'nobody-here@' . DOMAIN)['ms'];
    }
    sort($times['known']);
    sort($times['unknown']);
    note('through php-cgi, the whole script took (median of 5): ' . $times['known'][2] . ' ms for an address with an account, ' . $times['unknown'][2]
        . ' ms for one without. Neither includes a mail server. The difference is the database work a known address causes (storing the link and the waiting message).');
}

// =============================================================================
if (part('X5', 'A certificate that fails: mail is held, administrators are told, and it goes by itself once the certificate passes') === true) {
// =============================================================================
    useServer('cert-heals');
    $first = child(['task' => 'send', 'messages' => [msg(mailOf('other'), 'X5 the first, which meets the certificate')]]);
    $seen1 = mailSince('cert-heals');
    $next  = child(['task' => 'send', 'messages' => [msg(mailOf('third'), 'X5 the second, sent while it is on record')]]);
    $seen2 = mailSince('cert-heals');
    $rows  = queued();
    $state1 = one('SELECT problem, problemWords, certReasons, certSha256, certValidTo, certNames FROM tblMailRouteState');
    ok(
        'the first message is HELD, not sent and not dropped, with no sign-in offered; the second is held WITHOUT the mail server being contacted; both say why',
        ($first['each'][0][1] ?? '') === 'held' && ($first['each'][0][0] ?? null) === true && $seen1['signIns'] === 0 && $seen1['messages'] === []
            && ($next['each'][0][1] ?? '') === 'held' && $seen2['connections'] === 0
            && array_column($rows, 'status') === ['held', 'held'] && array_column($rows, 'holdReason') === ['certificate', 'certificate'] && str_contains((string) $rows[1]['lastError'], 'The certificate expired on') === true,
        json_encode([$first['each'] ?? $first['threw'], $next['each'] ?? $next['threw']]) . '; connections for the second ' . $seen2['connections'] . '; rows ' . json_encode(array_column($rows, 'holdReason'))
    );
    ok(
        'what the later certificate page needs is on record for the route: the reason, the certificate\'s fingerprint, its dates and its names',
        $state1 !== null && $state1['problem'] === 'certificate' && str_contains((string) $state1['certReasons'], 'expired') === true && preg_match('/\A[0-9a-f]{64}\z/', (string) $state1['certSha256']) === 1
            && $state1['certValidTo'] !== null && str_contains((string) $state1['certNames'], MAIL_HOST) === true && str_contains((string) $state1['problemWords'], 'The certificate expired on') === true,
        json_encode($state1)
    );
    $health = json_decode(req('', 'GET', '/cron/health?token=' . $jobToken, null, ip(2))['body'], true);
    $line   = (array) ($health['probes']['Mail queue'] ?? []);
    ok(
        'administrators are told on the health page: CRITICAL, the certificate failed, what the check found, and that 2 are held',
        ($line['state'] ?? '') === 'crit' && str_contains((string) ($line['detail'] ?? ''), 'certificate failed its check') === true && str_contains((string) $line['detail'], 'The certificate expired on') === true
            && str_contains((string) $line['detail'], '2 held') === true && str_contains((string) $line['detail'], '@') === false,
        json_encode($line)
    );
    // The job: ONE checked connection a run. The certificate is still bad on this one ...
    $ran1  = runQueue();
    $seen3 = mailSince('cert-heals');
    ok(
        'a run of the job tries the certificate ONCE (one attempt, with no sign-in, and the connection that reads the certificate), sends nothing, and both messages are still held',
        $seen3['connections'] === 2 && $seen3['signIns'] === 0 && $seen3['messages'] === [] && ($ran1['sent'] ?? -1) === 0 && array_column(queued(), 'status') === ['held', 'held'],
        json_encode($ran1) . '; connections ' . $seen3['connections']
    );
    // ... and good on the next: nobody presses anything.
    $ran2  = runQueue();
    $seen4 = mailSince('cert-heals');
    $health = json_decode(req('', 'GET', '/cron/health?token=' . $jobToken, null, ip(2))['body'], true);
    ok(
        'once the certificate passes, the held mail goes BY ITSELF in that same run: both messages are delivered once, the fault is off the record, and the health line is no longer critical',
        ($ran2['sent'] ?? 0) === 2 && count($seen4['messages']) === 2 && queued() === [] && (one('SELECT problem FROM tblMailRouteState')['problem'] ?? 'x') === ''
            && in_array($health['probes']['Mail queue']['state'] ?? '', ['ok', 'warn'], true) === true,
        json_encode($ran2) . '; messages ' . count($seen4['messages'])
    );
    // A held reset is never sent after its link has run out, even when the certificate is then put right.
    startClean();
    useServer('cert-expired');
    askForLink(ip(91), mailOf('member'));
    $held = queued()[0] ?? null;
    later(3601);
    useServer('accept'); // the mail settings are changed to a server whose certificate is good
    $ran  = runQueue();
    $mail = mailSince('accept');
    ok(
        'a reset held for the certificate is marked expired once its link has run out, and is not sent when the certificate is put right after that',
        $held !== null && $held['status'] === 'held' && $held['holdReason'] === 'certificate' && ($ran['expired'] ?? 0) === 1 && $mail['messages'] === [] && (queued()[0]['status'] ?? '') === 'expired',
        json_encode($held === null ? null : [$held['status'], $held['holdReason']]) . '; ' . json_encode($ran)
    );
}

// =============================================================================
if (part('F1', 'With NO scheduled job the portal does as it did before the queue; only the job\'s own page records that the job ran (H1, M1, L4)') === true) {
// =============================================================================
    // "Forgot password" through `php-cgi`, as X3 asks it: PHP as Apache runs it,
    // where the whole answer is held back until the script has ended.
    $ask = static function (int $last, string $email): array {
        $form   = cgi('GET', '/forgot-password', [], '', ip($last));
        $answer = cgi('POST', '/forgot-password/save', ['csrf_token' => token($form['body']), 'email' => $email], $form['cookie'], ip($last));

        return $answer + ['formStatus' => $form['status']];
    };
    $same = static fn (array $a): string => $a['status'] . "\n" . preg_replace('/^(Set-Cookie|Date):.*$/mi', '', $a['head']) . "\n" . $a['body'];

    // (a) No job has EVER run. The mail server is up.
    noJob();
    $facts   = child(['task' => 'facts']);
    $known   = $ask(101, mailOf('member'));
    $mail    = mailSince('accept');
    $leftNow = queued();
    ok(
        'no job has ever run: the one question answers NO, and on php-cgi a reset for an address with an account ARRIVES in the request: it is with the mail server when the script ends, nothing is left in the queue, and the record says it was e-mailed',
        ($facts['jobIsRunning'] ?? null) === false && $known['formStatus'] === 200 && $known['status'] === 302 && count($mail['messages']) === 1 && ($mail['messages'][0]['to'] ?? []) === [mailOf('member')]
            && $leftNow === [] && records(ip(101), 'PasswordResetEmailed') === 1,
        'the question answered ' . json_encode($facts['jobIsRunning'] ?? $facts['threw']) . '; HTTP ' . $known['status'] . '; messages ' . count($mail['messages']) . '; queue rows ' . count($leftNow)
    );
    $unknownNoJob = $ask(102, 'nobody-here@' . DOMAIN);

    // (b) The job is running: as the queue was built (X3 holds the detail).
    jobRunning();
    $factsOn    = child(['task' => 'facts']);
    $knownJob   = $ask(103, mailOf('member'));
    $mailJob    = mailSince('accept');
    $kept       = queued();
    $unknownJob = $ask(104, 'nobody-here@' . DOMAIN);
    $ran        = $job('right');
    $mailRun    = mailSince('accept');
    ok(
        'the job running: the question answers YES, the same request then sends NOTHING and keeps the reset (marked as that person\'s reset), and the job\'s next run delivers it',
        ($factsOn['jobIsRunning'] ?? null) === true && $mailJob['connections'] === 0 && count($kept) === 1 && $kept[0]['origin'] === 'reset:' . PEOPLE['member']
            && ($ran['json']['sent'] ?? 0) === 1 && count($mailRun['messages']) === 1 && queued() === [],
        'the question answered ' . json_encode($factsOn['jobIsRunning'] ?? null) . '; connections in the request ' . $mailJob['connections'] . '; kept ' . count($kept) . '; the job answered ' . $ran['body']
    );
    ok(
        'what the person is shown is the SAME in all four cases (no job or a job, an account or none): the same status, address, headers and body',
        $same($known) === $same($unknownNoJob) && $same($known) === $same($knownJob) && $same($known) === $same($unknownJob),
        "no job, an account:\n" . $same($known) . "\nno job, no account:\n" . $same($unknownNoJob) . "\na job, an account:\n" . $same($knownJob) . "\na job, no account:\n" . $same($unknownJob)
    );

    // (b2) THE 15 MINUTES THEMSELVES. The moment the job's own page records is
    //      set by hand, on the database's clock, and the one question is asked
    //      by a new process each time. (Nothing held this rule before: with
    //      "any moment at all counts as running" every check still passed.)
    $answers = [];
    foreach ([
        '14 minutes 40 seconds ago' => 'UTC_TIMESTAMP() - INTERVAL 880 SECOND', '15 minutes 20 seconds ago' => 'UTC_TIMESTAMP() - INTERVAL 920 SECOND',
        'yesterday' => 'UTC_TIMESTAMP() - INTERVAL 86400 SECOND', 'an hour in the FUTURE' => 'UTC_TIMESTAMP() + INTERVAL 3600 SECOND', 'an empty moment' => 'NULL',
    ] as $when => $moment) {
        jobRunning();
        $db->query('UPDATE tblMailRouteState SET jobLastRanAt = ' . $moment);
        $answers[$when] = child(['task' => 'facts'])['jobIsRunning'] ?? null;
    }
    q('DELETE FROM tblMailRouteState');
    $answers['no record at all'] = child(['task' => 'facts'])['jobIsRunning'] ?? null;
    ok(
        'the 15 minutes are kept: a job that last ran 14 minutes 40 seconds ago counts as running; one that ran 15 minutes 20 seconds ago, or yesterday, does NOT; nor does a moment in the FUTURE (a clock since put right), an empty moment, or no record at all',
        $answers === ['14 minutes 40 seconds ago' => true, '15 minutes 20 seconds ago' => false, 'yesterday' => false, 'an hour in the FUTURE' => false, 'an empty moment' => false, 'no record at all' => false],
        (string) json_encode($answers)
    );

    // (c) One refused sign-in, with no job. It used to stop all mail for good:
    //     only the job ever tried the mail server again.
    startClean();
    noJob();
    useServer('other-password');
    $page   = static fn (string $subject): array => child(['task' => 'send', 'messages' => [msg(mailOf('other'), $subject)]]);
    $first  = $page('F1 the first, which meets the refusal');
    $inside = $page('F1 the second, inside the wait');
    $seenAB = mailSince('other-password');
    later(905); // the 15 minutes are over
    $third  = $page('F1 the third, after the wait');
    $seenC  = mailSince('other-password');
    $record = one('SELECT tries, TIMESTAMPDIFF(SECOND, problemSeenAt, nextTryAt) AS wait FROM tblMailRouteState');
    later(3605); // the hour that followed is over too
    jobRunning();
    $fourth = $page('F1 the fourth, after the next wait, WITH a job running');
    $seenD  = mailSince('other-password');
    noJob();
    $fifth  = $page('F1 the fifth, after the next wait, with no job');
    $seenE  = mailSince('other-password');
    $became = array_map(static fn (array $r): string => (string) ($r['each'][0][1] ?? $r['threw']), [$first, $inside, $third, $fourth, $fifth]);
    ok(
        'after ONE refused sign-in with no job, mail is not dead: a page sending inside the 15 minutes does not contact the mail server; the first page to send after them DOES sign in again (and, refused again, the wait grows to an hour); with a job running that try is left to the job; nothing is lost meanwhile (all five messages are kept)',
        $seenAB['signIns'] === 1 && $seenAB['connections'] === 1 && $seenC['signIns'] === 1 && $record !== null && (int) $record['tries'] === 2 && (int) $record['wait'] === 3600
            && $seenD['connections'] === 0 && $seenE['signIns'] === 1 && $became === array_fill(0, 5, 'queued') && count(queued("status = 'queued'")) === 5,
        'sign-ins: first two ' . $seenAB['signIns'] . ', after the wait ' . $seenC['signIns'] . ', with a job (connections) ' . $seenD['connections'] . ', with no job ' . $seenE['signIns'] . '; on record ' . json_encode($record) . '; ' . json_encode($became)
    );
    setting('mail.smtp.password', sealed($otherPassword, $keyText), 1); // the administrator stores the password the mailbox wants
    $sixth = $page('F1 the sixth, once the right password is stored');
    $seenF = mailSince('other-password');
    ok(
        'and once the right password is stored, the very next page that sends gets its message through at once, with no job',
        ($sixth['each'][0][1] ?? '') === 'sent' && count($seenF['messages']) === 1 && (one('SELECT problem FROM tblMailRouteState')['problem'] ?? 'x') === '',
        json_encode($sixth['each'] ?? $sixth['threw']) . '; messages ' . count($seenF['messages'])
    );

    // (c2) TWELVE pages at the same moment, once the wait is over. Each used
    //      to see "the wait is over" and sign in: twelve refused sign-ins in a
    //      second, which is what gets a mailbox locked. Each is a process of
    //      its own; all twelve are started, given time to be ready, and then
    //      let go together by one starting file.
    startClean();
    noJob();
    useServer('other-password');
    $page('F1 one page meets the refusal');
    mailSince('other-password');
    later(905);
    $go   = $child . $sep . 'go-' . bin2hex(random_bytes(4));
    $kids = [];
    for ($n = 1; $n <= 12; $n++) {
        $kids[] = startChild(['task' => 'send', 'messages' => [msg(mailOf('other'), 'F1 one of twelve at once, number ' . $n)], 'waitFor' => $go]);
    }
    usleep(6000000);
    touch($go);
    $became = [];
    foreach ($kids as $kid) {
        $done     = finishChild($kid);
        $became[] = (string) ($done['each'][0][1] ?? $done['threw']);
    }
    unlink($go);
    $seen   = mailSince('other-password');
    $record = one('SELECT tries FROM tblMailRouteState');
    ok(
        'twelve pages that send at the same moment, once the wait is over, make ONE sign-in between them, not twelve: one request claims the try, the other eleven do not contact the mail server, and all twelve messages are kept',
        $seen['signIns'] === 1 && $seen['connections'] === 1 && $became === array_fill(0, 12, 'queued') && (int) ($record['tries'] ?? 0) === 2 && count(queued("status = 'queued'")) === 13,
        'sign-ins ' . $seen['signIns'] . ', connections ' . $seen['connections'] . '; ' . json_encode(array_count_values($became)) . '; refusals on record ' . json_encode($record['tries'] ?? null) . '; kept ' . count(queued("status = 'queued'"))
    );

    // (d) A broadcast to 60 with no job. It used to reach 25 and leave 35 waiting for ever.
    startClean();
    noJob();
    $press = static fn (string $subject): array => req($rootJar, 'POST', '/calendar/event/broadcast/send', [
        'csrf_token' => anyToken($rootJar, ip(1)), 'eventID' => (string) EVENT_SIXTY, 'segment' => 'all-rsvps', 'subject' => $subject, 'body' => 'A broadcast to sixty people.',
    ], ip(1));
    $all    = $press('F1 broadcast to sixty');
    $said   = words(req($rootJar, 'GET', $all['location'], null, ip(1))['body']);
    $mail   = mailSince('accept');
    $jobRow = one('SELECT jobLastRanAt FROM tblMailRouteState');
    ok(
        'a broadcast to 60 with no job reaches ALL 60 in the request (there is no stop at 25), and the page says so and speaks of nothing waiting',
        $all['status'] === 302 && count($mail['messages']) === 60 && queued() === [] && str_contains($said, 'Sent to 60 of 60 recipients.') === true
            && str_contains($said, 'queued') === false && str_contains($said, 'WAITING') === false,
        'HTTP ' . $all['status'] . '; messages ' . count($mail['messages']) . '; left ' . count(queued()) . '; the page said: ' . (preg_match('/Sent to [^.]*\.[^.]*\./', $said, $m) === 1 ? $m[0] : '(nothing)')
    );
    ok(
        'that broadcast made a run of the queue of its own, and it is NOT on record as the scheduled job having run: only the job\'s own page records that',
        $jobRow !== null && $jobRow['jobLastRanAt'] === null,
        'on record: ' . json_encode($jobRow)
    );

    // (e) The same with an hourly limit that leaves some waiting.
    setting('mail.limit.perHour', '100'); // ordinary mail may use 90 an hour, and 60 have just gone
    $some  = $press('F1 broadcast to sixty, in other words');
    $said2 = words(req($rootJar, 'GET', $some['location'], null, ip(1))['body']);
    $mail2 = mailSince('accept');
    $line  = healthLine();
    ok(
        'with room for only 30 more this hour, 30 go and 30 wait; the page says exactly how many wait and WHY they will not go (the scheduled job has not run), and does NOT say "there is no need to send this again"',
        count($mail2['messages']) === 30 && count(queued("status = 'queued'")) === 30 && str_contains($said2, 'Sent to 30 of 60 recipients.') === true
            && str_contains($said2, '30 are WAITING and will not be sent yet') === true && str_contains($said2, 'scheduled job') === true && str_contains($said2, 'has not run in the last 15 minutes') === true
            && str_contains($said2, 'no need to send this again') === false,
        'messages ' . count($mail2['messages']) . '; waiting ' . count(queued("status = 'queued'")) . '; the page said: ' . (preg_match('/Sent to .*?go\./', $said2, $m) === 1 ? $m[0] : mb_substr($said2, 0, 300))
    );
    $detail = (string) ($line['detail'] ?? '');
    ok(
        'the health page\'s mail queue line is then CRITICAL, not a warning: 30 messages are waiting and will not be sent, the job has never run and must be added, and where the line for the scheduler is; it holds no address',
        ($line['state'] ?? '') === 'crit' && str_contains($detail, '30 messages are waiting in the mail queue and will NOT be sent') === true && str_contains($detail, 'has never run') === true
            && str_contains($detail, 'Add the scheduled job') === true && str_contains($detail, 'Help → Admin Guide') === true && str_contains($detail, 'developer notes') === false && str_contains($detail, '@') === false,
        json_encode($line)
    );

    // (f) Nothing waiting: the warning that there is no job must stay.
    startClean();
    noJob();
    $quiet = healthLine();
    jobRunning();
    $fine  = healthLine();
    ok(
        'with nothing waiting and no job the line is a WARNING that still says the job has never run and must be added (so that it is added before mail waits); with the job running it is fine and says when the job last ran',
        ($quiet['state'] ?? '') === 'warn' && str_contains((string) ($quiet['detail'] ?? ''), 'has never run') === true && str_contains((string) $quiet['detail'], 'Add the scheduled job') === true
            && ($fine['state'] ?? '') === 'ok' && str_contains((string) ($fine['detail'] ?? ''), 'last ran at') === true && str_contains((string) $fine['detail'], 'Add the scheduled job') === false,
        'no job: ' . json_encode($quiet) . '; a job: ' . json_encode($fine)
    );

    // (g) The job's address on a PRE-RELEASE copy of the portal. The staff
    //     gate used to send the scheduler to the sign-in page, token and all.
    setting('portal.gatekeeper.enabled', 'true');
    child(['task' => 'add', 'messages' => [row([mailOf('other')], 'F1 waiting on a beta copy')]]);
    $beta   = ['PORTAL_ENV' => 'beta'];
    $none   = cgi('GET', '/cron/mail-queue', [], '', ip(2), $beta);
    $wrong  = cgi('GET', '/cron/mail-queue?token=' . strrev($jobToken), [], '', ip(2), $beta);
    $right  = cgi('GET', '/cron/mail-queue?token=' . $jobToken, [], '', ip(2), $beta);
    $mailB  = mailSince('accept');
    $other  = cgi('GET', '/cron/health?token=' . $jobToken, [], '', ip(2), $beta);
    $member = cgi('GET', '/dashboard', [], '', ip(2), $beta);
    ok(
        'on a beta copy the job\'s address is let past the staff gate and checks its own token: no token and a wrong one are answered 403 "forbidden" (not sent to the sign-in page), the right one runs the job and the waiting message goes; an ordinary page there is still sent to the sign-in page',
        $none['status'] === 403 && json_decode($none['body'], true) === ['status' => 'forbidden'] && $wrong['status'] === 403 && $wrong['location'] === ''
            && $right['status'] === 200 && (json_decode($right['body'], true)['sent'] ?? 0) === 1 && count($mailB['messages']) === 1
            && $member['status'] === 302 && str_contains($member['location'], 'login') === true,
        'no token: HTTP ' . $none['status'] . ' ' . $none['body'] . '; wrong: HTTP ' . $wrong['status'] . ' ' . $wrong['location'] . '; right: HTTP ' . $right['status'] . ' ' . $right['body'] . '; an ordinary page: HTTP ' . $member['status'] . ' to ' . $member['location']
    );
    // ONLY that one. Two other scheduled jobs, each called with its own right
    // token, on the same beta copy. (This was a note, not a check: a second
    // job added to the gate's open list went unnoticed.)
    $reminders = cgi('GET', '/cron/event-reminders?key=' . $jobToken, [], '', ip(2), $beta);
    ok(
        'ONLY that one job is let past the staff gate: on the same beta copy two other scheduled jobs (cron/health and cron/event-reminders), each called with its right token, are still sent to the sign-in page, as they were before this package',
        $other['status'] === 302 && str_contains($other['location'], 'login') === true && $reminders['status'] === 302 && str_contains($reminders['location'], 'login') === true,
        'cron/health: HTTP ' . $other['status'] . '; cron/event-reminders: HTTP ' . $reminders['status']
    );
}

// =============================================================================
if (part('F2', 'A record says "waiting", not "sent", for mail that was only kept, and is corrected when the message goes or fails (M2)') === true) {
// =============================================================================
    // --- A newsletter. Its third recipient's address is one the mail server will refuse outright. ---
    q("INSERT INTO tblNewsletter (newsletterID, siteID, title, subject, status) VALUES (?, 1, 'Selftest queue newsletter', 'F2 newsletter', 'sending')", 'i', [NEWSLETTER]);
    foreach (['other' => mailOf('other'), 'third' => mailOf('third'), 'fourth' => 'nobody@' . DOMAIN] as $who => $address) {
        q('INSERT INTO tblNewsletterRecipient (newsletterID, userID, emailAddress, unsubToken) VALUES (?, ?, ?, ?)', 'iiss', [NEWSLETTER, PEOPLE[$who], $address, bin2hex(random_bytes(20))]);
    }
    $marks = static fn (): array => array_map(
        static fn (array $r): array => [(int) $r['delivered'], $r['errorMsg']],
        q('SELECT deliveredAt IS NOT NULL AS delivered, errorMsg FROM tblNewsletterRecipient WHERE newsletterID = ? ORDER BY recipientID', 'i', [NEWSLETTER])
    );
    $counted = static fn (): int => (int) one('SELECT sentCount FROM tblNewsletter WHERE newsletterID = ?', 'i', [NEWSLETTER])['sentCount'];
    serverDown();
    $pass1  = child(['task' => 'newsletter', 'newsletterId' => NEWSLETTER]);
    $after1 = $marks();
    $count1 = $counted();
    $kept   = queued();
    ok(
        'a newsletter pass while the mail server is down: all three copies are KEPT, the pass reports 0 sent and 3 waiting, each recipient is marked "waiting in the mail queue" and NONE as delivered, and the newsletter\'s count of copies sent stays 0',
        ($pass1['result'] ?? null) === ['sent' => 0, 'failed' => 0, 'waiting' => 3, 'notSent' => ''] && $after1 === array_fill(0, 3, [0, 'waiting in the mail queue']) && $count1 === 0
            && count($kept) === 3 && array_filter($kept, static fn (array $r): bool => preg_match('/\Anewsletter:\d+\z/', (string) $r['origin']) !== 1) === [],
        'the pass: ' . json_encode($pass1['result'] ?? $pass1['threw']) . '; recipients ' . json_encode($after1) . '; counted ' . $count1 . '; kept ' . count($kept)
    );
    $pass2 = child(['task' => 'newsletter', 'newsletterId' => NEWSLETTER]);
    useServer('refuse-recipient');
    later(300);
    $ran    = runQueue();
    $mail   = mailSince('refuse-recipient');
    $after2 = $marks();
    ok(
        'a second pass sends none of them again; when the queue then sends two and the mail server refuses the third outright, the two are marked delivered and counted, and the third is marked as failed: nothing is left saying "waiting" or "delivered" that is not so',
        ($pass2['result'] ?? null) === ['sent' => 0, 'failed' => 0, 'waiting' => 0, 'notSent' => ''] && ($ran['sent'] ?? 0) === 2 && ($ran['failed'] ?? 0) === 1 && count($mail['messages']) === 2
            && $after2 === [[1, null], [1, null], [0, 'send-failed']] && $counted() === 2,
        'the second pass: ' . json_encode($pass2['result'] ?? $pass2['threw']) . '; the run ' . json_encode($ran) . '; recipients ' . json_encode($after2) . '; counted ' . $counted()
    );

    // --- A giving statement, for last year, to two donors. ---
    startClean();
    $year = (int) date('Y') - 1; // any finished year: the dates only label the statement
    $pdf  = $makeFile('f2-statement.pdf', "%PDF-1.4\nA made-up giving statement from the mail queue self-test.\n");
    foreach ([0 => 'other', 1 => 'third'] as $n => $who) {
        q(
            'INSERT INTO tblGivingStatementLog (logID, siteID, donorID, periodKey, fromDate, toDate, pdfPath, queuedAt) VALUES (?, 1, ?, ?, ?, ?, ?, NOW())',
            'iissss',
            [STATEMENT + $n, PEOPLE[$who], $year . '-01-01_' . $year . '-12-31', $year . '-01-01', $year . '-12-31', $pdf]
        );
    }
    $logRow = static fn (int $n, string $who): array => ['logID' => STATEMENT + $n, 'siteID' => 1, 'donorID' => PEOPLE[$who], 'pdfPath' => $pdf, 'fromDate' => $year . '-01-01', 'toDate' => $year . '-12-31'];
    $stated = static fn (): array => array_map(
        static fn (array $r): array => [(int) $r['stamped'], $r['emailedTo'], $r['errorMsg']],
        q('SELECT emailedAt IS NOT NULL AS stamped, emailedTo, errorMsg FROM tblGivingStatementLog WHERE logID BETWEEN ? AND ? ORDER BY logID', 'ii', [STATEMENT, STATEMENT + 2])
    );
    serverDown();
    $one  = child(['task' => 'statement', 'logRow' => $logRow(0, 'other')]);
    $two  = child(['task' => 'statement', 'logRow' => $logRow(1, 'third')]);
    $held = $stated();
    ok(
        'a giving statement that was only KEPT is recorded as "waiting in the mail queue", with no address against it as e-mailed (its stamp stays, which is what stops a second run sending it again)',
        ($one['result'] ?? null) === true && ($one['waiting'] ?? null) === true && ($two['result'] ?? null) === true && $held === array_fill(0, 2, [1, null, 'waiting in the mail queue'])
            && count(queued()) === 2 && array_column(queued(), 'origin') === ['statement:' . STATEMENT, 'statement:' . (STATEMENT + 1)],
        json_encode([$one['result'] ?? $one['threw'], $two['result'] ?? $two['threw']]) . '; rows ' . json_encode($held) . '; kept ' . count(queued())
    );
    // A third record, for a message that will run past a USE-BY moment. No
    // statement carries one today, so the record is marked as waiting here,
    // as sendStatementEmail() marks one, and its message is put in the queue
    // with that record named on it and a use-by moment two minutes away.
    // (Nothing held "an expired message tells its record" before.)
    q(
        "INSERT INTO tblGivingStatementLog (logID, siteID, donorID, periodKey, fromDate, toDate, pdfPath, queuedAt, emailedAt, errorMsg) VALUES (?, 1, ?, ?, ?, ?, ?, NOW(), NOW(), 'waiting in the mail queue')",
        'iissss',
        [STATEMENT + 2, PEOPLE['fourth'], $year . '-01-01_' . $year . '-12-31', $year . '-01-01', $year . '-12-31', $pdf]
    );
    $soon = (string) one("SELECT DATE_FORMAT(UTC_TIMESTAMP() + INTERVAL 120 SECOND, '%Y-%m-%d %H:%i:%s') AS s")['s'];
    child(['task' => 'add', 'messages' => [row([mailOf('fourth')], 'F2 a statement with a use-by moment', ['priority' => 9, 'origin' => 'statement:' . (STATEMENT + 2), 'expiresAt' => $soon])]]);
    // The first goes. The second has waited longer than anything is ever sent, and is given up on. The third passes its use-by moment.
    q('UPDATE tblMailQueue SET createdAt = createdAt - INTERVAL 49 HOUR WHERE toRecipients = ?', 's', [mailOf('third')]);
    useServer('accept');
    later(300);
    $ran     = runQueue();
    $mail    = mailSince('accept');
    $settled = $stated();
    ok(
        'when the first goes it is recorded as e-mailed, to the address it went to; when the second is given up on as too old, and when the third passes its use-by moment, each has its stamp taken off and says "send-failed", so that a treasurer can send it again: "e-mailed" and "waiting" are left standing for none of them by mistake',
        ($ran['sent'] ?? 0) === 1 && ($ran['failed'] ?? 0) === 1 && ($ran['expired'] ?? 0) === 1 && count($mail['messages']) === 1 && str_contains($mail['messages'][0]['raw'], 'f2-statement.pdf') === true
            && $settled === [[1, mailOf('other'), null], [0, null, 'send-failed'], [0, null, 'send-failed']],
        'the run ' . json_encode($ran) . '; rows ' . json_encode($settled)
    );
}

// =============================================================================
if (part('F3', 'A use-by moment is worked out on the EVENT\'s own clock: a reminder is not thrown away early, nothing goes after its event has started, and a reminder job counts only what went (M3, M2)') === true) {
// =============================================================================
    // EVERY EVENT IN THIS PART IS MADE AS THE EVENT FORM MAKES IT: its zone is
    // in `timezone`, the one column an event's zone is kept in. None of them
    // is in London's zone. The use-by moment of a reminder and of an
    // invitation used to be read from a second column, `eventTimezone`, which
    // held "Europe/London" for every event made in the portal, and this part
    // could not see it: its one event set both columns by hand to the same
    // value. Migration 217 removed that column (#604).
    //
    // WHEN A REMINDER IS MADE. Since #604 the reminder job decides from
    // MOMENTS: the event's start, turned from its clock time and zone into
    // UTC, against the database's own UTC clock. So each event below REALLY
    // starts when its reminder says: an hour from now, and 24 hours from now,
    // on New York's clock. (The job used to compare an event's clock time with
    // the database SERVER's clock, and this part's events were written an hour
    // ahead of THAT clock to make it fire. An event in New York was then
    // reminded about four or five hours early, which this part took as given.)
    /** The database's UTC clock, as seconds since 1970, so that this part's "now" is the portal's "now". */
    $dbNow = static fn (): int => (int) one("SELECT TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', UTC_TIMESTAMP()) AS s")['s'];
    /** Does this time on a clock happen TWICE in that zone (the night the clocks go back)? Such a time has two moments, and this part must not build on one. */
    $twice = static function (string $wall, string $zone): bool {
        $tz     = new DateTimeZone($zone);
        $moment = (new DateTimeImmutable($wall, $tz))->getTimestamp();
        foreach ([-3600, -1800, 1800, 3600] as $shift) {
            if ((new DateTimeImmutable('@' . ($moment + $shift)))->setTimezone($tz)->format('Y-m-d H:i:s') === $wall) {
                return true;
            }
        }

        return false;
    };
    /** A moment, as New York's clock shows it. */
    $inNewYork = static fn (int $moment): string => (new DateTimeImmutable('@' . $moment))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d H:i:s');

    // 1. NEW YORK. It starts 60 minutes from now, so the job makes its
    //    "starting soon" reminder in this run.
    $soonAt = $dbNow() + 3600;
    $soon   = $inNewYork($soonAt);
    // 2. An event that starts 24 hours from now, also in New York, with one
    //    person going: the "is tomorrow" reminder. (86,400 seconds is a
    //    length of time here, not "a day": the job's window is counted the
    //    same way.)
    $tomorrow = $inNewYork($dbNow() + 86400);
    if ($twice($soon, 'America/New_York') === true || $twice($tomorrow, 'America/New_York') === true) {
        refuse('part F3 cannot be run in these hours of the year: a time it would give one of its events happens twice on New York\'s clock. Run the test again in an hour');
    }
    // The reminder says "is tomorrow" only when the event's own day IS the day after today on its clock. On
    // two days a year that is not so for an event 24 hours away: 24 hours before a start late on the 25-hour
    // day is that same day (nothing is sent then), and 24 hours before a start just after midnight following
    // the 23-hour day is two days before (the reminder names the day). tools/time-zones-selftest.php holds
    // both. This part is about the use-by moment of an ordinary "is tomorrow" reminder, so it steps aside.
    $newYorkToday = substr($inNewYork($dbNow()), 0, 10);
    if ((new DateTimeImmutable($newYorkToday, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d') !== substr($tomorrow, 0, 10)) {
        refuse('part F3 cannot be run in this hour of the year: 24 hours from now is not "tomorrow" on New York\'s clock, because its clocks change in between. Run the test again in an hour or two');
    }
    eventAsTheFormMakesIt(EVENT_SOON, 'mail-queue-selftest-soon', 'Selftest queue event soon', $soon, 'America/New_York');
    foreach (['other', 'third', 'fourth'] as $who) {
        q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (?, ?, 1, 'going', 'confirmed')", 'ii', [EVENT_SOON, PEOPLE[$who]]);
    }
    // Its own "day of" summary is not what is looked at: marked as already done.
    q("INSERT INTO tblEventReminderLog (eventID, reminderType, recipientCount) VALUES (?, 'day', 0)", 'i', [EVENT_SOON]);
    eventAsTheFormMakesIt(EVENT_TOMORROW, 'mail-queue-selftest-tomorrow', 'Selftest queue event next day', $tomorrow, 'America/New_York');
    q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (?, ?, 1, 'going', 'confirmed')", 'ii', [EVENT_TOMORROW, PEOPLE['other']]);
    q("INSERT INTO tblEventReminderLog (eventID, reminderType, recipientCount) VALUES (?, 'day', 0)", 'i', [EVENT_TOMORROW]);
    // 2b. A LATE CALL (the second check of #604, 13 October 2026). An event at 23:52 on a clock that reads two
    //     minutes past midnight NOW: its start is 23 hours 50 minutes away, inside the day-before window, and
    //     this run is the first call inside it, on the event's OWN day. (That is what a 23:55 start gets when
    //     the job is called on the hour.) The reminder must still be made, with the day named, and kept with
    //     the event's START as its use-by moment. The start of its day, which every other day-before reminder
    //     carries, is already two minutes in the past: kept with that, it would be thrown away unsent.
    //     The clock is a fixed distance from UTC, worked out from now so that this needs no particular hour
    //     ("+09:17", say). PHP and the database both read a zone written that way.
    $lateOffset = ((2 - intdiv($dbNow() % 86400, 60)) % 1440 + 1440) % 1440; // minutes ahead of UTC at which a clock reads 00:02 now
    $lateOffset = $lateOffset > 840 ? $lateOffset - 1440 : $lateOffset;      // no clock is more than 14 hours ahead: the same reading, behind UTC
    $lateZone   = sprintf('%s%02d:%02d', $lateOffset < 0 ? '-' : '+', intdiv(abs($lateOffset), 60), abs($lateOffset) % 60);
    $lateDay    = gmdate('Y-m-d', $dbNow() + $lateOffset * 60);
    $late       = $lateDay . ' 23:52:00';
    eventAsTheFormMakesIt(EVENT_LATE, 'mail-queue-selftest-late', 'Selftest queue event late call', $late, $lateZone);
    q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (?, ?, 1, 'going', 'confirmed')", 'ii', [EVENT_LATE, PEOPLE['other']]);
    q("INSERT INTO tblEventReminderLog (eventID, reminderType, recipientCount) VALUES (?, 'day', 0)", 'i', [EVENT_LATE]);
    // 3. An event that is TODAY on its own clock, for the job's "day of"
    //    summary. Its zone box holds something that is NOT a zone ("Londn"),
    //    so "today", "between six and nine in the morning" and its use-by
    //    moment all have to be worked out on the ORGANISATION's own clock.
    //    For this one request the organisation is given a zone where it is
    //    seven in the morning now, in its own row: the one place its zone is
    //    kept since #604. (This used to set the "site.timezone" setting as
    //    well, the second place, which migration 217 removed.) Nobody is going
    //    to the event, so it gets no other reminder.
    $atSeven = '';
    foreach (timezone_identifiers_list() as $name) {
        if ((new DateTimeImmutable('now', new DateTimeZone($name)))->format('G') === '7') {
            $atSeven = $name;
            break;
        }
    }
    if ($atSeven === '') {
        refuse('part F3 found no time zone in which it is seven in the morning now');
    }
    $today = (new DateTimeImmutable('@' . $dbNow()))->setTimezone(new DateTimeZone($atSeven))->format('Y-m-d') . ' 23:59:00';
    eventAsTheFormMakesIt(EVENT_TODAY, 'mail-queue-selftest-today', 'Selftest queue event today', $today, 'Londn');
    $siteWas = (string) one('SELECT timezone FROM tblSites WHERE siteID = 1')['timezone'];
    organisationZone($atSeven);
    serverDown();
    $cron = req('', 'GET', '/cron/event-reminders?key=' . $jobToken, null, ip(2));
    organisationZone($siteWas);
    $soonRows  = queued("subject LIKE '%Starting soon: Selftest queue event soon'");
    $dayRows   = queued("subject LIKE '%Today: Selftest queue event today'");
    $nextRows  = queued("subject LIKE '%Selftest queue event next day tomorrow'");
    $allKept   = count(queued("subject LIKE '%Selftest queue event%'"));
    $trueStart = utcOf($soon, 'America/New_York');
    $logged    = one("SELECT recipientCount FROM tblEventReminderLog WHERE eventID = ? AND reminderType = '1h'", 'i', [EVENT_SOON]);
    ok(
        'the reminder job with the mail server down, for an event in New York\'s zone that really starts in an hour, made as the form makes it: its three "starting soon" reminders are KEPT, the job reports 0 sent and how many wait (it used to report them as sent), and each carries as its use-by moment the event\'s start ON NEW YORK\'S CLOCK, an hour from now, which is hours later than the same time read on London\'s (what it used to be given)',
        $cron['status'] === 200 && preg_match('/"1h":0[,}]/', $cron['body']) === 1 && preg_match('/"waiting":(\d+)[,}]/', $cron['body'], $w) === 1 && (int) $w[1] === $allKept
            && count($soonRows) === 3 && array_values(array_unique(array_column($soonRows, 'expiresAt'))) === [$trueStart] && $trueStart === gmdate('Y-m-d H:i:s', $soonAt)
            && strtotime($trueStart . ' UTC') - strtotime(utcOf($soon, 'Europe/London') . ' UTC') >= 4 * 3600 && $logged !== null && (int) $logged['recipientCount'] === 0,
        'HTTP ' . $cron['status'] . ' ' . $cron['body'] . '; kept ' . count($soonRows) . ' of ' . $allKept . ', use-by ' . json_encode(array_values(array_unique(array_column($soonRows, 'expiresAt'))))
            . '; the start on New York\'s clock is ' . $trueStart . ' UTC, on London\'s ' . utcOf($soon, 'Europe/London') . ' UTC'
    );
    ok(
        'the "is tomorrow" reminder for an event that starts 24 hours from now is kept with the START OF THE EVENT\'S OWN DAY, on New York\'s clock, as its use-by moment: from then on "tomorrow" would be untrue',
        preg_match('/"24h":0[,}]/', $cron['body']) === 1 && count($nextRows) === 1 && $nextRows[0]['toRecipients'] === mailOf('other')
            && $nextRows[0]['expiresAt'] === utcOf(substr($tomorrow, 0, 10), 'America/New_York') && $nextRows[0]['expiresAt'] !== utcOf($tomorrow, 'America/New_York'),
        'kept ' . count($nextRows) . ', use-by ' . json_encode($nextRows[0]['expiresAt'] ?? null) . '; that day starts ' . utcOf(substr($tomorrow, 0, 10), 'America/New_York') . ' UTC on New York\'s clock'
    );
    $lateRows = queued("subject LIKE '%Selftest queue event late call%'");
    $lateSubject = 'Reminder: Selftest queue event late call on ' . (new DateTimeImmutable($lateDay, new DateTimeZone('UTC')))->format('l j M');
    ok(
        'a LATE CALL: the day-before reminder for a 23:52 start, first reached two minutes into the event\'s own day (on a clock at ' . $lateZone . '), is still MADE, with the day named and not "tomorrow", and is kept with the event\'s START as its use-by moment, not the start of its day, which has already passed',
        count($lateRows) === 1 && str_ends_with((string) $lateRows[0]['subject'], $lateSubject) === true && $lateRows[0]['toRecipients'] === mailOf('other')
            && $lateRows[0]['expiresAt'] === utcOf($late, $lateZone) && $lateRows[0]['expiresAt'] !== utcOf($lateDay, $lateZone)
            && strtotime((string) $lateRows[0]['expiresAt'] . ' UTC') > $dbNow() && strtotime(utcOf($lateDay, $lateZone) . ' UTC') <= $dbNow(),
        'kept ' . count($lateRows) . ': subject ' . json_encode($lateRows[0]['subject'] ?? null) . ' (looked for one ending ' . json_encode($lateSubject) . '), use-by ' . json_encode($lateRows[0]['expiresAt'] ?? null)
            . '; the event starts ' . utcOf($late, $lateZone) . ' UTC and its day began ' . utcOf($lateDay, $lateZone) . ' UTC'
    );
    ok(
        'the job\'s "day of" summary for an event that is today is kept as well (for the administrator, at least), with the moment THAT event starts as its use-by moment; it used to be sent with none. That event\'s zone box holds "Londn", so the moment is worked out on the ORGANISATION\'s clock (' . $atSeven . ' for this one request), and the summary is not lost for the mistyped zone',
        preg_match('/"day":0[,}]/', $cron['body']) === 1 && count($dayRows) >= 1 && in_array(mailOf('root'), array_column($dayRows, 'toRecipients'), true) === true
            && array_values(array_unique(array_column($dayRows, 'expiresAt'))) === [utcOf($today, $atSeven)],
        'kept ' . count($dayRows) . ', use-by ' . json_encode(array_values(array_unique(array_column($dayRows, 'expiresAt')))) . '; on the organisation\'s clock that event starts ' . utcOf($today, $atSeven) . ' UTC'
    );
    // Forty minutes pass and the mail server is back. The event is still
    // twenty minutes away: the reminders must GO. (Read on London's clock
    // their use-by moment was hours in the past before they were even kept,
    // and all three were thrown away.)
    later(2400);
    useServer('accept');
    $ran  = runQueue();
    $mail = mailSince('accept');
    $to   = array_merge([], ...array_column($mail['messages'], 'to'));
    $went = array_values(array_intersect([mailOf('other'), mailOf('third'), mailOf('fourth')], $to));
    ok(
        'forty minutes later, with the mail server back, the three reminders are DELIVERED, not thrown away: the event has not started on its own clock',
        count($went) === 3 && queued("subject LIKE '%Starting soon: Selftest queue event soon'") === [],
        'delivered to ' . count($went) . ' of the three; the run ' . json_encode($ran)
    );

    // 3. SYDNEY, the other way round: an event that REALLY starts in an hour.
    //    Read on London's clock, the same time is ten or eleven hours later,
    //    and a message kept for it could go long after the event had begun.
    //    ("In an hour" is three hours in the one hour of the year that happens
    //    twice on Sydney's clock.)
    startClean();
    $invite = static fn (int $eventId, string $email): array => req($rootJar, 'POST', '/calendar/event/invites/send', [
        'csrf_token' => anyToken($rootJar, ip(1)), 'eventID' => (string) $eventId, 'email' => $email, 'displayName' => 'A guest of the self-test',
    ], ip(1));
    $lead     = 3600;
    $inSydney = static fn (int $moment): string => (new DateTimeImmutable('@' . $moment))->setTimezone(new DateTimeZone('Australia/Sydney'))->format('Y-m-d H:i:s');
    if ($twice($inSydney($dbNow() + $lead), 'Australia/Sydney') === true) {
        $lead = 3 * 3600; // the one hour of the year that happens twice in Sydney: the event starts two hours later instead
    }
    $inAnHour = $dbNow() + $lead;
    $east     = $inSydney($inAnHour);
    eventAsTheFormMakesIt(EVENT_EAST, 'mail-queue-selftest-east', 'Selftest queue event east', $east, 'Australia/Sydney');
    serverDown();
    $asked = $invite(EVENT_EAST, mailOf('third'));
    // What the portal RECORDED about it. (The page the sender is sent back to
    // does not show the invitation page's message at all, whatever it says:
    // that is older than this package and is written up, not changed here.)
    $said  = (string) (one("SELECT activityDescription FROM tblActivityLogs WHERE activityType = 'EventInviteSent' AND logID > ? ORDER BY logID DESC LIMIT 1", 'i', [$activityFrom])['activityDescription'] ?? '');
    $row   = queued("toRecipients = '" . mailOf('third') . "'")[0] ?? null;
    later($lead + 3600);
    useServer('accept');
    $ran   = runQueue();
    $mail  = mailSince('accept');
    $after = queued("toRecipients = '" . mailOf('third') . "'")[0] ?? null;
    ok(
        'an invitation to an event in Sydney\'s zone that really starts in an hour: kept while the mail server is down, with that very moment as its use-by moment, and the portal records it as waiting in the mail queue (not as delivered); an hour after that moment, with the server back, it is NOT sent, because the event has started. It is marked expired',
        $asked['status'] === 302 && $row !== null && $row['status'] === 'queued' && $row['expiresAt'] === gmdate('Y-m-d H:i:s', $inAnHour) && $row['expiresAt'] === utcOf($east, 'Australia/Sydney')
            && str_contains($said, '[waiting in the mail queue]') === true && ($ran['expired'] ?? 0) === 1 && $mail['messages'] === [] && ($after['status'] ?? '') === 'expired',
        'HTTP ' . $asked['status'] . '; recorded as: ' . $said . '; use-by ' . json_encode($row['expiresAt'] ?? null) . ', the event starts ' . gmdate('Y-m-d H:i:s', $inAnHour) . ' UTC; the run ' . json_encode($ran) . '; messages ' . count($mail['messages'])
    );

    // 4. BOTH CLOCK-CHANGE NIGHTS, IN TWO ZONES THAT CHANGE, BOTH WAYS, through
    //    the real invitation page: the next time the clocks go forward and the
    //    next time they go back, in New York and in Sydney, an event at ten in
    //    the morning the day BEFORE the change and one ON the day of it. The
    //    yardstick is the database's own conversion, not PHP's.
    startClean();
    serverDown();
    $got    = [];
    $want   = [];
    $nights = [];
    $n      = 0;
    foreach (['America/New_York', 'Australia/Sydney'] as $zone) {
        $picked = [];
        foreach (array_slice((new DateTimeZone($zone))->getTransitions(time(), time() + 400 * 86400), 1) as $change) {
            $picked[$change['isdst'] === true ? 'forward' : 'back'] ??= (int) $change['ts'];
        }
        foreach ($picked as $way => $at) {
            $pair = [];
            foreach (['the day before' => -86400, 'the day of the change' => 0] as $which => $shift) {
                $day     = (new DateTimeImmutable('@' . ($at + $shift)))->setTimezone(new DateTimeZone($zone))->format('Y-m-d');
                $label   = $zone . ', the clocks going ' . $way . ', ' . $which . ' (' . $day . ' 10:00)';
                $address = crowd($n + 1);
                eventAsTheFormMakesIt(EVENT_CHANGE + $n, 'mail-queue-selftest-change-' . $n, 'Selftest queue event change ' . $n, $day . ' 10:00:00', $zone);
                $invite(EVENT_CHANGE + $n, $address);
                $got[$label]  = queued("toRecipients = '" . $address . "'")[0]['expiresAt'] ?? null;
                $want[$label] = utcOf($day . ' 10:00:00', $zone);
                $pair[]       = (int) strtotime((string) $got[$label] . ' UTC');
                $n++;
            }
            // From ten one morning to ten the next: 23 hours when the clocks went forward in between, 25 when they went back.
            $nights[$zone . ' ' . $way] = ($pair[1] - $pair[0]) / 3600;
        }
    }
    ksort($nights);
    ok(
        'invitations to events at ten in the morning the day before and the day of each clock change (New York and Sydney, the clocks going forward and going back: 8 events, through the real page): each is kept with exactly the moment its event starts, as the database itself converts it; and from one morning to the next is 23 hours where the clocks went forward and 25 where they went back',
        count($want) === 8 && $got === $want
            && $nights === ['America/New_York back' => 25, 'America/New_York forward' => 23, 'Australia/Sydney back' => 25, 'Australia/Sydney forward' => 23],
        'expected ' . json_encode($want) . "\n        got      " . json_encode($got) . "\n        hours from one morning to the next " . json_encode($nights)
    );

    // 5. A ZONE BOX THAT DOES NOT HOLD A ZONE. The event form's box is free
    //    text. The organisation is given Tokyo's zone for this check, so that
    //    "the organisation's own clock" cannot be mistaken for a zone written
    //    into the code (London, UTC).
    startClean();
    $oddStart = gmdate('Y-m-d', $dbNow() + 3 * 86400) . ' 10:00:00';
    eventAsTheFormMakesIt(EVENT_ODD, 'mail-queue-selftest-odd', 'Selftest queue event odd', $oddStart, 'Londn');
    organisationZone('Asia/Tokyo');
    serverDown();
    $invite(EVENT_ODD, mailOf('fourth'));
    organisationZone($siteWas);
    $row = queued("toRecipients = '" . mailOf('fourth') . "'")[0] ?? null;
    ok(
        'an event whose zone box holds something that is not a time zone ("Londn"): its invitation is NOT lost for that. It is kept, with its use-by moment worked out on the ORGANISATION\'s own clock (Tokyo\'s, for this check), and not on London\'s or UTC\'s',
        $row !== null && $row['status'] === 'queued' && $row['expiresAt'] === utcOf($oddStart, 'Asia/Tokyo') && $row['expiresAt'] !== utcOf($oddStart, 'Europe/London') && $row['expiresAt'] !== $oddStart
            && (string) one('SELECT timezone FROM tblSites WHERE siteID = 1')['timezone'] === $siteWas,
        'use-by ' . json_encode($row['expiresAt'] ?? null) . '; on Tokyo\'s clock ' . utcOf($oddStart, 'Asia/Tokyo') . ', on London\'s ' . utcOf($oddStart, 'Europe/London') . ', as written ' . $oddStart
    );

    // 6. The conversion by itself: the day before, the day of and the day
    //    after the next clock change in each direction, in three zones, at ten
    //    in the morning and at the start of the day. Checked against the
    //    DATABASE's own conversion, not against PHP's.
    $asked    = [];
    $expected = [];
    foreach (['Europe/London', 'America/New_York', 'Australia/Sydney'] as $zone) {
        foreach (array_slice((new DateTimeZone($zone))->getTransitions(time(), time() + 400 * 86400), 1, 2) as $change) {
            foreach ([-86400, 0, 86400] as $shift) {
                $day = (new DateTimeImmutable('@' . ((int) $change['ts'] + $shift)))->setTimezone(new DateTimeZone($zone))->format('Y-m-d');
                foreach ([$day . ' 10:00:00', $day] as $wall) {
                    $asked[]    = [$wall, $zone];
                    $expected[] = utcOf($wall, $zone);
                }
            }
        }
    }
    $cases   = count($expected);
    $any     = gmdate('Y-m-d', $dbNow() + 86400) . ' 10:00:00';
    $asked[] = [$any, 'Nowhere/Nothing', 'Asia/Tokyo'];     // the first is not a zone: the second is used
    $asked[] = [$any, 'America/New_York', 'Asia/Tokyo'];    // the first is a zone: the second is not looked at
    $asked[] = [$any, 'Nowhere/Nothing'];                   // not a zone, and nothing else to go on
    $asked[] = [$any, 'Nowhere/Nothing', 'Also/Nowhere'];   // neither is a zone
    $asked[] = ['tomorrow at ten', 'Europe/London'];        // not a time
    $got     = (array) (child(['task' => 'facts', 'useBy' => $asked])['useBy'] ?? []);
    $moved   = count(array_filter($expected, static fn (string $utc, int $i): bool => $utc !== (strlen($asked[$i][0]) === 10 ? $asked[$i][0] . ' 00:00:00' : $asked[$i][0]), ARRAY_FILTER_USE_BOTH));
    ok(
        'a time on a clock and its zone become the right moment in UTC on the day before, the day of and the day after both clock changes, in London, New York and Sydney (36 cases, each as the database itself converts it). A zone PHP does not know is passed over for the second one given; when neither is a zone, or the time is not a time, the answer is text that is NOT a moment, so such a message is never kept',
        $cases === 36 && array_slice($got, 0, 36) === $expected && $moved >= 24
            && array_slice($got, 36) === [utcOf($any, 'Asia/Tokyo'), utcOf($any, 'America/New_York'), 'not known', 'not known', 'not known'],
        'expected ' . json_encode($expected) . "\n        got      " . json_encode($got)
    );

    // 7. THE ONE STRETCH THIS CANNOT GET RIGHT, held as the code describes it.
    //    On the night the clocks go back, a stretch of time on the clock
    //    happens twice. Asked about a time in it, PHP takes the SECOND
    //    happening in London and Sydney (so the moment is an hour LATE) and
    //    the FIRST in New York. If a later PHP chooses differently this fails,
    //    and the note beside MailQueue::useBy() must then be put right.
    $asked = [];
    $first = [];
    foreach (['Europe/London', 'Australia/Sydney', 'America/New_York'] as $zone) {
        foreach (array_slice((new DateTimeZone($zone))->getTransitions(time(), time() + 400 * 86400), 1) as $change) {
            if ((int) $change['offset'] < (int) (new DateTimeImmutable('@' . ((int) $change['ts'] - 1)))->setTimezone(new DateTimeZone($zone))->getOffset()) {
                // The clocks go back at this moment. A quarter of an hour before it: the FIRST happening of that time on the clock.
                $first[$zone] = (int) $change['ts'] - 900;
                $asked[]      = [(new DateTimeImmutable('@' . $first[$zone]))->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i:s'), $zone];
                break;
            }
        }
    }
    $got   = (array) (child(['task' => 'facts', 'useBy' => $asked])['useBy'] ?? []);
    $late  = [];
    foreach (array_keys($first) as $i => $zone) {
        $late[$zone] = isset($got[$i]) === true ? (int) strtotime($got[$i] . ' UTC') - $first[$zone] : null;
    }
    ok(
        'a time in the stretch that happens twice on the night the clocks go back is read as the code says it is: in London and Sydney as its SECOND happening (an hour late), in New York as its first',
        $late === ['Europe/London' => 3600, 'Australia/Sydney' => 3600, 'America/New_York' => 0],
        'seconds after the first happening: ' . json_encode($late) . ' for ' . json_encode($asked)
    );
}

// =============================================================================
if (part('F4', 'Three resets asked for while the mail server is down: one arrives, with a link that works (L1)') === true) {
// =============================================================================
    serverDown();
    foreach ([111, 112, 113] as $last) {
        askForLink(ip($last), mailOf('member'));
    }
    $rows     = queued();
    $replaced = (int) one("SELECT COUNT(*) AS n FROM tblEmailLog WHERE toRecipients = ? AND status = 'expired' AND errorCode = 'replaced'", 's', [mailOf('member')])['n'];
    ok(
        'only the NEWEST reset is waiting: the two older ones were removed when their links stopped working, each with a line in the send log saying a newer message took its place',
        count($rows) === 1 && $rows[0]['origin'] === 'reset:' . PEOPLE['member'] && $rows[0]['status'] === 'queued' && $replaced === 2,
        'waiting ' . count($rows) . '; send-log lines saying "replaced" ' . $replaced
    );
    useServer('accept');
    later(60);
    $ran  = $job('right');
    $mail = mailSince('accept');
    $got  = $mail['messages'][0] ?? null;
    $page = $got === null || preg_match('#http://127\.0\.0\.1:' . $port . '(/reset-password\?token=[0-9a-f]{64})#', $got['html'], $l) !== 1 ? null : req('', 'GET', $l[1], null, ip(111));
    ok(
        'when the mail server is back ONE reset e-mail arrives, not three, and its link works',
        ($ran['json']['sent'] ?? 0) === 1 && count($mail['messages']) === 1 && $page !== null && $page['status'] === 200 && str_contains($page['body'], 'name="password"') === true && queued() === [],
        'the job answered ' . $ran['body'] . '; messages ' . count($mail['messages']) . '; the link\'s page ' . json_encode($page['status'] ?? null)
    );
}

// =============================================================================
if (part('F5', 'A broadcast whose request is ended part way keeps NOTHING, and the next press reaches everybody (L2)') === true) {
// =============================================================================
    noJob();
    // Keeping a message is made slow (a sixth of a second each), so that the
    // request can be ended while it is part way through its 60. The request
    // is made through `php-cgi`, a process of its own that can be ended, as a
    // web server ends a request that has run too long.
    $db->query('CREATE TRIGGER `selftest_mq_slow` BEFORE INSERT ON `tblMailQueue` FOR EACH ROW BEGIN DECLARE waited INT; SET waited = SLEEP(0.15); END');
    $fields  = ['eventID' => (string) EVENT_SIXTY, 'segment' => 'all-rsvps', 'subject' => 'F5 broadcast ended part way', 'body' => 'A broadcast to sixty people.'];
    $started = cgiOpen('POST', '/calendar/event/broadcast/send', ['csrf_token' => anyToken($rootJar, ip(1))] + $fields, cookiesOf($rootJar), ip(1));
    // Watched from a connection that can see rows another connection has not yet made permanent.
    $watch = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $creds['db_name'], $creds['db_port']);
    $watch->query('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
    $count = static fn (string $table): int => (int) $watch->query('SELECT COUNT(*) AS n FROM ' . $table . " WHERE subject = 'F5 broadcast ended part way'")->fetch_assoc()['n'];
    $seen  = 0;
    $until = microtime(true) + 60;
    while ($seen < 12 && microtime(true) < $until) {
        usleep(100000);
        $seen = $count('tblMailQueue');
    }
    $working = $started !== null && proc_get_status($started['proc'])['running'] === true;
    if ($started !== null) {
        proc_terminate($started['proc'], 9); // by its own process handle, never by a name
        fclose($started['out']);
        proc_close($started['proc']);
    }
    // The database notices that the connection has gone and undoes whatever was not made permanent.
    $left  = $seen;
    $until = microtime(true) + 30;
    while ($left > 0 && microtime(true) < $until) {
        usleep(200000);
        $left = $count('tblMailQueue');
    }
    $db->query('DROP TRIGGER IF EXISTS `selftest_mq_slow`');
    $record = $count('tblEventBroadcasts');
    $watch->close();
    ok(
        'the request was ended while it had kept some of its 60 messages and was still working; afterwards NOTHING of it is left: no message in the queue, and no record of the broadcast to refuse the next press',
        $working === true && $seen >= 12 && $seen < 60 && $left === 0 && $record === 0,
        'still working when ended: ' . json_encode($working) . '; kept when ended ' . $seen . '; left afterwards ' . $left . '; records of the broadcast ' . $record
    );
    $again = req($rootJar, 'POST', '/calendar/event/broadcast/send', ['csrf_token' => anyToken($rootJar, ip(1))] + $fields, ip(1));
    $said  = words(req($rootJar, 'GET', $again['location'], null, ip(1))['body']);
    $mail  = mailSince('accept');
    $to    = array_map(static fn (array $m): string => implode(',', $m['to']), $mail['messages']);
    ok(
        'pressing Send again is NOT refused as "already sent": all 60 get the message, once each',
        $again['status'] === 302 && str_contains($said, 'Sent to 60 of 60 recipients.') === true && count($mail['messages']) === 60 && count(array_unique($to)) === 60
            && (int) one("SELECT COUNT(*) AS n FROM tblEventBroadcasts WHERE subject = 'F5 broadcast ended part way'")['n'] === 1,
        'the page said: ' . (preg_match('/(Sent to [^.]*\.|This same message[^.]*\.)/', $said, $m) === 1 ? $m[0] : '(nothing)') . '; messages ' . count($mail['messages'])
    );
}

// =============================================================================
if (part('F6', 'A message for more people than a whole hour allows is refused at once, and such rows do not block the queue (L3)') === true) {
// =============================================================================
    setting('mail.limit.perHour', '10'); // ordinary mail may use 5 of the 10 (5 are kept for urgent mail); urgent mail may use all 10
    $crowdOf = static fn (int $n): array => array_map('crowd', range(1, $n));
    $sent = child(['task' => 'send', 'messages' => [
        msg($crowdOf(6), 'F6 for six people'),
        msg($crowdOf(11), 'F6 urgent, for eleven people', 'A message.', ['priority' => 'urgent']),
        msg($crowdOf(5), 'F6 for five people'),
    ]]);
    $mail = mailSince('accept');
    $log  = one("SELECT status, errorCode, errorDetail FROM tblEmailLog WHERE subject = 'F6 for six people'");
    ok(
        'a message for 6 people, where ordinary mail may use 5 an hour, is refused AT ONCE: send() answers false ("refused"), nothing is kept, and the send log says why in words that name the limit. An urgent one for 11 (the whole limit is 10) the same. One for 5 goes',
        ($sent['each'][0] ?? null) === [false, 'refused', 0] && ($sent['each'][1] ?? null) === [false, 'refused', 0] && ($sent['each'][2][1] ?? '') === 'sent' && queued() === [] && count($mail['messages']) === 1
            && $log !== null && $log['status'] === 'failed' && $log['errorCode'] === 'over-hourly-limit' && str_contains((string) $log['errorDetail'], 'the limit is 10 recipients an hour') === true
            && str_contains((string) $log['errorDetail'], 'no hour in which it could go') === true,
        json_encode($sent['each'] ?? $sent['threw']) . '; kept ' . count(queued()) . '; send log ' . json_encode($log)
    );
    // Rows that were kept BEFORE the limit was lowered: twenty of them in front of an ordinary message.
    startClean();
    $rows = [];
    for ($n = 1; $n <= 20; $n++) {
        $rows[] = row($crowdOf(6), 'F6 kept before the limit ' . $n, ['priority' => 9]);
    }
    $rows[] = row([mailOf('other')], 'F6 the ordinary message behind them', ['priority' => 9]);
    child(['task' => 'add', 'messages' => $rows]);
    setting('mail.limit.perHour', '10');
    $ran    = runQueue();
    $mail   = mailSince('accept');
    $failed = queued("status = 'failed' AND lastError LIKE '%the limit is 10 recipients an hour%'");
    ok(
        'twenty such messages at the front of the queue do not block it: each is failed at once, in words that name the limit (not two days later as "too old"), and the ordinary message behind them goes in the same run',
        ($ran['failed'] ?? 0) === 20 && ($ran['sent'] ?? 0) === 1 && count($mail['messages']) === 1 && ($mail['messages'][0]['to'] ?? []) === [mailOf('other')] && count($failed) === 20,
        'the run ' . json_encode($ran) . '; messages ' . count($mail['messages']) . '; failed for the limit ' . count($failed)
    );
}

// =============================================================================
if (part('F7', 'A "no" to the sender does not throw a message away; a use-by moment that cannot be read stops it being kept (L4, L5)') === true) {
// =============================================================================
    useServer('refuse-sender');
    $no  = child(['task' => 'send', 'messages' => [msg(mailOf('other'), 'F7 the sender is refused')]]);
    $row = rowOf('F7 the sender is refused');
    $log = one("SELECT status FROM tblEmailLog WHERE subject = 'F7 the sender is refused'");
    ok(
        'a "no" from the mail server to the SENDER (a mailbox that has reached its limit for the day) does NOT throw the message away: it is kept, queued, counted as tried once, to be tried again',
        ($no['each'][0][0] ?? null) === true && ($no['each'][0][1] ?? '') === 'queued' && $row !== null && $row['status'] === 'queued' && (int) $row['attempts'] === 1 && ($log['status'] ?? '') === 'queued',
        json_encode($no['each'] ?? $no['threw']) . '; the row ' . json_encode($row === null ? null : [$row['status'], $row['attempts']]) . '; send log ' . json_encode($log)
    );
    startClean();
    useServer('later');
    $asked = child(['task' => 'send', 'messages' => [msg(mailOf('other'), 'F7 the mail server says later')]]);
    $said  = (string) (one("SELECT errorDetail FROM tblEmailLog WHERE subject = 'F7 the mail server says later'")['errorDetail'] ?? '');
    ok(
        'a mail server that says "try again later" when given the sender is reported as having ASKED FOR IT LATER, not as having refused the "from" address',
        ($asked['each'][0][1] ?? '') === 'queued' && str_contains($said, 'asked for the message to be sent again later') === true && str_contains($said, 'refused the "from" address') === false,
        json_encode($asked['each'] ?? $asked['threw']) . '; the send log said: ' . $said
    );
    startClean();
    serverDown();
    $bad = child(['task' => 'send', 'messages' => [
        msg(mailOf('other'), 'F7 a use-by moment that cannot be read', 'A message.', ['expiresAt' => 'next Tuesday']),
        msg(mailOf('other'), 'F7 the same, asked to wait', 'A message.', ['expiresAt' => 'next Tuesday', 'sendNow' => false]),
        msg(mailOf('other'), 'F7 with no use-by moment'),
    ]]);
    $rows = queued();
    useServer('accept');
    $now  = child(['task' => 'send', 'messages' => [msg(mailOf('other'), 'F7 unreadable use-by, the mail server up', 'A message.', ['expiresAt' => 'next Tuesday'])]]);
    ok(
        'a message whose use-by moment cannot be read is NEVER kept as if it had none: with the mail server down it is not sent and not kept (send() answers false), where the same message with no use-by moment is kept; with the server up it still goes at once',
        ($bad['each'][0] ?? null) === [false, 'not-sent', 0] && ($bad['each'][1] ?? null) === [false, 'not-sent', 0] && ($bad['each'][2][1] ?? '') === 'queued'
            && array_column($rows, 'subject') === ['F7 with no use-by moment'] && ($now['each'][0][1] ?? '') === 'sent',
        json_encode($bad['each'] ?? $bad['threw']) . '; kept ' . json_encode(array_column($rows, 'subject')) . '; with the server up ' . json_encode($now['each'] ?? $now['threw'])
    );
}

// =============================================================================
if (part('F8', 'One organisation\'s own value for the four numbers, through the Settings page (M6)') === true) {
// =============================================================================
    $orgJar = signIn('orgadmin', $password, ip(121));
    /** Post the Settings form and read back what the page then says. */
    $save = static function (string $jar, int $last, array $fields): string {
        $answer = req($jar, 'POST', '/settings/save', ['csrf_token' => anyToken($jar, ip($last))] + $fields, ip($last));

        return 'HTTP ' . $answer['status'] . ' ' . words(req($jar, 'GET', $answer['location'] === '' ? '/settings' : $answer['location'], null, ip($last))['body']);
    };
    $ownRow = static fn (string $key): ?array => one('SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE siteID = 1 AND settingKey = ?', 's', [$key]);
    $wide   = static fn (string $key): ?array => one('SELECT settingID, settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey = ?', 's', [$key]);
    $tried  = static fn (?int $siteId): int => (int) (child(['task' => 'numbers', 'siteId' => $siteId])['numbers']['retryHours'] ?? 0);

    $bad   = $save($orgJar, 121, ['settingKey' => 'mail.queue.maxAgeHours', 'settingValue' => 'two days']);
    $noRow = $ownRow('mail.queue.maxAgeHours');
    $added = $save($orgJar, 121, ['settingKey' => 'mail.queue.retryHours', 'settingValue' => '6', 'isSensitive' => '1']);
    $row   = $ownRow('mail.queue.retryHours');
    ok(
        'an administrator of ONE organisation gives it its own value: "6" for how long mail is tried is stored for that organisation (never as a secret, whatever the form says), the page says what the installation\'s value is, and the queue uses 6 for that organisation\'s mail and 24 for mail that belongs to none. A value that is not a number is refused, in words that give the range and the installation\'s value, and nothing is stored',
        str_contains($bad, 'must be a whole number from 1 to 720') === true && str_contains($bad, "The installation's value, which every organisation without its own uses, is 48.") === true && $noRow === null
            && $row !== null && $row['settingValue'] === '6' && (int) $row['isSensitive'] === 0
            && str_contains($added, "The installation's value, which every organisation without its own uses, is 24.") === true && $tried(1) === 6 && $tried(null) === 24,
        'the refusal: ' . mb_substr($bad, 0, 300) . ' / the row ' . json_encode($row) . ' / after adding: ' . mb_substr($added, 0, 300)
    );
    // Adding the SAME name again. (With the installation's row left out of the
    // "does it exist already?" question, the organisation's own row must
    // still be in it: nothing held that, and the name could be added twice.)
    $twice   = $save($orgJar, 121, ['settingKey' => 'mail.queue.retryHours', 'settingValue' => '9']);
    $ownRows = array_column(q("SELECT settingValue FROM tblSettings WHERE siteID = 1 AND settingKey = 'mail.queue.retryHours'"), 'settingValue');
    ok(
        'adding the same name a SECOND time for the organisation is refused as already existing: there is still ONE row of its own, and it still holds 6',
        str_contains($twice, 'already exists') === true && $ownRows === ['6'],
        'its own rows now hold ' . json_encode($ownRows) . '; the page said: ' . mb_substr($twice, 0, 200)
    );
    $id      = (string) ($row['settingID'] ?? 0);
    $changed = $save($orgJar, 121, ['settingID' => $id, 'settingKey' => 'mail.queue.retryHours', 'settingValue' => '12']);
    $now12   = $ownRow('mail.queue.retryHours')['settingValue'] ?? null;
    $zero    = $save($orgJar, 121, ['settingID' => $id, 'settingKey' => 'mail.queue.retryHours', 'settingValue' => '0']);
    $still12 = $ownRow('mail.queue.retryHours')['settingValue'] ?? null;
    $removed = $save($orgJar, 121, ['settingID' => $id, 'settingKey' => 'mail.queue.retryHours', 'settingValue' => '']);
    ok(
        'it changes its own value to 12; 0 is refused and 12 stays; clearing the box REMOVES the organisation\'s own row, and its mail goes back to the installation\'s 24',
        $now12 === '12' && $still12 === '12' && str_contains($zero, 'must be a whole number from 1 to 720') === true && $ownRow('mail.queue.retryHours') === null
            && str_contains($removed, 'own value of "mail.queue.retryHours" was removed') === true && $tried(1) === 24,
        'after 12: ' . json_encode($now12) . '; after 0: ' . json_encode($still12) . ' ' . mb_substr($zero, 0, 200) . '; after clearing: ' . json_encode($ownRow('mail.queue.retryHours')) . ' ' . mb_substr($removed, 0, 200)
    );
    // What the page refused before, it still refuses.
    $wideRow  = $wide('mail.queue.retryHours');
    $tryWide  = $save($orgJar, 121, ['settingID' => (string) ($wideRow['settingID'] ?? 0), 'settingKey' => 'mail.queue.retryHours', 'settingValue' => '99']);
    $other    = $save($orgJar, 121, ['settingKey' => 'mail.limit.perHour', 'settingValue' => '5']);
    $capitals = $save($orgJar, 121, ['settingKey' => 'Mail.Queue.RetryHours', 'settingValue' => '5']);
    $member   = signIn('member', $password, ip(122));
    $asMember = req($member, 'POST', '/settings/save', ['csrf_token' => anyToken($member, ip(122)), 'settingKey' => 'mail.queue.retryHours', 'settingValue' => '5'], ip(122));
    $byRoot   = $save($rootJar, 1, ['settingKey' => 'mail.queue.holdDays', 'settingValue' => '3']);
    $ownNow   = array_column(q("SELECT settingKey, settingValue FROM tblSettings WHERE siteID = 1 AND (settingKey LIKE 'mail.queue.%' OR settingKey = 'mail.limit.perHour')"), 'settingValue', 'settingKey');
    ok(
        'every refusal the page made before still holds: that administrator cannot change the INSTALLATION\'s value, cannot add an organisation\'s row for any other setting the installation has, nor one whose name differs only in capital letters, and an ordinary member cannot use the page at all. A global administrator can give the organisation its own value too',
        ($wide('mail.queue.retryHours')['settingValue'] ?? '') === '24' && str_contains($tryWide, 'portal-wide') === true && str_contains($other, 'already exists') === true
            && str_contains($capitals, 'differs from it only in capital') === true && $asMember['status'] === 403 && $ownNow === ['mail.queue.holdDays' => '3'],
        'the installation\'s value ' . json_encode($wide('mail.queue.retryHours')) . '; this organisation\'s own rows ' . json_encode($ownNow) . '; a member got HTTP ' . $asMember['status'] . '; a global administrator: ' . mb_substr($byRoot, 0, 160)
    );

    // 🚧 #668 part 2 — THE INSTALLATION CAPS THE ORGANISATION, through the real
    //    page. These four numbers are the ONLY settings on #668's list that an
    //    organisation can really raise: `_apps/settings/save.php` refuses to ADD
    //    a per-organisation row for any key the installation already has, and
    //    these are the deliberate exception. Until 30 October 2026 the only test
    //    applied was `sensible()`, the hard range IN CODE, with the
    //    installation's value merely MENTIONED in the words — so an
    //    administrator of a single organisation (this page needs
    //    `App::isAdmin()`, not `isRootAdmin()`) could set `keepFailedDays` to
    //    365 where the installation chose 7, keeping a failed message's body on
    //    the installation's disk for a year, or `maxAgeHours` to 720 where it
    //    chose 48, sending a reset link a month old.
    //
    //    The installation's values are the seeded ones, listed just below so
    //    they appear once. Each of the four is checked just above and just
    //    below its own ceiling, so a change to one number cannot make this
    //    section pass by accident.
    // It ADDS where the organisation has no row of its own and EDITS where it
    // has one, because both branches of the page call ownNumberCheck() and both
    // need the cap. Taking that from the row rather than assuming "no row yet"
    // matters here: a global administrator gave this organisation a holdDays
    // row a few lines above, so an unconditional add hits "already exists" and
    // the cap is never reached — which is how the first version of this check
    // reported two passes and one failure that had nothing to do with the cap.
    $capFor = static function (string $key, int $allowed) use ($save, $orgJar, $ownRow): array {
        $post = static function (int $value) use ($save, $orgJar, $ownRow, $key): string {
            $existing = $ownRow($key);
            $fields   = ['settingKey' => $key, 'settingValue' => (string) $value];
            if ($existing !== null) {
                $fields['settingID'] = (string) $existing['settingID'];
            }

            return $save($orgJar, 121, $fields);
        };
        $before = $ownRow($key);
        $over   = $post($allowed + 1);
        $rowAfterOver = $ownRow($key);
        $under  = $post($allowed - 1);
        $rowAfterUnder = $ownRow($key);

        return [
            'over' => $over, 'under' => $under, 'underRow' => $rowAfterUnder,
            // "Nothing was stored" means the row is as it was — absent if it was
            // absent, unchanged if it already existed.
            'overUnchanged' => $before === null
                ? $rowAfterOver === null
                : ($rowAfterOver !== null && $rowAfterOver['settingValue'] === $before['settingValue']),
        ];
    };
    // 🔢 ALL FOUR, and the figures in ONE place. The first version drove three
    //    and left `retryHours` out while its own label said "each of the four"
    //    — the overstated-guarantee trap. The ceilings were also written twice
    //    in this block, which is how they could disagree.
    $ceilings = [
        'mail.queue.retryHours'     => 24,
        'mail.queue.maxAgeHours'    => 48,
        'mail.queue.keepFailedDays' => 7,
        'mail.queue.holdDays'       => 7,
    ];
    $capResults = [];
    foreach ($ceilings as $key => $allowed) {
        $capResults[$key] = $capFor($key, $allowed);
        // Put the organisation back as it was, so the next key starts clean and
        // the run's own clean-up check still finds nothing left behind.
        $left = $ownRow($key);
        if ($left !== null) {
            $save($orgJar, 121, ['settingID' => (string) $left['settingID'], 'settingKey' => $key, 'settingValue' => '']);
        }
    }
    $capsRefused = [];
    $capsAllowed = [];
    foreach ($capResults as $key => $r) {
        $allowed = $ceilings[$key];
        $capsRefused[$key] = str_contains($r['over'], 'allows at most ' . $allowed) === true
            && str_contains($r['over'], 'not saved') === true
            && $r['overUnchanged'] === true;
        $capsAllowed[$key] = $r['underRow'] !== null && (string) $r['underRow']['settingValue'] === (string) ($allowed - 1);
    }
    ok(
        '#668: an organisation asking for MORE than the installation allows is refused for each of the four numbers, told what the ceiling is, and nothing is stored',
        $capsRefused === array_map(static fn (): bool => true, $ceilings),
        json_encode($capsRefused) . ' — one answer: ' . mb_substr($capResults['mail.queue.keepFailedDays']['over'], 0, 320)
    );
    ok(
        '#668: …while one LESS than the installation allows is still stored, so the cap tightens rather than blocks',
        $capsAllowed === array_map(static fn (): bool => true, $ceilings),
        json_encode($capsAllowed)
    );
    // The installation's own value must still be changeable UPWARDS by a global
    // administrator — capping it against itself would make the ceiling a
    // one-way ratchet.
    $wideHold = $wide('mail.queue.holdDays');
    $raise    = $save($rootJar, 1, ['settingID' => (string) ($wideHold['settingID'] ?? 0), 'settingKey' => 'mail.queue.holdDays', 'settingValue' => '30']);
    $raisedTo = $wide('mail.queue.holdDays')['settingValue'] ?? '';
    ok(
        '#668: a GLOBAL administrator can still RAISE the installation\'s own ceiling, which capping it against itself would have prevented',
        $raisedTo === '30',
        'it now holds ' . json_encode($raisedTo) . '; the page said: ' . mb_substr($raise, 0, 200)
    );
    if ($wideHold !== null) {
        $save($rootJar, 1, ['settingID' => (string) $wideHold['settingID'], 'settingKey' => 'mail.queue.holdDays', 'settingValue' => (string) $wideHold['settingValue']]);
    }

    // 🚧 #668: AND THE JOB OBEYS THE CAP TOO, not just the Save button.
    //    A row can be over the installation's limit without anybody pressing
    //    Save: it may predate the check (the per-organisation feature shipped
    //    with #592), be written by direct SQL, or come back from a
    //    whole-database restore of an older backup — the supported route, since
    //    a one-table restore of tblSettings is refused. So the row is written
    //    straight into the database here, exactly as those three cases would
    //    leave it, and what the JOB uses is read back.
    //
    //    Without this the cap was save-time only, and the independent check
    //    measured the job still using 365 days where the installation said 7.
    q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (1, 'mail.queue.keepFailedDays', '365', '7', 0) ON DUPLICATE KEY UPDATE settingValue = '365'");
    $jobNumbers = child(['task' => 'numbers', 'siteId' => 1])['numbers'] ?? [];
    $wideKeep   = (int) ($wide('mail.queue.keepFailedDays')['settingValue'] ?? 0);
    ok(
        '#668: a row already stored ABOVE the installation\'s limit is brought down for the JOB as well, not only refused at the Save button',
        $wideKeep > 0 && (int) ($jobNumbers['keepFailedDays'] ?? -1) === $wideKeep,
        'the installation allows ' . $wideKeep . '; the job would use ' . json_encode($jobNumbers['keepFailedDays'] ?? null) . ' (all of them: ' . json_encode($jobNumbers) . ')'
    );
    q("DELETE FROM tblSettings WHERE siteID = 1 AND settingKey = 'mail.queue.keepFailedDays'");
}

// =============================================================================
if (part('F9', 'An erasure while a run of the job is handing that person\'s message over (M5)') === true) {
// =============================================================================
    // A giving statement for the same person is WAITING too (kept while the
    // mail server is down, and not due again for five minutes, so the run
    // below leaves it alone). Its record is kept when its donor is erased.
    $year = (int) date('Y') - 1;
    $pdf  = $makeFile('f9-statement.pdf', "%PDF-1.4\nA made-up giving statement from the mail queue self-test.\n");
    q(
        'INSERT INTO tblGivingStatementLog (logID, siteID, donorID, periodKey, fromDate, toDate, pdfPath, queuedAt) VALUES (?, 1, ?, ?, ?, ?, ?, NOW())',
        'iissss',
        [STATEMENT + 10, PEOPLE['raced'], $year . '-01-01_' . $year . '-12-31', $year . '-01-01', $year . '-12-31', $pdf]
    );
    serverDown();
    $stated = child(['task' => 'statement', 'logRow' => ['logID' => STATEMENT + 10, 'siteID' => 1, 'donorID' => PEOPLE['raced'], 'pdfPath' => $pdf, 'fromDate' => $year . '-01-01', 'toDate' => $year . '-12-31']]);
    $before = one('SELECT emailedAt IS NOT NULL AS stamped, errorMsg FROM tblGivingStatementLog WHERE logID = ?', 'i', [STATEMENT + 10]);
    useServer('slow'); // one second before every reply: a run takes several seconds to hand one message over
    child(['task' => 'add', 'messages' => [row([mailOf('raced')], 'F9 handed over while the person is erased')]]);
    q(
        "INSERT INTO tblErasureRequest (requestID, siteID, userID, subjectEmail, subjectName, confirmToken, status, dueBy) VALUES (?, 1, ?, ?, 'Selftest queue raced', NULL, 'processing', DATE_ADD(NOW(), INTERVAL 1 MONTH))",
        'iis',
        [REQUEST_RACE, PEOPLE['raced'], mailOf('raced')]
    );
    $run   = startChild(['task' => 'run']);
    // Wait until the run has marked the message as being handed over.
    $until = microtime(true) + 30;
    while ((rowOf('F9 handed over while the person is erased')['stage'] ?? '') !== 'handing-over' && microtime(true) < $until) {
        usleep(100000);
    }
    $inFlight = (rowOf('F9 handed over while the person is erased')['stage'] ?? '') === 'handing-over';
    $erased   = child(['task' => 'erase', 'requestId' => REQUEST_RACE, 'userId' => PEOPLE['raced'], 'adminId' => PEOPLE['root']]);
    $ran      = finishChild($run);
    $mail     = mailSince('slow');
    $kept     = (string) json_encode([
        q('SELECT toRecipients, errorDetail FROM tblEmailLog'), q('SELECT errorDetail FROM tblErrors WHERE errorID > ?', 'i', [$errorsFrom]), q('SELECT toRecipients, lastError FROM tblMailQueue'),
    ]);
    $audit = one("SELECT action FROM tblErasureAudit WHERE requestID = ? AND tableName = 'tblMailQueue'", 'i', [REQUEST_RACE]);
    ok(
        'the erasure WAITS for the run to end; afterwards the person\'s address is in no line of the send log, no mail error line and no waiting message: the line the run wrote for the message it was handing over has been cleaned as well',
        $inFlight === true && $erased['threw'] === '' && ($erased['result']['failed'] ?? -1) === 0 && $ran['threw'] === '' && count($mail['messages']) === 1
            && str_contains($kept, mailOf('raced')) === false && $audit !== null,
        'the run was handing it over when the erasure began: ' . json_encode($inFlight) . '; erasure ' . json_encode($erased['result'] ?? $erased['threw']) . '; the run ' . json_encode($ran['numbers'] ?? $ran['threw'])
            . '; the address is still stored: ' . json_encode(str_contains($kept, mailOf('raced')))
    );
    $after = one('SELECT emailedAt IS NOT NULL AS stamped, emailedTo, errorMsg FROM tblGivingStatementLog WHERE logID = ?', 'i', [STATEMENT + 10]);
    ok(
        'the giving statement that was WAITING for that person does not go on saying "waiting" for ever: its message is out of the queue, and its record (which is kept) has its stamp taken off and says "send-failed"',
        ($stated['waiting'] ?? null) === true && $before !== null && (int) $before['stamped'] === 1 && $before['errorMsg'] === 'waiting in the mail queue'
            && $after !== null && (int) $after['stamped'] === 0 && $after['emailedTo'] === null && $after['errorMsg'] === 'send-failed' && (int) one('SELECT COUNT(*) AS n FROM tblMailQueue')['n'] === 0,
        'before the erasure ' . json_encode($before) . '; after it ' . json_encode($after) . '; rows left in the queue ' . json_encode(one('SELECT COUNT(*) AS n FROM tblMailQueue'))
    );
}

// =============================================================================
if (part('F10', 'An erasure that cannot have the run lock still takes the person out of the queue, and running it again finishes it (M5)') === true) {
// =============================================================================
    serverDown();
    child(['task' => 'send', 'messages' => [msg(mailOf('locked'), 'F10 waiting for the person alone'), msg([mailOf('locked'), mailOf('other')], 'F10 waiting for two people')]]);
    q(
        "INSERT INTO tblErasureRequest (requestID, siteID, userID, subjectEmail, subjectName, confirmToken, status, dueBy) VALUES (?, 1, ?, ?, 'Selftest queue locked', NULL, 'processing', DATE_ADD(NOW(), INTERVAL 1 MONTH))",
        'iis',
        [REQUEST_LOCKED, PEOPLE['locked'], mailOf('locked')]
    );
    // This test's own connection takes the lock a run of the job holds, and
    // keeps it: a run that never ends. The erasure waits its 20 seconds for it.
    $db->query("SELECT GET_LOCK(CONCAT('portal-mail-queue:', SHA1(DATABASE())), 0)");
    $began   = microtime(true);
    $first   = child(['task' => 'erase', 'requestId' => REQUEST_LOCKED, 'userId' => PEOPLE['locked'], 'adminId' => PEOPLE['root']]);
    $waited  = microtime(true) - $began;
    $db->query("DO RELEASE_LOCK(CONCAT('portal-mail-queue:', SHA1(DATABASE())))");
    $request = one('SELECT status FROM tblErasureRequest WHERE requestID = ?', 'i', [REQUEST_LOCKED]);
    $audit   = one("SELECT action, recordKey, details FROM tblErasureAudit WHERE requestID = ? AND tableName = 'tblMailQueue' ORDER BY auditID DESC LIMIT 1", 'i', [REQUEST_LOCKED]);
    $left    = (string) json_encode(q('SELECT toRecipients FROM tblMailQueue'));
    // The lock is free again and the mail server is back: the next run of the job.
    useServer('accept');
    later(900);
    $ran  = runQueue();
    $mail = mailSince('accept');
    $to   = array_merge([], ...array_column($mail['messages'], 'to'));
    ok(
        'with a run of the job holding the lock for good, the erasure waits its 20 seconds and is then recorded as NOT FINISHED (one failed step, the request back at pending review, the audit line saying why and to run it again). But the person is OUT of the queue all the same: the next run sends them nothing, and the message that was also for somebody else goes to that person alone',
        $first['threw'] === '' && $waited >= 19.0 && ($first['result']['failed'] ?? 0) === 1 && ($request['status'] ?? '') === 'pending_review'
            && $audit !== null && $audit['action'] === 'failed' && $audit['recordKey'] === '1 deleted, 1 changed' && str_contains((string) $audit['details'], 'Run this erasure again') === true
            && str_contains($left, mailOf('locked')) === false && $to === [mailOf('other')],
        'it waited ' . round($waited, 1) . ' s; ' . json_encode($first['result'] ?? $first['threw']) . '; the request is ' . json_encode($request['status'] ?? null) . '; audit ' . json_encode($audit)
            . '; still in the queue: ' . json_encode(str_contains($left, mailOf('locked'))) . '; the next run sent to ' . json_encode($to)
    );
    // The administrator runs the erasure again. (The page that does so marks
    // the request as being worked on first; here that is done by hand.)
    q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQUEST_LOCKED]);
    $second  = child(['task' => 'erase', 'requestId' => REQUEST_LOCKED, 'userId' => PEOPLE['locked'], 'adminId' => PEOPLE['root']]);
    $request = one('SELECT status FROM tblErasureRequest WHERE requestID = ?', 'i', [REQUEST_LOCKED]);
    $kept    = (string) json_encode([
        q('SELECT toRecipients, errorDetail FROM tblEmailLog'), q('SELECT errorDetail FROM tblErrors WHERE errorID > ?', 'i', [$errorsFrom]), q('SELECT toRecipients, lastError FROM tblMailQueue'),
    ]);
    ok(
        'running the erasure again FINISHES it: no failed step, the request is completed, and the person\'s address is in no waiting message, no line of the send log and no mail error line',
        $second['threw'] === '' && ($second['result']['failed'] ?? -1) === 0 && ($request['status'] ?? '') === 'completed' && str_contains($kept, mailOf('locked')) === false,
        json_encode($second['result'] ?? $second['threw']) . '; the request is ' . json_encode($request['status'] ?? null) . '; the address is still stored: ' . json_encode(str_contains($kept, mailOf('locked')))
    );
}

// =============================================================================
if (part('LAST', 'Nothing printed, logged or stored holds a message body, a reset link or the mailbox\'s password') === true) {
// =============================================================================
    // Something of every kind is left in place to be searched: a waiting
    // reset (the only thing that ever stores a working link), a waiting
    // message with marked words in its body, and whatever the parts above
    // left in the logs.
    serverDown();
    askForLink(ip(95), mailOf('member'));
    child(['task' => 'send', 'messages' => [msg(mailOf('other'), 'LAST a message with marked words', 'Words that must not be found: ' . $bodyMark)]]);
    // Neither of those is due yet, so the job would not touch them. A third
    // message, urgent and due at once, makes the job really HANDLE a message
    // whose body holds the marked words (it decrypts it and tries to send it),
    // so that whatever the job prints or logs while doing so is searched too.
    child(['task' => 'add', 'messages' => [row([mailOf('third')], 'LAST handled by the job', ['priority' => 1, 'html' => '<p>' . $bodyMark . '</p>', 'text' => $bodyMark])]]);
    $ranJob = $job('right');
    $seenHealth = req('', 'GET', '/cron/health?token=' . $jobToken, null, ip(2));
    $rows   = queued();
    $reset  = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['priority'] === 1))[0] ?? null;
    $marked = rowOf('LAST a message with marked words');
    $token  = $reset !== null && preg_match('/reset-password\?token=([0-9a-f]{64})/', unsealed((string) $reset['bodyHtml'], $keyText), $m) === 1 ? $m[1] : '';
    $stored = (string) json_encode([
        q('SELECT * FROM tblMailQueue'), q('SELECT * FROM tblMailQueueFiles'), q('SELECT * FROM tblMailRouteState'), q('SELECT * FROM tblEmailLog'),
        q('SELECT * FROM tblErrors WHERE errorID > ?', 'i', [$errorsFrom]), q('SELECT * FROM tblActivityLogs WHERE logID > ?', 'i', [$activityFrom]),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $printed = $state['haystack'] . "\n" . (string) @file_get_contents($child . $sep . 'php-errors.log') . "\n" . (string) @file_get_contents($child . $sep . 'server-output.log');
    ok(
        'the search looks in the right places: the marked message\'s SUBJECT is found in what is stored, its body decrypts to the marked words with the installation\'s key, and the waiting reset holds a link',
        $marked !== null && str_contains($stored, 'LAST a message with marked words') === true && str_contains(unsealed((string) $marked['bodyHtml'], $keyText), $bodyMark) === true && $token !== ''
            // The job did handle the marked message (it is counted as tried), and its answer and the health report are in what is searched.
            && ($ranJob['json']['waiting'] ?? 0) === 1 && (int) (rowOf('LAST handled by the job')['attempts'] ?? 0) === 1
            && str_contains($printed, $ranJob['body']) === true && $ranJob['body'] !== '' && str_contains($printed, 'Mail queue') === true && $seenHealth['status'] === 200,
        'the job answered ' . $ranJob['body'] . '; printed ' . strlen($printed) . ' bytes, stored ' . strlen($stored) . ' bytes; a link was found: ' . json_encode($token !== '')
    );
    ok(
        'the marked words of message bodies are in NOTHING the job and the pages printed or logged, and nowhere readable in the queue, the send log, the route\'s record, the error records or the activity records',
        str_contains($printed, $bodyMark) === false && str_contains($stored, $bodyMark) === false,
        (str_contains($printed, $bodyMark) === true ? 'found in what was printed or logged. ' : '') . (str_contains($stored, $bodyMark) === true ? 'found in what is stored.' : '')
    );
    ok(
        'the waiting reset\'s link is in none of them either: not the link, and not its secret part',
        $token !== '' && str_contains($printed, $token) === false && str_contains($stored, $token) === false && stripos($stored, 'reset-password?token=') === false,
        (str_contains($printed, $token) === true ? 'found in what was printed or logged. ' : '') . (str_contains($stored, $token) === true ? 'found in what is stored.' : '')
    );
    $forms = [];
    foreach ([$mailPassword, $otherPassword] as $secret) {
        $forms = array_merge($forms, [
            $secret, htmlspecialchars($secret, ENT_QUOTES, 'UTF-8'), rawurlencode($secret), urlencode($secret), base64_encode($secret),
            base64_encode("\0" . MAILBOX_USER . "\0" . $secret), trim((string) json_encode($secret), '"'),
        ]);
    }
    $found = array_values(array_filter($forms, static fn (string $form): bool => str_contains($printed, $form) === true || str_contains($stored, $form) === true));
    ok(
        'the mailbox\'s password, in every form it could turn up in (as typed, escaped for a page, as an address or a form carries it, as the sign-in sends it, as JSON), is in none of them',
        $found === [],
        count($found) . ' form(s) of a password were found'
    );
    ok(
        'the job\'s token is in nothing that was stored (it is in the answers only where this test itself sent it)',
        str_contains($stored, $jobToken) === false,
        'the token is in a stored record'
    );
    $phpLog  = (string) @file_get_contents($child . $sep . 'php-errors.log');
    $faults  = (int) preg_match_all('/PHP (Fatal|Parse|Warning|Notice|Deprecated)|Uncaught/', $phpLog);
    // (Q9 writes five error lines of its own by hand, two of them under the Microsoft 365 and Google routes' names. They are told apart by their own made-up code.)
    $unknown = q("SELECT errorPlatform, errorCode, errorTitle FROM tblErrors WHERE errorID > ? AND NOT (errorPlatform = 'SMTP' AND errorTitle = 'Mail send fail') AND (errorCode IS NULL OR errorCode <> 'q9-made-up')", 'i', [$errorsFrom]);
    ok(
        'nothing went wrong behind the pages: PHP reported no warning or error anywhere, and the only error records are the mail failures this test caused on purpose and the lines Q9 wrote by hand',
        $faults === 0 && $unknown === [],
        $faults . ' line(s) in PHP\'s log' . ($faults > 0 ? ': ' . mb_substr($phpLog, 0, 600) : '') . '; other error records: ' . json_encode(array_slice($unknown, 0, 5))
    );
}

// =============================================================================
// 🧹 The end
// =============================================================================
q('DELETE FROM tblErrors WHERE errorID > ?', 'i', [$errorsFrom]);
q('DELETE FROM tblActivityLogs WHERE logID > ?', 'i', [$activityFrom]);
cleanup();
$left = (int) one("SELECT COUNT(*) AS n FROM tblMailQueue")['n'] + (int) one('SELECT COUNT(*) AS n FROM tblMailRouteState')['n']
    + (int) one('SELECT COUNT(*) AS n FROM tblUsers WHERE userID BETWEEN 931000 AND 931999')['n'] + (int) one("SELECT COUNT(*) AS n FROM tblSettings WHERE siteID = 1 AND settingKey LIKE 'mail.queue.%'")['n'];
ok('everything this test made has been removed, and the scratch folder is gone', $left === 0 && is_dir($child) === false, $left . ' row(s) left; the scratch folder is ' . (is_dir($child) === true ? 'still there' : 'gone'));

$ran = $pass + $fail;
echo "\n" . $pass . ' passed, ' . $fail . ' failed, ' . $ran . ' ran' . ($only === [] ? ' of ' . EXPECTED_CHECKS . ' expected' : '') . ".\n";
if ($only !== []) {
    echo ($fail === 0 ? 'PARTIAL RUN — the parts ' . implode(', ', $only) . ' passed. This is NOT the whole test.' : 'FAIL — ' . $fail . ' check(s) failed in a run of some parts only.') . "\n";
    echo "REACHED THE END — of a run of some parts only.\n";
    exit($fail === 0 ? 2 : 1);
}
if ($fail > 0 || $ran !== EXPECTED_CHECKS) {
    echo 'FAIL — ' . ($fail > 0 ? $fail . ' check(s) failed. ' : '') . ($ran !== EXPECTED_CHECKS ? 'The number of checks that ran is not the number expected: a part was skipped or ran twice. ' : '') . "Read the FAIL lines above before releasing.\n";
    echo "REACHED THE END — the mail queue self-test ran to its last line, and did not pass.\n";
    exit(1);
}
echo 'PASS — all ' . EXPECTED_CHECKS . " checks passed.\n";
echo "REACHED THE END — the mail queue self-test ran every part.\n";
exit(0);
