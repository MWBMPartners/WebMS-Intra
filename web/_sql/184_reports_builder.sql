-- =============================================================================
-- Migration 184: Reports Builder — saved report definitions (#156)
-- =============================================================================
-- A saved report is a STRUCTURED DEFINITION (registry keys + literal filter
-- values), never SQL. Portal\Core\ReportBuilder::compile() re-validates the
-- JSON against the PHP-code whitelist registry (Portal\Core\ReportRegistry)
-- on EVERY run, so a row hand-edited to contain an unknown key fails
-- closed. `definition` never contains SQL text, a table name, or a siteID
-- (tenant scope is force-injected from the caller's session). Report
-- OUTPUT is never stored anywhere — only lastRunAt/runCount metadata.
--
-- DEVIATION from issue #156's literal wording: the issue names the table
-- `tblReports`, which never existed in any prior migration and is a
-- generically collision-prone name; this migration uses the
-- self-describing `tblReportDefinitions` instead (see the build spec's
-- Ambiguity A4 — approved default).
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/156
-- =============================================================================

-- #############################################################################
-- 🗃️ A. Table
-- #############################################################################

CREATE TABLE IF NOT EXISTS `tblReportDefinitions` (
    `reportID`    INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1 COMMENT 'FK to tblSites.siteID, owning tenant; a report never spans sites',
    `reportName`  VARCHAR(150) NOT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `sourceKey`   VARCHAR(50)  NOT NULL COMMENT 'ReportRegistry source key (denormalised from definition for listing); validated in PHP, never interpolated into SQL',
    `definition`  JSON         NOT NULL COMMENT 'Structured report definition: registry keys + bound filter values ONLY. No SQL fragments. Re-validated against the PHP whitelist registry on every run (#156)',
    `isShared`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = visible to every site admin of this site; 0 = author only',
    `createdByID` INT          DEFAULT NULL COMMENT 'FK to tblUsers; nulled by GdprEraser (authorship attribution detached)',
    `updatedByID` INT          DEFAULT NULL,
    `lastRunAt`   DATETIME     DEFAULT NULL,
    `runCount`    INT          NOT NULL DEFAULT 0,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`reportID`),
    KEY `idx_reportdef_site`  (`siteID`, `isShared`),
    KEY `idx_reportdef_owner` (`createdByID`),
    CONSTRAINT `fk_reportdef_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_reportdef_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_reportdef_updater` FOREIGN KEY (`updatedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- #############################################################################
-- ⚙️ B. Settings seed (3 keys)
-- #############################################################################
-- reports.enabled seeds 'true' -- the reports area (fixed dashboards at
-- /admin/reports since #93 + this builder at /admin/reports/builder/*)
-- becomes an AppRegistry-toggleable app; seeding ON preserves existing
-- behaviour on upgrade (worship/salvation/kids precedent, migration 158).
-- The two numeric caps are structural per-site guardrails read by
-- ReportBuilder::run()/streamCsv() (reads are ?? default-guarded anyway).

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'reports.enabled',          'true',  'true',  0),
    (NULL, 'reports.builder.maxRows',  '10000', '10000', 0),
    (NULL, 'reports.builder.pageSize', '50',    '50',    0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #############################################################################
-- 🗺️ C. Routes seed (7 -- all isProtected=1; every handler ALSO re-checks
--        App::isAdmin() itself, house belt-and-braces. No api/* routes here:
--        the preview endpoint is a session-authed AJAX POST, following the
--        geo/ AJAX-proxy precedent (#456) -- the ApiRouter routing trap
--        does not apply anywhere in this migration.)
-- #############################################################################

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/reports/builder',         'admin/reports/builder/index.php',   1),
    ('admin/reports/builder/edit',    'admin/reports/builder/edit.php',    1),
    ('admin/reports/builder/save',    'admin/reports/builder/save.php',    1),
    ('admin/reports/builder/preview', 'admin/reports/builder/preview.php', 1),
    ('admin/reports/builder/run',     'admin/reports/builder/run.php',     1),
    ('admin/reports/builder/export',  'admin/reports/builder/export.php',  1),
    ('admin/reports/builder/delete',  'admin/reports/builder/delete.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📖 Help Centre guide route (public-style, matches the help/expenses /
-- help/approvals precedent from migration 005 -- isProtected=0, the page
-- itself sits behind the normal portal login wall like every other /help/*
-- page).

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('help/reports', 'help/reports.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- #############################################################################
-- 📋 D. Self-record (installer replays every numbered migration after
--        full_schema.sql ignoring tblMigrations -- this INSERT is idempotent)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('184_reports_builder.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
