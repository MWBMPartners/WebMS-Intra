-- Migration 019: Update slug uniqueness constraints for multisite
-- Changes global UNIQUE(slug) to composite UNIQUE(slug, siteID)
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/60

-- -----------------------------------------------------------------------------
-- 📂 tblEventCategories — categorySlug unique per site
-- -----------------------------------------------------------------------------
ALTER TABLE `tblEventCategories`
    DROP INDEX `uq_cat_slug`,
    ADD UNIQUE KEY `uq_cat_slug_site` (`categorySlug`, `siteID`);

-- -----------------------------------------------------------------------------
-- 📋 tblEventTypes — typeSlug unique per site
-- -----------------------------------------------------------------------------
ALTER TABLE `tblEventTypes`
    DROP INDEX `uq_type_slug`,
    ADD UNIQUE KEY `uq_type_slug_site` (`typeSlug`, `siteID`);

-- -----------------------------------------------------------------------------
-- 🎨 tblEventThemes — themeSlug unique per site
-- -----------------------------------------------------------------------------
ALTER TABLE `tblEventThemes`
    DROP INDEX `uq_theme_slug`,
    ADD UNIQUE KEY `uq_theme_slug_site` (`themeSlug`, `siteID`);

-- -----------------------------------------------------------------------------
-- 🔗 tblEventSeries — seriesSlug unique per site
-- -----------------------------------------------------------------------------
ALTER TABLE `tblEventSeries`
    DROP INDEX `uq_series_slug`,
    ADD UNIQUE KEY `uq_series_slug_site` (`seriesSlug`, `siteID`);

-- -----------------------------------------------------------------------------
-- ⛪ tblAttendanceServiceTypes — typeSlug unique per site
-- -----------------------------------------------------------------------------
ALTER TABLE `tblAttendanceServiceTypes`
    DROP INDEX `uq_att_type_slug`,
    ADD UNIQUE KEY `uq_att_type_slug_site` (`typeSlug`, `siteID`);

-- 📋 Track migration
INSERT INTO `tblMigrations` (`filename`) VALUES ('019_slug_uniqueness_multisite.sql');
