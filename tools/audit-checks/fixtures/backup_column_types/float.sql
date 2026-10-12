-- Fixture: a FLOAT column. The database hands a FLOAT over rounded to six
-- digits, so the backup refuses it. The check must name tblFixtureReadings.ratio.
CREATE TABLE IF NOT EXISTS `tblFixtureSites` (
    `siteID` INT NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (`siteID`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tblFixtureReadings` (
    `readingID` INT   NOT NULL AUTO_INCREMENT,
    `siteID`    INT   NOT NULL,
    `ratio`     FLOAT DEFAULT NULL,
    PRIMARY KEY (`readingID`),
    CONSTRAINT `fk_fixture_reading_site` FOREIGN KEY (`siteID`) REFERENCES `tblFixtureSites`(`siteID`)
) ENGINE=InnoDB;
