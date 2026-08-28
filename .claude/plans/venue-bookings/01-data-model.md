# Venue Bookings — Stage 01: Data model & domain design

**Pipeline:** Stage 01 of 3 (data model → app design → build plan). Read `00-brief.md` first — its four confirmed decisions are fixed inputs here.
**Written:** 2026-08-27 against `main`-lineage schema state: migrations 000–165 present, `full_schema.sql` @ 6,947 lines, latest table block = Asset Tracker Phase 3 (migration 161). Migration numbers **166–169 are reserved elsewhere** (in-flight gap-analysis items); this design reserves **170–173** (§12).

Every claim below about existing schema/code was verified against the repo in this session (file + line refs given). Downstream agents can copy the DDL verbatim — it follows the house style exactly (see §0).

---

## 0. House conventions applied (verified, with sources)

All from `web/_sql/full_schema.sql` unless noted:

| Convention | Source precedent |
|---|---|
| Plain `CREATE TABLE IF NOT EXISTS`, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci`, table `COMMENT='… (#issue)'` | every app table; e.g. `tblAssetCategories` (l.6109) |
| `siteID INT NOT NULL DEFAULT 1` + `CONSTRAINT fk_xxx_site FOREIGN KEY (siteID) REFERENCES tblSites(siteID)` (no ON DELETE clause ⇒ RESTRICT) | `tblAssets` (l.6153), `tblResource` (l.3381) |
| Money = integer minor units `…Pence INT` + `currency CHAR(3) NOT NULL DEFAULT 'GBP'` — "house pence convention (#266)" | `tblAssets.purchaseCostPence` (l.6173), `tblPayment.amountPence` (l.4092) |
| PK `INT NOT NULL AUTO_INCREMENT`, camelCase columns, `createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `updatedAt … ON UPDATE CURRENT_TIMESTAMP` | throughout |
| Flags `TINYINT(1) NOT NULL DEFAULT 0/1`; soft-disable via `isActive`; soft delete via `isDeleted` on high-value rows | `tblAssets`, `tblEvents` |
| Creator FK `createdByID → tblUsers ON DELETE RESTRICT`; optional actor FKs `ON DELETE SET NULL` | `tblAssets` (l.6198/6214), `tblAssetLoans` |
| Per-table-family unique FK-constraint prefix, **checked against the full `CONSTRAINT \`fk_` inventory before choosing** (migration 161 header records the historical `fk_ast_site` collision) | `web/_sql/161_asset_tracker_phase3.sql` header |
| Reminder single-shot dedupe log, **no FKs by design**, `UNIQUE (refType, refID, dueDate)` | `tblAssetReminderLog` (l.6489) |
| Import staging = batch table + row table with parse snapshot + match state | `tblBankImports`/`tblBankTxns` (l.5913/5932, migration 152) |
| Recurrence philosophy: rules **generate materialised per-date rows**; the occurrence row is the source of truth | `tblRecurrenceRules` → `tblEvents` (l.712 comment: "generates individual event dates") |
| Config vocab tables are per-site rows with `sortOrder`/`isActive`; **no per-site row seeds in migrations** (only siteID-NULL settings seeds); vocab rows are seeded PHP-side | `tblAttendanceServiceTypes`, `tblGivingCategory` (no `INSERT` seeds exist for either) |
| Colour column style `color VARCHAR(9) DEFAULT NULL COMMENT 'Hex colour (#RRGGBB or #RRGGBBAA) …'` | `tblEventCategories.color` (l.600) |
| **Zero SQL views in the schema** (grep `CREATE VIEW` = 0 hits) → availability logic is a PHP helper, not a view (§8) | verified |
| New tables need no idempotency guard; **any ALTER on an existing table needs the information_schema + PREPARE/EXECUTE guard** (MySQL 8.0 rejects MariaDB `IF [NOT] EXISTS` forms with ERROR 1064) | DEV_NOTES.md "Portable DDL convention"; migration 161 header |
| Migration self-records: `INSERT INTO tblMigrations (filename) VALUES ('NNN_…') ON DUPLICATE KEY UPDATE filename = filename` | tail of every migration, e.g. 161 |

**FK/index prefix reservation for this app (verified collision-free):** `fk_ven_`, `fk_venrm_`, `fk_venut_`, `fk_venutw_`, `fk_venst_`, `fk_vengrp_`, `fk_venbk_`, `fk_venagr_`, `fk_venagf_`, `fk_veninv_`, `fk_venil_`, `fk_venip_`, `fk_venimb_`, `fk_venimr_` and `idx_ven*`/`uq_ven*`. The only existing `fk_v*` constraint in the whole schema is `fk_visitor_*`; no `venue`/`tblVenue`/`idx_ven`/`uq_ven` identifier exists anywhere in `web/_sql/`.

---

## 1. The four big reuse decisions

### 1.1 Landlord = REUSE `tblAssetOrgs` (yes) — hire agreements = NEW tables (do NOT reuse the vault)

**Landlord: reuse.** `tblAssetOrgs` (full_schema l.6136–6151) is already a *generic per-site external-organisation registry* — `siteID, orgName, contactName, contactEmail, contactPhone, agreementRef, notes, isActive, createdAt` — whose own table comment scopes it as "external organisations (owners/lenders/borrowers)". A landlord is precisely a lender (of a building). Schema fit is 100%: nothing a landlord party needs is missing, and duplicating it as `tblVenueLandlords` would create two divergent org registries on the same site (the same Baptist church could be both an asset lender and the venue landlord — one record, not two). Therefore:

- `tblVenues.landlordOrgID INT DEFAULT NULL` → FK `tblAssetOrgs(orgID) ON DELETE SET NULL`.
- Schema-level coupling is safe: **all tables ship in every install regardless of which apps are enabled** (the installer runs `full_schema.sql` + all migrations; app toggling is purely `tblSettings` flags), so the FK target always exists even where the Assets app is off.
- **Coupling risk + mitigation (stage 02 must implement):** org CRUD currently lives in `AssetRegister::saveOrg()` (`web/_core/AssetRegister.php` l.~3563–3625) and `_apps/assets/orgs.php`. The Venues app must ship its own thin landlord picker + create/edit form so landlord management works when the Assets app is disabled. Recommended: call `Portal\Core\AssetRegister::saveOrg()` directly (it is a plain core class, always loaded, app-toggle-independent) rather than duplicating the SQL; stage 02 must verify `saveOrg()`'s logging path is venue-safe (orgs are site-level reference data — AssetRegister logs them via `Logger::activity()`, not the asset-scoped `audit()`, per its class-header "point 2" convention, so no phantom assetID is needed).

