<?php
// Path: _apps/cron/_retention-sweep.php
/**
 * -----------------------------------------------------------------------------
 * Shared — Audit-Log Retention Sweep (the code both callers run) 🧹
 * -----------------------------------------------------------------------------
 * NOT a page. No address points at this file; it is only ever loaded with
 * require_once by the two places that run the sweep:
 *
 *   - web/_apps/admin/maintenance/retention.php — the staff page, where a
 *     global administrator sees the counts and presses "Run Sweep Now";
 *   - web/_apps/cron/retention-sweep.php — the scheduled job at
 *     /cron/retention-sweep?token=…, run by the hosting company's scheduler.
 *
 * WHY THIS FILE EXISTS
 * Until 14 September 2026 these functions sat at the bottom of the staff page,
 * and the scheduled job was a "?cron=1" mode of that same page. That stopped
 * being possible once the Router started enforcing sign-in (issue #497): the
 * staff page is a protected address, so a scheduler arriving with a token and
 * no session would be sent to the sign-in page and the sweep would silently
 * never run. The job therefore moved to its own address, and the code moved
 * here so the page and the job still run exactly the same code. Two copies
 * would drift apart, and then the count shown to an administrator and the
 * rows the job deletes would stop matching — for a table holding children's
 * medical notes that is not an acceptable risk.
 *
 * The functions are moved here unchanged apart from one comment inside
 * run_retention_sweep() that described who could open the page, which was out
 * of date.
 *
 * WHAT THIS FILE CANNOT DO
 * It does no permission check of its own. Each caller decides who may run the
 * sweep: the staff page requires a global administrator, the job requires the
 * `maintenance.cronToken` token.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/497
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/491
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/574
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/810
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// 📦 WHAT THIS FILE NEEDS, for anybody requiring it WITHOUT the autoloader
// -----------------------------------------------------------------------------
// The two real callers (the scheduled job and the staff page) come through
// bootstrap, which autoloads. `tools/gdpr-eraser-selftest.php` requires this
// file directly and must load each class itself, IN DEPENDENCY ORDER:
//
//   App, Logger, Site, Giving, GivingRetention, GdprEraser, AnonymousCheckins,
//   InstallationSettings -> SettingLimit (the log clear-outs ask it for the
//   installation's own period, #491; `tools/retention-caps-selftest.php`
//   section F requires this file with exactly those three loaded and nothing
//   else, because it calls only the log functions),
//   ActivityLogRetention (#932 — the activity log's own period, where 0 means
//   "never delete"). THIS ONE IS LOADED BY THIS FILE ITSELF, just below, so no
//   caller's hand-picked list has to name it: the four self-tests that require
//   this file each keep their own list, and one of them belongs to a package
//   that must not be edited alongside this one. It needs only the three above,
//   and loads those itself if nothing has.
//   InstallationSettings -> SettingLimit -> WebhookRetention (#713),
//   InstallationSettings -> SettingLimit -> TranscriptRetention (#714),
//   Pdf (#721 — it has no dependencies of its own; dompdf is only loaded by
//   create(), and sweepPartFiles() never reaches it),
//   InstallationSettings -> App -> SettingLimit -> RegistrationRetention
//   (#816 — the registrations clear-out reads each organisation's period
//   through it; it loads SettingLimit itself if nothing has, but App must be
//   there because `daysForSite()` asks `App::settingForSite()`),
//   Site -> I18n -> Events (#810 — the registrations clear-out asks
//   `Events::nowForADeletionDeadline()` for each event's deadline reading,
//   which reads the zones through `Site` and the clock through `I18n`; anybody
//   calling `registration_sweep()`, `preview_retention_counts()` or
//   `run_retention_sweep()` needs all three)
//
// Adding a class here without adding it there stops that test dead at the first
// line that mentions it. That happened twice in one session, which is why the
// list is written down rather than remembered.
// -----------------------------------------------------------------------------

use Portal\Core\ActivityLogRetention;
use Portal\Core\AnonymousCheckins;
use Portal\Core\App;
use Portal\Core\Events;
use Portal\Core\GdprEraser;
use Portal\Core\GivingRetention;
use Portal\Core\Logger;
use Portal\Core\Pdf;
use Portal\Core\RegistrationRetention;
use Portal\Core\SettingLimit;
use Portal\Core\TranscriptRetention;
use Portal\Core\WebhookRetention;

// 📜 #932: see the list above. Loaded without asking the autoloader first
//    (`class_exists(..., false)`), because a caller with no autoloader would
//    otherwise stop at the first line naming the class.
if (class_exists(ActivityLogRetention::class, false) === false) {
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'ActivityLogRetention.php';
}

/**
 * 🗒️ The two log clear-outs, each with the column that dates a row and the
 *     setting that decides how long that organisation keeps them (#491).
 *
 * ONE LIST, because the count an administrator is shown and the delete that
 * then runs must be told the same three things. Written out here rather than
 * passed in by each caller: a table paired with the wrong date column or the
 * wrong setting in ONE of the two callers is exactly how a page comes to
 * promise one number and a job to remove another.
 *
 * Both names are literals in this file and never come from a request, which is
 * what makes it safe to put them into a statement. They are backticked where
 * they are used, like every other identifier this project writes.
 *
 * @return array<string, array{table: string, dateColumn: string, key: string}>
 */
function log_sweep_tables(): array
{
    return [
        'activity' => ['table' => 'tblActivityLogs', 'dateColumn' => 'timestamp', 'key' => 'audit.retentionDays'],
        'errors'   => ['table' => 'tblErrors',       'dateColumn' => 'createdAt', 'key' => 'errors.retentionDays'],
    ];
}

/** What an installation that has never chosen keeps logs for. A year. */
const LOG_RETENTION_DEFAULT_DAYS = 365;

/**
 * "Never delete" (#932, D-117). Only the activity log can ever answer it —
 * `ActivityLogRetention` decides that — and `log_sweep()` deletes nothing for a
 * figure equal to it. The error log's figure is never 0: see below.
 */
const LOG_RETENTION_NEVER = 0;

/**
 * The most either of these may ever be, whatever is in the database. Ten
 * years, the same ceiling the other retention periods use (#713, #714), so a
 * figure typed in by hand cannot mean "keep for ever by accident".
 */
const LOG_RETENTION_HARD_MAX_DAYS = 3650;

/**
 * 🏛️ The number of days the INSTALLATION keeps these logs for — or, for the
 *    activity log only, 0 for "never delete".
 *
 * This is both the period for rows that belong to no organisation, and the
 * ceiling every organisation is held to — "for a setting that is a LIMIT the
 * installation's value takes precedence; an organisation may hold itself to a
 * lower limit and may never raise its own above it".
 *
 * WHAT A STORED 0 MEANS DIFFERS BETWEEN THE TWO SETTINGS, ON PURPOSE.
 *
 *   - `audit.retentionDays` (the activity log): "never delete", since #932
 *     (the owner, 10 November 2026 — D-117). 0 is the LARGEST value, as it is
 *     for the attendance, webhook and transcript periods. The answer comes
 *     from `ActivityLogRetention`, the ONE place it is decided, and it is NOT
 *     clamped to the ten-year maximum: D-117 rules that out by name.
 *   - `errors.retentionDays` (the error log): still "nothing usable, fall back
 *     to a year", as the code this replaced did with
 *     `if ($activityDays < 1) { $activityDays = 365; }`. The owner named the
 *     activity log only, and D-117 rules out extending the choice to
 *     `tblErrors`. Handing that 0 to the ceiling machinery as a number of days
 *     would be worse still: a ceiling of 0 compared as days would clear EVERY
 *     row on every run.
 *
 * Before #932 the first rule was the second one too, and this comment said
 * "ZERO IS NOT A MEANING FOR THESE TWO SETTINGS". That is no longer true of
 * the activity log, and was never an owner's decision.
 *
 * WHAT THIS CANNOT DO. When start-up has not handed over the installation-wide
 * layer at all (a file arriving late on shared hosting), the layer is empty and
 * this answers the built-in year rather than what the installation chose. For a
 * clear-out that errs towards keeping rows longer, which is the recoverable
 * direction for the organisation's own history and the less careful one for
 * privacy. Said here rather than left to be discovered.
 */
