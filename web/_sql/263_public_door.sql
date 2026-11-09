-- =============================================================================
-- Migration 263: the public door's names (#493, package P4a; D-056, D-097)
-- =============================================================================
-- What this adds:
--
--   tblPublicDoorNames         ONE row per organisation whose public door is
--                              switched ON. The row holds the organisation's
--                              random public name — 24 lower-case hex
--                              characters, made by the portal from
--                              random_bytes(12) — which appears in the door's
--                              addresses on the portal's own address, such as
--                              /w/{name}/events.json. An organisation with NO
--                              row has NO door: nothing answers for it, on its
--                              name or on its public address (D-097, "off until
--                              somebody turns it on"). Replacing the name stops
--                              every copy pasted into another website at once;
--                              deleting the row switches the door off.
--                              The name is NOT a secret (it is pasted into
--                              public websites). It is random so that nobody
--                              can guess names to find out which organisations
--                              an installation hosts. No two organisations may
--                              share one: the unique key is the real guard.
--   public.door.testCopies.permitted
--                              INSTALLATION ONLY, 'testers' or 'either' (D-087):
--                              on a test copy of the portal (the alpha, beta or
--                              dev channel), whether an organisation may open
--                              its door to anybody, or only to signed-in
--                              testers. Seeded 'testers', the stricter. The
--                              portal READS it from this package on; the card
--                              that changes it, and the organisation's own
--                              choice (`public.door.testCopies.open`, which is
--                              NOT seeded — no row means 'testers'), arrive in
--                              package P4d. The general settings editor refuses
--                              both (PublicWebsite::LOCKED_KEYS), so until then
--                              every test copy's door answers signed-in testers
--                              only. On the live portal nothing reads either.
--
--   tblPublicHosts.approvedAtUtc, .approvedByID
--                              D-101: an organisation's public address (D-095)
--                              takes effect ONLY once the installation's global
--                              administrator approves it. Round 6 of P4a's check
--                              found an organisation's administrator could save
--                              an address members really sign in at that the
--                              portal had no record of (an alias, or the members'
--                              address itself while the portal's address was not
--                              set), turning every page there — sign-in included
--                              — into the door's 404. Added to migration 262's
--                              table here, behind information_schema guards. Every
--                              existing address starts NOT approved.
--
-- NO PERSONAL DETAILS: tblPublicDoorNames holds an organisation, a random name
-- and a moment. Who turned the door on, replaced its name or switched it off is
-- in the activity log, not here.
--
-- NOTHING IS TURNED ON. No organisation gets a row from this migration, so
-- every organisation's door starts switched off (D-097) and nothing the portal
-- holds reaches any outside website until a global administrator, or somebody
-- given "Set up the public website", presses "Turn on the public door" on
-- Admin, Public website.
--
-- SAFE TO RUN TWICE: CREATE TABLE IF NOT EXISTS; the setting's ON DUPLICATE
-- KEY UPDATE touches `defaultValue` only, so a choice somebody made is never
-- changed. The two columns and the link are each added only when the
-- information_schema says they are missing.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/493
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblPublicDoorNames` (
    `siteID`       INT      NOT NULL COMMENT 'The ONE organisation whose public door this is (#493 P4a, D-097). A row here means the door is switched ON; no row means it is off.',
    `publicName`   CHAR(24) NOT NULL COMMENT 'The organisation''s public name in its door addresses (/w/{name}/...): 24 lower-case hex characters made by the portal from random_bytes(12), never typed by anybody. Not a secret: it is pasted into public websites. Replacing it stops every old copy at once. Checked again by Portal\\Core\\PublicWebsiteSetup whenever it is read.',
    `changedAtUtc` DATETIME NOT NULL COMMENT 'A moment in UTC, written with UTC_TIMESTAMP(): when the door was turned on or its name last replaced. Nothing compares it; who did it is in the activity log.',
    PRIMARY KEY (`siteID`),
    UNIQUE KEY `uq_public_door_name` (`publicName`),
    CONSTRAINT `fk_public_door_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Each organisation''s public door name; a row means the door is on (#493 P4a, D-097)';

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'public.door.testCopies.permitted', 'testers', 'testers', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- D-101: an organisation's public address takes effect ONLY once the
-- installation's global administrator approves it. Two columns on
-- tblPublicHosts (made by migration 262), each added only when missing — the
-- information_schema guard, because MySQL refuses IF NOT EXISTS on a column —
-- and the link to the approver, added only when missing. Every existing
-- address starts UNAPPROVED (NULL): it does nothing until approved.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPublicHosts' AND COLUMN_NAME = 'approvedAtUtc'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPublicHosts` ADD COLUMN `approvedAtUtc` DATETIME NULL DEFAULT NULL COMMENT ''A moment in UTC, written with UTC_TIMESTAMP(): when the installation''''s global administrator approved this public address (D-101). NULL = not approved, and then the address does NOTHING: it is not the public door. Saving or changing the address empties it.'' AFTER `changedAtUtc`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPublicHosts' AND COLUMN_NAME = 'approvedByID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPublicHosts` ADD COLUMN `approvedByID` INT NULL DEFAULT NULL COMMENT ''FK to tblUsers: the installation''''s global administrator who approved this public address (D-101); NULL when not approved, or when that account is gone. Who approved or withdrew is also in the activity log.'' AFTER `approvedAtUtc`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPublicHosts'
      AND CONSTRAINT_NAME = 'fk_public_host_approver' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblPublicHosts` ADD CONSTRAINT `fk_public_host_approver` FOREIGN KEY (`approvedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblMigrations` (`filename`) VALUES ('263_public_door.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
