-- =============================================================================
-- Migration 238: an expense receipt is named by the portal, not by the uploader
--                (#714)
-- =============================================================================
-- One renamed column, and any row still carrying an uploaded name relabelled.
--
--   tblExpenseClaimFiles.originalFilename -> fileLabel
--
-- -----------------------------------------------------------------------------
-- WHAT WAS WRONG BEFORE
-- -----------------------------------------------------------------------------
-- `web/_apps/expenses/submit/save.php` stored the filename from the uploader's
-- own computer, `basename()`'d and nothing else. So "Jane Smith receipt.pdf"
-- was kept exactly as given and shown back on the claim.
--
-- AN ERASURE REQUEST COULD NOT REACH IT, and the reason is worth understanding
-- rather than guessing: a claim is kept for six years for HMRC and only has the
-- account number detached, so the row is never deleted and the `ON DELETE
-- CASCADE` on `claimID` never fires. #709's parent matching cannot help either,
-- because the parent is deliberately retained. The name would therefore have sat
-- there for six years after somebody asked to be forgotten.
--
-- AND IT WAS KEPT TWICE. The stored name was
-- `$claimID . '_' . time() . '_' . preg_replace(…, $orig)`, so the uploader's
-- name was also the name of the file ON DISK -- where no erasure reaches it
-- either, and where it shows up in any directory listing, any backup and any
-- off-site copy. The personal-data catalogue said the stored name was "made up
-- by the portal". It was derived from the claimant's.
--
-- -----------------------------------------------------------------------------
-- WHY A GENERATED NAME AT SUBMISSION, AND NOT A RENAME ON ERASURE
-- -----------------------------------------------------------------------------
-- The owner's decision, 3 November 2026: name the file generically when it is
-- submitted, so there is nothing to clear later and no link to break. Clearing
-- it on erasure would mean an erasure step that rewrites a retained financial
-- record, and a window -- however long somebody waits before asking -- in which
-- the name is there anyway.
--
-- Nothing is lost. `Claim_412_Receipt_1_2026-11-03.pdf` says which claim, which
-- attachment, and when: all a treasurer needs to tell one receipt from another,
-- and all HMRC needs beside the figures and the evidence itself. What HMRC
-- requires is the claim, not what the claimant called the file on their laptop.
--
-- The column is RENAMED because `originalFilename` would otherwise be a plain
-- untruth for every row. It was already half untrue: `ExpensePdf::generate()`
-- has always written a generated name (`Claim_412_Approved.pdf`) into it.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS CANNOT DO
-- -----------------------------------------------------------------------------
-- The relabelling below deals with the DATABASE. It does NOT rename the files
-- already on disk, whose names embed the uploaded name, and it cannot: this
-- migration is SQL and the files are outside the database. On an installation
-- that had received uploads, those names would have to be dealt with by hand.
-- There is no live installation of this portal yet (the owner, 2 November 2026),
-- so there are none -- but that is a fact about today, not a property of this
-- migration, and anybody replaying it onto a database with real uploads should
-- know it.
--
-- It also cannot recover the extension where the OLD LABEL never had a dot —
-- the `UPDATE` reads `fileLabel`, the uploaded name, and not `storedFilename`,
-- which is a distinction this whole package exists to draw. In that case the new
-- label has no extension rather than keeping part of the old name.
-- `stage IS NULL` is what tells an upload from a generated form.
--
-- -----------------------------------------------------------------------------
-- TWO TABLES HAVE THIS COLUMN, AND THE FIRST ATTEMPT RENAMED THE WRONG ONE
-- -----------------------------------------------------------------------------
-- `tblPhoto` also has an `originalFilename`, and THAT ONE KEEPS ITS MEANING:
-- `_apps/photos/serve-raw.php` hands it back as the download name, so a visitor
-- gets the file called what it was called. Whether a photo should keep an
-- uploader's filename is a separate question about a separate feature, and
-- nothing here answers it.
--
-- The first version of this migration renamed `tblPhoto`'s column and left
-- `tblExpenseClaimFiles`' alone. The coverage self-test caught it immediately
-- ("tblExpenseClaimFiles.fileLabel" does not exist), but nothing would have
-- caught the half that broke three working photo pages until they were opened.
--
-- BE EXACT ABOUT WHY, because a first write-up of this said the two
-- declarations "differ only in whitespace" and that is false:
--
--   tblExpenseClaimFiles:  `originalFilename` VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
--   tblPhoto:              `originalFilename`  VARCHAR(255) DEFAULT NULL,
--
-- They differ by a whole `COLLATE` clause, so they WERE distinguishable. The
-- mistake was that the search matched on the column NAME alone, where the two
-- are identical, and the difference never came into it. An independent check
-- caught the untruth, and it matters because the stated difference was the
-- evidence for the mistake.
--
-- That is the same conflation migration 236 was written to prevent one package
-- earlier -- two columns of one name in one codebase, one to be changed and one
-- not -- and knowing about it was not enough to avoid it. The lesson is the one
-- 236 drew: tell them apart by their TABLE, not by the column name, and check
-- which table a match landed in before replacing anything.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- The `information_schema` guard runs the rename only while the old column is
-- still there, the shape migrations 194, 201, 235 and 236 use (MySQL 8.0 has no
-- `IF EXISTS` for a column change). The relabelling is written so that running
-- it again changes nothing: a row already relabelled starts with `Claim_` and is
-- skipped.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/714
-- =============================================================================

SET @col_is_old := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblExpenseClaimFiles'
      AND COLUMN_NAME  = 'originalFilename'
);
SET @sql := IF(@col_is_old = 1,
    -- ⚠️ THE COMMENT MUST MATCH `full_schema.sql`'s WORD FOR WORD. A shorter one
    --    here gave an upgraded database a different comment from a fresh
    --    install, which is the drift `full_schema.sql`'s own note above
    --    `tblTranscript.startedAt` records happening once with migration 229 —
    --    and the end-to-end harness cannot see it, because `dump_inventory()`
    --    reads type, nullability, default and extra and NOT `COLUMN_COMMENT`.
    'ALTER TABLE `tblExpenseClaimFiles` CHANGE COLUMN `originalFilename` `fileLabel` VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT ''A name the PORTAL makes up, never the uploaders own (#714): Claim_<id>_Receipt_<n>_<date>.<ext> for an upload, Claim_<id>_<Stage>.pdf for a generated form. The claim is kept six years for HMRC, so a name taken from the claimant could not be erased.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🏷️ Any upload row still carrying a name that came from somebody's computer.
--
--    NOTE THE SHAPE IS NOT THE SAME as a new upload's. A new one is
--    `Claim_<id>_Receipt_<n>_<date>.<ext>`, where `<n>` counts receipts within
--    the claim; a relabelled one is `Claim_<id>_Receipt_<fileID>` with no date,
--    because neither the position within the claim nor the submission date can
--    be recovered from the row. Both are generic and neither names anybody,
--    which is the point — but they are two shapes, not one.
--    Only `stage IS NULL` rows: a row with a stage is a form the portal
--    generated and already has a made-up name. A row already relabelled begins
--    with `Claim_`, so a second run matches nothing.
UPDATE `tblExpenseClaimFiles`
   SET `fileLabel` = CONCAT(
           'Claim_', `claimID`, '_Receipt_', `fileID`,
           IF(LOCATE('.', `fileLabel`) > 0, CONCAT('.', SUBSTRING_INDEX(`fileLabel`, '.', -1)), '')
       )
 WHERE `stage` IS NULL
   AND `fileLabel` IS NOT NULL
   AND `fileLabel` NOT LIKE 'Claim\_%';

INSERT INTO `tblMigrations` (`filename`) VALUES ('238_expense_receipt_label.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