function log_retention_installation_days(string $key): int
{
    // 📜 The activity log: its own rule, in its own class (#932).
    if ($key === ActivityLogRetention::KEY) {
        return ActivityLogRetention::installationDays();
    }

    $ceiling = SettingLimit::installationAllows(
        $key,
        LOG_RETENTION_DEFAULT_DAYS,
        LOG_RETENTION_HARD_MAX_DAYS
    );

    return $ceiling < 1 ? LOG_RETENTION_DEFAULT_DAYS : $ceiling;
}

/**
 * 📄 The number of days ONE organisation keeps these logs for, held to what the
 *    installation allows — or, for the activity log only, 0 for "never delete",
 *    which survives only where the installation ALSO says never (#932).
 *
 * Read one organisation at a time, by number, and never from the merged
 * settings array. `App::settings()` answers the CURRENT organisation's value,
 * and this sweep is run by a scheduled job that has no current organisation at
 * all (#683) — so the merged array would apply whichever organisation the job
 * happened to resolve to, to every organisation's rows. That was #491.
 *
 * `App::settingForSite()` falls back to the installation-wide row by itself, so
 * an organisation that has chosen nothing is already following the
 * installation; the cap below then compares that figure with itself and changes
 * nothing. For the ERROR log an unusable figure — empty, not a number, zero or
 * negative — is read as "nothing chosen" for the reason given above
 * `log_retention_installation_days()`. For the ACTIVITY log, 0 is a real answer
 * since #932 ("never delete"), so it is handed to
 * `ActivityLogRetention::daysForSite()`, which holds it to the installation's
 * figure — the same shape as `AnonymousCheckins::readRetentionDays()`.
 *
 * A SAVE-TIME CAP WOULD NOT BE A CAP (#668). For the ERROR log no page can
 * create a per-organisation row at all — `_apps/settings/save.php` counts the
 * installation's own row as a clash and refuses — so such a row can only be
 * here because somebody wrote it with SQL or because a whole-database restore
 * brought it back. Since #932 the ACTIVITY log's per-organisation row IS
 * written by a page (Admin, Maintenance, Retention, by that organisation's own
 * administrator — D-118 — through `ActivityLogRetention`, which refuses a
 * figure above the installation's, and refuses an installation figure below an
 * organisation's — D-119); its reader caps as well, because a row from SQL or
 * a restore is precisely what a save-time refusal never sees.
 */
function log_retention_days(string $key, int $siteId): int
{
    // 📜 The activity log: its own rule, in its own class (#932).
    if ($key === ActivityLogRetention::KEY) {
        return ActivityLogRetention::daysForSite($siteId);
    }

    $ceiling = log_retention_installation_days($key);

    $own  = App::settingForSite($key, $siteId);
    $text = $own === null ? '' : trim((string) $own);
    if (preg_match('/^\d{1,18}$/', $text) !== 1 || (int) $text < 1) {
        return $ceiling;
    }

    return min((int) $text, $ceiling);
}

/**
 * 🧹 Count, or really delete, the old rows of ONE of the two log tables —
 *    organisation by organisation, each by its own number of days.
 *
 * ONE FUNCTION FOR BOTH, with `$reallyDelete` deciding which, for the reason
 * `registration_sweep_where()` sets out above: an administrator is shown a
 * number before confirming, and a count worked out by a different rule from the
 * delete would eventually promise one thing and do another.
 *
 * WHAT WAS WRONG BEFORE (#491). Both clear-outs read ONE pair of settings
 * through `App::settings()` and then deleted across the whole installation. An
 * administrator of one organisation choosing "keep activity logs for 7 days"
 * shortened every other organisation's history, including one that had
 * deliberately chosen a year; and run from the scheduled job, which has no
 * current organisation, whichever value happened to resolve decided for
 * everybody. Both tables DO carry a `siteID`, so the rows can be told apart —
 * that was checked in the schema rather than assumed, and it is what makes the
 * per-organisation fix possible at all.
 *
 * THE ROWS THAT BELONG TO NO ORGANISATION ARE SWEPT TOO, by the INSTALLATION's
 * own figure, and leaving them out would have been the quiet fault in this
 * change: `siteID` is empty on both tables for a record written before the
 * organisation was decided, for one written by a scheduled job, and for one
 * whose organisation has since been deleted (`fk_logs_site` and `fk_errors_site`
 * are both `ON DELETE SET NULL`). A loop over `tblSites` alone would have left
 * every one of them on disk for ever, which for the activity log means the
 * sender's address, the browser description and the session facts. They belong
 * to the installation, not to any customer, so the installation's administrator
 * is the right party to decide how long they are kept.
 *
 * "NEVER DELETE" DELETES NOTHING, AND COUNTS NOTHING (#932). Where the figure
 * is `LOG_RETENTION_NEVER` the statement is not run at all for that
 * organisation (or for the rows nobody owns), so the count an administrator is
 * shown and the delete agree on it. Only the activity log's figure can be 0;
 * the error log's helpers never answer it.
 *
 * @param string $which One of the keys of `log_sweep_tables()`.
 *
 * @return int Rows counted, or rows really deleted.
 */
function log_sweep(string $which, bool $reallyDelete): int
{
    $spec = log_sweep_tables()[$which] ?? null;
    if ($spec === null) {
        return 0;
    }

    $db    = App::db();
    $total = 0;

    $sql = ($reallyDelete === true ? 'DELETE FROM `' : 'SELECT COUNT(*) AS cnt FROM `')
        . $spec['table'] . '` WHERE ';
    $byDate = '`' . $spec['dateColumn'] . '` < DATE_SUB(NOW(), INTERVAL ? DAY)';

    // 🏢 One organisation at a time, each by its own figure.
    $perSite = $db->prepare($sql . '`siteID` = ? AND ' . $byDate);
    if ($perSite !== false) {
        $sites = $db->query('SELECT `siteID` FROM `tblSites`');
        if ($sites !== false) {
            while ($one = $sites->fetch_assoc()) {
                $siteId = (int) $one['siteID'];
                $days   = log_retention_days($spec['key'], $siteId);
                if ($days === LOG_RETENTION_NEVER) {
                    // ♾️ This organisation keeps its log for ever (#932).
                    continue;
                }
                $perSite->bind_param('ii', $siteId, $days);
                $perSite->execute();
                $total += $reallyDelete === true
                    ? (int) $perSite->affected_rows
                    : (int) ($perSite->get_result()->fetch_assoc()['cnt'] ?? 0);
            }
            $sites->free();
        }
        $perSite->close();
    }

    // 🏛️ And the rows no organisation owns, by the installation's own figure.
    // ♾️ Not even prepared when the installation keeps them for ever (#932).
    $orphanDays = log_retention_installation_days($spec['key']);
    $orphans    = $orphanDays === LOG_RETENTION_NEVER ? false : $db->prepare($sql . '`siteID` IS NULL AND ' . $byDate);
    if ($orphans !== false) {
        $days = $orphanDays;
        $orphans->bind_param('i', $days);
        $orphans->execute();
        $total += $reallyDelete === true
            ? (int) $orphans->affected_rows
            : (int) ($orphans->get_result()->fetch_assoc()['cnt'] ?? 0);
        $orphans->close();
    }

    return $total;
}

