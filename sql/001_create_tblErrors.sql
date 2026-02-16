-- =============================================================================
-- Migration: 001_create_tblErrors.sql
-- Purpose:   Creates the tblErrors table for centralised error/warning/notice
--            logging from PHP, MySQL, external libraries and third-party APIs.
--            Referenced by core/Logger.php::errorPlatform().
-- Author:    MWBM Partners Ltd (t/a MWservices)
-- Copyright: 2025-present MWBM Partners Ltd
-- See:       https://www.php.net/manual/en/errorfunc.constants.php
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblErrors` (
    `errorID`        INT           NOT NULL AUTO_INCREMENT COMMENT 'Unique error record identifier',
    `errorPlatform`  VARCHAR(50)   NOT NULL DEFAULT 'PHP'  COMMENT 'Platform/tool/library where error occurred (PHP, MySQL, dompdf, MS365, Google, cURL, JavaScript etc)',
    `errorSeverity`  VARCHAR(50)   NOT NULL DEFAULT 'Error' COMMENT 'Severity level: Notification, Warning, Error, Fatal etc',
    `errorCode`      VARCHAR(100)  DEFAULT NULL             COMMENT 'Platform-specific error code (e.g. PHP errno, HTTP status)',
    `errorTitle`     VARCHAR(500)  DEFAULT NULL             COMMENT 'Short error description / title returned by the platform',
    `errorDetail`    LONGTEXT      DEFAULT NULL             COMMENT 'Full error detail including file, line, backtrace etc',
    `userID`         INT           DEFAULT NULL             COMMENT 'UserID of the logged-in user when error occurred (NULL if anonymous)',
    `visitorIP`      VARCHAR(100)  DEFAULT NULL             COMMENT 'Client IP address (respects CF-Connecting-IP / X-Forwarded-For)',
    `userAgent`      TEXT          DEFAULT NULL             COMMENT 'Browser/client user-agent string',
    `requestURL`     VARCHAR(2048) DEFAULT NULL             COMMENT 'Full request URI that triggered the error',
    `requestHeaders` LONGTEXT      DEFAULT NULL             COMMENT 'JSON-encoded request headers for debugging context',
    `isResolved`     TINYINT(1)    NOT NULL DEFAULT 0       COMMENT 'Whether this error has been reviewed/resolved by an admin',
    `resolvedAt`     DATETIME      DEFAULT NULL             COMMENT 'Timestamp when the error was marked as resolved',
    `resolvedByID`   INT           DEFAULT NULL             COMMENT 'UserID of the admin who resolved this error',
    `createdAt`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when the error was logged',
    PRIMARY KEY (`errorID`),
    KEY `idx_errors_platform`  (`errorPlatform`),
    KEY `idx_errors_severity`  (`errorSeverity`),
    KEY `idx_errors_created`   (`createdAt`),
    KEY `idx_errors_resolved`  (`isResolved`),
    KEY `idx_errors_user`      (`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Centralised error log for all platforms/libraries. See core/Logger.php.';
