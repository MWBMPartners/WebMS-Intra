-- Fixture: the same ring as ring.sql, but the second link is added the way this
-- codebase's guarded statements add things: from INSIDE a quoted text that is
-- prepared and run. A check that skipped quoted text would miss it.
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

SET @sql := IF(1 = 1,
    'ALTER TABLE `tblFixtureTeams` ADD CONSTRAINT `fk_fixture_team_leader` FOREIGN KEY (`leaderID`) REFERENCES `tblFixtureMembers`(`memberID`) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