/**
 * 📏 The rule that decides whether one registration is past its keep-by date.
 *
 * Two limits worth stating plainly rather than leaving to be discovered.
 *
 * The number shown on the page is a snapshot, not a promise. More records
 * become eligible as time passes, and a setting can be changed in between, so
 * the count an administrator sees and the number actually removed a moment
 * later can differ slightly. The page says "currently eligible" for that
 * reason. Guaranteeing an exact figure would mean fixing the list of candidates
 * and checking each one again before deleting, which is not worth the
 * complication for a clear-out that runs on a timescale of months.
 *
 * 🕰️ MEASURED ON EACH EVENT'S OWN CLOCK (#810). `startDateTime` and
 * `endDateTime` are readings of the EVENT's clock (`tblEvents.timezone`). This
 * used to compare them with `DATE_SUB(NOW(), …)` — the DATABASE SERVER's clock,
 * in a session zone that is `SYSTEM` by default, so whatever the database host
 * happens to be set to. A reading on a clock BEHIND the server's is a smaller
 * number for the same moment, so it crossed the deadline EARLY by the
 * difference: on a database in UTC, an `Etc/GMT+12` event had its
 * registrations — names, a child's medical notes, a parent's telephone number
 * — deleted twelve hours before the period the portal promised was up, and a
 * New York one four or five hours before; on a database whose session zone is
 * Pacific/Auckland, a London event's went eleven to thirteen hours early,
 * depending on the time of year. At the
 * extremes (a server on UTC+14, an event on UTC-12) it is twenty-six hours.
 * That breaks the promise outright; it is not "a few hours at 90 days". (This
 * note used to say a few hours either way did not matter. Early deletion of
 * personal data is not a rounding error, and it cannot be undone.) An event on
 * a clock AHEAD of the server's was deleted late, which is harmless. (#810's
 * own text gives the direction the other way round, "ahead of the server";
 * section G of `tools/retention-caps-selftest.php` runs the old expression on
 * real rows and settles it.) `Events::nowOnItsOwnClock()` is the ONE place a
 * query asks what an event's own clock reads now; a DELETION asks its
 * sibling `Events::nowForADeletionDeadline()`, which can only be late.
 *
 * ERRING LATE IS THE SAFE DIRECTION, so the reading asked for is
 * `Events::nowForADeletionDeadline()`, not the reading a page uses, and every
 * case that cannot be exact is made late, never early:
 *   - THE DEADLINE IS THE LATER OF "n days on the event's clock" and
 *     "n x 24 real hours". The two differ across a night the clocks change
 *     (an hour, two in Antarctica/Troll) and across a date-line jump (a
 *     whole day, Pacific/Apia 2011); for a deletion the later one is the
 *     promise. The number n is unchanged; only which of the two meanings of
 *     "n days" applies where they disagree. The method's docblock has the
 *     working. The price is lateness, and it is not small: a row waits past
 *     the later deadline by the gap between its zone's offset when the event
 *     ended and that zone's LOWEST offset over the look-back window — an hour
 *     for ordinary summer time, about two hours for Antarctica/Troll and for
 *     America/Nuuk (which moved its clocks for good in 2023), and up to a day
 *     where a zone jumped the date line (Pacific/Apia, 2011). The window
 *     is this ORGANISATION's longest period, not the event's, so one event
 *     kept for ten years puts every event of the organisation on a ten-year
 *     window.
 *   - A row whose zone was written in the instant between the method's two
 *     statements (so it is not in the list) reads `Etc/GMT+12`, the clock
 *     that reads earliest anywhere on Earth, so it waits until the period is
 *     up on every clock there is.
 *   - "Now" is worked out in PHP a moment BEFORE the statement runs, so by
 *     the time the statement runs it is a little behind, never ahead.
 * A FAILED READ IS NOT A CASE HERE. `bootstrap.php` sets
 * `MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR`, so a statement that cannot be
 * prepared or run THROWS; the exception leaves `registration_sweep()` before
 * its delete runs, and nothing is deleted. (An earlier draft of this note said
 * every row would then read the earliest clock. That path cannot be reached
 * under strict reporting, and a safety claim resting on an unreachable path is
 * worth nothing.)
 * One thing this cannot make late, said plainly: "now" comes from the WEB
 * server's clock (PHP) rather than the database server's. A host whose clock
 * is wrong is wrong either way; with ordinary time-keeping the two agree to
 * well under a second, against a period counted in days.
 *
 * Written out once, on purpose, and used by BOTH the count shown to an
 * administrator and the delete that actually runs. If the two were written
 * separately they would eventually drift apart, and then the page would promise
 * to remove one number of records and remove a different one. For a table
 * holding children's medical notes that is not an acceptable risk.
 *
 * The values are bound in this order: the site, the fallback number of days
 * (to test it is above zero), then every value of the deadline-reading
 * expression, then the fallback number of days again (for the date
 * arithmetic). The function returns them with the clause, so no caller can
 * bind them in a different order from the one the clause was written in.
 *
 * Two details worth explaining, because both look redundant and neither is:
 *
 *   COALESCE(e.registrationRetentionDays, ?) - the event's own number of days
 *   when it has one, the site's setting otherwise. That is exactly the rule
 *   "the event's own setting wins". Testing it is above zero first is what
 *   makes zero mean "keep indefinitely".
 *
 *   GREATEST(COALESCE(end, start), start) - the later of the event's end and
 *   its start. An end time is optional, so it may be missing; but it can also
 *   be WRONG. Nothing stops somebody saving an event whose end is before its
 *   start, and nothing stops an event being moved into the future while its old
 *   end date is left behind. Taking whichever is later means a stale end date
 *   can never drag the deadline earlier than the event itself. Without it, an
 *   event starting in December with a leftover end date in January of the year
 *   just gone would have its registrations deleted today.
 *
 * @return array{sql: string, types: string, params: list<int|string>} The WHERE
 *         clause (including the word WHERE), with what to bind, in order.
 */
function registration_sweep_where(mysqli $db, int $siteId, int $days): array
{
    // ONE organisation's zones, never every organisation's: the clause
    // below is limited to `e.siteID = ?`, so this organisation's zones are
    // exactly the ones the statement can meet, and a scheduled job has no
    // "current" organisation to fall back to (the fault #491 was about).
    //
    // The look-back is the LONGEST period any of this organisation's events
    // is kept for — each event's own number of days, or the organisation's
    // where it has none — because the deadline reading's proof that it is
    // never earlier than n x 24 real hours needs a window at least that long
    // (see `Events::nowForADeletionDeadline()`). A failed read throws.
    $longest = 0;
    $max     = $db->prepare(
        'SELECT MAX(COALESCE(`registrationRetentionDays`, ?)) AS `longest` FROM `tblEvents` WHERE `siteID` = ?'
    );
    // A backstop only: under strict reporting a failed prepare throws.
    if ($max !== false) {
        $max->bind_param('ii', $days, $siteId);
        $max->execute();
        $longest = (int) ($max->get_result()->fetch_assoc()['longest'] ?? 0);
        $max->close();
    }
    $deadlineNow = Events::nowForADeletionDeadline($db, 'e', $siteId, max($longest, $days));

    return [
        'sql' => 'WHERE `e`.`siteID` = ? '
            . '  AND COALESCE(`e`.`registrationRetentionDays`, ?) > 0 '
            . '  AND GREATEST(COALESCE(`e`.`endDateTime`, `e`.`startDateTime`), `e`.`startDateTime`) '
            . '      < DATE_SUB(' . $deadlineNow['sql'] . ', INTERVAL COALESCE(`e`.`registrationRetentionDays`, ?) DAY)',
        'types'  => 'ii' . $deadlineNow['types'] . 'i',
        'params' => array_merge([$siteId, $days], $deadlineNow['params'], [$days]),
    ];
}

/**
 * 🧹 Count, or really delete, the registrations past their keep-by date —
 *    organisation by organisation, each by its own setting.
 *
 * ONE FUNCTION FOR BOTH, with `$reallyDelete` deciding which, exactly as
 * `log_sweep()` does: the count an administrator is shown before confirming
 * and the delete that then runs go through the same clause, built the same
 * way, for the same organisations. Until #810 the two callers each wrote their
 * own loop round one shared clause; the clause now carries values that differ
 * by organisation (each one's clocks), so the statement is prepared once per
 * organisation, and one loop is what keeps the count and the delete alike.
 *
 * @return int Registrations counted, or registrations really deleted.
 */
