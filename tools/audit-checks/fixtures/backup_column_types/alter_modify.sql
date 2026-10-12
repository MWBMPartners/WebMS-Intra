-- Fixture: an existing column turned into a FLOAT by MODIFY. The check does not
-- read this form, so it must SAY so and name the statement, not pass.
CREATE TABLE IF NOT EXISTS `tblFixtureReadings` (
    `readingID` INT NOT NULL AUTO_INCREMENT,
    `latitude`  DECIMAL(10,7) DEFAULT NULL,
    PRIMARY KEY (`readingID`)
) ENGINE=InnoDB;

ALTER TABLE `tblFixtureReadings` MODIFY COLUMN `latitude` FLOAT NULL;
