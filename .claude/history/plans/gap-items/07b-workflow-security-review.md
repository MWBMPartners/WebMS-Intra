# Adversarial Security Review — Workflow Execution Engine (#443)

Branch: `claude/gap7-workflow-engine` @ `ad2aaa3`
Scope: `Portal\Core\Workflow` engine, `/approvals` inbox + act handler, timeout cron,
announcement-publish reference consumer, admin definition CRUD, notifications pref,
migration 174 + full_schema fold.
Method: read-only, every finding cited to real code at file:line and independently
re-derived (comments/docs not trusted).

---

## VERDICT

**The engine's own core controls are sound** — atomic `FOR UPDATE` + `affected_rows===1`
claim, in-`act()` authorisation under the lock, site-scoped IDOR wall, stale-step guard,
CSRF, prepared statements, correct bind_param arity, idempotent guarded migration. An
attacker **cannot** approve a step they are not the current approver for, cannot act
cross-tenant, cannot double-approve, and cannot double-publish through the engine.

**BUT two HIGH-severity flaws break the feature's headline security claim** ("an
announcement cannot be published EXCEPT through final approval"):

1. **A rejection at a non-final step does NOT terminate the workflow — it advances to
   the next step** (`claimTransition()` never branches on approve-vs-reject). In any
   multi-step definition a later approval then overrides the rejection and publishes.
   The shipped single-step seed is not affected, but multi-step is a first-class,
   admin-configurable use of this generic engine (and of the announcement_publish
   definition itself).
2. **The Announcements REST API (`api/create.php`, `api/update.php`, both enabled by
   default) writes `isPublished=1` directly with no workflow gate** — a full
   publish-around-the-workflow path for any `announcements:write` bearer key or admin
   session.

Plus one LOW fail-open race. Verdict: **NOT ready to ship as an authz-critical publish
gate** until findings SEC-01 and SEC-02 are fixed.

---

## FINDINGS

| ID | Sev | Conf | Location | Title |
|----|-----|------|----------|-------|
| SEC-01 | HIGH | CONFIRMED | `web/_core/Workflow.php:770-790` | Reject at a non-final step advances instead of terminating; a later approval overrides the rejection and publishes |
| SEC-02 | HIGH | CONFIRMED | `web/_apps/announcements/api/create.php:43,78-83` / `api/update.php:92-96` | Announcements REST write API (default-enabled) publishes directly, bypassing the approval workflow entirely |
| SEC-03 | LOW | PLAUSIBLE | `web/_apps/announcements/save.php:238-262` | Fail-open publish on a concurrent double-submit race publishes around an already-running approval |
| INFO-04 | INFO | CONFIRMED | `web/_apps/approvals/index.php:48-61` + `Workflow.php:516-518` | Inbox shows admins rows they cannot act on when `workflows.admin_override='0'` (display-only, `act()` still enforces) |

---

## SEC-01 — Reject at a non-final step advances instead of terminating (HIGH / CONFIRMED)

`web/_core/Workflow.php:281-282` (act) and the shared claim:

```php
// act() — line 281-282
$word = $decision === 'reject' ? 'rejected' : 'approved';
$txResult = self::claimTransition($db, $instance, $step, $word, $word, $actorId, $comment);
```

```php
// claimTransition() — line 768-790
$currentStepOrder = (int) $instance['currentStep'];
$nextStep = self::resolveNextStep((int) $instance['workflowID'], $currentStepOrder);

if ($nextStep !== null) {                       // ← a next step exists…
    $newStepOrder = (int) $nextStep['stepOrder'];
    $upd = $db->prepare(
        "UPDATE tblWorkflowInstances SET status = 'in_progress', currentStep = ?, ..."
        . "WHERE instanceID = ? AND currentStep = ? AND status IN ('pending','in_progress')"
    );
    ...
    self::recordAction($db, $instanceId, (int) $step['stepID'], $actionWord, $comment, $actorId);
    return ['ok' => true, 'error' => null, 'completed' => false, 'outcome' => null];   // ← ADVANCES
}
// Final step only — this is the ONLY place status becomes 'completed'/outcome set
```

