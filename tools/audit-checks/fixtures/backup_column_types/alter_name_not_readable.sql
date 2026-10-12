-- Fixture: an ALTER TABLE whose table name is in a form the check does not
-- read (a hyphen, inside backticks). It cannot tell which table is changed,
-- so it must SAY so and quote the statement, never skip it.
CREATE TABLE IF NOT EXISTS `tblFixtureReadings` (
    `readingID` INT NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (`readingID`)
) ENGINE=InnoDB;

ALTER TABLE `tblFixture-Readings` ADD COLUMN `ratio` FLOAT NULL;
