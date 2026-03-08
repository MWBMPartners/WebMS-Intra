-- Migration 018: Multi-site schema fixes
-- Adds missing siteID to tblRecurrenceRules
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/59

-- -----------------------------------------------------------------------------
-- 🔁 Add siteID to tblRecurrenceRules (missed in migration 015)
-- -----------------------------------------------------------------------------
ALTER TABLE `tblRecurrenceRules`
    ADD COLUMN `siteID` INT NOT NULL DEFAULT 1 COMMENT 'FK → tblSites' AFTER `ruleID`;

ALTER TABLE `tblRecurrenceRules`
    ADD KEY `idx_recur_site` (`siteID`),
    ADD CONSTRAINT `fk_recur_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE RESTRICT;

-- 📋 Track migration
INSERT INTO `tblMigrations` (`filename`) VALUES ('018_multisite_fixes.sql');
