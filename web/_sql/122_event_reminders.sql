-- =============================================================================
-- Migration 122: Event lifecycle email reminders (#329)
-- =============================================================================
-- Cron-driven reminder scheduler. Sends three reminder types per event:
--   24h  — to every confirmed RSVP, 24 hours before startDateTime
--   1h   — to every confirmed RSVP, 1 hour before startDateTime
--   day  — daily 07:00 summary to coordinator + admin, listing today's
--          events with attendance counts + manage links
--
-- tblEventReminderLog rows are per (eventID, reminderType) so we never
-- double-send. The cron endpoint is /cron/event-reminders?key=<secret>;
-- the shared secret lives in tblSettings ('reminders.cron_token').
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/329
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblEventReminderLog` (
    `reminderID`      INT NOT NULL AUTO_INCREMENT,
    `eventID`         INT NOT NULL,
    `reminderType`    ENUM('24h','1h','day') NOT NULL,
    `recipientCount`  INT NOT NULL DEFAULT 0,
    `sentAt`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`reminderID`),
    UNIQUE KEY `uq_reminder` (`eventID`, `reminderType`),
    KEY `idx_reminder_sent` (`sentAt`),
    CONSTRAINT `fk_reminder_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`) VALUES
    ('reminders.enabled',    '1', 0),
    ('reminders.cron_token', '', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    -- Public (token-protected) cron endpoint.
    ('cron/event-reminders',        'cron/event-reminders.php',          0),
    -- Admin status page.
    ('admin/calendar/reminders',    'admin/calendar/reminders.php',      1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
