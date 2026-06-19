-- =============================================================================
-- Migration 127: Embeddable event widgets (#336)
-- =============================================================================
-- /widget?slug=<slug>      — iframe-friendly minimal page (one event)
-- /widget?upcoming=N       — iframe of next N upcoming events for the site
-- /assets/js/widget.js     — script-tag friendly drop-in (resolves to iframe)
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/336
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('widget', 'calendar/widget.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
