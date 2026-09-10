# Build Plan — Issue #436: `tblEvents.venueID` / `tblEvents.roomID` + room-aware venue coverage

**Base:** `alpha` @ `3291898` (migration 175 = UPC-E, merged). Follow-up to the shipped Venue Bookings app (#429, migration 170) and the wall-clock fix (#435). **Additive only** — null links must reproduce today's behaviour byte-for-byte.

---

## 1. Current-state analysis (file:line evidence)

### 1.1 `classifyEventCoverage()` — the exact algorithm today

`web/_core/Venues.php:3406` — `public static function classifyEventCoverage(array $event, int $venueId): array`

**Inputs:** `$event` carries `tblEvents` columns *verbatim* (`startDateTime`, `endDateTime?`, `timezone?` — never pre-converted, per the docblock at `Venues.php:3398-3404`); `$venueId` is an int the function itself re-validates.

**Step-by-step:**

1. **Venue resolution + tenant gate** (`Venues.php:3408-3420`): `self::getVenue($venueId, Site::id())` — a null (bad ID, wrong site, venueId ≤ 0) returns the **dormant sentinel** `['classification' => 'venue-missing', 'severity' => 'success', 'message' => I18n::t('venues.coverage.venue_missing'), 'perDay' => [], 'dataConflict' => false]`. Severity `success` means `save.php` shows it as part of a success flash and the form check hides it — never an error. This is also the cross-tenant defence: a foreign venueID is indistinguishable from a missing one.
2. **Wall-clock timezone handling** (`Venues.php:3422-3448`): event tz from `$event['timezone']` (fallback `Europe/London`, `Venues.php:3423-3427`); venue tz via `self::venueTimezone($venue)` (`Venues.php:3859`). **Fast path** (`:3434-3439`): identical IANA names ⇒ both datetimes constructed directly in the venue zone — *zero conversion, `setTimezone()` never called, UTC never involved*. **Cross-zone path** (`:3440-3447`): construct in the event's zone, `->setTimezone($venTz)`. Missing end ⇒ `start + 1 hour` (`:3439`, `:3447`); `end <= start` ⇒ coerced to `start + 1 hour` (`:3458-3460`).
3. **Day expansion** (`Venues.php:3462-3472`): Y-m-d list from first to last date, capped at 31 days with a truncation note.
4. **One overlay fetch** (`Venues.php:3474`): `$days = self::availabilityForRange(Site::id(), $venueId, $firstDate, $lastDate)` — per-date buckets of booking rows (see §1.2).
5. **Per-day classification** (`Venues.php:3478-3524`): for each day, the local window is `$ls/$le` (`H:i:s` of event start/end on first/last day, else `00:00:00`–`24:00:00`, `:3481-3482`). The day's rows (`$rows = $days[$date] ?? []`, `:3483`) are partitioned into:
   - `$unavailableRows` — `usageKind === 'unavailable'` (`:3488`)
   - `$confirmedCovering` — `countsAsConfirmed=1 AND isBookable=1 AND startTime <= $ls AND endTime >= $le` (string TIME comparison, wall-clock, `:3489-3493`)
   - `$anyConfirmedBookable` — confirmed + bookable regardless of window (`:3494`)
   - `$closedRows` — `usageKind === 'closed'` (`:3495`)
   - `$unconfirmedRows` — bookable, unconfirmed, not `rejected` (`:3496-3498`)

   Verdict cascade (`:3500-3521`): `unavailable` (sets `dataConflict` if a confirmed hire coexists, `:3503-3505`) → `confirmed` → `outside-hours` → `closed` → `unconfirmed` → `no-booking` (matchedRows = rejected rows, `:3520`). **Nothing in this cascade looks at `roomID`** — a booking for *any* room of the venue counts (this is the exact gap; the class header at `Venues.php:20-22` pre-plans the fix: *"rule (b) must tighten to room-aware coverage (b.roomID IS NULL OR b.roomID = event.roomID) if events ever gain room placement"*).
6. **Overall verdict** (`Venues.php:3526-3539`): first classification present across days in `WORST_ORDER` (`Venues.php:104-111`: unavailable → no-booking → outside-hours → unconfirmed → closed → confirmed). Severity from `COVERAGE_SEVERITY` (`:113-120`, display-only map).
7. **Message** (`Venues.php:3541-3550`): `coverageMessage($overall, ['venue','date','window'])` (`:3564-3574`) → i18n keys `venues.coverage.*` (`web/_lang/en.php:355-362`; placeholders `:venue :date :window` only — `:status` documented as never populated). Multi-day wraps in `venues.coverage.multi_day_worst`.
8. **Return shape:** `{classification, severity, message, perDay: [{date, classification, rows}], dataConflict}`.

**Consumers (all of them — verified by grep):**
- `web/_apps/venues/api/check.php:99` — Surface A backend (`GET /api/venues/check`, params `start`/`end` strict `Y-m-d\TH:i` + `tz` + `venueID`, gated by `api.venues.check.enabled`, seeded in `170_venue_bookings.sql:586`). Passes the result straight through `ApiResponse::success($result)`.
- `web/_apps/calendar/manage/save.php:190` — Surface B: `$appendVenueCoverageFlash` closure (`save.php:179-202`) reads *transient* `$_POST['venueID']` (`:179`), guards with `AppRegistry::isEnabled('venues')` + try/catch + a `getVenue()` null-suppression (`:181-189`), and appends `$coverage['message']` to the success flash (`:195-198`). Called post-INSERT (`:266`) and post-UPDATE (`:338`). Never blocks a save.
- The grid views do **not** call it — they consume `availabilityForRange` only (day strips, §1.2). "The calendar warnings" = Surface A + Surface B.

### 1.2 Room model, bookings, and the overlay's existing room handling

- `tblVenueRooms` (`web/_sql/170_venue_bookings.sql:108-125`): `roomID` PK, `siteID`, `venueID` (FK → tblVenues ON DELETE CASCADE), `roomName` (UNIQUE per venue), `capacity`, `sortOrder`, `isActive`. Doc: *"Single-space venues (the common case) have zero rows here: `tblVenueBookings.roomID = NULL` means 'the whole venue'"* (`:104-106`).
- `tblVenueBookings.roomID` (`170_venue_bookings.sql:325`): `INT DEFAULT NULL COMMENT 'NULL = the whole venue (single-space venues always NULL, §3.2)'`, FK `fk_venbk_room` → tblVenueRooms **ON DELETE SET NULL** (`:355`), index `idx_venbk_room` (`:349`). So the booking side already has the exact semantics we need: **NULL = whole venue; set = that room only**.
- Save-side validation precedent: `Venues::saveBooking()` (`Venues.php:1292-1300`) validates a posted roomID via **`validateRoomForVenue(int $roomId, int $venueId, int $siteId): ?array`** (`Venues.php:3932-3947`, `private`, `WHERE roomID=? AND venueID=? AND siteID=?` — note: does **not** require `isActive=1`).
- `availabilityForRange()` (`Venues.php:3352-3396`) **already selects `b.roomID, r.roomName`** (`:3364`, LEFT JOIN `tblVenueRooms` at `:3372`) — every per-day row handed to `classifyEventCoverage` and to the calendar strip already carries the room. The strip renderer (`calendar/views/_venue_strip.php:129-138`) already shows `venueName — roomName (statusName)` in chip tooltips. **Nothing else is room-aware** — the coverage cascade ignores `roomID` entirely.

### 1.3 Event form + save path (where the picker slots in)

- **Form context loader** — `web/_apps/calendar/manage/index.php:119-137`: the shipped resilience pattern verbatim: `$defaultVenueId = 0; $venueOptions = [];` then `if (AppRegistry::isEnabled('venues') === true) { try { … App::settings('venues.calendar_default_venue') … Venues::listVenues($siteId, true); } catch (\Throwable) { reset to empties + error_log } }`. The partial renders the venue block **only when `$hasVenueCheck = count($venueOptions) > 0`** (`_event_form.php:40`, `:277`) — empty options ⇒ the form is byte-identical to pre-#429 output.
- **Form partial** — `web/_apps/calendar/manage/_event_form.php:277-402`: today's Surface A is an *advisory-only* `<select name="venueID">` (`:292-300`, preselecting `$defaultVenueId`) + a hidden alert div + debounced fetch JS (`:308-401`). The header comment (`:12-18`) explicitly says *"tblEvents has no venueID column, so nothing here is persisted"* — #436 flips exactly that.
- **Edit prefill:** `manage/index.php:50` loads `$editEvent` via `SELECT * FROM tblEvents` — new columns flow into `$ev` automatically, no query change needed.
- **Save handler** — `web/_apps/calendar/manage/save.php`: admin gate (`:29-32`), POST-only (`:35-38`), CSRF (`:40-45`); create INSERT lists 29 explicit columns (`:229-257`, types `'ssssssiiiisissssddssssssssiii'`); update builds `$setClauses`/`$paramTypes`/`$paramValues` dynamically (`:284-325`) with `WHERE eventID = ? AND siteID = ?` (`:325`). `venueID` is currently consumed only transiently at `:179`.
- **`tblEvents` columns** — `web/_sql/full_schema.sql:742-846`: no venueID/roomID anywhere (verified); `startDateTime`/`endDateTime` comments now correctly read "wall-clock local … NOT UTC" (fixed in #435/#448, `full_schema.sql:751-752`). tblEvents is created at line **742**; the venue tables live at **6798-7110** — *after* tblEvents (and after tblAssetOrgs, which tblVenues FKs, per the section comment at `full_schema.sql:6798`). This ordering matters for the migration design (§3).

### 1.4 🐛 Pre-existing bug found (fix in this PR — we rewrite this JS anyway)

The Surface A live check is **silently dead**: the form JS posts `startDateTime` / `endDateTime` / `timezone` (`_event_form.php:345-351`), but `check.php` reads **`start` / `end` / `tz`** (`check.php:71-73`; its header at `:19-21` documents `start`/`end`/`tz` as the contract). Result: `start` arrives empty → `invalid-range` 400 (`check.php:78-80`) → the JS `.catch()` → `hideAlert()` (`_event_form.php:384-386`). Every live check no-ops. `venueID` alone matches (`check.php:90`). The JS is the deviant side — align it to `start`/`end`/`tz` while adding `roomID`. Call this out in the PR body (it makes Surface A work for the first time).

---

## 2. Scope (what ships)

1. **Schema:** additive nullable `tblEvents.venueID` + `tblEvents.roomID`, two FKs `ON DELETE SET NULL`, two indexes — guarded MySQL-8-safe migration **179** + full_schema fold.
2. **Core:** `classifyEventCoverage()` gains an optional `?int $roomId = null` parameter; when set, per-day rows are filtered to `roomID IS NULL OR roomID = $roomId`, and a new `room-not-covered` classification fires when the room is uncovered but the venue is otherwise confirmed-booked. One new public accessor `Venues::getRoom()`. Null roomId ⇒ bit-for-bit today's behaviour.
3. **UI:** the manage-form venue picker becomes persistent and gains a cascading room select (room options filtered client-side to the chosen venue), still rendered only when the Venues app is enabled and non-throwing.
4. **Save path:** `save.php` validates and persists both links (site-scoped, room-belongs-to-venue), guarded so a disabled/absent/throwing Venues app leaves the columns untouched.
5. **Warnings:** Surface A (`check.php` + form JS, incl. the §1.4 bug fix) and Surface B (post-save flash) become room-aware, driven by the persisted links.
6. **Docs/i18n:** new `venues.coverage.room_not_covered` + room-picker label keys in `en.php`; CHANGELOG/FEATURES/DEV_NOTES entries.

**Not in scope** (open questions, §8): per-event coverage badges on grid views; venue/room fields on the events REST write API; event detail-page venue display; cy.php translations (venues has none today — `grep venues.coverage web/_lang/cy.php` is empty; I18n falls back to en).

---

## 3. Migration 179 — `179_event_venue_link.sql`

**Numbering:** `web/_sql/` on alpha tops out at `175_upce_barcode.sql` (merged). Per the coordination brief, **176 (shared-mailbox), 177 (webpush), 178 (order-of-service) are reserved by in-flight sibling branches** (the `claude/venue-b…f` siblings exist on origin but carry no 17x files yet — verified via `git ls-tree`; the reservations are session-level, not yet visible in git). **Reserve 179. Re-confirm at build time** with `ls web/_sql/ | sort` + `git ls-remote --heads origin` + a `git ls-tree origin/<branch> web/_sql/` sweep of any new sibling branches; take the next free number if 179 has been claimed.

**Template:** migration `173_worship_runsheet_link.sql` is the exact house model (guarded ADD COLUMN → guarded ADD KEY → guarded ADD CONSTRAINT, each via `SET @x := (SELECT COUNT(*) FROM information_schema…)` + `IF(...)` + `PREPARE/EXECUTE/DEALLOCATE` — `173:58-98`), per DEV_NOTES.md §"Portable DDL convention (MySQL 8.0 ∩ MariaDB)" (`DEV_NOTES.md:905`). MariaDB-only `IF NOT EXISTS` on ALTER is banned (ERROR 1064 on MySQL 8; CI: `check_mariadb_only_ddl.py`).

**Contents (6 guarded blocks + self-record, zero data migration, replays as a no-op):**

1. Guarded `ALTER TABLE tblEvents ADD COLUMN venueID INT DEFAULT NULL COMMENT 'Optional link to the hired external venue hosting this event — tblVenues.venueID; NULL = none (#436)' AFTER externalUid` (append after the last real column so the fold position matches full_schema).
2. Guarded `ADD COLUMN roomID INT DEFAULT NULL COMMENT 'Optional room within venueID — tblVenueRooms.roomID; NULL = whole venue (#436)' AFTER venueID`.
3. Guarded `ADD KEY idx_event_venue (venueID)`.
4. Guarded `ADD KEY idx_event_room (roomID)`.
5. Guarded `ADD CONSTRAINT fk_event_venue FOREIGN KEY (venueID) REFERENCES tblVenues(venueID) ON DELETE SET NULL` (information_schema.TABLE_CONSTRAINTS guard, `CONSTRAINT_TYPE='FOREIGN KEY'`, as `173:87-98`).
6. Guarded `ADD CONSTRAINT fk_event_room FOREIGN KEY (roomID) REFERENCES tblVenueRooms(roomID) ON DELETE SET NULL`.
7. `INSERT INTO tblMigrations … ON DUPLICATE KEY UPDATE filename = filename` (`173:110-111` pattern).

No new routes (both endpoints already exist and are flag-gated — `170:586-587`), no new settings keys, no tblRoutes rows (the #372 dead-route lesson: never register `api/*` in tblRoutes).

**PHP-level integrity note:** `ON DELETE SET NULL` on `fk_event_room` covers *room deletion*, but changing an **event's venue** does not touch its roomID at the SQL level — a stale roomID belonging to the old venue would otherwise persist. `save.php` must therefore always write venueID and roomID **together** (roomID forced NULL whenever it doesn't validate against the *new* venueID — §5.3). No DB trigger (house style has none).

**full_schema.sql fold — ordering constraint (important):** tblEvents (`full_schema.sql:742`) is created ~6,000 lines *before* tblVenues/tblVenueRooms (`:6815`/`:6849`), and full_schema contains **zero ALTER statements and no FOREIGN_KEY_CHECKS toggles** (verified). So the two FKs **cannot** fold inline into tblEvents' CREATE (forward reference ⇒ errno 1824 on fresh install), and tblEvents cannot move (dozens of later tables FK it). Fold strategy:
- Fold the **two columns + `idx_event_venue`/`idx_event_room` KEYs inline** into tblEvents' CREATE (after `externalUid`, before the PK/KEY block) — columns and plain indexes have no cross-table dependency.
- **Do NOT put `fk_event_venue`/`fk_event_room` in full_schema at all.** Add a comment at both the tblEvents fold and the venues section pointing at migration 179. The installer *replays every numbered migration after full_schema* (CLAUDE.md §SQL dialect trap), so on a fresh install 179's column guards no-op and its **FK guards add the two constraints** — end state identical to an upgraded install. On any subsequent replay all six guards no-op. `check_schema_seed_parity.py` compares only migration filenames, settings keys and route keys (verified from its docstring), so this creates no parity finding; `check_migration_idempotency.py` recognises the `SET @… PREPARE` guard idiom structurally.
- Also update the header count comment in `full_schema.sql` if it enumerates migrations, and add `('179_event_venue_link.sql')` to full_schema's tblMigrations seed block (parity check item 1).

---

## 4. Core design — `web/_core/Venues.php`

### 4.1 New classification vocabulary (additive)

```php
public const COVERAGE_ROOM_NOT_COVERED = 'room-not-covered';   // after line 98's siblings
```
- `WORST_ORDER` (`Venues.php:104-111`): insert **immediately after `COVERAGE_NO_BOOKING`** → `[unavailable, no-booking, room-not-covered, outside-hours, unconfirmed, closed, confirmed]`. (Unreachable when `$roomId` is null, so the ordering change cannot affect existing calls.)
- `COVERAGE_SEVERITY` (`:113-120`): `COVERAGE_ROOM_NOT_COVERED => 'danger'` (the event's actual space is not secured — same severity family as no-booking).

### 4.2 `classifyEventCoverage()` — exact signature/logic change

```php
public static function classifyEventCoverage(array $event, int $venueId, ?int $roomId = null): array
```
Optional trailing param ⇒ **both existing call sites (`save.php:190`, `check.php:99`) compile and behave identically unchanged.**

Logic deltas (all inside the existing function, minimal diff):

1. **Room resolution** (after the venue gate at `:3420`, before tz handling): if `$roomId !== null && $roomId > 0`, resolve `$room = self::validateRoomForVenue($roomId, $venueId, Site::id())` (private, same class — `:3932`). If it does **not** resolve (wrong venue, wrong site, deleted): degrade to `$roomId = null; $room = null;` — venue-level coverage, exactly today's output. Rationale: coverage is advisory; and a cross-tenant/cross-venue roomID must be indistinguishable from no room (no existence oracle — same philosophy as the venue-missing sentinel, `:3408-3419`). `$roomId <= 0` ⇒ also null.
2. **Per-day room filter** (the class-header's own planned rule, `Venues.php:20-22`) — immediately after `$rows = $days[$date] ?? [];` (`:3483`):
   ```php
   $allRows = $rows;   // unfiltered — kept for the room-not-covered probe
   if ($roomId !== null) {
       $rows = array_values(array_filter($rows, static fn (array $r): bool =>
           $r['roomID'] === null || (int) $r['roomID'] === $roomId));
   }
   ```
   `availabilityForRange` already returns `roomID` per row (`:3364`), so no query change. Every existing partition (`:3488-3498`) then operates on the filtered set: a whole-venue booking (roomID NULL) still covers every room; a booking for a *different* room no longer covers this event — including `unavailable`/`closed` rows scoped to another room (a "Room B unavailable" row must not mark a Room A event unavailable, and vice versa a whole-venue `unavailable` still blocks every room). `dataConflict` (`:3503-3505`) is computed on the filtered set (a conflict between *this room's* rows).
3. **`room-not-covered` detection** — in the final `else` branch of the cascade (`:3518-3521`), before settling on `no-booking`:
   ```php
   } elseif ($roomId !== null && count(array_filter($allRows, /* countsAsConfirmed=1 && isBookable=1 */)) > 0) {
       $classification = self::COVERAGE_ROOM_NOT_COVERED;
       $matchedRows    = /* those other-room confirmed rows */;
   } else { /* existing no-booking + rejected matchedRows, :3519-3520 */ }
   ```
   i.e. *this room* has nothing (no filtered rows of any kind reached an earlier branch) **but the venue has a confirmed bookable hire for some other room that day** ⇒ the precise #436 warning: "venue is booked — but for a different room". If the unfiltered set has only unconfirmed/closed/rejected rows for other rooms, plain `no-booking` stands (accurate: nothing confirmed anywhere).
4. **Message context** (`:3542-3546`): when `$room !== null`, add `'room' => (string) $room['roomName']` to `$context`; `coverageMessage()` (`:3564-3574`) adds `'room' => (string)($context['room'] ?? '')` to its params array (additive — existing keys don't reference `:room`).
5. **Return shape:** unchanged keys. Optionally add `'roomID' => $roomId` for API clients — additive, harmless (check.php passes the array straight through `ApiResponse::success`).

### 4.3 New public accessor (for `save.php` — `validateRoomForVenue` is private)

```php
/** Site+venue-scoped room fetch — the public mirror of getVenue() (Venues.php:383). */
public static function getRoom(int $roomId, int $venueId, int $siteId): ?array
{
    return self::validateRoomForVenue($roomId, $venueId, $siteId);
}
```
One-liner delegation; keeps the single SQL definition. (Deliberately does not require `isActive=1` — matching `saveBooking`'s own room rule at `:1295`, so an event keeps a room link that is later deactivated; the *picker* lists active rooms only.)

### 4.4 i18n — `web/_lang/en.php` (extend the #429 block at `:346-393`)

```php
'venues.coverage.room_not_covered' => ':venue is booked on :date, but not for :room — this event\'s room has no booking covering it.',
'venues.check.room_label'          => 'Room (optional)',
'venues.check.room_placeholder'    => '— Whole venue —',
'venues.check.room_help'           => 'Pick a specific room to check and record room-level coverage.',
```
Update the placeholder documentation comment at `en.php:352-354` (":venue, :date, :window" → add ":room"). No cy.php work (venues keys don't exist there; I18n falls back to en — consistent with #429 as shipped).

---

## 5. Surfaces

### 5.1 Form context — `web/_apps/calendar/manage/index.php` (`:119-137`)

Inside the **existing** guarded try/catch (do not add a second guard), after `listVenues`:
```php
$venueRoomOptions = [];                       // venueID => [{roomID, roomName}], active rooms only
foreach ($venueOptions as $vOpt) {
    $rooms = Venues::listRooms((int) $vOpt['venueID'], $siteId, true);   // Venues.php:588
    foreach ($rooms as $r) { $venueRoomOptions[(int) $vOpt['venueID']][] = ['roomID' => (int)$r['roomID'], 'roomName' => (string)$r['roomName']]; }
}
```
`catch` resets `$venueRoomOptions = []` alongside the existing resets (`:134-135`). Venue counts are tiny (external hired buildings — typically 1-3), so N small queries beat a new API endpoint (which would need an `api.venues.rooms.enabled` flag + spec — see ApiRouter trap). No new endpoint.

**Edit-mode room-link repair display:** `$editEvent` comes from `SELECT *` (`:50`) so `$ev['venueID']`/`$ev['roomID']` are available to the partial with no query change.

### 5.2 Form partial — `web/_apps/calendar/manage/_event_form.php`

- Header comment (`:12-18`): rewrite — the picker is now **persisted** (#436); update `@version`, add `@link` #436.
- Defensive defaults (`:36-40`): add `$venueRoomOptions = $venueRoomOptions ?? [];`.
- Venue `<select name="venueID">` (`:292-300`): preselect **`(int)($ev['venueID'] ?? 0)` when editing an event that has a link; else `$defaultVenueId`** (the stored link must beat the site default). Keep option value `0` = "— Not applicable —" ⇒ NULL.
- New room `<select name="roomID" id="venueRoomSelect-…">` beside it (same `col-12 col-md-6` grid rhythm; label `venues.check.room_label`, placeholder option value `0` = `venues.check.room_placeholder`): server-render the options for the *initially selected* venue (edit prefill via `(int)($ev['roomID'] ?? 0)`), then let JS own repopulation. Visually disable (not hide) the select when the chosen venue has no rooms — hiding/showing would complicate the byte-identical guarantee reasoning; the whole block is already conditional on `$hasVenueCheck`.
- JS block (`:308-401`), same IIFE:
  1. Embed `var roomsByVenue = <?php echo json_encode($venueRoomOptions); ?>;` (json_encode with default flags is XSS-safe inside `<script>` here per house precedent `:314`; use `JSON_HEX_TAG` for belt-and-braces since roomName is user-authored).
  2. On venue change: rebuild room options from `roomsByVenue[venueId] || []`, reset to `0`, toggle `disabled`.
  3. `runCheck()` (`:335-387`): **fix the §1.4 param bug** — send `start` (was `startDateTime`), `end`, `tz`, keep `venueID`, add `roomID`. Everything else (debounce 500ms, AbortController, `textContent`-only alert writes, silent catch) stays as-is.
  4. Add the room select to the change-listener list (`:396-399`).

**Byte-identical guarantee:** everything new sits inside the existing `<?php if ($hasVenueCheck === true): ?>` region (`:277-402`); `$venueOptions` empty (app disabled / registry missing / Venues:: threw) ⇒ `$hasVenueCheck === false` ⇒ output unchanged from today, which is itself byte-identical to pre-#429.

### 5.3 Save path — `web/_apps/calendar/manage/save.php`

New block after `$siteId = Site::id();` (`:170`), **before** the create/update branches:

```php
// 🏛️ #436 — resolve the persisted venue/room links. Tri-state:
//   $venueLinkActive === false  ⇒ Venues disabled/absent/threw ⇒ do not touch the columns
//   $venueLinkActive === true   ⇒ write $eventVenueID / $eventRoomID (either may be NULL)
$venueLinkActive = false; $eventVenueID = null; $eventRoomID = null;
if (AppRegistry::isEnabled('venues') === true) {
    try {
        $venueLinkActive = true;
        $postedVenue = (int) ($_POST['venueID'] ?? 0);
        $postedRoom  = (int) ($_POST['roomID'] ?? 0);
        if ($postedVenue > 0 && Venues::getVenue($postedVenue, $siteId) !== null) {   // tenant gate
            $eventVenueID = $postedVenue;
            if ($postedRoom > 0 && Venues::getRoom($postedRoom, $postedVenue, $siteId) !== null) {
                $eventRoomID = $postedRoom;                                            // room ∈ venue ∈ site
            }   // else: silently NULL — never a save-blocking error (advisory feature)
        }       // venue 0/foreign ⇒ both NULL (roomID never survives without its venue)
    } catch (\Throwable $e) {
        $venueLinkActive = false; $eventVenueID = null; $eventRoomID = null;
        error_log('Calendar save: venue link resolution failed: ' . $e->getMessage());
    }
}
```

- **Create** (`:229-257`): when `$venueLinkActive === true`, add `venueID, roomID` to the column list + two `?` + `'ii'` in the types string + the two values (nullable ints bind fine as `'i'` with null — same as `$categoryID` at `:58/:250-252`). When `false`, INSERT exactly as today (columns default NULL).
- **Update** (`:284-325`): when `$venueLinkActive === true`, append `'venueID = ?', 'roomID = ?'` to `$setClauses` + `'ii'` + values. When `false`, **omit them entirely** — this is the write-path resilience rule: toggling the Venues app off and editing an event must *preserve* existing links, never wipe them (mirror of the render-path byte-identical rule).
- **Surface B** (`:179-202`, `:266`, `:338`): the closure now uses the *validated persisted* values, not raw POST: change signature to `$appendVenueCoverageFlash($startDateTime, $endDt, $timezone)` capturing `$eventVenueID/$eventRoomID` (`use`), early-return when `$eventVenueID === null`, drop the internal duplicate `getVenue` probe (validation already done — keep the try/catch), and call `Venues::classifyEventCoverage([...], $eventVenueID, $eventRoomID)`. The `venue-missing` suppression comment (`:176-177`) updates accordingly.
- CSRF (`:40-45`) and admin gate (`:29-32`) already cover the new fields — no change.

### 5.4 API — `web/_apps/venues/api/check.php`

- After `$venueIdParam` (`:90-91`): `$roomIdParam = (int)($_GET['roomID'] ?? 0); $roomId = $roomIdParam > 0 ? $roomIdParam : null;`
- `:99`: `Venues::classifyEventCoverage($event, $venueId, $roomId);`
- Header doc (`:19-34`): document `roomID` (optional int; re-validated against venue+site inside `classifyEventCoverage`, degrades to venue-level — no duplicate check here, same philosophy as the venueID paragraph at `:28-34`).
- Same endpooint ⇒ existing `api.venues.check.enabled` flag still gates it — **no new settings key, no tblRoutes row** (ApiRouter trap). `api-spec.json` has no venues paths today (verified) — nothing to update.

### 5.5 Calendar grid views

**No changes.** The day strips are venue-level day summaries fed by `availabilityForRange` and already display room names in tooltips (`_venue_strip.php:129-138`). Per-event coverage badges in grids would need one `classifyEventCoverage` call per event per page (N× `availabilityForRange` queries) — deferred (Open Question 3).

---

## 6. File list (10 modified + 1 new; all paths repo-absolute)

| # | File | Change |
|---|------|--------|
| 1 | `web/_sql/179_event_venue_link.sql` | **NEW** — 6 guarded DDL blocks + tblMigrations self-record (§3) |
| 2 | `web/_sql/full_schema.sql` | Fold 2 columns + 2 KEYs inline into tblEvents CREATE (`:742-846`); FK-deferral comments; tblMigrations seed row for 179 |
| 3 | `web/_core/Venues.php` | New const + WORST_ORDER/COVERAGE_SEVERITY entries (`:96-120`); `classifyEventCoverage` `?int $roomId` + room filter + room-not-covered branch (`:3406-3559`); `coverageMessage` `:room` param (`:3564-3574`); public `getRoom()`; retire the `:20-22` "Q12 follow-up" header note (now implemented); `@version` bump |
| 4 | `web/_lang/en.php` | 4 new keys + placeholder-doc comment update (`:346-393` block) |
| 5 | `web/_apps/calendar/manage/index.php` | `$venueRoomOptions` loading inside the existing guard (`:119-137`) |
| 6 | `web/_apps/calendar/manage/_event_form.php` | Persistent venue preselect, room select, cascade JS, **`start`/`end`/`tz` param fix** (`:277-402`); header rewrite |
| 7 | `web/_apps/calendar/manage/save.php` | Link resolution block; INSERT/UPDATE column wiring; room-aware Surface B (`:170-202`, `:229-257`, `:284-325`) |
| 8 | `web/_apps/venues/api/check.php` | Optional `roomID` param + header doc (`:19-34`, `:90-99`) |
| 9 | `CHANGELOG.md` | #436 entry |
| 10 | `FEATURES.md` | Venue Bookings + Calendar rows: per-event venue/room link + room-aware coverage |
| 11 | `DEV_NOTES.md` | Note the full_schema FK-deferral pattern (first occurrence — tblEvents→tblVenues ordering) + the Surface A param-fix |

(Plus `.claude/` memory per standing instructions at commit time. No version.php bump unless this rides a release PR.)

---

## 7. House conventions & security checklist

**Conventions (every touched file):** `declare(strict_types=1)`; full IF notation (`if ($x === true)`); MySQLi prepared statements only; `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` on all new output (roomName in options!); emoji-annotated section comments; full file-header blocks with #436 `@link`; no `<table>`; `DIRECTORY_SEPARATOR` (n/a — no new paths).

**Security checklist:**
1. **Cross-tenant:** venue link only persists after `Venues::getVenue($id, $siteId)` (site-scoped, `Venues.php:383`); room only after `getRoom($roomId, $venueId, $siteId)` (`WHERE roomID=? AND venueID=? AND siteID=?`, `:3938`) — an event can never link a foreign site's venue/room, and a foreign roomID probed via `/api/venues/check` degrades to venue-level output (no existence oracle).
2. **Room ∈ venue invariant:** roomID is written only alongside a venueID it validated against; venue cleared ⇒ room cleared; venue changed ⇒ old room fails `getRoom` against the new venue ⇒ NULL (§3 note). DB backstop: both FKs `ON DELETE SET NULL`.
3. **CSRF:** new fields ride the existing `Auth::verifyCsrf` POST (`save.php:40-45`); `check.php` stays read-only session-or-bearer (`:52`), no CSRF surface.
4. **Disabled-app byte-identity (render):** all form additions inside `$hasVenueCheck` (`_event_form.php:277`), which is empty-options-gated by the `AppRegistry::isEnabled('venues')` + try/catch loader (`manage/index.php:128-137`). Calendar views untouched ⇒ trivially identical.
5. **Disabled-app write-preservation:** UPDATE omits venueID/roomID when the guard is inactive — links survive an app toggle; saves never fail because of Venues (resolution errors ⇒ NULL + `error_log`, never a flash error or abort).
6. **Wall-clock rule (#435 — non-negotiable):** zero changes to the tz logic (`Venues.php:3422-3448`); the room filter operates on `availabilityForRange`'s venue-local TIME strings after the existing wall-clock window computation. **UTC is never introduced anywhere.**
7. **XSS:** roomName escaped via `htmlspecialchars` in server-rendered options; `roomsByVenue` embedded via `json_encode(..., JSON_HEX_TAG)`; JS keeps `textContent`-only alert writes (`_event_form.php:380-383`); JS-built room options use `document.createElement`/`textContent`, never innerHTML.
8. **SQL:** two new bind params typed `'i'` (nullable); no interpolation; `check_bind_param_arity.py` will verify the retyped strings — count characters carefully on `save.php` (`'ssssssiiiisissssddssssssssiii'` create string grows by `ii`).
9. **No new attack surface:** no new routes, endpoints, or settings flags.

---

## 8. Acceptance gates

1. `php -l` clean on every touched `.php` file (zero warnings).
2. **All 11 audit checks green** (`tools/audit-checks/*.py`, incl. `--strict` where CI uses it): notably `check_migration_idempotency` (guard idiom), `check_mariadb_only_ddl` (no `IF EXISTS` ALTERs), `check_schema_seed_parity` (179 in full_schema's tblMigrations seed), `check_sql_columns` (venueID/roomID present in the map via full_schema fold), `check_bind_param_arity`, `check_settings_keys`.
3. **Migration idempotency:** 179 applies cleanly on (a) an upgraded 175-schema and (b) a fresh full_schema install (installer replay adds the FKs), and **re-runs as a pure no-op** on both; e2e-migrations harness green.
4. **Behavioural — regression:** with `venueID`/`roomID` NULL (every pre-existing event) and for both existing `classifyEventCoverage` call sites uncalled with a room, coverage output (classification, severity, message, perDay, dataConflict) is bit-for-bit identical to alpha HEAD.
5. **Behavioural — the #436 scenario:** event assigned venue V + room A on a day where V has a *confirmed* booking for room B only ⇒ classification `room-not-covered`, severity `danger`, message names the venue, date and room; same day with a confirmed **whole-venue** (roomID NULL) booking ⇒ `confirmed`; confirmed booking *for room A* with covering times ⇒ `confirmed`; room-A booking with non-covering times ⇒ `outside-hours`; whole-venue `unavailable` row ⇒ `unavailable` even with a room set; room-B-only `unavailable` row ⇒ does **not** poison room A.
6. **Venues disabled:** `/calendar` (all 7 views), `/calendar/manage` list + create + edit forms byte-identical to alpha HEAD (diff the rendered HTML); editing and saving an event that already has links preserves them.
7. **Save-path validation:** posting a foreign-site venueID or a roomID from another venue stores NULL (never errors, never stores); clearing the venue clears the room; the create + update flash shows the room-aware coverage line.
8. **Surface A live check works** (for the first time): form fetch hits `/api/venues/check?start=…&end=…&tz=…&venueID=…&roomID=…` and renders the alert; venue-with-rooms cascade populates/disables correctly; abort/failure still silently hides.
9. PR Security Checks bot comment clean (route-target-missing n/a — no new routes; SQL drift n/a; etc.) per the standing instruction; CodeQL/Psalm no new findings.

---

## 9. Open questions (with recommended defaults)

1. **Invalid posted room/venue: silently NULL vs. blocking validation error?** — **Default: silently NULL + `error_log`** (chosen in §5.3). The link is advisory metadata; a stale select value must never lose an admin's event edit. `Venues::saveBooking` errors on invalid rooms (`:1296`) but that's the booking register's own domain where the room *is* the data.
2. **New classification constant vs. reusing `no-booking` with a room-flavoured message?** — **Default: new `room-not-covered` constant** (§4.1). API consumers of `/api/venues/check` get a machine-distinguishable verdict; `WORST_ORDER`/`COVERAGE_SEVERITY` are designed for additive growth; unreachable when roomId is null so zero regression risk.
3. **Per-event coverage badges on the calendar grid views (calling `classifyEventCoverage` per rendered event)?** — **Default: defer** to a follow-up. N-per-page query cost, and the day strips + form/save warnings already cover the workflow. If pursued, batch via one `availabilityForRange` reuse per view, not per event.
4. **Expose `venueID`/`roomID` on the events REST write API (`events/api/create|update.php`) and OpenAPI?** — **Default: defer.** Read side is automatic (`events/api/detail.php:39` uses `SELECT e.*` — the two nullable ints will appear in detail responses; harmless, note in PR). Write-side needs its own scope discussion (#323 conventions).
5. **Should the existing coverage messages (confirmed/outside-hours/…) also name the room when one is set?** — **Default: no** — only the new `room_not_covered` key references `:room`; keeps the en.php diff minimal and avoids re-translating shipped strings. `:room` is plumbed through `coverageMessage()` regardless, so flavouring later is a lang-file-only change.
6. **Room select UX when the chosen venue has zero rooms: disabled vs. hidden?** — **Default: disabled with the "— Whole venue —" placeholder** (§5.2). Stable layout, self-documenting semantics (roomID NULL = whole venue, mirroring `tblVenueBookings.roomID`'s comment at `170:325`).
7. **Backfill events from existing `tblVenueBookings.eventID` links (bookings already point at events, `170:337`)?** — **Default: no backfill** in 179 (additive-only brief; a booking→event link is "this hire hosts that event", not necessarily "that event is AT this venue" — e.g. multi-venue events). Revisit as an admin one-shot tool if wanted.
8. **Migration number** — **179**, contingent on the build-time re-scan (§3); siblings hold 176-178.
