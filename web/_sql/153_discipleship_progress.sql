-- =============================================================================
-- Migration 153: Discipleship — per-user progress + auto-completion (#303 Phase 2)
-- =============================================================================
-- Phase 1 (142) shipped pathway/step schema + admin CRUD, gated OFF by
-- default (`discipleship.enabled`). Phase 2 adds:
--   • tblPathwayEnrolments — who is assigned to which pathway (status
--     active/completed/withdrawn). Kept as an explicit table rather than
--     inferred from progress rows — "0 of N steps done" (member dashboard
--     card) and "who's stuck" (pastor roster) both need to represent a
--     member with ZERO completed steps, which a progress-only model can't.
--   • tblPathwayProgress — one row per (step, user) the member has
--     completed, manually or automatically. Unmark = REVOKE (`revokedAt`
--     set), NEVER a DELETE — a deleted row would let the auto-sweep
--     silently resurrect a step a pastor deliberately unmarked (the
--     UNIQUE(stepID, userID) row still exists, blocking re-insertion).
--     "Complete" everywhere in the app means `revokedAt IS NULL`.
--   • tblPathwaySteps.autoRule / autoRefID — optional auto-completion rule
--     per step (attended a specific event; attended any event in a
--     category; RSVP'd "going" to a now-past event). Guarded ADD COLUMN
--     per DEV_NOTES.md "Portable DDL convention (MySQL 8.0 ∩ MariaDB)" —
--     MySQL 8 rejects MariaDB's `IF NOT EXISTS` on ALTER with ERROR 1064
--     (house examples: migrations 037, 112, 138, 151).
--
-- Auto-completion sources (adopted, issue #303 blocker comment
-- 2026-06-21, option (a)): ONLY per-user evidence tables —
-- `tblEventAttendance` rows with `userID` NOT NULL, and `tblEventRSVPs`.
-- `tblSalvationCards` (no userID) and `tblDecisionMoments` (aggregate
-- counter, no per-user rows) are structurally excluded from this phase;
-- revisit in a later phase. Mentor relationships are deferred likewise
-- (no `tblPathwayMentor` schema, no UI).
--
-- Sweep engine: `Portal\Core\Discipleship::autoSweep()` runs three
-- set-based `INSERT IGNORE … SELECT` statements (one per autoRule),
-- idempotent via the UNIQUE(stepID, userID) key on tblPathwayProgress —
-- repeat runs insert nothing new. It has no scheduler dependency: it is
-- invoked LAZILY at the top of every discipleship page view (member +
-- admin), plus an optional cron endpoint below for freshness without any
-- page views at all.
--
-- Pastor surface: a per-pathway roster LIST (portal-data-list rows —
-- progress bar, n/m steps, last completion) with drill-down to a
-- per-member step list. NEVER a members×steps `<table>` matrix — the
-- house `<table>` ban plus issue #303's own blocker-comment decision 2.
--
-- New routes are normal page routes — NOT under `api/*` — so they DO
-- need tblRoutes rows (the "ApiRouter routing trap" in .claude/CLAUDE.md
-- doesn't apply to any of them, including the cron endpoint).
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/303
-- =============================================================================

-- 🧭 One row per (pathway, member) the pathway has been assigned to.
CREATE TABLE IF NOT EXISTS `tblPathwayEnrolments` (
    `enrolmentID`   INT      NOT NULL AUTO_INCREMENT,
    `siteID`        INT      NOT NULL,
    `pathwayID`     INT      NOT NULL,
    `userID`        INT      NOT NULL,
    `status`        ENUM('active','completed','withdrawn') NOT NULL DEFAULT 'active',
    `enrolledByID`  INT      DEFAULT NULL,
    `enrolledAt`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completedAt`   DATETIME DEFAULT NULL,
    `updatedAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`enrolmentID`),
    UNIQUE KEY `uq_enrolment_pathway_user` (`pathwayID`, `userID`),
    KEY `idx_enrolment_site_status` (`siteID`, `status`),
    KEY `idx_enrolment_user_status` (`userID`, `status`),
    CONSTRAINT `fk_enrolment_site`     FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_enrolment_pathway`  FOREIGN KEY (`pathwayID`)    REFERENCES `tblPathways`(`pathwayID`) ON DELETE CASCADE,
    CONSTRAINT `fk_enrolment_user`     FOREIGN KEY (`userID`)       REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_enrolment_enroller` FOREIGN KEY (`enrolledByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Member enrolment on a discipleship pathway (#303 Phase 2)';

-- ✅ One row per (step, member) completion — manual or auto-swept.
--    Unmark = revoke (revokedAt set), never DELETE — see header comment.
CREATE TABLE IF NOT EXISTS `tblPathwayProgress` (
    `progressID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL,
    `stepID`       INT          NOT NULL,
    `userID`       INT          NOT NULL,
    `source`       ENUM('auto','manual') NOT NULL DEFAULT 'manual',
    `markedByID`   INT          DEFAULT NULL COMMENT 'Coordinator who clicked complete; NULL for auto rows',
    `autoRef`      VARCHAR(100) DEFAULT NULL COMMENT 'Evidence reference, e.g. event:123 / category:7',
    `notes`        VARCHAR(500) DEFAULT NULL,
    `completedAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Evidence timestamp for auto rows',
    `revokedAt`    DATETIME     DEFAULT NULL COMMENT 'Set when a coordinator unmarks — row is kept, never deleted',
    `revokedByID`  INT          DEFAULT NULL,
    PRIMARY KEY (`progressID`),
    UNIQUE KEY `uq_progress_step_user` (`stepID`, `userID`),
    KEY `idx_progress_user` (`userID`),
    KEY `idx_progress_site` (`siteID`),
    CONSTRAINT `fk_progress_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_progress_step`    FOREIGN KEY (`stepID`)      REFERENCES `tblPathwaySteps`(`stepID`) ON DELETE CASCADE,
    CONSTRAINT `fk_progress_user`    FOREIGN KEY (`userID`)      REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_progress_marker`  FOREIGN KEY (`markedByID`)  REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_progress_revoker` FOREIGN KEY (`revokedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Per-member step completion — revoke, never delete (#303 Phase 2)';

-- -----------------------------------------------------------------------------
-- 🧩 tblPathwaySteps auto-completion rule columns — guarded per DEV_NOTES.md
-- "Portable DDL convention (MySQL 8.0 ∩ MariaDB)". MySQL 8 rejects MariaDB's
-- `IF [NOT] EXISTS` on ALTER with ERROR 1064, so every ALTER below goes
-- through the information_schema + PREPARE/EXECUTE guard idiom (house
-- examples: migrations 037, 112, 138, 151).
-- -----------------------------------------------------------------------------

-- ➕ tblPathwaySteps.autoRule — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblPathwaySteps'
      AND COLUMN_NAME  = 'autoRule'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPathwaySteps` ADD COLUMN `autoRule` ENUM(''none'',''attended_event'',''attended_category'',''rsvpd_event'') NOT NULL DEFAULT ''none'' COMMENT ''Auto-completion rule (#303 Phase 2)'' AFTER `isOptional`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblPathwaySteps.autoRefID — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblPathwaySteps'
      AND COLUMN_NAME  = 'autoRefID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPathwaySteps` ADD COLUMN `autoRefID` INT DEFAULT NULL COMMENT ''eventID (attended_event/rsvpd_event) or categoryID (attended_category); polymorphic, validated in PHP, deliberately no FK (#303 Phase 2)'' AFTER `autoRule`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📋 Page routes (NOT api/* — normal page routes, so they DO need tblRoutes
--    rows; see the "ApiRouter routing trap" note in .claude/CLAUDE.md).
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('discipleship',                          'discipleship/index.php',                 1),
    ('discipleship/view',                     'discipleship/view.php',                  1),
    ('admin/discipleship/progress',           'admin/discipleship/progress.php',        1),
    ('admin/discipleship/progress/pathway',   'admin/discipleship/progress-pathway.php',1),
    ('admin/discipleship/progress/member',    'admin/discipleship/progress-member.php', 1),
    ('admin/discipleship/enrol',              'admin/discipleship/enrol-save.php',      1),
    ('admin/discipleship/progress/mark',      'admin/discipleship/progress-mark.php',   1),
    ('cron/discipleship-sweep',               'cron/discipleship-sweep.php',             0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Settings — dashboard-card display metadata (fixes the dead dashboard
--    link: enabling `discipleship.enabled` today links a card to
--    `/discipleship`, which Phase 1 never seeded a route for) + the cron
--    shared-secret token (empty ⇒ 403, same semantics as
--    `reminders.cron_token` / 122_event_reminders.sql).
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`) VALUES
    ('discipleship.displayName', 'Discipleship',      0, 'Discipleship'),
    ('discipleship.displayIcon', 'fa-solid fa-route', 0, 'fa-solid fa-route'),
    ('discipleship.brandColor',  '#a855f7',            0, '#a855f7'),
    ('discipleship.cron_token',  '',                   1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('153_discipleship_progress.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
