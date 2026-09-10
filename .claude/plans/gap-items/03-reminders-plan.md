# Gap #3 — Scheduled Reminder Jobs: Implementation Plan

**Branch analysed:** `alpha` @ `4433b30` (includes Venues #429/#431, PayPal #432/#434, bind_param fixes #433/#437).
**Status:** build-ready plan, no code. All findings verified against real files with `file:line` evidence.
**Working tree used:** `/home/user/WebMS-Intra/.claude/worktrees/agent-a271ae7579be68996` (paths below are repo-relative; prefix with that root).

---

## 0. Executive summary — scope decision

**Build now (one PR, one migration `171`):**

1. **New cron endpoint `web/_apps/cron/user-reminders.php`** (routeKey `cron/user-reminders`, isProtected=0), cloned from the canonical `cron/asset-reminders.php` / `cron/venue-reminders.php` pattern, sweeping **three families**:
   - **`task-reminder`** — `tblTasks.reminderDate`/`reminderSent` (schema shipped in migration 036, *never consumed by any code*; the confirmed primary gap).
   - **`rota-slot`** — `tblRotaSlot.reminderSentAt` + `rota.reminder_days_before` (schema + setting shipped in migration 074, *never consumed*; FEATURES table promises "reminders").
   - **`milestone-digest`** — daily digest of today's birthdays/anniversaries to designated roles (`milestones.digest_recipients` seeded in migration 076, *never consumed*; the AppRegistry description at `web/_core/apps/milestones.php:7` promises a "daily digest" that does not exist).
2. **Repair the broken reference cron** `cron/event-reminders.php` — it selects `u.email` from `tblUsers`, but the real column is `emailAddress` (`web/_sql/full_schema.sql:177`). Under `mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR)` (`web/_core/bootstrap.php:287`) the very first prepare throws, so **the event-reminder cron currently 500s on every authenticated invocation**. Three-line fix, squarely in this gap's domain.
3. **Small write-path fixes so dedupe stamps stay correct** when the underlying date/assignee changes: `tasks/save.php` (reset `reminderSent` on future re-schedule), `tasks/complete.php` (carry `reminderDate` forward on recurrence spawn — today it is silently dropped), `rota/swap-respond.php` (clear `reminderSentAt` when a swap reassigns a slot).
4. **Two new notification-preference switches** (`taskReminders`, `rotaReminders`) on `/account/notifications`, honoured by the new sweep (default ON; absent key = ON).
5. **One new dedupe table `tblUserReminderLog`** (generic `(refType, refID, dueDate)` single-shot log, mirroring `tblAssetReminderLog`) — used today only by `milestone-digest` (tasks and rota use their existing schema columns as the guard), sized so deferred families (DBS expiry, visitor/care nudges) can join later with zero DDL.

**Defer (verified, classified below in §3):** DBS-expiry emails, visitor follow-up emails, care follow-up emails, expense approver nag, reading-plans daily nudge, weekly email digest, giving-pledge reminders, resource-booking reminders, service-plan prep reminders, the venue-cron I18n cosmetic bug, and the three non-cron `u.email` call sites.

**Migration number: `171`** (`web/_sql/` max is `170_venue_bookings.sql`; `168`/`169` are unused gaps — see §5.1).

**Owner action to call out:** add one DreamHost crontab line hitting `https://<host>/cron/user-reminders?key=<token>` every 15 minutes, after setting `user_reminders.cron_token` at `/admin/settings`.

---

## 1. Verified findings — cron infrastructure inventory

All six existing cron endpoints live in `web/_apps/cron/`. None is special-cased by the Router: `Router::handleSpecialRoutes` intercepts only `api/`, `e/`, `a/`, `01/`, `8003/`, `8004/` prefixes (`web/_core/Router.php:253-344`); `cron/*` resolves through **tblRoutes** like any route, seeded `isProtected = 0` (public but token-gated) — e.g. `('cron/event-reminders', 'cron/event-reminders.php', 0)` at `full_schema.sql:4716`, `cron/asset-reminders` at `:6731`, `cron/venue-reminders` at `:7334`. Route targets are `require`d with `global $mysqli, $SETTINGS;` in scope (`Router.php:138-139`).

| File | Sweeps | Token setting (read via) | Site iteration | Delivery | Dedupe guard |
|---|---|---|---|---|---|
| `cron/event-reminders.php` (183 ln) | Events: 24h / 1h / day-of windows | `reminders.cron_token` via `Settings::get` (`:29-33`) | ❌ none — single global pass, no `forceContext` | `Mailer::send` per RSVP'd user / coordinators+admins | `tblEventReminderLog` UNIQUE `(eventID, reminderType)` (`full_schema.sql:5378-5387`), `NOT EXISTS` pre-filter + `INSERT … ON DUPLICATE KEY UPDATE` (`:66-72`) |
| `cron/asset-reminders.php` (465 ln) | Assets: maintenance / warranty / insurance / loan-overdue + housekeeping | `assets.cron_token` via `Settings::get` (`:85-90`) | ✅ sites owning ≥1 asset (`:228-241`); `Site::forceContext` in try/catch-continue (`:261-272`); **no context reset after loop** (process exits) | `Mailer::send`; recipients via `AssetRegister::resolveReminderRecipients()` (role/admin holders) | `tblAssetReminderLog` UNIQUE `uq_astrl_ref (refType, refID, dueDate)` (`full_schema.sql:6526-6537`); check-first (`:148-158`) → send → INSERT in try/catch swallowing the concurrent-run race (`:173-190`); **recipientCount=0 still logs** (`:118-131`) |
| `cron/venue-reminders.php` (323 ln) | Venues: booking-unagreed / agreement-renewal / invoice-due | `venues.cron_token` via `Settings::get` (`:78-83`) | ✅ sites owning ≥1 active venue (`:139-152`); per-site enable flags read via `App::settingForSite` **inside** the loop (`:162-186`) — never ambient `Settings::get` (frozen snapshot; see its header `:34-43`); no reset after loop (`:308-309`) | `Mailer::send`; recipients via `Venues::resolveReminderRecipients()` (`Venues.php:3692-3738`) | `tblVenueReminderLog` UNIQUE `uq_venrl_ref` (`full_schema.sql:7236-7249`); `Venues::reminderAlreadySent()` (`Venues.php:3650-3662`) + `Venues::logReminder()` race-catch (`:3665-3683`) |
| `cron/discipleship-sweep.php` (67 ln) | Auto-completion freshness (not a reminder mailer) | `discipleship.cron_token` via `Settings::get` (`:34-39`) | ✅ sites owning ≥1 active pathway; delegates to `Discipleship::autoSweep()` per site | none (no mail) | idempotent `INSERT IGNORE` inside autoSweep |
| `cron/import-feeds.php` (152 ln) | ICS feed import | `feeds.cron_token` via `Settings::get` (`:24-28`) | per-feed `siteID` on the row; no forceContext | none | upsert keyed `(externalFeedID, externalUid)` |
| `cron/webhook-retry.php` (72 ln) | Outbound webhook retry | `webhooks.cron_token` via `App::settings()` (`:52-57`) | delegated to `WebhookDispatcher::retryDue()` | HTTP redelivery | `nextRetryAt` backoff columns |

**Token-gate canonical shape** (identical in all six; clone verbatim):
```php
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('<ns>.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403);
    exit('Forbidden');
}
```
- **Empty stored token fails closed** (always 403) — every migration seeds the token as `''` with `isSensitive = 1`.
- `isSensitive = 1` values are **encrypted at rest** via libsodium `encrypt_setting()` (`bootstrap.php:171-232`, key file `_auth_keys/enc.key` outside webroot) and transparently decrypted into the `$SETTINGS` snapshot at bootstrap (`bootstrap.php:361-371`) — so `Settings::get()` returns plaintext. The generic `/admin/settings` editor (`settings/index.php` + `settings/save.php`, route seed `full_schema.sql:4578`) encrypts on save for `isSensitive` rows (`settings/save.php:10`); DEV_NOTES documents "set via /admin/settings" as the owner workflow for `discipleship.cron_token`/`webhooks.cron_token` (`DEV_NOTES.md:2347-2367, 2380-2395`). (Venues additionally built a one-click regenerate button — `venues/settings.php:85-125` — optional nicety, not required.)
- `hash_equals` for constant-time compare; the incoming `?key=` is the only input and is never echoed or logged.

**Canonical reference cron to clone: `cron/asset-reminders.php`** (multi-family, multi-site, per-site logging, dedupe log with race-catch, zero-recipient logging) with the **per-site settings discipline of `cron/venue-reminders.php`** (`App::settingForSite()` inside the loop — `venue-reminders.php:34-43` explains why: `Site::forceContext()` does NOT refresh the frozen `$SETTINGS` snapshot).

**`Site::forceContext(int $siteId)`** (`web/_core/Site.php:448-488`): validates the site is active, throws on unknown/inactive site or when multisite is disabled and `$siteId` differs from current (no-op when equal — so single-site installs iterate `[1]` safely). House convention: wrap in try/catch + `Logger::errorPlatform` + `continue` (`asset-reminders.php:261-272`), **no reset after the loop** (`venue-reminders.php:308-309` documents this deliberately — the process exits).

**`App::settingForSite(string $key, int $siteId): ?string`** (`web/_core/App.php:166-187`): direct tblSettings read, site row wins over `siteID IS NULL` global. Note it returns the **raw stored value** (no decryption) — fine for flags/lead-days; the cron token must be read via `Settings::get()` (decrypted snapshot), exactly as `venue-reminders.php:79` does.

---

## 2. Verified findings — delivery layer

- **Email — `Portal\Core\Mailer::send(string|array $to, string $subj, string $body, array $files = []): bool`** (`web/_core/Mailer.php:62-98`). Dispatches to MS365 Graph or Google per `mail.provider`. Bodies containing HTML tags are sent as-is; plain text is auto-wrapped in the branded base template. Every existing reminder cron builds small inline HTML bodies with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` on every interpolation and calls `Mailer::send` per address after a `filter_var($email, FILTER_VALIDATE_EMAIL)` re-check at the dispatch point (`asset-reminders.php:160-171`). `Mailer::sendTemplated` (`:113-133`) exists but no cron uses it — follow the inline-body convention.
- **SMS — `Portal\Core\Sms`** (`web/_core/Sms.php`): `send(int $siteId, string $number, string $body, string $category, ?int $userId)` (`:43`) with per-user verification, category opt-in (`CATEGORIES` incl. `rota_changes` — `:28-38`), daily cap, Sabbath quiet hours. **No reminder cron sends SMS today**; keep v1 email-only (SMS noted as follow-up — `rota_changes` category already exists for it).
- **Web push:** `tblPushSubscriptions` with a `'reminders'` channel exists (`full_schema.sql:5168-5170`), but the only PHP touching it is `api/push/{subscribe,unsubscribe}.php` — there is **no server-side push sender** (no VAPID anywhere in `web/`). Push is out of reach for a cron; do not plan for it.
- **User notification preferences:** `tblUsers.notifyPrefs` JSON (`full_schema.sql:184`, migration 026). The `/account/notifications` page defines the pref vocabulary (`_apps/auth/account/notifications.php:75-84`: `emailDigest`, `eventReminders`, `eventRsvpConfirmation`, `expenseStatusUpdates`, `expenseApproverNudges`, `announcementsNew`, `prayerModeration`, `accountSecurity`) and `notifications-save.php:38-44` whitelists keys. **Verified: no sending code anywhere reads notifyPrefs** — the switches are captured but never honoured (the page's own header admits it: `notifications.php:19` "Admins flip the setting once Mailer + the digest cron…"). The new sweep will be the first honourer, for its own two new keys only.
- **"Don't send twice" patterns in the codebase (pick per family):**
  1. *Dedupe-log table* keyed `(refType, refID, dueDate)` with UNIQUE key, check-first + race-catch, zero-recipient sends still logged — `tblAssetReminderLog` / `tblVenueReminderLog` (evidence in §1). A re-scheduled date legitimately re-reminds (dueDate is part of the key — `full_schema.sql:7233-7234`).
  2. *Sent-flag column on the entity* — `tblTasks.reminderSent` TINYINT (`full_schema.sql:1317`) and `tblRotaSlot.reminderSentAt` DATETIME (`:2756`) were **designed for exactly this** (composite index `idx_task_reminder (reminderDate, reminderSent)` at `:1327`) but never used.
  3. *`ON DUPLICATE KEY UPDATE` log upsert* — event cron (`event-reminders.php:66-72`).

---

## 3. Verified findings — every entity carrying a reminder/due field, classified

Legend: **(a)** already covered by a cron · **(b)** real gap — build now · **(c)** out of scope / defer (with reason).

| Entity / field | Evidence | Cron today? | Class |
|---|---|---|---|
| `tblEvents.startDateTime` 24h/1h/day reminders | cron exists (`cron/event-reminders.php`) **but is fatally broken**: `u.email` ×5 (`:47,50,141,142`) vs real column `emailAddress` (`full_schema.sql:177`); strict mysqli (`bootstrap.php:287`) throws at the first `prepare` (`:81`), before any window logic. The codebase itself documents the trap: `_apps/assets/found-save.php:254-257` ("NOT `u.email`, which some other call sites in this codebase reference but does not exist as a column"). | (a) nominally — **broken** | **(b)** repair in this PR |
| `tblTasks.reminderDate` + `reminderSent` + `dueDate` | schema `full_schema.sql:1305,1315-1317,1326-1327` (migration 036). UI captures reminderDate (`tasks/index.php:208-210`; `tasks/save.php:48,92,100,112,119`). **Zero readers of `reminderSent` anywhere in PHP** (grep: only SQL files match). `tasks/api/` create/complete never touch reminderDate. | none | **(b)** primary |
| `tblRotaSlot.reminderSentAt` + `rota.reminder_days_before` | schema `full_schema.sql:2756`, setting seeded `'3'` at `:2800` (migration 074). **Zero PHP readers of either.** FEATURES table promises "reminders". `slot-save.php` is delete-or-insert only (`:32-61`); `swap-respond.php:59` reassigns `assignedToID` without clearing the stamp. | none | **(b)** |
| Milestones daily digest (`tblUserMilestone`, `milestones.digest_recipients`) | table `full_schema.sql:2541-2555` (**no siteID column** — user-scoped; `monthDay` CHAR(5) `MM-DD`, `privacy` enum, `originYear`); setting seeded `''` at `:2564`; description promises "daily digest for designated roles" (`_core/apps/milestones.php:7`). App is view-only (`milestones/index.php`). **Zero readers of `digest_recipients`.** | none | **(b)** |
| Visitors follow-up cadence (`tblVisitorContact.nextContactAt`, `visitors.followup_*_days`) | `full_schema.sql:2670-2695`; surfaced in-app at `/visitors/my-follow-ups` (`my-follow-ups.php:27-30,42` uses only `followup_initial_days`); `followup_followup_days`/`final_days` are dead seeds. | none | **(c)** — in-app kanban/list is the designed surface; an email nudge is net-new product behaviour, not a missing sweep of a designed field. Note in the ticket backlog. |
| Care follow-ups (`tblCareVisit.followUpAt`, `followUpAssignedToID`, `idx_care_visit_followup`) | `full_schema.sql:2598-2604`; shown in-app (`care/case.php:139-140`). | none | **(c)** — confidential register; emailing pastoral data is a safeguarding/GDPR decision, not a mechanical gap. If ever built: content-free "N follow-ups due" nudge only. Defer to its own issue. |
| DBS check expiry (`tblDbsChecks.expiresAt`, `idx_dbs_expiring_soon`, `safeguarding.dbs_renewal_warning_days`) | `full_schema.sql:5426-5442`, setting `:4708-4709`; in-app pills only (`admin/safeguarding/dbs.php:38`, `account/safeguarding.php:23`). Table is user-scoped (no siteID). | none | **(c)** — genuine gap, but recipients (the user + safeguarding leads) and cross-site scoping need their own small design; `tblUserReminderLog` (this PR) is deliberately generic so a `dbs-expiry` family can be added later with no DDL. Recommend a follow-up issue. |
| Expense approver nag (`expenses.reminder.throttleHours`) | seeded `full_schema.sql:1683`, **never read**; `expenses.followUpDays` only feeds email copy (`ExpenseMailer.php:157`). `notifyPrefs.expenseApproverNudges` switch exists unused. | none | **(c)** — no due/reminder field on the claim; "pending too long" is a workflow-SLA feature owned by the expenses app. Defer. |
| Reading-plans daily nudge (`reading_plans.daily_reminder`) | seeded `full_schema.sql:3281`, **never read**. | none | **(c)** — daily fan-out to every enrolled member needs a per-user opt-in surface first; spam risk. Defer. |
| Weekly email digest (`notifications.digestEnabled/digestDay`, `notifyPrefs.emailDigest`) | seeds `full_schema.sql:1646-1658`; `/account/notifications` admits it's aspirational (`notifications.php:19`). | none | **(c)** — a content-aggregation feature, not a reminder sweep. Defer. |
| Giving pledges | `tblPledgeCampaigns.startDate/endDate` only (`151_giving_pledge_campaigns.sql:53-77`); **no per-pledge due date exists**. | n/a | **(c)** — nothing to sweep. |
| Resource bookings (`tblResourceBooking.startAt/endAt`) | `full_schema.sql:3399+`; no reminder column/setting was ever designed. | none | **(c)** — net-new feature. |
| Service plans | no due/prep date field found. | n/a | **(c)**. |
| Assets (4 families), Venues (3 families), Discipleship, Webhooks, Feeds | §1 | yes | (a) |
| Adjacent, same-family bugs found (not blocking): venue cron email subjects render as raw I18n keys — `venue-reminders.php:206,234,269` call `I18n::t('venues.reminder.*_subject')` but no such keys exist in `web/_lang/en.php` (grep: 0 hits) and `I18n::t` falls back to the key itself (`I18n.php:252-263`). Other `u.email` call sites: `calendar/event-broadcast-send.php:64-92`, `admin/calendar/coordinators.php:55`, `admin/safeguarding/dbs.php:44-45`. | — | — | **(c)** — file one bug ticket listing all four; only `cron/event-reminders.php` is fixed here (it is this gap's own infrastructure). |

---

## 4. Design

### 4.0 Endpoint architecture

**One new endpoint, `_apps/cron/user-reminders.php`, three families** — precedented by `asset-reminders.php` (4 families) and `event-reminders.php` (mixing 15-minute and once-daily families in one endpoint, gating the daily one on an hour window at `:126-127`). Rationale over three per-app endpoints: one token, one tblRoutes row, one DreamHost crontab line, one owner action; the three families are all "remind a person about their own upcoming item". Cadence: **every 15 minutes** (matches the event cron's documented recommendation, `admin/calendar/reminders.php:60`); task reminders have DATETIME precision so a daily cadence would be wrong for them.

Token: **new global `user_reminders.cron_token`** (seeded `''`, `isSensitive = 1`). A non-app settings namespace is precedented (`reminders.*` and `feeds.*` are not apps). Deliberately NOT reusing `reminders.cron_token`: per-endpoint tokens are the shipped convention (six of six), and rotation must not couple two crontab entries.

Skeleton (mirror `asset-reminders.php` structure exactly):
1. `declare(strict_types=1)`; `use Portal\Core\{App, Logger, Mailer, Settings, Site};` — file-header comment block in house format citing this plan's issue number.
2. Token gate — clone `asset-reminders.php:85-90` verbatim with `user_reminders.cron_token`; **before** any output/header. Read via `Settings::get` (decrypted snapshot).
3. `header('Content-Type: text/plain; charset=utf-8');`
4. Global kill-switch: `if ((string) Settings::get('user_reminders.enabled', '1') !== '1') { echo 'User reminders disabled'; exit(); }` (mirrors `assets.reminders_enabled` gate at `asset-reminders.php:94-97`).
5. `$db = App::db();` — never bare `$mysqli` (both work under `Router.php:138`, but `App::db()` is what asset/venue crons use).
6. Site list: **all active sites** — `SELECT siteID FROM tblSites WHERE isActive = 1` (mild, justified deviation from "sites owning ≥1 candidate": with three families a union pre-filter is noisier than three cheap empty result sets; `forceContext` on an idle site is harmless, and single-site installs yield `[1]` = current context = no-op).
7. Per site: `Site::forceContext($siteId)` in try/catch → `Logger::errorPlatform('UserReminders', 'Warning', 'USER_REMINDERS_SITE_CONTEXT_FAIL', …)` → `continue` (clone `asset-reminders.php:261-272`). Then the three families, each guarded by its per-site flags read via **`App::settingForSite(...)`** (venue discipline, `venue-reminders.php:162-186`). Per-site `Logger::activity('UserRemindersRun', sprintf(...))` (clone `asset-reminders.php:434-447`). **No context reset after the loop** (house convention, `venue-reminders.php:308-309`).
8. Grand-total text/plain summary, one line per family (clone `asset-reminders.php:453-462`) — greppable from scheduler logs.
9. A small local `userReminderUrl(string $path): string` helper cloning `venueReminderUrl` (`venue-reminders.php:95-101`) for absolute links (`$_SERVER['HTTPS']`/`HTTP_HOST` — connection-derived, not user input).
10. A small local `notifyPrefAllows(?string $notifyPrefsJson, string $key): bool` helper: `json_decode`; missing/invalid JSON or absent key ⇒ `true` (default-on, matching the defaults-array semantics of `notifications.php:75-84`); only an explicit `false` suppresses.

Email subjects/bodies: **plain English with emoji prefix** (asset-cron style, `asset-reminders.php:294-300`) — NOT `I18n::t` (the venue cron's untranslated-key bug, §3 last row, shows why not without seeding lang keys).

### 4.1 Family `task-reminder`

- **Gate:** `tasks.enabled` (seeded `'true'` — `full_schema.sql:2269`; accept `'1'` or `'true'` via `in_array($v, ['1','true'], true)`, the tolerant check from `venue-reminders.php:163`) AND new `tasks.reminders_enabled` ≠ `'0'`.
- **Candidate query** (uses `idx_task_reminder`):
  `SELECT t.taskID, t.title, t.description, t.priority, t.dueDate, t.reminderDate, u.emailAddress, u.fullName, u.notifyPrefs FROM tblTasks t INNER JOIN tblUsers u ON u.userID = t.assignedToID AND u.isActive = 1 WHERE t.siteID = ? AND t.isDeleted = 0 AND t.status IN ('pending','in_progress') AND t.reminderSent = 0 AND t.reminderDate IS NOT NULL AND t.reminderDate <= NOW() AND t.reminderDate >= DATE_SUB(NOW(), INTERVAL ? DAY)` — the second param is new setting `tasks.reminder_lookback_days` (default 7): **first-activation backlog guard** — installs have years of rows with `reminderSent = 0`; anything older than the lookback simply never matches (no blast, no need to claim it).
- **Recipient:** the assignee only (`u.emailAddress`, re-validated with `FILTER_VALIDATE_EMAIL` at dispatch, per `asset-reminders.php:160-171`), suppressed when `notifyPrefAllows(notifyPrefs, 'taskReminders') === false`.
- **Send order & dedupe (sent-flag column, atomic claim):** send the email(s), then `UPDATE tblTasks SET reminderSent = 1 WHERE taskID = ? AND reminderSent = 0`; `affected_rows === 0` ⇒ a concurrent run won — count as `skipped`, exactly the acknowledged narrow-race trade-off documented at `asset-reminders.php:37-43,183-190`. **Stamp even when the recipient was suppressed/invalid** (recipientCount=0 outcome still claims — asset precedent `:118-131` — otherwise the row retries forever).
- **Body:** title (escaped), priority, dueDate if set, truncated description, link `userReminderUrl('/tasks?edit=' . $taskId)`. Subject: `⏰ Task reminder: {title}`.
- **Write-path fixes (same PR):**
  - `tasks/save.php` UPDATE branch (`:90-105`): when the posted `reminderDate` is non-null and in the future, also reset `reminderSent = 0` (PHP-side decision, extra bound param via `reminderSent = COALESCE(?, reminderSent)` with `0` or `NULL`) — today an edited reminder never re-fires.
  - `tasks/complete.php` recurrence spawn (`:123-141`): the INSERT drops `reminderDate` entirely, so recurring tasks get exactly one reminder ever. Add `reminderDate` to the column list, advanced by the same `DateTime::modify` interval applied to `dueDate` (`:100-115`) when the parent has one (new row's `reminderSent` defaults 0). Mirror the same fix in `tasks/api/complete.php` **only if** it also spawns (verify at build time; grep showed no `reminderDate` handling there).
- **Cited pattern:** dedupe = §2 pattern 2 (the columns migration 036 built for this); flow = `sendAssetReminder` (`asset-reminders.php:133-210`) with the log-INSERT swapped for the claim-UPDATE.

### 4.2 Family `rota-slot`

- **Gate:** `rota.enabled` (seeded `'0'` — `full_schema.sql:2799`; tolerant check) AND new `rota.reminders_enabled` ≠ `'0'`.
- **Candidate query** (lead from existing `rota.reminder_days_before`, default 3, via `settingForSite`):
  `SELECT s.slotID, s.slotDate, s.startTime, s.endTime, s.notes, s.assignedToID, r.name AS roleName, u.emailAddress, u.fullName, u.notifyPrefs FROM tblRotaSlot s INNER JOIN tblRotaRoleType r ON r.roleTypeID = s.roleTypeID INNER JOIN tblUsers u ON u.userID = s.assignedToID AND u.isActive = 1 WHERE s.siteID = ? AND s.assignedToID IS NOT NULL AND s.reminderSentAt IS NULL AND s.slotDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) ORDER BY s.assignedToID, s.slotDate, s.startTime` (uses `idx_rota_slot_site_date`).
- **Grouping:** one email per assignee per site per run listing all their due slots (role, date, time window, notes) — friendlier than per-slot mail; the dedupe stays per-slot.
- **Recipient:** the assignee, suppressed by `notifyPrefAllows(..., 'rotaReminders') === false`.
- **Dedupe:** after the grouped send, stamp each included slot: `UPDATE tblRotaSlot SET reminderSentAt = NOW() WHERE slotID = ? AND reminderSentAt IS NULL` (same claim/race semantics as 4.1; stamp suppressed/invalid recipients too).
- **Body:** list of duties + link `userReminderUrl('/rota')`. Subject: `📅 Rota reminder: {n} upcoming duty/duties`.
- **Write-path fix (same PR):** `rota/swap-respond.php:59` — extend the acceptance UPDATE to `SET assignedToID = ?, reminderSentAt = NULL WHERE slotID = ?` so the incoming person gets their own reminder. (`slot-save.php` has no update path — delete/insert only, `:32-61` — nothing to fix there.)

### 4.3 Family `milestone-digest`

- **Gate:** hour window `06:00–08:59` server time (`(int) date('H') >= 6 && <= 8`, exactly the event cron's day-of family, `event-reminders.php:126-127`) AND per-site `milestones.enabled` truthy AND new `milestones.digest_enabled` ≠ `'0'` AND `milestones.digest_recipients` CSV **non-empty** (empty ⇒ skip site: the digest is explicit opt-in — privacy-conservative; see Open Question 2).
- **Dedupe:** the entity has no sent-flag ⇒ §2 pattern 1: new **`tblUserReminderLog`**, `refType = 'milestone-digest'`, `refID = siteID`, `dueDate = CURDATE()`. Check-first helper + INSERT wrapped in try/catch for `\mysqli_sql_exception` (clone `Venues::reminderAlreadySent`/`logReminder`, `Venues.php:3650-3683`, as two small local functions in the cron file — no new `_core` class needed for one caller). Zero-recipient outcome still logs (when milestones existed but no valid recipient resolved); a day with **no milestones sends nothing and logs nothing** (cheap re-query on later runs in the window is harmless).
- **Data query** (note `tblUserMilestone` has **no siteID** — scope through site membership):
  `SELECT m.kind, m.label, m.monthDay, m.originYear, u.fullName FROM tblUserMilestone m INNER JOIN tblUsers u ON u.userID = m.userID AND u.isActive = 1 INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 WHERE m.monthDay = DATE_FORMAT(CURDATE(), '%m-%d') AND m.privacy <> 'private' ORDER BY u.fullName` — includes `team`-privacy rows deliberately (recipients are designated leadership roles; `private` always excluded). `originYear` → "(Nth)" ordinal when non-null.
- **Recipients:** CSV roleKeys from `settingForSite('milestones.digest_recipients', $siteId)` resolved with the same role→email SQL shape as `Venues::resolveReminderRecipients` (`Venues.php:3705-3730`: `tblUsers u INNER JOIN tblUserSites us … LEFT JOIN tblUserRoles ur … LEFT JOIN tblRoles r … WHERE u.isActive = 1 AND u.emailAddress …`), but **roles-only — no implicit admin union** (privacy-tighter than venues; admins opt in by holding a listed role). Implement as a local function; **use `u.emailAddress`** (the real column — §3 row 1).
- **Body:** one line per milestone (`fullName — kind[/label] — Nth`), no years for `originYear IS NULL`, link `userReminderUrl('/milestones')`. Subject: `🎂 Today's milestones — {j M}`.