function registration_sweep(bool $reallyDelete): int
{
    $db    = App::db();
    $total = 0;

    $head = $reallyDelete === true
        ? 'DELETE `r` FROM `tblEventRegistrations` AS `r` '
        : 'SELECT COUNT(*) AS `cnt` FROM `tblEventRegistrations` AS `r` ';

    foreach (registration_sweep_sites() as $siteId => $days) {
        $where = registration_sweep_where($db, $siteId, $days);
        $stmt  = $db->prepare(
            $head
            . 'INNER JOIN `tblEvents` AS `e` ON `e`.`eventID` = `r`.`eventID` '
            . $where['sql']
        );
        // A backstop only: under this portal's strict reporting a statement
        // that cannot be prepared THROWS, which leaves this function before
        // any delete runs. This branch is for a caller without it.
        if ($stmt === false) {
            continue;
        }
        $stmt->bind_param($where['types'], ...$where['params']);
        $stmt->execute();
        $total += $reallyDelete === true
            ? (int) $stmt->affected_rows
            : (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }

    return $total;
}

/**
 * 🏢 Every site, with the number of days that site keeps registrations for.
 *
 * A site that has switched the clear-out off is left out of the list entirely,
 * so nothing of its is touched. Sites that are no longer in use are still
 * included: a site being closed down is not a reason to keep a child's medical
 * notes for ever - if anything it is a reason not to.
 *
 * @return array<int, int> The site's identity number, mapped to its number of days.
 */
function registration_sweep_sites(): array
{
    $db  = App::db();
    $out = [];

    $result = $db->query('SELECT siteID FROM tblSites');
    if ($result === false) {
        return $out;
    }

    while ($row = $result->fetch_assoc()) {
        $siteId = (int) $row['siteID'];

        // 🔛 Read in ONE place since #816, so the two pages that say what
        //    happens to registrations ask the same question the job does. The
        //    reading is unchanged: no row is ON, anything but `true` is OFF.
        if (RegistrationRetention::clearOutRunsForSite($siteId) === false) {
            continue;
        }

        // 🧒 The organisation's period, read in ONE place (#816). The reading
        //    is exactly what this loop did inline until then — no row is 90
        //    days, a negative number is 90 days (somebody typing "-1" has made
        //    a mistake, not expressed an intention), anything else is PHP's
        //    `(int)` of the text — and it moved so that the installation's
        //    optional ceiling judges a stored period exactly as this job acts
        //    on it.
        //
        //    ⚠️ IT IS NOT CAPPED BY THAT CEILING, and that is the owner's
        //       decision (D-074, following D-032), not an omission. Two
        //       save-time gates keep every period at or under the ceiling; a
        //       period above it can only be written by hand in SQL or brought
        //       back by a restored backup, and then this job keeps the details
        //       LONGER rather than deleting them early — a registration period
        //       can be a legal requirement, and an early deletion cannot be
        //       undone. See Portal\Core\RegistrationRetention's header.
        $out[$siteId] = RegistrationRetention::daysForSite($siteId);
    }
    $result->free();

    return $out;
}

/**
 * 🏢 Every site, with the number of days it keeps anonymous check-in detail for.
 *
 * "Detail" here means the scrambled version of the sender's internet address
 * that is stored on each anonymous check-in. Nothing anywhere ever shows it
 * on a screen — the attendance page's door-figures panel (#525) reads it
 * only to work out a count, never to display the address itself. (#530: a
 * browser-description column used to be stored here too, and unlike the
 * scrambled address, nothing ever read it for any purpose at all; migration
 * 201 removed it.)
 *
 * A site that keeps them for ever is left out of the list entirely, so nothing
 * of its is touched. SINCE #685 "for ever" survives only where the
 * INSTALLATION allows for ever: `readRetentionDays()` holds every organisation
 * to the installation's figure, so an organisation that stored 1000 days — or
 * 0 — while that was allowed is now cleared at the installation's number. The
 * cap is in the reader rather than only on the form because this job is what
 * actually clears, and a row written by hand, restored from a backup, or
 * stored before the save-time refusal existed would otherwise still be obeyed.
 *
 * ONE DELIBERATE DIFFERENCE FROM registration_sweep_sites() ABOVE. There, a
 * negative number of days is turned back into the ordinary 90-day default,
 * because somebody typing "-1" has made a mistake and keeping a child's medical
 * notes for ever is the dangerous outcome. Here a negative is treated the same
 * as 0 — keep for ever — because nothing sensitive is being kept and, for a
 * clear-out, doing nothing on a number nobody meant to type is the safe
 * direction. The two differ on purpose, and `AnonymousCheckins::
 * readRetentionDays()` is where that rule lives.
 *
 * @return array<int, int> The site's identity number, mapped to its number of days.
 */
function anon_checkin_sweep_sites(): array
{
    $db  = App::db();
    $out = [];

    $result = $db->query('SELECT siteID FROM tblSites');
    if ($result === false) {
        return $out;
    }

    while ($row = $result->fetch_assoc()) {
        $siteId = (int) $row['siteID'];

        // Read INSIDE the loop, so every organisation's own setting decides
        // what happens to its own rows. Reading one organisation's setting and
        // applying it everywhere is the fault the registrations sweep below
        // explains at length; the same reasoning applies exactly here.
        $days = AnonymousCheckins::readRetentionDays($siteId);
        if ($days < 1) {
            continue;
        }

        $out[$siteId] = $days;
    }
    $result->free();

    return $out;
}

/**
 * 🧾 The donors whose names each organisation is still keeping on its giving
 *    records after they asked to be erased, one organisation at a time, each
 *    with whether it may be cleared now (roll-out package A2b).
 *
 * One organisation at a time with ITS OWN rule, for exactly the reason set out
 * at length in run_retention_sweep() for registrations: this is run by a
 * scheduled job that has no current organisation, and one organisation's
 * number of years, hold switch or "confirmed" tick must never decide what
 * happens to another's records.
 *
 * The preview and the run both read this, and the decision itself is made in
 * ONE place (Portal\Core\GivingRetention::stateFor()), so the number an
 * administrator is shown and what the job then does cannot be decided by two
 * different rules.
 *
 * Every organisation is included, in use or not: an organisation being closed
 * down is not a reason to keep a donor's name for ever.
 *
 * @return array<int, array<int, array<string, mixed>>> Organisation number => its kept donors.
 */
function giving_identity_sweep_rows(): array
{
    $db  = App::db();
    $out = [];

    $result = $db->query('SELECT siteID FROM tblSites ORDER BY siteID');
    if ($result === false) {
        return $out;
    }
    $siteIds = [];
    while ($row = $result->fetch_assoc()) {
        $siteIds[] = (int) $row['siteID'];
    }
    $result->free();

    foreach ($siteIds as $siteId) {
        $rows = GivingRetention::keptDonorsForSite($db, $siteId);
        if ($rows !== []) {
            $out[$siteId] = $rows;
        }
    }

    return $out;
}

/**
 * Count rows that WOULD be deleted now — every organisation's, each by its own
 * period, which is why this takes no arguments any more (#491). It used to be
 * handed one number of days for the activity log and one for the error log, and
 * those two numbers then stood for the whole installation.
 *
 * `checkinDetail` is the odd one out and is named differently on purpose:
 * nothing is deleted there. It counts anonymous check-in ROWS that still hold
 * a scrambled address and would have it emptied out. The rows themselves,
 * and every count on them, stay. (#530: this used to also count a row that
 * held only a browser description; migration 201 removed that column, so
 * the scrambled address is the only detail left to count.)
 *
 * The `givingIdentity…` figures are not deletions either. They count donors
 * whose name is kept on giving records: how many would have it removed now,
 * and how many are being held back, and why. `givingIdentityUnfinished` is
 * the ones whose ACCOUNT HAS NOT BEEN EMPTIED YET: an erasure kept the name
 * and stopped before it emptied the account, so the person is still a
 * member. (A request can also be unfinished with the account already
 * emptied. Those are erased donors and are counted with the others.)
 *
 * @return array{activity:int,errors:int,registrations:int,checkinDetail:int,
 *               givingIdentityDue:int,givingIdentityOnHold:int,givingIdentityWaiting:int,givingIdentityNotDue:int,
 *               givingIdentityUnfinished:int}
 */
function preview_retention_counts(): array
{
    $db = App::db();
    $out = ['activity' => 0, 'errors' => 0, 'registrations' => 0, 'checkinDetail' => 0,
        'givingIdentityDue' => 0, 'givingIdentityOnHold' => 0, 'givingIdentityWaiting' => 0, 'givingIdentityNotDue' => 0,
        'givingIdentityUnfinished' => 0];

    // 📝 Activity logs and error rows, counted organisation by organisation
    //    with each organisation's own figure, plus the rows that belong to no
    //    organisation by the installation's — the same rule, through the same
    //    function, as the delete itself (#491). This used to take two numbers
    //    as arguments, which is what made one organisation's setting decide
    //    the whole installation's history.
    $out['activity'] = log_sweep('activity', false);
    $out['errors']   = log_sweep('errors', false);

    // 🧒 Registrations, counted site by site with each site's own setting -
    //    the same rule, and the same WHERE clause, as the delete itself. An
    //    administrator has to be shown the real number BEFORE confirming.
    //    Being told "12 log rows" and then silently losing several thousand
    //    children's registration records would be indefensible.
    $out['registrations'] = registration_sweep(false);

    // 🚪 Anonymous check-in detail, counted one organisation at a time with
    //    that organisation's own setting — the same loop the clear-out itself
    //    uses, so the number shown and the number acted on come from one rule.
    foreach (anon_checkin_sweep_sites() as $siteId => $days) {
        $out['checkinDetail'] += AnonymousCheckins::countDetailToClear($db, $siteId, $days);
    }

    // 🧾 Erased donors whose name is still kept on giving records. Counted
    //    from the same list, by the same decision, as the run itself.
    foreach (giving_identity_sweep_rows() as $rows) {
        foreach ($rows as $row) {
            $key = match ((string) $row['state']) {
                'due'           => 'givingIdentityDue',
                'on-hold'       => 'givingIdentityOnHold',
                'not-confirmed' => 'givingIdentityWaiting',
                // The erasure that made the row stopped before it emptied
                // the account. Never cleared; shown so that somebody runs
                // that request again.
                'not-erased'    => 'givingIdentityUnfinished',
                default         => 'givingIdentityNotDue',
            };
            $out[$key]++;
        }
    }

    return $out;
}

/**
 * 🧾 Remove erased donors' names from giving records whose keep-until date has
 *    passed (roll-out package A2b). Part of run_retention_sweep(); a function
 *    of its own so that it can be tested without also deleting old log rows.
 *
 * NOT A DELETE. When an organisation's keep-until date for a donor has
 * passed, the donor's name, address and account number are taken off that
 * organisation's giving records, and the statement files kept with them are
 * removed. Every gift, declaration and statement record stays, with its
 * amount and date.
 *
 * THIS LOOP DECIDES NOTHING. It hands every kept record to
 * GdprEraser::clearKeptGivingIdentity(), which clears one only when all four
 * are true: the donor's account has really been emptied; and, for the
 * organisation that owns the record, no hold is on, an administrator has
 * confirmed the number of years, and the keep-until date has passed where
 * that organisation is. The test is inside that method on
 * purpose, so that no caller - this one included - can clear early by leaving
 * a test out.
 *
 * One organisation at a time, each by its own rule: see
 * giving_identity_sweep_rows().
 *
 * What it cannot do: clear anything while nobody runs it. With the scheduled
 * job switched off (an empty `maintenance.cronToken`) a name stays until a
 * global administrator presses the button on the page.
 *
 * @return array{cleared: int, onHold: int, waiting: int, failed: int} `failed`: records that were
 *   due and could not be cleared. Nothing was changed for them.
 */
function run_giving_identity_sweep(): array
{
    $givingIdentityCleared = 0;
    $givingIdentityOnHold  = 0;
    $givingIdentityWaiting = 0;
    $givingIdentityFailed  = 0;
    foreach (giving_identity_sweep_rows() as $siteId => $rows) {
        $clearedHere = 0;
        foreach ($rows as $row) {
            switch (GdprEraser::clearKeptGivingIdentity((int) $row['keptDonorID'])) {
                case 'cleared':
                    $clearedHere++;
                    break;
                case 'on-hold':
                    $givingIdentityOnHold++;
                    break;
                case 'not-confirmed':
                    $givingIdentityWaiting++;
                    break;
                case 'failed':
                case 'unreadable':
                    $givingIdentityFailed++;
                    break;
                default:
                    // Not yet due; or no run of the erasure this record
                    // belongs to has emptied the account yet, so it still
                    // names the person ("not-erased"); or cleared by another
                    // run a moment ago.
                    break;
            }
        }
        if ($clearedHere > 0) {
            $givingIdentityCleared += $clearedHere;
            // One line per organisation, because the audit line for each
            // donor is on the trail of the erasure request, and that request
            // may have been filed at a different organisation.
            Logger::activity(
                'GivingIdentityCleared',
                'Organisation #' . $siteId . ': the keep-until date had passed for ' . $clearedHere
                . ' erased donor(s), so their names and addresses were removed from its giving records. '
                . 'No gift, declaration or statement record was deleted.'
            );
        }
    }

    return [
        'cleared' => $givingIdentityCleared,
        'onHold'  => $givingIdentityOnHold,
        'waiting' => $givingIdentityWaiting,
        'failed'  => $givingIdentityFailed,
    ];
}

/**
 * Perform the actual delete. Returns counts per table.
 *
 * Two of the returned numbers are NOT deletions and are named so that nobody
 * mistakes them for any: `checkinDaysStored` and `checkinDetailCleared`. See
 * the note beside them at the bottom of this function.
 *
 * Four more are not deletions either: the `givingIdentity…` figures. A donor
 * who asked to be erased may have their name kept on some giving records for
 * tax reasons, until their organisation's keep-until date. When that date has
 * passed the name, address and link are REMOVED FROM rows that all stay.
 *
 * @return array{activityDeleted:int,errorsDeleted:int,registrationsDeleted:int,
 *               checkinDaysStored:int,checkinDetailCleared:int,
 *               givingIdentityCleared:int,givingIdentityOnHold:int,givingIdentityWaiting:int,
 *               givingIdentityFailed:int,totalDeleted:int}
 */
/**
 * 🗑️ Erasures that offboarding asked for and whose undo window has passed.
 *
 * Only runs at all when `offboarding.erasureMode` is 'after_undo_window'.
 * The other mode, 'on_request' (the default), leaves every request waiting on
 * Admin → Erasure requests for a person to carry out, which is what most
 * will want: nothing has to be scheduled for it to be safe.
 *
 * WHY THE WINDOW IS LOAD-BEARING. Offboarding can be undone — that is what
 * `offboarding/rehire.php` is for, within `offboarding.undo_window_days` (7 by
 * default). Erasing somebody's details destroys exactly what a rehire puts
 * back, so an erasure must never run while that window is open. The window is
 * measured from `offboardedAt`, the moment the offboarding happened, not from
 * when the request was filed; they are the same moment today, and measuring
 * from the offboarding is what the window is actually about.
 *
 * ONLY 'pending_review' IS TAKEN. A request at 'processing' has a run in
 * flight — or a run that stopped half way, which `putBackStuckRequest()`
 * exists to deal with. Running `execute()` over it again could erase twice,
 * and "never twice" comes before "always promptly" for something
 * irreversible. Those are counted as waiting instead.
 *
 * EACH REQUEST IS CLAIMED FIRST, with the same conditional UPDATE the Erasure
 * requests page uses. That is what makes `execute()`'s own "put it back" and
 * "still going" updates work, because both look for 'processing'. An earlier
 * version of this function skipped the claim and its comment then said
 * "`execute()` puts a failed request back itself" — which was NOT true for
 * this caller, since that UPDATE matched nothing. The end state was right by
 * accident. A claim that fails means somebody else's run has it, and the
 * request is left alone.
 *
 * ONE FAILURE MUST NOT STOP THE REST, so each is attempted on its own and a
 * failure is counted rather than thrown.
 *
 * NOT gated on `offboarding.enabled`. A request that has already been filed is
 * a promise to erase somebody's details; switching the app off afterwards must
 * not quietly cancel it.
 *
 * WHAT THIS CANNOT DO
 *   - Tell whether the undo window was the right length, or notice an
 *     offboarding somebody meant to undo and forgot. Once the window on the
 *     person's newest departure has passed, the erasure runs.
 *   - Honour a PER-ORGANISATION choice of mode. `offboarding.erasureMode` is
 *     read once, from the settings of whichever organisation this scheduled
 *     request resolved to, and then applied to every organisation's
 *     offboardings — because `tblOffboarding` has no organisation column at
 *     all: offboarding is portal-wide. So an organisation that set its own
 *     override would not get it. Nothing stops that override being saved in
 *     the general Settings editor, which is the real gap; it is written down
 *     here rather than half-guarded.
 *   - Cancel a request when an offboarding is undone. `rehire.php` does that,
 *     and it has to, because in the default mode this function never runs.
 *
 * @return array{mode: string, ran: int, failed: int, waiting: int}
 */
function run_offboarding_erasure_sweep(): array
{
    $mode = (string) (App::settings('offboarding.erasureMode') ?? 'on_request');
    if ($mode !== 'after_undo_window') {
        return ['mode' => $mode, 'ran' => 0, 'failed' => 0, 'waiting' => 0];
    }

    $days = (int) (App::settings('offboarding.undo_window_days') ?? 7);
    if ($days < 0) {
        $days = 7;
    }

    $db   = App::db();
    $due  = [];
    $wait = 0;

    // 🎯 THE NEWEST offboarding row for the person, and only that one.
    //
    //    THIS WAS WRONG AND IT COULD HAVE ERASED SOMEBODY EARLY. The first
    //    version joined `r.userID = o.userID` with no link between the
    //    request and the offboarding that filed it, so ANY row for that person
    //    satisfied the age test. An independent check proved it against real
    //    MySQL: an offboarding from 2024 that was never undone made a request
    //    filed TODAY due on tonight's run, while the form had just promised
    //    the administrator that the person could still be brought back for
    //    seven days. That is not an exotic history — `rehire.php` refuses an
    //    undo once the window has passed, so ANYBODY who left, came back
    //    later and left again has an older row with `rehiredAt` still empty.
    //
    //    Joining to the newest row answers the question actually being asked:
    //    "has the window on THIS person's current departure passed, and are
    //    they still gone?" A person who has since been brought back has
    //    `rehiredAt` filled on that newest row and is excluded.
    $sql = 'SELECT r.requestID, r.userID, r.status '
         . 'FROM tblErasureRequest r '
         . 'INNER JOIN tblOffboarding o ON o.offboardingID = ('
         . '    SELECT o2.offboardingID FROM tblOffboarding o2 '
         . '     WHERE o2.userID = r.userID '
         . '     ORDER BY o2.offboardedAt DESC, o2.offboardingID DESC LIMIT 1'
         . ') '
         . "WHERE r.status IN ('pending_review', 'processing') "
         . "  AND o.dataDisposition IN ('erase', 'anonymise', 'delete') "
         . '  AND o.rehiredAt IS NULL '
         . '  AND o.offboardedAt <= DATE_SUB(NOW(), INTERVAL ? DAY) '
         . 'ORDER BY r.requestID';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        Logger::errorPlatform('Cron', 'Error', 'OFFBOARD_ERASE_READ', 'could not read offboarding erasures that are due', $db->error);
        return ['mode' => $mode, 'ran' => 0, 'failed' => 0, 'waiting' => 0];
    }
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $rows = $stmt->get_result();
    while (($row = $rows->fetch_assoc()) !== null) {
        if ((string) $row['status'] !== 'pending_review') {
            $wait++;
            continue;
        }
        $due[] = ['requestID' => (int) $row['requestID'], 'userID' => (int) $row['userID']];
    }
    $stmt->close();

    $ran    = 0;
    $failed = 0;
    foreach ($due as $one) {
        // 🔒 CLAIM IT FIRST, exactly as the Erasure requests page does
        //    (`_apps/admin/erasure/process.php`): one UPDATE that only
        //    succeeds from 'pending_review'.
        //
        //    The first version of this did not, and that was a real hole
        //    found by an independent check. Without the claim the row stayed
        //    at 'pending_review' for the WHOLE run, so the Execute button on
        //    that page stayed armed and could claim the same request while
        //    this was already erasing it — two runs over one account. It also
        //    meant `GdprEraser`'s own "put it back" and "still going" updates
        //    matched nothing, because they look for 'processing': a run that
        //    died half way left no sign of life at all, and
        //    `putBackStuckRequest()` could never apply to it.
        //
        //    A claim that fails is somebody else's run, not an error. It is
        //    counted as waiting and left completely alone.
        $claimed = false;
        try {
            $lock = $db->prepare(
                'UPDATE tblErasureRequest SET status = "processing", claimedAt = UTC_TIMESTAMP() '
                . 'WHERE requestID = ? AND status = "pending_review"'
            );
            if ($lock !== false) {
                $lock->bind_param('i', $one['requestID']);
                $lock->execute();
                $claimed = $lock->affected_rows > 0;
                $lock->close();
            }
        } catch (\Throwable $e) {
            // A database without migration 208 has no `claimedAt` column. The
            // run is refused rather than started with no way of telling later
            // whether it is still going — the same decision the staff page
            // makes. Nothing has been changed at this point.
            Logger::errorPlatform(
                'Cron',
                'Error',
                'OFFBOARD_ERASE_CLAIM',
                'could not claim an offboarding erasure that was due; it was left alone',
                'requestID=' . $one['requestID']
            );
            $claimed = false;
        }
        if ($claimed === false) {
            $wait++;
            continue;
        }

        try {
            // No administrator carried this out, so no account number is
            // recorded against it: the trail says the scheduled job did.
            GdprEraser::execute($one['requestID'], $one['userID'], null);
            $ran++;
        } catch (\Throwable $e) {
            $failed++;
            // 🚫 THE FAULT'S OWN WORDS ARE NOT LOGGED. An earlier version
            //    logged $e->getMessage() under a comment promising it named
            //    nobody — but a database or filesystem message can quote the
            //    very value being erased, such as an address or a path with
            //    somebody's name in it, and that row would then outlive the
            //    erasure by up to a year. Only the request number goes in.
            //    `GdprEraser::execute()` records its own failure against the
            //    request, where it belongs, and puts the request back.
            Logger::errorPlatform(
                'Cron',
                'Error',
                'OFFBOARD_ERASE_FAIL',
                'an offboarding erasure that was due could not be carried out; the request has been put back for the next run',
                'requestID=' . $one['requestID']
            );
        }
    }

    return ['mode' => $mode, 'ran' => $ran, 'failed' => $failed, 'waiting' => $wait];
}

