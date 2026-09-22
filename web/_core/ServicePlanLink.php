<?php
// Path: _core/ServicePlanLink.php
/**
 * -----------------------------------------------------------------------------
 * Service-Plan Bridge Resolver 🎶🔗 (gap #6, #442)
 * -----------------------------------------------------------------------------
 * The ONLY code in the codebase that knows about BOTH parallel "service plan"
 * data models:
 *
 *   Model A — the run-sheet builder (#262/#300): `tblServicePlan` (SINGULAR)
 *             + `tblServicePlanItem`. Login-only app, no coordinator ACL.
 *   Model B — the worship presentation engine (#308/#355): `tblServicePlans`
 *             (PLURAL) + `tblServicePlanItems`. admin-or-coordinator write ACL
 *             derived from `tblServicePlans.eventID`.
 *
 * Migration 154's header calls the two "unrelated" — that remains true of
 * their DATA (no field sync, no merge, ever — see class doc footer). This
 * class only resolves an OPTIONAL, EXPLICIT, additive 1:1 cross-reference:
 * `tblServicePlans.runSheetPlanID` (nullable, UNIQUE, FK ON DELETE SET NULL
 * → tblServicePlan.planID — migration 173). NULL = unpaired, the state of
 * every pre-existing row; nothing is migrated or backfilled in bulk.
 *
 * Pairing invariants (enforced here, not just in the caller — see pair()):
 *   1. Same site (hard) — every row loaded here is siteID-scoped; a foreign
 *      tenant's planID simply resolves to "not found", never a mismatch.
 *   2. Same event (hard, when knowable) — refuse when BOTH sides declare an
 *      eventID and they differ. Either side NULL (the common case — Model
 *      A's eventID is written by nobody today) always proceeds.
 *   3. 1:1 (hard) — a run-sheet already claimed by a DIFFERENT worship plan
 *      is refused with a friendly message; the UNIQUE key is the race
 *      backstop (errno 1062 caught below, never allowed to fatal).
 *   4. eventID backfill (soft, one-directional, worship → run-sheet ONLY,
 *      NULL-only) — wakes Model A's dormant `eventID` column when a paired
 *      worship plan already has one. NEVER the reverse: writing Model B's
 *      eventID is an ACL-bearing action (it decides who may edit the
 *      worship plan, see plan-save.php's `$gate` closure) and must only ever
 *      happen through that app's own explicit, authorised binding flow.
 *
 * ACL is NOT this class's job — every method here is a mechanism, not a
 * policy. The caller (web/_apps/worship/plan-link.php) is the ONLY place
 * that decides WHO may pair/unpair; it reuses the worship app's existing
 * admin-or-coordinator write gate verbatim.
 *
 * No field sync in v1 (Q4, decided): the two item representations are
 * structurally incompatible (free-text `title` in Model A vs a canonical
 * `songID` FK into `tblSongs` in Model B) — every method below is read-only
 * with respect to programme content. A one-shot, user-triggered "copy
 * sections → slides" action is a deliberate v2 candidate, not built here.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/442
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class ServicePlanLink
{
    /**
     * 🔎 Resolve the worship (presentation) plan paired to a run-sheet, if any.
     *
     * @param int $runPlanId tblServicePlan.planID (the run-sheet's own id)
     * @param int $siteId    Active site — every row is scoped to it
     *
     * @return array{planID:int,name:string,isActive:int,eventID:?int,eventName:?string,itemCount:int,songTitles:string[]}|null
     */
    public static function worshipPlanForRunSheet(int $runPlanId, int $siteId): ?array
    {
        $db = App::db();

        // 👁️ EventVisibility (#514 D5, fix round 1: checker finding 2b). This resolver's result
        // is shown on the run-sheet editor's paired-plan panel (service-plans/edit.php), reachable
        // by anyone who can edit a run-sheet — proven that, with a pairing made before this fix,
        // a HIDDEN imported event's name appeared there to an old-flag-only administrator the
        // visibility rule refuses the event itself to. The pairing (eventID) is untouched; only
        // the event's own NAME is hidden.
        $stmt = $db->prepare(
            'SELECT p.planID, p.name, p.isActive, p.eventID, e.eventName '
            . 'FROM tblServicePlans p '
            . 'LEFT JOIN tblEvents e ON e.eventID = p.eventID AND e.isDeleted = 0 AND e.externalFeedID IS NULL '
            . 'WHERE p.runSheetPlanID = ? AND p.siteID = ?'
        );
        $stmt->bind_param('ii', $runPlanId, $siteId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($plan === null) {
            return null;
        }

        $worshipPlanId = (int) $plan['planID'];

        // 🎞️ Slide count.
        $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM tblServicePlanItems WHERE planID = ?');
        $stmt->bind_param('i', $worshipPlanId);
        $stmt->execute();
        $plan['itemCount'] = (int) (($stmt->get_result()->fetch_assoc() ?: ['cnt' => 0])['cnt']);
        $stmt->close();

        // 🎵 Song titles (NULL join = the referenced song was deleted).
        $songTitles = [];
        $stmt = $db->prepare(
            'SELECT s.title FROM tblServicePlanItems i '
            . 'LEFT JOIN tblSongs s ON s.songID = i.songID '
            . "WHERE i.planID = ? AND i.itemType = 'song' ORDER BY i.sortOrder"
        );
        $stmt->bind_param('i', $worshipPlanId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $songTitles[] = $r['title'] !== null ? (string) $r['title'] : null;
        }
        $stmt->close();
        $plan['songTitles'] = $songTitles;

        return $plan;
    }

    /**
     * 🔎 Resolve the run-sheet paired to a worship plan, if any.
     *
     * @param int $worshipPlanId tblServicePlans.planID
     * @param int $siteId        Active site — every row is scoped to it
     *
     * @return array{planID:int,title:string,serviceDate:string,status:string,itemCount:int,songTitles:string[]}|null
     */
    public static function runSheetForWorshipPlan(int $worshipPlanId, int $siteId): ?array
    {
        $db = App::db();

        $stmt = $db->prepare(
            'SELECT r.planID, r.title, r.serviceDate, r.status '
            . 'FROM tblServicePlans p '
            . 'JOIN tblServicePlan r ON r.planID = p.runSheetPlanID '
            . 'WHERE p.planID = ? AND p.siteID = ? AND r.siteID = ?'
        );
        $stmt->bind_param('iii', $worshipPlanId, $siteId, $siteId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($plan === null) {
            return null;
        }

        $runPlanId = (int) $plan['planID'];

        // 📋 Section count.
        $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM tblServicePlanItem WHERE planID = ?');
        $stmt->bind_param('i', $runPlanId);
        $stmt->execute();
        $plan['itemCount'] = (int) (($stmt->get_result()->fetch_assoc() ?: ['cnt' => 0])['cnt']);
        $stmt->close();

        // 🎵 Song/hymn section titles (free-text — Model A has no songID FK).
        $songTitles = [];
        $stmt = $db->prepare(
            "SELECT title FROM tblServicePlanItem WHERE planID = ? AND sectionType = 'song' ORDER BY position"
        );
        $stmt->bind_param('i', $runPlanId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $songTitles[] = $r['title'] !== null ? (string) $r['title'] : null;
        }
        $stmt->close();
        $plan['songTitles'] = $songTitles;

        return $plan;
    }

    /**
     * 📋 Unpaired, active worship plans a run-sheet editor can offer as pairing
     * candidates (most-recently-updated first).
     *
     * @return array<int,array{planID:int,name:string,eventID:?int,eventName:?string,itemCount:int}>
     */
    public static function candidatesForRunSheet(int $siteId): array
    {
        $db = App::db();
        $out = [];

        // 👁️ EventVisibility (#514 D5, fix round 1: checker finding 2b). Same reasoning as
        // worshipPlanForRunSheet() above: this list offers pairing candidates to a run-sheet
        // editor, so an imported event's name must not appear here to somebody the visibility
        // rule would refuse that event to.
        $stmt = $db->prepare(
            'SELECT p.planID, p.name, p.eventID, e.eventName, '
            . '       (SELECT COUNT(*) FROM tblServicePlanItems i WHERE i.planID = p.planID) AS itemCount '
            . 'FROM tblServicePlans p '
            . 'LEFT JOIN tblEvents e ON e.eventID = p.eventID AND e.isDeleted = 0 AND e.externalFeedID IS NULL '
            . 'WHERE p.siteID = ? AND p.isActive = 1 AND p.runSheetPlanID IS NULL '
            . 'ORDER BY p.updatedAt DESC LIMIT 50'
        );
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $r['itemCount'] = (int) $r['itemCount'];
            $out[] = $r;
        }
        $stmt->close();

        return $out;
    }

    /**
     * 📋 Run-sheets not yet paired to any worship plan, offered as pairing
     * candidates to a worship-plan editor. Same-event matches (when the
     * worship plan already has one) sort first, then most recent service date.
     *
     * @param int      $siteId  Active site
     * @param int|null $eventId The worship plan's bound event, if any
     *
     * @return array<int,array{planID:int,title:string,serviceDate:string,status:string}>
     */
    public static function candidatesForWorshipPlan(int $siteId, ?int $eventId): array
    {
        $db = App::db();
        $out = [];
        $eventArg = $eventId ?? 0; // 0 never matches a real eventID (AUTO_INCREMENT starts at 1)

        $stmt = $db->prepare(
            'SELECT r.planID, r.title, r.serviceDate, r.status '
            . 'FROM tblServicePlan r '
            . 'WHERE r.siteID = ? '
            . 'AND NOT EXISTS (SELECT 1 FROM tblServicePlans p WHERE p.runSheetPlanID = r.planID) '
            . 'ORDER BY (r.eventID IS NOT NULL AND r.eventID = ?) DESC, r.serviceDate DESC LIMIT 50'
        );
        $stmt->bind_param('ii', $siteId, $eventArg);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $out[] = $r;
        }
        $stmt->close();

        return $out;
    }

    /**
     * 🔗 Pair a worship plan to a run-sheet. Mechanism only — the caller owns
     * the write-ACL decision (see class doc). Re-pairing the worship plan's
     * OWN existing link is allowed (overwrite); claiming a run-sheet already
     * paired to a DIFFERENT worship plan is refused (Q6, decided).
     *
     * @return array{ok:bool,error:?string}
     */
    public static function pair(int $worshipPlanId, int $runPlanId, int $siteId, int $userId): array
    {
        $db = App::db();

        // 🛡️ Site-scoped load of BOTH sides — a foreign-tenant id simply
        // resolves to "not found" here, never a cross-site mismatch.
        $stmt = $db->prepare('SELECT planID, eventID FROM tblServicePlans WHERE planID = ? AND siteID = ?');
        $stmt->bind_param('ii', $worshipPlanId, $siteId);
        $stmt->execute();
        $worship = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($worship === null) {
            return ['ok' => false, 'error' => 'Worship plan not found.'];
        }

        $stmt = $db->prepare('SELECT planID, eventID FROM tblServicePlan WHERE planID = ? AND siteID = ?');
        $stmt->bind_param('ii', $runPlanId, $siteId);
        $stmt->execute();
        $runSheet = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($runSheet === null) {
            return ['ok' => false, 'error' => 'Run-sheet plan not found.'];
        }

        // 🛡️ Invariant 2 — refuse only when BOTH sides declare an event AND
        // they differ. Either side NULL always proceeds.
        if ($worship['eventID'] !== null && $runSheet['eventID'] !== null
            && (int) $worship['eventID'] !== (int) $runSheet['eventID']) {
            return ['ok' => false, 'error' => 'These plans are bound to different events.'];
        }

        // 🛡️ Invariant 3 (pre-check) — the run-sheet is already claimed by a
        // DIFFERENT worship plan. Re-pairing the SAME worship plan to the
        // same run-sheet (or overwriting its own prior pairing) is allowed.
        $stmt = $db->prepare('SELECT planID, name FROM tblServicePlans WHERE runSheetPlanID = ? AND siteID = ?');
        $stmt->bind_param('ii', $runPlanId, $siteId);
        $stmt->execute();
        $claimant = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($claimant !== null && (int) $claimant['planID'] !== $worshipPlanId) {
            return [
                'ok'    => false,
                'error' => 'That run-sheet is already linked to worship plan "' . (string) $claimant['name'] . '".',
            ];
        }

        // 🔗 Perform the pair. The UNIQUE key (uq_plans_runsheet) is the race
        // backstop for two concurrent pair attempts on the same run-sheet —
        // caught here as a friendly refusal, never a fatal 500.
        try {
            $stmt = $db->prepare('UPDATE tblServicePlans SET runSheetPlanID = ? WHERE planID = ? AND siteID = ?');
            $stmt->bind_param('iii', $runPlanId, $worshipPlanId, $siteId);
            $stmt->execute();
            $stmt->close();
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                return ['ok' => false, 'error' => 'That run-sheet was just linked to another worship plan.'];
            }
            throw $e;
        }

        // ➕ Invariant 4 — NULL-only, one-directional eventID backfill
        // (worship → run-sheet). Never the reverse (see class doc).
        if ($runSheet['eventID'] === null && $worship['eventID'] !== null) {
            $backfillEventId = (int) $worship['eventID'];
            $stmt = $db->prepare(
                'UPDATE tblServicePlan SET eventID = ? WHERE planID = ? AND siteID = ? AND eventID IS NULL'
            );
            $stmt->bind_param('iii', $backfillEventId, $runPlanId, $siteId);
            $stmt->execute();
            $stmt->close();
        }

        Logger::activity(
            'ServicePlanLinked',
            'Worship plan #' . $worshipPlanId . ' linked to run-sheet #' . $runPlanId,
            $userId
        );

        return ['ok' => true, 'error' => null];
    }

    /**
     * 🔓 Unpair a worship plan from its run-sheet (a no-op success if it was
     * already unpaired). Never touches the run-sheet row — a prior eventID
     * backfill (§ pair() invariant 4) stays; it's true information.
     */
    public static function unpair(int $worshipPlanId, int $siteId, int $userId): bool
    {
        $db = App::db();

        $stmt = $db->prepare('UPDATE tblServicePlans SET runSheetPlanID = NULL WHERE planID = ? AND siteID = ?');
        $stmt->bind_param('ii', $worshipPlanId, $siteId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            Logger::activity('ServicePlanUnlinked', 'Worship plan #' . $worshipPlanId . ' unlinked', $userId);
        }

        return true;
    }
}
