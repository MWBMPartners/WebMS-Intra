-- =============================================================================
-- Migration 103: Off-site backup sync log (#249)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblOffsiteSyncLog` (
    `logID`       INT          NOT NULL AUTO_INCREMENT,
    `runAt`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `triggeredBy` VARCHAR(50)  NOT NULL DEFAULT 'cron' COMMENT 'cron / admin-userID',
    `destination` VARCHAR(50)  NOT NULL,
    `snapshotName` VARCHAR(255) DEFAULT NULL,
    `bundleSize`  BIGINT       DEFAULT NULL,
    `durationSec` INT          DEFAULT NULL,
    `status`      ENUM('success','failed','skipped') NOT NULL,
    `errorMsg`    VARCHAR(500) DEFAULT NULL,
    `output`      MEDIUMTEXT   DEFAULT NULL,
    PRIMARY KEY (`logID`),
    KEY `idx_osl_run` (`runAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/maintenance/offsite-backup',     'admin/maintenance/offsite-backup.php',     1),
    ('admin/maintenance/offsite-backup/run', 'admin/maintenance/offsite-backup-run.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'backup.offsite.enabled',     '0', '0', 0),
    (NULL, 'backup.offsite.destination', 'rclone', 'rclone', 0),
    (NULL, 'backup.offsite.rcloneRemote', '', '', 0),
    (NULL, 'backup.offsite.keepWeekly',  '8', '8', 0),
    (NULL, 'backup.offsite.keepMonthly', '12', '12', 0),
    (NULL, 'backup.offsite.alertEmail',  '', '', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
