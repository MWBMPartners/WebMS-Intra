-- =============================================================================
-- Migration 277: how long an event with no end time stays on the public
-- website's list (#882; D-129)
-- =============================================================================
-- What this adds:
--
--   tblSettings  public.events.noEndListedFor   the INSTALLATION's row, 'day'
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- The public door (#493 P4) keeps an event on its list until it has finished
-- on its own clock. An event with a start time and no end time counted its
-- START as its end, so a 10:00 service with no end time was gone from a
-- church's website at 10:00 on the morning it happened. The owner decided
-- (D-129, 11 November 2026) that such an event stays listed until the end of
-- its day on its own clock, by default, and that an organisation may instead
-- choose a fixed time after the start, used only when there is no end time.
--
-- The value is 'day' (until the end of the day) or a whole number of MINUTES
-- (15 to 1440). An organisation's own choice is its own row, written only by
-- Admin, Public website (Portal\Core\PublicWebsiteSetup::saveNoEndListing());
-- with no own row the installation's row below is read. No page offers the
-- installation's row (the noticeboard foyer's `noticeboard.foyer.shows` is the
-- same shape), and the general settings editor refuses the key
-- (Portal\Core\PublicWebsite::LOCKED_KEYS). Nothing is written for any
-- organisation here: every organisation starts on the default.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- The row is seeded with ON DUPLICATE KEY UPDATE of `defaultValue` only, so a
-- second run, or the installer's replay, never overwrites a value somebody
-- set by hand. `full_schema.sql` seeds the same row in the same words.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/882
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
-- @license    All Rights Reserved
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'public.events.noEndListedFor', 'day', 'day', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('277_public_events_without_end_time.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
