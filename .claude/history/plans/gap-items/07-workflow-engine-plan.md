# Gap #7 — Workflow Execution Engine + Generic Approvals Inbox
## Build-ready implementation plan (no code) — verified against `alpha` @ 7e07262

**Scope in one sentence:** migration 034 shipped four workflow tables and an admin
definition CRUD, but **no code anywhere starts, advances, completes, or times out a
workflow instance** — this plan builds the engine (`Portal\Core\Workflow`), a generic
`/approvals` inbox, a token-gated timeout cron, and wires exactly ONE safe reference
consumer (announcement publish approval), all behind per-site feature flags so the
existing manual paths keep working unchanged when the flags are off.

---

## 1. Verified current state (file:line evidence)

### 1.1 The four workflow tables — exact schema

Created by `web/_sql/034_workflow_engine.sql` and folded into
`web/_sql/full_schema.sql` (identical definitions at lines 1211, 1231, 1252, 1276).

**`tblWorkflows`** (034:8-22; full_schema:1211) — the definition header:

| Column | Type | Notes |
|---|---|---|
| workflowID | INT PK AUTO_INCREMENT | |
| siteID | INT NOT NULL DEFAULT 1 | FK → tblSites (`fk_workflow_site`) — **definitions are per-site** |
| workflowName | VARCHAR(100) NOT NULL | |
| workflowKey | VARCHAR(50) NOT NULL | machine key, e.g. `expense_approval` |
| description | VARCHAR(255) NULL | |
| isActive | TINYINT(1) NOT NULL DEFAULT 1 | **never read by any code today** |
| createdAt / updatedAt | DATETIME | auto |

Keys: `uq_workflow_key_site (workflowKey, siteID)` (unique — makes definition seeds
idempotent), `idx_workflow_site (siteID)`.

**`tblWorkflowSteps`** (034:25-40; full_schema:1231) — ordered stages:

| Column | Type | Notes |
|---|---|---|
| stepID | INT PK AUTO_INCREMENT | |
| workflowID | INT NOT NULL | FK → tblWorkflows **ON DELETE CASCADE** |
| stepOrder | INT NOT NULL DEFAULT 1 | 1-based ordering; **no unique key on (workflowID, stepOrder)** — see §3.6 |
| stepName | VARCHAR(100) NOT NULL | |
| stepType | ENUM('approval','review','notification','auto') DEFAULT 'approval' | |
| assigneeType | ENUM('role','user','group') DEFAULT 'role' | |
| assigneeValue | VARCHAR(100) NULL | "Role name, userID, or groupID" (column comment) |
| autoAction | ENUM('approve','reject','escalate') NULL | "For auto steps" — also the natural timeout action vocabulary |
| timeoutHours | INT NULL | column comment: "Auto-escalate after N hours" — **the timeout concept exists in schema but nothing sweeps it** |
| createdAt | DATETIME | |

Key: `idx_wfstep_workflow (workflowID)`. **No siteID** — steps are site-scoped only
transitively via workflowID → tblWorkflows.siteID (the admin save handler already
defends this at `web/_apps/admin/workflows/save.php:88-106`).

**`tblWorkflowInstances`** (034:43-61; full_schema:1252) — running instances:

