-- Fixture: a table whose rows point at rows of the same table, with no primary
-- key. The restore could not find each row again to fill its pointer in.
CREATE TABLE IF NOT EXISTS `tblFixtureSites` (
    `siteID` INT NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (`siteID`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tblFixtureTree` (
    `nodeCode` VARCHAR(20) NOT NULL,
    `siteID`   INT         NOT NULL,
    `parent`   VARCHAR(20) DEFAULT NULL,
    UNIQUE KEY `uq_fixture_node` (`nodeCode`),
    CONSTRAINT `fk_fixture_tree_site`   FOREIGN KEY (`siteID`) REFERENCES `tblFixtureSites`(`siteID`),
    CONSTRAINT `fk_fixture_tree_parent` FOREIGN KEY (`parent`) REFERENCES `tblFixtureTree`(`nodeCode`) ON DELETE SET NULL
) ENGINE=InnoDB;