/**
 * 🧾 Empty webhook payloads that are past their period. Answers how many rows
 *    were emptied.
 *
 * ITS OWN FUNCTION so that `tools/webhook-retention-selftest.php` can call THE
 * REAL CODE. A test holding its own copy of the logic it tests is not testing
 * anything, and the alternative here was a shim repeating these two statements.
 * The giving and offboarding sweeps in this same file are split out for their
 * own reasons; this one is split out for that one.
 */
function run_webhook_payload_sweep(): int
{
    $webhookPayloadsCleared = 0;
    $db = App::db();

    // 💳 Incoming payment events: one period, because the table has no
    //    organisation column and the provider's credentials are an
    //    installation-wide setting. 0 means "keep for ever", so nothing is
    //    emptied.
    $incomingDays = WebhookRetention::daysForPaymentEvents();
    if ($incomingDays > 0) {
        $stmt = $db->prepare(
            'UPDATE tblWebhookEvent SET payload = NULL '
            . 'WHERE payload IS NOT NULL AND receivedAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $incomingDays);
            $stmt->execute();
            $webhookPayloadsCleared += (int) $stmt->affected_rows;
            $stmt->close();
        }
}

// 📤 Outgoing deliveries: ONE ORGANISATION AT A TIME, for the reason the
//    registration sweep above sets out at length. A delivery belongs to a
//    webhook, which belongs to an organisation, and this job has no current
//    organisation — so reading the merged settings would apply whichever
//    organisation the job resolved to, to every organisation's rows (#683).
//
//    ⚠️ ONLY `delivered` AND `dead` ROWS, AND THAT IS LOAD-BEARING.
//       cron/webhook-retry.php re-sends a delivery FROM ITS STORED PAYLOAD.
//       Empty a pending or failed one and every later retry would send
//       nothing, for ever, while reporting that it had tried. A `dead` row
//       has been given up on, so its body is needed for nothing.
$stmt = $db->prepare(
    'UPDATE tblWebhookDeliveries AS d '
    . 'INNER JOIN tblWebhooks AS w ON w.webhookID = d.webhookID '
    . "SET d.payload = '', d.responseSnippet = NULL "
    . "WHERE w.siteID = ? AND d.status IN ('delivered', 'dead') "
    . "AND (d.payload <> '' OR d.responseSnippet IS NOT NULL) "
    . 'AND d.createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
);
if ($stmt !== false) {
    $sites = $db->query('SELECT siteID FROM tblSites');
    if ($sites !== false) {
        while ($one = $sites->fetch_assoc()) {
            $siteId = (int) $one['siteID'];
            $days   = WebhookRetention::daysForSite($siteId);
            if ($days <= 0) {
                // This organisation keeps them for ever, and the
                // installation allows that. Nothing to do for it.
                continue;
            }
            $stmt->bind_param('ii', $siteId, $days);
            $stmt->execute();
            $webhookPayloadsCleared += (int) $stmt->affected_rows;
        }
    }
    $stmt->close();
}

    return $webhookPayloadsCleared;
}

