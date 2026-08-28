-- =============================================================================
-- Migration 172: Giving — bulk year-end statements (gap #4, #440)
-- =============================================================================
-- Treasurer-facing "Annual statements" batch generate + email at
-- /giving/statements, reusing (and hardening) the self-service PDF renderer
-- Portal\Core\Giving::renderStatementPdf() so both paths emit byte-identical
-- output. See .claude/plans/gap-items/04-bulk-statements-plan.md for the
-- full design; this migration ships only the dedupe/log table, three new
-- settings, and the four/five route rows — no changes to existing tables.
--
-- Q1 (period) — calendar-year default + UK-tax-year preset button (client-
-- side only, no schema impact). Q2 — statement shows "Gift Aid eligible"
-- total only, never a projected 25% reclaim figure. Q3 — donors who have
-- left the site (no active tblUserSites row) still appear in the preview
-- and can be downloaded individually by the treasurer, but are never
-- auto-emailed. Q4 — an explicit, audit-logged "resend" override exists.
-- Q5 — GDPR erasure additionally unlinks that donor's rendered statement
-- PDFs (Portal\Core\GdprEraser — no schema change needed for this, it's a
-- filesystem sweep). Q6 — donor-less/free-text gifts are out of scope;
-- the UI surfaces only an informational excluded-count line for them.
--
-- ONE new table: `tblGivingStatementLog` — one row per (siteID, donorID,
-- periodKey), UNIQUE-keyed so re-running "Generate"/"Email" is naturally
-- idempotent (the row IS the run state — see column comments). Plain
-- `CREATE TABLE IF NOT EXISTS`, zero guarded ALTERs, so
-- check_mariadb_only_ddl.py / check_migration_idempotency.py are green by
-- construction.
--
-- THREE new settings (global defaults, siteID NULL):
--   giving.statements.batchPerRun   — synchronous per-request cap, mirrors
--     newsletter.batchPerHour (DreamHost FastCGI can't run an unbounded
--     dompdf+Mailer loop in one request).
--   giving.statements.emailSubject  — {year}/{period}/{charity} placeholder
--     template for the per-donor email subject.
--   giving.cron_token — empty + isSensitive=1 (venues.cron_token /
--     assets.cron_token precedent): the optional sweeper
--     cron/giving-statements.php is INERT (always 403) until an admin sets
--     a real token, so it never accidentally emails anyone on a fresh
--     install.
--
-- FIVE new tblRoutes rows: the treasurer page + its three POST/GET handlers
-- (isProtected=1 — Giving::canManage() gates every one of them) plus the
-- optional cron sweeper (isProtected=0, public URL — the endpoint checks
-- its own token; cron/venue-reminders precedent).
--
-- NOTE for editors: never put a literal "--" inside a COMMENT/string in
-- this file — check_schema_seed_parity.py strips from any "--" to
-- end-of-line, even inside quotes. Use an em/en dash instead.
--
-- @package    Portal
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/440
-- =============================================================================


-- #############################################################################
-- 🗄️ TABLE
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 🧾 tblGivingStatementLog — dedupe/audit log for bulk year-end statements.
-- One row per (siteID, donorID, periodKey); the row IS the run state:
--   pdfPath   set        → PDF rendered (namespaced by siteID+periodKey —
--                           fixes the cross-site filename overwrite the old
--                           self-service-only renderer had).
--   queuedAt  set        → treasurer has started an email run for this row.
--   emailedAt set        → dedupe fence: a re-run selector always adds
--                           `emailedAt IS NULL`, so a completed row can
--                           never be picked up again without the explicit,
--                           audit-logged "resend" override (which clears
--                           both emailedAt and errorMsg first).
--   errorMsg  set        → last generate/send attempt failed; surfaced in
--                           the preview with a manual "retry" (never
--                           auto-retried — see the plan's security item 9).
-- `donorID` is NOT NULL (a statement without a member to send it to makes
-- no sense — donor-less/free-text gifts are Q6 out-of-scope) with
-- ON DELETE CASCADE so a hard-deleted user (rare — most erasures anonymise
-- tblUsers in place rather than delete the row) can never leave an orphaned
-- log row behind.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblGivingStatementLog` (
    `logID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `donorID`      INT          NOT NULL,
    `periodKey`    VARCHAR(30)  NOT NULL COMMENT 'fromDate_toDate — Giving::statementPeriodKey()',
    `fromDate`     DATE         NOT NULL,
    `toDate`       DATE         NOT NULL,
    `totalPence`   INT          NOT NULL DEFAULT 0,
    `giftAidPence` INT          NOT NULL DEFAULT 0,
    `pdfPath`      VARCHAR(500) DEFAULT NULL COMMENT 'NULL until generated',
    `queuedAt`     DATETIME     DEFAULT NULL COMMENT 'Set when treasurer starts an email run; cron sweeps queued rows',
    `emailedAt`    DATETIME     DEFAULT NULL COMMENT 'Dedupe fence: re-runs skip rows with this set',
    `emailedTo`    VARCHAR(255) DEFAULT NULL COMMENT 'Address actually mailed (audit)',
    `errorMsg`     VARCHAR(255) DEFAULT NULL,
    `createdByID`  INT          DEFAULT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`logID`),
    UNIQUE KEY `uq_gsl_site_donor_period` (`siteID`, `donorID`, `periodKey`),
    KEY `idx_gsl_site_period` (`siteID`, `periodKey`),
    KEY `idx_gsl_queue` (`queuedAt`, `emailedAt`),
    CONSTRAINT `fk_gsl_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_gsl_donor`   FOREIGN KEY (`donorID`)     REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_gsl_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Giving — bulk year-end statement generate/email log + dedupe (gap #4, #440)';


-- #############################################################################
-- ⚙️ SETTINGS SEED
-- #############################################################################

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'giving.statements.batchPerRun',  '25', '25', 0),
    (NULL, 'giving.statements.emailSubject', 'Your {year} giving statement', 'Your {year} giving statement', 0),
    (NULL, 'giving.cron_token',              '', '', 1)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 🗺️ ROUTES SEED
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 4 treasurer-only routes (Giving::canManage() 403-gates every handler) +
-- the optional cron sweeper (public URL, token-gated internally — inert
-- until `giving.cron_token` is set, cron/venue-reminders precedent).
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('giving/statements',          'giving/statements.php',          1),
    ('giving/statements-generate', 'giving/statements-generate.php', 1),
    ('giving/statements-email',    'giving/statements-email.php',    1),
    ('giving/statements-download', 'giving/statements-download.php', 1),
    ('cron/giving-statements',     'cron/giving-statements.php',     0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- 📋 SELF-RECORD
-- #############################################################################

-- -----------------------------------------------------------------------------
-- Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('172_giving_bulk_statements.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
