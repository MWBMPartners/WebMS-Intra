<?php
// Path: _core/Discipleship.php
/**
 * -----------------------------------------------------------------------------
 * Discipleship Pathway Tracker — per-user progress engine 🧭 (#303 Phase 2)
 * -----------------------------------------------------------------------------
 * Static helper mirroring the house pattern (Giving, HostConsole, LiveChat).
 * Phase 1 (142) shipped pathway/step schema + admin CRUD; this class adds
 * the per-user enrolment + progress engine, including the lazy auto-sweep
 * that turns per-user attendance/RSVP evidence into completed steps.
 *
 * "Complete" everywhere in this class means an unrevoked
 * `tblPathwayProgress` row (`revokedAt IS NULL`) — unmarking a step sets
 * `revokedAt`, it never deletes the row (see migration 153 header comment
 * and DEV_NOTES.md "Discipleship Pathway Tracker Phase 2").
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/303
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Discipleship
{
    /**
     * 🎛️ Feature gate — mirrors the `'1'||'true'` check every Phase 1
     * handler repeats inline.
     */
    public static function isEnabled(): bool
    {
        $enabled = (string) Settings::get('discipleship.enabled', 'false');
        return $enabled === '1' || $enabled === 'true';
    }

    /**
     * 🎛️ The same switch, for ONE NAMED organisation rather than the one the
     *    request happens to be in (#819).
     *
     * `isEnabled()` reads the merged settings start-up built for the
     * request's own organisation. That is right for a page, and wrong for the
     * scheduled sweep, which is one request working through EVERY
     * organisation: it used to ask `isEnabled()` once and then sweep them
     * all, so an organisation that had switched Discipleship off was swept
     * whenever the organisation the request landed on had it on, and the
     * reverse refused everybody.
     *
     * It ASKS `AppRegistry::isEnabledForSite()`, which is how every app's
     * switch is read for one named organisation, rather than keeping a second
     * copy of that rule here (#819 round 1). The rule: the organisation's own
     * row, else the installation-wide one (`App::settingForSite()`); exactly
     * `'1'` or `'true'` is on; anything else -- and no row at all -- is off,
     * as `isEnabled()`'s default `'false'` is. The two were measured to agree
     * before this was made a call: X18i-X18j of `tools/time-zones-selftest.php`
     * drive the real scheduled sweep over ten stored values and over no row,
     * and gave the same answers with the old copy and with this call.
     *
     * @param int $siteId The organisation to ask about.
     */
    public static function isEnabledFor(int $siteId): bool
    {
        return AppRegistry::isEnabledForSite('discipleship', $siteId);
    }

    /**
     * 🤖 Auto-completion sweep. Three set-based `INSERT IGNORE … SELECT`
     * statements — one per `tblPathwaySteps.autoRule` value — each joining
     * active pathways × active enrolments × the matching per-user evidence
     * table. `INSERT IGNORE` + the UNIQUE(stepID, userID) key on
     * `tblPathwayProgress` makes every statement idempotent: a repeat run
     * inserts nothing new, and a row a coordinator has REVOKED blocks
     * re-insertion by design (the unique key row still exists).
     *
     * Scoped to one site always; optionally to one pathway (used by the
     * per-pathway admin roster page so it doesn't sweep the whole site on
     * every view).
     *
     * @param int      $siteId    Active site — every join is site-scoped.
     * @param int|null $pathwayId Optional — restrict the sweep to one pathway.
     *
     * @return int Total new progress rows inserted across all three rules.
     */
    public static function autoSweep(int $siteId, ?int $pathwayId = null): int
    {
        $db = App::db();
        $inserted = 0;

        // 🎫 Rule 1 — attended_event: a per-user attendance row against the
        // exact event the step names. tblEventAttendance has no siteID of
        // its own, so the site guard comes from the joined tblEvents row.
        $sql = 'INSERT IGNORE INTO tblPathwayProgress '
             . '(siteID, stepID, userID, source, autoRef, completedAt) '
             . 'SELECT p.siteID, s.stepID, e.userID, \'auto\', '
             . 'CONCAT(\'event:\', s.autoRefID), ea.markedAt '
             . 'FROM tblPathwaySteps s '
             . 'JOIN tblPathways p ON p.pathwayID = s.pathwayID AND p.isActive = 1 '
             . 'JOIN tblPathwayEnrolments e ON e.pathwayID = p.pathwayID AND e.status = \'active\' '
             . 'JOIN tblEventAttendance ea ON ea.eventID = s.autoRefID AND ea.userID = e.userID '
             . 'JOIN tblEvents ev ON ev.eventID = s.autoRefID AND ev.siteID = p.siteID '
             . 'WHERE s.autoRule = \'attended_event\' AND s.autoRefID IS NOT NULL AND p.siteID = ?';
        $inserted += self::runSweepRule($db, $sql, $siteId, $pathwayId);

        // 🎫 Rule 2 — attended_category: any per-user attendance row for any
        // event in the named category. First matching row wins; duplicates
        // (multi-day attendance) are ignored by the same unique key.
        $sql = 'INSERT IGNORE INTO tblPathwayProgress '
             . '(siteID, stepID, userID, source, autoRef, completedAt) '
             . 'SELECT p.siteID, s.stepID, e.userID, \'auto\', '
             . 'CONCAT(\'category:\', s.autoRefID), ea.markedAt '
             . 'FROM tblPathwaySteps s '
             . 'JOIN tblPathways p ON p.pathwayID = s.pathwayID AND p.isActive = 1 '
             . 'JOIN tblPathwayEnrolments e ON e.pathwayID = p.pathwayID AND e.status = \'active\' '
             . 'JOIN tblEventAttendance ea ON ea.userID = e.userID '
             . 'JOIN tblEvents ev ON ev.eventID = ea.eventID AND ev.categoryID = s.autoRefID AND ev.siteID = p.siteID '
             . 'WHERE s.autoRule = \'attended_category\' AND s.autoRefID IS NOT NULL AND p.siteID = ?';
        $inserted += self::runSweepRule($db, $sql, $siteId, $pathwayId);

        // 🎫 Rule 3 — rsvpd_event: a confirmed "going" RSVP to the named
        // event, only once the event has actually started (intent must
        // have met the date — a future RSVP never completes the step).
        //
        // 🕰️ "Has it started" is asked on the EVENT's OWN CLOCK (#804). This
        //    was `ev.startDateTime <= UTC_TIMESTAMP()`, which compares a
        //    reading of the event's clock with UTC: for an event in New York a
        //    step was marked complete up to five hours BEFORE the event began
        //    -- "a future RSVP never completes the step" broken, and stored for
        //    good, because the progress row this writes is permanent -- and for
        //    one in Auckland it waited up to thirteen hours after. The zones
        //    read are this organisation's events' (a step names one of its own
        //    events), and a zone PHP cannot open falls back to this
        //    organisation's clock.
        //
        // 🏢 THE EVENT MUST BELONG TO THE PATHWAY'S ORGANISATION (#812), as in
        //    rules 1 and 2. This join used to lack `ev.siteID = p.siteID`, so a
        //    step naming ANOTHER organisation's event could be completed by an
        //    RSVP to it, writing a permanent progress row this organisation's
        //    leaders see -- evidence from an organisation that never meant it
        //    to count here. (It also made the clock above wrong for such an
        //    event: its zone is read from THIS organisation's events only.)
        //
        //    How far it reached, measured rather than assumed: no page writes
        //    either half of it. `admin/discipleship/step-save.php` refuses a
        //    step naming an event of another organisation, and
        //    `calendar/rsvp.php` -- the only writer of an RSVP row -- stores
        //    the RSVP under the organisation the event belongs to, so
        //    `r.siteID = p.siteID` already ruled such an event out for every
        //    row the portal writes. It took a row written by hand, by a
        //    restore, or by some future writer. The guard is here anyway
        //    because the row this writes is permanent, and because the RSVP
        //    row's own `siteID` is not the same fact as the EVENT's
        //    organisation -- one is a copy of the other, made once, and rule 3
        //    was the only rule trusting the copy.
        //
        //    Nothing else in this join can cross organisations: the enrolment
        //    is reached through the pathway itself (`e.pathwayID =
        //    p.pathwayID`), the step through its pathway, and the pathway is
        //    this organisation's by `p.siteID = ?`. Like rules 1 and 2, the
        //    sweep does NOT ask whether the person is still a MEMBER of this
        //    organisation; that is a different question from whose event it
        //    was, and is left as it is.
        $nowOnClock = Events::nowOnItsOwnClock($db, 'ev', $siteId);
        $sql = 'INSERT IGNORE INTO tblPathwayProgress '
             . '(siteID, stepID, userID, source, autoRef, completedAt) '
             . 'SELECT p.siteID, s.stepID, e.userID, \'auto\', '
             . 'CONCAT(\'rsvp:\', s.autoRefID), r.createdAt '
             . 'FROM tblPathwaySteps s '
             . 'JOIN tblPathways p ON p.pathwayID = s.pathwayID AND p.isActive = 1 '
             . 'JOIN tblPathwayEnrolments e ON e.pathwayID = p.pathwayID AND e.status = \'active\' '
             . 'JOIN tblEventRSVPs r ON r.eventID = s.autoRefID AND r.userID = e.userID '
             . '  AND r.siteID = p.siteID AND r.response = \'going\' AND r.status = \'confirmed\' '
             . 'JOIN tblEvents ev ON ev.eventID = s.autoRefID AND `ev`.`siteID` = `p`.`siteID` '
             . '  AND `ev`.`startDateTime` <= ' . $nowOnClock['sql'] . ' '
             . 'WHERE s.autoRule = \'rsvpd_event\' AND s.autoRefID IS NOT NULL AND p.siteID = ?';
        $inserted += self::runSweepRule($db, $sql, $siteId, $pathwayId, $nowOnClock['types'], $nowOnClock['params']);

        // 🔄 Enrolment status follows from the progress state we just wrote.
        self::refreshEnrolmentStatuses($siteId, $pathwayId);

        return $inserted;
    }

    /**
     * Shared execution helper for the three autoSweep() rules — appends the
     * optional `AND p.pathwayID = ?` clause, binds params, and returns the
     * number of rows actually inserted (INSERT IGNORE reports 0 for rows it
     * skipped as duplicates).
     *
     * `$leadTypes` / `$leadParams` are bound FIRST, for a rule whose statement
     * has marks before its `WHERE` (rule 3's "now on the event's own clock",
     * #804, which sits in a join).
     *
     * @param list<string> $leadParams
     */
    private static function runSweepRule(\mysqli $db, string $sql, int $siteId, ?int $pathwayId, string $leadTypes = '', array $leadParams = []): int
    {
        if ($pathwayId !== null) {
            $sql .= ' AND p.pathwayID = ?';
        }

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return 0;
        }

        if ($pathwayId !== null) {
            $stmt->bind_param($leadTypes . 'ii', ...array_merge($leadParams, [$siteId, $pathwayId]));
        } else {
            $stmt->bind_param($leadTypes . 'i', ...array_merge($leadParams, [$siteId]));
        }
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected > 0 ? $affected : 0;
    }

    /**
     * 🔄 Recompute `tblPathwayEnrolments.status` from the current progress
     * state. An active enrolment flips to `completed` once every
     * non-optional step of its pathway has an unrevoked progress row; a
     * completed enrolment reverts to `active` if a step is later revoked
     * (handles unmarks). An all-optional pathway completes immediately —
     * the NOT EXISTS subquery is vacuously true over zero required steps.
     *
     * @param int      $siteId    Active site.
     * @param int|null $pathwayId Optional — restrict to one pathway.
     */
    public static function refreshEnrolmentStatuses(int $siteId, ?int $pathwayId = null): void
    {
        $db = App::db();

        // ✅ active → completed
        $sql = 'UPDATE tblPathwayEnrolments e '
             . 'SET e.status = \'completed\', e.completedAt = NOW() '
             . 'WHERE e.siteID = ? AND e.status = \'active\' '
             . (($pathwayId !== null) ? 'AND e.pathwayID = ? ' : '')
             . 'AND NOT EXISTS ('
             . '  SELECT 1 FROM tblPathwaySteps s '
             . '  WHERE s.pathwayID = e.pathwayID AND s.isOptional = 0 '
             . '    AND NOT EXISTS ('
             . '      SELECT 1 FROM tblPathwayProgress pr '
             . '      WHERE pr.stepID = s.stepID AND pr.userID = e.userID AND pr.revokedAt IS NULL'
             . '    )'
             . ')';
        $stmt = $db->prepare($sql);
        if ($stmt !== false) {
            if ($pathwayId !== null) {
                $stmt->bind_param('ii', $siteId, $pathwayId);
            } else {
                $stmt->bind_param('i', $siteId);
            }
            $stmt->execute();
            $stmt->close();
        }

        // 🔁 completed → active (a previously-required step got revoked)
        $sql = 'UPDATE tblPathwayEnrolments e '
             . 'SET e.status = \'active\', e.completedAt = NULL '
             . 'WHERE e.siteID = ? AND e.status = \'completed\' '
             . (($pathwayId !== null) ? 'AND e.pathwayID = ? ' : '')
             . 'AND EXISTS ('
             . '  SELECT 1 FROM tblPathwaySteps s '
             . '  WHERE s.pathwayID = e.pathwayID AND s.isOptional = 0 '
             . '    AND NOT EXISTS ('
             . '      SELECT 1 FROM tblPathwayProgress pr '
             . '      WHERE pr.stepID = s.stepID AND pr.userID = e.userID AND pr.revokedAt IS NULL'
             . '    )'
             . ')';
        $stmt = $db->prepare($sql);
        if ($stmt !== false) {
            if ($pathwayId !== null) {
                $stmt->bind_param('ii', $siteId, $pathwayId);
            } else {
                $stmt->bind_param('i', $siteId);
            }
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * 📋 Ordered step list for one pathway, LEFT JOINed to one user's
     * unrevoked progress row (if any). Used by the member-facing pathway
     * view and the admin per-member step editor.
     *
     * @param int $siteId    Active site — enforced via the pathway join.
     * @param int $pathwayId Pathway to list steps for.
     * @param int $userId    User whose progress to attach.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function progressFor(int $siteId, int $pathwayId, int $userId): array
    {
        $db = App::db();
        $rows = [];

        $stmt = $db->prepare(
            'SELECT s.stepID, s.sortOrder, s.name, s.description, s.completionHint, '
            . '       s.isOptional, s.autoRule, s.autoRefID, '
            . '       pr.progressID, pr.source, pr.autoRef, pr.notes, pr.completedAt, pr.markedByID '
            . 'FROM tblPathwaySteps s '
            . 'JOIN tblPathways p ON p.pathwayID = s.pathwayID AND p.siteID = ? '
            . 'LEFT JOIN tblPathwayProgress pr ON pr.stepID = s.stepID AND pr.userID = ? AND pr.revokedAt IS NULL '
            . 'WHERE s.pathwayID = ? '
            . 'ORDER BY s.sortOrder ASC, s.stepID ASC'
        );
        if ($stmt === false) {
            return $rows;
        }
        $stmt->bind_param('iii', $siteId, $userId, $pathwayId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * 👥 Per-pathway roster for the admin/pastor list view — one row per
     * enrolled member with completed/required step counts and the latest
     * completion timestamp. Deliberately a flat list, never a
     * members×steps matrix (house `<table>` ban; issue #303 decision 2).
     *
     * @param int $siteId    Active site.
     * @param int $pathwayId Pathway to build the roster for.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rosterStats(int $siteId, int $pathwayId): array
    {
        $db = App::db();
        $rows = [];

        $stmt = $db->prepare(
            'SELECT en.enrolmentID, en.userID, u.fullName, en.status, en.enrolledAt, en.completedAt, '
            . '  (SELECT COUNT(*) FROM tblPathwaySteps s2 '
            . '     WHERE s2.pathwayID = en.pathwayID AND s2.isOptional = 0) AS requiredCount, '
            . '  (SELECT COUNT(*) FROM tblPathwayProgress pr2 '
            . '     JOIN tblPathwaySteps s3 ON s3.stepID = pr2.stepID '
            . '     WHERE s3.pathwayID = en.pathwayID AND s3.isOptional = 0 '
            . '       AND pr2.userID = en.userID AND pr2.revokedAt IS NULL) AS completedCount, '
            . '  (SELECT MAX(pr3.completedAt) FROM tblPathwayProgress pr3 '
            . '     JOIN tblPathwaySteps s4 ON s4.stepID = pr3.stepID '
            . '     WHERE s4.pathwayID = en.pathwayID '
            . '       AND pr3.userID = en.userID AND pr3.revokedAt IS NULL) AS lastCompletedAt '
            . 'FROM tblPathwayEnrolments en '
            . 'JOIN tblUsers u ON u.userID = en.userID '
            . 'WHERE en.siteID = ? AND en.pathwayID = ? '
            . 'ORDER BY en.status ASC, u.fullName ASC'
        );
        if ($stmt === false) {
            return $rows;
        }
        $stmt->bind_param('ii', $siteId, $pathwayId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
