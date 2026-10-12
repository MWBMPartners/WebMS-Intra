-- Fixture: a BIT column added by ALTER TABLE, with the table named with its
-- database in front (`portal`.`tblFixtureFlags`). The check used to match
-- nothing in such a statement and pass. It must read it and catch the column.
CREATE TABLE IF NOT EXISTS `tblFixtureFlags` (
    `flagID` INT NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (`flagID`)
) ENGINE=InnoDB;

ALTER TABLE `portal`.`tblFixtureFlags` ADD COLUMN `flags` BIT(4) NULL;
