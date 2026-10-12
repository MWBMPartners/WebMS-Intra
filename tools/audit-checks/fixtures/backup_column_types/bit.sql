-- Fixture: a BIT column. The database hands a BIT over as digits it then
-- refuses to take back. The check must name tblFixtureFlags.flags.
CREATE TABLE IF NOT EXISTS `tblFixtureSites` (
    `siteID` INT NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (`siteID`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tblFixtureFlags` (
    `flagID` INT    NOT NULL AUTO_INCREMENT,
    `siteID` INT    NOT NULL,
    `flags`  BIT(8) NOT NULL DEFAULT b'0',
    PRIMARY KEY (`flagID`),
    CONSTRAINT `fk_fixture_flag_site` FOREIGN KEY (`siteID`) REFERENCES `tblFixtureSites`(`siteID`)
) ENGINE=InnoDB;
