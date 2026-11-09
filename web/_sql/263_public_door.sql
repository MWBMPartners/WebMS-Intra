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
-- changed.
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

INSERT INTO `tblMigrations` (`filename`) VALUES ('263_public_door.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
