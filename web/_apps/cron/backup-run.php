<?php
// Path: _apps/cron/backup-run.php
/**
 * -----------------------------------------------------------------------------
 * Cron — take a backup on a schedule 📦⏰ (#569)
 * -----------------------------------------------------------------------------
 * The portal had no way to take a backup by itself. `DbBackup` could take one,
 * the Backup page had a button, `_install/upgrade.php` took one before running
 * migrations, and `cron/backup-check` reported HOW OLD the newest one was and
 * e-mailed when it got too old — but nothing ever MADE one unless somebody
 * pressed the button. So the freshness alert existed to report a staleness that
 * nothing was preventing.
 *
 * Meant for a daily call from the hosting company's scheduler:
 *
 *   curl -fsS "https://<your-portal>/cron/backup-run?token=<TOKEN>"
 *
 * CALL IT DAILY WHATEVER THE SETTING SAYS. How often a snapshot is actually
 * taken is decided here, from `backup.schedule.frequency`, not by how often the
 * scheduler calls. A daily call with a weekly setting takes one snapshot a
 * week; the other six answer "not due" and cost one query. That way an
 * administrator changing the setting does not also have to change a scheduler
 * entry they may not be able to reach.
 *
 * WHAT IT DOES
 *   1. Checks the token. No session is involved.
 *   2. Works out whether a snapshot is due. Not due → says so and stops.
 *   3. Takes one (`DbBackup::snapshot('scheduled')`).
 *   4. Removes the oldest, keeping `backup.schedule.keep` of them.
 *
 * IT DOES NOT CLOSE THE PORTAL. `snapshot()` reads; it changes nothing and
 * holds no long lock, so members can carry on. Only a RESTORE closes the
 * portal, and nothing here restores anything.
 *
 * WHY "DUE" IS WORKED OUT FROM THE SNAPSHOTS THEMSELVES
 * -----------------------------------------------------------------------------
 * There is no "last run" setting to keep. The newest finished snapshot's own
 * timestamp is the answer, which means:
 *
 *   • a snapshot taken by hand, or by an upgrade, counts. Somebody who pressed
 *     the button an hour ago does not get a second one tonight.
 *   • nothing can drift out of step. A stored "last run" that was written when
 *     the snapshot FAILED would push the next one a whole period away, which is
 *     the shape of fault that leaves somebody with no backup and no warning.
 *   • restoring the database cannot confuse it, because the answer is not in
 *     the database at all.
 *
 * WHAT IT CANNOT DO, said plainly
 * -----------------------------------------------------------------------------
 *   • It cannot take a snapshot larger than the host will allow in one request.
 *     `DbBackup::snapshot()` streams table by table and is careful with memory,
 *     but a very large database on a host with a short time limit may not
 *     finish. It says so in its own result, and the freshness alert
 *     (`cron/backup-check`) is what notices that nothing fresh exists.
 *   • It does not copy anything off the server. That is a download, which a
 *     person does from the Backup page; see `Portal\Core\BackupArchive`.
 *   • It has no per-organisation setting, and should not: one database serves
 *     every organisation on the installation, so a backup is installation-wide
 *     by nature.
 *
 * @package   Portal\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/569
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\DbBackup;
use Portal\Core\Logger;

// -----------------------------------------------------------------------------
// 🔑 Token check. No session is involved at all.
// -----------------------------------------------------------------------------
// The same shape as cron/backup-check: a scheduler has a token and no session,
// and this address is seeded unprotected so the router does not send it to the
// sign-in page. An empty stored token refuses everything, so a portal that
// never set one cannot be triggered by a guess.
$expected = (string) (App::settings()['maintenance']['cronToken'] ?? '');
$supplied = (string) ($_GET['token'] ?? '');
if ($expected === '' || is_string($supplied) === false || hash_equals($expected, $supplied) === false) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_token']);
    exit();
}

header('Content-Type: application/json');

/**
 * How often a snapshot should be taken, in hours, or 0 for "never".
 *
 * Read as a word rather than a number so the settings page offers choices
 * instead of inviting somebody to type 0.5 and wonder why nothing happens.
 */
