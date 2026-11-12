<?php
// Path: tools/scheduled-jobs-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Does every scheduled job really record that it ran? (#775)
 * -----------------------------------------------------------------------------
 * Fifteen addresses under `cron/` are called by whatever runs scheduled tasks
 * on a customer's hosting. Until #775 only ONE left any trace, so a portal
 * could silently stop sending reminders, stop clearing out personal data past
 * its retention period, or stop taking backups, and nothing anywhere would
 * show it.
 *
 * `tools/audit-checks/check_scheduled_jobs_recorded.py` reads the SOURCE: that
 * every job is listed, that each calls both halves with its own key, and that
 * the start is recorded after the token gate. **It says plainly what it cannot
 * see** — whether `finished()` really sits at the END of the work, and whether
 * any of it RUNS. That is the class D-065 settled: where the property is "this
 * code runs", drive it. This file drives it.
 *
 * WHAT IT PROVES, and why each one is here:
 *
 *   A  an unknown job key writes NOTHING. A row under a mistyped name is read
 *      by nothing, so the real job would report "nothing is known yet" for
 *      ever while looking recorded.
 *   B  a start, a finish, and the state each produces.
 *   C  the three states apart: recent, overdue, and began-but-never-finished.
 *   D  a job with no record is NOT_KNOWN, never a warning (D-071).
 *   E  the thresholds really DIFFER BY JOB (D-070). The same age must be
 *      overdue for the mail queue and perfectly fine for giving statements —
 *      and a single threshold for all fifteen would pass every other check in
 *      this file. Every job's figure is also WRITTEN OUT BY HAND here (D-070,
 *      D-076) and compared, and the jobs called every few minutes are shown
 *      overdue at two hours while the nightly ones are not (#793).
 *   F  the window between files landing and somebody pressing "Run
 *      migrations": with the table absent, nothing throws and everything reads
 *      as not-known. If this were wrong the mail queue would stop, and this
 *      class would have caused the very silence it exists to reveal.
 *   G  what the "Check this portal" page actually says, by calling it.
 *   H  TWO REAL CRON PAGES DRIVEN OVER HTTP: the row is written, the finish
 *      lands after the start, and the work really happened in between.
 *   I  A WRONG TOKEN WRITES NOTHING. The security property, driven rather than
 *      read out of the source: a request that could record a start would let
 *      anybody on the internet make a dead job look alive.
 *   J  #817: A JOB THAT DIES ON EVERY RUN IS REPORTED. Each start used to
 *      reset the clock, so such a job read "part-way through" for ever. The
 *      streak of unfinished runs is driven through the real began() and
 *      finished(), the page's words are read, a job merely called again while
 *      still running is NOT reported, and the class still records before
 *      migration 287 has run. Three plants, each in a COPY of the class run in
 *      its own process (this one has the real class loaded), must go red.
 *
 * WHAT IT CANNOT DO, said plainly:
 *   * It drives TWO of the fifteen pages, not all fifteen. The other thirteen
 *     are covered by the source check only. Driving each needs its own fixture
 *     (a feed to import, a claim to chase), which is #789.
 *   * It cannot prove `finished()` is at the end of the LONGEST path through a
 *     page — only that it is reached on the path the test takes.
 *   * **It cannot tell the `SHOW TABLES LIKE` guard inside `began()` and
 *     `finished()` from its absence**, and that is a property of the design
 *     rather than a gap in the test: both the guard and the `try`/`catch`
 *     answer `false`, so no observer can distinguish them. Planted and
 *     confirmed green. That guard exists to avoid throwing and catching an
 *     exception on every call before the migration has run, and to keep "the
 *     table is not there yet" from being answered the same way as "something
 *     is wrong" — a cost-and-clarity measure, not a safety property.
 *
 *     The guard that DOES carry a safety property is the one in `state()`,
 *     and that one is pinned: section F proves a missing table reads as
 *     NOT_KNOWN while a half-applied migration reads as COULD_NOT_READ, and
 *     planting `if (true)` in its place turns F4 red.
 *
 * HOW TO RUN IT
 *   SELFTEST_DB_NAME=selftest_jobs SELFTEST_DB_PORT=3306 SELFTEST_DB_PASS=… \
 *       php tools/scheduled-jobs-selftest.php
 *
 *   SELFTEST_DB_HOST (default 127.0.0.1)   SELFTEST_DB_USER (default root)
 *   SELFTEST_DB_NAME (required; must start with selftest_; built from
 *                     web/_sql/full_schema.sql and used by nothing else)
 *   SELFTEST_WEB_DIR (default the system's temporary folder)
 *   SELFTEST_WEB_PORT (default 9893)
 *
 * @package WebMS\Portal\Tools
 * @author  MWBM Partners Ltd (t/a MWservices)
 * @copyright All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
 * @version 1.0.0
 */

declare(strict_types=1);

$sep   = DIRECTORY_SEPARATOR;
$repo  = dirname(__DIR__);
$state = ['procs' => [], 'child' => null];

$passed = 0;
$failed = 0;

function refuse(string $why): never
{
    // ⚠️ REFUSE LOUDLY, NEVER SKIP. A test that quietly skips reports success
    //    about something it never looked at (#646).
    fwrite(STDERR, "REFUSED: {$why}\n");
    fwrite(STDERR, "Nothing was checked. This is not a pass.\n");
    exit(2);
}

function check(string $label, mixed $got, mixed $want): void
{
    global $passed, $failed;
    if ($got === $want) {
        $passed++;
        echo "  ok    {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$label}\n";
    echo "          wanted: " . var_export($want, true) . "\n";
    echo "          got:    " . var_export($got, true) . "\n";
}

foreach (['curl_init', 'proc_open', 'mysqli_connect'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}

// ── Where to write, and what to connect to ───────────────────────────────
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows and drops a table");
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = (string) getenv('SELFTEST_DB_PASS');
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;
$port   = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9893;

$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = $parentRaw !== false ? (string) $parentRaw : sys_get_temp_dir();
if (is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse('could not connect to the test database');
}
$db->set_charset('utf8mb4');

// The class under test, loaded on its own: it must need nothing else.
require $repo . $sep . 'web' . $sep . '_core' . $sep . 'ScheduledJobs.php';
use Portal\Core\MailQueue;
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it (#536).
//    This one was missing and section H0 died at the very last check.
use Portal\Core\ScheduledJobs;

$tidy = static function () use (&$state, $sep): void {
    foreach ($state['procs'] as $proc) {
        if (is_resource($proc) === true) {
            proc_terminate($proc);
            // Wait for it to really go before the folder is removed: killing
            // and deleting races the server's own writes.
            for ($i = 0; $i < 50; $i++) {
                $info = proc_get_status($proc);
                if ($info['running'] === false) {
                    break;
                }
                usleep(100000);
            }
            proc_close($proc);
        }
    }
    $child = $state['child'];
    if (is_string($child) === true && is_dir($child) === true
        && is_file($child . $sep . '.scheduled-jobs-selftest-owner') === true) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path => $info) {
            $info->isDir() === true ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
};
register_shutdown_function($tidy);

$row = static function (string $jobKey) use ($db): ?array {
    $stmt = $db->prepare(
        'SELECT `jobKey`, `startedAtUtc`, `finishedAtUtc`, `lastOutcome` '
        . 'FROM `tblScheduledJobRun` WHERE `jobKey` = ?'
    );
    $stmt->bind_param('s', $jobKey);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $found === null ? null : $found;
};

$age = static function (string $jobKey, int $seconds, bool $alsoFinish) use ($db): void {
    // Age a row by moving its moments back. Both are UTC, so this is plain
    // arithmetic with no calendar in it.
    $sql = $alsoFinish === true
        ? 'UPDATE `tblScheduledJobRun` SET `startedAtUtc` = UTC_TIMESTAMP() - INTERVAL ? SECOND, '
            . '`finishedAtUtc` = UTC_TIMESTAMP() - INTERVAL ? SECOND WHERE `jobKey` = ?'
        : 'UPDATE `tblScheduledJobRun` SET `startedAtUtc` = UTC_TIMESTAMP() - INTERVAL ? SECOND, '
            . '`finishedAtUtc` = NULL WHERE `jobKey` = ?';
    $stmt = $db->prepare($sql);
    if ($alsoFinish === true) {
        $stmt->bind_param('iis', $seconds, $seconds, $jobKey);
    } else {
        $stmt->bind_param('is', $seconds, $jobKey);
    }
    $stmt->execute();
    $stmt->close();
};

$db->query('DELETE FROM `tblScheduledJobRun`');
ScheduledJobs::forgetTableCheck();

echo "\n=== A. An unknown job key writes nothing ===\n";
check(
    'A1 began() with a key that is not in JOBS answers false',
    ScheduledJobs::began($db, 'cron/not-a-real-job'),
    false
);
check('A2 and it wrote no row', $row('cron/not-a-real-job'), null);
check(
    'A3 finished() with such a key answers false too',
    ScheduledJobs::finished($db, 'cron/not-a-real-job'),
    false
);
check('A4 and still no row', $row('cron/not-a-real-job'), null);
check(
    'A5 isKnownJob() is the one test, and it says no',
    ScheduledJobs::isKnownJob('cron/not-a-real-job'),
    false
);
check(
    'A6 and yes for a real one',
    ScheduledJobs::isKnownJob('cron/mail-queue'),
    true
);

echo "\n=== B. A start, a finish, and what each leaves behind ===\n";
check('B1 began() answers true', ScheduledJobs::began($db, 'cron/backup-run'), true);
$r = $row('cron/backup-run');
check('B2 it wrote a row', is_array($r), true);
check('B3 with a start', is_string($r['startedAtUtc'] ?? null) === true && $r['startedAtUtc'] !== '', true);
check('B4 and NO finish, so a run in flight is visible as exactly that', $r['finishedAtUtc'], null);
check('B5 and no outcome yet', $r['lastOutcome'], '');

check('B6 finished() answers true', ScheduledJobs::finished($db, 'cron/backup-run'), true);
$r = $row('cron/backup-run');
check('B7 the finish is filled in', is_string($r['finishedAtUtc'] ?? null) === true && $r['finishedAtUtc'] !== '', true);
check('B8 and the outcome is ok', $r['lastOutcome'], 'ok');

// ⚠️ NEVER STORE THE WORD GIVEN. A fault's own words can quote the value that
//    caused it, and this row is read by an administrator.
ScheduledJobs::finished($db, 'cron/backup-run', 'the database said: duplicate entry for key PRIMARY');
check(
    'B9 an outcome that is not one of the three words is stored as FAILED, never the words given',
    $row('cron/backup-run')['lastOutcome'],
    'failed'
);
// WHY `failed` AND NOT `refused` FOR AN UNRECOGNISED WORD: it means the caller
// is out of step with the class, so something is wrong — and `refused` reads on
// the page as "somebody switched this off", which would be a reassurance
// nobody has earned.
check(
    'B9b and each of the three real words is kept exactly as given',
    array_map(static function (string $word) use ($db, $row): string {
        ScheduledJobs::finished($db, 'cron/backup-run', $word);
        return (string) $row('cron/backup-run')['lastOutcome'];
    }, [ScheduledJobs::OUTCOME_OK, ScheduledJobs::OUTCOME_REFUSED, ScheduledJobs::OUTCOME_FAILED]),
    ['ok', 'refused', 'failed']
);

ScheduledJobs::finished($db, 'cron/backup-run', ScheduledJobs::OUTCOME_OK);
ScheduledJobs::began($db, 'cron/backup-run');
check(
    'B10 a new start CLEARS the old finish, so the new run cannot read as the last one succeeding',
    $row('cron/backup-run')['finishedAtUtc'],
    null
);
check('B11 and clears the outcome with it', $row('cron/backup-run')['lastOutcome'], '');

// finished() is an upsert, so a page that somehow recorded only a finish still
// records SOMETHING. Silence would look exactly like the job not running.
$db->query("DELETE FROM `tblScheduledJobRun` WHERE `jobKey` = 'cron/health'");
check('B12 finished() alone still writes a row', ScheduledJobs::finished($db, 'cron/health'), true);
check('B13 and that row has both moments', is_string($row('cron/health')['finishedAtUtc']), true);

echo "\n=== C. Recent, overdue, and began-but-never-finished ===\n";
$db->query('DELETE FROM `tblScheduledJobRun`');
ScheduledJobs::began($db, 'cron/backup-run');
ScheduledJobs::finished($db, 'cron/backup-run');
check(
    'C1 a finish a moment ago is RECENT',
    ScheduledJobs::state($db)['cron/backup-run']['status'],
    ScheduledJobs::RECENT
);

$age('cron/backup-run', ScheduledJobs::OVERDUE_NIGHTLY + 60, true);
check(
    'C2 a finish past its own threshold is OVERDUE',
    ScheduledJobs::state($db)['cron/backup-run']['status'],
    ScheduledJobs::OVERDUE
);

$age('cron/backup-run', ScheduledJobs::OVERDUE_NIGHTLY - 60, true);
check(
    'C3 and just inside it is RECENT again, so the boundary is where it says',
    ScheduledJobs::state($db)['cron/backup-run']['status'],
    ScheduledJobs::RECENT
);

$age('cron/backup-run', 30, false);
check(
    'C4 a run that began 30 seconds ago and has not finished is RUNNING_NOW — not a fault, '
    . 'it has simply not finished YET, but NOT counted as finished either',
    ScheduledJobs::state($db)['cron/backup-run']['status'],
    ScheduledJobs::RUNNING_NOW
);
// ⚠️ C4b IS THE CHECK WHOSE ABSENCE LET THE WORST FAULT IN THIS PACKAGE SHIP.
//    C4 pins the CLASSIFICATION, and that is all it ever pinned. The page had
//    no branch for this state, so such a job fell through every test and was
//    swept into the all-clear, which then said "All 15 of them recorded a
//    finish" about a job that had recorded none. Section G drove the page only
//    with everything finished, or one job overdue — never in C4's state.
//
//    **When a check pins how a state is CLASSIFIED, ask what the page SAYS in
//    that state.** The two are different questions and only the second is what
//    an administrator reads.
check(
    'C4b and RUNNING_NOW is NOT the same answer as RECENT, so nothing can fold them back together',
    ScheduledJobs::RUNNING_NOW === ScheduledJobs::RECENT,
    false
);

$age('cron/backup-run', ScheduledJobs::OVERDUE_NIGHTLY + 60, false);
check(
    'C5 a run that began past its threshold and never finished is DIED_PART_WAY, which is NOT the same as overdue',
    ScheduledJobs::state($db)['cron/backup-run']['status'],
    ScheduledJobs::DIED_PART_WAY
);

echo "\n=== D. A job with no record says nothing is known (D-071) ===\n";
$db->query('DELETE FROM `tblScheduledJobRun`');
$all = ScheduledJobs::state($db);
check(
    'D1 EVERY job in JOBS appears in the answer, so none can read as fine by being absent',
    count($all),
    count(ScheduledJobs::JOBS)
);
$statuses = array_unique(array_column($all, 'status'));
check('D2 and with no rows at all, every one is NOT_KNOWN', $statuses, [ScheduledJobs::NOT_KNOWN]);
check('D3 with no seconds to report', $all['cron/mail-queue']['seconds'], null);

echo "\n=== E. The thresholds really differ BY JOB (D-070) ===\n";
// ⚠️ THIS IS THE CHECK A SINGLE THRESHOLD WOULD PASS NOTHING ELSE ON. Every
//    other check in this file uses one job at a time, so one number for all
//    fifteen would satisfy them. Statements go out weekly: if they shared the
//    mail queue's 15 minutes they would read as overdue six days in seven — a
//    permanent false alarm, and a check that cries wolf gets switched off.
$db->query('DELETE FROM `tblScheduledJobRun`');
foreach (['cron/mail-queue', 'cron/giving-statements', 'cron/event-reminders'] as $job) {
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
    $age($job, 10800, true);           // three hours ago, for all three
}
$all = ScheduledJobs::state($db);
check('E1 three hours is OVERDUE for the mail queue (15 minutes)', $all['cron/mail-queue']['status'], ScheduledJobs::OVERDUE);
check('E2 three hours is OVERDUE for event reminders (2 hours)', $all['cron/event-reminders']['status'], ScheduledJobs::OVERDUE);
check('E3 the SAME three hours is RECENT for giving statements (8 days)', $all['cron/giving-statements']['status'], ScheduledJobs::RECENT);
// ⚠️ EVERY FIGURE IS WRITTEN OUT BY HAND HERE, and that is the point (#795).
//    This used to assert only that the constants were five DIFFERENT numbers,
//    which a wrong number passes, and it went stale the moment D-076 gave two
//    jobs the same figure. Asking `ScheduledJobs` what its own figures are
//    would prove only that the class agrees with itself — so the owner's
//    figures are typed below, in seconds, with where each comes from:
//      D-070: mail queue 15 min, event reminders 2 h, import feeds 6 h,
//             giving statements 8 days, the genuinely nightly jobs 36 h.
//      D-076: three times each job's own stated gap, never under 30 minutes.
//    A changed figure must be changed HERE too, deliberately.
$owners = [
    'cron/mail-queue'         => 900,      // 15 minutes (D-070)
    'cron/event-reminders'    => 7200,     // 2 hours (D-070)
    'cron/import-feeds'       => 21600,    // 6 hours (D-070)
    'cron/giving-statements'  => 691200,   // 8 days (D-070)
    'cron/retention-sweep'    => 129600,   // 36 hours (D-070) — "once a night"
    'cron/backup-run'         => 129600,   // 36 hours (D-070) — "a daily call"
    'cron/backup-check'       => 129600,   // 36 hours (D-070) — "a daily call"
    'cron/health'             => 10800,    // 3 hours (D-076) — hourly, by the health page's own line
    'cron/user-reminders'     => 2700,     // 45 minutes (D-076) — "every 15 minutes"
    'cron/asset-reminders'    => 10800,    // 3 hours (D-076) — "every 15-60 minutes"
    'cron/venue-reminders'    => 10800,    // 3 hours (D-076) — "every 30-60 minutes"
    'cron/discipleship-sweep' => 129600,   // 36 hours (D-076) — optional, works by the day
    'cron/push-golive'        => 1800,     // 30 minutes (D-076) — "every 5 minutes", the floor
    'cron/webhook-retry'      => 2700,     // 45 minutes (D-076) — "every 5-15 minutes"
    'cron/workflow-timeouts'  => 10800,    // 3 hours (D-076) — "called hourly"
];
check(
    'E4 every job\'s allowance is the figure the owner chose (D-070, D-076), as typed in this test',
    array_map(static fn (array $facts): int => $facts['overdue'], ScheduledJobs::JOBS),
    $owners
);

// ── #793 / D-076: a job called every few minutes must not get a NIGHT's grace ──
// ⚠️ THE MEASURED FAULT, DRIVEN. Under D-070's "everything else" row a job
//    called every five minutes could be 35 hours late and read as healthy.
//    Two hours is the age the issue asked about: past every frequent job's
//    figure under an hour, and nowhere near a nightly one's.
$db->query('DELETE FROM `tblScheduledJobRun`');
foreach (['cron/webhook-retry', 'cron/push-golive', 'cron/user-reminders', 'cron/retention-sweep',
          'cron/discipleship-sweep'] as $job) {
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
    $age($job, 7200, true);            // two hours ago, for all five
}
$all = ScheduledJobs::state($db);
check('E5 two hours is OVERDUE for the webhook retry (45 minutes)', $all['cron/webhook-retry']['status'], ScheduledJobs::OVERDUE);
check('E5b and for the "We are live" push (30 minutes)', $all['cron/push-golive']['status'], ScheduledJobs::OVERDUE);
check('E5c and for task and personal reminders (45 minutes)', $all['cron/user-reminders']['status'], ScheduledJobs::OVERDUE);
check('E6 the SAME two hours is RECENT for the nightly retention sweep (36 hours)', $all['cron/retention-sweep']['status'], ScheduledJobs::RECENT);
check('E6b and for the optional discipleship sweep, judged nightly (36 hours)', $all['cron/discipleship-sweep']['status'], ScheduledJobs::RECENT);

// Each figure D-076 introduced, from BOTH sides of its own boundary. A figure
// that drifted by more than a minute either way turns one of these red, and the
// boundary used is the literal above, never the class's constant.
foreach (['cron/push-golive', 'cron/webhook-retry', 'cron/user-reminders', 'cron/asset-reminders',
          'cron/venue-reminders', 'cron/workflow-timeouts', 'cron/health'] as $job) {
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
    $age($job, $owners[$job] - 60, true);
    $inside = ScheduledJobs::state($db)[$job]['status'];
    $age($job, $owners[$job] + 60, true);
    $past = ScheduledJobs::state($db)[$job]['status'];
    check(
        'E7 ' . $job . ' is RECENT a minute inside ' . $owners[$job] . ' seconds and OVERDUE a minute past it',
        [$inside, $past],
        [ScheduledJobs::RECENT, ScheduledJobs::OVERDUE]
    );
}

// ── The setup guide must give the same figures ─────────────────────────────
// ⚠️ AN ALLOWANCE A CUSTOMER WAS NEVER TOLD ABOUT IS A FALSE ALARM WAITING TO
//    HAPPEN. D-076's figures assume each job is called as often as its own
//    page says, and until #793 nothing a customer reads said so for most of
//    them — the Venues settings page even said "daily" for a job its own
//    header calls every 30-60 minutes. `docs/INSTALL.md` step 6 now lists
//    every job, and this holds its last column to the figures typed above, so
//    a changed figure that is not also changed in the guide turns this red.
$inWords = [900 => '15 minutes', 1800 => '30 minutes', 2700 => '45 minutes', 7200 => '2 hours',
            10800 => '3 hours', 21600 => '6 hours', 129600 => '36 hours', 691200 => '8 days'];
$guide = (string) file_get_contents($repo . $sep . 'docs' . $sep . 'INSTALL.md');
$guideSays = [];
foreach (array_keys($owners) as $job) {
    $guideSays[$job] = preg_match('/^\s*\| `\/' . preg_quote($job, '/') . '` \|.*\| ([^|]+?) \|\s*$/m', $guide, $m) === 1
        ? $m[1] : '(no row in docs/INSTALL.md)';
}
check(
    'E8 docs/INSTALL.md step 6 tells a customer the same allowance for every job as typed above',
    $guideSays,
    array_map(static fn (int $seconds): string => $inWords[$seconds], $owners)
);

// ── HOW OFTEN each job is called, typed, and tied to its allowance ─────────
// ⚠️ E8 HELD ONLY THE GUIDE'S LAST COLUMN, so the allowance and the instruction
//    it depends on could drift apart with everything green: round 1 of #793's
//    check changed venue reminders to "once a day" in the guide AND put the
//    Venues settings page back to "on a daily schedule", and both suites and
//    every audit script stayed green — while a customer following either would
//    be told every day that the job had stopped. An allowance is only honest
//    against the gap a customer is TOLD, so the places that tell them are held
//    here to one typed gap per job:
//      * the guide's "Call it" column (E9),
//      * each job's own page header, which ScheduledJobs names as the source
//        of its figure (E11),
//      * every other schedule printed for a customer that the search below
//        finds (E12, E12c),
//    every allowance is held to its gap (E10), and every typed wording is
//    held to its typed number (E9b), so a wording and its gap cannot be
//    edited apart.
//
//    ⚠️ "EVERY PLACE" IS A CLAIM ABOUT A SEARCH, AND ROUND 2 OF #793's CHECK
//       FOUND THE FIRST VERSION OF THIS COMMENT UNTRUE: it named two places
//       when there were nine. The list in `$printed` is what these two
//       commands return, outside `web/_apps/cron/` and `docs/decisions/`:
//         grep -rnE "[0-9*/,]+ [0-9*/,]+ \* \* \*" web/_apps README.md docs/INSTALL.md
//         grep -rnE "every (five|fifteen|[0-9]+)( to [0-9]+|-[0-9]+)? minutes|every hour|once a (day|night|week)" \
//             web/_apps README.md docs/INSTALL.md
//       read by hand for the lines that tell a customer how often to call a
//       job. It CANNOT see a schedule worded some other way, and it leaves
//       out `CHANGELOG.md`, whose entries are a record of what shipped, not
//       an instruction kept current.
//
//    'header' is the phrase the job's own page states, or null where it states
//    none (health — judged from the health page's line — and the jobs D-070
//    set by purpose, whose headers this test does not lean on).
$gaps = [
    'cron/mail-queue'         => ['guide' => 'every 5 minutes', 'header' => null, 'gap' => 300],
    'cron/push-golive'        => ['guide' => 'every 5 minutes', 'header' => 'every 5 minutes', 'gap' => 300],
    'cron/webhook-retry'      => ['guide' => 'every 5 to 15 minutes', 'header' => 'every 5-15 minutes', 'gap' => 900],
    'cron/user-reminders'     => ['guide' => 'every 15 minutes', 'header' => 'every 15 minutes', 'gap' => 900],
    'cron/event-reminders'    => ['guide' => 'every 15 minutes', 'header' => null, 'gap' => 900],
    'cron/asset-reminders'    => ['guide' => 'every 15 to 60 minutes', 'header' => 'every 15-60 minutes', 'gap' => 3600],
    'cron/venue-reminders'    => ['guide' => 'every 30 to 60 minutes', 'header' => 'every 30-60 minutes', 'gap' => 3600],
    'cron/workflow-timeouts'  => ['guide' => 'every hour', 'header' => 'called hourly', 'gap' => 3600],
    'cron/health'             => ['guide' => 'every hour', 'header' => null, 'gap' => 3600],
    'cron/import-feeds'       => ['guide' => 'every 15 minutes', 'header' => null, 'gap' => 900],
    'cron/retention-sweep'    => ['guide' => 'once a night', 'header' => 'called once a night', 'gap' => 86400],
    'cron/backup-run'         => ['guide' => 'once a day', 'header' => 'Meant for a daily call', 'gap' => 86400],
    'cron/backup-check'       => ['guide' => 'once a day', 'header' => 'Meant for a daily call', 'gap' => 86400],
    'cron/discipleship-sweep' => ['guide' => 'once a day, while Discipleship Pathways is switched on', 'header' => null, 'gap' => 86400],
    'cron/giving-statements'  => ['guide' => 'at least once a week, while Giving is switched on or statements are waiting to be sent', 'header' => null, 'gap' => 604800],
];
$gapJobs   = array_keys($gaps);
$ownerJobs = array_keys($owners);
sort($gapJobs);
sort($ownerJobs);
check('E9-0 the typed gaps cover exactly the jobs the typed allowances do', $gapJobs, $ownerJobs);

// How long a written schedule means, in seconds. Test code, and deliberately
// small: it knows only the wordings this repository uses, and answers null for
// anything else, which fails the checks below rather than passing them.
$toSeconds = static function (string $said): ?int {
    $said  = strtolower(trim($said));
    $words = ['five' => 5, 'ten' => 10, 'fifteen' => 15, 'thirty' => 30];
    if (preg_match('/^(\S+) (\S+) \* \* \*$/', $said, $m) === 1) {
        [$minute, $hour] = [$m[1], $m[2]];
        if ($hour === '*') {
            if (preg_match('/^\*\/(\d+)$/', $minute, $x) === 1) {
                return (int) $x[1] * 60;
            }
            if (preg_match('/^\d+$/', $minute) === 1) {
                return 3600;
            }
            if (preg_match('/^\d+(,\d+)+$/', $minute) === 1) {
                $at   = array_map('intval', explode(',', $minute));
                $long = 60 - $at[count($at) - 1] + $at[0];
                for ($i = 1; $i < count($at); $i++) {
                    $long = max($long, $at[$i] - $at[$i - 1]);
                }
                return $long * 60;
            }
            return null;
        }
        return (preg_match('/^\d+$/', $minute) === 1 && preg_match('/^\d+$/', $hour) === 1) ? 86400 : null;
    }
    if (preg_match('/every (\w+)(?:(?: to |-)(\w+))? minutes/', $said, $m) === 1) {
        $longer = $m[2] ?? $m[1];
        $n = ctype_digit($longer) === true ? (int) $longer : ($words[$longer] ?? null);
        return $n === null ? null : $n * 60;
    }
    if (str_contains($said, 'every hour') === true || str_contains($said, 'hourly') === true) {
        return 3600;
    }
    if (str_contains($said, 'once a week') === true) {
        return 604800;
    }
    if (str_contains($said, 'once a night') === true || str_contains($said, 'once a day') === true
        || str_contains($said, 'daily') === true) {
        return 86400;
    }
    return null;
};
$wordingAgrees = [];
$wordingWant   = [];
foreach ($gaps as $job => $g) {
    foreach (['guide', 'header'] as $which) {
        if ($g[$which] !== null) {
            $wordingAgrees[$job . ' ' . $which] = $toSeconds($g[$which]);
            $wordingWant[$job . ' ' . $which]   = $g['gap'];
        }
    }
}
check(
    'E9b every typed wording above means its typed gap, so the two cannot be edited apart',
    $wordingAgrees,
    $wordingWant
);

$guideCalls = [];
foreach (array_keys($gaps) as $job) {
    $guideCalls[$job] = preg_match('/^\s*\| `\/' . preg_quote($job, '/') . '` \| ([^|]+?) \|/m', $guide, $m) === 1
        ? $m[1] : '(no row in docs/INSTALL.md)';
}
check(
    'E9 docs/INSTALL.md\'s "Call it" column says, for every job, exactly the cadence typed here',
    $guideCalls,
    array_map(static fn (array $g): string => $g['guide'], $gaps)
);

// ⚠️ "AT LEAST TWICE THE GAP" CANNOT BE ASKED OF EVERY JOB, and the reason is
//    the owner's figures, which this test does not get to change. D-076's
//    seven are three times their gap by rule, so they are held to that. D-070
//    gave the nightly jobs "a night plus a day's grace" (36 hours over a
//    24-hour gap, 1.5 times) and giving statements a week plus a day (8 days
//    over 7). For those the honest requirement is weaker and still worth
//    having: the allowance must be LONGER than the gap, so that a job called
//    exactly as the guide says is never reported as stopped.
$frequent = ['cron/push-golive', 'cron/webhook-retry', 'cron/user-reminders', 'cron/asset-reminders',
             'cron/venue-reminders', 'cron/workflow-timeouts', 'cron/health'];
$tooTight = [];
foreach ($gaps as $job => $g) {
    $needs = in_array($job, $frequent, true) === true ? 3 * $g['gap'] : $g['gap'] + 1;
    if ($owners[$job] < $needs) {
        $tooTight[] = $job . ' (' . $owners[$job] . 's against a gap of ' . $g['gap'] . 's)';
    }
}
check(
    'E10 every allowance is longer than the gap the guide gives, and D-076\'s seven are at least three times it',
    $tooTight,
    []
);

$headersSay = [];
$headersWant = [];
foreach ($gaps as $job => $g) {
    if ($g['header'] === null) {
        continue;
    }
    $page = (string) file_get_contents($repo . $sep . 'web' . $sep . '_apps' . $sep . 'cron' . $sep
        . substr($job, strlen('cron/')) . '.php');
    $headersSay[$job]  = str_contains($page, $g['header']);
    $headersWant[$job] = true;
}
check(
    'E11 each job\'s own page header still states the cadence its allowance was derived from',
    $headersSay,
    $headersWant
);

// Every other place a customer is told how often to call a job (see the
// search above). Each row: the file, the exact text it prints, the job, and
// the cadence in that text. The text must still be there (E12), and its
// cadence must be the job's typed gap (E12c) — so changing the help page's
// mail queue to hourly, or the backup page to once a week, fails here.
$printed = [
    ['web/_apps/venues/settings.php', 'must call it with <code>?key=&lt;token&gt;</code> every 30 to 60 minutes.',
     'cron/venue-reminders', 'every 30 to 60 minutes'],
    ['web/_apps/admin/maintenance/health.php', '<code>0 * * * * curl -fsS "https://',
     'cron/health', '0 * * * *'],
    ['web/_apps/admin/maintenance/backup-check.php', '<code>0 9 * * * curl -fsS "https://',
     'cron/backup-check', '0 9 * * *'],
    ['web/_apps/admin/maintenance/backup.php',
     'Set it to call <code>/cron/backup-run?token=&lt;your token&gt;</code> <strong>once a day</strong>',
     'cron/backup-run', 'once a day'],
    ['web/_apps/help/admin-first-steps.php',
     '<code>0 3 * * * curl -fsS "https://YOUR-PORTAL/cron/retention-sweep?token=YOUR_TOKEN"',
     'cron/retention-sweep', '0 3 * * *'],
    ['web/_apps/help/admin.php',
     'add a job that calls the address <code>/cron/mail-queue</code> on your portal every five minutes',
     'cron/mail-queue', 'every five minutes'],
    ['web/_apps/help/admin.php',
     '<code>*/5 * * * * curl -fsS -H "Authorization: Bearer YOUR_TOKEN" "https://YOUR-PORTAL/cron/mail-queue"',
     'cron/mail-queue', '*/5 * * * *'],
    ['web/_apps/admin/calendar/reminders.php', 'Schedule recommendation: every 15 minutes.',
     'cron/event-reminders', 'every 15 minutes'],
    ['web/_apps/admin/calendar/reminders.php', '<code>0,15,30,45 * * * * curl',
     'cron/event-reminders', '0,15,30,45 * * * *'],
    ['web/_apps/help/calendar-imports.php', 'Ask it to visit this address every 15 minutes:',
     'cron/import-feeds', 'every 15 minutes'],
    ['README.md', '**Add one scheduled job for waiting mail**, every five minutes',
     'cron/mail-queue', 'every five minutes'],
    ['README.md', '`*/5 * * * * curl -fsS -H "Authorization: Bearer YOUR_TOKEN" "https://YOUR-PORTAL/cron/mail-queue"',
     'cron/mail-queue', '*/5 * * * *'],
    ['README.md', 'add one scheduled job for them**, every 15 minutes',
     'cron/import-feeds', 'every 15 minutes'],
    ['README.md', '`*/15 * * * * curl -fsS "https://YOUR-PORTAL/cron/import-feeds?key=YOUR_KEY"',
     'cron/import-feeds', '*/15 * * * *'],
];
$stillThere = [];
$cadence    = [];
$cadenceWant = [];
foreach ($printed as $i => [$file, $text, $job, $said]) {
    $label = $i . ' ' . $file . ' (' . $job . ')';
    $body  = (string) file_get_contents($repo . $sep . str_replace('/', $sep, $file));
    $stillThere[$label]  = str_contains($body, $text) === true && str_contains($text, $said) === true;
    $cadence[$label]     = $toSeconds($said);
    $cadenceWant[$label] = $gaps[$job]['gap'];
}
check(
    'E12 every schedule printed for a customer is still printed as listed (' . count($printed) . ' places)',
    $stillThere,
    array_fill_keys(array_keys($stillThere), true)
);
check(
    'E12b and the Venues page no longer says "on a daily schedule" anywhere',
    str_contains((string) file_get_contents($repo . $sep . 'web' . $sep . '_apps' . $sep . 'venues' . $sep
        . 'settings.php'), 'on a daily schedule'),
    false
);
check(
    'E12c each of those places gives the same gap as the guide does for that job',
    $cadence,
    $cadenceWant
);

echo "\n=== F. The window before anybody presses Run migrations ===\n";
// Files reach a customer's server BEFORE the migration runs (#694), so these
// pages are called while the table does not exist. If that threw, the mail
// queue, the reminders and the retention sweep would ALL stop — this class
// would have caused the exact silence it exists to reveal.
$db->query('DROP TABLE `tblScheduledJobRun`');
ScheduledJobs::forgetTableCheck();
$threw = false;
try {
    $beganAnswer    = ScheduledJobs::began($db, 'cron/mail-queue');
    $finishedAnswer = ScheduledJobs::finished($db, 'cron/mail-queue');
    $stateAnswer    = ScheduledJobs::state($db);
} catch (Throwable $e) {
    $threw = true;
}
check('F1 nothing throws with the table absent', $threw, false);
check('F2 began() answers false rather than throwing', $beganAnswer ?? null, false);
check('F3 finished() answers false too', $finishedAnswer ?? null, false);
check(
    'F4 and every job reads as NOT_KNOWN, which is the truthful answer',
    array_unique(array_column($stateAnswer ?? [], 'status')),
    [ScheduledJobs::NOT_KNOWN]
);
check('F5 with every job still listed', count($stateAnswer ?? []), count(ScheduledJobs::JOBS));

// Put it back exactly as the migration declares it, from the migration itself,
// so this test cannot disagree with what ships.
$migration = (string) file_get_contents($repo . $sep . 'web' . $sep . '_sql' . $sep . '253_scheduled_jobs_record_their_runs.sql');
if (preg_match('/CREATE TABLE IF NOT EXISTS `tblScheduledJobRun`.*?;/s', $migration, $grab) !== 1) {
    refuse('could not find the CREATE TABLE in migration 253, so this test cannot restore the table it dropped');
}
$db->query($grab[0]);
// #817: and migration 287's two columns, from the migration itself, so every
// section after this one runs on the table as it ships — not on 253's shape,
// which the class now treats as "287 has not run yet" (section J checks that).
$migration287 = (string) file_get_contents($repo . $sep . 'web' . $sep . '_sql' . $sep . '287_scheduled_jobs_count_unfinished_runs.sql');
$apply287 = static function () use ($db, $migration287): void {
    $db->multi_query($migration287);
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($db->more_results() === true && $db->next_result() === true);
};
$apply287();
ScheduledJobs::forgetTableCheck();
check('F6 the table is back, taken from the migration itself and not a copy', is_array($row('cron/mail-queue')) || $row('cron/mail-queue') === null, true);
check(
    'F7 and the migration really is safe to run twice',
    (static function () use ($db, $grab): bool {
        try {
            $db->query($grab[0]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    })(),
    true
);

// ── A HALF-APPLIED MIGRATION: the table there, a column missing ──────────
// ⚠️ THIS IS WHAT MAKES THE `SHOW TABLES LIKE` GUARD LOAD-BEARING, and
//    without it part F passed with that guard DELETED — because the
//    try/catch answered identically, so nothing could tell the guard from
//    its absence. Planted and measured.
//
//    It is also a realistic state rather than a contrived one: a migration
//    that stopped part-way leaves exactly this.
$db->query('DROP TABLE `tblScheduledJobRun`');
$db->query(
    'CREATE TABLE `tblScheduledJobRun` ('
    . '`jobKey` VARCHAR(40) NOT NULL, PRIMARY KEY (`jobKey`)'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
ScheduledJobs::forgetTableCheck();
$threw = false;
try {
    $halfState = ScheduledJobs::state($db);
} catch (Throwable $e) {
    $threw = true;
}
check('F8 a half-applied migration does not make the page throw', $threw, false);
check(
    'F9 and every job reads as COULD_NOT_READ, never as "nothing is known yet" — '
    . 'which would tell an administrator to wait for something that will never happen',
    array_unique(array_column($halfState ?? [], 'status')),
    [ScheduledJobs::COULD_NOT_READ]
);
check(
    'F10 which is a DIFFERENT answer from the table being absent',
    ScheduledJobs::COULD_NOT_READ === ScheduledJobs::NOT_KNOWN,
    false
);
$db->query('DROP TABLE `tblScheduledJobRun`');
$db->query($grab[0]);
$apply287();
ScheduledJobs::forgetTableCheck();

// PortalCheck is required here because the token seeding below reads its
// JOB_TOKENS list, and section G calls its scheduledJobs() by reflection.
//
// ⚠️ MailQueue COMES WITH IT. `scheduledJobs()` asks
//    `MailQueue::jobIsRunning()` for the mail job while the new record has
//    nothing to say (the independent check's finding 3), and this test has no
//    autoloader — the portal does, so this is a requirement of the TEST and
//    not of the page. Without it the section died with "Class MailQueue not
//    found" rather than reporting anything.
require $repo . $sep . 'web' . $sep . '_core' . $sep . 'MailRoutes.php';
require $repo . $sep . 'web' . $sep . '_core' . $sep . 'MailQueue.php';
require $repo . $sep . 'web' . $sep . '_core' . $sep . 'PortalCheck.php';

$setSetting = static function (string $key, string $value) use ($db): void {
    $stmt = $db->prepare(
        'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) '
        . 'VALUES (NULL, ?, ?, \'\', 0) '
        . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)'
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
};

// A plaintext token. `SecretSettings::forReader()` hands back a value it
// cannot decrypt unchanged, so a short token works and nothing is encrypted.
$token = bin2hex(random_bytes(16));

// ⚠️ EVERY TOKEN IS SET BEFORE THE PAGE IS ASKED ANYTHING, and leaving them
//    unset made this whole section vacuous the moment the page started
//    excluding token-less jobs from its later findings.
//
//    `full_schema.sql` seeds all eleven tokens EMPTY on purpose, so a fresh
//    test database is a portal whose jobs can never run. Every check below
//    then got NULL — no finding at all — because the only true thing to say
//    about such a portal is the no-token PROBLEM. They passed before only
//    because the page used to report a job as overdue while also reporting
//    that it had no token, which is the contradiction the independent check
//    found (finding 4).
//
//    **A fixture that cannot reach the state a check is about tests nothing**,
//    and the other half of that lesson is here too: these checks had been
//    GREEN against a world where their subject did not exist.
foreach (array_keys(\Portal\Core\PortalCheck::JOB_TOKENS) as $tokenKey) {
    $setSetting($tokenKey, $token);
}
// ⚠️ GIVING ON, so every job is NEEDED in this world. `full_schema.sql` seeds
//    `giving.enabled` '0', and since #793 round 1 a job whose app is switched
//    off everywhere is not counted (`PortalCheck::JOB_NEEDS_FEATURE`), so the
//    sentences below would otherwise be about fourteen jobs. The off state is
//    driven in section Q of `tools/portal-check-selftest.php`.
$setSetting('giving.enabled', '1');

echo "\n=== G. What the Check this portal page actually says ===\n";
// ⚠️ THE REAL METHOD, reached by reflection because it is private. A copy of
//    its logic in this test would prove only that the copy agrees with itself
//    (#647).
$pageSays = static function () use ($db): array {
    // ⚠️ NO setAccessible(): it has had no effect since PHP 8.1 and PHP 8.5
    //    DEPRECATES it, so calling it would print a notice on the version this
    //    portal recommends.
    $method = new ReflectionMethod(\Portal\Core\PortalCheck::class, 'scheduledJobs');

    return $method->invoke(null, $db, 1);
};
$severityOf = static function (array $findings, string $needle): ?string {
    foreach ($findings as $finding) {
        if (str_contains($finding['what'], $needle) === true) {
            return $finding['severity'];
        }
    }

    return null;
};

$db->query('DELETE FROM `tblScheduledJobRun`');
$found = $pageSays();
check(
    'G1 with no records at all, the page says nothing is known yet',
    $severityOf($found, 'Nothing is known yet'),
    \Portal\Core\PortalCheck::NOTE
);
// D-071: a NOTE, never a WARNING. Warning here would give every
// correctly-configured portal a page of warnings the morning after it upgrades.
check(
    'G2 and that is a NOTE, never a warning (D-071)',
    $severityOf($found, 'Nothing is known yet') === \Portal\Core\PortalCheck::WARNING,
    false
);
check(
    'G3 and it does NOT claim every job has run',
    $severityOf($found, 'has run recently enough'),
    null
);

foreach (array_keys(ScheduledJobs::JOBS) as $job) {
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
}
$found = $pageSays();
check(
    'G4 with every job just finished, the page says so',
    $severityOf($found, 'has run recently enough'),
    \Portal\Core\PortalCheck::NOTE
);
check('G5 and says nothing about anything being unknown', $severityOf($found, 'Nothing is known yet'), null);

$age('cron/retention-sweep', ScheduledJobs::OVERDUE_NIGHTLY + 600, true);
check(
    'G6 one overdue job is a PROBLEM, not a note',
    $severityOf($pageSays(), 'have not run for longer'),
    \Portal\Core\PortalCheck::PROBLEM
);
check('G7 and the all-clear is gone', $severityOf($pageSays(), 'has run recently enough'), null);

// The half-applied-migration state, as the page words it.
$db->query('DROP TABLE `tblScheduledJobRun`');
$db->query('CREATE TABLE `tblScheduledJobRun` (`jobKey` VARCHAR(40) NOT NULL, PRIMARY KEY (`jobKey`)) ENGINE=InnoDB');
ScheduledJobs::forgetTableCheck();
$found = $pageSays();
check(
    'G13 a record that cannot be read is a WARNING of its own',
    $severityOf($found, 'could not be read'),
    \Portal\Core\PortalCheck::WARNING
);
check(
    'G14 and is NOT the reassuring "nothing is known yet" note',
    $severityOf($found, 'Nothing is known yet'),
    null
);
check('G15 nor an all-clear', $severityOf($found, 'has run recently enough'), null);
$db->query('DROP TABLE `tblScheduledJobRun`');
$db->query($grab[0]);
$apply287();
ScheduledJobs::forgetTableCheck();
foreach (array_keys(ScheduledJobs::JOBS) as $job) {
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
}

$age('cron/retention-sweep', ScheduledJobs::OVERDUE_NIGHTLY + 600, false);
check(
    'G8 a job that started and never finished is its own PROBLEM, worded differently',
    $severityOf($pageSays(), 'started and never finished'),
    \Portal\Core\PortalCheck::PROBLEM
);

// A failing backup must NEVER be described as switched off.
ScheduledJobs::began($db, 'cron/retention-sweep');
ScheduledJobs::finished($db, 'cron/retention-sweep');
ScheduledJobs::finished($db, 'cron/backup-run', ScheduledJobs::OUTCOME_FAILED);
$found = $pageSays();
check(
    'G9 a run that FAILED is a PROBLEM of its own',
    $severityOf($found, 'could not finish their work'),
    \Portal\Core\PortalCheck::PROBLEM
);
check(
    'G10 and is NOT reported as being switched off, which a single outcome word did',
    $severityOf($found, 'switched off'),
    null
);

ScheduledJobs::finished($db, 'cron/backup-run', ScheduledJobs::OUTCOME_REFUSED);
$found = $pageSays();
// The needle is the NEW wording. It used to be 'switched off', which the
// independent check showed was false in this state's commonest cause — so a
// check searching for those words would now pin the untruth back in place
// (this repository's "a check that pins a reassurance" shape).
check(
    'G11 a run that deliberately did nothing is only a NOTE',
    $severityOf($found, 'deliberately did nothing'),
    \Portal\Core\PortalCheck::NOTE
);
check('G12 and is not reported as a failure', $severityOf($found, 'could not finish their work'), null);

echo "\n=== H. Two real cron pages, driven over HTTP ===\n";
// ⚠️ THIS IS WHY THIS FILE EXISTS. The source check proves the two calls are
//    there and in the right order. It CANNOT prove they run, nor that a
//    switched-off path records a finish — and that second one is not a
//    hypothetical: it is the fault this package shipped first. D-065's rule is
//    that where the property is "this code RUNS", drive it.
$webSrc = $repo . $sep . 'web';
$child  = $parent . $sep . 'scheduled-jobs-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
// The marker FIRST, so the tidy-up will only ever delete a folder this run made.
file_put_contents($child . $sep . '.scheduled-jobs-selftest-owner', bin2hex(random_bytes(8)));
mkdir($child . $sep . 'web', 0700);
$copied = 0;
$tree = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
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
$creds = ['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort];
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/scheduled-jobs-selftest.php for ONE run; deleted with its folder.\nreturn "
    . var_export($creds, true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

$router = <<<'ROUTER'
<?php
// Written by tools/scheduled-jobs-selftest.php for ONE run. Test only.
declare(strict_types=1);
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1
    && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) {
    http_response_code(404); exit('404');
}
$target = $root . '/index.php';
$real   = ($uri === '/') ? false : realpath($root . $uri);
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
file_put_contents($child . $sep . 'router.php', $router);

$env = getenv();
// ⚠️ ONE WORKER. Two requests must never overlap, or a test of "exactly one
//    row was written" becomes a race (#647's recorded limit).
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['procs'][] = proc_open(
    [
        PHP_BINARY,
        '-d', 'session.save_path=' . $child . $sep . 'sessions',
        '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'opcache.enable=0',
        '-d', 'max_execution_time=120',
        '-S', '127.0.0.1:' . $port,
        '-t', $child . $sep . 'web' . $sep . 'public_html',
        $child . $sep . 'router.php',
    ],
    [0 => ['file', '/dev/null', 'r'],
     1 => ['file', $child . $sep . 'server.log', 'a'],
     2 => ['file', $child . $sep . 'server.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['procs'][0]) === false) {
    refuse('the test server could not be started');
}

$get = static function (string $path) use ($port): array {
    $curl = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HEADER         => false,
    ]);
    $body = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    // ⚠️ NO curl_close(): a no-op since PHP 8.0 and DEPRECATED in 8.5, so
    //    calling it prints a notice on the version this portal recommends.
    //    The handle is freed when $curl goes out of scope.

    return ['code' => $code, 'body' => is_string($body) === true ? $body : ''];
};

// Wait for it, and REFUSE rather than skip if it never answers.
$up = false;
for ($i = 0; $i < 100; $i++) {
    if ($get('/')['code'] > 0) {
        $up = true;
        break;
    }
    usleep(100000);
}
if ($up === false) {
    refuse('the test server never answered on port ' . $port);
}

$setSetting('webhooks.cron_token', $token);
$setSetting('webhooks.enabled', 'true');

$db->query("DELETE FROM `tblScheduledJobRun` WHERE `jobKey` = 'cron/webhook-retry'");
check('H1 no record before the job is ever called', $row('cron/webhook-retry'), null);

$answer = $get('/cron/webhook-retry?key=' . $token);
check('H2 the real page answers 200 to the right token', $answer['code'], 200);
$r = $row('cron/webhook-retry');
check('H3 and it really wrote a record — the calls RUN, not merely exist', is_array($r), true);
check('H4 with a start', ($r['startedAtUtc'] ?? '') !== '', true);
check('H5 and a finish, so the work ran to the end', ($r['finishedAtUtc'] ?? null) !== null, true);
check('H6 recorded as having done its work', $r['lastOutcome'] ?? null, 'ok');
check(
    'H7 the finish is not before the start',
    strtotime((string) $r['finishedAtUtc']) >= strtotime((string) $r['startedAtUtc']),
    true
);
check(
    'H8 and the state reads RECENT',
    ScheduledJobs::state($db)['cron/webhook-retry']['status'],
    ScheduledJobs::RECENT
);

// ── The blind spot, driven ────────────────────────────────────────────────
// ⚠️ THE CHECK SCRIPT CANNOT SEE THIS, AND SAYS SO. A page may hold several
//    finishes, so it requires only one — and deleting the finish from the
//    switched-off path alone leaves it green. This is what notices.
$setSetting('webhooks.enabled', 'false');
$db->query("DELETE FROM `tblScheduledJobRun` WHERE `jobKey` = 'cron/webhook-retry'");
$answer = $get('/cron/webhook-retry?key=' . $token);
check('H9 a switched-off job still answers 200', $answer['code'], 200);
check('H10 and says it is switched off', str_contains($answer['body'], 'disabled'), true);
$r = $row('cron/webhook-retry');
check('H11 it recorded a run', is_array($r), true);
check(
    'H12 WITH A FINISH. Without this, a job somebody deliberately switched off '
    . 'reads as "started and never finished" on the check page, as a problem, for ever',
    ($r['finishedAtUtc'] ?? null) !== null,
    true
);
check('H13 recorded as having deliberately done nothing', $r['lastOutcome'] ?? null, 'refused');
$aged = $age('cron/webhook-retry', 2700 + 600, true);   // past its own 45 minutes (D-076)
check(
    'H14 and even long afterwards it is OVERDUE at worst, never DIED_PART_WAY — which is the real harm the finish prevents',
    ScheduledJobs::state($db)['cron/webhook-retry']['status'] === ScheduledJobs::DIED_PART_WAY,
    false
);
$setSetting('webhooks.enabled', 'true');

echo "\n=== I. A wrong token records NOTHING ===\n";
// ⚠️ THE SECURITY PROPERTY, DRIVEN RATHER THAN READ. A request that could
//    record a start would let anybody on the internet make a job that has
//    stopped look alive — turning the Check this portal page from a true
//    report into a forgeable one.
$db->query("DELETE FROM `tblScheduledJobRun` WHERE `jobKey` = 'cron/webhook-retry'");
$answer = $get('/cron/webhook-retry?key=' . bin2hex(random_bytes(16)));
check('I1 a wrong token is refused', $answer['code'], 403);
check('I2 and NOTHING was recorded', $row('cron/webhook-retry'), null);

$answer = $get('/cron/webhook-retry');
check('I3 no token at all is refused', $answer['code'], 403);
check('I4 and still nothing recorded', $row('cron/webhook-retry'), null);

// An empty stored token must refuse every request, so the job is inert until
// somebody sets one — and must still record nothing.
$setSetting('webhooks.cron_token', '');
$answer = $get('/cron/webhook-retry?key=');
check('I5 an empty stored token refuses even an empty supplied one', $answer['code'], 403);
check('I6 and records nothing', $row('cron/webhook-retry'), null);
$setSetting('webhooks.cron_token', $token);

// ── A second page, so this is not one page's quirk ───────────────────────
$setSetting('maintenance.cronToken', $token);
$db->query("DELETE FROM `tblScheduledJobRun` WHERE `jobKey` = 'cron/health'");
$answer = $get('/cron/health?token=' . $token);
check('I7 a SECOND real page answers', in_array($answer['code'], [200, 500], true), true);
$r = $row('cron/health');
check('I8 and recorded its own run under its own key', is_array($r), true);
check('I9 with a finish', ($r['finishedAtUtc'] ?? null) !== null, true);
$db->query("DELETE FROM `tblScheduledJobRun` WHERE `jobKey` = 'cron/health'");
$answer = $get('/cron/health?token=' . bin2hex(random_bytes(16)));
check('I10 and refuses a wrong token', $answer['code'], 403);
check('I11 recording nothing', $row('cron/health'), null);

// ⚠️ NOTHING THE PORTAL DID MAY HAVE WRITTEN A PHP ERROR. A green run above
//    with warnings in the log is not a pass.
$log = $child . $sep . 'php-errors.log';
$logText = is_file($log) === true ? (string) file_get_contents($log) : '';
$noisy = [];
foreach (['PHP Fatal', 'PHP Warning', 'PHP Deprecated', 'Undefined variable', 'Undefined array key'] as $bad) {
    if (str_contains($logText, $bad) === true) {
        $noisy[] = $bad;
    }
}
check('I12 the server wrote no PHP error, warning or deprecation', $noisy, []);

echo "\n=== I-ALL. EVERY page refuses a wrong token and records NOTHING ===\n";
// 🚨 THIS REPLACES A SOURCE CHECK THAT WAS WRONG IN BOTH DIRECTIONS FOUR TIMES,
//    AND IT IS THE OWNER'S OWN RULE FOR THIS SITUATION (D-065): where the
//    property is "this code RUNS", DRIVE it; where it is "this code LOOKS like
//    this", pin it.
//
//    "Does this gate refuse an unauthenticated request" is BEHAVIOURAL. Five
//    versions of `gate_refuses()` tried to read it out of the source, and each
//    was defeated — v1 by an exit in a closure, v2 by `$log->exit()`, v3 by an
//    arrow function, v4 by an arrow body containing a brace, v5 by an `else`
//    branch (false red) and by `!hash_equals(...)` (false pass). Round 6 then
//    found the shape that settles it:
//
//        if (($_GET['debug'] ?? '') !== '1') {
//            if ($expected === '' || hash_equals(...) === false) { exit; }
//        }
//
//    A gate the REQUEST decides whether to apply. Every committed guard stayed
//    green and `?debug=1` let anybody on the internet write the record and run
//    the job's real work. **No source reading can see that**, and the only
//    thing that found it was an HTTP probe.
//
//    So the detector is gone and this is what replaces it, over all fifteen
//    pages rather than two. Every wrapping the detector caught also fails this
//    — a gate that does not refuse answers something other than 403 — and this
//    catches the classes it provably could not.
//
//    `ScheduledJobs::JOBS` supplies the address and the token setting, so a new
//    job is covered the day it is listed. The query parameter differs by page
//    (`key` or `token`), which is read from the page itself rather than kept in
//    a second list.
$tokenParamFor = static function (string $jobKey) use ($repo, $sep): string {
    $file = $repo . $sep . 'web' . $sep . '_apps' . $sep . 'cron' . $sep
        . substr($jobKey, strlen('cron/')) . '.php';
    $src = (string) file_get_contents($file);
    // ⚠️ ASKED OF THE PAGE, NOT TYPED HERE. A second list would drift, and a
    //    page whose parameter was renamed would then be driven with the wrong
    //    one — which looks exactly like a refusal, so the check would pass
    //    while testing nothing.
    if (preg_match("/\\\$_GET\\['(key|token)'\\]/", $src, $hit) !== 1) {
        return '';
    }

    return $hit[1];
};

$drivenOk = 0;
foreach (ScheduledJobs::JOBS as $jobKey => $facts) {
    $param = $tokenParamFor($jobKey);
    check('IA ' . $jobKey . ' reads its token from a query parameter', $param !== '', true);
    if ($param === '') {
        continue;
    }
    $setSetting($facts['token'], $token);
    MailQueue::forget();
    $db->query('DELETE FROM `tblScheduledJobRun`');

    // A wrong token, from somebody who does not have it.
    $answer = $get('/' . $jobKey . '?' . $param . '=' . bin2hex(random_bytes(16)));
    check('IA ' . $jobKey . ' refuses a wrong token', $answer['code'], 403);
    check('IA ' . $jobKey . ' and records NOTHING', $row($jobKey), null);

    // No token at all.
    $answer = $get('/' . $jobKey);
    check('IA ' . $jobKey . ' refuses no token at all', $answer['code'], 403);
    check('IA ' . $jobKey . ' and still records nothing', $row($jobKey), null);
    $drivenOk++;
}
check(
    'IA-all every one of the ' . count(ScheduledJobs::JOBS) . ' jobs was driven, not just the two '
    . 'the old section covered — a count, so a job silently dropped from the loop is caught',
    $drivenOk,
    count(ScheduledJobs::JOBS)
);

echo "\n=== H0. What the page SAYS in the states the independent check found ===\n";
// Every one of these is a sentence an administrator reads, printed by calling
// the real code. Three of the four PORTAL faults in this package were sentences
// that were false in the state producing them, and all three were found by
// PRINTING them rather than reading the code.
$allText = static function (array $findings): string {
    $out = '';
    foreach ($findings as $f) {
        // ⚠️ THE EXAMPLES COUNT AS WORDS THE ADMINISTRATOR READS. The page
        //    prints them under each finding, and the per-job detail — "last ran
        //    3 days ago", "has not run in the last 15 minutes" — lives ONLY
        //    there. Leaving them out made a check look for a sentence that was
        //    on the page and report it absent.
        $out .= $f['what'] . ' ' . $f['why'] . ' ' . $f['todo'] . ' '
            . implode(' ', $f['examples'] ?? []) . ' ';
    }

    return $out;
};

// ── The all-clear must not claim a finish for a job that is mid-run ─────
$db->query('DELETE FROM `tblScheduledJobRun`');
foreach (array_keys(ScheduledJobs::JOBS) as $job) {
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
}
$age('cron/backup-run', 600, false);          // began 10 minutes ago, no finish
$found = $pageSays();
$text  = $allText($found);
check(
    'H0a a job mid-run is reported as part-way through, in its own finding',
    $severityOf($found, 'part-way through when this page was drawn'),
    \Portal\Core\PortalCheck::NOTE
);
check(
    'H0b and the all-clear no longer claims ALL of them recorded a finish — '
    . 'it used to say exactly that about a job that had recorded none',
    str_contains($text, 'All ' . count(ScheduledJobs::JOBS) . ' of them recorded a finish'),
    false
);
check(
    'H0c the number it DOES give excludes the mid-run job, and says what it is out of',
    str_contains($text, (string) (count(ScheduledJobs::JOBS) - 1) . ' of the '
        . count(ScheduledJobs::JOBS) . ' recorded a FINISH'),
    true
);
check(
    'H0c2 and names WHY that job is not counted, rather than leaving an unexplained number',
    str_contains($text, 'the 1 part-way through, which has not recorded a finish'),
    true
);
// ⚠️ THE ALLOWANCE SENTENCE IS PINNED, AS TEXT WRITTEN OUT BY HAND (#795).
//    Nothing used to read a character past "recorded a FINISH", so reverting
//    the sentence to round 4's withdrawn wording left every guard green, and
//    round 5's replacement was wrong about `cron/import-feeds` (six hours told
//    as 36) with nothing noticing.
//
//    The first pin asked `ScheduledJobs::allowancesInWords()` for the figures
//    and looked for its answer on the page — so the page and the test agreed
//    on whatever that method said. Measured (#795): making it report every
//    allowance at DOUBLE its length left the whole suite green. The whole
//    reason, figures included, is typed below; a change to the wording or to
//    any figure must be made here too, deliberately.
$expectedWhy = '14 of the 15 recorded a FINISH inside the time allowed for it. '
    . 'That allowance is set for each job from what it is for and how often it is meant to run: '
    . '15 minutes (the mail queue), 30 minutes (the live-stream push), 45 minutes (2 jobs), '
    . '2 hours (event reminders), 3 hours (4 jobs), 6 hours (feed imports), 36 hours (4 jobs) '
    . 'and 8 days (giving statements). '
    . 'Not counted here: the 1 part-way through, which has not recorded a finish '
    . '— it is reported above.';
$allClearWhy = null;
foreach ($found as $finding) {
    if (str_contains($finding['what'], 'has run recently enough') === true) {
        $allClearWhy = $finding['why'];
    }
}
check(
    'H0c3 the all-clear\'s reason is exactly the sentence written out here, every figure included '
    . '— it once named four allowances of five, so one job was told six times its real figure',
    $allClearWhy,
    $expectedWhy
);
check(
    'H0c4 and does NOT carry round 4\'s withdrawn claim that the allowance matches each job',
    str_contains($text, 'the nightly jobs daily'),
    false
);

// ── `refused` must not be worded as one of its three causes ────────────
$db->query('DELETE FROM `tblScheduledJobRun`');
foreach (array_keys(ScheduledJobs::JOBS) as $job) {
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
}
// The commonest real cause, and NOT a setting: the newest backup is not old
// enough yet, which on a weekly schedule happens six nights in seven.
ScheduledJobs::finished($db, 'cron/backup-run', ScheduledJobs::OUTCOME_REFUSED);
$text = $allText($pageSays());
check(
    'H0d a refused run is NOT described as "switched off", which was false in its '
    . 'commonest state — nothing switched off, the work being done, and no setting to look at',
    str_contains($text, 'are being called but are switched off'),
    false
);
check(
    'H0e nor does it tell the administrator a setting turns them off',
    str_contains($text, 'because a setting turns them off'),
    false
);
check(
    'H0f it says what is KNOWN — the job ran and chose to do nothing',
    str_contains($text, 'deliberately did nothing'),
    true
);
check(
    'H0g and names all three reasons rather than asserting one',
    str_contains($text, 'another run of it was already in progress')
        && str_contains($text, 'was not due yet'),
    true
);

// ── The overdue finding must not contradict the token finding ──────────
$db->query('DELETE FROM `tblScheduledJobRun`');
ScheduledJobs::began($db, 'cron/retention-sweep');
ScheduledJobs::finished($db, 'cron/retention-sweep');
$age('cron/retention-sweep', ScheduledJobs::OVERDUE_NIGHTLY + 3600, true);
$setSetting('maintenance.cronToken', '');     // cleared AFTER the job had run
$found = $pageSays();
check(
    'H0h with the token cleared, the no-token PROBLEM is reported',
    $severityOf($found, 'can never run, because no token has been set'),
    \Portal\Core\PortalCheck::PROBLEM
);
check(
    'H0i and the SAME job is NOT also reported as overdue with "has a token set, so it CAN run" — '
    . 'one answer used to say a job both could and could not run',
    $severityOf($found, 'have not run for longer'),
    null
);
$setSetting('maintenance.cronToken', $token);

// ── The mail job's older record must still be read while the new one is
//    silent, which is the upgrade-day case (finding 3) ──────────────────
// ⚠️ THIS IS THE STATE EVERY EXISTING PORTAL IS IN THE MORNING AFTER THE
//    UPGRADE: `tblScheduledJobRun` is empty (D-071, correctly), and
//    `tblMailRouteState.jobLastRanAt` still holds real history. Dropping that
//    second reading downgraded a portal whose mail job had GENUINELY STOPPED
//    from a warning with an action to a note saying "Nothing".
$db->query('DELETE FROM `tblScheduledJobRun`');
$db->query(
    "INSERT INTO `tblMailRouteState` (`routeKey`, `jobLastRanAt`) "
    . "VALUES ('installation', UTC_TIMESTAMP() - INTERVAL 30 DAY) "
    . "ON DUPLICATE KEY UPDATE `jobLastRanAt` = UTC_TIMESTAMP() - INTERVAL 30 DAY"
);
MailQueue::forget();
$found = $pageSays();
$text  = $allText($found);
check(
    'H0j with NO new record and a mail job that last ran 30 days ago, the page '
    . 'still reports it — not as "nothing is known yet"',
    $severityOf($found, 'mail job has not run'),
    \Portal\Core\PortalCheck::WARNING
);
check(
    'H0k and says what stops without it',
    str_contains($text, 'mail that could not go out at once'),
    true
);

// And the moment the job records once, the older reading stops being used:
// one job, one answer, always.
ScheduledJobs::began($db, 'cron/mail-queue');
ScheduledJobs::finished($db, 'cron/mail-queue');
MailQueue::forget();
$text = $allText($pageSays());
check(
    'H0l once the new record says the mail job just ran, the older reading is '
    . 'no longer used — so one page never gives two answers about one job',
    str_contains($text, 'has not run in the last 15 minutes'),
    false
);

// ── Some tokens missing AND every token-bearing job healthy ──────────────
// ⚠️ THIS IS THE STEADY STATE OF AN ORDINARY PORTAL, AND NO FIXTURE REACHED
//    IT. There are eleven separate tokens, `full_schema.sql` seeds every one
//    EMPTY, nothing in the installer sets any, and a customer who does not use
//    the noticeboard push, discipleship or venue agreements leaves those empty
//    for ever.
//
//    Section G sets EVERY token (round 1's own fix for its vacuity) and H0h/H0i
//    clear one but leave ten jobs with no record, so `count($notKnown) > 0`
//    suppressed the all-clear. **Closing one blind spot opened another at the
//    far end**, and what lived in it was a permanent falsehood: "Every
//    scheduled job has run recently enough" printed directly under a PROBLEM
//    saying seven of them can never run — under the sentence warning that
//    personal data may not be being deleted.
$db->query('DELETE FROM `tblScheduledJobRun`');
foreach (array_keys(\Portal\Core\PortalCheck::JOB_TOKENS) as $tokenKey) {
    $setSetting($tokenKey, '');
}
// The four an administrator following the guide really does set.
foreach (['maintenance.cronToken', 'reminders.cron_token',
          'feeds.cron_token', 'giving.cron_token'] as $tokenKey) {
    $setSetting($tokenKey, $token);
}
foreach (ScheduledJobs::JOBS as $jobKey => $facts) {
    if (in_array($facts['token'], ['maintenance.cronToken', 'reminders.cron_token',
                                   'feeds.cron_token', 'giving.cron_token'], true) === true) {
        ScheduledJobs::began($db, $jobKey);
        ScheduledJobs::finished($db, $jobKey);
    }
}
MailQueue::forget();
$found = $pageSays();
$text  = $allText($found);
check(
    'H0m the no-token PROBLEM is reported',
    $severityOf($found, 'can never run, because no token has been set'),
    \Portal\Core\PortalCheck::PROBLEM
);
check(
    'H0n and the all-clear does NOT claim EVERY job has run, which it did while the '
    . 'problem above said seven of them can never run at all',
    str_contains($text, 'Every scheduled job has run recently enough'),
    false
);
check(
    'H0o it says what it CAN speak for instead',
    str_contains($text, 'Every scheduled job this page can speak for has run recently enough'),
    true
);
check(
    'H0p and says where its number came from, rather than an unexplained count',
    str_contains($text, 'with no token, which cannot run at all'),
    true
);
// ⚠️ AND THE NUMBER ITSELF, which nothing asserted. Dropping
//    `- count($noToken)` from `$finishedWell` left every check green, because
//    no fixture had BOTH a token-less job and a number to get wrong — the
//    mid-run fixture has no token-less jobs, and the token-less fixtures had
//    no healthy ones. The all-clear then said "15 of the 15 recorded a FINISH"
//    where eight did.
//
//    Counted from JOBS rather than typed, so adding a sixteenth job cannot
//    make this stale while still passing.
$withTokens = 0;
foreach (ScheduledJobs::JOBS as $facts) {
    if (in_array($facts['token'], ['maintenance.cronToken', 'reminders.cron_token',
                                   'feeds.cron_token', 'giving.cron_token'], true) === true) {
        $withTokens++;
    }
}
check(
    'H0p2 the count is the number of jobs that CAN run (' . $withTokens . ' of '
    . count(ScheduledJobs::JOBS) . '), not all of them',
    str_contains($text, $withTokens . ' of the ' . count(ScheduledJobs::JOBS)
        . ' recorded a FINISH'),
    true
);

// ── The mail fallback must fire ONLY on a real, stale record ─────────────
foreach (array_keys(\Portal\Core\PortalCheck::JOB_TOKENS) as $tokenKey) {
    $setSetting($tokenKey, $token);
}
$db->query('DELETE FROM `tblScheduledJobRun`');

// (i) no route-state row at all — a portal installed ten minutes ago.
$db->query("DELETE FROM `tblMailRouteState`");
MailQueue::forget();
$found = $pageSays();
check(
    'H0q a brand-new portal with no mail record at all is NOT warned about — it used to '
    . 'be reported as a PROBLEM asserting a misconfiguration that was not there',
    $severityOf($found, 'mail job has not run'),
    null
);
check(
    'H0r it says nothing is known yet, which is what D-071 asks for',
    $severityOf($found, 'Nothing is known yet'),
    \Portal\Core\PortalCheck::NOTE
);

// (ii) a record saying it ran a moment ago — nothing to say.
$db->query(
    "INSERT INTO `tblMailRouteState` (`routeKey`, `jobLastRanAt`) "
    . "VALUES ('installation', UTC_TIMESTAMP()) "
    . "ON DUPLICATE KEY UPDATE `jobLastRanAt` = UTC_TIMESTAMP()"
);
MailQueue::forget();
check(
    'H0s a mail job that ran a moment ago is not warned about either — this pins the '
    . 'condition that makes the fallback truthful, which nothing exercised',
    $severityOf($pageSays(), 'mail job has not run'),
    null
);

// (iii) a record saying it last ran an hour ago — the case the fix is for.
$db->query(
    "UPDATE `tblMailRouteState` SET `jobLastRanAt` = UTC_TIMESTAMP() - INTERVAL 1 HOUR "
    . "WHERE `routeKey` = 'installation'"
);
MailQueue::forget();
$found = $pageSays();
check(
    'H0t a mail job that HAS run before and has now stopped is reported',
    $severityOf($found, 'mail job has not run'),
    \Portal\Core\PortalCheck::WARNING
);
check(
    'H0u as a WARNING and NOT a problem — the fix silently escalated the very warning '
    . 'it was restoring, which the release note had promised would not happen',
    $severityOf($found, 'mail job has not run') === \Portal\Core\PortalCheck::PROBLEM,
    false
);
check(
    'H0v and it names the likeliest cause rather than asserting a misconfiguration',
    str_contains($allText($found), 'likeliest reason'),
    true
);

// ── The mail job stopped AND every other job healthy ────────────────────
// ⚠️ NO FIXTURE REACHED THIS, AND IT IS WHERE ROUND 2's NEW BUCKET HID.
//    H0m-H0p2 give the mail job a record, so the fallback never fires;
//    H0q-H0v leave `tblScheduledJobRun` empty, so `count($notKnown)` suppresses
//    the all-clear. "Mail stopped, everything else fine" fell between them —
//    and there the all-clear counted the mail job as having finished and
//    claimed to speak for it, directly beneath the warning saying it had not
//    run. Four jobs had finished and the sentence said five.
foreach (array_keys(\Portal\Core\PortalCheck::JOB_TOKENS) as $tokenKey) {
    $setSetting($tokenKey, $token);
}
$db->query('DELETE FROM `tblScheduledJobRun`');
foreach (array_keys(ScheduledJobs::JOBS) as $job) {
    if ($job === 'cron/mail-queue') {
        continue;
    }
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
}
$db->query(
    "INSERT INTO `tblMailRouteState` (`routeKey`, `jobLastRanAt`) "
    . "VALUES ('installation', UTC_TIMESTAMP() - INTERVAL 3 DAY) "
    . "ON DUPLICATE KEY UPDATE `jobLastRanAt` = UTC_TIMESTAMP() - INTERVAL 3 DAY"
);
MailQueue::forget();
$found = $pageSays();
$text  = $allText($found);
check(
    'H0w the mail job is reported as stopped',
    $severityOf($found, 'mail job has not run'),
    \Portal\Core\PortalCheck::WARNING
);
check(
    'H0x and there is NO all-clear beside it — it used to say "Every scheduled job has '
    . 'run recently enough" directly under that warning',
    $severityOf($found, 'has run recently enough'),
    null
);
check(
    'H0y so nothing claims to speak for the job the page has just said has stopped',
    str_contains($text, 'can speak for has run recently enough'),
    false
);

// ── One page, one number for one fact ───────────────────────────────────
// ⚠️ THE PROBLEM COUNTED TOKENS AND THE NOTE COUNTED JOBS. There are eleven
//    tokens and fifteen jobs, and `maintenance.cronToken` alone covers five —
//    so with only that token empty the page said "Some scheduled jobs can
//    never run" with a count of ONE, beside "the 5 with no token … each is
//    reported above". Differing fivefold, pointing at a single line.
$db->query('DELETE FROM `tblScheduledJobRun`');
$db->query("DELETE FROM `tblMailRouteState`");
foreach (array_keys(\Portal\Core\PortalCheck::JOB_TOKENS) as $tokenKey) {
    $setSetting($tokenKey, $token);
}
$setSetting('maintenance.cronToken', '');
$sharesIt = 0;
foreach (ScheduledJobs::JOBS as $jobKey => $facts) {
    if ($facts['token'] === 'maintenance.cronToken') {
        $sharesIt++;
        continue;
    }
    ScheduledJobs::began($db, $jobKey);
    ScheduledJobs::finished($db, $jobKey);
}
MailQueue::forget();
$found = $pageSays();
$problem = null;
foreach ($found as $f) {
    if (str_contains($f['what'], 'can never run, because no token') === true) {
        $problem = $f;
    }
}
check(
    'H0z the no-token problem counts JOBS, not tokens — ' . $sharesIt
    . ' jobs share maintenance.cronToken, and it used to report 1',
    $problem['count'] ?? null,
    $sharesIt
);
check(
    'H0z2 and the all-clear names the SAME number, so one page gives one number for one fact',
    str_contains($allText($found), 'the ' . $sharesIt . ' with no token'),
    true
);
foreach (array_keys(\Portal\Core\PortalCheck::JOB_TOKENS) as $tokenKey) {
    $setSetting($tokenKey, $token);
}

// ── An unreadable mail-route table ──────────────────────────────────────
// ⚠️ NOTHING EXERCISED THIS, AND IT IS WHERE ROUND 3's FIX WENT WRONG AND
//    ROUND 2's VANISHED THE WHOLE CHECK. The state is a half-applied upgrade:
//    `tblMailRouteState` is there and `jobLastRanAt` is not.
//
//    Round 2 (no try/catch): the exception escaped and `run()` replaced the
//    ENTIRE scheduled-jobs check with one tidy warning — so "ten jobs can
//    never run, including the clearing-out of personal data" was lost.
//    Round 3 (catch setting $everRan = ''): the mail job fell into $notKnown
//    and got "expected straight after an update: each one starts being watched
//    the first time it runs", with todo "Nothing", while mail sat unsent — the
//    exact reassurance COULD_NOT_READ exists to prevent, at a LOWER severity
//    than round 2.
$db->query('DELETE FROM `tblScheduledJobRun`');
foreach (array_keys(\Portal\Core\PortalCheck::JOB_TOKENS) as $tokenKey) {
    $setSetting($tokenKey, $token);
}
// ⚠️ EVERY JOB BUT THE MAIL QUEUE GETS A RECORD. The older mail reading is
//    only consulted when the NEW record has nothing to say, so a fixture that
//    gives the mail job a record never reaches the read at all — my first
//    version of this check did exactly that and went red for the wrong reason.
foreach (array_keys(ScheduledJobs::JOBS) as $job) {
    if ($job === 'cron/mail-queue') {
        continue;
    }
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
}
$db->query('ALTER TABLE `tblMailRouteState` DROP COLUMN `jobLastRanAt`');
MailQueue::forget();
$threw = false;
try {
    $found = $pageSays();
} catch (Throwable $e) {
    $threw = true;
    $found = [];
}
check('H1a the page does not throw when the mail-route table cannot be read', $threw, false);
check(
    'H1b and the whole scheduled-jobs check is NOT replaced by "could not be completed" — '
    . 'round 2 lost every other finding that way',
    count($found) > 0,
    true
);
check(
    'H1c it reports a record it could not READ, as a WARNING',
    $severityOf($found, 'could not be read'),
    \Portal\Core\PortalCheck::WARNING
);
check(
    'H1d and NOT the reassuring "nothing is known yet" — which told a half-applied '
    . 'upgrade to wait for something that was never going to happen',
    $severityOf($found, 'Nothing is known yet'),
    null
);
$db->query(
    'ALTER TABLE `tblMailRouteState` ADD COLUMN `jobLastRanAt` DATETIME DEFAULT NULL'
);
MailQueue::forget();

// ── "Never run" must not be said about a job that just ran ──────────────
// ⚠️ A TOKEN CAN BE CLEARED AFTER THE JOB HAS RUN, and the page then said "it
//    has never run and never will" about a job whose finish row the same
//    method reads twenty lines later. The "never will" half is true and the
//    action is right, so this is the alarming direction rather than the
//    reassuring one — and still an untrue sentence on the page whose whole
//    subject is saying what is true.
$db->query('DELETE FROM `tblScheduledJobRun`');
foreach (array_keys(\Portal\Core\PortalCheck::JOB_TOKENS) as $tokenKey) {
    $setSetting($tokenKey, $token);
}
ScheduledJobs::began($db, 'cron/venue-reminders');
ScheduledJobs::finished($db, 'cron/venue-reminders');
$setSetting('venues.cron_token', '');       // cleared AFTER it ran
MailQueue::forget();
$found = $pageSays();
check(
    'H1e the no-token problem is still reported',
    $severityOf($found, 'can never run, because no token'),
    \Portal\Core\PortalCheck::PROBLEM
);
check(
    'H1f and it does NOT claim the job has never run, when its finish row says it did '
    . 'ninety seconds ago',
    str_contains($allText($found), 'has never run and never will'),
    false
);
check(
    'H1g while still saying it cannot run now, which is the part that matters',
    str_contains($allText($found), 'cannot run now and will not until one is set'),
    true
);
$setSetting('venues.cron_token', $token);

echo "\n=== J. #817: a job that dies on every run is reported ===\n";
// ⚠️ THE FAULT, IN ONE SENTENCE: each run's began() cleared the finish and reset
//    the start, so a job dying part-way on EVERY run always had a fresh start
//    and no finish — RUNNING_NOW for ever, under an all-clear, while the page's
//    own note promised it "will be reported as having started and never
//    finished". The streak columns (migration 287) keep the OLDEST unfinished
//    start, and state() judges that against the job's own allowance.
$streakRow = static function (string $jobKey) use ($db): ?array {
    $stmt = $db->prepare('SELECT `unfinishedRuns`, `unfinishedSinceUtc`, `finishedAtUtc` FROM `tblScheduledJobRun` WHERE `jobKey` = ?');
    $stmt->bind_param('s', $jobKey);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $found;
};
$colsThere = (int) $db->query("SELECT COUNT(*) AS `n` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() "
    . "AND `TABLE_NAME` = 'tblScheduledJobRun' AND `COLUMN_NAME` IN ('unfinishedSinceUtc', 'unfinishedRuns')")->fetch_assoc()['n'];
check('J0 PREMISE: the table carries migration 287\'s two columns', $colsThere, 2);
ScheduledJobs::forgetTableCheck();

// The SCENARIOS, as one PHP program run in its OWN process against a given copy
// of the class, so a planted copy can be judged by exactly the same steps. It
// prints one JSON object. Every step goes through the real began()/finished()/
// state(); the only SQL is moving moments back in time, which is the one thing
// a test cannot wait for.
$scenarioCode = <<<'PHPCODE'
<?php
declare(strict_types=1);
require $argv[1];
use Portal\Core\ScheduledJobs;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli((string) getenv('J_HOST'), (string) getenv('J_USER'), (string) getenv('J_PASS'), (string) getenv('J_NAME'), (int) getenv('J_PORT'));
$job = 'cron/mail-queue';
$allow = ScheduledJobs::JOBS[$job]['overdue'];
$back = static function (int $seconds) use ($db, $job): void {
    // Move the whole run back: its start AND the streak's oldest start.
    $stmt = $db->prepare('UPDATE `tblScheduledJobRun` SET `startedAtUtc` = UTC_TIMESTAMP() - INTERVAL ? SECOND, '
        . '`unfinishedSinceUtc` = UTC_TIMESTAMP() - INTERVAL ? SECOND WHERE `jobKey` = ?');
    $stmt->bind_param('iis', $seconds, $seconds, $job);
    $stmt->execute();
    $stmt->close();
};
$out = [];
// 1. Dies on every run: the first unfinished run began past the allowance, and
//    it has been started again (and died again) three times since.
$db->query("DELETE FROM `tblScheduledJobRun` WHERE `jobKey` = 'cron/mail-queue'");
ScheduledJobs::began($db, $job);
$back($allow + 120);
ScheduledJobs::began($db, $job);
ScheduledJobs::began($db, $job);
ScheduledJobs::began($db, $job);
$st = ScheduledJobs::state($db)[$job];
$out['crash'] = ['status' => $st['status'], 'runs' => $st['unfinishedRuns'], 'oldest' => $st['unfinishedSeconds'], 'newest' => $st['seconds']];
// 2. Called again while still running, inside the allowance: NOT a problem.
$db->query("DELETE FROM `tblScheduledJobRun` WHERE `jobKey` = 'cron/mail-queue'");
ScheduledJobs::began($db, $job);
$back(60);
ScheduledJobs::began($db, $job);
$st = ScheduledJobs::state($db)[$job];
$out['overlap'] = ['status' => $st['status'], 'runs' => $st['unfinishedRuns']];
// 3. A finish ENDS the streak: a job that failed for a long time and then
//    finished must start afresh, or it would be reported for ever.
$db->query("DELETE FROM `tblScheduledJobRun` WHERE `jobKey` = 'cron/mail-queue'");
ScheduledJobs::began($db, $job);
$back($allow + 120);
ScheduledJobs::began($db, $job);
ScheduledJobs::finished($db, $job);
ScheduledJobs::began($db, $job);
$st = ScheduledJobs::state($db)[$job];
$out['afterFinish'] = ['status' => $st['status'], 'runs' => $st['unfinishedRuns']];
$db->query("DELETE FROM `tblScheduledJobRun` WHERE `jobKey` = 'cron/mail-queue'");
echo json_encode($out);
PHPCODE;
$scenarioDir = sys_get_temp_dir() . $sep . 'sj817-' . bin2hex(random_bytes(5));
mkdir($scenarioDir, 0700);
file_put_contents($scenarioDir . $sep . 'scenario.php', $scenarioCode);
$classFile = $repo . $sep . 'web' . $sep . '_core' . $sep . 'ScheduledJobs.php';
$runScenario = static function (string $classPath) use ($scenarioDir, $sep, $dbHost, $dbUser, $dbPass, $dbName, $dbPort): ?array {
    $env = ['J_HOST' => $dbHost, 'J_USER' => $dbUser, 'J_PASS' => $dbPass, 'J_NAME' => $dbName, 'J_PORT' => (string) $dbPort,
        'PATH' => (string) getenv('PATH')];
    $proc = proc_open([PHP_BINARY, $scenarioDir . $sep . 'scenario.php', $classPath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (is_resource($proc) === false) {
        return null;
    }
    $text = (string) stream_get_contents($pipes[1]);
    $errs = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode($text, true);
    if (is_array($decoded) === false) {
        echo "          (the scenario printed: " . substr($text . ' ' . $errs, 0, 400) . ")\n";
        return null;
    }

    return $decoded;
};
/** A planted copy of the class: `$from` must occur exactly once, or nothing is planted. */
$plantClass = static function (string $from, string $to) use ($classFile, $scenarioDir, $sep, $runScenario): array {
    $text = (string) file_get_contents($classFile);
    if (substr_count($text, $from) !== 1) {
        return ['applied' => false, 'result' => null];
    }
    $copy = $scenarioDir . $sep . 'Planted' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents($copy, str_replace($from, $to, $text));
    $result = $runScenario($copy);
    unlink($copy);

    return ['applied' => true, 'result' => $result];
};

$real = $runScenario($classFile);
check('J1 a job whose runs keep dying, with none finished for longer than its allowance, is DIED_PART_WAY — not RUNNING_NOW for ever',
    $real['crash']['status'] ?? null, ScheduledJobs::DIED_PART_WAY);
check('J2 ...counting all four runs that began and never finished', $real['crash']['runs'] ?? null, 4);
check('J3 ...and timed from the OLDEST of them, not the newest start',
    ($real['crash']['oldest'] ?? 0) > ScheduledJobs::OVERDUE_MAIL_QUEUE && ($real['crash']['newest'] ?? PHP_INT_MAX) < 60, true);
check('J4 a job merely called again while its previous run is still going, inside its allowance, is RUNNING_NOW — no false alarm (#483)',
    [$real['overlap']['status'] ?? null, $real['overlap']['runs'] ?? null], [ScheduledJobs::RUNNING_NOW, 2]);
check('J5 a finish ENDS the streak: the next run starts a streak of one, and is not reported',
    [$real['afterFinish']['status'] ?? null, $real['afterFinish']['runs'] ?? null], [ScheduledJobs::RUNNING_NOW, 1]);

// What the page SAYS in that state (the C4b lesson: a classification pinned is
// not a page pinned).
$db->query('DELETE FROM `tblScheduledJobRun`');
foreach (array_keys(ScheduledJobs::JOBS) as $job) {
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
}
ScheduledJobs::began($db, 'cron/mail-queue');
$db->query('UPDATE `tblScheduledJobRun` SET `startedAtUtc` = UTC_TIMESTAMP() - INTERVAL ' . (ScheduledJobs::OVERDUE_MAIL_QUEUE + 120) . ' SECOND, '
    . '`unfinishedSinceUtc` = UTC_TIMESTAMP() - INTERVAL ' . (ScheduledJobs::OVERDUE_MAIL_QUEUE + 120) . " SECOND WHERE `jobKey` = 'cron/mail-queue'");
ScheduledJobs::began($db, 'cron/mail-queue');
ScheduledJobs::began($db, 'cron/mail-queue');
MailQueue::forget();
$found = $pageSays();
check('J6 the page reports it as a PROBLEM: started and never finished',
    $severityOf($found, 'started and never finished'), \Portal\Core\PortalCheck::PROBLEM);
check('J7 ...saying its last 3 runs each started and never finished, not "started a moment ago"',
    str_contains($allText($found), 'its last 3 runs each started and never finished, and none has finished since the first of them started'), true);
check('J8 ...and the all-clear is gone', $severityOf($found, 'has run recently enough'), null);
check('J9 ...and it is NOT also listed as part-way through', $severityOf($found, 'part-way through when this page was drawn'), null);

// The single unfinished run keeps its old words exactly.
$db->query('DELETE FROM `tblScheduledJobRun`');
foreach (array_keys(ScheduledJobs::JOBS) as $job) {
    ScheduledJobs::began($db, $job);
    ScheduledJobs::finished($db, $job);
}
ScheduledJobs::began($db, 'cron/retention-sweep');
$age('cron/retention-sweep', ScheduledJobs::OVERDUE_NIGHTLY + 600, false);
$db->query("UPDATE `tblScheduledJobRun` SET `unfinishedSinceUtc` = `startedAtUtc` WHERE `jobKey` = 'cron/retention-sweep'");
$found = $pageSays();
check('J10 ONE run that died keeps its own wording ("started … ago and never finished")',
    preg_match('/ — started [0-9a-z ]+ ago and never finished/', $allText($found)), 1);
check('J10b ...and never says "its last 1 runs"', str_contains($allText($found), 'its last 1 runs'), false);

// Before migration 287 has run: the class still records, and reads as before.
$db->query('DROP TABLE `tblScheduledJobRun`');
$db->query($grab[0]);
ScheduledJobs::forgetTableCheck();
check('J11 with 287 not yet run, began() STILL records (files land before the migration, #694)',
    ScheduledJobs::began($db, 'cron/mail-queue'), true);
check('J12 ...and so does finished()', ScheduledJobs::finished($db, 'cron/mail-queue'), true);
check('J13 ...and the state is read, not COULD_NOT_READ', ScheduledJobs::state($db)['cron/mail-queue']['status'], ScheduledJobs::RECENT);
$apply287();
$apply287(); // safe to run twice
ScheduledJobs::forgetTableCheck();
$migratedRow = $streakRow('cron/mail-queue');
check('J14 migration 287 runs twice cleanly and leaves a finished row with no streak',
    // `array_key_exists`, never `??`: the value checked IS null, and `null ?? 'missing'` answers 'missing'.
    [(int) ($migratedRow['unfinishedRuns'] ?? -1), is_array($migratedRow) === true && array_key_exists('unfinishedSinceUtc', $migratedRow) === true ? $migratedRow['unfinishedSinceUtc'] : 'missing'], [0, null]);
// A run in flight when the migration runs is carried in as a streak of one.
$db->query('DROP TABLE `tblScheduledJobRun`');
$db->query($grab[0]);
$db->query("INSERT INTO `tblScheduledJobRun` (`jobKey`, `startedAtUtc`, `finishedAtUtc`, `lastOutcome`) VALUES ('cron/backup-run', UTC_TIMESTAMP() - INTERVAL 5 MINUTE, NULL, '')");
$apply287();
ScheduledJobs::forgetTableCheck();
$carried = $streakRow('cron/backup-run');
check('J15 migration 287 carries a run already in flight in as a streak of one, from its own start',
    [(int) ($carried['unfinishedRuns'] ?? -1), $carried['unfinishedSinceUtc'] !== null], [1, true]);
$db->query('DELETE FROM `tblScheduledJobRun`');

// The plants, each in a COPY of the class run by the same scenarios.
$pa = $plantClass('if ($neverFinished === true && $unfinishedSeconds > $facts[\'overdue\']) {',
    'if ($neverFinished === true && $since > $facts[\'overdue\']) {');
check('JPa with state() judging the NEWEST start again (the #817 fault), the plant applied AND J1\'s job reads RUNNING_NOW',
    [$pa['applied'], $pa['result']['crash']['status'] ?? null], [true, ScheduledJobs::RUNNING_NOW]);
$pb = $plantClass("'`unfinishedSinceUtc` = COALESCE(`unfinishedSinceUtc`, UTC_TIMESTAMP()), '",
    "'`unfinishedSinceUtc` = UTC_TIMESTAMP(), '");
check('JPb with began() restarting the streak\'s clock on every run, the plant applied AND J1\'s job reads RUNNING_NOW',
    [$pb['applied'], $pb['result']['crash']['status'] ?? null], [true, ScheduledJobs::RUNNING_NOW]);
$pc = $plantClass("'`unfinishedSinceUtc` = NULL, `unfinishedRuns` = 0'", "'`unfinishedRuns` = `unfinishedRuns`'");
check('JPc with finished() no longer ending the streak, the plant applied AND a job that finished is reported as never finishing',
    [$pc['applied'], $pc['result']['afterFinish']['status'] ?? null], [true, ScheduledJobs::DIED_PART_WAY]);
foreach (glob($scenarioDir . $sep . '*') ?: [] as $leftover) {
    unlink($leftover);
}
rmdir($scenarioDir);
$db->query('DROP TABLE `tblScheduledJobRun`');
$db->query($grab[0]);
$apply287();
ScheduledJobs::forgetTableCheck();

echo "\n=== Summary ===\n";
echo "{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
