-- =============================================================================
-- Migration 170: Venue Bookings app — foundation schema + seeds (#429)
--
-- Path: web/_sql/170_venue_bookings.sql
-- The complete Venue Bookings ("/venues") schema: 15 new tables (tenant-side
-- register of hired external buildings — schedule, vocabularies, agreements,
-- payable invoices + payment ledger, import staging, reminder dedupe log),
-- plus route/settings/role seeds.
--
-- ZERO guarded ALTERs by design (see .claude/plans/venue-bookings/
-- 01b-review-resolutions.md Q3): every table below is a plain, brand-new
-- `CREATE TABLE` (all fifteen carry the standard `IF NOT EXISTS` clause) —
-- idempotent by construction — plus ON DUPLICATE KEY UPDATE / WHERE-NOT-
-- EXISTS seeds. Replays as a no-op.
--
-- DROPPED tblPayment BRIDGE (01b Q3): tblVenueInvoicePayments carries NO
-- portalPaymentID column, NO idx_venip_portal index, NO fk_venip_portal
-- constraint, and this file makes NO edit to tblPayment.purpose. Venue hire
-- is a TENANT paying a landlord (money OUT); tblPayment/Payments.php is the
-- INBOUND card-checkout rail (donations/pledges, money IN) — the two are
-- deliberately kept separate. tblVenueInvoicePayments is a pure outgoing
-- ledger, modelled on tblExpenseClaimPayments.
--
-- NO per-site vocabulary rows are seeded here (house rule — see
-- tblAttendanceServiceTypes precedent): statuses are seeded PHP-side by
-- Venues::seedStatuses() on first /venues access per site; usage types are
-- seeded per-venue at venue creation by Venues::seedUsageTypes().
--
-- WALL-CLOCK RULE: tblVenueBookings.bookingDate/startTime/endTime are
-- wall-clock VENUE-LOCAL — never converted to/from UTC. See Venues.php's
-- class header for the full rule (it also applies to tblEvents comparison).
--
-- DEPENDENCY ORDER (creation order below): tblVenues (tblSites,
-- tblAssetOrgs, tblUsers) → tblVenueRooms (1) → tblVenueUsageTypes (1) →
-- tblVenueUsageTypeWindows (3) → tblVenueStatuses (tblSites) →
-- tblVenueAgreements (1, self-FK) → tblVenueAgreementFiles (6) →
-- tblVenueBookingGroups (1) → tblVenueBookings (1,2,3,5,6,8 + tblEvents,
-- tblUsers) → tblVenueInvoices (1,6) → tblVenueInvoiceLines (9,10) →
-- tblVenueInvoicePayments (10) → tblVenueImportBatches (1) →
-- tblVenueImportRows (13,3,5,9) → tblVenueReminderLog (none — no FKs by
-- design). Then settings/role/route seeds, then the tblMigrations
-- self-record.
--
-- FK/INDEX PREFIX RESERVATION (verified collision-free against the full
-- `CONSTRAINT \`fk_` inventory in full_schema.sql — see 01-data-model.md
-- §0): fk_ven_, fk_venrm_, fk_venut_, fk_venutw_, fk_venst_, fk_vengrp_,
-- fk_venbk_, fk_venagr_, fk_venagf_, fk_veninv_, fk_venil_, fk_venip_,
-- fk_venimb_, fk_venimr_ and idx_ven*/uq_ven*.
--
-- NOTE for editors: never put a literal "--" inside a COMMENT/string in this
-- file — check_schema_seed_parity.py strips from any "--" to end-of-line,
-- even inside quotes. Use an em/en dash instead.
--
-- @package    Portal
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/429
-- =============================================================================


-- #############################################################################
-- 🗄️ TABLES
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 🏛️ tblVenues — a rented external building (tenant-side register). Mirror
-- image of Resources (rooms you own) / Assets (things you own): this is a
-- building someone else owns that the site hires. `landlordOrgID` reuses
-- the generic external-org registry `tblAssetOrgs` rather than duplicating
-- it (01-data-model.md §1.1) — the FK target ships in every install
-- regardless of whether the Assets app is enabled. (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — rented external buildings (tenant-side register) (#429)';


-- -----------------------------------------------------------------------------
-- 🚪 tblVenueRooms — optional sub-spaces of a venue. Single-space venues
-- (the common case) have zero rows here: `tblVenueBookings.roomID = NULL`
-- means "the whole venue". (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — optional sub-spaces/rooms of a venue (#429)';


-- -----------------------------------------------------------------------------
-- ⏰ tblVenueUsageTypes — the configurable "HOURS" vocabulary, per VENUE (a
-- default time window is a property of one building's hire arrangement —
-- 01-data-model §2). `isBookable` is kept in lockstep with `usageKind` by
-- the PHP save choke-point (1 iff usageKind='hire') — never posted by a
-- form directly. (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — per-venue usage-type ("HOURS") vocabulary (#429)';


-- -----------------------------------------------------------------------------
-- 🗓️ tblVenueUsageTypeWindows — EFFECTIVE-DATED default time windows. The
-- resolution rule (Venues::resolveWindow()): the row for usage type U on
-- date D is the one with `usageTypeID=U AND effectiveFrom <= D` having the
-- GREATEST effectiveFrom (ORDER BY effectiveFrom DESC LIMIT 1). Seed rows
-- use 1970-01-01 (= always); a per-year default is effectiveFrom = Jan 1 of
-- that year. Changing a default never rewrites existing bookings — times
-- are denormalised onto the booking row at save time. (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — effective-dated default TIMES per usage type (defaults change per schedule year) (#429)';


-- -----------------------------------------------------------------------------
-- 🚦 tblVenueStatuses — the configurable booking-status vocabulary, per
-- SITE (the leadership/landlord approval process is organisational, not
-- per-building — 01-data-model §2). `countsAsConfirmed` is THE flag
-- (decision #4): 1 = the hire is agreed/secured. Seeded PHP-side by
-- Venues::seedStatuses() on first /venues access per site — NOT here (see
-- the file header's "no per-site vocabulary" rule). (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — per-site configurable booking-status vocabulary with countsAsConfirmed/isAvailable flags (decision #4) (#429)';


-- -----------------------------------------------------------------------------
-- 📜 tblVenueAgreements — standing/blanket + ad-hoc hire agreements. Thin
-- NEW tables rather than reusing the Assets agreement vault (01-data-model
-- §1.1) — a hire agreement is a first-class business record (term dates,
-- rate, renewal, supersession chain), not a document attachment.
-- `supersededByID` is a self-FK renewal chain (mirrors
-- tblAssetLoans.parentLoanID). (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — hire agreements: standing/blanket + ad-hoc, with term/rate/renewal (§1.1) (#429)';


-- -----------------------------------------------------------------------------
-- 📎 tblVenueAgreementFiles — agreement document attachments. Field shape
-- mirrors tblAssetResources so upload/serve code is congruent; storage
-- under _uploads/venues/agreements/. (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — signed agreements/schedules/insurance certs attached to a hire agreement (#429)';


-- -----------------------------------------------------------------------------
-- 🔗 tblVenueBookingGroups — groups linking per-date booking rows: multi-day
-- runs (e.g. a VBS week) and recurring generation batches (e.g. weekly
-- worship). A separate table (rather than a self-FK "anchor" booking)
-- avoids anchor-deletion ambiguity and gives the generator a natural audit
-- target. Generation parameters are stored HERE (not as a query-time RRULE)
-- because every occurrence immediately diverges in real data — see
-- 01-data-model §1.3. (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — groups linking per-date booking rows: multi-day runs + recurring generation batches (§1.2/§1.3) (#429)';


-- -----------------------------------------------------------------------------
-- 📅 tblVenueBookings — the core per-date booking rows: ONE ROW PER DAY (no
-- endDate column), linked by groupID for multi-day/recurring runs
-- (01-data-model §1.2). Times are DENORMALISED (resolved from the usage
-- type's effective window at save time) so overlay/conflict queries never
-- join the windows table. No unique key on (venueID, bookingDate) — two
-- rooms or morning+evening hires on one day are legitimate; the
-- generator's duplicate guard is PHP-level. (#429)
-- -----------------------------------------------------------------------------
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
    `notes`           TEXT       DEFAULT NULL COMMENT 'The programme — free text (Communion Service, Fellowship Lunch, VBS, etc). This is the human link to what happens that day',
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
COMMENT='Venue Bookings — one row per venue per date: the hire schedule (§1.2) (#429)';


-- -----------------------------------------------------------------------------
-- 🧾 tblVenueInvoices — payable hire invoices RECEIVED FROM the landlord
-- (money OUT — the church is the tenant). `status` is maintained by the
-- PHP choke-point from SUM(tblVenueInvoicePayments.amountPence) vs
-- amountPence; the paid family can never be spoofed via manual write. A
-- single inline attachment field (invoices are one document). (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — payable hire invoices received from the landlord (§1.4) (#429)';


-- -----------------------------------------------------------------------------
-- 🔢 tblVenueInvoiceLines — which bookings an invoice covers (a monthly
-- invoice covers several Saturdays). Answers "cost per booking AND per
-- agreement" alongside tblVenueBookings.costPence and
-- tblVenueAgreements.rateAmountPence. (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — invoice-booking allocation (a monthly invoice covers several Saturdays) (#429)';


-- -----------------------------------------------------------------------------
-- 💷 tblVenueInvoicePayments — money-out records against a hire invoice.
-- Modelled on tblExpenseClaimPayments (partial payments). PURE OUTGOING
-- LEDGER: per 01b-review-resolutions.md Q3 this table carries NO
-- portalPaymentID column/index/FK and is NEVER bridged to the inbound
-- card-processor ledger Payments.php drives (Stripe/PayPal) — recording
-- what the church paid a landlord is entirely manual/offline by design. (#429)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblVenueInvoicePayments` (
    `payID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `invoiceID`    INT          NOT NULL,
    `paidDate`     DATE         NOT NULL,
    `amountPence`  INT          NOT NULL COMMENT 'Integer minor units — house pence convention (#266); partial payments allowed',
    `method`       ENUM('bank-transfer','standing-order','cash','cheque','card','online','other') NOT NULL DEFAULT 'bank-transfer',
    `reference`    VARCHAR(100) DEFAULT NULL COMMENT 'Bank ref / cheque number',
    `notes`        VARCHAR(500) DEFAULT NULL,
    `recordedByID` INT          NOT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`payID`),
    KEY `idx_venip_invoice` (`invoiceID`),
    KEY `idx_venip_site_date` (`siteID`, `paidDate`),
    CONSTRAINT `fk_venip_site`     FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_venip_invoice`  FOREIGN KEY (`invoiceID`)    REFERENCES `tblVenueInvoices`(`invoiceID`) ON DELETE CASCADE,
    CONSTRAINT `fk_venip_recorder` FOREIGN KEY (`recordedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — payments recorded against hire invoices (pure outgoing ledger, no card-rail bridge) (#429)';


-- -----------------------------------------------------------------------------
-- 📥 tblVenueImportBatches — Excel/CSV import wizard staging (batch state).
-- Mirrors the tblBankImports staging precedent (migration 152) with wizard
-- state (`status`/`vocabMap`) added. `fileHash` is a soft duplicate-import
-- warning only — NOT unique (a legitimately abandoned batch may be
-- re-uploaded). (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — Excel/CSV import wizard batches (staging precedent: tblBankImports) (#429)';


-- -----------------------------------------------------------------------------
-- 📄 tblVenueImportRows — staged import rows: raw cells + parse results +
-- vocab-mapping state, one row per source spreadsheet row. `stateNote`
-- carries human-readable parse/mapping diagnostics (e.g. "times missing",
-- "sheet-year mismatch"). `importedBookingID` traces a committed row to
-- the booking it became. (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — staged import rows: raw cells + parse results + mapping state (#429)';


-- -----------------------------------------------------------------------------
-- 🔔 tblVenueReminderLog — reminder single-shot dedupe. Mirrors
-- tblAssetReminderLog exactly in shape and philosophy: NO FKs by design
-- (the log must survive row deletion of the thing it reminded about).
-- `(refType, refID, dueDate)` is the dedupe key, so a re-scheduled date
-- legitimately re-reminds while a same-day re-run no-ops. (#429)
-- -----------------------------------------------------------------------------
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
COMMENT='Venue Bookings — reminder single-shot dedupe log, no FKs by design (#429)';


-- #############################################################################
-- ⚙️ SETTINGS SEED
-- #############################################################################

-- -----------------------------------------------------------------------------
-- venues.enabled seeds '0' (opt-in, matches resources.enabled/
-- service_plans.enabled precedent) — the #255 lesson: this flag row MUST
-- be seeded or registering the app at /admin/apps silently 403s it
-- forever. venues.cron_token seeds empty + isSensitive=1 (the cron is
-- inert until an admin sets a token, migration-160 precedent). The two
-- api.venues.*.enabled flags gate the ApiRouter convention-path handlers
-- (_apps/venues/api/{check,availability}.php) — nothing for these is
-- registered in tblRoutes (ApiRouter ignores tblRoutes for api/* paths).
-- -----------------------------------------------------------------------------
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'venues.enabled',                  '0',        '0',        0),
    (NULL, 'venues.currency',                 'GBP',      'GBP',      0),
    (NULL, 'venues.maxFileSize',              '10485760', '10485760', 0),
    (NULL, 'venues.unagreed_lead_days',       '21',       '21',       0),
    (NULL, 'venues.renewal_lead_days',        '60',       '60',       0),
    (NULL, 'venues.invoice_due_lead_days',    '7',        '7',        0),
    (NULL, 'venues.reminders_enabled',        '1',        '1',        0),
    (NULL, 'venues.reminder_roles',           '',         '',         0),
    (NULL, 'venues.cron_token',               '',         '',         1),
    (NULL, 'venues.calendar_default_venue',   '0',        '0',        0),
    (NULL, 'api.venues.check.enabled',        'true',     'true',     0),
    (NULL, 'api.venues.availability.enabled', 'true',     'true',     0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 🏷️ ROLE SEED
-- #############################################################################

-- -----------------------------------------------------------------------------
-- Venue Manager. WHERE NOT EXISTS idiom (matches migration 159's
-- asset_manager seed) since tblRoles has no meaningful column for
-- ON DUPLICATE KEY UPDATE to touch.
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoles` (`roleKey`, `roleName`)
    SELECT 'venue_manager', 'Venue Manager'
    WHERE NOT EXISTS (SELECT 1 FROM `tblRoles` WHERE `roleKey` = 'venue_manager');


-- #############################################################################
-- 🗺️ ROUTES SEED
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 26 rows. Every targetFile below ships as a REAL handler in this same PR
-- (no stubs — 02b-review-resolutions.md disposition 10). `cron/venue-
-- reminders` and `help/venues` seed isProtected=0 (public — the cron
-- checks its own token; help/assets precedent for the public help route).
-- NOTHING for api/venues/* is registered here — ApiRouter ignores
-- tblRoutes for api/* paths (the #372 dead-route lesson); those two
-- endpoints are gated solely by the api.venues.*.enabled flags above.
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('venues',                       'venues/index.php',                1),
    ('venues/venue',                 'venues/venue.php',                1),
    ('venues/manage',                'venues/manage.php',               1),
    ('venues/venue-save',            'venues/venue-save.php',           1),
    ('venues/rooms',                 'venues/rooms.php',                1),
    ('venues/usage-types',           'venues/usage-types.php',          1),
    ('venues/statuses',              'venues/statuses.php',             1),
    ('venues/booking',               'venues/booking.php',              1),
    ('venues/booking-save',          'venues/booking-save.php',         1),
    ('venues/generate',              'venues/generate.php',             1),
    ('venues/import',                'venues/import.php',               1),
    ('venues/agreements',            'venues/agreements.php',           1),
    ('venues/agreement-save',        'venues/agreement-save.php',       1),
    ('venues/agreement-files',       'venues/agreement-files.php',      1),
    ('venues/agreement-download',    'venues/agreement-download.php',   1),
    ('venues/invoices',              'venues/invoices.php',             1),
    ('venues/invoice',               'venues/invoice.php',              1),
    ('venues/invoice-save',          'venues/invoice-save.php',         1),
    ('venues/invoice-payment-save',  'venues/invoice-payment-save.php', 1),
    ('venues/invoice-download',      'venues/invoice-download.php',     1),
    ('venues/invoice-pdf',           'venues/invoice-pdf.php',          1),
    ('venues/export',                'venues/export.php',               1),
    ('venues/schedule-pdf',          'venues/schedule-pdf.php',         1),
    ('venues/settings',              'venues/settings.php',             1),
    ('cron/venue-reminders',         'cron/venue-reminders.php',        0),
    ('help/venues',                  'help/venues.php',                 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- 📋 SELF-RECORD
-- #############################################################################

-- -----------------------------------------------------------------------------
-- Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('170_venue_bookings.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
