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
 *      this file.
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
// ⚠️ NAMED FOR THE JOB, NOT A NUMBER. This said "the four thresholds are four
//    different numbers" while asserting FIVE — a label named after a number is
//    the shape #739 recorded, and it would have printed "four" on the day a
//    sixth was added.
check('E4 every threshold is a distinct number, so none is a copy of another', count(array_unique([
    ScheduledJobs::OVERDUE_MAIL_QUEUE,
    ScheduledJobs::OVERDUE_EVENT_REMINDERS,
    ScheduledJobs::OVERDUE_IMPORT_FEEDS,
    ScheduledJobs::OVERDUE_GIVING_STATEMENTS,
    ScheduledJobs::OVERDUE_NIGHTLY,
])), 5);

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
$aged = $age('cron/webhook-retry', ScheduledJobs::OVERDUE_NIGHTLY + 600, true);
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
    str_contains($text, 'part-way through, which have not recorded a finish'),
    true
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

echo "\n=== Summary ===\n";
echo "{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