function backup_run_period_hours(string $frequency): int
{
    return match ($frequency) {
        'daily'   => 24,
        'weekly'  => 24 * 7,
        'monthly' => 24 * 28,   // 28, not "a month": a period, not a calendar date
        default   => 0,         // 'off', and anything unrecognised
    };
}

$settings  = App::settings();
$frequency = (string) ($settings['backup']['schedule']['frequency'] ?? 'off');
$period    = backup_run_period_hours($frequency);

if ($period === 0) {
    // Not an error: an installation may deliberately take its backups another
    // way, or its host may do it. Answering plainly means a scheduler calling
    // this every day does not fill a log with failures.
    echo json_encode([
        'ok'        => true,
        'action'    => 'none',
        'reason'    => 'scheduled backups are switched off',
        'frequency' => $frequency,
    ]);
    exit();
}

// 🧮 How many to keep. 0 would mean "delete the one just taken", so the
//    smallest meaningful answer is 1, and anything unusable falls back to a
//    sensible default rather than to nothing.
$keepRaw = (string) ($settings['backup']['schedule']['keep'] ?? '7');
$keep    = preg_match('/^\d{1,4}$/', trim($keepRaw)) === 1 ? (int) trim($keepRaw) : 7;
if ($keep < 1) {
    $keep = 1;
}

// 🔒 ONE RUN AT A TIME.
//
//    Two overlapping calls — a scheduler that fires twice, or a slow snapshot
//    still going when the next call arrives — both decide a backup is due. The
//    second one's `mkdir` then fails inside `snapshot()`, which records
//    SCHEDULED_BACKUP_FAILED and answers HTTP 500, so the hosting company's
//    scheduler e-mails the customer about a failure on a perfectly healthy
//    portal. Nothing is corrupted; the alarm is the fault.
//
//    `flock` with LOCK_NB, not a file that is created and deleted: a lock held
//    by the file handle is released by the operating system even if the process
//    is killed, so a run that dies cannot leave the job wedged for ever. The
//    file itself is left in place; it holds nothing and is one inode.
$lockPath   = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_backups' . DIRECTORY_SEPARATOR . '.backup-run.lock';
$lockHandle = @fopen($lockPath, 'cb');
if ($lockHandle !== false && flock($lockHandle, LOCK_EX | LOCK_NB) === false) {
    // Not an error: the other run is doing the work. Answering plainly means a
    // doubled scheduler entry does not look like a fault.
    echo json_encode([
        'ok'     => true,
        'action' => 'none',
        'reason' => 'another backup run is in progress',
    ]);
    exit();
}
// Released when the handle closes, which PHP does at the end of the request
// however the request ends.

$backup = new DbBackup($mysqli);

// 📅 Due? The newest finished snapshot THIS INSTALLATION TOOK decides.
//
//    Three things about that sentence, each of which was wrong at some point:
//
//      • `created_at` is what `listSnapshots()` really calls it — checked
//        against that method rather than guessed. The first draft read
//        `takenAt`, `name` and `deleted`, none of which exist: the job would
//        have taken a snapshot every single run (an age of null reads as
//        "due"), told nobody which one, and reported nothing tidied.
//      • an UNFINISHED snapshot is ignored: one that never completed is not a
//        backup, and treating it as one would mean a failed run blocks the next
//        attempt for a whole period.
//      • an IMPORTED snapshot is ignored (`DbBackup::isTakenHere()`). It is a
//        copy brought back from somewhere else, not evidence that the CURRENT
//        database has been saved. Without this, importing a recent file
//        silently suppressed the next scheduled backup — measured by section N
//        of `tools/backup-page-selftest.php`, which found the job answering
//        "not old enough" minutes after an import. `prune()` draws the same
//        line, so what this job counts and what it tidies agree.
$ageHours = null;
foreach ($backup->listSnapshots() as $snapshot) {
    if (($snapshot['complete'] ?? false) !== true) {
        continue;
    }
    if (DbBackup::isTakenHere((string) ($snapshot['name'] ?? '')) === false) {
        continue;
    }
    $takenAt = strtotime((string) ($snapshot['created_at'] ?? ''));
    if ($takenAt === false) {
        continue;
    }
    $thisAge = (time() - $takenAt) / 3600;
    if ($ageHours === null || $thisAge < $ageHours) {
        $ageHours = $thisAge;   // the NEWEST is the smallest age
    }
}

