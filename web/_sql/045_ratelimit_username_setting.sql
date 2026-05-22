-- =============================================================================
-- 045 — Composite IP+username rate-limit setting 🛡️
-- =============================================================================
-- Closes issue #52. The existing rate limiter was IP-only — caught the
-- "one attacker scanning many usernames from one IP" pattern but missed
-- the "single account being targeted across rotating IPs" pattern.
--
-- RateLimiter::isUserOrIpBlocked() now checks both. This setting
-- controls the per-username threshold; the existing
-- auth.rateLimit.maxAttempts (default 5) still controls the per-IP one.
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'auth.rateLimit.maxAttemptsByUsername', '10', '10', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
