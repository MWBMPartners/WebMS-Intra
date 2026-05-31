-- =============================================================================
-- Migration 075: Praise Reports app (#260)
-- =============================================================================
-- Extends tblPrayerRequests with a `kind` column. Praise reports use the
-- same schema, lifecycle, and moderation flow — they're philosophically the
-- counterpart to prayer requests, structurally identical.
-- =============================================================================

ALTER TABLE `tblPrayerRequests`
    ADD COLUMN IF NOT EXISTS `kind` ENUM('request','praise','testimony') NOT NULL DEFAULT 'request'
        COMMENT 'request=prayer ask, praise=answered/gratitude, testimony=longer-form (#260)' AFTER `body`;

-- Index for the praise listing query.
ALTER TABLE `tblPrayerRequests`
    ADD KEY IF NOT EXISTS `idx_pr_kind_site_status` (`siteID`, `kind`, `status`);

-- 📋 Routes
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('praise',     'praise/index.php', 1),
    ('praise/new', 'praise/new.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ⚙️ Settings
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'praise.enabled',     '0',              '0',              0),
    (NULL, 'praise.displayName', 'Praise Reports', 'Praise Reports', 0),
    (NULL, 'praise.displayIcon', 'fa-solid fa-hands-clapping', 'fa-solid fa-hands-clapping', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
