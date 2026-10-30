# Issue #128 — "Order of Service planner with iHymns integration"
## Build-ready plan (architecture pass, no code) — 2026-08-28, against `alpha` @ 0201e60

---

## 1. Overlap verdict: **(A) — largely already covered**

Issue #128 was filed 2026-05-22, when the portal genuinely had no order-of-service
capability (the issue's own Background says so, and referenced the pre-#159 layout
`web/public_html/calendar/` that no longer exists). Since then **two whole apps shipped
that cover ~90% of its scope**, and the issue's own 2026-07-21 triage comment already
recorded this: *"superseded by the shipped service-plans app (#262) + Worship
Presentation Engine (#308); residue is iHymns integration only."*

This plan confirms that verdict with file-level evidence, widens the residue slightly
(the congregation-facing / public order-of-service view is also genuinely missing), and
plans **only the additive delta**. **Do NOT build a new app.** Recommended disposition:
**re-scope #128 in place** (retitle to "Hymnal lookup (iHymns) + public printable Order
of Service for Service Plans"; swap label `app: calendar` for the service-plans app
label) rather than close-and-refile, preserving its comment history.

### 1.1 What #128 asked for vs what already ships (evidence)

| #128 scope item | Status | Evidence (file:line) |
| --- | --- | --- |
| Order-of-service builder attached to a calendar event | **SHIPPED** | `tblServicePlan` w/ `eventID` FK → `tblEvents` — `web/_sql/089_service_plans.sql:8,19`; worship side `tblServicePlans.eventID` — `web/_sql/137_worship_service_plans.sql:29`; #442 bridge pairs the two and backfills the run-sheet's `eventID` — `web/_sql/173_worship_runsheet_link.sql:58-98`, `web/_core/ServicePlanLink.php` |
| Item types: song, reading, prayer, sermon, announcement, offering, welcome, special music, benediction, custom | **SHIPPED** (11-value ENUM) | `tblServicePlanItem.sectionType` ENUM `('greeting','song','prayer','scripture','sermon','offering','communion','special_music','announcement','reading','other')` — `web/_sql/089_service_plans.sql:26`; editor labels — `web/_apps/service-plans/edit.php:114-126`. Benediction / children's story map to `other`/`prayer` + free title |
| Reordering of items | **SHIPPED** (up/down buttons, not drag-and-drop) | `web/_apps/service-plans/item-save.php:122-155` (`move-up`/`move-down` swap) |
| Durations, per-item notes (presenter/AV/musicians) | **SHIPPED** | `tblServicePlanItem.durationMin`,`notes` — `web/_sql/089_service_plans.sql:31-32`; `edit.php:311,328` |
| Roles per item, pulling from existing users | **SHIPPED** (single presenter per item, site-scoped picker + POST validation) | `presenterID`/`presenterText` — `089:29-30`; site-scoped dropdown — `edit.php:86-101`; server-side site-scope validation — `item-save.php:80-99` |
| Default service-structure templates | **SHIPPED** (two seeded structures on create) | `web/_apps/service-plans/new.php:46-61` ("Opening hymn"… seed rows) |
| Print / PDF export | **PARTIAL** | `web/_apps/service-plans/print.php` — print-CSS view exists, but it is **login-only** (`print.php:18-19`) and **always renders internal notes/AV cues** (`print.php:115-117`). No congregation-vs-leader split, no PDF file (dompdf 3.1.5 + `_core/Pdf.php` exist but unused here) |
| Read-only view for the congregation / public view | **MISSING** | No member-facing read-only route; no public/tokenised route. (Precedent exists elsewhere: `tblAssets.publicToken` + `/a/{token}` — `web/_sql/159_asset_tracker.sql:157,167`, `web/_core/Router.php:271-290`) |
| Song library, lyrics, CCLI, "when did we last sing hymn X" | **SHIPPED** (worship side) | `tblSongs` — `web/_sql/135_song_library.sql:5-26` (FULLTEXT title/author/lyrics); `tblCcliUsage` append-only usage log — `web/_sql/139_worship_phase3.sql:29`; song editor `web/_apps/worship/song.php:43-50` |
| **iHymns lookup for song items** | **MISSING — the genuine headline residual** | `grep -rn 'iHymns\|ihymns' web/` → **zero hits in code** (only docs/backlog mentions: `FEATURES.md:169,525`, `README.md:251`; `deploy.yml:9` refers to the iHymns *deployment pipeline*, not an integration). Run-sheet song items are pure free text (`edit.php:347` placeholder "Hymn 256 — Amazing Grace"); `tblSongs` has **no hymnal/number/tune fields** (`135:5-26`) |
| Settings page for iHymns config + templates | **MISSING** (for the residual only) | No `hymns.*` settings keys anywhere in `web/_sql/` |