**Hire agreements: thin new tables.** The Assets "agreement vault" is NOT an org-level document store — it is rows of `tblAssetResources` with `resourceType IN ('ownership-agreement','insurance','legal')` (`AssetRegister::AGREEMENT_VAULT_RESOURCE_TYPES`, AssetRegister.php l.646), **hard-keyed to `assetID` with `ON DELETE CASCADE`** (l.6328) and gated by asset-specific confidentiality logic (`isResponsibleFor()` / `listAgreementDocs()`, l.6048). Reusing it for venue hire agreements would require either (a) a phantom `tblAssets` row per venue (semantic abuse: a rented building is explicitly NOT an owned asset — decision #2 in the brief separates Venues from Assets for exactly this reason), or (b) a guarded ALTER making `tblAssetResources.assetID` nullable + adding `venueID` + rewriting the vault gating — deep cross-app coupling for zero gain. Moreover a hire agreement is a first-class *business record* (term dates, rate, renewal date, status, supersession chain), not a document attachment. So: **`tblVenueAgreements` + `tblVenueAgreementFiles`** (§3.8–3.9), with the files table mirroring the `tblAssetResources` upload-field shape so upload handling code is congruent.

### 1.2 Multi-day representation = ONE ROW PER DAY, linked by a booking group

`tblVenueBookings` has exactly one `bookingDate DATE` per row; a multi-day run (the VBS week) is N rows sharing a `groupID` → `tblVenueBookingGroups`. **No `endDate` column on the booking row.** Justification:

1. **Matches the source of truth 1:1** — the church's workbook is one row per date; import becomes a straight row→row mapping with no range-splitting logic.
2. **Per-day divergence is real**: within a VBS week individual days can carry different TIMES, different NOTES, even different STATUS (a single rejected day inside an agreed run). A range row cannot represent that without exception sub-rows — strictly more complexity.
3. **Queries stay trivial and indexable**: "is venue V booked on date D?" is an equality hit on `(venueID, bookingDate)`; a range model needs `startDate <= D AND endDate >= D` overlap scans everywhere (calendar overlay, conflict engine, reminders).
4. **House precedent**: the Events platform materialises one `tblEvents` row per occurrence for the same reasons.
5. The group row preserves the "this was one hire" identity for UI (edit/cancel whole run), invoicing (invoice the run), and audit.

### 1.3 Recurrence = a GENERATOR that bulk-inserts per-date rows (parameters recorded on the group), not a query-time RRULE

The weekly-worship rows are generated by a "generate bookings" tool that expands (frequency, interval, days-of-week, date range) into individual `tblVenueBookings` rows, all tagged with one `tblVenueBookingGroups` row of `groupType='recurring'` that **stores the generation parameters** (`frequency`, `intervalVal`, `daysOfWeek` CSV `0=Sun..6=Sat` — same encoding as `tblRecurrenceRules.dayOfWeek` — `dateFrom`, `dateTo`). Justification:

- Every occurrence **immediately diverges** in the real data: each Saturday is individually proposed → agreed/rejected by the landlord, times get overridden, notes differ. A stored-rule model would need a per-occurrence exception row for nearly 100% of occurrences — i.e. it degenerates into the materialised model with extra steps.
- The conflict/overlay engine (§8) must answer point date queries cheaply; materialised rows keep it to indexed equality.
- Storing the parameters on the group still allows "extend this series into 2027" (re-run the generator from `dateTo`+1) and honest audit of what was generated. Deliberately **no template snapshot** (usage type/status/notes) is stored: extending into a new schedule year must re-ask those anyway (defaults change per year; a new year's rows start life un-agreed, never blind-copied as "Agreed").
- Generator dedupe rule (data-level contract for stage 02): before inserting a date, skip it if any non-deleted `tblVenueBookings` row already exists for `(venueID, bookingDate)` with the same `roomID` (NULL-equal); report skipped dates to the user. No DB unique key on `(venueID, bookingDate)` — two same-day bookings (morning hire + evening hire, or two rooms) are legitimate.

### 1.4 Invoicing/payments = standalone payable-ledger tables with an OPTIONAL bridge to `tblPayment` (+ new `'venue-hire'` purpose)

Critical domain observation: the church is the **TENANT** — rent flows **outbound** (landlord invoices the church; church pays by bank transfer/standing order). `Payments.php` (`web/_core/Payments.php`) is an **inbound** rail (Stripe Checkout → `markPaymentSucceeded()` fan-out over `purpose ∈ Payments::PURPOSES = ['giving','pledge','membership','other']`, l.40). So the *primary* payment-tracking flow must be manual/offline recording — modelled on `tblExpenseClaimPayments` (partial payments against a claim, l.482) — not a Stripe checkout.

Design (§3.10–3.12): `tblVenueInvoices` (payable invoices received from the landlord) ← `tblVenueInvoiceLines` (which bookings an invoice covers) and ← `tblVenueInvoicePayments` (money-out records: date, amount, method, reference). The bridge to the existing rail is **optional and forward-compatible**: `tblVenueInvoicePayments.portalPaymentID → tblPayment(paymentID) ON DELETE SET NULL`, plus **one guarded ALTER** adding `'venue-hire'` to the `tblPayment.purpose` ENUM (`purposeRef` = the `invoiceID`, string-encoded, matching the existing `'pledge'` convention). That covers the future case where money DOES move through the portal rails (e.g. a landlord accepting card payment, or a multi-tenant install where the *landlord side* runs the portal), and it gives `Payments::markPaymentSucceeded()` a clean fan-out target (`venue-hire` → mark invoice paid — stage 02 adds the branch + extends `Payments::PURPOSES`). Rejected alternative — making `tblPayment` the invoice store — fails because `tblPayment` rows are provider-transaction records (`provider`/`providerRef NOT NULL`, unique per provider ref): an offline bank transfer to a landlord has no provider transaction and would need fake refs.

### 1.5 Audit = REUSE generic `tblAuditTrail` via `Logger::audit()` (no new audit table)

Two live patterns exist: generic `tblAuditTrail` (l.1163, written by `Logger::audit(tableName, recordId, action∈create|update|delete, oldData, newData, userId?, apiKeyId?, source?)` — Logger.php l.111, with `changeSet` JSON diff and #323 API-key attribution built in) and the app-specific `tblAssetAudit` + `AssetRegister::audit()` choke-point (l.6420). Assets built its own because it needed **secret redaction** (`licenseKey`/`publicToken`), **public/kiosk actor types**, and no-FK durability for anonymous scans. **None of those forces apply to Venues**: no secrets, no public/anonymous mutation surface (all venue mutations are logged-in admin/leader actions or system cron), standard create/update/delete verbs. Reuse wins: zero new tables, the existing `/admin` audit browser shows venue changes for free, API-key source attribution comes along automatically. Contract for stage 02: a single `Venues::audit()` app-level choke-point wraps `Logger::audit()` so every mutation funnels through one call site; **bulk generation writes ONE row** (`tableName='tblVenueBookingGroups'`, `action='create'`, `newData` carrying `{dates:[…], count:N, params:{…}}`) instead of N rows. Import commit likewise: one row per batch (`tblVenueImportBatches`).

---

## 2. Entity-relationship overview

```
tblSites ─┬─< tblVenueStatuses                    (per-SITE status vocabulary, decision #4)
          │
          └─< tblVenues >── tblAssetOrgs          (landlordOrgID, reuse §1.1)
                 │
                 ├─< tblVenueRooms                (optional sub-spaces)
                 ├─< tblVenueUsageTypes ─< tblVenueUsageTypeWindows   (effective-dated default TIMES)
                 ├─< tblVenueAgreements ─< tblVenueAgreementFiles     (standing/ad-hoc hire contracts)
                 ├─< tblVenueBookingGroups        (multi-day runs + recurring generation batches)
                 │
                 └─< tblVenueBookings >── tblVenueUsageTypes
                          │        >── tblVenueStatuses
                          │        >── tblVenueRooms?  tblVenueBookingGroups?  tblVenueAgreements?  tblEvents?
                          │
                          └──< tblVenueInvoiceLines >── tblVenueInvoices >─ tblVenueAgreements?
                                                              │
                                                              └─< tblVenueInvoicePayments >── tblPayment?  ('venue-hire' purpose)
tblVenueImportBatches ─< tblVenueImportRows ··> (mapped usageType/status, importedBookingID)
tblVenueReminderLog        (no FKs by design — single-shot dedupe)
tblAuditTrail              (reused — no venue audit table)
```

Rationale for the two vocabulary scopes (brief allows either): **usage types are per-VENUE** — a default time window ("Regular Hours = 09:30–13:30") is a property of one building's hire arrangement; a site renting two buildings has two different "Regular Hours". **Statuses are per-SITE** — the approval lifecycle (leadership sign-off → landlord agreement) is an organisational process applied identically to every venue the site rents. Both tables still carry `siteID` (multi-site scoping rule applies to every query).

Cross-tenant integrity note: MySQL FKs here are single-column (house style — the schema never uses composite FKs), so "booking.statusID belongs to the booking's site" and "booking.usageTypeID belongs to the booking's venue" are **PHP-enforced invariants** (every save handler validates the referenced row's siteID/venueID). Same pre-existing situation as e.g. `tblEvents.categoryID`.

---

## 3. Table-by-table DDL (build-ready)

> All blocks: plain `CREATE TABLE IF NOT EXISTS`, InnoDB, utf8mb4/utf8mb4_general_ci. The ONLY guarded ALTER in the whole design is §3.16 (`tblPayment.purpose`). Issue number placeholder `#VEN` — stage 03 substitutes the real GitHub issue number.

### 3.1 `tblVenues` — a rented external building

```sql
CREATE TABLE IF NOT EXISTS `tblVenues` (
    `venueID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`         INT          NOT NULL DEFAULT 1,
    `venueName`      VARCHAR(255) NOT NULL,
    `landlordOrgID`  INT          DEFAULT NULL COMMENT 'FK → tblAssetOrgs — the landlord organisation (reused external-org registry, see 01-data-model §1.1)',
    `addressLine1`   VARCHAR(255) DEFAULT NULL,
    `addressLine2`   VARCHAR(255) DEFAULT NULL,
    `city`           VARCHAR(100) DEFAULT NULL,
    `region`         VARCHAR(100) DEFAULT NULL COMMENT 'County / state / province',
    `postcode`       VARCHAR(20)  DEFAULT NULL,
    `countryCode`    CHAR(2)      NOT NULL DEFAULT 'GB' COMMENT 'ISO 3166-1 alpha-2',
    `timezone`       VARCHAR(64)  NOT NULL DEFAULT 'Europe/London' COMMENT 'IANA timezone — booking dates/times are wall-clock LOCAL to the venue (§8.4); mirrors tblEvents.eventTimezone style',
    `caretakerName`  VARCHAR(150) DEFAULT NULL COMMENT 'On-site contact for access/keys, when different from the landlord org contact',
    `caretakerPhone` VARCHAR(50)  DEFAULT NULL,
    `notes`          TEXT         DEFAULT NULL COMMENT 'Access arrangements, parking, alarm codes go in a vault doc NOT here — free operational notes only',
    `isActive`       TINYINT(1)   NOT NULL DEFAULT 1,
    `createdByID`    INT          NOT NULL,
    `createdAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`venueID`),
    KEY `idx_ven_site_active` (`siteID`, `isActive`),
    KEY `idx_ven_landlord` (`landlordOrgID`),
    CONSTRAINT `fk_ven_site`     FOREIGN KEY (`siteID`)        REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_ven_landlord` FOREIGN KEY (`landlordOrgID`) REFERENCES `tblAssetOrgs`(`orgID`) ON DELETE SET NULL,
    CONSTRAINT `fk_ven_creator`  FOREIGN KEY (`createdByID`)   REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — rented external buildings (tenant-side register) (#VEN)';
```

Notes: SDA/Sabbath context is intentionally absent from the schema — the weekly cadence lives in the generator's `daysOfWeek`, so any denomination/weekday works. No slug (internal app, ID-addressed). Venue deletion is soft (`isActive=0`) in the UI; hard delete cascades children (see child FKs) and is admin-only with a PHP guard when bookings/invoices exist.

### 3.2 `tblVenueRooms` — optional sub-spaces

```sql
CREATE TABLE IF NOT EXISTS `tblVenueRooms` (
    `roomID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `venueID`     INT          NOT NULL,
    `roomName`    VARCHAR(150) NOT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `capacity`    INT          DEFAULT NULL COMMENT 'Seats/people; NULL = unspecified',
    `sortOrder`   INT          NOT NULL DEFAULT 0,
    `isActive`    TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`roomID`),
    UNIQUE KEY `uq_venrm_venue_name` (`venueID`, `roomName`),
    KEY `idx_venrm_site` (`siteID`),
    CONSTRAINT `fk_venrm_site`  FOREIGN KEY (`siteID`)  REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_venrm_venue` FOREIGN KEY (`venueID`) REFERENCES `tblVenues`(`venueID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — optional sub-spaces/rooms of a venue (#VEN)';
```

**Single-space degradation:** a venue with zero `tblVenueRooms` rows is the normal case (Mill Road). `tblVenueBookings.roomID = NULL` means "the whole venue"; the booking UI hides the room picker when the venue has no active rooms; the conflict engine treats `roomID IS NULL` as covering every room. Nothing else changes — single-space venues never touch this table.

### 3.3 `tblVenueUsageTypes` — the configurable "HOURS" vocabulary (per venue)

```sql
CREATE TABLE IF NOT EXISTS `tblVenueUsageTypes` (
    `usageTypeID` INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `venueID`     INT          NOT NULL COMMENT 'Usage types are per-venue — default windows are properties of one building''s hire arrangement (§2)',
    `typeName`    VARCHAR(100) NOT NULL COMMENT 'e.g. Regular Hours / Extended / Extended (All Day) / Custom / Closed - Not Needed / Building Unavailable',
    `usageKind`   ENUM('hire','closed','unavailable') NOT NULL DEFAULT 'hire'
                  COMMENT 'hire = a real hire of the building; closed = we chose not to use it (Closed/Not Needed); unavailable = the landlord/world blocked it (Building Unavailable, road closure). closed vs unavailable render DISTINCTLY on the calendar (brief: two orthogonal non-booking states)',
    `isBookable`  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Convenience flag driving the conflict engine: 1 iff usageKind=hire. Kept in sync by the PHP save choke-point; queries filter on this, display groups on usageKind',
    `sortOrder`   INT          NOT NULL DEFAULT 0,
    `isActive`    TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`usageTypeID`),
    UNIQUE KEY `uq_venut_venue_name` (`venueID`, `typeName`),
    KEY `idx_venut_site` (`siteID`),
    CONSTRAINT `fk_venut_site`  FOREIGN KEY (`siteID`)  REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_venut_venue` FOREIGN KEY (`venueID`) REFERENCES `tblVenues`(`venueID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — per-venue usage-type ("HOURS") vocabulary (#VEN)';
```

`utf8mb4_general_ci` collation makes the importer's `typeName` matching case-insensitive in SQL for free. "Times required" needs no column: a `usageKind='hire'` type whose current window (§3.4) has NULL times (the `Custom` type) simply forces manual time entry in the UI/import (`<enter times>` semantics).

### 3.4 `tblVenueUsageTypeWindows` — EFFECTIVE-DATED default time windows

The real data's defaults change per schedule year (2026 shows Regular → 09:30–16:00). Effective-dating is strictly more general than per-year rows and needs no "year" concept:

```sql
CREATE TABLE IF NOT EXISTS `tblVenueUsageTypeWindows` (
    `windowID`         INT      NOT NULL AUTO_INCREMENT,
    `siteID`           INT      NOT NULL DEFAULT 1,
    `usageTypeID`      INT      NOT NULL,
    `effectiveFrom`    DATE     NOT NULL COMMENT 'This window applies to bookingDate >= effectiveFrom, until a row with a later effectiveFrom supersedes it. Seed rows use 1970-01-01 (= always). A per-year default is simply effectiveFrom = Jan 1 of that year',
    `defaultStartTime` TIME     DEFAULT NULL COMMENT 'NULL together with defaultEndTime = no default window (Custom => times must be entered; closed/unavailable kinds => no times at all)',
    `defaultEndTime`   TIME     DEFAULT NULL,
    `note`             VARCHAR(255) DEFAULT NULL COMMENT 'e.g. "2026 revised hire terms"',
    `createdByID`      INT      DEFAULT NULL,
    `createdAt`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`windowID`),
    UNIQUE KEY `uq_venutw_type_from` (`usageTypeID`, `effectiveFrom`),
    KEY `idx_venutw_site` (`siteID`),
    CONSTRAINT `fk_venutw_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_venutw_type`    FOREIGN KEY (`usageTypeID`) REFERENCES `tblVenueUsageTypes`(`usageTypeID`) ON DELETE CASCADE,
    CONSTRAINT `fk_venutw_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — effective-dated default TIMES per usage type (defaults change per schedule year) (#VEN)';
```

**Resolution rule (the one query stage 02 must implement exactly):** the default window for usage type U on date D = the row with `usageTypeID=U AND effectiveFrom <= D` having the greatest `effectiveFrom` (`ORDER BY effectiveFrom DESC LIMIT 1`). No row ⇒ no default (times manual). Changing a default **never rewrites existing bookings** — resolved times are denormalised onto the booking row at creation (§3.7); a "re-apply new defaults to future rows where timesOverridden=0" admin tool is a stage-02 feature the data already supports.

### 3.5 `tblVenueStatuses` — the configurable status vocabulary (per site; decision #4)

```sql
CREATE TABLE IF NOT EXISTS `tblVenueStatuses` (
    `statusID`          INT          NOT NULL AUTO_INCREMENT,
    `siteID`            INT          NOT NULL DEFAULT 1,
    `statusName`        VARCHAR(150) NOT NULL,
    `statusCategory`    ENUM('proposed','agreed','rejected','standing','unavailable') NOT NULL DEFAULT 'proposed'
                        COMMENT 'Coarse grouping for filters/reports/colour fallback. standing = blanket/standing agreement; unavailable = a site that prefers modelling unavailability as a status (seeds do not use it — usageKind covers it)',
    `countsAsConfirmed` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'THE flag (decision #4): 1 = the hire is agreed/secured — calendar overlay shows it as booked and the "is it booked?" engine treats the slot as safe to plan against',
    `isAvailable`       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = this status means the slot is known NOT usable by us (rejections, unavailability); 1 = usable or potentially usable (proposed/agreed/standing)',
    `color`             VARCHAR(9)   DEFAULT NULL COMMENT 'Hex colour (#RRGGBB or #RRGGBBAA) for the calendar overlay; NULL = derive from statusCategory (mirrors tblEventCategories.color)',
    `sortOrder`         INT          NOT NULL DEFAULT 0,
    `isActive`          TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`statusID`),
    UNIQUE KEY `uq_venst_site_name` (`siteID`, `statusName`),
    CONSTRAINT `fk_venst_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — per-site configurable booking-status vocabulary with countsAsConfirmed/isAvailable flags (decision #4) (#VEN)';
```

**Seed vocabulary** (per-site, PHP-seeded — see §4) generalised from the church's BackingData:

| statusName | statusCategory | countsAsConfirmed | isAvailable | sortOrder |
|---|---|---|---|---|
| Standard Agreement | standing | 1 | 1 | 10 |
| Pending Leadership Agreement | proposed | 0 | 1 | 20 |
| Proposed to Landlord | proposed | 0 | 1 | 30 |
| Agreed by Landlord | agreed | 1 | 1 | 40 |
| Rejected by Landlord | rejected | 0 | 0 | 50 |
| Rejected – building already in use | rejected | 0 | 0 | 60 |

Deleting an in-use status is blocked by the booking FK (RESTRICT); the UI retires with `isActive=0`. The two-sided approval (leadership + landlord) stays encoded purely as vocabulary + flags — no workflow table, per decision #4.

### 3.6 `tblVenueBookingGroups` — multi-day runs & recurring generation batches

```sql
CREATE TABLE IF NOT EXISTS `tblVenueBookingGroups` (
    `groupID`     INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `venueID`     INT          NOT NULL,
    `groupType`   ENUM('multi-day','recurring') NOT NULL,
    `label`       VARCHAR(150) DEFAULT NULL COMMENT 'e.g. "VBS week 2025", "2026 weekly worship"',
    `frequency`   ENUM('weekly','fortnightly','monthly','custom') DEFAULT NULL COMMENT 'recurring groups only; NULL for multi-day',
    `intervalVal` INT          DEFAULT NULL COMMENT 'e.g. every 2 weeks (mirrors tblRecurrenceRules.intervalVal)',
    `daysOfWeek`  VARCHAR(20)  DEFAULT NULL COMMENT 'CSV of days 0=Sun..6=Sat — same encoding as tblRecurrenceRules.dayOfWeek',
    `dateFrom`    DATE         DEFAULT NULL COMMENT 'Generation range start (recurring) / run start (multi-day)',
    `dateTo`      DATE         DEFAULT NULL COMMENT 'Generation range end / run end — "extend series" re-runs the generator from dateTo+1',
    `createdByID` INT          NOT NULL,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`groupID`),
    KEY `idx_vengrp_site` (`siteID`),
    KEY `idx_vengrp_venue` (`venueID`),
    CONSTRAINT `fk_vengrp_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_vengrp_venue`   FOREIGN KEY (`venueID`)     REFERENCES `tblVenues`(`venueID`) ON DELETE CASCADE,
    CONSTRAINT `fk_vengrp_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — groups linking per-date booking rows: multi-day runs + recurring generation batches (§1.2/§1.3) (#VEN)';
```

A separate table (rather than a self-FK to an "anchor" booking) avoids anchor-deletion ambiguity and gives the generator a natural audit target (§1.5).

### 3.7 `tblVenueBookings` — the core per-date booking rows

```sql
CREATE TABLE IF NOT EXISTS `tblVenueBookings` (
    `bookingID`       INT        NOT NULL AUTO_INCREMENT,
    `siteID`          INT        NOT NULL DEFAULT 1,
    `venueID`         INT        NOT NULL,
    `roomID`          INT        DEFAULT NULL COMMENT 'NULL = the whole venue (single-space venues always NULL, §3.2)',
    `groupID`         INT        DEFAULT NULL COMMENT 'FK → tblVenueBookingGroups; NULL = standalone single-day booking',
    `bookingDate`     DATE       NOT NULL COMMENT 'Venue-LOCAL calendar date (one row per day, §1.2)',
    `usageTypeID`     INT        NOT NULL COMMENT 'The HOURS vocabulary entry; PHP validates it belongs to venueID',
    `startTime`       TIME       DEFAULT NULL COMMENT 'Resolved venue-local start — copied from the usage type''s effective default at save time, overridable. NULL for closed/unavailable kinds and for hire rows still awaiting times',
    `endTime`         TIME       DEFAULT NULL COMMENT 'Resolved venue-local end; PHP enforces endTime > startTime (no cross-midnight rows in v1, see Open Questions)',
    `timesOverridden` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = times were manually set (differ from the usage-type default at save time); lets a "re-apply changed defaults" tool skip manual rows (§3.4)',
    `statusID`        INT        NOT NULL COMMENT 'Per-site status vocabulary row; PHP validates same siteID',
    `notes`           TEXT       DEFAULT NULL COMMENT 'The programme — free text (Communion Service, Fellowship Lunch, VBS, …). This is the human link to what happens that day',
    `eventID`         INT        DEFAULT NULL COMMENT 'Optional link to the calendar event this hire hosts (mirrors tblServicePlan.eventID)',
    `agreementID`     INT        DEFAULT NULL COMMENT 'Which hire agreement covers this booking; NULL = none recorded (cost may still be set directly)',
    `costPence`       INT        DEFAULT NULL COMMENT 'Integer minor units — house pence convention (#266). NULL = not costed / derive from agreement rate',
    `currency`        CHAR(3)    NOT NULL DEFAULT 'GBP' COMMENT 'ISO 4217',
    `isDeleted`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete — cancellations are normally a STATUS change; this is for true mistakes',
    `createdByID`     INT        NOT NULL,
    `updatedByID`     INT        DEFAULT NULL,
    `createdAt`       DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`       DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`bookingID`),
    KEY `idx_venbk_site_date`  (`siteID`, `bookingDate`),
    KEY `idx_venbk_venue_date` (`venueID`, `bookingDate`),
    KEY `idx_venbk_status`     (`statusID`),
    KEY `idx_venbk_usagetype`  (`usageTypeID`),
    KEY `idx_venbk_group`      (`groupID`),
    KEY `idx_venbk_room`       (`roomID`),
    KEY `idx_venbk_event`      (`eventID`),
    KEY `idx_venbk_agreement`  (`agreementID`),
    CONSTRAINT `fk_venbk_site`      FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_venbk_venue`     FOREIGN KEY (`venueID`)     REFERENCES `tblVenues`(`venueID`) ON DELETE CASCADE,
    CONSTRAINT `fk_venbk_room`      FOREIGN KEY (`roomID`)      REFERENCES `tblVenueRooms`(`roomID`) ON DELETE SET NULL,
    CONSTRAINT `fk_venbk_group`     FOREIGN KEY (`groupID`)     REFERENCES `tblVenueBookingGroups`(`groupID`) ON DELETE SET NULL,
    CONSTRAINT `fk_venbk_usagetype` FOREIGN KEY (`usageTypeID`) REFERENCES `tblVenueUsageTypes`(`usageTypeID`),
    CONSTRAINT `fk_venbk_status`    FOREIGN KEY (`statusID`)    REFERENCES `tblVenueStatuses`(`statusID`),
    CONSTRAINT `fk_venbk_event`     FOREIGN KEY (`eventID`)     REFERENCES `tblEvents`(`eventID`) ON DELETE SET NULL,
    CONSTRAINT `fk_venbk_agreement` FOREIGN KEY (`agreementID`) REFERENCES `tblVenueAgreements`(`agreementID`) ON DELETE SET NULL,
    CONSTRAINT `fk_venbk_creator`   FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_venbk_updater`   FOREIGN KEY (`updatedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — one row per venue per date: the hire schedule (§1.2) (#VEN)';
```

Key design points:
- **Times are denormalised** (resolved at save from §3.4) so the overlay/conflict queries never join the windows table; `timesOverridden` preserves the ability to re-apply changed defaults.
- **No unique key on `(venueID, bookingDate)`** — two rooms or morning+evening hires on one day are legal. The generator's duplicate guard is PHP-level (§1.3).
- `usageTypeID`/`statusID` FKs deliberately have **no ON DELETE clause (= RESTRICT)**: vocabulary rows in use cannot vanish; UIs retire them with `isActive=0`.
- Room deletion uses SET NULL (not RESTRICT) so a venue-delete cascade can never dead-lock between the two child FKs; the rooms UI must PHP-guard deletion while future bookings reference the room.

### 3.8 `tblVenueAgreements` — standing/blanket + ad-hoc hire agreements

```sql
CREATE TABLE IF NOT EXISTS `tblVenueAgreements` (
    `agreementID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`           INT          NOT NULL DEFAULT 1,
    `venueID`          INT          NOT NULL,
    `agreementType`    ENUM('standing','ad-hoc') NOT NULL DEFAULT 'standing'
                       COMMENT 'standing = blanket recurring hire (the "Standard Agreement"); ad-hoc = one-off hire contract (e.g. the VBS week letter)',
    `title`            VARCHAR(255) NOT NULL,
    `reference`        VARCHAR(100) DEFAULT NULL COMMENT 'Landlord''s agreement reference (pairs with tblAssetOrgs.agreementRef naming)',
    `termStart`        DATE         DEFAULT NULL,
    `termEnd`          DATE         DEFAULT NULL COMMENT 'NULL = open-ended/rolling',
    `renewalDate`      DATE         DEFAULT NULL COMMENT 'Next renewal/review due date — feeds the reminder sweep (§9)',
    `noticePeriodDays` INT          DEFAULT NULL COMMENT 'Contractual notice period; reminder sweep warns noticePeriodDays before termEnd too',
    `rateAmountPence`  INT          DEFAULT NULL COMMENT 'Integer minor units — house pence convention (#266)',
    `rateUnit`         ENUM('per-booking','per-hour','per-day','per-week','per-month','per-quarter','per-year','fixed-total') DEFAULT NULL,
    `currency`         CHAR(3)      NOT NULL DEFAULT 'GBP',
    `status`           ENUM('draft','active','expired','terminated','superseded') NOT NULL DEFAULT 'draft',
    `supersededByID`   INT          DEFAULT NULL COMMENT 'Self-FK — renewal chain (mirrors tblAssetLoans.parentLoanID pattern)',
    `notes`            TEXT         DEFAULT NULL,
    `createdByID`      INT          NOT NULL,
    `createdAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`agreementID`),
    KEY `idx_venagr_site_status`  (`siteID`, `status`),
    KEY `idx_venagr_venue_status` (`venueID`, `status`),
    KEY `idx_venagr_renewal`      (`siteID`, `renewalDate`),
    KEY `idx_venagr_termend`      (`siteID`, `termEnd`),
    KEY `idx_venagr_superseded`   (`supersededByID`),
    CONSTRAINT `fk_venagr_site`       FOREIGN KEY (`siteID`)         REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_venagr_venue`      FOREIGN KEY (`venueID`)        REFERENCES `tblVenues`(`venueID`) ON DELETE CASCADE,
    CONSTRAINT `fk_venagr_superseded` FOREIGN KEY (`supersededByID`) REFERENCES `tblVenueAgreements`(`agreementID`) ON DELETE SET NULL,
    CONSTRAINT `fk_venagr_creator`    FOREIGN KEY (`createdByID`)    REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — hire agreements: standing/blanket + ad-hoc, with term/rate/renewal (§1.1) (#VEN)';
```

### 3.9 `tblVenueAgreementFiles` — agreement document attachments

Field shape mirrors `tblAssetResources` (l.6310) so upload/serve code is congruent; storage under `_uploads/venues/agreements/`.

```sql
CREATE TABLE IF NOT EXISTS `tblVenueAgreementFiles` (
    `fileID`       INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `agreementID`  INT          NOT NULL,
    `title`        VARCHAR(255) NOT NULL,
    `fileName`     VARCHAR(255) NOT NULL COMMENT 'Original upload filename',
    `filePath`     VARCHAR(255) NOT NULL COMMENT 'Path relative to _uploads/venues/agreements/',
    `fileSize`     INT          DEFAULT NULL COMMENT 'Bytes',
    `mimeType`     VARCHAR(100) DEFAULT NULL,
    `uploadedByID` INT          NOT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`fileID`),
    KEY `idx_venagf_site` (`siteID`),
    KEY `idx_venagf_agreement` (`agreementID`),
    CONSTRAINT `fk_venagf_site`      FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_venagf_agreement` FOREIGN KEY (`agreementID`)  REFERENCES `tblVenueAgreements`(`agreementID`) ON DELETE CASCADE,
    CONSTRAINT `fk_venagf_uploader`  FOREIGN KEY (`uploadedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — signed agreements/schedules/insurance certs attached to a hire agreement (#VEN)';
```

### 3.10 `tblVenueInvoices` — payable invoices from the landlord

```sql
CREATE TABLE IF NOT EXISTS `tblVenueInvoices` (
    `invoiceID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `venueID`     INT          NOT NULL,
    `agreementID` INT          DEFAULT NULL COMMENT 'Agreement this invoice bills under, when known',
    `invoiceRef`  VARCHAR(100) DEFAULT NULL COMMENT 'Landlord''s invoice number (free text — not unique: refs repeat/absent in the wild)',
    `description` VARCHAR(500) DEFAULT NULL,
    `periodStart` DATE         DEFAULT NULL COMMENT 'Billing period covered (e.g. a month of Saturdays)',
    `periodEnd`   DATE         DEFAULT NULL,
    `issueDate`   DATE         NOT NULL,
    `dueDate`     DATE         DEFAULT NULL COMMENT 'Feeds the payment-due reminder sweep (§9)',
    `amountPence` INT          NOT NULL COMMENT 'Integer minor units — house pence convention (#266)',
    `currency`    CHAR(3)      NOT NULL DEFAULT 'GBP',
    `status`      ENUM('pending','part-paid','paid','disputed','cancelled') NOT NULL DEFAULT 'pending'
                  COMMENT 'Maintained by the PHP choke-point from SUM(tblVenueInvoicePayments.amountPence) vs amountPence',
    `paidAt`      DATETIME     DEFAULT NULL COMMENT 'Stamped when status flips to paid',
    `fileName`    VARCHAR(255) DEFAULT NULL COMMENT 'Scanned/PDF invoice — single attachment inline (invoices are one document)',
    `filePath`    VARCHAR(255) DEFAULT NULL COMMENT 'Path relative to _uploads/venues/invoices/',
    `fileSize`    INT          DEFAULT NULL,
    `mimeType`    VARCHAR(100) DEFAULT NULL,
    `notes`       TEXT         DEFAULT NULL,
    `createdByID` INT          NOT NULL,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`invoiceID`),
    KEY `idx_veninv_site_status_due` (`siteID`, `status`, `dueDate`),
    KEY `idx_veninv_venue` (`venueID`),
    KEY `idx_veninv_agreement` (`agreementID`),
    CONSTRAINT `fk_veninv_site`      FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_veninv_venue`     FOREIGN KEY (`venueID`)     REFERENCES `tblVenues`(`venueID`) ON DELETE CASCADE,
    CONSTRAINT `fk_veninv_agreement` FOREIGN KEY (`agreementID`) REFERENCES `tblVenueAgreements`(`agreementID`) ON DELETE SET NULL,
    CONSTRAINT `fk_veninv_creator`   FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — payable hire invoices received from the landlord (§1.4) (#VEN)';
```

### 3.11 `tblVenueInvoiceLines` — which bookings an invoice covers

```sql
CREATE TABLE IF NOT EXISTS `tblVenueInvoiceLines` (
    `lineID`      INT      NOT NULL AUTO_INCREMENT,
    `siteID`      INT      NOT NULL DEFAULT 1,
    `invoiceID`   INT      NOT NULL,
    `bookingID`   INT      NOT NULL,
    `amountPence` INT      DEFAULT NULL COMMENT 'Portion of the invoice attributable to this booking; NULL = unallocated (invoice total stands alone)',
    `createdAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`lineID`),
    UNIQUE KEY `uq_venil_invoice_booking` (`invoiceID`, `bookingID`),
    KEY `idx_venil_site` (`siteID`),
    KEY `idx_venil_booking` (`bookingID`),
    CONSTRAINT `fk_venil_site`    FOREIGN KEY (`siteID`)    REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_venil_invoice` FOREIGN KEY (`invoiceID`) REFERENCES `tblVenueInvoices`(`invoiceID`) ON DELETE CASCADE,
    CONSTRAINT `fk_venil_booking` FOREIGN KEY (`bookingID`) REFERENCES `tblVenueBookings`(`bookingID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — invoice↔booking allocation (a monthly invoice covers several Saturdays) (#VEN)';
```

This answers "cost per booking AND per agreement": per-booking cost = `tblVenueBookings.costPence` (or its share of an invoice via lines); per-agreement cost = the agreement's `rateAmountPence`/`rateUnit` plus the invoices billed under it.

### 3.12 `tblVenueInvoicePayments` — money-out records (+ optional portal-rail bridge)

Modelled on `tblExpenseClaimPayments` (partial payments) but with pence-integer money and the `tblPayment` bridge:

```sql
CREATE TABLE IF NOT EXISTS `tblVenueInvoicePayments` (
    `payID`           INT          NOT NULL AUTO_INCREMENT,
    `siteID`          INT          NOT NULL DEFAULT 1,
    `invoiceID`       INT          NOT NULL,
    `paidDate`        DATE         NOT NULL,
    `amountPence`     INT          NOT NULL COMMENT 'Integer minor units — house pence convention (#266); partial payments allowed',
    `method`          ENUM('bank-transfer','standing-order','cash','cheque','card','online','other') NOT NULL DEFAULT 'bank-transfer',
    `reference`       VARCHAR(100) DEFAULT NULL COMMENT 'Bank ref / cheque number',
    `portalPaymentID` INT          DEFAULT NULL COMMENT 'FK → tblPayment — set ONLY when the money moved through the portal payment rails (tblPayment.purpose=venue-hire, §3.16); NULL for all offline payments',
    `notes`           VARCHAR(500) DEFAULT NULL,
    `recordedByID`    INT          NOT NULL,
    `createdAt`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`payID`),
    KEY `idx_venip_invoice` (`invoiceID`),
    KEY `idx_venip_site_date` (`siteID`, `paidDate`),
    KEY `idx_venip_portal` (`portalPaymentID`),
    CONSTRAINT `fk_venip_site`     FOREIGN KEY (`siteID`)          REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_venip_invoice`  FOREIGN KEY (`invoiceID`)       REFERENCES `tblVenueInvoices`(`invoiceID`) ON DELETE CASCADE,
    CONSTRAINT `fk_venip_portal`   FOREIGN KEY (`portalPaymentID`) REFERENCES `tblPayment`(`paymentID`) ON DELETE SET NULL,
    CONSTRAINT `fk_venip_recorder` FOREIGN KEY (`recordedByID`)    REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — payments recorded against hire invoices; optional bridge to tblPayment (§1.4) (#VEN)';
```

### 3.13 `tblVenueImportBatches` — Excel/CSV import wizard staging (batch)

Mirrors the `tblBankImports` staging precedent (migration 152) with wizard state added:

```sql
CREATE TABLE IF NOT EXISTS `tblVenueImportBatches` (
    `batchID`       INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `venueID`       INT          NOT NULL,
    `fileName`      VARCHAR(255) NOT NULL COMMENT 'Original client filename (basename only, display)',
    `fileHash`      CHAR(64)     NOT NULL COMMENT 'SHA-256 of raw upload bytes — duplicate-import warning (PHP-level soft guard, NOT unique: an abandoned batch may be legitimately re-uploaded)',
    `sourceKind`    ENUM('xlsx','csv') NOT NULL DEFAULT 'xlsx',
    `status`        ENUM('uploaded','mapping','committed','abandoned') NOT NULL DEFAULT 'uploaded',
    `sheetCount`    INT          NOT NULL DEFAULT 0,
    `rowCount`      INT          NOT NULL DEFAULT 0 COMMENT 'Data rows staged (immutable parse snapshot)',
    `importedCount` INT          NOT NULL DEFAULT 0,
    `skippedCount`  INT          NOT NULL DEFAULT 0,
    `vocabMap`      JSON         DEFAULT NULL COMMENT 'Wizard state: {"hours":{"<raw>":{"usageTypeID":n|"create":{...}}},"status":{"<raw>":{"statusID":n|"create":{...}}},"backingData":{...}} — unknown vocab surfaced here for map-or-create (§11.6)',
    `createdByID`   INT          DEFAULT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `committedAt`   DATETIME     DEFAULT NULL,
    PRIMARY KEY (`batchID`),
    KEY `idx_venimb_site_status` (`siteID`, `status`),
    KEY `idx_venimb_venue` (`venueID`),
    KEY `idx_venimb_hash` (`siteID`, `fileHash`),
    CONSTRAINT `fk_venimb_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_venimb_venue`   FOREIGN KEY (`venueID`)     REFERENCES `tblVenues`(`venueID`) ON DELETE CASCADE,
    CONSTRAINT `fk_venimb_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — Excel/CSV import wizard batches (staging precedent: tblBankImports) (#VEN)';
```

### 3.14 `tblVenueImportRows` — staged rows (raw + parsed + mapping state)

```sql
CREATE TABLE IF NOT EXISTS `tblVenueImportRows` (
    `rowID`             INT          NOT NULL AUTO_INCREMENT,
    `batchID`           INT          NOT NULL,
    `siteID`            INT          NOT NULL DEFAULT 1,
    `sheetName`         VARCHAR(100) DEFAULT NULL,
    `sheetYear`         INT          DEFAULT NULL COMMENT 'Parsed 4-digit year from the sheet name (one sheet per year); NULL for CSV',
    `rowNum`            INT          NOT NULL COMMENT 'Source row number within the sheet (1-based, for error reporting)',
    `rawDate`           VARCHAR(50)  DEFAULT NULL COMMENT 'Cell as found: Excel serial or text',
    `parsedDate`        DATE         DEFAULT NULL,
    `rawHours`          VARCHAR(150) DEFAULT NULL,
    `rawTimes`          VARCHAR(100) DEFAULT NULL,
    `rawStatus`         VARCHAR(150) DEFAULT NULL,
    `rawNotes`          TEXT         DEFAULT NULL,
    `parsedStartTime`   TIME         DEFAULT NULL,
    `parsedEndTime`     TIME         DEFAULT NULL,
    `mappedUsageTypeID` INT          DEFAULT NULL,
    `mappedStatusID`    INT          DEFAULT NULL,
    `rowState`          ENUM('pending','ready','imported','skipped','error') NOT NULL DEFAULT 'pending',
    `stateNote`         VARCHAR(255) DEFAULT NULL COMMENT 'e.g. "times missing", "unmapped STATUS", "implausible date (1904 system?)", "sheet-year mismatch"',
    `importedBookingID` INT          DEFAULT NULL COMMENT 'Traceability: the tblVenueBookings row this staged row became',
    `createdAt`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`rowID`),
    KEY `idx_venimr_batch_state` (`batchID`, `rowState`),
    KEY `idx_venimr_site` (`siteID`),
    KEY `idx_venimr_booking` (`importedBookingID`),
    CONSTRAINT `fk_venimr_batch`     FOREIGN KEY (`batchID`)           REFERENCES `tblVenueImportBatches`(`batchID`) ON DELETE CASCADE,
    CONSTRAINT `fk_venimr_site`      FOREIGN KEY (`siteID`)            REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_venimr_usagetype` FOREIGN KEY (`mappedUsageTypeID`) REFERENCES `tblVenueUsageTypes`(`usageTypeID`) ON DELETE SET NULL,
    CONSTRAINT `fk_venimr_status`    FOREIGN KEY (`mappedStatusID`)    REFERENCES `tblVenueStatuses`(`statusID`) ON DELETE SET NULL,
    CONSTRAINT `fk_venimr_booking`   FOREIGN KEY (`importedBookingID`) REFERENCES `tblVenueBookings`(`bookingID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — staged import rows: raw cells + parse results + mapping state (#VEN)';
```

### 3.15 `tblVenueReminderLog` — reminder single-shot dedupe (no FKs by design)

Mirrors `tblAssetReminderLog` (l.6489) exactly in shape and philosophy:

```sql
CREATE TABLE IF NOT EXISTS `tblVenueReminderLog` (
    `logID`          INT      NOT NULL AUTO_INCREMENT,
    `siteID`         INT      NOT NULL DEFAULT 1,
    `refType`        ENUM('booking-unagreed','agreement-renewal','invoice-due') NOT NULL
                     COMMENT 'Which due-date family this reminder was about (§9)',
    `refID`          INT      NOT NULL COMMENT 'No FK by design (log must survive row deletion — see tblAssetReminderLog). bookingID / agreementID / invoiceID depending on refType',
    `dueDate`        DATE     NOT NULL COMMENT 'bookingDate / renewalDate-or-termEnd / invoice dueDate — part of the dedupe key so a re-scheduled date re-reminds',
    `recipientCount` INT      NOT NULL DEFAULT 0,
    `sentAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`logID`),
    UNIQUE KEY `uq_venrl_ref` (`refType`, `refID`, `dueDate`),
    KEY `idx_venrl_site` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — reminder single-shot dedupe log, no FKs by design (#VEN)';
```

### 3.16 GUARDED ALTER (the only one): `tblPayment.purpose` gains `'venue-hire'`

Current definition (full_schema l.4096): `purpose ENUM('giving','pledge','membership','other') NOT NULL DEFAULT 'other'`. The migration must use the **information_schema + PREPARE/EXECUTE guard idiom** (DEV_NOTES "Portable DDL convention"; house examples: migrations 037/112/138) — check `information_schema.COLUMNS.COLUMN_TYPE` for the absence of `'venue-hire'` before running `ALTER TABLE tblPayment MODIFY COLUMN purpose ENUM('giving','pledge','membership','other','venue-hire') NOT NULL DEFAULT 'other'`. Appending a member to the END of an ENUM is a metadata-only, replay-safe change. In `full_schema.sql` the fold edits the `tblPayment` CREATE TABLE inline (house fold convention). `purposeRef` for this purpose = string-encoded `invoiceID` (same convention as `'pledge'` → pledgeID). Companion code changes (stage 02): extend `Payments::PURPOSES` const and add a `'venue-hire'` branch in `Payments::markPaymentSucceeded()` that marks the invoice paid via the Venues choke-point (wrapped in the same `try/catch (\Throwable)` app-not-installed guard as the `'giving'`/`'pledge'` branches).

---

## 4. Seeding model (vocabularies, settings)

- **No per-site vocabulary rows are seeded in the migration.** House precedent: neither `tblAttendanceServiceTypes` nor `tblGivingCategory` has an `INSERT` seed anywhere in `web/_sql/` — per-site vocab is created PHP-side. Reason: migrations only know siteID 1; a multi-site install would silently miss sites 2+.
- **Statuses (§3.5 table):** seeded idempotently per site by a `Venues::seedStatuses(int $siteId)` helper, called on first access to any `/venues` page for that site (and safe to re-call — `INSERT` only names not already present for the site). Stage 02 wires the call site.
- **Usage types (§3.3/§3.4):** seeded per **venue** at venue creation: the six defaults (Regular Hours 09:30–13:30 / Extended 09:00–15:00 / Extended (All Day) 09:30–17:30 / Custom no-window / Closed - Not Needed `usageKind='closed'` / Building Unavailable `usageKind='unavailable'`), each with one `tblVenueUsageTypeWindows` row at `effectiveFrom='1970-01-01'` (hire kinds with windows; Custom and the two non-hire kinds with NULL times). The create-venue form lets the user tweak before saving.
- **Settings** (stage 02 finalises the list; migration seeds the siteID-NULL defaults per house pattern): `venues.enabled` ('0' — matches `resources.enabled`/`service_plans.enabled` opt-in precedent), `venues.displayName` ('Venue Bookings'), `venues.displayIcon` (suggest `fa-solid fa-building-columns`), `venues.currency` ('GBP'), `venues.unagreed_lead_days` ('21'), `venues.renewal_lead_days` ('60'), `venues.invoice_due_lead_days` ('7'), `venues.maxFileSize` ('10485760'), plus `api.venues.{action}.enabled` flags for whatever API endpoints stage 02 defines (ApiRouter trap: convention path `_apps/venues/api/{action}.php`, flags mandatory, nothing in tblRoutes).
- **AppRegistry:** `web/_core/apps/venues.php` (stage 02) — without it the app can't be toggled at `/admin/apps`, and per the #255 lesson the enable flag must be seeded or registration silently 403s.

---

## 5. Bookings lifecycle semantics (summary of the moving parts)

A booking row's meaning = `usageType.usageKind` × `status.countsAsConfirmed`:

| usageKind | countsAsConfirmed | Meaning | Calendar rendering |
|---|---|---|---|
| hire | 1 | Confirmed hire — safe to plan | solid venue-layer block with times |
| hire | 0 (category proposed/standing) | Proposed/pending hire | hatched/outline block, warning colour |
| hire | 0 (category rejected) | Rejected request | hidden by default; listed in schedule view |
| closed | (any; seed pairs it with Standard Agreement or any status) | We chose not to use the building | distinct "closed" marker |
| unavailable | (any) | Building cannot be had (landlord use, road closure) | distinct "unavailable" marker — strongest signal |

Cancellation is a status change (site can add a "Cancelled" status, category `rejected`, flags 0/0); `isDeleted` is only for data-entry mistakes.

---

## 6. Multi-day runs & the recurring generator (data contract)

Covered in §1.2/§1.3 + §3.6. Recap of exact fields each approach needed and which were chosen:

- Chosen (materialised): `tblVenueBookings.bookingDate` (one/day) + `groupID`; `tblVenueBookingGroups.groupType/frequency/intervalVal/daysOfWeek/dateFrom/dateTo`.
- Rejected (stored rule evaluated at query time) would have needed: rule row + per-occurrence exception table (status/times/notes overrides per date) + query-time expansion in every calendar/conflict/reminder read — strictly worse for this domain (near-100% divergence rate) and foreign to the house pattern (`tblRecurrenceRules` also materialises).
- VBS-style run: `groupType='multi-day'`, `dateFrom/dateTo` = run span, one row per day (days may differ in times/notes/status).
- Weekly worship: `groupType='recurring'`, `frequency='weekly'`, `daysOfWeek='6'` (Saturday; any CSV of days works — no SDA hardcoding), one generated row per matching date. Generator writes rows with `timesOverridden=0` and the usage-type default window in effect **for each generated date** (a January-generated 2027 series correctly picks up a 2027 window row).

---

## 7. Costing model

Three layers, cheapest-wins for display, none mandatory:
1. `tblVenueAgreements.rateAmountPence/rateUnit` — the contractual rate (per-booking/hour/day/…/fixed-total).
2. `tblVenueBookings.costPence` — per-booking override/actual (NULL = derive from agreement where linkable).
3. `tblVenueInvoices.amountPence` (+ optional per-booking allocation via `tblVenueInvoiceLines.amountPence`) — what was actually billed; `tblVenueInvoicePayments` — what was actually paid.
Reports (stage 02) reconcile expected (1/2) vs billed (3) vs paid.

---

## 8. Calendar overlay + conflict/"is it booked?" engine — exact data contract

### 8.1 Overlay layer query (what Calendar renders as the venue layer)

For site S, local date range [d1, d2], optionally one venue:

```sql
SELECT b.bookingID, b.venueID, v.venueName, b.roomID, r.roomName,
       b.bookingDate, b.startTime, b.endTime, b.notes, b.eventID,
       ut.typeName  AS usageTypeName, ut.usageKind, ut.isBookable,
       st.statusName, st.statusCategory, st.countsAsConfirmed, st.isAvailable, st.color
FROM tblVenueBookings b
JOIN tblVenues          v  ON v.venueID      = b.venueID
JOIN tblVenueUsageTypes ut ON ut.usageTypeID = b.usageTypeID
JOIN tblVenueStatuses   st ON st.statusID    = b.statusID
LEFT JOIN tblVenueRooms r  ON r.roomID       = b.roomID
WHERE b.siteID = ? AND b.isDeleted = 0
  AND b.bookingDate BETWEEN ? AND ?
  /* AND b.venueID = ? */
ORDER BY b.bookingDate, b.venueID, b.startTime
```

Served by index `idx_venbk_site_date` (or `idx_venbk_venue_date`). Rendering groups by the §5 matrix; rejected rows are excluded from the overlay by default (`NOT (ut.isBookable = 1 AND st.statusCategory = 'rejected')` client-side filter).

### 8.2 "Is it booked?" classification for one event

Inputs: event E with `startDateTime`/`endDateTime` (**stored UTC** — tblEvents l.751) and a target venue V. Convert E's UTC datetimes to **venue-local** wall clock using `tblVenues.timezone` (fallback `tblSites.timezone`, default Europe/London), yielding local date(s) + times. For each local date D the event touches, fetch that date's rows (8.1 restricted to `bookingDate = D AND venueID = V`) and classify, first match wins:

1. **UNAVAILABLE** — any row with `ut.usageKind='unavailable'` (regardless of status). If a confirmed hire ALSO exists that day, still UNAVAILABLE + a data-conflict flag for admins.
2. **CONFIRMED_COVERED** — a row with `st.countsAsConfirmed=1 AND ut.isBookable=1 AND b.startTime IS NOT NULL AND b.endTime IS NOT NULL AND b.startTime <= localStart AND b.endTime >= localEnd` (v1: events carry no room, so any such row counts as venue coverage; the room name is surfaced informationally).
3. **OUTSIDE_HOURS** — a confirmed (`countsAsConfirmed=1 AND isBookable=1`) row exists that day but its window does not cover [localStart, localEnd] (or its times are NULL).
4. **CLOSED** — any row with `ut.usageKind='closed'`.
5. **UNCONFIRMED** — only rows with `ut.isBookable=1 AND st.countsAsConfirmed=0 AND st.statusCategory <> 'rejected'` ("proposed, not yet agreed").
6. **NO_BOOKING** — nothing else (rejected-only days collapse here, with the rejected rows attached as info).

A multi-day event evaluates per-day; the worst per-day classification (order above) is the event's warning. Severity mapping (stage 02 UI): UNAVAILABLE/NO_BOOKING = red, OUTSIDE_HOURS/UNCONFIRMED/CLOSED = amber, CONFIRMED_COVERED = none.

### 8.3 Helper method, NOT a SQL view

`Portal\Core\Venues::availabilityForRange(int $siteId, ?int $venueId, string $dateFrom, string $dateTo): array` (per-date row sets) and `Venues::classifyEventCoverage(array $event, int $venueId): array` (classification + reasons). A view is wrong here because (a) the schema contains **zero views** — introducing the first one adds installer/replay/privilege surface on shared hosting for no gain; (b) the classification needs timezone conversion and ordered precedence — procedural logic, not relational; (c) helpers are unit-testable and settings-aware.

### 8.4 Timezone rule (make-or-break correctness detail)

Booking rows are **wall-clock venue-local** (`DATE` + `TIME`, no UTC conversion) — this matches the spreadsheet, and a 09:30 hire stays 09:30 across DST transitions (converting to UTC would shift it). The ONLY place conversion happens is when comparing against `tblEvents` UTC datetimes (§8.2). Document this in the class header; never "fix" bookings into UTC.

---

## 9. Reminders — the data that drives the cron (cron itself = stage 02/03)

Three sweeps, all fields already in the §3 tables; dedupe via `tblVenueReminderLog` (§3.15):

| refType | Trigger condition (all site-scoped, `isDeleted=0`/active rows only) | Lead-time setting | dueDate logged |
|---|---|---|---|
| `booking-unagreed` | booking with `ut.isBookable=1 AND st.countsAsConfirmed=0 AND st.statusCategory <> 'rejected'` and `bookingDate <= CURDATE() + venues.unagreed_lead_days` | `venues.unagreed_lead_days` (21) | `bookingDate` |
| `agreement-renewal` | agreement `status='active'` and (`renewalDate` within `venues.renewal_lead_days`) OR (`termEnd - noticePeriodDays` within the window) | `venues.renewal_lead_days` (60) | `renewalDate` or `termEnd` |
| `invoice-due` | invoice `status IN ('pending','part-paid')` and `dueDate <= CURDATE() + venues.invoice_due_lead_days` (includes overdue) | `venues.invoice_due_lead_days` (7) | `dueDate` |

Indexes already provided: `idx_venbk_site_date`, `idx_venagr_renewal` + `idx_venagr_termend`, `idx_veninv_site_status_due`. The `(refType, refID, dueDate)` unique key means a date/dueDate change legitimately re-reminds while a re-run the same day no-ops (INSERT with `ON DUPLICATE KEY UPDATE` or pre-check, matching the asset-reminders cron). Cron endpoint pattern to copy: token-gated `_apps/cron/asset-reminders.php` (constant-time token compare, empty-token→403).

---

## 10. Audit — final shape

Per §1.5: **no new table.** Every venue mutation funnels through `Venues::audit()` → `Logger::audit($tableName, $recordId, $action, $oldData, $newData)` → `tblAuditTrail` (JSON `changeSet` diff, API-key source attribution built in). Bulk operations write ONE summary row each (generator → `tblVenueBookingGroups` row; import commit → `tblVenueImportBatches` row) with counts/dates in `newData`. No redaction list needed (no secret columns exist in any §3 table — deliberate: e.g. alarm codes are explicitly steered to the agreement-file vault, not `tblVenues.notes`). `tblActivityLogs` (`Logger::activity()`) additionally records page-level actions per house convention — stage 02 decides call sites.

---

## 11. Excel/CSV import — exact mapping for the church workbook

Source: `Proposed_Mill_Road_Rental.xlsx` — sheets `2024`,`2025`,`2026` (+ `BackingData`), columns DATE / HOURS / TIMES / NOTES / STATUS, one row per date. Parser constraints: **no Composer** — XLSX is read natively (`ZipArchive` + `SimpleXML` over `xl/worksheets/sheet*.xml` + `xl/sharedStrings.xml`); CSV path via `fgetcsv`. Staging into §3.13/§3.14; wizard = upload → parse+auto-map → resolve unknown vocab → preview → commit.

### 11.1 Field mapping

| Sheet cell | Staging column | Target | Parse rules |
|---|---|---|---|
| DATE | `rawDate` → `parsedDate` | `tblVenueBookings.bookingDate` | Numeric ⇒ Excel 1900-system serial: `date = 1899-12-30 + n days` (the -30 offset absorbs Excel's phantom 1900-02-29 for all serials ≥ 61; every serial in this workbook is ≥ 45k). Verified: 45542 → **2024-09-07, a Saturday** ✓. Text ⇒ `DateTime` parse with d/m/Y preference. Sanity window 2000-01-01…2100-12-31; outside ⇒ `rowState='error'`, `stateNote='implausible date (1904 system?)'`. Blank/header-repeat rows ⇒ `rowState='skipped'`. |
| HOURS | `rawHours` → `mappedUsageTypeID` | `tblVenueBookings.usageTypeID` | Trim; case-insensitive match against the target venue's `tblVenueUsageTypes.typeName` (CI collation does this in SQL). No match ⇒ entry in `vocabMap.hours` with resolution `pending` — wizard forces map-to-existing or create (create prompts `usageKind` + default window). |
| TIMES | `rawTimes` → `parsedStartTime/parsedEndTime` | `tblVenueBookings.startTime/endTime` + `timesOverridden` | Regex `^\s*(\d{1,2})[:.](\d{2})\s*(?:-|–|—|to)\s*(\d{1,2})[:.](\d{2})\s*$`. Sentinel set ⇒ NULL times: `''`, `0`, `N/A` (any case), `-`, `—`, `<enter times>`, `TBC`, `TBD`. After mapping: if usage type `isBookable=1` and times NULL ⇒ apply the **effective-dated default window for `parsedDate`** (§3.4) with `timesOverridden=0`; if parsed times equal that default ⇒ `timesOverridden=0`, else `1`. Bookable + no times + no default ⇒ `rowState='ready'`, `stateNote='times missing'` (importable — `<enter times>` rows are real data; they surface on a post-import checklist). Non-bookable kinds ⇒ times forced NULL. |
| NOTES | `rawNotes` | `tblVenueBookings.notes` | Verbatim, trimmed; empty ⇒ NULL. |
| STATUS | `rawStatus` → `mappedStatusID` | `tblVenueBookings.statusID` | Trim; CI match against the site's `tblVenueStatuses.statusName`. The church's landlord-specific names ("Proposed to Mill Rd Baptist Church", "Agreed by Mill Rd Baptist Church", "Pending Mill Rd Leadership Agreement") will NOT match the generic seeds — the wizard's vocab step offers map-to-seed (recommended default, fuzzy-suggested by `Proposed→Proposed to Landlord` etc.) or create-verbatim (create prompts `statusCategory` + `countsAsConfirmed` + `isAvailable`). Unmatched ⇒ `vocabMap.status` pending entry. |

### 11.2 Sheets & BackingData

- Every sheet whose name parses as a 4-digit year is staged; `sheetYear` recorded per row. `YEAR(parsedDate) <> sheetYear` ⇒ `stateNote='sheet-year mismatch'` (warning, still importable).
- `BackingData` is **not** staged as rows. It is parsed into `vocabMap.backingData`: the STATUS list pre-populates the status-mapping step's suggestions; the usage-type→default-TIMES columns (which differ per year) are offered as **`tblVenueUsageTypeWindows` rows with `effectiveFrom` = Jan 1 of the year each column represents** — this is how the "2026 Regular → 09:30–16:00" change lands as data.
- Unknown-vocab surfacing contract: commit is blocked while any `vocabMap` entry or `rowState='pending'` row remains unresolved; `error` rows are excluded from commit and listed; `skipped` rows are counted. Commit wraps inserts in a transaction, stamps `importedBookingID` per row, sets batch `status='committed'`/`committedAt`, writes one audit row (§10).
- Multi-day runs import as their per-day rows exactly as in the sheet (no grouping inferred); an optional "group consecutive identical rows" assist is a stage-02 nicety the schema already supports (`groupID` nullable).

---

## 12. Migration plan hint (stage 03 finalises)

**Reserved numbers: 170–173** (166–169 belong to the in-flight gap-analysis items — do NOT take them). Proposed split, subject to stage 03:
- **170** — the entire venues schema foundation: all 15 tables in the dependency order below, the §3.16 guarded ALTER on `tblPayment`, route seeds (with stub handlers if handlers ship later — the `check_route_targets.py` lesson), settings seeds, self-record. One migration is house-normal for a schema drop of this size (precedent: 159 shipped 12+ tables).
- **171–173** — reserved buffer for the venues build (stage 03 may split UI/API/cron seeds or later-pass additions; unused numbers simply remain free).

**Dependency order (creation order inside 170 AND fold order in full_schema.sql):**
1. `tblVenues` (needs `tblSites`, `tblAssetOrgs`, `tblUsers`)
2. `tblVenueRooms` (1)
3. `tblVenueUsageTypes` (1)
4. `tblVenueUsageTypeWindows` (3)
5. `tblVenueStatuses` (`tblSites`)
6. `tblVenueAgreements` (1, self-FK)
7. `tblVenueAgreementFiles` (6)
8. `tblVenueBookingGroups` (1)
9. `tblVenueBookings` (1,2,3,5,6,8 + `tblEvents`, `tblUsers`)
10. `tblVenueInvoices` (1,6)
11. `tblVenueInvoiceLines` (9,10)
12. `tblVenueInvoicePayments` (10 + `tblPayment`)
13. `tblVenueImportBatches` (1)
14. `tblVenueImportRows` (13,3,5,9)
15. `tblVenueReminderLog` (none — no FKs by design)
Then: guarded ALTER `tblPayment.purpose` → then route/settings seeds → then `tblMigrations` self-record.

**full_schema.sql fold:** one new commented block at the **end of the file, after the Asset Tracker block** — mandatory, because `tblVenues` FKs `tblAssetOrgs` (defined at l.≈6136) and `tblVenueBookings` FKs `tblEvents`/`tblPayment` (both far earlier); same "placed out-of-numeric-order so FK targets are in scope" convention the asset block itself documents (l.6100–6107). The `tblPayment.purpose` ENUM member folds **inline into the existing `tblPayment` CREATE TABLE** (l.≈4096). Seeds fold beside their tables.

**Guarded-ALTER inventory (flag for CI):** exactly one — §3.16. Everything else is new `CREATE TABLE IF NOT EXISTS` (idempotent by construction, replays as a no-op). No `INSERT` seeds touch per-site vocab (§4), so replay-idempotency holds via `ON DUPLICATE KEY UPDATE` on routes/settings/tblMigrations only.

---

## 13. OPEN QUESTIONS / RISKS for stage 02

1. **`AssetRegister::saveOrg()` reuse for landlord CRUD** — confirmed the method exists (l.≈3563) and orgs are site-level reference data logged via `Logger::activity()`, but stage 02 must verify its exact return/validation contract and permission gate before calling it from `/venues`, and must ship the venues-side landlord picker/create UI so the flow works with the Assets app disabled (§1.1). Fallback: venue-local prepared statements against `tblAssetOrgs` (same columns).
2. **GDPR catalogue** — `GdprEraser::catalogue()` does NOT currently cover `tblAssetOrgs` contact fields, and the new venue tables carry `createdByID`/`updatedByID`/`recordedByID`/`uploadedByID` user references plus `caretakerName`/`caretakerPhone` (a natural person's data). Stage 02/03 must add the venue tables (and decide org-contact handling) to the eraser catalogue — the #372 lesson was precisely silent-skip erasure bugs.
3. **`Payments::PURPOSES` + fan-out branch** — ship the ENUM member + `markPaymentSucceeded()` `'venue-hire'` branch in v1 (recommended, cheap, keeps rails coherent) vs dormant ENUM only. If shipped: which UI initiates a venue-hire checkout (probably none in v1 — the bridge exists for API/future)? Decide.
4. **Cross-midnight hires** unsupported in v1 (`endTime > startTime` PHP-enforced; lock-ins/overnights would need either next-day second row or a spec change). Confirm acceptable.
5. **Generator duplicate policy** — recommended: skip dates with ANY existing non-deleted booking for (venue, date, same-roomID-or-NULL) and report; alternative (skip only same usage type) risks double-booking rows. Confirm UX.
6. **Event↔venue linkage direction** — v1 links booking→event (`tblVenueBookings.eventID`) and classifies coverage venue-wide because `tblEvents` has no venueID. If the site has multiple venues, which venue does an event check against? Options: (a) per-check UI selection, (b) a `venues.calendar_default_venue` setting, (c) a later guarded ALTER adding `tblEvents.venueID`. Recommend (b) for v1 + (c) as a follow-up issue. Decide.
7. **Status/usage-type cross-tenant invariants are PHP-enforced** (no composite FKs, §2) — every save handler MUST validate `status.siteID = booking.siteID` and `usageType.venueID = booking.venueID`. Carry this into the stage-03 verification checklist.
8. **Per-site status seeding trigger** — recommended "on first `/venues` page access per site, idempotent" (§4); alternative is an admin "seed defaults" button. Confirm, and confirm the same for re-seeding after a site deletes all statuses.
9. **XLSX native parsing** — `ZipArchive` availability on DreamHost shared PHP is expected but must be smoke-tested in stage 03's verification plan; the CSV path is the guaranteed fallback and the wizard must advertise it.
10. **VAT / deposits / part-refunds on invoices** — deliberately out of v1 (UK small-charity hall hire is typically VAT-exempt; deposits can be modelled as a `tblVenueInvoicePayments` row against a deposit invoice if needed). Confirm.
11. **Reminder recipients** — which roles receive each sweep's digest (`venues.reminder_roles`-style setting vs leadership-role targeting like milestones). Stage 02 decision; data model is indifferent.
12. **Room-level event coverage** — if events later gain room placement, §8.2 rule 2 must tighten to room-aware coverage (`b.roomID IS NULL OR b.roomID = event.roomID`). Note in the helper's header now.
13. **Booking `notes` vs multiple events per day** — one booking links at most one `eventID`; a day hosting service + lunch + afternoon programme is either one booking (notes describe all, event link = main service) or several bookings. Document the recommendation in the leader-facing help page (stage 02).
