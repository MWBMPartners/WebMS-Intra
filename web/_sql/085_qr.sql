-- =============================================================================
-- Migration 085: QR code generator + CueRCode integration (#275)
-- =============================================================================
-- Routes for the QR endpoint + the admin provider-config page.
-- Settings for the CueRCode adapter.
--
-- @link https://github.com/MWBMPartners/CueRCode
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('qr',                  'qr.php',                        1),
    ('admin/settings/qr',   'admin/settings/qr/index.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.qr.provider',              'local', 'local', 0),
    (NULL, 'portal.qr.cuercode.api_endpoint', '',      '',      0),
    (NULL, 'portal.qr.cuercode.api_key',      '',      '',      1)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