### 1.2 The residual delta (all this plan builds)

1. **R1 — Hymnal lookup ("iHymns integration")**: a hymn picker on the run-sheet
   song rows (and reusable by the worship song library), backed by a local hymnal
   index (CSV-importable, manually editable) with an **optional, default-off,
   owner-configurable remote iHymns JSON lookup** that degrades gracefully.
2. **R2 — Congregation-facing Order of Service**: a `congregation` variant of the
   existing print view (internal notes suppressed) + an opt-in **public tokenised
   read-only page** (`/os/{token}`) with QR share, so the plan can go on a screen,
   a printed sheet, or a phone.
3. **R3 (glue)** — a nullable `tblServicePlanItem.songID` FK → `tblSongs`, so a
   picked hymn is stored canonically rather than as a third parallel representation.
   This is the natural maturation of #442's documented "no field sync in v1 — the
   song representations are structurally incompatible (free-text title vs canonical
   songID FK)" note (`173_worship_runsheet_link.sql` header, `ServicePlanLink.php:154`).

### 1.3 Explicitly NOT rebuilding (anti-duplication contract)

- **No new app**, no new AppRegistry entry, no new `Portal\Apps\ServicePlanner`
  namespace, no `web/public_html/service-planner/` (that layout predates #159 anyway).
- **No third service-plan data model.** Everything hangs off the existing
  `tblServicePlan`/`tblServicePlanItem` (run-sheet) and `tblSongs` (canonical song
  entity). `tblServicePlans`/`tblServicePlanItems` (worship) is untouched except the
  song editor gaining three metadata fields.
- No rebuild of: item CRUD/reorder, presenters, durations, notes, event linking,
  plan templates, the #442 pairing UI, projector/CCLI, or the leader print layout.
- No drag-and-drop upgrade of reordering (works today; cosmetic; separate issue if wanted).
- No PDF-file export in v1 (browser print over the existing print CSS covers both
  variants; dompdf reuse is a deferred option — see Open Questions).

---

## 2. iHymns, concretely

### 2.1 What we could establish

- The issue links **https://ihymns.co.uk/**. From this build sandbox the domain is
  **unverifiable**: WebFetch → DNS `ENOTFOUND`, direct curl → proxy `CONNECT 403`
  (egress allowlist). Web search surfaces **no public API for ihymns.co.uk**; the only
  "iHymns" with a public footprint is *iHymns.org* (the Christian Hymns tune project,
  Evangelical Movement of Wales) — a different domain and probably a different thing.
- Crucially, the repo's own docs show **iHymns is a sibling MWBM/MWservices
  deployment**: `deploy.yml:9` ("Mirrors the iHymns deployment model"), `deploy.yml:46`
  ("the iHymns model"), `DEV_NOTES.md:56-57` ("modelled on the iHymns pipeline") — same
  DreamHost SFTP multi-branch pipeline. So the integration target is almost certainly
  **owner-controlled**, and the issue's "written permission from iHymns" acceptance
  criterion is likely a formality — but there is **no evidence any API exists today**.

### 2.2 Decision: three-tier lookup, remote tier optional and default-off

- **Tier 0 — Manual (always, unchanged):** free-text song title keeps working exactly
  as today. Nothing regresses when nothing is configured.
- **Tier 1 — Local hymnal index (the primary ship):** new `tblHymnals` +
  `tblHymnalEntries` tables, populated by an admin **CSV import of a hymnal index**
  (`number,title,firstLine,author,tuneName,meter,ccliNumber,copyrightLine`) and/or row-by-row
  manual entry at `/admin/hymns`. The picker searches this index instantly with zero
  egress. Multi-hymnal by design (answers issue OQ3 — SDA Hymnal, Mission Praise, etc.
  are just rows in `tblHymnals`). **Metadata only — no lyrics imported** (lyrics carry
  the copyright risk; they stay in `tblSongs.lyrics`, hand-entered under the church's
  own CCLI licence as today).
- **Tier 2 — Remote iHymns lookup (generic JSON client, default OFF):** a small
  provider-agnostic client in `Portal\Core\Hymnal` that calls an owner-configured
  HTTPS base URL. Because no public API is documented, this plan **defines the
  contract we need** and the owner (who runs iHymns) can implement or map it
  server-side there:

  ```
  GET {baseUrl}/search?q={query}&hymnal={code?}&limit={n}
  → 200 {"results":[{"id":"…","hymnal":"CH","number":"256","title":"…",
                      "firstLine":"…","author":"…","tune":"…","meter":"…","url":"…"}]}
  ```

  Runtime behaviour: merged into picker results labelled "iHymns"; any failure
  (timeout, non-200, malformed JSON, egress blocked) → local results only + a
  non-blocking "remote lookup unavailable" notice. Successful responses are cached
  server-side (`tblHymnLookupCache`, TTL `hymns.remote.cacheTtl`, default 24h) so
  repeat searches never re-hit iHymns. A picked remote result is persisted into
  `tblHymnalEntries` (`sourceRef` = remote id/URL), so it needs no re-fetch ever.
- **No vendored dependency** (plain curl, matching `Translation.php:207-375`
  conventions); **no live fetch is ever load-bearing** — every page renders fully
  with egress blocked.
- The issue's "written confirmation from iHymns" AC maps to: `hymns.remote.enabled`
  stays `'false'` until the owner flips it — record the confirmation (or "it's ours,
  self-granted") on #128 when flipping.

---

## 3. Design

### 3.1 Data model (all additive; reuses existing tables)

**New table `tblHymnals`** — a hymn book known to a site:
- `hymnalID` INT PK AI; `siteID` INT NOT NULL FK → `tblSites` ON DELETE CASCADE
  (house tenancy pattern, cf. `tblSongs.siteID` `135:7,24`)
- `code` VARCHAR(20) NOT NULL (e.g. `CH`, `SDAH`, `MP`); `name` VARCHAR(120) NOT NULL;
  `publisher` VARCHAR(255) NULL; `isActive` TINYINT(1) NOT NULL DEFAULT 1;
  `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP
- UNIQUE `uq_hymnal_site_code (siteID, code)`

**New table `tblHymnalEntries`** — one hymn in one hymnal (metadata only, never lyrics):
- `entryID` INT PK AI; `hymnalID` INT NOT NULL FK → `tblHymnals` ON DELETE CASCADE
- `number` VARCHAR(20) NOT NULL (supports "256a"); `numberSort` INT NOT NULL DEFAULT 0
  (numeric prefix, for ordering); `title` VARCHAR(255) NOT NULL; `firstLine` VARCHAR(255) NULL;
  `author` VARCHAR(255) NULL; `tuneName` VARCHAR(120) NULL; `meter` VARCHAR(40) NULL;
  `ccliNumber` VARCHAR(40) NULL; `copyrightLine` VARCHAR(500) NULL;
  `sourceRef` VARCHAR(255) NULL COMMENT 'External id/URL when sourced from a remote provider (e.g. iHymns)';
  `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP
- UNIQUE `uq_hymnalentry (hymnalID, number)`; KEY `(hymnalID, numberSort)`;
  FULLTEXT `ft_hymnal_search (title, firstLine, author, tuneName)` (precedent `135:23`)

**New table `tblHymnLookupCache`** — short-TTL remote-result cache (site-scoped):
- `cacheID` INT PK AI; `siteID` INT NOT NULL FK → `tblSites` ON DELETE CASCADE;
  `queryHash` CHAR(64) NOT NULL (SHA-256 of normalised query+hymnal);
  `resultsJson` MEDIUMTEXT NOT NULL; `fetchedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
- UNIQUE `uq_hymncache (siteID, queryHash)`; KEY `(fetchedAt)` (opportunistic purge on write)

**Guarded ALTERs** (information_schema + PREPARE/EXECUTE idiom per DEV_NOTES —
house precedents 110/138/151/173; **never** MariaDB `IF NOT EXISTS` on ALTER):
- `tblSongs` + `hymnalCode` VARCHAR(20) NULL, + `hymnNumber` VARCHAR(20) NULL,
  + `tuneName` VARCHAR(120) NULL — a promoted hymn keeps its hymnal identity;
  surfaced in `worship/song.php` editor + `songs.php` list/search.
- `tblServicePlanItem` + `songID` INT NULL, FK `fk_spi_song` → `tblSongs(songID)`
  ON DELETE SET NULL, KEY `idx_spi_song (songID)` — canonical song reference for
  run-sheet song rows. `title` remains the display string (so `print.php`, the #442
  panel, and every existing read path work unchanged with `songID` NULL).
- `tblServicePlan` + `publicToken` CHAR(32) NULL, UNIQUE `uq_sp_public_token`,
  + `isPublicShared` TINYINT(1) NOT NULL DEFAULT 0 — mirrors `tblAssets.publicToken`
  (`159:157,167`; generated `bin2hex(random_bytes(16))`).

**FK-ordering note for the `full_schema.sql` fold:** new columns fold inline into the
existing CREATEs; `tblHymnals`/`tblHymnalEntries`/`tblHymnLookupCache` insert after
`tblSites`; the `tblServicePlanItem.songID` FK requires `tblSongs` (migration 135) to
be created **before** `tblServicePlanItem` (migration 089) in full_schema order — it is
NOT (089 < 135). So in full_schema the FK must be added as the file's established
alternative: keep the column inline but attach `fk_spi_song` in the same late position
pattern migration 173 used for `runSheetPlanID` (whose FK folds inline only because 089
precedes 137). **Concretely: fold `songID` column inline into `tblServicePlanItem`'s
CREATE; emit the FK as a guarded ALTER placed after `tblSongs`'s CREATE in
full_schema** — verify against the file's stated "FK ordering respected" guarantee at
build time (`173:23-29` documents exactly this reasoning).

### 3.2 Migration

- **Number: `177_hymnal_lookup_public_oos.sql`** — alpha currently tops at
  `174_workflow_engine.sql`; **175/176 are reserved** by in-flight webpush /
  shared-mailbox branches; the historical 168/169 gap is left alone (never backfill).
  **Re-confirm at build**: `ls web/_sql/ | sort` + `git ls-remote --heads origin` +
  scan sibling branches for `web/_sql/17[5-9]_*` (at plan time `git ls-remote` showed
  only alpha/beta/main + venue-*/gap423/dependabot branches — none carrying 175+, but
  the reservation instruction stands).
- Contents: 3 × `CREATE TABLE IF NOT EXISTS`; 6 guarded ALTER blocks (3 columns
  `tblSongs`, 1 column + 1 key + 1 FK `tblServicePlanItem`, 2 columns + 1 unique key
  `tblServicePlan`); route seeds; settings seeds; self-record INSERT into
  `tblMigrations` (`ON DUPLICATE KEY UPDATE filename=filename`, per `173:110-111`).
  Replays as a no-op on an up-to-date schema (installer contract).
- Route seeds (`tblRoutes`, all `isProtected=1`):
  - `admin/hymns` → `admin/hymns.php`
  - `admin/hymns/save` → `admin/hymns-save.php`
  - `service-plans/share` → `service-plans/share.php`
  - **NOT seeded:** `/os/{token}` (Router special route, deliberately DB-free —
    precedent `Router.php:277-279` "works even with tblRoutes unreachable");
    **no `api/*` rows ever** (ApiRouter ignores tblRoutes — CLAUDE.md trap).
- Settings seeds (`tblSettings`, siteID NULL defaults):
  - `hymns.remote.enabled` = `'false'` (default OFF — the issue's permission gate)
  - `hymns.remote.baseUrl` = `''`
  - `hymns.remote.apiKey` = `''`, **`isSensitive=1`** (stored via `encrypt_setting()`,
    `bootstrap.php:179` — migration-167 encrypted-at-rest precedent)
  - `hymns.remote.cacheTtl` = `'86400'`
  - `service_plans.public_share.enabled` = `'false'` (per-site opt-in for /os/)
  - `api.service-plans.hymn-search.enabled` = `'true'` (ApiRouter gating flag —
    without it the endpoint 403s; CLAUDE.md trap, `158` precedent)

### 3.3 Behaviour

**Hymn picker (run-sheet editor, `edit.php` song rows + add-row):** progressive
enhancement — a small JS typeahead against `GET /api/service-plans/hymn-search?q=…`
(session-authenticated; site-scoped). Results grouped by source: hymnal index /
song library (`tblSongs`) / iHymns (when enabled). Selecting fills
`title` = `"{code} {number} — {title}"`, sets a hidden `songID` (after promote, below),
and shows tune/author as muted hint text. JS absent/failed ⇒ plain text input, as today.

**Promote-to-song-library:** picking a hymnal entry upserts a minimal `tblSongs` row
(title, author, ccliNumber, copyrightLine, hymnalCode, hymnNumber, tuneName; no lyrics)
— check-first on `(siteID, hymnalCode, hymnNumber)` then insert, duplicate race
tolerated — and stores its `songID` on the item. Single canonical song entity for both
plan models; enables "when did we last sing hymn X" across run-sheets AND worship/CCLI.

**`item-save.php`:** accepts optional `songID`; **validates it against
`tblSongs.siteID = Site::id()`** before persisting (mirror of the presenterID
site-scope validation, `item-save.php:80-99`); invalid ⇒ NULL, never an error.

**Worship song editor:** `song.php`/`songs-save.php` gain the three new fields
(hymnalCode, hymnNumber, tuneName) alongside CCLI; `songs.php` search includes them.

**Admin page `/admin/hymns`** (admin-gated like the sms/transcription/translation
admin integrations): hymnal CRUD (portal-data-list, never `<table>`), CSV import
(size-capped upload, `fgetcsv`, prepared bulk INSERT ... ON DUPLICATE KEY UPDATE into
`tblHymnalEntries`, per-row error report), remote-provider settings
(enabled/baseUrl/apiKey with https validation) + a "Test connection" button
(BookIT/CloudflareStream `testConnection()` parity, migration-158 precedent).

**Congregation vs leader print:** `print.php` gains `?version=congregation|leader`
(default `leader`, byte-compatible with today). `congregation` suppresses
`notes` entirely and any `sectionType` internals; keeps numbering, titles, presenters,
scripture refs. Two buttons on `edit.php`.

**Public Order of Service `/os/{token}`:** Router special-route block cloned from
`/a/{token}` (`Router.php:283-290`): anchored `^[a-f0-9]{32}$`, sets `$_GET['token']`,
requires `service-plans/public.php` directly (no tblRoutes dependency). The handler:
- resolves token → plan; renders **only** when `isPublicShared=1` AND
  `status='published'` AND per-site `service_plans.public_share.enabled='true'`;
  **uniform 404** for every other state (unknown token, draft, archived, sharing off,
  app disabled) — `assets/tag.php` uniform-404 precedent;
- renders the congregation view only (never notes), `noindex,nofollow`, no session
  required, no personal data beyond presenter display names;
- share panel on `edit.php` (behind `service_plans.public_share.enabled`): CSRF'd POST
  to `service-plans/share.php` to enable / disable / **rotate** the token, plus a QR
  via the existing shared `/qr.php` utility (noticeboard precedent).

### 3.4 `Portal\Core\Hymnal` (new core class)

`searchLocal(siteId, q, ?hymnalId, limit)` (FULLTEXT + number-prefix match) ·
`searchRemote(siteId, q, limit)` (cache-first; SSRF-hardened curl; never throws to
callers — returns `[]` + logs) · `promoteToSong(siteId, entryId, userId): int` ·
`importCsv(siteId, hymnalId, stream): array{ok,skipped,errors}` ·
`generateShareToken(): string`. All queries prepared + site-scoped. Registered in
bootstrap's autoload map alongside the other `_core` classes.

---

## 4. File list

**New (8):**
1. `web/_sql/177_hymnal_lookup_public_oos.sql`
2. `web/_core/Hymnal.php`
3. `web/_apps/admin/hymns.php`
4. `web/_apps/admin/hymns-save.php` (settings save + hymnal CRUD + CSV import POST)
5. `web/_apps/service-plans/api/hymn-search.php` (ApiRouter convention path —
   `_apps/{app}/api/{action}.php`; hyphen appNames valid per `ApiRouter.php:108`)
6. `web/_apps/service-plans/share.php`
7. `web/_apps/service-plans/public.php`
8. (optional, if in-app docs wanted) `web/_apps/help/` hymns section — default: skip,
   fold a paragraph into the existing service-plans help page instead.

**Modified (10):**
1. `web/_core/Router.php` — `/os/{token}` special block (clone of `a/{token}`)
2. `web/_core/bootstrap.php` — autoload entry for `Hymnal` (only if the map is explicit; verify)
3. `web/_apps/service-plans/edit.php` — picker JS + hidden songID + share panel + print-variant buttons
4. `web/_apps/service-plans/item-save.php` — songID accept + site-scope validation
5. `web/_apps/service-plans/print.php` — `version` param, congregation suppression
6. `web/_apps/worship/song.php` — 3 new fields
7. `web/_apps/worship/songs-save.php` — persist 3 new fields
8. `web/_apps/worship/songs.php` — show/search hymnal ref
9. `web/_sql/full_schema.sql` — fold (tables + inline columns + late FK; see §3.1 note)
10. Docs: `FEATURES.md` (move #128 out of "Tracked but not started", rewrite rows
    169/525), `CHANGELOG.md`, `DEV_NOTES.md` (remote-provider contract + SSRF notes),
    `README.md:251`, `.claude/` memory. `_core/api-spec.json` untouched (hymn-search
    is an internal UI endpoint, not part of the public v1 facade — note on the PR).

House conventions applying throughout: `declare(strict_types=1)`; full IF notation;
emoji section comments; file headers (path/description/package/author/copyright/
version); `DIRECTORY_SEPARATOR`; prepared statements only; `htmlspecialchars(...,
ENT_QUOTES, 'UTF-8')` on all output; `portal-data-list` (no `<table>`); `data-confirm`
(never native `confirm()`); `ApiResponse::success()` not `::ok()`.

---

## 5. Security checklist

- **CSRF:** every POST (`share.php`, `hymns-save.php`, extended `item-save.php`) via
  `Auth::verifyCsrf` (existing pattern `item-save.php:18-21`).
- **Site-scoping / tenancy:** every hymnal query joins through
  `tblHymnals.siteID = Site::id()`; `songID` and `hymnalID` from POST validated
  against the current site before use (presenterID-validation precedent); the
  hymn-search endpoint requires a logged-in session (worship/api handlers precedent)
  and never accepts a siteID parameter.
- **Public `/os/{token}`:** 128-bit random token, anchored regex in Router, uniform
  404 (no oracle distinguishing "wrong token" from "sharing off"/"draft"), congregation
  fields only (notes NEVER rendered), `noindex,nofollow` + `Cache-Control` modest,
  no session cookies set, token rotation invalidates old links; optional
  `RateLimiter` reuse if abuse observed (not blocking v1).
- **SSRF (remote iHymns tier):** scheme locked to `https://` (refused at settings save
  AND re-validated at request time); host must equal the configured host exactly
  (single-host allowlist — no user-supplied URLs ever fetched); IP-literal and
  private/reserved-range hosts refused at save; `CURLOPT_FOLLOWLOCATION = false`
  (no-follow-redirect), `CURLOPT_PROTOCOLS = CURLPROTO_HTTPS`,
  `CURLOPT_CONNECTTIMEOUT 3` / `CURLOPT_TIMEOUT 5`, response body capped (~512 KB);
  documented residual: DNS-rebinding is not fully mitigable on DreamHost shared
  (no pinned resolver) — acceptable because the feature is default-off,
  owner-configured, single-host, GET-only.
- **Secrets:** `hymns.remote.apiKey` `isSensitive=1` + `encrypt_setting()` at rest;
  sent as a request header; **never logged** — `Logger::errorPlatform` messages carry
  status code + host only, never full URL/query/key.
- **CSV import:** admin-only, CSRF'd, upload size cap, `fgetcsv` parse, length-clamped
  fields, prepared inserts; no formula-injection surface (import only).
- **GDPR:** new tables hold no personal data (hymn metadata; cache keyed by site) —
  `GdprEraser::catalogue()` needs no additions; state this in the PR so the
  table-refs/eraser reviews don't flag it.
- **CI hygiene:** new tables land in `full_schema.sql` (keeps
  `check_php_table_refs.py` green); every seeded route's targetFile ships in the same
  change (`check_route_targets.py`); zero MariaDB-only DDL
  (`check_mariadb_only_ddl.py`); monitor the full PR Security Checks comment per the
  standing instruction.

---

## 6. Acceptance gates

1. `php -l` clean on every touched file; all 11 audit checks green; migration
   harness passes; **177 replays as a no-op** on an up-to-date schema; full_schema
   parity (fresh-install schema ≡ migrated schema, FK order verified).
2. **Zero-config regression gate:** with nothing configured (no hymnals, remote off,
   share off) every existing service-plans/worship page byte-behaves as today;
   `print.php` without `version` is unchanged.
3. Hymnal CSV fixture (≥10 rows incl. a "256a"-style number) imports; picker finds by
   number and by title fragment; re-import is idempotent (upsert, no dupes).
4. Picking an entry fills the title, upserts one `tblSongs` row (no dupe on re-pick),
   stores `songID`; a crafted cross-site `songID` POST is nulled, not saved.
5. Remote tier: `http://` baseUrl refused; endpoint down/slow ⇒ local results + notice,
   page fully functional; a 3xx from the provider is NOT followed; repeat query within
   TTL served from `tblHymnLookupCache` (no second outbound call); key never appears in
   logs or HTML.
6. Public view: OFF by default at both site and plan level; enabling yields a working
   `/os/{token}` for a **published** plan showing no notes; draft/archived/disabled/
   rotated-token/unknown-token all return the identical 404; QR resolves.
7. Congregation print variant renders no `notes` content under any item; leader
   variant unchanged.
8. Docs updated (FEATURES/CHANGELOG/DEV_NOTES/README + `.claude/` memory); #128
   re-scoped with a comment linking the PR; no new vendored dependencies.

---

## 7. Open questions (with recommended defaults)

1. **Does ihymns.co.uk expose an API?** Unverifiable from the sandbox (DNS/proxy
   blocked); no public documentation found; repo docs show it is an in-house sibling
   deployment. **Default:** ship Tier 0+1 now; build the generic HTTPS client behind
   `hymns.remote.enabled='false'`; owner implements/maps the §2.2 contract on iHymns
   and flips the flag — recording the issue's "written permission" AC (self-granted if
   owner-controlled) on #128 at that moment. Do not block the ship on iHymns.
2. **Auto-promote picked hymns into `tblSongs`?** **Default: yes** (upsert, metadata
   only) — one canonical song entity, feeds worship/CCLI and "last sung"; avoids a
   third song representation.
3. **Extend the `sectionType` ENUM with `benediction`/`childrens_story`?**
   **Default: no** — `other`/`prayer` + free title covers it; ENUM MODIFY (even
   guarded) buys little.
4. **Show presenter names on the public/congregation view?** **Default: yes**
   (standard on printed orders of service); notes always hidden. Revisit with a
   per-site setting only if requested.
5. **PDF-file export (dompdf) for the two variants?** **Default: defer** — the print
   CSS + browser print covers congregation & leader; `Pdf.php`/`ExpensePdf` precedent
   makes it a cheap follow-up if demanded.
6. **Tighten service-plans write ACL?** Today ANY logged-in user can edit run-sheets
   (`item-save.php:16-21` — login+CSRF only), vs the issue's `service.plan.edit`
   capability and worship's admin-or-coordinator gate. **Default: out of scope here**
   (changes shipped app behaviour); file a separate small issue referencing the
   worship gate as the model.
7. **Migration number 177** (175/176 reserved webpush/shared-mailbox; 168/169 gap left
   unfilled). **Default: 177, re-confirmed at build** per §3.2.
8. **Issue disposition:** **Default: keep #128 open and re-scope it** (retitle +
   comment + relabel to the service-plans app label); close it from the delta PR.