---

## 5. Migration `171_user_reminders.sql`

### 5.1 Number

`web/_sql/` max on `alpha` is `170_venue_bookings.sql` (167 = PayPal; **168/169 are unused gaps** — no remote branch (`alpha`, `beta`, `main`, `claude/venue-b..f`, dependabot) ships them; they were skipped during the venues renumbering). Take **171**; implementer must re-scan `git ls-remote` + `web/_sql/` at build time in case another in-flight branch claims it first.

### 5.2 Contents (in order; zero ALTERs, so zero `information_schema` guards needed — the 170 precedent header even brags "ZERO guarded ALTERs by design"; everything is `CREATE TABLE IF NOT EXISTS` + `INSERT … ON DUPLICATE KEY UPDATE`, which `check_migration_idempotency.py` accepts and MySQL 8.0 accepts — no MariaDB-only syntax anywhere)

1. **Header comment** in the migration-170 house format (`170_venue_bookings.sql:1-60`): purpose, issue link, idempotency statement, prefix reservation, and the **`--`-in-COMMENT trap note** (never a literal `--` inside a COMMENT string — `check_schema_seed_parity.py` strips from `--` to EOL even inside quotes; use an em dash — `170_venue_bookings.sql` header documents this).
2. **`CREATE TABLE IF NOT EXISTS tblUserReminderLog`** — mirror `tblAssetReminderLog` (`full_schema.sql:6526-6541`) exactly in shape and philosophy:
   - `logID INT NOT NULL AUTO_INCREMENT` PK
   - `siteID INT NOT NULL` (attribution only — **no FK by design**: the log must survive deletion of what it reminded about, per `tblVenueReminderLog`'s comment at `full_schema.sql:7230-7234`)
   - `refType VARCHAR(30) NOT NULL` COMMENT naming today's single value `milestone-digest` and the intent that future families (dbs-expiry etc) reuse it (VARCHAR not ENUM so no ALTER is ever needed)
   - `refID INT NOT NULL` (for `milestone-digest`: the siteID)
   - `dueDate DATE NOT NULL` (digest date; part of the key so each day re-fires)
   - `recipientCount INT NOT NULL DEFAULT 0`
   - `sentAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
   - `UNIQUE KEY uq_usrrem_ref (refType, refID, dueDate)`; `KEY idx_usrrem_site (siteID, sentAt)`
   - Prefix `usrrem`/`fk_usrrem` verified collision-free against full_schema (0 grep hits).
3. **Settings seeds** — single `INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES … ON DUPLICATE KEY UPDATE defaultValue = VALUES(defaultValue)` (the exact idiom of `170_venue_bookings.sql:575+`):
   - `(NULL, 'user_reminders.cron_token', '', '', 1)` — **empty ⇒ endpoint inert** (fails closed)
   - `(NULL, 'user_reminders.enabled', '1', '1', 0)`
   - `(NULL, 'tasks.reminders_enabled', '1', '1', 0)`
   - `(NULL, 'tasks.reminder_lookback_days', '7', '7', 0)`
   - `(NULL, 'rota.reminders_enabled', '1', '1', 0)`
   - `(NULL, 'milestones.digest_enabled', '1', '1', 0)`
   - (`rota.reminder_days_before` and `milestones.digest_recipients` already seeded — migrations 074/076 — do **not** re-seed.)
4. **Route seed:** `INSERT INTO tblRoutes (routeKey, targetFile, isProtected) VALUES ('cron/user-reminders', 'cron/user-reminders.php', 0) ON DUPLICATE KEY UPDATE targetFile = VALUES(targetFile)` (isProtected=0, token-gated — `full_schema.sql:4716` precedent).
5. **Self-record:** `INSERT INTO tblMigrations (filename) VALUES ('171_user_reminders.sql') ON DUPLICATE KEY UPDATE filename = filename;` (mandatory — installer replays every migration ignoring tblMigrations; `170_venue_bookings.sql` tail is the template).

### 5.3 full_schema.sql fold (schema/seed parity — `check_schema_seed_parity.py`)

Append, in a `-- ── from 171_user_reminders.sql ──` block matching the house fold style (cf. `full_schema.sql:5377` "from 122_event_reminders.sql"):
- the `tblUserReminderLog` CREATE (verbatim),
- the six settings rows into a seeds block,
- the `cron/user-reminders` route row,
- the `('171_user_reminders.sql')` tblMigrations seed (cf. `:2395` precedent for 036).

**No DDL touches existing tables anywhere in this feature** — `tblTasks` and `tblRotaSlot` already carry their guard columns; that is the whole point of choosing pattern 2 for them.

---

## 6. File list

**New:**
| File | Purpose |
|---|---|
| `web/_apps/cron/user-reminders.php` | The sweep: token gate, 3 families, per-site loop, dedupe, text/plain summary (§4) |
| `web/_sql/171_user_reminders.sql` | Log table + settings + route seeds + self-record (§5) |

**Changed:**
| File | Change (one line each) |
|---|---|
| `web/_sql/full_schema.sql` | Fold block for 171 (§5.3) |
| `web/_apps/cron/event-reminders.php` | `u.email` → `u.emailAddress AS email` (+ WHERE clauses) at `:47-50` and `:141-142` — unbreaks the existing cron |
| `web/_apps/tasks/save.php` | Reset `reminderSent = 0` on update when new `reminderDate` is future (§4.1) |
| `web/_apps/tasks/complete.php` | Carry `reminderDate` forward (interval-shifted) into the recurrence spawn INSERT (§4.1) |
| `web/_apps/rota/swap-respond.php` | Add `reminderSentAt = NULL` to the accept-swap UPDATE at `:59` (§4.2) |
| `web/_apps/auth/account/notifications.php` | Add `taskReminders` + `rotaReminders` to the defaults array (`:75-84`) and two `$switchRow` entries (near `:162`) |
| `web/_apps/auth/account/notifications-save.php` | Add both keys to the whitelist (`:38-44`) |
| `DEV_NOTES.md` | New "User reminders cron" section in the cron-token setup area (~`:2347`): token, URL, crontab line, family semantics |
| `FEATURES.md` | Row(s) for the sweep + repaired event cron, migration 171 |
| `CHANGELOG.md` | Entry |
| `.claude/CLAUDE.md` (+ `.claude/HANDOFF.md` if in use) | Memory update per standing instructions |

**Deliberately NOT touched:** `web/_core/*` (no new class — two small local helper functions in the cron file suffice for one caller; promote to a `_core` class only when a second cron needs them), `api-spec.json`/OpenAPI (no API change), AppRegistry (no new app), `web/_core/version.php` (not a release), `_lang/*` (plain-English emails), `account/index.php`'s legacy 3-switch block (canonical prefs page is `notifications.php`).

---

## 7. House conventions the implementer MUST follow

- `declare(strict_types=1)` first statement of every touched PHP file; house file-header comment (path, description, package `Portal\App\Cron`, author, copyright All Rights Reserved, version, issue `@link`).
- **Full IF notation** everywhere: `if ($x === true)`, `hash_equals(...) === false`, `!== null` — see any cron for the register.
- **MySQLi prepared statements only**; count `bind_param` types vs args carefully (`check_bind_param_arity.py` now gates this — added by #433/#437 after two real arity fatals).
- `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` on **every** interpolation into email HTML (asset cron does this even for dates).
- Token gate before any output; token read via `Settings::get` (decrypted); per-site flags/tunables via `App::settingForSite` **inside** the loop (never ambient `Settings::get`/`App::settings()` for per-site values — `venue-reminders.php:34-43`).
- `Site::forceContext` in try/catch-continue; no reset after the loop; `Logger::activity(type, description, ?userId)` (`Logger.php:48`) once per site inside the loop (so `Site::id()` attribution is correct — `asset-reminders.php:429-434`).
- **`u.emailAddress`**, never `u.email` (§3 row 1 — the exact trap this PR also fixes).
- Emoji-annotated section comments; platform-neutral paths (`DIRECTORY_SEPARATOR`) — n/a for the cron beyond the header, applies to any `require`.
- Migration: MySQL-8-safe only (no MariaDB `IF NOT EXISTS` on ALTER/INDEX — none needed here); every statement idempotent; self-record with `ON DUPLICATE KEY UPDATE filename = filename`; fold into full_schema; no literal `--` inside SQL COMMENT strings.
- ApiRouter trap: **N/A** — `cron/*` is ordinary tblRoutes routing (verified `Router.php:253-344`); do NOT create `api.*.enabled` flags or `_apps/*/api/` paths for this.
- No `<table>` in any HTML surface touched (`notifications.php` uses switch rows; emails use `<p>`/lists as the existing crons do).

## 8. Security checklist

- [ ] Empty `user_reminders.cron_token` ⇒ unconditional 403 (`$expected === '' ||` first) — endpoint inert until the owner sets a token.
- [ ] `hash_equals($expected, $incoming)` — constant-time; `$incoming` cast to string, never logged, never echoed, never written to `Logger::*` or the text/plain summary.
- [ ] Token seeded `isSensitive = 1` ⇒ encrypted at rest (libsodium, `bootstrap.php:171-232`); set via `/admin/settings` which encrypts on save.
- [ ] The only query param is `key`; no other `$_GET`/`$_POST` is read (if any is added later, validate/cast).
- [ ] Recipient scoping is per-site: tasks/rota rows are `siteID`-filtered; milestone recipients and milestone subjects both join `tblUserSites us.siteID = ? AND us.isActive = 1`; digest excludes `privacy = 'private'`.
- [ ] Assignee-only delivery for tasks/rota — no broadcast; milestone digest goes only to explicitly configured roleKeys (no implicit admin union).
- [ ] Every address `FILTER_VALIDATE_EMAIL`-checked at the dispatch point; `array_unique` before send.
- [ ] Email bodies contain no secrets and nothing beyond what the recipient already sees in-app; all interpolations escaped.
- [ ] `$_SERVER['HTTPS']`/`HTTP_HOST` link builder: connection-derived values only (documented safe at `asset-reminders.php:101-109`).
- [ ] Dedupe claims are atomic (`WHERE reminderSent = 0` / `WHERE reminderSentAt IS NULL` / UNIQUE-key race-catch) — re-running the cron the same day never double-sends; overlapping-run window documented as the accepted asset-cron trade-off.
- [ ] One misbehaving site cannot abort other sites (try/catch-continue).
- [ ] No user input reaches SQL except via bound params (and the only input is the token, which never reaches SQL).

## 9. Acceptance gates

1. `php -l` clean on every touched PHP file (PHP 8.4 available in-repo env; code must be 8.4-compatible).
2. **All 11 audit checks green** (`tools/audit-checks/`): `check_bind_param_arity`, `check_cdn_sri`, `check_mariadb_only_ddl`, `check_migration_idempotency`, `check_mobile_readiness`, `check_no_native_confirm`, `check_php_table_refs` (tblUserReminderLog must be in schema before the PHP referencing it), `check_route_targets` (route row ↔ `cron/user-reminders.php` both present), `check_schema_seed_parity` (171 ↔ full_schema fold), `check_settings_keys` (all six new keys seeded), `check_sql_columns` (the `u.email`→`emailAddress` fix *removes* existing drift).
3. **Idempotent replay:** run `171_user_reminders.sql` twice against an up-to-date schema — second run is a no-op (CREATE IF NOT EXISTS + ON DUPLICATE seeds); e2e-migrations harness green.
4. **Run-twice test (the core behavioural gate):** seed one due task, one rota slot in-window, one milestone today + configured digest role; hit the endpoint twice in a row ⇒ first run sends 1+1+1, second run reports `skipped(already-sent)` for all three and sends nothing. Then: edit the task's reminderDate to a future time, complete a recurring task, accept a rota swap ⇒ verify each re-arms exactly one new reminder.
5. **Fail-closed test:** empty token ⇒ 403; wrong token ⇒ 403; correct token + `user_reminders.enabled = '0'` ⇒ "disabled" and no sends.
6. **Event-cron regression:** after the `emailAddress` fix, hit `/cron/event-reminders?key=…` with a published event ~24h out and a confirmed RSVP ⇒ no exception, one send, one `tblEventReminderLog` row.
7. **PR Security checks / CodeQL / Psalm** monitored and cleaned per standing instruction.
8. **Owner action (release note + DEV_NOTES):** set `user_reminders.cron_token` at `/admin/settings`, then add the DreamHost crontab entry:
   `0,15,30,45 * * * * curl -fsS "https://<host>/cron/user-reminders?key=<token>" > /dev/null`
   (format precedent: `admin/calendar/reminders.php:60`, `DEV_NOTES.md:2391`). Without this line the sweep never runs — the deploy alone is not sufficient.

## 10. Open questions (each with a recommended default — proceed on the defaults unless overruled)

1. **One combined endpoint vs three per-app crons?** → **Recommended: one (`cron/user-reminders`)** — one token/route/crontab line; precedented by the multi-family asset cron and the mixed-cadence event cron. Split later only if per-family scheduling needs diverge.
2. **Milestone digest when `milestones.digest_recipients` is empty:** skip site (explicit opt-in) vs fall back to site admins? → **Recommended: skip** — birthday data + privacy tiers make silent-default distribution the wrong failure mode; the setting has existed since migration 076 precisely to designate recipients.
3. **Honour new `taskReminders`/`rotaReminders` notifyPrefs now** (first sender ever to honour prefs) vs ship without prefs like every existing cron? → **Recommended: honour now** — trivial (one JSON decode; default-on so behaviour is unchanged for everyone who never touched the page), and it stops the prefs page being 100% decorative.
4. **Fix the other three `u.email` call sites** (`calendar/event-broadcast-send.php`, `admin/calendar/coordinators.php`, `admin/safeguarding/dbs.php`) in this PR? → **Recommended: no** — separate bug ticket citing §3's evidence (keeps this PR reviewable and on-scope); only the cron file is repaired here.
5. **DBS-expiry reminders as a 4th family now?** → **Recommended: defer** to its own small issue — recipients (subject user + safeguarding leads) and the no-siteID scoping deserve their own decision; `tblUserReminderLog.refType='dbs-expiry'` is reserved for it, so it lands later with zero DDL.
