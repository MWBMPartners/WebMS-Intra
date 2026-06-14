-- =============================================================================
-- Migration 108: Product brand layer — multi-brand sub-product support (#296)
-- =============================================================================
-- Introduces a SYSTEM-LEVEL product brand layer that sits ABOVE the existing
-- per-tenant `branding.*` settings. Lets the same codebase ship as
-- "WebMS Intra", "ChurchMS", or future vertical sub-brands, picked at install
-- time via the installer's organisation-type step (see #296 design comment).
--
-- 🗂️ Two-layer model:
--   product.* (this migration)     → set ONCE at install, system-wide
--   branding.* / tblSites.* (already shipped) → per-tenant overrides
-- Resolution rule everywhere: tenant override > product default > hardcoded.
--
-- The `portal.industry` setting was introduced in migration 073 alongside the
-- AppRegistry; this migration extends that by adding name/tagline/publisher.
-- Defaults match the historical hardcoded values so existing installs see
-- zero change unless an admin explicitly updates these rows or re-runs the
-- installer (locked after first run via _auth_keys/.installed).
--
-- 📦 New settings:
--   product.name          'WebMS Intra'                          (short product name)
--   product.tagline       'Internal Management System'           (long tagline)
--   product.publisher     'MWBM Partners Ltd (t/a MWservices)'   (always this — decision #4)
--
-- 🔄 Reversible: admins can edit these freely via /admin/settings. Changing
--    `portal.industry` later via /admin/settings does NOT auto-update name/
--    tagline (admin may have customised them). The preset bundle is only
--    applied at install time.
--
-- @see   web/_core/brand-defaults.php — preset bundles by industry
-- @link  https://github.com/MWBMPartners/WebMS-Intra/issues/296
-- =============================================================================

-- 📱 Brand-aware PWA manifest: route `manifest.json` → `manifest.php`.
--    The static manifest.json file is deleted in this PR; Apache falls
--    through to index.php which then dispatches here. Public route
--    (isProtected=0) because browsers fetch this before login.
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('manifest.json', 'manifest.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    -- 🏷️ Short product name (header meta generator, footer powered-by mark,
    --     X-Powered-By header, PWA manifest name).
    (NULL, 'product.name', 'WebMS Intra', 'WebMS Intra', 0),

    -- 📝 Long tagline (installer wizard subtitle, /admin/about, manifest description).
    (NULL, 'product.tagline', 'Internal Management System', 'Internal Management System', 0),

    -- 🏛️ Publisher / copyright org (footer "© 2026 …", X-Powered-By branding).
    --    Per decision #4: ALWAYS 'MWBM Partners Ltd (t/a MWservices)' regardless
    --    of which sub-brand is installed. Sub-brands are MWBM products, not
    --    white-labels.
    (NULL, 'product.publisher', 'MWBM Partners Ltd (t/a MWservices)', 'MWBM Partners Ltd (t/a MWservices)', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
