-- =============================================================================
-- 038 — Seed the `branding.hidePoweredBy` setting
-- =============================================================================
-- Adds a global setting that controls the "Powered by WebMS Intra" footer
-- attribution. Default value 'false' — attribution SHOWS when the site is
-- using custom branding (any tblSites branding column differs from the
-- WebMS Intra default values defined as Site::DEFAULT_* constants).
--
-- Admins set this to 'true' via /settings/ to hide the attribution across
-- all sites in the install (e.g. for whitelabel agreements).
--
-- Idempotent: ON DUPLICATE KEY UPDATE preserves existing values on re-run.
-- =============================================================================

INSERT INTO `tblSettings`
    (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`)
VALUES
    (NULL, 'branding.hidePoweredBy', 'false', 'false', 0)
ON DUPLICATE KEY UPDATE
    `defaultValue` = VALUES(`defaultValue`);
