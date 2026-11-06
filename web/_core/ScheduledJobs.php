<?php
// Path: _core/ScheduledJobs.php
/**
 * -----------------------------------------------------------------------------
 * Scheduled jobs ⏰ — the one record of whether each job actually ran
 * -----------------------------------------------------------------------------
 * Fifteen addresses under `cron/` are called by whatever runs scheduled tasks
 * on a customer's hosting. Until #775 only ONE of them left any trace: the
 * mail queue, through `MailRoutes::jobRan()`. So a portal could silently stop
 * sending reminders, stop clearing out personal data past its retention
 * period, or stop taking backups, and nothing anywhere would show it. No
 * error, no log line, no badge. The first sign was somebody asking why their
 * reminders had stopped.
 *
 * That matters more than it looks, because several of these jobs ARE the
 * mechanism behind a promise the portal makes. The retention sweep is how
 * personal data stops being kept; if it has not run for six months, the portal
 * is keeping records it told people it would delete.
 *
 * THIS CLASS IS THE ONE PLACE a job's run is recorded and the one place it is
 * read. Do not write a second recorder, and do not read `tblScheduledJobRun`
 * anywhere else.
 *
 * -----------------------------------------------------------------------------
 * A TOKEN IS NOT A JOB, and that is why this is keyed by JOB
 * -----------------------------------------------------------------------------
 * `PortalCheck::JOB_TOKENS` is a list of the eleven TOKENS, and it reports a
 * real problem for any job whose token was never set. It cannot be the spine of
 * this, because `maintenance.cronToken` alone covers FIVE jobs — the mail
 * queue, the retention sweep, the backup run, the backup check and the health
 * check. Keyed by token, "the backup has not run for a month" would be
 * indistinguishable from "the mail queue ran a minute ago".
 *
 * -----------------------------------------------------------------------------
 * OVERDUE IS PER JOB (D-070, the owner, 6 November 2026)
 * -----------------------------------------------------------------------------
 * One number for all fifteen cannot work, and the reason is measured rather
 * than argued. Giving statements go out WEEKLY; anything loose enough for them
 * would let the mail queue sit dead for a week. Anything tight enough for the
 * mail queue (minutes) would warn about the nightly backup every single day.
 *
 * A two-tier split was rejected for the same kind of reason: statements would
 * read as overdue six days in seven — a permanent false alarm. **A check that
 * cries wolf gets switched off (#483)**, and this project has already paid for
 * that once.
 *
 * Each threshold is ONE constant below, so changing one later is one line.
 *
 * -----------------------------------------------------------------------------
 * NO RECORD MEANS "NOTHING IS KNOWN YET" (D-071, the owner, 6 November 2026)
 * -----------------------------------------------------------------------------
 * On the day these files land, no job on any existing portal has ever written a
 * record. A job with no record is therefore reported as NOT KNOWN — never as a
 * warning, and never by writing a run time at upgrade so that nothing looks
 * overdue.
 *
 * Warning straight away would give every correctly-configured portal a page of
 * warnings the morning after it upgrades, for jobs running perfectly well, with
 * no way to clear them but to wait. Backfilling was ruled out for a different
 * reason: it would write a record saying a job ran when nobody knows whether it
 * did — **inventing evidence in the one place whose purpose is telling an
 * administrator what is actually true.**
 *
 * This weakens nothing. A portal whose jobs were never set up is still caught,
 * and caught better, by the token check in `PortalCheck`: a job with no token
 * cannot run at all until somebody sets one, and that IS knowable from the
 * database — unlike whether a job WITH a token actually ran.
 *
 * ⚠️ THIS SAID "has never run and never will", quoting what the page used to
 *    say, and round 4 withdrew that wording from the page because it is untrue
 *    when a token is cleared AFTER the job has run. The quotation outlived the
 *    sentence it quoted, which is this package's "corrected in one place, not
 *    the others" pattern yet again.
 *
 * -----------------------------------------------------------------------------
 * ⚠️ RECORDING MUST NEVER BE ABLE TO STOP THE JOB IT IS RECORDING
 * -----------------------------------------------------------------------------
 * Files reach a customer's server BEFORE anybody presses "Run migrations"
 * (#694's lesson), so for a while `tblScheduledJobRun` does not exist while
 * these pages are already being called. If `began()` threw then, the mail
 * queue, the reminders and the retention sweep would all stop — this class
 * would have caused the exact silence it exists to reveal.
 *
 * So `began()` and `finished()` answer `false` rather than throwing, on any
 * cause. **The cost, said plainly: a recording that fails is silent**, and the
 * check page then says "nothing is known yet" about a job that really ran.
 * That is the honest direction — it under-claims — and it is the opposite
 * direction from the fault this class exists to fix.
 *
 * The missing table is asked about with `SHOW TABLES LIKE` rather than being
 * inferred from a caught error, because "the table is not there yet" and
 * "something is wrong" must not be answered the same way.
 *
 * @package WebMS\Portal\Core
 * @author  MWBM Partners Ltd (t/a MWservices)
 * @copyright All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
 * @version 1.0.0
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;
use Throwable;

final class ScheduledJobs
{
    /**
     * ⏱️ How late is too late, one constant per job (D-070).
     *
     * Each is in SECONDS and each carries the reason it is what it is, because
     * the reason is what somebody changing it has to argue with.
     */

    /** The one job that must run almost continuously. This is MailQueue's own existing window. */
    public const OVERDUE_MAIL_QUEUE = 900;          // 15 minutes

    /** It promises a reminder one hour ahead, so a two-hour gap has already missed one. */
    public const OVERDUE_EVENT_REMINDERS = 7200;    // 2 hours

    /** An outside calendar changing is not urgent. */
    public const OVERDUE_IMPORT_FEEDS = 21600;      // 6 hours

    /** Weekly at most, so a week plus a day's grace. */
    public const OVERDUE_GIVING_STATEMENTS = 691200; // 8 days

    /**
     * 36 hours: a night plus a day's grace. **THE DEFAULT, NOT A STATEMENT
     * THAT THESE JOBS ARE NIGHTLY** — and the name says "nightly" because
     * D-070's table calls this row "everything else | 36 hours | nightly, plus
     * a day's grace", which was decided on that premise.
     *
     * ⚠️ THE PREMISE IS MEASURABLY FALSE FOR SIX OF THE ELEVEN JOBS THAT USE
     *    IT, and their own page headers say so:
     *
     *      cron/push-golive        "every 5 minutes"
     *      cron/webhook-retry      "every 5-15 minutes"
     *      cron/user-reminders     "every 15 minutes"
     *      cron/asset-reminders    "every 15-60 minutes"
     *      cron/venue-reminders    "every 30-60 minutes"
     *      cron/workflow-timeouts  "called hourly"
     *
     *    The other five are genuinely nightly or state no cadence
     *    (retention-sweep "once a night", backup-run and backup-check
     *    "daily", health and discipleship-sweep silent).
     *
     *    So a job called every five minutes can be 35 hours late and still
     *    count as healthy. **The figure is the owner's and is NOT changed
     *    here**; the measured fact is put to them against D-070's row as #793,
     *    which is the register's own route for a fact that unsettles a
     *    recorded answer, and which quotes the row and the fact. Until that is decided, this comment is the warning
     *    and the page no longer claims the allowance matches each job.
     */
    public const OVERDUE_NIGHTLY = 129600;          // 36 hours

    /**
     * 🏷️ How a finished run ended, as ONE WORD.
     *
     * ⚠️ NEVER THE WORDS OF A FAULT. A database or mail message can quote the
     *    value that caused it, and this row is read by an administrator on a
     *    page, not by a developer in a log.
     *
     * THREE WORDS, NOT TWO, AND THE THIRD WAS ADDED FOR A MEASURED REASON.
     * A first version had only `ok` and `refused`, and `refused` was then
     * doing two quite different jobs: "somebody switched this off" and "this
     * ran and could not do its work". The check page words `refused` as
     * "being called but switched off" — so a nightly backup that FAILED would
     * have been reported to an administrator as switched off, which is untrue
     * in the reassuring direction, and about backups.
     *
     *   ok       the job did its work.
     *   refused  the job was called and deliberately did nothing: a setting
     *            switches it off, another run holds the lock, or nothing was
     *            due yet. NOT a fault, and the page says so.
     *   failed   the job ran and could not complete its work. A real problem,
     *            and the page reports it as one.
     */
    public const OUTCOME_OK      = 'ok';
    public const OUTCOME_REFUSED = 'refused';
    public const OUTCOME_FAILED  = 'failed';

    /**
     * 📋 THE ONE LIST OF SCHEDULED JOBS.
     *
     * Keyed by the job's own address under `cron/`, which is the only name that
     * is already unique, already registered in `tblRoutes` and already what an
     * administrator was given to put in their scheduler. A new job is added
     * here in the same change that adds its page, and
     * `tools/audit-checks/check_scheduled_jobs_recorded.py` fails both ways if
     * the two ever disagree.
     *
     *   whatStops  what a PERSON loses if it never runs, in their terms, not
     *              the code's. This is what the check page shows.
     *   token      the setting holding its token. SEVERAL JOBS SHARE ONE: see
     *              the note at the top of this file.
     *   overdue    seconds, from the constants above.
     *
     * @var array<string, array{whatStops: string, token: string, overdue: int}>
     */
    public const JOBS = [
        'cron/mail-queue' => [
            'whatStops' => 'Sending mail that could not go out at once, and text messages held for quiet hours',
            'token'     => 'maintenance.cronToken',
            'overdue'   => self::OVERDUE_MAIL_QUEUE,
        ],
        'cron/event-reminders' => [
            'whatStops' => 'Event reminders the day before and an hour ahead, and the morning summary',
            'token'     => 'reminders.cron_token',
            'overdue'   => self::OVERDUE_EVENT_REMINDERS,
        ],
        'cron/import-feeds' => [
            'whatStops' => 'Importing events from outside calendars',
            'token'     => 'feeds.cron_token',
            'overdue'   => self::OVERDUE_IMPORT_FEEDS,
        ],
        'cron/giving-statements' => [
            'whatStops' => 'Sending giving statements',
            'token'     => 'giving.cron_token',
            'overdue'   => self::OVERDUE_GIVING_STATEMENTS,
        ],
        'cron/retention-sweep' => [
            'whatStops' => 'Clearing out personal data past the period this portal said it would keep it',
            'token'     => 'maintenance.cronToken',
            'overdue'   => self::OVERDUE_NIGHTLY,
        ],
        'cron/backup-run' => [
            'whatStops' => 'Taking the nightly backup',
            'token'     => 'maintenance.cronToken',
            'overdue'   => self::OVERDUE_NIGHTLY,
        ],
        'cron/backup-check' => [
            'whatStops' => 'Checking that the backups are recent and readable',
            'token'     => 'maintenance.cronToken',
            'overdue'   => self::OVERDUE_NIGHTLY,
        ],
        'cron/health' => [
            'whatStops' => 'The health check this portal reports to whoever watches it',
            'token'     => 'maintenance.cronToken',
            'overdue'   => self::OVERDUE_NIGHTLY,
        ],
        'cron/user-reminders' => [
            'whatStops' => 'Task and personal reminders',
            'token'     => 'user_reminders.cron_token',
            'overdue'   => self::OVERDUE_NIGHTLY,
        ],
        'cron/asset-reminders' => [
            'whatStops' => 'Reminders that equipment is due back or due a check',
            'token'     => 'assets.cron_token',
            'overdue'   => self::OVERDUE_NIGHTLY,
        ],
        'cron/venue-reminders' => [
            'whatStops' => 'Reminders that a venue agreement is running out',
            'token'     => 'venues.cron_token',
            'overdue'   => self::OVERDUE_NIGHTLY,
        ],
        'cron/discipleship-sweep' => [
            'whatStops' => 'Moving people on through their reading plans',
            'token'     => 'discipleship.cron_token',
            'overdue'   => self::OVERDUE_NIGHTLY,
        ],
        'cron/push-golive' => [
            'whatStops' => 'The "We are live" push when a stream starts',
            'token'     => 'push.cron_token',
            'overdue'   => self::OVERDUE_NIGHTLY,
        ],
        'cron/webhook-retry' => [
            'whatStops' => 'Retrying payment messages from Stripe or GoCardless that failed first time',
            'token'     => 'webhooks.cron_token',
            'overdue'   => self::OVERDUE_NIGHTLY,
        ],
        'cron/workflow-timeouts' => [
            'whatStops' => 'Chasing approvals that have been waiting too long',
            'token'     => 'workflows.cron_token',
            'overdue'   => self::OVERDUE_NIGHTLY,
        ],
    ];

    /** Answered by state(): this job has never written a record (D-071). */
    public const NOT_KNOWN = 'not-known';

    /** Answered by state(): it ran recently enough for its own threshold. */
    public const RECENT = 'recent';

    /** Answered by state(): its last run is older than its own threshold. */
    public const OVERDUE = 'overdue';

    /** Answered by state(): its newest run began and never reached the end. */
    public const DIED_PART_WAY = 'died-part-way';

    /**
     * Answered by state(): a run has begun and not finished, and is still
     * inside its own threshold — so it is very likely RUNNING right now.
     *
     * ⚠️ THIS USED TO BE FOLDED INTO `RECENT`, AND THAT MADE THE ALL-CLEAR
     *    SAY SOMETHING FALSE. `scheduledJobs()` had no branch for it, so such
     *    a job fell through every test and was swept into "Every scheduled job
     *    has run recently enough … All 15 of them recorded a finish inside the
     *    time expected for that job" — about a job that had recorded no finish
     *    at all, with the record holding that very fact.
     *
     *    The cost was measured and it is not a corner case: a nightly backup
     *    that dies part-way at 02:00 — the state the whole design calls "the
     *    state worth reporting" — was reported as an all-clear for the next 36
     *    hours, straight through the morning somebody would look. For the mail
     *    queue the window is 15 minutes, and a busy portal's mail job is
     *    mid-run a noticeable fraction of the time, so the false sentence was
     *    shown routinely.
     *
     *    It is NOT a fault in itself: a job that started a moment ago has
     *    simply not finished YET, which is why it is not DIED_PART_WAY until
     *    it passes its own threshold. It just must not be counted as finished.
     */
    public const RUNNING_NOW = 'running-now';

    /**
     * Answered by state(): the record EXISTS but could not be read.
     *
     * ⚠️ THIS IS NOT THE SAME AS "NOTHING IS KNOWN YET", and conflating them
     *    was a real fault. Both used to answer NOT_KNOWN, so a half-applied
     *    migration — the table there but a column missing — reported the
     *    reassuring "expected straight after an update: each one starts being
     *    watched the first time it runs". Nothing was going to start being
     *    watched: the read was broken and would stay broken.
     *
     *    It also left the `SHOW TABLES LIKE` guard doing nothing observable,
     *    because the `try`/`catch` answered identically — so the self-test
     *    passed with that guard deleted. A guard nothing can distinguish from
     *    its absence is not a guard.
     */
    public const COULD_NOT_READ = 'could-not-read';

    /** @var bool|null Whether the table is there; asked once per request. */
    private static ?bool $tableThere = null;

    /**
     * 🔀 Record that a job has BEGUN, called once its token has been accepted.
     *
     * AFTER the token gate, never before: a request with the wrong token is a
     * stranger knocking, and letting one write here would let anybody on the
     * internet make a dead job look alive.
     *
     * It clears the finish, so a run that begins and never ends is visible as
     * exactly that rather than reading as the previous run's success.
     *
     * @return bool Whether the record was written. FALSE IS NOT AN ERROR THE
     *              CALLER SHOULD ACT ON — see the note at the top of this file.
     *              It is returned so a test can tell the two apart.
     */
    public static function began(mysqli $db, string $jobKey): bool
    {
        if (self::isKnownJob($jobKey) === false) {
            // 🚨 AN UNKNOWN KEY IS NEVER WRITTEN. A row under a mistyped name
            //    would be read by nothing, so the real job would report
            //    "nothing is known yet" for ever while looking recorded.
            return false;
        }
        if (self::tableExists($db) === false) {
            return false;
        }
        try {
            $stmt = $db->prepare(
                'INSERT INTO `tblScheduledJobRun` '
                . '(`jobKey`, `startedAtUtc`, `finishedAtUtc`, `lastOutcome`) '
                . 'VALUES (?, UTC_TIMESTAMP(), NULL, \'\') '
                . 'ON DUPLICATE KEY UPDATE '
                . '`startedAtUtc` = UTC_TIMESTAMP(), `finishedAtUtc` = NULL, `lastOutcome` = \'\''
            );
            $stmt->bind_param('s', $jobKey);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            // ⚠️ NEVER LOG THE FAULT'S OWN WORDS, and never let this reach the
            //    caller. Recording is advisory; the job's work is not.
            return false;
        }

        return true;
    }

    /**
     * 🔀 Record that a job has FINISHED its work.
     *
     * Called at the end of the job's own page, on the path that really did the
     * work. A job that exits early because there was nothing to do has still
     * run, and must still call this: "ran and found nothing" is a healthy job,
     * and treating it as a non-run would make a quiet portal look broken.
     *
     * ⚠️ EVERY EARLY EXIT BETWEEN began() AND HERE MUST CALL THIS, and getting
     *    that wrong was a real fault in the first version of #775's wiring.
     *    EIGHT of the fifteen pages exit early when a setting switches them
     *    off, when another run holds the lock, or when nothing is due. Those
     *    TWELVE paths recorded a START and no finish — ten are refusals and
     *    two are genuine failures, and both failures sit in pages already
     *    among the eight. A THIRTEENTH early finish lives in `health.php`'s
     *    handled catch, which is not an early-exit path, so NINE pages record
     *    an early finish in all.
     *
     *    (This said "nine of the fifteen pages … those twelve paths", which
     *    cannot both be true: the twelve live in eight pages, and nine counts
     *    `health.php`, which the same sentence removed from the early-exit
     *    set. The correction of a count was itself inconsistent, in four
     *    places.) So a job somebody had
     *    deliberately switched off read as "started and never finished" on the
     *    check page, for ever, as a PROBLEM. **A job working exactly as
     *    configured must never be reported as broken**, and a check that cries
     *    wolf gets switched off (#483).
     *
     *    Such a path calls this with OUTCOME_REFUSED; a path that tried and
     *    could not finish its work calls it with OUTCOME_FAILED. The ONLY
     *    state that should leave a start with no finish is a run that died
     *    without reaching any of them — which is the state worth reporting.
     *
     * @param string $outcome One of the three OUTCOME_* words. Anything else is
     *                        stored as `failed` rather than guessed at: an
     *                        unrecognised word means the caller is out of step
     *                        with this class, and reporting a possible problem
     *                        is the safe direction.
     */
    public static function finished(mysqli $db, string $jobKey, string $outcome = self::OUTCOME_OK): bool
    {
        if (self::isKnownJob($jobKey) === false) {
            return false;
        }
        if (self::tableExists($db) === false) {
            return false;
        }
        $word = in_array($outcome, [self::OUTCOME_OK, self::OUTCOME_REFUSED, self::OUTCOME_FAILED], true) === true
            ? $outcome
            : self::OUTCOME_FAILED;
        try {
            // ⚠️ AN UPSERT, NOT AN UPDATE. A job whose page was changed to call
            //    only finished() would otherwise record nothing at all, and the
            //    silence would look exactly like the job not running.
            $stmt = $db->prepare(
                'INSERT INTO `tblScheduledJobRun` '
                . '(`jobKey`, `startedAtUtc`, `finishedAtUtc`, `lastOutcome`) '
                . 'VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?) '
                . 'ON DUPLICATE KEY UPDATE '
                . '`finishedAtUtc` = UTC_TIMESTAMP(), `lastOutcome` = VALUES(`lastOutcome`)'
            );
            $stmt->bind_param('ss', $jobKey, $word);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * 📖 What is known about every job, for the "Check this portal" page.
     *
     * Every job in JOBS appears in the answer, whether or not it has a record,
     * because a job missing from the list would read as "fine" (#671: an
     * absence looks exactly like an all-clear).
     *
     * `seconds` is how long ago the job last FINISHED, worked out by the
     * database rather than by PHP, so neither clock nor zone comes into it —
     * every moment in this table is UTC from `UTC_TIMESTAMP()`.
     *
     * @return array<string, array{status: string, whatStops: string, token: string,
     *                             overdue: int, seconds: int|null, outcome: string}>
     */
    public static function state(mysqli $db): array
    {
        $rows      = [];
        $readBroke = false;
        if (self::tableExists($db) === true) {
            try {
                $result = $db->query(
                    'SELECT `jobKey`, `lastOutcome`, '
                    . 'TIMESTAMPDIFF(SECOND, `finishedAtUtc`, UTC_TIMESTAMP()) AS `sinceFinished`, '
                    . 'TIMESTAMPDIFF(SECOND, `startedAtUtc`, UTC_TIMESTAMP()) AS `sinceStarted`, '
                    . '(`finishedAtUtc` IS NULL) AS `neverFinished` '
                    . 'FROM `tblScheduledJobRun`'
                );
                while (($row = $result->fetch_assoc()) !== null) {
                    $rows[(string) $row['jobKey']] = $row;
                }
                $result->free();
            } catch (Throwable $e) {
                // ⚠️ THE TABLE IS THERE AND THE READ FAILED, which is NOT the
                //    same as a job never having recorded anything. Saying
                //    "nothing is known yet" here would tell an administrator
                //    to wait for something that is never going to happen — a
                //    half-applied migration, say. Never the fault's own words:
                //    a database message can quote the value that caused it.
                $rows      = [];
                $readBroke = true;
            }
        }

        $out = [];
        foreach (self::JOBS as $jobKey => $facts) {
            $row = $rows[$jobKey] ?? null;
            if ($row === null) {
                $out[$jobKey] = [
                    'status'    => $readBroke === true ? self::COULD_NOT_READ : self::NOT_KNOWN,
                    'whatStops' => $facts['whatStops'],
                    'token'     => $facts['token'],
                    'overdue'   => $facts['overdue'],
                    'seconds'   => null,
                    'outcome'   => '',
                ];
                continue;
            }
            $neverFinished = (int) $row['neverFinished'] === 1;
            $since         = $neverFinished ? (int) $row['sinceStarted'] : (int) $row['sinceFinished'];

            // A run that began and never reached the end is its own state, and
            // it is only worth saying once the run is past its own threshold:
            // a job that started a second ago has simply not finished YET.
            //
            // ⚠️ BUT IT IS STILL NOT "FINISHED", and answering RECENT here let
            //    the page claim every job had recorded a finish when one had
            //    not. RUNNING_NOW is the honest answer, and the page counts it
            //    separately.
            if ($neverFinished === true && $since > $facts['overdue']) {
                $status = self::DIED_PART_WAY;
            } elseif ($neverFinished === true) {
                $status = self::RUNNING_NOW;
            } elseif ($since > $facts['overdue']) {
                $status = self::OVERDUE;
            } else {
                $status = self::RECENT;
            }

            $out[$jobKey] = [
                'status'    => $status,
                'whatStops' => $facts['whatStops'],
                'token'     => $facts['token'],
                'overdue'   => $facts['overdue'],
                'seconds'   => $since,
                'outcome'   => (string) $row['lastOutcome'],
            ];
        }

        return $out;
    }

    /**
     * 🕒 The allowances in words, DERIVED from the list rather than typed.
     *
     * ⚠️ TYPING THEM OUT WAS WRONG TWICE IN TWO ROUNDS. Round 4's wording
     *    claimed the allowance matched each job's own cadence, which is false
     *    for six of them. Round 5 replaced it with four figures — and there
     *    are FIVE: `cron/import-feeds` has six hours, and fell under "36 hours
     *    for the rest", so the page told an administrator its allowance was
     *    SIX TIMES what it is. The same comment also called 36 hours "the
     *    longest of them" when eight days is.
     *
     *    So nothing here is typed. The figures and the job counts come out of
     *    JOBS, which means a new job or a changed constant cannot leave this
     *    sentence stale — the shape of mistake that made it wrong both times.
     *
     * @return string A list ready to drop into a sentence, longest last.
     */
    public static function allowancesInWords(): string
    {
        $byLimit = [];
        foreach (self::JOBS as $facts) {
            $byLimit[$facts['overdue']] = ($byLimit[$facts['overdue']] ?? 0) + 1;
        }
        ksort($byLimit);
        $parts = [];
        foreach ($byLimit as $seconds => $howMany) {
            $parts[] = self::lengthInWords((int) $seconds)
                . ($howMany === 1 ? ' for one job' : ' for ' . $howMany . ' of them');
        }
        $last = array_pop($parts);

        return count($parts) === 0 ? (string) $last : implode(', ', $parts) . ' and ' . $last;
    }

    /** A number of seconds as a person would say it. */
    private static function lengthInWords(int $seconds): string
    {
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' minutes';
        }
        if ($seconds < 172800) {
            $hours = intdiv($seconds, 3600);
            return $hours === 1 ? 'an hour' : $hours . ' hours';
        }

        return intdiv($seconds, 86400) . ' days';
    }

    /** Whether this is a job this class knows, compared exactly: the keys are written here. */
    public static function isKnownJob(string $jobKey): bool
    {
        return array_key_exists($jobKey, self::JOBS);
    }

    /**
     * 🕒 How long ago, in words an administrator reads.
     *
     * Deliberately rough. The question on that page is "has this stopped", not
     * "exactly when did it run", and a precise figure invites somebody to read
     * a two-second difference as meaningful.
     *
     * ⚠️ THE DIVISION BY 86,400 IS AN EXACT DURATION, NOT CALENDAR DAYS, and
     *    `check_clock_change_arithmetic.py` is right to ask: twice a year a
     *    calendar day is 23 or 25 hours, and dividing a span by 86,400 to get
     *    calendar days is out by one across a change. It is sound HERE because
     *    the span arrives from `TIMESTAMPDIFF(SECOND, ...)` between two moments
     *    BOTH IN UTC, and UTC has no transitions — so there is no calendar in
     *    it to be wrong about. The same case as the server's uptime, which is
     *    that check's other allowed entry.
     *
     *    The cost, stated rather than waved away: across a clock change this
     *    can read one day short of what somebody counting on a calendar would
     *    say, because 3 calendar days spanning a spring-forward are 3 × 86,400
     *    minus an hour. For a phrase this rough that changes nothing, and
     *    **the overdue decision never comes through here** — `state()` compares
     *    raw seconds with the job's own threshold, so nothing acts on these
     *    words.
     */
    public static function howLongAgo(int $seconds): string
    {
        if ($seconds < 60) {
            return 'less than a minute ago';
        }
        if ($seconds < 3600) {
            $mins = intdiv($seconds, 60);
            return $mins === 1 ? 'a minute ago' : $mins . ' minutes ago';
        }
        if ($seconds < 172800) {
            $hours = intdiv($seconds, 3600);
            return $hours === 1 ? 'an hour ago' : $hours . ' hours ago';
        }
        $days = intdiv($seconds, 86400);

        return $days . ' days ago';
    }

    /**
     * Whether `tblScheduledJobRun` is there yet.
     *
     * ⚠️ `SHOW TABLES LIKE`, NEVER a caught error. "The migration has not run
     *    yet" is an ordinary, expected state on a server that has just had its
     *    files updated, and it must not be answered the same way as something
     *    being wrong (#694).
     *
     *    Asked once per request and remembered, because fifteen jobs never run
     *    in one request but the check page asks about all fifteen at once.
     */
    private static function tableExists(mysqli $db): bool
    {
        if (self::$tableThere !== null) {
            return self::$tableThere;
        }
        try {
            $result = $db->query("SHOW TABLES LIKE 'tblScheduledJobRun'");
            $there  = $result->num_rows > 0;
            $result->free();
        } catch (Throwable $e) {
            $there = false;
        }

        return self::$tableThere = $there;
    }

    /** Forget whether the table is there. For tests, and for the upgrade page after a migration. */
    public static function forgetTableCheck(): void
    {
        self::$tableThere = null;
    }
}
