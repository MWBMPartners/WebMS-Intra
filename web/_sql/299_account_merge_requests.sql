-- =============================================================================
-- Migration 299: a merge of two accounts, agreed by the person (#1064, D-190)
--
-- Path: web/_sql/299_account_merge_requests.sql
--
-- WHY THIS EXISTS
--   The owner decided on 16 November 2026 (D-190) that two accounts become one
--   only when the person themselves agrees by signing in to BOTH. An
--   administrator starts a request; the person agrees from each account in turn;
--   the second agreement completes it (Portal\Core\AccountMerge).
--
-- WHAT IT DOES
--   Two tables and four addresses. A request row says who asked, when, until
--   when, and how it ended. Its two SIDE rows say which account stays and which
--   is the duplicate, and when each agreed. One account number per side row, in
--   a column called `userID`, so the erasure routine reaches it the ordinary way.
--   `browserTokenHash` is the SHA-256 of a random token the first agreement puts
--   in a cookie; the second must come from the browser holding it.
--
-- SAFE TO RUN TWICE
--   CREATE TABLE IF NOT EXISTS, and ON DUPLICATE KEY UPDATE on the rows.
--
-- MySQL 8.0 ∩ MariaDB
--   Plain DDL; no generated column, no IF [NOT] EXISTS on a column or index.
--
--   The same text is in web/_sql/full_schema.sql, in the block of migration
--   records near its end, and the two must agree.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblAccountMergeRequest` (
    `requestID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`           INT          NOT NULL COMMENT 'The organisation the administrator started the request in',
    `requestedByID`    INT          DEFAULT NULL COMMENT 'The administrator who started it. Never one of the two accounts (refused)',
    `requestedAtUtc`   DATETIME     NOT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP(): when it was started',
    `expiresAtUtc`     DATETIME     NOT NULL COMMENT 'A MOMENT IN UTC: after this nobody can agree to it (AccountMerge::REQUEST_DAYS)',
    `status`           ENUM('waiting','completed','cancelled') NOT NULL DEFAULT 'waiting' COMMENT 'waiting: open. completed: merged. cancelled: by either account or an administrator',
    `browserTokenHash` CHAR(64)     DEFAULT NULL COMMENT 'SHA-256 of the random token the FIRST agreement put in a cookie; the token itself is never stored. Emptied on completion',
    `movedSignIns`     TINYINT(1)   DEFAULT NULL COMMENT 'Whether the person ticked to bring the duplicate''s Microsoft or Google sign-ins and passkeys. NULL until completed',
    `cancelledBy`      ENUM('person','administrator') DEFAULT NULL COMMENT 'Who cancelled it, when it was cancelled',
    `finishedAtUtc`    DATETIME     DEFAULT NULL COMMENT 'A MOMENT IN UTC: when it was completed or cancelled',
    PRIMARY KEY (`requestID`),
    KEY `idx_amr_site_status` (`siteID`, `status`),
    CONSTRAINT `fk_amr_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_amr_requester` FOREIGN KEY (`requestedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='A request to merge two accounts that belong to one person; completes only when the person agrees from both (#1064, D-190)';

CREATE TABLE IF NOT EXISTS `tblAccountMergeSide` (
    `sideID`          INT        NOT NULL AUTO_INCREMENT,
    `requestID`       INT        NOT NULL COMMENT 'The request this side belongs to',
    `userID`          INT        NOT NULL COMMENT 'One of the two accounts',
    `side`            ENUM('kept','duplicate') NOT NULL COMMENT 'kept: the account that stays. duplicate: the one merged into it and closed',
    `confirmedAtUtc`  DATETIME   DEFAULT NULL COMMENT 'A MOMENT IN UTC: when the person, signed in as this account with a fresh proof, agreed. Cleared when the other side restarts',
    PRIMARY KEY (`sideID`),
    UNIQUE KEY `uq_ams_request_side` (`requestID`, `side`),
    UNIQUE KEY `uq_ams_request_user` (`requestID`, `userID`),
    KEY `idx_ams_user` (`userID`),
    CONSTRAINT `fk_ams_request` FOREIGN KEY (`requestID`) REFERENCES `tblAccountMergeRequest` (`requestID`) ON DELETE CASCADE,
    CONSTRAINT `fk_ams_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='The two accounts of a merge request, and when each agreed (#1064)';

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/users/merge/start',  'admin/users/merge-start.php',  1),
    ('admin/users/merge/cancel', 'admin/users/merge-cancel.php', 1),
    ('account/merge',            'account/merge.php',            1),
    ('account/merge/save',       'account/merge-save.php',       1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('299_account_merge_requests.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
