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
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AnonymousCheckins;
use Portal\Core\App;

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
 * NOW() is the database server's idea of the time, while these event columns
 * hold local wall-clock times. If the two disagree, the deadline moves by that
 * difference - later if the database is behind, earlier if it is ahead. At 90
 * days a few hours either way does not matter, but it is a real difference and
 * not a guaranteed-safe one, so it is written down here rather than assumed.
 *
 * Written out once, on purpose, and used by BOTH the count shown to an
 * administrator and the delete that actually runs. If the two were written
 * separately they would eventually drift apart, and then the page would promise
 * to remove one number of records and remove a different one. For a table
 * holding children's medical notes that is not an acceptable risk.
 *
 * Three values are bound, in this order: the site, then the fallback number of
 * days twice (once to test it is above zero, once to do the date arithmetic).
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
 * @return string The WHERE clause, including the word WHERE.
 */
function registration_sweep_where(): string
{
    return 'WHERE e.siteID = ? '
        . '  AND COALESCE(e.registrationRetentionDays, ?) > 0 '
        . '  AND GREATEST(COALESCE(e.endDateTime, e.startDateTime), e.startDateTime) '
        . '      < DATE_SUB(NOW(), INTERVAL COALESCE(e.registrationRetentionDays, ?) DAY)';
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

        $run = (string) (App::settingForSite('events.registrationRetentionRun', $siteId) ?? 'true');
        if ($run !== 'true') {
            continue;
        }

        $days = (int) (App::settingForSite('events.registrationRetentionDays', $siteId) ?? '90');
        if ($days < 0) {
            // A negative number is meaningless here. Treated as the ordinary
            // default rather than as "keep indefinitely", because somebody
            // typing "-1" has made a mistake, not expressed an intention.
            $days = 90;
        }

        $out[$siteId] = $days;
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
 * of its is touched.
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
 * Count rows that WOULD be deleted at the current window.
 *
 * `checkinDetail` is the odd one out and is named differently on purpose:
 * nothing is deleted there. It counts anonymous check-in ROWS that still hold
 * a scrambled address and would have it emptied out. The rows themselves,
 * and every count on them, stay. (#530: this used to also count a row that
 * held only a browser description; migration 201 removed that column, so
 * the scrambled address is the only detail left to count.)
 *
 * @return array{activity:int,errors:int,registrations:int,checkinDetail:int}
 */
function preview_retention_counts(int $activityDays, int $errorDays): array
{
    $db = App::db();
    $out = ['activity' => 0, 'errors' => 0, 'registrations' => 0, 'checkinDetail' => 0];

    $stmt = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM tblActivityLogs '
        . 'WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $activityDays);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $out['activity'] = (int) ($row['cnt'] ?? 0);
        $stmt->close();
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM tblErrors '
        . 'WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $errorDays);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $out['errors'] = (int) ($row['cnt'] ?? 0);
        $stmt->close();
    }

    // 🧒 Registrations, counted site by site with each site's own setting -
    //    the same rule, and the same WHERE clause, as the delete itself. An
    //    administrator has to be shown the real number BEFORE confirming.
    //    Being told "12 log rows" and then silently losing several thousand
    //    children's registration records would be indefensible.
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM tblEventRegistrations AS r '
        . 'INNER JOIN tblEvents AS e ON e.eventID = r.eventID '
        . registration_sweep_where()
    );
    if ($stmt !== false) {
        foreach (registration_sweep_sites() as $siteId => $days) {
            $stmt->bind_param('iii', $siteId, $days, $days);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $out['registrations'] += (int) ($row['cnt'] ?? 0);
        }
        $stmt->close();
    }

    // 🚪 Anonymous check-in detail, counted one organisation at a time with
    //    that organisation's own setting — the same loop the clear-out itself
    //    uses, so the number shown and the number acted on come from one rule.
    foreach (anon_checkin_sweep_sites() as $siteId => $days) {
        $out['checkinDetail'] += AnonymousCheckins::countDetailToClear($db, $siteId, $days);
    }

    return $out;
}

/**
 * Perform the actual delete. Returns counts per table.
 *
 * Two of the returned numbers are NOT deletions and are named so that nobody
 * mistakes them for any: `checkinDaysStored` and `checkinDetailCleared`. See
 * the note beside them at the bottom of this function.
 *
 * @return array{activityDeleted:int,errorsDeleted:int,registrationsDeleted:int,
 *               checkinDaysStored:int,checkinDetailCleared:int,totalDeleted:int}
 */
function run_retention_sweep(): array
{
    $activityDays = (int) (App::settings('audit.retentionDays')  ?? '365');
    $errorDays    = (int) (App::settings('errors.retentionDays') ?? '365');
    if ($activityDays < 1) { $activityDays = 365; }
    if ($errorDays    < 1) { $errorDays    = 365; }

    $db = App::db();
    $activityDeleted = 0;
    $errorsDeleted   = 0;

    $stmt = $db->prepare(
        'DELETE FROM tblActivityLogs WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $activityDays);
        $stmt->execute();
        $activityDeleted = (int) $stmt->affected_rows;
        $stmt->close();
    }

    $stmt = $db->prepare(
        'DELETE FROM tblErrors WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $errorDays);
        $stmt->execute();
        $errorsDeleted = (int) $stmt->affected_rows;
        $stmt->close();
    }

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
    $registrationsDeleted = 0;

    // One site at a time, deliberately.
    //
    //    The two clear-outs above read one set of settings and then delete
    //    across the whole portal. That is wrong here, and dangerously so. This
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
    $stmt = $db->prepare(
        'DELETE r FROM tblEventRegistrations AS r '
        . 'INNER JOIN tblEvents AS e ON e.eventID = r.eventID '
        . registration_sweep_where()
    );
    if ($stmt !== false) {
        foreach (registration_sweep_sites() as $siteId => $days) {
            $stmt->bind_param('iii', $siteId, $days, $days);
            $stmt->execute();
            $registrationsDeleted += (int) $stmt->affected_rows;
        }
        $stmt->close();
    }

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

    return [
        'activityDeleted'      => $activityDeleted,
        'errorsDeleted'        => $errorsDeleted,
        'registrationsDeleted' => $registrationsDeleted,
        // 🚫 NEITHER of these two is added to totalDeleted, on purpose. Nothing
        //    was deleted: personal detail was emptied out of rows that remain.
        //    Adding them would make the scheduled job's own "deleted N rows"
        //    line untrue, and that line is what somebody reads when they are
        //    trying to work out what a sweep did.
        'checkinDaysStored'    => $checkinDaysStored,
        'checkinDetailCleared' => $checkinDetailCleared,
        'totalDeleted'         => $activityDeleted + $errorsDeleted + $registrationsDeleted,
    ];
}
