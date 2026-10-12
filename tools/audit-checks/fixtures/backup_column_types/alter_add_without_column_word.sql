-- Fixture: a column added by ALTER TABLE WITHOUT the word COLUMN and without
-- backticks. The check used to skip it altogether. It must read it and name
-- tblFixtureFlags.flags as a BIT column.
CREATE TABLE IF NOT EXISTS `tblFixtureFlags` (
    `flagID` INT NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (`flagID`)
) ENGINE=InnoDB;

ALTER TABLE tblFixtureFlags ADD flags BIT(4) NOT NULL;
