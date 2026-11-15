-- =============================================================================
-- Migration 296: the publisher's website, for the API page's "Website" link
-- (#863; D-160)
-- =============================================================================
-- What this adds:
--
--   tblSettings  product.publisherUrl   the INSTALLATION's row, empty
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- The page that documents the portal's API for developers (/api-docs, drawn
-- from /openapi.json) shows the publisher's name, from `product.publisher`,
-- with a "Website" link beside it. That link was a fixed address written into
-- web/_core/api-spec.json — the product's own source repository — so an
-- installation whose publisher was a different company showed that company's
-- name linking to somebody else's site. The owner decided (D-160, 12 November
-- 2026) that the link follows a setting given during installation and is left
-- out when none is set. No address is built in.
--
-- The row ships EMPTY, so an installation that is upgraded shows the name
-- with no link until somebody sets one (a global administrator, in Admin,
-- Settings). That is the honest default: the portal cannot know a
-- publisher's website, and the old link was right only for one publisher.
-- web/public_html/openapi.php reads the row through
-- Portal\Core\PublisherWebsite, which refuses anything that is not an http(s)
-- address and then leaves the link out.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- The row is seeded with ON DUPLICATE KEY UPDATE of `defaultValue` only, so a
-- second run, or the installer's replay, never overwrites a value somebody
-- set. `full_schema.sql` seeds the same row in the same words.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/863
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
-- @license    All Rights Reserved
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'product.publisherUrl', '', '', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('296_publisher_website_setting.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
