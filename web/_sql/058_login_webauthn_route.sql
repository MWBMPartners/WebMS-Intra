-- =============================================================================
-- Migration 058: Register login/webauthn route
-- =============================================================================
-- web/public_html/auth/login/webauthn.php is called from the login form via
-- `fetch('/login/webauthn')` but was not registered in tblRoutes. It used
-- to work via direct .htaccess fall-through; the v1.0 security hardening
-- tightened .htaccess to route all .php through the front controller, so
-- this AJAX endpoint now 404s. Adding the route restores it.
--
-- isProtected = 0 because the endpoint runs during the login challenge
-- (pre-auth).
--
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/206
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('login/webauthn', 'auth/login/webauthn.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
