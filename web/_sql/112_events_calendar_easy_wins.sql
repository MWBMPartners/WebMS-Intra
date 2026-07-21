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

ALTER TABLE `tblEvents`
    ADD COLUMN IF NOT EXISTS `submissionStatus` ENUM('pending','approved','rejected') DEFAULT NULL
        COMMENT 'NULL = admin-created (legacy); non-NULL = went through public submission moderation (#326)' AFTER `isFeatured`,
    ADD COLUMN IF NOT EXISTS `submittedByID`   INT          DEFAULT NULL
        COMMENT 'FK → tblUsers when submitter logged in; NULL for anonymous submissions' AFTER `submissionStatus`,
    ADD COLUMN IF NOT EXISTS `submitterName`   VARCHAR(120) DEFAULT NULL
        COMMENT 'Anonymous submitter''s display name' AFTER `submittedByID`,
    ADD COLUMN IF NOT EXISTS `submitterEmail`  VARCHAR(255) DEFAULT NULL
        COMMENT 'Anonymous submitter''s contact email (for moderation response)' AFTER `submitterName`,
    ADD COLUMN IF NOT EXISTS `submittedAt`     DATETIME     DEFAULT NULL
        COMMENT 'When the submission was created' AFTER `submitterEmail`,
    ADD COLUMN IF NOT EXISTS `moderatedByID`   INT          DEFAULT NULL
        COMMENT 'FK → tblUsers — who approved/rejected the submission' AFTER `submittedAt`,
    ADD COLUMN IF NOT EXISTS `moderatedAt`     DATETIME     DEFAULT NULL AFTER `moderatedByID`,
    ADD COLUMN IF NOT EXISTS `moderationNote`  TEXT         DEFAULT NULL
        COMMENT 'Internal note recorded at moderation time' AFTER `moderatedAt`;

CREATE INDEX IF NOT EXISTS `idx_event_submission` ON `tblEvents`(`submissionStatus`, `submittedAt`);

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

ALTER TABLE `tblEventRSVPs`
    ADD COLUMN IF NOT EXISTS `guestCount` INT NOT NULL DEFAULT 0
        COMMENT 'Number of +N guests in addition to the responder (#334)' AFTER `response`,
    ADD COLUMN IF NOT EXISTS `status` ENUM('confirmed','waitlist','cancelled') NOT NULL DEFAULT 'confirmed'
        COMMENT '#334 waitlist support — confirmed = within capacity; waitlist = beyond capacity' AFTER `guestCount`,
    ADD COLUMN IF NOT EXISTS `waitlistedAt` DATETIME DEFAULT NULL
        COMMENT 'Stamped when moved to waitlist (auto-promote chronological)' AFTER `status`;

CREATE INDEX IF NOT EXISTS `idx_event_rsvp_status` ON `tblEventRSVPs`(`eventID`, `status`, `createdAt`);

-- #337 — Cancellation/postponement audit columns ------------------------------
-- (status ENUM already has 'cancelled' and 'postponed' values — see migration 008.)

ALTER TABLE `tblEvents`
    ADD COLUMN IF NOT EXISTS `cancelReason`   TEXT     DEFAULT NULL
        COMMENT 'Reason shown on the cancellation banner (#337)' AFTER `isFeatured`,
    ADD COLUMN IF NOT EXISTS `statusChangedByID` INT  DEFAULT NULL
        COMMENT 'FK → tblUsers — who last flipped status' AFTER `cancelReason`,
    ADD COLUMN IF NOT EXISTS `statusChangedAt`   DATETIME DEFAULT NULL
        COMMENT 'When the current status was set' AFTER `statusChangedByID`;

-- #339 — Per-site unique event slug ------------------------------------------
-- Replace the global uq_event_slug with a per-site composite. We add the new
-- index first to avoid a window where slugs are non-unique on busy installs.

CREATE UNIQUE INDEX IF NOT EXISTS `uq_event_site_slug` ON `tblEvents`(`siteID`, `eventSlug`);

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
