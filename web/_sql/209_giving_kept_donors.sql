-- =============================================================================
-- Migration 209: giving records keep a donor's name for tax reasons, for a
--                set time, after the donor has asked to be erased
--                (roll-out package A2b)
-- =============================================================================
-- One new table, seven settings and two addresses. No existing table is
-- changed and no existing data is changed.
--
-- -----------------------------------------------------------------------------
-- WHY THE TABLE
-- -----------------------------------------------------------------------------
-- When somebody asks to be forgotten, their account is emptied. Tax and
-- accounting law in most countries makes an organisation keep SOME giving
-- records with the donor's name for a number of years: in the United Kingdom,
-- for example, a Gift Aid declaration must show the donor's full name and home
-- address, and every gift claimed under it must lead back to it.
--
-- Until now the kept declaration carried no name at all: the name was on the
-- account, and the account was emptied. `tblGivingKeptDonors` is where the
-- name and home address now live, one row per organisation and erased donor,
-- so that the account can still be emptied. The kept gifts keep their account
-- number until the keep-until date; the retention job then clears the name,
-- the address and the link, and writes a line to the erasure's audit trail.
--
-- The owner (Salem874) set the rule on 11 October 2026. Each organisation has
-- its own country, its own number of years and its own financial year end,
-- because this is a product used in any country.
--
-- Tried and rejected:
--   - Putting the name on the tax declaration. Outside the United Kingdom
--     there is often no declaration, only gifts and statements.
--   - Storing the keep-until DATE on the row. A setting changed later would
--     leave old dates behind, and the page and the job could then disagree.
--     The row stores the fact (the day of the last kept gift); the date is
--     worked out from the organisation's settings each time it is needed.
--
-- -----------------------------------------------------------------------------
-- WHAT THE LINKS DO
-- -----------------------------------------------------------------------------
--   fk_gkd_donor    ON DELETE RESTRICT. An erasure never deletes an account
--                   row, it empties it. If something ever did try to delete
--                   one, this refuses, rather than quietly taking the kept
--                   name with it.
--   fk_gkd_request  ON DELETE SET NULL. Erasure requests are never deleted
--                   either; if one were, the kept row must survive it.
-- Every link points at a primary key, so MySQL 8.4's rule that a link must
-- point at a unique key (ERROR 6125, #552) is met.
--
-- -----------------------------------------------------------------------------
-- THE SEVEN SETTINGS
-- -----------------------------------------------------------------------------
-- Seeded here as installation-wide DEFAULTS. Each organisation's own values
-- are written as its own rows by /admin/settings/giving-records.
--
--   giving.retention.countryCode  the organisation's country, two capital
--                                 letters. EMPTY by default, on purpose: an
--                                 organisation that has not said where it is
--                                 gets a cautious placeholder of 10 years,
--                                 clearly flagged. It is deliberately NOT read
--                                 from `org.address.countryCode`, which is one
--                                 value for the whole installation and is "GB"
--                                 unless changed, and would make every
--                                 organisation look British.
--   giving.retention.scope        receipted (only gifts with a tax
--                                 declaration, receipt or statement keep the
--                                 name) or all.
--   giving.retention.years        empty = follow the portal's suggestion for
--                                 the country; otherwise 1 to 50.
--   giving.retention.confirmed    true once an administrator has ticked
--                                 "confirmed with our adviser". NOTHING IS
--                                 CLEARED WHILE THIS IS FALSE.
--   giving.retention.confirmedOn  the calendar date of that tick.
--   giving.retention.yearEnd      the financial year end as MM-DD, or empty.
--   giving.retention.hold         true while an audit or tax enquiry is open:
--                                 nothing is cleared.
--
-- ON DUPLICATE KEY UPDATE touches only `defaultValue`, so running this again
-- never overwrites a value somebody has set.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- The installer replays every numbered migration after full_schema.sql,
-- whatever tblMigrations says. CREATE TABLE IF NOT EXISTS is standard MySQL
-- (it is `IF NOT EXISTS` on a COLUMN that only MariaDB accepts), and every
-- INSERT below is an "insert, or leave what is there".
--
-- The same table, settings and addresses are in full_schema.sql, and the two
-- must agree (tools/audit-checks/check_schema_seed_parity.py).
--
-- WHAT THIS CANNOT DO: it gives no existing donor a kept row. A person erased
-- BEFORE this migration has no name anywhere to keep; that cannot be put back.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/574
-- =============================================================================

-- #############################################################################
-- 🗄️  A. The table
-- #############################################################################

CREATE TABLE IF NOT EXISTS `tblGivingKeptDonors` (
    `keptDonorID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`           INT          NOT NULL COMMENT 'Which organisation these giving records belong to. Each organisation has its own rule for how long the name is kept',
    `donorID`          INT          NOT NULL COMMENT 'The emptied account that the kept gifts, declarations and statements still point at. The account names nobody; the name is only on this row',
    `erasureRequestID` INT          DEFAULT NULL COMMENT 'The erasure request that made this row. The line written when the name is finally cleared goes on that request''s audit trail, so one report tells the whole story',
    `fullName`         VARCHAR(255) DEFAULT NULL COMMENT 'The donor''s name as it was when they were erased. Emptied by the retention job when the keep-until date has passed. Empty from the start if the account had already been emptied by other means',
    `address`          VARCHAR(500) DEFAULT NULL COMMENT 'Home address when erased: from the newest tax declaration at this organisation, otherwise from the account. Emptied by the retention job with the name',
    `postcode`         VARCHAR(20)  DEFAULT NULL COMMENT 'Postcode when erased, from the same declaration. Emptied by the retention job with the name',
    `scope`            ENUM('receipted','all') NOT NULL COMMENT 'Which gifts kept the name on the day: only those with a tax declaration, receipt or statement (receipted), or every gift (all). Kept for the record; changing the setting later does not change this',
    `lastGiftDate`     DATE         NOT NULL COMMENT 'A DATE ON A CALENDAR, not a moment in time: the day of the donor''s last kept gift. The keep-until date is worked out from this each time it is needed, from the organisation''s settings as they are then; it is never stored',
    `keptGiftCount`    INT          NOT NULL DEFAULT 0 COMMENT 'How many gifts kept the donor''s name when this row was made. For the settings page and the audit lines',
    `createdAt`        DATETIME     NOT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP(): when the erasure made this row. Shown only; nothing is worked out from it',
    `clearedAt`        DATETIME     DEFAULT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP(). Empty means the name is still kept. Set when the retention job removes the name; the row itself stays as the record that it happened',
    PRIMARY KEY (`keptDonorID`),
    UNIQUE KEY `uq_gkd_site_donor` (`siteID`, `donorID`),
    KEY `idx_gkd_site_cleared` (`siteID`, `clearedAt`),
    KEY `idx_gkd_donor` (`donorID`),
    KEY `idx_gkd_request` (`erasureRequestID`),
    CONSTRAINT `fk_gkd_site`    FOREIGN KEY (`siteID`)           REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_gkd_donor`   FOREIGN KEY (`donorID`)          REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_gkd_request` FOREIGN KEY (`erasureRequestID`) REFERENCES `tblErasureRequest`(`requestID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='The name and home address of a donor who asked to be erased, kept for tax reasons until a keep-until date (roll-out package A2b)';

-- #############################################################################
-- ⚙️  B. The seven settings (installation-wide defaults)
-- #############################################################################

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'giving.retention.countryCode', '',          '',          0),
    (NULL, 'giving.retention.scope',       'receipted', 'receipted', 0),
    (NULL, 'giving.retention.years',       '',          '',          0),
    (NULL, 'giving.retention.confirmed',   'false',     'false',     0),
    (NULL, 'giving.retention.confirmedOn', '',          '',          0),
    (NULL, 'giving.retention.yearEnd',     '',          '',          0),
    (NULL, 'giving.retention.hold',        'false',     'false',     0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #############################################################################
-- 🔗  C. The two addresses
-- #############################################################################
-- Neither ends in `.php`: the portal answers "page not found" for any address
-- that does.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/settings/giving-records',      'admin/settings/giving-records/index.php', 1),
    ('admin/settings/giving-records/save', 'admin/settings/giving-records/save.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

-- #############################################################################
-- 📋  D. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('209_giving_kept_donors.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
