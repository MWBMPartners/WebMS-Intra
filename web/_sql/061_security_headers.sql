-- =============================================================================
-- Migration 061: Baseline security response headers (#160)
-- =============================================================================
-- Seeds the seven `portal.headers.*` settings that govern the global
-- security headers sent by bootstrap.php. Each is overridable per-site
-- via the standard tblSettings mechanism; set the value to empty
-- string to suppress the header for that key.
--
-- Defaults match the "industry baseline" recommended by Mozilla
-- Observatory and securityheaders.com. None of these block existing
-- functionality on a default install.
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.headers.strict_transport_security', 'max-age=31536000; includeSubDomains', 'max-age=31536000; includeSubDomains', 0),
    (NULL, 'portal.headers.permissions_policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), accelerometer=(), gyroscope=(), browsing-topics=(), interest-cohort=()', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), accelerometer=(), gyroscope=(), browsing-topics=(), interest-cohort=()', 0),
    (NULL, 'portal.headers.coop', 'same-origin', 'same-origin', 0),
    (NULL, 'portal.headers.corp', 'same-origin', 'same-origin', 0),
    (NULL, 'portal.headers.referrer_policy', 'strict-origin-when-cross-origin', 'strict-origin-when-cross-origin', 0),
    (NULL, 'portal.headers.x_frame_options', 'SAMEORIGIN', 'SAMEORIGIN', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
