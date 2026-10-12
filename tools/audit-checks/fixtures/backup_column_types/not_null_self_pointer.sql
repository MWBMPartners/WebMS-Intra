-- Fixture: a table whose rows point at rows of the same table through a column
-- that may NOT be empty. The restore writes such rows with the pointer empty
-- first, so it could never put this table back.
CREATE TABLE IF NOT EXISTS `tblFixtureSites` (
    `siteID` INT NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (`siteID`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tblFixtureTree` (
    `nodeID`   INT NOT NULL AUTO_INCREMENT,
    `siteID`   INT NOT NULL,
    `parentID` INT NOT NULL,
    PRIMARY KEY (`nodeID`),
    CONSTRAINT `fk_fixture_tree_site`   FOREIGN KEY (`siteID`)   REFERENCES `tblFixtureSites`(`siteID`),
    CONSTRAINT `fk_fixture_tree_parent` FOREIGN KEY (`parentID`) REFERENCES `tblFixtureTree`(`nodeID`)
) ENGINE=InnoDB;
