# Gap Item #6 — Reconcile the Two Parallel Service-Plans Data Models
## Build-Ready Implementation Plan (ADDITIVE BRIDGE — no destructive merge)

Branch base: `alpha` @ `4433b30` (verified 2026-08-28).
All file paths relative to repo root `/home/user/WebMS-Intra` (worktree
`/home/user/WebMS-Intra/.claude/worktrees/agent-a5beec2cf5dedec4e` during this
investigation; line numbers were verified against that checkout of `alpha`).

---

## 0. Executive summary

Two independent "service plan" data models exist, confirmed in code:

| | Run-sheet builder (#262/#300) | Worship presentation (#308/#355) |
|---|---|---|
| Tables | `tblServicePlan` (SINGULAR) + `tblServicePlanItem` + `tblServicePlanMessages` | `tblServicePlans` (PLURAL) + `tblServicePlanItems` + `tblServicePlanState` (+ `tblCcliUsage` refs) |
| Born in | migration 089 (+110, +154) | migration 137 (+138, +139) |
| Surface | `/service-plans` app (11 handlers) | `/worship` app (10 handlers + 2 api) |
| PK | `planID` (both — same column name, DIFFERENT id spaces) | `planID` |
| Event link | `eventID` nullable FK → tblEvents — **schema-only, never written by any handler (dead column)** | `eventID` nullable FK → tblEvents — actively written, drives the coordinator write-ACL |

Neither model knows the other exists. Migration 154's header literally calls
them "unrelated" (`web/_sql/154_service_plan_messages.sql:10-12`), yet the
worship AppRegistry entry describes worship as "Live presentation layer for
**Service Plans**" (`web/_core/apps/worship.php`, description field) — the
stated product intent is that these are two faces of the same service.

**Chosen bridge (v1):** one additive, nullable, unique cross-reference column
**`tblServicePlans.runSheetPlanID`** (worship plan → its run-sheet), plus a
small `Portal\Core\ServicePlanLink` resolver helper, one pair/unpair POST
handler (`worship/plan-link.php`), and a **read-only "linked plan" panel on
each editor surface**, each guarded by `AppRegistry::isEnabled()` + try/catch
exactly like the calendar venue-overlay precedent
(`web/_apps/calendar/index.php:321-341`). **No field sync in v1. No writes to
either model's existing columns except one optional, guarded backfill of the
run-sheet's dormant `eventID` at pair time.** Migration **173** (re-confirm at
build), one full_schema fold. Nothing dropped, renamed, or migrated; both
surfaces work unchanged when the counterpart is absent or the counterpart app
is disabled.

---

## 1. Verified current state (file:line evidence)

### 1.1 Model A — run-sheet builder family

**`tblServicePlan`** — created `web/_sql/089_service_plans.sql:5-21`; two
columns added by `web/_sql/110_easy_wins_bundle.sql:95-117` (guarded ALTERs);
current folded shape `web/_sql/full_schema.sql:3442-3460`:

