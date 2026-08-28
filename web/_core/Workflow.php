<?php
// Path: _core/Workflow.php
/**
 * -----------------------------------------------------------------------------
 * Workflow Execution Engine 🔄 (#443)
 * -----------------------------------------------------------------------------
 * Migration 034 shipped four workflow tables (tblWorkflows, tblWorkflowSteps,
 * tblWorkflowInstances, tblWorkflowActions) and an admin definition CRUD, but
 * nothing ever started, advanced, completed, or timed out an instance. This
 * class is that engine.
 *
 * A *definition* (tblWorkflows row + ordered tblWorkflowSteps) instantiates
 * into a tblWorkflowInstances row pointing at a subject record ($tableName +
 * $recordID), walks `currentStep` (a stepOrder value, NOT a stepID) through
 * the step sequence, records one tblWorkflowActions row per decision, and
 * terminates with status='completed'|'cancelled' + an `outcome`
 * ('approved'|'rejected'|'cancelled', migration 174).
 *
 * PUBLIC API (the only entry points into the state machine — every guard
 * lives inside these four mutators, so no caller can skip one):
 *   start($definitionKey, $subjectTable, $subjectId, $startedById, $label, $context)
 *   act($instanceId, $stepId, $actorId, $decision, $comment)
 *   cancelForSubject($subjectTable, $subjectId, $reason, $actorId)
 *   timeoutSweep($siteId)
 * Read-only inbox helpers:
 *   actionableForUser($userId, $siteId, $isAdmin)
 *   historyForSite($siteId, $userId, $isAdmin, $limit)
 *   actionsForInstance($instanceId, $siteId)
 *   activeInstanceForSubject($subjectTable, $subjectId)
 *   contextUrl($instance)
 *
 * ATOMICITY (Payments::markPaymentSucceeded + expenses/approve/save.php
 * discipline): every state change runs inside begin_transaction()/commit(),
 * re-loads + FOR UPDATE locks the instance row, resolves the CURRENT step
 * fresh, and claims the transition with a single
 * `UPDATE … WHERE instanceID=? AND currentStep=? AND status IN (…)` gated on
 * affected_rows===1 BEFORE recording the action row or applying any subject
 * side effect. A losing racer (affected_rows 0) does nothing and reports
 * 'conflict'. The posted stepID passed to act() must equal the server-
 * resolved current step (stale-form guard) — a mismatch is 'stale_step',
 * never silently accepted.
 *
 * AUTHORISATION lives INSIDE act() (never trusted from the HTTP layer): the
 * actor must be authorised for the CURRENT step (role/user/group per the
 * step schema) AND an active tblUserSites member of the instance's site,
 * OR a site admin when workflows.admin_override='1' (default on). A foreign
 * (cross-tenant) instanceID is deliberately indistinguishable from a missing
 * one — both return 'not_found' — no enumeration signal.
 *
 * SUBJECT-ADAPTER REGISTRY (§3.7 of the plan): applySubjectEffect() is a
 * single `match` on workflowKey with one arm per wired consumer. v1 ships
 * exactly one arm — 'announcement_publish' flips tblAnnouncements.isPublished
 * INSIDE the same transaction as the final approval claim, so there is no
 * double-publish and no approved-but-never-published ghost. Adding consumer
 * #2 later means one new arm here + one start() call at that consumer's
 * submit point + one enable flag — no other engine changes.
 *
 * DUPLICATE stepOrder EDGE: tblWorkflowSteps has no unique key on
 * (workflowID, stepOrder) — the admin CRUD computes MAX+1 so duplicates only
 * arise from a double-submit race. The engine resolves "the current step"
 * and "the next step" deterministically everywhere with
 * `ORDER BY stepOrder ASC, stepID ASC LIMIT 1` / `stepOrder > ? ORDER BY …
 * LIMIT 1` — duplicates run sequentially by stepID, never fan out.
 *
 * TIMEOUT POLICY: escalate-only unless a step explicitly sets
 * autoAction='approve'|'reject' — the engine NEVER auto-acts on a bare
 * timeout. Escalation is deduped per (instanceID, stepID) via a dedicated
 * `escalated` action row, itself inserted under the SAME FOR UPDATE lock
 * used for a real transition, so two overlapping sweeps can't both escalate.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/443
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;
use Throwable;

class Workflow
{
    /** Active (non-terminal) instance statuses — used in nearly every WHERE clause below. */
    private const ACTIVE_STATUSES = ['pending', 'in_progress'];

    /** Hard cap on the notification/auto step walk in processAutoSteps() — belt-and-braces against a cyclic definition. */
    private const MAX_AUTO_STEPS = 25;

    /* ========================================================================= */
    /* 🚦 PUBLIC MUTATORS                                                        */
    /* ========================================================================= */

    /**
     * Start a new instance of $definitionKey's active definition (for the
     * CURRENT site, Site::id()) against a subject record. Never throws to
     * the caller — every failure path returns null so a consumer can apply
     * its own fallback policy (e.g. fail-open direct publish).
     *
     * Returns null when: no active definition exists for this site/key, the
     * definition has zero steps (e.g. the dormant seeded expense_approval),
     * or an active instance already exists for this exact subject row
     * (duplicate-active guard, race-safe via a single conditional INSERT).
     *
     * @param array<string, mixed> $context Stored as contextJson (e.g. ['url' => '/announcements/view?...']).
     */
    public static function start(
        string $definitionKey,
        string $subjectTable,
        int $subjectId,
        int $startedById,
        string $subjectLabel,
        array $context = []
    ): ?int {
        try {
            $db = App::db();
            $siteId = Site::id();

            // 1️⃣ Resolve the active definition for this site.
            $stmt = $db->prepare(
                'SELECT workflowID FROM tblWorkflows WHERE workflowKey = ? AND siteID = ? AND isActive = 1 LIMIT 1'
            );
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('si', $definitionKey, $siteId);
            $stmt->execute();
            $wf = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($wf === null) {
                return null;
            }
            $workflowId = (int) $wf['workflowID'];

            // 2️⃣ Resolve the first step (zero-step definition ⇒ null).
            $firstStep = self::firstStep($workflowId);
            if ($firstStep === null) {
                return null;
            }
            $firstOrder = (int) $firstStep['stepOrder'];

            $label = mb_substr($subjectLabel, 0, 255);
            $contextJson = count($context) > 0 ? json_encode($context, JSON_UNESCAPED_SLASHES) : null;

            // 3️⃣ Duplicate-active guard, race-safe: single conditional INSERT
            //    gated on affected_rows===1 (Payments::markPaymentSucceeded
            //    discipline). Under InnoDB the NOT-EXISTS scan via
            //    idx_wfi_record takes the locks that serialise two
            //    concurrent starts for the same subject.
            $stmt = $db->prepare(
                'INSERT INTO tblWorkflowInstances '
                . '(workflowID, siteID, tableName, recordID, currentStep, status, startedByID, currentStepStartedAt, subjectLabel, contextJson) '
                . "SELECT ?, ?, ?, ?, ?, 'pending', ?, NOW(), ?, ? FROM DUAL WHERE NOT EXISTS ("
                . "SELECT 1 FROM tblWorkflowInstances WHERE tableName = ? AND recordID = ? AND status IN ('pending','in_progress'))"
            );
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param(
                'iisiiisssi',
                $workflowId,
                $siteId,
                $subjectTable,
                $subjectId,
                $firstOrder,
                $startedById,
                $label,
                $contextJson,
                $subjectTable,
                $subjectId
            );
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected !== 1) {
                // An active instance already exists for this subject.
                return null;
            }
            $instanceId = (int) $db->insert_id;

            Logger::activity(
                'WorkflowStarted',
                'Started "' . $definitionKey . '" for ' . $subjectTable . '#' . $subjectId . ' (instance #' . $instanceId . ')',
                $startedById
            );
            Logger::audit(
                'tblWorkflowInstances',
                $instanceId,
                'create',
                null,
                ['workflowID' => $workflowId, 'tableName' => $subjectTable, 'recordID' => $subjectId, 'status' => 'pending'],
                $startedById
            );

            // 4️⃣-5️⃣ Auto-process any leading notification/auto steps, then
            //    notify step-1 assignees once the instance settles on a
            //    human-facing (approval/review) step.
            self::processAutoSteps($instanceId);

            return $instanceId;
        } catch (Throwable $e) {
            Logger::exception($e);
            return null;
        }
    }

    /**
     * Record a decision on the CURRENT step of an instance. $decision is one
     * of 'approve' | 'reject' | 'comment'. Authorisation, the stale-form
     * guard, and the atomic claim all live here — the caller (act.php) is
     * transport only.
     *
     * @return array{ok: bool, error: ?string, completed: bool, outcome: ?string}
     *         errors: not_found (also cross-site — deliberately
     *         indistinguishable), not_active, stale_step, forbidden,
     *         conflict, invalid (malformed decision/comment).
     */
    public static function act(int $instanceId, int $stepId, int $actorId, string $decision, string $comment = ''): array
    {
        if (in_array($decision, ['approve', 'reject', 'comment'], true) === false) {
            return self::result(false, 'invalid');
        }
        $comment = trim($comment);
        // 🛡️ Reject and comment-only decisions must carry a reason — an
        // outsider must not write a blank decision into the audit trail,
        // and a rejection with no explanation is unreviewable later.
        if (($decision === 'comment' || $decision === 'reject') && $comment === '') {
            return self::result(false, 'invalid');
        }

        $db = App::db();
        $siteId = Site::id();
        $instance = null;
        $step = null;
        $txResult = null;

        $db->begin_transaction();
        try {
            // 1️⃣ Load + lock, site-scoped — the IDOR wall. A foreign
            //    instanceID behaves exactly like a nonexistent one.
            $instance = self::loadLockedInstance($db, $instanceId, $siteId);
            if ($instance === null) {
                $db->rollback();
                return self::result(false, 'not_found');
            }

            // 2️⃣ Status guard.
            if (in_array($instance['status'], self::ACTIVE_STATUSES, true) === false) {
                $db->rollback();
                return self::result(false, 'not_active');
            }

            // 3️⃣ Current-step resolution + stale-form guard.
            $step = self::resolveStepByOrder((int) $instance['workflowID'], (int) $instance['currentStep']);
            if ($step === null || (int) $step['stepID'] !== $stepId) {
                $db->rollback();
                return self::result(false, 'stale_step');
            }

            // 4️⃣ Authorisation — INSIDE the lock, never trusted from the HTTP layer.
            if (self::canAct($step, $actorId, (int) $instance['siteID']) === false) {
                $db->rollback();
                return self::result(false, 'forbidden');
            }

            // 5️⃣ Comment short-circuit — never advances state.
            if ($decision === 'comment') {
                self::recordAction($db, $instanceId, (int) $step['stepID'], 'commented', $comment, $actorId);
                $db->commit();
                Logger::activity('WorkflowCommented', 'Commented on instance #' . $instanceId, $actorId);
                return ['ok' => true, 'error' => null, 'completed' => false, 'outcome' => null];
            }

            // 6️⃣-8️⃣ Atomic claim + action row + (on terminal outcome) the
            //    subject-adapter side effect — all inside this transaction.
            $word = $decision === 'reject' ? 'rejected' : 'approved';
            $txResult = self::claimTransition($db, $instance, $step, $word, $word, $actorId, $comment);
            if ($txResult['ok'] === false) {
                $db->rollback();
                return $txResult;
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            Logger::exception($e);
            return self::result(false, 'error');
        }

        // 9️⃣ Post-commit: audit + notifications. Never re-enters the txn.
        Logger::activity(
            'WorkflowActioned',
            ucfirst($decision) . 'd step "' . $step['stepName'] . '" on instance #' . $instanceId,
            $actorId
        );
        Logger::audit(
            'tblWorkflowInstances',
            $instanceId,
            'update',
            ['status' => $instance['status'], 'outcome' => null],
            ['status' => $txResult['completed'] === true ? 'completed' : 'in_progress', 'outcome' => $txResult['outcome']],
            $actorId
        );

        if ($txResult['completed'] === true) {
            Logger::activity('WorkflowCompleted', 'Instance #' . $instanceId . ' completed: ' . $txResult['outcome'], $actorId);
            self::notifyOutcome($instance, (string) $txResult['outcome']);
            self::emitCompletedWebhook($instance, (string) $txResult['outcome']);
        } else {
            self::processAutoSteps($instanceId);
        }

        return $txResult;
    }

    /**
     * Cancel any active instance bound to a subject row (consumer delete/
     * withdraw hooks). Safe no-op when none exists. Returns count cancelled
     * (0 or 1 — a subject can only ever have one active instance at a time,
     * enforced by start()'s own duplicate-active guard).
     */
    public static function cancelForSubject(string $subjectTable, int $subjectId, string $reason, ?int $actorId = null): int
    {
        $db = App::db();
        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                'SELECT i.instanceID, i.workflowID, i.siteID, i.tableName, i.recordID, i.currentStep, i.status, '
                . 'i.subjectLabel, i.contextJson, i.startedByID, w.workflowKey, w.workflowName '
                . 'FROM tblWorkflowInstances i JOIN tblWorkflows w ON w.workflowID = i.workflowID '
                . "WHERE i.tableName = ? AND i.recordID = ? AND i.status IN ('pending','in_progress') FOR UPDATE"
            );
            if ($stmt === false) {
                $db->rollback();
                return 0;
            }
            $stmt->bind_param('si', $subjectTable, $subjectId);
            $stmt->execute();
            $instance = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($instance === null) {
                $db->rollback();
                return 0;
            }

            $instanceId = (int) $instance['instanceID'];
            $currentStepOrder = (int) $instance['currentStep'];

            $upd = $db->prepare(
                "UPDATE tblWorkflowInstances SET status = 'cancelled', outcome = 'cancelled', completedAt = NOW() "
                . "WHERE instanceID = ? AND status IN ('pending','in_progress')"
            );
            if ($upd === false) {
                $db->rollback();
                return 0;
            }
            $upd->bind_param('i', $instanceId);
            $upd->execute();
            $claimed = ($upd->affected_rows === 1);
            $upd->close();
            if ($claimed === false) {
                $db->rollback();
                return 0;
            }

            // Action-row FK is RESTRICT on stepID — must reference a real step.
            $step = self::resolveStepByOrder((int) $instance['workflowID'], $currentStepOrder);
            if ($step !== null) {
                self::recordAction($db, $instanceId, (int) $step['stepID'], 'skipped', 'Cancelled: ' . $reason, $actorId);
            }

            // Adapter no-op for announcement_publish (rejected/cancelled ⇒ no subject write).
            self::applySubjectEffect($db, $instance, 'cancelled', $actorId);

            $db->commit();

            Logger::activity('WorkflowCancelled', 'Instance #' . $instanceId . ' cancelled: ' . $reason, $actorId);
            Logger::audit(
                'tblWorkflowInstances',
                $instanceId,
                'update',
                ['status' => $instance['status']],
                ['status' => 'cancelled'],
                $actorId
            );

            return 1;
        } catch (Throwable $e) {
            $db->rollback();
            Logger::exception($e);
            return 0;
        }
    }

    /**
     * Timeout sweep for ONE site — called by cron/workflow-timeouts.php
     * inside its per-site Site::forceContext() loop. NEVER auto-acts unless
     * a step explicitly sets autoAction='approve'|'reject'; a NULL or
     * 'escalate' autoAction only escalates (notify + keep waiting) and is
     * deduped per (instanceID, stepID).
     *
     * @return array{checked: int, auto_acted: int, escalated: int}
     */
    public static function timeoutSweep(int $siteId): array
    {
        $db = App::db();
        $summary = ['checked' => 0, 'auto_acted' => 0, 'escalated' => 0];

        $stmt = $db->prepare(
            'SELECT i.instanceID, s.stepID, s.timeoutHours, s.autoAction '
            . 'FROM tblWorkflowInstances i '
            . 'JOIN tblWorkflowSteps s ON s.workflowID = i.workflowID AND s.stepOrder = i.currentStep '
            . "WHERE i.siteID = ? AND i.status IN ('pending','in_progress') "
            . 'AND s.timeoutHours IS NOT NULL '
            . 'AND i.currentStepStartedAt <= DATE_SUB(NOW(), INTERVAL s.timeoutHours HOUR) '
            . 'ORDER BY i.instanceID ASC, s.stepID ASC'
        );
        if ($stmt === false) {
            return $summary;
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();

        // 🪞 Collapse duplicate-stepOrder fan-out — first stepID (by the
        // ORDER BY above) wins per instance, mirroring §3.6 everywhere else.
        $seen = [];
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $iid = (int) $row['instanceID'];
            if (isset($seen[$iid]) === true) {
                continue;
            }
            $seen[$iid] = true;
            $rows[] = $row;
        }
        $stmt->close();

        foreach ($rows as $row) {
            $summary['checked']++;
            $instanceId = (int) $row['instanceID'];
            $stepId = (int) $row['stepID'];
            $autoAction = $row['autoAction'];
            $hours = (int) $row['timeoutHours'];

            if ($autoAction === 'approve' || $autoAction === 'reject') {
                $txResult = self::timeoutAutoAct($db, $instanceId, $siteId, $stepId, (string) $autoAction, $hours);
                if ($txResult === null) {
                    continue; // lost race, definition moved on, or error — skip
                }
                $summary['auto_acted']++;
                continue;
            }

            // escalate or NULL — dedupe per (instance, step), locked so two
            // overlapping sweeps can't both escalate the same step.
            $escalated = self::timeoutEscalate($db, $instanceId, $siteId, $stepId, $hours);
            if ($escalated === true) {
                $summary['escalated']++;
            }
        }

        return $summary;
    }

    /* ========================================================================= */
    /* 📥 READ-ONLY INBOX HELPERS                                                */
    /* ========================================================================= */

    /**
     * Active site instances the given user may currently act on (admins see
     * every active instance for the site). Collapses duplicate-stepOrder
     * fan-out to the first stepID per instance.
     *
     * @return list<array<string, mixed>>
     */
    public static function actionableForUser(int $userId, int $siteId, bool $isAdmin): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT i.instanceID, i.subjectLabel, i.contextJson, i.startedAt, i.currentStepStartedAt, '
            . 'i.startedByID, u.fullName AS startedByName, w.workflowName, s.stepID, s.stepName, '
            . 's.stepOrder, s.timeoutHours, s.assigneeType, s.assigneeValue '
            . 'FROM tblWorkflowInstances i '
            . 'JOIN tblWorkflows w ON w.workflowID = i.workflowID '
            . 'JOIN tblWorkflowSteps s ON s.workflowID = i.workflowID AND s.stepOrder = i.currentStep '
            . 'LEFT JOIN tblUsers u ON u.userID = i.startedByID '
            . "WHERE i.siteID = ? AND i.status IN ('pending','in_progress') "
            . 'ORDER BY i.currentStepStartedAt ASC, i.instanceID ASC, s.stepID ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();

        $roleKeys = $isAdmin === true ? [] : self::userRoleKeys($db, $userId);
        $groupIds = $isAdmin === true ? [] : self::userGroupIds($db, $userId);

        $seen = [];
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $iid = (int) $row['instanceID'];
            if (isset($seen[$iid]) === true) {
                continue;
            }
            $seen[$iid] = true;

            if ($isAdmin === true) {
                $rows[] = $row;
                continue;
            }

            $atype = (string) $row['assigneeType'];
            $aval = (string) ($row['assigneeValue'] ?? '');
            $isActionable = false;
            if ($atype === 'user' && $aval !== '' && (int) $aval === $userId) {
                $isActionable = true;
            } elseif ($atype === 'role' && in_array($aval, $roleKeys, true) === true) {
                $isActionable = true;
            } elseif ($atype === 'group' && $aval !== '' && in_array((int) $aval, $groupIds, true) === true) {
                $isActionable = true;
            }

            if ($isActionable === true) {
                $rows[] = $row;
            }
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Last $limit completed/cancelled instances for the site. Non-admins see
     * only instances they started or acted on.
     *
     * @return list<array<string, mixed>>
     */
    public static function historyForSite(int $siteId, ?int $userId, bool $isAdmin, int $limit = 50): array
    {
        $db = App::db();
        $limit = max(1, min(200, $limit));

        if ($isAdmin === true || $userId === null) {
            $stmt = $db->prepare(
                'SELECT i.instanceID, i.subjectLabel, i.startedAt, i.completedAt, i.outcome, i.status, '
                . 'w.workflowName, u.fullName AS startedByName '
                . 'FROM tblWorkflowInstances i '
                . 'JOIN tblWorkflows w ON w.workflowID = i.workflowID '
                . 'LEFT JOIN tblUsers u ON u.userID = i.startedByID '
                . "WHERE i.siteID = ? AND i.status IN ('completed','cancelled') "
                . 'ORDER BY i.completedAt DESC LIMIT ?'
            );
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param('ii', $siteId, $limit);
        } else {
            $stmt = $db->prepare(
                'SELECT DISTINCT i.instanceID, i.subjectLabel, i.startedAt, i.completedAt, i.outcome, i.status, '
                . 'w.workflowName, u.fullName AS startedByName '
                . 'FROM tblWorkflowInstances i '
                . 'JOIN tblWorkflows w ON w.workflowID = i.workflowID '
                . 'LEFT JOIN tblUsers u ON u.userID = i.startedByID '
                . 'LEFT JOIN tblWorkflowActions a ON a.instanceID = i.instanceID AND a.actedByID = ? '
                . "WHERE i.siteID = ? AND i.status IN ('completed','cancelled') "
                . 'AND (i.startedByID = ? OR a.actionID IS NOT NULL) '
                . 'ORDER BY i.completedAt DESC LIMIT ?'
            );
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param('iiii', $userId, $siteId, $userId, $limit);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Full decision timeline for one instance. Site-scope is re-checked via
     * the join — never trust a bare instanceID from the caller.
     *
     * @return list<array<string, mixed>>
     */
    public static function actionsForInstance(int $instanceId, int $siteId): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT a.actionID, a.stepID, a.action, a.comment, a.actedByID, a.actedAt, '
            . 's.stepName, u.fullName AS actorName '
            . 'FROM tblWorkflowActions a '
            . 'JOIN tblWorkflowInstances i ON i.instanceID = a.instanceID '
            . 'JOIN tblWorkflowSteps s ON s.stepID = a.stepID '
            . 'LEFT JOIN tblUsers u ON u.userID = a.actedByID '
            . 'WHERE a.instanceID = ? AND i.siteID = ? '
            . 'ORDER BY a.actedAt ASC, a.actionID ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $instanceId, $siteId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * The active instance (if any) bound to a subject row, site-unscoped by
     * design (subject tables carry their own siteID that the caller already
     * trusts — mirrors how tableName+recordID is the sole lookup key
     * everywhere else in this class).
     */
    public static function activeInstanceForSubject(string $subjectTable, int $subjectId): ?array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT instanceID, status, currentStep, startedAt FROM tblWorkflowInstances '
            . "WHERE tableName = ? AND recordID = ? AND status IN ('pending','in_progress') LIMIT 1"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('si', $subjectTable, $subjectId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    /**
     * Decode an instance's contextJson['url'] for inbox "view" links. Never
     * builds a consumer URL itself — that stays the consumer's job at
     * start() time.
     */
    public static function contextUrl(array $instance): string
    {
        $json = $instance['contextJson'] ?? null;
        if ($json === null || $json === '') {
            return '';
        }
        $data = json_decode((string) $json, true);
        if (is_array($data) === false) {
            return '';
        }
        return (string) ($data['url'] ?? '');
    }

    /* ========================================================================= */
    /* 🔒 PRIVATE — timeout-sweep-per-instance helpers                          */
    /* ========================================================================= */

    /**
     * One instance's system-driven auto-approve/auto-reject on timeout.
     * Returns the transition result, or null when the claim was lost /
     * the instance already moved on / a definition step disappeared.
     */
    private static function timeoutAutoAct(mysqli $db, int $instanceId, int $siteId, int $stepId, string $autoAction, int $hours): ?array
    {
        $db->begin_transaction();
        try {
            $instance = self::loadLockedInstance($db, $instanceId, $siteId);
            if ($instance === null || in_array($instance['status'], self::ACTIVE_STATUSES, true) === false) {
                $db->rollback();
                return null;
            }
            $step = self::resolveStepByOrder((int) $instance['workflowID'], (int) $instance['currentStep']);
            if ($step === null || (int) $step['stepID'] !== $stepId) {
                $db->rollback();
                return null; // definition or currentStep moved between the sweep's SELECT and this lock
            }
            $word = $autoAction === 'approve' ? 'approved' : 'rejected';
            $note = 'Auto-' . $word . ' after ' . $hours . 'h timeout';
            $txResult = self::claimTransition($db, $instance, $step, $word, $word, null, $note);
            if ($txResult['ok'] === false) {
                $db->rollback();
                return null;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            Logger::exception($e);
            return null;
        }

        Logger::activity('WorkflowTimeoutAutoAct', 'Instance #' . $instanceId . ' auto-' . $autoAction . 'd after ' . $hours . 'h timeout');

        if ($txResult['completed'] === true) {
            self::notifyOutcome($instance, (string) $txResult['outcome']);
            self::emitCompletedWebhook($instance, (string) $txResult['outcome']);
        } else {
            self::processAutoSteps($instanceId);
        }

        return $txResult;
    }

    /**
     * One instance's escalation-on-timeout — locked + deduped so two
     * overlapping sweeps can never double-escalate the same step. Never
     * changes status/currentStep — the instance stays active awaiting a
     * human decision (fail-safe default per #443 open question 2).
     */
    private static function timeoutEscalate(mysqli $db, int $instanceId, int $siteId, int $stepId, int $hours): bool
    {
        $db->begin_transaction();
        try {
            $instance = self::loadLockedInstance($db, $instanceId, $siteId);
            if ($instance === null || in_array($instance['status'], self::ACTIVE_STATUSES, true) === false) {
                $db->rollback();
                return false;
            }
            $step = self::resolveStepByOrder((int) $instance['workflowID'], (int) $instance['currentStep']);
            if ($step === null || (int) $step['stepID'] !== $stepId) {
                $db->rollback();
                return false;
            }
            if (self::hasEscalated($db, $instanceId, $stepId) === true) {
                $db->rollback();
                return false;
            }
            self::recordAction($db, $instanceId, $stepId, 'escalated', 'Escalated after ' . $hours . 'h with no decision', null);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            Logger::exception($e);
            return false;
        }

        Logger::activity('WorkflowEscalated', 'Instance #' . $instanceId . ' escalated after ' . $hours . 'h timeout');
        self::escalateNotify($db, $siteId, $instance, $step);
        return true;
    }

    /* ========================================================================= */
    /* 🔒 PRIVATE — the atomic claim mechanic + notification/auto step walk     */
    /* ========================================================================= */

    /**
     * The atomic claim (§3.4 step 6): advances to the next step, or — when
     * there is none — marks the instance completed with $terminalOutcome and
     * applies the subject-adapter side effect INSIDE the same caller-owned
     * transaction. $actionWord is what lands in tblWorkflowActions
     * ('approved'|'rejected'|'skipped'); $terminalOutcome is what
     * tblWorkflowInstances.outcome gets set to IF this call completes the
     * instance. Caller owns begin_transaction()/commit()/rollback().
     *
     * @return array{ok: bool, error: ?string, completed: bool, outcome: ?string}
     */
    private static function claimTransition(
        mysqli $db,
        array $instance,
        array $step,
        string $actionWord,
        string $terminalOutcome,
        ?int $actorId,
        string $comment
    ): array {
        $instanceId = (int) $instance['instanceID'];
        $currentStepOrder = (int) $instance['currentStep'];
        $nextStep = self::resolveNextStep((int) $instance['workflowID'], $currentStepOrder);

        if ($nextStep !== null) {
            $newStepOrder = (int) $nextStep['stepOrder'];
            $upd = $db->prepare(
                "UPDATE tblWorkflowInstances SET status = 'in_progress', currentStep = ?, currentStepStartedAt = NOW() "
                . "WHERE instanceID = ? AND currentStep = ? AND status IN ('pending','in_progress')"
            );
            if ($upd === false) {
                return self::result(false, 'conflict');
            }
            $upd->bind_param('iii', $newStepOrder, $instanceId, $currentStepOrder);
            $upd->execute();
            $claimed = ($upd->affected_rows === 1);
            $upd->close();
            if ($claimed === false) {
                return self::result(false, 'conflict');
            }
            self::recordAction($db, $instanceId, (int) $step['stepID'], $actionWord, $comment, $actorId);
            return ['ok' => true, 'error' => null, 'completed' => false, 'outcome' => null];
        }

        // Final step — completes the instance.
        $upd = $db->prepare(
            "UPDATE tblWorkflowInstances SET status = 'completed', outcome = ?, completedAt = NOW() "
            . "WHERE instanceID = ? AND currentStep = ? AND status IN ('pending','in_progress')"
        );
        if ($upd === false) {
            return self::result(false, 'conflict');
        }
        $upd->bind_param('sii', $terminalOutcome, $instanceId, $currentStepOrder);
        $upd->execute();
        $claimed = ($upd->affected_rows === 1);
        $upd->close();
        if ($claimed === false) {
            return self::result(false, 'conflict');
        }

        self::recordAction($db, $instanceId, (int) $step['stepID'], $actionWord, $comment, $actorId);

        // 🚀 Terminal side effect INSIDE the same transaction — approval and
        // publish commit or fail together, never "approved but unpublished".
        self::applySubjectEffect($db, $instance, $terminalOutcome, $actorId);

        return ['ok' => true, 'error' => null, 'completed' => true, 'outcome' => $terminalOutcome];
    }

    /**
     * Run claimTransition() inside its own fresh transaction — used by
     * processAutoSteps() for each notification/auto step it walks.
     *
     * @return array{ok: bool, error: ?string, completed: bool, outcome: ?string}|null
     *         null only on an unexpected exception (already logged).
     */
    private static function runClaim(
        mysqli $db,
        array $instance,
        array $step,
        string $actionWord,
        string $terminalOutcome,
        ?int $actorId,
        string $comment
    ): ?array {
        $db->begin_transaction();
        try {
            $result = self::claimTransition($db, $instance, $step, $actionWord, $terminalOutcome, $actorId, $comment);
            if ($result['ok'] === false) {
                $db->rollback();
                return $result;
            }
            $db->commit();
            return $result;
        } catch (Throwable $e) {
            $db->rollback();
            Logger::exception($e);
            return null;
        }
    }

    /**
     * Walk any leading notification/auto steps after start() or a non-
     * terminal act(), stopping (and notifying) as soon as the current step
     * needs a human, or the instance completes. 'review' behaves identically
     * to 'approval' in v1 (both block awaiting a human) — the enum
     * distinction is reserved for a future phase.
     */
    private static function processAutoSteps(int $instanceId): void
    {
        $db = App::db();
        for ($guard = 0; $guard < self::MAX_AUTO_STEPS; $guard++) {
            $instance = self::loadInstance($db, $instanceId);
            if ($instance === null || in_array($instance['status'], ['completed', 'cancelled'], true) === true) {
                return;
            }

            $step = self::resolveStepByOrder((int) $instance['workflowID'], (int) $instance['currentStep']);
            if ($step === null) {
                return;
            }

            $stepType = (string) $step['stepType'];

            if ($stepType === 'notification') {
                self::sendFyiNotice($instance, $step);
                $txResult = self::runClaim($db, $instance, $step, 'skipped', 'approved', null, 'Notification-only step — auto-advanced');
                if ($txResult === null || $txResult['ok'] === false) {
                    return; // lost race — another run/actor is already handling this instance
                }
                if ($txResult['completed'] === true) {
                    self::notifyOutcome($instance, (string) $txResult['outcome']);
                    self::emitCompletedWebhook($instance, (string) $txResult['outcome']);
                    return;
                }
                continue;
            }

            if ($stepType === 'auto') {
                $autoAction = $step['autoAction'];
                if ($autoAction === 'approve' || $autoAction === 'reject') {
                    $word = $autoAction === 'approve' ? 'approved' : 'rejected';
                    $txResult = self::runClaim($db, $instance, $step, $word, $word, null, 'Auto-' . $word . ' (auto step)');
                } else {
                    // 🛡️ Definition error — an 'auto' step with no
                    // actionable autoAction. Never silently treat this as an
                    // approval; skip past it and flag it for an admin.
                    Logger::errorPlatform(
                        'Workflow',
                        'Warning',
                        'WF_AUTO_MISCONFIG',
                        'Auto step has no actionable autoAction — skipped',
                        'instanceID=' . $instanceId . ' stepID=' . $step['stepID']
                    );
                    $txResult = self::runClaim($db, $instance, $step, 'skipped', 'approved', null, 'Auto step misconfigured (no autoAction) — skipped');
                }
                if ($txResult === null || $txResult['ok'] === false) {
                    return;
                }
                if ($txResult['completed'] === true) {
                    self::notifyOutcome($instance, (string) $txResult['outcome']);
                    self::emitCompletedWebhook($instance, (string) $txResult['outcome']);
                    return;
                }
                continue;
            }

            // approval / review (or any unrecognised type) — needs a human.
            self::notifyStepAssignees($instance, $step);
            return;
        }
    }

    /* ========================================================================= */
    /* 🔒 PRIVATE — authorisation                                                */
    /* ========================================================================= */

    /**
     * Is $actorId authorised to act on $step, for the instance's own site?
     * Requires an active tblUserSites membership for THAT site regardless of
     * role/user/group match, then either a role/user/group match on the
     * step's assignee, or (workflows.admin_override, default on) a site
     * admin for that site.
     */
    private static function canAct(array $step, int $actorId, int $siteId): bool
    {
        $db = App::db();

        $stmt = $db->prepare(
            'SELECT 1 FROM tblUserSites us JOIN tblUsers u ON u.userID = us.userID '
            . 'WHERE us.userID = ? AND us.siteID = ? AND us.isActive = 1 AND u.isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $actorId, $siteId);
        $stmt->execute();
        $isMember = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        if ($isMember === false) {
            return false;
        }

        $assigneeType = (string) $step['assigneeType'];
        $assigneeValue = (string) ($step['assigneeValue'] ?? '');
        $isAssignee = false;

        if ($assigneeType === 'user' && $assigneeValue !== '') {
            $isAssignee = ((int) $assigneeValue === $actorId);
        } elseif ($assigneeType === 'role' && $assigneeValue !== '') {
            $stmt = $db->prepare(
                'SELECT 1 FROM tblUserRoles ur JOIN tblRoles r ON r.roleID = ur.roleID '
                . 'WHERE ur.userID = ? AND r.roleKey = ? LIMIT 1'
            );
            if ($stmt !== false) {
                $stmt->bind_param('is', $actorId, $assigneeValue);
                $stmt->execute();
                $isAssignee = $stmt->get_result()->fetch_assoc() !== null;
                $stmt->close();
            }
        } elseif ($assigneeType === 'group' && $assigneeValue !== '') {
            $groupId = (int) $assigneeValue;
            $stmt = $db->prepare('SELECT 1 FROM tblUserGroups WHERE userID = ? AND groupID = ? LIMIT 1');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $actorId, $groupId);
                $stmt->execute();
                $isAssignee = $stmt->get_result()->fetch_assoc() !== null;
                $stmt->close();
            }
        }

        if ($isAssignee === true) {
            return true;
        }

        $overrideOn = (string) (App::settingForSite('workflows.admin_override', $siteId) ?? '1') !== '0';
        if ($overrideOn === false) {
            return false;
        }

        return self::isAdminForSite($actorId, $siteId, $db);
    }

    private static function isAdminForSite(int $userId, int $siteId, mysqli $db): bool
    {
        $stmt = $db->prepare(
            'SELECT u.isAdmin, u.isRootAdmin, COALESCE(us.isSiteAdmin, 0) AS isSiteAdmin, '
            . 'COALESCE(us.isSiteRootAdmin, 0) AS isSiteRootAdmin '
            . 'FROM tblUsers u LEFT JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
            . 'WHERE u.userID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $siteId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return false;
        }
        return ((string) $row['isAdmin'] === '1'
            || (string) $row['isRootAdmin'] === '1'
            || (string) $row['isSiteAdmin'] === '1'
            || (string) $row['isSiteRootAdmin'] === '1');
    }

    /* ========================================================================= */
    /* 🔒 PRIVATE — subject-adapter registry                                     */
    /* ========================================================================= */

    /**
     * The generic⇄consumer boundary (§3.7). One `match` arm per wired
     * consumer. Runs INSIDE the caller's open transaction — a thrown
     * exception here rolls back the whole approval, so approval and publish
     * always commit or fail together.
     */
    private static function applySubjectEffect(mysqli $db, array $instance, string $outcome, ?int $actorId): void
    {
        $workflowKey = (string) ($instance['workflowKey'] ?? '');
        $recordId = (int) $instance['recordID'];
        $siteId = (int) $instance['siteID'];

        switch ($workflowKey) {
            case 'announcement_publish':
                if ($outcome === 'approved') {
                    $stmt = $db->prepare(
                        'UPDATE tblAnnouncements SET isPublished = 1, updatedByID = ? '
                        . 'WHERE announcementID = ? AND siteID = ? AND isDeleted = 0'
                    );
                    if ($stmt === false) {
                        throw new \RuntimeException('Workflow::applySubjectEffect — prepare failed for announcement_publish');
                    }
                    $stmt->bind_param('iii', $actorId, $recordId, $siteId);
                    $stmt->execute();
                    $affected = $stmt->affected_rows;
                    $stmt->close();
                    if ($affected === 0) {
                        // 🛡️ Row is gone/soft-deleted — the workflow still
                        // completes truthfully; this is a warning, not a
                        // reason to fail the approval itself.
                        Logger::errorPlatform(
                            'Workflow',
                            'Warning',
                            'WF_PUBLISH_NOROW',
                            'Approved announcement publish found no row to update',
                            'announcementID=' . $recordId . ' siteID=' . $siteId
                        );
                    }
                }
                // rejected / cancelled ⇒ no subject write — stays unpublished.
                break;
            default:
                // Unwired workflowKey — pure sign-off trail, no side effect
                // (e.g. an admin-created ad-hoc definition, or the dormant
                // seeded expense_approval, which the engine never starts).
                break;
        }
    }

    /* ========================================================================= */
    /* 🔒 PRIVATE — assignee resolution + email (reuses the Mailer layer)       */
    /* ========================================================================= */

    /**
     * Resolve a step's eligible recipients for THIS site.
     *
     * @return array<int, array{email: string, notifyPrefs: ?string}>
     */
    private static function resolveAssignees(array $step, int $siteId): array
    {
        $db = App::db();
        $assigneeType = (string) $step['assigneeType'];
        $assigneeValue = (string) ($step['assigneeValue'] ?? '');
        $out = [];

        if ($assigneeType === 'user' && $assigneeValue !== '') {
            $userId = (int) $assigneeValue;
            $stmt = $db->prepare(
                'SELECT u.userID, u.emailAddress, u.notifyPrefs FROM tblUsers u '
                . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
                . 'WHERE u.userID = ? AND u.isActive = 1 LIMIT 1'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ii', $siteId, $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row !== null) {
                    $out[(int) $row['userID']] = [
                        'email' => (string) $row['emailAddress'],
                        'notifyPrefs' => $row['notifyPrefs'] !== null ? (string) $row['notifyPrefs'] : null,
                    ];
                }
                $stmt->close();
            }
        } elseif ($assigneeType === 'role' && $assigneeValue !== '') {
            $stmt = $db->prepare(
                'SELECT DISTINCT u.userID, u.emailAddress, u.notifyPrefs FROM tblUsers u '
                . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
                . 'JOIN tblUserRoles ur ON ur.userID = u.userID '
                . 'JOIN tblRoles r ON r.roleID = ur.roleID '
                . 'WHERE u.isActive = 1 AND r.roleKey = ?'
            );
            if ($stmt !== false) {
                $stmt->bind_param('is', $siteId, $assigneeValue);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $out[(int) $row['userID']] = [
                        'email' => (string) $row['emailAddress'],
                        'notifyPrefs' => $row['notifyPrefs'] !== null ? (string) $row['notifyPrefs'] : null,
                    ];
                }
                $stmt->close();
            }
        } elseif ($assigneeType === 'group' && $assigneeValue !== '') {
            $groupId = (int) $assigneeValue;
            $stmt = $db->prepare(
                'SELECT DISTINCT u.userID, u.emailAddress, u.notifyPrefs FROM tblUsers u '
                . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
                . 'JOIN tblUserGroups ug ON ug.userID = u.userID '
                . 'WHERE u.isActive = 1 AND ug.groupID = ?'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ii', $siteId, $groupId);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $out[(int) $row['userID']] = [
                        'email' => (string) $row['emailAddress'],
                        'notifyPrefs' => $row['notifyPrefs'] !== null ? (string) $row['notifyPrefs'] : null,
                    ];
                }
                $stmt->close();
            }
        }

        return $out;
    }

    /**
     * Default-on notifyPrefs check — clones the notifyPrefAllows() idiom
     * from cron/user-reminders.php. Only an explicit `false` for this exact
     * key suppresses sending.
     */
    private static function notifyPrefAllows(?string $notifyPrefsJson, string $key): bool
    {
        if ($notifyPrefsJson === null || trim($notifyPrefsJson) === '') {
            return true;
        }
        $prefs = json_decode($notifyPrefsJson, true);
        if (is_array($prefs) === false || array_key_exists($key, $prefs) === false) {
            return true;
        }
        return $prefs[$key] === false ? false : true;
    }

    /**
     * "You have an approval waiting" email to a step's eligible assignees.
     */
    private static function notifyStepAssignees(array $instance, array $step): void
    {
        $siteId = (int) $instance['siteID'];
        if ((string) (App::settingForSite('workflows.notify_email', $siteId) ?? '1') === '0') {
            return;
        }
        $assignees = self::resolveAssignees($step, $siteId);
        if (count($assignees) === 0) {
            return;
        }

        $label = htmlspecialchars((string) ($instance['subjectLabel'] ?? ''), ENT_QUOTES, 'UTF-8');
        $workflowName = htmlspecialchars((string) ($instance['workflowName'] ?? ''), ENT_QUOTES, 'UTF-8');
        $stepName = htmlspecialchars((string) $step['stepName'], ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars(self::absoluteUrl('/approvals'), ENT_QUOTES, 'UTF-8');

        $subject = 'Approval needed: ' . (string) ($instance['subjectLabel'] ?? $workflowName);
        $body = '<p><strong>' . $workflowName . '</strong> — ' . $stepName . '</p>'
              . '<p>' . $label . '</p>'
              . '<p><a href="' . $url . '">Review in Approvals</a></p>';

        foreach ($assignees as $info) {
            if (filter_var($info['email'], FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            if (self::notifyPrefAllows($info['notifyPrefs'], 'approvalRequests') === false) {
                continue;
            }
            try {
                Mailer::send($info['email'], $subject, $body);
            } catch (Throwable $ignored) {
                // 📮 A mail failure must never break a state transition.
            }
        }
    }

    /**
     * FYI email for a notification-type step's assignees — sent before the
     * step is auto-advanced past.
     */
    private static function sendFyiNotice(array $instance, array $step): void
    {
        $siteId = (int) $instance['siteID'];
        if ((string) (App::settingForSite('workflows.notify_email', $siteId) ?? '1') === '0') {
            return;
        }
        $assignees = self::resolveAssignees($step, $siteId);
        if (count($assignees) === 0) {
            return;
        }
        $label = htmlspecialchars((string) ($instance['subjectLabel'] ?? ''), ENT_QUOTES, 'UTF-8');
        $workflowName = htmlspecialchars((string) ($instance['workflowName'] ?? ''), ENT_QUOTES, 'UTF-8');
        $stepName = htmlspecialchars((string) $step['stepName'], ENT_QUOTES, 'UTF-8');
        $subject = 'FYI: ' . $workflowName;
        $body = '<p><strong>' . $workflowName . '</strong> — ' . $stepName . '</p><p>' . $label . '</p>';

        foreach ($assignees as $info) {
            if (filter_var($info['email'], FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            if (self::notifyPrefAllows($info['notifyPrefs'], 'approvalRequests') === false) {
                continue;
            }
            try {
                Mailer::send($info['email'], $subject, $body);
            } catch (Throwable $ignored) {
            }
        }
    }

    /**
     * Outcome email to the instance's submitter on completion.
     */
    private static function notifyOutcome(array $instance, string $outcome): void
    {
        $siteId = (int) $instance['siteID'];
        if ((string) (App::settingForSite('workflows.notify_email', $siteId) ?? '1') === '0') {
            return;
        }
        $startedById = $instance['startedByID'] !== null ? (int) $instance['startedByID'] : 0;
        if ($startedById <= 0) {
            return;
        }
        $db = App::db();
        $stmt = $db->prepare('SELECT emailAddress, notifyPrefs FROM tblUsers WHERE userID = ? AND isActive = 1 LIMIT 1');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $startedById);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return;
        }
        $email = (string) $row['emailAddress'];
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        if (self::notifyPrefAllows($row['notifyPrefs'] !== null ? (string) $row['notifyPrefs'] : null, 'approvalRequests') === false) {
            return;
        }

        $label = htmlspecialchars((string) ($instance['subjectLabel'] ?? ''), ENT_QUOTES, 'UTF-8');
        $workflowName = htmlspecialchars((string) ($instance['workflowName'] ?? ''), ENT_QUOTES, 'UTF-8');
        $outcomeEsc = htmlspecialchars($outcome, ENT_QUOTES, 'UTF-8');
        $subject = $workflowName . ' — ' . ucfirst($outcome);
        $body = '<p>Your request <strong>' . $label . '</strong> (' . $workflowName . ') has been <strong>' . $outcomeEsc . '</strong>.</p>';

        try {
            Mailer::send($email, $subject, $body);
        } catch (Throwable $ignored) {
        }
    }

    /**
     * Escalation email to site admins + the overdue step's own assignees.
     */
    private static function escalateNotify(mysqli $db, int $siteId, array $instance, array $step): void
    {
        if ((string) (App::settingForSite('workflows.notify_email', $siteId) ?? '1') === '0') {
            return;
        }

        $admins = [];
        $stmt = $db->prepare(
            'SELECT DISTINCT u.emailAddress FROM tblUsers u '
            . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
            . 'WHERE u.isActive = 1 AND (u.isAdmin = 1 OR u.isRootAdmin = 1 OR us.isSiteAdmin = 1 OR us.isSiteRootAdmin = 1)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $admins[] = (string) $row['emailAddress'];
            }
            $stmt->close();
        }

        $recipients = $admins;
        foreach (self::resolveAssignees($step, $siteId) as $info) {
            $recipients[] = $info['email'];
        }
        $recipients = array_values(array_unique(array_filter(
            $recipients,
            static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        )));
        if (count($recipients) === 0) {
            return;
        }

        $label = htmlspecialchars((string) ($instance['subjectLabel'] ?? ''), ENT_QUOTES, 'UTF-8');
        $workflowName = htmlspecialchars((string) ($instance['workflowName'] ?? ''), ENT_QUOTES, 'UTF-8');
        $stepName = htmlspecialchars((string) $step['stepName'], ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars(self::absoluteUrl('/approvals'), ENT_QUOTES, 'UTF-8');
        $subject = 'Approval overdue: ' . $workflowName;
        $body = '<p><strong>' . $workflowName . '</strong> — ' . $stepName . ' has been waiting longer than the configured timeout.</p>'
              . '<p>' . $label . '</p>'
              . '<p><a href="' . $url . '">Review in Approvals</a></p>';

        foreach ($recipients as $email) {
            try {
                Mailer::send($email, $subject, $body);
            } catch (Throwable $ignored) {
            }
        }
    }

    /**
     * Fire-and-forget webhook emission on a terminal transition. Never
     * throws — webhooks are observability, not the source of truth.
     */
    private static function emitCompletedWebhook(array $instance, string $outcome): void
    {
        try {
            WebhookDispatcher::emit('workflow.completed', [
                'instanceID' => (int) $instance['instanceID'],
                'workflowKey' => (string) ($instance['workflowKey'] ?? ''),
                'outcome' => $outcome,
                'tableName' => (string) $instance['tableName'],
                'recordID' => (int) $instance['recordID'],
            ]);
        } catch (Throwable $ignored) {
        }
    }

    /**
     * Absolute link builder for reminder/approval emails — mirrors
     * userReminderUrl() in cron/user-reminders.php.
     */
    private static function absoluteUrl(string $path): string
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . $path;
    }

    /* ========================================================================= */
    /* 🔒 PRIVATE — small data-access helpers                                    */
    /* ========================================================================= */

    /**
     * FOR UPDATE row lock on one instance, site-scoped (the IDOR wall). NO
     * status filter here — callers that need "must still be active" check
     * status themselves so they can distinguish not_found from not_active.
     */
    private static function loadLockedInstance(mysqli $db, int $instanceId, int $siteId): ?array
    {
        $stmt = $db->prepare(
            'SELECT i.instanceID, i.workflowID, i.siteID, i.tableName, i.recordID, i.currentStep, i.status, '
            . 'i.subjectLabel, i.contextJson, i.startedByID, w.workflowKey, w.workflowName '
            . 'FROM tblWorkflowInstances i JOIN tblWorkflows w ON w.workflowID = i.workflowID '
            . 'WHERE i.instanceID = ? AND i.siteID = ? FOR UPDATE'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $instanceId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    /** Unlocked instance read — used by processAutoSteps() (each iteration takes its own fresh lock via claimTransition's UPDATE). */
    private static function loadInstance(mysqli $db, int $instanceId): ?array
    {
        $stmt = $db->prepare(
            'SELECT i.instanceID, i.workflowID, i.siteID, i.tableName, i.recordID, i.currentStep, i.status, '
            . 'i.subjectLabel, i.contextJson, i.startedByID, w.workflowKey, w.workflowName '
            . 'FROM tblWorkflowInstances i JOIN tblWorkflows w ON w.workflowID = i.workflowID '
            . 'WHERE i.instanceID = ?'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $instanceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    /** The very first step of a definition — ORDER BY stepOrder ASC, stepID ASC LIMIT 1 (§3.6). */
    private static function firstStep(int $workflowId): ?array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT stepID, stepOrder, stepName, stepType, assigneeType, assigneeValue, autoAction, timeoutHours '
            . 'FROM tblWorkflowSteps WHERE workflowID = ? ORDER BY stepOrder ASC, stepID ASC LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $workflowId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    /** Resolve "the current step" deterministically — duplicate stepOrder edge (§3.6). */
    private static function resolveStepByOrder(int $workflowId, int $stepOrder): ?array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT stepID, stepOrder, stepName, stepType, assigneeType, assigneeValue, autoAction, timeoutHours '
            . 'FROM tblWorkflowSteps WHERE workflowID = ? AND stepOrder = ? ORDER BY stepID ASC LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $workflowId, $stepOrder);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    /** Resolve "the next step after $currentStepOrder" deterministically (§3.6). */
    private static function resolveNextStep(int $workflowId, int $currentStepOrder): ?array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT stepID, stepOrder FROM tblWorkflowSteps WHERE workflowID = ? AND stepOrder > ? '
            . 'ORDER BY stepOrder ASC, stepID ASC LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $workflowId, $currentStepOrder);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    /** Insert one tblWorkflowActions row. $comment '' is stored as NULL. */
    private static function recordAction(mysqli $db, int $instanceId, int $stepId, string $action, string $comment, ?int $actorId): void
    {
        $stmt = $db->prepare(
            'INSERT INTO tblWorkflowActions (instanceID, stepID, action, comment, actedByID) VALUES (?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Workflow::recordAction — prepare failed: ' . $db->error);
        }
        $commentVal = $comment !== '' ? $comment : null;
        $stmt->bind_param('iissi', $instanceId, $stepId, $action, $commentVal, $actorId);
        $stmt->execute();
        $stmt->close();
    }

    /** Has this (instanceID, stepID) already recorded an 'escalated' action? */
    private static function hasEscalated(mysqli $db, int $instanceId, int $stepId): bool
    {
        $stmt = $db->prepare("SELECT 1 FROM tblWorkflowActions WHERE instanceID = ? AND stepID = ? AND action = 'escalated' LIMIT 1");
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $instanceId, $stepId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        return $exists;
    }

    /** @return list<string> roleKeys the user holds. */
    private static function userRoleKeys(mysqli $db, int $userId): array
    {
        $keys = [];
        $stmt = $db->prepare('SELECT r.roleKey FROM tblUserRoles ur JOIN tblRoles r ON r.roleID = ur.roleID WHERE ur.userID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $keys[] = (string) $row['roleKey'];
            }
            $stmt->close();
        }
        return $keys;
    }

    /** @return list<int> groupIDs the user belongs to. */
    private static function userGroupIds(mysqli $db, int $userId): array
    {
        $ids = [];
        $stmt = $db->prepare('SELECT groupID FROM tblUserGroups WHERE userID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $ids[] = (int) $row['groupID'];
            }
            $stmt->close();
        }
        return $ids;
    }

    /** Uniform failure-shape helper — see act()'s return contract. */
    private static function result(bool $ok, ?string $error): array
    {
        return ['ok' => $ok, 'error' => $error, 'completed' => false, 'outcome' => null];
    }
}