`claimTransition()` decides advance-vs-terminate **solely on whether a next step
exists** — it never inspects `$actionWord`/`$terminalOutcome`. So an `act('reject')`
on a step that is **not** the last step records a `'rejected'` action row and then
*advances the instance to the next approver* (status `in_progress`) instead of
completing it `rejected`. The identical bug affects the timeout auto-reject path
(`timeoutAutoAct()` → `claimTransition()`, line 681-683) and the `'auto'` step with
`autoAction='reject'` (`processAutoSteps()` → `runClaim()`, line 888-890).

This directly contradicts the designed state machine (plan §3.1: "`act(reject)` — any
step → `completed`, outcome=`rejected`") and the class header (line 16-17).

**Attacker / abuse path (multi-step announcement_publish):**
1. An admin adds a second approval step to the `announcement_publish` definition at
   `/admin/workflows` (fully supported — the "Add Step" row appends at MAX(stepOrder)+1;
   the definition is a normal editable row, nothing pins it to one step).
2. Author submits a publish → instance at step 1.
3. Approver A **rejects** at step 1. Intended result: request closed, never published.
   Actual result: instance advances to step 2, `isPublished` still 0, a `'rejected'`
   action row logged but ignored.
4. Approver B **approves** at step 2 (final) → `claimTransition()` final branch →
   `applySubjectEffect()` sets `isPublished=1`. **The announcement A rejected is now
   published.**

A recorded rejection is silently overridable by a downstream approval — i.e. an
announcement can be *wrongly published despite a rejection*. For a generic,
definition-driven approval engine whose whole purpose is gating, this is a
correctness-of-authorisation flaw, not a cosmetic one.

**Why it slipped:** no multi-step E2E was run (see builder-item verdicts). The seeded
reference definition is single-step, where reject *is* final and the bug is dormant.

**Fix:** in `claimTransition()` (or in `act()` before calling it), branch on the
outcome — a `'rejected'` (or `'cancelled'`) terminalOutcome must ALWAYS take the
"complete the instance" path (`status='completed', outcome='rejected'`) regardless of
whether a later step exists; only `'approved'` should consult `resolveNextStep()`.
E.g. pass an explicit `$isTerminalDecision` flag, or gate the advance branch on
`$terminalOutcome === 'approved' && $nextStep !== null`.

---

## SEC-02 — Announcements REST write API bypasses the workflow gate (HIGH / CONFIRMED)

The workflow publish gate is wired **only** into the HTML handler
`announcements/save.php`. The REST write endpoints set `isPublished` directly with no
gate, and are **enabled by default** (`full_schema.sql:4590-4592` seed
`api.announcements.{create,update,delete}.enabled = 'true'`).

`web/_apps/announcements/api/create.php:43,78-83`:

```php
$isPublished = isset($body['isPublished']) === false || (bool) $body['isPublished'] === true ? 1 : 0;
...
$stmt = $db->prepare('INSERT INTO tblAnnouncements (... isPublished ...) VALUES (...)');
$stmt->bind_param('issssisiii', ..., $isPublished, $creatorId);   // publishes directly, default 1
```

`web/_apps/announcements/api/update.php:92-96`:

```php
if (array_key_exists('isPublished', $body) === true) {
    $updates[] = 'isPublished = ?';
    $params[]  = (bool) $body['isPublished'] === true ? 1 : 0;   // flips 0→1 with no gate
}
```

Neither handler references `Portal\Core\Workflow`, `workflows.announcements.enabled`,
or `activeInstanceForSubject`. Auth is `ApiAuth::requireWrite('announcements:write')`
(`ApiAuth.php:195`): a **bearer key with `announcements:write` scope** publishes with
no CSRF and no admin requirement; a **session** caller needs admin + CSRF.

**Attacker path:** a site enables the approval workflow
(`workflows.announcements.enabled=true`), believing publish now requires an approver.
Any holder of an `announcements:write` API key — a distinct, potentially non-admin,
non-`announcement_approver` principal — issues:

```
POST /api/announcements/create      { "title":"…","body":"…","isPublished":true }
   (or POST /api/v1/announcements — same handler via ApiRouter::dispatchV1)
```

and the announcement is published live to `/announcements`, `/announcements/view`, and
the dashboard pinned query, with **zero approval**. `update.php` does the same to an
existing draft the workflow is currently holding at `isPublished=0`.

This falsifies the feature's stated guarantee (plan §10.4 / DEV_NOTES / CHANGELOG:
"an announcement cannot be published EXCEPT through final approval"). The engine is
fine; the **consumer mediation is incomplete** — the gate must sit at every
publish entry point, not only the HTML form.