| Column | Type | Notes |
|---|---|---|
| planID | INT PK AUTO_INCREMENT | |
| siteID | INT NOT NULL DEFAULT 1 | FK `fk_sp_site` → tblSites(siteID) |
| eventID | INT NULL | FK `fk_sp_event` → tblEvents(eventID) ON DELETE SET NULL — **never written by any handler** (see §1.5) |
| title | VARCHAR(255) NOT NULL | |
| serviceDate | DATE NOT NULL | |
| status | ENUM('draft','published','archived') DEFAULT 'draft' | |
| preparedByID | INT NULL | FK `fk_sp_prepared` → tblUsers ON DELETE SET NULL |
| startedAt | DATETIME NULL | live runtime start (#300, mig 110) |
| closedAt | DATETIME NULL | live runtime end (#300, mig 110) |
| createdAt / updatedAt | DATETIME | defaults / ON UPDATE |
| — keys | `idx_sp_site_date(siteID,serviceDate)`, `idx_sp_event(eventID)` | **no uniqueness on eventID** |

**`tblServicePlanItem`** — `089:23-37`, folded `full_schema.sql:3462-3476`:
itemID PK; planID FK `fk_spi_plan` → tblServicePlan ON DELETE CASCADE;
`sectionType` ENUM('greeting','song','prayer','scripture','sermon','offering',
'communion','special_music','announcement','reading','other') DEFAULT 'other';
`position` INT; `title` VARCHAR(255) NULL (free text — "Hymn 256 — Amazing
Grace"); `presenterID` INT NULL FK tblUsers; `presenterText` VARCHAR(255);
`durationMin` INT NULL; `notes` TEXT (markdown, AV cues). Key
`idx_spi_plan_position(planID,position)`.

**`tblServicePlanMessages`** — `154_service_plan_messages.sql:28-43` (#300 v2
operator → confidence-monitor channel), folded `full_schema.sql:6053-6068`:
messageID PK, planID FK `fk_spm_plan` → **tblServicePlan** CASCADE, siteID,
body VARCHAR(255), isCleared, clearedAt, createdByID, createdAt.

### 1.2 Model B — worship presentation family

**`tblServicePlans`** — created `web/_sql/137_worship_service_plans.sql:26-42`;
`displayToken` + unique key added by `web/_sql/138_worship_present_state.sql:15-38`
(guarded); folded `full_schema.sql:5618-5638`:

| Column | Type | Notes |
|---|---|---|
| planID | INT PK AUTO_INCREMENT | |
| siteID | INT NOT NULL | FK `fk_plan_site` → tblSites ON DELETE CASCADE |
| eventID | INT NULL | FK `fk_plan_event` → tblEvents ON DELETE SET NULL; NULL = re-usable template; **drives coordinator write-ACL** |
| name | VARCHAR(120) NOT NULL | |
| notes | VARCHAR(1000) NULL | operator-only |
| isActive | TINYINT(1) DEFAULT 1 | archive toggle |
| createdByID | INT NULL | FK tblUsers |
| createdAt / updatedAt | DATETIME | |
| displayToken | CHAR(64) NULL, UNIQUE `uq_plan_display_token` | projector URL token (mig 138) |
| — keys | `idx_plan_site_active(siteID,isActive,updatedAt)`, `idx_plan_event(eventID)` | **no uniqueness on eventID** |

**`tblServicePlanItems`** — `137:44-59`, folded `full_schema.sql:5640-5656`:
itemID PK; planID FK `fk_planitem_plan` → tblServicePlans CASCADE; `sortOrder`
INT; `itemType` ENUM('song','text','verse') DEFAULT 'text'; `songID` INT NULL
FK `fk_planitem_song` → tblSongs ON DELETE SET NULL (canonical song reference —
lyrics render from tblSongs at display time); `slideTitle` VARCHAR(255);
`slideBody` MEDIUMTEXT; `slideNotes` VARCHAR(500) (operator-only); createdAt.

**`tblServicePlanState`** — `138:41-55`, folded `full_schema.sql:5658-5674`:
planID PK + FK → tblServicePlans CASCADE; currentItemID FK → tblServicePlanItems
SET NULL; currentSlideIndex (mig 139); isBlank; isBlack; updatedByID; updatedAt.
Live operator pointer mirrored to the projector.

**`tblCcliUsage`** — `139:` (folded `full_schema.sql:~5676-5693`): planID /
itemID nullable FKs → tblServicePlans / tblServicePlanItems ON DELETE SET NULL.

**Grep confirms these six are the ONLY `tblServicePlan*` tables** (plus
`tblVenueBookings` merely name-drops `tblServicePlan.eventID` in a column
comment, `full_schema.sql:7026` — not part of either model).

### 1.3 Complete reader/writer map

**Model A surface — `/service-plans` app (all in `web/_apps/service-plans/`).**
Routes seeded: `full_schema.sql:3479-3484` (index/new/edit/save/print/item-save,
mig 089), `:4599-4601` (live/confidence/live-toggle, mig 110), `:5016`
(live-message, mig 154). App gate: `service_plans.enabled` seed
`full_schema.sql:3488` — AppRegistry entry `web/_core/apps/service-plans.php`
(slug `service-plans`, settingKey `service_plans.enabled`). ACL on EVERY
handler: `Auth::ensureSession(); Auth::requireLogin();` only — **no
admin/coordinator gate anywhere in this app**. DB access via `App::db()`.
Site-scoping via `Site::id()` on every plan query.

| File | Reads | Writes |
|---|---|---|
| `index.php:23-31` | tblServicePlan + item count | — |
| `new.php:31-77` | — | INSERT tblServicePlan `(siteID,title,serviceDate,preparedByID)` (**no eventID**) + template-seeded tblServicePlanItem rows, in a transaction |
| `edit.php:29-56` | tblServicePlan (`SELECT *`), tblServicePlanItem + presenter join | — |
| `save.php:34-43` | — | UPDATE tblServicePlan title/serviceDate/status (planID+siteID scoped) (**no eventID**) |
| `item-save.php:29,52-146` | plan existence probe | INSERT/UPDATE/DELETE/reorder tblServicePlanItem |
| `print.php:26-42` | plan + items | — |
| `live.php:41-71` | plan (`SELECT *`), items, latest uncleared tblServicePlanMessages | — |
| `live-toggle.php:55-60` | — | UPDATE tblServicePlan startedAt/closedAt |
| `confidence.php:36` | plan (planID,title,startedAt,closedAt) | — |
| `live-message.php:69-110` | plan probe | INSERT tblServicePlanMessages / UPDATE isCleared |
| `message-poll.php:62` | tblServicePlanMessages | — |

**Model B surface — `/worship` app (all in `web/_apps/worship/`).** Routes
seeded: `full_schema.sql:4788-4799` (plans/plan/plan-save/present/display/
plan-reorder; display is `isProtected=0` — public via displayToken) and
`:4765-4767` (songs library). App gate: `worship.enabled` seed
`full_schema.sql:5094` (mig 158) — AppRegistry entry
`web/_core/apps/worship.php` (slug `worship`, settingKey `worship.enabled`).
API endpoints at the ApiRouter convention path with flags
`api.worship.state.enabled` / `api.worship.advance.enabled`
(`full_schema.sql:5092-5093`). Read ACL: login. Write ACL: `App::isAdmin()`
OR `Auth::isCoordinatorOf(plan.eventID)` (`web/_core/Auth.php:127`), gate
closure at `plan-save.php:61-66`; free-floating templates (eventID NULL) are
admin-only. DB access via the `$mysqli` global (post-#373 Router import).

| File | Reads | Writes |
|---|---|---|
| `plans.php:41-61` | tblServicePlans + tblEvents(eventName) + item counts + creator | — |
| `plan.php:49-98` | plan + eventName, tblServicePlanItems + tblSongs titles, song pool | — |
| `plan-save.php:48,84-224` | plan probe, tblEvents probe (site-scoped, isDeleted=0) | INSERT/UPDATE tblServicePlans (incl. **eventID binding** with coordinator/authorization check at :95-97); INSERT/DELETE/reorder tblServicePlanItems |
| `plan-reorder.php:45-88` | plan probe (isActive=1) | UPDATE tblServicePlanItems.sortOrder |
| `present.php:37-77` | plan (+ displayToken mint at :55 — the only other tblServicePlans write), items, state | UPDATE tblServicePlans.displayToken |
| `display.php:29` | plan by displayToken (public) | — |
| `api/state.php:56-86` | plan + state + items | — |
| `api/advance.php:72-202` | plan, state, items | UPSERT tblServicePlanState |
| `song.php` / `songs.php` / `songs-save.php` | tblSongs (adjacent, no plan tables) | tblSongs |

**No `_core` class touches any of the six tables** — `ls web/_core/ | grep -iE
"service|worship"` is empty; all SQL is inline in handlers. `tblCcliUsage`
writes happen from the worship runtime (out of scope here).

### 1.4 The conceptual overlap

Duplicated concept ("what happens in this service"):
- **Ordered list of items** — `tblServicePlanItem(position, sectionType, title)`
  vs `tblServicePlanItems(sortOrder, itemType, slideTitle/songID)`.
- **Songs/hymns** — Model A: `sectionType='song'` with a free-text `title`
  ("Opening hymn", "Hymn 256 — Amazing Grace"); Model B: `itemType='song'` with
  a **canonical FK** `songID` → tblSongs. Same real-world entity, incompatible
  representations (free text vs FK).
- **Scripture/readings** — A: `sectionType='scripture'|'reading'` + title;
  B: `itemType='verse'` + slideTitle(ref)/slideBody(passage).
- **The service date/event** — A: `serviceDate` DATE (required) + dead
  `eventID`; B: `eventID` (live, optional) and no date of its own.

Unique to A (run-sheet): presenter assignment (presenterID/presenterText),
durations, AV/markdown notes, print view, status lifecycle, live runtime
(startedAt/closedAt), confidence-monitor messaging (tblServicePlanMessages).
Unique to B (worship): canonical song library + lyrics rendering, slide
bodies, projector displayToken + public display, live pointer state
(tblServicePlanState), CCLI logging, coordinator ACL, template plans.

Where a user would expect cross-visibility: (1) on the run-sheet editor —
"which worship/projection plan runs this service, and which songs are loaded
for projection?"; (2) on the worship plan editor — "what's the full programme
(preacher, order, timings) this slide deck belongs to?"; (3) arguably on the
live surfaces (confidence monitor ↔ operator console) — deferred, see §11.

### 1.5 The eventID linkage — key findings

- **Model A's `eventID` is write-dead.** `grep -rn eventID web/_apps/service-plans/`
  returns ZERO matches — `new.php:31-33` inserts without it, `save.php:35-37`
  updates without it. It exists only in DDL (089:8,17,19). Every run-sheet row
  in production has `eventID = NULL`. Consequence: **"pair via shared eventID"
  cannot work today** and any bridge keyed on A.eventID alone would match
  nothing.
- **Model B's `eventID` is live and ACL-bearing** (`plan-save.php:84-99`
  validates site + coordinator authority before binding; the write gate at
  :61-66 derives from it). Changing B.eventID as a side effect would change
  who can edit the worship plan — must never be done implicitly.
- **Both rows can exist for the same event simultaneously today** — neither
  table has a unique constraint on eventID (`idx_sp_event` and
  `idx_plan_event` are plain KEYs), and nothing anywhere joins the two models
  (`grep` across `web/` shows no query touching both families).
- tblEvents linkage details used by B: `eventName`, `isDeleted` filter
  (`plans.php:44`, `plan-save.php:89`).

### 1.6 Prior intent

- `web/_sql/154_service_plan_messages.sql:10-12`: "Targets the run-sheet
  builder's `tblServicePlan` (PK `planID`, migration 089) — NOT the unrelated
  worship slides system's `tblServicePlans` / `tblServicePlanItems` /
  `tblServicePlanState` (migrations 137/138)." → the collision is KNOWN and
  deliberately routed around; no rename/merge was ever attempted. A bridge
  must preserve both namespaces untouched.
- `web/_core/apps/worship.php` description: "Live presentation layer for
  Service Plans — operator console, public projector display…" (same wording
  in `.claude/CLAUDE.md` app table and FEATURES.md) → product-level intent is
  that worship *presents* a service plan. The bridge direction "worship plan
  points at its run-sheet" matches this stated relationship.
- `137_worship_service_plans.sql:14-17`: worship plans were designed to bind
  to events (coordinator self-service) with NULL = template — the bridge must
  keep template plans (eventID NULL) fully functional and pairable.
- No doc anywhere proposes merging the tables; FEATURES.md lists them as two
  shipped features (`FEATURES.md:511` run-sheet; `:20` worship engine). The
  conservative additive bridge is consistent with all in-tree statements.

---

## 2. Scope decision

Options evaluated:

**(a) Explicit pairing — nullable cross-reference column or link table.** ✅
CHOSEN, as the single physical bridge. Column vs link table:
- A separate `tblServicePlanLinks` table touches neither model but needs TWO
  unique keys to guarantee 1:1, a siteID column, CASCADE rules in both
  directions, and one more join in every resolver — more surface, same result.
- A single nullable column **on the worship side**, `tblServicePlans.runSheetPlanID`
  INT NULL + UNIQUE + FK → `tblServicePlan(planID)` ON DELETE SET NULL, gives
  a structural 1:1 (column ⇒ ≤1 run-sheet per worship plan; UNIQUE ⇒ ≤1
  worship plan per run-sheet; MySQL allows unlimited NULLs in a unique index),
  one-statement pair/unpair, and clean self-healing on deletes.
- Why the worship side and not `tblServicePlan.worshipPlanID`: (1) in
  `full_schema.sql` the parent `tblServicePlan` (L3442) is created ~2,200
  lines BEFORE `tblServicePlans` (L5618), so the FK folds **inline** into the
  child CREATE, honouring the file's "FK ordering respected — parent tables
  created before children" guarantee (header, `full_schema.sql:10`); the
  reverse direction would need an out-of-order ALTER with no precedent (grep:
  zero `ALTER TABLE` statements exist in full_schema — every later column is
  folded inline, e.g. startedAt/closedAt at :3450-3451). (2) Pairing then
  mutates only a worship-plan row, so the worship app's existing, stricter
  write-ACL (admin-or-coordinator gate, `plan-save.php:61-66`) governs
  pair/unpair for free; the ACL-free run-sheet model is never written by the
  bridge (except the optional eventID backfill, §3.3). (3) Matches stated
  intent: the presentation layer references the programme it presents.
- Additive-bridge precedent already in-tree: migration 110 added columns to
  tblServicePlan, 138 added displayToken to tblServicePlans — guarded ALTERs
  on these exact tables are proven safe.

**(b) Read-only "linked plan" panel on each surface.** ✅ CHOSEN — this is the
user-visible value of v1. Each editor shows a summary card of the counterpart
(or a pairing picker when unpaired), guarded so a missing counterpart, a
disabled counterpart app, or any resolver exception leaves the page exactly as
it is today (venue-overlay resilience precedent,
`web/_apps/calendar/index.php:321-341`: `AppRegistry::isEnabled()` check +
try/catch + `error_log`, empty fallback).

**(c) One-/two-way sync of shared fields (songs/hymns).** ❌ DEFERRED entirely.
The song representations are incompatible (free-text `title` in A vs canonical
`songID` FK in B); any auto-sync needs fuzzy title↔song matching with high
false-positive risk, and a two-way sync would create write paths into an
ACL-free model from an ACL-bearing one and vice versa. v1 ships **zero field
sync**; the panels give read-only visibility instead. A deliberate,
user-triggered "copy song sections → worship slides" button is the v2
candidate (§11).

**v1 = (a) + (b).** Nothing destructive is even scheduled: no renames, no
table merge, no data migration, no backfill sweep.

---

## 3. Design

### 3.1 Bridge mechanism (exact)

New column on `tblServicePlans`:

- `runSheetPlanID` INT DEFAULT NULL,
  COMMENT `'Optional 1:1 link to the programme run-sheet this plan presents — tblServicePlan.planID (gap #6 bridge, migration 173)'`,
  positioned `AFTER eventID`.
- `UNIQUE KEY uq_plans_runsheet (runSheetPlanID)` — enforces at most one
  worship plan per run-sheet (NULLs exempt). Name verified unused (grep).
- `CONSTRAINT fk_plans_runsheet FOREIGN KEY (runSheetPlanID) REFERENCES
  tblServicePlan(planID) ON DELETE SET NULL` — a deleted run-sheet silently
  unpairs; nothing cascades into the worship model. Name verified unused.

NULL = unpaired (the universal state for all existing rows — zero data
migration). Template worship plans (eventID NULL) may pair; nothing requires
an event.

### 3.2 Pairing rules / invariants (enforced in the handler + helper, single
implementation in `ServicePlanLink::pair()`)

1. **Same site (hard):** both rows are loaded with `siteID = Site::id()` in
   the WHERE; either miss → 404. Cross-tenant pairing is therefore impossible
   even with a forged POST (`Site::forceContext` tenant pinning unaffected —
   no api/* surface is added).
2. **Same event (hard, when knowable):** if `worship.eventID IS NOT NULL` AND
   `runsheet.eventID IS NOT NULL` AND they differ → refuse with a flash error
   ("These plans are bound to different events."). If either side's eventID is
   NULL (the normal case — A's is always NULL today) the pair proceeds.
3. **1:1 (hard):** if the target run-sheet is already paired to a DIFFERENT
   worship plan (pre-check `SELECT planID FROM tblServicePlans WHERE
   runSheetPlanID = ? AND siteID = ?`), refuse with a friendly message naming
   the other plan; the UNIQUE key is the backstop against races (catch the
   1062 duplicate-key on execute and show the same friendly error — do not let
   it fatal). Re-pairing the same pair is a no-op success. A worship plan that
   is already paired must be unpaired first (or the pair action simply
   overwrites its own row's runSheetPlanID — allowed, it's the same row being
   edited; choose overwrite-own = simpler UX).
4. **eventID backfill (soft, one-directional, recommended — open question Q1):**
   at successful pair, if `runsheet.eventID IS NULL` AND `worship.eventID IS
   NOT NULL`, additionally `UPDATE tblServicePlan SET eventID = ? WHERE planID
   = ? AND siteID = ? AND eventID IS NULL` — wakes the dormant column, makes
   invariant 2 self-strengthening, has zero ACL side effects (A has no
   event-derived ACL). **Never the reverse** — writing `worship.eventID` would
   silently grant that event's coordinator write access to the worship plan
   (`plan-save.php:61-66`); forbidden.
5. **Unpair:** `UPDATE tblServicePlans SET runSheetPlanID = NULL WHERE planID
   = ? AND siteID = ?`. Never touches the run-sheet row (the backfilled
   eventID, if any, stays — it is true information about the run-sheet).
6. Both actions log via `Logger::activity()` (`web/_core/Logger.php:48`),
   types `ServicePlanLinked` / `ServicePlanUnlinked`, description naming both
   planIDs — matches `plan-save.php:115,130` precedent.

### 3.3 Source-of-truth rules (documented in DEV_NOTES; no code sync in v1)

| Data | Owner | The other side… |
|---|---|---|
| Programme order, sections, presenters, durations, AV notes, serviceDate, print | Model A (run-sheet) | renders a read-only summary |
| Slides, canonical songs (songID), lyrics, projector state, displayToken, CCLI | Model B (worship) | renders a read-only summary |
| The pairing itself | `tblServicePlans.runSheetPlanID` — single physical record, no mirror column, no dual write | resolved by reverse lookup |
| Event binding | B's `eventID` authoritative where both set (invariant 2); A's may be backfilled from B at pair time only | — |

### 3.4 ACL for pair/unpair

Pair/unpair mutates a `tblServicePlans` row ⇒ reuse the worship write gate
verbatim: `App::isAdmin() === true` OR (`plan.eventID !== null` AND
`Auth::isCoordinatorOf((int)$plan['eventID']) === true`); template plans
admin-only — copy the `$gate` closure from `plan-save.php:61-66` into the new
handler (do not refactor plan-save.php in this change). CSRF via
`Auth::verifyCsrf($_POST['csrf_token'] ?? '')` exactly as `plan-save.php:36-38`.
POST-only (`plan-save.php:30-32` pattern).

UI rendering of the controls (authorization is ALWAYS re-checked server-side):
- worship editor panel: show controls when the page's existing `$canWrite`
  is true (`plan.php:76-87`).
- run-sheet editor panel: show controls when `App::isAdmin() === true`
  (computing per-candidate coordinator status would cost a query per
  candidate; admins-only on this side is the v1 simplification — open
  question Q2). Non-admins still SEE the read-only linked-plan summary.

### 3.5 What each surface shows (exact insertion points)

**`web/_apps/service-plans/edit.php`** — insert a new card between the plan
metadata card (ends line 143) and the Items card (starts line 146). Data is
resolved BEFORE the header include (i.e. in the PHP prologue around line 78,
after `$plan` is loaded), guarded:

```
$worshipLink = null; $worshipCandidates = [];
if (AppRegistry::isEnabled('worship') === true) {
    try {   // 🎶 gap #6 bridge — venue-overlay resilience precedent (calendar/index.php:321-341)
        $worshipLink = ServicePlanLink::worshipPlanForRunSheet($id, $siteId);
        if ($worshipLink === null && App::isAdmin() === true) {
            $worshipCandidates = ServicePlanLink::candidatesForRunSheet($siteId);
        }
    } catch (\Throwable $e) { error_log('Service-plan worship panel failed: ' . $e->getMessage()); $worshipLink = null; $worshipCandidates = []; }
}
```
(NB: written as full-IF house style in the real code; snippet here is
structural only — the plan mandates NO code, the implementer writes it.)

Panel content when paired: worship plan name; Active/Archived badge
(isActive); event badge (eventName) or "Template"; slide count; the song
titles list (itemType='song' joined to tblSongs.title, "(song deleted)" for
NULL joins — mirror `plan.php:159`); links `→ Open in Worship`
(`/worship/plan?id=N`) and `→ Present` (`/worship/present?id=N`); Unpair
button (admin-only, `data-confirm` attribute — house rule, no native
`confirm()`). When unpaired + admin: a `<select>` of candidates (name +
event/template badge + slide count) + "Link" button posting to
`/worship/plan/link` with `action=pair`, `worshipPlanID`, `runPlanID=$id`,
`from=runsheet`, CSRF. When unpaired + non-admin: one muted line "No worship
presentation linked." When `AppRegistry::isEnabled('worship') === false`: the
card is entirely absent (today's page, byte-for-byte behaviour).
Use div-based layout (`portal-data-list` idiom) — **no `<table>`**.

**`web/_apps/worship/plan.php`** — resolve in the prologue (after `$plan`
loads, near line 98, guarded identically with
`AppRegistry::isEnabled('service-plans')`); insert the card after the
metadata form block (`endif` at line 141), only when `$isNew !== true`.
Panel content when paired: run-sheet title; serviceDate (`date('l j F Y')`
format as `edit.php:104`); status badge; item count; the song-type sections
(`sectionType='song'` titles); links `→ Open run-sheet`
(`/service-plans/edit?id=N`) and `→ Print` (`/service-plans/print?id=N`,
target=_blank); Unpair button (when `$canWrite === true`). When unpaired +
`$canWrite`: candidate `<select>` (title + date + status) + "Link" button
(`action=pair`, `runPlanID` from select, `worshipPlanID=$planId`,
`from=worship`, CSRF). Candidates ordering: same-eventID matches first (if
`$plan['eventID']` set), then `serviceDate DESC`, LIMIT 50.

**List pages (`service-plans/index.php`, `worship/plans.php`): unchanged in
v1** (open question Q3 — deferred badge).

**Live surfaces (`live.php`, `confidence.php`, `present.php`, `display.php`,
`api/*`): untouched in v1.**

### 3.6 Resolver helper — `web/_core/ServicePlanLink.php` (new)

`namespace Portal\Core; class ServicePlanLink` — small, static, mysqli-prepared,
every query site-scoped, `App::db()` internally (avoids the #373 `$mysqli`
global-scope trap for new code). House header comment block (path,
description, @package Portal\Core, @author/@copyright MWBM Partners Ltd (t/a
MWservices), All Rights Reserved, @version, @link to the gap issue).
`declare(strict_types=1)`. Methods (all may `throw` mysqli exceptions — the
calling pages' try/catch is the containment, per §3.5):

- `worshipPlanForRunSheet(int $runPlanId, int $siteId): ?array` — one query:
  `SELECT p.planID, p.name, p.isActive, p.eventID, e.eventName FROM
  tblServicePlans p LEFT JOIN tblEvents e ON e.eventID = p.eventID AND
  e.isDeleted = 0 WHERE p.runSheetPlanID = ? AND p.siteID = ?` (`bind_param('ii', …)`);
  plus a second query for slide count + song titles from tblServicePlanItems
  LEFT JOIN tblSongs. Returns null when absent.
- `runSheetForWorshipPlan(int $worshipPlanId, int $siteId): ?array` —
  `SELECT r.planID, r.title, r.serviceDate, r.status FROM tblServicePlans p
  JOIN tblServicePlan r ON r.planID = p.runSheetPlanID WHERE p.planID = ? AND
  p.siteID = ? AND r.siteID = ?` (`'iii'`) + item count/song-section titles
  from tblServicePlanItem.
- `candidatesForRunSheet(int $siteId): array` — unpaired active worship plans:
  `… WHERE siteID = ? AND isActive = 1 AND runSheetPlanID IS NULL ORDER BY
  updatedAt DESC LIMIT 50`.
- `candidatesForWorshipPlan(int $siteId, ?int $eventId): array` — run-sheets
  not yet paired: `SELECT r.planID, r.title, r.serviceDate, r.status FROM
  tblServicePlan r WHERE r.siteID = ? AND NOT EXISTS (SELECT 1 FROM
  tblServicePlans p WHERE p.runSheetPlanID = r.planID) ORDER BY (r.eventID
  IS NOT NULL AND r.eventID = ?) DESC, r.serviceDate DESC LIMIT 50` (pass
  eventId or 0; `'ii'`).
- `pair(int $worshipPlanId, int $runPlanId, int $siteId, int $userId): array`
  → `['ok' => bool, 'error' => string|null]`, implementing §3.2 rules 1-4 + 6.
  The two UPDATEs: `UPDATE tblServicePlans SET runSheetPlanID = ? WHERE planID
  = ? AND siteID = ?` (`'iii'`); optional backfill `UPDATE tblServicePlan SET
  eventID = ? WHERE planID = ? AND siteID = ? AND eventID IS NULL` (`'iii'`).
  Catch mysqli errno 1062 on the first UPDATE → friendly 'already linked'
  error (race backstop). ACL is NOT in the helper — the handler owns it
  (helper is mechanism, handler is policy).
- `unpair(int $worshipPlanId, int $siteId, int $userId): bool` — §3.2 rule 5 + 6.

Mind `check_bind_param_arity.py`: every `bind_param` type-string length must
equal its argument count (recent fatal-bug precedent, PR #437) — the arities
above are part of the spec.

### 3.7 Pair/unpair handler — `web/_apps/worship/plan-link.php` (new)

Route `('worship/plan/link', 'worship/plan-link.php', 1)`. ONE handler serves
both panels (both post here). Skeleton order (mirrors `plan-save.php`):
1. header comment + `declare(strict_types=1)` + uses.
2. POST-only redirect guard (`plan-save.php:30-32` pattern).
3. `Auth::ensureSession(); Auth::requireLogin();` + CSRF check (:34-38).
4. Read `action` ('pair'|'unpair'), `worshipPlanID`, `runPlanID` (pair only),
   `from` ('worship'|'runsheet' — whitelist, default 'worship'; determines the
   redirect target only, never trust it for IDs).
5. Load the worship plan `SELECT planID, eventID, runSheetPlanID FROM
   tblServicePlans WHERE planID = ? AND siteID = ?` → 404 if missing.
6. `$gate` closure copied from `plan-save.php:61-66`; 403 on failure.
7. Dispatch to `ServicePlanLink::pair(...)` / `::unpair(...)`; set
   `$_SESSION['flash_msg']`/`flash_type` from the result (pattern
   `plan-save.php:78-79`).
8. Redirect: `from=runsheet` → `/service-plans/edit?id={runPlanID}` (for
   unpair, resolve the runPlanID BEFORE unpairing so the redirect works);
   else `/worship/plan?id={worshipPlanID}`. 302, `exit()`.

No api/* endpoint is added (panels are server-rendered; the ApiRouter
trap — enable flags, convention paths — is therefore entirely avoided). No
OpenAPI change.

---

## 4. Migration — `web/_sql/173_worship_runsheet_link.sql`

**Number: 173** — current `alpha` tops out at `170_venue_bookings.sql`
(168/169 were never used — do NOT backfill into the gap; replay order must
match numeric order and the gap is below the shipped max); no remote branch
(`claude/venue-b..f`, dependabot, main/beta) carries anything above 170, but
the caller states 171 (venue reminders) and 172 (bulk statements) are claimed
by in-flight sibling sessions not yet pushed → next TRUE free is 173.
**RE-CONFIRM AT BUILD:** `ls web/_sql/ | sort` + `git fetch --all` + for each
remote head `git ls-tree -r --name-only origin/<branch> web/_sql/ | grep -E
'^web/_sql/1[7-9][0-9]_'`; take the lowest number above every hit.

Header comment: migration number, purpose ("gap #6 additive bridge between the
run-sheet builder (mig 089, #262/#300) and the worship presentation engine
(mig 137, #308/#355) — pairing column only, NO data migration, NO sync"),
@link to the GitHub issue created for this work (standing instruction 1).

Statement-by-statement (ALL DDL via the house guard idiom — DEV_NOTES.md:905
"Portable DDL convention"; precedents: column guard `110:95-106`, unique-key
guard `138:26-38`, FK guard `110:62-74` and `151:157-169`; one guard block per
DDL object, literal DDL contiguous inside the quoted string, `SELECT 1` no-op,
`''` for embedded quotes):

1. **Guarded ADD COLUMN** — sentinel `information_schema.COLUMNS`
   (TABLE_SCHEMA = DATABASE(), TABLE_NAME 'tblServicePlans', COLUMN_NAME
   'runSheetPlanID'); DDL literal: ``ALTER TABLE `tblServicePlans` ADD COLUMN
   `runSheetPlanID` INT DEFAULT NULL COMMENT 'Optional 1:1 link to the
   programme run-sheet this plan presents — tblServicePlan.planID (gap #6,
   migration 173)' AFTER `eventID` ``.
2. **Guarded ADD UNIQUE KEY** — sentinel `information_schema.STATISTICS`
   (INDEX_NAME 'uq_plans_runsheet'); DDL: ``ALTER TABLE `tblServicePlans` ADD
   UNIQUE KEY `uq_plans_runsheet` (`runSheetPlanID`)``.
3. **Guarded ADD CONSTRAINT** — sentinel `information_schema.TABLE_CONSTRAINTS`
   (CONSTRAINT_SCHEMA = DATABASE(), TABLE_NAME 'tblServicePlans',
   CONSTRAINT_NAME 'fk_plans_runsheet', CONSTRAINT_TYPE 'FOREIGN KEY'); DDL:
   ``ALTER TABLE `tblServicePlans` ADD CONSTRAINT `fk_plans_runsheet` FOREIGN
   KEY (`runSheetPlanID`) REFERENCES `tblServicePlan`(`planID`) ON DELETE SET
   NULL``.
4. **Route seed** (page route — belongs in tblRoutes, unlike api/*):
   `INSERT INTO tblRoutes (routeKey, targetFile, isProtected) VALUES
   ('worship/plan/link', 'worship/plan-link.php', 1) ON DUPLICATE KEY UPDATE
   targetFile = VALUES(targetFile);`
5. **Self-record**: `INSERT INTO tblMigrations (filename) VALUES
   ('173_worship_runsheet_link.sql') ON DUPLICATE KEY UPDATE filename =
   filename;` (exact 170 pattern, `170_venue_bookings.sql` tail).

No settings seeds — the bridge introduces **no new settings keys** (gating
rides on the existing `worship.enabled` / `service_plans.enabled` via
`AppRegistry::isEnabled()`, `web/_core/AppRegistry.php:95-116`), which also
keeps `check_settings_keys.py` trivially green.

Idempotency: replay on an up-to-date schema hits all three sentinels → three
`SELECT 1`s + two ON-DUPLICATE no-ops. MySQL-8-safe: no `IF [NOT] EXISTS` on
any ALTER/INDEX (the `check_mariadb_only_ddl.py` gate).

**full_schema.sql fold** (same commit — `check_schema_seed_parity.py` gate):
1. In the `tblServicePlans` CREATE (lines 5618-5638): add the
   `runSheetPlanID` column line directly after the `eventID` line (~5620),
   with the comment suffix "(gap #6, migration 173)"; add
   ``UNIQUE KEY `uq_plans_runsheet` (`runSheetPlanID`),`` beside
   `uq_plan_display_token` (~5633); add ``CONSTRAINT `fk_plans_runsheet`
   FOREIGN KEY (`runSheetPlanID`) REFERENCES `tblServicePlan`(`planID`) ON
   DELETE SET NULL`` after `fk_plan_creator` (~5637). This is FK-safe inline:
   tblServicePlan is created at :3442, far earlier — consistent with the
   header guarantee (:10) and the inline-fold house style (startedAt/closedAt
   precedent :3450-3451; there are zero standalone ALTERs in the file).
2. Add the route row to the worship routes INSERT block at :4788-4799 (one
   line, trailing `-- migration 173` comment).
3. Append the tblMigrations mark after line 7584-7585 (the 170 mark):
   `INSERT INTO tblMigrations (filename) VALUES ('173_worship_runsheet_link.sql')
   ON DUPLICATE KEY UPDATE filename = filename;`
4. Optionally correct the stale header "Covers migrations: 000-158" → the new
   max (it is already stale at 170; harmless either way — if touched, say
   000-173).

---

## 5. File list (complete)

New files (4):
| File | Purpose |
|---|---|
| `web/_sql/173_worship_runsheet_link.sql` | Guarded bridge column + unique key + FK on tblServicePlans; route seed; self-record (§4) |
| `web/_core/ServicePlanLink.php` | Portal\Core resolver/mutator helper — the ONLY code that knows both models (§3.6) |
| `web/_apps/worship/plan-link.php` | POST pair/unpair handler, worship write-ACL + CSRF, serves both panels (§3.7) |
| *(none else — no api endpoints, no new templates)* | |

Changed files (7):
| File | Change |
|---|---|
| `web/_sql/full_schema.sql` | Fold per §4 (column+key+FK inline at 5618-5638; route at 4788-4799; migration mark after :7585) |
| `web/_apps/service-plans/edit.php` | Prologue: guarded resolve (§3.5); one new card between lines 143/146; `use` lines for AppRegistry + ServicePlanLink |
| `web/_apps/worship/plan.php` | Prologue: guarded resolve; one new card after line 141 (skip when `$isNew`); `use` AppRegistry + ServicePlanLink |
| `CHANGELOG.md` | Entry under Unreleased: gap #6 bridge, migration 173 |
| `FEATURES.md` | Row: "Service-plan ↔ worship-plan pairing (read-only cross-panels, gap #6)" with issue + migration refs on both features' sections |
| `DEV_NOTES.md` | New subsection: the two-model situation, bridge column, source-of-truth table (§3.3), the dormant-`tblServicePlan.eventID` note, invariants (§3.2) |
| `.claude/CLAUDE.md` | One-line memory update in Recent ships (standing instruction 4) |

Deliberately NOT touched: `plan-save.php`, `save.php`, `new.php`, all live/
present/display/api handlers, both list pages, `_core/api-spec.json`,
`GdprEraser` (the new column holds no personal data), `demo_data.sql` (no
service plans seeded there — verified empty grep).

## 6. Implementation order

1. GitHub issue (standing instruction 1): description = §0-§3 summary,
   acceptance criteria = §8. Labels: `type: enhancement`, `scope: core`,
   `app:` (service-plans is an existing label; worship may need creating).
2. Migration 173 + full_schema fold → run `check_mariadb_only_ddl.py`,
   `check_migration_idempotency.py`, `check_schema_seed_parity.py`,
   `check_sql_columns.py` locally.
3. `_core/ServicePlanLink.php` → `php -l`.
4. `_apps/worship/plan-link.php` → `php -l` (+ `check_route_targets.py` now
   green: route row + handler land together).
5. The two panels → `php -l`, `check_php_table_refs.py`,
   `check_bind_param_arity.py`, `check_no_native_confirm.py`,
   `check_mobile_readiness.py`.
6. Docs + memory; full audit suite (all 11); commit (NO push unless asked).

## 7. House conventions checklist (each item MUST hold in every new/changed file)

- `declare(strict_types=1)` first statement after the header comment.
- Full IF notation (`if ($x === true)`, `=== false`, `!== null`).
- File headers: path, description, @package, @author + @copyright MWBM
  Partners Ltd (t/a MWservices), All Rights Reserved, @version, @link.
- Emoji-annotated section comments (🎶 🛡️ 📋 🔗 ➕ per the precedents cited).
- MySQLi prepared statements ONLY; bind_param type-string arity exact (§3.6).
- `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` on every echo of DB/user data
  in both panels (names, titles, dates, song titles).
- CSRF on the new POST route (`Auth::verifyCsrf`) + POST-only guard.
- Site-scoping (`siteID = ?` with `Site::id()`) in every query of both models.
- No `<table>` — `portal-data-list` / Bootstrap grid markup.
- `data-confirm` (+ `data-confirm-destructive`) for the Unpair buttons — no
  native `confirm()`.
- DIRECTORY_SEPARATOR for the template requires (copy existing lines).
- New PHP uses `App::db()` (not the `$mysqli` global — sidesteps the #373
  Router-globals trap for new files; existing files keep their style).
- Migration: guard idiom per object, self-record, full_schema fold, no
  MariaDB-only DDL, replay-as-no-op.

## 8. Security / safety checklist

- **Additive only:** zero DROP/RENAME/MODIFY on any existing column, key, or
  table of either model; zero UPDATE/DELETE of existing data by the migration.
- **Absence-tolerant:** each surface renders byte-identical to today when (a)
  the counterpart app is disabled (`AppRegistry::isEnabled` short-circuit),
  (b) the helper class file is missing/stale on a partially-synced deploy or
  the column hasn't been migrated yet (try/catch swallows the mysqli "unknown
  column" error → panel absent, page fine — the venue-overlay precedent's
  exact failure mode), (c) no counterpart plan exists (null → muted line or
  nothing).
- **No cross-tenant pairing:** every load in helper + handler is
  `siteID = Site::id()`-scoped; a forged POST with a foreign planID 404s
  before any write (§3.2 rule 1).
- **Same-event invariant** enforced when both sides declare an event (§3.2
  rule 2).
- **CSRF + POST-only + worship write-ACL** on pair/unpair (§3.4); UI-level
  control hiding is cosmetic only, the handler is authoritative.
- **No ACL side effects:** `tblServicePlans.eventID` is never written by the
  bridge (it gates who may edit the worship plan); the only bridge write into
  Model A is the optional NULL-only eventID backfill, and Model A carries no
  event-derived ACL (login-only app, §1.3).
- **Race-safe 1:1:** UNIQUE key + errno-1062 catch (§3.2 rule 3).
- **Delete-safe:** FK ON DELETE SET NULL — deleting a run-sheet (only
  possible manually today; no delete route exists in either app) auto-unpairs.
- **XSS:** all panel output escaped; candidate `<option>` values are ints
  cast with `(int)`.
- **No new attack surface:** no api/* endpoint, no public route (the new
  route is `isProtected = 1`), no new settings, no uploads, no JS beyond
  what exists.

## 9. Acceptance gates (all must pass before commit)

1. `php -l` clean on every touched/new `.php` file (zero warnings).
2. All **11** audit checks pass: `check_bind_param_arity`, `check_cdn_sri`,
   `check_mariadb_only_ddl`, `check_migration_idempotency`,
   `check_mobile_readiness`, `check_no_native_confirm`,
   `check_php_table_refs`, `check_route_targets`, `check_schema_seed_parity`,
   `check_settings_keys`, `check_sql_columns` (in `tools/audit-checks/`).
3. Migration replay: apply 173 to an up-to-date schema **twice** → second run
   is a pure no-op (three SELECT 1 + two ON-DUPLICATE no-ops); fresh-install
   path (full_schema then replay-all) also no-ops on 173.
4. Functional matrix:
   - Run-sheet exists, no worship plan → `/service-plans/edit` renders with
     "no linked plan" state; zero errors in log.
   - Worship plan exists, no run-sheet → `/worship/plan` likewise.
   - Worship app disabled (`worship.enabled` ≠ true) → run-sheet editor
     renders with NO panel at all; and vice versa with `service_plans.enabled`.
   - Admin pairs from either panel → both editors show the counterpart
     summary card with working cross-links.
   - Unpair from either side → both revert to unpaired state.
   - Pair refusals: run-sheet already paired elsewhere → friendly flash, no
     500; forged cross-site planID → 404; non-admin non-coordinator POST →
     403; event-mismatch (worship bound to event X, run-sheet manually bound
     to event Y) → refused with flash.
   - eventID backfill: pair a worship plan bound to event E with a run-sheet
     whose eventID is NULL → run-sheet row gains eventID = E; a run-sheet
     with an existing eventID is never overwritten.
5. Regression: `/service-plans` (index/new/edit/save/item-save/print/live/
   confidence/live-toggle/live-message/message-poll) and `/worship` (plans/
   plan/plan-save/plan-reorder/present/display + `/api/worship/state|advance`)
   behave exactly as before for unpaired plans — no query on those paths
   changed.
6. PR checks (if pushed): pr-security bot comment clean (route-target,
   DDL, idempotency, column-drift, schema/seed parity), CodeQL/Psalm no new
   findings — fix or justify per the standing instruction.

## 10. Explicitly deferred (v2+ candidates — do NOT build now)

- One-shot, user-triggered "copy song sections → worship slides" (and/or
  reverse) with per-item confirmation — the only field-sync worth having, and
  only as an explicit action, never automatic.
- Paired badges on the two list pages (one extra LEFT JOIN each).
- Confidence monitor (`live.php`/`confidence.php`) showing the worship
  operator's current slide via the paired plan's `tblServicePlanState`.
- Auto-suggest pairing at creation time (worship plan bound to event E offers
  the run-sheet whose backfilled eventID = E, or same serviceDate).
- An event-detail page block listing both plans for the event.
- Any rename/merge of the confusingly-similar table names — never, or only in
  a major version with a compatibility view; out of scope permanently for
  this gap item.

## 11. Open questions (each with a recommended default — proceed on defaults)

- **Q1. Backfill `tblServicePlan.eventID` from the worship plan at pair time?**
  DEFAULT: **yes**, NULL-only, one-directional (worship→run-sheet), inside
  `pair()` (§3.2 rule 4). It revives a dead column additively, has no ACL
  effect, and strengthens the same-event invariant. Never the reverse.
- **Q2. Who sees pair/unpair controls on the RUN-SHEET side?** DEFAULT:
  render for `App::isAdmin()` only (worship-side panel uses its page's
  existing `$canWrite`, so coordinators keep control there); the handler
  enforces the real worship gate regardless. Alternative (coordinator
  detection per candidate) costs a query per row — not worth it in v1.
- **Q3. Paired badges on the list pages in v1?** DEFAULT: **no** — keep v1's
  footprint to the two editors; badges are a 10-line v2 follow-up.
- **Q4. Field sync in v1?** DEFAULT: **none** (read-only panels only). The
  song representations are incompatible (free text vs songID FK); any sync is
  deferred to an explicit user-triggered copy in v2 (§10).
- **Q5. Migration number.** DEFAULT: **173** (`173_worship_runsheet_link.sql`);
  re-confirm at build against `web/_sql/` + every remote head (§4). Do not
  occupy the 168/169 gap.
- **Q6. Pair overwrite semantics when the ACTING worship plan is already
  paired.** DEFAULT: allow — pairing overwrites that same row's
  runSheetPlanID (it is the row being edited, gate already passed); refuse
  only when the TARGET run-sheet is claimed by a different worship plan.
