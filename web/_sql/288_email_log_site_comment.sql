-- =============================================================================
-- Migration 288: the send log's organisation column says what it holds (#1040)
-- =============================================================================
-- What this changes:
--
--   A. The COMMENT on `tblEmailLog`.`siteID`, and nothing else. The column's
--      type, its empty default, its key and its link to `tblSites` stay
--      exactly as they are; no row is touched.
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- The comment written by migration 176 said "NULL when sent outside site
-- context (e.g. cron)". Since #683 and #1006 that is untrue: a message the
-- scheduled mail job sends from the queue is recorded under the queue row's
-- own organisation (`Mailer::queueRowSite()`), whichever route writes the line,
-- and a scheduled job sending at once records under the organisation it has
-- moved to with `Site::forceContext()`. A column comment is what a person
-- reading the database is told; a wrong one sends them looking for scheduled
-- sends among the empty rows, where they are not.
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `MODIFY COLUMN` with the column's whole definition restated word for word
-- as `full_schema.sql` holds it, apart from the comment. SAFE TO RUN TWICE: a
-- second run sets the same definition again and changes nothing. No
-- `IF EXISTS` on the column (MySQL 8 rejects MariaDB's form; see
-- .claude/rules/database.md). The type is unchanged, which is what lets MySQL
-- and MariaDB modify a column that a foreign key (`fk_emaillog_site`) uses.
-- The table itself exists on every database this can run on: migration 176
-- creates it, and the installer's install script holds it.
-- =============================================================================

ALTER TABLE `tblEmailLog`
    MODIFY COLUMN `siteID` INT DEFAULT NULL COMMENT 'The organisation the message was for. Sent at once: the organisation the sending request is working for (Site::id(), which a scheduled job moves with Site::forceContext() while it works for one). Sent later by the mail job from the queue: the queue row''s own organisation, whichever route writes the line (Mailer::queueRowSite(), #683, #1006). NULL when a queued message was kept for no organisation, or when the organisation has since been deleted (ON DELETE SET NULL)';

INSERT INTO `tblMigrations` (`filename`) VALUES ('288_email_log_site_comment.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