// 🪟 A quarter of an hour of slack. Without it a scheduler that fires at
//    02:00:05 one night and 01:59:55 the next would skip a day, because the
//    newest snapshot would be 23 hours and 59 minutes old rather than 24.
$slackHours = 0.25;

if ($ageHours !== null && $ageHours < ($period - $slackHours)) {
    echo json_encode([
        'ok'            => true,
        'action'        => 'none',
        'reason'        => 'the newest backup is not old enough yet',
        'frequency'     => $frequency,
        'everyHours'    => $period,
        'newestAgeHours' => round($ageHours, 2),
    ]);
    exit();
}

// -----------------------------------------------------------------------------
// 📦 Take one.
// -----------------------------------------------------------------------------
// ⚠️ `snapshot()` ANSWERS `success: false`; it does not throw for an ordinary
//    failure. A first draft only caught exceptions, so a snapshot that simply
//    did not work would have been reported as taken — the worst possible
//    outcome for a backup job. Both are handled.
$result = ['success' => false, 'warnings' => []];
try {
    $result = $backup->snapshot('scheduled');
} catch (\Throwable $e) {
    $result = ['success' => false, 'warnings' => []];
}

if (($result['success'] ?? false) !== true) {
    // 🤫 The fault's own words are not recorded: a database error can quote
    //    the value it choked on, and this reads every table in the portal.
    //    `snapshot()` has already put its own reason in its result, which the
    //    Backup page shows.
    Logger::errorPlatform(
        'Backup',
        'Error',
        'SCHEDULED_BACKUP_FAILED',
        'the scheduled backup could not be taken',
        'frequency=' . $frequency
    );
    http_response_code(500);
    echo json_encode([
        'ok'     => false,
        'action' => 'snapshot',
        'error'  => 'the backup could not be taken; the reason is in the portal\'s error log',
    ]);
    exit();
}

// 🏷️ `pruned`, which is what prune() really calls it.
$pruned = ['pruned' => 0, 'kept' => 0, 'errors' => []];
try {
    $pruned = $backup->prune($keep);
} catch (\Throwable $e) {
    // A snapshot that was taken is worth reporting even if the tidying failed.
    // Nothing is lost by keeping too many; the disk filling is what the
    // freshness alert and the hosting company's own warnings are for.
    Logger::errorPlatform(
        'Backup',
        'Warning',
        'SCHEDULED_BACKUP_PRUNE_FAILED',
        'the scheduled backup was taken, but older snapshots could not be tidied away',
        'keep=' . $keep
    );
}

// 📖 `directory` is the snapshot's folder; its basename is the name an
//    administrator sees on the Backup page.
$madeName = basename((string) ($result['directory'] ?? ''));

Logger::activity(
    'ScheduledBackupTaken',
    'A scheduled backup was taken (' . $frequency . '), keeping ' . $keep
    . '; ' . (int) ($pruned['pruned'] ?? 0) . ' older snapshot(s) removed',
    null
);

echo json_encode([
    'ok'         => true,
    'action'     => 'snapshot',
    'frequency'  => $frequency,
    'everyHours' => $period,
    'snapshot'   => $madeName,
    'tables'     => $result['tables'] ?? null,
    'rows'       => $result['rows'] ?? null,
    'warnings'   => $result['warnings'] ?? [],
    'keep'       => $keep,
    'kept'       => (int) ($pruned['kept'] ?? 0),
    'removed'    => (int) ($pruned['pruned'] ?? 0),
    // Reported because an unfinished folder being tidied away is worth seeing:
    // each one is a snapshot that failed or was cut off.
    'unfinishedRemoved' => (int) ($pruned['unfinished_removed'] ?? 0),
    'pruneErrors' => $pruned['errors'] ?? [],
]);
