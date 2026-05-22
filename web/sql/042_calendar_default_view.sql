-- =============================================================================
-- 042 — Calendar default view setting 📅
-- =============================================================================
-- Adds `calendar.defaultView` so admins can set the landing view on /calendar
-- when the user has no per-device preference yet (no localStorage value).
--
-- Valid values: day | week | weekdays | weekend | month | year | list
-- Default: month  — most-used overview for a typical calendar landing.
--
-- See: web/public_html/calendar/index.php (issue #136)
-- =============================================================================
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'calendar.defaultView', 'month', 'month', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
