-- =============================================================================
-- Migration 079: Member Directory app (#261)
-- =============================================================================
-- Extends tblUsers with directory-profile columns. Per-field visibility
-- enums let each user opt in field-by-field. Defaults are conservative:
-- name + role visible to members, contact details private.
-- =============================================================================

ALTER TABLE `tblUsers`
    ADD COLUMN IF NOT EXISTS `displayBio`     TEXT         DEFAULT NULL  COMMENT 'Markdown bio (#261)',
    ADD COLUMN IF NOT EXISTS `displayPhoto`   VARCHAR(500) DEFAULT NULL  COMMENT 'Path under _uploads/ to profile photo',
    ADD COLUMN IF NOT EXISTS `displayPhone`   VARCHAR(50)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `displayAddress` VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `visibilityName`    ENUM('private','team','members','public') NOT NULL DEFAULT 'members',
    ADD COLUMN IF NOT EXISTS `visibilityRoles`   ENUM('private','team','members','public') NOT NULL DEFAULT 'members',
    ADD COLUMN IF NOT EXISTS `visibilityEmail`   ENUM('private','team','members','public') NOT NULL DEFAULT 'private',
    ADD COLUMN IF NOT EXISTS `visibilityPhone`   ENUM('private','team','members','public') NOT NULL DEFAULT 'private',
    ADD COLUMN IF NOT EXISTS `visibilityAddress` ENUM('private','team','members','public') NOT NULL DEFAULT 'private',
    ADD COLUMN IF NOT EXISTS `visibilityBio`     ENUM('private','team','members','public') NOT NULL DEFAULT 'members',
    ADD COLUMN IF NOT EXISTS `visibilityPhoto`   ENUM('private','team','members','public') NOT NULL DEFAULT 'private';

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('directory',             'directory/index.php',   1),
    ('directory/profile',     'directory/profile.php', 1),
    ('directory/my-settings', 'directory/me.php',      1),
    ('directory/save',        'directory/save.php',    1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'directory.enabled',     '0', '0', 0),
    (NULL, 'directory.displayName', 'Member Directory', 'Member Directory', 0),
    (NULL, 'directory.displayIcon', 'fa-solid fa-address-book', 'fa-solid fa-address-book', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
