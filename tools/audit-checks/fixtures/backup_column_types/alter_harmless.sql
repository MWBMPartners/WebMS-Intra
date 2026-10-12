-- Fixture: ALTER TABLE statements the check reads in full or knows to be
-- harmless: an index, a rule that no two rows are the same, a link, and a
-- column of a type the backup can copy. None of these is a finding.
CREATE TABLE IF NOT EXISTS `tblFixtureSites` (
    `siteID` INT NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (`siteID`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tblFixtureReadings` (
    `readingID` INT NOT NULL AUTO_INCREMENT,
    `latitude`  DECIMAL(10,7) DEFAULT NULL,
    PRIMARY KEY (`readingID`)
) ENGINE=InnoDB;

ALTER TABLE `tblFixtureReadings` ADD COLUMN `siteID` INT NULL, ADD INDEX `idx_fixture_site` (`siteID`);
ALTER TABLE `tblFixtureReadings` ADD CONSTRAINT `fk_fixture_reading_site` FOREIGN KEY (`siteID`) REFERENCES `tblFixtureSites`(`siteID`) ON DELETE SET NULL;
ALTER TABLE `tblFixtureReadings` ADD UNIQUE KEY `uq_fixture_lat` (`latitude`), DROP INDEX `idx_fixture_site`;
