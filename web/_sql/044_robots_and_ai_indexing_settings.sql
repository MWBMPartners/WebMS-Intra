-- =============================================================================
-- 044 — Robots / AI-indexing opt-in settings 🤖
-- =============================================================================
-- WebMS Intra is an INTERNAL portal by default. The new
-- `core/templates/header.php` emits <meta name="robots"> and
-- <meta name="ai-robots"> tags whose content is driven by these settings.
--
-- Both default to 'false' (deny). robots.txt at /robots.txt provides
-- the belt-and-braces version for bots that don't read HTML meta tags.
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'site.allowIndexing',   'false', 'false', 0),
    (NULL, 'site.allowAiIndexing', 'false', 'false', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
