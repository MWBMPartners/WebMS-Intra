-- =============================================================================
-- Migration 071: Polish follow-ups (#244 #245 #246 #247)
-- =============================================================================
-- Various pre-rollout polish items raised after the omnibus PR:
--   • portal.i18n.minimum_coverage_for_switcher — hide incomplete locales
--   • Tour engine welcome-tour activation flag (already in #237)
--   • Future settings for the next batch of follow-ups
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.i18n.minimum_coverage_for_switcher', '0', '0', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