/**
 * 🎙️ Delete transcripts past their period. Answers how many were deleted.
 *
 * A transcript is the VERBATIM written-out words of a recording's audio —
 * whatever was spoken, including any name, address or telephone number somebody
 * read out — and `tblTranscriptSearch` holds a second copy for searching.
 * NOTHING EVER REMOVED EITHER: the only route was the cascade from
 * `tblRecording`, and an erasure never deletes a recording (it detaches
 * `uploadedByID` and `presenterID` and leaves the row), so the cascade never
 * fired.
 *
 * AN ERASURE COULD NOT REACH THEM, and not for want of a column to add: no
 * column says whose words they are. Somebody who merely SPOKE at the meeting is
 * nowhere on the row, and the transcript is a record of a meeting involving
 * other people, so deleting it on one participant's request would destroy what
 * everybody else said. The owner chose a recorded limit and a time limit
 * (3 November 2026). `Portal\Core\TranscriptRetention` decides the period.
 *
 * DELETED RATHER THAN EMPTIED, unlike a webhook payload: a transcript with no
 * text is not a record of anything, and deleting the row takes its search copy
 * with it through `fk_ts_transcript … ON DELETE CASCADE` — one statement, and no
 * way for the mirror to outlive the original. THE RECORDING IS UNTOUCHED.
 *
 * ONE ORGANISATION AT A TIME, for the reason the registration sweep sets out: a
 * transcript belongs to a recording, which belongs to an organisation, and this
 * job has no current organisation (#683).
 *
 * `generatedAt` is the basis, because that is when the words came into
 * existence. A row that never completed has none and no text either, so it is
 * left alone rather than swept on `queuedAt` — a recording still waiting in the
 * queue must not be quietly dropped from it.
 *
 * ⚠️ `AND t.generatedAt IS NOT NULL` IS REDUNDANT, AND IS KEPT AS DOCUMENTATION
 *    RATHER THAN AS A GUARD. `NULL < <date>` is NULL in SQL, so the comparison
 *    below already excludes a row with no date; deleting the clause changes
 *    nothing, which an independent check measured by deleting it and watching
 *    all 33 checks stay green. It is NOT load-bearing and no test can make it
 *    so — said here because a clause that looks protective and is not is worse
 *    than no clause, and somebody would otherwise write a check for it and
 *    believe the result. What DOES protect the queued row is the three-valued
 *    logic of the comparison itself, and the test proves the row survives
 *    whichever way it is written.
 */