| Column | Type | Notes |
|---|---|---|
| instanceID | INT PK AUTO_INCREMENT | |
| workflowID | INT NOT NULL | FK → tblWorkflows (no cascade — a definition with instances can't be deleted) |
| siteID | INT NOT NULL DEFAULT 1 | denormalised tenant scope — use it in EVERY instance query |
| tableName | VARCHAR(100) NOT NULL | subject table, e.g. `tblAnnouncements` |
| recordID | INT NOT NULL | subject PK |
| currentStep | INT NOT NULL DEFAULT 1 | **holds a stepOrder value, not a stepID** (DEFAULT 1 = first stepOrder) |
| status | ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending' | **note: no 'rejected' member** — see §7 (new `outcome` column instead of an enum widen) |
| startedByID | INT NULL | FK → tblUsers ON DELETE SET NULL |
| startedAt | DATETIME DEFAULT CURRENT_TIMESTAMP | |
| completedAt | DATETIME NULL | |

Keys: `idx_wfi_workflow (workflowID)`, `idx_wfi_record (tableName, recordID)`,
`idx_wfi_status (status)`. Missing for our inbox query: a composite
`(siteID, status)` index — added in migration 174 (§7).

**`tblWorkflowActions`** (034:64-78; full_schema:1276) — the per-step audit log:

| Column | Type | Notes |
|---|---|---|
| actionID | INT PK AUTO_INCREMENT | |
| instanceID | INT NOT NULL | FK → tblWorkflowInstances **ON DELETE CASCADE** |
| stepID | INT NOT NULL | FK → tblWorkflowSteps, **no ON DELETE clause ⇒ RESTRICT** — deleting a step with any recorded action fails at the DB (exploited deliberately by the step-delete handler, §5.4) |
| action | ENUM('approved','rejected','escalated','skipped') NOT NULL | **no 'commented' / 'delegated'** — 'commented' added in 174; delegate deferred (§12 Q3) |
| comment | TEXT NULL | |
| actedByID | INT NULL | FK → tblUsers ON DELETE SET NULL — **NULL = system/timeout action** |
| actedAt | DATETIME DEFAULT CURRENT_TIMESTAMP | |

Key: `idx_wfa_instance (instanceID)`.

**Intended state machine (as designed by 034):** a *definition* (tblWorkflows row +
ordered tblWorkflowSteps) instantiates into a tblWorkflowInstances row pointing at a
subject record (`tableName` + `recordID`), walks `currentStep` through the stepOrder
sequence, records one tblWorkflowActions row per decision, and terminates with
`status='completed'` + `completedAt`. Steps carry the approver pointer
(assigneeType/assigneeValue), the step behaviour (stepType), and the timeout policy
(timeoutHours + autoAction). All of this is coherent and usable **as-is** — the
tables need only four additive columns and one enum value (§7); no redesign.

### 1.2 The engine does not exist — proof

`grep -rn tblWorkflow web/` matches exactly **four** files:
`web/_sql/full_schema.sql`, `web/_sql/034_workflow_engine.sql`,
`web/_apps/admin/workflows/index.php`, `web/_apps/admin/workflows/save.php`.
The two PHP files only CRUD definitions and `COUNT(*)` instances for a badge
(`admin/workflows/index.php:70-71`). There is **no** `_core/Workflow.php`, no
`INSERT INTO tblWorkflowInstances` anywhere, no `INSERT INTO tblWorkflowActions`
anywhere, no reader of `tblWorkflowSteps.timeoutHours`/`autoAction`/`isActive`
outside the admin display. Nothing starts, advances, records, completes, or
times out anything.

### 1.3 The seeded `expense_approval` definition + why Expenses stays untouched

- 034:89-92 (and full_schema:2253-2256) seeds ONE `tblWorkflows` row —
  `('Expense Approval', 'expense_approval')` for siteID=1 — with **zero steps** and
  no start caller. It is dormant.
- Expenses already has a complete, independent approval implementation:
  - Own decision table `tblExpenseClaimApprovals` (full_schema:458-477) with
    decision/comments/approverRole per approver.
  - Own multi-approver engine in `web/_apps/expenses/approve/save.php`: department-
    scoped authority via `tblUserDepts` flags (isDeptLead/isApprover/
    isMandatoryApprover, lines 97-135), a 403 on non-authority (line 131-134),
    `begin_transaction()` + `SELECT … FOR UPDATE` re-check to close the concurrent-
    approval race (lines 141-163), "any rejection rejects" + "all mandatory
    approvers must approve" logic (lines 178-190+), status transitions on
    `tblExpenseClaims.status` ENUM('Pending','Approved','Rejected','Reimbursed')
    (full_schema:395-396), plus treasury/withdraw flows in
    `web/_apps/expenses/{treasury,withdraw}/`.
- **Decision:** the engine must not drive, mirror, or observe expenses in v1.
  Mirroring would double-write approval state with no consumer, and driving would
  destabilise a money path that already has stronger (department-scoped, mandatory-
  multi-approver) semantics than the generic step schema can express. The
  `expense_approval` seed row stays exactly as-is — inert, because the engine only
  runs definitions that some consumer explicitly `start()`s (§3.2), and nothing
  calls `start('expense_approval', …)`. Document this in DEV_NOTES (§8) and in the
  admin UI info-box (§5.4). Do not delete or alter the seed row (replay/parity
  churn for zero benefit).

### 1.4 Admin definition CRUD — what exists, what's missing

Lives at `/admin/workflows` (routes seeded 034:81-87; full_schema:2244-2250):

- `web/_apps/admin/workflows/index.php` — admin-only
  (`App::isAdmin()`, line 28), site-scoped list of definitions with step-count +
  active-instance badges (lines 67-82), and an edit form that shows existing steps
  read-only (portal-data-list, lines 131-153) plus a single "Add Step" row
  (lines 155-181: stepName/stepType/assigneeType/assigneeValue/timeoutHours —
  **no autoAction input**).
- `web/_apps/admin/workflows/save.php` — POST-only, CSRF'd
  (line 39), creates/updates the workflow header and appends one step at
  MAX(stepOrder)+1 (lines 113-131), with a site-ownership re-check before step
  insert (lines 88-106).

**Missing to actually run a workflow** (built in this plan):
1. Any engine (§3) and any start caller (§5).
2. Any actor-facing surface — an inbox to see and decide pending steps (§4).
3. Timeout sweep (§6).
4. In the CRUD itself: step **delete** (a mistyped step currently bricks a
   definition forever), an **isActive toggle** (the column exists, nothing writes
   or reads it), and an **autoAction** input on the add-step row (needed for
   timeout auto-decide). All three added in §5.4. Step *reorder* is deliberately
   out of v1 scope (delete + re-add reaches the same place).

### 1.5 Candidate reference consumers — evaluation

Requirement: a real, currently-manual approve/publish gate; reversible; not money,
not safeguarding; "pending → approved → published" a clean fit; feature-flagged so
OFF = today's behaviour byte-for-byte.

| Candidate | Today | Verdict |
|---|---|---|
| **Announcements publish** | `tblAnnouncements.isPublished` TINYINT manual checkbox (full_schema:1091; form checkbox `announcements/manage.php:150-152`; write `announcements/save.php:55` + bind at 99-104/132-135). Every reader gates on `isPublished = 1`: `announcements/index.php:48,65`, `announcements/view.php:46`, `dashboard/index.php:208`. Admin-gated management (`save.php:33`). Soft delete via `isDeleted` (`delete.php:60`). | **RECOMMENDED.** Pure editorial content; fully reversible (unpublish = flip a flag; no side effects on approval beyond the flag); single boolean gate means the workflow simply *withholds* `isPublished=1` until final approval — no schema change on the consumer; a dual-control "second pair of eyes before it hits every dashboard" is a genuinely wanted control even among admins. |
| Documents publish | `tblDocuments.isPublished` DEFAULT **1** (full_schema:1141); upload is admin-only (`documents/upload.php:29`) and there is **no publish UI at all** — no manual gate exists to replace. | Rejected: wiring would first have to invent the manual flow it is supposed to gate (flip the default, build a publish toggle) — bigger blast radius than announcements for the same demo value. Good **v2** consumer. |
| Newsletter send | `tblNewsletter.status` ENUM('draft','scheduled','sending','sent','cancelled') (full_schema:3700) driven through `newsletter/send.php`. | Rejected for v1: the approved action (email blast to the whole membership) is **irreversible**, and the send path has its own multi-state machine + scheduling — a workflow bug becomes an un-recallable mass email. |
| Photos / moderation queue | `tblPhoto.status` ENUM('pending_approval','approved','rejected') + moderatedByID/moderatedAt/rejectionReason (full_schema:4435-4438) with an existing admin queue (`admin/photos/queue`, `admin/photos/moderate` routes, full_schema:4451-4460). | Rejected: photos **already has** its own complete moderation flow — same "don't duplicate an existing approval" rule as expenses. |

**Chosen reference consumer: Announcements publish approval**, behind per-site flag
`workflows.announcements.enabled` (default `'false'`). Full wiring in §5.

---

## 2. Architecture overview

```
                        ┌──────────────────────────────────────────┐
                        │  Portal\Core\Workflow  (new, _core)      │
 consumer "submit" ────▶│  start()  act()  cancelForSubject()      │
 (announcements/save)   │  timeoutSweep()  + private: advance(),   │
                        │  complete(), processAutoSteps(),         │
 /approvals inbox ─────▶│  canAct(), resolveAssignees(), notify…() │
 (index + act POST)     └───────────────┬──────────────────────────┘
                                        │ atomic guarded UPDATEs + txn
 cron/workflow-timeouts ───────────────▶│ (Payments.php:1086 pattern +
 (token-gated, hourly)                  │  expenses/approve/save.php txn+FOR UPDATE)
                                        ▼
                 tblWorkflows / tblWorkflowSteps / tblWorkflowInstances /
                 tblWorkflowActions   (+4 columns, +1 enum value, +1 index — mig 174)
                                        │
                       on final approve │ subject-adapter side effect
                                        ▼
                 UPDATE tblAnnouncements SET isPublished = 1 …
```

Everything is definition-driven: the engine knows *nothing* about announcements
except through a small, explicit **subject-adapter registry** inside Workflow.php
(§3.7) — one `match` on `workflowKey` providing `onApproved` / `onRejected` /
`onCancelled` side effects and label/url derivation. Adding consumer #2 later means
one new adapter arm + one `start()` call at that consumer's submit point + one
enable flag. No engine changes.

---

## 3. Engine design — `web/_core/Workflow.php` (new, `Portal\Core\Workflow`)

House frame: `declare(strict_types=1)`; file header block (path/description/
package/author/copyright All Rights Reserved/version/@link to the GitHub issue);
emoji-annotated sections; `App::db()` for the handle (never a bare global inside
_core — Router only injects `$mysqli` into controllers, Router.php:129-139);
prepared statements everywhere; every `bind_param` arity hand-counted
(`tools/audit-checks/check_bind_param_arity.py` gates it).

### 3.1 State machine — statuses, verbs, transitions

Instance `status` × new `outcome` column (§7):

| From | Verb / event | To | Side effects |
|---|---|---|---|
| *(none)* | `start()` | `pending`, currentStep = first stepOrder, currentStepStartedAt = NOW() | duplicate-active guard; auto-process leading notification/auto steps (§3.5); notify step-1 assignees |
| pending / in_progress | `act(approve)` — NOT final step | `in_progress`, currentStep = next stepOrder, currentStepStartedAt = NOW() | action row `approved`; auto-process (§3.5); notify next assignees |
| pending / in_progress | `act(approve)` — final step | `completed`, outcome = `approved`, completedAt = NOW() | action row `approved`; adapter `onApproved` (publishes); notify submitter |
| pending / in_progress | `act(reject)` — any step | `completed`, outcome = `rejected`, completedAt = NOW() | action row `rejected`; adapter `onRejected`; notify submitter |
| pending / in_progress | `act(comment)` | *(unchanged)* | action row `commented` only — never advances |
| pending / in_progress | `cancelForSubject()` | `cancelled`, outcome = `cancelled`, completedAt = NOW() | action row `skipped` w/ comment 'Cancelled: <reason>'; adapter `onCancelled` (no-op for announcements) |
| pending / in_progress | timeout, step.autoAction = approve | as `act(approve)` with actedByID = NULL | comment "Auto-approved after Nh timeout" |
| pending / in_progress | timeout, step.autoAction = reject | as `act(reject)` with actedByID = NULL | comment "Auto-rejected after Nh timeout" |
| pending / in_progress | timeout, step.autoAction = escalate **or NULL** | *(unchanged)* | ONE-time action row `escalated` (dedupe by existing row for this instanceID+stepID); notify site admins + re-notify assignees |
| completed / cancelled | any verb | **refused** | terminal states are immutable — no resurrect, no re-approve |

`pending` = created, no decision recorded yet; first recorded decision flips to
`in_progress`. This matches the existing badge query
(`admin/workflows/index.php:71` counts `status IN ('pending','in_progress')` as
"running") with zero change.

### 3.2 Public API (exact signatures)

```php
// Start an instance of the site's active definition $definitionKey against a
// subject row. Returns the new instanceID, or null when it cannot start
// (no active definition for Site::id(), definition has zero steps, or an
// active instance already exists for this subject). Never throws to the
// consumer — callers implement their own fallback policy (§5.2).
public static function start(
    string $definitionKey,   // e.g. 'announcement_publish'
    string $subjectTable,    // e.g. 'tblAnnouncements'
    int    $subjectId,
    int    $startedById,
    string $subjectLabel,    // display snapshot for the inbox, e.g. the title
    array  $context = []     // stored as contextJson (e.g. ['url' => '/announcements/view?...'])
): ?int

// Record a decision on the CURRENT step of an instance. $decision is one of
// 'approve' | 'reject' | 'comment'. Returns a result array
// ['ok' => bool, 'error' => ?string, 'completed' => bool, 'outcome' => ?string]
// — errors: 'not_found' (also covers cross-site, deliberately
// indistinguishable), 'not_active', 'stale_step' (posted stepID no longer
// current), 'forbidden' (actor may not act on this step), 'conflict'
// (atomic claim lost — someone else decided first).
public static function act(
    int    $instanceId,
    int    $stepId,          // MUST match the current step — anti-stale-form guard
    int    $actorId,
    string $decision,
    string $comment = ''
): array

// Cancel any active instance bound to a subject row (used by consumer delete/
// withdraw hooks). Safe no-op when none exists. Returns count cancelled (0|1).
public static function cancelForSubject(string $subjectTable, int $subjectId, string $reason, ?int $actorId = null): int

// Timeout sweep for ONE site (the cron loops sites and calls this inside its
// Site::forceContext loop, §6). Returns ['checked'=>int,'auto_acted'=>int,'escalated'=>int].
public static function timeoutSweep(int $siteId): array

// Inbox helpers (read-only, used by /approvals — §4):
public static function actionableForUser(int $userId, int $siteId, bool $isAdmin): array
public static function historyForSite(int $siteId, ?int $userId, bool $isAdmin, int $limit = 50): array
public static function actionsForInstance(int $instanceId, int $siteId): array
public static function activeInstanceForSubject(string $subjectTable, int $subjectId): ?array
```

`advance()`, `complete()`, `processAutoSteps()`, `canAct()`, `resolveAssignees()`,
`notifyStepAssignees()`, `notifyOutcome()`, `applySubjectEffect()` are **private** —
the state machine is only enterable through the four public mutators, so no caller
can skip a guard.

### 3.3 `start()` — exact behaviour

1. Resolve the definition: `SELECT workflowID FROM tblWorkflows WHERE workflowKey=?
   AND siteID=? AND isActive=1 LIMIT 1` with `Site::id()`. None ⇒ return null.
2. Resolve the first step: `SELECT stepID, stepOrder, … FROM tblWorkflowSteps
   WHERE workflowID=? ORDER BY stepOrder ASC, stepID ASC LIMIT 1`. None (zero-step
   definition, e.g. the dormant `expense_approval`) ⇒ return null.
3. **Duplicate-active guard, race-safe:** single statement
   `INSERT INTO tblWorkflowInstances (workflowID, siteID, tableName, recordID,
   currentStep, status, startedByID, currentStepStartedAt, subjectLabel, contextJson)
   SELECT ?,?,?,?,?,'pending',?,NOW(),?,? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM
   tblWorkflowInstances WHERE tableName=? AND recordID=? AND status IN
   ('pending','in_progress'))` — gated on `affected_rows === 1` (the
   `Payments::markPaymentSucceeded` discipline, `web/_core/Payments.php:1086-1092`).
   Under InnoDB REPEATABLE READ the NOT-EXISTS scan via `idx_wfi_record` takes the
   locks that make two concurrent starts serialise; `affected_rows === 0` ⇒ an
   active instance already exists ⇒ return null. `subjectLabel` truncated to 255;
   `contextJson` = `json_encode($context)` or NULL when empty.
4. `processAutoSteps()` (§3.5) in case step 1 is notification/auto.
5. If the (possibly advanced-past-autos) current step is approval/review:
   `notifyStepAssignees()` (§3.8) — post-commit, failure never rolls anything back.
6. `Logger::activity('WorkflowStarted', …)` + `Logger::audit('tblWorkflowInstances',
   $instanceId, 'create', null, [...])` (`web/_core/Logger.php:111` signature).

### 3.4 `act()` — exact behaviour (the core guard set)

All inside `$db->begin_transaction()` / commit / rollback-on-throw — the exact
shape shipped in `web/_apps/expenses/approve/save.php:141-163`:

1. **Load + lock:** `SELECT i.*, w.workflowKey, w.workflowName FROM
   tblWorkflowInstances i JOIN tblWorkflows w ON w.workflowID=i.workflowID WHERE
   i.instanceID=? AND i.siteID=? FOR UPDATE` with `Site::id()` — the siteID
   predicate is the IDOR wall: a cross-tenant instanceID behaves exactly like a
   nonexistent one (`not_found`).
2. **Status guard:** status not in ('pending','in_progress') ⇒ rollback,
   `not_active`.
3. **Current-step resolution:** `SELECT * FROM tblWorkflowSteps WHERE workflowID=?
   AND stepOrder=? ORDER BY stepID ASC LIMIT 1` (deterministic under the
   no-unique-key duplicate-order edge, §3.6). Resolved stepID ≠ posted `$stepId`
   ⇒ rollback, `stale_step` (the form was rendered before someone else advanced
   the instance — never act on a step the actor didn't see).
4. **Authorisation** — `canAct($step, $actorId, (int)$instance['siteID'])`:
   - actor must be an active member of the instance's site: `tblUserSites`
     row with `isActive=1` (full_schema:214-233) and `tblUsers.isActive=1`;
   - then one of:
     - `assigneeType='user'` → `(int)$step['assigneeValue'] === $actorId`;
     - `assigneeType='role'` → actor holds `roleKey = assigneeValue` via
       `tblUserRoles JOIN tblRoles` (the `App::hasRole` query shape,
       `web/_core/App.php:348-365`, but run for `$actorId` explicitly — hasRole()
       only checks the *session* user);
     - `assigneeType='group'` → `tblUserGroups` row with
       `groupID = (int)assigneeValue` (full_schema:344-356);
     - **admin override** (default on, `workflows.admin_override = '1'`): actor is
       a site admin for the instance's site (tblUserSites.isSiteAdmin/
       isSiteRootAdmin for that siteID, or tblUsers.isAdmin/isRootAdmin — the
       four-tier test of `App::isAdmin()`, App.php:388-398, evaluated against the
       instance's siteID, which under §4's flow always equals `Site::id()`).
   - Failure ⇒ rollback, `forbidden`. `comment` decisions require the same
     authorisation (an outsider must not write into the action log).
5. **Comment short-circuit:** decision 'comment' (requires non-empty comment) ⇒
   INSERT action row `commented` (needs the 174 enum widen), commit, return ok
   with no state change.
6. **Atomic claim** (approve/reject) — the no-double-advance / no-double-approve
   guard, even though FOR UPDATE already serialises (belt & braces, and it keeps
   the invariant if a future caller forgets the lock):
   - approve, non-final (a next `stepOrder > current` exists):
     `UPDATE tblWorkflowInstances SET status='in_progress', currentStep=?,
     currentStepStartedAt=NOW() WHERE instanceID=? AND currentStep=? AND status IN
     ('pending','in_progress')`;
   - approve, final / reject:
     `UPDATE tblWorkflowInstances SET status='completed', outcome=?,
     completedAt=NOW() WHERE instanceID=? AND currentStep=? AND status IN
     ('pending','in_progress')`;
   - `affected_rows !== 1` ⇒ rollback, `conflict`.
7. INSERT the action row (`approved`/`rejected`, comment, actedByID, actedAt) —
   *after* the claim so a lost race never leaves an orphan action.
8. **Terminal side effect inside the same transaction:** on outcome
   `applySubjectEffect($instance, $outcome, $actorId)` (§3.7) — for announcements
   an idempotent flag UPDATE; if it throws, the whole act() rolls back (approval
   and publish commit or fail together — no "approved but never published" ghost).
9. Commit. Then (post-commit, never inside the txn): `processAutoSteps()` +
   `notifyStepAssignees()` for the new current step, or `notifyOutcome()`;
   `Logger::activity('WorkflowAction…')` + `Logger::audit(...)` per §3.9;
   optional `WebhookDispatcher::emit('workflow.completed', …)` (§12 Q7).

### 3.5 `processAutoSteps()` — notification / auto / review steps

Loop (bounded by the definition's step count as a hard cap against cycles): while
the instance is active and its current step is:
- `stepType='notification'` → resolve assignees, send the "FYI" email, INSERT
  action row `skipped` with comment 'Notification-only step — auto-advanced'
  (actedByID NULL), advance via the same §3.4-6 atomic UPDATE (or complete+
  `onApproved` if it was the last step);
- `stepType='auto'` → apply `autoAction` (approve ⇒ advance/complete as above,
  recording `approved` actedByID NULL; reject ⇒ terminal `rejected`; escalate or
  NULL ⇒ treat as approve-with-warning? **No** — an 'auto' step whose autoAction
  is escalate/NULL is a definition error: record `skipped` with an explanatory
  comment and advance, plus `Logger::errorPlatform('Workflow','Warning',...)`);
- `stepType='review'` behaves **identically to 'approval'** in v1 (blocks awaiting
  a human) — documented in DEV_NOTES; the enum distinction is reserved.
Each iteration runs its own small transaction with the same atomic-claim guard.

### 3.6 Duplicate stepOrder edge

`tblWorkflowSteps` has no unique key on (workflowID, stepOrder); the admin CRUD
computes MAX+1 (`save.php:113-121`) so duplicates arise only from a double-submit
race. Rather than a new unique index (which could brick migration replay on a DB
where the race already happened), the engine resolves the current step
deterministically with `ORDER BY stepOrder ASC, stepID ASC LIMIT 1` everywhere,
and "next step" as `stepOrder > current` ordered the same way — duplicates thus
run sequentially by stepID, never fan out. Documented in the class header.

### 3.7 Subject-adapter registry — the generic⇄consumer boundary

Private `applySubjectEffect(array $instance, string $outcome, ?int $actorId)`:
a single `match ((string) $instance['workflowKey'])` with one arm per wired
consumer. v1 ships exactly one arm:

- `'announcement_publish'`:
  - outcome `approved` → `UPDATE tblAnnouncements SET isPublished=1, updatedByID=?
    WHERE announcementID=? AND siteID=? AND isDeleted=0` (idempotent — a re-run
    matches 0 rows only when already deleted, and publishing an already-published
    row is a harmless no-op; `updatedByID` = final approver, NULL-safe for
    timeout auto-approve). `affected_rows === 0` with the row soft-deleted is
    logged (`Logger::errorPlatform`, Warning) but does not abort — the workflow
    record still completes truthfully.
  - outcome `rejected` / `cancelled` → no subject write (stays unpublished).
- `default` → no-op (unknown keys complete with no side effect — an admin-created
  ad-hoc definition can be used as a pure sign-off trail).

The inbox "view" link comes from `contextJson['url']` (set by the consumer at
start()), falling back to nothing — the engine never builds consumer URLs itself.

### 3.8 Notifications (reuses the Mailer layer)

- `resolveAssignees(array $step, int $siteId): array` — returns
  `[userID => emailAddress]` for the step: role → the
  `resolveMilestoneDigestRecipients` query shape (active user + active
  tblUserSites membership for $siteId + role join,
  `web/_apps/cron/user-reminders.php:213-249`) for the single roleKey; user →
  that user if active + site member; group → tblUserGroups members ∩ active site
  members. Emails `FILTER_VALIDATE_EMAIL`-checked.
- "You have an approval waiting" email per assignee on step activation, and an
  outcome email to `startedByID` on completion — `Mailer::send()`
  (`web/_core/Mailer.php:62`), plain-English bodies with
  `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` on every interpolation, absolute
  links built the `userReminderUrl()` way (user-reminders.php:129-135). **No
  `I18n::t()` in email bodies** — the venue-cron missing-key trap
  (user-reminders.php:80-84).
- Honour `tblUsers.notifyPrefs` key **`approvalRequests`** (default ON, only an
  explicit false suppresses — clone `notifyPrefAllows()`,
  user-reminders.php:144-154). Add the pref to the `$defaults` array and a
  `$switchRow` in `web/_apps/auth/account/notifications.php` (defaults at 76-88,
  rows at ~181).
- Gated by global `workflows.notify_email` = '1' (seeded, §7). All sends are
  post-commit; a send failure logs and continues — mail must never block a state
  transition.

### 3.9 Audit

Every mutation writes both:
- `Logger::activity('WorkflowStarted'|'WorkflowActioned'|'WorkflowCompleted'|
  'WorkflowCancelled'|'WorkflowEscalated', description, $userId)`
  (`web/_core/Logger.php:48`) — attribution uses `Site::id()`, correct both in web
  requests and inside the cron's `Site::forceContext` loop;
- `Logger::audit('tblWorkflowInstances', $instanceId, 'create'|'update', $old,
  $new, $userId)` (Logger.php:111) on instance create + every status/currentStep
  change. The tblWorkflowActions rows themselves are the fine-grained decision
  log (who/what/when/comment) — that's their designed job.

---

## 4. Generic approvals inbox — `/approvals`

New infrastructure-style app dir `web/_apps/approvals/` + AppRegistry entry so it
surfaces in nav and /admin/apps.

### 4.1 Registration & gating

- `web/_core/apps/approvals.php` — registry file cloned from
  `web/_core/apps/tasks.php` shape: slug/route `approvals`, settingKey
  `approvals.enabled`, icon `fa-solid fa-stamp`, category `productivity`,
  `isCore => false`.
- **Must seed `approvals.enabled = 'true'` in the same migration** — Router 403s
  routes of a registered-but-unflagged app
  (`Router.php:120-127` → `AppRegistry::appForRoute`/`isEnabled`,
  `AppRegistry.php:95-116,164-183`; the PR #372 lesson: registering
  worship/salvation/kids without flags silently 403'd three live apps).
- Nav link appears automatically from the settings-driven loop
  (`_core/templates/nav.php:79-108`) once `approvals.enabled='true'` +
  `approvals.displayName`/`approvals.displayIcon` are seeded — zero nav.php edits.
- Routes seeded (tblRoutes, isProtected=1): `approvals` →
  `approvals/index.php`, `approvals/act` → `approvals/act.php`.

### 4.2 `web/_apps/approvals/index.php` — the inbox page

Frame: `Auth::ensureSession(); Auth::requireLogin();` (Auth.php:71,108) then the
standard header/footer template includes (the `admin/workflows/index.php:84-91`
pattern; `$pageSection = 'approvals'`).

Three sections, **no `<table>` — portal-data-list** throughout
(house rule; component usage precedent `admin/workflows/index.php:133-152`):

1. **"Awaiting your decision"** — `Workflow::actionableForUser($userId, Site::id(),
   App::isAdmin())`. Query: active instances for THIS site joined to their current
   step:
   ```
   SELECT i.instanceID, i.subjectLabel, i.contextJson, i.startedAt,
          i.currentStepStartedAt, i.startedByID, u.fullName AS startedByName,
          w.workflowName, s.stepID, s.stepName, s.stepOrder, s.timeoutHours,
          s.assigneeType, s.assigneeValue
   FROM tblWorkflowInstances i
   JOIN tblWorkflows w ON w.workflowID = i.workflowID
   JOIN tblWorkflowSteps s ON s.workflowID = i.workflowID AND s.stepOrder = i.currentStep
   LEFT JOIN tblUsers u ON u.userID = i.startedByID
   WHERE i.siteID = ? AND i.status IN ('pending','in_progress')
   ORDER BY i.currentStepStartedAt ASC, i.instanceID ASC, s.stepID ASC
   ```
   then PHP-side: collapse duplicate-stepOrder fan-out to first stepID per
   instance, and filter by actionability — precompute once the viewer's roleKey
   set (tblUserRoles JOIN tblRoles) and groupID set (tblUserGroups), then keep
   rows where user/role/group matches, or viewer is admin (admin sees ALL site
   instances, with a "Mine / All" filter toggle via `?filter=`; non-admins only
   ever see actionable rows). Uses the new `idx_wfi_site_status` (§7).
   Row renders: subjectLabel (+ "view" link from contextJson url,
   htmlspecialchars'd), workflow name, step name badge, started-by + waiting-since,
   a "due" hint when timeoutHours set (`currentStepStartedAt + timeoutHours` vs
   NOW), and the action controls.
2. **Action controls per row** — one POST form per row to `/approvals/act`:
   hidden `csrf_token` (`Auth::csrfToken()`, Auth.php:268, rendered exactly as
   `admin/workflows/index.php:109`), hidden `instanceID` + `stepID` (the stale-form
   guard token, §3.4-3), a comment `<input>`, Approve button, Reject button
   (`name="decision"` submit values), and a Comment-only button. The Reject form
   carries `data-confirm="Reject this request?"` — the house confirm pattern
   (`assets/js/portal-confirm.js:18`; native `confirm()` banned by
   `check_no_native_confirm.py`).
3. **History** — `Workflow::historyForSite(...)`: last 50 completed/cancelled
   instances for the site; non-admins see only instances they started or acted on
   (EXISTS on tblWorkflowActions.actedByID / startedByID); each row expandable
   (Bootstrap collapse) showing `actionsForInstance()` — the full timeline
   (step name, action badge, actor name or "System", comment, actedAt), site-scope
   re-checked inside the helper (`WHERE i.instanceID=? AND i.siteID=?`).

Empty state: friendly "Nothing awaiting your approval" alert-info (the
`admin/workflows/index.php:195-198` pattern).

### 4.3 `web/_apps/approvals/act.php` — the act handler

Clone the house POST-handler frame exactly (`admin/workflows/save.php:25-44`
order): method check → `Auth::requireLogin()` → **CSRF first**
(`Auth::verifyCsrf`, Auth.php:289) → input casting (`(int)` instanceID/stepID,
whitelist `decision ∈ {approve, reject, comment}`, trimmed comment, comment
required for reject — approver must say why — and for comment-only) → call
`Workflow::act($instanceId, $stepId, (int)$_SESSION['user_id'], $decision,
$comment)` → map the result to flash msg/type (`conflict` → warning "already
decided by someone else" — mirroring the expenses copy at
`expenses/approve/save.php:157-160`; `forbidden` → danger; ok → success incl.
"…and published" when `completed && outcome==='approved'`) → redirect
`/approvals`. **No authorisation logic in the handler** — the engine owns every
guard; the handler is transport only.

---

## 5. Reference-consumer wiring — Announcements publish approval

Per-site flag: `workflows.announcements.enabled`, seeded global-default `'false'`
(§7). Read in web handlers via `Settings::get('workflows.announcements.enabled',
'false')` (ambient snapshot is correct in a normal request; only forceContext
loops must use `App::settingForSite` — App.php:166-186).

### 5.1 `web/_apps/announcements/save.php` (changed)

After the existing CSRF/validation/slug blocks, before the INSERT/UPDATE branches:

- `$workflowGate = (Settings::get('workflows.announcements.enabled','false') === 'true');`
- **Create path** (line 112-144 today): when gate on AND `$isPublished === 1`:
  force `$isPublished = 0` before the INSERT (bind unchanged at 132-135), then
  after `$mysqli->insert_id` call `Workflow::start('announcement_publish',
  'tblAnnouncements', $announcementId, $userId, $title,
  ['url' => '/announcements/view?slug=' . $slug])`.
- **Update path** (line 78-111 today): first fetch the row's current
  `isPublished` (one extra site-scoped SELECT). Gate on AND posted
  `$isPublished === 1` AND stored `isPublished === 0` ⇒ this is a *publish
  request*: force `$isPublished = 0` in the UPDATE, then `Workflow::start(...)`.
  Already-published rows being edited, and unpublish (checkbox cleared), pass
  through **unchanged** — no approval needed to edit or retract in v1.
- `start()` returned an int ⇒ flash success "Announcement saved — publication
  submitted for approval." `start()` returned null:
  - because an active instance already exists (`activeInstanceForSubject` check
    first for a distinct message) ⇒ flash info "already awaiting approval";
  - because no active definition/steps exist ⇒ **fail-open** (§12 Q4): restore
    the direct publish (run the small `UPDATE … SET isPublished=1` for the row),
    flash warning "Approval workflow not configured — published directly", and
    `Logger::errorPlatform('Workflow','Warning','WF_MISCONFIG', …)` so admins see
    it in /admin/errors.
- Gate off ⇒ **not one line of today's behaviour changes** (the new branch is
  never entered; `$isPublished` flows exactly as at line 55 today).

### 5.2 `web/_apps/announcements/manage.php` (changed)

- When the gate is on, relabel the checkbox (line 152) "Published" →
  "Publish (requires approval)".
- List section: one batch query
  (`Workflow::activeInstanceForSubject` generalised to a
  `WHERE tableName='tblAnnouncements' AND recordID IN (...) AND status IN
  ('pending','in_progress')` map) to render an "Awaiting approval" badge beside
  unpublished rows with a running instance (next to the published badge at
  line 200), linking to `/approvals`.

### 5.3 `web/_apps/announcements/delete.php` (changed)

After the soft-delete UPDATE (line 60): `Workflow::cancelForSubject(
'tblAnnouncements', $announcementId, 'Announcement deleted', $userId)` — a
deleted announcement never lingers in anyone's inbox. (cancelForSubject is
site-safe by construction: it resolves the instance by subject and still applies
its transition under the §3.4 guards.)

### 5.4 Admin CRUD completion (changed/new, small)

- `web/_apps/admin/workflows/index.php`: per-step Delete button (POST form to the
  new route, data-confirm), an isActive toggle on the workflow form, an
  `autoAction` select (approve/reject/escalate/—) on the Add Step row, and an
  info box explaining engine wiring: which workflowKeys are engine-wired
  (`announcement_publish`) vs. sign-off-trail-only, and that `expense_approval`
  is dormant because Expenses has its own approval system.
- `web/_apps/admin/workflows/save.php`: persist `isActive` (0/1) on
  create/update; persist `autoAction` (whitelist + NULL) on step insert
  (bind arity check: the INSERT at 123-130 grows by one `s`).
- **New** `web/_apps/admin/workflows/step-delete.php` (+ route
  `admin/workflows/step-delete`): POST-only, admin, CSRF; verify the step's
  workflow belongs to `Site::id()` (the save.php:88-106 ownership probe); refuse
  when the parent workflow has active instances (flash error); DELETE the step
  wrapped in try/catch for `\mysqli_sql_exception` — the RESTRICT FK from
  tblWorkflowActions.stepID (§1.1) turns "step has history" into a caught,
  friendly refusal. Renumbering is not needed (ordering is relative; MAX+1
  append still works).

---

## 6. Timeout cron — `web/_apps/cron/workflow-timeouts.php` (new)

Clone `web/_apps/cron/user-reminders.php` structurally — it is the newest and
most heavily documented cron and encodes every house discipline:

- **Token gate first** (user-reminders.php:108-113): `?key=` vs
  `Settings::get('workflows.cron_token','')`, `hash_equals`, **empty stored token
  ⇒ always 403** (fails closed; seeded empty + `isSensitive=1`, §7). Route
  seeded `isProtected=0` (public-but-token-gated, the 171:104-106 precedent).
  Dedicated token — the shipped one-token-per-endpoint rotation convention
  (user-reminders.php:24-30).
- Global kill-switch `workflows.enabled` ≠ '0' check, then loop every active site
  (`tblSites.isActive=1`), `Site::forceContext($siteId)` in try/catch-continue
  (user-reminders.php:289-304), **per-site settings read inside the loop via
  `App::settingForSite()`** (the frozen-snapshot trap, user-reminders.php:42-51)
  — though v1 has no per-site timeout tunables, the frame is kept so adding one
  never reintroduces the bug.
- Per site: call `Workflow::timeoutSweep($siteId)`. The sweep query:
  ```
  SELECT i.instanceID, i.currentStep, i.currentStepStartedAt, i.subjectLabel,
         s.stepID, s.stepName, s.timeoutHours, s.autoAction, s.stepOrder
  FROM tblWorkflowInstances i
  JOIN tblWorkflows     w ON w.workflowID = i.workflowID
  JOIN tblWorkflowSteps s ON s.workflowID = i.workflowID AND s.stepOrder = i.currentStep
  WHERE i.siteID = ? AND i.status IN ('pending','in_progress')
    AND s.timeoutHours IS NOT NULL
    AND i.currentStepStartedAt <= DATE_SUB(NOW(), INTERVAL s.timeoutHours HOUR)
  ORDER BY i.instanceID ASC, s.stepID ASC
  ```
  (PHP-side first-stepID-per-instance collapse, §3.6.) Per overdue step:
  - `autoAction='approve'` / `'reject'` → the internal system transition — same
    atomic claim + transaction as act() but skipping canAct(), `actedByID=NULL`,
    comment `'Auto-approved after 72h timeout'` — a concurrent human decision
    losing/winning the race is naturally absorbed by `affected_rows === 1`.
  - `autoAction='escalate'` **or NULL** → escalation, **once per (instance, step)**:
    skip if `SELECT 1 FROM tblWorkflowActions WHERE instanceID=? AND stepID=? AND
    action='escalated'` hits; else INSERT the `escalated` action row (the dedupe
    marker — same claim-even-when-suppressed philosophy as
    user-reminders.php:68-71), then email the site's admins (tblUserSites
    isSiteAdmin/isSiteRootAdmin + umbrella admins) and re-email the step
    assignees. Instance stays active awaiting a human — fail-safe.
- Per-site `Logger::activity('WorkflowTimeoutSweep', 'checked=N auto=N esc=N')`
  (attribution correct inside forceContext, user-reminders.php:570-580), no
  context reset after the loop (582-584), grand-total `text/plain` summary echo
  (586-598). **Hourly** external cadence (timeoutHours granularity is hours).

---

## 7. Migration — `web/_sql/174_workflow_engine_execution.sql` (reserve 174)

Numbering: 171 (user-reminders) is merged on `alpha` (`web/_sql/171_user_reminders.sql`);
172 is reserved by in-flight `claude/gap4-bulk-statements`, 173 by in-flight
`claude/gap6-serviceplan-bridge` (neither pushed yet — `git ls-remote --heads
origin` currently shows only `claude/venue-*`/dependabot; 168/169 are likewise
absent from `alpha`). **Re-confirm at build time with `git ls-remote --heads
origin` + `ls web/_sql/` and take the next free number ≥ 174.**

File header carries the 171-style notes, including the **`--`-inside-strings
parity-checker trap** (171:30-32 — use an em dash in comments/COMMENT strings).
Every DDL statement uses the `information_schema` + `PREPARE/EXECUTE` guard idiom
exactly as `web/_sql/112_*.sql:28-37` (house template; MySQL 8.0 rejects
MariaDB's `IF NOT EXISTS` on ALTER — ERROR 1064). Everything replays as a no-op.

**A. Guarded ALTERs on `tblWorkflowInstances`** (each justified; all additive):
1. `outcome ENUM('approved','rejected','cancelled') DEFAULT NULL AFTER status` —
   the 034 status enum has no 'rejected'; widening a *status* enum in place is
   riskier than recording the terminal disposition orthogonally, and
   completed-vs-outcome also cleanly distinguishes "completed approved" from
   "completed rejected" in history queries.
2. `currentStepStartedAt DATETIME DEFAULT NULL AFTER currentStep` — the timeout
   sweep needs "when did the current step become active"; deriving it from
   MAX(actedAt) is wrong once comments exist. Backfill (idempotent):
   `UPDATE tblWorkflowInstances SET currentStepStartedAt = startedAt WHERE
   currentStepStartedAt IS NULL;` (plain DML, replays as 0-row no-op).
3. `subjectLabel VARCHAR(255) DEFAULT NULL AFTER recordID` — inbox display
   snapshot; without it the inbox would need per-tableName joins into arbitrary
   consumer tables.
4. `contextJson TEXT DEFAULT NULL AFTER subjectLabel` — start() context (subject
   URL etc.); TEXT not JSON type (matches house usage, e.g.
   tblNewsletterSegment.ruleJson, full_schema:3728).

**B. Guarded index:** `idx_wfi_site_status (siteID, status)` on
tblWorkflowInstances (guard via information_schema.STATISTICS) — the inbox and
sweep both filter `siteID = ? AND status IN (…)`; existing `idx_wfi_status` is
status-only.

**C. Guarded enum widen on `tblWorkflowActions.action`** — add `'commented'`:
guard on `information_schema.COLUMNS.COLUMN_TYPE NOT LIKE '%''commented''%'`,
then `MODIFY COLUMN action ENUM('approved','rejected','escalated','skipped','commented') NOT NULL`
via PREPARE/EXECUTE. Pure superset — existing values untouched. This is the only
enum change the design needs (timeout auto-actions reuse 'approved'/'rejected'
with actedByID NULL; escalation uses the existing 'escalated'; notification/auto
bookkeeping uses the existing 'skipped').

**D. Seeds** (all idempotent):
- Role: `announcement_approver` / 'Announcement Approver' via the
  `INSERT … SELECT … WHERE NOT EXISTS` role idiom (full_schema:4895-4897).
- Definition: `('Announcement Publish Approval','announcement_publish')` for
  siteID=1, `ON DUPLICATE KEY UPDATE workflowName=VALUES(workflowName)`
  (uq_workflow_key_site makes this safe — 034:90-92 precedent). Then its ONE
  step — **tblWorkflowSteps has no unique key, so the seed must be
  `INSERT … SELECT w.workflowID, 1, 'Approve publication', 'approval', 'role',
  'announcement_approver', NULL, 72 FROM tblWorkflows w WHERE w.workflowKey =
  'announcement_publish' AND w.siteID = 1 AND NOT EXISTS (SELECT 1 FROM
  tblWorkflowSteps s WHERE s.workflowID = w.workflowID AND s.stepOrder = 1)`**
  (columns: workflowID, stepOrder, stepName, stepType, assigneeType,
  assigneeValue, autoAction=NULL ⇒ timeout escalates, timeoutHours=72).
  Other sites: admins clone at /admin/workflows (definitions are per-site by
  design; the flag is also per-site, and §5.1's fail-open covers a site enabling
  the flag before creating a definition).
- Settings (siteID NULL, `(siteID, settingKey, settingValue, defaultValue,
  isSensitive)` shape + `ON DUPLICATE KEY UPDATE defaultValue=VALUES(defaultValue)`
  — 171:86-93 template):
  `workflows.cron_token` '' '' **1**; `workflows.enabled` '1'; 
  `workflows.notify_email` '1'; `workflows.admin_override` '1';
  `workflows.announcements.enabled` 'false';
  `approvals.enabled` 'true'; `approvals.displayName` 'Approvals';
  `approvals.displayIcon` 'fa-solid fa-stamp'
  (displayName/Icon precedent full_schema:2178+; **every key any PHP reads must
  be seeded** — check_settings_keys.py).
- Routes (`ON DUPLICATE KEY UPDATE targetFile=VALUES(targetFile)`):
  `approvals` → `approvals/index.php` (1); `approvals/act` → `approvals/act.php`
  (1); `admin/workflows/step-delete` → `admin/workflows/step-delete.php` (1);
  `cron/workflow-timeouts` → `cron/workflow-timeouts.php` (**0** — token-gated,
  171:104-106 precedent). No `api/*` routes anywhere in this feature — the
  ApiRouter trap never comes into play (deliberately: the inbox is session-only
  in v1).
- Self-record: `INSERT INTO tblMigrations (filename) VALUES
  ('174_workflow_engine_execution.sql') ON DUPLICATE KEY UPDATE
  filename=filename;` (171:118-119).

**E. `full_schema.sql` fold** (schema/seed parity is CI-checked —
check_schema_seed_parity.py + check_sql_columns.py):
- Edit the `tblWorkflowInstances` CREATE TABLE (line 1252 block): add the four
  columns + the new index inline.
- Edit the `tblWorkflowActions` CREATE TABLE (line 1276 block): the widened enum.
- Append the 174 seed block (role, definition + step, settings, routes) in the
  migration-appendix section, mirroring the 171 fold.

**The four tables suffice** — no new tables; the four ALTER columns + one enum
value + one index are everything, each justified above.

---

## 8. File list (exact)

**New (8):**
| File | Purpose |
|---|---|
| `web/_core/Workflow.php` | The engine: start/act/cancelForSubject/timeoutSweep + private advance/complete/processAutoSteps/canAct/resolveAssignees/notify/adapter (§3) |
| `web/_apps/approvals/index.php` | Inbox: actionable queue + admin all-view + history timeline (§4.2) |
| `web/_apps/approvals/act.php` | CSRF'd POST act handler → Workflow::act (§4.3) |
| `web/_core/apps/approvals.php` | AppRegistry entry (nav + /admin/apps toggle + Router enable-gate) (§4.1) |
| `web/_apps/admin/workflows/step-delete.php` | FK-aware step deletion, site-ownership + active-instance guarded (§5.4) |
| `web/_apps/cron/workflow-timeouts.php` | Token-gated hourly per-site timeout sweep (§6) |
| `web/_sql/174_workflow_engine_execution.sql` | Migration per §7 |
| `web/_lang/` — *no new keys required* (page copy is literal-English like the existing admin/workflows page; explicitly NOT using I18n in emails) |

**Changed (9):**
| File | Change |
|---|---|
| `web/_apps/announcements/save.php` | Gate + Workflow::start on publish request; fail-open fallback (§5.1) |
| `web/_apps/announcements/manage.php` | "Awaiting approval" badge + checkbox relabel when gated (§5.2) |
| `web/_apps/announcements/delete.php` | cancelForSubject hook after soft delete (§5.3) |
| `web/_apps/admin/workflows/index.php` | Step-delete buttons, isActive toggle, autoAction input, wiring info box (§5.4) |
| `web/_apps/admin/workflows/save.php` | Persist isActive + autoAction (bind-arity updated) (§5.4) |
| `web/_apps/auth/account/notifications.php` | `approvalRequests` pref default + switch row (§3.8) |
| `web/_sql/full_schema.sql` | Fold per §7-E |
| `FEATURES.md`, `CHANGELOG.md`, `DEV_NOTES.md` | Feature entry; changelog stamp; DEV_NOTES: engine contract, adapter extension recipe, 'review'≡'approval' note, expense_approval-is-dormant note, cron setup |
| `.claude/CLAUDE.md` | Recent-ships entry + migration count |

(Plus the standing-instruction GitHub issue create/close and Wiki update at build
time — process, not files.)

---

## 9. House-convention checklist (each item verified against a live precedent)

- `declare(strict_types=1)` + full file-header block in every new/changed file.
- Full IF notation (`if ($x === true)`), platform-neutral paths
  (`DIRECTORY_SEPARATOR` in template requires — admin/workflows/index.php:90),
  emoji section comments.
- MySQLi prepared statements only; **hand-verify every bind_param type-string
  arity** (check_bind_param_arity.py is a hard CI gate; two fatal arity bugs were
  just fixed in fbc772e) — the changed admin/workflows/save.php step INSERT grows
  to `'iisssssi'`-class signatures: count twice.
- `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` on every output including email
  bodies and subjectLabel/contextJson-url renders.
- CSRF-first on every POST (`Auth::verifyCsrf` before any state read —
  admin/workflows/save.php:39 order), method check before login redirect.
- Site scoping on every instance/definition query (`siteID = Site::id()` in web
  handlers; explicit `$siteId` params inside the engine/cron).
- Atomic transitions: `affected_rows === 1` guarded UPDATEs
  (Payments.php:1086) inside `begin_transaction` + `FOR UPDATE`
  (expenses/approve/save.php:141-163).
- Cron gating: `hash_equals`, empty-token-403s, `isSensitive=1` seed, route
  `isProtected=0`, `Site::forceContext` try/catch-continue,
  `App::settingForSite` inside the site loop, no I18n in emails, plain-text
  greppable summary (all: cron/user-reminders.php).
- No `<table>` — portal-data-list only; `data-confirm` not native confirm().
- MySQL-8-safe guarded DDL (112-style PREPARE/EXECUTE); migration replays as
  no-op; self-record; full_schema fold; no `--` inside SQL strings.
- **No `api/*` surface added** — ApiRouter trap avoided entirely by design.
- `_core` code uses `App::db()`; controllers may use the Router-injected
  `$mysqli` (Router.php:138).

---

## 10. Security checklist

1. **Step authorisation** — only user/role/group-matched actors (each additionally
   required to be an active member of the *instance's* site via tblUserSites) or
   site admins (flag-controlled) can act; enforced inside `Workflow::act()` under
   row lock, never in the HTTP layer (§3.4-4).
2. **No cross-tenant access (IDOR)** — every instance load carries
   `AND siteID = Site::id()`; a foreign instanceID is indistinguishable from a
   missing one; inbox lists only `Site::id()` rows; `actionsForInstance`
   re-checks site on the detail query (§4.2).
3. **CSRF** — `/approvals/act`, `admin/workflows/step-delete`, and all changed
   POST handlers verify the session token before anything else.
4. **No unauthorised publish path** — the ONLY place the engine writes
   `isPublished=1` is the adapter arm inside the same transaction as the final
   approval claim; the manual path stays admin-only + CSRF'd as today; with the
   gate on, save.php forces `isPublished=0` on publish requests so a crafted
   POST cannot self-publish past the workflow (and with the gate off, behaviour
   is precisely today's admin-only toggle).
5. **Idempotent/atomic transitions** — double-approve/double-advance/
   approve-vs-reject races collapse to one winner via
   `WHERE currentStep=? AND status IN (…)` + `affected_rows === 1`; the publish
   side effect is inside that transaction, so double-publish is impossible and
   approve-without-publish cannot be observed; `start()` duplicate-guard is a
   single conditional INSERT; terminal states are immutable.
6. **Stale-form guard** — posted stepID must equal the server-resolved current
   stepID (§3.4-3).
7. **Timeout cron fails closed** — empty `workflows.cron_token` ⇒ 403 always;
   constant-time compare; escalation is the default timeout behaviour (never
   auto-act unless a step explicitly opts in via autoAction).
8. **Audit everything** — tblWorkflowActions row per decision (system actions
   actedByID NULL + explanatory comment) + Logger::activity + Logger::audit on
   every instance mutation (§3.9).
9. **Output escaping** everywhere incl. email interpolations; comment TEXT is
   stored raw, escaped on render.
10. **Enumeration hygiene** — act() error messages don't reveal whether a foreign
    instance exists ('not_found' for both).

---

## 11. Acceptance gates

1. `php -l` clean on every touched PHP file (zero warnings).
2. All **11** audit checks green (`tools/audit-checks/`: bind_param_arity,
   cdn_sri, mariadb_only_ddl, migration_idempotency, mobile_readiness,
   no_native_confirm, php_table_refs, route_targets, schema_seed_parity,
   settings_keys, sql_columns) — note route_targets requires every seeded route's
   handler file to exist in the same commit, and php_table_refs requires the
   full_schema fold to land with the PHP.
3. Migration 174 applies on an up-to-date schema AND **replays as a no-op**
   (double-apply in the e2e-migrations harness: second run zero errors, zero
   diffs); fresh-install path (full_schema + replay-all) also clean.
4. **E2E happy path:** flag `workflows.announcements.enabled='true'` for site 1;
   grant a user `announcement_approver`; admin creates an announcement with
   "Publish" ticked ⇒ row saved `isPublished=0` + instance `pending` + approver
   email; approver sees it at `/approvals`, approves ⇒ instance
   `completed/approved`, announcement `isPublished=1`, visible at
   `/announcements` + dashboard pinned query; two action-log rows tell the story.
5. **Unauthorised actor rejected:** a logged-in site member without the role
   POSTs a forged act (valid CSRF, correct instanceID/stepID) ⇒ 'forbidden'
   flash, no action row, no state change; a user from another site with the
   instanceID ⇒ 'not_found'; a stale stepID ⇒ 'stale_step'.
6. **Double-decision race:** two simultaneous approve POSTs ⇒ exactly one action
   row, one transition, loser gets the 'conflict' flash; publish UPDATE ran once.
7. **Flag-off regression:** with `workflows.announcements.enabled` unset/'false',
   create + publish + unpublish + delete announcements behave byte-for-byte as
   today (no instance rows created); with `approvals.enabled='false'` the
   `/approvals` routes render the app-disabled page and nav hides the link.
8. **Timeout paths:** step with timeoutHours=1, autoAction NULL ⇒ sweep (correct
   token) records exactly one 'escalated' row across two consecutive runs +
   admin email; autoAction='approve' variant ⇒ auto-advance/publish with
   actedByID NULL; wrong/empty token ⇒ 403, nothing swept.
9. **Cancel path:** deleting an announcement with a running instance ⇒ instance
   `cancelled`, gone from the inbox.
10. **Dormant-seed check:** `expense_approval` still zero steps, zero instances;
    expenses submit/approve E2E untouched and green.

---

## 12. Open questions (each with a recommended default — proceed on defaults)

1. **Reference consumer.** → **Announcements publish approval** (per §1.5: pure
   editorial, reversible, single-boolean gate, all read paths already filter
   `isPublished=1`). Documents publish is the named v2 candidate.
2. **Timeout behaviour when `autoAction` is NULL (incl. the seeded step).** →
   **Escalate-only** (notify site admins + assignees once, keep waiting). Never
   auto-approve by default — auto-acting is opt-in per step via
   autoAction='approve'/'reject'. Seeded step: 72h, escalate.
3. **`delegate` verb.** → **Defer to v2.** The 034 action enum has no
   'delegated', delegation needs target-picking UI + its own authorisation
   surface; v1 ships approve/reject/comment (comment via the one-value enum
   widen). Note in DEV_NOTES as the designed next verb.
4. **Flag on but definition missing/step-less at publish time.** → **Fail-open**:
   publish directly + warning flash + platform-error log (§5.1). Rationale: this
   is an editorial convenience gate, not a security boundary; silently blocking
   all publishing on a half-configured site is the worse failure. Flip to
   fail-closed later by policy if a consumer is ever compliance-critical.
5. **Site admins may act on any step (`workflows.admin_override`).** → **Default
   on** ('1'). Matches the platform's 4-tier admin philosophy and prevents
   deadlock when a role has no holders; every action is attributed + audited
   anyway. Sites wanting strict separation set it to '0'.
6. **`approvals.enabled` default.** → **'true'** (nav-discoverable inbox;
   empty state is one cheap query; Router/AppRegistry gate lets any site hide
   it). The *consumer* flag stays default-off, so nothing flows into the inbox
   until a site opts in.
7. **Outbound webhooks for workflow events.** → **Emit
   `WebhookDispatcher::emit('workflow.completed', {...})`** on terminal
   transitions only (one line, fire-and-forget, WebhookDispatcher.php:71
   contract); skip started/acted events in v1.
8. **Seeded `expense_approval` row.** → **Leave completely untouched** (dormant
   by construction; explained in the admin info box + DEV_NOTES). No deletion
   (FK/parity churn), no steps seeded (would invite double-approval confusion
   with the real expenses flow).
9. **Migration number.** → **174**, re-verified against `git ls-remote --heads
   origin` + `ls web/_sql/` immediately before commit (172 gap4 / 173 gap6 are
   in flight and unpushed; if either lands renumbered, take the next free and
   update the self-record + fold header).
