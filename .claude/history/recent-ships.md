# Recent ships — history moved out of `.claude/CLAUDE.md`

**What this is.** The "Recent ships (chronological)" section that used to sit inside
`.claude/CLAUDE.md`, copied here word for word. It is a record of how the product got
here, written as each piece shipped; it has not been rewritten since, so where it says
something is "in flight", that was true when it was written and may not be now.

**Why it moved.** It was about 40% of `.claude/CLAUDE.md`, and that file is loaded into
every Claude Code session, so every session paid for reading history it did not need.
Moved on 5 October 2026 at the owner's decision (Salem874), following two reviews of the
instruction files (`.claude-work/resume/instructions-review.md` and
`instructions-audit-2.md`). Claude Code does not load this folder by itself, so nothing
here is lost and nothing here costs a session anything.

**Where history lives from now on.** `CHANGELOG.md` is the record going forward. Do not
add new entries here.

**Two traps that were buried in this text** were checked before the move and are already
in `DEV_NOTES.md`, where people look for them: the `check_sql_columns.py` alias trap
("`check_sql_columns.py` false positive: a table name embedding a bare SQL keyword + a
short alias (#150)") and the `full_schema.sql` fold pattern ("`full_schema.sql` fold
pattern: an ALTER's FK target is created LATER in the file").

---

## Recent ships (chronological)

- **`claude/backlog156-reports`** (branched off `alpha`) — issue #156:
  Reports Builder, a whitelist-driven custom report builder at
  `/admin/reports/builder/*` alongside the pre-existing #93 fixed
  dashboards. `Portal\Core\ReportRegistry` (pure static data — no DB, no
  superglobal reads) is the ENTIRE whitelist: six v1 sources (users,
  events, attendance, expenses, giving, tasks), each a registry-owned
  table/alias/tenant-scope-expression/curated-JOINs entry with a closed
  per-column expr/type/gates/aggs whitelist; closed keyed sets for
  operators, aggregations, and date-bucket transforms.
  `ReportRegistry::assertSelfConsistent()` hard-fails if a future edit
  ever references care/kids/safeguarding/prayer-requests/auth/settings/
  API-key tables — those domains are structurally absent, not merely
  gated. `Portal\Core\ReportBuilder::compile()` is the ONE place report
  SQL is assembled: every identifier reaches the SQL string only via
  strict key lookup against the registry; every value is bound via
  `bind_param()` with a lockstep types string + an explicit
  `strlen()===count()` assert; `siteID = ?` is force-injected first,
  outside the user-filter parentheses, so no `OR` can bypass tenancy.
  Column gates (a role, `@siteAdmin`, or `@rootAdmin`) apply identically
  in SELECT and WHERE, closing the filter-as-oracle leak; financial
  totals need Treasurer/Site Admin, Giving donor identity needs Treasurer
  strictly. A saved definition is re-validated against the registry on
  EVERY run — a hand-edited DB row fails closed. Builder UI: drag-
  reorderable column chips (`Asset::sortableJs()`), repeatable filter rows
  (one AND/OR toggle), optional group-by + aggregates, an AJAX preview
  endpoint (session-authed, outside `api/*` — the `geo/` precedent), CSV
  export, and a bar/line chart on grouped results via a new SRI-pinned
  `Asset::chartJs()` (Chart.js 4.4.4, hash independently re-derived from
  the npm registry tarball — jsdelivr itself was policy-denied from this
  build's sandbox proxy). Migration 184: `tblReportDefinitions`
  (DEVIATION from #156's literal `tblReports` — documented in the
  migration header), 3 settings seeds (`reports.enabled` seeded ON so the
  #93 dashboards survive the upgrade unchanged), 8 route seeds. New
  AppRegistry entry `reports` folds BOTH the dashboards and the builder
  under one toggle. GDPR lockstep in the same PR (GdprEraser catalogue
  entry + data-export.php block). A committed, dependency-free red-team
  self-test (`tools/report-builder-selftest.php`) exercises the real
  classes — registry self-consistency, a benign compile with tenant scope
  provably first, and 15 hostile/malformed definitions each throwing
  `InvalidArgumentException` before any SQL string exists. All 11 audit
  checks green, `php -l` clean on every touched file, `node --check`
  clean on the new JS.
- **`claude/backlog153-forms`** (branched off `alpha`) — issue #153: new
  Forms Builder app at `/forms` (`web/_apps/forms/`, 16 pages/handlers) +
  `Portal\Core\FormEngine` — the single injection-safety boundary for a
  12-type field registry (`FormEngine::FIELD_TYPES` — a PHP whitelist, NOT a
  SQL ENUM, so adding a type is code-only, never a migration), a whitelist
  config sanitiser (`sanitiseConfig()` — `configJson` is DATA, re-sanitised
  on every read AND write), an escaped-everything renderer
  (`f_{fieldID}` server-integer field names; choice fields submit
  bounds-checked integer indexes, never raw option text), per-type
  server-side validators, and immutable `answersJson` snapshot persistence
  (`{fieldKey:{label,type,value}}`, survives later field edits/deletes).
  Admins build/publish forms at `/forms/edit` + `/forms/manage`; members
  fill published internal/both forms at `/forms/fill`; an OPTIONAL public
  link `/f/{token}` is a Router special route (cloned from service-plans'
  `/os/{token}`), default OFF (`forms.allowPublic='false'`), six-gate
  uniform-404 scoped to the FORM's own siteID throughout (never ambient
  `Site::id()` — no active-site context on a public route). Public POST:
  honeypot → CSRF → `Captcha::verify()` → `RateLimiter` (fake-success on
  `isBlocked()`) → 5/15min per-IP bucket (`prayer-requests/anonymous-save
  .php` + `assets/found-save.php` precedent). Responses reviewed/exported
  admin-only at `/forms/responses` (CSV via `FormEngine::csvRows()` +
  `CsvExporter`). GDPR lockstep in the SAME PR: `GdprEraser::catalogue()`
  hard-deletes a member's responses by `submitterID`;
  `auth/account/data-export.php` gained a matching `formResponses` block —
  a public (anonymous) response carries no `submitterID` and sits outside
  both by design (salvation decision-card precedent), documented in
  `/help/forms` + DEV_NOTES. Three residual defaults applied per the build
  spec (owner sign-off deferred, minimal-safe choice made): the
  display-only `heading` field type is included; NO per-role fill
  restriction in v1 (any signed-in site member may fill a published
  internal/both form); public-response retention = **keep indefinitely**
  in v1, with a `forms.responseRetentionDays` settings stub (seeded `'0'`
  = forever) for a future auto-purge cron. Migration 182: 3 new tables
  (`tblForms`/`tblFormFields`/`tblFormResponses`), 3 settings seeds, 15
  route seeds (13 protected + 2 public, no `api/*` rows — ApiRouter trap).
  All 13 audit checks green, `php -l` clean on every touched file.
- **`claude/backlog150-groups`** (branched off `alpha`) — issue #150: new
  Small Groups app (`web/_apps/small-groups/`, slug `small-groups`) —
  groups/classes register for Sabbath School classes, home groups, Bible
  studies. Roster with leader/co-leader/member roles + optional
  self-service join requests (pending → approve/decline, last-active-leader
  guard on remove/demote/leave); per-meeting roll
  (`tblSmallGroupMeetingAttendance`, presence-row model mirroring
  `tblEventAttendance`) with an ADDITIVE headcount push into the existing
  Attendance app via a group's linked `tblAttendanceServiceTypes` row —
  several groups can share one service type/session, each contributing its
  own labelled `tblAttendanceCounts` row matched by `(sessionID,
  groupLabel)`; zero changes to Attendance's own schema/code. Meeting
  location reuses the #456 shared partials (`portal_location_input`/
  `portal_location_display`) with canonical column names, byte-identical
  to migration 180's `tblVenues` shape, plus a new `locationVisibility`
  gate (leaders/members/site, default `members` — no public tier, since
  groups often meet in a member's home). New `Portal\Core\SmallGroups`
  class is the tenant-safety choke-point AND the stable contract #304
  (group messaging) and #321 (watch-party rooms) are expected to consume —
  `groupID` scope anchor, `status='active'` membership predicate,
  `isLeader()`/`canManage()` gates; the denormalised `siteID` on
  member/meeting rows is written only by `SmallGroups::upsertMembership()`
  after confirming an ACTIVE `tblUserSites` row for the group's own site
  (leadership `assign.php:87-93` join precedent), making cross-site
  membership structurally impossible. New `groups_coordinator` role. GDPR
  lockstep in the same PR: 6 `GdprEraser::catalogue()` entries, 4
  `data-export.php` blocks, 1 `offboarding/do.php` step ending a leaver's
  memberships. **v1 is adults-only** — membership rows are portal users
  only; zero named-child rows anywhere (the Kids app's `tblKidProfiles`
  remains the sole place child identity lives, verified by grep). Migration
  183 (181 = alpha head at spec time, 182 reserved by #153 Forms in
  flight): 4 new tables, 7 settings seeds (`small-groups.enabled` defaults
  `'0'`, opt-in — the pre-existing `'1'`-vs-`'true'` nav/dashboard
  enable-flag quirk is inherited verbatim, not fixed here), 1 role seed
  (`WHERE NOT EXISTS` idiom), 13 route seeds (12 app + 1 help,
  `isProtected=0`), zero ALTERs to any existing table. Also found + fixed
  along the way: `check_sql_columns.py`'s SELECT-column regex false-
  positives on any `FROM tblSmallGroup*` clause carrying a short alias
  immediately after the table name — the bare substring "Group" inside
  every one of the four new table names lets the regex's own greedy-`\w+`
  backtracking mis-match "Group…" as a false `GROUP BY` terminator,
  truncating the captured table name to `tblSmall` and reporting a bogus
  unknown-table finding; worked around by never aliasing the PRIMARY
  `FROM tblSmallGroup*` table (using full-name column qualification
  instead) while still freely aliasing any table introduced via `JOIN`
  (invisible to that checker's FROM-anchored regex) — documented inline at
  each call site since the same shape will recur for any future table
  whose name embeds a bare SQL keyword. New help page (`/help/small-groups`)
  + help-index card. All 13 audit checks green, `php -l` clean on every
  touched file, zero raw `<table>` (portal-data-list throughout).
- **`claude/backlog-pwa-brand`** (branched off `alpha`) — two small,
  low-risk backlog finishers, one PR: **#141 residual** (the push half
  was already fully shipped as #322 — only install-prompt/manifest/iOS-meta
  remained) — self-hosted `assets/js/pwa-install.js` captures
  `beforeinstallprompt`, suppresses the mini-infobar
  (`event.preventDefault()`), and reveals a dismissible bottom-sheet
  banner (`#portal-install-prompt` in footer.php, same visual pattern as
  the existing cookie-consent banner) with a brand-aware "Install
  {product name}" heading; dismissal remembered 30 days in localStorage,
  a real install remembered permanently via `appinstalled`.
  `manifest.php` gains a brand-aware `shortcuts[]` (Dashboard / Calendar
  / Giving / Prayer Requests) gated through `AppRegistry::isEnabled()`,
  failing CLOSED (shortcut dropped) on any registry exception.
  `header.php` gains the missing `apple-mobile-web-app-title`
  (brand-aware — was absent entirely) + the standard-track
  `mobile-web-app-capable` twin of the pre-existing apple- tag. **#306**
  — functional starter SVG brand kits (`icon.svg`/`icon-192.svg`/
  `icon-512.svg`/`logo.svg`) for the four presets that previously fell
  back to generic WebMS-Intra assets:
  `assets/images/brandkit/assets/{schoolms,charityms,communityms,businessms}/`,
  each a distinct emblem (mortarboard/heart/interlocking-rings/bar-chart)
  on the same indigo-tile + gradient-token structure as the WebMS/
  ChurchMS kits. `brand-defaults.php`'s four stub presets now point
  `assetFolder` at their new kits. Known design debt (documented in-repo,
  not fixed — no font-outlining tool available): each `logo.svg`'s
  wordmark is set with a system-font stack, not the WebMS/ChurchMS kits'
  outlined vector glyphs — a designer pass is recommended before any of
  the four ship to a real customer; the PWA-install-facing `icon*.svg`
  files need no such caveat. Also fixed two stale DEV_NOTES.md doc-drift
  items found along the way: the "Per-brand assets" section still
  described the pre-brandkit-move `assets/images/brands/` path, and its
  #297 deferred-follow-ups list still showed sub-brand artwork and
  OpenAPI brand-awareness as open when both are now done (#306 here,
  #307 previously). No migration in either half. All 10 audit checks
  green, `php -l` clean on every touched PHP file, all 16 new SVGs
  well-formed XML.
- **`claude/gap322-webpush`** (branched off `alpha`) — issue #322: Web Push
  notifications ("we're live now" + service-reminder channels). Migration
  111 shipped `tblPushSubscriptions` + the four `push.vapid*`/
  `push.contact`/`push.enabled` settings, but the subscribe/unsubscribe
  handlers sat at `_apps/api/push/*` — a path ApiRouter never resolves
  (same routing trap already fixed for worship/livestream in #372/#373) —
  and NO sender existed anywhere. This ships the whole loop:
  `Portal\Core\WebPush` (VAPID ES256 JWT with the mandatory DER→JOSE
  signature conversion, RFC 8291 aes128gcm payload encryption via a fresh
  ephemeral P-256 keypair per message + triple `hash_hkdf()`, RFC 8030
  delivery with TTL/Urgency/Topic); a committed crypto self-test
  (`tools/webpush-selftest.php`, no DB/network, exercises the real private
  methods via reflection) that PASSES; relocated
  `_apps/push/api/{subscribe,unsubscribe}.php` + the two
  `api.push.*.enabled` flags migration 177 seeds; SSRF-guarded endpoint
  validation (https-only, no IP-literal/local host, admin-editable
  host-suffix allowlist) enforced at BOTH subscribe and send time; the
  VAPID private key sodium-encrypted at rest, never redisplayed, never
  sent to the client; client subscribe UI (`assets/js/push-subscribe.js`)
  + new `sw.js` `push`/`notificationclick` handlers; the "we're live now"
  manual admin button (`/admin/livestream` + Host Console) plus a
  default-OFF auto-detect cron (`cron/push-golive.php`, dedupe once per
  channel per day) and a default-OFF anonymous "starting soon" broadcast;
  a Web Push companion to `cron/event-reminders.php`'s 1h window (same
  dedupe claim as the email send); `/admin/integrations/push` config page
  (generate-or-paste keys, TTLs, toggles, per-channel subscription counts,
  test-send); GdprEraser + offboarding coverage of `tblPushSubscriptions`.
  INERT until an admin sets VAPID keys (`WebPush::isConfigured()` gates
  every send path). Migration 177 (renumbered from 175 — #423's UPC-E took
  175, #234's shared-mailbox took 176): 3 additive `tblPushSubscriptions`
  columns (dead-subscription pruning), settings seeds, 4 route seeds, no
  new tables (reuses `tblUserReminderLog` / `tblEventReminderLog` for
  dedupe). All 13 audit checks green, `php -l` clean on every touched file.
- **`claude/gap128-oos`** (branched off `alpha`) — gap #128 residual
  (re-scoped #128 "Order of Service planner with iHymns integration"):
  service-plans (#262/#300) + Worship (#308/#355) already covered
  everything the issue asked for except three genuine gaps, all additive
  on the EXISTING `tblServicePlan`/`tblServicePlanItem`/`tblSongs` tables —
  no third service-plan model, no new app. **R1** local hymnal index —
  new `tblHymnals`/`tblHymnalEntries` (metadata only, never lyrics),
  `Portal\Core\Hymnal::searchLocal()`, admin CRUD + CSV import at
  `/admin/hymns`. **R2** optional remote ("iHymns") lookup, Tier 2,
  default OFF (`hymns.remote.enabled='false'`) — a generic SSRF-hardened
  HTTPS JSON client (`Hymnal::searchRemote()`: https-only, single-host
  allowlist, private/reserved-IP refusal, no-redirect-follow, 3s/5s
  timeouts, ~512 KB body cap, JSON-only, 24h cache in
  `tblHymnLookupCache`), reachable via session-authed
  `service-plans/api/hymn-search.php` (ApiRouter convention path,
  `api.service-plans.hymn-search.enabled` flag, NOT a tblRoutes row).
  **R3** congregation-facing public Order of Service — new
  `tblServicePlan.publicToken`/`isPublicShared`, Router special route
  `/os/{token}` (cloned from `/a/{token}`) → `service-plans/public.php`:
  congregation fields only, `notes` never queried, uniform 404 for
  unknown/unshared/unpublished/disabled tokens, OFF by default at both
  site (`service_plans.public_share.enabled`) and plan level; CSRF'd
  `service-plans/share.php` (enable/disable/rotate) + a `/qr.php` code.
  `print.php` gains `?version=leader|congregation` (default `leader`,
  byte-identical to before). **R4 (glue)** nullable
  `tblServicePlanItem.songID` FK → `tblSongs` — picking a hymn/song
  auto-promotes it into `tblSongs` (check-first upsert) and links it;
  free-text `title` stays the universal fallback. Migration 178 (176/177
  reserved by in-flight webpush/shared-mailbox work); all 11 audit checks
  green, `php -l` clean on every touched file.
- **`claude/gap436-venue-coverage`** (branched off `alpha`) — gap #436:
  additive follow-up to the shipped Venue Bookings app (#429, migration
  170) and the wall-clock fix (#435). `tblEvents` gains two optional
  nullable columns, `venueID`/`roomID` (migration 179, FKs
  `ON DELETE SET NULL`) — the calendar manage form's venue picker upgrades
  from a transient advisory-only check into a **persisted** link, with a
  cascading room `<select>` (disabled, not hidden, when the venue has no
  rooms). `Venues::classifyEventCoverage()` gains an optional trailing
  `?int $roomId = null` — when set, per-day booking rows are filtered to
  `roomID IS NULL OR roomID = $roomId` (a whole-venue booking still covers
  every room) before the untouched wall-clock day-cascade runs, and a new
  `COVERAGE_ROOM_NOT_COVERED` verdict (severity danger) fires when the room
  itself is uncovered but the venue has a confirmed bookable hire for a
  *different* room that day; null `$roomId` (both pre-#436 call sites)
  reproduces today's output bit-for-bit, and an unresolvable room silently
  degrades to venue-level coverage (no existence oracle). New public
  `Venues::getRoom()` accessor. `calendar/manage/save.php` validates and
  persists both links site+venue-scoped (`Venues::getVenue()`/`getRoom()`)
  on create/update — invalid/foreign posts silently NULL, never a
  save-blocking error — and OMITS the columns from the UPDATE entirely
  when the Venues app is disabled/absent/throws, so a toggle can never
  wipe an existing link; an event can only ever link its own site's
  venue/room. `full_schema.sql` folds the two columns + KEY indexes inline
  into `tblEvents`' CREATE but deliberately leaves the two FKs out
  (`tblEvents` is created thousands of lines before `tblVenues`/
  `tblVenueRooms` in that file — see DEV_NOTES.md's new "full_schema.sql
  fold pattern" subsection) — migration 179's guarded `ADD CONSTRAINT`
  blocks add both FKs on replay instead. Also fixed in this PR: the
  event-form's live "is it booked?" JS posted `startDateTime`/
  `endDateTime`/`timezone` while `venues/api/check.php` has always read
  `start`/`end`/`tz` — every live check silently 400'd since #429 shipped;
  canonicalised on `check.php`'s existing contract and fixed the JS to
  match, adding `roomID`. All 10 audit checks green, `php -l` clean on
  every touched file.
- **`claude/gap456-location-chunkB`** (branched off
  `claude/gap456-location-chunkA`) — #456 Chunk B: the PII + GDPR half.
  Migration 181 adds FOUR columns to `tblUsers` ONLY — `latitude`/
  `longitude`/`what3words` (member home coordinates, PRIVATE by default,
  NEVER auto-geocoded — no `geocodedAt`/`geocodeSource` pair) and
  `visibilityCoords` (ENUM, default `'private'`, INDEPENDENT of the
  existing `visibilityAddress`). Capture on the owner surface
  (`directory/me.php`, per the spec — NOT `/account`); `directory/
  save.php` validates the pair (10 → 14 bind_param placeholders).
  `directory/profile.php` gates coords through a SEPARATE
  `$can($u['visibilityCoords'])` check: owner/admin see full precision +
  the exact what3words, any other permitted viewer sees coords coarsened
  to 3dp (~110m, `GeoLocation::coarsenCoords()`) with an "Approximate
  location" badge and the what3words value suppressed entirely (a
  3m-precise W3W square can't be meaningfully coarsened). The pre-existing
  `$can()` "team tier = private" quirk is inherited verbatim, not fixed.
  GDPR lockstep in the SAME PR: `data-export.php` gained a
  `giftAidDeclarations` block (closed a pre-existing gap — Gift Aid
  address PII was never exported); `delete-confirm.php`'s tblUsers
  anonymise UPDATE now also nulls `displayAddress`/`displayPhone` (a
  separate pre-existing miss) plus the three new PII columns;
  `GdprEraser::catalogue()`'s tblUsers `nullCols` extended with
  `latitude`/`longitude`/`what3words`. GiftAid (`giving/gift-aid.php`) and
  Salvation (`salvation/card.php`) reuse the shared `location-input`
  partial in TEXT-ONLY "reduced names map" mode mapped onto their existing
  `address`/`postcode` POST fields — no new columns, no new erasure
  surface. Kids/Care/Visitors hard-excluded, verified by grep (zero
  matches). `UserCreate`/`UserUpdate` API schemas document that member
  coordinates/W3W are never readable or writable via the REST API in any
  mode. All 13 audit checks green, `php -l` clean on every touched file.
- **`claude/gap456-location-chunkA`** (branched off `alpha`) — #456 Chunk A:
  full address + geocoordinates + what3words platform layer (foundation,
  non-PII, interactive map — Chunk B lands the PII/GDPR half in a later
  PR off this branch). Cross-repo data-format CONTRACT with
  ProjectBookIT/ProjectEPass (identical column shapes, canonical
  `location` JSON wire object, what3words canonical form) but fully
  standalone — zero runtime dependency on either repo. New
  `Portal\Core\GeoLocation` (address normalise/format mirroring
  `Venues::saveVenue()`, DECIMAL(10,7) coord validation, W3W
  canonicalisation, map link-outs, `toLocationObject()`/
  `fromLocationObject()` serializer), `Portal\Core\What3Words` (v3 API
  client — key in the QUERY STRING not a header, unlike every other
  Bearer adapter in this codebase; never logged; default OFF via
  `w3w.enabled`, which gates ONLY the API — the `///word.word.word` input
  is always present as a stored-field fallback), `Portal\Core\Geocoder`
  (Google primary → Nominatim/OSM fallback, policy-compliant User-Agent +
  ≤1 rps throttle + `tblGeocodeCache`, `geo.autoGeocode` default OFF,
  every method best-effort/never-throws). Three new shared partials —
  first in the codebase at `web/_core/partials/`:
  `location-display.php`/`location-input.php`/`location-map-assets.php`
  (pinned Leaflet 1.9.4 from cdn.jsdelivr.net with SRI — hashes verified
  by independently re-deriving them from the npm registry tarball, since
  jsdelivr itself was unreachable from the build sandbox; all four
  sha384/sha256 digests matched the build spec exactly). New admin pages
  (`/admin/integrations/{what3words,geocoding}`,
  `/admin/settings/organisation`) + two session-authed AJAX proxies
  (`/geo/w3w-suggest`, `/geo/lookup`) outside `api/*`. Wired into Venues,
  Events (existing `locationGeoLat/locationGeoLng/locationW3W` columns
  now validated + JSON-LD `geo` + interactive map + canonical `location`
  object additively emitted by the events REST API create/update/list/
  detail), Event occurrence overrides (`overrideGeoLat/overrideGeoLng/
  overrideW3W`, hand-entered, NULL = inherit), Resources, Asset Locations.
  Migration 180: five-column location block on
  `tblVenues`/`tblResource`/`tblAssetLocations`, three override columns on
  `tblEventOccurrenceOverrides`, new `tblGeocodeCache`, 14 settings seeds
  (all default OFF/empty), 10 route seeds — upgrade is a full no-op. No
  PII table touched (tblUsers/directory/GiftAid/Salvation are Chunk B).
  All 13 audit checks green, `php -l` clean on every touched file.
- **`claude/gap234-shared-mailbox`** (branched off `alpha`) — gap #234:
  MS365 Graph email via an admin-configured shared mailbox, formalising
  and hardening the app-only `Mailer::sendViaGraph()` path already in
  place rather than adding the issue body's delegated `Mail.Send.Shared`
  auth model (deferred — zero new secrets vs. an entire OAuth refresh
  surface + a dependency on a licensed human account). New
  `mail.ms365.sharedMailbox` (empty = off, today's behaviour unchanged)
  + `Mailer::effectiveSender()` resolver; explicit `message.from` object
  now built in BOTH modes (benign fix — `mail.defaultFromName` finally
  works on MS365, not just Google); 401-retry-once / 429-bounded-retry
  (≤5s `Retry-After` only) / 403-404-Graph-error-code-surfaced / optional
  opt-in `mail.fallbackProvider='google'` (default off, fail loud). New
  `tblEmailLog` (migration 176) logs every send from BOTH providers via
  the new public `Mailer::logSend()` — closes the #230 audit-trail
  dependency too; opportunistic retention prune, no new cron.
  `GdprEraser` gained a bespoke (not `catalogue()`) step scrubbing an
  erased user's address out of the comma-joined `toRecipients` column,
  captured before the catalogue's own `tblUsers` step nulls it. Admin UI:
  `/admin/integrations` MS365 Graph card gained a Shared-Mailbox Sending
  sub-section + CSRF'd save handler (`admin/integrations/ms365-mail-
  save.php`); Send Test Email now calls the real `Mailer::send()` instead
  of a duplicated inline cURL flow, closing the test/production drift
  risk permanently. Fold-in fix: `/admin/integrations/email` was reading
  a dead `email.provider`/`email.from` vocabulary and always reported
  "smtp" — now reports `Mailer::provider()` + effective sender, plus a
  "Recent sends" `portal-data-list`. Shared mailbox is admin-config-only
  (never request-derived); no secret ever logged. Migration 176:
  `tblEmailLog` + 5 non-sensitive settings seeds + 1 route seed, folded
  into `full_schema.sql`. All 13 audit checks green, `php -l` clean.
- **`claude/gap7-workflow-engine`** (branched off `alpha`) — gap #7
  (#443): Workflow Execution Engine + generic `/approvals` inbox.
  Migration 034 shipped four workflow tables + an admin definition CRUD
  but no code anywhere started/advanced/completed/timed-out an instance —
  this ships the engine (`Portal\Core\Workflow`, `web/_core/Workflow.php`).
  Every mutator (`start`/`act`/`cancelForSubject`/`timeoutSweep`) is
  atomic: `begin_transaction` + `SELECT … FOR UPDATE` + `UPDATE …
  WHERE currentStep=? AND status IN (…)` gated on `affected_rows === 1`
  (the `expenses/approve/save.php` / `Payments::markPaymentSucceeded`
  discipline) before recording the action row or applying any subject
  side effect — a losing racer does nothing. Authorisation (role/user/
  group match on the CURRENT step, or a default-on site-admin override)
  lives INSIDE `act()`, never trusted from the HTTP layer; a cross-tenant
  instanceID is indistinguishable from a missing one. New `/approvals`
  app (AppRegistry entry, default-on) with an awaiting-decision queue,
  CSRF'd approve/reject/comment handler, and history timeline. New
  token-gated hourly `cron/workflow-timeouts.php` — escalates an overdue
  step unless it explicitly sets `autoAction=approve|reject`; never
  auto-acts on a bare timeout. Reference consumer wired: Announcements
  publish approval behind default-off per-site
  `workflows.announcements.enabled` — final approval flips
  `tblAnnouncements.isPublished` inside the SAME transaction as the
  approval claim (no double-publish, no approved-but-unpublished ghost);
  a misconfigured gate fails OPEN (publishes directly + logs a platform
  warning) rather than blocking publishing. Admin CRUD completion at
  `/admin/workflows`: per-step delete (FK-aware, refuses with active
  instances), an `isActive` toggle, an `autoAction` selector. The seeded
  `expense_approval` definition (034) stays dormant by design — Expenses
  keeps its own independent multi-approver system. Migration 174: four
  additive `tblWorkflowInstances` columns + one composite index + one
  `tblWorkflowActions` enum value (no new tables), plus the
  `announcement_approver` role/definition/step, 8 settings seeds, 4
  route seeds. All 13 audit checks green, `php -l` clean on every
  touched file.
- **`claude/gap4-bulk-statements`** (this session, branched off `alpha`) —
  gap #4: treasurer-only bulk year-end giving statements at
  `/giving/statements` (#440). Generalised `Portal\Core\Giving::
  renderStatementPdf()` to `(siteId, donorId, from, to, label)` so the
  self-service page (`giving/my-statement.php`) and the new bulk batch
  render through the exact same function — byte-identical output. Three
  upgrades landed on that shared renderer: donor lookup is now site-scoped
  (active membership OR giving history at the site — an `OR` of two
  `EXISTS`, closing a latent cross-tenant render hole while still allowing
  a treasurer to pull a departed donor's historical statement); a
  Gift-Aid-eligible column + summary via correlated `EXISTS` (never a
  JOIN, so overlapping declarations can't double-count, mirroring
  `buildHmrcCsv()`'s own known hazard), deliberately with no projected 25%
  reclaim figure; and the output path is now namespaced by
  `{siteID}/{periodKey}`, fixing a cross-site filename overwrite the old
  flat naming had. New `tblGivingStatementLog` (migration 172) is a
  `UNIQUE(siteID, donorID, periodKey)` dedupe/audit log; generate/email
  both cap at `giving.statements.batchPerRun` (default 25) per request
  (Newsletter-dispatch pattern, re-trigger to continue), with an explicit
  audit-logged "resend" override, ZIP download (`ZipArchive`, never a
  combined PDF, degrades to per-row links), and an optional token-gated
  `cron/giving-statements.php` sweeper (`giving.cron_token`, empty ⇒ 403
  fail-closed). New `givingStatements` notifyPrefs opt-out (default on)
  enforced at both queue and live-send time; `GdprEraser` now also
  unlinks an erased donor's rendered statement PDFs from disk. All 11
  audit checks green, `php -l` clean on every touched file.
- **`claude/gap6-serviceplan-bridge`** (branched off `alpha`) — gap #6
  (#442): additive, non-destructive bridge between the two parallel
  "service plan" data models that never knew about each other — the
  run-sheet builder (`tblServicePlan` SINGULAR, migration 089, #262/#300)
  and the worship presentation engine (`tblServicePlans` PLURAL, migration
  137, #308/#355). New nullable, UNIQUE `tblServicePlans.runSheetPlanID`
  FK (`ON DELETE SET NULL` → `tblServicePlan.planID`) — NULL (unpaired) is
  the state of every pre-existing row, zero data migration. New
  `Portal\Core\ServicePlanLink` resolver is the only code that knows about
  both models; `pair()`/`unpair()` enforce same-site (hard), same-event
  when both sides declare one (hard, either-NULL always proceeds), and
  1:1 (hard — UNIQUE key + errno-1062 race catch, never fatal). A
  NULL-only, one-directional (worship → run-sheet) backfill wakes the
  run-sheet's dormant, write-dead `eventID` at pair time — never the
  reverse, since the worship side's `eventID` is ACL-bearing. New CSRF'd
  `worship/plan/link` POST handler (`worship/plan-link.php`) reuses the
  worship app's admin-or-coordinator write gate verbatim (copied, not
  refactored out of `plan-save.php`); own-row re-pairing overwrites, a
  foreign run-sheet claim is refused with a flash. Read-only counterpart
  panels on both editors (`service-plans/edit.php`, `worship/plan.php`),
  each guarded by `AppRegistry::isEnabled()` + try/catch (venue-overlay
  resilience precedent) so a disabled counterpart app or any resolver
  exception leaves the panel empty rather than breaking the page. No
  field sync in v1 — the song representations are structurally
  incompatible (free-text title vs canonical `songID` FK) — read-only
  visibility only. Migration 173: guarded MySQL-8-safe DDL, one route
  seed, no new settings keys, folded into `full_schema.sql`. All 11 audit
  checks green, `php -l` clean on every touched file.
- **`claude/gap3-reminders`** (branched off `alpha`) — gap #3 (#439):
  new `cron/user-reminders.php` sweeps three reminder fields earlier
  migrations shipped but no code ever consumed —
  `tblTasks.reminderDate`/`reminderSent` (036), `tblRotaSlot.
  reminderSentAt` + `rota.reminder_days_before` (074), and the milestones
  daily digest promised by `milestones.digest_recipients` (076). Token-
  gated (`user_reminders.cron_token`, empty-fails-closed), 15-minute
  cadence, per-site loop with `App::settingForSite()` read INSIDE the
  loop (venue-cron discipline). Dedupe: tasks/rota reuse their existing
  sent-flag columns via an atomic claim UPDATE; milestone-digest uses a
  new generic `tblUserReminderLog` `(refType, refID, dueDate)` table
  (check-first + race-catch), reserved so a future single-shot family
  (e.g. DBS-expiry) can reuse it with zero DDL. Milestone-digest is
  explicit opt-in only — an empty `milestones.digest_recipients` skips
  the site rather than falling back to admins. Two new notification
  preferences (`taskReminders`/`rotaReminders`, default on) on
  `/account/notifications` — the first prefs this codebase actually
  honours when sending. Write-path fixes so dedupe stamps stay correct as
  rows change: `tasks/save.php` re-arms `reminderSent` on a future
  reminder edit, `tasks/complete.php` carries the reminder forward
  (interval-shifted) into a recurring task's spawned next occurrence,
  `rota/swap-respond.php` clears `reminderSentAt` on an accepted swap.
  Also fixed in this PR: `cron/event-reminders.php` selected `u.email`
  from `tblUsers` (real column: `emailAddress`) — under this app's strict
  mysqli reporting, the very first `prepare()` threw, so the event-
  reminder cron 500'd on every single invocation; fixed throughout
  (`u.emailAddress AS email`). Three other `u.email` sites found during
  this work are tracked separately in #438, not touched here. Migration
  171: new `tblUserReminderLog` table + six settings seeds + one route
  seed, zero ALTERs, folded into `full_schema.sql`.
- **`claude/paypal-checkout`** (branched off `alpha`) — gap #1:
  PayPal Orders v2 fully wired into `Portal\Core\Payments` (create checkout,
  capture-on-return + `CHECKOUT.ORDER.APPROVED` webhook backstop for a payer
  who approves and never returns, verified webhooks via PayPal's own
  verify-webhook-signature API, refunds by capture id) plus the previously-
  missing user-facing checkout UI (new `giving/give.php` "Give online" page,
  Projects `my-pledges.php` "Pay now"). The S1 amount/currency integrity
  gate now lives INSIDE `markPaymentSucceeded()` itself (signature gained
  `?int $observedAmountPence, ?string $observedCurrency`) — every PayPal
  success path (return capture, 422 `ORDER_ALREADY_CAPTURED` reconcile,
  verified `PAYMENT.CAPTURE.COMPLETED`) asserts the captured amount+
  currency against the pending row before any Giving/Projects fan-out, and
  a mismatch marks the row `failed` + logs `PaymentIntegrityFail` instead.
  The status transition is now a single atomic
  `UPDATE … WHERE status = "pending"` gated on `affected_rows === 1`,
  closing the return-path-vs-webhook race (and incidentally Stripe's own
  two-event race too — Stripe call sites keep passing null observed values
  unchanged, upgrading them is a follow-up). `checkout.php` (already
  #430-hardened for pledge/giving/else purpose validation) gained the two
  remaining §6.3 pieces: a £10,000 online-giving ceiling
  (`GIVING_MAX_AMOUNT_PENCE`) and server-built descriptions
  (`'Giving — {category}'` / `'Pledge — {project}'`) — the POSTed
  `description` field is removed from the flow entirely. Migration 167
  (seeds only, no DDL): `payments.paypal.{webhookId,mode}`, `clientId`
  flipped to encrypted-at-rest (predicate-guarded — only where still
  empty, since `decrypt_setting()` returns `''` for a plaintext value), and
  the new `giving/give` route.
- **PR #372** (accumulating, draft, `claude/alpha-enhancements` → `alpha`) — a
  discovery-pass fold-in batch on top of #386/#387 (migrations 155-157):
  #373 ApiRouter half — `ApiRouter.php` never got the `global $mysqli,
  $SETTINGS;` import Router.php gained for #373, fatally breaking 6 live-chat
  /livestream handlers; fixed at both `dispatch()` and `dispatchV1()`. #339
  residual — `calendar/manage/save.php`'s create-flow slug-uniqueness probe
  now scopes to `siteID`. Worship live-sync (#308) — `/api/worship/state` +
  `/api/worship/advance` were unreachable (dead legacy `_apps/api/worship-
  *.php` + tblRoutes rows, no `api.worship.*.enabled` flags); relocated to
  the ApiRouter convention path `_apps/worship/api/{state,advance}.php`
  (precedent: migration 144's livestream/ping relocation). AppRegistry
  (#255) — added `_core/apps/{noticeboard,worship,salvation,kids}.php` so
  all 41 apps surface in `/admin/apps`; seeded the 3 missing enable flags
  (worship/salvation/kids) so registering them didn't silently 403 three
  live apps. Dead-route cleanup — removed 19 unreachable `api/*` tblRoutes
  rows (Router never consults tblRoutes for `api/*` paths) plus the matching
  full_schema.sql seed-block prune. Cloudflare Stream `testConnection()` +
  admin "Test connection" button (#386 parity with BookIT Phase 2). All in
  migration 158 + one full_schema fold; CI-green (10/10 audit checks, `php
  -l` clean). See `.claude/HANDOFF.md` for the fuller discovery-pass notes.
- **PR #372** — this
  session's additions on top of the #323 Phase 2 base below: #299 "Giving
  polish" sub-features — two-person offering-count session (sub-1, migration
  150), pledge campaigns (sub-2, migration 151), bank reconciliation (sub-3,
  migration 152), plus the online/project-gift auto-attribution follow-up
  wiring `Giving::attributeGift()` into `Payments::markPaymentSucceeded()` and
  `Projects::fulfilPledge()`; #303 Phase 2 Discipleship — per-user progress +
  auto-completion (migration 153); #300 v2 Service Plans — operator →
  confidence-monitor message channel (migration 154); a data-protection fix to
  `Portal\Core\GdprEraser::catalogue()` (wrong/mis-cased table names silently
  skipping erasure; added auth-residue tables `tblLocalAccounts` /
  `tblLinkedAccounts` / `tblTrustedDevices` / `tblPasswordResets` /
  `tblKidProfiles`) plus a demo-data-wipe table-name fix; a new
  `tools/audit-checks/check_php_table_refs.py` CI check (flags `tblXxx`
  identifiers hard-coded in PHP that aren't real tables) and a native
  `confirm()` → `data-confirm` cleanup sweep. All CI-green through migration
  154; see `.claude/HANDOFF.md` for the full remaining/next breakdown.
- **PR #372** — #323 Phase 2: REST API v1 write surface — dual-mode `ApiAuth` (bearer API key OR session), `/api/v1/{resource}[/{id}]` RESTful facade, new write endpoints (Attendance/Documents/Expenses create+delete/Users), canonical `ApiKey::SCOPES` + rotation grace, per-key rate limiting, `Site::forceContext` tenant pinning, admin scope-checkbox + audit source-badge UI, OpenAPI v1 paths + `bearerAuth` scheme (v1.4.0). Plus #324 outbound webhooks admin CRUD UI.
- **PR #358** (in flight) — #303 Discipleship Pathway Tracker Phase 1 + #313 COP Live Chat Phase 1 + Phase 2 (push prompts + viewer widget) + #317 Virtual Host Console Phase 2 (overlap on `tblLivePrompts`) + #360 Community Noticeboard Phase 1 (poster wall, self-hosted React, page-scoped CSP extension). Includes a Phase 1 hotfix (`::ok`→`::success`) and multiple security-check-clean bug fixes.
- **PR #357** — #317 Phase 1 + #323 API key infrastructure Phase 1.
- **PR #356** — Plus Jakarta Sans modular embed.
- **PR #355** — Worship Presentation Engine full v1.
- **PR #354** — Post-merge cleanups (composer fix + 1.3.0 + installer favicons).
- **PR #340** — Events platform overhaul (36 issues / 39 commits).
- **PR #297** — Multi-brand product layer (#296).
- **PR #129** — Prayer Requests app (logged-in + anonymous public route)
- **PR #130** — Multi-provider Captcha (Turnstile / reCAPTCHA v2+v3 / hCaptcha) with admin priority drag-and-drop
- **PR #131** — Release prep v0.11.0 (version bump + CHANGELOG stamp)
- **PR #132** — Password policy hardening (#53) — min 12 chars, full-flow coverage, JS strength meter
- **PR #133** — Debug mode refused in production (#54), error display silenced
- **PR #134** — Deploy `dry_run` workflow_dispatch + DEV_NOTES SFTP `--delete` docs (#107)
- **PR #135** — Anchor colour bound to `--bs-link-color` for both themes (was leaking browser-default blue)
- **PR #137** (in flight) — Calendar seven view modes (closes #136)
- **PR #138** (in flight) — Calendar per-month strap-lines + category display-style toggle