function run_transcript_sweep(): int
{
    $db      = App::db();
    $deleted = 0;

    $stmt = $db->prepare(
        'DELETE t FROM tblTranscript AS t '
        . 'INNER JOIN tblRecording AS r ON r.recordingID = t.recordingID '
        . 'WHERE r.siteID = ? AND t.generatedAt IS NOT NULL '
        . 'AND t.generatedAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt === false) {
        return 0;
    }

    $sites = $db->query('SELECT siteID FROM tblSites');
    if ($sites !== false) {
        while ($one = $sites->fetch_assoc()) {
            $siteId = (int) $one['siteID'];
            $days   = TranscriptRetention::daysForSite($siteId);
            if ($days <= 0) {
                // This organisation keeps them for ever, and the installation
                // allows that. Nothing to do for it.
                //
                // ⚠️ THIS BRANCH COULD NOT BE REACHED UNTIL #722, and the
                //    comment above it was a guarantee the code had forgotten
                //    to implement. `TranscriptRetention::daysForSite()` worked
                //    its own three-tier arithmetic out, and the ceiling it
                //    compared against never answered 0 — `installationAllows()`
                //    hands back the hard maximum where "for ever" is stored,
                //    because what a form's `max` needs is a number of days. So
                //    an installation that chose "keep for ever" had every
                //    transcript deleted at ten years. It now goes through
                //    `SettingLimit::cappedForSite()`, where "for ever" survives
                //    where BOTH levels mean it, which is the rule written once.
                continue;
            }
            $stmt->bind_param('ii', $siteId, $days);
            $stmt->execute();
            $deleted += (int) $stmt->affected_rows;
        }
    }
    $stmt->close();

    return $deleted;
}

/**
 * 🧹 Remove part-written PDFs a killed process left behind (#721).
 *
 * `Pdf::writeOrWhyNot()` writes under a temporary name and renames it into
 * place (#711), so a process killed in between leaves a `.part-…` file. Before
 * this nothing swept them and they accumulated for ever.
 *
 * ONE HOUR is the owner's choice (3 November 2026) and the margin is the point:
 * a statement PDF is written in well under a second, so nothing in flight can
 * be caught. `Pdf::sweepPartFiles()` holds the walk and is the ONE place such a
 * file is removed; this function only decides WHERE and HOW OLD.
 *
 * ⚠️ THIS IS HOUSEKEEPING, NOT THE PRIVACY FIX, and the difference matters. A
 *    stray statement holds the donor's name and home address. What reaches it
 *    on an erasure request is TWO things together: the widened glob in
 *    `GdprEraser::statementFilesOf()`, and `statementFileIsKept()` refusing to
 *    treat a part-written file as tax evidence. The glob alone was not enough —
 *    it found the file and the kept test then KEPT it, and told the
 *    administrator so. Both land the moment the request runs, not at the next
 *    nightly sweep. Do not let this function be read as what makes an erasure
 *    complete.
 *
 * `_uploads` is passed as the root, but the sweep does NOT walk all of it:
 * `Pdf::PART_FOLDERS` is the one list of folders it may touch, and `documents`
 * and `recordings` are deliberately absent. The first version of this did sweep
 * everything, and an independent check measured a member's own upload being
 * deleted an hour after they made it — the two upload pages take the stored
 * file's extension straight from the client filename, so `report.part-x` became
 * `20261103_082700_aabbccdd.part-x` and matched. The database row survived, so
 * the portal went on offering a file that was gone.
 *
 * @return array{removed:int,left:int}
 */
function run_part_file_sweep(): array
{
    $root = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads';

    // 🛡️ A fault here must never stop the rest of the nightly job: this is the
    //    least important thing it does, and the clear-outs above it hold
    //    personal data to a deadline.
    try {
        return Pdf::sweepPartFiles($root, 3600);
    } catch (\Throwable $e) {
        // The fault's own words are not logged: a path can name a person
        // (a statement's file name carries a donor's account number).
        error_log('[WebMS-Intra] retention sweep: the part-written PDF sweep could not run.');

        return ['removed' => 0, 'left' => 0];
    }
}

