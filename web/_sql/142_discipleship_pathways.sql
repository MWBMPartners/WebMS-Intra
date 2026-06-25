-- =============================================================================
-- Migration 142: Discipleship Pathway Tracker — Phase 1 of #303
-- =============================================================================
-- Pastoral / membership-org tool that lets a site define one or more
-- discipleship "pathways" (e.g. "New believer 101", "Leadership track") as
-- ordered, optionally-marked step lists. Phase 1 ships the foundational
-- schema + admin CRUD + a setting gate so the app is OFF by default and is
-- only loaded when an admin opts in. Phase 2 will layer per-user progress,
-- mentor relationships, auto-completion rules, member-facing routes and a
-- pastor dashboard on top of these primitives.
--
-- Design discipline (matches 117, 137, 141):
--   • siteID is INT NOT NULL and immediately follows the PK; ON DELETE CASCADE
--     so wiping a site cleans every pathway it owned.
--   • Child rows (steps) FK to parent with ON DELETE CASCADE so deleting a
--     pathway cleans its step list atomically.
--   • sortOrder INT NOT NULL DEFAULT 0 on the child; (pathwayID, sortOrder)
--     compound index for ordered retrieval.
--   • CREATE TABLE IF NOT EXISTS + INSERT … ON DUPLICATE KEY UPDATE so the
--     migration is fully idempotent (re-runnable on a partially-migrated DB).
--   • ENGINE=InnoDB, utf8mb4 / utf8mb4_general_ci across the board.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/303
-- =============================================================================

-- 📖 Parent table — one row per pathway, scoped to a site.
CREATE TABLE IF NOT EXISTS `tblPathways` (
    `pathwayID`    INT           NOT NULL AUTO_INCREMENT,
    `siteID`       INT           NOT NULL,
    `name`         VARCHAR(120)  NOT NULL
                                 COMMENT 'Pathway label shown to admins and (Phase 2+) members',
    `description`  VARCHAR(1000) DEFAULT NULL
                                 COMMENT 'Operator-facing summary of who this pathway is for',
    `isActive`     TINYINT(1)    NOT NULL DEFAULT 1
                                 COMMENT 'Soft-delete flag; inactive pathways hidden from selectors',
    `createdByID`  INT           DEFAULT NULL,
    `createdAt`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`pathwayID`),
    KEY `idx_pathway_site_active` (`siteID`, `isActive`, `updatedAt`),
    CONSTRAINT `fk_pathway_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_pathway_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 🚶 Child table — ordered steps within a pathway.
CREATE TABLE IF NOT EXISTS `tblPathwaySteps` (
    `stepID`          INT           NOT NULL AUTO_INCREMENT,
    `pathwayID`       INT           NOT NULL,
    `sortOrder`       INT           NOT NULL DEFAULT 0,
    `name`            VARCHAR(255)  NOT NULL
                                    COMMENT 'Short step title (e.g. "Attend welcome class")',
    `description`     VARCHAR(1000) DEFAULT NULL
                                    COMMENT 'Longer explanation of the step, shown to the member (Phase 2+)',
    `completionHint`  VARCHAR(500)  DEFAULT NULL
                                    COMMENT 'How a coordinator should mark this complete (e.g. "Tick when baptised")',
    `isOptional`      TINYINT(1)    NOT NULL DEFAULT 0
                                    COMMENT 'If 1, completion does not block pathway completion in Phase 2',
    `createdAt`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`stepID`),
    KEY `idx_pathstep_pathway_sort` (`pathwayID`, `sortOrder`),
    CONSTRAINT `fk_pathstep_pathway` FOREIGN KEY (`pathwayID`) REFERENCES `tblPathways`(`pathwayID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 🎛️ Feature gate — discipleship.enabled defaults to 'false' so the app is
--    invisible until an admin flips it on via /admin/settings.
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('discipleship.enabled', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 🔗 Routes — list, new (form), save (create/update), edit (form), delete,
--    + step save (create/update) and step delete. Seven admin routes total
--    — all behind requireLogin + App::isAdmin() in the handlers themselves.
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/discipleship/pathways',             'admin/discipleship/pathways.php',             1),
    ('admin/discipleship/pathways/new',         'admin/discipleship/pathway-form.php',         1),
    ('admin/discipleship/pathways/edit',        'admin/discipleship/pathway-form.php',         1),
    ('admin/discipleship/pathways/save',        'admin/discipleship/pathway-save.php',         1),
    ('admin/discipleship/pathways/delete',      'admin/discipleship/pathway-delete.php',       1),
    ('admin/discipleship/pathways/step/save',   'admin/discipleship/step-save.php',            1),
    ('admin/discipleship/pathways/step/delete', 'admin/discipleship/step-delete.php',          1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
