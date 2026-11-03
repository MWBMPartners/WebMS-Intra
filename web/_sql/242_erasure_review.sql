-- =============================================================================
-- Migration 242: where a human decision about free text is kept (#724)
-- =============================================================================
-- One new table, `tblErasureReviewDecisions`, and the two addresses of the
-- review page. Nothing existing is changed, and no column is relaxed — see
-- "NOTHING HAD TO BE MADE NULLABLE" below, which is a measurement and not a
-- hope.
--
-- -----------------------------------------------------------------------------
-- WHY THERE IS A TABLE AT ALL
-- -----------------------------------------------------------------------------
-- Seven columns in this portal hold free text that an automatic erasure rule
-- deliberately does not touch, because whether the text matters depends on the
-- request in front of you: "Jane's laptop" on a licence seat is a person's
-- name, and "spare projector" on the next row is not. The owner decided on
-- 3 November 2026 that a person holding the `data_controller` role looks at
-- each such line and decides — empty it, or keep it — and that an erasure
-- request CANNOT CLOSE while any of them is undecided.
--
-- That decision has to be kept somewhere, with who made it and when, because
-- "not skippable in silence" is the whole requirement. `tblErasureAudit`
-- records it too, but the audit trail is a sealed list of lines, not something
-- a page can ask "has this row been decided about?".
--
-- -----------------------------------------------------------------------------
-- IT HOLDS NO TEXT, AND THAT IS A RULE RATHER THAN AN OVERSIGHT
-- -----------------------------------------------------------------------------
-- No copy of the words being judged, and no typed reason. #710 refused a
-- free-text reason box for exactly this: asking somebody to describe what they
-- did invites them to quote the erased words into a record that is never
-- erased. A decision is a choice from two, and the row says which row was
-- judged, what was decided and who decided.
--
-- -----------------------------------------------------------------------------
-- WHY EVERY PART OF THE UNIQUE KEY IS `NOT NULL`
-- -----------------------------------------------------------------------------
-- #487: in MySQL and MariaDB a unique key STANDS ASIDE for any row where any
-- part of it is empty, so a key with a nullable part is a rule only for rows
-- that fill in every column of it — and `ON DUPLICATE KEY UPDATE` then stops
-- meaning "change the existing row" and starts meaning "insert another one".
-- `uq_errd_item` is what makes deciding the same line twice replace the
-- decision instead of adding a second, so all four of its columns are
-- NOT NULL. `decidedByID` is outside the key and nullable on purpose: it is
-- left empty when the person deciding is the person being erased, and the
-- foreign key empties it if that account is ever deleted outright.
--
-- ⚠️ AND THE REASON FOR THAT IS NOT THE ONE THIS HEADER FIRST GAVE. It said
-- "writing their number into a trail that is never erased would put it
-- straight back", which named the WRONG TABLE: `tblErasureAudit` is the trail
-- that is never erased (#510 rule 7d keeps it out of the personal-data
-- catalogue altogether). THIS table has a catalogue entry — `unlink` on
-- `decidedByID` — so an erasure of the person who decided does clear it. The
-- real reason is narrower and still worth having: between the decision and a
-- completed run the number sits on the row, and a request CANCELLED rather
-- than completed leaves it there for good.
--
-- ⚠️ IT WAS ALSO NOT TRUE OF THE CODE UNTIL 3 NOVEMBER 2026. The audit line
-- had always masked the number; `record()` bound the raw value into the row.
-- Measured, not read: a decision taken by the subject stored their own
-- account number. A comment promising a guarantee the code has not
-- implemented, in the reassuring direction, in a privacy feature, is the
-- shape this repository keeps being burned by — so the CODE was changed to
-- match this promise rather than the promise softened.
--
-- -----------------------------------------------------------------------------
-- NOTHING HAD TO BE MADE NULLABLE, AND HERE IS THE MEASUREMENT
-- -----------------------------------------------------------------------------
-- "Empty it" sets the column to NULL, and A NOT NULL COLUMN CANNOT BE EMPTIED
-- — the attempt fails quietly, which is #715's lesson and the reason
-- `tools/audit-checks/check_erasure_columns_nullable.py` exists. All seven
-- reviewable columns were read out of a loaded MySQL 8.0.36 database (not out
-- of the schema file) and every one is already nullable:
--
--   tblResourceBooking.notes              TEXT         NULL
--   tblServicePlanItem.presenterText      VARCHAR(255) NULL
--   tblRecording.presenterText            VARCHAR(255) NULL
--   tblAssetLicenseAssignments.deviceName VARCHAR(255) NULL
--   tblAssetLicenseAssignments.seatLabel  VARCHAR(100) NULL
--   tblSmallGroups.meetingNotes           VARCHAR(500) NULL
--   tblSalvationCards.prayerRequest       VARCHAR(1000) NULL
--
-- So this migration relaxes nothing. `check_erasure_review_items.py` is what
-- keeps it that way: it fails if a reviewable column is ever declared NOT
-- NULL, and `ErasureReview::record()` reads the cell back after emptying it so
-- that a database which disagrees with the install script is caught at the
-- moment it matters rather than recorded as a success.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- `CREATE TABLE IF NOT EXISTS` is standard MySQL and MariaDB (it is only
-- `IF [NOT] EXISTS` on a COLUMN or an INDEX that MySQL 8 rejects). The two
-- route rows and this migration's own record both carry
-- `ON DUPLICATE KEY UPDATE`, which matters because the installer replays every
-- numbered migration after loading `full_schema.sql`, ignoring what
-- `tblMigrations` already says.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/724
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblErasureReviewDecisions` (
    `decisionID`   INT          NOT NULL AUTO_INCREMENT,
    `requestID`    INT          NOT NULL COMMENT 'The erasure request this decision was taken for. A later request about the same person gets a fresh judgement: a decision is about one request, not about a row for ever',
    `tableName`    VARCHAR(64)  NOT NULL COMMENT 'Which table the line is in. Written by the portal from its own catalogue, never from a request',
    `columnName`   VARCHAR(64)  NOT NULL COMMENT 'Which column. Only a column marked `needsReview` in _core/personal-data-catalogue.php can appear here',
    `rowKey`       INT          NOT NULL COMMENT 'The row''s own key value. The key COLUMN is read from information_schema, so no second list of key columns can drift from the schema',
    `decision`     ENUM('empty','keep') NOT NULL COMMENT 'The two answers, and there is no third. An ENUM so the database refuses anything else, and so that no free text can ever reach this column',
    `decidedByID`  INT          DEFAULT NULL COMMENT 'Who decided, or EMPTY when the decider is the person being erased - ErasureReview::record() leaves it out under the same test that masks the audit line. This table IS erased (its catalogue entry unlinks this column), so the reason is not that the record is permanent: it is that the number would sit on the row from the decision until the run nulled it, and a CANCELLED request would leave it there for good',
    `decidedAtUtc` DATETIME     NOT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP() only - never a time on the server clock, so two installations in different countries agree about the order of things',
    PRIMARY KEY (`decisionID`),
    UNIQUE KEY `uq_errd_item` (`requestID`, `tableName`, `columnName`, `rowKey`),
    KEY `idx_errd_request` (`requestID`),
    CONSTRAINT `fk_errd_request` FOREIGN KEY (`requestID`) REFERENCES `tblErasureRequest`(`requestID`) ON DELETE CASCADE,
    CONSTRAINT `fk_errd_decider` FOREIGN KEY (`decidedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='One line of free text a person looked at during an erasure, and which of the two answers they gave. Holds no text of its own (#724)';

-- ── The review page, and the handler that records one decision ───────────────
-- `isProtected` = 1: both need a sign-in, and the pages themselves then ask
-- `Roles::has()` for the `data_controller` role, which is the ONE gate in this
-- portal that does NOT accept a global administrator without a role row.
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/erasure-requests/review',      'admin/erasure/review.php',      1),
    ('admin/erasure-requests/review-save', 'admin/erasure/review-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('242_erasure_review.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
