-- =============================================================================
-- Migration: 000_create_migrations_table.sql
-- Purpose:   Creates the tblMigrations table used by the web-based Migrator
--            to track which SQL migration scripts have already been executed.
-- Author:    MWBM Partners Ltd (t/a MWservices)
-- Copyright: 2025-present MWBM Partners Ltd
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblMigrations` (
    `migrationID`   INT          NOT NULL AUTO_INCREMENT COMMENT 'Unique migration record identifier',
    `filename`      VARCHAR(255) NOT NULL                COMMENT 'Name of the SQL migration file executed',
    `executedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when migration was run',
    `executedByID`  INT          DEFAULT NULL             COMMENT 'UserID of the admin who triggered this migration',
    PRIMARY KEY (`migrationID`),
    UNIQUE KEY `uq_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Tracks executed SQL migrations to prevent re-running.';