Precondition (why HIGH not CRITICAL): the announcements write API must be enabled
(it is, by default) and the caller must hold `announcements:write` (bearer) or be an
admin (session). But that is exactly the population the dual-control gate is meant to
constrain, so the bypass is squarely on-target.

**Fix:** apply the same gate inside `api/create.php` and `api/update.php` — when
`workflows.announcements.enabled` is on and the request would set/flip `isPublished`
to 1, withhold the flag and `Workflow::start('announcement_publish', …)` (mirroring
save.php), or reject the publish field with a 409/422 pointing at the approval flow.
The `announcements:write` scope should not include "publish past the workflow."

---

## SEC-03 — Fail-open on a concurrent double-submit publishes around approval (LOW / PLAUSIBLE)

`web/_apps/announcements/save.php:213-262` (`announcementsHandlePublishRequest`):

```php
$already = Workflow::activeInstanceForSubject('tblAnnouncements', $announcementId);
if ($already !== null) { /* "already awaiting approval" — no publish */ return; }

$instanceId = Workflow::start('announcement_publish', 'tblAnnouncements', $announcementId, ...);
if ($instanceId !== null) { /* submitted for approval */ return; }

// 🛟 Fail-open: ANY null after the pre-check ⇒ publish directly
$pubStmt = $mysqli->prepare('UPDATE tblAnnouncements SET isPublished = 1 WHERE announcementID = ? AND siteID = ?');
```

`Workflow::start()` returns `null` for three distinct reasons (no active definition,
zero-step definition, **or an active instance already exists** — the duplicate-active
guard at `Workflow.php:155-183`). The caller pre-checks `activeInstanceForSubject()`
to peel off the "already active" case, then treats every remaining `null` as
"misconfigured → fail open → publish directly." Those two states are indistinguishable
at the return value, so a **TOCTOU race** collapses them:

1. Requests A and B (both admins editing the same announcement) each read
   `activeInstanceForSubject()` → null.
2. A calls `start()` → creates the instance, returns an id → "submitted for approval."
3. B calls `start()` → duplicate-active guard fires, `affected_rows===0`, returns
   `null` → **fail-open → `isPublished=1` directly**, while A's approval sits pending.

The announcement is published without approval despite a running workflow instance.
Narrow (needs two simultaneous publish submits for the same row, both admin-only) and
self-inflicted, hence LOW — but it is a real conflation of "duplicate active" (must
not publish) with "no definition" (may fail open).

**Fix:** have `start()` distinguish its null reasons (e.g. return a small result with a
reason, or expose a separate "definition exists?" probe) so the caller only fails open
when a definition genuinely does not exist, and treats the duplicate-active race like
the pre-checked case ("already awaiting approval").

---

## INFO-04 — Admin inbox shows non-actionable rows when admin_override is off (INFO)

`approvals/index.php:48` calls `actionableForUser($userId, $siteId, $isAdmin)`, and
`Workflow.php:516-518` returns **every** active site instance to an admin regardless of
the `workflows.admin_override` setting, whereas `canAct()` (line 983-988) refuses an
admin who is not the assignee when `workflows.admin_override='0'`. Result: with the
override off, an admin sees rows in the inbox that `act()` will reject as `forbidden`.
Display-only inconsistency — no authorisation is granted (the engine re-checks on every
POST). Not a security defect; worth a one-line note.

---

## PER-CATEGORY CLEAN STATEMENTS (with evidence)

**Authorisation inside `act()` — CLEAN.** `act()` (Workflow.php:242-293) runs
`begin_transaction()` → `loadLockedInstance()` (`FOR UPDATE`, site-scoped) → status
guard → stale-step guard → `canAct()` **before** any state change, all inside the txn.
`canAct()` (932-989) requires an active `tblUserSites` membership for the instance's own
site (937-949) AND a role/user/group match on the CURRENT step (951-977) OR a site-admin
under `workflows.admin_override` (983-988, default-on, honoured via `settingForSite`'s
global-fallback, App.php:166-186). The HTTP handler `act.php` holds no authz — it is
CSRF + input-whitelist + `Workflow::act()` only (act.php:32-68). Approving a
non-assigned step, a stale/forged stepID, a terminal instance, or via the handler are
all blocked (`forbidden` / `stale_step` / `not_active`).

