-- Fixture for tools/audit-checks/check_backup_column_types.py --self-test.
-- A small SOUND schema: every type is one the backup carries, the table that
-- points at itself does so through a column that may be empty and has a key,
-- and nothing points in a ring. The check must find nothing here.
CREATE TABLE IF NOT EXISTS `tblFixtureSites` (
    `siteID`   INT          NOT NULL AUTO_INCREMENT,
    `siteName` VARCHAR(100) NOT NULL COMMENT 'A comment with a comma, a (bracket) and the word FLOAT in it',
    `logo`     BLOB         DEFAULT NULL,
    PRIMARY KEY (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblFixtureTree` (
    `nodeID`    INT           NOT NULL AUTO_INCREMENT,
    `siteID`    INT           NOT NULL,
    `parentID`  INT           DEFAULT NULL,
    `weight`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `updatedAt` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`nodeID`),
    KEY `idx_fixture_parent` (`parentID`),
    CONSTRAINT `fk_fixture_tree_site`   FOREIGN KEY (`siteID`)   REFERENCES `tblFixtureSites`(`siteID`),
    CONSTRAINT `fk_fixture_tree_parent` FOREIGN KEY (`parentID`) REFERENCES `tblFixtureTree`(`nodeID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