function run_retention_sweep(): array
{
    $db = App::db();

    // 📝 Activity logs and error rows, ONE ORGANISATION AT A TIME (#491).
    //
    //    These two used to read a single pair of settings through
    //    `App::settings()` and then delete across the whole installation:
    //
    //      $activityDays = (int) (App::settings('audit.retentionDays') ?? '365');
    //      DELETE FROM tblActivityLogs WHERE timestamp < DATE_SUB(NOW(), …)
    //
    //    So an administrator of one organisation who chose "keep activity logs
    //    for 7 days" shortened every other organisation's history, including
    //    one that had deliberately chosen a year — and from the scheduled job,
    //    which has no current organisation at all, whichever value happened to
    //    resolve decided for everybody. The registration clear-out below has
    //    explained at length since #479 why that is wrong; these two are now
    //    the same shape. The rows that belong to no organisation are swept by
    //    the installation's own figure rather than left for ever; see
    //    `log_sweep()`.
    $activityDeleted = log_sweep('activity', true);
    $errorsDeleted   = log_sweep('errors', true);

    // 🧹 Children's event registrations.
    //
    //    This is the most sensitive clear-out here, and the one that matters
    //    most. Event registrations hold a child's name, date of birth,
    //    allergies and medical notes, together with a parent's telephone number
    //    and email address.
    //
    //    Most are submitted by people with no account - a parent should not
    //    have to create one to bring their child to a holiday club - which
    //    means a "delete everything you hold about me" request cannot reach
    //    them: there is nothing to match a person against. So nobody should
    //    have to ASK. After the event, and a reasonable gap, they simply go.
    //
    //    Two levels, because events genuinely differ. An event may set its own
    //    number of days; otherwise the site's setting applies. A residential
    //    trip may need longer for insurance; a single afternoon may want less.
    //
    //    Zero means keep indefinitely. It has to be set deliberately on an
    //    event, and is never the default - "we kept a child's medical notes for
    //    ever" should not be something that happens by accident.
    //
    //    Repeating events need no special handling, which is worth saying
    //    because it looks as though they should. A registration always points
    //    at one specific event record, and that record carries its own start
    //    and end times. Repetition is described separately, against the SERIES,
    //    and the only thing that reads it is the calendar feed export - which
    //    gathers matching event records up into a single repeating entry purely
    //    for the benefit of somebody's calendar application. Nothing generates
    //    event records from it. So the dates used below always belong to the
    //    very event the registration was made for.

    // One site at a time, deliberately.
    //
    //    (This note used to begin "the two clear-outs above read one set of
    //    settings and then delete across the whole portal". They did until
    //    #491, on 3 November 2026; they no longer do, and the reasoning below
    //    is what they were changed to follow.) This
    //    sweep is run by a scheduled job that has no current site at all.
    //    (This comment used to add that the staff page was open to ANY
    //    administrator. Since 13 September 2026 it is for global administrators
    //    only, issue #495, but the reasoning below does not depend on that.)
    //
    //    Read one site's settings and apply them everywhere, and an
    //    administrator of site B who sets "keep for 1 day" would silently
    //    destroy site A's registrations too - records site A believed it was
    //    keeping for 90 days. The reverse is just as bad: site B switching the
    //    clear-out off would leave every other site's children's medical notes
    //    sitting there indefinitely.
    //
    //    So each site's own setting decides, and the delete below is limited to
    //    that same site. A site is never able to reach another site's records.
    //    Each event's period is measured on the event's OWN clock (#810);
    //    see registration_sweep_where().
    $registrationsDeleted = registration_sweep(true);

    // 🚪 Anonymous check-in detail.
    //
    //    Not a delete. The rows stay exactly where they are, with their counts,
    //    their headcounts, how each check-in arrived and when it happened all
    //    untouched. What is emptied is the scrambled version of the sender's
    //    internet address — never shown on a screen, read only by
    //    AnonymousCheckins::summaryForEvent() to work out a count (#525).
    //    (#530: a browser-description column used to be emptied here too;
    //    nothing anywhere ever read it, for any purpose, so migration 201
    //    dropped it rather than leaving it to be cleared on a timer forever.)
    //
    //    They cannot simply be left. An anonymous check-in has no link to any
    //    person at all, so a "delete everything you hold about me" request can
    //    never reach one: there is nothing to match somebody against. Nobody
    //    should have to ask, so a time limit is the answer instead.
    //
    //    The figure for "probably how many different senders" is worked out
    //    from the scrambled address, so it would silently change the moment the
    //    address went. That is why the clear-out writes the figure down for
    //    each day BEFORE emptying that day's detail, and why this returns two
    //    numbers rather than one.
    //
    //    One organisation at a time, with its own setting, for exactly the
    //    reasons set out for the registrations sweep above.
    $checkinDaysStored    = 0;
    $checkinDetailCleared = 0;
    foreach (anon_checkin_sweep_sites() as $siteId => $days) {
        $result = AnonymousCheckins::clearOldDetail($db, $siteId, $days);
        $checkinDaysStored    += (int) $result['daysStored'];
        $checkinDetailCleared += (int) $result['rowsCleared'];
    }

    // 🧾 Giving records that kept an erased donor's name for tax reasons.
    //    Not a delete either: see run_giving_identity_sweep() above.
    $giving = run_giving_identity_sweep();
    $givingIdentityCleared = $giving['cleared'];
    $givingIdentityOnHold  = $giving['onHold'];
    $givingIdentityWaiting = $giving['waiting'];
    $givingIdentityFailed  = $giving['failed'];

    // 🗑️ Erasures offboarding asked for, now that their undo window has
    //    passed. Does nothing unless the installation has chosen that mode.
    $offboardErase = run_offboarding_erasure_sweep();

    $webhookPayloadsCleared = run_webhook_payload_sweep();
    $transcriptsDeleted    = run_transcript_sweep();
    $partFiles             = run_part_file_sweep();

    return [
        'activityDeleted'      => $activityDeleted,
        'errorsDeleted'        => $errorsDeleted,
        'registrationsDeleted' => $registrationsDeleted,
        // 🗑️ Offboarding erasures. Not added to totalDeleted either: an
        //    erasure empties and anonymises across many tables rather than
        //    deleting a countable number of rows, so a figure here would not
        //    mean the same thing as the others.
        'offboardErasureMode'    => $offboardErase['mode'],
        'offboardErasuresRun'    => $offboardErase['ran'],
        'offboardErasuresFailed' => $offboardErase['failed'],
        'offboardErasuresWaiting' => $offboardErase['waiting'],
        // 🚫 NONE of the figures from here down to totalDeleted is added to
        //    it, on purpose. Nothing was deleted: personal detail was emptied
        //    out of rows that remain. Adding them would make the scheduled
        //    job's own "deleted N rows" line untrue, and that line is what
        //    somebody reads when they are trying to work out what a sweep did.
        'checkinDaysStored'    => $checkinDaysStored,
        'checkinDetailCleared' => $checkinDetailCleared,
        'givingIdentityCleared' => $givingIdentityCleared,
        'givingIdentityOnHold'  => $givingIdentityOnHold,
        'givingIdentityWaiting' => $givingIdentityWaiting,
        // Records that were due and could not be cleared (a file that would
        // not delete, a write that failed). Nothing was changed for them; the
        // reason is in the server's error log and the next run tries again.
        'givingIdentityFailed'  => $givingIdentityFailed,
        // 🧾 Emptied, not deleted, so NOT added to totalDeleted — same rule as
        //    the figures above it.
        'webhookPayloadsCleared' => $webhookPayloadsCleared,
        // 🎙️ Transcripts really ARE deleted, so this one IS counted in
        //    totalDeleted — unlike every figure above it, which empties rows
        //    that remain.
        'transcriptsDeleted'   => $transcriptsDeleted,
        // 🧹 Part-written PDFs a killed process left behind (#721). FILES, not
        //    rows, so NOT added to totalDeleted — that figure is what somebody
        //    reads to work out what happened to the database, and mixing files
        //    into it would make it mean nothing. `partFilesLeft` is files that
        //    matched and would not delete, which is a permissions problem and
        //    is reported rather than hidden.
        'partFilesRemoved'     => $partFiles['removed'],
        'partFilesLeft'        => $partFiles['left'],
        'totalDeleted'         => $activityDeleted + $errorsDeleted + $registrationsDeleted
                                + $transcriptsDeleted,
    ];
}