**Cross-tenant IDOR — CLEAN.** Every instance load is site-scoped:
`loadLockedInstance` (1376-1392) `WHERE i.instanceID=? AND i.siteID=?`; `actionsForInstance`
(597-606) re-checks `i.siteID=?`; `actionableForUser`/`historyForSite` filter
`i.siteID=?` (494, 558, 573). A foreign `instanceID` returns `not_found` — identical to
missing (act.php maps both to the same message) — no enumeration signal. Admin
`/admin/workflows` and `step-delete.php` re-probe `w.siteID=Site::id()`
(index.php:43, save.php:95-101, step-delete.php:77-86).

**Atomicity / no double-approve / no double-publish — CLEAN.** Every mutator claims via
`UPDATE … WHERE instanceID=? AND currentStep=? AND status IN ('pending','in_progress')`
gated on `affected_rows===1` (claimTransition 774-806, cancelForSubject 355-366,
start's conditional INSERT 155-183) under the `FOR UPDATE` lock. Two concurrent
approvers: one wins the lock+claim; the other reloads the advanced/completed row →
`stale_step`/`not_active`. `applySubjectEffect()` (the `isPublished=1` write) runs
INSIDE the terminal claim's transaction (812) and only from the final-step branch, so
there is no double-publish and no approved-but-unpublished ghost. *(SEC-01 is a
state-machine correctness bug in the advance/terminate decision, orthogonal to
atomicity — no double-write occurs.)*

**`processAutoSteps()` loop — CLEAN.** Bounded by `MAX_AUTO_STEPS=25` (859); terminates
regardless because each iteration's `claimTransition()` advances `currentStep` to a
strictly greater `stepOrder` (`resolveNextStep`: `stepOrder > ?`, 1454) or completes;
only `'notification'` and `'auto'` step types auto-advance (872-913); `'approval'`,
`'review'`, and any unrecognised type call `notifyStepAssignees()` and return
(915-917), so a human-required step is never auto-approved; an `'auto'` step lacking an
actionable `autoAction` is logged + `'skipped'`, never treated as approval (891-903);
the guarded claim prevents double-advance if two walks race.

**Cron — CLEAN.** `cron/workflow-timeouts.php:56-61`: `hash_equals()` compare, empty
stored token ⇒ always 403 (fail-closed), token is `isSensitive=1` (174:197). Global
kill-switch read once (65), per-site `Site::forceContext()` in try/catch-continue
(93-104). `timeoutSweep()` only auto-acts when `autoAction ∈ {approve,reject}`
(452-459); NULL/`escalate` only escalates, deduped per `(instanceID, stepID)` via a
locked `hasEscalated()` check (713-742). No auto-approve on a bare timeout.

**CSRF — CLEAN.** `act.php:35`, `announcements/save.php:56`, `announcements/delete.php:46`,
`admin/workflows/save.php:39`, `admin/workflows/step-delete.php:55`,
`auth/account/notifications-save.php:29` all `Auth::verifyCsrf()` before any state
write, after the POST-method check. (API bearer path intentionally skips CSRF —
ApiAuth.php:198-201 — but see SEC-02 for that path's separate problem.)

**bind_param arity — CLEAN.** Hand-counted every call in Workflow.php (start
`'iisiiisssi'`=10; claim `'iii'`/`'sii'`; recordAction `'iissi'`; applySubjectEffect
`'iii'`; canAct `'ii'`/`'is'`/`'ii'`; isAdminForSite `'ii'`; historyForSite
`'ii'`/`'iiii'`; resolveAssignees `'ii'`/`'is'`/`'ii'`; timeoutSweep `'i'`) and in the
handlers (admin/workflows/save.php step INSERT `'iisssssi'`=8; header `'sssiii'`/`'isssi'`).
All match placeholders and column order. `nullable int` binds (actorId in
recordAction/applySubjectEffect) are safe — `tblAnnouncements.updatedByID` is
`INT DEFAULT NULL` (full_schema).

**SQLi — CLEAN.** All prepared statements. The one dynamic IN-list
(`announcements/manage.php:96-100`) builds placeholders with
`implode(',', array_fill(0, N, '?'))` and binds `'s'.str_repeat('i', N)` with
`$tableName, ...$ids` — arity 1+N matches. `api/update.php` builds a dynamic SET from a
fixed column whitelist (not user strings) + placeholders.

**XSS — CLEAN.** `approvals/index.php` escapes every render (subjectLabel 125,
contextUrl 127, workflow/step names 133-134, startedByName 136, history 191-212) with
`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`; `admin/workflows/index.php` escapes
stepName/type/assignee/autoAction (171-176). Email bodies escape all interpolations
(Workflow.php:1179-1187, 1218-1222, 1271-1275, 1320-1327).

**Migration 174 — CLEAN.** All four ALTERs use the information_schema + PREPARE/EXECUTE
guard idiom (174:41-141); seeds use `INSERT … WHERE NOT EXISTS` /
`ON DUPLICATE KEY UPDATE`; backfill is a 0-row no-op on replay (83). `workflows.cron_token`
seeded empty + `isSensitive=1` (197). `approvals.enabled` seeded `'true'` (202) — the
PR #372 register-without-flag lesson is satisfied. full_schema fold folds the four
columns + `idx_wfi_site_status` inline into the CREATE TABLE and appends the seed block
after 173's self-record — parity maintained (verified against the diff). AppRegistry
`web/_core/apps/approvals.php` `settingKey='approvals.enabled'` matches the seed. `php -l`
clean on all in-scope files.

---

## VERDICT ON THE BUILDER'S 4 FLAGGED ITEMS

**1. No E2E replay.** Accepted as a real gap, and it is precisely what let SEC-01
through. Migration idempotency is sound by construction (guarded DDL, WHERE-NOT-EXISTS
/ ON-DUPLICATE seeds, 0-row backfill) and I verified the guard patterns, so the
*migration-replay* portion of the missing testing is low-risk. But the missing
*functional* E2E (plan gates 4-6: happy path, unauthorised actor, double-decision, and
especially a **multi-step reject**) is exactly where the two HIGH bugs live. The
single-step seeded definition masks SEC-01 in any smoke test; only a multi-step reject
exercise surfaces it. Recommendation: a multi-step reject E2E and an
API-publish-with-gate-on E2E are mandatory before merge.

**2. Mid-build rebase resolution.** Verified clean. Branch sits on `alpha` with 171/172/173
present; 174 is the next free number and self-records correctly (174:228 + fold). The
full_schema fold places the four new columns + `idx_wfi_site_status` inline in the
`tblWorkflowInstances` CREATE TABLE and the `'commented'` enum value in
`tblWorkflowActions`, with the seed block appended after the 173 appendix — no
duplication, no orphaned/missing statement. Column/seed parity holds. No rebase artefact
found.

**3. `processAutoSteps()` loop.** Verified safe — see the CLEAN statement above.
Bounded, strictly-monotonic termination, no authz skipped for human steps, no
auto-approval of a step that needs a human, atomic-claim-protected against races. One
benign nuance: duplicate `stepOrder` `auto` steps are skipped (not run) because
`resolveNextStep` uses strictly `>`; duplicates only arise from an admin double-submit
race, so not a concern.

**4. Combined admin form.** Verified safe. `admin/workflows/save.php` handles the
workflow header create/update AND a step-add in one handler, but re-probes
`tblWorkflows WHERE workflowID=? AND siteID=?` (94-101) before writing a step to the
site-less `tblWorkflowSteps`, so a forged `workflowID` cannot plant a step in another
tenant's definition. `autoAction` is whitelisted to `{approve,reject,escalate}` else
NULL (115-116); `stepType`/`assigneeType` come from fixed selects; bind arity
`'iisssssi'` is correct. CSRF-first, admin-gated. No injection or cross-tenant write.

---

## SUMMARY BY SEVERITY

- CRITICAL: 0
- HIGH: 2 (SEC-01, SEC-02)
- LOW: 1 (SEC-03)
- INFO: 1 (INFO-04)

**Can an unauthorised user approve or publish by any path?**
- *Approve:* **No** — `act()`'s in-lock `canAct()` is airtight; no bypass found.
- *Publish:* **Yes.** SEC-02 — an `announcements:write` API key (or admin session)
  publishes directly via the default-enabled REST write API, bypassing the gate
  entirely. SEC-03 — a narrow admin double-submit race fails open and publishes around
  a running approval.

**Can a workflow be double-approved or an announcement double-/wrongly-published?**
- *Double-approved / double-published:* **No** — `FOR UPDATE` + `affected_rows===1` +
  same-transaction side effect prevent it.
- *Wrongly published:* **Yes** — SEC-01: in a multi-step definition a rejection does not
  terminate the instance, so a downstream approval overrides the rejection and publishes
  a rejected announcement.
