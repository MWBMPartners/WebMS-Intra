-- Fixture: two columns added at once, in brackets. The check does not read this
-- form, so it must SAY so and name the statement, not pass.
CREATE TABLE IF NOT EXISTS `tblFixtureReadings` (
    `readingID` INT NOT NULL AUTO_INCREMENT,
    `latitude`  DECIMAL(10,7) DEFAULT NULL,
    PRIMARY KEY (`readingID`)
) ENGINE=InnoDB;

ALTER TABLE `tblFixtureReadings` ADD (`extraA` INT NULL, `ratio` FLOAT NULL);
