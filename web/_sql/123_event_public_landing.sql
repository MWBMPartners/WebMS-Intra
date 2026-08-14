-- =============================================================================
-- Migration 123: Per-event public landing page (#346)
-- =============================================================================
-- The short URL /e/<slug> renders a branded hero + countdown widget + RSVP
-- CTA. Routing is handled in Router::handleSpecialRoutes (prefix match
-- on "e/") — no tblRoutes entry needed for the short URL itself.
--
-- Adds a setting for whether to show the QR code on the landing page.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/346
-- =============================================================================

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`) VALUES
    ('public_landing.show_qr',     '1', 0),
    ('public_landing.show_countdown', '1', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);
