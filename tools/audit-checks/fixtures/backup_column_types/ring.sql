-- Fixture: two tables that point at each other. A restore empties and refills
-- tables in an order, and a ring has none.
CREATE TABLE IF NOT EXISTS `tblFixtureTeams` (
    `teamID`   INT NOT NULL AUTO_INCREMENT,
    `leaderID` INT DEFAULT NULL,
    PRIMARY KEY (`teamID`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tblFixtureMembers` (
    `memberID` INT NOT NULL AUTO_INCREMENT,
    `teamID`   INT NOT NULL,
    PRIMARY KEY (`memberID`),
    CONSTRAINT `fk_fixture_member_team` FOREIGN KEY (`teamID`) REFERENCES `tblFixtureTeams`(`teamID`)
) ENGINE=InnoDB;

ALTER TABLE `tblFixtureTeams` ADD CONSTRAINT `fk_fixture_team_leader` FOREIGN KEY (`leaderID`) REFERENCES `tblFixtureMembers`(`memberID`) ON DELETE SET NULL;
