-- =============================================================================
-- Migration 112: Events Calendar easy-wins bundle from The Events Calendar audit
-- =============================================================================
-- Seven small extensions to the already-shipped Calendar/Events apps, bundled
-- per the user's single-PR preference. Each ships the MVP slice of a larger
-- "for consideration" issue.
--
-- #326 — Public "Submit an Event" form with moderation queue.
-- #328 — Schema.org JSON-LD on event detail (no schema impact; PHP only).
-- #331 — Photo view of /calendar (no schema impact; view template only).
-- #334 — Guest +N + waitlist on tblEventRSVPs.
-- #337 — Cancellation/postponement audit columns + broadcast banner.
-- #338 — VTIMEZONE + RRULE emission in iCal (Ical.php changes; no schema).
-- #339 — Per-site unique event slug.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/326
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/331
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/334
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/337
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/339
-- =============================================================================

-- #326 — Public "Submit an Event" submission tracking columns ------------------

-- ➕ tblEvents.submissionStatus — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'submissionStatus'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `submissionStatus` ENUM(''pending'',''approved'',''rejected'') DEFAULT NULL COMMENT ''NULL = admin-created (legacy); non-NULL = went through public submission moderation (#326)'' AFTER `isFeatured`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.submittedByID — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'submittedByID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `submittedByID` INT DEFAULT NULL COMMENT ''FK → tblUsers when submitter logged in; NULL for anonymous submissions'' AFTER `submissionStatus`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.submitterName — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'submitterName'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `submitterName` VARCHAR(120) DEFAULT NULL COMMENT ''Anonymous submitter''''s display name'' AFTER `submittedByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.submitterEmail — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'submitterEmail'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `submitterEmail` VARCHAR(255) DEFAULT NULL COMMENT ''Anonymous submitter''''s contact email (for moderation response)'' AFTER `submitterName`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.submittedAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'submittedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `submittedAt` DATETIME DEFAULT NULL COMMENT ''When the submission was created'' AFTER `submitterEmail`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.moderatedByID — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'moderatedByID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `moderatedByID` INT DEFAULT NULL COMMENT ''FK → tblUsers — who approved/rejected the submission'' AFTER `submittedAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.moderatedAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'moderatedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `moderatedAt` DATETIME DEFAULT NULL AFTER `moderatedByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.moderationNote — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'moderationNote'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `moderationNote` TEXT DEFAULT NULL COMMENT ''Internal note recorded at moderation time'' AFTER `moderatedAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔍 idx_event_submission — guarded ADD INDEX
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND INDEX_NAME   = 'idx_event_submission'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEvents` ADD INDEX `idx_event_submission` (`submissionStatus`, `submittedAt`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/submit',                  'calendar/submit.php',           0),
    ('calendar/submit-save',             'calendar/submit-save.php',      0),
    ('admin/calendar/moderation',        'admin/calendar/moderation.php', 1),
    ('admin/calendar/moderate',          'admin/calendar/moderate.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    -- Master kill switch for public event submission.
    (NULL, 'calendar.publicSubmit.enabled',        'true',  'true',  0),
    -- Allow non-logged-in users to submit (captcha-protected).
    (NULL, 'calendar.publicSubmit.allowAnonymous', 'true',  'true',  0),
    -- Require captcha on the anonymous form (multi-provider stack from #130).
    (NULL, 'calendar.publicSubmit.requireCaptcha', 'true',  'true',  0),
    -- Email the submitter when their submission is approved/rejected.
    (NULL, 'calendar.publicSubmit.notifySubmitter','true',  'true',  0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #331 — Photo view route -----------------------------------------------------

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    -- Photo view: image-grid layout for image-rich events. Joins the existing
    -- /calendar?view=day|week|weekdays|weekend|month|year|list family.
    ('calendar/views/photo', 'calendar/views/photo.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- #334 — Guest +N + waitlist on RSVPs ----------------------------------------

-- ➕ tblEventRSVPs.guestCount — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventRSVPs'
      AND COLUMN_NAME  = 'guestCount'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventRSVPs` ADD COLUMN `guestCount` INT NOT NULL DEFAULT 0 COMMENT ''Number of +N guests in addition to the responder (#334)'' AFTER `response`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEventRSVPs.status — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventRSVPs'
      AND COLUMN_NAME  = 'status'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventRSVPs` ADD COLUMN `status` ENUM(''confirmed'',''waitlist'',''cancelled'') NOT NULL DEFAULT ''confirmed'' COMMENT ''#334 waitlist support — confirmed = within capacity; waitlist = beyond capacity'' AFTER `guestCount`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEventRSVPs.waitlistedAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventRSVPs'
      AND COLUMN_NAME  = 'waitlistedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventRSVPs` ADD COLUMN `waitlistedAt` DATETIME DEFAULT NULL COMMENT ''Stamped when moved to waitlist (auto-promote chronological)'' AFTER `status`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔍 idx_event_rsvp_status — guarded ADD INDEX
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventRSVPs'
      AND INDEX_NAME   = 'idx_event_rsvp_status'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEventRSVPs` ADD INDEX `idx_event_rsvp_status` (`eventID`, `status`, `createdAt`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #337 — Cancellation/postponement audit columns ------------------------------
-- (status ENUM already has 'cancelled' and 'postponed' values — see migration 008.)

-- ➕ tblEvents.cancelReason — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'cancelReason'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `cancelReason` TEXT DEFAULT NULL COMMENT ''Reason shown on the cancellation banner (#337)'' AFTER `isFeatured`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.statusChangedByID — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'statusChangedByID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `statusChangedByID` INT DEFAULT NULL COMMENT ''FK → tblUsers — who last flipped status'' AFTER `cancelReason`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.statusChangedAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'statusChangedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `statusChangedAt` DATETIME DEFAULT NULL COMMENT ''When the current status was set'' AFTER `statusChangedByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #339 — Per-site unique event slug ------------------------------------------
-- Replace the global uq_event_slug with a per-site composite. We add the new
-- index first to avoid a window where slugs are non-unique on busy installs.

-- 🔍 uq_event_site_slug — guarded ADD UNIQUE KEY
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND INDEX_NAME   = 'uq_event_site_slug'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEvents` ADD UNIQUE KEY `uq_event_site_slug` (`siteID`, `eventSlug`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- On pre-#339 databases the global unique key still exists; drop it only if
-- present so re-runs (and post-fix fresh installs, where full_schema.sql now
-- creates uq_event_site_slug directly) are clean no-ops.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblEvents'
      AND INDEX_NAME = 'uq_event_slug'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE `tblEvents` DROP INDEX `uq_event_slug`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
