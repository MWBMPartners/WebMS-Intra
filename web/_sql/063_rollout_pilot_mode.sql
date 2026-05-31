-- =============================================================================
-- Migration 063: Rollout pilot-mode flag (#232)
-- =============================================================================
-- Phase 1 of the rollout plan keeps the portal restricted to a small group
-- of leadership volunteers while feedback is gathered. The flag below gates
-- the (future) feedback widget and any features the admin wants to defer
-- until whole-congregation rollout.
--
-- See docs/rollout-plan.md for the rollout contract.
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.rollout.pilot_mode', '1', '1', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
